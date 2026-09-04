<?php
/**
 * Router for the PHP built-in server (local development only).
 *
 * Usage:
 *   php -S 127.0.0.1:8080 dev-router.php
 *
 * Mirrors the .htaccess / nginx.conf routing behavior:
 *   /admin/*  -> admin files (directory index handled by built-in server)
 *   /api/*    -> api/index.php
 *   /assets/* -> static
 *   /slug     -> index.php
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Let the built-in server serve existing files and admin/API/assets paths directly
if (preg_match('#^/(admin|api|assets)(/|$)#', $path)) {
    if ($path === '/api/' || $path === '/api' || !file_exists(__DIR__ . $path)) {
        if (preg_match('#^/api(/|$)#', $path)) {
            require __DIR__ . '/api/index.php';
            return true;
        }
    }
    return false; // serve as-is (admin files exist on disk)
}

if ($path !== '/' && file_exists(__DIR__ . $path)) {
    return false;
}

require __DIR__ . '/index.php';
return true;
