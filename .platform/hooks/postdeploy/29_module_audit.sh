#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
php bin/module-audit-migrate.php --plan
php bin/module-audit-migrate.php --apply
