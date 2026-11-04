<?php
declare(strict_types=1);

/** Presentation only: the saved Writer article remains the content source. */
function inaugural_story_parts(array $post, array $toc): ?array {
    if (($post['slug'] ?? '') !== 'impact-driven-inspiration') return null;
    $html = (string)($toc['html'] ?? '');
    if (!preg_match_all('~<h2\b[^>]*>.*?</h2>~is', $html, $matches, PREG_OFFSET_CAPTURE)) return null;
    $chapters = [];
    $photos = [
        'The people who opened doors' => [
            ['file'=>'early-learning.webp', 'alt'=>'An early photograph of a student examining a piece of apparatus at a school desk.', 'caption'=>'An early moment of learning.']
        ],
        'Why social entrepreneurship matters to me' => [
            ['file'=>'farm-cassava.webp', 'alt'=>'Holding freshly harvested cassava, with farm tools and green fields in the background.', 'caption'=>'Learning through practical work on the farm.']
        ],
        'Why I am writing here' => [
            ['file'=>'travel-open-bus.webp', 'alt'=>'Travelling on an open-top bus with a camera.', 'caption'=>'New places, different perspectives.'],
            ['file'=>'skydiving.webp', 'alt'=>'A tandem skydive above a patchwork of fields.', 'caption'=>'A moment of adventure.']
        ]
    ];
    foreach ($matches[0] as $index => [$heading, $offset]) {
        $next = $matches[0][$index + 1][1] ?? strlen($html);
        $label = trim(html_entity_decode(strip_tags($heading), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (!preg_match('~\bid\s*=\s*([\x22\x27])(.*?)\1~is', $heading, $anchor) || $anchor[2] === '') return null;
        $chapters[] = [
            'id'=>html_entity_decode($anchor[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'label'=>$label,
            'html'=>substr($html, $offset, $next - $offset),
            'photos'=>$photos[$label] ?? []
        ];
    }
    return ['intro'=>substr($html, 0, $matches[0][0][1]), 'chapters'=>$chapters];
}
