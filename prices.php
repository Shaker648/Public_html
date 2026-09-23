<?php
require 'auth.php';
require 'config.php';
require_once __DIR__ . '/push_helpers.php';
require 'car_images_helpers.php';

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

    if (!empty($changed)) {
        notify_event($pdo, 'price_changed', [
            'brand' => $brand, 'model' => $model_name, 'trim' => $trim_name, 'year' => $car_year,
            'old' => ($oldVals['official'] ?? '') !== $newVals['official'] ? (string)($oldVals['official'] ?? '') : '',
            'new' => (string)$official_price,
            'customer' => (string)($customer_price ?? ''),
            'type' => $type ?? 'update',
        ]);
    }
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
/* Every active model is sent once. The search and brand filters still work
   exactly as before (search matches model or trim, brand is exact), but they
   are applied instantly in the browser, so filtering never reloads the page.
   A ?brand= or ?search= link still opens with that filter applied. */
$mWhere  = ["m.active = 1"];
$mParams = [];

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

/* ═══════════════ Context for each price line (screen extras) ═══════════════
   All read once for the page. Nothing here is used by saving or reordering. */
$pxKey = fn($b, $m, $tr, $y = '') => mb_strtolower(trim((string)$b) . '|' . trim((string)$m) . '|' . trim((string)$tr) . '|' . trim((string)$y));

// 1. what is in stock right now, per brand / model / trim / year, split by branch
$pxStock = [];      // yearKey => ['n'=>, 'br'=>[label=>n]]
$pxColors = [];     // trimKey(no year) => [colour => n]
try {
    $sr = $pdo->query("
        SELECT c.brand, c.model, c.trim_name, c.car_year, c.branch, c.color, COUNT(*) AS n,
               b.name_ar, b.name_en
        FROM cars c LEFT JOIN branches b ON b.name = c.branch
        WHERE c.status IN ('available','reserved')
        GROUP BY c.brand, c.model, c.trim_name, c.car_year, c.branch, c.color, b.name_ar, b.name_en
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sr as $r) {
        $k  = $pxKey($r['brand'], $r['model'], $r['trim_name'], $r['car_year']);
        $bl = $lang === 'ar' ? ($r['name_ar'] ?: $r['branch']) : ($r['name_en'] ?: $r['branch']);
        $pxStock[$k]['n'] = ($pxStock[$k]['n'] ?? 0) + (int)$r['n'];
        $pxStock[$k]['br'][$bl] = ($pxStock[$k]['br'][$bl] ?? 0) + (int)$r['n'];
        $tk = $pxKey($r['brand'], $r['model'], $r['trim_name']);
        $pxColors[$tk][$r['color']] = ($pxColors[$tk][$r['color']] ?? 0) + (int)$r['n'];
    }
} catch (Throwable $e) { error_log('prices: stock context failed: ' . $e->getMessage()); }

// 2. recent price changes, newest first (last 180 days)
$pxHist = [];       // yearKey => list
try {
    $hr = $pdo->query("
        SELECT brand, model_name, trim_name, car_year, change_type,
               old_official, new_official, old_customer, new_customer, old_trade, new_trade,
               changed_by, changed_at
        FROM pricing_history
        WHERE changed_at >= NOW() - INTERVAL 180 DAY
        ORDER BY changed_at DESC, id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($hr as $r) {
        $k = $pxKey($r['brand'], $r['model_name'], $r['trim_name'], $r['car_year']);
        if (count($pxHist[$k] ?? []) >= 8) continue;
        $pxHist[$k][] = [
            't'  => (string)$r['change_type'],
            'y'  => (string)$r['car_year'],
            'o0' => (string)($r['old_official'] ?? ''), 'o1' => (string)($r['new_official'] ?? ''),
            'c0' => (string)($r['old_customer'] ?? ''), 'c1' => (string)($r['new_customer'] ?? ''),
            // the trade price never leaves the server for users who cannot see it
            'd0' => $isSales ? '' : (string)($r['old_trade'] ?? ''), 'd1' => $isSales ? '' : (string)($r['new_trade'] ?? ''),
            'by' => (string)$r['changed_by'],
            'at' => (string)$r['changed_at'],
        ];
    }
} catch (Throwable $e) { /* no history table yet: nothing to show */ }

// 3. a photo per trim from the image library: the colour most in stock that has
//    one, else the model's fallback image, else any image of that model
$pxImgMap = car_images_map($pdo);
$pxImage  = function ($brand, $model, $trim) use ($pxImgMap, $pxColors, $pxKey) {
    $cols = $pxColors[$pxKey($brand, $model, $trim)] ?? [];
    arsort($cols);
    foreach (array_keys($cols) as $c) {
        $u = car_image_url_for($pxImgMap, ['brand' => $brand, 'model' => $model, 'trim_name' => $trim, 'color' => $c], true);
        if ($u !== '') return $u;
    }
    $u = car_image_url_for($pxImgMap, ['brand' => $brand, 'model' => $model, 'trim_name' => $trim, 'color' => ''], true);
    if ($u !== '') return $u;
    $pre = car_image_norm($brand) . '|' . car_image_norm($model) . '|';
    foreach ($pxImgMap as $k => $row) {
        if (strpos($k, $pre) === 0) { $u = car_image_url($row, true); if ($u !== '') return $u; }
    }
    return '';
};

$pxDealKind = function ($v) {
    $v = (string)$v;
    if ($v === '') return '';
    if (mb_strpos($v, 'خصم') !== false || stripos($v, 'Discount') !== false) return 'disc';
    if (mb_strpos($v, 'أوفر') !== false || stripos($v, 'Offer') !== false)    return 'offer';
    return '';
};

$pxData   = [];     // trimKey (the page's own data-trim-key) => everything the extras need
$pxBrands = [];     // brand => up to 4 model photos
foreach ($trimMap as $bk => $trims) {
    foreach ($trims as $key => $tr) {
        $img = $pxImage($tr['brand'], $tr['model_name'], $tr['trim_name']);
        if ($img !== '' && count($pxBrands[$bk] ?? []) < 4 && !in_array($img, $pxBrands[$bk] ?? [], true)) {
            // one photo per model, so the strip shows different cars
            $seenModel = false;
            foreach ($pxData as $d) if ($d['brand'] === $tr['brand'] && $d['model'] === $tr['model_name'] && in_array($d['img'], $pxBrands[$bk] ?? [], true)) $seenModel = true;
            if (!$seenModel) $pxBrands[$bk][] = $img;
        }
        $years = [];
        foreach ($tr['years'] as $yr => $row) {
            $yk   = $pxKey($tr['brand'], $tr['model_name'], $tr['trim_name'], $yr);
            $age  = $row['updated_at'] ? (int)floor((time() - strtotime($row['updated_at'])) / 86400) : null;
            $h    = $pxHist[$yk] ?? [];
            $chg  = null;
            foreach ($h as $e) {
                if (!in_array($e['t'], ['create', 'update'], true)) continue;
                $days = (int)floor((time() - strtotime($e['at'])) / 86400);
                if ($days > 30) break;
                if (is_numeric($e['o0']) && is_numeric($e['o1']) && (float)$e['o0'] !== (float)$e['o1']) {
                    $chg = ['dir' => (float)$e['o1'] > (float)$e['o0'] ? 'up' : 'down', 'days' => $days,
                            'from' => number_format((float)$e['o0']), 'to' => number_format((float)$e['o1'])];
                }
                break;   // only the newest change counts
            }
            $years[(string)$yr] = [
                'off'   => $row['official_price'] !== null && $row['official_price'] !== '' ? number_format((float)$row['official_price']) : '',
                'cust'  => (string)($row['customer_price'] ?? ''),
                'trade' => $isSales ? '' : (string)($row['trade_price'] ?? ''),
                'ck'    => $pxDealKind($row['customer_price'] ?? ''),
                'tk'    => $isSales ? '' : $pxDealKind($row['trade_price'] ?? ''),
                'notes' => (string)($row['notes'] ?? ''),
                'upd'   => $row['updated_at'] ? date('Y-m-d', strtotime($row['updated_at'])) : '',
                'age'   => $age,
                'stock' => $pxStock[$yk]['n'] ?? 0,
                'br'    => $pxStock[$yk]['br'] ?? (object)[],
                'chg'   => $chg,
                'wk'    => !empty($h) && (time() - strtotime($h[0]['at'])) <= 7 * 86400,
            ];
        }
        $hist = [];
        foreach (array_keys($tr['years']) as $yr) foreach ($pxHist[$pxKey($tr['brand'], $tr['model_name'], $tr['trim_name'], $yr)] ?? [] as $e) $hist[] = $e;
        usort($hist, fn($a, $b) => strcmp($b['at'], $a['at']));
        $pxData[$key] = [
            'brand' => $tr['brand'], 'model' => $tr['model_name'], 'trim' => $tr['trim_name'],
            'img'   => $img, 'years' => $years, 'hist' => array_slice($hist, 0, 10),
        ];
    }
}
$pxCanHist = can('page.price_history');

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

<!-- ══════════════ Screen extras around the price tables ══════════════
     Nothing below changes how prices are edited, saved, reordered or deleted.
     It adds context beside the existing cells, never inside them, so a save
     that rewrites a cell cannot wipe it. -->
<style>
body::before { content:''; position:fixed; inset:0; z-index:-1; pointer-events:none;
    background: radial-gradient(ellipse 55% 40% at 88% -6%, rgba(245,158,11,.10), transparent 70%),
                radial-gradient(ellipse 45% 35% at 6% 2%, rgba(147,51,234,.13), transparent 70%),
                radial-gradient(ellipse 60% 45% at 50% 110%, rgba(34,197,94,.07), transparent 70%); }

.stats-row { display:none !important; }   /* its three numbers live on in the row below */
.px-kpis { display:grid; grid-template-columns:1.25fr repeat(5,1fr); gap:12px; margin-bottom:18px; }
.px-k { position:relative; overflow:hidden; padding:16px 18px; border-radius:20px;
        background:linear-gradient(160deg, rgba(20,30,52,.95), rgba(10,16,32,.95));
        border:1px solid rgba(255,255,255,.07); box-shadow:0 8px 30px rgba(0,0,0,.4); }
.px-k::before { content:''; position:absolute; inset-inline:0; top:0; height:3px; background:var(--kc); }
.px-k .n { font-size:34px; font-weight:900; line-height:1; color:var(--kc); font-variant-numeric:tabular-nums; }
.px-k .l { font-size:12px; font-weight:800; color:#94a3b8; margin-top:7px; }
.px-k.ring { display:flex; align-items:center; gap:16px; --kc:#22c55e; }
.px-k.ring svg { transform:rotate(-90deg); flex-shrink:0; }
.px-k.ring .bg { stroke:rgba(255,255,255,.08); } .px-k.ring .fg { stroke:url(#pxGrad); stroke-linecap:round; transition:stroke-dashoffset 1.2s cubic-bezier(.22,1,.36,1); }
.px-k.ring .px-pct { font-size:30px; font-weight:900; background:linear-gradient(90deg,#22c55e,#a855f7); -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent; }
.px-k.ring .sub { font-size:12px; color:#94a3b8; font-weight:800; margin-top:4px; }
.px-k.tot { --kc:#e2e8f0; } .px-k.unp { --kc:#ef4444; } .px-k.off { --kc:#38bdf8; } .px-k.dis { --kc:#f59e0b; } .px-k.wk { --kc:#a78bfa; }
.px-k.tap { cursor:pointer; transition:transform .15s, border-color .2s; } .px-k.tap:hover { transform:translateY(-2px); border-color:rgba(255,255,255,.18); }
.px-k.on { border-color:var(--kc); background:linear-gradient(160deg, color-mix(in srgb, var(--kc) 16%, #14203a), rgba(10,16,32,.95)); }

.px-chips { display:flex; gap:7px; overflow-x:auto; scrollbar-width:none; margin-top:12px; padding-bottom:2px; }
.px-chips::-webkit-scrollbar { display:none; }
.px-chip { flex-shrink:0; height:32px; padding:0 13px; border-radius:50px; cursor:pointer; font-family:inherit; font-size:12.5px; font-weight:800;
           background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:#94a3b8; display:inline-flex; align-items:center; gap:6px; transition:all .2s; }
.px-chip:hover { color:#e2e8f0; }
.px-chip.on { background:#9333ea; border-color:transparent; color:#fff; }
.px-chip b { font-size:10.5px; opacity:.75; }
.px-sep { flex-shrink:0; width:1px; height:18px; background:rgba(255,255,255,.14); margin:7px 3px; }

.px-jump { position:sticky; top:0; z-index:60; display:flex; gap:8px; margin:0 -20px 16px; padding:10px 20px; overflow-x:auto; scrollbar-width:none;
           background:rgba(2,6,23,.9); backdrop-filter:blur(16px); -webkit-backdrop-filter:blur(16px); border-bottom:1px solid rgba(255,255,255,.07); }
.px-jump::-webkit-scrollbar { display:none; } .px-jump:empty { display:none; }
.px-jp { flex-shrink:0; display:inline-flex; align-items:center; gap:8px; height:36px; padding:0 14px 0 10px; border-radius:50px; cursor:pointer;
         border:1px solid rgba(255,255,255,.1); background:rgba(255,255,255,.04); color:#cbd5e1; font-family:inherit; font-size:13px; font-weight:800; transition:all .25s; }
.px-jp i { width:10px; height:10px; border-radius:50%; background:linear-gradient(135deg,#f59e0b,#9333ea); }
.px-jp b { font-size:11.5px; padding:2px 8px; border-radius:50px; background:rgba(255,255,255,.08); }
.px-jp.on { color:#fff; border-color:transparent; background:linear-gradient(90deg,#b45309,#9333ea); box-shadow:0 6px 20px rgba(147,51,234,.35); }
.px-jp.on i { background:#fff; }

.table-card { background:none !important; border:none !important; box-shadow:none !important; padding:0 !important; }
.brand-block { padding:0 18px 14px; border-radius:22px; border:1px solid rgba(255,255,255,.07); box-shadow:0 10px 36px rgba(0,0,0,.4);
               background:linear-gradient(180deg, rgba(15,23,42,.94), rgba(10,16,32,.94)); margin-bottom:18px !important;
               scroll-margin-top:calc(var(--jb-h,56px) + 10px); transition:opacity .6s ease, transform .6s cubic-bezier(.22,1,.36,1); }
.brand-block.rv-wait { opacity:0; transform:translateY(22px); }
.brand-divider { position:sticky !important; top:var(--jb-h,56px); z-index:40; margin:0 -18px 12px !important; border-radius:22px 22px 0 0 !important;
                 background:linear-gradient(90deg, rgba(245,158,11,.14), #0c1426 72%) !important; backdrop-filter:blur(14px); border-width:0 0 1px !important; }
.px-strip { display:flex; align-items:center; flex-shrink:0; }
.px-strip span { width:58px; height:40px; border-radius:11px; overflow:hidden; margin-inline-start:-14px; border:2px solid #0c1426;
                 background:radial-gradient(ellipse 85% 60% at 50% 104%, #cbd5e1, transparent 72%), linear-gradient(180deg,#fff,#e2e8f0); box-shadow:0 4px 12px rgba(0,0,0,.4); }
.px-strip span:first-child { margin-inline-start:0; }
.px-strip img { width:100%; height:100%; object-fit:contain; padding:4px; mix-blend-mode:multiply; }

.price-official { font-size:16px; font-variant-numeric:tabular-nums; letter-spacing:.01em; }
.price-official .px-cur { font-size:11px; font-weight:700; color:#86efac; opacity:.75; margin-inline-start:4px; }

.px-stock { display:inline-flex; align-items:center; gap:5px; margin-top:6px; height:22px; padding:0 9px; border-radius:50px; font-size:11px; font-weight:900; cursor:default; white-space:nowrap; }
.px-stock.has { background:rgba(34,197,94,.13); color:#4ade80; border:1px solid rgba(34,197,94,.28); }
.px-stock.none { background:rgba(255,255,255,.04); color:#64748b; border:1px solid rgba(255,255,255,.07); }
tr.px-dim > td { opacity:.5; transition:opacity .2s; } tr.px-dim:hover > td { opacity:1; }
.px-chg { display:inline-flex; align-items:center; gap:3px; margin-top:6px; height:21px; padding:0 8px; border-radius:50px; font-size:10.5px; font-weight:900; white-space:nowrap; cursor:default; }
.px-chg.up { background:rgba(239,68,68,.13); color:#f87171; } .px-chg.down { background:rgba(34,197,94,.13); color:#4ade80; }
.px-tools { display:inline-flex; gap:5px; margin-top:6px; margin-inline-start:6px; vertical-align:middle; }
.px-tools button { width:26px; height:24px; border-radius:8px; border:1px solid rgba(255,255,255,.1); background:rgba(255,255,255,.04); cursor:pointer; font-size:12px; padding:0; transition:background .2s; }
.px-tools button:hover { background:rgba(255,255,255,.12); }
.px-fresh { display:inline-block; width:9px; height:9px; border-radius:50%; margin-inline-end:6px; vertical-align:middle; }
.px-fresh.g { background:#22c55e; box-shadow:0 0 0 3px rgba(34,197,94,.18); }
.px-fresh.a { background:#f59e0b; box-shadow:0 0 0 3px rgba(245,158,11,.18); }
.px-fresh.r { background:#ef4444; box-shadow:0 0 0 3px rgba(239,68,68,.2); animation:pxPulse 2.2s ease-in-out infinite; }
@keyframes pxPulse { 50% { box-shadow:0 0 0 6px rgba(239,68,68,0); } }
.px-open { cursor:pointer; } .px-open:hover .model-cell { color:#c4b5fd; text-decoration:underline; text-underline-offset:3px; }
/* while a row is being edited, the extras step aside */
tr:has(.price-input-wrap.active) .px-stock, tr:has(.price-input-wrap.active) .px-chg, tr:has(.price-input-wrap.active) .px-tools { display:none !important; }
.drag-mode-active .px-tools, .drag-mode-active .px-chg { display:none !important; }

/* model card */
.pc-ov { position:fixed; inset:0; z-index:500; background:rgba(2,6,23,.75); backdrop-filter:blur(6px); display:none; align-items:center; justify-content:center; padding:18px; }
.pc-ov.on { display:flex; animation:pcF .2s ease both; } @keyframes pcF { from { opacity:0; } }
.pc { width:100%; max-width:560px; max-height:92vh; overflow-y:auto; background:#0a1020; border:1px solid rgba(245,158,11,.28); border-radius:24px; box-shadow:0 30px 90px rgba(0,0,0,.75); color:#f1f5f9; position:relative; animation:pcI .32s cubic-bezier(.22,1,.36,1) both; }
@keyframes pcI { from { opacity:0; transform:translateY(14px) scale(.97); } }
.pc-x { position:absolute; top:12px; inset-inline-end:12px; z-index:5; width:34px; height:34px; border-radius:11px; border:1px solid rgba(255,255,255,.15); background:rgba(2,6,23,.72); color:#e2e8f0; cursor:pointer; font-family:inherit; }
.pc-stage { position:relative; height:180px; overflow:hidden; isolation:isolate; border-radius:24px 24px 0 0;
    background:radial-gradient(ellipse 70% 95% at 50% 112%, rgba(245,158,11,.28), transparent 70%), linear-gradient(180deg,#101b33,#0a1222); }
.pc-stage::before { content:''; position:absolute; left:20%; right:20%; bottom:14px; height:14px; background:radial-gradient(ellipse at center, rgba(0,0,0,.6), transparent 70%); }
.pc-stage img { position:relative; z-index:1; display:block; width:100%; height:100%; padding:30px 30px 18px; object-fit:contain; object-position:center 88%; opacity:0; transition:opacity .4s; }
.pc-stage.ready img { opacity:1; }
.pc-stage.studio { background:radial-gradient(ellipse 85% 60% at 50% 104%, #cbd5e1, transparent 72%), linear-gradient(180deg,#fff,#eef2f7 62%,#e2e8f0); }
.pc-stage.studio img { mix-blend-mode:multiply; } .pc-stage.scene img { object-fit:cover; padding:0; } .pc-stage.scene::before { display:none; }
.pc-stage.matte { background:var(--stage-bg,#0a1222); }
.pc-ph { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:64px; opacity:.25; }
.pc-b { padding:16px 18px 20px; }
.pc-t { font-size:22px; font-weight:900; } .pc-s { font-size:13px; color:#94a3b8; font-weight:700; margin-top:3px; }
.pc-badges { display:flex; flex-wrap:wrap; gap:7px; margin-top:10px; }
.pc-badges span { height:26px; padding:0 11px; border-radius:50px; font-size:12px; font-weight:900; display:inline-flex; align-items:center; gap:5px; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:#cbd5e1; }
.pc-h { font-size:12px; font-weight:900; color:#94a3b8; margin:16px 0 8px; display:flex; align-items:center; gap:8px; }
.pc-h::after { content:''; flex:1; height:1px; background:rgba(255,255,255,.07); }
.pc-years { display:flex; flex-direction:column; gap:8px; }
.pc-y { background:rgba(15,23,42,.9); border:1px solid rgba(255,255,255,.07); border-radius:14px; padding:11px 13px; }
.pc-y-top { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
.pc-y-yr { font-size:13px; font-weight:900; color:#c4b5fd; }
.pc-y-off { font-size:20px; font-weight:900; color:#22c55e; font-variant-numeric:tabular-nums; }
.pc-y-off small { font-size:11px; color:#86efac; opacity:.75; margin-inline-start:4px; }
.pc-y-row { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; align-items:center; font-size:12px; color:#cbd5e1; font-weight:700; }
.pc-tag { height:24px; padding:0 10px; border-radius:50px; display:inline-flex; align-items:center; gap:4px; font-size:11.5px; font-weight:900; }
.pc-tag.disc { background:rgba(245,158,11,.14); color:#fbbf24; } .pc-tag.offer { background:rgba(56,189,248,.14); color:#7dd3fc; } .pc-tag.plain { background:rgba(255,255,255,.06); color:#cbd5e1; }
.pc-tag.stock { background:rgba(34,197,94,.13); color:#4ade80; } .pc-tag.nost { background:rgba(255,255,255,.05); color:#64748b; }
.pc-note { margin-top:8px; font-size:12px; color:#cbd5e1; background:rgba(147,51,234,.08); border-radius:10px; padding:7px 10px; }
.pc-tl { display:flex; flex-direction:column; gap:0; }
.pc-tl-i { display:grid; grid-template-columns:16px 1fr; gap:10px; }
.pc-tl-i i { width:10px; height:10px; border-radius:50%; margin:5px 3px 0; background:#9333ea; position:relative; }
.pc-tl-i i::after { content:''; position:absolute; left:4px; top:12px; width:2px; height:34px; background:rgba(255,255,255,.08); }
.pc-tl-i:last-child i::after { display:none; }
.pc-tl-i.up i { background:#ef4444; } .pc-tl-i.down i { background:#22c55e; } .pc-tl-i.create i { background:#38bdf8; } .pc-tl-i.delete i { background:#64748b; }
.pc-tl-b { padding-bottom:12px; font-size:12.5px; font-weight:700; color:#e2e8f0; }
.pc-tl-b span { display:block; font-size:11px; color:#64748b; margin-top:2px; }
.pc-actions { margin-top:14px; display:flex; gap:8px; flex-wrap:wrap; }
.pc-actions a { flex:1; min-width:140px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center; text-decoration:none; font-size:13px; font-weight:800; color:#fff; background:#7c3aed; }
.px-toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(20px); z-index:600; background:#14532d; color:#dcfce7; font-size:13px; font-weight:800;
            padding:10px 18px; border-radius:50px; opacity:0; transition:all .25s; pointer-events:none; }
.px-toast.on { opacity:1; transform:translateX(-50%); }

@media (max-width:900px) {
    .px-kpis { grid-template-columns:repeat(5,minmax(0,1fr)); gap:7px; }
    .px-k.ring { grid-column:1 / -1; padding:12px 16px; }
    .px-k.ring svg { width:62px; height:62px; }
    .px-k.ring .px-pct { font-size:26px; }
    .px-k:not(.ring) { padding:12px 6px 10px; border-radius:15px; text-align:center; }
    .px-k .n { font-size:24px; }
    .px-k .l { font-size:10px; line-height:1.35; margin-top:5px; }
    .brand-block { padding:0 10px 10px; }
    .brand-divider { margin:0 -10px 10px !important; flex-wrap:wrap; }
    .px-jump { margin:0 -16px 14px; padding:9px 14px; }
    .px-strip span { width:46px; height:32px; }
}
@media (max-width:600px) {
    .pc-ov { align-items:flex-end; padding:0; }
    .pc { max-width:none; border-radius:24px 24px 0 0; animation:pcU .34s cubic-bezier(.22,1,.36,1) both; }
    @keyframes pcU { from { transform:translateY(100%); } }
}
@media (prefers-reduced-motion: reduce) { .brand-block.rv-wait { opacity:1; transform:none; } .px-fresh.r { animation:none; } }
</style>

<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs><linearGradient id="pxGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#22c55e"/><stop offset="1" stop-color="#a855f7"/></linearGradient></defs></svg>
<div class="pc-ov" id="pcOv" aria-hidden="true"><div class="pc" id="pc"><button type="button" class="pc-x" id="pcX">✕</button><div id="pcBody"></div></div></div>
<div class="px-toast" id="pxToast"></div>

<script>
(function () {
    const PX = <?= json_encode($pxData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const PXB = <?= json_encode($pxBrands, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const AR = <?= json_encode($lang === 'ar') ?>, LANG = <?= json_encode($lang) ?>;
    const SALES = <?= $isSales ? 'true' : 'false' ?>, CAN_HIST = <?= $pxCanHist ? 'true' : 'false' ?>;
    const CUR = <?= json_encode($t[$lang]['currency'], JSON_UNESCAPED_UNICODE) ?>;
    const T = AR ? {
        models:'إجمالي الموديلات', priced:'مسعّرة', unpriced:'بدون سعر', offers:'عروض أوفر', discs:'خصومات', week:'تحدّثت هذا الأسبوع',
        ofPriced:'من الموديلات لها سعر', all:'الكل', allBrands:'كل الماركات', cDisc:'فيها خصم', cOffer:'فيها أوفر', cUnp:'بدون سعر',
        cStock:'متوفرة في المخزون', cWeek:'تغيّرت هذا الأسبوع', inStock:'في المخزون', noStock:'غير متوفرة',
        daysAgo:d=>d===0?'اليوم':(d===1?'أمس':'منذ '+d+' يوم'), fresh:'حُدّث', copy:'نسخ السعر', copied:'✓ تم النسخ',
        official:'السعر الرسمي', cust:'العميل', trade:'التاجر', years:'الأسعار حسب السنة', history:'آخر تغييرات السعر', byBranch:'المخزون حسب الفرع',
        noHist:'لا توجد تغييرات مسجلة خلال آخر ٦ أشهر', t_create:'تسعير جديد', t_update:'تعديل', t_delete:'حذف سنة', histPage:'📈 سجل الأسعار الكامل',
        by:'بواسطة', stockAll:'سيارة في المخزون', none:'—', changed:'تغيّر'
    } : {
        models:'Total models', priced:'Priced', unpriced:'Unpriced', offers:'Offers', discs:'Discounts', week:'Updated this week',
        ofPriced:'of models are priced', all:'All', allBrands:'All brands', cDisc:'Has a discount', cOffer:'Has an offer', cUnp:'Unpriced',
        cStock:'In stock', cWeek:'Changed this week', inStock:'in stock', noStock:'none in stock',
        daysAgo:d=>d===0?'today':(d===1?'yesterday':d+' days ago'), fresh:'Updated', copy:'Copy price', copied:'✓ Copied',
        official:'Official', cust:'Customer', trade:'Trade', years:'Prices by year', history:'Recent price changes', byBranch:'Stock by branch',
        noHist:'No changes recorded in the last 6 months', t_create:'Newly priced', t_update:'Changed', t_delete:'Year removed', histPage:'📈 Full price history',
        by:'by', stockAll:'cars in stock', none:'—', changed:'changed'
    };
    const esc = v => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
    const $ = id => document.getElementById(id);
    const form = document.querySelector('.filters-card');
    const blocks = Array.from(document.querySelectorAll('.brand-block'));
    const freshCls = a => a == null ? '' : a <= 7 ? 'g' : a <= 30 ? 'a' : 'r';
    function toast(m) { const t = $('pxToast'); t.textContent = m; t.classList.add('on'); clearTimeout(toast._t); toast._t = setTimeout(() => t.classList.remove('on'), 1500); }

    /* ═══ currency in small type beside the big number ═══ */
    function styleOfficial(el) {
        if (!el || el.querySelector('.px-cur')) return;
        const t = el.textContent.trim(), i = t.lastIndexOf(' ');
        if (i > 0) el.innerHTML = esc(t.slice(0, i)) + '<span class="px-cur">' + esc(t.slice(i + 1)) + '</span>';
    }
    document.querySelectorAll('.price-official').forEach(styleOfficial);
    new MutationObserver(ms => ms.forEach(m => m.target.querySelectorAll && m.target.querySelectorAll('.price-official').forEach(styleOfficial)))
        .observe(document.querySelector('.table-card') || document.body, { childList: true, subtree: true });

    /* ═══ decorate each price line ═══ */
    const rows = Array.from(document.querySelectorAll('.pricing-table tr[data-trim-key]'));
    function yearOf(tr) {
        const md = document.getElementById('meta-data-' + tr.id.replace('row-', ''));
        if (md && md.dataset.year) return md.dataset.year;
        const b = tr.querySelector('.year-badge'); return b ? b.textContent.replace(/[^0-9]/g, '') : '';
    }
    rows.forEach(tr => {
        const id = tr.id.replace('row-', ''), d = PX[tr.dataset.trimKey]; if (!d) return;
        const y = yearOf(tr), yd = d.years[y];
        tr._px = { key: tr.dataset.trimKey, year: y, yd: yd };

        // tap the model name to open its card
        const first = tr.querySelector('.model-cell, .trim-cell');
        if (first) { const td = first.closest('td'); td.classList.add('px-open'); td.addEventListener('click', () => openCard(tr.dataset.trimKey)); }
        if (!yd) return;

        // stock right now, under the year
        const yearTd = (document.getElementById('disp-year-' + id) || {}).parentElement;
        if (yearTd) {
            const sp = document.createElement('div');
            sp.innerHTML = '<span class="px-stock ' + (yd.stock ? 'has' : 'none') + '" title="' + esc(Object.entries(yd.br || {}).map(([b, n]) => b + ': ' + n).join(' · ')) + '">🚗 ' +
                           (yd.stock ? yd.stock + ' ' + esc(T.inStock) : esc(T.noStock)) + '</span>';
            yearTd.appendChild(sp.firstChild);
            if (!yd.stock) tr.classList.add('px-dim');
        }
        // change arrow + copy / share, under the official price
        const offTd = (document.getElementById('disp-off-' + id) || {}).parentElement;
        if (offTd && yd.off) {
            const box = document.createElement('div');
            let h = '';
            h += '<span class="px-tools"><button type="button" data-a="copy" title="' + esc(T.copy) + '">📋</button></span>';
            box.innerHTML = h;
            offTd.appendChild(box);
            box.querySelectorAll('button').forEach(b => b.addEventListener('click', ev => { ev.stopPropagation(); share(d, y, yd, b.dataset.a); }));
        }
        // freshness dot beside "last updated"
        const meta = document.getElementById('meta-' + id);
        if (meta && yd.age != null) {
            const dot = document.createElement('span');
            dot.className = 'px-fresh ' + freshCls(yd.age);
            dot.title = T.fresh + ' ' + T.daysAgo(yd.age);
            meta.parentElement.insertBefore(dot, meta);
            // a save rewrites that cell: the price is fresh again
            new MutationObserver(() => { dot.className = 'px-fresh g'; yd.age = 0; }).observe(meta, { childList: true });
        }
    });

    function shareText(d, y, yd) {
        const lines = ['🚗 ' + [d.brand, d.model, d.trim, y].filter(Boolean).join(' '),
                       '💰 ' + T.official + ': ' + yd.off + ' ' + CUR];
        if (yd.cust) lines.push('🏷️ ' + yd.cust);   // the customer label exactly as written, never recalculated
        lines.push('First 1 Car');
        return lines.join('\n');
    }
    function share(d, y, yd, how) {
        const txt = shareText(d, y, yd);
        const done = () => toast(T.copied);
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(txt).then(done, done);
        else { const ta = document.createElement('textarea'); ta.value = txt; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch (e) {} ta.remove(); done(); }
    }

    /* ═══ brand headers: model photos instead of initials ═══ */
    blocks.forEach(bl => {
        const imgs = PXB[bl.dataset.brand] || [];
        const ph = bl.querySelector('.brand-logo-placeholder');
        if (imgs.length && ph) {
            const strip = document.createElement('div'); strip.className = 'px-strip';
            strip.innerHTML = imgs.map(u => '<span><img src="' + esc(u) + '" alt="" loading="lazy"></span>').join('');
            ph.replaceWith(strip);
        }
    });

    /* ═══ headline numbers ═══ */
    const kp = document.createElement('div'); kp.className = 'px-kpis';
    kp.innerHTML =
        '<div class="px-k ring"><svg width="78" height="78" viewBox="0 0 78 78"><circle class="bg" cx="39" cy="39" r="32" fill="none" stroke-width="9"/><circle class="fg" id="pxRing" cx="39" cy="39" r="32" fill="none" stroke-width="9" stroke-dasharray="201.1" stroke-dashoffset="201.1"/></svg>' +
        '<div><div class="px-pct" id="pxPct">0%</div><div class="sub">' + esc(T.ofPriced) + '</div></div></div>' +
        '<div class="px-k tot"><div class="n" data-k="tot">0</div><div class="l">📦 ' + esc(T.models) + '</div></div>' +
        '<div class="px-k unp tap" data-chip="unp"><div class="n" data-k="unp">0</div><div class="l">⚠️ ' + esc(T.unpriced) + '</div></div>' +
        '<div class="px-k off tap" data-chip="offer"><div class="n" data-k="off">0</div><div class="l">⬆️ ' + esc(T.offers) + '</div></div>' +
        '<div class="px-k dis tap" data-chip="disc"><div class="n" data-k="dis">0</div><div class="l">⬇️ ' + esc(T.discs) + '</div></div>' +
        '<div class="px-k wk tap" data-chip="week"><div class="n" data-k="wk">0</div><div class="l">🔄 ' + esc(T.week) + '</div></div>';
    const statsRow = document.querySelector('.stats-row');
    if (statsRow) statsRow.parentNode.insertBefore(kp, statsRow);
    const shown = {};
    function countTo(k, v) {
        const el = kp.querySelector('[data-k="' + k + '"]'); if (!el) return;
        const from = shown[k] || 0; shown[k] = v; const t0 = performance.now(), dur = from ? 450 : 1100;
        (function step(t) { const p = Math.min(1, (t - t0) / dur); el.textContent = Math.round(from + (v - from) * (1 - Math.pow(1 - p, 3))); if (p < 1) requestAnimationFrame(step); })(t0);
    }

    /* ═══ chips ═══ */
    const chipDefs = [['', T.all], ['disc', '⬇️ ' + T.cDisc], ['offer', '⬆️ ' + T.cOffer], ['unp', '⚠️ ' + T.cUnp],
                      ['stock', '🚗 ' + T.cStock], ['week', '🔄 ' + T.cWeek]];
    const chips = document.createElement('div'); chips.className = 'px-chips';
    chips.innerHTML = chipDefs.map(([k, l]) => '<button type="button" class="px-chip' + (k === '' ? ' on' : '') + '" data-c="' + k + '">' + esc(l) + ' <b data-n="' + k + '"></b></button>').join('');
    if (form) form.appendChild(chips);
    let chip = '';
    chips.querySelectorAll('.px-chip').forEach(b => b.addEventListener('click', () => { chip = chip === b.dataset.c ? '' : b.dataset.c; apply(); }));
    kp.querySelectorAll('.tap').forEach(k => k.addEventListener('click', () => { chip = chip === k.dataset.chip ? '' : k.dataset.chip; apply(); if (form) form.scrollIntoView({ behavior: 'smooth', block: 'start' }); }));

    /* ═══ the live filter: search (model or trim) + brand, as before, plus the chips ═══ */
    function trimYears(key) { return Object.values((PX[key] || {}).years || {}); }
    function passChip(key, c) {
        const ys = trimYears(key);
        switch (c) {
            case 'disc':  return ys.some(y => y.ck === 'disc' || y.tk === 'disc');
            case 'offer': return ys.some(y => y.ck === 'offer' || y.tk === 'offer');
            case 'unp':   return ys.length === 0;
            case 'stock': return ys.some(y => y.stock > 0);
            case 'week':  return ys.some(y => y.wk);
            default:      return true;
        }
    }
    function apply(silent) {
        const q = form && form.elements.search ? form.elements.search.value.trim().toLowerCase() : '';
        const br = form && form.elements.brand ? form.elements.brand.value : '';
        const groups = {};
        rows.forEach(tr => (groups[tr.dataset.trimKey] = groups[tr.dataset.trimKey] || []).push(tr));
        const counts = { '': 0, disc: 0, offer: 0, unp: 0, stock: 0, week: 0 };
        let vis = 0, pricedN = 0, offN = 0, disN = 0, wkN = 0;
        blocks.forEach(bl => {
            let n = 0;
            const keys = [...new Set(Array.from(bl.querySelectorAll('tr[data-trim-key]')).map(t => t.dataset.trimKey))];
            keys.forEach(k => {
                const d = PX[k] || { model: '', trim: '', brand: '' };
                const base = (!br || d.brand === br) && (!q || (d.model + ' ' + d.trim).toLowerCase().indexOf(q) !== -1);
                if (base) Object.keys(counts).forEach(c => { if (passChip(k, c)) counts[c]++; });
                const ok = base && passChip(k, chip);
                (groups[k] || []).forEach(tr => tr.style.display = ok ? '' : 'none');
                if (ok) {
                    n++; vis++;
                    const ys = trimYears(k);
                    if (ys.length) pricedN++;
                    ys.forEach(y => { if (y.ck === 'offer' || y.tk === 'offer') offN++; if (y.ck === 'disc' || y.tk === 'disc') disN++; if (y.wk) wkN++; });
                }
            });
            bl.style.display = n ? '' : 'none';
            bl._n = n;
            const cp = bl.querySelector('.brand-count-pill'); if (cp) cp.firstChild.nodeValue = n + ' ';
        });
        countTo('tot', vis); countTo('unp', vis - pricedN); countTo('off', offN); countTo('dis', disN); countTo('wk', wkN);
        const pct = vis ? Math.round(pricedN * 100 / vis) : 0;
        $('pxPct').textContent = pct + '%';
        requestAnimationFrame(() => $('pxRing').setAttribute('stroke-dashoffset', (201.1 * (1 - pct / 100)).toFixed(1)));
        chips.querySelectorAll('.px-chip').forEach(b => { b.classList.toggle('on', b.dataset.c === chip); const nb = b.querySelector('b'); if (nb) nb.textContent = b.dataset.c ? counts[b.dataset.c] : ''; });
        kp.querySelectorAll('.tap').forEach(k => k.classList.toggle('on', k.dataset.chip === chip));
        buildJump();
        if (!silent) try {
            const p = new URLSearchParams(location.search);
            q ? p.set('search', form.elements.search.value.trim()) : p.delete('search');
            br ? p.set('brand', br) : p.delete('brand');
            history.replaceState(null, '', location.pathname + '?' + p.toString());
        } catch (e) {}
    }
    if (form) {
        let tm = null;
        form.addEventListener('submit', e => { e.preventDefault(); apply(); });
        if (form.elements.search) form.elements.search.addEventListener('input', () => { clearTimeout(tm); tm = setTimeout(apply, 110); });
        if (form.elements.brand) form.elements.brand.addEventListener('change', () => apply());
    }

    /* reordering works on the whole list, so it starts from an unfiltered view */
    if (typeof window.enterDragMode === 'function') {
        const orig = window.enterDragMode;
        window.enterDragMode = function () {
            chip = '';
            if (form && form.elements.search) form.elements.search.value = '';
            if (form && form.elements.brand) form.elements.brand.value = '';
            apply();
            return orig.apply(this, arguments);
        };
    }

    /* ═══ brand jump bar + scroll tracking ═══ */
    const jump = document.createElement('nav'); jump.className = 'px-jump';
    const tableCard = document.querySelector('.table-card');
    if (tableCard) tableCard.parentNode.insertBefore(jump, tableCard);
    function buildJump() {
        jump.innerHTML = blocks.filter(b => b._n).map(b => '<button type="button" class="px-jp" data-b="' + esc(b.dataset.brand) + '"><i></i>' + esc(b.dataset.brand) + ' <b>' + b._n + '</b></button>').join('');
        jump.querySelectorAll('.px-jp').forEach(p => p.addEventListener('click', () => {
            const bl = blocks.find(b => b.dataset.brand === p.dataset.b); if (!bl) return;
            jump._hold = { b: p.dataset.b, until: Date.now() + 900 }; spy();
            bl.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }));
        measure(); spy();
    }
    function spy() {
        const line = jump.offsetHeight + 90, vis = blocks.filter(b => b.style.display !== 'none');
        let cur = vis[0];
        vis.forEach(b => { if (b.getBoundingClientRect().top <= line) cur = b; });
        if (innerHeight + scrollY >= document.documentElement.scrollHeight - 4 && vis.length) cur = vis[vis.length - 1];
        if (jump._hold && Date.now() < jump._hold.until) cur = blocks.find(b => b.dataset.brand === jump._hold.b) || cur;
        jump.querySelectorAll('.px-jp').forEach(p => {
            const on = cur && p.dataset.b === cur.dataset.brand;
            if (on && !p.classList.contains('on')) jump.scrollTo({ left: p.offsetLeft - jump.clientWidth / 2 + p.offsetWidth / 2, behavior: 'smooth' });
            p.classList.toggle('on', !!on);
        });
    }
    function measure() { document.documentElement.style.setProperty('--jb-h', (jump.offsetHeight || 0) + 'px'); }
    let st = null;
    addEventListener('scroll', () => { if (st) return; st = requestAnimationFrame(() => { st = null; spy(); }); }, { passive: true });
    addEventListener('resize', measure);

    /* ═══ entrance ═══ */
    if ('IntersectionObserver' in window && !(matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches)) {
        let qn = 0;
        const io = new IntersectionObserver(en => en.forEach(e => { if (!e.isIntersecting) return; io.unobserve(e.target); setTimeout(() => e.target.classList.remove('rv-wait'), (qn++ % 6) * 90); }), { rootMargin: '0px 0px -40px 0px' });
        blocks.forEach(b => { b.classList.add('rv-wait'); io.observe(b); });
    }

    /* ═══ the model card ═══ */
    function openCard(key) {
        const d = PX[key]; if (!d) return;
        const years = Object.entries(d.years);
        const stockTotal = years.reduce((a, [, y]) => a + (y.stock || 0), 0);
        const brAll = {}; years.forEach(([, y]) => Object.entries(y.br || {}).forEach(([b, n]) => brAll[b] = (brAll[b] || 0) + n));
        const tag = v => { if (!v) return '<span class="pc-tag plain">' + T.none + '</span>'; const k = (/خصم|discount/i.test(v)) ? 'disc' : (/أوفر|offer/i.test(v) ? 'offer' : 'plain'); return '<span class="pc-tag ' + k + '">' + esc(v) + '</span>'; };
        let h = '<div class="pc-stage" id="pcStage">' + (d.img ? '<img id="pcImg" src="' + esc(d.img) + '" alt="">' : '<div class="pc-ph">🚗</div>') + '</div><div class="pc-b">';
        h += '<div class="pc-t">' + esc(d.brand + ' ' + d.model) + '</div><div class="pc-s">' + esc(d.trim) + '</div>';
        h += '<div class="pc-badges"><span>🚗 ' + stockTotal + ' ' + esc(T.stockAll) + '</span>' + (years.length ? '' : '<span style="color:#f87171">⚠️ ' + esc(T.unpriced) + '</span>') + '</div>';
        if (years.length) {
            h += '<div class="pc-h">' + esc(T.years) + '</div><div class="pc-years">';
            years.sort((a, b) => b[0].localeCompare(a[0])).forEach(([yr, y]) => {
                h += '<div class="pc-y"><div class="pc-y-top"><span class="pc-y-yr">📅 ' + esc(yr) + '</span><span class="pc-y-off">' + (y.off ? esc(y.off) + '<small>' + esc(CUR) + '</small>' : T.none) + '</span></div>';
                h += '<div class="pc-y-row">' + esc(T.cust) + ': ' + tag(y.cust) + (SALES ? '' : ' &nbsp;' + esc(T.trade) + ': ' + tag(y.trade)) + '</div>';
                h += '<div class="pc-y-row"><span class="pc-tag ' + (y.stock ? 'stock' : 'nost') + '">🚗 ' + (y.stock ? y.stock + ' ' + esc(T.inStock) : esc(T.noStock)) + '</span>' +
                     (y.age != null ? '<span><span class="px-fresh ' + freshCls(y.age) + '"></span>' + esc(T.fresh + ' ' + T.daysAgo(y.age)) + '</span>' : '') +
                     (y.chg ? '<span class="px-chg ' + y.chg.dir + '">' + (y.chg.dir === 'up' ? '▲' : '▼') + ' ' + esc(y.chg.from + ' → ' + y.chg.to) + '</span>' : '') + '</div>';
                if (y.notes) h += '<div class="pc-note">📝 ' + esc(y.notes) + '</div>';
                h += '</div>';
            });
            h += '</div>';
        }
        const brE = Object.entries(brAll);
        if (brE.length) h += '<div class="pc-h">' + esc(T.byBranch) + '</div><div class="pc-badges">' + brE.map(([b, n]) => '<span>📍 ' + esc(b) + ' · ' + n + '</span>').join('') + '</div>';
        h += '<div class="pc-h">' + esc(T.history) + '</div>';
        if (!d.hist.length) h += '<div style="font-size:12px;color:#64748b">' + esc(T.noHist) + '</div>';
        else {
            h += '<div class="pc-tl">';
            d.hist.forEach(e => {
                let cls = e.t, line = T['t_' + e.t] || e.t;
                if (e.t === 'update' && e.o0 !== e.o1 && e.o0 && e.o1 && !isNaN(e.o0) && !isNaN(e.o1)) { cls = (+e.o1 > +e.o0) ? 'up' : 'down'; line += ': ' + (+e.o0).toLocaleString('en-US') + ' → ' + (+e.o1).toLocaleString('en-US'); }
                else if (e.t === 'create' && e.o1) line += ': ' + (+e.o1).toLocaleString('en-US');
                const extra = [];
                if (e.c0 !== e.c1) extra.push(T.cust + ': ' + (e.c0 || T.none) + ' → ' + (e.c1 || T.none));
                if (!SALES && e.d0 !== e.d1) extra.push(T.trade + ': ' + (e.d0 || T.none) + ' → ' + (e.d1 || T.none));
                h += '<div class="pc-tl-i ' + cls + '"><i></i><div class="pc-tl-b">📅 ' + esc(e.y) + ' · ' + esc(line) + (extra.length ? '<span>' + esc(extra.join(' · ')) + '</span>' : '') +
                     '<span>' + esc(e.at.slice(0, 16)) + ' · ' + esc(T.by) + ' ' + esc(e.by) + '</span></div></div>';
            });
            h += '</div>';
        }
        if (CAN_HIST) h += '<div class="pc-actions"><a href="price_history.php?lang=' + LANG + '">' + esc(T.histPage) + '</a></div>';
        h += '</div>';
        $('pcBody').innerHTML = h;
        const img = $('pcImg'); if (img) stage(img, $('pcStage'));
        $('pcOv').classList.add('on'); $('pcOv').setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden';
    }
    function closeCard() { $('pcOv').classList.remove('on'); $('pcOv').setAttribute('aria-hidden', 'true'); document.body.style.overflow = ''; }
    $('pcX').addEventListener('click', closeCard);
    $('pcOv').addEventListener('click', e => { if (e.target.id === 'pcOv') closeCard(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && $('pcOv').classList.contains('on')) closeCard(); });

    /* same showroom stage as the dashboard and stock report */
    function stage(img, st) {
        const PAD = { x: 30, t: 30, b: 18 };
        function go() {
            try {
                const w = 120, h = Math.max(24, Math.round(w * img.naturalHeight / img.naturalWidth));
                const cv = document.createElement('canvas'); cv.width = w; cv.height = h;
                const cx = cv.getContext('2d', { willReadFrequently: true }); cx.drawImage(img, 0, 0, w, h);
                const dd = cx.getImageData(0, 0, w, h).data, at = (x, y) => { const i = (y * w + x) * 4; return [dd[i], dd[i+1], dd[i+2]]; };
                const ring = [];
                for (let x = 1; x < w - 1; x += 5) { ring.push(at(x, 1)); ring.push(at(x, h - 2)); }
                for (let y = 1; y < h - 1; y += 3) { ring.push(at(1, y)); ring.push(at(w - 2, y)); }
                const m = [0,1,2].map(k => ring.reduce((a, p) => a + p[k], 0) / ring.length);
                const spread = Math.sqrt(ring.reduce((a, p) => a + (p[0]-m[0])**2 + (p[1]-m[1])**2 + (p[2]-m[2])**2, 0) / ring.length);
                if (spread > 34) st.classList.add('scene');
                else {
                    if (.299*m[0] + .587*m[1] + .114*m[2] > 226) st.classList.add('studio');
                    else { st.classList.add('matte'); st.style.setProperty('--stage-bg', 'rgb(' + m.map(Math.round).join(',') + ')'); }
                    let x0 = w, y0 = h, x1 = -1, y1 = -1;
                    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) { const p = at(x, y);
                        if (Math.abs(p[0]-m[0]) + Math.abs(p[1]-m[1]) + Math.abs(p[2]-m[2]) > 60) { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; } }
                    const fw = (x1-x0+1)/w, fh = (y1-y0+1)/h;
                    if (x1 > 0 && fw > .08 && fh > .08 && !(fw > .97 && fh > .97)) {
                        const sw = st.clientWidth - PAD.x * 2, sh = st.clientHeight - PAD.t - PAD.b, nw = img.naturalWidth, nh = img.naturalHeight;
                        const cw = (x1+1-x0)/w*nw, ch = (y1+1-y0)/h*nh, k = Math.min(sw/cw, sh/ch);
                        Object.assign(img.style, { position:'absolute', maxWidth:'none', padding:'0', objectFit:'fill', width:(nw*k)+'px', height:(nh*k)+'px',
                            left:(PAD.x + (sw - cw*k)/2 - x0/w*nw*k)+'px', top:(PAD.t + (sh - ch*k) - y0/h*nh*k)+'px' });
                    }
                }
            } catch (e) {}
            st.classList.add('ready');
        }
        img.addEventListener('error', () => { img.remove(); st.insertAdjacentHTML('afterbegin', '<div class="pc-ph">🚗</div>'); }, { once: true });
        if (img.complete && img.naturalWidth) go(); else img.addEventListener('load', go, { once: true });
    }

    apply(true);
    measure();
    addEventListener('load', () => { measure(); spy(); });
})();
</script>
</body>
</html>
