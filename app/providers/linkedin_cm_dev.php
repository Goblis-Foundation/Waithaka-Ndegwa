<?php
declare(strict_types=1);

/**
 * Unregistered development-only profile. No network calls, secrets, or production
 * connection changes. Deliberately not required by publishing.php/oauth.php.
 */
function publishing_linkedin_cm_dev_profile(): array {
    return [
        'service'=>'linkedin_cm_dev',
        'credential_namespace'=>'linkedin_cm_dev',
        'enabled'=>false,
        'production_allowed'=>false,
        'automatic_delivery_allowed'=>false,
        'scopes'=>['r_basicprofile','w_member_social'],
        'identity_endpoint'=>'https://api.linkedin.com/v2/me',
        'authorization_endpoint'=>'https://www.linkedin.com/oauth/v2/authorization',
        'token_endpoint'=>'https://www.linkedin.com/oauth/v2/accessToken',
        'app_calls_per_24h'=>500,
        'member_calls_per_24h'=>100,
    ];
}

/** Preflight only. Caller must reserve against a shared durable rolling ledger
 * atomically BEFORE each request, including identity/read-back/retries. This
 * function does not reserve capacity and must never authorize transport alone.
 */
function publishing_linkedin_cm_dev_budget(int $appCalls, int $memberCalls, int $requested=1): void {
    if ($appCalls < 0 || $memberCalls < 0 || $requested < 1 || $memberCalls > $appCalls
        || $requested > 500 || $appCalls > 500-$requested || $memberCalls > 100-$requested) {
        throw new RuntimeException('LinkedIn development budget unavailable; do not call the provider.');
    }
}

/** Self profile only. Never accept OIDC sub or a member ID from another app. */
function publishing_linkedin_cm_dev_identity(array $who): array {
    $id=$who['id']??null;
    $first=$who['localizedFirstName']??null;
    $last=$who['localizedLastName']??null;
    if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,120}$/D',$id)
        || !is_string($first) || !is_string($last) || trim($first.' '.$last)===''
        || strlen($first.' '.$last)>500 || preg_match('/[\x00-\x1f\x7f]/',$first.$last)) {
        throw new RuntimeException('LinkedIn did not return a valid self profile.');
    }
    $name=trim($first.' '.$last);
    return ['id'=>$id,'name'=>$name,'resources'=>[['id'=>$id,'name'=>$name,'type'=>'member']],
        'metadata'=>['profile'=>'linkedin_cm_dev','tier'=>'development','identity_source'=>'v2/me']];
}

/** Accept only the canonical callback for this isolated profile, never a request
 * supplied return URL. HTTP is allowed solely on loopback in local/test builds.
 */
function publishing_linkedin_cm_dev_callback(string $canonicalBase, string $environment): string {
    if (!in_array($environment,['local','development','test'],true)) {
        throw new RuntimeException('Community Management development profile is not available in this environment.');
    }
    $parts=parse_url($canonicalBase);
    if ($parts===false || !isset($parts['scheme'],$parts['host']) || isset($parts['user'])
        || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || !in_array($parts['path']??'',['','/'],true) || preg_match('/[\x00-\x20\x7f]/',$canonicalBase)) {
        throw new RuntimeException('Use a canonical origin for the development callback.');
    }
    $loopback=in_array(strtolower($parts['host']),['localhost','127.0.0.1','[::1]'],true);
    if ($parts['scheme']!=='https' && !($parts['scheme']==='http' && $loopback && in_array($environment,['local','test'],true))) {
        throw new RuntimeException('The development callback requires HTTPS.');
    }
    return rtrim($canonicalBase,'/').'/write/connections/callback/linkedin_cm_dev';
}

/** A hard stop until shared quota, consent, callback and test-post gates have
 * independent review. No flag or stored setting can enable this draft. */
function publishing_linkedin_cm_dev_require_transport(): never {
    throw new RuntimeException('LinkedIn CM development transport is disabled pending independent review and explicit activation.');
}
