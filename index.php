<?php
/**
 * Cloaking SaaS - Main Entry Point
 * Handles all incoming traffic and routes to the appropriate page.
 */

require_once __DIR__ . '/includes/bootstrap.php';
boot_app(false); // no session on the public hot path

require_once __DIR__ . '/includes/bot_detector.php';
require_once __DIR__ . '/includes/rules.php';

$db = getDB();

// ---- Resolve slug -----------------------------------------------------------
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$slug = basename($path);
if ($slug === '' || $slug === '/' || $slug === 'index.php') {
    $slug = app_query_scalar('s', 128) ?? '';
}

// ---- Resolve domain -----------------------------------------------------------
$host = app_request_context()['host'] ?? '';
$domainId = null;
$domainRow = null;
if ($host !== '') {
    $stmt = $db->prepare("SELECT * FROM domains WHERE lower(domain) = ? AND is_active = 1");
    $stmt->execute([$host]);
    $domainRow = $stmt->fetch() ?: null;
    if ($domainRow) {
        $domainId = (int)$domainRow['id'];
    }
}

// ---- Lookup link (scoped to domain) ---------------------------------------------
$link = null;
if ($slug !== '') {
    if ($domainId !== null) {
        $stmt = $db->prepare("SELECT * FROM links WHERE slug = ? AND is_active = 1 AND domain_id = ?");
        $stmt->execute([$slug, $domainId]);
    } else {
        $stmt = $db->prepare("SELECT * FROM links WHERE slug = ? AND is_active = 1 AND domain_id IS NULL");
        $stmt->execute([$slug]);
    }
    $link = $stmt->fetch() ?: null;
}

// ---- Unknown slug: 404 with safe page --------------------------------------------
if ($link === null) {
    http_response_code(404);
    echo defined('DEFAULT_WHITE_PAGE') ? DEFAULT_WHITE_PAGE
         : '<html><body><h1>Not Found</h1></body></html>';
    exit;
}

// ---- Effective rules (campaign or link-local) --------------------------------------
$rules = effective_rules($db, $link);
$pendingVisitorIssue = null;
$inactiveCampaignWhitePage = '';
if ($rules === null) {
    // Warm-up support: a paused campaign still serves its own white page
    if (!empty($link['campaign_id'])) {
        $stmt = $db->prepare("SELECT white_page FROM campaigns WHERE id = ? AND user_id = ?");
        $stmt->execute([(int) $link['campaign_id'], (int) $link['user_id']]);
        $inactiveCampaignWhitePage = (string) ($stmt->fetchColumn() ?: '');
    }
    $evalResult = ['allowed' => false, 'reasons' => ['campaign_inactive']];
    $detector = new BotDetector();
    $detector->detect(['datacenter' => false, 'tor' => false]);
    $detectionResult = $detector->getResult();
} else {
    // ---- Fingerprint handling ---------------------------------------------------
    $fingerprintToken = app_query_scalar('_fph');
    $fingerprint = $fingerprintToken !== null ? fingerprint_decode($fingerprintToken) : [];
    $needsFp = !empty($rules['require_screen_info'])
        || !empty($rules['allowed_resolutions']) || !empty($rules['blocked_resolutions'])
        || !empty($rules['single_visit_only']);
    $scopeKind = !empty($link['campaign_id']) ? 'campaign' : 'link';
    $scopeId = !empty($link['campaign_id']) ? (int) $link['campaign_id'] : (int) $link['id'];
    $scopeKey = visitor_scope_key($scopeKind, $scopeId);
    $visitorToken = app_query_scalar('_fv', 512) ?? '';
    $signedVisitor = $visitorToken !== ''
        ? verify_visitor_token((string) ($_COOKIE[visitor_cookie_name($scopeKey)] ?? ''), $scopeKey)
        : false;
    $tokenPresent = is_string($signedVisitor) && hash_equals($signedVisitor, $visitorToken);

    if ($needsFp && $fingerprint === []) {
        // Signed tokens are only trusted once the JS token round-trip proves
        // the browser still holds the same visitor token as the HttpOnly cookie.
        $base = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $query = $_GET;
        unset($query['_fph'], $query['_fv'], $query['_debug']);
        serve_interstitial($base, $query, $scopeKey);
        exit;
    } else {
        if ($visitorToken !== '' && !app_is_https()) {
            app_abort_request(403, 'HTTPS is required for visitor verification.');
        }

        $clientIp = app_client_ip();
        $warmupBypass = false;

        // Clicks-before-filtering: the first N visits bypass every filter
        // (reference: CH filter_clicks_before_filtering)
        if ((int)($rules['clicks_before_filtering'] ?? 0) > 0
            && (int)($rules['total_hits'] ?? 0) < (int)$rules['clicks_before_filtering']) {
            $warmupBypass = true;
        }

        // Per-IP daily click cap (reference: CH filter_ip_clicks_per_day)
        $ipLimit = (int)($rules['ip_clicks_per_day'] ?? 0);
        $ipLimited = !$warmupBypass && !ip_clicks_per_day_check(
            $db,
            $scopeKind === 'campaign',
            $scopeId,
            $clientIp,
            $ipLimit
        );

        if ($warmupBypass) {
            $detector = new BotDetector();
            $detector->detect(['datacenter' => false, 'tor' => false, 'dns' => false]);
            $detectionResult = $detector->getResult();
            $evalResult = ['allowed' => true, 'reasons' => []];
        } elseif ($ipLimited) {
            $detector = new BotDetector();
            $detector->detect(['datacenter' => false, 'tor' => false, 'dns' => false]);
            $detectionResult = $detector->getResult();
            $evalResult = ['allowed' => false, 'reasons' => ['ip_clicks_per_day']];
        } else {
            $detector = new BotDetector();
            $evalResult = $detector->evaluate($rules, $fingerprint, $tokenPresent);
            $detectionResult = $detector->getResult();

            // Delay-start filter: block the first N unique IPs (launch protection).
            // Runs after the main evaluation; adds its reason when triggered.
            if (!empty($rules['delay_start']) && $evalResult['allowed']) {
                $delayReason = delay_start_check(
                    $db,
                    !empty($link['campaign_id']) ? (int)$link['campaign_id'] : (int)$link['id'],
                    !empty($link['campaign_id']),
                    $clientIp,
                    $rules
                );
                if ($delayReason !== '') {
                    $evalResult = ['allowed' => false, 'reasons' => [$delayReason]];
                }
            }

            // Attached reusable filter list (black/white)
            if ($evalResult['allowed'] && !empty($rules['filter_id'])) {
                $listReason = filter_list_check(
                    $db,
                    (int)$rules['filter_id'],
                    (int)$link['user_id'],
                    $clientIp,
                    (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
                    (string)($_SERVER['HTTP_REFERER'] ?? ''),
                    (string)($detectionResult['isp'] ?? '')
                );
                if ($listReason !== '') {
                    $evalResult = ['allowed' => false, 'reasons' => [$listReason]];
                }
            }
        }

        // Defer issuance until the final delivery target is validated so a
        // denied direct request follows the same policy as client mode.
        if ($fingerprint !== [] && $visitorToken !== '') {
            $pendingVisitorIssue = [$scopeKey, $visitorToken];
        }
    }
}

$showOffer = $evalResult['allowed'];
$delivery = null;
$offerUrl = '';
if ($showOffer) {
    $delivery = build_delivery_config(
        $rules,
        (string) ($detectionResult['country'] ?? ''),
        (int) ($rules['offer_shows'] ?? 0)
    );
    $offerUrl = (string) $delivery['offer_url'];

    if ($offerUrl !== '' && !empty($rules['forward_utms'])) {
        $params = $_GET;
        unset($params['_fph'], $params['_fv'], $params['_debug'], $params['clid']);
        if ($params !== []) {
            $offerUrl .= (strpos($offerUrl, '?') !== false ? '&' : '?') . http_build_query($params);
        }
    }
    if (!is_valid_offer_url($offerUrl)) {
        $showOffer = false;
        $evalResult = ['allowed' => false, 'reasons' => ['invalid_offer_url']];
    }
}

// ---- Log the hit -------------------------------------------------------------------
if (defined('LOG_ENABLED') && LOG_ENABLED) {
    $source = derive_source(
        (string)($_SERVER['HTTP_REFERER'] ?? ''),
        (string)($detectionResult['client_type'] ?? ''),
        app_query_scalar('utm_source', 255) ?? ''
    );
    record_hit($db, [
        'link_id' => (int) $link['id'],
        'campaign_id' => !empty($link['campaign_id']) ? (int) $link['campaign_id'] : null,
        'host' => $host,
        'ip' => app_client_ip(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'referer' => $_SERVER['HTTP_REFERER'] ?? '',
        'language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
        'country' => $detectionResult['country'] ?? '',
        'device_type' => $detectionResult['device_type'] ?? '',
        'os_name' => $detectionResult['os_name'] ?? '',
        'os_version' => $detectionResult['os_version'] ?? '',
        'client_type' => $detectionResult['client_type'] ?? '',
        'source' => $source,
        'browser' => $detectionResult['browser'] ?? '',
        'is_bot' => !empty($detectionResult['is_bot']),
        'is_vpn' => !empty($detectionResult['is_vpn']),
        'is_datacenter' => !empty($detectionResult['is_datacenter']),
        'reject_reason' => $showOffer ? null : implode(',', $evalResult['reasons']),
    ], $showOffer);

    maintenance_tick($db);
}

if (is_array($pendingVisitorIssue)) {
    $cookie = visitor_cookie_issue(
        (string) $pendingVisitorIssue[0],
        (string) $pendingVisitorIssue[1],
        app_is_https(),
        $showOffer
    );
    if (is_array($cookie)) {
        setcookie($cookie['name'], $cookie['value'], $cookie['options']);
    }
}

// ---- Route to offer / white page / error ------------------------------------------------
if ($showOffer) {
    // No-cache mode: force the browser/proxies to bypass caches
    if (!empty($rules['no_cache'])) {
        header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate, s-maxage=0');
        header('Pragma: no-cache');
        header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() - 86400));
    }

    $offerMethod = $delivery['offer_method'];
    $delay = $delivery['redirect_delay'];
    $redirectType = $delivery['redirect_type'];

    if ($offerMethod === 'iframe') {
        // Full-page iframe (reference-script OFFER_METHOD=iframe)
        $safeUrl = htmlspecialchars($offerUrl, ENT_QUOTES, 'UTF-8');
        echo "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"UTF-8\">\n"
           . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0\">\n"
           . "<style>html,body,iframe{margin:0;padding:0;height:100%;width:100%;overflow:hidden}</style>\n"
           . "</head>\n<body>\n"
           . "<iframe src=\"{$safeUrl}\" style=\"position:fixed;top:0;left:0;bottom:0;right:0;width:100%;height:100%;border:none\" "
           . "allowfullscreen=\"allowfullscreen\" webkitallowfullscreen=\"webkitallowfullscreen\" mozallowfullscreen=\"mozallowfullscreen\"></iframe>\n"
           . "</body>\n</html>";
        exit;
    } elseif ($redirectType === 'meta' || $delay > 0) {
        // Meta-refresh or delayed redirect (client-side)
        $safeUrl = htmlspecialchars($offerUrl, ENT_QUOTES, 'UTF-8');
        echo "<!DOCTYPE html>\n<html>\n<head>\n"
           . "<meta charset=\"UTF-8\">\n"
           . "<meta http-equiv=\"refresh\" content=\"{$delay};url={$safeUrl}\">\n"
           . "<script>setTimeout(function(){window.location.href="
           . json_encode($offerUrl) . ";}, {$delay}000);</script>\n"
           . "</head>\n<body style=\"font-family:sans-serif;text-align:center;padding:4rem\">\n"
           . "<p>Redirecting…</p>\n</body>\n</html>";
        exit;
    } else {
        $status = $redirectType === '301' ? 301 : ($redirectType === '303' ? 303 : 302);
        header('Location: ' . $offerUrl, true, $status);
        exit;
    }
}

// ---- White page / error action -----------------------------------------------------------
$rejectMode = (string)($rules['reject_mode'] ?? 'white');
if ($rejectMode === 'error') {
    $code = in_array((int)($rules['reject_code'] ?? 403), [400, 403, 404, 410, 429, 451], true)
        ? (int)$rules['reject_code'] : 403;
    http_response_code($code);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;text-align:center;padding:4rem">'
       . '<h1>' . $code . '</h1><p>Access denied.</p></body></html>';
    exit;
}

if (!empty($rules['white_page'])) {
    echo $rules['white_page'];
} elseif ($inactiveCampaignWhitePage !== '') {
    echo $inactiveCampaignWhitePage;
} else {
    echo defined('DEFAULT_WHITE_PAGE') ? DEFAULT_WHITE_PAGE
         : '<html><body><h1>Page Not Found</h1></body></html>';
}
exit;

/**
 * Minimal interstitial page that collects a fingerprint via JS and
 * re-requests the same URL with the payload attached.
 */
function serve_interstitial(string $path, array $query, string $visitorScope): void
{
    $qs = http_build_query($query);
    $redirect = $path . ($qs !== '' ? '?' . $qs : '');
    $fpConfig = json_encode([
        'redirect' => $redirect,
        'visitorStorageKey' => visitor_storage_key($visitorScope),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    echo "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"UTF-8\">\n"
       . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n"
       . "<title>Loading…</title>\n"
       . "<script>window.__CLOAK_CFG__ = {$fpConfig};</script>\n"
       . "<script src=\"/assets/js/tracker.js\"></script>\n"
       . "</head>\n<body style=\"font-family:sans-serif;text-align:center;padding:4rem\">\n"
       . "<p>Please wait…</p>\n</body>\n</html>";
    exit;
}
