#!/usr/bin/env bash
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STATE_DIR="${STATE_DIR:-$HOME/waithaka-aws-bootstrap}"
ACCOUNT_ID="466401734428"
REGION="af-south-1"
DATA_STACK="waithaka-ndegwa-production-466401734428-data"

usage(){
  cat <<'TXT'
Usage: continue-staging.sh [--apply-data]

Without --apply-data: performs all read-only checks and requests/reuses ACM certificates,
then reports the exact DNS-validation records. It makes no DNS changes and creates no RDS.

With --apply-data: after those checks, creates/reuses the retained S3 + RDS data foundation.
This creates a billable RDS instance if the data stack does not already exist.

This script intentionally stops before Route 53 delegation, registrar glue, CloudFront traffic,
Elastic Beanstalk application deployment, SES sending, workers, or production cutover.
TXT
}

APPLY_DATA=false
while (($#)); do
  case "$1" in
    --apply-data) APPLY_DATA=true ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

command -v aws >/dev/null || { echo "AWS CLI required." >&2; exit 2; }
command -v jq >/dev/null || { echo "jq required." >&2; exit 2; }
mkdir -p "$STATE_DIR" && chmod 700 "$STATE_DIR"

ACCOUNT="$(aws sts get-caller-identity --query Account --output text --no-cli-pager)"
ARN="$(aws sts get-caller-identity --query Arn --output text --no-cli-pager)"
echo "AWS account: $ACCOUNT"
echo "AWS identity: $ARN"
[[ "$ACCOUNT" == "$ACCOUNT_ID" ]] || { echo "Refusing: expected $ACCOUNT_ID." >&2; exit 3; }
[[ "$ARN" != *":root" ]] || { echo "Refusing: use waithaka-admin, not root." >&2; exit 3; }

for stack in waithaka-iam-baseline waithaka-network; do
  st="$(aws cloudformation describe-stacks --stack-name "$stack" --region "$REGION" --query 'Stacks[0].StackStatus' --output text --no-cli-pager 2>/dev/null || true)"
  [[ "$st" == "CREATE_COMPLETE" || "$st" == "UPDATE_COMPLETE" ]] || { echo "Foundation stack $stack not complete: ${st:-missing}" >&2; exit 4; }
  echo "Foundation: $stack = $st"
done

# Always request/reuse certificates and persist clean evidence.
"$HERE/prepare-data-and-certificates.sh" --request-certificates
CERT_REPORT="$(readlink -f "$STATE_DIR/staging-foundation-latest.json")"

viewer_status="$(jq -r '.certificates.viewer.Status // "missing"' "$CERT_REPORT")"
origin_status="$(jq -r '.certificates.origin.Status // "missing"' "$CERT_REPORT")"
echo "Viewer certificate: $viewer_status"
echo "Origin certificate: $origin_status"

# Produce a clean machine-readable DNS validation handoff without mutating DNS.
VALIDATION="$STATE_DIR/certificate-validation-latest.tsv"
jq -r '.certificates | to_entries[] | select(.value != null) | .key as $kind | .value.ValidationRecords[] | select(.ResourceRecord != null) | [$kind,.DomainName,.ValidationStatus,.ResourceRecord.Name,.ResourceRecord.Type,.ResourceRecord.Value] | @tsv' "$CERT_REPORT" > "$VALIDATION"
chmod 600 "$VALIDATION"
echo "Certificate validation handoff: $VALIDATION"
column -t -s $'\t' "$VALIDATION" || cat "$VALIDATION"

if $APPLY_DATA; then
  echo
  echo "Applying persistent data foundation (billable RDS if absent)..."
  "$HERE/prepare-data-and-certificates.sh" --apply-data
  DATA_STATUS="$(aws cloudformation describe-stacks --stack-name "$DATA_STACK" --region "$REGION" --query 'Stacks[0].StackStatus' --output text --no-cli-pager 2>/dev/null || true)"
  [[ "$DATA_STATUS" == "CREATE_COMPLETE" || "$DATA_STATUS" == "UPDATE_COMPLETE" ]] || { echo "Data stack not complete: ${DATA_STATUS:-missing}" >&2; exit 5; }
  echo "Data foundation: $DATA_STATUS"
fi

echo
echo "STAGING AUTOMATION STOP POINT"
echo "Completed/reused: IAM foundation, network foundation, ACM requests"
$APPLY_DATA && echo "Completed/reused: RDS + S3 data foundation"
echo "Not changed: registrar delegation, branded nameservers, live DNS, CloudFront traffic, SES, workers, production site"
echo "Next external prerequisites: certificate DNS validation and live-host recovery inputs (database, original app key, uploads)."
