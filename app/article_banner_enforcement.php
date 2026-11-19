<?php
declare(strict_types=1);

require_once __DIR__.'/article_banner_policy.php';

/**
 * Publication-time guard. The generated policy banner needs no database image.
 * A logo-only value is cleared so it cannot leak into a card, feed or preview.
 */
function writer_after_publish(int $postId,array $fields): void {
    $source=trim((string)($fields['featured_image']??''));
    if($source!==''&&abp_is_logo_only($source)){
        db()->prepare("UPDATE posts SET featured_image='',featured_image_alt='',featured_image_caption='',updated_at=NOW() WHERE id=?")
            ->execute([$postId]);
        return;
    }
    if($source!==''){
        $alt=trim((string)($fields['featured_image_alt']??''));
        if(mb_strlen($alt,'UTF-8')<12)throw new InvalidArgumentException('Describe the featured image clearly before publishing.');
        if(abp_is_legacy_logo($source))throw new InvalidArgumentException('The former logo cannot be used in article imagery. Use the current approved identity only inside a composed banner.');
    }
}
