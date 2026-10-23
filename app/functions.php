<?php
declare(strict_types=1);

function app_config(?string $key = null, mixed $default = null): mixed {
    global $CONFIG;
    if ($key === null) return $CONFIG;
    $value = $CONFIG;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) return $default;
        $value = $value[$part];
    }
    return $value;
}

function installed(): bool {
    global $APP_INSTALLED;
    return (bool)$APP_INSTALLED;
}

function db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    if (!installed()) throw new RuntimeException('Application is not installed.');
    $db = app_config('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'] ?? 3306, $db['name'], $db['charset'] ?? 'utf8mb4');
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if (!empty($db['ssl_ca'])) $options[PDO::MYSQL_ATTR_SSL_CA] = $db['ssl_ca'];
    $pdo = new PDO($dsn, $db['user'], $db['pass'], $options);
    // Legacy DATETIME values are Nairobi wall time, including scheduled posts.
    $pdo->exec("SET time_zone = '+03:00'");
    return $pdo;
}

function e(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function base_url(): string { return rtrim((string)app_config('base_url', 'https://www.waithakandegwa.com'), '/'); }
function url(string $path = ''): string { return base_url() . '/' . ltrim($path, '/'); }
function asset(string $path): string {
    $path=ltrim($path,'/');$value=url('assets/'.$path);
    if(preg_match('~^(?:css|js)/[a-zA-Z0-9_./-]+\.(?:css|js)$~D',$path)&&!str_contains($path,'..')){
        $root=realpath(__DIR__.'/../assets');$file=realpath(__DIR__.'/../assets/'.$path);
        if($root&&$file&&str_starts_with($file,$root.DIRECTORY_SEPARATOR)&&is_file($file))$value.='?v='.(string)filemtime($file);
    }
    return $value;
}
function path_only(): string { $p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH); return '/' . trim((string)$p, '/'); }
function is_post(): bool { return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }
function redirect(string $to, int $code = 302): never { header('Location: ' . $to, true, $code); exit; }

function setting(string $key, mixed $default = null): mixed {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $st = db()->prepare('SELECT setting_value, is_secret FROM settings WHERE setting_key=? LIMIT 1');
        $st->execute([$key]);
        $row = $st->fetch();
        if (!$row) return $cache[$key] = $default;
        $value = $row['setting_value'];
        if ((int)$row['is_secret'] === 1 && $value !== null && $value !== '') $value = decrypt_secret($value);
        return $cache[$key] = $value;
    } catch (Throwable $e) {
        return $default;
    }
}

function clear_setting_cache(): void {
    // Per-request PHP lifecycle makes explicit invalidation unnecessary after redirects.
}

function set_setting(string $key, ?string $value, bool $secret = false): void {
    if ($secret && $value !== null && $value !== '') $value = encrypt_secret($value);
    $st = db()->prepare('INSERT INTO settings(setting_key,setting_value,is_secret) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), is_secret=VALUES(is_secret)');
    $st->execute([$key, $value, $secret ? 1 : 0]);
}

function encrypt_secret(string $plain): string {
    $key = hash('sha256', (string)app_config('app_key'), true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('Unable to encrypt secret.');
    return base64_encode($iv . $tag . $cipher);
}

function decrypt_secret(string $encoded): string {
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 29) return '';
    $iv = substr($raw, 0, 12); $tag = substr($raw, 12, 16); $cipher = substr($raw, 28);
    $key = hash('sha256', (string)app_config('app_key'), true);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : $plain;
}

function csrf_token(): string {
    if (!is_string($_SESSION['csrf'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_matches(mixed $expected, mixed $provided): bool {
    return is_string($expected) && is_string($provided)
        && preg_match('/^[a-f0-9]{64}$/D', $expected) === 1
        && preg_match('/^[a-f0-9]{64}$/D', $provided) === 1
        && hash_equals($expected, $provided);
}
function verify_csrf(): void {
    $provided = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!csrf_matches($_SESSION['csrf'] ?? null, $provided)) {
        http_response_code(419); exit('Your session has expired. Please refresh and try again.');
    }
}

function flash(string $type, string $message): void { $_SESSION['flash'][] = ['type'=>$type,'message'=>$message]; }
function flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    static $user;
    if ($user) return $user;
    $st = db()->prepare('SELECT id,name,email FROM users WHERE id=? AND is_active=1');
    $st->execute([$_SESSION['user_id']]);
    $row=$st->fetch();
    if(!$row)return null;
    $epoch=(string)setting('auth.password_epoch.'.$row['id'],'');
    if($epoch!==''&&!hash_equals($epoch,(string)($_SESSION['auth_epoch']??''))){unset($_SESSION['user_id'],$_SESSION['auth_epoch']);return null;}
    return $user=$row;
}
function require_auth(): void { if (!current_user()) redirect(url('/write/login')); }

function ip_hash(): string {
    $ip = function_exists('wk_client_ip') ? wk_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return hash_hmac('sha256', $ip, (string)app_config('app_key', 'wk'));
}

function login_rate_limited(string $email): bool {
    $since = date('Y-m-d H:i:s', time() - 15*60);
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE attempted_at>=? AND success=0 AND (ip_hash=? OR email=?)');
    $st->execute([$since, ip_hash(), strtolower($email)]);
    return (int)$st->fetchColumn() >= 8;
}

function attempt_login(string $email, string $password): bool {
    $email = strtolower(trim($email));
    if (login_rate_limited($email)) return false;
    $st = db()->prepare('SELECT * FROM users WHERE email=? AND is_active=1 LIMIT 1');
    $st->execute([$email]); $user = $st->fetch();
    $ok = $user && password_verify($password, $user['password_hash']);
    db()->prepare('INSERT INTO login_attempts(email,ip_hash,success) VALUES(?,?,?)')->execute([$email,ip_hash(),$ok?1:0]);
    if ($ok) {
        session_regenerate_id(true); $_SESSION['user_id'] = $user['id']; $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['auth_epoch']=(string)setting('auth.password_epoch.'.$user['id'],'');
        db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$user['id']]);
        return true;
    }
    return false;
}

function slugify(string $text): string {
    $text = trim($text);
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($ascii !== false) $text = $ascii;
    $text = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $text) ?? '');
    $text = trim($text, '-');
    return $text ?: 'post-' . date('YmdHis');
}

function unique_post_slug(string $title, ?int $postId = null): string {
    $base = slugify($title); $slug = $base; $i = 2;
    while (true) {
        $sql = 'SELECT id FROM posts WHERE slug=?' . ($postId ? ' AND id<>?' : '') . ' LIMIT 1';
        $st = db()->prepare($sql); $params = [$slug]; if ($postId) $params[] = $postId; $st->execute($params);
        if (!$st->fetch()) return $slug;
        $slug = $base . '-' . $i++;
    }
}

function safe_content_url(string $value, bool $image = false): bool {
    if ($value === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) return false;
    if (preg_match('~^https?://~i', $value)) {
        $parts = parse_url($value);
        return filter_var($value, FILTER_VALIDATE_URL) !== false && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass']);
    }
    if ($image) return preg_match('~^/assets/(?:uploads|images)/[^\\\\]*$~D', $value) === 1 && !str_contains(rawurldecode($value), '..');
    if (str_starts_with($value, '/') && !str_starts_with($value, '//')) return true;
    if (str_starts_with($value, '#')) return true;
    return str_starts_with(strtolower($value), 'mailto:') && filter_var(substr($value, 7), FILTER_VALIDATE_EMAIL) !== false;
}

function sanitize_html(string $html): string {
    if (trim($html) === '') return '';
    $allowed = ['p','h2','h3','h4','blockquote','ul','ol','li','strong','b','em','i','a','br','hr','figure','figcaption','img','pre','code'];
    $discard = ['script','style','template','svg','math','iframe','object','embed','noscript','head','title','textarea','xmp','plaintext'];
    $source = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    try {
        $loaded = $source->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    if (!$loaded) return e($html);
    $output = new DOMDocument('1.0', 'UTF-8');
    $root = $output->createElement('div');
    $output->appendChild($root);
    // Rebuild an allowlisted tree. Unwrapping a disallowed container must never
    // skip its descendants (the old traversal left nested scripts/handlers intact).
    $copy = function(DOMNode $from, DOMNode $into) use (&$copy, $allowed, $discard, $output): void {
        foreach ($from->childNodes as $child) {
            if ($child instanceof DOMText) { $into->appendChild($output->createTextNode($child->nodeValue ?? '')); continue; }
            if (!$child instanceof DOMElement) continue;
            $tag = strtolower($child->tagName);
            if (in_array($tag, $discard, true) || $child->namespaceURI) continue;
            if (!in_array($tag, $allowed, true)) { $copy($child, $into); continue; }
            $clean = $output->createElement($tag);
            if ($tag === 'a') {
                $href = trim($child->getAttribute('href'));
                if (safe_content_url($href)) $clean->setAttribute('href', $href);
                if ($child->hasAttribute('title')) $clean->setAttribute('title', $child->getAttribute('title'));
                if ($child->getAttribute('target') === '_blank') { $clean->setAttribute('target', '_blank'); $clean->setAttribute('rel', 'noopener noreferrer'); }
            }
            if ($tag === 'img') {
                $src = trim($child->getAttribute('src'));
                if (!safe_content_url($src, true)) continue;
                $clean->setAttribute('src', $src);
                foreach (['alt','title'] as $name) if ($child->hasAttribute($name)) $clean->setAttribute($name, $child->getAttribute($name));
                foreach (['width','height'] as $name) if (preg_match('/^[1-9][0-9]{0,4}$/D', $child->getAttribute($name))) $clean->setAttribute($name, $child->getAttribute($name));
                $clean->setAttribute('loading', 'lazy');
            }
            $into->appendChild($clean);
            $copy($child, $clean);
        }
    };
    $body = $source->getElementsByTagName('body')->item(0);
    if ($body) $copy($body, $root);
    $html = '';
    foreach ($root->childNodes as $child) $html .= $output->saveHTML($child);
    return trim($html);
}

function xml_escape(string $value): string {
    $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

function sitemap_xml(array $entries): string {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($entries as $entry) {
        $xml .= '<url><loc>'.xml_escape((string)$entry['loc']).'</loc>';
        if (!empty($entry['lastmod'])) $xml .= '<lastmod>'.xml_escape((string)$entry['lastmod']).'</lastmod>';
        $xml .= '</url>';
    }
    return $xml.'</urlset>';
}

function plain_text(string $html): string { return trim(preg_replace('/\s+/u',' ', html_entity_decode(strip_tags($html), ENT_QUOTES|ENT_HTML5,'UTF-8')) ?? ''); }
function excerpt(string $html, int $length = 180): string {
    $txt = plain_text($html); if (mb_strlen($txt) <= $length) return $txt;
    $s = mb_substr($txt, 0, $length); $p = mb_strrpos($s, ' '); if ($p !== false) $s = mb_substr($s,0,$p);
    return rtrim($s, '.,;: ') . '…';
}
function reading_time(string $html): int { $words = preg_split('/\s+/u', plain_text($html), -1, PREG_SPLIT_NO_EMPTY); return max(1, (int)ceil(count($words ?: []) / 225)); }
function format_date(?string $date): string { return $date ? date('j F Y', strtotime($date)) : ''; }

function categories(): array { return db()->query('SELECT * FROM categories ORDER BY sort_order,name')->fetchAll(); }
function category_by_slug(string $slug): ?array { $st=db()->prepare('SELECT * FROM categories WHERE slug=?');$st->execute([$slug]);return $st->fetch()?:null; }
function published_where(): string { return "p.status IN ('published','scheduled') AND p.published_at IS NOT NULL AND p.published_at<=NOW()"; }
/** Strict page parsing prevents arrays, overflow and malformed URLs becoming page one. */
function archive_page_number(mixed $value=null): ?int {
    if ($value===null) return 1;
    if ((!is_string($value)&&!is_int($value))||!preg_match('/^[1-9][0-9]*$/D',(string)$value)) return null;
    $number=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    return $number===false?null:$number;
}
function archive_page_path(string $path,int $page,array $query=[]): string {
    unset($query['page']);
    if($page>1)$query['page']=$page;
    return $path.($query?'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986):'');
}
/** Returns null before any offset query when a page is outside the public result set. */
function archive_pagination(?int $page,int $total,int $perPage,string $path,array $query=[]): ?array {
    if($page===null||$page<1||$total<0||$perPage<1||$perPage>200) return null;
    $pages=max(1,intdiv($total,$perPage)+($total%$perPage===0?0:1));
    if($page>$pages)return null;
    $offset=($page-1)*$perPage;
    return ['page'=>$page,'pages'=>$pages,'total'=>$total,'per_page'=>$perPage,'offset'=>$offset,
        'start'=>$total?$offset+1:0,'end'=>min($total,$offset+$perPage),
        'path'=>archive_page_path($path,$page,$query),'first'=>archive_page_path($path,1,$query),
        'previous'=>$page>1?archive_page_path($path,$page-1,$query):null,
        'next'=>$page<$pages?archive_page_path($path,$page+1,$query):null];
}
function public_post_count(?int $categoryId=null): int {
    $sql='SELECT COUNT(*) FROM posts p WHERE '.published_where();$params=[];
    if($categoryId!==null){$sql.=' AND p.category_id=?';$params[]=$categoryId;}
    $st=db()->prepare($sql);$st->execute($params);return (int)$st->fetchColumn();
}
function latest_posts(int $limit=6, ?int $categoryId=null,int $offset=0): array {
    $sql='SELECT p.*,c.name category_name,c.slug category_slug FROM posts p LEFT JOIN categories c ON c.id=p.category_id WHERE '.published_where();
    $params=[]; if ($categoryId!==null) { $sql.=' AND p.category_id=?'; $params[]=$categoryId; }
    $sql.=' ORDER BY p.published_at DESC,p.id DESC LIMIT '.max(1,min(5000,$limit)).' OFFSET '.max(0,$offset);
    $st=db()->prepare($sql);$st->execute($params);return $st->fetchAll();
}
function selected_posts(int $limit=3): array {
    $sql='SELECT p.*,c.name category_name,c.slug category_slug FROM posts p LEFT JOIN categories c ON c.id=p.category_id WHERE '.published_where().' AND p.is_selected=1 ORDER BY p.published_at DESC LIMIT '.max(1,min(12,$limit));
    return db()->query($sql)->fetchAll();
}
function post_by_slug(string $slug): ?array {
    $st=db()->prepare('SELECT p.*,c.name category_name,c.slug category_slug,u.name author_name FROM posts p LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN users u ON u.id=p.user_id WHERE p.slug=? AND '.published_where().' LIMIT 1');
    $st->execute([$slug]); $p=$st->fetch(); if(!$p)return null; $p['content_html']=sanitize_html($p['content_html']);$p['tags']=post_tags((int)$p['id']); return $p;
}
function post_tags(int $postId): array { $st=db()->prepare('SELECT t.* FROM tags t JOIN post_tags pt ON pt.tag_id=t.id WHERE pt.post_id=? ORDER BY t.name');$st->execute([$postId]);return $st->fetchAll(); }

class WriterConflict extends RuntimeException {}

function writer_editable_fields(): array {
    return ['category_id','title','standfirst','content_html','featured_image','featured_image_alt','featured_image_caption','video_url','series_name','writer_note','source_notes','travel_country','travel_city','seo_title','seo_description','is_selected','published_at'];
}

function writer_is_public(array $post): bool {
    return in_array($post['status'] ?? '', ['published','scheduled'], true)
        && !empty($post['published_at']) && strtotime($post['published_at']) <= time();
}

function writer_merge_draft(array $post): array {
    $post['_public_status'] = $post['status'];
    $post['_public_title'] = $post['title'];
    $post['_is_public'] = writer_is_public($post);
    $post['_draft_version'] = (int)($post['draft_version'] ?? 0);
    $draft = !empty($post['draft_payload']) ? json_decode($post['draft_payload'], true, 32, JSON_THROW_ON_ERROR) : null;
    $post['_has_draft'] = is_array($draft);
    if (is_array($draft)) {
        foreach (writer_editable_fields() as $field) if (array_key_exists($field, $draft)) $post[$field] = $draft[$field];
    }
    unset($post['draft_payload']);
    $post['content_html'] = sanitize_html((string)($post['content_html'] ?? ''));
    return $post;
}

function writer_post_by_id(int $id): ?array {
    $st = db()->prepare('SELECT p.*,d.payload draft_payload,d.version draft_version,d.updated_at draft_updated_at FROM posts p LEFT JOIN post_drafts d ON d.post_id=p.id WHERE p.id=?');
    $st->execute([$id]);
    $post = $st->fetch();
    return $post ? writer_merge_draft($post) : null;
}

function writer_posts(int $limit = 200): array {
    $rows = db()->query('SELECT p.*,c.name category_name,d.payload draft_payload,d.version draft_version,d.updated_at draft_updated_at FROM posts p LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN post_drafts d ON d.post_id=p.id ORDER BY GREATEST(p.updated_at,COALESCE(d.updated_at,p.updated_at)) DESC LIMIT '.max(1,min(500,$limit)))->fetchAll();
    return array_map('writer_merge_draft', $rows);
}

function writer_post_fields(array $input, array $existing, ?string $image): array {
    $fields = [];
    $limits = ['title'=>240,'standfirst'=>8000,'featured_image_alt'=>500,'featured_image_caption'=>500,'video_url'=>1000,'series_name'=>220,'writer_note'=>16000,'source_notes'=>100000,'travel_country'=>120,'travel_city'=>120,'seo_title'=>255,'seo_description'=>320];
    foreach ($limits as $key=>$limit) {
        if (isset($input[$key]) && !is_string($input[$key])) throw new InvalidArgumentException('Invalid editor field.');
        $fields[$key] = trim($input[$key] ?? '');
        if (mb_strlen($fields[$key]) > $limit) throw new InvalidArgumentException('The '.str_replace('_',' ',$key).' is too long.');
    }
    if ($fields['title'] === '') throw new InvalidArgumentException('Add a title before saving.');
    $html = $input['content_html'] ?? '';
    if (!is_string($html) || strlen($html) > 2000000) throw new InvalidArgumentException('The article is too large.');
    $fields['content_html'] = sanitize_html($html);
    $fields['featured_image'] = $image ?? ($existing['featured_image'] ?? null);
    if ($fields['video_url'] !== '' && (!safe_content_url($fields['video_url']) || !preg_match('~^https?://~i',$fields['video_url']))) throw new InvalidArgumentException('Enter a valid HTTPS or HTTP video URL.');
    $categoryId = filter_var($input['category_id'] ?? 0, FILTER_VALIDATE_INT);
    if ($categoryId === false || $categoryId < 0) throw new InvalidArgumentException('Choose a valid topic.');
    if ($categoryId) {
        $st = db()->prepare('SELECT id FROM categories WHERE id=?'); $st->execute([$categoryId]);
        if (!$st->fetchColumn()) throw new InvalidArgumentException('Choose a valid topic.');
    }
    $fields['category_id'] = $categoryId ?: null;
    $fields['is_selected'] = !empty($input['is_selected']) ? 1 : 0;
    $raw = $input['published_at'] ?? '';
    if (!is_string($raw)) throw new InvalidArgumentException('Choose a valid publication date.');
    if (writer_is_public($existing)) {
        // A revised title never changes the public URL or original publication date.
        $fields['published_at'] = $existing['published_at'];
    } elseif ($raw === '') {
        $fields['published_at'] = date('Y-m-d H:i:s');
    } else {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d\TH:i') !== $raw) throw new InvalidArgumentException('Choose a valid publication date.');
        $fields['published_at'] = $date->format('Y-m-d H:i:s');
    }
    return array_intersect_key($fields, array_flip(writer_editable_fields()));
}

/** Save a private working copy, or explicitly replace the public snapshot.
 * The version survives publishing, so a delayed autosave cannot undo a publish.
 * Optional writer_after_publish($postId, $fields) runs inside this transaction.
 */
function writer_save_post(array $input, int $userId, bool $publish, ?string $image = null, ?array $preparedMetadata = null): array {
    $publish = $publish && empty($input['autosave']);
    $id = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT);
    if ($id === false || $id < 0) throw new InvalidArgumentException('Invalid article.');
    $expected = filter_var($input['draft_version'] ?? null, FILTER_VALIDATE_INT);
    if ($id && ($expected === false || $expected === null || $expected < 0)) throw new WriterConflict('Reload the editor before saving this article.');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $existing = ['status'=>'draft'];
        if ($id) {
            $st = $pdo->prepare('SELECT * FROM posts WHERE id=? FOR UPDATE'); $st->execute([$id]);
            $existing = $st->fetch();
            if (!$existing) throw new InvalidArgumentException('The article could not be found.');
            $st = $pdo->prepare('SELECT payload,version FROM post_drafts WHERE post_id=? FOR UPDATE'); $st->execute([$id]);
            $draft = $st->fetch();
            $version = (int)($draft['version'] ?? 0);
            if ($version !== $expected) throw new WriterConflict('A newer version was saved in another tab. Copy your changes, then reload before saving.');
            $working = writer_merge_draft($existing + ['draft_payload'=>$draft['payload'] ?? null,'draft_version'=>$version]);
        } else {
            $working = $existing;
            $version = 0;
        }
        // Use the public date/status for published records, but preserve the draft image.
        $base = $working;
        $base['status'] = $existing['status'];
        if (writer_is_public($existing)) $base['published_at'] = $existing['published_at'];
        $fields = writer_post_fields($input, $base, $image);
        if ($publish && !$fields['category_id']) throw new InvalidArgumentException('Choose a topic before publishing.');
        if ($publish && plain_text($fields['content_html']) === '' && !str_contains($fields['content_html'], '<img')) throw new InvalidArgumentException('Add the article before publishing.');
        if (!$id) {
            $slug = unique_post_slug($fields['title']);
            $st = $pdo->prepare("INSERT INTO posts(user_id,title,slug,content_html,status) VALUES(?,?,?,'','draft')");
            $st->execute([$userId,$fields['title'],$slug]);
            $id = (int)$pdo->lastInsertId();
        }
        $status = $existing['status'];
        if ($publish) {
            // Optional configured assistance is prepared before this transaction.
            // Local fallback remains available without external dependencies.
            $category = $pdo->prepare('SELECT name FROM categories WHERE id=?'); $category->execute([$fields['category_id']]);
            $tags = fallback_tags($fields['title'].' '.$fields['standfirst'].' '.plain_text($fields['content_html']), (string)$category->fetchColumn());
            if (is_array($preparedMetadata['tags']??null)) {
                $preparedTags=array_values(array_filter(array_map(static fn($tag)=>is_string($tag)?mb_substr(trim(strip_tags($tag)),0,80):'',array_slice($preparedMetadata['tags'],0,12))));
                if ($preparedTags) $tags=$preparedTags;
            }
            if ($fields['seo_title'] === '') $fields['seo_title'] = is_string($preparedMetadata['seo_title']??null) && trim($preparedMetadata['seo_title'])!=='' ? mb_substr(trim($preparedMetadata['seo_title']),0,255) : $fields['title'];
            if ($fields['seo_description'] === '') $fields['seo_description'] = is_string($preparedMetadata['seo_description']??null) && trim($preparedMetadata['seo_description'])!=='' ? mb_substr(trim($preparedMetadata['seo_description']),0,320) : excerpt($fields['standfirst'] ?: $fields['content_html'], 155);
            if (!empty($existing['id'])) $pdo->prepare('INSERT INTO post_revisions(post_id,title,standfirst,content_html) VALUES(?,?,?,?)')->execute([$id,$existing['title'],$existing['standfirst'],$existing['content_html']]);
            $status = strtotime($fields['published_at']) > time() ? 'scheduled' : 'published';
            $assignments = implode(',', array_map(static fn($key)=>$key.'=?', array_keys($fields)));
            $pdo->prepare('UPDATE posts SET '.$assignments.',status=?,updated_at=NOW() WHERE id=?')->execute(array_merge(array_values($fields),[$status,$id]));
            assign_tags($id,$tags);
            if (function_exists('writer_after_publish')) writer_after_publish($id,$fields);
        }
        $version++;
        $payload = $publish ? null : json_encode($fields, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $pdo->prepare('INSERT INTO post_drafts(post_id,payload,version,updated_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),version=VALUES(version),updated_by=VALUES(updated_by),updated_at=NOW()')->execute([$id,$payload,$version,$userId]);
        $pdo->commit();
        return ['id'=>$id,'version'=>$version,'status'=>$status,'published'=>$publish];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function private_response_headers(): void {
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Referrer-Policy: no-referrer');
}

function render_writer_preview(array $post): void {
    private_response_headers();
    $post['tags'] = post_tags((int)$post['id']);
    $st = db()->prepare('SELECT name,slug FROM categories WHERE id=?'); $st->execute([$post['category_id']]);
    $category = $st->fetch() ?: [];
    $post['category_name'] = $category['name'] ?? null; $post['category_slug'] = $category['slug'] ?? null;
    $post['published_at'] = $post['published_at'] ?: date('Y-m-d H:i:s');
    $related = [];
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Private preview · '.e($post['title']).'</title><link rel="stylesheet" href="'.e(asset('css/site.css')).'"><style>[data-reveal]{opacity:1;transform:none}</style></head><body><p class="container">Private saved preview · <a href="'.e(url('/write/edit/'.$post['id'])).'">Return to editor</a></p><main>';
    require __DIR__.'/../views/post.php';
    echo '</main></body></html>';
}

function assign_tags(int $postId, array $tagNames): void {
    $clean=[]; foreach($tagNames as $tag){$tag=trim((string)$tag);if($tag!==''&&mb_strlen($tag)<=120)$clean[mb_strtolower($tag)]=$tag;}
    db()->prepare('DELETE FROM post_tags WHERE post_id=?')->execute([$postId]);
    foreach(array_slice(array_values($clean),0,12) as $name){
        $slug=slugify($name); if($slug==='')continue;
        db()->prepare('INSERT INTO tags(name,slug) VALUES(?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)')->execute([$name,$slug]);
        $st=db()->prepare('SELECT id FROM tags WHERE slug=?');$st->execute([$slug]);$id=$st->fetchColumn();
        if($id)db()->prepare('INSERT IGNORE INTO post_tags(post_id,tag_id) VALUES(?,?)')->execute([$postId,$id]);
    }
}

function related_posts(array $post, int $limit=3): array {
    $sql='SELECT DISTINCT p.*,c.name category_name,c.slug category_slug FROM posts p LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN post_tags pt ON pt.post_id=p.id WHERE '.published_where().' AND p.id<>? AND (p.category_id=? OR pt.tag_id IN (SELECT tag_id FROM post_tags WHERE post_id=?)) ORDER BY (p.category_id=? ) DESC,p.published_at DESC LIMIT '.max(1,min(6,$limit));
    $st=db()->prepare($sql);$st->execute([$post['id'],$post['category_id'],$post['id'],$post['category_id']]);return $st->fetchAll();
}

function render(string $view, array $data=[]): void {
    header('Content-Type: text/html; charset=UTF-8');
    extract($data, EXTR_SKIP);
    $viewFile=__DIR__.'/../views/'.$view.'.php'; if(!file_exists($viewFile))throw new RuntimeException('View not found: '.$view);
    ob_start(); require $viewFile; $content=ob_get_clean();
    require __DIR__.'/../views/layout.php';
}
function render_write(string $view, array $data=[]): void {
    extract($data, EXTR_SKIP); $viewFile=__DIR__.'/../views/write/'.$view.'.php'; ob_start();require $viewFile;$content=ob_get_clean();require __DIR__.'/../views/write/layout.php';
}

function seo_defaults(): array {
    return [
        'title'=>'Waithaka Ndegwa',
        'description'=>'Waithaka Ndegwa shares personal experience and views from Africa on social entrepreneurship, agribusiness, infrastructure, innovation and travel, connecting East African realities with global ideas.',
        'canonical'=>url('/'),
        'image'=>asset('images/social-default.png'),
        'type'=>'website',
    ];
}

function social_links(): array {
    return [
      'LinkedIn'=>'https://www.linkedin.com/in/iwaithakaw/',
      'Facebook'=>'https://www.facebook.com/iwaithakaw',
      'X'=>'https://x.com/iwaithakaw',
      'Instagram'=>'https://www.instagram.com/iwaithakaw',
      'YouTube'=>'https://www.youtube.com/@waithakandegwa',
      'TikTok'=>'https://www.tiktok.com/@waithakandegwa',
    ];
}

function featured_image_url(?string $path): string { if(!$path)return asset('images/social-default.png'); if(preg_match('~^https?://~',$path))return $path; return url(ltrim($path,'/')); }
function responsive_image_attrs(?string $source,string $sizes='(max-width: 720px) 100vw, 50vw'): string {
    $attributes=function_exists('discovery_image_attributes')?discovery_image_attributes($source,[],$sizes):['src'=>featured_image_url($source)];
    $html=[];foreach($attributes as$key=>$value)if(in_array($key,['src','width','height','srcset','sizes'],true))$html[]=$key.'="'.e((string)$value).'"';
    return implode(' ',$html);
}

function video_embed(?string $urlValue): ?array {
    $u=trim((string)$urlValue); if($u==='')return null;
    if(preg_match('~(?:youtube\.com/(?:watch\?v=|shorts/|embed/)|youtu\.be/)([A-Za-z0-9_-]{6,})~',$u,$m)) return ['provider'=>'YouTube','embed'=>'https://www.youtube-nocookie.com/embed/'.$m[1]];
    if(preg_match('~vimeo\.com/(?:video/)?([0-9]+)~',$u,$m)) return ['provider'=>'Vimeo','embed'=>'https://player.vimeo.com/video/'.$m[1]];
    return ['provider'=>'Link','url'=>$u];
}

function upload_featured_image(array $file): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('Image upload failed.');
    if (($file['size'] ?? 0) > 8*1024*1024) throw new RuntimeException('Image must be 8 MB or smaller.');
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Invalid image upload.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if(!isset($allowed[$mime]))throw new RuntimeException('Use JPG, PNG or WebP images.');
    $size=@getimagesize($file['tmp_name']);
    if(!$size || $size[0]<1 || $size[1]<1 || $size[0]*$size[1]>40000000)throw new RuntimeException('Use an image no larger than 40 megapixels.');
    $source=$file['tmp_name'];$temporary=null;$extension=$allowed[$mime];
    $image=null;
    if(function_exists('imagecreatefromjpeg')&&$mime==='image/jpeg')$image=@imagecreatefromjpeg($source);
    elseif(function_exists('imagecreatefrompng')&&$mime==='image/png')$image=@imagecreatefrompng($source);
    elseif(function_exists('imagecreatefromwebp')&&$mime==='image/webp')$image=@imagecreatefromwebp($source);
    try {
        if($image && function_exists('imagewebp')){
            $w=imagesx($image);$h=imagesy($image);$scale=min(1,1600/max($w,$h));$nw=max(1,(int)round($w*$scale));$nh=max(1,(int)round($h*$scale));
            $out=imagecreatetruecolor($nw,$nh);imagealphablending($out,false);imagesavealpha($out,true);imagecopyresampled($out,$image,0,0,0,0,$nw,$nh,$w,$h);
            $temporary=tempnam(sys_get_temp_dir(),'wk-image-');
            if(!$temporary || !imagewebp($out,$temporary,80)){imagedestroy($out);throw new RuntimeException('Unable to prepare uploaded image.');}
            imagedestroy($out);$source=$temporary;$extension='webp';$mime='image/webp';
        }
        $filename=bin2hex(random_bytes(12)).'.'.$extension;
        if(function_exists('media_store_upload'))return media_store_upload($source,$filename,$mime);
        $relative='assets/uploads/'.date('Y/m').'/'.$filename;$destination=__DIR__.'/../'.$relative;$directory=dirname($destination);
        if(!is_dir($directory)&&!mkdir($directory,0755,true)&&!is_dir($directory))throw new RuntimeException('Unable to create upload folder.');
        $saved=$temporary ? copy($source,$destination) : move_uploaded_file($source,$destination);
        if(!$saved)throw new RuntimeException('Unable to save uploaded image.');
        return '/'.$relative;
    } finally {
        if($image)imagedestroy($image);
        if($temporary&&is_file($temporary))unlink($temporary);
    }
}

function country_options(): array {
    return [
'Kenya','Afghanistan','Albania','Algeria','Andorra','Angola','Antigua and Barbuda','Argentina','Armenia','Australia','Austria','Azerbaijan','Bahamas','Bahrain','Bangladesh','Barbados','Belarus','Belgium','Belize','Benin','Bhutan','Bolivia','Bosnia and Herzegovina','Botswana','Brazil','Brunei','Bulgaria','Burkina Faso','Burundi','Cabo Verde','Cambodia','Cameroon','Canada','Central African Republic','Chad','Chile','China','Colombia','Comoros','Congo (DRC)','Congo (Republic)','Costa Rica','Côte d’Ivoire','Croatia','Cuba','Cyprus','Czechia','Denmark','Djibouti','Dominica','Dominican Republic','Ecuador','Egypt','El Salvador','Equatorial Guinea','Eritrea','Estonia','Eswatini','Ethiopia','Fiji','Finland','France','Gabon','Gambia','Georgia','Germany','Ghana','Greece','Grenada','Guatemala','Guinea','Guinea-Bissau','Guyana','Haiti','Honduras','Hungary','Iceland','India','Indonesia','Iran','Iraq','Ireland','Israel','Italy','Jamaica','Japan','Jordan','Kazakhstan','Kiribati','Kuwait','Kyrgyzstan','Laos','Latvia','Lebanon','Lesotho','Liberia','Libya','Liechtenstein','Lithuania','Luxembourg','Madagascar','Malawi','Malaysia','Maldives','Mali','Malta','Marshall Islands','Mauritania','Mauritius','Mexico','Micronesia','Moldova','Monaco','Mongolia','Montenegro','Morocco','Mozambique','Myanmar','Namibia','Nauru','Nepal','Netherlands','New Zealand','Nicaragua','Niger','Nigeria','North Korea','North Macedonia','Norway','Oman','Pakistan','Palau','Panama','Papua New Guinea','Paraguay','Peru','Philippines','Poland','Portugal','Qatar','Romania','Russia','Rwanda','Saint Kitts and Nevis','Saint Lucia','Saint Vincent and the Grenadines','Samoa','San Marino','São Tomé and Príncipe','Saudi Arabia','Senegal','Serbia','Seychelles','Sierra Leone','Singapore','Slovakia','Slovenia','Solomon Islands','Somalia','South Africa','South Korea','South Sudan','Spain','Sri Lanka','Sudan','Suriname','Sweden','Switzerland','Syria','Taiwan','Tajikistan','Tanzania','Thailand','Timor-Leste','Togo','Tonga','Trinidad and Tobago','Tunisia','Türkiye','Turkmenistan','Tuvalu','Uganda','Ukraine','United Arab Emirates','United Kingdom','United States','Uruguay','Uzbekistan','Vanuatu','Vatican City','Venezuela','Vietnam','Yemen','Zambia','Zimbabwe'
    ];
}

function contact_rate_limited(): bool {
    $st=db()->prepare('SELECT COUNT(*) FROM contact_messages WHERE ip_hash=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)');$st->execute([ip_hash()]);return (int)$st->fetchColumn()>=5;
}
function make_reference(string $prefix): string { return strtoupper($prefix).'-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(6)),0,10)); }

function send_contact_email(array $m): bool {
    $to=trim((string)setting('contact.recipient','')); if($to===''||!filter_var($to,FILTER_VALIDATE_EMAIL))return false;
    $subject='Website message '.$m['reference'].' — '.$m['first_name'].' '.$m['last_name'];
    $body="New message from waithakandegwa.com\n\nReference: {$m['reference']}\nName: {$m['first_name']} {$m['last_name']}\nEmail: {$m['email']}\nPhone: {$m['phone_code']} {$m['phone']}\nLocation: {$m['city']}, {$m['country']}\n\nMessage:\n{$m['message']}\n";
    $from=trim((string)setting('mail.from','noreply@waithakandegwa.com')); if(!filter_var($from,FILTER_VALIDATE_EMAIL))$from='noreply@waithakandegwa.com';
    $safeReply=str_replace(["\r","\n"],'',$m['email']);
    if(function_exists('enqueue_email')){try{enqueue_email($to,$subject,'<pre>'.e($body).'</pre>','contact:'.$m['reference']);return true;}catch(Throwable $e){error_log('Contact email queue failed for '.$m['reference']);return false;}}
    $headers=['From: Waithaka Ndegwa Website <'.$from.'>','Reply-To: '.$safeReply,'Content-Type: text/plain; charset=UTF-8'];
    return @mail($to,$subject,$body,implode("\r\n",$headers));
}

function recaptcha_ok(): bool {
    $secret=(string)setting('recaptcha.secret','');
    if($secret==='') return !empty($_POST['not_robot']);
    $token=$_POST['g-recaptcha-response']??''; if($token==='')return false;
    $payload=http_build_query(['secret'=>$secret,'response'=>$token,'remoteip'=>$_SERVER['REMOTE_ADDR']??'']);
    $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>'Content-Type: application/x-www-form-urlencoded','content'=>$payload,'timeout'=>8]]);
    $raw=@file_get_contents('https://www.google.com/recaptcha/api/siteverify',false,$ctx);$data=$raw?json_decode($raw,true):null;return !empty($data['success']);
}

function payment_payload_for_storage(array $payload): string {
    $sensitive=['email','email_address','phone','phone_number','first_name','last_name','given_name','surname','full_name','address_line_1','address_line_2'];
    $walk=function($value) use (&$walk,$sensitive){
        if(!is_array($value)) return $value;
        if(isset($value['Name'],$value['Value']) && in_array(strtolower((string)$value['Name']),['phonenumber','phone_number','email','emailaddress','email_address'],true)){
            $value['Value']='[redacted]';
        }
        $out=[];
        foreach($value as $k=>$v){
            $key=strtolower((string)$k);
            $out[$k]=in_array($key,$sensitive,true)?'[redacted]':$walk($v);
        }
        return $out;
    };
    return json_encode($walk($payload),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?: '{}';
}

function json_response(array $data,int $code=200): never { http_response_code($code);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit; }

function media_release_by_id(int $id): ?array {
    $st=db()->prepare('SELECT * FROM media_releases WHERE id=? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function media_release_by_token(string $token): ?array {
    if(!preg_match('/^[a-f0-9]{64}$/',$token)) return null;
    $st=db()->prepare('SELECT * FROM media_releases WHERE public_token=? LIMIT 1');
    $st->execute([$token]);
    return $st->fetch() ?: null;
}

function media_release_can_sign(array $release, ?int $now = null): bool {
    return ($release['status'] ?? '') === 'sent' && empty($release['signed_at'])
        && (empty($release['expires_at']) || strtotime($release['expires_at']) >= ($now ?? time()));
}

function media_release_payments(int $releaseId): array {
    $st=db()->prepare('SELECT * FROM media_release_payments WHERE release_id=? ORDER BY paid_at DESC,id DESC');
    $st->execute([$releaseId]);
    return $st->fetchAll();
}

function media_release_events(int $releaseId): array {
    $st=db()->prepare('SELECT e.*,u.name actor_name FROM media_release_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.release_id=? ORDER BY e.created_at DESC,e.id DESC');
    $st->execute([$releaseId]);
    return $st->fetchAll();
}

function log_release_event(int $releaseId,string $eventType,string $note='',string $actorType='system',?int $actorUserId=null): void {
    $st=db()->prepare('INSERT INTO media_release_events(release_id,event_type,event_note,actor_type,actor_user_id,ip_hash) VALUES(?,?,?,?,?,?)');
    $st->execute([$releaseId,mb_substr($eventType,0,80),mb_substr($note,0,500),$actorType,$actorUserId,ip_hash()]);
}

function media_release_url(array $release): string {
    return url('/consent/'.$release['public_token']);
}

function upload_release_proof(array $file): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('Proof upload failed.');
    if (($file['size'] ?? 0) > 5*1024*1024) throw new RuntimeException('Proof must be 5 MB or smaller.');
    $finfo=new finfo(FILEINFO_MIME_TYPE);$mime=$finfo->file($file['tmp_name']);
    $allowed=['image/jpeg'=>'jpg','image/png'=>'png','application/pdf'=>'pdf'];
    if(!isset($allowed[$mime])) throw new RuntimeException('Use JPG, PNG or PDF for payment proof.');
    if(empty($file['tmp_name'])||!is_uploaded_file($file['tmp_name']))throw new RuntimeException('Invalid proof upload.');
    if(function_exists('media_store_private_upload'))return media_store_private_upload($file['tmp_name'],bin2hex(random_bytes(16)).'.'.$allowed[$mime],$mime);
    $dir=__DIR__.'/../storage/release-proofs/'.date('Y/m');
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir)) throw new RuntimeException('Unable to create proof folder.');
    $name=bin2hex(random_bytes(16)).'.'.$allowed[$mime];
    $dest=$dir.'/'.$name;
    if(!move_uploaded_file($file['tmp_name'],$dest)) throw new RuntimeException('Unable to save payment proof.');
    return 'storage/release-proofs/'.date('Y/m').'/'.$name;
}

function release_consent_version(): string { return '2026-08-31-v1'; }

function release_consent_text(array $release): string {
    $items=[];
    if(!empty($release['allow_photo']))$items[]='photographs/images';
    if(!empty($release['allow_video']))$items[]='video';
    if(!empty($release['allow_audio']))$items[]='audio/recorded conversation';
    if(!empty($release['allow_quotes']))$items[]='quoted or paraphrased conversation excerpts';
    $scope=$items?implode(', ',$items):'the selected material';
    $purpose=trim((string)($release['purpose_note']??''));
    if($purpose==='')$purpose='editorial, educational and documentary use connected with Waithaka Ndegwa’s personal website, published writing and related social-media promotion of that writing';
    return "I consent to the use of the options I select below from {$scope} for {$purpose}. Unless the purpose above says otherwise, this may include waithakandegwa.com and Waithaka Ndegwa’s personal social-media accounts, which can be viewed worldwide. Photographs or video may be cropped or edited for format and clarity but not materially altered to misrepresent me; quoted material may be shortened or edited for clarity without changing its meaning. The material will not be sold or licensed to unrelated third parties, and my likeness will not be used to create a synthetic or AI-generated version of me without separate permission. I may choose how I am identified, I do not have to agree to every requested use, and any appreciation or expense reimbursement is separate from my choice of consent. I may request withdrawal of consent for future use by contacting Waithaka Ndegwa. Material already lawfully published or distributed may not always be removable from every existing copy, cache, repost or third-party platform. My personal details will be kept only as reasonably necessary to document this consent, respond to a withdrawal request, and protect legitimate legal or record-keeping interests.";
}

function notify_writer(string $subject,string $body): bool {
    $to=trim((string)setting('contact.recipient',''));if($to===''||!filter_var($to,FILTER_VALIDATE_EMAIL))return false;
    $from=trim((string)setting('mail.from','noreply@waithakandegwa.com'));if(!filter_var($from,FILTER_VALIDATE_EMAIL))$from='noreply@waithakandegwa.com';
    if(function_exists('enqueue_email')){try{enqueue_email($to,$subject,'<pre>'.e($body).'</pre>','notify:'.hash('sha256',$subject.'|'.$body));return true;}catch(Throwable $e){error_log('Writer notification queue failed.');return false;}}
    $headers=['From: Waithaka Ndegwa Website <'.$from.'>','Content-Type: text/plain; charset=UTF-8'];
    return @mail($to,$subject,$body,implode("\r\n",$headers));
}
