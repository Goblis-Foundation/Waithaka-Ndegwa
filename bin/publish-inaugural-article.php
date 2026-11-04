<?php
declare(strict_types=1);

/**
 * Publish the owner-supplied inaugural essay once, using the existing posts
 * table and publication workflow. Never overwrite, republish or reset an
 * already-existing article: subsequent editorial changes belong in Writer.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';

$slug='impact-driven-inspiration';
$lock='waithaka-inaugural-impact-driven-inspiration';
$pdo=db();
$lockStatement=$pdo->prepare('SELECT GET_LOCK(?, 10)');
$lockStatement->execute([$lock]);
if ((int)$lockStatement->fetchColumn() !== 1) throw new RuntimeException('Inaugural article publishing is busy.');

try {
    $pdo->beginTransaction();
    $exists=$pdo->prepare('SELECT id,status FROM posts WHERE slug=? FOR UPDATE');
    $exists->execute([$slug]);
    $prior=$exists->fetch(PDO::FETCH_ASSOC);
    if ($prior) {
        $pdo->commit();
        fwrite(STDOUT, "Existing inaugural article preserved; Writer owns all future revisions.\n");
        exit(0);
    }

    // Do not silently select a secondary Writer or another account's identity.
    $owner=(int)(comm_config()['owner']??0);
    $writer=null;
    if ($owner>0) {
        $find=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1');
        $find->execute([$owner]);
        $writer=$find->fetchColumn();
    } else {
        $users=$pdo->query('SELECT id FROM users WHERE is_active=1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
        if (count($users)===1) $writer=$users[0];
    }
    if (!$writer) throw new RuntimeException('A unique active website owner must be configured before publishing the inaugural article.');

    $category=$pdo->prepare('SELECT id FROM categories WHERE slug=?');
    $category->execute(['social-entrepreneurship']);
    $categoryId=$category->fetchColumn();
    if (!$categoryId) throw new RuntimeException('The Social Entrepreneurship category is missing.');

    $file=dirname(__DIR__).'/app/content/impact-driven-inspiration.html';
    $html=file_get_contents($file);
    if (!is_string($html)||strlen($html)<4000) throw new RuntimeException('Inaugural article source is missing or incomplete.');
    $body=sanitize_html($html);
    if (mb_strlen(plain_text($body))<3500) throw new RuntimeException('The sanitized article is unexpectedly short.');
    $title='Impact-Driven Inspiration: My Journey, Purpose and an Invitation to Connect';
    $standfirst='From Endarasha in Nyeri County to the work of building social enterprises, this is where my story begins—and why I invite you to learn, question and create meaningful impact with me.';
    $summary='Waithaka Ndegwa shares his story from Endarasha, Nyeri County: faith, education, enterprise setbacks, social entrepreneurship and a personal invitation to connect.';
    $groups=json_encode(['personal-experiences','ideas-perspectives'],JSON_THROW_ON_ERROR);
    $insert=$pdo->prepare(
        "INSERT INTO posts (user_id,category_id,title,slug,standfirst,content_html,featured_image,featured_image_alt,featured_image_caption,series_name,seo_title,seo_description,status,is_selected,published_at,article_groups_json)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'published',1,NOW(),?)"
    );
    $insert->execute([
        (int)$writer,(int)$categoryId,$title,$slug,$standfirst,$body,
        '/assets/images/journey/speaking.webp','Waithaka Ndegwa speaking at a community event',
        'Waithaka Ndegwa — experiences, perspective and purpose.',
        'The Beginning','Impact-Driven Inspiration: My Journey and Purpose',$summary,$groups
    ]);
    $postId=(int)$pdo->lastInsertId();
    assign_tags($postId,['Waithaka Ndegwa','Personal Journey','Social Entrepreneurship','Nyeri','Endarasha','Purpose','Community','Innovation','Kenya']);
    if (function_exists('discovery_sync_public_urls')) discovery_sync_public_urls();
    $pdo->commit();
    fwrite(STDOUT, "Published inaugural article with id {$postId} and canonical slug {$slug}.\n");
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
} finally {
    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
}
