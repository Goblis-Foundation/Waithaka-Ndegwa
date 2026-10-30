#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
# Fixed additive catalogue data only; no DDL, admin secret or outbound request.
php bin/community-channels.php --plan
php bin/community-channels.php --apply
