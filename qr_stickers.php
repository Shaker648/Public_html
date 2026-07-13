<?php
/**
 * qr_stickers.php — First 1 Car · QR sticker printer
 * ═══════════════════════════════════════════════════════════════
 * Print QR labels for cars. Each sticker encodes qr.php?c=CHASSIS:
 *  - any phone camera → opens the car's identity card (behind login)
 *  - the in-app chassis scanners → auto-extract the chassis
 *
 * Two modes in one page:
 *  SELECTION  — filter (branch / brand / status / search), tick cars
 *  PRINT      — A4 sheets, small (10/page) or large (4/page) stickers,
 *               QRs drawn client-side (qrcode lib, CDN + fallback)
 *
 * Preselect support: ?chassis=A,B,C (used by receive_shipment's
 * success screen to print labels for a just-received batch).
 *
 * Admin / Manager only.
 */

require 'auth.php';
require 'config.php';

$lang = $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar', 'en'], true)) $lang = 'ar';
$dir = $lang === 'ar' ? 'rtl' : 'ltr';

$role = $_SESSION['role'] ?? 'sales';
perm_require('page.qr_stickers');

$t = [
    'ar' => [
        'title'        => 'ملصقات QR للسيارات',
        'subtitle'     => 'اطبع ملصقاً لكل سيارة — مسحة واحدة تفتح بطاقة السيارة فوراً',
        'dashboard'    => 'الرئيسية',
        'all_branches' => 'كل الفروع',
        'all_brands'   => 'كل الماركات',
        'all_status'   => 'كل الحالات',
        'available'    => 'متاحة',
        'reserved'     => 'محجوزة',
        'consignment'  => 'امانة',
        'search_ph'    => 'بحث بالشاسيه أو الموديل...',
        'filter'       => 'عرض',
        'select_all'   => 'تحديد الكل',
        'clear_all'    => 'إلغاء الكل',
        'selected'     => 'محدد',
        'make'         => '🏷️ توليد الملصقات',
        'no_cars'      => 'لا توجد سيارات مطابقة',
        'size'         => 'الحجم',
        'size_small'   => 'صغير (10 / صفحة)',
        'size_large'   => 'كبير (4 / صفحة)',
        'print'        => '🖨️ طباعة',
        'back'         => '← رجوع للاختيار',
        'print_hint'   => 'استخدم ورق ملصقات A4 · اجعل هوامش الطباعة None/بلا · عطّل Headers and Footers',
        'switch'       => 'English',
    ],
    'en' => [
        'title'        => 'Car QR Stickers',
        'subtitle'     => 'Print a sticker per car — one scan opens its card instantly',
        'dashboard'    => 'Dashboard',
        'all_branches' => 'All Branches',
        'all_brands'   => 'All Brands',
        'all_status'   => 'All Statuses',
        'available'    => 'Available',
        'reserved'     => 'Reserved',
        'consignment'  => 'Consignment',
        'search_ph'    => 'Search chassis or model...',
        'filter'       => 'Show',
        'select_all'   => 'Select all',
        'clear_all'    => 'Clear all',
        'selected'     => 'selected',
        'make'         => '🏷️ Generate Stickers',
        'no_cars'      => 'No matching cars',
        'size'         => 'Size',
        'size_small'   => 'Small (10 / page)',
        'size_large'   => 'Large (4 / page)',
        'print'        => '🖨️ Print',
        'back'         => '← Back to selection',
        'print_hint'   => 'Use A4 label paper · set print margins to None · disable Headers and Footers',
        'switch'       => 'العربية',
    ],
];
$T = $t[$lang];

/* Absolute base URL for the QR payload */
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$qrBase  = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'stock.first1car.net') . '/qr.php?c=';

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES); }

/* ─── PRINT MODE? (ids or chassis provided) ─────────────── */
$printIds     = array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')));
$printChassis = array_filter(array_map(fn($c) => strtoupper(trim($c)), explode(',', $_GET['chassis'] ?? '')));
$printMode    = !empty($printIds) || !empty($printChassis);
$size         = ($_GET['size'] ?? 'small') === 'large' ? 'large' : 'small';

if ($printMode) {
    $sel = "SELECT cars.*, b.name_ar AS b_ar, b.name_en AS b_en
            FROM cars LEFT JOIN branches b ON b.name = cars.branch";
    if ($printIds) {
        $ph = implode(',', array_fill(0, count($printIds), '?'));
        $stmt = $pdo->prepare("$sel WHERE cars.id IN ($ph) ORDER BY cars.brand, cars.model, cars.id");
        $stmt->execute($printIds);
    } else {
        $ph = implode(',', array_fill(0, count($printChassis), 'UPPER(?)'));
        $stmt = $pdo->prepare("$sel WHERE UPPER(cars.chassis) IN ($ph) ORDER BY cars.brand, cars.model, cars.id");
        $stmt->execute($printChassis);
    }
    $cars = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    /* ─── SELECTION MODE ─── */
    $fBranch = trim($_GET['branch'] ?? '');
    $fBrand  = trim($_GET['brand']  ?? '');
    $fStatus = trim($_GET['status'] ?? 'available');
    $fSearch = trim($_GET['search'] ?? '');

    $where = []; $args = [];
    if ($fBranch !== '') { $where[] = "branch = ?"; $args[] = $fBranch; }
    if ($fBrand  !== '') { $where[] = "brand = ?";  $args[] = $fBrand; }
    if ($fStatus !== '') { $where[] = "status = ?"; $args[] = $fStatus; }
    if ($fSearch !== '') {
        $where[] = "(chassis LIKE ? OR model LIKE ? OR trim_name LIKE ?)";
        $args[] = "%$fSearch%"; $args[] = "%$fSearch%"; $args[] = "%$fSearch%";
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $stmt = $pdo->prepare("SELECT * FROM cars $whereSql ORDER BY id DESC LIMIT 300");
    $stmt->execute($args);
    $cars = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $branches = $pdo->query("SELECT * FROM branches ORDER BY name_en")->fetchAll(PDO::FETCH_ASSOC);
    $brandsL  = $pdo->query("SELECT DISTINCT brand FROM cars ORDER BY brand")->fetchAll(PDO::FETCH_COLUMN);
}
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
body { background:linear-gradient(135deg,#020617,#0f172a); color:var(--text); min-height:100vh; padding-bottom:110px; }
.container { max-width:920px; margin:auto; padding:18px; }

.page-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:18px; flex-wrap:wrap; }
.page-title { font-size:24px; font-weight:900; }
.page-sub { color:var(--muted); font-size:13px; margin-top:2px; }
.head-links { display:flex; gap:8px; flex-wrap:wrap; }
.head-links a, .head-links button {
    color:var(--text); text-decoration:none; font-size:13px; font-weight:700; cursor:pointer;
    background:rgba(255,255,255,.05); border:1px solid var(--border);
    padding:8px 14px; border-radius:12px; font-family:inherit;
}
.card {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:20px; padding:16px; margin-bottom:14px;
}

/* ── Filters ── */
.filters { display:flex; gap:10px; flex-wrap:wrap; }
.filters select, .filters input {
    background:#0b1120; color:var(--text); border:1px solid var(--border);
    border-radius:12px; padding:11px 13px; font-size:13.5px; font-family:inherit; outline:none;
}
.filters input { flex:1; min-width:150px; }
.filters button {
    background:linear-gradient(135deg,var(--blue),#1d4ed8); color:#fff;
    border:none; border-radius:12px; padding:11px 22px; font-weight:900;
    font-size:13.5px; cursor:pointer; font-family:inherit;
}

/* ── Selection list ── */
.sel-tools { display:flex; gap:8px; margin-bottom:12px; flex-wrap:wrap; }
.sel-tools button {
    background:rgba(255,255,255,.05); border:1px solid var(--border); color:var(--text);
    border-radius:10px; padding:8px 14px; font-size:12.5px; font-weight:700;
    cursor:pointer; font-family:inherit;
}
.car-row {
    display:flex; align-items:center; gap:12px;
    background:rgba(255,255,255,.03); border:1px solid var(--border);
    border-radius:14px; padding:11px 14px; margin-bottom:8px; cursor:pointer;
    transition:.15s;
}
.car-row:hover { border-color:rgba(37,99,235,.4); }
.car-row.on { border-color:var(--green); background:rgba(34,197,94,.06); }
.car-row input { width:18px; height:18px; accent-color:var(--green); flex-shrink:0; }
.car-row .info { min-width:0; }
.car-row .nm { font-weight:800; font-size:14px; }
.car-row .mt { color:var(--muted); font-size:12px; margin-top:2px; display:flex; gap:10px; flex-wrap:wrap; }
.car-row .ch { font-family:'Inter',monospace; letter-spacing:.5px; }
.empty-state { text-align:center; color:var(--muted); padding:36px 12px; font-size:14px; }

/* ── Sticky make bar ── */
.make-bar {
    position:fixed; bottom:0; left:0; right:0; z-index:50;
    background:rgba(2,6,23,.92); backdrop-filter:blur(14px);
    border-top:1px solid var(--border); padding:12px 16px; display:none;
}
.make-bar.show { display:block; }
.make-inner { max-width:920px; margin:auto; display:flex; align-items:center; gap:14px; }
.make-count { font-size:13.5px; color:var(--muted); }
.make-count strong { color:var(--green); font-size:16px; }
.make-inner select {
    background:#0b1120; color:var(--text); border:1px solid var(--border);
    border-radius:12px; padding:10px 12px; font-size:13px; font-family:inherit;
}
.btn-make {
    margin-inline-start:auto;
    background:linear-gradient(135deg,var(--green),#16a34a); color:#fff;
    border:none; border-radius:14px; padding:13px 24px;
    font-size:14.5px; font-weight:900; cursor:pointer; font-family:inherit;
    box-shadow:0 6px 20px rgba(34,197,94,.35);
}

/* ══ PRINT MODE ══ */
.print-hint { color:var(--muted); font-size:12.5px; line-height:1.7; }

/* screen preview: responsive, nothing clipped */
.sheet {
    background:#fff; border-radius:14px; margin:0 auto 16px;
    width:100%; max-width:820px; padding:14px;
    display:grid; grid-template-columns:1fr 1fr; gap:12px;
    box-shadow:0 8px 30px rgba(0,0,0,.35);
}
@media (max-width:640px){ .sheet { grid-template-columns:1fr; } }

.sticker {
    border:1.5px solid #cbd5e1; border-radius:12px; background:#fff; color:#0f172a;
    display:flex; align-items:center; gap:12px; padding:12px;
    break-inside:avoid; overflow:hidden; position:relative;
}
.sticker::before {           /* brand accent bar */
    content:''; position:absolute; top:0; bottom:0; inset-inline-start:0;
    width:4px; background:linear-gradient(180deg,#22c55e,#2563eb);
}
.sticker .qr { flex-shrink:0; width:118px; height:118px; }
.sheet.large .sticker .qr { width:158px; height:158px; }
.sticker .qr img, .sticker .qr canvas { width:100% !important; height:100% !important; display:block; }
.sticker .stx { min-width:0; flex:1; }
.sticker .brandline {
    font-size:9.5px; font-weight:900; color:#64748b;
    letter-spacing:2px; text-transform:uppercase;
}
.sticker .chx {
    font-family:'Inter',monospace; font-weight:800; letter-spacing:1.5px;
    font-size:17px; margin:3px 0 4px; word-break:break-all; color:#0f172a;
}
.sheet.large .sticker .chx { font-size:22px; }
.sticker .mdl { font-size:13.5px; font-weight:900; color:#1e293b; line-height:1.35; }
.sheet.large .sticker .mdl { font-size:16px; }
.sticker .trm { font-size:12px; font-weight:700; color:#334155; margin-top:1px; }
.sheet.large .sticker .trm { font-size:13.5px; }
.sticker .meta {
    font-size:11px; color:#475569; margin-top:5px;
    display:flex; flex-wrap:wrap; gap:4px 10px; font-weight:600;
}
.sheet.large .sticker .meta { font-size:12.5px; }

/* real paper: exact A4 geometry only when actually printing */
@media print {
    body { background:#fff !important; padding:0 !important; }
    .no-print { display:none !important; }
    .container { max-width:none; padding:0; }
    .sheet {
        width:auto; max-width:none; margin:0; padding:6mm; border-radius:0;
        box-shadow:none; grid-template-columns:1fr 1fr !important; gap:4mm;
        page-break-after:always;
    }
    .sticker { border-style:solid; border-color:#e2e8f0; border-radius:3mm; padding:4mm; gap:4mm; }
    .sheet.small .sticker { height:50mm; }
    .sheet.large .sticker { height:64mm; }
    .sheet.small .sticker .qr { width:38mm; height:38mm; }
    .sheet.large .sticker .qr { width:52mm; height:52mm; }
    .sheet.small .sticker .chx { font-size:13pt; }
    .sheet.large .sticker .chx { font-size:17pt; }
    .sheet.small .sticker .mdl { font-size:10pt; }
    .sheet.large .sticker .mdl { font-size:12pt; }
    .sticker .trm  { font-size:9pt; }
    .sticker .meta { font-size:8pt; }
    .sticker::before { print-color-adjust:exact; -webkit-print-color-adjust:exact; }
}
</style>
</head>
<body>
<div class="container">

    <div class="page-head no-print">
        <div>
            <div class="page-title">🏷️ <?= $T['title'] ?></div>
            <div class="page-sub"><?= $T['subtitle'] ?></div>
        </div>
        <div class="head-links">
            <?php if ($printMode): ?>
            <a href="qr_stickers.php?lang=<?= $lang ?>"><?= $T['back'] ?></a>
            <button onclick="window.print()"><?= $T['print'] ?></button>
            <?php endif; ?>
            <a href="dashboard.php?lang=<?= $lang ?>">🏠 <?= $T['dashboard'] ?></a>
            <a href="?<?= e(http_build_query(array_merge($_GET, ['lang' => $lang === 'ar' ? 'en' : 'ar']))) ?>">🌐 <?= $T['switch'] ?></a>
        </div>
    </div>

<?php if ($printMode): ?>

    <div class="card no-print">
        <div class="print-hint">💡 <?= $T['print_hint'] ?></div>
    </div>

    <?php
    $perPage = $size === 'large' ? 4 : 10;
    $chunks  = array_chunk($cars, $perPage);
    foreach ($chunks as $chunk): ?>
    <div class="sheet <?= $size ?>">
        <?php foreach ($chunk as $c): ?>
        <?php $bLabel = $lang === 'ar' ? ($c['b_ar'] ?? '') : ($c['b_en'] ?? '');
              $bLabel = $bLabel !== '' && $bLabel !== null ? $bLabel : $c['branch']; ?>
        <div class="sticker">
            <div class="qr" data-url="<?= e($qrBase . rawurlencode($c['chassis'])) ?>"></div>
            <div class="stx">
                <div class="brandline">FIRST 1 CAR</div>
                <div class="chx"><?= e($c['chassis']) ?></div>
                <div class="mdl">🚗 <?= e($c['brand'] . ' ' . $c['model']) ?></div>
                <div class="trm"><?= e($c['trim_name']) ?></div>
                <div class="meta">
                    <span>📅 <?= e($c['car_year']) ?></span>
                    <span>🎨 <?= e($c['color']) ?></span>
                    <span>📍 <?= e($bLabel) ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
    (function draw(){
        function render(){
            document.querySelectorAll('div.qr').forEach(function (el) {
                el.innerHTML = '';
                new QRCode(el, {
                    text: el.getAttribute('data-url'),
                    width: 320, height: 320,   /* high-res; CSS scales — crisp on paper */
                    colorDark: '#0f172a', colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M
                });
            });
        }
        if (window.QRCode && window.QRCode.CorrectLevel) { render(); return; }
        /* fallback CDN */
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/davidshimjs-qrcodejs@0.0.2/qrcode.min.js';
        s.onload = render;
        s.onerror = function(){ alert('QR library blocked — check internet'); };
        document.head.appendChild(s);
    })();
    </script>

<?php else: ?>

    <div class="card">
        <form class="filters" method="GET">
            <input type="hidden" name="lang" value="<?= $lang ?>">
            <select name="branch">
                <option value=""><?= $T['all_branches'] ?></option>
                <?php foreach ($branches as $b): ?>
                <option value="<?= e($b['name']) ?>" <?= $fBranch === $b['name'] ? 'selected' : '' ?>>
                    <?= e($lang === 'ar' ? ($b['name_ar'] ?: $b['name']) : ($b['name_en'] ?: $b['name'])) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="brand">
                <option value=""><?= $T['all_brands'] ?></option>
                <?php foreach ($brandsL as $b): ?>
                <option value="<?= e($b) ?>" <?= $fBrand === $b ? 'selected' : '' ?>><?= e($b) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status">
                <option value="" <?= $fStatus === '' ? 'selected' : '' ?>><?= $T['all_status'] ?></option>
                <option value="available"   <?= $fStatus === 'available'   ? 'selected' : '' ?>><?= $T['available'] ?></option>
                <option value="reserved"    <?= $fStatus === 'reserved'    ? 'selected' : '' ?>><?= $T['reserved'] ?></option>
                <option value="consignment" <?= $fStatus === 'consignment' ? 'selected' : '' ?>><?= $T['consignment'] ?></option>
            </select>
            <input type="text" name="search" placeholder="<?= $T['search_ph'] ?>" value="<?= e($fSearch) ?>">
            <button type="submit"><?= $T['filter'] ?></button>
        </form>
    </div>

    <div class="card">
        <div class="sel-tools">
            <button type="button" id="btnAll"><?= $T['select_all'] ?></button>
            <button type="button" id="btnNone"><?= $T['clear_all'] ?></button>
        </div>
        <?php if (!$cars): ?>
            <div class="empty-state">📭 <?= $T['no_cars'] ?></div>
        <?php else: foreach ($cars as $c): ?>
        <label class="car-row">
            <input type="checkbox" class="car-check" value="<?= (int)$c['id'] ?>">
            <div class="info">
                <div class="nm">🚗 <?= e($c['brand'] . ' ' . $c['model']) ?> · <?= e($c['trim_name']) ?></div>
                <div class="mt">
                    <span class="ch"><?= e($c['chassis']) ?></span>
                    <span><?= e($c['car_year']) ?></span>
                    <span><?= e($c['color']) ?></span>
                    <span>📍 <?= e($c['branch']) ?></span>
                </div>
            </div>
        </label>
        <?php endforeach; endif; ?>
    </div>

    <div class="make-bar" id="makeBar">
        <div class="make-inner">
            <div class="make-count"><strong id="selCount">0</strong> <?= $T['selected'] ?></div>
            <select id="selSize">
                <option value="small"><?= $T['size_small'] ?></option>
                <option value="large"><?= $T['size_large'] ?></option>
            </select>
            <button type="button" class="btn-make" id="btnMake"><?= $T['make'] ?></button>
        </div>
    </div>

    <script>
    const checks = () => [...document.querySelectorAll('.car-check')];
    function sync(){
        const on = checks().filter(c => c.checked);
        document.getElementById('selCount').textContent = on.length;
        document.getElementById('makeBar').classList.toggle('show', on.length > 0);
        checks().forEach(c => c.closest('.car-row').classList.toggle('on', c.checked));
    }
    checks().forEach(c => c.addEventListener('change', sync));
    document.getElementById('btnAll').addEventListener('click', () => { checks().forEach(c => c.checked = true); sync(); });
    document.getElementById('btnNone').addEventListener('click', () => { checks().forEach(c => c.checked = false); sync(); });
    document.getElementById('btnMake').addEventListener('click', () => {
        const ids = checks().filter(c => c.checked).map(c => c.value).join(',');
        if (!ids) return;
        const size = document.getElementById('selSize').value;
        window.location = `qr_stickers.php?lang=<?= $lang ?>&size=${size}&ids=${ids}`;
    });
    </script>

<?php endif; ?>

</div>
</body>
</html>
