<?php
declare(strict_types=1);
function campaign_dispatch(string $path): bool {
    if(!preg_match('~^/write/campaigns(?:/([1-9][0-9]{0,9}))?$~D',$path,$match))return false;
    require_auth();private_response_headers();
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');return true;}
    $postId=isset($match[1])?(int)$match[1]:0;
    if(!$postId){
        if(is_post()){http_response_code(405);return true;}
        $page=archive_page_number($_GET['page']??null);$total=(int)db()->query('SELECT COUNT(*) FROM posts')->fetchColumn();
        $pagination=archive_pagination($page,$total,40,'/write/campaigns');if($pagination===null)not_found();
        $posts=db()->query('SELECT p.id,p.title,p.status,p.published_at,e.objective,e.success_measure FROM posts p LEFT JOIN editorial_campaigns e ON e.source_post_id=p.id ORDER BY p.updated_at DESC,p.id DESC LIMIT 40 OFFSET '.(int)$pagination['offset'])->fetchAll();
        render_write('campaigns',['writeTitle'=>'Editorial campaigns','posts'=>$posts,'pagination'=>$pagination]);return true;
    }
    $post=campaign_source($postId);if(!$post)not_found();
    if(is_post()){
        verify_csrf();
        try{
            $version=$_POST['version']??null;if(!is_string($version)||!preg_match('/^(?:0|[1-9][0-9]{0,8})$/D',$version))throw new InvalidArgumentException('Reload the saved campaign before editing.');
            campaign_save($postId,$_POST,(int)current_user()['id'],(int)$version);flash('success','Campaign plan saved. Publication and email delivery still use their own reviewed revisions.');
        }catch(InvalidArgumentException|CampaignConflictException $e){flash('error',$e->getMessage());}
        catch(Throwable $e){$reference=strtoupper(bin2hex(random_bytes(5)));error_log('Campaign save '.$reference.' failed: '.get_class($e));flash('error','The campaign could not be saved. Please retry. Reference: '.$reference);}
        redirect(url('/write/campaigns/'.$postId));
    }
    render_write('campaign_detail',['writeTitle'=>'Editorial campaign','post'=>$post,'plan'=>campaign_plan($postId),'links'=>campaign_link_set($post),'summary'=>campaign_delivery_summary($postId)]);return true;
}
