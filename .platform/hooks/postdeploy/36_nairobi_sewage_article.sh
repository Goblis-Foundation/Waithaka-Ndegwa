#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
php bin/publish-nairobi-sewage-article.php
php bin/publish-tanzania-railways-vision-2050.php
