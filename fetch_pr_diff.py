from github import Github
import os

token = os.environ['GITHUB_TOKEN']
repo_name = os.environ['GITHUB_REPOSITORY']
pr_number = int(os.environ['PR_NUMBER'])

g = Github(token)
repo = g.get_repo(repo_name)
pr = repo.get_pull(pr_number)

changed_files = []
for file in pr.get_files():
    if file.filename.endswith('.php'):
        changed_files.append(file.filename)

with open("changed_files.txt", "w") as f:
    for file in changed_files:
        f.write(file + "\n")
