#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
case "${APP_FRESH_BOOTSTRAP:-0}" in
  1|true|TRUE|yes|YES|on|ON) exec php bin/fresh-bootstrap.php ;;
  *) exit 0 ;;
esac
