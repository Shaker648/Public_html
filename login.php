<?php
/*
 * login.php — handles POST login form submission only.
 *
 *  • last_login is written to the DB on every successful login.
 *  • Failed attempts are counted in the database (per username and per
 *    device), so clearing cookies no longer resets the limit.
 *  • "Remember me" keeps the user signed in on that device for 30 days.
 *  • After login the user goes back to the page they had opened (e.g. a car
 *    from a QR sticker), in the language they chose.
 */

require_once __DIR__ . '/auth_remember.php';
f1c_session_start();

require 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');
$lang     = ($_POST['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
$next     = f1c_safe_next($_POST['next'] ?? '');

// back to the login page, keeping language, return page and the typed username
$back = function (array $err) use ($lang, $next, $username) {
    $_SESSION['login_err']  = $err;
    $_SESSION['login_user'] = $username;
    header('Location: index.php?lang=' . $lang . ($next !== '' ? '&next=' . urlencode($next) : ''));
    exit;
};

// ── Form token ──
if (empty($_SESSION['login_csrf']) || !hash_equals($_SESSION['login_csrf'], (string)($_POST['csrf'] ?? ''))) {
    $back(['code' => 'expired']);
}

if ($username === '' || $password === '') {
    $back(['code' => 'empty']);
}

// ── Too many failed attempts? (server-side, per username and per device) ──
$lockLeft = f1c_lock_left($pdo, $username);
if ($lockLeft > 0) {
    $back(['code' => 'locked', 'until' => time() + $lockLeft]);
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
    unset($_SESSION['login_err'], $_SESSION['login_user'], $_SESSION['login_csrf'],
          $_SESSION['login_attempts'], $_SESSION['login_lock_until']);

    // ── record the login timestamp ──
    $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
        ->execute([$user['id']]);
    f1c_log_attempt($pdo, $username, true);

    // ── stay signed in on this device ──
    if (!empty($_POST['remember'])) {
        try { f1c_remember_issue($pdo, (int)$user['id']); }
        catch (Throwable $e) { error_log('remember issue failed: ' . $e->getMessage()); }
    }
    // the login page greets this user by name next time (the username only, never the password)
    // (readable by the page so "Not you?" can clear it — it holds no secret)
    f1c_cookie(F1C_LASTUSER_COOKIE, $user['username'], time() + 90 * 86400, false);

    if ($next !== '') {
        header('Location: ' . $next . (strpos($next, 'lang=') === false ? (strpos($next, '?') === false ? '?' : '&') . 'lang=' . $lang : ''));
    } else {
        header('Location: dashboard.php?lang=' . $lang);
    }
    exit;
}

// ── Failed login ──
f1c_log_attempt($pdo, $username, false);
$lockLeft = f1c_lock_left($pdo, $username);
if ($lockLeft > 0) {
    $back(['code' => 'locked', 'until' => time() + $lockLeft]);
}
$back(['code' => 'bad', 'left' => f1c_fails_left($pdo, $username)]);
