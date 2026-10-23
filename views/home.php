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

<section class="section experience-explore"><div class="container"><span class="eyebrow">Explore</span><h2>Where would you like to begin?</h2><div class="experience-grid"><?php foreach([
['/start-here','Start Here','An introduction and useful reading paths.'],['/my-thoughts','My Thoughts','Experience, questions and ideas from the field.'],['/startup-journeys','Startup Journeys','Follow a venture from its first question.'],['/resources','Practical resources','Tools and guides to put ideas into practice.'],['/watch','Watch','Published videos and conversations.'],['/media-centre','Media centre','Biography, interview topics and enquiries.']
]as$item):?><a class="experience-card" href="<?=url($item[0])?>"><h3><?=e($item[1])?> →</h3><p><?=e($item[2])?></p></a><?php endforeach;?></div></div></section>
<?php include __DIR__.'/impact_summary.php'; ?>

<?php $activeJourney=function_exists('journeys_home_feature')?journeys_home_feature():null; ?>
<section class="home-build-section">
  <div class="container home-build-grid" data-reveal>
    <div><span class="eyebrow">Startup Journeys</span><h2>One startup.<br>From the first question.</h2><p>A weekly record of building a venture from scratch: the ideas, customer conversations, experiments, decisions and systems that turn a possibility into something useful.</p><div class="button-row"><a class="button button-primary" href="<?=url('/startup-journeys')?>">Follow the journey</a><a class="text-link" href="<?=url('/newsletter')?>" data-analytics-event="newsletter_signup_open" data-content-id="weekly-updates">Get weekly updates →</a></div></div>
    <div class="home-build-card"><?php if($activeJourney):?><span class="eyebrow"><?=e(ucfirst($activeJourney['phase']))?></span><h3><a href="<?=url('/startup-journeys/'.$activeJourney['slug'])?>"><?=e($activeJourney['title'])?></a></h3><p><?=e($activeJourney['summary'])?></p><a class="text-link" href="<?=url('/startup-journeys/'.$activeJourney['slug'])?>"><?=intval($activeJourney['episode_count'])?> published updates · Read from the beginning →</a><?php else:?><span class="eyebrow">The starting point</span><h3>Begin with a real problem.</h3><p>The first season is being prepared. Each update will record what was tried, what the evidence showed and what changes next.</p><ol class="home-build-steps"><li><span>01</span> Find a problem worth solving</li><li><span>02</span> Test it with real people</li><li><span>03</span> Build, measure and share the lessons</li></ol><?php endif;?></div>
  </div>
</section>

<section class="home-intro">
  <div class="container home-intro-grid" data-reveal>
    <span class="eyebrow">The lens</span>
    <h2>I am interested in the space between a good idea and what actually happens when people have to live with it.</h2>
    <p>That means looking beyond sectors in isolation. Agriculture depends on roads and markets. Enterprise depends on institutions and capital. Innovation matters when it solves a real problem. Travel is useful when it changes what I notice back home.</p>
  </div>
</section>

<section class="regional-lens">
  <div class="container regional-lens-grid" data-reveal>
    <div><span class="eyebrow">An African starting point</span><h2>Rooted in Kenya. Looking outward without losing the ground beneath the argument.</h2></div>
    <div><p>Many of the questions I care about cross borders: food systems, enterprise, infrastructure, energy, trade, tourism, technology and how opportunity moves. I follow those connections across East Africa, then compare them with what I encounter in Europe, the United Kingdom, North America and elsewhere when the comparison helps rather than distracts.</p><div class="region-chips"><span>Kenya</span><span>East Africa</span><span>Europe</span><span>United Kingdom</span><span>United States</span><span>Canada</span><span>Elsewhere</span></div></div>
  </div>
</section>

<?php if(!empty($latest)): $featured=$latest[0];?>
<section class="section surface">
  <div class="container">
    <div class="section-heading" data-reveal><div><span class="eyebrow">Latest thought</span><h2>What I am thinking about now.</h2></div><a class="text-link" href="<?=url('/my-thoughts')?>">Browse all thoughts →</a></div>
    <article class="featured-thought" data-reveal>
      <a class="featured-thought-media" href="<?=url('/my-thoughts/'.$featured['slug'])?>"><img <?=responsive_image_attrs(featured_image_url($featured['featured_image']))?> alt="<?=e($featured['featured_image_alt'] ?: $featured['title'])?>" loading="eager" decoding="async"></a>
      <div class="featured-thought-copy"><div class="meta-line"><?php if($featured['category_name']):?><a href="<?=url('/topic/'.$featured['category_slug'])?>"><?=e($featured['category_name'])?></a><?php endif;?><span><?=e(format_date($featured['published_at']))?></span><span><?=reading_time($featured['content_html'])?> min read</span></div><h3><a href="<?=url('/my-thoughts/'.$featured['slug'])?>"><?=e($featured['title'])?></a></h3><p><?=e($featured['standfirst'] ?: excerpt($featured['content_html'],230))?></p><a class="button button-primary button-small" href="<?=url('/my-thoughts/'.$featured['slug'])?>">Read this thought</a></div>
    </article>
  </div>
</section>
<?php else:?>
<section class="section surface"><div class="container"><div class="empty-state" data-reveal><span class="eyebrow">My Thoughts</span><h2>The first long-form thought will appear here.</h2><p>This is where experience becomes reflection — and reflection becomes a conversation.</p><a class="button button-primary" href="<?=url('/my-thoughts')?>">Open My Thoughts</a></div></div></section>
<?php endif;?>

<section class="section topics-section">
  <div class="container">
    <div class="section-heading" data-reveal><div><span class="eyebrow">Five recurring lenses</span><h2>Different subjects. One connected world.</h2></div><p>Categories are a way into the archive, not boxes around the thinking.</p></div>
    <div class="topic-grid">
      <?php foreach($topicStats as $i=>$c):?><a class="topic-tile" href="<?=url('/topic/'.$c['slug'])?>" data-reveal><span><?=str_pad((string)($i+1),2,'0',STR_PAD_LEFT)?></span><h3><?=e($c['name'])?></h3><p><?=e($c['description'])?></p><small><?=intval($c['post_count'])?> <?=intval($c['post_count'])===1?'thought':'thoughts'?> · Explore →</small></a><?php endforeach;?>
    </div>
  </div>
</section>

<section class="section approach-section surface">
  <div class="container">
    <div class="section-heading narrow-heading" data-reveal><div><span class="eyebrow">How I approach the world</span><h2>A point of view should be earned, not merely announced.</h2></div><p>I try to move from observation to context, from context to testing, and from testing to a view that can still change.</p></div>
    <div class="process-line" aria-label="How I form a point of view">
      <article data-reveal><span class="process-number">01</span><div><small>Observe</small><h3>Start with reality</h3><p>Listen, look closely and understand the experience before reaching for the explanation.</p></div></article>
      <article data-reveal><span class="process-number">02</span><div><small>Connect</small><h3>Follow the system</h3><p>Trace the links between people, markets, land, infrastructure, institutions, capital and incentives.</p></div></article>
      <article data-reveal><span class="process-number">03</span><div><small>Test</small><h3>Challenge the easy answer</h3><p>Bring in costs, trade-offs, implementation constraints and competing evidence.</p></div></article>
      <article data-reveal><span class="process-number">04</span><div><small>Revisit</small><h3>Keep the view alive</h3><p>Publish what I can defend today and change it when better evidence deserves to win.</p></div></article>
    </div>
  </div>
</section>

<section class="section tested-section">
  <div class="container">
    <div class="section-heading" data-reveal><div><span class="eyebrow">Where the thinking gets tested</span><h2>Reality has a way of exposing weak assumptions.</h2></div><p>Much of what I write is tested first in places where decisions have consequences.</p></div>
    <div class="tested-grid">
      <article class="tested-card" data-reveal><figure><img <?=responsive_image_attrs(asset('images/journey/community-event.webp'))?> alt="A community gathering" loading="lazy" decoding="async"></figure><div><span>People & enterprise</span><h3>Can an idea work for the people expected to carry it?</h3><p>Working with entrepreneurs and communities keeps attention on incentives, dignity, execution and whether value is actually created.</p><ul><li>Participation</li><li>Governance</li><li>Markets</li></ul></div></article>
      <article class="tested-card" data-reveal><figure><img <?=responsive_image_attrs(asset('images/journey/farm-produce.webp'))?> alt="Agricultural produce in the field" loading="lazy" decoding="async"></figure><div><span>Land & production</span><h3>Does the system still work when nature joins the discussion?</h3><p>Agriculture makes interdependence visible: soil, water, weather, labour, storage, transport and price all meet in one outcome.</p><ul><li>Resilience</li><li>Productivity</li><li>Value chains</li></ul></div></article>
      <article class="tested-card" data-reveal><figure><img <?=responsive_image_attrs(asset('images/journey/infrastructure.webp'))?> alt="Modern transport infrastructure seen while travelling" loading="lazy" decoding="async"></figure><div><span>Infrastructure & places</span><h3>What does a system feel like to the person using it?</h3><p>Travel and infrastructure turn policy into lived experience — movement, access, reliability, cost and the everyday design of opportunity.</p><ul><li>Access</li><li>Movement</li><li>Public systems</li></ul></div></article>
    </div>
  </div>
</section>

<section class="section origin-section surface">
  <div class="container origin-feature">
    <div class="origin-media" data-reveal><figure><img <?=responsive_image_attrs(asset('images/journey/water-source.webp'))?> alt="At a rural water source" loading="lazy" decoding="async"></figure><figure><img <?=responsive_image_attrs(asset('images/journey/city-overlook.webp'))?> alt="Looking over a city while travelling" loading="lazy" decoding="async"></figure></div>
    <div class="origin-copy" data-reveal><span class="eyebrow">From Nyeri outward</span><h2>The place I come from still shapes what I notice.</h2><p>I was born and raised in Charity, Endarasha, Nyeri County. In a rural setting, land, water, farming, roads, markets and livelihoods are not abstract policy concepts. They are everyday life.</p><p>That beginning did not give me all the answers. It gave me reasons to keep asking better questions about opportunity, dignity, enterprise and the systems that surround them.</p><a class="text-link" href="<?=url('/about')?>">Read the story behind the lens →</a></div>
  </div>
</section>

<?php if(!empty($selected)):?>
<section class="section"><div class="container"><div class="section-heading" data-reveal><div><span class="eyebrow">Selected thoughts</span><h2>Good places to start.</h2></div><a class="text-link" href="<?=url('/my-thoughts')?>">Explore the archive →</a></div><div class="post-grid"><?php foreach($selected as $p) include __DIR__.'/partials_post_card.php';?></div></div></section>
<?php endif;?>

<section class="section social-section surface"><div class="container social-section-grid" data-reveal><div><span class="eyebrow">Elsewhere</span><h2>Shorter thoughts live on social media.</h2><p>This website is where I slow down. For day-to-day observations, photographs and conversations, find me on my personal accounts.</p></div><div class="social-links-large"><?php foreach(social_links() as $name=>$link):?><a href="<?=e($link)?>" target="_blank" rel="me noopener noreferrer"><span><?=e($name)?></span><b>↗</b></a><?php endforeach;?></div></div></section>
