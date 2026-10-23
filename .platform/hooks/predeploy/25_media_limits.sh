#!/usr/bin/env bash
set -euo pipefail
cd /var/app/staging
# Beanstalk generates alphabetically late PHP configuration files. Load the
# reviewed application limits after those platform defaults.
install -m 0644 deploy/aws/media-limits.ini /etc/php.d/zzzz_waithaka_media.ini
php -r '$upload=ini_get("upload_max_filesize"); $post=ini_get("post_max_size"); if (ini_parse_quantity($upload) !== 512*1024*1024 || ini_parse_quantity($post) !== 514*1024*1024) { fwrite(STDERR, "Writer upload configuration mismatch: upload=".$upload." post=".$post." scanned=".php_ini_scanned_files().PHP_EOL); exit(1); }'
