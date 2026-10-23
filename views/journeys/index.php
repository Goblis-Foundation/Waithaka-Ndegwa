<section class="journey-hero">
  <div class="container journey-hero-grid">
    <div><span class="eyebrow">Startup Journeys</span><h1>The work of<br>building something.</h1><p class="journey-lead">A venture has a starting point, decisions that shape it, and lessons that arrive along the way. Follow those changes, one chapter at a time.</p><div class="journey-actions"><a class="button" href="#journeys">Explore the journeys ↓</a><a class="text-link" href="<?=e(url('/newsletter'))?>">Follow new chapters →</a></div></div>
    <aside class="journey-principles"><span class="eyebrow">What you will find</span><ol><li><strong>A clear starting point</strong><span>The circumstances, resources and question behind each venture.</span></li><li><strong>Decisions in sequence</strong><span>What changed, what was tried and what happened next.</span></li><li><strong>Evidence and reflection</strong><span>The basis for a claim, alongside what is still uncertain.</span></li></ol></aside>
  </div>
</section>
<section class="section journey-collection" id="journeys"><div class="container">
  <div class="journey-section-head"><div><span class="eyebrow">The collection</span><h2>Follow a journey.</h2></div><p>Each journey brings its chapters together in order, from the starting point to the latest reflection.</p></div>
  <?php if(!$seasons):?><div class="journey-empty"><h3>The first journey is taking shape.</h3><p>New chapters will appear here when they are ready. In the meantime, explore the writing that informs my work.</p><div class="journey-actions"><a class="button" href="<?=e(url('/my-thoughts'))?>">Read My Thoughts →</a><a class="text-link" href="<?=e(url('/newsletter'))?>">Follow future chapters →</a></div></div><?php endif;?>
  <div class="journey-season-list">
    <?php foreach($seasons as $season):?><article class="journey-season-card">
      <div class="journey-season-number" aria-hidden="true"><?=str_pad((string)($season['episode_count']??0),2,'0',STR_PAD_LEFT)?><span>chapters</span></div>
      <div><div class="journey-meta"><span class="journey-badge"><?=e(journeys_phases()[$season['phase']]??$season['phase'])?></span><span><?=e($season['venture_name'])?></span><?php if($season['started_on']):?><span>Started <?=e(format_date($season['started_on']))?></span><?php endif;?></div><h3><a href="<?=e(url('/startup-journeys/'.$season['slug']))?>"><?=e($season['title'])?></a></h3><p><?=e($season['summary'])?></p><a class="text-link" href="<?=e(url('/startup-journeys/'.$season['slug']))?>">Read this journey →</a></div>
    </article><?php endforeach;?>
  </div>
  <div class="journey-related"><div><span class="eyebrow">Other founders, other perspectives</span><h2>There is more than one way to build.</h2><p>Read selected founder stories, or share an experience for consideration.</p></div><a class="button" href="<?=e(url('/founders'))?>">Explore Founder Stories →</a></div>
</div></section>
