<?php
declare(strict_types=1);
function wise_api_config(): array {return comm_get('giving.wise.api');}
function wise_api_host(string $env): string {return $env==='sandbox'?'https://api.wise-sandbox.com':'https://api.wise.com';}
function wise_api_public_key(string $env): string {
 return $env==='sandbox'?<<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAwpb91cEYuyJNQepZAVfP
ZIlPZfNUefH+n6w9SW3fykqKu938cR7WadQv87oF2VuT+fDt7kqeRziTmPSUhqPU
ys/V2Q1rlfJuXbE+Gga37t7zwd0egQ+KyOEHQOpcTwKmtZ81ieGHynAQzsn1We3j
wt760MsCPJ7GMT141ByQM+yW1Bx+4SG3IGjXWyqOWrcXsxAvIXkpUD/jK/L958Cg
nZEgz0BSEh0QxYLITnW1lLokSx/dTianWPFEhMC9BgijempgNXHNfcVirg1lPSyg
z7KqoKUN0oHqWLr2U1A+7kqrl6O2nx3CKs1bj1hToT1+p4kcMoHXA7kA+VBLUpEs
VwIDAQAB
-----END PUBLIC KEY-----
PEM
:<<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAvO8vXV+JksBzZAY6GhSO
XdoTCfhXaaiZ+qAbtaDBiu2AGkGVpmEygFmWP4Li9m5+Ni85BhVvZOodM9epgW3F
bA5Q1SexvAF1PPjX4JpMstak/QhAgl1qMSqEevL8cmUeTgcMuVWCJmlge9h7B1CS
D4rtlimGZozG39rUBDg6Qt2K+P4wBfLblL0k4C4YUdLnpGYEDIth+i8XsRpFlogx
CAFyH9+knYsDbR43UJ9shtc42Ybd40Afihj8KnYKXzchyQ42aC8aZ/h5hyZ28yVy
Oj3Vos0VdBIs/gAyJ/4yyQFCXYte64I7ssrlbGRaco4nKF3HmaNhxwyKyJafz19e
HwIDAQAB
-----END PUBLIC KEY-----
PEM;
}
function wise_api_signature(string $raw,string $signature,string $key): bool {
 $sig=base64_decode($signature,true);if($sig===false||strlen($raw)>262144)return false;return openssl_verify($raw,$sig,$key,OPENSSL_ALGO_SHA256)===1;
}
/** Deliberately limited to identity, balances, statements and subscription management. */
function wise_api_request(array $cfg,string $method,string $path,?array $body=null): array {
 $read=$method==='GET'&&preg_match('~^/profiles(?:/[1-9][0-9]*(?:/balances\?types=STANDARD|/balance-statements/[1-9][0-9]*/statement\.json\?[^\r\n]+)?)?$~D',$path);
 $subscribe=$method==='POST'&&preg_match('~^/profiles/[1-9][0-9]*/subscriptions$~D',$path);
 $subscriptionsRead=$method==='GET'&&preg_match('~^/profiles/[1-9][0-9]*/subscriptions(?:/[a-fA-F0-9-]{36})?$~D',$path);
 if(!$read&&!$subscribe&&!$subscriptionsRead)throw new InvalidArgumentException('Wise operation is not enabled.');
 $token=$cfg['token']??'';if(!is_string($token)||$token===''||preg_match('/[\r\n]/',$token))throw new RuntimeException('Save the Wise API token securely first.');
 $ch=curl_init(wise_api_host($cfg['environment']??'production').'/2026Q4'.$path);$raw='';curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json','Content-Type: application/json'],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$raw){if(strlen($raw)+strlen($chunk)>1048576)return 0;$raw.=$chunk;return strlen($chunk);}]);if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));$ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
 if($ok===false||$code<200||$code>=300)throw new RuntimeException('Wise request was not authorised or available (HTTP '.$code.'). Check token permissions, account eligibility and any required Wise authentication. No payment has been confirmed.');
 try{$data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(JsonException){throw new RuntimeException('Wise returned an unreadable response.');}if(!is_array($data))throw new RuntimeException('Wise response is incomplete.');return $data;
}
function wise_api_probe(array $cfg): array {
 $profiles=wise_api_request($cfg,'GET','/profiles');$profile=null;foreach($profiles as$p)if((string)($p['id']??'')===$cfg['profile'])$profile=$p;if(!$profile)throw new RuntimeException('The selected profile is not accessible with this token.');
 $balances=wise_api_request($cfg,'GET','/profiles/'.$cfg['profile'].'/balances?types=STANDARD');$out=[];foreach($balances as$b)if(in_array($b['currency']??'',['EUR','USD','GBP'],true)&&preg_match('/^[1-9][0-9]*$/D',(string)($b['id']??'')))$out[]=['id'=>(string)$b['id'],'currency'=>$b['currency']];
 return ['at'=>time(),'profile'=>$cfg['profile'],'type'=>substr((string)($profile['type']??''),0,30),'country'=>substr((string)($profile['address']['countryIso2Code']??$profile['details']['address']['country']??''),0,3),'balances'=>$out,'identity'=>'verified','statements'=>'not tested'];
}
function wise_api_webhook_data(array $event,array $cfg,bool $test): array {
 if(!((($event['event_type']??'')==='balances#update'&&in_array($event['schema_version']??'',['4.0.0'],true))||(($event['event_type']??'')==='balances#credit'&&in_array($event['schema_version']??'',['2.0.0','4.0.0'],true))))throw new InvalidArgumentException('Unsupported Wise event.');
 if(!hash_equals((string)($cfg['subscription']??''),(string)($event['subscription_id']??''))||empty($cfg['subscription']))throw new InvalidArgumentException('Unexpected subscription.');
 $d=$event['data']??[];if((string)($d['resource']['profile_id']??'')!==$cfg['profile'])throw new InvalidArgumentException('Unexpected Wise profile.');
 return ['event'=>$event['event_type'],'subscription'=>$event['subscription_id'],'profile'=>$cfg['profile'],'balance'=>substr((string)($d['balance_id']??''),0,30),'step'=>substr((string)($d['step_id']??''),0,80),'currency'=>substr((string)($d['currency']??''),0,3),'direction'=>substr((string)($d['transaction_type']??''),0,10),'occurred'=>substr((string)($d['occurred_at']??''),0,40),'reference'=>substr((string)($d['transfer_reference']??''),0,180),'amount'=>is_numeric($d['amount']??null)?(string)$d['amount']:'','test'=>$test,'status'=>$test?'test only':'awaiting reconciliation','received'=>time()];
}
function wise_api_setup_test(array $event,bool $test): bool {
 return $test&&is_string($event['event_type']??null)&&$event['event_type']!==''&&is_string($event['schema_version']??null);
}
function wise_api_webhook(): never {
 $method=$_SERVER['REQUEST_METHOD']??'';if(in_array($method,['GET','HEAD'],true))json_response(['ok'=>true,'service'=>'Wise webhook','delivery'=>'POST signed Wise notifications; browser visits do not confirm payments.'],200);
 if($method!=='POST')json_response(['ok'=>false],405);$cfg=wise_api_config();
 $raw=file_get_contents('php://input',false,null,0,262145);if(!is_string($raw)||strlen($raw)>262144)json_response(['ok'=>false],413);
 if(!wise_api_signature($raw,(string)($_SERVER['HTTP_X_SIGNATURE_SHA256']??''),wise_api_public_key($cfg['environment']??'production')))json_response(['ok'=>false],401);
 try{$event=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($event))throw new InvalidArgumentException();
 // Signed test deliveries establish reachability before Wise supplies a subscription ID.
 // They never enter the payment event store, activate a receiver, or confirm receipt.
 if(wise_api_setup_test($event,strtolower((string)($_SERVER['HTTP_X_TEST_NOTIFICATION']??''))==='true'))json_response(['ok'=>true,'test'=>true,'payments_confirmed'=>false],200);
 if(empty($cfg['webhook_enabled'])||empty($cfg['subscription']))json_response(['ok'=>false],503);
 $row=wise_api_webhook_data($event,$cfg,strtolower((string)($_SERVER['HTTP_X_TEST_NOTIFICATION']??''))==='true');}catch(JsonException|InvalidArgumentException){json_response(['ok'=>false],400);}
 $id=hash('sha256',($cfg['environment']??'production').'|'.$cfg['profile'].'|'.$raw);comm_lock('wise.events',function()use($id,$row){if(!comm_get('wise.event.'.$id))comm_put('wise.event.'.$id,$row+['id'=>$id]);});json_response(['ok'=>true],200);
}
function wise_api_events(): array {$q=db()->query("SELECT setting_value FROM settings WHERE setting_key LIKE 'communication.wise.event.%' ORDER BY setting_key DESC LIMIT 50");return array_map(static fn($r)=>json_decode(decrypt_secret($r['setting_value']),true,32,JSON_THROW_ON_ERROR),$q->fetchAll());}

function wise_api_subscription_match(array $row,string $profile,string $callback): bool {
 return preg_match('/^[a-fA-F0-9-]{36}$/D',(string)($row['id']??''))===1
 &&($row['trigger_on']??'')==='balances#credit'
 &&($row['delivery']['version']??'')==='4.0.0'
 &&($row['delivery']['url']??'')===$callback
 &&($row['scope']['domain']??'')==='profile'&&(string)($row['scope']['id']??'')===$profile;
}
function wise_api_connect_notifications(array $cfg,?callable $send=null): array {
 if(empty($cfg['probe'])||($cfg['probe']['profile']??'')!==($cfg['profile']??''))throw new RuntimeException('Verify the selected Wise profile first.');
 $callback=url('/webhooks/wise');if(!preg_match('~^https://www\\.waithakandegwa\\.com/webhooks/wise$~D',$callback))throw new RuntimeException('Use the production Wise callback.');
 $send??=static fn($method,$path,$body=null)=>wise_api_request($cfg,$method,$path,$body);
 $path='/profiles/'.$cfg['profile'].'/subscriptions';$rows=$send('GET',$path);
 if(!array_is_list($rows))throw new RuntimeException('Wise subscriptions could not be inspected. No subscription was created.');
 $matches=array_values(array_filter($rows,static fn($row)=>is_array($row)&&wise_api_subscription_match($row,$cfg['profile'],$callback)));
 if(count($matches)>1)throw new RuntimeException('Multiple website subscriptions need review. No subscription was created.');
 if($matches)$row=$matches[0];
 else{
  foreach($rows as$row)if(is_array($row)&&($row['delivery']['url']??'')===$callback)throw new RuntimeException('An existing website callback uses a different event or schema. Review it in Wise first.');
  $row=$send('POST',$path,['name'=>'Waithaka Ndegwa Website receiving updates','trigger_on'=>'balances#credit','delivery'=>['version'=>'4.0.0','url'=>$callback]]);
  if(!is_array($row)||!wise_api_subscription_match($row,$cfg['profile'],$callback))throw new RuntimeException('Wise subscription creation needs review. Reload and reconnect to inspect it before trying again.');
 }
 $verified=$send('GET',$path.'/'.$row['id']);
 if(!wise_api_subscription_match($verified,$cfg['profile'],$callback))throw new RuntimeException('Wise subscription details did not match this profile and callback.');
 $cfg['subscription']=$verified['id'];$cfg['webhook_enabled']=true;$cfg['subscription_verified_at']=time();return $cfg;
}

function wise_api_owner_action(int $uid,array $in): void {
 comm_lock('wise.api',function()use($uid,$in){$cfg=wise_api_config();$action=$in['action']??'';if((int)($in['api_version']??0)!==(int)($cfg['version']??0))throw new RuntimeException('Wise connection changed. Reload before continuing.');
 if($action==='api_save'){$env=reader_choice($in['environment']??'production',['production'=>'Live','sandbox'=>'Sandbox'],'Wise environment');$profile=reader_text($in['profile']??'','Wise profile ID',30,true);if(!preg_match('/^[1-9][0-9]*$/D',$profile))throw new InvalidArgumentException('Use the numeric Wise profile ID.');$token=reader_text($in['api_token']??'','API token',5000);if($token!==''&&preg_match('/\s/',$token))throw new InvalidArgumentException('Check the token; it must not contain whitespace.');$changed=$env!==($cfg['environment']??'')||$profile!==($cfg['profile']??'')||$token!=='';$cfg['environment']=$env;$cfg['profile']=$profile;if($token!=='')$cfg['token']=$token;if($changed){unset($cfg['probe'],$cfg['available_profiles']);$cfg['webhook_enabled']=false;$cfg['subscription']='';}$sub=reader_text($in['subscription']??'','Webhook subscription ID',80);if($sub!==''&&!preg_match('/^[a-fA-F0-9-]{36}$/D',$sub))throw new InvalidArgumentException('Use the Wise webhook subscription UUID.');$cfg['subscription']=$sub;if(!empty($in['webhook_enabled'])){if(empty($cfg['probe'])||$sub==='')throw new RuntimeException('Verify access, then save the correct subscription ID before enabling its receiver.');$cfg['webhook_enabled']=true;}else $cfg['webhook_enabled']=false;}
 elseif($action==='api_profiles'){$profiles=wise_api_request($cfg,'GET','/profiles');$available=[];foreach($profiles as$p)if(is_array($p)&&preg_match('/^[1-9][0-9]*$/D',(string)($p['id']??'')))$available[]=['id'=>(string)$p['id'],'type'=>substr((string)($p['type']??''),0,30)];$cfg['available_profiles']=array_slice($available,0,30);if(!$cfg['available_profiles'])throw new RuntimeException('Wise returned no accessible profiles. Check token scope and account eligibility.');}
 elseif($action==='api_notifications'){$cfg=wise_api_connect_notifications($cfg);}
 elseif($action==='api_probe'){$cfg['probe']=wise_api_probe($cfg);}
 elseif($action==='api_statement'){if(empty($cfg['probe']))throw new RuntimeException('Verify this profile first.');$balance=null;foreach($cfg['probe']['balances']as$b)if($b['id']===(string)($in['balance']??''))$balance=$b;if(!$balance)throw new InvalidArgumentException('Choose a verified currency balance.');$query=http_build_query(['currency'=>$balance['currency'],'intervalStart'=>gmdate('Y-m-d\TH:i:s.000\Z',time()-86400),'intervalEnd'=>gmdate('Y-m-d\TH:i:s.000\Z'),'type'=>'COMPACT']);$statement=wise_api_request($cfg,'GET','/profiles/'.$cfg['profile'].'/balance-statements/'.$balance['id'].'/statement.json?'.$query);if(!isset($statement['transactions'])||!is_array($statement['transactions']))throw new RuntimeException('The statement response was incomplete.');$cfg['probe']['statements']='verified read access';$cfg['probe']['statement_at']=time();$cfg['probe']['statement_currency']=$balance['currency'];}
 else throw new InvalidArgumentException('Choose a Wise setup action.');
 $cfg['version']=($cfg['version']??0)+1;$cfg['updated']=time();$cfg['user']=$uid;comm_put('giving.wise.api',$cfg);
 });
}
