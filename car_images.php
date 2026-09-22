<?php
/*
 * car_images.php — مكتبة صور السيارات / Car Image Library
 *
 * Pick a model, pick a colour, upload one official photo. Every car of that
 * model in that colour then shows it automatically — on the dashboard cards, in
 * the add-vehicle preview, and on every car received from now on. Nobody has to
 * drive between branches taking photos.
 *
 * The page leads with the WORKLIST: the model-and-colour combinations that
 * actually have cars in stock right now and still have no image, ordered by how
 * many cars are waiting. That turns an endless job into a finite one that
 * shrinks every time you upload.
 *
 * Permission-gated (default: admin only, editable in the Permission Center):
 * page.car_images / car_images.upload / car_images.delete.
 */

require 'auth.php';
require 'config.php';
require 'car_images_helpers.php';

perm_require('page.car_images');

$lang = $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar', 'en'], true)) $lang = 'ar';
$dir   = $lang === 'ar' ? 'rtl' : 'ltr';
$isRTL = $lang === 'ar';

$canUpload = can('car_images.upload');
$canDelete = can('car_images.delete');

$schemaOk = car_images_ensure($pdo);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$t = [
    'ar' => [
        'title'      => 'مكتبة صور السيارات',
        'subtitle'   => 'صورة واحدة لكل موديل ولون — تظهر تلقائياً على كل سيارة مطابقة',
        'dashboard'  => 'الرئيسية',
        'add_image'  => '➕ إضافة صورة',
        'coverage'   => 'تغطية المخزون',
        'st_images'  => 'صورة في المكتبة',
        'st_models'  => 'موديل مغطّى',
        'st_covered' => 'سيارة تعرض صورة',
        'st_missing' => 'سيارة بدون صورة',
        'work_title' => '🎯 الأولوية الآن',
        'work_sub'   => 'موديلات وألوان موجودة في المخزون فعلياً وما زالت بدون صورة — الأكثر عدداً أولاً',
        'work_cars'  => 'سيارة',
        'work_done'  => '✅ كل سيارة في المخزون لها صورة — المكتبة مكتملة',
        'all_brands' => 'كل الماركات',
        'search_ph'  => 'ابحث بالماركة أو الموديل...',
        'filter'     => 'تصفية',
        'reset'      => 'إعادة تعيين',
        'in_stock'   => 'في المخزون',
        'no_models'  => 'لا توجد موديلات',
        'no_models_s'=> 'أضف موديلات وسيارات أولاً',
        'upload_t'   => 'إضافة صورة',
        'target'     => 'الصورة لـ',
        'model'      => 'الموديل',
        'color'      => 'اللون',
        'any_color'  => 'كل الألوان (صورة افتراضية للموديل)',
        'advanced'   => 'تحديد أدق (اختياري)',
        'adv_hint'   => 'اتركهما فارغين إلا لو شكل الموديل بيختلف فعلاً حسب السنة أو الفئة',
        'year'       => 'السنة',
        'trim'       => 'الفئة',
        'any'        => 'الكل',
        'drop_here'  => 'اسحب الصورة هنا أو اضغط للاختيار',
        'drop_hint'  => 'JPG أو PNG أو WEBP',
        'preview_as' => 'شكلها على الكارت',
        'save'       => 'حفظ الصورة',
        'cancel'     => 'إلغاء',
        'delete'     => 'حذف',
        'del_confirm'=> 'حذف هذه الصورة؟',
        'illus'      => 'صورة توضيحية',
        'f_saved'    => '✓ تم حفظ الصورة — ظهرت على كل السيارات المطابقة',
        'f_deleted'  => '✓ تم حذف الصورة',
        'f_missing'  => '⚠️ اختر الماركة والموديل',
        'f_badmodel' => '⚠️ الموديل غير موجود في النظام',
        'f_badcolor' => '⚠️ اللون غير موجود في النظام',
        'f_badyear'  => '⚠️ السنة غير صحيحة',
        'f_toobig'   => '⚠️ حجم الصورة كبير جداً',
        'f_notimage' => '⚠️ الملف ليس صورة صالحة',
        'f_badtype'  => '⚠️ نوع الصورة غير مدعوم — استخدم JPG أو PNG أو WEBP',
        'f_nofile'   => '⚠️ لم يتم اختيار صورة',
        'f_notwrite' => '⚠️ مجلد الصور غير قابل للكتابة على السيرفر',
        'f_upfail'   => '⚠️ تعذّر رفع الصورة، حاول مرة أخرى',
        'f_err'      => '⚠️ تعذّر تنفيذ العملية، حاول مرة أخرى',
        'schema_err' => '⚠️ تعذّر تجهيز جدول الصور في قاعدة البيانات',
    ],
    'en' => [
        'title'      => 'Car Image Library',
        'subtitle'   => 'One image per model and colour, shown automatically on every matching car',
        'dashboard'  => 'Dashboard',
        'add_image'  => '➕ Add image',
        'coverage'   => 'Stock coverage',
        'st_images'  => 'images in library',
        'st_models'  => 'models covered',
        'st_covered' => 'cars showing an image',
        'st_missing' => 'cars with no image',
        'work_title' => '🎯 Do these first',
        'work_sub'   => 'Model and colour combinations sitting in stock right now with no image, most cars first',
        'work_cars'  => 'cars',
        'work_done'  => '✅ Every car in stock has an image. The library is complete.',
        'all_brands' => 'All brands',
        'search_ph'  => 'Search by brand or model...',
        'filter'     => 'Filter',
        'reset'      => 'Reset',
        'in_stock'   => 'in stock',
        'no_models'  => 'No models yet',
        'no_models_s'=> 'Add models and vehicles first',
        'upload_t'   => 'Add image',
        'target'     => 'Image for',
        'model'      => 'Model',
        'color'      => 'Colour',
        'any_color'  => 'All colours (model fallback image)',
        'advanced'   => 'Narrow the scope (optional)',
        'adv_hint'   => 'Leave both empty unless the model genuinely looks different by year or trim',
        'year'       => 'Year',
        'trim'       => 'Trim',
        'any'        => 'Any',
        'drop_here'  => 'Drop an image here, or click to choose',
        'drop_hint'  => 'JPG, PNG or WEBP',
        'preview_as' => 'How it will look on a card',
        'save'       => 'Save image',
        'cancel'     => 'Cancel',
        'delete'     => 'Delete',
        'del_confirm'=> 'Delete this image?',
        'illus'      => 'Illustration',
        'f_saved'    => '✓ Image saved. It now shows on every matching car.',
        'f_deleted'  => '✓ Image deleted',
        'f_missing'  => '⚠️ Pick a brand and model',
        'f_badmodel' => '⚠️ That model does not exist in the system',
        'f_badcolor' => '⚠️ That colour does not exist in the system',
        'f_badyear'  => '⚠️ Invalid year',
        'f_toobig'   => '⚠️ That image is too large.',
        'f_notimage' => '⚠️ That file is not a valid image',
        'f_badtype'  => '⚠️ Unsupported image type. Use JPG, PNG or WEBP.',
        'f_nofile'   => '⚠️ No image was chosen',
        'f_notwrite' => '⚠️ The image folder is not writable on the server',
        'f_upfail'   => '⚠️ Could not upload the image, please try again',
        'f_err'      => '⚠️ Could not complete the action, please try again',
        'schema_err' => '⚠️ Could not prepare the image table in the database',
    ],
];
$L = $t[$lang];

$flashMap = [
    'saved'       => ['f_saved',    'ok'],
    'deleted'     => ['f_deleted',  'ok'],
    'missing'     => ['f_missing',  'warn'],
    'badmodel'    => ['f_badmodel', 'warn'],
    'badcolor'    => ['f_badcolor', 'warn'],
    'badyear'     => ['f_badyear',  'warn'],
    'toobig'      => ['f_toobig',   'warn'],
    'notimage'    => ['f_notimage', 'warn'],
    'badtype'     => ['f_badtype',  'warn'],
    'nofile'      => ['f_nofile',   'warn'],
    'notwritable' => ['f_notwrite', 'warn'],
    'uploadfail'  => ['f_upfail',   'warn'],
    'err'         => ['f_err',      'warn'],
];
$flashKey  = $_GET['flash'] ?? '';
$flashText = isset($flashMap[$flashKey]) ? $L[$flashMap[$flashKey][0]] : '';
if ($flashKey === 'toobig') {
    $flashText .= ' ' . ($isRTL ? 'الحد الأقصى ' : 'The limit is ') . car_images_max_label() . '.';
}
$flashKind = isset($flashMap[$flashKey]) ? $flashMap[$flashKey][1] : 'ok';

/* ─── Filters ─── */
$fBrand = trim($_GET['brand']  ?? '');
$search = trim($_GET['search'] ?? '');

/* ─── Reference data ─── */
$colors = [];
try {
    $colors = $pdo->query("SELECT color_en, color_ar FROM colors ORDER BY color_en")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('car images: colors failed: ' . $e->getMessage()); }

/* Every model the system knows: the catalog plus anything actually in stock. */
$models = [];
try {
    $models = $pdo->query("
        SELECT brand, model_name FROM (
            SELECT brand, model_name FROM models
            UNION
            SELECT brand, model AS model_name FROM cars
        ) m
        WHERE brand IS NOT NULL AND brand <> '' AND model_name IS NOT NULL AND model_name <> ''
        ORDER BY brand, model_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('car images: models failed: ' . $e->getMessage()); }

/* Which model+colour combinations are actually sitting in stock, and how many. */
$stockCombos = [];
try {
    $stockCombos = $pdo->query("
        SELECT brand, model, color, COUNT(*) AS cnt
        FROM cars
        WHERE status IN ('available','reserved','consignment')
        GROUP BY brand, model, color
        ORDER BY cnt DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('car images: stock combos failed: ' . $e->getMessage()); }

/* ─── The library itself ─── */
$images = [];
if ($schemaOk) {
    try {
        $images = $pdo->query("
            SELECT * FROM car_model_images ORDER BY brand, model_name, color_en
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('car images: library read failed: ' . $e->getMessage()); }
}
$imgMap = car_images_map($pdo);

/* Index the library by brand|model|colour for the grid tiles. A row scoped to
   any year and any trim is the one a tile represents. */
$byScope = [];
foreach ($images as $im) {
    $k = car_image_norm($im['brand']) . '|' . car_image_norm($im['model_name']) . '|' . car_image_norm($im['color_en']);
    if ($im['car_year'] === '' && $im['trim_name'] === '') {
        $byScope[$k] = $im;
    } elseif (!isset($byScope[$k])) {
        $byScope[$k] = $im;
    }
}

/* ─── Coverage: how many cars in stock actually resolve to an image ─── */
$carsCovered = 0;
$carsMissing = 0;
$worklist    = [];
foreach ($stockCombos as $sc) {
    $has = car_image_resolve($imgMap, [
        'brand' => $sc['brand'], 'model' => $sc['model'], 'color' => $sc['color'],
    ]);
    if ($has) {
        $carsCovered += (int)$sc['cnt'];
    } else {
        $carsMissing += (int)$sc['cnt'];
        $worklist[] = $sc;
    }
}
$carsTotal   = $carsCovered + $carsMissing;
$coveragePct = $carsTotal > 0 ? (int)round($carsCovered / $carsTotal * 100) : 0;

$modelsCovered = count(array_unique(array_map(
    fn($i) => car_image_norm($i['brand']) . '|' . car_image_norm($i['model_name']), $images
)));

/* ─── Group models by brand for the grid, honouring the filters ─── */
$brands = array_values(array_unique(array_map(fn($m) => $m['brand'], $models)));
sort($brands);

$grid = [];
foreach ($models as $m) {
    if ($fBrand !== '' && $m['brand'] !== $fBrand) continue;
    if ($search !== '' && mb_stripos($m['brand'] . ' ' . $m['model_name'], $search) === false) continue;
    $grid[$m['brand']][] = $m['model_name'];
}

/* Cars in stock per model, shown on each model card. */
$stockPerModel = [];
foreach ($stockCombos as $sc) {
    $k = car_image_norm($sc['brand']) . '|' . car_image_norm($sc['model']);
    $stockPerModel[$k] = ($stockPerModel[$k] ?? 0) + (int)$sc['cnt'];
}

/**
 * A display swatch for a colour name, so the tiles read at a glance even before
 * any image exists. Unknown names fall back to a neutral grey.
 */
function ci_swatch(string $colorEn): string
{
    static $map = [
        'white' => '#f8fafc', 'pearl white' => '#f8fafc', 'black' => '#111827',
        'silver' => '#cbd5e1', 'grey' => '#6b7280', 'gray' => '#6b7280',
        'red' => '#dc2626', 'blue' => '#2563eb', 'navy' => '#1e3a8a',
        'green' => '#16a34a', 'gold' => '#d4af37', 'beige' => '#e0d5c0',
        'brown' => '#78350f', 'orange' => '#ea580c', 'yellow' => '#eab308',
        'purple' => '#7c3aed', 'bronze' => '#a97142', 'champagne' => '#e6d7b8',
    ];
    $k = mb_strtolower(trim($colorEn));
    return $map[$k] ?? '#64748b';
}

function ciEsc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Attribute-safe JSON, for passing a value into an inline onclick handler. */
function ciJs($v): string
{
    return htmlspecialchars(json_encode($v, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
}

$colorLabelMap = [];
foreach ($colors as $c) {
    $colorLabelMap[$c['color_en']] = $isRTL ? ($c['color_ar'] ?: $c['color_en']) : $c['color_en'];
}

$other_lang = $lang === 'ar' ? 'en' : 'ar';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#020617">
<title><?= ciEsc($L['title']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root {
    --bg-deep:#020617; --bg-card:rgba(15,23,42,.93); --border:rgba(255,255,255,.07);
    --green:#22c55e; --green-d:rgba(34,197,94,.12);
    --purple:#9333ea; --purple-d:rgba(147,51,234,.12);
    --blue:#2563eb; --amber:#f59e0b; --amber-d:rgba(245,158,11,.12);
    --red:#ef4444; --red-d:rgba(239,68,68,.12);
    --text:#f1f5f9; --muted:#64748b; --muted-l:#94a3b8;
    --r-card:20px; --r-btn:12px; --shadow:0 4px 28px rgba(0,0,0,.45);
}
html[lang="ar"] body { font-family:'Cairo',sans-serif; }
html[lang="en"] body { font-family:'Inter',sans-serif; }
body {
    background:linear-gradient(150deg,#020617 0%,#0a0f1e 55%,#05101f 100%);
    color:var(--text); min-height:100vh; padding:16px 14px 90px; overflow-x:hidden;
}
.wrap { max-width:1180px; margin:0 auto; }

.topbar {
    display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;
    background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-card);
    padding:18px 22px; margin-bottom:14px; backdrop-filter:blur(20px); box-shadow:var(--shadow);
}
.topbar h1 { font-size:20px; font-weight:900; letter-spacing:-.01em; }
.topbar .sub { font-size:12.5px; color:var(--muted-l); font-weight:600; margin-top:4px; max-width:560px; line-height:1.6; }
.top-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.btn {
    border:none; cursor:pointer; font-family:inherit; font-weight:800; font-size:13px;
    padding:10px 16px; border-radius:var(--r-btn); color:#fff; text-decoration:none;
    display:inline-flex; align-items:center; gap:6px; transition:transform .15s, box-shadow .15s;
}
.btn:hover { transform:translateY(-2px); }
.btn-primary { background:linear-gradient(90deg,var(--purple),#a855f7); box-shadow:0 6px 22px rgba(147,51,234,.28); }
.btn-ghost { background:rgba(255,255,255,.06); border:1px solid var(--border); color:var(--muted-l); }
.btn-sm { padding:7px 12px; font-size:12px; border-radius:10px; }

.flash { border-radius:14px; padding:13px 18px; margin-bottom:14px; font-size:13.5px; font-weight:700; text-align:center; }
.flash.ok { background:var(--green-d); border:1px solid rgba(34,197,94,.35); color:#86efac; }
.flash.warn { background:var(--amber-d); border:1px solid rgba(245,158,11,.35); color:#fcd34d; }

/* coverage ring */
.cover-wrap { display:flex; align-items:center; gap:16px; }
.ring { position:relative; width:74px; height:74px; flex-shrink:0; }
.ring svg { transform:rotate(-90deg); }
.ring .bg { stroke:rgba(255,255,255,.07); }
.ring .fg { stroke:url(#ringGrad); stroke-linecap:round; transition:stroke-dashoffset 1s cubic-bezier(.22,1,.36,1); }
.ring .pct { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:16px; font-weight:900; color:var(--green); }
.cover-label { font-size:11.5px; font-weight:700; color:var(--muted-l); }
.cover-num { font-size:13px; font-weight:800; margin-top:2px; }

.stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin-bottom:14px; }
.stat { background:var(--bg-card); border:1px solid var(--border); border-radius:16px; padding:14px 16px; backdrop-filter:blur(20px); }
.stat .n { font-size:25px; font-weight:900; line-height:1.1; }
.stat .l { font-size:11.5px; font-weight:700; color:var(--muted-l); margin-top:4px; }
.stat.green .n { color:var(--green); } .stat.amber .n { color:var(--amber); }
.stat.purple .n { color:#c084fc; } .stat.blue .n { color:#60a5fa; }

/* worklist */
.work { background:var(--amber-d); border:1px solid rgba(245,158,11,.3); border-radius:var(--r-card); padding:17px 19px; margin-bottom:14px; }
.work.done { background:var(--green-d); border-color:rgba(34,197,94,.3); }
.work h3 { font-size:15px; font-weight:900; color:#fcd34d; margin-bottom:3px; }
.work.done h3 { color:#86efac; }
.work .s { font-size:12px; color:var(--muted-l); font-weight:600; margin-bottom:12px; line-height:1.6; }
.work-list { display:grid; grid-template-columns:repeat(auto-fill,minmax(250px,1fr)); gap:8px; }
.work-item { background:rgba(0,0,0,.28); border:1px solid var(--border); border-radius:12px; padding:10px 13px; display:flex; align-items:center; justify-content:space-between; gap:10px; }
.work-item.clickable { cursor:pointer; transition:border-color .18s, transform .18s; }
.work-item.clickable:hover { border-color:rgba(245,158,11,.5); transform:translateY(-2px); }
.work-item .wi-l { display:flex; align-items:center; gap:9px; min-width:0; }
.sw { width:20px; height:20px; border-radius:6px; border:1px solid rgba(255,255,255,.22); flex-shrink:0; }
.work-item .wi-name { font-size:12.5px; font-weight:800; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.work-item .wi-c { font-size:11px; color:var(--muted-l); font-weight:700; }
.badge-n { background:rgba(245,158,11,.2); color:#fcd34d; border-radius:50px; padding:3px 9px; font-size:11px; font-weight:900; white-space:nowrap; }

/* filters */
.filters { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-card); padding:14px 16px; margin-bottom:14px; backdrop-filter:blur(20px); }
.frow { display:flex; gap:8px; flex-wrap:wrap; }
.frow input, .frow select {
    flex:1; min-width:150px; background:#0d1526; border:1px solid var(--border); color:var(--text);
    padding:10px 12px; border-radius:10px; font-family:inherit; font-size:13.5px; outline:none;
}
.frow input:focus, .frow select:focus { border-color:rgba(147,51,234,.5); }

/* library grid */
.brand-h { font-size:15px; font-weight:900; margin:20px 0 10px; display:flex; align-items:center; gap:9px; }
.brand-h::after { content:''; flex:1; height:1px; background:var(--border); }
.models { display:grid; grid-template-columns:repeat(auto-fill,minmax(330px,1fr)); gap:12px; }
.mcard { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-card); padding:15px 17px; backdrop-filter:blur(20px); box-shadow:var(--shadow); }
.mcard-h { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:12px; }
.mcard-name { font-size:14.5px; font-weight:900; }
.mcard-stock { font-size:11px; font-weight:700; color:var(--muted-l); background:rgba(255,255,255,.05); border-radius:50px; padding:3px 10px; white-space:nowrap; }
.tiles { display:grid; grid-template-columns:repeat(auto-fill,minmax(88px,1fr)); gap:8px; }
.tile {
    position:relative; border-radius:12px; overflow:hidden; cursor:pointer;
    background:#0d1526; border:1px solid var(--border); aspect-ratio:4/3;
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    transition:transform .18s, border-color .18s, box-shadow .18s;
}
.tile:hover { transform:translateY(-3px); border-color:rgba(147,51,234,.5); box-shadow:0 8px 24px rgba(147,51,234,.16); }
.tile.empty { border-style:dashed; border-color:rgba(255,255,255,.14); }
.tile img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
.tile .t-label {
    position:absolute; inset-inline:0; bottom:0; padding:4px 6px;
    background:linear-gradient(transparent,rgba(2,6,23,.92));
    font-size:9.5px; font-weight:800; text-align:center;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap; z-index:2;
}
.tile.empty .t-plus { font-size:19px; color:var(--muted); font-weight:300; line-height:1; margin-bottom:4px; }
.tile.empty .t-label { background:none; color:var(--muted); position:static; padding:0 5px; }
.tile .t-del {
    position:absolute; top:4px; inset-inline-end:4px; z-index:3;
    width:21px; height:21px; border-radius:7px; border:none; cursor:pointer;
    background:rgba(2,6,23,.82); color:#fca5a5; font-size:11px; font-weight:900;
    opacity:0; transition:opacity .18s; display:flex; align-items:center; justify-content:center;
}
.tile:hover .t-del { opacity:1; }
.tile .t-sw { position:absolute; top:4px; inset-inline-start:4px; z-index:2; width:13px; height:13px; border-radius:4px; border:1px solid rgba(255,255,255,.35); }

.empty-state { text-align:center; padding:60px 20px; color:var(--muted); }
.empty-state .e-i { font-size:52px; margin-bottom:12px; }
.empty-state .e-t { font-size:16px; font-weight:800; color:var(--muted-l); margin-bottom:5px; }

/* modal */
.ov { position:fixed; inset:0; background:rgba(2,6,23,.85); backdrop-filter:blur(7px); z-index:900; display:none; align-items:center; justify-content:center; padding:18px; }
.ov.on { display:flex; }
.modal { width:100%; max-width:540px; max-height:92vh; overflow-y:auto; background:#0a1020; border:1px solid rgba(147,51,234,.25); border-radius:24px; padding:22px; box-shadow:0 24px 80px rgba(0,0,0,.85); }
.modal h2 { font-size:18px; font-weight:900; margin-bottom:14px; }
.target-chip { display:flex; align-items:center; gap:10px; background:var(--purple-d); border:1px solid rgba(147,51,234,.28); border-radius:14px; padding:11px 14px; margin-bottom:15px; }
.target-chip .tc-t { font-size:10.5px; font-weight:700; color:var(--muted-l); }
.target-chip .tc-v { font-size:14px; font-weight:900; margin-top:1px; }
.fld { margin-bottom:13px; }
.fld label { display:block; font-size:12.5px; font-weight:800; margin-bottom:6px; color:var(--muted-l); }
.fld select, .fld input { width:100%; background:#0d1526; border:1px solid var(--border); color:var(--text); padding:11px 13px; border-radius:11px; font-family:inherit; font-size:14px; outline:none; }
.fld select:focus, .fld input:focus { border-color:rgba(147,51,234,.5); }
.fld .hint { font-size:11px; color:var(--muted); font-weight:600; margin-top:5px; line-height:1.55; }
details.adv summary { cursor:pointer; font-size:12.5px; font-weight:800; color:var(--muted-l); margin-bottom:10px; list-style:none; }
details.adv summary::-webkit-details-marker { display:none; }

.drop { border:2px dashed rgba(255,255,255,.16); border-radius:16px; padding:26px 18px; text-align:center; cursor:pointer; transition:border-color .2s, background .2s; margin-bottom:13px; }
.drop:hover, .drop.over { border-color:var(--purple); background:var(--purple-d); }
.drop .d-i { font-size:34px; margin-bottom:8px; }
.drop .d-t { font-size:13px; font-weight:800; }
.drop .d-h { font-size:11px; color:var(--muted); font-weight:600; margin-top:5px; }
.drop.has { padding:0; border-style:solid; border-color:rgba(147,51,234,.35); overflow:hidden; }
.drop.has img { width:100%; display:block; max-height:230px; object-fit:cover; }

.cardprev { background:rgba(15,23,42,.88); border:1px solid var(--border); border-radius:18px; overflow:hidden; margin-bottom:13px; display:none; }
.cardprev.on { display:block; }
.cardprev .cp-img { position:relative; aspect-ratio:16/9; background:#0d1526; }
.cardprev .cp-img img { width:100%; height:100%; object-fit:cover; }
.cardprev .cp-tag { position:absolute; top:7px; inset-inline-start:7px; background:rgba(2,6,23,.75); color:var(--muted-l); font-size:9px; font-weight:800; padding:3px 7px; border-radius:6px; }
.cardprev .cp-b { padding:11px 14px; }
.cardprev .cp-n { font-size:13.5px; font-weight:900; }
.cardprev .cp-s { font-size:11px; color:var(--muted-l); font-weight:600; margin-top:2px; }
.cardprev .cp-l { font-size:10px; font-weight:800; color:var(--muted); padding:0 14px 9px; }

.modal-actions { display:flex; gap:8px; margin-top:6px; }
.modal-actions .btn { flex:1; justify-content:center; }

@media (max-width:560px) {
    .topbar h1 { font-size:17px; }
    .models { grid-template-columns:1fr; }
    .stat .n { font-size:21px; }
}
</style>
</head>
<body>
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <defs><linearGradient id="ringGrad" x1="0%" y1="0%" x2="100%" y2="100%">
    <stop offset="0%" stop-color="#22c55e"/><stop offset="100%" stop-color="#9333ea"/>
  </linearGradient></defs>
</svg>

<div class="wrap">

<div class="topbar">
    <div>
        <h1>🖼️ <?= ciEsc($L['title']) ?></h1>
        <div class="sub"><?= ciEsc($L['subtitle']) ?></div>
    </div>

    <div class="cover-wrap">
        <div class="ring">
            <svg width="74" height="74" viewBox="0 0 74 74">
                <circle class="bg" cx="37" cy="37" r="31" fill="none" stroke-width="7"></circle>
                <circle class="fg" cx="37" cy="37" r="31" fill="none" stroke-width="7"
                        stroke-dasharray="<?= (int)round(2 * M_PI * 31) ?>"
                        stroke-dashoffset="<?= (int)round(2 * M_PI * 31 * (1 - $coveragePct / 100)) ?>"></circle>
            </svg>
            <div class="pct"><?= $coveragePct ?>%</div>
        </div>
        <div>
            <div class="cover-label"><?= ciEsc($L['coverage']) ?></div>
            <div class="cover-num"><?= $carsCovered ?> / <?= $carsTotal ?></div>
        </div>
    </div>

    <div class="top-actions">
        <?php if ($canUpload): ?>
        <button type="button" class="btn btn-primary" onclick="openUpload('','','')"><?= ciEsc($L['add_image']) ?></button>
        <?php endif; ?>
        <a href="dashboard.php?lang=<?= $lang ?>" class="btn btn-ghost">🏠 <?= ciEsc($L['dashboard']) ?></a>
        <a href="car_images.php?lang=<?= $other_lang ?>" class="btn btn-ghost"><?= $isRTL ? 'EN' : 'ع' ?></a>
    </div>
</div>

<?php if (!$schemaOk): ?><div class="flash warn"><?= ciEsc($L['schema_err']) ?></div><?php endif; ?>
<?php if ($flashText !== ''): ?><div class="flash <?= ciEsc($flashKind) ?>"><?= ciEsc($flashText) ?></div><?php endif; ?>

<div class="stats">
    <div class="stat purple"><div class="n"><?= count($images) ?></div><div class="l"><?= ciEsc($L['st_images']) ?></div></div>
    <div class="stat blue"><div class="n"><?= $modelsCovered ?></div><div class="l"><?= ciEsc($L['st_models']) ?></div></div>
    <div class="stat green"><div class="n"><?= $carsCovered ?></div><div class="l"><?= ciEsc($L['st_covered']) ?></div></div>
    <div class="stat amber"><div class="n"><?= $carsMissing ?></div><div class="l"><?= ciEsc($L['st_missing']) ?></div></div>
</div>

<!-- ── The worklist: what is actually worth uploading, most cars first ── -->
<?php if (empty($worklist)): ?>
    <?php if ($carsTotal > 0): ?>
    <div class="work done"><h3><?= ciEsc($L['work_done']) ?></h3></div>
    <?php endif; ?>
<?php else: ?>
<div class="work">
    <h3><?= ciEsc($L['work_title']) ?></h3>
    <div class="s"><?= ciEsc($L['work_sub']) ?></div>
    <div class="work-list">
        <?php foreach (array_slice($worklist, 0, 24) as $w):
            $cl = $colorLabelMap[$w['color']] ?? $w['color'];
        ?>
        <div class="work-item <?= $canUpload ? 'clickable' : '' ?>"
             <?php if ($canUpload): ?>onclick="openUpload(<?= ciJs($w['brand']) ?>,<?= ciJs($w['model']) ?>,<?= ciJs($w['color']) ?>)"<?php endif; ?>>
            <div class="wi-l">
                <span class="sw" style="background:<?= ciEsc(ci_swatch((string)$w['color'])) ?>"></span>
                <div style="min-width:0">
                    <div class="wi-name"><?= ciEsc($w['brand'] . ' ' . $w['model']) ?></div>
                    <div class="wi-c"><?= ciEsc($cl) ?></div>
                </div>
            </div>
            <span class="badge-n"><?= (int)$w['cnt'] ?> <?= ciEsc($L['work_cars']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="filters">
    <form method="GET" action="car_images.php" class="frow">
        <input type="hidden" name="lang" value="<?= ciEsc($lang) ?>">
        <input type="text" name="search" value="<?= ciEsc($search) ?>" placeholder="<?= ciEsc($L['search_ph']) ?>">
        <select name="brand">
            <option value=""><?= ciEsc($L['all_brands']) ?></option>
            <?php foreach ($brands as $b): ?>
            <option value="<?= ciEsc($b) ?>" <?= $fBrand === $b ? 'selected' : '' ?>><?= ciEsc($b) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary"><?= ciEsc($L['filter']) ?></button>
        <a href="car_images.php?lang=<?= $lang ?>" class="btn btn-ghost"><?= ciEsc($L['reset']) ?></a>
    </form>
</div>

<!-- ── Library grid: a tile per model and colour ── -->
<?php if (empty($grid)): ?>
    <div class="empty-state">
        <div class="e-i">🖼️</div>
        <div class="e-t"><?= ciEsc($L['no_models']) ?></div>
        <div><?= ciEsc($L['no_models_s']) ?></div>
    </div>
<?php else: ?>
    <?php foreach ($grid as $brandName => $modelList): ?>
    <div class="brand-h"><?= ciEsc($brandName) ?></div>
    <div class="models">
        <?php foreach ($modelList as $modelName):
            $stockKey = car_image_norm($brandName) . '|' . car_image_norm($modelName);
            $stockN   = $stockPerModel[$stockKey] ?? 0;
        ?>
        <div class="mcard">
            <div class="mcard-h">
                <div class="mcard-name"><?= ciEsc($modelName) ?></div>
                <?php if ($stockN > 0): ?>
                    <span class="mcard-stock"><?= $stockN ?> <?= ciEsc($L['in_stock']) ?></span>
                <?php endif; ?>
            </div>
            <div class="tiles">
                <?php foreach ($colors as $c):
                    $ce  = $c['color_en'];
                    $lbl = $isRTL ? ($c['color_ar'] ?: $ce) : $ce;
                    $k   = car_image_norm($brandName) . '|' . car_image_norm($modelName) . '|' . car_image_norm($ce);
                    $im  = $byScope[$k] ?? null;
                    $url = $im ? car_image_url($im, true) : '';
                ?>
                <div class="tile <?= $url === '' ? 'empty' : '' ?>"
                     title="<?= ciEsc($modelName . ' — ' . $lbl) ?>"
                     <?php if ($canUpload): ?>onclick="openUpload(<?= ciJs($brandName) ?>,<?= ciJs($modelName) ?>,<?= ciJs($ce) ?>)"<?php endif; ?>>
                    <?php if ($url !== ''): ?>
                        <span class="t-sw" style="background:<?= ciEsc(ci_swatch($ce)) ?>"></span>
                        <img src="<?= ciEsc($url) ?>" alt="<?= ciEsc($modelName . ' ' . $lbl) ?>" loading="lazy">
                        <?php if ($canDelete): ?>
                        <button type="button" class="t-del" title="<?= ciEsc($L['delete']) ?>"
                                onclick="event.stopPropagation();delImage(<?= (int)$im['id'] ?>)">&#10005;</button>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="sw" style="background:<?= ciEsc(ci_swatch($ce)) ?>;width:15px;height:15px;margin-bottom:5px"></span>
                        <div class="t-plus">+</div>
                    <?php endif; ?>
                    <div class="t-label"><?= ciEsc($lbl) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

</div><!-- /wrap -->

<!-- ══════════ Upload modal ══════════ -->
<?php if ($canUpload): ?>
<div class="ov" id="ovUp" onclick="if(event.target===this) closeOv('ovUp')">
    <div class="modal">
        <h2>🖼️ <?= ciEsc($L['upload_t']) ?></h2>

        <div class="target-chip">
            <span class="sw" id="upSwatch" style="background:#64748b;width:26px;height:26px"></span>
            <div style="min-width:0">
                <div class="tc-t"><?= ciEsc($L['target']) ?></div>
                <div class="tc-v" id="upTarget">—</div>
            </div>
        </div>

        <form method="POST" action="car_image_action.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= ciEsc($csrfToken) ?>">
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="lang" value="<?= ciEsc($lang) ?>">

            <div class="fld">
                <label><?= ciEsc($L['model']) ?></label>
                <select id="upModel" required onchange="modelPicked()">
                    <option value="">—</option>
                    <?php foreach ($models as $mi => $m): ?>
                    <option value="<?= (int)$mi ?>"><?= ciEsc($m['brand'] . ' ' . $m['model_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <input type="hidden" name="brand"      id="upBrand">
            <input type="hidden" name="model_name" id="upModelName">

            <div class="fld">
                <label><?= ciEsc($L['color']) ?></label>
                <select name="color_en" id="upColor" onchange="refreshTarget()">
                    <option value=""><?= ciEsc($L['any_color']) ?></option>
                    <?php foreach ($colors as $c): ?>
                    <option value="<?= ciEsc($c['color_en']) ?>"><?= ciEsc($isRTL ? ($c['color_ar'] ?: $c['color_en']) : $c['color_en']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <details class="adv">
                <summary>⚙ <?= ciEsc($L['advanced']) ?></summary>
                <div class="fld">
                    <label><?= ciEsc($L['year']) ?></label>
                    <input type="text" name="car_year" inputmode="numeric" pattern="\d{4}" placeholder="<?= ciEsc($L['any']) ?>">
                </div>
                <div class="fld">
                    <label><?= ciEsc($L['trim']) ?></label>
                    <input type="text" name="trim_name" placeholder="<?= ciEsc($L['any']) ?>">
                    <div class="hint"><?= ciEsc($L['adv_hint']) ?></div>
                </div>
            </details>

            <div class="drop" id="drop" onclick="document.getElementById('upFile').click()">
                <div id="dropIdle">
                    <div class="d-i">📷</div>
                    <div class="d-t"><?= ciEsc($L['drop_here']) ?></div>
                    <div class="d-h"><?= ciEsc($L['drop_hint']) ?> &middot; <?= ciEsc($isRTL ? 'حتى ' . car_images_max_label() : 'up to ' . car_images_max_label()) ?></div>
                </div>
                <img id="dropPrev" style="display:none" alt="">
            </div>
            <input type="file" name="image" id="upFile" accept="image/jpeg,image/png,image/webp"
                   style="display:none" required onchange="filePicked(this)">

            <div class="cardprev" id="cardPrev">
                <div class="cp-img"><img id="cpImg" alt=""><span class="cp-tag"><?= ciEsc($L['illus']) ?></span></div>
                <div class="cp-b"><div class="cp-n" id="cpName">—</div><div class="cp-s" id="cpSub">—</div></div>
                <div class="cp-l"><?= ciEsc($L['preview_as']) ?></div>
            </div>

            <div class="modal-actions">
                <button type="submit" class="btn btn-primary"><?= ciEsc($L['save']) ?></button>
                <button type="button" class="btn btn-ghost" onclick="closeOv('ovUp')"><?= ciEsc($L['cancel']) ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canDelete): ?>
<form method="POST" action="car_image_action.php" id="delForm" style="display:none">
    <input type="hidden" name="csrf_token" value="<?= ciEsc($csrfToken) ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="lang" value="<?= ciEsc($lang) ?>">
    <input type="hidden" name="id" id="delId">
</form>
<?php endif; ?>

<script>
/* The model list, in the same order as the picker's options, so an option's
   index is all that has to travel through the DOM. */
const MODELS      = <?= json_encode(array_map(fn($m) => ['b' => $m['brand'], 'm' => $m['model_name']], $models), JSON_UNESCAPED_UNICODE) ?>;
const SWATCH      = <?= json_encode(array_reduce($colors, function ($acc, $c) { $acc[$c['color_en']] = ci_swatch($c['color_en']); return $acc; }, []), JSON_UNESCAPED_UNICODE) ?>;
const COLOR_LABEL = <?= json_encode($colorLabelMap, JSON_UNESCAPED_UNICODE) ?>;
const ANY_COLOR   = <?= json_encode($L['any_color'], JSON_UNESCAPED_UNICODE) ?>;
const DEL_CONFIRM = <?= json_encode($L['del_confirm'], JSON_UNESCAPED_UNICODE) ?>;

function openOv(id)  { const e = document.getElementById(id); if (e) e.classList.add('on'); }
function closeOv(id) { const e = document.getElementById(id); if (e) e.classList.remove('on'); }

/* Open the uploader already pointed at one model and colour, so clicking a
   worklist row or an empty tile needs no further typing. */
function openUpload(brand, model, color) {
    const mSel = document.getElementById('upModel');
    mSel.value = '';
    if (brand && model) {
        for (let i = 0; i < MODELS.length; i++) {
            if (MODELS[i].b === brand && MODELS[i].m === model) { mSel.value = String(i); break; }
        }
    }
    document.getElementById('upColor').value = color || '';
    resetFile();
    modelPicked();
    openOv('ovUp');
}

function modelPicked() {
    const v = document.getElementById('upModel').value;
    const m = (v !== '' && MODELS[Number(v)]) ? MODELS[Number(v)] : null;
    document.getElementById('upBrand').value     = m ? m.b : '';
    document.getElementById('upModelName').value = m ? m.m : '';
    refreshTarget();
}

function refreshTarget() {
    const brand  = document.getElementById('upBrand').value;
    const model  = document.getElementById('upModelName').value;
    const color  = document.getElementById('upColor').value;
    const name   = [brand, model].filter(Boolean).join(' ') || '—';
    const clabel = color ? (COLOR_LABEL[color] || color) : ANY_COLOR;

    document.getElementById('upTarget').textContent = name + (color ? ' · ' + clabel : '');
    document.getElementById('upSwatch').style.background = color ? (SWATCH[color] || '#64748b') : '#64748b';
    document.getElementById('cpName').textContent = name;
    document.getElementById('cpSub').textContent  = clabel;
}

function resetFile() {
    document.getElementById('upFile').value = '';
    document.getElementById('dropPrev').style.display = 'none';
    document.getElementById('dropIdle').style.display = '';
    document.getElementById('drop').classList.remove('has');
    document.getElementById('cardPrev').classList.remove('on');
}

function showFile(file) {
    if (!file || !file.type || file.type.indexOf('image/') !== 0) return;
    const url  = URL.createObjectURL(file);
    const prev = document.getElementById('dropPrev');
    prev.src = url;
    prev.style.display = 'block';
    document.getElementById('dropIdle').style.display = 'none';
    document.getElementById('drop').classList.add('has');
    document.getElementById('cpImg').src = url;
    document.getElementById('cardPrev').classList.add('on');
    refreshTarget();
}

function filePicked(input) { showFile(input.files && input.files[0]); }

/* Drag and drop straight onto the box */
(function () {
    const drop = document.getElementById('drop');
    if (!drop) return;
    ['dragenter', 'dragover'].forEach(function (ev) {
        drop.addEventListener(ev, function (e) { e.preventDefault(); e.stopPropagation(); drop.classList.add('over'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        drop.addEventListener(ev, function (e) { e.preventDefault(); e.stopPropagation(); drop.classList.remove('over'); });
    });
    drop.addEventListener('drop', function (e) {
        const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (!f) return;
        try {
            const dt = new DataTransfer();
            dt.items.add(f);
            document.getElementById('upFile').files = dt.files;
            showFile(f);
        } catch (err) {
            /* Older browsers can't assign to .files — fall back to the picker. */
            document.getElementById('upFile').click();
        }
    });
})();

function delImage(id) {
    if (!confirm(DEL_CONFIRM)) return;
    document.getElementById('delId').value = id;
    document.getElementById('delForm').submit();
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        const open = document.querySelectorAll('.ov.on');
        for (let i = 0; i < open.length; i++) open[i].classList.remove('on');
    }
});
</script>
</body>
</html>
