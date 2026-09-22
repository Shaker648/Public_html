<?php
/*
 * stock_report.php — تقرير المخزون / Stock Report
 *
 * An inventory map rather than a flat list. It answers "what do we have, how
 * many of each, and where" at a glance:
 *
 *   Matrix  — every model as a row, every colour as a column, counts in the
 *             cells. Tap a cell to see exactly which cars they are.
 *   Models  — a card per model with its photo from the image library, the
 *             total, a count per colour, and the split between branches.
 *   List    — the detailed table, one row per car, sortable.
 *
 * Every car shows how many days it has been in stock, colour-coded, because
 * stock age is the number that matters most on a stock report.
 *
 * All of the stock is loaded with two queries (the old page ran roughly one
 * per car), then filtering, the three views, the printed report and the Excel
 * export all happen instantly in the browser with no reloads.
 *
 * Old links still work: ?search= ?branch= ?brand= ?date_from= ?date_to= set the
 * starting filters, and ?print=1 opens the print dialog once the page is ready.
 */

require 'auth.php';
require 'config.php';
require 'car_images_helpers.php';

perm_require('page.stock_report');

$lang = $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar', 'en'], true)) $lang = 'ar';
$dir   = $lang === 'ar' ? 'rtl' : 'ltr';
$isRTL = $lang === 'ar';

$canSeeAmana = can('stock.amana');   // consignment section (default: admin / manager)

/* ─── Motivational quote (edit here) — shown on screen only, never printed ─── */
$quotes = [
    'ar' => ['text' => 'النجاح ليس نهاية الطريق، والفشل ليس نهاية المطاف — الشجاعة هي ما يهم.', 'author' => 'ونستون تشرشل'],
    'en' => ['text' => 'Success is not final, failure is not fatal — it is the courage to continue that counts.', 'author' => 'Winston Churchill'],
];
$quote = $quotes[$lang];

$t = [
    'ar' => [
        'title'        => 'تقرير المخزون',
        'subtitle'     => 'خريطة المخزون — ماذا لدينا، كم من كل موديل، وأين',
        'dashboard'    => 'الرئيسية',
        'prices'       => 'الأسعار',
        'print'        => 'طباعة التقرير',
        'excel'        => 'تصدير Excel',
        'k_total'      => 'إجمالي المخزون',
        'k_avail'      => 'متاحة',
        'k_res'        => 'محجوزة',
        'k_amana'      => 'أمانة',
        'k_avg'        => 'متوسط العمر',
        'k_old'        => 'أكثر من ٩٠ يوم',
        'day'          => 'يوم',
        'share'        => 'توزيع المخزون على الفروع',
        'share_hint'   => 'اضغط على فرع لعرضه فقط',
        'search_ph'    => 'ابحث بالماركة أو الموديل أو الشاسيه أو اللون أو الملاحظات...',
        'all'          => 'الكل',
        'all_branches' => 'كل الفروع',
        'all_brands'   => 'كل الماركات',
        'st_available' => 'متاحة',
        'st_reserved'  => 'محجوزة',
        'age_all'      => 'كل الأعمار',
        'age_new'      => 'أقل من ٣٠ يوم',
        'age_mid'      => '٣٠ – ٩٠ يوم',
        'age_old'      => 'أكثر من ٩٠ يوم',
        'from'         => 'وصلت من',
        'to'           => 'إلى',
        'reset'        => 'مسح الفلاتر',
        'v_matrix'     => 'المصفوفة',
        'v_models'     => 'الموديلات',
        'v_list'       => 'القائمة',
        'm_hint'       => 'كل خانة = عدد السيارات من هذا الموديل بهذا اللون. اضغط على أي رقم لرؤية السيارات نفسها.',
        'm_model'      => 'الموديل',
        'm_total'      => 'الإجمالي',
        'sort'         => 'ترتيب',
        'sort_branch'  => 'حسب الفرع',
        'sort_age'     => 'الأقدم أولاً',
        'sort_model'   => 'حسب الموديل',
        'c_num'        => '#',
        'c_car'        => 'السيارة',
        'c_year'       => 'السنة',
        'c_color'      => 'اللون',
        'c_chassis'    => 'الشاسيه',
        'c_age'        => 'في المخزون',
        'c_status'     => 'الحالة',
        'c_notes'      => 'ملاحظات',
        'c_branch'     => 'الفرع',
        'c_arrived'    => 'تاريخ الوصول',
        'c_brand'      => 'الماركة',
        'c_model'      => 'الموديل',
        'c_trim'       => 'الفئة',
        'empty'        => 'لا توجد سيارات تطابق الفلاتر',
        'amana_title'  => 'سيارات الأمانة',
        'dealer'       => 'التاجر',
        'salesman'     => 'البائع',
        'out_for'      => 'خارج منذ',
        'consignment'  => 'أمانة',
        'generated_by' => 'أعده',
        'generated_at' => 'بتاريخ',
        'copied'       => '✓ تم نسخ رقم الشاسيه',
        'timeline'     => 'رحلة السيارة',
        'cars'         => 'سيارة',
        'oldest'       => 'الأقدم',
        'avg'          => 'متوسط',
        'print_summary'=> 'الملخص',
        'print_matrix' => 'مصفوفة الموديلات والألوان',
        'print_detail' => 'التفاصيل حسب الفرع',
        'print_filters'=> 'الفلاتر المطبقة',
        'no_filters'   => 'بدون فلاتر — المخزون كاملاً',
        'close'        => 'إغلاق',
        'in_branches'  => 'التوزيع على الفروع',
    ],
    'en' => [
        'title'        => 'Stock Report',
        'subtitle'     => 'Your inventory map: what you have, how many of each, and where',
        'dashboard'    => 'Dashboard',
        'prices'       => 'Prices',
        'print'        => 'Print report',
        'excel'        => 'Export to Excel',
        'k_total'      => 'Total stock',
        'k_avail'      => 'Available',
        'k_res'        => 'Reserved',
        'k_amana'      => 'Consignment',
        'k_avg'        => 'Average age',
        'k_old'        => 'Over 90 days',
        'day'          => 'd',
        'share'        => 'Stock by branch',
        'share_hint'   => 'Tap a branch to show only that branch',
        'search_ph'    => 'Search brand, model, chassis, colour or notes...',
        'all'          => 'All',
        'all_branches' => 'All branches',
        'all_brands'   => 'All brands',
        'st_available' => 'Available',
        'st_reserved'  => 'Reserved',
        'age_all'      => 'Any age',
        'age_new'      => 'Under 30 days',
        'age_mid'      => '30 – 90 days',
        'age_old'      => 'Over 90 days',
        'from'         => 'Arrived from',
        'to'           => 'to',
        'reset'        => 'Clear filters',
        'v_matrix'     => 'Matrix',
        'v_models'     => 'Models',
        'v_list'       => 'List',
        'm_hint'       => 'Each cell is the number of cars of that model in that colour. Tap any number to see the cars themselves.',
        'm_model'      => 'Model',
        'm_total'      => 'Total',
        'sort'         => 'Sort',
        'sort_branch'  => 'By branch',
        'sort_age'     => 'Oldest first',
        'sort_model'   => 'By model',
        'c_num'        => '#',
        'c_car'        => 'Vehicle',
        'c_year'       => 'Year',
        'c_color'      => 'Colour',
        'c_chassis'    => 'Chassis',
        'c_age'        => 'In stock',
        'c_status'     => 'Status',
        'c_notes'      => 'Notes',
        'c_branch'     => 'Branch',
        'c_arrived'    => 'Arrived',
        'c_brand'      => 'Brand',
        'c_model'      => 'Model',
        'c_trim'       => 'Trim',
        'empty'        => 'No cars match these filters',
        'amana_title'  => 'Consignment vehicles',
        'dealer'       => 'Dealer',
        'salesman'     => 'Salesman',
        'out_for'      => 'Out for',
        'consignment'  => 'Consignment',
        'generated_by' => 'Prepared by',
        'generated_at' => 'Generated',
        'copied'       => '✓ Chassis number copied',
        'timeline'     => 'Vehicle journey',
        'cars'         => 'cars',
        'oldest'       => 'Oldest',
        'avg'          => 'Avg',
        'print_summary'=> 'Summary',
        'print_matrix' => 'Model and colour matrix',
        'print_detail' => 'Detail by branch',
        'print_filters'=> 'Filters applied',
        'no_filters'   => 'No filters, the whole stock',
        'close'        => 'Close',
        'in_branches'  => 'Across branches',
    ],
];
$L = $t[$lang];

/* ─── Starting filters from the URL, so old links and bookmarks keep working ─── */
$init = [
    'q'      => trim((string)($_GET['search']    ?? '')),
    'branch' => trim((string)($_GET['branch']    ?? '')),
    'brand'  => trim((string)($_GET['brand']     ?? '')),
    'from'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_from'] ?? '')) ? $_GET['date_from'] : '',
    'to'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_to']   ?? '')) ? $_GET['date_to']   : '',
];
$autoPrint = ($_GET['print'] ?? '') === '1';

/** A display swatch for a colour name. Unknown names fall back to neutral grey. */
function sr_swatch(string $colorEn): string
{
    static $map = [
        'white' => '#f8fafc', 'pearl white' => '#f1f5f9', 'snow white' => '#f8fafc', 'black' => '#111827',
        'silver' => '#cbd5e1', 'grey' => '#6b7280', 'gray' => '#6b7280', 'dark grey' => '#374151',
        'dark gray' => '#374151', 'titanium' => '#8b8f97', 'red' => '#dc2626', 'dark red' => '#991b1b',
        'blue' => '#2563eb', 'dark blue' => '#1e3a8a', 'navy' => '#1e3a8a', 'sky blue' => '#38bdf8',
        'green' => '#16a34a', 'dark green' => '#14532d', 'gold' => '#d4af37', 'beige' => '#e0d5c0',
        'brown' => '#78350f', 'orange' => '#ea580c', 'yellow' => '#eab308', 'purple' => '#7c3aed',
        'bronze' => '#a97142', 'champagne' => '#e6d7b8', 'cream' => '#f5efdc',
    ];
    return $map[mb_strtolower(trim($colorEn))] ?? '#64748b';
}

/* ═══════════════════ Data: two queries for the whole page ═══════════════════
   Colours and branches are joined once instead of being looked up per row.
   The colour table is collapsed per name first, so a duplicate colour row can
   never duplicate a car. */
$imgMap = car_images_map($pdo);

$stock = [];
try {
    $rows = $pdo->query("
        SELECT c.id, c.brand, c.model, c.trim_name, c.car_year, c.color, c.chassis,
               c.branch, c.status, c.notes, c.created_at,
               DATEDIFF(NOW(), c.created_at) AS age_days,
               col.color_ar, b.name_ar AS b_ar, b.name_en AS b_en
        FROM cars c
        LEFT JOIN (SELECT color_en, MAX(color_ar) AS color_ar FROM colors GROUP BY color_en) col
               ON col.color_en = c.color
        LEFT JOIN branches b ON b.name = c.branch
        WHERE c.status IN ('available','reserved')
        ORDER BY c.branch, c.brand, c.model, c.trim_name, c.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $color = (string)$r['color'];
        $stock[] = [
            'id'      => (int)$r['id'],
            'brand'   => (string)$r['brand'],
            'model'   => (string)$r['model'],
            'trim'    => (string)$r['trim_name'],
            'year'    => (string)$r['car_year'],
            'color'   => $color,
            'colorL'  => $isRTL ? ((string)$r['color_ar'] ?: $color) : $color,
            'sw'      => sr_swatch($color),
            'branch'  => (string)$r['branch'],
            'branchL' => $isRTL ? ((string)$r['b_ar'] ?: (string)$r['branch']) : ((string)$r['b_en'] ?: (string)$r['branch']),
            'chassis' => (string)$r['chassis'],
            'notes'   => trim((string)$r['notes']),
            'status'  => $r['status'] === 'reserved' ? 'reserved' : 'available',
            'age'     => $r['age_days'] === null ? null : max(0, (int)$r['age_days']),
            'date'    => $r['created_at'] ? date('Y-m-d', strtotime($r['created_at'])) : '',
            'img'     => car_image_url_for($imgMap, [
                'brand' => $r['brand'], 'model' => $r['model'], 'trim_name' => $r['trim_name'],
                'car_year' => $r['car_year'], 'color' => $color,
            ], true),
        ];
    }
} catch (Throwable $e) {
    error_log('stock report: stock query failed: ' . $e->getMessage());
}

$amana = [];
if ($canSeeAmana) {
    try {
        $rows = $pdo->query("
            SELECT c.id, c.brand, c.model, c.trim_name, c.car_year, c.color, c.chassis,
                   c.branch, c.notes, c.created_at,
                   DATEDIFF(NOW(), c.created_at) AS age_days,
                   cn.dealer_name, cn.salesman, cn.started_at,
                   DATEDIFF(NOW(), cn.started_at) AS out_days,
                   col.color_ar, b.name_ar AS b_ar, b.name_en AS b_en
            FROM cars c
            LEFT JOIN consignments cn ON cn.car_id = c.id AND cn.status = 'active'
            LEFT JOIN (SELECT color_en, MAX(color_ar) AS color_ar FROM colors GROUP BY color_en) col
                   ON col.color_en = c.color
            LEFT JOIN branches b ON b.name = c.branch
            WHERE c.status = 'consignment'
            ORDER BY cn.started_at, c.id
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $color = (string)$r['color'];
            $amana[] = [
                'id'       => (int)$r['id'],
                'brand'    => (string)$r['brand'],
                'model'    => (string)$r['model'],
                'trim'     => (string)$r['trim_name'],
                'year'     => (string)$r['car_year'],
                'color'    => $color,
                'colorL'   => $isRTL ? ((string)$r['color_ar'] ?: $color) : $color,
                'sw'       => sr_swatch($color),
                'branch'   => (string)$r['branch'],
                'branchL'  => $isRTL ? ((string)$r['b_ar'] ?: (string)$r['branch']) : ((string)$r['b_en'] ?: (string)$r['branch']),
                'chassis'  => (string)$r['chassis'],
                'notes'    => trim((string)$r['notes']),
                'status'   => 'consignment',
                'age'      => $r['age_days'] === null ? null : max(0, (int)$r['age_days']),
                'date'     => $r['created_at'] ? date('Y-m-d', strtotime($r['created_at'])) : '',
                'dealer'   => (string)($r['dealer_name'] ?? ''),
                'salesman' => (string)($r['salesman'] ?? ''),
                'since'    => $r['started_at'] ? date('Y-m-d', strtotime($r['started_at'])) : '',
                'out'      => $r['out_days'] === null ? null : max(0, (int)$r['out_days']),
            ];
        }
    } catch (Throwable $e) {
        error_log('stock report: consignment query failed: ' . $e->getMessage());
    }
}

/* JSON that is safe to drop straight into a <script> block */
function sr_json($v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

function sr_esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$other_lang = $isRTL ? 'en' : 'ar';
$langQs     = http_build_query(array_filter([
    'search' => $init['q'], 'branch' => $init['branch'], 'brand' => $init['brand'],
    'date_from' => $init['from'], 'date_to' => $init['to'],
], fn($v) => $v !== ''));
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#020617">
<title><?= sr_esc($L['title']) ?> — First 1 Car</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root {
    --bg-card:#0f172aeb; --bg-soft:#0b1324; --border:rgba(255,255,255,.07); --border-2:rgba(255,255,255,.11);
    --green:#22c55e; --amber:#f59e0b; --red:#ef4444; --gold:#eab308; --purple:#9333ea; --blue:#3b82f6;
    --text:#f1f5f9; --muted:#64748b; --muted-l:#94a3b8;
    --shadow:0 4px 28px rgba(0,0,0,.45);
}
html[lang="ar"] body { font-family:'Cairo',sans-serif; }
html[lang="en"] body { font-family:'Inter',sans-serif; }
body {
    background:
        radial-gradient(ellipse 60% 40% at 90% -5%, rgba(147,51,234,.14), transparent 70%),
        radial-gradient(ellipse 50% 35% at 5% 0%, rgba(34,197,94,.10), transparent 70%),
        linear-gradient(150deg,#020617 0%,#0a0f1e 55%,#05101f 100%);
    background-attachment:fixed;
    color:var(--text); min-height:100vh; padding:16px 14px 70px; overflow-x:hidden;
}
.wrap { max-width:1320px; margin:0 auto; }
button { font-family:inherit; }
.num { font-variant-numeric:tabular-nums; }

/* ── header ── */
.header {
    display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;
    background:var(--bg-card); border:1px solid var(--border); border-radius:22px;
    padding:16px 20px; margin-bottom:12px; box-shadow:var(--shadow); backdrop-filter:blur(18px);
}
.brandline { display:flex; align-items:center; gap:13px; min-width:0; }
.brandline img { width:44px; height:44px; border-radius:12px; object-fit:contain; flex-shrink:0; }
.h-title { font-size:21px; font-weight:900; letter-spacing:-.01em; }
.h-sub { font-size:12px; color:var(--muted-l); font-weight:600; margin-top:2px; }
.h-actions { display:flex; gap:7px; flex-wrap:wrap; align-items:center; }
.hbtn {
    display:inline-flex; align-items:center; gap:6px; height:38px; padding:0 13px; border-radius:11px;
    font-size:12.5px; font-weight:800; text-decoration:none; color:#fff; border:1px solid transparent;
    cursor:pointer; transition:transform .15s, box-shadow .2s; white-space:nowrap;
}
.hbtn:hover { transform:translateY(-2px); }
.hbtn.ghost { background:rgba(255,255,255,.05); border-color:var(--border-2); color:#cbd5e1; }
.hbtn.print { background:linear-gradient(90deg,#7c3aed,#a855f7); box-shadow:0 6px 20px rgba(147,51,234,.3); }
.hbtn.xls   { background:linear-gradient(90deg,#15803d,#22c55e); box-shadow:0 6px 20px rgba(34,197,94,.25); color:#022c14; }

.quote-line {
    display:flex; align-items:center; gap:9px; padding:7px 14px; margin-bottom:12px;
    background:rgba(147,51,234,.07); border:1px solid rgba(147,51,234,.16); border-radius:12px;
    font-size:12.5px; color:#c4b5fd; font-weight:600;
}
.quote-line .qt { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.quote-line .qa { color:#8b7fc9; font-size:11.5px; white-space:nowrap; }

/* ── KPIs ── */
.kpis { display:grid; grid-template-columns:repeat(6,1fr); gap:10px; margin-bottom:12px; }
.kpi {
    position:relative; overflow:hidden; background:var(--bg-card); border:1px solid var(--border);
    border-radius:18px; padding:14px 16px 13px; box-shadow:var(--shadow);
}
.kpi::after { content:''; position:absolute; inset-inline:0; bottom:0; height:3px; background:var(--k,#94a3b8); opacity:.85; }
.kpi .k-n { font-size:30px; font-weight:900; line-height:1.05; color:var(--k,#f1f5f9); }
.kpi .k-n small { font-size:13px; font-weight:800; color:var(--muted-l); margin-inline-start:3px; }
.kpi .k-l { font-size:11.5px; font-weight:800; color:var(--muted-l); margin-top:5px; }
.kpi.total { --k:#f1f5f9; } .kpi.avail { --k:var(--green); } .kpi.res { --k:var(--gold); }
.kpi.amana { --k:var(--amber); } .kpi.avg { --k:#38bdf8; } .kpi.old { --k:var(--red); }
.kpi.old.zero { --k:var(--green); }
.kpi.tap { cursor:pointer; transition:border-color .2s, transform .15s; }
.kpi.tap:hover { transform:translateY(-2px); border-color:rgba(147,51,234,.4); }
.kpi.tap.on { border-color:rgba(147,51,234,.7); background:rgba(147,51,234,.1); }

/* ── branch share bar ── */
.share {
    background:var(--bg-card); border:1px solid var(--border); border-radius:18px;
    padding:13px 16px 12px; margin-bottom:12px; box-shadow:var(--shadow);
}
.share-h { display:flex; justify-content:space-between; align-items:baseline; gap:10px; margin-bottom:9px; }
.share-t { font-size:13px; font-weight:900; }
.share-hint { font-size:11px; color:var(--muted); font-weight:600; }
.share-bar { display:flex; height:16px; border-radius:50px; overflow:hidden; gap:2px; background:rgba(255,255,255,.04); }
.share-seg { height:100%; cursor:pointer; transition:filter .2s, opacity .25s, flex-grow .45s cubic-bezier(.22,1,.36,1); min-width:6px; }
.share-seg:hover { filter:brightness(1.25); }
.share.filtering .share-seg:not(.on) { opacity:.28; }
.share-legend { display:flex; flex-wrap:wrap; gap:6px 14px; margin-top:9px; }
.share-lg { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; color:#cbd5e1; cursor:pointer; }
.share-lg i { width:10px; height:10px; border-radius:3px; display:inline-block; }
.share-lg b { font-weight:900; color:#fff; }
.share-lg em { font-style:normal; color:var(--muted); font-weight:700; }

/* ── sticky control band ── */
.band {
    position:sticky; top:0; z-index:50; margin:0 -14px 12px; padding:10px 14px 8px;
    background:rgba(2,6,23,.94); backdrop-filter:blur(16px); border-bottom:1px solid var(--border);
}
.band.stuck { box-shadow:0 10px 30px rgba(0,0,0,.45); }
.band-top { display:flex; gap:9px; align-items:center; flex-wrap:wrap; }
.search {
    flex:1 1 280px; min-width:0; display:flex; align-items:center; gap:9px; height:44px; padding:0 13px;
    background:rgba(15,23,42,.9); border:1px solid var(--border-2); border-radius:13px;
    transition:border-color .2s, box-shadow .2s;
}
.search:focus-within { border-color:rgba(147,51,234,.55); box-shadow:0 0 0 3px rgba(147,51,234,.12); }
.search input { flex:1; min-width:0; background:none; border:none; outline:none; color:var(--text); font-family:inherit; font-size:14px; }
.search .x { display:none; width:22px; height:22px; border-radius:50%; border:none; background:rgba(255,255,255,.08); color:var(--muted-l); cursor:pointer; font-size:11px; }
.search.has .x { display:block; }
.dates { display:flex; align-items:center; gap:6px; flex-wrap:nowrap; }
.dates label { font-size:11px; font-weight:800; color:var(--muted-l); white-space:nowrap; }
.dates input {
    height:44px; background:rgba(15,23,42,.9); border:1px solid var(--border-2); border-radius:12px;
    color:var(--text); padding:0 9px; font-family:inherit; font-size:12.5px; color-scheme:dark;
}
.reset-btn {
    height:44px; padding:0 13px; border-radius:12px; border:1px solid var(--border-2); cursor:pointer;
    background:rgba(255,255,255,.04); color:var(--muted-l); font-size:12px; font-weight:800; white-space:nowrap;
}
.reset-btn:hover { color:#fff; }
.more-btn {
    display:none; position:relative; width:42px; height:42px; flex-shrink:0; border-radius:12px; cursor:pointer;
    border:1px solid var(--border-2); background:rgba(255,255,255,.04); color:#cbd5e1; font-size:16px;
}
.more-btn .mdot { display:none; position:absolute; top:7px; inset-inline-end:7px; width:8px; height:8px; border-radius:50%; background:var(--purple); }
.band.dated .more-btn .mdot { display:block; }
.band.more .more-btn { background:rgba(147,51,234,.18); border-color:rgba(147,51,234,.5); }
.chips { display:flex; gap:6px; align-items:center; margin-top:8px; overflow-x:auto; scrollbar-width:none; padding-bottom:2px; }
.chips::-webkit-scrollbar { display:none; }
.chip {
    flex-shrink:0; height:31px; padding:0 12px; border-radius:50px; cursor:pointer;
    background:rgba(255,255,255,.05); border:1px solid var(--border-2); color:var(--muted-l);
    font-size:12px; font-weight:800; display:inline-flex; align-items:center; gap:6px;
    transition:background .2s, color .2s, border-color .2s;
}
.chip:hover { color:#e2e8f0; background:rgba(255,255,255,.09); }
.chip.on { background:var(--purple); border-color:transparent; color:#fff; }
.chip .n { font-size:10.5px; opacity:.75; }
.chip .dot { width:9px; height:9px; border-radius:50%; }
.sep { flex-shrink:0; width:1px; height:18px; background:rgba(255,255,255,.13); margin:0 3px; }

/* ── view switcher ── */
.viewbar { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-bottom:12px; }
.seg {
    display:inline-flex; padding:4px; gap:3px; border-radius:14px;
    background:rgba(15,23,42,.9); border:1px solid var(--border-2);
}
.seg button {
    height:36px; padding:0 15px; border-radius:10px; border:none; cursor:pointer;
    background:none; color:var(--muted-l); font-size:13px; font-weight:800;
    display:inline-flex; align-items:center; gap:7px; transition:background .2s, color .2s;
}
.seg button.on { background:linear-gradient(90deg,#7c3aed,#a855f7); color:#fff; box-shadow:0 4px 14px rgba(147,51,234,.35); }
.result { font-size:12.5px; color:var(--muted-l); font-weight:700; }
.result b { color:#fff; font-size:15px; }
.sortsel {
    height:36px; background:rgba(15,23,42,.9); border:1px solid var(--border-2); border-radius:10px;
    color:var(--text); padding:0 10px; font-family:inherit; font-size:12.5px; font-weight:700;
}

/* ── panel shell ── */
.panel { background:var(--bg-card); border:1px solid var(--border); border-radius:20px; box-shadow:var(--shadow); overflow:hidden; }
.panel-hint { font-size:11.5px; color:var(--muted); font-weight:600; padding:11px 16px 0; }
.empty { text-align:center; padding:60px 20px; color:var(--muted); }
.empty .ei { font-size:44px; margin-bottom:10px; }
.empty .et { font-size:15px; font-weight:800; color:var(--muted-l); }

/* ── MATRIX ── */
.mx-scroll { overflow-x:auto; padding:10px 0 4px; }
.mx { border-collapse:separate; border-spacing:0; min-width:100%; }
.mx th, .mx td { white-space:nowrap; }
.mx thead th {
    position:sticky; top:0; z-index:2; background:#0c1426; padding:10px 6px 9px;
    font-size:11px; font-weight:800; color:var(--muted-l); border-bottom:1px solid var(--border);
    text-align:center; vertical-align:bottom;
}
.mx thead .colh { display:flex; flex-direction:column; align-items:center; gap:5px; min-width:58px; }
.mx thead .colh i { width:18px; height:18px; border-radius:6px; border:1px solid rgba(255,255,255,.25); display:block; }
.mx .rowh {
    position:sticky; inset-inline-start:0; z-index:1; background:#0c1426;
    text-align:start; padding:9px 16px; border-bottom:1px solid var(--border); min-width:210px; cursor:pointer;
}
.mx thead .rowh { z-index:3; vertical-align:bottom; }
.mx .rowh:hover .rn { color:#c4b5fd; }
.mx .rn { font-size:13.5px; font-weight:900; transition:color .2s; }
.mx .rm { font-size:11px; color:var(--muted); font-weight:700; margin-top:2px; display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
.mx td.cell { text-align:center; padding:5px 4px; border-bottom:1px solid var(--border); }
.mx .cb {
    width:48px; height:36px; margin:0 auto; border-radius:10px; border:none; cursor:pointer;
    display:flex; align-items:center; justify-content:center; font-size:15px; font-weight:900; color:#fff;
    background:rgba(147,51,234,var(--a,.2)); transition:transform .15s, box-shadow .2s;
    font-family:inherit;
}
.mx .cb:hover { transform:scale(1.1); box-shadow:0 6px 18px rgba(147,51,234,.4); }
.mx .cb.hasres { box-shadow:inset 0 0 0 2px rgba(234,179,8,.8); }
.mx .cb.hasold::after { content:''; position:absolute; }
.mx .zero { color:rgba(255,255,255,.12); font-size:13px; font-weight:700; }
.mx td.tot { text-align:center; padding:5px 12px; border-bottom:1px solid var(--border); font-size:15px; font-weight:900; }
.mx tr.brandrow td {
    background:rgba(147,51,234,.07); padding:7px 16px; font-size:11.5px; font-weight:900;
    color:#c4b5fd; letter-spacing:.03em; border-bottom:1px solid var(--border);
}
.mx tr.brandrow td:first-child { position:sticky; inset-inline-start:0; z-index:1; }
.mx tfoot td { padding:10px 6px; font-size:13px; font-weight:900; text-align:center; background:#0c1426; border-top:1px solid var(--border-2); }
.mx tfoot td.rowh { font-size:12px; color:var(--muted-l); }
.mx tfoot td.grand { color:#c4b5fd; font-size:17px; }
.mx-legend { display:flex; gap:14px; flex-wrap:wrap; padding:6px 16px 13px; font-size:11px; color:var(--muted); font-weight:700; }
.mx-legend span { display:inline-flex; align-items:center; gap:6px; }
.mx-legend i { width:16px; height:12px; border-radius:4px; display:inline-block; }

/* ── age pill ── */
.age { display:inline-flex; align-items:center; gap:4px; height:22px; padding:0 8px; border-radius:50px; font-size:11px; font-weight:900; white-space:nowrap; }
.age.g { background:rgba(34,197,94,.14); color:#4ade80; border:1px solid rgba(34,197,94,.3); }
.age.a { background:rgba(245,158,11,.14); color:#fbbf24; border:1px solid rgba(245,158,11,.32); }
.age.r { background:rgba(239,68,68,.14); color:#f87171; border:1px solid rgba(239,68,68,.32); }
.age.n { background:rgba(255,255,255,.05); color:var(--muted); border:1px solid var(--border); }

/* ── MODELS ── */
.mgrid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:12px; padding:14px; }
.mcard {
    position:relative; background:rgba(11,19,36,.9); border:1px solid var(--border); border-radius:20px;
    padding:12px; cursor:pointer; transition:transform .2s, border-color .2s, box-shadow .25s;
    display:flex; flex-direction:column; gap:11px;
}
.mcard:hover { transform:translateY(-4px); border-color:rgba(147,51,234,.4); box-shadow:0 14px 38px rgba(147,51,234,.14); }
.stage {
    position:relative; height:128px; border-radius:15px; overflow:hidden; isolation:isolate;
    border:1px solid rgba(255,255,255,.06);
    background:
        radial-gradient(ellipse 70% 95% at 50% 112%, rgba(147,51,234,.26), transparent 70%),
        radial-gradient(ellipse 55% 70% at 50% -10%, rgba(255,255,255,.08), transparent 70%),
        linear-gradient(180deg,#101b33 0%,#0a1222 100%);
}
.stage::before { content:''; position:absolute; left:20%; right:20%; bottom:10px; height:12px; z-index:0; background:radial-gradient(ellipse at center, rgba(0,0,0,.6), transparent 70%); }
.stage img {
    position:relative; z-index:1; display:block; width:100%; height:100%; padding:12px 18px 14px;
    object-fit:contain; object-position:center 88%; filter:drop-shadow(0 6px 9px rgba(0,0,0,.4));
    opacity:0; transform:translateY(6px); transition:opacity .45s ease, transform .5s cubic-bezier(.22,1,.36,1);
}
.stage.ready img { opacity:1; transform:none; }
.mcard:hover .stage.ready img { transform:translateY(-3px) scale(1.045); }
.stage.studio { border-color:rgba(255,255,255,.2); background:radial-gradient(ellipse 85% 60% at 50% 104%, #cbd5e1, transparent 72%), linear-gradient(180deg,#fff 0%,#eef2f7 62%,#e2e8f0 100%); }
.stage.studio img { mix-blend-mode:multiply; filter:none; }
.stage.studio::before { background:radial-gradient(ellipse at center, rgba(15,23,42,.32), transparent 70%); }
.stage.scene img { object-fit:cover; object-position:center 58%; padding:0; filter:none; }
.stage.scene::before { display:none; }
.stage.matte { background:var(--stage-bg,#0a1222); }
.stage .ph { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:46px; opacity:.28; z-index:1; }
.stage .big {
    position:absolute; top:9px; inset-inline-end:10px; z-index:3; min-width:44px; height:44px; padding:0 9px;
    border-radius:14px; display:flex; flex-direction:column; align-items:center; justify-content:center;
    background:rgba(2,6,23,.78); backdrop-filter:blur(6px); border:1px solid rgba(255,255,255,.14);
    font-size:20px; font-weight:900; line-height:1; color:#fff;
}
.stage .big small { font-size:8.5px; font-weight:800; color:var(--muted-l); margin-top:2px; }
.stage.studio .big { background:rgba(255,255,255,.86); color:#0f172a; border-color:rgba(15,23,42,.1); }
.stage.studio .big small { color:#64748b; }
.stage .resb {
    position:absolute; top:9px; inset-inline-start:10px; z-index:3; height:22px; padding:0 8px; border-radius:50px;
    background:rgba(234,179,8,.9); color:#1f1600; font-size:10.5px; font-weight:900; display:flex; align-items:center;
}
.mc-name { font-size:16px; font-weight:900; line-height:1.25; }
.mc-name span { color:var(--muted-l); font-weight:700; font-size:12.5px; }
.mc-trims { display:flex; gap:5px; flex-wrap:wrap; margin-top:5px; }
.mc-trims em { font-style:normal; font-size:10.5px; font-weight:800; color:#c4b5fd; background:rgba(147,51,234,.12); border:1px solid rgba(147,51,234,.25); border-radius:50px; padding:2px 8px; }
.dots { display:flex; flex-wrap:wrap; gap:6px; }
.dotc { display:inline-flex; align-items:center; gap:5px; height:26px; padding:0 9px 0 5px; border-radius:50px; background:rgba(255,255,255,.04); border:1px solid var(--border); font-size:12px; font-weight:900; }
.dotc i { width:16px; height:16px; border-radius:50%; border:1px solid rgba(255,255,255,.3); display:block; }
.minibar { display:flex; height:7px; border-radius:50px; overflow:hidden; gap:2px; background:rgba(255,255,255,.04); }
.minibar b { display:block; height:100%; }
.mc-foot { display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; font-size:11px; color:var(--muted-l); font-weight:700; }
.mc-branches { display:flex; gap:9px; flex-wrap:wrap; }
.mc-branches span { display:inline-flex; align-items:center; gap:4px; }
.mc-branches i { width:8px; height:8px; border-radius:2px; display:inline-block; }

/* ── LIST ── */
.lt { width:100%; border-collapse:collapse; }
.lt th {
    position:sticky; top:0; z-index:2; background:#0c1426; text-align:start; padding:10px 12px;
    font-size:11px; font-weight:800; color:var(--muted-l); border-bottom:1px solid var(--border); white-space:nowrap;
}
.lt td { padding:10px 12px; border-bottom:1px solid var(--border); font-size:13px; vertical-align:middle; }
.lt tr.gh td {
    background:linear-gradient(90deg, rgba(147,51,234,.14), rgba(147,51,234,.02)); padding:10px 14px;
    font-size:13px; font-weight:900; color:#e9d5ff;
}
.lt tr.gh td .ghn { color:var(--muted-l); font-weight:800; font-size:12px; margin-inline-start:8px; }
.lt tr.row:hover td { background:rgba(255,255,255,.025); }
.lt tr.row.res td { background:rgba(234,179,8,.05); }
.lt tr.row.res td:first-child { box-shadow:inset 3px 0 0 var(--gold); }
html[dir="rtl"] .lt tr.row.res td:first-child { box-shadow:inset -3px 0 0 var(--gold); }
.lt .n { color:var(--muted); font-weight:800; font-size:12px; width:36px; }
.lt .car b { font-weight:900; }
.lt .car span { display:block; font-size:11.5px; color:var(--muted-l); font-weight:700; margin-top:1px; }
.sw { display:inline-flex; align-items:center; gap:7px; font-weight:700; white-space:nowrap; }
.sw i { width:14px; height:14px; border-radius:50%; border:1px solid rgba(255,255,255,.3); display:inline-block; flex-shrink:0; }
.chs { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:12.5px; color:#a3e635; cursor:pointer; border:none; background:none; padding:0; letter-spacing:.02em; }
.chs:hover { text-decoration:underline; }
.stb { display:inline-flex; align-items:center; height:22px; padding:0 9px; border-radius:50px; font-size:11px; font-weight:900; white-space:nowrap; }
.stb.available { background:rgba(34,197,94,.13); color:#4ade80; }
.stb.reserved { background:rgba(234,179,8,.16); color:#fde047; }
.stb.consignment { background:rgba(245,158,11,.14); color:#fbbf24; }
.nt { color:var(--muted-l); font-size:12px; max-width:260px; white-space:normal; }
.tl { color:#93c5fd; text-decoration:none; font-size:15px; }

/* ── consignment ── */
.panel.amana { margin-top:14px; border-color:rgba(245,158,11,.25); }
.amana-h { display:flex; align-items:center; gap:10px; padding:13px 16px; border-bottom:1px solid var(--border); }
.amana-h b { font-size:15px; font-weight:900; color:var(--amber); }
.amana-h em { font-style:normal; background:rgba(245,158,11,.2); color:var(--amber); font-weight:900; font-size:12px; border-radius:50px; padding:2px 10px; }

.footer {
    display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-top:14px;
    padding:12px 16px; border-radius:14px; background:rgba(15,23,42,.6); border:1px solid var(--border);
    font-size:12px; color:var(--muted-l); font-weight:600;
}
.footer strong { color:#e2e8f0; }

/* ── drawer ── */
.dr-ov { position:fixed; inset:0; z-index:200; background:rgba(2,6,23,.72); backdrop-filter:blur(5px); display:none; }
.dr-ov.on { display:block; animation:fadeIn .2s ease both; }
.drawer {
    position:fixed; z-index:201; top:0; bottom:0; inset-inline-end:0; width:min(470px,100%);
    background:#0a1020; border-inline-start:1px solid rgba(147,51,234,.25);
    box-shadow:-20px 0 60px rgba(0,0,0,.6); display:flex; flex-direction:column;
    transform:translateX(105%); transition:transform .32s cubic-bezier(.22,1,.36,1);
}
html[dir="rtl"] .drawer { transform:translateX(-105%); box-shadow:20px 0 60px rgba(0,0,0,.6); }
.drawer.on { transform:none !important; }
.dr-h { padding:16px 18px 12px; border-bottom:1px solid var(--border); display:flex; gap:12px; align-items:flex-start; }
.dr-t { flex:1; min-width:0; }
.dr-t b { display:block; font-size:17px; font-weight:900; line-height:1.3; }
.dr-t span { display:block; font-size:12px; color:var(--muted-l); font-weight:700; margin-top:3px; }
.dr-x { width:36px; height:36px; border-radius:11px; border:1px solid var(--border-2); background:rgba(255,255,255,.05); color:#cbd5e1; cursor:pointer; font-size:14px; flex-shrink:0; }
.dr-body { flex:1; overflow-y:auto; padding:12px 14px 30px; display:flex; flex-direction:column; gap:9px; }
.dcar { background:rgba(15,23,42,.9); border:1px solid var(--border); border-radius:15px; padding:12px 13px; display:flex; flex-direction:column; gap:8px; }
.dcar.res { border-color:rgba(234,179,8,.4); background:linear-gradient(180deg,rgba(234,179,8,.07),rgba(15,23,42,.9)); }
.dcar-top { display:flex; justify-content:space-between; gap:8px; align-items:flex-start; }
.dcar-top b { font-size:14px; font-weight:900; }
.dcar-top span { display:block; font-size:11.5px; color:var(--muted-l); font-weight:700; margin-top:2px; }
.dcar-row { display:flex; flex-wrap:wrap; gap:7px 12px; align-items:center; font-size:12px; color:#cbd5e1; font-weight:700; }
.dcar-note { font-size:12px; color:var(--muted-l); background:rgba(255,255,255,.03); border-radius:10px; padding:7px 10px; }
.dcar-foot { display:flex; justify-content:flex-end; }
.dcar-foot a { font-size:12px; font-weight:800; color:#93c5fd; text-decoration:none; }
.dr-branches { display:flex; flex-wrap:wrap; gap:6px; }
.dr-branches span { font-size:11.5px; font-weight:800; background:rgba(255,255,255,.05); border:1px solid var(--border); border-radius:50px; padding:3px 10px; color:#cbd5e1; }

.toast {
    position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(20px); z-index:300;
    background:#14532d; color:#dcfce7; font-size:13px; font-weight:800; padding:10px 18px; border-radius:50px;
    opacity:0; transition:opacity .25s, transform .25s; pointer-events:none; box-shadow:0 10px 30px rgba(0,0,0,.5);
}
.toast.on { opacity:1; transform:translateX(-50%) translateY(0); }
@keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
.view-enter { animation:viewIn .35s cubic-bezier(.22,1,.36,1) both; }
@keyframes viewIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:none; } }

#printArea { display:none; }

/* ── responsive ── */
@media (max-width:1100px) { .kpis { grid-template-columns:repeat(3,1fr); } }
@media (max-width:760px) {
    body { padding:12px 12px 60px; }
    .band { margin:0 -12px 12px; padding:9px 12px 7px; }
    .header { padding:12px 14px; border-radius:18px; }
    .brandline img { width:36px; height:36px; }
    .h-title { font-size:17px; }
    .h-sub { font-size:11px; }
    .h-actions { width:100%; }
    .h-actions .hbtn { flex:1; justify-content:center; height:36px; padding:0 8px; font-size:11.5px; }
    .kpis { grid-template-columns:repeat(3,1fr); gap:7px; }
    .kpi { padding:10px 11px 9px; border-radius:14px; }
    .kpi .k-n { font-size:22px; }
    .kpi .k-n small { font-size:10px; }
    .kpi .k-l { font-size:10px; }
    /* on a phone the date range and clear button fold behind ⚙, so the pinned band stays short */
    .band-top { flex-wrap:wrap; }
    .search { height:42px; flex:1 1 0; }
    .more-btn { display:block; }
    .dates, .reset-btn { display:none; }
    .band.more .dates { display:flex; width:100%; }
    .band.more .reset-btn { display:block; width:100%; }
    .dates input { flex:1; min-width:0; height:40px; }
    .reset-btn { height:40px; }
    .seg button { padding:0 11px; font-size:12px; height:34px; }
    .mgrid { grid-template-columns:1fr; padding:10px; }
    .mx .rowh { min-width:150px; padding:8px 10px; }
    .mx .rn { font-size:12.5px; }
    .mx .cb { width:40px; height:32px; font-size:14px; }
    .mx thead .colh { min-width:46px; }
    /* the list turns into compact cards on a phone */
    .lt thead { display:none; }
    .lt, .lt tbody { display:block; }
    .lt tr.gh { display:block; }
    .lt tr.gh td { display:block; }
    .lt tr.row { display:grid; grid-template-columns:1fr auto; grid-auto-flow:row dense; gap:5px 10px; padding:11px 13px; border-bottom:1px solid var(--border); }
    .lt tr.row td { padding:0; border:none; }
    .lt tr.row td.n { display:none; }
    .lt tr.row td.car { grid-column:1 / 2; }
    .lt tr.row td.agec { grid-column:2 / 3; grid-row:1; text-align:end; }
    .lt tr.row td.yr { display:none; }
    .lt tr.row td.clr { grid-column:1 / 2; }
    .lt tr.row td.stc { grid-column:2 / 3; text-align:end; }
    .lt tr.row td.chc { grid-column:1 / 3; }
    .lt tr.row td.brc { grid-column:1 / 3; font-size:11.5px; color:var(--muted-l); }
    .lt tr.row td.ntc { grid-column:1 / 3; }
    .lt tr.row td.tlc { display:none; }
    .lt tr.row td:empty { display:none; }
    .lt tr.row.res { box-shadow:inset 3px 0 0 var(--gold); }
    .lt tr.row.res td { background:none; }
    .lt tr.row.res td:first-child { box-shadow:none; }
}
@media (max-width:420px) { .kpis { grid-template-columns:repeat(2,1fr); } }

/* ════════════════ PRINT: a real report, not a screenshot ════════════════ */
@media print {
    @page { size:A4; margin:12mm 11mm; }
    html, body { background:#fff !important; color:#0f172a !important; padding:0 !important; }
    .screen { display:none !important; }
    #printArea { display:block !important; font-size:10.5pt; }
    .p-head { display:flex; align-items:center; justify-content:space-between; border-bottom:2.5px solid #7c3aed; padding-bottom:9px; margin-bottom:12px; }
    .p-brand { display:flex; align-items:center; gap:10px; }
    .p-brand img { width:44px; height:44px; object-fit:contain; }
    .p-brand b { display:block; font-size:18pt; font-weight:900; color:#0f172a; }
    .p-brand span { display:block; font-size:9pt; color:#64748b; font-weight:700; }
    .p-meta { text-align:end; font-size:9pt; color:#334155; line-height:1.6; }
    .p-meta strong { color:#0f172a; }
    .p-filters { font-size:9pt; color:#475569; background:#f1f5f9; border-radius:6px; padding:6px 10px; margin-bottom:12px; }
    .p-h2 { font-size:12.5pt; font-weight:900; color:#4c1d95; margin:14px 0 7px; padding-bottom:4px; border-bottom:1px solid #e2e8f0; }
    .p-kpis { display:grid; grid-template-columns:repeat(6,1fr); gap:6px; }
    .p-kpi { border:1px solid #e2e8f0; border-radius:7px; padding:7px 8px; }
    .p-kpi b { display:block; font-size:16pt; font-weight:900; color:#0f172a; line-height:1.1; }
    .p-kpi span { font-size:8pt; color:#64748b; font-weight:700; }
    .p-share { display:flex; height:10px; border-radius:5px; overflow:hidden; margin:9px 0 5px; }
    .p-share i { display:block; height:100%; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .p-legend { display:flex; flex-wrap:wrap; gap:4px 14px; font-size:8.5pt; color:#334155; font-weight:700; }
    .p-legend i { display:inline-block; width:8px; height:8px; border-radius:2px; margin-inline-end:4px; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    table.p-t { width:100%; border-collapse:collapse; font-size:8.8pt; }
    table.p-t th { background:#f1f5f9 !important; color:#334155; font-weight:800; text-align:start; padding:5px 6px; border:1px solid #e2e8f0; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    table.p-t td { padding:4px 6px; border:1px solid #e2e8f0; color:#0f172a; }
    table.p-t td.c, table.p-t th.c { text-align:center; }
    table.p-t tr { break-inside:avoid; }
    table.p-t .z { color:#cbd5e1; }
    table.p-t .tt { font-weight:900; background:#faf5ff; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    table.p-t .sq { display:inline-block; width:9px; height:9px; border-radius:50%; border:1px solid #94a3b8; vertical-align:middle; margin-inline-end:4px; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .p-branch { break-before:page; }
    .p-branch:first-of-type { break-before:auto; }
    .p-bh { display:flex; justify-content:space-between; align-items:baseline; font-size:12pt; font-weight:900; color:#0f172a; margin:0 0 6px; }
    .p-bh span { font-size:9pt; color:#64748b; }
    .p-res { font-weight:900; color:#a16207; }
    .p-old { font-weight:900; color:#b91c1c; }
    .p-foot { margin-top:14px; padding-top:6px; border-top:1px solid #e2e8f0; font-size:8pt; color:#94a3b8; display:flex; justify-content:space-between; }
}
</style>
</head>
<body>

<div class="wrap screen">

    <!-- ══ Header ══ -->
    <div class="header">
        <div class="brandline">
            <img src="logo.png" alt="First 1 Car">
            <div>
                <div class="h-title">📄 <?= sr_esc($L['title']) ?></div>
                <div class="h-sub"><?= sr_esc($L['subtitle']) ?></div>
            </div>
        </div>
        <div class="h-actions">
            <button type="button" class="hbtn print" onclick="SR.print()">🖨 <?= sr_esc($L['print']) ?></button>
            <button type="button" class="hbtn xls" onclick="SR.excel()">📊 <?= sr_esc($L['excel']) ?></button>
            <a href="dashboard.php?lang=<?= $lang ?>" class="hbtn ghost">🏠 <?= sr_esc($L['dashboard']) ?></a>
            <?php if (can('page.prices')): ?>
            <a href="prices.php?lang=<?= $lang ?>" class="hbtn ghost">💲 <?= sr_esc($L['prices']) ?></a>
            <?php endif; ?>
            <a href="stock_report.php?lang=<?= $other_lang ?><?= $langQs !== '' ? '&' . sr_esc($langQs) : '' ?>" class="hbtn ghost" id="langLink"><?= $isRTL ? 'EN' : 'ع' ?></a>
        </div>
    </div>

    <div class="quote-line">
        <span>💬</span>
        <span class="qt">«<?= sr_esc($quote['text']) ?>»</span>
        <span class="qa">— <?= sr_esc($quote['author']) ?></span>
    </div>

    <!-- ══ Headline numbers (follow the filters) ══ -->
    <div class="kpis" id="kpis"></div>

    <!-- ══ Share of stock per branch ══ -->
    <div class="share" id="share">
        <div class="share-h">
            <span class="share-t">📍 <?= sr_esc($L['share']) ?></span>
            <span class="share-hint"><?= sr_esc($L['share_hint']) ?></span>
        </div>
        <div class="share-bar" id="shareBar"></div>
        <div class="share-legend" id="shareLegend"></div>
    </div>

    <!-- ══ Controls: pinned while scrolling ══ -->
    <div class="band" id="band">
        <div class="band-top">
            <div class="search" id="searchBox">
                <span>🔍</span>
                <input type="text" id="q" placeholder="<?= sr_esc($L['search_ph']) ?>" autocomplete="off" inputmode="search">
                <button type="button" class="x" id="qx" aria-label="clear">✕</button>
            </div>
            <div class="dates">
                <label for="dFrom"><?= sr_esc($L['from']) ?></label>
                <input type="date" id="dFrom">
                <label for="dTo"><?= sr_esc($L['to']) ?></label>
                <input type="date" id="dTo">
            </div>
            <button type="button" class="reset-btn" id="resetBtn">↺ <?= sr_esc($L['reset']) ?></button>
            <button type="button" class="more-btn" id="moreBtn" aria-expanded="false">⚙ <span class="mdot"></span></button>
        </div>
        <div class="chips" id="chipsA"></div>
        <div class="chips" id="chipsB"></div>
    </div>

    <!-- ══ View switcher ══ -->
    <div class="viewbar">
        <div class="seg" id="seg">
            <button type="button" data-v="matrix">▦ <?= sr_esc($L['v_matrix']) ?></button>
            <button type="button" data-v="models">🚘 <?= sr_esc($L['v_models']) ?></button>
            <button type="button" data-v="list">☰ <?= sr_esc($L['v_list']) ?></button>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <select class="sortsel" id="sortSel" style="display:none">
                <option value="branch"><?= sr_esc($L['sort_branch']) ?></option>
                <option value="age"><?= sr_esc($L['sort_age']) ?></option>
                <option value="model"><?= sr_esc($L['sort_model']) ?></option>
            </select>
            <span class="result" id="result"></span>
        </div>
    </div>

    <div id="view"></div>

    <?php if ($canSeeAmana): ?>
    <div class="panel amana" id="amanaPanel" style="display:none">
        <div class="amana-h"><b>🔶 <?= sr_esc($L['amana_title']) ?></b><em id="amanaCount">0</em></div>
        <div id="amanaBody"></div>
    </div>
    <?php endif; ?>

    <div class="footer">
        <div><?= sr_esc($L['generated_by']) ?>: <strong><?= sr_esc($_SESSION['username'] ?? '') ?></strong></div>
        <div><?= sr_esc($L['generated_at']) ?>: <strong><?= date('d M Y · h:i A') ?></strong></div>
    </div>
</div>

<!-- drawer: the exact cars behind any number you tap -->
<div class="dr-ov screen" id="drOv"></div>
<aside class="drawer screen" id="drawer" aria-hidden="true">
    <div class="dr-h">
        <div class="dr-t"><b id="drTitle"></b><span id="drSub"></span></div>
        <button type="button" class="dr-x" id="drX" aria-label="<?= sr_esc($L['close']) ?>">✕</button>
    </div>
    <div class="dr-body" id="drBody"></div>
</aside>
<div class="toast screen" id="toast"></div>

<!-- built on demand just before printing -->
<div id="printArea"></div>

<script>
(function () {
'use strict';

const STOCK  = <?= sr_json($stock) ?>;
const AMANA  = <?= sr_json($amana) ?>;
const L      = <?= sr_json($L) ?>;
const INIT   = <?= sr_json($init) ?>;
const LANG   = <?= sr_json($lang) ?>;
const RTL    = <?= $isRTL ? 'true' : 'false' ?>;
const ME     = <?= sr_json($_SESSION['username'] ?? '') ?>;
const AUTOPRINT = <?= $autoPrint ? 'true' : 'false' ?>;
const PCT = RTL ? '٪' : '%';
const NOW_TXT = <?= sr_json(date('d M Y · h:i A')) ?>;

/* one colour per branch, used by the share bar, legends and mini bars */
const PALETTE = ['#8b5cf6','#22c55e','#3b82f6','#f59e0b','#ec4899','#14b8a6','#ef4444','#a3e635','#f97316','#06b6d4'];
const branchKeys = [...new Set(STOCK.concat(AMANA).map(c => c.branch))].sort();
const BR_COLOR = {}; branchKeys.forEach((b, i) => BR_COLOR[b] = PALETTE[i % PALETTE.length]);
const BR_LABEL = {}; STOCK.concat(AMANA).forEach(c => BR_LABEL[c.branch] = c.branchL);

const state = {
    q: INIT.q || '', status: '', branch: INIT.branch || '', brand: INIT.brand || '',
    age: '', from: INIT.from || '', to: INIT.to || '', view: 'matrix', sort: 'branch'
};
try {
    const v = localStorage.getItem('sr_view'); if (v === 'matrix' || v === 'models' || v === 'list') state.view = v;
    const s = localStorage.getItem('sr_sort'); if (s === 'branch' || s === 'age' || s === 'model') state.sort = s;
} catch (e) {}

const $ = id => document.getElementById(id);
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, ch => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[ch]));
const nf = n => Number(n).toLocaleString(RTL ? 'ar-EG' : 'en-US');

function ageCls(a) { return a == null ? 'n' : a < 30 ? 'g' : a < 90 ? 'a' : 'r'; }
function agePill(a) { return '<span class="age ' + ageCls(a) + '">' + (a == null ? '—' : nf(a) + ' ' + esc(L.day)) + '</span>'; }
function statusBadge(s) {
    const t = s === 'reserved' ? L.st_reserved : s === 'consignment' ? L.consignment : L.st_available;
    return '<span class="stb ' + s + '">' + (s === 'reserved' ? '🔒 ' : '') + esc(t) + '</span>';
}
function carName(c) { return esc(c.brand + ' ' + c.model); }
function carSub(c) { return esc([c.trim, c.year].filter(Boolean).join(' · ')); }

/* ════════════════════ filtering ════════════════════ */
function matchText(c, q) {
    if (!q) return true;
    const hay = (c.brand + ' ' + c.model + ' ' + c.trim + ' ' + c.year + ' ' + c.color + ' ' + c.colorL + ' ' +
                 c.branch + ' ' + c.branchL + ' ' + c.chassis + ' ' + c.notes + ' ' + (c.dealer || '')).toLowerCase();
    return q.split(/\s+/).every(w => hay.indexOf(w) !== -1);
}
function base(c, ignore) {
    ignore = ignore || {};
    if (!matchText(c, state.q.trim().toLowerCase())) return false;
    if (!ignore.branch && state.branch && c.branch !== state.branch) return false;
    if (!ignore.brand  && state.brand  && c.brand  !== state.brand)  return false;
    if (state.from && (!c.date || c.date < state.from)) return false;
    if (state.to   && (!c.date || c.date > state.to))   return false;
    return true;
}
function passAge(c) {
    if (!state.age) return true;
    if (c.age == null) return false;
    if (state.age === 'new') return c.age < 30;
    if (state.age === 'mid') return c.age >= 30 && c.age < 90;
    return c.age >= 90;
}
function filtered() {
    return STOCK.filter(c => base(c) && passAge(c) && (!state.status || c.status === state.status));
}
function filteredAmana() { return AMANA.filter(c => base(c) && passAge(c)); }

/* ════════════════════ KPIs + share bar ════════════════════ */
function renderKpis(list) {
    const withAge = list.filter(c => c.age != null);
    const avg = withAge.length ? Math.round(withAge.reduce((a, c) => a + c.age, 0) / withAge.length) : null;
    const old = list.filter(c => c.age != null && c.age >= 90).length;
    const avail = list.filter(c => c.status === 'available').length;
    const res = list.filter(c => c.status === 'reserved').length;
    const am = AMANA.length ? filteredAmana().length : null;

    const k = [
        ['total', list.length, L.k_total, ''],
        ['avail tap' + (state.status === 'available' ? ' on' : ''), avail, L.k_avail, 'available'],
        ['res tap'   + (state.status === 'reserved'  ? ' on' : ''), res, L.k_res, 'reserved'],
    ];
    let html = k.map(x => '<div class="kpi ' + x[0] + '"' + (x[3] ? ' data-st="' + x[3] + '"' : '') + '><div class="k-n num">' + nf(x[1]) + '</div><div class="k-l">' + esc(x[2]) + '</div></div>').join('');
    if (am !== null) {
        html += '<div class="kpi amana tap" data-go="amana"><div class="k-n num">' + nf(am) + '</div><div class="k-l">🔶 ' + esc(L.k_amana) + '</div></div>';
    }
    html += '<div class="kpi avg"><div class="k-n num">' + (avg == null ? '—' : nf(avg)) + '<small>' + esc(L.day) + '</small></div><div class="k-l">⏱ ' + esc(L.k_avg) + '</div></div>';
    html += '<div class="kpi old tap' + (old === 0 ? ' zero' : '') + (state.age === 'old' ? ' on' : '') + '" data-age="old"><div class="k-n num">' + nf(old) + '</div><div class="k-l">⚠️ ' + esc(L.k_old) + '</div></div>';
    $('kpis').innerHTML = html;
    $('kpis').style.gridTemplateColumns = window.innerWidth > 1100 ? 'repeat(' + (am !== null ? 6 : 5) + ',1fr)' : '';

    $('kpis').querySelectorAll('[data-st]').forEach(el => el.addEventListener('click', () => {
        const v = el.getAttribute('data-st'); state.status = state.status === v ? '' : v; render();
    }));
    const og = $('kpis').querySelector('[data-age]');
    if (og) og.addEventListener('click', () => { state.age = state.age === 'old' ? '' : 'old'; render(); });
    const ag = $('kpis').querySelector('[data-go="amana"]');
    if (ag) ag.addEventListener('click', () => { const p = $('amanaPanel'); if (p) p.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
}

function renderShare() {
    // counted across all branches so the bar always shows the full split,
    // while the chosen branch stays highlighted
    const list = STOCK.filter(c => base(c, { branch: true }) && passAge(c) && (!state.status || c.status === state.status));
    const by = {}; list.forEach(c => by[c.branch] = (by[c.branch] || 0) + 1);
    const keys = branchKeys.filter(b => by[b]);
    const total = list.length || 1;
    $('share').classList.toggle('filtering', !!state.branch);
    $('shareBar').innerHTML = keys.map(b =>
        '<div class="share-seg' + (state.branch === b ? ' on' : '') + '" data-b="' + esc(b) + '" title="' + esc(BR_LABEL[b]) + ': ' + by[b] + '" style="flex-grow:' + by[b] + ';background:' + BR_COLOR[b] + '"></div>'
    ).join('') || '<div style="flex:1"></div>';
    $('shareLegend').innerHTML = keys.map(b =>
        '<span class="share-lg" data-b="' + esc(b) + '"><i style="background:' + BR_COLOR[b] + '"></i>' + esc(BR_LABEL[b]) +
        ' <b class="num">' + nf(by[b]) + '</b> <em class="num">' + nf(Math.round(by[b] * 100 / total)) + PCT + '</em></span>'
    ).join('');
    document.querySelectorAll('#shareBar [data-b], #shareLegend [data-b]').forEach(el => el.addEventListener('click', () => {
        const b = el.getAttribute('data-b'); state.branch = state.branch === b ? '' : b; render();
    }));
}

/* ════════════════════ chips ════════════════════ */
function renderChips() {
    const pre = STOCK.filter(c => base(c, { branch: true, brand: true }));
    const cnt = (key, v) => pre.filter(c => c[key] === v).length;
    const brands = [...new Set(STOCK.map(c => c.brand))].sort();

    let a = '';
    a += chip('status', '', L.all, state.status === '');
    a += chip('status', 'available', '✅ ' + L.st_available, state.status === 'available');
    a += chip('status', 'reserved', '🔒 ' + L.st_reserved, state.status === 'reserved');
    a += '<span class="sep"></span>';
    a += chip('age', '', '⏱ ' + L.age_all, state.age === '');
    a += chip('age', 'new', '<span class="dot" style="background:#22c55e"></span>' + L.age_new, state.age === 'new', true);
    a += chip('age', 'mid', '<span class="dot" style="background:#f59e0b"></span>' + L.age_mid, state.age === 'mid', true);
    a += chip('age', 'old', '<span class="dot" style="background:#ef4444"></span>' + L.age_old, state.age === 'old', true);
    $('chipsA').innerHTML = a;

    let b = '';
    if (branchKeys.length > 1) {
        b += chip('branch', '', '📍 ' + L.all_branches, state.branch === '');
        branchKeys.forEach(k => {
            const n = cnt('branch', k); if (!n && state.branch !== k) return;
            b += chip('branch', k, '<span class="dot" style="background:' + BR_COLOR[k] + '"></span>' + esc(BR_LABEL[k]) + ' <span class="n">' + nf(n) + '</span>', state.branch === k, true);
        });
        b += '<span class="sep"></span>';
    }
    if (brands.length > 1) {
        b += chip('brand', '', '🚘 ' + L.all_brands, state.brand === '');
        brands.forEach(k => {
            const n = cnt('brand', k); if (!n && state.brand !== k) return;
            b += chip('brand', k, esc(k) + ' <span class="n">' + nf(n) + '</span>', state.brand === k, true);
        });
    }
    $('chipsB').innerHTML = b;
    $('chipsB').style.display = b ? '' : 'none';

    document.querySelectorAll('.chip[data-k]').forEach(el => el.addEventListener('click', () => {
        state[el.getAttribute('data-k')] = el.getAttribute('data-v'); render();
    }));
}
function chip(k, v, label, on, raw) {
    return '<button type="button" class="chip' + (on ? ' on' : '') + '" data-k="' + k + '" data-v="' + esc(v) + '">' + (raw ? label : esc(label)) + '</button>';
}

/* ════════════════════ helpers for grouping ════════════════════ */
function colorsOf(list) {
    const m = {};
    list.forEach(c => { if (!m[c.color]) m[c.color] = { color: c.color, label: c.colorL, sw: c.sw, n: 0 }; m[c.color].n++; });
    return Object.values(m).sort((a, b) => b.n - a.n || a.label.localeCompare(b.label));
}
function branchSplit(list) {
    const m = {}; list.forEach(c => m[c.branch] = (m[c.branch] || 0) + 1);
    return branchKeys.filter(b => m[b]).map(b => ({ b, n: m[b] }));
}

/* ════════════════════ MATRIX ════════════════════ */
function renderMatrix(list) {
    if (!list.length) return emptyHtml();
    const cols = colorsOf(list);
    const rows = {};
    list.forEach(c => {
        const k = c.brand + '|' + c.model + '|' + c.trim;
        if (!rows[k]) rows[k] = { brand: c.brand, model: c.model, trim: c.trim, cars: [] };
        rows[k].cars.push(c);
    });
    const rowList = Object.values(rows).sort((a, b) =>
        a.brand.localeCompare(b.brand) || a.model.localeCompare(b.model) || a.trim.localeCompare(b.trim));
    let max = 1;
    rowList.forEach(r => cols.forEach(col => { const n = r.cars.filter(c => c.color === col.color).length; if (n > max) max = n; }));

    let h = '<div class="panel view-enter"><div class="panel-hint">💡 ' + esc(L.m_hint) + '</div><div class="mx-scroll"><table class="mx"><thead><tr>';
    h += '<th class="rowh">' + esc(L.m_model) + '</th>';
    cols.forEach(col => { h += '<th><div class="colh"><i style="background:' + col.sw + '"></i>' + esc(col.label) + '</div></th>'; });
    h += '<th>' + esc(L.m_total) + '</th></tr></thead><tbody>';

    let lastBrand = null;
    rowList.forEach(r => {
        if (r.brand !== lastBrand) {
            lastBrand = r.brand;
            h += '<tr class="brandrow"><td>' + esc(r.brand) + '</td><td colspan="' + (cols.length + 1) + '"></td></tr>';
        }
        const years = [...new Set(r.cars.map(c => c.year).filter(Boolean))].sort();
        const oldest = Math.max.apply(null, r.cars.map(c => c.age == null ? -1 : c.age));
        const rk = esc(r.brand + '|' + r.model + '|' + r.trim);
        h += '<tr><td class="rowh" data-row="' + rk + '"><div class="rn">' + esc(r.model) + (r.trim ? ' <span style="color:#94a3b8;font-weight:700">· ' + esc(r.trim) + '</span>' : '') + '</div>';
        h += '<div class="rm">' + esc(years.join(' · ')) + (oldest >= 0 ? ' ' + agePill(oldest) : '') + '</div></td>';
        cols.forEach(col => {
            const cs = r.cars.filter(c => c.color === col.color);
            if (!cs.length) { h += '<td class="cell"><span class="zero">·</span></td>'; return; }
            const a = (0.16 + 0.62 * cs.length / max).toFixed(2);
            const hasRes = cs.some(c => c.status === 'reserved');
            const br = branchSplit(cs).map(x => BR_LABEL[x.b] + ' ' + x.n).join(' · ');
            h += '<td class="cell"><button type="button" class="cb num' + (hasRes ? ' hasres' : '') + '" style="--a:' + a + '" data-row="' + rk + '" data-color="' + esc(col.color) + '" title="' + esc(br) + '">' + nf(cs.length) + '</button></td>';
        });
        h += '<td class="tot num">' + nf(r.cars.length) + '</td></tr>';
    });
    h += '</tbody><tfoot><tr><td class="rowh">' + esc(L.m_total) + '</td>';
    cols.forEach(col => { h += '<td class="num">' + nf(col.n) + '</td>'; });
    h += '<td class="grand num">' + nf(list.length) + '</td></tr></tfoot></table></div>';
    h += '<div class="mx-legend"><span><i style="background:rgba(147,51,234,.2)"></i><i style="background:rgba(147,51,234,.5)"></i><i style="background:rgba(147,51,234,.78)"></i> ' +
         esc(RTL ? 'كلما زاد العدد زاد اللون' : 'darker means more cars') + '</span><span><i style="box-shadow:inset 0 0 0 2px rgba(234,179,8,.8);background:rgba(147,51,234,.3)"></i> ' +
         esc(RTL ? 'فيها سيارة محجوزة' : 'includes a reserved car') + '</span></div></div>';

    return { html: h, bind: root => {
        root.querySelectorAll('.cb').forEach(b => b.addEventListener('click', () => {
            const [br, md, tr] = b.getAttribute('data-row').split('|');
            const col = b.getAttribute('data-color');
            const cs = list.filter(c => c.brand === br && c.model === md && c.trim === tr && c.color === col);
            openDrawer(br + ' ' + md + (tr ? ' · ' + tr : ''), (cs[0] ? cs[0].colorL : col), cs);
        }));
        root.querySelectorAll('.rowh[data-row]').forEach(td => td.addEventListener('click', () => {
            const [br, md, tr] = td.getAttribute('data-row').split('|');
            const cs = list.filter(c => c.brand === br && c.model === md && c.trim === tr);
            openDrawer(br + ' ' + md + (tr ? ' · ' + tr : ''), '', cs);
        }));
    }};
}

/* ════════════════════ MODELS ════════════════════ */
function renderModels(list) {
    if (!list.length) return emptyHtml();
    const groups = {};
    list.forEach(c => {
        const k = c.brand + '|' + c.model;
        if (!groups[k]) groups[k] = { brand: c.brand, model: c.model, cars: [] };
        groups[k].cars.push(c);
    });
    const gl = Object.values(groups).sort((a, b) => b.cars.length - a.cars.length || (a.brand + a.model).localeCompare(b.brand + b.model));

    let h = '<div class="panel view-enter"><div class="mgrid">';
    gl.forEach((g, i) => {
        const cols = colorsOf(g.cars);
        // photo: the most common colour that has one, then any car with one
        let img = '';
        for (const col of cols) { const c = g.cars.find(x => x.color === col.color && x.img); if (c) { img = c.img; break; } }
        const trims = [...new Set(g.cars.map(c => c.trim).filter(Boolean))];
        const res = g.cars.filter(c => c.status === 'reserved').length;
        const ages = g.cars.filter(c => c.age != null).map(c => c.age);
        const avg = ages.length ? Math.round(ages.reduce((a, b) => a + b, 0) / ages.length) : null;
        const oldest = ages.length ? Math.max.apply(null, ages) : null;
        const split = branchSplit(g.cars);

        h += '<div class="mcard" data-g="' + i + '">';
        h += '<div class="stage">' + (img ? '<img src="' + esc(img) + '" alt="' + carName(g) + '" loading="lazy">' : '<div class="ph">🚗</div>');
        h += '<div class="big num">' + nf(g.cars.length) + '<small>' + esc(L.cars) + '</small></div>';
        if (res) h += '<div class="resb">🔒 ' + nf(res) + ' ' + esc(L.st_reserved) + '</div>';
        h += '</div>';
        h += '<div><div class="mc-name">' + esc(g.model) + ' <span>' + esc(g.brand) + '</span></div>';
        if (trims.length) h += '<div class="mc-trims">' + trims.map(t => '<em>' + esc(t) + '</em>').join('') + '</div>';
        h += '</div>';
        h += '<div class="dots">' + cols.map(col => '<span class="dotc" title="' + esc(col.label) + '"><i style="background:' + col.sw + '"></i><span class="num">' + nf(col.n) + '</span></span>').join('') + '</div>';
        h += '<div class="minibar">' + split.map(x => '<b style="flex-grow:' + x.n + ';background:' + BR_COLOR[x.b] + '"></b>').join('') + '</div>';
        h += '<div class="mc-foot"><div class="mc-branches">' + split.map(x => '<span><i style="background:' + BR_COLOR[x.b] + '"></i>' + esc(BR_LABEL[x.b]) + ' <b class="num">' + nf(x.n) + '</b></span>').join('') + '</div>';
        h += '<div>' + (avg != null ? esc(L.avg) + ' ' + agePill(avg) : '') + '</div></div>';
        h += '</div>';
    });
    h += '</div></div>';

    return { html: h, bind: root => {
        root.querySelectorAll('.mcard').forEach(el => el.addEventListener('click', () => {
            const g = gl[+el.getAttribute('data-g')];
            openDrawer(g.brand + ' ' + g.model, '', g.cars);
        }));
        root.querySelectorAll('.stage img').forEach(stageImage);
    }};
}

/* ════════════════════ LIST ════════════════════ */
function sortCars(list) {
    const l = list.slice();
    if (state.sort === 'age') l.sort((a, b) => (b.age == null ? -1 : b.age) - (a.age == null ? -1 : a.age));
    else if (state.sort === 'model') l.sort((a, b) => (a.brand + a.model + a.trim).localeCompare(b.brand + b.model + b.trim) || (b.age || 0) - (a.age || 0));
    else l.sort((a, b) => a.branchL.localeCompare(b.branchL) || (a.brand + a.model + a.trim).localeCompare(b.brand + b.model + b.trim));
    return l;
}
function rowHtml(c, n, withBranch) {
    return '<tr class="row' + (c.status === 'reserved' ? ' res' : '') + '">' +
        '<td class="n num">' + n + '</td>' +
        '<td class="car"><b>' + carName(c) + '</b><span>' + carSub(c) + '</span></td>' +
        '<td class="yr num">' + esc(c.year) + '</td>' +
        '<td class="clr"><span class="sw"><i style="background:' + c.sw + '"></i>' + esc(c.colorL) + '</span></td>' +
        '<td class="chc"><button type="button" class="chs" data-ch="' + esc(c.chassis) + '">' + esc(c.chassis) + '</button></td>' +
        '<td class="agec">' + agePill(c.age) + '</td>' +
        '<td class="stc">' + statusBadge(c.status) + '</td>' +
        (withBranch ? '<td class="brc">📍 ' + esc(c.branchL) + '</td>' : '') +
        '<td class="ntc nt">' + (c.notes ? '📝 ' + esc(c.notes) : '') + '</td>' +
        '<td class="tlc"><a class="tl" href="vehicle_timeline.php?id=' + c.id + '&lang=' + LANG + '" title="' + esc(L.timeline) + '">↗</a></td>' +
        '</tr>';
}
function renderList(list) {
    if (!list.length) return emptyHtml();
    const sorted = sortCars(list);
    const grouped = state.sort === 'branch';
    let h = '<div class="panel view-enter"><div style="overflow-x:auto"><table class="lt"><thead><tr>' +
        '<th>' + esc(L.c_num) + '</th><th>' + esc(L.c_car) + '</th><th>' + esc(L.c_year) + '</th><th>' + esc(L.c_color) + '</th>' +
        '<th>' + esc(L.c_chassis) + '</th><th>' + esc(L.c_age) + '</th><th>' + esc(L.c_status) + '</th>' +
        (grouped ? '' : '<th>' + esc(L.c_branch) + '</th>') + '<th>' + esc(L.c_notes) + '</th><th></th></tr></thead><tbody>';
    let last = null, n = 0;
    sorted.forEach(c => {
        if (grouped && c.branch !== last) {
            last = c.branch; n = 0;
            const cnt = sorted.filter(x => x.branch === c.branch).length;
            h += '<tr class="gh"><td colspan="9"><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:' + BR_COLOR[c.branch] + ';margin-inline-end:8px"></span>📍 ' + esc(c.branchL) + '<span class="ghn num">' + nf(cnt) + ' ' + esc(L.cars) + '</span></td></tr>';
        }
        h += rowHtml(c, ++n, !grouped);
    });
    h += '</tbody></table></div></div>';
    return { html: h, bind: bindChassis };
}

function emptyHtml() {
    return { html: '<div class="panel view-enter"><div class="empty"><div class="ei">🔍</div><div class="et">' + esc(L.empty) + '</div></div></div>', bind: () => {} };
}

/* ════════════════════ consignment ════════════════════ */
function renderAmana() {
    const p = $('amanaPanel'); if (!p) return;
    const list = filteredAmana();
    p.style.display = AMANA.length ? '' : 'none';
    $('amanaCount').textContent = nf(list.length);
    if (!list.length) { $('amanaBody').innerHTML = '<div class="empty" style="padding:26px"><div class="et">' + esc(L.empty) + '</div></div>'; return; }
    let h = '<div style="overflow-x:auto"><table class="lt"><thead><tr><th>#</th><th>' + esc(L.c_car) + '</th><th>' + esc(L.c_color) + '</th><th>' + esc(L.c_chassis) +
            '</th><th>' + esc(L.dealer) + '</th><th>' + esc(L.out_for) + '</th><th>' + esc(L.c_branch) + '</th></tr></thead><tbody>';
    list.forEach((c, i) => {
        h += '<tr class="row"><td class="n num">' + (i + 1) + '</td><td class="car"><b>' + carName(c) + '</b><span>' + carSub(c) + '</span></td>' +
             '<td class="clr"><span class="sw"><i style="background:' + c.sw + '"></i>' + esc(c.colorL) + '</span></td>' +
             '<td class="chc"><button type="button" class="chs" data-ch="' + esc(c.chassis) + '">' + esc(c.chassis) + '</button></td>' +
             '<td class="stc"><b>👤 ' + esc(c.dealer || '—') + '</b>' + (c.salesman ? '<span style="display:block;font-size:11px;color:#94a3b8">' + esc(L.salesman) + ': ' + esc(c.salesman) + '</span>' : '') + '</td>' +
             '<td class="agec">' + agePill(c.out) + '</td><td class="brc">📍 ' + esc(c.branchL) + '</td></tr>';
    });
    h += '</tbody></table></div>';
    $('amanaBody').innerHTML = h;
    bindChassis($('amanaBody'));
}

/* ════════════════════ drawer ════════════════════ */
function openDrawer(title, sub, cars) {
    const split = branchSplit(cars);
    $('drTitle').textContent = title;
    $('drSub').textContent = (sub ? sub + ' · ' : '') + nf(cars.length) + ' ' + L.cars;
    let h = '<div class="dr-branches">' + split.map(x => '<span><i style="display:inline-block;width:8px;height:8px;border-radius:2px;background:' + BR_COLOR[x.b] + ';margin-inline-end:5px"></i>' + esc(BR_LABEL[x.b]) + ' · <b class="num">' + nf(x.n) + '</b></span>').join('') + '</div>';
    cars.slice().sort((a, b) => (b.age || 0) - (a.age || 0)).forEach(c => {
        h += '<div class="dcar' + (c.status === 'reserved' ? ' res' : '') + '">' +
            '<div class="dcar-top"><div><b>' + carName(c) + '</b><span>' + carSub(c) + '</span></div>' + agePill(c.age) + '</div>' +
            '<div class="dcar-row"><span class="sw"><i style="background:' + c.sw + '"></i>' + esc(c.colorL) + '</span><span>📍 ' + esc(c.branchL) + '</span>' + statusBadge(c.status) + '</div>' +
            '<div class="dcar-row"><button type="button" class="chs" data-ch="' + esc(c.chassis) + '">' + esc(c.chassis) + '</button>' +
            (c.date ? '<span style="color:#64748b">' + esc(L.c_arrived) + ': ' + esc(c.date) + '</span>' : '') + '</div>' +
            (c.notes ? '<div class="dcar-note">📝 ' + esc(c.notes) + '</div>' : '') +
            '<div class="dcar-foot"><a href="vehicle_timeline.php?id=' + c.id + '&lang=' + LANG + '">🕐 ' + esc(L.timeline) + ' ↗</a></div></div>';
    });
    $('drBody').innerHTML = h;
    bindChassis($('drBody'));
    $('drOv').classList.add('on');
    $('drawer').classList.add('on');
    $('drawer').setAttribute('aria-hidden', 'false');
}
function closeDrawer() {
    $('drOv').classList.remove('on');
    $('drawer').classList.remove('on');
    $('drawer').setAttribute('aria-hidden', 'true');
}
$('drX').addEventListener('click', closeDrawer);
$('drOv').addEventListener('click', closeDrawer);
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDrawer(); });

/* tap a chassis number to copy it */
function bindChassis(root) {
    (root || document).querySelectorAll('.chs[data-ch]').forEach(b => b.addEventListener('click', ev => {
        ev.stopPropagation();
        const v = b.getAttribute('data-ch');
        const done = () => toast(L.copied);
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(v).then(done, done);
        else { const ta = document.createElement('textarea'); ta.value = v; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch (e) {} ta.remove(); done(); }
    }));
}
let toastT = null;
function toast(msg) {
    const t = $('toast'); t.textContent = msg; t.classList.add('on');
    clearTimeout(toastT); toastT = setTimeout(() => t.classList.remove('on'), 1600);
}

/* ════════════════════ showroom stage for the model photos ════════════════════
   Same idea as the dashboard cards: detect what the photo was shot on, pick a
   matching backdrop, and zoom so every car fills its stage at the same size. */
function stageImage(img) {
    const box = img.closest('.stage'); if (!box) return;
    const PAD = { l: 20, r: 70, t: 14, b: 14 };
    function frame() {
        const bb = box._bbox; if (!bb || !img.naturalWidth) return;
        const sw = box.clientWidth - PAD.l - PAD.r, sh = box.clientHeight - PAD.t - PAD.b;
        if (sw <= 0 || sh <= 0) return;
        const nw = img.naturalWidth, nh = img.naturalHeight;
        const cw = (bb.x1 - bb.x0) * nw, ch = (bb.y1 - bb.y0) * nh, k = Math.min(sw / cw, sh / ch);
        const leftPad = RTL ? PAD.r : PAD.l;
        Object.assign(img.style, { position: 'absolute', maxWidth: 'none', padding: '0', objectFit: 'fill',
            width: (nw * k) + 'px', height: (nh * k) + 'px',
            left: (leftPad + (sw - cw * k) / 2 - bb.x0 * nw * k) + 'px',
            top: (PAD.t + (sh - ch * k) - bb.y0 * nh * k) + 'px' });
    }
    function classify() {
        try {
            const w = 120, h = Math.max(24, Math.round(w * img.naturalHeight / img.naturalWidth));
            const cv = document.createElement('canvas'); cv.width = w; cv.height = h;
            const cx = cv.getContext('2d', { willReadFrequently: true }); cx.drawImage(img, 0, 0, w, h);
            const d = cx.getImageData(0, 0, w, h).data;
            const at = (x, y) => { const i = (y * w + x) * 4; return [d[i], d[i + 1], d[i + 2]]; };
            const ring = [];
            for (let x = 1; x < w - 1; x += 5) { ring.push(at(x, 1)); ring.push(at(x, h - 2)); }
            for (let y = 1; y < h - 1; y += 3) { ring.push(at(1, y)); ring.push(at(w - 2, y)); }
            const m = [0, 1, 2].map(c => ring.reduce((a, p) => a + p[c], 0) / ring.length);
            const spread = Math.sqrt(ring.reduce((a, p) => a + (p[0] - m[0]) ** 2 + (p[1] - m[1]) ** 2 + (p[2] - m[2]) ** 2, 0) / ring.length);
            const lum = 0.299 * m[0] + 0.587 * m[1] + 0.114 * m[2];
            if (spread > 34) box.classList.add('scene');
            else {
                if (lum > 226) box.classList.add('studio');
                else { box.classList.add('matte'); box.style.setProperty('--stage-bg', 'rgb(' + m.map(Math.round).join(',') + ')'); }
                let x0 = w, y0 = h, x1 = -1, y1 = -1;
                for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
                    const p = at(x, y);
                    if (Math.abs(p[0] - m[0]) + Math.abs(p[1] - m[1]) + Math.abs(p[2] - m[2]) > 60) {
                        if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y;
                    }
                }
                const fw = (x1 - x0 + 1) / w, fh = (y1 - y0 + 1) / h;
                if (x1 > 0 && fw > 0.08 && fh > 0.08 && !(fw > 0.97 && fh > 0.97)) {
                    box._bbox = { x0: x0 / w, y0: y0 / h, x1: (x1 + 1) / w, y1: (y1 + 1) / h };
                    box._frame = frame; frame();
                }
            }
        } catch (e) {}
        box.classList.add('ready');
    }
    img.addEventListener('error', () => { img.remove(); box.insertAdjacentHTML('afterbegin', '<div class="ph">🚗</div>'); box.classList.add('ready'); }, { once: true });
    if (img.complete && img.naturalWidth) classify(); else img.addEventListener('load', classify, { once: true });
}
let rsT = null;
window.addEventListener('resize', () => {
    clearTimeout(rsT);
    rsT = setTimeout(() => { document.querySelectorAll('.stage').forEach(b => { if (b._frame) b._frame(); }); renderKpis(filtered()); }, 150);
});

/* ════════════════════ render everything ════════════════════ */
function syncUrl() {
    try {
        const p = new URLSearchParams();
        p.set('lang', LANG);
        if (state.q) p.set('search', state.q);
        if (state.branch) p.set('branch', state.branch);
        if (state.brand) p.set('brand', state.brand);
        if (state.from) p.set('date_from', state.from);
        if (state.to) p.set('date_to', state.to);
        history.replaceState(null, '', 'stock_report.php?' + p.toString());
        const other = new URLSearchParams(p); other.set('lang', LANG === 'ar' ? 'en' : 'ar');
        $('langLink').href = 'stock_report.php?' + other.toString();
    } catch (e) {}
}
function render() {
    const list = filtered();
    renderKpis(list);
    renderShare();
    renderChips();
    $('searchBox').classList.toggle('has', state.q !== '');
    $('band').classList.toggle('dated', !!(state.from || state.to));
    $('result').innerHTML = '<b class="num">' + nf(list.length) + '</b> ' + esc(L.cars);
    document.querySelectorAll('#seg button').forEach(b => b.classList.toggle('on', b.getAttribute('data-v') === state.view));
    $('sortSel').style.display = state.view === 'list' ? '' : 'none';
    $('sortSel').value = state.sort;

    const out = state.view === 'models' ? renderModels(list) : state.view === 'list' ? renderList(list) : renderMatrix(list);
    $('view').innerHTML = out.html;
    out.bind($('view'));
    renderAmana();
    syncUrl();
}

/* inputs */
$('q').value = state.q;
$('dFrom').value = state.from;
$('dTo').value = state.to;
if (state.from || state.to) $('band').classList.add('more');   // a dated link opens with the dates visible
let qT = null;
$('q').addEventListener('input', () => { clearTimeout(qT); qT = setTimeout(() => { state.q = $('q').value; render(); }, 120); });
$('qx').addEventListener('click', () => { $('q').value = ''; state.q = ''; render(); $('q').focus(); });
$('dFrom').addEventListener('change', () => { state.from = $('dFrom').value; render(); });
$('dTo').addEventListener('change', () => { state.to = $('dTo').value; render(); });
$('moreBtn').addEventListener('click', () => {
    const open = !$('band').classList.contains('more');
    $('band').classList.toggle('more', open);
    $('moreBtn').setAttribute('aria-expanded', open ? 'true' : 'false');
});
$('resetBtn').addEventListener('click', () => {
    Object.assign(state, { q: '', status: '', branch: '', brand: '', age: '', from: '', to: '' });
    $('q').value = ''; $('dFrom').value = ''; $('dTo').value = ''; render();
});
document.querySelectorAll('#seg button').forEach(b => b.addEventListener('click', () => {
    state.view = b.getAttribute('data-v'); try { localStorage.setItem('sr_view', state.view); } catch (e) {} render();
}));
$('sortSel').addEventListener('change', () => { state.sort = $('sortSel').value; try { localStorage.setItem('sr_sort', state.sort); } catch (e) {} render(); });

/* shadow on the control band once it is actually stuck */
if ('IntersectionObserver' in window) {
    const probe = document.createElement('div'); probe.style.height = '1px';
    $('band').parentNode.insertBefore(probe, $('band'));
    new IntersectionObserver(en => $('band').classList.toggle('stuck', !en[0].isIntersecting)).observe(probe);
}

/* ════════════════════ PRINT: a proper report ════════════════════
   Built fresh from whatever is filtered right now: letterhead, the summary,
   the model-and-colour matrix, then one page per branch with every car. */
function buildPrint() {
    const list = filtered();
    const am = filteredAmana();
    const withAge = list.filter(c => c.age != null);
    const avg = withAge.length ? Math.round(withAge.reduce((a, c) => a + c.age, 0) / withAge.length) : null;
    const old = list.filter(c => c.age != null && c.age >= 90).length;

    const f = [];
    if (state.q) f.push('🔍 ' + state.q);
    if (state.status) f.push(state.status === 'reserved' ? L.st_reserved : L.st_available);
    if (state.branch) f.push(BR_LABEL[state.branch]);
    if (state.brand) f.push(state.brand);
    if (state.age) f.push(state.age === 'new' ? L.age_new : state.age === 'mid' ? L.age_mid : L.age_old);
    if (state.from || state.to) f.push(L.from + ' ' + (state.from || '…') + ' ' + L.to + ' ' + (state.to || '…'));

    let h = '<div class="p-head"><div class="p-brand"><img src="logo.png" alt=""><div><b>First 1 Car</b><span>' + esc(L.title) + '</span></div></div>' +
            '<div class="p-meta">' + esc(L.generated_at) + ': <strong>' + esc(NOW_TXT) + '</strong><br>' + esc(L.generated_by) + ': <strong>' + esc(ME) + '</strong></div></div>';
    h += '<div class="p-filters"><b>' + esc(L.print_filters) + ':</b> ' + esc(f.length ? f.join(' · ') : L.no_filters) + '</div>';

    h += '<div class="p-h2">' + esc(L.print_summary) + '</div><div class="p-kpis">' +
         pk(list.length, L.k_total) + pk(list.filter(c => c.status === 'available').length, L.k_avail) +
         pk(list.filter(c => c.status === 'reserved').length, L.k_res) + pk(AMANA.length ? am.length : '—', L.k_amana) +
         pk(avg == null ? '—' : nf(avg) + ' ' + L.day, L.k_avg) + pk(old, L.k_old) + '</div>';
    const split = branchSplit(list);
    h += '<div class="p-share">' + split.map(x => '<i style="flex-grow:' + x.n + ';background:' + BR_COLOR[x.b] + '"></i>').join('') + '</div>';
    h += '<div class="p-legend">' + split.map(x => '<span><i style="background:' + BR_COLOR[x.b] + '"></i>' + esc(BR_LABEL[x.b]) + ' ' + nf(x.n) + ' (' + nf(Math.round(x.n * 100 / (list.length || 1))) + PCT + ')</span>').join('') + '</div>';

    if (list.length) {
        const cols = colorsOf(list);
        const rows = {};
        list.forEach(c => { const k = c.brand + '|' + c.model + '|' + c.trim; (rows[k] = rows[k] || { brand: c.brand, model: c.model, trim: c.trim, cars: [] }).cars.push(c); });
        const rl = Object.values(rows).sort((a, b) => (a.brand + a.model + a.trim).localeCompare(b.brand + b.model + b.trim));
        h += '<div class="p-h2">' + esc(L.print_matrix) + '</div><table class="p-t"><thead><tr><th>' + esc(L.m_model) + '</th>' +
             cols.map(c => '<th class="c"><span class="sq" style="background:' + c.sw + '"></span>' + esc(c.label) + '</th>').join('') + '<th class="c">' + esc(L.m_total) + '</th></tr></thead><tbody>';
        rl.forEach(r => {
            h += '<tr><td><b>' + esc(r.brand + ' ' + r.model) + '</b>' + (r.trim ? ' · ' + esc(r.trim) : '') + '</td>';
            cols.forEach(col => { const n = r.cars.filter(c => c.color === col.color).length; h += '<td class="c' + (n ? '' : ' z') + '">' + (n ? nf(n) : '·') + '</td>'; });
            h += '<td class="c tt">' + nf(r.cars.length) + '</td></tr>';
        });
        h += '<tr><td class="tt">' + esc(L.m_total) + '</td>' + cols.map(c => '<td class="c tt">' + nf(c.n) + '</td>').join('') + '<td class="c tt">' + nf(list.length) + '</td></tr></tbody></table>';

        h += '<div class="p-h2" style="break-before:page">' + esc(L.print_detail) + '</div>';
        split.forEach((x, i) => {
            const cars = sortCars(list.filter(c => c.branch === x.b)).sort((a, b) => (a.brand + a.model + a.trim).localeCompare(b.brand + b.model + b.trim));
            h += '<div class="' + (i ? 'p-branch' : '') + '"><div class="p-bh">📍 ' + esc(BR_LABEL[x.b]) + '<span>' + nf(cars.length) + ' ' + esc(L.cars) + '</span></div>' +
                 '<table class="p-t"><thead><tr><th>#</th><th>' + esc(L.c_brand) + '</th><th>' + esc(L.c_model) + '</th><th>' + esc(L.c_trim) + '</th><th>' + esc(L.c_year) +
                 '</th><th>' + esc(L.c_color) + '</th><th>' + esc(L.c_chassis) + '</th><th>' + esc(L.c_age) + '</th><th>' + esc(L.c_notes) + '</th></tr></thead><tbody>';
            cars.forEach((c, j) => {
                h += '<tr><td>' + (j + 1) + '</td><td>' + esc(c.brand) + (c.status === 'reserved' ? ' <span class="p-res">🔒 ' + esc(L.st_reserved) + '</span>' : '') + '</td><td>' + esc(c.model) + '</td><td>' + esc(c.trim) +
                     '</td><td>' + esc(c.year) + '</td><td><span class="sq" style="background:' + c.sw + '"></span>' + esc(c.colorL) + '</td><td>' + esc(c.chassis) +
                     '</td><td class="' + (c.age != null && c.age >= 90 ? 'p-old' : '') + '">' + (c.age == null ? '—' : nf(c.age) + ' ' + esc(L.day)) + '</td><td>' + esc(c.notes) + '</td></tr>';
            });
            h += '</tbody></table></div>';
        });
    }

    if (am.length) {
        h += '<div class="p-branch"><div class="p-bh">🔶 ' + esc(L.amana_title) + '<span>' + nf(am.length) + ' ' + esc(L.cars) + '</span></div><table class="p-t"><thead><tr><th>#</th><th>' + esc(L.c_car) +
             '</th><th>' + esc(L.c_color) + '</th><th>' + esc(L.c_chassis) + '</th><th>' + esc(L.dealer) + '</th><th>' + esc(L.out_for) + '</th><th>' + esc(L.c_branch) + '</th></tr></thead><tbody>';
        am.forEach((c, i) => {
            h += '<tr><td>' + (i + 1) + '</td><td>' + esc(c.brand + ' ' + c.model + ' ' + c.trim + ' ' + c.year) + '</td><td><span class="sq" style="background:' + c.sw + '"></span>' + esc(c.colorL) +
                 '</td><td>' + esc(c.chassis) + '</td><td>' + esc(c.dealer) + '</td><td>' + (c.out == null ? '—' : nf(c.out) + ' ' + esc(L.day)) + '</td><td>' + esc(c.branchL) + '</td></tr>';
        });
        h += '</tbody></table></div>';
    }

    h += '<div class="p-foot"><span>First 1 Car · ' + esc(L.title) + '</span><span>' + esc(NOW_TXT) + '</span></div>';
    $('printArea').innerHTML = h;
}
function pk(n, label) { return '<div class="p-kpi"><b>' + (typeof n === 'number' ? nf(n) : esc(n)) + '</b><span>' + esc(label) + '</span></div>'; }
window.addEventListener('beforeprint', buildPrint);

/* ════════════════════ EXCEL ════════════════════
   A CSV with a byte-order mark, which Excel opens with Arabic intact. */
function excel() {
    const list = sortCars(filtered()).sort((a, b) => a.branchL.localeCompare(b.branchL) || (a.brand + a.model).localeCompare(b.brand + b.model));
    const head = [L.c_branch, L.c_brand, L.c_model, L.c_trim, L.c_year, L.c_color, L.c_chassis, L.c_status, L.c_age, L.c_arrived, L.c_notes, L.dealer];
    const rows = [head];
    const st = s => s === 'reserved' ? L.st_reserved : s === 'consignment' ? L.consignment : L.st_available;
    list.forEach(c => rows.push([c.branchL, c.brand, c.model, c.trim, c.year, c.colorL, c.chassis, st(c.status), c.age == null ? '' : c.age, c.date, c.notes, '']));
    filteredAmana().forEach(c => rows.push([c.branchL, c.brand, c.model, c.trim, c.year, c.colorL, c.chassis, st('consignment'), c.age == null ? '' : c.age, c.date, c.notes, c.dealer]));
    const csv = rows.map(r => r.map(v => {
        const s = String(v == null ? '' : v);
        return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
    }).join(',')).join('\r\n');
    const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'first1car-stock-' + new Date().toISOString().slice(0, 10) + '.csv';
    document.body.appendChild(a); a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
}

window.SR = {
    print: function () { buildPrint(); setTimeout(() => window.print(), 50); },
    excel: excel
};

render();
if (AUTOPRINT) window.addEventListener('load', () => setTimeout(window.SR.print, 400));

})();
</script>
</body>
</html>
