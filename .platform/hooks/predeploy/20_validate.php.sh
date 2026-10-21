#!/usr/bin/env bash
set -euo pipefail
cd /var/app/staging
php -r 'foreach(["pdo_mysql","curl","dom","mbstring","fileinfo","openssl","gd"] as $ext){if(!extension_loaded($ext)){fwrite(STDERR,"Missing PHP extension: ".$ext.PHP_EOL);exit(1);}}'
test -f vendor/autoload.php
test -f deploy/aws/global-bundle.pem
# Deliberately no schema writes in web requests or automatic deployment hooks.
install -d -m 0750 -o webapp -g webapp storage storage/logs storage/sessions assets/uploads
