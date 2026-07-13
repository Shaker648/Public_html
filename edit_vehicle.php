<?php

require 'auth.php';
require 'config.php';

perm_require('page.edit_vehicle');

$lang = $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar', 'en'])) {
    $lang = 'ar';
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    die('Invalid Vehicle');
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
    ],
];

/* ─── Load the car ─── */
$stmt = $pdo->prepare("SELECT * FROM cars WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$car = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$car) {
    die('Vehicle Not Found');
}

/* ─── Reference data ─── */
$brands = $pdo->query("SELECT * FROM brands ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$colors = $pdo->query("SELECT * FROM colors ORDER BY color_en")->fetchAll(PDO::FETCH_ASSOC);
$branches = $pdo->query("SELECT * FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$success = '';
$error   = '';

/* ─── Handle save ─── */
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $brand     = trim($_POST['brand'] ?? '');
    $model     = trim($_POST['model'] ?? '');
    $car_year  = trim($_POST['car_year'] ?? '');
    $trim_name = trim($_POST['trim_name'] ?? '');
    $color     = trim($_POST['color'] ?? '');
    $branch    = trim($_POST['branch'] ?? '');
    $notes     = trim($_POST['notes'] ?? '');

    if (empty($brand) || empty($model) || empty($car_year) ||
        empty($trim_name) || empty($color) || empty($branch)) {
        $error = $t[$lang]['error'];
    } else {
        $update = $pdo->prepare("
            UPDATE cars
            SET brand=?, model=?, car_year=?, trim_name=?, color=?, branch=?, notes=?
            WHERE id=?
            LIMIT 1
        ");
        $update->execute([$brand, $model, $car_year, $trim_name, $color, $branch, $notes, $id]);

        $success = $t[$lang]['success'];

        $stmt = $pdo->prepare("SELECT * FROM cars WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $car = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

/* ─── Pre-load models + trims (fixes the "only one option" bug) ─── */
$modelStmt = $pdo->prepare("SELECT DISTINCT model_name FROM models WHERE brand = ? ORDER BY model_name");
$modelStmt->execute([$car['brand']]);
$carModels = $modelStmt->fetchAll(PDO::FETCH_COLUMN);

$trimStmt = $pdo->prepare("SELECT DISTINCT trim_name FROM models WHERE brand = ? AND model_name = ? ORDER BY trim_name");
$trimStmt->execute([$car['brand'], $car['model']]);
$carTrims = $trimStmt->fetchAll(PDO::FETCH_COLUMN);

if ($car['model'] !== '' && !in_array($car['model'], $carModels, true)) {
    array_unshift($carModels, $car['model']);
}
if ($car['trim_name'] !== '' && !in_array($car['trim_name'], $carTrims, true)) {
    array_unshift($carTrims, $car['trim_name']);
}

$isSold = ($car['status'] === 'sold');

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
    <?php if ($success): ?>
        <div class="alert success">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="main-grid">

        <!-- ── Form ── -->
        <div class="form-card">
            <div class="section-title">🚗 <?= $t[$lang]['title'] ?></div>

            <form method="POST" id="vehicleForm">

                <div class="form-grid">
                    <div class="form-group">
                        <label><?= $t[$lang]['brand'] ?></label>
                        <select id="brand" name="brand" required>
                            <option value=""><?= $t[$lang]['select_brand'] ?></option>
                            <?php foreach ($brands as $brand): ?>
                                <option value="<?= htmlspecialchars($brand['name']) ?>" <?= $car['brand']==$brand['name']?'selected':'' ?>>
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
                                <option value="<?= htmlspecialchars($m) ?>" <?= $car['model']==$m?'selected':'' ?>>
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
                            $savedYear = $car['car_year'];
                            if ($savedYear !== '' && !in_array((int)$savedYear, $years, true) && ctype_digit((string)$savedYear)) {
                                array_unshift($years, (int)$savedYear);
                            }
                            foreach ($years as $year):
                            ?>
                                <option value="<?= $year ?>" <?= (string)$car['car_year']===(string)$year?'selected':'' ?>><?= $year ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label><?= $t[$lang]['trim'] ?></label>
                        <select id="trim" name="trim_name" required>
                            <option value=""><?= $t[$lang]['select_trim'] ?></option>
                            <?php foreach ($carTrims as $tr): ?>
                                <option value="<?= htmlspecialchars($tr) ?>" <?= $car['trim_name']==$tr?'selected':'' ?>>
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
                                    <?= $car['color']==$color['color_en']?'selected':'' ?>>
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
                                <option value="<?= htmlspecialchars($branch['name']) ?>" <?= $car['branch']==$branch['name']?'selected':'' ?>>
                                    <?= $lang=='ar'?htmlspecialchars($branch['name_ar']):htmlspecialchars($branch['name_en']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label><?= $t[$lang]['chassis'] ?></label>
                    <input type="text" value="<?= htmlspecialchars($car['chassis']) ?>" readonly class="readonly">
                    <div class="field-hint">🔒 <?= $t[$lang]['locked'] ?></div>
                </div>

                <div class="form-group">
                    <label><?= $t[$lang]['notes'] ?></label>
                    <textarea id="notes" name="notes" placeholder="<?= $t[$lang]['notes_ph'] ?>"><?= htmlspecialchars($car['notes'] ?? '') ?></textarea>
                </div>

                <button type="submit" class="save-btn">💾 <?= $t[$lang]['save'] ?></button>
            </form>
        </div>

        <!-- ── Preview ── -->
        <div class="preview-card">
            <div class="section-title">👁 <?= $t[$lang]['preview'] ?></div>

            <div class="preview-vehicle" id="previewVehicle">
                <?= htmlspecialchars($car['brand']) ?> <?= htmlspecialchars($car['model']) ?>
            </div>

            <div class="vehicle-status <?= $isSold ? 'status-sold' : 'status-available' ?>">
                <?= $isSold ? '🔴 ' . $t[$lang]['st_sold'] : '🟢 ' . $t[$lang]['st_available'] ?>
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
</div>

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

</body>
</html>