#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
# Explicitly authorised article, created once; preserve all existing Writer edits.
php bin/publish-ev-biofuels-article.php
