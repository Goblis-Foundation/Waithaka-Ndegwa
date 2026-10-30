<?php
declare(strict_types=1);

/** Deployment-only data registration using existing runtime DML permissions. */
function community_catalogue_hash(): string {
    $hash='6090a3c59926570a324366a6d36e268d28a1e1da3115a4e9015d60fe8a3737c4';
    if(!hash_equals($hash,(string)hash_file('sha256',__DIR__.'/migrations/200_community_channels.sql')))throw new RuntimeException('Community catalogue migration does not match the reviewed data registration.');
    return $hash;
}
function community_catalogue_rows(): array {
    return [
        ['threads','Threads','https://www.threads.net/','profile'],
        ['telegram','Telegram','https://telegram.org/','channel_or_group'],
        ['discord','Discord','https://discord.com/channels/@me','server_channel'],
        ['signal','Signal','https://signal.org/','private_group'],
    ];
}
function community_catalogue_missing(PDO $pdo): array {
    $slugs=array_column(community_catalogue_rows(),0);
    $st=$pdo->prepare('SELECT slug FROM publishing_channels WHERE slug IN (?,?,?,?)');$st->execute($slugs);
    return array_values(array_diff($slugs,$st->fetchAll(PDO::FETCH_COLUMN)));
}
function community_catalogue_apply(PDO $pdo): void {
    $hash=community_catalogue_hash();
    if($pdo->inTransaction())throw new RuntimeException('Community catalogue needs its own transaction.');
    $lock='wk-migrate-'.substr(hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),0,40);
    $q=$pdo->prepare('SELECT GET_LOCK(?,5)');$q->execute([$lock]);
    if((int)$q->fetchColumn()!==1)throw new RuntimeException('Community catalogue is being registered by another deployment.');
    try {
        $pdo->beginTransaction();
        $q=$pdo->prepare('SELECT sha256 FROM schema_migrations WHERE name=?');$q->execute(['200_community_channels.sql']);$saved=$q->fetchColumn();
        if($saved!==false&&!hash_equals((string)$saved,$hash))throw new RuntimeException('Previously applied community migration differs from this release.');
        // An existing owner name, destination, account type or delivery route is never replaced.
        $insert=$pdo->prepare("INSERT IGNORE INTO publishing_channels(slug,name,profile_url,account_type,delivery_method) VALUES(?,?,?,?,'manual')");
        foreach(community_catalogue_rows()as$row)$insert->execute($row);
        if(community_catalogue_missing($pdo))throw new RuntimeException('Community catalogue registration is incomplete.');
        if($saved===false)$pdo->prepare('INSERT INTO schema_migrations(name,sha256) VALUES(?,?)')->execute(['200_community_channels.sql',$hash]);
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    finally{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
}
