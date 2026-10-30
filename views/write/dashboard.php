<?php
$dashCanCommunicate=$dashboard['can_communicate'];$dashDevices=$dashboard['devices'];
$recentDraft=null;foreach($posts as$working)if($working['status']==='draft'||!empty($working['_has_draft'])){$recentDraft=$working;break;}
$dashAttention=$workspace['attention']??null;$dashOpen=$dashboard['open'];$dashOverdue=$dashboard['overdue'];
?>
<main class="write-main writer-dashboard dashboard-home">
  <div class="writer-head dashboard-heading"><div><span class="writer-kicker">Personal workspace</span><h1>Workspace overview</h1><p class="muted">A clear view of today. Create, review and follow through.</p><span class="admin-day-label"><?=e((new DateTimeImmutable('now',new DateTimeZone('Africa/Nairobi')))->format('l, j F Y'))?> · Nairobi</span></div><a class="wbtn primary" href="<?=url('/write/capture')?>">＋ Capture an idea</a></div>
  <?php foreach(flashes() as $f):?><div class="notice <?=$f['type']==='error'?'error':''?>"><?=e($f['message'])?></div><?php endforeach;?>
  <nav class="dashboard-jump" data-page-outline aria-label="Dashboard sections"><a href="#day">Today</a><a href="#attention">Needs attention</a><a href="#create">Create & publish</a><a href="#library">Article library</a></nav>

  <div class="dashboard-day-grid" id="day">
    <section class="dashboard-today" aria-labelledby="today-title"><span class="writer-kicker">Your next steps</span><h2 id="today-title">Today</h2><p class="muted"><?=e($dashboard['timezone'])?> · Saved program and calendar snapshot</p>
      <?php if($dashboard['activities']===null):?><p>Today’s activities could not be loaded. Open the program to review.</p><?php elseif(!$dashboard['activities']):?><p>No remaining activities in your saved plan. Review earlier work or add your next commitment.</p><?php else:?>
      <ol class="dashboard-agenda"><?php foreach($dashboard['activities']as$activity):?><li><time><?=e((new DateTimeImmutable('@'.$activity['start']))->setTimezone(comm_zone($dashboard['timezone']))->format('H:i'))?></time><div><strong><?=e($activity['title'])?></strong><small><?=e($activity['source'])?><?=!empty($activity['stale'])?' · refresh needed':''?></small></div><a href="<?=url('/write/program')?>">Review<span class="sr-only"> <?=e($activity['title'])?></span></a></li><?php endforeach;?></ol><?php endif;?>
      <a class="wbtn primary" href="<?=url('/write/program')?>">Open daily program →</a><p><a href="<?=url('/write/activity')?>">Activity targets & reports →</a></p><p class="dashboard-footnote"><?=$dashboard['calendar_observed']?'Calendar last refreshed '.e(date('j M H:i T',(int)$dashboard['calendar_observed'])):'Connected calendar freshness is unverified. Refresh from the daily program.'?></p>
    </section>
    <aside class="dashboard-attention" id="attention" aria-labelledby="attention-title">
      <span class="writer-kicker">Keep things moving</span><h2 id="attention-title">Needs attention</h2>
      <a class="dashboard-task" href="<?=url('/write/publishing')?>"><span><strong>Social deliveries</strong><small><?=$dashAttention===null?'Saved delivery status unavailable.':($dashAttention?'Review assisted posts, failures or account issues.':'No saved social deliveries flagged for review.')?></small></span><b><?=$dashAttention===null?'—':intval($dashAttention)?></b></a>
      <?php if($dashCanCommunicate):?><a class="dashboard-task" href="<?=url('/write/communication?tab=inbox')?>"><span><strong>Open conversations</strong><small><?=$dashOverdue?intval($dashOverdue).' overdue follow-ups':'Review messages and next steps.'?></small></span><b><?=$dashOpen===null?'—':intval($dashOpen)?></b></a><?php endif;?>
      <a class="dashboard-task" href="<?=url('/write/app')?>"><span><strong>Device notifications</strong><small><?=$dashDevices===null?'Device inventory could not be loaded.':($dashDevices?'Review your devices and reminder preferences.':'Connect your phone or computer to receive reminders.')?></small></span><span class="dashboard-chip"><?=$dashDevices===null?'Unknown':($dashDevices?intval($dashDevices).' connected':'Set up')?></span></a>
      <a class="dashboard-task" href="<?=url('/write/journeys/founders')?>"><span><strong>Founder reviews</strong><small>Track approval rounds and returned stories.</small></span><span aria-hidden="true">→</span></a>
      <?php if($dashboard['is_owner']):?><a class="dashboard-task" href="<?=url('/write/activation')?>"><span><strong>Launch readiness</strong><small>Account permissions, delivery and remaining acceptance tasks.</small></span><span aria-hidden="true">→</span></a><?php endif;?>
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
      ['/write/newsletter','↗','Prepare a newsletter','Prepare an email for your subscribers.']
    ]as$tool):?><a class="dashboard-action" href="<?=url($tool[0])?>"><span aria-hidden="true"><?=e($tool[1])?></span><strong><?=e($tool[2])?></strong><small><?=e($tool[3])?></small></a><?php endforeach;?>
    </div>
  </section>

  <div class="dashboard-progress-grid">
    <section class="dashboard-continue"><span class="writer-kicker"><?=$recentDraft?'Continue writing':'Make room for a new idea'?></span><h2><?=e($recentDraft['title']??'What is on your mind?')?></h2><p><?=e($recentDraft?($recentDraft['standfirst']?:'Your private draft is ready to continue.'):'Capture a question, an observation or a voice-dictated note.')?></p><a class="wbtn" href="<?=url($recentDraft?'/write/edit/'.$recentDraft['id']:'/write/capture')?>"><?=$recentDraft?'Continue draft':'Capture an idea'?> →</a></section>
    <section class="dashboard-pipeline" aria-labelledby="pipeline-title"><h2 id="pipeline-title">Publishing overview</h2><div class="dashboard-grid writer-stats">
      <?php foreach(['draft'=>['Drafts','Private articles'],'scheduled'=>['Scheduled','Future articles'],'published'=>['Published','Publicly available'],'selected'=>['Selected','Featured articles']]as$key=>$labels):?><button class="stat" type="button" aria-pressed="false" data-writer-filter="<?=e($key)?>"><strong><?=intval($counts[$key]??0)?></strong><span><?=e($labels[0])?></span><small><?=e($labels[1])?></small></button><?php endforeach;?>
      </div><p><?=intval($workspace['scheduled']??0)?> scheduled social deliveries · <?=intval($workspace['connected']??0)?> of <?=count(publishing_networks())?> platforms with saved account access</p><a href="<?=url('/write/media-centre#connect')?>">Manage social connections →</a></section>
  </div>

  <details class="dashboard-journeys"><summary>Website &amp; workspace · manage each visitor journey</summary><p>Public pages serve visitors. Reviews, personal information and delivery controls stay in the protected workspace.</p><div class="dashboard-destination-grid">
    <?php foreach(workspace_home_journeys()as[$label,$public,$private,$flow]):?><article><h3><?=e($label)?></h3><small><?=e($flow)?></small><div><a href="<?=url($public)?>" target="_blank" rel="noopener noreferrer">View public page<span class="sr-only">: <?=e($label)?></span> ↗</a> · <a href="<?=url($private)?>">Manage<span class="sr-only"> <?=e($label)?></span> →</a></div></article><?php endforeach;?>
  </div><p><a class="wbtn" href="<?=url('/write/control-centre')?>">All workspaces →</a></p></details>
  <section class="writer-library" id="library">
    <div class="writer-library-head"><div><span class="writer-kicker">Library</span><h2>Your article library.</h2></div><div class="writer-library-tools"><label class="writer-search"><span aria-hidden="true">⌕</span><input type="search" aria-label="Filter articles on this page" placeholder="Filter this page" data-writer-search autocomplete="off"></label><select data-writer-status aria-label="Filter posts by status"><option value="all">All statuses</option><option value="draft">Drafts</option><option value="scheduled">Scheduled</option><option value="published">Published</option><option value="selected">Selected</option><option value="held">Held from public view</option></select></div></div>

    <form class="dashboard-library-search" method="get" action="<?=url('/write#library')?>"><label for="library-query">Search the whole library</label><div><input id="library-query" name="q" type="search" maxlength="120" value="<?=e($libraryQuery??'')?>" placeholder="Title, topic or saved draft"><button class="wbtn" type="submit">Search all</button><?php if(($libraryQuery??'')!==''):?><a href="<?=url('/write#library')?>">Clear search</a><?php endif;?></div></form>
    <p class="dashboard-footnote">Page <?=intval($libraryPage??1)?> · Most recently updated <?=count($posts)?> of <?=intval($counts['total']??count($posts))?> articles. Counts above cover the whole library; quick filters apply to this page.</p><p class="dashboard-footnote" data-writer-results aria-live="polite"></p>
    <div class="writer-post-list" data-writer-list>
      <?php if(!$posts):?><div class="writer-empty"><strong><?=($libraryQuery??'')!==''?'No articles match this search.':(($libraryPage??1)>1?'No more articles on this page.':'Your first thought starts here.')?></strong><p>Search another phrase, return to an earlier page or start a new draft.</p><a class="wbtn primary" href="<?=url('/write/new')?>">Start a thought</a></div><?php endif;?>
      <?php foreach($posts as $p):$libraryStatus=!empty($p['_is_public'])?'published':(in_array($p['status'],['published','scheduled'],true)&&!empty($p['published_at'])&&strtotime($p['published_at'])>time()?'scheduled':(in_array($p['status'],['published','scheduled'],true)?'held':$p['status']));?>
        <article class="writer-post-row" data-writer-post data-status="<?=e($libraryStatus)?>" data-selected="<?=!empty($p['is_selected'])?'1':'0'?>" data-search="<?=e(mb_strtolower(($p['title']??'').' '.($p['standfirst']??'').' '.($p['category_name']??'')))?>">
          <a class="writer-post-main" href="<?=url('/write/edit/'.$p['id'])?>"><span class="status <?=e($libraryStatus)?>"><?=e($libraryStatus)?></span><?php if(!empty($p['_has_draft'])):?><small> · Saved working draft</small><?php endif;?><h3><?=e($p['title'] ?: 'Untitled')?></h3><p><?=e($p['standfirst'] ?: 'No standfirst yet.')?></p></a>
          <div class="writer-post-meta"><span><?=e($p['category_name']??'No topic')?></span><?php if(!empty($p['is_selected'])):?><span class="selected-mark">Selected</span><?php endif;?><small>Updated <?=e(format_date($p['updated_at']))?></small></div>
          <div class="writer-post-actions"><a href="<?=url('/write/edit/'.$p['id'])?>">Edit</a><a href="<?=url('/write/preview/'.$p['id'])?>">Preview</a><?php if(!empty($p['_is_public'])):?><a href="<?=url('/my-thoughts/'.$p['slug'])?>" target="_blank" rel="noopener noreferrer">View live ↗</a><?php endif;?></div>
        </article>
      <?php endforeach;?>
      <div class="writer-empty" data-writer-empty hidden><strong>No thoughts match that filter.</strong><p>Try another status or a broader search phrase.</p></div>
    </div><button class="wbtn" type="button" data-writer-more hidden>Show more articles</button>
    <?php if(($libraryPage??1)>1||count($posts)===200):?><nav class="dashboard-library-pages" aria-label="Article library pages"><?php if(($libraryPage??1)>1):?><a class="wbtn" href="<?=url('/write?'.http_build_query(['page'=>$libraryPage-1,'q'=>$libraryQuery??'']).'#library')?>">← Previous page</a><?php endif;?><?php if(count($posts)===200):?><a class="wbtn" href="<?=url('/write?'.http_build_query(['page'=>($libraryPage??1)+1,'q'=>$libraryQuery??'']).'#library')?>">Next page →</a><?php endif;?></nav><?php endif;?>
  </section>
</main>
