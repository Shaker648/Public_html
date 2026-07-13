<?php

require 'auth.php';
require 'config.php';

perm_require('page.transfer_vehicle');

$lang = $_GET['lang'] ?? 'ar';

// Car id passed from the dashboard card (auto-select on load)
$preselectId = (int)($_GET['id'] ?? 0);
$dir  = $lang === 'ar' ? 'rtl' : 'ltr';

/* ─── Translations ─────────────────────────────────────────── */
$t = [
    'ar' => [
        'title'           => 'نقل سيارة',
        'subtitle'        => 'نقل المركبات بين الفروع',
        'vehicle'         => 'السيارة',
        'search_vehicle'  => 'ابحث بالماركة أو الموديل أو الشاسيه…',
        'to_branch'       => 'الفرع المستقبِل',
        'notes'           => 'ملاحظات',
        'transfer'        => 'تأكيد النقل',
        'success'         => 'تم نقل السيارة بنجاح',
        'select_branch'   => 'اختر الفرع',
        'back'            => 'العودة للرئيسية',
        'vehicle_details' => 'تفاصيل السيارة',
        'brand'           => 'الماركة',
        'model'           => 'الموديل',
        'trim'            => 'الفئة',
        'color'           => 'اللون',
        'branch'          => 'الفرع الحالي',
        'chassis'         => 'رقم الشاسيه',
        'no_vehicle'      => 'لم يتم اختيار مركبة',
        'switch_lang'     => 'English',
        'same_branch_err' => 'السيارة موجودة بالفعل في هذا الفرع',
        'results'         => 'النتائج',
        'no_results'      => 'لا توجد مركبات مطابقة',
        'clear'           => 'مسح',
        'available'       => 'متاحة',
    ],
    'en' => [
        'title'           => 'Vehicle Transfer',
        'subtitle'        => 'Move vehicles between branches',
        'vehicle'         => 'Vehicle',
        'search_vehicle'  => 'Search by brand, model or chassis…',
        'to_branch'       => 'Destination Branch',
        'notes'           => 'Notes',
        'transfer'        => 'Confirm Transfer',
        'success'         => 'Vehicle Transferred Successfully',
        'select_branch'   => 'Select Branch',
        'back'            => 'Back to Dashboard',
        'vehicle_details' => 'Vehicle Details',
        'brand'           => 'Brand',
        'model'           => 'Model',
        'trim'            => 'Trim',
        'color'           => 'Color',
        'branch'          => 'Current Branch',
        'chassis'         => 'Chassis No.',
        'no_vehicle'      => 'No vehicle selected',
        'switch_lang'     => 'عربي',
        'same_branch_err' => 'Vehicle is already at this branch',
        'results'         => 'Results',
        'no_results'      => 'No matching vehicles found',
        'clear'           => 'Clear',
        'available'       => 'Available',
    ],
];

/* ─── Data ─────────────────────────────────────────────────── */
$cars = $pdo->query("
    SELECT
        cars.*,
        colors.color_ar,
        colors.color_en,
        branches.name_ar  AS branch_name_ar,
        branches.name_en  AS branch_name_en
    FROM cars
    LEFT JOIN colors   ON cars.color  = colors.color_en
    LEFT JOIN branches ON cars.branch = branches.name
    WHERE cars.status = 'available'
    ORDER BY cars.brand, cars.model
")->fetchAll(PDO::FETCH_ASSOC);

$branches = $pdo->query("
    SELECT * FROM branches ORDER BY name_en
")->fetchAll(PDO::FETCH_ASSOC);

/* ─── POST handler ──────────────────────────────────────────── */
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $car_id    = (int) ($_POST['car_id']    ?? 0);
    $to_branch = trim($_POST['to_branch']   ?? '');
    $notes     = trim($_POST['notes']       ?? '');

    $stmt = $pdo->prepare("SELECT * FROM cars WHERE id = ? LIMIT 1");
    $stmt->execute([$car_id]);
    $car = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($car) {
        $from_branch = $car['branch'];

        if ($from_branch === $to_branch) {
            $error = $t[$lang]['same_branch_err'];
        } else {
            $pdo->prepare("UPDATE cars SET branch = ? WHERE id = ?")
                ->execute([$to_branch, $car_id]);

            $ins = $pdo->prepare("
                INSERT INTO movements
                    (car_id, from_branch, to_branch, moved_by, notes, event_type)
                VALUES (?, ?, ?, ?, ?, 'transfer')
            ");
            $ins->execute([
                $car_id,
                $from_branch,
                $to_branch,
                $_SESSION['username'],
                $notes,
            ]);

            if ($ins->rowCount()) {
                $success = $t[$lang]['success'];
                /* Refresh available cars after transfer */
                $cars = $pdo->query("
                    SELECT
                        cars.*,
                        colors.color_ar,
                        colors.color_en,
                        branches.name_ar  AS branch_name_ar,
                        branches.name_en  AS branch_name_en
                    FROM cars
                    LEFT JOIN colors   ON cars.color  = colors.color_en
                    LEFT JOIN branches ON cars.branch = branches.name
                    WHERE cars.status = 'available'
                    ORDER BY cars.brand, cars.model
                ")->fetchAll(PDO::FETCH_ASSOC);
            } else {
                die("Movement insert failed");
            }
        }
    }
}

/* ─── Build JSON payload for JS ────────────────────────────── */
$cars_json = json_encode(array_map(fn($c) => [
    'id'      => $c['id'],
    'brand'   => $c['brand'],
    'model'   => $c['model'],
    'trim'    => $c['trim_name'],
    'color'   => $lang === 'ar' ? ($c['color_ar'] ?: $c['color']) : ($c['color_en'] ?: $c['color']),
    'branch'  => $lang === 'ar' ? ($c['branch_name_ar'] ?: $c['branch']) : ($c['branch_name_en'] ?: $c['branch']),
    'chassis' => $c['chassis'],
], $cars), JSON_UNESCAPED_UNICODE);

?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $t[$lang]['title'] ?></title>
<style>
/* ── Reset & tokens ─────────────────────────────────────── */
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

:root {
    --bg-deep:   #020617;
    --bg-card:   rgba(10,18,40,.92);
    --bg-input:  #0d1526;
    --bg-row:    rgba(255,255,255,.03);
    --bg-row-h:  rgba(99,102,241,.12);

    --purple:    #7c3aed;
    --purple-lt: #a855f7;
    --green:     #22c55e;
    --green-lt:  #86efac;
    --red:       #ef4444;

    --text:      #e2e8f0;
    --muted:     #64748b;
    --border:    rgba(255,255,255,.07);

    --radius-lg: 24px;
    --radius-md: 14px;
    --radius-sm: 9px;

    --font: 'Segoe UI', Tahoma, Arial, sans-serif;
    --transition: .22s cubic-bezier(.4,0,.2,1);
}

html, body { height:100%; }

body {
    font-family: var(--font);
    background: var(--bg-deep);
    background-image:
        radial-gradient(ellipse 80% 50% at 50% -10%, rgba(124,58,237,.22), transparent),
        radial-gradient(ellipse 60% 40% at 80% 100%, rgba(34,197,94,.1), transparent);
    min-height:100vh;
    color: var(--text);
    padding: 24px 16px;
    font-size: 15px;
    line-height: 1.6;
}

/* ── Layout shell ───────────────────────────────────────── */
.shell {
    max-width: 860px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    gap: 20px;
}

/* ── Top bar ────────────────────────────────────────────── */
.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.logo-area { display:flex; align-items:center; gap:12px; }

.icon-wrap {
    width:48px; height:48px;
    background: linear-gradient(135deg,var(--purple),var(--green));
    border-radius: var(--radius-md);
    display: grid; place-items: center;
    font-size: 22px;
    flex-shrink: 0;
    box-shadow: 0 0 22px rgba(124,58,237,.45);
}

.page-title  { font-size:clamp(20px,4vw,28px); font-weight:800; letter-spacing:-.5px; }
.page-sub    { font-size:13px; color:var(--muted); }

.topbar-actions { display:flex; gap:10px; align-items:center; }

.btn-ghost {
    padding: 8px 18px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
    background: transparent;
    color: var(--muted);
    font-size:13px; font-weight:600;
    cursor:pointer;
    text-decoration:none;
    transition: var(--transition);
    display:inline-flex; align-items:center; gap:6px;
}
.btn-ghost:hover { border-color:var(--purple-lt); color:var(--purple-lt); }

/* ── Card ───────────────────────────────────────────────── */
.card {
    background: var(--bg-card);
    backdrop-filter: blur(28px);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 28px;
    box-shadow: 0 24px 60px rgba(0,0,0,.4);
}

/* ── Section label ──────────────────────────────────────── */
.section-label {
    font-size:11px;
    font-weight:700;
    letter-spacing:.12em;
    text-transform:uppercase;
    color:var(--purple-lt);
    margin-bottom:12px;
    display:flex;
    align-items:center;
    gap:8px;
}
.section-label::after {
    content:'';
    flex:1;
    height:1px;
    background:var(--border);
}

/* ── Alert banners ──────────────────────────────────────── */
.alert {
    padding:14px 18px;
    border-radius:var(--radius-md);
    font-weight:600;
    font-size:14px;
    display:flex; align-items:center; gap:10px;
    animation: fadeSlide .35s ease;
}
.alert-success {
    background:rgba(34,197,94,.1);
    border:1px solid rgba(34,197,94,.28);
    color:var(--green-lt);
}
.alert-error {
    background:rgba(239,68,68,.1);
    border:1px solid rgba(239,68,68,.28);
    color:#fca5a5;
}
@keyframes fadeSlide {
    from { opacity:0; transform:translateY(-8px); }
    to   { opacity:1; transform:translateY(0); }
}

/* ── Search box ─────────────────────────────────────────── */
.search-wrap {
    position:relative;
    margin-bottom:10px;
}
.search-icon {
    position:absolute;
    top:50%; transform:translateY(-50%);
    font-size:16px;
    color:var(--muted);
    pointer-events:none;
}
[dir=ltr] .search-icon { left:16px; }
[dir=rtl] .search-icon { right:16px; }

#vehicleSearch {
    width:100%;
    height:52px;
    background:var(--bg-input);
    border:1px solid var(--border);
    border-radius:var(--radius-md);
    color:var(--text);
    font-size:15px;
    outline:none;
    transition: var(--transition);
}
[dir=ltr] #vehicleSearch { padding:0 42px 0 48px; }
[dir=rtl] #vehicleSearch { padding:0 48px 0 42px; }

#vehicleSearch:focus { border-color:var(--purple); box-shadow:0 0 0 3px rgba(124,58,237,.2); }
#vehicleSearch::placeholder { color:var(--muted); }

.clear-btn {
    position:absolute;
    top:50%; transform:translateY(-50%);
    background:none; border:none;
    color:var(--muted); cursor:pointer;
    font-size:18px; line-height:1;
    display:none;
    transition:color var(--transition);
}
[dir=ltr] .clear-btn { right:14px; }
[dir=rtl] .clear-btn { left:14px; }
.clear-btn:hover { color:var(--text); }

/* ── Vehicle list dropdown ──────────────────────────────── */
.list-header {
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:8px 4px;
    font-size:12px;
    color:var(--muted);
    font-weight:600;
    letter-spacing:.05em;
}

.vehicle-list {
    border:1px solid var(--border);
    border-radius:var(--radius-md);
    overflow:hidden;
    max-height:260px;
    overflow-y:auto;
    scrollbar-width:thin;
    scrollbar-color: var(--purple) transparent;
}

.vehicle-list::-webkit-scrollbar       { width:5px; }
.vehicle-list::-webkit-scrollbar-thumb { background:var(--purple); border-radius:4px; }

.v-row {
    display:flex;
    align-items:center;
    gap:14px;
    padding:13px 16px;
    border-bottom:1px solid var(--border);
    cursor:pointer;
    background:var(--bg-row);
    transition: background var(--transition);
    position:relative;
}
.v-row:last-child { border-bottom:none; }
.v-row:hover      { background:var(--bg-row-h); }
.v-row.selected   {
    background: rgba(124,58,237,.18);
    border-color:rgba(124,58,237,.35);
}
.v-row.selected::before {
    content:'';
    position:absolute;
    inset-block:0;
    width:3px;
    background:var(--purple-lt);
    border-radius:4px;
}
[dir=ltr] .v-row.selected::before { left:0; }
[dir=rtl] .v-row.selected::before { right:0; }

.v-icon {
    width:38px; height:38px;
    border-radius:10px;
    background:linear-gradient(135deg,rgba(124,58,237,.3),rgba(34,197,94,.15));
    display:grid; place-items:center;
    font-size:18px;
    flex-shrink:0;
}

.v-main   { flex:1; min-width:0; }
.v-name   { font-weight:700; font-size:14px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.v-meta   { font-size:12px; color:var(--muted); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.v-badge  {
    font-size:11px; font-weight:700; padding:3px 9px;
    border-radius:20px;
    background:rgba(34,197,94,.14);
    color:var(--green);
    border:1px solid rgba(34,197,94,.25);
    white-space:nowrap;
    flex-shrink:0;
}

.empty-state {
    padding:36px 20px;
    text-align:center;
    color:var(--muted);
    font-size:14px;
}
.empty-state .es-icon { font-size:36px; margin-bottom:8px; }

/* Hidden real select (kept for form POST) */
#carIdInput { display:none; }

/* ── Vehicle preview card ───────────────────────────────── */
.preview-card {
    border:1px solid rgba(124,58,237,.35);
    border-radius:var(--radius-md);
    padding:18px 20px;
    background:rgba(124,58,237,.06);
    margin-top:18px;
    display:none;
    animation:fadeSlide .3s ease;
}

.preview-header {
    display:flex;
    align-items:center;
    gap:10px;
    margin-bottom:14px;
}
.preview-header .ph-icon {
    width:40px; height:40px;
    background:linear-gradient(135deg,var(--purple),var(--green));
    border-radius:10px;
    display:grid; place-items:center;
    font-size:20px;
}
.preview-header h3 { font-size:15px; font-weight:800; }
.preview-header p  { font-size:12px; color:var(--muted); }

.detail-grid {
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(160px,1fr));
    gap:10px;
}

.detail-item {
    background:var(--bg-input);
    border-radius:var(--radius-sm);
    padding:11px 13px;
}
.detail-item .di-label { font-size:11px; color:var(--muted); font-weight:600; letter-spacing:.05em; text-transform:uppercase; margin-bottom:4px; }
.detail-item .di-value { font-size:14px; font-weight:700; color:var(--text); }

/* ── Form fields ────────────────────────────────────────── */
.form-group { margin-bottom:18px; }

label.field-label {
    display:block;
    font-size:13px;
    font-weight:700;
    color:var(--muted);
    text-transform:uppercase;
    letter-spacing:.07em;
    margin-bottom:8px;
}

select, textarea {
    width:100%;
    background:var(--bg-input);
    border:1px solid var(--border);
    border-radius:var(--radius-md);
    color:var(--text);
    font-size:15px;
    font-family:var(--font);
    outline:none;
    transition: var(--transition);
}
select   { height:52px; padding:0 16px; }
textarea { padding:14px 16px; min-height:100px; resize:vertical; }

select:focus, textarea:focus {
    border-color:var(--purple);
    box-shadow:0 0 0 3px rgba(124,58,237,.18);
}
select option { background:#0d1526; }

/* ── Transfer button ────────────────────────────────────── */
.btn-transfer {
    width:100%;
    height:58px;
    border:none;
    border-radius:var(--radius-md);
    font-size:17px;
    font-weight:800;
    color:#fff;
    cursor:pointer;
    background:linear-gradient(100deg,#22c55e 0%,#7c3aed 60%,#a855f7 100%);
    background-size:200% 100%;
    background-position:right;
    transition:background-position .45s ease, transform var(--transition), box-shadow var(--transition);
    letter-spacing:.03em;
    position:relative;
    overflow:hidden;
    display:flex; align-items:center; justify-content:center; gap:10px;
}
.btn-transfer:hover {
    background-position:left;
    transform:translateY(-2px);
    box-shadow:0 10px 35px rgba(124,58,237,.45);
}
.btn-transfer:active { transform:translateY(0); }

/* ── Two-column on desktop ──────────────────────────────── */
.two-col { display:grid; grid-template-columns:1fr 1fr; gap:18px; }

/* ── Footer link ────────────────────────────────────────── */
.footer-link {
    display:flex; align-items:center; justify-content:center;
    gap:6px; margin-top:18px;
    color:var(--muted); font-size:13px; font-weight:600;
    text-decoration:none;
    transition:color var(--transition);
}
.footer-link:hover { color:var(--text); }

/* ── Divider ────────────────────────────────────────────── */
.divider { height:1px; background:var(--border); margin:6px 0 22px; }

/* ── Mobile ─────────────────────────────────────────────── */
@media(max-width:600px) {
    body    { padding:16px 12px; }
    .card   { padding:20px 16px; }
    .two-col { grid-template-columns:1fr; gap:14px; }
    .topbar { gap:8px; }
    .topbar-actions { gap:6px; }
    .page-title { font-size:20px; }
}
</style>
</head>
<body>
<div class="shell">

    <!-- ── Top bar ─────────────────────────────────────────── -->
    <div class="topbar">
        <div class="logo-area">
            <div class="icon-wrap">🔄</div>
            <div>
                <div class="page-title"><?= $t[$lang]['title'] ?></div>
                <div class="page-sub"><?= $t[$lang]['subtitle'] ?></div>
            </div>
        </div>
        <div class="topbar-actions">
            <a href="transfer.php?lang=<?= $lang === 'ar' ? 'en' : 'ar' ?>" class="btn-ghost">
                🌐 <?= $t[$lang]['switch_lang'] ?>
            </a>
            <a href="dashboard.php?lang=<?= $lang ?>" class="btn-ghost">
                ← <?= $t[$lang]['back'] ?>
            </a>
        </div>
    </div>

    <!-- ── Main card ───────────────────────────────────────── -->
    <div class="card">

        <?php if ($success): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" id="transferForm">
            <!-- Hidden input carries the chosen car id -->
            <input type="hidden" name="car_id" id="carIdInput">

            <!-- ── Vehicle Search ──────────────────────────── -->
            <div class="section-label">🚗 <?= $t[$lang]['vehicle'] ?></div>

            <div class="search-wrap">
                <span class="search-icon">🔍</span>
                <input
                    type="text"
                    id="vehicleSearch"
                    autocomplete="off"
                    placeholder="<?= $t[$lang]['search_vehicle'] ?>"
                >
                <button type="button" class="clear-btn" id="clearSearch" title="<?= $t[$lang]['clear'] ?>">✕</button>
            </div>

            <div class="list-header">
                <span id="listCount"></span>
            </div>

            <div class="vehicle-list" id="vehicleList"></div>

            <!-- ── Vehicle Preview ─────────────────────────── -->
            <div class="preview-card" id="vehiclePreview">
                <div class="preview-header">
                    <div class="ph-icon">🚘</div>
                    <div>
                        <h3 id="pvTitle">—</h3>
                        <p><?= $t[$lang]['vehicle_details'] ?></p>
                    </div>
                </div>
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="di-label"><?= $t[$lang]['brand'] ?></div>
                        <div class="di-value" id="pvBrand">—</div>
                    </div>
                    <div class="detail-item">
                        <div class="di-label"><?= $t[$lang]['model'] ?></div>
                        <div class="di-value" id="pvModel">—</div>
                    </div>
                    <div class="detail-item">
                        <div class="di-label"><?= $t[$lang]['trim'] ?></div>
                        <div class="di-value" id="pvTrim">—</div>
                    </div>
                    <div class="detail-item">
                        <div class="di-label"><?= $t[$lang]['color'] ?></div>
                        <div class="di-value" id="pvColor">—</div>
                    </div>
                    <div class="detail-item">
                        <div class="di-label"><?= $t[$lang]['branch'] ?></div>
                        <div class="di-value" id="pvBranch">—</div>
                    </div>
                    <div class="detail-item">
                        <div class="di-label"><?= $t[$lang]['chassis'] ?></div>
                        <div class="di-value" id="pvChassis">—</div>
                    </div>
                </div>
            </div>

            <div class="divider" style="margin-top:22px;"></div>

            <!-- ── Transfer Details ───────────────────────── -->
            <div class="section-label">📍 <?= $t[$lang]['to_branch'] ?></div>

            <div class="two-col">
                <div class="form-group" style="margin-bottom:0">
                    <label class="field-label"><?= $t[$lang]['to_branch'] ?></label>
                    <select name="to_branch" required>
                        <option value=""><?= $t[$lang]['select_branch'] ?></option>
                        <?php foreach ($branches as $b): ?>
                        <option value="<?= htmlspecialchars($b['name']) ?>">
                            <?= htmlspecialchars($lang === 'ar' ? $b['name_ar'] : $b['name_en']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom:0">
                    <label class="field-label"><?= $t[$lang]['notes'] ?></label>
                    <textarea name="notes" placeholder="…" style="min-height:52px;height:52px;"></textarea>
                </div>
            </div>

            <div style="margin-top:22px;">
                <button type="submit" class="btn-transfer" id="submitBtn" disabled>
                    <span>🔄</span>
                    <span><?= $t[$lang]['transfer'] ?></span>
                </button>
            </div>

        </form>

        <a href="dashboard.php?lang=<?= $lang ?>" class="footer-link">
            <?= $dir === 'rtl' ? '→' : '←' ?>  <?= $t[$lang]['back'] ?>
        </a>

    </div>
</div>

<script>
/* ── Data injected from PHP ─────────────────────────────── */
const CARS = <?= $cars_json ?>;
const LABELS = {
    brand:    "<?= $t[$lang]['brand'] ?>",
    chassis:  "<?= $t[$lang]['chassis'] ?>",
    available:"<?= $t[$lang]['available'] ?>",
    noRes:    "<?= $t[$lang]['no_results'] ?>",
    results:  "<?= $t[$lang]['results'] ?>",
};

/* ── DOM refs ───────────────────────────────────────────── */
const searchInput  = document.getElementById('vehicleSearch');
const clearBtn     = document.getElementById('clearSearch');
const listEl       = document.getElementById('vehicleList');
const listCount    = document.getElementById('listCount');
const carIdInput   = document.getElementById('carIdInput');
const previewCard  = document.getElementById('vehiclePreview');
const submitBtn    = document.getElementById('submitBtn');
const pvTitle      = document.getElementById('pvTitle');
const pvBrand      = document.getElementById('pvBrand');
const pvModel      = document.getElementById('pvModel');
const pvTrim       = document.getElementById('pvTrim');
const pvColor      = document.getElementById('pvColor');
const pvBranch     = document.getElementById('pvBranch');
const pvChassis    = document.getElementById('pvChassis');

let selectedId = null;

/* ── Render list ────────────────────────────────────────── */
function renderList(query) {
    const q = (query || '').trim().toLowerCase();
    const filtered = q
        ? CARS.filter(c =>
            c.brand.toLowerCase().includes(q) ||
            c.model.toLowerCase().includes(q) ||
            c.chassis.toLowerCase().includes(q) ||
            (c.trim  && c.trim.toLowerCase().includes(q))
          )
        : CARS;

    listCount.textContent = filtered.length
        ? `${filtered.length} ${LABELS.results}`
        : '';

    if (!filtered.length) {
        listEl.innerHTML = `
            <div class="empty-state">
                <div class="es-icon">🔍</div>
                <div>${LABELS.noRes}</div>
            </div>`;
        return;
    }

    listEl.innerHTML = filtered.map(c => `
        <div
            class="v-row ${c.id == selectedId ? 'selected' : ''}"
            data-id="${c.id}"
            role="option"
            tabindex="0"
        >
            <div class="v-icon">🚗</div>
            <div class="v-main">
                <div class="v-name">${escHtml(c.brand)} ${escHtml(c.model)}</div>
                <div class="v-meta">${escHtml(c.chassis)} · ${escHtml(c.branch)}</div>
            </div>
            <div class="v-badge">${LABELS.available}</div>
        </div>
    `).join('');

    listEl.querySelectorAll('.v-row').forEach(row => {
        row.addEventListener('click', () => selectCar(parseInt(row.dataset.id)));
        row.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') selectCar(parseInt(row.dataset.id));
        });
    });
}

function selectCar(id) {
    const car = CARS.find(c => c.id === id);
    if (!car) return;

    selectedId        = id;
    carIdInput.value  = id;
    submitBtn.disabled = false;

    /* Update preview */
    pvTitle.textContent   = `${car.brand} ${car.model}`;
    pvBrand.textContent   = car.brand;
    pvModel.textContent   = car.model;
    pvTrim.textContent    = car.trim  || '—';
    pvColor.textContent   = car.color || '—';
    pvBranch.textContent  = car.branch;
    pvChassis.textContent = car.chassis;

    previewCard.style.display = 'block';

    /* Re-render to mark selected */
    renderList(searchInput.value);
}

function escHtml(str) {
    return String(str ?? '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── Events ─────────────────────────────────────────────── */
searchInput.addEventListener('input', () => {
    clearBtn.style.display = searchInput.value ? 'block' : 'none';
    renderList(searchInput.value);
});

clearBtn.addEventListener('click', () => {
    searchInput.value = '';
    clearBtn.style.display = 'none';
    renderList('');
    searchInput.focus();
});

/* ── Form guard: require a vehicle selected ─────────────── */
document.getElementById('transferForm').addEventListener('submit', function(e) {
    if (!carIdInput.value) {
        e.preventDefault();
        searchInput.focus();
        searchInput.style.borderColor = 'var(--red)';
        setTimeout(() => searchInput.style.borderColor = '', 1800);
    }
});

/* ── Init ───────────────────────────────────────────────── */
renderList('');

// Auto-select the car coming from the dashboard (?id=)
const preselectId = <?= (int)$preselectId ?>;
if (preselectId > 0 && CARS.some(c => c.id === preselectId)) {
    selectCar(preselectId);
    previewCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
</script>
</body>
</html>