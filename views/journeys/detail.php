<section class="journey-hero journey-detail-hero"><div class="container">
  <a class="journey-back" href="<?=e(url('/startup-journeys'))?>">← All Startup Journeys</a>
  <div class="journey-meta"><span class="journey-badge"><?=e(journeys_phases()[$season['phase']]??$season['phase'])?></span><span><?=e($season['venture_name'])?></span><?php if($season['started_on']):?><span>Started <?=e(format_date($season['started_on']))?></span><?php endif;?></div>
  <h1><?=e($season['title'])?></h1><p class="journey-lead"><?=e($season['summary'])?></p>
  <div class="journey-actions"><a class="button" href="#chapters">Read the chapters ↓</a><a class="text-link" href="<?=e(url('/newsletter'))?>">Follow this writing →</a></div>
</div></section>
<section class="section"><div class="container journey-detail-grid">
  <aside class="journey-baseline"><span class="eyebrow">The starting point</span><h2>Where this began.</h2><p><?=nl2br(e($season['baseline']))?></p><?php if($season['learning_question']):?><div class="journey-question"><h3>The question</h3><p><?=nl2br(e($season['learning_question']))?></p></div><?php endif;?><small>Last updated <?=e(format_date($season['updated_at']))?></small></aside>
  <div id="chapters"><div class="journey-section-head"><div><span class="eyebrow">In sequence</span><h2>The chapters.</h2></div><span class="journey-meta"><?=count($episodes)?> published</span></div>
    <?php if(!$episodes):?><div class="journey-empty journey-empty-small"><h3>The starting point is here.</h3><p>The next chapter will appear once it is published.</p></div><?php endif;?>
    <ol class="journey-chapters">
      <?php foreach($episodes as $episode):?><li value="<?=(int)$episode['episode_order']?>" class="journey-chapter"><div class="journey-chapter-number" aria-hidden="true"><?=str_pad((string)$episode['episode_order'],2,'0',STR_PAD_LEFT)?></div><article>
        <div class="journey-meta"><span>Chapter <?=(int)$episode['episode_order']?></span><time datetime="<?=e(date('Y-m-d',strtotime($episode['published_at'])))?>"><?=e(format_date($episode['published_at']))?></time></div>
        <h3><a href="<?=e(url('/my-thoughts/'.$episode['slug']))?>"><?=e($episode['title'])?></a></h3>
        <?php if($episode['public_summary']||$episode['standfirst']):?><p><?=e($episode['public_summary']?:$episode['standfirst'])?></p><?php endif;?>
        <div class="journey-evidence"><span>Basis: <?=e(journeys_evidence_bases()[$episode['evidence_basis']]??'Not yet assessed')?></span><?php if($episode['evidence_summary']):?><p><?=nl2br(e($episode['evidence_summary']))?></p><?php endif;?></div>
        <a class="text-link" href="<?=e(url('/my-thoughts/'.$episode['slug']))?>">Read chapter <?=(int)$episode['episode_order']?> →</a>
      </article></li><?php endforeach;?>
    </ol>
    <?php if($season['outcomes']):?><section class="journey-outcomes"><span class="eyebrow">Looking back, looking ahead</span><h2>Outcomes and lessons.</h2><p><?=nl2br(e($season['outcomes']))?></p></section><?php endif;?>
  </div>
</div></section>
