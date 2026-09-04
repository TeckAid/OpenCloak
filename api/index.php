<?php
/**
 * API Endpoint
 *
 * Authentication: Authorization: Bearer {api_key} header ONLY.
 *
 *   GET    /api/links              List links
 *   POST   /api/links              Create link
 *   GET    /api/links/{id}         Get link
 *   PUT    /api/links/{id}         Update link
 *   DELETE /api/links/{id}         Delete link
 *   GET    /api/stats/{id}         Link statistics
 *
 *   GET    /api/campaigns          List campaigns
 *   POST   /api/campaigns          Create campaign
 *   GET    /api/campaigns/{id}     Get campaign
 *   PUT    /api/campaigns/{id}     Update campaign
 *   DELETE /api/campaigns/{id}     Delete campaign
 *   POST   /api/campaigns/{id}/clone  Clone campaign
 *
 *   GET    /api/domains            List domains
 *   POST   /api/domains            Add custom domain
 *   PUT    /api/domains/{id}       Update domain
 *   DELETE /api/domains/{id}       Delete domain
 *
 *   POST   /api/verify             Verify a visitor (client-deployment mode)
 */

require_once __DIR__ . '/../includes/bootstrap.php';
boot_app(false);

require_once __DIR__ . '/../includes/rules.php';
require_once __DIR__ . '/../includes/bot_detector.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = preg_replace('#^/api#', '', $path);
$segments = array_values(array_filter(explode('/', $path)));

// ---- Authenticate -----------------------------------------------------------
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$apiKey = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $apiKey = trim($m[1]);
}
if ($apiKey === '') {
    apiError('API key required. Use the Authorization: Bearer header.', 401);
}

$db = getDB();
$stmt = $db->prepare("SELECT id FROM users WHERE api_key = ?");
$stmt->execute([$apiKey]);
$user = $stmt->fetch();

if (!$user) {
    apiError('Invalid API key.', 401);
}
$userId = (int)$user['id'];

// ---- Helpers ------------------------------------------------------------------
function apiError(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

function apiSuccess($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function getSegmentId(array $segments, int $index): ?int
{
    if (isset($segments[$index]) && ctype_digit($segments[$index])) {
        return (int)$segments[$index];
    }
    return null;
}

function readJsonBody(): array
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        apiError('Invalid JSON body.', 400);
    }
    return $input;
}

const RULE_COLUMNS = [
    'block_bots', 'block_datacenters', 'block_review_infra', 'block_vpn', 'block_tor', 'block_headless', 'block_curl',
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
];

const FLAG_COLUMNS = [
    'block_bots', 'block_datacenters', 'block_review_infra', 'block_vpn', 'block_tor', 'block_headless', 'block_curl',
    'allow_empty_referer', 'require_screen_info', 'single_visit_only',
    'forward_utms', 'no_cache', 'fast_mode', 'delay_permanent', 'allow_geo_override',
];

/** Schema defaults for flag columns (must match database.php). */
const FLAG_DEFAULTS = [
    'block_bots'         => 1,
    'block_datacenters'  => 1,
    'block_review_infra' => 1,
    'block_vpn'          => 0,
    'block_tor'          => 1,
    'block_headless'     => 1,
    'block_curl'         => 1,
    'allow_empty_referer'=> 1,
    'require_screen_info'=> 0,
    'single_visit_only'  => 0,
    'forward_utms'       => 0,
    'no_cache'           => 0,
    'fast_mode'          => 0,
    'delay_permanent'    => 0,
    'allow_geo_override' => 0,
];

function sanitizeRules(array $input, array $existing = []): array
{
    $out = [];
    foreach (RULE_COLUMNS as $col) {
        if (in_array($col, FLAG_COLUMNS, true)) {
            $out[$col] = array_key_exists($col, $input)
                ? ((int)$input[$col] ? 1 : 0)
                : (int)($existing[$col] ?? FLAG_DEFAULTS[$col] ?? 0);
        } elseif ($col === 'rotation_mode') {
            $mode = (string)($input[$col] ?? $existing[$col] ?? 'single');
            $out[$col] = in_array($mode, ['single', 'random', 'sequential'], true) ? $mode : 'single';
        } elseif ($col === 'offer_method') {
            $method = (string)($input[$col] ?? $existing[$col] ?? 'redirect');
            $out[$col] = in_array($method, ['redirect', 'iframe'], true) ? $method : 'redirect';
        } elseif ($col === 'delay_start') {
            $out[$col] = max(0, min(100000, (int)($input[$col] ?? $existing[$col] ?? 0)));
        } else {
            $out[$col] = trim((string)($input[$col] ?? $existing[$col] ?? ''));
        }
    }
    return $out;
}

// ---- Routing --------------------------------------------------------------------
$resource = $segments[0] ?? '';
$id = getSegmentId($segments, 1);

switch ($resource) {
    // ============================ LINKS ========================================
    case 'links':
        if ($method === 'GET' && $id === null) {
            $stmt = $db->prepare("
                SELECT l.*, c.name AS campaign_name, d.domain AS domain_name
                FROM links l
                LEFT JOIN campaigns c ON c.id = l.campaign_id
                LEFT JOIN domains d ON d.id = l.domain_id
                WHERE l.user_id = ? ORDER BY l.created_at DESC");
            $stmt->execute([$userId]);
            apiSuccess(['links' => $stmt->fetchAll()]);
        }

        if ($method === 'POST' && $id === null) {
            $input = readJsonBody();

            $slug = trim((string)($input['slug'] ?? ''));
            if ($slug === '') $slug = bin2hex(random_bytes(6));
            if (!is_valid_slug($slug)) {
                apiError('Invalid slug: use 1-64 chars of [a-zA-Z0-9_-] and avoid reserved words.', 400);
            }
            if (!is_valid_offer_url((string)($input['offer_url'] ?? ''))) {
                apiError('offer_url must be a valid http(s) URL.', 400);
            }

            $campaignId = null;
            if (!empty($input['campaign_id'])) {
                $c = $db->prepare("SELECT id FROM campaigns WHERE id = ? AND user_id = ?");
                $c->execute([(int)$input['campaign_id'], $userId]);
                if (!$c->fetch()) apiError('Campaign not found.', 404);
                $campaignId = (int)$input['campaign_id'];
            }
            $domainId = null;
            if (!empty($input['domain_id'])) {
                $d = $db->prepare("SELECT id FROM domains WHERE id = ? AND user_id = ?");
                $d->execute([(int)$input['domain_id'], $userId]);
                if (!$d->fetch()) apiError('Domain not found.', 404);
                $domainId = (int)$input['domain_id'];
            }

            $check = $db->prepare("SELECT id FROM links WHERE slug = ?");
            $check->execute([$slug]);
            if ($check->fetch()) apiError('Slug already exists.', 409);

            $r = sanitizeRules($input);
            $rt = (string)($input['redirect_type'] ?? '302');
            $redirectType = in_array($rt, ['301', '302', '303', 'meta'], true) ? $rt : '302';

            $linkColumns = array_merge(
                ['user_id', 'slug', 'name', 'campaign_id', 'domain_id', 'offer_url', 'white_page', 'redirect_type', 'redirect_delay'],
                RULE_COLUMNS
            );
            $linkValues = array_merge(
                [
                    $userId, $slug, trim((string)($input['name'] ?? '')), $campaignId, $domainId,
                    trim((string)$input['offer_url']), $input['white_page'] ?? '', $redirectType,
                    max(0, min(30, (int)($input['redirect_delay'] ?? 0))),
                ],
                array_map(fn($col) => $r[$col], RULE_COLUMNS)
            );
            $stmt = $db->prepare(
                "INSERT INTO links (" . implode(',', $linkColumns) . ")
                 VALUES (" . implode(',', array_fill(0, count($linkColumns), '?')) . ")"
            );
            $stmt->execute($linkValues);

            $linkId = (int)$db->lastInsertId();
            $stmt = $db->prepare("SELECT * FROM links WHERE id = ?");
            $stmt->execute([$linkId]);
            apiSuccess(['link' => $stmt->fetch()], 201);
        }

        if (($method === 'GET' || $method === 'PUT' || $method === 'DELETE') && $id !== null) {
            $stmt = $db->prepare("SELECT * FROM links WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
            $link = $stmt->fetch();
            if (!$link) apiError('Link not found.', 404);

            if ($method === 'GET') {
                apiSuccess(['link' => $link]);
            }
            if ($method === 'DELETE') {
                $db->prepare("DELETE FROM links WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
                apiSuccess(['message' => 'Link deleted']);
            }
            if ($method === 'PUT') {
                $input = readJsonBody();
                if (array_key_exists('offer_url', $input) && !is_valid_offer_url((string)$input['offer_url'])) {
                    apiError('offer_url must be a valid http(s) URL.', 400);
                }
                $r = sanitizeRules($input, $link);
                $rt = (string)($input['redirect_type'] ?? $link['redirect_type']);
                $redirectType = in_array($rt, ['301', '302', '303', 'meta'], true) ? $rt : '302';

                $setCols = array_merge(
                    ['name = ?', 'offer_url = ?', 'white_page = ?', 'redirect_type = ?', 'redirect_delay = ?', 'is_active = ?'],
                    array_map(fn($col) => "{$col} = ?", RULE_COLUMNS),
                    ['updated_at = CURRENT_TIMESTAMP']
                );
                $setValues = array_merge(
                    [
                        trim((string)($input['name'] ?? $link['name'])),
                        trim((string)($input['offer_url'] ?? $link['offer_url'])),
                        $input['white_page'] ?? $link['white_page'],
                        $redirectType,
                        max(0, min(30, (int)($input['redirect_delay'] ?? $link['redirect_delay']))),
                        (int)($input['is_active'] ?? $link['is_active']) ? 1 : 0,
                    ],
                    array_map(fn($col) => $r[$col], RULE_COLUMNS),
                    [$id, $userId]
                );
                $db->prepare("UPDATE links SET " . implode(', ', $setCols) . " WHERE id = ? AND user_id = ?")
                   ->execute($setValues);

                $stmt = $db->prepare("SELECT * FROM links WHERE id = ?");
                $stmt->execute([$id]);
                apiSuccess(['link' => $stmt->fetch()]);
            }
        }
        break;

    // ============================ CAMPAIGNS =====================================
    case 'campaigns':
        if ($method === 'GET' && $id === null) {
            $stmt = $db->prepare("SELECT * FROM campaigns WHERE user_id = ? ORDER BY created_at DESC");
            $stmt->execute([$userId]);
            apiSuccess(['campaigns' => $stmt->fetchAll()]);
        }

        if ($method === 'POST' && $id === null) {
            $input = readJsonBody();
            $r = sanitizeRules($input);
            $rt = (string)($input['redirect_type'] ?? '302');
            $redirectType = in_array($rt, ['301', '302', '303', 'meta'], true) ? $rt : '302';

            $campColumns = array_merge(
                ['user_id', 'name', 'is_active', 'offer_url', 'white_page', 'reject_mode', 'reject_code', 'redirect_type', 'redirect_delay'],
                RULE_COLUMNS
            );
            $campValues = array_merge(
                [
                    $userId, trim((string)($input['name'] ?? '')), (int)($input['is_active'] ?? 1) ? 1 : 0,
                    trim((string)($input['offer_url'] ?? '')), $input['white_page'] ?? '',
                    in_array((string)($input['reject_mode'] ?? 'white'), ['white', 'error'], true)
                        ? (string)($input['reject_mode'] ?? 'white') : 'white',
                    (int)($input['reject_code'] ?? 403),
                    $redirectType,
                    max(0, min(30, (int)($input['redirect_delay'] ?? 0))),
                ],
                array_map(fn($col) => $r[$col], RULE_COLUMNS)
            );
            $stmt = $db->prepare(
                "INSERT INTO campaigns (" . implode(',', $campColumns) . ")
                 VALUES (" . implode(',', array_fill(0, count($campColumns), '?')) . ")"
            );
            $stmt->execute($campValues);
            $campaignId = (int)$db->lastInsertId();
            $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ?");
            $stmt->execute([$campaignId]);
            apiSuccess(['campaign' => $stmt->fetch()], 201);
        }

        if (($method === 'GET' || $method === 'PUT' || $method === 'DELETE') && $id !== null) {
            $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
            $campaign = $stmt->fetch();
            if (!$campaign) apiError('Campaign not found.', 404);

            if ($method === 'GET') {
                apiSuccess(['campaign' => $campaign]);
            }
            if ($method === 'DELETE') {
                $db->prepare("UPDATE links SET campaign_id = NULL WHERE campaign_id = ? AND user_id = ?")
                   ->execute([$id, $userId]);
                $db->prepare("DELETE FROM campaigns WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
                apiSuccess(['message' => 'Campaign deleted']);
            }
            if ($method === 'PUT') {
                $input = readJsonBody();
                $r = sanitizeRules($input, $campaign);
                $rt = (string)($input['redirect_type'] ?? $campaign['redirect_type']);
                $redirectType = in_array($rt, ['301', '302', '303', '303', 'meta'], true) ? $rt : '302';

                $setCols = array_merge(
                    ['name = ?', 'is_active = ?', 'offer_url = ?', 'white_page = ?', 'reject_mode = ?',
                     'reject_code = ?', 'redirect_type = ?', 'redirect_delay = ?'],
                    array_map(fn($col) => "{$col} = ?", RULE_COLUMNS),
                    ['updated_at = CURRENT_TIMESTAMP']
                );
                $setValues = array_merge(
                    [
                        trim((string)($input['name'] ?? $campaign['name'])),
                        (int)($input['is_active'] ?? $campaign['is_active']) ? 1 : 0,
                        trim((string)($input['offer_url'] ?? $campaign['offer_url'])),
                        $input['white_page'] ?? $campaign['white_page'],
                        in_array((string)($input['reject_mode'] ?? $campaign['reject_mode']), ['white', 'error'], true)
                            ? (string)($input['reject_mode'] ?? $campaign['reject_mode']) : 'white',
                        (int)($input['reject_code'] ?? $campaign['reject_code']),
                        $redirectType,
                        max(0, min(30, (int)($input['redirect_delay'] ?? $campaign['redirect_delay']))),
                    ],
                    array_map(fn($col) => $r[$col], RULE_COLUMNS),
                    [$id, $userId]
                );
                $db->prepare("UPDATE campaigns SET " . implode(', ', $setCols) . " WHERE id = ? AND user_id = ?")
                   ->execute($setValues);
                $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ?");
                $stmt->execute([$id]);
                apiSuccess(['campaign' => $stmt->fetch()]);
            }
        }

        if ($method === 'POST' && isset($segments[2]) && $segments[2] === 'clone' && $id !== null) {
            $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
            $campaign = $stmt->fetch();
            if (!$campaign) apiError('Campaign not found.', 404);

            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                $body = json_decode(file_get_contents('php://input'), true);
                $name = is_array($body) ? trim((string)($body['name'] ?? '')) : '';
            }
            if ($name === '') $name = $campaign['name'] . ' (copy)';

            $cols = array_merge(
                ['name', 'is_active', 'offer_url', 'white_page', 'reject_mode', 'reject_code', 'redirect_type', 'redirect_delay'],
                RULE_COLUMNS
            );
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $params = [];
            foreach ($cols as $col) {
                $params[] = $col === 'name' ? $name : $campaign[$col];
            }
            $db->prepare("INSERT INTO campaigns (user_id, " . implode(',', $cols) . ") VALUES (?, {$placeholders})")
               ->execute(array_merge([$userId], $params));
            $newId = (int)$db->lastInsertId();
            $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ?");
            $stmt->execute([$newId]);
            apiSuccess(['campaign' => $stmt->fetch()], 201);
        }
        break;

    // ============================ DOMAINS ======================================
    case 'domains':
        if ($method === 'GET' && $id === null) {
            $stmt = $db->prepare("SELECT * FROM domains WHERE user_id = ? ORDER BY is_system DESC, created_at ASC");
            $stmt->execute([$userId]);
            apiSuccess(['domains' => $stmt->fetchAll()]);
        }

        if ($method === 'POST' && $id === null) {
            $input = readJsonBody();
            $domain = strtolower(trim((string)($input['domain'] ?? '')));
            if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
                apiError('Invalid domain. Use a hostname like s.example.com.', 400);
            }
            $check = $db->prepare("SELECT id FROM domains WHERE domain = ?");
            $check->execute([$domain]);
            if ($check->fetch()) apiError('Domain already exists.', 409);
            $db->prepare("INSERT INTO domains (user_id, domain, is_system, is_active) VALUES (?, ?, 0, 1)")
               ->execute([$userId, $domain]);
            $newId = (int)$db->lastInsertId();
            $stmt = $db->prepare("SELECT * FROM domains WHERE id = ?");
            $stmt->execute([$newId]);
            apiSuccess(['domain' => $stmt->fetch()], 201);
        }

        if (($method === 'PUT' || $method === 'DELETE') && $id !== null) {
            $stmt = $db->prepare("SELECT * FROM domains WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
            $domainRow = $stmt->fetch();
            if (!$domainRow) apiError('Domain not found.', 404);

            if ($method === 'DELETE') {
                if (!empty($domainRow['is_system'])) apiError('System domain cannot be deleted.', 400);
                $db->prepare("UPDATE links SET domain_id = NULL WHERE domain_id = ? AND user_id = ?")
                   ->execute([$id, $userId]);
                $db->prepare("DELETE FROM domains WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
                apiSuccess(['message' => 'Domain deleted']);
            }
            if ($method === 'PUT') {
                $input = readJsonBody();
                if (array_key_exists('is_active', $input)) {
                    $db->prepare("UPDATE domains SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?")
                       ->execute([(int)$input['is_active'] ? 1 : 0, $id, $userId]);
                }
                $stmt = $db->prepare("SELECT * FROM domains WHERE id = ?");
                $stmt->execute([$id]);
                apiSuccess(['domain' => $stmt->fetch()]);
            }
        }
        break;

    // ============================ VERIFY =======================================
    case 'verify':
        if ($method === 'POST' && $id === null) {
            if (defined('RATE_LIMIT_ENABLED') && RATE_LIMIT_ENABLED) {
                if (!rate_limit('verify:' . $apiKey, 1000, 60)) {
                    apiError('Rate limit exceeded.', 429);
                }
            }

            $input = readJsonBody();
            $campaignId = (int)($input['campaign_id'] ?? 0);
            if ($campaignId <= 0) apiError('campaign_id is required.', 400);

            $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ? AND user_id = ?");
            $stmt->execute([$campaignId, $userId]);
            $campaign = $stmt->fetch();
            if (!$campaign) apiError('Campaign not found.', 404);
            if (empty($campaign['is_active'])) apiError('Campaign is inactive.', 403);

            $ip = trim((string)($input['ip'] ?? ''));
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            }
            $context = [
                'accept'        => (string)($input['accept'] ?? ''),
                'language'      => (string)($input['language'] ?? ''),
                'referer'       => (string)($input['referer'] ?? ''),
                'params'        => is_array($input['params'] ?? null) ? $input['params'] : [],
            ];
            $fingerprint = is_array($input['fingerprint'] ?? null) ? $input['fingerprint'] : [];
            $tokenPresent = !empty($input['token_present']);

            // Fingerprint-dependent rules: ask the client for a JS round-trip
            $needsFp = !empty($campaign['require_screen_info'])
                || !empty($campaign['allowed_resolutions']) || !empty($campaign['blocked_resolutions'])
                || !empty($campaign['single_visit_only']);
            if ($needsFp && empty($fingerprint) && !$tokenPresent) {
                apiSuccess(['allowed' => false, 'reasons' => [], 'fingerprint_required' => true]);
            }

            $detector = new BotDetector($ip, (string)($input['user_agent'] ?? ''), $context);
            $eval = $detector->evaluate($campaign, $fingerprint, $tokenPresent);
            $result = $detector->getResult();

            // Delay-start filter (first N unique IPs blocked)
            if ($eval['allowed'] && !empty($campaign['delay_start'])) {
                $delayReason = delay_start_check(
                    $db,
                    $campaignId,
                    true,
                    $ip,
                    $campaign
                );
                if ($delayReason !== '') {
                    $eval = ['allowed' => false, 'reasons' => [$delayReason]];
                }
            }

            // Log
            if (defined('LOG_ENABLED') && LOG_ENABLED) {
                $utm = is_array($input['params'] ?? null) && isset($input['params']['utm_source'])
                    ? (string)$input['params']['utm_source'] : '';
                $source = derive_source(
                    (string)($input['referer'] ?? ''),
                    (string)($result['client_type'] ?? ''),
                    $utm
                );
                $db->prepare("
                    INSERT INTO hit_log (link_id, campaign_id, host, ip, user_agent, referer, language, country,
                                         device_type, os_name, os_version, client_type, source,
                                         is_bot, is_vpn, is_datacenter, shown_page, reject_reason)
                    VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $campaignId,
                    (string)($input['host'] ?? ''),
                    $ip,
                    (string)($input['user_agent'] ?? ''),
                    (string)($input['referer'] ?? ''),
                    (string)($input['language'] ?? ''),
                    $result['country'] ?? '',
                    $result['device_type'] ?? '',
                    $result['os_name'] ?? '',
                    $result['os_version'] ?? '',
                    $result['client_type'] ?? '',
                    $source,
                    !empty($result['is_bot']) ? 1 : 0,
                    !empty($result['is_vpn']) ? 1 : 0,
                    !empty($result['is_datacenter']) ? 1 : 0,
                    $eval['allowed'] ? 'offer' : 'white',
                    $eval['allowed'] ? null : implode(',', $eval['reasons']),
                ]);
                $col = $eval['allowed'] ? 'offer_shows' : 'white_shows';
                $db->prepare("UPDATE campaigns SET total_hits = total_hits + 1, {$col} = {$col} + 1 WHERE id = ?")
                   ->execute([$campaignId]);
            }

            apiSuccess([
                'allowed'        => $eval['allowed'],
                'reasons'        => $eval['reasons'],
                'pass_target'    => $eval['allowed']
                    ? resolve_offer_target($campaign, (string)($result['country'] ?? ''), (int)$campaign['offer_shows'])
                    : '',
                'offer_method'   => (string)($campaign['offer_method'] ?? 'redirect'),
                'forward_utms'   => !empty($campaign['forward_utms']) ? 1 : 0,
                'reject_mode'    => (string)($campaign['reject_mode'] ?? 'white'),
                'reject_code'    => (int)($campaign['reject_code'] ?? 403),
                'reject_target'  => (string)($campaign['white_page'] ?? ''),
                'redirect_type'  => (string)($campaign['redirect_type'] ?? '302'),
                'redirect_delay' => (int)($campaign['redirect_delay'] ?? 0),
            ]);
        }
        break;

    // ============================ STATS ========================================
    case 'stats':
        if ($method === 'GET' && $id !== null) {
            $stmt = $db->prepare("SELECT * FROM links WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
            $link = $stmt->fetch();
            if (!$link) apiError('Link not found.', 404);

            $stmt = $db->prepare("
                SELECT DATE(created_at) AS date, shown_page, COUNT(*) AS count
                FROM hit_log WHERE link_id = ? AND created_at >= datetime('now', '-30 days')
                GROUP BY DATE(created_at), shown_page
                ORDER BY date DESC");
            $stmt->execute([$id]);
            $daily = $stmt->fetchAll();

            $stmt = $db->prepare("
                SELECT device_type, COUNT(*) AS count FROM hit_log WHERE link_id = ? GROUP BY device_type");
            $stmt->execute([$id]);
            $devices = $stmt->fetchAll();

            $stmt = $db->prepare("
                SELECT reject_reason, COUNT(*) AS count FROM hit_log
                WHERE link_id = ? AND reject_reason IS NOT NULL AND reject_reason != ''
                GROUP BY reject_reason ORDER BY count DESC");
            $stmt->execute([$id]);
            $reasons = $stmt->fetchAll();

            $stmt = $db->prepare("
                SELECT source, shown_page, COUNT(*) AS count FROM hit_log
                WHERE link_id = ?
                GROUP BY source, shown_page ORDER BY count DESC LIMIT 20");
            $stmt->execute([$id]);
            $sources = $stmt->fetchAll();

            apiSuccess([
                'link'    => $link,
                'daily'   => $daily,
                'devices' => $devices,
                'reasons' => $reasons,
                'sources' => $sources,
            ]);
        }
        break;

    default:
        apiError('Unknown resource.', 404);
}

apiError('Method not allowed.', 405);
