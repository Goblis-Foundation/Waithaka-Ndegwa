<main class="write-main writer-dashboard">
  <div class="writer-head writer-head-rich"><div><span class="writer-kicker">Private publishing desk</span><h1>My Thoughts</h1><div class="muted">One place to capture, refine, schedule and publish long-form work.</div></div><div class="writer-actions"><a class="wbtn" href="<?=url('/write/studio')?>">Studio · capture & AI</a><a class="wbtn" href="<?=url('/write/media-centre')?>">Media centre · social & analytics</a><a class="wbtn" href="<?=url('/my-thoughts')?>" target="_blank">View archive ↗</a><a class="wbtn primary" href="<?=url('/write/new')?>">+ New thought</a></div></div>
  <?php foreach(flashes() as $f):?><div class="notice <?=$f['type']==='error'?'error':''?>"><?=e($f['message'])?></div><?php endforeach;?>

  <section class="dashboard-grid writer-stats" aria-label="Publishing overview">
    <button class="stat" type="button" data-writer-filter="draft"><strong><?=intval($counts['draft']??0)?></strong><span>Drafts</span><small>Still taking shape</small></button>
    <button class="stat" type="button" data-writer-filter="scheduled"><strong><?=intval($counts['scheduled']??0)?></strong><span>Scheduled</span><small>Ready for later</small></button>
    <button class="stat" type="button" data-writer-filter="published"><strong><?=intval($counts['published']??0)?></strong><span>Published</span><small>Live in the archive</small></button>
    <button class="stat" type="button" data-writer-filter="selected"><strong><?=intval($counts['selected']??0)?></strong><span>Selected</span><small>Highlighted publicly</small></button>
  </section>

  <section class="writer-quiet-tools"><a href="<?=url('/write/releases')?>"><span>Consent & media releases</span><strong>Private permission and payment trace →</strong></a><p>Use only when a photo, video or conversation needs an explicit record. Nothing here appears on the public site.</p></section>

  <section class="writer-library">
    <div class="writer-library-head"><div><span class="writer-kicker">Library</span><h2>Find the thought you want to work on.</h2></div><div class="writer-library-tools"><label class="writer-search"><span aria-hidden="true">⌕</span><input type="search" placeholder="Search title, topic or summary" data-writer-search autocomplete="off"></label><select data-writer-status aria-label="Filter posts by status"><option value="all">All statuses</option><option value="draft">Drafts</option><option value="scheduled">Scheduled</option><option value="published">Published</option><option value="selected">Selected</option></select></div></div>

    <div class="writer-post-list" data-writer-list>
      <?php if(!$posts):?><div class="writer-empty"><strong>Your first thought starts here.</strong><p>Create a draft, add a topic and publish only when it is ready.</p><a class="wbtn primary" href="<?=url('/write/new')?>">Start a thought</a></div><?php endif;?>
      <?php foreach($posts as $p):?>
        <article class="writer-post-row" data-writer-post data-status="<?=e($p['status'])?>" data-selected="<?=!empty($p['is_selected'])?'1':'0'?>" data-search="<?=e(mb_strtolower(($p['title']??'').' '.($p['standfirst']??'').' '.($p['category_name']??'')))?>">
          <a class="writer-post-main" href="<?=url('/write/edit/'.$p['id'])?>"><span class="status <?=e($p['status'])?>"><?=e($p['status'])?></span><h3><?=e($p['title'] ?: 'Untitled')?></h3><p><?=e($p['standfirst'] ?: 'No standfirst yet.')?></p></a>
          <div class="writer-post-meta"><span><?=e($p['category_name']??'No topic')?></span><?php if(!empty($p['is_selected'])):?><span class="selected-mark">Selected</span><?php endif;?><small>Updated <?=e(format_date($p['updated_at']))?></small></div>
          <div class="writer-post-actions"><a href="<?=url('/write/edit/'.$p['id'])?>">Edit</a><?php if($p['status']==='published'):?><a href="<?=url('/my-thoughts/'.$p['slug'])?>" target="_blank">View ↗</a><?php endif;?></div>
        </article>
      <?php endforeach;?>
      <div class="writer-empty" data-writer-empty hidden><strong>No thoughts match that filter.</strong><p>Try another status or a broader search phrase.</p></div>
    </div>
  </section>
</main>
