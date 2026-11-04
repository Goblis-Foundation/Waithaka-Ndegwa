<?php
declare(strict_types=1);
/** Owner-requested, single release. Uses native Writer and Publishing Desk approvals. */
function curated_release_catalog(): array {
    $data=json_decode((string)file_get_contents(__DIR__.'/content/africa-opportunities-20261104.json'),true,64,JSON_THROW_ON_ERROR);
    if(($data['release']??'')!=='africa-opportunities-20261104'||count($data['entries']??[])!==16)throw new RuntimeException('Unexpected opportunity release.');
    $slugs=[];
    foreach($data['entries'] as $entry){
        $slug=$entry['slug']??'';
        if(!is_string($slug)||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)+$/D',$slug)||isset($slugs[$slug]))throw new RuntimeException('Invalid or duplicate opportunity slug.');
        $slugs[$slug]=true;
        foreach(['title','organisation','summary','support','deadline_label','countries','topic']as$key)if(!is_string($entry[$key]??null)||trim($entry[$key])==='')throw new RuntimeException('Incomplete opportunity: '.$slug);
        if(!isset(desk_types()[$entry['type']??''])||!is_array($entry['sources']??null)||!$entry['sources'])throw new RuntimeException('Missing category or sources.');
        desk_url($entry['apply_url'],'application destination',true);
        foreach($entry['sources']as$source){if(count($source)!==2)throw new RuntimeException('Malformed source.');desk_url($source[1],'source',true);}
        if($entry['deadline_iso']!==null&&!preg_match('/^202[67]-\d{2}-\d{2}T\d{2}:\d{2}:00[+-]\d{2}:\d{2}$/D',$entry['deadline_iso']))throw new RuntimeException('Ambiguous exact deadline.');
        foreach(['benefits','eligibility','conditions','steps']as$key)if(!is_array($entry[$key]??null)||!$entry[$key])throw new RuntimeException('Missing opportunity section.');
    }
    return $data;
}
function curated_release_body(array $entry): string {
    $body='<p><strong>Deadline:</strong> '.e($entry['deadline_label']).'</p><p><strong>Funding or support:</strong> '.e($entry['support']).'</p>';
    if($entry['notice']!=='')$body.='<blockquote><p><strong>Check before applying:</strong> '.e($entry['notice']).'</p></blockquote>';
    $body.='<p>'.e($entry['summary']).'</p><h2>Eligible countries</h2><p>'.e($entry['countries']).'</p>';
    foreach(['benefits'=>'What it offers','eligibility'=>'Who can apply','conditions'=>'Important conditions','steps'=>'How to apply']as$key=>$heading){
        $tag=$key==='steps'?'ol':'ul';$body.='<h2>'.e($heading).'</h2><'.$tag.'>';
        foreach($entry[$key]as$text)$body.='<li>'.e($text).'</li>';
        $body.='</'.$tag.'>';
    }
    $body.='<p><a href="'.e($entry['apply_url']).'" target="_blank" rel="noopener noreferrer">Check the official details and application route</a></p><h2>Sources and official information</h2><ul>';
    foreach($entry['sources']as[$label,$link])$body.='<li><a href="'.e($link).'" target="_blank" rel="noopener noreferrer">'.e($label).'</a></li>';
    return sanitize_html($body.'</ul>');
}
function curated_release_input(array $entry,string $checked): array {
    $fixed=$entry['deadline_iso']!==null;
    $in=['title'=>$entry['title'],'organisation'=>$entry['organisation'],'summary'=>$entry['summary'],'description'=>$entry['summary'],
        'type'=>$entry['type'],'countries'=>$entry['countries'],'sectors'=>$entry['sectors'],'instrument'=>$entry['support'],
        'source_url'=>$entry['sources'][0][1],'apply_url'=>$entry['apply_url'],'cta_label'=>'Check official details / apply',
        'deadline_mode'=>$fixed?'fixed':'unknown','timezone'=>'Africa/Nairobi','deadline'=>$fixed?(new DateTimeImmutable($entry['deadline_iso']))->setTimezone(new DateTimeZone('Africa/Nairobi'))->format('Y-m-d\TH:i'):'',
        'verified_on'=>$checked,'verification_notes'=>'Independent editorial source check, not organiser endorsement. '.($entry['notice']?:'Core terms checked against linked organiser material; applicants must confirm current details.'),
        'source_excerpt'=>json_encode($entry,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
        'sponsored'=>false,'asset_permissions'=>'Original native typographic header; no third-party logos or photographs.'];
    foreach(['benefits','eligibility','conditions','steps']as$key)$in[$key]=implode("\n",$entry[$key]);
    return $in;
}
function curated_release_import(int $uid,?array $catalog=null): array {
    if($uid<1||$uid!==(int)(comm_config()['owner']??0))throw new RuntimeException('Configured website owner required.');
    $owner=db()->prepare('SELECT id FROM users WHERE id=? AND is_active=1');$owner->execute([$uid]);if(!$owner->fetchColumn())throw new RuntimeException('Active owner required.');
    $catalog??=curated_release_catalog();$report=[];
    foreach($catalog['entries']as$entry){
        $key='curated.release.20261104.'.$entry['slug'];
        $result=comm_lock('desk.catalog',function()use($uid,$catalog,$entry,$key){
            $saved=comm_get($key);
            if(!empty($saved['record'])){
                $r=desk_get($saved['record']);
                if(!empty($r['public'])||in_array($r['state'],['withdrawn','closed'],true))return ['state'=>'preserved','record'=>$r['id'],'url'=>url(desk_path($r))];
                throw new RuntimeException('A partial or edited draft needs review before retrying: '.$entry['slug']);
            }
            foreach(desk_all()as$r){
                if($r['slug']===$entry['slug']||(($r['fields']['source_url']??'')===$entry['sources'][0][1]&&($r['fields']['title']??'')===$entry['title']))return ['state'=>'existing_preserved','record'=>$r['id'],'url'=>url(desk_path($r))];
            }
            $collision=db()->prepare('SELECT id FROM posts WHERE slug=?');$collision->execute([$entry['slug']]);if($collision->fetchColumn())throw new RuntimeException('Existing Writer slug must not be overwritten: '.$entry['slug']);
            $category=category_by_slug($entry['topic']);if(!$category)throw new RuntimeException('Missing article topic: '.$entry['topic']);
            $in=curated_release_input($entry,$catalog['checked_on']);desk_fields($in,'opportunity',true);
            if($entry['deadline_iso']!==null&&(new DateTimeImmutable($entry['deadline_iso']))->getTimestamp()<=time())throw new RuntimeException('Opportunity closed before release: '.$entry['slug']);
            $r=desk_new($in,'opportunity',$uid);
            $r['slug']=$entry['slug'];$r['curated_release']=$catalog['release'];
            // Supplemental display fields are part of the exact-version approval digest and public snapshot.
            $r['fields']['deadline_label']=$entry['deadline_label'];$r['fields']['support_label']=$entry['support'];$r['fields']['review_notice']=$entry['notice'];
            $r=desk_store($r,'curated_source_review',(string)$uid);
            comm_put($key,['record'=>$r['id'],'stage'=>'draft','source_digest'=>hash('sha256',json_encode($entry,JSON_THROW_ON_ERROR))]);
            db()->prepare('UPDATE posts SET slug=? WHERE id=?')->execute([$entry['slug'],$r['post_id']]);
            $p=writer_post_by_id((int)$r['post_id']);$input=desk_post_input($p);
            $input['content_html']=curated_release_body($entry);$input['category_id']=(int)$category['id'];$input['is_selected']=0;
            $input['seo_title']=$entry['title'];$input['seo_description']=mb_substr($entry['summary'],0,300);
            $input['source_notes']=json_encode($entry['sources'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            writer_save_post($input,$uid,false);
            return ['state'=>'ready','record'=>$r['id'],'url'=>url(desk_path($r))];
        });
        if($result['state']==='ready'){
            $r=desk_get($result['record']);$r=desk_update($r['id'],['version'=>$r['version'],'action'=>'approve','approval_ack'=>'1'],$uid);
            $r=desk_update($r['id'],['version'=>$r['version'],'action'=>'publish'],$uid);
            $saved=comm_get($key);$saved['stage']='published';$saved['published_at']=time();$saved['digest']=$r['public']['digest'];comm_put($key,$saved);
            $result['state']='published';
        }
        $report[]=['slug'=>$entry['slug']]+$result;
    }
    return $report;
}
