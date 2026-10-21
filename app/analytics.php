<?php
declare(strict_types=1);

/** One measurement destination for public editorial pages. */
function analytics_measurement_id(array $seo = [], bool $private = false, ?string $path = null): string {
    $path ??= path_only();
    if ($private || str_contains((string)($seo['robots'] ?? ''), 'noindex')
        || preg_match('~^/(?:write|install|app|storage|consent|payments|webhooks|contact|contribute|search|newsletter)(?:/|$)~', $path)
        || preg_match('~^/newsletter/(?:confirm|unsubscribe|one-click|preferences)(?:/|$)~', $path)
        || preg_match('~^/founders/(?:submit|follow-up)(?:/|$)~', $path)
        || isset($_GET['preview']) || (string)setting('google.analytics_enabled', '1') !== '1') return '';
    $id = trim((string)setting('google.ga4', 'G-RN0YXC2WCH'));
    // A deliberately blank setting stays off. Never revive a previous property.
    return preg_match('/^G-[A-Z0-9]{4,20}$/D', $id) ? $id : '';
}

/** Campaign labels are editorial identifiers, never arbitrary incoming text. */
function analytics_campaign_parameters(array $input): array {
    $result = [];
    $sources = ['facebook','instagram','x','tiktok','linkedin','youtube','newsletter','rss','community','partner'];
    $mediums = ['social','organic_social','email','video','referral','rss'];
    foreach (['utm_source'=>$sources, 'utm_medium'=>$mediums] as $key=>$values) {
        if (is_string($input[$key] ?? null) && in_array($input[$key], $values, true)) $result[$key] = $input[$key];
    }
    $campaign = $input['utm_campaign'] ?? null;
    if (is_string($campaign) && (in_array($campaign, ['weekly-updates','start-here'], true)
        || preg_match('/^(?:post|journey|resource|edition|watch)-[1-9][0-9]{0,8}(?:-week-[1-9][0-9]{0,2})?$/D', $campaign))) $result['utm_campaign'] = $campaign;
    $content = $input['utm_content'] ?? null;
    if (is_string($content) && (in_array($content, ['article','video','short','carousel','text','newsletter','profile','bio','qr'], true)
        || preg_match('/^(?:post|episode|edition)-[1-9][0-9]{0,8}$/D', $content))) $result['utm_content'] = $content;
    // Partial or invalid attribution is discarded rather than mislabelled.
    return isset($result['utm_source'], $result['utm_medium'], $result['utm_campaign']) ? $result : [];
}

function analytics_page_location(string $canonical, array $input = []): string {
    $parts = parse_url($canonical);
    $base = rtrim(base_url(), '/');
    $path = (string)($parts['path'] ?? '/');
    if (!str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/[\x00-\x20\x7f]/', $path)) $path = '/';
    $query = [];
    parse_str((string)($parts['query'] ?? ''), $canonicalQuery);
    if (is_string($canonicalQuery['page'] ?? null) && preg_match('/^[1-9][0-9]{0,5}$/D', $canonicalQuery['page'])) $query['page'] = $canonicalQuery['page'];
    $query += analytics_campaign_parameters($input);
    return $base.$path.($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
}

function analytics_campaign_url(string $path, string $source, string $medium, string $campaign, string $content = ''): string {
    if (!str_starts_with($path, '/') || str_starts_with($path, '//') || strpbrk($path, "?#\r\n") !== false
        || !preg_match('~^/(?:my-thoughts|startup-journeys|resources|watch)(?:/[a-z0-9-]+)?$|^/(?:start-here|newsletter)$~D', $path)) {
        throw new InvalidArgumentException('Choose a public editorial destination.');
    }
    $params = analytics_campaign_parameters(['utm_source'=>$source,'utm_medium'=>$medium,'utm_campaign'=>$campaign,'utm_content'=>$content]);
    if (!$params) throw new InvalidArgumentException('Choose recognised campaign identifiers.');
    return url($path).'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function analytics_known_content_ids(): array {
    $ids=['weekly-updates','founder-stories'];
    if(function_exists('editorial_templates'))foreach(editorial_templates()as$item){
        $id='resource-'.(string)($item['id']??'');
        if(preg_match('/^resource-template-[a-z0-9-]{1,100}$/D',$id))$ids[]=$id;
    }
    return $ids;
}
