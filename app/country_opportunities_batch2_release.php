<?php
declare(strict_types=1);
require_once __DIR__.'/opportunity_guides.php';
require_once __DIR__.'/curated_opportunities_release.php';

function country_opportunities_batch2_catalog(?array $data=null): array {
    $data??=json_decode((string)file_get_contents(__DIR__.'/content/country-opportunities-20261106-batch2.json'),true,128,JSON_THROW_ON_ERROR);
    $expected=[
      'ecowyert-south-africa-regional-trade-training',
      'ecowyert-zimbabwe-regional-trade-training',
      'ifad-crisis-response-farmers-organisations-2026',
      'mastercard-cambridge-scholars-2027-2028',
      'undp-ieee-genai-for-good-cities-2026',
      'isdb-competitive-technology-sourcing-2026'
    ];
    if(($data['release']??'')!=='country-opportunities-20261106-batch2'||array_column($data['entries']??[],'slug')!==$expected)throw new RuntimeException('Unexpected country opportunity batch.');
    if(($data['checked_on']??'')!=='2026-11-06')throw new RuntimeException('Unexpected source-check date.');
    foreach($data['entries'] as $entry){
        foreach(['slug','title','organisation','type','topic','sectors','summary','support','deadline_label','countries','apply_url','deadline_mode'] as $key){
            if(!is_string($entry[$key]??null)||trim($entry[$key])==='')throw new RuntimeException('Incomplete field '.$key.' for '.($entry['slug']??'entry'));
        }
        if(!isset(desk_types()[$entry['type']]))throw new RuntimeException('Unsupported opportunity type.');
        if(!in_array($entry['deadline_mode'],['fixed','rolling','unknown'],true))throw new RuntimeException('Unsupported deadline mode.');
        desk_url($entry['apply_url'],'application destination',true);
        if(($entry['deadline_date']??'')!==''&&!ol_date_valid($entry['deadline_date']))throw new RuntimeException('Invalid date-only deadline.');
        if(($entry['deadline_iso']??null)!==null){
            $d=new DateTimeImmutable($entry['deadline_iso']);
            if($d->format(DATE_ATOM)!==$entry['deadline_iso'])throw new RuntimeException('Invalid exact deadline.');
        }
        foreach(['benefits','eligibility','conditions','steps'] as $key)if(!is_array($entry[$key]??null)||count($entry[$key])<3)throw new RuntimeException('Missing '.$key.' for '.$entry['slug']);
        foreach(['context','fit','strategy','commitment'] as $key)desk_text($entry['guide'][$key]??null,$key,7000,true);
        if(count($entry['guide']['prepare']??[])<3)throw new RuntimeException('Preparation guidance required.');
        if(!is_array($entry['sources']??null)||!$entry['sources'])throw new RuntimeException('Official sources required.');
        foreach($entry['sources'] as $source){
            if(!is_array($source)||count($source)!==2)throw new RuntimeException('Malformed source.');
            desk_url($source[1],'official source',true);
        }
    }
    return $data;
}
function country_opportunities_batch2_input(array $entry,string $checked): array {
    $input=curated_release_input($entry,$checked);
    $input['instrument']='';
    if($entry['deadline_mode']==='rolling'){
        $input['deadline_mode']='rolling';$input['deadline']='';$input['deadline_date']='';
    }elseif($entry['deadline_mode']==='unknown'){
        $input['deadline_mode']='unknown';$input['deadline']='';
        if(($entry['deadline_date']??'')!=='')$input['deadline_date']=$entry['deadline_date'];
    }
    return $input;
}
function country_opportunities_batch2_expired(array $entry,?int $now=null): bool {
    $now??=time();
    if(($entry['deadline_iso']??null)!==null)return (new DateTimeImmutable($entry['deadline_iso']))->getTimestamp()<=$now;
    if(($entry['deadline_date']??'')!==''){
        return (new DateTimeImmutable($entry['deadline_date'].' 23:59:59',new DateTimeZone('Africa/Nairobi')))->getTimestamp()<$now;
    }
    return false;
}
function country_opportunities_batch2_import(int $uid,?array $catalog=null): array {
    opportunity_owner($uid);$catalog=country_opportunities_batch2_catalog($catalog);$report=[];
    foreach($catalog['entries'] as $entry){
        $slug=$entry['slug'];$key='curated.release.20261106.country_batch2.'.$slug;
        $result=comm_lock('desk.catalog',function()use($uid,$catalog,$entry,$slug,$key){
            $saved=comm_get($key);
            if(!empty($saved['record'])){
                $record=desk_get($saved['record']);
                if(!empty($record['public'])||in_array($record['state'],['closed','withdrawn'],true))return ['state'=>'preserved','record'=>$record['id'],'url'=>url(desk_path($record))];
                throw new RuntimeException('Interrupted opportunity draft requires review: '.$slug);
            }
            foreach(desk_all() as $record)if($record['slug']===$slug)return ['state'=>'existing_preserved','record'=>$record['id'],'url'=>url(desk_path($record))];
            if(country_opportunities_batch2_expired($entry))return ['state'=>'skipped_expired','record'=>null,'url'=>null];
            $category=category_by_slug($entry['topic']);if(!$category)throw new RuntimeException('Missing article topic: '.$entry['topic']);
            $body=opportunity_guide_body($entry,$entry['guide'],$catalog['guidance_note']);
            if(count(preg_split('/\s+/u',plain_text($body),-1,PREG_SPLIT_NO_EMPTY))<300)throw new RuntimeException('Opportunity guide is too shallow: '.$slug);
            $record=desk_new(country_opportunities_batch2_input($entry,$catalog['checked_on']),'opportunity',$uid);
            $record['slug']=$slug;$record['curated_release']=$catalog['release'];
            $record['fields']['deadline_label']=$entry['deadline_label'];
            $record['fields']['support_label']=$entry['support'];
            $record['fields']['review_notice']=$entry['notice']??'';
            if(($entry['deadline_date']??'')!=='')$record['fields']['deadline_date']=$entry['deadline_date'];
            $record['lifecycle']=['sources'=>array_slice(array_column($entry['sources'],1),0,2),'date'=>$entry['deadline_date']?:($entry['deadline_iso']?substr($entry['deadline_iso'],0,10):''),'reported'=>false];
            $record=desk_store($record,'country_batch2_source_checked_release',(string)$uid);
            comm_put($key,['record'=>$record['id'],'stage'=>'draft','source_digest'=>hash('sha256',json_encode($entry,JSON_THROW_ON_ERROR))]);
            db()->prepare('UPDATE posts SET slug=? WHERE id=?')->execute([$slug,$record['post_id']]);
            $post=writer_post_by_id((int)$record['post_id']);$input=desk_post_input($post);
            $input['content_html']=$body;$input['category_id']=(int)$category['id'];$input['is_selected']=0;
            $input['seo_title']=$entry['title'];$input['seo_description']=mb_substr($entry['summary'],0,300);
            $input['source_notes']=json_encode($entry['sources'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            writer_save_post($input,$uid,false);
            $record=desk_update($record['id'],['version'=>$record['version'],'action'=>'approve','approval_ack'=>'1'],$uid);
            $record=desk_update($record['id'],['version'=>$record['version'],'action'=>'publish'],$uid);
            if(($record['public']['article']['content_html']??'')!==$body||($record['owner_approval']??'')!==($record['public']['digest']??''))throw new RuntimeException('Publication readback failed: '.$slug);
            comm_put($key,['record'=>$record['id'],'stage'=>'published','public_digest'=>$record['public']['digest'],'at'=>time()]);
            return ['state'=>'published','record'=>$record['id'],'url'=>url(desk_path($record))];
        });
        $report[]=['slug'=>$slug]+$result;
    }
    return $report;
}
