#!/usr/bin/env bash
set -euo pipefail
cd /var/app/staging
install -m 0644 deploy/aws/media-limits.ini /etc/php.d/99_waithaka_media.ini
php -r 'if (ini_get("upload_max_filesize") !== "512M" || ini_get("post_max_size") !== "514M") { fwrite(STDERR, "Writer media upload limits were not applied.".PHP_EOL); exit(1); }'
