<?php
/**
 * Local configuration overrides.
 *
 * Copy this file to config.local.php and adjust. Every value is optional;
 * only define what you need to change.
 *
 * This file is excluded from Docker builds (.dockerignore) and should be
 * excluded from version control.
 */

/*
// Mutable runtime state should live outside the deployed app tree and be
// writable by the PHP user. By default the app uses ../cloaking-runtime/.
define('APP_RUNTIME_DIR', dirname(__DIR__) . '/cloaking-runtime');

// Override the SQLite file directly when you need a different path.
define('DB_PATH', APP_RUNTIME_DIR . '/cloaking.sqlite');

// Provide your own application key (64 hex chars). Otherwise one is
// generated automatically in APP_RUNTIME_DIR/app.key on first run.
define('APP_KEY', str_repeat('0', 64));

// Canonical application URL used for generated links and callbacks.
define('APP_BASE_URL', 'https://app.example.com');

// System hosts that may serve system-owned links (usually your primary app
// domain plus any direct IP or local-dev hostname you intentionally use).
define('SYSTEM_HOSTS', [
    'app.example.com',
]);

// Trust forwarding headers only from these proxies (e.g. Cloudflare IPs).
// Leave empty (default) to always use REMOTE_ADDR.
define('TRUSTED_PROXIES', [
    // '173.245.48.0/20', // Cloudflare example (CIDR blocks supported)
]);

// Disable the Tor exit-node DNSBL lookup if DNS is slow in your environment
define('ENABLE_TOR_CHECK', true);

// Optional IP-intelligence adapter. The endpoint must be HTTPS, accept an
// authenticated JSON POST {"ip":"..."}, and return the documented normalized
// schema. Network-sensitive rules fail closed when the adapter is unavailable.
define('IP_INTELLIGENCE_ENDPOINT', 'https://intel.internal.example/v1/lookup');
define('IP_INTELLIGENCE_API_KEY', 'replace-with-secret-from-your-vault');
define('IP_INTELLIGENCE_FAILURE_MODE', 'closed'); // "closed" or explicit "open"

// Login rate limit
define('LOGIN_MAX_ATTEMPTS', 10);
define('LOGIN_WINDOW_SECONDS', 300);

// Hit-log retention (days). Old rows are pruned probabilistically.
define('LOG_RETENTION_DAYS', 30);
*/
