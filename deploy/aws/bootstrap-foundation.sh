#!/usr/bin/env bash
set -euo pipefail

MANAGEMENT_ACCOUNT="891923193638"
WORKLOAD_ACCOUNT="466401734428"
REGION="af-south-1"
ROLE_NAME="OrganizationAccountAccessRole"
APPLY=0
TWO_NAT="false"
OUT_DIR="${HOME}/waithaka-aws-bootstrap"

usage() {
  cat <<USAGE
Waithaka Ndegwa AWS foundation bootstrap

Usage:
  bash deploy/aws/bootstrap-foundation.sh [--apply] [--two-nat] [--role-name NAME] [--out-dir DIR]

Default mode is read-only. It verifies account 466401734428 and writes readiness evidence.
--apply creates only the reviewed IAM and VPC/network baseline in af-south-1.
It does NOT create Route 53 zones, change registrar nameservers, create RDS, deploy the website,
activate SES, or change production traffic.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --apply) APPLY=1; shift ;;
    --two-nat) TWO_NAT="true"; shift ;;
    --role-name) ROLE_NAME="${2:?missing role name}"; shift 2 ;;
    --out-dir) OUT_DIR="${2:?missing output directory}"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
done

command -v aws >/dev/null 2>&1 || { echo "AWS CLI is required (CloudShell includes it)." >&2; exit 2; }
command -v python3 >/dev/null 2>&1 || { echo "python3 is required." >&2; exit 2; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
mkdir -p "$OUT_DIR"
umask 077

caller_account="$(aws sts get-caller-identity --query Account --output text --no-cli-pager)"
echo "Entry AWS account: ${caller_account}"

cleanup() {
  unset AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_SESSION_TOKEN AWS_SECURITY_TOKEN || true
}
trap cleanup EXIT

if [[ "$caller_account" == "$MANAGEMENT_ACCOUNT" ]]; then
  echo "Assuming ${ROLE_NAME} into workload account ${WORKLOAD_ACCOUNT}..."
  read -r AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_SESSION_TOKEN < <(
    aws sts assume-role \
      --role-arn "arn:aws:iam::${WORKLOAD_ACCOUNT}:role/${ROLE_NAME}" \
      --role-session-name waithaka-foundation-bootstrap \
      --query 'Credentials.[AccessKeyId,SecretAccessKey,SessionToken]' \
      --output text --no-cli-pager
  )
  export AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_SESSION_TOKEN
  unset AWS_PROFILE || true
elif [[ "$caller_account" != "$WORKLOAD_ACCOUNT" ]]; then
  echo "Refusing to continue. Expected ${MANAGEMENT_ACCOUNT} or ${WORKLOAD_ACCOUNT}, got ${caller_account}." >&2
  exit 3
fi

export AWS_DEFAULT_REGION="$REGION" AWS_REGION="$REGION"
target_account="$(aws sts get-caller-identity --query Account --output text --no-cli-pager)"
[[ "$target_account" == "$WORKLOAD_ACCOUNT" ]] || { echo "Target identity is not ${WORKLOAD_ACCOUNT}." >&2; exit 4; }
echo "Target workload account verified: ${target_account}"

readiness="$OUT_DIR/account-readiness-$(date -u +%Y%m%dT%H%M%SZ).json"
python3 "$SCRIPT_DIR/account_readiness.py" --out "$readiness"
python3 - "$readiness" <<'PY'
import json,sys
p=sys.argv[1]
d=json.load(open(p,encoding='utf-8'))
assert str(d.get('workload_account_id')) == '466401734428'
assert str((d.get('target_identity') or {}).get('Account')) == '466401734428'
if not d.get('ready_to_prepare'):
    raise SystemExit('Readiness check did not establish the minimum platform/region prerequisites. Review '+p)
print('Readiness evidence:', p)
print('PHP 8.4 branch visible:', bool((d.get('elastic_beanstalk_php84') or {}).get('ok')))
print('MySQL 8.4 versions visible:', len(d.get('rds_mysql84_versions') or []))
PY

if [[ "$APPLY" -ne 1 ]]; then
  echo "Read-only readiness completed. Re-run with --apply to create only IAM + network foundation."
  exit 0
fi

# Validate every AWS-managed policy referenced by the IAM baseline before CloudFormation starts.
required_policies=(
  "arn:aws:iam::aws:policy/service-role/AWSElasticBeanstalkEnhancedHealth"
  "arn:aws:iam::aws:policy/AWSElasticBeanstalkManagedUpdatesCustomerRolePolicy"
  "arn:aws:iam::aws:policy/AWSElasticBeanstalkWebTier"
)
for policy_arn in "${required_policies[@]}"; do
  aws iam get-policy --policy-arn "$policy_arn" --query 'Policy.Arn' --output text --no-cli-pager >/dev/null
done
echo "Elastic Beanstalk managed-policy preflight passed."

# A stack that failed its first creation cannot be updated. For this exact foundation
# stack, ROLLBACK_COMPLETE means CloudFormation already rolled back its resources;
# delete the failed stack record before a reviewed --apply retry.
iam_stack_status="$(aws cloudformation describe-stacks \
  --region "$REGION" --stack-name waithaka-iam-baseline \
  --query 'Stacks[0].StackStatus' --output text --no-cli-pager 2>/dev/null || true)"
if [[ "$iam_stack_status" == "ROLLBACK_COMPLETE" ]]; then
  echo "Removing failed waithaka-iam-baseline stack in ROLLBACK_COMPLETE before retry..."
  aws cloudformation delete-stack --region "$REGION" --stack-name waithaka-iam-baseline --no-cli-pager
  aws cloudformation wait stack-delete-complete --region "$REGION" --stack-name waithaka-iam-baseline
fi

network_stack_status="$(aws cloudformation describe-stacks \
  --region "$REGION" --stack-name waithaka-network \
  --query 'Stacks[0].StackStatus' --output text --no-cli-pager 2>/dev/null || true)"
if [[ "$network_stack_status" == "ROLLBACK_COMPLETE" ]]; then
  echo "Removing failed waithaka-network stack in ROLLBACK_COMPLETE before retry..."
  aws cloudformation delete-stack --region "$REGION" --stack-name waithaka-network --no-cli-pager
  aws cloudformation wait stack-delete-complete --region "$REGION" --stack-name waithaka-network
fi

prefix_list_id="$(aws ec2 describe-managed-prefix-lists \
  --region "$REGION" \
  --filters Name=prefix-list-name,Values=com.amazonaws.global.cloudfront.origin-facing \
  --query 'PrefixLists[0].PrefixListId' --output text --no-cli-pager)"
if [[ -z "$prefix_list_id" || "$prefix_list_id" == "None" ]]; then
  echo "CloudFront origin-facing managed prefix list was not found in ${REGION}." >&2
  exit 5
fi

prefix_list_name="$(aws ec2 describe-managed-prefix-lists \
  --region "$REGION" \
  --prefix-list-ids "$prefix_list_id" \
  --query 'PrefixLists[0].PrefixListName' --output text --no-cli-pager)"
if [[ "$prefix_list_name" != "com.amazonaws.global.cloudfront.origin-facing" ]]; then
  echo "Resolved prefix list ${prefix_list_id} is not the CloudFront origin-facing AWS-managed list (got ${prefix_list_name})." >&2
  exit 6
fi
echo "CloudFront origin-facing prefix list verified: ${prefix_list_id}"

echo "Deploying reviewed IAM baseline..."
aws cloudformation deploy \
  --region "$REGION" \
  --stack-name waithaka-iam-baseline \
  --template-file "$SCRIPT_DIR/iam-baseline-stack.json" \
  --capabilities CAPABILITY_NAMED_IAM \
  --no-fail-on-empty-changeset \
  --no-cli-pager

echo "Deploying reviewed network baseline (two NAT gateways: ${TWO_NAT})..."
aws cloudformation deploy \
  --region "$REGION" \
  --stack-name waithaka-network \
  --template-file "$SCRIPT_DIR/network-stack.json" \
  --parameter-overrides \
    ProjectName=waithaka \
    CloudFrontOriginPrefixListId="$prefix_list_id" \
    UseTwoNatGateways="$TWO_NAT" \
  --no-fail-on-empty-changeset \
  --no-cli-pager

for stack in waithaka-iam-baseline waithaka-network; do
  aws cloudformation describe-stacks \
    --region "$REGION" --stack-name "$stack" \
    --query 'Stacks[0].{StackName:StackName,StackStatus:StackStatus,Outputs:Outputs}' \
    --output json --no-cli-pager > "$OUT_DIR/${stack}-outputs.json"
done

aws sts get-caller-identity --output json --no-cli-pager > "$OUT_DIR/final-caller-identity.json"

python3 - "$OUT_DIR" <<'PY'
import json, pathlib, sys
root=pathlib.Path(sys.argv[1])
for name in ('waithaka-iam-baseline','waithaka-network'):
    d=json.load(open(root/f'{name}-outputs.json',encoding='utf-8'))
    if d.get('StackStatus') not in ('CREATE_COMPLETE','UPDATE_COMPLETE'):
        raise SystemExit(f'{name} finished in unexpected state {d.get("StackStatus")}')
print('Foundation stacks verified as complete.')
PY

echo "Completed only IAM + network foundation in account ${WORKLOAD_ACCOUNT}."
echo "Evidence directory: ${OUT_DIR}"
echo "STOP: do not proceed to data, DNS, website traffic or SES until recovery inputs and certificates are reviewed."
