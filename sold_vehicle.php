<?php

require 'auth.php';
require 'config.php';
require_once 'car_images_helpers.php';
require_once 'reserve_helpers.php';
require_once 'sold_helpers.php';

perm_require('page.sold_vehicle');

$lang = $_GET['lang'] ?? 'ar';
if ($lang !== 'en') $lang = 'ar';

/* Form token (same session key the other pages use) */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

/* ─── Has this phone number bought before? (asked while typing) ─── */
if (($_GET['ajax'] ?? '') === 'phone') {
    header('Content-Type: application/json; charset=utf-8');
    $digits = preg_replace('/\D/', '', (string)($_GET['q'] ?? ''));
    $tail   = substr($digits, -10);
    $out    = [];
    if (strlen($tail) >= 9) {
        try {
            $colOk = ensure_sold_revert_columns($pdo);
            $st = $pdo->prepare("
                SELECT sc.customer_name, sc.salesman, sc.sold_at, c.brand, c.model, c.car_year, c.trim_name
                FROM sold_cars sc LEFT JOIN cars c ON c.id = sc.car_id
                WHERE " . sold_active_sql($colOk, 'sc') . "
                  AND REPLACE(REPLACE(REPLACE(REPLACE(sc.customer_phone, ' ', ''), '-', ''), '+', ''), '(', '') LIKE ?
                ORDER BY sc.sold_at DESC LIMIT 5");
            $st->execute(['%' . $tail]);
            $out = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { error_log('sold_vehicle: phone lookup failed: ' . $e->getMessage()); }
    }
    echo json_encode(['hits' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

// Car id passed from the dashboard card (auto-select on load)
$preselectId = (int)($_GET['id'] ?? 0);

/* ─── امانة "mark as sold" entry ───────────────────────────────
   If the admin/manager opened this page for a car currently out on
   امانة (consignment), we pre-fill + LOCK the dealer name and force
   the sale type to "dealer". This closes the consignment as a sale. */
$amanaLock      = false;   // locks the dealer field
$amanaDealer    = '';      // pre-filled trader name
$amanaSalesman  = '';      // pre-filled salesman (locked when closing امانة)
$amanaCar       = null;    // the consignment car row (so it can be sold)
$canAmanaManage = can('amana.manage');

if ($preselectId > 0 && $canAmanaManage) {
    $cStmt = $pdo->prepare("
        SELECT cn.dealer_name, cn.salesman, c.*
        FROM consignments cn
        JOIN cars c ON c.id = cn.car_id
        WHERE cn.car_id = ? AND cn.status = 'active'
        ORDER BY cn.id DESC LIMIT 1
    ");
    $cStmt->execute([$preselectId]);
    if ($row = $cStmt->fetch(PDO::FETCH_ASSOC)) {
        $amanaLock     = true;
        $amanaDealer   = $row['dealer_name'];
        $amanaSalesman = $row['salesman'] ?? '';
        $amanaCar      = $row;
    }
}

$t = [
    'ar' => [
        'title'          => 'بيع سيارة',
        'subtitle'       => 'أتمم صفقتك في ثوانٍ',
        'vehicle'        => 'السيارة',
        'sale_type'      => 'نوع البيع',
        'salesman'       => 'البائع',
        'select_salesman'=> 'اختر البائع',
        'customer'       => 'عميل مباشر',
        'dealer'         => 'تاجر',
        'deal_kind'      => 'نوع التعامل مع التاجر',
        'paid'           => 'مدفوعة',
        'amana'          => 'امانة',
        'paid_hint'      => 'بيع عادي ونهائي',
        'amana_hint'     => 'السيارة تخرج أمانة وتعود للوحة بلون مميز',
        'amana_success'  => 'تم تسجيل السيارة كأمانة بنجاح! 🔶',
        'customer_name'  => 'اسم العميل',
        'customer_phone' => 'رقم الهاتف',
        'dealer_name'    => 'اسم التاجر',
        'notes'          => 'ملاحظات إضافية',
        'sell'           => 'إتمام البيع',
        'success'        => 'تم بيع السيارة بنجاح! 🎉',
        'select_vehicle' => '— اختر السيارة —',
        'back'           => 'الرئيسية',
        'vehicle_details'=> 'تفاصيل السيارة',
        'brand'          => 'الماركة',
        'model'          => 'الموديل',
        'trim'           => 'الفئة',
        'color'          => 'اللون',
        'branch'         => 'الفرع',
        'chassis'        => 'رقم الشاسيه',
        'step1'          => 'اختر السيارة',
        'step2'          => 'بيانات البيع',
        'step3'          => 'تأكيد',
        'available'      => 'متاحة',
        'sale_info'      => 'معلومات البيع',
        'no_cars'        => 'لا توجد سيارات متاحة',
        'amana_group'    => '🔶 سيارات الأمانة (اضغط للبيع)',
        'search_car'     => 'ابحث بالماركة أو الشاسيه...',
        'required'       => 'هذا الحقل مطلوب',
        'lang_switch'    => 'English',
        'sold_by'        => 'تم البيع بواسطة',
        'token_expired'  => 'انتهت صلاحية الصفحة — حدّث الصفحة وحاول مرة أخرى',
        'err_car'        => 'اختر السيارة أولاً',
        'err_type'       => 'اختر نوع البيع',
        'err_salesman'   => 'اختر البائع',
        'err_customer'   => 'اكتب اسم العميل ورقم هاتف صحيح',
        'err_dealer'     => 'اكتب اسم التاجر',
        'err_already_sold'=> 'هذه السيارة بيعت بالفعل — البائع: %s · %s',
        'err_on_amana'   => 'هذه السيارة خارج أمانة الآن ولا يمكن بيعها من هنا',
        'err_gone'       => 'هذه السيارة لم تعد متاحة للبيع',
        'err_db'         => 'حدث خطأ أثناء الحفظ — لم يتم تسجيل البيع، حاول مرة أخرى',
    ],
    'en' => [
        'title'          => 'Sell Vehicle',
        'subtitle'       => 'Close your deal in seconds',
        'vehicle'        => 'Vehicle',
        'sale_type'      => 'Sale Type',
        'salesman'       => 'Salesman',
        'select_salesman'=> 'Select Salesman',
        'customer'       => 'Direct Customer',
        'dealer'         => 'Dealer',
        'deal_kind'      => 'Dealer Deal Type',
        'paid'           => 'Paid',
        'amana'          => 'Consignment',
        'paid_hint'      => 'Normal final sale',
        'amana_hint'     => 'Car goes out on consignment, returns to board in a special color',
        'amana_success'  => 'Vehicle registered as consignment! 🔶',
        'customer_name'  => 'Customer Name',
        'customer_phone' => 'Phone Number',
        'dealer_name'    => 'Dealer Name',
        'notes'          => 'Additional Notes',
        'sell'           => 'Complete Sale',
        'success'        => 'Vehicle sold successfully! 🎉',
        'select_vehicle' => '— Select Vehicle —',
        'back'           => 'Dashboard',
        'vehicle_details'=> 'Vehicle Details',
        'brand'          => 'Brand',
        'model'          => 'Model',
        'trim'           => 'Trim',
        'color'          => 'Color',
        'branch'         => 'Branch',
        'chassis'        => 'Chassis No.',
        'step1'          => 'Pick Vehicle',
        'step2'          => 'Sale Info',
        'step3'          => 'Confirm',
        'available'      => 'Available',
        'sale_info'      => 'Sale Information',
        'no_cars'        => 'No vehicles available',
        'amana_group'    => '🔶 Consignment cars (tap to sell)',
        'search_car'     => 'Search by brand or chassis...',
        'required'       => 'This field is required',
        'lang_switch'    => 'عربي',
        'sold_by'        => 'Sold by',
        'token_expired'  => 'This page expired — refresh it and try again',
        'err_car'        => 'Pick the vehicle first',
        'err_type'       => 'Choose the sale type',
        'err_salesman'   => 'Choose the salesman',
        'err_customer'   => 'Enter the customer name and a valid phone number',
        'err_dealer'     => 'Enter the dealer name',
        'err_already_sold'=> 'This vehicle is already sold — salesman: %s · %s',
        'err_on_amana'   => 'This vehicle is out on consignment and cannot be sold from here',
        'err_gone'       => 'This vehicle is no longer available',
        'err_db'         => 'Something went wrong while saving — the sale was NOT recorded, please try again',
    ],
];

$success = '';
$error   = '';

$cars = $pdo->query("
    SELECT
        cars.*,
        colors.color_ar,
        colors.color_en,
        branches.name_ar,
        branches.name_en
    FROM cars
    LEFT JOIN colors   ON cars.color  = colors.color_en
    LEFT JOIN branches ON cars.branch = branches.name
    WHERE cars.status IN ('available','reserved')
    ORDER BY cars.brand, cars.model
")->fetchAll(PDO::FETCH_ASSOC);

/* ─── امانة cars available to close as a sale (admin/manager only) ─── */
$amanaList = [];
if ($canAmanaManage) {
    $amanaList = $pdo->query("
        SELECT
            cars.*,
            colors.color_ar,
            colors.color_en,
            branches.name_ar,
            branches.name_en,
            cn.dealer_name AS amana_dealer,
            cn.salesman    AS amana_salesman
        FROM cars
        LEFT JOIN colors   ON cars.color  = colors.color_en
        LEFT JOIN branches ON cars.branch = branches.name
        LEFT JOIN consignments cn ON cn.car_id = cars.id AND cn.status = 'active'
        WHERE cars.status = 'consignment'
        ORDER BY cars.brand, cars.model
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Salesmen eligible to be credited with a sale: managers + sales (never admins)
$salesmen = $pdo->query("
    SELECT username FROM users
    WHERE role IN ('manager', 'sales') AND active = 1
    ORDER BY username
")->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $car_id         = (int)  ($_POST['car_id']   ?? 0);
    $sale_type      = trim(  $_POST['sale_type'] ?? '');
    $deal_kind      = trim(  $_POST['deal_kind']      ?? 'paid');  // paid | amana (dealer only)
    $salesman       = trim(  $_POST['salesman']       ?? '');
    $customer_name  = trim(  $_POST['customer_name']  ?? '');
    $customer_phone = trim(  $_POST['customer_phone'] ?? '');
    $dealer_name    = trim(  $_POST['dealer_name']    ?? '');
    $notes          = trim(  $_POST['notes']          ?? '');

    // امانة only applies to dealer sales
    $isAmana = ($sale_type === 'dealer' && $deal_kind === 'amana');

    // Only users with the amana.manage permission may put a car out on امانة
    $canAmana = can('amana.manage');
    if ($isAmana && !$canAmana) {
        $isAmana = false; // safety: silently fall back to normal sale rules
    }

    // Only accept a salesman that's actually in the eligible list (clean data).
    // Exception: when closing an امانة, the salesman is the one who originally
    // took the car out — keep it even if they're no longer in the active list.
    $closingAmana = false;
    if ($canAmana && $car_id > 0) {
        $chk = $pdo->prepare("SELECT salesman FROM consignments WHERE car_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
        $chk->execute([$car_id]);
        if ($crow = $chk->fetch(PDO::FETCH_ASSOC)) {
            $closingAmana = true;
            // Force the original salesman; ignore anything the form sent.
            $salesman = $crow['salesman'] ?? '';
        }
    }
    if (!$closingAmana && $salesman !== '' && !in_array($salesman, $salesmen, true)) {
        $salesman = '';
    }

    // When closing an امانة as a sale: force Dealer sale type, lock the trader
    // name to the consignment's dealer, and never treat it as a NEW امانة.
    // This enforces the UI locks server-side (can't be bypassed via POST).
    if ($closingAmana && isset($crow)) {
        $sale_type     = 'dealer';
        $deal_kind     = 'paid';
        $isAmana       = false;
        $customer_name = '';
        $customer_phone= '';
        $cd = $pdo->prepare("SELECT dealer_name FROM consignments WHERE car_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
        $cd->execute([$car_id]);
        $dealer_name = (string)($cd->fetchColumn() ?: $dealer_name);
    }

    /* The same checks the browser does, done again here so they can't be skipped */
    $phoneDigits = preg_replace('/\D/', '', $customer_phone);
    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $error = $t[$lang]['token_expired'];
    } elseif ($car_id <= 0) {
        $error = $t[$lang]['err_car'];
    } elseif (!in_array($sale_type, ['customer', 'dealer'], true)) {
        $error = $t[$lang]['err_type'];
    } elseif (!$closingAmana && $salesman === '') {
        $error = $t[$lang]['err_salesman'];
    } elseif ($sale_type === 'customer' && ($customer_name === '' || strlen($phoneDigits) < 7)) {
        $error = $t[$lang]['err_customer'];
    } elseif ($sale_type === 'dealer' && $dealer_name === '') {
        $error = $t[$lang]['err_dealer'];
    }

    // Accept an available car normally, OR a consignment car when an
    // admin/manager is closing the امانة as a sale.
    // Reserved cars sell exactly like available ones (تم البيع on a gold card).
    if ($canAmana) {
        $stmt = $pdo->prepare("SELECT * FROM cars WHERE id = ? AND status IN ('available','reserved','consignment') LIMIT 1");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM cars WHERE id = ? AND status IN ('available','reserved') LIMIT 1");
    }
    $stmt->execute([$car_id]);
    $car = $stmt->fetch(PDO::FETCH_ASSOC);

    /* The car is no longer on sale (someone else sold it a moment ago, etc.) — say so */
    if (!$error && !$car) {
        $why = $pdo->prepare("SELECT status FROM cars WHERE id = ? LIMIT 1");
        $why->execute([$car_id]);
        $st = (string)($why->fetchColumn() ?: '');
        if ($st === 'sold') {
            $colOk = ensure_sold_revert_columns($pdo);
            $ws = $pdo->prepare("SELECT sold_by, salesman, sold_at FROM sold_cars WHERE car_id = ? AND " . sold_active_sql($colOk) . " ORDER BY id DESC LIMIT 1");
            $ws->execute([$car_id]);
            $w = $ws->fetch(PDO::FETCH_ASSOC) ?: [];
            $error = sprintf($t[$lang]['err_already_sold'],
                             ($w['salesman'] ?? '') !== '' ? $w['salesman'] : ($w['sold_by'] ?? '—'),
                             !empty($w['sold_at']) ? date('d/m/Y h:i A', strtotime($w['sold_at'])) : '—');
        } elseif ($st === 'consignment') {
            $error = $t[$lang]['err_on_amana'];
        } else {
            $error = $t[$lang]['err_gone'];
        }
    }

    $svDone = '';
    if (!$error && $car) {
        try {
            $pdo->beginTransaction();

            if ($isAmana) {
                /* ─── امانة: car goes out on consignment (NOT a sale) ─── */
                $pdo->prepare("UPDATE cars SET status = 'consignment' WHERE id = ?")
                    ->execute([$car_id]);

                $pdo->prepare("
                    INSERT INTO consignments
                        (car_id, dealer_name, salesman, from_branch, started_by, notes, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'active')
                ")->execute([
                    $car_id,
                    $dealer_name,
                    $salesman,
                    $car['branch'],
                    $_SESSION['username'],
                    $notes,
                ]);

                // Journey log
                $pdo->prepare("
                    INSERT INTO movements
                        (car_id, from_branch, to_branch, moved_by, notes, event_type)
                    VALUES (?, ?, ?, ?, ?, 'amana_out')
                ")->execute([
                    $car_id,
                    $car['branch'],
                    $car['branch'],
                    $_SESSION['username'],
                    ($dealer_name !== '' ? $dealer_name : '') . ($notes !== '' ? ' — ' . $notes : ''),
                ]);

                $pdo->commit();
                $success = $t[$lang]['amana_success'];
                $svDone  = 'amana';

            } else {
                /* ─── Normal sale (unchanged) ─── */
                $pdo->prepare("UPDATE cars SET status = 'sold' WHERE id = ?")
                    ->execute([$car_id]);

                $pdo->prepare("
                    INSERT INTO sold_cars
                        (car_id, sold_branch, sold_by, salesman, sale_type, customer_name, customer_phone, dealer_name, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $car_id,
                    $car['branch'],
                    $_SESSION['username'],
                    $salesman,
                    $sale_type,
                    $customer_name,
                    $customer_phone,
                    $dealer_name,
                    $notes,
                ]);

                // If this sale closes an open امانة for the car, mark it sold
                $pdo->prepare("
                    UPDATE consignments
                    SET status = 'sold', closed_at = NOW(), closed_by = ?
                    WHERE car_id = ? AND status = 'active'
                ")->execute([$_SESSION['username'], $car_id]);

                $pdo->commit();
                $success = $t[$lang]['success'];
                $svDone  = 'sale';
            }

            // Refresh car list after sale
            $cars = $pdo->query("
                SELECT cars.*, colors.color_ar, colors.color_en, branches.name_ar, branches.name_en
                FROM cars
                LEFT JOIN colors   ON cars.color  = colors.color_en
                LEFT JOIN branches ON cars.branch = branches.name
                WHERE cars.status IN ('available','reserved')
                ORDER BY cars.brand, cars.model
            ")->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('sold_vehicle: sale failed: ' . $e->getMessage());
            $error = $t[$lang]['err_db'];
        }
    }

    if ($svDone !== '') {
        /* Show the result on a fresh GET, so a refresh never re-sends the form */
        $cInfo = $pdo->prepare("SELECT c.*, co.color_ar, co.color_en, b.name_ar, b.name_en
                                FROM cars c LEFT JOIN colors co ON co.color_en = c.color LEFT JOIN branches b ON b.name = c.branch
                                WHERE c.id = ? LIMIT 1");
        $cInfo->execute([$car_id]);
        $ci = $cInfo->fetch(PDO::FETCH_ASSOC) ?: $car;
        $isCust = ($sale_type === 'customer' && !$closingAmana);
        $_SESSION['sv_done'] = [
            'kind'     => $svDone, 'closing' => $closingAmana,
            'car'      => ['id' => (int)$car_id, 'brand' => $ci['brand'], 'model' => $ci['model'], 'car_year' => $ci['car_year'],
                           'trim_name' => $ci['trim_name'], 'color' => $ci['color'], 'branch' => $ci['branch'], 'chassis' => $ci['chassis']],
            'customer' => $isCust ? $customer_name : '', 'phone' => $isCust ? $customer_phone : '',
            'dealer'   => $isCust ? '' : $dealer_name, 'salesman' => $salesman,
        ];

        header('Location: sold_vehicle.php?lang=' . $lang);
        exit;
    }
}

/* ─── After the redirect: what was just done ─── */
$svFlash = null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_SESSION['sv_done'])) {
    $svFlash = $_SESSION['sv_done'];
    unset($_SESSION['sv_done']);
    $success = $svFlash['kind'] === 'amana' ? $t[$lang]['amana_success'] : $t[$lang]['success'];
}
/* After an error: put the same car and choices back */
$svReselect = ($error && $_SERVER['REQUEST_METHOD'] === 'POST')
    ? ['car' => (int)($_POST['car_id'] ?? 0), 'type' => (string)($_POST['sale_type'] ?? ''), 'kind' => (string)($_POST['deal_kind'] ?? 'paid')]
    : null;

/* ═══ Context for the page: photos, reservations, prices, dealers, today ═══ */
function sv_swatch(string $colorEn): string
{
    static $map = [
        'white' => '#f8fafc', 'pearl white' => '#f1f5f9', 'black' => '#111827', 'silver' => '#cbd5e1',
        'grey' => '#6b7280', 'gray' => '#6b7280', 'red' => '#dc2626', 'blue' => '#2563eb', 'navy' => '#1e3a8a',
        'green' => '#16a34a', 'gold' => '#d4af37', 'beige' => '#e0d5c0', 'brown' => '#78350f',
        'orange' => '#ea580c', 'yellow' => '#eab308', 'purple' => '#7c3aed', 'bronze' => '#a97142', 'champagne' => '#e6d7b8',
    ];
    return $map[mb_strtolower(trim($colorEn))] ?? '#64748b';
}
$svImgMap = [];
try { $svImgMap = car_images_map($pdo); } catch (Throwable $e) { /* no library yet */ }
$svResv = reservation_info($pdo, array_map(fn($c) => (int)$c['id'], array_filter($cars, fn($c) => $c['status'] === 'reserved')));

$svPrices = [];
if (can('page.prices')) {
    try {
        foreach ($pdo->query("SELECT brand, model_name, trim_name, car_year, official_price, customer_price FROM pricing") as $r) {
            $svPrices[mb_strtolower(implode('|', [$r['brand'], $r['model_name'], $r['trim_name'], $r['car_year']]))] = [
                'off'  => ($r['official_price'] !== null && $r['official_price'] !== '') ? number_format((float)$r['official_price']) : '',
                'cust' => (string)($r['customer_price'] ?? ''),   // the label exactly as written
            ];
        }
    } catch (Throwable $e) { /* no pricing table */ }
}

/* the extra data each car card carries */
$svCard = function (array $c) use ($svImgMap, $svResv, $lang): string {
    $img = '';
    try { $img = car_image_url_for($svImgMap, $c, true); } catch (Throwable $e) {}
    $r = $svResv[(int)$c['id']] ?? null;
    return ' data-img="'     . htmlspecialchars($img, ENT_QUOTES) . '"'
         . ' data-sw="'      . sv_swatch((string)$c['color']) . '"'
         . ' data-year="'    . htmlspecialchars((string)$c['car_year'], ENT_QUOTES) . '"'
         . ' data-bkey="'    . htmlspecialchars((string)$c['branch'], ENT_QUOTES) . '"'
         . ' data-pkey="'    . htmlspecialchars(mb_strtolower(implode('|', [$c['brand'], $c['model'], $c['trim_name'], $c['car_year']])), ENT_QUOTES) . '"'
         . ($c['status'] === 'reserved' ? ' data-res="1" data-resby="' . htmlspecialchars((string)($r['by'] ?? ''), ENT_QUOTES) . '"' : '');
};

$svDealers = [];
try {
    $svDealers = $pdo->query("
        SELECT name FROM (
            SELECT dealer_name AS name FROM sold_cars    WHERE dealer_name IS NOT NULL AND dealer_name <> ''
            UNION
            SELECT dealer_name AS name FROM consignments WHERE dealer_name IS NOT NULL AND dealer_name <> ''
        ) d ORDER BY name LIMIT 800")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { /* no data yet */ }

$svToday = [];
try {
    $colOk = ensure_sold_revert_columns($pdo);
    $q1 = $pdo->query("
        SELECT 'sale' AS kind, sc.sold_at AS at, sc.sale_type, sc.customer_name, sc.dealer_name, sc.salesman,
               c.id, c.brand, c.model, c.car_year, c.trim_name, c.color, c.chassis
        FROM sold_cars sc JOIN cars c ON c.id = sc.car_id
        WHERE sc.sold_at >= CURDATE() AND " . sold_active_sql($colOk, 'sc'))->fetchAll(PDO::FETCH_ASSOC);
    $q2 = $pdo->query("
        SELECT 'amana' AS kind, cn.started_at AS at, 'dealer' AS sale_type, '' AS customer_name, cn.dealer_name, cn.salesman,
               c.id, c.brand, c.model, c.car_year, c.trim_name, c.color, c.chassis
        FROM consignments cn JOIN cars c ON c.id = cn.car_id
        WHERE cn.started_at >= CURDATE()")->fetchAll(PDO::FETCH_ASSOC);
    $svToday = array_merge($q1, $q2);
    usort($svToday, fn($a, $b) => strcmp($b['at'], $a['at']));
    $svToday = array_slice($svToday, 0, 40);
} catch (Throwable $e) { error_log('sold_vehicle: today list failed: ' . $e->getMessage()); }


$other_lang = $lang === 'ar' ? 'en' : 'ar';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $lang === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $t[$lang]['title'] ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;600;700&family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
/* ── Reset & Tokens ── */
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

:root {
    --bg-deep:    #020617;
    --bg-surface: #0c1120;
    --bg-card:    rgba(15, 23, 42, 0.92);
    --bg-input:   #0f172a;
    --bg-hover:   #1e293b;

    --purple-400: #c084fc;
    --purple-500: #a855f7;
    --purple-600: #9333ea;
    --purple-700: #7e22ce;
    --green-400:  #4ade80;
    --green-500:  #22c55e;
    --red-400:    #f87171;

    --text-primary:   #f1f5f9;
    --text-secondary: #94a3b8;
    --text-muted:     #475569;
    --border:         rgba(255,255,255,0.07);
    --border-focus:   rgba(168, 85, 247, 0.6);

    --radius-sm:  10px;
    --radius-md:  16px;
    --radius-lg:  24px;
    --radius-xl:  32px;

    --shadow-card: 0 25px 60px rgba(0,0,0,0.5), 0 0 0 1px rgba(255,255,255,0.04);
    --shadow-glow: 0 0 40px rgba(147,51,234,0.18);

    --font: 'Inter', 'IBM Plex Sans Arabic', Tahoma, sans-serif;
}

html[lang="ar"] { font-family: 'IBM Plex Sans Arabic', Tahoma, sans-serif; }
html[lang="en"] { font-family: 'Inter', Tahoma, sans-serif; }

body {
    background: var(--bg-deep);
    min-height: 100vh;
    color: var(--text-primary);
    padding: 24px 16px 60px;
    background-image:
        radial-gradient(ellipse 80% 50% at 50% -10%, rgba(147,51,234,0.15), transparent),
        radial-gradient(ellipse 60% 40% at 80% 90%, rgba(34,197,94,0.06), transparent);
}

/* ── Layout ── */
.page-wrap {
    max-width: 780px;
    margin: 0 auto;
}

/* ── Top Bar ── */
.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 32px;
    gap: 12px;
}

.back-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    padding: 8px 14px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
    background: var(--bg-surface);
    transition: all .2s;
    white-space: nowrap;
}
.back-btn:hover { color: var(--text-primary); border-color: var(--border-focus); }

.lang-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--purple-400);
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    padding: 8px 14px;
    border-radius: var(--radius-sm);
    border: 1px solid rgba(168,85,247,0.3);
    background: rgba(168,85,247,0.08);
    transition: all .2s;
    white-space: nowrap;
}
.lang-btn:hover { background: rgba(168,85,247,0.18); }

/* ── Page Header ── */
.page-header {
    text-align: center;
    margin-bottom: 36px;
}
.page-header .icon-wrap {
    width: 72px; height: 72px;
    background: linear-gradient(135deg, rgba(147,51,234,0.25), rgba(34,197,94,0.12));
    border: 1px solid rgba(168,85,247,0.3);
    border-radius: 22px;
    display: flex; align-items: center; justify-content: center;
    font-size: 32px;
    margin: 0 auto 18px;
    box-shadow: var(--shadow-glow);
}
.page-header h1 {
    font-size: clamp(24px, 5vw, 34px);
    font-weight: 700;
    background: linear-gradient(90deg, #fff 30%, var(--purple-400));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    line-height: 1.2;
}
.page-header p {
    color: var(--text-secondary);
    font-size: 15px;
    margin-top: 8px;
}

/* ── Alerts ── */
.alert {
    padding: 16px 20px;
    border-radius: var(--radius-md);
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 600;
    font-size: 15px;
    animation: slideDown .3s ease;
}
.alert-success {
    background: rgba(34,197,94,0.1);
    border: 1px solid rgba(34,197,94,0.3);
    color: #86efac;
}
.alert-error {
    background: rgba(248,113,113,0.1);
    border: 1px solid rgba(248,113,113,0.3);
    color: #fca5a5;
}
.alert .alert-icon { font-size: 20px; flex-shrink: 0; }

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── Step Indicator ── */
.steps {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0;
    margin-bottom: 32px;
}
.step {
    display: flex;
    align-items: center;
    gap: 10px;
}
.step-bubble {
    width: 36px; height: 36px;
    border-radius: 50%;
    border: 2px solid var(--border);
    background: var(--bg-surface);
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 700;
    color: var(--text-muted);
    transition: all .3s;
    flex-shrink: 0;
}
.step.active .step-bubble {
    border-color: var(--purple-500);
    background: rgba(147,51,234,0.2);
    color: var(--purple-400);
    box-shadow: 0 0 16px rgba(147,51,234,0.35);
}
.step.done .step-bubble {
    border-color: var(--green-500);
    background: rgba(34,197,94,0.15);
    color: var(--green-400);
}
.step-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
    white-space: nowrap;
}
.step.active .step-label { color: var(--purple-400); }
.step.done .step-label   { color: var(--green-400); }

.step-line {
    width: 40px;
    height: 2px;
    background: var(--border);
    margin: 0 4px;
    flex-shrink: 0;
}
.step-line.done { background: rgba(34,197,94,0.3); }

@media (max-width: 480px) {
    .step-label { display: none; }
    .step-line  { width: 24px; }
}

/* ── Card ── */
.card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
    padding: 32px;
    box-shadow: var(--shadow-card);
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
}

.section-label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--text-muted);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.section-label::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
}

/* ── Search Box ── */
.search-wrap {
    position: relative;
    margin-bottom: 12px;
}
.search-wrap .search-icon {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    font-size: 16px;
    pointer-events: none;
}
html[dir="ltr"] .search-wrap .search-icon { left: 16px; }
html[dir="rtl"] .search-wrap .search-icon { right: 16px; }

#carSearch {
    width: 100%;
    height: 52px;
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    color: var(--text-primary);
    font-size: 14px;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
}
html[dir="ltr"] #carSearch { padding: 0 16px 0 44px; }
html[dir="rtl"] #carSearch { padding: 0 44px 0 16px; }
#carSearch:focus {
    border-color: var(--border-focus);
    box-shadow: 0 0 0 3px rgba(147,51,234,0.12);
}
#carSearch::placeholder { color: var(--text-muted); }

/* ── Car Grid ── */
.car-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    max-height: 320px;
    overflow-y: auto;
    padding-right: 4px;
    scrollbar-width: thin;
    scrollbar-color: var(--purple-700) transparent;
}
.car-grid::-webkit-scrollbar { width: 4px; }
.car-grid::-webkit-scrollbar-track { background: transparent; }
.car-grid::-webkit-scrollbar-thumb { background: var(--purple-700); border-radius: 4px; }

.car-card {
    background: var(--bg-input);
    border: 2px solid transparent;
    border-radius: var(--radius-md);
    padding: 14px;
    cursor: pointer;
    transition: all .2s;
    position: relative;
    overflow: hidden;
}
.car-card:hover {
    border-color: rgba(147,51,234,0.4);
    background: var(--bg-hover);
}
.car-card.selected {
    border-color: var(--purple-500);
    background: rgba(147,51,234,0.1);
    box-shadow: 0 0 20px rgba(147,51,234,0.2);
}
.car-card.selected::before {
    content: '✓';
    position: absolute;
    top: 8px;
    font-size: 11px;
    font-weight: 700;
    background: var(--purple-600);
    color: white;
    width: 20px; height: 20px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    line-height: 20px;
    text-align: center;
}
html[dir="ltr"] .car-card.selected::before { right: 8px; }
html[dir="rtl"] .car-card.selected::before { left: 8px; }

/* ── امانة group separator (spans full grid width) ── */
.amana-group-label {
    grid-column: 1 / -1;
    margin: 6px 0 2px;
    padding: 7px 12px;
    background: rgba(245,158,11,.1);
    border: 1px dashed rgba(245,158,11,.4);
    border-radius: 10px;
    font-size: 12px;
    font-weight: 800;
    color: #f59e0b;
    text-align: center;
}
/* ── امانة car card (amber accent) ── */
.car-card-amana { border-color: rgba(245,158,11,.3); background: rgba(245,158,11,.04); }
.car-card-amana:hover { border-color: rgba(245,158,11,.55); background: rgba(245,158,11,.08); }
.car-card-amana.selected { border-color: #f59e0b; background: rgba(245,158,11,.12); box-shadow: 0 0 20px rgba(245,158,11,.2); }
.car-card-amana.selected::before { background: #f59e0b; }
.avail-badge.amana-badge { background: rgba(245,158,11,.18); color: #f59e0b; border-color: rgba(245,158,11,.35); }
.car-amana-dealer { font-size: 10px; color: #fbbf24; font-weight: 700; margin-top: 5px; }

.car-card .car-brand {
    font-weight: 700;
    font-size: 14px;
    color: var(--text-primary);
    margin-bottom: 4px;
}
.car-card .car-model {
    font-size: 12px;
    color: var(--text-secondary);
    margin-bottom: 6px;
}
.car-card .car-chassis {
    font-size: 10px;
    color: var(--text-muted);
    font-family: monospace;
    background: rgba(255,255,255,0.04);
    padding: 3px 7px;
    border-radius: 6px;
    display: inline-block;
}
.car-card .avail-badge {
    font-size: 10px;
    font-weight: 700;
    color: var(--green-400);
    background: rgba(34,197,94,0.12);
    border: 1px solid rgba(34,197,94,0.2);
    padding: 2px 8px;
    border-radius: 20px;
    float: right;
}
html[dir="rtl"] .car-card .avail-badge { float: left; }

.no-cars {
    grid-column: 1/-1;
    text-align: center;
    padding: 32px;
    color: var(--text-muted);
    font-size: 14px;
}

/* Hidden real select (for form submission) */
#carIdInput { display: none; }

/* ── Vehicle Preview Panel ── */
.vehicle-preview {
    background: linear-gradient(135deg, rgba(147,51,234,0.08), rgba(34,197,94,0.05));
    border: 1px solid rgba(147,51,234,0.2);
    border-radius: var(--radius-md);
    padding: 20px;
    margin-top: 16px;
    display: none;
    animation: fadeIn .3s ease;
}
.vehicle-preview h4 {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--purple-400);
    margin-bottom: 14px;
}
.preview-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 12px;
}
.preview-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.preview-item .pi-label {
    font-size: 10px;
    color: var(--text-muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .08em;
}
.preview-item .pi-value {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
}
.preview-item .pi-value.chassis-val {
    font-family: monospace;
    font-size: 12px;
    color: var(--purple-400);
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── Divider ── */
.divider {
    height: 1px;
    background: var(--border);
    margin: 28px 0;
}

/* ── Sale Type Toggle ── */
.sale-toggle {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 24px;
}
.sale-opt {
    position: relative;
}
.sale-opt input[type="radio"] {
    position: absolute;
    opacity: 0;
    width: 0; height: 0;
}
.sale-opt label {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 16px;
    border-radius: var(--radius-md);
    border: 2px solid var(--border);
    background: var(--bg-input);
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    color: var(--text-secondary);
    transition: all .2s;
    text-align: center;
}
.sale-opt label .opt-icon { font-size: 20px; }
.sale-opt input:checked + label {
    border-color: var(--purple-500);
    background: rgba(147,51,234,0.12);
    color: var(--purple-400);
    box-shadow: 0 0 16px rgba(147,51,234,0.2);
}
.sale-opt label:hover {
    border-color: rgba(147,51,234,0.3);
    color: var(--text-primary);
}

/* ── امانة sub-toggle (amber) ── */
.amana-toggle {
    margin: -8px 0 24px;
    padding: 14px;
    border: 1px dashed rgba(245,158,11,.35);
    border-radius: var(--radius-md);
    background: rgba(245,158,11,.04);
}
.amana-toggle-label {
    font-size: 13px;
    font-weight: 700;
    color: #f59e0b;
    margin-bottom: 12px;
}
.amana-sub { margin-bottom: 0; }
.amana-sub .sale-opt label { flex-direction: column; gap: 4px; padding: 12px; }
.amana-sub .sale-opt label small {
    display: block;
    font-size: 10px;
    font-weight: 500;
    opacity: .7;
    margin-top: 2px;
}
.amana-sub .sale-opt input:checked + label {
    border-color: #f59e0b;
    background: rgba(245,158,11,.12);
    color: #f59e0b;
    box-shadow: 0 0 16px rgba(245,158,11,.2);
}
.amana-sub .sale-opt input:disabled + label { opacity: .45; cursor: not-allowed; }

/* ── Form Fields ── */
.fields-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
.field-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.field-group.full { grid-column: 1 / -1; }

.field-group label {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-secondary);
}
.field-group label span.req {
    color: var(--red-400);
    margin-inline-start: 3px;
}

.field-group input,
.field-group select,
.field-group textarea {
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    color: var(--text-primary);
    font-size: 15px;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
    width: 100%;
}
.field-group input,
.field-group select {
    height: 54px;
    padding: 0 16px;
}
.field-group select { cursor: pointer; font-family: inherit; }
.field-group select option { background: var(--bg-input); }
.field-group textarea {
    padding: 14px 16px;
    height: 110px;
    resize: vertical;
    min-height: 80px;
    font-family: inherit;
    line-height: 1.6;
}
.field-group input:focus,
.field-group select:focus,
.field-group textarea:focus {
    border-color: var(--border-focus);
    box-shadow: 0 0 0 3px rgba(147,51,234,0.12);
}
.field-group input::placeholder,
.field-group textarea::placeholder {
    color: var(--text-muted);
}

/* ── Submit Button ── */
.submit-btn {
    width: 100%;
    height: 62px;
    border: none;
    cursor: pointer;
    border-radius: var(--radius-lg);
    font-size: 17px;
    font-weight: 700;
    color: white;
    background: linear-gradient(90deg, var(--green-500), var(--purple-600));
    background-size: 200% 100%;
    background-position: 0% 50%;
    transition: all .3s;
    margin-top: 8px;
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    font-family: inherit;
}
.submit-btn::before {
    content: '';
    position: absolute;
    inset: 0;
    background: rgba(255,255,255,0);
    transition: background .2s;
}
.submit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 30px rgba(147,51,234,0.4);
    background-position: 100% 50%;
}
.submit-btn:active { transform: translateY(0); }
.submit-btn:disabled {
    opacity: .5;
    cursor: not-allowed;
    transform: none;
}

/* ── Responsive ── */
@media (max-width: 600px) {
    .card { padding: 20px 16px; }
    .car-grid { grid-template-columns: 1fr; max-height: 260px; }
    .preview-grid { grid-template-columns: 1fr 1fr; }
    .fields-grid { grid-template-columns: 1fr; }
    .field-group.full { grid-column: 1; }
    .topbar { gap: 8px; }
    .back-btn span { display: none; }
}
</style>
</head>
<body>
<div class="page-wrap">

    <!-- Top Bar -->
    <div class="topbar">
        <a href="dashboard.php?lang=<?= $lang ?>" class="back-btn">
            <?= $lang === 'ar' ? '→' : '←' ?>
            <span><?= $t[$lang]['back'] ?></span>
        </a>

        <div class="sv-top-r">
            <a href="sold_vehicle.php?lang=<?= $other_lang ?><?= $preselectId > 0 ? '&id=' . $preselectId : '' ?>" class="lang-btn">
                🌐 <?= $t[$lang]['lang_switch'] ?>
            </a>
        </div>
    </div>

    <!-- Page Header -->
    <div class="page-header">
        <div class="icon-wrap">🚗</div>
        <h1><?= $t[$lang]['title'] ?></h1>
        <p><?= $t[$lang]['subtitle'] ?></p>
    </div>

    <!-- Step Indicator -->
    <div class="steps">
        <div class="step active" id="step1-ind">
            <div class="step-bubble">1</div>
            <div class="step-label"><?= $t[$lang]['step1'] ?></div>
        </div>
        <div class="step-line" id="line1"></div>
        <div class="step active" id="step2-ind">
            <div class="step-bubble">2</div>
            <div class="step-label"><?= $t[$lang]['step2'] ?></div>
        </div>
        <div class="step-line" id="line2"></div>
        <div class="step" id="step3-ind">
            <div class="step-bubble">3</div>
            <div class="step-label"><?= $t[$lang]['step3'] ?></div>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($success && $svFlash):
        $fc = $svFlash['car'];
        $fImg = '';
        try { $fImg = car_image_url_for($svImgMap, $fc, false); } catch (Throwable $e) {}
        $fColor = $fc['color']; $fBranch = $fc['branch'];
        foreach ($pdo->query("SELECT color_en, color_ar FROM colors") as $cr) if (strcasecmp($cr['color_en'], $fc['color']) === 0) { $fColor = $lang === 'ar' ? $cr['color_ar'] : $cr['color_en']; break; }
        foreach ($pdo->query("SELECT name, name_ar, name_en FROM branches") as $br) if ($br['name'] === $fc['branch']) { $fBranch = $lang === 'ar' ? $br['name_ar'] : $br['name_en']; break; }
    ?>
    <div class="sv-done <?= $svFlash['kind'] === 'amana' ? 'amana' : '' ?>" id="svDone" data-img="<?= htmlspecialchars($fImg) ?>" data-sw="<?= sv_swatch((string)$fc['color']) ?>">
        <div class="sv-done-stage" id="svDoneStage"></div>
        <div class="sv-done-b">
            <div class="sv-done-t"><?= htmlspecialchars($success) ?></div>
            <div class="sv-done-car"><?= htmlspecialchars($fc['brand'] . ' ' . $fc['model'] . ' ' . $fc['car_year']) ?> <span><?= htmlspecialchars($fc['trim_name']) ?></span></div>
            <div class="sv-done-meta">
                <span><i style="background:<?= sv_swatch((string)$fc['color']) ?>"></i><?= htmlspecialchars($fColor) ?></span>
                <span>📍 <?= htmlspecialchars($fBranch) ?></span>
                <span class="mono">🔑 <?= htmlspecialchars($fc['chassis']) ?></span>
            </div>
            <div class="sv-done-meta">
                <?php if ($svFlash['customer'] !== ''): ?>
                <span>👤 <?= htmlspecialchars($svFlash['customer']) ?><?= $svFlash['phone'] !== '' ? ' · <b class="mono">' . htmlspecialchars($svFlash['phone']) . '</b>' : '' ?></span>
                <?php else: ?>
                <span>🤝 <?= htmlspecialchars($svFlash['dealer']) ?></span>
                <?php endif; ?>
                <span>🧑‍💼 <?= htmlspecialchars($svFlash['salesman'] !== '' ? $svFlash['salesman'] : '—') ?></span>
            </div>
            <div class="sv-done-a">
                <a href="#sellForm" class="sv-btn sv-btn-p" id="svAnother">🚗 <?= $lang === 'ar' ? 'بيع سيارة أخرى' : 'Sell another' ?></a>
                <a href="dashboard.php?lang=<?= $lang ?>" class="sv-btn">🏠 <?= $t[$lang]['back'] ?></a>
            </div>
        </div>
    </div>
    <?php elseif ($success): ?>
    <div class="alert alert-success">
        <span class="alert-icon">✅</span>
        <?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-error">
        <span class="alert-icon">⚠️</span>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <!-- Main Card -->
    <div class="card">
        <form method="POST" id="sellForm" novalidate>

            <!-- Hidden real input -->
            <input type="hidden" name="car_id" id="carIdInput" value="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <!-- ── Section 1: Vehicle ── -->
            <div class="section-label">🚘 <?= $t[$lang]['vehicle'] ?></div>

            <div class="search-wrap">
                <span class="search-icon">🔍</span>
                <input
                    type="text"
                    id="carSearch"
                    placeholder="<?= $t[$lang]['search_car'] ?>"
                    autocomplete="off"
                >
            </div>

            <div class="car-grid" id="carGrid">
                <?php if (empty($cars) && empty($amanaList)): ?>
                <div class="no-cars">🚫 <?= $t[$lang]['no_cars'] ?></div>
                <?php else: ?>

                    <?php foreach ($cars as $c): ?>
                    <div class="car-card"
                        data-id="<?= $c['id'] ?>"
                        data-brand="<?= htmlspecialchars($c['brand']) ?>"
                        data-model="<?= htmlspecialchars($c['model']) ?>"
                        data-trim="<?= htmlspecialchars($c['trim_name'] ?? '') ?>"
                        data-color="<?= htmlspecialchars($lang === 'ar' ? ($c['color_ar'] ?: $c['color']) : ($c['color_en'] ?: $c['color'])) ?>"
                        data-branch="<?= htmlspecialchars($lang === 'ar' ? ($c['name_ar'] ?: $c['branch']) : ($c['name_en'] ?: $c['branch'])) ?>"
                        data-chassis="<?= htmlspecialchars($c['chassis']) ?>"
                        <?= $svCard($c) ?>
                        onclick="selectCar(this)"
                    >
                        <?php if ($c['status'] === 'reserved'): ?>
                        <span class="avail-badge res-badge">🟡 <?= $lang === 'ar' ? 'محجوزة' : 'Reserved' ?></span>
                        <?php else: ?>
                        <span class="avail-badge"><?= $t[$lang]['available'] ?></span>
                        <?php endif; ?>
                        <div class="car-brand"><?= htmlspecialchars($c['brand']) ?></div>
                        <div class="car-model"><?= htmlspecialchars($c['model']) ?> · <?= htmlspecialchars($c['trim_name'] ?? '') ?></div>
                        <div class="car-chassis"><?= htmlspecialchars($c['chassis']) ?></div>
                    </div>
                    <?php endforeach; ?>

                    <?php if (!empty($amanaList)): ?>
                    <div class="amana-group-label">
                        <span><?= $t[$lang]['amana_group'] ?></span>
                    </div>
                    <?php foreach ($amanaList as $c): ?>
                    <div class="car-card car-card-amana"
                        data-id="<?= $c['id'] ?>"
                        data-brand="<?= htmlspecialchars($c['brand']) ?>"
                        data-model="<?= htmlspecialchars($c['model']) ?>"
                        data-trim="<?= htmlspecialchars($c['trim_name'] ?? '') ?>"
                        data-color="<?= htmlspecialchars($lang === 'ar' ? ($c['color_ar'] ?: $c['color']) : ($c['color_en'] ?: $c['color'])) ?>"
                        data-branch="<?= htmlspecialchars($lang === 'ar' ? ($c['name_ar'] ?: $c['branch']) : ($c['name_en'] ?: $c['branch'])) ?>"
                        data-chassis="<?= htmlspecialchars($c['chassis']) ?>"
                        data-amana="1"
                        data-dealer="<?= htmlspecialchars($c['amana_dealer'] ?? '') ?>"
                        data-salesman="<?= htmlspecialchars($c['amana_salesman'] ?? '') ?>"
                        <?= $svCard($c) ?>
                        onclick="selectCar(this)"
                    >
                        <span class="avail-badge amana-badge">🔶 <?= $t[$lang]['amana'] ?></span>
                        <div class="car-brand"><?= htmlspecialchars($c['brand']) ?></div>
                        <div class="car-model"><?= htmlspecialchars($c['model']) ?> · <?= htmlspecialchars($c['trim_name'] ?? '') ?></div>
                        <div class="car-chassis"><?= htmlspecialchars($c['chassis']) ?></div>
                        <div class="car-amana-dealer">👤 <?= htmlspecialchars($c['amana_dealer'] ?? '—') ?></div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>

                <?php endif; ?>
            </div>

            <!-- Vehicle Preview -->
            <div class="vehicle-preview" id="vehiclePreview">
                <h4>📋 <?= $t[$lang]['vehicle_details'] ?></h4>
                <div class="preview-grid">
                    <div class="preview-item">
                        <span class="pi-label"><?= $t[$lang]['brand'] ?></span>
                        <span class="pi-value" id="pvBrand">—</span>
                    </div>
                    <div class="preview-item">
                        <span class="pi-label"><?= $t[$lang]['model'] ?></span>
                        <span class="pi-value" id="pvModel">—</span>
                    </div>
                    <div class="preview-item">
                        <span class="pi-label"><?= $t[$lang]['trim'] ?></span>
                        <span class="pi-value" id="pvTrim">—</span>
                    </div>
                    <div class="preview-item">
                        <span class="pi-label"><?= $t[$lang]['color'] ?></span>
                        <span class="pi-value" id="pvColor">—</span>
                    </div>
                    <div class="preview-item">
                        <span class="pi-label"><?= $t[$lang]['branch'] ?></span>
                        <span class="pi-value" id="pvBranch">—</span>
                    </div>
                    <div class="preview-item">
                        <span class="pi-label"><?= $t[$lang]['chassis'] ?></span>
                        <span class="pi-value chassis-val" id="pvChassis">—</span>
                    </div>
                </div>
            </div>

            <div class="divider"></div>

            <!-- ── Section 2: Sale Info ── -->
            <div class="section-label">💼 <?= $t[$lang]['sale_info'] ?></div>

            <!-- Sale Type Toggle -->
            <div class="sale-toggle">
                <div class="sale-opt">
                    <input type="radio" name="sale_type" id="typeCustomer" value="customer" <?= $amanaLock ? 'disabled' : 'checked' ?>>
                    <label for="typeCustomer" <?= $amanaLock ? 'style="opacity:.4;cursor:not-allowed;"' : '' ?>>
                        <span class="opt-icon">👤</span>
                        <?= $t[$lang]['customer'] ?>
                    </label>
                </div>
                <div class="sale-opt">
                    <input type="radio" name="sale_type" id="typeDealer" value="dealer" <?= $amanaLock ? 'checked' : '' ?>>
                    <label for="typeDealer">
                        <span class="opt-icon">🤝</span>
                        <?= $t[$lang]['dealer'] ?>
                        <?php if ($amanaLock): ?><span style="color:#f59e0b;font-size:.8em;margin-inline-start:4px;">🔒</span><?php endif; ?>
                    </label>
                </div>
            </div>

            <?php if ($canAmanaManage): ?>
            <!-- Dealer deal kind: Paid vs امانة (admin/manager only) -->
            <div class="amana-toggle" id="dealKindWrap" style="display:<?= $amanaLock ? 'block' : 'none' ?>;">
                <div class="amana-toggle-label">🔶 <?= $t[$lang]['deal_kind'] ?></div>
                <div class="sale-toggle amana-sub">
                    <div class="sale-opt">
                        <input type="radio" name="deal_kind" id="kindPaid" value="paid" checked>
                        <label for="kindPaid">
                            <span class="opt-icon">💵</span>
                            <span><?= $t[$lang]['paid'] ?><small><?= $t[$lang]['paid_hint'] ?></small></span>
                        </label>
                    </div>
                    <div class="sale-opt">
                        <input type="radio" name="deal_kind" id="kindAmana" value="amana" <?= $amanaLock ? 'disabled' : '' ?>>
                        <label for="kindAmana">
                            <span class="opt-icon">🔶</span>
                            <span><?= $t[$lang]['amana'] ?><small><?= $t[$lang]['amana_hint'] ?></small></span>
                        </label>
                    </div>
                </div>
                <?php if ($amanaLock): ?>
                <div style="font-size:11px;color:#94a3b8;margin-top:8px;">
                    <?= $lang === 'ar' ? 'سيتم إتمام بيع سيارة الأمانة لنفس التاجر.' : 'Completing the consignment sale to the same dealer.' ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Dynamic Fields -->
            <div class="fields-grid">

                <div class="field-group full">
                    <label for="salesman">
                        🧑‍💼 <?= $t[$lang]['salesman'] ?>
                        <span class="req">*</span>
                        <?php if ($amanaLock): ?><span style="color:#f59e0b;font-size:.8em;">🔒</span><?php endif; ?>
                    </label>
                    <div id="salesmanWrap">
                    <?php if ($amanaLock): ?>
                        <!-- Locked: salesman is fixed to whoever put the car on امانة -->
                        <select disabled style="opacity:.85;cursor:not-allowed;background:rgba(245,158,11,.06);">
                            <option selected><?= htmlspecialchars($amanaSalesman !== '' ? $amanaSalesman : '—') ?></option>
                        </select>
                        <input type="hidden" name="salesman" value="<?= htmlspecialchars($amanaSalesman) ?>">
                    <?php else: ?>
                        <select id="salesman" name="salesman" required>
                            <option value=""><?= $t[$lang]['select_salesman'] ?></option>
                            <?php foreach ($salesmen as $sm): ?>
                                <option value="<?= htmlspecialchars($sm) ?>"
                                    <?= (($_POST['salesman'] ?? '') === $sm) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sm) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    </div>
                </div>

                <!-- Snapshot of the unlocked salesman selector for client-side unlock -->
                <script type="text/template" id="salesmanOriginalTpl"><select id="salesman" name="salesman" required><option value=""><?= htmlspecialchars($t[$lang]['select_salesman']) ?></option><?php foreach ($salesmen as $sm): ?><option value="<?= htmlspecialchars($sm) ?>"><?= htmlspecialchars($sm) ?></option><?php endforeach; ?></select></script>

                <div class="field-group" id="fieldCustomerName" style="display:<?= $amanaLock ? 'none' : '' ?>;">
                    <label for="customer_name">
                        <?= $t[$lang]['customer_name'] ?>
                        <span class="req">*</span>
                    </label>
                    <input
                        type="text"
                        id="customer_name"
                        name="customer_name"
                        placeholder="<?= $lang === 'ar' ? 'محمد أحمد' : 'John Smith' ?>"
                        value="<?= htmlspecialchars($_POST['customer_name'] ?? '') ?>"
                    >
                </div>

                <div class="field-group" id="fieldCustomerPhone" style="display:<?= $amanaLock ? 'none' : '' ?>;">
                    <label for="customer_phone">
                        <?= $t[$lang]['customer_phone'] ?>
                        <span class="req">*</span>
                    </label>
                    <input
                        type="tel"
                        id="customer_phone"
                        name="customer_phone"
                        placeholder="01xxxxxxxxx"
                        inputmode="tel"
                        autocomplete="off"
                        value="<?= htmlspecialchars($_POST['customer_phone'] ?? '') ?>"
                    >
                    <div class="sv-hint" id="phoneHint"></div>
                </div>

                <div class="field-group" id="fieldDealer" style="display:<?= $amanaLock ? 'block' : 'none' ?>; grid-column:1/-1;">
                    <label for="dealer_name">
                        <?= $t[$lang]['dealer_name'] ?>
                        <span class="req">*</span>
                        <?php if ($amanaLock): ?><span style="color:#f59e0b;font-size:.8em;">🔒</span><?php endif; ?>
                    </label>
                    <input
                        type="text"
                        id="dealer_name"
                        name="dealer_name"
                        list="svDealerList"
                        autocomplete="off"
                        placeholder="<?= $lang === 'ar' ? 'اسم شركة التاجر' : 'Dealer company name' ?>"
                        value="<?= htmlspecialchars($amanaLock ? $amanaDealer : ($_POST['dealer_name'] ?? '')) ?>"
                        <?= $amanaLock ? 'readonly style="opacity:.85;cursor:not-allowed;background:rgba(245,158,11,.06);"' : '' ?>
                    >
                </div>

                <div class="field-group full">
                    <label for="notes"><?= $t[$lang]['notes'] ?></label>
                    <textarea
                        id="notes"
                        name="notes"
                        placeholder="<?= $lang === 'ar' ? 'أي ملاحظات إضافية...' : 'Any additional notes...' ?>"
                    ><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                </div>

            </div>

            <datalist id="svDealerList"><?php foreach ($svDealers as $dn): ?><option value="<?= htmlspecialchars($dn) ?>"><?php endforeach; ?></datalist>

            <button type="submit" class="submit-btn" id="submitBtn">
                <span>🚗</span>
                <span><?= $t[$lang]['sell'] ?></span>
            </button>

        </form>
    </div>

    <!-- Today -->
    <div class="card sv-today">
        <div class="sv-today-h">
            <span>🗓 <?= $lang === 'ar' ? 'مبيعات اليوم' : "Today's sales" ?></span>
            <?php $nSale = count(array_filter($svToday, fn($r) => $r['kind'] === 'sale')); $nAm = count($svToday) - $nSale; ?>
            <span class="sv-today-n"><b><?= $nSale ?></b> <?= $lang === 'ar' ? 'بيع' : 'sold' ?><?php if ($nAm): ?> · <b class="am"><?= $nAm ?></b> <?= $lang === 'ar' ? 'أمانة' : 'consignment' ?><?php endif; ?></span>
        </div>
        <?php if (!$svToday): ?>
        <div class="sv-today-empty"><?= $lang === 'ar' ? 'لا توجد مبيعات اليوم بعد — أول بيعة ستظهر هنا' : 'No sales yet today — the first one will show here' ?></div>
        <?php else: ?>
        <div class="sv-today-list">
            <?php foreach ($svToday as $i => $r): ?>
            <div class="sv-ti <?= $r['kind'] === 'amana' ? 'amana' : '' ?><?= ($i === 0 && $svFlash && (int)$svFlash['car']['id'] === (int)$r['id']) ? ' fresh' : '' ?>">
                <i class="sv-ti-dot" style="background:<?= sv_swatch((string)$r['color']) ?>"></i>
                <div class="sv-ti-m">
                    <div class="sv-ti-n"><?= htmlspecialchars($r['brand'] . ' ' . $r['model'] . ' ' . $r['car_year']) ?> <span><?= htmlspecialchars((string)$r['trim_name']) ?></span></div>
                    <div class="sv-ti-s">
                        <?= $r['kind'] === 'amana' ? '🔶 ' : ($r['sale_type'] === 'dealer' ? '🤝 ' : '👤 ') ?><?= htmlspecialchars($r['sale_type'] === 'dealer' ? (string)$r['dealer_name'] : (string)$r['customer_name']) ?>
                        · 🧑‍💼 <?= htmlspecialchars($r['salesman'] ?: '—') ?>
                    </div>
                </div>
                <div class="sv-ti-r">
                    <span class="sv-ti-t"><?= date('h:i A', strtotime($r['at'])) ?></span>
                    <span class="sv-ti-c"><?= htmlspecialchars($r['chassis']) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div>

<script>
// ── Car Selection ──
let selectedCarId = null;

// Auto-select the car passed from the dashboard (?id=)
const preselectId = <?= json_encode((string)$preselectId) ?>;

function selectCar(el) {
    document.querySelectorAll('.car-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    selectedCarId = el.dataset.id;
    document.getElementById('carIdInput').value = selectedCarId;

    document.getElementById('pvBrand').textContent  = el.dataset.brand  || '—';
    document.getElementById('pvModel').textContent  = el.dataset.model  || '—';
    document.getElementById('pvTrim').textContent   = el.dataset.trim   || '—';
    document.getElementById('pvColor').textContent  = el.dataset.color  || '—';
    document.getElementById('pvBranch').textContent = el.dataset.branch || '—';
    document.getElementById('pvChassis').textContent= el.dataset.chassis|| '—';

    // If this is an امانة car, lock the sale to Dealer + fixed trader + fixed salesman.
    // If it's a normal car, make sure those locks are cleared.
    if (el.dataset.amana === '1') {
        applyAmanaLock(el.dataset.dealer || '', el.dataset.salesman || '');
    } else {
        clearAmanaLock();
    }

    const preview = document.getElementById('vehiclePreview');
    preview.style.display = 'block';
    preview.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    updateSteps();
}

// ── امانة lock/unlock (when picking a consignment car from the list) ──
function applyAmanaLock(dealer, salesman) {
    amanaLocked = true;

    // Force Dealer, disable Customer
    const cust = document.getElementById('typeCustomer');
    const deal = document.getElementById('typeDealer');
    if (deal) deal.checked = true;
    if (cust) { cust.checked = false; cust.disabled = true; cust.closest('.sale-opt').querySelector('label').style.opacity = '.4'; }

    // Dealer field: fill + lock
    const dealerInput = document.getElementById('dealer_name');
    if (dealerInput) {
        dealerInput.value = dealer;
        dealerInput.readOnly = true;
        dealerInput.style.cssText = 'opacity:.85;cursor:not-allowed;background:rgba(245,158,11,.06);';
    }

    // Salesman: replace the select with a locked display + hidden input
    lockSalesman(salesman);

    // Deal-kind: force paid (selling), disable امانة option, show the wrap
    const kindPaid  = document.getElementById('kindPaid');
    const kindAmana = document.getElementById('kindAmana');
    if (kindPaid)  kindPaid.checked = true;
    if (kindAmana) kindAmana.disabled = true;
    if (dealKindWrap) dealKindWrap.style.display = 'block';

    // Hide customer fields, show dealer field
    document.getElementById('fieldCustomerName').style.display  = 'none';
    document.getElementById('fieldCustomerPhone').style.display = 'none';
    document.getElementById('fieldDealer').style.display        = 'block';

    refreshSubmitLabel();
}

function clearAmanaLock() {
    // Only undo if we previously locked client-side (don't fight a server lock)
    if (!amanaLocked) return;
    amanaLocked = false;

    const cust = document.getElementById('typeCustomer');
    if (cust) { cust.disabled = false; cust.closest('.sale-opt').querySelector('label').style.opacity = ''; }

    const dealerInput = document.getElementById('dealer_name');
    if (dealerInput) {
        dealerInput.value = '';
        dealerInput.readOnly = false;
        dealerInput.style.cssText = '';
    }

    unlockSalesman();

    const kindAmana = document.getElementById('kindAmana');
    if (kindAmana) kindAmana.disabled = false;

    // Default back to Customer sale
    if (cust) cust.checked = true;
    const deal = document.getElementById('typeDealer');
    if (deal) deal.checked = false;
    document.getElementById('fieldCustomerName').style.display  = '';
    document.getElementById('fieldCustomerPhone').style.display = '';
    document.getElementById('fieldDealer').style.display        = 'none';
    if (dealKindWrap) dealKindWrap.style.display = 'none';

    refreshSubmitLabel();
}

function lockSalesman(name) {
    const wrap = document.getElementById('salesmanWrap');
    if (!wrap) return;
    wrap.innerHTML =
        '<select disabled style="opacity:.85;cursor:not-allowed;background:rgba(245,158,11,.06);">' +
        '<option selected>' + (name && name.trim() !== '' ? escapeHtml(name) : '—') + '</option></select>' +
        '<input type="hidden" name="salesman" id="salesmanHidden" value="' + escapeHtml(name || '') + '">';
}

function unlockSalesman() {
    const wrap = document.getElementById('salesmanWrap');
    const tpl  = document.getElementById('salesmanOriginalTpl');
    if (!wrap || !tpl) return;
    wrap.innerHTML = tpl.innerHTML;
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

// ── Search Filter ──
// searches brand, model, trim, year, colour, branch and chassis; the branch chips narrow it further
let branchFilter = '';
function applyCarFilter() {
    const q = document.getElementById('carSearch').value.toLowerCase().trim();
    document.querySelectorAll('.car-card').forEach(card => {
        const d = card.dataset;
        const text = [d.brand, d.model, d.trim, d.year, d.color, d.branch, d.chassis].join(' ').toLowerCase();
        card.style.display = (!q || text.includes(q)) && (!branchFilter || d.bkey === branchFilter) ? '' : 'none';
    });
    const lbl = document.querySelector('.amana-group-label');
    if (lbl) lbl.style.display = Array.from(document.querySelectorAll('.car-card-amana')).some(c => c.style.display !== 'none') ? '' : 'none';
    if (window.svAfterFilter) window.svAfterFilter();
}
document.getElementById('carSearch').addEventListener('input', applyCarFilter);

// ── Sale Type Switch ──
const dealKindWrap = document.getElementById('dealKindWrap');
const submitBtn    = document.getElementById('submitBtn');
const T_SELL       = <?= json_encode($t[$lang]['sell']) ?>;
const T_AMANA      = <?= json_encode('🔶 ' . $t[$lang]['amana']) ?>;
let amanaLocked  = <?= $amanaLock ? 'true' : 'false' ?>;

function currentDealKind() {
    const k = document.querySelector('input[name="deal_kind"]:checked');
    return k ? k.value : 'paid';
}

function refreshSubmitLabel() {
    const isDealer = document.querySelector('input[name="sale_type"]:checked').value === 'dealer';
    if (isDealer && currentDealKind() === 'amana') {
        submitBtn.querySelector('span:last-child').textContent = T_AMANA;
    } else {
        submitBtn.querySelector('span:last-child').textContent = T_SELL;
    }
}

document.querySelectorAll('input[name="sale_type"]').forEach(radio => {
    radio.addEventListener('change', function () {
        const isDealer = this.value === 'dealer';
        document.getElementById('fieldCustomerName').style.display  = isDealer ? 'none' : '';
        document.getElementById('fieldCustomerPhone').style.display = isDealer ? 'none' : '';
        document.getElementById('fieldDealer').style.display        = isDealer ? '' : 'none';
        if (dealKindWrap) dealKindWrap.style.display = isDealer ? 'block' : 'none';
        refreshSubmitLabel();
    });
});

document.querySelectorAll('input[name="deal_kind"]').forEach(radio => {
    radio.addEventListener('change', refreshSubmitLabel);
});
refreshSubmitLabel();

// ── Step Indicator ──
function updateSteps() {
    if (selectedCarId) {
        document.getElementById('step1-ind').classList.add('done');
        document.getElementById('line1').classList.add('done');
        document.getElementById('step3-ind').classList.add('active');
    }
}

// ── Form Validation ──
document.getElementById('sellForm').addEventListener('submit', function (e) {
    if (!selectedCarId) {
        e.preventDefault();
        document.getElementById('carGrid').scrollIntoView({ behavior: 'smooth' });
        document.getElementById('carGrid').style.boxShadow = '0 0 0 2px rgba(248,113,113,0.6)';
        setTimeout(() => document.getElementById('carGrid').style.boxShadow = '', 2000);
        return;
    }

    const saleType = document.querySelector('input[name="sale_type"]:checked').value;

    // When closing an امانة, salesman is locked (hidden input) — skip this check.
    if (!amanaLocked) {
        const salesman = document.getElementById('salesman').value;
        if (!salesman) {
            e.preventDefault();
            document.getElementById('salesman').focus();
            document.getElementById('salesman').style.borderColor = 'rgba(248,113,113,0.8)';
            setTimeout(() => document.getElementById('salesman').style.borderColor = '', 2000);
            return;
        }
    }

    if (saleType === 'customer') {
        const name  = document.getElementById('customer_name').value.trim();
        const phone = document.getElementById('customer_phone').value.trim();
        if (!name || !phone) {
            e.preventDefault();
            if (!name)  document.getElementById('customer_name').focus();
            else        document.getElementById('customer_phone').focus();
            return;
        }
    } else {
        const dealer = document.getElementById('dealer_name').value.trim();
        if (!dealer) {
            e.preventDefault();
            document.getElementById('dealer_name').focus();
            return;
        }
    }

    // everything is filled: review it in the confirm sheet first (it sends the form)
    e.preventDefault();
    if (window.svConfirm) window.svConfirm();
    else { this.submit(); }
});

// ── Auto-select the car coming from the dashboard ──
if (preselectId && preselectId !== '0') {
    const target = document.querySelector('.car-card[data-id="' + preselectId + '"]');
    if (target) {
        selectCar(target);
    }
}
</script>
<style>
/* ═══════════ Sell-vehicle extras (the form itself is unchanged) ═══════════ */
.sv-top-r { display: flex; gap: 8px; align-items: center; }

/* live steps */
.step.active .step-bubble { box-shadow: 0 0 0 5px rgba(168,85,247,.14); }
.step.done .step-bubble { animation: svPop .35s cubic-bezier(.34,1.56,.64,1); }
@keyframes svPop { 50% { transform: scale(1.18); } }

/* branch chips */
.sv-bchips { display: flex; gap: 6px; overflow-x: auto; scrollbar-width: none; margin: -4px 0 12px; padding-bottom: 2px; }
.sv-bchips::-webkit-scrollbar { display: none; }
.sv-bchip { flex-shrink: 0; height: 32px; padding: 0 13px; border-radius: 999px; border: 1px solid var(--border); background: var(--bg-input); color: var(--text-secondary);
    font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all .2s; }
.sv-bchip b { font-size: 11px; background: rgba(255,255,255,.07); border-radius: 999px; padding: 1px 7px; color: var(--text-primary); }
.sv-bchip:hover { border-color: rgba(168,85,247,.4); color: var(--text-primary); }
.sv-bchip.on { background: rgba(147,51,234,.18); border-color: var(--purple-500); color: #fff; }
.sv-count { font-size: 11px; color: var(--text-muted); font-weight: 700; margin: -6px 2px 8px; }

/* car cards with a photo */
.car-grid { max-height: 470px; align-content: start; }
.car-card { padding: 0 0 12px; display: flex; flex-direction: column; }
.car-card > .avail-badge { position: absolute; top: 8px; inset-inline-start: 8px; float: none !important; z-index: 2; backdrop-filter: blur(6px); background: rgba(2,6,23,.55); }
.car-card.selected::before { z-index: 3; top: 8px; }
.cc-media { position: relative; aspect-ratio: 16/8.6; margin-bottom: 10px; overflow: hidden; --car: #64748b;
    background: radial-gradient(ellipse 80% 75% at 50% 15%, color-mix(in srgb, var(--car) 20%, #16223b), #0a1120 75%); border-bottom: 1px solid var(--border); }
.cc-media img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; padding: 8px 10px 4px; transition: transform .35s; }
.cc-media svg { position: absolute; inset-inline: 10%; bottom: 8%; width: 80%; height: auto; transition: transform .35s; }
.car-card:hover .cc-media img, .car-card:hover .cc-media svg { transform: scale(1.05); }
.car-card .car-brand, .car-card .car-model, .car-card .cc-meta, .car-card .car-chassis, .car-card .car-amana-dealer, .car-card .cc-res { margin-inline: 12px; }
.car-card .car-chassis { align-self: flex-start; direction: ltr; }
.cc-meta { display: flex; align-items: center; gap: 6px; font-size: 11px; color: var(--text-secondary); margin-bottom: 7px; flex-wrap: wrap; }
.cc-meta i { width: 10px; height: 10px; border-radius: 50%; border: 1px solid rgba(255,255,255,.3); flex-shrink: 0; }
.cc-res { font-size: 10px; font-weight: 800; color: #fcd34d; margin-top: 5px; }
.car-card-res { border-color: rgba(234,179,8,.32); background: linear-gradient(170deg, rgba(234,179,8,.07), var(--bg-input) 55%); }
.car-card-res:hover { border-color: rgba(234,179,8,.6); }
.car-card-res.selected { border-color: #eab308; box-shadow: 0 0 20px rgba(234,179,8,.22); }
.car-card-res.selected::before { background: #ca8a04; }
.avail-badge.res-badge { color: #fcd34d !important; border-color: rgba(234,179,8,.4) !important; }
.sv-nomatch { grid-column: 1 / -1; text-align: center; color: var(--text-muted); font-size: 13px; padding: 26px 10px; display: none; }

/* selected car: showroom stage + price */
.sv-pv { display: grid; grid-template-columns: 1.05fr 1fr; gap: 14px; margin-bottom: 16px; align-items: stretch; }
.sv-stage { position: relative; border-radius: 14px; overflow: hidden; aspect-ratio: 16/9.5; --car: #64748b;
    background: radial-gradient(ellipse 80% 70% at 50% 15%, color-mix(in srgb, var(--car) 24%, #1a2744), #0a1120 74%); border: 1px solid var(--border); }
.sv-stage::after { content: ''; position: absolute; inset-inline: 0; bottom: 0; height: 32%; background: radial-gradient(ellipse 60% 60% at 50% 30%, color-mix(in srgb, var(--car) 28%, transparent), transparent 70%); pointer-events: none; }
.sv-stage img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; padding: 10px 14px 6px; z-index: 1; }
.sv-stage svg { position: absolute; inset-inline: 7%; bottom: 7%; width: 86%; height: auto; z-index: 1; }
.sv-stage.studio { background: linear-gradient(#fff, #eef1f5); } .sv-stage.studio::after { display: none; }
.sv-stage.scene img { object-fit: cover; padding: 0; } .sv-stage.scene::after { display: none; }
.sv-side { display: flex; flex-direction: column; gap: 8px; justify-content: center; }
.sv-name { font-size: 20px; font-weight: 800; line-height: 1.25; }
.sv-name span { display: block; font-size: 13px; color: var(--text-secondary); font-weight: 600; }
.sv-price { border-radius: 13px; padding: 10px 12px; background: rgba(34,197,94,.07); border: 1px solid rgba(34,197,94,.22); }
.sv-price small { display: block; font-size: 10px; font-weight: 700; color: var(--text-muted); margin-bottom: 2px; }
.sv-price b { font-size: 20px; color: var(--green-400); font-variant-numeric: tabular-nums; }
.sv-price em { font-style: normal; font-size: 11px; color: var(--text-secondary); margin-inline-start: 4px; }
.sv-price .tag { display: inline-block; margin-top: 5px; font-size: 11px; font-weight: 800; padding: 2px 9px; border-radius: 7px; background: rgba(148,163,184,.12); color: #cbd5e1; }
.sv-price .tag.disc { background: rgba(245,158,11,.14); color: #fbbf24; } .sv-price .tag.offer { background: rgba(56,189,248,.14); color: #7dd3fc; }
.sv-price.none { background: rgba(245,158,11,.06); border-color: rgba(245,158,11,.28); color: #fbbf24; font-size: 12px; font-weight: 700; }
.sv-flag { font-size: 12px; font-weight: 700; border-radius: 11px; padding: 7px 11px; }
.sv-flag.res { background: rgba(234,179,8,.1); border: 1px solid rgba(234,179,8,.3); color: #fde68a; }
.sv-flag.am { background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.3); color: #fcd34d; }
.pi-value .pv-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-inline-end: 5px; vertical-align: -1px; border: 1px solid rgba(255,255,255,.3); }
#pvChassis { unicode-bidi: isolate; }

/* phone + dealer hints */
.sv-hint { font-size: 12px; font-weight: 700; margin-top: 6px; min-height: 0; display: flex; flex-direction: column; gap: 5px; }
.sv-hint .ok { color: var(--green-400); } .sv-hint .warn { color: #fbbf24; }
.sv-hint .back { background: rgba(168,85,247,.1); border: 1px solid rgba(168,85,247,.3); color: #e9d5ff; border-radius: 10px; padding: 7px 10px; font-weight: 600; line-height: 1.6; }
.sv-hint .back b { color: #fff; }
.field-group.sv-ok input, .field-group.sv-ok select { border-color: rgba(34,197,94,.4) !important; }

/* confirm sheet */
.sv-ov { position: fixed; inset: 0; z-index: 900; background: rgba(2,6,23,.8); backdrop-filter: blur(7px); display: none; align-items: center; justify-content: center; padding: 18px; }
.sv-ov.on { display: flex; animation: svFade .2s ease both; }
@keyframes svFade { from { opacity: 0; } }
.sv-sheet { width: 100%; max-width: 470px; max-height: 92vh; overflow-y: auto; border-radius: 26px; background: linear-gradient(170deg, #151b36, #0a1122); border: 1px solid rgba(168,85,247,.25);
    box-shadow: 0 40px 100px rgba(0,0,0,.6); animation: svIn .3s cubic-bezier(.22,1,.36,1) both; }
.sv-sheet.amana { border-color: rgba(245,158,11,.45); }
@keyframes svIn { from { transform: translateY(22px) scale(.97); opacity: 0; } }
.sv-sheet .sv-stage { border-radius: 26px 26px 0 0; border: 0; aspect-ratio: 16/8.5; }
.sv-sb { padding: 18px 22px 22px; }
.sv-kind { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 800; padding: 4px 12px; border-radius: 999px; background: rgba(34,197,94,.14); color: #86efac; }
.sv-sheet.amana .sv-kind { background: rgba(245,158,11,.16); color: #fcd34d; }
.sv-st { font-size: 22px; font-weight: 800; margin-top: 8px; }
.sv-ss { font-size: 13px; color: var(--text-secondary); font-weight: 600; }
.sv-g { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin: 14px 0 12px; }
.sv-g div { background: rgba(255,255,255,.03); border: 1px solid var(--border); border-radius: 13px; padding: 9px 12px; min-width: 0; }
.sv-g div.w { grid-column: 1 / -1; }
.sv-g small { display: block; font-size: 10px; font-weight: 700; color: var(--text-muted); margin-bottom: 3px; }
.sv-g b { font-size: 14px; display: flex; align-items: center; gap: 6px; word-break: break-word; }
.sv-g b i { width: 11px; height: 11px; border-radius: 50%; border: 1px solid rgba(255,255,255,.3); flex-shrink: 0; }
.sv-plate { text-align: center; margin: 4px 0; }
.sv-plate small { display: block; font-size: 11px; font-weight: 700; color: var(--text-muted); margin-bottom: 6px; }
.sv-plate span { display: inline-block; direction: ltr; font-family: 'SFMono-Regular', Consolas, monospace; font-size: 26px; font-weight: 900; letter-spacing: .14em; color: #111827;
    background: linear-gradient(#fefce8, #fde68a); border: 3px solid #1f2937; border-radius: 12px; padding: 5px 16px; box-shadow: 0 0 0 2px #fde68a, 0 12px 30px rgba(0,0,0,.4); word-break: break-all; }
.sv-note { font-size: 12px; color: var(--text-secondary); background: rgba(255,255,255,.03); border-radius: 11px; padding: 8px 12px; margin-top: 10px; white-space: pre-wrap; }
.sv-acts { display: flex; flex-direction: column; gap: 8px; margin-top: 16px; }
.sv-acts button { height: 52px; border-radius: 14px; border: 1px solid var(--border); font: inherit; font-size: 15px; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all .2s; }
.sv-ok-b { background: linear-gradient(135deg, var(--green-500), #16a34a); color: #fff; border: 0 !important; box-shadow: 0 8px 26px rgba(34,197,94,.3); }
.sv-sheet.amana .sv-ok-b { background: linear-gradient(135deg, #f59e0b, #d97706); box-shadow: 0 8px 26px rgba(245,158,11,.3); }
.sv-back-b { background: transparent; color: var(--text-secondary); }
.sv-acts button:disabled { opacity: .5; cursor: not-allowed; }
.sv-spin { width: 14px; height: 14px; border-radius: 50%; border: 2px solid rgba(255,255,255,.35); border-top-color: #fff; animation: svSpin .7s linear infinite; display: inline-block; }
@keyframes svSpin { to { transform: rotate(360deg); } }

/* success */
.sv-done { position: relative; overflow: hidden; display: flex; gap: 18px; align-items: center; margin-bottom: 20px; padding: 16px; border-radius: 24px;
    background: linear-gradient(120deg, rgba(34,197,94,.16), rgba(15,23,42,.92) 62%); border: 1px solid rgba(34,197,94,.35); box-shadow: 0 20px 50px rgba(0,0,0,.35); animation: svIn .45s cubic-bezier(.22,1,.36,1) both; }
.sv-done.amana { background: linear-gradient(120deg, rgba(245,158,11,.16), rgba(15,23,42,.92) 62%); border-color: rgba(245,158,11,.4); }
.sv-done-stage { width: 210px; flex-shrink: 0; }
.sv-done-stage .sv-stage { aspect-ratio: 16/10; }
.sv-done-b { flex: 1; min-width: 0; }
.sv-done-t { font-size: 18px; font-weight: 800; color: var(--green-400); }
.sv-done.amana .sv-done-t { color: #fbbf24; }
.sv-done-car { font-size: 16px; font-weight: 800; margin: 4px 0 8px; }
.sv-done-car span { color: var(--text-secondary); font-weight: 600; }
.sv-done-meta { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 6px; }
.sv-done-meta span { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; background: rgba(255,255,255,.05); border: 1px solid var(--border); padding: 4px 10px; border-radius: 999px; color: #e2e8f0; }
.sv-done-meta i { width: 10px; height: 10px; border-radius: 50%; border: 1px solid rgba(255,255,255,.3); }
.mono { font-family: 'SFMono-Regular', Consolas, monospace; direction: ltr; unicode-bidi: isolate; }
.sv-done-a { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
.sv-btn { height: 38px; padding: 0 15px; border-radius: 11px; display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 800; text-decoration: none; color: var(--text-primary);
    background: rgba(255,255,255,.05); border: 1px solid var(--border); transition: all .2s; }
.sv-btn:hover { border-color: rgba(255,255,255,.2); }
.sv-btn-p { background: var(--green-500); border-color: var(--green-500); color: #052e16; }
.sv-done.amana .sv-btn-p { background: #f59e0b; border-color: #f59e0b; color: #1c1002; }
.sv-confetti { position: absolute; top: -10px; width: 7px; height: 12px; border-radius: 2px; pointer-events: none; animation: svFall 1.9s cubic-bezier(.25,.6,.4,1) forwards; }
@keyframes svFall { to { transform: translateY(200px) rotate(560deg); opacity: 0; } }

/* today */
.sv-today { margin-top: 18px; }
.sv-today-h { display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 16px; font-weight: 800; margin-bottom: 14px; }
.sv-today-n { font-size: 12px; font-weight: 700; color: var(--text-secondary); background: rgba(34,197,94,.08); border: 1px solid rgba(34,197,94,.2); padding: 4px 11px; border-radius: 999px; }
.sv-today-n b { color: var(--green-400); } .sv-today-n b.am { color: #fbbf24; }
.sv-today-empty { text-align: center; color: var(--text-muted); font-size: 13px; padding: 18px; border: 1px dashed var(--border); border-radius: 14px; }
.sv-today-list { display: flex; flex-direction: column; gap: 7px; max-height: 360px; overflow-y: auto; }
.sv-ti { display: flex; align-items: center; gap: 11px; padding: 10px 12px; border-radius: 14px; background: rgba(255,255,255,.025); border: 1px solid transparent; }
.sv-ti.amana { background: rgba(245,158,11,.05); border-color: rgba(245,158,11,.18); }
.sv-ti.fresh { border-color: rgba(34,197,94,.5); background: rgba(34,197,94,.08); animation: svFresh 1.6s ease 2; }
@keyframes svFresh { 50% { box-shadow: 0 0 0 4px rgba(34,197,94,.18); } }
.sv-ti-dot { width: 14px; height: 14px; border-radius: 50%; border: 2px solid rgba(255,255,255,.2); flex-shrink: 0; }
.sv-ti-m { flex: 1; min-width: 0; }
.sv-ti-n { font-size: 13px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sv-ti-n span { color: var(--text-muted); font-weight: 600; }
.sv-ti-s { font-size: 11px; color: var(--text-secondary); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sv-ti-r { display: flex; flex-direction: column; align-items: flex-end; gap: 2px; flex-shrink: 0; }
.sv-ti-t { font-size: 11px; color: var(--text-muted); font-weight: 700; direction: ltr; }
.sv-ti-c { font-family: 'SFMono-Regular', Consolas, monospace; font-size: 11px; color: #fbbf24; font-weight: 800; direction: ltr; }


@media (max-width: 600px) {
    .car-grid { grid-template-columns: 1fr 1fr; max-height: 430px; gap: 8px; }
    .car-card .car-brand { font-size: 13px; }
    .car-card .car-model { font-size: 11px; }
    .sv-pv { grid-template-columns: 1fr; }
    .submit-btn { position: sticky; bottom: 12px; z-index: 50; box-shadow: 0 10px 30px rgba(0,0,0,.55), 0 4px 20px rgba(147,51,234,.3); }
    .sv-done { flex-direction: column; align-items: stretch; }
    .sv-done-stage { width: 100%; }
    .sv-ov { align-items: flex-end; padding: 0; }
    .sv-sheet { max-width: none; border-radius: 26px 26px 0 0; animation: svUp .34s cubic-bezier(.22,1,.36,1) both; }
    @keyframes svUp { from { transform: translateY(100%); } }
    .sv-plate span { font-size: 22px; }
}
@media (max-width: 360px) { .car-grid { grid-template-columns: 1fr; } }
@media (prefers-reduced-motion: reduce) { .sv-ti.fresh { animation: none; } .sv-confetti { display: none; } }
</style>

<div class="sv-ov" id="svOv" aria-hidden="true"><div class="sv-sheet" id="svSheet" role="dialog" aria-modal="true"></div></div>

<script>
(function () {
    'use strict';
    const AR = <?= json_encode($lang === 'ar') ?>, LANG = <?= json_encode($lang) ?>;
    const PRICES = <?= json_encode((object)$svPrices, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const CAN_PRICE = <?= can('page.prices') ? 'true' : 'false' ?>;
    const RESELECT = <?= json_encode($svReselect, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const CUR = AR ? 'جنيه' : 'EGP';
    const T = AR ? {
        all: 'كل الفروع', shown: n => n + ' سيارة معروضة', nomatch: 'لا توجد سيارة تطابق البحث', resBy: b => '🟡 محجوزة' + (b ? ' بواسطة ' + b : ''),
        price: '💰 السعر الرسمي', noPrice: '⚠️ لم تُسعّر هذه الفئة بعد', resFlag: b => '🟡 هذه السيارة محجوزة' + (b ? ' بواسطة ' + b : '') + ' — تأكد قبل البيع',
        amFlag: d => '🔶 سيارة أمانة عند ' + d + ' — سيتم إغلاق الأمانة كبيع',
        egOk: '✓ رقم موبايل مصري صحيح', egWarn: '⚠️ تأكد من الرقم — الرقم المصري ١١ رقم ويبدأ بـ 01', returning: '⭐ عميل سابق —', bought: 'اشترى', on: 'في', by: 'البائع',
        kSale: '🎉 بيع لعميل', kDealer: '🤝 بيع لتاجر', kAmana: '🔶 خروج أمانة', kClose: '✅ إغلاق أمانة كبيع', review: 'راجع البيانات قبل التأكيد',
        cust: 'العميل', phone: 'الهاتف', dealer: 'التاجر', salesman: 'البائع', color: 'اللون', branch: 'الفرع', chassis: 'رقم الشاسيه',
        ok: '✓ تأكيد البيع', okAm: '🔶 تأكيد خروج الأمانة', back: '✏️ رجوع للتعديل', saving: 'جارٍ الحفظ…'
    } : {
        all: 'All branches', shown: n => n + ' cars shown', nomatch: 'No vehicle matches the search', resBy: b => '🟡 Reserved' + (b ? ' by ' + b : ''),
        price: '💰 Official price', noPrice: '⚠️ This trim has no price yet', resFlag: b => '🟡 This car is reserved' + (b ? ' by ' + b : '') + ' — double-check before selling',
        amFlag: d => '🔶 Consignment car with ' + d + ' — the consignment closes as a sale',
        egOk: '✓ Valid Egyptian mobile', egWarn: '⚠️ Check the number — Egyptian mobiles are 11 digits starting with 01', returning: '⭐ Returning customer —', bought: 'bought', on: 'on', by: 'salesman',
        kSale: '🎉 Sale to customer', kDealer: '🤝 Sale to dealer', kAmana: '🔶 Out on consignment', kClose: '✅ Consignment closed as sale', review: 'Check the details before confirming',
        cust: 'Customer', phone: 'Phone', dealer: 'Dealer', salesman: 'Salesman', color: 'Colour', branch: 'Branch', chassis: 'Chassis No.',
        ok: '✓ Confirm sale', okAm: '🔶 Confirm consignment', back: '✏️ Back to edit', saving: 'Saving…'
    };
    const $ = id => document.getElementById(id);
    const esc = v => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const dealKind = v => /خصم|discount/i.test(v) ? 'disc' : (/أوفر|offer/i.test(v) ? 'offer' : '');
    const reduced = matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    const SIL = c => '<svg viewBox="0 0 320 130" aria-hidden="true"><defs><linearGradient id="svSh" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff" stop-opacity=".38"/><stop offset=".45" stop-color="#fff" stop-opacity=".06"/><stop offset="1" stop-color="#000" stop-opacity=".35"/></linearGradient></defs>' +
        '<ellipse cx="163" cy="119" rx="142" ry="7" fill="#000" opacity=".5"/>' +
        '<path fill="' + c + '" d="M20,92 L22,72 Q24,62 36,60 L80,55 L112,31 Q118,26 128,26 L222,26 Q234,26 242,34 L268,57 L292,61 Q306,64 306,78 L306,92 Q306,98 300,98 L277,98 A27,27 0 0 0 223,98 L105,98 A27,27 0 0 0 51,98 L26,98 Q20,98 20,92 Z"/>' +
        '<path fill="url(#svSh)" d="M20,92 L22,72 Q24,62 36,60 L80,55 L112,31 Q118,26 128,26 L222,26 Q234,26 242,34 L268,57 L292,61 Q306,64 306,78 L306,92 Q306,98 300,98 L277,98 A27,27 0 0 0 223,98 L105,98 A27,27 0 0 0 51,98 L26,98 Q20,98 20,92 Z"/>' +
        '<path d="M88,57 L116,35 Q120,32 126,32 L166,32 L166,57 Z M174,32 L221,32 Q229,32 235,38 L256,57 L174,57 Z" fill="#0b1220" opacity=".82"/>' +
        '<path d="M292,70 L304,72" stroke="#fde68a" stroke-width="4" stroke-linecap="round"/><path d="M22,74 L30,73" stroke="#f87171" stroke-width="4" stroke-linecap="round"/>' +
        '<circle cx="78" cy="98" r="21" fill="#0b1220" stroke="#1e293b" stroke-width="5"/><circle cx="78" cy="98" r="9" fill="#94a3b8"/>' +
        '<circle cx="250" cy="98" r="21" fill="#0b1220" stroke="#1e293b" stroke-width="5"/><circle cx="250" cy="98" r="9" fill="#94a3b8"/></svg>';
    const stageHTML = (img, hex) => '<div class="sv-stage" style="--car:' + hex + '">' + (img ? '<img src="' + esc(img) + '" alt="">' : SIL(hex)) + '</div>';
    function frame(st) {   // studio / scene photos get a matching backdrop
        const img = st.querySelector('img'); if (!img) return;
        const go = () => { try {
            const w = 60, h = Math.max(12, Math.round(w * img.naturalHeight / img.naturalWidth)), cv = document.createElement('canvas'); cv.width = w; cv.height = h;
            const cx = cv.getContext('2d', { willReadFrequently: true }); cx.drawImage(img, 0, 0, w, h); const d = cx.getImageData(0, 0, w, h).data, ring = [];
            const at = (x, y) => { const i = (y * w + x) * 4; return [d[i], d[i + 1], d[i + 2]]; };
            for (let x = 0; x < w; x += 3) { ring.push(at(x, 0)); ring.push(at(x, h - 1)); } for (let y = 0; y < h; y += 2) { ring.push(at(0, y)); ring.push(at(w - 1, y)); }
            const m = [0, 1, 2].map(k => ring.reduce((a, p) => a + p[k], 0) / ring.length);
            const sp = Math.sqrt(ring.reduce((a, p) => a + (p[0] - m[0]) ** 2 + (p[1] - m[1]) ** 2 + (p[2] - m[2]) ** 2, 0) / ring.length);
            if (sp > 34) st.classList.add('scene'); else if (.299 * m[0] + .587 * m[1] + .114 * m[2] > 226) st.classList.add('studio');
        } catch (e) {} };
        img.addEventListener('error', () => { img.outerHTML = SIL(getComputedStyle(st).getPropertyValue('--car').trim() || '#64748b'); }, { once: true });
        if (img.complete && img.naturalWidth) go(); else img.addEventListener('load', go, { once: true });
    }

    /* ═══ car cards: photo, colour, branch, reservation ═══ */
    const cards = Array.from(document.querySelectorAll('.car-card'));
    cards.forEach(c => {
        const d = c.dataset, hex = d.sw || '#64748b';
        const media = document.createElement('div'); media.className = 'cc-media'; media.style.setProperty('--car', hex);
        media.innerHTML = d.img ? '<img src="' + esc(d.img) + '" alt="" loading="lazy">' : SIL(hex);
        const im = media.querySelector('img'); if (im) im.addEventListener('error', () => { im.outerHTML = SIL(hex); }, { once: true });
        c.insertBefore(media, c.querySelector('.car-brand'));
        const mod = c.querySelector('.car-model');
        if (mod) {
            if (d.year) mod.textContent = mod.textContent + ' · ' + d.year;
            mod.insertAdjacentHTML('afterend', '<div class="cc-meta"><i style="background:' + hex + '"></i>' + esc(d.color) + ' · 📍 ' + esc(d.branch) + '</div>');
        }
        if (d.res === '1') { c.classList.add('car-card-res'); c.insertAdjacentHTML('beforeend', '<div class="cc-res">' + esc(T.resBy(d.resby)) + '</div>'); }
    });

    /* ═══ branch chips + result count ═══ */
    const grid = $('carGrid');
    const branches = {};
    cards.forEach(c => { const k = c.dataset.bkey; if (k) (branches[k] = branches[k] || { label: c.dataset.branch, n: 0 }).n++; });
    const chips = document.createElement('div'); chips.className = 'sv-bchips';
    const count = document.createElement('div'); count.className = 'sv-count';
    if (Object.keys(branches).length > 1) {
        chips.innerHTML = '<button type="button" class="sv-bchip on" data-b="">' + esc(T.all) + ' <b>' + cards.length + '</b></button>' +
            Object.entries(branches).map(([k, o]) => '<button type="button" class="sv-bchip" data-b="' + esc(k) + '">📍 ' + esc(o.label) + ' <b>' + o.n + '</b></button>').join('');
        grid.parentNode.insertBefore(chips, grid);
        chips.addEventListener('click', e => {
            const b = e.target.closest('.sv-bchip'); if (!b) return;
            branchFilter = b.dataset.b;   // the page's own filter reads this
            chips.querySelectorAll('.sv-bchip').forEach(x => x.classList.toggle('on', x === b));
            applyCarFilter();
        });
    }
    grid.parentNode.insertBefore(count, grid);
    const nomatch = document.createElement('div'); nomatch.className = 'sv-nomatch'; nomatch.textContent = T.nomatch; grid.appendChild(nomatch);
    window.svAfterFilter = function () {
        const n = cards.filter(c => c.style.display !== 'none').length;
        count.textContent = cards.length ? T.shown(n) : '';
        nomatch.style.display = cards.length && !n ? 'block' : 'none';
    };
    window.svAfterFilter();

    /* ═══ the chosen car: stage, price, flags ═══ */
    const pv = $('vehiclePreview');
    const pvTop = document.createElement('div'); pvTop.className = 'sv-pv';
    pv.insertBefore(pvTop, pv.querySelector('h4').nextSibling);
    let current = null;
    function showCar(el) {
        current = el; const d = el.dataset, hex = d.sw || '#64748b';
        let side = '<div class="sv-name">' + esc(d.brand + ' ' + d.model) + '<span>' + esc([d.trim, d.year].filter(Boolean).join(' · ')) + '</span></div>';
        if (CAN_PRICE) {
            const p = PRICES[d.pkey];
            side += p && p.off ? '<div class="sv-price"><small>' + esc(T.price) + '</small><b>' + esc(p.off) + '</b><em>' + esc(CUR) + '</em>' + (p.cust ? '<br><span class="tag ' + dealKind(p.cust) + '">' + esc(p.cust) + '</span>' : '') + '</div>'
                               : '<div class="sv-price none">' + esc(T.noPrice) + '</div>';
        }
        if (d.res === '1') side += '<div class="sv-flag res">' + esc(T.resFlag(d.resby)) + '</div>';
        if (d.amana === '1') side += '<div class="sv-flag am">' + esc(T.amFlag(d.dealer || '—')) + '</div>';
        pvTop.innerHTML = stageHTML(d.img ? d.img.replace(/_t(\.\w+)$/, '$1') : '', hex) + '<div class="sv-side">' + side + '</div>';
        const st = pvTop.querySelector('.sv-stage'); const im = st.querySelector('img');
        if (im && d.img) im.addEventListener('error', () => { if (im.src.indexOf(d.img) === -1) im.src = d.img; }, { once: true });
        frame(st);
        const pc = $('pvColor'); if (pc) pc.innerHTML = '<i class="pv-dot" style="background:' + hex + '"></i>' + esc(d.color || '—');
        steps();
    }
    const origSelect = window.selectCar;
    window.selectCar = function (el) { origSelect(el); showCar(el); };
    cards.forEach(c => c.setAttribute('onclick', 'selectCar(this)'));
    const pre = document.querySelector('.car-card.selected'); if (pre) showCar(pre);

    /* ═══ phone: Egyptian check + returning customer ═══ */
    const phone = $('customer_phone'), hint = $('phoneHint');
    let ptm = null, pseq = 0;
    function egNorm(v) { let d = String(v).replace(/\D/g, ''); if (d.indexOf('0020') === 0) d = '0' + d.slice(4); else if (d.indexOf('20') === 0 && d.length === 12) d = '0' + d.slice(2); return d; }
    function phoneCheck() {
        const raw = phone.value.trim(), d = egNorm(raw);
        const g = phone.closest('.field-group');
        if (!raw) { hint.innerHTML = ''; g.classList.remove('sv-ok'); steps(); return; }
        const ok = /^01[0125]\d{8}$/.test(d);
        g.classList.toggle('sv-ok', ok);
        let h = '<span class="' + (ok ? 'ok' : 'warn') + '">' + esc(ok ? T.egOk : T.egWarn) + '</span>';
        hint.innerHTML = h + (hint.querySelector('.back') ? hint.querySelector('.back').outerHTML : '');
        clearTimeout(ptm);
        if (d.length >= 9) {
            const my = ++pseq;
            ptm = setTimeout(() => fetch('sold_vehicle.php?ajax=phone&q=' + encodeURIComponent(d), { credentials: 'same-origin' }).then(r => r.json()).then(j => {
                if (my !== pseq) return;
                const hits = (j && j.hits) || [];
                const old = hint.querySelector('.back'); if (old) old.remove();
                if (hits.length) hint.insertAdjacentHTML('beforeend', '<div class="back">' + esc(T.returning) + ' <b>' + esc(hits[0].customer_name || '') + '</b><br>' +
                    hits.map(x => esc(T.bought) + ' <b>' + esc([x.brand, x.model, x.car_year, x.trim_name].filter(Boolean).join(' ')) + '</b> ' + esc(T.on) + ' ' + esc(String(x.sold_at).slice(0, 10)) + (x.salesman ? ' · ' + esc(T.by) + ' ' + esc(x.salesman) : '')).join('<br>') + '</div>');
            }).catch(() => {}), 350);
        } else { const old = hint.querySelector('.back'); if (old) old.remove(); }
        steps();
    }
    if (phone) {
        phone.addEventListener('input', phoneCheck);
        phone.addEventListener('blur', () => { const v = phone.value.replace(/[\s\-().]/g, ''); if (v !== phone.value) { phone.value = v; phoneCheck(); } });
        if (phone.value) phoneCheck();
    }

    /* ═══ live steps: 1 car · 2 details · 3 confirm ═══ */
    function detailsOk() {
        const type = (document.querySelector('input[name="sale_type"]:checked') || {}).value;
        const sm = $('salesman');
        if (!amanaLocked && !(sm && sm.value)) return false;
        if (type === 'customer') return !!($('customer_name').value.trim() && phone.value.trim());
        return !!$('dealer_name').value.trim();
    }
    function steps(confirming) {
        const has = !!$('carIdInput').value, ok = has && detailsOk();
        const s1 = $('step1-ind'), s2 = $('step2-ind'), s3 = $('step3-ind');
        s1.classList.toggle('done', has); s1.classList.toggle('active', !has); $('line1').classList.toggle('done', has);
        s2.classList.toggle('active', has && !ok); s2.classList.toggle('done', ok); $('line2').classList.toggle('done', ok);
        s3.classList.toggle('active', ok || !!confirming); s3.classList.remove('done');
        document.querySelectorAll('.fields-grid .field-group').forEach(g => {
            const inp = g.querySelector('input:not([type=hidden]), select, textarea');
            if (inp && inp.id !== 'customer_phone' && inp.id !== 'notes') g.classList.toggle('sv-ok', !!(inp.value && inp.value.trim()) && inp.offsetParent !== null);
        });
    }
    window.updateSteps = () => steps();
    $('sellForm').addEventListener('input', () => steps());
    $('sellForm').addEventListener('change', () => steps());
    steps();

    /* ═══ confirm sheet (and a one-time lock) ═══ */
    const ov = $('svOv'), sheet = $('svSheet');
    let locked = false;
    function closeOv() { if (locked) return; ov.classList.remove('on'); ov.setAttribute('aria-hidden', 'true'); document.body.style.overflow = ''; sheet.className = 'sv-sheet'; steps(); }
    function openOv() { ov.classList.add('on'); ov.setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden'; }
    ov.addEventListener('click', e => { if (e.target === ov) closeOv(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && ov.classList.contains('on')) closeOv(); });
    window.svConfirm = function () {
        if (locked) return;
        const el = document.querySelector('.car-card.selected'); if (!el) return;
        const d = el.dataset, hex = d.sw || '#64748b';
        const type = (document.querySelector('input[name="sale_type"]:checked') || {}).value;
        const kind = (document.querySelector('input[name="deal_kind"]:checked') || {}).value || 'paid';
        const isAm = type === 'dealer' && kind === 'amana' && !amanaLocked;
        const label = amanaLocked ? T.kClose : isAm ? T.kAmana : type === 'dealer' ? T.kDealer : T.kSale;
        const sm = $('salesman') ? $('salesman').value : (($('salesmanHidden') || document.querySelector('input[name="salesman"]')) || {}).value;
        const p = CAN_PRICE ? PRICES[d.pkey] : null;
        let h = stageHTML(d.img ? d.img.replace(/_t(\.\w+)$/, '$1') : '', hex) + '<div class="sv-sb">';
        h += '<span class="sv-kind">' + esc(label) + '</span><div class="sv-st">' + esc(d.brand + ' ' + d.model + ' ' + (d.year || '')) + '</div><div class="sv-ss">' + esc(d.trim || '') + ' · ' + esc(T.review) + '</div>';
        h += '<div class="sv-g">';
        if (type === 'customer') h += '<div><small>👤 ' + esc(T.cust) + '</small><b>' + esc($('customer_name').value.trim()) + '</b></div><div><small>📞 ' + esc(T.phone) + '</small><b class="mono">' + esc(phone.value.trim()) + '</b></div>';
        else h += '<div class="w"><small>🤝 ' + esc(T.dealer) + '</small><b>' + esc($('dealer_name').value.trim()) + '</b></div>';
        h += '<div><small>🧑‍💼 ' + esc(T.salesman) + '</small><b>' + esc(sm || '—') + '</b></div>';
        h += '<div><small>🎨 ' + esc(T.color) + '</small><b><i style="background:' + hex + '"></i>' + esc(d.color || '—') + '</b></div>';
        h += '<div><small>📍 ' + esc(T.branch) + '</small><b>' + esc(d.branch || '—') + '</b></div>';
        h += '<div><small>' + esc(T.price) + '</small><b>' + (p && p.off ? esc(p.off) + ' <span style="font-size:11px;color:#94a3b8">' + esc(CUR) + '</span>' : '—') + '</b></div>';
        h += '</div><div class="sv-plate"><small>🔑 ' + esc(T.chassis) + '</small><span>' + esc(d.chassis) + '</span></div>';
        const notes = $('notes').value.trim(); if (notes) h += '<div class="sv-note">📝 ' + esc(notes) + '</div>';
        h += '<div class="sv-acts"><button type="button" class="sv-ok-b">' + esc(isAm ? T.okAm : T.ok) + '</button><button type="button" class="sv-back-b">' + esc(T.back) + '</button></div></div>';
        sheet.innerHTML = h; sheet.className = 'sv-sheet' + (isAm || amanaLocked ? ' amana' : '');
        frame(sheet.querySelector('.sv-stage'));
        sheet.querySelector('.sv-back-b').addEventListener('click', closeOv);
        sheet.querySelector('.sv-ok-b').addEventListener('click', function () {
            if (locked) return; locked = true;
            this.disabled = true; sheet.querySelector('.sv-back-b').disabled = true;
            this.innerHTML = '<span class="sv-spin"></span> ' + esc(T.saving);
            const b = $('submitBtn'); b.disabled = true;
            $('step3-ind').classList.add('done');
            $('sellForm').submit();
        });
        steps(true); openOv();
        setTimeout(() => sheet.querySelector('.sv-ok-b').focus(), 60);
    };

    /* ═══ after an error: the same car and choices come back ═══ */
    if (RESELECT && RESELECT.car) {
        const c = document.querySelector('.car-card[data-id="' + RESELECT.car + '"]');
        if (c && !c.classList.contains('selected')) window.selectCar(c);
        if (!amanaLocked && RESELECT.type) {
            const r = document.querySelector('input[name="sale_type"][value="' + RESELECT.type + '"]');
            if (r && !r.disabled) { r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); }
            const k = document.querySelector('input[name="deal_kind"][value="' + RESELECT.kind + '"]');
            if (k && !k.disabled) { k.checked = true; k.dispatchEvent(new Event('change', { bubbles: true })); }
        }
        steps();
    }

    /* ═══ success: the car on stage, confetti, "sell another" ═══ */
    const done = $('svDone');
    if (done) {
        const hex = done.dataset.sw || '#64748b';
        $('svDoneStage').innerHTML = stageHTML(done.dataset.img, hex);
        frame($('svDoneStage').querySelector('.sv-stage'));
        if (!reduced) {
            const cols = ['#22c55e', '#a855f7', '#3b82f6', '#f59e0b', '#f472b6', hex];
            for (let i = 0; i < 30; i++) {
                const c = document.createElement('i'); c.className = 'sv-confetti';
                c.style.left = (Math.random() * 100) + '%'; c.style.background = cols[i % cols.length]; c.style.animationDelay = (Math.random() * .5) + 's';
                done.appendChild(c); setTimeout(() => c.remove(), 2700);
            }
        }
        $('svAnother').addEventListener('click', e => { e.preventDefault(); $('sellForm').scrollIntoView({ behavior: 'smooth', block: 'start' }); setTimeout(() => $('carSearch').focus({ preventScroll: true }), 450); });
    }

})();
</script>

</body>
</html>