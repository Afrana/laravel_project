import os, json, re, sys
from github import Github

TOKEN = os.getenv("GITHUB_TOKEN")
REPO = os.getenv("GITHUB_REPOSITORY")
PR_NUMBER = int(os.getenv("PR_NUMBER", "0"))

if not (TOKEN and REPO and PR_NUMBER):
    print("Missing env vars GITHUB_TOKEN/GITHUB_REPOSITORY/PR_NUMBER", file=sys.stderr)
    sys.exit(1)

g = Github(TOKEN)
repo = g.get_repo(REPO)
pr = repo.get_pull(PR_NUMBER)

def parse_added_hunks(patch: str):
    # Return list of (start, length) added ranges in NEW file coordinates
    if not patch:
        return []
    hunks = []
    for line in patch.splitlines():
        m = re.match(r'^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@', line)
        if m:
            start = int(m.group(1))
            length = int(m.group(2) or "1")
            hunks.append([start, length])
    return hunks

changed_php = []
hunk_map = {}
for f in pr.get_files():
    if f.status in ("removed", "unchanged"):
        continue
    if not f.filename.endswith(".php"):
        continue
    changed_php.append(f.filename)
    hunk_map[f.filename] = parse_added_hunks(f.patch)

labels = [lbl.name for lbl in pr.get_labels()]

with open("code_review/changed_files.txt", "w") as out:
    for p in changed_php:
        out.write(p + "\n")

with open("code_review/changed_hunks.json", "w") as out:
    json.dump(hunk_map, out, indent=2)

with open("code_review/labels.json", "w") as out:
    json.dump(labels, out)

print(f"Collected {len(changed_php)} PHP files and hunk ranges for PR #{PR_NUMBER}")
