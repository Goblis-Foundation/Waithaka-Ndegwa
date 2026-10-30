#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
php bin/owner-identity-alias.php --apply
