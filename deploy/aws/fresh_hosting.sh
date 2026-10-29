#!/usr/bin/env bash
set -euo pipefail
SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ACTION="${1:-status}"
case "$ACTION" in
 status)
   echo "=== AWS identity ==="; aws sts get-caller-identity --no-cli-pager
   echo "=== Foundation stacks ==="
   for s in waithaka-iam-baseline waithaka-network waithaka-ndegwa-production-466401734428-data; do
     aws cloudformation describe-stacks --stack-name "$s" --region af-south-1 --query 'Stacks[0].[StackName,StackStatus]' --output table --no-cli-pager 2>/dev/null || true
   done
   if [[ -f "$HOME/waithaka-aws-bootstrap/fresh-dns-state.json" ]]; then python3 "$SOURCE_DIR/deploy/aws/fresh_dns.py" status; else echo "Fresh DNS not prepared."; fi
   ;;
 --prepare-dns)
   python3 "$SOURCE_DIR/deploy/aws/fresh_dns.py" prepare
   echo
   echo "STOP POINT: perform only the registrar glue/delegation actions in REGISTRAR-HANDOFF.json, then run $0 --after-registrar."
   ;;
 --after-registrar)
   echo "Verifying public nameserver delegation and waiting for ACM validation..."
   for i in $(seq 1 120); do
     STATUS_JSON="$(python3 "$SOURCE_DIR/deploy/aws/fresh_dns.py" status)"
     echo "$STATUS_JSON" > "$HOME/waithaka-aws-bootstrap/fresh-dns-status.json"
     ready="$(python3 - <<'PY' "$HOME/waithaka-aws-bootstrap/fresh-dns-status.json"
import json,sys
x=json.load(open(sys.argv[1]));print('yes' if all(z.get('delegated_to_vanity') for z in x.get('zones',{}).values()) and all(c.get('status')=='ISSUED' for c in x.get('certificates',{}).values()) else 'no')
PY
)"
     if [[ "$ready" == yes ]]; then echo "Registrar delegation and both ACM certificates are verified."; break; fi
     if [[ "$i" == 120 ]]; then echo "Delegation/certificate validation is not complete. Review $HOME/waithaka-aws-bootstrap/fresh-dns-status.json" >&2; exit 1; fi
     sleep 15
   done
   # Code-only releases must not continuously update CloudFront or Route 53.
   # DNS and edge cutover are one-time infrastructure operations, not per-release work.
   edge_state="$(aws cloudformation describe-stacks --stack-name waithaka-edge --region "${AWS_REGION:-af-south-1}" --query 'Stacks[0].StackStatus' --output text --no-cli-pager 2>/dev/null || true)"
   bash "$SOURCE_DIR/deploy/aws/fresh_application.sh"
   if [[ "$edge_state" == CREATE_COMPLETE || "$edge_state" == UPDATE_COMPLETE ]]; then
     echo "PASS: existing CloudFront edge preserved; no routing or DNS cutover for this application release."
   elif [[ -z "$edge_state" || "$edge_state" == None ]]; then
     echo "Initial edge stack is absent; invoking reviewed one-time edge provisioning."
     bash "$SOURCE_DIR/deploy/aws/fresh_edge.sh"
   else
     echo "Existing edge stack is in state $edge_state; refusing an unreviewed replacement or update." >&2
     exit 1
   fi
   echo
   bash "$SOURCE_DIR/deploy/aws/google_workspace.sh" status
   echo
   echo "HOSTING AUTOMATION COMPLETE THROUGH CLOUDFRONT EDGE."
   echo "Mail and workers remain disabled until their separate acceptance steps."
   ;;
 --workspace-status)
   bash "$SOURCE_DIR/deploy/aws/google_workspace.sh" status
   ;;
 --workspace-enable)
   bash "$SOURCE_DIR/deploy/aws/google_workspace.sh" --enable
   ;;
 *)
   echo "Usage: $0 [status|--prepare-dns|--after-registrar|--workspace-status|--workspace-enable]" >&2; exit 2;;
esac
