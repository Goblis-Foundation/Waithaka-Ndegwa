<?php
// Callers provide a stable, non-secret path. Never derive QR targets from a request URI.
$documentQrPath=(string)($documentQrPath??'');
if(!preg_match('~^/[a-zA-Z0-9_/-]*$~D',$documentQrPath) || preg_match('~^/(?:consent|founders/story-review)(?:/|$)~',$documentQrPath)) return;
$documentQrUrl=url($documentQrPath);
?>
<link rel="stylesheet" href="<?=asset('css/document.css')?>">
<aside class="document-record" data-document-qr="<?=e($documentQrUrl)?>" aria-label="Online document reference">
<div class="document-qr-graphic" data-document-qr-graphic></div>
<div class="document-record-copy"><strong><?=e($documentQrLabel??'Open the current online version')?></strong>
<?php if(!empty($documentQrReference)):?><small>Reference: <?=e($documentQrReference)?></small><?php endif;?>
<a href="<?=e($documentQrUrl)?>"><?=e($documentQrUrl)?></a>
<small><?=!empty($documentQrPrivate)?'Private record. An authorised workspace sign-in is required.':'The online version may change after this copy was printed.'?></small>
<button class="document-print" type="button" data-document-print hidden>Print / save PDF</button></div>
</aside>
<script src="<?=asset('js/vendor/qrcode.js')?>" defer></script>
<script src="<?=asset('js/document-qr.js')?>" defer></script>
