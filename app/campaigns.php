<?php
declare(strict_types=1);

final class CampaignConflictException extends RuntimeException {}

function campaign_objectives(): array {
    return ['read'=>'Read the full article','continue'=>'Continue the startup journey','subscribe'=>'Receive weekly updates','discuss'=>'Invite a thoughtful response','submit'=>'Invite founder submissions'];
}
function campaign_source(int $id): ?array {
    $query=db()->prepare('SELECT p.*,c.name category_name FROM posts p LEFT JOIN categories c ON c.id=p.category_id WHERE p.id=?');$query->execute([$id]);return $query->fetch()?:null;
}
function campaign_plan(int $postId): ?array {
    $query=db()->prepare('SELECT * FROM editorial_campaigns WHERE source_post_id=?');$query->execute([$postId]);return $query->fetch()?:null;
}
function campaign_save(int $postId,array $input,int $actor,int $expectedVersion=0): int {
    $objective=newsletter_text($input['objective']??'','Objective',40,true);
    if(!array_key_exists($objective,campaign_objectives()))throw new InvalidArgumentException('Choose a campaign objective.');
    $measure=newsletter_text($input['success_measure']??'','Success measure',500,true);
    $notes=newsletter_text($input['notes']??'','Planning notes',5000);
    db()->beginTransaction();
    try {
        $query=db()->prepare('SELECT id FROM posts WHERE id=? FOR UPDATE');$query->execute([$postId]);
        if(!$query->fetchColumn())throw new InvalidArgumentException('Article not found.');
        $plan=campaign_plan($postId);
        if(($plan?(int)$plan['version']:0)!==$expectedVersion)throw new CampaignConflictException('This campaign plan changed in another window. Reload before saving.');
        if($plan){db()->prepare('UPDATE editorial_campaigns SET objective=?,success_measure=?,notes=?,version=version+1,updated_by=? WHERE id=?')->execute([$objective,$measure,$notes,$actor,$plan['id']]);$id=(int)$plan['id'];}
        else{db()->prepare('INSERT INTO editorial_campaigns(source_post_id,objective,success_measure,notes,created_by,updated_by)VALUES(?,?,?,?,?,?)')->execute([$postId,$objective,$measure,$notes,$actor,$actor]);$id=(int)db()->lastInsertId();}
        db()->commit();return $id;
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
function campaign_link_set(array $post): array {
    $q=db()->prepare('SELECT 1 FROM posts p WHERE p.id=? AND '.published_where());$q->execute([(int)$post['id']]);
    if(!$q->fetchColumn())return [];
    $path='/my-thoughts/'.$post['slug'];$code='post-'.(int)$post['id'];$links=[];
    foreach(['linkedin','facebook','instagram','x','youtube','tiktok','newsletter']as$source){
        $medium=$source==='newsletter'?'email':'social';
        $format=in_array($source,['youtube','tiktok'],true)?'video':($source==='instagram'?'bio':($source==='newsletter'?'newsletter':'article'));
        $links[$source]=analytics_campaign_url($path,$source,$medium,$code,$format);
    }
    return $links;
}
function campaign_delivery_summary(int $postId): array {
    $q=db()->prepare('SELECT p.id,p.title,p.draft_version,p.approved_revision_id,COUNT(j.id) job_count FROM publishing_packages p LEFT JOIN publishing_revisions r ON r.package_id=p.id LEFT JOIN publishing_jobs j ON j.revision_id=r.id WHERE p.source_post_id=? GROUP BY p.id ORDER BY p.updated_at DESC LIMIT 20');$q->execute([$postId]);$packages=$q->fetchAll();
    $q=db()->prepare('SELECT id,title,status,audience_size,scheduled_at FROM newsletter_campaigns WHERE source_post_id=? ORDER BY updated_at DESC LIMIT 20');$q->execute([$postId]);$editions=$q->fetchAll();
    $q=db()->prepare('SELECT c.name,j.status,COUNT(*) total FROM publishing_jobs j JOIN publishing_revisions r ON r.id=j.revision_id JOIN publishing_packages p ON p.id=r.package_id JOIN publishing_channels c ON c.id=j.channel_id WHERE p.source_post_id=? GROUP BY c.name,j.status ORDER BY c.name,j.status');$q->execute([$postId]);
    return ['packages'=>$packages,'editions'=>$editions,'deliveries'=>$q->fetchAll()];
}
