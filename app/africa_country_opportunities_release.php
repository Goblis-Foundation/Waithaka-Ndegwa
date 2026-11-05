<?php
declare(strict_types=1);
require_once __DIR__.'/opportunity_guides.php';
require_once __DIR__.'/curated_opportunities_release.php';

function africa_country_opportunities_catalog(?array $data=null): array {
    $data??=json_decode((string)file_get_contents(__DIR__.'/content/africa-country-opportunities-20261105.json'),true,96,JSON_THROW_ON_ERROR);
    $expected=['undp-timbuktoo-greentech-launchpad','undp-timbuktoo-edtech-launchpad','undp-timbuktoo-minetech-launchpad','undp-timbuktoo-creativetech-launchpad','undp-timbuktoo-agritech-launchpad','undp-timbuktoo-healthtech-launchpad','gef-small-grants-northern-cape-conservation-2026'];
    if(($data['release']??'')!=='africa-country-opportunities-20261105'||array_column($data['entries']??[],'slug')!==$expected)throw new RuntimeException('Unexpected Africa country opportunity release.');
    if(($data['checked_on']??'')!=='2026-11-05')throw new RuntimeException('Unexpected source-check date.');
    foreach($data['entries'] as $entry){
        foreach(['slug','title','organisation','type','topic','sectors','summary','support','deadline_label','countries','apply_url']as$key)if(!is_string($entry[$key]??null)||trim($entry[$key])==='')throw new RuntimeException('Incomplete opportunity field: '.$key);
        if(!isset(desk_types()[$entry['type']]))throw new RuntimeException('Unsupported opportunity type.');
        desk_url($entry['apply_url'],'application destination',true);
        foreach($entry['sources']??[]as$source){if(!is_array($source)||count($source)!==2)throw new RuntimeException('Malformed source.');desk_url($source[1],'official source',true);}
        foreach(['benefits','eligibility','conditions','steps']as$key)if(!is_array($entry[$key]??null)||count($entry[$key])<2)throw new RuntimeException('Missing opportunity detail section.');
        foreach(['context','fit','strategy','commitment']as$key)desk_text($entry['guide'][$key]??null,$key,6000,true);
        if(count($entry['guide']['prepare']??[])<3)throw new RuntimeException('Preparation guidance required.');
        if($entry['deadline_iso']!==null){$d=new DateTimeImmutable($entry['deadline_iso']);if($d->format(DATE_ATOM)!==$entry['deadline_iso'])throw new RuntimeException('Deadline must be an exact ISO-8601 timestamp.');}
    }
    return $data;
}
function africa_country_opportunity_input(array $entry,string $checked): array {
    $input=curated_release_input($entry,$checked);
    if($entry['deadline_iso']===null){$input['deadline_mode']='rolling';$input['deadline']='';$input['deadline_date']='';}
    return $input;
}
function africa_country_opportunities_import(int $uid,?array $catalog=null): array {
    opportunity_owner($uid);$catalog=africa_country_opportunities_catalog($catalog);$report=[];
    foreach($catalog['entries']as$entry){
        $slug=$entry['slug'];$markerKey='curated.release.20261105.country.'.$slug;
        $result=comm_lock('desk.catalog',function()use($uid,$catalog,$entry,$slug,$markerKey){
            $marker=comm_get($markerKey);
            if(!empty($marker['record'])){
                $record=desk_get($marker['record']);
                if(!empty($record['public'])||in_array($record['state'],['closed','withdrawn'],true))return ['state'=>'preserved','record'=>$record['id'],'url'=>url(desk_path($record))];
                throw new RuntimeException('Interrupted opportunity draft requires review: '.$slug);
            }
            foreach(desk_all()as$record)if($record['slug']===$slug)return ['state'=>'existing_preserved','record'=>$record['id'],'url'=>url(desk_path($record))];
            if($entry['deadline_iso']!==null&&(new DateTimeImmutable($entry['deadline_iso']))->getTimestamp()<=time())return ['state'=>'skipped_expired','record'=>null,'url'=>null];
            $category=category_by_slug($entry['topic']);if(!$category)throw new RuntimeException('Missing article topic: '.$entry['topic']);
            $body=opportunity_guide_body($entry,$entry['guide'],$catalog['guidance_note']);
            if(count(preg_split('/\s+/u',plain_text($body),-1,PREG_SPLIT_NO_EMPTY))<300)throw new RuntimeException('Opportunity guide is too shallow: '.$slug);
            $record=desk_new(africa_country_opportunity_input($entry,$catalog['checked_on']),'opportunity',$uid);
            $record['slug']=$slug;$record['curated_release']=$catalog['release'];
            $record['fields']['deadline_label']=$entry['deadline_label'];$record['fields']['support_label']=$entry['support'];$record['fields']['review_notice']=$entry['notice']??'';
            $record['lifecycle']=['sources'=>array_slice(array_column($entry['sources'],1),0,2),'date'=>$entry['deadline_iso']?substr($entry['deadline_iso'],0,10):'','reported'=>false];
            $record=desk_store($record,'country_source_checked_release',(string)$uid);
            comm_put($markerKey,['record'=>$record['id'],'stage'=>'draft','source_digest'=>hash('sha256',json_encode($entry,JSON_THROW_ON_ERROR))]);
            db()->prepare('UPDATE posts SET slug=? WHERE id=?')->execute([$slug,$record['post_id']]);
            $post=writer_post_by_id((int)$record['post_id']);$input=desk_post_input($post);
            $input['content_html']=$body;$input['category_id']=(int)$category['id'];$input['is_selected']=0;$input['seo_title']=$entry['title'];$input['seo_description']=mb_substr($entry['summary'],0,300);
            $input['source_notes']=json_encode($entry['sources'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);writer_save_post($input,$uid,false);
            $record=desk_update($record['id'],['version'=>$record['version'],'action'=>'approve','approval_ack'=>'1'],$uid);
            $record=desk_update($record['id'],['version'=>$record['version'],'action'=>'publish'],$uid);
            if(($record['public']['article']['content_html']??'')!==$body||($record['owner_approval']??'')!==($record['public']['digest']??''))throw new RuntimeException('Publication readback failed: '.$slug);
            comm_put($markerKey,['record'=>$record['id'],'stage'=>'published','public_digest'=>$record['public']['digest'],'at'=>time()]);
            return ['state'=>'published','record'=>$record['id'],'url'=>url(desk_path($record))];
        });
        $report[]=['slug'=>$slug]+$result;
    }
    return $report;
}
