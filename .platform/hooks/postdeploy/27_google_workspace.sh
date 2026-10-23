#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
php bin/google-workspace-migrate.php --plan
php bin/google-workspace-migrate.php --apply
