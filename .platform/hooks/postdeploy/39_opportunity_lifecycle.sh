#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
# Register the owner-authorised policy. Network checks run through the existing worker.
php -r 'require "app/bootstrap.php"; echo json_encode(ol_install(),JSON_THROW_ON_ERROR).PHP_EOL;'
