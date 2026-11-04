<?php
/* This partial receives the existing published post and sanitized editorial TOC. */
$storyTitle = explode(':', (string)$post['title'], 2);
$storyHero = asset('images/journey/conversation.webp');
$storyHeroAlt = 'A moment in conversation, with hands raised while making a point.';
?>
<link rel="stylesheet" href="<?=e(asset('css/inaugural-story.css'))?>">
<header class="story-header container" id="story-top">
  <div class="story-header-copy">
    <div class="story-kicker"><a href="<?=e(url('/my-thoughts'))?>">My Thoughts</a><span aria-hidden="true"> / </span><span>A personal journey</span></div>
    <h1><span class="story-title-main"><?=e($storyTitle[0])?></span><?php if(isset($storyTitle[1])):?><span class="story-title-rest">: <?=e(trim($storyTitle[1]))?></span><?php endif;?></h1>
    <?php if(!empty($post['standfirst'])):?><p class="story-standfirst"><?=e($post['standfirst'])?></p><?php endif;?>
    <div class="story-byline"><a href="<?=e(url('/about'))?>" rel="author">Waithaka Ndegwa</a><span><time datetime="<?=e(date('c',strtotime($post['published_at'])))?>"><?=e(format_date($post['published_at']))?></time><span aria-hidden="true"> · </span><?=reading_time($post['content_html'])?> min read</span></div>
    <div class="story-header-actions"><a class="button button-primary" href="#story-beginning">Begin the story <span aria-hidden="true">↓</span></a><a class="story-text-link" href="#story-chapters">Explore the chapters <span aria-hidden="true">↓</span></a></div>
  </div>
  <figure class="story-photo story-portrait article-featured article-featured-uncropped">
    <a href="<?=e($storyHero)?>" target="_blank" rel="noopener noreferrer" aria-label="Open the conversation photograph at full size in a new tab"><img <?=responsive_image_attrs($storyHero, '(max-width: 900px) calc(100vw - 56px), 400px')?> alt="<?=e($storyHeroAlt)?>" fetchpriority="high" decoding="async"></a>
    <figcaption><span>In conversation.</span><a href="<?=e($storyHero)?>" target="_blank" rel="noopener noreferrer">View full photo ↗</a></figcaption>
  </figure>
</header>
<div class="story-reading-layout container">
  <nav class="story-index" id="story-chapters" aria-label="Article chapters" data-page-outline>
    <details class="story-index-details" open><summary>In this story <span><?=count($storyParts['chapters'])?> chapters</span></summary>
      <ol><?php foreach($storyParts['chapters'] as $index=>$chapter):?><li><a href="#<?=e($chapter['id'])?>" data-story-link><span class="story-index-number" aria-hidden="true"><?=sprintf('%02d',$index+1)?></span><span><?=e($chapter['label'])?></span></a></li><?php endforeach;?></ol>
      <a class="story-back-link" href="<?=e(url('/my-thoughts'))?>">← All My Thoughts</a>
    </details>
  </nav>
  <div class="story-prose article-body">
    <div class="story-introduction" id="story-beginning" tabindex="-1"><?=$storyParts['intro']?></div>
    <?php $video=video_embed($post['video_url']??'');if($video&&isset($video['embed'])):?><div class="video-wrap"><iframe src="<?=e($video['embed'])?>" title="Video accompanying <?=e($post['title'])?>" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen loading="lazy"></iframe></div><?php elseif($video&&isset($video['url'])):?><p><a href="<?=e($video['url'])?>" target="_blank" rel="noopener noreferrer">Watch accompanying video ↗</a></p><?php endif;?>
    <?php foreach($storyParts['chapters'] as $index=>$chapter):?>
      <section class="story-chapter" aria-labelledby="<?=e($chapter['id'])?>" data-story-chapter>
        <span class="story-chapter-number" aria-hidden="true"><?=sprintf('%02d',$index+1)?> /</span>
        <div class="story-chapter-copy"><?=$chapter['html']?></div>
        <?php if($chapter['photos']):?><div class="story-photo-grid<?=count($chapter['photos'])>1?' story-photo-pair':''?>">
          <?php foreach($chapter['photos'] as $photo):$photoUrl=asset('images/journey/'.$photo['file']);?>
          <figure class="story-photo">
            <a href="<?=e($photoUrl)?>" target="_blank" rel="noopener noreferrer" aria-label="<?=e('Open photograph: '.$photo['caption'].' (new tab)')?>"><img <?=responsive_image_attrs($photoUrl, count($chapter['photos'])>1?'(max-width: 600px) calc(100vw - 56px), (max-width: 900px) 42vw, 350px':'(max-width: 900px) calc(100vw - 56px), 700px')?> alt="<?=e($photo['alt'])?>" loading="lazy" decoding="async"></a>
            <figcaption><span><?=e($photo['caption'])?></span><a href="<?=e($photoUrl)?>" target="_blank" rel="noopener noreferrer">View full photo ↗</a></figcaption>
          </figure>
          <?php endforeach;?>
        </div><?php endif;?>
      </section>
    <?php endforeach;?>
    <div class="story-end-nav"><a href="#story-top">↑ Back to the beginning</a><a href="<?=e(url('/my-thoughts'))?>">Explore My Thoughts →</a></div>
  </div>
</div>
<script src="<?=e(asset('js/inaugural-story.js'))?>" defer></script>
