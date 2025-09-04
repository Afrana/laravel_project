#!/usr/bin/env python3
# -*- coding: utf-8 -*-

from github import Github, Auth
import os
import re
import json
import fnmatch
from dataclasses import dataclass
from typing import List, Optional, Dict

# ---------------- Constants ----------------
SEVERITY_ORDER = {"info": 0, "warning": 1, "critical": 2}
CONFIG_PATH = "code_review/phpcodereview.json"
FEEDBACK_PATH = "code_review/feedback.txt"
MAX_BODY_LEN = 65500  # keep well under GitHub comment limits

# ---------------- Config (required) ----------------
def load_config() -> Dict:
    if not os.path.exists(CONFIG_PATH):
        raise FileNotFoundError(f"Required config file not found: {CONFIG_PATH}")
    with open(CONFIG_PATH, "r", encoding="utf-8") as fh:
        return json.load(fh)

CONFIG = load_config()

# ---------------- GitHub setup ----------------
TOKEN = os.environ.get("GITHUB_TOKEN")
REPO_NAME = os.environ.get("GITHUB_REPOSITORY")
PR_NUMBER = int(os.environ.get("PR_NUMBER", "0"))

if not TOKEN:
    raise RuntimeError("GITHUB_TOKEN env var is required")
if not REPO_NAME:
    raise RuntimeError("GITHUB_REPOSITORY env var is required")
if PR_NUMBER <= 0:
    raise RuntimeError("PR_NUMBER env var must be a valid PR number")

g = Github(auth=Auth.Token(TOKEN))
repo = g.get_repo(REPO_NAME)
pr = repo.get_pull(PR_NUMBER)

# ---------------- Filters ----------------
IGNORE_GLOBS: List[str] = CONFIG.get("paths", {}).get("ignore", []) or []
ONLY_GLOBS: List[str] = CONFIG.get("paths", {}).get("only", []) or []
MAX_INLINE = int(CONFIG.get("max_inline_comments", 30))
MIN_SEVERITY = CONFIG.get("min_severity", "info").lower()

def path_is_included(path: str) -> bool:
    if ONLY_GLOBS:
        allowed = any(fnmatch.fnmatch(path, pat) for pat in ONLY_GLOBS)
        if not allowed:
            return False
    if IGNORE_GLOBS:
        if any(fnmatch.fnmatch(path, pat) for pat in IGNORE_GLOBS):
            return False
    return True

# ---------------- Data classes ----------------
@dataclass
class Finding:
    path: Optional[str]
    line: Optional[int]
    body: str
    severity: str  # "info" | "warning" | "critical"

# ---------------- Rule inference (fallbacks) ----------------
INFER_RULE_MAP = [
    ("eval_usage", re.compile(r"\beval\(\)", re.I), "critical"),
    ("debug_call", re.compile(r"\b(var_dump|print_r)\s*\(", re.I), "info"),
    ("deprecated_mysql", re.compile(r"\bmysql_\w+\s*\(", re.I), "warning"),
    ("exit_die", re.compile(r"\b(exit|die)\b", re.I), "warning"),
    ("goto_usage", re.compile(r"\bgoto\b", re.I), "warning"),
    ("camel_function", re.compile(r"Function '.*' does not follow camelCase", re.I), "info"),
    ("verb_function", re.compile(r"Function '.*' should start with a verb", re.I), "info"),
    ("long_function", re.compile(r"too long \(\d+ lines\)", re.I), "warning"),
    ("studly_class", re.compile(r"does not follow StudlyCaps", re.I), "info"),
    ("underscore_private_prop", re.compile(r"Property '.*' does not follow camelCase", re.I), "info"),
    ("superglobal_use", re.compile(r"Use of superglobal", re.I), "info"),
    ("nested_loop", re.compile(r"Nested loop", re.I), "warning"),
    ("magic_number", re.compile(r"Magic number", re.I), "info"),
]

def infer_severity(text: str) -> str:
    # explicit tag like [CRITICAL], [WARNING], [INFO]
    m = re.search(r"\[(critical|warning|info)\]", text, re.I)
    if m:
        return m.group(1).lower()
    # try to infer from known patterns
    for _, pat, sev in INFER_RULE_MAP:
        if pat.search(text):
            return sev
    return "warning"

def sev_passes_threshold(sev: str) -> bool:
    return SEVERITY_ORDER[sev] >= SEVERITY_ORDER.get(MIN_SEVERITY, 0)

# ---------------- Feedback parser ----------------
# Accepts: "line no 23", "line no. 23", "on line 23", or just "line 23"
LINE_RX = re.compile(r"(?:line\s*no\.?|on\s*line|line)\s*(\d+)", re.I)
FILE_RX = re.compile(r"^\s*File:\s*(.+?)\s*$", re.I)

def parse_feedback(fp: str) -> List[Finding]:
    if not os.path.exists(fp):
        # If analyzer hasn't produced feedback, don't fail the run
        print(f"[post_feedback] Feedback file not found: {fp}")
        return []
    findings: List[Finding] = []
    current_file: Optional[str] = None

    with open(fp, "r", encoding="utf-8", errors="ignore") as fh:
        for raw in fh:
            line = raw.strip()
            if not line:
                continue
            mfile = FILE_RX.match(line)
            if mfile:
                current_file = mfile.group(1).strip()
                continue

            # capture line number (if any)
            mline = LINE_RX.search(line)
            line_no: Optional[int] = int(mline.group(1)) if mline else None

            sev = infer_severity(line)
            body = line

            findings.append(Finding(
                path=current_file,
                line=line_no,
                body=body if len(body) <= MAX_BODY_LEN else (body[:MAX_BODY_LEN] + "…"),
                severity=sev
            ))
    return findings

# ---------------- Diff position mapping ----------------
def find_position_in_diff(patch: str, target_line: int) -> Optional[int]:
    """
    Convert a right-side (new file) line number to a GitHub 'position' within the patch.
    Returns None if the line can't be mapped (e.g., deleted-only lines).
    """
    if not patch:
        return None

    position = 0
    new_line = 0

    for raw_line in patch.splitlines():
        position += 1
        if raw_line.startswith("@@"):
            # Hunk header: @@ -<oldStart>,<oldCount> +<newStart>,<newCount> @@
            m = re.search(r"\+(\d+)", raw_line)
            if m:
                new_line = int(m.group(1)) - 1  # next '+ ' or ' ' will increment to start
            continue

        if raw_line.startswith("+"):
            new_line += 1
            if new_line == target_line:
                return position
        elif raw_line.startswith("-"):
            # removed from old file; no increment on new_line
            continue
        else:
            # context
            new_line += 1
            if new_line == target_line:
                return position

    return None

# ---------------- Pending review cleanup ----------------
def clear_my_pending_review(pr_obj, my_login: str):
    """Delete any pending review owned by the current user on this PR."""
    try:
        for r in pr_obj.get_reviews():
            if getattr(r, "user", None) and r.user.login == my_login and str(r.state).upper() == "PENDING":
                try:
                    r.delete()  # allowed for PENDING reviews
                    print(f"[post_feedback] Deleted pending review id={r.id} user={my_login}")
                except Exception as ex:
                    print(f"[post_feedback] Warning: could not delete pending review id={r.id}: {ex}")
    except Exception as ex:
        print(f"[post_feedback] Warning: could not enumerate reviews: {ex}")

# ---------------- Summary builder ----------------
def build_summary(items: List[Finding]) -> str:
    if not items:
        return "No additional issues."
    by_file: Dict[str, List[Finding]] = {}
    for it in items:
        key = it.path or "General"
        by_file.setdefault(key, []).append(it)

    lines: List[str] = ["**Automated PHP Code Review – Summary (unmapped lines/large diffs):**\n"]
    for path, lst in by_file.items():
        lines.append(f"- **{path}**")
        for it in lst:
            lines.append(f"  - [{it.severity.upper()}] {it.body}")
    text = "\n".join(lines)
    return text if len(text) <= MAX_BODY_LEN else (text[:MAX_BODY_LEN] + "…")

# ---------------- Main ----------------
def main():
    findings = parse_feedback(FEEDBACK_PATH)
    if not findings:
        pr.create_issue_comment("No issues found. Nice implementation!")
        return

    # Filter by severity + path
    selected: List[Finding] = []
    for f in findings:
        if not f.path:
            # let summary carry file-less notes
            selected.append(f)
            continue
        if not path_is_included(f.path):
            continue
        if not sev_passes_threshold(f.severity):
            continue
        selected.append(f)

    # Split into inline candidates (must have file + line) vs summary
    inline_candidates: List[Finding] = []
    summary_items: List[Finding] = []
    for f in selected:
        if f.path and f.line:
            inline_candidates.append(f)
        else:
            summary_items.append(f)

    # Map to diff positions (respect MAX_INLINE)
    comments = []
    inline_count = 0
    pr_files = list(pr.get_files())  # cache for speed

    for f in inline_candidates:
        if inline_count >= MAX_INLINE:
            summary_items.append(f)
            continue

        pr_file = next((pf for pf in pr_files if pf.filename == f.path), None)
        if not pr_file:
            summary_items.append(f)
            continue

        patch = getattr(pr_file, "patch", "") or ""
        if not patch:
            # Large files sometimes provide no patch → summary fallback
            summary_items.append(f)
            continue

        position = find_position_in_diff(patch, f.line)
        if position is None:
            # Typically: pointing to a removed-only line
            summary_items.append(f)
            continue

        comments.append({
            "path": f.path,
            "position": position,
            "body": f.body[:MAX_BODY_LEN]
        })
        inline_count += 1

    # Sort comments by severity (critical → warning → info), keep stable order otherwise
    def sev_key(cmt):
        body = cmt.get("body", "")
        m = re.search(r"\[(CRITICAL|WARNING|INFO)\]", body, re.I)
        sev = m.group(1).lower() if m else infer_severity(body)
        return -SEVERITY_ORDER.get(sev, 0)
    comments.sort(key=sev_key)

    me_login = g.get_user().login
    # Always ensure no stale pending review
    clear_my_pending_review(pr, me_login)

    # Publish review (not draft) and add summary as issue comment
    # Wrap in a retry if GitHub races a pending review between list/delete and create
    try:
        if comments:
            pr.create_review(
                body="Automated PHP Code Review Feedback",
                comments=comments,
                event="COMMENT"  # publish immediately
            )
        if summary_items:
            pr.create_issue_comment(build_summary(summary_items))
    except Exception as e:
        if "pending review" in str(e).lower():
            clear_my_pending_review(pr, me_login)
            if comments:
                pr.create_review(
                    body="Automated PHP Code Review Feedback",
                    comments=comments,
                    event="COMMENT"
                )
            if summary_items:
                pr.create_issue_comment(build_summary(summary_items))
        else:
            raise

    # If nothing at all was posted, leave a friendly note
    if not comments and not summary_items:
        pr.create_issue_comment("No issues found. Nice implementation!")

if __name__ == "__main__":
    main()