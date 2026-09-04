<?php
/**
 * Security helpers: request normalization, CSRF, rate limiting, URL utilities.
 */

final class BadRequestException extends RuntimeException
{
}

/**
 * Detect HTTPS, honoring forwarding headers only from trusted proxies.
 */
function app_is_https(): bool
{
    return app_request_context()['is_https'];
}

/**
 * Absolute base URL of this install (scheme + host, no trailing slash).
 */
function app_base_url(): string
{
    $baseUrl = defined('APP_BASE_URL') ? trim((string) APP_BASE_URL) : 'http://127.0.0.1';
    if ($baseUrl === '' || preg_match('/[\x00-\x20\x7f]/', $baseUrl)) {
        throw new RuntimeException('Invalid APP_BASE_URL configuration.');
    }

    $parts = parse_url($baseUrl);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        throw new RuntimeException('Invalid APP_BASE_URL configuration.');
    }

    $scheme = strtolower((string) $parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException('Invalid APP_BASE_URL configuration.');
    }

    $host = app_normalize_host((string) $parts['host']);
    if ($host === null) {
        throw new RuntimeException('Invalid APP_BASE_URL configuration.');
    }

    $normalized = $scheme . '://' . $host;
    if (isset($parts['port'])) {
        $port = (int) $parts['port'];
        if ($port <= 0 || $port > 65535) {
            throw new RuntimeException('Invalid APP_BASE_URL configuration.');
        }
        $normalized .= ':' . $port;
    }

    return rtrim($normalized, '/');
}

/**
 * Normalize and resolve the client IP, honoring trusted proxy headers.
 */
function app_client_ip(): string
{
    return app_request_context()['client_ip'];
}

/**
 * Normalize an incoming host value. Returns null when the value is malformed.
 */
function app_normalize_host(string $host): ?string
{
    $host = trim($host);
    if ($host === '' || preg_match('/[\x00-\x20\x7f\/\\\\#?@,]/', $host)) {
        return null;
    }

    if ($host[0] === '[') {
        if (!preg_match('/^\[([0-9a-fA-F:.]+)\](?::(\d{1,5}))?$/', $host, $matches)) {
            return null;
        }
        if (!filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return null;
        }
        if (isset($matches[2]) && ((int) $matches[2] <= 0 || (int) $matches[2] > 65535)) {
            return null;
        }
        return strtolower($matches[1]);
    }

    if (preg_match('/^(.+):(\d{1,5})$/', $host, $matches) && substr_count($host, ':') === 1) {
        if ((int) $matches[2] <= 0 || (int) $matches[2] > 65535) {
            return null;
        }
        $host = $matches[1];
    } elseif (substr_count($host, ':') > 1) {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? strtolower($host) : null;
    }

    $host = rtrim($host, '.');
    if ($host === '') {
        return null;
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return strtolower($host);
    }

    if (function_exists('idn_to_ascii')) {
        $idnVariant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0;
        $asciiHost = idn_to_ascii($host, 0, $idnVariant);
        if ($asciiHost !== false) {
            $host = $asciiHost;
        }
    }

    if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        return null;
    }

    return strtolower($host);
}

function app_is_allowed_host(string $host): bool
{
    $normalized = app_normalize_host($host);
    if ($normalized === null) {
        return false;
    }

    if (in_array($normalized, app_system_hosts(), true)) {
        return true;
    }

    if (!function_exists('getDB')) {
        return false;
    }

    static $cache = [];
    if (array_key_exists($normalized, $cache)) {
        return $cache[$normalized];
    }

    $stmt = getDB()->prepare('SELECT 1 FROM domains WHERE lower(domain) = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$normalized]);

    return $cache[$normalized] = ($stmt->fetchColumn() !== false);
}

function app_enforce_allowed_host(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $host = app_request_context()['host'];
    if ($host !== null && app_is_allowed_host($host)) {
        return;
    }

    app_abort_request(421, 'Unknown host.');
}

/**
 * @return array<string, mixed>
 */
function app_request_context(): array
{
    static $context = null;
    if ($context !== null) {
        return $context;
    }

    $remote = app_normalize_ip((string) ($_SERVER['REMOTE_ADDR'] ?? '')) ?? '127.0.0.1';
    $trustedProxy = app_is_trusted_proxy($remote);
    $host = app_normalize_host((string) ($_SERVER['HTTP_HOST'] ?? ''));

    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if (!$isHttps && $trustedProxy) {
        $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($forwardedProto !== '') {
            $isHttps = in_array(explode(',', $forwardedProto)[0], ['https', 'wss'], true);
        } elseif (strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''))) === 'on') {
            $isHttps = true;
        }
    }

    $context = [
        'remote_ip' => $remote,
        'client_ip' => app_resolve_client_ip($remote, $trustedProxy),
        'trusted_proxy' => $trustedProxy,
        'host' => $host,
        'is_https' => $isHttps,
    ];

    return $context;
}

function app_query_scalar(string $name, int $maxBytes = 4096): ?string
{
    return app_array_get_scalar($_GET, $name, $maxBytes, "query parameter {$name}");
}

function app_array_get_scalar(array $input, string $name, int $maxBytes = 4096, ?string $label = null): ?string
{
    if (!array_key_exists($name, $input)) {
        return null;
    }

    return app_scalar_value($input[$name], $label ?? $name, $maxBytes);
}

/**
 * @return array<string, string>
 */
function app_validate_scalar_map(array $input, int $maxBytes = 4096, string $labelPrefix = 'parameter'): array
{
    $output = [];
    foreach ($input as $key => $value) {
        if (!is_string($key) && !is_int($key)) {
            throw new BadRequestException("Invalid {$labelPrefix}.", 400);
        }

        $output[(string) $key] = app_scalar_value($value, "{$labelPrefix} " . (string) $key, $maxBytes) ?? '';
    }

    return $output;
}

/**
 * @return string|null
 */
function app_scalar_value(mixed $value, string $label, int $maxBytes = 4096): ?string
{
    if ($value === null) {
        return null;
    }

    if (is_array($value) || is_object($value)) {
        throw new BadRequestException("Invalid {$label}.", 400);
    }

    if (!is_scalar($value)) {
        throw new BadRequestException("Invalid {$label}.", 400);
    }

    $stringValue = (string) $value;
    if (strlen($stringValue) > $maxBytes) {
        throw new BadRequestException("Invalid {$label}.", 400);
    }

    return $stringValue;
}

function app_abort_request(int $status, string $message): never
{
    $isApi = strpos((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api') === 0;

    if (!headers_sent()) {
        http_response_code($status);
        header('Cache-Control: no-store');
        if ($isApi) {
            header('Content-Type: application/json');
        } else {
            header('Content-Type: text/plain; charset=UTF-8');
        }
    }

    if ($isApi) {
        echo json_encode(['error' => $message]);
    } else {
        echo $status === 400 ? 'Bad Request' : $message;
    }

    exit;
}

function is_valid_offer_url(string $url): bool
{
    if ($url === '' || strlen($url) > 2048) {
        return false;
    }
    if ($url !== trim($url) || preg_match('/[\x00-\x20\x7f]/', $url)) {
        return false;
    }

    $parts = parse_url($url);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }
    if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
        return false;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }
    if (app_normalize_host((string) $parts['host']) === null) {
        return false;
    }
    if (isset($parts['port']) && ((int) $parts['port'] <= 0 || (int) $parts['port'] > 65535)) {
        return false;
    }

    return true;
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

    if (!$row || (int) $row['reset_at'] < $now) {
        $db->prepare(
            "INSERT INTO rate_limits (rkey, count, reset_at) VALUES (?, 1, ?)
             ON CONFLICT(rkey) DO UPDATE SET count = 1, reset_at = excluded.reset_at"
        )->execute([$key, $now + $windowSeconds]);
        return true;
    }

    if ((int) $row['count'] >= $max) {
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

function app_normalize_ip(string $ip): ?string
{
    $ip = trim($ip);
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return null;
    }

    return strtolower($ip);
}

function app_is_trusted_proxy(string $ip): bool
{
    $trusted = defined('TRUSTED_PROXIES') && is_array(TRUSTED_PROXIES) ? TRUSTED_PROXIES : [];
    foreach ($trusted as $candidate) {
        if (!is_string($candidate)) {
            continue;
        }
        if (app_ip_matches_cidr($ip, trim($candidate))) {
            return true;
        }
    }

    return false;
}

function app_resolve_client_ip(string $remote, bool $trustedProxy): string
{
    if (!$trustedProxy) {
        return $remote;
    }

    $forwardedFor = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwardedFor !== '') {
        $ips = array_map('trim', explode(',', $forwardedFor));
        for ($index = count($ips) - 1; $index >= 0; $index--) {
            $candidate = app_normalize_ip($ips[$index] ?? '');
            if ($candidate === null) {
                continue;
            }
            if (!app_is_trusted_proxy($candidate)) {
                return $candidate;
            }
        }
    }

    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $header) {
        $candidate = app_normalize_ip((string) ($_SERVER[$header] ?? ''));
        if ($candidate !== null && !app_is_trusted_proxy($candidate)) {
            return $candidate;
        }
    }

    return $remote;
}

function app_ip_matches_cidr(string $ip, string $candidate): bool
{
    if ($candidate === '') {
        return false;
    }

    if (!str_contains($candidate, '/')) {
        $normalizedCandidate = app_normalize_ip($candidate);
        return $normalizedCandidate !== null && $normalizedCandidate === app_normalize_ip($ip);
    }

    [$network, $prefixLength] = explode('/', $candidate, 2);
    $normalizedNetwork = app_normalize_ip($network);
    $normalizedIp = app_normalize_ip($ip);
    if ($normalizedNetwork === null || $normalizedIp === null || !ctype_digit($prefixLength)) {
        return false;
    }

    $ipPacked = @inet_pton($normalizedIp);
    $networkPacked = @inet_pton($normalizedNetwork);
    if ($ipPacked === false || $networkPacked === false || strlen($ipPacked) !== strlen($networkPacked)) {
        return false;
    }

    $bits = (int) $prefixLength;
    $maxBits = strlen($ipPacked) * 8;
    if ($bits < 0 || $bits > $maxBits) {
        return false;
    }

    $fullBytes = intdiv($bits, 8);
    if ($fullBytes > 0 && substr($ipPacked, 0, $fullBytes) !== substr($networkPacked, 0, $fullBytes)) {
        return false;
    }

    $remainingBits = $bits % 8;
    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

    return (ord($ipPacked[$fullBytes]) & $mask) === (ord($networkPacked[$fullBytes]) & $mask);
}

/**
 * @return array<int, string>
 */
function app_system_hosts(): array
{
    static $hosts = null;
    if ($hosts !== null) {
        return $hosts;
    }

    $candidates = defined('SYSTEM_HOSTS') && is_array(SYSTEM_HOSTS) ? SYSTEM_HOSTS : [];
    if ($candidates === []) {
        $configuredHost = parse_url(app_base_url(), PHP_URL_HOST);
        if (is_string($configuredHost) && $configuredHost !== '') {
            $candidates[] = $configuredHost;
        }
    }

    $normalized = [];
    foreach ($candidates as $candidate) {
        if (!is_string($candidate)) {
            continue;
        }
        $host = app_normalize_host($candidate);
        if ($host !== null) {
            $normalized[$host] = true;
        }
    }

    if ($normalized === []) {
        $normalized['127.0.0.1'] = true;
    }

    return $hosts = array_keys($normalized);
}
