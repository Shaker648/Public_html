<?php

require 'auth.php';
require 'config.php';
require 'sold_helpers.php';

$lang = $_GET['lang'] ?? 'ar';
$dir  = $lang === 'ar' ? 'rtl' : 'ltr';
$id   = (int)($_GET['id'] ?? 0);

perm_require('page.vehicle_timeline');

/* امانة events — permission-gated (default: admin/manager) */
$canSeeAmana = can('timeline.amana');

/* ─── Translations ──────────────────────────────────────── */
$t = [
    'ar' => [
        'title'            => 'رحلة السيارة',
        'status'           => 'الحالة',
        'branch'           => 'الفرع',
        'branch_type'      => 'نوع الموقع',
        'chassis'          => 'الشاسيه',
        'created_by'       => 'أضيف بواسطة',
        'created_at'       => 'تاريخ الإضافة',
        'days_stock'       => 'أيام بالمخزون',
        'transfers'        => 'مرات النقل',
        'journey'          => 'رحلة السيارة',
        'available'        => 'متاحة',
        'sold'             => 'مباعة',
        'reserved'         => 'محجوزة',
        'consignment'      => 'امانة',
        'showroom'         => 'معرض',
        'storage'          => 'مخزن',
        'added'            => 'إضافة السيارة',
        'transferred'      => 'نقل السيارة',
        'sold_event'       => 'بيع السيارة',
        'sale_return_event'=> 'إرجاع من البيع (رجعت للمخزون)',
        'sold_reverted_tag'=> '↩️ أُرجعت لاحقاً للمخزون',
        'amana_out_event'  => 'خروج أمانة',
        'amana_return_event' => 'إرجاع من الأمانة',
        'amana_dealer'     => 'التاجر',
        'from'             => 'من',
        'to'               => 'إلى',
        'by'               => 'بواسطة',
        'notes'            => 'ملاحظات',
        'customer'         => 'العميل',
        'dealer'           => 'التاجر',
        'color'            => 'اللون',
        'route_map'        => 'مسار السيارة',
        'journey_start'    => 'نقطة البداية',
        'current_location' => 'الموقع الحالي',
        'back'             => 'العودة للرئيسية',
        'switch_lang'      => 'English',
        'stops'            => 'محطات',
        'timeline'         => 'التسلسل الزمني',
    ],
    'en' => [
        'title'            => 'Vehicle Journey',
        'status'           => 'Status',
        'branch'           => 'Branch',
        'branch_type'      => 'Location Type',
        'chassis'          => 'Chassis',
        'created_by'       => 'Added By',
        'created_at'       => 'Date Added',
        'days_stock'       => 'Days In Stock',
        'transfers'        => 'Transfers',
        'journey'          => 'Vehicle Journey',
        'available'        => 'Available',
        'sold'             => 'Sold',
        'reserved'         => 'Reserved',
        'consignment'      => 'Consignment',
        'showroom'         => 'Showroom',
        'storage'          => 'Storage',
        'added'            => 'Vehicle Added',
        'transferred'      => 'Vehicle Transferred',
        'sold_event'       => 'Vehicle Sold',
        'sale_return_event'=> 'Returned from Sale (back in stock)',
        'sold_reverted_tag'=> '↩️ Later returned to stock',
        'amana_out_event'  => 'Out on Consignment',
        'amana_return_event' => 'Returned from Consignment',
        'amana_dealer'     => 'Dealer',
        'from'             => 'From',
        'to'               => 'To',
        'by'               => 'By',
        'notes'            => 'Notes',
        'customer'         => 'Customer',
        'dealer'           => 'Dealer',
        'color'            => 'Color',
        'route_map'        => 'Route Map',
        'journey_start'    => 'Journey Start',
        'current_location' => 'Current Location',
        'back'             => 'Back to Dashboard',
        'switch_lang'      => 'عربي',
        'stops'            => 'Stops',
        'timeline'         => 'Timeline',
    ],
];

/* ─── Fetch car ─────────────────────────────────────────── */
$stmt = $pdo->prepare("
    SELECT
        cars.*,
        colors.color_ar,
        colors.color_en,
        branches.name_ar   AS branch_name_ar,
        branches.name_en   AS branch_name_en,
        branches.branch_type
    FROM cars
    LEFT JOIN colors   ON cars.color  = colors.color_en
    LEFT JOIN branches ON cars.branch = branches.name
    WHERE cars.id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$car = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$car) { http_response_code(404); die('Vehicle not found'); }

// Sales staff must not see امانة cars at all — send them back to the dashboard.
if (($car['status'] ?? '') === 'consignment' && !$canSeeAmana) {
    header('Location: dashboard.php?lang=' . $lang);
    exit;
}

/* ─── Display helpers ───────────────────────────────────── */
$displayColor  = $lang === 'ar' ? ($car['color_ar']       ?: $car['color'])  : ($car['color_en']       ?: $car['color']);
$displayBranch = $lang === 'ar' ? ($car['branch_name_ar'] ?: $car['branch']) : ($car['branch_name_en'] ?: $car['branch']);
$displayBranchType = $lang === 'ar'
    ? ($car['branch_type'] === 'storage' ? 'مخزن' : 'معرض')
    : ucfirst($car['branch_type'] ?? '');

/* ─── Fetch movements ───────────────────────────────────── */
$stmt = $pdo->prepare("SELECT * FROM movements WHERE car_id = ? ORDER BY created_at ASC");
$stmt->execute([$id]);
$movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count only true branch transfers for the stat (exclude created + امانة events)
$totalTransfers = 0;
foreach ($movements as $mv) {
    if (($mv['event_type'] ?? '') === 'transfer') $totalTransfers++;
}

/* ─── Consignment history (for امانة dealer names on events) ─── */
$consignHist = [];
$cStmt = $pdo->prepare("SELECT * FROM consignments WHERE car_id = ? ORDER BY id ASC");
$cStmt->execute([$id]);
foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $cn) {
    $consignHist[] = $cn;
}
// Most recent dealer name (used as a fallback label on امانة events)
$lastDealer = !empty($consignHist) ? end($consignHist)['dealer_name'] : '';

/* ─── Fetch sale record(s) — kept even after a revert, so the trip shows
       "sold to X" AND "returned to stock" ─────────────────────────────── */
ensure_sold_revert_columns($pdo);
$stmt = $pdo->prepare("SELECT * FROM sold_cars WHERE car_id = ? ORDER BY sold_at ASC");
$stmt->execute([$id]);
$soldRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$sold     = $soldRows[0] ?? null;   // kept for any backward-compat reference

/* ─── Merge movements + sales into ONE chronological stream ─── */
$timelineEvents = [];
foreach ($movements as $mv) {
    $timelineEvents[] = [
        'ts'   => strtotime($mv['created_at'] ?? '') ?: 0,
        'kind' => $mv['event_type'] ?? 'transfer',
        'mv'   => $mv,
    ];
}
foreach ($soldRows as $sr) {
    $timelineEvents[] = [
        'ts'   => strtotime($sr['sold_at'] ?? '') ?: 0,
        'kind' => 'sold',
        'sold' => $sr,
    ];
}
usort($timelineEvents, fn($a, $b) => $a['ts'] <=> $b['ts']);

$daysInStock = floor((time() - strtotime($car['created_at'])) / 86400);

/* ─── Branch name lookup helper ─────────────────────────── */
$branchCache = [];
function getBranchName(PDO $pdo, string $name, string $lang, array &$cache): string {
    if (!$name) return $name;
    if (isset($cache[$name])) return $cache[$name];
    $s = $pdo->prepare("SELECT name_ar, name_en FROM branches WHERE name = ? LIMIT 1");
    $s->execute([$name]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    $result = $row ? ($lang === 'ar' ? $row['name_ar'] : $row['name_en']) : $name;
    $cache[$name] = $result;
    return $result;
}

/* ─── Build route stops ─────────────────────────────────── */
$startRaw   = $totalTransfers ? $movements[0]['from_branch'] : $car['branch'];
$startLabel = getBranchName($pdo, $startRaw, $lang, $branchCache);

$routeStops = [['label' => $startLabel, 'current' => false]];
foreach ($movements as $mv) {
    $routeStops[] = ['label' => getBranchName($pdo, $mv['to_branch'], $lang, $branchCache), 'current' => false];
}
// Mark last as current
$routeStops[count($routeStops)-1]['current'] = true;

/* ─── Status config ─────────────────────────────────────── */
$statusConfig = [
    'available' => ['color' => '#22c55e', 'bg' => 'rgba(34,197,94,.12)',  'icon' => '✅'],
    'sold'      => ['color' => '#ef4444', 'bg' => 'rgba(239,68,68,.12)',  'icon' => '💰'],
    'reserved'  => ['color' => '#f59e0b', 'bg' => 'rgba(245,158,11,.12)', 'icon' => '🔒'],
    'consignment' => ['color' => '#f59e0b', 'bg' => 'rgba(245,158,11,.12)', 'icon' => '🔶'],
];
$sc = $statusConfig[$car['status']] ?? $statusConfig['available'];

?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($car['brand'] . ' ' . $car['model']) ?> — <?= $t[$lang]['title'] ?></title>
<style>
/* ── Reset & tokens ─────────────────────────────────────── */
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

:root {
    --bg-deep:   #020617;
    --bg-card:   rgba(10,18,40,.93);
    --bg-input:  #0d1526;
    --bg-row:    rgba(255,255,255,.025);

    --purple:    #7c3aed;
    --purple-lt: #a855f7;
    --green:     #22c55e;
    --green-lt:  #86efac;
    --amber:     #f59e0b;
    --red:       #ef4444;

    --text:      #e2e8f0;
    --muted:     #64748b;
    --border:    rgba(255,255,255,.07);

    --radius-xl: 28px;
    --radius-lg: 20px;
    --radius-md: 14px;
    --radius-sm: 9px;

    --font: 'Segoe UI', Tahoma, Arial, sans-serif;
    --t: .22s cubic-bezier(.4,0,.2,1);
}

html, body { height:100%; }

body {
    font-family: var(--font);
    background: var(--bg-deep);
    background-image:
        radial-gradient(ellipse 70% 45% at 50% -5%,  rgba(124,58,237,.2),  transparent),
        radial-gradient(ellipse 50% 35% at 90% 90%,  rgba(34,197,94,.1),   transparent),
        radial-gradient(ellipse 40% 30% at 5%  60%,  rgba(124,58,237,.07), transparent);
    min-height:100vh;
    color: var(--text);
    padding: 24px 16px 48px;
    font-size: 15px;
    line-height: 1.6;
}

/* ── Layout ─────────────────────────────────────────────── */
.shell { max-width:1000px; margin:0 auto; display:flex; flex-direction:column; gap:20px; }

/* ── Top bar ────────────────────────────────────────────── */
.topbar {
    display:flex; align-items:center; justify-content:space-between;
    flex-wrap:wrap; gap:12px;
}
.logo-area { display:flex; align-items:center; gap:12px; }
.icon-wrap {
    width:50px; height:50px;
    background:linear-gradient(135deg,var(--purple),var(--green));
    border-radius:var(--radius-md);
    display:grid; place-items:center;
    font-size:24px; flex-shrink:0;
    box-shadow:0 0 24px rgba(124,58,237,.45);
}
.page-title { font-size:clamp(20px,4vw,28px); font-weight:800; letter-spacing:-.5px; }
.page-sub   { font-size:13px; color:var(--muted); }

.topbar-actions { display:flex; gap:8px; }
.btn-ghost {
    padding:8px 16px; border-radius:var(--radius-sm);
    border:1px solid var(--border); background:transparent;
    color:var(--muted); font-size:13px; font-weight:600;
    cursor:pointer; text-decoration:none;
    transition:var(--t); display:inline-flex; align-items:center; gap:6px;
}
.btn-ghost:hover { border-color:var(--purple-lt); color:var(--purple-lt); }

/* ── Card ───────────────────────────────────────────────── */
.card {
    background:var(--bg-card);
    backdrop-filter:blur(28px);
    border:1px solid var(--border);
    border-radius:var(--radius-xl);
    padding:28px;
    box-shadow:0 24px 60px rgba(0,0,0,.4);
}

/* ── Hero header ────────────────────────────────────────── */
.hero {
    display:flex; gap:20px; align-items:flex-start;
    flex-wrap:wrap;
    margin-bottom:28px;
}
.hero-icon {
    width:70px; height:70px; flex-shrink:0;
    background:linear-gradient(135deg,rgba(124,58,237,.35),rgba(34,197,94,.2));
    border-radius:18px;
    display:grid; place-items:center;
    font-size:34px;
    border:1px solid rgba(124,58,237,.3);
}
.hero-text { flex:1; min-width:0; }
.hero-name {
    font-size:clamp(22px,4vw,34px);
    font-weight:900; letter-spacing:-.5px;
    line-height:1.1; margin-bottom:6px;
}
.hero-chassis { font-size:13px; color:var(--muted); margin-bottom:12px; letter-spacing:.05em; font-weight:600; }

.status-pill {
    display:inline-flex; align-items:center; gap:7px;
    padding:7px 18px; border-radius:50px;
    font-weight:700; font-size:14px;
}

/* ── Stats grid ─────────────────────────────────────────── */
.stats-grid {
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(150px,1fr));
    gap:12px;
}
.stat-card {
    background:var(--bg-input);
    border:1px solid var(--border);
    border-radius:var(--radius-md);
    padding:16px;
    transition:border-color var(--t);
}
.stat-card:hover { border-color:rgba(124,58,237,.35); }
.stat-icon { font-size:20px; margin-bottom:8px; }
.stat-label { font-size:11px; color:var(--muted); font-weight:700; text-transform:uppercase; letter-spacing:.08em; margin-bottom:5px; }
.stat-value { font-size:20px; font-weight:800; }

/* ── Section label ──────────────────────────────────────── */
.section-label {
    font-size:11px; font-weight:700; letter-spacing:.12em;
    text-transform:uppercase; color:var(--purple-lt);
    margin-bottom:18px;
    display:flex; align-items:center; gap:10px;
}
.section-label::after { content:''; flex:1; height:1px; background:var(--border); }

/* ── Route map ──────────────────────────────────────────── */
.route-track {
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:0;
    padding: 4px 0;
}

.route-stop {
    display:flex;
    align-items:center;
    gap:14px;
    width:100%;
    max-width:540px;
}

.rs-dot-col {
    display:flex;
    flex-direction:column;
    align-items:center;
    flex-shrink:0;
    width:36px;
}
.rs-dot {
    width:36px; height:36px;
    border-radius:50%;
    background:var(--bg-input);
    border:2px solid var(--purple);
    display:grid; place-items:center;
    font-size:15px;
    position:relative;
    z-index:2;
    transition:var(--t);
}
.rs-dot.current {
    border-color:var(--green);
    background:rgba(34,197,94,.15);
    box-shadow:0 0 18px rgba(34,197,94,.35);
}
.rs-dot.start {
    border-color:var(--purple-lt);
    background:rgba(124,58,237,.15);
}

.rs-line {
    width:2px;
    height:36px;
    background:linear-gradient(180deg,var(--purple),var(--green));
    opacity:.4;
    flex-shrink:0;
}

.rs-label {
    flex:1;
    background:var(--bg-input);
    border:1px solid var(--border);
    border-radius:var(--radius-md);
    padding:12px 16px;
    font-weight:700;
    font-size:14px;
    transition:var(--t);
    margin:6px 0;
}
.rs-label.current {
    border-color:rgba(34,197,94,.4);
    background:rgba(34,197,94,.06);
    color:var(--green-lt);
}
.rs-label.start {
    border-color:rgba(124,58,237,.35);
    background:rgba(124,58,237,.06);
    color:var(--purple-lt);
}
.rs-sublabel { font-size:11px; font-weight:500; color:var(--muted); margin-top:2px; }

/* ── Timeline ───────────────────────────────────────────── */
.timeline {
    position:relative;
    display:flex;
    flex-direction:column;
    gap:0;
}

/* Vertical line */
.timeline::before {
    content:'';
    position:absolute;
    top:18px; bottom:18px;
    width:2px;
    background:linear-gradient(180deg,var(--purple),var(--green));
    opacity:.3;
    border-radius:4px;
}
[dir=ltr] .timeline::before { left:17px; }
[dir=rtl] .timeline::before { right:17px; }

.tl-item {
    display:flex;
    gap:16px;
    align-items:flex-start;
    padding-bottom:24px;
    position:relative;
}

.tl-dot-wrap {
    flex-shrink:0;
    display:flex;
    flex-direction:column;
    align-items:center;
    width:36px;
}
.tl-dot {
    width:36px; height:36px;
    border-radius:50%;
    display:grid; place-items:center;
    font-size:16px;
    position:relative; z-index:2;
    flex-shrink:0;
    border:2px solid transparent;
}
.tl-dot.ev-add      { background:rgba(124,58,237,.2); border-color:var(--purple); }
.tl-dot.ev-transfer { background:rgba(34,197,94,.15); border-color:var(--green); }
.tl-dot.ev-amana { background:rgba(245,158,11,.15); border-color:#f59e0b; }
.tl-dot.ev-amana-return { background:rgba(34,197,94,.15); border-color:#22c55e; }
.tl-dot.ev-sold     { background:rgba(239,68,68,.15); border-color:var(--red); }

.tl-body {
    flex:1;
    background:var(--bg-input);
    border:1px solid var(--border);
    border-radius:var(--radius-md);
    padding:16px 18px;
    margin-top:4px;
    transition:border-color var(--t);
}
.tl-body:hover { border-color:rgba(124,58,237,.3); }

.tl-title { font-size:16px; font-weight:800; margin-bottom:4px; }
.tl-title.ev-add      { color:var(--purple-lt); }
.tl-title.ev-transfer { color:var(--green); }
.tl-title.ev-amana { color:#f59e0b; }
.tl-title.ev-amana-return { color:#22c55e; }
.tl-title.ev-sold     { color:var(--red); }

.tl-date { font-size:12px; color:var(--muted); font-weight:600; margin-bottom:10px; }

.tl-facts { display:flex; flex-direction:column; gap:6px; }
.tl-fact  { display:flex; align-items:baseline; gap:8px; font-size:14px; }
.tl-fact-label { color:var(--muted); font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.05em; white-space:nowrap; }
.tl-fact-value { font-weight:700; }

.tl-note {
    margin-top:10px;
    padding:10px 14px;
    background:rgba(124,58,237,.07);
    border:1px solid rgba(124,58,237,.18);
    border-radius:var(--radius-sm);
    font-size:13px;
    color:#c4b5fd;
}

/* Arrow badge inside transfer */
.branch-flow {
    display:flex; align-items:center; gap:8px; flex-wrap:wrap;
    margin:8px 0 4px;
}
.branch-tag {
    padding:4px 12px; border-radius:20px;
    font-size:13px; font-weight:700;
    background:var(--bg-card);
    border:1px solid var(--border);
}
.branch-arrow { color:var(--purple-lt); font-weight:900; font-size:18px; }

/* ── Footer ─────────────────────────────────────────────── */
.footer-link {
    display:flex; align-items:center; justify-content:center; gap:6px;
    color:var(--muted); font-size:13px; font-weight:600;
    text-decoration:none; margin-top:8px;
    transition:color var(--t);
}
.footer-link:hover { color:var(--text); }

/* ── Responsive ─────────────────────────────────────────── */
@media(max-width:600px){
    body { padding:14px 12px 36px; }
    .card { padding:18px 14px; }
    .hero-icon { width:54px; height:54px; font-size:26px; }
    .stats-grid { grid-template-columns:1fr 1fr; }
    .route-stop { max-width:100%; }
}
</style>
</head>
<body>
<div class="shell">

    <!-- ── Top bar ─────────────────────────────────────── -->
    <div class="topbar">
        <div class="logo-area">
            <div class="icon-wrap">📍</div>
            <div>
                <div class="page-title"><?= $t[$lang]['title'] ?></div>
                <div class="page-sub"><?= htmlspecialchars($car['brand'] . ' · ' . $car['model']) ?></div>
            </div>
        </div>
        <div class="topbar-actions">
            <a href="?id=<?= $id ?>&lang=<?= $lang === 'ar' ? 'en' : 'ar' ?>" class="btn-ghost">
                🌐 <?= $t[$lang]['switch_lang'] ?>
            </a>
            <a href="dashboard.php?lang=<?= $lang ?>" class="btn-ghost">
                <?= $dir === 'rtl' ? '→' : '←' ?> <?= $t[$lang]['back'] ?>
            </a>
        </div>
    </div>

    <!-- ── Hero card ───────────────────────────────────── -->
    <div class="card">
        <div class="hero">
            <div class="hero-icon">🚗</div>
            <div class="hero-text">
                <div class="hero-name">
                    <?= htmlspecialchars($car['brand']) ?>
                    <?= htmlspecialchars($car['model']) ?>
                    <?= htmlspecialchars($car['trim_name']) ?>
                </div>
                <div class="hero-chassis">
                    <?= $t[$lang]['chassis'] ?>: <?= htmlspecialchars($car['chassis']) ?>
                </div>
                <div class="status-pill" style="
                    background:<?= $sc['bg'] ?>;
                    color:<?= $sc['color'] ?>;
                    border:1px solid <?= $sc['color'] ?>44;
                ">
                    <?= $sc['icon'] ?> <?= $t[$lang][$car['status']] ?>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">📍</div>
                <div class="stat-label"><?= $t[$lang]['branch'] ?></div>
                <div class="stat-value" style="font-size:15px"><?= htmlspecialchars($displayBranch) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🏢</div>
                <div class="stat-label"><?= $t[$lang]['branch_type'] ?></div>
                <div class="stat-value" style="font-size:15px"><?= htmlspecialchars($displayBranchType) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📅</div>
                <div class="stat-label"><?= $t[$lang]['days_stock'] ?></div>
                <div class="stat-value"><?= $daysInStock ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🔄</div>
                <div class="stat-label"><?= $t[$lang]['transfers'] ?></div>
                <div class="stat-value"><?= $totalTransfers ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🎨</div>
                <div class="stat-label"><?= $t[$lang]['color'] ?></div>
                <div class="stat-value" style="font-size:15px"><?= htmlspecialchars($displayColor) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">👤</div>
                <div class="stat-label"><?= $t[$lang]['created_by'] ?></div>
                <div class="stat-value" style="font-size:15px"><?= htmlspecialchars($car['created_by']) ?></div>
            </div>
        </div>
    </div>

    <!-- ── Route map ───────────────────────────────────── -->
    <div class="card">
        <div class="section-label">🗺️ <?= $t[$lang]['route_map'] ?> · <?= count($routeStops) ?> <?= $t[$lang]['stops'] ?></div>

        <div class="route-track">
            <?php foreach ($routeStops as $i => $stop):
                $isFirst   = ($i === 0);
                $isCurrent = $stop['current'];
                $isLast    = ($i === count($routeStops) - 1);
                $dotClass  = $isCurrent ? 'current' : ($isFirst ? 'start' : '');
                $lblClass  = $isCurrent ? 'current' : ($isFirst ? 'start' : '');
                $emoji     = $isCurrent ? '✅' : ($isFirst ? '🚗' : '📍');
                $sublabel  = $isCurrent ? $t[$lang]['current_location'] : ($isFirst ? $t[$lang]['journey_start'] : '');
            ?>
            <div class="route-stop">
                <div class="rs-dot-col">
                    <?php if ($i > 0): ?>
                    <div class="rs-line"></div>
                    <?php endif; ?>
                    <div class="rs-dot <?= $dotClass ?>"><?= $emoji ?></div>
                    <?php if (!$isLast): ?>
                    <div class="rs-line"></div>
                    <?php endif; ?>
                </div>
                <div class="rs-label <?= $lblClass ?>">
                    <?= htmlspecialchars($stop['label']) ?>
                    <?php if ($sublabel): ?>
                    <div class="rs-sublabel"><?= $sublabel ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── Timeline ────────────────────────────────────── -->
    <div class="card">
        <div class="section-label">⏱ <?= $t[$lang]['timeline'] ?></div>

        <div class="timeline">

            <!-- Vehicle Added -->
            <div class="tl-item">
                <div class="tl-dot-wrap">
                    <div class="tl-dot ev-add">🚗</div>
                </div>
                <div class="tl-body">
                    <div class="tl-title ev-add"><?= $t[$lang]['added'] ?></div>
                    <div class="tl-date">📅 <?= date('d M Y · h:i A', strtotime($car['created_at'])) ?></div>
                    <div class="tl-facts">
                        <div class="tl-fact">
                            <span class="tl-fact-label"><?= $t[$lang]['branch'] ?></span>
                            <span class="tl-fact-value"><?= htmlspecialchars($startLabel) ?></span>
                        </div>
                        <div class="tl-fact">
                            <span class="tl-fact-label"><?= $t[$lang]['by'] ?></span>
                            <span class="tl-fact-value"><?= htmlspecialchars($car['created_by']) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Transfers, امانة, sale & return events (chronological) -->
            <?php foreach ($timelineEvents as $ev):
                $kind = $ev['kind'];

                // For movement-based events, prep labels + hide امانة from sales.
                if ($kind !== 'sold') {
                    $mv = $ev['mv'];
                    if (($kind === 'amana_out' || $kind === 'amana_return') && !$canSeeAmana) {
                        continue;
                    }
                    $fromLabel = getBranchName($pdo, $mv['from_branch'], $lang, $branchCache);
                    $toLabel   = getBranchName($pdo, $mv['to_branch'],   $lang, $branchCache);
                }
            ?>

            <?php /* ─── SOLD event ─── */ if ($kind === 'sold'):
                $srow            = $ev['sold'];
                $soldBranchLabel = getBranchName($pdo, $srow['sold_branch'], $lang, $branchCache);
                $wasReverted     = (($srow['status'] ?? 'sold') === 'returned');
            ?>
            <div class="tl-item">
                <div class="tl-dot-wrap">
                    <div class="tl-dot ev-sold">💰</div>
                </div>
                <div class="tl-body">
                    <div class="tl-title ev-sold"><?= $t[$lang]['sold_event'] ?></div>
                    <div class="tl-date">📅 <?= date('d M Y · h:i A', strtotime($srow['sold_at'])) ?></div>
                    <div class="tl-facts">
                        <div class="tl-fact">
                            <span class="tl-fact-label"><?= $t[$lang]['branch'] ?></span>
                            <span class="tl-fact-value"><?= htmlspecialchars($soldBranchLabel) ?></span>
                        </div>
                        <div class="tl-fact">
                            <span class="tl-fact-label"><?= $t[$lang]['by'] ?></span>
                            <span class="tl-fact-value"><?= htmlspecialchars($srow['sold_by']) ?></span>
                        </div>
                        <?php if (!empty($srow['customer_name'])): ?>
                        <div class="tl-fact">
                            <span class="tl-fact-label"><?= $t[$lang]['customer'] ?></span>
                            <span class="tl-fact-value"><?= htmlspecialchars($srow['customer_name']) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($srow['dealer_name'])): ?>
                        <div class="tl-fact">
                            <span class="tl-fact-label"><?= $t[$lang]['dealer'] ?></span>
                            <span class="tl-fact-value"><?= htmlspecialchars($srow['dealer_name']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($wasReverted): ?>
                    <div class="tl-note">💬 <?= $t[$lang]['sold_reverted_tag'] ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php /* ─── امانة OUT event ─── */ elseif ($kind === 'amana_out'): ?>
            <div class="tl-item">
                <div class="tl-dot-wrap">
                    <div class="tl-dot ev-amana">🔶</div>
                </div>
                <div class="tl-body">
                    <div class="tl-title ev-amana"><?= $t[$lang]['amana_out_event'] ?></div>
                    <div class="tl-date">📅 <?= date('d M Y · h:i A', strtotime($mv['created_at'])) ?></div>
                    <div class="tl-facts">
                        <?php if (!empty($mv['notes'])): ?>
                        <div class="tl-fact">
                            <span class="tl-fact-label"><?= $t[$lang]['amana_dealer'] ?></span>
                            <span class="tl-fact-value"><?= htmlspecialchars($mv['notes']) ?></span>
                        </div>
                        <?php elseif ($lastDealer !== ''): ?>
                        <div class="tl-fact">
                            <span class="tl-fact-label"><?= $t[$lang]['amana_dealer'] ?></span>
                            <span class="tl-fact-value"><?= htmlspecialchars($lastDealer) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="tl-fact">
                            <span class="tl-fact-label"><?= $t[$lang]['by'] ?></span>
                            <span class="tl-fact-value"><?= htmlspecialchars($mv['moved_by']) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <?php /* ─── امانة RETURN event ─── */ elseif ($kind === 'amana_return'): ?>
            <div class="tl-item">
                <div class="tl-dot-wrap">
                    <div class="tl-dot ev-amana-return">↩️</div>
                </div>
                <div class="tl-body">
                    <div class="tl-title ev-amana-return"><?= $t[$lang]['amana_return_event'] ?></div>
                    <div class="tl-date">📅 <?= date('d M Y · h:i A', strtotime($mv['created_at'])) ?></div>
                    <div class="branch-flow">
                        <span class="branch-tag">📍 <?= htmlspecialchars($fromLabel) ?></span>
                        <span class="branch-arrow"><?= $dir === 'rtl' ? '←' : '→' ?></span>
                        <span class="branch-tag" style="border-color:rgba(34,197,94,.3);color:var(--green-lt)">
                            📍 <?= htmlspecialchars($toLabel) ?>
                        </span>
                    </div>
                    <div class="tl-facts">
                        <div class="tl-fact">
                            <span class="tl-fact-label"><?= $t[$lang]['by'] ?></span>
                            <span class="tl-fact-value"><?= htmlspecialchars($mv['moved_by']) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <?php /* ─── SALE RETURN event (car reverted back to stock) ─── */ elseif ($kind === 'sale_return'): ?>
            <div class="tl-item">
                <div class="tl-dot-wrap">
                    <div class="tl-dot ev-amana-return">↩️</div>
                </div>
                <div class="tl-body">
                    <div class="tl-title ev-amana-return"><?= $t[$lang]['sale_return_event'] ?></div>
                    <div class="tl-date">📅 <?= date('d M Y · h:i A', strtotime($mv['created_at'])) ?></div>
                    <div class="tl-facts">
                        <div class="tl-fact">
                            <span class="tl-fact-label"><?= $t[$lang]['branch'] ?></span>
                            <span class="tl-fact-value"><?= htmlspecialchars($toLabel) ?></span>
                        </div>
                        <div class="tl-fact">
                            <span class="tl-fact-label"><?= $t[$lang]['by'] ?></span>
                            <span class="tl-fact-value"><?= htmlspecialchars($mv['moved_by']) ?></span>
                        </div>
                    </div>
                    <?php if (!empty($mv['notes'])): ?>
                    <div class="tl-note">💬 <?= htmlspecialchars($mv['notes']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php /* ─── Normal transfer ─── */ else: ?>
            <div class="tl-item">
                <div class="tl-dot-wrap">
                    <div class="tl-dot ev-transfer">🔄</div>
                </div>
                <div class="tl-body">
                    <div class="tl-title ev-transfer"><?= $t[$lang]['transferred'] ?></div>
                    <div class="tl-date">📅 <?= date('d M Y · h:i A', strtotime($mv['created_at'])) ?></div>
                    <div class="branch-flow">
                        <span class="branch-tag">📍 <?= htmlspecialchars($fromLabel) ?></span>
                        <span class="branch-arrow"><?= $dir === 'rtl' ? '←' : '→' ?></span>
                        <span class="branch-tag" style="border-color:rgba(34,197,94,.3);color:var(--green-lt)">
                            📍 <?= htmlspecialchars($toLabel) ?>
                        </span>
                    </div>
                    <div class="tl-facts">
                        <div class="tl-fact">
                            <span class="tl-fact-label"><?= $t[$lang]['by'] ?></span>
                            <span class="tl-fact-value"><?= htmlspecialchars($mv['moved_by']) ?></span>
                        </div>
                    </div>
                    <?php if (!empty($mv['notes'])): ?>
                    <div class="tl-note">💬 <?= htmlspecialchars($mv['notes']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; /* event kind */ ?>
            <?php endforeach; ?>

        </div>

        <a href="dashboard.php?lang=<?= $lang ?>" class="footer-link">
            <?= $dir === 'rtl' ? '→' : '←' ?> <?= $t[$lang]['back'] ?>
        </a>
    </div>

</div>
</body>
</html>