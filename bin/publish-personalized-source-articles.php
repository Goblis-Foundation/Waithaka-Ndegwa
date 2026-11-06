<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';

$root=dirname(__DIR__).'/app/content/';
$manifest=json_decode((string)file_get_contents($root.'personalized-source-articles-20261106.json'),true,256,JSON_THROW_ON_ERROR);
if(($manifest['release']??'')!=='personalized-source-articles-20261106'||($manifest['research_cutoff']??'')!=='2026-11-06'||count($manifest['entries']??[])!==6)throw new RuntimeException('Unexpected personalized article manifest.');
$owner=(int)(comm_config()['owner']??0);
if(!$owner)throw new RuntimeException('A unique active website owner must be configured.');
$q=db()->prepare('SELECT id FROM users WHERE id=? AND is_active=1');$q->execute([$owner]);if(!$q->fetchColumn())throw new RuntimeException('Configured website owner is not active.');
$pdo=db();$lock='waithaka-publish-personalized-source-articles-20261106';$q=$pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lock]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Personalized article publication is busy.');
$created=[];
try{
  foreach($manifest['entries']as$meta){
    $slug=(string)($meta['slug']??'');if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)+$/D',$slug))throw new RuntimeException('Invalid article slug.');
    $check=$pdo->prepare('SELECT id,status FROM posts WHERE slug=?');$check->execute([$slug]);if($existing=$check->fetch(PDO::FETCH_ASSOC)){$created[]=['slug'=>$slug,'state'=>'preserved','id'=>(int)$existing['id']];continue;}
    $source=(string)file_get_contents($root.$slug.'.html');if(strlen($source)<3500)throw new RuntimeException('Article source is incomplete: '.$slug);
    $body=sanitize_html($source);$words=count(preg_split('/\s+/u',plain_text($body),-1,PREG_SPLIT_NO_EMPTY));
    if($words<550||substr_count($body,'<h2>')<5)throw new RuntimeException('Article lacks reviewed depth: '.$slug);
    $category=$pdo->prepare('SELECT id FROM categories WHERE slug=?');$category->execute([$meta['category_slug']]);$categoryId=$category->fetchColumn();if(!$categoryId)throw new RuntimeException('Missing article category: '.$meta['category_slug']);
    $pdo->beginTransaction();
    try{
      $insert=$pdo->prepare("INSERT INTO posts (user_id,category_id,title,slug,standfirst,content_html,featured_image,featured_image_alt,featured_image_caption,series_name,seo_title,seo_description,status,is_selected,published_at,article_groups_json,source_notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'published',0,NOW(),?,?)");
      $insert->execute([$owner,(int)$categoryId,$meta['title'],$slug,$meta['standfirst'],$body,'','','',$meta['series_name'],$meta['seo_title'],$meta['seo_description'],json_encode($meta['article_groups'],JSON_THROW_ON_ERROR),'Updated from earlier personal notes; current source check completed 6 November 2026.']);
      $id=(int)$pdo->lastInsertId();assign_tags($id,$meta['tags']);$pdo->commit();$created[]=['slug'=>$slug,'state'=>'published','id'=>$id];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  }
  if(function_exists('editorial_sync_discovery'))editorial_sync_discovery();
  fwrite(STDOUT,json_encode($created,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);
}finally{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
