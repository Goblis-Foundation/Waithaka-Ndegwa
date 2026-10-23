<main class="write-main journey-writer">
  <div class="writer-head"><div><a class="journey-back" href="<?=e(url('/write/journeys'))?>">← Startup Journeys</a><span class="writer-kicker">Private editorial review</span><h1>Founder submissions</h1><p class="muted">Review the account, record permission and link a separately edited article.</p></div><a class="wbtn" href="<?=e(url('/founders'))?>">View Founder Stories ↗</a></div>
  <?php require __DIR__.'/../journeys/notices.php';?>
  <section class="journey-panel"><form method="get" action="<?=e(url('/write/journeys/founders'))?>" class="journey-filter"><label for="review-status-filter">Review status</label><select name="status" id="review-status-filter"><option value="">All submissions</option><?php foreach(founders_review_states() as $key=>$label):?><option value="<?=e($key)?>" <?=$filter===$key?'selected':''?>><?=e($label)?></option><?php endforeach;?></select><button class="wbtn" type="submit">Apply</button></form>
    <?php if(!$submissions):?><div class="journey-empty journey-empty-small"><h3>No submissions in this view.</h3><p>New stories and nominations will appear here after they are submitted.</p></div><?php endif;?>
    <div class="journey-writer-list"><?php foreach($submissions as $submission):?><article><div><div class="journey-meta"><span class="journey-badge"><?=e(founders_review_states()[$submission['review_status']]??$submission['review_status'])?></span><span><?=$submission['submission_type']==='nomination'?'Nomination':'Founder submission'?></span><?php if($submission['is_public']):?><span>Public collection enabled</span><?php endif;?></div><h3><a href="<?=e(url('/write/journeys/founders/'.$submission['id']))?>"><?=e($submission['founder_name'])?> · <?=e($submission['venture_name'])?></a></h3><p><?=e($submission['country'])?> · <?=e(format_date($submission['created_at']))?> · <?=e($submission['reference'])?></p></div><a class="wbtn" href="<?=e(url('/write/journeys/founders/'.$submission['id']))?>">Review</a></article><?php endforeach;?></div>
    <p class="journey-help"><?php if($pagination['total']):?>Showing <?=intval($pagination['start'])?>–<?=intval($pagination['end'])?> of <?=intval($pagination['total'])?> submissions.<?php else:?>0 submissions.<?php endif;?></p>
    <?php if($pagination['pages']>1):?><nav class="journey-filter" aria-label="Founder submission pages">
      <?php if($pagination['page']>2):?><a class="wbtn" href="<?=e(url($pagination['first']))?>">First page</a><?php endif;?>
      <?php if($pagination['previous']!==null):?><a class="wbtn" rel="prev" href="<?=e(url($pagination['previous']))?>">← Previous</a><?php endif;?>
      <span>Page <?=intval($pagination['page'])?> of <?=intval($pagination['pages'])?></span>
      <?php if($pagination['next']!==null):?><a class="wbtn" rel="next" href="<?=e(url($pagination['next']))?>">Next →</a><?php endif;?>
    </nav><?php endif;?>
  </section>
</main>
