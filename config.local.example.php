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
// Use a different database location
define('DB_PATH', __DIR__ . '/data/cloaking.db');

// Provide your own application key (64 hex chars). Otherwise one is
// generated automatically in data/app.key on first run.
define('APP_KEY', str_repeat('0', 64));

// Trust forwarding headers only from these proxies (e.g. Cloudflare IPs).
// Leave empty (default) to always use REMOTE_ADDR.
define('TRUSTED_PROXIES', [
    // '173.245.48.0/20', // Cloudflare example (CIDR blocks not supported — list IPs or use a library)
]);

// Disable the Tor exit-node DNSBL lookup if DNS is slow in your environment
define('ENABLE_TOR_CHECK', true);

// Login rate limit
define('LOGIN_MAX_ATTEMPTS', 10);
define('LOGIN_WINDOW_SECONDS', 300);

// Hit-log retention (days). Old rows are pruned probabilistically.
define('LOG_RETENTION_DAYS', 30);
*/
