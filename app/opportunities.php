<?php
declare(strict_types=1);
/** Encrypted opportunity records, exact-version founder consent, separate owner publication. */
final class OpportunityException extends InvalidArgumentException {}
function opportunity_types(): array {return ['FULL_TIME'=>'Full-time employment','PART_TIME'=>'Part-time employment','CONTRACTOR'=>'Paid contract','INTERN'=>'Internship','VOLUNTEER'=>'Volunteering'];}
function opportunity_states(): array {return ['draft'=>'Private draft','submitted'=>'Submitted','held'=>'Held for review','needs_info'=>'More information requested','awaiting_approval'=>'Awaiting founder approval','returned'=>'Founder requested changes','approved'=>'Founder approved','published'=>'Published','expired'=>'Closed · deadline passed','discarded'=>'Closed without approval','withdrawn'=>'Withdrawn'];}
function opportunity_id(mixed $id): string {if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new OpportunityException('Opportunity unavailable.');return $id;}
function opportunity_record(string $id): array {$r=comm_get('opportunity.record.'.opportunity_id($id));if(!$r)throw new OpportunityException('Opportunity unavailable.');return $r;}
function opportunity_owner(int $uid): void {if($uid<1||$uid!==(int)(comm_config()['owner']??0))throw new OpportunityException('Owner access required.');}
function opportunity_state(array $r,?int $now=null): string {
 $now??=time();if($r['state']==='published'&&($r['fields']['closes_at']??0)<=$now)return 'expired';
 if(in_array($r['state'],['awaiting_approval','needs_info'],true)&&($r['link']['expires']??0)<=$now)return $r['state']==='awaiting_approval'?(($r['rounds']??0)>=3?'discarded':'returned'):'submitted';
 return $r['state'];
}
function opportunity_digest(array $f): string {return hash('sha256',reader_json($f));}
function opportunity_fields(array $in,bool $complete=true): array {
 $f=[];foreach(['organisation'=>180,'title'=>180,'purpose'=>3000,'responsibilities'=>8000,'essential'=>5000,'desirable'=>3000,'accessibility'=>2000,'application_process'=>3000,'compensation_reason'=>500,'remote_countries'=>1000]as$key=>$max)$f[$key]=reader_text($in[$key]??'',ucwords(str_replace('_',' ',$key)),$max,in_array($key,$complete?['organisation','title','purpose','responsibilities','essential','application_process']:['organisation','title'],true));
 foreach(['organisation','title']as$key)if(str_contains($f[$key],"\n"))throw new OpportunityException('Use one line for the organisation and role title.');
 $f+=location_fields($in);$f['type']=reader_choice($in['type']??'FULL_TIME',opportunity_types(),'opportunity type');$f['mode']=reader_choice($in['mode']??'on_site',['on_site'=>1,'hybrid'=>1,'remote'=>1],'workplace mode');
 $f['permanence']=reader_choice($in['permanence']??'permanent',['permanent'=>1,'temporary'=>1,'not_applicable'=>1],'employment duration');
 $f['paid']=reader_choice($in['paid']??'paid',['paid'=>1,'unpaid'=>1],'paid or unpaid');if($f['paid']==='unpaid'&&!in_array($f['type'],['INTERN','VOLUNTEER'],true))throw new OpportunityException('Employment and contract opportunities must be paid.');
 $n=filter_var($in['positions']??1,FILTER_VALIDATE_INT);if($n===false||$n<1||$n>10000)throw new OpportunityException('Enter 1–10,000 positions.');$f['positions']=$n;
 $f['currency']=strtoupper(reader_text($in['currency']??'KES','Currency',3,true));if(!preg_match('/^[A-Z]{3}$/D',$f['currency']))throw new OpportunityException('Use a three-letter currency code.');
 $f['salary_period']=reader_choice($in['salary_period']??'MONTH',['HOUR'=>1,'DAY'=>1,'WEEK'=>1,'MONTH'=>1,'YEAR'=>1],'salary period');
 foreach(['salary_min','salary_max']as$key){$f[$key]=reader_text($in[$key]??'','Compensation',15);if($f[$key]!==''&&(!preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/D',$f[$key])||(float)$f[$key]<=0))throw new OpportunityException('Use positive compensation amounts.');}
 if(($f['salary_min']==='')!==($f['salary_max']==='')||($f['salary_min']!==''&&(float)$f['salary_max']<(float)$f['salary_min']))throw new OpportunityException('Enter a complete compensation range, with maximum at least minimum.');
 if($f['paid']==='unpaid'&&$f['salary_min']!=='')throw new OpportunityException('Unpaid opportunities cannot show a salary range.');
 if($complete&&$f['paid']==='paid'&&$f['salary_min']===''&&$f['compensation_reason']==='')throw new OpportunityException('Provide compensation or explain why it is not disclosed.');
 $f['apply_url']=reader_text($in['apply_url']??'','Application destination',1000,$complete);
 if($f['apply_url']!==''){
  $url=$f['apply_url'];$mail=str_starts_with($url,'mailto:')?substr($url,7):'';
  if($mail!==''?!filter_var($mail,FILTER_VALIDATE_EMAIL):(!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https'||parse_url($url,PHP_URL_USER)!==null||parse_url($url,PHP_URL_PASS)!==null))throw new OpportunityException('Use a public HTTPS application page or mailto: address without private tokens.');
 }
 $f['timezone']=reader_text($in['timezone']??'Africa/Nairobi','Timezone',80,true);if(!in_array($f['timezone'],DateTimeZone::listIdentifiers(),true))throw new OpportunityException('Choose an IANA timezone such as Africa/Nairobi.');
 $f['closing_local']=reader_text($in['closing_local']??'','Closing date and time',16,$complete);$f['closes_at']=0;
 if($f['closing_local']!==''){$d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$f['closing_local'],new DateTimeZone($f['timezone']));if(!$d||$d->format('Y-m-d\TH:i')!==$f['closing_local']||$d->getTimestamp()<=time()||$d->getTimestamp()>time()+366*86400)throw new OpportunityException('Choose a real future deadline within the next year.');$f['closes_at']=$d->getTimestamp();}
 if($complete&&($f['city']===''||($f['mode']==='remote'&&$f['remote_countries']==='')))throw new OpportunityException('Specify the workplace town/location and, for remote work, eligible countries.');
 return $f;
}
function opportunity_index(): array {return comm_get('opportunity.index')['items']??[];}
function opportunity_persist(array $r): void {
 comm_put('opportunity.record.'.$r['id'],$r);$index=opportunity_index();$index[$r['id']]=array_intersect_key($r,array_flip(['id','state','version','rounds','created','updated','published_at']));$index[$r['id']]['title']=$r['fields']['title'];$index[$r['id']]['organisation']=$r['fields']['organisation'];$index[$r['id']]['deadline']=$r['fields']['closes_at'];$index[$r['id']]['link_expires']=$r['link']['expires']??0;comm_put('opportunity.index',['items'=>$index]);
}
/** The settings record, summary and queued notification commit together. */
function opportunity_atomic(callable $fn): mixed {return comm_lock('opportunity.catalog',static function()use($fn){$p=db();$p->beginTransaction();try{$v=$fn();$p->commit();return $v;}catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}});}
function opportunity_audit(array &$r,string $action,string $actor,string $note=''): void {$r['version']++;$r['updated']=time();$r['audit']=array_slice([...($r['audit']??[]),['at'=>time(),'actor'=>$actor,'action'=>$action,'version'=>$r['version'],'note'=>$note]],-200);}
function opportunity_link(array &$r,string $purpose,string $intro): void {
 $token=bin2hex(random_bytes(32));$r['link']=['purpose'=>$purpose,'hash'=>hash('sha256',$token),'token'=>$token,'expires'=>time()+14*86400,'version'=>$r['version'],'digest'=>opportunity_digest($r['fields'])];
 $link=url('/opportunities/review?id='.$r['id'].'&token='.$token);$html=email_design($purpose==='approval'?'Review your opportunity':'Your private opportunity link','<p>'.nl2br(e($intro)).'</p>'.email_design_button($purpose==='approval'?'Review this exact listing':'Open private submission',$link).'<p>This link expires in 14 days. Opening it does not approve or publish anything. Keep it private.</p>',['name'=>$r['contact']['name']]);
 $r['notification']=['queued_at'=>time(),'outbox_id'=>enqueue_email($r['contact']['email'],'Opportunity review · Waithaka Ndegwa',$html,'opportunity:'.$r['id'].':'.$r['version'])];
}
function opportunity_submit(array $in,array $hold=[]): string {
 $draft=($in['action']??'submit')==='draft';$f=opportunity_fields($in,!$draft);$contact=['name'=>reader_text($in['contact_name']??'','Your name',120,true),'email'=>journeys_email($in['contact_email']??''),'relationship'=>reader_text($in['relationship']??'','Your authority to post',1000,true)]+location_phone($in);
 if(($in['privacy_ack']??'')!=='1'||($in['no_fee_ack']??'')!=='1')throw new OpportunityException('Confirm privacy permission and that applicants pay no fee.');
 $evidence=reader_text($in['private_evidence']??'','Private evidence',5000);$contact['whatsapp_optin']=($in['whatsapp_optin']??'')==='1';
 return opportunity_atomic(static function()use($f,$contact,$draft,$hold,$evidence){$index=opportunity_index();$dedupe=opportunity_digest([$contact['email'],$f]);$dupes=comm_get('opportunity.duplicates')['items']??[];$dupes=array_filter($dupes,static fn($r)=>$r['at']>time()-86400);if(isset($dupes[$dedupe]))return $dupes[$dedupe]['id'];if(count($index)>=500)throw new OpportunityException('The review inbox is full. Please contact me before submitting.');
  $id=bin2hex(random_bytes(16));$r=['id'=>$id,'created'=>time(),'updated'=>time(),'version'=>0,'rounds'=>0,'state'=>$hold?'held':($draft?'draft':'submitted'),'fields'=>$f,'contact'=>$contact,'private_evidence'=>$evidence,'hold_reasons'=>$hold,'public'=>null,'audit'=>[]];
  opportunity_audit($r,'created','submitter');if(!$hold)opportunity_link($r,'followup','Your private '.($draft?'draft':'submission').' is saved. You can '.($draft?'complete it':'view its status or withdraw it').'. Publication requires editorial review and your approval of the final listing.');opportunity_persist($r);$dupes[$dedupe]=['id'=>$id,'at'=>time()];comm_put('opportunity.duplicates',['items'=>$dupes]);return $id;
 });
}
function opportunity_token(string $id,string $token): ?array {
 if(!preg_match('/^[a-f0-9]{64}$/D',$token))return null;try{$r=opportunity_record($id);}catch(OpportunityException){return null;}$l=$r['link']??[];
 return !empty($l)&&($l['expires']??0)>time()&&($l['version']??0)===$r['version']&&hash_equals($l['hash'],hash('sha256',$token))&&hash_equals($l['digest'],opportunity_digest($r['fields']))&&!in_array(opportunity_state($r),['discarded','withdrawn'],true)?$r:null;
}
function opportunity_respond(string $id,string $token,array $in): void {
 opportunity_atomic(static function()use($id,$token,$in){$r=opportunity_token($id,$token);if(!$r)throw new OpportunityException('This private link expired, changed or was already used. Request a new link from the reviewer.');$decision=$in['decision']??'';$state=opportunity_state($r);$note=reader_text($in['note']??'','Response note',5000);
  if($decision==='withdraw'){$r['state']='withdrawn';$r['public']=null;}
  elseif(($r['link']['purpose']??'')==='approval'&&$state==='awaiting_approval'){
   if($decision==='approve'){if(($in['approval_ack']??'')!=='1')throw new OpportunityException('Confirm this exact listing.');$r['state']='approved';$r['approved_hash']=opportunity_digest($r['fields']);$r['approved_at']=time();}
   elseif($decision==='changes'){$proposal=opportunity_fields($in)+array_intersect_key($r['fields'],array_flip(['enterprise_id','enterprise_name']));$r['previous_fields']=$r['fields'];$r['fields']=$proposal;$r['state']=$r['rounds']>=3?'discarded':'returned';$r['approved_hash']=null;}
   elseif($decision==='decline'){$r['state']='discarded';$r['public']=null;}else throw new OpportunityException('Choose approve, return edits or decline.');
  }elseif(in_array($state,['draft','needs_info'],true)&&$decision==='resubmit'){$r['fields']=opportunity_fields($in);$r['state']='submitted';$r['approved_hash']=null;}
  else throw new OpportunityException('This submission is currently with the reviewer. You can view its status or withdraw it.');
  $r['founder_note']=$note;$r['contact_verified_at']=time();opportunity_audit($r,'founder_'.$decision,'founder',$note);$r['link']=null;if(!in_array($r['state'],['withdrawn','discarded'],true))opportunity_link($r,'followup','Your response was saved. Current status: '.opportunity_states()[$r['state']].'. Nothing is published until the separate publication check.');opportunity_persist($r);
 });
}
function opportunity_admin(int $uid,string $id,array $in): void {
 opportunity_owner($uid);opportunity_atomic(static function()use($uid,$id,$in){$r=opportunity_record($id);if((string)($in['version']??'')!==(string)$r['version'])throw new OpportunityException('This record changed. Reload before continuing.');$state=opportunity_state($r);$r['state']=$state;$action=$in['action']??'';$note=reader_text($in['review_note']??'','Review note',5000);$send=null;
  if($action==='withdraw'){$r['state']='withdrawn';$r['public']=null;}
  elseif(in_array($state,['discarded','withdrawn','published','expired'],true))throw new OpportunityException('This opportunity is closed or public. Withdraw it before starting a fresh submission.');
  elseif($action==='publish'){
   if($state!=='approved'||($in['publish_ack']??'')!=='1'||!hash_equals((string)($r['approved_hash']??''),opportunity_digest($r['fields'])))throw new OpportunityException('Publication requires founder approval of the exact final version and your confirmation.');
   if(($r['fields']['closes_at']??0)<=time())throw new OpportunityException('The deadline passed. A fresh listing and approval are required.');
   $r['state']='published';$r['published_at']=time();$r['public']=$r['fields']+['id'=>$id,'published_at'=>$r['published_at'],'reviewed_at'=>time(),'enterprise_id'=>$r['enterprise_id']??''];
  }elseif($action==='save'||$action==='request_info'||$action==='request_approval'){
   if($state==='awaiting_approval')throw new OpportunityException('Wait for the founder response or deadline; an active review cannot be silently replaced.');
   $r['fields']=opportunity_fields($in,$action==='request_approval');$r['public']=null;$r['approved_hash']=null;$r['private_evidence']=reader_text($in['private_evidence']??'','Private authority/evidence notes',5000);$r['enterprise_id']=reader_text($in['enterprise_id']??'','Enterprise',16);
   $r['fields']['enterprise_id']=$r['enterprise_id'];$r['fields']['enterprise_name']='';
   if($r['enterprise_id']!==''){enterprise_id($r['enterprise_id']);$enterprise=enterprise_owned($uid,$r['enterprise_id']);if(empty($enterprise['public']))throw new OpportunityException('Choose a publicly approved enterprise profile.');$r['fields']['enterprise_name']=$enterprise['public']['name'];}
   $r['questions']=$note;$r['state']='submitted';
   if($action!=='save'){
    if(($in['contact_confirmed']??'')!=='1'||$r['private_evidence']===''||($in['send_ack']??'')!=='1')throw new OpportunityException('Verify the submitter’s identity, authority and contact privately, and confirm sending the link.');
    if($action==='request_info'){if($note==='')throw new OpportunityException('Write specific questions for the founder.');$r['state']='needs_info';$send='clarification';}
    else{if($r['rounds']>=3)throw new OpportunityException('Three founder review rounds have already been used.');$r['rounds']++;$r['state']='awaiting_approval';$send='approval';$r['review_snapshot']=$r['fields'];}
   }
  }else throw new OpportunityException('Choose a valid action.');
  opportunity_audit($r,$action,'owner:'.$uid,$note);$r['link']=null;if($send)opportunity_link($r,$send,$send==='approval'?'Please review the exact final listing. Round '.$r['rounds'].' of 3. Approve, return edits or decline. The listing will close without publication after three unapproved reviews.':'Please answer these questions and resubmit your listing: '.$note);
  elseif($action==='publish')opportunity_link($r,'followup','Your approved opportunity is now published. Use this private link to view its status or withdraw the listing. Applications remain with your organisation.');opportunity_persist($r);
 });
}
function opportunity_public(array $r,?int $now=null): ?array {if(opportunity_state($r,$now)!=='published'||!is_array($r['public']??null)||!hash_equals((string)($r['approved_hash']??''),opportunity_digest($r['fields'])))return null;return $r['public'];}
function opportunity_public_list(?string $enterprise=null): array {$out=[];foreach(opportunity_index()as$i){if($i['state']!=='published'||($i['deadline']??0)<=time())continue;$p=opportunity_public(opportunity_record($i['id']));if($p&&($enterprise===null||($p['enterprise_id']??'')===$enterprise))$out[]=$p;}usort($out,static fn($a,$b)=>$b['published_at']<=>$a['published_at']);return $out;}
function opportunity_sitemap(): array {return array_merge([['loc'=>url('/opportunities')]],array_map(static fn($p)=>['loc'=>url('/opportunities/'.$p['id']),'lastmod'=>date(DATE_ATOM,$p['reviewed_at'])],opportunity_public_list()));}
function opportunity_schema(array $p): ?array {
 if(($p['closes_at']??0)<=time()||$p['paid']!=='paid'||!in_array($p['type'],['FULL_TIME','PART_TIME','CONTRACTOR'],true))return null;
 $description='';foreach(['purpose','responsibilities','essential','desirable','accessibility','application_process']as$key)if($p[$key]!=='')$description.='<h2>'.e(ucwords(str_replace('_',' ',$key))).'</h2><p>'.nl2br(e($p[$key])).'</p>';
 $s=['@context'=>'https://schema.org','@type'=>'JobPosting','title'=>$p['title'],'description'=>$description,'datePosted'=>date(DATE_ATOM,$p['published_at']),'validThrough'=>date(DATE_ATOM,$p['closes_at']),'employmentType'=>$p['type'],'hiringOrganization'=>['@type'=>'Organization','name'=>$p['organisation']],'totalJobOpenings'=>$p['positions'],'url'=>url('/opportunities/'.$p['id'])];
 if($p['mode']==='remote'){$s['jobLocationType']='TELECOMMUTE';$s['applicantLocationRequirements']=array_map(static fn($c)=>['@type'=>'Country','name'=>trim($c)],explode(',',$p['remote_countries']));}
 else $s['jobLocation']=['@type'=>'Place','address'=>['@type'=>'PostalAddress','addressLocality'=>$p['city'],'addressRegion'=>$p['county']?:$p['region'],'addressCountry'=>$p['country']]];
 if($p['salary_min']!=='')$s['baseSalary']=['@type'=>'MonetaryAmount','currency'=>$p['currency'],'value'=>['@type'=>'QuantitativeValue','minValue'=>(float)$p['salary_min'],'maxValue'=>(float)$p['salary_max'],'unitText'=>$p['salary_period']]];return $s;
}
