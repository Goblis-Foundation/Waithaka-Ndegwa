<?php
declare(strict_types=1);
/** Fixed additive recovery for two release omissions. Never guesses subscriber names. */
function module_audit_migrations(): array {return [
 '150_subscriber_names.sql'=>'1540b71dddaf65af451d2b26472f5b2b90330b1a61ba1fa7aee408c0ff1e5db6',
 '180_culture_topic.sql'=>'8b833a1ae8171df5bffca137ed1f0380178797f1f25789a68312e2ee2b2a35d1',
];}
function module_audit_verified(PDO $pdo): bool {
 $complete=true;
 foreach(module_audit_migrations() as $name=>$hash){
  if(!hash_equals($hash,(string)hash_file('sha256',__DIR__.'/migrations/'.$name)))throw new RuntimeException('Unreviewed module migration file.');
  $q=$pdo->prepare('SELECT sha256 FROM schema_migrations WHERE name=?');$q->execute([$name]);$prior=$q->fetchColumn();
  if($prior===false)$complete=false;elseif(!hash_equals($hash,(string)$prior))throw new RuntimeException('Applied module migration checksum differs.');
 }
 if(!$complete)return false;
 module_audit_verify_columns($pdo);
 if(!(int)$pdo->query("SELECT COUNT(*) FROM categories WHERE slug='culture'")->fetchColumn())throw new RuntimeException('Culture migration has no category.');
 return true;
}
function module_audit_verify_columns(PDO $pdo): void {
 $columns=$pdo->query('SHOW COLUMNS FROM newsletter_subscribers')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
 foreach(['first_name','last_name']as$name){$c=$columns[$name]??[];if(strtolower($c['Type']??'')!=='varchar(120)'||($c['Null']??'')!=='NO'||($c['Default']??null)!=='')throw new RuntimeException('Subscriber name column differs from the reviewed schema.');}
}
function module_audit_apply_migrations(PDO $pdo): void {
 // Verify hashes and existing receipts before any DDL, including partial recovery.
 if(module_audit_verified($pdo))return;
 $key='wk-module-audit-'.substr(hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),0,32);
 $q=$pdo->prepare('SELECT GET_LOCK(?,30)');$q->execute([$key]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Another module migration is running.');
 try{
  if(module_audit_verified($pdo))return;
  $columns=array_column($pdo->query('SHOW COLUMNS FROM newsletter_subscribers')->fetchAll(),'Field');
  foreach(['first_name','last_name']as$name)if(!in_array($name,$columns,true))$pdo->exec("ALTER TABLE newsletter_subscribers ADD COLUMN ".$name." VARCHAR(120) NOT NULL DEFAULT ''");
  module_audit_verify_columns($pdo);
  $pdo->exec((string)file_get_contents(__DIR__.'/migrations/180_culture_topic.sql'));
  if(!(int)$pdo->query("SELECT COUNT(*) FROM categories WHERE slug='culture'")->fetchColumn())throw new RuntimeException('Culture category could not be verified.');
  foreach(module_audit_migrations()as$name=>$hash){$q=$pdo->prepare('SELECT sha256 FROM schema_migrations WHERE name=?');$q->execute([$name]);if($q->fetchColumn()===false)$pdo->prepare('INSERT INTO schema_migrations(name,sha256) VALUES(?,?)')->execute([$name,$hash]);}
  if(!module_audit_verified($pdo))throw new RuntimeException('Module migration verification failed.');
 }finally{$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$key]);}
}
