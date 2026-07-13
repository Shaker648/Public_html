<?php

require 'auth.php';
require 'config.php';

perm_require('page.add_vehicle');

$lang = $_GET['lang'] ?? 'ar';

$error   = '';
$success = '';
$addedVehicle = null;

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

    if (empty($brand) || empty($model) || empty($car_year) ||
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
        }
    }
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
    <div class="added-banner">
        <span class="added-banner-icon">🎉</span>
        <div>
            <div class="added-banner-title"><?= $t[$lang]['success'] ?></div>
            <div class="added-banner-details">
                <?= htmlspecialchars($addedVehicle['brand']) ?> <?= htmlspecialchars($addedVehicle['model']) ?> <?= htmlspecialchars($addedVehicle['car_year']) ?> —
                <?= htmlspecialchars($addedVehicle['trim_name']) ?> /
                <?= htmlspecialchars($addedVehicle['color']) ?> |
                <?= $t[$lang]['chassis'] ?>: <?= htmlspecialchars($addedVehicle['chassis']) ?>
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

        <!-- PREVIEW CARD -->
        <div class="card preview-card">
            <div class="section-title">
                <div class="section-title-icon" style="background:var(--purple-dim);border-color:rgba(168,85,247,0.2)">👁</div>
                <?= $t[$lang]['preview'] ?>
            </div>

            <div class="preview-vehicle-name" id="previewVehicle">—</div>

            <div class="vehicle-badge">
                <span class="badge-dot"></span>
                <?= $t[$lang]['available'] ?>
            </div>

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

        fetch('get_trims.php?model=' + encodeURIComponent(modelVal))
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

</body>
</html>