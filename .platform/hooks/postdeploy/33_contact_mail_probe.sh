#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
# The probe never sends mail. Preserve public availability if Google needs review.
php deploy/aws/contact_mail_probe.php || true
