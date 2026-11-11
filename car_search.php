<?php
/*
 * car_search.php — search from any page (app.js): a chassis number, a model,
 * a colour or a branch → the matching cars, as JSON.
 *
 * Shows what the dashboard shows: cars in stock (available / reserved), plus
 * consignment cars for whoever may see them. A chassis that still has to be
 * typed off the car (chassis_gate.php) stays hidden and cannot be searched.
 */
require 'auth.php';
require 'config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$lang = ($_GET['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) { echo json_encode(['ok' => true, 'items' => []]); exit; }

try {
    $statuses = ["'available'", "'reserved'"];
    if (can('dash.amana_section')) $statuses[] = "'consignment'";
    $words = array_slice(array_filter(preg_split('/\s+/u', $q)), 0, 5);
    $where = []; $args = [];
    foreach ($words as $w) {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $w) . '%';
        $where[] = "(c.brand LIKE ? OR c.model LIKE ? OR c.trim_name LIKE ? OR c.car_year LIKE ? OR c.color LIKE ? OR c.chassis LIKE ? OR c.branch LIKE ?
                     OR b.name_ar LIKE ? OR b.name_en LIKE ? OR co.color_ar LIKE ?)";
        array_push($args, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    }
    $st = $pdo->prepare("SELECT c.id, c.brand, c.model, c.trim_name, c.car_year, c.color, c.chassis, c.branch, c.status, b.name_ar, b.name_en, co.color_ar
                         FROM cars c LEFT JOIN branches b ON b.name = c.branch LEFT JOIN colors co ON co.color_en = c.color
                         WHERE c.status IN (" . implode(',', $statuses) . ") AND " . implode(' AND ', $where) . "
                         ORDER BY c.brand, c.model, c.trim_name LIMIT 60");
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // hidden chassis: masked, and a row that only matched on that hidden number is dropped
    $hide = [];
    if (is_file(__DIR__ . '/chassis_gate.php')) {
        require_once __DIR__ . '/chassis_gate.php';
        if (($_SESSION['role'] ?? '') !== 'admin') {
            if (is_file(__DIR__ . '/notify_smart.php')) require_once __DIR__ . '/notify_smart.php';
            $hide = chassis_hidden_ids($pdo, (int)$_SESSION['user_id'], (string)$_SESSION['role']);
        }
    }
    $stL = $lang === 'ar' ? ['available' => 'متاحة', 'reserved' => 'محجوزة', 'consignment' => 'أمانة'] : ['available' => 'Available', 'reserved' => 'Reserved', 'consignment' => 'Consignment'];
    $items = [];
    foreach ($rows as $r) {
        if (isset($hide[(int)$r['id']])) {
            $rest = mb_strtolower(implode(' ', [$r['brand'], $r['model'], $r['trim_name'], $r['car_year'], $r['color'], $r['branch'], $r['name_ar'], $r['name_en'], $r['color_ar']]));
            foreach ($words as $w) if (mb_strpos($rest, mb_strtolower($w)) === false) continue 2;
            $r['chassis'] = chassis_mask();
        }
        $items[] = [
            'id'      => (int)$r['id'],
            'title'   => trim($r['brand'] . ' ' . $r['model'] . ' ' . $r['trim_name'] . ' ' . $r['car_year']),
            'color'   => $lang === 'ar' ? ($r['color_ar'] ?: $r['color']) : $r['color'],
            'branch'  => ($lang === 'ar' ? $r['name_ar'] : $r['name_en']) ?: $r['branch'],
            'chassis' => (string)$r['chassis'],
            'status'  => (string)$r['status'],
            'statusL' => $stL[$r['status']] ?? (string)$r['status'],
            'url'     => (can('page.vehicle_timeline') ? 'vehicle_timeline.php?id=' . (int)$r['id'] . '&lang=' . $lang
                                                       : 'dashboard.php?lang=' . $lang . '&search=' . urlencode(isset($hide[(int)$r['id']]) ? $r['model'] : $r['chassis'])),
        ];
        if (count($items) >= 30) break;
    }
    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('car_search: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'items' => []]);
}
