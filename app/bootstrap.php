<?php
declare(strict_types=1);

function wk_fail_safe(string $message, ?Throwable $exception = null): void {
    static $handled = false;
    if ($handled) return;
    $handled = true;
    $id = strtoupper(bin2hex(random_bytes(6)));
    // Provider responses and configuration may contain secrets; omit exception messages.
    error_log('Application error '.$id.' '.($exception ? get_class($exception).' '.$exception->getFile().':'.$exception->getLine() : $message));
    if (PHP_SAPI === 'cli') { fwrite(STDERR, "Application error $id. Check configuration and the private server log.\n"); return; }
    if (!headers_sent()) { http_response_code(503); header('Content-Type: text/html; charset=UTF-8'); header('Cache-Control: no-store, private'); header('Retry-After: 300'); header('X-Robots-Tag: noindex, nofollow'); }
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Temporarily unavailable · Waithaka Ndegwa</title><body><main style="max-width:42rem;margin:12vh auto;padding:2rem;font-family:system-ui;line-height:1.6"><h1>Back shortly.</h1><p>The website is temporarily unavailable. Please try again later.</p><p>Reference: '.htmlspecialchars($id, ENT_QUOTES, 'UTF-8').'</p></main></body></html>';
}
set_exception_handler(static function (Throwable $e): void { wk_fail_safe('Uncaught exception', $e); if (PHP_SAPI === 'cli') exit(1); });
register_shutdown_function(static function (): void { $e=error_get_last(); if ($e && in_array($e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true)) wk_fail_safe('Fatal error in '.basename($e['file']).':'.$e['line']); });
require_once __DIR__.'/environment.php';
$CONFIG_FILE = getenv('APP_CONFIG_FILE') ?: __DIR__.'/config.php';
$CONFIG = is_file($CONFIG_FILE) ? require $CONFIG_FILE : [];
if (!is_array($CONFIG)) throw new RuntimeException('Invalid configuration.');
$CONFIG = wk_environment_config($CONFIG);
$APP_INSTALLED = (is_file($CONFIG_FILE) && is_file(__DIR__.'/../storage/installed.lock')) || wk_env_bool('APP_READY',false);
if ($APP_INSTALLED && PHP_SAPI==='cli') wk_validate_app_key($CONFIG['app_key']??null);
date_default_timezone_set((string)($CONFIG['timezone']??'Africa/Nairobi'));
require_once __DIR__.'/functions.php';
require_once __DIR__.'/impact.php';
require_once __DIR__.'/writer_account.php';
require_once __DIR__.'/studio.php';
require_once __DIR__.'/media_centre.php';
require_once __DIR__.'/analytics.php';
if (is_file(__DIR__.'/../vendor/autoload.php')) require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/operations.php';
require_once __DIR__.'/media_storage.php';
require_once __DIR__.'/media_library.php';
require_once __DIR__.'/mail_feedback.php';
require_once __DIR__.'/integrations.php';
foreach (['journeys','journey_routes','newsletter','newsletter_routes','media_library_routes','publishing','publishing_routes','readiness','readiness_routes','editorial','editorial_routes','reader','reader_routes','founder_stories','founder_story_routes','discovery','discovery_routes','campaigns','campaign_routes','google_workspace','meetings','communication','communications_cycle','communication_notifications','app_security','communication_mail','trusted_devices','planner','publishing_automation','workspace_reports','contact_experience','enterprises','microsoft_workspace','microsoft_actions','opportunities','opportunity_routes'] as $module) if (is_file(__DIR__.'/'.$module.'.php')) require_once __DIR__.'/'.$module.'.php';

if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: strict-origin-when-cross-origin'); header('X-Frame-Options: SAMEORIGIN');
    $requestPath=path_only();
    if ($requestPath==='/healthz') {
        header('Content-Type: application/json'); header('Cache-Control: no-store');
        try { if (!$APP_INSTALLED) throw new RuntimeException(); wk_validate_app_key(app_config('app_key')); db()->query('SELECT 1'); echo '{"ok":true}'; }
        catch (Throwable $e) { http_response_code(503); echo '{"ok":false}'; }
        exit;
    }
    if (($CONFIG['app_env']??'production')==='production' && ($CONFIG['force_canonical']??true)) {
        $expected=strtolower((string)parse_url(base_url(),PHP_URL_HOST)); $actual=strtolower(explode(':',(string)($_SERVER['HTTP_HOST']??''))[0]);
        $allowed=array_unique(array_merge([$expected],$CONFIG['allowed_hosts']??[]));
        if (!$expected || !in_array($actual,$allowed,true)) { http_response_code(400); exit('Invalid host.'); }
        if ($actual!==$expected || !wk_request_is_https()) {
            $uri=$_SERVER['REQUEST_URI']??'/'; if (!str_starts_with($uri,'/') || preg_match('/[\x00-\x20\x7f]/',$uri)) $uri='/';
            redirect(base_url().$uri,308);
        }
        header('Strict-Transport-Security: max-age=31536000');
    }
    if (preg_match('~^/(?:write|consent|payments|webhooks|newsletter/(?:confirm|unsubscribe|one-click|preferences)|founders/follow-up)(?:/|$)~',$requestPath) || isset($_GET['preview'])) {
        header('Cache-Control: no-store, private, max-age=0'); header('X-Robots-Tag: noindex, nofollow, noarchive'); header('Referrer-Policy: no-referrer');
    } else header('Cache-Control: private, no-cache');
    if (!$APP_INSTALLED) { wk_fail_safe('Deployment is not configured.'); exit; }
    wk_validate_app_key(app_config('app_key'));
    mail_feedback_webhook($requestPath);
    // RFC 8058 recipients do not need a session, cookie, redirect or CSRF token.
    if($requestPath==='/newsletter/one-click' && function_exists('newsletter_dispatch') && newsletter_dispatch($requestPath)) exit;
    ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1');
    if (app_config('session_driver','files')==='database') { require_once __DIR__.'/sessions.php'; session_set_save_handler(new WkDatabaseSessions(),true); }
    else { $sessionDir=__DIR__.'/../storage/sessions'; if (!is_dir($sessionDir)) mkdir($sessionDir,0700,true); session_save_path($sessionDir); }
    session_set_cookie_params(['httponly'=>true,'secure'=>wk_request_is_https()||str_starts_with(base_url(),'https://'),'samesite'=>'Lax','path'=>'/']);
    session_start();
    if(function_exists('app_trust_restore')){app_trust_restore();if(!empty($_SESSION['user_id'])&&!isset($_SESSION['trust_opt_out'])&&current_user())app_trust_issue((int)$_SESSION['user_id']);}
}




