<?php

require 'auth.php';
require 'config.php';
require 'reserve_helpers.php';

$lang = $_GET['lang'] ?? 'ar';

if (!in_array($lang, ['ar', 'en'])) {
    $lang = 'ar';
}

$t = [

    'ar' => [
        'title'            => 'مخزون السيارات',
        'Prices'           => ' السعر',
        'year'             => 'السنة',
        'total_vehicles'   => 'إجمالي السيارات',
        'available'        => 'المتاح',
        'sold'             => 'المباع',
        'chassis'          => 'الشاسيه',
        'qr_btn'           => 'QR',
        'qr_hint'          => 'امسح الرمز بأي كاميرا لفتح بطاقة السيارة',
        'qr_print'         => '🖨️ طباعة ملصق',
        'qr_close'         => 'إغلاق',
        'search'           => 'ابحث بالماركة أو الموديل أو الشاسيه أو اللون أو الفرع',
        'journey'          => 'الرحلة',
        'edit'             => 'تعديل',
        'transfer'         => 'نقل',
        'sell'             => 'بيع',
        'trim'             => 'الفئة',
        'color'            => 'اللون',
        'branch'           => 'الفرع',
        'notes'            => 'ملاحظات',
        'created_by'       => 'أضيف بواسطة',
        'dashboard'        => 'الرئيسية',
        'inventory'        => 'المخزون',
        'add'              => 'إضافة',
        'logout'           => 'خروج',
        'no_vehicles'      => 'لا توجد سيارات',
        'start_adding'     => 'ابدأ بإضافة سيارات جديدة',
        'no_results'       => 'لا توجد نتائج للبحث',
        'locked'           => 'مغلقة',
        'add_vehicle'      => 'إضافة سيارة',
        'sold_inventory'   => 'السيارات المباعة',
        'managers_only'    => 'للإدارة فقط',
        'status_available' => 'متاحة',
        'status_sold'      => 'مباعة',
        'status_amana'     => 'امانة',
        'amana_section'    => 'سيارات الأمانة',
        'amana_dealer'     => 'التاجر',
        'amana_since'      => 'خرجت بتاريخ',
        'amana_elapsed'    => 'منذ',
        'amana_by'         => 'سجّلها',
        'amana_sold_btn'   => 'تم البيع',
        'amana_return_btn' => 'إرجاع السيارة',
        'amana_salesman'   => 'البائع',
        'amana_days'       => 'يوم',
        'amana_hours'      => 'ساعة',
        'amana_return_title' => 'إرجاع سيارة الأمانة',
        'amana_return_to'  => 'الفرع المستقبِل',
        'amana_return_confirm' => 'تأكيد الإرجاع',
        'amana_cancel'     => 'إلغاء',
        'available_section'=> 'السيارات المتاحة',
        'sold_section'     => 'السيارات المباعة',
        'stock_report'     => 'تقرير المخزون',
        'management_only'  => 'للإدارة فقط',
        'som_title'        => 'بائع الشهر',
        'som_sub'          => 'الأكثر مبيعاً للعملاء هذا الشهر',
        'som_sales'        => 'مبيعات',
        'som_runner'       => 'التالون',
        'wa_customer'      => 'واتساب عميل',
        'wa_dealer'        => 'واتساب تاجر',
        'wa_share'         => 'واتساب',
        'wa_no_price'      => 'السعر غير محدد',
        'wa_price_label'   => 'السعر',
        'wa_trade_label'   => 'سعر التاجر',
        'status_reserved'  => 'محجوزة',
        'reserve_btn'      => 'حجز السيارة',
        'unreserve_btn'    => 'إلغاء الحجز',
        'sold_btn'         => 'تم البيع',
        'reserved_by'      => 'حجزها',
        'reserved_at'      => 'تاريخ الحجز',
        'reserved_count'   => 'المحجوزة',
        'flash_reserved'   => '✓ تم حجز السيارة — ظهرت باللون الذهبي في كل الصفحات',
        'flash_unreserved' => '✓ تم إلغاء الحجز — رجعت السيارة لحالتها العادية',
        'flash_res_err'    => '⚠️ تعذّر تنفيذ العملية، حاول مرة أخرى',
        'unreserve_confirm'=> 'إلغاء حجز هذه السيارة؟',
    ],

    'en' => [
        'title'            => 'Vehicle Inventory',
        'Prices'           => 'Prices',
        'total_vehicles'   => 'Total Vehicles',
        'available'        => 'Available',
        'sold'             => 'Sold',
        'search'           => 'Search by Brand, Model, Chassis, Color or Branch',
        'journey'          => 'Journey',
        'edit'             => 'Edit',
        'transfer'         => 'Transfer',
        'sell'             => 'Sell',
        'chassis'          => 'Chassis',
        'qr_btn'           => 'QR',
        'qr_hint'          => 'Scan with any camera to open the car card',
        'qr_print'         => '🖨️ Print Sticker',
        'qr_close'         => 'Close',
        'trim'             => 'Trim',
        'color'            => 'Color',
        'branch'           => 'Branch',
        'notes'            => 'Notes',
        'created_by'       => 'Created By',
        'year'             => 'Year',
        'dashboard'        => 'Dashboard',
        'inventory'        => 'Inventory',
        'add'              => 'Add',
        'logout'           => 'Logout',
        'no_vehicles'      => 'No Vehicles Found',
        'start_adding'     => 'Start adding vehicles to your inventory',
        'no_results'       => 'No results found for your search',
        'locked'           => 'Locked',
        'add_vehicle'      => 'Add Vehicle',
        'sold_inventory'   => 'Sold Inventory',
        'managers_only'    => 'Managers Only',
        'status_available' => 'Available',
        'status_sold'      => 'Sold',
        'status_amana'     => 'Consignment',
        'amana_section'    => 'Consignment Vehicles',
        'amana_dealer'     => 'Dealer',
        'amana_since'      => 'Out since',
        'amana_elapsed'    => 'Elapsed',
        'amana_by'         => 'Registered by',
        'amana_sold_btn'   => 'Mark Sold',
        'amana_return_btn' => 'Return Car',
        'amana_salesman'   => 'Salesman',
        'amana_days'       => 'd',
        'amana_hours'      => 'h',
        'amana_return_title' => 'Return Consignment Car',
        'amana_return_to'  => 'Destination Branch',
        'amana_return_confirm' => 'Confirm Return',
        'amana_cancel'     => 'Cancel',
        'available_section'=> 'Available Vehicles',
        'sold_section'     => 'Sold Vehicles',
        'stock_report'     => 'Stock Report',
        'management_only'  => 'Management Only',
        'som_title'        => 'Salesman of the Month',
        'som_sub'          => 'Top customer sales this month',
        'som_sales'        => 'sales',
        'som_runner'       => 'Runners-up',
        'wa_customer'      => 'Customer WhatsApp',
        'wa_dealer'        => 'Dealer WhatsApp',
        'wa_share'         => 'WhatsApp',
        'wa_no_price'      => 'Price not set',
        'wa_price_label'   => 'Price',
        'wa_trade_label'   => 'Trade Price',
        'status_reserved'  => 'Reserved',
        'reserve_btn'      => 'Reserve',
        'unreserve_btn'    => 'Cancel reservation',
        'sold_btn'         => 'Mark Sold',
        'reserved_by'      => 'Reserved by',
        'reserved_at'      => 'Reserved on',
        'reserved_count'   => 'Reserved',
        'flash_reserved'   => '✓ Car reserved — it now shows in gold on every page',
        'flash_unreserved' => '✓ Reservation cancelled — the car is back to normal',
        'flash_res_err'    => '⚠️ Could not complete the action, please try again',
        'unreserve_confirm'=> 'Cancel this car\'s reservation?',
    ],

];

// ─── Motivational quotes ─────────────────────────────────────────────────────
// Default quotes (always the fallback). If an admin saves custom quotes via the
// banner editor, those replace these; clearing the editor reverts to these
// automatically. Custom quotes live in the `settings` table (key: dashboard_quotes),
// which is auto-created on first save by quote_save.php.
$defaultQuotes = [
    'النجاح ليس نهاية المطاف، بل رحلة تبدأ بخطوة',
    'كل عميل راضٍ هو بداية لصفقة جديدة',
    'الثقة تُبنى بالصدق، والمبيعات تُبنى بالثقة',
    'لا تبع سيارة، بل بِع حلماً على أربع عجلات',
    'الفرص لا تنتظر، اصنعها بنفسك',
    'العمل الجاد اليوم هو نجاح الغد',
    'أفضل استثمار هو الاستثمار في رضا العميل',
    'الابتسامة أول خطوة في إتمام أي صفقة',
    'كن متميزاً في الخدمة، يتذكرك العملاء دائماً',
    'الطريق إلى القمة يبدأ من أول عميل',
];

// Load admin's custom quotes (if any). Wrapped in try/catch so a missing
// settings table simply falls back to the defaults with no error.
$customQuotesRaw = '';
try {
    $cq = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='dashboard_quotes' LIMIT 1")->fetchColumn();
    $customQuotesRaw = trim((string)($cq !== false ? $cq : ''));
} catch (Exception $e) {
    $customQuotesRaw = '';
}

$isCustomQuotes = false;
$activeQuotes   = $defaultQuotes;
if ($customQuotesRaw !== '') {
    $lines = array_values(array_filter(
        array_map('trim', preg_split('/\r\n|\r|\n/', $customQuotesRaw)),
        fn($l) => $l !== ''
    ));
    if (!empty($lines)) { $activeQuotes = $lines; $isCustomQuotes = true; }
}
$quoteStartIdx = (int) date('z') % count($activeQuotes);
$quoteOfDay    = $activeQuotes[$quoteStartIdx];

$search    = trim($_GET['search'] ?? '');
$isAdmin   = ($_SESSION['role'] === 'admin');
$isManager = ($_SESSION['role'] === 'manager');
$isSales   = ($_SESSION['role'] === 'sales');

/* ─── Permission flags (defaults reproduce the old admin/manager/sales rules,
       but every one of them is editable from permissions_admin.php) ─── */
$canStatTotal    = can('dash.stat_total');       // real total-vehicles number
$canStatSold     = can('dash.stat_sold');        // sold count + link
$canStatAmana    = can('dash.stat_amana');       // amana stat card
$canSeeAmana     = can('dash.amana_section');    // amana strip + details
$canAmanaActions = can('dash.amana_actions');    // mark-sold / return buttons
$canSoldSection  = can('dash.sold_section');     // sold cards at the bottom
$canQuotesEdit   = can('dash.quotes_edit');      // ✏️ quote banner editor
$canBtnEdit      = can('dash.btn_edit');
$canBtnTransfer  = can('dash.btn_transfer');
$canBtnSell      = can('dash.btn_sell');
$canWaCustomer   = can('dash.wa_customer');
$canWaCustPrice  = can('dash.wa_customer_price');
$canWaDealer     = can('dash.wa_dealer');
$canReserve      = can('reserve.create');       // حجز السيارة
$canUnreserve    = can('reserve.cancel');       // إلغاء الحجز

// CSRF token for the one-click reserve / cancel buttons
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ─── Build query with pricing join ───────────────────────────────────────────
$conditions = [];
$params     = [];

// Which car statuses is this user allowed to see on the dashboard?
// Reserved cars stay in stock for EVERYONE — they are just painted gold.
$statuses = ["'available'", "'reserved'"];
if ($canSeeAmana)    $statuses[] = "'consignment'";
if ($canSoldSection) $statuses[] = "'sold'";
$conditions[] = "cars.status IN (" . implode(',', $statuses) . ")";

if (!empty($search)) {
    // FIX: each placeholder must be unique — PDO (no emulation) does NOT allow
    // reusing the same named placeholder multiple times. We also search Arabic
    // color and branch names so Arabic input works too.
    $conditions[] = "(
        cars.brand        LIKE :s1
        OR cars.model     LIKE :s2
        OR cars.trim_name LIKE :s3
        OR cars.chassis   LIKE :s4
        OR cars.color     LIKE :s5
        OR cars.branch    LIKE :s6
        OR colors.color_ar  LIKE :s7
        OR colors.color_en  LIKE :s8
        OR branches.name_ar LIKE :s9
        OR branches.name_en LIKE :s10
    )";
    $like = "%$search%";
    $params[':s1']  = $like;
    $params[':s2']  = $like;
    $params[':s3']  = $like;
    $params[':s4']  = $like;
    $params[':s5']  = $like;
    $params[':s6']  = $like;
    $params[':s7']  = $like;
    $params[':s8']  = $like;
    $params[':s9']  = $like;
    $params[':s10'] = $like;
}

$whereClause = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$query = "
    SELECT
        cars.*,
        colors.color_ar,
        colors.color_en,
        branches.name_ar,
        branches.name_en,
        MAX(pricing.customer_price) AS customer_price,
        MAX(pricing.trade_price)    AS trade_price
    FROM cars
    LEFT JOIN colors   ON cars.color  = colors.color_en
    LEFT JOIN branches ON cars.branch = branches.name
    LEFT JOIN pricing  ON pricing.brand      = cars.brand
                      AND pricing.model_name = cars.model
                      AND pricing.trim_name  = cars.trim_name
                      AND pricing.car_year   = cars.car_year
    $whereClause
    GROUP BY cars.id
    ORDER BY cars.id DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$cars = $stmt->fetchAll(PDO::FETCH_ASSOC);

$availableCarsList = [];
$soldCarsList      = [];
$amanaCarsList     = [];
foreach ($cars as $car) {
    if ($car['status'] === 'sold') {
        $soldCarsList[] = $car;
    } elseif ($car['status'] === 'consignment') {
        $amanaCarsList[] = $car;
    } else {
        $availableCarsList[] = $car;
    }
}

/* ─── Who reserved which car (for the gold cards) ─── */
$reservedIds  = [];
foreach ($availableCarsList as $c) {
    if (($c['status'] ?? '') === 'reserved') $reservedIds[] = $c['id'];
}
$reserveInfo = reservation_info($pdo, $reservedIds);

/* ─── Pull active امانة details (dealer, since-when, who) for the cards ─── */
$amanaInfo = [];
if (!empty($amanaCarsList)) {
    $ids  = array_column($amanaCarsList, 'id');
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $aStmt = $pdo->prepare("
        SELECT car_id, dealer_name, salesman, started_by, started_at
        FROM consignments
        WHERE status = 'active' AND car_id IN ($in)
    ");
    $aStmt->execute($ids);
    foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $amanaInfo[$r['car_id']] = $r;
    }
}

/* Branches list for the "return car" dropdown (permission-gated) */
$amanaBranches = [];
if ($canAmanaActions && !empty($amanaCarsList)) {
    $amanaBranches = $pdo->query("SELECT name, name_ar, name_en FROM branches ORDER BY name_en")->fetchAll(PDO::FETCH_ASSOC);
}

$totalCars     = (int) $pdo->query("SELECT COUNT(*) FROM cars")->fetchColumn();
$availableCars = (int) $pdo->query("SELECT COUNT(*) FROM cars WHERE status='available'")->fetchColumn();
$soldCars      = (int) $pdo->query("SELECT COUNT(*) FROM cars WHERE status='sold'")->fetchColumn();
$amanaCars     = (int) $pdo->query("SELECT COUNT(*) FROM cars WHERE status='consignment'")->fetchColumn();
$reservedCars  = (int) $pdo->query("SELECT COUNT(*) FROM cars WHERE status='reserved'")->fetchColumn();

function fmtPrice($p) {
    if ($p === null || $p === '') return '';
    return number_format((float)$p, 0, '.', ',') . ' ج.م';
}

?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $lang === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <meta name="theme-color" content="#0f172a">

    <!-- PWA / Add to Home Screen -->
    <link rel="manifest" href="/pwa/manifest.json">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="First 1 Car">
    <link rel="apple-touch-icon" href="/pwa/icon-192.png">
    <link rel="apple-touch-icon" sizes="192x192" href="/pwa/icon-192.png">
    <link rel="apple-touch-icon" sizes="512x512" href="/pwa/icon-512.png">

    <title><?= $t[$lang]['title'] ?></title>

    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            background: linear-gradient(135deg, #020617, #0f172a);
            color: #fff;
            min-height: 100vh;
            padding-bottom: 100px;
        }

        .container { max-width: 1500px; margin: 0 auto; padding: 16px; }

        /* Header */
        .header {
            display: flex; justify-content: space-between; align-items: center;
            gap: 16px; background: rgba(15,23,42,.88);
            border: 1px solid rgba(255,255,255,.08); padding: 20px 24px;
            border-radius: 24px; margin-bottom: 20px; backdrop-filter: blur(20px); flex-wrap: wrap;
        }
        .logo { display: flex; align-items: center; gap: 12px; }
        .logo img { width: 52px; height: 52px; object-fit: contain; border-radius: 12px; }
        .logo-title { font-size: 24px; font-weight: 800; white-space: nowrap; }
        .logo-green  { color: #22c55e; }
        .logo-purple { color: #9333ea; }
        .header-right { display: flex; flex-direction: column; align-items: flex-end; gap: 10px; }
        .lang-switch { display: flex; gap: 8px; }
        .lang-switch a {
            text-decoration: none; padding: 8px 14px; border-radius: 10px;
            background: #111827; color: #fff; font-weight: 700; font-size: 13px; transition: background .2s;
        }
        .lang-switch a:hover { background: #1e293b; }
        .lang-active { background: #9333ea !important; }
        .welcome-badge { font-size: 13px; color: #94a3b8; font-weight: 600; }
        .welcome-badge span { color: #22c55e; font-weight: 800; }
        .role-badge {
            display: inline-block; padding: 3px 10px; border-radius: 20px;
            font-size: 11px; font-weight: 700; margin-inline-start: 8px;
            background: rgba(147,51,234,.2); color: #a855f7;
        }

        /* Quote banner — animated & editable */
        .quote-banner {
            position: relative; overflow: hidden;
            display: flex; align-items: center; gap: 14px;
            background: linear-gradient(135deg, rgba(147,51,234,.12), rgba(34,197,94,.08));
            border: 1px solid rgba(147,51,234,.22); padding: 16px 22px;
            border-radius: 20px; margin-bottom: 20px; backdrop-filter: blur(20px);
        }
        /* moving light sweep across the banner */
        .quote-banner::before {
            content: ''; position: absolute; inset: 0; pointer-events: none;
            background: linear-gradient(120deg, transparent 32%, rgba(147,51,234,.16) 50%, transparent 68%);
            transform: translateX(-120%);
            animation: quoteSweep 6.5s ease-in-out infinite;
        }
        @keyframes quoteSweep { 0%,100% { transform: translateX(-120%); } 50% { transform: translateX(120%); } }

        .quote-banner .quote-icon {
            font-size: 26px; flex-shrink: 0; opacity: .95; z-index: 1;
            animation: quoteFloat 3.2s ease-in-out infinite;
        }
        @keyframes quoteFloat { 0%,100% { transform: translateY(0) rotate(0deg); } 50% { transform: translateY(-4px) rotate(10deg); } }

        .quote-viewport { flex: 1; position: relative; min-height: 26px; z-index: 1; }
        .quote-banner .quote-text {
            display: block; font-size: 17px; font-weight: 800; line-height: 1.5;
            color: #f1f5f9; /* fallback if gradient-text unsupported */
            background: linear-gradient(90deg, #ffffff, #c4b5fd, #86efac, #ffffff);
            background-size: 300% auto;
            -webkit-background-clip: text; background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: quoteShimmer 7s linear infinite;
            transition: opacity .45s ease, transform .45s ease;
        }
        .quote-banner .quote-text.q-hidden { opacity: 0; transform: translateY(9px); }
        @keyframes quoteShimmer { to { background-position: 300% center; } }

        .quote-edit-btn {
            z-index: 1; flex-shrink: 0; background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.14); color: #c084fc;
            width: 34px; height: 34px; border-radius: 10px; cursor: pointer; font-size: 15px;
            transition: background .2s, transform .15s;
        }
        .quote-edit-btn:hover { background: rgba(147,51,234,.22); transform: scale(1.06); }
        .quote-custom-tag {
            z-index: 1; flex-shrink: 0; font-size: 10px; font-weight: 800;
            color: #4ade80; background: rgba(34,197,94,.12);
            border: 1px solid rgba(34,197,94,.3); padding: 3px 8px; border-radius: 20px;
        }

        /* Quote editor modal */
        .qe-overlay { position: fixed; inset: 0; background: rgba(2,6,23,.8); backdrop-filter: blur(6px); z-index: 1500; display: none; align-items: center; justify-content: center; padding: 20px; }
        .qe-overlay.open { display: flex; }
        .qe-box { background: #0f172a; border: 1px solid rgba(147,51,234,.3); border-radius: 24px; padding: 24px; width: 100%; max-width: 460px; box-shadow: 0 32px 72px rgba(0,0,0,.6); }
        .qe-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
        .qe-head h3 { color: #c084fc; font-size: 18px; }
        .qe-x { background: none; border: none; color: #64748b; font-size: 20px; cursor: pointer; }
        .qe-x:hover { color: #ef4444; }
        .qe-hint { color: #94a3b8; font-size: 12.5px; line-height: 1.6; margin-bottom: 14px; }
        .qe-textarea { width: 100%; height: 180px; resize: vertical; background: #1e293b; border: 1px solid rgba(255,255,255,.1); border-radius: 14px; color: #f1f5f9; font-size: 14px; line-height: 1.8; padding: 12px 14px; font-family: inherit; outline: none; }
        .qe-textarea:focus { border-color: rgba(147,51,234,.5); }
        .qe-actions { display: flex; gap: 10px; margin-top: 16px; }
        .qe-btn { flex: 1; padding: 13px; border-radius: 13px; font-weight: 800; font-size: 14px; cursor: pointer; border: none; font-family: inherit; transition: transform .15s, opacity .2s; }
        .qe-btn:hover { transform: translateY(-2px); }
        .qe-save { background: linear-gradient(90deg, #9333ea, #2563eb); color: #fff; }
        .qe-clear { background: rgba(239,68,68,.12); color: #f87171; border: 1px solid rgba(239,68,68,.3); }
        .qe-status { text-align: center; font-size: 13px; font-weight: 700; margin-top: 12px; min-height: 18px; color: #4ade80; }


        /* Confetti */
        .confetti-piece { position:fixed; top:-12px; width:9px; height:14px; opacity:0; z-index:9999; pointer-events:none; will-change:transform,opacity; }
        @keyframes confettiFall { 0%{opacity:1;transform:translate(0,0) rotate(0deg);} 100%{opacity:0;transform:translate(var(--drift),105vh) rotate(var(--rot));} }

        /* Search */
        .search-box { margin-bottom:20px; position:relative; }
        .search-box .search-icon { position:absolute; top:50%; transform:translateY(-50%); font-size:18px; pointer-events:none; color:#64748b; }
        [dir="ltr"] .search-box .search-icon { left:20px; }
        [dir="rtl"] .search-box .search-icon { right:20px; }
        .search-box input { width:100%; height:58px; border:1px solid rgba(255,255,255,.08); outline:none; background:rgba(15,23,42,.88); color:#fff; border-radius:18px; font-size:15px; transition:border-color .2s; }
        [dir="ltr"] .search-box input { padding:0 20px 0 52px; }
        [dir="rtl"] .search-box input { padding:0 52px 0 20px; }
        .search-box input::placeholder { color:#64748b; }
        .search-box input:focus { border-color:rgba(147,51,234,.5); background:rgba(15,23,42,1); }

        /* Stats */
        .stats { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:20px; }
        .stat-card { background:rgba(15,23,42,.88); border:1px solid rgba(255,255,255,.08); border-radius:22px; padding:22px; transition:border-color .2s; }
        .stat-card a { text-decoration:none; color:#fff; display:block; }
        .stat-card:hover { border-color:rgba(147,51,234,.35); }
        .stat-title { font-size:13px; color:#94a3b8; font-weight:600; display:flex; align-items:center; gap:6px; }
        .stat-number { font-size:38px; font-weight:800; margin-top:8px; line-height:1; }
        .stat-green { color:#22c55e; }
        .stat-red { color:#ef4444; }
        .stat-amana { color:#f59e0b; }
        .stat-sub { margin-top:8px; font-size:11px; color:#64748b; }

        /* Section divider */
        .section-divider { display:flex; align-items:center; gap:12px; margin:24px 0 18px; }
        .section-divider h2 { font-size:22px; font-weight:800; white-space:nowrap; }
        .section-divider .divider-line { flex:1; height:1px; background:rgba(255,255,255,.08); }
        .section-count { background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1); padding:4px 12px; border-radius:20px; font-size:13px; font-weight:700; color:#94a3b8; }

        /* Grid */
        .inventory-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:18px; }

        /* Vehicle card */
        .vehicle-card { background:rgba(15,23,42,.88); border:1px solid rgba(255,255,255,.08); border-radius:26px; padding:20px; transition:transform .25s,border-color .25s,box-shadow .25s; backdrop-filter:blur(15px); display:flex; flex-direction:column; gap:16px; }
        .vehicle-card:hover { transform:translateY(-4px); border-color:rgba(147,51,234,.4); box-shadow:0 12px 40px rgba(147,51,234,.1); }
        .vehicle-card.sold-card { opacity:.85; border-color:rgba(239,68,68,.15); }
        .vehicle-card.sold-card:hover { border-color:rgba(239,68,68,.4); box-shadow:0 12px 40px rgba(239,68,68,.1); }
        .vehicle-header { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; }
        .vehicle-title { font-size:19px; font-weight:800; line-height:1.3; flex:1; }
        .vehicle-year-badge { font-size:11px; font-weight:700; color:#64748b; background:rgba(255,255,255,.05); padding:3px 8px; border-radius:8px; border:1px solid rgba(255,255,255,.06); white-space:nowrap; margin-top:4px; }
        .status { padding:6px 14px; border-radius:50px; font-size:11px; font-weight:800; white-space:nowrap; flex-shrink:0; }
        .status-available { background:rgba(34,197,94,.15); color:#22c55e; border:1px solid rgba(34,197,94,.25); }
        .status-sold { background:rgba(239,68,68,.15); color:#ef4444; border:1px solid rgba(239,68,68,.25); }
        .status-amana { background:rgba(245,158,11,.15); color:#f59e0b; border:1px solid rgba(245,158,11,.3); }

        /* ── 🔒 محجوزة / Reserved — the golden card ── */
        .status-reserved {
            background:linear-gradient(135deg,rgba(250,204,21,.22),rgba(234,179,8,.16));
            color:#facc15; border:1px solid rgba(250,204,21,.5);
            box-shadow:0 0 12px rgba(250,204,21,.22);
        }
        .vehicle-card.reserved-card {
            border-color:rgba(250,204,21,.55);
            background:
                linear-gradient(135deg,rgba(250,204,21,.07),rgba(234,179,8,.03)),
                rgba(15,23,42,.88);
            box-shadow:0 0 0 1px rgba(250,204,21,.14), 0 10px 34px rgba(234,179,8,.14);
            position:relative; overflow:hidden;
        }
        /* soft golden sheen sweeping across a reserved card */
        .vehicle-card.reserved-card::after {
            content:''; position:absolute; inset:0; pointer-events:none;
            background:linear-gradient(120deg,transparent 35%,rgba(250,204,21,.10) 50%,transparent 65%);
            transform:translateX(-120%);
            animation:goldSweep 5.5s ease-in-out infinite;
        }
        @keyframes goldSweep { 0%,100% { transform:translateX(-120%); } 50% { transform:translateX(120%); } }
        .vehicle-card.reserved-card:hover {
            border-color:rgba(250,204,21,.85);
            box-shadow:0 0 0 1px rgba(250,204,21,.28), 0 16px 46px rgba(234,179,8,.24);
        }
        .vehicle-card.reserved-card .vehicle-title { color:#fde68a; }
        .reserved-meta {
            display:flex; align-items:center; gap:8px; flex-wrap:wrap;
            background:rgba(250,204,21,.08); border:1px solid rgba(250,204,21,.26);
            border-radius:12px; padding:8px 12px; font-size:12px; font-weight:700; color:#fde68a;
        }
        .btn-reserve   { background:linear-gradient(135deg,#eab308,#ca8a04); color:#1c1400; }
        .btn-unreserve { background:rgba(250,204,21,.14); color:#facc15; border:1px solid rgba(250,204,21,.4) !important; }
        .btn-soldnow   { background:linear-gradient(135deg,#dc2626,#b91c1c); }
        .reserve-form  { display:contents; }
        .stat-reserved { color:#facc15; }

        /* flash banner for reserve / cancel */
        .res-flash {
            border-radius:16px; padding:13px 18px; margin-bottom:16px;
            font-weight:700; font-size:14px;
            background:rgba(250,204,21,.1); border:1px solid rgba(250,204,21,.35); color:#fde68a;
        }
        .res-flash.err { background:rgba(239,68,68,.1); border-color:rgba(239,68,68,.35); color:#fca5a5; }

        /* Compact strip styles below */

        /* ── Compact امانة strip (top of page) ── */
        .amana-strip { margin:18px 0 8px; background:rgba(245,158,11,.05); border:1px solid rgba(245,158,11,.25); border-radius:16px; padding:12px 14px; }
        .amana-strip-head { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
        .amana-strip-title { font-size:15px; font-weight:800; color:#f59e0b; }
        .amana-strip-badge { background:rgba(245,158,11,.2); color:#f59e0b; font-weight:800; font-size:12px; min-width:22px; height:22px; display:inline-flex; align-items:center; justify-content:center; border-radius:11px; padding:0 7px; }
        .amana-strip-list { display:flex; flex-direction:column; gap:8px; }
        .amana-row { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; background:rgba(0,0,0,.18); border:1px solid rgba(245,158,11,.18); border-radius:12px; padding:9px 12px; }
        .amana-row-main { display:flex; align-items:center; gap:14px; flex-wrap:wrap; flex:1; min-width:0; }
        .amana-row-car { font-weight:800; font-size:14px; color:#fbbf24; white-space:nowrap; }
        .amana-row-year { font-weight:600; font-size:11px; color:#94a3b8; }
        .amana-row-dealer { font-size:13px; color:#e2e8f0; white-space:nowrap; }
        .amana-row-meta { font-size:12px; color:#94a3b8; white-space:nowrap; }
        .amana-timer-pill { font-size:11px; font-weight:800; padding:3px 9px; border-radius:20px; white-space:nowrap; font-variant-numeric:tabular-nums; letter-spacing:.02em; }
        .amana-timer-pill.t-fresh { background:rgba(34,197,94,.15); color:#4ade80; border:1px solid rgba(34,197,94,.3); }
        .amana-timer-pill.t-warn  { background:rgba(245,158,11,.15); color:#fbbf24; border:1px solid rgba(245,158,11,.35); }
        .amana-timer-pill.t-late  { background:rgba(239,68,68,.15); color:#f87171; border:1px solid rgba(239,68,68,.35); animation:amanaPulse 2s ease-in-out infinite; }
        @keyframes amanaPulse { 0%,100%{opacity:1;} 50%{opacity:.6;} }
        .amana-row-actions { display:flex; gap:6px; flex-shrink:0; }
        .amana-mini-btn { font-size:12px; font-weight:700; padding:6px 12px; border-radius:9px; cursor:pointer; border:1px solid transparent; text-decoration:none; white-space:nowrap; }
        .mini-eye { background:rgba(147,51,234,.15); color:#c084fc; border-color:rgba(147,51,234,.3); }
        .mini-eye:hover { background:rgba(147,51,234,.28); }
        .mini-sold { background:rgba(239,68,68,.15); color:#f87171; border-color:rgba(239,68,68,.3); }
        .mini-sold:hover { background:rgba(239,68,68,.28); }
        .mini-return { background:rgba(34,197,94,.15); color:#22c55e; border-color:rgba(34,197,94,.3); }
        .mini-return:hover { background:rgba(34,197,94,.28); }
        @media (max-width:600px) {
            .amana-row { flex-direction:column; align-items:stretch; }
            .amana-row-main { gap:6px; }
            .amana-row-actions { justify-content:flex-end; }
        }

        /* Return modal */
        .amana-modal-overlay { position:fixed; inset:0; background:rgba(2,6,23,.78); backdrop-filter:blur(6px); z-index:1000; display:none; align-items:center; justify-content:center; padding:20px; }
        .amana-modal-overlay.open { display:flex; }
        .amana-modal { background:#0f172a; border:1px solid rgba(245,158,11,.3); border-radius:24px; padding:28px; width:100%; max-width:420px; box-shadow:0 32px 72px rgba(0,0,0,.6); }
        .amana-modal h3 { color:#f59e0b; font-size:18px; margin-bottom:6px; }
        .amana-modal .modal-car { color:#94a3b8; font-size:14px; margin-bottom:20px; }
        .amana-modal label { display:block; color:#cbd5e1; font-size:13px; font-weight:600; margin-bottom:8px; }
        .amana-modal select { width:100%; padding:14px; border-radius:14px; background:#1e293b; border:1px solid rgba(255,255,255,.1); color:#f1f5f9; font-size:15px; margin-bottom:22px; }
        .amana-modal-actions { display:flex; gap:10px; }
        .amana-modal-actions button { flex:1; padding:14px; border-radius:14px; font-weight:700; font-size:14px; cursor:pointer; border:none; }
        .modal-confirm { background:#22c55e; color:#022c14; }
        .modal-cancel { background:rgba(255,255,255,.08); color:#cbd5e1; }

        /* Info rows */
        .vehicle-info { display:flex; flex-direction:column; gap:0; background:rgba(0,0,0,.15); border-radius:16px; overflow:hidden; border:1px solid rgba(255,255,255,.05); }
        .info-row { display:flex; justify-content:space-between; align-items:center; padding:10px 14px; border-bottom:1px solid rgba(255,255,255,.04); gap:10px; }
        .info-row:last-child { border-bottom:none; }
        .info-label { color:#64748b; font-size:13px; font-weight:600; white-space:nowrap; }
        .info-value { font-weight:700; font-size:14px; text-align:end; word-break:break-word; max-width:60%; }
        .chassis-value { font-family:'Courier New',monospace; font-size:12px; color:#a3e635; letter-spacing:.5px; }

        /* ── on-card QR sticker: tilted like a real windshield sticker.
              Hover peeks it larger; tap opens the full scannable modal.
              Rendered lazily (IntersectionObserver) so big lists stay fast. ── */
        .qr-sticker {
            width:52px; height:52px; flex-shrink:0;
            background:#fff; border-radius:9px; padding:4px;
            transform:rotate(-5deg);
            box-shadow:0 3px 12px rgba(0,0,0,.4);
            cursor:pointer; position:relative; z-index:1;
            transition:transform .25s cubic-bezier(.2,1.4,.4,1), box-shadow .25s;
            line-height:0;
        }
        .qr-sticker:hover {
            transform:rotate(0deg) scale(1.9);
            z-index:40; box-shadow:0 10px 28px rgba(0,0,0,.55);
        }
        .qr-sticker:active { transform:rotate(0deg) scale(1.7); }
        .qr-sticker .qs { width:44px; height:44px; overflow:hidden; border-radius:4px; }
        .qr-sticker .qs img, .qr-sticker .qs canvas { width:100% !important; height:100% !important; display:block; }
        .qr-sticker .qs-wait {
            width:44px; height:44px; border-radius:4px;
            background:repeating-linear-gradient(45deg,#e2e8f0,#e2e8f0 4px,#f8fafc 4px,#f8fafc 8px);
        }

        /* ── per-car QR ── */
        .btn-qr {
            background: rgba(34,197,94,.10); color:#4ade80;
            border:1px solid rgba(34,197,94,.35) !important; cursor:pointer; font-family:inherit;
        }
        .btn-qr:hover { background: rgba(34,197,94,.22); color:#fff; }
        .qr-modal {
            display:none; position:fixed; inset:0; z-index:1200;
            background:rgba(2,6,23,.94); backdrop-filter:blur(6px);
            align-items:center; justify-content:center; padding:18px;
        }
        .qr-modal.show { display:flex; }
        .qr-box {
            width:100%; max-width:360px; background:rgba(15,23,42,.96);
            border:1px solid rgba(255,255,255,.1); border-radius:22px;
            padding:22px; text-align:center;
            animation:qrPop .3s cubic-bezier(.2,1.4,.4,1) both;
        }
        @keyframes qrPop { from{opacity:0; transform:scale(.92);} to{opacity:1; transform:none;} }
        .qr-box .qr-name { font-size:16px; font-weight:900; margin-bottom:2px; }
        .qr-box .qr-ch { font-family:'Courier New',monospace; color:#a3e635; font-size:13px; letter-spacing:1px; margin-bottom:14px; }
        .qr-panel {
            background:#fff; border-radius:16px; padding:14px;
            display:inline-block; line-height:0;
        }
        .qr-box .qr-hint { color:#94a3b8; font-size:12px; margin-top:12px; line-height:1.6; }
        .qr-box .qr-actions { display:flex; gap:10px; margin-top:16px; }
        .qr-box .qr-actions a, .qr-box .qr-actions button {
            flex:1; text-decoration:none; font-weight:800; font-size:13px; cursor:pointer;
            border-radius:12px; padding:12px; font-family:inherit;
        }
        .qr-print { background:linear-gradient(135deg,#22c55e,#16a34a); color:#fff; border:none; }
        .qr-close { background:rgba(255,255,255,.06); color:#f1f5f9; border:1px solid rgba(255,255,255,.1); }
        .info-row-note { background:rgba(217,119,6,.08); align-items:flex-start; }
        .info-row-note .info-label { color:#f59e0b; }
        .note-value { font-weight:600; font-size:13px; line-height:1.5; color:#fcd34d; text-align:end; word-break:break-word; max-width:70%; white-space:pre-wrap; }

        /* Actions */
        .actions { display:grid; grid-template-columns:repeat(2,1fr); gap:8px; }
        .btn { display:flex; align-items:center; justify-content:center; gap:6px; height:44px; border-radius:12px; text-decoration:none; font-weight:700; font-size:13px; color:#fff; transition:transform .2s,opacity .2s,box-shadow .2s; border:none; cursor:pointer; font-family:inherit; }
        .btn:hover { transform:translateY(-2px); opacity:.92; }
        .btn:active { transform:translateY(0); }
        .btn-journey  { background:#2563eb; }
        .btn-edit     { background:#9333ea; }
        .btn-transfer { background:#d97706; }
        .btn-sell     { background:#dc2626; }
        .btn-wa-cust  { background:#16a34a; }
        .btn-wa-deal  { background:#0e7490; }

        /* ── compact WhatsApp row at the card's bottom ── */
        .mini-actions { display:flex; gap:8px; margin-top:-6px; }
        .mini-btn {
            flex:1; display:flex; align-items:center; justify-content:center; gap:5px;
            height:30px; border-radius:999px; font-size:11.5px; font-weight:800;
            font-family:inherit; cursor:pointer; border:none;
            transition:transform .15s, opacity .15s;
        }
        .mini-btn:hover { transform:translateY(-1px); opacity:.92; }
        .mb-cust {
            background:rgba(22,163,74,.14); color:#4ade80;
            border:1px solid rgba(22,163,74,.35);
        }
        .mb-deal {
            background:rgba(14,116,144,.16); color:#67e8f9;
            border:1px solid rgba(14,116,144,.45);
        }
        .mb-locked {
            background:rgba(255,255,255,.04); color:#475569;
            border:1px solid rgba(255,255,255,.06); cursor:default;
        }
        .mb-locked:hover { transform:none; opacity:1; }
        .btn-disabled { background:rgba(55,65,81,.6); cursor:not-allowed; opacity:.5; pointer-events:none; }

        /* Empty state */
        .empty-state { grid-column:1/-1; background:rgba(15,23,42,.88); border:1px solid rgba(255,255,255,.08); border-radius:24px; padding:60px 40px; text-align:center; }
        .empty-state h2 { font-size:22px; font-weight:800; margin-bottom:10px; }
        .empty-state p { color:#64748b; font-size:15px; }

        /* Bottom nav */
        .bottom-nav { position:fixed; bottom:0; left:0; right:0; height:76px; background:rgba(15,23,42,.97); border-top:1px solid rgba(255,255,255,.08); display:flex; justify-content:space-around; align-items:center; z-index:999; backdrop-filter:blur(20px); padding:0 8px; padding-bottom:env(safe-area-inset-bottom); }
        .nav-link { text-decoration:none; color:#64748b; font-size:11px; font-weight:700; text-align:center; transition:color .2s,transform .2s; display:flex; flex-direction:column; align-items:center; gap:4px; padding:8px 6px; border-radius:12px; min-width:58px; }
        .nav-link:hover { color:#fff; transform:translateY(-2px); }
        .nav-active { color:#22c55e !important; }
        .nav-icon { font-size:20px; display:block; }
        .nav-link-disabled { opacity:.3; pointer-events:none; cursor:not-allowed; }
        .nav-link-logout { color:#ef4444 !important; }
        .nav-link-logout:hover { color:#fca5a5 !important; }

        /* "More" button + popup sheet */
        .nav-more-btn { background:none; border:none; cursor:pointer; font-family:inherit; }
        .more-overlay { position:fixed; inset:0; background:rgba(2,6,23,.6); backdrop-filter:blur(3px); z-index:1000; display:none; }
        .more-overlay.open { display:block; }
        .more-sheet {
            position:fixed; bottom:0; left:0; right:0; z-index:1001;
            background:rgba(15,23,42,.99); border-top:1px solid rgba(255,255,255,.1);
            border-radius:22px 22px 0 0; padding:14px 14px calc(20px + env(safe-area-inset-bottom));
            transform:translateY(100%); transition:transform .28s cubic-bezier(.4,0,.2,1);
            box-shadow:0 -12px 40px rgba(0,0,0,.5);
        }
        .more-overlay.open .more-sheet { transform:translateY(0); }
        .more-handle { width:40px; height:4px; background:rgba(255,255,255,.2); border-radius:4px; margin:2px auto 14px; }
        .more-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; }
        .more-item {
            text-decoration:none; color:#cbd5e1; background:rgba(255,255,255,.04);
            border:1px solid rgba(255,255,255,.06); border-radius:16px; padding:16px 8px;
            display:flex; flex-direction:column; align-items:center; gap:8px;
            font-size:12px; font-weight:700; transition:background .2s,transform .15s;
        }
        .more-item:hover, .more-item:active { background:rgba(147,51,234,.15); transform:translateY(-2px); }
        .more-item .mi-icon { font-size:24px; }
        .more-item-logout { color:#f87171; border-color:rgba(239,68,68,.2); background:rgba(239,68,68,.06); }
        .more-item-disabled { opacity:.35; pointer-events:none; }

        /* Scroll top */
        .scroll-top { position:fixed; bottom:96px; inset-inline-end:20px; width:42px; height:42px; background:#9333ea; border:none; border-radius:50%; color:#fff; font-size:18px; cursor:pointer; display:none; align-items:center; justify-content:center; box-shadow:0 4px 20px rgba(147,51,234,.4); z-index:998; transition:opacity .2s; }
        .scroll-top.visible { display:flex; }

        /* Responsive */
        @media (max-width:768px) {
            .header { flex-direction:column; align-items:flex-start; gap:14px; }
            .header-right { width:100%; flex-direction:row; justify-content:space-between; align-items:center; }
            .stats { grid-template-columns:1fr 1fr; }
            .stats .stat-card:first-child { grid-column:1/-1; }
            .inventory-grid { grid-template-columns:1fr; }
            .vehicle-title { font-size:17px; }
            .info-row { flex-direction:column; align-items:flex-start; gap:3px; }
            .info-value, .note-value { max-width:100%; text-align:start; }
            .actions { grid-template-columns:1fr 1fr; }
            .btn { font-size:12px; height:42px; }
            .quote-banner { padding:14px 18px; gap:10px; }
            .quote-banner .quote-text { font-size:15px; }
            .quote-banner .quote-icon { font-size:22px; }
        }
        @media (max-width:380px) {
            .actions { grid-template-columns:1fr; }
            .btn { height:46px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .som-card, .som-glow, .som-trophy { animation:none !important; opacity:1 !important; transform:none !important; }
        }
    </style>
</head>

<body>

<!-- ═══ ambient 4D background — decorative, sits BEHIND all content ═══ -->
<style>
.f1c-amb{position:fixed;inset:0;pointer-events:none;z-index:-1}
.f1c-amb-grid{background-image:
   linear-gradient(rgba(148,163,184,.045) 1px,transparent 1px),
   linear-gradient(90deg,rgba(148,163,184,.045) 1px,transparent 1px);
   background-size:56px 56px;
   -webkit-mask-image:radial-gradient(circle at 50% 26%,#000,transparent 80%);
           mask-image:radial-gradient(circle at 50% 26%,#000,transparent 80%);
   will-change:transform}
.f1c-amb-orb{position:fixed;border-radius:50%;filter:blur(95px);z-index:-1;pointer-events:none;will-change:transform}
.f1c-amb-g{width:520px;height:520px;top:-170px;left:-150px;background:radial-gradient(circle,rgba(34,197,94,.16),transparent 68%)}
.f1c-amb-p{width:560px;height:560px;bottom:-200px;right:-160px;background:radial-gradient(circle,rgba(147,51,234,.16),transparent 68%)}
</style>
<canvas id="f1c-amb-stars" class="f1c-amb" aria-hidden="true"></canvas>
<div class="f1c-amb f1c-amb-grid" id="f1c-amb-grid" aria-hidden="true"></div>
<div class="f1c-amb-orb f1c-amb-g" id="f1c-amb-o1" aria-hidden="true"></div>
<div class="f1c-amb-orb f1c-amb-p" id="f1c-amb-o2" aria-hidden="true"></div>
<script>
(function(){
  var grid=document.getElementById('f1c-amb-grid'),o1=document.getElementById('f1c-amb-o1'),o2=document.getElementById('f1c-amb-o2');
  var tx=0,ty=0;
  addEventListener('pointermove',function(e){
    var gx=e.clientX/innerWidth-.5, gy=e.clientY/innerHeight-.5; tx=gx; ty=gy;
    if(grid) grid.style.transform='translate('+(gx*-22)+'px,'+(gy*-22)+'px)';
    if(o1) o1.style.transform='translate('+(gx*30)+'px,'+(gy*30)+'px)';
    if(o2) o2.style.transform='translate('+(gx*-34)+'px,'+(gy*-34)+'px)';
  },{passive:true});
  var cv=document.getElementById('f1c-amb-stars'); if(!cv) return;
  var cx=cv.getContext('2d'), stars=[];
  function resize(){cv.width=innerWidth;cv.height=innerHeight;
    stars=Array.from({length:Math.min(110,Math.round(innerWidth/12))},function(){
      return {x:Math.random()*cv.width,y:Math.random()*cv.height,z:Math.random(),r:Math.random()*1.3+.3};});}
  resize(); addEventListener('resize',resize,{passive:true});
  var reduce=matchMedia('(prefers-reduced-motion:reduce)').matches;
  (function draw(){
    cx.clearRect(0,0,cv.width,cv.height);
    for(var i=0;i<stars.length;i++){var s=stars[i];
      if(!reduce){s.y+=s.z*.18; if(s.y>cv.height){s.y=0; s.x=Math.random()*cv.width;}}
      var px=s.x+tx*s.z*26, py=s.y+ty*s.z*26;
      cx.globalAlpha=.25+s.z*.45;
      cx.fillStyle=s.z>.7?'#4ade80':(s.z>.4?'#a855f7':'#cbd5e1');
      cx.beginPath(); cx.arc(px,py,s.r,0,6.28); cx.fill();
    }
    cx.globalAlpha=1; requestAnimationFrame(draw);
  })();
})();
</script>

<div class="container">

    <!-- Header -->
    <div class="header">
        <div class="logo">
            <img src="logo.png" alt="First1Car Logo">
            <div class="logo-title">
                <span class="logo-green">First</span><span class="logo-purple">1</span>Car
            </div>
        </div>
        <div class="header-right">
            <div class="lang-switch">
                <a href="?lang=ar<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
                   class="<?= $lang === 'ar' ? 'lang-active' : '' ?>">🇪🇬 العربية</a>
                <a href="?lang=en<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
                   class="<?= $lang === 'en' ? 'lang-active' : '' ?>">🇺🇸 English</a>
            </div>
            <div class="welcome-badge">
                <span><?= htmlspecialchars($_SESSION['username']) ?></span>
                <span class="role-badge"><?= htmlspecialchars($_SESSION['role']) ?></span>
            </div>
        </div>
    </div>

    <?php if (isset($_GET['reserved'])): ?>
        <div class="res-flash">🔒 <?= $t[$lang]['flash_reserved'] ?></div>
    <?php elseif (isset($_GET['unreserved'])): ?>
        <div class="res-flash"><?= $t[$lang]['flash_unreserved'] ?></div>
    <?php elseif (isset($_GET['res_err'])): ?>
        <div class="res-flash err"><?= $t[$lang]['flash_res_err'] ?></div>
    <?php endif; ?>

    <?php if (isset($_GET['denied'])): ?>
    <!-- Shown when a page redirects here because a permission is missing -->
    <div style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.35);color:#fca5a5;
                border-radius:16px;padding:13px 18px;margin-bottom:16px;font-weight:700;font-size:14px;">
        🔒 <?= $lang === 'ar' ? 'ليست لديك صلاحية للوصول لهذه الصفحة' : 'You don\'t have permission to access that page' ?>
    </div>
    <?php endif; ?>

    <!-- Quote -->
    <div class="quote-banner" id="quoteBanner">
        <span class="quote-icon">✨</span>
        <div class="quote-viewport">
            <span class="quote-text" id="quoteText"><?= htmlspecialchars($quoteOfDay) ?></span>
        </div>
        <?php if ($isCustomQuotes): ?>
            <span class="quote-custom-tag"><?= $lang === 'ar' ? 'مخصص' : 'Custom' ?></span>
        <?php endif; ?>
        <?php if ($canQuotesEdit): ?>
            <button type="button" class="quote-edit-btn" onclick="openQuoteEditor()" title="<?= $lang === 'ar' ? 'تعديل العبارات' : 'Edit quotes' ?>">✏️</button>
        <?php endif; ?>
    </div>

    <!-- Search -->
    <form method="GET" class="search-box" role="search">
        <input type="hidden" name="lang" value="<?= $lang ?>">
        <span class="search-icon">🔍</span>
        <input type="text" name="search" placeholder="<?= $t[$lang]['search'] ?>"
               value="<?= htmlspecialchars($search) ?>" autocomplete="off" inputmode="search">
    </form>

    <!-- Stats -->
    <div class="stats">
        <div class="stat-card">
            <?php if ($canStatTotal): ?>
                <div class="stat-title">🚗 <?= $t[$lang]['total_vehicles'] ?></div>
                <div class="stat-number"><?= $totalCars ?></div>
            <?php else: ?>
                <a href="sold_login.php?lang=<?= $lang ?>">
                    <div class="stat-title">🔒 <?= $t[$lang]['total_vehicles'] ?></div>
                    <div class="stat-number">••••</div>
                    <div class="stat-sub"><?= $t[$lang]['management_only'] ?></div>
                </a>
            <?php endif; ?>
        </div>
        <div class="stat-card">
            <div class="stat-title">✅ <?= $t[$lang]['available'] ?></div>
            <div class="stat-number stat-green"><?= $availableCars ?></div>
        </div>
        <?php if ($reservedCars > 0): ?>
        <div class="stat-card">
            <div class="stat-title">🔒 <?= $t[$lang]['reserved_count'] ?></div>
            <div class="stat-number stat-reserved"><?= $reservedCars ?></div>
        </div>
        <?php endif; ?>
        <?php if ($canStatAmana && $amanaCars > 0): ?>
        <div class="stat-card">
            <div class="stat-title">🔶 <?= $t[$lang]['status_amana'] ?></div>
            <div class="stat-number stat-amana"><?= $amanaCars ?></div>
        </div>
        <?php endif; ?>
        <div class="stat-card">
            <?php if ($canStatSold): ?>
                <a href="sold_inventory.php?lang=<?= $lang ?>">
                    <div class="stat-title">💰 <?= $t[$lang]['sold_inventory'] ?></div>
                    <div class="stat-number stat-red"><?= $soldCars ?></div>
                </a>
            <?php else: ?>
                <a href="sold_login.php?lang=<?= $lang ?>">
                    <div class="stat-title">🔒 <?= $t[$lang]['sold_inventory'] ?></div>
                    <div class="stat-number">••••</div>
                    <div class="stat-sub"><?= $t[$lang]['managers_only'] ?></div>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- ════════ امانة / Consignment (compact strip, top of page) ════════ -->
    <?php if ($canSeeAmana && !empty($amanaCarsList)): ?>
    <div class="amana-strip">
        <div class="amana-strip-head">
            <span class="amana-strip-title">🔶 <?= $t[$lang]['amana_section'] ?></span>
            <span class="amana-strip-badge"><?= count($amanaCarsList) ?></span>
        </div>
        <div class="amana-strip-list">
            <?php foreach ($amanaCarsList as $car):
                $displayBranch = $lang === 'ar' ? ($car['name_ar'] ?: $car['branch']) : ($car['name_en'] ?: $car['branch']);
                $info          = $amanaInfo[$car['id']] ?? null;
                $dealerName    = $info['dealer_name'] ?? '—';
                $startedAt     = $info['started_at']  ?? null;
                $startedTs     = $startedAt ? strtotime($startedAt) : 0;
                $startedFmt    = $startedAt ? date('Y-m-d', $startedTs) : '—';
            ?>
            <div class="amana-row">
                <div class="amana-row-main">
                    <span class="amana-row-car">🔶 <?= htmlspecialchars($car['brand']) ?> <?= htmlspecialchars($car['model']) ?> <span class="amana-row-year"><?= htmlspecialchars($car['car_year']) ?></span></span>
                    <span class="amana-row-dealer">👤 <?= htmlspecialchars($dealerName) ?></span>
                    <span class="amana-row-meta">📍 <?= htmlspecialchars($displayBranch) ?> · 🔩 <?= htmlspecialchars($car['chassis']) ?></span>
                    <?php if ($canSeeAmana): ?>
                    <span class="amana-row-meta">📅 <?= htmlspecialchars($startedFmt) ?></span>
                    <span class="amana-timer-pill" data-start="<?= $startedTs ?>">⏱ …</span>
                    <?php endif; ?>
                </div>
                <?php if ($canAmanaActions): ?>
                <div class="amana-row-actions">
                    <a href="vehicle_timeline.php?id=<?= $car['id'] ?>&lang=<?= $lang ?>" class="amana-mini-btn mini-eye" title="<?= $t[$lang]['journey'] ?>">👁 <?= $lang === 'ar' ? 'الرحلة' : 'Trip' ?></a>
                    <a href="sold_vehicle.php?id=<?= $car['id'] ?>&lang=<?= $lang ?>" class="amana-mini-btn mini-sold"><?= $t[$lang]['amana_sold_btn'] ?></a>
                    <button type="button" class="amana-mini-btn mini-return"
                        onclick="openReturn(<?= $car['id'] ?>, '<?= addslashes(htmlspecialchars($car['brand'].' '.$car['model'], ENT_QUOTES)) ?>')"><?= $t[$lang]['amana_return_btn'] ?></button>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Available Vehicles -->
    <div class="section-divider">
        <h2>🚗 <?= $t[$lang]['available_section'] ?></h2>
        <div class="divider-line"></div>
        <span class="section-count"><?= count($availableCarsList) ?></span>
    </div>

    <div class="inventory-grid">

        <?php if (empty($availableCarsList)): ?>
            <div class="empty-state">
                <?php if (!empty($search)): ?>
                    <h2>🔍 <?= $t[$lang]['no_results'] ?></h2>
                    <p><?= htmlspecialchars($search) ?></p>
                <?php else: ?>
                    <h2>🚗 <?= $t[$lang]['no_vehicles'] ?></h2>
                    <p><?= $t[$lang]['start_adding'] ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php foreach ($availableCarsList as $car):
            $isReserved = (($car['status'] ?? '') === 'reserved');
            $resInfo    = $isReserved ? ($reserveInfo[$car['id']] ?? null) : null;
            $displayColor  = $lang === 'ar' ? ($car['color_ar']  ?: $car['color'])  : ($car['color_en']  ?: $car['color']);
            $displayBranch = $lang === 'ar' ? ($car['name_ar']   ?: $car['branch']) : ($car['name_en']   ?: $car['branch']);
            $noteText      = trim((string)($car['notes'] ?? ''));
            $officialPrice = $car['customer_price'] ?? null;
            $tradePrice    = $car['trade_price']     ?? null;

            $jsName   = addslashes(htmlspecialchars($car['brand'].' '.$car['model'], ENT_QUOTES));
            $jsChassis = addslashes(htmlspecialchars($car['chassis'], ENT_QUOTES));
            $jsYear   = addslashes(htmlspecialchars($car['car_year'], ENT_QUOTES));
            $jsTrim   = addslashes(htmlspecialchars($car['trim_name'], ENT_QUOTES));
            $jsColor  = addslashes(htmlspecialchars($displayColor, ENT_QUOTES));
            $jsPrice  = $officialPrice ? addslashes(fmtPrice($officialPrice)) : '';
            $jsTrade  = $tradePrice    ? addslashes(fmtPrice($tradePrice))    : '';
            // Searchable text blob (brand, model, trim, color, branch, chassis, year) — both languages
            $searchBlob = mb_strtolower(trim(
                $car['brand'].' '.$car['model'].' '.$car['trim_name'].' '.
                $car['car_year'].' '.$displayColor.' '.($car['color_en'] ?? '').' '.($car['color_ar'] ?? '').' '.
                $displayBranch.' '.($car['name_en'] ?? '').' '.($car['name_ar'] ?? '').' '.$car['chassis']
            ));
        ?>
            <div class="vehicle-card <?= $isReserved ? 'reserved-card' : '' ?>" data-search="<?= htmlspecialchars($searchBlob) ?>">

                <div class="vehicle-header">
                    <div class="qr-sticker" title="QR"
                         data-ch="<?= htmlspecialchars($car['chassis'], ENT_QUOTES) ?>"
                         data-name="<?= htmlspecialchars($car['brand'].' '.$car['model'], ENT_QUOTES) ?>"
                         data-id="<?= (int)$car['id'] ?>"><div class="qs qs-wait"></div></div>
                    <div>
                        <div class="vehicle-title"><?= $isReserved ? '🔒' : '🚗' ?> <?= htmlspecialchars($car['brand']) ?> <?= htmlspecialchars($car['model']) ?></div>
                        <div class="vehicle-year-badge">📅 <?= htmlspecialchars($car['car_year']) ?></div>
                    </div>
                    <?php if ($isReserved): ?>
                        <div class="status status-reserved">🔒 <?= $t[$lang]['status_reserved'] ?></div>
                    <?php else: ?>
                        <div class="status status-available"><?= $t[$lang]['status_available'] ?></div>
                    <?php endif; ?>
                </div>

                <?php if ($isReserved && $resInfo): ?>
                <div class="reserved-meta">
                    <span>👤 <?= $t[$lang]['reserved_by'] ?>: <?= htmlspecialchars($resInfo['by']) ?></span>
                    <span>📅 <?= date('d M Y', strtotime($resInfo['at'])) ?></span>
                </div>
                <?php endif; ?>

                <div class="vehicle-info">
                    <div class="info-row">
                        <span class="info-label"><?= $t[$lang]['trim'] ?></span>
                        <span class="info-value"><?= htmlspecialchars($car['trim_name']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><?= $t[$lang]['color'] ?></span>
                        <span class="info-value"><?= htmlspecialchars($displayColor) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><?= $t[$lang]['branch'] ?></span>
                        <span class="info-value"><?= htmlspecialchars($displayBranch) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><?= $t[$lang]['chassis'] ?></span>
                        <span class="info-value chassis-value"><?= htmlspecialchars($car['chassis']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><?= $t[$lang]['created_by'] ?></span>
                        <span class="info-value"><?= htmlspecialchars($car['created_by']) ?></span>
                    </div>
                    <?php if ($noteText !== ''): ?>
                    <div class="info-row info-row-note">
                        <span class="info-label">📝 <?= $t[$lang]['notes'] ?></span>
                        <span class="note-value"><?= htmlspecialchars($noteText) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="actions">
                    <a href="vehicle_timeline.php?id=<?= $car['id'] ?>&lang=<?= $lang ?>" class="btn btn-journey">
                        👁 <?= $t[$lang]['journey'] ?>
                    </a>

                    <?php if ($canBtnEdit): ?>
                        <a href="edit_vehicle.php?id=<?= $car['id'] ?>&lang=<?= $lang ?>" class="btn btn-edit">
                            ✏ <?= $t[$lang]['edit'] ?>
                        </a>
                    <?php else: ?>
                        <div class="btn btn-disabled">✏ <?= $t[$lang]['edit'] ?></div>
                    <?php endif; ?>
                    <?php if ($canBtnTransfer): ?>
                        <a href="transfer_vehicle.php?id=<?= $car['id'] ?>&lang=<?= $lang ?>" class="btn btn-transfer">
                            🔄 <?= $t[$lang]['transfer'] ?>
                        </a>
                    <?php else: ?>
                        <div class="btn btn-disabled">🔄 <?= $t[$lang]['transfer'] ?></div>
                    <?php endif; ?>
                    <?php if ($canBtnSell): ?>
                        <?php /* On a reserved car the sell button reads "تم البيع" and opens the
                                normal sale page — the sale itself follows the exact same steps. */ ?>
                        <a href="sold_vehicle.php?id=<?= $car['id'] ?>&lang=<?= $lang ?>"
                           class="btn <?= $isReserved ? 'btn-soldnow' : 'btn-sell' ?>">
                            💰 <?= $isReserved ? $t[$lang]['sold_btn'] : $t[$lang]['sell'] ?>
                        </a>
                    <?php else: ?>
                        <div class="btn btn-disabled">💰 <?= $isReserved ? $t[$lang]['sold_btn'] : $t[$lang]['sell'] ?></div>
                    <?php endif; ?>

                    <?php /* ── حجز السيارة / إلغاء الحجز — one click, no data entry ── */ ?>
                    <?php if (!$isReserved && $canReserve): ?>
                        <form method="POST" action="reserve_action.php" class="reserve-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="reserve">
                            <input type="hidden" name="car_id" value="<?= (int)$car['id'] ?>">
                            <input type="hidden" name="lang" value="<?= $lang ?>">
                            <input type="hidden" name="back" value="dashboard.php">
                            <button type="submit" class="btn btn-reserve">🔒 <?= $t[$lang]['reserve_btn'] ?></button>
                        </form>
                    <?php elseif ($isReserved && $canUnreserve): ?>
                        <form method="POST" action="reserve_action.php" class="reserve-form"
                              onsubmit="return confirm(<?= htmlspecialchars(json_encode($t[$lang]['unreserve_confirm'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>);">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="car_id" value="<?= (int)$car['id'] ?>">
                            <input type="hidden" name="lang" value="<?= $lang ?>">
                            <input type="hidden" name="back" value="dashboard.php">
                            <button type="submit" class="btn btn-unreserve">↩️ <?= $t[$lang]['unreserve_btn'] ?></button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- compact WhatsApp row (role-gated, same handlers) -->
                <div class="mini-actions">
                    <?php if ($canWaCustomer): ?>
                        <button class="mini-btn mb-cust"
                            onclick="waCustomer('<?= $jsName ?>','<?= $jsYear ?>','<?= $jsTrim ?>','<?= $jsColor ?>','<?= $canWaCustPrice ? $jsPrice : '' ?>')">
                            💬 <?= $canWaCustPrice ? $t[$lang]['wa_customer'] : $t[$lang]['wa_share'] ?>
                        </button>
                    <?php else: ?>
                        <div class="mini-btn mb-locked">💬 <?= $t[$lang]['wa_customer'] ?></div>
                    <?php endif; ?>
                    <?php if ($canWaDealer): ?>
                        <button class="mini-btn mb-deal"
                            onclick="waDealer('<?= $jsName ?>','<?= $jsYear ?>','<?= $jsTrim ?>','<?= $jsColor ?>','<?= $jsTrade ?>')">
                            🤝 <?= $t[$lang]['wa_dealer'] ?>
                        </button>
                    <?php else: ?>
                        <div class="mini-btn mb-locked">🔒 <?= $t[$lang]['wa_dealer'] ?></div>
                    <?php endif; ?>
                </div>

            </div>
        <?php endforeach; ?>

    </div>

    <!-- Sold Vehicles -->
    <?php if ($canSoldSection && !empty($soldCarsList)): ?>
        <div class="section-divider" style="margin-top:36px;">
            <h2>💰 <?= $t[$lang]['sold_section'] ?></h2>
            <div class="divider-line"></div>
            <span class="section-count"><?= count($soldCarsList) ?></span>
        </div>
        <div class="inventory-grid">
            <?php foreach ($soldCarsList as $car):
                $displayColor  = $lang === 'ar' ? ($car['color_ar']  ?: $car['color'])  : ($car['color_en']  ?: $car['color']);
                $displayBranch = $lang === 'ar' ? ($car['name_ar']   ?: $car['branch']) : ($car['name_en']   ?: $car['branch']);
                $noteText      = trim((string)($car['notes'] ?? ''));
            ?>
                <div class="vehicle-card sold-card">
                    <div class="vehicle-header">
                        <div class="qr-sticker" title="QR"
                             data-ch="<?= htmlspecialchars($car['chassis'], ENT_QUOTES) ?>"
                             data-name="<?= htmlspecialchars($car['brand'].' '.$car['model'], ENT_QUOTES) ?>"
                             data-id="<?= (int)$car['id'] ?>"><div class="qs qs-wait"></div></div>
                        <div>
                            <div class="vehicle-title">🚗 <?= htmlspecialchars($car['brand']) ?> <?= htmlspecialchars($car['model']) ?></div>
                            <div class="vehicle-year-badge">📅 <?= htmlspecialchars($car['car_year']) ?></div>
                        </div>
                        <div class="status status-sold"><?= $t[$lang]['status_sold'] ?></div>
                    </div>
                    <div class="vehicle-info">
                        <div class="info-row"><span class="info-label"><?= $t[$lang]['trim'] ?></span><span class="info-value"><?= htmlspecialchars($car['trim_name']) ?></span></div>
                        <div class="info-row"><span class="info-label"><?= $t[$lang]['color'] ?></span><span class="info-value"><?= htmlspecialchars($displayColor) ?></span></div>
                        <div class="info-row"><span class="info-label"><?= $t[$lang]['branch'] ?></span><span class="info-value"><?= htmlspecialchars($displayBranch) ?></span></div>
                        <div class="info-row"><span class="info-label"><?= $t[$lang]['chassis'] ?></span><span class="info-value chassis-value"><?= htmlspecialchars($car['chassis']) ?></span></div>
                        <div class="info-row"><span class="info-label"><?= $t[$lang]['created_by'] ?></span><span class="info-value"><?= htmlspecialchars($car['created_by']) ?></span></div>
                        <?php if ($noteText !== ''): ?>
                        <div class="info-row info-row-note">
                            <span class="info-label">📝 <?= $t[$lang]['notes'] ?></span>
                            <span class="note-value"><?= htmlspecialchars($noteText) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="actions">
                        <a href="vehicle_timeline.php?id=<?= $car['id'] ?>&lang=<?= $lang ?>" class="btn btn-journey">👁 <?= $t[$lang]['journey'] ?></a>
                        <div class="btn btn-disabled">✅ <?= $t[$lang]['status_sold'] ?></div>
                        <div class="btn btn-disabled">🔄 <?= $t[$lang]['locked'] ?></div>
                        <div class="btn btn-disabled">🚫 <?= $t[$lang]['locked'] ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- Bottom Nav -->
<nav class="bottom-nav" role="navigation">
    <a href="dashboard.php?lang=<?= $lang ?>" class="nav-link">
        <span class="nav-icon">🏠</span><?= $t[$lang]['dashboard'] ?>
    </a>
    <?php if (can('page.stock_report')): ?>
    <a href="stock_report.php?lang=<?= $lang ?>" class="nav-link">
        <span class="nav-icon">📄</span><?= $t[$lang]['stock_report'] ?>
    </a>
    <?php endif; ?>
    <?php if (can('page.prices')): ?>
    <a href="prices.php?lang=<?= $lang ?>" class="nav-link">
        <span class="nav-icon">💲</span><?= $t[$lang]['Prices'] ?>
    </a>
    <?php endif; ?>
    <?php if (can('page.add_vehicle')): ?>
        <a href="add_vehicle.php?lang=<?= $lang ?>" class="nav-link">
            <span class="nav-icon">➕</span><?= $t[$lang]['add_vehicle'] ?>
        </a>
    <?php else: ?>
        <div class="nav-link nav-link-disabled">
            <span class="nav-icon">➕</span><?= $t[$lang]['add_vehicle'] ?>
        </div>
    <?php endif; ?>
    <?php if (can('dash.nav_attendance') && can('page.attendance')): ?>
    <a href="attendance.php?lang=<?= $lang ?>" class="nav-link">
        <span class="nav-icon">🕐</span><?= $lang === 'ar' ? 'بصمة' : 'Basma' ?>
    </a>
    <?php endif; ?>
    <button type="button" class="nav-link nav-more-btn" onclick="openMore()">
        <span class="nav-icon">⋯</span><?= $lang === 'ar' ? 'المزيد' : 'More' ?>
    </button>

</nav>

<!-- More menu (extra pages) -->
<div class="more-overlay" id="moreOverlay" onclick="closeMore(event)">
    <div class="more-sheet" onclick="event.stopPropagation()">
        <div class="more-handle"></div>
        <div class="more-grid">
            <?php if (can('page.forecast')): ?>
            <a href="forecast.php?lang=<?= $lang ?>" class="more-item">
                <span class="mi-icon">📊</span><?= $lang === 'ar' ? 'التوقعات' : 'Forecast' ?>
            </a>
            <?php endif; ?>
            <?php if (can('page.incoming_cars')): ?>
            <a href="incoming_cars.php?lang=<?= $lang ?>" class="more-item">
                <span class="mi-icon">🚚</span><?= $lang === 'ar' ? 'الالوان' : 'Incoming' ?>
            </a>
            <?php endif; ?>
            <?php if (can('page.attendance_admin')): ?>
            <a href="attendance_admin.php?lang=<?= $lang ?>" class="more-item">
                <span class="mi-icon">📋</span><?= $lang === 'ar' ? 'سجل البصمة' : 'Attendance Log' ?>
            </a>
            <?php endif; ?>
            <?php if (can('page.users')): ?>
            <a href="users.php?lang=<?= $lang ?>" class="more-item">
                <span class="mi-icon">👥</span><?= $lang === 'ar' ? 'المستخدمون' : 'Users' ?>
            </a>
            <?php endif; ?>
            <a href="logout.php" class="more-item more-item-logout">
                <span class="mi-icon">🚪</span><?= $t[$lang]['logout'] ?>
            </a>
        </div>
    </div>
</div>

<button class="scroll-top" id="scrollTop" aria-label="Scroll to top"
        onclick="window.scrollTo({top:0,behavior:'smooth'})">↑</button>

<script>
    const LANG_NO_RESULTS = <?= json_encode($t[$lang]['no_results']) ?>;

    // ══════════════════════════════════════════════════════
    //  Animated quote banner (rotation + admin editing)
    // ══════════════════════════════════════════════════════
    const QUOTES = <?= json_encode(array_values($activeQuotes), JSON_UNESCAPED_UNICODE) ?>;
    const DEFAULT_QUOTES = <?= json_encode(array_values($defaultQuotes), JSON_UNESCAPED_UNICODE) ?>;
    let quoteIdx = <?= (int) $quoteStartIdx ?>;
    const quoteTextEl = document.getElementById('quoteText');

    function rotateQuote() {
        if (!quoteTextEl || QUOTES.length <= 1) return;
        quoteTextEl.classList.add('q-hidden');
        setTimeout(() => {
            quoteIdx = (quoteIdx + 1) % QUOTES.length;
            quoteTextEl.textContent = QUOTES[quoteIdx];
            quoteTextEl.classList.remove('q-hidden');
        }, 450);
    }
    let quoteTimer = setInterval(rotateQuote, 5500);

    // ── Admin editor (only wired if the button exists) ──
    const QE_SAVED = <?= json_encode($lang === 'ar' ? '✓ اتحفظ!' : '✓ Saved!') ?>;
    const QE_ERR   = <?= json_encode($lang === 'ar' ? '⚠️ حصل خطأ، حاول تاني' : '⚠️ Something went wrong') ?>;

    function openQuoteEditor() {
        const o = document.getElementById('qeOverlay');
        if (o) o.classList.add('open');
    }
    function closeQuoteEditor() {
        const o = document.getElementById('qeOverlay');
        if (o) o.classList.remove('open');
        const s = document.getElementById('qeStatus');
        if (s) s.textContent = '';
    }
    function clearQuotes() {
        const ta = document.getElementById('qeText');
        if (ta) ta.value = '';
        postQuotes('');
    }
    function saveQuotes() {
        const ta = document.getElementById('qeText');
        postQuotes(ta ? ta.value : '');
    }
    async function postQuotes(val) {
        const status = document.getElementById('qeStatus');
        if (status) { status.style.color = '#94a3b8'; status.textContent = '...'; }
        try {
            const res = await fetch('quote_save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ value: val })
            });
            const data = await res.json();
            if (data && data.ok) {
                // Rebuild the live rotation immediately
                let lines = String(data.value || '').split(/\r\n|\r|\n/).map(s => s.trim()).filter(Boolean);
                if (!lines.length) lines = DEFAULT_QUOTES.slice();
                QUOTES.length = 0; QUOTES.push(...lines);
                quoteIdx = 0;
                if (quoteTextEl) {
                    quoteTextEl.classList.add('q-hidden');
                    setTimeout(() => { quoteTextEl.textContent = QUOTES[0]; quoteTextEl.classList.remove('q-hidden'); }, 300);
                }
                // Update / toggle the "Custom" tag
                const banner = document.getElementById('quoteBanner');
                let tag = banner ? banner.querySelector('.quote-custom-tag') : null;
                const isCustom = lines.length && val.trim() !== '';
                if (isCustom && !tag && banner) {
                    tag = document.createElement('span');
                    tag.className = 'quote-custom-tag';
                    tag.textContent = <?= json_encode($lang === 'ar' ? 'مخصص' : 'Custom') ?>;
                    banner.insertBefore(tag, banner.querySelector('.quote-edit-btn'));
                } else if (!isCustom && tag) {
                    tag.remove();
                }
                if (status) { status.style.color = '#4ade80'; status.textContent = QE_SAVED; }
                setTimeout(closeQuoteEditor, 750);
            } else {
                if (status) { status.style.color = '#f87171'; status.textContent = QE_ERR; }
            }
        } catch (e) {
            if (status) { status.style.color = '#f87171'; status.textContent = QE_ERR; }
        }
    }

    // ── Live search: filter cards in-page, no reload (keeps keyboard open) ──
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        // Stop the form from reloading the page on Enter — filtering is live.
        const searchForm = searchInput.closest('form');
        if (searchForm) {
            searchForm.addEventListener('submit', (e) => { e.preventDefault(); searchInput.blur(); });
        }

        const availableCards = Array.from(document.querySelectorAll('.vehicle-card[data-search]'));
        // The available-vehicles section divider (to hide if nothing matches)
        let liveMsg = null;

        function runLiveSearch() {
            const q = searchInput.value.trim().toLowerCase();
            let shown = 0;
            availableCards.forEach(card => {
                const hay = card.getAttribute('data-search') || '';
                const match = q === '' || hay.indexOf(q) !== -1;
                card.style.display = match ? '' : 'none';
                if (match) shown++;
            });

            // Show a gentle "no results" line inside the available grid
            const grid = availableCards.length ? availableCards[0].parentElement : null;
            if (grid) {
                if (!liveMsg) {
                    liveMsg = document.createElement('div');
                    liveMsg.style.cssText = 'grid-column:1/-1;text-align:center;color:#64748b;padding:30px;font-size:15px;';
                    liveMsg.textContent = '🔍 ' + (LANG_NO_RESULTS || 'No results');
                    grid.appendChild(liveMsg);
                }
                liveMsg.style.display = (q !== '' && shown === 0) ? '' : 'none';
            }
        }

        searchInput.addEventListener('input', runLiveSearch);
        // Run once on load in case the field is pre-filled
        if (searchInput.value.trim() !== '') runLiveSearch();
    }

    // ── "More" menu sheet ──
    function openMore() {
        document.getElementById('moreOverlay').classList.add('open');
    }
    function closeMore(e) {
        // close only when tapping the dark overlay (not the sheet itself)
        document.getElementById('moreOverlay').classList.remove('open');
    }

    // ── Scroll to top ──
    const scrollBtn = document.getElementById('scrollTop');
    window.addEventListener('scroll', () => {
        scrollBtn.classList.toggle('visible', window.scrollY > 400);
    }, { passive: true });

    // ══════════════════════════════════════════════════════
    //  WhatsApp Interactive Flow
    // ══════════════════════════════════════════════════════

    let _waMode  = '';
    let _waData  = {};
    let _waPrice = '';
    let _waStep  = 0;

    function waCustomer(name, year, trim, color, custPrice) {
        _waMode  = 'customer';
        _waData  = { name, year, trim, color };
        _waPrice = custPrice;
        startWaFlow();
    }

    function waDealer(name, year, trim, color, tradePrice) {
        _waMode  = 'dealer';
        _waData  = { name, year, trim, color };
        _waPrice = tradePrice;
        startWaFlow();
    }

    function startWaFlow() {
        _waStep = 1;
        buildModal();
        showStep(1);
        document.getElementById('waModal').classList.add('open');
    }

    function buildModal() {
        if (document.getElementById('waModal')) {
            updateStep1();
            return;
        }
        const isAr = document.documentElement.lang === 'ar';

        const div = document.createElement('div');
        div.id = 'waModal';
        div.innerHTML = `
        <div id="waBackdrop"></div>
        <div id="waSheet">
            <div id="waStep1" class="wa-step">
                <div class="wa-step-icon" id="s1icon">💬</div>
                <div class="wa-step-title" id="s1title"></div>
                <div class="wa-step-sub" id="s1sub"></div>
                <div class="wa-car-chip" id="s1chip"></div>
                <div class="wa-btn-row">
                    <button class="wa-btn wa-btn-yes" onclick="stepPriceConfirm()">
                        ${isAr ? '✅ نعم، أضف السعر' : '✅ Yes, include price'}
                    </button>
                    <button class="wa-btn wa-btn-no" onclick="stepNoPrice()">
                        ${isAr ? '❌ لا، بدون سعر' : '❌ No price'}
                    </button>
                </div>
                <button class="wa-close" onclick="closeWa()">✕</button>
            </div>

            <div id="waStep2" class="wa-step" style="display:none;">
                <div class="wa-step-icon">💰</div>
                <div class="wa-step-title">${isAr ? 'تأكيد السعر' : 'Confirm Price'}</div>
                <div class="wa-price-display" id="s2price"></div>
                <div class="wa-step-sub" id="s2sub"></div>
                <div class="wa-edit-wrap" id="s2editWrap" style="display:none;">
                    <input class="wa-price-input" id="s2editInput" type="text"
                           placeholder="${isAr ? 'أدخل السعر (مثال: 850,000 ج.م)' : 'Enter price (e.g. 850,000 EGP)'}">
                </div>
                <div class="wa-btn-row">
                    <button class="wa-btn wa-btn-yes" onclick="stepConfirmPrice()">
                        ${isAr ? '✅ السعر صحيح' : '✅ Price is correct'}
                    </button>
                    <button class="wa-btn wa-btn-edit" onclick="togglePriceEdit()">
                        ${isAr ? '✏️ تعديل السعر' : '✏️ Edit price'}
                    </button>
                </div>
                <button class="wa-back" onclick="showStep(1)">
                    ${isAr ? '← رجوع' : '← Back'}
                </button>
                <button class="wa-close" onclick="closeWa()">✕</button>
            </div>

            <div id="waStep3" class="wa-step" style="display:none;">
                <div class="wa-loading-ring"></div>
                <div class="wa-step-title" id="s3title">
                    ${isAr ? 'جاري تجهيز الرسالة...' : 'Preparing your message...'}
                </div>
                <div class="wa-progress-wrap"><div class="wa-progress-bar" id="waProgress"></div></div>
                <div class="wa-loading-sub" id="s3sub"></div>
            </div>

            <div id="waStep4" class="wa-step" style="display:none;">
                <div class="wa-step-icon">📋</div>
                <div class="wa-step-title">
                    ${isAr ? 'معاينة الرسالة' : 'Message Preview'}
                </div>
                <div class="wa-step-sub">
                    ${isAr ? 'يمكنك تعديل الرسالة قبل الإرسال' : 'You can edit before sending'}
                </div>
                <textarea class="wa-msg-area" id="s4textarea"></textarea>
                <div class="wa-btn-row" style="margin-top:12px;">
                    <button class="wa-btn wa-btn-send" onclick="sendToWhatsApp()">
                        <span>📲</span> ${isAr ? 'إرسال واتساب' : 'Send WhatsApp'}
                    </button>
                </div>
                <button class="wa-back" onclick="showStep(1)">
                    ${isAr ? '← إعادة' : '← Restart'}
                </button>
                <button class="wa-close" onclick="closeWa()">✕</button>
            </div>
        </div>`;
        document.body.appendChild(div);
        document.getElementById('waBackdrop').onclick = closeWa;
    }

    function updateStep1() {
        const isAr = document.documentElement.lang === 'ar';
        const d = _waData;
        const icon  = _waMode === 'dealer' ? '🤝' : '💬';
        const title = _waMode === 'dealer'
            ? (isAr ? 'رسالة تاجر' : 'Dealer Message')
            : (isAr ? 'رسالة عميل' : 'Customer Message');
        const sub = _waMode === 'dealer'
            ? (isAr ? 'هل تريد إضافة سعر التاجر للرسالة؟' : 'Include trade price in message?')
            : (isAr ? 'هل تريد إضافة سعر البيع للرسالة؟' : 'Include selling price in message?');
        document.getElementById('s1icon').textContent  = icon;
        document.getElementById('s1title').textContent = title;
        document.getElementById('s1sub').textContent   = sub;
        document.getElementById('s1chip').textContent  =
            '🚗 ' + d.name + ' ' + d.year + '  |  ' + d.trim + '  |  ' + d.color;
    }

    function showStep(n) {
        [1,2,3,4].forEach(i => {
            const el = document.getElementById('waStep'+i);
            if (el) el.style.display = i === n ? 'flex' : 'none';
        });
        _waStep = n;
        if (n === 1) updateStep1();
    }

    function stepNoPrice() {
        _waPrice = '';
        runLoading();
    }

    function stepPriceConfirm() {
        const isAr = document.documentElement.lang === 'ar';
        showStep(2);
        const el = document.getElementById('s2price');
        if (_waPrice) {
            el.textContent = _waPrice;
            el.style.color = '#22c55e';
            document.getElementById('s2sub').textContent =
                isAr ? 'هل هذا هو السعر الصحيح للإرسال؟' : 'Is this the correct price to send?';
        } else {
            el.textContent = isAr ? '⚠️ لا يوجد سعر مسجل' : '⚠️ No price on record';
            el.style.color = '#f59e0b';
            document.getElementById('s2sub').textContent =
                isAr ? 'يمكنك إدخال السعر يدوياً' : 'You can enter the price manually';
            togglePriceEdit(true);
        }
        document.getElementById('s2editWrap').style.display = 'none';
        document.getElementById('s2editInput').value = '';
    }

    let _editOpen = false;
    function togglePriceEdit(forceOpen) {
        const wrap = document.getElementById('s2editWrap');
        _editOpen = forceOpen === true ? true : !_editOpen;
        wrap.style.display = _editOpen ? 'block' : 'none';
        if (_editOpen && _waPrice) document.getElementById('s2editInput').value = _waPrice;
    }

    function stepConfirmPrice() {
        const edited = document.getElementById('s2editInput').value.trim();
        if (_editOpen && edited) _waPrice = edited;
        runLoading();
    }

    const loadingPhrases = {
        ar: [
            '🔍 جاري جمع بيانات السيارة...',
            '📋 تجهيز تفاصيل العرض...',
            '💼 إضافة معلومات الشركة...',
            '🎨 تنسيق الرسالة...',
            '✅ الرسالة جاهزة!',
        ],
        en: [
            '🔍 Gathering vehicle data...',
            '📋 Preparing offer details...',
            '💼 Adding company information...',
            '🎨 Formatting the message...',
            '✅ Message ready!',
        ]
    };

    function runLoading() {
        showStep(3);
        const lang = document.documentElement.lang === 'ar' ? 'ar' : 'en';
        const phrases = loadingPhrases[lang];
        const bar = document.getElementById('waProgress');
        const sub = document.getElementById('s3sub');
        bar.style.width = '0%';
        bar.style.transition = 'none';
        let i = 0;
        sub.textContent = phrases[0];
        const interval = setInterval(() => {
            i++;
            if (i < phrases.length) {
                sub.textContent = phrases[i];
                bar.style.transition = 'width 1s ease';
                bar.style.width = ((i / (phrases.length - 1)) * 100) + '%';
            }
            if (i >= phrases.length - 1) {
                clearInterval(interval);
                setTimeout(showPreview, 800);
            }
        }, 1000);
        bar.style.transition = 'width .5s ease';
        bar.style.width = '5%';
    }

    function showPreview() {
        const msg = _waMode === 'dealer'
            ? buildDealerMsg(_waData, _waPrice)
            : buildCustomerMsg(_waData, _waPrice);
        document.getElementById('s4textarea').value = msg;
        showStep(4);
    }

    function sendToWhatsApp() {
        const msg = document.getElementById('s4textarea').value;
        window.open('https://wa.me/?text=' + encodeURIComponent(msg), '_blank');
        closeWa();
    }

    function closeWa() {
        const m = document.getElementById('waModal');
        if (m) m.classList.remove('open');
    }

    // ── Message builders (no chassis, no branch) ──
    function buildCustomerMsg(d, price) {
        const priceLine = price ? '\n💰 سعر البيع: *' + price + '*' : '';
        return (
'🚗 فرست 1 كار – First 1 Car\n' +
'موزعون معتمدون لأكبر العلامات التجارية  ✔️\n' +
'✔️ أكتر من براند متاح للتسليم الفوري\n' +
'✔️ أكتر من صالة عرض جاهزة لاستقبالكم\n' +
'✔️ أسرع تسليم وترخيص للسيارة خلال 48 ساعة فقط\n\n' +
'━━━━━━━━━━━━━━━━━━━━\n' +
'🚘 *' + d.name + ' ' + d.year + '*\n' +
'📋 الفئة: ' + d.trim + '\n' +
'🎨 اللون: ' + d.color +
priceLine + '\n' +
'━━━━━━━━━━━━━━━━━━━━\n\n' +
'🎯 عروض خاصة لـ:\n' +
'• ربة المنزل\n• الأجانب\n• العاملين بالخارج\n\n' +
'💥 مفاجآت وعروض حصرية:\n' +
'👈 كاش باك يصل إلى 5%\n' +
'👈 ترخيص هدية\n' +
'👈 تأمين مجاني\n' +
'👈 صيانة مجانية\n\n' +
'📍 اختار عربيتك وانت مطمّن…\n' +
'اعتماد رسمي – سرعة في التسليم – أفضل عروض\n\n' +
'📞 تواصل معنا الآن واحجز عربيتك فورًا عن طريق رسايل الصفحه\n' +
'01117550080 - 01120080191\n' +
'01110440482 - 01120058020\n' +
'01110203098 - 01110203099\n' +
'01120080607 - 01110066487\n' +
'01110070437 - 01110070432\n' +
'01110067528\n\n' +
'🏢 لزيارة فروعنا:\n' +
'الفرع الرئيسي: 62 ش الحجاز - هليوبلوس\nhttps://maps.app.goo.gl/hhjx5xhd8diZjaic9\n☎️ 0226397788 & 0226397555\n\n' +
'فرع مصر الجديدة: 44 ش عبد العزيز فهمي\nhttps://maps.app.goo.gl/G8UGyDD3iftuqBqz5\n☎️ 0226399874\n\n' +
'فرع الجيزة: 164 شارع البحر الأعظم بالجيزة\nhttps://maps.app.goo.gl/6PS5VYpB71HwdR5u6\n☎️ 0235712733\n\n' +
'فرع مدينة نصر: ش عباس العقاد - بعد وندر لاند\n☎️ 0223896441\nhttps://maps.app.goo.gl/fgtksvgnhD1swuuy5\n\n' +
'فرع العاشر من رمضان: الأردنية عمارة 1 - مول دلتا سنتر\nhttps://maps.app.goo.gl/ZH5KtXci7PqTBBBt6\n☎️ 0554359936'
        );
    }

    function buildDealerMsg(d, price) {
        const priceLine = price
            ? '\n💼 سعر التاجر: *' + price + '*'
            : '\n💼 سعر التاجر: يُحدَّد عند التواصل';
        return (
'🏢 *First 1 Car – فرست 1 كار*\n' +
'موزعون معتمدون | B2B\n\n' +
'━━━━━━━━━━━━━━━━━━━━\n' +
'🚘 *' + d.name + ' ' + d.year + '*\n' +
'📋 الفئة: ' + d.trim + '\n' +
'🎨 اللون: ' + d.color +
priceLine + '\n' +
'━━━━━━━━━━━━━━━━━━━━\n\n' +
'✅ السيارة متاحة للاستلام الفوري\n' +
'📄 جميع الأوراق جاهزة\n' +
'🤝 نرحب بالتعامل مع التجار والموزعين\n\n' +
'📞 للتفاصيل والتفاوض:\n' +
'01110440482 - 011102030\n\n' +
'رقم التسجيل الضريبي: 895-607-506'
        );
    }

    // ── امانة: live elapsed timer (admin/manager only) ──
    const AMANA_D = <?= json_encode($t[$lang]['amana_days']) ?>;
    const AMANA_H = <?= json_encode($t[$lang]['amana_hours']) ?>;
    function tickAmana() {
        const now = Math.floor(Date.now() / 1000);
        document.querySelectorAll('.amana-timer-pill').forEach(el => {
            const start = parseInt(el.dataset.start || '0', 10);
            if (!start) { el.textContent = '⏱ —'; return; }
            let s = now - start; if (s < 0) s = 0;
            const days  = Math.floor(s / 86400);
            const hours = Math.floor((s % 86400) / 3600);
            const mins  = Math.floor((s % 3600) / 60);

            // Clean format: show the two most relevant units
            let txt;
            if (days > 0)       txt = days + AMANA_D + ' ' + hours + AMANA_H;
            else if (hours > 0) txt = hours + AMANA_H + ' ' + mins + 'm';
            else                txt = mins + 'm';
            el.textContent = '⏱ ' + txt;

            // Urgency color: green < 3d, amber 3–7d, red > 7d
            el.classList.remove('t-fresh', 't-warn', 't-late');
            if (days >= 7)      el.classList.add('t-late');
            else if (days >= 3) el.classList.add('t-warn');
            else                el.classList.add('t-fresh');
        });
    }
    tickAmana();
    setInterval(tickAmana, 60000);

    // ── امانة: return-car modal ──
    function openReturn(carId, carName) {
        document.getElementById('returnCarId').value = carId;
        document.getElementById('returnCarName').textContent = carName;
        document.getElementById('amanaReturnModal').classList.add('open');
    }
    function closeReturn() {
        document.getElementById('amanaReturnModal').classList.remove('open');
    }
    function submitReturn() {
        const branch = document.getElementById('returnBranch').value;
        if (!branch) { document.getElementById('returnBranch').style.borderColor = '#ef4444'; return; }
        document.getElementById('amanaReturnForm').submit();
    }
</script>

<style>
/* ── WhatsApp Modal ── */
#waModal { display:none; }
#waModal.open { display:block; }

#waBackdrop {
    position:fixed; inset:0; z-index:2000;
    background:rgba(2,6,23,.85); backdrop-filter:blur(6px);
}
#waSheet {
    position:fixed; z-index:2001;
    bottom:0; left:0; right:0;
    background: linear-gradient(180deg,#0f1a2e,#0a1220);
    border-top:1px solid rgba(34,197,94,.25);
    border-radius:28px 28px 0 0;
    padding:28px 22px 40px;
    max-height:90vh; overflow-y:auto;
    box-shadow:0 -20px 60px rgba(0,0,0,.6);
    animation:sheetUp .35s cubic-bezier(.2,.8,.2,1);
}
@keyframes sheetUp { from{transform:translateY(100%);} to{transform:translateY(0);} }

.wa-step {
    display:flex; flex-direction:column; align-items:center;
    gap:14px; text-align:center; position:relative;
}
.wa-step-icon { font-size:52px; line-height:1; }
.wa-step-title { font-size:22px; font-weight:900; color:#f1f5f9; }
.wa-step-sub { font-size:14px; color:#94a3b8; max-width:340px; line-height:1.5; }

.wa-car-chip {
    background:rgba(34,197,94,.08); border:1px solid rgba(34,197,94,.2);
    border-radius:50px; padding:8px 18px;
    font-size:13px; font-weight:700; color:#86efac;
    max-width:100%; text-align:center;
}

.wa-btn-row { display:flex; gap:10px; flex-wrap:wrap; justify-content:center; margin-top:4px; }
.wa-btn {
    height:50px; padding:0 24px; border:none; border-radius:14px;
    font-weight:800; font-size:15px; cursor:pointer; font-family:inherit;
    transition:transform .2s, box-shadow .2s;
}
.wa-btn:hover { transform:translateY(-2px); }
.wa-btn-yes  { background:linear-gradient(90deg,#16a34a,#22c55e); color:#001a08; box-shadow:0 6px 20px rgba(34,197,94,.3); }
.wa-btn-no   { background:#1e2d40; color:#94a3b8; border:1px solid rgba(255,255,255,.1); }
.wa-btn-edit { background:rgba(147,51,234,.2); color:#c084fc; border:1px solid rgba(147,51,234,.3); }
.wa-btn-send { background:linear-gradient(90deg,#16a34a,#22c55e); color:#001a08; font-size:16px; padding:0 32px; height:54px; box-shadow:0 8px 24px rgba(34,197,94,.35); display:flex; align-items:center; gap:8px; }

.wa-price-display { font-size:36px; font-weight:900; padding:16px 28px; background:rgba(0,0,0,.25); border-radius:18px; border:1px solid rgba(34,197,94,.2); min-width:200px; }

.wa-edit-wrap { width:100%; }
.wa-price-input {
    width:100%; height:52px; border:2px solid rgba(147,51,234,.4); outline:none;
    background:#0d1526; color:white; padding:0 16px;
    border-radius:14px; font-size:16px; font-family:inherit; text-align:center;
    transition:border-color .2s;
}
.wa-price-input:focus { border-color:#9333ea; box-shadow:0 0 0 3px rgba(147,51,234,.2); }

.wa-loading-ring {
    width:80px; height:80px; border-radius:50%;
    border:4px solid rgba(34,197,94,.15);
    border-top-color:#22c55e; border-right-color:#9333ea;
    animation:spin 1s linear infinite;
    box-shadow:0 0 30px rgba(34,197,94,.2);
    margin-bottom:4px;
}
@keyframes spin { to{transform:rotate(360deg);} }
.wa-progress-wrap { width:100%; max-width:320px; height:6px; background:rgba(255,255,255,.07); border-radius:50px; overflow:hidden; }
.wa-progress-bar { height:100%; width:0%; background:linear-gradient(90deg,#22c55e,#9333ea); border-radius:50px; transition:width 1s ease; }
.wa-loading-sub { font-size:14px; color:#94a3b8; min-height:24px; }

.wa-msg-area {
    width:100%; height:280px; resize:vertical; min-height:180px;
    background:#0a1220; border:1px solid rgba(34,197,94,.2);
    border-radius:16px; color:#e2e8f0; font-size:13px; line-height:1.7;
    padding:14px; font-family:'Segoe UI',Tahoma,sans-serif; outline:none;
    direction:rtl;
}
.wa-msg-area:focus { border-color:rgba(34,197,94,.5); box-shadow:0 0 0 3px rgba(34,197,94,.1); }

.wa-back { background:none; border:none; color:#64748b; font-size:13px; font-weight:700; cursor:pointer; font-family:inherit; margin-top:4px; }
.wa-back:hover { color:#94a3b8; }
.wa-close { position:absolute; top:-8px; inset-inline-end:0; background:none; border:none; color:#64748b; font-size:22px; cursor:pointer; line-height:1; }
.wa-close:hover { color:#ef4444; }
</style>

<?php if ($canAmanaActions && !empty($amanaCarsList)): ?>
<!-- امانة: Return-to-branch modal (permission-gated) -->
<div class="amana-modal-overlay" id="amanaReturnModal">
    <div class="amana-modal">
        <h3>🔄 <?= $t[$lang]['amana_return_title'] ?></h3>
        <div class="modal-car" id="returnCarName">—</div>
        <form method="POST" action="consignment_return.php?lang=<?= $lang ?>" id="amanaReturnForm">
            <input type="hidden" name="car_id" id="returnCarId" value="">
            <label for="returnBranch"><?= $t[$lang]['amana_return_to'] ?></label>
            <select name="return_branch" id="returnBranch" required>
                <option value=""><?= $lang === 'ar' ? 'اختر الفرع' : 'Select branch' ?></option>
                <?php foreach ($amanaBranches as $b): ?>
                    <option value="<?= htmlspecialchars($b['name']) ?>">
                        <?= htmlspecialchars($lang === 'ar' ? ($b['name_ar'] ?: $b['name']) : ($b['name_en'] ?: $b['name'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="amana-modal-actions">
                <button type="button" class="modal-cancel" onclick="closeReturn()"><?= $t[$lang]['amana_cancel'] ?></button>
                <button type="button" class="modal-confirm" onclick="submitReturn()"><?= $t[$lang]['amana_return_confirm'] ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canQuotesEdit): ?>
<!-- Quote editor (permission-gated) -->
<div class="qe-overlay" id="qeOverlay" onclick="if(event.target===this)closeQuoteEditor()">
    <div class="qe-box">
        <div class="qe-head">
            <h3>✏️ <?= $lang === 'ar' ? 'تعديل عبارات اللوحة' : 'Edit Banner Quotes' ?></h3>
            <button type="button" class="qe-x" onclick="closeQuoteEditor()">✕</button>
        </div>
        <p class="qe-hint">
            <?= $lang === 'ar'
                ? 'اكتب كل عبارة في سطر منفصل، وهتتبدّل تلقائياً على اللوحة. لو مسحت كل الكلام وحفظت، هترجع العبارات الأصلية لوحدها.'
                : 'Write one quote per line — they rotate automatically on the banner. Clear everything and save to restore the original quotes.' ?>
        </p>
        <textarea class="qe-textarea" id="qeText" placeholder="<?= $lang === 'ar' ? 'عبارة في كل سطر...' : 'One quote per line...' ?>"><?= htmlspecialchars($customQuotesRaw) ?></textarea>
        <div class="qe-actions">
            <button type="button" class="qe-btn qe-clear" onclick="clearQuotes()"><?= $lang === 'ar' ? '🗑 مسح ورجوع للأصلي' : '🗑 Clear & restore' ?></button>
            <button type="button" class="qe-btn qe-save" onclick="saveQuotes()"><?= $lang === 'ar' ? '💾 حفظ' : '💾 Save' ?></button>
        </div>
        <div class="qe-status" id="qeStatus"></div>
    </div>
</div>
<?php endif; ?>

<?php include 'chatbot_widget.php'; ?>

<!-- ══ per-car QR modal ══ -->
<div class="qr-modal" id="qrModal">
    <div class="qr-box">
        <div class="qr-name" id="qrName"></div>
        <div class="qr-ch" id="qrCh"></div>
        <div class="qr-panel"><div id="qrHolder" style="width:230px;height:230px"></div></div>
        <div id="qrErr" style="display:none;color:#fca5a5;font-size:12px;margin-top:10px">⚠️ QR library blocked — check internet</div>
        <div class="qr-hint"><?= $t[$lang]['qr_hint'] ?></div>
        <div class="qr-actions">
            <?php if (can('qr.manage')): ?>
            <a class="qr-print" id="qrPrint" href="#"><?= $t[$lang]['qr_print'] ?></a>
            <?php endif; ?>
            <button type="button" class="qr-close" id="qrClose"><?= $t[$lang]['qr_close'] ?></button>
        </div>
    </div>
</div>
<script>
(function(){
    var modal = document.getElementById('qrModal');
    var lib = null;
    /* qrcodejs (davidshimjs) — global QRCode constructor, renders into a div */
    function ensureQrLib(){
        if (lib) return lib;
        lib = new Promise(function(resolve, reject){
            if (window.QRCode && window.QRCode.CorrectLevel) { resolve(); return; }
            var urls = ['https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
                        'https://cdn.jsdelivr.net/npm/davidshimjs-qrcodejs@0.0.2/qrcode.min.js'];
            (function tryUrl(i){
                if (i >= urls.length) { lib = null; reject(); return; }
                var s = document.createElement('script');
                s.src = urls[i];
                s.onload  = function(){ (window.QRCode && window.QRCode.CorrectLevel) ? resolve() : tryUrl(i+1); };
                s.onerror = function(){ tryUrl(i+1); };
                document.head.appendChild(s);
            })(0);
        });
        return lib;
    }
    window.__ensureQrLib = ensureQrLib;
    window.showQr = function(chassis, name, id){
        document.getElementById('qrName').textContent = '🚗 ' + name;
        document.getElementById('qrCh').textContent = chassis;
        document.getElementById('qrErr').style.display = 'none';
        var pr = document.getElementById('qrPrint');
        if (pr) pr.href = 'qr_stickers.php?lang=<?= $lang ?>&ids=' + id;
        modal.classList.add('show');
        var url = location.origin + '/qr.php?c=' + encodeURIComponent(chassis);
        var holder = document.getElementById('qrHolder');
        holder.innerHTML = '';
        ensureQrLib().then(function(){
            new QRCode(holder, {
                text: url, width: 230, height: 230,
                colorDark: '#0f172a', colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        }).catch(function(){
            document.getElementById('qrErr').style.display = 'block';
        });
    };
    document.getElementById('qrClose').addEventListener('click', function(){ modal.classList.remove('show'); });
    modal.addEventListener('click', function(e){ if (e.target === modal) modal.classList.remove('show'); });
})();

/* ══ on-card QR stickers: lazy render as cards scroll into view ══ */
(function(){
    var stickers = document.querySelectorAll('.qr-sticker');
    if (!stickers.length) return;

    function renderSticker(el){
        if (el.dataset.done) return;
        el.dataset.done = '1';
        window.__ensureQrLib().then(function(){
            var holder = el.querySelector('.qs');
            holder.classList.remove('qs-wait');
            holder.innerHTML = '';
            /* draw at 96px, CSS scales down to 44px → extra crisp */
            new QRCode(holder, {
                text: location.origin + '/qr.php?c=' + encodeURIComponent(el.getAttribute('data-ch')),
                width: 96, height: 96,
                colorDark: '#0f172a', colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        }).catch(function(){ /* keep placeholder pattern */ });
    }

    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function(entries){
            entries.forEach(function(en){
                if (en.isIntersecting){ renderSticker(en.target); io.unobserve(en.target); }
            });
        }, { rootMargin: '260px' });
        stickers.forEach(function(el){ io.observe(el); });
    } else {
        stickers.forEach(renderSticker);
    }

    /* tap → the full scannable modal (same one as before) */
    stickers.forEach(function(el){
        el.addEventListener('click', function(){
            showQr(el.getAttribute('data-ch'), el.getAttribute('data-name'), el.getAttribute('data-id'));
        });
    });
})();
</script>
</body>
</html>
