<?php
declare(strict_types=1);

/** Fixed-host migration redirect. Never forward private/free-text query data. */
function wk_opportunity_migration_target(string $path, array $query): ?string
{
    if (!str_starts_with($path, '/opportunities') && !str_starts_with($path, '/write/opportunities')) return null;
    $base = 'https://www.ubunifuinstitute.com/opportunities';
    // Preserve the existing private/unknown-route fallback without leaking its query.
    if (!preg_match('~^/opportunities(?:/country/([a-z]+(?:-[a-z]+)*))?/?$~D', $path, $match)) return $base;
    $codes = [
      'KE'=>'kenya','DZ'=>'algeria','AO'=>'angola','BJ'=>'benin','BW'=>'botswana','BF'=>'burkina-faso','BI'=>'burundi','CV'=>'cabo-verde','CM'=>'cameroon','CF'=>'central-african-republic','TD'=>'chad','KM'=>'comoros','CG'=>'republic-of-congo','CD'=>'democratic-republic-of-the-congo','CI'=>'cote-divoire','DJ'=>'djibouti','EG'=>'egypt','GQ'=>'equatorial-guinea','ER'=>'eritrea','SZ'=>'eswatini','ET'=>'ethiopia','GA'=>'gabon','GM'=>'the-gambia','GH'=>'ghana','GN'=>'guinea','GW'=>'guinea-bissau','LS'=>'lesotho','LR'=>'liberia','LY'=>'libya','MG'=>'madagascar','MW'=>'malawi','ML'=>'mali','MR'=>'mauritania','MU'=>'mauritius','MA'=>'morocco','MZ'=>'mozambique','NA'=>'namibia','NE'=>'niger','NG'=>'nigeria','RW'=>'rwanda','ST'=>'sao-tome-and-principe','SN'=>'senegal','SC'=>'seychelles','SL'=>'sierra-leone','SO'=>'somalia','ZA'=>'south-africa','SS'=>'south-sudan','SD'=>'sudan','TZ'=>'tanzania','TG'=>'togo','TN'=>'tunisia','UG'=>'uganda','ZM'=>'zambia','ZW'=>'zimbabwe'
    ];
    $codes['CG'] = 'congo'; // Personal-site legacy slug.
    $filters = [];
    if (isset($match[1]) && $match[1] !== '') {
        $code = array_search($match[1], $codes, true);
        if (!is_string($code)) return $base;
        $filters['country'] = $code;
    }
    $type = $query['type'] ?? '';
    // Only semantically identical native types; do not guess grant/scholarship mappings.
    if (is_string($type) && in_array($type, ['grant','accelerator','fellowship','training','conference'], true)) $filters['type'] = $type;
    return $base . ($filters ? '?' . http_build_query($filters, '', '&', PHP_QUERY_RFC3986) : '');
}
