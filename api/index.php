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

function apiMethodNotAllowed(): void
{
    apiError('Method not allowed.', 405);
}

function apiNotFound(): void
{
    apiError('Not found.', 404);
}

function getSegmentId(array $segments, int $index): ?int
{
    if (isset($segments[$index]) && ctype_digit($segments[$index])) {
        return (int) $segments[$index];
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

// ---- Authenticate -----------------------------------------------------------
// Apache's mod_rewrite internal redirect (root .htaccess -> /api/index.php)
// drops the Authorization header into REDIRECT_HTTP_AUTHORIZATION; some
// proxy stacks expose it only via getallheaders(). Accept all three sources.
$authHeader = '';
foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $headerKey) {
    $candidate = $_SERVER[$headerKey] ?? '';
    if (is_string($candidate) && trim($candidate) !== '') {
        $authHeader = $candidate;
        break;
    }
}
if ($authHeader === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp((string) $name, 'Authorization') === 0 && trim((string) $value) !== '') {
            $authHeader = (string) $value;
            break;
        }
    }
}
$apiKey = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $apiKey = trim($m[1]);
}
if ($apiKey === '') {
    apiError('API key required. Use the Authorization: Bearer header.', 401);
}

$db = getDB();
$resource = $segments[0] ?? '';
$id = getSegmentId($segments, 1);
$isVerifyRequest = $resource === 'verify';
$clientCredential = authenticate_client_credential($db, $apiKey);

if ($isVerifyRequest) {
    if ($clientCredential === false) {
        apiError('Invalid client credential.', 401);
    }
    $userId = (int) $clientCredential['user_id'];
} else {
    if ($clientCredential !== false) {
        apiError('Client credentials can only call /api/verify.', 403);
    }

    $stmt = $db->prepare("SELECT id FROM users WHERE api_key = ?");
    $stmt->execute([$apiKey]);
    $user = $stmt->fetch();

    if (!$user) {
        apiError('Invalid API key.', 401);
    }
    $userId = (int) $user['id'];
}

// ---- Routing --------------------------------------------------------------------
switch ($resource) {
    case 'links':
        if (count($segments) === 1) {
            if ($method === 'GET') {
                $stmt = $db->prepare("
                    SELECT l.*, c.name AS campaign_name, d.domain AS domain_name
                    FROM links l
                    LEFT JOIN campaigns c ON c.id = l.campaign_id
                    LEFT JOIN domains d ON d.id = l.domain_id
                    WHERE l.user_id = ? AND l.is_deleted = 0 ORDER BY l.created_at DESC");
                $stmt->execute([$userId]);
                apiSuccess(['links' => $stmt->fetchAll()]);
            }
            if ($method !== 'POST') {
                apiMethodNotAllowed();
            }

            $input = readJsonBody();
            try {
                $parsed = parse_link_input($input, [], ['source' => 'api']);
            } catch (BadRequestException $e) {
                apiError($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
            }

            if ($parsed['campaign_id'] !== null && owned_row($db, 'campaigns', $parsed['campaign_id'], $userId) === null) {
                apiError('Campaign not found.', 404);
            }
            if ($parsed['domain_id'] !== null && owned_row($db, 'domains', $parsed['domain_id'], $userId) === null) {
                apiError('Domain not found.', 404);
            }

            $check = $db->prepare("SELECT id FROM links WHERE slug = ?");
            $check->execute([$parsed['slug']]);
            if ($check->fetch()) {
                apiError('Slug already exists.', 409);
            }

            $columns = array_merge(['user_id', 'slug'], LINK_MUTABLE_COLUMNS);
            $values = array_merge([$userId, $parsed['slug']], array_map(static fn(string $column) => $parsed[$column], LINK_MUTABLE_COLUMNS));
            $stmt = $db->prepare(
                "INSERT INTO links (" . implode(',', $columns) . ")
                 VALUES (" . implode(',', array_fill(0, count($columns), '?')) . ")"
            );
            $stmt->execute($values);

            $linkId = (int) $db->lastInsertId();
            $stmt = $db->prepare("SELECT * FROM links WHERE id = ?");
            $stmt->execute([$linkId]);
            apiSuccess(['link' => $stmt->fetch()], 201);
        }

        if (count($segments) === 2 && $id !== null) {
            $stmt = $db->prepare("SELECT * FROM links WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
            $link = $stmt->fetch();
            if (!$link) {
                apiError('Link not found.', 404);
            }

            if ($method === 'GET') {
                apiSuccess(['link' => $link]);
            }
            if ($method === 'DELETE') {
                $db->prepare("UPDATE links SET is_deleted = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
                apiSuccess(['message' => 'Link deleted']);
            }
            if ($method !== 'PUT') {
                apiMethodNotAllowed();
            }

            $input = readJsonBody();
            try {
                $parsed = parse_link_input($input, $link, ['source' => 'api', 'partial' => true]);
            } catch (BadRequestException $e) {
                apiError($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
            }

            if ($parsed['campaign_id'] !== null && owned_row($db, 'campaigns', $parsed['campaign_id'], $userId) === null) {
                apiError('Campaign not found.', 404);
            }
            if ($parsed['domain_id'] !== null && owned_row($db, 'domains', $parsed['domain_id'], $userId) === null) {
                apiError('Domain not found.', 404);
            }

            $set = implode(', ', array_map(static fn(string $column): string => "{$column} = ?", LINK_MUTABLE_COLUMNS));
            $values = array_map(static fn(string $column) => $parsed[$column], LINK_MUTABLE_COLUMNS);
            $db->prepare("UPDATE links SET {$set}, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?")
               ->execute(array_merge($values, [$id, $userId]));

            $stmt = $db->prepare("SELECT * FROM links WHERE id = ?");
            $stmt->execute([$id]);
            apiSuccess(['link' => $stmt->fetch()]);
        }

        if (count($segments) === 3 && $id !== null && $segments[2] === 'restore') {
            $stmt = $db->prepare("SELECT * FROM links WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
            if (!$stmt->fetch()) {
                apiError('Link not found.', 404);
            }
            $db->prepare("UPDATE links SET is_deleted = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?")
               ->execute([$id, $userId]);
            $stmt = $db->prepare("SELECT * FROM links WHERE id = ?");
            $stmt->execute([$id]);
            apiSuccess(['link' => $stmt->fetch()]);
        }

        apiNotFound();

    case 'campaigns':
        if (count($segments) === 1) {
            if ($method === 'GET') {
                $stmt = $db->prepare("SELECT * FROM campaigns WHERE user_id = ? AND is_deleted = 0 ORDER BY created_at DESC");
                $stmt->execute([$userId]);
                apiSuccess(['campaigns' => $stmt->fetchAll()]);
            }
            if ($method !== 'POST') {
                apiMethodNotAllowed();
            }

            $input = readJsonBody();
            try {
                $parsed = parse_campaign_input($input, [], ['source' => 'api']);
            } catch (BadRequestException $e) {
                apiError($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
            }

            $columns = array_merge(['user_id'], CAMPAIGN_MUTABLE_COLUMNS);
            $values = array_merge([$userId], array_map(static fn(string $column) => $parsed[$column], CAMPAIGN_MUTABLE_COLUMNS));
            $stmt = $db->prepare(
                "INSERT INTO campaigns (" . implode(',', $columns) . ")
                 VALUES (" . implode(',', array_fill(0, count($columns), '?')) . ")"
            );
            $stmt->execute($values);

            $campaignId = (int) $db->lastInsertId();
            $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ?");
            $stmt->execute([$campaignId]);
            apiSuccess(['campaign' => $stmt->fetch()], 201);
        }

        if (count($segments) === 2 && $id !== null) {
            $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
            $campaign = $stmt->fetch();
            if (!$campaign) {
                apiError('Campaign not found.', 404);
            }

            if ($method === 'GET') {
                apiSuccess(['campaign' => $campaign]);
            }
            if ($method === 'DELETE') {
                $error = delete_campaign_safely($db, $userId, $id);
                if ($error !== null) {
                    apiError($error, 409);
                }
                apiSuccess(['message' => 'Campaign deleted']);
            }
            if ($method !== 'PUT') {
                apiMethodNotAllowed();
            }

            $input = readJsonBody();
            try {
                $parsed = parse_campaign_input($input, $campaign, ['source' => 'api', 'partial' => true]);
            } catch (BadRequestException $e) {
                apiError($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
            }

            $set = implode(', ', array_map(static fn(string $column): string => "{$column} = ?", CAMPAIGN_MUTABLE_COLUMNS));
            $values = array_map(static fn(string $column) => $parsed[$column], CAMPAIGN_MUTABLE_COLUMNS);
            $db->prepare("UPDATE campaigns SET {$set}, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?")
               ->execute(array_merge($values, [$id, $userId]));

            $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ?");
            $stmt->execute([$id]);
            apiSuccess(['campaign' => $stmt->fetch()]);
        }

        if (count($segments) === 3 && $id !== null && $segments[2] === 'clone') {
            if ($method !== 'POST') {
                apiMethodNotAllowed();
            }

            $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
            $campaign = $stmt->fetch();
            if (!$campaign) {
                apiError('Campaign not found.', 404);
            }

            $name = trim(app_array_get_scalar($_POST, 'name', 255, 'name') ?? '');
            if ($name === '') {
                $body = json_decode(file_get_contents('php://input'), true);
                $name = is_array($body) ? trim(app_array_get_scalar($body, 'name', 255, 'name') ?? '') : '';
            }
            if ($name === '') {
                $name = $campaign['name'] . ' (copy)';
            }

            $params = [];
            foreach (CAMPAIGN_MUTABLE_COLUMNS as $column) {
                $params[] = $column === 'name' ? $name : $campaign[$column];
            }
            $placeholders = implode(',', array_fill(0, count(CAMPAIGN_MUTABLE_COLUMNS), '?'));
            $db->prepare("INSERT INTO campaigns (user_id, " . implode(',', CAMPAIGN_MUTABLE_COLUMNS) . ") VALUES (?, {$placeholders})")
               ->execute(array_merge([$userId], $params));

            $newId = (int) $db->lastInsertId();
            $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ?");
            $stmt->execute([$newId]);
            apiSuccess(['campaign' => $stmt->fetch()], 201);
        }

        if (count($segments) === 3 && $id !== null && $segments[2] === 'restore') {
            $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
            if (!$stmt->fetch()) {
                apiError('Campaign not found.', 404);
            }
            $db->prepare("UPDATE campaigns SET is_deleted = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?")
               ->execute([$id, $userId]);
            $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ?");
            $stmt->execute([$id]);
            apiSuccess(['campaign' => $stmt->fetch()]);
        }

        apiNotFound();

    case 'domains':
        if (count($segments) === 1) {
            if ($method === 'GET') {
                $stmt = $db->prepare("SELECT * FROM domains WHERE user_id = ? AND is_deleted = 0 ORDER BY is_system DESC, created_at ASC");
                $stmt->execute([$userId]);
                apiSuccess(['domains' => $stmt->fetchAll()]);
            }
            if ($method !== 'POST') {
                apiMethodNotAllowed();
            }

            $input = readJsonBody();
            $domain = strtolower(trim(app_array_get_scalar($input, 'domain', 255, 'domain') ?? ''));
            if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
                apiError('Invalid domain. Use a hostname like s.example.com.', 400);
            }

            $check = $db->prepare("SELECT id FROM domains WHERE domain = ?");
            $check->execute([$domain]);
            if ($check->fetch()) {
                apiError('Domain already exists.', 409);
            }

            $db->prepare("INSERT INTO domains (user_id, domain, is_system, is_active) VALUES (?, ?, 0, 1)")
               ->execute([$userId, $domain]);
            $newId = (int) $db->lastInsertId();
            $stmt = $db->prepare("SELECT * FROM domains WHERE id = ?");
            $stmt->execute([$newId]);
            apiSuccess(['domain' => $stmt->fetch()], 201);
        }

        if (count($segments) === 2 && $id !== null) {
            $stmt = $db->prepare("SELECT * FROM domains WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
            $domainRow = $stmt->fetch();
            if (!$domainRow) {
                apiError('Domain not found.', 404);
            }

            if ($method === 'DELETE') {
                if (!empty($domainRow['is_system'])) {
                    apiError('System domain cannot be deleted.', 400);
                }
                $error = delete_domain_safely($db, $userId, $id);
                if ($error !== null) {
                    apiError($error, 409);
                }
                apiSuccess(['message' => 'Domain deleted']);
            }
            if ($method !== 'PUT') {
                apiMethodNotAllowed();
            }

            $input = readJsonBody();
            if (array_key_exists('is_active', $input)) {
                $db->prepare("UPDATE domains SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?")
                   ->execute([(int) $input['is_active'] ? 1 : 0, $id, $userId]);
            }
            $stmt = $db->prepare("SELECT * FROM domains WHERE id = ?");
            $stmt->execute([$id]);
            apiSuccess(['domain' => $stmt->fetch()]);
        }

        if (count($segments) === 3 && $id !== null && $segments[2] === 'restore') {
            $stmt = $db->prepare("SELECT * FROM domains WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
            if (!$stmt->fetch()) {
                apiError('Domain not found.', 404);
            }
            $db->prepare("UPDATE domains SET is_deleted = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?")
               ->execute([$id, $userId]);
            $stmt = $db->prepare("SELECT * FROM domains WHERE id = ?");
            $stmt->execute([$id]);
            apiSuccess(['domain' => $stmt->fetch()]);
        }

        if (count($segments) === 3 && $id !== null && $segments[2] === 'status') {
            $stmt = $db->prepare("SELECT * FROM domains WHERE id = ? AND user_id = ? AND is_system = 0");
            $stmt->execute([$id, $userId]);
            $domainRow = $stmt->fetch();
            if (!$domainRow) {
                apiError('Custom domain not found.', 404);
            }
            apiSuccess(refresh_domain_status($db, $domainRow));
        }

        apiNotFound();

    case 'verify':
        if (count($segments) !== 1) {
            apiNotFound();
        }
        if ($method !== 'POST') {
            apiMethodNotAllowed();
        }
        if (defined('RATE_LIMIT_ENABLED') && RATE_LIMIT_ENABLED) {
            if (!rate_limit('verify:' . ($clientCredential['credential_hash'] ?? client_credential_hash($apiKey)), 1000, 60)) {
                apiError('Rate limit exceeded.', 429);
            }
        }

        $input = readJsonBody();
        $campaignId = (int)($input['campaign_id'] ?? 0);
        if ($campaignId <= 0) {
            apiError('campaign_id is required.', 400);
        }

        if (!credential_allows_campaign($clientCredential, $campaignId)) {
            apiError('Credential is not allowed to verify this campaign.', 403);
        }

        $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ? AND user_id = ?");
        $stmt->execute([$campaignId, $userId]);
        $campaign = $stmt->fetch();
        if (!$campaign) {
            apiError('Campaign not found.', 404);
        }
        if (empty($campaign['is_active'])) {
            apiError('Campaign is inactive.', 403);
        }

        $ip = trim(app_array_get_scalar($input, 'ip', 64, 'ip') ?? '');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = app_client_ip();
        }
        $params = is_array($input['params'] ?? null) ? app_validate_scalar_map($input['params'], 4096, 'query parameter') : [];
        $userAgent = app_array_get_scalar($input, 'user_agent', 2048, 'user_agent') ?? '';
        $context = [
            'accept' => app_array_get_scalar($input, 'accept', 1024, 'accept') ?? '',
            'language' => app_array_get_scalar($input, 'language', 255, 'language') ?? '',
            'referer' => app_array_get_scalar($input, 'referer', 2048, 'referer') ?? '',
            'params' => $params,
            // Cloudflare edge headers forwarded by a client-deployment landing
            // server (validated by schema in the detector)
            'cf_ipcountry' => app_array_get_scalar($input, 'cf_ipcountry', 8, 'cf_ipcountry') ?? '',
            'cf_ipasn' => app_array_get_scalar($input, 'cf_ipasn', 32, 'cf_ipasn') ?? '',
        ];
        $fingerprint = is_array($input['fingerprint'] ?? null) ? $input['fingerprint'] : [];
        $visitorToken = trim(app_array_get_scalar($input, 'visitor_token', 512, 'visitor_token') ?? '');
        $visitorCookie = trim(app_array_get_scalar($input, 'visitor_cookie', 2048, 'visitor_cookie') ?? '');
        if (array_key_exists('visitor_https', $input) && !is_bool($input['visitor_https'])) {
            apiError('visitor_https must be a boolean.', 400);
        }
        $visitorHttps = ($input['visitor_https'] ?? false) === true;
        if ($visitorToken !== '' && (!$visitorHttps || !app_is_https())) {
            apiError('HTTPS is required for visitor verification.', 403);
        }
        $visitorScope = visitor_scope_key('campaign', $campaignId);
        $verifiedVisitor = $visitorToken !== '' ? verify_visitor_token($visitorCookie, $visitorScope) : false;
        $tokenPresent = is_string($verifiedVisitor) && hash_equals($verifiedVisitor, $visitorToken);

        $needsFp = !empty($campaign['require_screen_info'])
            || !empty($campaign['allowed_resolutions']) || !empty($campaign['blocked_resolutions'])
            || !empty($campaign['single_visit_only']);
        if ($needsFp && empty($fingerprint) && !$tokenPresent) {
            apiSuccess(['allowed' => false, 'reasons' => [], 'fingerprint_required' => true]);
        }

        $detector = new BotDetector($ip, $userAgent, $context);
        $eval = $detector->evaluate($campaign, $fingerprint, $tokenPresent);
        $result = $detector->getResult();

        // Warm-up bypass: first N clicks skip every filter
        $warmupBypass = (int)($campaign['clicks_before_filtering'] ?? 0) > 0
            && (int)($campaign['total_hits'] ?? 0) < (int)$campaign['clicks_before_filtering'];

        // Per-IP daily click cap
        $ipLimit = (int)($campaign['ip_clicks_per_day'] ?? 0);
        if (!$warmupBypass && !ip_clicks_per_day_check($db, true, $campaignId, $ip, $ipLimit)) {
            $eval = ['allowed' => false, 'reasons' => ['ip_clicks_per_day']];
        }

        if ($eval['allowed'] && !empty($campaign['delay_start'])) {
            $delayReason = delay_start_check($db, $campaignId, true, $ip, $campaign);
            if ($delayReason !== '') {
                $eval = ['allowed' => false, 'reasons' => [$delayReason]];
            }
        }

        // Attached reusable filter list
        if ($eval['allowed'] && !empty($campaign['filter_id'])) {
            $listReason = filter_list_check(
                $db,
                (int)$campaign['filter_id'],
                (int)$campaign['user_id'],
                $ip,
                $userAgent,
                $context['referer'],
                (string)($result['isp'] ?? '')
            );
            if ($listReason !== '') {
                $eval = ['allowed' => false, 'reasons' => [$listReason]];
            }
        }

        $delivery = build_delivery_config($campaign, (string) ($result['country'] ?? ''), (int) $campaign['offer_shows']);
        $passTarget = $eval['allowed'] ? (string) $delivery['offer_url'] : '';
        if ($passTarget !== '' && !is_valid_offer_url($passTarget)) {
            $eval = ['allowed' => false, 'reasons' => ['invalid_offer_url']];
            $passTarget = '';
        }

        if (defined('LOG_ENABLED') && LOG_ENABLED) {
            $utm = $params['utm_source'] ?? '';
            $source = derive_source(
                $context['referer'],
                (string)($result['client_type'] ?? ''),
                $utm
            );
            record_hit($db, [
                'link_id' => null,
                'campaign_id' => $campaignId,
                'host' => app_array_get_scalar($input, 'host', 255, 'host') ?? '',
                'ip' => $ip,
                'user_agent' => $userAgent,
                'referer' => $context['referer'],
                'language' => $context['language'],
                'country' => $result['country'] ?? '',
                'device_type' => $result['device_type'] ?? '',
                'os_name' => $result['os_name'] ?? '',
                'os_version' => $result['os_version'] ?? '',
                'client_type' => $result['client_type'] ?? '',
                'source' => $source,
                'browser' => $result['browser'] ?? '',
                'is_bot' => !empty($result['is_bot']),
                'is_vpn' => !empty($result['is_vpn']),
                'is_datacenter' => !empty($result['is_datacenter']),
                'reject_reason' => $eval['allowed'] ? null : implode(',', $eval['reasons']),
            ], (bool) $eval['allowed']);
        }

        $visitorIssue = visitor_cookie_issue(
            $visitorScope,
            $visitorToken,
            $visitorHttps && app_is_https(),
            (bool) $eval['allowed']
        );
        apiSuccess([
            'allowed' => $eval['allowed'],
            'reasons' => $eval['reasons'],
            'pass_target' => $passTarget,
            'offer_method' => $delivery['offer_method'],
            'forward_utms' => !empty($campaign['forward_utms']) ? 1 : 0,
            'reject_mode' => normalize_reject_mode((string)($campaign['reject_mode'] ?? 'white')),
            'reject_code' => (int)($campaign['reject_code'] ?? 403),
            'reject_target' => (string)($campaign['white_page'] ?? ''),
            'redirect_type' => $delivery['redirect_type'],
            'redirect_delay' => $delivery['redirect_delay'],
            'visitor_cookie_name' => is_array($visitorIssue) ? $visitorIssue['name'] : '',
            'visitor_cookie' => is_array($visitorIssue) ? $visitorIssue['value'] : '',
        ]);

    // ============================ FILTER LISTS ================================
    case 'filter-lists':
        if ($method === 'GET' && $id === null) {
            $stmt = $db->prepare("SELECT * FROM filter_lists WHERE user_id = ? ORDER BY is_deleted ASC, created_at DESC");
            $stmt->execute([$userId]);
            apiSuccess(['lists' => $stmt->fetchAll()]);
        }

        if ($method === 'POST' && $id === null) {
            $input = readJsonBody();
            $name = trim((string)($input['name'] ?? ''));
            $listType = (string)($input['list_type'] ?? 'black');
            if ($name === '' || strlen($name) > 32) {
                apiError('name is required (max 32 chars).', 400);
            }
            if (!in_array($listType, ['white', 'black'], true)) {
                apiError('list_type must be white or black.', 400);
            }
            $db->prepare("INSERT INTO filter_lists (user_id, name, list_type, list_ips, list_agents, list_providers, list_referers)
                          VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([
                $userId, $name, $listType,
                trim((string)($input['list_ips'] ?? '')),
                trim((string)($input['list_agents'] ?? '')),
                trim((string)($input['list_providers'] ?? '')),
                trim((string)($input['list_referers'] ?? '')),
            ]);
            $listId = (int)$db->lastInsertId();
            $stmt = $db->prepare("SELECT * FROM filter_lists WHERE id = ?");
            $stmt->execute([$listId]);
            apiSuccess(['list' => $stmt->fetch()], 201);
        }

        if ($id !== null && ($method === 'GET' || $method === 'PUT' || $method === 'DELETE')) {
            $stmt = $db->prepare("SELECT * FROM filter_lists WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
            $list = $stmt->fetch();
            if (!$list) apiError('Filter list not found.', 404);

            if ($method === 'GET') {
                apiSuccess(['list' => $list]);
            }
            if ($method === 'DELETE') {
                $db->prepare("UPDATE filter_lists SET is_deleted = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?")
                   ->execute([$id, $userId]);
                apiSuccess(['message' => 'Filter list deleted']);
            }
            if ($method === 'PUT') {
                $input = readJsonBody();
                $fields = [];
                $values = [];
                foreach (['name', 'list_ips', 'list_agents', 'list_providers', 'list_referers'] as $col) {
                    if (array_key_exists($col, $input)) {
                        $fields[] = "{$col} = ?";
                        $values[] = trim((string)$input[$col]);
                    }
                }
                if (array_key_exists('list_type', $input)) {
                    if (!in_array((string)$input['list_type'], ['white', 'black'], true)) {
                        apiError('list_type must be white or black.', 400);
                    }
                    $fields[] = 'list_type = ?';
                    $values[] = (string)$input['list_type'];
                }
                if ($fields === []) {
                    apiError('No fields to update.', 400);
                }
                $fields[] = 'updated_at = CURRENT_TIMESTAMP';
                $db->prepare("UPDATE filter_lists SET " . implode(', ', $fields) . " WHERE id = ? AND user_id = ?")
                   ->execute(array_merge($values, [$id, $userId]));
                $stmt = $db->prepare("SELECT * FROM filter_lists WHERE id = ?");
                $stmt->execute([$id]);
                apiSuccess(['list' => $stmt->fetch()]);
            }
        }

        if ($method === 'POST' && isset($segments[2]) && $segments[2] === 'restore' && $id !== null) {
            $db->prepare("UPDATE filter_lists SET is_deleted = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?")
               ->execute([$id, $userId]);
            $stmt = $db->prepare("SELECT * FROM filter_lists WHERE id = ?");
            $stmt->execute([$id]);
            apiSuccess(['list' => $stmt->fetch()]);
        }
        break;

    // ============================ STATISTICS ==================================
    case 'statistics':
        if ($method === 'GET' && $id === null) {
            $groupBy = (string)($_GET['group_by'] ?? 'date');
            $allowedGroups = ['date', 'hour', 'day_week', 'flow', 'country', 'device', 'os', 'browser', 'source', 'client'];
            if (!in_array($groupBy, $allowedGroups, true)) {
                apiError('group_by must be one of: ' . implode(', ', $allowedGroups) . '.', 400);
            }
            $days = (int)($_GET['days'] ?? 7);
            $days = in_array($days, [1, 7, 30, 0], true) ? $days : 7;
            $flowId = (int)($_GET['flow_id'] ?? 0);

            $where = ['l.user_id = ?'];
            $params = [$userId];
            if ($flowId > 0) {
                $where[] = 'h.link_id = ?';
                $params[] = $flowId;
            }
            if ($days > 0) {
                $where[] = "h.created_at >= datetime('now', ?)";
                $params[] = "-{$days} days";
            }

            $exprMap = [
                'date' => "DATE(h.created_at)",
                'hour' => "strftime('%H', h.created_at)",
                'day_week' => "strftime('%w', h.created_at)",
                'flow' => 'COALESCE(l.slug, c.name, \'—\')',
                'country' => "COALESCE(NULLIF(h.country, ''), '—')",
                'device' => "COALESCE(NULLIF(h.device_type, ''), '—')",
                'os' => "COALESCE(NULLIF(h.os_name, ''), '—')",
                'browser' => "COALESCE(NULLIF(h.browser, ''), '—')",
                'source' => "COALESCE(NULLIF(h.source, ''), 'direct')",
                'client' => "COALESCE(NULLIF(h.client_type, ''), 'browser')",
            ];
            $expr = $exprMap[$groupBy];

            $stmt = $db->prepare("
                SELECT {$expr} AS value,
                       COUNT(*) AS hits,
                       COUNT(DISTINCT h.ip) AS hosts,
                       SUM(CASE WHEN h.shown_page = 'offer' THEN 1 ELSE 0 END) AS offers,
                       SUM(CASE WHEN h.shown_page = 'white' THEN 1 ELSE 0 END) AS safe
                FROM hit_log h
                LEFT JOIN links l ON l.id = h.link_id
                LEFT JOIN campaigns c ON c.id = h.campaign_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY value ORDER BY hits DESC");
            $stmt->execute($params);
            $rows = array_map(static function (array $row): array {
                $row['ctr'] = (int)$row['hits'] > 0
                    ? round((int)$row['offers'] / (int)$row['hits'] * 100, 1) : 0;
                return $row;
            }, $stmt->fetchAll());

            apiSuccess(['group_by' => $groupBy, 'days' => $days, 'rows' => $rows]);
        }
        break;

    // ============================ CLICKS ======================================
    case 'clicks':
        if ($method === 'GET' && $id === null) {
            $days = (int)($_GET['days'] ?? 7);
            $days = in_array($days, [1, 7, 30, 0], true) ? $days : 7;
            $verdict = (string)($_GET['verdict'] ?? '');
            $verdict = in_array($verdict, ['offer', 'white'], true) ? $verdict : '';
            $source = trim((string)($_GET['source'] ?? ''));
            $reason = trim((string)($_GET['reason'] ?? ''));
            $flowId = (int)($_GET['flow_id'] ?? 0);
            $perPage = min(200, max(10, (int)($_GET['per_page'] ?? 50)));
            $page = max(1, (int)($_GET['page'] ?? 1));

            $where = ['l.user_id = ?'];
            $params = [$userId];
            if ($flowId > 0) {
                $where[] = 'h.link_id = ?';
                $params[] = $flowId;
            }
            if ($days > 0) {
                $where[] = "h.created_at >= datetime('now', ?)";
                $params[] = "-{$days} days";
            }
            if ($verdict !== '') {
                $where[] = 'h.shown_page = ?';
                $params[] = $verdict;
            }
            if ($source !== '') {
                $where[] = 'h.source = ?';
                $params[] = $source;
            }
            if ($reason !== '') {
                $where[] = 'h.reject_reason LIKE ?';
                $params[] = '%' . $reason . '%';
            }

            $stmt = $db->prepare("SELECT COUNT(*) FROM hit_log h LEFT JOIN links l ON l.id = h.link_id LEFT JOIN campaigns c ON c.id = h.campaign_id WHERE " . implode(' AND ', $where));
            $stmt->execute($params);
            $total = (int)$stmt->fetchColumn();

            $stmt = $db->prepare("
                SELECT h.id AS click_id, h.link_id AS flow_id, h.created_at AS time_created,
                       h.country AS country_code, h.ip AS ip_address, h.referer, h.user_agent,
                       h.device_type AS device, h.browser, h.os_name AS os, h.client_type AS client,
                       h.source, h.shown_page AS filter_page, h.reject_reason AS filter_type
                FROM hit_log h
                LEFT JOIN links l ON l.id = h.link_id
                LEFT JOIN campaigns c ON c.id = h.campaign_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY h.created_at DESC, h.id DESC LIMIT ? OFFSET ?");
            $stmt->execute(array_merge($params, [$perPage, ($page - 1) * $perPage]));

            apiSuccess([
                'total' => $total,
                'per_page' => $perPage,
                'page' => $page,
                'data' => $stmt->fetchAll(),
            ]);
        }
        break;

    // ============================ DICTIONARIES ===============================
    case 'dictionaries':
        if ($method !== 'GET') {
            apiMethodNotAllowed();
        }
        $type = (string)($_GET['type'] ?? '');
        if ($type === '') {
            apiSuccess(['dictionaries' => REFERENCE_DICTIONARIES]);
        }
        if (!isset(REFERENCE_DICTIONARIES[$type])) {
            apiError('Unknown dictionary type: ' . $type, 400);
        }
        $entries = [];
        foreach (REFERENCE_DICTIONARIES[$type] as $value => $label) {
            $entries[] = ['value' => (string)$value, 'label' => $label];
        }
        apiSuccess(['type' => $type, 'entries' => $entries]);
        break;

    case 'stats':
        if (count($segments) !== 2 || $id === null) {
            apiNotFound();
        }
        if ($method !== 'GET') {
            apiMethodNotAllowed();
        }

        $stmt = $db->prepare("SELECT * FROM links WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        $link = $stmt->fetch();
        if (!$link) {
            apiError('Link not found.', 404);
        }

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
            'link' => $link,
            'daily' => $daily,
            'devices' => $devices,
            'reasons' => $reasons,
            'sources' => $sources,
        ]);

    default:
        apiError('Unknown resource.', 404);
}
