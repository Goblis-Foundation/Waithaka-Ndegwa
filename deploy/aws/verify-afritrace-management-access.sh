#!/usr/bin/env bash
set -euo pipefail

MANAGEMENT_ACCOUNT="891923193638"
WORKLOAD_ACCOUNT="466401734428"
ROLE_NAME="${ROLE_NAME:-OrganizationAccountAccessRole}"
REGION="${AWS_REGION:-af-south-1}"

command -v aws >/dev/null 2>&1 || { echo "AWS CLI is required (CloudShell includes it)." >&2; exit 2; }

entry_account="$(aws sts get-caller-identity --query Account --output text --no-cli-pager)"
entry_arn="$(aws sts get-caller-identity --query Arn --output text --no-cli-pager)"

echo "Entry account: ${entry_account}"
echo "Entry identity: ${entry_arn}"

if [[ "$entry_account" != "$MANAGEMENT_ACCOUNT" ]]; then
  echo "Refusing to test the AfriTrace management path: expected ${MANAGEMENT_ACCOUNT}, got ${entry_account}." >&2
  exit 3
fi

read -r AK SK ST < <(
  aws sts assume-role \
    --role-arn "arn:aws:iam::${WORKLOAD_ACCOUNT}:role/${ROLE_NAME}" \
    --role-session-name waithaka-access-verification \
    --query 'Credentials.[AccessKeyId,SecretAccessKey,SessionToken]' \
    --output text --no-cli-pager
)

result="$(AWS_ACCESS_KEY_ID="$AK" AWS_SECRET_ACCESS_KEY="$SK" AWS_SESSION_TOKEN="$ST" AWS_DEFAULT_REGION="$REGION" \
  aws sts get-caller-identity --output json --no-cli-pager)"

python3 - <<'PY' "$result"
import json, sys
obj=json.loads(sys.argv[1])
assert obj.get('Account') == '466401734428', obj
print(json.dumps({
  'verified': True,
  'target_account': obj.get('Account'),
  'assumed_role_arn': obj.get('Arn'),
}, indent=2))
PY
