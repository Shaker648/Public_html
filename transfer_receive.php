<?php
/*
 * transfer_receive.php — "cars on their way to your branch".
 *
 * After a transfer, the receiving branch presses «استلمت» when the car arrives
 * (here, on the notification itself, or on the pop-up in the system). Whoever
 * moved it is told; if nobody confirms within 24 hours the admin is told.
 * Your branch is shown first (the branch you clocked in at today, or the one
 * the admin assigned to you); the other branches are listed below it.
 */
require 'auth.php';
require 'config.php';
require_once __DIR__ . '/notify_smart.php';

$lang = ($_GET['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
$dir  = $lang === 'ar' ? 'rtl' : 'ltr';
$ar   = $lang === 'ar';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$me   = (string)$_SESSION['username'];
$uid  = (int)$_SESSION['user_id'];
push_tables($pdo);

/* ─── confirm ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['tr_flash'] = ['bad', $ar ? 'انتهت صلاحية الصفحة — حاول تاني' : 'The page expired — try again'];
    } else {
        $n = smart_receive($pdo, (array)($_POST['mids'] ?? []), $me);
        $_SESSION['tr_flash'] = $n ? ['ok', $ar ? '✅ تم تأكيد استلام ' . $n . ($n === 1 ? ' عربية' : ' عربيات') . ' — اتبعت رسالة للي نقلها' : '✅ ' . $n . ' received — the sender was told']
                                   : ['bad', $ar ? 'العربية دي اتأكد استلامها قبل كده' : 'Already confirmed'];
    }
    header('Location: transfer_receive.php?lang=' . $lang);
    exit;
}
$flash = $_SESSION['tr_flash'] ?? null;
unset($_SESSION['tr_flash']);

/* ─── my branch(es) ─── */
$mine = [];
$ub = notify_user_branches($pdo);
if (isset($ub[$uid])) $mine[] = $ub[$uid];
try {
    $st = $pdo->prepare("SELECT DISTINCT branch_name FROM attendance_logs WHERE user_id = ? AND clock_in >= CURDATE() AND clock_out IS NULL");
    $st->execute([$uid]);
    $mine = array_merge($mine, $st->fetchAll(PDO::FETCH_COLUMN));
} catch (Throwable $e) {}
$mine = array_values(array_unique(array_filter($mine)));

$pending = smart_pending_transfers($pdo, '', 7);
$groups = [];
foreach ($pending as $p) $groups[$p['to_branch']][] = $p;
uksort($groups, fn($a, $b) => (in_array($b, $mine, true) <=> in_array($a, $mine, true)) ?: strcmp($a, $b));

$recent = $pdo->query("SELECT r.received_by, TIMESTAMPDIFF(SECOND, r.received_at, NOW()) AS age, m.to_branch, c.brand, c.model, c.color, c.chassis
                       FROM transfer_receipts r JOIN movements m ON m.id = r.movement_id JOIN cars c ON c.id = m.car_id
                       ORDER BY r.received_at DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

$ago = function ($s) use ($ar): string {
    $s = max(0, (int)$s);
    if ($s < 60) return $ar ? 'الآن' : 'just now';
    if ($s < 3600) { $m = intdiv($s, 60); return $ar ? 'منذ ' . $m . ' دقيقة' : $m . ' min ago'; }
    if ($s < 86400) { $h = intdiv($s, 3600); return $ar ? 'منذ ' . $h . ' ساعة' : $h . ' h ago'; }
    $d = intdiv($s, 86400); return $ar ? ($d === 1 ? 'أمس' : 'منذ ' . $d . ' يوم') : ($d === 1 ? 'yesterday' : $d . ' days ago');
};
$br  = fn($n) => push_branch_label($pdo, (string)$n, $lang);
$col = fn($c) => push_color_label($pdo, (string)$c, $lang);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= $ar ? 'عربيات في الطريق' : 'Cars on the way' ?> — First 1 Car</title>
<?php include __DIR__ . '/pwa_head.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<?php include __DIR__ . '/notify_style.php'; ?>
<style>
.tr-br{margin-bottom:18px}
.tr-bh{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px}
.tr-bh h2{margin:0}
.tr-you{font-size:11px;font-weight:900;color:#052e16;background:#4ade80;padding:3px 10px;border-radius:999px}
.tr-bh form{margin-inline-start:auto}
.tr-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:10px}
.tr-car{position:relative;display:flex;flex-direction:column;gap:8px;padding:14px 16px;border-radius:18px;background:linear-gradient(160deg,rgba(56,189,248,.08),rgba(255,255,255,.02));border:1px solid rgba(56,189,248,.25);overflow:hidden}
.tr-car.late{border-color:rgba(245,158,11,.45);background:linear-gradient(160deg,rgba(245,158,11,.10),rgba(255,255,255,.02))}
.tr-car .nm{font-size:16px;font-weight:900}
.tr-car .mt{font-size:12.5px;color:var(--mut);font-weight:700;display:flex;gap:8px;flex-wrap:wrap}
.tr-car .ch{font-family:Inter,monospace;color:#a3e635;font-weight:800;letter-spacing:.5px}
.tr-road{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:800}
.tr-road .ln{flex:1;height:3px;border-radius:3px;background:linear-gradient(90deg,#38bdf8,#22c55e);position:relative;overflow:hidden}
.tr-road .ln::after{content:'🚚';position:absolute;top:-11px;font-size:16px;animation:trDrive 3.2s linear infinite}
[dir=rtl] .tr-road .ln::after{animation-name:trDriveR;transform:scaleX(-1)}
@keyframes trDrive{from{left:-20px}to{left:100%}}
@keyframes trDriveR{from{right:-20px}to{right:100%}}
.tr-car .ft{display:flex;align-items:center;justify-content:space-between;gap:8px}
.tr-car .ft small{font-size:11.5px;color:var(--mut);font-weight:700}
.tr-car .ft small.late{color:#fbbf24}
.tr-ok{height:40px;padding:0 16px;border:0;border-radius:12px;background:linear-gradient(90deg,#16a34a,#22c55e);color:#fff;font:inherit;font-size:14px;font-weight:900;cursor:pointer;box-shadow:0 8px 20px rgba(34,197,94,.3)}
.tr-ok:active{transform:scale(.96)}
.tr-all{height:38px;padding:0 14px;border-radius:12px;border:1px solid rgba(34,197,94,.5);background:rgba(34,197,94,.12);color:#86efac;font:inherit;font-size:13px;font-weight:900;cursor:pointer}
.tr-empty{text-align:center;padding:40px 16px}
.tr-empty .big{font-size:54px;margin-bottom:8px;animation:trBob 3s ease-in-out infinite}
@keyframes trBob{50%{transform:translateY(-6px)}}
.tr-empty b{display:block;font-size:18px;margin-bottom:4px}
.tr-rec .nf-item{cursor:default}
.tr-flash{padding:12px 16px;border-radius:14px;font-weight:800;margin-bottom:14px}
.tr-flash.ok{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.4);color:#86efac}
.tr-flash.bad{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.4);color:#fca5a5}
</style>
</head>
<body>
<div class="nf-wrap">
    <header class="nf-head">
        <div><h1>🚚 <?= $ar ? 'عربيات في الطريق لفروعنا' : 'Cars on their way' ?></h1>
            <p><?= $ar ? 'اضغط «استلمت» أول ما العربية توصل الفرع — اللي نقلها بيوصله إشعار' : 'Tap "Received" when the car reaches the branch — whoever sent it is told' ?></p></div>
        <div class="nf-nav">
            <a class="nf-btn" href="dashboard.php?lang=<?= $lang ?>">🏠 <?= $ar ? 'الرئيسية' : 'Dashboard' ?></a>
            <a class="nf-btn ghost" href="?lang=<?= $ar ? 'en' : 'ar' ?>"><?= $ar ? 'English' : 'العربية' ?></a>
        </div>
    </header>

    <?php if ($flash): ?><div class="tr-flash <?= $flash[0] ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

    <?php if (!$groups): ?>
    <section class="nf-card tr-empty">
        <div class="big">✅</div>
        <b><?= $ar ? 'مفيش عربيات مستنية تأكيد استلام' : 'Nothing waiting to be received' ?></b>
        <span class="nf-note"><?= $ar ? 'كل العربيات المنقولة وصلت واتأكدت' : 'Every transferred car has been confirmed' ?></span>
    </section>
    <?php endif; ?>

    <?php foreach ($groups as $to => $cars): $isMine = in_array($to, $mine, true); ?>
    <section class="nf-card tr-br">
        <div class="tr-bh">
            <h2>📍 <?= htmlspecialchars($br($to)) ?> <span class="nf-count"><?= count($cars) ?></span></h2>
            <?php if ($isMine): ?><span class="tr-you"><?= $ar ? 'فرعك' : 'Your branch' ?></span><?php endif; ?>
            <?php if (count($cars) > 1): ?>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <?php foreach ($cars as $c): ?><input type="hidden" name="mids[]" value="<?= (int)$c['mid'] ?>"><?php endforeach; ?>
                <button type="submit" class="tr-all">✅ <?= $ar ? 'استلمت الكل (' . count($cars) . ')' : 'Received all (' . count($cars) . ')' ?></button></form>
            <?php endif; ?>
        </div>
        <div class="tr-list">
            <?php foreach ($cars as $c): $late = (int)$c['hours'] >= 24; ?>
            <div class="tr-car<?= $late ? ' late' : '' ?>">
                <div class="nm">🚗 <?= htmlspecialchars(trim($c['brand'] . ' ' . $c['model'] . ' ' . $c['trim_name'])) ?></div>
                <div class="mt"><span><?= htmlspecialchars($col($c['color'])) ?></span><span><?= htmlspecialchars((string)$c['car_year']) ?></span><span class="ch"><?= htmlspecialchars((string)$c['chassis']) ?></span></div>
                <div class="tr-road"><span><?= htmlspecialchars($br($c['from_branch'])) ?></span><span class="ln"></span><span><?= htmlspecialchars($br($to)) ?></span></div>
                <div class="ft">
                    <small class="<?= $late ? 'late' : '' ?>"><?= $late ? '⏳ ' : '🕐 ' ?><?= htmlspecialchars($ago((int)$c['hours'] * 3600 + 60)) ?> · <?= $ar ? 'نقلها' : 'by' ?> <?= htmlspecialchars((string)$c['moved_by']) ?></small>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="mids[]" value="<?= (int)$c['mid'] ?>">
                        <button type="submit" class="tr-ok">✅ <?= $ar ? 'استلمت' : 'Received' ?></button></form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>

    <?php if ($recent): ?>
    <section class="nf-card tr-rec">
        <h2>📥 <?= $ar ? 'آخر عربيات اتأكد استلامها' : 'Recently received' ?></h2>
        <div class="nf-feed">
            <?php foreach ($recent as $r): ?>
            <div class="nf-item">
                <div class="t"><?= htmlspecialchars(trim($r['brand'] . ' ' . $r['model'] . ' · ' . $col($r['color']))) ?></div>
                <div class="b">📍 <?= htmlspecialchars($br($r['to_branch'])) ?> · ✅ <?= htmlspecialchars((string)$r['received_by']) ?> · 🔑 <?= htmlspecialchars((string)$r['chassis']) ?></div>
                <div class="w"><?= htmlspecialchars($ago($r['age'])) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</div>
</body>
</html>
