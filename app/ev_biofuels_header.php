<?php
declare(strict_types=1);
/** The exact generated illustration selected by the owner in the conversation. */
function evb_header_spec(): array {
    return [
        'path'=>'/assets/images/articles/ev-biofuels-approved-20261104.jpg',
        'sha256'=>'b66997140fb97b2431ade46771cab6e97c187d69ab55d71312382824e2fc9a57',
        'alt'=>'Illustration of an electric vehicle charging beside a road, with buses, fuel infrastructure and oilseed crops.',
        'caption'=>'AI-generated illustration of electric mobility, fuel infrastructure and biofuel crops; not a photograph of the projects discussed.'
    ];
}
function evb_header_research_note(): string {
    return 'Research cut-off: 4 November 2026. Sources are linked beside the relevant claims. The Namibia figures describe proposed infrastructure reported on the launch date; the Turkana pipeline is a reported proposal. Forecasts are distinguished from measured results. This article updates an earlier 2023 draft.';
}
/** Remove only the exact trailing note the owner rejected, preserving other bytes. */
function evb_header_clean_html(string $html): string {
    $pattern='~(?:<hr\s*/?>\s*)?<p>\s*<em>'.preg_quote(evb_header_research_note(),'~').'</em>\s*</p>\s*\z~u';
    $clean=preg_replace($pattern,'',$html,1);
    if(!is_string($clean))throw new RuntimeException('The article note could not be checked.');
    return $clean;
}
/** A single, explicitly authorised edit, not recurring enforcement over Writer changes. */
function evb_header_apply(PDO $pdo, int $owner): bool {
    $spec=evb_header_spec();
    $image=dirname(__DIR__).$spec['path'];
    if(!is_file($image)||!hash_equals($spec['sha256'],(string)hash_file('sha256',$image)))throw new RuntimeException('The approved header asset is missing or changed.');
    $size=getimagesize($image);
    if(!$size||$size[0]!==1672||$size[1]!==941||$size[2]!==IMAGETYPE_JPEG)throw new RuntimeException('The approved header image dimensions are invalid.');
    $marker='editorial.ev_biofuels_header.20261104';
    $lock='waithaka-ev-biofuels-header-20261104';
    $q=$pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lock]);
    if((int)$q->fetchColumn()!==1)throw new RuntimeException('The article presentation update is busy.');
    try {
        $pdo->beginTransaction();
        $q=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? FOR UPDATE');$q->execute([$marker]);
        if($q->fetchColumn()!==false){$pdo->commit();return false;}
        $q=$pdo->prepare('SELECT id,user_id,title,standfirst,content_html,featured_image,featured_image_alt,featured_image_caption FROM posts WHERE slug=? FOR UPDATE');
        $q->execute(['electric-vehicles-biofuels-africa']);$post=$q->fetch(PDO::FETCH_ASSOC);
        if(!$post||($owner>0&&(int)$post['user_id']!==$owner))throw new RuntimeException('The owner article was not found.');
        $before=(string)$post['content_html'];$after=evb_header_clean_html($before);
        if(str_contains($after,evb_header_research_note()))throw new RuntimeException('The flagged note was edited or moved; no content was replaced.');
        $revision=$pdo->prepare('INSERT INTO post_revisions(post_id,title,standfirst,content_html) VALUES(?,?,?,?)');
        $revision->execute([(int)$post['id'],$post['title'],$post['standfirst'],$before]);$revisionId=(int)$pdo->lastInsertId();
        // No title, slug, publication date, status, category or other article is changed.
        $q=$pdo->prepare('UPDATE posts SET content_html=?,featured_image=?,featured_image_alt=?,featured_image_caption=? WHERE id=?');
        $q->execute([$after,$spec['path'],$spec['alt'],$spec['caption'],(int)$post['id']]);
        $proof=json_encode(['post_id'=>(int)$post['id'],'revision_id'=>$revisionId,'applied_at'=>gmdate('c'),'previous_image'=>$post['featured_image'],'previous_alt'=>$post['featured_image_alt'],'previous_caption'=>$post['featured_image_caption'],'before_sha256'=>hash('sha256',$before),'after_sha256'=>hash('sha256',$after),'asset_sha256'=>$spec['sha256']],JSON_THROW_ON_ERROR);
        $pdo->prepare('INSERT INTO settings(setting_key,setting_value,is_secret) VALUES(?,?,0)')->execute([$marker,$proof]);
        $pdo->commit();return true;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    finally{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
}
