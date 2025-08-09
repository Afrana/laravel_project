from github import Github
import os
import re
import json
import fnmatch
from dataclasses import dataclass
from typing import List, Optional, Dict

# ---------------- Config schema ----------------
DEFAULT_CONFIG = {
    "min_severity": "info",             # info | warning | critical
    "max_inline_comments": 30,
    "paths": {
        "ignore": ["vendor/**", "storage/**", "tests/**", "**/.history/**"],
        "only": []                         # restrict analysis to these patterns if non-empty
    },
    "enable_taint_rules": True,            # lightweight extra checks in this script
    "rules": {                             # per-rule toggles & severity overrides
        # Example entries; if a rule is missing here, defaults apply.
        # "eval_usage": {"enabled": True, "severity": "critical"}
    }
}

SEVERITY_ORDER = {"info": 0, "warning": 1, "critical": 2}

# ---------------- GitHub setup ----------------
TOKEN = os.environ.get('GITHUB_TOKEN')
REPO_NAME = os.environ.get('GITHUB_REPOSITORY')
PR_NUMBER = int(os.environ.get('PR_NUMBER', '0'))

g = Github(TOKEN)
repo = g.get_repo(REPO_NAME)
pr = repo.get_pull(PR_NUMBER)

# ---------------- Helpers ----------------

def load_config() -> Dict:
    cfg_paths = ['.phpcodereview.json', 'code_review/.phpcodereview.json']
    for p in cfg_paths:
        if os.path.exists(p):
            try:
                with open(p, 'r', encoding='utf-8') as fh:
                    cfg = json.load(fh)
                merged = DEFAULT_CONFIG.copy()
                # deep merge for nested keys
                merged['paths'] = {**DEFAULT_CONFIG['paths'], **cfg.get('paths', {})}
                merged['rules'] = {**DEFAULT_CONFIG.get('rules', {}), **cfg.get('rules', {})}
                for k,v in cfg.items():
                    if k not in ('paths','rules'):
                        merged[k] = v
                return merged
            except Exception:
                break
    return DEFAULT_CONFIG

CONFIG = load_config()

def path_ignored(path: str) -> bool:
    only = CONFIG.get('paths', {}).get('only', [])
    if only:
        if not any(fnmatch.fnmatch(path, pat) for pat in only):
            return True
    for pat in CONFIG.get('paths', {}).get('ignore', []):
        if fnmatch.fnmatch(path, pat):
            return True
    return False

@dataclass
class Finding:
    path: str
    line: Optional[int]
    body: str
    severity: str = "warning"
    rule: Optional[str] = None

# Map message text to a stable rule id when not explicitly tagged
INFER_RULE_MAP = [
    ("eval_usage", re.compile(r"\beval\(\)", re.I)),
    ("debug_call", re.compile(r"\b(var_dump|print_r)\s*\(", re.I)),
    ("deprecated_mysql", re.compile(r"\bmysql_\w+\s*\(", re.I)),
    ("exit_die", re.compile(r"\b(exit|die)\b", re.I)),
    ("goto_usage", re.compile(r"\bgoto\b", re.I)),
    ("camel_function", re.compile(r"Function '.*' does not follow camelCase", re.I)),
    ("verb_function", re.compile(r"Function '.*' should start with a verb", re.I)),
    ("long_function", re.compile(r"too long \(\d+ lines\)", re.I)),
    ("studly_class", re.compile(r"does not follow StudlyCaps", re.I)),
    ("underscore_private_prop", re.compile(r"Property '.*' should start with an underscore", re.I)),
    ("superglobal_use", re.compile(r"Use of superglobal", re.I)),
    ("nested_loop", re.compile(r"Nested loop", re.I)),
    ("magic_number", re.compile(r"Magic number", re.I)),
    ("hardcoded_value", re.compile(r"Hard-?coded value", re.I)),
    ("const_caps", re.compile(r"Class constant '.*' should be in ALL_CAPS", re.I)),
    ("var_camel", re.compile(r"Variable '\$.*' does not follow lowerCamelCase", re.I)),
    ("missing_phpdoc", re.compile(r"missing PHPDoc", re.I)),
    ("global_usage", re.compile(r"Use of 'global'", re.I)),
    ("error_suppression", re.compile(r"Use of '@' operator", re.I)),
    ("empty_catch", re.compile(r"Empty catch block", re.I)),
    ("empty_finally", re.compile(r"Empty finally block", re.I)),
    ("empty_if", re.compile(r"Empty if block", re.I)),
    ("empty_elseif", re.compile(r"Empty elseif block", re.I)),
    ("empty_else", re.compile(r"Empty else block", re.I)),
    ("empty_foreach", re.compile(r"Empty foreach body", re.I)),
    ("empty_for", re.compile(r"Empty for loop body", re.I)),
    ("empty_while", re.compile(r"Empty while loop body", re.I)),
    ("empty_do_while", re.compile(r"Empty do-while loop body", re.I)),
    ("empty_switch_case", re.compile(r"Empty .* block in switch", re.I)),
    ("empty_function", re.compile(r"Empty function '", re.I)),
    ("empty_method", re.compile(r"Empty method '", re.I)),
    ("unused_local", re.compile(r"assigned but never used", re.I)),
    ("unused_property", re.compile(r"never used; remove it or use it", re.I)),
    ("short_open_tag", re.compile(r"Short open tag", re.I)),
    ("closing_tag", re.compile(r"Closing \?>.*pure PHP files", re.I)),
]

def infer_rule(msg: str) -> Optional[str]:
    # explicit tag wins: [rule:my_rule_id]
    m = re.search(r"\[rule:([a-z0-9_\.:-]+)\]", msg, re.I)
    if m:
        return m.group(1).lower()
    for rid, rx in INFER_RULE_MAP:
        if rx.search(msg):
            return rid
    return None

def infer_severity(msg: str, rule: Optional[str]) -> str:
    # explicit tag: [severity:critical|warning|info]
    m = re.search(r"\[severity:(critical|warning|info)\]", msg, re.I)
    if m:
        return m.group(1).lower()
    # config override
    if rule and rule in CONFIG.get("rules", {}):
        sev = CONFIG["rules"][rule].get("severity")
        if sev in SEVERITY_ORDER:
            return sev
    # heuristic fallback
    low = msg.lower()
    if any(k in low for k in ["sql injection", "xss", "unescaped", "eval()", "danger", "shell_exec", "system(", "secrets", "deserializ"]):
        return "critical"
    if any(k in low for k in ["exit", "die", "global", "goto", "error suppression", "@ operator"]):
        return "warning"
    return "info"

def rule_enabled(rule: Optional[str]) -> bool:
    if rule is None:
        return True
    cfg = CONFIG.get("rules", {}).get(rule)
    if cfg is None:
        return True  # default enabled if not specified
    return cfg.get("enabled", True)

def min_sev_allows(sev: str) -> bool:
    want = CONFIG.get("min_severity", "warning").lower()
    return SEVERITY_ORDER.get(sev, 1) >= SEVERITY_ORDER.get(want, 1)

def find_position_in_diff(patch: str, target_line: int) -> Optional[int]:
    if not patch:
        return None
    position = 0
    current_old = 0
    current_new = 0
    for line in patch.splitlines():
        position += 1
        if line.startswith('@@'):
            m = re.search(r"\+([0-9]+)", line)
            if m:
                current_new = int(m.group(1)) - 1
            continue
        if line.startswith('+'):
            current_new += 1
            if current_new == target_line:
                return position
        elif line.startswith('-'):
            current_old += 1
        else:
            current_new += 1
            current_old += 1
            if current_new == target_line:
                return position
    return None

def parse_feedback(feedback_text: str) -> List[Finding]:
    findings: List[Finding] = []
    current_file: Optional[str] = None
    for line in feedback_text.splitlines():
        line = line.strip()
        if not line:
            continue

        file_match = re.match(r"File:\s+(.*)$", line)
        if file_match:
            current_file = file_match.group(1)
            continue

        # non-file lines: treat as general/summary
        if current_file is None:
            rid = infer_rule(line)
            sev = infer_severity(line, rid)
            findings.append(Finding(path="", line=None, body=line, severity=sev, rule=rid))
            continue

        # standard "line no N" parsing
        m = re.search(r"line no\s*(\d+)", line, re.IGNORECASE)
        line_no = int(m.group(1)) if m else None
        rid = infer_rule(line)
        sev = infer_severity(line, rid)
        findings.append(Finding(path=current_file, line=line_no, body=line, severity=sev, rule=rid))
    return findings

# Lightweight taint checks
UNESCAPED_SOURCES = ["$_GET", "$_POST", "$_REQUEST", "$_COOKIE"]
ESCAPE_FUNCS = ["htmlspecialchars", "htmlentities"]

def run_light_taint_checks() -> List[Finding]:
    if not CONFIG.get("enable_taint_rules", True):
        return []
    results: List[Finding] = []
    files = [f for f in pr.get_files() if f.filename.endswith('.php')]
    for f in files:
        if path_ignored(f.filename):
            continue
        try:
            content = repo.get_contents(f.filename, ref=pr.head.sha).decoded_content.decode('utf-8', errors='ignore')
        except Exception:
            continue
        # Rule: Unescaped echo/print of superglobals
        for i, line in enumerate(content.splitlines(), start=1):
            if any(src in line for src in UNESCAPED_SOURCES) and re.search(r"\b(echo|print)\b", line) and not any(fn in line for fn in ESCAPE_FUNCS):
                msg = f"[rule:xss_unescaped_output] [severity:critical] Potential XSS: unescaped user input echoed on line no {i}; escape with htmlspecialchars()."
                results.append(Finding(path=f.filename, line=i, body=msg, severity="critical", rule="xss_unescaped_output"))
        # Rule: Naive SQL concatenation/interpolation without prepare
        uses_prepare = re.search(r"prepare\s*\(|bindParam|bindValue", content, re.IGNORECASE)
        for i, line in enumerate(content.splitlines(), start=1):
            if re.search(r"(SELECT|INSERT|UPDATE|DELETE)", line, re.IGNORECASE) and ('.' in line or '$' in line):
                if (any(src in line for src in UNESCAPED_SOURCES) or re.search(r"\$[a-zA-Z_][a-zA-Z0-9_]*", line)) and not uses_prepare:
                    msg = f"[rule:sql_concat_query] [severity:critical] Possible SQL injection: query built by string concatenation on line no {i}; use prepared statements."
                    results.append(Finding(path=f.filename, line=i, body=msg, severity="critical", rule="sql_concat_query"))
    return results

def main():
    feedback_path = 'code_review/feedback.txt'
    base_text = ""
    if os.path.exists(feedback_path):
        with open(feedback_path, 'r', encoding='utf-8') as fh:
            base_text = fh.read()

    findings = parse_feedback(base_text)
    findings.extend(run_light_taint_checks())

    # Apply per-rule enablement and severity threshold
    filtered: List[Finding] = []
    for f in findings:
        if f.path and path_ignored(f.path):
            continue
        if not rule_enabled(f.rule):
            continue
        if not min_sev_allows(f.severity):
            continue
        filtered.append(f)

    # Split inline vs summary
    inline_items = [f for f in filtered if f.path and f.line]
    summary_items = [f for f in filtered if not (f.path and f.line)]

    # Build inline review comments (respect cap)
    comments = []
    cap = CONFIG.get("max_inline_comments", 30)
    for f in inline_items[:cap]:
        pr_file = next((pf for pf in pr.get_files() if pf.filename == f.path), None)
        if not pr_file:
            continue
        position = find_position_in_diff(pr_file.patch or "", f.line)
        if position is None:
            continue
        comments.append({
            "path": f.path,
            "position": position,
            "body": f.body
        })

    if comments:
        pr.create_review(body="Automated PHP Code Review Feedback", comments=comments)

    # Summarize remaining/non-line findings
    if summary_items:
        by_file: Dict[str, List[Finding]] = {}
        for f in summary_items:
            key = f.path or "General"
            by_file.setdefault(key, []).append(f)
        lines = ["**Summary of non-inline findings:**\n"]
        for path, items in by_file.items():
            lines.append(f"- **{path}**")
            for it in items:
                sev = it.severity.upper()
                rid = f" ({it.rule})" if it.rule else ""
                lines.append(f"  - [{sev}]{rid} {it.body}")
        pr.create_issue_comment("\n".join(lines))

    if not comments and not summary_items:
        pr.create_issue_comment("No issues found. Nice implementation!")

if __name__ == "__main__":
    main()