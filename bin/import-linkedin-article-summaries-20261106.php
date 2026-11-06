<?php
declare(strict_types=1);

/**
 * Import the owner-approved 6 November LinkedIn article summaries as private
 * Writer publication drafts. This is deliberately draft-only: provider
 * approval, destination verification and publication receipts remain separate.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/publishing.php';

$path=dirname(__DIR__).'/app/content/linkedin-article-summaries-20261106.json';
$data=json_decode((string)file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
if(($data['release']??'')!=='linkedin-article-summaries-20261106')throw new RuntimeException('Unexpected LinkedIn release payload.');
if(($data['status']??'')!=='prepared_not_provider_published')throw new RuntimeException('LinkedIn release must remain provider-unpublished.');

$owner=(int)(comm_config()['owner']??0);
if($owner<1)throw new RuntimeException('Configured website owner required.');

$lock='waithaka-linkedin-article-summaries-20261106';
$pdo=db();
$q=$pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lock]);
if((int)$q->fetchColumn()!==1)throw new RuntimeException('LinkedIn draft import is busy.');

$created=0;$preserved=0;
try{
  foreach($data['posts']??[] as$postSpec){
    $slug=(string)($postSpec['slug']??'');
    $title=(string)($postSpec['title']??'');
    $url=(string)($postSpec['url']??'');
    $text=(string)($postSpec['linkedin']??'');
    if($slug===''||$title===''||$url===''||$text==='')throw new RuntimeException('Incomplete LinkedIn post specification.');
    if($url!=='https://www.waithakandegwa.com/my-thoughts/'.$slug)throw new RuntimeException('Unexpected canonical URL for '.$slug);
    if(mb_strlen($text)>3000)throw new RuntimeException('LinkedIn post exceeds 3,000 characters for '.$slug);

    $st=$pdo->prepare("SELECT id,title,slug,standfirst FROM posts WHERE slug=? AND status='published' LIMIT 1");
    $st->execute([$slug]);$article=$st->fetch(PDO::FETCH_ASSOC);
    if(!$article)throw new RuntimeException('Published article not found for '.$slug);

    $packageTitle='LinkedIn — '.$title;
    $exists=$pdo->prepare('SELECT id FROM publishing_packages WHERE source_post_id=? AND title=? ORDER BY id LIMIT 1');
    $exists->execute([(int)$article['id'],$packageTitle]);
    if($exists->fetchColumn()){$preserved++;continue;}

    $draft=publishing_default_draft($article);
    $draft['title']=$packageTitle;
    $draft['source_url']=$url;
    $draft['notes']='Recovered 6 November 2026 owner-approved LinkedIn summary. Draft only; verify the personal LinkedIn destination before approval/publication.';
    $draft['variants']['linkedin']['enabled']=true;
    $draft['variants']['linkedin']['text']=$text;
    $draft['variants']['linkedin']['format']='post';
    $draft['variants']['linkedin']['audience']='public';

    publishing_save_package($draft,$owner);
    $created++;
  }
  fwrite(STDOUT,"LinkedIn Writer drafts: created {$created}, preserved {$preserved}. No provider publication attempted.\n");
}finally{
  $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
}
