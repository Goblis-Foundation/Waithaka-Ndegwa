<?php
declare(strict_types=1);
function article_groups_migration_verified(PDO $pdo): bool {
    $q=$pdo->prepare('SELECT sha256 FROM schema_migrations WHERE name=?');$q->execute(['210_article_groups.sql']);$hash=$q->fetchColumn();
    $column=$pdo->query("SHOW COLUMNS FROM posts LIKE 'article_groups_json'")->fetch();
    return is_string($hash)&&hash_equals(hash_file('sha256',__DIR__.'/migrations/210_article_groups.sql'),$hash)&&is_array($column)&&in_array(strtolower($column['Type']),['json','longtext'],true)&&$column['Null']==='YES';
}
function article_groups_apply_migration(PDO $pdo): void {
    $file=__DIR__.'/migrations/210_article_groups.sql';$sql=wk_sql_statements((string)file_get_contents($file));$hash=hash_file('sha256',$file);
    if($sql!==['ALTER TABLE posts ADD COLUMN article_groups_json JSON NULL'])throw new RuntimeException('Unexpected article grouping migration.');
    $key='wk-article-groups-'.substr(hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),0,32);
    $q=$pdo->prepare('SELECT GET_LOCK(?,30)');$q->execute([$key]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Another article grouping upgrade is running.');
    try{
        $q=$pdo->prepare('SELECT sha256 FROM schema_migrations WHERE name=?');$q->execute(['210_article_groups.sql']);$prior=$q->fetchColumn();
        if($prior!==false&&!hash_equals((string)$prior,$hash))throw new RuntimeException('Applied article grouping migration differs from this release.');
        if(!$pdo->query("SHOW COLUMNS FROM posts LIKE 'article_groups_json'")->fetch())$pdo->exec($sql[0]);
        if($prior===false)$pdo->prepare('INSERT INTO schema_migrations(name,sha256) VALUES(?,?)')->execute(['210_article_groups.sql',$hash]);
        if(!article_groups_migration_verified($pdo))throw new RuntimeException('Article grouping schema is incompatible.');
    }finally{$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$key]);}
}

/** Private, expiring deployment metadata; no credentials are accepted or stored here. */
function article_groups_authority(array $manifest,string $host,string $database,string $hash,?int $now=null): string {
    $now??=time();$keys=['schema_version','migration','sha256','state','account_id','region','admin_secret_arn','db_host','db_name','release','expires_at'];
    if(array_diff(array_keys($manifest),$keys)||array_diff($keys,array_keys($manifest))
        ||($manifest['schema_version']??null)!==1||($manifest['migration']??null)!=='210_article_groups.sql'||($manifest['state']??null)!=='open'
        ||($manifest['sha256']??null)!==$hash||($manifest['account_id']??null)!=='466401734428'||($manifest['region']??null)!=='af-south-1'
        ||($manifest['db_host']??null)!==$host||($manifest['db_name']??null)!==$database
        ||!is_int($manifest['expires_at']??null)||$manifest['expires_at']<=$now||$manifest['expires_at']>$now+3660
        ||!is_string($manifest['release']??null)||!preg_match('/^r17-[a-f0-9]{12}$/D',$manifest['release'])
        ||!is_string($manifest['admin_secret_arn']??null)||!preg_match('~^arn:aws:secretsmanager:af-south-1:466401734428:secret:[A-Za-z0-9/!_+=.@-]+$~D',$manifest['admin_secret_arn']))throw new RuntimeException('The article migration authority does not match this database and release.');
    return $manifest['admin_secret_arn'];
}
