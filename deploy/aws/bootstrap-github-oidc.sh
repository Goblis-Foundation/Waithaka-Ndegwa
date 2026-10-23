#!/usr/bin/env bash
set -euo pipefail
ACCOUNT_ID="466401734428"
REGION="${AWS_REGION:-af-south-1}"
STACK="waithaka-github-oidc"
REPO_ID="${GITHUB_REPOSITORY_ID:-}"
REPO_FULL_NAME="${GITHUB_REPOSITORY_FULL_NAME:-}"
REPO_OWNER_ID="${GITHUB_REPOSITORY_OWNER_ID:-}"
BRANCH="${GITHUB_DEPLOY_BRANCH:-main}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
fail(){ echo "ERROR: $*" >&2; exit 1; }
[[ "$(aws sts get-caller-identity --query Account --output text --no-cli-pager)" == "$ACCOUNT_ID" ]] || fail "Run in AWS account $ACCOUNT_ID."
[[ -n "$REPO_ID" && "$REPO_ID" =~ ^[0-9]+$ ]] || fail "Set GITHUB_REPOSITORY_ID to the numeric repository ID."
[[ -n "$REPO_FULL_NAME" ]] || fail "Set GITHUB_REPOSITORY_FULL_NAME (expected Goblis-Foundation/Waithaka-Ndegwa)."
[[ -n "$REPO_OWNER_ID" && "$REPO_OWNER_ID" =~ ^[0-9]+$ ]] || fail "Set GITHUB_REPOSITORY_OWNER_ID to the numeric Goblis Foundation organization ID."
PROVIDER_ARN="arn:aws:iam::${ACCOUNT_ID}:oidc-provider/token.actions.githubusercontent.com"
if aws iam get-open-id-connect-provider --open-id-connect-provider-arn "$PROVIDER_ARN" --no-cli-pager >/dev/null 2>&1; then EXISTING="$PROVIDER_ARN"; else EXISTING=""; fi
aws cloudformation deploy \
  --template-file "$ROOT/deploy/aws/github-oidc-stack.json" \
  --stack-name "$STACK" \
  --parameter-overrides RepositoryId="$REPO_ID" RepositoryOwnerId="$REPO_OWNER_ID" RepositoryFullName="$REPO_FULL_NAME" DeployBranch="$BRANCH" ExistingProviderArn="$EXISTING" \
  --capabilities CAPABILITY_NAMED_IAM \
  --region "$REGION" \
  --no-cli-pager
ROLE_ARN="$(aws cloudformation describe-stacks --stack-name "$STACK" --region "$REGION" --query "Stacks[0].Outputs[?OutputKey=='DeployRoleArn'].OutputValue | [0]" --output text --no-cli-pager)"
printf '{"role_arn":"%s","repository_id":"%s","repository_owner_id":"%s","repository":"%s","branch":"%s"}\n' "$ROLE_ARN" "$REPO_ID" "$REPO_OWNER_ID" "$REPO_FULL_NAME" "$BRANCH"
