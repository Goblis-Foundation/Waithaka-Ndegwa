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
/** Every contact email, including legacy queued acknowledgements, needs an explicit current-owner review. */
function contact_guard_email_approved(array $meta,int $owner): bool {
 return $owner>0&&in_array($meta['state']??'',['approved','released'],true)&&($meta['reviewed_by']??0)===$owner&&($meta['email_consent']??false)===true&&is_int($meta['reviewed_at']??null)&&$meta['reviewed_at']>0&&trim((string)($meta['review_note']??''))!=='';
}
function contact_guard_outbox_allowed(string $key,array $payload): bool {
 $receipt=str_starts_with($key,'contact-receipt:');$prefix=$receipt?'contact-receipt:':'contact:';if(!str_starts_with($key,$prefix)||!is_string($payload['to']??null))return false;$reference=substr($key,strlen($prefix));if(!preg_match('/^[A-Z0-9-]{5,32}$/D',$reference))return false;
 $q=db()->prepare('SELECT r.id,c.email FROM reader_enquiries r JOIN contact_messages c ON c.id=r.contact_id WHERE c.reference=? AND NOT EXISTS(SELECT 1 FROM reader_enquiry_trash t WHERE t.enquiry_id=r.id)');$q->execute([$reference]);$row=$q->fetch();if(!$row)return false;
 $meta=contact_guard_meta((int)$row['id']);if(!contact_guard_email_approved($meta,(int)(comm_config()['owner']??0))){if(!$meta||in_array($meta['state']??'',['approved','released'],true)){$meta=$meta+['id'=>(int)$row['id'],'version'=>0,'created'=>time(),'reasons'=>[]];$meta['state']='held';$meta['reasons'][]='Legacy contact email requires current-owner review';$meta['version']=(int)($meta['version']??0)+1;comm_put(contact_guard_key((int)$row['id']),$meta);}return false;}
 $to=$receipt?(string)$row['email']:trim((string)setting('contact.recipient',''));return $to!==''&&hash_equals(strtolower(trim($to)),strtolower(trim($payload['to'])));
}
function contact_guard_notify(int $id): void {$r=reader_enquiry($id);if(!$r||!empty($r['is_trashed']))throw new RuntimeException('Enquiry unavailable.');if(!contact_guard_email_approved(contact_guard_meta($id),(int)(comm_config()['owner']??0)))throw new RuntimeException('Review this exact enquiry before queuing contact email.');$values=reader_contact_values($r,['enquiry_type'=>$r['enquiry_type']]);reader_contact_notifications($id,$values);contact_delivery_register((int)$r['contact_id'],$r['reference']);}
function contact_guard_receive(array $m,array $input,bool $token,bool $captcha): int {
 // Historical categories remain readable; retired purposes cannot enter new submissions.
 $type=$input['enquiry_type']??'general';if(!is_string($type)||!isset(contact_guides()[$type]))throw new InvalidArgumentException('Choose a current enquiry purpose.');
 $values=reader_contact_values($m,$input);$hash=ip_hash();$sender=strtolower($values['email']);$cfg=contact_guard_config();
 return comm_lock('contact.ip.'.$hash,function()use($values,$input,$token,$captcha,$hash,$sender,$cfg){return comm_lock('contact.sender.'.hash('sha256',$sender),function()use($values,$input,$token,$captcha,$hash,$sender,$cfg){
 $q=db()->prepare('SELECT COUNT(*) FROM contact_messages WHERE ip_hash=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 MINUTE)');$q->execute([$hash]);$ip=(int)$q->fetchColumn();
 $q=db()->prepare('SELECT SUM(created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)) hour_count,COUNT(*) day_count FROM contact_messages WHERE LOWER(email)=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY)');$q->execute([$sender]);$counts=$q->fetch();
 $digest=contact_guard_digest($values);$q=db()->prepare('SELECT id,email,message FROM contact_messages WHERE LOWER(email)=? AND created_at>=DATE_SUB(NOW(),INTERVAL '.$cfg['duplicate_hours'].' HOUR) ORDER BY id DESC LIMIT 1000');$q->execute([$sender]);$duplicate=false;foreach($q->fetchAll()as$prior)if(hash_equals($digest,contact_guard_digest($prior))){$duplicate=true;break;}
 $reasons=contact_guard_reasons($input,$token,$captcha,$ip,(int)$counts['hour_count'],(int)$counts['day_count'],$duplicate,$cfg);if($ip>=5||(int)$counts['hour_count']>=$cfg['sender_hour']||(int)$counts['day_count']>=$cfg['sender_day']||$duplicate)throw new InvalidArgumentException('An enquiry was already received recently. Please keep its reference or try again later.');if(is_int($input['_contact_age']??null)&&$input['_contact_age']<2)$reasons[]='Form completed unusually quickly';$reasons[]='Owner review required before contact email';$id=reader_contact_submit($values,$input,false);
 $meta=['id'=>$id,'state'=>'held','reasons'=>$reasons,'version'=>1,'created'=>time(),'digest'=>$digest];comm_put(contact_guard_key($id),$meta);
 db()->prepare("UPDATE reader_enquiries SET notification_state='held' WHERE id=?")->execute([$id]);
 return $id;});});
}
/** Old pending contact mail can be individually reviewed without releasing an unrelated backlog. */
function contact_guard_review_candidate(int $id): array {
 $row=reader_enquiry($id);if(!$row||!empty($row['is_trashed']))return [];
 $meta=contact_guard_meta($id);if(in_array($meta['state']??'',['held','retained'],true)||contact_guard_email_approved($meta,(int)(comm_config()['owner']??0)))return $meta;
 $ids=contact_delivery_ids((int)$row['contact_id'],$row['reference']);if(!$ids)return $meta;
 $q=db()->prepare("SELECT COUNT(*) FROM email_outbox WHERE id IN (".implode(',',array_fill(0,count($ids),'?')).") AND status='pending'");$q->execute($ids);if(!(int)$q->fetchColumn())return $meta;
 $meta=$meta+['id'=>$id,'version'=>0,'created'=>time(),'reasons'=>[]];$meta['state']='held';$meta['reasons'][]='Pending contact email requires current-owner review';return $meta;
}
function contact_guard_review(int $uid,array $in): void {
 if($uid<1||$uid!==(int)(comm_config()['owner']??0))throw new InvalidArgumentException('Only the workspace owner can review contact email.');
 $id=reader_id($in['id']??null);comm_lock('contact.review.'.$id,function()use($uid,$in,$id){$meta=contact_guard_review_candidate($id);if(!$meta||$meta['state']!=='held'||$meta['version']!==(int)($in['version']??0))throw new RuntimeException('This enquiry was already reviewed or changed. Reload before continuing.');
 $action=$in['action']??'';if(!in_array($action,['release','keep'],true))throw new InvalidArgumentException('Choose a review action.');$note=reader_text($in['review_note']??'','Review note',1000,true);if($action==='release'&&($in['review_consent']??'')!=='1')throw new InvalidArgumentException('Confirm release of this enquiry’s service notifications.');
 $meta['state']=$action==='release'?'approved':'retained';$meta['email_consent']=$action==='release';$meta['reviewed_by']=$uid;$meta['reviewed_at']=time();$meta['review_note']=$note;$meta['version']++;comm_put(contact_guard_key($id),$meta);db()->prepare('INSERT INTO reader_enquiry_events(enquiry_id,event_type,note,actor_id,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP())')->execute([$id,'workflow_updated','Contact screening: '.$meta['state'].'. '.$note,$uid]);
 if($action==='release'){contact_guard_notify($id);$meta['state']='released';comm_put(contact_guard_key($id),$meta);$r=reader_enquiry($id);$ids=contact_delivery_ids((int)$r['contact_id'],$r['reference']);if($ids)db()->prepare("UPDATE email_outbox SET available_at=NOW(),error_code=NULL WHERE id IN (".implode(',',array_fill(0,count($ids),'?')).") AND status='pending' AND error_code='contact_owner_review'")->execute($ids);}
 });
}
function contact_guard_dispatch(string $path): bool {
 if($path!=='/write/contact-controls')return false;require_auth();private_response_headers();journeys_method(['GET','HEAD','POST']);$uid=(int)current_user()['id'];$cfg=comm_config();if((int)($cfg['owner']??0)<1||(int)$cfg['owner']!==$uid){http_response_code(403);return true;}$error='';
 if(is_post()){verify_csrf();try{if(($_POST['action']??'')==='settings'){comm_put('contact.guard.config',contact_guard_settings($_POST));flash('success','Contact controls saved. IP limit remains five per minute.');}else{contact_guard_review($uid,$_POST);flash('success','Enquiry review saved.');}redirect(url($path));}catch(Throwable $e){$error=$e instanceof InvalidArgumentException||get_class($e)===RuntimeException::class?$e->getMessage():'Review could not be completed. The enquiry remains saved.';}}
 $q=db()->query("SELECT setting_value FROM settings WHERE setting_key LIKE 'communication.contact.review.%' ORDER BY setting_key DESC LIMIT 200");$reviews=[];foreach($q->fetchAll()as$row){$meta=json_decode(decrypt_secret($row['setting_value']),true,32,JSON_THROW_ON_ERROR);if($meta['state']==='held'){$r=reader_enquiry($meta['id']);if($r&&empty($r['is_trashed']))$reviews[]=['meta'=>$meta,'enquiry'=>$r];}}
 $seen=array_column(array_column($reviews,'enquiry'),'id');$q=db()->query("SELECT r.id FROM reader_enquiries r JOIN contact_messages c ON c.id=r.contact_id WHERE NOT EXISTS(SELECT 1 FROM reader_enquiry_trash t WHERE t.enquiry_id=r.id) AND EXISTS(SELECT 1 FROM email_outbox e WHERE e.status='pending' AND (e.dedupe_key=SHA2(CONCAT('contact:',c.reference),256) OR e.dedupe_key=SHA2(CONCAT('contact-receipt:',c.reference),256))) ORDER BY r.id DESC LIMIT 200");foreach($q->fetchAll()as$row){$id=(int)$row['id'];if(in_array($id,array_map('intval',$seen),true))continue;$meta=contact_guard_review_candidate($id);if(($meta['state']??'')==='held'){$enquiry=reader_enquiry($id);if($enquiry)$reviews[]=['meta'=>$meta,'enquiry'=>$enquiry];}}
 render_write('contact_controls',['writeTitle'=>'Contact controls & review','guard'=>contact_guard_config(),'reviews'=>$reviews,'error'=>$error]);return true;
}
