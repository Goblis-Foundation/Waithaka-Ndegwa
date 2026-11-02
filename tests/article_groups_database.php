<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||getenv('WK_TEST_ALLOW_WRITES')!=='1'||!in_array(getenv('DB_HOST'),['127.0.0.1','::1'],true)||!preg_match('/^waithaka_test_[a-z0-9_]+_test$/D',(string)getenv('DB_DATABASE')))exit(2);
require dirname(__DIR__).'/app/bootstrap.php';restore_exception_handler();
require dirname(__DIR__).'/app/migrations.php';require dirname(__DIR__).'/app/article_groups_migration.php';
$checks=0;function agb(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;}
function ag_archive(string $key,array $filters=[],int $page=1): ?array {return article_group_archive($key,array_replace(['q'=>'','topic'=>''],$filters),$page);}
function ag_total(string $key,array $filters=[]): int {return ag_archive($key,$filters)['pagination']['total'];}
// Rehearse an existing installation with no grouping column. Only this disposable database is modified.
db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Legacy synthetic owner','legacy-groups@example.invalid','unused']);$legacyActor=(int)db()->lastInsertId();
db()->prepare("INSERT INTO posts(user_id,title,slug,content_html,writer_note,status) VALUES(?,?,?,?,?,'draft')")->execute([$legacyActor,'Legacy private draft','legacy-private-groups','<p>PRIVATE_LEGACY_BODY</p>','PRIVATE_LEGACY_NOTE']);$legacyPost=(int)db()->lastInsertId();
db()->exec('ALTER TABLE posts DROP COLUMN article_groups_json');db()->exec("DELETE FROM schema_migrations WHERE name='210_article_groups.sql'");
$before=(int)db()->query('SELECT COUNT(*) FROM posts')->fetchColumn();article_groups_apply_migration(db());article_groups_apply_migration(db());
$legacy=db()->query('SELECT article_groups_json,content_html,writer_note,status FROM posts WHERE id='.$legacyPost)->fetch();
agb($legacy['article_groups_json']===null&&$legacy['content_html']==='<p>PRIVATE_LEGACY_BODY</p>'&&$legacy['writer_note']==='PRIVATE_LEGACY_NOTE'&&$legacy['status']==='draft','An additive upgrade preserves private content and leaves existing classification unset.');
agb(article_groups_migration_verified(db())&&(int)db()->query('SELECT COUNT(*) FROM posts')->fetchColumn()===$before,'Migration is repeatable and preserves articles.');
db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Synthetic group owner','groups@example.invalid','unused']);$actor=(int)db()->lastInsertId();comm_put('config',['owner'=>$actor]);
$topic=(int)db()->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();$slug=(string)db()->query('SELECT slug FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$input=['title'=>'Reviewed enterprise lesson','category_id'=>$topic,'content_html'=>'<p>Evidence from a synthetic fixture only.</p>','published_at'=>date('Y-m-d\TH:i',time()-3600),'article_groups_present'=>'1','article_groups'=>['personal-experiences']];
$draft=writer_save_post($input,$actor,false);$postId=$draft['id'];$input+=['id'=>$postId,'draft_version'=>$draft['version']];
agb(ag_total('personal-experiences')===0,'Private article is absent from group archives.');
$published=writer_save_post($input,$actor,true);$url=writer_post_by_id($postId)['slug'];
agb(ag_total('personal-experiences')===1&&ag_total('ideas-perspectives')===0,'Only the reviewed public grouping appears.');
$input['draft_version']=$published['version'];$input['article_groups']=['ideas-perspectives'];$input['title']='UNPUBLISHED_GROUP_TITLE';$saved=writer_save_post($input,$actor,false);
agb(ag_total('personal-experiences')===1&&ag_total('ideas-perspectives')===0&&ag_total('personal-experiences',['q'=>'UNPUBLISHED_GROUP_TITLE'])===0,'Draft regrouping and titles cannot change public search.');
$input['draft_version']=$saved['version'];$input['title']='Reviewed enterprise lesson';$saved=writer_save_post($input,$actor,true);
agb(ag_total('personal-experiences')===0&&ag_total('ideas-perspectives')===1&&writer_post_by_id($postId)['slug']===$url,'Explicit publication moves groups but preserves the article URL.');
$future=writer_save_post(array_replace($input,['id'=>0,'title'=>'Future fixture','published_at'=>date('Y-m-d\TH:i',time()+86400),'article_groups'=>['personal-experiences']]),$actor,true);
agb(ag_total('personal-experiences')===0,'Future scheduled articles remain hidden.');
$profile=['name'=>'Synthetic enterprise','sector'=>'Fixture only','summary'=>'Reviewed synthetic profile','problem'=>'Fixture question','solution'=>'Fixture test','involvement'=>'Synthetic involvement','country'=>'Kenya','private_notes'=>'PRIVATE_PROFILE_NOTE','consent_reference'=>'PRIVATE_PERMISSION','post_ids'=>[$postId,$future['id'],$draft['id']],'version'=>0,'action'=>'draft'];
$enterprise=enterprise_save($actor,null,$profile);agb(ag_total('my-enterprise-journey')===0,'A private profile cannot expose its associations.');
enterprise_save($actor,$enterprise,array_replace($profile,['version'=>1,'action'=>'publish','reviewed'=>'1','consent_confirmed'=>'1']));
agb(ag_total('my-enterprise-journey')===1&&count(enterprise_articles([$postId,$future['id']]))===1,'Public enterprise links include only due public articles.');
$context=article_public_context(post_by_slug($url));agb(count($context['enterprises'])===1&&!str_contains(json_encode($context),'PRIVATE_'),'Article context contains only the approved public profile.');
enterprise_save($actor,$enterprise,array_replace($profile,['version'=>2,'action'=>'unpublish']));agb(ag_total('my-enterprise-journey')===0,'Unpublishing removes automatic membership and enterprise context.');
$input['draft_version']=$saved['version'];$input['article_groups']=['personal-experiences','my-enterprise-journey'];writer_save_post($input,$actor,true);
agb(ag_total('personal-experiences')===1&&ag_total('my-enterprise-journey')===1,'Explicit cross-listing preserves one article in each archive.');
$insert=db()->prepare("INSERT INTO posts(user_id,category_id,title,slug,content_html,status,published_at,article_groups_json) VALUES(?,?,?,?,?,'published',?,?)");
for($i=0;$i<25;$i++)$insert->execute([$actor,$topic,'Fixture page '.$i,'group-page-'.$i,'<p>Public fixture only.</p>',date('Y-m-d H:i:s',time()-7200),'["personal-experiences"]']);
$page2=ag_archive('personal-experiences',[],2);agb(count($page2['posts'])===2&&$page2['pagination']['total']===26&&ag_archive('personal-experiences',[],3)===null,'Group pagination spans the full archive and rejects pages beyond it.');
agb(ag_total('personal-experiences',['q'=>'Fixture page 24','topic'=>$slug])===1,'Search and topic filters apply across pages.');
agb(ag_total('personal-experiences',['q'=>'%'])===0&&ag_total('personal-experiences',['q'=>"' OR 1=1 --"])===0,'Search treats wildcards and SQL fragments as literal text.');
$insert->execute([$actor,$topic,'Legacy unclassified writing','legacy-group-fixture','<p>Reviewed legacy article.</p>',date('Y-m-d H:i:s',time()-7200),null]);
agb(ag_total('ideas-perspectives')===1,'Legacy unclassified articles remain in Ideas & Perspectives.');
// A general approval is insufficient: the original final-copy guard must still exclude this record everywhere.
db()->prepare("INSERT INTO founder_submissions(reference,submission_type,submitter_name,submitter_email,founder_name,venture_name,country,story_summary,metric_basis,privacy_version,privacy_accepted_at,ip_hash,associated_post_id,review_status,is_public,permission_confirmed,claims_reviewed,consent_reference,created_at,updated_at) VALUES('AG-UNAPPROVED','founder','Synthetic founder','founder@example.invalid','Synthetic founder','Fixture venture','Kenya','PRIVATE_INTAKE','not_applicable','fixture',NOW(),?,?,'approved',1,1,1,'PRIVATE_PERMISSION',NOW(),NOW())")->execute([str_repeat('0',64),$postId]);
agb(ag_total('my-enterprise-journey')===0&&ag_total('personal-experiences')===25&&enterprise_articles([$postId])===[],'Missing founder final-copy approval cannot be bypassed through groups or enterprise articles.');
db()->prepare("UPDATE posts SET status='archived' WHERE slug='legacy-group-fixture'")->execute();agb(ag_total('ideas-perspectives')===0,'Archived articles leave the grouped collection.');
echo "PASS: $checks database grouping, draft/public isolation, enterprise linkage, filtering, pagination and migration checks.\n";
