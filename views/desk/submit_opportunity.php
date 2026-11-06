<link rel="stylesheet" href="<?=e(asset('css/publishing-desk.css'))?>">
<section class="desk desk-public"><div class="container">
<header class="desk-hero"><div><p class="desk-kicker">Share a programme, scholarship, grant or research opportunity</p><h1>Submit an opportunity for editorial review.</h1><p>Send the official source and the facts you know. The submission stays private while the system structures it, flags missing or unverified details, and an authorised editor checks the source before anything can enter the public catalogue.</p></div><div><img class="desk-monogram" src="<?=asset('images/wn-approved-monogram.svg')?>" alt="Waithaka Ndegwa monogram"><p>People · Ideas · Impact</p></div></header>
<?php if($received):?><section class="desk-card"><h2>Submission received.</h2><p>Thank you. The record is now private in the editorial review queue. AI may assist the editor with structuring after explicit review consent, but the opportunity will not be published until the official source, eligibility, dates and application destination have been checked and the exact version is approved.</p><div class="desk-actions"><a class="desk-button primary" href="<?=url('/opportunities')?>">Back to Opportunities</a><a class="desk-button" href="<?=url('/opportunities/suggest')?>">Submit another</a></div></section>
<?php else:?>
<?php if($error):?><p class="desk-alert" role="alert"><?=e($error)?></p><?php endif;?>
<form method="post" action="<?=url('/opportunities/suggest')?>" class="desk-form"><?=csrf_field()?>
<div class="opp-trap" aria-hidden="true"><label>Leave empty<input name="website_repeat" tabindex="-1" autocomplete="off"></label></div>
<section class="desk-card"><h2>Private contact</h2><p class="desk-note">Used only if the editor needs clarification. It is never included in the public opportunity or sent to AI.</p><div class="desk-grid"><label class="desk-field"><span>Your name</span><input name="contact_name" maxlength="120" required value="<?=e($values['contact_name']??'')?>"></label><label class="desk-field"><span>Your email</span><input name="contact_email" type="email" maxlength="254" required value="<?=e($values['contact_email']??'')?>"></label></div></section>
<section class="desk-card"><h2>Opportunity facts</h2><div class="desk-grid">
<label class="desk-field"><span>Opportunity title</span><input name="title" maxlength="180" required value="<?=e($values['title']??'')?>"></label>
<label class="desk-field"><span>Organisation / provider</span><input name="organisation" maxlength="180" required value="<?=e($values['organisation']??'')?>"></label>
<label class="desk-field"><span>Type</span><select name="type"><?php foreach(desk_types()as$key=>$label):?><option value="<?=e($key)?>" <?=($values['type']??'grant')===$key?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label>
<label class="desk-field"><span>Official source URL</span><input name="source_url" type="url" required placeholder="https://…" value="<?=e($values['source_url']??'')?>"></label>
<label class="desk-field"><span>Direct application URL · if known</span><input name="apply_url" type="url" placeholder="https://…" value="<?=e($values['apply_url']??'')?>"></label>
<label class="desk-field"><span>Deadline status</span><select name="deadline_mode"><option value="unknown" <?=($values['deadline_mode']??'unknown')==='unknown'?'selected':''?>>Unknown / programme-specific</option><option value="rolling" <?=($values['deadline_mode']??'')==='rolling'?'selected':''?>>Rolling</option><option value="fixed" <?=($values['deadline_mode']??'')==='fixed'?'selected':''?>>Fixed date and time</option></select></label>
<label class="desk-field"><span>Fixed deadline · if known</span><input name="deadline" type="datetime-local" value="<?=e($values['deadline']??'')?>"></label>
<label class="desk-field"><span>Timezone</span><input name="timezone" value="<?=e($values['timezone']??'Africa/Nairobi')?>" required></label>
<label class="desk-field"><span>Eligible countries · if known</span><input name="countries" maxlength="2000" value="<?=e($values['countries']??'')?>" placeholder="Kenya, Uganda, Africa-wide, worldwide…"></label>
<label class="desk-field"><span>Sector / discipline</span><input name="sectors" maxlength="1000" value="<?=e($values['sectors']??'')?>"></label>
</div>
<label class="desk-field"><span>Short summary</span><textarea name="summary" rows="3" maxlength="800" required><?=e($values['summary']??'')?></textarea></label>
<label class="desk-field"><span>Eligibility · if known</span><textarea name="eligibility" rows="4" maxlength="5000"><?=e($values['eligibility']??'')?></textarea></label>
<label class="desk-field"><span>Funding / scholarship / support terms · if known</span><textarea name="benefits" rows="4" maxlength="5000"><?=e($values['benefits']??'')?></textarea></label>
<label class="desk-field"><span>Additional notes</span><textarea name="description" rows="5" maxlength="16000"><?=e($values['description']??'')?></textarea></label>
<input type="hidden" name="currency" value="KES">
</section>
<label class="desk-check"><input type="checkbox" name="privacy_ack" value="1" required> I confirm this information may be reviewed editorially and the public source links may be checked. I understand submission does not guarantee publication, endorsement, eligibility or funding.</label>
<div class="desk-actions"><button class="desk-button primary" type="submit">Submit for editorial review</button><a class="desk-button" href="<?=url('/opportunities')?>">Cancel</a></div>
<p class="desk-note">Workflow: public submission → structured verification flags → optional AI-assisted drafting after authorised editor consent → source review → exact-version approval → publication into the existing catalogue.</p>
</form>
<?php endif;?></div></section>