<?php
// Legacy hosting compatibility. Prefer environment injection on AWS.
// Copy outside the public document root and point APP_CONFIG_FILE at it.
// When restoring existing data, retain its exact original app_key.
return [
 'app_env'=>'production',
 'base_url'=>'https://www.waithakandegwa.com',
 'force_canonical'=>true,
 'timezone'=>'Africa/Nairobi',
 'app_key'=>'',
 'allowed_hosts'=>['www.waithakandegwa.com','waithakandegwa.com','www.waithakandegwa.co.ke','waithakandegwa.co.ke'],
 'trusted_proxies'=>[],
 'session_driver'=>'database',
 'db'=>['host'=>'','port'=>3306,'name'=>'','user'=>'','pass'=>'','charset'=>'utf8mb4','ssl_ca'=>''],
 'mail'=>['transport'=>'disabled','from'=>'','ses_region'=>''],
 'media'=>['driver'=>'local','bucket'=>'','private_bucket'=>'','public_url'=>''],
];
