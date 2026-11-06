#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
php -r 'require "app/bootstrap.php"; require_once "app/education_research_opportunities_release.php"; echo json_encode(education_research_opportunities_import((int)(comm_config()["owner"]??0)),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;'
