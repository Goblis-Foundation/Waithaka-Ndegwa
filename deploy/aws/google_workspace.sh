#!/usr/bin/env bash
set -euo pipefail
REGION="${AWS_REGION:-af-south-1}"; ACCOUNT="466401734428"; NETWORK_STACK="waithaka-network"; ENV_NAME="waithaka-ndegwa-production"; DOMAIN="waithakandegwa.com"; FROM="isaac@waithakandegwa.com"; STATE_DIR="$HOME/waithaka-aws-bootstrap"
[[ "$(aws sts get-caller-identity --query Account --output text --no-cli-pager)" == "$ACCOUNT" ]] || { echo "Wrong AWS account" >&2; exit 1; }
VPC_ID="$(aws cloudformation describe-stacks --stack-name "$NETWORK_STACK" --region "$REGION" --query "Stacks[0].Outputs[?OutputKey=='VpcId'].OutputValue | [0]" --output text --no-cli-pager)"
NAT_ALLOC="$(aws ec2 describe-nat-gateways --region "$REGION" --filter Name=vpc-id,Values="$VPC_ID" Name=state,Values=available --query 'NatGateways[0].NatGatewayAddresses[0].AllocationId' --output text --no-cli-pager)"
NAT_IP="$(aws ec2 describe-addresses --region "$REGION" --allocation-ids "$NAT_ALLOC" --query 'Addresses[0].PublicIp' --output text --no-cli-pager)"
ACTION="${1:-status}"
case "$ACTION" in
 status)
   cat <<EOF
Google Workspace application-mail handoff
Domain: $DOMAIN
Application sender: $FROM
SMTP relay: smtp-relay.gmail.com:587 (STARTTLS)
AWS egress IP to authorize in Google Admin SMTP relay: $NAT_IP

Google Admin action required before enabling mail:
- Apps > Google Workspace > Gmail > Routing > SMTP relay service
- Allowed senders: only registered users in your domains
- Authentication: only accept mail from the specified IP address: $NAT_IP
- Require TLS
- Generate/enable 2048-bit DKIM for $DOMAIN; add the supplied TXT record to Route 53.

The application remains MAIL_TRANSPORT=disabled until you run --enable after completing the Google Admin relay setup.
EOF
   ;;
 --enable)
   [[ "$NAT_IP" == "15.240.2.118" ]] || { echo "Relay IP differs from the approved Google rule; refusing activation." >&2; exit 1; }
   export WK_MAIL_REGION="$REGION" WK_MAIL_ENV="$ENV_NAME"
   python3 - <<'PYMAIL'
import json, os, subprocess
region=os.environ['WK_MAIL_REGION']; name=os.environ['WK_MAIL_ENV']
def aws(*args):
    return json.loads(subprocess.check_output(['aws','elasticbeanstalk',*args,'--region',region,'--output','json','--no-cli-pager'],text=True))
ns='aws:elasticbeanstalk:application:environment'
desired={'MAIL_TRANSPORT':'smtp','MAIL_FROM':'isaac@waithakandegwa.com','MAIL_FROM_NAME':'Waithaka Ndegwa','MAIL_REPLY_TO':'isaac@waithakandegwa.com','SMTP_HOST':'smtp-relay.gmail.com','SMTP_PORT':'587','SMTP_ENCRYPTION':'tls','SMTP_HELO':'waithakandegwa.com'}
current=aws('describe-configuration-settings','--application-name','waithaka-ndegwa','--environment-name',name)
options=current['ConfigurationSettings'][0]['OptionSettings']
values={v['OptionName']:v.get('Value','') for v in options if v['Namespace']==ns}
changes=[{'Namespace':ns,'OptionName':k,'Value':v} for k,v in desired.items() if values.get(k)!=v]
if changes:
    aws('update-environment','--environment-name',name,'--option-settings',json.dumps(changes))
    subprocess.run(['aws','elasticbeanstalk','wait','environment-updated','--environment-names',name,'--region',region],check=True)
    print('Workspace application mail configuration applied.')
else:
    print('Workspace application mail configuration already matches.')
# Deliberately leave WORKER_ENABLED unchanged: transport configuration is not permission to drain queues.
PYMAIL
   echo "Google Workspace SMTP relay configuration applied to Elastic Beanstalk. NAT egress IP: $NAT_IP"
   echo "Newsletter approval remains blocked until a real postal address is configured; no address has been invented."
   ;;
 *) echo "Usage: $0 [status|--enable]" >&2; exit 2;;
esac
