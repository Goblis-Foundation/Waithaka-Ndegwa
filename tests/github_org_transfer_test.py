from pathlib import Path
import json
ROOT=Path(__file__).resolve().parents[1]
pub=(ROOT/'deploy/github/publish-repository.sh').read_text()
boot=(ROOT/'deploy/aws/bootstrap-github-oidc.sh').read_text()
stack=json.loads((ROOT/'deploy/aws/github-oidc-stack.json').read_text())
assert 'ORG_OWNER="Goblis-Foundation"' in pub
assert 'TARGET_REPO="${ORG_OWNER}/Waithaka-Ndegwa"' in pub
assert '/transfer' in pub and 'new_owner="$ORG_OWNER"' in pub and 'new_name="Waithaka-Ndegwa"' in pub
assert 'GITHUB_REPOSITORY_OWNER_ID' in boot
stmt=stack['Resources']['GitHubDeployRole']['Properties']['AssumeRolePolicyDocument']['Statement'][0]
cond=stmt['Condition']['StringEquals']
assert 'token.actions.githubusercontent.com:repository_id' in cond
assert 'token.actions.githubusercontent.com:repository_owner_id' in cond
sub=cond['token.actions.githubusercontent.com:sub']['Fn::Sub']
assert 'Goblis-Foundation@${RepositoryOwnerId}/Waithaka-Ndegwa@${RepositoryId}' in sub
assert 'refs/heads/${DeployBranch}' in sub
print('github org transfer/OIDC tests passed')
