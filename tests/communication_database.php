<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing')exit(2);
require __DIR__.'/prepare.php';$settings=wk_test_database_settings();$database=(string)(getenv('DB_DATABASE')?:getenv('WK_TEST_DB_NAME'));wk_test_assert_database_name($database);
$CONFIG=['app_env'=>'testing','base_url'=>'https://www.waithakandegwa.com','app_key'=>'local-test-key-not-for-production-use','db'=>$settings+['name'=>$database]];
$APP_INSTALLED=true;
require dirname(__DIR__).'/app/functions.php';require dirname(__DIR__).'/app/communication.php';require dirname(__DIR__).'/app/communication_notifications.php';
function cd(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$b=['id'=>str_repeat('a',32),'nonce'=>str_repeat('b',48),'email'=>'fixture@example.invalid','first'=>'Synthetic','last'=>'Person','status'=>'requested','end'=>time()+3600,'version'=>1];comm_put('booking.'.$b['id'],$b);
$raw=db()->query("SELECT setting_value FROM settings WHERE setting_key LIKE 'communication.booking.%'")->fetchColumn();cd(!str_contains($raw,'fixture@example.invalid'),'PII encrypted at rest');cd(comm_records('booking')[0]['email']===$b['email'],'Record round trip');
cd(comm_booking_by_cap($b['id'],comm_capability($b))['id']===$b['id'],'Valid private capability');try{comm_booking_by_cap($b['id'],str_repeat('0',64));throw new LogicException('Foreign capability accepted');}catch(InvalidArgumentException){}
comm_lock('fixture',function(){comm_put('signal',['id'=>'fixture']);});cd(comm_get('signal')['id']==='fixture','Locked mutation persists');
$v=comm_vapid();cd(strlen(base64_decode(strtr($v['public'],'-_','+/')))===65,'P-256 public key');cd(comm_vapid()['public']===$v['public'],'Key stable across reads');
$raw=json_encode(['endpoint'=>'https://fcm.googleapis.com/fcm/send/fixture']);comm_save_subscription(1,$raw);cd(count(comm_user_devices(1))===1&&count(comm_user_devices(2))===0,'Device ownership');$d=comm_user_devices(1)[0];try{comm_remove_subscription(2,$d['id']);throw new LogicException('Foreign device removed');}catch(InvalidArgumentException){}comm_remove_subscription(1,$d['id']);cd(comm_user_devices(1)===[],'Owner revocation');
comm_queue_mail($b,'requested');cd(count(comm_records('mail'))===1,'Durable scoped mail');cd(communication_work()===['enabled'=>false],'No delivery without explicit booking-worker opt-in');
cd((int)db()->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn()===0,'Does not drain or fill legacy newsletter/mail queues');
echo "PASS: encrypted records, capability authorization, locks, stable notification identity and no unapproved delivery.\n";
