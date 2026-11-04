#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
php -r 'require "app/bootstrap.php"; require_once "app/publishing_desk.php"; require_once "app/curated_opportunities_release.php"; $report=curated_release_import((int)(comm_config()["owner"]??0)); echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;'
