#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
php bin/founder-story-migrate.php --plan
php bin/founder-story-migrate.php --apply
