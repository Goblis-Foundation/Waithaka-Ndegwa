<?php
$locationValues=$locationValues??[];$locationPrefix=$locationPrefix??'location';$locationPhone=$locationPhone??true;$locationAllRequired=$locationAllRequired??false;
$lv=static fn(string $key,string $default=''):string=>isset($locationValues[$key])&&is_string($locationValues[$key])?$locationValues[$key]:$default;
$selectedCountry=$lv('country','Kenya');if($selectedCountry==='')$selectedCountry='Kenya';
?>
<link rel="stylesheet" href="<?=asset('css/location-fields.css')?>">
<fieldset class="location-fields" data-location-fields <?=!empty($locationAllRequired)?'data-location-required="1"':''?>>
<legend><?=e($locationLegend??'Contact & location')?></legend>
<?php if($locationPhone):?><div class="location-grid">
<label for="<?=e($locationPrefix)?>-phone-code">Country calling code<input id="<?=e($locationPrefix)?>-phone-code" name="phone_code" <?=!empty($locationAllRequired)?'required':''?> type="tel" autocomplete="tel-country-code" inputmode="tel" maxlength="4" pattern="\+[1-9][0-9]{0,2}" value="<?=e($lv('phone_code','+254'))?>"></label>
<label for="<?=e($locationPrefix)?>-phone">Phone number<?=!empty($locationAllRequired)?' · required':' · optional'?><input id="<?=e($locationPrefix)?>-phone" name="phone" <?=!empty($locationAllRequired)?'required':''?> type="tel" autocomplete="tel-national" inputmode="tel" maxlength="60" value="<?=e($lv('phone'))?>" placeholder="e.g. 0712 345 678"></label>
</div><p class="location-help">Kenya’s +254 is the default. Change the calling code for another number, or enter its full international number starting with +. This does not start a WhatsApp conversation.</p><?php endif;?>
<label for="<?=e($locationPrefix)?>-country"><?=e($locationCountryLabel??'Country or territory')?><select id="<?=e($locationPrefix)?>-country" name="country" autocomplete="country-name" data-location-country <?=!empty($locationCountryRequired)?'required':''?>><?php $countries=country_options();if(!in_array($selectedCountry,$countries,true))$countries[]=$selectedCountry;foreach($countries as$country):?><option value="<?=e($country)?>" <?=$country===$selectedCountry?'selected':''?>><?=e($country)?></option><?php endforeach;?></select></label>
<div class="location-grid location-country-specific" data-location-kenya <?=!empty($locationAllRequired)&&$selectedCountry!=='Kenya'?'hidden':''?>>
<label for="<?=e($locationPrefix)?>-county">County<select id="<?=e($locationPrefix)?>-county" name="county" <?=!empty($locationAllRequired)?'data-location-required-field '.($selectedCountry==='Kenya'?'required':'disabled'):''?> autocomplete="address-level1"><option value="">Choose county<?=!empty($locationAllRequired)?'':' · optional'?></option><?php foreach(location_kenya_counties()as$county):?><option value="<?=e($county)?>" <?=$county===$lv('county')?'selected':''?>><?=e($county)?></option><?php endforeach;?></select></label>
<label for="<?=e($locationPrefix)?>-sub-county">Sub-County<input id="<?=e($locationPrefix)?>-sub-county" name="sub_county" <?=!empty($locationAllRequired)?'data-location-required-field '.($selectedCountry==='Kenya'?'required':'disabled'):''?> autocomplete="address-level2" maxlength="120" value="<?=e($lv('sub_county'))?>" placeholder="Your sub-county<?=!empty($locationAllRequired)?'':' · optional'?>"></label>
<label for="<?=e($locationPrefix)?>-ward">Ward<?=!empty($locationAllRequired)?'':' · optional'?><input id="<?=e($locationPrefix)?>-ward" name="ward" <?=!empty($locationAllRequired)?'data-location-required-field '.($selectedCountry==='Kenya'?'required':'disabled'):''?> autocomplete="address-level3" maxlength="120" value="<?=e($lv('ward'))?>" placeholder="<?=!empty($locationAllRequired)?'Your ward':'Your ward, if known'?>"></label>
<p class="location-help location-kenya-help">Select one of Kenya’s 47 counties. <?=!empty($locationAllRequired)?'Complete your Sub-County and Ward.':'Enter your Sub-County and Ward as applicable.'?> Neither field is a constituency list.</p>
</div>
<div class="location-grid location-country-specific" data-location-international <?=!empty($locationAllRequired)&&$selectedCountry==='Kenya'?'hidden':''?>>
<label for="<?=e($locationPrefix)?>-region">State / Province / Region<input id="<?=e($locationPrefix)?>-region" name="region" <?=!empty($locationAllRequired)?'data-location-required-field '.($selectedCountry!=='Kenya'?'required':'disabled'):''?> autocomplete="address-level1" maxlength="120" value="<?=e($lv('region'))?>" placeholder="<?=!empty($locationAllRequired)?'Enter N/A if this level does not apply':'Outside Kenya · if applicable'?>"></label>
<label for="<?=e($locationPrefix)?>-district">District / Area<input id="<?=e($locationPrefix)?>-district" name="district" <?=!empty($locationAllRequired)?'data-location-required-field '.($selectedCountry!=='Kenya'?'required':'disabled'):''?> autocomplete="address-level2" maxlength="120" value="<?=e($lv('district'))?>" placeholder="<?=!empty($locationAllRequired)?'Enter N/A if this level does not apply':'Outside Kenya · if applicable'?>"></label>
</div>
<label for="<?=e($locationPrefix)?>-city"><span data-location-city-label><?=$selectedCountry==='Kenya'?'Town / Area':'City / Town / Locality'?></span><input id="<?=e($locationPrefix)?>-city" name="city" <?=!empty($locationAllRequired)?'required':''?> autocomplete="address-level4" maxlength="120" value="<?=e($lv('city'))?>" placeholder="Where you are based<?=!empty($locationAllRequired)?'':' · optional'?>"></label>
</fieldset>
<script src="<?=asset('js/location-fields.js')?>" defer></script>

<?php $locationAllRequired=false; ?>
