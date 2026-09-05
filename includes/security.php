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

    $serializedHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
    $normalized = $scheme . '://' . $serializedHost;
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
    $cloudflare = defined('TRUST_CLOUDFLARE') && TRUST_CLOUDFLARE
        && isset($_SERVER['HTTP_CF_RAY']) && trim((string) $_SERVER['HTTP_CF_RAY']) !== '';
    $trustedProxy = $cloudflare || app_is_trusted_proxy($remote);
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
    if (!$isHttps && $cloudflare) {
        $visitor = json_decode((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), true);
        if (is_array($visitor) && strtolower((string) ($visitor['scheme'] ?? '')) === 'https') {
            $isHttps = true;
        }
    }

    $clientIp = app_resolve_client_ip($remote, $trustedProxy);
    if ($cloudflare) {
        $cfConnecting = app_normalize_ip((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cfConnecting !== null) {
            $clientIp = $cfConnecting;
        }
    }

    $context = [
        'remote_ip' => $remote,
        'client_ip' => $clientIp,
        'trusted_proxy' => $trustedProxy,
        'cloudflare' => $cloudflare,
        'host' => $host,
        'is_https' => $isHttps,
    ];

    return $context;
}

/**
 * Validated Cloudflare geo headers for the current request.
 * Only populated when TRUST_CLOUDFLARE is enabled and the request passed
 * through Cloudflare (CF-RAY present). Returns ['country' => '', 'asn' => ''].
 *
 * Cloudflare sends CF-IPCountry to origins (IP Geolocation / visitor location
 * headers, all plans). ASN is NOT a standard origin header: operators forward
 * request.cf.asn through a small edge Worker as `X-Client-ASN`; the app also
 * accepts `CF-IPASN` where a platform already injects it. Both are trusted
 * only under the TRUST_CLOUDFLARE + origin-lock contract.
 */
function app_cloudflare_headers(): array
{
    if (!app_request_context()['cloudflare']) {
        return ['country' => '', 'asn' => ''];
    }

    $country = strtoupper(trim((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));
    if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
        $country = '';
    }

    $asn = '';
    foreach (['HTTP_X_CLIENT_ASN', 'HTTP_CF_IPASN'] as $headerKey) {
        $candidate = strtoupper(trim((string) ($_SERVER[$headerKey] ?? '')));
        if (preg_match('/^AS\d{1,10}$/', $candidate) === 1) {
            $asn = $candidate;
            break;
        }
    }

    return ['country' => $country, 'asn' => $asn];
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

function app_array_flag(array $input, string $name, int $default = 0, bool $preserveMissing = false): int
{
    if (!array_key_exists($name, $input)) {
        return $preserveMissing ? $default : 0;
    }

    return ((int) app_scalar_value($input[$name], $name, 32)) ? 1 : 0;
}

function app_array_clamped_int(
    array $input,
    string $name,
    int $default,
    int $min,
    int $max,
    bool $preserveMissing = false,
    ?string $label = null
): int {
    if (!array_key_exists($name, $input)) {
        return $preserveMissing ? $default : $min;
    }

    $value = (int) (app_scalar_value($input[$name], $label ?? $name, 64) ?? (string) $default);

    return max($min, min($max, $value));
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

/**
 * Fetch normalized IP intelligence over an authenticated TLS transport.
 * The optional transport seam is used by deterministic tests and private
 * adapters; it receives the configured endpoint and request options.
 *
 * @return array{ip:string,asn:string,country_code:string,is_proxy:bool,is_hosting:bool}|null
 */
function app_fetch_ip_intelligence(string $ip, ?callable $transport = null): ?array
{
    $normalizedIp = app_normalize_ip($ip);
    $endpoint = defined('IP_INTELLIGENCE_ENDPOINT') ? trim((string) IP_INTELLIGENCE_ENDPOINT) : '';
    $apiKey = defined('IP_INTELLIGENCE_API_KEY') ? trim((string) IP_INTELLIGENCE_API_KEY) : '';
    if ($normalizedIp === null || $endpoint === '' || $apiKey === '' || preg_match('/[\r\n]/', $apiKey)) {
        return null;
    }

    $endpointParts = parse_url($endpoint);
    if ($endpointParts === false
        || strtolower((string) ($endpointParts['scheme'] ?? '')) !== 'https'
        || app_normalize_host((string) ($endpointParts['host'] ?? '')) === null
        || isset($endpointParts['user'])
        || isset($endpointParts['pass'])) {
        return null;
    }

    $body = json_encode(['ip' => $normalizedIp], JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
        return null;
    }
    $options = [
        'method' => 'POST',
        'headers' => "Accept: application/json\r\nContent-Type: application/json\r\nAuthorization: Bearer {$apiKey}\r\n",
        'body' => $body,
        'timeout' => 2.0,
        'verify_peer' => true,
        'verify_peer_name' => true,
    ];

    if ($transport === null) {
        $transport = static function (string $url, array $request): string|false {
            $context = stream_context_create([
                'http' => [
                    'method' => $request['method'],
                    'header' => $request['headers'],
                    'content' => $request['body'],
                    'timeout' => $request['timeout'],
                    'ignore_errors' => true,
                    'follow_location' => 0,
                    'max_redirects' => 0,
                ],
                'ssl' => [
                    'verify_peer' => $request['verify_peer'],
                    'verify_peer_name' => $request['verify_peer_name'],
                    'allow_self_signed' => false,
                ],
            ]);

            $response = file_get_contents($url, false, $context);
            $status = 0;
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $matches) === 1) {
                    $status = (int) $matches[1];
                }
            }

            return $status >= 200 && $status < 300 ? $response : false;
        };
    }

    try {
        $response = $transport($endpoint, $options);
    } catch (Throwable) {
        return null;
    }
    if (!is_string($response) || $response === '' || strlen($response) > 65536) {
        return null;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || app_normalize_ip((string) ($decoded['ip'] ?? '')) !== $normalizedIp) {
        return null;
    }
    $asn = strtoupper(trim((string) ($decoded['asn'] ?? '')));
    if (preg_match('/^AS\d{1,10}$/', $asn) !== 1) {
        return null;
    }
    $countryCode = strtoupper(trim((string) ($decoded['country_code'] ?? '')));
    if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1
        || !is_bool($decoded['is_proxy'] ?? null)
        || !is_bool($decoded['is_hosting'] ?? null)) {
        return null;
    }

    return [
        'ip' => $normalizedIp,
        'asn' => $asn,
        'country_code' => $countryCode,
        'is_proxy' => $decoded['is_proxy'],
        'is_hosting' => $decoded['is_hosting'],
    ];
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
    $resetAt = $now + $windowSeconds;

    $db->exec('BEGIN IMMEDIATE');

    try {
        $stmt = $db->prepare('SELECT count, reset_at FROM rate_limits WHERE rkey = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        if (!$row || (int) $row['reset_at'] < $now) {
            $count = 1;
            $currentResetAt = $resetAt;
            $allowed = true;
        } elseif ((int) $row['count'] >= $max) {
            $count = (int) $row['count'];
            $currentResetAt = (int) $row['reset_at'];
            $allowed = false;
        } else {
            $count = (int) $row['count'] + 1;
            $currentResetAt = (int) $row['reset_at'];
            $allowed = true;
        }

        $db->prepare(
            'INSERT INTO rate_limits (rkey, count, reset_at) VALUES (?, ?, ?)
             ON CONFLICT(rkey) DO UPDATE SET count = excluded.count, reset_at = excluded.reset_at'
        )->execute([$key, $count, $currentResetAt]);

        $db->commit();

        return $allowed;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
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
