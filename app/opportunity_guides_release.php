<?php
declare(strict_types=1);
require_once __DIR__.'/opportunity_guides.php';
require_once __DIR__.'/curated_opportunities_release.php';
/** Upgrade only the sixteen unchanged source-reviewed releases. Never overwrite Writer edits. */
function opportunity_guides_upgrade(int $uid,?array $catalog=null,?array $guides=null): array {
    opportunity_owner($uid);
    $owner=db()->prepare('SELECT id FROM users WHERE id=? AND is_active=1');$owner->execute([$uid]);if(!$owner->fetchColumn())throw new RuntimeException('Active publication owner required.');
    $catalog??=curated_release_catalog();$guides??=opportunity_guides_catalog();$report=[];
    foreach($catalog['entries']as$entry){
        $slug=$entry['slug'];if(!isset($guides['entries'][$slug]))throw new RuntimeException('Missing guide: '.$slug);
        $guide=$guides['entries'][$slug];$target=opportunity_guide_body($entry,$guide,$guides['guidance_note']);
        $result=comm_lock('desk.catalog',function()use($uid,$entry,$guide,$slug,$target,$guides){
            $origin=comm_get('curated.release.20261104.'.$slug);
            if(empty($origin['record']))return ['state'=>'not_imported'];
            $r=desk_get($origin['record']);$key='curated.guides.v1.'.$slug;$marker=comm_get($key);
            if($r['state']!=='published'||!$r['public_visible']||desk_closed($r))return ['state'=>'closed_or_private_preserved','record'=>$r['id']];
            if(($marker['stage']??'')==='complete')return ['state'=>'preserved','record'=>$r['id']];
            if(!empty($r['fields']['sponsored'])||($r['curated_release']??'')!=='africa-opportunities-20261104'||$r['slug']!==$slug)throw new RuntimeException('Unexpected publication ownership.');
            $post=writer_post_by_id((int)$r['post_id']);if(!$post)throw new RuntimeException('Source article missing.');
            if(!writer_is_public($post))return ['state'=>'closed_or_private_preserved','record'=>$r['id']];
            $baseline=curated_release_body($entry);$publicBody=(string)($r['public']['article']['content_html']??'');
            if($publicBody!==$baseline)return ['state'=>'editorial_changes_preserved','record'=>$r['id']];
            // Replacing only content_html in the comparison also detects private title/media/field changes.
            $baselinePost=array_replace($post,['content_html'=>$baseline]);
            if(!hash_equals((string)$r['public']['digest'],desk_digest($r,$baselinePost)))return ['state'=>'editorial_changes_preserved','record'=>$r['id']];
            $current=(string)$post['content_html'];
            $resume=($marker['stage']??'')==='draft'&&hash_equals((string)($marker['target']??''),hash('sha256',$target))&&($marker['draft_version']??-1)===(int)$post['_draft_version']&&$current===$target;
            $draftQuery=db()->prepare('SELECT payload FROM post_drafts WHERE post_id=?');$draftQuery->execute([(int)$r['post_id']]);$draft=$draftQuery->fetch(PDO::FETCH_ASSOC);
            // Even a metadata-only private draft is the editor's work, not ours to publish or replace.
            if(($draft['payload']??null)!==null&&!$resume)return ['state'=>'private_draft_preserved','record'=>$r['id']];
            if($current!==$baseline&&!$resume)return ['state'=>'private_draft_preserved','record'=>$r['id']];
            if(!$resume){
                $input=desk_post_input($post);$input['content_html']=$target;
                $input['source_notes']=json_encode(opportunity_guide_sources($entry,$guide),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                $saved=writer_save_post($input,$uid,false);
                comm_put($key,['stage'=>'draft','record'=>$r['id'],'target'=>hash('sha256',$target),'draft_version'=>$saved['version']]);
            }
            // MySQL named locks are reentrant on this connection: the outer catalog lock remains held.
            $r=desk_get($r['id']);$r=desk_update($r['id'],['version'=>$r['version'],'action'=>'approve','approval_ack'=>'1'],$uid);
            $r=desk_update($r['id'],['version'=>$r['version'],'action'=>'publish'],$uid);
            if($r['public']['article']['content_html']!==$target)throw new RuntimeException('Guide publication readback failed.');
            comm_put($key,['stage'=>'complete','record'=>$r['id'],'revision'=>$guides['revision'],'target'=>hash('sha256',$target),'public_digest'=>$r['public']['digest'],'at'=>time()]);
            return ['state'=>'enriched','record'=>$r['id'],'url'=>url(desk_path($r))];
        });
        $report[]=['slug'=>$slug]+$result;
    }
    return $report;
}
