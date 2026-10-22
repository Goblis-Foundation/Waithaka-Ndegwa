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
   aws elasticbeanstalk update-environment --environment-name "$ENV_NAME" --region "$REGION" --option-settings \
     Namespace=aws:elasticbeanstalk:application:environment,OptionName=MAIL_TRANSPORT,Value=smtp \
     Namespace=aws:elasticbeanstalk:application:environment,OptionName=MAIL_FROM,Value="$FROM" \
     Namespace=aws:elasticbeanstalk:application:environment,OptionName=MAIL_FROM_NAME,Value="Waithaka Ndegwa" \
     Namespace=aws:elasticbeanstalk:application:environment,OptionName=MAIL_REPLY_TO,Value="$FROM" \
     Namespace=aws:elasticbeanstalk:application:environment,OptionName=SMTP_HOST,Value=smtp-relay.gmail.com \
     Namespace=aws:elasticbeanstalk:application:environment,OptionName=SMTP_PORT,Value=587 \
     Namespace=aws:elasticbeanstalk:application:environment,OptionName=SMTP_ENCRYPTION,Value=tls \
     Namespace=aws:elasticbeanstalk:application:environment,OptionName=SMTP_HELO,Value="$DOMAIN" \
     --no-cli-pager >/dev/null
   echo "Google Workspace SMTP relay configuration applied to Elastic Beanstalk. NAT egress IP: $NAT_IP"
   echo "Newsletter approval remains blocked until a real postal address is configured; no address has been invented."
   ;;
 *) echo "Usage: $0 [status|--enable]" >&2; exit 2;;
esac
