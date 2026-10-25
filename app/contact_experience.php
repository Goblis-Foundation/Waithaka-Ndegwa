<?php
declare(strict_types=1);
function contact_guides(): array {return [
 'collaboration'=>['title'=>'Build something together','summary'=>'Partnerships, enterprise and practical collaboration.','prompt'=>'What are you working on, where do you see a fit, and what would a useful next step look like?','include'=>['The problem or opportunity','Your organisation or role','The collaboration you have in mind']],
 'speaking'=>['title'=>'Invite me to speak','summary'=>'Events, workshops and conversations with an audience.','prompt'=>'Tell me about your event, audience, date, location or format, and the role you have in mind.','include'=>['Event date and location / online format','Audience and theme','Expected role and practical arrangements']],
 'media'=>['title'=>'Arrange an interview','summary'=>'Media questions, interviews and informed commentary.','prompt'=>'Which outlet are you writing for, what is the topic, and when do you need a response?','include'=>['Publication or outlet','Topic and interview format','Deadline, including timezone']],
 'reader_question'=>['title'=>'Continue a thought','summary'=>'Questions and reflections on something I have shared.','prompt'=>'Which article or idea are you responding to? Include its link and your question or perspective.','include'=>['Article title or link','The idea you want to discuss','Your question or experience']],
 'correction'=>['title'=>'Suggest a correction','summary'=>'Sources, factual corrections and useful evidence.','prompt'=>'Include the article link, the specific statement to review, and a reliable source or explanation.','include'=>['Article link and exact passage','What needs checking','Your source or evidence']],
 'general'=>['title'=>'Start a conversation','summary'=>'A thoughtful question or another reason to get in touch.','prompt'=>'What would you like me to know, consider or respond to?','include'=>['A little context','Your main question','What a useful response would help with']],
];}
function contact_draft_values(array $in): array {
 $out=[];foreach(['first_name'=>120,'last_name'=>120,'email'=>190,'phone_code'=>12,'phone'=>60,'country'=>120,'city'=>120,'message'=>10000]as$key=>$max){$v=$in[$key]??'';$out[$key]=is_string($v)&&mb_check_encoding($v,'UTF-8')?mb_substr(trim($v),0,$max):'';}
 $type=$in['enquiry_type']??'general';$out['enquiry_type']=is_string($type)&&isset(contact_guides()[$type])?$type:'general';return $out;
}
function contact_form_nonce(mixed $value): string {return is_string($value)&&preg_match('/^[a-f0-9]{32}$/D',$value)?$value:'';}
function contact_delivery_ids(int $contactId,string $reference): array {
 $q=db()->prepare('SELECT reference FROM contact_messages WHERE id=?');$q->execute([$contactId]);if($q->fetchColumn()!==$reference)return [];
 $q=db()->prepare('SELECT id FROM email_outbox WHERE dedupe_key IN (?,?) ORDER BY id');$q->execute([hash('sha256','contact:'.$reference),hash('sha256','contact-receipt:'.$reference)]);return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
}
function contact_delivery_register(int $contactId,string $reference): void {if(!contact_delivery_ids($contactId,$reference))return;comm_put('contact.delivery.'.$contactId,['id'=>$contactId,'reference'=>$reference,'created'=>time()]);}
/** Future successful contact submissions only. Does not release the historical outbox. */
function contact_delivery_work(?callable $testTransport=null): void {
 if(!$testTransport&&!email_transport_ready())return;
 comm_lock('contact.delivery.worker',function()use($testTransport){$q=db()->query("SELECT setting_key,setting_value FROM settings WHERE setting_key LIKE 'communication.contact.delivery.%' ORDER BY setting_key LIMIT 10");foreach($q->fetchAll()as$row){$job=json_decode(decrypt_secret($row['setting_value']),true,8,JSON_THROW_ON_ERROR);$ids=contact_delivery_ids((int)$job['id'],(string)$job['reference']);if($ids)email_work(2,$testTransport,$ids);
  $pending=0;if($ids){$p=db()->prepare("SELECT COUNT(*) FROM email_outbox WHERE id IN (".implode(',',array_fill(0,count($ids),'?')).") AND status IN ('pending','sending')");$p->execute($ids);$pending=(int)$p->fetchColumn();}if(!$pending)db()->prepare('DELETE FROM settings WHERE setting_key=?')->execute([$row['setting_key']]);
 }});
}
