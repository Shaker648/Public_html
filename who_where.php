<?php
/*
 * who_where.php — admins only: who is at which branch right now, as JSON for
 * the «من أين الآن» panel on the dashboard.
 *
 * For each person clocked in: their branch, since when, and the last thing the
 * system knows about where they physically are — a location request answered
 * after the clock-in, otherwise the clock-in itself. Plus: online now, a request
 * waiting, system stopped.
 */
require 'auth.php';
require 'config.php';
require_once __DIR__ . '/notify_smart.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); echo json_encode(['ok' => false]); exit; }
$lang = ($_GET['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
$ar = $lang === 'ar';

try {
    if (function_exists('loc_ping_tables')) loc_ping_tables($pdo);
    $fence = function_exists('loc_fence') ? loc_fence($pdo) : 50;
    $time = function ($dt) use ($ar) { $ts = strtotime((string)$dt); return date('h:i', $ts) . ($ar ? (date('A', $ts) === 'AM' ? ' ص' : ' م') : ' ' . date('A', $ts)); };
    $dist = fn($m) => $m === null ? '' : ((int)$m < 1000 ? (int)$m . ($ar ? ' م' : ' m') : round((int)$m / 1000, 1) . ($ar ? ' كم' : ' km'));

    $rows = $pdo->query("SELECT a.id AS att, a.user_id, a.branch_name, a.clock_in, a.in_dist_m, u.username, TIMESTAMPDIFF(SECOND, a.clock_in, NOW()) AS secs,
                                TIMESTAMPDIFF(SECOND, u.last_seen, NOW()) AS idle,
                                EXISTS (SELECT 1 FROM user_locks l WHERE l.user_id = a.user_id AND l.unlocked_at IS NULL) AS stopped
                         FROM attendance_logs a JOIN users u ON u.id = a.user_id
                         WHERE a.status = 'active' AND u.active = 1 AND u.role <> 'admin' ORDER BY a.branch_name, u.username")->fetchAll(PDO::FETCH_ASSOC);
    $lp = $pdo->prepare("SELECT status, dist_m, acc, asked_at, answered_at, late FROM loc_pings WHERE user_id = ? AND asked_at >= ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 1");

    $branches = [];
    foreach ($pdo->query("SELECT name, name_ar, name_en FROM branches ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $b)
        $branches[$b['name']] = ['name' => ($ar ? $b['name_ar'] : $b['name_en']) ?: $b['name'], 'people' => []];

    foreach ($rows as $r) {
        $lp->execute([(int)$r['user_id'], $r['clock_in']]);
        $p = $lp->fetch(PDO::FETCH_ASSOC) ?: null;
        // where they are: green = inside the branch · red = far · amber = not known · blue = waiting for a location request
        if ($p && $p['status'] === 'pending') {
            $st = 'wait'; $why = $ar ? 'طُلب موقعه ' . $time($p['asked_at']) . ' — النظام مغلق حتى يرسله' : 'Location asked ' . $time($p['asked_at']) . ' — closed until sent';
        } elseif ($p && in_array($p['status'], ['ok', 'far'], true)) {
            $st = $p['status'] === 'ok' ? 'in' : 'far';
            $why = ($ar ? 'آخر تأكيد ' : 'Last checked ') . $time($p['answered_at'] ?: $p['asked_at']) . ($p['dist_m'] !== null ? ' · ' . $dist($p['dist_m']) : '') . ((int)$p['late'] ? ($ar ? ' · متأخراً' : ' · late') : '');
        } elseif ($r['in_dist_m'] !== null) {
            $st = (int)$r['in_dist_m'] <= $fence ? 'in' : 'far';
            $why = ($ar ? 'عند البصمة ' : 'At clock-in ') . $time($r['clock_in']) . ' · ' . $dist($r['in_dist_m']);
        } else {
            $st = 'unknown'; $why = $ar ? 'لا يوجد موقع مسجَّل منذ البصمة' : 'No location since clock-in';
        }
        $secs = max(0, (int)$r['secs']);
        $item = [
            'id' => (int)$r['user_id'], 'name' => (string)$r['username'], 'st' => $st, 'why' => $why,
            'since' => $time($r['clock_in']), 'for' => sprintf($ar ? '%dس %02dد' : '%dh %02dm', intdiv($secs, 3600), intdiv($secs % 3600, 60)),
            'online' => $r['idle'] !== null && (int)$r['idle'] < 180, 'stopped' => (bool)$r['stopped'], 'asking' => $st === 'wait',
        ];
        $key = (string)$r['branch_name'];
        if (!isset($branches[$key])) $branches[$key] = ['name' => $key, 'people' => []];
        $branches[$key]['people'][] = $item;
    }
    $out = [];
    foreach ($branches as $b) $out[] = $b;
    usort($out, fn($a, $b) => count($b['people']) <=> count($a['people']));
    echo json_encode(['ok' => true, 'branches' => $out, 'total' => count($rows), 'at' => $time((string)$pdo->query("SELECT NOW()")->fetchColumn())], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('who_where: ' . $e->getMessage());
    echo json_encode(['ok' => false]);
}
