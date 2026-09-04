<?php
/**
 * Authentication helpers for admin pages.
 */

function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function current_user(): ?array
{
    $id = current_user_id();
    if ($id === null) {
        return null;
    }
    static $user = null;
    static $loadedId = null;
    if ($user === null || $loadedId !== $id) {
        $stmt = getDB()->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch() ?: null;
        $loadedId = $id;
    }
    return $user;
}

/**
 * Redirect to login if not authenticated, and to settings.php when a
 * password change is still required for an existing account.
 */
function require_login(): void
{
    if (current_user_id() === null) {
        header('Location: /admin/login.php');
        exit;
    }
    if (!empty($_SESSION['needs_password_change'])) {
        $self = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if ($self !== 'settings.php' && $self !== 'login.php') {
            header('Location: /admin/settings.php?force=1');
            exit;
        }
    }
}

/**
 * Must be called on every admin POST handler. Halts on CSRF failure.
 */
function require_valid_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrf_verify()) {
        http_response_code(403);
        header('Cache-Control: no-store');
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html><body style="font-family:sans-serif;text-align:center;padding:4rem">'
           . '<h1>403</h1><p>Invalid or expired security token. Please go back and try again.</p></body></html>';
        exit;
    }
}
