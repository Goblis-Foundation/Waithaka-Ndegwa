<main class="write-main">
  <div class="writer-head"><div><span class="writer-kicker">Private records</span><h1>Consent & media releases</h1><div class="muted">Create a private consent link, see what was agreed, and keep any appreciation/payment trace separate from the public website.</div></div><div class="writer-actions"><a class="wbtn primary" href="<?=url('/write/releases/new')?>">New consent link</a></div></div>
  <?php foreach(flashes() as $f):?><div class="notice <?=$f['type']==='error'?'error':''?>"><?=e($f['message'])?></div><?php endforeach;?>
  <section class="writer-stats release-stats">
    <?php foreach(['all'=>'All','sent'=>'Awaiting signature','signed'=>'Signed','withdrawal_requested'=>'Withdrawal requested','closed'=>'Closed'] as $k=>$label):?><button type="button" class="stat <?=$k==='all'?'active':''?>" data-release-filter="<?=e($k)?>"><strong><?=intval($counts[$k]??0)?></strong><span><?=e($label)?></span></button><?php endforeach;?>
  </section>
  <section class="writer-library release-library">
    <div class="writer-library-head"><div><span class="writer-kicker">Record trail</span><h2>Private consent links</h2><p>These links are unlisted, excluded from search, and never shown in public navigation.</p></div><div class="writer-library-tools"><label class="writer-search"><span>⌕</span><input type="search" placeholder="Search reference or context" data-release-search autocomplete="off"></label></div></div>
    <div class="release-list" data-release-list>
      <?php if(!$releases):?><div class="writer-empty"><strong>No consent records yet.</strong><p>Create a private link when a photo, video or conversation needs explicit permission.</p><a class="wbtn primary" href="<?=url('/write/releases/new')?>">Create first link</a></div><?php endif;?>
      <?php foreach($releases as $r):?><article class="release-row" data-release-row data-status="<?=e($r['status'])?>" data-search="<?=e(mb_strtolower(($r['reference']??'').' '.($r['title']??'').' '.($r['context_note']??'').' '.($r['signer_name']??'')))?>"><a class="release-row-main" href="<?=url('/write/releases/'.$r['id'])?>"><span class="status <?=e($r['status'])?>"><?=e(str_replace('_',' ',$r['status']))?></span><h3><?=e($r['title'])?></h3><p><?=e($r['context_note'] ?: 'No context note.')?></p></a><div class="release-row-meta"><span><?=e($r['reference'])?></span><small><?=!empty($r['signed_at'])?'Signed '.e(format_date($r['signed_at'])):'Created '.e(format_date($r['created_at']))?></small><?php if((int)($r['payment_count']??0)>0):?><small><?=intval($r['payment_count'])?> payment record<?=intval($r['payment_count'])===1?'':'s'?></small><?php endif;?></div><div class="writer-post-actions"><a href="<?=url('/write/releases/'.$r['id'])?>">Open</a></div></article><?php endforeach;?>
      <div class="writer-empty" data-release-empty hidden><strong>No records match that filter.</strong><p>Try a different status or search phrase.</p></div>
    </div>
  </section>
</main>
