<?php
/*
 * logout.php
 *
 * Fixes applied:
 *  1. session_unset() before session_destroy() — properly clears all data.
 *  2. Deletes the session cookie so the browser doesn't hold a stale ID.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear all session variables
session_unset();

// Delete the session cookie from the browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy the session on the server
session_destroy();

header('Location: index.php');
exit;