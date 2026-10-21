<main class="write-main journey-writer">
  <div class="writer-head"><div><span class="writer-kicker">Private publishing desk</span><h1>Startup Journeys</h1><p class="muted">Bring a venture’s starting point, chapters and lessons into one place.</p></div><div class="writer-actions"><a class="wbtn" href="<?=e(url('/startup-journeys'))?>">View collection ↗</a><a class="wbtn primary" href="<?=e(url('/write/journeys/new'))?>">New journey</a></div></div>
  <?php require __DIR__.'/../journeys/notices.php';?>
  <div class="journey-writer-tools"><a href="<?=e(url('/write/journeys/founders'))?>"><strong><?=(int)$reviewCount?> founder submissions to review</strong><span>Private stories, permission and publication decisions →</span></a><div><strong><?=(int)$newsletterCount?> confirmed email subscribers</strong><span>Only people who have completed email confirmation.</span></div></div>
  <section class="journey-panel"><div class="journey-section-head"><h2>Your journeys</h2><span class="journey-help"><?=count($seasons)?> total</span></div>
    <?php if(!$seasons):?><div class="journey-empty journey-empty-small"><h3>Start with a real venture.</h3><p>Create a private journey, record the baseline, then link chapters from your writing library.</p><a class="wbtn primary" href="<?=e(url('/write/journeys/new'))?>">Create a journey</a></div><?php endif;?>
    <div class="journey-writer-list"><?php foreach($seasons as $season):?><article>
      <div><div class="journey-meta"><span class="status <?=e($season['publication_status'])?>"><?=e(journeys_publication_states()[$season['publication_status']]??$season['publication_status'])?></span><span><?=e(journeys_phases()[$season['phase']]??$season['phase'])?></span></div><h3><a href="<?=e(url('/write/journeys/'.$season['id']))?>"><?=e($season['title'])?></a></h3><p><?=e($season['venture_name'])?> · <?=(int)$season['episode_count']?> chapter links · Updated <?=e(format_date($season['updated_at']))?></p></div>
      <div class="writer-actions"><a class="wbtn" href="<?=e(url('/write/journeys/'.$season['id']))?>">Edit</a><?php if($season['publication_status']==='published'):?><a class="wbtn" href="<?=e(url('/startup-journeys/'.$season['slug']))?>">View ↗</a><?php endif;?></div>
    </article><?php endforeach;?></div>
  </section>
</main>
