<?php
declare(strict_types=1);

/**
 * Production-safe failure handler.
 * Logs uncaught errors and shows a useful response instead of a blank page.
 */
function wk_fail_safe(string $message, ?Throwable $exception = null): void {
    static $handled = false;
    if ($handled) return;
    $handled = true;

    $errorId = strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
    $detail = '[' . date('c') . '] [' . $errorId . '] ' . $message;
    if ($exception) {
        $detail .= ' | ' . get_class($exception) . ': ' . $exception->getMessage()
            . ' in ' . $exception->getFile() . ':' . $exception->getLine();
    }
    $detail .= PHP_EOL;

    $logDir = __DIR__ . '/../storage/logs';
    if (is_dir($logDir) && is_writable($logDir)) {
        @file_put_contents($logDir . '/application.log', $detail, FILE_APPEND | LOCK_EX);
    }
    error_log($detail);

    if (PHP_SAPI === 'cli') return;
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (str_starts_with($path, '/payments/')) {
        echo 'Temporary server error. Reference: ' . htmlspecialchars($errorId, ENT_QUOTES, 'UTF-8');
        return;
    }

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Website setup needs attention · Waithaka Ndegwa</title>'
       . '<style>body{margin:0;background:#f7f7f4;color:#17202b;font-family:system-ui,-apple-system,Segoe UI,sans-serif}.w{width:min(720px,calc(100% - 32px));margin:10vh auto}.c{background:#fff;border:1px solid #e1e4e7;border-radius:20px;padding:32px;box-shadow:0 20px 60px rgba(0,0,0,.06)}h1{font-family:Georgia,serif;font-size:clamp(2rem,6vw,3.4rem);margin:.1em 0 .35em}p{line-height:1.65;color:#59636d}.r{font-family:ui-monospace,monospace;background:#f1f3f5;padding:8px 10px;border-radius:8px;display:inline-block;color:#17202b}</style>'
       . '</head><body><main class="w"><section class="c"><h1>The website needs a configuration check.</h1>'
       . '<p>The application encountered a server-side setup error. It has been logged without exposing database credentials or other secrets.</p>'
       . '<p>Error reference: <span class="r">' . htmlspecialchars($errorId, ENT_QUOTES, 'UTF-8') . '</span></p>'
       . '<p>If this is the first installation, confirm that the files are in the domain document root and open <strong>/install/</strong>.</p>'
       . '</section></main></body></html>';
}

set_exception_handler(static function (Throwable $e): void {
    wk_fail_safe('Uncaught application exception', $e);
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!$error) return;
    if (!in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    wk_fail_safe('Fatal PHP error: ' . ($error['message'] ?? 'unknown') . ' in ' . ($error['file'] ?? 'unknown') . ':' . ($error['line'] ?? 0));
});

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

$CONFIG_FILE = __DIR__ . '/config.php';
$APP_INSTALLED = file_exists($CONFIG_FILE) && file_exists(__DIR__ . '/../storage/installed.lock');
$CONFIG = $APP_INSTALLED ? require $CONFIG_FILE : [];

date_default_timezone_set($CONFIG['timezone'] ?? 'Africa/Nairobi');

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/integrations.php';


if ($APP_INSTALLED) {
    $migrationLock = __DIR__ . '/../storage/migration-paypal-v1.lock';
    if (!file_exists($migrationLock)) {
        try {
            db()->exec("ALTER TABLE contributions MODIFY method ENUM('mpesa','pesapal','paypal') NOT NULL");
            @file_put_contents($migrationLock, 'Applied '.date(DATE_ATOM)."\n", LOCK_EX);
        } catch (Throwable $e) {
            error_log('Schema migration (PayPal): '.$e->getMessage());
        }
    }
}

if ($APP_INSTALLED) {
    $migrationLock = __DIR__ . '/../storage/migration-media-consent-v1.lock';
    if (!file_exists($migrationLock)) {
        try {
            $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS media_releases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(40) NOT NULL UNIQUE,
  public_token CHAR(64) NOT NULL UNIQUE,
  title VARCHAR(220) NOT NULL,
  context_note TEXT NULL,
  purpose_note TEXT NULL,
  allow_photo TINYINT(1) NOT NULL DEFAULT 1,
  allow_video TINYINT(1) NOT NULL DEFAULT 0,
  allow_audio TINYINT(1) NOT NULL DEFAULT 0,
  allow_quotes TINYINT(1) NOT NULL DEFAULT 0,
  requested_attribution ENUM('full_name','first_name','anonymous','signer_choice') NOT NULL DEFAULT 'signer_choice',
  status ENUM('draft','sent','signed','withdrawal_requested','closed') NOT NULL DEFAULT 'draft',
  expires_at DATETIME NULL,
  signer_name VARCHAR(190) NULL,
  signer_contact VARCHAR(190) NULL,
  signer_country VARCHAR(120) NULL,
  signer_capacity ENUM('self','parent_guardian') NOT NULL DEFAULT 'self',
  participant_name VARCHAR(190) NULL,
  signer_attribution ENUM('full_name','first_name','anonymous') NULL,
  consent_photo TINYINT(1) NULL,
  consent_video TINYINT(1) NULL,
  consent_audio TINYINT(1) NULL,
  consent_quotes TINYINT(1) NULL,
  consent_version VARCHAR(40) NULL,
  consent_snapshot LONGTEXT NULL,
  signature_text VARCHAR(190) NULL,
  signed_ip_hash CHAR(64) NULL,
  signed_user_agent VARCHAR(500) NULL,
  signed_at DATETIME NULL,
  withdrawal_requested_at DATETIME NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_media_release_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_release_status_date (status, created_at),
  INDEX idx_release_signed (signed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media_release_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  release_id BIGINT UNSIGNED NOT NULL,
  method ENUM('mpesa','bank') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'KES',
  recipient_name VARCHAR(190) NULL,
  recipient_destination VARCHAR(190) NULL,
  institution VARCHAR(190) NULL,
  transaction_reference VARCHAR(190) NOT NULL,
  paid_at DATETIME NOT NULL,
  proof_path VARCHAR(500) NULL,
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_release_payment_release FOREIGN KEY (release_id) REFERENCES media_releases(id) ON DELETE CASCADE,
  CONSTRAINT fk_release_payment_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_release_payment_release (release_id, paid_at),
  INDEX idx_release_payment_reference (transaction_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media_release_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  release_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  event_note VARCHAR(500) NULL,
  actor_type ENUM('writer','signer','system') NOT NULL DEFAULT 'system',
  actor_user_id BIGINT UNSIGNED NULL,
  ip_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_release_event_release FOREIGN KEY (release_id) REFERENCES media_releases(id) ON DELETE CASCADE,
  CONSTRAINT fk_release_event_user FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_release_event_release (release_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
            foreach (preg_split('/;\s*(?:\r?\n|$)/', trim($sql)) as $statement) {
                if (trim($statement) !== '') db()->exec($statement);
            }
            @file_put_contents($migrationLock, 'Applied '.date(DATE_ATOM)."\n", LOCK_EX);
        } catch (Throwable $e) {
            error_log('Schema migration (media consent): '.$e->getMessage());
        }
    }
}

if ($APP_INSTALLED) {
    $migrationLock = __DIR__ . '/../storage/migration-writer-planning-v1.lock';
    if (!file_exists($migrationLock)) {
        try {
            $cols=db()->query("SHOW COLUMNS FROM posts")->fetchAll();$names=array_column($cols,'Field');
            if(!in_array('writer_note',$names,true)) db()->exec("ALTER TABLE posts ADD writer_note TEXT NULL AFTER series_name");
            if(!in_array('source_notes',$names,true)) db()->exec("ALTER TABLE posts ADD source_notes LONGTEXT NULL AFTER writer_note");
            @file_put_contents($migrationLock, 'Applied '.date(DATE_ATOM)."\n", LOCK_EX);
        } catch (Throwable $e) { error_log('Schema migration (writer planning): '.$e->getMessage()); }
    }
}

if ($APP_INSTALLED && ($CONFIG['app_env'] ?? 'production') === 'production' && !empty($CONFIG['force_canonical'])) {
    $baseHost = parse_url($CONFIG['base_url'] ?? '', PHP_URL_HOST);
    $currentHost = $_SERVER['HTTP_HOST'] ?? '';
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if ($baseHost && ($currentHost !== $baseHost || !$isHttps)) {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: ' . rtrim((string)$CONFIG['base_url'], '/') . $uri, true, 301);
        exit;
    }
}
