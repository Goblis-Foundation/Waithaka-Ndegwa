<?php
/* A short, predictable secondary navigation. The complete index remains at /explore. */
$explorePath=path_only();
$showExplore=empty($privatePage)&&($explorePath==='/'||preg_match('~^/(?:explore|advertise|advertising-policy|start-here|my-thoughts|topic|startup-journeys|enterprises|founders|resources|watch|opportunities|partner-stories|promote|monthly-roundup)(?:/|$)~',$explorePath))&&!preg_match('~^/(?:founders/(?:submit|follow-up|story-review)|opportunities/(?:submit|review|evidence))(?:/|$)~',$explorePath);
if($showExplore):
$quickExplore=['/my-thoughts'=>'My Thoughts','/startup-journeys'=>'Startup Journeys','/resources'=>'Resources','/opportunities'=>'Opportunities'];
?>
<div class="public-explore-strip">
  <nav class="container public-explore-nav" aria-label="Explore Waithaka Ndegwa">
    <span class="public-explore-label" aria-hidden="true">Discover</span>
    <div class="public-explore-links">
    <?php foreach($quickExplore as $destination=>$label): ?>
      <a href="<?=url($destination)?>" <?=($explorePath===$destination||str_starts_with($explorePath,$destination.'/'))?'aria-current="page"':''?>><?=e($label)?></a>
    <?php endforeach; ?>
    </div>
    <a class="public-explore-all" href="<?=url('/explore')?>" <?=($explorePath==='/explore'||str_starts_with($explorePath,'/explore/'))?'aria-current="page"':''?>>All sections <span aria-hidden="true">↗</span></a>
  </nav>
</div>
<?php endif; ?>
