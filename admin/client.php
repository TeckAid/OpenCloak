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

$selectedId = (int)($_GET['campaign_id'] ?? 0);
$selected = null;
if ($selectedId > 0) {
    $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ? AND user_id = ?");
    $stmt->execute([$selectedId, $userId]);
    $selected = $stmt->fetch() ?: null;
}

$user = current_user();
$apiKey = $user['api_key'];
$verifyUrl = app_base_url() . '/api/verify';

$clientCode = '';
if ($selected && $apiKey) {
    $placeholders = [
        '{{API_KEY}}'     => $apiKey,
        '{{CAMPAIGN_ID}}' => (string)$selected['id'],
        '{{VERIFY_URL}}'  => $verifyUrl,
    ];
    $template = file_get_contents(__DIR__ . '/../includes/client_template.php.txt');
    $clientCode = strtr($template, $placeholders);
}

$trackerCode = file_get_contents(__DIR__ . '/../assets/js/tracker.js');

$activeNav = '/admin/client.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Mode - Cloaking SaaS</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <?php include __DIR__ . '/_nav.php'; ?>

    <div class="container">
        <div class="card">
            <div class="card-header"><h2>Client Deployment</h2></div>
            <div class="card-body">
                <p>Use this when you want to show a landing page from <strong>your own server</strong>.
                The generated client calls our verification API server-side and shows the offer or safe page
                according to the campaign rules.</p>

                <form method="GET" action="/admin/client.php">
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

                    <h3 style="margin-top:2rem">Step 3 — Optional money page</h3>
                    <p>If the campaign's offer URL is not a full http(s) link but a filename (e.g. <code>page.html</code>),
                    the client will render that file from its own directory. Otherwise visitors are redirected to the offer URL.</p>
                    <p>Upload both files to your landing server directory, ensure PHP is available, then visit the URL.
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
