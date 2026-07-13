<?php

/**
 * ═══════════════════════════════════════════════════════
 *  First 1 Car — Daily Summary Email
 *  Run via cron every morning at 9:00 AM
 *  Sends a formatted HTML email to the admin with:
 *    - Available stock overview
 *    - Yesterday's sales
 *    - Month-to-date vs last month comparison
 *    - Salesman leaderboard (this month)
 *    - Low stock alert if needed
 * ═══════════════════════════════════════════════════════
 */

// ── Config ────────────────────────────────────────────────────────────────────
define('TO_EMAIL',   'm.shaker@first1car.net');
define('TO_NAME',    'First 1 Car Admin');
define('FROM_EMAIL', 'noreply@first1car.net');
define('FROM_NAME',  'First 1 Car System');
define('LOW_STOCK',  10);   // warn when available cars drop below this number

// ── Bootstrap ─────────────────────────────────────────────────────────────────
// Works whether called from cron (absolute path) or browser
$root = dirname(__FILE__);
require $root . '/config.php';

// ── Queries ───────────────────────────────────────────────────────────────────

// Total available
$totalAvail = (int) $pdo->query("SELECT COUNT(*) FROM cars WHERE status='available'")->fetchColumn();

// Available breakdown by brand
$byBrand = $pdo->query("
    SELECT brand, COUNT(*) AS cnt
    FROM cars WHERE status='available'
    GROUP BY brand ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Yesterday's sales
$yesterday = date('Y-m-d', strtotime('-1 day'));
$yesSales = $pdo->prepare("
    SELECT sc.*, c.brand, c.model, c.trim_name, c.color
    FROM sold_cars sc
    LEFT JOIN cars c ON sc.car_id = c.id
    WHERE DATE(sc.sold_at) = ?
    ORDER BY sc.sold_at DESC
");
$yesSales->execute([$yesterday]);
$yesSalesList = $yesSales->fetchAll(PDO::FETCH_ASSOC);
$yesCount = count($yesSalesList);

// Month-to-date sales
$thisMonthCount = (int) $pdo->query("
    SELECT COUNT(*) FROM sold_cars
    WHERE MONTH(sold_at)=MONTH(CURDATE()) AND YEAR(sold_at)=YEAR(CURDATE())
")->fetchColumn();

// Last month total
$lastMonthCount = (int) $pdo->query("
    SELECT COUNT(*) FROM sold_cars
    WHERE MONTH(sold_at)=MONTH(DATE_SUB(CURDATE(),INTERVAL 1 MONTH))
      AND YEAR(sold_at)=YEAR(DATE_SUB(CURDATE(),INTERVAL 1 MONTH))
")->fetchColumn();

$diff = $thisMonthCount - $lastMonthCount;
$pct  = $lastMonthCount > 0 ? round(($diff / $lastMonthCount) * 100) : ($thisMonthCount > 0 ? 100 : 0);

// Salesman leaderboard (this month, customer sales only, no admins)
$leaderboard = $pdo->query("
    SELECT
        COALESCE(NULLIF(s.salesman,''), s.sold_by) AS seller,
        SUM(s.sale_type='customer') AS cust,
        SUM(s.sale_type='dealer')   AS deal,
        COUNT(*) AS total
    FROM sold_cars s
    LEFT JOIN users u ON u.username = COALESCE(NULLIF(s.salesman,''), s.sold_by)
    WHERE MONTH(s.sold_at)=MONTH(CURDATE()) AND YEAR(s.sold_at)=YEAR(CURDATE())
      AND (u.role IS NULL OR u.role <> 'admin')
    GROUP BY seller ORDER BY total DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Top available brands for subject line
$topBrand = !empty($byBrand) ? $byBrand[0]['brand'] : '';

// ── Build the HTML email ──────────────────────────────────────────────────────

$dateAr   = date('l، d F Y');
$dateEn   = date('l, d F Y');
$today    = date('Y-m-d');
$monthAr  = date('F Y');
$lowStock = $totalAvail < LOW_STOCK;

// Trend arrow
$arrow     = $diff > 0 ? '▲' : ($diff < 0 ? '▼' : '●');
$arrowColor= $diff > 0 ? '#22c55e' : ($diff < 0 ? '#ef4444' : '#94a3b8');
$diffTxt   = ($diff > 0 ? '+' : '') . $diff . ' (' . ($pct > 0 ? '+' : '') . $pct . '%)';

// Medal emojis
$medals = ['🥇', '🥈', '🥉', '4️⃣', '5️⃣'];

ob_start();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تقرير يومي — First 1 Car</title>
</head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:'Segoe UI',Tahoma,Arial,sans-serif;direction:rtl;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f0f4f8;padding:24px 0;">
<tr><td align="center">
<table width="620" cellpadding="0" cellspacing="0" border="0" style="max-width:620px;width:100%;">

    <!-- Header -->
    <tr>
        <td style="background:linear-gradient(135deg,#020617,#0f172a);border-radius:20px 20px 0 0;padding:32px 32px 24px;text-align:center;">
            <div style="font-size:36px;margin-bottom:8px;">🚗</div>
            <div style="font-size:26px;font-weight:900;color:#22c55e;letter-spacing:-0.5px;">
                First<span style="color:#9333ea;">1</span>Car
            </div>
            <div style="font-size:14px;color:#94a3b8;margin-top:6px;">التقرير اليومي — <?= $dateAr ?></div>
            <?php if ($lowStock): ?>
            <div style="margin-top:14px;background:rgba(239,68,68,.2);border:1px solid rgba(239,68,68,.4);border-radius:50px;padding:8px 20px;display:inline-block;color:#fca5a5;font-size:13px;font-weight:700;">
                ⚠️ تحذير: المخزون المتاح منخفض (<?= $totalAvail ?> سيارة فقط)
            </div>
            <?php endif; ?>
        </td>
    </tr>

    <!-- Stat cards row -->
    <tr>
        <td style="background:#111827;padding:0 32px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="padding:20px 0;border-bottom:1px solid rgba(255,255,255,.08);">
                <tr>
                    <td width="33%" align="center" style="padding:16px 8px;">
                        <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">المخزون المتاح</div>
                        <div style="font-size:42px;font-weight:900;color:<?= $lowStock ? '#ef4444' : '#22c55e' ?>;margin-top:4px;line-height:1;"><?= $totalAvail ?></div>
                        <div style="font-size:11px;color:#64748b;margin-top:4px;">سيارة</div>
                    </td>
                    <td width="33%" align="center" style="padding:16px 8px;border-right:1px solid rgba(255,255,255,.08);border-left:1px solid rgba(255,255,255,.08);">
                        <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">مبيعات أمس</div>
                        <div style="font-size:42px;font-weight:900;color:#60a5fa;margin-top:4px;line-height:1;"><?= $yesCount ?></div>
                        <div style="font-size:11px;color:#64748b;margin-top:4px;"><?= $yesterday ?></div>
                    </td>
                    <td width="33%" align="center" style="padding:16px 8px;">
                        <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">مبيعات الشهر</div>
                        <div style="font-size:42px;font-weight:900;color:#c084fc;margin-top:4px;line-height:1;"><?= $thisMonthCount ?></div>
                        <div style="font-size:11px;color:<?= $arrowColor ?>;margin-top:4px;"><?= $arrow ?> <?= $diffTxt ?> عن الشهر الماضي</div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    <!-- Available by brand -->
    <tr>
        <td style="background:#111827;padding:0 32px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="padding:20px 0;border-bottom:1px solid rgba(255,255,255,.08);">
                <tr><td colspan="2">
                    <div style="font-size:14px;font-weight:800;color:#f1f5f9;margin-bottom:14px;">📦 المخزون المتاح حسب الماركة</div>
                </td></tr>
                <?php foreach ($byBrand as $b): ?>
                <tr>
                    <td style="color:#94a3b8;font-size:14px;padding:6px 0;"><?= htmlspecialchars($b['brand']) ?></td>
                    <td style="text-align:left;" align="left">
                        <span style="background:rgba(34,197,94,.12);color:#22c55e;border:1px solid rgba(34,197,94,.25);border-radius:50px;padding:3px 12px;font-size:13px;font-weight:800;"><?= (int)$b['cnt'] ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($byBrand)): ?>
                <tr><td colspan="2" style="color:#64748b;font-size:14px;padding:6px 0;">لا توجد سيارات متاحة</td></tr>
                <?php endif; ?>
            </table>
        </td>
    </tr>

    <!-- Yesterday's sales -->
    <tr>
        <td style="background:#111827;padding:0 32px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="padding:20px 0;border-bottom:1px solid rgba(255,255,255,.08);">
                <tr><td colspan="4">
                    <div style="font-size:14px;font-weight:800;color:#f1f5f9;margin-bottom:14px;">
                        💰 مبيعات أمس (<?= $yesterday ?>)
                        <?php if ($yesCount === 0): ?>
                        <span style="color:#64748b;font-weight:400;font-size:13px;"> — لا توجد مبيعات</span>
                        <?php endif; ?>
                    </div>
                </td></tr>
                <?php if (!empty($yesSalesList)): ?>
                <tr style="border-bottom:1px solid rgba(255,255,255,.06);">
                    <td style="font-size:11px;color:#64748b;font-weight:700;padding-bottom:8px;">السيارة</td>
                    <td style="font-size:11px;color:#64748b;font-weight:700;padding-bottom:8px;">البائع</td>
                    <td style="font-size:11px;color:#64748b;font-weight:700;padding-bottom:8px;">النوع</td>
                    <td style="font-size:11px;color:#64748b;font-weight:700;padding-bottom:8px;">الفرع</td>
                </tr>
                <?php foreach ($yesSalesList as $s): ?>
                <tr>
                    <td style="color:#e2e8f0;font-size:13px;font-weight:700;padding:8px 0;">
                        <?= htmlspecialchars(($s['brand']??'').' '.($s['model']??'')) ?>
                        <div style="color:#64748b;font-size:11px;font-weight:400;"><?= htmlspecialchars($s['trim_name']??'') ?> • <?= htmlspecialchars($s['color']??'') ?></div>
                    </td>
                    <td style="color:#94a3b8;font-size:13px;padding:8px 4px;"><?= htmlspecialchars(($s['salesman']??'')?:($s['sold_by']??'—')) ?></td>
                    <td style="padding:8px 4px;">
                        <?php if (($s['sale_type']??'') === 'customer'): ?>
                            <span style="background:rgba(34,197,94,.12);color:#22c55e;border-radius:50px;padding:2px 8px;font-size:11px;font-weight:700;">عميل</span>
                        <?php else: ?>
                            <span style="background:rgba(147,51,234,.12);color:#c084fc;border-radius:50px;padding:2px 8px;font-size:11px;font-weight:700;">تاجر</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:#94a3b8;font-size:13px;padding:8px 4px;"><?= htmlspecialchars($s['sold_branch']??'—') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </table>
        </td>
    </tr>

    <!-- Leaderboard -->
    <tr>
        <td style="background:#111827;padding:0 32px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="padding:20px 0;border-bottom:1px solid rgba(255,255,255,.08);">
                <tr><td colspan="4">
                    <div style="font-size:14px;font-weight:800;color:#f1f5f9;margin-bottom:14px;">🏆 ترتيب البائعين — <?= $monthAr ?></div>
                </td></tr>
                <?php if (empty($leaderboard)): ?>
                <tr><td colspan="4" style="color:#64748b;font-size:14px;padding:6px 0;">لا توجد مبيعات هذا الشهر بعد</td></tr>
                <?php else: ?>
                <?php foreach ($leaderboard as $i => $row): ?>
                <tr>
                    <td style="width:32px;font-size:18px;padding:8px 4px 8px 0;"><?= $medals[$i] ?? ($i+1).'.' ?></td>
                    <td style="color:#e2e8f0;font-size:14px;font-weight:700;padding:8px 4px;"><?= htmlspecialchars($row['seller']) ?></td>
                    <td style="padding:8px 4px;">
                        <span style="background:rgba(34,197,94,.12);color:#22c55e;border-radius:50px;padding:2px 8px;font-size:11px;font-weight:700;"><?= (int)$row['cust'] ?> عميل</span>
                        <span style="background:rgba(147,51,234,.12);color:#c084fc;border-radius:50px;padding:2px 8px;font-size:11px;font-weight:700;margin-right:4px;"><?= (int)$row['deal'] ?> تاجر</span>
                    </td>
                    <td style="color:#22c55e;font-size:16px;font-weight:900;text-align:left;" align="left"><?= (int)$row['total'] ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </table>
        </td>
    </tr>

    <!-- Footer -->
    <tr>
        <td style="background:#0a0f1e;border-radius:0 0 20px 20px;padding:20px 32px;text-align:center;">
            <div style="color:#64748b;font-size:12px;line-height:1.6;">
                هذا البريد يُرسل تلقائياً كل صباح الساعة 9:00 صباحاً<br>
                <strong style="color:#94a3b8;">First 1 Car</strong> — نظام إدارة المخزون<br>
                <a href="https://first1car.net/dashboard.php" style="color:#22c55e;text-decoration:none;">فتح لوحة التحكم ←</a>
            </div>
        </td>
    </tr>

</table>
</td></tr>
</table>

</body>
</html>
<?php
$htmlBody = ob_get_clean();

// ── Plain text fallback ───────────────────────────────────────────────────────
$plainText = "First 1 Car — التقرير اليومي\n";
$plainText .= str_repeat('=', 40) . "\n";
$plainText .= "التاريخ: $dateAr\n\n";
$plainText .= "المخزون المتاح: $totalAvail سيارة\n";
$plainText .= "مبيعات أمس ($yesterday): $yesCount\n";
$plainText .= "مبيعات الشهر: $thisMonthCount ($arrow $diffTxt عن الشهر الماضي)\n\n";

if (!empty($leaderboard)) {
    $plainText .= "ترتيب البائعين:\n";
    foreach ($leaderboard as $i => $row) {
        $plainText .= ($i+1) . ". " . $row['seller'] . " — " . $row['total'] . " مبيعات\n";
    }
}

if ($lowStock) {
    $plainText .= "\n⚠️ تحذير: المخزون المتاح منخفض ($totalAvail سيارة فقط)\n";
}

// ── Send email ────────────────────────────────────────────────────────────────
$subject = "📊 تقرير First 1 Car اليومي — " . date('d/m/Y') .
           " | متاح: $totalAvail" .
           ($yesCount > 0 ? " | أمس: $yesCount مبيعات" : '') .
           ($lowStock ? " ⚠️ مخزون منخفض" : '');

$boundary = 'BOUNDARY_' . md5(uniqid());

$headers  = "From: " . FROM_NAME . " <" . FROM_EMAIL . ">\r\n";
$headers .= "Reply-To: " . FROM_EMAIL . "\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
$headers .= "X-Mailer: First1Car-DailySummary/1.0\r\n";

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

// ── Log result ────────────────────────────────────────────────────────────────
$logFile = $root . '/daily_summary.log';
$logLine = date('Y-m-d H:i:s') . " | " .
           ($sent ? "✅ Sent" : "❌ Failed") .
           " | avail=$totalAvail | yesterday=$yesCount | month=$thisMonthCount\n";

// Keep log to last 100 lines to avoid it growing forever
if (file_exists($logFile)) {
    $lines = file($logFile);
    if (count($lines) > 100) {
        $lines = array_slice($lines, -90);
        file_put_contents($logFile, implode('', $lines));
    }
}
file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

// ── If run from browser (for testing), show confirmation ─────────────────────
if (php_sapi_name() !== 'cli') {
    echo '<div style="font-family:sans-serif;padding:20px;background:#0f172a;color:white;min-height:100vh;">';
    echo '<h2 style="color:' . ($sent ? '#22c55e' : '#ef4444') . '">';
    echo ($sent ? '✅ Email sent successfully' : '❌ Email failed to send');
    echo '</h2>';
    echo '<p style="color:#94a3b8;">To: ' . TO_EMAIL . '</p>';
    echo '<p style="color:#94a3b8;">Available: ' . $totalAvail . ' | Yesterday: ' . $yesCount . ' | Month: ' . $thisMonthCount . '</p>';
    echo '<hr style="border-color:rgba(255,255,255,.1);margin:20px 0;">';
    echo '<p style="color:#64748b;font-size:13px;">Email preview:</p>';
    echo $htmlBody;
    echo '</div>';
}
