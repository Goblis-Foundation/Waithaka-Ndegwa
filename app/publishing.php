<?php
declare(strict_types=1);

require_once __DIR__.'/providers/http.php';
require_once __DIR__.'/providers/oauth.php';
require_once __DIR__.'/providers/buffer.php';
require_once __DIR__.'/providers/youtube.php';
require_once __DIR__.'/providers/direct.php';

function publishing_channels(): array { return db()->query('SELECT * FROM publishing_channels ORDER BY id')->fetchAll(); }
function publishing_channel(string $slug): ?array { $st=db()->prepare('SELECT * FROM publishing_channels WHERE slug=?');$st->execute([$slug]);return $st->fetch()?:null; }
function publishing_package(int $id): ?array { $st=db()->prepare('SELECT * FROM publishing_packages WHERE id=?');$st->execute([$id]);return $st->fetch()?:null; }

/** Describe the saved declaration without asserting a provider-verified account subtype. */
function publishing_account_type_label(array $channel): string {
    $type=(string)($channel['account_type']??'');
    if (($channel['slug']??'')==='tiktok'&&$type==='professional') return 'professional (owner-declared)';
    return str_replace('_',' ',$type);
}

function publishing_event(?int $packageId, ?int $jobId, string $type, string $note, ?int $actorId = null): void {
    db()->prepare('INSERT INTO publishing_events(package_id,job_id,event_type,note,actor_id) VALUES(?,?,?,?,?)')->execute([$packageId,$jobId,$type,mb_substr($note,0,500),$actorId]);
}

function publishing_public_url(string $url): bool {
    if (strlen($url)>2000 || !filter_var($url,FILTER_VALIDATE_URL)) return false;
    $p=parse_url($url);$host=strtolower($p['host']??'');
    if (($p['scheme']??'')!=='https' || isset($p['user']) || isset($p['pass']) || (isset($p['port'])&&(int)$p['port']!==443)) return false;
    if ($host==='' || !str_contains($host,'.') || preg_match('/(^|\.)(localhost|local|internal|test|invalid)$/',$host)) return false;
    if (filter_var(trim($host,'[]'),FILTER_VALIDATE_IP) && !filter_var(trim($host,'[]'),FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return false;
    $path=$p['path']??'/';for($i=0;$i<3;$i++)$path=rawurldecode($path);
    if (preg_match('~/(write|consent|install|payments)(/|$)|/newsletter/(confirm|unsubscribe|one-click|preferences)(/|$)|/founders/(follow-up|story-review)(/|$)|/storage/(release-proofs|logs|private)(/|$)~i',$path)) return false;
    // Source and media URLs are distributed publicly. Do not forward private preview links,
    // subscription capabilities, OAuth tokens or signed object-storage credentials.
    foreach(preg_split('/[&;]/',(string)($p['query']??'')) as $part) {
        $key=explode('=',$part,2)[0];for($i=0;$i<3;$i++)$key=rawurldecode($key);
        $key=strtolower(preg_replace('/\[.*$/','',$key));
        if(preg_match('/^(preview|token|access_token|id_token|refresh_token|auth|authorization|api_?key|code|state|signature|sig|policy|key-pair-id|awsaccesskeyid|x-amz-.+|x-goog-.+)$/',$key)) return false;
    }
    return !preg_match('/[\x00-\x20\x7f]/',$url);
}

function publishing_job_url_valid(string $url, string $slug): bool {
    if (!publishing_public_url($url)) return false;
    $host=strtolower((string)parse_url($url,PHP_URL_HOST));$path=(string)parse_url($url,PHP_URL_PATH);
    $domains=['linkedin'=>['linkedin.com'],'facebook'=>['facebook.com','fb.watch'],'instagram'=>['instagram.com'],'x'=>['x.com','twitter.com'],'youtube'=>['youtube.com','youtu.be'],'tiktok'=>['tiktok.com']];
    if ($slug==='youtube') {
        if ($host==='youtu.be') return (bool)preg_match('~^/[A-Za-z0-9_-]{11}/?$~',$path);
        if ($host!=='youtube.com'&&!str_ends_with($host,'.youtube.com')) return false;
        if (preg_match('~^/(shorts|live)/[A-Za-z0-9_-]{11}/?$~',$path)) return true;
        parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
        return $path==='/watch'&&is_string($query['v']??null)&&(bool)preg_match('/^[A-Za-z0-9_-]{11}$/',$query['v']);
    }
    $pattern=match($slug){
        'linkedin'=>'~/(feed/update|posts)/~', 'instagram'=>'~/(p|reel|reels|stories)/~',
        'x'=>'~/status/\d+(?:/|$)~', 'tiktok'=>'~/(video|photo)/\d+(?:/|$)~',
        'facebook'=>'~/(posts|videos|reel|photo|permalink\.php|story\.php)|^/\d+_\d+$~', default=>'~a^~'
    };
    foreach ($domains[$slug]??[] as $domain) if (($host===$domain||str_ends_with($host,'.'.$domain)) && preg_match($pattern,$path)) return true;
    return false;
}

function publishing_local_time(?string $utc): string {
    if (!$utc) return '—';
    try { return (new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone((string)app_config('timezone','Africa/Nairobi')))->format('D j M Y, H:i T'); } catch (Throwable $e) { return $utc; }
}

function publishing_state_label(string $state): string {
    return ['scheduled'=>'Scheduled','processing'=>'Sending','delivered'=>'Accepted / processing','published'=>'Published','assisted'=>'Finish in platform','reminder_queued'=>'Reminder queued','reminder_sent'=>'Reminder sent','reconnect'=>'Reconnect required','failed'=>'Needs attention','ambiguous'=>'Outcome unknown','cancelled'=>'Cancelled','pending_confirmation'=>'Confirm destination','connected'=>'Connected','disconnected'=>'Not connected'][$state]??ucfirst(str_replace('_',' ',$state));
}

function publishing_job_label(array $job): string {
    $destination=publishing_decode($job['destination_json']??'');
    if (($destination['slug']??'')==='youtube'&&($job['method']??'')==='direct'&&($job['status']??'')==='delivered') {
        $state=publishing_youtube_state($job);
        if (($state['phase']??'')==='uploading'&&!empty($state['bytes'])) return 'Uploading · '.min(100,(int)floor(100*(int)($state['offset']??0)/(int)$state['bytes'])).'% acknowledged';
        if (($state['phase']??'')==='processing') return 'Uploaded · Processing';
    }
    if (($job['status']??'')==='published'&&($destination['slug']??'')==='youtube') {
        $visibility=$destination['visibility']??'unknown';
        return match($visibility) {'private'=>'Uploaded · Private','unlisted'=>'Uploaded · Unlisted','public'=>'Published · Public',default=>'Upload completed'};
    }
    return publishing_state_label((string)($job['status']??''));
}

function publishing_default_draft(?array $post=null): array {
    $source=$post?url('/my-thoughts/'.$post['slug']):'';
    $draft=['title'=>$post['title']??'','source_post_id'=>$post['id']??null,'source_url'=>$source,'notes'=>'','variants'=>[]];
    foreach (['linkedin','facebook','instagram','x','youtube','tiktok'] as $slug) $draft['variants'][$slug]=[
        'enabled'=>false,'text'=>$post?trim((string)($post['standfirst']??'')."\n\n".(function_exists('analytics_campaign_url')?analytics_campaign_url('/my-thoughts/'.$post['slug'],$slug,'social','post-'.(int)$post['id'],in_array($slug,['youtube','tiktok'],true)?'video':'article'):$source)):'',
        'media_url'=>'','media_asset_id'=>0,'media_type'=>$slug==='youtube'?'video':'image','alt_text'=>'','title'=>$post['title']??'','format'=>$slug==='youtube'?'video':'post',
        'privacy'=>'private','made_for_kids'=>false,'ai_generated'=>false,'notify_subscribers'=>false,
        'tiktok_allow_comments'=>false,'tiktok_allow_duet'=>false,'tiktok_allow_stitch'=>false,
        'tiktok_brand_organic'=>false,'tiktok_branded_content'=>false,'tiktok_settings_confirmed'=>false
    ];
    return $draft;
}

function publishing_normalize_draft(array $input): array {
    $title=trim((string)($input['title']??''));
    if ($title===''||mb_strlen($title)>240) throw new RuntimeException('Give this package a title of 240 characters or fewer.');
    $source=trim((string)($input['source_url']??''));
    if ($source!==''&&!publishing_public_url($source)) throw new RuntimeException('Use a public HTTPS article URL without private preview, consent, subscription or signed-access tokens.');
    $draft=['title'=>$title,'source_post_id'=>!empty($input['source_post_id'])?(int)$input['source_post_id']:null,'source_url'=>$source,'notes'=>mb_substr(trim((string)($input['notes']??'')),0,5000),'variants'=>[]];
    foreach (['linkedin','facebook','instagram','x','youtube','tiktok'] as $slug) {
        $v=is_array($input['variants'][$slug]??null)?$input['variants'][$slug]:[];
        $assetId=filter_var($v['media_asset_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>PHP_INT_MAX]]);
        if($assetId===false)throw new RuntimeException('Choose a valid media-library asset for '.$slug.'.');
        $media=$assetId?'':trim((string)($v['media_url']??''));
        if ($media!==''&&!publishing_public_url($media)) throw new RuntimeException('Use a public HTTPS media URL without private or signed-access tokens for '.$slug.'.');
        $draft['variants'][$slug]=[
            'enabled'=>!empty($v['enabled']),'text'=>trim((string)($v['text']??'')), 'media_url'=>$media,'media_asset_id'=>$assetId,
            'media_type'=>in_array($v['media_type']??'', ['image','video'],true)?$v['media_type']:'image',
            'alt_text'=>mb_substr(trim((string)($v['alt_text']??'')),0,1000),'title'=>mb_substr(trim((string)($v['title']??$title)),0,100),
            'format'=>in_array($v['format']??'', ['post','reel','story','short','video'],true)?$v['format']:'post',
            'privacy'=>in_array($v['privacy']??'', ['private','unlisted','public'],true)?$v['privacy']:'private',
            'made_for_kids'=>!empty($v['made_for_kids']),'ai_generated'=>!empty($v['ai_generated']),'notify_subscribers'=>!empty($v['notify_subscribers']),
            'tiktok_allow_comments'=>!empty($v['tiktok_allow_comments']),'tiktok_allow_duet'=>!empty($v['tiktok_allow_duet']),
            'tiktok_allow_stitch'=>!empty($v['tiktok_allow_stitch']),'tiktok_brand_organic'=>!empty($v['tiktok_brand_organic']),
            'tiktok_branded_content'=>!empty($v['tiktok_branded_content']),'tiktok_settings_confirmed'=>!empty($v['tiktok_settings_confirmed'])
        ];
        if (mb_strlen($draft['variants'][$slug]['text'])>20000) throw new RuntimeException('The '.$slug.' caption is too long.');
    }
    return $draft;
}

/** Freeze only library-owned metadata, never a media_asset object supplied by a form. */
function publishing_variant_with_asset(array $variant): array {
    if (empty($variant['media_asset_id'])) { unset($variant['media_asset']); return $variant; }
    if (!function_exists('media_library_approved_asset')) throw new RuntimeException('The media library is not installed. Apply its migration before selecting an asset.');
    $asset=media_library_approved_asset((int)$variant['media_asset_id']);
    $variant['media_asset']=$asset;
    $variant['media_type']=str_starts_with((string)$asset['mime'],'video/')?'video':'image';
    $variant['media_url']=($asset['visibility']??'')==='public'?(string)($asset['public_url']??''):'';
    if($variant['media_url']!==''&&!publishing_public_url($variant['media_url']))throw new RuntimeException('The selected asset has no safe public delivery URL.');
    return $variant;
}

/** Pure capability gate: configured branding is never treated as an OAuth grant. */
function publishing_capability(array $channel, ?array $connection = null, ?array $bufferChannel = null): array {
    $slug=$channel['slug'];$method=$channel['delivery_method']??'manual';
    if ($slug==='facebook'&&($channel['account_type']??'')!=='page') return ['usable'=>$method==='manual','mode'=>'manual','reason'=>'A Facebook professional profile is completed in Facebook. Page delivery needs a separately discovered Page.'];
    if ($method==='manual') return ['usable'=>true,'mode'=>'manual','reason'=>'Writer prepares a task; you finish publishing in the platform.'];
    if (!$connection||$connection['status']!=='connected'||empty($connection['external_id'])||empty($connection['selected_resource'])) return ['usable'=>false,'mode'=>$method,'reason'=>'Connect the provider and confirm the actual destination first.'];
    if ($method==='buffer') {
        if (!$bufferChannel) return ['usable'=>false,'mode'=>'buffer','reason'=>'Select a channel returned by Buffer.'];
        try { publishing_validate_buffer_destination($channel,$bufferChannel,$channel['buffer_mode']??'automatic'); }
        catch (Throwable $e) { return ['usable'=>false,'mode'=>'buffer','reason'=>$e->getMessage()]; }
        return ['usable'=>true,'mode'=>$channel['buffer_mode']??'automatic','reason'=>'Buffer will handle the approved delivery. Actual publication is tracked separately.'];
    }
    if ($method==='direct'&&$slug==='youtube') return publishing_youtube_connection_ready($connection)
        ? ['usable'=>true,'mode'=>'direct','reason'=>'Resumable video upload is available for the confirmed YouTube channel. Each revision must include an owned video and explicit audience settings.']
        : ['usable'=>false,'mode'=>'direct','reason'=>'Authorize the separate YouTube upload connection, including upload and read scopes, then confirm the actual channel.'];
    if ($method==='direct'&&$slug==='tiktok') return publishing_tiktok_business_connection_ready($connection)
        ? ['usable'=>true,'mode'=>'direct','reason'=>'Native Accounts API video publishing is available for this confirmed TikTok account. Each revision needs an owned public video, explicit settings and a verified media URL property.']
        : ['usable'=>false,'mode'=>'direct','reason'=>'Authorize the separate TikTok Accounts API app and confirm its returned account and publishing permissions. TikTok read access does not authorize posting.'];
    if ($method==='direct'&&in_array($slug,['linkedin','x','instagram','facebook'],true)) return ['usable'=>true,'mode'=>'direct','reason'=>in_array($slug,['x','linkedin','facebook'],true)?'Direct text/link publishing is available for this confirmed account.':'Direct single-image publishing is available for this professional account.'];
    return ['usable'=>false,'mode'=>$method,'reason'=>'Use Buffer or assisted publishing for this channel and format.'];
}

function publishing_service_for_channel(string $slug): string { return match($slug){'facebook'=>'facebook_pages','youtube'=>'google_youtube_upload','tiktok'=>'tiktok_business',default=>$slug}; }

function publishing_destination(array $channel, array $variant=[]): array {
    $method=$channel['delivery_method'];
    $dest=['slug'=>$channel['slug'],'name'=>$channel['name'],'profile_url'=>$channel['profile_url'],'account_type'=>$channel['account_type'],'method'=>$method];
    if ($channel['slug']==='youtube'||($channel['slug']==='tiktok'&&$method==='direct')) $dest['visibility']=$variant['privacy']??'private';
    $connection=null;$remote=null;
    if ($method!=='manual') $connection=publishing_connection($method==='buffer'?'buffer':publishing_service_for_channel($channel['slug']));
    if ($method==='buffer') foreach(publishing_buffer_channels($connection) as $item) if($item['id']===$channel['buffer_channel_id']){$remote=$item;break;}
    $cap=publishing_capability($channel,$connection,$remote);
    if (!$cap['usable']) throw new RuntimeException($channel['name'].': '.$cap['reason']);
    if ($connection) $dest+=['connection_id'=>(int)$connection['id'],'connection_external_id'=>$connection['external_id'],'service'=>$connection['service'],'resource'=>$connection['selected_resource'],'account_name'=>$connection['display_name']];
    if ($method==='buffer') {
        $dest+=['buffer_channel_id'=>$remote['id'],'buffer_mode'=>$channel['buffer_mode'],'service_id'=>$remote['serviceId'],'organization_id'=>$remote['organizationId']];
        $dest['account_name']=$remote['displayName']??$remote['name'];
    }
    if ($connection&&$method==='direct') foreach(publishing_decode($connection['resources_json']) as $resource) if($resource['id']===$connection['selected_resource']){$dest['account_name']=$resource['name'];if(!empty($resource['url'])&&publishing_public_url($resource['url']))$dest['profile_url']=$resource['url'];}
    if ($remote&&!empty($remote['externalLink'])&&publishing_public_url($remote['externalLink'])) $dest['profile_url']=$remote['externalLink'];
    return $dest;
}

function publishing_validate_variant(string $slug, array $variant, array $destination): void {
    $text=$variant['text'];$media=$variant['media_url'];$method=$destination['method'];
    $ownedMedia=!empty($variant['media_asset']);
    if ($text===''&&$media===''&&!$ownedMedia) throw new RuntimeException('Add content for '.$slug.'.');
    $limits=['linkedin'=>3000,'x'=>280,'instagram'=>2200,'tiktok'=>2200,'youtube'=>5000,'facebook'=>20000];
    if (mb_strlen($text)>$limits[$slug]) throw new RuntimeException(ucfirst($slug).' exceeds the centre’s '.$limits[$slug].'-character caption limit.');
    if (($media!==''||$ownedMedia)&&$variant['media_type']==='image'&&$variant['alt_text']==='') throw new RuntimeException('Add an image description for '.$slug.'.');
    if ($method==='manual') return;
    if ($slug==='youtube'&&$method==='direct') { publishing_youtube_validate_variant($variant); return; }
    if ($slug==='tiktok'&&$method==='direct') { publishing_tiktok_business_validate_variant($variant); return; }
    if ($slug==='tiktok'&&$method==='buffer'&&(!empty($variant['tiktok_settings_confirmed'])||!empty($variant['tiktok_allow_comments'])||!empty($variant['tiktok_allow_duet'])||!empty($variant['tiktok_allow_stitch'])||!empty($variant['tiktok_brand_organic'])||!empty($variant['tiktok_branded_content']))) throw new RuntimeException('The selected native TikTok interaction and promotional settings need the Accounts API route or assisted publishing. This Buffer adapter cannot apply them.');
    if ($ownedMedia&&$media==='') throw new RuntimeException('This delivery route needs a public media-library asset. Keep private media for direct YouTube upload or assisted publishing.');
    if ($slug==='youtube'&&($media===''||$variant['media_type']!=='video'||$variant['title']==='')) throw new RuntimeException('YouTube delivery through Buffer needs a public video file and title.');
    if ($slug==='youtube'&&$method==='buffer'&&($variant['format']!=='short'||!empty($variant['notify_subscribers']))) throw new RuntimeException('This Buffer route supports Shorts without a custom subscriber-notification setting. Choose direct YouTube upload for a full-length video or explicit notifications.');
    if (in_array($slug,['instagram','tiktok'],true)&&$media==='') throw new RuntimeException(ucfirst($slug).' needs a media file for automatic/provider delivery.');
    if ($slug==='tiktok'&&$variant['media_type']!=='video') throw new RuntimeException('This TikTok adapter currently accepts videos. Use assisted publishing for photo posts.');
    if ($method==='direct') {
        if (in_array($slug,['x','linkedin','facebook'],true)&&($media!==''||$ownedMedia)) throw new RuntimeException('Use Buffer or assisted publishing for media on '.$slug.'. Direct delivery supports text and links.');
        if (in_array($slug,['x','linkedin','facebook'],true)&&$variant['format']!=='post') throw new RuntimeException('Direct delivery on '.$slug.' supports a standard text/link post. Choose Buffer or assisted publishing for this format.');
        if ($slug==='instagram'&&($variant['media_type']!=='image'||$variant['format']!=='post'||!preg_match('/\.jpe?g$/i',(string)parse_url($media,PHP_URL_PATH)))) throw new RuntimeException('Direct Instagram delivery supports a single JPEG image post. Use Buffer or assisted delivery for Reels, Stories or other image formats.');
        if ($slug==='instagram'&&$variant['ai_generated']) throw new RuntimeException('Use Buffer or assisted Instagram publishing when the platform AI disclosure is required.');
    }
    if ($slug==='instagram'&&!in_array($variant['format'],['post','reel','story'],true)) throw new RuntimeException('Choose an Instagram post, Reel or Story.');
    if ($slug==='facebook'&&!in_array($variant['format'],['post','reel','story'],true)) throw new RuntimeException('Choose a Facebook post, Reel or Story.');
    if (in_array($variant['format'],['reel','short'],true)&&($media===''||$variant['media_type']!=='video')) throw new RuntimeException('A Reel or Short requires a video file.');
}

function publishing_save_package(array $draft, int $userId, int $id=0, int $version=0): int {
    $draft=publishing_normalize_draft($draft);
    foreach($draft['variants'] as &$variant) {
        $variant=publishing_variant_with_asset($variant);
        // Store the selection in the editable draft; approval pins the immutable metadata.
        unset($variant['media_asset']);
    }
    unset($variant);
    if ($draft['source_post_id']) {
        $st=db()->prepare('SELECT id FROM posts WHERE id=?');$st->execute([$draft['source_post_id']]);
        if (!$st->fetchColumn()) throw new RuntimeException('The linked article was not found.');
    }
    if ($id) {
        $st=db()->prepare('UPDATE publishing_packages SET title=?,source_post_id=?,draft_json=?,draft_version=draft_version+1,approved_revision_id=NULL WHERE id=? AND draft_version=?');
        $st->execute([$draft['title'],$draft['source_post_id'],publishing_json($draft),$id,$version]);
        if ($st->rowCount()!==1) throw new RuntimeException('Another edit changed this package. Reload it before saving.');
    } else {
        db()->prepare('INSERT INTO publishing_packages(title,source_post_id,draft_json,created_by) VALUES(?,?,?,?)')->execute([$draft['title'],$draft['source_post_id'],publishing_json($draft),$userId]);
        $id=(int)db()->lastInsertId();
    }
    publishing_event($id,null,'draft_saved','Draft saved. Existing approved revisions and delivery jobs are unchanged.',$userId);
    return $id;
}

function publishing_approve(int $packageId, int $expectedVersion, int $userId, string $expectedDestinationsHash=''): int {
    db()->beginTransaction();
    try {
        $st=db()->prepare('SELECT * FROM publishing_packages WHERE id=? FOR UPDATE');$st->execute([$packageId]);$package=$st->fetch();
        if (!$package||(int)$package['draft_version']!==$expectedVersion) throw new RuntimeException('This draft changed. Review the saved version before approving.');
        if ($package['approved_revision_id']) { db()->commit(); return (int)$package['approved_revision_id']; }
        $snapshot=publishing_decode($package['draft_json']);$snapshot['destinations']=[];
        foreach(publishing_channels() as $channel) {
            $variant=$snapshot['variants'][$channel['slug']]??[];
            if (empty($variant['enabled'])) continue;
            $variant=publishing_variant_with_asset($variant);
            $snapshot['variants'][$channel['slug']]=$variant;
            $destination=publishing_destination($channel,$variant);
            publishing_validate_variant($channel['slug'],$variant,$destination);
            $snapshot['destinations'][$channel['slug']]=$destination;
        }
        if (!$snapshot['destinations']) throw new RuntimeException('Select at least one channel before approving.');
        if ($expectedDestinationsHash!==''&&!hash_equals($expectedDestinationsHash,hash('sha256',publishing_json($snapshot['destinations'])))) throw new RuntimeException('A destination changed since this review was opened. Reload and review the saved package before approving.');
        if (!empty($snapshot['source_post_id'])) {
            $st=db()->prepare('SELECT title,standfirst,content_html,slug FROM posts WHERE id=?');$st->execute([$snapshot['source_post_id']]);$post=$st->fetch();
            if (!$post) throw new RuntimeException('The source article was not found.');
            $snapshot['source_snapshot']=['title'=>$post['title'],'standfirst'=>$post['standfirst'],'slug'=>$post['slug'],'hash'=>hash('sha256',publishing_json($post))];
        }
        $json=publishing_json($snapshot);
        db()->prepare('INSERT INTO publishing_revisions(package_id,draft_version,snapshot_json,content_hash,approved_by,approved_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())')->execute([$packageId,$expectedVersion,$json,hash('sha256',$json),$userId]);
        $revision=(int)db()->lastInsertId();
        db()->prepare('UPDATE publishing_packages SET approved_revision_id=? WHERE id=?')->execute([$revision,$packageId]);
        publishing_event($packageId,null,'revision_approved','Revision '.$expectedVersion.' approved with its exact copy, media and destinations.',$userId);
        db()->commit();return $revision;
    } catch(Throwable $e) { if(db()->inTransaction())db()->rollBack();throw $e; }
}

function publishing_schedule(int $packageId, int $revisionId, string $localTime, int $userId): int {
    $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$localTime,new DateTimeZone((string)app_config('timezone','Africa/Nairobi')));
    if (!$date||$date->format('Y-m-d\TH:i')!==$localTime||$date->getTimestamp()<time()-60) throw new RuntimeException('Choose a valid future release time.');
    $utc=$date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    db()->beginTransaction();
    try {
        $st=db()->prepare('SELECT r.*,p.approved_revision_id FROM publishing_revisions r JOIN publishing_packages p ON p.id=r.package_id WHERE r.id=? AND r.package_id=? FOR UPDATE');$st->execute([$revisionId,$packageId]);$revision=$st->fetch();
        if (!$revision||(int)$revision['approved_revision_id']!==$revisionId) throw new RuntimeException('Approve the current draft before scheduling.');
        $snapshot=publishing_decode($revision['snapshot_json']);$count=0;
        foreach($snapshot['destinations'] as $slug=>$destination) {
            $channel=publishing_channel($slug);$key=hash('sha256',$revisionId.':'.$channel['id'].':'.$revision['content_hash']);
            $st=db()->prepare("INSERT IGNORE INTO publishing_jobs(revision_id,channel_id,connection_id,connection_external_id,destination_json,job_key,method,status,scheduled_at) VALUES(?,?,?,?,?,?,?,'scheduled',?)");
            $st->execute([$revisionId,$channel['id'],$destination['connection_id']??null,$destination['connection_external_id']??null,publishing_json($destination),$key,$destination['method'],$utc]);$count+=$st->rowCount();
        }
        if ($count) publishing_event($packageId,null,'revision_scheduled','Approved revision scheduled for '.publishing_local_time($utc).'.',$userId);
        db()->commit();return $count;
    } catch(Throwable $e) { if(db()->inTransaction())db()->rollBack();throw $e; }
}

function publishing_job_update(int $id, array $fields): void {
    $allowed=['status','locked_at','next_check_at','remote_id','external_url','provider_state_json','last_error','completed_at'];$set=[];$values=[];
    foreach($fields as $key=>$value) { if(!in_array($key,$allowed,true))throw new LogicException('Unexpected job field.');$set[]=$key.'=?';$values[]=$value; }
    if (!$set) return;
    $values[]=$id;db()->prepare('UPDATE publishing_jobs SET '.implode(',',$set).' WHERE id=?')->execute($values);
}

function publishing_job_connection(array $job): array {
    $st=db()->prepare('SELECT * FROM publishing_connections WHERE id=?');$st->execute([$job['connection_id']]);$connection=$st->fetch();
    $destination=publishing_decode($job['destination_json']);
    if (!$connection||$connection['status']!=='connected') throw new PublishingRemoteException('Reconnect the approved account before continuing.',401);
    if ((string)$connection['external_id']!==(string)$job['connection_external_id']||(string)$connection['selected_resource']!==(string)$destination['resource']) throw new RuntimeException('The destination changed after approval. Approve a new revision for the new account.');
    return $connection;
}

/** One caller at a time, guarded by a database advisory lock across every PHP host.
 * A crashed/incomplete mutation is never automatically replayed. The absence of a
 * provider idempotency API is a material limit; local deduplication cannot prove
 * that a timed-out request did not publish remotely.
 */
function publishing_work(int $limit=10): array {
    $limit=max(1,min(50,$limit));$result=['processed'=>0,'published'=>0,'assisted'=>0,'attention'=>0,'checked'=>0];
    if ((int)db()->query("SELECT GET_LOCK('waithaka_publishing_worker',0)")->fetchColumn()!==1) return $result+['busy'=>true];
    try {
        db()->exec("UPDATE publishing_jobs SET status='ambiguous',last_error='A worker stopped before recording a confirmed result. Check the platform before creating another post.',locked_at=NULL WHERE status='processing' AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)");
        $jobs=db()->query("SELECT j.*,r.snapshot_json,r.content_hash,r.package_id,p.created_by FROM publishing_jobs j JOIN publishing_revisions r ON r.id=j.revision_id JOIN publishing_packages p ON p.id=r.package_id WHERE j.status='scheduled' AND j.scheduled_at<=UTC_TIMESTAMP() ORDER BY j.scheduled_at,j.id LIMIT ".$limit)->fetchAll();
        foreach($jobs as $job) {
            $claim=db()->prepare("UPDATE publishing_jobs SET status='processing',locked_at=UTC_TIMESTAMP(),attempts=attempts+1 WHERE id=? AND status='scheduled'");$claim->execute([$job['id']]);if($claim->rowCount()!==1)continue;
            $result['processed']++;
            $providerEntered=false;$confirmedFields=null;
            try {
                if (!hash_equals($job['content_hash'],hash('sha256',$job['snapshot_json']))) throw new RuntimeException('The approved revision integrity check failed.');
                $snapshot=publishing_decode($job['snapshot_json']);
                if (!empty($snapshot['source_post_id'])) {
                    $st=db()->prepare('SELECT p.id FROM posts p WHERE p.id=? AND '.published_where());$st->execute([$snapshot['source_post_id']]);
                    if (!$st->fetchColumn()) throw new RuntimeException('The linked article is not public yet. Publish it before retrying distribution.');
                }
                if ($job['method']==='manual') {
                    $fields=['status'=>'assisted'];
                    publishing_job_update((int)$job['id'],$fields);
                    if (function_exists('publishing_send_reminder') && publishing_send_reminder($job,$snapshot)) $fields['status']='reminder_queued';
                    $result['assisted']++;
                } else {
                    $connection=publishing_job_connection($job);
                    $providerEntered=true;
                    $fields=$job['method']==='buffer'?publishing_buffer_deliver($job,$snapshot,$connection):publishing_direct_deliver($job,$snapshot,$connection);
                }
                if ($fields['status']==='published') { $fields['completed_at']=gmdate('Y-m-d H:i:s');$result['published']++; }
                $fields['locked_at']=null;$confirmedFields=$fields;publishing_job_update((int)$job['id'],$fields);
                try { publishing_event((int)$job['package_id'],(int)$job['id'],'delivery_'.$fields['status'],'Delivery state: '.publishing_state_label($fields['status']).'.'); } catch (Throwable $eventError) { error_log('Publishing delivery succeeded but its audit event could not be saved.'); }
            } catch(Throwable $e) {
                // Preserve a confirmed remote result if a subsequent local write failed.
                if($confirmedFields!==null){publishing_job_update((int)$job['id'],$confirmedFields);continue;}
                $unknown=($e instanceof PublishingRemoteException&&$e->ambiguous)||($providerEntered&&!($e instanceof PublishingRemoteException));
                $state=$unknown?'ambiguous':($e instanceof PublishingRemoteException&&$e->httpStatus===401?'reconnect':'failed');
                publishing_job_update((int)$job['id'],['status'=>$state,'last_error'=>mb_substr($e->getMessage(),0,500),'locked_at'=>null]);
                if ($state==='reconnect'&&!empty($job['connection_id'])) db()->prepare("UPDATE publishing_connections SET status='reconnect' WHERE id=? AND external_id=? AND status='connected'")->execute([$job['connection_id'],$job['connection_external_id']]);
                publishing_event((int)$job['package_id'],(int)$job['id'],'delivery_'.$state,mb_substr($e->getMessage(),0,500));$result['attention']++;
            }
        }
        $polls=db()->query("SELECT j.*,r.package_id,r.snapshot_json,r.content_hash FROM publishing_jobs j JOIN publishing_revisions r ON r.id=j.revision_id WHERE j.status IN ('delivered','reminder_sent') AND j.method<>'manual' AND (j.next_check_at IS NULL OR j.next_check_at<=UTC_TIMESTAMP()) ORDER BY j.next_check_at,j.id LIMIT ".$limit)->fetchAll();
        foreach($polls as $job) {
            $providerEntered=false;$confirmedFields=null;
            try {
                $connection=publishing_job_connection($job);
                $providerEntered=true;
                $fields=$job['method']==='buffer'?publishing_buffer_check($job,$connection):publishing_direct_check($job,$connection);
                if ($fields['status']==='published') $fields['completed_at']=gmdate('Y-m-d H:i:s');
                $fields['locked_at']=null;$confirmedFields=$fields;publishing_job_update((int)$job['id'],$fields);$result['checked']++;
            } catch(Throwable $e) {
                if($confirmedFields!==null){publishing_job_update((int)$job['id'],$confirmedFields);continue;}
                $ambiguous=($e instanceof PublishingRemoteException&&$e->ambiguous)||($providerEntered&&$job['method']==='direct'&&!($e instanceof PublishingRemoteException));
                $fields=['next_check_at'=>gmdate('Y-m-d H:i:s',time()+1800),'last_error'=>mb_substr($e->getMessage(),0,500),'locked_at'=>null];
                if ($ambiguous) $fields['status']='ambiguous';
                elseif ($e instanceof PublishingRemoteException&&$e->httpStatus===401) {
                    $fields['status']='reconnect';
                    db()->prepare("UPDATE publishing_connections SET status='reconnect' WHERE id=? AND external_id=? AND status='connected'")->execute([$job['connection_id'],$job['connection_external_id']]);
                }
                elseif (!($e instanceof PublishingRemoteException)||($e->httpStatus>=400&&$e->httpStatus<500)) $fields['status']='failed';
                publishing_job_update((int)$job['id'],$fields);$result['attention']++;
            }
        }
        return $result;
    } finally { db()->query("SELECT RELEASE_LOCK('waithaka_publishing_worker')"); }
}

