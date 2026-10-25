#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
php bin/message-trash-migrate.php
