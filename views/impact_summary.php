<section class="section impact-section" id="goals-2030" aria-labelledby="goals-title">
  <div class="container">
    <div class="section-heading"><div><span class="eyebrow">My commitments · 1 July 2026–30 June 2030</span><h2 id="goals-title">Building opportunity. Accountable to results.</h2></div><p>Three connected ambitions: build enterprises, strengthen social entrepreneurship and open access to markets.</p></div>
    <div class="impact-grid">
      <?php foreach(impact_metrics() as$key=>$metric):$report=impact_display(impact_snapshot($metric['source']),$key);?>
      <article class="impact-card">
        <span class="eyebrow"><?=e(impact_sources()[$metric['source']])?></span>
        <p class="impact-target"><?=number_format($metric['target'])?><small>Target by June 2030</small></p>
        <h3><?=e($metric['label'])?></h3><p><?=e($metric['copy'])?></p>
        <div class="impact-reported"><span><?=e($report['status'])?></span><strong><?=$report['value']===null?'—':number_format($report['value'])?></strong>
        <?php if($report['as_of']):?><small>Reported as of <time datetime="<?=e($report['as_of'])?>"><?=e(date('j M Y',strtotime($report['as_of'])))?></time></small><?php else:?><small>Progress will appear when the source system supplies an approved report.</small><?php endif;?></div>
      </article>
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
      <p>Each source deduplicates its own enterprises and staff before publishing an aggregate report. The categories can overlap and must not be added together. AfriTrace Link is the primary market-access count; Goblis Foundation supplies corroborating programme reporting, not an additional count.</p>
      <?php $foundation=impact_display(impact_snapshot('goblis_foundation'),'market_access');$market=impact_display(impact_snapshot('afritrace'),'market_access');?>
      <p><strong>Goblis Foundation reporting:</strong> <?=e($foundation['status'])?>.<?php if($foundation['as_of']):?> As of <?=e(date('j M Y',strtotime($foundation['as_of'])))?>.<?php if($market['value']!==null&&$foundation['value']!==$market['value']):?> Its market-access figure differs from the primary report; reconciliation is pending.<?php endif;?><?php endif;?></p>
      <p>Configured feeds are checked every 15 minutes. Reports older than 48 hours, or sources that cannot be reached, retain their last reported figure with an update-pending label. Definitions may be refined transparently with the source organisations before their first approved reports.</p>
      <p>Reporting design draws on <a href="https://impactreporting.org/what-to-report/" target="_blank" rel="noopener noreferrer">Impact Performance Reporting Norms</a> and <a href="https://impactfrontiers.org/norms/five-dimensions-of-impact/" target="_blank" rel="noopener noreferrer">Impact Frontiers</a>: distinguish targets, activities, outcomes and contribution, and explain the evidence and limits behind each number.</p></div>
    </details>
    <div class="button-row"><a class="button button-primary" href="<?=url('/contact')?>">Collaborate on these goals</a><a class="button button-secondary" href="<?=url('/startup-journeys')?>">Follow Startup Journeys</a></div>
  </div>
</section>
