<?php
/**
 * price_history.php — First 1 Car
 * ═══════════════════════════════════════════════════════════════
 * Timeline of every pricing change logged by prices.php into
 * pricing_history (that table auto-creates on first save/delete).
 *
 *  - Filters: brand, model/trim search, period
 *  - Stats: changes, increases vs decreases, most-changed model
 *  - Timeline grouped by day: old → new per field, who, when
 *  - SVG price curve of the official price for the most-tracked
 *    model in the current filter (or the only one, when narrowed)
 *
 * Admin / Manager only — trade prices appear here.
 */

require 'auth.php';
require 'config.php';

$lang = $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar', 'en'], true)) $lang = 'ar';
$dir = $lang === 'ar' ? 'rtl' : 'ltr';

$role = $_SESSION['role'] ?? 'sales';
perm_require('page.price_history');

/* ─── Translations ─────────────────────────────────────────── */
$t = [
    'ar' => [
        'title'        => 'سجل الأسعار',
        'subtitle'     => 'كل تغييرات الأسعار — من غيّر، ماذا، ومتى',
        'dashboard'    => 'الرئيسية',
        'prices'       => 'الأسعار',
        'all_brands'   => 'كل الماركات',
        'search_ph'    => 'بحث بالموديل أو الفئة...',
        'period'       => 'الفترة',
        'p30'          => 'آخر 30 يوم',
        'p90'          => 'آخر 90 يوم',
        'p365'         => 'آخر سنة',
        'pall'         => 'كل الوقت',
        'filter'       => 'عرض',
        'st_changes'   => 'تغيير',
        'st_up'        => 'زيادة سعر',
        'st_down'      => 'تخفيض سعر',
        'st_top'       => 'الأكثر تغييراً',
        'chart_title'  => '📈 منحنى السعر الرسمي',
        'official'     => 'الرسمي',
        'customer'     => 'العميل',
        'trade'        => 'التجاري',
        'created'      => '🆕 سعر جديد',
        'updated'      => '✏️ تعديل',
        'deleted'      => '🗑️ حذف',
        'by'           => 'بواسطة',
        'empty'        => 'لا توجد تغييرات مسجّلة في هذه الفترة بعد — السجل يبدأ من أول تعديل سعر بعد تفعيل هذه الميزة',
        'was_empty'    => '—',
        'egp'          => 'ج.م',
        'switch_lang'  => 'English',
    ],
    'en' => [
        'title'        => 'Price History',
        'subtitle'     => 'Every pricing change — who, what, and when',
        'dashboard'    => 'Dashboard',
        'prices'       => 'Prices',
        'all_brands'   => 'All Brands',
        'search_ph'    => 'Search model or trim...',
        'period'       => 'Period',
        'p30'          => 'Last 30 days',
        'p90'          => 'Last 90 days',
        'p365'         => 'Last year',
        'pall'         => 'All time',
        'filter'       => 'Show',
        'st_changes'   => 'changes',
        'st_up'        => 'price increases',
        'st_down'      => 'price cuts',
        'st_top'       => 'most changed',
        'chart_title'  => '📈 Official Price Curve',
        'official'     => 'Official',
        'customer'     => 'Customer',
        'trade'        => 'Trade',
        'created'      => '🆕 New price',
        'updated'      => '✏️ Update',
        'deleted'      => '🗑️ Deleted',
        'by'           => 'by',
        'empty'        => 'No changes recorded in this period yet — history starts from the first price edit after this feature went live',
        'was_empty'    => '—',
        'egp'          => 'EGP',
        'switch_lang'  => 'العربية',
    ],
];
$T = $t[$lang];

/* ─── Ensure table exists (page may be opened before first log) ── */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pricing_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        brand VARCHAR(100) NOT NULL,
        model_name VARCHAR(150) NOT NULL,
        trim_name VARCHAR(150) NOT NULL,
        car_year VARCHAR(20) NOT NULL,
        change_type VARCHAR(10) NOT NULL,
        old_official VARCHAR(100) NULL,
        new_official VARCHAR(100) NULL,
        old_customer VARCHAR(100) NULL,
        new_customer VARCHAR(100) NULL,
        old_trade VARCHAR(100) NULL,
        new_trade VARCHAR(100) NULL,
        changed_by VARCHAR(100) NOT NULL,
        changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_model (brand, model_name, trim_name, car_year),
        INDEX idx_time (changed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { error_log('pricing_history create failed: ' . $e->getMessage()); }

/* ─── Filters ─────────────────────────────────────────────── */
$fBrand  = trim($_GET['brand']  ?? '');
$fSearch = trim($_GET['search'] ?? '');
$fDays   = (int)($_GET['days'] ?? 90);
if (!in_array($fDays, [30, 90, 365, 0], true)) $fDays = 90;

$where = []; $args = [];
if ($fBrand !== '')  { $where[] = "brand = ?"; $args[] = $fBrand; }
if ($fSearch !== '') { $where[] = "(model_name LIKE ? OR trim_name LIKE ?)"; $args[] = "%$fSearch%"; $args[] = "%$fSearch%"; }
if ($fDays > 0)      { $where[] = "changed_at >= DATE_SUB(NOW(), INTERVAL $fDays DAY)"; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$rows = $pdo->prepare("SELECT * FROM pricing_history $whereSql ORDER BY changed_at DESC, id DESC LIMIT 500");
$rows->execute($args);
$rows = $rows->fetchAll(PDO::FETCH_ASSOC);

$brands = $pdo->query("SELECT DISTINCT brand FROM pricing_history ORDER BY brand")->fetchAll(PDO::FETCH_COLUMN);

/* ─── Analytics ───────────────────────────────────────────── */
function numPrice($v) {
    $n = preg_replace('/[^0-9.]/', '', (string)$v);
    return ($n !== '' && is_numeric($n)) ? (float)$n : null;
}

$ups = 0; $downs = 0; $modelCounts = []; $series = [];
foreach ($rows as $r) {
    $key = $r['brand'] . ' ' . $r['model_name'] . ' ' . $r['trim_name'] . ' ' . $r['car_year'];
    $modelCounts[$key] = ($modelCounts[$key] ?? 0) + 1;

    $o = numPrice($r['old_official']); $n = numPrice($r['new_official']);
    if ($o !== null && $n !== null && $n > $o) $ups++;
    if ($o !== null && $n !== null && $n < $o) $downs++;

    if ($n !== null && $r['change_type'] !== 'delete') {
        $series[$key][] = ['t' => $r['changed_at'], 'v' => $n];
    }
}
arsort($modelCounts);
$topModel = $modelCounts ? array_key_first($modelCounts) : null;

/* pick the series with the most points (min 2) for the curve */
$chartKey = null; $chartPts = [];
foreach ($series as $k => $pts) {
    if (count($pts) >= 2 && count($pts) > count($chartPts)) { $chartKey = $k; $chartPts = $pts; }
}
if ($chartPts) usort($chartPts, fn($a, $b) => strcmp($a['t'], $b['t']));

/* group rows by day for the timeline */
$byDay = [];
foreach ($rows as $r) $byDay[substr($r['changed_at'], 0, 10)][] = $r;

function fmtN($v) {
    $n = numPrice($v);
    return $n !== null ? number_format($n) : htmlspecialchars((string)$v);
}

/* customer/trade values are TEXT deal labels ("رسمي", "أوفر 5,000",
   "خصم 30,000") — the word IS the meaning, so they are always shown
   verbatim as colored badges and NEVER passed through number math. */
function labelBadge($val) {
    $val = (string)$val;
    if ($val === '') return '<span style="color:#64748b">—</span>';
    $isOfficial = (mb_strpos($val, 'رسمي') !== false) || (stripos($val, 'Official') !== false);
    $isOffer    = (mb_strpos($val, 'أوفر') !== false) || (stripos($val, 'Offer') !== false);
    $isDisc     = (mb_strpos($val, 'خصم')  !== false) || (stripos($val, 'Discount') !== false);
    if ($isOfficial)  { $cls = 'lbl-official'; $icon = '📢'; }
    elseif ($isOffer) { $cls = 'lbl-offer';    $icon = '⬆️'; }
    elseif ($isDisc)  { $cls = 'lbl-disc';     $icon = '⬇️'; }
    else              { $cls = 'lbl-plain';    $icon = '';   }
    return '<span class="deal-lbl ' . $cls . '">' . $icon . ' ' . htmlspecialchars($val) . '</span>';
}
function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#0f172a">
<title><?= $T['title'] ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root {
    --green:#22c55e; --purple:#9333ea; --blue:#2563eb; --amber:#f59e0b; --red:#ef4444;
    --bg-card:rgba(15,23,42,.90); --border:rgba(255,255,255,.08);
    --text:#f1f5f9; --muted:#94a3b8; --muted-d:#64748b;
}
html[lang="ar"] body { font-family:'Cairo','Segoe UI',Tahoma,sans-serif; }
html[lang="en"] body { font-family:'Inter','Segoe UI',Tahoma,sans-serif; }
body { background:linear-gradient(135deg,#020617,#0f172a); color:var(--text); min-height:100vh; padding-bottom:60px; }
.container { max-width:920px; margin:auto; padding:18px; }

.page-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:18px; flex-wrap:wrap; }
.page-title { font-size:24px; font-weight:900; display:flex; align-items:center; gap:10px; }
.page-sub { color:var(--muted); font-size:13px; margin-top:2px; }
.head-links { display:flex; gap:8px; flex-wrap:wrap; }
.head-links a {
    color:var(--text); text-decoration:none; font-size:13px; font-weight:700;
    background:rgba(255,255,255,.05); border:1px solid var(--border);
    padding:8px 14px; border-radius:12px; transition:.2s;
}
.head-links a:hover { background:rgba(255,255,255,.1); }

.card {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:20px; padding:18px; margin-bottom:16px;
    box-shadow:0 8px 32px rgba(0,0,0,.35);
}

/* ── Filters ── */
.filters { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
.filters select, .filters input {
    background:#0b1120; color:var(--text); border:1px solid var(--border);
    border-radius:12px; padding:11px 13px; font-size:13.5px; font-family:inherit; outline:none;
}
.filters input { flex:1; min-width:160px; }
.filters select:focus, .filters input:focus { border-color:var(--amber); }
.filters button {
    background:linear-gradient(135deg,var(--amber),#d97706); color:#0b1120;
    border:none; border-radius:12px; padding:11px 22px; font-size:13.5px;
    font-weight:900; cursor:pointer; font-family:inherit;
}

/* ── Stats ── */
.stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:16px; }
.stat {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:16px; padding:14px 16px;
}
.stat .num { font-size:22px; font-weight:900; }
.stat .lbl { color:var(--muted); font-size:12px; margin-top:3px; }
.stat.up   .num { color:var(--green); }
.stat.down .num { color:var(--red); }
.stat.top  .num { font-size:14px; line-height:1.5; color:var(--amber); }

/* ── Chart ── */
.chart-card h3 { font-size:15px; font-weight:800; margin-bottom:4px; }
.chart-model { color:var(--muted); font-size:12.5px; margin-bottom:12px; }
.chart-wrap { width:100%; overflow-x:auto; }
.chart-wrap svg { display:block; width:100%; height:180px; }

/* ── Timeline ── */
.day-head {
    font-size:13px; font-weight:800; color:var(--muted);
    margin:20px 4px 10px; display:flex; align-items:center; gap:10px;
}
.day-head::after { content:''; flex:1; height:1px; background:var(--border); }
.entry {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:16px; padding:14px 16px; margin-bottom:10px;
}
.entry-top { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:10px; }
.entry-model { font-weight:900; font-size:14.5px; }
.badge {
    font-size:11px; font-weight:800; border-radius:999px; padding:3px 11px;
}
.badge.create { background:rgba(34,197,94,.12);  color:var(--green);  border:1px solid rgba(34,197,94,.3); }
.badge.update { background:rgba(245,158,11,.12); color:var(--amber);  border:1px solid rgba(245,158,11,.3); }
.badge.delete { background:rgba(239,68,68,.12);  color:#fca5a5;       border:1px solid rgba(239,68,68,.3); }
.entry-meta { margin-inline-start:auto; color:var(--muted-d); font-size:11.5px; }
.changes { display:flex; flex-direction:column; gap:7px; }
.chg { display:flex; align-items:center; gap:9px; font-size:13px; flex-wrap:wrap; }
.chg .fld { color:var(--muted); font-size:11.5px; font-weight:700; min-width:58px; }
.chg .old { color:var(--muted-d); text-decoration:line-through; }
.chg .arrow { color:var(--muted-d); }
.chg .new { font-weight:800; }
.delta { font-size:11.5px; font-weight:800; border-radius:8px; padding:2px 8px; }
.delta.up   { background:rgba(239,68,68,.12);  color:#fca5a5; }
.delta.down { background:rgba(34,197,94,.12);  color:var(--green); }
.deal-lbl {
    display:inline-flex; align-items:center; gap:4px;
    font-size:12px; font-weight:800; border-radius:999px; padding:3px 11px;
}
.lbl-official { background:rgba(34,197,94,.15);  color:#4ade80; border:1px solid rgba(34,197,94,.35); }
.lbl-offer    { background:rgba(34,197,94,.12);  color:#4ade80; border:1px solid rgba(34,197,94,.3); }
.lbl-disc     { background:rgba(239,68,68,.12);  color:#f87171; border:1px solid rgba(239,68,68,.3); }
.lbl-plain    { background:rgba(255,255,255,.06); color:var(--text); border:1px solid var(--border); }
.chg .old-lbl { opacity:.45; }
.chg .old-lbl .deal-lbl { text-decoration:line-through; }
.empty-state { text-align:center; color:var(--muted); padding:44px 16px; font-size:14px; line-height:1.8; }
</style>
</head>
<body>
<div class="container">

    <div class="page-head">
        <div>
            <div class="page-title">📈 <?= $T['title'] ?></div>
            <div class="page-sub"><?= $T['subtitle'] ?></div>
        </div>
        <div class="head-links">
            <a href="prices.php?lang=<?= $lang ?>">💰 <?= $T['prices'] ?></a>
            <a href="dashboard.php?lang=<?= $lang ?>">🏠 <?= $T['dashboard'] ?></a>
            <a href="?lang=<?= $lang === 'ar' ? 'en' : 'ar' ?>&brand=<?= urlencode($fBrand) ?>&search=<?= urlencode($fSearch) ?>&days=<?= $fDays ?>">🌐 <?= $T['switch_lang'] ?></a>
        </div>
    </div>

    <!-- ── Filters ── -->
    <div class="card">
        <form class="filters" method="GET">
            <input type="hidden" name="lang" value="<?= $lang ?>">
            <select name="brand">
                <option value=""><?= $T['all_brands'] ?></option>
                <?php foreach ($brands as $b): ?>
                <option value="<?= esc($b) ?>" <?= $fBrand === $b ? 'selected' : '' ?>><?= esc($b) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="search" placeholder="<?= $T['search_ph'] ?>" value="<?= esc($fSearch) ?>">
            <select name="days">
                <option value="30"  <?= $fDays === 30  ? 'selected' : '' ?>><?= $T['p30'] ?></option>
                <option value="90"  <?= $fDays === 90  ? 'selected' : '' ?>><?= $T['p90'] ?></option>
                <option value="365" <?= $fDays === 365 ? 'selected' : '' ?>><?= $T['p365'] ?></option>
                <option value="0"   <?= $fDays === 0   ? 'selected' : '' ?>><?= $T['pall'] ?></option>
            </select>
            <button type="submit"><?= $T['filter'] ?></button>
        </form>
    </div>

    <?php if ($rows): ?>
    <!-- ── Stats ── -->
    <div class="stats">
        <div class="stat"><div class="num"><?= count($rows) ?></div><div class="lbl"><?= $T['st_changes'] ?></div></div>
        <div class="stat up"><div class="num">▲ <?= $ups ?></div><div class="lbl"><?= $T['st_up'] ?></div></div>
        <div class="stat down"><div class="num">▼ <?= $downs ?></div><div class="lbl"><?= $T['st_down'] ?></div></div>
        <?php if ($topModel): ?>
        <div class="stat top"><div class="num"><?= esc($topModel) ?></div><div class="lbl"><?= $T['st_top'] ?> (<?= $modelCounts[$topModel] ?>)</div></div>
        <?php endif; ?>
    </div>

    <?php if (count($chartPts) >= 2):
        /* ── SVG price curve ── */
        $W = 800; $H = 180; $padX = 48; $padY = 26;
        $vals = array_column($chartPts, 'v');
        $min = min($vals); $max = max($vals);
        if ($max == $min) { $max += 1; }
        $n = count($chartPts);
        $pts = [];
        foreach ($chartPts as $i => $p) {
            $x = $padX + ($W - 2 * $padX) * ($n > 1 ? $i / ($n - 1) : 0.5);
            $y = $H - $padY - ($H - 2 * $padY) * (($p['v'] - $min) / ($max - $min));
            $pts[] = [round($x, 1), round($y, 1), $p];
        }
        $poly = implode(' ', array_map(fn($p) => $p[0] . ',' . $p[1], $pts));
        $areaPoly = $padX . ',' . ($H - $padY) . ' ' . $poly . ' ' . $pts[count($pts)-1][0] . ',' . ($H - $padY);
    ?>
    <div class="card chart-card">
        <h3><?= $T['chart_title'] ?></h3>
        <div class="chart-model"><?= esc($chartKey) ?> · <?= number_format($min) ?> → <?= number_format($max) ?> <?= $T['egp'] ?></div>
        <div class="chart-wrap">
            <svg viewBox="0 0 <?= $W ?> <?= $H ?>" preserveAspectRatio="none">
                <defs>
                    <linearGradient id="ag" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%"  stop-color="rgba(245,158,11,.30)"/>
                        <stop offset="100%" stop-color="rgba(245,158,11,0)"/>
                    </linearGradient>
                </defs>
                <line x1="<?= $padX ?>" y1="<?= $H - $padY ?>" x2="<?= $W - $padX ?>" y2="<?= $H - $padY ?>" stroke="rgba(255,255,255,.1)" stroke-width="1"/>
                <text x="<?= $padX ?>" y="14" fill="#94a3b8" font-size="11"><?= number_format($max) ?></text>
                <text x="<?= $padX ?>" y="<?= $H - 6 ?>" fill="#64748b" font-size="11"><?= number_format($min) ?></text>
                <polygon points="<?= $areaPoly ?>" fill="url(#ag)"/>
                <polyline points="<?= $poly ?>" fill="none" stroke="#f59e0b" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
                <?php foreach ($pts as $p): ?>
                <circle cx="<?= $p[0] ?>" cy="<?= $p[1] ?>" r="3.5" fill="#f59e0b" stroke="#0f172a" stroke-width="1.5">
                    <title><?= esc(substr($p[2]['t'], 0, 16)) ?> — <?= number_format($p[2]['v']) ?></title>
                </circle>
                <?php endforeach; ?>
            </svg>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Timeline ── -->
    <?php foreach ($byDay as $day => $entries): ?>
        <div class="day-head">📅 <?= esc($day) ?></div>
        <?php foreach ($entries as $r):
            $type = in_array($r['change_type'], ['create','update','delete'], true) ? $r['change_type'] : 'update';
            $badgeTxt = $type === 'create' ? $T['created'] : ($type === 'delete' ? $T['deleted'] : $T['updated']);
            $fields = [
                'official' => [$T['official'], $r['old_official'], $r['new_official']],
                'customer' => [$T['customer'], $r['old_customer'], $r['new_customer']],
                'trade'    => [$T['trade'],    $r['old_trade'],    $r['new_trade']],
            ];
        ?>
        <div class="entry">
            <div class="entry-top">
                <span class="badge <?= $type ?>"><?= $badgeTxt ?></span>
                <span class="entry-model">🚗 <?= esc($r['brand'] . ' ' . $r['model_name'] . ' ' . $r['trim_name']) ?> · <?= esc($r['car_year']) ?></span>
                <span class="entry-meta"><?= $T['by'] ?> <strong><?= esc($r['changed_by']) ?></strong> · <?= esc(substr($r['changed_at'], 11, 5)) ?></span>
            </div>
            <div class="changes">
                <?php foreach ($fields as $fk => [$label, $old, $new]):
                    $old = (string)($old ?? ''); $new = (string)($new ?? '');
                    if ($type === 'update' && $old === $new) continue;      /* unchanged field */
                    if ($type === 'create' && $new === '') continue;        /* empty on create */
                    if ($type === 'delete' && $old === '') continue;        /* empty on delete */
                    $oN = numPrice($old); $nN = numPrice($new);
                    $delta = ($fk === 'official' && $oN !== null && $nN !== null && $oN != $nN)
                           ? ($nN - $oN) : null;
                ?>
                <div class="chg">
                    <span class="fld"><?= $label ?></span>
                    <?php if ($fk === 'official'): /* numeric field: format + delta */ ?>
                        <?php if ($type === 'create'): ?>
                            <span class="new" style="color:var(--green)"><?= fmtN($new) ?></span>
                        <?php elseif ($type === 'delete'): ?>
                            <span class="old"><?= fmtN($old) ?></span>
                        <?php else: ?>
                            <span class="old"><?= $old !== '' ? fmtN($old) : $T['was_empty'] ?></span>
                            <span class="arrow"><?= $dir === 'rtl' ? '←' : '→' ?></span>
                            <span class="new" style="color:<?= $delta !== null ? ($delta > 0 ? '#fca5a5' : 'var(--green)') : 'var(--text)' ?>">
                                <?= $new !== '' ? fmtN($new) : $T['was_empty'] ?>
                            </span>
                            <?php if ($delta !== null): ?>
                            <span class="delta <?= $delta > 0 ? 'up' : 'down' ?>">
                                <?= $delta > 0 ? '▲ +' : '▼ −' ?><?= number_format(abs($delta)) ?>
                                (<?= $oN > 0 ? number_format(abs($delta) / $oN * 100, 1) : '0' ?>%)
                            </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php else: /* deal-label field: verbatim colored badges, no math */ ?>
                        <?php if ($type === 'create'): ?>
                            <?= labelBadge($new) ?>
                        <?php elseif ($type === 'delete'): ?>
                            <span class="old-lbl"><?= labelBadge($old) ?></span>
                        <?php else: ?>
                            <span class="old-lbl"><?= labelBadge($old) ?></span>
                            <span class="arrow"><?= $dir === 'rtl' ? '←' : '→' ?></span>
                            <?= labelBadge($new) ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <?php else: ?>
    <div class="card"><div class="empty-state">📭 <?= $T['empty'] ?></div></div>
    <?php endif; ?>

</div>
</body>
</html>
