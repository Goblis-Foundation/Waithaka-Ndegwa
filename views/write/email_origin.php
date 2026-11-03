<link rel="stylesheet" href="<?=asset('css/readiness.css')?>">
<main class="write-main readiness-main">
  <div class="writer-head"><div><span class="writer-kicker">Owner-only · read-only diagnostic</span><h1>Email origin review</h1></div><a class="wbtn" href="<?=url('/write/operations#email-attention')?>">Back to Operations</a></div>
  <p>This view identifies the original queue category. It does not send, suppress or approve a message. Recipients, message bodies and private access links are not displayed.</p>
  <section class="readiness-section"><h2>Outbox #<?=intval($emailReview['id'])?></h2>
    <dl><dt>Queue state</dt><dd><?=e(ucfirst($emailReview['status']))?></dd><dt>Created</dt><dd><?=e(readiness_time($emailReview['created_at'],'Africa/Nairobi'))?></dd><dt>Delivery attempts</dt><dd><?=intval($emailReview['attempts'])?></dd><dt>Original category</dt><dd><?=e($emailReview['category'])?></dd></dl>
    <p class="readiness-note"><?=e($emailReview['guidance'])?></p>
    <p>A recognized category does not prove current consent, source availability or delivery eligibility. Follow the original source workflow before changing delivery.</p>
  </section>
</main>
