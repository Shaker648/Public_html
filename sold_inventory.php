<?php

require 'auth.php';
require 'config.php';
require 'sold_helpers.php';

perm_require('page.sold_inventory');

$lang = $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar', 'en'])) $lang = 'ar';
$dir = $lang === 'ar' ? 'rtl' : 'ltr';

// Revert-a-sale feature: make sure the columns exist, and only count/list
// ACTIVE (non-reverted) sales everywhere on this page.
$soldColOk    = ensure_sold_revert_columns($pdo);
$activeSold   = sold_active_sql($soldColOk, 'sc');   // for aliased queries (sc.*)
$activeSoldNA = sold_active_sql($soldColOk, '');     // for un-aliased queries

// Per-card action permissions (admin only by default)
$canRevertSale = can('sold.revert');
$canEditSale   = can('sold.edit');

// CSRF token for the revert / edit actions
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$t = [
    'ar' => [
        'title'            => 'تحليلات المبيعات',
        'subtitle'         => 'أداء المبيعات والبائعين',
        'dashboard'        => 'الرئيسية',
        'inventory'        => 'المخزون',
        'total_sold'       => 'إجمالي المبيعات',
        'this_month'       => 'مبيعات هذا الشهر',
        'this_year'        => 'مبيعات هذا العام',
        'top_seller'       => 'أفضل بائع',
        'leaderboard'      => 'ترتيب البائعين',
        'monthly'          => 'المبيعات الشهرية',
        'by_branch'        => 'المبيعات حسب الفرع',
        'top_models'       => 'الأكثر مبيعاً',
        'split'            => 'عملاء مقابل تجار',
        'salesman'         => 'البائع',
        'sales'            => 'المبيعات',
        'customer'         => 'عميل',
        'dealer'           => 'تاجر',
        'branch'           => 'الفرع',
        'model'            => 'الموديل',
        'count'            => 'العدد',
        'no_data'          => 'لا توجد بيانات بعد',
        'rank'             => '#',
        'cars'             => 'سيارة',
        'filter_title'     => 'تصفية البيانات',
        'date_from'        => 'من تاريخ',
        'date_to'          => 'إلى تاريخ',
        'all_sellers'      => 'كل البائعين',
        'all_branches'     => 'كل الفروع',
        'all_types'        => 'كل الأنواع',
        'sale_type'        => 'نوع البيع',
        'filter_btn'       => 'تصفية',
        'reset'            => 'إعادة تعيين',
        'customer_vs'      => 'عملاء',
        'dealer_vs'        => 'تجار',

        /* ── Sold cards ── */
        'sold_list'        => 'سجل السيارات المباعة',
        'sold_by_label'    => 'البائع',
        'sold_branch_lbl'  => 'الفرع',
        'sold_date'        => 'تاريخ البيع',
        'cust_name'        => 'اسم العميل',
        'cust_phone'       => 'هاتف العميل',
        'dealer_name_lbl'  => 'اسم التاجر',
        'chassis'          => 'الشاسيه',
        'color'            => 'اللون',
        'trim'             => 'الفئة',
        'year'             => 'السنة',
        'notes'            => 'ملاحظات',
        'showing'          => 'عرض',
        'results'          => 'نتيجة',

        /* ── Revert / edit a sale ── */
        'edit_buyer'      => 'تعديل المشتري',
        'revert_stock'    => 'إرجاع للمخزون',
        'revert_confirm'  => 'هل تريد إرجاع هذه السيارة إلى المخزون؟ سترجع كسيارة متاحة، ويظهر في رحلتها أنها بيعت ثم رجعت.',
        'reverted_ok'     => '✓ تم إرجاع السيارة إلى المخزون بنجاح',
        'edited_ok'       => '✓ تم تحديث بيانات المشتري',
        'edit_sale_title' => 'تعديل بيانات المشتري',
        'edit_sale_hint'  => 'عدّل اسم/هاتف العميل أو اسم التاجر الذي بيعت له السيارة فقط.',
        'save'            => '💾 حفظ',
        'cancel'          => 'إلغاء',

        /* ── Vault modal ── */
        'vault_entering'   => 'جارٍ الدخول إلى المنطقة الآمنة...',
        'vault_title'      => '🔐 المنطقة الأكثر حماية في النظام',
        'vault_sub'        => 'وصول للمديرين فقط',
        'vault_l1'         => '🛡️ تشفير من طرف إلى طرف',
        'vault_l2'         => '🔒 حماية متعددة الطبقات',
        'vault_l3'         => '📋 تسجيل كامل لعمليات الوصول',
        'vault_l4'         => '🔑 صلاحيات المسؤول فقط',
        'vault_l5'         => '⚡ جلسة محمية ومُشفَّرة',
        'vault_warning'    => 'جميع العمليات مُسجَّلة ومراقَبة. وصولك مُرتبط بهويتك.',
        'vault_btn'        => 'أفهم — أدخل القبو',
        'vault_user'       => 'مُحقَّق من المسؤول',
    ],
    'en' => [
        'title'            => 'Sales Analytics',
        'subtitle'         => 'Sales & Salesman Performance',
        'dashboard'        => 'Dashboard',
        'inventory'        => 'Inventory',
        'total_sold'       => 'Total Sold',
        'this_month'       => 'This Month',
        'this_year'        => 'This Year',
        'top_seller'       => 'Top Salesman',
        'leaderboard'      => 'Salesman Leaderboard',
        'monthly'          => 'Monthly Sales',
        'by_branch'        => 'Sales by Branch',
        'top_models'       => 'Best Sellers',
        'split'            => 'Customer vs Dealer',
        'salesman'         => 'Salesman',
        'sales'            => 'Sales',
        'customer'         => 'Customer',
        'dealer'           => 'Dealer',
        'branch'           => 'Branch',
        'model'            => 'Model',
        'count'            => 'Count',
        'no_data'          => 'No data yet',
        'rank'             => '#',
        'cars'             => 'cars',
        'filter_title'     => 'Filter Data',
        'date_from'        => 'Date From',
        'date_to'          => 'Date To',
        'all_sellers'      => 'All Salesmen',
        'all_branches'     => 'All Branches',
        'all_types'        => 'All Types',
        'sale_type'        => 'Sale Type',
        'filter_btn'       => 'Apply',
        'reset'            => 'Reset',
        'customer_vs'      => 'Customers',
        'dealer_vs'        => 'Dealers',

        /* ── Sold cards ── */
        'sold_list'        => 'Sold Cars Log',
        'sold_by_label'    => 'Sold by',
        'sold_branch_lbl'  => 'Branch',
        'sold_date'        => 'Sold date',
        'cust_name'        => 'Customer name',
        'cust_phone'       => 'Customer phone',
        'dealer_name_lbl'  => 'Dealer name',
        'chassis'          => 'Chassis',
        'color'            => 'Color',
        'trim'             => 'Trim',
        'year'             => 'Year',
        'notes'            => 'Notes',
        'showing'          => 'Showing',
        'results'          => 'results',

        /* ── Revert / edit a sale ── */
        'edit_buyer'      => 'Edit buyer',
        'revert_stock'    => 'Return to stock',
        'revert_confirm'  => 'Return this car to inventory? It becomes available again, and its timeline will show it was sold then returned.',
        'reverted_ok'     => '✓ Car returned to inventory',
        'edited_ok'       => '✓ Buyer info updated',
        'edit_sale_title' => 'Edit buyer info',
        'edit_sale_hint'  => 'Edit only the customer name/phone or the dealer name the car was sold to.',
        'save'            => '💾 Save',
        'cancel'          => 'Cancel',

        /* ── Vault modal ── */
        'vault_entering'   => 'Entering secure vault...',
        'vault_title'      => '🔐 Most Secured Area of the System',
        'vault_sub'        => 'Admin Access Only',
        'vault_l1'         => '🛡️ End-to-End Encryption',
        'vault_l2'         => '🔒 Multi-Layer Protection',
        'vault_l3'         => '📋 Full Access Audit Log',
        'vault_l4'         => '🔑 Admin Privileges Required',
        'vault_l5'         => '⚡ Encrypted & Protected Session',
        'vault_warning'    => 'All actions are logged and monitored. Your access is identity-bound.',
        'vault_btn'        => 'I Understand — Enter Vault',
        'vault_user'       => 'Admin Verified',
    ],
];

/* ─── Active Filters ─── */
$fFrom   = trim($_GET['date_from'] ?? '');
$fTo     = trim($_GET['date_to']   ?? '');
$fSeller = trim($_GET['seller']    ?? '');
$fBranch = trim($_GET['branch']    ?? '');
$fType   = trim($_GET['sale_type'] ?? '');

$where  = [];
$params = [];
$where[] = $activeSold;   // exclude reverted sales from every filtered query
if ($fFrom !== '')   { $where[] = "sc.sold_at >= ?"; $params[] = $fFrom . ' 00:00:00'; }
if ($fTo !== '')     { $where[] = "sc.sold_at <= ?"; $params[] = $fTo   . ' 23:59:59'; }
if ($fSeller !== '') { $where[] = "(COALESCE(NULLIF(sc.salesman,''), sc.sold_by) = ?)"; $params[] = $fSeller; }
if ($fBranch !== '') { $where[] = "sc.sold_branch = ?"; $params[] = $fBranch; }
if ($fType !== '')   { $where[] = "sc.sale_type = ?"; $params[] = $fType; }

$wClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

function fq($pdo, $sql, $params) {
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return $s;
}

/* ─── Stats ─── */
$totalSold     = (int)fq($pdo, "SELECT COUNT(*) FROM sold_cars sc $wClause", $params)->fetchColumn();
$soldMonth     = (int)$pdo->query("SELECT COUNT(*) FROM sold_cars WHERE MONTH(sold_at)=MONTH(CURDATE()) AND YEAR(sold_at)=YEAR(CURDATE()) AND $activeSoldNA")->fetchColumn();
$soldYear      = (int)$pdo->query("SELECT COUNT(*) FROM sold_cars WHERE YEAR(sold_at)=YEAR(CURDATE()) AND $activeSoldNA")->fetchColumn();
$soldPrevMonth = (int)$pdo->query("SELECT COUNT(*) FROM sold_cars WHERE MONTH(sold_at)=MONTH(DATE_SUB(CURDATE(),INTERVAL 1 MONTH)) AND YEAR(sold_at)=YEAR(DATE_SUB(CURDATE(),INTERVAL 1 MONTH)) AND $activeSoldNA")->fetchColumn();
$monthDiff     = $soldMonth - $soldPrevMonth;

/* ─── Leaderboard ─── */
$lbSQL = "
    SELECT COALESCE(NULLIF(sc.salesman,''), sc.sold_by) AS seller,
           SUM(sc.sale_type='customer') AS customer_sales,
           SUM(sc.sale_type='dealer')   AS dealer_sales,
           COUNT(*) AS total
    FROM sold_cars sc $wClause
    GROUP BY seller ORDER BY total DESC
";
$leaderboard    = fq($pdo, $lbSQL, $params)->fetchAll(PDO::FETCH_ASSOC);
$topSeller      = $leaderboard[0]['seller'] ?? '—';
$topSellerTotal = (int)($leaderboard[0]['total'] ?? 0);

/* ─── By Branch ─── */
$byBranch = fq($pdo,
    "SELECT sc.sold_branch, COUNT(*) AS cnt FROM sold_cars sc $wClause GROUP BY sc.sold_branch ORDER BY cnt DESC",
    $params)->fetchAll(PDO::FETCH_ASSOC);

/* ─── Top Models ─── */
$topModels = fq($pdo, "
    SELECT c.brand, c.model, COUNT(*) AS cnt
    FROM sold_cars sc JOIN cars c ON sc.car_id = c.id $wClause
    GROUP BY c.brand, c.model ORDER BY cnt DESC LIMIT 8
", $params)->fetchAll(PDO::FETCH_ASSOC);

/* ─── Monthly (12 months) ─── */
$monthlyRaw = $pdo->query("
    SELECT DATE_FORMAT(sold_at,'%Y-%m') AS ym, COUNT(*) AS cnt
    FROM sold_cars
    WHERE sold_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND $activeSoldNA
    GROUP BY ym ORDER BY ym ASC
")->fetchAll(PDO::FETCH_ASSOC);
$months = [];
for ($i = 11; $i >= 0; $i--) $months[date('Y-m', strtotime("-$i months"))] = 0;
foreach ($monthlyRaw as $r) if (isset($months[$r['ym']])) $months[$r['ym']] = (int)$r['cnt'];

/* ─── Customer vs Dealer ─── */
$splitRaw  = fq($pdo,
    "SELECT sc.sale_type, COUNT(*) AS cnt FROM sold_cars sc $wClause GROUP BY sc.sale_type",
    $params)->fetchAll(PDO::FETCH_KEY_PAIR);
$custCount = (int)($splitRaw['customer'] ?? 0);
$dealCount = (int)($splitRaw['dealer']   ?? 0);

/* ─── Sold Cars list (respects the same filters) ─── */
$soldCardsSQL = "
    SELECT sc.*,
           c.brand, c.model, c.trim_name, c.car_year, c.color, c.chassis,
           col.color_ar, col.color_en
    FROM sold_cars sc
    LEFT JOIN cars c     ON sc.car_id = c.id
    LEFT JOIN colors col ON c.color   = col.color_en
    $wClause
    ORDER BY sc.sold_at DESC
";
$soldCards = fq($pdo, $soldCardsSQL, $params)->fetchAll(PDO::FETCH_ASSOC);

/* ─── Filter dropdowns ─── */
$allSellers  = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(salesman,''), sold_by) AS s FROM sold_cars WHERE $activeSoldNA ORDER BY s")->fetchAll(PDO::FETCH_COLUMN);
$allBranches = $pdo->query("SELECT DISTINCT sold_branch FROM sold_cars WHERE sold_branch IS NOT NULL AND sold_branch != '' AND $activeSoldNA ORDER BY sold_branch")->fetchAll(PDO::FETCH_COLUMN);

/* ─── JSON for charts ─── */
$chartMonths      = json_encode(array_map(fn($m) => date('M y', strtotime($m.'-01')), array_keys($months)));
$chartMonthVal    = json_encode(array_values($months));
$chartSellers     = json_encode(array_column($leaderboard, 'seller'));
$chartSellerV     = json_encode(array_map('intval', array_column($leaderboard, 'total')));
$chartCustSeller  = json_encode(array_map('intval', array_column($leaderboard, 'customer_sales')));
$chartDealSeller  = json_encode(array_map('intval', array_column($leaderboard, 'dealer_sales')));

/* Branch chart */
$chartBranchLabels = json_encode(array_column($byBranch, 'sold_branch'));
$chartBranchVals   = json_encode(array_map(fn($b) => (int)$b['cnt'], $byBranch));
$branchCount       = count($byBranch);

$username = htmlspecialchars($_SESSION['username'] ?? 'Admin');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<meta name="theme-color" content="#020617">
<title><?= $t[$lang]['title'] ?></title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root {
    --bg-deep:  #020617;
    --bg-card:  rgba(15,23,42,.93);
    --border:   rgba(255,255,255,.07);
    --green:    #22c55e;
    --green-d:  rgba(34,197,94,.12);
    --purple:   #9333ea;
    --purple-d: rgba(147,51,234,.12);
    --blue:     #2563eb;
    --blue-d:   rgba(37,99,235,.12);
    --amber:    #f59e0b;
    --amber-d:  rgba(245,158,11,.12);
    --red:      #ef4444;
    --text:     #f1f5f9;
    --muted:    #64748b;
    --muted-l:  #94a3b8;
    --r-card:   22px;
    --r-btn:    12px;
    --shadow:   0 4px 28px rgba(0,0,0,.45);
}
html[lang="ar"] body { font-family:'Cairo',sans-serif; }
html[lang="en"] body { font-family:'Inter',sans-serif; }

body {
    background: linear-gradient(150deg,#020617 0%,#0a0f1e 55%,#05101f 100%);
    color: var(--text);
    min-height: 100vh;
    padding-bottom: 80px;
    overflow-x: hidden;
}

#vaultOverlay {
    position: fixed; inset: 0; z-index: 9999;
    background: #020617;
    display: flex; align-items: center; justify-content: center;
    flex-direction: column;
    overflow: hidden;
}
#vaultOverlay::before {
    content: '';
    position: absolute; inset: 0;
    background: repeating-linear-gradient(0deg, transparent, transparent 2px, rgba(34,197,94,.015) 2px, rgba(34,197,94,.015) 4px);
    pointer-events: none;
    animation: scanMove 8s linear infinite;
}
@keyframes scanMove { 0% { background-position: 0 0; } 100% { background-position: 0 200px; } }
#vaultOverlay::after {
    content: '';
    position: absolute;
    width: 600px; height: 600px; border-radius: 50%;
    background: radial-gradient(circle, rgba(34,197,94,.07) 0%, transparent 70%);
    pointer-events: none;
    animation: pulseGlow 3s ease-in-out infinite;
}
@keyframes pulseGlow { 0%,100% { transform: scale(1); opacity: .6; } 50% { transform: scale(1.15); opacity: 1; } }
#vaultLoading { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 20px; z-index: 2; }
.vault-lock-anim { font-size: 72px; animation: lockShake .5s ease-in-out 2, lockGlow 1.5s ease-in-out infinite; filter: drop-shadow(0 0 20px rgba(34,197,94,.6)); }
@keyframes lockShake { 0%,100% { transform: rotate(0); } 25% { transform: rotate(-8deg); } 75% { transform: rotate(8deg); } }
@keyframes lockGlow { 0%,100% { filter: drop-shadow(0 0 10px rgba(34,197,94,.4)); } 50% { filter: drop-shadow(0 0 30px rgba(34,197,94,.9)); } }
.vault-loading-text { font-size: 16px; font-weight: 700; color: var(--green); letter-spacing: .15em; text-transform: uppercase; }
.vault-loading-dots span { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--green); margin: 0 4px; animation: dotBounce .9s ease-in-out infinite; }
.vault-loading-dots span:nth-child(2) { animation-delay: .15s; }
.vault-loading-dots span:nth-child(3) { animation-delay: .30s; }
@keyframes dotBounce { 0%,80%,100% { transform: scale(0); opacity: .3; } 40% { transform: scale(1); opacity: 1; } }
.vault-progress-wrap { width: 280px; height: 3px; background: rgba(34,197,94,.12); border-radius: 50px; overflow: hidden; }
.vault-progress-bar { height: 100%; background: linear-gradient(90deg, var(--green), #86efac); border-radius: 50px; width: 0%; animation: progressFill 2s ease-in-out forwards; }
@keyframes progressFill { 0% { width: 0%; } 100% { width: 100%; } }
#vaultModal {
    position: relative; z-index: 3; display: none; flex-direction: column; align-items: center; text-align: center;
    max-width: 520px; width: calc(100% - 40px);
    background: rgba(10,16,35,.98); border: 1px solid rgba(34,197,94,.3); border-radius: 28px;
    padding: 40px 36px 32px;
    box-shadow: 0 0 0 1px rgba(34,197,94,.08), 0 0 60px rgba(34,197,94,.12), 0 24px 80px rgba(0,0,0,.8);
    animation: modalAppear .5s cubic-bezier(.22,1,.36,1) both; gap: 0;
}
@keyframes modalAppear { from { opacity:0; transform:scale(.88) translateY(20px); } to { opacity:1; transform:scale(1) translateY(0); } }
#vaultModal::before, #vaultModal::after { content: ''; position: absolute; width: 40px; height: 40px; border-color: var(--green); border-style: solid; opacity: .4; }
#vaultModal::before { top: 12px; left: 12px; border-width: 2px 0 0 2px; border-radius: 6px 0 0 0; }
#vaultModal::after  { bottom: 12px; right: 12px; border-width: 0 2px 2px 0; border-radius: 0 0 6px 6px; }
.vault-modal-icon { width: 88px; height: 88px; border-radius: 24px; background: linear-gradient(135deg, rgba(34,197,94,.15), rgba(147,51,234,.12)); border: 1px solid rgba(34,197,94,.3); display: flex; align-items: center; justify-content: center; font-size: 44px; margin-bottom: 22px; box-shadow: 0 0 40px rgba(34,197,94,.15); animation: iconPulse 2s ease-in-out infinite; }
@keyframes iconPulse { 0%,100% { box-shadow: 0 0 20px rgba(34,197,94,.2); } 50% { box-shadow: 0 0 50px rgba(34,197,94,.45); } }
.vault-modal-title { font-size: 21px; font-weight: 900; color: var(--green); margin-bottom: 6px; line-height: 1.3; }
.vault-modal-sub { font-size: 13px; font-weight: 700; color: var(--muted-l); margin-bottom: 24px; letter-spacing: .08em; text-transform: uppercase; }
.vault-user-chip { display: inline-flex; align-items: center; gap: 8px; background: rgba(34,197,94,.08); border: 1px solid rgba(34,197,94,.22); border-radius: 50px; padding: 6px 16px; font-size: 13px; font-weight: 700; color: var(--green); margin-bottom: 24px; }
.vault-user-chip::before { content: '●'; font-size: 8px; animation: chipBlink .8s ease-in-out infinite; }
@keyframes chipBlink { 0%,100% { opacity: 1; } 50% { opacity: .2; } }
.vault-layers { width: 100%; display: flex; flex-direction: column; gap: 10px; margin-bottom: 24px; }
.vault-layer-item { display: flex; align-items: center; gap: 12px; background: rgba(34,197,94,.05); border: 1px solid rgba(34,197,94,.1); border-radius: 12px; padding: 11px 16px; font-size: 13.5px; font-weight: 600; color: var(--muted-l); animation: layerSlide .4s ease both; text-align: start; }
.vault-layer-item:nth-child(1) { animation-delay: .05s; }
.vault-layer-item:nth-child(2) { animation-delay: .10s; }
.vault-layer-item:nth-child(3) { animation-delay: .15s; }
.vault-layer-item:nth-child(4) { animation-delay: .20s; }
.vault-layer-item:nth-child(5) { animation-delay: .25s; }
@keyframes layerSlide { from { opacity:0; transform:translateX(<?= $dir==='rtl'?'12px':'-12px' ?>); } to { opacity:1; transform:translateX(0); } }
.layer-check { width: 22px; height: 22px; border-radius: 6px; background: var(--green-d); border: 1px solid rgba(34,197,94,.3); display: flex; align-items: center; justify-content: center; color: var(--green); font-size: 12px; font-weight: 900; flex-shrink: 0; }
.vault-warning-strip { width: 100%; background: rgba(245,158,11,.06); border: 1px solid rgba(245,158,11,.2); border-radius: 12px; padding: 12px 16px; font-size: 12px; font-weight: 600; color: #fbbf24; margin-bottom: 24px; line-height: 1.5; }
.vault-enter-btn { width: 100%; height: 52px; border: none; border-radius: 14px; cursor: pointer; background: linear-gradient(90deg, #15803d, var(--green), #86efac); background-size: 200% 100%; color: #001a08; font-weight: 900; font-size: 16px; font-family: inherit; letter-spacing: .04em; transition: background-position .4s, transform .2s, box-shadow .2s; box-shadow: 0 6px 30px rgba(34,197,94,.25); animation: btnShimmer 3s ease-in-out infinite; }
@keyframes btnShimmer { 0%,100% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } }
.vault-enter-btn:hover { transform: translateY(-3px); box-shadow: 0 12px 40px rgba(34,197,94,.4); }
.vault-enter-btn:active { transform: translateY(0); }
#vaultParticles { position: absolute; inset: 0; z-index: 1; pointer-events: none; }
#mainContent { opacity: 0; transform: translateY(16px); transition: opacity .5s ease, transform .5s ease; }
#mainContent.visible { opacity: 1; transform: translateY(0); }

.wrap { max-width:1600px; margin:auto; padding:16px 20px; }
.header { background: var(--bg-card); border:1px solid var(--border); border-radius: var(--r-card); padding:22px 26px; margin-bottom:18px; backdrop-filter:blur(20px); box-shadow:var(--shadow); }
.header-top { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; }
.page-title { font-size:28px; font-weight:900; background:linear-gradient(90deg,var(--green),#86efac); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; }
.page-subtitle { font-size:13px; color:var(--muted-l); margin-top:4px; }
.nav-group { display:flex; gap:8px; flex-wrap:wrap; }
.nav-btn { text-decoration:none; padding:9px 16px; border-radius:var(--r-btn); font-weight:700; font-size:13px; color:white; display:flex; align-items:center; gap:6px; transition:transform .2s; }
.nav-btn:hover { transform:translateY(-2px); }
.nav-btn.dash { background:var(--blue); }
.nav-btn.inv  { background:var(--green); color:#002b14; }
.lang-row { display:flex; gap:8px; margin-top:14px; }
.lang-btn { text-decoration:none; padding:7px 14px; border-radius:10px; background:#111827; color:var(--muted-l); font-size:12px; font-weight:700; border:1px solid var(--border); transition:background .2s; }
.lang-btn.active { background:var(--purple); color:white; border-color:transparent; }

.stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:18px; }
.stat-card { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-card); padding:20px 18px; box-shadow:var(--shadow); position:relative; overflow:hidden; }
.stat-card::after { content:''; position:absolute; bottom:0; left:0; right:0; height:3px; }
.stat-card.c1::after { background:linear-gradient(90deg,var(--green),#86efac); }
.stat-card.c2::after { background:linear-gradient(90deg,var(--blue),#60a5fa); }
.stat-card.c3::after { background:linear-gradient(90deg,var(--purple),#c084fc); }
.stat-card.c4::after { background:linear-gradient(90deg,var(--amber),#fcd34d); }
.stat-label { font-size:12px; color:var(--muted); font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
.stat-number { font-size:44px; font-weight:900; margin-top:8px; line-height:1; }
.c1 .stat-number { color:var(--green); }
.c2 .stat-number { color:#60a5fa; }
.c3 .stat-number { color:#c084fc; }
.c4 .stat-number { color:var(--amber); font-size:22px; margin-top:10px; word-break:break-word; }
.stat-trend { margin-top:6px; font-size:12px; font-weight:700; display:flex; align-items:center; gap:4px; flex-wrap:wrap; }
.trend-up   { color:var(--green); }
.trend-down { color:var(--red); }
.trend-neutral { color:var(--muted); }

.filters-card { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-card); padding:18px 22px; margin-bottom:18px; box-shadow:var(--shadow); }
.filter-title { font-size:13px; font-weight:800; color:var(--muted-l); margin-bottom:14px; text-transform:uppercase; letter-spacing:.06em; }
.filter-grid { display:grid; grid-template-columns:1fr 1fr 1fr 1fr 1fr auto auto; gap:10px; align-items:end; }
.fg { display:flex; flex-direction:column; gap:5px; }
.fl { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; }
input[type="date"], select { width:100%; height:44px; border:1px solid rgba(255,255,255,.08); outline:none; background:#0d1526; color:var(--text); padding:0 12px; border-radius:10px; font-size:13px; font-family:inherit; transition:border-color .2s, box-shadow .2s; -webkit-appearance:none; appearance:none; }
input:focus, select:focus { border-color:var(--green); box-shadow:0 0 0 3px rgba(34,197,94,.1); }
input[type="date"]::-webkit-calendar-picker-indicator { filter:invert(1) opacity(.5); cursor:pointer; }
select option { background:#0d1526; }
.btn-apply { height:44px; padding:0 20px; border:none; border-radius:var(--r-btn); font-weight:800; font-size:13px; cursor:pointer; background:linear-gradient(90deg,var(--green),#16a34a); color:#002b14; font-family:inherit; transition:transform .2s, box-shadow .2s; }
.btn-apply:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(34,197,94,.3); }
.btn-reset-f { height:44px; padding:0 16px; border:1px solid var(--border); border-radius:var(--r-btn); background:#111827; color:var(--muted-l); font-weight:700; font-size:13px; cursor:pointer; font-family:inherit; text-decoration:none; display:flex; align-items:center; gap:5px; transition:border-color .2s; }
.btn-reset-f:hover { border-color:var(--muted); }

.grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:18px; }
.grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:18px; margin-bottom:18px; }
.panel { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-card); padding:22px; box-shadow:var(--shadow); }
.panel-title { font-size:16px; font-weight:800; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
.chart-box    { position:relative; width:100%; height:280px; }
.chart-box-sm { position:relative; width:100%; height:220px; }
.chart-branch-wrap { position:relative; width:100%; overflow:hidden; }

table { width:100%; border-collapse:collapse; }
th { text-align:start; font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); font-weight:700; padding:10px 14px; }
td { padding:11px 14px; border-top:1px solid rgba(255,255,255,.04); font-size:14px; }
tbody tr { transition:background .15s; }
tbody tr:hover { background:rgba(34,197,94,.04); }
.rank-badge { display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:9px; font-weight:800; font-size:13px; background:var(--purple-d); color:#c084fc; }
.rank-1 { background:rgba(245,158,11,.2); color:#fcd34d; }
.rank-2 { background:rgba(148,163,184,.15); color:#cbd5e1; }
.rank-3 { background:rgba(217,119,6,.15); color:#fbbf24; }
.seller-name { font-weight:700; font-size:15px; }
.pill { display:inline-block; padding:3px 10px; border-radius:50px; font-size:12px; font-weight:700; }
.pill-c { background:var(--green-d); color:var(--green); }
.pill-d { background:var(--blue-d);  color:#60a5fa; }
.total-cell { font-weight:800; color:var(--green); font-size:15px; }
.branch-bar-wrap { margin-top:6px; height:5px; background:rgba(255,255,255,.06); border-radius:50px; overflow:hidden; }
.branch-bar-fill { height:100%; border-radius:50px; background:linear-gradient(90deg,var(--blue),#60a5fa); }
.empty { text-align:center; padding:40px; color:var(--muted); font-size:15px; }
.donut-wrap { width:180px; height:180px; margin:0 auto; }
.split-legend { display:flex; flex-direction:column; gap:10px; margin-top:16px; }
.split-item { display:flex; align-items:center; justify-content:space-between; }
.split-dot-label { display:flex; align-items:center; gap:8px; font-size:14px; }
.split-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
.split-val { font-weight:800; font-size:15px; }

/* ══════ SOLD CARS CARDS (NEW) ══════ */
.sold-count-pill { background:var(--purple-d); color:#c084fc; padding:4px 14px; border-radius:50px; font-size:12px; font-weight:800; border:1px solid rgba(147,51,234,.25); margin-inline-start:auto; }
.sold-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; }
.sold-card {
    background:#0d1526; border:1px solid var(--border); border-radius:16px;
    padding:14px 16px; display:flex; flex-direction:column; gap:10px;
    transition:transform .2s, border-color .2s; position:relative; overflow:hidden;
}
.sold-card::before { content:''; position:absolute; top:0; inset-inline-start:0; width:3px; height:100%; }
.sold-card.t-customer::before { background:var(--green); }
.sold-card.t-dealer::before   { background:var(--blue); }
.sold-card:hover { transform:translateY(-3px); border-color:rgba(147,51,234,.35); }
.sold-card-head { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; }
.sold-car-name { font-size:15px; font-weight:800; color:#e2e8f0; line-height:1.3; }
.sold-car-year { font-size:11px; color:var(--muted); font-weight:700; margin-top:2px; }
.sold-type-badge { font-size:10px; font-weight:800; padding:3px 9px; border-radius:50px; white-space:nowrap; flex-shrink:0; }
.sold-type-badge.customer { background:var(--green-d); color:var(--green); border:1px solid rgba(34,197,94,.25); }
.sold-type-badge.dealer   { background:var(--blue-d);  color:#60a5fa; border:1px solid rgba(37,99,235,.25); }
.sold-rows { display:flex; flex-direction:column; gap:5px; }
.sold-row { display:flex; justify-content:space-between; gap:10px; font-size:12px; line-height:1.4; }
.sold-row .sr-label { color:var(--muted); font-weight:600; white-space:nowrap; }
.sold-row .sr-val { color:#cbd5e1; font-weight:700; text-align:end; word-break:break-word; }
.sold-row .sr-val.chassis { font-family:'Courier New',monospace; font-size:11px; color:#a3e635; letter-spacing:.3px; }
.sold-seller-tag { display:inline-flex; align-items:center; gap:5px; background:rgba(147,51,234,.10); border:1px solid rgba(147,51,234,.25); color:#c084fc; border-radius:8px; padding:4px 10px; font-size:12px; font-weight:800; }
.sold-card-foot { display:flex; justify-content:space-between; align-items:center; gap:8px; margin-top:2px; padding-top:8px; border-top:1px solid rgba(255,255,255,.05); }
.sold-date { font-size:11px; color:var(--muted); font-weight:600; }
.sold-note { background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.18); color:#fcd34d; border-radius:8px; padding:6px 10px; font-size:11.5px; font-weight:600; line-height:1.45; }

/* ── Per-card sale actions (admin) ── */
.sold-actions { display:flex; gap:8px; margin-top:4px; padding-top:10px; border-top:1px solid rgba(255,255,255,.05); }
.sa-form { display:contents; }
.sa-btn { flex:1; display:flex; align-items:center; justify-content:center; gap:5px; height:36px; border-radius:10px; font-size:12px; font-weight:800; font-family:inherit; cursor:pointer; border:1px solid transparent; transition:transform .15s, filter .15s; }
.sa-btn:hover { transform:translateY(-2px); filter:brightness(1.12); }
.sa-edit   { background:rgba(147,51,234,.14); color:#c084fc; border-color:rgba(147,51,234,.3); }
.sa-revert { background:rgba(34,197,94,.14); color:#4ade80; border-color:rgba(34,197,94,.3); }

/* ── Flash banner ── */
.flash-banner { border-radius:14px; padding:13px 18px; font-weight:800; font-size:14px; margin-bottom:16px; background:rgba(34,197,94,.1); border:1px solid rgba(34,197,94,.3); color:#4ade80; }

/* ── Edit-buyer modal ── */
.se-overlay { position:fixed; inset:0; background:rgba(2,6,23,.8); backdrop-filter:blur(6px); z-index:2000; display:none; align-items:center; justify-content:center; padding:20px; }
.se-overlay.open { display:flex; }
.se-box { background:#0f172a; border:1px solid rgba(147,51,234,.3); border-radius:24px; padding:24px; width:100%; max-width:440px; box-shadow:0 32px 72px rgba(0,0,0,.6); }
.se-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; }
.se-head h3 { color:#c084fc; font-size:18px; }
.se-x { background:none; border:none; color:#64748b; font-size:22px; cursor:pointer; line-height:1; }
.se-x:hover { color:#ef4444; }
.se-hint { color:#94a3b8; font-size:12.5px; line-height:1.6; margin-bottom:16px; }
.se-car { background:rgba(147,51,234,.08); border:1px solid rgba(147,51,234,.22); color:#e2e8f0; border-radius:12px; padding:10px 14px; font-size:13px; font-weight:700; margin-bottom:16px; }
.se-field { margin-bottom:14px; }
.se-field label { display:block; font-size:12px; font-weight:700; color:#94a3b8; margin-bottom:6px; }
.se-field input { width:100%; height:46px; border:1px solid rgba(255,255,255,.1); background:#0d1526; color:#f1f5f9; border-radius:12px; padding:0 14px; font-size:14px; font-family:inherit; outline:none; }
.se-field input:focus { border-color:rgba(147,51,234,.55); box-shadow:0 0 0 3px rgba(147,51,234,.12); }
.se-actions { display:flex; gap:10px; margin-top:18px; }
.se-btn { flex:1; height:48px; border-radius:13px; font-weight:800; font-size:14px; cursor:pointer; border:none; font-family:inherit; transition:transform .15s; }
.se-btn:hover { transform:translateY(-2px); }
.se-save { background:linear-gradient(90deg,#9333ea,#2563eb); color:#fff; }
.se-cancel { background:rgba(255,255,255,.06); color:#cbd5e1; border:1px solid rgba(255,255,255,.1); }

@media(max-width:1200px) { .filter-grid { grid-template-columns:1fr 1fr 1fr; } }
@media(max-width:900px) {
    .stats-row { grid-template-columns:1fr 1fr; }
    .grid-2, .grid-3 { grid-template-columns:1fr; }
    .filter-grid { grid-template-columns:1fr 1fr; }
}
@media(max-width:600px) {
    .stats-row { grid-template-columns:1fr 1fr; }
    .filter-grid { grid-template-columns:1fr; }
    .header-top { flex-direction:column; align-items:flex-start; }
    .page-title { font-size:22px; }
    .stat-number { font-size:36px; }
    .c4 .stat-number { font-size:18px; }
    .panel { padding:16px; }
    #vaultModal { padding:28px 20px 24px; }
    .vault-modal-title { font-size:17px; }
    .sold-grid { grid-template-columns:1fr; }
}
@media(max-width:380px) { .stats-row { grid-template-columns:1fr; } }
</style>
</head>
<body>

<div id="vaultOverlay">
    <canvas id="vaultParticles"></canvas>
    <div id="vaultLoading">
        <div class="vault-lock-anim">🔐</div>
        <div class="vault-loading-text"><?= $t[$lang]['vault_entering'] ?></div>
        <div class="vault-loading-dots"><span></span><span></span><span></span></div>
        <div class="vault-progress-wrap"><div class="vault-progress-bar"></div></div>
    </div>
    <div id="vaultModal">
        <div class="vault-modal-icon">🔐</div>
        <div class="vault-modal-title"><?= $t[$lang]['vault_title'] ?></div>
        <div class="vault-modal-sub"><?= $t[$lang]['vault_sub'] ?></div>
        <div class="vault-user-chip"><?= $t[$lang]['vault_user'] ?> — <?= $username ?></div>
        <div class="vault-layers">
            <div class="vault-layer-item"><div class="layer-check">✓</div><?= $t[$lang]['vault_l1'] ?></div>
            <div class="vault-layer-item"><div class="layer-check">✓</div><?= $t[$lang]['vault_l2'] ?></div>
            <div class="vault-layer-item"><div class="layer-check">✓</div><?= $t[$lang]['vault_l3'] ?></div>
            <div class="vault-layer-item"><div class="layer-check">✓</div><?= $t[$lang]['vault_l4'] ?></div>
            <div class="vault-layer-item"><div class="layer-check">✓</div><?= $t[$lang]['vault_l5'] ?></div>
        </div>
        <div class="vault-warning-strip">⚠️ <?= $t[$lang]['vault_warning'] ?></div>
        <button class="vault-enter-btn" onclick="enterVault()"><?= $t[$lang]['vault_btn'] ?></button>
    </div>
</div>

<div id="mainContent">
<div class="wrap">

<?php if (isset($_GET['reverted'])): ?>
    <div class="flash-banner"><?= $t[$lang]['reverted_ok'] ?></div>
<?php elseif (isset($_GET['edited'])): ?>
    <div class="flash-banner"><?= $t[$lang]['edited_ok'] ?></div>
<?php endif; ?>

<div class="header">
    <div class="header-top">
        <div>
            <div class="page-title">📊 <?= $t[$lang]['title'] ?></div>
            <div class="page-subtitle"><?= $t[$lang]['subtitle'] ?></div>
        </div>
        <div class="nav-group">
            <a href="dashboard.php?lang=<?= $lang ?>" class="nav-btn dash">🏠 <?= $t[$lang]['dashboard'] ?></a>
            <a href="dashboard.php?lang=<?= $lang ?>"  class="nav-btn inv">🚗 <?= $t[$lang]['inventory'] ?></a>
        </div>
    </div>
    <div class="lang-row">
        <a href="?lang=ar&date_from=<?= urlencode($fFrom) ?>&date_to=<?= urlencode($fTo) ?>&seller=<?= urlencode($fSeller) ?>&branch=<?= urlencode($fBranch) ?>&sale_type=<?= urlencode($fType) ?>" class="lang-btn <?= $lang==='ar'?'active':'' ?>">🇪🇬 العربية</a>
        <a href="?lang=en&date_from=<?= urlencode($fFrom) ?>&date_to=<?= urlencode($fTo) ?>&seller=<?= urlencode($fSeller) ?>&branch=<?= urlencode($fBranch) ?>&sale_type=<?= urlencode($fType) ?>" class="lang-btn <?= $lang==='en'?'active':'' ?>">🇺🇸 English</a>
    </div>
</div>

<div class="stats-row">
    <div class="stat-card c1">
        <div class="stat-label">🚗 <?= $t[$lang]['total_sold'] ?></div>
        <div class="stat-number"><?= $totalSold ?></div>
    </div>
    <div class="stat-card c2">
        <div class="stat-label">📅 <?= $t[$lang]['this_month'] ?></div>
        <div class="stat-number"><?= $soldMonth ?></div>
        <div class="stat-trend <?= $monthDiff > 0 ? 'trend-up' : ($monthDiff < 0 ? 'trend-down' : 'trend-neutral') ?>">
            <?php if ($monthDiff > 0): ?>↑ +<?= $monthDiff ?>
            <?php elseif ($monthDiff < 0): ?>↓ <?= $monthDiff ?>
            <?php else: ?>→ 0<?php endif; ?>
            <span style="font-weight:400;color:var(--muted);"> <?= $lang==='ar'?'عن الشهر الماضي':'vs last month' ?></span>
        </div>
    </div>
    <div class="stat-card c3">
        <div class="stat-label">🗓️ <?= $t[$lang]['this_year'] ?></div>
        <div class="stat-number"><?= $soldYear ?></div>
    </div>
    <div class="stat-card c4">
        <div class="stat-label">🏆 <?= $t[$lang]['top_seller'] ?></div>
        <div class="stat-number"><?= htmlspecialchars($topSeller) ?></div>
        <?php if ($topSellerTotal): ?>
        <div class="stat-trend trend-neutral"><?= $topSellerTotal ?> <?= $t[$lang]['cars'] ?></div>
        <?php endif; ?>
    </div>
</div>

<form method="GET" class="filters-card">
    <input type="hidden" name="lang" value="<?= $lang ?>">
    <div class="filter-title">🔽 <?= $t[$lang]['filter_title'] ?></div>
    <div class="filter-grid">
        <div class="fg">
            <span class="fl">📅 <?= $t[$lang]['date_from'] ?></span>
            <input type="date" name="date_from" value="<?= htmlspecialchars($fFrom) ?>">
        </div>
        <div class="fg">
            <span class="fl">📅 <?= $t[$lang]['date_to'] ?></span>
            <input type="date" name="date_to" value="<?= htmlspecialchars($fTo) ?>">
        </div>
        <div class="fg">
            <span class="fl">👤 <?= $t[$lang]['salesman'] ?></span>
            <select name="seller">
                <option value=""><?= $t[$lang]['all_sellers'] ?></option>
                <?php foreach ($allSellers as $s): ?>
                <option value="<?= htmlspecialchars($s) ?>" <?= $fSeller===$s?'selected':'' ?>><?= htmlspecialchars($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <span class="fl">📍 <?= $t[$lang]['branch'] ?></span>
            <select name="branch">
                <option value=""><?= $t[$lang]['all_branches'] ?></option>
                <?php foreach ($allBranches as $b): ?>
                <option value="<?= htmlspecialchars($b) ?>" <?= $fBranch===$b?'selected':'' ?>><?= htmlspecialchars($b) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <span class="fl">🏷️ <?= $t[$lang]['sale_type'] ?></span>
            <select name="sale_type">
                <option value=""><?= $t[$lang]['all_types'] ?></option>
                <option value="customer" <?= $fType==='customer'?'selected':'' ?>><?= $t[$lang]['customer'] ?></option>
                <option value="dealer"   <?= $fType==='dealer'  ?'selected':'' ?>><?= $t[$lang]['dealer'] ?></option>
            </select>
        </div>
        <button type="submit" class="btn-apply">🔍 <?= $t[$lang]['filter_btn'] ?></button>
        <a href="?lang=<?= $lang ?>" class="btn-reset-f">✕ <?= $t[$lang]['reset'] ?></a>
    </div>
</form>

<div class="grid-2">
    <div class="panel">
        <div class="panel-title">📈 <?= $t[$lang]['monthly'] ?></div>
        <div class="chart-box"><canvas id="monthlyChart"></canvas></div>
    </div>
    <div class="panel">
        <div class="panel-title">🏅 <?= $t[$lang]['leaderboard'] ?></div>
        <div class="chart-box"><canvas id="sellerChart"></canvas></div>
    </div>
</div>

<div class="grid-3">
    <div class="panel" style="display:flex;flex-direction:column;">
        <div class="panel-title">🍩 <?= $t[$lang]['split'] ?></div>
        <div class="donut-wrap"><canvas id="splitChart"></canvas></div>
        <div class="split-legend">
            <div class="split-item">
                <div class="split-dot-label"><div class="split-dot" style="background:#22c55e;"></div><span><?= $t[$lang]['customer_vs'] ?></span></div>
                <div class="split-val" style="color:var(--green);"><?= $custCount ?></div>
            </div>
            <div class="split-item">
                <div class="split-dot-label"><div class="split-dot" style="background:#2563eb;"></div><span><?= $t[$lang]['dealer_vs'] ?></span></div>
                <div class="split-val" style="color:#60a5fa;"><?= $dealCount ?></div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-title">📍 <?= $t[$lang]['by_branch'] ?></div>
        <?php if (empty($byBranch)): ?>
            <div class="empty"><?= $t[$lang]['no_data'] ?></div>
        <?php else: ?>
        <div class="chart-branch-wrap" id="branchWrap"><canvas id="branchChart"></canvas></div>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="panel-title">⭐ <?= $t[$lang]['top_models'] ?></div>
        <?php if (empty($topModels)): ?>
            <div class="empty"><?= $t[$lang]['no_data'] ?></div>
        <?php else: $rnk = 1; ?>
        <table>
            <thead><tr><th><?= $t[$lang]['rank'] ?></th><th><?= $t[$lang]['model'] ?></th><th><?= $t[$lang]['count'] ?></th></tr></thead>
            <tbody>
            <?php foreach ($topModels as $m): ?>
            <tr>
                <td><span class="rank-badge rank-<?= $rnk ?>"><?= $rnk++ ?></span></td>
                <td><?= htmlspecialchars($m['brand'] . ' ' . $m['model']) ?></td>
                <td class="total-cell"><?= (int)$m['cnt'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="panel" style="margin-bottom:18px;">
    <div class="panel-title">🏅 <?= $t[$lang]['leaderboard'] ?></div>
    <?php if (empty($leaderboard)): ?>
        <div class="empty"><?= $t[$lang]['no_data'] ?></div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table>
        <thead><tr>
            <th><?= $t[$lang]['rank'] ?></th>
            <th><?= $t[$lang]['salesman'] ?></th>
            <th><?= $t[$lang]['customer'] ?></th>
            <th><?= $t[$lang]['dealer'] ?></th>
            <th><?= $t[$lang]['sales'] ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($leaderboard as $i => $row): ?>
        <tr>
            <td><span class="rank-badge rank-<?= $i+1 ?>"><?= $i+1 ?></span></td>
            <td class="seller-name"><?= htmlspecialchars($row['seller']) ?></td>
            <td><span class="pill pill-c"><?= (int)$row['customer_sales'] ?></span></td>
            <td><span class="pill pill-d"><?= (int)$row['dealer_sales'] ?></span></td>
            <td class="total-cell"><?= (int)$row['total'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<div class="panel" style="margin-bottom:18px;">
    <div class="panel-title">📍 <?= $t[$lang]['by_branch'] ?></div>
    <?php if (empty($byBranch)): ?>
        <div class="empty"><?= $t[$lang]['no_data'] ?></div>
    <?php else: $maxB = max(array_column($byBranch, 'cnt')); ?>
    <div style="overflow-x:auto;">
    <table>
        <thead><tr><th><?= $t[$lang]['branch'] ?></th><th><?= $t[$lang]['count'] ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($byBranch as $b): $pct = $maxB ? round($b['cnt'] / $maxB * 100) : 0; ?>
        <tr>
            <td><?= htmlspecialchars($b['sold_branch']) ?></td>
            <td class="total-cell"><?= (int)$b['cnt'] ?> <?= $t[$lang]['cars'] ?></td>
            <td style="width:40%;min-width:100px;"><div class="branch-bar-wrap"><div class="branch-bar-fill" style="width:<?= $pct ?>%"></div></div></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- ══════ SOLD CARS CARDS (NEW — respects filters) ══════ -->
<div class="panel">
    <div class="panel-title" style="justify-content:flex-start;">
        🧾 <?= $t[$lang]['sold_list'] ?>
        <span class="sold-count-pill"><?= $t[$lang]['showing'] ?> <?= count($soldCards) ?> <?= $t[$lang]['results'] ?></span>
    </div>
    <?php if (empty($soldCards)): ?>
        <div class="empty"><?= $t[$lang]['no_data'] ?></div>
    <?php else: ?>
    <div class="sold-grid">
        <?php foreach ($soldCards as $sc):
            $isCust   = ($sc['sale_type'] === 'customer');
            $seller   = ($sc['salesman'] ?? '') !== '' ? $sc['salesman'] : ($sc['sold_by'] ?? '—');
            $carName  = trim(($sc['brand'] ?? '') . ' ' . ($sc['model'] ?? ''));
            if ($carName === '') $carName = '🚗 #' . ($sc['car_id'] ?? '?');
            $clr = $lang === 'ar' ? ($sc['color_ar'] ?: $sc['color']) : ($sc['color_en'] ?: $sc['color']);
            $noteTxt = trim((string)($sc['notes'] ?? ''));
        ?>
        <div class="sold-card <?= $isCust ? 't-customer' : 't-dealer' ?>">
            <div class="sold-card-head">
                <div>
                    <div class="sold-car-name">🚗 <?= htmlspecialchars($carName) ?></div>
                    <?php if (!empty($sc['car_year'])): ?>
                    <div class="sold-car-year">📅 <?= htmlspecialchars($sc['car_year']) ?><?= !empty($sc['trim_name']) ? ' • ' . htmlspecialchars($sc['trim_name']) : '' ?></div>
                    <?php endif; ?>
                </div>
                <span class="sold-type-badge <?= $isCust ? 'customer' : 'dealer' ?>">
                    <?= $isCust ? $t[$lang]['customer'] : $t[$lang]['dealer'] ?>
                </span>
            </div>

            <div class="sold-rows">
                <?php if (!empty($clr)): ?>
                <div class="sold-row"><span class="sr-label">🎨 <?= $t[$lang]['color'] ?></span><span class="sr-val"><?= htmlspecialchars($clr) ?></span></div>
                <?php endif; ?>
                <?php if (!empty($sc['chassis'])): ?>
                <div class="sold-row"><span class="sr-label">🔢 <?= $t[$lang]['chassis'] ?></span><span class="sr-val chassis"><?= htmlspecialchars($sc['chassis']) ?></span></div>
                <?php endif; ?>
                <div class="sold-row"><span class="sr-label">📍 <?= $t[$lang]['sold_branch_lbl'] ?></span><span class="sr-val"><?= htmlspecialchars($sc['sold_branch'] ?? '—') ?></span></div>
                <?php if ($isCust && !empty($sc['customer_name'])): ?>
                <div class="sold-row"><span class="sr-label">🧑 <?= $t[$lang]['cust_name'] ?></span><span class="sr-val"><?= htmlspecialchars($sc['customer_name']) ?></span></div>
                <?php endif; ?>
                <?php if ($isCust && !empty($sc['customer_phone'])): ?>
                <div class="sold-row"><span class="sr-label">📞 <?= $t[$lang]['cust_phone'] ?></span><span class="sr-val"><?= htmlspecialchars($sc['customer_phone']) ?></span></div>
                <?php endif; ?>
                <?php if (!$isCust && !empty($sc['dealer_name'])): ?>
                <div class="sold-row"><span class="sr-label">🏢 <?= $t[$lang]['dealer_name_lbl'] ?></span><span class="sr-val"><?= htmlspecialchars($sc['dealer_name']) ?></span></div>
                <?php endif; ?>
            </div>

            <?php if ($noteTxt !== ''): ?>
            <div class="sold-note">📝 <?= htmlspecialchars($noteTxt) ?></div>
            <?php endif; ?>

            <div class="sold-card-foot">
                <span class="sold-seller-tag">👤 <?= htmlspecialchars($seller) ?></span>
                <span class="sold-date">🕐 <?= !empty($sc['sold_at']) ? date('d M Y', strtotime($sc['sold_at'])) : '—' ?></span>
            </div>

            <?php if ($canEditSale || $canRevertSale): ?>
            <div class="sold-actions">
                <?php if ($canEditSale): ?>
                <button type="button" class="sa-btn sa-edit"
                    onclick='openEditSale(<?= json_encode([
                        "id"    => (int)$sc["id"],
                        "car"   => $carName,
                        "type"  => $sc["sale_type"],
                        "cname" => (string)($sc["customer_name"]  ?? ""),
                        "cphone"=> (string)($sc["customer_phone"] ?? ""),
                        "dname" => (string)($sc["dealer_name"]    ?? ""),
                    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                    ✏️ <?= $t[$lang]['edit_buyer'] ?>
                </button>
                <?php endif; ?>
                <?php if ($canRevertSale): ?>
                <form method="POST" action="sale_action.php?lang=<?= $lang ?>" class="sa-form"
                      onsubmit="return confirm(<?= htmlspecialchars(json_encode($t[$lang]['revert_confirm'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>);">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="revert">
                    <input type="hidden" name="sold_id" value="<?= (int)$sc['id'] ?>">
                    <input type="hidden" name="lang" value="<?= $lang ?>">
                    <button type="submit" class="sa-btn sa-revert">↩️ <?= $t[$lang]['revert_stock'] ?></button>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

</div><!-- /.wrap -->
</div><!-- /#mainContent -->

<?php if ($canEditSale): ?>
<!-- Edit-buyer modal -->
<div class="se-overlay" id="seOverlay" onclick="if(event.target===this)closeEditSale()">
    <div class="se-box">
        <div class="se-head">
            <h3>✏️ <?= $t[$lang]['edit_sale_title'] ?></h3>
            <button type="button" class="se-x" onclick="closeEditSale()">✕</button>
        </div>
        <p class="se-hint"><?= $t[$lang]['edit_sale_hint'] ?></p>
        <div class="se-car" id="seCar">—</div>
        <form method="POST" action="sale_action.php?lang=<?= $lang ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="lang" value="<?= $lang ?>">
            <input type="hidden" name="sold_id" id="seSoldId" value="">

            <div class="se-field" id="seCustNameWrap">
                <label>🧑 <?= $t[$lang]['cust_name'] ?></label>
                <input type="text" name="customer_name" id="seCustName" autocomplete="off">
            </div>
            <div class="se-field" id="seCustPhoneWrap">
                <label>📞 <?= $t[$lang]['cust_phone'] ?></label>
                <input type="text" name="customer_phone" id="seCustPhone" autocomplete="off">
            </div>
            <div class="se-field" id="seDealerWrap">
                <label>🏢 <?= $t[$lang]['dealer_name_lbl'] ?></label>
                <input type="text" name="dealer_name" id="seDealer" autocomplete="off">
            </div>

            <div class="se-actions">
                <button type="button" class="se-btn se-cancel" onclick="closeEditSale()"><?= $t[$lang]['cancel'] ?></button>
                <button type="submit" class="se-btn se-save"><?= $t[$lang]['save'] ?></button>
            </div>
        </form>
    </div>
</div>
<script>
function openEditSale(d) {
    document.getElementById('seSoldId').value    = d.id;
    document.getElementById('seCar').textContent = '🚗 ' + d.car;
    document.getElementById('seCustName').value  = d.cname  || '';
    document.getElementById('seCustPhone').value = d.cphone || '';
    document.getElementById('seDealer').value    = d.dname  || '';
    // Show the fields relevant to the sale type, but keep all editable.
    var isDealer = d.type === 'dealer';
    document.getElementById('seCustNameWrap').style.display  = isDealer ? 'none' : '';
    document.getElementById('seCustPhoneWrap').style.display = isDealer ? 'none' : '';
    document.getElementById('seDealerWrap').style.display    = isDealer ? '' : 'none';
    document.getElementById('seOverlay').classList.add('open');
}
function closeEditSale() {
    document.getElementById('seOverlay').classList.remove('open');
}
</script>
<?php endif; ?>

<script>
/* ── Vault Particle Canvas ── */
(function () {
    const canvas = document.getElementById('vaultParticles');
    const ctx    = canvas.getContext('2d');
    let W, H, particles = [];
    function resize() { W = canvas.width = window.innerWidth; H = canvas.height = window.innerHeight; }
    resize();
    window.addEventListener('resize', resize);
    for (let i = 0; i < 60; i++) {
        particles.push({ x: Math.random()*window.innerWidth, y: Math.random()*window.innerHeight, r: Math.random()*1.8+.4, vx:(Math.random()-.5)*.4, vy:(Math.random()-.5)*.4, alpha:Math.random()*.5+.1 });
    }
    function draw() {
        ctx.clearRect(0, 0, W, H);
        particles.forEach(p => {
            p.x += p.vx; p.y += p.vy;
            if (p.x < 0) p.x = W; if (p.x > W) p.x = 0;
            if (p.y < 0) p.y = H; if (p.y > H) p.y = 0;
            ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, Math.PI*2);
            ctx.fillStyle = `rgba(34,197,94,${p.alpha})`; ctx.fill();
        });
        requestAnimationFrame(draw);
    }
    draw();
})();

/* ── Vault Phase Transition ── */
setTimeout(function () {
    const loading = document.getElementById('vaultLoading');
    const modal   = document.getElementById('vaultModal');
    loading.style.transition = 'opacity .4s';
    loading.style.opacity    = '0';
    setTimeout(function () { loading.style.display = 'none'; modal.style.display = 'flex'; }, 420);
}, 2200);

function enterVault() {
    const overlay = document.getElementById('vaultOverlay');
    overlay.style.transition = 'opacity .6s ease';
    overlay.style.opacity    = '0';
    setTimeout(function () {
        overlay.style.display = 'none';
        document.getElementById('mainContent').classList.add('visible');
    }, 620);
}

/* ── Chart.js Setup ── */
const GREEN='#22c55e', PURPLE='#9333ea', BLUE='#2563eb', AMBER='#f59e0b';
const GRID='rgba(255,255,255,.06)', TICK='#94a3b8';

new Chart(document.getElementById('monthlyChart'), {
    type: 'line',
    data: { labels: <?= $chartMonths ?>, datasets: [{ data: <?= $chartMonthVal ?>, borderColor: GREEN, backgroundColor:'rgba(34,197,94,.10)', fill:true, tension:.38, borderWidth:3, pointBackgroundColor:GREEN, pointRadius:5, pointHoverRadius:7 }] },
    options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{ y:{beginAtZero:true,ticks:{color:TICK,precision:0},grid:{color:GRID}}, x:{ticks:{color:TICK},grid:{display:false}} } }
});

new Chart(document.getElementById('sellerChart'), {
    type: 'bar',
    data: { labels: <?= $chartSellers ?>, datasets: [
        { label:'<?= addslashes($t[$lang]['customer_vs']) ?>', data:<?= $chartCustSeller ?>, backgroundColor:'rgba(34,197,94,.65)', borderColor:GREEN, borderWidth:1, borderRadius:{topLeft:6,topRight:6,bottomLeft:0,bottomRight:0} },
        { label:'<?= addslashes($t[$lang]['dealer_vs']) ?>', data:<?= $chartDealSeller ?>, backgroundColor:'rgba(37,99,235,.65)', borderColor:BLUE, borderWidth:1, borderRadius:0 }
    ]},
    options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{display:true,labels:{color:TICK,boxWidth:12,font:{size:12}}}}, scales:{ y:{beginAtZero:true,stacked:true,ticks:{color:TICK,precision:0},grid:{color:GRID}}, x:{stacked:true,ticks:{color:TICK},grid:{display:false}} } }
});

<?php if (!empty($byBranch)): ?>
(function () {
    const branchCount = <?= $branchCount ?>;
    const minBarHeight = 44;
    const canvasH = Math.max(220, branchCount * minBarHeight + 60);
    const wrap = document.getElementById('branchWrap');
    const canvas = document.getElementById('branchChart');
    wrap.style.height = canvasH + 'px';
    canvas.style.height = canvasH + 'px';
    new Chart(canvas, {
        type: 'bar',
        data: { labels: <?= $chartBranchLabels ?>, datasets: [{ data: <?= $chartBranchVals ?>, backgroundColor:'rgba(37,99,235,.60)', borderColor:BLUE, borderWidth:1, borderRadius:6, hoverBackgroundColor:'rgba(37,99,235,.85)' }] },
        options: { indexAxis:'y', responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{ x:{beginAtZero:true,ticks:{color:TICK,precision:0,stepSize:1},grid:{color:GRID}}, y:{ticks:{color:TICK,font:{size:12}},grid:{display:false}} } }
    });
})();
<?php endif; ?>

new Chart(document.getElementById('splitChart'), {
    type: 'doughnut',
    data: { labels: ['<?= addslashes($t[$lang]['customer_vs']) ?>', '<?= addslashes($t[$lang]['dealer_vs']) ?>'], datasets: [{ data:[<?= $custCount ?>, <?= $dealCount ?>], backgroundColor:['rgba(34,197,94,.75)','rgba(37,99,235,.75)'], borderColor:['#22c55e','#2563eb'], borderWidth:2, hoverOffset:8 }] },
    options: { responsive:true, maintainAspectRatio:false, cutout:'65%', plugins:{legend:{display:false}, tooltip:{callbacks:{label:ctx=>' '+ctx.parsed}}} }
});
</script>
</body>
</html>
