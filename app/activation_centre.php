<?php
declare(strict_types=1);

/**
 * Owner-only, read-only activation register. Local saved state is NOT a live
 * provider response, an inbox receipt, settlement, an approved source report
 * or a completed physical-device test.
 */
function activation_definitions(): array {
    return [
        70=>['title'=>'Personal Meta & WhatsApp','path'=>'/write/connections#integrations-meta','action'=>'Review Meta connections','next'=>'Authorize the personal Facebook identity through its own Login app; separately verify WhatsApp Business sender, coexistence, webhook and template rights. Never substitute a Page or company portfolio.'],
        71=>['title'=>'Social & community publishing','path'=>'/write/connections#integrations-social','action'=>'Review personal channels','next'=>'Verify each exact personal profile, scopes and direct-post product entitlement. Configure Threads, Telegram, Discord and Signal in Communities; confirm the exact Facebook Connect subscriber destination. Assisted completion is not a provider-verified receipt. Approve every real post and record the provider receipt; paid API access requires a separate spending decision.'],
        72=>['title'=>'Giving & settlements','path'=>'/write/connections#integrations-finance','action'=>'Review receiving providers','next'=>'Verify the live merchant, signed callbacks, an expressly approved real settlement and recurring-mandate/cancellation evidence. Card/PayPal checkout, bank giving and paid editorial services stay separate.'],
        73=>['title'=>'Monthly newsletter & delivery','path'=>'/write/newsletter/sender','action'=>'Complete newsletter sender setup','next'=>'Complete the approved sender postal details, confirm the exact receiving mailbox, test inbox delivery and suppression before any subscription send. Review queued messages individually; no historical bulk release.'],
        74=>['title'=>'Goal 2030 approved impact','path'=>'/write/impact','action'=>'Review source-owned reports','next'=>'Confirm each source-owned endpoint and approved closed-month report with correction/withdrawal evidence. Never publish targets as progress, combine corroborating counts or replace missing values with zero.'],
        75=>['title'=>'Google & Microsoft productivity','path'=>'/write/google#calendars','action'=>'Review calendar accounts','next'=>'Use isaac@goblisfoundation.org as the canonical account; isaac@afritracelink.com is its alias, not a separate grant. Review the canonical returned calendars, then verify permitted account-specific Workspace/Microsoft operations with an actual read-back.'],
        76=>['title'=>'Live experience & devices','path'=>'/write/app','action'=>'Review device enrollment','next'=>'Perform real phone/tablet, assistive-technology, passkey/push and field Core Web Vitals checks. Install the activity companion and verify Health Connect source sharing/permissions and the separate Google Health/Strava account grants. Passing synthetic browser scans does not certify real-device experience.'],
    ];
}
function activation_local_facts(): array {
    $facts=['services'=>[],'finance'=>[],'sender'=>null,'impact'=>[],
        'calendars'=>null,'delivery_worker'=>null,'observed_at'=>gmdate('c')];
    // A provider error is unknown, not a disconnected account.
    foreach(['facebook_profile','linkedin','x','tiktok_read','tiktok_business','instagram','google_search_console','google_analytics','google_drive','google_tasks','google_postmaster','meetings_microsoft']as $service) {
        try {
            $row=publishing_connection($service);
            $state=$row?(string)($row['status']??'unknown'):'disconnected';
            $facts['services'][$service]=in_array($state,['connected','pending_confirmation','disconnected','reconnect'],true)?$state:'unknown';
        }catch(Throwable){$facts['services'][$service]='unknown';}
    }
    foreach(['mpesa','pesapal','paypal']as $provider) {
        try {
            $saved=function_exists('integration_hub_configured')&&integration_hub_configured($provider);
            $env=(string)setting($provider.'.environment','sandbox');
            $facts['finance'][$provider]=$saved?($env==='live'?'configured_live':'configured_nonlive'):'not_configured';
        }catch(Throwable){$facts['finance'][$provider]='unknown';}
    }
    try {
        $s=newsletter_sender();
        $facts['sender']=[
            'from_valid'=>filter_var($s['from']??'',FILTER_VALIDATE_EMAIL)!==false,
            'reply_valid'=>($s['reply_to']??'')===''||filter_var($s['reply_to'],FILTER_VALIDATE_EMAIL)!==false,
            'name_present'=>trim((string)($s['name']??''))!=='',
            'postal_present'=>trim((string)($s['postal_address']??''))!=='',
            'transport_ready'=>email_transport_ready(),
        ];
    }catch(Throwable){/* Cannot infer delivery readiness from missing data. */}
    foreach(array_keys(impact_sources())as $source) {
        try {
            $snapshot=impact_snapshot($source);
            if(($snapshot['status']??'')==='withheld'||isset($snapshot['withdrawn_report']))$state='withdrawn';
            elseif(($snapshot['status']??'')==='unavailable')$state='source_unavailable';
            elseif(($snapshot['status']??'')==='available'&&($snapshot['data']['schema_version']??null)===2&&($snapshot['data']['approved_for_publication']??null)===true)$state='approved_snapshot';
            else $state='no_approved_report';
            $facts['impact'][$source]=$state;
        }catch(Throwable){$facts['impact'][$source]='unknown';}
    }
    try {
        $owner=(int)(comm_config()['owner']??0);
        if($owner>0) {
            $connected=0;$pending=0;$other=0;
            foreach(google_calendar_accounts($owner)as $account) {
                $state=$account['status']??'';
                if($state==='connected')$connected++;
                elseif($state==='pending_confirmation')$pending++;
                else $other++;
            }
            $facts['calendars']=['connected'=>$connected,'pending'=>$pending,'other'=>$other];
        }
    }catch(Throwable){/* A failed inventory must not be shown as zero accounts. */}
    $facts['delivery_worker']=(bool)app_config('worker_enabled',false);
    return $facts;
}

/** Transform allowlisted booleans/states only. Never forward tokens, IDs, addresses or raw provider records. */
function activation_assess(array $facts): array {
    $services=is_array($facts['services']??null)?$facts['services']:[];
    $finance=is_array($facts['finance']??null)?$facts['finance']:[];
    $impact=is_array($facts['impact']??null)?$facts['impact']:[];
    $sender=is_array($facts['sender']??null)?$facts['sender']:null;
    $calendar=is_array($facts['calendars']??null)?$facts['calendars']:null;
    $known=static fn(string $s):bool=>isset($services[$s])&&in_array($services[$s],['connected','pending_confirmation','disconnected','reconnect'],true);
    $social=['linkedin','x','tiktok_read'];
    $socialLinked=array_reduce($social,static fn(int $total,string $k):int=>$total+(($services[$k]??'')==='connected'?1:0),0);
    $knownSocial=count(array_filter($social,$known));
    $financeLive=count(array_filter(['mpesa','pesapal','paypal'],static fn(string $p):bool=>($finance[$p]??'')==='configured_live'));
    $knownFinance=count(array_filter(['mpesa','pesapal','paypal'],static fn(string $p):bool=>isset($finance[$p])&&$finance[$p]!=='unknown'));
    $approved=count(array_filter(array_values($impact),static fn(string $v):bool=>$v==='approved_snapshot'));
    $knownImpact=count(array_filter(array_values($impact),static fn(string $v):bool=>$v!=='unknown'));
    $meta=($services['facebook_profile']??'unknown')==='connected'
        ? 'Personal Facebook identity is saved. This does not verify WhatsApp Business or profile posting rights.'
        : (($services['facebook_profile']??'unknown')==='unknown'?'Personal Meta authorization could not be checked locally.':'Personal Facebook identity is not confirmed in Writer.');
    $network=$knownSocial===count($social)
        ? $socialLinked.'/3 personal service connections are saved; live posting permissions still need verification.'
        : 'Personal service inventory unavailable or incomplete; confirm all three exact accounts.';
    $money=$knownFinance===3
        ? $financeLive.'/3 payment providers have credentials marked for live use; settlement remains unverified.'
        : 'Payment configuration inventory incomplete. Never treat an unknown merchant status as active.';
    $mail=$sender===null?'Sender and delivery settings are unavailable for review.'
        : ((!empty($sender['from_valid'])&&!empty($sender['reply_valid'])&&!empty($sender['name_present'])&&!empty($sender['postal_present'])&&!empty($sender['transport_ready']))
          ? 'Sender fields and transport are configured. Inbox delivery and subscriber release remain unverified.'
          : 'Sender, mailing-address or transport prerequisites need review; do not release subscriber mail.');
    $impactText=$knownImpact===count(impact_sources())&&$knownImpact>0
        ? $approved.'/4 sources have a locally accepted approved snapshot; freshness and publication still require verification.'
        : 'Source-report inventory incomplete; no public progress is inferred from missing status.';
    $cal=$calendar===null?'Selected calendar inventory unavailable. Reauthorization and scoped Microsoft access still require review.'
        : max(0,(int)($calendar['connected']??0)).' saved connected Google calendar account(s), '.max(0,(int)($calendar['pending']??0)).' awaiting confirmation; actual token renewal and Microsoft permissions are separate.';
    $messages=[
        70=>$meta,71=>$network,72=>$money,73=>$mail,
        74=>$impactText,75=>$cal,
        76=>'Automated production browser checks are installed. Physical-device and field-performance acceptance remain outstanding. Activity companion installation, Health Connect permissions and actual Google Health/Strava consent also need their own acceptance.'
    ];
    $rows=[];
    foreach(activation_definitions()as $number=>$definition) {
        $rows[]=$definition+['issue'=>$number,'observed'=>$messages[$number],
            'acceptance'=>'Open until independently evidenced'];
    }
    return $rows;
}
/** Allowlisted states only: no profile IDs, private labels, tokens or activity records leave inventory. */
function activation_connection_state(?array $row,int $owner,string $service): string {
    if(!$row||($row['status']??'')==='disconnected')return 'disconnected';
    if($owner<1||(int)($row['connected_by']??0)!==$owner)return 'other_owner';
    if(($row['status']??'')!=='connected')return in_array($row['status']??'',['pending_confirmation','reconnect'],true)?$row['status']:'unknown';
    if(empty($row['selected_resource']))return 'pending_confirmation';
    $metadata=json_decode((string)($row['metadata_json']??'{}'),true,32,JSON_THROW_ON_ERROR);
    if(!empty($metadata['owner_declared']))return 'assisted';
    if($service==='signal')return 'assisted';
    if($service==='threads'){$scopes=json_decode((string)($row['scopes_json']??'[]'),true,32,JSON_THROW_ON_ERROR);if(!is_array($scopes)||array_diff(['threads_basic','threads_content_publish'],$scopes))return 'permissions_missing';}
    return 'connected';
}
function activation_activity_state(int $owner,string $source,array $grant,array $client): string {
    if($owner<1||!$grant||($grant['status']??'')==='disconnected')return 'disconnected';
    if(($grant['owner']??0)!==$owner)return 'other_owner';
    if(empty($client['client_id'])||empty($client['client_secret'])||($source==='google_health'&&($client['dedicated_project']??false)!==true)||!isset($grant['client'])||!hash_equals((string)$grant['client'],fitness_client_fingerprint($client)))return 'reconnect';
    return in_array($grant['status']??'',['connected','pending_confirmation','reconnect'],true)?$grant['status']:'unknown';
}
function activation_connection_inventory(): array {
    $owner=(int)(comm_config()['owner']??0);$states=[];
    foreach(['threads','telegram','discord','signal']as$service){try{$states[$service]=activation_connection_state(publishing_connection($service),$owner,$service);}catch(Throwable){$states[$service]='unknown';}}
    foreach(['google_health','strava']as$source){try{$states[$source]=activation_activity_state($owner,$source,comm_get(fitness_key($owner,'grant',$source)),fitness_client($source));}catch(Throwable){$states[$source]='unknown';}}
    foreach(['health_connect','samsung_health','google_fit']as$source){try{$data=comm_get(fitness_key($owner,'data',$source));$states[$source]=!$data?'not_imported':(($data['owner']??0)!==$owner?'other_owner':(is_array($data['rows']??null)?'imported':'unknown'));}catch(Throwable){$states[$source]='unknown';}}
    return $states;
}
function activation_connection_rows(array $states): array {
    $definitions=[
        'threads'=>['Threads','/write/connections#integrations-communities','Connect the separate Threads app, confirm your profile, then approve text, photo or video in the posting studio.'],
        'telegram'=>['Telegram','/write/connections#integrations-communities','Verify a bot with administrator/posting rights and confirm the exact channel or supergroup.'],
        'discord'=>['Discord','/write/connections#integrations-communities','Verify the incoming webhook and confirm its server/channel. Prepare text, links or an owned photo/video in the posting studio.'],
        'signal'=>['Signal','/write/connections#integrations-communities','Confirm the private destination label. Complete the approved post in Signal and record your private reference.'],
        'google_health'=>['Google Health','/write/activity#connections','Save a dedicated Google Health Cloud project, authorize the linked account, then confirm its returned identity.'],
        'strava'=>['Strava','/write/activity#connections','Configure the Strava application, authorize your own athlete account and confirm the returned identity.'],
        'health_connect'=>['Android Health Connect','/write/activity#imports','Install the companion, grant workout access, export a file and review it before importing.'],
        'samsung_health'=>['Samsung Health','/write/activity#imports','Share workouts with Health Connect on the phone. Select Samsung Health in the companion, then review and import the export.'],
        'google_fit'=>['Google Fit history','/write/activity#imports','Select available Google Fit workouts in the Health Connect companion or prepare the documented CSV, then review and import it.'],
    ];
    $labels=['connected'=>'Saved account confirmed','assisted'=>'Confirmed for assisted posting','pending_confirmation'=>'Confirm the returned account','reconnect'=>'Reconnect or update app setup','disconnected'=>'Connection needed','other_owner'=>'Owner connection needed','permissions_missing'=>'Publishing permission needed','unknown'=>'Status unavailable','imported'=>'Reviewed import saved','not_imported'=>'Reviewed import needed'];
    $rows=[];foreach($definitions as$key=>[$name,$path,$next]){$state=$states[$key]??'unknown';$rows[]=['key'=>$key,'name'=>$name,'status'=>$labels[$state]??$labels['unknown'],'path'=>$path,'next'=>$next];}return $rows;
}
function activation_dispatch(string $path): bool {
    if($path!=='/write/activation')return false;
    require_auth();private_response_headers();journeys_method(['GET','HEAD']);
    $user=current_user();$owner=(int)(comm_config()['owner']??0);
    if($owner<1||!$user||(int)$user['id']!==$owner){http_response_code(403);echo 'Owner access required.';return true;}
    if(($_SERVER['REQUEST_METHOD']??'GET')==='HEAD')return true;
    $facts=activation_local_facts();$rows=activation_assess($facts);$connections=activation_connection_rows(activation_connection_inventory());
    render_write('activation',['writeTitle'=>'Activation & launch readiness','activationRows'=>$rows,
        'activationObservedAt'=>$facts['observed_at'],'activationWorker'=>$facts['delivery_worker'],
        'activationConnections'=>$connections,'activationWorkAreas'=>function_exists('reconciliation_items')?count(reconciliation_items()):null]);
    return true;
}
