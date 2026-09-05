<?php
/**
 * CLI installer: creates the admin user with a custom password.
 *
 * Usage:
 *   printf '%s\n' 'StrongPass123!' | php install.php --username=admin --password-stdin
 *
 * Run this before exposing the app to the internet.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--password=')) {
        fwrite(STDERR, "Error: passwords in process arguments are not supported; use --password-stdin.\n");
        exit(1);
    }
}

$options = getopt('', ['username::', 'password-stdin', 'generate-password']);
$password = '';
$generated = false;
if (isset($options['password-stdin'])) {
    $line = fgets(STDIN);
    if (!is_string($line)) {
        fwrite(STDERR, "Error: unable to read password from stdin.\n");
        exit(1);
    }
    $password = rtrim($line, "\r\n");
} elseif (isset($options['generate-password'])) {
    $password = bin2hex(random_bytes(9));
    $generated = true;
} else {
    fwrite(STDERR, "Error: use --password-stdin or --generate-password.\n");
    exit(1);
}

require __DIR__ . '/config.php';
require __DIR__ . '/includes/database.php';

$db = setupDatabase();

$username = trim((string)($options['username'] ?? 'admin'));
if (!preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $username)) {
    fwrite(STDERR, "Error: username may only contain letters, numbers, dot, dash, underscore.\n");
    exit(1);
}

$db->exec('BEGIN IMMEDIATE');

try {
    $existingUsers = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($existingUsers > 0) {
        $db->rollBack();
        fwrite(STDERR, "Error: installer has already created the first administrator.\n");
        exit(1);
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
echo "Admin panel: https://yourdomain.com/admin/\n";
echo "\nSecurity checklist:\n";
echo "  - Serve over HTTPS only\n";
echo "  - data/ and logs/ are denied by .htaccess / nginx.conf\n";
echo "  - If you sit behind Cloudflare or another proxy, set TRUSTED_PROXIES in config.local.php\n";
echo "  - Rotate API keys from the admin Settings page when needed\n";
