<?php
require_once __DIR__.'/../../app/promotion_experience.php';
$progress=promote_client_progress($r,$post,$orders,$report);
?>
<link rel="stylesheet" href="<?=e(asset('css/promotion-experience.css'))?>">
<section class="promote-client-journey" aria-labelledby="client-next-title"><p class="promote-eyebrow">Your publishing journey</p><h2 id="client-next-title"><?=e($progress['next']['title'])?></h2><p><?=e($progress['next']['text'])?></p><a class="promote-button primary" href="<?=e($progress['next']['href'])?>"><?=e($progress['next']['label'])?> <span aria-hidden="true">↓</span></a><div class="promote-process-grid"><?php foreach($progress['steps']as$i=>$stage):?><details><summary><span class="promote-number"><?=$i+1?></span><strong><?=e($stage['title'])?></strong><span class="promote-expand" aria-hidden="true">+</span></summary><p><?=e($stage['status'])?></p><a href="<?=e($stage['href'])?>">Open this section →</a></details><?php endforeach;?></div><p class="promote-small">Status is based on saved records, not a time estimate. Opening a stage does not accept a quote, confirm payment or approve publication.</p></section>
