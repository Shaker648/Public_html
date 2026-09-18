<?php
/*
 * ads.php — إدارة الإعلانات / Ad Manager
 *
 * One page for every platform you advertise a car on. The model is deliberately
 * simple: an ad is a car + a platform + a start date + an end date, and the only
 * difference between platforms is who sets the end date (see ads_helpers.php).
 *
 * What it is actually for:
 *   - Live ads still running on cars that are ALREADY SOLD  → wasted points/money
 *   - Ads about to expire                                   → renew before they die
 *   - Cars sitting in stock that were never advertised      → nobody is selling them
 *
 * Permission-gated end to end (default: admin only, editable in the Permission
 * Center): page.ads / ads.create / ads.close / ads.cost / ads.platforms.
 */

require 'auth.php';
require 'config.php';
require 'ads_helpers.php';

perm_require('page.ads');

$lang = $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar', 'en'], true)) $lang = 'ar';
$dir   = $lang === 'ar' ? 'rtl' : 'ltr';
$isRTL = $lang === 'ar';

$canCreate    = can('ads.create');
$canClose     = can('ads.close');
$canCost      = can('ads.cost');
$canPlatforms = can('ads.platforms');

$schemaOk  = ads_ensure_tables($pdo);
$csrfToken = ads_csrf_token();

$t = [
    'ar' => [
        'title'        => 'إدارة الإعلانات',
        'subtitle'     => 'إعلانات السيارات على كل المنصات',
        'dashboard'    => 'الرئيسية',
        'new_ad'       => '➕ إعلان جديد',
        'stat_live'    => 'إعلانات نشطة',
        'stat_soon'    => 'تنتهي قريباً',
        'stat_waste'   => 'إعلانات على سيارات مباعة',
        'stat_noad'    => 'سيارات بدون إعلان',
        'alert_waste'  => '🚨 إعلانات ما زالت تعمل على سيارات مباعة',
        'alert_waste_s'=> 'أغلقها الآن — هذه نقاط وفلوس تُصرف على سيارات لم تعد لديك',
        'alert_soon'   => '⏳ إعلانات تنتهي خلال ٣ أيام',
        'alert_soon_s' => 'جدّدها قبل أن تسقط من المنصة',
        'alert_noad'   => '💤 سيارات في المخزون بدون أي إعلان',
        'alert_noad_s' => 'موجودة في المخزون ولم يتم الإعلان عنها أبداً',
        'tab_live'     => 'النشطة',
        'tab_expired'  => 'المنتهية',
        'tab_closed'   => 'المغلقة',
        'tab_all'      => 'الكل',
        'all_platforms'=> 'كل المنصات',
        'all_salesmen' => 'كل البائعين',
        'search_ph'    => 'ابحث بالماركة أو الموديل أو الشاسيه...',
        'filter'       => 'تصفية',
        'reset'        => 'إعادة تعيين',
        'car'          => 'السيارة',
        'platform'     => 'المنصة',
        'salesman'     => 'البائع',
        'select_sales' => 'اختر البائع',
        'start'        => 'تاريخ البدء',
        'end'          => 'تاريخ الانتهاء',
        'days'         => 'المدة بالأيام',
        'days_hint'    => 'اتركها فارغة ليبقى الإعلان مفتوحاً حتى تغلقه بنفسك',
        'no_end'       => 'مفتوح حتى الإغلاق',
        'points'       => 'النقاط',
        'amount'       => 'التكلفة',
        'url'          => 'رابط الإعلان',
        'notes'        => 'ملاحظات',
        'save'         => 'حفظ الإعلان',
        'cancel'       => 'إلغاء',
        'close_ad'     => 'إغلاق',
        'renew_ad'     => 'تجديد',
        'renew_title'  => 'تجديد الإعلان',
        'close_title'  => 'إغلاق الإعلان',
        'close_why'    => 'سبب الإغلاق',
        'why_manual'   => 'أغلقته يدوياً',
        'why_sold'     => 'السيارة اتباعت',
        'why_expired'  => 'انتهت مدته',
        'confirm'      => 'تأكيد',
        'st_live'      => 'نشط',
        'st_expired'   => 'منتهي',
        'st_closed'    => 'مغلق',
        'car_sold'     => '⚠️ السيارة مباعة',
        'car_reserved' => 'محجوزة',
        'days_left'    => 'باقي',
        'days_over'    => 'انتهى منذ',
        'day_unit'     => 'يوم',
        'today'        => 'ينتهي اليوم',
        'renewed_from' => 'تجديد لإعلان سابق',
        'posted_by'    => 'أضافه',
        'closed_by'    => 'أغلقه',
        'empty'        => 'لا توجد إعلانات هنا',
        'empty_sub'    => 'اضغط «إعلان جديد» للبدء',
        'select_car'   => '— اختر السيارة —',
        'select_plat'  => '— اختر المنصة —',
        'plat_settings'=> '⚙️ إعدادات المنصات',
        'plat_hint'    => 'المنصة «ثابتة» يحسب النظام تاريخ انتهائها تلقائياً. المنصة «يدوية» تفضل مفتوحة لحد ما تقفلها بنفسك.',
        'p_type'       => 'نوع المدة',
        'p_fixed'      => 'ثابتة (مدة معروفة)',
        'p_manual'     => 'يدوية (مفتوحة)',
        'p_days'       => 'المدة الافتراضية',
        'p_cost'       => 'التكلفة',
        'p_points'     => 'نقاط',
        'p_perad'      => 'لكل إعلان',
        'p_free'       => 'بدون تكلفة',
        'p_needsales'  => 'يتطلب اسم البائع',
        'p_active'     => 'مفعّلة',
        'p_save'       => 'حفظ الإعدادات',
        'f_created'    => '✓ تم حفظ الإعلان',
        'f_closed'     => '✓ تم إغلاق الإعلان',
        'f_renewed'    => '✓ تم تجديد الإعلان',
        'f_platforms'  => '✓ تم حفظ إعدادات المنصات',
        'f_dupe'       => '⚠️ يوجد إعلان نشط لنفس السيارة على نفس المنصة — استخدم «تجديد»',
        'f_nosales'    => '⚠️ اختر اسم البائع — هذه المنصة تتطلبه',
        'f_missing'    => '⚠️ بيانات ناقصة',
        'f_err'        => '⚠️ تعذّر تنفيذ العملية، حاول مرة أخرى',
        'schema_err'   => '⚠️ تعذّر تجهيز جداول الإعلانات في قاعدة البيانات',
        'advertise'    => 'أعلن عنها',
        'in_stock'     => 'في المخزون',
    ],
    'en' => [
        'title'        => 'Ad Manager',
        'subtitle'     => 'Vehicle listings across every platform',
        'dashboard'    => 'Dashboard',
        'new_ad'       => '➕ New Ad',
        'stat_live'    => 'Live ads',
        'stat_soon'    => 'Expiring soon',
        'stat_waste'   => 'Ads on sold cars',
        'stat_noad'    => 'Cars with no ad',
        'alert_waste'  => '🚨 Ads still running on cars you already sold',
        'alert_waste_s'=> 'Close these now — points and money burning on cars you no longer have',
        'alert_soon'   => '⏳ Ads expiring within 3 days',
        'alert_soon_s' => 'Renew them before they drop off the platform',
        'alert_noad'   => '💤 Cars in stock that were never advertised',
        'alert_noad_s' => 'Sitting in stock with no listing on any platform',
        'tab_live'     => 'Live',
        'tab_expired'  => 'Expired',
        'tab_closed'   => 'Closed',
        'tab_all'      => 'All',
        'all_platforms'=> 'All platforms',
        'all_salesmen' => 'All salesmen',
        'search_ph'    => 'Search by brand, model or chassis...',
        'filter'       => 'Filter',
        'reset'        => 'Reset',
        'car'          => 'Vehicle',
        'platform'     => 'Platform',
        'salesman'     => 'Salesman',
        'select_sales' => 'Select salesman',
        'start'        => 'Start date',
        'end'          => 'End date',
        'days'         => 'Duration in days',
        'days_hint'    => 'Leave empty to keep the ad open until you close it yourself',
        'no_end'       => 'Open until closed',
        'points'       => 'Points',
        'amount'       => 'Cost',
        'url'          => 'Listing URL',
        'notes'        => 'Notes',
        'save'         => 'Save ad',
        'cancel'       => 'Cancel',
        'close_ad'     => 'Close',
        'renew_ad'     => 'Renew',
        'renew_title'  => 'Renew ad',
        'close_title'  => 'Close ad',
        'close_why'    => 'Reason',
        'why_manual'   => 'Closed it manually',
        'why_sold'     => 'The car was sold',
        'why_expired'  => 'It ran out',
        'confirm'      => 'Confirm',
        'st_live'      => 'Live',
        'st_expired'   => 'Expired',
        'st_closed'    => 'Closed',
        'car_sold'     => '⚠️ Car already sold',
        'car_reserved' => 'Reserved',
        'days_left'    => 'left',
        'days_over'    => 'ended',
        'day_unit'     => 'd',
        'today'        => 'ends today',
        'renewed_from' => 'renewal of an earlier ad',
        'posted_by'    => 'Posted by',
        'closed_by'    => 'Closed by',
        'empty'        => 'No ads here',
        'empty_sub'    => 'Press "New Ad" to start',
        'select_car'   => '— Select vehicle —',
        'select_plat'  => '— Select platform —',
        'plat_settings'=> '⚙️ Platform settings',
        'plat_hint'    => 'A "fixed" platform gets its end date computed automatically. A "manual" platform stays live until you close it.',
        'p_type'       => 'Duration type',
        'p_fixed'      => 'Fixed (known length)',
        'p_manual'     => 'Manual (open ended)',
        'p_days'       => 'Default days',
        'p_cost'       => 'Cost',
        'p_points'     => 'Points',
        'p_perad'      => 'Per ad',
        'p_free'       => 'Free',
        'p_needsales'  => 'Requires salesman',
        'p_active'     => 'Active',
        'p_save'       => 'Save settings',
        'f_created'    => '✓ Ad saved',
        'f_closed'     => '✓ Ad closed',
        'f_renewed'    => '✓ Ad renewed',
        'f_platforms'  => '✓ Platform settings saved',
        'f_dupe'       => '⚠️ A live ad already exists for this car on this platform — use Renew',
        'f_nosales'    => '⚠️ Pick a salesman — this platform requires one',
        'f_missing'    => '⚠️ Missing data',
        'f_err'        => '⚠️ Could not complete the action, please try again',
        'schema_err'   => '⚠️ Could not prepare the ad tables in the database',
        'advertise'    => 'Advertise',
        'in_stock'     => 'in stock',
    ],
];
$L = $t[$lang];

/* ─── Flash messages ─── */
$flashMap = [
    'created'   => ['f_created',   'ok'],
    'closed'    => ['f_closed',    'ok'],
    'renewed'   => ['f_renewed',   'ok'],
    'platforms' => ['f_platforms', 'ok'],
    'dupe'      => ['f_dupe',      'warn'],
    'nosalesman'=> ['f_nosales',   'warn'],
    'missing'   => ['f_missing',   'warn'],
    'err'       => ['f_err',       'warn'],
];
$flashKey  = $_GET['flash'] ?? '';
$flashText = '';
$flashKind = 'ok';
if (isset($flashMap[$flashKey])) {
    $flashText = $L[$flashMap[$flashKey][0]];
    $flashKind = $flashMap[$flashKey][1];
}

/* ─── Filters ─── */
$tab         = $_GET['tab'] ?? 'live';
if (!in_array($tab, ['live', 'expired', 'closed', 'all'], true)) $tab = 'live';
$fPlatform   = (int)($_GET['platform'] ?? 0);
$fSalesman   = trim($_GET['salesman'] ?? '');
$search      = trim($_GET['search'] ?? '');

$platforms = $schemaOk ? ads_platforms($pdo) : [];
$salesmen  = ads_salesmen($pdo);

/* ─── Load every ad with its car and platform ─── */
$statusSql = ads_status_sql('l');
$listings  = [];

if ($schemaOk) {
    $where  = [];
    $params = [];

    if ($fPlatform > 0) { $where[] = "l.platform_id = ?"; $params[] = $fPlatform; }
    if ($fSalesman !== '') { $where[] = "l.salesman = ?"; $params[] = $fSalesman; }
    if ($search !== '') {
        $where[] = "(c.brand LIKE ? OR c.model LIKE ? OR c.trim_name LIKE ? OR c.chassis LIKE ? OR l.salesman LIKE ?)";
        for ($i = 0; $i < 5; $i++) $params[] = "%$search%";
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    try {
        $sql = "
            SELECT
                l.*,
                $statusSql AS ad_status,
                c.brand, c.model, c.trim_name, c.car_year, c.color, c.chassis,
                c.branch, c.status AS car_status,
                col.color_ar, col.color_en,
                br.name_ar AS branch_ar, br.name_en AS branch_en,
                p.name_ar AS p_ar, p.name_en AS p_en, p.icon AS p_icon,
                p.duration_type, p.cost_type, p.requires_salesman
            FROM car_listings l
            JOIN cars         c  ON c.id  = l.car_id
            JOIN ad_platforms p  ON p.id  = l.platform_id
            LEFT JOIN colors   col ON c.color  = col.color_en
            LEFT JOIN branches br  ON c.branch = br.name
            $whereSql
            ORDER BY (l.closed_at IS NOT NULL), l.planned_end IS NULL, l.planned_end ASC, l.id DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('ads list failed: ' . $e->getMessage());
    }
}

/* ─── Buckets and the three things this page exists for ─── */
$live = $expired = $closed = [];
$wasted = [];      // live ad, car already sold  → burning money
$soon   = [];      // live ad ending within 3 days

foreach ($listings as $l) {
    switch ($l['ad_status']) {
        case 'live':    $live[] = $l;    break;
        case 'expired': $expired[] = $l; break;
        default:        $closed[] = $l;  break;
    }
    if ($l['ad_status'] === 'live') {
        if ($l['car_status'] === 'sold') {
            $wasted[] = $l;
        }
        $dl = ads_days_left($l['planned_end']);
        if ($dl !== null && $dl >= 0 && $dl <= 3) $soon[] = $l;
    }
}

/* ─── Cars in stock with no live ad at all ─── */
$noAdCars = [];
if ($schemaOk) {
    try {
        $noAdCars = $pdo->query("
            SELECT c.id, c.brand, c.model, c.trim_name, c.car_year, c.chassis, c.branch,
                   c.status, DATEDIFF(NOW(), c.created_at) AS age_days
            FROM cars c
            WHERE c.status IN ('available','reserved')
              AND NOT EXISTS (
                  SELECT 1 FROM car_listings l
                  WHERE l.car_id = c.id
                    AND l.closed_at IS NULL
                    AND (l.planned_end IS NULL OR l.planned_end >= CURDATE())
              )
            ORDER BY age_days DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('ads no-ad query failed: ' . $e->getMessage());
    }
}

/* ─── Cars available to advertise (for the new-ad picker) ─── */
$pickCars = [];
try {
    $pickCars = $pdo->query("
        SELECT c.id, c.brand, c.model, c.trim_name, c.car_year, c.color, c.chassis, c.branch, c.status
        FROM cars c
        WHERE c.status IN ('available','reserved')
        ORDER BY c.brand, c.model, c.trim_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('ads car picker failed: ' . $e->getMessage());
}

/* Which bucket the tab shows */
$shown = $tab === 'live' ? $live : ($tab === 'expired' ? $expired : ($tab === 'closed' ? $closed : $listings));

/* Platform config for the new-ad form JS */
$platJs = [];
foreach ($platforms as $p) {
    $platJs[(int)$p['id']] = [
        'name'      => ads_platform_name($p, $lang),
        'type'      => $p['duration_type'],
        'days'      => $p['default_days'] !== null ? (int)$p['default_days'] : null,
        'cost'      => $p['cost_type'],
        'needSales' => (int)$p['requires_salesman'] === 1,
    ];
}

function adsEsc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Car label used in lists and pickers. */
function adsCarLabel(array $c): string
{
    $bits = array_filter([
        $c['brand'] ?? '', $c['model'] ?? '', $c['trim_name'] ?? '', $c['car_year'] ?? '',
    ], fn($x) => trim((string)$x) !== '');
    return implode(' ', $bits);
}

$other_lang = $lang === 'ar' ? 'en' : 'ar';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#020617">
<title><?= adsEsc($L['title']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root {
    --bg-deep:#020617; --bg-card:rgba(15,23,42,.93); --border:rgba(255,255,255,.07);
    --green:#22c55e; --green-d:rgba(34,197,94,.12);
    --purple:#9333ea; --purple-d:rgba(147,51,234,.12);
    --blue:#2563eb;  --blue-d:rgba(37,99,235,.12);
    --amber:#f59e0b; --amber-d:rgba(245,158,11,.12);
    --red:#ef4444;   --red-d:rgba(239,68,68,.12);
    --gold:#eab308;  --gold-d:rgba(234,179,8,.12);
    --text:#f1f5f9; --muted:#64748b; --muted-l:#94a3b8;
    --r-card:20px; --r-btn:12px; --shadow:0 4px 28px rgba(0,0,0,.45);
}
html[lang="ar"] body { font-family:'Cairo',sans-serif; }
html[lang="en"] body { font-family:'Inter',sans-serif; }
body {
    background:linear-gradient(150deg,#020617 0%,#0a0f1e 55%,#05101f 100%);
    color:var(--text); min-height:100vh; padding:16px 14px 90px; overflow-x:hidden;
}
.wrap { max-width:1100px; margin:0 auto; }

/* ── top bar ── */
.topbar {
    display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
    background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-card);
    padding:16px 20px; margin-bottom:14px; backdrop-filter:blur(20px); box-shadow:var(--shadow);
}
.topbar h1 { font-size:20px; font-weight:900; letter-spacing:-.01em; }
.topbar .sub { font-size:12.5px; color:var(--muted-l); font-weight:600; margin-top:3px; }
.top-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.btn {
    border:none; cursor:pointer; font-family:inherit; font-weight:800; font-size:13px;
    padding:10px 16px; border-radius:var(--r-btn); color:#fff; text-decoration:none;
    display:inline-flex; align-items:center; gap:6px; transition:transform .15s, box-shadow .15s;
}
.btn:hover { transform:translateY(-2px); }
.btn-primary { background:linear-gradient(90deg,var(--purple),#a855f7); box-shadow:0 6px 22px rgba(147,51,234,.28); }
.btn-ghost   { background:rgba(255,255,255,.06); border:1px solid var(--border); color:var(--muted-l); }
.btn-amber   { background:linear-gradient(90deg,#b45309,var(--amber)); box-shadow:0 6px 22px rgba(245,158,11,.25); }
.btn-red     { background:linear-gradient(90deg,#b91c1c,var(--red)); box-shadow:0 6px 22px rgba(239,68,68,.25); }
.btn-sm      { padding:7px 12px; font-size:12px; border-radius:10px; }

/* ── flash ── */
.flash {
    border-radius:14px; padding:13px 18px; margin-bottom:14px;
    font-size:13.5px; font-weight:700; text-align:center;
}
.flash.ok   { background:var(--green-d); border:1px solid rgba(34,197,94,.35); color:#86efac; }
.flash.warn { background:var(--amber-d); border:1px solid rgba(245,158,11,.35); color:#fcd34d; }

/* ── stats ── */
.stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin-bottom:14px; }
.stat {
    background:var(--bg-card); border:1px solid var(--border); border-radius:16px;
    padding:14px 16px; backdrop-filter:blur(20px);
}
.stat .n { font-size:26px; font-weight:900; line-height:1.1; }
.stat .l { font-size:11.5px; font-weight:700; color:var(--muted-l); margin-top:4px; }
.stat.green .n { color:var(--green); }
.stat.amber .n { color:var(--amber); }
.stat.red   .n { color:var(--red); }
.stat.blue  .n { color:#60a5fa; }

/* ── alert panels ── */
.alert { border-radius:var(--r-card); padding:16px 18px; margin-bottom:12px; border:1px solid; }
.alert.red   { background:var(--red-d);   border-color:rgba(239,68,68,.3); }
.alert.amber { background:var(--amber-d); border-color:rgba(245,158,11,.3); }
.alert.blue  { background:var(--blue-d);  border-color:rgba(37,99,235,.3); }
.alert h3 { font-size:14.5px; font-weight:900; margin-bottom:3px; }
.alert.red h3 { color:#fca5a5; } .alert.amber h3 { color:#fcd34d; } .alert.blue h3 { color:#93c5fd; }
.alert .s { font-size:12px; color:var(--muted-l); font-weight:600; margin-bottom:10px; }
.alert ul { list-style:none; display:flex; flex-direction:column; gap:6px; }
.alert li {
    background:rgba(0,0,0,.25); border-radius:10px; padding:9px 12px; font-size:12.5px;
    display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;
}
.alert li .who { font-weight:700; }
.alert li .meta { color:var(--muted-l); font-size:11.5px; font-weight:600; }
.alert details summary { cursor:pointer; font-size:12px; color:var(--muted-l); font-weight:700; margin-top:8px; }

/* ── filters ── */
.filters {
    background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-card);
    padding:14px 16px; margin-bottom:14px; backdrop-filter:blur(20px);
}
.tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px; }
.tab {
    text-decoration:none; padding:8px 14px; border-radius:10px; font-size:12.5px; font-weight:800;
    background:rgba(255,255,255,.05); color:var(--muted-l); border:1px solid var(--border);
}
.tab.on { background:var(--purple); color:#fff; border-color:transparent; }
.tab .c { opacity:.75; font-weight:700; }
.frow { display:flex; gap:8px; flex-wrap:wrap; }
.frow input, .frow select {
    flex:1; min-width:140px; background:#0d1526; border:1px solid var(--border); color:var(--text);
    padding:10px 12px; border-radius:10px; font-family:inherit; font-size:13.5px; outline:none;
}
.frow input:focus, .frow select:focus { border-color:rgba(147,51,234,.5); }

/* ── ad cards ── */
.grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(310px,1fr)); gap:12px; }
.card {
    background:var(--bg-card); border:1px solid var(--border); border-left:3px solid var(--muted);
    border-radius:var(--r-card); padding:15px 17px; backdrop-filter:blur(20px); box-shadow:var(--shadow);
}
html[dir="rtl"] .card { border-left:1px solid var(--border); border-right:3px solid var(--muted); }
.card.live    { border-inline-start-color:var(--green); }
.card.expired { border-inline-start-color:var(--amber); }
.card.closed  { border-inline-start-color:var(--muted); opacity:.72; }
.card.waste   { border-inline-start-color:var(--red); background:linear-gradient(180deg,rgba(239,68,68,.07),var(--bg-card)); }
.card-top { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; margin-bottom:10px; }
.card-car { font-size:15px; font-weight:900; line-height:1.35; }
.card-sub { font-size:11.5px; color:var(--muted-l); font-weight:600; margin-top:3px; }
.chip {
    display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:50px;
    font-size:10.5px; font-weight:800; white-space:nowrap;
}
.chip.live    { background:var(--green-d); color:#86efac; border:1px solid rgba(34,197,94,.3); }
.chip.expired { background:var(--amber-d); color:#fcd34d; border:1px solid rgba(245,158,11,.3); }
.chip.closed  { background:rgba(255,255,255,.05); color:var(--muted-l); border:1px solid var(--border); }
.chip.plat    { background:var(--purple-d); color:#d8b4fe; border:1px solid rgba(147,51,234,.3); }
.chip.sold    { background:var(--red-d); color:#fca5a5; border:1px solid rgba(239,68,68,.35); }
.chip.gold    { background:var(--gold-d); color:#fde047; border:1px solid rgba(234,179,8,.3); }
.rows { display:flex; flex-direction:column; gap:6px; margin:10px 0; }
.row { display:flex; align-items:center; justify-content:space-between; gap:8px; font-size:12.5px; }
.row .k { color:var(--muted); font-weight:600; }
.row .v { font-weight:800; }
.countdown { font-size:12px; font-weight:900; }
.countdown.ok   { color:var(--green); }
.countdown.warn { color:var(--amber); }
.countdown.bad  { color:var(--red); }
.card-actions { display:flex; gap:7px; flex-wrap:wrap; margin-top:11px; }
.card a.url { color:#93c5fd; font-size:11.5px; font-weight:700; text-decoration:none; word-break:break-all; }

.empty { text-align:center; padding:60px 20px; color:var(--muted); }
.empty .e-i { font-size:52px; margin-bottom:12px; }
.empty .e-t { font-size:16px; font-weight:800; margin-bottom:5px; color:var(--muted-l); }

/* ── modals ── */
.ov { position:fixed; inset:0; background:rgba(2,6,23,.82); backdrop-filter:blur(6px); z-index:900;
      display:none; align-items:center; justify-content:center; padding:18px; }
.ov.on { display:flex; }
.modal {
    width:100%; max-width:520px; max-height:90vh; overflow-y:auto;
    background:#0a1020; border:1px solid rgba(147,51,234,.25); border-radius:24px;
    padding:22px; box-shadow:0 24px 80px rgba(0,0,0,.8);
}
.modal h2 { font-size:18px; font-weight:900; margin-bottom:4px; }
.modal .mh { font-size:12px; color:var(--muted-l); font-weight:600; margin-bottom:16px; }
.fld { margin-bottom:13px; }
.fld label { display:block; font-size:12.5px; font-weight:800; margin-bottom:6px; color:var(--muted-l); }
.fld input, .fld select, .fld textarea {
    width:100%; background:#0d1526; border:1px solid var(--border); color:var(--text);
    padding:11px 13px; border-radius:11px; font-family:inherit; font-size:14px; outline:none;
}
.fld textarea { resize:vertical; min-height:64px; }
.fld input:focus, .fld select:focus, .fld textarea:focus { border-color:rgba(147,51,234,.5); }
.fld .hint { font-size:11px; color:var(--muted); font-weight:600; margin-top:5px; line-height:1.5; }
.modal-actions { display:flex; gap:8px; margin-top:16px; }
.modal-actions .btn { flex:1; justify-content:center; }

/* ── platform settings ── */
.psec { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-card);
        padding:16px 18px; margin-top:16px; backdrop-filter:blur(20px); }
.psec > summary { cursor:pointer; font-size:14px; font-weight:900; list-style:none; }
.psec > summary::-webkit-details-marker { display:none; }
.phint { font-size:12px; color:var(--muted-l); font-weight:600; margin:10px 0 14px; line-height:1.7; }
.prow { background:rgba(0,0,0,.25); border:1px solid var(--border); border-radius:14px;
        padding:12px 14px; margin-bottom:9px; }
.prow .pname { font-size:14px; font-weight:900; margin-bottom:9px; }
.pgrid { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:8px; }
.pgrid label { font-size:11px; font-weight:700; color:var(--muted); display:block; margin-bottom:4px; }
.pgrid select, .pgrid input[type=number] {
    width:100%; background:#0d1526; border:1px solid var(--border); color:var(--text);
    padding:8px 10px; border-radius:9px; font-family:inherit; font-size:12.5px; outline:none;
}
.pchecks { display:flex; gap:16px; margin-top:10px; flex-wrap:wrap; }
.pchecks label { display:flex; align-items:center; gap:6px; font-size:12px; font-weight:700; color:var(--muted-l); cursor:pointer; }
.pchecks input { width:16px; height:16px; accent-color:var(--purple); cursor:pointer; }

@media (max-width:560px) {
    .topbar h1 { font-size:17px; }
    .grid { grid-template-columns:1fr; }
    .stat .n { font-size:22px; }
}
</style>
</head>
<body>
<div class="wrap">

<div class="topbar">
    <div>
        <h1>📣 <?= adsEsc($L['title']) ?></h1>
        <div class="sub"><?= adsEsc($L['subtitle']) ?></div>
    </div>
    <div class="top-actions">
        <?php if ($canCreate): ?>
            <button type="button" class="btn btn-primary" onclick="openNew(0)"><?= adsEsc($L['new_ad']) ?></button>
        <?php endif; ?>
        <a href="dashboard.php?lang=<?= $lang ?>" class="btn btn-ghost">🏠 <?= adsEsc($L['dashboard']) ?></a>
        <a href="ads.php?lang=<?= $other_lang ?>" class="btn btn-ghost"><?= $isRTL ? 'EN' : 'ع' ?></a>
    </div>
</div>

<?php if (!$schemaOk): ?>
    <div class="flash warn"><?= adsEsc($L['schema_err']) ?></div>
<?php endif; ?>

<?php if ($flashText !== ''): ?>
    <div class="flash <?= adsEsc($flashKind) ?>"><?= adsEsc($flashText) ?></div>
<?php endif; ?>

<!-- ── Stats ── -->
<div class="stats">
    <div class="stat green"><div class="n"><?= count($live) ?></div><div class="l"><?= adsEsc($L['stat_live']) ?></div></div>
    <div class="stat amber"><div class="n"><?= count($soon) ?></div><div class="l"><?= adsEsc($L['stat_soon']) ?></div></div>
    <div class="stat red"><div class="n"><?= count($wasted) ?></div><div class="l"><?= adsEsc($L['stat_waste']) ?></div></div>
    <div class="stat blue"><div class="n"><?= count($noAdCars) ?></div><div class="l"><?= adsEsc($L['stat_noad']) ?></div></div>
</div>

<!-- ── The money leak: live ads on cars already sold ── -->
<?php if (!empty($wasted)): ?>
<div class="alert red">
    <h3><?= adsEsc($L['alert_waste']) ?></h3>
    <div class="s"><?= adsEsc($L['alert_waste_s']) ?></div>
    <ul>
        <?php foreach ($wasted as $w): ?>
        <li>
            <span class="who"><?= adsEsc(adsCarLabel($w)) ?>
                <span class="chip plat"><?= adsEsc($w['p_icon'] . ' ' . ($isRTL ? $w['p_ar'] : $w['p_en'])) ?></span>
            </span>
            <span class="meta">
                <?= adsEsc($w['chassis']) ?>
                <?php if ($canClose): ?>
                    &nbsp;<button type="button" class="btn btn-red btn-sm"
                        onclick="openClose(<?= (int)$w['id'] ?>,'sold')"><?= adsEsc($L['close_ad']) ?></button>
                <?php endif; ?>
            </span>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- ── Expiring within 3 days ── -->
<?php if (!empty($soon)): ?>
<div class="alert amber">
    <h3><?= adsEsc($L['alert_soon']) ?></h3>
    <div class="s"><?= adsEsc($L['alert_soon_s']) ?></div>
    <ul>
        <?php foreach ($soon as $s): $dl = ads_days_left($s['planned_end']); ?>
        <li>
            <span class="who"><?= adsEsc(adsCarLabel($s)) ?>
                <span class="chip plat"><?= adsEsc($s['p_icon'] . ' ' . ($isRTL ? $s['p_ar'] : $s['p_en'])) ?></span>
            </span>
            <span class="meta">
                <?= $dl === 0 ? adsEsc($L['today']) : adsEsc($L['days_left'] . ' ' . $dl . ' ' . $L['day_unit']) ?>
                <?php if ($canCreate): ?>
                    &nbsp;<button type="button" class="btn btn-amber btn-sm"
                        onclick="openRenew(<?= (int)$s['id'] ?>,<?= (int)$s['platform_id'] ?>)"><?= adsEsc($L['renew_ad']) ?></button>
                <?php endif; ?>
            </span>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- ── Never advertised ── -->
<?php if (!empty($noAdCars)): ?>
<div class="alert blue">
    <h3><?= adsEsc($L['alert_noad']) ?></h3>
    <div class="s"><?= adsEsc($L['alert_noad_s']) ?></div>
    <details>
        <summary><?= count($noAdCars) ?> <?= adsEsc($L['in_stock']) ?></summary>
        <ul style="margin-top:9px;">
            <?php foreach (array_slice($noAdCars, 0, 40) as $n): ?>
            <li>
                <span class="who"><?= adsEsc(adsCarLabel($n)) ?></span>
                <span class="meta">
                    <?= adsEsc($n['chassis']) ?> · <?= (int)$n['age_days'] ?> <?= adsEsc($L['day_unit']) ?>
                    <?php if ($canCreate): ?>
                        &nbsp;<button type="button" class="btn btn-ghost btn-sm"
                            onclick="openNew(<?= (int)$n['id'] ?>)"><?= adsEsc($L['advertise']) ?></button>
                    <?php endif; ?>
                </span>
            </li>
            <?php endforeach; ?>
        </ul>
    </details>
</div>
<?php endif; ?>

<!-- ── Filters ── -->
<div class="filters">
    <div class="tabs">
        <?php
        $tabDefs = [
            'live'    => [$L['tab_live'],    count($live)],
            'expired' => [$L['tab_expired'], count($expired)],
            'closed'  => [$L['tab_closed'],  count($closed)],
            'all'     => [$L['tab_all'],     count($listings)],
        ];
        $qsBase = ['lang' => $lang, 'platform' => $fPlatform ?: null, 'salesman' => $fSalesman ?: null, 'search' => $search ?: null];
        foreach ($tabDefs as $k => $def):
            $qs = array_filter(array_merge($qsBase, ['tab' => $k]), fn($v) => $v !== null && $v !== '');
        ?>
        <a class="tab <?= $tab === $k ? 'on' : '' ?>" href="ads.php?<?= http_build_query($qs) ?>">
            <?= adsEsc($def[0]) ?> <span class="c">(<?= $def[1] ?>)</span>
        </a>
        <?php endforeach; ?>
    </div>

    <form method="GET" action="ads.php" class="frow">
        <input type="hidden" name="lang" value="<?= adsEsc($lang) ?>">
        <input type="hidden" name="tab"  value="<?= adsEsc($tab) ?>">
        <input type="text" name="search" value="<?= adsEsc($search) ?>" placeholder="<?= adsEsc($L['search_ph']) ?>">
        <select name="platform">
            <option value="0"><?= adsEsc($L['all_platforms']) ?></option>
            <?php foreach ($platforms as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= $fPlatform === (int)$p['id'] ? 'selected' : '' ?>>
                <?= adsEsc($p['icon'] . ' ' . ads_platform_name($p, $lang)) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select name="salesman">
            <option value=""><?= adsEsc($L['all_salesmen']) ?></option>
            <?php foreach ($salesmen as $s): ?>
            <option value="<?= adsEsc($s) ?>" <?= $fSalesman === $s ? 'selected' : '' ?>><?= adsEsc($s) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary"><?= adsEsc($L['filter']) ?></button>
        <a href="ads.php?lang=<?= $lang ?>" class="btn btn-ghost"><?= adsEsc($L['reset']) ?></a>
    </form>
</div>

<!-- ── Ad list ── -->
<?php if (empty($shown)): ?>
    <div class="empty">
        <div class="e-i">📣</div>
        <div class="e-t"><?= adsEsc($L['empty']) ?></div>
        <div><?= adsEsc($L['empty_sub']) ?></div>
    </div>
<?php else: ?>
<div class="grid">
    <?php foreach ($shown as $a):
        $st        = $a['ad_status'];
        $isWaste   = ($st === 'live' && $a['car_status'] === 'sold');
        $dl        = ads_days_left($a['planned_end']);
        $platName  = $a['p_icon'] . ' ' . ($isRTL ? $a['p_ar'] : $a['p_en']);
        $colorName = $isRTL ? ($a['color_ar'] ?: $a['color']) : ($a['color_en'] ?: $a['color']);
        $branchNm  = $isRTL ? ($a['branch_ar'] ?: $a['branch']) : ($a['branch_en'] ?: $a['branch']);
    ?>
    <div class="card <?= $isWaste ? 'waste' : adsEsc($st) ?>">
        <div class="card-top">
            <div>
                <div class="card-car"><?= adsEsc(adsCarLabel($a)) ?></div>
                <div class="card-sub">
                    <?= adsEsc($colorName) ?> · <?= adsEsc($branchNm) ?> · <?= adsEsc($a['chassis']) ?>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:5px;align-items:flex-end;">
                <span class="chip <?= adsEsc($st) ?>"><?= adsEsc($L['st_' . $st]) ?></span>
                <span class="chip plat"><?= adsEsc($platName) ?></span>
            </div>
        </div>

        <?php if ($isWaste): ?>
            <span class="chip sold"><?= adsEsc($L['car_sold']) ?></span>
        <?php elseif ($a['car_status'] === 'reserved'): ?>
            <span class="chip gold"><?= adsEsc($L['car_reserved']) ?></span>
        <?php endif; ?>

        <div class="rows">
            <?php if (!empty($a['salesman'])): ?>
            <div class="row"><span class="k"><?= adsEsc($L['salesman']) ?></span><span class="v"><?= adsEsc($a['salesman']) ?></span></div>
            <?php endif; ?>

            <div class="row">
                <span class="k"><?= adsEsc($L['start']) ?></span>
                <span class="v"><?= adsEsc(date('Y-m-d', strtotime($a['started_at']))) ?></span>
            </div>

            <div class="row">
                <span class="k"><?= adsEsc($L['end']) ?></span>
                <span class="v">
                    <?php if ($a['planned_end'] === null): ?>
                        <?= adsEsc($L['no_end']) ?>
                    <?php else: ?>
                        <?= adsEsc($a['planned_end']) ?>
                    <?php endif; ?>
                </span>
            </div>

            <?php if ($st === 'live' && $dl !== null): ?>
            <div class="row">
                <span class="k">&nbsp;</span>
                <span class="countdown <?= $dl <= 1 ? 'bad' : ($dl <= 3 ? 'warn' : 'ok') ?>">
                    <?= $dl === 0 ? adsEsc($L['today']) : adsEsc($L['days_left'] . ' ' . $dl . ' ' . $L['day_unit']) ?>
                </span>
            </div>
            <?php elseif ($st === 'expired' && $dl !== null): ?>
            <div class="row">
                <span class="k">&nbsp;</span>
                <span class="countdown bad"><?= adsEsc($L['days_over'] . ' ' . abs($dl) . ' ' . $L['day_unit']) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($canCost && ($a['cost_points'] !== null || $a['cost_amount'] !== null)): ?>
                <?php if ($a['cost_points'] !== null): ?>
                <div class="row"><span class="k"><?= adsEsc($L['points']) ?></span>
                    <span class="v"><?= adsEsc(rtrim(rtrim(number_format((float)$a['cost_points'], 2, '.', ','), '0'), '.')) ?></span></div>
                <?php endif; ?>
                <?php if ($a['cost_amount'] !== null): ?>
                <div class="row"><span class="k"><?= adsEsc($L['amount']) ?></span>
                    <span class="v"><?= adsEsc(number_format((float)$a['cost_amount'], 0, '.', ',')) ?></span></div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!empty($a['renewed_from_id'])): ?>
            <div class="row"><span class="k">🔁</span><span class="v" style="font-size:11.5px;color:var(--muted-l);"><?= adsEsc($L['renewed_from']) ?></span></div>
            <?php endif; ?>

            <?php if ($st === 'closed' && !empty($a['closed_by'])): ?>
            <div class="row"><span class="k"><?= adsEsc($L['closed_by']) ?></span><span class="v"><?= adsEsc($a['closed_by']) ?></span></div>
            <?php endif; ?>

            <?php if (!empty($a['created_by'])): ?>
            <div class="row"><span class="k"><?= adsEsc($L['posted_by']) ?></span><span class="v"><?= adsEsc($a['created_by']) ?></span></div>
            <?php endif; ?>
        </div>

        <?php if (!empty($a['listing_url'])): ?>
            <a class="url" href="<?= adsEsc($a['listing_url']) ?>" target="_blank" rel="noopener noreferrer">🔗 <?= adsEsc($a['listing_url']) ?></a>
        <?php endif; ?>

        <?php if ($st !== 'closed' && ($canClose || $canCreate)): ?>
        <div class="card-actions">
            <?php if ($canCreate): ?>
            <button type="button" class="btn btn-amber btn-sm"
                onclick="openRenew(<?= (int)$a['id'] ?>,<?= (int)$a['platform_id'] ?>)">🔁 <?= adsEsc($L['renew_ad']) ?></button>
            <?php endif; ?>
            <?php if ($canClose): ?>
            <button type="button" class="btn btn-ghost btn-sm"
                onclick="openClose(<?= (int)$a['id'] ?>,'manual')">✕ <?= adsEsc($L['close_ad']) ?></button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Platform settings ── -->
<?php if ($canPlatforms && $schemaOk): ?>
<details class="psec">
    <summary><?= adsEsc($L['plat_settings']) ?></summary>
    <div class="phint"><?= adsEsc($L['plat_hint']) ?></div>
    <form method="POST" action="ads_action.php">
        <input type="hidden" name="csrf_token" value="<?= adsEsc($csrfToken) ?>">
        <input type="hidden" name="action" value="platform_save">
        <input type="hidden" name="lang" value="<?= adsEsc($lang) ?>">
        <?php foreach ($platforms as $p): $pid = (int)$p['id']; ?>
        <div class="prow">
            <input type="hidden" name="p_id[<?= $pid ?>]" value="<?= $pid ?>">
            <div class="pname"><?= adsEsc($p['icon'] . ' ' . ads_platform_name($p, $lang)) ?></div>
            <div class="pgrid">
                <div>
                    <label><?= adsEsc($L['p_type']) ?></label>
                    <select name="p_type[<?= $pid ?>]">
                        <option value="fixed"  <?= $p['duration_type'] === 'fixed'  ? 'selected' : '' ?>><?= adsEsc($L['p_fixed']) ?></option>
                        <option value="manual" <?= $p['duration_type'] === 'manual' ? 'selected' : '' ?>><?= adsEsc($L['p_manual']) ?></option>
                    </select>
                </div>
                <div>
                    <label><?= adsEsc($L['p_days']) ?></label>
                    <input type="number" min="1" max="3650" name="p_days[<?= $pid ?>]"
                           value="<?= $p['default_days'] !== null ? (int)$p['default_days'] : '' ?>">
                </div>
                <div>
                    <label><?= adsEsc($L['p_cost']) ?></label>
                    <select name="p_cost[<?= $pid ?>]">
                        <option value="points" <?= $p['cost_type'] === 'points' ? 'selected' : '' ?>><?= adsEsc($L['p_points']) ?></option>
                        <option value="per_ad" <?= $p['cost_type'] === 'per_ad' ? 'selected' : '' ?>><?= adsEsc($L['p_perad']) ?></option>
                        <option value="free"   <?= $p['cost_type'] === 'free'   ? 'selected' : '' ?>><?= adsEsc($L['p_free']) ?></option>
                    </select>
                </div>
            </div>
            <div class="pchecks">
                <label><input type="checkbox" name="p_salesman[<?= $pid ?>]" value="1" <?= (int)$p['requires_salesman'] === 1 ? 'checked' : '' ?>> <?= adsEsc($L['p_needsales']) ?></label>
                <label><input type="checkbox" name="p_active[<?= $pid ?>]"   value="1" <?= (int)$p['active'] === 1 ? 'checked' : '' ?>> <?= adsEsc($L['p_active']) ?></label>
            </div>
        </div>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-primary" style="margin-top:6px;"><?= adsEsc($L['p_save']) ?></button>
    </form>
</details>
<?php endif; ?>

</div><!-- /wrap -->

<!-- ══════════ New ad modal ══════════ -->
<?php if ($canCreate): ?>
<div class="ov" id="ovNew" onclick="if(event.target===this) closeOv('ovNew')">
    <div class="modal">
        <h2><?= adsEsc($L['new_ad']) ?></h2>
        <div class="mh"><?= adsEsc($L['subtitle']) ?></div>
        <form method="POST" action="ads_action.php" id="formNew">
            <input type="hidden" name="csrf_token" value="<?= adsEsc($csrfToken) ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="lang" value="<?= adsEsc($lang) ?>">

            <div class="fld">
                <label><?= adsEsc($L['car']) ?></label>
                <select name="car_id" id="newCar" required>
                    <option value=""><?= adsEsc($L['select_car']) ?></option>
                    <?php foreach ($pickCars as $c): ?>
                    <option value="<?= (int)$c['id'] ?>">
                        <?= adsEsc(adsCarLabel($c) . ' · ' . $c['color'] . ' · ' . $c['chassis']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="fld">
                <label><?= adsEsc($L['platform']) ?></label>
                <select name="platform_id" id="newPlat" required onchange="platChanged()">
                    <option value=""><?= adsEsc($L['select_plat']) ?></option>
                    <?php foreach ($platforms as $p): if ((int)$p['active'] !== 1) continue; ?>
                    <option value="<?= (int)$p['id'] ?>"><?= adsEsc($p['icon'] . ' ' . ads_platform_name($p, $lang)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="fld" id="fldSalesman" style="display:none;">
                <label><?= adsEsc($L['salesman']) ?></label>
                <select name="salesman" id="newSalesman">
                    <option value=""><?= adsEsc($L['select_sales']) ?></option>
                    <?php foreach ($salesmen as $s): ?>
                    <option value="<?= adsEsc($s) ?>"><?= adsEsc($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="fld">
                <label><?= adsEsc($L['start']) ?></label>
                <input type="date" name="started_at" id="newStart" value="<?= date('Y-m-d') ?>">
            </div>

            <div class="fld">
                <label><?= adsEsc($L['days']) ?></label>
                <input type="number" min="1" max="3650" name="days" id="newDays" placeholder="">
                <div class="hint" id="daysHint"><?= adsEsc($L['days_hint']) ?></div>
            </div>

            <div class="fld" id="fldPoints" style="display:none;">
                <label><?= adsEsc($L['points']) ?></label>
                <input type="number" step="0.01" min="0" name="cost_points">
            </div>

            <div class="fld" id="fldAmount" style="display:none;">
                <label><?= adsEsc($L['amount']) ?></label>
                <input type="number" step="0.01" min="0" name="cost_amount">
            </div>

            <div class="fld">
                <label><?= adsEsc($L['url']) ?></label>
                <input type="url" name="listing_url" placeholder="https://">
            </div>

            <div class="fld">
                <label><?= adsEsc($L['notes']) ?></label>
                <textarea name="notes"></textarea>
            </div>

            <div class="modal-actions">
                <button type="submit" class="btn btn-primary"><?= adsEsc($L['save']) ?></button>
                <button type="button" class="btn btn-ghost" onclick="closeOv('ovNew')"><?= adsEsc($L['cancel']) ?></button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════ Renew modal ══════════ -->
<div class="ov" id="ovRenew" onclick="if(event.target===this) closeOv('ovRenew')">
    <div class="modal" style="max-width:420px;">
        <h2>🔁 <?= adsEsc($L['renew_title']) ?></h2>
        <div class="mh"><?= adsEsc($L['days_hint']) ?></div>
        <form method="POST" action="ads_action.php">
            <input type="hidden" name="csrf_token" value="<?= adsEsc($csrfToken) ?>">
            <input type="hidden" name="action" value="renew">
            <input type="hidden" name="lang" value="<?= adsEsc($lang) ?>">
            <input type="hidden" name="listing_id" id="renewId">
            <div class="fld">
                <label><?= adsEsc($L['days']) ?></label>
                <input type="number" min="1" max="3650" name="days" id="renewDays">
            </div>
            <div class="fld">
                <label><?= adsEsc($L['points']) ?></label>
                <input type="number" step="0.01" min="0" name="cost_points">
            </div>
            <div class="modal-actions">
                <button type="submit" class="btn btn-amber"><?= adsEsc($L['confirm']) ?></button>
                <button type="button" class="btn btn-ghost" onclick="closeOv('ovRenew')"><?= adsEsc($L['cancel']) ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ══════════ Close modal ══════════ -->
<?php if ($canClose): ?>
<div class="ov" id="ovClose" onclick="if(event.target===this) closeOv('ovClose')">
    <div class="modal" style="max-width:420px;">
        <h2>✕ <?= adsEsc($L['close_title']) ?></h2>
        <div class="mh">&nbsp;</div>
        <form method="POST" action="ads_action.php">
            <input type="hidden" name="csrf_token" value="<?= adsEsc($csrfToken) ?>">
            <input type="hidden" name="action" value="close">
            <input type="hidden" name="lang" value="<?= adsEsc($lang) ?>">
            <input type="hidden" name="listing_id" id="closeId">
            <div class="fld">
                <label><?= adsEsc($L['close_why']) ?></label>
                <select name="close_reason" id="closeReason">
                    <option value="manual"><?= adsEsc($L['why_manual']) ?></option>
                    <option value="sold"><?= adsEsc($L['why_sold']) ?></option>
                    <option value="expired"><?= adsEsc($L['why_expired']) ?></option>
                </select>
            </div>
            <div class="modal-actions">
                <button type="submit" class="btn btn-red"><?= adsEsc($L['confirm']) ?></button>
                <button type="button" class="btn btn-ghost" onclick="closeOv('ovClose')"><?= adsEsc($L['cancel']) ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
const PLATFORMS = <?= json_encode($platJs, JSON_UNESCAPED_UNICODE) ?>;

function openOv(id)  { const e = document.getElementById(id); if (e) e.classList.add('on'); }
function closeOv(id) { const e = document.getElementById(id); if (e) e.classList.remove('on'); }

/* New ad — optionally pre-selecting a car from the "never advertised" list */
function openNew(carId) {
    const sel = document.getElementById('newCar');
    if (sel && carId) sel.value = String(carId);
    openOv('ovNew');
}

/*
 * The whole platform difference lives here: a fixed platform pre-fills the
 * package length, a manual one leaves the end date empty so the ad stays live
 * until it is closed by hand. Dubizzle and Contact also ask for the salesman.
 */
function platChanged() {
    const id   = document.getElementById('newPlat').value;
    const p    = PLATFORMS[id];
    const days = document.getElementById('newDays');
    const sFld = document.getElementById('fldSalesman');
    const sSel = document.getElementById('newSalesman');
    const pFld = document.getElementById('fldPoints');
    const aFld = document.getElementById('fldAmount');

    if (!p) {
        sFld.style.display = 'none';
        sSel.required = false;
        pFld.style.display = 'none';
        aFld.style.display = 'none';
        return;
    }

    days.value = (p.type === 'fixed' && p.days) ? p.days : '';
    days.placeholder = (p.type === 'manual') ? '<?= adsEsc($L['no_end']) ?>' : '';

    sFld.style.display = p.needSales ? 'block' : 'none';
    sSel.required = !!p.needSales;
    if (!p.needSales) sSel.value = '';

    pFld.style.display = (p.cost === 'points') ? 'block' : 'none';
    aFld.style.display = (p.cost === 'per_ad') ? 'block' : 'none';
}

function openRenew(listingId, platformId) {
    document.getElementById('renewId').value = listingId;
    const p = PLATFORMS[platformId];
    document.getElementById('renewDays').value = (p && p.type === 'fixed' && p.days) ? p.days : '';
    openOv('ovRenew');
}

function openClose(listingId, reason) {
    document.getElementById('closeId').value = listingId;
    const sel = document.getElementById('closeReason');
    if (sel && reason) sel.value = reason;
    openOv('ovClose');
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.ov.on').forEach(o => o.classList.remove('on'));
});
</script>
</body>
</html>
