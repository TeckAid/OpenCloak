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
 * Parse "OS>=14.0,Android>=10" into ['ios' => 14.0, 'android' => 10].
 */
function parse_os_min_versions(string $spec): array
{
    $out = [];
    foreach (explode(',', $spec) as $entry) {
        $entry = trim($entry);
        if ($entry === '' || !preg_match('/^([a-zA-Z0-9 ._-]+)>=([0-9]+(?:\.[0-9]+)?)$/', $entry, $m)) {
            continue;
        }
        $out[strtolower(trim($m[1]))] = (float)$m[2];
    }
    return $out;
}

/**
 * Compare dotted numeric versions numerically.
 */
function version_at_least(string $have, float $need): bool
{
    return ((float)preg_replace('/[^0-9.]/', '', $have) ?: 0) >= $need;
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

    $stmt = $db->prepare("SELECT COUNT(*) FROM delay_ips WHERE {$col} = ?");
    $stmt->execute([$scopeId]);
    $count = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM delay_ips WHERE {$col} = ? AND ip_hash = ?");
    $stmt->execute([$scopeId, $hash]);
    $seen = (int)$stmt->fetchColumn() > 0;

    if ($seen) {
        return ($permanent || $count <= $limit) ? 'delay_start' : '';
    }
    if ($count <= $limit) {
        $db->prepare("INSERT INTO delay_ips ({$col}, ip_hash) VALUES (?, ?)")->execute([$scopeId, $hash]);
        return 'delay_start';
    }
    return '';
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
