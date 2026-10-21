<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||getenv('WK_TEST_ALLOW_WRITES')!=='1'||!preg_match('/^waithaka_test_[a-z0-9_]+_test$/D',(string)getenv('DB_DATABASE'))){fwrite(STDERR,"Use an isolated, freshly prepared test database.\n");exit(2);}
require dirname(__DIR__).'/app/bootstrap.php';
$checks=0;$owned=[];$paths=[];$staged=[];$source=tempnam(sys_get_temp_dir(),'wk-image-fixture-');
function media_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;}
function media_reject(callable $operation,string $label):void{try{$operation();}catch(MediaLibraryException|RuntimeException $e){$GLOBALS['checks']++;return;}throw new RuntimeException($label);}
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
    foreach($owned as$id)db()->prepare('DELETE FROM media_assets WHERE id=?')->execute([$id]);
}
