<?php
declare(strict_types=1);
/** Fixed additive migration only. Caller supplies the governed TLS migration connection. */
function founder_story_migration_statements(string $file): array {
    $sql=wk_sql_statements((string)file_get_contents($file));
    if(count($sql)!==5)throw new RuntimeException('Unexpected founder migration statement count.');
    if(!preg_match('/^ALTER TABLE posts ADD COLUMN founder_approval_hash CHAR\(64\) NULL$/i',$sql[0])
        ||!preg_match('/^ALTER TABLE founder_submissions ADD INDEX idx_founder_story_post\(associated_post_id,id\)$/i',$sql[1]))throw new RuntimeException('Unexpected founder schema alteration.');
    foreach([2=>'founder_story_workflows',3=>'founder_story_reviews',4=>'founder_story_deliveries']as$i=>$table)
        if(!preg_match('/^CREATE TABLE IF NOT EXISTS '.preg_quote($table,'/').'\s*\(/i',$sql[$i]))throw new RuntimeException('Unexpected founder table statement.');
    return $sql;
}
function founder_story_apply_migration(PDO $pdo,string $file): void {
    $sql=founder_story_migration_statements($file);$hash=hash_file('sha256',$file);
    $lock='wk-founder-schema-'.substr(hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),0,32);
    $q=$pdo->prepare('SELECT GET_LOCK(?,60)');$q->execute([$lock]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Another founder schema migration is running.');
    try{
        $q=$pdo->prepare('SELECT sha256 FROM schema_migrations WHERE name=?');$q->execute(['170_founder_story_approval.sql']);$prior=$q->fetchColumn();
        if($prior!==false){if(!hash_equals((string)$prior,$hash))throw new RuntimeException('The applied founder migration differs from this release.');return;}
        $column=$pdo->query("SHOW COLUMNS FROM posts LIKE 'founder_approval_hash'")->fetch();
        if(!$column)$pdo->exec($sql[0]);
        $column=$pdo->query("SHOW COLUMNS FROM posts LIKE 'founder_approval_hash'")->fetch();
        if(!$column||strtolower($column['Type'])!=='char(64)'||$column['Null']!=='YES')throw new RuntimeException('The founder approval column is incompatible.');
        $index=$pdo->query("SHOW INDEX FROM founder_submissions WHERE Key_name='idx_founder_story_post'")->fetchAll();
        if(!$index){$pdo->exec($sql[1]);$index=$pdo->query("SHOW INDEX FROM founder_submissions WHERE Key_name='idx_founder_story_post'")->fetchAll();}
        usort($index,fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
        if(array_column($index,'Column_name')!==['associated_post_id','id']||array_filter($index,fn($r)=>(int)$r['Non_unique']!==1))throw new RuntimeException('The founder lookup index is incompatible.');
        foreach(array_slice($sql,2)as$statement)$pdo->exec($statement);
        $required=[
            'founder_story_workflows'=>['founder_id','post_id','email','whatsapp','contact_evidence','state','rounds_used','current_review_id','approved_hash','created_at','updated_at'],
            'founder_story_reviews'=>['id','founder_id','round_no','token_hash','contact_hash','snapshot_json','content_hash','proposed_json','response_note','state','response_days','expires_at','responded_at','created_by','created_at'],
            'founder_story_deliveries'=>['id','review_id','channel','payload_encrypted','state','provider_id','error_code','claimed_at','accepted_at']
        ];
        foreach($required as$table=>$columns)if(array_diff($columns,array_column($pdo->query('SHOW COLUMNS FROM '.$table)->fetchAll(),'Field')))throw new RuntimeException('A founder table has an incompatible schema.');
        foreach(['founder_story_workflows'=>[['founder_id'],['post_id']],'founder_story_reviews'=>[['id'],['token_hash'],['founder_id','round_no']],'founder_story_deliveries'=>[['id'],['review_id','channel']]]as$table=>$keys){
            $indexes=[];foreach($pdo->query('SHOW INDEX FROM '.$table)->fetchAll()as$row)if((int)$row['Non_unique']===0)$indexes[$row['Key_name']][(int)$row['Seq_in_index']]=$row['Column_name'];
            $actual=[];foreach($indexes as$parts){ksort($parts);$actual[]=array_values($parts);}foreach($keys as$key)if(!in_array($key,$actual,true))throw new RuntimeException('A founder table is missing a required unique key.');
        }
        $pdo->prepare('INSERT INTO schema_migrations(name,sha256) VALUES(?,?)')->execute(['170_founder_story_approval.sql',$hash]);
    }finally{$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
}
