<?php
$all=$countries;$indexable=$indexed;
$regions=[];foreach($all as$slug=>$country)$regions[$country['region']][$slug]=$country;
ksort($regions);
?>
<link rel="stylesheet" href="<?=e(asset('css/publishing-desk.css'))?>">
<section class="desk desk-public">
<header class="desk-hero"><div><p class="desk-kicker">African opportunity discovery</p><h1>Browse opportunities by country.</h1><p>Choose an applicant country to see grants, fellowships, accelerators, training and business support that current eligibility information indicates may be open there.</p></div></header>
<nav class="desk-tabs" aria-label="Discover"><a href="<?=url('/opportunities')?>">All opportunities</a><a href="<?=url('/opportunities/countries')?>" aria-current="page">African countries</a><a href="<?=url('/opportunities/jobs')?>">Jobs & volunteering</a></nav>
<div class="opportunity-discovery-note"><strong>Search-quality rule:</strong> country pages only enter the public sitemap when they contain current eligible opportunities. Empty country pages remain available for browsing but are not submitted for indexing.</div>
<?php foreach($regions as$region=>$rows):?>
<section class="country-region"><h2><?=e($region)?></h2><div class="country-grid">
<?php foreach($rows as$slug=>$country):$live=isset($indexable[$slug]);?>
<a class="country-card<?=$live?' has-opportunities':''?>" href="<?=url('/opportunities/country/'.$slug)?>"><strong><?=e($country['name'])?></strong><span><?=$live?'Current opportunities available':'No current verified listings'?></span></a>
<?php endforeach;?>
</div></section>
<?php endforeach;?>
<?php require __DIR__.'/partials_opportunity_support.php';?>
</section>
