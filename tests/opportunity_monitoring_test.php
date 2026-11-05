<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/openai.php';
require dirname(__DIR__).'/app/opportunity_lifecycle.php';

// This suite uses in-memory settings and injected transports, never application data.
$memory=[];$settings=['openai.api_key'=>'fixture-not-a-credential'];$aiRaw='';$aiCalls=0;
function comm_get(string $key): array { return $GLOBALS['memory'][$key]??[]; }
function comm_put(string $key,array $value): void { $GLOBALS['memory'][$key]=$value; }
function setting(string $key,mixed $default=null): mixed { return $GLOBALS['settings'][$key]??$default; }
function desk_url(mixed $url,string $label,bool $required=false): string {
    if(!is_string($url)||!filter_var($url,FILTER_VALIDATE_URL))throw new InvalidArgumentException('Invalid fixture URL.');
    return $url;
}
function openai_text(string $instructions,string $input,int $maxTokens=700,bool $strict=false): ?string {
    $GLOBALS['aiCalls']++;return $GLOBALS['aiRaw'];
}
$n=0;
function omt(bool $condition,string $message): void { if(!$condition)throw new RuntimeException($message);$GLOBALS['n']++; }
function omthrows(callable $operation,string $reason): void {
    try{$operation();}catch(Throwable $error){omt($error->getMessage()===$reason,'Expected '.$reason.', got '.$error->getMessage());return;}
    throw new RuntimeException('Expected failure: '.$reason);
}
set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{if(error_reporting()&$severity)throw new ErrorException($message,0,$severity,$file,$line);return false;});
$now=time();$quote='The Example Fellowship 2027 accepts applications until 20 November 2026.';
$text=$quote.' Applicants need relevant professional experience and a qualifying degree. Review the official application form before submitting.';
$url='https://official.example/programme';$other='https://official.example/criteria';
$r=['id'=>'fixture','kind'=>'opportunity','slug'=>'monitor-recovery-fixture','state'=>'published','public_visible'=>true,
    'lifecycle'=>['sources'=>[$url]],'public'=>['digest'=>'fixture-public-digest','fields'=>['title'=>'Example Fellowship 2027','organisation'=>'Example provider','deadline'=> $now+20*86400,'sponsored'=>false]]];
$cfg=['ai_enabled'=>true,'ai_daily_limit'=>32];$fetch=static fn($url)=>$text;
$noChange=static fn($record,$texts)=>['changed'=>false];
$changed=static fn($record,$texts)=>['changed'=>true,'kind'=>'deadline','summary'=>'A supported change','source_index'=>0,'quote'=>$quote,'deadline_date'=>'2026-11-20'];
$good=om_scan($r,[],$cfg,$fetch,$noChange,$now);
omt($good['fetch_state']==='checked'&&$good['ai_state']==='no_change','A complete source and AI pass succeeds.');
omt($good['last_success']===$now&&$good['ai_checked_at']===$now,'Success timestamps are actual execution evidence.');
omt($good['engine_version']===om_version()&&$good['analysis_version']===om_version(),'Engine and analysis versions recorded.');
omt(om_due([],86400)===0,'Older records receive a new-engine check.');
omt(om_due($good,86400)===$now+86400,'Ordinary source cadence retained.');
$next=om_scan($r,$good,$cfg,$fetch,static function(){throw new RuntimeException('Unchanged evidence must not call AI twice.');},$now+5);
omt($next['ai_checked_at']===$now,'Unchanged source and listing avoid duplicate analysis.');
omt(count($next['attempt_history'])===2,'Private attempt history is retained.');
$failed=om_scan($r,$good,$cfg,static function(){throw new RuntimeException('source_access');},$noChange,$now+10);
omt($failed['fetch_state']==='needs_review'&&$failed['fetch_error']==='source_access','Access blocks are explicit.');
omt($failed['last_success']===$good['last_success']&&$failed['texts']===$good['texts']&&$failed['sources']===$good['sources'],'A failed read preserves previous evidence.');
omt($failed['ai_state']==='awaiting_source'&&$failed['next_check']===$now+3610,'Failed source read schedules a bounded retry without claiming a fresh AI pass.');
omt($r['state']==='published'&&$r['public']['digest']==='fixture-public-digest','Monitoring does not change the publication.');
$partial=$r;$partial['lifecycle']['sources']=[$url,$other];
$m=om_scan($partial,[],$cfg,static function($source)use($url,$text){if($source===$url)throw new RuntimeException('source_timeout');return $text;},$changed,$now);
omt($m['fetch_state']==='partial'&&count($m['texts'])===1&&count($m['source_reports'])===2,'One failed source does not discard another successful source.');
omt($m['sources'][0]===$other&&$m['proposal']['source_url']===$other,'AI evidence indexes refer to the successfully read source.');
omt($m['source_reports'][0]['reason']==='source_timeout'&&$m['source_reports'][1]['state']==='checked','Each source has its own outcome.');
omt($m['next_check']===$now+3600,'Partial reads receive a bounded retry.');
$proposal=$m['proposal'];$m['analysis_version']='old';
$m2=om_scan($partial,$m,$cfg,$fetch,$noChange,$now+15);
omt($m2['proposal']===$proposal&&$m2['ai_state']==='review_required','A subsequent no-change result cannot dismiss an unresolved suggestion.');
$badAi=om_scan($r,[],$cfg,$fetch,static function(){throw new OpenAiRequestException('token_limit');},$now);
omt($badAi['fetch_state']==='checked'&&$badAi['ai_error']==='analysis_token_limit'&&empty($badAi['ai_checked_at']),'Incomplete AI responses do not count as success.');
omt($badAi['next_check']===$now+3600,'AI failures receive bounded retries.');
omt(om_error(new OpenAiRequestException('authentication'),'analysis')==='analysis_credentials','Credential errors have a bounded diagnostic.');
omt(om_error(new RuntimeException('PRIVATE_PROVIDER_MESSAGE'),'source')==='source_unavailable','Unexpected private error text is not exposed.');
foreach([1=>3600,2=>7200,3=>14400,4=>28800,50=>57600]as$failures=>$delay)omt(om_retry($now,$failures,86400)===$now+$delay,'Retry backoff '.$failures);
omt(om_retry($now,50,21600)===$now+21600,'Retry interval never exceeds the near-deadline check interval.');
$history=$good;$history['attempt_history']=array_fill(0,30,[]);omt(count(om_finish($history)['attempt_history'])===20,'History stays bounded.');
$missing=$r;$missing['lifecycle']['sources']=[];
omt(om_scan($missing,[],$cfg,$fetch,$noChange,$now)['fetch_error']==='source_missing','Missing source is explicit.');

// Validate model output, including a no-change finding, against actual source text.
$valid=['changed'=>false,'same_round'=>true,'source_index'=>0,'quote'=>$quote];
$aiRaw=json_encode($valid,JSON_THROW_ON_ERROR);$analysed=ol_analyse($r,[$text]);
omt(!$analysed['changed']&&$analysed['quote']===$quote,'Production no-change result requires same-round evidence.');
$aiRaw='{"changed":false,"same_round":false}';omthrows(static fn()=>ol_analyse($r,[$text]),'analysis_source_mismatch');
$unsupported=$valid;$unsupported['quote']='This purported evidence is not present in the official source.';
omthrows(static fn()=>om_parse(json_encode($unsupported),[$text],true),'analysis_evidence_missing');
$whitespace=$valid;$whitespace['quote']=str_replace(' ','  ',$quote);
omt(om_parse(json_encode($whitespace),[$text],true)['quote']===$quote,'Evidence tolerates whitespace normalization only.');
$invalid=['changed'=>true,'same_round'=>true,'kind'=>'deadline','source_index'=>0,'quote'=>$quote,'deadline_date'=>'2026-02-30'];
omthrows(static fn()=>om_parse(json_encode($invalid),[$text],true),'analysis_date_invalid');
omthrows(static fn()=>om_parse('{broken',[$text],true),'analysis_invalid');
omthrows(static fn()=>om_parse(str_repeat('x',16001),[$text],true),'analysis_invalid');
$injected=om_scan($r,[],$cfg,$fetch,static fn()=>['changed'=>true,'kind'=>'deadline','source_index'=>0,'quote'=>'Invented application extension not in the source.','deadline_date'=>'2026-12-31'],$now);
omt($injected['ai_error']==='analysis_evidence_missing'&&empty($injected['proposal']),'Injected adapters cannot manufacture revision evidence.');
$aiRaw=json_encode($valid);$memory=[];$aiCalls=0;
$budgeted=om_scan($r,[],$cfg,$fetch,null,$now);
omt($aiCalls===1&&($memory['ol.budget.'.gmdate('Y-m-d',$now)]['calls']??0)===1,'Production AI use consumes the daily allowance.');
omt($budgeted['analysis_evidence']['source_url']===$url,'Production evidence provenance is saved.');
$memory['ol.budget.'.gmdate('Y-m-d',$now)]=['calls'=>32];$limited=om_scan($r,[],$cfg,$fetch,null,$now);
omt($limited['ai_state']==='daily_limit'&&$aiCalls===1&&empty($limited['ai_checked_at']),'Exhausted daily allowance does not call AI or claim success.');
$settings=[];$unconfigured=om_scan($r,[],$cfg,$fetch,null,$now);omt($unconfigured['ai_state']==='not_configured','No key means no AI verification.');
$disabled=om_scan($r,[],['ai_enabled'=>false]+$cfg,$fetch,null,$now);omt($disabled['ai_state']==='disabled','Explicit AI disable remains effective.');

// Injected HTTP responses exercise network boundaries without any network access.
$resolve=static fn($host)=>['8.8.8.8'];$request=static fn()=>['status'=>200,'type'=>'text/plain','body'=>$text];
omt(om_fetch($url,$resolve,$request)===$text,'Readable public HTTPS response succeeds.');
foreach(['http://official.example/a','https://user:password@official.example/a','https://official.example:8443/a']as$unsafe)omthrows(static fn()=>om_fetch($unsafe,$resolve,$request),'source_address_review');
foreach(['127.0.0.1','10.0.0.1','169.254.169.254','192.168.1.2','::1']as$ip)omthrows(static fn()=>om_fetch($url,static fn()=>[$ip],$request),'source_address_review');
omthrows(static fn()=>om_fetch($url,static fn()=>false,$request),'source_dns');
foreach([401=>'source_access',403=>'source_access',999=>'source_access',429=>'source_rate_limit',404=>'source_not_found',410=>'source_not_found',503=>'source_server']as$status=>$reason)omthrows(static fn()=>om_fetch($url,$resolve,static fn()=>['status'=>$status]),$reason);
omthrows(static fn()=>om_fetch($url,$resolve,static fn()=>['status'=>302,'location'=>'https://other.example/notice']),'source_redirect_review');
omthrows(static fn()=>om_fetch($url,$resolve,static fn()=>['status'=>302,'location'=>'https://169.254.169.254/latest/']),'source_redirect_review');
$requests=0;$relative=static function($destination)use(&$requests,$text){$requests++;return $requests===1?['status'=>302,'location'=>'../notice?round=2027']:['status'=>200,'type'=>'text/plain','body'=>$text];};
omt(om_fetch('https://official.example/calls/old',$resolve,$relative)===$text&&$requests===2,'Same-host relative redirects work.');
omt(om_redirect('https://official.example/a/b','../notice?round=2027')==='https://official.example/notice?round=2027','Relative paths resolve correctly.');
omt(om_redirect($url,'/')==='https://official.example/','Root redirect has one slash.');
omthrows(static fn()=>om_fetch($url,$resolve,static fn()=>['status'=>302,'location'=>'/again']),'source_redirect_review');
omthrows(static fn()=>om_text($text,'application/pdf'),'source_format');
omthrows(static fn()=>om_text(str_repeat('x',1048577),'text/plain'),'source_size');
omthrows(static fn()=>om_text('Too short','text/plain'),'source_content');
omthrows(static fn()=>om_text('<html><head><title>Just a moment</title></head><body>'.str_repeat('Waiting ',100).'</body></html>','text/html'),'source_access');
$html='<html><body><nav>IGNORE_NAV</nav><main><h1>Example Fellowship 2027</h1><p>'.$quote.'</p><p>Relevant experience is required. Full details are in the official application portal.</p></main><footer>IGNORE_FOOTER</footer><script>IGNORE_SCRIPT</script></body></html>';
$clean=om_text($html,'text/html; charset=utf-8');omt(str_contains($clean,$quote)&&!str_contains($clean,'IGNORE_'),'HTML evidence retains announcement text and removes navigation/scripts.');
restore_error_handler();echo 'PASS: '.$n." source recovery, evidence, retry, budget and network-boundary assertions. No external calls.\n";
