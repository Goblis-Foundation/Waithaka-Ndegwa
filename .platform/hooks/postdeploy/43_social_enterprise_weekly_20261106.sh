#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
# Idempotent first weekly research release. This publishes only the reviewed
# website article; it never bypasses per-channel social approval or sends mail.
php bin/publish-social-enterprise-weekly-20261106.php
