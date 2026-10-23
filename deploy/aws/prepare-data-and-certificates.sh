#!/usr/bin/env bash
set -euo pipefail

ACCOUNT_ID="466401734428"
REGION="af-south-1"
VIEWER_REGION="us-east-1"
NETWORK_STACK="waithaka-network"
DATA_STACK="waithaka-ndegwa-production-466401734428-data"
DEPLOYMENT_ID="waithaka-ndegwa-production-466401734428"
DB_NAME="waithaka"
DB_CLASS="${DB_CLASS:-db.t4g.small}"
STATE_DIR="${STATE_DIR:-$HOME/waithaka-aws-bootstrap}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DATA_TEMPLATE="$HERE/data-stack.json"
MODE_PLAN=true
REQUEST_CERTS=false
APPLY_DATA=false

usage() {
  cat <<'EOF'
Usage: prepare-data-and-certificates.sh [--request-certificates] [--apply-data] [--all]

Default is read-only planning.
  --request-certificates  Request/reuse ACM public certificates. No DNS changes are made.
  --apply-data            Create/reuse the persistent RDS + S3 data stack. RDS is billable.
  --all                   Perform both actions after all preflights pass.

Environment overrides:
  DB_CLASS=db.t4g.small   Reviewed initial RDS instance class.
  STATE_DIR=...           Private evidence directory (default ~/waithaka-aws-bootstrap).
EOF
}

while (($#)); do
  case "$1" in
    --request-certificates) REQUEST_CERTS=true; MODE_PLAN=false ;;
    --apply-data) APPLY_DATA=true; MODE_PLAN=false ;;
    --all) REQUEST_CERTS=true; APPLY_DATA=true; MODE_PLAN=false ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

command -v aws >/dev/null || { echo "AWS CLI is required." >&2; exit 2; }
command -v jq >/dev/null || { echo "jq is required (CloudShell includes it)." >&2; exit 2; }
mkdir -p "$STATE_DIR"
chmod 700 "$STATE_DIR"

current_account="$(aws sts get-caller-identity --query Account --output text --no-cli-pager)"
current_arn="$(aws sts get-caller-identity --query Arn --output text --no-cli-pager)"
echo "AWS account: $current_account"
echo "AWS identity: $current_arn"
[[ "$current_account" == "$ACCOUNT_ID" ]] || { echo "Refusing: expected account $ACCOUNT_ID." >&2; exit 3; }
[[ "$current_arn" != *":root" ]] || { echo "Refusing: run as waithaka-admin or another approved non-root administrator." >&2; exit 3; }

for stack in waithaka-iam-baseline "$NETWORK_STACK"; do
  status="$(aws cloudformation describe-stacks --stack-name "$stack" --region "$REGION" --query 'Stacks[0].StackStatus' --output text --no-cli-pager 2>/dev/null || true)"
  [[ "$status" == "CREATE_COMPLETE" || "$status" == "UPDATE_COMPLETE" ]] || {
    echo "Foundation stack $stack is not complete (status: ${status:-missing})." >&2
    exit 4
  }
done

stack_output() {
  local key="$1"
  aws cloudformation describe-stacks --stack-name "$NETWORK_STACK" --region "$REGION" \
    --query "Stacks[0].Outputs[?OutputKey=='$key'].OutputValue | [0]" --output text --no-cli-pager
}

VPC_ID="$(stack_output VpcId)"
DB_SUBNETS="$(stack_output PrivateDbSubnetIds)"
APP_SG="$(stack_output AppSecurityGroupId)"
[[ "$VPC_ID" == vpc-* && "$DB_SUBNETS" == subnet-*,subnet-* && "$APP_SG" == sg-* ]] || {
  echo "Network outputs are incomplete; refusing data preparation." >&2; exit 4;
}

PHP_PLATFORM_JSON="$(aws elasticbeanstalk list-platform-versions --region "$REGION" \
  --filters '[{"Type":"PlatformBranchName","Operator":"begins_with","Values":["PHP 8.4"]},{"Type":"PlatformStatus","Operator":"=","Values":["Ready"]},{"Type":"PlatformLifecycleState","Operator":"=","Values":["recommended"]}]' \
  --query 'PlatformSummaryList[0].{Arn:PlatformArn,Branch:PlatformBranchName,Version:PlatformVersion,Lifecycle:PlatformLifecycleState,Status:PlatformStatus}' \
  --output json --no-cli-pager)"
PHP_PLATFORM_ARN="$(jq -r '.Arn // empty' <<<"$PHP_PLATFORM_JSON")"
PHP_PLATFORM_BRANCH="$(jq -r '.Branch // empty' <<<"$PHP_PLATFORM_JSON")"
[[ "$PHP_PLATFORM_ARN" == arn:aws:elasticbeanstalk:${REGION}::* && "$PHP_PLATFORM_BRANCH" == "PHP 8.4"* ]] || {
  echo "No recommended Ready PHP 8.4 Elastic Beanstalk platform was resolved." >&2
  echo "$PHP_PLATFORM_JSON" >&2
  exit 4
}

DB_VERSION="$(aws rds describe-db-engine-versions --region "$REGION" --engine mysql \
  --query 'DBEngineVersions[?starts_with(EngineVersion, `8.4.`)].EngineVersion' --output text --no-cli-pager \
  | tr '\t' '\n' | sort -V | tail -1)"
[[ "$DB_VERSION" =~ ^8\.4\.[0-9]+$ ]] || { echo "No MySQL 8.4 minor was resolved." >&2; exit 4; }

ORDERABLE="$(aws rds describe-orderable-db-instance-options --region "$REGION" --engine mysql --engine-version "$DB_VERSION" \
  --db-instance-class "$DB_CLASS" --query 'length(OrderableDBInstanceOptions)' --output text --no-cli-pager)"
[[ "$ORDERABLE" =~ ^[1-9][0-9]*$ ]] || { echo "$DB_CLASS is not orderable for MySQL $DB_VERSION in $REGION." >&2; exit 4; }

echo "Foundation outputs verified."
echo "PHP platform: $PHP_PLATFORM_ARN"
echo "RDS engine: MySQL $DB_VERSION"
echo "RDS class: $DB_CLASS"
echo "Data stack: $DATA_STACK"

cert_exact_match() {
  local region="$1" primary="$2" expected_json="$3" arn desc actual
  while read -r arn; do
    [[ -n "$arn" && "$arn" != "None" ]] || continue
    desc="$(aws acm describe-certificate --region "$region" --certificate-arn "$arn" --output json --no-cli-pager)"
    actual="$(jq -c '[.Certificate.SubjectAlternativeNames[]] | sort' <<<"$desc")"
    if [[ "$actual" == "$expected_json" ]]; then printf '%s\n' "$arn"; return 0; fi
  done < <(aws acm list-certificates --region "$region" \
    --query "CertificateSummaryList[?DomainName=='$primary'].CertificateArn" --output text --no-cli-pager | tr '\t' '\n')
  return 1
}

request_or_reuse_cert() {
  local region="$1" purpose="$2" primary="$3"; shift 3
  local domains=("$primary" "$@") expected arn args token
  expected="$(printf '%s\n' "${domains[@]}" | jq -R . | jq -sc 'sort')"
  if arn="$(cert_exact_match "$region" "$primary" "$expected")"; then
    echo "Reusing $purpose certificate: $arn" >&2
  else
    args=(--region "$region" --domain-name "$primary" --validation-method DNS --key-algorithm RSA_2048 \
          --options CertificateTransparencyLoggingPreference=ENABLED \
          --tags "Key=Project,Value=WaithakaNdegwa" "Key=Purpose,Value=$purpose")
    if (($#)); then args+=(--subject-alternative-names "$@"); fi
    token="$(printf '%s-%s' "$region" "$purpose" | sha256sum | cut -c1-32)"
    args+=(--idempotency-token "$token")
    arn="$(aws acm request-certificate "${args[@]}" --query CertificateArn --output text --no-cli-pager)"
    echo "Requested $purpose certificate: $arn" >&2
  fi
  printf '%s\n' "$arn"
}

cert_evidence() {
  local arn="$1" region="$2" tries=0 desc
  while ((tries < 12)); do
    desc="$(aws acm describe-certificate --region "$region" --certificate-arn "$arn" --output json --no-cli-pager)"
    if jq -e '(.Certificate.DomainValidationOptions // []) as $d | [$d[] | select(.ResourceRecord != null)] | length == ($d|length)' >/dev/null <<<"$desc"; then
      jq '{CertificateArn:.Certificate.CertificateArn,Status:.Certificate.Status,DomainName:.Certificate.DomainName,SubjectAlternativeNames:.Certificate.SubjectAlternativeNames,ValidationRecords:[.Certificate.DomainValidationOptions[] | {DomainName,ValidationStatus,ResourceRecord}]}' <<<"$desc"
      return
    fi
    tries=$((tries+1)); sleep 2
  done
  jq '{CertificateArn:.Certificate.CertificateArn,Status:.Certificate.Status,DomainName:.Certificate.DomainName,SubjectAlternativeNames:.Certificate.SubjectAlternativeNames,ValidationRecords:[.Certificate.DomainValidationOptions[] | {DomainName,ValidationStatus,ResourceRecord}]}' <<<"$desc"
}

VIEWER_ARN=""
ORIGIN_ARN=""
if $REQUEST_CERTS; then
  VIEWER_ARN="$(request_or_reuse_cert "$VIEWER_REGION" CloudFrontViewer www.waithakandegwa.com waithakandegwa.com waithakandegwa.co.ke www.waithakandegwa.co.ke)"
  ORIGIN_ARN="$(request_or_reuse_cert "$REGION" RegionalOrigin origin.waithakandegwa.com)"
fi

DATA_OUTPUTS='{}'
if $APPLY_DATA; then
  existing_status="$(aws cloudformation describe-stacks --stack-name "$DATA_STACK" --region "$REGION" --query 'Stacks[0].StackStatus' --output text --no-cli-pager 2>/dev/null || true)"
  if [[ "$existing_status" == "ROLLBACK_COMPLETE" ]]; then
    echo "Removing failed data stack in ROLLBACK_COMPLETE before retry..."
    aws cloudformation delete-stack --stack-name "$DATA_STACK" --region "$REGION" --no-cli-pager
    aws cloudformation wait stack-delete-complete --stack-name "$DATA_STACK" --region "$REGION"
    existing_status=""
  fi
  if [[ -n "$existing_status" && "$existing_status" != "CREATE_COMPLETE" && "$existing_status" != "UPDATE_COMPLETE" ]]; then
    echo "Data stack exists in non-stable state $existing_status; reconcile before retry." >&2; exit 5
  fi

  echo "Deploying persistent data foundation. This creates a billable RDS instance ($DB_CLASS)."
  aws cloudformation deploy --region "$REGION" --stack-name "$DATA_STACK" --template-file "$DATA_TEMPLATE" \
    --parameter-overrides \
      "VpcId=$VPC_ID" \
      "PrivateSubnetIds=$DB_SUBNETS" \
      "ApplicationSecurityGroupId=$APP_SG" \
      "DatabaseName=$DB_NAME" \
      "DatabaseClass=$DB_CLASS" \
      "DatabaseEngineVersion=$DB_VERSION" \
      "MultiAZ=false" \
      "MediaDistributionArn=" \
    --tags "WaithakaDeployment=$DEPLOYMENT_ID" "Project=WaithakaNdegwa" "Environment=production" \
    --no-fail-on-empty-changeset --no-cli-pager

  DATA_OUTPUTS="$(aws cloudformation describe-stacks --stack-name "$DATA_STACK" --region "$REGION" \
    --query 'Stacks[0].Outputs' --output json --no-cli-pager | jq 'map({(.OutputKey):.OutputValue})|add')"
  [[ "$(aws cloudformation describe-stacks --stack-name "$DATA_STACK" --region "$REGION" --query 'Stacks[0].StackStatus' --output text --no-cli-pager)" =~ ^(CREATE_COMPLETE|UPDATE_COMPLETE)$ ]] || {
    echo "Data stack did not finish in a complete state." >&2; exit 5;
  }
fi

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
report="$STATE_DIR/staging-foundation-$stamp.json"
viewer_evidence='null'; origin_evidence='null'
if [[ -n "$VIEWER_ARN" ]]; then viewer_evidence="$(cert_evidence "$VIEWER_ARN" "$VIEWER_REGION")"; fi
if [[ -n "$ORIGIN_ARN" ]]; then origin_evidence="$(cert_evidence "$ORIGIN_ARN" "$REGION")"; fi

jq -n \
  --arg checked_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  --arg account "$ACCOUNT_ID" --arg region "$REGION" --arg vpc "$VPC_ID" \
  --arg db_subnets "$DB_SUBNETS" --arg app_sg "$APP_SG" --arg php "$PHP_PLATFORM_ARN" \
  --arg db_version "$DB_VERSION" --arg db_class "$DB_CLASS" \
  --argjson viewer "$viewer_evidence" --argjson origin "$origin_evidence" --argjson data "$DATA_OUTPUTS" \
  '{schema_version:1,kind:"waithaka-staging-foundation",checked_at:$checked_at,account_id:$account,region:$region,network:{vpc_id:$vpc,database_subnet_ids:($db_subnets|split(",")),application_security_group_id:$app_sg},runtime:{php_platform_arn:$php,mysql_version:$db_version,database_class:$db_class},certificates:{viewer:$viewer,origin:$origin},data:$data}' \
  > "$report"
chmod 600 "$report"
ln -sfn "$(basename "$report")" "$STATE_DIR/staging-foundation-latest.json"

echo "Evidence: $report"
if $REQUEST_CERTS; then
  echo "Certificate DNS validation records (no DNS changes were made):"
  jq -r '.certificates | to_entries[] | select(.value != null) | .key as $kind | .value.ValidationRecords[] | [$kind,.DomainName,.ValidationStatus,(.ResourceRecord.Name // "pending"),(.ResourceRecord.Value // "pending")] | @tsv' "$report" | column -t -s $'\t'
fi
if $APPLY_DATA; then
  echo "Data foundation verified complete. RDS deletion protection is enabled; S3 buckets are versioned and retained."
  jq '.data' "$report"
fi
if $MODE_PLAN; then
  echo "Read-only preparation complete. Use --request-certificates, --apply-data, or --all after review."
fi
