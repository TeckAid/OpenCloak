<?php
/**
 * Admin Panel - Settings
 */

require_once __DIR__ . '/../includes/bootstrap.php';
boot_app(true);
require_once __DIR__ . '/../includes/auth.php';

require_login();

$db = getDB();
$userId = current_user_id();
$message = '';
$messageType = '';

$forceChange = isset($_GET['force']) || !empty($_SESSION['needs_password_change']);

// ---- Handle form submissions --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_post();
    $action = $_POST['action'] ?? '';

    if ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userRow = $stmt->fetch();

        if (!$userRow || !password_verify($currentPassword, $userRow['password'])) {
            $message = 'Current password is incorrect.';
            $messageType = 'error';
        } elseif (strlen($newPassword) < 10) {
            $message = 'Password must be at least 10 characters.';
            $messageType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $message = 'New passwords do not match.';
            $messageType = 'error';
        } elseif ($newPassword === $currentPassword) {
            $message = 'New password must be different from the current one.';
            $messageType = 'error';
        } else {
            $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET password = ?, must_change_password = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
               ->execute([$hashed, $userId]);
            $_SESSION['needs_password_change'] = false;
            unset($_SESSION['needs_password_change']);
            session_regenerate_id(true);
            $message = 'Password changed successfully!';
            $messageType = 'success';
            $forceChange = false;
        }
    } elseif ($action === 'regenerate_api') {
        $apiKey = bin2hex(random_bytes(32));
        $db->prepare("UPDATE users SET api_key = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
           ->execute([$apiKey, $userId]);
        $message = 'API key regenerated!';
        $messageType = 'success';
    }
}

$user = current_user();
$baseUrl = app_base_url();

$activeNav = '/admin/settings.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Cloaking SaaS</title>
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260906">
</head>
<body>
    <?php include __DIR__ . '/_nav.php'; ?>

    <div class="container">
        <h1 class="page-title">Settings</h1>
        <p class="page-intro">Password, API key, and console details.</p>
        <?php if ($forceChange): ?>
            <div class="alert alert-warning">You must change your password before continuing.</div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2>Change Password</h2>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-group">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                    </div>
                    <div class="form-group">
                        <label for="new_password">New Password (min 10 characters)</label>
                        <input type="password" id="new_password" name="new_password" required minlength="10" autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-primary">Change Password</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>API Access</h2>
            </div>
            <div class="card-body">
                <p>Use the API key to manage your links programmatically.</p>
                <div class="api-key-box">
                    <code id="api-key"><?= htmlspecialchars($user['api_key']) ?></code>
                    <button onclick="copyApiKey()" class="btn btn-sm">Copy</button>
                </div>
                <form method="POST" action="" style="margin-top: 1rem;"
                      onsubmit="return confirm('Regenerate API key? Old key will stop working.')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="regenerate_api">
                    <button type="submit" class="btn btn-warning">Regenerate API Key</button>
                </form>

                <h3 style="margin-top: 2rem;">API Usage</h3>
                <pre class="code-block">GET /api/links          List links
POST /api/links         Create link
GET /api/campaigns      List campaigns
POST /api/campaigns     Create campaign
POST /api/verify        Verify a visitor (client mode)
Authorization: Bearer {your_api_key}</pre>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>How to Use</h2>
            </div>
            <div class="card-body">
                <h3>Setup</h3>
                <ol>
                    <li>Create a link in the <a href="/admin/links.php">Links</a> section</li>
                    <li>Use the generated URL in your campaigns</li>
                    <li>Real users will be redirected to your offer page</li>
                    <li>Bots and moderators will see the white page</li>
                </ol>

                <h3>Testing</h3>
                <p>Use the authenticated <strong>Diagnostics</strong> action on the Links page.
                Diagnostics require your admin session and a one-time CSRF-protected POST.</p>

                <h3>Custom White Pages</h3>
                <p>You can set a custom white page HTML for each link, or use the default safe page.</p>
            </div>
        </div>
    </div>

    <script>
    function copyApiKey() {
        var el = document.getElementById('api-key');
        navigator.clipboard.writeText(el.textContent).then(function () {
            alert('API key copied!');
        });
    }
    </script>
</body>
</html>
