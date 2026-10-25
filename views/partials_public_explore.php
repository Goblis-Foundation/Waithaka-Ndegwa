<?php
$explorePath=path_only();
$showExplore=empty($privatePage)&&preg_match('~^/(?:start-here|my-thoughts|topic|startup-journeys|enterprises|founders|resources|watch)(?:/|$)~',$explorePath)&&!preg_match('~^/founders/(?:submit|follow-up|story-review)(?:/|$)~',$explorePath);
if($showExplore):
?>
<div class="public-explore-strip"><nav class="container" aria-label="Explore writing and practical work"><span>Explore</span><?php foreach(['/my-thoughts'=>'My Thoughts','/startup-journeys'=>'Startup Journeys','/enterprises'=>'Enterprise areas','/founders'=>'Founder Stories','/resources'=>'Resources','/watch'=>'Watch']as$destination=>$label):?><a href="<?=url($destination)?>" <?=($explorePath===$destination||str_starts_with($explorePath,$destination.'/'))?'aria-current="page"':''?>><?=e($label)?></a><?php endforeach;?></nav></div>
<?php endif;?>
