<?php
/*
 * dashboard_pulse.php — a fingerprint of every car the dashboard shows, so an
 * open dashboard can tell the user "the stock changed — refresh" when someone
 * else sells, reserves, moves, edits or adds a car. Read-only, tiny JSON.
 * The fingerprint must match car_sig() in dashboard.php.
 */
require 'auth.php';
require 'config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $statuses = ["'available'", "'reserved'"];
    if (can('dash.amana_section')) $statuses[] = "'consignment'";
    $rows = $pdo->query("SELECT id, status, branch, color, trim_name, model, car_year, chassis FROM cars WHERE status IN (" . implode(',', $statuses) . ")")
                ->fetchAll(PDO::FETCH_ASSOC);
    $sig = [];
    foreach ($rows as $c) {
        $sig[(int)$c['id']] = crc32(implode('|', [$c['status'], $c['branch'], $c['color'], $c['trim_name'], $c['model'], $c['car_year'], $c['chassis']]));
    }
    $out = ['ok' => true, 'sig' => (object)$sig];

    // live activity + who is online (dash.activity) — a sale never shows who sold it
    if (can('dash.activity')) {
        try {
            $out['online'] = $pdo->query("SELECT username AS n, role AS r FROM users WHERE active = 1 AND last_seen >= NOW() - INTERVAL 3 MINUTE ORDER BY last_seen DESC")
                                 ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $out['online'] = []; }
        try {
            $rows = $pdo->query("SELECT id, event, title, actor, url, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age FROM activity_log
                                 WHERE created_at >= NOW() - INTERVAL 3 DAY ORDER BY id DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
            $out['act'] = array_map(fn($r) => ['id' => (int)$r['id'], 'ev' => $r['event'], 't' => $r['title'], 'u' => (string)$r['url'], 'a' => (int)$r['age'],
                                               'by' => in_array($r['event'], ['car_sold', 'amana_closed'], true) ? '' : (string)$r['actor']], $rows);
        } catch (Throwable $e) { $out['act'] = []; }   // table not created yet
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('dashboard_pulse: ' . $e->getMessage());
    echo json_encode(['ok' => false]);
}
