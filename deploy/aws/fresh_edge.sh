#!/usr/bin/env bash
set -euo pipefail
REGION="${AWS_REGION:-af-south-1}"; ACCOUNT="466401734428"; EDGE_STACK="waithaka-edge"; DATA_STACK="waithaka-ndegwa-production-466401734428-data"; ENV_NAME="waithaka-ndegwa-production"; CANONICAL="www.waithakandegwa.com"; ORIGIN="origin.waithakandegwa.com"; DNS_STATE="$HOME/waithaka-aws-bootstrap/fresh-dns-state.json"; SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
[[ "$(aws sts get-caller-identity --query Account --output text --no-cli-pager)" == "$ACCOUNT" ]] || { echo "Wrong AWS account" >&2; exit 1; }
[[ -f "$DNS_STATE" ]] || { echo "Fresh DNS state not found." >&2; exit 1; }
find_cert(){ aws acm list-certificates --region "$1" --certificate-statuses PENDING_VALIDATION ISSUED --query "CertificateSummaryList[?DomainName=='$2'].CertificateArn | [0]" --output text --no-cli-pager; }
VIEWER_CERT="$(find_cert us-east-1 "$CANONICAL")"; ORIGIN_CERT="$(find_cert "$REGION" "$ORIGIN")"
[[ "$(aws acm describe-certificate --region us-east-1 --certificate-arn "$VIEWER_CERT" --query Certificate.Status --output text --no-cli-pager)" == ISSUED ]] || { echo "CloudFront viewer certificate is not ISSUED." >&2; exit 1; }
[[ "$(aws acm describe-certificate --region "$REGION" --certificate-arn "$ORIGIN_CERT" --query Certificate.Status --output text --no-cli-pager)" == ISSUED ]] || { echo "Origin certificate is not ISSUED." >&2; exit 1; }
STATUS="$(aws elasticbeanstalk describe-environments --environment-names "$ENV_NAME" --region "$REGION" --query 'Environments[0].Status' --output text --no-cli-pager)"; HEALTH="$(aws elasticbeanstalk describe-environments --environment-names "$ENV_NAME" --region "$REGION" --query 'Environments[0].Health' --output text --no-cli-pager)"
[[ "$STATUS" == Ready && "$HEALTH" == Green ]] || { echo "Elastic Beanstalk is not Ready/Green." >&2; exit 1; }
PUBLIC_BUCKET="$(aws cloudformation describe-stacks --stack-name "$DATA_STACK" --region "$REGION" --query "Stacks[0].Outputs[?OutputKey=='PublicMedia'].OutputValue | [0]" --output text --no-cli-pager)"
MEDIA_DOMAIN="$PUBLIC_BUCKET.s3.$REGION.amazonaws.com"
# Keep the global CloudFront resources in a Cape Town-managed stack. The viewer
# certificate remains in us-east-1. Never create a duplicate distribution.
EDGE_STATUS="$(aws cloudformation describe-stacks --stack-name "$EDGE_STACK" --region "$REGION" --query 'Stacks[0].StackStatus' --output text --no-cli-pager 2>/dev/null || true)"
if [[ -z "$EDGE_STATUS" || "$EDGE_STATUS" == None ]]; then
  EXISTING_DISTS="$(aws cloudfront list-distributions --query "DistributionList.Items[?Aliases.Items && contains(Aliases.Items, 'www.waithakandegwa.com')].Id" --output text --no-cli-pager)"
  [[ -z "$EXISTING_DISTS" || "$EXISTING_DISTS" == None ]] || { echo "Existing CloudFront distribution $EXISTING_DISTS must be adopted before a new edge stack is created." >&2; exit 1; }
fi
aws cloudformation deploy --template-file "$SOURCE_DIR/deploy/aws/edge-stack.json" --stack-name "$EDGE_STACK" --parameter-overrides ViewerCertificateArn="$VIEWER_CERT" OriginDomain="$ORIGIN" MediaBucketRegionalDomain="$MEDIA_DOMAIN" --region "$REGION" --no-cli-pager
DIST_ID="$(aws cloudformation describe-stacks --stack-name "$EDGE_STACK" --region "$REGION" --query "Stacks[0].Outputs[?OutputKey=='DistributionId'].OutputValue | [0]" --output text --no-cli-pager)"
DIST_DOMAIN="$(aws cloudformation describe-stacks --stack-name "$EDGE_STACK" --region "$REGION" --query "Stacks[0].Outputs[?OutputKey=='DistributionDomain'].OutputValue | [0]" --output text --no-cli-pager)"
DIST_ARN="$(aws cloudformation describe-stacks --stack-name "$EDGE_STACK" --region "$REGION" --query "Stacks[0].Outputs[?OutputKey=='DistributionArn'].OutputValue | [0]" --output text --no-cli-pager)"
aws cloudfront wait distribution-deployed --id "$DIST_ID" --no-cli-pager
# Keep data-stack ownership of the media bucket policy; update only MediaDistributionArn.
set +e
UPDATE_OUT="$(aws cloudformation update-stack --stack-name "$DATA_STACK" --use-previous-template --region "$REGION" --parameters \
 ParameterKey=VpcId,UsePreviousValue=true ParameterKey=PrivateSubnetIds,UsePreviousValue=true ParameterKey=ApplicationSecurityGroupId,UsePreviousValue=true \
 ParameterKey=DatabaseName,UsePreviousValue=true ParameterKey=DatabaseClass,UsePreviousValue=true ParameterKey=MultiAZ,UsePreviousValue=true ParameterKey=DatabaseEngineVersion,UsePreviousValue=true \
 ParameterKey=MediaDistributionArn,ParameterValue="$DIST_ARN" --no-cli-pager 2>&1)"
UPDATE_RC=$?
set -e
if [[ $UPDATE_RC -ne 0 && "$UPDATE_OUT" != *"No updates are to be performed"* ]]; then echo "$UPDATE_OUT" >&2; exit $UPDATE_RC; fi
if [[ $UPDATE_RC -eq 0 ]]; then
  for i in $(seq 1 90); do S="$(aws cloudformation describe-stacks --stack-name "$DATA_STACK" --region "$REGION" --query 'Stacks[0].StackStatus' --output text --no-cli-pager)"; [[ "$S" == UPDATE_COMPLETE ]] && break; [[ "$S" =~ FAILED|ROLLBACK ]] && { echo "Data stack update failed: $S" >&2; exit 1; }; sleep 10; done
  [[ "$S" == UPDATE_COMPLETE ]] || { echo "Timed out waiting for data bucket-policy update." >&2; exit 1; }
fi
python3 - "$DNS_STATE" "$DIST_DOMAIN" <<'PY'
import json,subprocess,sys,tempfile,os
state=json.load(open(sys.argv[1])); domain=sys.argv[2]; cf_zone='Z2FDTNDATAQYW2'
for zone,zid in state['zones'].items():
  names=[zone,'www.'+zone]
  inventory=json.loads(subprocess.check_output(['aws','route53','list-resource-record-sets','--hosted-zone-id',zid,'--output','json']))['ResourceRecordSets']
  # Route 53 applies a change batch atomically. Delete only exact conflicting
  # website CNAMEs, using their complete original record definitions.
  changes=[{'Action':'DELETE','ResourceRecordSet':record} for record in inventory if record['Type']=='CNAME' and record['Name'].rstrip('.') in names]
  for name in names:
    for typ in ('A','AAAA'):
      changes.append({'Action':'UPSERT','ResourceRecordSet':{'Name':name+'.','Type':typ,'AliasTarget':{'HostedZoneId':cf_zone,'DNSName':domain+'.','EvaluateTargetHealth':False}}})
  batch={'Comment':'Point Waithaka public domains to CloudFront','Changes':changes}
  f=tempfile.NamedTemporaryFile('w',delete=False,suffix='.json');json.dump(batch,f);f.close()
  subprocess.run(['aws','route53','change-resource-record-sets','--hosted-zone-id',zid,'--change-batch','file://'+f.name,'--no-cli-pager'],check=True,stdout=subprocess.DEVNULL);os.unlink(f.name)
PY
cat <<EOF
CloudFront edge deployed.
Distribution: $DIST_ID
Domain: $DIST_DOMAIN
Route 53 website aliases now point to CloudFront.
Canonical: https://$CANONICAL
EOF
