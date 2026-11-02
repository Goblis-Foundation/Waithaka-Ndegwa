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
