from github import Github
import os

token = os.environ['GITHUB_TOKEN']
repo_name = os.environ['GITHUB_REPOSITORY']
pr_number = int(os.environ['PR_NUMBER'])

g = Github(token)
repo = g.get_repo(repo_name)
pr = repo.get_pull(pr_number)

feedback = "**Automated PHP Code Review Summary:**\n\n- ⚠️ Found use of `var_dump()` in `MyClass.php` line 42"

pr.create_issue_comment(feedback)
