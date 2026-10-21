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
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function e(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function base_url(): string { return rtrim((string)app_config('base_url', 'https://www.waithakandegwa.com'), '/'); }
function url(string $path = ''): string { return base_url() . '/' . ltrim($path, '/'); }
function asset(string $path): string { return url('assets/' . ltrim($path, '/')); }
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
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function verify_csrf(): void {
    $provided = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)$provided)) {
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
    return $user = ($st->fetch() ?: null);
}
function require_auth(): void { if (!current_user()) redirect(url('/write/login')); }

function ip_hash(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
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
        session_regenerate_id(true); $_SESSION['user_id'] = $user['id'];
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

function sanitize_html(string $html): string {
    $html = trim($html);
    if ($html === '') return '';
    $allowedTags = ['p','h2','h3','h4','blockquote','ul','ol','li','strong','b','em','i','a','br','hr','figure','figcaption','img','pre','code'];
    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?><div id="wk-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $root = $dom->getElementById('wk-root');
    if (!$root) return strip_tags($html, '<p><h2><h3><h4><blockquote><ul><ol><li><strong><em><a><br><hr><pre><code>');
    $walk = function(DOMNode $node) use (&$walk, $allowedTags): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (!in_array($tag, $allowedTags, true) && $child->parentNode) {
                    while ($child->firstChild) $child->parentNode->insertBefore($child->firstChild, $child);
                    $child->parentNode->removeChild($child); continue;
                }
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $name = strtolower($attr->name);
                    $keep = false;
                    if ($tag === 'a' && in_array($name,['href','title','target','rel'],true)) $keep = true;
                    if ($tag === 'img' && in_array($name,['src','alt','title','loading','width','height'],true)) $keep = true;
                    if (!$keep) $child->removeAttribute($attr->name);
                }
                if ($tag === 'a') {
                    $href = $child->getAttribute('href');
                    if ($href && !preg_match('~^(https?://|mailto:|/|#)~i',$href)) $child->removeAttribute('href');
                    if ($child->getAttribute('target') === '_blank') $child->setAttribute('rel','noopener noreferrer');
                }
                if ($tag === 'img') {
                    $src = $child->getAttribute('src');
                    if ($src && !preg_match('~^(https?://|/assets/uploads/)~i',$src)) $child->removeAttribute('src');
                    $child->setAttribute('loading','lazy');
                }
                $walk($child);
            }
        }
    };
    $walk($root);
    $out=''; foreach ($root->childNodes as $child) $out .= $dom->saveHTML($child);
    return trim($out);
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
function latest_posts(int $limit=6, ?int $categoryId=null): array {
    $sql='SELECT p.*,c.name category_name,c.slug category_slug FROM posts p LEFT JOIN categories c ON c.id=p.category_id WHERE '.published_where();
    $params=[]; if ($categoryId) { $sql.=' AND p.category_id=?'; $params[]=$categoryId; }
    $sql.=' ORDER BY p.published_at DESC LIMIT '.max(1,min(5000,$limit));
    $st=db()->prepare($sql);$st->execute($params);return $st->fetchAll();
}
function selected_posts(int $limit=3): array {
    $sql='SELECT p.*,c.name category_name,c.slug category_slug FROM posts p LEFT JOIN categories c ON c.id=p.category_id WHERE '.published_where().' AND p.is_selected=1 ORDER BY p.published_at DESC LIMIT '.max(1,min(12,$limit));
    return db()->query($sql)->fetchAll();
}
function post_by_slug(string $slug): ?array {
    $st=db()->prepare('SELECT p.*,c.name category_name,c.slug category_slug,u.name author_name FROM posts p LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN users u ON u.id=p.user_id WHERE p.slug=? AND '.published_where().' LIMIT 1');
    $st->execute([$slug]); $p=$st->fetch(); if(!$p)return null; $p['tags']=post_tags((int)$p['id']); return $p;
}
function post_tags(int $postId): array { $st=db()->prepare('SELECT t.* FROM tags t JOIN post_tags pt ON pt.tag_id=t.id WHERE pt.post_id=? ORDER BY t.name');$st->execute([$postId]);return $st->fetchAll(); }

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
      'YouTube'=>'https://www.youtube.com/@iwaithakaw',
      'TikTok'=>'https://www.tiktok.com/@iwaithakaw',
    ];
}

function featured_image_url(?string $path): string { if(!$path)return asset('images/social-default.png'); if(preg_match('~^https?://~',$path))return $path; return url(ltrim($path,'/')); }

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
    $finfo=new finfo(FILEINFO_MIME_TYPE);$mime=$finfo->file($file['tmp_name']);
    $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp']; if(!isset($allowed[$mime]))throw new RuntimeException('Use JPG, PNG or WebP images.');
    $dir=__DIR__.'/../assets/uploads/'.date('Y/m'); if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir))throw new RuntimeException('Unable to create upload folder.');
    $name=bin2hex(random_bytes(12)); $rel='assets/uploads/'.date('Y/m').'/'.$name.'.webp'; $dest=__DIR__.'/../'.$rel;
    $src=null; if(function_exists('imagecreatefromjpeg')&&$mime==='image/jpeg')$src=@imagecreatefromjpeg($file['tmp_name']); elseif(function_exists('imagecreatefrompng')&&$mime==='image/png')$src=@imagecreatefrompng($file['tmp_name']); elseif(function_exists('imagecreatefromwebp')&&$mime==='image/webp')$src=@imagecreatefromwebp($file['tmp_name']);
    if($src && function_exists('imagewebp')){
        $w=imagesx($src);$h=imagesy($src);$max=1600;$scale=min(1,$max/max($w,$h));$nw=max(1,(int)round($w*$scale));$nh=max(1,(int)round($h*$scale));$out=imagecreatetruecolor($nw,$nh);imagealphablending($out,false);imagesavealpha($out,true);imagecopyresampled($out,$src,0,0,0,0,$nw,$nh,$w,$h);imagewebp($out,$dest,78);imagedestroy($out);imagedestroy($src);
    } else {
        $ext=$allowed[$mime];$rel='assets/uploads/'.date('Y/m').'/'.$name.'.'.$ext;$dest=__DIR__.'/../'.$rel;if(!move_uploaded_file($file['tmp_name'],$dest))throw new RuntimeException('Unable to save uploaded image.');
    }
    return '/'.$rel;
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
    $headers=['From: Waithaka Ndegwa Website <'.$from.'>','Content-Type: text/plain; charset=UTF-8'];
    return @mail($to,$subject,$body,implode("\r\n",$headers));
}
