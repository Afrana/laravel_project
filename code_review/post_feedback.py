import os, json, hashlib, time
from github import Github

CONFIG_PATH = "code_review/phpcodereview.json"
FINDINGS_PATH = "code_review/findings.json"
HUNKS_PATH = "code_review/changed_hunks.json"
LABELS_PATH = "code_review/labels.json"

def load_config():
    try:
        with open(CONFIG_PATH, "r") as f:
            return json.load(f)
    except FileNotFoundError:
        # Fail-soft default config
        return {
            "min_severity": "warning",
            "comment_cap": 30,
            "group_similar": True,
            "severity": {}
        }

SEV_RANK = {"info": 0, "warning": 1, "critical": 2}

def min_severity_from_labels(labels, default_min):
    if "review:strict" in labels:
        return "info"
    if "review:critical-only" in labels:
        return "critical"
    return default_min

def load_json(path, default):
    try:
        with open(path, "r") as f:
            return json.load(f)
    except FileNotFoundError:
        return default

def filter_to_hunks(findings, hunks_map):
    # keep items whose line falls within any added hunk (start..start+len-1)
    out = []
    for it in findings:
        file = it.get("file")
        line = int(it.get("line", 0))
        if not file or file not in hunks_map:
            continue
        keep = False
        for start, length in hunks_map[file]:
            if line >= start and line < start + length:
                keep = True
                break
        if keep:
            out.append(it)
    return out

def group_key(it):
    sig = f'{it.get("file")}:{it.get("line")}:{it.get("rule")}:{it.get("message")}'
    return hashlib.sha1(sig.encode()).hexdigest()

def build_comment_body(items):
    # group similar items per hunk/file
    lines = ["Automated context-aware PHP review findings:", ""]
    for it in items:
        rule = it.get("rule")
        sev = it.get("severity")
        msg = it.get("message")
        sig = group_key(it)
        lines.append(f"- [{sev.upper()}] {msg}  <!-- review-bot:{sig} -->")
    return "\n".join(lines)

def main():
    TOKEN = os.getenv("GITHUB_TOKEN")
    REPO = os.getenv("GITHUB_REPOSITORY")
    PR_NUMBER = int(os.getenv("PR_NUMBER", "0"))
    g = Github(TOKEN)
    repo = g.get_repo(REPO)
    pr = repo.get_pull(PR_NUMBER)

    cfg = load_config()
    hunks = load_json(HUNKS_PATH, {})
    labels = load_json(LABELS_PATH, [])
    findings = load_json(FINDINGS_PATH, [])

    # severity gate (with label override)
    min_sev = min_severity_from_labels(labels, cfg.get("min_severity", "warning"))
    findings = [f for f in findings if SEV_RANK.get(f.get("severity","warning"),1) >= SEV_RANK[min_sev]]

    # filter to changed hunks
    findings = filter_to_hunks(findings, hunks)

    # de-duplicate by signature
    seen = set()
    uniq = []
    for f in findings:
        sig = group_key(f)
        if sig not in seen:
            seen.add(sig)
            uniq.append(f)

    # Respect comment cap
    cap = int(cfg.get("comment_cap", 30))
    uniq = uniq[:cap]

    # Post or update: if an identical signature exists, skip
    existing = list(pr.get_issue_comments())
    existing_sigs = set()
    for c in existing:
        if "<!-- review-bot:" in c.body:
            # collect all sigs inside
            for part in c.body.split("<!-- review-bot:")[1:]:
                sig = part.split("-->")[0].strip()
                existing_sigs.add(sig)

    new_items = [f for f in uniq if group_key(f) not in existing_sigs]

    if not new_items:
        pr.create_issue_comment("✅ No new context-aware findings for the latest changes. <!-- review-bot:summary -->")
        return

    body = build_comment_body(new_items)
    pr.create_issue_comment(body)

    # Optional: write SARIF for upload
    runs = []
    for it in new_items:
        rule = it.get("rule")
        sev = it.get("severity", "warning")
        message = it.get("message")
        file = it.get("file")
        line = int(it.get("line", 1))
        level = "warning" if sev != "critical" else "error"
        runs.append({
            "ruleId": rule,
            "level": level,
            "message": {"text": message},
            "locations": [{
                "physicalLocation": {
                    "artifactLocation": {"uri": file},
                    "region": {"startLine": line}
                }
            }]
        })
    sarif = {
        "version": "2.1.0",
        "runs": [{
            "tool": {"driver": {"name": "context-php-review", "rules": []}},
            "results": runs
        }]
    }
    with open("code_review/findings.sarif", "w") as f:
        json.dump(sarif, f, indent=2)

if __name__ == "__main__":
    main()