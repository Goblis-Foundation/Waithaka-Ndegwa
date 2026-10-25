<?php
declare(strict_types=1);

/** Kenya county names: https://kdsp.devolution.go.ke/counties (checked 25 October 2026).
 * Sub-counties and towns are entered as text; constituencies are not substituted. */
function location_kenya_counties(): array {
 return ['Baringo','Bomet','Bungoma','Busia','Elgeyo Marakwet','Embu','Garissa','Homa Bay','Isiolo','Kajiado','Kakamega','Kericho','Kiambu','Kilifi','Kirinyaga','Kisii','Kisumu','Kitui','Kwale','Laikipia','Lamu','Machakos','Makueni','Mandera','Marsabit','Meru','Migori','Mombasa',"Murang'a",'Nairobi City','Nakuru','Nandi','Narok','Nyamira','Nyandarua','Nyeri','Samburu','Siaya','Taita Taveta','Tana River','Tharaka Nithi','Trans Nzoia','Turkana','Uasin Gishu','Vihiga','Wajir','West Pokot'];
}
function location_text(mixed $value,string $label,int $max=120): string {
 if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||preg_match('/[\x00-\x1f\x7f]/u',$value))throw new InvalidArgumentException('Review '.$label.'.');
 $value=trim($value);if(mb_strlen($value)>$max)throw new InvalidArgumentException($label.' is too long.');return $value;
}
function location_fields(array $input): array {
 $country=location_text($input['country']??'Kenya','Country');if($country==='')$country='Kenya';
 if(strcasecmp($country,'Kenya')===0)$country='Kenya';
 $kenya=$country==='Kenya';$county=$kenya?location_text($input['county']??'','County'):'';
 if($county!==''&&!in_array($county,location_kenya_counties(),true))throw new InvalidArgumentException('Choose a Kenyan county from the list.');
 return ['country'=>$country,'county'=>$county,'sub_county'=>$kenya?location_text($input['sub_county']??'','Sub-County'):'',
 'region'=>$kenya?'':location_text($input['region']??'','State, province or region'),
 'district'=>$kenya?'':location_text($input['district']??'','District or area'),
 'city'=>location_text($input['city']??'','Town or location')];
}
function location_phone(array $input): array {
 $code=location_text($input['phone_code']??'+254','Country calling code',4);if($code==='')$code='+254';
 if(!preg_match('/^\\+[1-9][0-9]{0,2}$/D',$code))throw new InvalidArgumentException('Use a country calling code such as +254.');
 $raw=location_text($input['phone']??'','Phone number',60);$phone=preg_replace('/[ ().-]/','',$raw);
 if($phone!==''&&!str_starts_with($phone,'+')){if($code==='+254'&&str_starts_with($phone,'0'))$phone=substr($phone,1);$phone=$code.$phone;}
 if($phone!==''&&!preg_match('/^\\+[1-9][0-9]{6,14}$/D',$phone))throw new InvalidArgumentException('Enter a valid phone number, or the full international number starting with +.');
 return ['phone_code'=>$code,'phone'=>$phone];
}
function location_record_save(string $kind,int $id,array $record): void {
 if(!in_array($kind,['founder','enquiry'],true)||$id<1)throw new InvalidArgumentException('Invalid location record.');
 set_setting('private.location.'.$kind.'.'.$id,json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),true);
}
function location_record(string $kind,int $id): array {
 if(!in_array($kind,['founder','enquiry'],true)||$id<1)return [];
 $q=db()->prepare('SELECT setting_value,is_secret FROM settings WHERE setting_key=?');$q->execute(['private.location.'.$kind.'.'.$id]);$row=$q->fetch();
 if(!$row||!(int)$row['is_secret'])return [];
 return json_decode(decrypt_secret((string)$row['setting_value']),true,16,JSON_THROW_ON_ERROR);
}
function location_label(array $record): string {
 return implode(', ',array_filter([$record['city']??'',($record['sub_county']??'')?:($record['district']??''),($record['county']??'')?:($record['region']??''),$record['country']??''],static fn($v)=>$v!==''));
}
