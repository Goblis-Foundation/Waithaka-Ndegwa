#!/usr/bin/env bash
set -euo pipefail
cd /var/app/current
php <<'PHP'
<?php
require 'app/bootstrap.php';
require_once 'app/publishing_desk.php';
require_once 'app/curated_opportunities_release.php';
// Multiple application instances may run postdeploy. Serialize the complete release.
$pdo=db();$lock='curated-africa-opportunities-20261104';
$query=$pdo->prepare('SELECT GET_LOCK(?,120)');$query->execute([$lock]);
if((int)$query->fetchColumn()!==1)throw new RuntimeException('Opportunity release lock unavailable.');
try{
    $report=curated_release_import((int)(comm_config()['owner']??0));
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}finally{
    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
}
PHP
