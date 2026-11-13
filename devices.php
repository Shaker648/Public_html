<?php
/*
 * devices.php — admins only: the phones and computers each account is used on,
 * when each was last used, and a button to sign a device (or a person) out.
 */
require 'auth.php';
require 'config.php';
require_once __DIR__ . '/push_helpers.php';
require_once __DIR__ . '/devices_helpers.php';

$lang = ($_GET['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
$ar   = $lang === 'ar';
if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: dashboard.php?lang=' . $lang . '&denied=1'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
dev_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $u = (int)($_POST['uid'] ?? 0);
        if ($u) {
            dev_revoke($pdo, $u, (int)($_POST['dev'] ?? 0));
            $_SESSION['dv_flash'] = $ar ? '✅ تم تسجيل الخروج — سيُطلب منه تسجيل الدخول من جديد على ذلك الجهاز' : '✅ Signed out — they will be asked to sign in again on that device';
        }
    }
    header('Location: devices.php?lang=' . $lang);
    exit;
}
$flash = $_SESSION['dv_flash'] ?? ''; unset($_SESSION['dv_flash']);

$rows = $pdo->query("SELECT d.*, u.username, u.role, u.active, TIMESTAMPDIFF(SECOND, d.last_seen, NOW()) AS age
                     FROM user_devices d JOIN users u ON u.id = d.user_id ORDER BY FIELD(u.role,'admin','manager','sales'), u.username, d.last_seen DESC")->fetchAll(PDO::FETCH_ASSOC);
$by = [];
foreach ($rows as $r) $by[(int)$r['user_id']][] = $r;
$mine = dev_id();
$ago = function ($s) use ($ar): string {
    $s = max(0, (int)$s);
    if ($s < 300) return $ar ? '🟢 الآن' : '🟢 now';
    if ($s < 3600) { $m = intdiv($s, 60); return $ar ? 'منذ ' . $m . ' دقيقة' : $m . ' min ago'; }
    if ($s < 86400) { $h = intdiv($s, 3600); return $ar ? 'منذ ' . $h . ' ساعة' : $h . ' h ago'; }
    $d = intdiv($s, 86400); return $ar ? ($d === 1 ? 'أمس' : 'منذ ' . $d . ' يوماً') : ($d === 1 ? 'yesterday' : $d . ' days ago');
};
$roleL = $ar ? ['admin' => 'أدمن', 'manager' => 'مدير', 'sales' => 'مبيعات'] : ['admin' => 'Admin', 'manager' => 'Manager', 'sales' => 'Sales'];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $ar ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= $ar ? 'الأجهزة' : 'Devices' ?> — First 1 Car</title>
<?php include __DIR__ . '/pwa_head.php'; ?>
<?php include __DIR__ . '/notify_style.php'; ?>
<style>
.dv-u{margin-bottom:14px}
.dv-uh{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px}
.dv-uh h2{margin:0}.dv-uh form{margin-inline-start:auto}
.dv-role{font-size:11px;font-weight:900;padding:3px 10px;border-radius:999px;background:rgba(147,51,234,.2);color:#e9d5ff}
.dv-d{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 14px;border-radius:14px;border:1px solid var(--line);background:rgba(255,255,255,.03);margin-bottom:8px}
.dv-d.out{opacity:.55}
.dv-d .ic{font-size:26px}.dv-d .i{flex:1;min-width:190px}
.dv-d b{display:block;font-size:14.5px}.dv-d small{display:block;font-size:12px;color:var(--mut);font-weight:700;margin-top:2px}
.dv-me{font-size:11px;font-weight:900;color:#052e16;background:#4ade80;padding:2px 9px;border-radius:999px;margin-inline-start:6px}
.dv-x{height:38px;padding:0 14px;border-radius:11px;border:1px solid rgba(239,68,68,.5);background:rgba(239,68,68,.1);color:#fca5a5;font:inherit;font-size:13px;font-weight:900;cursor:pointer}
.dv-all{height:36px;padding:0 12px;border-radius:11px;border:1px solid rgba(239,68,68,.4);background:none;color:#fca5a5;font:inherit;font-size:12.5px;font-weight:900;cursor:pointer}
.dv-flash{padding:12px 16px;border-radius:14px;font-weight:800;margin-bottom:14px;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.4);color:#86efac}
</style>
</head>
<body>
<div class="nf-wrap">
    <header class="nf-head">
        <div><h1>🔐 <?= $ar ? 'الأجهزة' : 'Devices' ?></h1>
            <p><?= $ar ? 'الهواتف والحواسيب التي يُستخدم عليها كل حساب، وآخر استخدام لكل منها. يصلك إشعار عند تسجيل الدخول من جهاز جديد.' : 'The phones and computers each account is used on, and when. You are told when an account signs in on a new device.' ?></p></div>
        <div class="nf-nav">
            <a class="nf-btn" href="dashboard.php?lang=<?= $lang ?>">🏠 <?= $ar ? 'الرئيسية' : 'Dashboard' ?></a>
            <a class="nf-btn ghost" href="?lang=<?= $ar ? 'en' : 'ar' ?>"><?= $ar ? 'English' : 'العربية' ?></a>
        </div>
    </header>
    <?php if ($flash): ?><div class="dv-flash"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
    <?php if (!$by): ?><section class="nf-card"><div class="nf-empty"><?= $ar ? 'لم يُسجَّل أي جهاز بعد — تظهر الأجهزة هنا مع استخدام النظام' : 'No devices yet — they appear here as the system is used' ?></div></section><?php endif; ?>
    <?php foreach ($by as $uid => $devs): $u = $devs[0]; ?>
    <section class="nf-card dv-u">
        <div class="dv-uh">
            <h2>👤 <?= htmlspecialchars($u['username']) ?> <span class="nf-count"><?= count($devs) ?></span></h2>
            <span class="dv-role"><?= $roleL[$u['role']] ?? htmlspecialchars((string)$u['role']) ?></span>
            <form method="post" onsubmit="return confirm(<?= htmlspecialchars(json_encode($ar ? 'تسجيل خروج ' . $u['username'] . ' من كل الأجهزة؟' : 'Sign ' . $u['username'] . ' out of every device?', JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="uid" value="<?= (int)$uid ?>">
                <button type="submit" class="dv-all">🚪 <?= $ar ? 'تسجيل الخروج من كل الأجهزة' : 'Sign out everywhere' ?></button></form>
        </div>
        <?php foreach ($devs as $d): $lbl = dev_label((string)$d['user_agent']); $isMe = (int)$uid === (int)$_SESSION['user_id'] && $d['dev_id'] === $mine; ?>
        <div class="dv-d<?= (int)$d['revoked'] ? ' out' : '' ?>">
            <span class="ic"><?= preg_match('/iPhone|Android|iPad/', $lbl) ? '📱' : '💻' ?></span>
            <div class="i"><b><?= htmlspecialchars($lbl) ?><?php if ($isMe): ?><span class="dv-me"><?= $ar ? 'هذا الجهاز' : 'This device' ?></span><?php endif; ?></b>
                <small><?= $ar ? 'آخر استخدام: ' : 'Last used: ' ?><?= htmlspecialchars($ago($d['age'])) ?> · IP <?= htmlspecialchars((string)$d['ip']) ?></small>
                <small><?= $ar ? 'أول مرة: ' : 'First seen: ' ?><?= htmlspecialchars(date('Y-m-d', strtotime($d['first_seen']))) ?><?= (int)$d['revoked'] ? ' · ' . ($ar ? '🚪 تم تسجيل خروجه' : '🚪 signed out') : '' ?></small></div>
            <?php if (!(int)$d['revoked'] && !$isMe): ?>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="uid" value="<?= (int)$uid ?>"><input type="hidden" name="dev" value="<?= (int)$d['id'] ?>">
                <button type="submit" class="dv-x">🚪 <?= $ar ? 'تسجيل الخروج' : 'Sign out' ?></button></form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endforeach; ?>
</div>
</body>
</html>
