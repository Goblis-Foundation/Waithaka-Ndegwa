<?php
declare(strict_types=1);

/**
 * Private editorial research policy for African opportunities.
 * This drives research order and classification only; it is never a public ranking.
 */
function opportunity_research_applicant_forms(): array {
    return [
        'for-profit'=>'For-profit enterprise or startup',
        'non-profit'=>'Non-profit, NGO, CBO or civil-society organisation',
        'research'=>'Research institution, university or researcher',
        'individual'=>'Individual founder, professional, student or innovator',
        'public'=>'Public institution or local authority',
        'mixed'=>'More than one applicant form',
    ];
}

function opportunity_research_instruments(): array {
    return [
        'grant'=>'Grant / non-dilutive funding',
        'prize'=>'Prize / challenge award',
        'equity'=>'Equity investment',
        'debt'=>'Loan / debt finance',
        'guarantee'=>'Guarantee / risk-sharing facility',
        'repayable-grant'=>'Repayable grant',
        'blended'=>'Blended finance',
        'research-grant'=>'Research grant',
        'contract'=>'Commissioned research / procurement contract',
        'in-kind'=>'In-kind incubation, technical assistance or services',
        'scholarship'=>'Scholarship / study funding',
        'fellowship'=>'Fellowship / stipend',
    ];
}

function opportunity_research_themes(): array {
    return [
        'research-funding',
        'startup-capital',
        'sme-finance',
        'innovation-grants',
        'non-profit-grants',
        'civil-society-funding',
        'climate-finance',
        'agriculture-and-food',
        'technology-and-digital',
        'health',
        'education',
        'energy',
        'creative-economy',
        'women-and-youth',
        'social-enterprise',
        'competitions-and-prizes',
    ];
}

function opportunity_research_country_order(): array {
    $countries=opportunity_africa_countries();
    $order=array_keys($countries);
    usort($order, static function(string $a,string $b): int {
        if($a==='kenya')return -1;
        if($b==='kenya')return 1;
        $aa=opportunity_africa_countries()[$a]['name']??$a;
        $bb=opportunity_africa_countries()[$b]['name']??$b;
        return strcmp($aa,$bb);
    });
    return $order;
}

function opportunity_research_policy(): array {
    return [
        'countries'=>opportunity_research_country_order(),
        'themes'=>opportunity_research_themes(),
        'applicant_forms'=>array_keys(opportunity_research_applicant_forms()),
        'instruments'=>array_keys(opportunity_research_instruments()),
        'source_rules'=>[
            'Prefer the funder, government, university, multilateral, foundation or programme owner as the primary source.',
            'Record the exact eligible countries and applicant form instead of inferring from geography.',
            'Keep grants, prizes, debt, equity, guarantees, repayable grants and blended finance distinct.',
            'Keep rolling programmes active only while the official application route remains available.',
            'Record closing dates exactly; do not invent a cutoff time when the source gives only a date.',
            'Archive expired calls and retain them as research history instead of presenting them as open.',
        ],
    ];
}
