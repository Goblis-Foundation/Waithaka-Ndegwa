<?php
declare(strict_types=1);
require_once __DIR__.'/opportunity_guides.php';
require_once __DIR__.'/curated_opportunities_release.php';

function education_research_opportunities_catalog(?array $data=null): array {
    $data??=json_decode((string)file_get_contents(__DIR__.'/content/education-research-opportunities-20261106.json'),true,128,JSON_THROW_ON_ERROR);
    $expected=['commonwealth-shared-scholarships-2027-2028','erasmus-mundus-joint-masters-2027','mastercard-foundation-scholars-cmu-africa-open','mastercard-foundation-scholars-alu-open','aerc-industrial-policy-growth-africa-research-2026','twas-research-continuity-grants-africa-2026'];
    if(($data['release']??'')!=='education-research-opportunities-20261106'||array_column($data['entries']??[],'slug')!==$expected)throw new RuntimeException('Unexpected education and research opportunity release.');
    if(($data['checked_on']??'')!=='2026-11-06')throw new RuntimeException('Unexpected source-check date.');
    foreach($data['entries']as$entry){
        foreach(['slug','title','organisation','type','topic','sectors','summary','support','deadline_label','countries','apply_url','deadline_mode']as$key)if(!is_string($entry[$key]??null)||trim($entry[$key])==='')throw new RuntimeException('Incomplete field '.$key.' for '.($entry['slug']??'entry'));
        if(!isset(desk_types()[$entry['type']]))throw new RuntimeException('Unsupported opportunity type: '.$entry['type']);
        desk_url($entry['apply_url'],'application destination',true);
        if(!in_array($entry['deadline_mode'],['fixed','unknown'],true))throw new RuntimeException('Unsupported deadline mode.');
        if($entry['deadline_iso']!==null&&!preg_match('/^202[67]-\d{2}-\d{2}T\d{2}:\d{2}:00[+-]\d{2}:\d{2}$/D',$entry['deadline_iso']))throw new RuntimeException('Invalid exact deadline.');
        if(($entry['deadline_date']??'')!==''&&!ol_date_valid($entry['deadline_date']))throw new RuntimeException('Invalid date-only deadline.');
        if($entry['deadline_mode']==='fixed'&&$entry['deadline_iso']===null)throw new RuntimeException('Fixed deadline requires an exact timestamp.');
        foreach(['benefits','eligibility','conditions','steps']as$key)if(!is_array($entry[$key]??null)||count($entry[$key])<2)throw new RuntimeException('Missing '.$key.' for '.$entry['slug']);
        foreach(['context','fit','strategy','commitment']as$key)desk_text($entry['guide'][$key]??null,$key,7000,true);
        if(count($entry['guide']['prepare']??[])<3)throw new RuntimeException('Preparation guidance required.');
        if(!is_array($entry['sources']??null)||!$entry['sources'])throw new RuntimeException('Official sources required.');
        foreach($entry['sources']as$source){if(!is_array($source)||count($source)!==2)throw new RuntimeException('Malformed source.');desk_url($source[1],'official source',true);}
    }
    return $data;
}
function education_research_opportunity_input(array $entry,string $checked): array {
    $input=curated_release_input($entry,$checked);
    $input['instrument']='';
    if($entry['deadline_mode']==='unknown'){$input['deadline_mode']='unknown';$input['deadline']='';}
    return $input;
}
function education_research_opportunity_expired(array $entry,?int $now=null): bool {
    $now??=time();
    if($entry['deadline_iso']!==null)return (new DateTimeImmutable($entry['deadline_iso']))->getTimestamp()<=$now;
    if(($entry['deadline_date']??'')!==''){
        return (new DateTimeImmutable($entry['deadline_date'].' 23:59:59',new DateTimeZone('Africa/Nairobi')))->getTimestamp()<$now;
    }
    return false;
}
function education_research_opportunities_import(int $uid,?array $catalog=null): array {
    opportunity_owner($uid);$catalog=education_research_opportunities_catalog($catalog);$report=[];
    foreach($catalog['entries']as$entry){
        $slug=$entry['slug'];$key='curated.release.20261106.education_research.'.$slug;
        $result=comm_lock('desk.catalog',function()use($uid,$catalog,$entry,$slug,$key){
            $saved=comm_get($key);
            if(!empty($saved['record'])){
                $record=desk_get($saved['record']);
                if(!empty($record['public'])||in_array($record['state'],['closed','withdrawn'],true))return ['state'=>'preserved','record'=>$record['id'],'url'=>url(desk_path($record))];
                throw new RuntimeException('Interrupted opportunity draft requires review: '.$slug);
            }
            foreach(desk_all()as$record)if($record['slug']===$slug)return ['state'=>'existing_preserved','record'=>$record['id'],'url'=>url(desk_path($record))];
            if(education_research_opportunity_expired($entry))return ['state'=>'skipped_expired','record'=>null,'url'=>null];
            $category=category_by_slug($entry['topic']);if(!$category)throw new RuntimeException('Missing article topic: '.$entry['topic']);
            $body=opportunity_guide_body($entry,$entry['guide'],$catalog['guidance_note']);
            if(count(preg_split('/\s+/u',plain_text($body),-1,PREG_SPLIT_NO_EMPTY))<300)throw new RuntimeException('Opportunity guide is too shallow: '.$slug);
            $record=desk_new(education_research_opportunity_input($entry,$catalog['checked_on']),'opportunity',$uid);
            $record['slug']=$slug;$record['curated_release']=$catalog['release'];
            $record['fields']['deadline_label']=$entry['deadline_label'];$record['fields']['support_label']=$entry['support'];$record['fields']['review_notice']=$entry['notice']??'';
            if(($entry['deadline_date']??'')!=='')$record['fields']['deadline_date']=$entry['deadline_date'];
            $record['lifecycle']=['sources'=>array_slice(array_column($entry['sources'],1),0,2),'date'=>$entry['deadline_date']?:($entry['deadline_iso']?substr($entry['deadline_iso'],0,10):''),'reported'=>false];
            $record=desk_store($record,'education_research_source_checked_release',(string)$uid);
            comm_put($key,['record'=>$record['id'],'stage'=>'draft','source_digest'=>hash('sha256',json_encode($entry,JSON_THROW_ON_ERROR))]);
            db()->prepare('UPDATE posts SET slug=? WHERE id=?')->execute([$slug,$record['post_id']]);
            $post=writer_post_by_id((int)$record['post_id']);$input=desk_post_input($post);
            $input['content_html']=$body;$input['category_id']=(int)$category['id'];$input['is_selected']=0;$input['seo_title']=$entry['title'];$input['seo_description']=mb_substr($entry['summary'],0,300);
            $input['source_notes']=json_encode($entry['sources'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);writer_save_post($input,$uid,false);
            $record=desk_update($record['id'],['version'=>$record['version'],'action'=>'approve','approval_ack'=>'1'],$uid);
            $record=desk_update($record['id'],['version'=>$record['version'],'action'=>'publish'],$uid);
            if(($record['public']['article']['content_html']??'')!==$body||($record['owner_approval']??'')!==($record['public']['digest']??''))throw new RuntimeException('Publication readback failed: '.$slug);
            if(($entry['deadline_date']??'')!==''&&($record['public']['fields']['deadline_date']??'')!==$entry['deadline_date'])throw new RuntimeException('Date-only deadline readback failed: '.$slug);
            comm_put($key,['record'=>$record['id'],'stage'=>'published','public_digest'=>$record['public']['digest'],'at'=>time()]);
            return ['state'=>'published','record'=>$record['id'],'url'=>url(desk_path($record))];
        });
        $report[]=['slug'=>$slug]+$result;
    }
    return $report;
}
