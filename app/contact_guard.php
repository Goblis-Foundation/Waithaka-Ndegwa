<?php
declare(strict_types=1);
function contact_form_issue(): string {$id=bin2hex(random_bytes(16));$_SESSION['contact_issued'][$id]=time();$_SESSION['contact_issued']=array_slice($_SESSION['contact_issued']??[],-30,null,true);return $id;}
function contact_form_consume(string $id): bool {$at=$_SESSION['contact_issued'][$id]??0;unset($_SESSION['contact_issued'][$id]);return $id!==''&&is_int($at)&&$at>time()-8*3600;}
function contact_guard_settings(array $input=[]): array {
 $defaults=['sender_hour'=>3,'sender_day'=>10,'duplicate_hours'=>24,'links'=>5];$out=[];foreach($defaults as$k=>$d){$v=filter_var($input[$k]??$d,FILTER_VALIDATE_INT);$max=$k==='duplicate_hours'?168:($k==='links'?30:100);if($v===false||$v<1||$v>$max)throw new InvalidArgumentException('Choose valid contact control limits.');$out[$k]=$v;}return $out;
}
function contact_guard_config(): array {return comm_get('contact.guard.config',contact_guard_settings());}
function contact_guard_digest(array $m): string {return hash('sha256',strtolower(trim($m['email'])).'|'.preg_replace('/\s+/u',' ',trim($m['message'])));}
function contact_guard_reasons(array $input,bool $token,bool $captcha,int $ipCount,int $hour,int $day,bool $duplicate,array $cfg): array {
 $r=[];if(!empty($input['website']))$r[]='Hidden field was filled';if(!$token)$r[]='Form token missing, expired or already used';if(!$captcha)$r[]='Human check incomplete';if($ipCount>=5)$r[]='IP limit: more than five messages per minute';if($hour>=$cfg['sender_hour'])$r[]='Sender hourly limit';if($day>=$cfg['sender_day'])$r[]='Sender daily limit';if($duplicate)$r[]='Duplicate message';$body=is_string($input['message']??null)?$input['message']:'';if(preg_match_all('~https?://|www\.~iu',$body)>=$cfg['links'])$r[]='High number of links';return $r;
}
function contact_guard_key(int $id): string {return 'contact.review.'.str_pad((string)$id,20,'0',STR_PAD_LEFT);}
function contact_guard_meta(int $id): array {return comm_get(contact_guard_key($id));}
function contact_guard_notify(int $id): void {$r=reader_enquiry($id);if(!$r)throw new RuntimeException('Enquiry unavailable.');$values=reader_contact_values($r,['enquiry_type'=>$r['enquiry_type']]);reader_contact_notifications($id,$values);contact_delivery_register((int)$r['contact_id'],$r['reference']);}
function contact_guard_receive(array $m,array $input,bool $token,bool $captcha): int {
 // Historical categories remain readable; retired purposes cannot enter new submissions.
 $type=$input['enquiry_type']??'general';if(!is_string($type)||!isset(contact_guides()[$type]))throw new InvalidArgumentException('Choose a current enquiry purpose.');
 $values=reader_contact_values($m,$input);$hash=ip_hash();$sender=strtolower($values['email']);$cfg=contact_guard_config();
 return comm_lock('contact.ip.'.$hash,function()use($values,$input,$token,$captcha,$hash,$sender,$cfg){return comm_lock('contact.sender.'.hash('sha256',$sender),function()use($values,$input,$token,$captcha,$hash,$sender,$cfg){
 $q=db()->prepare('SELECT COUNT(*) FROM contact_messages WHERE ip_hash=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 MINUTE)');$q->execute([$hash]);$ip=(int)$q->fetchColumn();
 $q=db()->prepare('SELECT SUM(created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)) hour_count,COUNT(*) day_count FROM contact_messages WHERE LOWER(email)=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY)');$q->execute([$sender]);$counts=$q->fetch();
 $digest=contact_guard_digest($values);$q=db()->prepare('SELECT id,email,message FROM contact_messages WHERE LOWER(email)=? AND created_at>=DATE_SUB(NOW(),INTERVAL '.$cfg['duplicate_hours'].' HOUR) ORDER BY id DESC LIMIT 1000');$q->execute([$sender]);$duplicate=false;foreach($q->fetchAll()as$prior)if(hash_equals($digest,contact_guard_digest($prior))){$duplicate=true;break;}
 $reasons=contact_guard_reasons($input,$token,$captcha,$ip,(int)$counts['hour_count'],(int)$counts['day_count'],$duplicate,$cfg);$id=reader_contact_submit($values,$input,false);
 $meta=['id'=>$id,'state'=>$reasons?'held':'approved','reasons'=>$reasons,'version'=>1,'created'=>time(),'digest'=>$digest];comm_put(contact_guard_key($id),$meta);
 if($reasons)db()->prepare("UPDATE reader_enquiries SET notification_state='held' WHERE id=?")->execute([$id]);
 else {try{contact_guard_notify($id);$meta['state']='released';comm_put(contact_guard_key($id),$meta);}catch(Throwable){error_log('Contact notification registration requires review.');}}
 return $id;});});
}
function contact_guard_review(int $uid,array $in): void {
 $id=reader_id($in['id']??null);comm_lock('contact.review.'.$id,function()use($uid,$in,$id){$meta=contact_guard_meta($id);if(!$meta||$meta['state']!=='held'||$meta['version']!==(int)($in['version']??0))throw new RuntimeException('This enquiry was already reviewed or changed. Reload before continuing.');
 $action=$in['action']??'';if(!in_array($action,['release','keep'],true))throw new InvalidArgumentException('Choose a review action.');$note=reader_text($in['review_note']??'','Review note',1000,true);if($action==='release'&&($in['review_consent']??'')!=='1')throw new InvalidArgumentException('Confirm release of this enquiry’s service notifications.');
 $meta['state']=$action==='release'?'approved':'retained';$meta['reviewed_by']=$uid;$meta['reviewed_at']=time();$meta['review_note']=$note;$meta['version']++;comm_put(contact_guard_key($id),$meta);db()->prepare('INSERT INTO reader_enquiry_events(enquiry_id,event_type,note,actor_id,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP())')->execute([$id,'workflow_updated','Contact screening: '.$meta['state'].'. '.$note,$uid]);
 if($action==='release'){contact_guard_notify($id);$meta['state']='released';comm_put(contact_guard_key($id),$meta);}
 });
}
function contact_guard_dispatch(string $path): bool {
 if($path!=='/write/contact-controls')return false;require_auth();private_response_headers();journeys_method(['GET','HEAD','POST']);$uid=(int)current_user()['id'];$cfg=comm_config();if(!empty($cfg['owner'])&&(int)$cfg['owner']!==$uid){http_response_code(403);return true;}$error='';
 if(is_post()){verify_csrf();try{if(($_POST['action']??'')==='settings'){comm_put('contact.guard.config',contact_guard_settings($_POST));flash('success','Contact controls saved. IP limit remains five per minute.');}else{contact_guard_review($uid,$_POST);flash('success','Enquiry review saved.');}redirect(url($path));}catch(Throwable $e){$error=$e instanceof InvalidArgumentException||get_class($e)===RuntimeException::class?$e->getMessage():'Review could not be completed. The enquiry remains saved.';}}
 $q=db()->query("SELECT setting_value FROM settings WHERE setting_key LIKE 'communication.contact.review.%' ORDER BY setting_key DESC LIMIT 200");$reviews=[];foreach($q->fetchAll()as$row){$meta=json_decode(decrypt_secret($row['setting_value']),true,32,JSON_THROW_ON_ERROR);if($meta['state']==='held'){$r=reader_enquiry($meta['id']);if($r)$reviews[]=['meta'=>$meta,'enquiry'=>$r];}}
 render_write('contact_controls',['writeTitle'=>'Contact controls & review','guard'=>contact_guard_config(),'reviews'=>$reviews,'error'=>$error]);return true;
}
