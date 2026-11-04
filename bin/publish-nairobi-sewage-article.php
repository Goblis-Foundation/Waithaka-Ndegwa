<?php
declare(strict_types=1);
/** Explicitly authorised new article only. Saved Writer records always take priority. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/nairobi_sewage_article.php';
$slug='the-gold-that-is-nairobis-sewage';
$pdo=db();$lock='waithaka-publish-nairobi-sewage';
$q=$pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lock]);
if((int)$q->fetchColumn()!==1)throw new RuntimeException('Article publication is busy.');
$created=false;
try{
    $pdo->beginTransaction();
    $q=$pdo->prepare('SELECT id,status FROM posts WHERE slug=? FOR UPDATE');$q->execute([$slug]);
    if($q->fetch(PDO::FETCH_ASSOC)){
        $pdo->commit();
        fwrite(STDOUT,"Existing Nairobi sewage article preserved; no publication or editorial change.\n");
    }else{
        $package=nairobi_sewage_article_package();$meta=$package['meta'];
        $owner=(int)(comm_config()['owner']??0);$writer=false;
        if($owner>0){$q=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1');$q->execute([$owner]);$writer=$q->fetchColumn();}
        else{$users=$pdo->query('SELECT id FROM users WHERE is_active=1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);if(count($users)===1)$writer=$users[0];}
        if(!$writer)throw new RuntimeException('A unique active website owner must be configured.');
        $q=$pdo->prepare('SELECT id FROM categories WHERE slug=?');$q->execute([$meta['category_slug']]);$category=$q->fetchColumn();
        if(!$category)throw new RuntimeException('The National Infrastructure category must exist.');
        $q=$pdo->prepare("INSERT INTO posts (user_id,category_id,title,slug,standfirst,content_html,featured_image,featured_image_alt,featured_image_caption,series_name,seo_title,seo_description,status,is_selected,published_at,article_groups_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'published',0,NOW(),?)");
        $q->execute([(int)$writer,(int)$category,$meta['title'],$slug,$meta['standfirst'],$package['body'],$meta['featured_image'],$meta['featured_image_alt'],$meta['featured_image_caption'],'Cities, Water and Enterprise',$meta['seo_title'],$meta['seo_description'],json_encode($meta['article_groups'],JSON_THROW_ON_ERROR)]);
        $id=(int)$pdo->lastInsertId();assign_tags($id,$meta['tags']);
        $pdo->commit();$created=true;
        fwrite(STDOUT,"Published the approved Nairobi sewage article with its approved header only.\n");
    }
}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
finally{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
// Reconcile discovery after commit; no email, social delivery, payments or scheduling.
if($created&&function_exists('editorial_sync_discovery'))editorial_sync_discovery();
