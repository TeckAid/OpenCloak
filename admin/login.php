<?php
/**
 * Admin Panel - Login Handler
 */

require_once __DIR__ . '/../includes/bootstrap.php';
boot_app(true);
require_once __DIR__ . '/../includes/auth.php';

// Redirect if already logged in
if (current_user_id() !== null) {
    header('Location: /admin/dashboard.php');
    exit;
}

$error = '';
$rateLimited = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_post();

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $allowed = true;
    if (defined('RATE_LIMIT_ENABLED') && RATE_LIMIT_ENABLED) {
        $allowed = rate_limit('login:ip:' . app_client_ip(), (int) LOGIN_MAX_ATTEMPTS, (int) LOGIN_WINDOW_SECONDS);
        if ($allowed && $username !== '') {
            $allowed = rate_limit(
                'login:account:' . hash('sha256', strtolower($username)),
                (int) LOGIN_MAX_ATTEMPTS,
                (int) LOGIN_WINDOW_SECONDS
            );
        }
    }

    if (!$allowed) {
        $rateLimited = true;
    } elseif ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = getDB()->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['needs_password_change'] = !empty($user['must_change_password']);
            unset($_SESSION['csrf_token']); // rotate on privilege change
            header('Location: /admin/dashboard.php');
            exit;
        }

        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Cloaking SaaS</title>
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260906">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="wordmark">cloak<span class="dot"></span></div>
                <p>Sign in to your traffic console</p>
            </div>
            <?php if ($rateLimited): ?>
                <div class="alert alert-error">Too many login attempts. Please try again later.</div>
            <?php elseif ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST" action="">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autofocus
                           autocomplete="username"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required
                           autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn-primary btn-block">Sign in</button>
            </form>
        </div>
    </div>
</body>
</html>
