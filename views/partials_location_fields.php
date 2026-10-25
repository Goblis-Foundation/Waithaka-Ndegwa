<?php
$locationValues=$locationValues??[];$locationPrefix=$locationPrefix??'location';$locationPhone=$locationPhone??true;
$lv=static fn(string $key,string $default=''):string=>isset($locationValues[$key])&&is_string($locationValues[$key])?$locationValues[$key]:$default;
$selectedCountry=$lv('country','Kenya');if($selectedCountry==='')$selectedCountry='Kenya';
?>
<link rel="stylesheet" href="<?=asset('css/location-fields.css')?>">
<fieldset class="location-fields" data-location-fields>
<legend><?=e($locationLegend??'Contact & location')?></legend>
<?php if($locationPhone):?><div class="location-grid">
<label for="<?=e($locationPrefix)?>-phone-code">Country calling code<input id="<?=e($locationPrefix)?>-phone-code" name="phone_code" type="tel" autocomplete="tel-country-code" inputmode="tel" maxlength="4" pattern="\+[1-9][0-9]{0,2}" value="<?=e($lv('phone_code','+254'))?>"></label>
<label for="<?=e($locationPrefix)?>-phone">Phone number · optional<input id="<?=e($locationPrefix)?>-phone" name="phone" type="tel" autocomplete="tel-national" inputmode="tel" maxlength="60" value="<?=e($lv('phone'))?>" placeholder="e.g. 0712 345 678"></label>
</div><p class="location-help">Kenya’s +254 is the default. Change the calling code for another number, or enter its full international number starting with +. This does not start a WhatsApp conversation.</p><?php endif;?>
<label for="<?=e($locationPrefix)?>-country"><?=e($locationCountryLabel??'Country or territory')?><select id="<?=e($locationPrefix)?>-country" name="country" autocomplete="country-name" data-location-country <?=!empty($locationCountryRequired)?'required':''?>><?php $countries=country_options();if(!in_array($selectedCountry,$countries,true))$countries[]=$selectedCountry;foreach($countries as$country):?><option value="<?=e($country)?>" <?=$country===$selectedCountry?'selected':''?>><?=e($country)?></option><?php endforeach;?></select></label>
<div class="location-grid location-country-specific" data-location-kenya>
<label for="<?=e($locationPrefix)?>-county">County<select id="<?=e($locationPrefix)?>-county" name="county" autocomplete="address-level1"><option value="">Choose county · optional</option><?php foreach(location_kenya_counties()as$county):?><option value="<?=e($county)?>" <?=$county===$lv('county')?'selected':''?>><?=e($county)?></option><?php endforeach;?></select></label>
<label for="<?=e($locationPrefix)?>-sub-county">Sub-County<input id="<?=e($locationPrefix)?>-sub-county" name="sub_county" autocomplete="address-level2" maxlength="120" value="<?=e($lv('sub_county'))?>" placeholder="Your sub-county · optional"></label>
</div>
<div class="location-grid location-country-specific" data-location-international>
<label for="<?=e($locationPrefix)?>-region">State / Province / Region<input id="<?=e($locationPrefix)?>-region" name="region" autocomplete="address-level1" maxlength="120" value="<?=e($lv('region'))?>" placeholder="Outside Kenya · if applicable"></label>
<label for="<?=e($locationPrefix)?>-district">District / Area<input id="<?=e($locationPrefix)?>-district" name="district" autocomplete="address-level2" maxlength="120" value="<?=e($lv('district'))?>" placeholder="Outside Kenya · if applicable"></label>
</div>
<label for="<?=e($locationPrefix)?>-city"><span data-location-city-label><?=$selectedCountry==='Kenya'?'Town or Location':'City / Town / Locality'?></span><input id="<?=e($locationPrefix)?>-city" name="city" autocomplete="address-level3" maxlength="120" value="<?=e($lv('city'))?>" placeholder="Where you are based · optional"></label>
</fieldset>
<script src="<?=asset('js/location-fields.js')?>" defer></script>
