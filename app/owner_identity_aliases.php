<?php
declare(strict_types=1);
/** Exact owner-confirmed relationship; aliases are not separate grants or mailboxes. */
function owner_identity_aliases(): array {return ['isaac@afritracelink.com'=>'isaac@goblisfoundation.org'];}
function owner_identity_email(string $email): string {$email=strtolower(trim($email));return owner_identity_aliases()[$email]??$email;}
function owner_identity_is_alias(string $email): bool {return isset(owner_identity_aliases()[strtolower(trim($email))]);}
function owner_identity_label_is(string $label,string $email): bool {return preg_match('/(?:^|[^A-Za-z0-9._%+\-])'.preg_quote($email,'/').'\s*>?$/i',trim($label))===1;}
function owner_identity_require_primary(string $email): void {if(owner_identity_is_alias($email))throw new RuntimeException('isaac@afritracelink.com is an alias of isaac@goblisfoundation.org. Connect the Foundation account once; do not add the alias as a separate account.');}

/** Runtime-data-only, local, idempotent reconciliation. No OAuth revocation or provider call. */
function owner_identity_reconcile(?int $owner=null): array {
 $owner??=(int)(comm_config()['owner']??0);if($owner<1)throw new RuntimeException('Configure the workspace owner before reconciling owner aliases.');
 return comm_lock('owner-afritrace-alias',static function()use($owner):array{
  $alias='isaac@afritracelink.com';$canonical='isaac@goblisfoundation.org';$result=['owner'=>$owner,'retired_calendars'=>0,'retired_mailboxes'=>0,'retired_services'=>0,'retired_logins'=>0,'booking_review'=>false];
  db()->beginTransaction();try{
   $u=db()->prepare('SELECT id,email,is_active FROM users WHERE LOWER(email)=?');$u->execute([$alias]);$oldUsers=$u->fetchAll();$owners=[$owner];foreach($oldUsers as$user)$owners[]=(int)$user['id'];$owners=array_values(array_unique($owners));$marks=implode(',',array_fill(0,count($owners),'?'));
   $q=db()->prepare('SELECT id,user_id,display_name,status,selected_json FROM google_calendar_accounts WHERE user_id IN ('.$marks.') ORDER BY id FOR UPDATE');$q->execute($owners);$all=$q->fetchAll();$old=[];$canonicalRows=[];
   foreach($all as$a){if(owner_identity_label_is($a['display_name'],$alias))$old[]=$a;elseif((int)$a['user_id']===$owner&&$a['status']==='connected'&&owner_identity_label_is($a['display_name'],$canonical))$canonicalRows[]=$a;}
   $retiredIds=array_map(static fn($a)=>(int)$a['id'],$old);$requiredRetired=[];$cfg=comm_config();
   foreach($old as$a){$uid=(int)$a['user_id'];$id=(int)$a['id'];if($a['status']!=='disconnected'){
     comm_put('identity.alias.calendar.'.$id,['owner'=>$uid,'account'=>$id,'old_label'=>$a['display_name'],'canonical'=>$canonical,'selected'=>publishing_decode($a['selected_json']),'retired_at'=>time()]);
     db()->prepare("UPDATE google_calendar_accounts SET status='disconnected',credentials_encrypted=NULL,selected_json='[]',calendars_json='[]' WHERE id=? AND user_id=?")->execute([$id,$uid]);$result['retired_calendars']++;
    }
    foreach(publishing_decode($a['selected_json'])as$calendar){$key='google.calendar.cache.'.hash('sha256',$id.'|'.$calendar);$cache=comm_get($key);$channel=$cache['channel']['id']??'';if(is_string($channel)&&preg_match('/^[a-f0-9]{32}$/D',$channel))comm_put('google.calendar.channel.'.$channel,['id'=>$channel,'status'=>'stopped','expires'=>time()]);foreach([$key,$key.'.notice']as$k)db()->prepare('DELETE FROM settings WHERE setting_key=?')->execute(['communication.'.$k]);}
    if((int)$cfg['owner']===$uid&&in_array($id,array_map('intval',$cfg['required_accounts']??[]),true))$requiredRetired[]=$a;
   }
   if($requiredRetired){$replacement=[];$uncovered=false;foreach($requiredRetired as$oldAccount){$covered=false;$wanted=publishing_decode($oldAccount['selected_json']);foreach($canonicalRows as$c)if($wanted&&!array_diff($wanted,publishing_decode($c['selected_json']))){$replacement[]=(int)$c['id'];$covered=true;break;}if(!$covered)$uncovered=true;}
    $cfg['required_accounts']=array_values(array_unique(array_merge(array_diff(array_map('intval',$cfg['required_accounts']),$retiredIds),$replacement)));
    if($uncovered){$cfg['enabled']=false;$cfg['identity_review']='The retired AfriTrace alias was a required conflict account. Review the Foundation account and its returned calendars before enabling new booking requests.';$result['booking_review']=true;}
    comm_put('config',$cfg);
   }
   foreach($owners as$uid){
    foreach(comm_records('booking-mailbox.'.$uid,30)as$m)if(owner_identity_is_alias((string)($m['email']??''))&&($m['status']??'')!=='disconnected'){$m['status']='disconnected';$m['credentials']=[];$m['canonical_email']=$canonical;$m['retired_alias']=true;$m['updated']=time();$m['revision']=($m['revision']??0)+1;comm_put('identity.alias.mailbox.'.$uid.'.'.$m['id'],$m);db()->prepare('DELETE FROM settings WHERE setting_key=?')->execute(['communication.booking-mailbox.'.$uid.'.'.$m['id']]);$result['retired_mailboxes']++;}
    $snapshot=comm_get('planner.calendar.'.$uid);if($snapshot){$events=$snapshot['events']??[];$snapshot['events']=array_values(array_filter($events,static fn($e)=>!in_array((int)($e['account_id']??0),$retiredIds,true)));$snapshot['errors']=array_values(array_filter($snapshot['errors']??[],static fn($e)=>!is_string($e)||stripos($e,$alias)===false));if($snapshot['events']!==$events)comm_put('planner.calendar.'.$uid,$snapshot);}
   }
   $p=db()->prepare("SELECT id,display_name,metadata_json,status FROM publishing_connections WHERE provider='google' AND connected_by IN (".$marks.")");$p->execute($owners);foreach($p->fetchAll()as$service){$meta=publishing_decode($service['metadata_json']);if($service['status']!=='disconnected'&&(owner_identity_is_alias((string)($meta['email']??''))||owner_identity_label_is((string)$service['display_name'],$alias))){comm_put('identity.alias.service.'.(int)$service['id'],['owner'=>$owner,'record'=>$service,'canonical'=>$canonical,'retired_at'=>time()]);db()->prepare("UPDATE publishing_connections SET status='disconnected',credentials_encrypted=NULL,selected_resource=NULL,resources_json='[]',scopes_json='[]',external_id=NULL,display_name=NULL,metadata_json=? WHERE id=?")->execute([publishing_json(['retired_alias'=>$alias,'canonical_email'=>$canonical]),$service['id']]);$result['retired_services']++;}}
   foreach($oldUsers as$user){$id=(int)$user['id'];if($id!==$owner&&(int)$user['is_active']){db()->prepare('UPDATE users SET is_active=0 WHERE id=?')->execute([$id]);$result['retired_logins']++;}elseif($id===$owner){$c=db()->prepare('SELECT id FROM users WHERE LOWER(email)=? AND id<>?');$c->execute([$canonical,$owner]);if(!$c->fetchColumn())db()->prepare('UPDATE users SET email=? WHERE id=?')->execute([$canonical,$owner]);}}
   foreach(comm_records('auth.identity',2000)as$binding)if(in_array((int)($binding['uid']??0),$owners,true)&&owner_identity_is_alias((string)($binding['email']??''))&&isset($binding['provider'],$binding['issuer'],$binding['subject'])){$key=hash('sha256',publishing_json([$binding['provider'],$binding['issuer'],$binding['subject']]));comm_put('identity.alias.auth.'.$key,$binding+['retired_at'=>time(),'canonical_email'=>$canonical]);comm_put('auth.identity.'.$key,[]);}
   // Assert the operative postcondition while preserving historical rows and canonical grants.
   $v=db()->prepare("SELECT display_name FROM google_calendar_accounts WHERE user_id IN (".$marks.") AND status<>'disconnected'");$v->execute($owners);foreach($v->fetchAll()as$a)if(owner_identity_label_is($a['display_name'],$alias))throw new RuntimeException('Alias calendar retirement did not complete.');
   foreach($owners as$uid)foreach(comm_records('booking-mailbox.'.$uid,30)as$m)if(owner_identity_is_alias((string)($m['email']??''))&&(($m['status']??'')!=='disconnected'||!empty($m['credentials'])))throw new RuntimeException('Alias mailbox retirement did not complete.');$result['active_alias_calendars']=0;$result['active_alias_mailboxes']=0;comm_put('identity.alias.last-reconciliation',$result+['at'=>time(),'canonical'=>$canonical,'alias'=>$alias]);db()->commit();return $result;
  }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
 });
}
