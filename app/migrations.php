<?php
declare(strict_types=1);
function wk_sql_statements(string $sql): array {
    // Migration files use ordinary SQL, without stored procedures or DELIMITER.
    $result=[];$buf='';$quote=null;$lineComment=false;$blockComment=false;
    for($i=0,$n=strlen($sql);$i<$n;$i++){
        $c=$sql[$i];$next=$sql[$i+1]??'';
        if($lineComment){if($c==="\n"){$lineComment=false;$buf.="\n";}continue;}
        if($blockComment){if($c==='*'&&$next==='/'){$blockComment=false;$i++;}continue;}
        if($quote!==null){$buf.=$c;if($c==='\\'&&$i+1<$n){$buf.=$sql[++$i];continue;}if($c===$quote){if($next===$quote){$buf.=$sql[++$i];}else $quote=null;}continue;}
        if(($c==='-'&&$next==='-'&&ctype_space($sql[$i+2]??' '))||$c==='#'){$lineComment=true;continue;}
        if($c==='/'&&$next==='*'){$blockComment=true;$i++;continue;}
        if(in_array($c,["'",'"','`'],true)){$quote=$c;$buf.=$c;continue;}
        if($c===';'){if(trim($buf)!=='')$result[]=trim($buf);$buf='';}else $buf.=$c;
    }
    if($quote!==null||$blockComment)throw new RuntimeException('Unterminated migration SQL.');
    if(trim($buf)!=='')$result[]=trim($buf);return $result;
}
function wk_table_exists(string $table): bool {
    $q=db()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);return(int)$q->fetchColumn()>0;
}
function wk_migrate(bool $fresh=false,bool $plan=false): array {
    $lock='wk-migrate-'.substr(hash('sha256',(string)app_config('db.name')),0,40);
    $q=db()->prepare('SELECT GET_LOCK(?,5)');$q->execute([$lock]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Another migration is running.');
    $actions=[];
    try {
        $tables=db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if($fresh && $tables)throw new RuntimeException('--fresh requires an empty database. Restore existing data and use ordinary migration for recovery.');
        if(!$fresh)foreach(['users','posts','settings','contributions']as$t)if(!in_array($t,$tables,true))throw new RuntimeException('Existing database is incomplete; restore the backup or explicitly initialize an empty database with --fresh.');
        if($fresh){$actions[]='Initialize empty database from recovered schema';if(!$plan)foreach(wk_sql_statements((string)file_get_contents(__DIR__.'/schema.sql'))as$sql)db()->exec($sql);}
        // Preserve V8/V9 installations: the old web-request migrations now run explicitly here.
        if(!$fresh){
            $cols=db()->query('SHOW COLUMNS FROM posts')->fetchAll();$names=array_column($cols,'Field');
            foreach(['writer_note'=>'TEXT NULL','source_notes'=>'LONGTEXT NULL']as$col=>$type)if(!in_array($col,$names,true)){$actions[]='Add posts.'.$col;if(!$plan)db()->exec('ALTER TABLE posts ADD '.$col.' '.$type);}
            $method=db()->query("SHOW COLUMNS FROM contributions LIKE 'method'")->fetch();
            if(!str_contains((string)($method['Type']??''),'paypal')){$actions[]='Enable legacy PayPal method';if(!$plan)db()->exec("ALTER TABLE contributions MODIFY method ENUM('mpesa','pesapal','paypal') NOT NULL");}
            foreach(wk_sql_statements((string)file_get_contents(__DIR__.'/schema.sql'))as$sql){if(preg_match('/^CREATE TABLE IF NOT EXISTS (media_release\w*)\s/i',$sql,$m)&&!wk_table_exists($m[1])){$actions[]='Create '.$m[1];if(!$plan)db()->exec($sql);}}
        }
        if(!$plan)db()->exec('CREATE TABLE IF NOT EXISTS schema_migrations(name VARCHAR(190) PRIMARY KEY,sha256 CHAR(64) NOT NULL,applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
        $applied=[];if(wk_table_exists('schema_migrations'))foreach(db()->query('SELECT name,sha256 FROM schema_migrations')->fetchAll()as$r)$applied[$r['name']]=$r['sha256'];
        $files=glob(__DIR__.'/migrations/*.sql');sort($files,SORT_STRING);
        foreach($files as$file){$name=basename($file);$hash=hash_file('sha256',$file);
            if(isset($applied[$name])){if(!hash_equals($applied[$name],$hash))throw new RuntimeException('Previously applied migration changed: '.$name);continue;}
            $actions[]='Apply '.$name;
            if(!$plan){foreach(wk_sql_statements((string)file_get_contents($file))as$sql)db()->exec($sql);db()->prepare('INSERT INTO schema_migrations(name,sha256)VALUES(?,?)')->execute([$name,$hash]);}
        }
        return $actions;
    } finally { db()->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); }
}
