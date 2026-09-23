<?php
/*
 * auth_remember.php — shared sign-in helpers for index.php, login.php,
 * logout.php and auth.php.
 *
 *  • f1c_session_start()  starts the session with safe cookie flags
 *                          (HttpOnly, SameSite=Lax, Secure on HTTPS).
 *  • "Remember me"         a 30-day token in an HttpOnly cookie. Only a
 *                          SHA-256 of the secret is stored, it is rotated on
 *                          every use, and it only works while the account is
 *                          active (auth.php still checks that on every page).
 *  • f1c_safe_next()       the page to return to after login — only a page of
 *                          this app, never an outside address.
 *  • Login throttling      failed attempts are counted in the database per
 *                          username and per device, so clearing cookies does
 *                          not reset the limit.
 *
 * The two small tables create themselves on first use (same auto-migrate
 * pattern the app already uses), so there is no manual SQL step.
 */

const F1C_REMEMBER_COOKIE = 'f1c_remember';
const F1C_LASTUSER_COOKIE = 'f1c_last_user';
const F1C_REMEMBER_DAYS   = 30;
const F1C_MAX_FAILS_USER  = 5;    // per username, inside the window
const F1C_MAX_FAILS_IP    = 20;   // per device/IP, inside the window
const F1C_LOCK_MINUTES    = 15;

function f1c_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
}

function f1c_session_start(): void
{
    if (session_status() !== PHP_SESSION_NONE) return;
    if (!headers_sent()) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => f1c_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_start();
}

function f1c_cookie(string $name, string $value, int $expires, bool $httpOnly = true): void
{
    setcookie($name, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => f1c_https(),
        'httponly' => $httpOnly,
        'samesite' => 'Lax',
    ]);
}

/* ─── Return-to page ─── */
function f1c_safe_next(?string $next): string
{
    $next = trim((string)$next);
    // a page of this app only: "page.php" or "page.php?query" — no scheme, host, slashes or line breaks
    if ($next === '' || !preg_match('#^[A-Za-z0-9_\-]+\.php(\?[^\r\n<>"\'\\\\]*)?$#', $next)) return '';
    if (preg_match('#^(index|login|logout)\.php#i', $next)) return '';
    return $next;
}

/* ─── Tables ─── */
function f1c_auth_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS auth_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        selector CHAR(24) NOT NULL UNIQUE,
        validator_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        user_agent VARCHAR(255) NULL,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        success TINYINT(1) NOT NULL DEFAULT 0,
        attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (username, attempted_at),
        INDEX idx_ip (ip, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/* ─── Remember me ─── */
function f1c_remember_issue(PDO $pdo, int $userId): void
{
    f1c_auth_tables($pdo);
    $selector  = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $expires   = time() + F1C_REMEMBER_DAYS * 86400;
    $pdo->prepare("INSERT INTO auth_tokens (user_id, selector, validator_hash, expires_at, user_agent) VALUES (?, ?, ?, ?, ?)")
        ->execute([$userId, $selector, hash('sha256', $validator), date('Y-m-d H:i:s', $expires), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
    f1c_cookie(F1C_REMEMBER_COOKIE, $selector . ':' . $validator, $expires);
}

function f1c_remember_forget(PDO $pdo): void
{
    $raw = (string)($_COOKIE[F1C_REMEMBER_COOKIE] ?? '');
    if ($raw !== '' && strpos($raw, ':') !== false) {
        try {
            f1c_auth_tables($pdo);
            $pdo->prepare("DELETE FROM auth_tokens WHERE selector = ?")->execute([explode(':', $raw, 2)[0]]);
        } catch (Throwable $e) { error_log('remember forget failed: ' . $e->getMessage()); }
    }
    if (isset($_COOKIE[F1C_REMEMBER_COOKIE])) f1c_cookie(F1C_REMEMBER_COOKIE, '', time() - 3600);
}

/**
 * Signs the user in from a valid remember-me cookie (and rotates it).
 * Returns true when the session was restored.
 */
function f1c_remember_login(PDO $pdo): bool
{
    $raw = (string)($_COOKIE[F1C_REMEMBER_COOKIE] ?? '');
    if ($raw === '' || !preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $raw, $m)) return false;
    try {
        f1c_auth_tables($pdo);
        $st = $pdo->prepare("SELECT t.id, t.user_id, t.validator_hash, t.expires_at, u.username, u.role, u.active
                             FROM auth_tokens t JOIN users u ON u.id = t.user_id WHERE t.selector = ? LIMIT 1");
        $st->execute([$m[1]]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $ok = $row && hash_equals($row['validator_hash'], hash('sha256', $m[2]))
                   && strtotime($row['expires_at']) > time() && (int)$row['active'] === 1;
        if (!$ok) {
            if ($row) $pdo->prepare("DELETE FROM auth_tokens WHERE id = ?")->execute([$row['id']]);
            f1c_cookie(F1C_REMEMBER_COOKIE, '', time() - 3600);
            return false;
        }
        // rotate: the old secret stops working, a fresh one is issued
        $pdo->prepare("DELETE FROM auth_tokens WHERE id = ?")->execute([$row['id']]);
        f1c_remember_issue($pdo, (int)$row['user_id']);
        session_regenerate_id(true);
        $_SESSION['user_id']  = (int)$row['user_id'];
        $_SESSION['username'] = $row['username'];
        $_SESSION['role']     = $row['role'];
        $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$row['user_id']]);
        return true;
    } catch (Throwable $e) {
        error_log('remember login failed: ' . $e->getMessage());
        return false;
    }
}

/* ─── Throttling ─── */
function f1c_client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/** Seconds left on a lock for this username / device, or 0. */
function f1c_lock_left(PDO $pdo, string $username): int
{
    try {
        f1c_auth_tables($pdo);
        $win = F1C_LOCK_MINUTES;
        $left = 0;
        foreach ([['username', mb_strtolower($username), F1C_MAX_FAILS_USER], ['ip', f1c_client_ip(), F1C_MAX_FAILS_IP]] as [$col, $val, $max]) {
            if ($val === '') continue;
            // failures since the last success, inside the window
            $st = $pdo->prepare("SELECT UNIX_TIMESTAMP(attempted_at) FROM login_attempts
                                 WHERE $col = ? AND success = 0 AND attempted_at >= NOW() - INTERVAL $win MINUTE
                                   AND attempted_at > COALESCE((SELECT MAX(attempted_at) FROM login_attempts s WHERE s.$col = ? AND s.success = 1), '1970-01-01')
                                 ORDER BY attempted_at DESC LIMIT $max");
            $st->execute([$val, $val]);
            $rows = $st->fetchAll(PDO::FETCH_COLUMN);
            if (count($rows) >= $max) {
                $now  = (int)$pdo->query("SELECT UNIX_TIMESTAMP()")->fetchColumn();
                $left = max($left, (int)end($rows) + $win * 60 - $now);   // both times from the database clock
            }
        }
        return max(0, $left);
    } catch (Throwable $e) {
        error_log('login throttle check failed: ' . $e->getMessage());
        return 0;
    }
}

/** Failures left for this username before it locks. */
function f1c_fails_left(PDO $pdo, string $username): int
{
    try {
        $win = F1C_LOCK_MINUTES;
        $st = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE username = ? AND success = 0 AND attempted_at >= NOW() - INTERVAL $win MINUTE
                               AND attempted_at > COALESCE((SELECT MAX(attempted_at) FROM login_attempts s WHERE s.username = ? AND s.success = 1), '1970-01-01')");
        $u = mb_strtolower($username);
        $st->execute([$u, $u]);
        return max(0, F1C_MAX_FAILS_USER - (int)$st->fetchColumn());
    } catch (Throwable $e) { return F1C_MAX_FAILS_USER; }
}

function f1c_log_attempt(PDO $pdo, string $username, bool $success): void
{
    try {
        f1c_auth_tables($pdo);
        $pdo->prepare("INSERT INTO login_attempts (username, ip, success) VALUES (?, ?, ?)")
            ->execute([mb_substr(mb_strtolower($username), 0, 100), f1c_client_ip(), $success ? 1 : 0]);
        // keep the table small
        if (random_int(1, 50) === 1) $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 30 DAY");
    } catch (Throwable $e) { error_log('login attempt log failed: ' . $e->getMessage()); }
}
