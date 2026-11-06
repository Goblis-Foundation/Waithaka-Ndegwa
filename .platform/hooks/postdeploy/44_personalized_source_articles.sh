#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
php bin/publish-personalized-source-articles.php
