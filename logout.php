<?php
/*
 * logout.php
 *
 *  1. session_unset() before session_destroy() — properly clears all data.
 *  2. Deletes the session cookie so the browser doesn't hold a stale ID.
 *  3. Forgets this device's "remember me" token, so logging out really logs out.
 */

require_once __DIR__ . '/auth_remember.php';
f1c_session_start();

$lang = ($_GET['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';

try {
    require_once __DIR__ . '/config.php';
    f1c_remember_forget($pdo);
} catch (Throwable $e) {
    error_log('logout: remember forget failed: ' . $e->getMessage());
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

header('Location: index.php?lang=' . $lang);
exit;
