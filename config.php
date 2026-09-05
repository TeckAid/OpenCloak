<?php
/**
 * Cloaking SaaS - Configuration
 *
 * Secrets are NOT hardcoded. On first run a random key is generated in the
 * runtime directory and all secrets are derived from it. For advanced setups,
 * copy config.local.example.php to config.local.php and adjust values.
 */

// ---- Local overrides (optional) -------------------------------------------
if (file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

// ---- Runtime storage -------------------------------------------------------
if (!defined('APP_RUNTIME_DIR')) {
    define('APP_RUNTIME_DIR', dirname(__DIR__) . '/cloaking-runtime');
}

// ---- Database --------------------------------------------------------------
if (!defined('DB_PATH')) {
    define('DB_PATH', APP_RUNTIME_DIR . '/cloaking.sqlite');
}

// ---- Application key (random, generated on first run) ----------------------
if (!function_exists('app_load_or_create_key')) {
    function app_load_or_create_key(string $runtimeDir): string
    {
        if (file_exists($runtimeDir) && !is_dir($runtimeDir)) {
            throw new RuntimeException('Unable to persist application key: runtime path is not a directory.');
        }
        if (!is_dir($runtimeDir) && !mkdir($runtimeDir, 0700, true) && !is_dir($runtimeDir)) {
            throw new RuntimeException('Unable to persist application key: runtime directory could not be created.');
        }
        if (!chmod($runtimeDir, 0700)) {
            throw new RuntimeException('Unable to secure application key runtime directory.');
        }

        $keyPath = rtrim($runtimeDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'app.key';
        if (is_link($keyPath) || (file_exists($keyPath) && !is_file($keyPath))) {
            throw new RuntimeException('Unable to load application key: key path is not a regular file.');
        }

        $lockPath = $keyPath . '.lock';
        $lock = fopen($lockPath, 'c');
        if (!is_resource($lock)) {
            throw new RuntimeException('Unable to persist application key: lock file could not be opened.');
        }

        try {
            if (!chmod($lockPath, 0600) || !flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to persist application key: lock could not be secured.');
            }

            if (is_file($keyPath)) {
                $key = trim((string) file_get_contents($keyPath));
                if (!preg_match('/^[a-f0-9]{64}$/', $key)) {
                    throw new RuntimeException('Unable to load application key: stored key is invalid.');
                }
                if (!chmod($keyPath, 0600) || (fileperms($keyPath) & 0777) !== 0600) {
                    throw new RuntimeException('Unable to secure application key file permissions.');
                }

                return $key;
            }

            $key = bin2hex(random_bytes(32));
            $temporaryPath = $runtimeDir . DIRECTORY_SEPARATOR . '.app.key.' . bin2hex(random_bytes(8)) . '.tmp';
            $temporary = fopen($temporaryPath, 'x');
            if (!is_resource($temporary)) {
                throw new RuntimeException('Unable to persist application key: temporary file could not be created.');
            }

            $writeSucceeded = false;
            try {
                if (!chmod($temporaryPath, 0600)
                    || fwrite($temporary, $key . PHP_EOL) !== strlen($key) + 1
                    || !fflush($temporary)) {
                    throw new RuntimeException('Unable to persist application key: atomic write failed.');
                }
                if (function_exists('fsync') && !fsync($temporary)) {
                    throw new RuntimeException('Unable to persist application key: sync failed.');
                }
                $writeSucceeded = true;
            } finally {
                fclose($temporary);
                if (!$writeSucceeded) {
                    @unlink($temporaryPath);
                }
            }

            if (!rename($temporaryPath, $keyPath)) {
                @unlink($temporaryPath);
                throw new RuntimeException('Unable to persist application key: atomic rename failed.');
            }
            if (!chmod($keyPath, 0600) || (fileperms($keyPath) & 0777) !== 0600) {
                throw new RuntimeException('Unable to secure application key file permissions.');
            }

            return $key;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

if (!defined('APP_KEY')) {
    try {
        define('APP_KEY', app_load_or_create_key(APP_RUNTIME_DIR));
    } catch (Throwable $e) {
        fwrite(STDERR, 'Fatal application key error: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
if (preg_match('/^[a-f0-9]{64}$/', (string) APP_KEY) !== 1) {
    fwrite(STDERR, "Fatal application key error: APP_KEY must be exactly 64 lowercase hexadecimal characters.\n");
    exit(1);
}

// ---- Security ---------------------------------------------------------------
if (!defined('ADMIN_SECRET_KEY')) {
    define('ADMIN_SECRET_KEY', hash_hmac('sha256', 'admin', APP_KEY));
}
if (!defined('JWT_SECRET')) {
    define('JWT_SECRET', hash_hmac('sha256', 'jwt', APP_KEY));
}
if (!defined('SESSION_LIFETIME')) {
    define('SESSION_LIFETIME', 3600 * 8); // 8 hours
}

// Canonical application URL and system short-link hosts. Never derive these
// from an arbitrary Host header in production.
if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', 'http://127.0.0.1');
}
if (!defined('SYSTEM_HOSTS')) {
    $appBaseHost = parse_url(APP_BASE_URL, PHP_URL_HOST);
    define('SYSTEM_HOSTS', [is_string($appBaseHost) && $appBaseHost !== '' ? strtolower($appBaseHost) : '127.0.0.1']);
}

// Proxies that are allowed to set forwarding headers (e.g. Cloudflare ranges).
// Empty = never trust forwarding headers; always use REMOTE_ADDR.
if (!defined('TRUSTED_PROXIES')) {
    define('TRUSTED_PROXIES', []);
}

// Trust Cloudflare edge headers (CF-Connecting-IP, CF-IPCountry, CF-IPASN,
// CF-Visitor) when the CF-RAY header is present. Enable ONLY when the origin
// firewall restricts direct traffic to Cloudflare's published IP ranges —
// otherwise these headers can be spoofed by direct requests. When enabled,
// CF-IPCountry/CF-IPASN satisfy geo and ASN rules without an IP-intelligence
// adapter call.
if (!defined('TRUST_CLOUDFLARE')) {
    define('TRUST_CLOUDFLARE', false);
}

// ---- Cloaking defaults ------------------------------------------------------
if (!defined('DEFAULT_WHITE_PAGE')) {
    define('DEFAULT_WHITE_PAGE', '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
               display: flex; justify-content: center; align-items: center; min-height: 100vh;
               background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; }
        .container { text-align: center; padding: 2rem; }
        h1 { font-size: 2.5rem; margin-bottom: 1rem; }
        p { font-size: 1.2rem; opacity: 0.9; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Welcome</h1>
        <p>This page is currently under construction.</p>
    </div>
</body>
</html>
');
}

// ---- Detection settings -----------------------------------------------------
if (!defined('ENABLE_TOR_CHECK')) {
    define('ENABLE_TOR_CHECK', true);
}
if (!defined('IP_INTELLIGENCE_ENDPOINT')) {
    define('IP_INTELLIGENCE_ENDPOINT', '');
}
if (!defined('IP_INTELLIGENCE_API_KEY')) {
    define('IP_INTELLIGENCE_API_KEY', '');
}
if (!defined('IP_INTELLIGENCE_FAILURE_MODE')) {
    define('IP_INTELLIGENCE_FAILURE_MODE', 'closed');
}

// ---- Rate limiting -----------------------------------------------------------
if (!defined('RATE_LIMIT_ENABLED')) {
    define('RATE_LIMIT_ENABLED', true);
}
if (!defined('LOGIN_MAX_ATTEMPTS')) {
    define('LOGIN_MAX_ATTEMPTS', 10);          // per IP
}
if (!defined('LOGIN_WINDOW_SECONDS')) {
    define('LOGIN_WINDOW_SECONDS', 300);       // 5 minutes
}

// ---- Logging ------------------------------------------------------------------
if (!defined('LOG_ENABLED')) {
    define('LOG_ENABLED', true);
}
if (!defined('LOG_PATH')) {
    define('LOG_PATH', APP_RUNTIME_DIR . '/logs/');
}
if (!defined('LOG_RETENTION_DAYS')) {
    define('LOG_RETENTION_DAYS', 30);
}
