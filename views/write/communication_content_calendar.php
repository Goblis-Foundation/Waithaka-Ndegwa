<?php
/** Private Writer view. No page load sends mail, posts, fetches external
 * accounts, starts a payment or publishes organizational statements. */
$calendar=$editorialCycle??['rows'=>[],'prepared_at'=>''];
$settings=$editorialCycleConfig??communications_cycle_defaults();
$stored=$editorialCycleStored??[];
$sender=newsletter_sender();
$mailReady=filter_var($sender['from']??'',FILTER_VALIDATE_EMAIL)
   &&trim((string)($sender['name']??''))!==''&&trim((string)($sender['postal_address']??''))!==''
   &&email_transport_ready();
$ready=count(array_filter($calendar['rows'],static fn($row)=>($row['kind']??'')==='daily'&&$row['copy']!==''));
$blocked=count(array_filter($calendar['rows'],static fn($row)=>($row['kind']??'')==='daily'&&$row['copy']===''));
?>
<section class="comm-panel comm-editorial-cycle" aria-labelledby="comm-cycle-title">
 <div class="comm-editorial-intro">
  <div><span class="writer-kicker">One marketing calendar · accountable sources</span><h2 id="comm-cycle-title">Editorial & communications calendar</h2>
   <p>Prepare daily organization briefs, one original article each week and a weekly newsletter draft. A scheduled editorial idea is never a published post, an email delivery or a verified impact result.</p>
  </div>
  <div class="comm-editorial-links"><a class="wbtn primary" href="<?=url('/write/publishing')?>">Social publishing</a>
   <a class="wbtn" href="<?=url('/write/newsletter')?>">Newsletter editions</a>
   <a class="wbtn" href="<?=url('/write/campaigns')?>">Campaign review</a>
   <a class="wbtn" href="<?=url('/write/impact')?>">Approved source data</a></div>
 </div>
 <div class="comm-editorial-stats">
  <article><span>Organization briefs with approved source data</span><strong><?=$ready?></strong><small>Editorial approval still required</small></article>
  <article><span>Organization briefs awaiting source verification</span><strong><?=$blocked?></strong><small>No unverifiable daily claims are drafted</small></article>
  <article><span>Social posting engine</span><strong><?=($publishingOverview['worker_enabled']??false)&&($publishingOverview['weekly_enabled']??false)?'Eligible':'Gated'?></strong><small>Account, revision and provider verification remain separate</small></article>
  <article><span>Newsletter sender</span><strong><?=$mailReady?'Configured':'Incomplete'?></strong><small>Postal address, consent, transport and edition approval are mandatory</small></article>
 </div>
 <p class="comm-editorial-note"><strong>Worker-generated plan:</strong> <?=!empty($stored['prepared_at'])?e($stored['prepared_at']).' UTC':'No stored worker snapshot yet'?>. The schedule below is also computed privately when you open this tab; it does not imply the background worker is running.</p>
 <div class="comm-editorial-steps" aria-label="Reviewed communications workflow">
  <span><strong>1.</strong> Source evidence</span><span><strong>2.</strong> Original draft</span>
  <span><strong>3.</strong> Editorial approval</span><span><strong>4.</strong> Exact channel & recipients</span>
  <span><strong>5.</strong> Scheduled release</span><span><strong>6.</strong> Provider receipt & reporting</span>
 </div>
 <h3>Next 14 days · Africa/Nairobi</h3>
 <div class="comm-editorial-scroll"><table class="comm-editorial-table"><thead><tr>
  <th>Date & time</th><th>Editorial task</th><th>Source and readiness</th><th>Next action</th>
  </tr></thead><tbody>
  <?php foreach($calendar['rows'] as$row):?>
   <tr><td><time><?=e($row['when'])?> EAT</time></td>
   <td><strong><?=e($row['label'])?></strong><small><?=e($row['title'])?></small></td>
   <td><strong><?=e($row['status'])?></strong><small><?=e($row['note'])?></small>
    <?php if($row['kind']==='daily'):?>
      <a href="<?=e($row['source_url'])?>" target="_blank" rel="noopener noreferrer">Source organization</a>
      <?php if($row['copy']!==''):?><details><summary>View source-grounded draft for review</summary><p class="comm-editorial-preview"><?=e($row['copy'])?></p><p>This copy is not an organizational statement issued by Waithaka Ndegwa or the source.</p></details><?php endif;?>
    <?php endif;?>
   </td>
   <td><a class="wbtn" href="<?=url($row['action'])?>"><?=e($row['kind']==='daily'?'Prepare approved channel copy':($row['kind']==='article'?'Write original article':'Review weekly newsletter'))?></a></td>
   </tr>
  <?php endforeach;?></tbody></table></div>
 <p class="comm-editorial-note">The weekly email automation creates a <strong>private draft only</strong> after the selected weekday/time when a published article exists in the current week. Its content is never approved, scheduled or emailed automatically. <a href="<?=url('/write/newsletter')?>">Review drafts and confirmed-reader controls</a>.</p>
 <details class="comm-editorial-settings"><summary>Configure editorial preparation cadence</summary>
  <form method="post" action="<?=url('/write/communication?tab=content')?>">
   <?=csrf_field()?><input type="hidden" name="action" value="editorial_cycle_config">
   <label class="comm-editorial-check"><input type="checkbox" name="enabled" value="1" <?=$settings['enabled']?'checked':''?>> Prepare private editorial briefs automatically</label>
   <label class="comm-editorial-check"><input type="checkbox" name="weekly_draft_enabled" value="1" <?=$settings['weekly_draft_enabled']?'checked':''?>> Create a weekly newsletter <em>draft</em> when a published article exists</label>
   <div class="comm-editorial-inputs">
    <label>Daily brief time (EAT)<input type="time" name="daily_time" value="<?=e($settings['daily_time'])?>" required></label>
    <label>Weekly article weekday<select name="article_day"><?php foreach([1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'] as$n=>$day):?><option value="<?=$n?>" <?=$settings['article_day']===$n?'selected':''?>><?=e($day)?></option><?php endforeach;?></select></label>
    <label>Article preparation time<input type="time" name="article_time" value="<?=e($settings['article_time'])?>" required></label>
    <label>Weekly newsletter weekday<select name="newsletter_day"><?php foreach([1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'] as$n=>$day):?><option value="<?=$n?>" <?=$settings['newsletter_day']===$n?'selected':''?>><?=e($day)?></option><?php endforeach;?></select></label>
    <label>Newsletter draft time<input type="time" name="newsletter_time" value="<?=e($settings['newsletter_time'])?>" required></label>
   </div>
   <button class="wbtn primary" type="submit">Save private preparation schedule</button>
  </form>
 </details>
 <p class="comm-editorial-note">The global posting worker and platform account scopes are controlled separately under <a href="<?=url('/write/publishing/automation')?>">Posting automation</a>. Facebook personal-professional profiles remain assisted; TikTok direct posting needs the distinct approved creator product. An email address is never enrolled without confirmation, and giving contacts are never subscribers by default.</p>
</section>
