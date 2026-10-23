<section class="home-hero">
  <div class="container home-hero-grid">
    <div class="home-hero-copy" data-reveal>
      <span class="eyebrow">On the frontlines of social change in Africa</span>
      <h1>Waithaka<br>Ndegwa</h1>
      <p class="hero-lead">On the frontlines of social change in Africa, building practical solutions to some of the world’s most pressing social, economic, and environmental challenges.</p>
      <p class="hero-note">I am a social entrepreneur, innovation strategist, and ecosystem builder turning ideas, talent, and real-world challenges into sustainable enterprises and measurable impact.</p>
      <div class="button-row"><a class="button button-primary" href="<?=url('/my-thoughts')?>">Read My Thoughts</a><a class="button button-secondary" href="#goals-2030">My 2030 goals</a></div>
    </div>
    <div class="home-hero-media" data-reveal>
      <figure class="hero-main-image"><img <?=responsive_image_attrs(asset('images/journey/speaking.webp'))?> alt="Waithaka Ndegwa speaking at an event" fetchpriority="high" decoding="async"></figure>
      <figure class="hero-secondary-image"><img <?=responsive_image_attrs(asset('images/journey/farm-cassava.webp'))?> alt="Working with harvested cassava on a farm" loading="eager" decoding="async"></figure>
      <div class="hero-location"><span>Roots</span><strong>Charity, Endarasha · Nyeri, Kenya</strong><small>With Mount Kenya shaping the horizon — and many of the questions.</small></div>
    </div>
  </div>
</section>

<?php if(!empty($latest)): $featured=$latest[0];?>
<section class="section surface">
  <div class="container">
    <div class="section-heading" data-reveal><div><span class="eyebrow">Latest thought</span><h2>What I am thinking about now.</h2></div><a class="text-link" href="<?=url('/my-thoughts')?>">Browse all thoughts →</a></div>
    <article class="featured-thought" data-reveal>
      <div class="featured-thought-media"><img <?=responsive_image_attrs(featured_image_url($featured['featured_image']))?> alt="<?=e($featured['featured_image_alt'] ?: $featured['title'])?>" loading="lazy" decoding="async"></div>
      <div class="featured-thought-copy"><div class="meta-line"><?php if($featured['category_name']):?><a href="<?=url('/topic/'.$featured['category_slug'])?>"><?=e($featured['category_name'])?></a><?php endif;?><span><?=e(format_date($featured['published_at']))?></span><span><?=reading_time($featured['content_html'])?> min read</span></div><h3><a href="<?=url('/my-thoughts/'.$featured['slug'])?>"><?=e($featured['title'])?></a></h3><p><?=e($featured['standfirst'] ?: excerpt($featured['content_html'],230))?></p></div>
    </article>
  </div>
</section>
<?php else:?>
<section class="section surface"><div class="container benchmark-focus"><div><span class="eyebrow">Current focus · July 2026–June 2030</span><h2>From capability to opportunity.</h2><p>My commitments connect venture creation through Goblis Group, social enterprise incubation through Ubunifu Institute, and market access through Goblis Foundation’s AfriTrace Link.</p><p>These are ambitions for the period, with progress published as approved source reports become available.</p><a class="text-link" href="#goals-2030">Explore the commitments and reporting method →</a></div><aside><h3>Follow the work as it develops</h3><p>The first long-form thought and startup journey are being prepared. In the meantime, use a practical worksheet to examine your own idea.</p><a class="text-link" href="<?=url('/resources/problem-interview-worksheet')?>">Prepare a problem interview →</a></aside></div></section>
<?php endif;?>
<section class="section experience-explore"><div class="container"><span class="eyebrow">Choose your starting point</span><h2>Read. Follow the work. Build a connection.</h2><div class="experience-grid">
<?php foreach([['/start-here','Explore the thinking','An introduction to My Thoughts and the questions that connect the five subjects.'],['/startup-journeys','Follow a venture','Read the experiments, decisions and evidence behind a venture as its journey is published.'],['/contact','Discuss a collaboration','Start a conversation about enterprise, innovation, sustainable development or a speaking invitation.']] as $item):?><a class="experience-card" href="<?=url($item[0])?>"><h3><?=e($item[1])?> →</h3><p><?=e($item[2])?></p></a><?php endforeach;?></div></div></section>
<?php include __DIR__.'/impact_summary.php'; ?>
<?php if(!empty($selected)):?>
<section class="section"><div class="container"><div class="section-heading" data-reveal><div><span class="eyebrow">Selected thoughts</span><h2>Good places to start.</h2></div><a class="text-link" href="<?=url('/my-thoughts')?>">Explore the archive →</a></div><div class="post-grid"><?php foreach(array_slice($selected,0,3) as $p) include __DIR__.'/partials_post_card.php';?></div></div></section>
<?php endif;?>

<section class="regional-lens">
  <div class="container regional-lens-grid" data-reveal>
    <div><span class="eyebrow">An African starting point</span><h2>Rooted in Kenya. Looking outward without losing the ground beneath the argument.</h2></div>
    <div><p>Many of the questions I care about cross borders: food systems, enterprise, infrastructure, energy, trade, tourism, technology and how opportunity moves. I follow those connections across East Africa and the rest of Africa, then compare them with what I encounter in Europe, the United Kingdom, North America and elsewhere when the comparison helps rather than distracts.</p><div class="region-chips"><span>Kenya</span><span>East Africa</span><span>Africa</span><span>Europe</span><span>United Kingdom</span><span>United States</span><span>Canada</span><span>Elsewhere</span></div></div>
  </div>
</section>

<section class="section surface"><div class="container benchmark-focus"><div><span class="eyebrow">A practical starting point</span><h2>Turn an assumption into a useful conversation.</h2><p>The problem interview worksheet helps you prepare questions, record observations and separate what you heard from what you assumed.</p><a class="button button-primary" href="<?=url('/resources/problem-interview-worksheet')?>">Use the problem interview worksheet</a></div><aside><span class="eyebrow">Blank working tool</span><h3>Read online or download editable text.</h3><p>Use your own evidence. The worksheet is a guide to inquiry, not proof of demand or a promise of success.</p><a class="text-link" href="<?=url('/resources')?>">Explore all practical resources →</a></aside></div></section>
<section class="section"><div class="container benchmark-subscribe"><span class="eyebrow">Stay connected</span><h2>Follow new thinking and practical lessons.</h2><p>Choose the subjects you want to follow. Confirm your email, manage your preferences and unsubscribe whenever you wish.</p><div class="button-row"><a class="button button-primary" href="<?=url('/newsletter')?>" data-analytics-event="newsletter_signup_open" data-content-id="homepage">Choose email updates</a><a class="text-link" href="<?=url('/feed.xml')?>">Follow by RSS →</a></div></div></section>
