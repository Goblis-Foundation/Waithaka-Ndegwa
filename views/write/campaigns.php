<main class="write-main">
  <div class="writer-head"><div><h1>Editorial campaigns</h1><p class="muted">Give each article a purpose, then coordinate its social versions and newsletter edition.</p></div><a class="wbtn" href="<?=url('/write/new')?>">New thought</a></div>
  <?php if(!$posts):?><div class="notice">Start by saving an article. Its campaign will connect the reading destination, channel links, social package and newsletter edition.</div><?php endif;?>
  <div class="settings-grid">
    <?php foreach($posts as$item):?><article class="settings-card"><span class="muted"><?=e(ucfirst($item['status']))?></span><h2><a href="<?=url('/write/campaigns/'.$item['id'])?>"><?=e($item['title'])?></a></h2><p><?=e(campaign_objectives()[$item['objective']]??'Choose the reader action this article should support.')?></p><?php if($item['success_measure']):?><p><?=e($item['success_measure'])?></p><?php endif;?><a class="wbtn" href="<?=url('/write/campaigns/'.$item['id'])?>">Plan & track</a></article><?php endforeach;?>
  </div>
  <?php include __DIR__.'/../partials_archive_pagination.php';?>
</main>
