<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/functions.php';
require dirname(__DIR__).'/app/providers/http.php';
require dirname(__DIR__).'/app/providers/instagram_engagement.php';
function publishing_access_token(array $c):string{return 'fixture-token';}
function publishing_meta_version():string{return 'v24.0';}
function ig_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function ig_reject(callable $fn):void{$rejected=false;try{$fn();}catch(InvalidArgumentException){$rejected=true;}ig_check($rejected,'Unsafe request accepted.');}
$c=['service'=>'instagram_comments','status'=>'connected','selected_resource'=>'123','scopes_json'=>'["instagram_business_manage_comments"]'];$calls=[];
$get=static function($url,$headers)use(&$calls){$calls[]=$url;if(str_contains($url,'?fields=id,user_id,username'))return ['user_id'=>'123'];if(str_contains($url,'/media?'))return ['data'=>[['id'=>'456','caption'=>'<script>never execute</script>']],'paging'=>['next'=>'https://untrusted.invalid/token=secret']];if(str_contains($url,'/456/comments?'))return ['data'=>[['id'=>'comment','text'=>'<img onerror=alert(1)>','timestamp'=>'2026-10-23']]];throw new RuntimeException('Unexpected endpoint.');};
$r=publishing_instagram_engagement($c,'comments','456',$get);
ig_check(count($calls)===3&&$r['rows'][0]['text']==='<img onerror=alert(1)>'&&$r['has_more']&&!str_contains(json_encode($r),'untrusted.invalid'),'Only bounded data is retained, never provider paging URLs.');
$calls=[];ig_reject(fn()=>publishing_instagram_engagement($c,'comments','outside-account',$get));ig_check(count($calls)===2,'Unowned resource rejected before reading comments.');
$calls=[];ig_reject(fn()=>publishing_instagram_engagement(array_replace($c,['scopes_json'=>'[]']),'list','',$get));ig_check(!$calls,'Missing scope never reads remote data.');
ig_reject(fn()=>publishing_instagram_engagement($c,'send','456',$get));
ig_reject(fn()=>publishing_instagram_engagement($c,'list','',static fn()=>['user_id'=>'different']));
$m=array_replace($c,['service'=>'instagram_messages','scopes_json'=>'["instagram_business_manage_messages"]']);$calls=[];
$r=publishing_instagram_engagement($m,'conversation','thread',static function($url,$h)use(&$calls){$calls[]=$url;if(str_contains($url,'?fields=id,user_id,username'))return ['user_id'=>'123'];if(str_contains($url,'/conversations?'))return ['data'=>[['id'=>'thread','updated_time'=>'2026-10-23']]];if(str_contains($url,'/thread/messages?'))return ['data'=>[['id'=>'message','message'=>'Private fixture','from'=>['username'=>'fixture'],'created_time'=>'2026-10-23']]];throw new RuntimeException('Unexpected endpoint.');});
ig_check($r['rows'][0]['text']==='Private fixture'&&count($calls)===3,'Selected conversation read uses only fixed provider endpoints.');
$empty=publishing_instagram_engagement($m,'list','',static fn($url)=>str_contains($url,'/conversations?')?['data'=>[]]:['user_id'=>'123']);
ig_check($empty['items']===[],'Empty API results are preserved without claiming a complete inbox.');
echo "PASS: Instagram engagement scopes, identity, ownership, fixed endpoints, bounded output and read-only actions.\n";
