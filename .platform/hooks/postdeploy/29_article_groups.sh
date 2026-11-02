#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
php bin/article-groups-migrate.php --apply
