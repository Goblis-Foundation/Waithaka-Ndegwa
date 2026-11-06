#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
php bin/import-country-opportunities-batch3-20261106.php
