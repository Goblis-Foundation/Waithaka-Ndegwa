<?php
declare(strict_types=1);

/**
 * Publish the first verified weekly social-enterprise research article once.
 * The release is idempotent: an existing slug is preserved and all later edits
 * remain owned by Writer. It does not queue social posts or subscriber email.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';

$slug='from-impact-evidence-to-economic-demand';
$lock='waithaka-weekly-social-enterprise-20261106';
$pdo=db();
$q=$pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lock]);
if((int)$q->fetchColumn()!==1)throw new RuntimeException('Weekly article release is busy.');

try{
    $pdo->beginTransaction();
    $exists=$pdo->prepare('SELECT id,status FROM posts WHERE slug=? FOR UPDATE');
    $exists->execute([$slug]);
    if($exists->fetch(PDO::FETCH_ASSOC)){
        $pdo->commit();
        fwrite(STDOUT,"Existing weekly article preserved; Writer owns all future revisions.\n");
        exit(0);
    }

    $owner=(int)(comm_config()['owner']??0);
    if($owner<1)throw new RuntimeException('Configured website owner required.');
    $find=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1');
    $find->execute([$owner]);
    if(!$find->fetchColumn())throw new RuntimeException('Active website owner required.');

    $category=$pdo->prepare('SELECT id FROM categories WHERE slug=?');
    $category->execute(['social-entrepreneurship']);
    $categoryId=$category->fetchColumn();
    if(!$categoryId)throw new RuntimeException('Social Entrepreneurship category is missing.');

    $file=dirname(__DIR__).'/app/content/from-impact-evidence-to-economic-demand.html';
    $html=file_get_contents($file);
    if(!is_string($html)||strlen($html)<8000)throw new RuntimeException('Weekly article source is missing or incomplete.');
    foreach([
        'https://www.weforum.org/publications/the-impact-economy-turning-disclosure-into-demand',
        'https://socialfinance.org/news/social-finance-impact-first-fund-announces-two-new-investments-advancing-economic-opportunity-for-people-with-disabilities-and-americas-farmers/',
        'https://socialinnovationexchange.org/'
    ]as$source)if(!str_contains($html,$source))throw new RuntimeException('Required reviewed source is missing from article.');
    if(preg_match('/\[(?:VERIFY|ILLUSTRATIVE)\]/i',$html))throw new RuntimeException('Unresolved verification marker in article.');

    $body=sanitize_html($html);
    if(mb_strlen(plain_text($body))<6500)throw new RuntimeException('Sanitized weekly article is unexpectedly short.');

    $title='From Impact Evidence to Economic Demand: What This Week’s Signals Mean for African Social Entrepreneurs';
    $standfirst='Measurement is necessary, but the next test is whether verified social and environmental value changes purchasing, investment, policy and partnership decisions.';
    $summary='Current signals from impact-first finance, Wayfinder Nairobi and the impact-economy debate point to a practical challenge for African social enterprises: connect evidence to real economic demand.';
    $groups=json_encode(['ideas-perspectives'],JSON_THROW_ON_ERROR);
    $insert=$pdo->prepare(
        "INSERT INTO posts (user_id,category_id,title,slug,standfirst,content_html,featured_image,featured_image_alt,featured_image_caption,series_name,seo_title,seo_description,status,is_selected,published_at,article_groups_json)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'published',0,NOW(),?)"
    );
    $insert->execute([
        $owner,(int)$categoryId,$title,$slug,$standfirst,$body,
        '/assets/images/journey/conversation.webp','Conversation and exchange of ideas.',
        'Dialogue, evidence and the practical work of turning ideas into impact.',
        'Social Entrepreneurship Weekly',
        'From Impact Evidence to Economic Demand | Waithaka Ndegwa',$summary,$groups
    ]);
    $postId=(int)$pdo->lastInsertId();
    assign_tags($postId,['Social Entrepreneurship','Impact Economy','Impact Investing','Social Innovation','Africa','Kenya','Enterprise Finance','Evidence']);
    $pdo->commit();
    if(function_exists('editorial_sync_discovery'))editorial_sync_discovery();
    fwrite(STDOUT,"Published weekly social-enterprise article with id {$postId} and canonical slug {$slug}.\n");
}catch(Throwable $error){
    if($pdo->inTransaction())$pdo->rollBack();
    throw $error;
}finally{
    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
}
