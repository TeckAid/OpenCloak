<?php
/**
 * Admin Panel - Client Mode
 * Generates a deployable client (index.php + tracker.min.js) that the user
 * uploads to their own landing server. The client calls /api/verify
 * server-side and acts on the response.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
boot_app(true);
require_once __DIR__ . '/../includes/auth.php';

require_login();

$db = getDB();
$userId = current_user_id();

$stmt = $db->prepare("SELECT id, name FROM campaigns WHERE user_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$userId]);
$campaigns = $stmt->fetchAll();

$selectedId = 0;
$selected = null;
$verifyUrl = app_base_url() . '/api/verify';
$clientCode = '';
$credentialExpiresAt = null;
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_post();
    $selectedId = (int) ($_POST['campaign_id'] ?? 0);
    $submittedNonce = is_string($_POST['rotation_nonce'] ?? null) ? $_POST['rotation_nonce'] : '';
    $expectedNonce = is_string($_SESSION['client_rotation_nonce'] ?? null) ? $_SESSION['client_rotation_nonce'] : '';

    if ($submittedNonce === '' || $expectedNonce === '' || !hash_equals($expectedNonce, $submittedNonce)) {
        http_response_code(409);
        $message = 'This client export request has already been used or expired. Reload and confirm again.';
    } elseif ((string) ($_POST['confirm_rotation'] ?? '') !== '1') {
        http_response_code(400);
        $message = 'Confirm credential rotation before generating a client export.';
    } else {
        unset($_SESSION['client_rotation_nonce']);
        $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ? AND user_id = ? AND is_active = 1");
        $stmt->execute([$selectedId, $userId]);
        $selected = $stmt->fetch() ?: null;
        if ($selected === null) {
            http_response_code(404);
            $message = 'Active campaign not found.';
        } else {
            $template = file_get_contents(__DIR__ . '/../includes/client_template.php.txt');
            if (!is_string($template) || $template === '') {
                throw new RuntimeException('Client export template is unavailable.');
            }
            $clientCredential = issue_client_credential($db, $userId, (int) $selected['id']);
            $credentialRow = authenticate_client_credential($db, $clientCredential);
            $clientCode = strtr($template, [
                '{{CLIENT_CREDENTIAL}}' => $clientCredential,
                '{{CAMPAIGN_ID}}' => (string) $selected['id'],
                '{{VERIFY_URL}}' => $verifyUrl,
            ]);
            $credentialExpiresAt = is_array($credentialRow) ? (string) ($credentialRow['expires_at'] ?? '') : null;
        }
    }
}

$_SESSION['client_rotation_nonce'] = bin2hex(random_bytes(32));
$rotationNonce = $_SESSION['client_rotation_nonce'];

$trackerCode = file_get_contents(__DIR__ . '/../assets/js/tracker.js');
if (!is_string($trackerCode)) {
    throw new RuntimeException('Client tracker template is unavailable.');
}

$activeNav = '/admin/client.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Mode - Cloaking SaaS</title>
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260905">
</head>
<body>
    <?php include __DIR__ . '/_nav.php'; ?>

    <div class="container">
        <h1 class="page-title">Client mode</h1>
        <p class="page-intro">Run verification from your own landing server.</p>
        <div class="card">
            <div class="card-header"><h2>Client Deployment</h2></div>
            <div class="card-body">
                <p>Use this when you want to show a landing page from <strong>your own server</strong>.
                The generated client calls our verification API server-side and shows the offer or safe page
                according to the campaign rules.</p>

                <?php if ($message !== ''): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>
                <form method="POST" action="/admin/client.php" onsubmit="return confirm('Generate this export and revoke the prior campaign credential?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="rotation_nonce" value="<?= htmlspecialchars($rotationNonce, ENT_QUOTES) ?>">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="campaign_id">Campaign</label>
                            <select id="campaign_id" name="campaign_id" required>
                                <option value="">— select campaign —</option>
                                <?php foreach ($campaigns as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= $selectedId === (int)$c['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['name']) ?> (ID <?= (int)$c['id'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><input type="checkbox" name="confirm_rotation" value="1" required>
                                I understand this revokes the prior client credential.</label>
                        </div>
                        <div class="form-group" style="align-self:flex-end">
                            <button type="submit" class="btn btn-primary">Generate Client</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($selected): ?>
            <div class="card">
                <div class="card-header">
                    <h2>Deployment Files</h2>
                </div>
                <div class="card-body">
                    <h3>Step 1 — Save <code>index.php</code> to your landing server</h3>
                    <textarea readonly rows="22" class="code-area" id="client-index"><?= htmlspecialchars($clientCode) ?></textarea>
                    <div class="form-actions">
                        <button onclick="downloadText('index.php', document.getElementById('client-index').value)" class="btn btn-primary">Download index.php</button>
                    </div>

                    <h3 style="margin-top:2rem">Step 2 — Save <code>tracker.min.js</code> next to it</h3>
                    <textarea readonly rows="14" class="code-area" id="client-tracker"><?= htmlspecialchars($trackerCode) ?></textarea>
                    <div class="form-actions">
                        <button onclick="downloadText('tracker.min.js', document.getElementById('client-tracker').value)" class="btn btn-primary">Download tracker.min.js</button>
                    </div>

                    <?php if ($credentialExpiresAt): ?>
                        <p><strong>Scoped verify credential:</strong> this export rotates the prior campaign token and expires at
                        <code><?= htmlspecialchars($credentialExpiresAt) ?> UTC</code>.</p>
                    <?php endif; ?>
                    <p>Upload both files to your landing server directory, ensure PHP is available, then visit the URL.
                    Offer targets must be validated HTTP(S) URLs configured on the campaign.
                    Results appear in the <a href="/admin/dashboard.php">Dashboard</a>.</p>
                </div>
            </div>
        <?php elseif (empty($campaigns)): ?>
            <div class="alert alert-warning">Create a campaign first: <a href="/admin/campaigns.php">Campaigns</a></div>
        <?php endif; ?>
    </div>

    <script>
    function downloadText(filename, content) {
        var blob = new Blob([content], { type: 'application/octet-stream' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(a.href);
    }
    </script>
</body>
</html>
