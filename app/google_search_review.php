<?php
declare(strict_types=1);

/**
 * On-demand, read-only GSC signals. Never promotes articles, contacts a reader,
 * or treats missing top-row data as zero. Uses the already-confirmed GSC grant.
 */
function google_search_review_extract(array $report): array {
    $rows = [];
    foreach (array_slice((array)($report['rows'] ?? []), 0, 1000) as $row) {
        if (!is_array($row) || !is_string($row[0] ?? null)) continue;
        try {
            $page = google_workspace_public_url($row[0]);
        } catch (InvalidArgumentException) {
            continue; // Never render arbitrary external or private provider URLs.
        }
        if (isset($rows[$page])) continue;
        $metrics = [];
        foreach ([1,2,3,4] as $i) {
            $value = $row[$i] ?? null;
            if (!is_numeric($value)) continue 2;
            $n = (float)$value;
            if (!is_finite($n) || $n < 0) continue 2;
            $metrics[] = $n;
        }
        if ($metrics[2] > 1 || $metrics[3] > 200) continue;
        $rows[$page] = ['page'=>$page,'clicks'=>$metrics[0],'impressions'=>$metrics[1],'ctr'=>$metrics[2],'position'=>$metrics[3]];
    }
    return $rows;
}

function google_search_review_compare(array $current, array $prior): array {
    $today = google_search_review_extract($current);
    $before = google_search_review_extract($prior);
    $signals = [];
    foreach ($today as $page => $now) {
        $was = $before[$page] ?? null;
        $kind = null;
        $priority = 9;
        $reason = '';
        if ($was !== null && $was['clicks'] >= 10 && $was['clicks']-$now['clicks'] >= 5
            && $now['clicks'] <= $was['clicks'] * .7) {
            $kind='Clicks declined';
            $priority=1;
            $reason='Comparable clicks fell by at least 30%. Review search demand, content accuracy and indexing; no cause is assumed.';
        } elseif ($now['impressions'] >= 100 && $now['ctr'] < .02 && $now['position'] > 0 && $now['position'] <= 20) {
            $kind='CTR worth reviewing';
            $priority=2;
            $reason='At least 100 impressions and CTR below 2% with average position 20 or better. Review the title and description against search intent.';
        } elseif ($was === null && $now['impressions'] >= 50) {
            $kind='No comparable prior row';
            $priority=3;
            $reason='This URL is present in the current top-row sample, not in the earlier sample. This does not mean previous clicks were zero or that the page is new.';
        } elseif ($was !== null && $was['clicks'] >= 5 && $now['clicks']-$was['clicks'] >= 5
            && $now['clicks'] >= $was['clicks'] * 1.3) {
            $kind='Clicks increased';
            $priority=4;
            $reason='Clicks increased by at least 30% across equal-length periods. Check the content and context before attributing a cause.';
        }
        if ($kind === null) continue;
        $signals[] = ['page'=>$page,'signal'=>$kind,'priority'=>$priority,
            'clicks'=>$now['clicks'],'prior_clicks'=>$was['clicks'] ?? null,
            'impressions'=>$now['impressions'],'ctr'=>$now['ctr'],
            'position'=>$now['position'],'reason'=>$reason];
    }
    usort($signals, static fn(array $a,array $b):int =>
        ($a['priority'] <=> $b['priority'])
        ?: ($b['impressions'] <=> $a['impressions'])
        ?: strcmp($a['page'],$b['page']));
    return ['rows'=>array_slice($signals,0,30),'current_sample'=>count($today),
        'previous_sample'=>count($before),'signals_in_sample'=>count($signals)];
}

function google_search_review(int $days, ?callable $transport=null, ?string $asOf=null, ?int $actorId=null): array {
    if (!in_array($days,[28,90],true)) throw new InvalidArgumentException('Choose 28 or 90 completed days.');
    $today=google_workspace_date($asOf ?? gmdate('Y-m-d'),'');
    $end=google_workspace_shift_date($today,-3);
    $start=google_workspace_shift_date($end,-($days-1));
    $previous=google_workspace_previous_period($start,$end);
    $now=google_workspace_report('gsc-pages',$start,$end,$transport,$actorId);
    $old=google_workspace_report('gsc-pages',$previous['start'],$previous['end'],$transport,$actorId);
    $summary=google_search_review_compare($now,$old);
    return ['title'=>'Search Console · content review','days'=>$days,
        'current'=>['start'=>$start,'end'=>$end], 'previous'=>$previous,
        'observed_at'=>gmdate('c')] + $summary + [
        'note'=>'Read-only signals from up to 1,000 top page rows in each equal-length, finalized Search Console period. '
           .'These samples are not complete traffic totals. A missing earlier or current row is unavailable, never zero. '
           .'CTR, position and click changes are prompts for editorial review, not diagnoses or predicted results. '
           .'Only supported canonical public editorial pages are shown. No automatic editing, indexing request or publication occurs.'
    ];
}
