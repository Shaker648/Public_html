<?php
/*
 * push_helpers.php — phone notifications for First 1 Car (Web Push).
 *
 * Works on Android (Chrome / Edge / Samsung Internet, in the browser or as an
 * installed app) and on iPhone / iPad (iOS 16.4+, once the site is added to
 * the Home Screen). No outside service or monthly fee: the server signs and
 * encrypts every message itself (VAPID + RFC 8291 "aes128gcm") and hands it to
 * Apple's / Google's push service, which delivers it to the phone.
 *
 *   notify_event($pdo, 'car_sold', [...])   call after a successful save.
 *
 * Messages are sent AFTER the page has answered (the user never waits), and a
 * failure is only logged — a notification can never break a sale or transfer.
 * Who receives what is set by the admin on notifications_admin.php.
 *
 * Tables create themselves on first use (same pattern the app already uses):
 *   push_subscriptions  one row per phone / browser that enabled notifications
 *   notify_log          every event that was announced
 *   notify_inbox        who it was for (each user's history on notifications.php)
 *   settings            VAPID keys, the rules and options (shared table)
 */

/* ════════════════════════ events ════════════════════════ */
function notify_events(): array
{
    // key => [ar, en, icon, default roles, on by default, "the person concerned" (null = not used), group]
    //   the person concerned = whoever the event is about: the one who reserved the car,
    //   the staff of the branch a car is moving to, the salesperson of a bank request…
    return [
        'car_added'        => ['إضافة سيارة جديدة',          'New car added',              '🚗', ['admin'], true, null, 'live'],
        'shipment_received'=> ['استلام شحنة',                 'Shipment received',          '📦', ['admin'], true, null, 'live'],
        'car_transferred'  => ['نقل سيارة بين الفروع',        'Car transferred',            '🔄', ['admin'], true, null, 'live'],
        'transfer_incoming'=> ['عربية جاية لفرعك (زر «استلمت»)', 'Car on its way to your branch', '🚚', [], true, true, 'live'],
        'transfer_received'=> ['تأكيد استلام عربية منقولة',   'Transferred car received',   '📥', ['admin'], true, true, 'live'],
        'car_reserved'     => ['حجز سيارة',                   'Car reserved',               '🔒', ['admin'], true, null, 'live'],
        'reserve_cancelled'=> ['إلغاء حجز',                   'Reservation cancelled',      '↩️', ['admin'], true, null, 'live'],
        'price_reserved'   => ['تغيّر سعر عربية محجوزة',       'Price changed on a reserved car', '🏷️', [], true, true, 'live'],
        'car_sold'         => ['بيع سيارة',                   'Car sold',                   '💰', ['admin'], true, null, 'live'],
        'sale_celebrate'   => ['احتفال بالبيع (بدون اسم البائع)', 'Sale celebration (no seller name)', '🎉', ['admin', 'manager', 'sales'], true, null, 'live'],
        'last_car'         => ['آخر عربية من موديل / لون',     'Last car of a model / colour', '⚠️', ['admin', 'manager'], true, null, 'live'],
        'amana_out'        => ['خروج سيارة أمانة',            'Car out on consignment',     '🔶', ['admin'], true, null, 'live'],
        'amana_closed'     => ['إغلاق أمانة كبيع',            'Consignment closed as sale', '✅', ['admin'], true, null, 'live'],
        'amana_returned'   => ['رجوع سيارة من الأمانة',        'Consignment returned',       '🏠', ['admin'], true, null, 'live'],
        'sale_returned'    => ['إرجاع سيارة مباعة للمخزون',    'Sold car returned to stock', '⏪', ['admin'], true, null, 'live'],
        'car_edited'       => ['تعديل بيانات سيارة',           'Car details edited',         '✏️', ['admin'], true, null, 'live'],
        'price_changed'    => ['تغيير سعر',                    'Price changed',              '📈', ['admin'], true, null, 'live'],
        'bank_decision'    => ['رد البنك على طلب تقسيط',       'Bank decision on installment', '🏦', [], true, true, 'live'],
        // attendance (bashma) — who receives them is chosen on notifications_admin.php
        'att_in'           => ['تسجيل حضور',                   'Clock in',                   '🟢', ['admin'], true, null, 'live'],
        'att_out'          => ['تسجيل انصراف',                 'Clock out',                  '🔵', ['admin'], true, null, 'live'],
        // automatic reminders (notify_cron.php)
        'reserve_old'      => ['حجز قديم',                     'Old reservation',            '⏰', ['admin'], true, true, 'remind'],
        'stock_aged'       => ['عربيات بقالها كتير في المخزون (أسبوعياً)', 'Cars in stock too long (weekly)', '🐢', ['admin', 'manager'], true, null, 'remind'],
        'transfer_unconfirmed' => ['نقل ما اتأكدش استلامه خلال 24 ساعة', 'Transfer not confirmed within 24 h', '⏳', ['admin'], true, null, 'remind'],
        'amana_long'       => ['أمانة بره من مدة طويلة',        'Consignment out too long',   '🔶', ['admin'], true, true, 'remind'],
        'bank_waiting'     => ['بنك ما ردّش على طلب تقسيط',     'Bank has not replied',       '⌛', ['admin', 'manager'], true, null, 'remind'],
        'clockout_forgot'  => ['نسي يسجّل انصراف (للموظف نفسه)', 'Forgot to clock out (to the employee)', '🌙', [], true, true, 'remind'],
        // security
        'login_failed'     => ['محاولات دخول خاطئة',           'Failed sign-in attempts',    '🔐', ['admin'], true, null, 'security'],
        'sensitive_change' => ['تغيير حساس (شاسيه / عربية مباعة / تخفيض سعر)', 'Sensitive change (chassis / sold car / price cut)', '🚨', ['admin'], true, null, 'security'],
    ];
}

/** Events that appear in the dashboard's live activity (never attendance or security). */
function notify_activity_events(): array
{
    return ['car_added', 'shipment_received', 'car_transferred', 'transfer_received', 'car_reserved', 'reserve_cancelled',
            'car_sold', 'amana_out', 'amana_closed', 'amana_returned', 'sale_returned', 'car_edited', 'price_changed'];
}

/** Settings for the automatic reminders (notifications_admin.php). */
function notify_smart(PDO $pdo): array
{
    $o = json_decode(push_setting($pdo, 'notify_smart', ''), true) ?: [];
    $int = fn($k, $d, $lo, $hi) => max($lo, min($hi, (int)($o[$k] ?? $d)));
    return [
        'reserve_days' => $int('reserve_days', 5, 1, 60),     // remind after N days, then every N days
        'aged_days'    => $int('aged_days', 90, 15, 365),     // "in stock for more than N days"
        'aged_dow'     => $int('aged_dow', 6, 0, 6),          // weekly on this day (0 = Sunday … 6 = Saturday)
        'amana_days'   => $int('amana_days', 14, 1, 120),
        'bank_days'    => $int('bank_days', 3, 1, 30),
        'clockout_at'  => preg_match('/^\d{2}:\d{2}$/', $o['clockout_at'] ?? '') ? $o['clockout_at'] : '23:00',
        'fail_count'   => $int('fail_count', 5, 3, 20),
    ];
}

/** Each person's home branch, set by the admin: [userId => branch name]. */
function notify_user_branches(PDO $pdo): array
{
    $m = json_decode(push_setting($pdo, 'user_branches', ''), true) ?: [];
    $out = [];
    foreach ($m as $id => $b) if ((int)$id > 0 && is_string($b) && $b !== '') $out[(int)$id] = $b;
    return $out;
}

/**
 * Who works at a branch right now: people who clocked in there today and have
 * not left yet, plus the people the admin assigned to it. Returns usernames.
 */
function notify_branch_staff(PDO $pdo, string $branch): array
{
    $names = [];
    try {
        $st = $pdo->prepare("SELECT DISTINCT u.username FROM attendance_logs a JOIN users u ON u.id = a.user_id
                             WHERE u.active = 1 AND a.branch_name = ? AND a.clock_in >= CURDATE() AND a.clock_out IS NULL");
        $st->execute([$branch]);
        $names = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {}   // no attendance table yet
    $ids = array_keys(array_filter(notify_user_branches($pdo), fn($b) => $b === $branch));
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $names = array_merge($names, $pdo->query("SELECT username FROM users WHERE active = 1 AND id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
    }
    return array_values(array_unique(array_map('strval', $names)));
}

/** True the first time a key is seen — so a reminder is sent only once. */
function notify_once(PDO $pdo, string $key): bool
{
    push_tables($pdo);
    $st = $pdo->prepare("INSERT IGNORE INTO notify_once (k, at) VALUES (?, NOW())");
    $st->execute([mb_substr($key, 0, 120)]);
    return $st->rowCount() > 0;
}

/* ════════════════════════ storage ════════════════════════ */
function push_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key   VARCHAR(64) PRIMARY KEY,
        setting_value TEXT,
        updated_by    VARCHAR(64),
        updated_at    DATETIME
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        endpoint TEXT NOT NULL,
        endpoint_hash CHAR(64) NOT NULL UNIQUE,
        p256dh VARCHAR(255) NOT NULL,
        auth VARCHAR(64) NOT NULL,
        device VARCHAR(120) NULL,
        user_agent VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_ok_at DATETIME NULL,
        last_error VARCHAR(255) NULL,
        fail_count INT NOT NULL DEFAULT 0,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS notify_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event VARCHAR(40) NOT NULL,
        title VARCHAR(200) NOT NULL,
        body TEXT NULL,
        url VARCHAR(255) NULL,
        actor VARCHAR(100) NULL,
        recipients INT NOT NULL DEFAULT 0,
        devices INT NOT NULL DEFAULT 0,
        delivered INT NOT NULL DEFAULT 0,
        failed INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_time (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS notify_inbox (
        id INT AUTO_INCREMENT PRIMARY KEY,
        log_id INT NOT NULL,
        user_id INT NOT NULL,
        seen_at DATETIME NULL,
        read_at DATETIME NULL,
        INDEX idx_user (user_id, log_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // older installs: add 'shown in the system' / 'message read' — what was sent before counts as already seen
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM notify_inbox")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('seen_at', $cols, true)) {
            $pdo->exec("ALTER TABLE notify_inbox ADD seen_at DATETIME NULL, ADD read_at DATETIME NULL");
            $pdo->exec("UPDATE notify_inbox SET seen_at = NOW(), read_at = NOW()");
        }
    } catch (Throwable $e) { error_log('notify_inbox upgrade: ' . $e->getMessage()); }
    // "تمام" confirmations + a private link for the buttons inside a phone notification
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM notify_inbox")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('ack_at', $cols, true)) $pdo->exec("ALTER TABLE notify_inbox ADD ack_at DATETIME NULL, ADD tok CHAR(24) NULL, ADD INDEX idx_tok (tok)");
        $cols = $pdo->query("SHOW COLUMNS FROM notify_log")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('need_ack', $cols, true)) $pdo->exec("ALTER TABLE notify_log ADD need_ack TINYINT NOT NULL DEFAULT 0, ADD ref VARCHAR(255) NULL");
    } catch (Throwable $e) { error_log('notify upgrade (ack): ' . $e->getMessage()); }
    $pdo->exec("CREATE TABLE IF NOT EXISTS notify_once (
        k VARCHAR(120) PRIMARY KEY,
        at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS notify_scheduled (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        body TEXT NOT NULL,
        user_ids TEXT NOT NULL,
        need_ack TINYINT NOT NULL DEFAULT 0,
        send_at DATETIME NOT NULL,
        created_by VARCHAR(100) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sent_log_id INT NULL,
        cancelled TINYINT NOT NULL DEFAULT 0,
        INDEX idx_due (sent_log_id, cancelled, send_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event VARCHAR(40) NOT NULL,
        title VARCHAR(200) NOT NULL,
        actor VARCHAR(100) NULL,
        url VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_time (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS transfer_receipts (
        movement_id INT PRIMARY KEY,
        received_by VARCHAR(100) NOT NULL,
        received_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function push_setting(PDO $pdo, string $key, string $default = ''): string
{
    try {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? $default : (string)$v;
    } catch (Throwable $e) { return $default; }
}

function push_setting_set(PDO $pdo, string $key, string $value, string $by = ''): void
{
    push_tables($pdo);
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value, updated_by, updated_at) VALUES (?, ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()")
        ->execute([$key, $value, $by]);
}

/* who gets what + options (admin page) */
function notify_rules(PDO $pdo): array
{
    $saved = json_decode(push_setting($pdo, 'notify_rules', ''), true);
    $out = [];
    foreach (notify_events() as $ev => $meta) {
        $r = is_array($saved[$ev] ?? null) ? $saved[$ev] : null;
        $out[$ev] = [
            'on'    => $r ? !empty($r['on']) : ($meta[4] ?? true),
            'roles' => $r ? array_values(array_intersect((array)($r['roles'] ?? []), ['admin', 'manager', 'sales'])) : $meta[3],
            'plus'  => $r ? array_values(array_map('intval', (array)($r['plus'] ?? []))) : [],   // extra users
            'minus' => $r ? array_values(array_map('intval', (array)($r['minus'] ?? []))) : [],  // excluded users
            // the person concerned (the one who reserved, the branch staff…) — null when the event has none
            'owner' => ($meta[5] ?? null) === null ? null : ($r && array_key_exists('owner', $r) ? !empty($r['owner']) : (bool)$meta[5]),
        ];
    }
    return $out;
}

function notify_options(PDO $pdo): array
{
    $o = json_decode(push_setting($pdo, 'notify_options', ''), true) ?: [];
    return [
        'self'       => !empty($o['self']),                       // also notify the person who did it
        'quiet'      => !empty($o['quiet']),
        'quiet_from' => preg_match('/^\d{2}:\d{2}$/', $o['quiet_from'] ?? '') ? $o['quiet_from'] : '23:00',
        'quiet_to'   => preg_match('/^\d{2}:\d{2}$/', $o['quiet_to'] ?? '')   ? $o['quiet_to']   : '08:00',
        'lang'       => ($o['lang'] ?? 'ar') === 'en' ? 'en' : 'ar',
    ];
}

/* ════════════════════════ base64url / keys ════════════════════════ */
function b64u_enc(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function b64u_dec(string $s): string
{
    $s = strtr(trim($s), '-_', '+/');
    return (string)base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}

/** The server's VAPID key pair — created once, kept in settings. */
function push_vapid(PDO $pdo): array
{
    static $cache = null;
    if ($cache) return $cache;
    push_tables($pdo);
    $pem = push_setting($pdo, 'vapid_private_pem');
    $pub = push_setting($pdo, 'vapid_public');
    if ($pem === '' || $pub === '') {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (!$key) throw new RuntimeException('Cannot create VAPID key (OpenSSL EC not available)');
        openssl_pkey_export($key, $pem);
        $d   = openssl_pkey_get_details($key)['ec'];
        $pub = b64u_enc("\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT));
        push_setting_set($pdo, 'vapid_private_pem', $pem, 'system');
        push_setting_set($pdo, 'vapid_public', $pub, 'system');
    }
    return $cache = ['pem' => $pem, 'pub' => $pub];
}

/** An uncompressed P-256 point (65 bytes) as a PEM public key. */
function push_point_to_pem(string $point): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** DER ECDSA signature → the raw 64-byte r||s that JWT ES256 needs. */
function push_der_to_raw(string $der): string
{
    $pos = 2;
    if (ord($der[1]) & 0x80) $pos += ord($der[1]) & 0x7f;
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = substr($der, $pos + 2, $len);
        $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $out;
}

function push_site_subject(): string
{
    $host = preg_replace('/[^A-Za-z0-9.\-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return 'mailto:admin@' . ($host !== '' ? $host : 'localhost');
}

/** VAPID "Authorization" header for one push service. */
function push_vapid_header(PDO $pdo, string $endpoint): string
{
    $v   = push_vapid($pdo);
    $p   = parse_url($endpoint);
    $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $jwtH = b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $jwtC = b64u_enc(json_encode(['aud' => $aud, 'exp' => time() + 3600 * 12, 'sub' => push_site_subject()], JSON_UNESCAPED_SLASHES));
    $sig = '';
    if (!openssl_sign($jwtH . '.' . $jwtC, $sig, openssl_pkey_get_private($v['pem']), OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('VAPID signing failed');
    }
    return 'vapid t=' . $jwtH . '.' . $jwtC . '.' . b64u_enc(push_der_to_raw($sig)) . ', k=' . $v['pub'];
}

/* ════════════════════════ encryption (RFC 8291, aes128gcm) ════════════════════════ */
function push_encrypt(string $payload, string $uaPublicB64, string $authB64): string
{
    $uaPublic = b64u_dec($uaPublicB64);
    $authSecret = b64u_dec($authB64);
    if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($authSecret) < 16) {
        throw new InvalidArgumentException('Bad subscription keys');
    }
    // a fresh key pair for this one message
    $local = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $d = openssl_pkey_get_details($local)['ec'];
    $asPublic = "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
    $shared = openssl_pkey_derive(openssl_pkey_get_public(push_point_to_pem($uaPublic)), $local, 32);
    if ($shared === false || strlen($shared) !== 32) throw new RuntimeException('ECDH failed');

    $prkKey = hash_hmac('sha256', $shared, $authSecret, true);
    $ikm    = hash_hmac('sha256', "WebPush: info\0" . $uaPublic . $asPublic . "\x01", $prkKey, true);
    $salt   = random_bytes(16);
    $prk    = hash_hmac('sha256', $ikm, $salt, true);
    $cek    = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
    $nonce  = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);

    $tag = '';
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cipher === false) throw new RuntimeException('Encryption failed');
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
}

/* ════════════════════════ sending ════════════════════════ */
/**
 * Sends one payload to many subscriptions in parallel.
 * Returns [subscriptionId => [httpCode, error]]. Dead subscriptions are removed.
 */
function push_send_many(PDO $pdo, array $subs, array $payload, array $perUser = []): array
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $mh = curl_multi_init();
    $hs = [];
    $res = [];
    foreach ($subs as $s) {
        try {
            // each person can get their own version (their unread count, their own buttons, a grouped title)
            $uj = isset($perUser[(int)($s['user_id'] ?? 0)])
                ? json_encode(array_merge($payload, $perUser[(int)$s['user_id']]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $json;
            $body = push_encrypt($uj, $s['p256dh'], $s['auth']);
            $ch = curl_init($s['endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 12,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/octet-stream',
                    'Content-Encoding: aes128gcm',
                    'TTL: 86400',
                    'Urgency: high',
                    'Authorization: ' . push_vapid_header($pdo, $s['endpoint']),
                ],
            ]);
            curl_multi_add_handle($mh, $ch);
            $hs[(int)$s['id']] = $ch;
        } catch (Throwable $e) {
            $res[(int)$s['id']] = [0, $e->getMessage()];
        }
    }
    do {
        $st = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1.0);
    } while ($running && $st === CURLM_OK);
    foreach ($hs as $id => $ch) {
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = ($code >= 200 && $code < 300) ? '' : ((trim(substr((string)curl_multi_getcontent($ch), 0, 180))) ?: curl_error($ch));
        $res[$id] = [$code, $err];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    // book-keeping: success time, errors, and drop phones that unsubscribed
    try {
        $ok   = $pdo->prepare("UPDATE push_subscriptions SET last_ok_at = NOW(), fail_count = 0, last_error = NULL WHERE id = ?");
        $bad  = $pdo->prepare("UPDATE push_subscriptions SET fail_count = fail_count + 1, last_error = ? WHERE id = ?");
        $gone = $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?");
        foreach ($res as $id => [$code, $err]) {
            if ($code >= 200 && $code < 300) $ok->execute([$id]);
            elseif ($code === 404 || $code === 410) $gone->execute([$id]);
            else $bad->execute([substr($code . ' ' . $err, 0, 250), $id]);
        }
        $pdo->exec("DELETE FROM push_subscriptions WHERE fail_count >= 20");
    } catch (Throwable $e) { error_log('push bookkeeping failed: ' . $e->getMessage()); }
    return $res;
}

/* ════════════════════════ building a message ════════════════════════ */
function push_branch_label(PDO $pdo, string $name, string $lang): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        try { foreach ($pdo->query("SELECT name, name_ar, name_en FROM branches") as $b) $map[$b['name']] = $b; } catch (Throwable $e) {}
    }
    $b = $map[$name] ?? null;
    return $b ? (($lang === 'ar' ? $b['name_ar'] : $b['name_en']) ?: $name) : $name;
}

function push_color_label(PDO $pdo, string $en, string $lang): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        try { foreach ($pdo->query("SELECT color_en, color_ar FROM colors") as $c) $map[mb_strtolower($c['color_en'])] = $c['color_ar']; } catch (Throwable $e) {}
    }
    return $lang === 'ar' ? ($map[mb_strtolower($en)] ?? $en) : $en;
}

/** Title / body / link for an event, in the chosen language. */
function notify_message(PDO $pdo, string $event, array $d, string $lang): array
{
    $ar  = $lang === 'ar';
    $ev  = notify_events()[$event] ?? ['', '', '🔔'];
    $car = $d['car'] ?? [];
    $name = trim(($car['brand'] ?? '') . ' ' . ($car['model'] ?? '') . ' ' . ($car['car_year'] ?? ''));
    $trim = trim((string)($car['trim_name'] ?? ''));
    $col  = isset($car['color'])  ? push_color_label($pdo, (string)$car['color'], $lang) : '';
    $br   = isset($car['branch']) ? push_branch_label($pdo, (string)$car['branch'], $lang) : '';
    $by   = (string)($d['actor'] ?? ($_SESSION['username'] ?? ''));
    $arrow = $ar ? ' ← ' : ' → ';
    $line  = trim($name . ($trim !== '' ? ' ' . $trim : ''));
    $meta  = implode(' · ', array_filter([$col, $br !== '' ? '📍 ' . $br : '', !empty($car['chassis']) ? '🔑 ' . $car['chassis'] : '']));
    $url   = !empty($car['id']) ? 'vehicle_timeline.php?id=' . (int)$car['id'] . '&lang=' . $lang : 'dashboard.php?lang=' . $lang;
    $body  = [];

    switch ($event) {
        case 'car_added':
            $title = $ev[2] . ' ' . ($ar ? 'سيارة جديدة: ' : 'New car: ') . $line;
            $body[] = $meta;
            break;
        case 'shipment_received':
            $n = (int)($d['count'] ?? 1);
            $title = $ev[2] . ' ' . ($ar ? 'استلام ' . $n . ' ' . ($n === 1 ? 'سيارة' : ($n === 2 ? 'سيارتين' : ($n <= 10 ? 'سيارات' : 'سيارة'))) . ': ' : 'Received ' . $n . ' × ') . $line;
            $body[] = ($br !== '' ? '📍 ' . $br : '') . (!empty($d['colors']) ? ' · ' . implode('، ', array_map(fn($c) => push_color_label($pdo, $c, $lang), $d['colors'])) : '');
            $url = 'dashboard.php?lang=' . $lang;
            break;
        case 'car_transferred':
            $n = (int)($d['count'] ?? 1);
            $to = push_branch_label($pdo, (string)($d['to'] ?? ''), $lang);
            $from = push_branch_label($pdo, (string)($d['from'] ?? ''), $lang);
            if ($n > 1) {
                $title = $ev[2] . ' ' . ($ar ? 'نقل ' . $n . ' سيارات إلى ' : 'Moved ' . $n . ' cars to ') . $to;
                $body[] = implode(' · ', array_slice((array)($d['names'] ?? []), 0, 4));
                $url = 'dashboard.php?lang=' . $lang;
            } else {
                $title = $ev[2] . ' ' . ($ar ? 'نقل: ' : 'Moved: ') . $line;
                $body[] = '📍 ' . $from . $arrow . $to . ($col !== '' ? ' · ' . $col : '') . (!empty($car['chassis']) ? ' · 🔑 ' . $car['chassis'] : '');
            }
            break;
        case 'car_reserved':
            $title = $ev[2] . ' ' . ($ar ? 'حجز: ' : 'Reserved: ') . $line;
            $body[] = $meta;
            break;
        case 'reserve_cancelled':
            $title = $ev[2] . ' ' . ($ar ? 'إلغاء حجز: ' : 'Reservation cancelled: ') . $line;
            $body[] = $meta;
            break;
        case 'car_sold':
        case 'amana_closed':
            $title = $ev[2] . ' ' . ($event === 'amana_closed' ? ($ar ? 'بيع سيارة أمانة: ' : 'Consignment sold: ') : ($ar ? 'تم البيع: ' : 'Sold: ')) . $line;
            $body[] = $meta;
            $who = !empty($d['dealer']) ? '🤝 ' . $d['dealer'] : (!empty($d['customer']) ? '👤 ' . $d['customer'] : '');
            $sm  = !empty($d['salesman']) ? '🧑‍💼 ' . $d['salesman'] : '';
            if ($who !== '' || $sm !== '') $body[] = trim($who . ($who !== '' && $sm !== '' ? ' · ' : '') . $sm);
            if (!empty($d['price'])) $body[] = '💰 ' . $d['price'] . ($ar ? ' جنيه' : ' EGP');
            break;
        case 'amana_out':
            $title = $ev[2] . ' ' . ($ar ? 'خروج أمانة: ' : 'Out on consignment: ') . $line;
            $body[] = $meta;
            if (!empty($d['dealer'])) $body[] = '🤝 ' . $d['dealer'] . (!empty($d['salesman']) ? ' · 🧑‍💼 ' . $d['salesman'] : '');
            break;
        case 'amana_returned':
            $title = $ev[2] . ' ' . ($ar ? 'رجوع من الأمانة: ' : 'Back from consignment: ') . $line;
            $body[] = ($ar ? 'رجعت إلى ' : 'Returned to ') . push_branch_label($pdo, (string)($d['to'] ?? ($car['branch'] ?? '')), $lang) . ($col !== '' ? ' · ' . $col : '');
            break;
        case 'sale_returned':
            $title = $ev[2] . ' ' . ($ar ? 'إرجاع سيارة مباعة: ' : 'Sale returned: ') . $line;
            $body[] = $meta;
            break;
        case 'car_edited':
            $title = $ev[2] . ' ' . ($ar ? 'تعديل: ' : 'Edited: ') . $line;
            $lbl = $ar ? ['brand' => 'الماركة', 'model' => 'الموديل', 'car_year' => 'السنة', 'trim_name' => 'الفئة', 'color' => 'اللون', 'branch' => 'الفرع', 'notes' => 'ملاحظات', 'chassis' => 'الشاسيه']
                       : ['brand' => 'Brand', 'model' => 'Model', 'car_year' => 'Year', 'trim_name' => 'Trim', 'color' => 'Colour', 'branch' => 'Branch', 'notes' => 'Notes', 'chassis' => 'Chassis'];
            foreach (array_slice((array)($d['changes'] ?? []), 0, 3, true) as $f => [$o, $n]) {
                if ($f === 'color')  { $o = push_color_label($pdo, (string)$o, $lang);  $n = push_color_label($pdo, (string)$n, $lang); }
                if ($f === 'branch') { $o = push_branch_label($pdo, (string)$o, $lang); $n = push_branch_label($pdo, (string)$n, $lang); }
                $body[] = ($lbl[$f] ?? $f) . ': ' . mb_substr((string)($o !== '' ? $o : '—'), 0, 30) . $arrow . mb_substr((string)($n !== '' ? $n : '—'), 0, 30);
            }
            break;
        case 'price_changed':
            $title = $ev[2] . ' ' . ($ar ? 'تغيير سعر: ' : 'Price change: ') . trim(($d['brand'] ?? '') . ' ' . ($d['model'] ?? '') . ' ' . ($d['trim'] ?? '') . ' ' . ($d['year'] ?? ''));
            if (($d['type'] ?? '') === 'create' && ($d['new'] ?? '') !== '') $body[] = ($ar ? 'سعر جديد: ' : 'New price: ') . number_format((float)$d['new']);
            elseif (($d['old'] ?? '') !== '' && ($d['new'] ?? '') !== '') $body[] = ($ar ? 'الرسمي: ' : 'Official: ') . number_format((float)$d['old']) . $arrow . number_format((float)$d['new']);
            if (!empty($d['customer'])) $body[] = '🏷️ ' . $d['customer'];
            $url = 'prices.php?lang=' . $lang . '&search=' . urlencode((string)($d['model'] ?? ''));
            break;
        case 'att_in':
        case 'att_out':
            $who  = (string)($d['actor'] ?? '');
            $tt   = !empty($d['time']) ? strtotime((string)$d['time']) : false;
            $at   = $tt ? date('h:i', $tt) . ($ar ? (date('A', $tt) === 'AM' ? ' ص' : ' م') : ' ' . date('A', $tt)) : '';
            $abr  = push_branch_label($pdo, (string)($d['branch'] ?? ''), $lang);
            $url  = 'attendance_admin.php?lang=' . $lang;
            $title = $ev[2] . ' ' . $who . ($event === 'att_in' ? ($ar ? ' سجّل حضور' : ' clocked in') : ($ar ? ' سجّل انصراف' : ' clocked out'));
            $body[] = '🕐 ' . $at . ($abr !== '' ? ' · 📍 ' . $abr : '')
                    . ($event === 'att_out' && isset($d['secs']) ? ' · ⏱️ ' . sprintf($ar ? '%dس %02dد' : '%dh %02dm', intdiv((int)$d['secs'], 3600), intdiv((int)$d['secs'] % 3600, 60)) : '');
            if (!empty($d['far'])) $body[] = '🛑 ' . ($ar ? 'على بعد ' . (int)$d['dist'] . ' متر من الفرع' : (int)$d['dist'] . ' m away from the branch');
            break;
        case 'transfer_incoming':
            $n  = (int)($d['count'] ?? 1);
            $to = push_branch_label($pdo, (string)($d['to'] ?? ''), $lang);
            $from = push_branch_label($pdo, (string)($d['from'] ?? ''), $lang);
            if ($n > 1) {
                $title = $ev[2] . ' ' . ($ar ? $n . ' عربيات جايين لفرع ' . $to : $n . ' cars on their way to ' . $to);
                $body[] = implode(' · ', array_slice((array)($d['names'] ?? []), 0, 4));
            } else {
                $title = $ev[2] . ' ' . ($ar ? 'عربية جاية لفرعك: ' : 'Car on its way to you: ') . $line;
                $body[] = ($from !== '' ? ($ar ? 'من ' : 'from ') . $from . ' · ' : '') . ($col !== '' ? $col : '') . (!empty($car['chassis']) ? ' · 🔑 ' . $car['chassis'] : '');
            }
            $body[] = $ar ? '👇 اضغط «استلمت» أول ما توصل' : '👇 Tap "Received" as soon as it arrives';
            $url = 'transfer_receive.php?lang=' . $lang;
            break;
        case 'transfer_received':
            $n  = (int)($d['count'] ?? 1);
            $to = push_branch_label($pdo, (string)($d['to'] ?? ''), $lang);
            $title = $ev[2] . ' ' . $by . ($ar ? ' استلم ' : ' received ') . ($n > 1 ? $n . ($ar ? ' عربيات' : ' cars') : $line) . ($to !== '' ? ($ar ? ' في ' : ' at ') . $to : '');
            if ($n > 1) $body[] = implode(' · ', array_slice((array)($d['names'] ?? []), 0, 4));
            elseif ($col !== '' || !empty($car['chassis'])) $body[] = trim($col . (!empty($car['chassis']) ? ' · 🔑 ' . $car['chassis'] : ''), ' ·');
            break;
        case 'price_reserved':
            $title = $ev[2] . ' ' . ($ar ? 'سعر العربية اللي حجزتها اتغيّر: ' : 'The car you reserved changed price: ') . trim(($d['brand'] ?? '') . ' ' . ($d['model'] ?? '') . ' ' . ($d['trim'] ?? '') . ' ' . ($d['year'] ?? ''));
            if (($d['old'] ?? '') !== '' && ($d['new'] ?? '') !== '') $body[] = ($ar ? 'الرسمي: ' : 'Official: ') . number_format((float)$d['old']) . $arrow . number_format((float)$d['new']);
            $body[] = $ar ? '📞 بلّغ العميل قبل ما يعرف من حد تاني' : '📞 Tell the customer before they hear it elsewhere';
            $url = 'prices.php?lang=' . $lang . '&search=' . urlencode((string)($d['model'] ?? ''));
            break;
        case 'sale_celebrate':
            $title = $ev[2] . ' ' . ($ar ? 'مبروك يا فريق! اتباعت ' : 'Well done, team! Sold: ') . $line;
            $body[] = trim($col . ($br !== '' ? ' · 📍 ' . $br : ''), ' ·');
            break;
        case 'last_car':
            $title = ($d['kind'] ?? '') === 'model'
                ? $ev[2] . ' ' . trim(($car['brand'] ?? '') . ' ' . ($car['model'] ?? '')) . ($ar ? ' خلصت من المخزون' : ' is out of stock')
                : $ev[2] . ' ' . ($ar ? 'آخر ' . trim(($car['brand'] ?? '') . ' ' . ($car['model'] ?? '')) . ' ' . $col . ' اتباعت' : 'The last ' . $col . ' ' . trim(($car['brand'] ?? '') . ' ' . ($car['model'] ?? '')) . ' is gone');
            $body[] = ($d['kind'] ?? '') === 'model'
                ? ($ar ? 'مفيش ولا عربية من الموديل ده في المخزون دلوقتي' : 'No car of this model left in stock')
                : ($ar ? 'مفيش غيرها باللون ده — فاضل ' . (int)($d['left'] ?? 0) . ' بألوان تانية' : 'None left in this colour — ' . (int)($d['left'] ?? 0) . ' in other colours');
            $url = 'prices.php?lang=' . $lang . '&search=' . urlencode((string)($car['model'] ?? ''));
            break;
        case 'bank_decision':
            $okB = ($d['decision'] ?? '') === 'approved';
            $title = ($okB ? '✅ ' : '❌ ') . ($d['bank'] ?? '') . ($okB ? ($ar ? ' وافق على طلب ' : ' approved ') : ($ar ? ' رفض طلب ' : ' rejected ')) . ($d['customer'] ?? '');
            $body[] = trim((string)($d['car'] ?? ''));
            if (!empty($d['note'])) $body[] = '📝 ' . mb_substr((string)$d['note'], 0, 80);
            $url = 'installments.php?lang=' . $lang;
            break;
        case 'reserve_old':
            $title = $ev[2] . ' ' . ($ar ? 'حجز ' . $line . ' بقاله ' . (int)$d['days'] . ' يوم' : $line . ' reserved for ' . (int)$d['days'] . ' days');
            $body[] = ($ar ? 'حجزها ' : 'Reserved by ') . ($d['owner'] ?? '') . ($col !== '' ? ' · ' . $col : '') . ($br !== '' ? ' · 📍 ' . $br : '');
            $body[] = $ar ? 'كمّل البيع أو الغي الحجز' : 'Finish the sale or release it';
            break;
        case 'stock_aged':
            $title = $ev[2] . ' ' . ($ar ? (int)$d['count'] . ' عربية بقالها أكتر من ' . (int)$d['days'] . ' يوم في المخزون' : (int)$d['count'] . ' cars in stock for over ' . (int)$d['days'] . ' days');
            $body[] = implode("\n", array_slice((array)($d['names'] ?? []), 0, 4));
            $url = 'dashboard.php?lang=' . $lang . '&sort=old';
            break;
        case 'transfer_unconfirmed':
            $title = $ev[2] . ' ' . ($ar ? 'محدش أكّد استلام: ' : 'Nobody confirmed: ') . $line;
            $body[] = ($ar ? 'منقولة إلى ' : 'Moved to ') . push_branch_label($pdo, (string)($d['to'] ?? ''), $lang) . ' · ' . ($ar ? 'من ' . (int)$d['hours'] . ' ساعة' : (int)$d['hours'] . ' h ago') . (!empty($d['mover']) ? ' · ' . ($ar ? 'نقلها ' : 'moved by ') . $d['mover'] : '');
            $url = 'transfer_receive.php?lang=' . $lang . '&all=1';
            break;
        case 'amana_long':
            $title = $ev[2] . ' ' . ($ar ? 'أمانة بقالها ' . (int)$d['days'] . ' يوم بره: ' : 'Out on consignment for ' . (int)$d['days'] . ' days: ') . $line;
            $body[] = trim((!empty($d['dealer']) ? '🤝 ' . $d['dealer'] : '') . (!empty($d['salesman']) ? ' · 🧑‍💼 ' . $d['salesman'] : ''), ' ·');
            $body[] = $ar ? 'اقفلها كبيع أو رجّعها' : 'Close it as a sale or bring it back';
            break;
        case 'bank_waiting':
            $title = $ev[2] . ' ' . ($ar ? (int)$d['count'] . ' طلب تقسيط مستني رد البنك من أكتر من ' . (int)$d['days'] . ' أيام' : (int)$d['count'] . ' installment requests waiting over ' . (int)$d['days'] . ' days');
            $body[] = implode("\n", array_slice((array)($d['names'] ?? []), 0, 4));
            $url = 'installments.php?lang=' . $lang;
            break;
        case 'clockout_forgot':
            $title = $ev[2] . ' ' . ($ar ? 'إنت لسه مسجّل حضور' : "You're still clocked in");
            $body[] = $ar ? 'لو خلصت شغلك سجّل انصرافك دلوقتي 👋' : 'If you have finished, clock out now 👋';
            $url = 'attendance.php?lang=' . $lang;
            break;
        case 'login_failed':
            $title = $ev[2] . ' ' . ($ar ? (int)$d['count'] . ' محاولات دخول غلط على حساب ' : (int)$d['count'] . ' wrong passwords for ') . ($d['username'] ?? '');
            $body[] = ($ar ? 'الحساب اتقفل مؤقتاً' : 'The account is locked for now') . (!empty($d['ip']) ? ' · 🌐 ' . $d['ip'] : '');
            $url = 'users.php?lang=' . $lang;
            break;
        case 'sensitive_change':
            $title = $ev[2] . ' ' . ($ar ? 'تغيير حساس: ' : 'Sensitive change: ') . ($line !== '' ? $line : trim(($d['brand'] ?? '') . ' ' . ($d['model'] ?? '')));
            foreach ((array)($d['lines'] ?? []) as $l) $body[] = $l;
            if (!empty($d['url'])) $url = $d['url'];
            break;
        case 'test':
            $title = '🔔 ' . ($ar ? 'تجربة إشعارات First 1 Car' : 'First 1 Car test notification');
            $body[] = $ar ? 'الإشعارات تعمل على هذا الجهاز ✅' : 'Notifications work on this device ✅';
            $url = 'notifications.php?lang=' . $lang;
            break;
        default:
            $title = $ev[2] . ' ' . ($ar ? $ev[0] : $ev[1]);
    }
    $noBy = ['test', 'transfer_received', 'sale_celebrate', 'last_car', 'reserve_old', 'stock_aged', 'transfer_unconfirmed',
             'amana_long', 'bank_waiting', 'clockout_forgot', 'login_failed', 'price_reserved'];
    if ($by !== '' && !in_array($event, $noBy, true) && strpos($event, 'att_') !== 0) $body[] = ($ar ? '✍️ بواسطة ' : '✍️ by ') . $by;
    return [
        'title' => mb_substr($title, 0, 120),
        'body'  => mb_substr(implode("\n", array_filter($body, fn($x) => trim((string)$x) !== '')), 0, 400),
        'url'   => $url,
    ];
}

/* ════════════════════════ the public API ════════════════════════ */
/** Queue of pushes to send once the page has finished answering. */
function push_queue(?array $job = null): array
{
    static $q = [];
    static $hooked = false;
    if ($job !== null) {
        $q[] = $job;
        if (!$hooked) {
            $hooked = true;
            register_shutdown_function('push_flush');
        }
    }
    return $q;
}

function push_flush(): void
{
    $jobs = push_queue();
    if (!$jobs) return;
    // let the user go: the page is done, the phones get their message after
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    ignore_user_abort(true);
    @set_time_limit(60);
    foreach ($jobs as $job) {
        try {
            $res = push_send_many($job['pdo'], $job['subs'], $job['payload'], $job['per'] ?? []);
            $ok  = count(array_filter($res, fn($r) => $r[0] >= 200 && $r[0] < 300));
            if (!empty($job['log_id'])) {
                $job['pdo']->prepare("UPDATE notify_log SET delivered = delivered + ?, failed = failed + ? WHERE id = ?")
                    ->execute([$ok, count($res) - $ok, $job['log_id']]);
            }
        } catch (Throwable $e) {
            error_log('push flush failed: ' . $e->getMessage());
        }
    }
}

function push_in_quiet_hours(array $opt): bool
{
    if (!$opt['quiet']) return false;
    $now = date('H:i');
    $from = $opt['quiet_from']; $to = $opt['quiet_to'];
    return $from <= $to ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to);
}

/** Unread count per person — shown as the red number on the app icon. */
function notify_unread_counts(PDO $pdo, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $out = array_fill_keys($ids, 0);
    foreach ($pdo->query("SELECT i.user_id, COUNT(*) n FROM notify_inbox i JOIN notify_log l ON l.id = i.log_id
                          WHERE i.user_id IN ($in) AND l.event <> 'test'
                            AND (i.seen_at IS NULL OR (l.event = 'message' AND i.read_at IS NULL))
                          GROUP BY i.user_id") as $r) $out[(int)$r['user_id']] = (int)$r['n'];
    return $out;
}

/** Saves the notification in the log and in each person's inbox (each row gets a private link for its buttons). */
function notify_store(PDO $pdo, string $event, array $msg, string $actor, array $ids, bool $needAck = false, string $ref = ''): int
{
    $pdo->prepare("INSERT INTO notify_log (event, title, body, url, actor, recipients, need_ack, ref) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$event, $msg['title'], $msg['body'], $msg['url'], $actor, count($ids), $needAck ? 1 : 0, $ref !== '' ? mb_substr($ref, 0, 255) : null]);
    $logId = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare("INSERT INTO notify_inbox (log_id, user_id, tok) VALUES (?, ?, ?)");
    foreach ($ids as $id) $ins->execute([$logId, $id, bin2hex(random_bytes(12))]);
    return $logId;
}

/**
 * Sends a stored notification to the phones of $ids.
 * Each person gets: their unread count (app icon badge), buttons that work
 * right from the notification, and — when several of the same kind arrive
 * close together — one grouped notification ("3 × car sold") instead of many.
 * $now = true sends immediately and returns [devices, delivered, failed].
 */
function notify_deliver(PDO $pdo, int $logId, string $event, array $msg, array $ids, string $lang, string $tag, bool $needAck = false, bool $now = false): array
{
    $ar = $lang === 'ar';
    $in = implode(',', array_map('intval', $ids));
    $subs = $pdo->query("SELECT id, user_id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
    $pdo->prepare("UPDATE notify_log SET devices = ? WHERE id = ?")->execute([count($subs), $logId]);
    if (!$subs) return [0, 0, 0];
    $withDev = array_values(array_unique(array_map('intval', array_column($subs, 'user_id'))));

    $badge = notify_unread_counts($pdo, $withDev);
    $toks = [];
    $st = $pdo->prepare("SELECT user_id, tok FROM notify_inbox WHERE log_id = ?");
    $st->execute([$logId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $toks[(int)$r['user_id']] = (string)$r['tok'];

    // same kind, not seen yet, in the last 15 minutes → one grouped notification
    $group = [];
    if (!in_array($event, ['message', 'test', 'transfer_incoming'], true)) {
        $in2 = implode(',', $withDev);
        $gs = $pdo->prepare("SELECT i.user_id, l.title FROM notify_inbox i JOIN notify_log l ON l.id = i.log_id
                             WHERE i.user_id IN ($in2) AND l.event = ? AND i.seen_at IS NULL AND l.created_at >= NOW() - INTERVAL 15 MINUTE
                             ORDER BY l.id DESC");
        $gs->execute([$event]);
        foreach ($gs->fetchAll(PDO::FETCH_ASSOC) as $r) $group[(int)$r['user_id']][] = (string)$r['title'];
    }
    $ev = notify_events()[$event] ?? null;

    $per = [];
    foreach ($withDev as $uid) {
        $p = ['badge' => $badge[$uid] ?? 0];
        $t = $toks[$uid] ?? '';
        $acts = [];
        if ($t !== '') {
            if ($event === 'message' && $needAck)       $acts['ack']  = [$ar ? '👍 تمام' : '👍 OK', 'notify_act.php?a=ack&t=' . $t];
            if ($event === 'transfer_incoming')         $acts['recv'] = [$ar ? '✅ استلمت' : '✅ Received', 'notify_act.php?a=recv&t=' . $t];
            if ($event === 'clockout_forgot')           $acts['open'] = [$ar ? '🔵 سجّل انصراف' : '🔵 Clock out', $msg['url']];
        }
        if ($acts) {
            $p['actions'] = array_map(fn($k, $a) => ['action' => $k, 'title' => $a[0]], array_keys($acts), $acts);
            $p['acts'] = array_map(fn($a) => $a[1], $acts);
        }
        $n = count($group[$uid] ?? []);
        if ($n >= 2 && $ev) {
            $p['title'] = $ev[2] . ' ' . ($ar ? $ev[0] : $ev[1]) . ' (' . $n . ')';
            $p['body']  = implode("\n", array_map(fn($x) => '• ' . trim(preg_replace('/^\S+\s+/u', '', $x, 1)), array_slice($group[$uid], 0, 3)))
                        . ($n > 3 ? "\n" . ($ar ? '… و' . ($n - 3) . ' كمان' : '… and ' . ($n - 3) . ' more') : '');
            $p['tag']   = 'grp-' . $event;
            $p['url']   = 'notifications.php?lang=' . $lang;
        }
        $per[$uid] = $p;
    }

    $payload = [
        'title' => $msg['title'], 'body' => $msg['body'], 'url' => $msg['url'], 'tag' => $tag,
        'lang' => $lang, 'dir' => $ar ? 'rtl' : 'ltr', 'ts' => time() * 1000,
    ];
    if (!$now) {
        push_queue(['pdo' => $pdo, 'subs' => $subs, 'log_id' => $logId, 'payload' => $payload, 'per' => $per]);
        return [count($subs), 0, 0];
    }
    $res = push_send_many($pdo, $subs, $payload, $per);
    $ok = count(array_filter($res, fn($r) => $r[0] >= 200 && $r[0] < 300));
    $pdo->prepare("UPDATE notify_log SET delivered = delivered + ?, failed = failed + ? WHERE id = ?")->execute([$ok, count($res) - $ok, $logId]);
    return [count($subs), $ok, count($res) - $ok];
}

/**
 * Announce an event. Safe to call anywhere after a successful save:
 * it never throws and never slows the page down.
 *
 *   $data['owners'] = [usernames]  the people the event is about (they get it
 *                                  when "the person concerned" is on for it)
 *   $data['ref']                   what the buttons act on (e.g. 'mv:12,13')
 *   $data['force']                 send even during quiet hours
 *   $data['now']                   send right away (reminders sent by the cron)
 */
function notify_event(PDO $pdo, string $event, array $data = []): void
{
    try {
        push_tables($pdo);
        $events = notify_events();
        if (!isset($events[$event])) return;
        $opt  = notify_options($pdo);
        $actor = (string)($data['actor'] ?? ($_SESSION['username'] ?? ''));
        $data['actor'] = $actor;

        // the dashboard's live activity keeps everything, whoever gets a notification
        if (in_array($event, notify_activity_events(), true)) {
            try {
                $am = notify_message($pdo, $event, $data, $opt['lang']);
                $pdo->prepare("INSERT INTO activity_log (event, title, actor, url) VALUES (?, ?, ?, ?)")->execute([$event, $am['title'], $actor, $am['url']]);
            } catch (Throwable $e) { error_log('activity_log: ' . $e->getMessage()); }
        }

        $rule = notify_rules($pdo)[$event];
        if (!$rule['on']) return;

        // who should get it
        $users = $pdo->query("SELECT id, username, role FROM users WHERE active = 1")->fetchAll(PDO::FETCH_ASSOC);
        $owners = array_map('strval', (array)($data['owners'] ?? []));
        $ids = [];
        foreach ($users as $u) {
            $id = (int)$u['id'];
            $in = in_array($u['role'], $rule['roles'], true) || in_array($id, $rule['plus'], true)
               || ($rule['owner'] && in_array($u['username'], $owners, true));
            if (in_array($id, $rule['minus'], true)) $in = false;
            if (!$opt['self'] && $actor !== '' && $u['username'] === $actor) $in = false;
            if ($in) $ids[] = $id;
        }
        if (!$ids) return;

        $msg = notify_message($pdo, $event, $data, $opt['lang']);
        $logId = notify_store($pdo, $event, $msg, $actor, $ids, false, (string)($data['ref'] ?? ''));

        if (empty($data['force']) && push_in_quiet_hours($opt)) return;   // kept in history, no sound at night

        notify_deliver($pdo, $logId, $event, $msg, $ids, $opt['lang'], $event . '-' . ($data['car']['id'] ?? $logId), false, !empty($data['now']));
    } catch (Throwable $e) {
        error_log('notify_event(' . $event . ') failed: ' . $e->getMessage());
    }
}

/** Test message to one user's devices (or one device) — sent right away, returns results. */
function notify_test(PDO $pdo, int $userId, ?int $subId = null, string $lang = 'ar'): array
{
    push_tables($pdo);
    $msg = notify_message($pdo, 'test', [], $lang);
    $sql = "SELECT id, user_id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?" . ($subId ? " AND id = " . (int)$subId : '');
    $st = $pdo->prepare($sql);
    $st->execute([$userId]);
    $subs = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$subs) return [];
    return push_send_many($pdo, $subs, ['title' => $msg['title'], 'body' => $msg['body'], 'url' => $msg['url'], 'tag' => 'test-' . time(),
                                        'lang' => $lang, 'dir' => $lang === 'ar' ? 'rtl' : 'ltr', 'ts' => time() * 1000]);
}

/**
 * A message the admin writes, sent right away to the chosen people (ignores
 * quiet hours — the admin is sending it on purpose). Kept in the log and in
 * each person's inbox. $needAck adds a "تمام" button everyone confirms with.
 * Returns [people, devices, delivered, failed, logId].
 */
function notify_custom(PDO $pdo, array $userIds, string $title, string $body, string $actor = '', bool $needAck = false): array
{
    push_tables($pdo);
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$ids) return [0, 0, 0, 0, 0];
    $in  = implode(',', $ids);
    $ids = array_map('intval', $pdo->query("SELECT id FROM users WHERE active = 1 AND id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) return [0, 0, 0, 0, 0];

    $logId = notify_store($pdo, 'message', ['title' => $title, 'body' => $body, 'url' => 'dashboard.php'], $actor, $ids, $needAck);
    $url   = 'dashboard.php?msg=' . $logId;   // tapping it on the phone opens the message inside the system
    $pdo->prepare("UPDATE notify_log SET url = ? WHERE id = ?")->execute([$url, $logId]);

    $rtl = (bool)preg_match('/\p{Arabic}/u', $title . $body);
    [$devs, $ok, $bad] = notify_deliver($pdo, $logId, 'message', ['title' => $title, 'body' => $body, 'url' => $url], $ids,
                                        $rtl ? 'ar' : 'en', 'msg-' . $logId, $needAck, true);
    return [count($ids), $devs, $ok, $bad, $logId];
}

/** A short, human name for a device from its browser string. */
function push_device_name(string $ua): string
{
    $os = 'Device';
    if (preg_match('/iPhone/i', $ua)) $os = 'iPhone';
    elseif (preg_match('/iPad/i', $ua)) $os = 'iPad';
    elseif (preg_match('/Android/i', $ua)) $os = preg_match('/SM-|Samsung/i', $ua) ? 'Samsung Android' : 'Android';
    elseif (preg_match('/Macintosh/i', $ua)) $os = 'Mac';
    elseif (preg_match('/Windows/i', $ua)) $os = 'Windows';
    $br = '';
    if (preg_match('/SamsungBrowser/i', $ua)) $br = 'Samsung Internet';
    elseif (preg_match('/EdgA?\//i', $ua)) $br = 'Edge';
    elseif (preg_match('/Firefox|FxiOS/i', $ua)) $br = 'Firefox';
    elseif (preg_match('/CriOS|Chrome/i', $ua)) $br = 'Chrome';
    elseif (preg_match('/Safari/i', $ua)) $br = 'Safari';
    return trim($os . ($br !== '' ? ' · ' . $br : ''));
}
