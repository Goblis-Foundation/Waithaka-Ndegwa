<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/migrations.php';

if(!in_array(strtolower((string)getenv('APP_FRESH_BOOTSTRAP')),['1','true','yes','on'],true)){
    fwrite(STDOUT,"Fresh bootstrap is disabled.\n");exit(0);
}

function fresh_fail(string $message): never { fwrite(STDERR,$message."\n");exit(1); }
function fresh_ident(string $value,string $label): string {
    if(!preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,62}\z/',$value))fresh_fail('Invalid '.$label.'.');
    return $value;
}
function fresh_secret_json(string $arn,string $region): array {
    if(!class_exists(\Aws\SecretsManager\SecretsManagerClient::class))fresh_fail('AWS Secrets Manager SDK is unavailable.');
    $client=new \Aws\SecretsManager\SecretsManagerClient(['version'=>'2017-10-17','region'=>$region,'retries'=>2,'http'=>['connect_timeout'=>5,'timeout'=>20]]);
    $result=$client->getSecretValue(['SecretId'=>$arn]);
    $raw=(string)($result['SecretString']??'');
    try{$value=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(Throwable $e){fresh_fail('Database administrator secret is malformed.');}
    if(!is_array($value)||!is_string($value['username']??null)||!is_string($value['password']??null)||$value['username']===''||$value['password']==='')fresh_fail('Database administrator secret is incomplete.');
    return $value;
}
function fresh_admin_pdo(array $secret): PDO {
    $db=(array)app_config('db');
    $host=(string)($db['host']??'');$name=fresh_ident((string)($db['name']??''),'database name');$port=(int)($db['port']??3306);$ca=(string)($db['ssl_ca']??'');
    if($host===''||$port<1||$port>65535||$ca===''||!is_readable($ca))fresh_fail('Database endpoint or TLS CA is not configured.');
    $pdo=new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",$secret['username'],$secret['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::MYSQL_ATTR_SSL_CA=>$ca,PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=>true,
    ]);
    return $pdo;
}
function fresh_apply_sql(PDO $pdo,string $file): void {
    $raw=file_get_contents($file);if($raw===false)fresh_fail('Required SQL file is unreadable.');
    foreach(wk_sql_statements($raw)as$sql)$pdo->exec($sql);
}

$region=(string)app_config('aws_region','');
$adminArn=trim((string)getenv('DB_ADMIN_SECRET_ARN'));
if($region===''||!preg_match('/\Aarn:aws:secretsmanager:[a-z0-9-]+:[0-9]{12}:secret:[A-Za-z0-9/_+=.@-]+\z/',$adminArn))fresh_fail('DB_ADMIN_SECRET_ARN is not configured.');
$secret=fresh_secret_json($adminArn,$region);
$pdo=fresh_admin_pdo($secret);
$lock='wk-fresh-bootstrap-'.substr(hash('sha256',(string)app_config('db.name')),0,40);
$q=$pdo->prepare('SELECT GET_LOCK(?,60)');$q->execute([$lock]);if((int)$q->fetchColumn()!==1)fresh_fail('Another fresh bootstrap is running.');
try{
    $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $core=['users','posts','settings','contributions'];
    $hasCore=count(array_intersect($core,$tables))===count($core);
    if(!$tables){
        fresh_apply_sql($pdo,dirname(__DIR__).'/app/schema.sql');
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations(name VARCHAR(190) PRIMARY KEY,sha256 CHAR(64) NOT NULL,applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
        $files=glob(dirname(__DIR__).'/app/migrations/*.sql')?:[];sort($files,SORT_STRING);
        foreach($files as$file){fresh_apply_sql($pdo,$file);$st=$pdo->prepare('INSERT INTO schema_migrations(name,sha256)VALUES(?,?)');$st->execute([basename($file),hash_file('sha256',$file)]);}
        fwrite(STDOUT,"Fresh database schema initialized.\n");
    }elseif(!$hasCore){
        fresh_fail('Database is not empty and does not contain a complete Waithaka schema. Refusing to overwrite it.');
    }

    $dbName=fresh_ident((string)app_config('db.name'),'database name');
    $runtimeUser=fresh_ident((string)app_config('db.user'),'runtime database user');
    $runtimePass=(string)app_config('db.pass','');
    if(strlen($runtimePass)<24)fresh_fail('Runtime database password is missing or too short.');
    $userSql=$pdo->quote($runtimeUser);$passSql=$pdo->quote($runtimePass);
    $pdo->exec("CREATE USER IF NOT EXISTS {$userSql}@'%' IDENTIFIED BY {$passSql}");
    $pdo->exec("ALTER USER {$userSql}@'%' IDENTIFIED BY {$passSql}");
    $pdo->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON `{$dbName}`.* TO {$userSql}@'%'");

    $writerEmail=strtolower(trim((string)getenv('WRITER_EMAIL')));$writerName=trim((string)getenv('WRITER_NAME'))?:'Waithaka Ndegwa';$writerPass=(string)getenv('WRITER_INITIAL_PASSWORD');
    if(!filter_var($writerEmail,FILTER_VALIDATE_EMAIL)||strlen($writerPass)<18)fresh_fail('Initial Writer identity is incomplete.');
    $count=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if($count===0){
        $st=$pdo->prepare('INSERT INTO users(name,email,password_hash,is_active)VALUES(?,?,?,1)');$st->execute([$writerName,$writerEmail,password_hash($writerPass,PASSWORD_DEFAULT)]);
        fwrite(STDOUT,"Initial Writer created.\n");
    }else{
        $st=$pdo->prepare('SELECT COUNT(*) FROM users WHERE email=? AND is_active=1');$st->execute([$writerEmail]);
        if((int)$st->fetchColumn()!==1)fresh_fail('An existing Writer account differs from the configured bootstrap identity.');
    }
    $settings=[
        'contact.recipient'=>$writerEmail,
        'mail.from'=>$writerEmail,
        'google.ga4'=>'G-RN0YXC2WCH',
        'google.analytics_enabled'=>'1',
    ];
    $st=$pdo->prepare('INSERT INTO settings(setting_key,setting_value,is_secret)VALUES(?,?,0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_secret=0');
    foreach($settings as$key=>$value)$st->execute([$key,$value]);
    fwrite(STDOUT,"Fresh bootstrap complete. Runtime database grants and owner settings verified.\n");
} finally {
    try{$st=$pdo->prepare('SELECT RELEASE_LOCK(?)');$st->execute([$lock]);}catch(Throwable $e){}
}
