<fieldset><legend>Travel and partial-day time off</legend>
<p>Block time for a trip, appointment or other commitment. Each block keeps its own timezone, even if your host timezone changes. The meeting buffer above also applies around these blocks.</p>
<p>These blocks affect new requests, approvals and rescheduling. Existing bookings are not moved or cancelled; review any conflicting bookings yourself. No location, travel estimate or calendar event is created.</p>
<input type="hidden" name="travel_blocks_present" value="1">
<input type="hidden" name="travel_blocks_revision" value="<?=e(!empty($error)&&is_string($_POST['travel_blocks_revision']??null)?$_POST['travel_blocks_revision']:booking_blocks_revision($cfg))?>">
<?php
$blockRows=[];
foreach($cfg['travel_blocks']??[]as$block){$blockZone=comm_zone($block['timezone']);$blockRows[]=['start'=>(new DateTimeImmutable('@'.$block['start']))->setTimezone($blockZone)->format('Y-m-d\TH:i'),'end'=>(new DateTimeImmutable('@'.$block['end']))->setTimezone($blockZone)->format('Y-m-d\TH:i'),'timezone'=>$blockZone->getName()];}
if(!empty($error)&&($_POST['travel_blocks_present']??'')==='1'&&is_array($_POST['travel_blocks']??null)){
    $blockRows=[];foreach(array_slice($_POST['travel_blocks'],0,20)as$block)if(is_array($block))$blockRows[]=['start'=>is_string($block['start']??null)?$block['start']:'','end'=>is_string($block['end']??null)?$block['end']:'','timezone'=>is_string($block['timezone']??null)?$block['timezone']:$cfg['timezone']];
}
if(count($blockRows)<20)$blockRows[]=['start'=>'','end'=>'','timezone'=>$cfg['timezone']];
foreach($blockRows as$blockIndex=>$block):?>
<fieldset><legend>Time block <?=$blockIndex+1?></legend><div class="comm-fields">
<label>Starts<input type="datetime-local" name="travel_blocks[<?=$blockIndex?>][start]" value="<?=e($block['start'])?>"></label>
<label>Ends<input type="datetime-local" name="travel_blocks[<?=$blockIndex?>][end]" value="<?=e($block['end'])?>"></label>
</div><label>Block timezone<input name="travel_blocks[<?=$blockIndex?>][timezone]" list="booking-block-timezones" value="<?=e($block['timezone'])?>" maxlength="100" autocomplete="off"></label></fieldset>
<?php endforeach;?>
<datalist id="booking-block-timezones"><?php foreach(DateTimeZone::listIdentifiers()as$blockZone):?><option value="<?=e($blockZone)?>"></option><?php endforeach;?></datalist>
<p>Save availability to add a block and reveal another blank row, up to 20 blocks. Clear both dates to remove a block. Each block can span up to 90 days.</p>
</fieldset>
