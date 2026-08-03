<?php
/*
 * installments.php — التقسيط · موافقات البنوك (bank approval requests)
 *
 * Sales files a request (customer + car + مقدم %) against one or more banks.
 * The customer/car data is entered ONCE and shared by every bank the request
 * is sent to — only the مقدم % changes per bank. Each bank line then waits for
 * a manager to accept or reject it.
 *
 * Everything about who may open this page, create requests, decide on them,
 * see other people's requests, or delete them is controlled from the
 * Permission Center (صفحة الصلاحيات).
 */

require 'auth.php';
require 'config.php';
require 'installment_helpers.php';

perm_require('page.installments');
inst_ensure_tables($pdo);

$lang = $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar', 'en'], true)) $lang = 'ar';
$dir = $lang === 'ar' ? 'rtl' : 'ltr';

$canCreate  = can('installments.create');
$canDecide  = can('installments.decide');
$canViewAll = can('installments.view_all');
$canDelete  = can('installments.delete');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
$me        = $_SESSION['username'] ?? '';

$t = [
    'ar' => [
        'title'        => 'التقسيط',
        'subtitle'     => 'موافقات البنوك على طلبات العملاء',
        'dashboard'    => 'الرئيسية',
        'new_request'  => 'طلب تقسيط جديد',
        'form_hint'    => 'اكتب بيانات العميل والسيارة مرة واحدة، ثم اختر البنوك — كل بنك له نسبة مقدم خاصة به.',
        'customer_data'=> 'بيانات العميل',
        'car_data'     => 'بيانات السيارة',
        'banks_data'   => 'البنوك والمقدم',
        'cust_name'    => 'اسم العميل',
        'cust_phone'   => 'رقم العميل',
        'brand'        => 'الماركة',
        'model'        => 'الموديل',
        'trim'         => 'الفئة',
        'year'         => 'السنة',
        'select'       => '— اختر —',
        'down_payment' => 'المقدم',
        'full_payment' => 'دفع كامل — بدون تقسيط',
        'pick_banks'   => 'اختر بنك واحد أو أكثر — البيانات تتعبأ تلقائياً، غيّر النسبة فقط',
        'send'         => '📤 إرسال الطلب',
        'requests'     => 'الطلبات',
        'no_requests'  => 'لا توجد طلبات بعد',
        'waiting'      => '⏳ في انتظار رد المدير',
        'approved'     => '✅ تمت الموافقة',
        'rejected'     => '❌ مرفوض',
        'pending'      => 'قيد الانتظار',
        'accept'       => '✅ قبول',
        'reject'       => '❌ رفض',
        'by'           => 'أضافه',
        'decided_by'   => 'الرد بواسطة',
        'note'         => 'ملاحظة',
        'note_ph'      => 'ملاحظة (اختياري)',
        'delete'       => '🗑 حذف الطلب',
        'del_confirm'  => 'حذف هذا الطلب وكل بنوكه نهائياً؟',
        'stat_total'   => 'إجمالي الطلبات',
        'stat_pending' => 'قيد الانتظار',
        'stat_approved'=> 'موافق عليها',
        'stat_rejected'=> 'مرفوضة',
        'flash_sent'   => '✓ تم إرسال الطلب — في انتظار رد المدير',
        'flash_appr'   => '✓ تم قبول الطلب',
        'flash_rej'    => '✓ تم رفض الطلب',
        'flash_del'    => '✓ تم حذف الطلب',
        'flash_err'    => '⚠️ تأكد من ملء كل البيانات واختيار بنك واحد على الأقل',
        'only_mine'    => 'تعرض طلباتك فقط',
        'no_create'    => 'ليست لديك صلاحية إضافة طلبات — يمكنك العرض فقط',
        'filter_all'   => 'كل الحالات',
        'search_ph'    => 'ابحث باسم العميل أو رقمه أو السيارة...',
    ],
    'en' => [
        'title'        => 'Installments',
        'subtitle'     => 'Bank approvals for customer requests',
        'dashboard'    => 'Dashboard',
        'new_request'  => 'New Installment Request',
        'form_hint'    => 'Enter the customer and car once, then pick the banks — each bank gets its own down payment %.',
        'customer_data'=> 'Customer Details',
        'car_data'     => 'Car Details',
        'banks_data'   => 'Banks & Down Payment',
        'cust_name'    => 'Customer Name',
        'cust_phone'   => 'Customer Phone',
        'brand'        => 'Brand',
        'model'        => 'Model',
        'trim'         => 'Trim',
        'year'         => 'Year',
        'select'       => '— Select —',
        'down_payment' => 'Down payment',
        'full_payment' => 'Full payment — no installment',
        'pick_banks'   => 'Pick one or more banks — data is auto-filled, just change the percentage',
        'send'         => '📤 Send Request',
        'requests'     => 'Requests',
        'no_requests'  => 'No requests yet',
        'waiting'      => '⏳ Waiting for manager response',
        'approved'     => '✅ Approved',
        'rejected'     => '❌ Rejected',
        'pending'      => 'Pending',
        'accept'       => '✅ Accept',
        'reject'       => '❌ Reject',
        'by'           => 'Added by',
        'decided_by'   => 'Decided by',
        'note'         => 'Note',
        'note_ph'      => 'Note (optional)',
        'delete'       => '🗑 Delete request',
        'del_confirm'  => 'Delete this request and all its banks permanently?',
        'stat_total'   => 'Total Requests',
        'stat_pending' => 'Pending',
        'stat_approved'=> 'Approved',
        'stat_rejected'=> 'Rejected',
        'flash_sent'   => '✓ Request sent — waiting for manager response',
        'flash_appr'   => '✓ Request approved',
        'flash_rej'    => '✓ Request rejected',
        'flash_del'    => '✓ Request deleted',
        'flash_err'    => '⚠️ Please fill everything and pick at least one bank',
        'only_mine'    => 'showing your requests only',
        'no_create'    => 'You can view requests but not create them',
        'filter_all'   => 'All statuses',
        'search_ph'    => 'Search by customer, phone or car...',
    ],
];
$L = $t[$lang];

$banks     = inst_banks();
$percents  = inst_down_payments();
$catalog   = inst_car_catalog($pdo);

/* ─── Load requests (respecting the view_all permission) ─── */
$fStatus = $_GET['status'] ?? '';
if (!in_array($fStatus, ['pending', 'approved', 'rejected'], true)) $fStatus = '';

$where  = [];
$params = [];
if (!$canViewAll) { $where[] = 'r.created_by = ?'; $params[] = $me; }
$wSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$requests = $pdo->prepare("SELECT r.* FROM installment_requests r $wSql ORDER BY r.id DESC");
$requests->execute($params);
$requests = $requests->fetchAll(PDO::FETCH_ASSOC);

/* Bank lines for those requests */
$linesByRequest = [];
if (!empty($requests)) {
    $ids = array_column($requests, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $ls  = $pdo->prepare("SELECT * FROM installment_bank_requests WHERE request_id IN ($in) ORDER BY id ASC");
    $ls->execute($ids);
    foreach ($ls->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $linesByRequest[(int)$line['request_id']][] = $line;
    }
}

/* Stats + status filter (computed from the visible set) */
$statTotal = count($requests);
$statP = $statA = $statR = 0;
$visible = [];
foreach ($requests as $r) {
    $lines = $linesByRequest[(int)$r['id']] ?? [];
    $st    = inst_overall_status($lines);
    if ($st === 'pending')  $statP++;
    if ($st === 'approved') $statA++;
    if ($st === 'rejected') $statR++;
    if ($fStatus === '' || $fStatus === $st) {
        $r['_status'] = $st;
        $r['_lines']  = $lines;
        $visible[]    = $r;
    }
}

$statusPill = [
    'pending'  => ['cls' => 'p-wait', 'txt' => $L['waiting']],
    'approved' => ['cls' => 'p-ok',   'txt' => $L['approved']],
    'rejected' => ['cls' => 'p-no',   'txt' => $L['rejected']],
];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $L['title'] ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root {
    --bg-card:  rgba(15,23,42,.93);
    --border:   rgba(255,255,255,.07);
    --green:    #22c55e;
    --purple:   #9333ea;
    --blue:     #2563eb;
    --red:      #ef4444;
    --amber:    #f59e0b;
    --text:     #f1f5f9;
    --muted:    #64748b;
    --muted-l:  #94a3b8;
    --shadow:   0 4px 28px rgba(0,0,0,.45);
}
html[lang="ar"] body { font-family:'Cairo',sans-serif; }
html[lang="en"] body { font-family:'Inter',sans-serif; }
body {
    background:linear-gradient(150deg,#020617 0%,#0a0f1e 55%,#05101f 100%);
    color:var(--text); min-height:100vh; padding-bottom:70px;
}
.wrap { max-width:1200px; margin:auto; padding:16px 20px; }

/* Header */
.header { background:var(--bg-card); border:1px solid var(--border); border-radius:22px;
          padding:22px 26px; margin-bottom:18px; box-shadow:var(--shadow); }
.header-top { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; }
.page-title { font-size:27px; font-weight:900;
    background:linear-gradient(90deg,#22c55e,#86efac);
    -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; }
.page-subtitle { font-size:13px; color:var(--muted-l); margin-top:4px; }
.nav-group { display:flex; gap:8px; flex-wrap:wrap; }
.nav-btn { text-decoration:none; padding:9px 16px; border-radius:12px; font-weight:700;
           font-size:13px; color:#fff; display:flex; align-items:center; gap:6px; transition:transform .2s; }
.nav-btn:hover { transform:translateY(-2px); }
.nav-btn.dash { background:var(--blue); }
.lang-row { display:flex; gap:8px; margin-top:14px; }
.lang-btn { text-decoration:none; padding:7px 14px; border-radius:10px; background:#111827;
            color:var(--muted-l); font-size:12px; font-weight:700; border:1px solid var(--border); }
.lang-btn.active { background:var(--purple); color:#fff; border-color:transparent; }

/* Flash */
.flash { border-radius:14px; padding:13px 18px; font-weight:800; font-size:14px; margin-bottom:16px;
         background:rgba(34,197,94,.1); border:1px solid rgba(34,197,94,.3); color:#4ade80; }
.flash.err { background:rgba(239,68,68,.1); border-color:rgba(239,68,68,.3); color:#fca5a5; }

/* Stats */
.stats { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:18px; }
.stat { background:var(--bg-card); border:1px solid var(--border); border-radius:20px;
        padding:18px; text-align:center; box-shadow:var(--shadow); position:relative; overflow:hidden; }
.stat::after { content:''; position:absolute; bottom:0; left:0; right:0; height:3px; }
.stat.s1::after { background:linear-gradient(90deg,#94a3b8,#e2e8f0); }
.stat.s2::after { background:linear-gradient(90deg,var(--amber),#fcd34d); }
.stat.s3::after { background:linear-gradient(90deg,var(--green),#86efac); }
.stat.s4::after { background:linear-gradient(90deg,var(--red),#fca5a5); }
.stat-l { font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); font-weight:800; }
.stat-n { font-size:38px; font-weight:900; margin-top:6px; line-height:1; }
.s1 .stat-n { color:var(--text); } .s2 .stat-n { color:var(--amber); }
.s3 .stat-n { color:var(--green); } .s4 .stat-n { color:var(--red); }

/* Card shell */
.card { background:var(--bg-card); border:1px solid var(--border); border-radius:22px;
        padding:22px; margin-bottom:18px; box-shadow:var(--shadow); }
.card-title { font-size:17px; font-weight:900; margin-bottom:6px; display:flex; align-items:center; gap:9px; }
.card-hint { font-size:12.5px; color:var(--muted-l); margin-bottom:18px; line-height:1.7; }

/* Form */
.sec-label { font-size:11px; font-weight:900; color:var(--muted); text-transform:uppercase;
             letter-spacing:.07em; margin:18px 0 10px; display:flex; align-items:center; gap:7px; }
.sec-label::after { content:''; flex:1; height:1px; background:var(--border); }
.grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:12px; }
.fld { display:flex; flex-direction:column; gap:6px; }
.fld label { font-size:12px; font-weight:700; color:var(--muted-l); }
input[type=text], input[type=tel], select {
    width:100%; height:46px; border:1px solid rgba(255,255,255,.1); outline:none;
    background:#0d1526; color:var(--text); padding:0 13px; border-radius:12px;
    font-size:14px; font-family:inherit; -webkit-appearance:none; appearance:none;
    transition:border-color .2s, box-shadow .2s;
}
input:focus, select:focus { border-color:var(--green); box-shadow:0 0 0 3px rgba(34,197,94,.12); }
select option { background:#0d1526; }
select:disabled { opacity:.45; cursor:not-allowed; }

/* Bank picker */
.bank-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(255px,1fr)); gap:10px; }
.bank-item { background:#0a1120; border:1px solid rgba(255,255,255,.07); border-radius:14px;
             padding:12px 14px; transition:border-color .2s, background .2s; }
.bank-item.on { border-color:rgba(34,197,94,.5); background:rgba(34,197,94,.06); }
.bank-head { display:flex; align-items:center; gap:10px; cursor:pointer; }
.bank-head input { width:19px; height:19px; accent-color:#22c55e; cursor:pointer; flex-shrink:0; }
.bank-name { font-size:13.5px; font-weight:800; }
.bank-pct { margin-top:10px; display:none; }
.bank-item.on .bank-pct { display:block; }
.bank-pct label { font-size:11px; font-weight:700; color:var(--muted); display:block; margin-bottom:5px; }
.bank-pct select { height:40px; font-size:13px; }
.full-note { font-size:11px; font-weight:700; color:#fcd34d; margin-top:6px; display:none; }

.btn-send { width:100%; height:52px; margin-top:20px; border:none; border-radius:14px;
            background:linear-gradient(90deg,var(--green),#16a34a); color:#002b14;
            font-weight:900; font-size:15px; font-family:inherit; cursor:pointer; transition:transform .15s; }
.btn-send:hover { transform:translateY(-2px); }

/* Filters */
.filters { display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap; }
.filters input, .filters select { height:44px; }
.filters input { flex:1; min-width:200px; }
.filters select { width:auto; min-width:150px; }

/* Request cards */
.req { background:#0a1120; border:1px solid var(--border); border-radius:18px;
       padding:16px 18px; margin-bottom:14px; }
.req.st-pending  { border-inline-start:4px solid var(--amber); }
.req.st-approved { border-inline-start:4px solid var(--green); }
.req.st-rejected { border-inline-start:4px solid var(--red); }
.req-top { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
.req-cust { font-size:16px; font-weight:900; }
.req-phone { font-size:13px; color:var(--muted-l); font-weight:700; margin-top:2px; }
.req-car { font-size:13px; color:#c4b5fd; font-weight:700; margin-top:6px; }
.req-meta { font-size:11.5px; color:var(--muted); margin-top:5px; }
.pill { font-size:11px; font-weight:800; padding:5px 12px; border-radius:50px; white-space:nowrap; }
.p-wait { background:rgba(245,158,11,.14); color:#fbbf24; border:1px solid rgba(245,158,11,.35); }
.p-ok   { background:rgba(34,197,94,.14);  color:#4ade80; border:1px solid rgba(34,197,94,.35); }
.p-no   { background:rgba(239,68,68,.12);  color:#fca5a5; border:1px solid rgba(239,68,68,.3); }

.lines { margin-top:14px; display:flex; flex-direction:column; gap:8px; }
.line { background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.06);
        border-radius:12px; padding:10px 13px; display:flex; align-items:center;
        justify-content:space-between; gap:12px; flex-wrap:wrap; }
.line-main { display:flex; align-items:center; gap:12px; flex-wrap:wrap; flex:1; min-width:0; }
.line-bank { font-size:13.5px; font-weight:800; }
.line-pct { font-size:12px; font-weight:800; color:#67e8f9;
            background:rgba(14,116,144,.16); border:1px solid rgba(14,116,144,.4);
            padding:3px 10px; border-radius:20px; }
.line-note { font-size:11.5px; color:var(--muted-l); font-style:italic; }
.line-act { display:flex; gap:7px; align-items:center; flex-wrap:wrap; }
.line-act input[type=text] { height:36px; width:150px; font-size:12px; }
.mini { height:36px; padding:0 14px; border-radius:10px; font-size:12px; font-weight:800;
        font-family:inherit; cursor:pointer; border:1px solid transparent; transition:transform .15s; }
.mini:hover { transform:translateY(-2px); }
.m-ok { background:rgba(34,197,94,.16); color:#4ade80; border-color:rgba(34,197,94,.4); }
.m-no { background:rgba(239,68,68,.14); color:#fca5a5; border-color:rgba(239,68,68,.35); }
.dec-form { display:contents; }
.req-foot { margin-top:12px; display:flex; justify-content:flex-end; }
.btn-del { background:rgba(239,68,68,.1); color:#f87171; border:1px solid rgba(239,68,68,.3);
           height:34px; padding:0 14px; border-radius:10px; font-size:12px; font-weight:800;
           font-family:inherit; cursor:pointer; }
.empty { text-align:center; padding:50px 20px; color:var(--muted); }
.empty .e-icon { font-size:48px; margin-bottom:12px; }
.notice { background:rgba(37,99,235,.08); border:1px solid rgba(37,99,235,.25);
          border-radius:14px; padding:12px 16px; font-size:13px; color:var(--muted-l); margin-bottom:16px; }

@media(max-width:900px) { .stats { grid-template-columns:1fr 1fr; } }
@media(max-width:600px) {
    .header-top { flex-direction:column; align-items:flex-start; }
    .page-title { font-size:22px; }
    .line { flex-direction:column; align-items:stretch; }
    .line-act { justify-content:flex-end; }
    .line-act input[type=text] { flex:1; width:auto; }
}
</style>
</head>
<body>
<div class="wrap">

<div class="header">
    <div class="header-top">
        <div>
            <div class="page-title">🏦 <?= $L['title'] ?></div>
            <div class="page-subtitle"><?= $L['subtitle'] ?></div>
        </div>
        <div class="nav-group">
            <a href="dashboard.php?lang=<?= $lang ?>" class="nav-btn dash">🏠 <?= $L['dashboard'] ?></a>
        </div>
    </div>
    <div class="lang-row">
        <a href="?lang=ar" class="lang-btn <?= $lang==='ar'?'active':'' ?>">🇪🇬 العربية</a>
        <a href="?lang=en" class="lang-btn <?= $lang==='en'?'active':'' ?>">🇺🇸 English</a>
    </div>
</div>

<?php if (isset($_GET['sent'])): ?>     <div class="flash"><?= $L['flash_sent'] ?></div>
<?php elseif (isset($_GET['approved'])): ?><div class="flash"><?= $L['flash_appr'] ?></div>
<?php elseif (isset($_GET['rejected'])): ?><div class="flash"><?= $L['flash_rej'] ?></div>
<?php elseif (isset($_GET['deleted'])): ?> <div class="flash"><?= $L['flash_del'] ?></div>
<?php elseif (isset($_GET['inst_err'])): ?><div class="flash err"><?= $L['flash_err'] ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="stats">
    <div class="stat s1"><div class="stat-l"><?= $L['stat_total'] ?></div><div class="stat-n"><?= $statTotal ?></div></div>
    <div class="stat s2"><div class="stat-l"><?= $L['stat_pending'] ?></div><div class="stat-n"><?= $statP ?></div></div>
    <div class="stat s3"><div class="stat-l"><?= $L['stat_approved'] ?></div><div class="stat-n"><?= $statA ?></div></div>
    <div class="stat s4"><div class="stat-l"><?= $L['stat_rejected'] ?></div><div class="stat-n"><?= $statR ?></div></div>
</div>

<?php if (!$canViewAll): ?>
    <div class="notice">👤 <?= $L['only_mine'] ?></div>
<?php endif; ?>

<?php if ($canCreate): ?>
<!-- ═══════════ NEW REQUEST ═══════════ -->
<div class="card">
    <div class="card-title">📝 <?= $L['new_request'] ?></div>
    <div class="card-hint"><?= $L['form_hint'] ?></div>

    <form method="POST" action="installment_action.php" id="instForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="lang" value="<?= $lang ?>">

        <div class="sec-label">👤 <?= $L['customer_data'] ?></div>
        <div class="grid">
            <div class="fld">
                <label><?= $L['cust_name'] ?></label>
                <input type="text" name="customer_name" required autocomplete="off">
            </div>
            <div class="fld">
                <label><?= $L['cust_phone'] ?></label>
                <input type="tel" name="customer_phone" required autocomplete="off" inputmode="tel">
            </div>
        </div>

        <div class="sec-label">🚗 <?= $L['car_data'] ?></div>
        <div class="grid">
            <div class="fld">
                <label><?= $L['brand'] ?></label>
                <select name="brand" id="selBrand" required><option value=""><?= $L['select'] ?></option></select>
            </div>
            <div class="fld">
                <label><?= $L['model'] ?></label>
                <select name="model" id="selModel" required disabled><option value=""><?= $L['select'] ?></option></select>
            </div>
            <div class="fld">
                <label><?= $L['trim'] ?></label>
                <select name="trim_name" id="selTrim" disabled><option value=""><?= $L['select'] ?></option></select>
            </div>
            <div class="fld">
                <label><?= $L['year'] ?></label>
                <select name="car_year" id="selYear" disabled><option value=""><?= $L['select'] ?></option></select>
            </div>
        </div>

        <div class="sec-label">🏦 <?= $L['banks_data'] ?></div>
        <div class="card-hint" style="margin-bottom:12px;"><?= $L['pick_banks'] ?></div>
        <div class="bank-grid">
            <?php foreach ($banks as $key => $names): ?>
            <div class="bank-item" id="bank-<?= $key ?>">
                <label class="bank-head">
                    <input type="checkbox" name="banks[]" value="<?= $key ?>"
                           onchange="toggleBank(this,'<?= $key ?>')">
                    <span class="bank-name"><?= htmlspecialchars($names[$lang]) ?></span>
                </label>
                <div class="bank-pct">
                    <label><?= $L['down_payment'] ?></label>
                    <select name="down_payment[<?= $key ?>]" onchange="pctChanged(this,'<?= $key ?>')">
                        <?php foreach ($percents as $p): ?>
                            <option value="<?= $p ?>" <?= $p === 20 ? 'selected' : '' ?>><?= $p ?>%</option>
                        <?php endforeach; ?>
                    </select>
                    <div class="full-note" id="full-<?= $key ?>">💰 <?= $L['full_payment'] ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <button type="submit" class="btn-send"><?= $L['send'] ?></button>
    </form>
</div>
<?php else: ?>
    <div class="notice">🔒 <?= $L['no_create'] ?></div>
<?php endif; ?>

<!-- ═══════════ REQUESTS ═══════════ -->
<div class="card">
    <div class="card-title">📋 <?= $L['requests'] ?> <span class="pill p-wait" style="margin-inline-start:auto;"><?= count($visible) ?></span></div>

    <div class="filters">
        <input type="text" id="reqSearch" placeholder="<?= $L['search_ph'] ?>" autocomplete="off">
        <select onchange="location.href='?lang=<?= $lang ?>&status='+this.value">
            <option value=""          <?= $fStatus===''         ?'selected':'' ?>><?= $L['filter_all'] ?></option>
            <option value="pending"   <?= $fStatus==='pending'  ?'selected':'' ?>><?= $L['stat_pending'] ?></option>
            <option value="approved"  <?= $fStatus==='approved' ?'selected':'' ?>><?= $L['stat_approved'] ?></option>
            <option value="rejected"  <?= $fStatus==='rejected' ?'selected':'' ?>><?= $L['stat_rejected'] ?></option>
        </select>
    </div>

    <?php if (empty($visible)): ?>
        <div class="empty"><div class="e-icon">🏦</div><p><?= $L['no_requests'] ?></p></div>
    <?php else: ?>
        <?php foreach ($visible as $r):
            $st      = $r['_status'];
            $lines   = $r['_lines'];
            $carTxt  = trim($r['brand'].' '.$r['model'].' '.($r['trim_name'] ?? '').' '.($r['car_year'] ?? ''));
            $blob    = mb_strtolower($r['customer_name'].' '.$r['customer_phone'].' '.$carTxt);
        ?>
        <div class="req st-<?= $st ?>" data-search="<?= htmlspecialchars($blob) ?>">
            <div class="req-top">
                <div>
                    <div class="req-cust">👤 <?= htmlspecialchars($r['customer_name']) ?></div>
                    <div class="req-phone">📞 <?= htmlspecialchars($r['customer_phone']) ?></div>
                    <div class="req-car">🚗 <?= htmlspecialchars($carTxt) ?></div>
                    <div class="req-meta"><?= $L['by'] ?>: <?= htmlspecialchars($r['created_by']) ?> · <?= date('d M Y · h:i A', strtotime($r['created_at'])) ?></div>
                </div>
                <span class="pill <?= $statusPill[$st]['cls'] ?>"><?= $statusPill[$st]['txt'] ?></span>
            </div>

            <div class="lines">
                <?php foreach ($lines as $line):
                    $ls = $line['status'] ?? 'pending';
                ?>
                <div class="line">
                    <div class="line-main">
                        <span class="line-bank">🏦 <?= htmlspecialchars(inst_bank_name($line['bank_key'], $lang)) ?></span>
                        <span class="line-pct"><?= $L['down_payment'] ?>: <?= (int)$line['down_payment'] ?>%<?= (int)$line['down_payment'] === 100 ? ' · '.$L['full_payment'] : '' ?></span>
                        <span class="pill <?= $statusPill[$ls]['cls'] ?>"><?= $statusPill[$ls]['txt'] ?></span>
                        <?php if ($ls !== 'pending'): ?>
                            <span class="line-note">
                                <?= $L['decided_by'] ?>: <?= htmlspecialchars($line['decided_by'] ?? '—') ?>
                                <?php if (!empty($line['decision_note'])): ?>
                                    · <?= $L['note'] ?>: <?= htmlspecialchars($line['decision_note']) ?>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if ($canDecide && $ls === 'pending'): ?>
                    <div class="line-act">
                        <form method="POST" action="installment_action.php" class="dec-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="decide">
                            <input type="hidden" name="line_id" value="<?= (int)$line['id'] ?>">
                            <input type="hidden" name="lang" value="<?= $lang ?>">
                            <input type="text" name="decision_note" placeholder="<?= $L['note_ph'] ?>" autocomplete="off">
                            <button type="submit" name="decision" value="approved" class="mini m-ok"><?= $L['accept'] ?></button>
                            <button type="submit" name="decision" value="rejected" class="mini m-no"><?= $L['reject'] ?></button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($canDelete): ?>
            <div class="req-foot">
                <form method="POST" action="installment_action.php"
                      onsubmit="return confirm(<?= htmlspecialchars(json_encode($L['del_confirm'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>);">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="lang" value="<?= $lang ?>">
                    <button type="submit" class="btn-del"><?= $L['delete'] ?></button>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</div><!-- /.wrap -->

<script>
/* ── Cascading car dropdowns, straight from the database ── */
const CATALOG = <?= json_encode($catalog, JSON_UNESCAPED_UNICODE) ?>;
const SELECT_TXT = <?= json_encode($L['select'], JSON_UNESCAPED_UNICODE) ?>;

const selBrand = document.getElementById('selBrand');
const selModel = document.getElementById('selModel');
const selTrim  = document.getElementById('selTrim');
const selYear  = document.getElementById('selYear');

function uniq(arr) { return [...new Set(arr.filter(v => v !== '' && v != null))].sort(); }
function fill(sel, values, enable) {
    sel.innerHTML = '<option value="">' + SELECT_TXT + '</option>';
    values.forEach(v => {
        const o = document.createElement('option');
        o.value = v; o.textContent = v;
        sel.appendChild(o);
    });
    sel.disabled = !enable || values.length === 0;
}

if (selBrand) {
    fill(selBrand, uniq(CATALOG.map(c => c.brand)), true);

    selBrand.addEventListener('change', () => {
        const b = selBrand.value;
        fill(selModel, uniq(CATALOG.filter(c => c.brand === b).map(c => c.model)), !!b);
        fill(selTrim, [], false);
        fill(selYear, [], false);
    });
    selModel.addEventListener('change', () => {
        const b = selBrand.value, m = selModel.value;
        fill(selTrim, uniq(CATALOG.filter(c => c.brand === b && c.model === m).map(c => c.trim)), !!m);
        fill(selYear, uniq(CATALOG.filter(c => c.brand === b && c.model === m).map(c => c.year)), !!m);
    });
    selTrim.addEventListener('change', () => {
        const b = selBrand.value, m = selModel.value, tr = selTrim.value;
        const years = uniq(CATALOG.filter(c => c.brand === b && c.model === m && (!tr || c.trim === tr)).map(c => c.year));
        const keep = selYear.value;
        fill(selYear, years, true);
        if (years.includes(keep)) selYear.value = keep;
    });
}

/* ── Bank picker: tick a bank → its own down-payment select appears ── */
function toggleBank(cb, key) {
    document.getElementById('bank-' + key).classList.toggle('on', cb.checked);
}
function pctChanged(sel, key) {
    // 100% = paying in full, so there is no down payment / installment.
    document.getElementById('full-' + key).style.display = (sel.value === '100') ? 'block' : 'none';
}

/* ── Require at least one bank before sending ── */
const instForm = document.getElementById('instForm');
if (instForm) {
    instForm.addEventListener('submit', e => {
        if (!instForm.querySelectorAll('input[name="banks[]"]:checked').length) {
            e.preventDefault();
            alert(<?= json_encode($L['flash_err'], JSON_UNESCAPED_UNICODE) ?>);
        }
    });
}

/* ── Live search across requests ── */
const reqSearch = document.getElementById('reqSearch');
if (reqSearch) {
    const cards = Array.from(document.querySelectorAll('.req[data-search]'));
    reqSearch.addEventListener('input', () => {
        const q = reqSearch.value.trim().toLowerCase();
        cards.forEach(c => {
            c.style.display = (q === '' || (c.dataset.search || '').indexOf(q) !== -1) ? '' : 'none';
        });
    });
}
</script>
</body>
</html>
