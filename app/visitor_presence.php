<?php
declare(strict_types=1);
/** Consented first-party live presence; no URL, IP, user account or raw session ID is stored. */
function visitor_presence_compact(array $state,int $now): array {
 if($state===[])return ['version'=>1,'visitors'=>[]];
 if(($state['version']??null)!==1||!is_array($state['visitors']??null))throw new RuntimeException('Presence snapshot is invalid.');
 $out=['version'=>1,'visitors'=>[]];
 if(is_int($state['overflow_until']??null)&&$state['overflow_until']>$now)$out['overflow_until']=$state['overflow_until'];
 foreach($state['visitors']as$visitor=>$tabs){
  if(!is_string($visitor)||!preg_match('/^[a-f0-9]{64}$/D',$visitor)||!is_array($tabs))throw new RuntimeException('Presence identity is invalid.');
  foreach($tabs as$tab=>$entry){
   if(!is_string($tab)||!preg_match('/^[a-f0-9]{64}$/D',$tab)||!is_array($entry)||!is_int($entry['at']??null)||!is_int($entry['seq']??null)||!is_bool($entry['live']??null))throw new RuntimeException('Presence entry is invalid.');
   if($entry['at']>$now-60&&$entry['at']<=$now+5)$out['visitors'][$visitor][$tab]=$entry;
  }
 }
 return $out;
}
function visitor_presence_change(array $state,string $visitor,string $tab,bool $live,int $sequence,int $now): array {
 if(!preg_match('/^[a-f0-9]{64}$/D',$visitor)||!preg_match('/^[a-f0-9]{64}$/D',$tab)||$sequence<1||$sequence>1000000000)throw new InvalidArgumentException('Invalid presence request.');
 $state=visitor_presence_compact($state,$now);$previous=$state['visitors'][$visitor][$tab]??null;
 if($previous&&$sequence<=$previous['seq'])return $state;
 if(!isset($state['visitors'][$visitor])&&count($state['visitors'])>=2000){$state['overflow_until']=$now+60;return $state;}
 if(!isset($state['visitors'][$visitor][$tab])&&count($state['visitors'][$visitor]??[])>=32){$state['overflow_until']=$now+60;return $state;}
 // Retain a short-lived leave tombstone so an older in-flight heartbeat cannot
 // resurrect a hidden or closed tab. Separate tabs share one anonymous browser.
 $state['visitors'][$visitor][$tab]=['at'=>$now,'seq'=>$sequence,'live'=>$live];return $state;
}
function visitor_presence_count(array $state,int $now): ?int {
 $state=visitor_presence_compact($state,$now);if(($state['overflow_until']??0)>$now)return null;$count=0;
 foreach($state['visitors']as$tabs)foreach($tabs as$entry)if($entry['live']){$count++;break;}
 return $count;
}
function visitor_presence_identity(string $session,string $tab,string $key): array {
 if($session===''||!preg_match('/^[a-f0-9]{32}$/D',$tab)||strlen($key)<32)throw new InvalidArgumentException('Invalid presence session.');
 return [hash_hmac('sha256','live-browser|'.$session,$key),hash_hmac('sha256','live-tab|'.$session.'|'.$tab,$key)];
}
function visitor_presence_same_origin(string $origin,string $site,string $fetchSite): bool {
 return $origin!==''&&rtrim($origin,'/')===rtrim($site,'/')&&in_array($fetchSite,['','same-origin'],true);
}
function visitor_presence_public(): array {
 if(setting('google.analytics_enabled','1')!=='1')return ['now'=>null,'at'=>null];
 try{$now=time();$count=visitor_presence_count(comm_get('visitor.presence'),$now);return ['now'=>$count,'at'=>$count===null?null:$now];}
 catch(Throwable){return ['now'=>null,'at'=>null];}
}
function visitor_presence_cleanup(): void {
 comm_lock('visitor.presence',static function(){$state=comm_get('visitor.presence');if(!$state)return;$clean=visitor_presence_compact($state,time());if($clean!==$state)comm_put('visitor.presence',$clean);});
}
function visitor_presence_dispatch(string $path): bool {
 if($path!=='/api/visitor-presence')return false;
 journeys_method(['POST']);private_response_headers();header('Cache-Control: no-store, max-age=0');
 $origin=is_string($_SERVER['HTTP_ORIGIN']??null)?$_SERVER['HTTP_ORIGIN']:'';$fetchSite=is_string($_SERVER['HTTP_SEC_FETCH_SITE']??null)?$_SERVER['HTTP_SEC_FETCH_SITE']:'';
 if(!visitor_presence_same_origin($origin,base_url(),$fetchSite))json_response(['ok'=>false],403);
 if((int)($_SERVER['CONTENT_LENGTH']??0)>2048)json_response(['ok'=>false],413);
 verify_csrf();
 try{
  $tab=$_POST['tab']??null;$sequence=filter_var($_POST['sequence']??null,FILTER_VALIDATE_INT);$action=$_POST['state']??null;
  if(!is_string($tab)||$sequence===false||!in_array($action,['visible','left'],true))throw new InvalidArgumentException('Invalid presence input.');
  $live=$action==='visible';
  if($live&&((($_POST['consent']??'')!=='analytics')||setting('google.analytics_enabled','1')!=='1'))json_response(['ok'=>false],403);
  if(!journeys_rate_limit('live-presence',ip_hash(),240,60))json_response(['ok'=>false],429);
  [$visitor,$tabHash]=visitor_presence_identity(session_id(),$tab,(string)app_config('app_key'));
  // Session cookies are already necessary for this website. Only an HMAC of the
  // session is used, and only after explicit analytics consent; no new cookie.
  comm_lock('visitor.presence',static function()use($visitor,$tabHash,$live,$sequence){$old=comm_get('visitor.presence');$next=visitor_presence_change($old,$visitor,$tabHash,$live,$sequence,time());if($next!==$old)comm_put('visitor.presence',$next);});
  json_response(visitor_statistics_payload()+['ok'=>true],200);
 }catch(InvalidArgumentException){json_response(['ok'=>false],422);}
 catch(Throwable){json_response(['ok'=>false],503);}
}
