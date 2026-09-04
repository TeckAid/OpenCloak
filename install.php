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
if (!preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $username)) {
    fwrite(STDERR, "Error: username may only contain letters, numbers, dot, dash, underscore.\n");
    exit(1);
}

$db = getDB();
$db->exec('BEGIN IMMEDIATE');

try {
    $existingUsers = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($existingUsers > 0) {
        $db->rollBack();
        fwrite(STDERR, "Error: installer has already created the first administrator.\n");
        exit(1);
    }

    $password = (string) ($options['password'] ?? '');
    $generated = false;
    if (isset($options['generate-password']) || $password === '') {
        $password = bin2hex(random_bytes(9));
        $generated = true;
    }

    if (strlen($password) < 10) {
        $db->rollBack();
        fwrite(STDERR, "Error: password must be at least 10 characters.\n");
        exit(1);
    }

    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $apiKey = bin2hex(random_bytes(32));
    $db->prepare("INSERT INTO users (username, password, api_key, must_change_password) VALUES (?, ?, ?, 0)")
       ->execute([$username, $hashed, $apiKey]);
    $db->commit();

    if ($generated) {
        echo "Generated password: {$password}\n";
    }

    echo "Created user '{$username}'.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $e;
}

echo "\nSetup complete.\n";
echo "Admin panel: http://yourdomain.com/admin/\n";
echo "\nSecurity checklist:\n";
echo "  - Serve over HTTPS only\n";
echo "  - data/ and logs/ are denied by .htaccess / nginx.conf\n";
echo "  - If you sit behind Cloudflare or another proxy, set TRUSTED_PROXIES in config.local.php\n";
echo "  - Rotate API keys from the admin Settings page when needed\n";
