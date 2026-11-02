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

/** Resolve only this application's owned stack and database; return no other metadata. */
function article_groups_stack_authority(array $response,string $host,string $database): string {
    $expected='waithaka-ndegwa-production-466401734428-data';$stacks=$response['Stacks']??null;
    if(!is_array($stacks)||count($stacks)!==1||!empty($response['NextToken']))throw new RuntimeException('The article migration stack metadata is incomplete.');
    $stack=array_values($stacks)[0];
    if(!is_array($stack)||($stack['StackName']??null)!==$expected||!is_string($stack['StackId']??null)||!preg_match('~^arn:aws:cloudformation:af-south-1:466401734428:stack/'.$expected.'/[A-Za-z0-9-]+$~D',$stack['StackId'])||!is_array($stack['Outputs']??null)||!is_array($stack['Parameters']??null))throw new RuntimeException('The article migration stack identity does not match.');
    $outputs=[];foreach($stack['Outputs']as$row){if(!is_array($row)||!is_string($row['OutputKey']??null)||!is_string($row['OutputValue']??null)||isset($outputs[$row['OutputKey']]))throw new RuntimeException('The article migration stack outputs are ambiguous.');$outputs[$row['OutputKey']]=$row['OutputValue'];}
    $parameters=[];foreach($stack['Parameters']as$row){if(!is_array($row)||!is_string($row['ParameterKey']??null)||!is_string($row['ParameterValue']??null)||isset($parameters[$row['ParameterKey']]))throw new RuntimeException('The article migration stack parameters are ambiguous.');$parameters[$row['ParameterKey']]=$row['ParameterValue'];}
    $arn=$outputs['AdminSecretArn']??'';
    if(($outputs['DatabaseHost']??null)!==$host||($parameters['DatabaseName']??null)!==$database||!preg_match('~^arn:aws:secretsmanager:af-south-1:466401734428:secret:[A-Za-z0-9/!_+=.@-]+$~D',$arn))throw new RuntimeException('The article migration authority does not match this database.');
    return $arn;
}
