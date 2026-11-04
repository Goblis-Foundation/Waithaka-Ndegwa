#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
# Publication occurs only after the existing article-group migration and
# only when the original article is absent. Never overwrite Writer edits.
php bin/publish-inaugural-article.php
