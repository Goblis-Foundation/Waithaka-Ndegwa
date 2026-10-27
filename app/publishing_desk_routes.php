<?php
declare(strict_types=1);
function desk_render(string $view,array $data,string $title,string $path,bool $private=false): void {
    if($private)private_response_headers();
    render('desk/'.$view,$data+['page'=>'opportunities','privatePage'=>$private,'suppressAnalytics'=>$private,'seo'=>['title'=>$title.' · Waithaka Ndegwa','description'=>'Opportunities, practical information and clearly disclosed partner features.','canonical'=>url($path),'robots'=>$private?'noindex,nofollow':'index,follow']]);
}
function desk_private(string $id): array {
    $r=desk_get($id);$access=$_SESSION['desk_client'][$id]??[];
    if(($access['until']??0)<time()||!hash_equals($r['client']['email']??'',(string)($access['email']??'')))throw new InvalidArgumentException('Open the current private link sent to the client email address.');return $r;
}
function desk_failure(Throwable $e): string {
    if($e instanceof InvalidArgumentException){http_response_code(422);return $e->getMessage();}
    http_response_code(503);error_log('Publishing desk failure: '.get_class($e));return 'This action could not be confirmed. Your saved work remains available. Check its current status before retrying.';
}
function desk_native_guard(string $path): bool {
    if($path==='/write/save'&&is_post()){
        $r=desk_post_record((int)($_POST['id']??0));
        if($r){
            $uid=desk_owner();verify_csrf();$autosave=!empty($_POST['autosave']);
            if(!$autosave&&in_array($_POST['action']??'',['publish','publish_weekly'],true)){flash('error','Publish this article through Opportunities & features so its approval and commercial safeguards are checked.');redirect(url('/write/desk/'.$r['id']));}
            try{$saved=comm_lock('desk.catalog',function()use($uid,$autosave){$image=null;if(!$autosave&&!empty($_FILES['featured_image_file']['name']))$image=upload_featured_image($_FILES['featured_image_file']);return writer_save_post($_POST,$uid,false,$image);});
                if($autosave)json_response(['ok'=>true,'id'=>$saved['id'],'version'=>$saved['version']]);flash('success','Private draft saved. Return to Publishing desk to review and release it.');redirect(url('/write/edit/'.$saved['id']));
            }catch(Throwable $e){if($autosave)json_response(['ok'=>false,'error'=>$e instanceof WriterConflict?$e->getMessage():'Draft save could not be confirmed. Reload the saved version before retrying.','conflict'=>$e instanceof WriterConflict],$e instanceof WriterConflict?409:422);flash('error',desk_failure($e));redirect(url('/write/edit/'.$r['post_id']));}
        }
    }
    if(preg_match('~^/write/preview/([1-9][0-9]*)$~D',$path,$m)){
        $r=desk_post_record((int)$m[1]);if($r){desk_owner();desk_render('article',['record'=>$r,'post'=>writer_post_by_id((int)$r['post_id']),'preview'=>true],$r['fields']['title'],$path,true);return true;}
    }
    if(preg_match('~^/my-thoughts/([a-z0-9-]+)$~D',$path,$m)){
        $q=db()->prepare('SELECT id FROM posts WHERE slug=?');$q->execute([$m[1]]);$r=desk_post_record((int)$q->fetchColumn());if($r){if(empty($r['public'])||empty($r['public_visible'])||$r['state']==='withdrawn'){http_response_code(404);return true;}redirect(url(desk_path($r)),301);}
    }
    if(!is_post()||!str_starts_with($path,'/write/publishing'))return false;
    $pid=0;
    if(in_array($path,['/write/publishing/save','/write/publishing/prepare'],true))$pid=(int)($_POST['id']??0);
    elseif(preg_match('~^/write/publishing/([1-9][0-9]*)/(approve|schedule)$~D',$path,$m))$pid=(int)$m[1];
    if(!$pid&&in_array($path,['/write/publishing/save','/write/publishing/prepare'],true)){
        if($r=desk_post_record((int)($_POST['source_post_id']??0))){desk_owner();verify_csrf();flash('error','Prepare this article’s channel drafts in Publishing desk first.');redirect(url('/write/desk/'.$r['id']));}return false;
    }
    if(preg_match('~^/write/publishing/jobs/([1-9][0-9]*)/retry$~D',$path,$retry)){
        $q=db()->prepare('SELECT v.package_id FROM publishing_jobs j JOIN publishing_revisions v ON v.id=j.revision_id WHERE j.id=?');$q->execute([(int)$retry[1]]);$map=comm_get('desk.package.'.(int)$q->fetchColumn());
        if(!empty($map['order'])){desk_owner();verify_csrf();flash('error','Paid delivery retries need a reviewed campaign schedule. Resolve the provider outcome, then arrange replacement slots or credit through Publishing desk.');redirect(url('/write/desk/'.$map['record']));}
    }
    if(!$pid)return false;$map=comm_get('desk.package.'.$pid);$p=publishing_package($pid);$r=$map?desk_get($map['record']):($p?desk_post_record((int)$p['source_post_id']):null);
    if(!$r)return false;desk_owner();verify_csrf();
    try{
        if(!$map||!empty($map['retired']))throw new InvalidArgumentException('Prepare the current source through Publishing desk before delivery.');desk_assert_publish($r,!empty($map['order'])?desk_order($map['order']):null);
        if($r['state']!=='published')throw new InvalidArgumentException('Publish the approved article first.');
        if(in_array($path,['/write/publishing/save','/write/publishing/prepare'],true)){
            if((int)($_POST['source_post_id']??0)!==(int)$r['post_id']||($_POST['source_url']??'')!==url(desk_path($r)))throw new InvalidArgumentException('Keep the linked article and canonical address unchanged.');
            foreach($_POST['variants']??[]as$slug=>$v)if(!empty($v['enabled']))desk_check_variant($r,$v,(string)$slug);
        }else{
            $draft=publishing_decode($p['draft_json']);foreach($draft['variants']as$slug=>$v)if(!empty($v['enabled']))desk_check_variant($r,$v,$slug);
        }
        if(str_ends_with($path,'/schedule')){
            if(!empty($map['order']))throw new InvalidArgumentException('Schedule paid rounds together from the accepted campaign order, not as an extra standalone post.');
            if(($_POST['schedule_mode']??'')!=='weekly'&&desk_closed($r,desk_date($_POST['scheduled_at']??'','Africa/Nairobi','release time',true)))throw new InvalidArgumentException('The social post would be after the opportunity closes.');
        }
    }catch(Throwable $e){flash('error',desk_failure($e));redirect(url('/write/desk/'.$r['id']));}
    return false;
}
function desk_dispatch(string $path): bool {
    if(ux_dispatch($path)||commercial_dispatch($path))return true;
    if(desk_native_guard($path))return true;
    if($path==='/publishing-sitemap.xml'){journeys_method(['GET','HEAD']);header('Content-Type: application/xml; charset=utf-8');echo sitemap_xml(desk_sitemap());return true;}
    if($path==='/robots.txt'){journeys_method(['GET','HEAD']);header('Content-Type: text/plain; charset=utf-8');echo "User-agent: *\nAllow: /\nDisallow: /write\nDisallow: /install\nDisallow: /app\nDisallow: /storage\nDisallow: /client/\nDisallow: /payments/\nDisallow: /consent/\nDisallow: /webhooks/\nDisallow: /newsletter/confirm\nDisallow: /newsletter/unsubscribe\nDisallow: /newsletter/preferences\nDisallow: /founders/follow-up\nDisallow: /opportunities/review\nDisallow: /opportunities/evidence\n\nSitemap: ".url('/sitemap.xml')."\nSitemap: ".url('/publishing-sitemap.xml')."\n";return true;}
    if($path==='/opportunities'||$path==='/partner-stories'){
        journeys_method(['GET','HEAD']);$kind=$path==='/opportunities'?'opportunity':'sponsored';$items=desk_public_list($kind);
        $filters=[];foreach(['q','type','country','sector','status']as$key)$filters[$key]=is_string($_GET[$key]??null)?mb_substr(trim($_GET[$key]),0,120):'';
        $items=array_values(array_filter($items,static function($r)use($filters){$f=$r['public']['fields'];$closed=desk_closed(['state'=>$r['state'],'kind'=>$r['kind'],'fields'=>$f]);
            if($filters['q']!==''&&mb_stripos($f['title'].' '.$f['organisation'].' '.$f['summary'],$filters['q'])===false)return false;
            foreach(['type'=>'type','country'=>'countries','sector'=>'sectors']as$a=>$b)if($filters[$a]!==''&&mb_stripos((string)($f[$b]??''),$filters[$a])===false)return false;
            if($filters['status']==='closed')return $closed;if($filters['status']==='all')return true;if($closed)return false;
            if($filters['status']==='soon')return isset($f['deadline'])&&$f['deadline']<=time()+14*86400;return true;
        }));
        $total=count($items);$page=archive_page_number($_GET['page']??null);if($page===null||$page>max(1,(int)ceil($total/18))){http_response_code(404);return true;}$pageNumber=$page;$items=array_slice($items,($pageNumber-1)*18,18);
        $jobs=$kind==='opportunity'&&in_array($filters['type'],['','jobs'],true)?opportunity_public_list():[];
        desk_render('index',compact('kind','items','filters','pageNumber','total','jobs'),$kind==='opportunity'?'Opportunities':'Partner stories',$path);return true;
    }
    if($path==='/promote'){
        journeys_method(['GET','HEAD','POST']);$error='';$received=false;$values=[];
        if(is_post()){
            verify_csrf();$values=array_filter($_POST,'is_scalar');
            try{
                $nonce=desk_text($_POST['form_nonce']??'','Form',64,true);$forms=$_SESSION['desk_forms']??[];
                if(!isset($forms[$nonce])||$forms[$nonce]<time()-7200)throw new InvalidArgumentException('This form expired or was already submitted.');
                if(!empty($_POST['website_repeat'])){$received=true;unset($_SESSION['desk_forms'][$nonce]);}
                else{
                    commercial_intake_values($_POST);$client=desk_client($_POST);if(($_POST['privacy_ack']??'')!=='1'||($_POST['authority_ack']??'')!=='1')throw new InvalidArgumentException('Confirm permission to submit this business brief and use these contact details.');
                    if(!journeys_rate_limit('desk-intake',ip_hash(),5,3600)||!journeys_rate_limit('desk-client',$client['email'],3,86400))throw new InvalidArgumentException('Please wait before submitting another brief.');
                    $uid=(int)(comm_config()['owner']??0);if(!$uid)throw new RuntimeException('Publishing owner is not configured.');
                    $r=comm_lock('desk.catalog',function()use($client,$uid){if(count(desk_all())>=2000)throw new InvalidArgumentException('Please contact the editor before sending another brief.');$r=desk_new($_POST,'sponsored',$uid,$client);$r['state']='submitted';$r['requested_package']=desk_choice($_POST['package']??'single',desk_packages(),'package');return desk_store($r,'client_submitted','client');});
                    unset($_SESSION['desk_forms'][$nonce]);$received=true;
                    try{desk_send_client($r,'Your publishing brief is saved');}catch(Throwable){error_log('Client intake saved; notification needs review.');}
                    if(($_FILES['evidence']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)try{desk_attach_evidence($r['id'],$_POST,$_FILES['evidence'],'client');}catch(Throwable){$error='The brief was saved, but its attachment could not be stored. Add it through the private workspace.';}
                }
            }catch(Throwable $e){$error=desk_failure($e);}
        }
        $nonce=bin2hex(random_bytes(16));$forms=array_filter($_SESSION['desk_forms']??[],static fn($at)=>$at>time()-7200);$forms[$nonce]=time();$_SESSION['desk_forms']=array_slice($forms,-10,null,true);
        desk_render('promote',compact('values','nonce','received','error'),'Promote your business',$path,true);return true;
    }
    if(preg_match('~^/(opportunities|partner-stories)/([a-z0-9]+(?:-[a-z0-9]+)+)$~D',$path,$m)){
        journeys_method(['GET','HEAD']);$r=null;foreach(desk_all()as$candidate)if(desk_path($candidate)===$path){$r=$candidate;break;}
        if(!$r||empty($r['public'])||empty($r['public_visible'])||$r['state']==='withdrawn'){http_response_code(404);desk_render('message',['heading'=>'This feature is unavailable.','message'=>'It may be private, withdrawn or not yet published.'],'Unavailable',$path,true);return true;}
        $r['fields']=$r['public']['fields'];$post=$r['public']['article'];$schema=[['@context'=>'https://schema.org','@type'=>'Article','headline'=>$post['title'],'description'=>$post['standfirst'],'url'=>url($path),'datePublished'=>gmdate('c',$r['published_at']),'dateModified'=>gmdate('c',$r['public']['at']),'author'=>['@type'=>'Person','name'=>'Waithaka Ndegwa']]];
        if($r['fields']['sponsored'])$schema[0]['sponsor']=['@type'=>'Organization','name'=>$r['fields']['sponsor_name']];
        desk_render('article',['record'=>$r,'post'=>$post,'preview'=>false,'schema'=>$schema],$post['title'],$path);return true;
    }
    if(str_starts_with($path,'/payments/commercial/'))return desk_payment_dispatch($path);
    if(preg_match('~^/client/access/([a-f0-9]{64})$~D',$path,$m)){
        journeys_method(['GET','HEAD']);private_response_headers();$a=comm_get('desk.access.'.hash('sha256',$m[1]));
        if(!$a||$a['expires']<=time()){http_response_code(410);desk_render('message',['heading'=>'This private link expired.','message'=>'Ask the editor to send a fresh client-workspace link.'],'Private workspace',$path,true);return true;}
        $r=desk_get($a['record']);if(!hash_equals($r['client']['email']??'',$a['email'])){http_response_code(410);return true;}
        if(($_SERVER['REQUEST_METHOD']??'GET')==='HEAD')return true;
        session_regenerate_id(true);$_SESSION['desk_client'][$r['id']]=['email'=>$a['email'],'until'=>min($a['expires'],time()+12*3600)];redirect(url('/client/workspace/'.$r['id']));
    }
    if(preg_match('~^/client/workspace/([a-f0-9]{32})(?:/evidence/([a-f0-9]{32}))?$~D',$path,$m)){
        journeys_method(['GET','HEAD','POST']);private_response_headers();$error='';
        try{$r=desk_private($m[1]);}catch(Throwable){http_response_code(403);desk_render('message',['heading'=>'Private client workspace','message'=>'Open the private link sent to the client email address.'],'Client workspace',$path,true);return true;}
        if(!empty($m[2])){$doc=opportunity_evidence_get($r,$m[2]);if(!$doc){http_response_code(404);return true;}opportunity_evidence_serve($doc);return true;}
        if(is_post()){
            verify_csrf();try{
                $action=$_POST['action']??'';$email=$r['client']['email'];
                if($action==='checkout'){$o=desk_order((string)($_POST['order']??''));if($o['record']!==$r['id'])throw new InvalidArgumentException('Order unavailable.');redirect(desk_checkout($o['id'],(string)($_POST['provider']??''),$email));}
                elseif(in_array($action,['refresh_payment','capture_paypal'],true)){$o=desk_order((string)($_POST['order']??''));if($o['record']!==$r['id'])throw new InvalidArgumentException('Order unavailable.');if(!journeys_rate_limit('desk-check',$o['id'],12,3600))throw new InvalidArgumentException('Check again later.');desk_reconcile($o['id'],$action==='capture_paypal');}
                elseif($action==='accept_schedule'){$o=desk_order((string)($_POST['order']??''));if($o['record']!==$r['id']||($_POST['schedule_ack']??'')!=='1')throw new InvalidArgumentException('Confirm this record’s replacement schedule.');desk_accept_schedule($o['id'],$email,(string)($_POST['schedule_hash']??''));}
                elseif($action==='evidence')desk_attach_evidence($r['id'],$_POST,$_FILES['evidence']??null,'client');
                elseif($action==='renewal_request')comm_lock('desk.catalog',function()use($r){$x=desk_get($r['id']);$x['renewal_requested']=time();desk_store($x,'renewal_requested','client');});
                else desk_client_action($r['id'],$_POST,$email);
                flash('success','Your request was saved. Payment, approval and publication are tracked separately.');redirect(url('/client/workspace/'.$r['id']));
            }catch(Throwable $e){$error=desk_failure($e);}
        }
        $r=desk_get($r['id']);$post=writer_post_by_id((int)$r['post_id']);$orders=array_map('desk_order',$r['orders']);$report=desk_campaign_report($r);
        desk_render('client',compact('r','post','orders','report','error'),'Client workspace',$path,true);return true;
    }
    if(!str_starts_with($path,'/write/desk'))return false;
    require_auth();private_response_headers();journeys_method(['GET','HEAD','POST']);$error='';
    try{$uid=desk_owner();}catch(Throwable){http_response_code(403);echo 'Owner access required.';return true;}
    if($path==='/write/desk/settings'){
        if(is_post()){verify_csrf();try{if(($_POST['action']??'')==='register_pesapal')desk_register_pesapal();else desk_billing_save($_POST,$uid);flash('success','Commercial configuration saved. Existing contribution settings are unchanged.');redirect(url($path));}catch(Throwable $e){$error=desk_failure($e);}}
        render_write('desk_settings',['writeTitle'=>'Commercial publishing settings','cfg'=>desk_config(),'error'=>$error]);return true;
    }
    if($path==='/write/desk'||$path==='/write/desk/orders'){
        journeys_method(['GET','HEAD']);$items=desk_all();usort($items,static fn($a,$b)=>$b['updated']<=>$a['updated']);$orders=comm_records('desk.order',3000);usort($orders,static fn($a,$b)=>$b['created']<=>$a['created']);render_write('desk_index',['writeTitle'=>'Opportunities & sponsored publishing','items'=>$items,'orders'=>$orders,'billing'=>$path==='/write/desk/orders']);return true;
    }
    if(preg_match('~^/write/desk/(opportunity|sponsored)/new$~D',$path,$m)){
        $kind=$m[1];$values=['timezone'=>'Africa/Nairobi','currency'=>'KES','type'=>'grant','deadline_mode'=>'unknown'];
        if(is_post()){verify_csrf();$values=array_filter($_POST,'is_scalar');try{$r=comm_lock('desk.catalog',function()use($kind,$uid){return desk_new($_POST,$kind,$uid,!empty($_POST['client_email'])?desk_client($_POST):null);});redirect(url('/write/desk/'.$r['id']));}catch(Throwable $e){$error=desk_failure($e);}}
        render_write('desk_edit',['writeTitle'=>'New '.($kind==='opportunity'?'opportunity':'sponsored feature'),'kind'=>$kind,'values'=>$values,'record'=>null,'error'=>$error,'orders'=>[],'post'=>null,'report'=>[]]);return true;
    }
    if(preg_match('~^/write/desk/([a-f0-9]{32})(?:/(creative|evidence)(?:/([a-f0-9]{32}))?)?$~D',$path,$m)){
        try{$r=desk_get($m[1]);}catch(Throwable){http_response_code(404);return true;}
        if(($m[2]??'')==='evidence'&&!empty($m[3])){$doc=opportunity_evidence_get($r,$m[3]);if(!$doc){http_response_code(404);return true;}opportunity_evidence_serve($doc);return true;}
        if(is_post()){
            verify_csrf();try{
                if(($m[2]??'')==='creative'){$asset=desk_save_creative($r['id'],$_POST,$_FILES['banner']??[],$uid);json_response(['ok'=>true,'asset'=>$asset],200);}
                $action=$_POST['action']??'save';
                if($action==='quote')desk_quote($r['id'],$_POST,$uid);
                elseif($action==='ai_background')desk_generate_background($r['id'],$_POST,$uid);
                elseif($action==='import_source')desk_import_source($r['id'],$_POST,$uid);
                elseif($action==='evidence')desk_attach_evidence($r['id'],$_POST,$_FILES['evidence']??null,(string)$uid);
                elseif($action==='organic')desk_prepare_organic($r['id'],$_POST,$uid);
                elseif(in_array($action,['prepare_campaign','schedule_campaign','propose_schedule','recover_payment','reconcile','bank_verify','pause','resume','settlement'],true)){
                    $oid=(string)($_POST['order']??'');$o=desk_order($oid);if($o['record']!==$r['id'])throw new InvalidArgumentException('Order does not belong to this feature.');
                    if($action==='propose_schedule')desk_propose_schedule($oid,$_POST,$uid);
                    elseif($action==='recover_payment')desk_recover_payment($oid,$_POST,$uid);
                    elseif($action==='prepare_campaign')desk_prepare_campaign($r['id'],$oid,$uid);
                    elseif($action==='schedule_campaign')desk_schedule_campaign($oid,$uid);
                    elseif($action==='reconcile')desk_reconcile($oid);
                    elseif($action==='bank_verify')desk_bank_verify($oid,$_POST,$uid);
                    else desk_order_adjust($oid,$_POST,$uid);
                }else desk_update($r['id'],$_POST,$uid);
                flash('success','Saved. Review the current status, approvals and delivery evidence below.');redirect(url('/write/desk/'.$r['id']));
            }catch(Throwable $e){$error=desk_failure($e);if(($m[2]??'')==='creative')json_response(['ok'=>false,'error'=>$error],422);}
        }
        $r=desk_get($r['id']);$values=desk_edit_fields($r['fields']);$post=writer_post_by_id((int)$r['post_id']);$orders=array_map('desk_order',$r['orders']);$report=desk_campaign_report($r);
        render_write('desk_edit',['writeTitle'=>$r['fields']['title'],'kind'=>$r['kind'],'values'=>$values,'record'=>$r,'post'=>$post,'orders'=>$orders,'report'=>$report,'error'=>$error]);return true;
    }
    http_response_code(404);echo 'Publishing desk page not found.';return true;
}
function desk_payment_dispatch(string $path): bool {
    private_response_headers();
    if($path==='/payments/commercial/pesapal/ipn'){
        journeys_method(['GET','HEAD']);if(($_SERVER['REQUEST_METHOD']??'')==='HEAD')return true;
        $id=is_string($_GET['OrderMerchantReference']??null)?$_GET['OrderMerchantReference']:'';$tracking=is_string($_GET['OrderTrackingId']??null)?$_GET['OrderTrackingId']:'';
        if($id==='')json_response(['ok'=>true,'payments_confirmed'=>false],200);
        try{
            $o=desk_order($id);if(($o['payment']['provider']??'')!=='pesapal'||empty($o['payment']['remote'])||!hash_equals($o['payment']['remote'],$tracking))throw new InvalidArgumentException('Unknown provider order.');
            if(!journeys_rate_limit('desk-ipn',$id,30,3600))throw new RuntimeException('Rate limited.');desk_reconcile($id);
            json_response(['orderNotificationType'=>'IPNCHANGE','orderTrackingId'=>$tracking,'orderMerchantReference'=>$id,'status'=>200],200);
        }catch(Throwable){json_response(['status'=>500],503);}
    }
    if($path==='/payments/commercial/return'){
        journeys_method(['GET','HEAD']);$id=is_string($_GET['order']??null)?$_GET['order']:'';
        try{$o=desk_order($id);desk_private($o['record']);redirect(url('/client/workspace/'.$o['record']));}catch(Throwable){desk_render('message',['heading'=>'Return to your private client workspace','message'=>'Open the private link in your email to check payment status. A checkout return is not proof of payment.'],'Payment status',$path,true);return true;}
    }
    http_response_code(404);return true;
}
