<?php
declare(strict_types=1);
require_once __DIR__.'/editorial_visibility.php';

/** Reading groups are independent of topics. Public routes never read working copies. */
function article_groups(): array {
    return [
        'personal-experiences'=>['label'=>'Personal Experiences','description'=>'My life, work, travels, successes, failures and the lessons I carry forward.','path'=>'/my-thoughts/group/personal-experiences'],
        'my-enterprise-journey'=>['label'=>'My Enterprise Journey','description'=>'Follow the ventures I help build, their milestones and the learning behind the goal of 30 successful startups.','path'=>'/my-thoughts/group/my-enterprise-journey'],
        'founder-stories'=>['label'=>'Founder Stories','description'=>'Learn from other founders through accounts reviewed and published with their permission.','path'=>'/founders'],
        'ideas-perspectives'=>['label'=>'Ideas & Perspectives','description'=>'Explore ideas on enterprise, innovation, infrastructure, agribusiness, culture and the wider world.','path'=>'/my-thoughts/group/ideas-perspectives'],
    ];
}
function article_editable_groups(): array { return array_diff_key(article_groups(),['founder-stories'=>true]); }
function article_group_values(mixed $raw): array {
    if(!is_array($raw)||count($raw)>3)throw new InvalidArgumentException('Choose up to three article groups.');
    foreach($raw as$value)if(!is_string($value)||!isset(article_editable_groups()[$value]))throw new InvalidArgumentException('Choose a supported article group. Founder Stories uses its separate approval process.');
    return array_values(array_intersect(array_keys(article_editable_groups()),$raw));
}
function article_saved_groups(array $post): array {
    $raw=$post['article_groups_json']??null;
    if($raw===null||$raw==='')return [];
    $values=json_decode((string)$raw,true,8,JSON_THROW_ON_ERROR);
    return article_group_values($values);
}
function article_group_fields(array $input,array $existing): string|null {
    if(!array_key_exists('article_groups_present',$input))return $existing['article_groups_json']??null;
    if($input['article_groups_present']!=='1')throw new InvalidArgumentException('Reload the article grouping controls.');
    return json_encode(article_group_values($input['article_groups']??[]),JSON_THROW_ON_ERROR);
}
function article_enterprise_ids(): array {
    $ids=[];
    foreach(enterprise_public_profiles()as$profile)foreach($profile['post_ids']??[]as$id)if(is_int($id)&&$id>0)$ids[$id]=$id;
    return array_values($ids);
}
function article_founder_sql(): string {
    return "EXISTS(SELECT 1 FROM founder_submissions agf WHERE agf.associated_post_id=p.id AND agf.review_status='approved' AND agf.is_public=1 AND agf.permission_confirmed=1 AND agf.claims_reviewed=1 AND agf.consent_reference IS NOT NULL AND agf.consent_reference<>'')";
}
function article_group_memberships(array $post,bool $founder=false,array $enterpriseIds=[]): array {
    $groups=article_saved_groups($post);
    if(in_array((int)($post['id']??0),$enterpriseIds,true))$groups[]='my-enterprise-journey';
    if($founder)$groups[]='founder-stories';
    if(!$groups)$groups[]='ideas-perspectives';
    return array_values(array_intersect(array_keys(article_groups()),array_unique($groups)));
}
function article_public_context(array $post): array {
    $q=db()->prepare('SELECT '.article_founder_sql().' FROM posts p WHERE p.id=? AND '.editorial_public_where());$q->execute([(int)$post['id']]);$founder=$q->fetchColumn();
    if($founder===false)return ['groups'=>[],'enterprises'=>[]];
    $profiles=[];foreach(enterprise_public_profiles()as$profile)if(in_array((int)$post['id'],$profile['post_ids']??[],true))$profiles[]=$profile;
    return ['groups'=>article_group_memberships($post,(bool)$founder,$profiles?[(int)$post['id']]:[]),'enterprises'=>$profiles];
}
function article_group_filters(array $query): array {
    $out=[];
    foreach(['q'=>120,'topic'=>190]as$key=>$max){$v=$query[$key]??'';if(!is_string($v)||!mb_check_encoding($v,'UTF-8')||mb_strlen($v)>$max||preg_match('/[\x00-\x1f\x7f]/',$v))throw new InvalidArgumentException('Use a valid search phrase and topic.');$out[$key]=trim($v);}
    if($out['topic']!==''&&!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$out['topic']))throw new InvalidArgumentException('Choose a valid topic.');
    return $out;
}
/** Uses the same visibility and founder final-copy guards as the canonical article. */
function article_group_query(string $group,array $filters,array $enterpriseIds): array {
    if(!isset(article_editable_groups()[$group]))throw new InvalidArgumentException('Unknown article group.');
    $enterpriseIds=array_values(array_unique(array_filter($enterpriseIds,static fn($id)=>is_int($id)&&$id>0)));
    $linked=$enterpriseIds?'p.id IN ('.implode(',',$enterpriseIds).')':'0=1';
    $membership="JSON_CONTAINS(COALESCE(p.article_groups_json,'[]'),?)";$params=[json_encode($group,JSON_THROW_ON_ERROR)];
    if($group==='my-enterprise-journey')$membership='('.$membership.' OR '.$linked.')';
    if($group==='ideas-perspectives')$membership='('.$membership." OR (JSON_LENGTH(COALESCE(p.article_groups_json,'[]'))=0 AND NOT ".article_founder_sql().' AND NOT ('.$linked.')))';
    $where=editorial_public_where().' AND '.$membership;
    if($filters['topic']!==''){$where.=' AND c.slug=?';$params[]=$filters['topic'];}
    if($filters['q']!==''){$where.=" AND (p.title LIKE ? ESCAPE '!' OR p.standfirst LIKE ? ESCAPE '!' OR p.content_html LIKE ? ESCAPE '!')";$term='%'.str_replace(['!','%','_'],['!!','!%','!_'],$filters['q']).'%';array_push($params,$term,$term,$term);}
    return [$where,$params];
}
function article_group_archive(string $group,array $filters,?int $page): ?array {
    [$where,$params]=article_group_query($group,$filters,article_enterprise_ids());
    $from=' FROM posts p LEFT JOIN categories c ON c.id=p.category_id WHERE '.$where;
    $q=db()->prepare('SELECT COUNT(*)'.$from);$q->execute($params);
    $pagination=archive_pagination($page,(int)$q->fetchColumn(),24,article_groups()[$group]['path'],array_filter($filters,static fn($value)=>$value!==''));
    if($pagination===null)return null;
    $q=db()->prepare('SELECT p.*,c.name category_name,c.slug category_slug'.$from.' ORDER BY p.published_at DESC,p.id DESC LIMIT 24 OFFSET '.$pagination['offset']);$q->execute($params);
    return ['posts'=>$q->fetchAll(),'pagination'=>$pagination];
}
function article_group_dispatch(string $path): bool {
    if(!preg_match('~^/my-thoughts/group/([a-z-]+)$~D',$path,$match))return false;
    journeys_method(['GET','HEAD']);$group=$match[1];if(!isset(article_editable_groups()[$group]))not_found();
    try{$filters=article_group_filters($_GET);}catch(InvalidArgumentException){not_found();}
    if($filters['topic']!==''&&!category_by_slug($filters['topic']))not_found();
    $archive=article_group_archive($group,$filters,archive_page_number($_GET['page']??null));if($archive===null)not_found();
    $definition=article_groups()[$group];$schema=['@context'=>'https://schema.org','@type'=>'CollectionPage','name'=>$definition['label'],'url'=>url($archive['pagination']['path'])];
    render('article_group',$archive+['page'=>'thoughts','group'=>$group,'filters'=>$filters,'categories'=>categories(),'schema'=>[$schema],'seo'=>['title'=>$definition['label'].($archive['pagination']['page']>1?' · Page '.$archive['pagination']['page']:'').' · Waithaka Ndegwa','description'=>$definition['description'],'canonical'=>url($archive['pagination']['path']),'robots'=>array_filter($filters)?'noindex,follow':'index,follow']]);return true;
}
