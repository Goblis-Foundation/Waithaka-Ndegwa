#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
php bin/remove-legacy-article-logo.php
