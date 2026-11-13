<?php
/*
 * day_replay.php — «ما تحرّك اليوم»: today's stock events in order, for the
 * replay on the dashboard's branch map. Cars and branches only — no names.
 */
require 'auth.php';
require 'config.php';
require_once __DIR__ . '/color_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$lang = ($_GET['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
$ar = $lang === 'ar';

try {
    $br = [];
    foreach ($pdo->query("SELECT name, name_ar, name_en FROM branches") as $b) $br[$b['name']] = ($ar ? $b['name_ar'] : $b['name_en']) ?: $b['name'];
    $lbl = fn($n) => $br[(string)$n] ?? (string)$n;
    $time = function ($dt) use ($ar) { $ts = strtotime((string)$dt); return date('h:i', $ts) . ($ar ? (date('A', $ts) === 'AM' ? ' ص' : ' م') : ' ' . date('A', $ts)); };
    $ev = [];
    $q = $pdo->query("SELECT m.event_type, m.from_branch, m.to_branch, m.created_at, c.brand, c.model, c.color, c.branch
                      FROM movements m JOIN cars c ON c.id = m.car_id WHERE m.created_at >= CURDATE() ORDER BY m.id");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $t = (string)$r['event_type'];
        $base = ['car' => trim($r['brand'] . ' ' . $r['model']), 'hex' => car_color_hex((string)$r['color']), 'at' => $time($r['created_at']), 'ts' => strtotime($r['created_at'])];
        if (in_array($t, ['transfer', ''], true)) {
            if ((string)$r['from_branch'] === (string)$r['to_branch'] || (string)$r['to_branch'] === '') continue;
            $ev[] = $base + ['k' => 'move', 'from' => $lbl($r['from_branch']), 'to' => $lbl($r['to_branch'])];
        } elseif ($t === 'created')        $ev[] = $base + ['k' => 'add', 'to' => $lbl($r['to_branch'] ?: $r['branch'])];
        elseif ($t === 'reserved')         $ev[] = $base + ['k' => 'res', 'to' => $lbl($r['branch'])];
        elseif ($t === 'reserve_cancel')   $ev[] = $base + ['k' => 'unres', 'to' => $lbl($r['branch'])];
    }
    if (can('dash.stat_sold')) {
        try {
            $q = $pdo->query("SELECT s.sold_at, s.sold_branch, c.brand, c.model, c.color, c.branch FROM sold_cars s JOIN cars c ON c.id = s.car_id WHERE s.sold_at >= CURDATE() AND s.returned_at IS NULL ORDER BY s.id");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $ev[] = ['k' => 'sold', 'car' => trim($r['brand'] . ' ' . $r['model']), 'hex' => car_color_hex((string)$r['color']), 'at' => $time($r['sold_at']),
                'ts' => strtotime($r['sold_at']), 'to' => $lbl($r['sold_branch'] ?: $r['branch'])];
        } catch (Throwable $e) {}
    }
    usort($ev, fn($a, $b) => $a['ts'] <=> $b['ts']);
    echo json_encode(['ok' => true, 'events' => array_slice($ev, -60)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('day_replay: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'events' => []]);
}
