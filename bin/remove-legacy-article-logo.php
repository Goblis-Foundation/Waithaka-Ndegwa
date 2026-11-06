<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';

$pdo=db();$lock='waithaka-remove-legacy-article-logo-20261106';
$q=$pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lock]);
if((int)$q->fetchColumn()!==1)throw new RuntimeException('Legacy article-logo cleanup is busy.');
try{
  $legacy=[
    'assets/images/logo.png','/assets/images/logo.png',
    'assets/images/waithaka-wn-logo-proposal.svg','/assets/images/waithaka-wn-logo-proposal.svg'
  ];
  $placeholders=implode(',',array_fill(0,count($legacy),'?'));
  $q=$pdo->prepare("SELECT id,slug,featured_image FROM posts WHERE featured_image IN ($placeholders) OR LOWER(featured_image) LIKE '%old-logo%' FOR UPDATE");
  $pdo->beginTransaction();$q->execute($legacy);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
  if($rows){
    $clear=$pdo->prepare("UPDATE posts SET featured_image='',featured_image_alt='',featured_image_caption='',updated_at=NOW() WHERE id=?");
    foreach($rows as$row)$clear->execute([(int)$row['id']]);
  }
  $key='editorial.legacy_article_logo_cleanup.20261106';
  $proof=json_encode(['cleared'=>array_map(static fn($r)=>['id'=>(int)$r['id'],'slug'=>(string)$r['slug'],'image'=>(string)$r['featured_image']],$rows),'applied_at'=>gmdate('c')],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
  $pdo->prepare("INSERT INTO settings(setting_key,setting_value,is_secret) VALUES(?,?,0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_secret=0")->execute([$key,$proof]);
  $pdo->commit();
  fwrite(STDOUT,json_encode(['cleared'=>count($rows)],JSON_THROW_ON_ERROR).PHP_EOL);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
finally{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
