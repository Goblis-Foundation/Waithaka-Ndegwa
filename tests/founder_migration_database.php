<?php
declare(strict_types=1);

/** Prepared, fresh loopback fixture DB only. Email uses the encrypted local outbox. */
function rd_env(string $primary, string $fallback): string|false { $v = getenv($primary); return $v !== false ? $v : getenv($fallback); }
$host = rd_env('DB_HOST', 'WK_TEST_DB_HOST'); $port = rd_env('DB_PORT', 'WK_TEST_DB_PORT'); $name = rd_env('DB_DATABASE', 'WK_TEST_DB_NAME'); $user = rd_env('DB_USERNAME', 'WK_TEST_DB_USER'); $pass = rd_env('DB_PASSWORD', 'WK_TEST_DB_PASS');
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || getenv('WK_TEST_ALLOW_WRITES') !== '1' || !is_string($name) || !preg_match('/^waithaka_test_[a-z0-9_]+_test$/D', $name) || !in_array($host, ['127.0.0.1', '::1'], true) || !is_string($port) || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535 || !is_string($user) || $user === '' || !is_string($pass)) { fwrite(STDERR, "Use explicit testing/write opt-in, numeric-loopback DB_* credentials and a fresh waithaka_test_*_test schema.\n"); exit(2); }
$CONFIG = ['app_env' => 'testing', 'app_key' => 'reader-database-fixture-key-not-production', 'base_url' => 'https://www.waithakandegwa.com', 'timezone' => 'Africa/Nairobi', 'worker_enabled' => false, 'db' => ['host' => $host, 'port' => (int)$port, 'name' => $name, 'user' => $user, 'pass' => $pass, 'charset' => 'utf8mb4'], 'mail' => ['transport' => 'disabled', 'from' => 'fixture@example.invalid', 'from_name' => 'Reader fixture', 'reply_to' => '', 'postal_address' => 'Synthetic fixture address']];
$APP_INSTALLED = true; date_default_timezone_set('Africa/Nairobi');
foreach (['functions', 'journeys', 'operations', 'integrations', 'mail_feedback', 'newsletter', 'newsletter_routes', 'reader','publishing','founder_stories'] as $module) require_once __DIR__.'/../app/'.$module.'.php';
$pdo=db();$file=__DIR__.'/../app/migrations/170_founder_story_approval.sql';
require __DIR__.'/../app/migrations.php';
require __DIR__.'/../app/founder_story_migration.php';
function fm_check(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
foreach(['founder_story_deliveries','founder_story_reviews','founder_story_workflows']as$table){fm_check((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'Use empty founder fixture tables.');$pdo->exec('DROP TABLE '.$table);}
$pdo->exec('ALTER TABLE posts DROP COLUMN founder_approval_hash');
$pdo->exec('ALTER TABLE founder_submissions DROP INDEX idx_founder_story_post');
$pdo->prepare('DELETE FROM schema_migrations WHERE name=?')->execute(['170_founder_story_approval.sql']);
$before=(int)$pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
founder_story_apply_migration($pdo,$file);
fm_check((int)$pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn()===$before,'Existing posts changed during additive migration.');
founder_story_apply_migration($pdo,$file);
$q=$pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE name=?');$q->execute(['170_founder_story_approval.sql']);fm_check((int)$q->fetchColumn()===1,'Migration receipt must be unique and idempotent.');
$pdo->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Schema fixture','schema-fixture@example.invalid',password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT)]);$actor=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO posts(user_id,title,slug,content_html,status) VALUES(?,?,?,?,'draft')")->execute([$actor,'Preserved schema fixture','preserved-schema-fixture','<p>Preserved body.</p>']);$post=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO founder_submissions(reference,submission_type,submitter_name,submitter_email,founder_name,venture_name,country,story_summary,metric_basis,privacy_version,privacy_accepted_at,ip_hash,associated_post_id,created_at,updated_at) VALUES('FM-FIXTURE','founder','Schema fixture','schema@example.invalid','Schema fixture','Fixture','Kenya','Private fixture','not_applicable','fixture',NOW(),?,?,NOW(),NOW())")->execute([str_repeat('0',64),$post]);$founder=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO founder_story_workflows(founder_id,post_id,email,whatsapp,contact_evidence,created_at,updated_at) VALUES(?,?,'schema@example.invalid','+254700000004','Preserved private approval evidence',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$founder,$post]);
$pdo->prepare('DELETE FROM schema_migrations WHERE name=?')->execute(['170_founder_story_approval.sql']);
founder_story_apply_migration($pdo,$file);
fm_check($pdo->query('SELECT contact_evidence FROM founder_story_workflows WHERE founder_id='.$founder)->fetchColumn()==='Preserved private approval evidence','Partial-completion recovery must retain existing workflow rows.');
$pdo->prepare('UPDATE schema_migrations SET sha256=? WHERE name=?')->execute([str_repeat('0',64),'170_founder_story_approval.sql']);
$rejected=false;try{founder_story_apply_migration($pdo,$file);}catch(RuntimeException){$rejected=true;}fm_check($rejected,'An applied checksum mismatch must reject.');
$pdo->prepare('UPDATE schema_migrations SET sha256=? WHERE name=?')->execute([hash_file('sha256',$file),'170_founder_story_approval.sql']);
$temp=tempnam(sys_get_temp_dir(),'founder-schema-test-');if($temp===false)throw new RuntimeException('Cannot allocate fixture.');
try{file_put_contents($temp,file_get_contents($file)."\nDELETE FROM posts;\n");$rejected=false;try{founder_story_apply_migration($pdo,$temp);}catch(RuntimeException){$rejected=true;}fm_check($rejected,'A non-allowlisted extra statement must reject.');}finally{unlink($temp);}
fm_check((int)$pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn()===$before+1,'Rejected plans must not change application rows.');
echo 'PASS: additive schema creation, repeated deployment, partial-completion recovery, retained private rows and fixed-statement/checksum boundaries.'.PHP_EOL;
