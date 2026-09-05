<?php
/**
 * Rule helpers: effective rules resolution, wildcard matching,
 * OS version parsing, screen-resolution tiers, fingerprint payloads,
 * and campaign presets.
 */

/**
 * Resolve the effective rule set for a link.
 *
 * - Link bound to a campaign: campaign's rules + actions are used.
 * - Link without a campaign: the link's own rule columns are used.
 *
 * Returns null when the link is bound to a campaign that is missing or
 * inactive (caller should deny with 'campaign_inactive').
 */
function effective_rules(PDO $db, array $link): ?array
{
    if (!empty($link['campaign_id'])) {
        $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ? AND user_id = ?");
        $stmt->execute([(int)$link['campaign_id'], (int)$link['user_id']]);
        $campaign = $stmt->fetch();
        if (!$campaign || empty($campaign['is_active'])) {
            return null;
        }
        return $campaign;
    }
    return $link;
}

/**
 * Wildcard-aware list match. Patterns may contain '*' (e.g. "*.facebook.com").
 */
function wildcard_match_list(string $csvList, string $haystack): bool
{
    $haystack = strtolower(trim($haystack));
    foreach (explode(',', $csvList) as $pattern) {
        $pattern = strtolower(trim($pattern));
        if ($pattern === '') {
            continue;
        }
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
        if (preg_match($regex, $haystack)) {
            return true;
        }
    }
    return false;
}

/**
 * Parse "OS>=14.0,Android>=10" into ['ios' => '14.0', 'android' => '10'].
 */
function parse_os_min_versions(string $spec): array
{
    $out = [];
    foreach (explode(',', $spec) as $entry) {
        $entry = trim($entry);
        if ($entry === '' || !preg_match('/^([a-zA-Z0-9 ._-]+)>=([0-9]+(?:\.[0-9]+)*)$/', $entry, $m)) {
            continue;
        }
        $out[strtolower(trim($m[1]))] = $m[2];
    }
    return $out;
}

/**
 * Compare dotted numeric versions numerically.
 */
function version_at_least(string $have, string $need): bool
{
    $have = trim((string) preg_replace('/[^0-9.]/', '', $have));
    $need = trim((string) preg_replace('/[^0-9.]/', '', $need));

    if ($need === '') {
        return true;
    }
    if ($have === '') {
        return false;
    }

    return version_compare($have, $need, '>=');
}

/**
 * Screen-resolution tier from fingerprint data.
 */
function resolution_tier(float $w, float $h, float $dpr, bool $touch): string
{
    if (!$touch || ($w <= 0 && $h <= 0)) {
        return $w <= 0 && $h <= 0 ? 'unknown' : 'pc';
    }
    if ($w <= 430 && $h >= 560 && $dpr >= 2) {
        return 'iphone';
    }
    if ($w <= 480) {
        return 'android_s';
    }
    if ($w <= 720) {
        return 'android_m';
    }
    if ($w <= 1080) {
        return 'android_l';
    }
    if ($w <= 1400) {
        return 'tablet';
    }
    return 'pc';
}

const RESOLUTION_TIERS = [
    'iphone'    => 'iPhone (≤430px, retina)',
    'android_s' => 'Android small (≤480px)',
    'android_m' => 'Android medium (≤720px)',
    'android_l' => 'Android large (≤1080px)',
    'tablet'    => 'Tablet (≤1400px)',
    'pc'        => 'Desktop / PC',
    'unknown'   => 'Unknown',
];

/**
 * Encode a fingerprint array into a URL-safe token.
 */
function fingerprint_encode(array $fp): string
{
    return rtrim(strtr(base64_encode(json_encode($fp)), '+/', '-_'), '=');
}

/**
 * Decode a fingerprint payload. Returns [] on failure.
 */
function fingerprint_decode(string $token): array
{
    $raw = base64_decode(strtr($token, '-_', '+/'));
    if ($raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Campaign presets (used by the admin UI to prefill rules).
 */
const CAMPAIGN_PRESETS = [
    'fb_mobile' => [
        'label' => 'Facebook / Instagram / Threads ads (mobile)',
        'rules' => [
            'allowed_clients'     => 'facebook,instagram,threads',
            'allowed_devices'     => 'mobile',
            'allowed_os'          => 'iOS,Android',
            'block_datacenters'   => 1,
            'block_vpn'           => 1,
            'block_headless'      => 1,
            'block_review_infra'  => 1,
            // In-app clicks often arrive WITHOUT a referer — keep empty allowed
            'allow_empty_referer' => 1,
            'allowed_referrers'   => 'facebook.com,*.facebook.com,instagram.com,*.instagram.com,threads.net,*.threads.net',
        ],
    ],
    'tiktok_mobile' => [
        'label' => 'TikTok video ads (mobile)',
        'rules' => [
            'allowed_clients'    => 'tiktok',
            'allowed_devices'    => 'mobile',
            'allowed_os'         => 'iOS,Android',
            'block_datacenters'  => 1,
            'block_vpn'          => 1,
            'block_headless'     => 1,
            'block_review_infra' => 1,
            // TikTok in-app clicks often have no referer
            'allow_empty_referer'=> 1,
        ],
    ],
    'google_desktop' => [
        'label' => 'Google search ads (desktop)',
        'rules' => [
            'allowed_devices'     => 'desktop',
            'allowed_os'          => 'Windows,macOS,Linux,Chrome OS',
            'allow_empty_referer' => 0,
            'allowed_referrers'   => 'google.com,*.google.com,google.*,*.google.*',
            'required_url_params' => 'utm_source=*',
            'block_datacenters'   => 1,
            'block_review_infra'  => 1,
        ],
    ],
];

/**
 * Human-readable device list for the admin UI.
 */
const DEVICE_TYPES = [
    'desktop'  => 'Desktop',
    'mobile'   => 'Mobile',
    'tablet'   => 'Tablet',
    'smarttv'  => 'Smart TV',
    'console'  => 'Game console',
    'wearable' => 'Wearable',
];

/**
 * Known in-app client list for the admin UI.
 */
const CLIENT_TYPES = [
    'facebook'  => 'Facebook (in-app)',
    'instagram' => 'Instagram (in-app)',
    'threads'   => 'Threads (in-app)',
    'tiktok'    => 'TikTok / Douyin (in-app)',
    'twitter'   => 'Twitter / X (in-app)',
    'linkedin'  => 'LinkedIn (in-app)',
    'line'      => 'LINE (in-app)',
    'kakaotalk' => 'KakaoTalk (in-app)',
    'wechat'    => 'WeChat (in-app)',
    'telegram'  => 'Telegram (in-app)',
    'snapchat'  => 'Snapchat (in-app)',
];

/**
 * In-app clients typically send no Referer — they are exempt from the
 * "referer_missing" denial.
 */
const IN_APP_CLIENTS = [
    'facebook', 'instagram', 'threads', 'tiktok', 'twitter', 'linkedin',
    'line', 'kakaotalk', 'wechat', 'telegram', 'snapchat',
];

const RULE_COLUMNS = [
    'block_bots', 'block_datacenters', 'block_review_infra', 'block_vpn', 'block_tor', 'block_headless', 'block_curl', 'block_ipv6',
    'allowed_countries', 'blocked_countries',
    'allowed_clients', 'blocked_clients',
    'allowed_devices', 'blocked_devices',
    'allowed_os', 'blocked_os', 'os_min_versions',
    'allowed_languages', 'blocked_languages',
    'allowed_referrers', 'blocked_referrers', 'allow_empty_referer',
    'required_url_params', 'blocked_url_params', 'required_url_keywords',
    'allowed_resolutions', 'blocked_resolutions',
    'require_screen_info', 'single_visit_only',
    'offer_urls', 'rotation_mode', 'offer_routes',
    'offer_method', 'forward_utms', 'no_cache', 'fast_mode',
    'delay_start', 'delay_permanent', 'allow_geo_override',
    'ip_allowlist',
];

const FLAG_COLUMNS = [
    'is_active',
    'block_bots', 'block_datacenters', 'block_review_infra', 'block_vpn', 'block_tor', 'block_headless', 'block_curl', 'block_ipv6',
    'allow_empty_referer', 'require_screen_info', 'single_visit_only',
    'forward_utms', 'no_cache', 'fast_mode', 'delay_permanent', 'allow_geo_override',
];

const REDIRECT_TYPES = ['301', '302', '303', 'meta'];
const ROTATION_MODES = ['single', 'random', 'sequential'];
const OFFER_METHODS = ['redirect', 'iframe'];
const REJECT_MODES = ['white', 'error'];

const CAMPAIGN_MUTABLE_COLUMNS = [
    'name', 'is_active', 'offer_url', 'white_page', 'reject_mode', 'reject_code', 'redirect_type', 'redirect_delay',
    'block_bots', 'block_datacenters', 'block_review_infra', 'block_vpn', 'block_tor', 'block_headless', 'block_curl',
    'allowed_countries', 'blocked_countries', 'allowed_clients', 'blocked_clients',
    'allowed_devices', 'blocked_devices', 'allowed_os', 'blocked_os', 'os_min_versions',
    'allowed_languages', 'blocked_languages', 'allowed_referrers', 'blocked_referrers', 'allow_empty_referer',
    'required_url_params', 'blocked_url_params', 'required_url_keywords',
    'allowed_resolutions', 'blocked_resolutions', 'require_screen_info', 'single_visit_only',
    'offer_urls', 'rotation_mode', 'offer_routes',
    'offer_method', 'forward_utms', 'no_cache', 'fast_mode', 'delay_start', 'delay_permanent', 'allow_geo_override',
    'ip_allowlist', 'block_ipv6',
];

const LINK_MUTABLE_COLUMNS = [
    'name', 'campaign_id', 'domain_id', 'offer_url', 'white_page', 'redirect_type', 'redirect_delay', 'is_active',
    'block_bots', 'block_datacenters', 'block_review_infra', 'block_vpn', 'block_tor', 'block_headless', 'block_curl',
    'allowed_countries', 'blocked_countries', 'allowed_clients', 'blocked_clients',
    'allowed_devices', 'blocked_devices', 'allowed_os', 'blocked_os', 'os_min_versions',
    'allowed_languages', 'blocked_languages', 'allowed_referrers', 'blocked_referrers', 'allow_empty_referer',
    'required_url_params', 'blocked_url_params', 'required_url_keywords',
    'allowed_resolutions', 'blocked_resolutions', 'require_screen_info', 'single_visit_only',
    'offer_urls', 'rotation_mode', 'offer_routes',
    'offer_method', 'forward_utms', 'no_cache', 'fast_mode', 'delay_start', 'delay_permanent', 'allow_geo_override',
    'ip_allowlist', 'block_ipv6',
];

/**
 * Parse an offer pool: one URL per line (or comma-separated).
 * Returns only valid http(s) URLs, in order.
 */
function parse_offer_urls(string $spec): array
{
    $urls = [];
    foreach (preg_split('/[\r\n,]+/', $spec) as $url) {
        $url = trim($url);
        if ($url !== '' && is_valid_offer_url($url) && !in_array($url, $urls, true)) {
            $urls[] = $url;
        }
    }
    return $urls;
}

/**
 * Parse country offer routes: "US=https://..." lines (or "CC:url").
 * "*" is the fallback. Returns [CODE => url].
 */
function parse_offer_routes(string $spec): array
{
    $routes = [];
    foreach (preg_split('/[\r\n]+/', $spec) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (!preg_match('/^(\*|[A-Za-z]{2})\s*[:=]\s*(https?:\/\/\S+)$/', $line, $m)) {
            continue;
        }
        $routes[strtoupper($m[1])] = $m[2];
    }
    return $routes;
}

function normalize_redirect_type(string $value): string
{
    return in_array($value, REDIRECT_TYPES, true) ? $value : '302';
}

function normalize_rotation_mode(string $value): string
{
    return in_array($value, ROTATION_MODES, true) ? $value : 'single';
}

function normalize_offer_method(string $value): string
{
    return in_array($value, OFFER_METHODS, true) ? $value : 'redirect';
}

function normalize_reject_mode(string $value): string
{
    return in_array($value, REJECT_MODES, true) ? $value : 'white';
}

function parse_campaign_input(array $input, array $existing = [], array $options = []): array
{
    $source = (($options['source'] ?? 'form') === 'api') ? 'api' : 'form';
    $partial = !empty($options['partial']);
    $rules = parse_rule_input($input, $existing, $source, $partial);

    $offerUrl = parse_input_string($input, 'offer_url', 2048, $existing, $partial);
    if ($offerUrl !== '' && !is_valid_offer_url($offerUrl)) {
        throw new BadRequestException('Invalid offer_url.', 400);
    }

    return array_merge([
        'name' => parse_input_string($input, 'name', 255, $existing, $partial),
        'is_active' => parse_input_flag($input, 'is_active', (int) ($existing['is_active'] ?? 1), $source, $partial, 1),
        'offer_url' => $offerUrl,
        'white_page' => parse_input_raw_string($input, 'white_page', 65535, $existing, $partial),
        'reject_mode' => normalize_reject_mode(parse_input_string($input, 'reject_mode', 32, $existing, $partial, 'white')),
        'reject_code' => parse_input_int($input, 'reject_code', (int) ($existing['reject_code'] ?? 403), 400, 599, $partial),
        'redirect_type' => normalize_redirect_type(parse_input_string($input, 'redirect_type', 32, $existing, $partial, '302')),
        'redirect_delay' => parse_input_int($input, 'redirect_delay', (int) ($existing['redirect_delay'] ?? 0), 0, 30, $partial),
    ], $rules);
}

function parse_link_input(array $input, array $existing = [], array $options = []): array
{
    $source = (($options['source'] ?? 'form') === 'api') ? 'api' : 'form';
    $partial = !empty($options['partial']);
    $campaignId = parse_input_nullable_id($input, 'campaign_id', $existing, $partial);
    $domainId = parse_input_nullable_id($input, 'domain_id', $existing, $partial);
    $rules = parse_rule_input($input, $existing, $source, $partial);

    $slug = parse_input_string($input, 'slug', 128, $existing, $partial);
    if ($slug === '' && !$partial && empty($existing)) {
        $slug = bin2hex(random_bytes(6));
    }
    if ($slug !== '' && !is_valid_slug($slug)) {
        throw new BadRequestException('Invalid slug.', 400);
    }

    $offerUrl = parse_input_string($input, 'offer_url', 2048, $existing, $partial);
    if ($offerUrl !== '' && !is_valid_offer_url($offerUrl)) {
        throw new BadRequestException('Invalid offer_url.', 400);
    }

    if ($campaignId === null && $offerUrl === '' && $rules['offer_urls'] === '' && $rules['offer_routes'] === '') {
        throw new BadRequestException('Offer URL, pool, or routes are required.', 400);
    }

    return array_merge([
        'slug' => $slug,
        'name' => parse_input_string($input, 'name', 255, $existing, $partial),
        'campaign_id' => $campaignId,
        'domain_id' => $domainId,
        'offer_url' => $campaignId === null ? $offerUrl : '',
        'white_page' => parse_input_raw_string($input, 'white_page', 65535, $existing, $partial),
        'redirect_type' => normalize_redirect_type(parse_input_string($input, 'redirect_type', 32, $existing, $partial, '302')),
        'redirect_delay' => parse_input_int($input, 'redirect_delay', (int) ($existing['redirect_delay'] ?? 0), 0, 30, $partial),
        'is_active' => parse_input_flag($input, 'is_active', (int) ($existing['is_active'] ?? 1), $source, $partial, 1),
    ], $rules);
}

function parse_rule_input(array $input, array $existing, string $source, bool $partial): array
{
    $out = [];
    foreach (RULE_COLUMNS as $column) {
        if (in_array($column, FLAG_COLUMNS, true)) {
            $out[$column] = parse_input_flag($input, $column, (int) ($existing[$column] ?? 0), $source, $partial);
            continue;
        }

        if ($column === 'rotation_mode') {
            $out[$column] = normalize_rotation_mode(parse_input_string($input, $column, 32, $existing, $partial, 'single'));
            continue;
        }

        if ($column === 'offer_method') {
            $out[$column] = normalize_offer_method(parse_input_string($input, $column, 32, $existing, $partial, 'redirect'));
            continue;
        }

        if ($column === 'delay_start') {
            $out[$column] = parse_input_int($input, $column, (int) ($existing[$column] ?? 0), 0, 100000, $partial);
            continue;
        }

        if ($column === 'offer_urls') {
            $out[$column] = normalize_offer_urls_spec(parse_input_raw_string($input, $column, 16384, $existing, $partial), $column);
            continue;
        }

        if ($column === 'offer_routes') {
            $out[$column] = normalize_offer_routes_spec(parse_input_raw_string($input, $column, 16384, $existing, $partial), $column);
            continue;
        }

        $out[$column] = parse_input_string($input, $column, 4096, $existing, $partial);
    }

    return $out;
}

function parse_input_string(array $input, string $name, int $maxBytes, array $existing, bool $partial, string $default = ''): string
{
    if (array_key_exists($name, $input)) {
        return trim(app_scalar_value($input[$name], $name, $maxBytes) ?? '');
    }

    if ($partial) {
        return trim((string) ($existing[$name] ?? $default));
    }

    return $default;
}

function parse_input_raw_string(array $input, string $name, int $maxBytes, array $existing, bool $partial, string $default = ''): string
{
    if (array_key_exists($name, $input)) {
        return (string) (app_scalar_value($input[$name], $name, $maxBytes) ?? '');
    }

    if ($partial) {
        return (string) ($existing[$name] ?? $default);
    }

    return $default;
}

function parse_input_flag(array $input, string $name, int $default, string $source, bool $partial, int $createDefault = 0): int
{
    if ($source === 'form') {
        return app_array_flag($input, $name, $default, $partial);
    }

    if (array_key_exists($name, $input)) {
        return app_array_flag($input, $name, $default, true);
    }

    return $partial ? $default : $createDefault;
}function parse_input_int(array $input, string $name, int $default, int $min, int $max, bool $partial): int
{
    return app_array_clamped_int($input, $name, $default, $min, $max, $partial, $name);
}

function parse_input_nullable_id(array $input, string $name, array $existing, bool $partial): ?int
{
    if (array_key_exists($name, $input)) {
        $value = trim(app_scalar_value($input[$name], $name, 64) ?? '');
        if ($value === '') {
            return null;
        }

        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    if ($partial && array_key_exists($name, $existing)) {
        $id = (int) $existing[$name];
        return $id > 0 ? $id : null;
    }

    return null;
}

function normalize_offer_urls_spec(string $spec, string $label = 'offer_urls'): string
{
    if (trim($spec) === '') {
        return '';
    }

    $urls = [];
    foreach (preg_split('/[\r\n,]+/', $spec) as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate === '') {
            continue;
        }
        if (!is_valid_offer_url($candidate)) {
            throw new BadRequestException("Invalid {$label}.", 400);
        }
        if (!in_array($candidate, $urls, true)) {
            $urls[] = $candidate;
        }
    }

    return implode("\n", $urls);
}

function normalize_offer_routes_spec(string $spec, string $label = 'offer_routes'): string
{
    if (trim($spec) === '') {
        return '';
    }

    $routes = [];
    foreach (preg_split('/[\r\n]+/', $spec) as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        if (!preg_match('/^(\*|[A-Za-z]{2})\s*[:=]\s*(.+)$/', $line, $matches)) {
            throw new BadRequestException("Invalid {$label}.", 400);
        }

        $country = strtoupper($matches[1]);
        $url = trim($matches[2]);
        if (!is_valid_offer_url($url)) {
            throw new BadRequestException("Invalid {$label}.", 400);
        }

        $routes[$country] = $country . '=' . $url;
    }

    return implode("\n", array_values($routes));
}

function owned_row(PDO $db, string $table, int $id, int $userId): ?array
{
    $stmt = $db->prepare("SELECT * FROM {$table} WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);

    return $stmt->fetch() ?: null;
}

function delete_campaign_safely(PDO $db, int $userId, int $campaignId): ?string
{
    return delete_owned_row_without_references(
        $db,
        'campaigns',
        'campaign_id',
        $userId,
        $campaignId,
        'Campaign is still assigned to one or more links.',
        'Campaign could not be deleted safely while related links are being updated. Please retry.'
    );
}

function delete_domain_safely(PDO $db, int $userId, int $domainId): ?string
{
    return delete_owned_row_without_references(
        $db,
        'domains',
        'domain_id',
        $userId,
        $domainId,
        'Domain is still assigned to one or more links. Reassign those links first.',
        'Domain could not be deleted safely while related links are being updated. Please retry.'
    );
}

function referenced_link_count(PDO $db, string $column, int $userId, int $id): int
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM links WHERE user_id = ? AND {$column} = ?");
    $stmt->execute([$userId, $id]);

    return (int) $stmt->fetchColumn();
}

function delete_owned_row_without_references(
    PDO $db,
    string $table,
    string $referenceColumn,
    int $userId,
    int $rowId,
    string $referenceMessage,
    string $lockedMessage
): ?string {
    try {
        $db->exec('BEGIN IMMEDIATE');
    } catch (Throwable $e) {
        if (is_sqlite_busy_error($e)) {
            return $lockedMessage;
        }
        throw $e;
    }

    try {
        $linkCount = referenced_link_count($db, $referenceColumn, $userId, $rowId);
        if ($linkCount > 0) {
            $db->exec('ROLLBACK');
            return $referenceMessage;
        }

        $db->prepare("DELETE FROM {$table} WHERE id = ? AND user_id = ?")->execute([$rowId, $userId]);
        $db->exec('COMMIT');

        return null;
    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable) {
        }
        if (is_sqlite_busy_error($e)) {
            return $lockedMessage;
        }
        throw $e;
    }
}

function is_sqlite_busy_error(Throwable $e): bool
{
    $message = strtolower($e->getMessage());

    return strpos($message, 'database is locked') !== false
        || strpos($message, 'database table is locked') !== false
        || strpos($message, 'database schema is locked') !== false;
}

/**
 * Resolve the final offer target for a visitor:
 *  1. country route (exact match, then "*" fallback)
 *  2. offer pool with rotation (random or sequential)
 *  3. single offer_url
 */
function resolve_offer_target(array $rules, string $country, int $offerShows): string
{
    $routes = parse_offer_routes((string)($rules['offer_routes'] ?? ''));
    if ($routes !== []) {
        $cc = strtoupper($country);
        if (isset($routes[$cc]) && is_valid_offer_url($routes[$cc])) {
            return $routes[$cc];
        }
        if (isset($routes['*']) && is_valid_offer_url($routes['*'])) {
            return $routes['*'];
        }
    }

    $pool = parse_offer_urls((string)($rules['offer_urls'] ?? ''));
    if ($pool !== []) {
        $mode = (string)($rules['rotation_mode'] ?? 'random');
        if ($mode === 'sequential') {
            return $pool[$offerShows % count($pool)];
        }
        return $pool[random_int(0, count($pool) - 1)];
    }

    return (string)($rules['offer_url'] ?? '');
}

function build_delivery_config(array $rules, string $country, int $offerShows): array
{
    $target = resolve_offer_target($rules, $country, $offerShows);

    return [
        'offer_url' => is_valid_offer_url($target) ? $target : '',
        'offer_method' => normalize_offer_method((string) ($rules['offer_method'] ?? 'redirect')),
        'redirect_type' => normalize_redirect_type((string) ($rules['redirect_type'] ?? '302')),
        'redirect_delay' => max(0, min(30, (int) ($rules['redirect_delay'] ?? 0))),
    ];
}

/**
 * Delay-start filter: block the first N unique IPs for a link/campaign.
 * Mirrors the reference script's DELAY_START / DELAY_PERMANENT behavior:
 *  - while the pool of seen IPs is below the limit, new IPs are recorded and denied
 *  - IPs recorded during the window stay denied only if permanent is set
 * Returns a deny reason, or '' when the visitor is allowed to pass this filter.
 */
function delay_start_check(PDO $db, int $scopeId, bool $isCampaign, string $ip, array $rules): string
{
    $limit = (int)($rules['delay_start'] ?? 0);
    if ($limit <= 0 || $ip === '') {
        return '';
    }
    $permanent = !empty($rules['delay_permanent']);
    $hash = hash('sha256', $ip);
    $col = $isCampaign ? 'campaign_id' : 'link_id';
    $db->exec('BEGIN IMMEDIATE');

    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM delay_ips WHERE {$col} = ?");
        $stmt->execute([$scopeId]);
        $count = (int) $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM delay_ips WHERE {$col} = ? AND ip_hash = ?");
        $stmt->execute([$scopeId, $hash]);
        $seen = (int) $stmt->fetchColumn() > 0;

        if ($seen) {
            $db->exec('COMMIT');
            return ($permanent || $count < $limit) ? 'delay_start' : '';
        }

        if ($count < $limit) {
            $db->prepare("INSERT INTO delay_ips ({$col}, ip_hash) VALUES (?, ?)")->execute([$scopeId, $hash]);
            $db->exec('COMMIT');
            return 'delay_start';
        }

        $db->exec('COMMIT');
        return '';
    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable) {
        }
        throw $e;
    }
}

/**
 * Derive a traffic source label for analytics:
 * client type > utm_source > referer host > direct.
 */
function derive_source(string $referer, string $clientType, string $utmSource): string
{
    if ($clientType !== '') {
        return $clientType;
    }
    if ($utmSource !== '') {
        return 'utm:' . strtolower($utmSource);
    }
    $host = strtolower((string)(parse_url($referer, PHP_URL_HOST) ?: ''));
    if ($host !== '') {
        foreach ([
            'google', 'facebook', 'instagram', 'threads', 'tiktok', 'douyin',
            'twitter', 'x.com', 'linkedin', 'bing', 'yahoo', 'yandex',
            'pinterest', 'youtube', 'snapchat', 'telegram', 'whatsapp',
            'line', 'kakao', 'wechat', 'reddit', 'quora', 't.co',
        ] as $label) {
            if (strpos($host, $label) !== false) {
                return $label;
            }
        }
        return $host;
    }
    return 'direct';
}
