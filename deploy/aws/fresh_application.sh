#!/usr/bin/env bash
set -euo pipefail
REGION="${AWS_REGION:-af-south-1}"
ACCOUNT="466401734428"
APP_NAME="waithaka-ndegwa"
ENV_NAME="waithaka-ndegwa-production"
DATA_STACK="waithaka-ndegwa-production-466401734428-data"
NETWORK_STACK="waithaka-network"
IAM_STACK="waithaka-iam-baseline"
EDGE_STACK="waithaka-edge"
RUNTIME_SECRET_NAME="waithaka-ndegwa-production-runtime"
WRITER_EMAIL="isaac@waithakandegwa.com"
WRITER_NAME="Waithaka Ndegwa"
CANONICAL="www.waithakandegwa.com"
ORIGIN="origin.waithakandegwa.com"
STATE_DIR="$HOME/waithaka-aws-bootstrap"
DNS_STATE="$STATE_DIR/fresh-dns-state.json"
CRED_FILE="$STATE_DIR/writer-initial-credentials.json"
SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUTER_DIR="$(cd "$SOURCE_DIR/.." && pwd)"
APP_ZIP="$OUTER_DIR/deployment/application.zip"
mkdir -p "$STATE_DIR"

fail(){ echo "$*" >&2; exit 1; }
aws_account(){ aws sts get-caller-identity --query Account --output text --no-cli-pager; }
[[ "$(aws_account)" == "$ACCOUNT" ]] || fail "Refusing to deploy outside account $ACCOUNT."
[[ -f "$APP_ZIP" ]] || fail "Application bundle not found at $APP_ZIP. Upload/extract the complete release, not source alone."

stack_output(){ aws cloudformation describe-stacks --stack-name "$1" --region "$REGION" --query "Stacks[0].Outputs[?OutputKey=='$2'].OutputValue | [0]" --output text --no-cli-pager; }
require_stack(){ local s="$1"; local st; st="$(aws cloudformation describe-stacks --stack-name "$s" --region "$REGION" --query 'Stacks[0].StackStatus' --output text --no-cli-pager 2>/dev/null || true)"; [[ "$st" =~ ^(CREATE_COMPLETE|UPDATE_COMPLETE|UPDATE_ROLLBACK_COMPLETE)$ ]] || fail "Required stack $s is not complete (status: ${st:-missing})."; }
find_cert(){ local reg="$1" dom="$2"; aws acm list-certificates --region "$reg" --certificate-statuses PENDING_VALIDATION ISSUED --query "CertificateSummaryList[?DomainName=='$dom'].CertificateArn | [0]" --output text --no-cli-pager; }
cert_status(){ aws acm describe-certificate --region "$1" --certificate-arn "$2" --query 'Certificate.Status' --output text --no-cli-pager; }

require_stack "$NETWORK_STACK"; require_stack "$IAM_STACK"; require_stack "$DATA_STACK"
[[ -f "$DNS_STATE" ]] || fail "Fresh Route 53 DNS has not been prepared. Run: python3 deploy/aws/fresh_dns.py prepare"

PLATFORM_ARN="$(aws elasticbeanstalk list-platform-versions --region "$REGION" --filters '[{"Type":"PlatformBranchName","Operator":"begins_with","Values":["PHP 8.4"]},{"Type":"PlatformStatus","Operator":"=","Values":["Ready"]},{"Type":"PlatformLifecycleState","Operator":"=","Values":["recommended"]}]' --query 'PlatformSummaryList[0].PlatformArn' --output text --no-cli-pager)"
[[ "$PLATFORM_ARN" == arn:aws:elasticbeanstalk:* ]] || fail "Unable to resolve the recommended PHP 8.4 platform."

DB_HOST="$(stack_output "$DATA_STACK" DatabaseHost)"
ADMIN_SECRET="$(stack_output "$DATA_STACK" AdminSecretArn)"
PUBLIC_BUCKET="$(stack_output "$DATA_STACK" PublicMedia)"
PRIVATE_BUCKET="$(stack_output "$DATA_STACK" PrivateMedia)"
RELEASE_BUCKET="$(stack_output "$DATA_STACK" ReleaseArtifacts)"
VPC_ID="$(stack_output "$NETWORK_STACK" VpcId)"
APP_SUBNETS="$(stack_output "$NETWORK_STACK" PrivateAppSubnetIds)"
ALB_SUBNETS="$(stack_output "$NETWORK_STACK" PublicAlbSubnetIds)"
APP_SG="$(stack_output "$NETWORK_STACK" AppSecurityGroupId)"
ALB_SG="$(stack_output "$NETWORK_STACK" AlbSecurityGroupId)"
SERVICE_ROLE="$(stack_output "$IAM_STACK" ServiceRoleName)"
INSTANCE_ROLE="$(stack_output "$IAM_STACK" InstanceRoleName)"
INSTANCE_PROFILE="$(stack_output "$IAM_STACK" InstanceProfileName)"
VPC_CIDR="$(aws ec2 describe-vpcs --vpc-ids "$VPC_ID" --region "$REGION" --query 'Vpcs[0].CidrBlock' --output text --no-cli-pager)"

ORIGIN_CERT="$(find_cert "$REGION" "$ORIGIN")"
[[ "$ORIGIN_CERT" == arn:aws:acm:* ]] || fail "Origin certificate is missing."
[[ "$(cert_status "$REGION" "$ORIGIN_CERT")" == "ISSUED" ]] || fail "Origin certificate is not ISSUED yet. Complete registrar delegation / ACM DNS validation first."

ensure_secret(){
  local arn tmp
  arn="$(aws secretsmanager describe-secret --secret-id "$RUNTIME_SECRET_NAME" --region "$REGION" --query ARN --output text --no-cli-pager 2>/dev/null || true)"
  if [[ "$arn" == arn:aws:secretsmanager:* ]]; then echo "$arn"; return; fi
  tmp="$(mktemp)"
  python3 - "$tmp" "$CRED_FILE" <<'PY'
import json,secrets,sys,pathlib,os
out=pathlib.Path(sys.argv[1]); cred=pathlib.Path(sys.argv[2])
writer=secrets.token_urlsafe(26)
data={'APP_KEY':secrets.token_urlsafe(48),'DB_USERNAME':'wk_app','DB_PASSWORD':secrets.token_urlsafe(40),'WRITER_INITIAL_PASSWORD':writer}
out.write_text(json.dumps(data))
cred.parent.mkdir(parents=True,exist_ok=True);cred.write_text(json.dumps({'writer_email':'isaac@waithakandegwa.com','initial_password':writer},indent=2)+'\n');os.chmod(cred,0o600)
PY
  arn="$(aws secretsmanager create-secret --name "$RUNTIME_SECRET_NAME" --description 'Waithaka Ndegwa runtime application secrets' --secret-string "file://$tmp" --region "$REGION" --query ARN --output text --no-cli-pager)"
  rm -f "$tmp"; echo "$arn"
}
RUNTIME_SECRET="$(ensure_secret)"

# A successful prior bootstrap is recorded by the explicit disabled flag.
# Routine deployments must not reopen database administrator access.
BOOTSTRAP_REQUIRED=1
PREVIOUS_BOOTSTRAP="$(aws elasticbeanstalk describe-configuration-settings --application-name "$APP_NAME" --environment-name "$ENV_NAME" --region "$REGION" --query "ConfigurationSettings[0].OptionSettings[?Namespace=='aws:elasticbeanstalk:application:environment' && OptionName=='APP_FRESH_BOOTSTRAP'].Value | [0]" --output text --no-cli-pager 2>/dev/null || true)"
[[ "$PREVIOUS_BOOTSTRAP" == 0 ]] && BOOTSTRAP_REQUIRED=0
# A checksum-bound receipt prevents routine deployments from reopening migration access.
GOOGLE_MIGRATION_REQUIRED=0
GOOGLE_MIGRATION_FILE="$SOURCE_DIR/app/migrations/160_google_workspace.sql"
GOOGLE_MIGRATION_RECEIPT=""
if [[ -f "$SOURCE_DIR/bin/google-workspace-migrate.php" && -f "$GOOGLE_MIGRATION_FILE" ]]; then
  GOOGLE_MIGRATION_HASH="$(sha256sum "$GOOGLE_MIGRATION_FILE" | awk '{print $1}')"
  GOOGLE_MIGRATION_RECEIPT="schema-migrations/160-$GOOGLE_MIGRATION_HASH.json"
  if ! aws s3api head-object --bucket "$RELEASE_BUCKET" --key "$GOOGLE_MIGRATION_RECEIPT" --region "$REGION" --no-cli-pager >/dev/null 2>&1; then GOOGLE_MIGRATION_REQUIRED=1; fi
fi
# Founder final-copy approval uses the same temporary, checksum-receipted DDL path.
FOUNDER_MIGRATION_REQUIRED=0
FOUNDER_MIGRATION_FILE="$SOURCE_DIR/app/migrations/170_founder_story_approval.sql"
if [[ -f "$SOURCE_DIR/bin/founder-story-migrate.php" && -f "$FOUNDER_MIGRATION_FILE" ]]; then
  FOUNDER_MIGRATION_HASH="$(sha256sum "$FOUNDER_MIGRATION_FILE" | awk '{print $1}')"
  FOUNDER_MIGRATION_RECEIPT="schema-migrations/170-$FOUNDER_MIGRATION_HASH.json"
  if ! aws s3api head-object --bucket "$RELEASE_BUCKET" --key "$FOUNDER_MIGRATION_RECEIPT" --region "$REGION" --no-cli-pager >/dev/null 2>&1; then
    FOUNDER_MIGRATION_REQUIRED=1
    GOOGLE_MIGRATION_REQUIRED=1
  fi
fi
if [[ "$FOUNDER_MIGRATION_REQUIRED" == 1 ]]; then
  backup_metadata="$(mktemp)"
  FOUNDER_BACKUP_PROOF="$(mktemp)"
  aws rds describe-db-instances --region "$REGION" --query 'DBInstances[].{arn:DBInstanceArn,host:Endpoint.Address,state:DBInstanceStatus,encrypted:StorageEncrypted,retention:BackupRetentionPeriod,latest:LatestRestorableTime}' --output json --no-cli-pager > "$backup_metadata"
  python3 "$SOURCE_DIR/deploy/aws/validate_migration_backup.py" "$backup_metadata" "$DB_HOST" > "$FOUNDER_BACKUP_PROOF"
  rm -f "$backup_metadata"
  echo "Existing encrypted RDS recovery window verified before founder schema migration."
fi
# Incremental DDL temporarily uses the same exact administrator secret as bootstrap.
# An EXIT trap removes that access on either deployment success or failure.
cleanup_google_migration_access(){
  [[ "$GOOGLE_MIGRATION_REQUIRED" == 1 ]] || return 0
  local cleanup_policy; cleanup_policy="$(mktemp)"
  cat > "$cleanup_policy" <<JSON
{"Version":"2012-10-17","Statement":[
 {"Sid":"RuntimeSecret","Effect":"Allow","Action":["secretsmanager:GetSecretValue","secretsmanager:DescribeSecret"],"Resource":"$RUNTIME_SECRET"},
 {"Sid":"PublicMedia","Effect":"Allow","Action":["s3:GetObject","s3:GetObjectVersion","s3:PutObject"],"Resource":"arn:aws:s3:::$PUBLIC_BUCKET/assets/uploads/*"},
 {"Sid":"PrivateMedia","Effect":"Allow","Action":["s3:GetObject","s3:GetObjectVersion","s3:PutObject"],"Resource":["arn:aws:s3:::$PRIVATE_BUCKET/release-proofs/*","arn:aws:s3:::$PRIVATE_BUCKET/media-library/*"]}
]}
JSON
  local cleanup_status=0
  aws iam put-role-policy --role-name "$INSTANCE_ROLE" --policy-name WaithakaRuntimeAccess --policy-document "file://$cleanup_policy" --no-cli-pager || cleanup_status=$?
  rm -f "$cleanup_policy"
  return "$cleanup_status"
}
if [[ "$GOOGLE_MIGRATION_REQUIRED" == 1 ]]; then
  trap 'prior_status=$?; cleanup_google_migration_access || exit 1; exit "$prior_status"' EXIT
fi
# Least-privilege runtime permissions; temporary DDL access is removed after migration.

POLICY_FILE="$(mktemp)"
cat > "$POLICY_FILE" <<JSON
{
 "Version":"2012-10-17",
 "Statement":[
  {"Sid":"RuntimeSecrets","Effect":"Allow","Action":["secretsmanager:GetSecretValue","secretsmanager:DescribeSecret"],"Resource":["$RUNTIME_SECRET","$ADMIN_SECRET"]},
  {"Sid":"PublicMedia","Effect":"Allow","Action":["s3:GetObject","s3:GetObjectVersion","s3:PutObject"],"Resource":"arn:aws:s3:::$PUBLIC_BUCKET/assets/uploads/*"},
  {"Sid":"PrivateMedia","Effect":"Allow","Action":["s3:GetObject","s3:GetObjectVersion","s3:PutObject"],"Resource":["arn:aws:s3:::$PRIVATE_BUCKET/release-proofs/*","arn:aws:s3:::$PRIVATE_BUCKET/media-library/*"]}
 ]
}
JSON
if [[ "$BOOTSTRAP_REQUIRED" == 0 && "$GOOGLE_MIGRATION_REQUIRED" == 0 ]]; then
  python3 - "$POLICY_FILE" "$RUNTIME_SECRET" <<'PY'
import json,sys
p=sys.argv[1]; policy=json.load(open(p)); policy['Statement'][0]['Resource']=[sys.argv[2]]
json.dump(policy,open(p,'w'))
PY
fi
aws iam put-role-policy --role-name "$INSTANCE_ROLE" --policy-name WaithakaRuntimeAccess --policy-document "file://$POLICY_FILE" --no-cli-pager
rm -f "$POLICY_FILE"
aws iam attach-role-policy --role-name "$INSTANCE_ROLE" --policy-arn arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore --no-cli-pager >/dev/null

# Upload the immutable application bundle and register a version.
SHA="$(sha256sum "$APP_ZIP" | awk '{print $1}')"; VERSION="r17-${SHA:0:12}"; KEY="application/$VERSION.zip"
aws s3 cp "$APP_ZIP" "s3://$RELEASE_BUCKET/$KEY" --region "$REGION" --only-show-errors
aws elasticbeanstalk describe-applications --application-names "$APP_NAME" --region "$REGION" --query 'Applications[0].ApplicationName' --output text --no-cli-pager 2>/dev/null | grep -qx "$APP_NAME" || aws elasticbeanstalk create-application --application-name "$APP_NAME" --description 'Waithaka Ndegwa personal publishing platform' --region "$REGION" --no-cli-pager >/dev/null
if [[ "$(aws elasticbeanstalk describe-application-versions --application-name "$APP_NAME" --version-labels "$VERSION" --region "$REGION" --query 'ApplicationVersions[0].VersionLabel' --output text --no-cli-pager 2>/dev/null || true)" != "$VERSION" ]]; then
  aws elasticbeanstalk create-application-version --application-name "$APP_NAME" --version-label "$VERSION" --source-bundle S3Bucket="$RELEASE_BUCKET",S3Key="$KEY" --process --region "$REGION" --no-cli-pager >/dev/null
fi

OPTIONS="$STATE_DIR/beanstalk-fresh-options.json"
python3 - "$SOURCE_DIR/deploy/aws/beanstalk-options.template.json" "$OPTIONS" <<PY
import json,sys
src,out=sys.argv[1:]; a=json.load(open(src))
repl={
 'REPLACE_EB_SERVICE_ROLE':'$SERVICE_ROLE','REPLACE_INSTANCE_PROFILE':'$INSTANCE_PROFILE','REPLACE_APP_SECURITY_GROUP':'$APP_SG',
 'REPLACE_VPC':'$VPC_ID','REPLACE_APPLICATION_SUBNETS':'$APP_SUBNETS','REPLACE_ALB_SUBNETS':'$ALB_SUBNETS','REPLACE_REGIONAL_CERTIFICATE_ARN':'$ORIGIN_CERT',
 'REPLACE_ALB_SECURITY_GROUP':'$ALB_SG','REPLACE_RDS_HOST':'$DB_HOST','REPLACE_ACTUAL_PROXY_CIDRS':'$VPC_CIDR',
 'REPLACE_PUBLIC_MEDIA_BUCKET':'$PUBLIC_BUCKET','REPLACE_PRIVATE_MEDIA_BUCKET':'$PRIVATE_BUCKET','REPLACE_REGION':'$REGION','REPLACE_APP_SECRET_ARN':'$RUNTIME_SECRET'
}
for o in a:
    if isinstance(o.get('Value'),str):
        for k,v in repl.items(): o['Value']=o['Value'].replace(k,v)
    if o.get('OptionName')=='AssociatePublicIpAddress': o['Value']='false'
    if o.get('OptionName')=='APP_READY': o['Value']='1'
    if o.get('OptionName')=='APP_URL': o['Value']='https://www.waithakandegwa.com'
    if o.get('OptionName')=='MEDIA_PUBLIC_URL': o['Value']='https://www.waithakandegwa.com'
    if o.get('OptionName')=='APP_ALLOWED_HOSTS': o['Value']='waithakandegwa.com,www.waithakandegwa.com,waithakandegwa.co.ke,www.waithakandegwa.co.ke,origin.waithakandegwa.com'
    if o.get('OptionName')=='APP_FORCE_CANONICAL': o['Value']='false'
# Explicit instance class and fresh-install controls.
a += [
 {'Namespace':'aws:ec2:instances','OptionName':'InstanceTypes','Value':'t3.small'},
 {'Namespace':'aws:elasticbeanstalk:application:environment','OptionName':'APP_FRESH_BOOTSTRAP','Value':'$BOOTSTRAP_REQUIRED'},
 {'Namespace':'aws:elasticbeanstalk:application:environment','OptionName':'WRITER_EMAIL','Value':'$WRITER_EMAIL'},
 {'Namespace':'aws:elasticbeanstalk:application:environment','OptionName':'WRITER_NAME','Value':'$WRITER_NAME'},
 {'Namespace':'aws:elasticbeanstalk:application:environment','OptionName':'DB_ADMIN_SECRET_ARN','Value':'$ADMIN_SECRET'},
 {'Namespace':'aws:elasticbeanstalk:application:environmentsecrets','OptionName':'WRITER_INITIAL_PASSWORD','Value':'$RUNTIME_SECRET:WRITER_INITIAL_PASSWORD'},
 {'Namespace':'aws:elasticbeanstalk:application:environment','OptionName':'MAIL_FROM','Value':'$WRITER_EMAIL'},
 {'Namespace':'aws:elasticbeanstalk:application:environment','OptionName':'MAIL_FROM_NAME','Value':'Waithaka Ndegwa'},
 {'Namespace':'aws:elasticbeanstalk:application:environment','OptionName':'MAIL_REPLY_TO','Value':'$WRITER_EMAIL'}
]
if '$BOOTSTRAP_REQUIRED' == '0':
    a=[o for o in a if o.get('OptionName') != 'WRITER_INITIAL_PASSWORD' and not (o.get('OptionName') == 'DB_ADMIN_SECRET_ARN' and '$GOOGLE_MIGRATION_REQUIRED' == '0')]
json.dump(a,open(out,'w'),indent=2)
PY

environment_snapshot(){
  aws elasticbeanstalk describe-environments \
    --application-name "$APP_NAME" \
    --environment-names "$ENV_NAME" \
    --no-include-deleted \
    --region "$REGION" \
    --query 'Environments[0].[EnvironmentName,Status,Health,VersionLabel]' \
    --output text --no-cli-pager 2>/dev/null || true
}
wait_environment_ready(){
  local status health
  for i in $(seq 1 120); do
    read -r _ status health _ <<<"$(environment_snapshot)"
    echo "Environment: ${status:-missing} / ${health:-unknown}"
    [[ "$status" == "Ready" ]] && return 0
    [[ "$status" == "Terminated" || -z "$status" || "$status" == "None" ]] && return 1
    sleep 15
  done
  return 1
}

snapshot="$(environment_snapshot)"
read -r existing_name existing_status existing_health existing_version <<<"$snapshot"
if [[ -z "$existing_name" || "$existing_name" == "None" ]]; then
  echo "Creating Elastic Beanstalk production environment (workers/mail still disabled)..."
  aws elasticbeanstalk create-environment --application-name "$APP_NAME" --environment-name "$ENV_NAME" --version-label "$VERSION" --platform-arn "$PLATFORM_ARN" --option-settings "file://$OPTIONS" --region "$REGION" --no-cli-pager >/dev/null
else
  echo "Adopting existing Elastic Beanstalk environment: $ENV_NAME ($existing_status / $existing_health, version ${existing_version:-unknown})"
  if [[ "$existing_status" != "Ready" ]]; then
    echo "Waiting for the existing environment operation to settle before applying the reviewed configuration..."
    wait_environment_ready || fail "Existing Elastic Beanstalk environment did not become Ready."
  fi
  echo "Updating existing Elastic Beanstalk environment to application version $VERSION..."
  aws elasticbeanstalk update-environment --environment-name "$ENV_NAME" --version-label "$VERSION" --option-settings "file://$OPTIONS" --region "$REGION" --no-cli-pager >/dev/null
fi

STATUS=""; HEALTH=""; READY_UNHEALTHY=0
for i in $(seq 1 120); do
  read -r _ STATUS HEALTH DEPLOYED_VERSION <<<"$(environment_snapshot)"
  echo "Environment: ${STATUS:-missing} / ${HEALTH:-unknown}"
  [[ "$STATUS" == "Ready" && "$HEALTH" == "Green" && "$DEPLOYED_VERSION" == "$VERSION" ]] && break
  if [[ "$STATUS" == "Ready" ]]; then
    [[ "$DEPLOYED_VERSION" == "$VERSION" ]] || fail "Beanstalk reverted to $DEPLOYED_VERSION instead of $VERSION. Inspect startup logs."
    READY_UNHEALTHY=$((READY_UNHEALTHY+1))
    [[ "$READY_UNHEALTHY" -lt 8 ]] || fail "Beanstalk remained Ready/$HEALTH after deployment; inspect startup logs."
  else
    READY_UNHEALTHY=0
  fi
  [[ "$STATUS" == "Terminated" || -z "$STATUS" || "$STATUS" == "None" ]] && fail "Elastic Beanstalk environment terminated or disappeared unexpectedly."
  sleep 15
done
[[ "$STATUS" == "Ready" && "$HEALTH" == "Green" && "$DEPLOYED_VERSION" == "$VERSION" ]] || fail "Elastic Beanstalk did not become Ready/Green on the requested release."
if [[ "$GOOGLE_MIGRATION_REQUIRED" == 1 ]]; then
  # Successful postdeploy hooks on this exact version prove the additive migration completed.
  migration_receipt="$(mktemp)"
  python3 - "$migration_receipt" "$GOOGLE_MIGRATION_HASH" "$VERSION" <<'PYRECEIPT'
import json,sys,datetime
json.dump({'account_id':'466401734428','migration':'160_google_workspace.sql','sha256':sys.argv[2],'release':sys.argv[3],'verified_at':datetime.datetime.now(datetime.timezone.utc).isoformat()},open(sys.argv[1],'w'))
PYRECEIPT
  aws s3api put-object --bucket "$RELEASE_BUCKET" --key "$GOOGLE_MIGRATION_RECEIPT" --body "$migration_receipt" --region "$REGION" --no-cli-pager >/dev/null
  rm -f "$migration_receipt"
  if [[ "$FOUNDER_MIGRATION_REQUIRED" == 1 ]]; then
    founder_receipt="$(mktemp)"
    python3 - "$founder_receipt" "$FOUNDER_MIGRATION_HASH" "$VERSION" "$FOUNDER_BACKUP_PROOF" <<'PYFOUNDER'
import json,sys,datetime
json.dump({'account_id':'466401734428','migration':'170_founder_story_approval.sql','sha256':sys.argv[2],'release':sys.argv[3],'verified_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'recovery':json.load(open(sys.argv[4]))},open(sys.argv[1],'w'))
PYFOUNDER
    aws s3api put-object --bucket "$RELEASE_BUCKET" --key "$FOUNDER_MIGRATION_RECEIPT" --body "$founder_receipt" --region "$REGION" --no-cli-pager >/dev/null
    rm -f "$founder_receipt" "$FOUNDER_BACKUP_PROOF"
  fi
  cleanup_google_migration_access
fi

# Add origin DNS alias to the actual ALB now that the environment exists.
ENV_ID="$(aws elasticbeanstalk describe-environments --environment-names "$ENV_NAME" --region "$REGION" --query 'Environments[0].EnvironmentId' --output text --no-cli-pager)"
LB_NAME="$(aws elasticbeanstalk describe-environment-resources --environment-id "$ENV_ID" --region "$REGION" --query 'EnvironmentResources.LoadBalancers[0].Name' --output text --no-cli-pager)"
if [[ "$LB_NAME" == arn:* ]]; then
  LB_ARN="$LB_NAME"
else
  LB_ARN="$(aws elbv2 describe-load-balancers --names "$LB_NAME" --region "$REGION" --query 'LoadBalancers[0].LoadBalancerArn' --output text --no-cli-pager)"
fi
LB_DNS="$(aws elbv2 describe-load-balancers --load-balancer-arns "$LB_ARN" --region "$REGION" --query 'LoadBalancers[0].DNSName' --output text --no-cli-pager)"
LB_ZONE="$(aws elbv2 describe-load-balancers --load-balancer-arns "$LB_ARN" --region "$REGION" --query 'LoadBalancers[0].CanonicalHostedZoneId' --output text --no-cli-pager)"
PRIMARY_ZONE="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["zones"]["waithakandegwa.com"])' "$DNS_STATE")"
DNS_BATCH="$STATE_DIR/origin-alias.json"
cat > "$DNS_BATCH" <<JSON
{"Comment":"Point Waithaka origin to Elastic Beanstalk ALB","Changes":[{"Action":"UPSERT","ResourceRecordSet":{"Name":"$ORIGIN.","Type":"A","AliasTarget":{"HostedZoneId":"$LB_ZONE","DNSName":"$LB_DNS","EvaluateTargetHealth":true}}}]}
JSON
aws route53 change-resource-record-sets --hosted-zone-id "$PRIMARY_ZONE" --change-batch "file://$DNS_BATCH" --no-cli-pager >/dev/null

# Successful deployment proves the fresh bootstrap hook completed. Disable it and stop injecting the one-time password.
aws elasticbeanstalk update-environment --environment-name "$ENV_NAME" --region "$REGION" \
  --option-settings Namespace=aws:elasticbeanstalk:application:environment,OptionName=APP_FRESH_BOOTSTRAP,Value=0 \
  --options-to-remove Namespace=aws:elasticbeanstalk:application:environmentsecrets,OptionName=WRITER_INITIAL_PASSWORD Namespace=aws:elasticbeanstalk:application:environment,OptionName=DB_ADMIN_SECRET_ARN \
  --no-cli-pager >/dev/null
for i in $(seq 1 80); do STATUS="$(aws elasticbeanstalk describe-environments --environment-names "$ENV_NAME" --region "$REGION" --query 'Environments[0].Status' --output text --no-cli-pager)"; [[ "$STATUS" == Ready ]] && break; sleep 10; done

# Remove the bootstrap-only database administrator secret permission.
POLICY_FILE="$(mktemp)"
cat > "$POLICY_FILE" <<JSON
{"Version":"2012-10-17","Statement":[
 {"Sid":"RuntimeSecret","Effect":"Allow","Action":["secretsmanager:GetSecretValue","secretsmanager:DescribeSecret"],"Resource":"$RUNTIME_SECRET"},
 {"Sid":"PublicMedia","Effect":"Allow","Action":["s3:GetObject","s3:GetObjectVersion","s3:PutObject"],"Resource":"arn:aws:s3:::$PUBLIC_BUCKET/assets/uploads/*"},
 {"Sid":"PrivateMedia","Effect":"Allow","Action":["s3:GetObject","s3:GetObjectVersion","s3:PutObject"],"Resource":["arn:aws:s3:::$PRIVATE_BUCKET/release-proofs/*","arn:aws:s3:::$PRIVATE_BUCKET/media-library/*"]}
]}
JSON
aws iam put-role-policy --role-name "$INSTANCE_ROLE" --policy-name WaithakaRuntimeAccess --policy-document "file://$POLICY_FILE" --no-cli-pager
rm -f "$POLICY_FILE"

cat <<EOF
Fresh application deployment complete.
Account: $ACCOUNT
Environment: $ENV_NAME
Origin: https://$ORIGIN
Initial Writer credential file (CloudShell private file): $CRED_FILE
Mail transport: disabled pending Google Workspace SMTP relay configuration.
Workers: disabled pending production acceptance.
EOF
