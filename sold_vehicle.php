<?php

require 'auth.php';
require 'config.php';

perm_require('page.sold_vehicle');

$lang = $_GET['lang'] ?? 'ar';

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
    $car_id         = (int)  $_POST['car_id'];
    $sale_type      = trim(  $_POST['sale_type']);
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

    if ($car) {
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
            $pdo->rollBack();
            $error = 'DB Error: ' . $e->getMessage();
        }
    }
}

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

        <a href="sold_vehicle.php?lang=<?= $other_lang ?>" class="lang-btn">
            🌐 <?= $t[$lang]['lang_switch'] ?>
        </a>
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
    <?php if ($success): ?>
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
                        onclick="selectCar(this)"
                    >
                        <span class="avail-badge"><?= $t[$lang]['available'] ?></span>
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
                        placeholder="<?= $lang === 'ar' ? '05xxxxxxxx' : '+1 555 0000' ?>"
                        value="<?= htmlspecialchars($_POST['customer_phone'] ?? '') ?>"
                    >
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

            <button type="submit" class="submit-btn" id="submitBtn">
                <span>🚗</span>
                <span><?= $t[$lang]['sell'] ?></span>
            </button>

        </form>
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
document.getElementById('carSearch').addEventListener('input', function () {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('.car-card').forEach(card => {
        const text = (card.dataset.brand + ' ' + card.dataset.model + ' ' + card.dataset.chassis).toLowerCase();
        card.style.display = text.includes(q) ? '' : 'none';
    });
});

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

    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span>⏳</span><span>...</span>';

    document.getElementById('step2-ind').classList.add('done');
    document.getElementById('step3-ind').classList.add('done');
    document.getElementById('line2').classList.add('done');
});

// ── Auto-select the car coming from the dashboard ──
if (preselectId && preselectId !== '0') {
    const target = document.querySelector('.car-card[data-id="' + preselectId + '"]');
    if (target) {
        selectCar(target);
    }
}
</script>
</body>
</html>