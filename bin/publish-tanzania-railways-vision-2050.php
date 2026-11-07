<?php
declare(strict_types=1);
/** Explicitly authorised new article only. Existing Writer records always take priority. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$slug='tanzania-railways-vision-2050';$pdo=db();$lock='waithaka-publish-tanzania-railways-vision-2050';
$q=$pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lock]);
if((int)$q->fetchColumn()!==1)throw new RuntimeException('Tanzania railways article publication is busy.');
$created=false;
try{
  $pdo->beginTransaction();
  $q=$pdo->prepare('SELECT id,status FROM posts WHERE slug=? FOR UPDATE');$q->execute([$slug]);
  if($q->fetch(PDO::FETCH_ASSOC)){
    $pdo->commit();
    fwrite(STDOUT,"Existing Tanzania railways article preserved; no publication or editorial change.\n");
  }else{
    $root=dirname(__DIR__).'/app/content/';
    $meta=json_decode((string)file_get_contents($root.$slug.'.json'),true,512,JSON_THROW_ON_ERROR);
    if(($meta['slug']??'')!==$slug||($meta['research_cutoff']??'')!=='2026-11-07')throw new RuntimeException('Unexpected Tanzania railways article manifest.');
    $source=file_get_contents($root.$slug.'.html');
    if(!is_string($source)||strlen($source)<12000)throw new RuntimeException('Tanzania railways article source is missing or incomplete.');
    $body=sanitize_html($source);
    if(mb_strlen(plain_text($body))<8500||substr_count($body,'<h2>')<8)throw new RuntimeException('Sanitized Tanzania railways article is incomplete.');
    $owner=(int)(comm_config()['owner']??0);$writer=false;
    if($owner>0){$q=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1');$q->execute([$owner]);$writer=$q->fetchColumn();}
    else{$users=$pdo->query('SELECT id FROM users WHERE is_active=1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);if(count($users)===1)$writer=$users[0];}
    if(!$writer)throw new RuntimeException('A unique active website owner must be configured.');
    $q=$pdo->prepare('SELECT id FROM categories WHERE slug=?');$q->execute([$meta['category_slug']]);$category=$q->fetchColumn();
    if(!$category)throw new RuntimeException('The National Infrastructure category must exist.');
    $q=$pdo->prepare("INSERT INTO posts (user_id,category_id,title,slug,standfirst,content_html,featured_image,featured_image_alt,featured_image_caption,series_name,seo_title,seo_description,status,is_selected,published_at,article_groups_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'published',0,NOW(),?)");
    $q->execute([(int)$writer,(int)$category,$meta['title'],$slug,$meta['standfirst'],$body,'','','','African Infrastructure and Enterprise',$meta['seo_title'],$meta['seo_description'],json_encode($meta['article_groups'],JSON_THROW_ON_ERROR)]);
    $id=(int)$pdo->lastInsertId();assign_tags($id,$meta['tags']);
    $pdo->commit();$created=true;
    fwrite(STDOUT,"Published owner-approved Tanzania railways and Vision 2050 article {$id}.\n");
  }
}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
finally{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
if($created&&function_exists('editorial_sync_discovery'))editorial_sync_discovery();
