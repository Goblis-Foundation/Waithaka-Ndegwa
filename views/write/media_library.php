<link rel="stylesheet" href="<?=asset('css/media-library.css')?>">
<main class="wk-media">
<header class="wk-media-heading"><div><p class="eyebrow">Publishing assets</p><h1>Media library</h1><p>Keep the exact images and videos you approve for your weekly updates.</p></div><a class="btn" href="<?=url('/write/publishing')?>">Publishing packages</a></header>
<?php if($error):?><div role="alert" class="notice error"><?=e($error)?></div><?php endif;?>
<?php foreach(flashes()as$message):?><div role="status" class="notice <?=e($message['type'])?>"><?=e($message['message'])?></div><?php endforeach;?>
<section class="wk-media-upload" aria-labelledby="upload-heading">
<h2 id="upload-heading">Upload an asset</h2>
<form method="post" enctype="multipart/form-data">
<?=csrf_field()?>
<label>Title<input name="title" maxlength="240" required value="<?=e(is_string($_POST['title']??null)?$_POST['title']:'')?>" placeholder="Week 01 — testing the problem"></label>
<label>File<input type="file" name="media" accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm" required aria-describedby="media-limits"></label>
<p id="media-limits" class="muted">JPEG, PNG or WebP up to 25 MiB. MP4, MOV or WebM up to 512 MiB, within your server’s upload limit. Upload a new asset when the file changes.</p>
<label>Access<select name="visibility"><option value="private">Private — review in Writer and upload to YouTube</option><option value="public" <?=($_POST['visibility']??'')==='public'?'selected':''?>>Public — available by URL for social publishing</option></select></label>
<label class="wk-media-check"><input type="checkbox" name="public_ack" value="1" <?=!empty($_POST['public_ack'])?'checked':''?>> If I select public access, this file may be viewed and shared using its URL.</label>
<button class="btn" type="submit">Upload media</button>
</form>
</section>
<section aria-labelledby="assets-heading"><h2 id="assets-heading">Your assets</h2>
<?php if(!$assets):?><div class="wk-media-empty"><h3>Your media library is ready.</h3><p>Upload the first image or video for your Startup Journey. Private media stays accessible through Writer until you approve a platform upload.</p></div>
<?php else:?><div class="wk-media-grid">
<?php foreach($assets as$item):?><article class="wk-media-card">
<?php if($item['kind']==='image'):?><img src="<?=url('/write/media/'.$item['id'].'/preview')?>" loading="lazy" alt=""><?php else:?><div class="wk-media-video" aria-hidden="true">Video</div><?php endif;?>
<div><p class="wk-media-meta"><?=e(ucfirst($item['visibility']))?> · <?=e(media_library_bytes((int)$item['bytes']))?> · <?=e(ucfirst($item['status']))?></p><h3><a href="<?=url('/write/media/'.$item['id'])?>"><?=e($item['title'])?></a></h3><p class="muted"><?=e($item['mime'])?></p></div>
</article><?php endforeach;?></div><?php endif;?>
</section>
</main>
