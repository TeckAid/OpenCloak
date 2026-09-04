<?php
/**
 * Cloaking SaaS - Configuration
 *
 * Secrets are NOT hardcoded. On first run a random key is generated in
 * data/app.key and all secrets are derived from it. For advanced setups,
 * copy config.local.example.php to config.local.php and adjust values.
 */

// ---- Local overrides (optional) -------------------------------------------
if (file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

// ---- Database --------------------------------------------------------------
if (!defined('DB_PATH')) {
    define('DB_PATH', __DIR__ . '/data/cloaking.db');
}

// ---- Application key (random, generated on first run) ----------------------
if (!defined('APP_KEY')) {
    $appKeyFile = __DIR__ . '/data/app.key';
    $appKey = null;
    if (is_readable($appKeyFile)) {
        $appKey = trim((string)file_get_contents($appKeyFile));
    }
    if (!$appKey || strlen($appKey) < 32) {
        $appKey = bin2hex(random_bytes(32));
        if (!is_dir(dirname($appKeyFile))) {
            @mkdir(dirname($appKeyFile), 0755, true);
        }
        @file_put_contents($appKeyFile, $appKey);
    }
    define('APP_KEY', $appKey);
}

// ---- Security ---------------------------------------------------------------
if (!defined('ADMIN_SECRET_KEY')) {
    define('ADMIN_SECRET_KEY', hash_hmac('sha256', 'admin', APP_KEY));
}
if (!defined('JWT_SECRET')) {
    define('JWT_SECRET', hash_hmac('sha256', 'jwt', APP_KEY));
}
if (!defined('DEBUG_TOKEN')) {
    define('DEBUG_TOKEN', substr(hash_hmac('sha256', 'debug', APP_KEY), 0, 16));
}
if (!defined('SESSION_LIFETIME')) {
    define('SESSION_LIFETIME', 3600 * 8); // 8 hours
}

// Canonical application URL and system short-link hosts. Never derive these
// from an arbitrary Host header in production.
if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', 'http://127.0.0.1');
}
if (!defined('SYSTEM_HOSTS')) {
    $appBaseHost = parse_url(APP_BASE_URL, PHP_URL_HOST);
    define('SYSTEM_HOSTS', [is_string($appBaseHost) && $appBaseHost !== '' ? strtolower($appBaseHost) : '127.0.0.1']);
}

// Proxies that are allowed to set forwarding headers (e.g. Cloudflare ranges).
// Empty = never trust forwarding headers; always use REMOTE_ADDR.
if (!defined('TRUSTED_PROXIES')) {
    define('TRUSTED_PROXIES', []);
}

// ---- Cloaking defaults ------------------------------------------------------
if (!defined('DEFAULT_WHITE_PAGE')) {
    define('DEFAULT_WHITE_PAGE', '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
               display: flex; justify-content: center; align-items: center; min-height: 100vh;
               background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; }
        .container { text-align: center; padding: 2rem; }
        h1 { font-size: 2.5rem; margin-bottom: 1rem; }
        p { font-size: 1.2rem; opacity: 0.9; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Welcome</h1>
        <p>This page is currently under construction.</p>
    </div>
</body>
</html>
');
}

// ---- Detection settings -----------------------------------------------------
if (!defined('ENABLE_TOR_CHECK')) {
    define('ENABLE_TOR_CHECK', true);
}

// ---- Rate limiting -----------------------------------------------------------
if (!defined('RATE_LIMIT_ENABLED')) {
    define('RATE_LIMIT_ENABLED', true);
}
if (!defined('LOGIN_MAX_ATTEMPTS')) {
    define('LOGIN_MAX_ATTEMPTS', 10);          // per IP
}
if (!defined('LOGIN_WINDOW_SECONDS')) {
    define('LOGIN_WINDOW_SECONDS', 300);       // 5 minutes
}

// ---- Logging ------------------------------------------------------------------
if (!defined('LOG_ENABLED')) {
    define('LOG_ENABLED', true);
}
if (!defined('LOG_PATH')) {
    define('LOG_PATH', __DIR__ . '/logs/');
}
if (!defined('LOG_RETENTION_DAYS')) {
    define('LOG_RETENTION_DAYS', 30);
}
