<?php
/*
 * auth.php — included at the top of EVERY protected page.
 *
 *  1. Starts session if not already started (safe cookie flags).
 *  2. Checks session exists (user_id set) — or restores it from a valid
 *     "remember me" token on this device.
 *  3. Re-queries the DB on EVERY request to verify the user is still active.
 *     → If an admin disables the account, the next page load logs them out.
 *  4. Destroys session and redirects to login on any failure, remembering the
 *     page that was opened so login can bring the user straight back to it.
 */

require_once __DIR__ . '/auth_remember.php';
f1c_session_start();

require_once __DIR__ . '/config.php';   // safe to include multiple times — once is fine

// Step 1: Must be logged in (a remembered device signs itself back in)
if (!isset($_SESSION['user_id']) && !f1c_remember_login($pdo)) {
    $next = '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $next = f1c_safe_next(ltrim(basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''), '/')
              . (($q = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY)) ? '?' . $q : ''));
    }
    $lang = ($_GET['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
    header('Location: index.php?lang=' . $lang . ($next !== '' ? '&next=' . urlencode($next) : ''));
    exit;
}

// Step 2: Verify user is still active in DB on every request
// This is what makes disabled-user lockout work immediately.
$stmt = $pdo->prepare("SELECT id, username, role, active FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser || (int)$currentUser['active'] !== 1) {
    // User not found OR has been disabled — kill session (and this device's
    // remember-me token) and redirect
    f1c_remember_forget($pdo);
    session_unset();
    session_destroy();
    header('Location: index.php?disabled=1');
    exit;
}

// Step 3: Refresh session data in case role changed
$_SESSION['username'] = $currentUser['username'];
$_SESSION['role']     = $currentUser['role'];

// "Online now": remember when this person was last active (at most once a minute)
if (time() - (int)($_SESSION['seen_t'] ?? 0) >= 60) {
    $_SESSION['seen_t'] = time();
    try {
        $pdo->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?")->execute([$currentUser['id']]);
    } catch (Throwable $e) {
        try { $pdo->exec("ALTER TABLE users ADD last_seen DATETIME NULL"); } catch (Throwable $e2) {}
    }
}

// Step 4: Load the permission engine — every protected page gets can() / perm_require().
require_once __DIR__ . '/permissions.php';

// Step 5: locked for not confirming transferred cars in time → only the lock screen
// (plus clocking in/out and signing out) until the admin unlocks. Never an admin.
if ($currentUser['role'] !== 'admin') {
    $__page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (!in_array($__page, ['transfer_lock.php', 'logout.php', 'attendance.php', 'push_subscribe.php', 'notify_feed.php'], true)) {
        try {
            $__lk = $pdo->prepare("SELECT 1 FROM user_locks WHERE user_id = ? AND unlocked_at IS NULL LIMIT 1");
            $__lk->execute([$currentUser['id']]);
            if ($__lk->fetchColumn()) {
                $lang = ($_GET['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
                if (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') === false) {   // background requests get a short answer
                    http_response_code(423);
                    header('Content-Type: application/json; charset=utf-8');
                    exit(json_encode(['ok' => false, 'error' => 'locked']));
                }
                header('Location: transfer_lock.php?lang=' . $lang);
                exit;
            }
        } catch (Throwable $e) {}   // table not created yet → nobody is locked
    }
}
