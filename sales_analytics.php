<?php

require 'auth.php';
require 'config.php';
require 'sold_helpers.php';

/* ── Permission-gated (default: admin only) ── */
perm_require('page.sales_analytics');

// Exclude reverted sales from all analytics.
$soldColOk    = ensure_sold_revert_columns($pdo);
$activeSoldNA = sold_active_sql($soldColOk, '');   // no-alias fragment
$activeSoldS  = sold_active_sql($soldColOk, 's');  // for the "s" alias query

$lang = $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar', 'en'])) {
    $lang = 'ar';
}

$t = [
    'ar' => [
        'title'        => 'تحليلات المبيعات',
        'subtitle'     => 'أداء المبيعات والبائعين',
        'dashboard'    => 'الرئيسية',
        'total_sold'   => 'إجمالي المبيعات',
        'this_month'   => 'مبيعات هذا الشهر',
        'this_year'    => 'مبيعات هذا العام',
        'top_seller'   => 'أفضل بائع',
        'leaderboard'  => 'ترتيب البائعين',
        'monthly'      => 'المبيعات الشهرية',
        'by_branch'    => 'المبيعات حسب الفرع',
        'top_models'   => 'الأكثر مبيعاً',
        'split'        => 'عملاء مقابل تجار',
        'salesman'     => 'البائع',
        'sales'        => 'المبيعات',
        'customer'     => 'عميل',
        'dealer'       => 'تاجر',
        'branch'       => 'الفرع',
        'model'        => 'الموديل',
        'count'        => 'العدد',
        'no_data'      => 'لا توجد بيانات بعد',
        'rank'         => '#',
        'cars'         => 'سيارة',
    ],
    'en' => [
        'title'        => 'Sales Analytics',
        'subtitle'     => 'Sales & salesman performance',
        'dashboard'    => 'Dashboard',
        'total_sold'   => 'Total Sold',
        'this_month'   => 'Sold This Month',
        'this_year'    => 'Sold This Year',
        'top_seller'   => 'Top Salesman',
        'leaderboard'  => 'Salesman Leaderboard',
        'monthly'      => 'Monthly Sales',
        'by_branch'    => 'Sales by Branch',
        'top_models'   => 'Best Sellers',
        'split'        => 'Customer vs Dealer',
        'salesman'     => 'Salesman',
        'sales'        => 'Sales',
        'customer'     => 'Customer',
        'dealer'       => 'Dealer',
        'branch'       => 'Branch',
        'model'        => 'Model',
        'count'        => 'Count',
        'no_data'      => 'No data yet',
        'rank'         => '#',
        'cars'         => 'cars',
    ],
];

/* ════════════════ QUERIES ════════════════ */

// Stat cards
$totalSold = (int)$pdo->query("SELECT COUNT(*) FROM sold_cars WHERE $activeSoldNA")->fetchColumn();
$soldMonth = (int)$pdo->query("SELECT COUNT(*) FROM sold_cars WHERE MONTH(sold_at)=MONTH(CURDATE()) AND YEAR(sold_at)=YEAR(CURDATE()) AND $activeSoldNA")->fetchColumn();
$soldYear  = (int)$pdo->query("SELECT COUNT(*) FROM sold_cars WHERE YEAR(sold_at)=YEAR(CURDATE()) AND $activeSoldNA")->fetchColumn();

// Leaderboard (salesman, fallback to sold_by)
$leaderboard = $pdo->query("
    SELECT COALESCE(NULLIF(salesman,''), sold_by) AS seller,
           SUM(sale_type='customer') AS customer_sales,
           SUM(sale_type='dealer')   AS dealer_sales,
           COUNT(*) AS total
    FROM sold_cars
    WHERE $activeSoldNA
    GROUP BY seller
    ORDER BY total DESC
")->fetchAll(PDO::FETCH_ASSOC);

$topSeller = $leaderboard[0]['seller'] ?? '—';

// By branch
$byBranch = $pdo->query("
    SELECT sold_branch, COUNT(*) AS cnt
    FROM sold_cars WHERE $activeSoldNA GROUP BY sold_branch ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Top models
$topModels = $pdo->query("
    SELECT c.brand, c.model, COUNT(*) AS cnt
    FROM sold_cars s JOIN cars c ON s.car_id = c.id
    WHERE $activeSoldS
    GROUP BY c.brand, c.model ORDER BY cnt DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// Monthly (last 12 months)
$monthlyRaw = $pdo->query("
    SELECT DATE_FORMAT(sold_at,'%Y-%m') AS ym, COUNT(*) AS cnt
    FROM sold_cars
    WHERE sold_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND $activeSoldNA
    GROUP BY ym ORDER BY ym ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Build a continuous 12-month series (fill gaps with 0)
$months = [];
for ($i = 11; $i >= 0; $i--) {
    $months[date('Y-m', strtotime("-$i months"))] = 0;
}
foreach ($monthlyRaw as $r) {
    if (isset($months[$r['ym']])) $months[$r['ym']] = (int)$r['cnt'];
}

// Customer vs dealer split
$splitRaw = $pdo->query("SELECT sale_type, COUNT(*) AS cnt FROM sold_cars WHERE $activeSoldNA GROUP BY sale_type")->fetchAll(PDO::FETCH_KEY_PAIR);
$custCount = (int)($splitRaw['customer'] ?? 0);
$dealCount = (int)($splitRaw['dealer'] ?? 0);

// JSON for charts
$chartMonths   = json_encode(array_map(fn($m) => date('M y', strtotime($m.'-01')), array_keys($months)));
$chartMonthVal = json_encode(array_values($months));
$chartSellers  = json_encode(array_column($leaderboard, 'seller'));
$chartSellerV  = json_encode(array_map('intval', array_column($leaderboard, 'total')));

?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $lang === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#0f172a">
<title><?= $t[$lang]['title'] ?></title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI', Tahoma, sans-serif; }

body {
    background: linear-gradient(135deg, #020617, #0f172a);
    color: white;
    min-height: 100vh;
    padding-bottom: 60px;
}
.container { max-width: 1500px; margin: auto; padding: 20px; }

/* Header */
.header {
    background: rgba(15,23,42,.90);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 30px;
    padding: 25px;
    margin-bottom: 25px;
    backdrop-filter: blur(20px);
    box-shadow: 0 20px 50px rgba(0,0,0,.35);
}
.header-top { display:flex; justify-content:space-between; align-items:center; gap:20px; flex-wrap:wrap; }
.page-title { font-size: 34px; font-weight: 800; color: #22c55e; }
.page-subtitle { margin-top: 8px; font-size: 14px; color: #94a3b8; }
.header-actions { display:flex; gap:10px; flex-wrap:wrap; }
.action-btn {
    text-decoration:none; padding:12px 18px; border-radius:14px; font-weight:700;
    color:white; transition:.3s; display:flex; align-items:center; gap:6px;
}
.action-btn:hover { transform: translateY(-2px); }
.dashboard-btn { background:#2563eb; }
.lang-switch { display:flex; gap:10px; margin-top:20px; }
.lang-btn { text-decoration:none; padding:10px 16px; border-radius:12px; background:#111827; color:white; font-weight:700; }
.lang-active { background:#9333ea !important; }

/* Stat cards */
.stats { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:22px; }
.stat-card {
    background: rgba(15,23,42,.90);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 24px;
    padding: 24px;
    position: relative;
    overflow: hidden;
}
.stat-card::after { content:''; position:absolute; bottom:0; left:0; right:0; height:3px; }
.stat-card.c1::after { background:linear-gradient(90deg,#22c55e,#86efac); }
.stat-card.c2::after { background:linear-gradient(90deg,#2563eb,#60a5fa); }
.stat-card.c3::after { background:linear-gradient(90deg,#9333ea,#c084fc); }
.stat-card.c4::after { background:linear-gradient(90deg,#f59e0b,#fcd34d); }
.stat-label { font-size:13px; color:#94a3b8; font-weight:700; display:flex; align-items:center; gap:6px; }
.stat-number { font-size:42px; font-weight:800; margin-top:8px; line-height:1; }
.stat-card.c1 .stat-number { color:#22c55e; }
.stat-card.c2 .stat-number { color:#60a5fa; }
.stat-card.c3 .stat-number { color:#c084fc; }
.stat-card.c4 .stat-number { color:#fcd34d; font-size:26px; margin-top:14px; }

/* Panels */
.grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px; }
.panel {
    background: rgba(15,23,42,.90);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 24px;
    padding: 24px;
}
.panel-title { font-size:18px; font-weight:800; margin-bottom:18px; display:flex; align-items:center; gap:8px; }
.chart-box { position:relative; height:300px; }

/* Tables */
table { width:100%; border-collapse:collapse; }
th { text-align:start; font-size:12px; text-transform:uppercase; letter-spacing:.05em; color:#64748b; font-weight:700; padding:10px 12px; }
td { padding:12px; border-top:1px solid rgba(255,255,255,.05); font-size:14px; }
tbody tr:hover { background: rgba(34,197,94,.04); }
.rank-badge {
    display:inline-grid; place-items:center; width:28px; height:28px; border-radius:9px;
    font-weight:800; font-size:13px; background:rgba(147,51,234,.15); color:#c084fc;
}
.rank-1 { background:rgba(245,158,11,.2); color:#fcd34d; }
.rank-2 { background:rgba(148,163,184,.2); color:#cbd5e1; }
.rank-3 { background:rgba(217,119,6,.2); color:#fbbf24; }
.seller-name { font-weight:700; }
.pill { padding:3px 10px; border-radius:50px; font-size:12px; font-weight:700; }
.pill-c { background:rgba(34,197,94,.15); color:#22c55e; }
.pill-d { background:rgba(37,99,235,.15); color:#60a5fa; }
.total-cell { font-weight:800; color:#22c55e; }

.empty { text-align:center; padding:40px; color:#64748b; }

@media (max-width:1000px) { .stats { grid-template-columns:1fr 1fr; } .grid-2 { grid-template-columns:1fr; } }
@media (max-width:600px) { .stats { grid-template-columns:1fr; } .page-title { font-size:26px; } }
</style>
</head>
<body>
<div class="container">

    <div class="header">
        <div class="header-top">
            <div>
                <div class="page-title">📊 <?= $t[$lang]['title'] ?></div>
                <div class="page-subtitle"><?= $t[$lang]['subtitle'] ?></div>
            </div>
            <div class="header-actions">
                <a href="dashboard.php?lang=<?= $lang ?>" class="action-btn dashboard-btn">🏠 <?= $t[$lang]['dashboard'] ?></a>
            </div>
        </div>
        <div class="lang-switch">
            <a href="?lang=ar" class="lang-btn <?= $lang==='ar'?'lang-active':'' ?>">🇪🇬 العربية</a>
            <a href="?lang=en" class="lang-btn <?= $lang==='en'?'lang-active':'' ?>">🇺🇸 English</a>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="stats">
        <div class="stat-card c1">
            <div class="stat-label">🚗 <?= $t[$lang]['total_sold'] ?></div>
            <div class="stat-number"><?= $totalSold ?></div>
        </div>
        <div class="stat-card c2">
            <div class="stat-label">📅 <?= $t[$lang]['this_month'] ?></div>
            <div class="stat-number"><?= $soldMonth ?></div>
        </div>
        <div class="stat-card c3">
            <div class="stat-label">🗓️ <?= $t[$lang]['this_year'] ?></div>
            <div class="stat-number"><?= $soldYear ?></div>
        </div>
        <div class="stat-card c4">
            <div class="stat-label">🏆 <?= $t[$lang]['top_seller'] ?></div>
            <div class="stat-number"><?= htmlspecialchars($topSeller) ?></div>
        </div>
    </div>

    <!-- Charts row -->
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

    <!-- Leaderboard table -->
    <div class="panel" style="margin-bottom:20px;">
        <div class="panel-title">🏅 <?= $t[$lang]['leaderboard'] ?></div>
        <?php if (empty($leaderboard)): ?>
            <div class="empty"><?= $t[$lang]['no_data'] ?></div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th><?= $t[$lang]['rank'] ?></th>
                    <th><?= $t[$lang]['salesman'] ?></th>
                    <th><?= $t[$lang]['customer'] ?></th>
                    <th><?= $t[$lang]['dealer'] ?></th>
                    <th><?= $t[$lang]['sales'] ?></th>
                </tr>
            </thead>
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
        <?php endif; ?>
    </div>

    <!-- Branch + Models -->
    <div class="grid-2">
        <div class="panel">
            <div class="panel-title">📍 <?= $t[$lang]['by_branch'] ?></div>
            <?php if (empty($byBranch)): ?>
                <div class="empty"><?= $t[$lang]['no_data'] ?></div>
            <?php else: ?>
            <table>
                <thead><tr><th><?= $t[$lang]['branch'] ?></th><th><?= $t[$lang]['count'] ?></th></tr></thead>
                <tbody>
                    <?php foreach ($byBranch as $b): ?>
                    <tr>
                        <td><?= htmlspecialchars($b['sold_branch']) ?></td>
                        <td class="total-cell"><?= (int)$b['cnt'] ?> <?= $t[$lang]['cars'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div class="panel">
            <div class="panel-title">⭐ <?= $t[$lang]['top_models'] ?></div>
            <?php if (empty($topModels)): ?>
                <div class="empty"><?= $t[$lang]['no_data'] ?></div>
            <?php else: ?>
            <table>
                <thead><tr><th><?= $t[$lang]['model'] ?></th><th><?= $t[$lang]['count'] ?></th></tr></thead>
                <tbody>
                    <?php foreach ($topModels as $m): ?>
                    <tr>
                        <td><?= htmlspecialchars($m['brand'].' '.$m['model']) ?></td>
                        <td class="total-cell"><?= (int)$m['cnt'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
const green='#22c55e', purple='#9333ea', grid='rgba(255,255,255,.06)', tick='#94a3b8';

// Monthly trend line
new Chart(document.getElementById('monthlyChart'), {
    type: 'line',
    data: {
        labels: <?= $chartMonths ?>,
        datasets: [{
            data: <?= $chartMonthVal ?>,
            borderColor: green,
            backgroundColor: 'rgba(34,197,94,.12)',
            fill: true, tension: .35, borderWidth: 3,
            pointBackgroundColor: green, pointRadius: 4,
        }]
    },
    options: {
        responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{display:false} },
        scales:{
            y:{ beginAtZero:true, ticks:{ color:tick, precision:0 }, grid:{ color:grid } },
            x:{ ticks:{ color:tick }, grid:{ display:false } }
        }
    }
});

// Salesman bar
new Chart(document.getElementById('sellerChart'), {
    type: 'bar',
    data: {
        labels: <?= $chartSellers ?>,
        datasets: [{
            data: <?= $chartSellerV ?>,
            backgroundColor: 'rgba(147,51,234,.65)',
            borderColor: purple, borderWidth: 1, borderRadius: 8,
        }]
    },
    options: {
        responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{display:false} },
        scales:{
            y:{ beginAtZero:true, ticks:{ color:tick, precision:0 }, grid:{ color:grid } },
            x:{ ticks:{ color:tick }, grid:{ display:false } }
        }
    }
});
</script>
</body>
</html>
