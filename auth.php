<?php
/*
 * auth.php — included at the top of EVERY protected page.
 *
 * Fixes applied:
 *  1. Starts session if not already started.
 *  2. Checks session exists (user_id set).
 *  3. Re-queries the DB on EVERY request to verify the user is still active.
 *     → If an admin disables the account, the next page load logs them out.
 *  4. Destroys session and redirects to login on any failure.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Step 1: Must be logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Step 2: Verify user is still active in DB on every request
// This is what makes disabled-user lockout work immediately.
require_once __DIR__ . '/config.php';   // safe to include multiple times — once is fine

$stmt = $pdo->prepare("SELECT id, username, role, active FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser || (int)$currentUser['active'] !== 1) {
    // User not found OR has been disabled — kill session and redirect
    session_unset();
    session_destroy();
    header('Location: index.php?disabled=1');
    exit;
}

// Step 3: Refresh session data in case role changed
$_SESSION['username'] = $currentUser['username'];
$_SESSION['role']     = $currentUser['role'];

// Step 4: Load the permission engine — every protected page gets can() / perm_require().
require_once __DIR__ . '/permissions.php';