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
  <section class="activation-note" aria-labelledby="activation-controls-title"><h2 id="activation-controls-title">Release controls retained</h2>
    <p>The existing website, original writing and media, scheduled public monthly roundup, approved identity and source records remain unchanged. A queued email is not sent automatically from this page. Payments require provider-confirmed settlement; social publication needs an approved revision and verified destination; private organizational records are never promoted into Goal 2030 results without source publication approval.</p>
    <p>Automated website accessibility and navigation checks complement, but do not replace, actual physical-device testing. Use the implementation register to review every earlier task and avoid treating a missing result as zero.</p>
  </section>
</main>
