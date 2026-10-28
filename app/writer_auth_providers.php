<?php
declare(strict_types=1);

function wa_providers(): array {return [
    'google'=>['name'=>'Google','authorize'=>'https://accounts.google.com/o/oauth2/v2/auth','token'=>'https://oauth2.googleapis.com/token','keys'=>'https://www.googleapis.com/oauth2/v3/certs','callback'=>'google_calendar'],
    'microsoft'=>['name'=>'Microsoft','authorize'=>'https://login.microsoftonline.com/common/oauth2/v2.0/authorize','token'=>'https://login.microsoftonline.com/common/oauth2/v2.0/token','keys'=>'https://login.microsoftonline.com/common/discovery/v2.0/keys','callback'=>'meetings_microsoft'],
];}
function wa_provider_ready(string $provider): bool {$c=publishing_client($provider);return isset(wa_providers()[$provider])&&$c['client_id']!==''&&$c['client_secret']!=='';}
/** Reuse the registered application callback; one-use state distinguishes sign-in from workspace grants. */
function wa_callback(string $provider): string {return publishing_callback_url(wa_providers()[$provider]['callback']);}
function wa_oauth_start(string $provider,bool $trust): string {
    $p=wa_providers()[$provider]??throw new WriterAuthException('Choose Google or Microsoft.');if(!wa_provider_ready($provider))throw new WriterAuthException($p['name'].' sign-in needs its application setup. Use your Writer password for now.');
    if(!journeys_rate_limit('auth-oauth',ip_hash(),20,900))throw new WriterAuthException('Please wait before starting another sign-in.');
    $client=publishing_client($provider);$state=bin2hex(random_bytes(32));$nonce=bin2hex(random_bytes(32));$verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
    $_SESSION['wa_oauth']=['provider'=>$provider,'state_hash'=>wa_mac($state),'nonce'=>$nonce,'verifier'=>$verifier,'trust'=>$trust,'expires'=>time()+600,'session'=>wa_mac(session_id()),'client'=>wa_mac(publishing_json($client))];
    return $p['authorize'].'?'.http_build_query(['client_id'=>$client['client_id'],'response_type'=>'code','redirect_uri'=>wa_callback($provider),'scope'=>'openid profile email','state'=>$state,'nonce'=>$nonce,'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256','prompt'=>'select_account','response_mode'=>'query'],'','&',PHP_QUERY_RFC3986);
}
function wa_oauth_consume(string $provider,mixed $state): array {
    $f=$_SESSION['wa_oauth']??[];unset($_SESSION['wa_oauth']);if(!is_string($state)||!preg_match('/^[a-f0-9]{64}$/D',$state)||($f['provider']??'')!==$provider||($f['expires']??0)<=time()||!hash_equals($f['state_hash']??'',wa_mac($state))||!hash_equals($f['session']??'',wa_mac(session_id()))||!hash_equals($f['client']??'',wa_mac(publishing_json(publishing_client($provider)))))throw new WriterAuthException('This provider sign-in expired or changed. Start again.');return $f;
}
function wa_id_claims(string $provider,string $token,array $jwks,string $client,string $nonce,?int $now=null): array {
    $now??=time();if(strlen($token)>32000)throw new WriterAuthException('Invalid provider identity.');
    try{
        $parts=explode('.',$token);if(count($parts)!==3)throw new RuntimeException();$header=json_decode(\Firebase\JWT\JWT::urlsafeB64Decode($parts[0]),true,8,JSON_THROW_ON_ERROR);
        if(($header['alg']??'')!=='RS256'||!is_string($header['kid']??null)||strlen($header['kid'])>200)throw new RuntimeException();
        $keys=[];foreach($jwks['keys']??[]as$key)if(($key['kid']??'')===$header['kid']&&($key['kty']??'')==='RSA'&&($key['use']??'sig')==='sig'&&($key['alg']??'RS256')==='RS256')$keys[]=$key;
        if(count($keys)!==1)throw new RuntimeException();$previous=\Firebase\JWT\JWT::$timestamp;\Firebase\JWT\JWT::$timestamp=$now;
        try{$c=(array)\Firebase\JWT\JWT::decode($token,\Firebase\JWT\JWK::parseKeySet(['keys'=>$keys],'RS256'));}finally{\Firebase\JWT\JWT::$timestamp=$previous;}
        $aud=$c['aud']??null;if($aud!==$client&&$aud!==[$client])throw new RuntimeException();
        if(isset($c['azp'])&&$c['azp']!==$client||!is_string($c['nonce']??null)||!hash_equals($nonce,$c['nonce'])||!is_int($c['iat']??null)||$c['iat']>$now+60||$c['iat']<$now-900||!is_int($c['exp']??null)||$c['exp']<=$now||!is_string($c['sub']??null)||$c['sub']===''||strlen($c['sub'])>255)throw new RuntimeException();
        if($provider==='google'){if(!in_array($c['iss']??'',['accounts.google.com','https://accounts.google.com'],true))throw new RuntimeException();$c['iss']='https://accounts.google.com';}
        elseif($provider==='microsoft'){
            if(!is_string($c['tid']??null)||!preg_match('/^[a-f0-9-]{36}$/Di',$c['tid'])||($c['iss']??'')!=='https://login.microsoftonline.com/'.$c['tid'].'/v2.0')throw new RuntimeException();
            if(isset($keys[0]['issuer'])&&str_replace('{tenantid}',$c['tid'],$keys[0]['issuer'])!==$c['iss'])throw new RuntimeException();
        }else throw new RuntimeException();return $c;
    }catch(Throwable){throw new WriterAuthException('The provider identity could not be verified. Start sign-in again.');}
}
function wa_identity_key(string $provider,string $issuer,string $subject): string {return hash('sha256',publishing_json([$provider,$issuer,$subject]));}
function wa_identity_target(string $provider,array $claims): array {
    $key=wa_identity_key($provider,$claims['iss'],$claims['sub']);$bound=comm_get('auth.identity.'.$key);
    if($bound){$u=wa_user((int)$bound['uid']);if(!$u||!in_array($bound['email'],wa_addresses($u),true))throw new WriterAuthException('This identity no longer has access.');return ['user'=>$u,'email'=>$bound['email'],'link'=>[]];}
    // Email/UPN is a candidate only. Never grant access from this mutable claim.
    $candidates=[$claims['email']??null,$claims['preferred_username']??null];
    foreach($candidates as$email)if(is_string($email)&&($u=wa_find_user(strtolower(trim($email)))))return ['user'=>$u,'email'=>strtolower(trim($email)),'link'=>['key'=>$key,'provider'=>$provider,'issuer'=>$claims['iss'],'subject'=>$claims['sub']]];
    throw new WriterAuthException('This provider account is not approved for this private workspace. Use an approved Writer identity.');
}
function wa_bind_identity(array $challenge): void {
    if($challenge['method']!=='email')throw new WriterAuthException('Verify this email before linking an identity.');$i=$challenge['link_identity'];
    comm_lock('wa-identities',static function()use($challenge,$i){
        $emailKey='auth.identity_email.'.hash('sha256',$i['provider'].'|'.$challenge['email']);$prior=comm_get($emailKey);$identity=comm_get('auth.identity.'.$i['key']);
        if($prior&&($prior['key']!==$i['key']||(int)$prior['uid']!==$challenge['uid'])||$identity&&(int)$identity['uid']!==$challenge['uid'])throw new WriterAuthException('This email is already linked to a different provider identity. Review sign-in settings.');
        comm_put('auth.identity.'.$i['key'],$i+['uid'=>$challenge['uid'],'email'=>$challenge['email'],'linked_at'=>time()]);comm_put($emailKey,['key'=>$i['key'],'uid'=>$challenge['uid']]);
    });
}
function wa_oauth_complete(string $provider,array $flow,string $code,?callable $http=null): string {
    if($code===''||strlen($code)>8000)throw new WriterAuthException('The provider did not return a valid sign-in code.');$http??='publishing_http';$p=wa_providers()[$provider];$client=publishing_client($provider);
    $r=$http('POST',$p['token'],[],$client+['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>wa_callback($provider),'code_verifier'=>$flow['verifier']],true)['data'];
    if(!is_string($r['id_token']??null))throw new WriterAuthException('The provider did not return a verified sign-in identity.');$cache=comm_get('auth.jwks.'.$provider);$keys=$cache['keys']??[];
    if(($cache['at']??0)<time()-3600){$keys=$http('GET',$p['keys'])['data'];comm_put('auth.jwks.'.$provider,['at'=>time(),'keys'=>$keys]);}
    try{$claims=wa_id_claims($provider,$r['id_token'],$keys,$client['client_id'],$flow['nonce']);}
    catch(WriterAuthException){$keys=$http('GET',$p['keys'])['data'];$claims=wa_id_claims($provider,$r['id_token'],$keys,$client['client_id'],$flow['nonce']);comm_put('auth.jwks.'.$provider,['at'=>time(),'keys'=>$keys]);}
    $target=wa_identity_target($provider,$claims);return wa_begin($target['user'],$target['email'],(bool)$flow['trust'],'login',$target['link']);
}

/** Twilio Verify holds SMS codes. The application accepts only an exact approved verification. */
function wa_sms_config(): array {return comm_get('auth.sms');}
function wa_sms_ready(): bool {$s=wa_sms_config();return !empty($s['enabled'])&&preg_match('/^AC[a-f0-9]{32}$/Di',$s['account']??'')&&preg_match('/^VA[a-f0-9]{32}$/Di',$s['service']??'')&&!empty($s['token']);}
function wa_phone(string $value): string {if(!preg_match('/^\+[1-9][0-9]{7,14}$/D',$value))throw new WriterAuthException('Use your phone number in international format, for example +254…');return $value;}
function wa_sms_request(string $action,array $body): array {
    if(!wa_sms_ready()||!in_array($action,['Verifications','VerificationCheck'],true))throw new WriterAuthException('Text verification is not configured.');$s=wa_sms_config();
    return publishing_http('POST','https://verify.twilio.com/v2/Services/'.$s['service'].'/'.$action,['Authorization: Basic '.base64_encode($s['account'].':'.$s['token'])],$body,true,true)['data'];
}
function wa_sms_start(string $phone): string {
    $phone=wa_phone($phone);$d=wa_sms_request('Verifications',['To'=>$phone,'Channel'=>'sms']);if(($d['status']??'')!=='pending'||($d['to']??'')!==$phone||($d['service_sid']??'')!==(wa_sms_config()['service']??'')||!preg_match('/^VE[a-f0-9]{32}$/Di',$d['sid']??''))throw new WriterAuthException('The text provider did not acknowledge verification.');return $d['sid'];
}
function wa_sms_check(string $phone,string $sid,string $code): bool {
    if(!preg_match('/^[0-9]{4,10}$/D',$code)||!preg_match('/^VE[a-f0-9]{32}$/Di',$sid))return false;$d=wa_sms_request('VerificationCheck',['VerificationSid'=>$sid,'Code'=>$code]);return ($d['status']??'')==='approved'&&($d['sid']??'')===$sid&&($d['to']??'')===$phone&&($d['service_sid']??'')===(wa_sms_config()['service']??'');
}
