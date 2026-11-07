#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
php bin/import-country-opportunities-batch6-20261107.php
