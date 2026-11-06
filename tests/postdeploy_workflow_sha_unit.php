<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$files=[
 '.github/workflows/editorial-banners.yml',
 '.github/workflows/ev-biofuels-article.yml',
 '.github/workflows/opportunity-quality.yml',
 '.github/workflows/opportunity-lifecycle.yml',
 '.github/workflows/inaugural-story-design.yml',
 '.github/workflows/nairobi-sewage-article.yml',
 '.github/workflows/curated-opportunities-release.yml',
];
$fail=[];$ok=function(bool $v,string $m)use(&$fail){if(!$v)$fail[]=$m;};
$pin=<<<'YAML'
ref: ${{ github.event_name == 'workflow_run' && github.event.workflow_run.head_sha || github.sha }}
YAML;
foreach($files as$file){
    $text=(string)file_get_contents($root.'/'.$file);
    $ok(str_contains($text,"workflows: ['Deploy production']"),$file.' is a Deploy production follow-up');
    $ok(str_contains($text,"github.event.workflow_run.head_branch == 'main'"),$file.' restricts workflow_run to main');
    $ok(str_contains($text,'github.event.workflow_run.head_repository.full_name == github.repository'),$file.' restricts workflow_run to this repository');
    $ok(str_contains($text,$pin),$file.' pins checkout to the triggering deployed SHA');
    $ok(str_contains($text,'persist-credentials: false'),$file.' keeps checkout credentials disabled');
}
if($fail){fwrite(STDERR,'FAIL: '.implode('; ',$fail)."\n");exit(1);}
echo "PASS: post-deployment acceptance workflows pin trusted main deployments to the exact deployed SHA.\n";
