<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| This app previously had no config/cors.php at all, so every response
| under `paths` below silently inherited Laravel's own packaged default
| (`vendor/laravel/framework/config/cors.php`) -- `allowed_origins: ['*']`,
| unconditionally, for every environment. This file replaces that with an
| explicit, environment-driven allowlist.
|
| CORS_ALLOWED_ORIGINS (.env) is a comma-separated list of exact origins,
| e.g. "http://localhost:8080,https://staging.example.com" -- set it for
| staging/production to the real Flutter Web origin(s), never a wildcard.
|
| When it's unset (the normal case for local development), a safe
| loopback-only pattern is used instead: any http(s)://localhost or
| 127.0.0.1 origin, at any port. This covers Flutter Web's dev server,
| whose port isn't fixed (`flutter run -d chrome` picks one unless
| --web-port is passed), without ever falling back to a global wildcard.
| The two are mutually exclusive -- as soon as a real CORS_ALLOWED_ORIGINS
| is configured, only that explicit list is trusted; the loopback pattern
| no longer applies.
|
| supports_credentials stays false regardless -- this app authenticates
| with a Sanctum bearer token (Authorization header), never a cookie-based
| SPA session, so there is no session cookie for a browser to leak
| cross-origin under either configuration.
|
*/

$configuredOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $configuredOrigins,

    'allowed_origins_patterns' => $configuredOrigins === []
        ? ['#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#']
        : [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
