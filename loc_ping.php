<?php
/*
 * loc_ping.php — «تأكيد التواجد»: the phone's side of a location check.
 *
 *   GET            → {"ping": id}   when an admin has asked where this person is
 *   POST {id, lat, lng, acc}        the phone's answer   ({id, denied: true} when it refuses)
 *
 * app.js asks this quietly while the system is open. See check_auto.php.
 */
require 'auth.php';
require 'config.php';
require_once __DIR__ . '/notify_smart.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$uid = (int)$_SESSION['user_id'];
if (!function_exists('loc_ping_due')) { echo json_encode(['ping' => 0]); exit; }

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($in['csrf'] ?? ''))) { echo json_encode(['ok' => false]); exit; }
        $denied = !empty($in['denied']) || !is_numeric($in['lat'] ?? null) || !is_numeric($in['lng'] ?? null);
        $ok = loc_ping_answer($pdo, $uid, (int)($in['id'] ?? 0), $denied ? null : (float)$in['lat'], $denied ? null : (float)$in['lng'], (int)round((float)($in['acc'] ?? 0)));
        echo json_encode(['ok' => $ok]); exit;
    }
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $p = loc_ping_due($pdo, $uid);
    echo json_encode(['ping' => $p ? (int)$p['id'] : 0, 'csrf' => $p ? $_SESSION['csrf_token'] : '']);
} catch (Throwable $e) {
    error_log('loc_ping: ' . $e->getMessage());
    echo json_encode(['ping' => 0]);
}
