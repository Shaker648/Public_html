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
    echo json_encode(['ok' => true, 'sig' => (object)$sig]);
} catch (Throwable $e) {
    error_log('dashboard_pulse: ' . $e->getMessage());
    echo json_encode(['ok' => false]);
}
