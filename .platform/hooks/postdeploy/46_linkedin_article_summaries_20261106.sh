#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
php bin/import-linkedin-article-summaries-20261106.php
