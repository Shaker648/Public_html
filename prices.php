<?php
require 'auth.php';
require 'config.php';

$lang = $_GET['lang'] ?? 'ar';
$dir  = $lang === 'ar' ? 'rtl' : 'ltr';
perm_require('page.prices');

$role = $_SESSION['role'] ?? 'sales';
$canEdit = can('prices.edit');          // add / edit / delete prices
$isSales = !can('prices.trade_price');  // without this permission the trade price shows locked 🔒

/* ─── Translations ─── */
$t = [
    'ar' => [
        'title'          => 'إدارة الأسعار',
        'subtitle'       => 'أسعار الموديلات والفئات',
        'dashboard'      => 'الرئيسية',
        'inventory'      => 'المخزون',
        'stock_report'   => 'تقرير المخزون',
        'price_history'  => 'سجل الأسعار',
        'brand'          => 'الماركة',
        'model'          => 'الموديل',
        'trim'           => 'الفئة',
        'year'           => 'السنة',
        'official_price' => 'السعر الرسمي',
        'customer_price' => 'سعر العميل',
        'trade_price'    => 'سعر التجاري',
        'notes'          => 'ملاحظات',
        'notes_ph'       => 'أضف ملاحظة...',
        'notes_optional' => 'اختياري',
        'last_updated'   => 'آخر تحديث',
        'updated_by'     => 'بواسطة',
        'save'           => 'حفظ',
        'edit'           => 'تعديل',
        'cancel'         => 'إلغاء',
        'add_pricing'    => 'إضافة سعر',
        'mgmt_only'      => 'للإدارة فقط',
        'sales_note'     => 'عرض الأسعار فقط — التعديل متاح للمديرين والمسؤولين.',
        'last_update_label' => 'آخر تحديث للأسعار',
        'no_price'       => 'غير محدد',
        'save_success'   => 'تم الحفظ بنجاح',
        'not_set'        => '—',
        'all_brands'     => 'كل الماركات',
        'filter'         => 'تصفية',
        'search_ph'      => 'بحث بالموديل أو الفئة...',
        'total_models'   => 'إجمالي الموديلات',
        'priced'         => 'مسعّرة',
        'unpriced'       => 'بدون سعر',
        'currency'       => 'جنيه',
        'required'       => 'مطلوب',
        'optional'       => 'اختياري',
        'year_required'  => 'اختر السنة (مطلوب)',
        'year2_optional' => 'سنة ثانية (اختياري — ينسخ الصف)',
        'trade_locked'   => 'محجوب',
        'sort_hint'      => 'اضغط مطولاً لتغيير الترتيب',
        'save_order'     => 'حفظ الترتيب',
        'saving_order'   => 'جاري الحفظ...',
        'order_saved'    => 'تم حفظ الترتيب',
        'drag_mode'      => 'وضع الترتيب',
        'exit_drag'      => 'خروج',
        'year_label'     => 'السنة',
        'delete_year'    => 'حذف السنة',
        'delete_confirm' => 'هل أنت متأكد من حذف سعر هذه السنة؟',
        'delete_success' => 'تم الحذف بنجاح',
        'accuracy_note' => '⚡ يتم تحديث هذه الصفحة بشكل يومي وقد تطرأ تغييرات أو عروض جديدة خلال اليوم. يجب مراجعة الأسعار قبل التعامل مع أي عميل. الإدارة غير مسؤولة عن أي أسعار أو معلومات غير صحيحة ناتجة عن عدم مراجعة الصفحة أو الاعتماد على بيانات قديمة.',
        'last_checked'   => 'آخر فحص تلقائي',
        /* ── NEW: discount / offer picker ── */
        'choose_type'    => 'اختر النوع',
        'type_discount'  => 'خصم',
        'type_offer'     => 'أوفر',
        'pick_amount'    => 'اختر القيمة',
        'type_official'  => 'رسمي',
        'discount'       => 'خصم',
        'offer'          => 'أوفر',
    ],
    'en' => [
        'title'          => 'Pricing Management',
        'subtitle'       => 'Models & Trim Pricing',
        'dashboard'      => 'Dashboard',
        'inventory'      => 'Inventory',
        'stock_report'   => 'Stock Report',
        'price_history'  => 'Price History',
        'brand'          => 'Brand',
        'model'          => 'Model',
        'trim'           => 'Trim',
        'year'           => 'Year',
        'official_price' => 'Official Price',
        'customer_price' => 'Customer Price',
        'trade_price'    => 'Trade Price',
        'notes'          => 'Notes',
        'notes_ph'       => 'Add a note...',
        'notes_optional' => 'optional',
        'last_updated'   => 'Last Updated',
        'updated_by'     => 'Updated By',
        'save'           => 'Save',
        'edit'           => 'Edit',
        'cancel'         => 'Cancel',
        'add_pricing'    => 'Add Pricing',
        'mgmt_only'      => 'Management Only',
        'sales_note'     => 'View only — editing is available for managers and admins.',
        'last_update_label' => 'Last Pricing Update',
        'no_price'       => 'Not Set',
        'not_set'        => '—',
        'all_brands'     => 'All Brands',
        'filter'         => 'Filter',
        'search_ph'      => 'Search by model or trim...',
        'total_models'   => 'Total Models',
        'priced'         => 'Priced',
        'unpriced'       => 'Unpriced',
        'currency'       => 'EGP',
        'required'       => 'Required',
        'optional'       => 'Optional',
        'year_required'  => 'Pick a year (required)',
        'year2_optional' => 'Second year (optional — duplicates row)',
        'trade_locked'   => 'Restricted',
        'sort_hint'      => 'Long-press to reorder rows',
        'save_order'     => 'Save Order',
        'saving_order'   => 'Saving...',
        'order_saved'    => 'Order Saved',
        'drag_mode'      => 'Reorder Mode',
        'exit_drag'      => 'Exit',
        'year_label'     => 'Year',
        'delete_year'    => 'Delete Year',
        'delete_confirm' => 'Are you sure you want to delete pricing for this year?',
        'delete_success' => 'Deleted successfully',
        'accuracy_note'  => '⚡ This page auto-refreshes daily to ensure accurate information — management is not responsible for any changes after the last update.',
        'last_checked'   => 'Last auto-check',
        /* ── NEW: discount / offer picker ── */
        'choose_type'    => 'Choose type',
        'type_discount'  => 'Discount',
        'type_offer'     => 'Offer',
        'pick_amount'    => 'Pick amount',
        'type_official'  => 'Official',
        'discount'       => 'Discount',
        'offer'          => 'Offer',
    ],
];

/* ─── Handle AJAX Save (pricing row) ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save' && $canEdit) {
    header('Content-Type: application/json');

    $brand          = trim($_POST['brand']          ?? '');
    $model_name     = trim($_POST['model_name']     ?? '');
    $trim_name      = trim($_POST['trim_name']      ?? '');
    $car_year       = trim($_POST['car_year']       ?? '');
    $official_price = trim($_POST['official_price'] ?? '');
    $customer_price = trim($_POST['customer_price'] ?? '') ?: null;
    $trade_price    = trim($_POST['trade_price']    ?? '') ?: null;
    $notes          = trim($_POST['notes']          ?? '') ?: null;
    $updated_by     = $_SESSION['username'];

    if (!$brand || !$model_name || !$trim_name || !$official_price || !$car_year) {
        echo json_encode(['ok' => false, 'msg' => 'Missing required fields']);
        exit;
    }

    /* ── Price change history: snapshot the current values first ──
       (table auto-creates itself; logging can never break saving) */
    $histOld = false;
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
        $oldStmt = $pdo->prepare("SELECT official_price, customer_price, trade_price
                                   FROM pricing
                                   WHERE brand = ? AND model_name = ? AND trim_name = ? AND car_year = ?
                                   LIMIT 1");
        $oldStmt->execute([$brand, $model_name, $trim_name, $car_year]);
        $histOld = $oldStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { error_log('pricing_history read failed: ' . $e->getMessage()); }

    $stmt = $pdo->prepare("
        INSERT INTO pricing (brand, model_name, trim_name, car_year, official_price, customer_price, trade_price, notes, updated_by, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            official_price = VALUES(official_price),
            customer_price = VALUES(customer_price),
            trade_price    = VALUES(trade_price),
            notes          = VALUES(notes),
            updated_by     = VALUES(updated_by),
            updated_at     = NOW()
    ");
    $stmt->execute([$brand, $model_name, $trim_name, $car_year, $official_price, $customer_price, $trade_price, $notes, $updated_by]);

    /* ── Log the change (create / update, only when something moved) ── */
    try {
        $newVals = ['official' => (string)$official_price,
                    'customer' => (string)($customer_price ?? ''),
                    'trade'    => (string)($trade_price    ?? '')];
        if ($histOld === false || $histOld === null) {
            $changed = true; $type = 'create';
            $oldVals = ['official' => null, 'customer' => null, 'trade' => null];
        } else {
            $oldVals = ['official' => (string)($histOld['official_price'] ?? ''),
                        'customer' => (string)($histOld['customer_price'] ?? ''),
                        'trade'    => (string)($histOld['trade_price']    ?? '')];
            $changed = ($oldVals['official'] !== $newVals['official'])
                    || ($oldVals['customer'] !== $newVals['customer'])
                    || ($oldVals['trade']    !== $newVals['trade']);
            $type = 'update';
        }
        if ($changed) {
            $pdo->prepare("INSERT INTO pricing_history
                (brand, model_name, trim_name, car_year, change_type,
                 old_official, new_official, old_customer, new_customer, old_trade, new_trade, changed_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$brand, $model_name, $trim_name, $car_year, $type,
                           $oldVals['official'], $newVals['official'],
                           $oldVals['customer'], $newVals['customer'],
                           $oldVals['trade'],    $newVals['trade'],
                           $updated_by]);
        }
    } catch (Exception $e) { error_log('pricing_history write failed: ' . $e->getMessage()); }

    echo json_encode(['ok' => true, 'updated_by' => $updated_by, 'updated_at' => date('d M Y h:i A'), 'car_year' => $car_year]);
    exit;
}

/* ─── Handle AJAX Save Order ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_order' && $canEdit) {
    header('Content-Type: application/json');
    $order = json_decode($_POST['order'] ?? '[]', true);
    if (!is_array($order)) {
        echo json_encode(['ok' => false]);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE models SET sort_order = ? WHERE brand = ? AND model_name = ? AND trim_name = ?");
    foreach ($order as $idx => $key) {
        [$b, $m, $tr] = explode('|||', $key, 3);
        $stmt->execute([$idx, $b, $m, $tr]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

/* ─── Handle AJAX Delete Year Row ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_year' && $canEdit) {
    header('Content-Type: application/json');

    $brand      = trim($_POST['brand']      ?? '');
    $model_name = trim($_POST['model_name'] ?? '');
    $trim_name  = trim($_POST['trim_name']  ?? '');
    $car_year   = trim($_POST['car_year']   ?? '');

    if (!$brand || !$model_name || !$trim_name || !$car_year) {
        echo json_encode(['ok' => false, 'msg' => 'Missing fields']);
        exit;
    }

    /* ── Price change history: log the deletion with its last values ── */
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
        $oldStmt = $pdo->prepare("SELECT official_price, customer_price, trade_price
                                   FROM pricing
                                   WHERE brand = ? AND model_name = ? AND trim_name = ? AND car_year = ?
                                   LIMIT 1");
        $oldStmt->execute([$brand, $model_name, $trim_name, $car_year]);
        $histOld = $oldStmt->fetch(PDO::FETCH_ASSOC);
        if ($histOld) {
            $pdo->prepare("INSERT INTO pricing_history
                (brand, model_name, trim_name, car_year, change_type,
                 old_official, new_official, old_customer, new_customer, old_trade, new_trade, changed_by)
                VALUES (?, ?, ?, ?, 'delete', ?, NULL, ?, NULL, ?, NULL, ?)")
                ->execute([$brand, $model_name, $trim_name, $car_year,
                           (string)($histOld['official_price'] ?? ''),
                           (string)($histOld['customer_price'] ?? ''),
                           (string)($histOld['trade_price']    ?? ''),
                           $_SESSION['username'] ?? '']);
        }
    } catch (Exception $e) { error_log('pricing_history delete-log failed: ' . $e->getMessage()); }

    $stmt = $pdo->prepare("DELETE FROM pricing WHERE brand = ? AND model_name = ? AND trim_name = ? AND car_year = ?");
    $stmt->execute([$brand, $model_name, $trim_name, $car_year]);

    echo json_encode(['ok' => true]);
    exit;
}

/* ─── Filters ─── */
$filterBrand  = trim($_GET['brand']  ?? '');
$filterSearch = trim($_GET['search'] ?? '');

/* ─── Load models grouped by brand ─── */
$mWhere  = ["m.active = 1"];
$mParams = [];
if ($filterBrand !== '') { $mWhere[] = "m.brand = ?"; $mParams[] = $filterBrand; }
if ($filterSearch !== '') {
    $mWhere[] = "(m.model_name LIKE ? OR m.trim_name LIKE ?)";
    $mParams[] = "%$filterSearch%";
    $mParams[] = "%$filterSearch%";
}

$mSQL = "
    SELECT m.brand, m.model_name, m.trim_name,
           m.sort_order,
           p.car_year, p.official_price, p.customer_price, p.trade_price, p.notes,
           p.updated_by, p.updated_at
    FROM models m
    LEFT JOIN pricing p
           ON p.brand      = m.brand
          AND p.model_name = m.model_name
          AND p.trim_name  = m.trim_name
    WHERE " . implode(' AND ', $mWhere) . "
    ORDER BY m.brand, COALESCE(m.sort_order, 9999), m.model_name, m.trim_name, p.car_year DESC
";
$mStmt = $pdo->prepare($mSQL);
$mStmt->execute($mParams);
$rawRows = $mStmt->fetchAll(PDO::FETCH_ASSOC);

$trimMap   = [];
$trimOrder = [];

foreach ($rawRows as $r) {
    $bk  = $r['brand'];
    $key = $r['brand'] . '|||' . $r['model_name'] . '|||' . $r['trim_name'];

    if (!isset($trimMap[$bk])) $trimMap[$bk] = [];

    if (!isset($trimMap[$bk][$key])) {
        $trimMap[$bk][$key] = [
            'brand'      => $r['brand'],
            'model_name' => $r['model_name'],
            'trim_name'  => $r['trim_name'],
            'sort_order' => $r['sort_order'],
            'years'      => [],
        ];
        if (!isset($trimOrder[$bk])) $trimOrder[$bk] = [];
        $trimOrder[$bk][] = $key;
    }

    if ($r['car_year'] !== null) {
        $trimMap[$bk][$key]['years'][$r['car_year']] = $r;
    }
}

$totalModels = 0;
$priced      = 0;
foreach ($trimMap as $bk => $trims) {
    foreach ($trims as $key => $trim) {
        $totalModels++;
        if (!empty($trim['years'])) $priced++;
    }
}
$unpriced = $totalModels - $priced;

$lastUpdateRow = $pdo->query("SELECT updated_by, updated_at FROM pricing ORDER BY updated_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$brands = $pdo->query("SELECT DISTINCT brand FROM models WHERE active=1 ORDER BY brand")->fetchAll(PDO::FETCH_COLUMN);

$currentYear = (int)date('Y');
$yearOptions = [];
for ($y = $currentYear + 2; $y >= 2015; $y--) {
    $yearOptions[] = $y;
}

/* ── NEW: discount / offer amount steps (1,000 → 300,000) ── */
$offerSteps = [];
for ($v = 1000; $v <= 300000; $v += 1000) { $offerSteps[] = $v; }

function fmt($val, $currency) {
    if ($val === null || $val === '') return null;
    return number_format((float)$val, 0, '.', ',') . ' ' . $currency;
}

/* For customer/trade price: stored as text labels like "أوفر 10,000" — show as-is */
function fmtLabel($val) {
    if ($val === null || $val === '') return null;
    return htmlspecialchars($val);
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $t[$lang]['title'] ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root {
    --bg-deep:    #020617;
    --bg-card:    rgba(15,23,42,.92);
    --border:     rgba(255,255,255,.07);
    --green:      #22c55e;
    --green-dim:  rgba(34,197,94,.12);
    --purple:     #9333ea;
    --purple-dim: rgba(147,51,234,.12);
    --blue:       #2563eb;
    --amber:      #f59e0b;
    --amber-dim:  rgba(245,158,11,.12);
    --red:        #ef4444;
    --text:       #f1f5f9;
    --muted:      #64748b;
    --muted-l:    #94a3b8;
    --r-card:     20px;
    --r-btn:      12px;
    --r-input:    10px;
    --shadow:     0 4px 24px rgba(0,0,0,.4);
    --font-ar:    'Cairo', sans-serif;
    --font-en:    'Inter', sans-serif;
}
html[lang="ar"] body { font-family: var(--font-ar); }
html[lang="en"] body { font-family: var(--font-en); }

body {
    background: linear-gradient(150deg,#020617 0%,#0a0f1e 50%,#05101f 100%);
    color: var(--text);
    min-height: 100vh;
    padding-bottom: 80px;
}

.wrap { max-width: 1600px; margin: auto; padding: 16px 20px; }

.header {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--r-card);
    padding: 20px 24px;
    margin-bottom: 18px;
    backdrop-filter: blur(20px);
    box-shadow: var(--shadow);
}
.header-top { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; }
.page-title {
    font-size: 28px; font-weight: 900;
    background: linear-gradient(90deg, var(--amber), #fbbf24);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.page-subtitle { font-size: 13px; color: var(--muted-l); margin-top: 4px; }
.nav-group { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.nav-btn {
    text-decoration:none; padding:9px 16px; border-radius:var(--r-btn);
    font-weight:700; font-size:13px; color:white;
    transition:transform .2s, box-shadow .2s;
    display:flex; align-items:center; gap:6px;
}
.nav-btn:hover { transform:translateY(-2px); }
.nav-btn.dash   { background:var(--blue); }
.nav-btn.inv    { background:var(--green); color:#002b14; }
.nav-btn.report { background:var(--purple); }
.lang-row { display:flex; gap:8px; margin-top:14px; }
.lang-btn {
    text-decoration:none; padding:7px 14px; border-radius:10px;
    background:#111827; color:var(--muted-l); font-size:12px; font-weight:700;
    border:1px solid var(--border); transition:background .2s;
}
.lang-btn.active { background:var(--purple); color:white; border-color:transparent; }

.update-banner {
    background: linear-gradient(90deg, rgba(245,158,11,.08), rgba(147,51,234,.08));
    border: 1px solid rgba(245,158,11,.22);
    border-radius: var(--r-card);
    padding: 14px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}
.update-banner .ub-label { font-size: 12px; color: var(--muted-l); font-weight: 700; text-transform: uppercase; letter-spacing: .06em; }
.update-banner .ub-val { font-size: 15px; font-weight: 800; color: var(--amber); margin-top: 3px; }
.sales-badge {
    background: rgba(239,68,68,.1);
    border: 1px solid rgba(239,68,68,.25);
    color: #fca5a5;
    border-radius: 50px;
    padding: 6px 16px;
    font-size: 12px;
    font-weight: 700;
}

.accuracy-banner {
    background: linear-gradient(90deg, rgba(34,197,94,.07), rgba(37,99,235,.07));
    border: 1px solid rgba(34,197,94,.2);
    border-radius: var(--r-card);
    padding: 12px 22px;
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
}
.accuracy-banner .ab-note {
    font-size: 12.5px;
    color: #86efac;
    font-weight: 700;
    line-height: 1.5;
}
.accuracy-banner .ab-checked {
    font-size: 11px;
    color: var(--muted);
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: 6px;
}
.accuracy-banner .ab-checked strong { color: var(--muted-l); }

.stats-row { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:18px; }
.stat-card {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:var(--r-card); padding:20px 18px; text-align:center;
    box-shadow:var(--shadow); position:relative; overflow:hidden;
}
.stat-card::after { content:''; position:absolute; bottom:0; left:0; right:0; height:3px; }
.stat-card.c-total::after  { background:linear-gradient(90deg,var(--amber),#fbbf24); }
.stat-card.c-priced::after { background:linear-gradient(90deg,var(--green),#86efac); }
.stat-card.c-unpriced::after { background:linear-gradient(90deg,var(--red),#fca5a5); }
.stat-label { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); font-weight:700; }
.stat-number { font-size:44px; font-weight:900; margin-top:6px; line-height:1; }
.c-total    .stat-number { color:var(--amber); }
.c-priced   .stat-number { color:var(--green); }
.c-unpriced .stat-number { color:var(--red); }

.filters-card {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:var(--r-card); padding:18px 22px; margin-bottom:18px;
    box-shadow:var(--shadow);
}
.filter-grid { display:grid; grid-template-columns:2fr 1fr auto; gap:10px; align-items:end; }
.filter-group { display:flex; flex-direction:column; gap:5px; }
.filter-label { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; }
input[type="text"], select {
    width:100%; height:46px;
    border:1px solid rgba(255,255,255,.08); outline:none;
    background:#0d1526; color:var(--text);
    padding:0 14px; border-radius:var(--r-input);
    font-size:14px; font-family:inherit;
    transition:border-color .2s, box-shadow .2s;
    -webkit-appearance:none; appearance:none;
}
input:focus, select:focus { border-color:var(--amber); box-shadow:0 0 0 3px rgba(245,158,11,.12); }
select { cursor:pointer; }
select option { background:#0d1526; }
.btn-filter {
    height:46px; padding:0 22px; border:none; border-radius:var(--r-btn);
    font-weight:800; font-size:14px; cursor:pointer;
    background:linear-gradient(90deg,var(--amber),#d97706);
    color:#1a0800; font-family:inherit;
    transition:transform .2s, box-shadow .2s;
}
.btn-filter:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(245,158,11,.3); }

.table-card {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:var(--r-card); padding:24px; box-shadow:var(--shadow);
}

.drag-toolbar {
    display: none;
    align-items: center;
    gap: 12px;
    background: rgba(147,51,234,.12);
    border: 1px solid rgba(147,51,234,.3);
    border-radius: 14px;
    padding: 12px 18px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.drag-toolbar.visible { display: flex; }
.drag-toolbar-label {
    font-size: 13px; font-weight: 800; color: var(--purple);
    display: flex; align-items: center; gap: 6px;
}
.btn-save-order {
    margin-inline-start: auto;
    border: none; cursor: pointer;
    padding: 8px 20px; border-radius: 10px;
    font-size: 13px; font-weight: 800; font-family: inherit;
    background: var(--green); color: #001a08;
    transition: opacity .2s;
}
.btn-save-order:disabled { opacity: .5; cursor: not-allowed; }
.btn-exit-drag {
    border: none; cursor: pointer;
    padding: 8px 16px; border-radius: 10px;
    font-size: 13px; font-weight: 800; font-family: inherit;
    background: rgba(239,68,68,.12); color: #fca5a5;
    border: 1px solid rgba(239,68,68,.25);
}

.brand-block { margin-bottom: 32px; }
.brand-divider {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 14px;
    padding: 13px 18px;
    border-radius: 14px;
    background: linear-gradient(90deg, rgba(245,158,11,.10) 0%, rgba(147,51,234,.07) 100%);
    border: 1px solid rgba(245,158,11,.18);
    position: relative;
    overflow: hidden;
}
.brand-divider::before {
    content:'';
    position:absolute; left:0; top:0; bottom:0; width:4px;
    background:linear-gradient(180deg, var(--amber), var(--purple));
    border-radius:4px 0 0 4px;
}
html[dir="rtl"] .brand-divider::before { left:auto; right:0; border-radius:0 4px 4px 0; }
.brand-divider .brand-logo-placeholder {
    width:36px; height:36px; border-radius:10px;
    background:linear-gradient(135deg,var(--amber),var(--purple));
    display:flex; align-items:center; justify-content:center;
    font-size:14px; font-weight:900; color:white;
    flex-shrink:0; letter-spacing:-.02em;
}
.brand-name-big { font-size:19px; font-weight:900; color:var(--amber); letter-spacing:.04em; text-transform:uppercase; }
.brand-count-pill {
    margin-inline-start:auto;
    background:var(--amber-dim); color:var(--amber);
    padding:4px 14px; border-radius:50px;
    font-size:12px; font-weight:800;
    border:1px solid rgba(245,158,11,.22);
}
.brand-priced-pill {
    background:var(--green-dim); color:var(--green);
    padding:4px 14px; border-radius:50px;
    font-size:12px; font-weight:800;
    border:1px solid rgba(34,197,94,.22);
}

.pricing-table { width:100%; border-collapse:collapse; }
.pricing-table thead tr { background:#0a1120; }
.pricing-table th {
    padding:11px 14px; text-align:center;
    font-size:11px; font-weight:700;
    text-transform:uppercase; letter-spacing:.05em; color:var(--muted);
    white-space:nowrap;
}
.pricing-table th:first-child { text-align: start; }
.pricing-table td {
    padding:10px 14px; text-align:center;
    border-bottom:1px solid rgba(255,255,255,.04);
    font-size:13.5px;
    vertical-align: middle;
}
.pricing-table td:first-child { text-align:start; }
.pricing-table tbody tr { transition:background .15s; }
.pricing-table tbody tr:hover { background:rgba(245,158,11,.03); }
.pricing-table tbody tr:last-child td { border-bottom:none; }

.pricing-table tbody tr.year-sub td:first-child {
    padding-inline-start: 28px;
    border-inline-start: 3px solid rgba(147,51,234,.3);
}
.pricing-table tbody tr.year-sub { background: rgba(147,51,234,.03); }

.model-cell { font-weight:700; color:#e2e8f0; }
.trim-cell  { color:var(--muted-l); font-size:13px; }
.year-badge {
    display: inline-flex; align-items: center; gap: 4px;
    background: rgba(147,51,234,.15);
    color: #c084fc;
    border: 1px solid rgba(147,51,234,.3);
    border-radius: 20px;
    padding: 3px 10px;
    font-size: 12px; font-weight: 800;
}
.price-official { font-weight:800; color:var(--green); }
.price-customer { font-weight:700; color:#60a5fa; }
.price-trade    { font-weight:700; color:#c084fc; }
.price-empty    { color:var(--muted); font-size:12px; }

/* ── NEW: offer / discount badge in display cells ── */
.deal-badge {
    display:inline-flex; align-items:center; gap:5px;
    border-radius:20px; padding:4px 12px;
    font-size:12.5px; font-weight:800; white-space:nowrap;
}
.deal-offer    { background:rgba(34,197,94,.12);  color:#4ade80; border:1px solid rgba(34,197,94,.3); }
.deal-discount { background:rgba(239,68,68,.12);  color:#f87171; border:1px solid rgba(239,68,68,.3); }

/* ── NEW: the discount/offer picker UI inside the edit cell ── */
.deal-picker { display:flex; flex-direction:column; gap:6px; align-items:center; }
.deal-type-row { display:flex; gap:6px; }
.deal-type-btn {
    border:1px solid rgba(255,255,255,.12); cursor:pointer;
    padding:6px 12px; border-radius:9px;
    font-size:12px; font-weight:800; font-family:inherit;
    background:#111827; color:var(--muted-l);
    transition:.15s;
}
.deal-type-btn.sel-offer    { background:rgba(34,197,94,.18); color:#4ade80; border-color:rgba(34,197,94,.4); }
.deal-type-btn.sel-discount { background:rgba(239,68,68,.18); color:#f87171; border-color:rgba(239,68,68,.4); }
.deal-type-btn.sel-official { background:rgba(34,197,94,.22); color:#4ade80; border-color:rgba(34,197,94,.5); }
.deal-amount-sel {
    width:150px; height:36px; font-size:13px;
    padding:0 10px; border-radius:8px;
    border:1px solid rgba(255,255,255,.1);
    background:#111827; color:var(--text); font-family:inherit;
}
.deal-clear {
    background:none; border:none; cursor:pointer;
    color:var(--muted); font-size:11px; font-weight:700;
    text-decoration:underline; font-family:inherit;
}
.deal-clear:hover { color:#fca5a5; }

.trade-col-sales {
    display: inline-flex; align-items: center; gap: 4px;
    background: rgba(239,68,68,.08);
    color: rgba(239,68,68,.4);
    border: 1px solid rgba(239,68,68,.15);
    border-radius: 20px;
    padding: 3px 10px;
    font-size: 11px; font-weight: 700;
    letter-spacing: .03em;
}

.row-note {
    display: inline-block;
    max-width: 180px;
    background: rgba(245,158,11,.08);
    border: 1px solid rgba(245,158,11,.18);
    color: #fcd34d;
    border-radius: 8px;
    padding: 4px 10px;
    font-size: 12px;
    font-weight: 600;
    white-space: pre-wrap;
    word-break: break-word;
    text-align: start;
}
.p-textarea {
    font-family: inherit;
    line-height: 1.5;
    border-radius: 8px;
    background: #111827;
    color: var(--text);
    border: 1px solid rgba(255,255,255,.1);
    outline: none;
    transition: border-color .2s;
    resize: vertical;
}
.p-textarea:focus { border-color: var(--amber); }

.update-meta {
    font-size:11px; color:var(--muted);
    display:flex; flex-direction:column; align-items:center; gap:2px;
}
.update-meta .meta-by { font-weight:700; color:var(--muted-l); }

.price-display { display:flex; flex-direction:column; align-items:center; gap:2px; }
.price-input-wrap { display:none; }
.price-input-wrap.active { display:block; }
.price-display.hidden { display:none; }

.year-select-wrap {
    display: flex; flex-direction: column; gap: 6px; align-items: center;
}
.year-select-wrap select {
    width: 130px; height: 36px; font-size: 13px;
    padding: 0 10px; border-radius: 8px;
}
.year-hint { font-size: 10px; color: var(--muted); margin-top: 2px; }

.p-input {
    width:120px; height:34px;
    border:1px solid rgba(255,255,255,.1); outline:none;
    background:#111827; color:var(--text);
    padding:0 10px; border-radius:8px;
    font-size:13px; font-family:inherit;
    text-align:center;
    transition:border-color .2s;
}
.p-input:focus { border-color:var(--amber); }

.btn-edit {
    border:none; cursor:pointer;
    padding:6px 14px; border-radius:8px;
    font-size:12px; font-weight:700; font-family:inherit;
    background:var(--amber-dim); color:var(--amber);
    border:1px solid rgba(245,158,11,.22);
    transition:background .2s;
}
.btn-edit:hover { background:rgba(245,158,11,.22); }

.btn-save {
    border:none; cursor:pointer;
    padding:6px 14px; border-radius:8px;
    font-size:12px; font-weight:700; font-family:inherit;
    background:var(--green-dim); color:var(--green);
    border:1px solid rgba(34,197,94,.25);
    transition:background .2s;
}
.btn-save:hover { background:rgba(34,197,94,.22); }
.btn-save.saving { opacity:.6; cursor:not-allowed; }

.btn-cancel {
    border:none; cursor:pointer;
    padding:6px 10px; border-radius:8px;
    font-size:12px; font-weight:700; font-family:inherit;
    background:rgba(239,68,68,.08); color:#fca5a5;
    border:1px solid rgba(239,68,68,.2);
}

.btn-delete-year {
    border:none; cursor:pointer;
    padding:5px 11px; border-radius:8px;
    font-size:11px; font-weight:700; font-family:inherit;
    background:rgba(239,68,68,.10); color:#fca5a5;
    border:1px solid rgba(239,68,68,.22);
    transition:background .2s, transform .15s;
    display: inline-flex; align-items: center; gap: 4px;
}
.btn-delete-year:hover {
    background:rgba(239,68,68,.22);
    transform: translateY(-1px);
}

.btn-disabled {
    border:none; padding:6px 14px; border-radius:8px;
    font-size:12px; font-weight:700;
    background:#1e2536; color:#3d4d63;
    cursor:not-allowed; opacity:.7;
}

.drag-handle {
    display: none;
    cursor: grab;
    color: var(--muted);
    font-size: 18px;
    padding: 4px 8px;
    border-radius: 8px;
    background: rgba(255,255,255,.04);
    user-select: none;
    touch-action: none;
    transition: background .15s, color .15s;
}
.drag-handle:hover { background: rgba(147,51,234,.15); color: var(--purple); }
.drag-handle:active { cursor: grabbing; }
.drag-mode-active .drag-handle { display: inline-flex; align-items: center; }
.drag-mode-active .btn-edit { display: none; }
.drag-mode-active .action-cell-wrap { justify-content: center; }

tr.dragging {
    opacity: 0.4;
    background: rgba(147,51,234,.1) !important;
}
tr.drag-over-top td { border-top: 2px solid var(--purple) !important; }
tr.drag-over-bottom td { border-bottom: 2px solid var(--purple) !important; }

.action-cell-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
}

.toast {
    position:fixed; bottom:30px;
    left: 50%; transform:translateX(-50%);
    background:#0f2710; border:1px solid rgba(34,197,94,.4);
    color:var(--green); padding:12px 28px; border-radius:50px;
    font-size:14px; font-weight:700;
    opacity:0; pointer-events:none;
    transition:opacity .3s;
    z-index:9999; white-space:nowrap;
    box-shadow:0 8px 32px rgba(34,197,94,.2);
}
.toast.show { opacity:1; }

@media(max-width:900px) {
    .stats-row { grid-template-columns:1fr; }
    .filter-grid { grid-template-columns:1fr; }
    .pricing-table { display:block; overflow-x:auto; white-space:nowrap; }
    .header-top { flex-direction:column; align-items:flex-start; }
}
@media(max-width:600px) {
    .page-title { font-size:22px; }
    .stat-number { font-size:36px; }
}
</style>
</head>
<body>
<div class="wrap">

<!-- ── HEADER ─────────────────────────────── -->
<div class="header">
    <div class="header-top">
        <div>
            <div class="page-title">💰 <?= $t[$lang]['title'] ?></div>
            <div class="page-subtitle"><?= $t[$lang]['subtitle'] ?></div>
        </div>
        <div class="nav-group">
            <a href="dashboard.php?lang=<?= $lang ?>"    class="nav-btn dash">🏠 <?= $t[$lang]['dashboard'] ?></a>
            <a href="stock_report.php?lang=<?= $lang ?>"    class="nav-btn inv">🚗 <?= $t[$lang]['inventory'] ?></a>
            <a href="stock_report.php?lang=<?= $lang ?>" class="nav-btn report">📄 <?= $t[$lang]['stock_report'] ?></a>
            <?php if (can('page.price_history')): ?>
            <a href="price_history.php?lang=<?= $lang ?>" class="nav-btn report">📈 <?= $t[$lang]['price_history'] ?></a>
            <?php endif; ?>
        </div>
    </div>
    <div class="lang-row">
        <a href="?lang=ar&brand=<?= urlencode($filterBrand) ?>&search=<?= urlencode($filterSearch) ?>" class="lang-btn <?= $lang==='ar'?'active':'' ?>">🇪🇬 العربية</a>
        <a href="?lang=en&brand=<?= urlencode($filterBrand) ?>&search=<?= urlencode($filterSearch) ?>" class="lang-btn <?= $lang==='en'?'active':'' ?>">🇺🇸 English</a>
    </div>
</div>

<!-- ── LAST UPDATE BANNER ─────────────────── -->
<div class="update-banner">
    <div>
        <div class="ub-label">⏱ <?= $t[$lang]['last_update_label'] ?></div>
        <div class="ub-val" id="lastUpdateDisplay">
            <?php if ($lastUpdateRow): ?>
                <?= htmlspecialchars($lastUpdateRow['updated_by']) ?>
                &nbsp;·&nbsp;
                <?= date('d M Y h:i A', strtotime($lastUpdateRow['updated_at'])) ?>
            <?php else: ?>
                <?= $t[$lang]['not_set'] ?>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($isSales): ?>
    <div class="sales-badge">🔒 <?= $t[$lang]['sales_note'] ?></div>
    <?php endif; ?>
</div>

<!-- ── ACCURACY / AUTO-REFRESH BANNER ── -->
<div class="accuracy-banner">
    <div class="ab-note"><?= $t[$lang]['accuracy_note'] ?></div>
    <div class="ab-checked">
        🕐 <?= $t[$lang]['last_checked'] ?>:
        <strong id="lastCheckedTime"><?= date('d M Y h:i A') ?></strong>
    </div>
</div>

<!-- ── STATS ──────────────────────────────── -->
<div class="stats-row">
    <div class="stat-card c-total">
        <div class="stat-label"><?= $t[$lang]['total_models'] ?></div>
        <div class="stat-number"><?= $totalModels ?></div>
    </div>
    <div class="stat-card c-priced">
        <div class="stat-label"><?= $t[$lang]['priced'] ?></div>
        <div class="stat-number"><?= $priced ?></div>
    </div>
    <div class="stat-card c-unpriced">
        <div class="stat-label"><?= $t[$lang]['unpriced'] ?></div>
        <div class="stat-number"><?= $unpriced ?></div>
    </div>
</div>

<!-- ── FILTERS ────────────────────────────── -->
<form method="GET" class="filters-card">
    <input type="hidden" name="lang" value="<?= $lang ?>">
    <div class="filter-grid">
        <div class="filter-group">
            <span class="filter-label">🔍 <?= $t[$lang]['search_ph'] ?></span>
            <input type="text" name="search" placeholder="<?= $t[$lang]['search_ph'] ?>" value="<?= htmlspecialchars($filterSearch) ?>">
        </div>
        <div class="filter-group">
            <span class="filter-label">🚘 <?= $t[$lang]['brand'] ?></span>
            <select name="brand">
                <option value=""><?= $t[$lang]['all_brands'] ?></option>
                <?php foreach ($brands as $b): ?>
                <option value="<?= htmlspecialchars($b) ?>" <?= $filterBrand===$b?'selected':'' ?>><?= htmlspecialchars($b) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-filter">🔍 <?= $t[$lang]['filter'] ?></button>
    </div>
</form>

<!-- ── PRICING TABLE ──────────────────────── -->
<div class="table-card">

<?php if ($canEdit): ?>
<div class="drag-toolbar" id="dragToolbar">
    <div class="drag-toolbar-label">
        ↕️ <?= $t[$lang]['drag_mode'] ?>
        <span style="font-size:11px;font-weight:600;color:var(--muted);margin-inline-start:6px;"><?= $t[$lang]['sort_hint'] ?></span>
    </div>
    <button class="btn-exit-drag" onclick="exitDragMode()">✕ <?= $t[$lang]['exit_drag'] ?></button>
    <button class="btn-save-order" id="btnSaveOrder" onclick="saveOrder()">💾 <?= $t[$lang]['save_order'] ?></button>
</div>
<?php endif; ?>

<?php if (empty($trimMap)): ?>
    <div style="text-align:center;padding:60px;color:var(--muted);font-size:16px;">
        🚘 <?= $lang==='ar'?'لا توجد موديلات':'No models found' ?>
    </div>
<?php else: ?>

<?php
/* ── Helper: render the discount/offer picker for a customer/trade cell ──
   $kind = 'cust' or 'trade'; $yRowId = row id; $current = existing label text */
function dealPicker($kind, $yRowId, $current, $offerSteps, $t, $lang) {
    ob_start();
    ?>
    <div class="deal-picker">
        <div class="deal-type-row">
            <button type="button" class="deal-type-btn" data-kind="<?= $kind ?>" data-row="<?= $yRowId ?>" data-type="offer"
                    onclick="setDealType('<?= $kind ?>','<?= $yRowId ?>','offer')">
                ⬆️ <?= $t[$lang]['type_offer'] ?>
            </button>
            <button type="button" class="deal-type-btn" data-kind="<?= $kind ?>" data-row="<?= $yRowId ?>" data-type="discount"
                    onclick="setDealType('<?= $kind ?>','<?= $yRowId ?>','discount')">
                ⬇️ <?= $t[$lang]['type_discount'] ?>
            </button>
            <button type="button" class="deal-type-btn deal-type-official" data-kind="<?= $kind ?>" data-row="<?= $yRowId ?>" data-type="official"
                    onclick="setOfficial('<?= $kind ?>','<?= $yRowId ?>')">
                📢 <?= $t[$lang]['type_official'] ?>
            </button>
        </div>
        <select class="deal-amount-sel" id="deal-amt-<?= $kind ?>-<?= $yRowId ?>" disabled>
            <option value=""><?= $t[$lang]['pick_amount'] ?></option>
            <?php foreach ($offerSteps as $amt): ?>
            <option value="<?= $amt ?>"><?= number_format($amt, 0, '.', ',') ?></option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" id="deal-type-<?= $kind ?>-<?= $yRowId ?>" value="">
        <input type="hidden" id="deal-val-<?= $kind ?>-<?= $yRowId ?>" value="<?= htmlspecialchars($current ?? '') ?>">
        <button type="button" class="deal-clear" onclick="clearDeal('<?= $kind ?>','<?= $yRowId ?>')">✕ <?= $t[$lang]['cancel'] ?></button>
    </div>
    <?php
    return ob_get_clean();
}

/* ── Helper: render a deal value as a colored badge for display ── */
function dealBadge($val, $t, $lang) {
    if ($val === null || $val === '') {
        return '<span class="price-empty">' . $t[$lang]['not_set'] . '</span>';
    }
    $offerWord = $t[$lang]['offer'];
    $discWord  = $t[$lang]['discount'];
    $offWord   = $t[$lang]['type_official'];
    $isOfficial = (mb_strpos($val, $offWord) !== false) || (stripos($val, 'Official') !== false) || (mb_strpos($val, 'رسمي') !== false);
    $isOffer = (mb_strpos($val, $offerWord) !== false) || (stripos($val, 'Offer') !== false);
    $isDisc  = (mb_strpos($val, $discWord)  !== false) || (stripos($val, 'Discount')  !== false);
    if ($isOfficial)   { $cls = 'deal-offer';    $icon = '📢'; }
    elseif ($isOffer)  { $cls = 'deal-offer';    $icon = '⬆️'; }
    elseif ($isDisc)   { $cls = 'deal-discount'; $icon = '⬇️'; }
    else               { $cls = 'deal-offer';    $icon = '';   }
    return '<span class="deal-badge ' . $cls . '">' . $icon . ' ' . htmlspecialchars($val) . '</span>';
}
?>

<?php foreach ($trimMap as $brandName => $trims):
    $bPriced = count(array_filter($trims, fn($t2) => !empty($t2['years'])));
    $bInitial = mb_substr($brandName, 0, 2, 'UTF-8');
?>
    <div class="brand-block" data-brand="<?= htmlspecialchars($brandName) ?>">

        <div class="brand-divider">
            <div class="brand-logo-placeholder"><?= $bInitial ?></div>
            <div class="brand-name-big"><?= htmlspecialchars($brandName) ?></div>
            <div class="brand-count-pill"><?= count($trims) ?> <?= $lang==='ar'?'فئة':'Trims' ?></div>
            <div class="brand-priced-pill"><?= $bPriced ?> ✓</div>
        </div>

        <table class="pricing-table" id="table-<?= md5($brandName) ?>">
            <thead>
                <tr>
                    <?php if ($canEdit): ?><th style="width:36px;"></th><?php endif; ?>
                    <th><?= $t[$lang]['model'] ?> / <?= $t[$lang]['trim'] ?></th>
                    <th>📅 <?= $t[$lang]['year'] ?></th>
                    <th>💚 <?= $t[$lang]['official_price'] ?> <small style="color:var(--red);font-size:9px;"><?= $t[$lang]['required'] ?></small></th>
                    <th>💙 <?= $t[$lang]['customer_price'] ?> <small style="color:var(--muted);font-size:9px;"><?= $t[$lang]['optional'] ?></small></th>
                    <th>
                        <?php if ($isSales): ?>
                            🔒 <span style="color:rgba(239,68,68,.4)"><?= $t[$lang]['trade_price'] ?></span>
                        <?php else: ?>
                            💜 <?= $t[$lang]['trade_price'] ?>
                        <?php endif; ?>
                        <small style="color:var(--muted);font-size:9px;"><?= $t[$lang]['optional'] ?></small>
                    </th>
                    <th>📝 <?= $t[$lang]['notes'] ?> <small style="color:var(--muted);font-size:9px;"><?= $t[$lang]['notes_optional'] ?></small></th>
                    <th><?= $t[$lang]['last_updated'] ?></th>
                    <th><?= $t[$lang]['edit'] ?></th>
                </tr>
            </thead>
            <tbody id="tbody-<?= md5($brandName) ?>">
            <?php
            foreach ($trims as $key => $trimData):
                $rowId    = md5($key);
                $hasYears = !empty($trimData['years']);
                $isFirst  = true;
            ?>

                <?php if ($hasYears): ?>
                    <?php foreach ($trimData['years'] as $yr => $yearRow):
                        $yRowId = md5($key . '|' . $yr);
                    ?>
                    <tr id="row-<?= $yRowId ?>"
                        class="<?= $isFirst ? '' : 'year-sub' ?>"
                        data-trim-key="<?= htmlspecialchars($key) ?>"
                        data-brand="<?= htmlspecialchars($trimData['brand']) ?>"
                        data-model="<?= htmlspecialchars($trimData['model_name']) ?>"
                        data-trim="<?= htmlspecialchars($trimData['trim_name']) ?>">

                        <?php if ($canEdit): ?>
                        <td style="text-align:center;">
                            <span class="drag-handle" title="<?= $t[$lang]['sort_hint'] ?>">⠿</span>
                        </td>
                        <?php endif; ?>

                        <td>
                            <?php if ($isFirst): ?>
                            <div class="model-cell"><?= htmlspecialchars($trimData['model_name']) ?></div>
                            <div class="trim-cell"><?= htmlspecialchars($trimData['trim_name']) ?></div>
                            <?php else: ?>
                            <div class="trim-cell" style="color:var(--muted);font-size:11px;">↳</div>
                            <?php endif; ?>
                        </td>

                        <!-- Year -->
                        <td>
                            <div class="price-display" id="disp-year-<?= $yRowId ?>">
                                <span class="year-badge">📅 <?= htmlspecialchars($yr) ?></span>
                            </div>
                            <?php if ($canEdit): ?>
                            <div class="price-input-wrap" id="inp-year-<?= $yRowId ?>">
                                <div class="year-select-wrap">
                                    <select class="p-year" id="sel-year1-<?= $yRowId ?>">
                                        <option value=""><?= $t[$lang]['year_required'] ?></option>
                                        <?php foreach ($yearOptions as $yo): ?>
                                        <option value="<?= $yo ?>" <?= $yo == $yr ? 'selected' : '' ?>><?= $yo ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select class="p-year" id="sel-year2-<?= $yRowId ?>" style="border-style:dashed;border-color:rgba(147,51,234,.35);">
                                        <option value=""><?= $t[$lang]['year2_optional'] ?></option>
                                        <?php foreach ($yearOptions as $yo): ?>
                                        <option value="<?= $yo ?>"><?= $yo ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="year-hint"><?= $lang==='ar'?'السنة الثانية تنشئ صفاً جديداً':'2nd year creates a duplicate row' ?></span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </td>

                        <!-- Official Price (UNCHANGED — numeric) -->
                        <td>
                            <div class="price-display" id="disp-off-<?= $yRowId ?>">
                                <?php if ($yearRow['official_price'] !== null): ?>
                                <span class="price-official"><?= fmt($yearRow['official_price'], $t[$lang]['currency']) ?></span>
                                <?php else: ?>
                                <span class="price-empty"><?= $t[$lang]['no_price'] ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($canEdit): ?>
                            <div class="price-input-wrap" id="inp-off-<?= $yRowId ?>">
                                <input class="p-input" type="number" min="0" step="1"
                                       value="<?= htmlspecialchars($yearRow['official_price'] ?? '') ?>"
                                       placeholder="0">
                            </div>
                            <?php endif; ?>
                        </td>

                        <!-- Customer Price (NOW: discount/offer picker) -->
                        <td>
                            <div class="price-display" id="disp-cust-<?= $yRowId ?>">
                                <?= dealBadge($yearRow['customer_price'], $t, $lang) ?>
                            </div>
                            <?php if ($canEdit): ?>
                            <div class="price-input-wrap" id="inp-cust-<?= $yRowId ?>">
                                <?= dealPicker('cust', $yRowId, $yearRow['customer_price'], $offerSteps, $t, $lang) ?>
                            </div>
                            <?php endif; ?>
                        </td>

                        <!-- Trade Price (NOW: discount/offer picker) -->
                        <td>
                            <?php if ($isSales): ?>
                                <span class="trade-col-sales">🔒 <?= $t[$lang]['trade_locked'] ?></span>
                            <?php else: ?>
                            <div class="price-display" id="disp-trade-<?= $yRowId ?>">
                                <?= dealBadge($yearRow['trade_price'], $t, $lang) ?>
                            </div>
                            <?php if ($canEdit): ?>
                            <div class="price-input-wrap" id="inp-trade-<?= $yRowId ?>">
                                <?= dealPicker('trade', $yRowId, $yearRow['trade_price'], $offerSteps, $t, $lang) ?>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>

                        <!-- Notes -->
                        <td>
                            <div class="price-display" id="disp-notes-<?= $yRowId ?>">
                                <?php if (!empty($yearRow['notes'])): ?>
                                <span class="row-note"><?= htmlspecialchars($yearRow['notes']) ?></span>
                                <?php else: ?>
                                <span class="price-empty"><?= $t[$lang]['not_set'] ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($canEdit): ?>
                            <div class="price-input-wrap" id="inp-notes-<?= $yRowId ?>">
                                <textarea class="p-input p-textarea" placeholder="<?= $t[$lang]['notes_ph'] ?>"
                                          rows="2" style="width:160px;height:auto;padding:8px 10px;"><?= htmlspecialchars($yearRow['notes'] ?? '') ?></textarea>
                            </div>
                            <?php endif; ?>
                        </td>

                        <!-- Updated meta -->
                        <td>
                            <div class="update-meta" id="meta-<?= $yRowId ?>">
                                <?php if ($yearRow['updated_by']): ?>
                                <span class="meta-by"><?= htmlspecialchars($yearRow['updated_by']) ?></span>
                                <span><?= date('d M Y', strtotime($yearRow['updated_at'])) ?></span>
                                <span style="font-size:10px;"><?= date('h:i A', strtotime($yearRow['updated_at'])) ?></span>
                                <?php else: ?>
                                <span><?= $t[$lang]['not_set'] ?></span>
                                <?php endif; ?>
                            </div>
                        </td>

                        <!-- Action -->
                        <td>
                            <?php if ($canEdit): ?>
                            <span style="display:none"
                                  data-brand="<?= htmlspecialchars($trimData['brand']) ?>"
                                  data-model="<?= htmlspecialchars($trimData['model_name']) ?>"
                                  data-trim="<?= htmlspecialchars($trimData['trim_name']) ?>"
                                  data-year="<?= htmlspecialchars($yr) ?>"
                                  id="meta-data-<?= $yRowId ?>"></span>

                            <div class="action-cell-wrap">
                                <div id="action-edit-<?= $yRowId ?>">
                                    <button class="btn-edit" onclick="startEdit('<?= $yRowId ?>')">
                                        ✏️ <?= $t[$lang]['edit'] ?>
                                    </button>
                                    <?php if (!$isFirst): ?>
                                    <button class="btn-delete-year"
                                            onclick="deleteYear('<?= $yRowId ?>')"
                                            style="margin-top:4px;">
                                        🗑 <?= $t[$lang]['delete_year'] ?>
                                    </button>
                                    <?php endif; ?>
                                </div>
                                <div id="action-save-<?= $yRowId ?>" style="display:none;flex-direction:column;gap:6px;align-items:center;">
                                    <button class="btn-save" onclick="saveRow('<?= $yRowId ?>')">
                                        💾 <?= $t[$lang]['save'] ?>
                                    </button>
                                    <button class="btn-cancel" onclick="cancelEdit('<?= $yRowId ?>')">
                                        ✕ <?= $t[$lang]['cancel'] ?>
                                    </button>
                                </div>
                            </div>
                            <?php else: ?>
                            <button class="btn-disabled" disabled title="<?= $t[$lang]['mgmt_only'] ?>">
                                🔒 <?= $t[$lang]['mgmt_only'] ?>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php $isFirst = false; endforeach; ?>

                <?php else: ?>
                <?php $yRowId = $rowId; ?>
                <tr id="row-<?= $yRowId ?>"
                    data-trim-key="<?= htmlspecialchars($key) ?>"
                    data-brand="<?= htmlspecialchars($trimData['brand']) ?>"
                    data-model="<?= htmlspecialchars($trimData['model_name']) ?>"
                    data-trim="<?= htmlspecialchars($trimData['trim_name']) ?>">

                    <?php if ($canEdit): ?>
                    <td style="text-align:center;">
                        <span class="drag-handle" title="<?= $t[$lang]['sort_hint'] ?>">⠿</span>
                    </td>
                    <?php endif; ?>

                    <td>
                        <div class="model-cell"><?= htmlspecialchars($trimData['model_name']) ?></div>
                        <div class="trim-cell"><?= htmlspecialchars($trimData['trim_name']) ?></div>
                    </td>

                    <!-- Year -->
                    <td>
                        <div class="price-display" id="disp-year-<?= $yRowId ?>">
                            <span class="price-empty"><?= $t[$lang]['no_price'] ?></span>
                        </div>
                        <?php if ($canEdit): ?>
                        <div class="price-input-wrap" id="inp-year-<?= $yRowId ?>">
                            <div class="year-select-wrap">
                                <select class="p-year" id="sel-year1-<?= $yRowId ?>">
                                    <option value=""><?= $t[$lang]['year_required'] ?></option>
                                    <?php foreach ($yearOptions as $yo): ?>
                                    <option value="<?= $yo ?>"><?= $yo ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select class="p-year" id="sel-year2-<?= $yRowId ?>" style="border-style:dashed;border-color:rgba(147,51,234,.35);">
                                    <option value=""><?= $t[$lang]['year2_optional'] ?></option>
                                    <?php foreach ($yearOptions as $yo): ?>
                                    <option value="<?= $yo ?>"><?= $yo ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="year-hint"><?= $lang==='ar'?'السنة الثانية تنشئ صفاً جديداً':'2nd year creates a duplicate row' ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </td>

                    <!-- Official (UNCHANGED) -->
                    <td>
                        <div class="price-display" id="disp-off-<?= $yRowId ?>">
                            <span class="price-empty"><?= $t[$lang]['no_price'] ?></span>
                        </div>
                        <?php if ($canEdit): ?>
                        <div class="price-input-wrap" id="inp-off-<?= $yRowId ?>">
                            <input class="p-input" type="number" min="0" step="1" value="" placeholder="0">
                        </div>
                        <?php endif; ?>
                    </td>

                    <!-- Customer (deal picker) -->
                    <td>
                        <div class="price-display" id="disp-cust-<?= $yRowId ?>">
                            <span class="price-empty"><?= $t[$lang]['not_set'] ?></span>
                        </div>
                        <?php if ($canEdit): ?>
                        <div class="price-input-wrap" id="inp-cust-<?= $yRowId ?>">
                            <?= dealPicker('cust', $yRowId, null, $offerSteps, $t, $lang) ?>
                        </div>
                        <?php endif; ?>
                    </td>

                    <!-- Trade (deal picker) -->
                    <td>
                        <?php if ($isSales): ?>
                            <span class="trade-col-sales">🔒 <?= $t[$lang]['trade_locked'] ?></span>
                        <?php else: ?>
                        <div class="price-display" id="disp-trade-<?= $yRowId ?>">
                            <span class="price-empty"><?= $t[$lang]['not_set'] ?></span>
                        </div>
                        <?php if ($canEdit): ?>
                        <div class="price-input-wrap" id="inp-trade-<?= $yRowId ?>">
                            <?= dealPicker('trade', $yRowId, null, $offerSteps, $t, $lang) ?>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>

                    <!-- Notes -->
                    <td>
                        <div class="price-display" id="disp-notes-<?= $yRowId ?>">
                            <span class="price-empty"><?= $t[$lang]['not_set'] ?></span>
                        </div>
                        <?php if ($canEdit): ?>
                        <div class="price-input-wrap" id="inp-notes-<?= $yRowId ?>">
                            <textarea class="p-input p-textarea" placeholder="<?= $t[$lang]['notes_ph'] ?>"
                                      rows="2" style="width:160px;height:auto;padding:8px 10px;"></textarea>
                        </div>
                        <?php endif; ?>
                    </td>

                    <!-- Meta -->
                    <td>
                        <div class="update-meta" id="meta-<?= $yRowId ?>">
                            <span><?= $t[$lang]['not_set'] ?></span>
                        </div>
                    </td>

                    <!-- Action -->
                    <td>
                        <?php if ($canEdit): ?>
                        <span style="display:none"
                              data-brand="<?= htmlspecialchars($trimData['brand']) ?>"
                              data-model="<?= htmlspecialchars($trimData['model_name']) ?>"
                              data-trim="<?= htmlspecialchars($trimData['trim_name']) ?>"
                              data-year=""
                              id="meta-data-<?= $yRowId ?>"></span>
                        <div class="action-cell-wrap">
                            <div id="action-edit-<?= $yRowId ?>">
                                <button class="btn-edit" onclick="startEdit('<?= $yRowId ?>')">
                                    ✏️ <?= $t[$lang]['edit'] ?>
                                </button>
                            </div>
                            <div id="action-save-<?= $yRowId ?>" style="display:none;flex-direction:column;gap:6px;align-items:center;">
                                <button class="btn-save" onclick="saveRow('<?= $yRowId ?>')">
                                    💾 <?= $t[$lang]['save'] ?>
                                </button>
                                <button class="btn-cancel" onclick="cancelEdit('<?= $yRowId ?>')">
                                    ✕ <?= $t[$lang]['cancel'] ?>
                                </button>
                            </div>
                        </div>
                        <?php else: ?>
                        <button class="btn-disabled" disabled>🔒 <?= $t[$lang]['mgmt_only'] ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>

            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endforeach; ?>

<?php endif; ?>
</div><!-- /.table-card -->

</div><!-- /.wrap -->

<div class="toast" id="toast"></div>
<script>
const LANG        = '<?= $lang ?>';
const CURRENCY    = '<?= $t[$lang]['currency'] ?>';
const CAN_EDIT    = <?= $canEdit ? 'true' : 'false' ?>;
const IS_SALES    = <?= $isSales ? 'true' : 'false' ?>;
const OFFER_WORD    = '<?= addslashes($t[$lang]['offer']) ?>';
const DISCOUNT_WORD = '<?= addslashes($t[$lang]['discount']) ?>';
const OFFICIAL_WORD = '<?= addslashes($t[$lang]['type_official']) ?>';
const TXT = {
    save:          '💾 <?= addslashes($t[$lang]['save']) ?>',
    saving:        '⏳',
    saveSuccess:   '✅ <?= addslashes($t[$lang]['save_success']) ?>',
    orderSaved:    '✅ <?= addslashes($t[$lang]['order_saved']) ?>',
    savingOrder:   '<?= addslashes($t[$lang]['saving_order']) ?>',
    saveOrder:     '💾 <?= addslashes($t[$lang]['save_order']) ?>',
    notSet:        '<?= addslashes($t[$lang]['not_set']) ?>',
    noPrice:       '<?= addslashes($t[$lang]['no_price']) ?>',
    deleteConfirm: '<?= addslashes($t[$lang]['delete_confirm']) ?>',
    deleteSuccess: '✅ <?= addslashes($t[$lang]['delete_success']) ?>',
    pickAmount:    '<?= addslashes($t[$lang]['pick_amount']) ?>',
};

function formatPrice(val) {
    if (!val && val !== 0) return null;
    return Number(val).toLocaleString() + ' ' + CURRENCY;
}

/* ════════════════════════════════════════════════════
   DISCOUNT / OFFER PICKER  (customer + trade only)
════════════════════════════════════════════════════ */

// User picked "offer" or "discount" — enable the amount dropdown
function setDealType(kind, rowId, type) {
    document.getElementById('deal-type-' + kind + '-' + rowId).value = type;
    // clear any previously-stored official value so it doesn't stick
    document.getElementById('deal-val-' + kind + '-' + rowId).value = '';
    const sel = document.getElementById('deal-amt-' + kind + '-' + rowId);
    sel.disabled = false;

    // highlight the chosen type button, clear ALL others (including official)
    document.querySelectorAll(
        '.deal-type-btn[data-kind="' + kind + '"][data-row="' + rowId + '"]'
    ).forEach(btn => {
        btn.classList.remove('sel-offer', 'sel-discount', 'sel-official');
        if (btn.dataset.type === type) {
            btn.classList.add(type === 'offer' ? 'sel-offer' : 'sel-discount');
        }
    });
}

// User clicked "رسمي" — store the plain word "رسمي", no number, disable amount picker
function setOfficial(kind, rowId) {
    document.getElementById('deal-type-' + kind + '-' + rowId).value = 'official';
    document.getElementById('deal-val-' + kind + '-' + rowId).value = OFFICIAL_WORD;
    const sel = document.getElementById('deal-amt-' + kind + '-' + rowId);
    sel.value = '';
    sel.disabled = true;
    document.querySelectorAll(
        '.deal-type-btn[data-kind="' + kind + '"][data-row="' + rowId + '"]'
    ).forEach(btn => {
        btn.classList.remove('sel-offer', 'sel-discount', 'sel-official');
        if (btn.dataset.type === 'official') btn.classList.add('sel-official');
    });
}

// Build the final label text, e.g. "أوفر 10,000"
function getDealValue(kind, rowId) {
    const typeOfficial = document.getElementById('deal-type-' + kind + '-' + rowId).value;
    if (typeOfficial === 'official') return OFFICIAL_WORD;
    const type = document.getElementById('deal-type-' + kind + '-' + rowId).value;
    const amtSel = document.getElementById('deal-amt-' + kind + '-' + rowId);
    const amt  = amtSel ? amtSel.value : '';
    if (!type || !amt) {
        // fall back to any previously-saved hidden value if user didn't change it
        const prev = document.getElementById('deal-val-' + kind + '-' + rowId);
        return prev ? prev.value.trim() : '';
    }
    const word = (type === 'offer') ? OFFER_WORD : DISCOUNT_WORD;
    const amtFmt = Number(amt).toLocaleString();
    return word + ' ' + amtFmt;
}

function clearDeal(kind, rowId) {
    document.getElementById('deal-type-' + kind + '-' + rowId).value = '';
    document.getElementById('deal-val-' + kind + '-' + rowId).value = '';
    const sel = document.getElementById('deal-amt-' + kind + '-' + rowId);
    sel.value = '';
    sel.disabled = true;
    document.querySelectorAll(
        '.deal-type-btn[data-kind="' + kind + '"][data-row="' + rowId + '"]'
    ).forEach(btn => btn.classList.remove('sel-offer', 'sel-discount'));
}

// Render a deal value as a colored badge (for display after save)
function dealBadgeHtml(val) {
    if (!val) return '<span class="price-empty">' + TXT.notSet + '</span>';
    const isOfficial = val.indexOf(OFFICIAL_WORD) !== -1 || /official/i.test(val);
    const isOffer = val.indexOf(OFFER_WORD) !== -1 || /offer/i.test(val);
    const isDisc  = val.indexOf(DISCOUNT_WORD) !== -1 || /discount/i.test(val);
    let cls, icon;
    if (isOfficial)      { cls = 'deal-offer';     icon = '📢'; }
    else if (isOffer)    { cls = 'deal-offer';     icon = '⬆️'; }
    else if (isDisc)     { cls = 'deal-discount';  icon = '⬇️'; }
    else                 { cls = 'deal-offer';     icon = '';   }
    const safe = val.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    return '<span class="deal-badge ' + cls + '">' + icon + ' ' + safe + '</span>';
}

/* ── Edit helpers ── */
function startEdit(id) {
    ['year','off','cust','trade','notes'].forEach(f => {
        const disp = document.getElementById('disp-' + f + '-' + id);
        const inp  = document.getElementById('inp-'  + f + '-' + id);
        if (disp) disp.classList.add('hidden');
        if (inp)  inp.classList.add('active');
    });
    const editDiv = document.getElementById('action-edit-' + id);
    const saveDiv = document.getElementById('action-save-' + id);
    if (editDiv) editDiv.style.display = 'none';
    if (saveDiv) saveDiv.style.display = 'flex';
    // pre-select the saved deal type (offer / discount / official) on each picker
    ['cust','trade'].forEach(kind => initDealPicker(kind, id));
    const offInput = document.querySelector('#inp-off-' + id + ' input');
    if (offInput) offInput.focus();
}

// Read the saved hidden value and highlight the matching button + dropdown
function initDealPicker(kind, id) {
    const valEl = document.getElementById('deal-val-' + kind + '-' + id);
    if (!valEl) return;
    const val = (valEl.value || '').trim();
    const typeEl = document.getElementById('deal-type-' + kind + '-' + id);
    const sel    = document.getElementById('deal-amt-' + kind + '-' + id);
    const btns   = document.querySelectorAll('.deal-type-btn[data-kind="' + kind + '"][data-row="' + id + '"]');

    // reset everything first
    btns.forEach(b => b.classList.remove('sel-offer','sel-discount','sel-official'));
    if (sel) { sel.value = ''; sel.disabled = true; }
    if (typeEl) typeEl.value = '';

    if (!val) return;

    if (val.indexOf(OFFICIAL_WORD) !== -1 || /official/i.test(val)) {
        if (typeEl) typeEl.value = 'official';
        btns.forEach(b => { if (b.dataset.type === 'official') b.classList.add('sel-official'); });
    } else {
        const isOffer = val.indexOf(OFFER_WORD) !== -1 || /offer/i.test(val);
        const type = isOffer ? 'offer' : 'discount';
        if (typeEl) typeEl.value = type;
        if (sel) sel.disabled = false;
        // extract the number from the label and pre-select it
        const num = (val.match(/[\d,]+/) || [''])[0].replace(/,/g,'');
        if (sel && num) sel.value = num;
        btns.forEach(b => { if (b.dataset.type === type) b.classList.add(isOffer ? 'sel-offer' : 'sel-discount'); });
    }
}

function cancelEdit(id) {
    ['year','off','cust','trade','notes'].forEach(f => {
        const disp = document.getElementById('disp-' + f + '-' + id);
        const inp  = document.getElementById('inp-'  + f + '-' + id);
        if (disp) disp.classList.remove('hidden');
        if (inp)  inp.classList.remove('active');
    });
    const editDiv = document.getElementById('action-edit-' + id);
    const saveDiv = document.getElementById('action-save-' + id);
    if (editDiv) editDiv.style.display = '';
    if (saveDiv) saveDiv.style.display = 'none';
}

async function saveRow(id) {
    const saveDiv = document.getElementById('action-save-' + id);
    const btn     = saveDiv ? saveDiv.querySelector('.btn-save') : null;
    const meta    = document.getElementById('meta-data-' + id);
    if (!meta) return;

    const brand      = meta.dataset.brand;
    const model_name = meta.dataset.model;
    const trim_name  = meta.dataset.trim;

    const year1El  = document.getElementById('sel-year1-' + id);
    const year2El  = document.getElementById('sel-year2-' + id);
    const offEl    = document.querySelector('#inp-off-'   + id + ' input');
    const notesEl  = document.querySelector('#inp-notes-' + id + ' textarea');

    const year1    = year1El  ? year1El.value.trim()  : '';
    const year2    = year2El  ? year2El.value.trim()  : '';
    const offVal   = offEl    ? offEl.value.trim()    : '';
    const notesVal = notesEl  ? notesEl.value.trim()  : '';

    // Customer + trade are now deal labels (e.g. "أوفر 10,000")
    const custVal  = getDealValue('cust', id);
    const tradeVal = IS_SALES ? '' : getDealValue('trade', id);

    if (!year1) {
        if (year1El) { year1El.style.borderColor = '#ef4444'; setTimeout(() => year1El.style.borderColor = '', 1500); }
        return;
    }
    if (!offVal) {
        if (offEl) { offEl.style.borderColor = '#ef4444'; setTimeout(() => offEl.style.borderColor = '', 1500); }
        return;
    }

    if (btn) { btn.classList.add('saving'); btn.textContent = TXT.saving; }

    async function postYear(yr) {
        const fd = new FormData();
        fd.append('action',         'save');
        fd.append('brand',          brand);
        fd.append('model_name',     model_name);
        fd.append('trim_name',      trim_name);
        fd.append('car_year',       yr);
        fd.append('official_price', offVal);
        fd.append('customer_price', custVal);
        fd.append('trade_price',    tradeVal);
        fd.append('notes',          notesVal);
        fd.append('lang',           LANG);
        const res = await fetch('prices.php', { method: 'POST', body: fd });
        return await res.json();
    }

    try {
        const data = await postYear(year1);

        if (data.ok) {
            const yearDisp = document.getElementById('disp-year-' + id);
            if (yearDisp) yearDisp.innerHTML = `<span class="year-badge">📅 ${year1}</span>`;

            updateDispCell('disp-off-' + id, offVal ? `<span class="price-official">${formatPrice(offVal)}</span>` : `<span class="price-empty">${TXT.noPrice}</span>`);
            updateDispCell('disp-cust-' + id, dealBadgeHtml(custVal));
            if (!IS_SALES) {
                updateDispCell('disp-trade-' + id, dealBadgeHtml(tradeVal));
            }
            updateDispCell('disp-notes-' + id,
                notesVal
                    ? `<span class="row-note">${notesVal.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}</span>`
                    : `<span class="price-empty">${TXT.notSet}</span>`
            );

            // keep the hidden value in sync so re-editing remembers it
            const custHid = document.getElementById('deal-val-cust-' + id);
            if (custHid) custHid.value = custVal;
            if (!IS_SALES) {
                const trHid = document.getElementById('deal-val-trade-' + id);
                if (trHid) trHid.value = tradeVal;
            }

            const metaEl = document.getElementById('meta-' + id);
            if (metaEl) metaEl.innerHTML = `<span class="meta-by">${data.updated_by}</span><span>${data.updated_at}</span>`;

            document.getElementById('lastUpdateDisplay').textContent = data.updated_by + ' · ' + data.updated_at;

            if (year2 && year2 !== year1) {
                await postYear(year2);
                showToast(TXT.saveSuccess);
                setTimeout(() => location.reload(), 1200);
                return;
            }

            cancelEdit(id);
            showToast(TXT.saveSuccess);
        } else {
            alert(data.msg || 'Error saving');
        }
    } catch(e) {
        alert('Network error');
    }

    if (btn) { btn.classList.remove('saving'); btn.innerHTML = TXT.save; }
}

function updateDispCell(elId, html) {
    const el = document.getElementById(elId);
    if (el) el.innerHTML = html;
}

function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2800);
}

async function deleteYear(id) {
    const meta = document.getElementById('meta-data-' + id);
    if (!meta) return;

    const brand      = meta.dataset.brand;
    const model_name = meta.dataset.model;
    const trim_name  = meta.dataset.trim;
    const car_year   = meta.dataset.year;

    if (!car_year) { alert('Year not found'); return; }
    if (!confirm(TXT.deleteConfirm)) return;

    const fd = new FormData();
    fd.append('action',     'delete_year');
    fd.append('brand',      brand);
    fd.append('model_name', model_name);
    fd.append('trim_name',  trim_name);
    fd.append('car_year',   car_year);

    try {
        const res  = await fetch('prices.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            showToast(TXT.deleteSuccess);
            const row = document.getElementById('row-' + id);
            if (row) {
                row.style.transition = 'opacity .3s';
                row.style.opacity    = '0';
                setTimeout(() => row.remove(), 320);
            }
        } else {
            alert(data.msg || 'Delete failed');
        }
    } catch(e) {
        alert('Network error');
    }
}

/* ── Daily auto-refresh ── */
(function() {
    const KEY    = 'prices_last_reload';
    const MS_DAY = 24 * 60 * 60 * 1000;
    const now    = Date.now();
    const last   = parseInt(localStorage.getItem(KEY) || '0', 10);
    if (now - last >= MS_DAY) {
        localStorage.setItem(KEY, now);
        location.reload(true);
    } else {
        const checkedEl = document.getElementById('lastCheckedTime');
        if (checkedEl && last) {
            const d = new Date(last);
            const pad = n => String(n).padStart(2,'0');
            const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            const arMonths = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
            const mon = LANG === 'ar' ? arMonths[d.getMonth()] : months[d.getMonth()];
            const hr  = d.getHours();
            const ampm = LANG === 'ar' ? (hr >= 12 ? 'م' : 'ص') : (hr >= 12 ? 'PM' : 'AM');
            const hr12 = hr % 12 || 12;
            checkedEl.textContent = `${pad(d.getDate())} ${mon} ${d.getFullYear()} ${pad(hr12)}:${pad(d.getMinutes())} ${ampm}`;
        }
    }
})();

/* ════════════════════════════════════════════
   DRAG-TO-REORDER
════════════════════════════════════════════ */
<?php if ($canEdit): ?>

let dragModeActive = false;
let dragSrc        = null;
let dragClone      = null;
let dragOffsetY    = 0;

function enterDragMode() {
    dragModeActive = true;
    document.querySelectorAll('.pricing-table').forEach(t => t.classList.add('drag-mode-active'));
    document.getElementById('dragToolbar').classList.add('visible');
    showToast('↕️ <?= addslashes($t[$lang]['drag_mode']) ?>');
}

function exitDragMode() {
    dragModeActive = false;
    document.querySelectorAll('.pricing-table').forEach(t => t.classList.remove('drag-mode-active'));
    document.getElementById('dragToolbar').classList.remove('visible');
    document.querySelectorAll('tr.drag-over-top, tr.drag-over-bottom').forEach(tr => {
        tr.classList.remove('drag-over-top', 'drag-over-bottom');
    });
    removeClone();
}

async function saveOrder() {
    const btn = document.getElementById('btnSaveOrder');
    btn.disabled    = true;
    btn.textContent = TXT.savingOrder;

    const allOrders = [];
    document.querySelectorAll('.pricing-table tbody').forEach(tb => {
        tb.querySelectorAll('tr[data-trim-key]').forEach(tr => {
            const key = tr.dataset.trimKey;
            if (key && !allOrders.includes(key)) allOrders.push(key);
        });
    });

    const fd = new FormData();
    fd.append('action', 'save_order');
    fd.append('order',  JSON.stringify(allOrders));

    try {
        const res  = await fetch('prices.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            showToast(TXT.orderSaved);
            exitDragMode();
        } else {
            alert('Error saving order');
        }
    } catch(e) {
        alert('Network error');
    }

    btn.disabled    = false;
    btn.textContent = TXT.saveOrder;
}

document.addEventListener('mousedown', function(e) {
    if (!dragModeActive) return;
    const handle = e.target.closest('.drag-handle');
    if (!handle) return;
    dragSrc = handle.closest('tr');
    if (dragSrc) dragSrc.classList.add('dragging');
    e.preventDefault();
});
document.addEventListener('mousemove', function(e) {
    if (!dragSrc) return;
    highlightTarget(e.clientX, e.clientY, dragSrc);
});
document.addEventListener('mouseup', function(e) {
    if (!dragSrc) return;
    dropRow(e.clientX, e.clientY, dragSrc);
    dragSrc.classList.remove('dragging');
    dragSrc = null;
    clearHighlights();
});

document.addEventListener('touchstart', function(e) {
    if (!dragModeActive) return;
    const handle = e.target.closest('.drag-handle');
    if (!handle) return;
    dragSrc = handle.closest('tr');
    if (!dragSrc) return;
    const touch = e.touches[0];
    const rect  = dragSrc.getBoundingClientRect();
    dragOffsetY = touch.clientY - rect.top;
    dragClone = dragSrc.cloneNode(true);
    dragClone.style.cssText = `
        position:fixed; left:${rect.left}px; top:${touch.clientY - dragOffsetY}px;
        width:${rect.width}px; opacity:0.85; pointer-events:none; z-index:9999;
        background:rgba(147,51,234,.18); border:2px solid var(--purple);
        border-radius:10px; box-shadow:0 8px 32px rgba(0,0,0,.5);
        transform:scale(1.02); transition:none;
    `;
    document.body.appendChild(dragClone);
    dragSrc.classList.add('dragging');
    e.preventDefault();
}, { passive: false });

document.addEventListener('touchmove', function(e) {
    if (!dragSrc || !dragClone) return;
    const touch = e.touches[0];
    dragClone.style.top = (touch.clientY - dragOffsetY) + 'px';
    highlightTarget(touch.clientX, touch.clientY, dragSrc);
    e.preventDefault();
}, { passive: false });

document.addEventListener('touchend', function(e) {
    if (!dragSrc) return;
    const touch = e.changedTouches[0];
    dropRow(touch.clientX, touch.clientY, dragSrc);
    dragSrc.classList.remove('dragging');
    dragSrc = null;
    removeClone();
    clearHighlights();
});

document.addEventListener('touchcancel', function() {
    if (dragSrc) dragSrc.classList.remove('dragging');
    dragSrc = null;
    removeClone();
    clearHighlights();
});

function getRowUnderPointer(clientX, clientY, exclude) {
    if (dragClone) dragClone.style.display = 'none';
    const el = document.elementFromPoint(clientX, clientY);
    if (dragClone) dragClone.style.display = '';
    if (!el) return null;
    const tr = el.closest('tr[data-trim-key]');
    return (tr && tr !== exclude) ? tr : null;
}

function highlightTarget(clientX, clientY, exclude) {
    clearHighlights();
    const target = getRowUnderPointer(clientX, clientY, exclude);
    if (!target) return;
    const rect   = target.getBoundingClientRect();
    const before = clientY < (rect.top + rect.height / 2);
    target.classList.add(before ? 'drag-over-top' : 'drag-over-bottom');
}

function dropRow(clientX, clientY, src) {
    const target = getRowUnderPointer(clientX, clientY, src);
    if (!target) return;
    const rect   = target.getBoundingClientRect();
    const before = clientY < (rect.top + rect.height / 2);
    const tbody  = src.closest('tbody');
    if (!tbody) return;
    if (before) tbody.insertBefore(src, target);
    else        tbody.insertBefore(src, target.nextSibling);
}

function clearHighlights() {
    document.querySelectorAll('tr.drag-over-top, tr.drag-over-bottom').forEach(tr => {
        tr.classList.remove('drag-over-top', 'drag-over-bottom');
    });
}

function removeClone() {
    if (dragClone) { dragClone.remove(); dragClone = null; }
}

(function() {
    const toolbar = document.getElementById('dragToolbar');
    if (!toolbar) return;
    const triggerBtn = document.createElement('button');
    triggerBtn.id        = 'btnEnterDrag';
    triggerBtn.className = 'btn-filter';
    triggerBtn.style.cssText = 'margin-bottom:14px;display:flex;align-items:center;gap:6px;font-size:13px;height:40px;padding:0 18px;';
    triggerBtn.innerHTML = '↕️ <?= addslashes($t[$lang]['drag_mode']) ?>';
    triggerBtn.onclick   = enterDragMode;
    toolbar.parentNode.insertBefore(triggerBtn, toolbar);

    const origEnter = enterDragMode;
    window.enterDragMode = function() {
        origEnter();
        triggerBtn.style.display = 'none';
    };
    const origExit = exitDragMode;
    window.exitDragMode = function() {
        origExit();
        triggerBtn.style.display = '';
    };
})();

<?php endif; ?>
</script>
</body>
</html>
