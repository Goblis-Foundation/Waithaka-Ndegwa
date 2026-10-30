<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require dirname(__DIR__).'/app/bootstrap.php';require_once dirname(__DIR__).'/app/owner_identity_aliases.php';
if(!in_array('--apply',$argv,true)){echo "Plan: retire only the owner-confirmed AfriTrace standalone identity locally; preserve canonical grants, bookings, provider records and history. No provider revocation or message.\n";exit;}
try{if((int)(comm_config()['owner']??0)<1){$q=db()->prepare('SELECT COUNT(*) FROM users WHERE LOWER(email)=?');$q->execute(['isaac@afritracelink.com']);if((int)$q->fetchColumn()===0){foreach(db()->query("SELECT display_name FROM google_calendar_accounts WHERE status<>'disconnected'")->fetchAll()as$a)if(owner_identity_label_is($a['display_name'],'isaac@afritracelink.com'))throw new RuntimeException('Workspace owner is required before removing a saved alias grant.');echo "OWNER_ALIAS_RECONCILE no configured owner or standalone login on this empty setup; no change.\n";exit;}}$r=owner_identity_reconcile();echo 'OWNER_ALIAS_RECONCILE '.json_encode($r,JSON_THROW_ON_ERROR)."\n";}catch(Throwable $e){fwrite(STDERR,'Owner identity reconciliation failed safely: '.get_class($e)."\n");exit(1);}
