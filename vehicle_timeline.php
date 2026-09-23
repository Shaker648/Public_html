<?php

require 'auth.php';
require 'config.php';
require 'sold_helpers.php';
require_once 'car_images_helpers.php';

$lang = $_GET['lang'] ?? 'ar';
if ($lang !== 'en') $lang = 'ar';
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
        'reserved_event'   => 'حجز السيارة',
        'reserve_cancel_event' => 'إلغاء الحجز',
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
        'days_to_sale'     => 'أيام حتى البيع',
        'salesman'         => 'البائع',
        'sale_type'        => 'نوع البيع',
        'st_customer'      => 'عميل مباشر',
        'st_dealer'        => 'تاجر',
        'closed_amana'     => '🔶 أُغلقت الأمانة بهذا البيع',
        'recorded_by'      => 'سجّلها',
        'price_up'         => 'ارتفع السعر الرسمي',
        'price_down'       => 'انخفض السعر الرسمي',
        'official'         => 'السعر الرسمي',
        'time_where'       => 'أين قضت وقتها',
        'seg_stock'        => 'في المخزون',
        'seg_reserved'     => 'محجوزة',
        'seg_amana'        => 'أمانة',
        'day'              => 'يوم',
        'act_sell'         => 'بيع',
        'act_sold_now'     => 'تم البيع',
        'act_transfer'     => 'نقل',
        'act_edit'         => 'تعديل',
        'act_reserve'      => 'حجز',
        'act_unreserve'    => 'إلغاء الحجز',
        'unreserve_q'      => 'إلغاء حجز هذه السيارة؟',
        'act_qr'           => 'QR',
        'share'            => 'نسخ ملخص الرحلة',
        'print'            => 'طباعة',
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
        'reserved_event'   => 'Car Reserved',
        'reserve_cancel_event' => 'Reservation Cancelled',
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
        'days_to_sale'     => 'Days Until Sold',
        'salesman'         => 'Salesman',
        'sale_type'        => 'Sale Type',
        'st_customer'      => 'Direct customer',
        'st_dealer'        => 'Dealer',
        'closed_amana'     => '🔶 This sale closed the consignment',
        'recorded_by'      => 'Recorded by',
        'price_up'         => 'Official price went up',
        'price_down'       => 'Official price went down',
        'official'         => 'Official price',
        'time_where'       => 'Where the time went',
        'seg_stock'        => 'In stock',
        'seg_reserved'     => 'Reserved',
        'seg_amana'        => 'Consignment',
        'day'              => 'd',
        'act_sell'         => 'Sell',
        'act_sold_now'     => 'Mark sold',
        'act_transfer'     => 'Transfer',
        'act_edit'         => 'Edit',
        'act_reserve'      => 'Reserve',
        'act_unreserve'    => 'Cancel reservation',
        'unreserve_q'      => 'Cancel the reservation on this car?',
        'act_qr'           => 'QR',
        'share'            => 'Copy journey summary',
        'print'            => 'Print',
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

if (!$car) {
    http_response_code(404);
    $nf = $lang === 'ar'
        ? ['السيارة غير موجودة', 'ربما حُذفت أو أن الرابط غير صحيح.', 'العودة للرئيسية']
        : ['Vehicle not found', 'It may have been deleted, or the link is wrong.', 'Back to Dashboard'];
    echo '<!DOCTYPE html><html lang="' . $lang . '" dir="' . $dir . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . $nf[0] . '</title>'
       . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#020617;color:#e2e8f0;font-family:"Segoe UI",Tahoma,Arial,sans-serif;padding:20px}'
       . '.b{text-align:center;max-width:380px;background:rgba(10,18,40,.93);border:1px solid rgba(255,255,255,.07);border-radius:28px;padding:36px 28px}'
       . '.i{font-size:48px;margin-bottom:10px}h1{font-size:22px;margin:0 0 8px}p{color:#64748b;margin:0 0 22px;font-size:14px}'
       . 'a{display:inline-block;padding:11px 22px;border-radius:12px;background:#7c3aed;color:#fff;text-decoration:none;font-weight:700}</style></head>'
       . '<body><div class="b"><div class="i">🔍</div><h1>' . $nf[0] . '</h1><p>' . $nf[1] . '</p><a href="dashboard.php?lang=' . $lang . '">' . $nf[2] . '</a></div></body></html>';
    exit;
}

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

// A real transfer: a 'transfer' row (or an old row without a type) that
// actually moved the car to a different place.
$isTransfer = fn(array $mv): bool => in_array((string)($mv['event_type'] ?? ''), ['transfer', ''], true)
                                  && (string)$mv['from_branch'] !== (string)$mv['to_branch'];
$transferMoves  = array_values(array_filter($movements, $isTransfer));
$totalTransfers = count($transferMoves);

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
    $kind = (string)($mv['event_type'] ?? '');
    if ($kind === 'created') continue;                         // already shown as "Vehicle Added"
    if (in_array($kind, ['transfer', ''], true) && !$isTransfer($mv)) continue;   // a "move" to the same place
    $timelineEvents[] = [
        'ts'   => strtotime($mv['created_at'] ?? '') ?: 0,
        'kind' => $kind === '' ? 'transfer' : $kind,
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
/* Official price changes for this model while the car was here */
$canPriceHist = can('page.price_history');
$carStart = strtotime($car['created_at']) ?: time();
$soldEnd  = null;
foreach ($soldRows as $sr) if (($sr['status'] ?? 'sold') !== 'returned') $soldEnd = strtotime($sr['sold_at']) ?: null;
if ($car['status'] !== 'sold') $soldEnd = null;
if ($canPriceHist) {
    try {
        $ph = $pdo->prepare("SELECT old_official, new_official, changed_by, changed_at FROM pricing_history
                             WHERE brand = ? AND model_name = ? AND trim_name = ? AND car_year = ? AND change_type = 'update'
                               AND changed_at >= ? AND changed_at <= ? ORDER BY changed_at ASC");
        $ph->execute([$car['brand'], $car['model'], $car['trim_name'], $car['car_year'],
                      date('Y-m-d H:i:s', $carStart), date('Y-m-d H:i:s', $soldEnd ?? time())]);
        foreach ($ph->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            if (!is_numeric($pr['old_official']) || !is_numeric($pr['new_official']) || (float)$pr['old_official'] === (float)$pr['new_official']) continue;
            $timelineEvents[] = ['ts' => strtotime($pr['changed_at']) ?: 0, 'kind' => 'price', 'price' => $pr];
        }
    } catch (Throwable $e) { /* no price history yet */ }
}

usort($timelineEvents, fn($a, $b) => $a['ts'] <=> $b['ts']);

/* Days in stock stop counting on the sale day */
$daysInStock = (int)floor((($soldEnd ?? time()) - $carStart) / 86400);

/* How long the car spent in each place / state */
$segments = [];
$segState = ['k' => 'stock', 'b' => ''];
$segFrom  = $carStart;
$segPush  = function (int $until) use (&$segments, &$segState, &$segFrom) {
    if ($until > $segFrom) $segments[] = ['k' => $segState['k'], 'b' => $segState['b'], 'from' => $segFrom, 'to' => $until];
    $segFrom = $until;
};
$segState['b'] = $transferMoves ? (string)$transferMoves[0]['from_branch'] : (string)($car['original_branch'] ?: $car['branch']);
$segEnded = false;
foreach ($timelineEvents as $ev) {
    if ($segEnded && $ev['kind'] !== 'sale_return') continue;
    $mv = $ev['mv'] ?? null;
    switch ($ev['kind']) {
        case 'transfer':       $segPush($ev['ts']); $segState = ['k' => $segState['k'] === 'reserved' ? 'reserved' : 'stock', 'b' => (string)$mv['to_branch']]; break;
        case 'reserved':       $segPush($ev['ts']); $segState['k'] = 'reserved'; break;
        case 'reserve_cancel': $segPush($ev['ts']); $segState['k'] = 'stock'; break;
        case 'amana_out':      if (!$canSeeAmana) break; $segPush($ev['ts']); $segState['k'] = 'amana'; break;
        case 'amana_return':   if (!$canSeeAmana) break; $segPush($ev['ts']); $segState = ['k' => 'stock', 'b' => (string)$mv['to_branch']]; break;
        case 'sold':           if (($ev['sold']['status'] ?? 'sold') === 'returned' || $car['status'] === 'sold') { $segPush($ev['ts']); $segState['k'] = 'sold'; $segEnded = true; } break;
        case 'sale_return':    $segPush($ev['ts']); $segState = ['k' => 'stock', 'b' => (string)$mv['to_branch']]; $segEnded = false; break;
    }
}
if (!$segEnded) $segPush(time());
$segments = array_values(array_filter($segments, fn($sg) => $sg['k'] !== 'sold'));

/* Photo, price, quick actions */
$heroImg = '';
try { $heroImg = car_image_url_for(car_images_map($pdo), $car, false); } catch (Throwable $e) {}
$heroPrice = null;
if (can('page.prices')) {
    try {
        $pp = $pdo->prepare("SELECT official_price, customer_price FROM pricing WHERE brand = ? AND model_name = ? AND trim_name = ? AND car_year = ? LIMIT 1");
        $pp->execute([$car['brand'], $car['model'], $car['trim_name'], $car['car_year']]);
        $heroPrice = $pp->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];
$onSale    = in_array($car['status'], ['available', 'reserved'], true);
$acts = [
    'sell'     => $onSale && can('dash.btn_sell') && can('page.sold_vehicle'),
    'transfer' => $onSale && can('dash.btn_transfer'),
    'edit'     => can('dash.btn_edit') && can('page.edit_vehicle'),
    'reserve'  => $car['status'] === 'available' && can('reserve.create'),
    'unreserve'=> $car['status'] === 'reserved'  && can('reserve.cancel'),
    'qr'       => can('qr.manage'),
];
function tl_swatch(string $colorEn): string
{
    static $map = [
        'white' => '#f8fafc', 'pearl white' => '#f1f5f9', 'black' => '#111827', 'silver' => '#cbd5e1',
        'grey' => '#6b7280', 'gray' => '#6b7280', 'red' => '#dc2626', 'blue' => '#2563eb', 'navy' => '#1e3a8a',
        'green' => '#16a34a', 'gold' => '#d4af37', 'beige' => '#e0d5c0', 'brown' => '#78350f',
        'orange' => '#ea580c', 'yellow' => '#eab308', 'purple' => '#7c3aed', 'bronze' => '#a97142', 'champagne' => '#e6d7b8',
    ];
    return $map[mb_strtolower(trim($colorEn))] ?? '#64748b';
}
$carHex = tl_swatch((string)$car['color']);
/* "3 أيام", "يومين", "15 يوم" … */
function tl_days(int $d, string $lang): string
{
    if ($lang !== 'ar') return $d . ' ' . ($d === 1 ? 'day' : 'days');
    if ($d === 1) return 'يوم';
    if ($d === 2) return 'يومين';
    return $d . ' ' . (($d >= 3 && $d <= 10) ? 'أيام' : 'يوم');
}

/* Which consignment a sale closed (a sale recorded while it was out on امانة) */
$closedAmanaAt = [];
foreach ($consignHist as $cn) if (($cn['status'] ?? '') === 'sold' && !empty($cn['closed_at'])) $closedAmanaAt[] = strtotime($cn['closed_at']);

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
$startRaw   = $totalTransfers ? $transferMoves[0]['from_branch'] : ($car['original_branch'] ?: $car['branch']);
$startLabel = getBranchName($pdo, (string)$startRaw, $lang, $branchCache);

$routeStops = [['label' => $startLabel, 'current' => false]];
foreach ($transferMoves as $mv) {
    $routeStops[] = ['label' => getBranchName($pdo, $mv['to_branch'], $lang, $branchCache), 'current' => false];
}
// Mark last as current
$routeStops[count($routeStops)-1]['current'] = true;

/* ─── Status config ─────────────────────────────────────── */
$statusConfig = [
    'available' => ['color' => '#22c55e', 'bg' => 'rgba(34,197,94,.12)',  'icon' => '✅'],
    'sold'      => ['color' => '#ef4444', 'bg' => 'rgba(239,68,68,.12)',  'icon' => '💰'],
    'reserved'  => ['color' => '#facc15', 'bg' => 'rgba(250,204,21,.14)', 'icon' => '🔒'],
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
.tl-dot.ev-reserved { background:rgba(250,204,21,.16); border-color:#facc15; }
.tl-dot.ev-reserve-cancel { background:rgba(250,204,21,.10); border-color:rgba(250,204,21,.55); }

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
.tl-title.ev-reserved { color:#facc15; }
.tl-title.ev-reserve-cancel { color:#fde68a; }

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
            <div class="hero-icon tl-stage" id="tlStage" style="--car:<?= $carHex ?>" data-img="<?= htmlspecialchars($heroImg) ?>"></div>
            <div class="hero-text">
                <div class="hero-name">
                    <?= htmlspecialchars($car['brand']) ?>
                    <?= htmlspecialchars($car['model']) ?>
                    <?= htmlspecialchars($car['trim_name']) ?>
                </div>
                <div class="hero-sub">
                    <span class="hs-chip"><i style="background:<?= $carHex ?>"></i><?= htmlspecialchars($displayColor) ?></span>
                    <span class="hs-chip">📅 <?= htmlspecialchars((string)$car['car_year']) ?></span>
                    <span class="hs-chip">📍 <?= htmlspecialchars($displayBranch) ?></span>
                </div>
                <div class="hero-chassis">
                    <?= $t[$lang]['chassis'] ?>: <span class="tl-plate"><?= htmlspecialchars($car['chassis']) ?></span>
                </div>
                <div class="status-pill" style="
                    background:<?= $sc['bg'] ?>;
                    color:<?= $sc['color'] ?>;
                    border:1px solid <?= $sc['color'] ?>44;
                ">
                    <?= $sc['icon'] ?> <?= $t[$lang][$car['status']] ?>
                </div>
                <?php if ($heroPrice && $heroPrice['official_price'] !== null && $heroPrice['official_price'] !== ''): ?>
                <div class="hero-price">
                    <small>💰 <?= $t[$lang]['official'] ?></small>
                    <b><?= number_format((float)$heroPrice['official_price']) ?></b><em><?= $lang === 'ar' ? 'جنيه' : 'EGP' ?></em>
                    <?php if (!empty($heroPrice['customer_price'])): ?><span class="hp-tag"><?= htmlspecialchars($heroPrice['customer_price']) ?></span><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (array_filter($acts)): ?>
        <div class="tl-acts">
            <?php if ($acts['sell']): ?><a class="tl-act sell" href="sold_vehicle.php?id=<?= $id ?>&lang=<?= $lang ?>">💰 <?= $car['status'] === 'reserved' ? $t[$lang]['act_sold_now'] : $t[$lang]['act_sell'] ?></a><?php endif; ?>
            <?php if ($acts['transfer']): ?><a class="tl-act" href="transfer_vehicle.php?id=<?= $id ?>&lang=<?= $lang ?>">🔄 <?= $t[$lang]['act_transfer'] ?></a><?php endif; ?>
            <?php if ($acts['reserve'] || $acts['unreserve']): ?>
            <form method="POST" action="reserve_action.php" class="tl-actf"<?= $acts['unreserve'] ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($t[$lang]['unreserve_q'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) . ');"' : '' ?>>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="<?= $acts['reserve'] ? 'reserve' : 'cancel' ?>">
                <input type="hidden" name="car_id" value="<?= $id ?>">
                <input type="hidden" name="lang" value="<?= $lang ?>">
                <input type="hidden" name="back" value="dashboard.php">
                <button type="submit" class="tl-act <?= $acts['reserve'] ? 'res' : '' ?>"><?= $acts['reserve'] ? '🔒 ' . $t[$lang]['act_reserve'] : '↩️ ' . $t[$lang]['act_unreserve'] ?></button>
            </form>
            <?php endif; ?>
            <?php if ($acts['edit']): ?><a class="tl-act" href="edit_vehicle.php?id=<?= $id ?>&lang=<?= $lang ?>">✏️ <?= $t[$lang]['act_edit'] ?></a><?php endif; ?>
            <?php if ($acts['qr']): ?><a class="tl-act" href="qr.php?id=<?= $id ?>&lang=<?= $lang ?>">▦ <?= $t[$lang]['act_qr'] ?></a><?php endif; ?>
        </div>
        <?php endif; ?>

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
                <div class="stat-label"><?= $soldEnd ? $t[$lang]['days_to_sale'] : $t[$lang]['days_stock'] ?></div>
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

    <?php
    $segTotal = array_sum(array_map(fn($sg) => $sg['to'] - $sg['from'], $segments));
    if ($segments && $segTotal > 0):
        $segName = function ($sg) use ($t, $lang, $pdo, &$branchCache) {
            $b = getBranchName($pdo, (string)$sg['b'], $lang, $branchCache);
            return $sg['k'] === 'reserved' ? $t[$lang]['seg_reserved'] . ' · ' . $b
                 : ($sg['k'] === 'amana' ? $t[$lang]['seg_amana'] : $b);
        };
        // merge back-to-back pieces of the same place for the legend
        $legend = [];
        foreach ($segments as $sg) { $k = $segName($sg); $legend[$k] = ['k' => $sg['k'], 'd' => ($legend[$k]['d'] ?? 0) + ($sg['to'] - $sg['from']), 'c' => $legend[$k]['c'] ?? count($legend) % 5]; }
    ?>
    <!-- ── Where the time went ─────────────────────────── -->
    <div class="card">
        <div class="section-label">⏳ <?= $t[$lang]['time_where'] ?></div>
        <div class="tb-bar">
            <?php foreach ($segments as $i => $sg): $w = max(1.5, ($sg['to'] - $sg['from']) * 100 / $segTotal); ?>
            <span class="tb-seg k-<?= $sg['k'] ?> c<?= $legend[$segName($sg)]['c'] ?>" style="flex:<?= round($w, 2) ?>" title="<?= htmlspecialchars($segName($sg)) ?> · <?= tl_days(max(0, (int)floor(($sg['to'] - $sg['from']) / 86400)), $lang) ?>"></span>
            <?php endforeach; ?>
        </div>
        <div class="tb-legend">
            <?php foreach ($legend as $name => $lg): $days = (int)floor($lg['d'] / 86400); ?>
            <span class="tb-l"><i class="k-<?= $lg['k'] ?> c<?= $lg['c'] ?>"></i><?= htmlspecialchars($name) ?> <b><?= $days < 1 ? ($lang === 'ar' ? 'أقل من يوم' : 'under a day') : tl_days($days, $lang) ?></b></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

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
        <div class="tl-tools">
            <div class="tl-chips" id="tlChips"></div>
            <div class="tl-tbtns">
                <button type="button" class="tl-tbtn" id="tlShare">📋 <span><?= $t[$lang]['share'] ?></span></button>
                <button type="button" class="tl-tbtn" onclick="window.print()">🖨 <span><?= $t[$lang]['print'] ?></span></button>
            </div>
        </div>

        <div class="timeline">

            <!-- Vehicle Added -->
            <div class="tl-item" data-k="add" data-ts="<?= $carStart ?>">
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
                if ($kind !== 'sold' && $kind !== 'price') {
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
            <div class="tl-item" data-k="sale" data-ts="<?= $ev['ts'] ?>">
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
                        <?php if (!empty($srow['salesman'])): ?>
                        <div class="tl-fact">
                            <span class="tl-fact-label"><?= $t[$lang]['salesman'] ?></span>
                            <span class="tl-fact-value"><?= htmlspecialchars($srow['salesman']) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($srow['sale_type'])): ?>
                        <div class="tl-fact">
                            <span class="tl-fact-label"><?= $t[$lang]['sale_type'] ?></span>
                            <span class="tl-fact-value"><?= $srow['sale_type'] === 'dealer' ? '🤝 ' . $t[$lang]['st_dealer'] : '👤 ' . $t[$lang]['st_customer'] ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="tl-fact">
                            <span class="tl-fact-label"><?= $t[$lang]['recorded_by'] ?></span>
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
                    <?php
                    $closedAmana = false;
                    if ($canSeeAmana) foreach ($closedAmanaAt as $ca) if (abs($ca - $ev['ts']) <= 600) $closedAmana = true;
                    if ($closedAmana): ?>
                    <div class="tl-note tl-note-amana"><?= $t[$lang]['closed_amana'] ?></div>
                    <?php endif; ?>
                    <?php if (!empty($srow['notes'])): ?>
                    <div class="tl-note">💬 <?= htmlspecialchars($srow['notes']) ?></div>
                    <?php endif; ?>
                    <?php if ($wasReverted): ?>
                    <div class="tl-note">💬 <?= $t[$lang]['sold_reverted_tag'] ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php /* ─── امانة OUT event ─── */ elseif ($kind === 'amana_out'): ?>
            <div class="tl-item" data-k="amana" data-ts="<?= $ev['ts'] ?>">
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
            <div class="tl-item" data-k="amana" data-ts="<?= $ev['ts'] ?>">
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
            <div class="tl-item" data-k="sale" data-ts="<?= $ev['ts'] ?>">
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

            <?php /* ─── حجز / RESERVED event ─── */ elseif ($kind === 'reserved'): ?>
            <div class="tl-item" data-k="reserve" data-ts="<?= $ev['ts'] ?>">
                <div class="tl-dot-wrap">
                    <div class="tl-dot ev-reserved">🔒</div>
                </div>
                <div class="tl-body">
                    <div class="tl-title ev-reserved"><?= $t[$lang]['reserved_event'] ?></div>
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
                </div>
            </div>

            <?php /* ─── إلغاء الحجز / RESERVATION CANCELLED event ─── */ elseif ($kind === 'reserve_cancel'): ?>
            <div class="tl-item" data-k="reserve" data-ts="<?= $ev['ts'] ?>">
                <div class="tl-dot-wrap">
                    <div class="tl-dot ev-reserve-cancel">↩️</div>
                </div>
                <div class="tl-body">
                    <div class="tl-title ev-reserve-cancel"><?= $t[$lang]['reserve_cancel_event'] ?></div>
                    <div class="tl-date">📅 <?= date('d M Y · h:i A', strtotime($mv['created_at'])) ?></div>
                    <div class="tl-facts">
                        <div class="tl-fact">
                            <span class="tl-fact-label"><?= $t[$lang]['by'] ?></span>
                            <span class="tl-fact-value"><?= htmlspecialchars($mv['moved_by']) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <?php /* ─── Official price change for this model ─── */ elseif ($kind === 'price'):
                $pr = $ev['price']; $up = (float)$pr['new_official'] > (float)$pr['old_official'];
            ?>
            <div class="tl-item" data-k="price" data-ts="<?= $ev['ts'] ?>">
                <div class="tl-dot-wrap">
                    <div class="tl-dot ev-price <?= $up ? 'up' : 'down' ?>"><?= $up ? '📈' : '📉' ?></div>
                </div>
                <div class="tl-body tl-body-price">
                    <div class="tl-title ev-price <?= $up ? 'up' : 'down' ?>"><?= $up ? $t[$lang]['price_up'] : $t[$lang]['price_down'] ?></div>
                    <div class="tl-date">📅 <?= date('d M Y · h:i A', $ev['ts']) ?></div>
                    <div class="branch-flow">
                        <span class="branch-tag"><?= number_format((float)$pr['old_official']) ?></span>
                        <span class="branch-arrow"><?= $dir === 'rtl' ? '←' : '→' ?></span>
                        <span class="branch-tag" style="border-color:<?= $up ? 'rgba(239,68,68,.35)' : 'rgba(34,197,94,.35)' ?>;color:<?= $up ? '#fca5a5' : 'var(--green-lt)' ?>"><?= number_format((float)$pr['new_official']) ?></span>
                    </div>
                    <div class="tl-facts">
                        <div class="tl-fact">
                            <span class="tl-fact-label"><?= $t[$lang]['by'] ?></span>
                            <span class="tl-fact-value"><?= htmlspecialchars((string)$pr['changed_by']) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <?php /* ─── Normal transfer ─── */ else: ?>
            <div class="tl-item" data-k="transfer" data-ts="<?= $ev['ts'] ?>">
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
<style>
/* ═══════════ Journey extras (same cards, route and timeline) ═══════════ */
/* hero photo stage */
.hero { align-items: center; }
.hero-icon.tl-stage { width: 250px; height: 150px; border-radius: 20px; font-size: 0; position: relative; overflow: hidden; --car: #64748b;
    background: radial-gradient(ellipse 80% 70% at 50% 15%, color-mix(in srgb, var(--car) 24%, #1a2744), #0a1120 74%); border: 1px solid var(--border); }
.tl-stage::after { content: ''; position: absolute; inset-inline: 0; bottom: 0; height: 32%; background: radial-gradient(ellipse 60% 60% at 50% 30%, color-mix(in srgb, var(--car) 30%, transparent), transparent 70%); }
.tl-stage img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; padding: 10px 14px 6px; z-index: 1; }
.tl-stage svg { position: absolute; inset-inline: 7%; bottom: 8%; width: 86%; height: auto; z-index: 1; }
.tl-stage.studio { background: linear-gradient(#fff, #eef1f5); } .tl-stage.studio::after, .tl-stage.scene::after { display: none; }
.tl-stage.scene img { object-fit: cover; padding: 0; }
.hero-sub { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }
.hs-chip { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; padding: 4px 11px; border-radius: 999px; background: rgba(255,255,255,.05); border: 1px solid var(--border); }
.hs-chip i { width: 11px; height: 11px; border-radius: 50%; border: 1px solid rgba(255,255,255,.35); }
.tl-plate { display: inline-block; direction: ltr; font-family: 'SFMono-Regular', Consolas, monospace; font-size: 15px; font-weight: 900; letter-spacing: .12em; color: #111827;
    background: linear-gradient(#fefce8, #fde68a); border: 2px solid #1f2937; border-radius: 8px; padding: 2px 10px; box-shadow: 0 0 0 1px #fde68a; margin-inline-start: 4px; }
.hero-price { display: inline-flex; flex-direction: column; margin-inline-start: 10px; vertical-align: middle; padding: 6px 14px; border-radius: 14px; background: rgba(34,197,94,.07); border: 1px solid rgba(34,197,94,.22); }
.hero-price small { font-size: 10px; color: var(--muted); font-weight: 700; }
.hero-price b { font-size: 20px; color: var(--green); font-variant-numeric: tabular-nums; line-height: 1.2; }
.hero-price em { font-style: normal; font-size: 11px; color: var(--muted); margin-inline-start: 4px; }
.hp-tag { font-size: 11px; font-weight: 800; color: #fbbf24; }

/* quick actions */
.tl-acts { display: flex; gap: 8px; flex-wrap: wrap; margin: -8px 0 22px; }
.tl-actf { display: contents; }
.tl-act { height: 40px; padding: 0 16px; border-radius: 12px; display: inline-flex; align-items: center; gap: 6px; font: inherit; font-size: 13px; font-weight: 800; cursor: pointer; text-decoration: none;
    color: var(--text); background: rgba(255,255,255,.04); border: 1px solid var(--border); transition: var(--t); }
.tl-act:hover { border-color: rgba(168,85,247,.45); background: rgba(124,58,237,.1); }
.tl-act.sell { background: linear-gradient(135deg, var(--green), #16a34a); border-color: transparent; color: #fff; box-shadow: 0 6px 20px rgba(34,197,94,.25); }
.tl-act.res { border-color: rgba(250,204,21,.35); color: #fde68a; }

/* where the time went */
.tb-bar { display: flex; gap: 3px; height: 18px; border-radius: 999px; overflow: hidden; background: rgba(255,255,255,.04); }
.tb-seg { min-width: 6px; transform-origin: inline-start; animation: tbGrow .9s cubic-bezier(.22,1,.36,1) both; }
@keyframes tbGrow { from { transform: scaleX(0); } }
.tb-seg.c0, .tb-legend i.c0 { background: linear-gradient(90deg, #7c3aed, #a855f7); }
.tb-seg.c1, .tb-legend i.c1 { background: linear-gradient(90deg, #0ea5e9, #38bdf8); }
.tb-seg.c2, .tb-legend i.c2 { background: linear-gradient(90deg, #16a34a, #4ade80); }
.tb-seg.c3, .tb-legend i.c3 { background: linear-gradient(90deg, #db2777, #f472b6); }
.tb-seg.c4, .tb-legend i.c4 { background: linear-gradient(90deg, #0d9488, #2dd4bf); }
.tb-seg.k-reserved, .tb-legend i.k-reserved { background: repeating-linear-gradient(45deg, #ca8a04 0 6px, #facc15 6px 12px); }
.tb-seg.k-amana, .tb-legend i.k-amana { background: repeating-linear-gradient(45deg, #d97706 0 6px, #f59e0b 6px 12px); }
.tb-legend { display: flex; flex-wrap: wrap; gap: 8px 16px; margin-top: 14px; }
.tb-l { display: inline-flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 700; color: #cbd5e1; }
.tb-l i { width: 12px; height: 12px; border-radius: 4px; }
.tb-l b { color: var(--text); font-weight: 900; }

/* timeline tools */
.tl-tools { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin: -6px 0 18px; }
.tl-chips { display: flex; gap: 6px; flex-wrap: wrap; }
.tl-chip { height: 32px; padding: 0 12px; border-radius: 999px; border: 1px solid var(--border); background: var(--bg-input); color: #94a3b8; font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: var(--t); }
.tl-chip b { font-size: 11px; background: rgba(255,255,255,.07); border-radius: 999px; padding: 0 7px; color: var(--text); }
.tl-chip.on { background: rgba(124,58,237,.2); border-color: var(--purple-lt); color: #fff; }
.tl-tbtns { display: flex; gap: 6px; }
.tl-tbtn { height: 32px; padding: 0 12px; border-radius: 10px; border: 1px solid var(--border); background: transparent; color: #94a3b8; font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; }
.tl-tbtn:hover { color: var(--text); border-color: rgba(168,85,247,.4); }

/* richer steps */
.tl-rel { display: inline-block; margin-inline-start: 8px; font-size: 11px; font-weight: 800; color: #a78bfa; background: rgba(124,58,237,.1); border-radius: 999px; padding: 1px 9px; }
.tl-gap { display: flex; align-items: center; gap: 8px; margin: -12px 0 12px; padding-inline-start: 52px; font-size: 11px; font-weight: 700; color: var(--muted); }
.tl-gap span { background: rgba(255,255,255,.03); border: 1px dashed rgba(255,255,255,.1); border-radius: 999px; padding: 2px 10px; }
.tl-item.latest .tl-body { border-color: rgba(34,197,94,.4); box-shadow: 0 0 0 3px rgba(34,197,94,.07), 0 10px 30px rgba(0,0,0,.25); }
.tl-item.latest .tl-dot { box-shadow: 0 0 0 5px rgba(34,197,94,.14); animation: tlPulse 2s ease-in-out infinite; }
@keyframes tlPulse { 50% { box-shadow: 0 0 0 9px rgba(34,197,94,.05); } }
.tl-latest-b { display: inline-block; margin-inline-start: 8px; font-size: 10px; font-weight: 900; color: #052e16; background: var(--green); border-radius: 999px; padding: 1px 8px; vertical-align: 3px; }
.tl-dot.ev-price.up { background: rgba(239,68,68,.12); border-color: rgba(239,68,68,.6); }
.tl-dot.ev-price.down { background: rgba(34,197,94,.12); border-color: rgba(34,197,94,.6); }
.tl-title.ev-price.up { color: #fca5a5; } .tl-title.ev-price.down { color: var(--green-lt); }
.tl-body-price { background: linear-gradient(135deg, rgba(255,255,255,.02), var(--bg-input)); border-style: dashed; }
.tl-note-amana { background: rgba(245,158,11,.08); border-color: rgba(245,158,11,.3); color: #fcd34d; }
.tl-item.rv { opacity: 0; transform: translateY(14px); }
.tl-item { transition: opacity .5s ease, transform .5s cubic-bezier(.22,1,.36,1); }
.tl-item.hide, .tl-gap.hide { display: none; }
.tl-toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%) translateY(20px); background: #0f2a1a; border: 1px solid rgba(34,197,94,.4); color: #dcfce7;
    font-size: 13px; font-weight: 800; padding: 10px 18px; border-radius: 999px; opacity: 0; transition: all .3s; pointer-events: none; z-index: 99; }
.tl-toast.on { opacity: 1; transform: translateX(-50%); }

@media (max-width: 600px) {
    .hero { flex-direction: column; align-items: stretch; }
    .hero-icon.tl-stage { width: 100%; height: auto; aspect-ratio: 16/9; }
    .hero-price { display: flex; margin: 10px 0 0; }
    .tl-acts { position: sticky; bottom: 10px; z-index: 20; flex-wrap: nowrap; overflow-x: auto; scrollbar-width: none; padding: 8px; margin: 0 -6px 18px;
        background: rgba(10,18,40,.92); backdrop-filter: blur(12px); border: 1px solid var(--border); border-radius: 16px; box-shadow: 0 12px 30px rgba(0,0,0,.5); }
    .tl-acts::-webkit-scrollbar { display: none; }
    .tl-act { flex-shrink: 0; height: 38px; padding: 0 13px; }
    .tl-tbtn span { display: none; }
    .rs-line { height: 22px; }
    .rs-label { padding: 9px 12px; font-size: 13px; }
    .tl-gap { padding-inline-start: 46px; }
}
@media (prefers-reduced-motion: reduce) { .tb-seg { animation: none; } .tl-item.rv { opacity: 1; transform: none; } .tl-item.latest .tl-dot { animation: none; } }
@media print {
    body { background: #fff !important; color: #111 !important; padding: 0; }
    .card { background: #fff !important; box-shadow: none !important; border: 1px solid #ddd !important; backdrop-filter: none; break-inside: avoid; }
    .topbar-actions, .tl-acts, .tl-tools, .footer-link, .tl-toast { display: none !important; }
    .tl-body, .stat-card, .rs-label { background: #fafafa !important; color: #111 !important; }
    .tl-item.rv { opacity: 1; transform: none; }
    .tl-item.hide, .tl-gap.hide { display: flex !important; }
    .page-sub, .tl-date, .stat-label, .tl-fact-label { color: #555 !important; }
}
</style>
<div class="tl-toast" id="tlToast"></div>
<script>
(function () {
    'use strict';
    const AR = <?= json_encode($lang === 'ar') ?>;
    const arDays = d => d === 1 ? 'يوم' : d === 2 ? 'يومين' : d + (d >= 3 && d <= 10 ? ' أيام' : ' يوم');
    const T = AR ? {
        all: 'الكل', add: 'إضافة', transfer: 'نقل', reserve: 'حجز', amana: 'أمانة', sale: 'بيع', price: 'السعر',
        ago: s => { const d = Math.floor(s / 86400); if (d < 1) { const h = Math.floor(s / 3600); return h < 1 ? 'الآن' : 'منذ ' + (h === 1 ? 'ساعة' : h === 2 ? 'ساعتين' : h + (h <= 10 ? ' ساعات' : ' ساعة')); } if (d === 1) return 'أمس'; if (d < 30) return 'منذ ' + arDays(d); const m = Math.round(d / 30); return m < 12 ? 'منذ ' + (m === 1 ? 'شهر' : m === 2 ? 'شهرين' : m + (m <= 10 ? ' أشهر' : ' شهر')) : 'منذ ' + (d / 365).toFixed(1) + ' سنة'; },
        after: d => '⏳ بعد ' + arDays(d), latest: 'الأحدث', copied: '✓ تم نسخ ملخص الرحلة', chassis: 'الشاسيه', route: 'المسار', days: 'يوم'
    } : {
        all: 'All', add: 'Added', transfer: 'Transfers', reserve: 'Reservations', amana: 'Consignment', sale: 'Sale', price: 'Price',
        ago: s => { const d = Math.floor(s / 86400); if (d < 1) { const h = Math.floor(s / 3600); return h < 1 ? 'just now' : h + 'h ago'; } if (d === 1) return 'yesterday'; if (d < 30) return d + ' days ago'; const m = Math.round(d / 30); return m < 12 ? m + ' months ago' : (d / 365).toFixed(1) + ' years ago'; },
        after: d => '⏳ ' + d + (d === 1 ? ' day later' : ' days later'), latest: 'Latest', copied: '✓ Journey summary copied', chassis: 'Chassis', route: 'Route', days: 'days'
    };
    const $ = id => document.getElementById(id);
    const esc = v => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const now = Math.floor(Date.now() / 1000);

    /* ═══ hero photo (or the car drawn in its colour) ═══ */
    const st = $('tlStage');
    if (st) {
        const hex = getComputedStyle(st).getPropertyValue('--car').trim() || '#64748b';
        const sil = '<svg viewBox="0 0 320 130" aria-hidden="true"><defs><linearGradient id="tlSh" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff" stop-opacity=".38"/><stop offset=".45" stop-color="#fff" stop-opacity=".06"/><stop offset="1" stop-color="#000" stop-opacity=".35"/></linearGradient></defs>' +
            '<ellipse cx="163" cy="119" rx="142" ry="7" fill="#000" opacity=".5"/>' +
            '<path fill="' + hex + '" d="M20,92 L22,72 Q24,62 36,60 L80,55 L112,31 Q118,26 128,26 L222,26 Q234,26 242,34 L268,57 L292,61 Q306,64 306,78 L306,92 Q306,98 300,98 L277,98 A27,27 0 0 0 223,98 L105,98 A27,27 0 0 0 51,98 L26,98 Q20,98 20,92 Z"/>' +
            '<path fill="url(#tlSh)" d="M20,92 L22,72 Q24,62 36,60 L80,55 L112,31 Q118,26 128,26 L222,26 Q234,26 242,34 L268,57 L292,61 Q306,64 306,78 L306,92 Q306,98 300,98 L277,98 A27,27 0 0 0 223,98 L105,98 A27,27 0 0 0 51,98 L26,98 Q20,98 20,92 Z"/>' +
            '<path d="M88,57 L116,35 Q120,32 126,32 L166,32 L166,57 Z M174,32 L221,32 Q229,32 235,38 L256,57 L174,57 Z" fill="#0b1220" opacity=".82"/>' +
            '<path d="M292,70 L304,72" stroke="#fde68a" stroke-width="4" stroke-linecap="round"/><path d="M22,74 L30,73" stroke="#f87171" stroke-width="4" stroke-linecap="round"/>' +
            '<circle cx="78" cy="98" r="21" fill="#0b1220" stroke="#1e293b" stroke-width="5"/><circle cx="78" cy="98" r="9" fill="#94a3b8"/>' +
            '<circle cx="250" cy="98" r="21" fill="#0b1220" stroke="#1e293b" stroke-width="5"/><circle cx="250" cy="98" r="9" fill="#94a3b8"/></svg>';
        if (st.dataset.img) {
            st.innerHTML = '<img src="' + esc(st.dataset.img) + '" alt="">';
            const img = st.querySelector('img');
            img.addEventListener('error', () => { st.innerHTML = sil; }, { once: true });
            const go = () => { try {
                const w = 60, h = Math.max(12, Math.round(w * img.naturalHeight / img.naturalWidth)), cv = document.createElement('canvas'); cv.width = w; cv.height = h;
                const cx = cv.getContext('2d', { willReadFrequently: true }); cx.drawImage(img, 0, 0, w, h); const d = cx.getImageData(0, 0, w, h).data, ring = [];
                const at = (x, y) => { const i = (y * w + x) * 4; return [d[i], d[i + 1], d[i + 2]]; };
                for (let x = 0; x < w; x += 3) { ring.push(at(x, 0)); ring.push(at(x, h - 1)); } for (let y = 0; y < h; y += 2) { ring.push(at(0, y)); ring.push(at(w - 1, y)); }
                const m = [0, 1, 2].map(k => ring.reduce((a, p) => a + p[k], 0) / ring.length);
                const sp = Math.sqrt(ring.reduce((a, p) => a + (p[0] - m[0]) ** 2 + (p[1] - m[1]) ** 2 + (p[2] - m[2]) ** 2, 0) / ring.length);
                if (sp > 34) st.classList.add('scene'); else if (.299 * m[0] + .587 * m[1] + .114 * m[2] > 226) st.classList.add('studio');
            } catch (e) {} };
            if (img.complete && img.naturalWidth) go(); else img.addEventListener('load', go, { once: true });
        } else st.innerHTML = sil;
    }

    /* ═══ timeline: relative times, gaps, latest ═══ */
    const tl = document.querySelector('.timeline');
    const items = Array.from(tl.querySelectorAll('.tl-item[data-ts]'));
    items.forEach((it, i) => {
        const ts = +it.dataset.ts, date = it.querySelector('.tl-date');
        if (date && ts) date.insertAdjacentHTML('beforeend', '<span class="tl-rel">' + esc(T.ago(Math.max(0, now - ts))) + '</span>');
        if (i > 0) {
            const gap = Math.floor((ts - +items[i - 1].dataset.ts) / 86400);
            if (gap >= 1) it.insertAdjacentHTML('beforebegin', '<div class="tl-gap"><span>' + esc(T.after(gap)) + '</span></div>');
        }
    });
    const last = items[items.length - 1];
    if (last && items.length > 1) { last.classList.add('latest'); const tt = last.querySelector('.tl-title'); if (tt) tt.insertAdjacentHTML('beforeend', '<span class="tl-latest-b">' + esc(T.latest) + '</span>'); }

    /* ═══ filter chips ═══ */
    const counts = {}; items.forEach(it => counts[it.dataset.k] = (counts[it.dataset.k] || 0) + 1);
    const kinds = ['transfer', 'reserve', 'amana', 'sale', 'price'].filter(k => counts[k]);
    const chips = $('tlChips');
    if (kinds.length >= 2) {
        chips.innerHTML = '<button type="button" class="tl-chip on" data-k="">' + esc(T.all) + ' <b>' + items.length + '</b></button>' +
            kinds.map(k => '<button type="button" class="tl-chip" data-k="' + k + '">' + esc(T[k]) + ' <b>' + counts[k] + '</b></button>').join('');
        chips.addEventListener('click', e => {
            const b = e.target.closest('.tl-chip'); if (!b) return;
            chips.querySelectorAll('.tl-chip').forEach(x => x.classList.toggle('on', x === b));
            const k = b.dataset.k;
            items.forEach(it => it.classList.toggle('hide', !!k && it.dataset.k !== k && it.dataset.k !== 'add'));
            tl.querySelectorAll('.tl-gap').forEach(g => g.classList.toggle('hide', !!k));
        });
    }

    /* ═══ steps slide in ═══ */
    if ('IntersectionObserver' in window && !(matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches)) {
        const io = new IntersectionObserver(en => en.forEach(e => { if (e.isIntersecting) { e.target.classList.remove('rv'); io.unobserve(e.target); } }), { rootMargin: '0px 0px -30px 0px' });
        items.forEach((it, i) => { if (it.getBoundingClientRect().top > innerHeight) { it.classList.add('rv'); io.observe(it); } });
    }

    /* ═══ copy a short journey summary ═══ */
    const share = $('tlShare');
    if (share) share.addEventListener('click', () => {
        const name = document.querySelector('.hero-name').textContent.replace(/\s+/g, ' ').trim();
        const plate = (document.querySelector('.tl-plate') || {}).textContent || '';
        const status = document.querySelector('.status-pill').textContent.replace(/\s+/g, ' ').trim();
        const route = Array.from(document.querySelectorAll('.rs-label')).map(l => l.childNodes[0].textContent.trim()).filter(Boolean);
        const lines = ['🚗 ' + name, '🔑 ' + T.chassis + ': ' + plate.trim(), status];
        if (route.length > 1) lines.push('🗺 ' + T.route + ': ' + route.join(AR ? ' ← ' : ' → '));
        items.filter(it => !it.classList.contains('hide')).forEach(it => {
            const tt = it.querySelector('.tl-title'), dd = it.querySelector('.tl-date');
            const title = tt ? Array.from(tt.childNodes).filter(n => n.nodeType === 3).map(n => n.textContent).join('').trim() : '';
            const date = dd ? Array.from(dd.childNodes).filter(n => n.nodeType === 3).map(n => n.textContent).join('').replace('📅', '').trim() : '';
            lines.push('• ' + title + ' — ' + date);
        });
        const txt = lines.join('\n'), done = () => { const t = $('tlToast'); t.textContent = T.copied; t.classList.add('on'); setTimeout(() => t.classList.remove('on'), 1800); };
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(txt).then(done, done);
        else { const ta = document.createElement('textarea'); ta.value = txt; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch (e) {} ta.remove(); done(); }
    });
})();
</script>

</body>
</html>