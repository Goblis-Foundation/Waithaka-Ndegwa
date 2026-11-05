<?php
declare(strict_types=1);
require_once __DIR__.'/opportunity_guides.php';
require_once __DIR__.'/curated_opportunities_release.php';

/** A bounded, versioned content release; no external calls or application submissions. */
function rolling_opportunities_catalog(?array $data=null): array {
    $data??=json_decode((string)file_get_contents(__DIR__.'/content/rolling-opportunities-20261105.json'),true,64,JSON_THROW_ON_ERROR);
    $expected=['drk-foundation-social-enterprise-funding','rufford-small-grants-nature-conservation'];
    if(($data['release']??'')!=='rolling-opportunities-20261105'||array_column($data['entries']??[],'slug')!==$expected)throw new RuntimeException('Unexpected rolling opportunity release.');
    if(!ol_date_valid((string)($data['checked_on']??''))||$data['checked_on']>date('Y-m-d'))throw new RuntimeException('Invalid source-check date.');
    if(!is_string($data['guidance_note']??null)||strlen($data['guidance_note'])<80)throw new RuntimeException('Editorial guidance disclosure required.');
    foreach($data['entries']as$entry){
        foreach(['title','organisation','summary','support','deadline_label','countries','topic','sectors','currency','amount_max']as$key)desk_text($entry[$key]??null,$key,2000,true);
        if(!isset(desk_types()[$entry['type']??''])||$entry['deadline_iso']!==null)throw new RuntimeException('Expected a rolling opportunity.');
        $host=$entry['slug']===$expected[0]?'www.drkfoundation.org':'apply.ruffordsmallgrants.org';
        foreach(array_merge([$entry['apply_url']],array_column($entry['sources'],1))as$url){
            desk_url($url,'official source',true);
            if(parse_url($url,PHP_URL_HOST)!==$host)throw new RuntimeException('Unexpected source origin.');
        }
        foreach(['benefits','eligibility','conditions','steps']as$key){
            if(!is_array($entry[$key]??null)||!$entry[$key])throw new RuntimeException('Missing opportunity section.');
            foreach($entry[$key]as$text)desk_text($text,$key,2000,true);
        }
        foreach(['context','fit','strategy','commitment']as$key)desk_text($entry['guide'][$key]??null,$key,3000,true);
        if(count($entry['guide']['prepare']??[])<3)throw new RuntimeException('Preparation guidance required.');
        $input=rolling_opportunities_input($entry,$data['checked_on']);desk_fields($input,'opportunity',true);
        $body=opportunity_guide_body($entry,$entry['guide'],$data['guidance_note']);
        if(count(preg_split('/\s+/u',plain_text($body),-1,PREG_SPLIT_NO_EMPTY))<450||substr_count($body,'<h2>')!==9)throw new RuntimeException('Complete nine-section guide required.');
    }
    return $data;
}
function rolling_opportunities_input(array $entry,string $checked): array {
    $input=curated_release_input($entry,$checked);
    $input['deadline_mode']='rolling';$input['deadline']='';$input['deadline_date']='';
    $input['currency']=$entry['currency'];$input['amount_max']=$entry['amount_max'];
    return $input;
}
function rolling_opportunities_import(int $uid,?array $catalog=null): array {
    opportunity_owner($uid);
    $owner=db()->prepare('SELECT id FROM users WHERE id=? AND is_active=1');$owner->execute([$uid]);
    if(!$owner->fetchColumn())throw new RuntimeException('Active website owner required.');
    $catalog=rolling_opportunities_catalog($catalog);$report=[];
    foreach($catalog['entries']as$entry){
        $slug=$entry['slug'];$key='curated.release.20261105.'.$slug;
        $result=comm_lock('desk.catalog',function()use($uid,$catalog,$entry,$slug,$key){
            $marker=comm_get($key);
            if(!empty($marker['record'])){
                $record=desk_get($marker['record']);
                if($record['slug']!==$slug||($record['curated_release']??'')!==$catalog['release'])throw new RuntimeException('Publication identity mismatch.');
                // Preserve all subsequent owner edits, closures, withdrawals and drafts.
                if(!empty($record['public'])||in_array($record['state'],['closed','withdrawn'],true))return ['state'=>'preserved','record'=>$record['id'],'url'=>url(desk_path($record))];
                throw new RuntimeException('Interrupted opportunity draft requires editorial review: '.$slug);
            }
            foreach(desk_all()as$record){
                if($record['slug']===$slug||(($record['fields']['source_url']??'')===$entry['sources'][0][1]&&($record['fields']['title']??'')===$entry['title']))return ['state'=>'existing_preserved','record'=>$record['id'],'url'=>url(desk_path($record))];
            }
            $collision=db()->prepare('SELECT id FROM posts WHERE slug=?');$collision->execute([$slug]);
            if($collision->fetchColumn())throw new RuntimeException('Existing article slug must not be overwritten.');
            $category=category_by_slug($entry['topic']);if(!$category)throw new RuntimeException('Missing topic: '.$entry['topic']);
            $body=opportunity_guide_body($entry,$entry['guide'],$catalog['guidance_note']);
            $record=desk_new(rolling_opportunities_input($entry,$catalog['checked_on']),'opportunity',$uid);
            $record['slug']=$slug;$record['curated_release']=$catalog['release'];
            $record['fields']['deadline_label']=$entry['deadline_label'];$record['fields']['support_label']=$entry['support'];$record['fields']['review_notice']=$entry['notice'];
            $record['lifecycle']=['sources'=>array_slice(array_column($entry['sources'],1),0,2),'date'=>'','reported'=>false];
            $record=desk_store($record,'source_checked_content_release',(string)$uid);
            comm_put($key,['record'=>$record['id'],'stage'=>'draft','source_digest'=>hash('sha256',json_encode($entry,JSON_THROW_ON_ERROR))]);
            db()->prepare('UPDATE posts SET slug=? WHERE id=?')->execute([$slug,$record['post_id']]);
            $post=writer_post_by_id((int)$record['post_id']);$input=desk_post_input($post);
            $input['content_html']=$body;$input['category_id']=(int)$category['id'];$input['is_selected']=0;
            $input['seo_title']=$entry['title'];$input['seo_description']=mb_substr($entry['summary'],0,300);
            $input['source_notes']=json_encode($entry['sources'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            writer_save_post($input,$uid,false);
            $record=desk_update($record['id'],['version'=>$record['version'],'action'=>'approve','approval_ack'=>'1'],$uid);
            $record=desk_update($record['id'],['version'=>$record['version'],'action'=>'publish'],$uid);
            if($record['public']['article']['content_html']!==$body||$record['public']['fields']['deadline_mode']!=='rolling'||$record['owner_approval']!==$record['public']['digest'])throw new RuntimeException('Publication readback failed.');
            comm_put($key,['record'=>$record['id'],'stage'=>'published','public_digest'=>$record['public']['digest'],'at'=>time()]);
            return ['state'=>'published','record'=>$record['id'],'url'=>url(desk_path($record))];
        });
        $report[]=['slug'=>$slug]+$result;
    }
    return $report;
}
