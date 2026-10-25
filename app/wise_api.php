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
 if(!$read&&!$subscribe)throw new InvalidArgumentException('Wise operation is not enabled.');
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
 if(!in_array($event['schema_version']??'',['4.0.0'],true)||($event['event_type']??'')!=='balances#update')throw new InvalidArgumentException('Unsupported Wise event.');
 if(!hash_equals((string)($cfg['subscription']??''),(string)($event['subscription_id']??''))||empty($cfg['subscription']))throw new InvalidArgumentException('Unexpected subscription.');
 $d=$event['data']??[];if((string)($d['resource']['profile_id']??'')!==$cfg['profile'])throw new InvalidArgumentException('Unexpected Wise profile.');
 return ['event'=>'balances#update','subscription'=>$event['subscription_id'],'profile'=>$cfg['profile'],'balance'=>substr((string)($d['balance_id']??''),0,30),'step'=>substr((string)($d['step_id']??''),0,80),'currency'=>substr((string)($d['currency']??''),0,3),'direction'=>substr((string)($d['transaction_type']??''),0,10),'occurred'=>substr((string)($d['occurred_at']??''),0,40),'reference'=>substr((string)($d['transfer_reference']??''),0,180),'amount'=>is_numeric($d['amount']??null)?(string)$d['amount']:'','test'=>$test,'status'=>$test?'test only':'awaiting reconciliation','received'=>time()];
}
function wise_api_webhook(): never {
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST')json_response(['ok'=>false],405);$cfg=wise_api_config();if(empty($cfg['webhook_enabled'])||empty($cfg['subscription']))json_response(['ok'=>false],503);
 $raw=file_get_contents('php://input',false,null,0,262145);if(!is_string($raw)||strlen($raw)>262144)json_response(['ok'=>false],413);
 if(!wise_api_signature($raw,(string)($_SERVER['HTTP_X_SIGNATURE_SHA256']??''),wise_api_public_key($cfg['environment']??'production')))json_response(['ok'=>false],401);
 try{$event=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($event))throw new InvalidArgumentException();$row=wise_api_webhook_data($event,$cfg,strtolower((string)($_SERVER['HTTP_X_TEST_NOTIFICATION']??''))==='true');}catch(JsonException|InvalidArgumentException){json_response(['ok'=>false],400);}
 $id=hash('sha256',($cfg['environment']??'production').'|'.$cfg['profile'].'|'.$raw);comm_lock('wise.events',function()use($id,$row){if(!comm_get('wise.event.'.$id))comm_put('wise.event.'.$id,$row+['id'=>$id]);});json_response(['ok'=>true],200);
}
function wise_api_events(): array {$q=db()->query("SELECT setting_value FROM settings WHERE setting_key LIKE 'communication.wise.event.%' ORDER BY setting_key DESC LIMIT 50");return array_map(static fn($r)=>json_decode(decrypt_secret($r['setting_value']),true,32,JSON_THROW_ON_ERROR),$q->fetchAll());}
function wise_api_owner_action(int $uid,array $in): void {
 comm_lock('wise.api',function()use($uid,$in){$cfg=wise_api_config();$action=$in['action']??'';if((int)($in['api_version']??0)!==(int)($cfg['version']??0))throw new RuntimeException('Wise connection changed. Reload before continuing.');
 if($action==='api_save'){$env=reader_choice($in['environment']??'production',['production'=>'Live','sandbox'=>'Sandbox'],'Wise environment');$profile=reader_text($in['profile']??'','Wise profile ID',30,true);if(!preg_match('/^[1-9][0-9]*$/D',$profile))throw new InvalidArgumentException('Use the numeric Wise profile ID.');$token=reader_text($in['api_token']??'','API token',5000);if($token!==''&&preg_match('/\s/',$token))throw new InvalidArgumentException('Check the token; it must not contain whitespace.');$changed=$env!==($cfg['environment']??'')||$profile!==($cfg['profile']??'')||$token!=='';$cfg['environment']=$env;$cfg['profile']=$profile;if($token!=='')$cfg['token']=$token;if($changed){unset($cfg['probe']);$cfg['webhook_enabled']=false;$cfg['subscription']='';}$sub=reader_text($in['subscription']??'','Webhook subscription ID',80);if($sub!==''&&!preg_match('/^[a-fA-F0-9-]{36}$/D',$sub))throw new InvalidArgumentException('Use the Wise webhook subscription UUID.');$cfg['subscription']=$sub;if(!empty($in['webhook_enabled'])){if(empty($cfg['probe'])||$sub==='')throw new RuntimeException('Verify access, then save the correct subscription ID before enabling its receiver.');$cfg['webhook_enabled']=true;}else $cfg['webhook_enabled']=false;}
 elseif($action==='api_probe'){$cfg['probe']=wise_api_probe($cfg);}
 elseif($action==='api_statement'){if(empty($cfg['probe']))throw new RuntimeException('Verify this profile first.');$balance=null;foreach($cfg['probe']['balances']as$b)if($b['id']===(string)($in['balance']??''))$balance=$b;if(!$balance)throw new InvalidArgumentException('Choose a verified currency balance.');$query=http_build_query(['currency'=>$balance['currency'],'intervalStart'=>gmdate('Y-m-d\TH:i:s.000\Z',time()-86400),'intervalEnd'=>gmdate('Y-m-d\TH:i:s.000\Z'),'type'=>'COMPACT']);$statement=wise_api_request($cfg,'GET','/profiles/'.$cfg['profile'].'/balance-statements/'.$balance['id'].'/statement.json?'.$query);if(!isset($statement['transactions'])||!is_array($statement['transactions']))throw new RuntimeException('The statement response was incomplete.');$cfg['probe']['statements']='verified read access';$cfg['probe']['statement_at']=time();$cfg['probe']['statement_currency']=$balance['currency'];}
 else throw new InvalidArgumentException('Choose a Wise setup action.');
 $cfg['version']=($cfg['version']??0)+1;$cfg['updated']=time();$cfg['user']=$uid;comm_put('giving.wise.api',$cfg);
 });
}
