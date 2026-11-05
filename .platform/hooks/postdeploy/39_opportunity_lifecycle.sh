#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
# Register the existing policy; source and AI checks remain in the scheduled worker.
php -r 'require "app/bootstrap.php"; echo json_encode(ol_install(),JSON_THROW_ON_ERROR).PHP_EOL; require "app/opportunity_review_health.php"; echo "Opportunity review health: ".json_encode(orh_snapshot(),JSON_THROW_ON_ERROR).PHP_EOL;'
