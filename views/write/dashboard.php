<?php
$dashUser=current_user();$dashUid=(int)$dashUser['id'];$dashCfg=comm_config();
$dashCanCommunicate=empty($dashCfg['owner'])||(int)$dashCfg['owner']===$dashUid;
$dashReader=$dashCanCommunicate?reader_operations_summary():[];
$dashDevices=count(array_filter(comm_user_devices($dashUid),static fn($d)=>!empty($d['enabled'])));
$recentDraft=null;foreach($posts as$working)if($working['status']==='draft'){$recentDraft=$working;break;}
$dashAttention=(int)($workspace['attention']??0);$dashOpen=(int)($dashReader['enquiries_open']??0);$dashOverdue=(int)($dashReader['enquiries_overdue']??0);
?>
<main class="write-main writer-dashboard dashboard-home">
  <div class="writer-head dashboard-heading"><div><span class="writer-kicker">Personal workspace</span><h1>Workspace overview</h1><p class="muted">Your schedule, conversations and publishing work, together.</p><span class="admin-day-label"><?=e((new DateTimeImmutable('now',new DateTimeZone('Africa/Nairobi')))->format('l, j F Y'))?> · Nairobi</span></div><a class="wbtn primary" href="<?=url('/write/capture')?>">＋ Capture an idea</a></div>
  <?php foreach(flashes() as $f):?><div class="notice <?=$f['type']==='error'?'error':''?>"><?=e($f['message'])?></div><?php endforeach;?>
  <div class="admin-dashboard-metrics" aria-label="Workspace snapshot">
    <?php foreach([['/write#library','Draft articles',(int)($counts['draft']??0),'Ready for your next edit'],['/write/publishing','Scheduled posts',(int)($workspace['scheduled']??0),'Saved publishing schedule'],['/write/communication','Open conversations',$dashCanCommunicate?$dashOpen:null,'Private correspondence'],['/write/connections','Connected platforms',(int)($workspace['connected']??0),'Saved account access']]as[$href,$label,$value,$note]):?><a class="admin-dashboard-metric" href="<?=url($href)?>"><span><?=e($label)?></span><strong><?=$value===null?'—':number_format($value)?></strong><small><?=e($note)?></small></a><?php endforeach;?>
  </div>
  <nav class="dashboard-jump" aria-label="Dashboard sections"><a href="#day">Today</a><a href="#attention">Needs attention</a><a href="#create">Create & publish</a><a href="#library">Article library</a></nav>

  <div class="dashboard-day-grid" id="day">
    <div><?php include __DIR__.'/program_home.php'; ?></div>
    <aside class="dashboard-attention" id="attention" aria-labelledby="attention-title">
      <span class="writer-kicker">Keep things moving</span><h2 id="attention-title">Needs attention</h2>
      <a class="dashboard-task" href="<?=url('/write/publishing')?>"><span><strong>Social deliveries</strong><small><?=$dashAttention?'Review assisted posts, failures or account issues.':'No social deliveries flagged for review.'?></small></span><b><?=$dashAttention?></b></a>
      <?php if($dashCanCommunicate):?><a class="dashboard-task" href="<?=url('/write/communication?tab=inbox')?>"><span><strong>Open conversations</strong><small><?=$dashOverdue?intval($dashOverdue).' overdue follow-ups':'Review messages and next steps.'?></small></span><b><?=intval($dashOpen)?></b></a><?php endif;?>
      <a class="dashboard-task" href="<?=url('/write/app')?>"><span><strong>Device notifications</strong><small><?=$dashDevices?'Review your devices and reminder preferences.':'Connect your phone or computer to receive reminders.'?></small></span><span class="dashboard-chip"><?=$dashDevices?intval($dashDevices).' connected':'Set up'?></span></a>
      <a class="dashboard-task" href="<?=url('/write/journeys/founders')?>"><span><strong>Founder reviews</strong><small>Track approval rounds and returned stories.</small></span><span aria-hidden="true">→</span></a>
      <p class="dashboard-footnote">Counts reflect saved workspace records. Open a workspace for current provider status.</p>
    </aside>
  </div>

  <section class="dashboard-create" id="create" aria-labelledby="create-title">
    <div class="dashboard-section-heading"><div><span class="writer-kicker">From idea to audience</span><h2 id="create-title">Create & publish</h2></div><a href="<?=url('/write/media-centre')?>">Open media centre →</a></div>
    <div class="dashboard-action-grid">
    <?php foreach([
      ['/write/studio','✦','Studio & AI','Capture notes, develop ideas and draft with AI.'],
      ['/write/new','✎','Write a thought','Open the editor and start an article.'],
      ['/write/publishing/new','＋','Create a social post','Prepare content for your professional accounts.'],
      ['/write/newsletter','↗','Send a newsletter','Prepare an email for your subscribers.']
    ]as$tool):?><a class="dashboard-action" href="<?=url($tool[0])?>"><span aria-hidden="true"><?=e($tool[1])?></span><strong><?=e($tool[2])?></strong><small><?=e($tool[3])?></small></a><?php endforeach;?>
    </div>
  </section>

  <div class="dashboard-progress-grid">
    <section class="dashboard-continue"><span class="writer-kicker"><?=$recentDraft?'Continue writing':'Make room for a new idea'?></span><h2><?=e($recentDraft['title']??'What is on your mind?')?></h2><p><?=e($recentDraft?($recentDraft['standfirst']?:'Your private draft is ready to continue.'):'Capture a question, an observation or a voice-dictated note.')?></p><a class="wbtn" href="<?=url($recentDraft?'/write/edit/'.$recentDraft['id']:'/write/capture')?>"><?=$recentDraft?'Continue draft':'Capture an idea'?> →</a></section>
    <section class="dashboard-pipeline" aria-labelledby="pipeline-title"><h2 id="pipeline-title">Publishing overview</h2><div class="dashboard-grid writer-stats">
      <?php foreach(['draft'=>['Drafts','Private articles'],'scheduled'=>['Scheduled','Articles queued'],'published'=>['Published','Live articles'],'selected'=>['Selected','Featured articles']]as$key=>$labels):?><button class="stat" type="button" data-writer-filter="<?=e($key)?>"><strong><?=intval($counts[$key]??0)?></strong><span><?=e($labels[0])?></span><small><?=e($labels[1])?></small></button><?php endforeach;?>
      </div><p><?=intval($workspace['scheduled']??0)?> scheduled social deliveries · <?=intval($workspace['connected']??0)?> of <?=count(publishing_networks())?> platforms with saved account access</p><a href="<?=url('/write/media-centre#connect')?>">Manage social connections →</a></section>
  </div>

  <section class="dashboard-destinations" aria-labelledby="more-title"><div class="dashboard-section-heading"><h2 id="more-title">Explore your workspace</h2></div><div class="dashboard-destination-grid">
    <?php foreach([
      ['/write/communication','Communication Centre','Inbox, people, meetings and follow-ups'],
      ['/write/media','Media library','Images, videos and assets'],
      ['/write/impact','2030 goals & evidence','Approved reporting and source records'],
      ['/write/discovery','Search performance','Audience discovery and site health']
    ]as$tool):?><a href="<?=url($tool[0])?>"><strong><?=e($tool[1])?> →</strong><small><?=e($tool[2])?></small></a><?php endforeach;?>
  </div></section>
  <section class="writer-library" id="library">
    <div class="writer-library-head"><div><span class="writer-kicker">Library</span><h2>Your article library.</h2></div><div class="writer-library-tools"><label class="writer-search"><span aria-hidden="true">⌕</span><input type="search" aria-label="Search articles" placeholder="Search title, topic or summary" data-writer-search autocomplete="off"></label><select data-writer-status aria-label="Filter posts by status"><option value="all">All statuses</option><option value="draft">Drafts</option><option value="scheduled">Scheduled</option><option value="published">Published</option><option value="selected">Selected</option></select></div></div>

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
