<?php
declare(strict_types=1);
/** Owner-authorised publication, followed by the separately approved one-time header edit. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/ev_biofuels_header.php';
$slug='electric-vehicles-biofuels-africa';
$lock='waithaka-publish-ev-biofuels-africa';
$pdo=db();
$statement=$pdo->prepare('SELECT GET_LOCK(?,10)');
$statement->execute([$lock]);
if((int)$statement->fetchColumn()!==1)throw new RuntimeException('EV/biofuels publication is busy.');
try {
    $pdo->beginTransaction();
    $check=$pdo->prepare('SELECT id,status FROM posts WHERE slug=? FOR UPDATE');
    $check->execute([$slug]);
    $existing=$check->fetch(PDO::FETCH_ASSOC);
    if($existing){
        $pdo->commit();
        fwrite(STDOUT,"Existing EV/biofuels article preserved; checking the approved one-time presentation edit.\n");
    } else {
        $root=dirname(__DIR__).'/app/content/';
        $meta=json_decode((string)file_get_contents($root.$slug.'.json'),true,512,JSON_THROW_ON_ERROR);
        if(($meta['slug']??'')!==$slug||($meta['research_cutoff']??'')!=='2026-11-04')throw new RuntimeException('Unexpected article manifest.');
        $source=file_get_contents($root.$slug.'.html');
        if(!is_string($source)||strlen($source)<15000)throw new RuntimeException('Article source is missing or incomplete.');
        $body=sanitize_html(evb_header_clean_html($source));
        if(mb_strlen(plain_text($body))<10000||substr_count($body,'<h2>')<10)throw new RuntimeException('Sanitized article is incomplete.');
        $owner=(int)(comm_config()['owner']??0);
        $writer=false;
        if($owner>0){
            $query=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1');
            $query->execute([$owner]);$writer=$query->fetchColumn();
        }else{
            $users=$pdo->query('SELECT id FROM users WHERE is_active=1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
            if(count($users)===1)$writer=$users[0];
        }
        if(!$writer)throw new RuntimeException('A unique active website owner must be configured.');
        $category=$pdo->prepare('SELECT id FROM categories WHERE slug=?');
        $category->execute([$meta['category_slug']]);
        $categoryId=$category->fetchColumn();
        if(!$categoryId)throw new RuntimeException('The article category is missing.');
        $insert=$pdo->prepare("INSERT INTO posts (user_id,category_id,title,slug,standfirst,content_html,featured_image,featured_image_alt,featured_image_caption,series_name,seo_title,seo_description,status,is_selected,published_at,article_groups_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'published',0,NOW(),?)");
        $image=evb_header_spec();
        $insert->execute([(int)$writer,(int)$categoryId,$meta['title'],$slug,$meta['standfirst'],$body,$image['path'],$image['alt'],$image['caption'],'Energy and African Enterprise',$meta['seo_title'],$meta['seo_description'],json_encode($meta['article_groups'],JSON_THROW_ON_ERROR)]);
        $id=(int)$pdo->lastInsertId();
        assign_tags($id,$meta['tags']);
        $pdo->commit();
        if(function_exists('editorial_sync_discovery'))editorial_sync_discovery();
        fwrite(STDOUT,"Published owner-approved EV/biofuels article {$id}: {$slug}.\n");
    }
}catch(Throwable $error){
    if($pdo->inTransaction())$pdo->rollBack();
    throw $error;
}finally{
    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
}
if(evb_header_apply($pdo,(int)(comm_config()['owner']??0))){
    if(function_exists('editorial_sync_discovery'))editorial_sync_discovery();
    fwrite(STDOUT,"Approved article header saved and exact trailing research note removed.\n");
}else fwrite(STDOUT,"Header edit already applied; later Writer changes preserved.\n");
