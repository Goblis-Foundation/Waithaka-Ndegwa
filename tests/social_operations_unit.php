<?php
declare(strict_types=1);

function app_config(?string $key=null,mixed $default=null):mixed{return ['timezone'=>'Africa/Nairobi'][$key]??$default;}
function publishing_networks():array{return ['linkedin'=>'LinkedIn','facebook'=>'Facebook','instagram'=>'Instagram','x'=>'X','youtube'=>'YouTube','tiktok'=>'TikTok','threads'=>'Threads','telegram'=>'Telegram','discord'=>'Discord','signal'=>'Signal'];}
function url(string $path=''):string{return 'https://www.waithakandegwa.com/'.ltrim($path,'/');}
function setting(string $key,mixed $default=null):mixed{return $default;}
function publishing_public_url(string $url):bool{return str_starts_with($url,'https://')&&filter_var($url,FILTER_VALIDATE_URL)!==false;}

require_once dirname(__DIR__).'/app/social_operations.php';

$n=0;function so_check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);$GLOBALS['n']++;}

$rows=[
 ['platform'=>'linkedin','published_at'=>'2026-10-19T09:00:00+03:00','metrics'=>['reach'=>100,'engagements'=>20,'clicks'=>5]],
 ['platform'=>'linkedin','published_at'=>'2026-10-26T09:00:00+03:00','metrics'=>['reach'=>100,'engagements'=>22,'clicks'=>6]],
 ['platform'=>'linkedin','published_at'=>'2026-11-02T09:00:00+03:00','metrics'=>['reach'=>100,'engagements'=>24,'clicks'=>7]],
 ['platform'=>'linkedin','published_at'=>'2026-11-02T16:00:00+03:00','metrics'=>['reach'=>100,'engagements'=>2,'clicks'=>0]],
];
$best=social_ops_best_times('linkedin',$rows);
so_check(count($best)>=2,'Best-time model returns observed time buckets.');
so_check($best[0]['hour']===9,'Higher observed engagement ranks the 09:00 bucket first.');
so_check($best[0]['confidence']==='developing','Three observations produce developing confidence.');
so_check($best[0]['score']===100,'Top observed bucket normalises to 100.');

$obs=social_ops_observation_input([
 'platform'=>'linkedin','published_at'=>'2026-11-01T10:30','reach'=>'120','engagements'=>'18','clicks'=>'3',
 'source'=>'LinkedIn native analytics export','post_url'=>'https://www.linkedin.com/posts/example'
],7);
so_check($obs['platform']==='linkedin'&&$obs['metrics']['clicks']===3,'Observation input preserves reviewed metrics.');
so_check($obs['recorded_by']===7,'Observation records actor identity.');
try{social_ops_observation_input(['platform'=>'linkedin','published_at'=>'2026-11-01T10:30','engagements'=>'2','source'=>'native'],7);so_check(false,'Missing denominator must fail.');}catch(InvalidArgumentException){so_check(true,'Missing denominator is rejected.');}

$links=social_ops_smartlinks_input(['links'=>[
 ['label'=>'Start Here','url'=>'https://www.waithakandegwa.com/start-here','enabled'=>'1'],
 ['label'=>'External','url'=>'https://example.org','enabled'=>''],
]]);
so_check(count($links)===2&&$links[0]['enabled']===true&&$links[1]['enabled']===false,'SmartLinks retain explicit visibility.');
so_check(preg_match('/^[a-f0-9]{12}$/',$links[0]['id'])===1,'SmartLinks receive stable first-party tracking ids.');
try{social_ops_smartlinks_input(['links'=>[['label'=>'Bad','url'=>'http://example.org','enabled'=>'1']]]);so_check(false,'Insecure SmartLink must fail.');}catch(InvalidArgumentException){so_check(true,'SmartLinks require HTTPS.');}

$competitor=social_ops_competitor_input(['platform'=>'instagram','name'=>'Public peer','url'=>'https://www.instagram.com/example','followers'=>'1000','engagements'=>'80','source'=>'Public profile snapshot, 1-7 Nov 2026'],7);
so_check($competitor['metrics']['followers']===1000&&$competitor['recorded_by']===7,'Competitor snapshots preserve public evidence and actor.');
$tag=social_ops_hashtag_input(['platform'=>'tiktok','hashtag'=>'#socialenterprise','views'=>'900','posts'=>'12','source'=>'Native public search snapshot, 7 Nov 2026'],7);
so_check($tag['hashtag']==='#socialenterprise'&&$tag['metrics']['views']===900,'Hashtag snapshots preserve observed public metrics.');

$source=(string)file_get_contents(dirname(__DIR__).'/app/social_operations.php');
so_check(str_contains($source,'Import up to 100')===false,'UI copy is not mixed into service code.');
so_check(str_contains($source,'function social_ops_bulk_rows'),'Bulk CSV parser exists.');
so_check(str_contains($source,'publishing_save_package($draft,$uid)'),'Bulk import creates Writer drafts through the existing publishing store.');
so_check(!str_contains($source,'publishing_approve(')&&!str_contains($source,'publishing_schedule('),'Bulk import cannot approve or schedule posts.');
$view=(string)file_get_contents(dirname(__DIR__).'/views/write/social_operations.php');
so_check(str_contains($view,'Bulk planner import')&&str_contains($view,'Import creates drafts only'),'Bulk planner explains its no-publish boundary.');
so_check(str_contains($view,'Best-time recommendations')&&str_contains($view,'SmartLinks / link in bio'),'Core native social tools are visible.');
so_check(str_contains($view,'Competitor watchlist')&&str_contains($view,'Hashtag & topic tracking'),'Native benchmarking tools are visible.');
$public=(string)file_get_contents(dirname(__DIR__).'/views/smartlinks.php');
so_check(!str_contains($public,'csrf')&&!str_contains($public,'write/'),'Public SmartLinks page contains no private controls.');
so_check(str_contains($public,"/links/go/"),'Public SmartLinks use first-party tracked redirects.');

echo 'PASS: '.$n." native social-operations planning, analytics and safety checks.\n";
