#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
php bin/import-country-opportunities-batch4-20261106.php
