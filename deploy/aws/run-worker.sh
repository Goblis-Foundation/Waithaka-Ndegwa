#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
# The PHP launcher reads EB environment properties without printing any secrets.
exec php deploy/aws/worker-launcher.php
