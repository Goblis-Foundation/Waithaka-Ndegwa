<main class="write-main writer-dashboard">
  <div class="writer-head writer-head-rich"><div><span class="writer-kicker">Your publishing workspace</span><h1>Make your next idea count.</h1><p class="muted">Writing, conversations and progress—organised in one place.</p></div><div class="writer-actions"><a class="wbtn" href="<?=url('/write/capture')?>">Capture an idea</a><a class="wbtn primary" href="<?=url('/write/publishing/new')?>">Create social post</a></div></div>
  <?php foreach(flashes() as $f):?><div class="notice <?=$f['type']==='error'?'error':''?>"><?=e($f['message'])?></div><?php endforeach;?>

  <?php include __DIR__.'/program_home.php'; ?>
  <section class="dashboard-grid writer-stats" aria-label="Publishing overview">
    <button class="stat" type="button" data-writer-filter="draft"><strong><?=intval($counts['draft']??0)?></strong><span>Drafts</span><small>Still taking shape</small></button>
    <button class="stat" type="button" data-writer-filter="scheduled"><strong><?=intval($counts['scheduled']??0)?></strong><span>Scheduled</span><small>Ready for later</small></button>
    <button class="stat" type="button" data-writer-filter="published"><strong><?=intval($counts['published']??0)?></strong><span>Published</span><small>Live in the archive</small></button>
    <button class="stat" type="button" data-writer-filter="selected"><strong><?=intval($counts['selected']??0)?></strong><span>Selected</span><small>Highlighted publicly</small></button>
  </section>

  <?php $recentDraft=null;foreach($posts as$working)if($working['status']==='draft'){$recentDraft=$working;break;}?>
  <div class="workspace-overview-grid">
    <section class="workspace-feature"><span class="writer-kicker"><?= $recentDraft?'Continue where you left off':'Start with a thought'?></span><h2><?=e($recentDraft['title']??'An observation can become a useful story.')?></h2><p><?=e($recentDraft?($recentDraft['standfirst']?:'Your private draft is ready to continue.'):'Capture a question, dictate an idea on your phone, or open the editor and begin.')?></p><a class="wbtn primary" href="<?=url($recentDraft?'/write/edit/'.$recentDraft['id']:'/write/capture')?>"><?=$recentDraft?'Continue draft':'Capture an idea'?> →</a></section>
    <section class="workspace-status"><h2>Social workspace</h2><div class="workspace-status-number"><?=intval($workspace['connected'])?><small>of 6 platforms with confirmed account access</small></div><p><?=intval($workspace['attention'])?> posting tasks need attention · <?=intval($workspace['scheduled'])?> scheduled social deliveries</p><div class="writer-actions"><a class="wbtn" href="<?=url('/write/media-centre#connect')?>">Connect accounts</a><a href="<?=url('/write/publishing')?>">Review deliveries →</a></div></section>
  </div>
  <section class="workspace-quick-section" aria-labelledby="workspace-tools"><div class="workspace-section-head"><h2 id="workspace-tools">What would you like to do?</h2><span class="muted">Choose a workspace to continue.</span></div><div class="workspace-quick-grid">
    <?php foreach([
['/write/studio','01','Shape an idea','Capture notes, develop an outline and request AI drafting help.'],
['/write/media-centre','02','Publish & connect','Create social posts, connect accounts and review account analytics.'],
['/write/media','03','Manage media','Upload and find the images and videos for your next story.'],
['/write/newsletter','04','Reach your audience','Prepare newsletters and review subscriber workflows.'],
['/write/journeys','05','Document the journey','Keep venture experiments, founder stories and evidence together.'],
['/write/impact','06','Track 2030 goals','Review source reporting and the evidence behind your commitments.']
]as$tool):?><a class="workspace-quick-card" href="<?=url($tool[0])?>"><span><?=e($tool[1])?></span><h3><?=e($tool[2])?> ↗</h3><p><?=e($tool[3])?></p></a><?php endforeach;?></div></section>

  <section class="writer-library" id="library">
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
