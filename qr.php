<?php
/**
 * qr.php — First 1 Car · QR car card & action hub (v2)
 * ═══════════════════════════════════════════════════════════════
 * Every car QR encodes:  qr.php?c=CHASSIS
 *
 * v2 upgrades:
 *  - PRICING: official price + customer/trade deal labels straight
 *    from the pricing table (trade price = manager/admin only,
 *    labels shown VERBATIM as colored chips — never number-mathed)
 *  - Localized color name from the colors table + swatch
 *  - Days-in-stock aging color (green <30 · amber 30-60 · red 60+)
 *  - "Transferred from" row when the car moved between branches
 *  - Notes row when present
 *  - QUICK ACTIONS (role-aware): edit / transfer / sell for
 *    managers+ on available cars — scan on the lot → sell directly
 *  - Print-sticker shortcut (managers+)
 *  - Tap the chassis to copy it
 *
 * Every extra lookup is guarded — a missing table or column can
 * never break the card itself.
 */

require 'auth.php';
require 'config.php';

$lang = $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar', 'en'], true)) $lang = 'ar';
$dir = $lang === 'ar' ? 'rtl' : 'ltr';

$role      = $_SESSION['role'] ?? 'sales';
$canManage = can('qr.manage');   // edit / transfer / sell / print buttons on the QR card

$chassis = strtoupper(trim($_GET['c'] ?? ''));

$t = [
    'ar' => [
        'title'      => 'بطاقة السيارة',
        'color'      => 'اللون',
        'chassis'    => 'الشاسيه',
        'branch'     => 'الفرع',
        'moved_from' => 'منقولة من',
        'added_at'   => 'تاريخ الإضافة',
        'days'       => 'يوم بالمخزون',
        'notes'      => 'ملاحظات',
        'available'  => 'متاحة ✅',
        'sold'       => 'مباعة 💰',
        'reserved'   => 'محجوزة ⏳',
        'consignment'=> 'امانة 🤝',
        'price_official' => 'السعر الرسمي',
        'price_customer' => 'سعر العميل',
        'price_trade'    => 'سعر التجاري',
        'egp'        => 'جنيه',
        'timeline'   => '🕐 رحلة السيارة الكاملة',
        'edit'       => '✏️ تعديل',
        'transfer'   => '🔄 نقل',
        'sell'       => '💰 بيع',
        'sticker'    => '🏷️ طباعة ملصق',
        'dashboard'  => '🏠 الرئيسية',
        'copied'     => '✅ تم نسخ الشاسيه',
        'tap_copy'   => 'اضغط للنسخ',
        'not_found'  => 'لا توجد سيارة بهذا الشاسيه في النظام',
        'scanned'    => 'الشاسيه الممسوح',
        'switch'     => 'English',
    ],
    'en' => [
        'title'      => 'Car Card',
        'color'      => 'Color',
        'chassis'    => 'Chassis',
        'branch'     => 'Branch',
        'moved_from' => 'Transferred from',
        'added_at'   => 'Added',
        'days'       => 'days in stock',
        'notes'      => 'Notes',
        'available'  => 'Available ✅',
        'sold'       => 'Sold 💰',
        'reserved'   => 'Reserved ⏳',
        'consignment'=> 'Consignment 🤝',
        'price_official' => 'Official Price',
        'price_customer' => 'Customer Price',
        'price_trade'    => 'Trade Price',
        'egp'        => 'EGP',
        'timeline'   => '🕐 Full Vehicle Timeline',
        'edit'       => '✏️ Edit',
        'transfer'   => '🔄 Transfer',
        'sell'       => '💰 Sell',
        'sticker'    => '🏷️ Print Sticker',
        'dashboard'  => '🏠 Dashboard',
        'copied'     => '✅ Chassis copied',
        'tap_copy'   => 'tap to copy',
        'not_found'  => 'No car with this chassis exists in the system',
        'scanned'    => 'Scanned chassis',
        'switch'     => 'العربية',
    ],
];
$T = $t[$lang];

/* ─── Car lookup ──────────────────────────────────────────── */
$car = null;
if ($chassis !== '') {
    $stmt = $pdo->prepare("SELECT * FROM cars WHERE UPPER(chassis) = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$chassis]);
    $car = $stmt->fetch(PDO::FETCH_ASSOC);
}

/* ─── Enrichment (each block guarded — can never break the card) ── */
$branchLabel = ''; $origLabel = ''; $days = null;
$colorLabel  = ''; $pricing = null;

if ($car) {
    /* branch names (current + original, one query) */
    try {
        $bStmt = $pdo->prepare("SELECT name, name_ar, name_en FROM branches WHERE name IN (?, ?)");
        $bStmt->execute([$car['branch'], $car['original_branch'] ?? '']);
        $bMap = [];
        foreach ($bStmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $bMap[$b['name']] = $lang === 'ar' ? ($b['name_ar'] ?: $b['name']) : ($b['name_en'] ?: $b['name']);
        }
        $branchLabel = $bMap[$car['branch']] ?? $car['branch'];
        if (!empty($car['original_branch']) && $car['original_branch'] !== $car['branch']) {
            $origLabel = $bMap[$car['original_branch']] ?? $car['original_branch'];
        }
    } catch (Exception $e) { $branchLabel = $car['branch']; }

    /* localized color name */
    $colorLabel = $car['color'];
    try {
        $cStmt = $pdo->prepare("SELECT color_ar, color_en FROM colors WHERE color_en = ? OR color_ar = ? LIMIT 1");
        $cStmt->execute([$car['color'], $car['color']]);
        if ($c = $cStmt->fetch(PDO::FETCH_ASSOC)) {
            $colorLabel = $lang === 'ar' ? ($c['color_ar'] ?: $car['color']) : ($c['color_en'] ?: $car['color']);
        }
    } catch (Exception $e) {}

    /* pricing row for this exact model/trim/year */
    try {
        $pStmt = $pdo->prepare("SELECT official_price, customer_price, trade_price
                                FROM pricing
                                WHERE brand = ? AND model_name = ? AND trim_name = ? AND car_year = ?
                                LIMIT 1");
        $pStmt->execute([$car['brand'], $car['model'], $car['trim_name'], $car['car_year']]);
        $pricing = $pStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {}

    if (!empty($car['created_at'])) {
        $days = (int)floor((time() - strtotime($car['created_at'])) / 86400);
    }
}

$statusKey = $car['status'] ?? '';
$statusMap = [
    'available'   => ['label' => $T['available'],   'cls' => 'st-green'],
    'sold'        => ['label' => $T['sold'],        'cls' => 'st-red'],
    'reserved'    => ['label' => $T['reserved'],    'cls' => 'st-gold'],
    'consignment' => ['label' => $T['consignment'], 'cls' => 'st-purple'],
];
$status = $statusMap[$statusKey] ?? ['label' => htmlspecialchars((string)$statusKey), 'cls' => 'st-plain'];

/* aging class for days-in-stock */
$daysCls = $days === null ? '' : ($days < 30 ? 'age-ok' : ($days < 60 ? 'age-warn' : 'age-bad'));

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES); }

/* official price: numeric → formatted; anything else → verbatim */
function fmtOfficial($v) {
    $n = preg_replace('/[^0-9.]/', '', (string)$v);
    return ($n !== '' && is_numeric($n)) ? number_format((float)$n) : e($v);
}

/* customer/trade deal labels: VERBATIM colored chips, never number-mathed
   (رسمي = same as official · أوفر = over · خصم = discount) */
function dealChip($val) {
    $val = (string)($val ?? '');
    if ($val === '') return '<span class="v" style="color:var(--muted-d)">—</span>';
    $isOfficial = (mb_strpos($val, 'رسمي') !== false) || (stripos($val, 'Official') !== false);
    $isOffer    = (mb_strpos($val, 'أوفر') !== false) || (stripos($val, 'Offer') !== false);
    $isDisc     = (mb_strpos($val, 'خصم')  !== false) || (stripos($val, 'Discount') !== false);
    if ($isOfficial)  { $cls = 'chip-official'; $icon = '📢'; }
    elseif ($isOffer) { $cls = 'chip-offer';    $icon = '⬆️'; }
    elseif ($isDisc)  { $cls = 'chip-disc';     $icon = '⬇️'; }
    else              { $cls = 'chip-plain';    $icon = '';   }
    return '<span class="deal-chip ' . $cls . '">' . $icon . ' ' . e($val) . '</span>';
}

$noteText = trim((string)($car['notes'] ?? ''));
// Reserved cars are still actionable (edit / transfer / sell) — just gold.
$isAvailable = in_array($statusKey, ['available', 'reserved'], true);
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
    --bg-card:rgba(15,23,42,.92); --border:rgba(255,255,255,.08);
    --text:#f1f5f9; --muted:#94a3b8; --muted-d:#64748b;
}
html[lang="ar"] body { font-family:'Cairo','Segoe UI',Tahoma,sans-serif; }
html[lang="en"] body { font-family:'Inter','Segoe UI',Tahoma,sans-serif; }
body {
    background:linear-gradient(135deg,#020617,#0f172a); color:var(--text);
    min-height:100vh; display:flex; align-items:center; justify-content:center; padding:18px;
}
.card {
    width:100%; max-width:440px;
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:24px; padding:22px; box-shadow:0 12px 44px rgba(0,0,0,.45);
    animation:pop .35s cubic-bezier(.2,1.4,.4,1) both;
}
@keyframes pop { from{opacity:0; transform:scale(.94) translateY(8px);} to{opacity:1; transform:none;} }
.top { text-align:center; margin-bottom:16px; }
.top .icon { font-size:42px; }
.top .name { font-size:21px; font-weight:900; margin-top:5px; line-height:1.4; }
.top .trim { color:var(--muted); font-size:13.5px; margin-top:2px; }
.status-badge {
    display:inline-block; margin-top:10px;
    font-size:13px; font-weight:900; border-radius:999px; padding:6px 18px;
}
.st-green  { background:rgba(34,197,94,.14);  color:#4ade80; border:1px solid rgba(34,197,94,.35); }
.st-red    { background:rgba(239,68,68,.14);  color:#fca5a5; border:1px solid rgba(239,68,68,.35); }
.st-amber  { background:rgba(245,158,11,.14); color:#fbbf24; border:1px solid rgba(245,158,11,.35); }
.st-gold   { background:linear-gradient(135deg,rgba(250,204,21,.22),rgba(234,179,8,.14)); color:#facc15; border:1px solid rgba(250,204,21,.5); box-shadow:0 0 12px rgba(250,204,21,.22); }
.st-purple { background:rgba(147,51,234,.14); color:#c084fc; border:1px solid rgba(147,51,234,.35); }
.st-plain  { background:rgba(255,255,255,.07); color:var(--text); border:1px solid var(--border); }

.rows { display:flex; flex-direction:column; gap:1px; border-radius:16px; overflow:hidden; margin-bottom:14px; }
.row {
    display:flex; align-items:center; justify-content:space-between; gap:12px;
    background:rgba(255,255,255,.03); padding:11px 15px; font-size:13.5px;
}
.row .k { color:var(--muted); font-size:12.5px; font-weight:700; flex-shrink:0; }
.row .v { font-weight:800; text-align:end; min-width:0; }
.row .v.mono { font-family:'Inter',monospace; letter-spacing:1px; cursor:pointer; }
.row .v.mono small { display:block; color:var(--muted-d); font-size:9.5px; font-weight:600; letter-spacing:0; }
.swatch { display:inline-block; width:13px; height:13px; border-radius:50%; border:2px solid rgba(255,255,255,.35); vertical-align:-2px; margin-inline-end:6px; }
.age-ok   { color:#4ade80; }
.age-warn { color:#fbbf24; }
.age-bad  { color:#fca5a5; }
.note-v { font-size:12.5px; color:var(--muted); font-weight:600; line-height:1.6; }

/* pricing rows */
.price-official { color:#4ade80; font-size:15px; }
.price-official small { color:var(--muted-d); font-size:11px; font-weight:700; }
.deal-chip {
    display:inline-flex; align-items:center; gap:4px;
    font-size:12px; font-weight:800; border-radius:999px; padding:3px 11px;
}
.chip-official { background:rgba(34,197,94,.15);  color:#4ade80; border:1px solid rgba(34,197,94,.35); }
.chip-offer    { background:rgba(34,197,94,.12);  color:#4ade80; border:1px solid rgba(34,197,94,.3); }
.chip-disc     { background:rgba(239,68,68,.12);  color:#f87171; border:1px solid rgba(239,68,68,.3); }
.chip-plain    { background:rgba(255,255,255,.06); color:var(--text); border:1px solid var(--border); }

/* actions */
.actions { display:flex; flex-direction:column; gap:9px; }
.actions a {
    text-decoration:none; text-align:center; font-weight:900; font-size:14px;
    border-radius:14px; padding:13px; font-family:inherit;
}
.quick-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:9px; }
.quick-grid a { font-size:13px; padding:12px 6px; }
.btn-primary  { background:linear-gradient(135deg,var(--blue),#1d4ed8); color:#fff; box-shadow:0 6px 18px rgba(37,99,235,.3); }
.btn-edit     { background:rgba(245,158,11,.12); color:#fbbf24; border:1px solid rgba(245,158,11,.35); }
.btn-transfer { background:rgba(147,51,234,.12); color:#c084fc; border:1px solid rgba(147,51,234,.35); }
.btn-sell     { background:rgba(34,197,94,.12);  color:#4ade80; border:1px solid rgba(34,197,94,.35); }
.btn-ghost    { background:rgba(255,255,255,.05); color:var(--text); border:1px solid var(--border); }
.ghost-grid   { display:grid; grid-template-columns:1fr 1fr; gap:9px; }

.lang-link { display:block; text-align:center; margin-top:14px; color:var(--muted-d); font-size:12px; text-decoration:none; }
.nf { text-align:center; padding:10px 0 4px; }
.nf .icon { font-size:46px; }
.nf .msg { color:var(--muted); font-size:14.5px; line-height:1.8; margin:12px 0 6px; }
.nf .ch { font-family:'Inter',monospace; letter-spacing:1px; font-weight:800; color:var(--amber); font-size:15px; }

/* copy toast */
.toast {
    position:fixed; bottom:26px; left:50%; transform:translateX(-50%) translateY(16px);
    background:rgba(34,197,94,.95); color:#052e16; font-weight:900; font-size:13.5px;
    border-radius:999px; padding:10px 22px; opacity:0; pointer-events:none;
    transition:.25s; z-index:100;
}
.toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
</style>
</head>
<body>
<div class="card">
<?php if ($car): ?>
    <div class="top">
        <div class="icon">🚗</div>
        <div class="name"><?= e($car['brand'] . ' ' . $car['model']) ?></div>
        <div class="trim"><?= e($car['trim_name']) ?> · <?= e($car['car_year']) ?></div>
        <div class="status-badge <?= $status['cls'] ?>"><?= $status['label'] ?></div>
    </div>

    <div class="rows">
        <div class="row">
            <span class="k"><?= $T['chassis'] ?></span>
            <span class="v mono" id="chassisCopy" data-ch="<?= e($car['chassis']) ?>">
                <?= e($car['chassis']) ?><small>👆 <?= $T['tap_copy'] ?></small>
            </span>
        </div>
        <div class="row"><span class="k"><?= $T['color'] ?></span>
            <span class="v"><span class="swatch" style="background:<?= e(strtolower(str_replace(' ', '', $car['color']))) ?>"></span><?= e($colorLabel) ?></span></div>
        <div class="row"><span class="k"><?= $T['branch'] ?></span><span class="v">📍 <?= e($branchLabel) ?></span></div>
        <?php if ($origLabel !== ''): ?>
        <div class="row"><span class="k"><?= $T['moved_from'] ?></span><span class="v" style="color:#c084fc">🔄 <?= e($origLabel) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($car['created_at'])): ?>
        <div class="row"><span class="k"><?= $T['added_at'] ?></span>
            <span class="v"><?= e(substr($car['created_at'], 0, 10)) ?><?php if ($days !== null): ?> · <span class="<?= $daysCls ?>"><?= $days ?> <?= $T['days'] ?></span><?php endif; ?></span></div>
        <?php endif; ?>
        <?php if ($noteText !== ''): ?>
        <div class="row"><span class="k">📝 <?= $T['notes'] ?></span><span class="v note-v"><?= e($noteText) ?></span></div>
        <?php endif; ?>
    </div>

    <?php if ($pricing): ?>
    <div class="rows">
        <?php if (trim((string)$pricing['official_price']) !== ''): ?>
        <div class="row"><span class="k">💚 <?= $T['price_official'] ?></span>
            <span class="v price-official"><?= fmtOfficial($pricing['official_price']) ?> <small><?= $T['egp'] ?></small></span></div>
        <?php endif; ?>
        <div class="row"><span class="k">💜 <?= $T['price_customer'] ?></span><?= dealChip($pricing['customer_price']) ?></div>
        <?php if ($canManage): ?>
        <div class="row"><span class="k">💙 <?= $T['price_trade'] ?></span><?= dealChip($pricing['trade_price']) ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="actions">
        <a class="btn-primary" href="vehicle_timeline.php?id=<?= (int)$car['id'] ?>&lang=<?= $lang ?>"><?= $T['timeline'] ?></a>

        <?php if ($canManage && $isAvailable): ?>
        <div class="quick-grid">
            <a class="btn-edit"     href="edit_vehicle.php?id=<?= (int)$car['id'] ?>&lang=<?= $lang ?>"><?= $T['edit'] ?></a>
            <a class="btn-transfer" href="transfer_vehicle.php?id=<?= (int)$car['id'] ?>&lang=<?= $lang ?>"><?= $T['transfer'] ?></a>
            <a class="btn-sell"     href="sold_vehicle.php?id=<?= (int)$car['id'] ?>&lang=<?= $lang ?>"><?= $T['sell'] ?></a>
        </div>
        <?php endif; ?>

        <div class="ghost-grid">
            <?php if ($canManage): ?>
            <a class="btn-ghost" href="qr_stickers.php?lang=<?= $lang ?>&ids=<?= (int)$car['id'] ?>"><?= $T['sticker'] ?></a>
            <?php endif; ?>
            <a class="btn-ghost" style="<?= $canManage ? '' : 'grid-column:1 / -1' ?>" href="dashboard.php?lang=<?= $lang ?>"><?= $T['dashboard'] ?></a>
        </div>
    </div>

<?php else: ?>
    <div class="nf">
        <div class="icon">🔍</div>
        <div class="msg"><?= $T['not_found'] ?></div>
        <?php if ($chassis !== ''): ?>
        <div class="msg" style="margin:0"><?= $T['scanned'] ?>: <span class="ch"><?= e($chassis) ?></span></div>
        <?php endif; ?>
    </div>
    <div class="actions" style="margin-top:16px">
        <a class="btn-ghost" href="dashboard.php?lang=<?= $lang ?>"><?= $T['dashboard'] ?></a>
    </div>
<?php endif; ?>
    <a class="lang-link" href="?c=<?= urlencode($chassis) ?>&lang=<?= $lang === 'ar' ? 'en' : 'ar' ?>">🌐 <?= $T['switch'] ?></a>
</div>

<div class="toast" id="toast"><?= $T['copied'] ?></div>

<script>
(function(){
    var el = document.getElementById('chassisCopy');
    if (!el) return;
    el.addEventListener('click', function(){
        var ch = el.getAttribute('data-ch');
        function done(){
            var t = document.getElementById('toast');
            t.classList.add('show');
            setTimeout(function(){ t.classList.remove('show'); }, 1600);
            if (navigator.vibrate) navigator.vibrate(50);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(ch).then(done).catch(function(){ fallback(); });
        } else { fallback(); }
        function fallback(){
            var ta = document.createElement('textarea');
            ta.value = ch; document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); done(); } catch(e){}
            document.body.removeChild(ta);
        }
    });
})();
</script>
</body>
</html>
