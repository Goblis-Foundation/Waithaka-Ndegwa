<?php
declare(strict_types=1);

function discovery_dispatch(string $path): bool {
    if($path==='/search') {
        journeys_method(['GET','HEAD']);
        try{$query=discovery_query($_GET['q']??'');}catch(DiscoveryValidationException){journeys_not_found();}
        $page=archive_page_number($_GET['page']??null);$type=$_GET['type']??'all';
        if($page===null || !is_string($type))journeys_not_found();
        $search=discovery_search($query,$page,$type);if($search===null)journeys_not_found();
        header('X-Robots-Tag: noindex, follow');
        if(strtoupper($_SERVER['REQUEST_METHOD']??'GET')==='HEAD')return true;
        render('search',$search+['page'=>'search','categories'=>categories(),'suppressAnalytics'=>true,
            'seo'=>['title'=>($query!==''?'Search: '.$query:'Search').' · Waithaka Ndegwa','description'=>'Search published writing, Startup Journeys, founder stories, resources and videos.',
                'canonical'=>url($search['pagination']['path']),'robots'=>'noindex,follow']]);return true;
    }
    if(preg_match('~\A/([a-zA-Z0-9-]{8,128})\.txt\z~',$path,$match)) {
        $settings=discovery_indexnow_settings();
        if(!$settings['enabled'] || $settings['key']==='' || !hash_equals($settings['key'],$match[1]))return false;
        journeys_method(['GET','HEAD']);header('Content-Type: text/plain; charset=utf-8');header('X-Robots-Tag: noindex');header('Cache-Control: public, max-age=300');
        if(strtoupper($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $settings['key'];return true;
    }
    if($path!=='/write/discovery' && !str_starts_with($path,'/write/discovery/'))return false;
    require_auth();journeys_private_headers();
    $allowed=['/write/discovery','/write/discovery/report','/write/discovery/indexnow','/write/discovery/reconcile','/write/discovery/retry'];
    if(!in_array($path,$allowed,true))journeys_not_found();
    if($path==='/write/discovery') {
        journeys_method(['GET','HEAD']);
        $days=$_GET['days']??'28';if(!is_string($days) || !in_array($days,['28','90'],true))journeys_not_found();
        $page=archive_page_number($_GET['page']??null);$total=(int)db()->query('SELECT COUNT(*) FROM discovery_indexnow_queue')->fetchColumn();
        $pagination=archive_pagination($page,$total,30,'/write/discovery',['days'=>$days]);if($pagination===null)journeys_not_found();
        if(strtoupper($_SERVER['REQUEST_METHOD']??'GET')==='HEAD')return true;
        $notifications=db()->query('SELECT id,canonical_url,event_type,status,attempt_count,available_at,attempted_at,accepted_at,last_http_status,error_code FROM discovery_indexnow_queue ORDER BY id DESC LIMIT 30 OFFSET '.$pagination['offset'])->fetchAll();
        $receipts=db()->query('SELECT id,queue_id,attempt_number,result,http_status,error_code,started_at,finished_at FROM discovery_indexnow_attempts ORDER BY id DESC LIMIT 20')->fetchAll();
        render_write('discovery',['writeTitle'=>'Discovery and reports','days'=>(int)$days,'report'=>discovery_gsc_latest((int)$days),
            'settings'=>discovery_indexnow_settings(),'notifications'=>$notifications,'pagination'=>$pagination,'receipts'=>$receipts,'exceptions'=>discovery_operations_summary()]);return true;
    }
    journeys_method(['POST']);journeys_verify_csrf();$user=current_user();
    if(!journeys_rate_limit('discovery-writer',(string)$user['id'],30,3600)){http_response_code(429);header('Retry-After: 3600');echo 'Please wait before making another discovery change.';return true;}
    $return='/write/discovery';
    try {
        if($path==='/write/discovery/report') {
            $raw=$_POST['days']??'';if(!is_string($raw)||!in_array($raw,['28','90'],true))throw new DiscoveryValidationException('Choose a 28-day or 90-day comparison.');
            if(!journeys_rate_limit('discovery-gsc',(string)$user['id'],4,3600)){http_response_code(429);header('Retry-After: 3600');echo 'Please wait before requesting another Search Console report.';return true;}
            discovery_gsc_refresh((int)$raw,(int)$user['id']);$return.='?days='.$raw;flash('success','Search Console returned a new report. The report shows its property, dates and reporting limits.');
        }elseif($path==='/write/discovery/indexnow') {
            discovery_indexnow_save(journeys_checked($_POST['enabled']??null),journeys_checked($_POST['rotate']??null),(int)$user['id']);
            flash('success','IndexNow preferences saved. Sending also requires the environment gate and the authenticated worker.');
        }elseif($path==='/write/discovery/reconcile') {
            $stats=discovery_sync_public_urls();flash('success','Public URL inventory checked: '.$stats['added'].' added, '.$stats['updated'].' updated, '.$stats['deleted'].' removed. No notification was sent by this page.');
        }else {
            $id=journeys_id($_POST['id']??'','notification');discovery_retry_notification($id,(int)$user['id']);flash('success','The latest eligible notification is queued for the worker. Earlier attempt receipts are preserved.');
        }
    }catch(DiscoveryValidationException $exception){flash('error',$exception->getMessage());}
    catch(Throwable){flash('error','The discovery action could not be completed. Review the private application log and try again.');}
    redirect(url($return));
}
