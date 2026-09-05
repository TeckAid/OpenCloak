<?php
/**
 * Authenticated, CSRF-protected link diagnostics. Public URLs never expose a
 * reusable diagnostic secret and this endpoint performs no state mutation.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
boot_app(true);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rules.php';
require_once __DIR__ . '/../includes/bot_detector.php';

require_login();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

require_valid_post();
$db = getDB();
$linkId = (int) ($_POST['link_id'] ?? 0);
$link = owned_row($db, 'links', $linkId, (int) current_user_id());
if ($link === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Link not found.']);
    exit;
}

$rules = effective_rules($db, $link);
if ($rules === null) {
    echo json_encode([
        'link_id' => $linkId,
        'allowed' => false,
        'reasons' => ['campaign_inactive'],
        'detection' => null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$detector = new BotDetector();
$evaluation = $detector->evaluate($rules);
echo json_encode([
    'link_id' => $linkId,
    'allowed' => $evaluation['allowed'],
    'reasons' => $evaluation['reasons'],
    'detection' => $detector->getResult(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
