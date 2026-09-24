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
    // key => [ar, en, icon, default roles, (on by default — optional, true)]
    return [
        'car_added'        => ['إضافة سيارة جديدة',          'New car added',              '🚗', ['admin']],
        'shipment_received'=> ['استلام شحنة',                 'Shipment received',          '📦', ['admin']],
        'car_transferred'  => ['نقل سيارة بين الفروع',        'Car transferred',            '🔄', ['admin']],
        'car_reserved'     => ['حجز سيارة',                   'Car reserved',               '🔒', ['admin']],
        'reserve_cancelled'=> ['إلغاء حجز',                   'Reservation cancelled',      '↩️', ['admin']],
        'car_sold'         => ['بيع سيارة',                   'Car sold',                   '💰', ['admin']],
        'amana_out'        => ['خروج سيارة أمانة',            'Car out on consignment',     '🔶', ['admin']],
        'amana_closed'     => ['إغلاق أمانة كبيع',            'Consignment closed as sale', '✅', ['admin']],
        'amana_returned'   => ['رجوع سيارة من الأمانة',        'Consignment returned',       '🏠', ['admin']],
        'sale_returned'    => ['إرجاع سيارة مباعة للمخزون',    'Sold car returned to stock', '⏪', ['admin']],
        'car_edited'       => ['تعديل بيانات سيارة',           'Car details edited',         '✏️', ['admin']],
        'price_changed'    => ['تغيير سعر',                    'Price changed',              '📈', ['admin']],
        // attendance — [4] = on by default (every clock-in / out would be a lot, so those start off)
        'att_late'         => ['تأخير في الحضور',              'Late arrival',               '⏰', ['admin']],
        'att_far'          => ['بصمة بعيدة عن الفرع',          'Punch far from the branch',  '🛑', ['admin']],
        'att_in'           => ['تسجيل حضور',                   'Clock in',                   '🟢', ['admin'], false],
        'att_out'          => ['تسجيل انصراف',                 'Clock out',                  '🔵', ['admin'], false],
    ];
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
        INDEX idx_user (user_id, log_id)
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
function push_send_many(PDO $pdo, array $subs, array $payload): array
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $mh = curl_multi_init();
    $hs = [];
    $res = [];
    foreach ($subs as $s) {
        try {
            $body = push_encrypt($json, $s['p256dh'], $s['auth']);
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
        case 'att_late':
        case 'att_far':
            $who  = (string)($d['actor'] ?? '');
            $at   = !empty($d['time']) ? date('h:i', strtotime((string)$d['time'])) . ($ar ? (date('A', strtotime((string)$d['time'])) === 'AM' ? ' ص' : ' م') : ' ' . date('A', strtotime((string)$d['time']))) : '';
            $abr  = push_branch_label($pdo, (string)($d['branch'] ?? ''), $lang);
            $url  = 'attendance_admin.php?lang=' . $lang;
            if ($event === 'att_in')   $title = $ev[2] . ' ' . $who . ($ar ? ' سجّل حضور' : ' clocked in');
            if ($event === 'att_out')  $title = $ev[2] . ' ' . $who . ($ar ? ' سجّل انصراف' : ' clocked out');
            if ($event === 'att_late') $title = $ev[2] . ' ' . $who . ($ar ? ' متأخر ' . (int)$d['late'] . ' دقيقة' : ' is ' . (int)$d['late'] . ' min late');
            if ($event === 'att_far')  $title = $ev[2] . ' ' . ($ar ? 'بصمة بعيدة: ' : 'Far punch: ') . $who;
            if ($event === 'att_far')  $body[] = (($d['dir'] ?? 'in') === 'out' ? ($ar ? 'انصراف' : 'Clock-out') : ($ar ? 'حضور' : 'Clock-in')) . ($ar ? ' على بعد ' . (int)$d['dist'] . ' متر من ' . $abr : ' ' . (int)$d['dist'] . ' m from ' . $abr);
            $st   = !empty($d['start']) ? date('h:i', strtotime('2000-01-01 ' . $d['start'])) . ($ar ? (date('A', strtotime('2000-01-01 ' . $d['start'])) === 'AM' ? ' ص' : ' م') : ' ' . date('A', strtotime('2000-01-01 ' . $d['start']))) : '';
            if ($event === 'att_late') $body[] = ($ar ? 'حضر ' . $at . ' · الموعد ' . $st : 'Arrived ' . $at . ' · start ' . $st) . ($abr !== '' ? ' · 📍 ' . $abr : '');
            if ($event === 'att_in' || $event === 'att_far') $body[] = '🕐 ' . $at . ($abr !== '' && $event === 'att_in' ? ' · 📍 ' . $abr : '');
            if ($event === 'att_out')  $body[] = '🕐 ' . $at . (isset($d['secs']) ? ' · ⏱️ ' . sprintf($ar ? '%dس %02dد' : '%dh %02dm', intdiv((int)$d['secs'], 3600), intdiv((int)$d['secs'] % 3600, 60)) : '') . ($abr !== '' ? ' · 📍 ' . $abr : '');
            break;
        case 'test':
            $title = '🔔 ' . ($ar ? 'تجربة إشعارات First 1 Car' : 'First 1 Car test notification');
            $body[] = $ar ? 'الإشعارات تعمل على هذا الجهاز ✅' : 'Notifications work on this device ✅';
            $url = 'notifications.php?lang=' . $lang;
            break;
        default:
            $title = $ev[2] . ' ' . ($ar ? $ev[0] : $ev[1]);
    }
    if ($by !== '' && $event !== 'test' && strpos($event, 'att_') !== 0) $body[] = ($ar ? '✍️ بواسطة ' : '✍️ by ') . $by;
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
            $res = push_send_many($job['pdo'], $job['subs'], $job['payload']);
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

/**
 * Announce an event. Safe to call anywhere after a successful save:
 * it never throws and never slows the page down.
 */
function notify_event(PDO $pdo, string $event, array $data = []): void
{
    try {
        push_tables($pdo);
        $events = notify_events();
        if (!isset($events[$event])) return;
        $rule = notify_rules($pdo)[$event];
        $opt  = notify_options($pdo);
        if (!$rule['on']) return;

        $actor = (string)($data['actor'] ?? ($_SESSION['username'] ?? ''));
        $data['actor'] = $actor;

        // who should get it
        $users = $pdo->query("SELECT id, username, role FROM users WHERE active = 1")->fetchAll(PDO::FETCH_ASSOC);
        $ids = [];
        foreach ($users as $u) {
            $id = (int)$u['id'];
            $in = in_array($u['role'], $rule['roles'], true) || in_array($id, $rule['plus'], true);
            if (in_array($id, $rule['minus'], true)) $in = false;
            if (!$opt['self'] && $u['username'] === $actor) $in = false;
            if ($in) $ids[] = $id;
        }
        if (!$ids) return;

        $msg = notify_message($pdo, $event, $data, $opt['lang']);
        $pdo->prepare("INSERT INTO notify_log (event, title, body, url, actor, recipients) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$event, $msg['title'], $msg['body'], $msg['url'], $actor, count($ids)]);
        $logId = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO notify_inbox (log_id, user_id) VALUES (?, ?)");
        foreach ($ids as $id) $ins->execute([$logId, $id]);

        if (push_in_quiet_hours($opt)) return;   // kept in history, no sound at night

        $in = implode(',', array_map('intval', $ids));
        $subs = $pdo->query("SELECT id, user_id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
        $pdo->prepare("UPDATE notify_log SET devices = ? WHERE id = ?")->execute([count($subs), $logId]);
        if (!$subs) return;

        push_queue(['pdo' => $pdo, 'subs' => $subs, 'log_id' => $logId, 'payload' => [
            'title' => $msg['title'],
            'body'  => $msg['body'],
            'url'   => $msg['url'],
            'tag'   => $event . '-' . ($data['car']['id'] ?? $logId),
            'lang'  => $opt['lang'],
            'dir'   => $opt['lang'] === 'ar' ? 'rtl' : 'ltr',
            'ts'    => time() * 1000,
        ]]);
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
 * each person's inbox. Returns [people, devices, delivered, failed].
 */
function notify_custom(PDO $pdo, array $userIds, string $title, string $body, string $actor = ''): array
{
    push_tables($pdo);
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$ids) return [0, 0, 0, 0];
    $in  = implode(',', $ids);
    $ids = array_map('intval', $pdo->query("SELECT id FROM users WHERE active = 1 AND id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) return [0, 0, 0, 0];
    $in  = implode(',', $ids);

    $pdo->prepare("INSERT INTO notify_log (event, title, body, url, actor, recipients) VALUES ('message', ?, ?, 'dashboard.php', ?, ?)")
        ->execute([$title, $body, $actor, count($ids)]);
    $logId = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare("INSERT INTO notify_inbox (log_id, user_id) VALUES (?, ?)");
    foreach ($ids as $id) $ins->execute([$logId, $id]);

    $subs = $pdo->query("SELECT id, user_id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
    $pdo->prepare("UPDATE notify_log SET devices = ? WHERE id = ?")->execute([count($subs), $logId]);
    if (!$subs) return [count($ids), 0, 0, 0];

    $rtl = (bool)preg_match('/\p{Arabic}/u', $title . $body);
    $res = push_send_many($pdo, $subs, [
        'title' => $title, 'body' => $body, 'url' => 'dashboard.php', 'tag' => 'msg-' . $logId,
        'lang' => $rtl ? 'ar' : 'en', 'dir' => $rtl ? 'rtl' : 'ltr', 'ts' => time() * 1000,
    ]);
    $ok = count(array_filter($res, fn($r) => $r[0] >= 200 && $r[0] < 300));
    $pdo->prepare("UPDATE notify_log SET delivered = ?, failed = ? WHERE id = ?")->execute([$ok, count($res) - $ok, $logId]);
    return [count($ids), count($subs), $ok, count($res) - $ok];
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
