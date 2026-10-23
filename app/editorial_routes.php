<?php
declare(strict_types=1);
require_once __DIR__.'/editorial.php';

function editorial_route_error(Throwable $error): string {
    if($error instanceof EditorialValidationException||$error instanceof EditorialConflictException||$error instanceof MediaLibraryException){http_response_code($error instanceof EditorialConflictException?409:422);return $error->getMessage();}
    $ref=strtoupper(bin2hex(random_bytes(5)));error_log('Editorial request '.$ref.' failed: '.get_class($error));http_response_code(503);return 'This request could not be completed. Please try again later. Reference: '.$ref;
}
function editorial_render(string $view,array $data,string $title,string $description,string $path,array $schema=[]): never {
    render($view,array_merge($data,['page'=>'editorial','seo'=>['title'=>$title.' · Waithaka Ndegwa','description'=>$description,'canonical'=>url($path)],'schema'=>$schema]));exit;
}
function editorial_missing(): never {not_found();exit;}
function editorial_dispatch(string $path): bool {
    if($path==='/start-here'){
        journeys_method(['GET','HEAD']);editorial_render('editorial/start',['seasons'=>journeys_public_seasons(3,true)],'Start Here','Choose a way into the writing: follow a startup from its starting point, test an idea, or learn from founder stories.','/start-here');
    }
    if($path==='/resources'||$path==='/watch'){
        journeys_method(['GET','HEAD']);$type=$path==='/resources'?'resource':'watch';$all=editorial_public_items($type,0);if($type==='resource')$all=array_merge(array_values(editorial_templates()),$all);
        $pagination=archive_pagination(archive_page_number($_GET['page']??null),count($all),24,$path);if($pagination===null)editorial_missing();
        editorial_render('editorial/collection',['items'=>array_slice($all,$pagination['offset'],$pagination['per_page']),'type'=>$type,'pagination'=>$pagination],($type==='resource'?'Resources':'Watch').($pagination['page']>1?' · Page '.$pagination['page']:''),$type==='resource'?'Practical worksheets, checklists and guides for examining a problem, testing an idea and recording what you learn.':'Videos with transcripts, context and clear publication details.',$pagination['path']);
    }
    if(preg_match('~^/resources/([a-z0-9]+(?:-[a-z0-9]+)*)/download$~D',$path,$m)){
        journeys_method(['GET','HEAD']);$item=editorial_public_item('resource',$m[1]);if(!$item)editorial_missing();$text=editorial_download_text($item);
        header('Content-Type: text/plain; charset=utf-8');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex');header('Content-Disposition: attachment; filename="'.$item['slug'].'.txt"');header('Content-Length: '.strlen($text));if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $text;exit;
    }
    if(preg_match('~^/(resources|watch)/([a-z0-9]+(?:-[a-z0-9]+)*)$~D',$path,$m)){
        journeys_method(['GET','HEAD']);$type=$m[1]==='resources'?'resource':'watch';$item=editorial_public_item($type,$m[2]);if(!$item)editorial_missing();$schema=$type==='watch'?editorial_watch_schema($item):[];
        editorial_render('editorial/'.$type,['item'=>$item],$item['title'],$item['summary'],'/'.$m[1].'/'.$item['slug'],$schema?[$schema]:[]);
    }
    if($path==='/write/editorial'){
        journeys_method(['GET','HEAD']);journeys_private_headers();require_auth();
        $items=db()->query('SELECT id,item_type,slug,draft_json,publication_status,published_at,public_modified_at,updated_at FROM editorial_items ORDER BY updated_at DESC,id DESC LIMIT 300')->fetchAll();
        render_write('editorial',['writeTitle'=>'Learning & editorial','items'=>$items]);exit;
    }
    if($path==='/write/editorial/items/new'){
        journeys_method(['GET','POST']);journeys_private_headers();require_auth();$raw=is_post()?($_POST['type']??''):($_GET['type']??'resource');$type=is_string($raw)?$raw:'';if(!isset(editorial_types()[$type]))editorial_missing();
        $values=editorial_default_item($type);$error=null;$row=null;
        if(is_post()){journeys_verify_csrf();$values=$_POST;try{$id=editorial_save_item($type,$_POST,null,(int)current_user()['id'],editorial_text($_POST['action']??'draft','Action',20));flash('success','Editorial item saved. Publication requires its own explicit confirmation.');redirect(url('/write/editorial/items/'.$id));}catch(Throwable $e){$error=editorial_route_error($e);}}
        elseif($type==='resource'&&is_string($_GET['template']??null)&&isset(editorial_templates()[$_GET['template']])){$template=editorial_templates()[$_GET['template']];$values=array_merge($values,array_intersect_key($template,array_flip(['title','summary','content_html','format'])));$values['slug']='my-'.$template['slug'];}
        render_write('editorial_item',['writeTitle'=>'New '.editorial_types()[$type],'type'=>$type,'row'=>$row,'values'=>$values,'error'=>$error,'seasons'=>editorial_writer_seasons(),'media'=>media_library_choices()]);exit;
    }
    if(preg_match('~^/write/editorial/items/([1-9][0-9]{0,9})(/preview)?$~D',$path,$m)){
        journeys_method(empty($m[2])?['GET','POST']:['GET','HEAD']);journeys_private_headers();require_auth();$id=editorial_id($m[1]);$row=editorial_writer_item($id);if(!$row)editorial_missing();$type=$row['item_type'];$values=editorial_decode($row['draft_json']);$error=null;
        if(!empty($m[2])){render('editorial/preview',['item'=>$values,'type'=>$type,'itemId'=>$id,'page'=>'editorial','privatePage'=>true,'suppressAnalytics'=>true,'schema'=>[],'seo'=>['title'=>'Private saved preview · Waithaka Ndegwa','description'=>'Private Writer preview.','canonical'=>url('/write/editorial/items/'.$id.'/preview'),'robots'=>'noindex,nofollow,noarchive']]);exit;}
        if(is_post()){journeys_verify_csrf();$values=$_POST;try{$action=editorial_text($_POST['action']??'draft','Action',20);editorial_save_item($type,$_POST,$id,(int)current_user()['id'],$action);flash('success',match($action){'publish'=>'This reviewed version is published or scheduled for the selected time.','archive'=>'This item has been removed from public collections.','draft'=>'Private working copy saved. The public version has not changed.'});redirect(url('/write/editorial/items/'.$id));}catch(Throwable $e){$error=editorial_route_error($e);}}
        $selected=[];foreach(['video_asset_id','thumbnail_asset_id'] as $key){$candidate=$values[$key]??null;if(is_scalar($candidate)&&ctype_digit((string)$candidate))$selected[]=(int)$candidate;}
        render_write('editorial_item',['writeTitle'=>editorial_types()[$type],'type'=>$type,'row'=>$row,'values'=>$values,'error'=>$error,'seasons'=>editorial_writer_seasons(),'media'=>media_library_choices($selected)]);exit;
    }
    if(preg_match('~^/write/editorial/posts/([1-9][0-9]{0,9})$~D',$path,$m)){
        journeys_method(['GET','POST']);journeys_private_headers();require_auth();$id=editorial_id($m[1]);$q=db()->prepare('SELECT id,title,slug,status,published_at FROM posts WHERE id=?');$q->execute([$id]);$post=$q->fetch();if(!$post)editorial_missing();$saved=editorial_post_draft($id);$values=$saved['draft'];$error=null;
        if(is_post()){journeys_verify_csrf();$values=$_POST;try{$action=editorial_text($_POST['action']??'draft','Action',20);editorial_save_post_meta($id,$_POST,(int)current_user()['id'],$action);flash('success',$action==='publish'?'The reviewed evidence and any meaningful update note are public.':'Evidence working copy saved privately.');redirect(url('/write/editorial/posts/'.$id));}catch(Throwable $e){$error=editorial_route_error($e);}}
        render_write('editorial_post',['writeTitle'=>'Public evidence & updates','post'=>$post,'values'=>$values,'rowVersion'=>$saved['row_version'],'public'=>editorial_public_post($id),'error'=>$error]);exit;
    }
    return false;
}
function editorial_writer_seasons(): array {return db()->query('SELECT id,title,publication_status,published_at FROM journey_seasons ORDER BY updated_at DESC,id DESC LIMIT 500')->fetchAll();}
