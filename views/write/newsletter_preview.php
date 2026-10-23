<link rel="stylesheet" href="<?=asset('css/newsletter.css')?>">
<main class="write-main nl-main"><div class="writer-head"><div><span class="writer-kicker">Saved version only</span><h1><?=e($campaign['title'])?></h1><p>Draft version <?=intval($campaign['draft_version'])?><?=$revision?' · Approved revision':''?>. This preview has no live recipient or unsubscribe token.</p></div><a class="wbtn" href="<?=url('/write/newsletter/'.$campaign['id'])?>">Back to edition</a></div>
<?php if($reviewError):?><div class="notice error"><?=e($reviewError)?></div><?php endif;?>
<?php if($snapshot):?><iframe class="nl-preview nl-preview-full" sandbox="" referrerpolicy="no-referrer" title="Saved email preview" srcdoc="<?=e(newsletter_html($snapshot))?>"></iframe><?php endif;?>
</main>
