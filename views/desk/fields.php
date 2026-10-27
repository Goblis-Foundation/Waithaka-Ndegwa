<?php
$brief=$publicBrief??false;
$input=static function(string $key,string $label,string $type='text',bool $required=false,string $help='')use($values):void{?>
<label class="desk-field"><span><?=e($label)?><?=$required?' *':''?></span><input name="<?=e($key)?>" type="<?=e($type)?>" value="<?=e((string)($values[$key]??''))?>" <?=$required?'required':''?> <?=$type==='number'?'step="0.01" min="0"':''?> maxlength="2000"><?php if($help):?><small><?=e($help)?></small><?php endif;?></label>
<?php };
$area=static function(string $key,string $label,bool $required=false,int $rows=3,string $help='')use($values):void{?>
<label class="desk-field desk-wide"><span><?=e($label)?><?=$required?' *':''?></span><textarea name="<?=e($key)?>" rows="<?=$rows?>" maxlength="16000" <?=$required?'required':''?>><?=e((string)($values[$key]??''))?></textarea><?php if($help):?><small><?=e($help)?></small><?php endif;?></label>
<?php }; ?>
<fieldset class="desk-card"><legend>1 · The essentials</legend><div class="desk-grid">
<?php $input('title',$kind==='opportunity'?'Opportunity title':'Feature title','text',true);$input('organisation',$kind==='opportunity'?'Funder or organiser':'Business or organisation','text',true);?>
<?php if($kind==='opportunity'):?><label class="desk-field"><span>Opportunity type</span><select name="type" data-desk-type><?php foreach(desk_types()as$key=>$label):?><option value="<?=e($key)?>" <?=($values['type']??'grant')===$key?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label><?php endif;?>
<?php $input('sectors','Relevant sectors','text',false,'For example: agriculture, education, climate or technology.');$area('summary','Short summary',true,3);$area('description','Full description',true,6);?>
</div></fieldset>
<?php if($kind==='opportunity'):?>
<fieldset class="desk-card"><legend>2 · Eligibility and benefits</legend><div class="desk-grid">
<?php $area('eligibility','Who is eligible?',false,4);$input('countries','Eligible countries or regions','text',false,'Use the actual eligibility wording. Do not infer worldwide access.');$input('instrument','Funding or support type','text',false,'Grant, loan, equity, prize, stipend, mentorship or another verified type.');$input('amount_min','Funding amount / minimum','number');$input('amount_max','Funding maximum','number');$input('currency','Currency code','text',false,'Leave amounts blank when they are not published.');$area('benefits','Benefits and support');$area('conditions','Conditions, exclusions or matching funding');?>
</div></fieldset>
<fieldset class="desk-card"><legend>3 · Dates, application and official source</legend><div class="desk-grid">
<label class="desk-field"><span>Deadline type</span><select name="deadline_mode"><option value="unknown" <?=($values['deadline_mode']??'unknown')==='unknown'?'selected':''?>>Not specified</option><option value="fixed" <?=($values['deadline_mode']??'')==='fixed'?'selected':''?>>Fixed deadline</option><option value="rolling" <?=($values['deadline_mode']??'')==='rolling'?'selected':''?>>Rolling applications</option></select></label>
<?php $input('timezone','Original timezone','text',true,'Use an IANA name, for example Africa/Nairobi or Europe/London.');$input('opens_at','Applications open','datetime-local');$input('deadline','Application deadline','datetime-local');$input('source_url','Official announcement URL','url');$input('apply_url','Official application / registration URL','url');$input('verified_on','Last manually verified','date');$input('contact_public','Published contact details');$area('documents','Required documents');$area('steps','How to apply',false,4);?>
</div><details><summary>Conference or programme details</summary><div class="desk-grid">
<?php $input('event_start','Event or programme starts','datetime-local');$input('event_end','Event or programme ends','datetime-local');$input('location','Venue or online / hybrid details');$input('costs','Registration costs');$area('travel_support','Verified travel and accommodation support');?>
</div></details></fieldset>
<?php else:?>
<fieldset class="desk-card"><legend>2 · The offer and customer action</legend><div class="desk-grid">
<?php $area('offer','Product, service, price and offer details',false,4);$area('audience','Who is it for?');$area('benefits','Benefits you can substantiate');$area('conditions','Conditions and limitations');$input('purchase_url','Purchase or enquiry URL','url',true);$input('cta_label','Button label','text',false,'For example: Enquire with the provider.');$input('offer_expires','Offer expiry, where applicable','datetime-local');$input('timezone','Offer timezone','text',true);$input('source_url','Supporting public website','url');$area('claims_evidence','Private evidence supporting product claims');?>
</div></fieldset>
<?php endif;?>
<?php if(!$brief):?><fieldset class="desk-card"><legend>Private editorial checks</legend><div class="desk-grid"><?php $area('source_excerpt','Official announcement text / source excerpt',false,6,'Kept private. Only sent to AI when you explicitly request a draft.');$area('verification_notes','Private verification notes');$area('asset_permissions','Image rights and permissions');?></div></fieldset><?php endif;?>
<?php if($kind==='sponsored'||!$brief):?><fieldset class="desk-card"><legend>Sponsorship and client contact</legend>
<?php if($kind==='opportunity'):?><label class="desk-check"><input type="checkbox" name="sponsored" value="1" <?=!empty($values['sponsored'])?'checked':''?>>This is a paid / sponsored opportunity placement.</label><p class="desk-note">Once classified as sponsored, the paid-content safeguards cannot be removed by editing this form.</p><?php endif;?>
<div class="desk-grid"><?php $input('sponsor_name','Public sponsor name');$input('client_name','Client contact name','text',$brief);$input('client_email','Client contact email','email',$brief);?></div>
</fieldset><?php endif;?>
