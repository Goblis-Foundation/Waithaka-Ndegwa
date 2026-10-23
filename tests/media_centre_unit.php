<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/functions.php';
require dirname(__DIR__).'/app/providers/http.php';
require dirname(__DIR__).'/app/media_centre.php';
function publishing_access_token(array $c):string{return 'synthetic-unit-token';}
function publishing_meta_version():string{return 'v24.0';}
function mc_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function mc_reject(callable $fn):void{$rejected=false;try{$fn();}catch(InvalidArgumentException){$rejected=true;}mc_check($rejected,'Invalid report accepted.');}
foreach([-1,1.5,'-1','1e3','1.2',[],str_repeat('9',20)]as$value)mc_check(media_centre_count($value)===null,'Unsafe metric accepted.');
mc_check(media_centre_count(0)===0&&media_centre_count('1234')===1234,'Zeros and numeric API strings survive.');
$input=['platform'=>'facebook','account'=>'@fixture','basis'=>'Native analytics export','start'=>'2026-01-01','end'=>'2026-01-28','followers'=>'0'];
$r=media_centre_manual($input);mc_check($r['metrics']['Followers at period end']===0&&!isset($r['metrics']['Views'])&&$r['source']==='Owner-recorded native report','Missing is not zero; provenance is explicit.');
foreach([['platform'=>'unknown'],['account'=>''],['basis'=>''],['start'=>'2026-02-30'],['end'=>'2025-01-01'],['views'=>'-1']]as$patch)mc_reject(fn()=>media_centre_manual(array_replace($input,$patch)));
mc_reject(fn()=>media_centre_api_report('x',[]));
$c=['service'=>'x','status'=>'connected','selected_resource'=>'123','display_name'=>'Test account'];$calls=[];
$r=media_centre_api_report('x',$c,static function($url,$h)use(&$calls){$calls[]=$url;return ['data'=>['id'=>'123','public_metrics'=>['followers_count'=>0,'tweet_count'=>12]]];});
mc_check($r['metrics']['Followers']===0&&$r['metrics']['Posts']===12&&!isset($r['metrics']['Following'])&&count($calls)===1,'Only returned account metrics are retained.');
mc_reject(fn()=>media_centre_api_report('x',$c,static fn()=>['data'=>['id'=>'different','public_metrics'=>['followers_count'=>99]]]));
mc_reject(fn()=>media_centre_api_report('linkedin',['service'=>'linkedin','status'=>'connected','selected_resource'=>'member']));
mc_check(count(media_centre_platforms())===6,'Every social account has a reporting route.');
echo "PASS: social analytics input, zero/missing, identity, source and grant boundaries.\n";

mc_check(array_keys(media_centre_connect_services())===array_keys(media_centre_platforms()),'Every platform has one primary Connect action.');
mc_check(media_centre_connect_services()['linkedin']==='linkedin'&&media_centre_connect_services()['youtube']==='google_youtube_upload','Primary grants support posting without silently adding separate analytics products.');
