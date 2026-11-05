<?php
declare(strict_types=1);

/** Run only inside the lifecycle suite's newly created loopback test database. */
function editorial_visibility_assertions(array $initial, int $uid): int {
    if (PHP_SAPI !== 'cli' || app_config('app_env') !== 'testing' || getenv('WK_TEST_ALLOW_WRITES') !== '1') {
        throw new RuntimeException('Editorial visibility assertions require an isolated test environment.');
    }
    $pdo=db();$database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!preg_match('/^waithaka_test_lifecycle_[a-f0-9]{12}_test$/D',$database)) {
        throw new RuntimeException('Expected the lifecycle suite disposable database.');
    }
    $checks=0;$check=static function(bool $ok,string $why)use(&$checks):void {
        if(!$ok)throw new RuntimeException($why);$checks++;
    };
    $records=array_map(static fn($item)=>desk_get($item['record']),$initial);
    $managedIds=array_map(static fn($record)=>(int)$record['post_id'],$records);
    $check(count($managedIds)===16,'All existing opportunity fixtures are covered.');
    $before=json_encode($records,JSON_THROW_ON_ERROR);
    $mail=(int)$pdo->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn();
    $jobs=(int)$pdo->query('SELECT COUNT(*) FROM publishing_jobs')->fetchColumn();
    $baseline=public_post_count();
    $category=(int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $marker='editorialvisibility'.bin2hex(random_bytes(6));$controls=[];
    foreach(['First','Second']as$title){
        $input=['title'=>$marker.' '.$title,'category_id'=>$category,'standfirst'=>'An ordinary personal essay.',
            'content_html'=>'<p>A personal reflection on enterprise and learning.</p>','is_selected'=>'1',
            'article_groups_present'=>'1','article_groups'=>['personal-experiences','ideas-perspectives']];
        $saved=writer_save_post($input,$uid,true);$controls[]=(int)$saved['id'];
    }
    $private=writer_save_post(['title'=>$marker.' private','category_id'=>$category,'content_html'=>'<p>Private editorial draft.</p>'],$uid,false);
    $future=writer_save_post(['title'=>$marker.' future','category_id'=>$category,'content_html'=>'<p>Scheduled editorial essay.</p>',
        'published_at'=>(new DateTimeImmutable('+10 days'))->format('Y-m-d\TH:i')],$uid,true);
    // Deliberately give fixture opportunities article metadata to prove the binding,
    // not categories or selection flags, determines editorial visibility.
    $pdo->exec("UPDATE posts SET is_selected=1,article_groups_json='[\"personal-experiences\",\"ideas-perspectives\"]' WHERE id IN (".implode(',',$managedIds).')');
    $check(public_post_count()===$baseline+2,'Editorial counts exclude every managed listing.');
    foreach([latest_posts(5000),selected_posts(12)]as$items){
        $ids=array_map('intval',array_column($items,'id'));
        $check(!array_intersect($ids,$managedIds),'Public editorial lists contain no opportunity records.');
        $check(!in_array((int)$private['id'],$ids,true)&&!in_array((int)$future['id'],$ids,true),'Draft and future essays remain private.');
        foreach($controls as$id)$check(in_array($id,$ids,true),'Ordinary published essays remain visible.');
    }
    $check(count(latest_posts(1,null,0))===1&&count(latest_posts(1,null,1))===1,'Filtering happens before pagination.');
    $ordinary=writer_post_by_id($controls[0]);
    $related=array_map('intval',array_column(related_posts($ordinary,6),'id'));
    $check(!array_intersect($related,$managedIds)&&in_array($controls[1],$related,true),'Related articles stay editorial.');
    $check(post_by_slug($ordinary['slug'])!==null,'Canonical editorial article remains readable.');
    foreach($records as$record){
        $check(post_by_slug($record['slug'])===null,'A managed record cannot render as a My Thoughts essay.');
        $stored=desk_get($record['id']);
        $check($stored['public']===$record['public'],'Opportunity public snapshot is preserved.');
        $check(str_starts_with(desk_path($stored),'/opportunities/'),'Opportunity canonical path is unchanged.');
    }
    foreach(['personal-experiences','ideas-perspectives']as$group){
        $page=article_group_archive($group,['q'=>'','topic'=>''],1);
        $ids=array_map('intval',array_column($page['posts'],'id'));
        $check(!array_intersect($ids,$managedIds),'Reading groups exclude opportunities even with matching metadata.');
        foreach($controls as$id)$check(in_array($id,$ids,true),'Reading groups retain real essays.');
    }
    $documents=iterator_to_array(discovery_public_documents(),false);
    $documentIds=array_column($documents,'id');
    foreach($managedIds as$id)$check(!in_array('post:'.$id,$documentIds,true),'Editorial discovery does not misclassify opportunities.');
    $search=discovery_search($marker,1,'post');
    $check($search['pagination']['total']===2,'Editorial search includes only the two public essays.');
    $writerIds=array_map('intval',array_column(writer_posts(500),'id'));
    foreach($managedIds as$id)$check(in_array($id,$writerIds,true),'Internal Writer access to opportunity records remains intact.');
    $q=$pdo->prepare('SELECT COUNT(*) FROM posts p WHERE p.id=? AND '.published_where());$q->execute([$managedIds[0]]);
    $check((int)$q->fetchColumn()===1,'General publication checks remain available to the Publishing Desk.');
    $inventory=['added'=>0,'updated'=>0,'deleted'=>0,'unchanged'=>0];
    $oldAlias=url('/my-thoughts/'.$records[0]['slug']);
    discovery_reconcile_url($oldAlias,'post:'.$managedIds[0],hash('sha256','old-editorial-projection'),bin2hex(random_bytes(16)),$inventory);
    discovery_sync_public_urls();
    $q=$pdo->prepare('SELECT is_public FROM discovery_public_urls WHERE url_hash=?');$q->execute([hash('sha256',$oldAlias)]);
    $check((int)$q->fetchColumn()===0,'Old editorial discovery aliases are retired without deleting opportunity pages.');
    $check(json_encode(array_map(static fn($item)=>desk_get($item['record']),$initial),JSON_THROW_ON_ERROR)===$before,'No opportunity records are rewritten by visibility queries.');
    $check((int)$pdo->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn()===$mail&&
        (int)$pdo->query('SELECT COUNT(*) FROM publishing_jobs')->fetchColumn()===$jobs,'No mail or social sends are created.');
    try{editorial_post_sql('p; DELETE FROM posts');throw new RuntimeException('Unsafe SQL alias accepted.');}
    catch(InvalidArgumentException){$checks++;}
    echo 'PASS: '.$checks." editorial separation and preservation assertions.\n";
    return $checks;
}
