<?php

require 'auth.php';
require 'config.php';
require_once __DIR__ . '/push_helpers.php';
require 'car_images_helpers.php';

perm_require('page.add_vehicle');

$lang = $_GET['lang'] ?? 'ar';

$error   = '';
$success = '';
$addedVehicle = null;

/* Form token (same session key the other pages use) */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

/* ─── Live chassis check (asked while typing) ───
   Same rule as the save below: an exact, case-insensitive match in cars. */
if (($_GET['ajax'] ?? '') === 'chassis') {
    header('Content-Type: application/json; charset=utf-8');
    $q = strtoupper(trim((string)($_GET['q'] ?? '')));
    if (strlen($q) < 4) { echo json_encode(['exists' => false]); exit; }
    $st = $pdo->prepare("SELECT id, brand, model, car_year, trim_name, color, branch, status
                         FROM cars WHERE UPPER(chassis) = UPPER(?) LIMIT 1");
    $st->execute([$q]);
    $hit = $st->fetch(PDO::FETCH_ASSOC);
    echo json_encode($hit ? ['exists' => true, 'car' => $hit] : ['exists' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ─────────────────────────────────────────────────────────────
   MOTIVATIONAL QUOTES  ← edit freely
───────────────────────────────────────────────────────────── */
$quotes = [
    'ar' => [
        '"كل سيارة تُضاف هي خطوة نحو النجاح."',
        '"الدقة في التفاصيل تصنع الفارق."',
        '"مخزون قوي يعني مستقبل أفضل."',
        '"العمل الجاد اليوم هو الإنجاز الذي تفتخر به غداً."',
        '"من يُتقن عمله اليوم يحصد ثماره غداً."',
    ],
    'en' => [
        '"Every vehicle added is a step toward success."',
        '"Precision in details makes all the difference."',
        '"A strong inventory means a brighter future."',
        '"Hard work today is tomorrow\'s achievement."',
        '"Master your work today, harvest the rewards tomorrow."',
    ],
];

// Pick a stable daily quote (changes each day)
$quoteIndex    = date('j') % count($quotes['ar']);
$currentQuote  = $quotes[$lang][$quoteIndex];

/* ─────────────────────────────────────────────────────────────
   TRANSLATIONS
───────────────────────────────────────────────────────────── */
$t = [
    'ar' => [
        'title'          => 'إضافة سيارة جديدة',
        'subtitle'       => 'إضافة سيارة إلى المخزون',
        'total_cars'     => 'إجمالي السيارات',
        'available_cars' => 'السيارات المتاحة',
        'branches'       => 'الفروع',
        'dashboard'      => 'الرئيسية',
        'inventory'      => 'المخزون',
        'users'          => 'المستخدمين',
        'brand'          => 'الماركة',
        'model'          => 'الموديل',
        'year'           => 'سنة الصنع',
        'trim'           => 'الفئة',
        'color'          => 'اللون',
        'chassis'        => 'رقم الشاسيه',
        'branch'         => 'الفرع',
        'notes'          => 'ملاحظات',
        'select_brand'   => 'اختر الماركة',
        'select_model'   => 'اختر الموديل',
        'select_trim'    => 'اختر الفئة',
        'select_color'   => 'اختر اللون',
        'select_branch'  => 'اختر الفرع',
        'select_year'    => 'اختر سنة الصنع',
        'preview'        => 'معاينة السيارة',
        'add_vehicle'    => 'إضافة السيارة',
        'fill_required'  => 'يرجى استكمال جميع البيانات المطلوبة',
        'chassis_exists' => 'رقم الشاسيه مسجّل بالفعل في النظام',
        'token_expired'  => 'انتهت صلاحية الصفحة — حدّث الصفحة وحاول مرة أخرى',
        'scan_title'     => 'مسح باركود الشاسيه',
        'scan_hint'      => 'التقط صورة قريبة وواضحة لباركود الشاسيه — قرّب حتى يملأ الباركود الصورة وتجنّب الانعكاسات',
        'scan_close'     => 'إغلاق',
        'scan_fail'      => 'تعذّر تشغيل الكاميرا — تأكد من السماح بالوصول للكاميرا',
        'scan_photo'     => 'من صورة',
        'scan_photo_fail'=> 'لم يتم التعرف على الباركود في الصورة — جرّب صورة أقرب وأوضح وبإضاءة جيدة',
        'scan_use_photo' => '📸 التقط صورة للباركود',
        'scan_reading'   => '⏳ جاري قراءة الصورة...',
        'scan_ocr'       => '⏳ لم يوجد باركود — جاري قراءة النص من الصورة (قد يستغرق ثوانٍ)...',
        'scan_select'    => '👆 ظلّل رقم الشاسيه بإصبعك على الصورة ثم اضغط قراءة',
        'scan_confirm'   => '✅ قراءة التحديد',
        'scan_new'       => '📸 صورة جديدة',
        'success'        => 'تمت إضافة السيارة بنجاح! 🎉',
        'available'      => 'متاحة',
        'created_by'     => 'أضيفت بواسطة',
        'status'         => 'الحالة',
        'mgmt_only'      => 'للإدارة فقط',
        'quote_label'    => 'اقتباس اليوم',
    ],
    'en' => [
        'title'          => 'Add New Vehicle',
        'subtitle'       => 'Add a Vehicle to Inventory',
        'total_cars'     => 'Total Vehicles',
        'available_cars' => 'Available Vehicles',
        'branches'       => 'Branches',
        'dashboard'      => 'Dashboard',
        'inventory'      => 'Inventory',
        'users'          => 'Users',
        'brand'          => 'Brand',
        'model'          => 'Model',
        'year'           => 'Year',
        'trim'           => 'Trim',
        'color'          => 'Color',
        'chassis'        => 'Chassis No.',
        'branch'         => 'Branch',
        'notes'          => 'Notes',
        'select_brand'   => 'Select Brand',
        'select_model'   => 'Select Model',
        'select_trim'    => 'Select Trim',
        'select_color'   => 'Select Color',
        'select_branch'  => 'Select Branch',
        'select_year'    => 'Select Year',
        'preview'        => 'Vehicle Preview',
        'add_vehicle'    => 'Add Vehicle',
        'fill_required'  => 'Please complete all required fields',
        'chassis_exists' => 'This chassis number already exists in the system',
        'token_expired'  => 'This page expired — refresh it and try again',
        'scan_title'     => 'Scan Chassis Barcode',
        'scan_hint'      => 'Take a close, sharp photo of the chassis barcode — fill the frame with it and avoid reflections',
        'scan_close'     => 'Close',
        'scan_fail'      => 'Camera could not start — make sure camera access is allowed',
        'scan_photo'     => 'From Photo',
        'scan_photo_fail'=> 'Could not read a barcode from that photo — try closer, sharper, well-lit',
        'scan_use_photo' => '📸 Take a photo of the barcode',
        'scan_reading'   => '⏳ Reading the photo...',
        'scan_ocr'       => '⏳ No barcode found — reading the TEXT from the photo (may take a few seconds)...',
        'scan_select'    => '👆 Highlight the chassis number with your finger, then press Read',
        'scan_confirm'   => '✅ Read selection',
        'scan_new'       => '📸 New photo',
        'success'        => 'Vehicle Added Successfully! 🎉',
        'available'      => 'Available',
        'created_by'     => 'Added By',
        'status'         => 'Status',
        'mgmt_only'      => 'Management Only',
        'quote_label'    => 'Quote of the Day',
    ],
];

/* ─────────────────────────────────────────────────────────────
   STATS
───────────────────────────────────────────────────────────── */
$totalCars    = $pdo->query("SELECT COUNT(*) FROM cars")->fetchColumn();
$availableCars= $pdo->query("SELECT COUNT(*) FROM cars WHERE status='available'")->fetchColumn();
$totalBranches= $pdo->query("SELECT COUNT(*) FROM branches")->fetchColumn();

/* ─────────────────────────────────────────────────────────────
   DROPDOWN DATA
───────────────────────────────────────────────────────────── */
$brands   = $pdo->query("SELECT * FROM brands ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$colors   = $pdo->query("SELECT * FROM colors ORDER BY color_en")->fetchAll(PDO::FETCH_ASSOC);
$branches = $pdo->query("SELECT * FROM branches ORDER BY name_en")->fetchAll(PDO::FETCH_ASSOC);

/* ─────────────────────────────────────────────────────────────
   FORM HANDLING  ← BUG FIX: columns/values were misaligned
───────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $brand      = trim($_POST['brand']      ?? '');
    $model      = trim($_POST['model']      ?? '');
    $car_year   = trim($_POST['car_year']   ?? '');
    $trim_name  = trim($_POST['trim_name']  ?? '');
    $color      = trim($_POST['color']      ?? '');
    $branch     = trim($_POST['branch']     ?? '');
    $chassis    = strtoupper(trim($_POST['chassis'] ?? ''));
    $notes      = trim($_POST['notes']      ?? '');
    $created_by = $_SESSION['username'];

    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {

        $error = $t[$lang]['token_expired'];

    } elseif (empty($brand) || empty($model) || empty($car_year) ||
        empty($trim_name) || empty($color) || empty($branch) || empty($chassis)) {

        $error = $t[$lang]['fill_required'];

    } else {

        /* Check duplicate chassis */
        $check = $pdo->prepare("SELECT id FROM cars WHERE UPPER(chassis) = UPPER(?) LIMIT 1");
        $check->execute([$chassis]);

        if ($check->fetch()) {

            $error = $t[$lang]['chassis_exists'];

        } else {

            /*
             * FIX: The original code had 10 column names but 11 ? placeholders
             * because it listed `original_branch` separately yet also used a
             * hardcoded string 'available' for status while passing status via ?.
             * Corrected INSERT below — 11 columns, 11 bound values.
             */
            $insert = $pdo->prepare("
                INSERT INTO cars
                    (brand, model, car_year, trim_name, color, chassis,
                     branch, original_branch, status, notes, created_by)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, 'available', ?, ?)
            ");

            $insert->execute([
                $brand,
                $model,
                $car_year,
                $trim_name,
                $color,
                $chassis,
                $branch,
                $branch,       // original_branch = branch at creation
                $notes,
                $created_by,
            ]);

            $carId = $pdo->lastInsertId();

            /* Log movement */
            $movement = $pdo->prepare("
                INSERT INTO movements
                    (car_id, from_branch, to_branch, moved_by, notes, event_type)
                VALUES
                    (?, ?, ?, ?, 'Vehicle Added', 'created')
            ");

            $movement->execute([$carId, $branch, $branch, $created_by]);

            $success = $t[$lang]['success'];

            $addedVehicle = compact(
                'brand','model','car_year','trim_name','color','branch','chassis'
            );

            /* Show the result on a fresh GET, so a refresh never re-sends the form */
            notify_event($pdo, 'car_added', ['car' => $addedVehicle + ['id' => (int)$carId]]);
            $_SESSION['av_added'] = [
                'car'  => $addedVehicle + ['id' => (int)$carId],
                'keep' => !empty($_POST['keep']),
            ];
            header('Location: add_vehicle.php?lang=' . urlencode($lang));
            exit;
        }
    }
}

/* ─── After the redirect: the car that was just added ─── */
$avKeep = null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_SESSION['av_added'])) {
    $flash = $_SESSION['av_added'];
    unset($_SESSION['av_added']);
    $addedVehicle = $flash['car'];
    $success      = $t[$lang]['success'];
    if (!empty($flash['keep'])) $avKeep = $addedVehicle;
}

/* What the form starts with: the same car again ("add another like this"),
   or, after an error, everything that was typed so nothing is lost. */
$avPrefill = null;
if ($avKeep) {
    $avPrefill = ['brand' => $avKeep['brand'], 'model' => $avKeep['model'], 'car_year' => $avKeep['car_year'],
                  'trim_name' => $avKeep['trim_name'], 'branch' => $avKeep['branch'], 'focus' => 'color'];
} elseif ($error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $avPrefill = [];
    foreach (['brand', 'model', 'car_year', 'trim_name', 'color', 'branch', 'chassis', 'notes'] as $f) {
        $avPrefill[$f] = trim((string)($_POST[$f] ?? ''));
    }
}

/* ─── Context for the preview: price, stock, today's cars ─── */
$avKey = fn(...$p) => implode('|', array_map(fn($v) => mb_strtolower(trim((string)$v)), $p));

$avPrices = [];
if (can('page.prices')) {
    try {
        foreach ($pdo->query("SELECT brand, model_name, trim_name, car_year, official_price, customer_price FROM pricing") as $r) {
            $avPrices[$avKey($r['brand'], $r['model_name'], $r['trim_name'], $r['car_year'])] = [
                'off'  => ($r['official_price'] !== null && $r['official_price'] !== '') ? number_format((float)$r['official_price']) : '',
                'cust' => (string)($r['customer_price'] ?? ''),   // the label exactly as written
            ];
        }
    } catch (Throwable $e) { /* no pricing table: no price line */ }
}

$avStock = [];
try {
    $sq = $pdo->query("SELECT brand, model, trim_name, car_year, branch, color, COUNT(*) AS n
                       FROM cars WHERE status IN ('available','reserved')
                       GROUP BY brand, model, trim_name, car_year, branch, color");
    foreach ($sq as $r) {
        $avStock[$avKey($r['brand'], $r['model'], $r['trim_name'], $r['car_year'])][] = [(string)$r['branch'], (string)$r['color'], (int)$r['n']];
    }
} catch (Throwable $e) { error_log('add_vehicle: stock context failed: ' . $e->getMessage()); }

$avToday = [];
try {
    $tq = $pdo->prepare("SELECT id, brand, model, car_year, trim_name, color, branch, chassis, created_at
                         FROM cars WHERE created_by = ? AND created_at >= CURDATE()
                         ORDER BY id DESC LIMIT 40");
    $tq->execute([$_SESSION['username']]);
    $avToday = $tq->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('add_vehicle: today list failed: ' . $e->getMessage()); }
$avCanEdit = can('page.edit_vehicle');

/** A display swatch for a colour name; unknown names fall back to neutral grey. */
function av_swatch(string $colorEn): string
{
    static $map = [
        'white' => '#f8fafc', 'pearl white' => '#f1f5f9', 'black' => '#111827', 'silver' => '#cbd5e1',
        'grey' => '#6b7280', 'gray' => '#6b7280', 'red' => '#dc2626', 'blue' => '#2563eb', 'navy' => '#1e3a8a',
        'green' => '#16a34a', 'gold' => '#d4af37', 'beige' => '#e0d5c0', 'brown' => '#78350f',
        'orange' => '#ea580c', 'yellow' => '#eab308', 'purple' => '#7c3aed', 'bronze' => '#a97142', 'champagne' => '#e6d7b8',
    ];
    return $map[mb_strtolower(trim($colorEn))] ?? '#64748b';
}

/* ─── Car image library, exported for the live preview ─── */
$carImgJs = [];
foreach (car_images_map($pdo) as $k => $row) {
    $u = car_image_url($row, true);
    if ($u !== '') $carImgJs[$k] = $u;
}

$isRTL = ($lang === 'ar');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $isRTL ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $t[$lang]['title'] ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">

<style>
/* ── RESET & BASE ─────────────────────────────────────── */
*, *::before, *::after {
    margin: 0; padding: 0; box-sizing: border-box;
}

:root {
    --bg-deep:      #020617;
    --bg-card:      rgba(13,20,40,0.92);
    --border:       rgba(255,255,255,0.07);
    --green:        #22c55e;
    --green-dim:    rgba(34,197,94,0.12);
    --purple:       #a855f7;
    --purple-dim:   rgba(168,85,247,0.12);
    --blue:         #3b82f6;
    --blue-dim:     rgba(59,130,246,0.12);
    --amber:        #f59e0b;
    --amber-dim:    rgba(245,158,11,0.12);
    --red:          #ef4444;
    --red-dim:      rgba(239,68,68,0.12);
    --text:         #f1f5f9;
    --text-muted:   #64748b;
    --text-soft:    #94a3b8;
    --radius-lg:    20px;
    --radius-xl:    28px;
    --radius-pill:  999px;
    --shadow:       0 20px 60px rgba(0,0,0,0.5);
    --ff-en:        'Inter', sans-serif;
    --ff-ar:        'Tajawal', sans-serif;
    --transition:   all 0.25s cubic-bezier(0.4,0,0.2,1);
}

html[lang="ar"] body { font-family: var(--ff-ar); }
html[lang="en"] body { font-family: var(--ff-en); }

body {
    background: var(--bg-deep);
    background-image:
        radial-gradient(ellipse 80% 50% at 50% -20%, rgba(34,197,94,0.07) 0%, transparent 60%),
        radial-gradient(ellipse 60% 40% at 80% 80%, rgba(168,85,247,0.05) 0%, transparent 50%);
    color: var(--text);
    min-height: 100vh;
    padding-bottom: 80px;
}

/* ── SCROLLBAR ───────────────────────────────────────── */
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: var(--bg-deep); }
::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.12); border-radius: 3px; }

/* ── LAYOUT ──────────────────────────────────────────── */
.container {
    max-width: 1500px;
    margin: 0 auto;
    padding: 20px 16px;
}

/* ── QUOTE BANNER ────────────────────────────────────── */
.quote-banner {
    background: linear-gradient(135deg, rgba(168,85,247,0.15), rgba(34,197,94,0.10));
    border: 1px solid rgba(168,85,247,0.25);
    border-radius: var(--radius-lg);
    padding: 14px 22px;
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.quote-icon {
    font-size: 22px;
    flex-shrink: 0;
    opacity: 0.8;
}

.quote-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: var(--purple);
    font-weight: 700;
    margin-bottom: 3px;
}

.quote-text {
    font-size: 14px;
    color: var(--text-soft);
    font-style: italic;
    line-height: 1.5;
}

/* ── HEADER ──────────────────────────────────────────── */
.header {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
    padding: 22px 24px;
    margin-bottom: 20px;
    backdrop-filter: blur(24px);
    box-shadow: var(--shadow);
}

.header-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.brand-area {
    display: flex;
    align-items: center;
    gap: 14px;
}

.logo-circle {
    width: 52px; height: 52px;
    background: linear-gradient(135deg, var(--green), var(--purple));
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    flex-shrink: 0;
    box-shadow: 0 4px 20px rgba(34,197,94,0.3);
}

.page-title {
    font-size: 26px;
    font-weight: 900;
    background: linear-gradient(90deg, var(--green), var(--purple));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    line-height: 1.2;
}

.page-subtitle {
    font-size: 13px;
    color: var(--text-muted);
    margin-top: 3px;
    font-weight: 500;
}

.header-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
}

.action-btn {
    text-decoration: none;
    padding: 10px 16px;
    border-radius: 14px;
    font-weight: 700;
    font-size: 13px;
    color: white;
    transition: var(--transition);
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}

.action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.3);
}

.btn-dashboard { background: var(--blue-dim); border: 1px solid rgba(59,130,246,0.3); color: #60a5fa; }
.btn-inventory { background: var(--green-dim); border: 1px solid rgba(34,197,94,0.3); color: var(--green); }
.btn-users     { background: var(--purple-dim); border: 1px solid rgba(168,85,247,0.3); color: var(--purple); }

.btn-dashboard:hover { background: rgba(59,130,246,0.25); }
.btn-inventory:hover { background: rgba(34,197,94,0.25); }
.btn-users:hover     { background: rgba(168,85,247,0.25); }

/* ── LANG SWITCH ─────────────────────────────────────── */
.header-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 18px;
    padding-top: 18px;
    border-top: 1px solid var(--border);
}

.lang-switch { display: flex; gap: 8px; }

.lang-btn {
    text-decoration: none;
    padding: 8px 16px;
    border-radius: 12px;
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--border);
    color: var(--text-soft);
    font-weight: 700;
    font-size: 13px;
    transition: var(--transition);
    display: flex;
    align-items: center;
    gap: 6px;
}

.lang-btn:hover { background: rgba(255,255,255,0.1); color: white; }

.lang-active {
    background: var(--purple-dim) !important;
    border-color: rgba(168,85,247,0.4) !important;
    color: var(--purple) !important;
}

.breadcrumb {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--text-muted);
}

.breadcrumb a {
    color: var(--text-muted);
    text-decoration: none;
    transition: color 0.2s;
}

.breadcrumb a:hover { color: var(--text); }

.breadcrumb-sep { opacity: 0.4; }

.breadcrumb-current { color: var(--green); font-weight: 600; }

/* ── STATS ───────────────────────────────────────────── */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}

.stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 20px;
    position: relative;
    overflow: hidden;
    transition: var(--transition);
}

.stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow); }

.stat-card::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, transparent 60%, rgba(255,255,255,0.02));
    pointer-events: none;
}

.stat-glow {
    position: absolute;
    top: 0; right: 0;
    width: 80px; height: 80px;
    border-radius: 50%;
    filter: blur(30px);
    opacity: 0.4;
}

.stat-card--total   .stat-glow { background: var(--blue); }
.stat-card--avail   .stat-glow { background: var(--green); }
.stat-card--branch  .stat-glow { background: var(--purple); }

.stat-icon {
    font-size: 22px;
    margin-bottom: 12px;
    display: block;
}

.stat-title {
    font-size: 12px;
    color: var(--text-muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-bottom: 8px;
}

.stat-number {
    font-size: 38px;
    font-weight: 900;
    line-height: 1;
}

.stat-card--total  .stat-number { color: #60a5fa; }
.stat-card--avail  .stat-number { color: var(--green); }
.stat-card--branch .stat-number { color: var(--purple); }

.stat-sub {
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 6px;
}

.stat-card a {
    text-decoration: none;
    color: inherit;
    display: block;
    height: 100%;
}

/* ── ALERTS ──────────────────────────────────────────── */
.alert {
    padding: 14px 18px;
    border-radius: var(--radius-lg);
    margin-bottom: 16px;
    font-weight: 600;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
}

.alert-error {
    background: var(--red-dim);
    border: 1px solid rgba(239,68,68,0.3);
    color: #f87171;
}

.alert-success {
    background: var(--green-dim);
    border: 1px solid rgba(34,197,94,0.3);
    color: var(--green);
}

/* ── MAIN GRID ───────────────────────────────────────── */
.main-grid {
    display: grid;
    grid-template-columns: 1fr 360px;
    gap: 18px;
    align-items: start;
}

/* ── CARDS ───────────────────────────────────────────── */
.card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
    padding: 28px;
    backdrop-filter: blur(20px);
    box-shadow: var(--shadow);
}

.section-title {
    font-size: 18px;
    font-weight: 800;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--text);
}

.section-title-icon {
    width: 36px; height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    background: var(--green-dim);
    border: 1px solid rgba(34,197,94,0.2);
}

/* ── FORM ────────────────────────────────────────────── */
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}

.form-group { margin-bottom: 14px; }

.form-group.full { grid-column: 1 / -1; }

label {
    display: block;
    margin-bottom: 7px;
    font-weight: 600;
    font-size: 13px;
    color: var(--text-soft);
    letter-spacing: 0.02em;
}

.field-wrap {
    position: relative;
}

.field-icon {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    font-size: 16px;
    color: var(--text-muted);
    pointer-events: none;
    z-index: 1;
}

html[dir="ltr"] .field-icon { left: 14px; }
html[dir="rtl"] .field-icon { right: 14px; }

input, select, textarea {
    width: 100%;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.09);
    outline: none;
    color: var(--text);
    border-radius: 14px;
    font-size: 14px;
    font-family: inherit;
    transition: var(--transition);
    appearance: none;
    -webkit-appearance: none;
}

input, select { height: 52px; padding: 0 16px; }

html[dir="ltr"] input.has-icon,
html[dir="ltr"] select.has-icon { padding-left: 42px; }
html[dir="rtl"] input.has-icon,
html[dir="rtl"] select.has-icon { padding-right: 42px; }

select {
    cursor: pointer;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
}

html[dir="ltr"] select { background-position: right 14px center; padding-right: 40px; }
html[dir="rtl"] select { background-position: left 14px center; padding-left: 40px; }

html[dir="ltr"] select.has-icon { padding-left: 42px; }
html[dir="rtl"] select.has-icon { padding-right: 42px; }

textarea {
    padding: 14px 16px;
    resize: none;
    min-height: 110px;
}

input:focus, select:focus, textarea:focus {
    border-color: rgba(168,85,247,0.5);
    background: rgba(168,85,247,0.05);
    box-shadow: 0 0 0 3px rgba(168,85,247,0.1);
}

input::placeholder { color: var(--text-muted); }

/* Disabled/loading select */
select:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.select-loading {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    font-size: 12px;
    color: var(--text-muted);
    pointer-events: none;
}

html[dir="ltr"] .select-loading { right: 40px; }
html[dir="rtl"] .select-loading { left: 40px; }

/* ── DIVIDER ─────────────────────────────────────────── */
.form-divider {
    height: 1px;
    background: var(--border);
    margin: 18px 0;
    grid-column: 1 / -1;
}

/* ── SUBMIT BTN ──────────────────────────────────────── */
.submit-btn {
    width: 100%;
    height: 58px;
    border: none;
    cursor: pointer;
    border-radius: 16px;
    font-size: 16px;
    font-weight: 800;
    color: white;
    background: linear-gradient(135deg, var(--green), #16a34a 40%, var(--purple));
    background-size: 200% 100%;
    background-position: 0% 0%;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-family: inherit;
    letter-spacing: 0.02em;
    box-shadow: 0 4px 20px rgba(34,197,94,0.25);
    margin-top: 8px;
    position: relative;
    overflow: hidden;
}

.submit-btn::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, transparent, rgba(255,255,255,0.08), transparent);
    transform: translateX(-100%);
    transition: transform 0.5s;
}

.submit-btn:hover::before { transform: translateX(100%); }

.submit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 30px rgba(34,197,94,0.35);
    background-position: 100% 0%;
}

.submit-btn:active { transform: translateY(0); }

/* ── PREVIEW CARD ────────────────────────────────────── */
.preview-photo {
    display: none; position: relative; aspect-ratio: 16/9; overflow: hidden;
    border-radius: 16px; margin-bottom: 14px; background: #0d1526;
    animation: ppFade .35s ease both;
}
.preview-photo.on { display: block; }
.preview-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
.preview-photo .pp-tag {
    position: absolute; top: 7px; inset-inline-start: 7px;
    background: rgba(2,6,23,.72); color: #94a3b8; font-size: 9px; font-weight: 800;
    padding: 3px 8px; border-radius: 6px;
}
@keyframes ppFade { from { opacity: 0; transform: scale(.97); } to { opacity: 1; transform: none; } }
.preview-card {
    position: sticky;
    top: 20px;
    height: fit-content;
}

.preview-vehicle-name {
    font-size: 22px;
    font-weight: 900;
    color: var(--green);
    margin-bottom: 6px;
    line-height: 1.3;
    min-height: 30px;
    transition: var(--transition);
}

.vehicle-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: var(--radius-pill);
    background: var(--green-dim);
    border: 1px solid rgba(34,197,94,0.25);
    color: var(--green);
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 22px;
}

.badge-dot {
    width: 7px; height: 7px;
    background: var(--green);
    border-radius: 50%;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%       { opacity: 0.5; transform: scale(0.8); }
}

.preview-items { display: flex; flex-direction: column; gap: 0; }

.preview-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 11px 0;
    border-bottom: 1px solid var(--border);
    gap: 12px;
}

.preview-item:last-child { border-bottom: none; }

.preview-label {
    font-size: 12px;
    color: var(--text-muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    flex-shrink: 0;
}

.preview-value {
    font-size: 14px;
    font-weight: 700;
    color: var(--text);
    text-align: end;
    word-break: break-word;
    transition: var(--transition);
}

.preview-value.empty { color: var(--text-muted); font-weight: 400; }

.preview-chassis-value {
    font-family: 'Courier New', monospace;
    font-size: 13px;
    color: var(--amber);
    letter-spacing: 0.05em;
}

/* ── CHASSIS VALIDITY ────────────────────────────────── */
.chassis-hint {
    font-size: 11px;
    margin-top: 5px;
    color: var(--text-muted);
    min-height: 16px;
    transition: var(--transition);
}

.chassis-hint.valid   { color: var(--green); }
.chassis-hint.invalid { color: var(--red); }

/* ── CHASSIS SCANNER ─────────────────────────────────── */
.chassis-row { display: flex; gap: 10px; align-items: stretch; }
.chassis-row .field-wrap { flex: 1; min-width: 0; }
.btn-scan-chassis {
    flex-shrink: 0;
    background: rgba(59,130,246,0.12);
    border: 1px solid rgba(59,130,246,0.4);
    color: #93c5fd;
    border-radius: 12px;
    padding: 0 18px;
    font-size: 20px;
    cursor: pointer;
    font-family: inherit;
    transition: .2s;
    display: flex;
    align-items: center;
    justify-content: center;
}
.btn-scan-chassis:hover { background: rgba(59,130,246,0.28); color: #fff; }
.btn-scan-chassis:active { transform: scale(.95); }

.scan-modal {
    display: none; position: fixed; inset: 0; z-index: 1000;
    background: rgba(2,6,23,0.94); backdrop-filter: blur(6px);
    align-items: center; justify-content: center; padding: 18px;
}
.scan-modal.show { display: flex; }
.scan-box {
    width: 100%; max-width: 440px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 22px; overflow: hidden;
}
.scan-head { padding: 15px 18px; display: flex; align-items: center; justify-content: space-between; }
.scan-head .st { font-weight: 900; font-size: 15px; }
.scan-close-btn {
    background: rgba(255,255,255,0.07); border: 1px solid var(--border); color: inherit;
    border-radius: 10px; padding: 6px 13px; font-size: 13px; font-weight: 700;
    cursor: pointer; font-family: inherit;
}
#scanner-view { width: 100%; min-height: 280px; background: #000; }
.scan-hint-txt { padding: 12px 18px 16px; color: var(--text-muted); font-size: 12.5px; text-align: center; line-height: 1.6; }
.scan-tools {
    display: flex; align-items: center; justify-content: center; gap: 10px;
    padding: 12px 18px 0; flex-wrap: wrap;
}
.scan-tool-btn {
    background: rgba(59,130,246,0.12); border: 1px solid rgba(59,130,246,0.4);
    color: #93c5fd; border-radius: 12px; padding: 9px 16px;
    font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit;
}
.scan-tool-btn:hover { background: rgba(59,130,246,0.28); color: #fff; }
.zoom-row { display: flex; gap: 6px; }
.zoom-btn {
    background: rgba(255,255,255,0.07); border: 1px solid var(--border); color: inherit;
    border-radius: 10px; padding: 8px 13px; font-size: 12.5px; font-weight: 800;
    cursor: pointer; font-family: inherit;
}
.zoom-btn.on { border-color: var(--green); color: var(--green); }
.scan-hero-btn {
    display: block; width: calc(100% - 36px); margin: 60px auto;
    background: linear-gradient(135deg, var(--green), #16a34a); color: #fff;
    border: none; border-radius: 16px; padding: 20px 18px;
    font-size: 16px; font-weight: 900; cursor: pointer; font-family: inherit;
    box-shadow: 0 6px 20px rgba(34,197,94,0.35);
}
.scan-err-txt  { color: #fca5a5; font-size: 12.5px; text-align: center; padding: 0 18px 14px; display: none; }

/* ── PROGRESS INDICATOR ──────────────────────────────── */
.form-progress {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 22px;
    padding: 14px 16px;
    background: rgba(255,255,255,0.03);
    border-radius: 14px;
    border: 1px solid var(--border);
}

.progress-bar-bg {
    flex: 1;
    height: 6px;
    background: rgba(255,255,255,0.07);
    border-radius: 3px;
    overflow: hidden;
}

.progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--green), var(--purple));
    border-radius: 3px;
    transition: width 0.4s cubic-bezier(0.4,0,0.2,1);
    width: 0%;
}

.progress-label {
    font-size: 12px;
    color: var(--text-muted);
    font-weight: 600;
    white-space: nowrap;
}

/* ── RESPONSIVE ──────────────────────────────────────── */
@media (max-width: 1100px) {
    .main-grid { grid-template-columns: 1fr; }
    .preview-card { position: static; }
}

@media (max-width: 768px) {
    .stats-grid { grid-template-columns: 1fr 1fr; }
    .stats-grid .stat-card:last-child { grid-column: 1 / -1; }
    .header-top { flex-direction: column; align-items: flex-start; }
    .page-title { font-size: 22px; }
    .form-grid { grid-template-columns: 1fr; }
    .card { padding: 20px 16px; }
    .header { padding: 18px 16px; }
    .stat-number { font-size: 30px; }
    .header-actions { gap: 6px; }
    .action-btn { padding: 8px 12px; font-size: 12px; }
}

@media (max-width: 480px) {
    .stats-grid { grid-template-columns: 1fr; }
    .stats-grid .stat-card:last-child { grid-column: unset; }
    .container { padding: 14px 12px; }
    .header-bottom { flex-direction: column; align-items: flex-start; }
    .logo-circle { width: 44px; height: 44px; font-size: 20px; }
}

/* ── ADDED VEHICLE BANNER ────────────────────────────── */
.added-banner {
    background: var(--green-dim);
    border: 1px solid rgba(34,197,94,0.3);
    border-radius: var(--radius-lg);
    padding: 18px 22px;
    margin-bottom: 18px;
    display: flex;
    align-items: flex-start;
    gap: 14px;
    animation: slideDown 0.4s ease;
}

.added-banner-icon {
    font-size: 28px;
    flex-shrink: 0;
}

.added-banner-title {
    font-size: 16px;
    font-weight: 800;
    color: var(--green);
    margin-bottom: 6px;
}

.added-banner-details {
    font-size: 13px;
    color: var(--text-soft);
    line-height: 1.8;
}
</style>
</head>

<body>
<div class="container">

    <!-- ── MOTIVATIONAL QUOTE ──────────────────────── -->
    <div class="quote-banner">
        <span class="quote-icon">💡</span>
        <div>
            <div class="quote-label"><?= $t[$lang]['quote_label'] ?></div>
            <div class="quote-text"><?= htmlspecialchars($currentQuote) ?></div>
        </div>
    </div>

    <!-- ── HEADER ─────────────────────────────────── -->
    <div class="header">
        <div class="header-top">
            <div class="brand-area">
                <div class="logo-circle">🚗</div>
                <div>
                    <div class="page-title"><?= $t[$lang]['title'] ?></div>
                    <div class="page-subtitle"><?= $t[$lang]['subtitle'] ?></div>
                </div>
            </div>
            <div class="header-actions">
                <a href="dashboard.php?lang=<?= $lang ?>" class="action-btn btn-dashboard">🏠 <?= $t[$lang]['dashboard'] ?></a>
                <a href="inventory.php?lang=<?= $lang ?>"  class="action-btn btn-inventory">📋 <?= $t[$lang]['inventory'] ?></a>
                <?php if (can('page.users')): ?>
                <a href="users.php?lang=<?= $lang ?>"      class="action-btn btn-users">👥 <?= $t[$lang]['users'] ?></a>
                <?php endif; ?>
            </div>
        </div>

        <div class="header-bottom">
            <div class="breadcrumb">
                <a href="dashboard.php?lang=<?= $lang ?>"><?= $t[$lang]['dashboard'] ?></a>
                <span class="breadcrumb-sep">›</span>
                <a href="inventory.php?lang=<?= $lang ?>"><?= $t[$lang]['inventory'] ?></a>
                <span class="breadcrumb-sep">›</span>
                <span class="breadcrumb-current"><?= $t[$lang]['title'] ?></span>
            </div>
            <div class="lang-switch">
                <a href="?lang=ar" class="lang-btn <?= $lang === 'ar' ? 'lang-active' : '' ?>">🇪🇬 العربية</a>
                <a href="?lang=en" class="lang-btn <?= $lang === 'en' ? 'lang-active' : '' ?>">🇺🇸 English</a>
            </div>
        </div>
    </div>

    <!-- ── ALERTS ─────────────────────────────────── -->
    <?php if ($error): ?>
    <div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success && $addedVehicle): ?>
    <div class="added-banner" id="addedBanner">
        <div class="ab-photo" id="abPhoto"><span class="added-banner-icon">🎉</span></div>
        <div class="ab-body">
            <div class="added-banner-title"><?= $t[$lang]['success'] ?></div>
            <div class="added-banner-details">
                <?= htmlspecialchars($addedVehicle['brand']) ?> <?= htmlspecialchars($addedVehicle['model']) ?> <?= htmlspecialchars($addedVehicle['car_year']) ?> —
                <?= htmlspecialchars($addedVehicle['trim_name']) ?> /
                <?= htmlspecialchars($addedVehicle['color']) ?> |
                <?= $t[$lang]['chassis'] ?>: <?= htmlspecialchars($addedVehicle['chassis']) ?>
            </div>
            <div class="ab-actions">
                <button type="button" class="ab-btn ab-again" id="abAgain">➕ <?= $lang === 'ar' ? 'أضف سيارة مماثلة' : 'Add another like this' ?></button>
                <?php if ($avCanEdit && !empty($addedVehicle['id'])): ?>
                <a class="ab-btn" href="edit_vehicle.php?id=<?= (int)$addedVehicle['id'] ?>&lang=<?= $lang ?>">✏️ <?= $lang === 'ar' ? 'تعديل' : 'Edit' ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── STATS ──────────────────────────────────── -->
    <div class="stats-grid">
        <div class="stat-card stat-card--total">
            <a href="sold_login.php?lang=<?= $lang ?>">
                <span class="stat-icon">🚘</span>
                <div class="stat-glow"></div>
                <div class="stat-title"><?= $t[$lang]['total_cars'] ?></div>
                <div class="stat-number">••••</div>
                <div class="stat-sub"><?= $t[$lang]['mgmt_only'] ?></div>
            </a>
        </div>
        <div class="stat-card stat-card--avail">
            <span class="stat-icon">✅</span>
            <div class="stat-glow"></div>
            <div class="stat-title"><?= $t[$lang]['available_cars'] ?></div>
            <div class="stat-number"><?= $availableCars ?></div>
            <div class="stat-sub"><?= $lang === 'ar' ? 'جاهزة للبيع' : 'Ready for sale' ?></div>
        </div>
        <div class="stat-card stat-card--branch">
            <span class="stat-icon">🏢</span>
            <div class="stat-glow"></div>
            <div class="stat-title"><?= $t[$lang]['branches'] ?></div>
            <div class="stat-number"><?= $totalBranches ?></div>
            <div class="stat-sub"><?= $lang === 'ar' ? 'فرع نشط' : 'Active branches' ?></div>
        </div>
    </div>

    <!-- ── MAIN GRID ───────────────────────────────── -->
    <div class="main-grid">

        <!-- FORM CARD -->
        <div class="card">
            <div class="section-title">
                <div class="section-title-icon">➕</div>
                <?= $t[$lang]['title'] ?>
            </div>

            <!-- Progress -->
            <div class="form-progress">
                <span style="font-size:14px;">📊</span>
                <div class="progress-bar-bg">
                    <div class="progress-bar-fill" id="progressFill"></div>
                </div>
                <span class="progress-label" id="progressLabel">0 / 7</span>
            </div>

            <form method="POST" id="vehicleForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="keep" id="keepInput" value="">

                <div class="form-grid">

                    <!-- BRAND -->
                    <div class="form-group">
                        <label>🏷️ <?= $t[$lang]['brand'] ?></label>
                        <div class="field-wrap">
                            <select id="brand" name="brand" class="has-icon" required>
                                <option value=""><?= $t[$lang]['select_brand'] ?></option>
                                <?php foreach ($brands as $b): ?>
                                <option value="<?= htmlspecialchars($b['name']) ?>"><?= htmlspecialchars($b['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- MODEL -->
                    <div class="form-group">
                        <label>🚙 <?= $t[$lang]['model'] ?></label>
                        <div class="field-wrap">
                            <select id="model" name="model" required disabled>
                                <option value=""><?= $t[$lang]['select_model'] ?></option>
                            </select>
                            <span class="select-loading" id="modelLoading" style="display:none">⏳</span>
                        </div>
                    </div>

                    <!-- YEAR -->
                    <div class="form-group">
                        <label>📅 <?= $t[$lang]['year'] ?></label>
                        <div class="field-wrap">
                            <select id="car_year" name="car_year" required>
                                <option value=""><?= $t[$lang]['select_year'] ?></option>
                                <?php
                                $cy = (int)date('Y');
                                for ($y = $cy - 1; $y <= $cy + 5; $y++): ?>
                                <option value="<?= $y ?>"><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <!-- TRIM -->
                    <div class="form-group">
                        <label>⭐ <?= $t[$lang]['trim'] ?></label>
                        <div class="field-wrap">
                            <select id="trim" name="trim_name" required disabled>
                                <option value=""><?= $t[$lang]['select_trim'] ?></option>
                            </select>
                            <span class="select-loading" id="trimLoading" style="display:none">⏳</span>
                        </div>
                    </div>

                    <!-- COLOR -->
                    <div class="form-group">
                        <label>🎨 <?= $t[$lang]['color'] ?></label>
                        <div class="field-wrap">
                            <select id="color" name="color" required>
                                <option value=""><?= $t[$lang]['select_color'] ?></option>
                                <?php foreach ($colors as $c): ?>
                                <option value="<?= htmlspecialchars($c['color_en']) ?>">
                                    <?= $isRTL ? htmlspecialchars($c['color_ar']) : htmlspecialchars($c['color_en']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- BRANCH -->
                    <div class="form-group">
                        <label>🏢 <?= $t[$lang]['branch'] ?></label>
                        <div class="field-wrap">
                            <select id="branch" name="branch" required>
                                <option value=""><?= $t[$lang]['select_branch'] ?></option>
                                <?php foreach ($branches as $br): ?>
                                <option value="<?= htmlspecialchars($br['name']) ?>">
                                    <?= $isRTL ? htmlspecialchars($br['name_ar']) : htmlspecialchars($br['name_en']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                </div>

                <!-- CHASSIS – full width -->
                <div class="form-group">
                    <label>🔑 <?= $t[$lang]['chassis'] ?></label>
                    <div class="chassis-row">
                        <div class="field-wrap">
                            <input
                                type="text"
                                id="chassis"
                                name="chassis"
                                required
                                maxlength="100"
                                autocomplete="off"
                                autocapitalize="characters"
                                spellcheck="false"
                                placeholder="e.g. 1HGBH41JXMN109186"
                            >
                        </div>
                        <button type="button" class="btn-scan-chassis" id="btnScanChassis"
                                title="<?= $t[$lang]['scan_title'] ?>">📷</button>
                    </div>
                    <div class="chassis-hint" id="chassisHint">
                        <?= $lang === 'ar' ? 'أدخل رقم الشاسيه — سيتحول للأحرف الكبيرة تلقائيًا' : 'Enter chassis number — auto-uppercased' ?>
                    </div>
                </div>

                <!-- NOTES – full width -->
                <div class="form-group">
                    <label>📝 <?= $t[$lang]['notes'] ?></label>
                    <textarea id="notes" name="notes" rows="4" placeholder="<?= $lang === 'ar' ? 'أي ملاحظات إضافية...' : 'Any additional notes...' ?>"></textarea>
                </div>

                <button type="submit" class="submit-btn" id="submitBtn">
                    <span>➕</span>
                    <span><?= $t[$lang]['add_vehicle'] ?></span>
                </button>

            </form>
        </div>

        <div class="side-col">
        <!-- PREVIEW CARD -->
        <div class="card preview-card">
            <div class="section-title">
                <div class="section-title-icon" style="background:var(--purple-dim);border-color:rgba(168,85,247,0.2)">👁</div>
                <?= $t[$lang]['preview'] ?>
            </div>

            <div class="preview-photo av-stage" id="previewPhoto">
                <div class="av-floor"></div>
                <svg class="av-sil" id="avSil" viewBox="0 0 320 130" aria-hidden="true">
                    <defs>
                        <linearGradient id="avShine" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0" stop-color="#fff" stop-opacity=".38"/><stop offset=".45" stop-color="#fff" stop-opacity=".06"/><stop offset="1" stop-color="#000" stop-opacity=".35"/>
                        </linearGradient>
                    </defs>
                    <ellipse cx="163" cy="119" rx="142" ry="7" fill="#000" opacity=".5"/>
                    <path class="av-body" d="M20,92 L22,72 Q24,62 36,60 L80,55 L112,31 Q118,26 128,26 L222,26 Q234,26 242,34 L268,57 L292,61 Q306,64 306,78 L306,92 Q306,98 300,98 L277,98 A27,27 0 0 0 223,98 L105,98 A27,27 0 0 0 51,98 L26,98 Q20,98 20,92 Z"/>
                    <path d="M20,92 L22,72 Q24,62 36,60 L80,55 L112,31 Q118,26 128,26 L222,26 Q234,26 242,34 L268,57 L292,61 Q306,64 306,78 L306,92 Q306,98 300,98 L277,98 A27,27 0 0 0 223,98 L105,98 A27,27 0 0 0 51,98 L26,98 Q20,98 20,92 Z" fill="url(#avShine)"/>
                    <path d="M88,57 L116,35 Q120,32 126,32 L166,32 L166,57 Z M174,32 L221,32 Q229,32 235,38 L256,57 L174,57 Z" fill="#0b1220" opacity=".82"/>
                    <path d="M292,70 L304,72" stroke="#fde68a" stroke-width="4" stroke-linecap="round"/>
                    <path d="M22,74 L30,73" stroke="#f87171" stroke-width="4" stroke-linecap="round"/>
                    <g><circle cx="78" cy="98" r="21" fill="#0b1220" stroke="#1e293b" stroke-width="5"/><circle cx="78" cy="98" r="9" fill="#94a3b8"/></g>
                    <g><circle cx="250" cy="98" r="21" fill="#0b1220" stroke="#1e293b" stroke-width="5"/><circle cx="250" cy="98" r="9" fill="#94a3b8"/></g>
                </svg>
                <img id="previewPhotoImg" alt="">
                <span class="pp-tag"><?= $lang === 'ar' ? 'صورة توضيحية' : 'Illustration' ?></span>
            </div>

            <div class="preview-vehicle-name" id="previewVehicle">—</div>

            <div class="vehicle-badge">
                <span class="badge-dot"></span>
                <?= $t[$lang]['available'] ?>
            </div>

            <div class="av-ctx" id="avCtx"></div>

            <div class="preview-items">
                <div class="preview-item">
                    <span class="preview-label"><?= $t[$lang]['brand'] ?></span>
                    <span class="preview-value empty" id="previewBrand">—</span>
                </div>
                <div class="preview-item">
                    <span class="preview-label"><?= $t[$lang]['model'] ?></span>
                    <span class="preview-value empty" id="previewModel">—</span>
                </div>
                <div class="preview-item">
                    <span class="preview-label"><?= $t[$lang]['year'] ?></span>
                    <span class="preview-value empty" id="previewYear">—</span>
                </div>
                <div class="preview-item">
                    <span class="preview-label"><?= $t[$lang]['trim'] ?></span>
                    <span class="preview-value empty" id="previewTrim">—</span>
                </div>
                <div class="preview-item">
                    <span class="preview-label"><?= $t[$lang]['color'] ?></span>
                    <span class="preview-value empty" id="previewColor">—</span>
                </div>
                <div class="preview-item">
                    <span class="preview-label"><?= $t[$lang]['branch'] ?></span>
                    <span class="preview-value empty" id="previewBranch">—</span>
                </div>
                <div class="preview-item">
                    <span class="preview-label"><?= $t[$lang]['chassis'] ?></span>
                    <span class="preview-value preview-chassis-value empty" id="previewChassis">—</span>
                </div>
                <div class="preview-item">
                    <span class="preview-label"><?= $t[$lang]['created_by'] ?></span>
                    <span class="preview-value"><?= htmlspecialchars($_SESSION['username']) ?></span>
                </div>
                <div class="preview-item">
                    <span class="preview-label"><?= $t[$lang]['status'] ?></span>
                    <span class="preview-value" style="color:var(--green)">🟢 <?= $t[$lang]['available'] ?></span>
                </div>
            </div>
        </div>

        <!-- TODAY -->
        <div class="card av-today" id="avToday">
            <div class="section-title" style="margin-bottom:14px">
                <div class="section-title-icon" style="background:var(--blue-dim);border-color:rgba(59,130,246,0.25)">🗓</div>
                <?= $lang === 'ar' ? 'أضفتها اليوم' : 'Added by you today' ?>
                <span class="avt-count" id="avtCount"><?= count($avToday) ?></span>
            </div>
            <div class="avt-list" id="avtList">
            <?php if (!$avToday): ?>
                <div class="avt-empty"><?= $lang === 'ar' ? 'لم تُضف أي سيارة اليوم بعد — أول سيارة ستظهر هنا' : 'Nothing added yet today — your first car will show here' ?></div>
            <?php endif; ?>
            <?php foreach ($avToday as $i => $tc):
                $cLabel = $tc['color'];
                foreach ($colors as $c) if (strcasecmp($c['color_en'], $tc['color']) === 0) { $cLabel = $isRTL ? $c['color_ar'] : $c['color_en']; break; }
                $bLabel = $tc['branch'];
                foreach ($branches as $br) if ($br['name'] === $tc['branch']) { $bLabel = $isRTL ? $br['name_ar'] : $br['name_en']; break; }
                $tag = $avCanEdit ? 'a' : 'div';
            ?>
                <<?= $tag ?> class="avt-item<?= ($i === 0 && $addedVehicle && (int)($addedVehicle['id'] ?? 0) === (int)$tc['id']) ? ' fresh' : '' ?>"<?= $avCanEdit ? ' href="edit_vehicle.php?id=' . (int)$tc['id'] . '&lang=' . $lang . '"' : '' ?>>
                    <i class="avt-dot" style="background:<?= av_swatch((string)$tc['color']) ?>"></i>
                    <div class="avt-main">
                        <div class="avt-name"><?= htmlspecialchars($tc['brand'] . ' ' . $tc['model'] . ' ' . $tc['car_year']) ?> <span><?= htmlspecialchars($tc['trim_name']) ?></span></div>
                        <div class="avt-sub"><?= htmlspecialchars($cLabel) ?> · <?= htmlspecialchars($bLabel) ?> · <?= date('h:i A', strtotime($tc['created_at'])) ?></div>
                    </div>
                    <span class="avt-ch"><?= htmlspecialchars($tc['chassis']) ?></span>
                </<?= $tag ?>>
            <?php endforeach; ?>
            </div>
        </div>
        </div><!-- /side-col -->

    </div><!-- /main-grid -->

</div><!-- /container -->

<script>
(function () {
    'use strict';

    /* ── ELEMENT REFS ─────────────────────────────── */
    const brandSel  = document.getElementById('brand');
    const modelSel  = document.getElementById('model');
    const trimSel   = document.getElementById('trim');
    const yearSel   = document.getElementById('car_year');
    const colorSel  = document.getElementById('color');
    const branchSel = document.getElementById('branch');
    const chassisIn = document.getElementById('chassis');
    const notesIn   = document.getElementById('notes');

    const pVehicle  = document.getElementById('previewVehicle');
    const pBrand    = document.getElementById('previewBrand');
    const pModel    = document.getElementById('previewModel');
    const pYear     = document.getElementById('previewYear');
    const pTrim     = document.getElementById('previewTrim');
    const pColor    = document.getElementById('previewColor');
    const pBranch   = document.getElementById('previewBranch');
    const pChassis  = document.getElementById('previewChassis');

    const pPhoto    = document.getElementById('previewPhoto');
    const pPhotoImg = document.getElementById('previewPhotoImg');

    /* The image library, keyed "brand|model|trim|year|colour" (lower case).
       An empty slot in a key means that row applies to any value there. */
    const CAR_IMAGES = window.__AV_IMAGES = <?= json_encode($carImgJs, JSON_UNESCAPED_UNICODE) ?>;

    const imgNorm = v => String(v == null ? '' : v).trim().toLowerCase();

    /* Same order as car_image_resolve() in car_images_helpers.php. */
    function findCarImage(brand, model, trim, year, color) {
        const b = imgNorm(brand), m = imgNorm(model);
        if (!b || !m) return '';
        const t = imgNorm(trim), y = imgNorm(year), c = imgNorm(color);
        const p = b + '|' + m + '|';
        const tries = [
            p + t + '|' + y + '|' + c,
            p + t + '||' + c,
            p + '|' + y + '|' + c,
            p + '||' + c,
            p + t + '|' + y + '|',
            p + t + '||',
            p + '|' + y + '|',
            p + '||'
        ];
        for (let i = 0; i < tries.length; i++) {
            if (CAR_IMAGES[tries[i]]) return CAR_IMAGES[tries[i]];
        }
        return '';
    }

    const progressFill  = document.getElementById('progressFill');
    const progressLabel = document.getElementById('progressLabel');
    const chassisHint   = document.getElementById('chassisHint');

    const modelLoading  = document.getElementById('modelLoading');
    const trimLoading   = document.getElementById('trimLoading');

    const TOTAL_REQUIRED = 7; // brand, model, year, trim, color, branch, chassis

    /* ── HELPERS ──────────────────────────────────── */
    function setText(el, val, emptyClass = true) {
        if (val) {
            el.textContent = val;
            el.classList.remove('empty');
        } else {
            el.textContent = '—';
            el.classList.add('empty');
        }
    }

    function selectedText(sel) {
        const opt = sel.options[sel.selectedIndex];
        return opt ? opt.text.trim() : '';
    }

    /* ── PROGRESS ─────────────────────────────────── */
    function updateProgress() {
        const fields = [
            brandSel.value,
            modelSel.value,
            yearSel.value,
            trimSel.value,
            colorSel.value,
            branchSel.value,
            chassisIn.value.trim(),
        ];
        const filled = fields.filter(Boolean).length;
        const pct    = Math.round((filled / TOTAL_REQUIRED) * 100);
        progressFill.style.width  = pct + '%';
        progressLabel.textContent = filled + ' / ' + TOTAL_REQUIRED;
    }

    /* ── LIVE PREVIEW ─────────────────────────────── */
    function updatePreview() {
        const brand  = brandSel.value  ? selectedText(brandSel)  : '';
        const model  = modelSel.value  ? modelSel.value          : '';
        const year   = yearSel.value   ? yearSel.value           : '';
        const trim   = trimSel.value   ? trimSel.value           : '';
        const color  = colorSel.value  ? selectedText(colorSel)  : '';
        const branch = branchSel.value ? selectedText(branchSel) : '';
        const chassis= chassisIn.value.trim();

        const vehicleName = [brand, model, year].filter(Boolean).join(' ') || '';

        setText(pVehicle, vehicleName);
        setText(pBrand,   brand);
        setText(pModel,   model);
        setText(pYear,    year);
        setText(pTrim,    trim);
        setText(pColor,   color);
        setText(pBranch,  branch);
        setText(pChassis, chassis);

        if (vehicleName) {
            pVehicle.style.color = 'var(--green)';
        }

        /* Show the library image for exactly this model and colour. */
        const imgUrl = findCarImage(brandSel.value, modelSel.value, trimSel.value, yearSel.value, colorSel.value);
        if (imgUrl) {
            if (pPhotoImg.getAttribute('src') !== imgUrl) pPhotoImg.src = imgUrl;
            pPhoto.classList.add('on');
        } else {
            pPhoto.classList.remove('on');
            pPhotoImg.removeAttribute('src');
        }

        updateProgress();
    }

    /* ── CHASSIS ──────────────────────────────────── */
    chassisIn.addEventListener('input', function () {
        const raw = this.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
        this.value = raw;

        if (raw.length === 0) {
            chassisHint.textContent = '<?= $lang === 'ar' ? 'أدخل رقم الشاسيه' : 'Enter chassis number' ?>';
            chassisHint.className = 'chassis-hint';
        } else if (raw.length < 5) {
            chassisHint.textContent = '<?= $lang === 'ar' ? 'الرقم قصير جداً' : 'Too short' ?>';
            chassisHint.className = 'chassis-hint invalid';
        } else if (raw.length > 17) {
            chassisHint.textContent = '<?= $lang === 'ar' ? 'الرقم طويل جداً' : 'Unusually long' ?>';
            chassisHint.className = 'chassis-hint invalid';
        } else {
            chassisHint.textContent = '✓ <?= $lang === 'ar' ? 'يبدو صحيحاً' : 'Looks good' ?>';
            chassisHint.className = 'chassis-hint valid';
        }

        updatePreview();
    });

    /* ── LOAD MODELS (ajax) ───────────────────────── */
    brandSel.addEventListener('change', function () {
        const brandVal = this.value;

        // reset dependent
        modelSel.innerHTML = '<option value=""><?= $t[$lang]['select_model'] ?></option>';
        trimSel.innerHTML  = '<option value=""><?= $t[$lang]['select_trim'] ?></option>';
        modelSel.disabled  = true;
        trimSel.disabled   = true;

        updatePreview();

        if (!brandVal) return;

        modelLoading.style.display = 'inline';

        fetch('get_models.php?brand=' + encodeURIComponent(brandVal))
            .then(r => {
                if (!r.ok) throw new Error('Network error');
                return r.json();
            })
            .then(data => {
                modelSel.innerHTML = '<option value=""><?= $t[$lang]['select_model'] ?></option>';
                if (Array.isArray(data) && data.length) {
                    data.forEach(item => {
                        const opt = document.createElement('option');
                        opt.value = item;
                        opt.textContent = item;
                        modelSel.appendChild(opt);
                    });
                    modelSel.disabled = false;
                } else {
                    modelSel.innerHTML = '<option value=""><?= $lang === 'ar' ? 'لا توجد موديلات' : 'No models found' ?></option>';
                }
            })
            .catch(() => {
                modelSel.innerHTML = '<option value=""><?= $lang === 'ar' ? 'خطأ في التحميل' : 'Load error' ?></option>';
            })
            .finally(() => {
                modelLoading.style.display = 'none';
                updatePreview();
            });
    });

    /* ── LOAD TRIMS (ajax) ────────────────────────── */
    modelSel.addEventListener('change', function () {
        const modelVal = this.value;

        trimSel.innerHTML = '<option value=""><?= $t[$lang]['select_trim'] ?></option>';
        trimSel.disabled  = true;

        updatePreview();

        if (!modelVal) return;

        trimLoading.style.display = 'inline';

        fetch('get_trims.php?brand=' + encodeURIComponent(brandSel.value) + '&model=' + encodeURIComponent(modelVal))
            .then(r => {
                if (!r.ok) throw new Error('Network error');
                return r.json();
            })
            .then(data => {
                trimSel.innerHTML = '<option value=""><?= $t[$lang]['select_trim'] ?></option>';
                if (Array.isArray(data) && data.length) {
                    data.forEach(item => {
                        const opt = document.createElement('option');
                        opt.value = item;
                        opt.textContent = item;
                        trimSel.appendChild(opt);
                    });
                    trimSel.disabled = false;
                } else {
                    trimSel.innerHTML = '<option value=""><?= $lang === 'ar' ? 'لا توجد فئات' : 'No trims found' ?></option>';
                }
            })
            .catch(() => {
                trimSel.innerHTML = '<option value=""><?= $lang === 'ar' ? 'خطأ في التحميل' : 'Load error' ?></option>';
            })
            .finally(() => {
                trimLoading.style.display = 'none';
                updatePreview();
            });
    });

    /* ── OTHER FIELD LISTENERS ────────────────────── */
    [yearSel, trimSel, colorSel, branchSel].forEach(el => {
        el.addEventListener('change', updatePreview);
    });

    /* ── CLIENT-SIDE FORM VALIDATION ─────────────── */
    document.getElementById('vehicleForm').addEventListener('submit', function (e) {
        const required = [brandSel, modelSel, yearSel, trimSel, colorSel, branchSel];
        let valid = true;

        required.forEach(sel => {
            if (!sel.value) { valid = false; sel.style.borderColor = 'rgba(239,68,68,0.6)'; }
            else              sel.style.borderColor = '';
        });

        if (!chassisIn.value.trim()) {
            valid = false;
            chassisIn.style.borderColor = 'rgba(239,68,68,0.6)';
        } else {
            chassisIn.style.borderColor = '';
        }

        if (!valid) {
            e.preventDefault();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    });

    /* ── INIT ─────────────────────────────────────── */
    updatePreview();

})();
</script>

<!-- ══ CHASSIS BARCODE SCANNER ══ -->
<div class="scan-modal" id="scanModal">
    <div class="scan-box">
        <div class="scan-head">
            <div class="st">📷 <?= $t[$lang]['scan_title'] ?></div>
            <button type="button" class="scan-close-btn" id="scanClose"><?= $t[$lang]['scan_close'] ?></button>
        </div>
        <div id="scanner-view"></div>
        <div class="scan-err-txt" id="scanErr"
             data-msg="<?= htmlspecialchars($t[$lang]['scan_fail'], ENT_QUOTES) ?>"
             data-msg-photo="<?= htmlspecialchars($t[$lang]['scan_photo_fail'], ENT_QUOTES) ?>"><?= $t[$lang]['scan_fail'] ?></div>
        <div class="scan-tools">
            <button type="button" class="scan-tool-btn" id="btnScanPhoto">🖼 <?= $t[$lang]['scan_photo'] ?></button>
            <div class="zoom-row" id="zoomRow"></div>
        </div>
        <input type="file" accept="image/*" capture="environment" id="scanPhotoInput" style="display:none"
               data-hero="<?= htmlspecialchars($t[$lang]['scan_use_photo'], ENT_QUOTES) ?>"
               data-reading="<?= htmlspecialchars($t[$lang]['scan_reading'], ENT_QUOTES) ?>"
               data-ocr="<?= htmlspecialchars($t[$lang]['scan_ocr'], ENT_QUOTES) ?>"
               data-select="<?= htmlspecialchars($t[$lang]['scan_select'], ENT_QUOTES) ?>"
               data-confirm="<?= htmlspecialchars($t[$lang]['scan_confirm'], ENT_QUOTES) ?>"
               data-new="<?= htmlspecialchars($t[$lang]['scan_new'], ENT_QUOTES) ?>">
        <div class="scan-hint-txt"><?= $t[$lang]['scan_hint'] ?></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(function () {
    /* Reads Code 39 / Code 128 (standard VIN plate barcodes) + QR + DataMatrix.
       On success: fills #chassis and fires 'input' so the existing
       cleaning / preview / validation logic runs untouched.

       v3 — built for real VIN windshield barcodes (thin bars, glossy, long):
       - Uses the phone's NATIVE barcode detector (Google ML Kit on
         Android Chrome) via useBarCodeDetectorIfSupported — far stronger
         at 1D barcodes than the pure-JS fallback.
       - High-resolution camera feed (thin bars need pixels).
       - 🖼 "From Photo": snap a sharp autofocused photo and decode the
         still image — the most reliable path, works on iPhone too.
       - Zoom buttons when the camera supports zoom.
       - Fallback CDN + visible error reasons (kept from v2). */
    const modal      = document.getElementById('scanModal');
    const errBox     = document.getElementById('scanErr');
    const chassis    = document.getElementById('chassis');
    const photoBtn   = document.getElementById('btnScanPhoto');
    const photoInput = document.getElementById('scanPhotoInput');
    const zoomRow    = document.getElementById('zoomRow');
    let   scanner    = null;

    function showScanErr(extra) {
        errBox.textContent = errBox.getAttribute('data-msg') + (extra ? ' [' + extra + ']' : '');
        errBox.style.display = 'block';
    }
    function showPhotoErr() {
        errBox.textContent = errBox.getAttribute('data-msg-photo');
        errBox.style.display = 'block';
    }

    /* Make sure the scanner library is available.
       Primary: jsdelivr (script tag above). Fallback: unpkg. */
    function ensureLib() {
        return new Promise(function (resolve, reject) {
            if (typeof Html5Qrcode !== 'undefined') { resolve(); return; }
            const s = document.createElement('script');
            s.src = 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js';
            s.onload  = function () {
                (typeof Html5Qrcode !== 'undefined') ? resolve() : reject('lib');
            };
            s.onerror = function () { reject('lib'); };
            document.head.appendChild(s);
        });
    }

    function getConfig() {
        return {
            formatsToSupport: [
                Html5QrcodeSupportedFormats.CODE_39,
                Html5QrcodeSupportedFormats.CODE_128,
                Html5QrcodeSupportedFormats.QR_CODE,
                Html5QrcodeSupportedFormats.DATA_MATRIX,
            ],
            /* THE key line for VIN barcodes: use the phone's native
               ML Kit detector when the browser has one (Android Chrome). */
            experimentalFeatures: { useBarCodeDetectorIfSupported: true },
            verbose: false,
        };
    }

    function cleanVin(decoded) {
        /* Clean typical VIN barcode payloads:
           some encode surrounding "*" or a leading import "I". */
        let v = String(decoded).trim().toUpperCase();
        v = v.replace(/^\*+|\*+$/g, '');
        if (v.length === 18 && v.charAt(0) === 'I') v = v.slice(1);

    /* First 1 Car business rule: the internal chassis number is the
       digit group AFTER the last letters of the full VIN.
       e.g. LS5A3DKE2TA991628 -> 991628 */
        var m = v.match(/([0-9]+)$/);
        if (m && m[1].length >= 4) v = m[1];
        return v;
    }

    /* ═══ ZBAR ENGINE (WebAssembly build of the ZBar scanner) ═══
       Much stronger at long/thin 1D barcodes (VIN plates) than the
       JS decoder — used as the primary engine for live frames on
       phones without a native detector (iPhone!) and for photos. */
    var zbarLoading = null;
    function ensureZbar(){
        if (window.zbarWasm) return Promise.resolve();
        if (zbarLoading) return zbarLoading;
        zbarLoading = new Promise(function (resolve, reject) {
            var urls = [
                'https://cdn.jsdelivr.net/npm/@undecaf/zbar-wasm@0.11.0/dist/index.js',
                'https://unpkg.com/@undecaf/zbar-wasm@0.11.0/dist/index.js'
            ];
            (function tryUrl(i){
                if (i >= urls.length) { zbarLoading = null; reject('zbar'); return; }
                var s = document.createElement('script');
                s.src = urls[i];
                s.onload  = function(){ window.zbarWasm ? resolve() : tryUrl(i + 1); };
                s.onerror = function(){ tryUrl(i + 1); };
                document.head.appendChild(s);
            })(0);
        });
        return zbarLoading;
    }

    function looksLikeChassis(v){
        return typeof v === 'string' && /^[A-Z0-9]{6,25}$/.test(v);
    }

    /* Scan an ImageData with zbar; return best VIN-like string or null */
    function zbarScan(imageData){
        return window.zbarWasm.scanImageData(imageData).then(function (symbols) {
            var best = null;
            (symbols || []).forEach(function (sym) {
                try {
                    var v = cleanVin(sym.decode());
                    if (looksLikeChassis(v) && (!best || v.length > best.length)) best = v;
                } catch (e) {}
            });
            return best;
        }).catch(function(){ return null; });
    }

    /* ── Live frame loop: grabs the camera frame every 400ms and runs
       zbar on the middle band, in parallel with the built-in decoder ── */
    var zbarTimer = null, zbarBusy = false, zbarTick = 0;
    function startZbarLoop(){
        stopZbarLoop();
        ensureZbar().then(function(){
            zbarTimer = setInterval(function(){
                if (zbarBusy) return;
                var video = document.querySelector('#scanner-view video');
                if (!video || video.readyState < 2 || !video.videoWidth) return;
                zbarBusy = true;
                try {
                    /* grab the middle band of the frame at full stream resolution */
                    var vw = video.videoWidth, vh = video.videoHeight;
                    var bandH = Math.floor(vh * 0.5), bandY = Math.floor((vh - bandH) / 2);
                    var band = document.createElement('canvas');
                    band.width = vw; band.height = bandH;
                    var ctx = band.getContext('2d', { willReadFrequently: true });
                    ctx.drawImage(video, 0, bandY, vw, bandH, 0, 0, vw, bandH);

                    /* cycle enhancement variants — same tricks that make
                       photo mode succeed, applied to live frames */
                    var variant;
                    switch (zbarTick++ % 3) {
                        case 0:  variant = band; break;
                        case 1:  variant = contrastStretch(band); break;
                        default: variant = contrastStretch(centerBand(band, 1.6)); break;
                    }
                    zbarScan(canvasData(variant)).then(function (v) {
                        zbarBusy = false;
                        if (v){ stopZbarLoop(); fillChassis(v); }
                    });
                } catch (e) { zbarBusy = false; }
            }, 350);
        }).catch(function(){ /* zbar unavailable: built-in decoder still runs */ });
    }
    function stopZbarLoop(){
        if (zbarTimer){ clearInterval(zbarTimer); zbarTimer = null; }
        zbarBusy = false;
    }

    /* ── Photo decoding: multiple enhanced variants through zbar ── */
    function fileToImage(file){
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload  = function(){ resolve(img); };
            img.onerror = function(){ URL.revokeObjectURL(url); reject('img'); };
            img.src = url;
        });
    }
    function drawScaled(img, maxSide){
        var scale = Math.min(1, maxSide / Math.max(img.naturalWidth, img.naturalHeight));
        /* never downscale below 1200 on the long side; upscale small images */
        var minSide = 1200;
        if (Math.max(img.naturalWidth, img.naturalHeight) * scale < minSide) {
            scale = minSide / Math.max(img.naturalWidth, img.naturalHeight);
        }
        var c = document.createElement('canvas');
        c.width  = Math.round(img.naturalWidth  * scale);
        c.height = Math.round(img.naturalHeight * scale);
        var ctx = c.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(img, 0, 0, c.width, c.height);
        return c;
    }
    function contrastStretch(canvas){
        var ctx = canvas.getContext('2d', { willReadFrequently: true });
        var d = ctx.getImageData(0, 0, canvas.width, canvas.height);
        var p = d.data, min = 255, max = 0, i, g;
        for (i = 0; i < p.length; i += 4){
            g = (p[i] * 0.299 + p[i+1] * 0.587 + p[i+2] * 0.114) | 0;
            if (g < min) min = g;
            if (g > max) max = g;
        }
        var range = Math.max(1, max - min);
        var c2 = document.createElement('canvas');
        c2.width = canvas.width; c2.height = canvas.height;
        var ctx2 = c2.getContext('2d', { willReadFrequently: true });
        var out = ctx2.createImageData(canvas.width, canvas.height);
        for (i = 0; i < p.length; i += 4){
            g = (p[i] * 0.299 + p[i+1] * 0.587 + p[i+2] * 0.114) | 0;
            g = Math.max(0, Math.min(255, ((g - min) * 255 / range) | 0));
            out.data[i] = out.data[i+1] = out.data[i+2] = g;
            out.data[i+3] = 255;
        }
        ctx2.putImageData(out, 0, 0);
        return c2;
    }
    function centerBand(canvas, factor){
        var bandH = Math.floor(canvas.height * 0.6);
        var y = Math.floor((canvas.height - bandH) / 2);
        var c2 = document.createElement('canvas');
        c2.width  = Math.floor(canvas.width * factor);
        c2.height = Math.floor(bandH * factor);
        var ctx2 = c2.getContext('2d', { willReadFrequently: true });
        ctx2.imageSmoothingEnabled = true;
        ctx2.drawImage(canvas, 0, y, canvas.width, bandH, 0, 0, c2.width, c2.height);
        return c2;
    }
    function canvasData(c){
        return c.getContext('2d', { willReadFrequently: true })
                .getImageData(0, 0, c.width, c.height);
    }
    function decodePhoto(file){
        return Promise.all([ensureZbar(), fileToImage(file)]).then(function (r) {
            var img  = r[1];
            var base = drawScaled(img, 2400);
            var variants = [
                base,
                contrastStretch(base),
                centerBand(base, 1.5),
                contrastStretch(centerBand(base, 2)),
                drawScaled(img, 3600)
            ];
            return (function tryVariant(i){
                if (i >= variants.length) return null;
                return zbarScan(canvasData(variants[i])).then(function (v) {
                    return v ? v : tryVariant(i + 1);
                });
            })(0);
        });
    }

    /* ═══ OCR ENGINE (Tesseract.js) — for text-only plates ═══
       Some plates (e.g. this photo's Changan style) have NO barcode,
       only etched text. When both barcode engines find nothing,
       the photo is read as TEXT. Loaded lazily; cached after first use. */
    var ocrWorker = null, ocrLoading = null;
    function ensureOcr(){
        if (ocrWorker) return Promise.resolve(ocrWorker);
        if (ocrLoading) return ocrLoading;
        ocrLoading = new Promise(function (resolve, reject) {
            function boot(){
                window.Tesseract.createWorker('eng').then(function (w) {
                    return w.setParameters({
                        tessedit_char_whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789*'
                    }).then(function(){ ocrWorker = w; resolve(w); });
                }).catch(function(){ ocrLoading = null; reject('ocr'); });
            }
            if (window.Tesseract){ boot(); return; }
            var urls = [
                'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js',
                'https://unpkg.com/tesseract.js@5/dist/tesseract.min.js'
            ];
            (function tryUrl(i){
                if (i >= urls.length){ ocrLoading = null; reject('ocr'); return; }
                var s = document.createElement('script');
                s.src = urls[i];
                s.onload  = function(){ window.Tesseract ? boot() : tryUrl(i + 1); };
                s.onerror = function(){ tryUrl(i + 1); };
                document.head.appendChild(s);
            })(0);
        });
        return ocrLoading;
    }

    function extractChassisFromText(text){
        var t = String(text || '').toUpperCase();
        var tokens = t.replace(/[^A-Z0-9]+/g, ' ').split(' ').filter(Boolean);
        /* OCR sometimes splits the VIN with a stray space — try merged too */
        if (tokens.length > 1) tokens.push(tokens.join(''));
        var best = null;
        tokens.forEach(function (tok) {
            /* VIN plates never contain I, O or Q — fix classic OCR confusions */
            var v = tok.replace(/O/g, '0').replace(/I/g, '1').replace(/Q/g, '0');
            if (!/^[A-Z0-9]{10,20}$/.test(v)) return;   /* VIN-length token */
            if (!/[0-9]{4,}$/.test(v)) return;          /* must end in the digit group */
            if (!best || v.length > best.length) best = v;
        });
        return best ? cleanVin(best) : null;
    }

    function invertCanvas(canvas){
        var c2 = document.createElement('canvas');
        c2.width = canvas.width; c2.height = canvas.height;
        var ctx2 = c2.getContext('2d', { willReadFrequently: true });
        ctx2.drawImage(canvas, 0, 0);
        var d = ctx2.getImageData(0, 0, c2.width, c2.height), p = d.data;
        for (var i = 0; i < p.length; i += 4){
            p[i] = 255 - p[i]; p[i+1] = 255 - p[i+1]; p[i+2] = 255 - p[i+2];
        }
        ctx2.putImageData(d, 0, 0);
        return c2;
    }

    function ocrCanvas(canvas, psm){
        return ensureOcr().then(function (w) {
            var pre = psm ? w.setParameters({ tessedit_pageseg_mode: psm }) : Promise.resolve();
            return pre.then(function(){
                return w.recognize(canvas);
            }).then(function (res) {
                var v = extractChassisFromText(res && res.data ? res.data.text : '');
                if (!psm) return v;
                /* restore default block mode for whole-photo passes */
                return w.setParameters({ tessedit_pageseg_mode: '6' }).then(function(){ return v; });
            });
        }).catch(function(){ return null; });
    }

    function ocrPhoto(img){
        var base = drawScaled(img, 2000);
        var stretched = contrastStretch(base);
        var variants = [
            stretched,                                   /* enhanced photo   */
            invertCanvas(stretched),                     /* light-on-dark    */
            contrastStretch(centerBand(base, 1.4))       /* zoomed mid strip */
        ];
        return (function tryVariant(i){
            if (i >= variants.length) return Promise.resolve(null);
            return ocrCanvas(variants[i]).then(function (v) {
                return v ? v : tryVariant(i + 1);
            });
        })(0);
    }

    /* ═══ MANUAL TARGETING (last-resort, strongest mode) ═══
       When auto-decode fails, the photo is shown and the user
       highlights the chassis with a finger. Only that strip is
       decoded — upscaled 4x with adaptive thresholding, which
       specifically defeats uneven glare/reflections. */
    var cropImg = null, cropSel = null, cropCanvas = null, cropScale = 1, cropBusy = false;

    /* Adaptive (local mean) threshold — kills uneven reflections */
    function adaptiveThreshold(canvas){
        var ctx = canvas.getContext('2d', { willReadFrequently: true });
        var w = canvas.width, h = canvas.height;
        var d = ctx.getImageData(0, 0, w, h).data;
        var gray = new Float64Array(w * h);
        for (var i = 0, j = 0; i < d.length; i += 4, j++)
            gray[j] = d[i] * 0.299 + d[i+1] * 0.587 + d[i+2] * 0.114;
        var W = w + 1;
        var integ = new Float64Array(W * (h + 1));
        for (var y = 0; y < h; y++){
            var rs = 0;
            for (var x = 0; x < w; x++){
                rs += gray[y * w + x];
                integ[(y + 1) * W + x + 1] = integ[y * W + x + 1] + rs;
            }
        }
        var win = Math.max(15, (Math.min(w, h) / 12) | 0), half = (win / 2) | 0, C = 8;
        var out = document.createElement('canvas');
        out.width = w; out.height = h;
        var octx = out.getContext('2d', { willReadFrequently: true });
        var od = octx.createImageData(w, h);
        for (var y2 = 0; y2 < h; y2++){
            var ya = Math.max(0, y2 - half), yb = Math.min(h - 1, y2 + half);
            for (var x2 = 0; x2 < w; x2++){
                var xa = Math.max(0, x2 - half), xb = Math.min(w - 1, x2 + half);
                var area = (xb - xa + 1) * (yb - ya + 1);
                var sum = integ[(yb + 1) * W + xb + 1] - integ[ya * W + xb + 1]
                        - integ[(yb + 1) * W + xa]     + integ[ya * W + xa];
                var v = gray[y2 * w + x2] > (sum / area - C) ? 255 : 0;
                var k = (y2 * w + x2) * 4;
                od.data[k] = od.data[k+1] = od.data[k+2] = v; od.data[k+3] = 255;
            }
        }
        octx.putImageData(od, 0, 0);
        return out;
    }

    function normSel(){
        if (!cropSel) return null;
        var x = Math.min(cropSel.x0, cropSel.x1), y = Math.min(cropSel.y0, cropSel.y1);
        var w = Math.abs(cropSel.x1 - cropSel.x0), h = Math.abs(cropSel.y1 - cropSel.y0);
        return { x: x, y: y, w: w, h: h };
    }

    function cropDraw(){
        var ctx = cropCanvas.getContext('2d');
        ctx.drawImage(cropImg, 0, 0, cropCanvas.width, cropCanvas.height);
        var s = normSel();
        if (!s) return;
        ctx.fillStyle = 'rgba(0,0,0,0.5)';
        ctx.fillRect(0, 0, cropCanvas.width, s.y);
        ctx.fillRect(0, s.y, s.x, s.h);
        ctx.fillRect(s.x + s.w, s.y, cropCanvas.width - s.x - s.w, s.h);
        ctx.fillRect(0, s.y + s.h, cropCanvas.width, cropCanvas.height - s.y - s.h);
        ctx.strokeStyle = '#22c55e'; ctx.lineWidth = 3;
        ctx.strokeRect(s.x, s.y, s.w, s.h);
    }

    function showCropUI(img){
        cropImg = img; cropSel = null; cropBusy = false;
        var input = document.getElementById('scanPhotoInput');
        var view  = document.getElementById('scanner-view');
        view.innerHTML = '';

        var hint = document.createElement('div');
        hint.style.cssText = 'color:#94a3b8;font-size:12.5px;text-align:center;padding:10px 16px 6px;line-height:1.6;';
        hint.textContent = input.getAttribute('data-select');
        view.appendChild(hint);

        cropCanvas = document.createElement('canvas');
        var maxW = Math.max(280, (view.clientWidth || 400) - 24);
        cropScale = maxW / img.naturalWidth;
        cropCanvas.width  = maxW;
        cropCanvas.height = Math.round(img.naturalHeight * cropScale);
        cropCanvas.style.cssText = 'display:block;width:calc(100% - 24px);margin:0 auto;border-radius:12px;touch-action:none;cursor:crosshair;max-height:52vh;object-fit:contain;';
        view.appendChild(cropCanvas);

        var row = document.createElement('div');
        row.style.cssText = 'display:flex;gap:10px;justify-content:center;padding:12px;flex-wrap:wrap;';
        var ok = document.createElement('button');
        ok.type = 'button'; ok.className = 'scan-hero-btn';
        ok.style.cssText = 'margin:0;width:auto;padding:13px 22px;font-size:14px;';
        ok.textContent = input.getAttribute('data-confirm');
        ok.addEventListener('click', decodeCrop);
        var again = document.createElement('button');
        again.type = 'button'; again.className = 'scan-tool-btn';
        again.textContent = input.getAttribute('data-new');
        again.addEventListener('click', function(){ input.click(); });
        row.appendChild(ok); row.appendChild(again);
        view.appendChild(row);

        function pos(e){
            var r = cropCanvas.getBoundingClientRect();
            return {
                x: (e.clientX - r.left) * (cropCanvas.width  / r.width),
                y: (e.clientY - r.top)  * (cropCanvas.height / r.height)
            };
        }
        var dragging = false;
        cropCanvas.addEventListener('pointerdown', function(e){
            e.preventDefault();
            cropCanvas.setPointerCapture(e.pointerId);
            var p = pos(e);
            cropSel = { x0: p.x, y0: p.y, x1: p.x, y1: p.y };
            dragging = true; cropDraw();
        });
        cropCanvas.addEventListener('pointermove', function(e){
            if (!dragging) return;
            var p = pos(e);
            cropSel.x1 = Math.max(0, Math.min(cropCanvas.width,  p.x));
            cropSel.y1 = Math.max(0, Math.min(cropCanvas.height, p.y));
            cropDraw();
        });
        cropCanvas.addEventListener('pointerup', function(){ dragging = false; });

        cropDraw();
    }

    function decodeCrop(){
        if (cropBusy) return;
        var s = normSel();
        if (!s || s.w < 25 || s.h < 10) return;   /* need a real selection */
        cropBusy = true;

        /* map selection back to the ORIGINAL full-resolution photo */
        var sx = s.x / cropScale, sy = s.y / cropScale;
        var sw = s.w / cropScale, sh = s.h / cropScale;
        var f  = Math.min(6, Math.max(1.5, 1800 / sw));   /* heavy upscale */
        var c  = document.createElement('canvas');
        c.width  = Math.round(sw * f);
        c.height = Math.round(sh * f);
        c.getContext('2d', { willReadFrequently: true })
         .drawImage(cropImg, sx, sy, sw, sh, 0, 0, c.width, c.height);

        var input = document.getElementById('scanPhotoInput');
        var view  = document.getElementById('scanner-view');
        view.innerHTML = '<div style="color:#94a3b8;font-size:13px;text-align:center;padding:60px 18px;line-height:1.6;">'
                       + input.getAttribute('data-reading') + '</div>';

        var stretched = contrastStretch(c);
        var thresh    = adaptiveThreshold(c);

        /* barcode engines on the strip first (fast), then OCR in
           single-line mode — the strip IS a single line */
        (function run(){
            return zbarScan(canvasData(c)).then(function (v) {
                return v || zbarScan(canvasData(stretched));
            }).then(function (v) {
                return v || zbarScan(canvasData(thresh));
            }).then(function (v) {
                if (v) return v;
                return ocrCanvas(stretched, '7').then(function (v2) {
                    return v2 || ocrCanvas(invertCanvas(stretched), '7');
                }).then(function (v2) {
                    return v2 || ocrCanvas(thresh, '7');
                });
            });
        })().then(function (v) {
            cropBusy = false;
            if (v){ fillChassis(v); return; }
            showPhotoErr();
            showCropUI(cropImg);   /* keep the photo — try a tighter selection */
        }).catch(function(){
            cropBusy = false;
            showPhotoErr();
            showCropUI(cropImg);
        });
    }




    function fillChassis(v) {
        chassis.value = v;
        chassis.dispatchEvent(new Event('input', { bubbles: true }));
        if (navigator.vibrate) navigator.vibrate(80);
        closeScanner();
        chassis.focus();
    }

    function setupZoom() {
        zoomRow.innerHTML = '';
        try {
            const caps = scanner.getRunningTrackCapabilities();
            if (!caps || !caps.zoom) return;
            const zmin = caps.zoom.min || 1, zmax = caps.zoom.max || 1;
            let first = true;
            [1, 2, 3, 4].forEach(function (z) {
                if (z < zmin || z > zmax) return;
                const b = document.createElement('button');
                b.type = 'button'; b.className = 'zoom-btn' + (first ? ' on' : '');
                first = false;
                b.textContent = z + 'x';
                b.addEventListener('click', function () {
                    try {
                        scanner.applyVideoConstraints({ advanced: [{ zoom: z }] });
                        zoomRow.querySelectorAll('.zoom-btn').forEach(x => x.classList.remove('on'));
                        b.classList.add('on');
                    } catch (e) {}
                });
                zoomRow.appendChild(b);
            });
        } catch (e) {}
    }

    function startCamera() {
        const runCfg = { fps: 10, qrbox: function (w, h) {
            return { width: Math.floor(w * 0.9), height: Math.floor(h * 0.5) };
        } };
        const onDecode = function (d) { fillChassis(cleanVin(d)); };

        /* Retry chain — some phones reject resolution constraints,
           some reject facingMode; try progressively simpler configs,
           then an explicit camera id, before giving up on live video. */
        const attempts = [
            /* 4K first — modern phones deliver it and thin VIN bars need pixels */
            { facingMode: 'environment', width: { ideal: 3840 }, height: { ideal: 2160 } },
            { facingMode: 'environment', width: { ideal: 1920 }, height: { ideal: 1080 } },
            { facingMode: 'environment' },
        ];
        let lastErr = null;

        function cleanupScanner() {
            if (scanner) { try { scanner.clear(); } catch (e) {} scanner = null; }
        }
        function tryAt(i) {
            if (i >= attempts.length) { tryById(); return; }
            scanner = new Html5Qrcode('scanner-view', getConfig());
            scanner.start(attempts[i], runCfg, onDecode, function () {})
                .then(function(){ setupZoom(); startZbarLoop(); })
                .catch(function (err) { lastErr = err; cleanupScanner(); tryAt(i + 1); });
        }
        function tryById() {
            Html5Qrcode.getCameras().then(function (cams) {
                if (!cams || !cams.length) { throw (lastErr || new Error('no camera')); }
                /* last device is almost always the main back camera */
                scanner = new Html5Qrcode('scanner-view', getConfig());
                return scanner.start(cams[cams.length - 1].id, runCfg, onDecode, function () {})
                              .then(function(){ setupZoom(); startZbarLoop(); });
            }).catch(function (err) {
                cleanupScanner();
                liveFailed(err || lastErr);
            });
        }
        tryAt(0);
    }

    /* Live video is impossible on this device/browser →
       switch to native photo capture, which always works. */
    function showPhotoHero() {
        const view = document.getElementById('scanner-view');
        view.innerHTML = '';
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'scan-hero-btn';
        b.textContent = photoInput.getAttribute('data-hero');
        b.addEventListener('click', function () { photoInput.click(); });
        view.appendChild(b);
    }
    function liveFailed(err) {
        showScanErr(err && err.name ? err.name : String(err).slice(0, 80));
        showPhotoHero();
    }

    function stopCamera() {
        stopZbarLoop();
        return new Promise(function (resolve) {
            if (!scanner) { resolve(); return; }
            const s = scanner; scanner = null;
            try {
                s.stop().then(function () { s.clear(); resolve(); })
                        .catch(function () { resolve(); });
            } catch (e) { resolve(); }
        });
    }

    function openScanner() {
        /* PHOTO-FIRST: live-video decoding of VIN plates proved unreliable,
           so the 📷 button now goes straight to the phone's native camera.
           The modal stays behind as the retry / progress / error surface. */
        errBox.style.display = 'none';
        zoomRow.innerHTML = '';
        modal.classList.add('show');
        showPhotoHero();
        photoInput.click();
    }

    function closeScanner() {
        modal.classList.remove('show');
        stopCamera();
    }

    /* ── 🖼 Scan from a photo (most reliable for VIN plates) ── */
    photoBtn.addEventListener('click', function () {
        ensureLib().then(function () { photoInput.click(); })
                   .catch(function () { showScanErr('library blocked — check internet / ad blocker'); });
    });
    photoInput.addEventListener('change', function () {
        const file = this.files && this.files[0];
        this.value = '';
        if (!file) return;
        errBox.style.display = 'none';
        stopCamera().then(function () {
            const view = document.getElementById('scanner-view');
            view.innerHTML = '<div class="scan-hint-txt" style="padding:60px 18px">'
                           + photoInput.getAttribute('data-reading') + '</div>';

            /* 1st engine: zbar (multiple enhanced variants of the photo) */
            decodePhoto(file).then(function (v) {
                if (v) { fillChassis(v); return; }

                /* 2nd engine: html5-qrcode scanFile as fallback */
                view.innerHTML = '';
                const fs = new Html5Qrcode('scanner-view', getConfig());
                fs.scanFile(file, /* showImage */ true)
                  .then(function (decoded) {
                      try { fs.clear(); } catch (e) {}
                      fillChassis(cleanVin(decoded));
                  })
                  .catch(function () {
                      try { fs.clear(); } catch (e) {}
                      /* 3rd engine: OCR — the plate may have no barcode
                         at all (text-only plates). Read it as text. */
                      view.innerHTML = '<div class="scan-hint-txt" style="padding:60px 18px">'
                                     + photoInput.getAttribute('data-ocr') + '</div>';
                      fileToImage(file)
                        .then(function (img) {
                            return ocrPhoto(img).then(function (v) {
                                if (v) { fillChassis(v); return; }
                                /* 4th stage: MANUAL TARGETING — show the photo,
                                   user highlights the chassis, only that strip
                                   is decoded with heavy enhancement. */
                                showCropUI(img);
                            });
                        })
                        .catch(function () {
                            showPhotoErr();
                            showPhotoHero();
                        });
                  });
            }).catch(function () {
                showPhotoErr();
                showPhotoHero();
            });
        });
    });

    document.getElementById('btnScanChassis').addEventListener('click', openScanner);
    document.getElementById('scanClose').addEventListener('click', closeScanner);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeScanner(); });
})();
</script>

<style>
/* ═══════════ Add-vehicle extras (form fields and order unchanged) ═══════════ */
.side-col { position: sticky; top: 20px; display: flex; flex-direction: column; gap: 18px; }
.side-col .preview-card { position: static; }
@media (max-width:1100px) { .side-col { position: static; } }

/* filled fields get a calm green edge and a tick */
.form-group label { display: flex; align-items: center; gap: 6px; }
.form-group label .av-tick { margin-inline-start: auto; width: 18px; height: 18px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 900; color: #052e16; background: var(--green); transform: scale(0); transition: transform .25s cubic-bezier(.34,1.56,.64,1); }
.form-group.done label .av-tick { transform: scale(1); }
.form-group.done select, .form-group.done input { border-color: rgba(34,197,94,.38); box-shadow: 0 0 0 3px rgba(34,197,94,.06); }
.form-group.bad input { border-color: rgba(239,68,68,.6) !important; box-shadow: 0 0 0 3px rgba(239,68,68,.1) !important; }

/* progress: turns into a "ready" state */
.form-progress.ready .progress-bar-fill { box-shadow: 0 0 14px rgba(34,197,94,.7); }
.form-progress.ready .progress-label { color: var(--green); }
.progress-label { direction: ltr; unicode-bidi: isolate; }
.submit-btn.ready { animation: avReady 2.4s ease-in-out infinite; }
@keyframes avReady { 0%,100% { box-shadow: 0 4px 20px rgba(34,197,94,.25); } 50% { box-shadow: 0 6px 34px rgba(34,197,94,.55); } }

/* colour: a dot inside the field + tap-to-pick chips */
.av-selwrap { position: relative; }
.av-seldot { position: absolute; top: 50%; inset-inline-start: 15px; width: 16px; height: 16px; margin-top: -8px; border-radius: 50%;
    border: 2px solid rgba(255,255,255,.25); box-shadow: 0 0 0 3px rgba(0,0,0,.25); pointer-events: none; display: none; }
.av-selwrap.has .av-seldot { display: block; }
.av-selwrap.has select { padding-inline-start: 42px; }
.av-sw { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 9px; }
.av-sw button { display: inline-flex; align-items: center; gap: 6px; height: 30px; padding: 0 11px 0 9px; border-radius: 999px; cursor: pointer;
    background: rgba(255,255,255,.03); border: 1px solid var(--border); color: var(--text-soft); font: inherit; font-size: 12px; font-weight: 700; transition: var(--transition); }
.av-sw button i { width: 13px; height: 13px; border-radius: 50%; border: 1px solid rgba(255,255,255,.3); flex-shrink: 0; }
.av-sw button:hover { border-color: rgba(255,255,255,.2); color: var(--text); }
.av-sw button.on { border-color: var(--sw); color: var(--text); background: color-mix(in srgb, var(--sw) 16%, transparent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--sw) 18%, transparent); }

/* chassis: bigger, monospaced, with a live status line */
#chassis { font-family: 'SFMono-Regular', Consolas, 'Courier New', monospace; font-size: 19px; font-weight: 800; letter-spacing: .12em; }
.chassis-hint { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; min-height: 20px; }
.chassis-hint.checking { color: var(--text-soft); }
.chassis-hint.dup { color: #fca5a5; }
.chassis-hint .ch-car { display: inline-flex; align-items: center; gap: 6px; background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.3); border-radius: 8px; padding: 2px 9px; color: #fecaca; font-weight: 700; }
.chassis-hint .ch-car i { width: 10px; height: 10px; border-radius: 50%; }
.av-spin { width: 12px; height: 12px; border-radius: 50%; border: 2px solid rgba(148,163,184,.3); border-top-color: var(--text-soft); animation: avSpin .7s linear infinite; display: inline-block; }
@keyframes avSpin { to { transform: rotate(360deg); } }

/* preview: a showroom stage that is always there */
.preview-photo.av-stage { display: block; --car: #64748b; aspect-ratio: 16/9.4;
    background: radial-gradient(ellipse 80% 70% at 50% 18%, color-mix(in srgb, var(--car) 22%, #1a2744), #0a1120 72%); border: 1px solid var(--border); }
.av-stage .av-floor { position: absolute; inset-inline: 0; bottom: 0; height: 34%;
    background: radial-gradient(ellipse 60% 55% at 50% 30%, color-mix(in srgb, var(--car) 30%, transparent), transparent 70%), linear-gradient(to bottom, rgba(255,255,255,.02), rgba(0,0,0,.3)); }
.av-stage .av-sil { position: absolute; inset-inline: 7%; bottom: 7%; width: 86%; height: auto; transition: opacity .3s, transform .45s cubic-bezier(.22,1,.36,1); }
.av-stage .av-body { fill: var(--car); transition: fill .45s; }
.av-stage.on .av-sil { opacity: 0; transform: translateX(-18px); }
.av-stage img { display: none; }
.av-stage.on img { display: block; position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; padding: 14px 18px 8px; }
.av-stage.on.scene img { object-fit: cover; padding: 0; }
.av-stage.on.studio { background: linear-gradient(#fff, #eef1f5); }
.av-stage.on.matte { background: var(--stage-bg, #0d1526); }
.av-stage.on.studio .av-floor, .av-stage.on.matte .av-floor, .av-stage.on.scene .av-floor { display: none; }
.av-stage .pp-tag { z-index: 2; }
.av-stage.pop .av-sil { animation: avPop .5s cubic-bezier(.34,1.56,.64,1); }
@keyframes avPop { 40% { transform: scale(1.04); } }
.av-stage .av-cname { position: absolute; bottom: 8px; inset-inline-end: 10px; z-index: 2; display: none; align-items: center; gap: 6px;
    background: rgba(2,6,23,.7); backdrop-filter: blur(6px); color: #e2e8f0; font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 999px; }
.av-stage .av-cname i { width: 10px; height: 10px; border-radius: 50%; background: var(--car); border: 1px solid rgba(255,255,255,.35); }
.av-stage.hasc .av-cname { display: inline-flex; }

/* chassis in the preview looks like a plate */
.preview-chassis-value:not(.empty) { font-family: 'SFMono-Regular', Consolas, monospace; letter-spacing: .12em; padding: 3px 10px; border-radius: 7px;
    background: linear-gradient(#fefce8, #fef3c7); color: #111827 !important; border: 2px solid #1f2937; box-shadow: 0 0 0 1px #fde68a; }
.preview-value .pv-dot { display: inline-block; width: 11px; height: 11px; border-radius: 50%; margin-inline-end: 6px; vertical-align: -1px; border: 1px solid rgba(255,255,255,.3); }

/* price + stock context */
.av-ctx { display: flex; flex-direction: column; gap: 10px; margin: -8px 0 18px; }
.av-ctx:empty { display: none; }
.av-box { border-radius: 16px; padding: 12px 14px; border: 1px solid var(--border); background: rgba(255,255,255,.025); animation: ppFade .3s ease both; }
.av-box .h { font-size: 11px; font-weight: 800; color: var(--text-muted); margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }
.av-box .h a { margin-inline-start: auto; color: #a78bfa; text-decoration: none; font-size: 11px; }
.av-price .v { font-size: 22px; font-weight: 900; color: var(--green); font-variant-numeric: tabular-nums; }
.av-price .v small { font-size: 11px; color: var(--text-soft); margin-inline-start: 4px; font-weight: 700; }
.av-price .tag { display: inline-block; margin-top: 6px; font-size: 12px; font-weight: 800; padding: 3px 10px; border-radius: 8px; background: rgba(148,163,184,.1); color: #cbd5e1; }
.av-price .tag.disc { background: rgba(245,158,11,.12); color: #fbbf24; } .av-price .tag.offer { background: rgba(56,189,248,.12); color: #7dd3fc; }
.av-price.none { border-color: rgba(245,158,11,.3); background: rgba(245,158,11,.06); }
.av-price.none .v { font-size: 13px; color: #fbbf24; }
.av-stock .big { font-size: 13px; color: var(--text); font-weight: 800; }
.av-stock .big b { font-size: 20px; color: #60a5fa; margin-inline-end: 4px; }
.av-stock .rows { display: flex; flex-direction: column; gap: 5px; margin-top: 8px; }
.av-stock .r { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--text-soft); padding: 5px 8px; border-radius: 9px; }
.av-stock .r.me { background: rgba(59,130,246,.1); color: var(--text); }
.av-stock .r .bn { font-weight: 800; min-width: 0; flex: 1; }
.av-stock .r .cs { display: flex; gap: 4px; flex-wrap: wrap; }
.av-stock .r .cs span { display: inline-flex; align-items: center; gap: 3px; font-weight: 800; font-size: 11px; }
.av-stock .r .cs i { width: 10px; height: 10px; border-radius: 50%; border: 1px solid rgba(255,255,255,.3); }
.av-stock .same { margin-top: 8px; font-size: 12px; font-weight: 800; color: #fbbf24; }
.av-stock.zero .big { color: var(--text-soft); }

/* today */
.av-today { padding: 22px; }
.avt-count { margin-inline-start: auto; font-size: 12px; font-weight: 900; background: var(--blue-dim); color: #93c5fd; padding: 3px 11px; border-radius: 999px; }
.avt-list { display: flex; flex-direction: column; gap: 6px; max-height: 330px; overflow-y: auto; margin: 0 -6px; padding: 0 6px; }
.avt-empty { font-size: 13px; color: var(--text-muted); text-align: center; padding: 18px 8px; border: 1px dashed var(--border); border-radius: 14px; }
.avt-item { display: flex; align-items: center; gap: 10px; padding: 9px 11px; border-radius: 13px; background: rgba(255,255,255,.025); border: 1px solid transparent; text-decoration: none; color: inherit; transition: var(--transition); }
a.avt-item:hover { border-color: rgba(59,130,246,.35); background: rgba(59,130,246,.07); }
.avt-item.fresh { border-color: rgba(34,197,94,.45); background: rgba(34,197,94,.08); animation: avFresh 1.6s ease 2; }
@keyframes avFresh { 50% { box-shadow: 0 0 0 4px rgba(34,197,94,.18); } }
.avt-dot { width: 14px; height: 14px; border-radius: 50%; flex-shrink: 0; border: 2px solid rgba(255,255,255,.2); }
.avt-main { flex: 1; min-width: 0; }
.avt-name { font-size: 13px; font-weight: 800; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.avt-name span { color: var(--text-muted); font-weight: 700; }
.avt-sub { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
.avt-ch { font-family: 'SFMono-Regular', Consolas, monospace; font-size: 12px; font-weight: 800; color: #fbbf24; letter-spacing: .06em; direction: ltr; }

/* success banner */
.added-banner { align-items: center; position: relative; overflow: hidden; background: linear-gradient(120deg, rgba(34,197,94,.14), rgba(13,20,40,.9) 60%); }
.ab-photo { width: 132px; aspect-ratio: 16/10; border-radius: 14px; flex-shrink: 0; overflow: hidden; background: #0d1526; display: flex; align-items: center; justify-content: center; position: relative; }
.ab-photo img { width: 100%; height: 100%; object-fit: contain; }
.ab-photo svg { width: 88%; }
.ab-body { flex: 1; min-width: 0; }
.ab-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
.ab-btn { height: 36px; padding: 0 14px; border-radius: 11px; display: inline-flex; align-items: center; gap: 6px; font: inherit; font-size: 13px; font-weight: 800; cursor: pointer;
    text-decoration: none; background: rgba(255,255,255,.05); border: 1px solid var(--border); color: var(--text); transition: var(--transition); }
.ab-btn:hover { border-color: rgba(255,255,255,.2); }
.ab-btn.ab-again { background: var(--green); border-color: var(--green); color: #052e16; }
.ab-btn.ab-again:hover { filter: brightness(1.08); }
.av-confetti { position: absolute; top: -10px; width: 7px; height: 12px; border-radius: 2px; opacity: .9; pointer-events: none; animation: avFall 1.8s cubic-bezier(.25,.6,.4,1) forwards; }
@keyframes avFall { to { transform: translateY(160px) rotate(540deg); opacity: 0; } }

/* confirm sheet */
.av-ov { position: fixed; inset: 0; z-index: 900; background: rgba(2,6,23,.78); backdrop-filter: blur(7px); display: none; align-items: center; justify-content: center; padding: 18px; }
.av-ov.on { display: flex; animation: avFade .2s ease both; }
@keyframes avFade { from { opacity: 0; } }
.av-sheet { width: 100%; max-width: 480px; max-height: 92vh; overflow-y: auto; border-radius: 26px; background: linear-gradient(170deg, #111b33, #0a1122); border: 1px solid rgba(255,255,255,.1);
    box-shadow: 0 40px 100px rgba(0,0,0,.6); animation: avIn .3s cubic-bezier(.22,1,.36,1) both; }
@keyframes avIn { from { transform: translateY(22px) scale(.97); opacity: 0; } }
.av-sheet .preview-photo { border-radius: 26px 26px 0 0; margin: 0; border: 0; border-bottom: 1px solid var(--border); }
.av-sb { padding: 18px 22px 22px; }
.av-st { font-size: 22px; font-weight: 900; color: var(--text); }
.av-ss { font-size: 13px; color: var(--text-soft); font-weight: 700; margin-top: 2px; }
.av-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin: 16px 0 12px; }
.av-grid div { background: rgba(255,255,255,.03); border: 1px solid var(--border); border-radius: 13px; padding: 9px 12px; }
.av-grid small { display: block; font-size: 10px; font-weight: 800; color: var(--text-muted); margin-bottom: 3px; }
.av-grid b { font-size: 14px; color: var(--text); display: flex; align-items: center; gap: 6px; }
.av-grid b i { width: 12px; height: 12px; border-radius: 50%; border: 1px solid rgba(255,255,255,.3); }
.av-plate { text-align: center; margin: 4px 0 6px; }
.av-plate small { display: block; font-size: 11px; font-weight: 800; color: var(--text-muted); margin-bottom: 6px; }
.av-plate span { display: inline-block; direction: ltr; font-family: 'SFMono-Regular', Consolas, monospace; font-size: 30px; font-weight: 900; letter-spacing: .16em; color: #111827;
    background: linear-gradient(#fefce8, #fde68a); border: 3px solid #1f2937; border-radius: 12px; padding: 6px 18px; box-shadow: 0 0 0 2px #fde68a, 0 12px 30px rgba(0,0,0,.4); word-break: break-all; }
.av-note { font-size: 12px; color: var(--text-soft); background: rgba(255,255,255,.03); border-radius: 11px; padding: 8px 12px; margin-top: 10px; white-space: pre-wrap; }
.av-warn { margin-top: 12px; font-size: 13px; font-weight: 800; color: #fecaca; background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.35); border-radius: 12px; padding: 10px 12px; }
.av-actions { display: flex; flex-direction: column; gap: 8px; margin-top: 16px; }
.av-actions button { height: 50px; border-radius: 14px; border: 1px solid var(--border); font: inherit; font-size: 15px; font-weight: 800; cursor: pointer; transition: var(--transition);
    display: flex; align-items: center; justify-content: center; gap: 8px; }
.av-ok { background: linear-gradient(135deg, var(--green), #16a34a); color: #fff; border: 0 !important; box-shadow: 0 8px 26px rgba(34,197,94,.3); }
.av-ok2 { background: rgba(34,197,94,.1); color: #86efac; border-color: rgba(34,197,94,.3) !important; }
.av-back { background: transparent; color: var(--text-soft); }
.av-actions button:disabled { opacity: .45; cursor: not-allowed; box-shadow: none; }
.av-actions button.busy .av-spin { border-color: rgba(255,255,255,.35); border-top-color: #fff; }
.av-toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%) translateY(20px); z-index: 950; background: #0f2a1a; border: 1px solid rgba(34,197,94,.4); color: #dcfce7;
    font-size: 13px; font-weight: 800; padding: 11px 18px; border-radius: 999px; opacity: 0; transition: all .3s; pointer-events: none; max-width: calc(100% - 32px); text-align: center; }
.av-toast.on { opacity: 1; transform: translateX(-50%); }

@media (max-width:768px) {
    .submit-btn { position: sticky; bottom: 12px; z-index: 50; box-shadow: 0 10px 30px rgba(0,0,0,.55), 0 4px 20px rgba(34,197,94,.3); }
    .added-banner { flex-direction: column; align-items: stretch; }
    .ab-photo { width: 100%; }
    .av-ov { align-items: flex-end; padding: 0; }
    .av-sheet { max-width: none; border-radius: 26px 26px 0 0; animation: avUp .34s cubic-bezier(.22,1,.36,1) both; }
    @keyframes avUp { from { transform: translateY(100%); } }
    .av-plate span { font-size: 24px; }
    .av-sw button { height: 34px; }
}
@media (prefers-reduced-motion: reduce) { .submit-btn.ready, .avt-item.fresh { animation: none; } .av-confetti { display: none; } }
</style>

<div class="av-ov" id="avOv" aria-hidden="true"><div class="av-sheet" id="avSheet" role="dialog" aria-modal="true"></div></div>
<div class="av-toast" id="avToast"></div>

<script>
(function () {
    'use strict';
    const AR = <?= json_encode($isRTL) ?>, LANG = <?= json_encode($lang) ?>;
    const COLORS = <?= json_encode(array_map(fn($c) => ['en' => (string)$c['color_en'], 'ar' => (string)$c['color_ar'], 'hex' => av_swatch((string)$c['color_en'])], $colors), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const BRANCHES = <?= json_encode(array_map(fn($b) => ['name' => (string)$b['name'], 'ar' => (string)$b['name_ar'], 'en' => (string)$b['name_en']], $branches), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const PRICES = <?= json_encode((object)$avPrices, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const STOCK = <?= json_encode((object)$avStock, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const CAN_PRICE = <?= can('page.prices') ? 'true' : 'false' ?>;
    const PREFILL = <?= json_encode($avPrefill, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const ADDED = <?= json_encode($addedVehicle, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const KEPT = <?= $avKeep ? 'true' : 'false' ?>;
    const CUR = AR ? 'جنيه' : 'EGP';
    const T = AR ? {
        checking: 'جارٍ التحقق من الرقم…', fresh: '✓ رقم جديد — غير مسجّل في النظام', dup: '⚠️ مسجّل بالفعل:', st: { available: 'متاحة', reserved: 'محجوزة', sold: 'مباعة', consignment: 'أمانة' },
        price: '💰 السعر الرسمي', noPrice: '⚠️ هذه الفئة لم تُسعّر بعد لهذه السنة', toPrices: 'صفحة الأسعار ←', stock: '📦 في المخزون الآن', none: 'لا توجد سيارة مماثلة في المخزون حالياً — هذه الأولى',
        cars: 'سيارة', same: n => '⚠️ عندك ' + n + ' بنفس اللون في نفس الفرع', confirmT: 'راجع البيانات قبل الإضافة', chassis: 'رقم الشاسيه',
        ok: '✓ تأكيد وإضافة', ok2: '✓ إضافة ثم إضافة سيارة مماثلة', back: '✏️ رجوع للتعديل', saving: 'جارٍ الحفظ…', dupWarn: '⚠️ رقم الشاسيه مسجّل بالفعل — لا يمكن إضافته مرة أخرى',
        kept: '✓ نفس السيارة جاهزة — اختر اللون ورقم الشاسيه', branch: 'الفرع', color: 'اللون', trim: 'الفئة', year: 'السنة', notes: 'ملاحظات'
    } : {
        checking: 'Checking the number…', fresh: '✓ New number — not in the system', dup: '⚠️ Already registered:', st: { available: 'Available', reserved: 'Reserved', sold: 'Sold', consignment: 'Consignment' },
        price: '💰 Official price', noPrice: '⚠️ This trim has no price for this year yet', toPrices: 'Prices page →', stock: '📦 In stock right now', none: 'None of this car in stock right now — this is the first',
        cars: 'cars', same: n => '⚠️ You already have ' + n + ' in this colour at this branch', confirmT: 'Check the details before adding', chassis: 'Chassis No.',
        ok: '✓ Confirm & add', ok2: '✓ Add, then add another like this', back: '✏️ Back to edit', saving: 'Saving…', dupWarn: '⚠️ This chassis number is already registered — it cannot be added again',
        kept: '✓ Same car ready — pick the colour and chassis', branch: 'Branch', color: 'Colour', trim: 'Trim', year: 'Year', notes: 'Notes'
    };
    const $ = id => document.getElementById(id);
    const esc = v => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const norm = v => String(v == null ? '' : v).trim().toLowerCase();
    const key = (...p) => p.map(norm).join('|');
    const colorOf = en => COLORS.find(c => norm(c.en) === norm(en));
    const hexOf = en => (colorOf(en) || {}).hex || '#64748b';
    const colorLabel = en => { const c = colorOf(en); return c ? (AR ? c.ar : c.en) : en; };
    const branchLabel = n => { const b = BRANCHES.find(x => x.name === n); return b ? (AR ? b.ar : b.en) : n; };
    const dealKind = v => /خصم|discount/i.test(v) ? 'disc' : (/أوفر|offer/i.test(v) ? 'offer' : '');
    function toast(m) { const t = $('avToast'); t.textContent = m; t.classList.add('on'); clearTimeout(toast._t); toast._t = setTimeout(() => t.classList.remove('on'), 2600); }

    const form = $('vehicleForm');
    const f = { brand: $('brand'), model: $('model'), car_year: $('car_year'), trim_name: $('trim'), color: $('color'), branch: $('branch'), chassis: $('chassis'), notes: $('notes') };
    const stageEl = $('previewPhoto'), stageImg = $('previewPhotoImg'), hint = $('chassisHint'), submitBtn = $('submitBtn');

    /* ═══ ticks on filled fields + ready state ═══ */
    const req = ['brand', 'model', 'car_year', 'trim_name', 'color', 'branch', 'chassis'];
    req.forEach(k => { const lb = f[k].closest('.form-group').querySelector('label'); if (lb) lb.insertAdjacentHTML('beforeend', '<span class="av-tick">✓</span>'); });
    let dup = null;   // the car that already has this chassis, if any
    function refresh() {
        let n = 0;
        req.forEach(k => { const ok = !!f[k].value.trim() && !(k === 'chassis' && dup); f[k].closest('.form-group').classList.toggle('done', ok); if (ok) n++; });
        f.chassis.closest('.form-group').classList.toggle('bad', !!dup);
        const ready = n === req.length;
        const fp = document.querySelector('.form-progress'); if (fp) fp.classList.toggle('ready', ready);
        submitBtn.classList.toggle('ready', ready);
        paintColor(); context();
    }
    Object.values(f).forEach(el => { el.addEventListener('change', refresh); el.addEventListener('input', refresh); });

    /* ═══ colour: dot inside the field, tap-to-pick chips, tinted stage ═══ */
    const cw = f.color.closest('.field-wrap'); cw.classList.add('av-selwrap'); cw.insertAdjacentHTML('afterbegin', '<i class="av-seldot" id="avSelDot"></i>');
    const sw = document.createElement('div'); sw.className = 'av-sw';
    sw.innerHTML = COLORS.map(c => '<button type="button" data-v="' + esc(c.en) + '" style="--sw:' + c.hex + '"><i style="background:' + c.hex + '"></i>' + esc(AR ? c.ar : c.en) + '</button>').join('');
    cw.parentElement.appendChild(sw);
    sw.addEventListener('click', e => { const b = e.target.closest('button'); if (!b) return; f.color.value = b.dataset.v; f.color.dispatchEvent(new Event('change', { bubbles: true })); });
    stageEl.insertAdjacentHTML('beforeend', '<span class="av-cname"><i></i><b id="avCName"></b></span>');
    let lastColor = null;
    function paintColor() {
        const v = f.color.value, hx = v ? hexOf(v) : '#64748b';
        cw.classList.toggle('has', !!v); $('avSelDot').style.background = hx;
        sw.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.v === v));
        stageEl.style.setProperty('--car', hx); stageEl.classList.toggle('hasc', !!v); $('avCName').textContent = v ? colorLabel(v) : '';
        if (v !== lastColor) { stageEl.classList.remove('pop'); void stageEl.offsetWidth; if (v) stageEl.classList.add('pop'); lastColor = v; }
        const pc = $('previewColor');
        if (pc && v && !pc.querySelector('.pv-dot')) pc.insertAdjacentHTML('afterbegin', '<i class="pv-dot" style="background:' + hx + '"></i>');
        else if (pc && pc.querySelector('.pv-dot')) pc.querySelector('.pv-dot').style.background = hx;
    }
    // the existing preview rewrites the colour text; keep the dot in front of it
    new MutationObserver(() => { const pc = $('previewColor'); if (f.color.value && pc && !pc.querySelector('.pv-dot')) paintColor(); })
        .observe($('previewColor'), { childList: true });

    /* ═══ library photo on the stage ═══ */
    function stage(img, st, pad) {
        st.classList.remove('scene', 'studio', 'matte', 'ready'); st.style.removeProperty('--stage-bg'); img.removeAttribute('style');
        function go() {
            try {
                const w = 120, h = Math.max(24, Math.round(w * img.naturalHeight / img.naturalWidth));
                const cv = document.createElement('canvas'); cv.width = w; cv.height = h;
                const cx = cv.getContext('2d', { willReadFrequently: true }); cx.drawImage(img, 0, 0, w, h);
                const dd = cx.getImageData(0, 0, w, h).data, at = (x, y) => { const i = (y * w + x) * 4; return [dd[i], dd[i + 1], dd[i + 2]]; };
                const ring = [];
                for (let x = 1; x < w - 1; x += 5) { ring.push(at(x, 1)); ring.push(at(x, h - 2)); }
                for (let y = 1; y < h - 1; y += 3) { ring.push(at(1, y)); ring.push(at(w - 2, y)); }
                const m = [0, 1, 2].map(k => ring.reduce((a, p) => a + p[k], 0) / ring.length);
                const spread = Math.sqrt(ring.reduce((a, p) => a + (p[0] - m[0]) ** 2 + (p[1] - m[1]) ** 2 + (p[2] - m[2]) ** 2, 0) / ring.length);
                if (spread > 34) st.classList.add('scene');
                else {
                    if (.299 * m[0] + .587 * m[1] + .114 * m[2] > 226) st.classList.add('studio');
                    else { st.classList.add('matte'); st.style.setProperty('--stage-bg', 'rgb(' + m.map(Math.round).join(',') + ')'); }
                    let x0 = w, y0 = h, x1 = -1, y1 = -1;
                    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) { const p = at(x, y);
                        if (Math.abs(p[0] - m[0]) + Math.abs(p[1] - m[1]) + Math.abs(p[2] - m[2]) > 60) { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; } }
                    const fw = (x1 - x0 + 1) / w, fh = (y1 - y0 + 1) / h;
                    if (x1 > 0 && fw > .08 && fh > .08 && !(fw > .97 && fh > .97)) {
                        const sw_ = st.clientWidth - pad.x * 2, sh = st.clientHeight - pad.t - pad.b, nw = img.naturalWidth, nh = img.naturalHeight;
                        const cw_ = (x1 + 1 - x0) / w * nw, ch = (y1 + 1 - y0) / h * nh;
                        // never zoom past 1.5x a plain fit (a white car on white can hide its own edges)
                        const k = Math.min(sw_ / cw_, sh / ch, 1.5 * Math.min(st.clientWidth / nw, st.clientHeight / nh));
                        Object.assign(img.style, { position: 'absolute', inset: 'auto', maxWidth: 'none', padding: '0', objectFit: 'fill', width: (nw * k) + 'px', height: (nh * k) + 'px',
                            left: (pad.x + (sw_ - cw_ * k) / 2 - x0 / w * nw * k) + 'px', top: (pad.t + (sh - ch * k) - y0 / h * nh * k) + 'px' });
                    }
                }
            } catch (e) {}
            st.classList.add('ready');
        }
        if (img.complete && img.naturalWidth) go(); else img.addEventListener('load', go, { once: true });
    }
    new MutationObserver(() => { if (stageImg.getAttribute('src')) stage(stageImg, stageEl, { x: 22, t: 18, b: 14 }); })
        .observe(stageImg, { attributes: true, attributeFilter: ['src'] });
    addEventListener('resize', () => { if (stageEl.classList.contains('on')) stage(stageImg, stageEl, { x: 22, t: 18, b: 14 }); });

    /* ═══ live chassis check ═══ */
    let seq = 0, tmr = null, pending = null;
    function check(v) {
        const my = ++seq;
        pending = fetch('add_vehicle.php?ajax=chassis&q=' + encodeURIComponent(v), { credentials: 'same-origin' })
            .then(r => r.json()).then(j => {
                if (my !== seq || f.chassis.value.trim() !== v) return;
                dup = j && j.exists ? j.car : null;
                if (dup) {
                    hint.className = 'chassis-hint dup';
                    hint.innerHTML = esc(T.dup) + ' <span class="ch-car"><i style="background:' + hexOf(dup.color) + '"></i>' +
                        esc([dup.brand, dup.model, dup.car_year, dup.trim_name].join(' ')) + ' · ' + esc(colorLabel(dup.color)) + ' · ' + esc(branchLabel(dup.branch)) + ' · ' + esc(T.st[dup.status] || dup.status) + '</span>';
                } else if (v.length >= 5 && v.length <= 17) {
                    hint.className = 'chassis-hint valid'; hint.textContent = T.fresh;
                }
                refresh();
            }).catch(() => {}).finally(() => { if (my === seq) pending = null; });
        return pending;
    }
    f.chassis.addEventListener('input', () => {
        const v = f.chassis.value.trim(); dup = null; clearTimeout(tmr); seq++;
        if (v.length >= 4) {
            if (v.length >= 5 && v.length <= 17) { hint.className = 'chassis-hint checking'; hint.innerHTML = '<span class="av-spin"></span> ' + esc(T.checking); }
            tmr = setTimeout(() => check(v), 320);
        }
        refresh();
    });

    /* ═══ price + stock for exactly this car ═══ */
    const ctx = $('avCtx');
    function context() {
        const b = f.brand.value, m = f.model.value, tr = f.trim_name.value, y = f.car_year.value;
        if (!b || !m || !tr || !y) { ctx.innerHTML = ''; return; }
        const k = key(b, m, tr, y);
        let h = '';
        if (CAN_PRICE) {
            const p = PRICES[k];
            if (p && p.off) {
                h += '<div class="av-box av-price"><div class="h">' + esc(T.price) + '</div><div class="v">' + esc(p.off) + '<small>' + esc(CUR) + '</small></div>' +
                     (p.cust ? '<span class="tag ' + dealKind(p.cust) + '">' + esc(p.cust) + '</span>' : '') + '</div>';
            } else {
                h += '<div class="av-box av-price none"><div class="h">' + esc(T.price) + '<a href="prices.php?lang=' + LANG + '&search=' + encodeURIComponent(m) + '">' + esc(T.toPrices) + '</a></div><div class="v">' + esc(T.noPrice) + '</div></div>';
            }
        }
        const rows = STOCK[k] || [];
        const total = rows.reduce((a, r) => a + r[2], 0);
        if (!total) h += '<div class="av-box av-stock zero"><div class="h">' + esc(T.stock) + '</div><div class="big">' + esc(T.none) + '</div></div>';
        else {
            const byB = {}; rows.forEach(([br, c, n]) => { (byB[br] = byB[br] || { n: 0, c: {} }).n += n; byB[br].c[c] = (byB[br].c[c] || 0) + n; });
            const selB = f.branch.value, selC = f.color.value;
            const sameN = selB && selC && byB[selB] ? Object.entries(byB[selB].c).filter(([c]) => norm(c) === norm(selC)).reduce((a, [, n]) => a + n, 0) : 0;
            h += '<div class="av-box av-stock"><div class="h">' + esc(T.stock) + '</div><div class="big"><b>' + total + '</b>' + esc(T.cars) + '</div><div class="rows">' +
                 Object.entries(byB).sort((a, b2) => b2[1].n - a[1].n).map(([br, o]) => '<div class="r' + (br === selB ? ' me' : '') + '"><span class="bn">📍 ' + esc(branchLabel(br)) + ' · ' + o.n + '</span><span class="cs">' +
                 Object.entries(o.c).map(([c, n]) => '<span title="' + esc(colorLabel(c)) + '"><i style="background:' + hexOf(c) + '"></i>' + n + '</span>').join('') + '</span></div>').join('') + '</div>' +
                 (sameN ? '<div class="same">' + esc(T.same(sameN)) + '</div>' : '') + '</div>';
        }
        if (ctx._h !== h) { ctx.innerHTML = h; ctx._h = h; }
    }

    /* ═══ confirm before saving (and a one-time lock) ═══ */
    const ov = $('avOv'), sheet = $('avSheet');
    let locked = false;
    function closeSheet() { if (locked) return; ov.classList.remove('on'); ov.setAttribute('aria-hidden', 'true'); document.body.style.overflow = ''; }
    function openSheet() {
        const v = k => f[k].value.trim();
        const tx = el => { const o = el.options[el.selectedIndex]; return o ? o.text.trim() : ''; };
        const photo = stageEl.classList.contains('on') ? stageImg.getAttribute('src') : '';
        const k = key(v('brand'), v('model'), v('trim_name'), v('car_year')), p = CAN_PRICE ? PRICES[k] : null;
        let h = '<div class="preview-photo av-stage' + (photo ? ' on' : '') + ' hasc" id="avSStage" style="--car:' + hexOf(v('color')) + '">' +
                '<div class="av-floor"></div>' + $('avSil').outerHTML.replace('id="avSil"', '') + (photo ? '<img id="avSImg" src="' + esc(photo) + '" alt="">' : '') +
                '<span class="av-cname"><i></i><b>' + esc(colorLabel(v('color'))) + '</b></span></div>';
        h += '<div class="av-sb"><div class="av-ss">' + esc(T.confirmT) + '</div><div class="av-st">' + esc([tx(f.brand), v('model'), v('car_year')].join(' ')) + '</div>';
        h += '<div class="av-grid">' +
             '<div><small>' + esc(T.trim) + '</small><b>' + esc(v('trim_name')) + '</b></div>' +
             '<div><small>' + esc(T.color) + '</small><b><i style="background:' + hexOf(v('color')) + '"></i>' + esc(tx(f.color)) + '</b></div>' +
             '<div><small>' + esc(T.branch) + '</small><b>📍 ' + esc(tx(f.branch)) + '</b></div>' +
             '<div><small>' + esc(T.price) + '</small><b>' + (p && p.off ? esc(p.off) + ' <span style="font-size:11px;color:#94a3b8">' + esc(CUR) + '</span>' : '—') + '</b></div></div>';
        h += '<div class="av-plate"><small>🔑 ' + esc(T.chassis) + '</small><span>' + esc(v('chassis')) + '</span></div>';
        if (v('notes')) h += '<div class="av-note">📝 ' + esc(v('notes')) + '</div>';
        if (dup) h += '<div class="av-warn">' + esc(T.dupWarn) + '</div>';
        h += '<div class="av-actions"><button type="button" class="av-ok" data-keep="">' + esc(T.ok) + '</button>' +
             '<button type="button" class="av-ok2" data-keep="1">' + esc(T.ok2) + '</button>' +
             '<button type="button" class="av-back">' + esc(T.back) + '</button></div></div>';
        sheet.innerHTML = h;
        sheet.querySelectorAll('[data-keep]').forEach(b => { if (dup) b.disabled = true; b.addEventListener('click', () => go(b)); });
        sheet.querySelector('.av-back').addEventListener('click', closeSheet);
        ov.classList.add('on'); ov.setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden';
        const si = $('avSImg'); if (si) requestAnimationFrame(() => stage(si, $('avSStage'), { x: 30, t: 22, b: 16 }));
        setTimeout(() => { const ok = sheet.querySelector('.av-ok'); if (ok && !ok.disabled) ok.focus(); }, 60);
    }
    function go(btn) {
        if (locked || dup) return;
        locked = true;
        $('keepInput').value = btn.dataset.keep;
        sheet.querySelectorAll('button').forEach(b => b.disabled = true);
        btn.classList.add('busy'); btn.innerHTML = '<span class="av-spin"></span> ' + esc(T.saving);
        submitBtn.disabled = true;
        form.submit();
    }
    ov.addEventListener('click', e => { if (e.target === ov) closeSheet(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && ov.classList.contains('on')) closeSheet(); });
    // runs after the page's own validation; only a valid form reaches the sheet
    form.addEventListener('submit', e => {
        if (e.defaultPrevented) return;
        e.preventDefault();
        if (locked) return;
        const v = f.chassis.value.trim();
        const wait = pending || (v.length >= 4 && !dup ? check(v) : null);
        Promise.resolve(wait).then(openSheet, openSheet);
    });

    /* ═══ fill the form: same car again, or what was typed before an error ═══ */
    const sleep = ms => new Promise(r => setTimeout(r, ms));
    async function setSel(el, val) {
        if (!val) return false;
        for (let i = 0; i < 50; i++) {
            if (!el.disabled && Array.from(el.options).some(o => o.value === val)) { el.value = val; el.dispatchEvent(new Event('change', { bubbles: true })); return true; }
            await sleep(100);
        }
        return false;
    }
    async function fill(d) {
        if (!d) return;
        if (d.car_year) await setSel(f.car_year, String(d.car_year));
        if (d.branch) await setSel(f.branch, d.branch);
        if (d.color) await setSel(f.color, d.color);
        if (await setSel(f.brand, d.brand) && await setSel(f.model, d.model)) await setSel(f.trim_name, d.trim_name);
        if (d.chassis) { f.chassis.value = d.chassis; f.chassis.dispatchEvent(new Event('input', { bubbles: true })); }
        if (d.notes) f.notes.value = d.notes;
        refresh();
    }
    function again() {
        fill({ brand: ADDED.brand, model: ADDED.model, car_year: ADDED.car_year, trim_name: ADDED.trim_name, branch: ADDED.branch }).then(() => {
            const g = f.color.closest('.form-group'); g.scrollIntoView({ behavior: 'smooth', block: 'center' }); toast(T.kept);
        });
    }
    if (PREFILL) fill(PREFILL).then(() => {
        if (KEPT) { toast(T.kept); f.color.closest('.form-group').scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    });

    /* ═══ the success banner: photo, confetti, "add another like this" ═══ */
    if (ADDED && $('addedBanner')) {
        const ab = $('addedBanner'), ph = $('abPhoto');
        const lib = (function () { try { return findImg(ADDED); } catch (e) { return ''; } })();
        ph.style.setProperty('--car', hexOf(ADDED.color));
        ph.innerHTML = lib ? '<img src="' + esc(lib) + '" alt="">' : $('avSil').outerHTML.replace('id="avSil"', '').replace('class="av-body"', 'class="av-body" style="fill:' + hexOf(ADDED.color) + '"');
        const again_ = $('abAgain'); if (again_) again_.addEventListener('click', again);
        if (!(matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches)) {
            const cols = ['#22c55e', '#a855f7', '#3b82f6', '#f59e0b', '#f472b6', hexOf(ADDED.color)];
            for (let i = 0; i < 26; i++) {
                const c = document.createElement('i'); c.className = 'av-confetti';
                c.style.left = (Math.random() * 100) + '%'; c.style.background = cols[i % cols.length]; c.style.animationDelay = (Math.random() * .5) + 's';
                ab.appendChild(c); setTimeout(() => c.remove(), 2600);
            }
        }
    }
    // same lookup order as findCarImage() above, for the car that was just saved
    function findImg(car) {
        const lib = window.__AV_IMAGES || {};
        const p = norm(car.brand) + '|' + norm(car.model) + '|', t = norm(car.trim_name), y = norm(car.car_year), c = norm(car.color);
        const tries = [p + t + '|' + y + '|' + c, p + t + '||' + c, p + '|' + y + '|' + c, p + '||' + c, p + t + '|' + y + '|', p + t + '||', p + '|' + y + '|', p + '||'];
        for (const k of tries) if (lib[k]) return lib[k];
        return '';
    }

    refresh();
})();
</script>

</body>
</html>