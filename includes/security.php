<?php
/**
 * Security helpers: CSRF, rate limiting, URL utilities.
 */

/**
 * Detect HTTPS, honoring common load-balancer headers.
 */
function app_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        return true;
    }
    if (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on') {
        return true;
    }
    return false;
}

/**
 * Absolute base URL of this install (scheme + host, no trailing slash).
 */
function app_base_url(): string
{
    $scheme = app_is_https() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

/**
 * The client IP as seen by this server (REMOTE_ADDR). For cloaked traffic
 * use BotDetector (which applies TRUSTED_PROXIES rules) instead.
 */
function app_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

// ---- CSRF -------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

function csrf_verify(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return true;
    }
    $token = $_POST['_csrf'] ?? '';
    return !empty($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

// ---- Rate limiting -----------------------------------------------------------

/**
 * Simple DB-backed sliding-window rate limiter.
 * Returns true if the request is allowed, false if the limit is hit.
 */
function rate_limit(string $key, int $max, int $windowSeconds): bool
{
    $db = getDB();
    $now = time();

    $stmt = $db->prepare("SELECT count, reset_at FROM rate_limits WHERE rkey = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();

    if (!$row || (int)$row['reset_at'] < $now) {
        $db->prepare(
            "INSERT INTO rate_limits (rkey, count, reset_at) VALUES (?, 1, ?)
             ON CONFLICT(rkey) DO UPDATE SET count = 1, reset_at = excluded.reset_at"
        )->execute([$key, $now + $windowSeconds]);
        return true;
    }

    if ((int)$row['count'] >= $max) {
        return false;
    }

    $db->prepare("UPDATE rate_limits SET count = count + 1 WHERE rkey = ?")->execute([$key]);
    return true;
}

// ---- Input validation ---------------------------------------------------------

const RESERVED_SLUGS = ['admin', 'api', 'assets', 'index.php', 'config.php',
    'config.local.php', 'install.php', 'dev-router.php', 'includes', 'data', 'logs'];

function is_valid_slug(string $slug): bool
{
    if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $slug)) {
        return false;
    }
    if (in_array(strtolower($slug), RESERVED_SLUGS, true)) {
        return false;
    }
    return true;
}

function is_valid_offer_url(string $url): bool
{
    if (strlen($url) > 2048) {
        return false;
    }
    if (preg_match('/[\r\n]/', $url)) {
        return false;
    }
    $parts = parse_url($url);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }
    return in_array(strtolower($parts['scheme']), ['http', 'https'], true);
}
