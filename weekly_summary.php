<?php

/**
 * ═══════════════════════════════════════════════════════
 *  First 1 Car — Weekly Summary Email
 *  Run via cron every Saturday at 9:00 AM
 *  Cron: 0 9 * * 6   public_html/weekly_summary.php
 * ═══════════════════════════════════════════════════════
 */

define('TO_EMAIL',   'm.shaker@first1car.net');
define('TO_NAME',    'First 1 Car Admin');
define('FROM_EMAIL', 'noreply@first1car.net');
define('FROM_NAME',  'First 1 Car System');
define('LOW_STOCK',  10);

$root = dirname(__FILE__);
require $root . '/config.php';

// ── Date ranges ───────────────────────────────────────────────────────────────
// This week: last 7 days (Sun–Sat or Mon–Sun depending on locale — using last 7 days)
$weekStart     = date('Y-m-d', strtotime('last Sunday'));
$weekEnd       = date('Y-m-d');   // today (Saturday)
$prevWeekStart = date('Y-m-d', strtotime('last Sunday -7 days'));
$prevWeekEnd   = date('Y-m-d', strtotime('last Saturday'));
$monthStart    = date('Y-m-01');
$monthEnd      = date('Y-m-d');

// ── Queries ───────────────────────────────────────────────────────────────────

// Available stock
$totalAvail = (int)$pdo->query("SELECT COUNT(*) FROM cars WHERE status='available'")->fetchColumn();
$lowStock   = $totalAvail < LOW_STOCK;

// This week sales
$thisWeekStmt = $pdo->prepare("
    SELECT COUNT(*) FROM sold_cars
    WHERE DATE(sold_at) BETWEEN ? AND ?
");
$thisWeekStmt->execute([$weekStart, $weekEnd]);
$thisWeekTotal = (int)$thisWeekStmt->fetchColumn();

// Last week sales
$lastWeekStmt = $pdo->prepare("
    SELECT COUNT(*) FROM sold_cars
    WHERE DATE(sold_at) BETWEEN ? AND ?
");
$lastWeekStmt->execute([$prevWeekStart, $prevWeekEnd]);
$lastWeekTotal = (int)$lastWeekStmt->fetchColumn();

$weekDiff = $thisWeekTotal - $lastWeekTotal;
$weekPct  = $lastWeekTotal > 0 ? round(($weekDiff / $lastWeekTotal) * 100) : ($thisWeekTotal > 0 ? 100 : 0);

// Day-by-day sales this week (last 7 days)
$dayLabels = [];
$dayValues = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $dayLabels[] = date('D d/m', strtotime($day));
    $s = $pdo->prepare("SELECT COUNT(*) FROM sold_cars WHERE DATE(sold_at)=?");
    $s->execute([$day]);
    $dayValues[] = (int)$s->fetchColumn();
}

// This week salesman performance
$thisWeekSellers = $pdo->prepare("
    SELECT COALESCE(NULLIF(s.salesman,''), s.sold_by) AS seller,
           SUM(s.sale_type='customer') AS cust,
           SUM(s.sale_type='dealer')   AS deal,
           COUNT(*) AS total
    FROM sold_cars s
    LEFT JOIN users u ON u.username = COALESCE(NULLIF(s.salesman,''), s.sold_by)
    WHERE DATE(s.sold_at) BETWEEN ? AND ?
      AND (u.role IS NULL OR u.role <> 'admin')
    GROUP BY seller ORDER BY total DESC
");
$thisWeekSellers->execute([$weekStart, $weekEnd]);
$thisWeekSellerList = $thisWeekSellers->fetchAll(PDO::FETCH_ASSOC);

// Last week salesman performance (for comparison)
$lastWeekSellers = $pdo->prepare("
    SELECT COALESCE(NULLIF(s.salesman,''), s.sold_by) AS seller,
           COUNT(*) AS total
    FROM sold_cars s
    LEFT JOIN users u ON u.username = COALESCE(NULLIF(s.salesman,''), s.sold_by)
    WHERE DATE(s.sold_at) BETWEEN ? AND ?
      AND (u.role IS NULL OR u.role <> 'admin')
    GROUP BY seller
");
$lastWeekSellers->execute([$prevWeekStart, $prevWeekEnd]);
$lastWeekSellerMap = [];
foreach ($lastWeekSellers->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $lastWeekSellerMap[$r['seller']] = (int)$r['total'];
}

// Top models this week
$topModels = $pdo->prepare("
    SELECT c.brand, c.model, COUNT(*) AS cnt
    FROM sold_cars s JOIN cars c ON s.car_id = c.id
    WHERE DATE(s.sold_at) BETWEEN ? AND ?
    GROUP BY c.brand, c.model ORDER BY cnt DESC LIMIT 5
");
$topModels->execute([$weekStart, $weekEnd]);
$topModelsList = $topModels->fetchAll(PDO::FETCH_ASSOC);

// Branch performance this week
$byBranch = $pdo->prepare("
    SELECT sold_branch, COUNT(*) AS cnt
    FROM sold_cars
    WHERE DATE(sold_at) BETWEEN ? AND ?
    GROUP BY sold_branch ORDER BY cnt DESC
");
$byBranch->execute([$weekStart, $weekEnd]);
$byBranchList = $byBranch->fetchAll(PDO::FETCH_ASSOC);

// Month-to-date
$monthTotal = (int)$pdo->query("
    SELECT COUNT(*) FROM sold_cars
    WHERE MONTH(sold_at)=MONTH(CURDATE()) AND YEAR(sold_at)=YEAR(CURDATE())
")->fetchColumn();

$monthCustomer = (int)$pdo->query("
    SELECT COUNT(*) FROM sold_cars
    WHERE MONTH(sold_at)=MONTH(CURDATE()) AND YEAR(sold_at)=YEAR(CURDATE())
      AND sale_type='customer'
")->fetchColumn();

$monthDealer = $monthTotal - $monthCustomer;

$lastMonthTotal = (int)$pdo->query("
    SELECT COUNT(*) FROM sold_cars
    WHERE MONTH(sold_at)=MONTH(DATE_SUB(CURDATE(),INTERVAL 1 MONTH))
      AND YEAR(sold_at)=YEAR(DATE_SUB(CURDATE(),INTERVAL 1 MONTH))
")->fetchColumn();

$monthDiff = $monthTotal - $lastMonthTotal;
$monthPct  = $lastMonthTotal > 0 ? round(($monthDiff / $lastMonthTotal) * 100) : ($monthTotal > 0 ? 100 : 0);

// Available by brand
$availByBrand = $pdo->query("
    SELECT brand, COUNT(*) AS cnt FROM cars
    WHERE status='available' GROUP BY brand ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── SVG Chart generators ──────────────────────────────────────────────────────

/**
 * Generates an inline SVG bar chart for day-by-day sales
 */
function svgDayChart($labels, $values, $width = 560, $height = 160) {
    $count   = count($values);
    $maxVal  = max(array_merge($values, [1]));
    $padL    = 32; $padR = 12; $padT = 16; $padB = 40;
    $chartW  = $width - $padL - $padR;
    $chartH  = $height - $padT - $padB;
    $barW    = floor($chartW / $count * 0.6);
    $gap     = $chartW / $count;

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height.'" style="font-family:Segoe UI,sans-serif;">';

    // Background
    $svg .= '<rect width="'.$width.'" height="'.$height.'" fill="#111827" rx="12"/>';

    // Grid lines (3)
    for ($i = 1; $i <= 3; $i++) {
        $y = $padT + $chartH - ($chartH * $i / 3);
        $gridVal = round($maxVal * $i / 3);
        $svg .= '<line x1="'.$padL.'" y1="'.$y.'" x2="'.($width-$padR).'" y2="'.$y.'" stroke="rgba(255,255,255,.07)" stroke-width="1"/>';
        $svg .= '<text x="'.($padL-4).'" y="'.($y+4).'" fill="#64748b" font-size="9" text-anchor="end">'.$gridVal.'</text>';
    }

    // Bars
    for ($i = 0; $i < $count; $i++) {
        $v   = $values[$i];
        $barH = $maxVal > 0 ? ($chartH * $v / $maxVal) : 0;
        $x   = $padL + $gap * $i + ($gap - $barW) / 2;
        $y   = $padT + $chartH - $barH;

        // Bar gradient effect (two rects)
        $color = $v > 0 ? '#22c55e' : '#1e2d40';
        $svg .= '<rect x="'.round($x).'" y="'.round($y).'" width="'.$barW.'" height="'.round($barH).'" fill="'.$color.'" rx="4" opacity="0.85"/>';

        // Value label on top
        if ($v > 0) {
            $svg .= '<text x="'.round($x + $barW/2).'" y="'.round($y - 4).'" fill="#22c55e" font-size="11" font-weight="700" text-anchor="middle">'.$v.'</text>';
        }

        // Day label at bottom
        $parts = explode(' ', $labels[$i]);
        $svg .= '<text x="'.round($x + $barW/2).'" y="'.($height - $padB + 14).'" fill="#94a3b8" font-size="9" text-anchor="middle">'.$parts[0].'</text>';
        $svg .= '<text x="'.round($x + $barW/2).'" y="'.($height - $padB + 25).'" fill="#64748b" font-size="9" text-anchor="middle">'.($parts[1]??'').'</text>';
    }

    $svg .= '</svg>';
    return $svg;
}

/**
 * Generates a horizontal comparison bar for week vs week
 */
function svgWeekCompare($thisW, $lastW, $width = 560) {
    $max = max($thisW, $lastW, 1);
    $barH = 28; $labelW = 90; $valW = 36; $padV = 10;
    $height = ($barH + $padV) * 2 + 40;
    $chartW = $width - $labelW - $valW - 20;

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height.'" style="font-family:Segoe UI,sans-serif;">';
    $svg .= '<rect width="'.$width.'" height="'.$height.'" fill="#111827" rx="12"/>';

    $rows = [
        ['هذا الأسبوع', $thisW, '#22c55e'],
        ['الأسبوع الماضي', $lastW, '#60a5fa'],
    ];

    foreach ($rows as $ri => $row) {
        $y     = 16 + $ri * ($barH + $padV + 8);
        $barPx = $max > 0 ? round($chartW * $row[1] / $max) : 0;

        // Label
        $svg .= '<text x="'.($labelW - 6).'" y="'.($y + $barH/2 + 4).'" fill="#94a3b8" font-size="11" text-anchor="end">'.$row[0].'</text>';

        // Background bar
        $svg .= '<rect x="'.$labelW.'" y="'.$y.'" width="'.$chartW.'" height="'.$barH.'" fill="rgba(255,255,255,.04)" rx="6"/>';

        // Value bar
        if ($barPx > 0) {
            $svg .= '<rect x="'.$labelW.'" y="'.$y.'" width="'.$barPx.'" height="'.$barH.'" fill="'.$row[2].'" rx="6" opacity="0.8"/>';
        }

        // Value text
        $svg .= '<text x="'.($labelW + $chartW + 8).'" y="'.($y + $barH/2 + 4).'" fill="'.$row[2].'" font-size="13" font-weight="800">'.$row[1].'</text>';
    }

    $svg .= '</svg>';
    return $svg;
}

// Generate charts
$daySvg     = svgDayChart($dayLabels, $dayValues);
$compareSvg = svgWeekCompare($thisWeekTotal, $lastWeekTotal);

// Trend helpers
$weekArrow  = $weekDiff > 0 ? '▲' : ($weekDiff < 0 ? '▼' : '●');
$weekColor  = $weekDiff > 0 ? '#22c55e' : ($weekDiff < 0 ? '#ef4444' : '#94a3b8');
$monthArrow = $monthDiff > 0 ? '▲' : ($monthDiff < 0 ? '▼' : '●');
$monthColor = $monthDiff > 0 ? '#22c55e' : ($monthDiff < 0 ? '#ef4444' : '#94a3b8');
$medals     = ['🥇','🥈','🥉','4️⃣','5️⃣'];

$weekLabel  = date('d/m', strtotime($weekStart)) . ' – ' . date('d/m', strtotime($weekEnd));
$monthLabel = date('F Y');

// ── Build HTML email ──────────────────────────────────────────────────────────
ob_start();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>التقرير الأسبوعي — First 1 Car</title>
</head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:'Segoe UI',Tahoma,Arial,sans-serif;direction:rtl;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f0f4f8;padding:24px 0;">
<tr><td align="center">
<table width="620" cellpadding="0" cellspacing="0" border="0" style="max-width:620px;width:100%;">

    <!-- Header -->
    <tr><td style="background:linear-gradient(135deg,#020617,#0f172a);border-radius:20px 20px 0 0;padding:32px 32px 24px;text-align:center;">
        <div style="font-size:38px;margin-bottom:8px;">📊</div>
        <div style="font-size:28px;font-weight:900;color:#22c55e;">First<span style="color:#9333ea;">1</span>Car</div>
        <div style="font-size:16px;font-weight:800;color:#f1f5f9;margin-top:6px;">التقرير الأسبوعي</div>
        <div style="font-size:13px;color:#94a3b8;margin-top:4px;"><?= $weekLabel ?> — <?= $monthLabel ?></div>
        <?php if ($lowStock): ?>
        <div style="margin-top:14px;background:rgba(239,68,68,.2);border:1px solid rgba(239,68,68,.4);border-radius:50px;padding:8px 20px;display:inline-block;color:#fca5a5;font-size:13px;font-weight:700;">
            ⚠️ تحذير: المخزون المتاح منخفض — <?= $totalAvail ?> سيارة فقط
        </div>
        <?php endif; ?>
    </td></tr>

    <!-- Top stat cards -->
    <tr><td style="background:#111827;padding:0 32px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="padding:20px 0;border-bottom:1px solid rgba(255,255,255,.08);">
            <tr>
                <td width="25%" align="center" style="padding:14px 6px;">
                    <div style="font-size:10px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">متاح</div>
                    <div style="font-size:38px;font-weight:900;color:<?= $lowStock?'#ef4444':'#22c55e' ?>;margin-top:4px;line-height:1;"><?= $totalAvail ?></div>
                    <div style="font-size:10px;color:#64748b;margin-top:3px;">سيارة</div>
                </td>
                <td width="25%" align="center" style="padding:14px 6px;border-right:1px solid rgba(255,255,255,.07);border-left:1px solid rgba(255,255,255,.07);">
                    <div style="font-size:10px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">هذا الأسبوع</div>
                    <div style="font-size:38px;font-weight:900;color:#60a5fa;margin-top:4px;line-height:1;"><?= $thisWeekTotal ?></div>
                    <div style="font-size:10px;color:<?= $weekColor ?>;margin-top:3px;"><?= $weekArrow ?> <?= ($weekDiff>0?'+':'').$weekDiff ?> (<?= ($weekPct>0?'+':'').$weekPct ?>%)</div>
                </td>
                <td width="25%" align="center" style="padding:14px 6px;border-left:1px solid rgba(255,255,255,.07);">
                    <div style="font-size:10px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">الشهر حتى الآن</div>
                    <div style="font-size:38px;font-weight:900;color:#c084fc;margin-top:4px;line-height:1;"><?= $monthTotal ?></div>
                    <div style="font-size:10px;color:<?= $monthColor ?>;margin-top:3px;"><?= $monthArrow ?> <?= ($monthDiff>0?'+':'').$monthDiff ?> عن الشهر الماضي</div>
                </td>
                <td width="25%" align="center" style="padding:14px 6px;border-right:1px solid rgba(255,255,255,.07);">
                    <div style="font-size:10px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">الأسبوع الماضي</div>
                    <div style="font-size:38px;font-weight:900;color:#94a3b8;margin-top:4px;line-height:1;"><?= $lastWeekTotal ?></div>
                    <div style="font-size:10px;color:#64748b;margin-top:3px;"><?= date('d/m',strtotime($prevWeekStart)).' – '.date('d/m',strtotime($prevWeekEnd)) ?></div>
                </td>
            </tr>
        </table>
    </td></tr>

    <!-- Week comparison chart -->
    <tr><td style="background:#111827;padding:16px 32px;border-bottom:1px solid rgba(255,255,255,.08);">
        <div style="font-size:14px;font-weight:800;color:#f1f5f9;margin-bottom:12px;">📊 هذا الأسبوع مقابل الأسبوع الماضي</div>
        <?= $compareSvg ?>
    </td></tr>

    <!-- Day by day chart -->
    <tr><td style="background:#111827;padding:16px 32px;border-bottom:1px solid rgba(255,255,255,.08);">
        <div style="font-size:14px;font-weight:800;color:#f1f5f9;margin-bottom:12px;">📈 المبيعات اليومية — آخر 7 أيام</div>
        <?= $daySvg ?>
    </td></tr>

    <!-- Salesman performance this week -->
    <tr><td style="background:#111827;padding:16px 32px;border-bottom:1px solid rgba(255,255,255,.08);">
        <div style="font-size:14px;font-weight:800;color:#f1f5f9;margin-bottom:14px;">🏆 أداء البائعين هذا الأسبوع</div>
        <?php if (empty($thisWeekSellerList)): ?>
        <div style="color:#64748b;font-size:13px;">لا توجد مبيعات هذا الأسبوع</div>
        <?php else: ?>
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr style="border-bottom:1px solid rgba(255,255,255,.06);">
                <td style="font-size:10px;color:#64748b;font-weight:700;padding-bottom:8px;width:28px;"></td>
                <td style="font-size:10px;color:#64748b;font-weight:700;padding-bottom:8px;">البائع</td>
                <td style="font-size:10px;color:#64748b;font-weight:700;padding-bottom:8px;text-align:center;">عملاء</td>
                <td style="font-size:10px;color:#64748b;font-weight:700;padding-bottom:8px;text-align:center;">تجار</td>
                <td style="font-size:10px;color:#64748b;font-weight:700;padding-bottom:8px;text-align:center;">الإجمالي</td>
                <td style="font-size:10px;color:#64748b;font-weight:700;padding-bottom:8px;text-align:center;">مقارنة</td>
            </tr>
            <?php foreach ($thisWeekSellerList as $i => $row):
                $lastW = $lastWeekSellerMap[$row['seller']] ?? 0;
                $d = (int)$row['total'] - $lastW;
                $dColor = $d > 0 ? '#22c55e' : ($d < 0 ? '#ef4444' : '#64748b');
                $dTxt   = $d > 0 ? '+' . $d : ($d < 0 ? (string)$d : '=');
            ?>
            <tr>
                <td style="font-size:16px;padding:8px 4px 8px 0;"><?= $medals[$i] ?? ($i+1).'.' ?></td>
                <td style="color:#e2e8f0;font-size:14px;font-weight:700;padding:8px 4px;"><?= htmlspecialchars($row['seller']) ?></td>
                <td style="text-align:center;padding:8px 4px;">
                    <span style="background:rgba(34,197,94,.12);color:#22c55e;border-radius:50px;padding:2px 8px;font-size:11px;font-weight:700;"><?= (int)$row['cust'] ?></span>
                </td>
                <td style="text-align:center;padding:8px 4px;">
                    <span style="background:rgba(147,51,234,.12);color:#c084fc;border-radius:50px;padding:2px 8px;font-size:11px;font-weight:700;"><?= (int)$row['deal'] ?></span>
                </td>
                <td style="text-align:center;padding:8px 4px;color:#22c55e;font-size:16px;font-weight:900;"><?= (int)$row['total'] ?></td>
                <td style="text-align:center;padding:8px 4px;color:<?= $dColor ?>;font-size:13px;font-weight:800;"><?= $dTxt ?> <span style="font-size:10px;color:#64748b;">الأسبوع الماضي: <?= $lastW ?></span></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </td></tr>

    <!-- Month breakdown -->
    <tr><td style="background:#111827;padding:16px 32px;border-bottom:1px solid rgba(255,255,255,.08);">
        <div style="font-size:14px;font-weight:800;color:#f1f5f9;margin-bottom:14px;">📅 تفاصيل الشهر حتى الآن — <?= $monthLabel ?></div>
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
                <td width="33%" align="center" style="padding:12px 6px;">
                    <div style="background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2);border-radius:14px;padding:14px;">
                        <div style="font-size:10px;color:#64748b;font-weight:700;margin-bottom:6px;">إجمالي الشهر</div>
                        <div style="font-size:32px;font-weight:900;color:#22c55e;line-height:1;"><?= $monthTotal ?></div>
                        <div style="font-size:10px;color:<?= $monthColor ?>;margin-top:4px;"><?= $monthArrow ?> <?= ($monthDiff>0?'+':'').$monthDiff ?> (<?= ($monthPct>0?'+':'').$monthPct ?>%)</div>
                    </div>
                </td>
                <td width="33%" align="center" style="padding:12px 6px;">
                    <div style="background:rgba(34,197,94,.06);border:1px solid rgba(34,197,94,.15);border-radius:14px;padding:14px;">
                        <div style="font-size:10px;color:#64748b;font-weight:700;margin-bottom:6px;">مبيعات عملاء</div>
                        <div style="font-size:32px;font-weight:900;color:#86efac;line-height:1;"><?= $monthCustomer ?></div>
                        <div style="font-size:10px;color:#64748b;margin-top:4px;"><?= $monthTotal > 0 ? round($monthCustomer/$monthTotal*100) : 0 ?>%</div>
                    </div>
                </td>
                <td width="33%" align="center" style="padding:12px 6px;">
                    <div style="background:rgba(147,51,234,.06);border:1px solid rgba(147,51,234,.15);border-radius:14px;padding:14px;">
                        <div style="font-size:10px;color:#64748b;font-weight:700;margin-bottom:6px;">مبيعات تجار</div>
                        <div style="font-size:32px;font-weight:900;color:#c084fc;line-height:1;"><?= $monthDealer ?></div>
                        <div style="font-size:10px;color:#64748b;margin-top:4px;"><?= $monthTotal > 0 ? round($monthDealer/$monthTotal*100) : 0 ?>%</div>
                    </div>
                </td>
            </tr>
        </table>
    </td></tr>

    <!-- Top models -->
    <tr><td style="background:#111827;padding:16px 32px;border-bottom:1px solid rgba(255,255,255,.08);">
        <div style="font-size:14px;font-weight:800;color:#f1f5f9;margin-bottom:14px;">⭐ أكثر الموديلات مبيعاً هذا الأسبوع</div>
        <?php if (empty($topModelsList)): ?>
        <div style="color:#64748b;font-size:13px;">لا توجد مبيعات هذا الأسبوع</div>
        <?php else: $maxM = max(array_column($topModelsList,'cnt')); ?>
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <?php foreach ($topModelsList as $i => $m):
                $pct = $maxM > 0 ? round($m['cnt']/$maxM*100) : 0;
            ?>
            <tr>
                <td style="width:20px;font-size:14px;padding:6px 4px 6px 0;color:#fcd34d;"><?= $i+1 ?>.</td>
                <td style="color:#e2e8f0;font-size:13px;font-weight:700;padding:6px 4px;width:180px;"><?= htmlspecialchars($m['brand'].' '.$m['model']) ?></td>
                <td style="padding:6px 4px;">
                    <div style="background:rgba(255,255,255,.05);border-radius:50px;height:8px;overflow:hidden;">
                        <div style="background:linear-gradient(90deg,#22c55e,#9333ea);height:8px;width:<?= $pct ?>%;border-radius:50px;"></div>
                    </div>
                </td>
                <td style="width:30px;text-align:left;color:#22c55e;font-size:13px;font-weight:800;padding:6px 0 6px 8px;" align="left"><?= (int)$m['cnt'] ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </td></tr>

    <!-- Branch performance -->
    <tr><td style="background:#111827;padding:16px 32px;border-bottom:1px solid rgba(255,255,255,.08);">
        <div style="font-size:14px;font-weight:800;color:#f1f5f9;margin-bottom:14px;">📍 أداء الفروع هذا الأسبوع</div>
        <?php if (empty($byBranchList)): ?>
        <div style="color:#64748b;font-size:13px;">لا توجد مبيعات هذا الأسبوع</div>
        <?php else: $maxB = max(array_column($byBranchList,'cnt')); ?>
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <?php foreach ($byBranchList as $b):
                $pct = $maxB > 0 ? round($b['cnt']/$maxB*100) : 0;
            ?>
            <tr>
                <td style="color:#94a3b8;font-size:13px;padding:6px 4px;width:160px;"><?= htmlspecialchars($b['sold_branch']) ?></td>
                <td style="padding:6px 4px;">
                    <div style="background:rgba(255,255,255,.05);border-radius:50px;height:8px;overflow:hidden;">
                        <div style="background:linear-gradient(90deg,#2563eb,#60a5fa);height:8px;width:<?= $pct ?>%;border-radius:50px;"></div>
                    </div>
                </td>
                <td style="width:30px;text-align:left;color:#60a5fa;font-size:13px;font-weight:800;padding:6px 0 6px 8px;" align="left"><?= (int)$b['cnt'] ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </td></tr>

    <!-- Available stock -->
    <tr><td style="background:#111827;padding:16px 32px;">
        <div style="font-size:14px;font-weight:800;color:#f1f5f9;margin-bottom:14px;">📦 المخزون المتاح حسب الماركة</div>
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <?php foreach ($availByBrand as $b): ?>
            <tr>
                <td style="color:#94a3b8;font-size:13px;padding:5px 4px;"><?= htmlspecialchars($b['brand']) ?></td>
                <td style="text-align:left;" align="left">
                    <span style="background:rgba(34,197,94,.12);color:#22c55e;border:1px solid rgba(34,197,94,.2);border-radius:50px;padding:2px 10px;font-size:12px;font-weight:800;"><?= (int)$b['cnt'] ?></span>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($availByBrand)): ?>
            <tr><td colspan="2" style="color:#64748b;font-size:13px;padding:5px 4px;">لا توجد سيارات متاحة</td></tr>
            <?php endif; ?>
        </table>
    </td></tr>

    <!-- Footer -->
    <tr><td style="background:#0a0f1e;border-radius:0 0 20px 20px;padding:20px 32px;text-align:center;">
        <div style="color:#64748b;font-size:12px;line-height:1.8;">
            هذا البريد يُرسل تلقائياً كل يوم سبت الساعة 9:00 صباحاً<br>
            <strong style="color:#94a3b8;">First 1 Car</strong> — نظام إدارة المخزون<br>
            <a href="https://stock.first1car.net/dashboard.php" style="color:#22c55e;text-decoration:none;">فتح لوحة التحكم ←</a>
            &nbsp;|&nbsp;
            <a href="https://stock.first1car.net/sold_inventory.php" style="color:#9333ea;text-decoration:none;">تقرير المبيعات ←</a>
        </div>
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
<?php
$htmlBody = ob_get_clean();

// ── Plain text fallback ───────────────────────────────────────────────────────
$plainText  = "First 1 Car — التقرير الأسبوعي\n";
$plainText .= str_repeat('=', 40) . "\n";
$plainText .= "الأسبوع: $weekLabel — $monthLabel\n\n";
$plainText .= "المخزون المتاح: $totalAvail سيارة\n";
$plainText .= "مبيعات هذا الأسبوع: $thisWeekTotal ($weekArrow " . ($weekDiff>0?'+':'') . "$weekDiff مقارنة بالأسبوع الماضي)\n";
$plainText .= "مبيعات الشهر حتى الآن: $monthTotal\n\n";

if (!empty($thisWeekSellerList)) {
    $plainText .= "ترتيب البائعين:\n";
    foreach ($thisWeekSellerList as $i => $r) {
        $plainText .= ($i+1) . ". " . $r['seller'] . " — " . $r['total'] . " مبيعات\n";
    }
    $plainText .= "\n";
}

if ($lowStock) $plainText .= "⚠️ تحذير: المخزون منخفض ($totalAvail سيارة)\n";

// ── Send email ────────────────────────────────────────────────────────────────
$subject = "📊 التقرير الأسبوعي First 1 Car — " . $weekLabel .
           " | هذا الأسبوع: $thisWeekTotal" .
           " | الشهر: $monthTotal" .
           ($lowStock ? " ⚠️ مخزون منخفض" : '');

$boundary = 'WBOUNDARY_' . md5(uniqid());

$headers  = "From: " . FROM_NAME . " <" . FROM_EMAIL . ">\r\n";
$headers .= "Reply-To: " . FROM_EMAIL . "\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
$headers .= "X-Mailer: First1Car-WeeklySummary/1.0\r\n";

$body  = "--$boundary\r\n";
$body .= "Content-Type: text/plain; charset=UTF-8\r\n";
$body .= "Content-Transfer-Encoding: base64\r\n\r\n";
$body .= chunk_split(base64_encode($plainText)) . "\r\n";
$body .= "--$boundary\r\n";
$body .= "Content-Type: text/html; charset=UTF-8\r\n";
$body .= "Content-Transfer-Encoding: base64\r\n\r\n";
$body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
$body .= "--$boundary--";

$sent = mail(TO_EMAIL, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);

// ── Log ───────────────────────────────────────────────────────────────────────
$logFile = $root . '/weekly_summary.log';
$logLine = date('Y-m-d H:i:s') . " | " . ($sent ? "✅ Sent" : "❌ Failed") .
           " | week=$thisWeekTotal | month=$monthTotal | avail=$totalAvail\n";

if (file_exists($logFile)) {
    $lines = file($logFile);
    if (count($lines) > 60) {
        file_put_contents($logFile, implode('', array_slice($lines, -50)));
    }
}
file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

// ── Browser preview ───────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    echo '<div style="font-family:sans-serif;padding:20px;background:#0f172a;color:white;">';
    echo '<h2 style="color:' . ($sent ? '#22c55e' : '#ef4444') . '">';
    echo ($sent ? '✅ Email sent successfully' : '❌ Email failed — check mail() config');
    echo '</h2>';
    echo '<p style="color:#94a3b8;">To: ' . TO_EMAIL . ' | Week: ' . $thisWeekTotal . ' | Month: ' . $monthTotal . ' | Available: ' . $totalAvail . '</p>';
    echo '<hr style="border-color:rgba(255,255,255,.1);margin:20px 0;">';
    echo $htmlBody;
    echo '</div>';
}
