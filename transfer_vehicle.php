<?php

require 'auth.php';
require 'config.php';
require_once __DIR__ . '/push_helpers.php';
require_once __DIR__ . '/notify_smart.php';
require_once 'car_images_helpers.php';

perm_require('page.transfer_vehicle');

$lang = $_GET['lang'] ?? 'ar';
if ($lang !== 'en') $lang = 'ar';

/* Form token (same session key the other pages use) */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

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
        'reserved'        => 'محجوزة',
        'token_expired'   => 'انتهت صلاحية الصفحة — حدّث الصفحة وحاول مرة أخرى',
        'err_pick'        => 'اختر السيارة أولاً',
        'err_branch'      => 'اختر فرعاً صحيحاً',
        'err_status'      => 'لا يمكن نقل هذه السيارة الآن (مباعة أو أمانة أو غير موجودة): %s',
        'err_db'          => 'حدث خطأ أثناء الحفظ — لم يتم نقل أي سيارة، حاول مرة أخرى',
        'success_n'       => 'تم نقل %s بنجاح',
        'skipped'         => 'كانت موجودة بالفعل في هذا الفرع فلم تُنقل: %s',
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
        'reserved'        => 'Reserved',
        'token_expired'   => 'This page expired — refresh it and try again',
        'err_pick'        => 'Pick the vehicle first',
        'err_branch'      => 'Choose a valid branch',
        'err_status'      => 'These vehicles cannot be moved right now (sold, on consignment or missing): %s',
        'err_db'          => 'Something went wrong while saving — nothing was moved, please try again',
        'success_n'       => '%s transferred successfully',
        'skipped'         => 'Already at that branch, so not moved: %s',
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
    WHERE cars.status IN ('available','reserved')
    ORDER BY cars.brand, cars.model
")->fetchAll(PDO::FETCH_ASSOC);

$branches = $pdo->query("
    SELECT * FROM branches ORDER BY name_en
")->fetchAll(PDO::FETCH_ASSOC);

/* ─── POST handler ──────────────────────────────────────────── */
$success = '';
$error   = '';
$branchNames = array_column($branches, 'name');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // one car (car_id) or several at once (car_ids[])
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['car_ids'] ?? [])))));
    if (!$ids && (int)($_POST['car_id'] ?? 0) > 0) $ids = [(int)$_POST['car_id']];
    $ids       = array_slice($ids, 0, 60);
    $to_branch = trim($_POST['to_branch'] ?? '');
    $notes     = trim($_POST['notes']     ?? '');

    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $error = $t[$lang]['token_expired'];
    } elseif (!$ids) {
        $error = $t[$lang]['err_pick'];
    } elseif (!in_array($to_branch, $branchNames, true)) {
        $error = $t[$lang]['err_branch'];
    } else {
        // only cars that are really on sale can move
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $cs   = $pdo->prepare("SELECT * FROM cars WHERE id IN ($in)");
        $cs->execute($ids);
        $rows = [];
        foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[(int)$r['id']] = $r;
        $bad = [];
        foreach ($ids as $cid) {
            if (!isset($rows[$cid]) || !in_array($rows[$cid]['status'], ['available', 'reserved'], true)) {
                $bad[] = isset($rows[$cid]) ? $rows[$cid]['chassis'] : ('#' . $cid);
            }
        }
        $move = array_values(array_filter($rows, fn($r) => $r['branch'] !== $to_branch));

        if ($bad) {
            $error = sprintf($t[$lang]['err_status'], implode('، ', $bad));
        } elseif (!$move) {
            $error = $t[$lang]['same_branch_err'];
        } else {
            try {
                // the branch change and its log line are saved together, or not at all
                $pdo->beginTransaction();
                $up  = $pdo->prepare("UPDATE cars SET branch = ? WHERE id = ?");
                $ins = $pdo->prepare("
                    INSERT INTO movements
                        (car_id, from_branch, to_branch, moved_by, notes, event_type)
                    VALUES (?, ?, ?, ?, ?, 'transfer')
                ");
                $moved = [];
                foreach ($move as $car) {
                    $up->execute([$to_branch, $car['id']]);
                    $ins->execute([$car['id'], $car['branch'], $to_branch, $_SESSION['username'], $notes]);
                    $moved[] = ['id' => (int)$car['id'], 'brand' => $car['brand'], 'model' => $car['model'], 'car_year' => $car['car_year'],
                                'trim_name' => $car['trim_name'], 'color' => $car['color'], 'chassis' => $car['chassis'], 'from' => $car['branch'],
                                'mid' => (int)$pdo->lastInsertId()];
                }
                $pdo->commit();

                if (count($moved) === 1) {
                    notify_event($pdo, 'car_transferred', ['car' => $moved[0] + ['branch' => $to_branch], 'from' => $moved[0]['from'], 'to' => $to_branch]);
                } else {
                    $froms = array_values(array_unique(array_column($moved, 'from')));
                    notify_event($pdo, 'car_transferred', ['count' => count($moved), 'to' => $to_branch, 'from' => count($froms) === 1 ? $froms[0] : '',
                                                           'names' => array_map(fn($c) => $c['brand'] . ' ' . $c['model'] . ' (' . $c['chassis'] . ')', $moved)]);
                }
                smart_transfer($pdo, $moved, $to_branch);   // the receiving branch gets «استلمت»

                /* Show the result on a fresh GET, so a refresh never re-sends the form */
                $skipped = array_values(array_map(fn($r) => $r['chassis'], array_filter($rows, fn($r) => $r['branch'] === $to_branch)));
                $_SESSION['tv_done'] = ['to' => $to_branch, 'cars' => $moved, 'notes' => $notes, 'skipped' => $skipped];
                header('Location: transfer_vehicle.php?lang=' . $lang);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('transfer_vehicle: transfer failed: ' . $e->getMessage());
                $error = $t[$lang]['err_db'];
            }
        }
    }
}

/* ─── After the redirect: what was just moved ─── */
$tvDone = null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_SESSION['tv_done'])) {
    $tvDone = $_SESSION['tv_done'];
    unset($_SESSION['tv_done']);
    $nMoved  = count($tvDone['cars']);
    $nLabel  = $lang === 'ar'
        ? ($nMoved === 2 ? 'سيارتين' : $nMoved . ($nMoved <= 10 ? ' سيارات' : ' سيارة'))
        : $nMoved . ' vehicles';
    $success = $nMoved > 1 ? sprintf($t[$lang]['success_n'], $nLabel) : $t[$lang]['success'];
}

/* ─── Context: photos, last move, branch stock, today ─── */
function tv_swatch(string $colorEn): string
{
    static $map = [
        'white' => '#f8fafc', 'pearl white' => '#f1f5f9', 'black' => '#111827', 'silver' => '#cbd5e1',
        'grey' => '#6b7280', 'gray' => '#6b7280', 'red' => '#dc2626', 'blue' => '#2563eb', 'navy' => '#1e3a8a',
        'green' => '#16a34a', 'gold' => '#d4af37', 'beige' => '#e0d5c0', 'brown' => '#78350f',
        'orange' => '#ea580c', 'yellow' => '#eab308', 'purple' => '#7c3aed', 'bronze' => '#a97142', 'champagne' => '#e6d7b8',
    ];
    return $map[mb_strtolower(trim($colorEn))] ?? '#64748b';
}
$tvImgMap = [];
try { $tvImgMap = car_images_map($pdo); } catch (Throwable $e) {}
$tvLastMove = [];
try {
    foreach ($pdo->query("SELECT car_id, MAX(created_at) AS at FROM movements WHERE event_type = 'transfer' AND from_branch <> to_branch GROUP BY car_id") as $r) {
        $tvLastMove[(int)$r['car_id']] = strtotime($r['at']);
    }
} catch (Throwable $e) {}
$tvBranchLabel = [];
foreach ($branches as $b) $tvBranchLabel[$b['name']] = $lang === 'ar' ? ($b['name_ar'] ?: $b['name']) : ($b['name_en'] ?: $b['name']);

// what each branch holds now, overall and per model (to see where a car is needed)
$tvStock = [];
foreach ($cars as $c) {
    $b = (string)$c['branch'];
    $tvStock[$b]['n'] = ($tvStock[$b]['n'] ?? 0) + 1;
    $mk = mb_strtolower($c['brand'] . '|' . $c['model']);
    $tvStock[$b]['m'][$mk] = ($tvStock[$b]['m'][$mk] ?? 0) + 1;
}

$tvToday = [];
try {
    $tq = $pdo->query("
        SELECT m.car_id, m.from_branch, m.to_branch, m.moved_by, m.created_at, c.brand, c.model, c.car_year, c.trim_name, c.color, c.chassis
        FROM movements m JOIN cars c ON c.id = m.car_id
        WHERE m.event_type = 'transfer' AND m.from_branch <> m.to_branch AND m.created_at >= CURDATE()
        ORDER BY m.id DESC LIMIT 40");
    $tvToday = $tq->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('transfer_vehicle: today list failed: ' . $e->getMessage()); }

/* after an error, put the same cars / branch / notes back */
$tvReselect = ($error && $_SERVER['REQUEST_METHOD'] === 'POST') ? [
    'ids'   => $ids ?? [],
    'to'    => (string)($_POST['to_branch'] ?? ''),
    'notes' => (string)($_POST['notes'] ?? ''),
    'multi' => !empty($_POST['car_ids']),
] : null;

/* ─── Build JSON payload for JS ────────────────────────────── */
$cars_json = json_encode(array_map(function ($c) use ($lang, $tvImgMap, $tvLastMove) {
    $img = '';
    try { $img = car_image_url_for($tvImgMap, $c, true); } catch (Throwable $e) {}
    $lm = $tvLastMove[(int)$c['id']] ?? null;
    return [
        'id'      => (int)$c['id'],
        'brand'   => $c['brand'],
        'model'   => $c['model'],
        'trim'    => $c['trim_name'],
        'year'    => (string)$c['car_year'],
        'color'   => $lang === 'ar' ? ($c['color_ar'] ?: $c['color']) : ($c['color_en'] ?: $c['color']),
        'hex'     => tv_swatch((string)$c['color']),
        'branch'  => $lang === 'ar' ? ($c['branch_name_ar'] ?: $c['branch']) : ($c['branch_name_en'] ?: $c['branch']),
        'bkey'    => (string)$c['branch'],
        'chassis' => $c['chassis'],
        'status'  => $c['status'],
        'img'     => $img,
        'moved'   => $lm ? (int)floor((time() - $lm) / 86400) : null,
        'mk'      => mb_strtolower($c['brand'] . '|' . $c['model']),
    ];
}, $cars), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

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
            <a href="transfer_vehicle.php?lang=<?= $lang === 'ar' ? 'en' : 'ar' ?><?= $preselectId > 0 ? '&id=' . $preselectId : '' ?>" class="btn-ghost">
                🌐 <?= $t[$lang]['switch_lang'] ?>
            </a>
            <a href="dashboard.php?lang=<?= $lang ?>" class="btn-ghost">
                ← <?= $t[$lang]['back'] ?>
            </a>
        </div>
    </div>

    <!-- ── Main card ───────────────────────────────────────── -->
    <div class="card">

        <?php if ($success && $tvDone):
            $toLbl = $tvBranchLabel[$tvDone['to']] ?? $tvDone['to'];
            $first = $tvDone['cars'][0];
            $fImg = '';
            try { $fImg = car_image_url_for($tvImgMap, $first, false); } catch (Throwable $e) {}
        ?>
        <div class="tv-done" id="tvDone" data-img="<?= htmlspecialchars($fImg) ?>" data-hex="<?= tv_swatch((string)$first['color']) ?>">
            <div class="tv-done-stage" id="tvDoneStage"></div>
            <div class="tv-done-b">
                <div class="tv-done-t">✅ <?= htmlspecialchars($success) ?></div>
                <div class="tv-done-route">
                    <?php $froms = array_unique(array_map(fn($c) => $tvBranchLabel[$c['from']] ?? $c['from'], $tvDone['cars'])); ?>
                    <span><?= htmlspecialchars(implode(' · ', $froms)) ?></span>
                    <i class="tv-done-arrow"><?= $dir === 'rtl' ? '←' : '→' ?></i>
                    <span class="to">📍 <?= htmlspecialchars($toLbl) ?></span>
                </div>
                <div class="tv-done-cars">
                    <?php foreach ($tvDone['cars'] as $mc): ?>
                    <span><i style="background:<?= tv_swatch((string)$mc['color']) ?>"></i><?= htmlspecialchars($mc['brand'] . ' ' . $mc['model'] . ' ' . $mc['car_year']) ?> <b class="mono"><?= htmlspecialchars($mc['chassis']) ?></b></span>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($tvDone['skipped'])): ?>
                <div class="tv-done-skip">ℹ️ <?= htmlspecialchars(sprintf($t[$lang]['skipped'], implode('، ', $tvDone['skipped']))) ?></div>
                <?php endif; ?>
                <div class="tv-done-a">
                    <?php if (count($tvDone['cars']) === 1 && can('page.vehicle_timeline')): ?>
                    <a class="tv-btn" href="vehicle_timeline.php?id=<?= (int)$first['id'] ?>&lang=<?= $lang ?>">🗺 <?= $lang === 'ar' ? 'رحلة السيارة' : 'Vehicle journey' ?></a>
                    <?php endif; ?>
                    <a class="tv-btn tv-btn-p" href="#transferForm" id="tvAnother">🔄 <?= $lang === 'ar' ? 'نقل سيارة أخرى' : 'Transfer another' ?></a>
                </div>
            </div>
        </div>
        <?php elseif ($success): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" id="transferForm">
            <!-- Hidden input carries the chosen car id -->
            <input type="hidden" name="car_id" id="carIdInput">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <div id="multiInputs"></div>

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

            <div class="tv-bchips" id="tvBChips"></div>
            <div class="list-header">
                <span id="listCount"></span>
                <label class="tv-multi"><input type="checkbox" id="multiToggle"><span class="tv-sw"></span><?= $lang === 'ar' ? 'تحديد متعدد' : 'Select several' ?></label>
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

            <div class="tv-bcards" id="tvBCards"></div>
            <div class="tv-route" id="tvRoute"></div>

            <div class="two-col">
                <div class="form-group" style="margin-bottom:0">
                    <label class="field-label"><?= $t[$lang]['to_branch'] ?></label>
                    <select name="to_branch" id="toBranch" required>
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
                    <textarea name="notes" id="tvNotes" placeholder="<?= $lang === 'ar' ? 'سبب النقل (اختياري)…' : 'Reason for the move (optional)…' ?>" style="min-height:52px;height:52px;"></textarea>
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

    <!-- ── Today ─────────────────────────────────────────── -->
    <div class="card tv-today">
        <div class="tv-today-h">
            <span>🗓 <?= $lang === 'ar' ? 'نقل اليوم' : "Today's transfers" ?></span>
            <span class="tv-today-n"><?= count($tvToday) ?></span>
        </div>
        <?php if (!$tvToday): ?>
        <div class="tv-today-empty"><?= $lang === 'ar' ? 'لا يوجد نقل اليوم بعد' : 'No transfers yet today' ?></div>
        <?php else: ?>
        <div class="tv-today-list">
            <?php foreach ($tvToday as $i => $r):
                $fresh = $tvDone && in_array((int)$r['car_id'], array_column($tvDone['cars'], 'id'), true) && $r['to_branch'] === $tvDone['to'];
            ?>
            <div class="tv-ti<?= $fresh ? ' fresh' : '' ?>">
                <i class="tv-ti-dot" style="background:<?= tv_swatch((string)$r['color']) ?>"></i>
                <div class="tv-ti-m">
                    <div class="tv-ti-n"><?= htmlspecialchars($r['brand'] . ' ' . $r['model'] . ' ' . $r['car_year']) ?> <span><?= htmlspecialchars((string)$r['trim_name']) ?></span></div>
                    <div class="tv-ti-s"><?= htmlspecialchars($tvBranchLabel[$r['from_branch']] ?? $r['from_branch']) ?> <?= $dir === 'rtl' ? '←' : '→' ?> <b><?= htmlspecialchars($tvBranchLabel[$r['to_branch']] ?? $r['to_branch']) ?></b> · <?= htmlspecialchars($r['moved_by']) ?></div>
                </div>
                <div class="tv-ti-r"><span class="tv-ti-t"><?= date('h:i A', strtotime($r['created_at'])) ?></span><span class="tv-ti-c"><?= htmlspecialchars($r['chassis']) ?></span></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<div class="tv-ov" id="tvOv" aria-hidden="true"><div class="tv-sheet" id="tvSheet" role="dialog" aria-modal="true"></div></div>

<style>
/* ═══════════ Transfer extras (same search → car → branch → confirm flow) ═══════════ */
.mono { font-family: 'SFMono-Regular', Consolas, monospace; direction: ltr; unicode-bidi: isolate; }
.tv-bchips { display: flex; gap: 6px; overflow-x: auto; scrollbar-width: none; margin: 10px 0 2px; }
.tv-bchips::-webkit-scrollbar { display: none; }
.tv-bchip { flex-shrink: 0; height: 32px; padding: 0 12px; border-radius: 999px; border: 1px solid var(--border); background: var(--bg-input); color: #94a3b8; font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: var(--transition); }
.tv-bchip b { font-size: 11px; background: rgba(255,255,255,.07); border-radius: 999px; padding: 0 7px; color: var(--text); }
.tv-bchip.on { background: rgba(124,58,237,.2); border-color: var(--purple-lt); color: #fff; }
.list-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.tv-multi { display: inline-flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 700; color: #94a3b8; cursor: pointer; user-select: none; }
.tv-multi input { display: none; }
.tv-sw { width: 34px; height: 20px; border-radius: 999px; background: rgba(255,255,255,.1); position: relative; transition: var(--transition); }
.tv-sw::after { content: ''; position: absolute; top: 3px; inset-inline-start: 3px; width: 14px; height: 14px; border-radius: 50%; background: #cbd5e1; transition: var(--transition); }
.tv-multi input:checked + .tv-sw { background: var(--purple); }
.tv-multi input:checked + .tv-sw::after { inset-inline-start: 17px; background: #fff; }
.tv-multi:has(input:checked) { color: #e9d5ff; }

/* rows with a photo */
.vehicle-list { max-height: 420px; }
.v-row { gap: 12px; }
.v-thumb { width: 74px; height: 46px; flex-shrink: 0; border-radius: 10px; overflow: hidden; position: relative; --car: #64748b;
    background: radial-gradient(ellipse 80% 75% at 50% 15%, color-mix(in srgb, var(--car) 22%, #16223b), #0a1120 75%); border: 1px solid var(--border); }
.v-thumb img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; padding: 3px; }
.v-thumb svg { position: absolute; inset-inline: 6%; bottom: 8%; width: 88%; height: auto; }
.v-meta i { display: inline-block; width: 9px; height: 9px; border-radius: 50%; border: 1px solid rgba(255,255,255,.3); vertical-align: 0; margin-inline-end: 4px; }
.v-moved { font-size: 11px; color: #a78bfa; font-weight: 700; margin-top: 2px; }
.v-badge.res { color: #fde68a !important; background: rgba(250,204,21,.12) !important; border-color: rgba(250,204,21,.35) !important; }
.v-row.res { background: linear-gradient(90deg, rgba(250,204,21,.05), transparent); }
.v-check { width: 22px; height: 22px; flex-shrink: 0; border-radius: 7px; border: 2px solid rgba(255,255,255,.2); display: none; align-items: center; justify-content: center; font-size: 12px; font-weight: 900; color: transparent; transition: var(--transition); }
.multi .v-check { display: flex; }
.multi .v-row.selected::before { display: none; }
.v-row.selected .v-check { background: var(--purple); border-color: var(--purple); color: #fff; }

/* selected cars (multi) */
.tv-picked { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; }
.tv-picked span { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; padding: 4px 6px 4px 10px; border-radius: 999px; background: rgba(124,58,237,.12); border: 1px solid rgba(168,85,247,.3); }
.tv-picked span i { width: 9px; height: 9px; border-radius: 50%; }
.tv-picked button { border: 0; background: rgba(255,255,255,.08); color: #e2e8f0; width: 18px; height: 18px; border-radius: 50%; cursor: pointer; font-size: 10px; line-height: 18px; }

/* preview stage */
.ph-icon.tv-stage { width: 120px; height: 72px; border-radius: 14px; font-size: 0; position: relative; overflow: hidden; --car: #64748b; flex-shrink: 0;
    background: radial-gradient(ellipse 80% 70% at 50% 15%, color-mix(in srgb, var(--car) 24%, #1a2744), #0a1120 74%); border: 1px solid var(--border); }
.tv-stage img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; padding: 5px 7px 3px; }
.tv-stage svg { position: absolute; inset-inline: 7%; bottom: 8%; width: 86%; height: auto; }
.tv-stage.studio { background: linear-gradient(#fff, #eef1f5); }
#pvChassis { font-family: 'SFMono-Regular', Consolas, monospace; letter-spacing: .08em; }

/* destination cards */
.tv-bcards { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; margin-bottom: 14px; }
.tv-bc { position: relative; text-align: start; padding: 13px 14px; border-radius: 16px; border: 2px solid var(--border); background: var(--bg-input); color: var(--text); font: inherit; cursor: pointer; transition: var(--transition); }
.tv-bc:hover:not(:disabled) { border-color: rgba(168,85,247,.45); transform: translateY(-2px); }
.tv-bc.on { border-color: var(--green); background: linear-gradient(160deg, rgba(34,197,94,.14), var(--bg-input)); box-shadow: 0 8px 24px rgba(34,197,94,.15); }
.tv-bc.on::after { content: '✓'; position: absolute; top: 9px; inset-inline-end: 10px; width: 20px; height: 20px; border-radius: 50%; background: var(--green); color: #052e16; font-size: 11px; font-weight: 900; display: flex; align-items: center; justify-content: center; }
.tv-bc:disabled { opacity: .45; cursor: not-allowed; }
.tv-bc .n { font-size: 15px; font-weight: 800; margin-bottom: 6px; padding-inline-end: 22px; }
.tv-bc .s { font-size: 11px; color: var(--muted); font-weight: 600; line-height: 1.7; }
.tv-bc .s b { color: var(--text); font-size: 13px; }
.tv-bc .need { display: inline-block; margin-top: 5px; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 999px; background: rgba(34,197,94,.14); color: #86efac; }
.tv-bc .have { display: inline-block; margin-top: 5px; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 999px; background: rgba(148,163,184,.12); color: #cbd5e1; }
.tv-bc .cur { display: inline-block; margin-top: 5px; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 999px; background: rgba(124,58,237,.18); color: #d8b4fe; }
.form-group:has(#toBranch) select { font-size: 13px; }

/* route preview */
.tv-route { display: none; align-items: center; gap: 12px; margin: 0 0 16px; padding: 14px 16px; border-radius: 16px; background: rgba(124,58,237,.06); border: 1px dashed rgba(168,85,247,.3); }
.tv-route.on { display: flex; animation: tvFade .3s ease both; }
.tv-route .pt { font-size: 14px; font-weight: 800; white-space: nowrap; }
.tv-route .pt.to { color: var(--green-lt); }
.tv-route .road { flex: 1; height: 4px; border-radius: 4px; position: relative; background: repeating-linear-gradient(90deg, rgba(168,85,247,.5) 0 10px, transparent 10px 18px); }
.tv-route .road b { position: absolute; top: 50%; font-size: 18px; transform: translateY(-58%); animation: tvDrive 2.4s cubic-bezier(.45,0,.55,1) infinite; }
[dir=rtl] .tv-route .road b { animation-name: tvDriveR; transform: translateY(-58%) scaleX(-1); }
@keyframes tvDrive { from { left: -4%; } to { left: 92%; } }
@keyframes tvDriveR { from { right: -4%; } to { right: 92%; } }
@keyframes tvFade { from { opacity: 0; transform: translateY(6px); } }
.btn-transfer.ready { animation: tvReady 2.4s ease-in-out infinite; }
@keyframes tvReady { 50% { box-shadow: 0 8px 34px rgba(34,197,94,.45); } }

/* confirm sheet */
.tv-ov { position: fixed; inset: 0; z-index: 900; background: rgba(2,6,23,.8); backdrop-filter: blur(7px); display: none; align-items: center; justify-content: center; padding: 18px; }
.tv-ov.on { display: flex; animation: tvFade .2s ease both; }
.tv-sheet { width: 100%; max-width: 470px; max-height: 92vh; overflow-y: auto; border-radius: 26px; background: linear-gradient(170deg, #151b36, #0a1122); border: 1px solid rgba(168,85,247,.28); box-shadow: 0 40px 100px rgba(0,0,0,.6); padding: 22px; animation: tvIn .3s cubic-bezier(.22,1,.36,1) both; }
@keyframes tvIn { from { transform: translateY(22px) scale(.97); opacity: 0; } }
.tv-sheet h3 { font-size: 13px; color: var(--muted); font-weight: 700; margin-bottom: 4px; }
.tv-sheet .big { font-size: 21px; font-weight: 800; margin-bottom: 14px; }
.tv-sheet .tv-route { display: flex; margin-bottom: 14px; }
.tv-sc { display: flex; flex-direction: column; gap: 7px; max-height: 280px; overflow-y: auto; }
.tv-sc > div { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 13px; background: rgba(255,255,255,.03); border: 1px solid var(--border); }
.tv-sc .v-thumb { width: 64px; height: 40px; }
.tv-sc .nm { flex: 1; min-width: 0; font-size: 13px; font-weight: 700; }
.tv-sc .nm span { display: block; font-size: 11px; color: var(--muted); font-weight: 600; }
.tv-sc .pl { direction: ltr; font-family: 'SFMono-Regular', Consolas, monospace; font-size: 13px; font-weight: 900; letter-spacing: .1em; color: #111827; background: linear-gradient(#fefce8, #fde68a); border: 2px solid #1f2937; border-radius: 8px; padding: 2px 8px; }
.tv-note { font-size: 12px; color: #c4b5fd; background: rgba(124,58,237,.08); border-radius: 11px; padding: 8px 12px; margin-top: 10px; white-space: pre-wrap; }
.tv-acts { display: flex; flex-direction: column; gap: 8px; margin-top: 16px; }
.tv-acts button { height: 50px; border-radius: 14px; border: 1px solid var(--border); font: inherit; font-size: 15px; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; }
.tv-ok { background: linear-gradient(135deg, var(--green), #16a34a); color: #fff; border: 0 !important; box-shadow: 0 8px 26px rgba(34,197,94,.3); }
.tv-back { background: transparent; color: #94a3b8; }
.tv-acts button:disabled { opacity: .5; cursor: not-allowed; }
.tv-spin { width: 14px; height: 14px; border-radius: 50%; border: 2px solid rgba(255,255,255,.35); border-top-color: #fff; animation: tvSpin .7s linear infinite; display: inline-block; }
@keyframes tvSpin { to { transform: rotate(360deg); } }

/* success + today */
.tv-done { display: flex; gap: 16px; align-items: center; margin-bottom: 20px; padding: 14px; border-radius: 20px; background: linear-gradient(120deg, rgba(34,197,94,.14), rgba(10,18,40,.9) 62%); border: 1px solid rgba(34,197,94,.35); animation: tvIn .45s cubic-bezier(.22,1,.36,1) both; }
.tv-done-stage { width: 170px; height: 104px; flex-shrink: 0; border-radius: 14px; overflow: hidden; position: relative; }
.tv-done-stage .tv-stage { position: absolute; inset: 0; width: auto; height: auto; }
.tv-done-b { flex: 1; min-width: 0; }
.tv-done-t { font-size: 17px; font-weight: 800; color: var(--green); margin-bottom: 6px; }
.tv-done-route { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 14px; font-weight: 700; margin-bottom: 8px; }
.tv-done-route .to { color: var(--green-lt); }
.tv-done-arrow { font-style: normal; color: var(--purple-lt); font-size: 18px; animation: tvNudge 1.4s ease-in-out infinite; }
@keyframes tvNudge { 50% { transform: translateX(-4px); } } [dir=ltr] .tv-done-arrow { animation-name: tvNudgeL; } @keyframes tvNudgeL { 50% { transform: translateX(4px); } }
.tv-done-cars { display: flex; flex-wrap: wrap; gap: 6px; }
.tv-done-cars span { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 999px; background: rgba(255,255,255,.05); border: 1px solid var(--border); }
.tv-done-cars i { width: 9px; height: 9px; border-radius: 50%; }
.tv-done-cars b { color: #fbbf24; font-size: 11px; }
.tv-done-skip { margin-top: 8px; font-size: 12px; font-weight: 700; color: #fcd34d; background: rgba(245,158,11,.08); border: 1px solid rgba(245,158,11,.25); border-radius: 10px; padding: 6px 10px; }
.tv-done-a { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
.tv-btn { height: 36px; padding: 0 14px; border-radius: 11px; display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 800; text-decoration: none; color: var(--text); background: rgba(255,255,255,.05); border: 1px solid var(--border); }
.tv-btn-p { background: var(--green); border-color: var(--green); color: #052e16; }
.tv-today { margin-top: 18px; }
.tv-today-h { display: flex; align-items: center; justify-content: space-between; font-size: 16px; font-weight: 800; margin-bottom: 14px; }
.tv-today-n { font-size: 12px; font-weight: 800; padding: 3px 11px; border-radius: 999px; background: rgba(124,58,237,.14); color: #d8b4fe; }
.tv-today-empty { text-align: center; color: var(--muted); font-size: 13px; padding: 18px; border: 1px dashed var(--border); border-radius: 14px; }
.tv-today-list { display: flex; flex-direction: column; gap: 7px; max-height: 340px; overflow-y: auto; }
.tv-ti { display: flex; align-items: center; gap: 11px; padding: 10px 12px; border-radius: 14px; background: rgba(255,255,255,.025); border: 1px solid transparent; }
.tv-ti.fresh { border-color: rgba(34,197,94,.5); background: rgba(34,197,94,.08); }
.tv-ti-dot { width: 14px; height: 14px; border-radius: 50%; border: 2px solid rgba(255,255,255,.2); flex-shrink: 0; }
.tv-ti-m { flex: 1; min-width: 0; }
.tv-ti-n { font-size: 13px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tv-ti-n span { color: var(--muted); font-weight: 600; }
.tv-ti-s { font-size: 11px; color: #94a3b8; margin-top: 2px; }
.tv-ti-s b { color: var(--green-lt); }
.tv-ti-r { display: flex; flex-direction: column; align-items: flex-end; gap: 2px; flex-shrink: 0; }
.tv-ti-t { font-size: 11px; color: var(--muted); font-weight: 700; direction: ltr; }
.tv-ti-c { font-family: 'SFMono-Regular', Consolas, monospace; font-size: 11px; color: #fbbf24; font-weight: 800; direction: ltr; }

@media (max-width: 600px) {
    .v-thumb { width: 60px; height: 38px; }
    .v-row { padding: 11px 12px; gap: 10px; }
    .v-name { white-space: normal; line-height: 1.35; }
    .v-meta { white-space: normal; }
    .v-badge { font-size: 10px; padding: 3px 8px; }
    .v-badge:not(.res) { display: none; }
    .detail-grid { grid-template-columns: 1fr 1fr !important; gap: 8px; }
    .tv-bcards { grid-template-columns: 1fr 1fr; }
    .btn-transfer { position: sticky; bottom: 12px; z-index: 50; box-shadow: 0 10px 30px rgba(0,0,0,.55); }
    .tv-done { flex-direction: column; align-items: stretch; }
    .tv-done-stage { width: 100%; height: auto; aspect-ratio: 16/9; }
    .tv-ov { align-items: flex-end; padding: 0; }
    .tv-sheet { max-width: none; border-radius: 26px 26px 0 0; animation: tvUp .34s cubic-bezier(.22,1,.36,1) both; }
    @keyframes tvUp { from { transform: translateY(100%); } }
    .ph-icon.tv-stage { width: 96px; height: 58px; }
}
@media (prefers-reduced-motion: reduce) { .tv-route .road b, .btn-transfer.ready, .tv-done-arrow { animation: none; } }
</style>
<script>
/* ── Data injected from PHP ─────────────────────────────── */
const CARS = <?= $cars_json ?>;
const STOCK = <?= json_encode((object)$tvStock, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const BLABEL = <?= json_encode((object)$tvBranchLabel, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const RESELECT = <?= json_encode($tvReselect, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const AR = <?= json_encode($lang === 'ar') ?>;
const LABELS = {
    brand:    <?= json_encode($t[$lang]['brand'], JSON_UNESCAPED_UNICODE) ?>,
    chassis:  <?= json_encode($t[$lang]['chassis'], JSON_UNESCAPED_UNICODE) ?>,
    available:<?= json_encode($t[$lang]['available'], JSON_UNESCAPED_UNICODE) ?>,
    reserved: <?= json_encode($t[$lang]['reserved'], JSON_UNESCAPED_UNICODE) ?>,
    noRes:    <?= json_encode($t[$lang]['no_results'], JSON_UNESCAPED_UNICODE) ?>,
    results:  <?= json_encode($t[$lang]['results'], JSON_UNESCAPED_UNICODE) ?>,
};
const T = AR ? {
    all: 'كل الفروع', moved: d => d === 0 ? 'آخر نقل اليوم' : d === 1 ? 'آخر نقل أمس' : 'آخر نقل منذ ' + (d === 2 ? 'يومين' : d + (d <= 10 ? ' أيام' : ' يوم')),
    cars: n => n === 1 ? 'سيارة واحدة' : n === 2 ? 'سيارتين' : n + (n >= 3 && n <= 10 ? ' سيارات' : ' سيارة'), here: 'الفرع الحالي', none: 'لا يوجد منها هنا — محتاجها', have: n => 'فيه منها ' + n,
    picked: n => (n === 2 ? 'سيارتين' : n + (n <= 10 ? ' سيارات' : ' سيارة')) + ' مختارة',
    several: 'من عدة فروع', confirmT: 'راجع النقل قبل التأكيد', ok: '✓ تأكيد النقل', okN: n => '✓ تأكيد نقل ' + (n === 2 ? 'سيارتين' : n + (n <= 10 ? ' سيارات' : ' سيارة')), back: '✏️ رجوع للتعديل', saving: 'جارٍ النقل…'
} : {
    all: 'All branches', moved: d => d === 0 ? 'last moved today' : d === 1 ? 'last moved yesterday' : 'last moved ' + d + ' days ago',
    cars: n => n + (n === 1 ? ' car' : ' cars'), here: 'Current branch', none: 'none of this model — needed', have: n => n + ' of this model', picked: n => n + ' selected',
    several: 'several branches', confirmT: 'Check the move before confirming', ok: '✓ Confirm transfer', okN: n => '✓ Move ' + n + ' vehicles', back: '✏️ Back to edit', saving: 'Moving…'
};

/* ── DOM refs ───────────────────────────────────────────── */
const $ = id => document.getElementById(id);
const searchInput  = $('vehicleSearch');
const clearBtn     = $('clearSearch');
const listEl       = $('vehicleList');
const listCount    = $('listCount');
const carIdInput   = $('carIdInput');
const previewCard  = $('vehiclePreview');
const submitBtn    = $('submitBtn');
const toSel        = $('toBranch');
const multiToggle  = $('multiToggle');

let selectedId = null;           // single mode
let picked = new Set();          // multi mode
let branchFilter = '';
let multi = false;

function escHtml(str) {
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
const SIL = hex => '<svg viewBox="0 0 320 130" aria-hidden="true"><ellipse cx="163" cy="119" rx="142" ry="7" fill="#000" opacity=".5"/>' +
    '<path fill="' + hex + '" d="M20,92 L22,72 Q24,62 36,60 L80,55 L112,31 Q118,26 128,26 L222,26 Q234,26 242,34 L268,57 L292,61 Q306,64 306,78 L306,92 Q306,98 300,98 L277,98 A27,27 0 0 0 223,98 L105,98 A27,27 0 0 0 51,98 L26,98 Q20,98 20,92 Z"/>' +
    '<path d="M88,57 L116,35 Q120,32 126,32 L166,32 L166,57 Z M174,32 L221,32 Q229,32 235,38 L256,57 L174,57 Z" fill="#0b1220" opacity=".82"/>' +
    '<circle cx="78" cy="98" r="21" fill="#0b1220" stroke="#1e293b" stroke-width="5"/><circle cx="78" cy="98" r="9" fill="#94a3b8"/>' +
    '<circle cx="250" cy="98" r="21" fill="#0b1220" stroke="#1e293b" stroke-width="5"/><circle cx="250" cy="98" r="9" fill="#94a3b8"/></svg>';
const thumb = (c, big) => '<div class="' + (big ? 'ph-icon tv-stage' : 'v-thumb') + '" style="--car:' + c.hex + '">' +
    (c.img ? '<img src="' + escHtml(big ? c.img.replace(/_t(\.\w+)$/, '$1') : c.img) + '" alt="" loading="lazy" onerror="this.outerHTML=SIL(\'' + c.hex + '\')">' : SIL(c.hex)) + '</div>';

/* ── Branch chips (where the car is now) ───────────────── */
(function () {
    const counts = {}; CARS.forEach(c => counts[c.bkey] = (counts[c.bkey] || 0) + 1);
    const keys = Object.keys(counts);
    if (keys.length < 2) return;
    const el = $('tvBChips');
    el.innerHTML = '<button type="button" class="tv-bchip on" data-b="">' + escHtml(T.all) + ' <b>' + CARS.length + '</b></button>' +
        keys.map(k => '<button type="button" class="tv-bchip" data-b="' + escHtml(k) + '">📍 ' + escHtml(BLABEL[k] || k) + ' <b>' + counts[k] + '</b></button>').join('');
    el.addEventListener('click', e => {
        const b = e.target.closest('.tv-bchip'); if (!b) return;
        branchFilter = b.dataset.b;
        el.querySelectorAll('.tv-bchip').forEach(x => x.classList.toggle('on', x === b));
        renderList(searchInput.value);
    });
})();

/* ── Render list ────────────────────────────────────────── */
function renderList(query) {
    const q = (query || '').trim().toLowerCase();
    const filtered = CARS.filter(c =>
        (!branchFilter || c.bkey === branchFilter) &&
        (!q || [c.brand, c.model, c.trim, c.year, c.color, c.branch, c.chassis].join(' ').toLowerCase().includes(q)));

    listCount.textContent = filtered.length ? `${filtered.length} ${LABELS.results}` : '';
    listEl.classList.toggle('multi', multi);

    if (!filtered.length) {
        listEl.innerHTML = `<div class="empty-state"><div class="es-icon">🔍</div><div>${LABELS.noRes}</div></div>`;
        return;
    }

    listEl.innerHTML = filtered.map(c => {
        const on = multi ? picked.has(c.id) : c.id === selectedId;
        const res = c.status === 'reserved';
        return `
        <div class="v-row ${on ? 'selected' : ''} ${res ? 'res' : ''}" data-id="${c.id}" role="option" tabindex="0" aria-selected="${on}">
            <span class="v-check">✓</span>
            ${thumb(c)}
            <div class="v-main">
                <div class="v-name">${escHtml(c.brand)} ${escHtml(c.model)} <span style="color:var(--muted);font-weight:600">${escHtml(c.trim || '')} ${escHtml(c.year || '')}</span></div>
                <div class="v-meta"><i style="background:${c.hex}"></i>${escHtml(c.color || '')} · 📍 ${escHtml(c.branch)} · <span class="mono">${escHtml(c.chassis)}</span></div>
                ${c.moved != null ? `<div class="v-moved">🔄 ${escHtml(T.moved(c.moved))}</div>` : ''}
            </div>
            <div class="v-badge ${res ? 'res' : ''}">${res ? '🟡 ' + LABELS.reserved : LABELS.available}</div>
        </div>`;
    }).join('');

    listEl.querySelectorAll('.v-row').forEach(row => {
        row.addEventListener('click', () => selectCar(parseInt(row.dataset.id)));
        row.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectCar(parseInt(row.dataset.id)); } });
    });
}

function selectedCars() { return multi ? CARS.filter(c => picked.has(c.id)) : CARS.filter(c => c.id === selectedId); }

function selectCar(id) {
    const car = CARS.find(c => c.id === id);
    if (!car) return;
    if (multi) { picked.has(id) ? picked.delete(id) : picked.add(id); }
    else { selectedId = id; carIdInput.value = id; }
    refresh();
    renderList(searchInput.value);
}

/* ── Preview: one car in detail, or the chosen cars as chips ─ */
function renderPreview() {
    const sel = selectedCars();
    if (!sel.length) { previewCard.style.display = 'none'; return; }
    previewCard.style.display = 'block';
    const head = previewCard.querySelector('.preview-header');
    const grid = previewCard.querySelector('.detail-grid');
    let chips = previewCard.querySelector('.tv-picked');
    if (!chips) { chips = document.createElement('div'); chips.className = 'tv-picked'; previewCard.appendChild(chips); }
    const old = head.querySelector('.ph-icon'); 
    if (sel.length === 1) {
        const car = sel[0];
        old.outerHTML = thumb(car, true);
        $('pvTitle').textContent = `${car.brand} ${car.model} ${car.year || ''}`;
        $('pvBrand').textContent = car.brand; $('pvModel').textContent = car.model; $('pvTrim').textContent = car.trim || '—';
        $('pvColor').innerHTML = `<i style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${car.hex};margin-inline-end:6px;border:1px solid rgba(255,255,255,.3)"></i>${escHtml(car.color || '—')}`;
        $('pvBranch').textContent = car.branch; $('pvChassis').textContent = car.chassis;
        grid.style.display = ''; chips.innerHTML = '';
    } else {
        old.outerHTML = '<div class="ph-icon">🚘</div>';
        $('pvTitle').textContent = T.picked(sel.length);
        grid.style.display = 'none';
        chips.innerHTML = sel.map(c => `<span><i style="background:${c.hex}"></i>${escHtml(c.brand + ' ' + c.model)} <b class="mono" style="color:#fbbf24">${escHtml(c.chassis)}</b><button type="button" data-x="${c.id}">✕</button></span>`).join('');
        chips.querySelectorAll('[data-x]').forEach(b => b.addEventListener('click', () => { picked.delete(+b.dataset.x); refresh(); renderList(searchInput.value); }));
    }
}

/* ── Destination cards: what each branch has now ───────── */
function renderBranches() {
    const sel = selectedCars();
    const froms = new Set(sel.map(c => c.bkey));
    const mk = sel.length === 1 ? sel[0].mk : null;
    const opts = Array.from(toSel.options).filter(o => o.value);
    // the page's own select stays the real field; the cards drive it
    opts.forEach(o => { o.disabled = sel.length > 0 && froms.size === 1 && froms.has(o.value); });
    if (toSel.selectedOptions[0] && toSel.selectedOptions[0].disabled) toSel.value = '';
    $('tvBCards').innerHTML = opts.map(o => {
        const st = STOCK[o.value] || { n: 0, m: {} };
        const isCur = sel.length > 0 && froms.size === 1 && froms.has(o.value);
        const have = mk ? (st.m && st.m[mk]) || 0 : null;
        return `<button type="button" class="tv-bc ${toSel.value === o.value ? 'on' : ''}" data-v="${escHtml(o.value)}" ${isCur ? 'disabled' : ''}>
            <div class="n">📍 ${escHtml(o.textContent.trim())}</div>
            <div class="s"><b>${escHtml(T.cars(st.n || 0))}</b></div>
            ${isCur ? `<span class="cur">${escHtml(T.here)}</span>` : have === null ? '' : have ? `<span class="have">${escHtml(T.have(have))}</span>` : `<span class="need">${escHtml(T.none)}</span>`}
        </button>`;
    }).join('');
    $('tvBCards').querySelectorAll('.tv-bc').forEach(b => b.addEventListener('click', () => { toSel.value = b.dataset.v; refresh(); }));
}

/* ── Route preview ─────────────────────────────────────── */
function routeHTML(sel, to) {
    const froms = [...new Set(sel.map(c => c.branch))];
    const from = froms.length === 1 ? froms[0] : T.several;
    const toLbl = (toSel.querySelector(`option[value="${CSS.escape(to)}"]`) || {}).textContent || to;
    return `<span class="pt">📍 ${escHtml(from)}</span><span class="road"><b>🚗</b></span><span class="pt to">📍 ${escHtml(toLbl.trim())}</span>`;
}
function refresh() {
    const sel = selectedCars();
    renderPreview();
    renderBranches();
    const r = $('tvRoute');
    if (sel.length && toSel.value) { r.innerHTML = routeHTML(sel, toSel.value); r.classList.add('on'); } else r.classList.remove('on');
    const ready = sel.length > 0 && !!toSel.value;
    submitBtn.disabled = !sel.length;
    submitBtn.classList.toggle('ready', ready);
    const lbl = submitBtn.querySelector('span:last-child');
    if (lbl) lbl.textContent = multi && sel.length > 1 ? T.okN(sel.length).replace('✓ ', '') : <?= json_encode($t[$lang]['transfer'], JSON_UNESCAPED_UNICODE) ?>;
}
toSel.addEventListener('change', refresh);

/* ── Single ↔ several ─────────────────────────────────── */
multiToggle.addEventListener('change', () => {
    multi = multiToggle.checked;
    if (multi) { picked = new Set(selectedId ? [selectedId] : []); }
    else { const first = [...picked][0]; selectedId = first || null; carIdInput.value = selectedId || ''; picked.clear(); }
    refresh(); renderList(searchInput.value);
});

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

/* ── Confirm sheet, then send once ─────────────────────── */
const ov = $('tvOv'), sheet = $('tvSheet');
let locked = false;
function closeOv() { if (locked) return; ov.classList.remove('on'); document.body.style.overflow = ''; }
ov.addEventListener('click', e => { if (e.target === ov) closeOv(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape' && ov.classList.contains('on')) closeOv(); });

document.getElementById('transferForm').addEventListener('submit', function (e) {
    e.preventDefault();
    if (locked) return;
    const sel = selectedCars();
    if (!sel.length) {
        searchInput.focus();
        searchInput.style.borderColor = 'var(--red)';
        setTimeout(() => searchInput.style.borderColor = '', 1800);
        return;
    }
    if (!toSel.value) {
        $('tvBCards').scrollIntoView({ behavior: 'smooth', block: 'center' });
        toSel.style.borderColor = 'var(--red)';
        setTimeout(() => toSel.style.borderColor = '', 1800);
        return;
    }
    const notes = $('tvNotes').value.trim();
    sheet.innerHTML = `<h3>${escHtml(T.confirmT)}</h3>
        <div class="big">${sel.length > 1 ? escHtml(T.picked(sel.length)) : escHtml(sel[0].brand + ' ' + sel[0].model + ' ' + (sel[0].year || ''))}</div>
        <div class="tv-route">${routeHTML(sel, toSel.value)}</div>
        <div class="tv-sc">${sel.map(c => `<div>${thumb(c)}<div class="nm">${escHtml(c.brand + ' ' + c.model)}<span>${escHtml([c.trim, c.year, c.color].filter(Boolean).join(' · '))}</span></div><span class="pl">${escHtml(c.chassis)}</span></div>`).join('')}</div>
        ${notes ? `<div class="tv-note">📝 ${escHtml(notes)}</div>` : ''}
        <div class="tv-acts"><button type="button" class="tv-ok">${escHtml(sel.length > 1 ? T.okN(sel.length) : T.ok)}</button><button type="button" class="tv-back">${escHtml(T.back)}</button></div>`;
    sheet.querySelector('.tv-back').addEventListener('click', closeOv);
    sheet.querySelector('.tv-ok').addEventListener('click', function () {
        if (locked) return; locked = true;
        this.disabled = true; sheet.querySelector('.tv-back').disabled = true;
        this.innerHTML = '<span class="tv-spin"></span> ' + escHtml(T.saving);
        const box = $('multiInputs'); box.innerHTML = '';
        if (multi) { carIdInput.value = ''; sel.forEach(c => { const i = document.createElement('input'); i.type = 'hidden'; i.name = 'car_ids[]'; i.value = c.id; box.appendChild(i); }); }
        else carIdInput.value = sel[0].id;
        submitBtn.disabled = true;
        HTMLFormElement.prototype.submit.call(document.getElementById('transferForm'));
    });
    ov.classList.add('on'); document.body.style.overflow = 'hidden';
    setTimeout(() => sheet.querySelector('.tv-ok').focus(), 60);
});

/* ── Success card ─────────────────────────────────────── */
(function () {
    const d = $('tvDone'); if (!d) return;
    $('tvDoneStage').innerHTML = '<div class="tv-stage" style="--car:' + d.dataset.hex + '">' + (d.dataset.img ? '<img src="' + escHtml(d.dataset.img) + '" alt="" onerror="this.outerHTML=SIL(\'' + d.dataset.hex + '\')">' : SIL(d.dataset.hex)) + '</div>';
    $('tvAnother').addEventListener('click', e => { e.preventDefault(); $('transferForm').scrollIntoView({ behavior: 'smooth' }); setTimeout(() => searchInput.focus({ preventScroll: true }), 450); });
})();

/* ── Init ───────────────────────────────────────────────── */
renderList('');
refresh();

// Auto-select the car coming from the dashboard (?id=)
const preselectId = <?= (int)$preselectId ?>;
if (RESELECT && RESELECT.ids && RESELECT.ids.length) {
    if (RESELECT.multi) { multiToggle.checked = true; multi = true; RESELECT.ids.forEach(i => { if (CARS.some(c => c.id === i)) picked.add(i); }); }
    else if (CARS.some(c => c.id === RESELECT.ids[0])) { selectedId = RESELECT.ids[0]; carIdInput.value = selectedId; }
    toSel.value = RESELECT.to || ''; $('tvNotes').value = RESELECT.notes || '';
    refresh(); renderList('');
} else if (preselectId > 0 && CARS.some(c => c.id === preselectId)) {
    selectCar(preselectId);
    previewCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
</script>
</body>
</html>