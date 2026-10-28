<?php
declare(strict_types=1);

function publishing_dispatch(string $path): bool {
    if (!preg_match('~^/write/(publishing|connections)(/|$)~',$path)) return false;
    require_auth();
    header('Cache-Control: no-store, private');
    header('Referrer-Policy: no-referrer');
    $userId=(int)current_user()['id'];
    $back=str_starts_with($path,'/write/connections')?'/write/connections':'/write/publishing';
    if(preg_match('~^/write/connections/(?:callback/)?(meetings_[a-z]+)(?:/(?:start|confirm|disconnect))?$~',$path,$meetingMatch)&&($meetingDefinition=meetings_definition($meetingMatch[1])))$back='/write/meetings#'.$meetingDefinition['provider'];
    try {
        if (is_post()) verify_csrf();
        if ($path==='/write/connections'&&!is_post()) {
            $connections=[];foreach(db()->query('SELECT * FROM publishing_connections')->fetchAll() as $connection)$connections[$connection['service']]=$connection;
            render_write('connections',['writeTitle'=>'Integrations','connections'=>$connections,'services'=>publishing_services(),'channels'=>publishing_channels()]);return true;
        }
        if ($path==='/write/connections/settings'&&is_post()) {
            $setup=$_POST['setup_service']??'';if(!is_string($setup)||($setup!==''&&!isset(publishing_services()[$setup])))throw new InvalidArgumentException('Choose a valid integration.');
            $providers=$setup!==''?[publishing_client_namespace($setup)]:['google','linkedin','instagram','facebook','facebook_profile','x','tiktok','tiktok_business'];
            $values=[];foreach($providers as$provider)foreach(['client_id','client_secret']as$field){$value=$_POST[$provider.'_'.$field]??'';if(!is_string($value)||strlen($value)>2000||preg_match('/[\\r\\n]/',$value))throw new InvalidArgumentException('A provider credential has an invalid format.');$value=trim($value);if($value!=='')$values['publishing.'.$provider.'.'.$field]=[$value,true];}
            if(in_array('tiktok_business',$providers,true)){$v=$_POST['tiktok_business_authorization_url']??'';if(!is_string($v)||strlen($v)>8000||preg_match('/[\\r\\n]/',$v))throw new InvalidArgumentException('Check the TikTok authorisation address.');if(trim($v)!=='')$values['publishing.tiktok_business.authorization_url']=[trim($v),true];}
            if(isset($_POST['meta_version'])){$version=$_POST['meta_version'];if(!is_string($version)||!preg_match('/^v[0-9]{2}\\.0$/D',$version))throw new InvalidArgumentException('Enter a valid Meta API version.');$values['publishing.meta.version']=[$version,false];}
            db()->beginTransaction();try{foreach($values as$key=>$value)set_setting($key,$value[0],$value[1]);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
            if($setup!==''&&($_POST['save_and_connect']??'')==='1')redirect(publishing_oauth_start($setup,$userId));
            flash('success','This provider’s setup was saved securely. Connect the account to authorise its requested features.');redirect(url($setup!==''?'/write/connections#'.$setup:$back));
        }
        if ($path==='/write/connections/buffer/connect'&&is_post()) {
            publishing_buffer_connect((string)($_POST['api_key']??''),$userId);
            flash('success','Buffer returned your account and channels. Confirm the organization below before selecting delivery destinations.');redirect(url('/write/connections#buffer'));
        }
        if (preg_match('~^/write/connections/([a-z_]+)/start$~',$path,$m)&&is_post()) redirect(publishing_oauth_start($m[1],$userId,$m[1]==='google_calendar'&&($_POST['calendar_edit']??'')==='1'));
        if (preg_match('~^/write/connections/callback/([a-z_]+)$~',$path,$m)&&!is_post()) {
            $service=$m[1];
            if(!isset(publishing_services()[$service]))throw new RuntimeException('Unknown provider callback.');
            $state=publishing_consume_state($service,(string)($_GET['state']??''),$userId);
            if(!empty($_GET['error']))throw new RuntimeException('Authorization was declined or cancelled. Existing publication drafts have not changed.');
            $code=(string)($_GET[$service==='tiktok_business'?'auth_code':'code']??'');if($code===''||strlen($code)>8000)throw new RuntimeException('The provider did not return an authorization code.');
            publishing_oauth_complete($service,$code,$state,$userId);
            flash('success','The provider returned an account. Confirm the actual destination before using this connection.');redirect(url($service==='google_booking_mail'?'/write/booking-mailboxes':($service==='google_calendar'?'/write/google#calendars':(meetings_definition($service)?'/write/meetings#'.publishing_services()[$service]['provider']:'/write/connections#'.$service))));
        }
        if (preg_match('~^/write/connections/([a-z_]+)/confirm$~',$path,$m)&&is_post()) {
            if(empty($_POST['confirm_identity']))throw new RuntimeException('Confirm that the returned account and selected destination are yours to use.');
            publishing_confirm_connection($m[1],(string)($_POST['resource']??''),$userId);
            flash('success','Account and destination confirmed. Use its authorized features below; publishing routes are selected separately.');redirect(url(meetings_definition($m[1])?'/write/meetings#'.publishing_services()[$m[1]]['provider']:'/write/connections#'.$m[1]));
        }
        if (preg_match('~^/write/connections/([a-z_]+)/disconnect$~',$path,$m)&&is_post()) {
            if(!isset(publishing_services()[$m[1]]))throw new RuntimeException('Unknown connection.');
            db()->prepare("UPDATE publishing_connections SET status='disconnected',credentials_encrypted=NULL,selected_resource=NULL,resources_json=NULL,metadata_json=NULL,scopes_json=NULL WHERE service=?")->execute([$m[1]]);
            publishing_event(null,null,'connection_removed',publishing_services()[$m[1]]['name'].' saved access removed.',$userId);
            flash('success','Saved access removed. Review any existing provider queue separately; removing a connection does not cancel posts already accepted there.');redirect(url($back));
        }
        if(preg_match('~^/write/connections/(instagram_comments|instagram_messages)/engagement$~',$path,$m)) {
            journeys_method(['GET','HEAD','POST']);private_response_headers();
            $connection=publishing_connection($m[1]);
            if(!$connection||$connection['status']!=='connected'||empty($connection['selected_resource']))throw new RuntimeException('Connect and confirm this Instagram feature before opening its desk.');
            $primary=publishing_connection('instagram');
            if($primary&&$primary['status']==='connected'&&$primary['selected_resource']!==$connection['selected_resource'])throw new RuntimeException('Instagram publishing and engagement accounts differ. Confirm the same account first.');
            $report=null;
            if(is_post()){
                if(!journeys_rate_limit('instagram-engagement',(string)$userId,12,3600))throw new RuntimeException('Please wait before requesting another engagement report.');
                $action=$_POST['action']??'list';$resource=$_POST['resource']??'';
                if(!is_string($action)||!is_string($resource)||strlen($resource)>255)throw new RuntimeException('Choose a supported account item.');
                $report=publishing_instagram_engagement($connection,$action,$resource);
            }
            render_write('instagram_engagement',['writeTitle'=>publishing_services()[$m[1]]['name'],'connection'=>$connection,'report'=>$report]);return true;
        }
        if (preg_match('~^/write/connections/([a-z_]+)/report$~',$path,$m)&&is_post()) {
            $connection=publishing_connection($m[1]);
            if(!$connection||!in_array($m[1],['google_search_console','google_analytics','google_youtube_read','google_youtube_upload','tiktok_read'],true)||$connection['status']!=='connected'||empty($connection['selected_resource']))throw new RuntimeException('Authorize the relevant reporting service and confirm the returned account or destination before loading a report.');
            $report=$m[1]==='tiktok_read'?publishing_tiktok_report($connection):publishing_google_report($connection);
            render_write('connections_report',['writeTitle'=>publishing_services()[$m[1]]['name'].' report','connection'=>$connection,'report'=>$report]);return true;
        }
        if ($path==='/write/connections/channel'&&is_post()) {
            $slug=(string)($_POST['slug']??'');$channel=publishing_channel($slug);if(!$channel)throw new RuntimeException('Unknown channel.');
            $method=(string)($_POST['delivery_method']??'manual');
            if(!in_array($method,['manual','buffer','direct'],true))throw new RuntimeException('Choose a supported delivery route.');
            $type=$channel['account_type'];
            if($slug==='facebook') {
                $type=(string)($_POST['account_type']??'professional_profile');
                if(!in_array($type,['professional_profile','page'],true))throw new RuntimeException('Choose Facebook professional profile or Page.');
            }
            $channel['account_type']=$type;$channel['delivery_method']=$method;
            $channel['buffer_mode']=($_POST['buffer_mode']??'automatic')==='notification'?'notification':'automatic';
            $bufferId=trim((string)($_POST['buffer_channel_id']??''));
            $connection=$method==='manual'?null:publishing_connection($method==='buffer'?'buffer':publishing_service_for_channel($slug));
            $remote=null;
            if($method==='buffer') {
                foreach(publishing_buffer_channels($connection) as $candidate)if($candidate['id']===$bufferId){$remote=$candidate;break;}
                if(!$remote)throw new RuntimeException('Choose a Buffer channel returned for the confirmed organization.');
                // A fresh read verifies that a cached channel has not been disconnected or replaced.
                $remote=publishing_buffer_channel($bufferId,$connection);
                if((string)$remote['organizationId']!==(string)$connection['selected_resource'])throw new RuntimeException('This channel moved to another Buffer organization. Reconnect and select its current organization.');
            }
            $cap=publishing_capability($channel,$connection,$remote);
            if(!$cap['usable'])throw new RuntimeException($cap['reason']);
            db()->prepare('UPDATE publishing_channels SET account_type=?,delivery_method=?,buffer_channel_id=?,buffer_mode=? WHERE id=?')->execute([$type,$method,$method==='buffer'?$bufferId:null,$channel['buffer_mode'],$channel['id']]);
            publishing_event(null,null,'channel_route_changed',$channel['name'].' delivery route changed to '.$method.'. Existing approved revisions retain their original destination.',$userId);
            flash('success','Channel route saved. Review and approve a new package revision to use it.');redirect(url('/write/connections#channels'));
        }
        if ($path==='/write/publishing'&&!is_post()) {
            $packages=db()->query('SELECT p.*, (SELECT COUNT(*) FROM publishing_jobs j JOIN publishing_revisions r ON r.id=j.revision_id WHERE r.package_id=p.id) job_count FROM publishing_packages p ORDER BY p.updated_at DESC LIMIT 100')->fetchAll();
            $jobs=db()->query("SELECT j.*,p.title,p.id package_id,c.name channel_name FROM publishing_jobs j JOIN publishing_revisions r ON r.id=j.revision_id JOIN publishing_packages p ON p.id=r.package_id JOIN publishing_channels c ON c.id=j.channel_id ORDER BY CASE WHEN j.status IN ('failed','ambiguous','reconnect','assisted','reminder_queued','reminder_sent') THEN 0 WHEN j.status='scheduled' THEN 1 ELSE 2 END,j.scheduled_at DESC LIMIT 100")->fetchAll();
            render_write('publishing',['writeTitle'=>'Publishing centre','packages'=>$packages,'jobs'=>$jobs]);return true;
        }
        if ($path==='/write/publishing/new'&&!is_post()) {
            $post=null;if(!empty($_GET['post'])){$st=db()->prepare('SELECT * FROM posts WHERE id=?');$st->execute([(int)$_GET['post']]);$post=$st->fetch()?:null;}
            render_write('publishing_editor',['writeTitle'=>'New publication package','package'=>null,'draft'=>publishing_default_draft($post),'channels'=>publishing_channels(),'jobs'=>[],'revision'=>null,'events'=>[]]);return true;
        }
        if($path==='/write/publishing/automation'){
            journeys_method(['GET','HEAD','POST']);
            if(is_post()){comm_put('publishing.weekly',publishing_weekly_input($_POST));flash('success','Weekly publishing preferences saved. Existing delivery times are unchanged.');redirect(url($path));}
            render_write('publishing_automation',['writeTitle'=>'Publishing schedule','cfg'=>publishing_weekly_config(),'heartbeat'=>comm_get('publishing.weekly.heartbeat'),'overview'=>publishing_weekly_overview()]);return true;
        }
        if($path==='/write/publishing/prepare'&&is_post()){
            $draft=publishing_prepare_channels($_POST);$package=!empty($_POST['id'])?publishing_package((int)$_POST['id']):null;
            if($package&&(int)$package['draft_version']!==(int)($_POST['draft_version']??-1))throw new InvalidArgumentException('This draft changed. Reload before preparing new versions.');
            flash('success','Channel suggestions prepared. Review the copy, selected media and image descriptions, then save and approve. Nothing has been published.');
            render_write('publishing_editor',['writeTitle'=>'Review channel suggestions','package'=>$package,'draft'=>$draft,'channels'=>publishing_channels(),'jobs'=>[],'revision'=>null,'events'=>[]]);return true;
        }
        if ($path==='/write/publishing/save'&&is_post()) {
            $id=publishing_save_package($_POST,$userId,(int)($_POST['id']??0),(int)($_POST['draft_version']??0));
            flash('success','Draft saved. Review the saved package before approval.');redirect(url('/write/publishing/'.$id));
        }
        if (preg_match('~^/write/publishing/(\d+)$~',$path,$m)&&!is_post()) {
            $package=publishing_package((int)$m[1]);if(!$package){http_response_code(404);echo 'Publication package not found.';return true;}
            $st=db()->prepare('SELECT j.*,r.draft_version,c.name channel_name FROM publishing_jobs j JOIN publishing_revisions r ON r.id=j.revision_id JOIN publishing_channels c ON c.id=j.channel_id WHERE r.package_id=? ORDER BY j.id DESC');$st->execute([$package['id']]);$jobs=$st->fetchAll();
            $st=db()->prepare('SELECT * FROM publishing_events WHERE package_id=? ORDER BY id DESC LIMIT 30');$st->execute([$package['id']]);$events=$st->fetchAll();
            $revision=null;if($package['approved_revision_id']){$st=db()->prepare('SELECT * FROM publishing_revisions WHERE id=?');$st->execute([$package['approved_revision_id']]);$revision=$st->fetch()?:null;}
            render_write('publishing_editor',['writeTitle'=>$package['title'],'package'=>$package,'draft'=>publishing_decode($package['draft_json']),'channels'=>publishing_channels(),'jobs'=>$jobs,'revision'=>$revision,'events'=>$events]);return true;
        }
        if (preg_match('~^/write/publishing/(\d+)/(approve|schedule)$~',$path,$m)&&is_post()) {
            $back='/write/publishing/'.$m[1];
            if($m[2]==='approve') {
                if(empty($_POST['approve_content'])||empty($_POST['media_rights']))throw new RuntimeException('Confirm the exact copy, destinations and permission to publish its media.');
                $destinationsHash=(string)($_POST['destinations_hash']??'');if(!preg_match('/^[a-f0-9]{64}$/',$destinationsHash))throw new RuntimeException('Reload the saved review before approving.');
                publishing_approve((int)$m[1],(int)($_POST['draft_version']??0),$userId,$destinationsHash);flash('success','This revision is approved and frozen. Choose its release time.');
            } else {
                if(($_POST['schedule_mode']??'')==='weekly'){
                    $n=comm_lock('publishing.weekly.schedule',function()use($m,$userId){$q=db()->prepare('SELECT snapshot_json FROM publishing_revisions WHERE id=? AND package_id=?');$q->execute([(int)($_POST['revision_id']??0),(int)$m[1]]);$json=$q->fetchColumn();if(!$json)throw new InvalidArgumentException('Approved revision not found.');$times=publishing_weekly_times(publishing_decode($json));return publishing_schedule((int)$m[1],(int)$_POST['revision_id'],date('Y-m-d\\TH:i',time()+600),$userId,$times,true);});
                }else $n=publishing_schedule((int)$m[1],(int)($_POST['revision_id']??0),(string)($_POST['scheduled_at']??''),$userId);
                flash('success',$n?'The approved jobs are scheduled. The worker will process them at release time.':'This revision already has delivery jobs. No duplicate jobs were created.');
            }
            redirect(url($back));
        }
        if (preg_match('~^/write/publishing/jobs/(\d+)/(complete|retry|cancel)$~',$path,$m)&&is_post()) {
            if ((int)db()->query("SELECT GET_LOCK('waithaka_publishing_worker',0)")->fetchColumn()!==1) throw new RuntimeException('The worker is processing deliveries. Reload this record in a moment before changing it.');
            try {
            $st=db()->prepare('SELECT j.*,r.package_id FROM publishing_jobs j JOIN publishing_revisions r ON r.id=j.revision_id WHERE j.id=?');$st->execute([(int)$m[1]]);$job=$st->fetch();if(!$job)throw new RuntimeException('Delivery job not found.');
            $back='/write/publishing/'.$job['package_id'];$destination=publishing_decode($job['destination_json']);
            if($m[2]==='complete') {
                if(!in_array($job['status'],['assisted','reminder_queued','reminder_sent','ambiguous','failed','reconnect','delivered'],true)||empty($_POST['confirm_published']))throw new RuntimeException('Confirm that this exact post is already published.');
                $external=trim((string)($_POST['external_url']??''));
                if(!publishing_job_url_valid($external,$destination['slug']))throw new RuntimeException('Paste the actual published post URL on the selected platform, not its profile URL.');
                publishing_job_update((int)$job['id'],['status'=>'published','external_url'=>$external,'completed_at'=>gmdate('Y-m-d H:i:s'),'last_error'=>'Publication confirmed by Writer user '.$userId.'.']);
                publishing_event((int)$job['package_id'],(int)$job['id'],'manual_completion','Writer confirmed the published post URL.',$userId);
            } elseif($m[2]==='retry') {
                $youtubeResume=publishing_youtube_can_resume($job);
                $tiktokResume=publishing_tiktok_business_can_resume($job);
                if((!in_array($job['status'],['failed','reconnect'],true)&&!($job['status']==='ambiguous'&&($youtubeResume||$tiktokResume)))||empty($_POST['reviewed_remote']))throw new RuntimeException('Only reviewed failed/reconnected work, a recoverable YouTube session or a known TikTok publishing task can be resumed. Unknown outcomes never create another post automatically.');
                if($tiktokResume) {
                    publishing_job_connection($job);
                    // This resumes status reads for the saved task; it cannot submit another video.
                    publishing_job_update((int)$job['id'],['status'=>'delivered','next_check_at'=>gmdate('Y-m-d H:i:s'),'last_error'=>null]);
                } elseif($youtubeResume) {
                    publishing_job_connection($job);
                    $state=publishing_youtube_state($job);$state['retries']=0;
                    publishing_job_update((int)$job['id'],['status'=>'delivered','next_check_at'=>gmdate('Y-m-d H:i:s'),'last_error'=>null,'provider_state_json'=>publishing_json($state)]);
                } elseif(publishing_youtube_can_restart_rejected_init($job)) {
                    publishing_job_connection($job);
                    publishing_job_update((int)$job['id'],['status'=>'scheduled','provider_state_json'=>null,'last_error'=>null]);
                } elseif(!empty($job['remote_id'])) {
                    publishing_job_connection($job);
                    if($job['method']!=='buffer')throw new RuntimeException('This job already has a remote ID. Review the existing post.');
                    publishing_job_update((int)$job['id'],['status'=>'delivered','next_check_at'=>gmdate('Y-m-d H:i:s'),'last_error'=>null]);
                } else {
                    if(!empty($job['provider_state_json']))throw new RuntimeException('This job has a remote processing record. Review it in the platform before creating another revision.');
                    if($job['method']!=='manual')publishing_job_connection($job);
                    publishing_job_update((int)$job['id'],['status'=>'scheduled','last_error'=>null]);
                }
                if(in_array((int)$job['id'],publishing_automation_ids(),true)){
                    $check=db()->prepare('SELECT status FROM publishing_jobs WHERE id=?');$check->execute([$job['id']]);
                    if($check->fetchColumn()==='scheduled'){$cfg=publishing_weekly_config();$slug=$destination['slug'];$next=publishing_weekly_next($cfg[$slug],$cfg['timezone'],time()+300,publishing_weekly_reserved($slug));publishing_job_update((int)$job['id'],['scheduled_at'=>gmdate('Y-m-d H:i:s',$next)]);}
                }
                publishing_event((int)$job['package_id'],(int)$job['id'],'delivery_resumed','Writer reviewed the failed attempt and resumed the existing job.',$userId);
            } else {
                $nativePending=$job['status']==='delivered'&&(publishing_youtube_can_resume($job)||publishing_tiktok_business_can_resume($job));
                if(!in_array($job['status'],['scheduled','assisted','failed','reconnect','ambiguous','reminder_queued','reminder_sent'],true)&&!$nativePending)throw new RuntimeException('Manage an accepted or processing post in the provider before closing its record.');
                if((!empty($job['remote_id'])||$job['status']==='ambiguous'||$nativePending)&&empty($_POST['reviewed_remote']))throw new RuntimeException('Check and stop any remote publication before closing this record.');
                $st=db()->prepare("UPDATE publishing_jobs SET status='cancelled' WHERE id=? AND status=?");$st->execute([$job['id'],$job['status']]);
                if($st->rowCount()!==1)throw new RuntimeException('The worker changed this job. Reload its status before continuing.');
                publishing_event((int)$job['package_id'],(int)$job['id'],'delivery_cancelled','Writer cancelled the local job after checking any remote work.',$userId);
            }
            } finally { db()->query("SELECT RELEASE_LOCK('waithaka_publishing_worker')"); }
            flash('success','Delivery record updated.');redirect(url($back));
        }
        http_response_code(404);echo 'Publishing route not found.';return true;
    } catch (Throwable $e) {
        $message=$e instanceof PDOException?'The publishing record could not be saved. Check the database migration and try again.':$e->getMessage();
        flash('error',mb_substr($message,0,500));redirect(url($back));
    }
}
