<?php
/*
 * devices_helpers.php — which phones and computers each account is used on.
 *
 * Every browser gets a random id in a cookie (it says nothing about the person).
 * The first time an account signs in on a browser the system has not seen for
 * that account, the admins are told. On devices.php an admin sees each person's
 * devices and can sign one of them — or all of them — out.
 *
 * Needs auth_remember.php (f1c_cookie, f1c_client_ip).
 */

const F1C_DEV_COOKIE = 'f1c_dev';

function dev_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_devices (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        dev_id     CHAR(32) NOT NULL,
        user_agent VARCHAR(255) NULL,
        ip         VARCHAR(64) NULL,
        first_seen DATETIME NOT NULL,
        last_seen  DATETIME NOT NULL,
        revoked    TINYINT NOT NULL DEFAULT 0,
        UNIQUE KEY u_dev (user_id, dev_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** This browser's id (made on first use). */
function dev_id(): string
{
    $id = (string)($_COOKIE[F1C_DEV_COOKIE] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        $id = bin2hex(random_bytes(16));
        f1c_cookie(F1C_DEV_COOKIE, $id, time() + 400 * 86400);
        $_COOKIE[F1C_DEV_COOKIE] = $id;
    }
    return $id;
}

/** "iPhone · Safari" from a user-agent string. */
function dev_label(string $ua): string
{
    $os = 'جهاز غير معروف';
    foreach (['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows', 'Macintosh' => 'Mac', 'Linux' => 'Linux'] as $k => $v) if (stripos($ua, $k) !== false) { $os = $v; break; }
    $br = '';
    foreach (['Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser' => 'Samsung Internet', 'CriOS' => 'Chrome', 'FxiOS' => 'Firefox', 'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'Safari' => 'Safari'] as $k => $v) if (stripos($ua, $k) !== false) { $br = $v; break; }
    return $os . ($br !== '' ? ' · ' . $br : '');
}

/**
 * Called on every signed-in request (auth.php) and at sign-in (login.php).
 * Returns 'revoked' when an admin signed this device out, 'new' the first time
 * this account is seen on this browser, otherwise 'known'.
 *   $login  true at a real sign-in with the password: it lifts a sign-out.
 */
function dev_touch(PDO $pdo, int $uid, bool $login = false): string
{
    try {
        dev_tables($pdo);
        $id = dev_id();
        $st = $pdo->prepare("SELECT id, revoked, TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS age FROM user_devices WHERE user_id = ? AND dev_id = ? LIMIT 1");
        $st->execute([$uid, $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $ip = function_exists('f1c_client_ip') ? f1c_client_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (!$row) {
            $pdo->prepare("INSERT IGNORE INTO user_devices (user_id, dev_id, user_agent, ip, first_seen, last_seen) VALUES (?, ?, ?, ?, NOW(), NOW())")->execute([$uid, $id, $ua, $ip]);
            return 'new';
        }
        if ((int)$row['revoked']) {
            if (!$login) return 'revoked';
            $pdo->prepare("UPDATE user_devices SET revoked = 0, last_seen = NOW(), user_agent = ?, ip = ? WHERE id = ?")->execute([$ua, $ip, (int)$row['id']]);
            return 'known';
        }
        if ($login || (int)$row['age'] >= 300) $pdo->prepare("UPDATE user_devices SET last_seen = NOW(), user_agent = ?, ip = ? WHERE id = ?")->execute([$ua, $ip, (int)$row['id']]);
        return 'known';
    } catch (Throwable $e) { error_log('dev_touch: ' . $e->getMessage()); return 'known'; }
}

/**
 * Should the admins be told about this new device? Not while the system is still
 * learning the devices people already use (the first two days), and not for an
 * account's very first device.
 */
function dev_should_alert(PDO $pdo, int $uid): bool
{
    try {
        $first = $pdo->query("SELECT MIN(first_seen) FROM user_devices")->fetchColumn();
        if (!$first || strtotime((string)$first) > time() - 2 * 86400) return false;
        $st = $pdo->prepare("SELECT COUNT(*) FROM user_devices WHERE user_id = ?");
        $st->execute([$uid]);
        return (int)$st->fetchColumn() > 1;
    } catch (Throwable $e) { return false; }
}

/** Sign a device out (or every device of a person when $devRow = 0). */
function dev_revoke(PDO $pdo, int $uid, int $devRow = 0): void
{
    dev_tables($pdo);
    if ($devRow) $pdo->prepare("UPDATE user_devices SET revoked = 1 WHERE id = ? AND user_id = ?")->execute([$devRow, $uid]);
    else {
        $pdo->prepare("UPDATE user_devices SET revoked = 1 WHERE user_id = ?")->execute([$uid]);
        try { $pdo->prepare("DELETE FROM auth_tokens WHERE user_id = ?")->execute([$uid]); } catch (Throwable $e) {}   // "stay signed in" on every device
    }
}
