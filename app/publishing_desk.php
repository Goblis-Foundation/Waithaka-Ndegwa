<?php
declare(strict_types=1);
require_once __DIR__.'/publishing_desk_policy.php';
require_once __DIR__.'/publishing_desk_lifecycle.php';
require_once __DIR__.'/publishing_desk_billing.php';
require_once __DIR__.'/publishing_desk_routes.php';
require_once __DIR__.'/publishing_desk_media.php';

function desk_config(): array {
    return comm_get('desk.config')+['billing_enabled'=>false,'seller'=>'','billing_email'=>'','invoice_address'=>'','tax_note'=>'Taxes, if applicable, are stated in your accepted quotation.','bank_instructions'=>'','bank_reference'=>'','paypal_merchant'=>'','providers'=>[],'daily_cap'=>1,'brand_asset'=>0];
}
function desk_owner(): int {
    require_auth();$id=(int)current_user()['id'];if($id!==(int)(comm_config()['owner']??0))throw new InvalidArgumentException('Only the website owner can manage this workspace.');return $id;
}
function desk_id(string $id): string {if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Record unavailable.');return $id;}
function desk_get(string $id): array {$r=comm_get('desk.record.'.desk_id($id));if(!$r)throw new InvalidArgumentException('Record unavailable.');return $r;}
function desk_all(): array {return comm_records('desk.record',2000);}
function desk_order(string $id): array {$o=comm_get('desk.order.'.desk_id($id));if(!$o)throw new InvalidArgumentException('Order unavailable.');return $o;}
function desk_post_record(int $post): ?array {if($post<1)return null;$m=comm_get('desk.post.'.$post);return empty($m['id'])?null:desk_get($m['id']);}
function desk_store(array $r,string $event,string $actor): array {
    $r['version']=(int)($r['version']??0)+1;$r['updated']=time();
    $r['audit']=array_slice([...($r['audit']??[]),['at'=>time(),'event'=>$event,'actor'=>$actor,'version'=>$r['version']]],-200);
    comm_put('desk.record.'.$r['id'],$r);return $r;
}
function desk_path(array $r): string {return ($r['kind']==='opportunity'?'/opportunities/':'/partner-stories/').$r['slug'];}
function desk_post_input(array $post): array {
    $in=array_intersect_key($post,array_flip(writer_editable_fields()));$in['id']=(int)$post['id'];$in['draft_version']=(int)$post['_draft_version'];
    $in['published_at']=date('Y-m-d\TH:i',time());return $in;
}
function desk_article_html(array $f,string $kind): string {
    $html='<p>'.e($f['summary']).'</p><p>'.nl2br(e($f['description'])).'</p>';
    $sections=$kind==='opportunity'?['benefits'=>'What it offers','eligibility'=>'Who qualifies','countries'=>'Eligible countries','conditions'=>'Conditions','documents'=>'Documents required','steps'=>'How to apply','costs'=>'Fees and costs','travel_support'=>'Travel and accommodation']:['offer'=>'The product or service','audience'=>'Who it is for','benefits'=>'What the client offers','conditions'=>'Terms and limitations'];
    foreach($sections as$key=>$heading)if(($f[$key]??'')!=='')$html.='<h2>'.e($heading).'</h2><p>'.nl2br(e($f[$key])).'</p>';
    return $html;
}
function desk_new(array $in,string $kind,int $uid,?array $client=null): array {
    $fields=desk_fields($in,$kind);$id=bin2hex(random_bytes(16));
    $saved=writer_save_post(['title'=>$fields['title'],'standfirst'=>$fields['summary'],'content_html'=>desk_article_html($fields,$kind)],$uid,false);
    $r=['id'=>$id,'kind'=>$kind,'fields'=>$fields,'state'=>'draft','slug'=>substr(slugify($fields['title']),0,130).'-'.substr($id,0,8),'post_id'=>$saved['id'],'created'=>time(),'version'=>0,'client'=>$client,'creatives'=>[],'orders'=>[],'campaigns'=>[],'owner_approval'=>null,'client_approval'=>null,'public'=>null,'public_visible'=>false];
    $pdo=db();$pdo->beginTransaction();try{comm_put('desk.post.'.$saved['id'],['id'=>$id,'sponsored'=>$fields['sponsored']]);$r=desk_store($r,'created',(string)$uid);$pdo->commit();return $r;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function desk_expect(array $r,array $in): void {if((string)($in['version']??'')!==(string)$r['version'])throw new InvalidArgumentException('This record changed. Reload before continuing.');}
function desk_current_order(array $r): ?array {return empty($r['order'])?null:desk_order($r['order']);}
function desk_assert_publish(array $r,?array $order=null): array {
    desk_fields(desk_edit_fields($r['fields']),$r['kind'],true);
    $post=writer_post_by_id((int)$r['post_id']);if(!$post)throw new InvalidArgumentException('The source article is unavailable.');$order??=desk_current_order($r);
    if($r['fields']['sponsored']&&$order&&($order['record']??'')!==$r['id'])throw new InvalidArgumentException('The commercial order belongs to another article.');
    $errors=desk_publish_errors($r,$post,$order);if($errors)throw new InvalidArgumentException(implode(' ',$errors));return $post;
}
function desk_send_client(array $r,string $subject): void {
    $client=$r['client']??null;if(!$client)throw new InvalidArgumentException('Add the client contact first.');
    $token=bin2hex(random_bytes(32));$expires=time()+14*86400;
    comm_put('desk.access.'.hash('sha256',$token),['record'=>$r['id'],'email'=>$client['email'],'expires'=>$expires]);
    $link=url('/client/access/'.$token);
    $id=enqueue_email($client['email'],$subject,email_design($subject,'<p>Your private brief, quote, article preview and campaign records are available below. Opening the link does not approve, pay or publish anything.</p>'.email_design_button('Open private client workspace',$link).'<p>The sign-in link expires in 14 days. Please keep it private.</p>',['name'=>$client['name']]),'desk:'.$r['id'].':'.$r['version'].':'.hash('sha256',$token));
    desk_register_mail($id,$r['id'],$client['email'],$expires);
}
function desk_client(array $in): array {
    $email=desk_text($in['client_email']??'','Client email',254,true);if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid client email.');
    return ['name'=>desk_text($in['client_name']??'','Client name',120,true),'email'=>strtolower($email),'business'=>desk_text($in['organisation']??'','Business',180,true)];
}
function desk_update(string $id,array $in,int $uid): array {
    return comm_lock('desk.catalog',function()use($id,$in,$uid){
        $r=desk_get($id);desk_expect($r,$in);$action=$in['action']??'save';$post=writer_post_by_id((int)$r['post_id']);
        if(!$post)throw new InvalidArgumentException('Source article unavailable.');
        if($action==='save'){
            $fields=desk_fields($in,$r['kind']);$fields['sponsored']=$fields['sponsored']||$r['fields']['sponsored'];
            if($fields['sponsored']&&$fields['sponsor_name']==='')$fields['sponsor_name']=$fields['organisation'];
            $r['fields']=$fields;$r['owner_approval']=$r['client_approval']=null;$r['state']='draft';
            if(!empty($in['client_email'])){$newClient=desk_client($in);if(!empty($r['orders'])&&!hash_equals($r['client']['email']??'',$newClient['email']))throw new InvalidArgumentException('An invoiced record cannot be transferred to another client email. Create a separate brief.');$r['client']=$newClient;}
            comm_put('desk.post.'.$r['post_id'],['id'=>$r['id'],'sponsored'=>$fields['sponsored']]);
        }elseif($action==='draft'){
            $input=desk_post_input($post);$input['title']=$r['fields']['title'];$input['standfirst']=$r['fields']['summary'];$input['content_html']=desk_article_html($r['fields'],$r['kind']);
            writer_save_post($input,$uid,false);$r['owner_approval']=$r['client_approval']=null;$r['state']='draft';
        }elseif($action==='ai'){
            if(($in['ai_consent']??'')!=='1')throw new InvalidArgumentException('Confirm that the selected public facts may be sent to AI.');
            if(!journeys_rate_limit('desk-ai',(string)$uid,8,3600))throw new InvalidArgumentException('Drafting limit reached. Continue manually for now.');
            $raw=openai_text(desk_ai_instructions(),json_encode(desk_ai_material($r),JSON_THROW_ON_ERROR),3500,true);
            $result=json_decode(trim(preg_replace('/^```(?:json)?|```$/m','',(string)$raw)),true);
            if(!is_array($result))throw new InvalidArgumentException('AI returned no valid draft. Your existing article has not changed.');
            $input=desk_post_input($post);$input['title']=desk_text($result['headline']??'','AI headline',180,true);$input['standfirst']=desk_text($result['summary']??'','AI summary',800,true);
            $body=desk_text($result['body']??'','AI body',30000,true);$input['content_html']=workspace_rich_text($body);
            $gaps=is_array($result['missing_facts']??null)?$result['missing_facts']:[];
            $r['banner_headline']=desk_text($result['banner_headline']??$r['fields']['title'],'Banner headline',150);
            $r['banner_theme']=in_array($result['banner_theme']??'',['funding','agriculture','conference','innovation','enterprise'],true)?$result['banner_theme']:'enterprise';
            writer_save_post($input,$uid,false);$r['ai_gaps']=array_slice(array_values(array_filter($gaps,'is_string')),0,30);
            $r['owner_approval']=$r['client_approval']=null;$r['state']='draft';
        }elseif($action==='invite'){
            if(!$r['fields']['sponsored'])throw new InvalidArgumentException('Only sponsored features need client factual review.');
            $r['review_digest']=desk_digest($r,$post);$r['state']='review';$r=desk_store($r,'review_requested',(string)$uid);desk_send_client($r,'Review your sponsored feature');return $r;
        }elseif($action==='approve'){
            if(($in['approval_ack']??'')!=='1')throw new InvalidArgumentException('Confirm sources, claims and media permissions.');
            desk_fields(desk_edit_fields($r['fields']),$r['kind'],true);$digest=desk_digest($r,$post);
            if($r['fields']['sponsored']&&($r['client_approval']??'')!==$digest)throw new InvalidArgumentException('Request client factual approval of this exact version first.');
            $r['owner_approval']=$digest;$r['state']='approved';
        }elseif(in_array($action,['publish','schedule'],true)){
            desk_assert_publish($r);
            if($action==='schedule'){$at=desk_date($in['publish_at']??'','Africa/Nairobi','publication time',true);if($at<=time()+60)throw new InvalidArgumentException('Choose a future publication time.');if(desk_closed($r,$at))throw new InvalidArgumentException('Publication would be after the deadline.');$r['publish_at']=$at;$r['state']='scheduled';}
            else return desk_publish($r,$uid);
        }elseif(in_array($action,['close','withdraw'],true)){$r['state']=$action==='close'?'closed':'withdrawn';$r['closed_reason']=desk_text($in['note']??'Closed by the editor.','Reason',1000,true);if($action==='withdraw'){$r['public_visible']=false;db()->prepare("UPDATE posts SET status='draft' WHERE id=?")->execute([$r['post_id']]);}}
        elseif($action==='client_link'){desk_send_client($r,'Your private publishing workspace');return $r;}
        else throw new InvalidArgumentException('Choose a supported action.');
        return desk_store($r,$action,(string)$uid);
    });
}
function desk_publish(array $r,int $uid): array {
    $post=desk_assert_publish($r);$input=desk_post_input($post);
    $saved=writer_save_post($input,$uid,true);$post=writer_post_by_id((int)$saved['id']);
    $r['state']='published';$r['public_visible']=true;$r['published_at']=$r['published_at']??time();$r['public']=['fields'=>desk_public_fields($r),'article'=>array_intersect_key($post,array_flip(['title','standfirst','content_html','featured_image','featured_image_alt','featured_image_caption','video_url','category_id'])),'creatives'=>$r['creatives'],'digest'=>desk_digest($r,$post),'at'=>time()];
    if($r['fields']['sponsored']){$o=desk_current_order($r);$r['visible_until']=max((int)($r['visible_until']??0),time()+(int)$o['quote']['visibility_days']*86400);}
    return desk_store($r,'published',(string)$uid);
}
function desk_destination_key(array $d): string {
    $keys=['slug','profile_url','account_type','method','connection_id','connection_external_id','resource','service','buffer_channel_id','buffer_mode','service_id','organization_id'];
    $v=[];foreach($keys as$key)$v[$key]=$d[$key]??null;return hash('sha256',json_encode($v,JSON_THROW_ON_ERROR));
}
function desk_channel_snapshot(string $slug,string $account=''): array {
    $channel=publishing_channel($slug);if(!$channel||!in_array($slug,['linkedin','facebook','instagram','x'],true))throw new InvalidArgumentException('Choose a supported social channel.');
    $dest=publishing_destination($channel);$profile=(string)($dest['profile_url']??'');
    if($profile==='')throw new InvalidArgumentException('Confirm the exact profile address in Integrations before quoting this account.');
    if($account!==''&&rtrim(desk_url($account,'assisted account URL',true),'/')!==rtrim($profile,'/'))throw new InvalidArgumentException('The requested account differs from the configured publishing destination.');
    return ['slug'=>$slug,'channel_id'=>(int)$channel['id'],'name'=>$channel['name'],'method'=>$dest['method'],'account'=>$profile,'format'=>$dest['method']==='direct'&&in_array($slug,['x','linkedin','facebook'],true)?'text_link':'image','route_hash'=>desk_destination_key($dest)];
}
function desk_capacity(array $times,array $channels,?string $except=null): void {
    $cap=(int)desk_config()['daily_cap'];$usage=[];$zone=new DateTimeZone('Africa/Nairobi');
    foreach(comm_records('desk.order',3000)as$o){if($o['id']===$except||in_array($o['state'],['cancelled','refunded','disputed'],true)||($o['state']!=='paid'&&($o['hold_until']??0)<time()))continue;
        foreach($o['times']??[]as$t)foreach($o['quote']['channels']as$c){$key=$c['slug'].':'.(new DateTimeImmutable('@'.$t))->setTimezone($zone)->format('Y-m-d');$usage[$key]=($usage[$key]??0)+1;}
        if(!empty($o['schedule_proposal'])&&$o['schedule_proposal']['expires']>time())foreach($o['schedule_proposal']['indices']as$i)foreach($o['quote']['channels']as$c){$t=$o['schedule_proposal']['times'][$i];$key=$c['slug'].':'.(new DateTimeImmutable('@'.$t))->setTimezone($zone)->format('Y-m-d');$usage[$key]=($usage[$key]??0)+1;}}
    foreach($times as$t)foreach($channels as$c){$key=$c['slug'].':'.(new DateTimeImmutable('@'.$t))->setTimezone($zone)->format('Y-m-d');if(($usage[$key]??0)>=$cap)throw new InvalidArgumentException('A sponsored slot is already reserved on '.$key.'. Choose another start date.');$usage[$key]=($usage[$key]??0)+1;}
}
function desk_quote(string $id,array $in,int $uid): array {
    return comm_lock('desk.catalog',function()use($id,$in,$uid){$r=desk_get($id);desk_expect($r,$in);if(!$r['fields']['sponsored']||empty($r['client']))throw new InvalidArgumentException('Add the sponsor and client contact first.');
        $cfg=desk_config();if($cfg['seller']===''||$cfg['billing_email']===''||$cfg['invoice_address']==='')throw new InvalidArgumentException('Complete the commercial supplier details in Publishing desk settings first.');
        if(!empty($in['renewal'])&&empty($r['public']))throw new InvalidArgumentException('A distribution-only renewal requires an existing article.');
        $channels=[];foreach(['channel_one','channel_two']as$key)$channels[]=desk_channel_snapshot((string)($in[$key]??''),(string)($in[$key.'_account']??''));
        $quote=desk_make_quote((string)($in['package']??''),$channels,$in);$times=desk_round_times($quote,(string)($in['campaign_start']??''));
        if($times[0]<time()+86400)throw new InvalidArgumentException('Allow at least 24 hours for payment and approvals before the first slot.');
        if(desk_closed($r,end($times)))throw new InvalidArgumentException('The campaign would run after the opportunity or offer expires.');
        desk_capacity($times,$channels);$oid=bin2hex(random_bytes(16));$seller=['name'=>$cfg['seller'],'email'=>$cfg['billing_email'],'address'=>$cfg['invoice_address'],'tax_note'=>$cfg['tax_note']];
        unset($quote['hash']);$quote['original_schedule']=$times;$quote['supplier']=$seller;$quote['hash']=hash('sha256',json_encode($quote,JSON_THROW_ON_ERROR));
        $o=['id'=>$oid,'record'=>$r['id'],'client_email'=>$r['client']['email'],'quote'=>$quote,'times'=>$times,'state'=>'quoted','created'=>time(),'hold_until'=>min(time()+72*3600,$times[0]-3600),'accepted_hash'=>null,'seller'=>$seller,'payments'=>[],'campaign'=>[]];
        comm_put('desk.order.'.$oid,$o);$r['orders'][]=$oid;$r['order']=$oid;$r=desk_store($r,'quote_created',(string)$uid);desk_send_client($r,'Your sponsored publishing quotation');return $o;
    });
}
function desk_client_action(string $id,array $in,string $email): void {
    comm_lock('desk.catalog',function()use($id,$in,$email){$r=desk_get($id);desk_expect($r,$in);if(!hash_equals($r['client']['email']??'',$email))throw new InvalidArgumentException('Client access denied.');$action=$in['action']??'';
        if($action==='approve'){$post=writer_post_by_id((int)$r['post_id']);$hash=desk_digest($r,$post);if(($in['approval_ack']??'')!=='1'||($r['review_digest']??'')!==$hash||($in['digest']??'')!==$hash)throw new InvalidArgumentException('Review the latest article and confirm this exact version.');$r['client_approval']=$hash;$r['client_approved_at']=time();}
        elseif($action==='changes'){$r['client_note']=desk_text($in['note']??'','Requested changes',5000,true);$r['client_approval']=$r['owner_approval']=null;$r['state']='review';}
        elseif($action==='accept_quote'){$o=desk_order((string)($in['order']??''));if($o['record']!==$id||!hash_equals($o['client_email'],$email)||$o['state']!=='quoted'||$o['hold_until']<=time()||($in['terms_ack']??'')!=='1'||($in['quote_hash']??'')!==$o['quote']['hash'])throw new InvalidArgumentException('The quote expired or changed. Request a new quotation.');desk_capacity($o['times'],$o['quote']['channels'],$o['id']);$o['accepted_hash']=$o['quote']['hash'];$o['accepted_at']=time();$o['state']='accepted';comm_put('desk.order.'.$o['id'],$o);}
        elseif($action==='cancel'){$r['cancellation_requested']=time();$r['client_note']=desk_text($in['note']??'','Cancellation note',3000);foreach($r['orders']as$oid){$o=desk_order($oid);$o['paused']=true;$o['cancellation_requested']=time();if($o['state']!=='paid')$o['state']='cancelled';comm_put('desk.order.'.$oid,$o);}}
        else throw new InvalidArgumentException('Unsupported client action.');desk_store($r,'client_'.$action,'client');
    });
}
function desk_prepare_campaign(string $id,string $oid,int $uid): void {
    comm_lock('desk.catalog',function()use($id,$oid,$uid){$r=desk_get($id);$o=desk_order($oid);$post=desk_assert_publish($r,$o);$digest=desk_digest($r,$post);
        if($o['record']!==$id||$o['state']!=='paid'||!empty($o['paused'])||$r['state']!=='published')throw new InvalidArgumentException('Publish the approved article and verify this campaign payment first.');
        foreach($o['times']as$i=>$at){
            if(isset($o['campaign'][$i])){
                $old=$o['campaign'][$i];$map=comm_get('desk.package.'.$old['package']);if(($map['digest']??'')===$digest&&!empty($map)&&empty($map['retired']))continue;
                $jobs=desk_round_jobs((int)$old['package']);if(!desk_replaceable_round($jobs))continue;
                foreach($jobs as$j)db()->prepare("UPDATE publishing_jobs SET status='cancelled',last_error='Source revision changed; review its replacement.' WHERE id=? AND (remote_id IS NULL OR remote_id='')")->execute([$j['id']]);
                $map['retired']=true;comm_put('desk.package.'.$old['package'],$map);$o['previous_rounds'][]=$old+['round'=>$i,'retired_at'=>time()];unset($o['campaign'][$i]);
            }
            $draft=publishing_default_draft($post);$draft['source_url']=url(desk_path($r));$draft['title']=mb_substr($r['fields']['title'].' · Round '.($i+1),0,240);$draft['notes']='Commercial order '.$oid.'; publication is counted only after a real post URL is recorded.';
            foreach($o['quote']['channels']as$c){$slug=$c['slug'];$draft['variants'][$slug]['enabled']=true;$draft['variants'][$slug]['text']=desk_social_text($r,['intro','benefits','eligibility','apply'][$i%4],$slug);
                $format=$slug==='instagram'?'square':'landscape';$asset=$r['creatives'][$format]['asset']??0;
                if(!$asset&&$c['format']==='image')throw new InvalidArgumentException('Generate and approve the '.$format.' banner before preparing this image campaign.');
                if($asset&&$c['format']==='image'){$draft['variants'][$slug]['media_asset_id']=$asset;$draft['variants'][$slug]['media_type']='image';$draft['variants'][$slug]['alt_text']=$r['creatives'][$format]['alt'];$draft['variants'][$slug]['ai_generated']=!empty($r['creatives'][$format]['ai_generated']);}
            }
            $pid=publishing_save_package($draft,$uid,0,0);comm_put('desk.package.'.$pid,['record'=>$id,'order'=>$oid,'round'=>$i,'package'=>$pid,'digest'=>$digest]);
            $o['campaign'][$i]=['package'=>$pid,'at'=>$at,'state'=>'draft'];comm_put('desk.order.'.$oid,$o);
        }
        desk_store($r,'campaign_drafts_prepared',(string)$uid);
    });
}
function desk_schedule_campaign(string $oid,int $uid): int {
    return comm_lock('desk.catalog',function()use($oid,$uid){$o=desk_order($oid);$r=desk_get($o['record']);$post=desk_assert_publish($r,$o);$digest=desk_digest($r,$post);
        if($o['state']!=='paid'||!empty($o['paused'])||$r['state']!=='published'||count($o['campaign'])!==$o['quote']['rounds'])throw new InvalidArgumentException('Prepare the paid campaign and publish the article first.');
        $prepared=[];foreach($o['campaign']as$i=>$round){$p=publishing_package((int)$round['package']);if(empty($p['approved_revision_id']))throw new InvalidArgumentException('Review and approve every social round in Posting & calendar first.');
            $map=comm_get('desk.package.'.$p['id']);if(!empty($map['retired'])||($map['digest']??'')!==$digest)throw new InvalidArgumentException('Prepare current campaign revisions before scheduling.');
            $q=db()->prepare('SELECT snapshot_json FROM publishing_revisions WHERE id=? AND package_id=?');$q->execute([$p['approved_revision_id'],$p['id']]);$snap=publishing_decode((string)$q->fetchColumn());$wanted=array_column($o['quote']['channels'],'slug');$got=array_keys($snap['destinations']??[]);sort($wanted);sort($got);if($wanted!==$got)throw new InvalidArgumentException('The approved destinations do not match the two contracted accounts.');
            foreach($o['quote']['channels']as$c){if(desk_destination_key($snap['destinations'][$c['slug']])!==$c['route_hash'])throw new InvalidArgumentException('The frozen social revision has a different account from the contract.');desk_contract_variant($r,$snap['variants'][$c['slug']],$c);$current=desk_channel_snapshot($c['slug'],$c['method']==='manual'?$c['account']:'');if($current['route_hash']!==$c['route_hash'])throw new InvalidArgumentException('A contracted account changed. Re-quote and approve its replacement explicitly.');}
            if($round['state']==='scheduled')continue;if($round['at']<time()+60||desk_closed($r,$round['at']))throw new InvalidArgumentException('A slot was missed or falls after expiry. Agree a valid replacement schedule.');$prepared[]=[$i,$round,$p];
        }
        $n=0;foreach($prepared as[$i,$round,$p]){$times=[];foreach($o['quote']['channels']as$c)$times[$c['slug']]=gmdate('Y-m-d H:i:s',$round['at']);$n+=publishing_schedule((int)$p['id'],(int)$p['approved_revision_id'],date('Y-m-d\TH:i',$round['at']),$uid,$times,true);$o['campaign'][$i]['state']='scheduled';comm_put('desk.order.'.$oid,$o);}return $n;
    });
}
function desk_public_list(string $kind): array {
    $out=[];foreach(desk_all()as$r)if($r['kind']===$kind&&!empty($r['public'])&&!empty($r['public_visible'])&&$r['state']!=='withdrawn')$out[]=$r;
    usort($out,static fn($a,$b)=>($b['published_at']??0)<=>($a['published_at']??0));return $out;
}
function desk_check_variant(array $r,array $v,string $slug): void {
    if(!str_contains((string)($v['text']??''),url(desk_path($r))))throw new InvalidArgumentException('Keep the canonical article link in every social post.');
    if($r['fields']['sponsored']){
        if(!str_starts_with((string)($v['text']??''),'Sponsored feature · '.$r['fields']['sponsor_name']))throw new InvalidArgumentException('Keep the sponsor disclosure at the start of every paid social post.');
        if(preg_match('~(?:/contribute(?:/|\b)|donate to my writing|tip me|support my thoughts)~i',(string)($v['text']??'')))throw new InvalidArgumentException('Sponsored posts must not solicit contributions.');
    }
    $asset=(int)($v['media_asset_id']??$v['media_asset']['id']??0);$match=null;
    foreach($r['creatives']as$c)if((int)$c['asset']===$asset)$match=$c;
    if($asset&&!$match)throw new InvalidArgumentException('This image is not among the article’s approved banners. Review the new creative first.');
    if(!empty($v['media_url'])&&(!$match||$v['media_url']!==$match['url']))throw new InvalidArgumentException('Use the exact approved media-library banner, not a replacement remote URL.');
    if(!empty($v['media_asset']['sha256'])&&(!$match||!hash_equals($match['sha256'],$v['media_asset']['sha256'])))throw new InvalidArgumentException('Approved image fingerprint changed.');
}
function desk_social_text(array $r,string $angle,string $slug): string {
    $link=url(desk_path($r));$f=$r['fields'];$prefix=$f['sponsored']?'Sponsored feature · '.$f['sponsor_name']."\n\n":'';
    $lead=match($angle){'eligibility'=>$f['eligibility']?:$f['audience'],'benefits'=>$f['benefits']?:$f['offer'],'apply'=>$f['steps']?:$f['summary'],default=>$f['summary']};
    $tail="\n\n".$link;$room=publishing_copy_limits()[$slug]-mb_strlen($prefix.$tail);
    if($room<20)throw new InvalidArgumentException('The sponsor name and article URL are too long for '.$slug.'. Shorten the approved public sponsor name.');
    return $prefix.publishing_fit_text($f['title']."\n\n".$lead,$room).$tail;
}
function desk_prepare_organic(string $id,array $in,int $uid): void {
    comm_lock('desk.catalog',function()use($id,$in,$uid){$r=desk_get($id);$post=desk_assert_publish($r);
        if($r['fields']['sponsored']||$r['state']!=='published')throw new InvalidArgumentException('Use the paid campaign workflow for sponsored articles.');
        $slugs=array_values(array_intersect(['linkedin','facebook','instagram','x'],is_array($in['channels']??null)?$in['channels']:[]));if(!$slugs)throw new InvalidArgumentException('Select at least one social account.');
        $draft=publishing_default_draft($post);$draft['source_url']=url(desk_path($r));
        foreach($slugs as$slug){$c=desk_channel_snapshot($slug);$v=&$draft['variants'][$slug];$v['enabled']=true;$v['text']=desk_social_text($r,'intro',$slug);$format=$slug==='instagram'?'square':'landscape';
            if($c['format']==='image'){if(empty($r['creatives'][$format]))throw new InvalidArgumentException('Generate the '.$format.' banner first.');$v['media_asset_id']=$r['creatives'][$format]['asset'];$v['alt_text']=$r['creatives'][$format]['alt'];$v['ai_generated']=!empty($r['creatives'][$format]['ai_generated']);}unset($v);}
        $pid=publishing_save_package($draft,$uid);comm_put('desk.package.'.$pid,['record'=>$id,'order'=>null,'package'=>$pid,'digest'=>desk_digest($r,$post)]);$r['campaigns'][]=$pid;desk_store($r,'social_draft_prepared',(string)$uid);
    });
}
function desk_delivery_guard(): void {
    $q=db()->query("SELECT j.id,j.destination_json,v.snapshot_json,v.package_id,p.source_post_id FROM publishing_jobs j JOIN publishing_revisions v ON v.id=j.revision_id JOIN publishing_packages p ON p.id=v.package_id WHERE j.status IN ('scheduled','assisted','reminder_queued','reminder_sent','failed','reconnect') AND (j.remote_id IS NULL OR j.remote_id='')");
    foreach($q->fetchAll()as$job){$r=desk_post_record((int)$job['source_post_id']);if(!$r)continue;$map=comm_get('desk.package.'.$job['package_id']);$post=writer_post_by_id((int)$r['post_id']);
        $valid=$map&&empty($map['retired'])&&$post&&$r['state']==='published'&&!desk_closed($r)&&hash_equals((string)$map['digest'],desk_digest($r,$post))&&($r['owner_approval']??'')===$map['digest']&&($r['public']['digest']??'')===$map['digest'];
        $snap=publishing_decode($job['snapshot_json']);$dest=publishing_decode($job['destination_json']);$slug=$dest['slug']??'';
        try{desk_check_variant($r,$snap['variants'][$slug]??[],$slug);}catch(Throwable){$valid=false;}
        if($r['fields']['sponsored']){
            $o=!empty($map['order'])?desk_order($map['order']):null;$valid=$valid&&$o&&$o['state']==='paid'&&empty($o['paused'])&&($r['client_approval']??'')===$map['digest'];
            $expected=[];foreach($o['quote']['channels']??[]as$c)if($c['slug']===$slug)$expected=$c;
            $valid=$valid&&$expected&&hash_equals($expected['route_hash'],desk_destination_key($dest));
            try{if($expected)desk_contract_variant($r,$snap['variants'][$slug]??[],$expected);}catch(Throwable){$valid=false;}
        }
        if(!$valid){$u=db()->prepare("UPDATE publishing_jobs SET status='cancelled',last_error='Source, disclosure, payment or exact-version approval changed; review in Publishing desk.' WHERE id=? AND (remote_id IS NULL OR remote_id='')");$u->execute([$job['id']]);}
    }
}
function desk_delivery_gate(callable $work): mixed {return comm_lock('desk.catalog',function()use($work){desk_delivery_guard();return $work();});}
function desk_work(): void {
    if(app_config('app_env')!=='production')return;
    comm_lock('desk.catalog',function(){foreach(desk_all()as$r){if($r['state']==='published'&&desk_closed($r)){$r['state']='closed';desk_store($r,'deadline_closed','worker');}
        if($r['state']==='scheduled'&&($r['publish_at']??PHP_INT_MAX)<=time()){try{desk_publish($r,(int)comm_config()['owner']);}catch(Throwable){$r['state']='review';$r['worker_notice']='Scheduled article needs renewed verification or approval.';desk_store($r,'schedule_blocked','worker');}}}desk_delivery_guard();});
    desk_reconcile_due();desk_mail_work();
}
