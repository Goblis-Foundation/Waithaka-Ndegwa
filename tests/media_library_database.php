<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||getenv('WK_TEST_ALLOW_WRITES')!=='1'||!preg_match('/^waithaka_test_[a-z0-9_]+_test$/D',(string)getenv('DB_DATABASE'))){fwrite(STDERR,"Use an isolated, freshly prepared test database.\n");exit(2);}
require dirname(__DIR__).'/app/bootstrap.php';
$checks=0;$owned=[];$packages=[];$paths=[];$staged=[];$source=tempnam(sys_get_temp_dir(),'wk-image-fixture-');
function media_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;}
function media_reject(callable $operation,string $label):void{try{$operation();}catch(MediaLibraryException|RuntimeException $e){$GLOBALS['checks']++;return;}throw new RuntimeException($label);}
function media_binding_reject(callable $operation,string $label):void{
    try{$operation();}catch(MediaLibraryException $e){media_check(true,$label);return;}
    throw new RuntimeException($label);
}
/** The SDK mock consumes a closed queue; no credentials lookup or HTTP handler is used. */
function media_s3_fixture(array $responses):array{
    $record=(object)['commands'=>[]];$mock=new \Aws\MockHandler($responses);
    $client=new \Aws\S3\S3Client(['version'=>'2006-03-01','region'=>'us-east-1','retries'=>0,
        'credentials'=>['key'=>'local-fixture-only','secret'=>'local-fixture-only'],
        'handler'=>static function($command,$request)use($record,$mock){$record->commands[]=['name'=>$command->getName(),'arguments'=>$command->toArray()];return $mock($command,$request);}]);
    return [$client,$record];
}
function media_editor_document(array $draft,?array $package=null):DOMXPath{
    $channels=publishing_channels();$revision=null;$jobs=[];$events=[];
    ob_start();
    try{require dirname(__DIR__).'/views/write/publishing_editor.php';$html=(string)ob_get_contents();}
    finally{ob_end_clean();}
    $document=new DOMDocument();$before=libxml_use_internal_errors(true);
    try{$document->loadHTML($html);}finally{libxml_clear_errors();libxml_use_internal_errors($before);}
    return new DOMXPath($document);
}
function media_editor_selection(DOMXPath $form,string $slug):array{
    $select=$form->query('//select[@id="media-asset-'.$slug.'"]')->item(0);
    if(!$select instanceof DOMElement)throw new RuntimeException('The media field is missing from the actual editor.');
    $options=$form->query('option',$select);$selected=$form->query('option[@selected]',$select);
    $option=$selected->item(0)??$options->item(0);
    if(!$option instanceof DOMElement)throw new RuntimeException('The media field has no options.');
    return ['id'=>(int)$option->getAttribute('value'),'disabled'=>$select->hasAttribute('disabled')||$option->hasAttribute('disabled'),
        'label'=>$option->textContent,'warning'=>$form->query('//*[@id="media-asset-state-'.$slug.'"]')->item(0)?->textContent??'',
        'options'=>array_map(static fn($item)=>(int)$item->getAttribute('value'),iterator_to_array($options))];
}
try {
    $image=imagecreatetruecolor(2,2);imagefill($image,0,0,imagecolorallocate($image,30,95,70));imagepng($image,$source);imagedestroy($image);
    $CONFIG['media']['driver']='local';$CONFIG['base_url']='https://www.waithakandegwa.com';
    $original=(string)file_get_contents($source);
    $meta=media_library_inspect($source,1024*1024);
    media_check($meta['mime']==='image/png'&&$meta['kind']==='image'&&$meta['bytes']===strlen($original),'The real uploaded bytes must determine the media type and size.');
    media_reject(fn()=>media_library_inspect($source,1),'The cap must reject media before storage.');
    $id=media_library_import($source,'../../misleading-name.php',['title'=>'Private fixture','visibility'=>'private'],1);$owned[]=$id;
    $asset=media_library_asset($id);$paths[]=media_library_local_path($asset);
    media_check($asset['original_name']==='misleading-name.php'&&$asset['extension']==='png'&&str_ends_with($asset['storage_key'],'.png'),'A user filename must never select a storage path or executable extension.');
    media_check($asset['public_url']===''&&$asset['visibility']==='private','Private uploads must not expose a public URL.');
    media_check((fileperms($paths[0])&0777)===0600,'Private local originals must have private file permissions.');
    $frozen=media_library_approved_asset($id);$copy=media_library_materialize($frozen,media_library_max_bytes());$staged[]=$copy;
    media_check($copy['sha256']===$meta['sha256']&&file_get_contents($copy['path'])===$original,'Delivery must stage the exact approved bytes.');
    media_check($copy['path']!==$paths[0]&&(fileperms($copy['path'])&0777)===0400,'The provider must receive a separate read-only staging copy.');
    file_put_contents($source,'new source bytes');
    media_check(file_get_contents($copy['path'])===$original&&file_get_contents($paths[0])===$original,'Changing the browser upload temporary file must not change approved storage.');
    $bad=$frozen;$bad['key']='../../app/config.php';media_reject(fn()=>media_library_materialize($bad,media_library_max_bytes()),'A modified path must fail the frozen reference check.');
    $bad=$frozen;$bad['bytes']=(string)$bad['bytes'];media_reject(fn()=>media_library_materialize($bad,media_library_max_bytes()),'Approval metadata types must remain exact.');
    media_reject(fn()=>media_library_materialize($frozen,1),'Delivery-specific size caps must be enforced before reading data.');
    $changed=$original;$changed[strlen($changed)-1]=chr(ord($changed[strlen($changed)-1])^1);
    file_put_contents($paths[0],$changed);
    media_reject(fn()=>media_library_materialize($frozen,media_library_max_bytes()),'A same-size change to stored media must fail the approval hash check.');
    media_check(file_get_contents($copy['path'])===$original,'A previously staged copy must remain stable even if storage is changed.');
    file_put_contents($paths[0],$original);
    unlink($paths[0]);symlink($source,$paths[0]);
    media_reject(fn()=>media_library_materialize($frozen,media_library_max_bytes()),'A symlink must not escape the owned media tree.');
    unlink($paths[0]);file_put_contents($paths[0],$original);chmod($paths[0],0600);
    media_library_archive($id,1);
    media_reject(fn()=>media_library_approved_asset($id),'Archived assets cannot be selected for a new approval.');
    $archivedCopy=media_library_materialize($frozen,media_library_max_bytes());$staged[]=$archivedCopy;
    media_check(file_get_contents($archivedCopy['path'])===$original,'Archiving must preserve delivery of an existing immutable approval.');
    media_check(!in_array($id,array_map('intval',array_column(media_library_choices(),'id')),true),'Archived assets must disappear from new selections.');
    file_put_contents($source,$original);
    media_reject(fn()=>media_library_import($source,'fixture.png',['title'=>'Public fixture','visibility'=>'public'],1),'A public upload must require explicit access acknowledgment.');
    $public=media_library_import($source,'fixture.png',['title'=>'Public fixture','visibility'=>'public','public_ack'=>'1'],1);$owned[]=$public;$publicAsset=media_library_asset($public);$paths[]=media_library_local_path($publicAsset);
    media_check(str_starts_with($publicAsset['public_url'],'https://www.waithakandegwa.com/assets/uploads/'),'Public assets must use the configured canonical media origin.');
    media_check(!str_contains($publicAsset['public_url'],'token')&&!str_contains($publicAsset['public_url'],'storage'),'Public asset URLs must not contain private capabilities.');
    $publicFrozen=media_library_approved_asset($public);
    media_library_validate_public_asset($publicFrozen,1024*1024);media_check(true,'The current owned public local file must pass provider-fetch preflight.');
    media_binding_reject(fn()=>media_library_validate_public_asset($frozen,1024*1024),'Private approved media cannot be handed to a public-URL provider.');
    media_binding_reject(fn()=>media_library_validate_public_asset($publicFrozen,1),'Public preflight must enforce the provider size bound.');
    $publicPath=media_library_local_path($publicAsset);
    try{file_put_contents($publicPath,$changed);media_binding_reject(fn()=>media_library_validate_public_asset($publicFrozen,1024*1024),'A same-size current public-file overwrite must fail the actual byte hash.');}
    finally{file_put_contents($publicPath,$original);}
    $urlUpdate=db()->prepare('UPDATE media_assets SET public_url=? WHERE id=?');
    try{
        $urlUpdate->execute(['https://different-media.example/'.$publicAsset['storage_key'],$public]);$otherUrl=media_library_snapshot(media_library_asset($public));
        media_binding_reject(fn()=>media_library_validate_public_asset($otherUrl,1024*1024),'Even a DB-owned and frozen URL must map to the configured local object, without fetching that other URL.');
    }finally{$urlUpdate->execute([$publicAsset['public_url'],$public]);}
    try{$CONFIG['base_url']='https://changed-canonical.example';media_binding_reject(fn()=>media_library_validate_public_asset($publicFrozen,1024*1024),'Changing the configured public domain must invalidate an older URL binding.');}
    finally{$CONFIG['base_url']='https://www.waithakandegwa.com';}

    // Metadata-only fixtures put the real selected asset outside the 200-item menu.
    // Listing choices must not fetch or create any of these media objects.
    $insert=db()->prepare("INSERT INTO media_assets(public_id,title,original_name,kind,mime,extension,bytes,sha256,driver,storage_key,visibility,public_url,created_by,created_at) VALUES(?,?,'recent.png','image','image/png','png',?,?,'local',?,'public',?,1,UTC_TIMESTAMP())");
    for($i=0;$i<200;$i++){
        $publicId=bin2hex(random_bytes(16));$key='assets/uploads/'.gmdate('Y/m').'/'.$publicId.'.png';
        $insert->execute([$publicId,'Recent option '.$i,$meta['bytes'],$meta['sha256'],$key,base_url().'/'.$key]);$owned[]=(int)db()->lastInsertId();
    }
    $recent=media_library_choices();
    media_check(count($recent)===200&&!in_array($public,array_map('intval',array_column($recent,'id')),true),'The recent options fixture must exclude the older real asset.');
    $preserved=media_library_choices([$public,$id,$public]);
    media_check(count($preserved)===202&&count(array_unique(array_column($preserved,'id')))===202,'Saved ready and archived IDs must be appended once without expanding the ordinary recent list.');
    $draft=publishing_default_draft();$draft['title']='Preserve owned media fixture';
    $draft['variants']['x']=array_merge($draft['variants']['x'],['enabled'=>true,'text'=>'A reviewed image.','alt_text'=>'A green fixture image.','media_asset_id'=>$public]);
    $packageId=publishing_save_package($draft,1);$packages[]=$packageId;$package=publishing_package($packageId);$saved=publishing_decode($package['draft_json']);
    $selection=media_editor_selection(media_editor_document($saved,$package),'x');
    media_check($selection['id']===$public&&!$selection['disabled']&&$selection['warning']==='','The actual editor must submit the selected old asset, not its default no-file option.');
    $saved['variants']['x']['media_asset_id']=$selection['id'];
    publishing_save_package($saved,1,$packageId,(int)$package['draft_version']);$package=publishing_package($packageId);$saved=publishing_decode($package['draft_json']);
    media_check($saved['variants']['x']['media_asset_id']===$public,'An ordinary edit must retain the owned reference rather than downgrade it to a public URL.');
    $revision=publishing_approve($packageId,(int)$package['draft_version'],1);
    $query=db()->prepare('SELECT snapshot_json FROM publishing_revisions WHERE id=?');$query->execute([$revision]);$snapshotJson=(string)$query->fetchColumn();$snapshot=publishing_decode($snapshotJson);
    media_check($snapshot['variants']['x']['media_asset']['id']===$public&&$snapshot['variants']['x']['media_asset']['sha256']===$meta['sha256'],'An older ready selection must still receive an immutable owned-file approval.');

    $pendingId=publishing_save_package($saved,1);$packages[]=$pendingId;$pending=publishing_package($pendingId);
    media_library_archive($public,1);
    $archivedForm=media_editor_document($saved,$pending);$selection=media_editor_selection($archivedForm,'x');
    media_check($selection['id']===$public&&!$selection['disabled']&&str_contains($selection['label'],'Unavailable for new approvals')&&str_contains($selection['warning'],'before saving or approving'),'An archived selection must remain submitted and clearly require replacement or removal.');
    media_check(!in_array($public,media_editor_selection($archivedForm,'facebook')['options'],true),'An archived asset preserved for one channel must not become a new choice in other channels.');
    $saved['variants']['x']['media_asset_id']=$selection['id'];
    media_reject(fn()=>publishing_save_package($saved,1,$pendingId,(int)$pending['draft_version']),'Preserving an archived menu entry must not bypass the ordinary draft-save gate.');
    media_check(publishing_package($pendingId)['draft_json']===$pending['draft_json'],'A rejected archived selection must leave the saved draft intact.');
    media_reject(fn()=>publishing_approve($pendingId,(int)$pending['draft_version'],1),'Preserving an archived menu entry must never grant a new approval.');
    $query->execute([$revision]);media_check($query->fetchColumn()===$snapshotJson,'Archiving and rejected edits must not change an already approved revision.');

    $missingId=max($owned)+1000;$missing=$saved;$missing['variants']['x']['media_asset_id']=$missingId;
    $selection=media_editor_selection(media_editor_document($missing),'x');
    media_check($selection['id']===$missingId&&!$selection['disabled']&&str_contains($selection['label'],'Unavailable asset #'),'Even a missing stored reference must require an explicit removal rather than silently selecting zero.');
    media_reject(fn()=>publishing_save_package($missing,1),'The retained missing reference must still be rejected by the server.');

    $saved['variants']['x']['media_asset_id']=0;$saved['variants']['x']['media_url']='';
    publishing_save_package($saved,1,$pendingId,(int)$pending['draft_version']);$removed=publishing_decode(publishing_package($pendingId)['draft_json']);
    media_check($removed['variants']['x']['media_asset_id']===0&&$removed['variants']['x']['media_url']==='','An explicit no-file selection and cleared URL must still remove the draft media.');

    // Preserve an existing archived approval while proving that a historical S3
    // version alone is insufficient for a provider's unversioned public URL fetch.
    $savedMedia=$CONFIG['media'];$storageUpdate=db()->prepare('UPDATE media_assets SET driver=?,bucket=?,version_id=?,public_url=? WHERE id=?');
    try{
        $CONFIG['media']=array_merge($savedMedia,['driver'=>'s3','bucket'=>'waithaka-local-fixture-public','public_url'=>'https://media.waithakandegwa.com']);
        $storageUpdate->execute(['s3',$CONFIG['media']['bucket'],'fixture-approved-version',media_library_public_url($publicAsset['storage_key']),$public]);
        $s3Asset=media_library_snapshot(media_library_asset($public));
        $head=['VersionId'=>$s3Asset['version_id'],'ContentLength'=>$s3Asset['bytes'],'ContentType'=>$s3Asset['mime'],'Metadata'=>['sha256'=>$s3Asset['sha256']]];
        $body=static fn(string $bytes,?string $version=null)=>new \Aws\Result(['VersionId'=>$version??$s3Asset['version_id'],'ContentLength'=>strlen($bytes),'ContentType'=>'image/png','Body'=>\GuzzleHttp\Psr7\Utils::streamFor($bytes)]);
        [$client,$record]=media_s3_fixture([new \Aws\Result($head),$body($original),new \Aws\Result($head)]);
        media_library_validate_public_asset($s3Asset,1024*1024,$client);
        media_check(array_column($record->commands,'name')===['HeadObject','GetObject','HeadObject'],'Public S3 validation must bracket its actual-byte check with current-object observations.');
        media_check(!isset($record->commands[0]['arguments']['VersionId'])&&!isset($record->commands[2]['arguments']['VersionId'])&&$record->commands[1]['arguments']['VersionId']===$s3Asset['version_id'],'HEAD must inspect the latest object while GET verifies the approved immutable version.');
        media_check(count(array_filter($record->commands,static fn($command)=>$command['arguments']['Bucket']===$s3Asset['bucket']&&$command['arguments']['Key']===$s3Asset['key']))===3,'Every storage operation must target only the approved configured bucket and key.');

        [$client,$record]=media_s3_fixture([new \Aws\Result(array_merge($head,['VersionId'=>'replacement-version']))]);
        media_binding_reject(fn()=>media_library_validate_public_asset($s3Asset,1024*1024,$client),'A different latest version must fail even if an unchanged historical approved version still exists.');
        media_check(array_column($record->commands,'name')===['HeadObject'],'An overwritten public object must fail before downloading a historical version.');
        [$client]=media_s3_fixture([new \Aws\Result($head),$body($original),new \Aws\Result(array_merge($head,['VersionId'=>'replacement-during-read']))]);
        media_binding_reject(fn()=>media_library_validate_public_asset($s3Asset,1024*1024,$client),'An overwrite during the pinned byte read must be detected by the final latest-version check.');
        [$client]=media_s3_fixture([new \Aws\Result($head),$body($changed)]);
        media_binding_reject(fn()=>media_library_validate_public_asset($s3Asset,1024*1024,$client),'Correct-looking HEAD metadata must not substitute for hashing actual object bytes.');
        [$client]=media_s3_fixture([new \Aws\Result($head),$body($original,'unexpected-returned-version')]);
        media_binding_reject(fn()=>media_library_validate_public_asset($s3Asset,1024*1024,$client),'The actual version returned by GET must match its approved version.');
        foreach([['DeleteMarker'=>true],['ContentLength'=>$s3Asset['bytes']+1],['ContentType'=>'text/plain'],['ContentEncoding'=>'gzip'],['Metadata'=>['sha256'=>str_repeat('0',64)]]] as $alteration){
            [$client]=media_s3_fixture([new \Aws\Result(array_merge($head,$alteration))]);
            media_binding_reject(fn()=>media_library_validate_public_asset($s3Asset,1024*1024,$client),'Deleted, resized, retyped, encoded or fingerprint-mismatched current objects must not pass public delivery.');
        }
        [$client,$record]=media_s3_fixture([]);
        $CONFIG['media']['bucket']='different-fixture-bucket';
        media_binding_reject(fn()=>media_library_validate_public_asset($s3Asset,1024*1024,$client),'Changing the configured S3 bucket must fail before any object request.');$CONFIG['media']['bucket']=$s3Asset['bucket'];
        $CONFIG['media']['public_url']='https://changed-media.example';
        media_binding_reject(fn()=>media_library_validate_public_asset($s3Asset,1024*1024,$client),'Changing the configured CDN base must fail before any object request.');$CONFIG['media']['public_url']='https://media.waithakandegwa.com';
        db()->prepare('UPDATE media_assets SET version_id=? WHERE id=?')->execute(['',$public]);$unversioned=media_library_snapshot(media_library_asset($public));
        media_binding_reject(fn()=>media_library_validate_public_asset($unversioned,1024*1024,$client),'An unversioned public S3 object needs reimport and approval rather than a guessed current binding.');
        media_check($record->commands===[],'Unbound configuration and unversioned references must not trigger storage or arbitrary URL reads.');
    }finally{
        $CONFIG['media']=$savedMedia;
        $storageUpdate->execute([$publicAsset['driver'],$publicAsset['bucket'],$publicAsset['version_id'],$publicAsset['public_url'],$public]);
    }

    foreach(['../outside.png','assets/uploads/2026/13/'.str_repeat('a',32).'.png','assets/uploads/2026/10/config.php']as$path)media_check(!media_library_key_valid($path,'public','png'),'Malformed owned object keys must be rejected.');
    $notOwned=tempnam(sys_get_temp_dir(),'other-task-');file_put_contents($notOwned,'preserve');
    media_library_release(['path'=>$notOwned,'temporary'=>true]);media_check(is_file($notOwned),'Cleanup must not delete another task’s temporary file.');unlink($notOwned);
    $tempPath=$copy['path'];media_library_release($copy);media_check(!file_exists($tempPath),'Owned staging files must be removed after provider use.');
    file_put_contents($source,'<?php echo "not an image";');
    media_reject(fn()=>media_library_import($source,'photo.jpg',['title'=>'Fake image','visibility'=>'private'],1),'A misleading image extension must not allow script content.');
    media_reject(fn()=>media_library_store(['error'=>UPLOAD_ERR_OK,'tmp_name'=>$source,'name'=>'fake.jpg'],['title'=>'Not an HTTP upload','visibility'=>'private'],1),'Web upload entry points must reject arbitrary local file paths.');
    echo "Media library database checks passed: $checks\n";
} finally {
    foreach($staged as$copy)media_library_release($copy);
    foreach($paths as$path)if(is_file($path)||is_link($path))unlink($path);
    if(is_file($source))unlink($source);
    // Only synthetic rows in this suite's unique database are removed.
    foreach($packages as$packageId){
        db()->prepare('DELETE FROM publishing_revisions WHERE package_id=?')->execute([$packageId]);
        db()->prepare('DELETE FROM publishing_packages WHERE id=?')->execute([$packageId]);
    }
    foreach($owned as$id)db()->prepare('DELETE FROM media_assets WHERE id=?')->execute([$id]);
}
