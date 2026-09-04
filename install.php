<?php
/**
 * CLI installer: creates the admin user with a custom password.
 *
 * Usage:
 *   php install.php --username=admin --password='StrongPass123!'
 *
 * Run this before exposing the app to the internet. The first user created
 * this way has must_change_password=0 (unlike the auto-created default).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/includes/bootstrap.php';
boot_app(false);

$options = getopt('', ['username::', 'password::', 'generate-password']);
$username = trim((string)($options['username'] ?? 'admin'));
$password = (string)($options['password'] ?? '');

if (isset($options['generate-password']) || $password === '') {
    $password = bin2hex(random_bytes(9)); // 18 hex chars
    echo "Generated password: {$password}\n";
}

if (strlen($password) < 10) {
    fwrite(STDERR, "Error: password must be at least 10 characters.\n");
    exit(1);
}
if (!preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $username)) {
    fwrite(STDERR, "Error: username may only contain letters, numbers, dot, dash, underscore.\n");
    exit(1);
}

$db = getDB();
$stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
$stmt->execute([$username]);

if ($stmt->fetch()) {
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $db->prepare("UPDATE users SET password = ?, must_change_password = 0, updated_at = CURRENT_TIMESTAMP WHERE username = ?")
       ->execute([$hashed, $username]);
    echo "Updated password for existing user '{$username}'.\n";
} else {
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $apiKey = bin2hex(random_bytes(32));
    $db->prepare("INSERT INTO users (username, password, api_key, must_change_password) VALUES (?, ?, ?, 0)")
       ->execute([$username, $hashed, $apiKey]);
    echo "Created user '{$username}'.\n";
}

echo "\nSetup complete.\n";
echo "Admin panel: http://yourdomain.com/admin/\n";
echo "\nSecurity checklist:\n";
echo "  - Serve over HTTPS only\n";
echo "  - data/ and logs/ are denied by .htaccess / nginx.conf\n";
echo "  - If you sit behind Cloudflare or another proxy, set TRUSTED_PROXIES in config.local.php\n";
echo "  - Rotate API keys from the admin Settings page when needed\n";
