from github import Github
import os
import re

token = os.environ['GITHUB_TOKEN']
repo_name = os.environ['GITHUB_REPOSITORY']
pr_number = int(os.environ['PR_NUMBER'])

g = Github(token)
repo = g.get_repo(repo_name)
pr = repo.get_pull(pr_number)

def find_position_in_diff(patch, target_line):
    diff_lines = patch.split('\n')
    position = 0
    current_file_line = None

    for line in diff_lines:
        position += 1
        if line.startswith('@@'):
            # Extract starting line of new file hunk
            m = re.search(r'\+(\d+)(?:,(\d+))?', line)
            if m:
                current_file_line = int(m.group(1)) - 1
        elif line.startswith('+') and not line.startswith('+++'):
            current_file_line += 1
            if current_file_line == target_line:
                return position
        elif not line.startswith('-'):
            # Context line increments original file line number too
            current_file_line += 1

    return None

# Read feedback.txt
if not os.path.exists("code_review/feedback.txt"):
    print("feedback.txt not found.")
    exit(0)

with open("code_review/feedback.txt", "r") as f:
    lines = [line.strip() for line in f if line.strip()]

current_file = None

review_comments = []

for line in lines:
    file_match = re.match(r'File: (.*)', line)
    if file_match:
        current_file = file_match.group(1)
        continue

    warn_match = re.match(r"line no (\d+)", line)
    if current_file and warn_match:
        line_no = int(warn_match.group(1))

        # Find file in PR
        pr_file = next((f for f in pr.get_files() if f.filename == current_file), None)
        if not pr_file:
            print(f"File {current_file} not found in PR files.")
            continue

        position = find_position_in_diff(pr_file.patch or "", line_no)
        if position is None:
            print(f"Could not find diff position for {current_file} line {line_no}")
            continue

        review_comments.append({
            'path': current_file,
            'position': position,
            'body': "Better to remove `var_dump()`"
        })

if review_comments:
    pr.create_review(body="Automated PHP Code Review Feedback", comments=review_comments)
    print(f"Posted {len(review_comments)} inline comments.")
else:
    pr.create_issue_comment("Nice implementation!")
    print("Nice implementation!")