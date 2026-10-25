<?php
declare(strict_types=1);
function message_trash_apply_migration(PDO $pdo,string $file): void {
 $hash=hash_file('sha256',$file);$name='190_message_trash.sql';$sql=wk_sql_statements((string)file_get_contents($file));
 if(count($sql)!==1||!preg_match('/^CREATE TABLE IF NOT EXISTS reader_enquiry_trash\s*\(/i',$sql[0]))throw new RuntimeException('Unexpected message Trash migration.');
 $key='wk-message-trash-'.substr(hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),0,32);$q=$pdo->prepare('SELECT GET_LOCK(?,30)');$q->execute([$key]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Another message Trash upgrade is running.');
 try{
  $q=$pdo->prepare('SELECT sha256 FROM schema_migrations WHERE name=?');$q->execute([$name]);$prior=$q->fetchColumn();if($prior!==false&&!hash_equals((string)$prior,$hash))throw new RuntimeException('Applied message Trash migration differs from this release.');
  $pdo->exec($sql[0]);$cols=array_column($pdo->query('SHOW COLUMNS FROM reader_enquiry_trash')->fetchAll(),'Field');if(array_diff(['enquiry_id','deleted_by','deleted_at'],$cols))throw new RuntimeException('Message Trash schema is incomplete.');
  $indexes=$pdo->query("SHOW INDEX FROM reader_enquiry_trash WHERE Key_name='PRIMARY'")->fetchAll();if(array_column($indexes,'Column_name')!==['enquiry_id'])throw new RuntimeException('Message Trash key is incompatible.');
  if($prior===false)$pdo->prepare('INSERT INTO schema_migrations(name,sha256) VALUES(?,?)')->execute([$name,$hash]);
 }finally{$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$key]);}
}
