<?php
declare(strict_types=1);
/** Explicit metadata only; unsupported automatic options stop approval instead of disappearing. */
function social_option_fields(string $slug): array {
 $common=['location_label'=>'Location name / native check-in','mentions'=>'People or accounts to tag','native_notes'=>'Native-app checklist · music, stickers, poll or other details'];
 return match($slug){
 'instagram'=>$common+['location_id'=>'Meta location Page ID','user_tags'=>'Image tags · username,x,y · one per line','collaborators'=>'Collaborator usernames · finish in Instagram'],
 'facebook'=>$common+['location_id'=>'Meta place Page ID','audience'=>'Audience · finish in Facebook'],
 'linkedin'=>$common+['audience'=>'Audience · finish in LinkedIn'],
 'x'=>$common+['location_id'=>'X place ID'],
 'youtube'=>$common+['tags'=>'Video keyword tags · comma separated','language'=>'Video metadata language · e.g. en or sw'],
 'tiktok'=>$common+['collaborators'=>'Creator / branded-partner tags · finish in TikTok'],
 default=>[]};
}
function social_options_input(string $slug,mixed $input): array {
 if($input===null)return [];if(!is_array($input))throw new InvalidArgumentException('Invalid platform options.');$out=[];$allowed=social_option_fields($slug);
 foreach($input as$k=>$v){if(!isset($allowed[$k]))throw new InvalidArgumentException('Unsupported option for '.$slug.'.');if(!is_string($v)||!mb_check_encoding($v,'UTF-8')||mb_strlen($v)>2000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$v))throw new InvalidArgumentException('Invalid '.$allowed[$k].'.');$v=trim($v);if($v==='')continue;
 if($k==='location_id'&&!preg_match($slug==='x'?'/^[a-fA-F0-9]{1,32}$/D':'/^[0-9]{1,40}$/D',$v))throw new InvalidArgumentException('Use the platform’s place ID. A search phrase or coordinates are not a platform location ID.');
 if($k==='language'&&!preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D',$v))throw new InvalidArgumentException('Use a language code such as en, sw or en-KE.');
 if($k==='user_tags')social_instagram_tags($v);
 if($k==='tags'){if(mb_strlen($v)>400||count(explode(',',$v))>30)throw new InvalidArgumentException('Use up to 30 video tags and 400 characters.');}
 $out[$k]=$v;
 }return $out;
}
function social_instagram_tags(string $raw): array {
 $rows=[];foreach(preg_split('/\R/u',$raw)as$line){$p=array_map('trim',explode(',',$line));if(count($p)!==3||!preg_match('/^[A-Za-z0-9._]{1,30}$/D',$p[0])||!is_numeric($p[1])||!is_numeric($p[2])||(float)$p[1]<0||(float)$p[1]>1||(float)$p[2]<0||(float)$p[2]>1)throw new InvalidArgumentException('Use username,x,y for image tags; coordinates must be from 0 to 1.');$rows[]=['username'=>$p[0],'x'=>(float)$p[1],'y'=>(float)$p[2]];if(count($rows)>20)throw new InvalidArgumentException('Use at most 20 image tags.');}return $rows;
}
function social_options_validate(string $slug,array $variant,array $destination): void {
 $options=social_options_input($slug,$variant['platform_options']??[]);$method=$destination['method'];
 if(!$options||$method==='manual')return;
 $implemented=$method==='direct'?match($slug){'instagram'=>['location_id','user_tags'],'facebook'=>['location_id'],'x'=>['location_id'],'youtube'=>['tags','language'],default=>[]}:[]; 
 foreach($options as$k=>$v)if(!in_array($k,$implemented,true))throw new RuntimeException(social_option_fields($slug)[$k].' requires assisted publishing for '.$slug.'. Switch this channel to assisted delivery or remove that option. It will not be silently dropped.');
}
function social_instagram_parameters(array $v): array {
 $body=['image_url'=>$v['media_url'],'caption'=>$v['text'],'alt_text'=>$v['alt_text']];$o=social_options_input('instagram',$v['platform_options']??[]);
 if(!empty($o['location_id']))$body['location_id']=$o['location_id'];if(!empty($o['user_tags']))$body['user_tags']=publishing_json(social_instagram_tags($o['user_tags']));return $body;
}
