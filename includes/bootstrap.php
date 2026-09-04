<?php
/**
 * Application bootstrap: error handling, security headers, sessions.
 *
 * Usage:
 *   require bootstrap(true)  - admin pages (session + everything)
 *   require bootstrap(false) - public/API pages (no session, faster)
 */

function boot_app(bool $withSession = true): void
{
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/security.php';

    // ---- Error handling: log, never leak details ---------------------------
    $logErrors = function (Throwable $e): void {
        if ($e instanceof BadRequestException) {
            app_abort_request($e->getCode() >= 400 ? $e->getCode() : 400, $e->getMessage() !== '' ? $e->getMessage() : 'Bad request.');
        }

        $line = sprintf(
            "[%s] %s: %s in %s:%d\n",
            date('Y-m-d H:i:s'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );
        if (defined('LOG_PATH') && is_dir(LOG_PATH)) {
            @file_put_contents(LOG_PATH . 'error.log', $line, FILE_APPEND | LOCK_EX);
        }

        $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api') === 0;
        if (!headers_sent()) {
            http_response_code(500);
        }
        if ($isApi) {
            echo json_encode(['error' => 'Internal server error']);
        } else {
            echo '<!DOCTYPE html><html><head><title>Server Error</title></head>'
               . '<body style="font-family:sans-serif;text-align:center;padding:4rem">'
               . '<h1>500</h1><p>Something went wrong. Please try again later.</p></body></html>';
        }
    };
    set_exception_handler($logErrors);
    set_error_handler(function ($severity, $message, $file, $line) use ($logErrors) {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    // ---- Security headers ---------------------------------------------------
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    $isAdminPath = strpos($_SERVER['REQUEST_URI'] ?? '', '/admin') === 0;
    if ($isAdminPath) {
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; "
             . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'self'");
    }

    require_once __DIR__ . '/database.php';
    initDatabase();
    app_enforce_allowed_host();

    // ---- Sessions (admin only; public cloaked requests stay session-free) ---
    if ($withSession) {
        $secure = app_is_https();
        session_name('cloaksess');
        session_set_cookie_params([
            'lifetime' => defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 28800,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string)(defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 28800));
        session_start();

        // Enforce lifetime on the server side as well
        $now = time();
        if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity'] > SESSION_LIFETIME)) {
            session_unset();
            session_destroy();
            session_start();
        }
        $_SESSION['last_activity'] = $now;
    }
}
