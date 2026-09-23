<?php
/*
 * push_subscribe.php — JSON endpoint for phone notifications.
 *
 *   action=key       → the server's public key (to subscribe with)
 *   action=save      → register this phone for the signed-in user
 *   action=renew     → the browser rotated its subscription (from sw.js)
 *   action=remove    → turn notifications off on this phone
 *   action=test      → send a test notification to this phone now
 *
 * Only for signed-in users. State-changing calls need the page's CSRF token
 * (the service worker's "renew" is matched by the old endpoint instead).
 */
require 'auth.php';
require 'config.php';
require_once 'push_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
$action = (string)($in['action'] ?? $_GET['action'] ?? '');
$uid    = (int)$_SESSION['user_id'];
$lang   = ($in['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';

function out(array $a): void { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

try {
    push_tables($pdo);

    if ($action === 'key') {
        out(['ok' => true, 'key' => push_vapid($pdo)['pub']]);
    }

    $csrfOk = !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)($in['csrf'] ?? ''));

    // read a PushSubscription JSON safely
    $readSub = function ($s): ?array {
        if (!is_array($s)) return null;
        $ep = (string)($s['endpoint'] ?? '');
        $p  = (string)($s['keys']['p256dh'] ?? '');
        $a  = (string)($s['keys']['auth'] ?? '');
        if (!preg_match('#^https://[^\s]{10,2000}$#', $ep)) return null;
        if (strlen(b64u_dec($p)) !== 65 || strlen(b64u_dec($a)) < 16) return null;
        return ['endpoint' => $ep, 'p256dh' => $p, 'auth' => $a];
    };
    $store = function (array $s) use ($pdo, $uid) {
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $pdo->prepare("INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, device, user_agent)
                       VALUES (?, ?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth),
                                               device = VALUES(device), user_agent = VALUES(user_agent), fail_count = 0, last_error = NULL")
            ->execute([$uid, $s['endpoint'], hash('sha256', $s['endpoint']), $s['p256dh'], $s['auth'], push_device_name($ua), $ua]);
        $st = $pdo->prepare("SELECT id FROM push_subscriptions WHERE endpoint_hash = ?");
        $st->execute([hash('sha256', $s['endpoint'])]);
        return (int)$st->fetchColumn();
    };

    switch ($action) {
        case 'save':
            if (!$csrfOk) out(['ok' => false, 'error' => 'csrf']);
            $s = $readSub($in['sub'] ?? null);
            if (!$s) out(['ok' => false, 'error' => 'bad_subscription']);
            $id = $store($s);
            $res = !empty($in['test']) ? notify_test($pdo, $uid, $id, $lang) : [];
            out(['ok' => true, 'id' => $id, 'test' => $res ? array_values($res)[0] : null]);

        case 'renew':
            $s = $readSub($in['sub'] ?? null);
            $old = (string)($in['old'] ?? '');
            if (!$s) out(['ok' => false]);
            // accepted when it replaces a phone this user already had, or with the page token
            $st = $pdo->prepare("SELECT id FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?");
            $st->execute([hash('sha256', $old), $uid]);
            if (!$csrfOk && !$st->fetchColumn()) out(['ok' => false, 'error' => 'denied']);
            if ($old !== '') $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?")->execute([hash('sha256', $old), $uid]);
            out(['ok' => true, 'id' => $store($s)]);

        case 'remove':
            if (!$csrfOk) out(['ok' => false, 'error' => 'csrf']);
            $ep = (string)($in['endpoint'] ?? '');
            $id = (int)($in['id'] ?? 0);
            if ($id > 0) {
                // own phones only (the admin page has its own remove for everyone's phones)
                $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ? AND user_id = ?")->execute([$id, $uid]);
            } elseif ($ep !== '') {
                $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?")->execute([hash('sha256', $ep), $uid]);
            }
            out(['ok' => true]);

        case 'test':
            if (!$csrfOk) out(['ok' => false, 'error' => 'csrf']);
            $id  = (int)($in['id'] ?? 0);
            $res = notify_test($pdo, $uid, $id > 0 ? $id : null, $lang);
            $ok  = count(array_filter($res, fn($r) => $r[0] >= 200 && $r[0] < 300));
            out(['ok' => $ok > 0, 'sent' => $ok, 'total' => count($res), 'errors' => array_values(array_filter(array_map(fn($r) => $r[1], $res)))]);

        case 'status':
            $ep = (string)($in['endpoint'] ?? '');
            $st = $pdo->prepare("SELECT id FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?");
            $st->execute([hash('sha256', $ep), $uid]);
            out(['ok' => true, 'known' => (bool)$st->fetchColumn()]);
    }
    out(['ok' => false, 'error' => 'unknown_action']);
} catch (Throwable $e) {
    error_log('push_subscribe: ' . $e->getMessage());
    out(['ok' => false, 'error' => 'server']);
}
