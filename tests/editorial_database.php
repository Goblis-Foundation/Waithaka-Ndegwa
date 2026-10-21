<?php
declare(strict_types=1);
/** Synthetic editorial/media integration. No provider, mail or external HTTP calls. */
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||getenv('WK_TEST_ALLOW_WRITES')!=='1'||!in_array(getenv('DB_HOST'),['127.0.0.1','::1'],true)||!preg_match('/^waithaka_test_[a-z0-9_]+_test$/D',(string)getenv('DB_DATABASE'))){fwrite(STDERR,"Use a fresh explicitly disposable loopback test database.\n");exit(2);}
require dirname(__DIR__).'/app/bootstrap.php';restore_exception_handler();
if((string)db()->query('SELECT DATABASE()')->fetchColumn()!==getenv('DB_DATABASE'))throw new RuntimeException('Unexpected fixture database.');
$checks=0;$actor=0;$postId=0;$seasonId=0;$owned=[];$assets=[];$paths=[];$sources=[];$prefix='ed-'.bin2hex(random_bytes(6));
$CONFIG['media']['driver']='local';$CONFIG['base_url']='https://www.waithakandegwa.com';
function ed_check(bool $ok,string $label): void {if(!$ok)throw new RuntimeException('FAIL: '.$label);$GLOBALS['checks']++;}
function ed_reject(callable $action,string $label,string $class=EditorialValidationException::class): void {try{$action();}catch(Throwable $e){if(!$e instanceof $class)throw $e;ed_check(true,$label);return;}throw new RuntimeException('FAIL: '.$label);}
function ed_edit(int $id,array $changes=[]): array {$row=editorial_writer_item($id);return array_merge(editorial_decode($row['draft_json']),['row_version'=>$row['row_version']],$changes);}
function ed_visible(string $needle): bool {return str_contains(editorial_json(editorial_search_documents()),$needle);}
try{
    db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Synthetic editorial writer',$prefix.'@example.invalid',password_hash(bin2hex(random_bytes(12)),PASSWORD_DEFAULT)]);$actor=(int)db()->lastInsertId();
    $input=['title'=>'Synthetic resource','slug'=>$prefix.'-resource','summary'=>'Synthetic checklist for an isolated test.','content_html'=>'<h2>Question</h2><p>Record the question and the evidence needed before drawing a conclusion.</p>','published_at'=>date('Y-m-d H:i:s',time()-3600),'source_notes'=>'PRIVATE_SOURCE_CANARY','writer_note'=>'PRIVATE_PLAN_CANARY'];
    $id=editorial_save_item('resource',$input,null,$actor);$owned[]=$id;
    ed_check(editorial_public_item('resource',$input['slug'])===null&&!ed_visible($input['slug']),'A private resource is absent from public lookup and unified search');
    ed_check(!str_contains(editorial_json(editorial_sitemap_entries()),$input['slug']),'Private resources are absent from the sitemap');
    ed_check(!str_contains(editorial_writer_item($id)['draft_json'],'PRIVATE_SOURCE_CANARY'),'Private article fields are not copied into resource data');
    ed_reject(fn()=>editorial_save_item('resource',ed_edit($id),$id,$actor,'publish'),'Publishing requires explicit approval');
    editorial_save_item('resource',ed_edit($id,['publish_ack'=>'1']),$id,$actor,'publish');$public=editorial_public_item('resource',$input['slug']);
    ed_check($public!==null&&$public['title']==='Synthetic resource'&&ed_visible($input['slug']),'An explicitly published due snapshot becomes publicly discoverable');
    $snapshot=editorial_writer_item($id)['public_json'];$modified=$public['updated_at'];$version=editorial_writer_item($id)['row_version'];
    editorial_save_item('resource',ed_edit($id,['title'=>'PRIVATE_DRAFT_CANARY','content_html'=>'<p>Private rewrite with useful unapproved draft text.</p>']),$id,$actor);
    ed_check(editorial_writer_item($id)['public_json']===$snapshot&&editorial_public_item('resource',$input['slug'])['updated_at']===$modified,'Saving a working copy changes neither the approved snapshot nor its public date');
    ed_check(!ed_visible('PRIVATE_DRAFT_CANARY')&&!str_contains(editorial_download_text(editorial_public_item('resource',$input['slug'])),'PRIVATE_DRAFT_CANARY'),'Search and downloads use the approved public version');
    ed_reject(fn()=>editorial_save_item('resource',array_merge($input,['row_version'=>$version,'publish_ack'=>'1']),$id,$actor,'publish'),'A stale review cannot overwrite a newer working copy',EditorialConflictException::class);
    ed_reject(fn()=>editorial_save_item('resource',ed_edit($id,['slug'=>$prefix.'-moved']),$id,$actor),'Published URLs remain stable');
    editorial_save_item('resource',ed_edit($id,['title'=>'Reviewed resource update','publish_ack'=>'1']),$id,$actor,'publish');
    ed_check(editorial_public_item('resource',$input['slug'])['title']==='Reviewed resource update','A fresh explicit publication replaces the public snapshot');
    editorial_save_item('resource',['row_version'=>editorial_writer_item($id)['row_version']],$id,$actor,'archive');
    ed_check(editorial_public_item('resource',$input['slug'])===null&&!ed_visible($input['slug']),'Archiving removes a public resource and search entry');
    editorial_save_item('resource',ed_edit($id,['title'=>'Private after archive']),$id,$actor);
    ed_check(editorial_writer_item($id)['publication_status']==='archived','A draft save does not republish an archived item');
    $future=array_merge($input,['slug'=>$prefix.'-scheduled','title'=>'Scheduled approved resource','published_at'=>date('Y-m-d H:i:s',time()+86400),'publish_ack'=>'1']);
    $scheduled=editorial_save_item('resource',$future,null,$actor,'publish');$owned[]=$scheduled;
    ed_check(editorial_public_item('resource',$future['slug'])===null&&!ed_visible($future['slug']),'Future approved content is absent from all public collections');
    editorial_save_item('resource',ed_edit($scheduled,['title'=>'SCHEDULED_PRIVATE_CANARY']),$scheduled,$actor);
    db()->prepare('UPDATE editorial_items SET published_at=? WHERE id=?')->execute([date('Y-m-d H:i:s',time()-10),$scheduled]);
    ed_check(editorial_public_item('resource',$future['slug'])['title']==='Scheduled approved resource','When scheduled time arrives, the approved snapshot appears, not later private edits');

    db()->prepare('INSERT INTO journey_seasons(slug,title,venture_name,summary,baseline,phase,publication_status,published_at,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$prefix.'-journey','Synthetic journey','Synthetic fixture venture','Only a fixture','No actual venture asserted','planning','draft',editorial_now(),$actor,editorial_now(),editorial_now()]);$seasonId=(int)db()->lastInsertId();
    $experiment=['title'=>'Synthetic planned test','slug'=>$prefix.'-experiment','summary'=>'A planned experiment, not an actual result.','season_id'=>$seasonId,'experiment_state'=>'planned','question'=>'What should be measured?','method'=>'Record the starting observation and its limits.','published_at'=>date('Y-m-d H:i:s',time()-60),'publish_ack'=>'1'];
    $experimentId=editorial_save_item('experiment',$experiment,null,$actor,'publish');$owned[]=$experimentId;
    ed_check(editorial_public_experiments($seasonId)===[],'A public experiment cannot expose a private journey');
    db()->prepare("UPDATE journey_seasons SET publication_status='published' WHERE id=?")->execute([$seasonId]);
    ed_check(count(editorial_public_experiments($seasonId))===1&&editorial_public_experiments($seasonId)[0]['findings']==='','A released planned test has no invented findings');
    ed_reject(fn()=>editorial_save_item('experiment',ed_edit($experimentId,['experiment_state'=>'concluded','publish_ack'=>'1']),$experimentId,$actor,'publish'),'Concluded tests require actual findings and a decision');
    editorial_save_item('experiment',ed_edit($experimentId,['findings'=>'UNAPPROVED_FINDINGS']),$experimentId,$actor);
    ed_check(!str_contains(editorial_json(editorial_public_experiments($seasonId)),'UNAPPROVED_FINDINGS'),'Experiment draft findings stay private');
    db()->prepare('UPDATE journey_seasons SET published_at=? WHERE id=?')->execute([date('Y-m-d H:i:s',time()+86400),$seasonId]);
    ed_check(editorial_public_experiments($seasonId)===[],'A future parent journey blocks experiment exposure');
    db()->prepare('UPDATE journey_seasons SET published_at=? WHERE id=?')->execute([date('Y-m-d H:i:s',time()-60),$seasonId]);

    db()->prepare('INSERT INTO posts(user_id,title,slug,content_html,writer_note,source_notes,status,published_at) VALUES(?,?,?,?,?,?,?,?)')->execute([$actor,'Synthetic article',$prefix.'-article','<h2>Question</h2><p>Body.</p>','PRIVATE_PLAN_CANARY','PRIVATE_SOURCE_CANARY','draft',editorial_now()]);$postId=(int)db()->lastInsertId();
    $evidence=['evidence'=>[['label'=>'Reviewed observation','basis'=>'firsthand','summary'=>'Synthetic public description only.','limitations'=>'This is an isolated test fixture.']],'update_kind'=>'none','update_note'=>'','source_notes'=>'PRIVATE_SOURCE_CANARY'];
    editorial_save_post_meta($postId,$evidence,$actor);
    ed_check(editorial_public_post($postId)===['evidence'=>[],'updates'=>[]],'Private articles cannot reveal even prepared evidence');
    ed_reject(fn()=>editorial_save_post_meta($postId,array_merge($evidence,['row_version'=>1,'publish_ack'=>'1']),$actor,'publish'),'Evidence cannot be published before its article');
    db()->prepare("UPDATE posts SET status='published',published_at=? WHERE id=?")->execute([date('Y-m-d H:i:s',time()-3600),$postId]);
    ed_check(editorial_public_post($postId)['evidence']===[],'Publishing an article does not release evidence drafts automatically');
    editorial_save_post_meta($postId,array_merge($evidence,['row_version'=>1,'publish_ack'=>'1']),$actor,'publish');$publishedEvidence=editorial_public_post($postId);
    ed_check(count($publishedEvidence['evidence'])===1&&!str_contains(editorial_json($publishedEvidence),'PRIVATE_'),'Explicit evidence publication contains no private research fields');
    $article=['id'=>$postId,'status'=>'published','published_at'=>date('Y-m-d H:i:s',time()-3600)];
    ed_check(editorial_article_modified($article)===null,'First evidence release without a meaningful change note does not invent a modification date');
    $next=$evidence;$next['evidence'][0]['summary']='UNPUBLISHED_CARD_CANARY';$next['row_version']=2;editorial_save_post_meta($postId,$next,$actor);
    ed_check(!str_contains(editorial_json(editorial_public_post($postId)),'UNPUBLISHED_CARD_CANARY')&&editorial_article_modified($article)===null,'Evidence draft saves affect neither public cards nor modification dates');
    ed_reject(fn()=>editorial_save_post_meta($postId,array_merge($next,['row_version'=>3,'publish_ack'=>'1']),$actor,'publish'),'Replacing public evidence requires an explanation');
    $next['evidence'][0]['summary']='Reviewed corrected observation';$next['row_version']=3;$next['publish_ack']='1';$next['update_kind']='correction';$next['update_note']='The earlier description omitted the small size of the sample.';
    editorial_save_post_meta($postId,$next,$actor,'publish');$correction=editorial_public_post($postId);
    ed_check(count($correction['updates'])===1&&$correction['updates'][0]['update_kind']==='correction','Correction publication appends a visible dated note');
    ed_check(editorial_article_modified($article)===$correction['updates'][0]['published_at'],'Only the explicit meaningful note drives article modified time');
    $cleared=editorial_post_draft($postId);ed_check($cleared['draft']['update_kind']==='none'&&$cleared['draft']['update_note']==='','A published correction is removed from pending draft fields to prevent accidental duplicates');
    editorial_save_post_meta($postId,array_merge($cleared['draft'],['row_version'=>$cleared['row_version'],'publish_ack'=>'1']),$actor,'publish');
    ed_check(count(editorial_public_post($postId)['updates'])===1,'Publishing unchanged evidence does not duplicate the correction history');
    ed_reject(fn()=>editorial_save_post_meta($postId,$next,$actor,'publish'),'Stale approval cannot append a duplicate correction',EditorialConflictException::class);
    db()->prepare("UPDATE posts SET status='archived' WHERE id=?")->execute([$postId]);
    ed_check(editorial_public_post($postId)===['evidence'=>[],'updates'=>[]]&&editorial_article_modified($article)===null,'Archiving the article hides cards, history and public modified date');

    $image=tempnam(sys_get_temp_dir(),'ed-image-');$sources[]=$image;$canvas=imagecreatetruecolor(4,3);imagepng($canvas,$image);imagedestroy($canvas);
    $video=tempnam(sys_get_temp_dir(),'ed-video-');$sources[]=$video;file_put_contents($video,hex2bin('000000186674797069736f6d0000020069736f6d69736f32').str_repeat('synthetic-container-only-',128));
    $imageId=media_library_import($image,'synthetic.png',['title'=>'Synthetic thumbnail','visibility'=>'public','public_ack'=>'1'],$actor);$assets[]=$imageId;$paths[]=media_library_local_path(media_library_asset($imageId));
    $privateVideo=media_library_import($video,'synthetic.mp4',['title'=>'Synthetic private video','visibility'=>'private'],$actor);$assets[]=$privateVideo;$paths[]=media_library_local_path(media_library_asset($privateVideo));
    $publicVideo=media_library_import($video,'synthetic.mp4',['title'=>'Synthetic public video','visibility'=>'public','public_ack'=>'1'],$actor);$assets[]=$publicVideo;$publicVideoPath=media_library_local_path(media_library_asset($publicVideo));$paths[]=$publicVideoPath;
    $watch=['title'=>'Synthetic watch page','slug'=>$prefix.'-watch','summary'=>'Only a synthetic media integration fixture.','video_asset_id'=>$privateVideo,'thumbnail_asset_id'=>$imageId,'duration_seconds'=>'42','upload_date'=>date('Y-m-d H:i:s',time()-7200),'transcript'=>'This synthetic test transcript contains no actual founder claims or venture results.','rights_confirmed'=>'1','publish_ack'=>'1','published_at'=>date('Y-m-d H:i:s',time()-3600)];
    ed_reject(fn()=>editorial_save_item('watch',$watch,null,$actor,'publish'),'Private library video cannot be exposed by watch publication');
    $watch['video_asset_id']=$publicVideo;$watch['video_asset']=['public_url'=>'https://attacker.example/private'];
    $watchId=editorial_save_item('watch',$watch,null,$actor,'publish');$owned[]=$watchId;$watchPublic=editorial_public_item('watch',$watch['slug']);
    ed_check($watchPublic!==null&&$watchPublic['video_asset']['public_url']===media_library_asset($publicVideo)['public_url'],'Video publication freezes server-owned media, ignoring client-supplied asset metadata');
    $schema=editorial_watch_schema($watchPublic);
    ed_check($schema['@type']==='VideoObject'&&$schema['contentUrl']===$watchPublic['video_asset']['public_url']&&$schema['duration']==='PT42S','VideoObject accurately describes the approved owned file and actual duration');
    ed_check($schema['thumbnailUrl']===[$watchPublic['thumbnail_asset']['public_url']]&&$schema['transcript']===$watchPublic['transcript']&&!isset($schema['embedUrl']),'The schema uses the stable thumbnail and transcript without inventing a player endpoint');
    ed_check(ed_visible($watch['slug'])&&str_contains(editorial_json(editorial_sitemap_entries()),$watch['slug']),'Released valid watch pages appear in discovery');
    editorial_save_item('watch',ed_edit($watchId,['transcript'=>'PRIVATE_TRANSCRIPT_CANARY with enough draft text to remain a complete sentence.']),$watchId,$actor);
    ed_check(!ed_visible('PRIVATE_TRANSCRIPT_CANARY'),'A video transcript draft cannot leak through search');
    $oldUrl=media_library_asset($publicVideo)['public_url'];db()->prepare('UPDATE media_assets SET public_url=? WHERE id=?')->execute(['https://different.example/file.mp4',$publicVideo]);
    ed_check(editorial_public_item('watch',$watch['slug'])===null&&editorial_watch_schema($watchPublic)===[]&&!ed_visible($watch['slug']),'Changed public-media bindings disable playback pages, schema and discovery');
    db()->prepare('UPDATE media_assets SET public_url=? WHERE id=?')->execute([$oldUrl,$publicVideo]);
    media_library_archive($publicVideo,$actor);
    ed_check(editorial_public_item('watch',$watch['slug'])!==null,'Archiving library selection preserves a previously approved immutable public watch file');
    ed_reject(fn()=>editorial_save_item('watch',ed_edit($watchId,['publish_ack'=>'1']),$watchId,$actor,'publish'),'An archived asset cannot be approved for a new public revision',MediaLibraryException::class);
    unlink($publicVideoPath);
    ed_check(editorial_public_item('watch',$watch['slug'])===null&&editorial_watch_schema($watchPublic)===[],'A missing owned local video cannot retain valid VideoObject markup');
    echo 'Editorial database checks passed: '.$checks."\n";
} finally {
    if(db()->inTransaction())db()->rollBack();
    if($owned){$q=db()->prepare('DELETE FROM editorial_items WHERE id IN ('.implode(',',array_fill(0,count($owned),'?')).')');$q->execute($owned);}
    if($postId)db()->prepare('DELETE FROM posts WHERE id=?')->execute([$postId]);
    if($seasonId)db()->prepare('DELETE FROM journey_seasons WHERE id=?')->execute([$seasonId]);
    if($assets){$q=db()->prepare('DELETE FROM media_assets WHERE id IN ('.implode(',',array_fill(0,count($assets),'?')).')');$q->execute($assets);}
    if($actor){db()->prepare('DELETE FROM journey_audit_events WHERE actor_id=?')->execute([$actor]);db()->prepare('DELETE FROM users WHERE id=?')->execute([$actor]);}
    foreach(array_merge($sources,$paths) as $file)if(is_file($file))unlink($file);
}
