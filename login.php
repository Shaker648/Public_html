
<?php
/*
 * login.php — handles POST login form submission only.
 *
 * FIX: last_login is now written to the DB on every successful login.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');

// ── Rate limiting: max 5 failed attempts, then 15-minute lockout ──
$attempts  = $_SESSION['login_attempts']  ?? 0;
$lockUntil = $_SESSION['login_lock_until'] ?? 0;

if ($lockUntil && time() < $lockUntil) {
    $wait = ceil(($lockUntil - time()) / 60);
    $_SESSION['error'] = "⏳ Too many failed attempts. Try again in {$wait} minute(s).";
    header('Location: index.php');
    exit;
}

if ($lockUntil && time() >= $lockUntil) {
    $_SESSION['login_attempts']   = 0;
    $_SESSION['login_lock_until'] = 0;
    $attempts = 0;
}

// ── Lookup user — only active accounts ──
$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND active = 1 LIMIT 1");
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$valid = false;

if ($user) {
    if (password_verify($password, $user['password'])) {
        $valid = true;
    } elseif ($user['password'] === $password) {
        // TEMPORARY plain-text fallback — auto-upgrade to hash
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $upd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $upd->execute([$hashed, $user['id']]);
        $valid = true;
    }
}

if ($valid) {
    session_regenerate_id(true);

    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role']     = $user['role'];

    // ── FIX: record the login timestamp ──────────────────────────────
    $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
        ->execute([$user['id']]);
    // ─────────────────────────────────────────────────────────────────

    unset($_SESSION['login_attempts'], $_SESSION['login_lock_until']);

    header('Location: dashboard.php');
    exit;
}

// ── Failed login ──
$_SESSION['login_attempts'] = $attempts + 1;

if ($_SESSION['login_attempts'] >= 5) {
    $_SESSION['login_lock_until'] = time() + (15 * 60);
    $_SESSION['error'] = "⛔ Too many failed attempts. Account locked for 15 minutes.";
} else {
    $remaining = 5 - $_SESSION['login_attempts'];
    $_SESSION['error'] =
        "❌ اسم المستخدم أو كلمة المرور غير صحيحة ({$remaining} محاولات متبقية)<br>" .
        "❌ Invalid username or password ({$remaining} attempt(s) remaining)";
}

header('Location: index.php');
exit;