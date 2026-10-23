<link rel="stylesheet" href="<?=asset('css/media-library.css')?>">
<main class="wk-media">
<p><a href="<?=url('/write/media')?>">← Media library</a></p>
<?php foreach(flashes()as$message):?><div role="status" class="notice <?=e($message['type'])?>"><?=e($message['message'])?></div><?php endforeach;?>
<?php if($error):?><p role="alert" class="notice error"><?=e($error)?></p><?php endif;?>
<header class="wk-media-heading"><div><p class="eyebrow"><?=e(ucfirst($mediaAsset['visibility']))?> asset · <?=e($mediaAsset['status'])?></p><h1><?=e($mediaAsset['title'])?></h1><p><?=e($mediaAsset['original_name'])?> · <?=e(media_library_bytes((int)$mediaAsset['bytes']))?></p></div><a class="btn" href="<?=url('/write/media/'.$mediaAsset['id'].'/download')?>">Download original</a></header>
<section class="wk-media-preview">
<?php if($mediaAsset['kind']==='image'):?><img src="<?=url('/write/media/'.$mediaAsset['id'].'/preview')?>" alt="<?=e($mediaAsset['title'])?>">
<?php else:?><video controls preload="metadata" playsinline src="<?=url('/write/media/'.$mediaAsset['id'].'/preview')?>">Download this video to preview it.</video><?php endif;?>
</section>
<section class="wk-media-upload"><h2>Use in a publishing package</h2>
<p>Choose asset #<?=(int)$mediaAsset['id']?> in the publishing editor. Approval records this file’s fingerprint, so delivery uses the media you reviewed.</p>
<?php if($mediaAsset['public_url']!==''):?><label>Public media URL<input readonly value="<?=e($mediaAsset['public_url'])?>" aria-label="Public media URL" onclick="this.select()"></label><p>This URL can be used by supported image and video publishing services.</p><?php else:?><p>For private assets, the publishing service receives the file only when an approved upload is due.</p><?php endif;?>
<a class="btn" href="<?=url('/write/publishing/new')?>">Create publishing package</a>
<details><summary>File details</summary><dl><dt>Media type</dt><dd><?=e($mediaAsset['mime'])?></dd><dt>Uploaded</dt><dd><?=e($mediaAsset['created_at'])?> UTC</dd><dt>SHA-256</dt><dd class="wk-media-hash"><?=e($mediaAsset['sha256'])?></dd></dl></details>
</section>
<?php if($mediaAsset['status']==='ready'):?><section class="wk-media-upload"><h2>Archive this asset</h2><p>Archiving removes it from new package selections. Previously approved packages retain their media reference, and public URLs remain accessible.</p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="archive"><button type="submit" class="btn secondary">Archive asset</button></form></section><?php endif;?>
</main>
