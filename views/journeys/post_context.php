<?php foreach(($journeyContexts??[]) as $context):?>
<nav class="journey-article-nav" aria-label="Chapters in <?=e($context['season']['title'])?>">
  <span class="eyebrow">Startup Journeys · Chapter <?=(int)$context['current']['episode_order']?></span><h2><a href="<?=e(url('/startup-journeys/'.$context['season']['slug']))?>"><?=e($context['season']['title'])?></a></h2>
  <div><?php if($context['previous']):?><a href="<?=e(url('/my-thoughts/'.$context['previous']['slug']))?>"><small>← Previous chapter</small><strong><?=e($context['previous']['title'])?></strong></a><?php endif;?><?php if($context['next']):?><a href="<?=e(url('/my-thoughts/'.$context['next']['slug']))?>" data-analytics-event="episode_continue" data-content-id="<?=e('post-'.$context['next']['post_id'])?>"><small>Next chapter →</small><strong><?=e($context['next']['title'])?></strong></a><?php endif;?></div>
</nav>
<?php endforeach;?>
