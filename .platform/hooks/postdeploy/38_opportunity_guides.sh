#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
php -r 'require "app/bootstrap.php"; require_once "app/publishing_desk.php"; require_once "app/opportunity_guides_release.php"; $report=opportunity_guides_upgrade((int)(comm_config()["owner"]??0)); echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;'
