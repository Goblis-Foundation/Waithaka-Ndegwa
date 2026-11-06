<link rel="stylesheet" href="<?=asset('css/activation.css')?>">
<main class="write-main activation-main" aria-labelledby="activation-title">
  <header class="activation-header">
    <div><p class="writer-kicker">Release control · owner workspace</p><h1 id="activation-title">Activation & launch readiness</h1>
      <p>Complete the last connection, delivery and evidence gates without losing what is already deployed. This overview reads the site's saved configuration only; it never authorizes a provider, charges a contributor, sends mail or publishes a post.</p>
    </div>
    <a class="wbtn" href="<?=url('/write/operations')?>">View live operations</a>
  </header>
  <div class="activation-summary" aria-label="Project recovery summary">
    <div><strong><?= $activationWorkAreas===null?'Not available':(int)$activationWorkAreas ?></strong><span>Preserved work areas</span></div>
    <div><strong><?=count($activationRows)?></strong><span>Open external acceptance gates</span></div>
    <div><strong><?=!empty($activationWorker)?'Enabled in config':'Paused in config'?></strong><span>Outbound worker setting — not delivery proof</span></div>
  </div>
  <p class="activation-stamp">Local state read: <?=e((new DateTimeImmutable($activationObservedAt))->setTimezone(new DateTimeZone('Africa/Nairobi'))->format('j M Y H:i T'))?>. Stored connection status, credentials or API test results are not evidence of an actual inbox delivery, payment settlement or social publication.</p>
  <nav class="activation-links" aria-label="Activation support">
    <a href="<?=url('/write/reconciliation')?>">Full implementation register</a>
    <a href="<?=url('/write/communication?tab=content')?>">Editorial and weekly calendar</a>
    <a href="<?=url('/write/monthly-roundup')?>">Monthly edition</a>
    <a href="<?=url('/write/operations')?>">Worker and outbox evidence</a>
  </nav>
  <section class="activation-section" aria-labelledby="activation-oauth-title">
    <div class="activation-section-heading"><div><h2 id="activation-oauth-title">Owner authorization queue</h2><p>Work through the remaining OAuth providers one at a time. Each button starts that provider's existing least-privilege consent flow. A returned identity must still be confirmed before the connection is treated as ready.</p></div></div>
    <div class="activation-connection-grid">
      <?php foreach(($activationOauthRows??[])as$oauth):?>
      <article class="activation-connection" aria-labelledby="activation-oauth-<?=e($oauth['service'])?>">
        <h3 id="activation-oauth-<?=e($oauth['service'])?>"><?=e($oauth['name'])?></h3>
        <p class="activation-status"><?=e(match($oauth['state']){'connected'=>'Connected and confirmed','pending_confirmation'=>'Provider returned an account · confirmation needed','reconnect'=>'Reconnect required','other_owner'=>'Owner connection required','disconnected'=>'Not connected',default=>'Status unavailable'})?></p>
        <?php if($oauth['complete']):?>
          <a class="wbtn" href="<?=url($oauth['review_path'])?>">Review connection</a>
        <?php elseif($oauth['state']==='pending_confirmation'):?>
          <a class="wbtn primary" href="<?=url($oauth['review_path'])?>">Confirm returned account</a>
        <?php elseif($oauth['configured']):?>
          <form method="post" action="<?=url($oauth['start_path'])?>"><?=csrf_field()?><button class="wbtn primary" type="submit"><?=e($oauth['state']==='reconnect'?'Reconnect':'Authorize')?> <?=e($oauth['name'])?></button></form>
        <?php else:?>
          <a class="wbtn primary" href="<?=url($oauth['review_path'])?>">Complete app setup</a>
        <?php endif;?>
      </article>
      <?php endforeach;?>
    </div>
    <p class="activation-stamp">This queue does not publish content, send messages, make payments or buy API access. Telegram, Discord and Signal use their separate destination setup; payment providers use Finance verification; Microsoft delegated actions and physical-device permissions remain in their dedicated workspaces.</p>
  </section>
  <section class="activation-section" aria-labelledby="activation-gates-title">
    <div class="activation-section-heading"><div><h2 id="activation-gates-title">External acceptance gates</h2><p>Each row states what the system can currently establish and the next verifiable action. These seven acceptance streams remain tracked until their separate criteria are evidenced. A GitHub issue closure alone does not establish activation.</p></div></div>
    <div class="activation-grid">
      <?php foreach($activationRows as $gate):?>
        <article class="activation-card" aria-labelledby="activation-issue-<?=intval($gate['issue'])?>">
          <div class="activation-card-head"><span class="activation-issue">Issue #<?=intval($gate['issue'])?></span><span class="activation-status"><?=e($gate['acceptance'])?></span></div>
          <h3 id="activation-issue-<?=intval($gate['issue'])?>"><?=e($gate['title'])?></h3>
          <dl><div><dt>Local evidence</dt><dd><?=e($gate['observed'])?></dd></div>
              <div><dt>To make this live</dt><dd><?=e($gate['next'])?></dd></div></dl>
          <div class="activation-actions"><a class="wbtn primary" href="<?=url($gate['path'])?>"><?=e($gate['action'])?></a>
            <a class="wbtn" href="https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/<?=intval($gate['issue'])?>" target="_blank" rel="noopener noreferrer">Acceptance details #<?=intval($gate['issue'])?></a></div>
        </article>
      <?php endforeach;?>
    </div>
  </section>
  <section class="activation-section" aria-labelledby="activation-connections-title">
    <div class="activation-section-heading"><h2 id="activation-connections-title">Community & activity connections</h2><p>Open the next setup step for each requested service. These are saved-state checks, not a live account test or evidence that a post was sent. Activity reports and weekly, monthly and annual targets stay private.</p></div>
    <div class="activation-connection-grid">
      <?php foreach(($activationConnections??activation_connection_rows([]))as$connection):?>
      <article class="activation-connection" aria-labelledby="activation-connection-<?=e($connection['key'])?>"><h3 id="activation-connection-<?=e($connection['key'])?>"><?=e($connection['name'])?></h3><p class="activation-status"><?=e($connection['status'])?></p><p><?=e($connection['next'])?></p><a class="wbtn" href="<?=url($connection['path'])?>">Set up <?=e($connection['name'])?></a></article>
      <?php endforeach;?>
    </div>
    <p class="activation-stamp">A saved import does not certify complete device history. Signal uses assisted posting; Google Fit and Samsung Health use reviewed activity files. Cloud reports require their own read consent.</p>
  </section>
  <section class="activation-note" aria-labelledby="activation-controls-title"><h2 id="activation-controls-title">Release controls retained</h2>
    <p>The existing website, original writing and media, scheduled public monthly roundup, approved identity and source records remain unchanged. A queued email is not sent automatically from this page. Payments require provider-confirmed settlement; social publication needs an approved revision and verified destination; private organizational records are never promoted into Goal 2030 results without source publication approval.</p>
    <p>Automated website accessibility and navigation checks complement, but do not replace, actual physical-device testing. Use the implementation register to review every earlier task and avoid treating a missing result as zero.</p>
  </section>
</main>
