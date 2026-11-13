<?php
/*
 * car_peek.php — the quick look at one car (dashboard): its details and, for
 * whoever may open the car's journey, its latest history. JSON.
 */
require 'auth.php';
require 'config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$lang = ($_GET['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
$ar = $lang === 'ar';
$id = (int)($_GET['id'] ?? 0);

try {
    $statuses = ["'available'", "'reserved'"];
    if (can('dash.amana_section')) $statuses[] = "'consignment'";
    $st = $pdo->prepare("SELECT c.*, b.name_ar, b.name_en, co.color_ar, DATEDIFF(CURDATE(), DATE(c.created_at)) AS days_in
                         FROM cars c LEFT JOIN branches b ON b.name = c.branch LEFT JOIN colors co ON co.color_en = c.color
                         WHERE c.id = ? AND c.status IN (" . implode(',', $statuses) . ")");
    $st->execute([$id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { echo json_encode(['ok' => false]); exit; }
    if (is_file(__DIR__ . '/chassis_gate.php')) { require_once __DIR__ . '/chassis_gate.php'; chassis_mask_rows($pdo, $rows); }   // not before it is typed off the car
    $c = $rows[0];

    $brL = function ($name) use ($pdo, $ar) {
        static $map = null;
        if ($map === null) { $map = []; foreach ($pdo->query("SELECT name, name_ar, name_en FROM branches") as $b) $map[$b['name']] = ($ar ? $b['name_ar'] : $b['name_en']) ?: $b['name']; }
        return $map[$name] ?? (string)$name;
    };
    $when = function ($dt) use ($ar) {
        $ts = strtotime((string)$dt); if (!$ts) return '';
        return date('Y-m-d', $ts) . ' · ' . date('h:i', $ts) . ($ar ? (date('A', $ts) === 'AM' ? ' ص' : ' م') : ' ' . date('A', $ts));
    };

    $hist = [];
    if (can('page.vehicle_timeline')) {
        $ev = $ar ? ['transfer' => '🚚 نقل', '' => '🚚 نقل', 'reserved' => '🔒 حجز', 'reserve_cancel' => '🔓 إلغاء الحجز']
                  : ['transfer' => '🚚 Transfer', '' => '🚚 Transfer', 'reserved' => '🔒 Reserved', 'reserve_cancel' => '🔓 Reservation cancelled'];
        $m = $pdo->prepare("SELECT event_type, from_branch, to_branch, moved_by, created_at FROM movements WHERE car_id = ? ORDER BY id DESC LIMIT 8");
        $m->execute([$id]);
        foreach ($m->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['event_type'] = (string)$r['event_type'];
            if ($r['event_type'] === 'created') continue;                                              // shown below as «أُضيفت إلى المخزون»
            $isTr = in_array($r['event_type'], ['transfer', ''], true);
            if ($isTr && (string)$r['from_branch'] === (string)$r['to_branch']) continue;              // a "move" to the same place
            $what = $ev[$r['event_type']] ?? ('• ' . $r['event_type']);
            if ($isTr) $what .= ' · ' . $brL($r['from_branch']) . ' ← ' . $brL($r['to_branch']);
            $hist[] = ['what' => $what, 'by' => (string)$r['moved_by'], 'when' => $when($r['created_at'])];
        }
        if (!empty($c['created_at'])) $hist[] = ['what' => $ar ? '➕ أُضيفت إلى المخزون' : '➕ Added to stock', 'by' => (string)($c['created_by'] ?? ''), 'when' => $when($c['created_at'])];
    }
    $days = $c['days_in'] !== null ? max(0, (int)$c['days_in']) : null;
    // the last time somebody physically confirmed this car — admins and managers only
    $seen = '';
    if (in_array($_SESSION['role'] ?? '', ['admin', 'manager'], true)) {
        $how = $ar ? ['receive' => 'عند الاستلام', 'check' => 'في جرد', 'spot' => 'في فحص سريع'] : ['receive' => 'on receiving', 'check' => 'in a stock check', 'spot' => 'in a spot check'];
        $seen = !empty($c['last_seen_at']) ? $when($c['last_seen_at']) . ' · ' . ($how[$c['last_seen_how'] ?? ''] ?? '') . ' · ' . ($c['last_seen_by'] ?? '')
                                           : ($ar ? 'لم يؤكد أحد وجودها فعلياً بعد' : 'Nobody has physically confirmed it yet');
    }
    echo json_encode(['ok' => true, 'car' => [
        'id'      => (int)$c['id'],
        'title'   => trim($c['brand'] . ' ' . $c['model']),
        'trim'    => (string)$c['trim_name'],
        'year'    => (string)$c['car_year'],
        'color'   => $ar ? ($c['color_ar'] ?: $c['color']) : (string)$c['color'],
        'branch'  => ($ar ? $c['name_ar'] : $c['name_en']) ?: (string)$c['branch'],
        'chassis' => (string)$c['chassis'],
        'status'  => (string)$c['status'],
        'age'     => $days === null ? '' : ($ar ? ($days === 0 ? 'أُضيفت اليوم' : 'في المخزون منذ ' . ($days === 1 ? 'يوم واحد' : ($days === 2 ? 'يومين' : $days . ($days <= 10 ? ' أيام' : ' يوماً')))) : ($days === 0 ? 'Added today' : $days . ' days in stock')),
        'notes'   => (string)($c['notes'] ?? ''),
        'seen'    => $seen,
    ], 'history' => $hist, 'timeline' => can('page.vehicle_timeline') ? 'vehicle_timeline.php?id=' . (int)$c['id'] . '&lang=' . $lang : ''], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('car_peek: ' . $e->getMessage());
    echo json_encode(['ok' => false]);
}
