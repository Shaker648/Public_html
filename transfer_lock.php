<?php
/*
 * transfer_lock.php — the screen someone sees when their system is locked:
 * transferred cars not confirmed («استلمت») in time, or a surprise stock check
 * not finished in time.
 *
 * Or stopped by hand from lockdown.php (with a reason, or without one): then it
 * says so, shows the reason and asks them to contact their manager.
 *
 * They can confirm the cars / finish the check and sign out — nothing else,
 * not even clocking in or out — until the admin unlocks them (or it unlocks by itself once everything
 * is confirmed, if the admin turned that on). The page checks every few
 * seconds and opens the system the moment it is unlocked.
 */
require 'auth.php';
require 'config.php';
require_once __DIR__ . '/notify_smart.php';

$lang = ($_GET['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
$ar   = $lang === 'ar';
$uid  = (int)$_SESSION['user_id'];
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
push_tables($pdo);

if (($_SESSION['role'] ?? '') === 'admin') { header('Location: notifications_admin.php?lang=' . $lang . '#tr'); exit; }
$lock = user_lock_active($pdo, $uid);

if (isset($_GET['check'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['locked' => (bool)$lock]);
    exit;
}
if (!$lock) { header('Location: dashboard.php?lang=' . $lang); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
    if (($_POST['act'] ?? '') === 'missing') smart_report_missing($pdo, (int)($_POST['mid'] ?? 0), (string)$_SESSION['username'], (string)($_POST['note'] ?? ''));
    else smart_receive($pdo, (array)($_POST['mids'] ?? []), (string)$_SESSION['username']);
    header('Location: transfer_lock.php?lang=' . $lang);
    exit;
}

$left = smart_my_duties($pdo, $uid);
$checks = smart_checks_for_user($pdo, $uid);
$rules = transfer_rules($pdo);
$locks = $pdo->prepare("SELECT * FROM user_locks WHERE user_id = ? AND unlocked_at IS NULL ORDER BY id");
$locks->execute([$uid]);
$locks = $locks->fetchAll(PDO::FETCH_ASSOC);
$manual = array_values(array_filter($locks, fn($l) => ($l['kind'] ?? '') === 'manual'));
$basma = 'none';
foreach ($locks as $l) if (in_array($l['basma'], ['stopped', 'running', 'pending'], true)) $basma = $l['basma'] === 'stopped' || $basma === 'none' ? $l['basma'] : $basma;
$kindTxt = $ar ? ['manual' => 'من الإدارة', 'check' => 'عدم إكمال الجرد المفاجئ', 'transfer' => 'عدم تأكيد استلام سيارات منقولة']
               : ['manual' => 'by management', 'check' => 'stock check not finished', 'transfer' => 'transferred cars not confirmed'];
$br  = fn($n) => push_branch_label($pdo, (string)$n, $lang);
$col = fn($c) => push_color_label($pdo, (string)$c, $lang);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $ar ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= $ar ? 'النظام متوقف' : 'System locked' ?> — First 1 Car</title>
<?php include __DIR__ . '/pwa_head.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<?php include __DIR__ . '/notify_style.php'; ?>
<style>
body{min-height:100vh;background:radial-gradient(120% 70% at 50% 0%,#3b0a0a 0%,#0b1024 50%,#02040d 100%)}
.lk{max-width:560px;margin:0 auto;padding:calc(28px + env(safe-area-inset-top)) 16px 40px;text-align:center}
.lk-ic{width:108px;height:108px;margin:0 auto 14px;border-radius:32px;display:grid;place-items:center;font-size:54px;
  background:linear-gradient(145deg,rgba(239,68,68,.25),rgba(147,51,234,.18));border:1px solid rgba(239,68,68,.45);
  box-shadow:0 0 0 10px rgba(239,68,68,.06),0 20px 50px rgba(239,68,68,.25);animation:lkShake 3.2s ease-in-out infinite}
.lk-ic.ok{background:linear-gradient(145deg,rgba(34,197,94,.25),rgba(56,189,248,.15));border-color:rgba(34,197,94,.5);box-shadow:0 0 0 10px rgba(34,197,94,.06),0 20px 50px rgba(34,197,94,.25);animation:lkBreath 2.4s ease-in-out infinite}
@keyframes lkShake{0%,86%,100%{transform:rotate(0)}89%{transform:rotate(-8deg)}92%{transform:rotate(8deg)}95%{transform:rotate(-5deg)}}
@keyframes lkBreath{50%{transform:scale(1.05)}}
.lk h1{font-size:clamp(24px,6vw,32px);font-weight:900;margin:0 0 8px}
.lk p.s{color:#cbd5e1;font-size:15px;line-height:1.8;font-weight:700;margin:0 0 20px}
.lk-list{display:grid;gap:10px;text-align:start}
.lk-car{display:flex;align-items:center;gap:12px;padding:14px;border-radius:18px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1)}
.lk-car .i{flex:1;min-width:0}
.lk-car b{display:block;font-size:15.5px}
.lk-car small{display:block;color:#94a3b8;font-size:12.5px;font-weight:700;margin-top:3px}
.lk-car .ch{font-family:Inter,monospace;color:#a3e635;font-weight:800}
.lk-ok{height:44px;padding:0 16px;border:0;border-radius:13px;background:linear-gradient(90deg,#16a34a,#22c55e);color:#fff;font:inherit;font-size:14.5px;font-weight:900;cursor:pointer;white-space:nowrap;box-shadow:0 8px 20px rgba(34,197,94,.3)}
.lk-all{width:100%;height:52px;margin-top:12px;border:0;border-radius:16px;background:linear-gradient(90deg,#16a34a,#0ea5e9);color:#fff;font:inherit;font-size:16px;font-weight:900;cursor:pointer}
.lk-wait{padding:16px;border-radius:18px;background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.35);color:#bbf7d0;font-weight:800;line-height:1.8}
.lk-wait .dots::after{content:'';animation:lkDots 1.4s steps(4) infinite}
@keyframes lkDots{0%{content:''}25%{content:'.'}50%{content:'..'}75%{content:'...'}}
.lk-foot{display:flex;gap:10px;justify-content:center;margin-top:24px;flex-wrap:wrap}
.lk-foot a{display:inline-flex;align-items:center;gap:6px;height:42px;padding:0 16px;border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.05);color:#e2e8f0;text-decoration:none;font-weight:800;font-size:14px}
.lk-car{flex-wrap:wrap}.lk-missf{margin:0}
.lk-miss{height:44px;padding:0 12px;border-radius:13px;border:1px solid rgba(239,68,68,.5);background:rgba(239,68,68,.1);color:#fca5a5;font:inherit;font-size:13.5px;font-weight:900;cursor:pointer;white-space:nowrap}
.lk-bas{margin:0 0 16px;padding:10px 14px;border-radius:14px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.4);color:#fecaca;font-size:13.5px;font-weight:800}
.lk-chk{display:flex;align-items:center;gap:12px;padding:14px;margin-bottom:12px;border-radius:18px;text-decoration:none;color:#fff;text-align:start;
  background:linear-gradient(120deg,rgba(220,38,38,.25),rgba(147,51,234,.2));border:1px solid rgba(248,113,113,.5)}
.lk-chk .ic{font-size:28px}.lk-chk .tx{flex:1}.lk-chk b{display:block;font-size:15.5px}.lk-chk small{color:#fecaca;font-weight:700}
.lk-chk .go{padding:9px 14px;border-radius:12px;background:linear-gradient(90deg,#16a34a,#22c55e);font-weight:900;font-size:13.5px;white-space:nowrap}
.lk-h3{text-align:start;font-size:15px;font-weight:900;margin:16px 0 10px}
.lk-rs{margin:0 0 14px;padding:14px;border-radius:16px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.12);text-align:start}
.lk-rs div{font-size:14px;font-weight:800;line-height:1.8}.lk-rs small{display:block;color:#94a3b8;font-size:12px;font-weight:700}
.lk-call{margin:0 0 16px;padding:12px 14px;border-radius:14px;background:rgba(56,189,248,.1);border:1px solid rgba(56,189,248,.4);color:#bae6fd;font-size:14px;font-weight:900}
.lk-bas.run{background:rgba(250,204,21,.08);border-color:rgba(250,204,21,.4);color:#fde68a}
.lk-why{margin-top:18px;font-size:12.5px;color:#94a3b8;font-weight:700;line-height:1.7}
</style>
</head>
<body>
<div class="lk">
    <?php
    $basmaBox = function () use ($ar, $basma) {
        if ($basma === 'stopped') return '<div class="lk-bas">⏹ ' . ($ar ? 'تم إيقاف بصمتك من وقت إيقاف النظام، وكُتب السبب في سجل الحضور. لا يمكنك تسجيل الحضور حتى تفتح الإدارة النظام' : 'Your clock-in was stopped at the moment of the stop and the reason written into attendance. You cannot clock in until the admin unlocks the system') . '</div>';
        if ($basma === 'running' || $basma === 'pending') return '<div class="lk-bas run">▶ ' . ($ar ? 'بصمتك ما زالت مستمرة، ولا يمكنك تسجيل الانصراف حتى تفتح الإدارة النظام' : 'Your clock-in is still running; you cannot clock out until the admin unlocks the system') . '</div>';
        return '<div class="lk-bas">🚫 ' . ($ar ? 'لا يمكنك تسجيل الحضور أو الانصراف (البصمة) حتى تفتح الإدارة النظام' : 'You cannot clock in or out until the admin unlocks the system') . '</div>';
    };
    $reasons = function () use ($locks, $ar, $kindTxt) {
        $h = '<div class="lk-rs">';
        foreach ($locks as $l) {
            $why = $l['kind'] === 'manual' ? ((string)$l['reason'] !== '' ? $l['reason'] : ($ar ? 'لم تُكتب ملاحظة' : 'no note given')) : ($kindTxt[$l['kind']] ?? '');
            $h .= '<div>📝 ' . htmlspecialchars(($ar ? 'السبب: ' : 'Reason: ') . $why) . '</div>'
                . '<small>🕐 ' . htmlspecialchars(date('Y-m-d', strtotime($l['locked_at'])) . ' ' . smart_time_label(strtotime($l['locked_at']), $ar ? 'ar' : 'en')) . '</small>';
        }
        return $h . '</div>';
    };
    ?>
    <?php if ($manual): ?>
    <div class="lk-ic">🔒</div>
    <h1><?= $ar ? 'تم إيقاف النظام من الإدارة' : 'Your system was stopped by management' ?></h1>
    <?= $reasons() ?>
    <div class="lk-call">📞 <?= $ar ? 'للاستفسار تواصل مع مديرك أو الإدارة' : 'Please contact your manager or the management' ?></div>
    <?= $basmaBox() ?>
    <?php if ($left || $checks): ?><p class="s"><?= $ar ? 'مطلوب منك أيضاً:' : 'Also waiting for you:' ?></p><?php endif; ?>
    <?php elseif ($left || $checks): ?>
    <div class="lk-ic">🔒</div>
    <h1><?= $ar ? 'تم إيقاف النظام مؤقتاً' : 'Your system is locked' ?></h1>
    <p class="s"><?= $ar ? 'لم يتم إنجاز المطلوب منك في الوقت المحدد.<br>أكمل المطلوب أدناه، ثم ينتظر فتح النظام من الإدارة.' : 'What was asked of you was not done in time.<br>Finish it below — then the admin unlocks your system.' ?></p>
    <?= $reasons() ?>
    <?= $basmaBox() ?>
    <?php endif; ?>
    <?php if ($left || $checks): ?>
    <?php foreach ($checks as $ck): ?>
    <a class="lk-chk" href="stock_check.php?lang=<?= $lang ?>&id=<?= (int)$ck['id'] ?>">
        <span class="ic">📋</span>
        <span class="tx"><b><?= $ar ? 'جرد مفاجئ: فرع ' : 'Surprise stock check: ' ?><?= htmlspecialchars($br($ck['branch'])) ?></b>
            <small><?php [$kt, $km] = smart_check_counts($pdo, (int)$ck['id']); echo $ar ? 'تمت مراجعة ' . $km . ' من أصل ' . $kt : $km . ' of ' . $kt . ' cars checked'; ?></small></span>
        <span class="go"><?= $ar ? 'إكمال الجرد ←' : 'Finish →' ?></span>
    </a>
    <?php endforeach; ?>
    <?php if ($left): ?><h3 class="lk-h3">🚚 <?= $ar ? 'سيارات بانتظار تأكيد الاستلام' : 'Cars waiting for "Received"' ?></h3><?php endif; ?>
    <div class="lk-list">
        <?php foreach ($left as $c): ?>
        <div class="lk-car">
            <div class="i"><b>🚗 <?= htmlspecialchars(trim($c['brand'] . ' ' . $c['model'] . ' ' . $c['trim_name'])) ?></b>
                <small><?= htmlspecialchars($col($c['color'])) ?> · <span class="ch"><?= htmlspecialchars((string)$c['chassis']) ?></span> · <?= htmlspecialchars($br($c['from_branch'])) ?> ← <?= htmlspecialchars($br($c['to_branch'])) ?></small></div>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="mids[]" value="<?= (int)$c['mid'] ?>">
                <button class="lk-ok" type="submit">✅ <?= $ar ? 'تم الاستلام' : 'Received' ?></button></form>
            <form method="post" class="lk-missf"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="act" value="missing"><input type="hidden" name="mid" value="<?= (int)$c['mid'] ?>"><input type="hidden" name="note" value="">
                <button class="lk-miss" type="submit">❌ <?= $ar ? 'لم تصل' : 'Not here' ?></button></form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if (count($left) > 1): ?>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <?php foreach ($left as $c): ?><input type="hidden" name="mids[]" value="<?= (int)$c['mid'] ?>"><?php endforeach; ?>
        <button class="lk-all" type="submit">✅ <?= $ar ? 'تأكيد استلام الكل (' . count($left) . ')' : 'Received all (' . count($left) . ')' ?></button></form>
    <?php endif; ?>
    <?php endif; ?>
    <?php if (!$manual && !$left && !$checks): ?>
    <div class="lk-ic ok">⏳</div>
    <h1><?= $ar ? 'أحسنت — تم إنجاز كل المطلوب ✅' : 'Done — everything is finished ✅' ?></h1>
    <div class="lk-wait"><?= $ar ? 'تم إبلاغ الإدارة. ستُفتح هذه الصفحة تلقائياً فور فتح النظام لك' : 'The admin has been told. This page opens by itself as soon as you are unlocked' ?><span class="dots"></span></div>
    <?= $basmaBox() ?>
    <?php endif; ?>
    <?php if ($left): ?><div class="lk-why">🔔 <?= $ar ? 'كانت المهلة ' . rtrim(rtrim(number_format($rules['hours'], 1), '0'), '.') . ' ساعة من وقت تسجيل حضورك في الفرع لتأكيد الاستلام' : 'You had ' . rtrim(rtrim(number_format($rules['hours'], 1), '0'), '.') . ' h from clocking in at the branch to confirm' ?></div><?php endif; ?>
    <div class="lk-foot">
        <a href="logout.php">🚪 <?= $ar ? 'تسجيل الخروج' : 'Sign out' ?></a>
    </div>
</div>
<script>
document.querySelectorAll('.lk-missf').forEach(f => f.addEventListener('submit', e => {
    const n = prompt(<?= json_encode($ar ? 'ملاحظة (اختياري): ماذا حدث؟' : 'Note (optional): what happened?', JSON_UNESCAPED_UNICODE) ?>, '');
    if (n === null) { e.preventDefault(); return; }
    f.querySelector('[name=note]').value = n;
}));
setInterval(async () => {
    try { const r = await (await fetch('transfer_lock.php?check=1', { credentials: 'same-origin', cache: 'no-store' })).json(); if (r && !r.locked) location.href = 'dashboard.php?lang=<?= $lang ?>'; } catch (e) {}
}, 10000);
</script>
</body>
</html>
