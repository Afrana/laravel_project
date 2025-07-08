from github import Github
import os

token = os.environ['GITHUB_TOKEN']
repo_name = os.environ['GITHUB_REPOSITORY']
pr_number = int(os.environ['PR_NUMBER'])

g = Github(token)
repo = g.get_repo(repo_name)
pr = repo.get_pull(pr_number)

if os.path.exists("feedback.txt"):
    with open("feedback.txt", "r") as f:
        feedback = f.read()
    if feedback.strip():
        pr.create_review_comment(f"**Automated PHP Code Review Feedback:**\n\n{feedback}")
    else:
        print("No feedback to post.")
else:
    print("feedback.txt not found.")
