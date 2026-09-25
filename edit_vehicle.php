<?php

require 'auth.php';
require 'config.php';
require_once __DIR__ . '/push_helpers.php';
require_once __DIR__ . '/notify_smart.php';
require_once 'car_images_helpers.php';
require_once 'car_edits_helpers.php';

perm_require('page.edit_vehicle');

$lang = $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar', 'en'])) {
    $lang = 'ar';
}

$id = (int)($_GET['id'] ?? 0);

/* A friendly page instead of a bare error */
function ev_not_found(string $lang): void
{
    http_response_code(404);
    $nf = $lang === 'ar'
        ? ['السيارة غير موجودة', 'ربما حُذفت أو أن الرابط غير صحيح.', 'العودة للرئيسية', 'rtl']
        : ['Vehicle not found', 'It may have been deleted, or the link is wrong.', 'Back to Dashboard', 'ltr'];
    echo '<!DOCTYPE html><html lang="' . $lang . '" dir="' . $nf[3] . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . $nf[0] . '</title>'
       . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#020617;color:#e2e8f0;font-family:"Segoe UI",Tahoma,Arial,sans-serif;padding:20px}'
       . '.b{text-align:center;max-width:380px;background:rgba(10,18,40,.93);border:1px solid rgba(255,255,255,.07);border-radius:28px;padding:36px 28px}'
       . '.i{font-size:48px;margin-bottom:10px}h1{font-size:22px;margin:0 0 8px}p{color:#64748b;margin:0 0 22px;font-size:14px}'
       . 'a{display:inline-block;padding:11px 22px;border-radius:12px;background:#7c3aed;color:#fff;text-decoration:none;font-weight:700}</style></head>'
       . '<body><div class="b"><div class="i">🔍</div><h1>' . $nf[0] . '</h1><p>' . $nf[1] . '</p><a href="dashboard.php?lang=' . $lang . '">' . $nf[2] . '</a></div></body></html>';
    exit;
}
if ($id <= 0) ev_not_found($lang);

/* Form token (same session key the other pages use) */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken    = $_SESSION['csrf_token'];
$canFixChassis = (($_SESSION['role'] ?? '') === 'admin');

/* ─── Admin: is this chassis already used by another car? (asked while typing) ─── */
if (($_GET['ajax'] ?? '') === 'chassis') {
    header('Content-Type: application/json; charset=utf-8');
    $q = strtoupper(trim((string)($_GET['q'] ?? '')));
    if (!$canFixChassis || strlen($q) < 4) { echo json_encode(['exists' => false]); exit; }
    $st = $pdo->prepare("SELECT id, brand, model, car_year, status FROM cars WHERE UPPER(chassis) = UPPER(?) AND id <> ? LIMIT 1");
    $st->execute([$q, $id]);
    $hit = $st->fetch(PDO::FETCH_ASSOC);
    echo json_encode($hit ? ['exists' => true, 'car' => $hit] : ['exists' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

$t = [
    'ar' => [
        'title'         => 'تعديل السيارة',
        'subtitle'      => 'تحديث بيانات السيارة',
        'brand'         => 'الماركة',
        'model'         => 'الموديل',
        'year'          => 'سنة الصنع',
        'trim'          => 'الفئة',
        'color'         => 'اللون',
        'branch'        => 'الفرع',
        'chassis'       => 'رقم الشاسيه',
        'notes'         => 'ملاحظات',
        'notes_ph'      => 'أضف أي ملاحظات عن السيارة (اختياري)',
        'save'          => 'حفظ التعديلات',
        'preview'       => 'معاينة السيارة',
        'dashboard'     => 'الرئيسية',
        'inventory'     => 'المخزون',
        'select_brand'  => 'اختر الماركة',
        'select_model'  => 'اختر الموديل',
        'select_trim'   => 'اختر الفئة',
        'select_color'  => 'اختر اللون',
        'select_branch' => 'اختر الفرع',
        'success'       => 'تم تحديث السيارة بنجاح',
        'error'         => 'يرجى استكمال جميع البيانات',
        'created_by'    => 'أضيف بواسطة',
        'status'        => 'الحالة',
        'st_available'  => 'متاحة',
        'st_sold'       => 'مباعة',
        'loading'       => 'جاري التحميل...',
        'locked'        => 'رقم الشاسيه لا يمكن تعديله',
        'st_reserved'   => 'محجوزة',
        'st_consignment'=> 'أمانة',
        'token_expired' => 'انتهت صلاحية الصفحة — حدّث الصفحة وحاول مرة أخرى',
        'err_value'     => 'قيمة غير صحيحة في: %s',
        'err_chassis_dup'=> 'رقم الشاسيه هذا مسجّل لسيارة أخرى',
        'err_db'        => 'حدث خطأ أثناء الحفظ — لم يتم حفظ أي تعديل، حاول مرة أخرى',
        'no_change'     => 'لم يتغير شيء',
        'edit_transfer' => '✏️ تعديل الفرع من صفحة التعديل',
    ],
    'en' => [
        'title'         => 'Edit Vehicle',
        'subtitle'      => 'Update Vehicle Information',
        'brand'         => 'Brand',
        'model'         => 'Model',
        'year'          => 'Year',
        'trim'          => 'Trim',
        'color'         => 'Color',
        'branch'        => 'Branch',
        'chassis'       => 'Chassis',
        'notes'         => 'Notes',
        'notes_ph'      => 'Add any notes about this vehicle (optional)',
        'save'          => 'Save Changes',
        'preview'       => 'Vehicle Preview',
        'dashboard'     => 'Dashboard',
        'inventory'     => 'Inventory',
        'select_brand'  => 'Select Brand',
        'select_model'  => 'Select Model',
        'select_trim'   => 'Select Trim',
        'select_color'  => 'Select Color',
        'select_branch' => 'Select Branch',
        'success'       => 'Vehicle Updated Successfully',
        'error'         => 'Please fill all required fields',
        'created_by'    => 'Created By',
        'status'        => 'Status',
        'st_available'  => 'Available',
        'st_sold'       => 'Sold',
        'loading'       => 'Loading...',
        'locked'        => 'Chassis number cannot be changed',
        'st_reserved'   => 'Reserved',
        'st_consignment'=> 'Consignment',
        'token_expired' => 'This page expired — refresh it and try again',
        'err_value'     => 'Invalid value in: %s',
        'err_chassis_dup'=> 'This chassis number belongs to another vehicle',
        'err_db'        => 'Something went wrong while saving — nothing was changed, please try again',
        'no_change'     => 'Nothing changed',
        'edit_transfer' => '✏️ Branch changed from the edit page',
    ],
];

/* ─── Load the car ─── */
$stmt = $pdo->prepare("SELECT * FROM cars WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$car = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$car) ev_not_found($lang);

/* ─── Reference data ─── */
$brands = $pdo->query("SELECT * FROM brands ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$colors = $pdo->query("SELECT * FROM colors ORDER BY color_en")->fetchAll(PDO::FETCH_ASSOC);
$branches = $pdo->query("SELECT * FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$success = '';
$error   = '';

/* ─── Handle save ─── */
$fieldLabel = fn($f) => ['brand' => $t[$lang]['brand'], 'model' => $t[$lang]['model'], 'car_year' => $t[$lang]['year'], 'trim_name' => $t[$lang]['trim'],
                         'color' => $t[$lang]['color'], 'branch' => $t[$lang]['branch'], 'notes' => $t[$lang]['notes'], 'chassis' => $t[$lang]['chassis']][$f] ?? $f;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $new = [
        'brand'     => trim($_POST['brand'] ?? ''),
        'model'     => trim($_POST['model'] ?? ''),
        'car_year'  => trim($_POST['car_year'] ?? ''),
        'trim_name' => trim($_POST['trim_name'] ?? ''),
        'color'     => trim($_POST['color'] ?? ''),
        'branch'    => trim($_POST['branch'] ?? ''),
        'notes'     => trim($_POST['notes'] ?? ''),
    ];
    $chassisNew = $canFixChassis ? strtoupper(trim((string)($_POST['chassis_new'] ?? ''))) : '';

    /* only real values from the lists (the car's own current value is always allowed) */
    $okBrand  = in_array($new['brand'], array_column($brands, 'name'), true) || $new['brand'] === $car['brand'];
    $okColor  = in_array($new['color'], array_column($colors, 'color_en'), true) || $new['color'] === $car['color'];
    $okBranch = in_array($new['branch'], array_column($branches, 'name'), true);
    $mq = $pdo->prepare("SELECT COUNT(*) FROM models WHERE brand = ? AND model_name = ?");
    $mq->execute([$new['brand'], $new['model']]);
    $okModel  = $mq->fetchColumn() > 0 || ($new['brand'] === $car['brand'] && $new['model'] === $car['model']);
    $tq = $pdo->prepare("SELECT COUNT(*) FROM models WHERE brand = ? AND model_name = ? AND trim_name = ?");
    $tq->execute([$new['brand'], $new['model'], $new['trim_name']]);
    $okTrim   = $tq->fetchColumn() > 0 || ($new['brand'] === $car['brand'] && $new['model'] === $car['model'] && $new['trim_name'] === $car['trim_name']);
    $okYear   = preg_match('/^(19|20)\d{2}$/', $new['car_year']) === 1;
    $bad = [];
    if (!$okBrand)  $bad[] = $fieldLabel('brand');
    if (!$okModel)  $bad[] = $fieldLabel('model');
    if (!$okYear)   $bad[] = $fieldLabel('car_year');
    if (!$okTrim)   $bad[] = $fieldLabel('trim_name');
    if (!$okColor)  $bad[] = $fieldLabel('color');
    if (!$okBranch) $bad[] = $fieldLabel('branch');

    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $error = $t[$lang]['token_expired'];
    } elseif ($new['brand'] === '' || $new['model'] === '' || $new['car_year'] === '' ||
              $new['trim_name'] === '' || $new['color'] === '' || $new['branch'] === '') {
        $error = $t[$lang]['error'];
    } elseif ($bad) {
        $error = sprintf($t[$lang]['err_value'], implode('، ', $bad));
    } else {
        if ($chassisNew !== '' && $chassisNew !== strtoupper((string)$car['chassis'])) {
            $dq = $pdo->prepare("SELECT id FROM cars WHERE UPPER(chassis) = ? AND id <> ? LIMIT 1");
            $dq->execute([$chassisNew, $id]);
            if ($dq->fetch()) $error = $t[$lang]['err_chassis_dup'];
            else $new['chassis'] = $chassisNew;
        }

        // what actually changed
        $changes = [];
        foreach ($new as $f => $v) {
            if ((string)($car[$f] ?? '') !== (string)$v) $changes[$f] = [(string)($car[$f] ?? ''), (string)$v];
        }

        if (!$error && !$changes) {
            $error = $t[$lang]['no_change'];
        } elseif (!$error) {
            try {
                // create the log table first: a CREATE TABLE inside the transaction would end it early
                car_edits_ensure($pdo);
                $pdo->beginTransaction();
                $sets = implode(', ', array_map(fn($f) => "$f = ?", array_keys($changes)));
                $pdo->prepare("UPDATE cars SET $sets WHERE id = ? LIMIT 1")
                    ->execute(array_merge(array_column($changes, 1), [$id]));

                // who changed what (kept forever)
                car_edits_log($pdo, $id, $changes, (string)$_SESSION['username']);

                // a new branch is a real move: record it on the journey like a transfer
                $transferMid = 0;
                if (isset($changes['branch']) && in_array($car['status'], ['available', 'reserved'], true)) {
                    $pdo->prepare("INSERT INTO movements (car_id, from_branch, to_branch, moved_by, notes, event_type)
                                   VALUES (?, ?, ?, ?, ?, 'transfer')")
                        ->execute([$id, $changes['branch'][0], $changes['branch'][1], $_SESSION['username'], $t['ar']['edit_transfer']]);
                    $transferMid = (int)$pdo->lastInsertId();
                }
                $pdo->commit();

                $carNow = $car;
                foreach ($changes as $f => [$o, $n]) $carNow[$f] = $n;
                notify_event($pdo, 'car_edited', ['car' => $carNow, 'changes' => $changes]);
                smart_car_edit($pdo, $car, $changes);   // chassis / sold car → admin
                if ($transferMid) smart_transfer($pdo, [['id' => $id, 'mid' => $transferMid, 'from' => $changes['branch'][0]] + $carNow], $changes['branch'][1]);

                /* Show the result on a fresh GET, so a refresh never re-sends the form */
                $_SESSION['ev_done'] = ['id' => $id, 'changes' => $changes];
                header('Location: edit_vehicle.php?id=' . $id . '&lang=' . $lang);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('edit_vehicle: save failed: ' . $e->getMessage());
                $error = $t[$lang]['err_db'];
            }
        }
    }
}

/* ─── After the redirect: what was just saved ─── */
$evDone = null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_SESSION['ev_done']) && (int)$_SESSION['ev_done']['id'] === $id) {
    $evDone  = $_SESSION['ev_done'];
    unset($_SESSION['ev_done']);
    $success = $t[$lang]['success'];
}

/* ─── On an error, the form shows what was typed, not the saved values ─── */
$form = $car;
if ($error && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($new)) {
    foreach ($new as $f => $v) if ($f !== 'chassis') $form[$f] = $v;
}

/* ─── Pre-load models + trims (fixes the "only one option" bug) ─── */
$modelStmt = $pdo->prepare("SELECT DISTINCT model_name FROM models WHERE brand = ? ORDER BY model_name");
$modelStmt->execute([$form['brand']]);
$carModels = $modelStmt->fetchAll(PDO::FETCH_COLUMN);

$trimStmt = $pdo->prepare("SELECT DISTINCT trim_name FROM models WHERE brand = ? AND model_name = ? ORDER BY trim_name");
$trimStmt->execute([$form['brand'], $form['model']]);
$carTrims = $trimStmt->fetchAll(PDO::FETCH_COLUMN);

if ($form['model'] !== '' && !in_array($form['model'], $carModels, true)) {
    array_unshift($carModels, $form['model']);
}
if ($form['trim_name'] !== '' && !in_array($form['trim_name'], $carTrims, true)) {
    array_unshift($carTrims, $form['trim_name']);
}

$isSold = ($car['status'] === 'sold');
$statusView = [
    'available'   => ['status-available', '🟢 ' . $t[$lang]['st_available']],
    'reserved'    => ['status-reserved',  '🟡 ' . $t[$lang]['st_reserved']],
    'consignment' => ['status-amana',     '🔶 ' . $t[$lang]['st_consignment']],
    'sold'        => ['status-sold',      '🔴 ' . $t[$lang]['st_sold']],
][$car['status']] ?? ['status-available', '🟢 ' . $t[$lang]['st_available']];

/* Photo library (same lookup as add_vehicle), prices, edit history, quick links */
$evImgs = [];
try { foreach (car_images_map($pdo) as $k => $row) { $u = car_image_url($row, true); if ($u !== '') $evImgs[$k] = $u; } } catch (Throwable $e) {}
$evPrices = [];
if (can('page.prices')) {
    try {
        foreach ($pdo->query("SELECT brand, model_name, trim_name, car_year, official_price FROM pricing") as $r) {
            if ($r['official_price'] === null || $r['official_price'] === '') continue;
            $evPrices[mb_strtolower(implode('|', [$r['brand'], $r['model_name'], $r['trim_name'], $r['car_year']]))] = number_format((float)$r['official_price']);
        }
    } catch (Throwable $e) {}
}
$evHistory = car_edits_for($pdo, $id, 30);
$evOnSale  = in_array($car['status'], ['available', 'reserved'], true);
$evLinks = [
    'timeline' => can('page.vehicle_timeline'),
    'sell'     => $evOnSale && can('dash.btn_sell') && can('page.sold_vehicle'),
    'transfer' => $evOnSale && can('dash.btn_transfer') && can('page.transfer_vehicle'),
    'qr'       => can('qr.manage'),
];
function ev_swatch(string $colorEn): string
{
    static $map = [
        'white' => '#f8fafc', 'pearl white' => '#f1f5f9', 'black' => '#111827', 'silver' => '#cbd5e1',
        'grey' => '#6b7280', 'gray' => '#6b7280', 'red' => '#dc2626', 'blue' => '#2563eb', 'navy' => '#1e3a8a',
        'green' => '#16a34a', 'gold' => '#d4af37', 'beige' => '#e0d5c0', 'brown' => '#78350f',
        'orange' => '#ea580c', 'yellow' => '#eab308', 'purple' => '#7c3aed', 'bronze' => '#a97142', 'champagne' => '#e6d7b8',
    ];
    return $map[mb_strtolower(trim($colorEn))] ?? '#64748b';
}
/* how a saved value reads (colour / branch names in the page language) */
$evShow = function (string $field, string $v) use ($colors, $branches, $lang): string {
    if ($v === '') return '—';
    if ($field === 'color')  foreach ($colors as $c)   if ($c['color_en'] === $v) return $lang === 'ar' ? $c['color_ar'] : $c['color_en'];
    if ($field === 'branch') foreach ($branches as $b) if ($b['name'] === $v)     return $lang === 'ar' ? $b['name_ar'] : $b['name_en'];
    return $v;
};

// localized color/branch names for initial preview
$carColorDisp = $car['color'];
foreach ($colors as $c) {
    if ($c['color_en'] == $car['color']) {
        $carColorDisp = $lang == 'ar' ? $c['color_ar'] : $c['color_en'];
        break;
    }
}
$carBranchDisp = $car['branch'];
foreach ($branches as $b) {
    if ($b['name'] == $car['branch']) {
        $carBranchDisp = $lang == 'ar' ? $b['name_ar'] : $b['name_en'];
        break;
    }
}

?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $lang == 'ar' ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#0f172a">
<title><?= $t[$lang]['title'] ?></title>

<style>
* { margin:0; padding:0; box-sizing:border-box; font-family: 'Segoe UI', Tahoma, sans-serif; }

body {
    background: linear-gradient(135deg, #020617, #0f172a);
    color: white;
    min-height: 100vh;
    padding-bottom: 100px;
}

.container { max-width: 1500px; margin: auto; padding: 20px; }

/* ── Header ── */
.header {
    background: rgba(15,23,42,.90);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 30px;
    padding: 25px;
    margin-bottom: 25px;
    backdrop-filter: blur(20px);
    box-shadow: 0 20px 50px rgba(0,0,0,.35);
}
.header-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}
.page-title { font-size: 34px; font-weight: 800; color: #22c55e; }
.page-subtitle { margin-top: 8px; font-size: 14px; color: #94a3b8; }

.header-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.action-btn {
    text-decoration: none;
    padding: 12px 18px;
    border-radius: 14px;
    font-weight: 700;
    color: white;
    transition: .3s;
    display: flex;
    align-items: center;
    gap: 6px;
}
.action-btn:hover { transform: translateY(-2px); }
.dashboard-btn { background: #2563eb; }
.inventory-btn { background: #22c55e; }

.lang-switch { display: flex; gap: 10px; margin-top: 20px; }
.lang-btn {
    text-decoration: none;
    padding: 10px 16px;
    border-radius: 12px;
    background: #111827;
    color: white;
    font-weight: 700;
}
.lang-active { background: #9333ea !important; }

/* ── Alerts ── */
.alert {
    padding: 15px;
    border-radius: 16px;
    margin-bottom: 20px;
    font-weight: 700;
}
.error   { background: rgba(239,68,68,.15); border: 1px solid rgba(239,68,68,.3); color: #ef4444; }
.success { background: rgba(34,197,94,.15); border: 1px solid rgba(34,197,94,.3); color: #22c55e; }

/* ── Layout ── */
.main-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }

.form-card, .preview-card {
    background: rgba(15,23,42,.90);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 30px;
    padding: 25px;
    backdrop-filter: blur(20px);
}

.section-title { font-size: 24px; font-weight: 800; margin-bottom: 20px; }

.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
.form-group { margin-bottom: 18px; }

label { display: block; margin-bottom: 8px; font-weight: 700; }

input, select, textarea {
    width: 100%;
    background: #111827;
    border: none;
    outline: none;
    color: white;
    padding: 15px;
    border-radius: 16px;
    font-size: 15px;
    font-family: inherit;
}
input, select { height: 58px; }
textarea { height: 120px; resize: none; }

input:focus, select:focus, textarea:focus { box-shadow: 0 0 0 2px #9333ea; }

.readonly { background: #1f2937 !important; cursor: not-allowed; opacity: .75; }
.field-hint { font-size: 11px; color: #64748b; margin-top: 6px; }

.save-btn {
    width: 100%;
    height: 62px;
    border: none;
    cursor: pointer;
    border-radius: 18px;
    font-size: 18px;
    font-weight: 800;
    color: white;
    background: linear-gradient(90deg, #22c55e, #9333ea);
    margin-top: 10px;
    transition: .3s;
    font-family: inherit;
}
.save-btn:hover { transform: translateY(-2px); }

/* ── Preview ── */
.preview-vehicle { font-size: 24px; font-weight: 800; margin-bottom: 20px; color: #22c55e; min-height: 28px; }

.vehicle-status {
    margin-bottom: 20px;
    padding: 10px 14px;
    border-radius: 12px;
    font-weight: 700;
    text-align: center;
}
.status-available { background: rgba(34,197,94,.15); border: 1px solid rgba(34,197,94,.3); color: #22c55e; }
.status-sold      { background: rgba(239,68,68,.15); border: 1px solid rgba(239,68,68,.3); color: #ef4444; }

.preview-item { padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,.05); }
.preview-item:last-child { border-bottom: none; }
.preview-label { font-size: 14px; color: #94a3b8; margin-bottom: 5px; }
.preview-value { font-weight: 700; }
.preview-value.chassis { font-family: 'Courier New', monospace; color: #a3e635; letter-spacing: .5px; }
.preview-value.note    { color: #fcd34d; }

.color-line { display: flex; align-items: center; gap: 9px; }
.swatch { width: 15px; height: 15px; border-radius: 50%; border: 1px solid rgba(255,255,255,.35); flex-shrink: 0; }

@media (max-width: 1000px) {
    .main-grid { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
    .form-grid { grid-template-columns: 1fr; }
    .page-title { font-size: 28px; }
}
</style>
</head>
<body>

<div class="container">

    <!-- ── Header ── -->
    <div class="header">
        <div class="header-top">
            <div>
                <div class="page-title">✏ <?= $t[$lang]['title'] ?></div>
                <div class="page-subtitle"><?= $t[$lang]['subtitle'] ?></div>
            </div>
            <div class="header-actions">
                <a href="dashboard.php?lang=<?= $lang ?>" class="action-btn dashboard-btn">🏠 <?= $t[$lang]['dashboard'] ?></a>
                <a href="stock_report.php?lang=<?= $lang ?>" class="action-btn inventory-btn">🚗 <?= $t[$lang]['inventory'] ?></a>
            </div>
        </div>
        <div class="lang-switch">
            <a href="?id=<?= $id ?>&lang=ar" class="lang-btn <?= $lang == 'ar' ? 'lang-active' : '' ?>">🇪🇬 العربية</a>
            <a href="?id=<?= $id ?>&lang=en" class="lang-btn <?= $lang == 'en' ? 'lang-active' : '' ?>">🇺🇸 English</a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert error">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success && $evDone): ?>
        <div class="ev-done">
            <div class="ev-done-t">✅ <?= htmlspecialchars($success) ?></div>
            <div class="ev-chg">
                <?php foreach ($evDone['changes'] as $f => [$o, $n]): ?>
                <div><span class="k"><?= htmlspecialchars($fieldLabel($f)) ?></span><span class="o"><?= htmlspecialchars($evShow($f, $o)) ?></span><i><?= $lang === 'ar' ? '←' : '→' ?></i><span class="n"><?= htmlspecialchars($evShow($f, $n)) ?></span></div>
                <?php endforeach; ?>
            </div>
            <?php if (isset($evDone['changes']['branch']) && $evOnSale): ?>
            <div class="ev-done-note">🔄 <?= $lang === 'ar' ? 'تم تسجيل تغيير الفرع كنقل في رحلة السيارة' : 'The branch change was recorded as a transfer on the journey' ?></div>
            <?php endif; ?>
        </div>
    <?php elseif ($success): ?>
        <div class="alert success">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($isSold || $car['status'] === 'consignment'): ?>
        <div class="ev-warn"><?= $isSold
            ? ($lang === 'ar' ? '🔴 هذه السيارة مباعة — أي تعديل هنا يغيّر بياناتها في سجل المبيعات أيضاً.' : '🔴 This vehicle is sold — any change here also changes it in the sales records.')
            : ($lang === 'ar' ? '🔶 هذه السيارة خارج أمانة الآن — تأكد قبل التعديل.' : '🔶 This vehicle is out on consignment — double-check before editing.') ?></div>
    <?php endif; ?>
    <?php if (array_filter($evLinks)): ?>
        <div class="ev-links">
            <?php if ($evLinks['timeline']): ?><a href="vehicle_timeline.php?id=<?= $id ?>&lang=<?= $lang ?>">🗺 <?= $lang === 'ar' ? 'رحلة السيارة' : 'Journey' ?></a><?php endif; ?>
            <?php if ($evLinks['sell']): ?><a class="sell" href="sold_vehicle.php?id=<?= $id ?>&lang=<?= $lang ?>">💰 <?= $lang === 'ar' ? 'بيع' : 'Sell' ?></a><?php endif; ?>
            <?php if ($evLinks['transfer']): ?><a href="transfer_vehicle.php?id=<?= $id ?>&lang=<?= $lang ?>">🔄 <?= $lang === 'ar' ? 'نقل' : 'Transfer' ?></a><?php endif; ?>
            <?php if ($evLinks['qr']): ?><a href="qr.php?id=<?= $id ?>&lang=<?= $lang ?>">▦ QR</a><?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="main-grid">

        <!-- ── Form ── -->
        <div class="form-card">
            <div class="section-title">🚗 <?= $t[$lang]['title'] ?></div>

            <form method="POST" id="vehicleForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <div class="form-grid">
                    <div class="form-group">
                        <label><?= $t[$lang]['brand'] ?></label>
                        <select id="brand" name="brand" required>
                            <option value=""><?= $t[$lang]['select_brand'] ?></option>
                            <?php foreach ($brands as $brand): ?>
                                <option value="<?= htmlspecialchars($brand['name']) ?>" <?= $form['brand']==$brand['name']?'selected':'' ?>>
                                    <?= htmlspecialchars($brand['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label><?= $t[$lang]['model'] ?></label>
                        <select id="model" name="model" required>
                            <option value=""><?= $t[$lang]['select_model'] ?></option>
                            <?php foreach ($carModels as $m): ?>
                                <option value="<?= htmlspecialchars($m) ?>" <?= $form['model']==$m?'selected':'' ?>>
                                    <?= htmlspecialchars($m) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label><?= $t[$lang]['year'] ?></label>
                        <select id="car_year" name="car_year" required>
                            <?php
                            $currentYear = (int)date('Y');
                            $years = range($currentYear + 5, $currentYear - 1);
                            $savedYear = $form['car_year'];
                            if ($savedYear !== '' && !in_array((int)$savedYear, $years, true) && ctype_digit((string)$savedYear)) {
                                array_unshift($years, (int)$savedYear);
                            }
                            foreach ($years as $year):
                            ?>
                                <option value="<?= $year ?>" <?= (string)$form['car_year']===(string)$year?'selected':'' ?>><?= $year ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label><?= $t[$lang]['trim'] ?></label>
                        <select id="trim" name="trim_name" required>
                            <option value=""><?= $t[$lang]['select_trim'] ?></option>
                            <?php foreach ($carTrims as $tr): ?>
                                <option value="<?= htmlspecialchars($tr) ?>" <?= $form['trim_name']==$tr?'selected':'' ?>>
                                    <?= htmlspecialchars($tr) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label><?= $t[$lang]['color'] ?></label>
                        <select id="color" name="color" required>
                            <option value=""><?= $t[$lang]['select_color'] ?></option>
                            <?php foreach ($colors as $color): ?>
                                <option value="<?= htmlspecialchars($color['color_en']) ?>"
                                    data-css="<?= htmlspecialchars(strtolower(str_replace(' ', '', $color['color_en']))) ?>"
                                    <?= $form['color']==$color['color_en']?'selected':'' ?>>
                                    <?= $lang=='ar'?htmlspecialchars($color['color_ar']):htmlspecialchars($color['color_en']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label><?= $t[$lang]['branch'] ?></label>
                        <select id="branch" name="branch" required>
                            <option value=""><?= $t[$lang]['select_branch'] ?></option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?= htmlspecialchars($branch['name']) ?>" <?= $form['branch']==$branch['name']?'selected':'' ?>>
                                    <?= $lang=='ar'?htmlspecialchars($branch['name_ar']):htmlspecialchars($branch['name_en']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label><?= $t[$lang]['chassis'] ?></label>
                    <div class="ev-chassis-row">
                        <input type="text" id="chassisView" value="<?= htmlspecialchars($car['chassis']) ?>" readonly class="readonly">
                        <?php if ($canFixChassis): ?>
                        <button type="button" class="ev-fix" id="chassisFix">🔓 <?= $lang === 'ar' ? 'تصحيح' : 'Fix' ?></button>
                        <?php endif; ?>
                    </div>
                    <?php if ($canFixChassis): ?><input type="hidden" name="chassis_new" id="chassisNew" value=""><?php endif; ?>
                    <div class="field-hint" id="chassisHint">🔒 <?= $canFixChassis ? ($lang === 'ar' ? 'مقفول — المدير فقط يمكنه تصحيحه إذا كان مكتوباً خطأ' : 'Locked — only an admin can correct a typo') : $t[$lang]['locked'] ?></div>
                </div>

                <div class="form-group">
                    <label><?= $t[$lang]['notes'] ?></label>
                    <textarea id="notes" name="notes" placeholder="<?= $t[$lang]['notes_ph'] ?>"><?= htmlspecialchars($form['notes'] ?? '') ?></textarea>
                </div>

                <button type="submit" class="save-btn" id="saveBtn">💾 <span><?= $t[$lang]['save'] ?></span></button>
            </form>
        </div>

        <!-- ── Preview ── -->
        <div class="preview-card">
            <div class="section-title">👁 <?= $t[$lang]['preview'] ?></div>

            <div class="ev-stage" id="evStage"></div>
            <div class="ev-price" id="evPrice"></div>
            <div class="preview-vehicle" id="previewVehicle">
                <?= htmlspecialchars($car['brand']) ?> <?= htmlspecialchars($car['model']) ?>
            </div>

            <div class="vehicle-status <?= $statusView[0] ?>">
                <?= $statusView[1] ?>
            </div>

            <div class="preview-item">
                <div class="preview-label"><?= $t[$lang]['brand'] ?></div>
                <div class="preview-value" id="previewBrand"><?= htmlspecialchars($car['brand']) ?></div>
            </div>
            <div class="preview-item">
                <div class="preview-label"><?= $t[$lang]['model'] ?></div>
                <div class="preview-value" id="previewModel"><?= htmlspecialchars($car['model']) ?></div>
            </div>
            <div class="preview-item">
                <div class="preview-label"><?= $t[$lang]['year'] ?></div>
                <div class="preview-value" id="previewYear"><?= htmlspecialchars($car['car_year']) ?></div>
            </div>
            <div class="preview-item">
                <div class="preview-label"><?= $t[$lang]['trim'] ?></div>
                <div class="preview-value" id="previewTrim"><?= htmlspecialchars($car['trim_name']) ?></div>
            </div>
            <div class="preview-item">
                <div class="preview-label"><?= $t[$lang]['color'] ?></div>
                <div class="preview-value color-line">
                    <span class="swatch" id="previewSwatch" style="background:<?= htmlspecialchars(strtolower(str_replace(' ', '', $car['color']))) ?>"></span>
                    <span id="previewColor"><?= htmlspecialchars($carColorDisp) ?></span>
                </div>
            </div>
            <div class="preview-item">
                <div class="preview-label"><?= $t[$lang]['branch'] ?></div>
                <div class="preview-value" id="previewBranch"><?= htmlspecialchars($carBranchDisp) ?></div>
            </div>
            <div class="preview-item">
                <div class="preview-label"><?= $t[$lang]['chassis'] ?></div>
                <div class="preview-value chassis"><?= htmlspecialchars($car['chassis']) ?></div>
            </div>
            <div class="preview-item">
                <div class="preview-label"><?= $t[$lang]['created_by'] ?></div>
                <div class="preview-value"><?= htmlspecialchars($car['created_by']) ?></div>
            </div>
            <div class="preview-item">
                <div class="preview-label"><?= $t[$lang]['notes'] ?></div>
                <div class="preview-value note" id="previewNotes"><?= trim((string)($car['notes']??''))!==''?htmlspecialchars($car['notes']):'—' ?></div>
            </div>
        </div>

    </div>

    <!-- ── Edit history ── -->
    <div class="form-card ev-hist">
        <div class="section-title">🕘 <?= $lang === 'ar' ? 'سجل التعديلات' : 'Edit history' ?> <span class="ev-hist-n"><?= count($evHistory) ?></span></div>
        <?php if (!$evHistory): ?>
        <div class="ev-hist-empty"><?= $lang === 'ar' ? 'لم يتم تعديل هذه السيارة بعد — أي تعديل سيظهر هنا بمن عدّله ومتى' : 'No edits yet — every change will show here with who made it and when' ?></div>
        <?php else: ?>
        <div class="ev-hist-list">
            <?php foreach ($evHistory as $h): ?>
            <div class="ev-h">
                <div class="ev-h-top"><b>✏️ <?= htmlspecialchars($h['by']) ?></b><span><?= date('d M Y · h:i A', strtotime($h['at'])) ?></span></div>
                <div class="ev-chg">
                    <?php foreach ($h['fields'] as $f => [$o, $n]): ?>
                    <div><span class="k"><?= htmlspecialchars($fieldLabel($f)) ?></span><span class="o"><?= htmlspecialchars($evShow($f, $o)) ?></span><i><?= $lang === 'ar' ? '←' : '→' ?></i><span class="n"><?= htmlspecialchars($evShow($f, $n)) ?></span></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<div class="ev-ov" id="evOv" aria-hidden="true"><div class="ev-sheet" id="evSheet" role="dialog" aria-modal="true"></div></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const labels = {
        selectModel: <?= json_encode($t[$lang]['select_model']) ?>,
        selectTrim:  <?= json_encode($t[$lang]['select_trim']) ?>,
        loading:     <?= json_encode($t[$lang]['loading']) ?>,
    };

    const brand=document.getElementById('brand'), model=document.getElementById('model'),
          trim=document.getElementById('trim'), color=document.getElementById('color'),
          branch=document.getElementById('branch'), year=document.getElementById('car_year'),
          notes=document.getElementById('notes');

    const pV=document.getElementById('previewVehicle'), pB=document.getElementById('previewBrand'),
          pM=document.getElementById('previewModel'), pY=document.getElementById('previewYear'),
          pT=document.getElementById('previewTrim'), pC=document.getElementById('previewColor'),
          pBr=document.getElementById('previewBranch'), pN=document.getElementById('previewNotes'),
          pSw=document.getElementById('previewSwatch');

    const txt = s => s.options[s.selectedIndex]?.text.trim() || '—';

    function refresh() {
        const b = brand.value ? txt(brand) : '—';
        const m = model.value ? txt(model) : '—';
        pB.textContent  = b;
        pM.textContent  = m;
        pT.textContent  = trim.value ? txt(trim) : '—';
        pY.textContent  = year.value || '—';
        pC.textContent  = color.value ? txt(color) : '—';
        pBr.textContent = branch.value ? txt(branch) : '—';
        pN.textContent  = notes.value.trim() || '—';
        pV.textContent  = ((brand.value?b:'') + ' ' + (model.value?m:'')).trim() || '—';
        const css = color.options[color.selectedIndex]?.dataset.css;
        if (css) pSw.style.background = css;
    }

    function fill(sel, items, placeholder) {
        sel.innerHTML = '<option value="">' + placeholder + '</option>';
        items.forEach(it => {
            const o = document.createElement('option');
            o.value = it; o.textContent = it;
            sel.appendChild(o);
        });
    }

    brand.addEventListener('change', function () {
        model.innerHTML = '<option value="">' + labels.loading + '</option>';
        trim.innerHTML  = '<option value="">' + labels.selectTrim + '</option>';
        refresh();
        fetch('get_models.php?brand=' + encodeURIComponent(this.value))
            .then(r => r.json())
            .then(d => { fill(model, d, labels.selectModel); refresh(); })
            .catch(() => fill(model, [], labels.selectModel));
    });

    model.addEventListener('change', function () {
        trim.innerHTML = '<option value="">' + labels.loading + '</option>';
        refresh();
        fetch('get_trims.php?brand=' + encodeURIComponent(brand.value) + '&model=' + encodeURIComponent(this.value))
            .then(r => r.json())
            .then(d => { fill(trim, d, labels.selectTrim); refresh(); })
            .catch(() => fill(trim, [], labels.selectTrim));
    });

    [trim, color, branch, year].forEach(el => el.addEventListener('change', refresh));
    notes.addEventListener('input', refresh);
    refresh();
});
</script>

<style>
/* ═══════════ Edit extras (same fields, same preview) ═══════════ */
.status-reserved { background: rgba(250,204,21,.14); border: 1px solid rgba(250,204,21,.35); color: #facc15; }
.status-amana    { background: rgba(245,158,11,.14); border: 1px solid rgba(245,158,11,.35); color: #f59e0b; }
.ev-warn { margin-bottom: 16px; padding: 12px 16px; border-radius: 14px; font-size: 13px; font-weight: 700; background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.3); color: #fecaca; }
.ev-links { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
.ev-links a { height: 38px; padding: 0 15px; border-radius: 12px; display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 800; text-decoration: none; color: #e2e8f0; background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08); transition: .2s; }
.ev-links a:hover { border-color: rgba(168,85,247,.45); }
.ev-links a.sell { background: linear-gradient(135deg, #22c55e, #16a34a); border-color: transparent; color: #fff; }

/* changed fields */
.form-group { position: relative; }
.form-group.ev-changed select, .form-group.ev-changed textarea { border-color: rgba(245,158,11,.65) !important; box-shadow: 0 0 0 3px rgba(245,158,11,.12) !important; }
.form-group.ev-changed label::after { content: '●'; color: #f59e0b; margin-inline-start: 6px; font-size: 10px; vertical-align: 2px; }
.ev-was { display: none; align-items: center; gap: 8px; margin-top: 6px; font-size: 12px; font-weight: 700; color: #fcd34d; }
.ev-changed .ev-was { display: flex; }
.ev-was s { color: #94a3b8; text-decoration-color: rgba(148,163,184,.6); }
.ev-undo { border: 1px solid rgba(245,158,11,.35); background: rgba(245,158,11,.08); color: #fcd34d; border-radius: 8px; height: 24px; padding: 0 8px; font: inherit; font-size: 11px; font-weight: 800; cursor: pointer; }
.ev-undo:hover { background: rgba(245,158,11,.18); }
.save-btn:disabled { opacity: .45; cursor: not-allowed; filter: grayscale(.4); transform: none !important; box-shadow: none !important; }
.save-btn .ev-n { display: inline-block; margin-inline-start: 6px; font-size: 12px; background: rgba(255,255,255,.2); border-radius: 999px; padding: 1px 9px; }

/* chassis fix */
.ev-chassis-row { display: flex; gap: 8px; }
.ev-chassis-row input { flex: 1; min-width: 0; font-family: 'SFMono-Regular', Consolas, monospace; letter-spacing: .1em; font-weight: 800; }
.ev-chassis-row input.ev-open { background: #0f172a !important; border-color: rgba(245,158,11,.6) !important; color: #fde68a !important; cursor: text !important; opacity: 1 !important; }
.ev-fix { flex-shrink: 0; border: 1px solid rgba(245,158,11,.35); background: rgba(245,158,11,.08); color: #fcd34d; border-radius: 12px; padding: 0 14px; font: inherit; font-size: 13px; font-weight: 800; cursor: pointer; }
#chassisHint.dup { color: #fca5a5; } #chassisHint.ok { color: #86efac; }

/* preview photo + price */
.ev-stage { position: relative; aspect-ratio: 16/9.4; border-radius: 16px; overflow: hidden; margin-bottom: 14px; --car: #64748b; border: 1px solid rgba(255,255,255,.07);
    background: radial-gradient(ellipse 80% 70% at 50% 15%, color-mix(in srgb, var(--car) 24%, #1a2744), #0a1120 74%); transition: background .4s; }
.ev-stage::after { content: ''; position: absolute; inset-inline: 0; bottom: 0; height: 32%; background: radial-gradient(ellipse 60% 60% at 50% 30%, color-mix(in srgb, var(--car) 30%, transparent), transparent 70%); pointer-events: none; }
.ev-stage img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; padding: 10px 14px 6px; z-index: 1; }
.ev-stage svg { position: absolute; inset-inline: 7%; bottom: 8%; width: 86%; height: auto; z-index: 1; transition: transform .4s; }
.ev-stage svg path.body { transition: fill .45s; }
.ev-stage .tag { position: absolute; top: 8px; inset-inline-start: 8px; z-index: 2; font-size: 10px; font-weight: 800; color: #1c1002; background: #f59e0b; border-radius: 999px; padding: 2px 9px; display: none; }
.ev-stage.chg .tag { display: block; }
.ev-price:empty { display: none; }
.ev-price { margin-bottom: 14px; padding: 10px 12px; border-radius: 14px; background: rgba(34,197,94,.07); border: 1px solid rgba(34,197,94,.22); font-size: 12px; color: #94a3b8; font-weight: 700; }
.ev-price b { font-size: 18px; color: #4ade80; margin-inline-start: 4px; }
.ev-price .was { display: block; margin-top: 3px; font-size: 11px; color: #fcd34d; }
.ev-price.none { background: rgba(245,158,11,.06); border-color: rgba(245,158,11,.25); color: #fbbf24; }
.preview-value .ev-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; border: 1px solid rgba(255,255,255,.35); vertical-align: -1px; margin-inline-end: 6px; }
.preview-item.ev-changed .preview-value { color: #fcd34d; }

/* success + history */
.ev-done { margin-bottom: 16px; padding: 14px 16px; border-radius: 16px; background: linear-gradient(120deg, rgba(34,197,94,.14), rgba(15,23,42,.9) 62%); border: 1px solid rgba(34,197,94,.35); animation: evIn .4s cubic-bezier(.22,1,.36,1) both; }
.ev-done-t { font-size: 16px; font-weight: 800; color: #4ade80; margin-bottom: 8px; }
.ev-done-note { margin-top: 8px; font-size: 12px; color: #c4b5fd; font-weight: 700; }
.ev-chg { display: flex; flex-direction: column; gap: 5px; }
.ev-chg > div { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 13px; }
.ev-chg .k { min-width: 70px; color: #94a3b8; font-weight: 700; font-size: 12px; }
.ev-chg .o { color: #94a3b8; text-decoration: line-through; text-decoration-color: rgba(239,68,68,.6); }
.ev-chg i { font-style: normal; color: #a855f7; font-weight: 900; }
.ev-chg .n { font-weight: 800; color: #e2e8f0; background: rgba(34,197,94,.1); border-radius: 7px; padding: 1px 8px; }
.ev-hist { margin-top: 20px; }
.ev-hist-n { margin-inline-start: auto; font-size: 12px; font-weight: 800; background: rgba(168,85,247,.15); color: #d8b4fe; border-radius: 999px; padding: 2px 10px; }
.ev-hist .section-title { display: flex; align-items: center; gap: 8px; }
.ev-hist-empty { text-align: center; color: #64748b; font-size: 13px; padding: 18px; border: 1px dashed rgba(255,255,255,.08); border-radius: 14px; }
.ev-hist-list { display: flex; flex-direction: column; gap: 10px; }
.ev-h { padding: 12px 14px; border-radius: 14px; background: rgba(255,255,255,.025); border: 1px solid rgba(255,255,255,.06); }
.ev-h-top { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 8px; font-size: 13px; }
.ev-h-top span { color: #64748b; font-size: 12px; font-weight: 600; }

/* confirm sheet */
.ev-ov { position: fixed; inset: 0; z-index: 900; background: rgba(2,6,23,.8); backdrop-filter: blur(7px); display: none; align-items: center; justify-content: center; padding: 18px; }
.ev-ov.on { display: flex; animation: evFade .2s ease both; }
@keyframes evFade { from { opacity: 0; } }
@keyframes evIn { from { transform: translateY(18px); opacity: 0; } }
.ev-sheet { width: 100%; max-width: 460px; max-height: 92vh; overflow-y: auto; border-radius: 26px; background: linear-gradient(170deg, #151b36, #0a1122); border: 1px solid rgba(245,158,11,.3); box-shadow: 0 40px 100px rgba(0,0,0,.6); padding: 22px; animation: evIn .3s cubic-bezier(.22,1,.36,1) both; }
.ev-sheet h3 { font-size: 13px; color: #64748b; font-weight: 700; margin-bottom: 4px; }
.ev-sheet .big { font-size: 20px; font-weight: 800; margin-bottom: 14px; }
.ev-sheet .ev-chg > div { padding: 8px 10px; border-radius: 11px; background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.06); }
.ev-sheet .note { margin-top: 10px; font-size: 12px; color: #c4b5fd; background: rgba(124,58,237,.08); border-radius: 10px; padding: 8px 12px; font-weight: 700; }
.ev-acts { display: flex; flex-direction: column; gap: 8px; margin-top: 16px; }
.ev-acts button { height: 50px; border-radius: 14px; border: 1px solid rgba(255,255,255,.08); font: inherit; font-size: 15px; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; }
.ev-ok { background: linear-gradient(135deg, #f59e0b, #d97706); color: #1c1002; border: 0 !important; box-shadow: 0 8px 26px rgba(245,158,11,.3); }
.ev-back { background: transparent; color: #94a3b8; }
.ev-acts button:disabled { opacity: .5; }
.ev-spin { width: 14px; height: 14px; border-radius: 50%; border: 2px solid rgba(0,0,0,.25); border-top-color: #1c1002; animation: evSpin .7s linear infinite; display: inline-block; }
@keyframes evSpin { to { transform: rotate(360deg); } }

@media (max-width: 900px) {
    .main-grid > .preview-card { order: -1; }
    .preview-card .preview-item { padding: 8px 0; }
}
@media (max-width: 600px) {
    .save-btn { position: sticky; bottom: 12px; z-index: 50; box-shadow: 0 10px 30px rgba(0,0,0,.55); }
    .ev-ov { align-items: flex-end; padding: 0; }
    .ev-sheet { max-width: none; border-radius: 26px 26px 0 0; }
    .ev-chg .k { min-width: 0; width: 100%; }
}
</style>
<script>
(function () {
    'use strict';
    const AR = <?= json_encode($lang === 'ar') ?>, LANG = <?= json_encode($lang) ?>, CAR_ID = <?= (int)$id ?>;
    const ORIG = <?= json_encode(['brand' => (string)$car['brand'], 'model' => (string)$car['model'], 'car_year' => (string)$car['car_year'], 'trim_name' => (string)$car['trim_name'], 'color' => (string)$car['color'], 'branch' => (string)$car['branch'], 'notes' => (string)($car['notes'] ?? '')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const ORIG_SHOW = <?= json_encode(['color' => $evShow('color', (string)$car['color']), 'branch' => $evShow('branch', (string)$car['branch'])], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const IMGS = <?= json_encode((object)$evImgs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const PRICES = <?= json_encode((object)$evPrices, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const CAN_PRICE = <?= can('page.prices') ? 'true' : 'false' ?>, ON_SALE = <?= $evOnSale ? 'true' : 'false' ?>;
    const HEX = <?= json_encode((object)array_combine(array_column($colors, 'color_en'), array_map(fn($c) => ev_swatch((string)$c['color_en']), $colors)) ?: new stdClass(), JSON_UNESCAPED_UNICODE) ?>;
    const ORIG_CHASSIS = <?= json_encode((string)$car['chassis']) ?>;
    const LBL = <?= json_encode(['brand' => $t[$lang]['brand'], 'model' => $t[$lang]['model'], 'car_year' => $t[$lang]['year'], 'trim_name' => $t[$lang]['trim'], 'color' => $t[$lang]['color'], 'branch' => $t[$lang]['branch'], 'notes' => $t[$lang]['notes'], 'chassis' => $t[$lang]['chassis']], JSON_UNESCAPED_UNICODE) ?>;
    const T = AR ? {
        was: 'كان:', undo: '↩️ تراجع', save: 'حفظ التعديلات', none: 'لا توجد تعديلات بعد', review: 'راجع التعديلات قبل الحفظ', n: n => n === 1 ? 'تعديل واحد' : n === 2 ? 'تعديلين' : n + (n <= 10 ? ' تعديلات' : ' تعديل'),
        ok: '✓ حفظ التعديلات', back: '✏️ رجوع', saving: 'جارٍ الحفظ…', branchNote: '🔄 تغيير الفرع سيُسجَّل كنقل في رحلة السيارة', changed: 'معدّلة',
        price: '💰 السعر الرسمي', priceWas: p => 'كان ' + p + ' قبل التعديل', noPrice: '⚠️ هذه الفئة لم تُسعّر بعد لهذه السنة', cur: 'جنيه',
        chkOpen: '✏️ اكتب الرقم الصحيح — سيتم التحقق أنه غير مستخدم', chkDup: c => '⚠️ هذا الرقم مسجّل لسيارة أخرى: ' + c, chkOk: '✓ رقم متاح', chkSame: 'نفس الرقم الحالي', empty: '—'
    } : {
        was: 'was:', undo: '↩️ Undo', save: 'Save Changes', none: 'No changes yet', review: 'Check the changes before saving', n: n => n + (n === 1 ? ' change' : ' changes'),
        ok: '✓ Save changes', back: '✏️ Back', saving: 'Saving…', branchNote: '🔄 The branch change will be recorded as a transfer on the journey', changed: 'edited',
        price: '💰 Official price', priceWas: p => 'was ' + p + ' before the edit', noPrice: '⚠️ This trim has no price for this year yet', cur: 'EGP',
        chkOpen: '✏️ Type the correct number — it will be checked', chkDup: c => '⚠️ This number belongs to another vehicle: ' + c, chkOk: '✓ Number is free', chkSame: 'Same as the current number', empty: '—'
    };
    const $ = id => document.getElementById(id);
    const esc = v => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const norm = v => String(v == null ? '' : v).trim().toLowerCase();
    const F = { brand: $('brand'), model: $('model'), car_year: $('car_year'), trim_name: $('trim'), color: $('color'), branch: $('branch'), notes: $('notes') };
    const form = $('vehicleForm'), saveBtn = $('saveBtn');
    const show = (f, v) => {
        if (v === '' || v == null) return T.empty;
        if (f === 'color' || f === 'branch') {
            const o = Array.from(F[f].options).find(o => o.value === v);
            return o ? o.textContent.trim() : (v === ORIG[f] ? ORIG_SHOW[f] : v);
        }
        return v;
    };

    /* ═══ "was: …" + undo under every field ═══ */
    Object.entries(F).forEach(([f, el]) => {
        const g = el.closest('.form-group'); if (!g) return;
        const w = document.createElement('div'); w.className = 'ev-was';
        w.innerHTML = '<span>' + esc(T.was) + ' <s>' + esc(show(f, ORIG[f]).slice(0, 60)) + '</s></span><button type="button" class="ev-undo">' + esc(T.undo) + '</button>';
        g.appendChild(w);
        w.querySelector('.ev-undo').addEventListener('click', () => undo(f));
    });
    const sleep = ms => new Promise(r => setTimeout(r, ms));
    async function setSel(el, val) {
        for (let i = 0; i < 40; i++) {
            if (Array.from(el.options).some(o => o.value === val)) { el.value = val; el.dispatchEvent(new Event('change', { bubbles: true })); return true; }
            await sleep(100);
        }
        return false;
    }
    async function undo(f) {
        if (f === 'notes') { F.notes.value = ORIG.notes; F.notes.dispatchEvent(new Event('input', { bubbles: true })); return; }
        // brand → model → trim restore together, because each list depends on the one before
        if (f === 'brand' || f === 'model' || f === 'trim_name') {
            if (f === 'brand' && F.brand.value !== ORIG.brand) await setSel(F.brand, ORIG.brand);
            if ((f === 'brand' || f === 'model') && F.model.value !== ORIG.model) await setSel(F.model, ORIG.model);
            await setSel(F.trim_name, ORIG.trim_name);
        } else await setSel(F[f], ORIG[f]);
        update();
    }

    /* ═══ what changed ═══ */
    function diffs() {
        const d = [];
        Object.entries(F).forEach(([f, el]) => { if ((el.value || '').trim() !== (ORIG[f] || '').trim()) d.push([f, ORIG[f], el.value.trim()]); });
        const cn = $('chassisNew');
        if (cn && cn.value && cn.value !== ORIG_CHASSIS.toUpperCase()) d.push(['chassis', ORIG_CHASSIS, cn.value]);
        return d;
    }
    let dupChassis = false;
    function update() {
        const d = diffs(), changed = new Set(d.map(x => x[0]));
        Object.entries(F).forEach(([f, el]) => { const g = el.closest('.form-group'); if (g) g.classList.toggle('ev-changed', changed.has(f)); });
        const map = { brand: 'previewBrand', model: 'previewModel', car_year: 'previewYear', trim_name: 'previewTrim', color: 'previewColor', branch: 'previewBranch', notes: 'previewNotes' };
        Object.entries(map).forEach(([f, id]) => { const e = $(id); if (e) { const it = e.closest('.preview-item'); if (it) it.classList.toggle('ev-changed', changed.has(f)); } });
        const ok = d.length > 0 && !dupChassis;
        saveBtn.disabled = !ok;
        saveBtn.querySelector('span').innerHTML = d.length ? esc(T.save) + '<span class="ev-n">' + esc(T.n(d.length)) + '</span>' : esc(T.none);
        paint(changed);
    }

    /* ═══ photo + colour + price ═══ */
    const stage = $('evStage');
    function findImg(b, m, tr, y, c) {
        b = norm(b); m = norm(m); if (!b || !m) return '';
        const t = norm(tr), yy = norm(y), cc = norm(c), p = b + '|' + m + '|';
        for (const k of [p + t + '|' + yy + '|' + cc, p + t + '||' + cc, p + '|' + yy + '|' + cc, p + '||' + cc, p + t + '|' + yy + '|', p + t + '||', p + '|' + yy + '|', p + '||']) if (IMGS[k]) return IMGS[k];
        return '';
    }
    const SIL = hex => '<svg viewBox="0 0 320 130" aria-hidden="true"><ellipse cx="163" cy="119" rx="142" ry="7" fill="#000" opacity=".5"/>' +
        '<path class="body" fill="' + hex + '" d="M20,92 L22,72 Q24,62 36,60 L80,55 L112,31 Q118,26 128,26 L222,26 Q234,26 242,34 L268,57 L292,61 Q306,64 306,78 L306,92 Q306,98 300,98 L277,98 A27,27 0 0 0 223,98 L105,98 A27,27 0 0 0 51,98 L26,98 Q20,98 20,92 Z"/>' +
        '<path d="M88,57 L116,35 Q120,32 126,32 L166,32 L166,57 Z M174,32 L221,32 Q229,32 235,38 L256,57 L174,57 Z" fill="#0b1220" opacity=".82"/>' +
        '<path d="M292,70 L304,72" stroke="#fde68a" stroke-width="4" stroke-linecap="round"/><path d="M22,74 L30,73" stroke="#f87171" stroke-width="4" stroke-linecap="round"/>' +
        '<circle cx="78" cy="98" r="21" fill="#0b1220" stroke="#1e293b" stroke-width="5"/><circle cx="78" cy="98" r="9" fill="#94a3b8"/>' +
        '<circle cx="250" cy="98" r="21" fill="#0b1220" stroke="#1e293b" stroke-width="5"/><circle cx="250" cy="98" r="9" fill="#94a3b8"/></svg>';
    let lastImg = null;
    function paint(changed) {
        const hex = HEX[F.color.value] || '#64748b';
        stage.style.setProperty('--car', hex);
        const img = findImg(F.brand.value, F.model.value, F.trim_name.value, F.car_year.value, F.color.value);
        if (img !== lastImg || !stage.firstChild) {
            lastImg = img;
            stage.innerHTML = '<span class="tag">' + esc(T.changed) + '</span>' + (img ? '<img src="' + esc(img) + '" alt="">' : SIL(hex));
            const im = stage.querySelector('img'); if (im) im.addEventListener('error', () => { im.outerHTML = SIL(hex); }, { once: true });
        } else { const b = stage.querySelector('path.body'); if (b) b.setAttribute('fill', hex); }
        stage.classList.toggle('chg', ['brand', 'model', 'trim_name', 'car_year', 'color'].some(f => changed.has(f)));
        const pc = $('previewColor'); if (pc && !pc.parentElement.querySelector('.ev-dot')) pc.insertAdjacentHTML('beforebegin', '<i class="ev-dot"></i>');
        const dot = pc && pc.parentElement.querySelector('.ev-dot'); if (dot) dot.style.background = hex;
        const sw = $('previewSwatch'); if (sw) sw.style.display = 'none';
        // price for exactly this model / trim / year
        const pe = $('evPrice');
        if (!CAN_PRICE) { pe.innerHTML = ''; return; }
        const k = [F.brand.value, F.model.value, F.trim_name.value, F.car_year.value].map(norm).join('|');
        const ko = [ORIG.brand, ORIG.model, ORIG.trim_name, ORIG.car_year].map(norm).join('|');
        const p = PRICES[k], po = PRICES[ko];
        if (!F.trim_name.value) { pe.innerHTML = ''; return; }
        pe.className = 'ev-price' + (p ? '' : ' none');
        pe.innerHTML = p ? esc(T.price) + ' <b>' + esc(p) + '</b> ' + esc(T.cur) + (k !== ko && po && po !== p ? '<span class="was">' + esc(T.priceWas(po)) + '</span>' : '') : esc(T.noPrice);
    }

    /* ═══ admin: fix a mistyped chassis ═══ */
    const fix = $('chassisFix'), view = $('chassisView'), cNew = $('chassisNew'), hint = $('chassisHint');
    if (fix && view && cNew) {
        let tm = null, seq = 0;
        fix.addEventListener('click', () => {
            view.readOnly = false; view.classList.add('ev-open'); view.focus(); view.select();
            fix.style.display = 'none'; hint.className = 'field-hint'; hint.textContent = T.chkOpen;
        });
        view.addEventListener('input', () => {
            view.value = view.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
            const v = view.value; cNew.value = v !== ORIG_CHASSIS.toUpperCase() ? v : '';
            dupChassis = false; clearTimeout(tm); const my = ++seq;
            if (!cNew.value) { hint.className = 'field-hint'; hint.textContent = T.chkSame; update(); return; }
            if (v.length >= 4) tm = setTimeout(() => fetch('edit_vehicle.php?ajax=chassis&id=' + CAR_ID + '&q=' + encodeURIComponent(v), { credentials: 'same-origin' })
                .then(r => r.json()).then(j => {
                    if (my !== seq) return;
                    dupChassis = !!(j && j.exists);
                    hint.className = 'field-hint ' + (dupChassis ? 'dup' : 'ok');
                    hint.textContent = dupChassis ? T.chkDup([j.car.brand, j.car.model, j.car.car_year].join(' ')) : T.chkOk;
                    update();
                }).catch(() => {}), 300);
            update();
        });
    }

    /* ═══ confirm the changes, then save once ═══ */
    const ov = $('evOv'), sheet = $('evSheet');
    let locked = false;
    function closeOv() { if (locked) return; ov.classList.remove('on'); document.body.style.overflow = ''; }
    ov.addEventListener('click', e => { if (e.target === ov) closeOv(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && ov.classList.contains('on')) closeOv(); });
    form.addEventListener('submit', e => {
        e.preventDefault();
        if (locked) return;
        const d = diffs(); if (!d.length || dupChassis) return;
        const arrow = AR ? '←' : '→';
        sheet.innerHTML = '<h3>' + esc(T.review) + '</h3><div class="big">' + esc(T.n(d.length)) + '</div><div class="ev-chg">' +
            d.map(([f, o, n]) => '<div><span class="k">' + esc(LBL[f] || f) + '</span><span class="o">' + esc(show(f, o).slice(0, 80)) + '</span><i>' + arrow + '</i><span class="n">' + esc(show(f, n).slice(0, 80)) + '</span></div>').join('') + '</div>' +
            (d.some(x => x[0] === 'branch') && ON_SALE ? '<div class="note">' + esc(T.branchNote) + '</div>' : '') +
            '<div class="ev-acts"><button type="button" class="ev-ok">' + esc(T.ok) + '</button><button type="button" class="ev-back">' + esc(T.back) + '</button></div>';
        sheet.querySelector('.ev-back').addEventListener('click', closeOv);
        sheet.querySelector('.ev-ok').addEventListener('click', function () {
            if (locked) return; locked = true;
            this.disabled = true; sheet.querySelector('.ev-back').disabled = true;
            this.innerHTML = '<span class="ev-spin"></span> ' + esc(T.saving);
            saveBtn.disabled = true;
            HTMLFormElement.prototype.submit.call(form);
        });
        ov.classList.add('on'); document.body.style.overflow = 'hidden';
        setTimeout(() => sheet.querySelector('.ev-ok').focus(), 60);
    });

    Object.values(F).forEach(el => { el.addEventListener('change', update); el.addEventListener('input', update); });
    update();
})();
</script>

</body>
</html>