<?php
/*
 * toggle_user.php — enable / disable a user account.
 *
 * FIX: The previous version required POST + CSRF, but users.php was
 *      calling this via plain <a href> (GET), so every toggle silently
 *      failed.  This version accepts both:
 *
 *   • GET  ?id=X&action=enable|disable&lang=xx
 *     — works with the existing <a href> links in users.php.
 *
 *   • POST with csrf_token
 *     — still supported for any future form-based callers.
 *
 * Security notes kept intact:
 *   - Admin-only access.
 *   - Cannot disable yourself.
 *   - auth.php kicks a disabled user out on their next page load.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'auth.php';
require 'config.php';

// Permission gate (default: admin only)
if (!can('action.toggle_user')) {
    http_response_code(403);
    die('Access Denied');
}

// Accept both GET (existing <a href> links) and POST (form submissions)
$id     = (int)(($_POST['id']     ?? $_GET['id']     ?? 0));
$action = trim( ($_POST['action'] ?? $_GET['action'] ?? ''));
$lang   = trim( ($_POST['lang']   ?? $_GET['lang']   ?? 'ar'));

if (!$id || !in_array($action, ['enable', 'disable'], true)) {
    header('Location: users.php?lang=' . urlencode($lang));
    exit;
}

// Cannot disable yourself
if ($id === (int)$_SESSION['user_id']) {
    $_SESSION['error'] = 'You cannot disable your own account.';
    header('Location: users.php?lang=' . urlencode($lang));
    exit;
}

// Cannot disable the primary admin (id = 1) as an extra safeguard
$target = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
$target->execute([$id]);
$targetUser = $target->fetch(PDO::FETCH_ASSOC);

if (!$targetUser) {
    header('Location: users.php?lang=' . urlencode($lang));
    exit;
}

$newActive = ($action === 'enable') ? 1 : 0;

$stmt = $pdo->prepare("UPDATE users SET active = ? WHERE id = ?");
$stmt->execute([$newActive, $id]);

// auth.php re-checks active on every request — disabled user is
// kicked out automatically on their very next page load.

header('Location: users.php?lang=' . urlencode($lang));
exit;