<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing')exit(2);
require __DIR__.'/prepare.php';
$fixtureSettings=wk_test_database_settings();
$fixtureDatabase=(string)getenv('DB_DATABASE');wk_test_assert_database_name($fixtureDatabase);
if(getenv('WK_TEST_DB_NAME')!==$fixtureDatabase||getenv('DB_HOST')!==$fixtureSettings['host']
    ||getenv('DB_PORT')!==(string)$fixtureSettings['port']||getenv('DB_USERNAME')!==$fixtureSettings['user']
    ||getenv('DB_PASSWORD')!==$fixtureSettings['pass']||getenv('APP_READY')!=='1'){
    throw new RuntimeException('Use the explicit isolated database environment from tests/run.php.');
}
$fixtureConfiguration=getenv('APP_CONFIG_FILE');
if($fixtureConfiguration===false||!is_file($fixtureConfiguration)
    ||realpath($fixtureConfiguration)===realpath(dirname(__DIR__).'/app/config.php')
    ||preg_match('/\A<\?php\s+return\s+\[\]\s*;\s*\z/',(string)file_get_contents($fixtureConfiguration))!==1){
    throw new RuntimeException('APP_CONFIG_FILE must point to an isolated empty fixture configuration.');
}
require dirname(__DIR__).'/app/bootstrap.php';
if(app_config('app_env')!=='testing'||!installed()
    ||app_config('db.host')!==$fixtureSettings['host']||(int)app_config('db.port')!==$fixtureSettings['port']
    ||app_config('db.name')!==$fixtureDatabase||app_config('db.user')!==$fixtureSettings['user']
    ||app_config('db.pass')!==$fixtureSettings['pass']){
    throw new RuntimeException('The loaded configuration does not match the isolated fixture environment.');
}
if(db()->query('SELECT DATABASE()')->fetchColumn()!==$fixtureDatabase)throw new RuntimeException('The connected database is not the explicit fixture database.');
$checks=0;$prefix='campaign-'.bin2hex(random_bytes(5));
function campaign_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;}
function campaign_reject(callable $action,string $label):void{try{$action();}catch(InvalidArgumentException|RuntimeException $e){campaign_check(true,$label);return;}throw new LogicException($label);}
db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Fixture',$prefix.'@example.invalid',password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT)]);$user=(int)db()->lastInsertId();
db()->prepare("INSERT INTO posts(user_id,title,slug,content_html,status)VALUES(?,?,?,?,'draft')")->execute([$user,'Campaign fixture',$prefix,'<p>Offline fixture.</p>']);$postId=(int)db()->lastInsertId();
try{
    $id=campaign_save($postId,['objective'=>'read','success_measure'=>'Compare readership with the prior four editions.','notes'=>'PRIVATE_CAMPAIGN_NOTES'],$user);
    campaign_check($id>0&&campaign_plan($postId)['version']===1,'A purpose is saved independently of publication.');
    campaign_check(campaign_link_set(campaign_source($postId))===[],'Draft articles cannot generate public campaign links.');
    campaign_reject(fn()=>campaign_save($postId,['objective'=>'read','success_measure'=>'A stale change'],$user,0),'Stale campaign edits must be rejected.');
    campaign_reject(fn()=>campaign_save($postId,['objective'=>'invalid','success_measure'=>'Unknown action'],$user,1),'Unknown objectives must be rejected.');
    campaign_reject(fn()=>campaign_save($postId,['objective'=>'subscribe','success_measure'=>str_repeat('x',501)],$user,1),'Oversized planning text must be rejected.');
    campaign_save($postId,['objective'=>'subscribe','success_measure'=>'Compare confirmed subscribers.'],$user,1);
    campaign_check((int)campaign_plan($postId)['version']===2,'Valid edits advance the optimistic version.');
    db()->prepare("UPDATE posts SET status='scheduled',published_at=DATE_ADD(NOW(),INTERVAL 1 DAY) WHERE id=?")->execute([$postId]);
    campaign_check(campaign_link_set(campaign_source($postId))===[],'Future publication must not produce links.');
    db()->prepare("UPDATE posts SET status='published',published_at=DATE_SUB(NOW(),INTERVAL 1 DAY) WHERE id=?")->execute([$postId]);
    $post=campaign_source($postId);$links=campaign_link_set($post);
    campaign_check(count($links)===7,'Six social channels and the newsletter get distinct links.');
    foreach($links as$channel=>$link){parse_str((string)parse_url($link,PHP_URL_QUERY),$query);campaign_check($query['utm_source']===$channel&&$query['utm_campaign']==='post-'.$postId,'Attribution is stable and identifies content, not a reader.');}
    $draft=publishing_default_draft($post);
    foreach($draft['variants']as$channel=>$variant)campaign_check(str_contains($variant['text'],'utm_source='.$channel)&&!$variant['enabled'],'Fresh social variants include the matching link and remain unselected drafts.');
    campaign_check(campaign_delivery_summary($postId)===['packages'=>[],'editions'=>[],'deliveries'=>[]],'Planning creates no delivery records.');
    db()->prepare("UPDATE posts SET status='archived' WHERE id=?")->execute([$postId]);
    campaign_check(campaign_link_set(campaign_source($postId))===[],'Withdrawn content ceases to offer public links.');
    echo "Campaign database checks passed: $checks\n";
}finally{db()->prepare('DELETE FROM posts WHERE id=?')->execute([$postId]);db()->prepare('DELETE FROM users WHERE id=?')->execute([$user]);}
