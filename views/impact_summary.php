<section class="section impact-section" id="goals-2030" aria-labelledby="goals-title">
  <div class="container">
    <div class="section-heading"><div><span class="eyebrow">My commitments · 1 July 2026–30 June 2030</span><h2 id="goals-title">Building opportunity. Accountable to results.</h2></div><p>Three connected ambitions: build enterprises, strengthen social entrepreneurship and open access to markets.</p></div>
    <div class="impact-groups">
    <?php $organisations=impact_organisations();foreach([
      ['org'=>0,'keys'=>['startups','permanent_staff']],
      ['org'=>1,'keys'=>['social_enterprises','ubunifu_permanent_staff']],
      ['org'=>3,'keys'=>['market_access']]
    ] as$group):$org=$organisations[$group['org']];?>
      <section class="impact-group" aria-label="<?=e($org['name'])?> goals">
        <a class="impact-organisation" href="<?=e($org['url'])?>" target="_blank" rel="noopener noreferrer" aria-label="<?=e('Visit '.$org['name'])?>">
          <span class="impact-organisation-logo"><img src="<?=asset('images/organisations/'.$org['logo'])?>" alt="<?=e($org['name'].' logo')?>" width="240" height="160" loading="lazy" decoding="async"></span>
          <h3><?=e($org['name'])?></h3><span>Explore the organisation ↗</span>
        </a>
        <?php if($group['org']===3):$project=$organisations[2];?>
        <a class="impact-project" href="<?=e($project['url'])?>" target="_blank" rel="noopener noreferrer"><img src="<?=asset('images/organisations/'.$project['logo'])?>" alt="AfriTrace Link logo" width="300" height="100" loading="lazy"><span><strong>AfriTrace Link</strong><small>A project of Goblis Foundation</small><small>Planned coverage · all 55 African countries</small><small>Explore AfriTrace Link ↗</small></span></a>
        <?php endif;?>
        <div class="impact-group-metrics">
        <?php foreach($group['keys'] as$key):$metric=impact_metrics()[$key];$report=impact_display(impact_snapshot($metric['source']),$key);?>
      <article class="impact-card">
        <span class="eyebrow"><?=e(impact_sources()[$metric['source']])?></span>
        <p class="impact-target"><?=number_format($metric['target'])?><small>Target by June 2030</small></p>
        <h3><?=e($metric['label'])?></h3><p><?=e($metric['copy'])?></p>
        <div class="impact-reported"><span><?=e($report['status'])?></span><strong><?=$report['value']===null?'—':number_format($report['value'])?></strong>
        <?php if($report['as_of']):?><small>Reported as of <time datetime="<?=e($report['as_of'])?>"><?=e(date('j M Y',strtotime($report['as_of'])))?></time></small><?php else:?><small>Progress will appear when the source system supplies an approved report.</small><?php endif;?></div>
      </article>
        <?php endforeach;?>
        </div>
        <?php $indirectSource=match($group['org']){0=>'goblis_group',1=>'ubunifu',default=>'goblis_foundation'};$indirect=impact_indirect_groups()[$indirectSource];$indirectSnapshot=impact_snapshot($indirectSource);$beneficiaries=impact_display($indirectSnapshot,'indirect_beneficiaries');?>
        <aside class="impact-indirect"><h4>Indirect beneficiaries</h4><p><?=e($indirect['copy'])?></p><strong><?=$beneficiaries['value']===null?'Count awaiting verified reporting':number_format($beneficiaries['value']).' people · '.e($beneficiaries['status'])?></strong><?php if($beneficiaries['as_of']):?><small>As of <?=e(date('j M Y',strtotime($beneficiaries['as_of'])))?></small><p><?=e($indirectSnapshot['data']['beneficiary_basis']??'')?></p><?php endif;?></aside>
      </section>
    <?php endforeach;?>
    </div>
    <p class="impact-context">These are my goals, not a claim of results already achieved. Progress comes from the organisations’ reporting systems. Missing data is shown as unavailable, rather than zero. These figures describe shared work with teams and partners, not outcomes attributable to me alone.</p>
    <details class="impact-method"><summary>How progress will be counted</summary>
      <div class="impact-method-copy"><h3>One reporting period. Clear definitions.</h3>
      <p>The reporting window runs from 1 July 2026 to 30 June 2030. Each source must approve its figures for publication and use the same reporting definitions. Source-reported progress is not an independent audit.</p>
      <ul><li><strong>Successful startups:</strong> distinct ventures established or directly supported through Goblis Group during the period, with a documented direct role, active operations, evidence of paying customers or revenue, and a reviewed case for continued viability. Incorporation alone does not qualify.</li>
      <li><strong>Permanent staff:</strong> distinct people currently employed on permanent contracts in the qualifying Goblis Group ventures at the reporting date. Temporary workers, contractors and volunteers are excluded. This is a headcount, not full-time equivalents or a cumulative sum of hires; it does not by itself establish net new job creation.</li>
      <li><strong>Social enterprises established:</strong> distinct operating enterprises supported through Ubunifu Institute during the period, with a documented social or environmental purpose, evidence of trading and a reviewed plan for financial sustainability and impact. Course enrolment or completion alone does not qualify.</li>
      <li><strong>Connected to markets:</strong> distinct African social enterprises with evidence of a completed buyer introduction, buyer meeting, accepted market opportunity or transaction through AfriTrace Link during the period. Profile creation alone does not qualify. A connection does not necessarily mean a sale.</li></ul>
      <p><strong>Ubunifu employment target:</strong> 2,030 social enterprises incubated at Ubunifu Institute × an average of 5 permanent staff = 10,150 permanent jobs across those enterprises. Employment is generated indirectly through incubation; the enterprises employ the staff. Actual staff are counted from source reports, rather than calculated from enterprise counts.</p><p><strong>Indirect beneficiaries:</strong> count distinct people outside the directly served group with evidence of benefit, using an approved survey or documented sampling method. Do not count every household member, customer, supplier or community resident automatically. Deduplicate people across households and activities; exclude direct participants and explain sampling, geographic coverage and uncertainty. No common household multiplier is applied across African countries. Research on <a href="https://www.gov.uk/research-for-development-outputs/tracking-indirect-beneficiaries-of-complex-development-interventions-in-agriculture" target="_blank" rel="noopener noreferrer">tracking indirect beneficiaries</a> supports tracing the pathway of benefit rather than assuming reach.</p><p>Each source deduplicates its own enterprises and staff before publishing an aggregate report. The categories can overlap and must not be added together. AfriTrace Link is the primary market-access count; Goblis Foundation supplies corroborating programme reporting, not an additional count.</p>
      <?php $foundation=impact_display(impact_snapshot('goblis_foundation'),'market_access');$market=impact_display(impact_snapshot('afritrace'),'market_access');?>
      <p><strong>Goblis Foundation reporting:</strong> <?=e($foundation['status'])?>.<?php if($foundation['as_of']):?> As of <?=e(date('j M Y',strtotime($foundation['as_of'])))?>.<?php if($market['value']!==null&&$foundation['value']!==$market['value']):?> Its market-access figure differs from the primary report; reconciliation is pending.<?php endif;?><?php endif;?></p>
      <p>Configured feeds are checked every 15 minutes. Reports older than 48 hours, or sources that cannot be reached, retain their last reported figure with an update-pending label. Definitions may be refined transparently with the source organisations before their first approved reports.</p>
      <p>Reporting design draws on <a href="https://impactreporting.org/what-to-report/" target="_blank" rel="noopener noreferrer">Impact Performance Reporting Norms</a> and <a href="https://impactfrontiers.org/norms/five-dimensions-of-impact/" target="_blank" rel="noopener noreferrer">Impact Frontiers</a>: distinguish targets, activities, outcomes and contribution, and explain the evidence and limits behind each number.</p></div>
    </details>
    <div class="button-row"><a class="button button-primary" href="<?=url('/contact')?>">Collaborate on these goals</a><a class="button button-secondary" href="<?=url('/startup-journeys')?>">Follow Startup Journeys</a></div>
  </div>
</section>
