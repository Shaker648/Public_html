<?php
/*
 * notifications.php — "My notifications" (every signed-in user).
 *
 * Turn phone notifications on for this phone (iPhone: from the Home Screen
 * app; Android: straight from Chrome), send yourself a test, see and remove
 * your phones, and read what you were sent. What each person receives is set
 * by the admin on notifications_admin.php.
 */
require 'auth.php';
require 'config.php';
require_once 'push_helpers.php';

perm_require('page.notifications');

$lang = ($_GET['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
$dir  = $lang === 'ar' ? 'rtl' : 'ltr';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid  = (int)$_SESSION['user_id'];
$me   = (string)$_SESSION['username'];
$role = (string)($_SESSION['role'] ?? '');

push_tables($pdo);
$events = notify_events();
$rules  = notify_rules($pdo);
$mine   = [];
$mineOwn = [];   // events you only get when they are about you
foreach ($rules as $ev => $r) {
    $in = $r['on'] && (in_array($role, $r['roles'], true) || in_array($uid, $r['plus'], true) || !empty($r['owner'])) && !in_array($uid, $r['minus'], true);
    if ($in) $mine[] = $ev;
    $mineOwn[$ev] = $r['on'] && !empty($r['owner']) && !in_array($role, $r['roles'], true) && !in_array($uid, $r['plus'], true);
}
$st = $pdo->prepare("SELECT id, device, last_error, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_created, TIMESTAMPDIFF(SECOND, last_ok_at, NOW()) AS age_ok FROM push_subscriptions WHERE user_id = ? ORDER BY id DESC");
$st->execute([$uid]);
$devices = $st->fetchAll(PDO::FETCH_ASSOC);
$st = $pdo->prepare("SELECT l.*, TIMESTAMPDIFF(SECOND, l.created_at, NOW()) AS age FROM notify_inbox i JOIN notify_log l ON l.id = i.log_id WHERE i.user_id = ? ORDER BY l.id DESC LIMIT 60");
$st->execute([$uid]);
$inbox = $st->fetchAll(PDO::FETCH_ASSOC);

$T = $lang === 'ar' ? [
    'title' => 'إشعاراتي', 'sub' => 'إشعارات فورية على موبايلك مثل واتساب', 'back' => 'الرئيسية', 'admin' => 'التحكم في الإشعارات',
    'this' => 'هذا الجهاز', 'enable' => '🔔 تفعيل الإشعارات على هذا الجهاز', 'enabled' => 'الإشعارات مفعّلة على هذا الجهاز', 'test' => '📨 إرسال إشعار تجربة', 'off' => 'إيقاف على هذا الجهاز',
    'devices' => 'أجهزتي', 'noDev' => 'لم تفعّل الإشعارات على أي جهاز بعد', 'remove' => 'إزالة', 'lastOk' => 'آخر إشعار وصل', 'never' => 'لم يصل بعد', 'added' => 'أضيف',
    'what' => 'ما الذي يصلك', 'whatNote' => 'المدير هو من يحدد ما يصل لكل شخص', 'none' => 'لا يوجد شيء مفعّل لك حالياً',
    'inbox' => 'آخر الإشعارات', 'noInbox' => 'لا توجد إشعارات بعد', 'open' => 'فتح',
] : [
    'title' => 'My notifications', 'sub' => 'Instant notifications on your phone, like WhatsApp', 'back' => 'Dashboard', 'admin' => 'Notification control',
    'this' => 'This device', 'enable' => '🔔 Turn on notifications on this device', 'enabled' => 'Notifications are on for this device', 'test' => '📨 Send a test notification', 'off' => 'Turn off on this device',
    'devices' => 'My devices', 'noDev' => 'You haven\'t turned notifications on on any device yet', 'remove' => 'Remove', 'lastOk' => 'Last delivered', 'never' => 'Nothing delivered yet', 'added' => 'Added',
    'what' => 'What you receive', 'whatNote' => 'The admin decides what each person receives', 'none' => 'Nothing is switched on for you right now',
    'inbox' => 'Recent notifications', 'noInbox' => 'No notifications yet', 'open' => 'Open',
];
$ago = function ($s) use ($lang): string {
    if ($s === null || $s === '') return '';
    $s = max(0, (int)$s);
    if ($s < 60) return $lang === 'ar' ? 'الآن' : 'just now';
    if ($s < 3600) { $m = intdiv($s, 60); return $lang === 'ar' ? 'منذ ' . $m . ' دقيقة' : $m . ' min ago'; }
    if ($s < 86400) { $h = intdiv($s, 3600); return $lang === 'ar' ? 'منذ ' . $h . ' ساعة' : $h . ' h ago'; }
    $d = intdiv($s, 86400); return $lang === 'ar' ? ($d === 1 ? 'أمس' : 'منذ ' . $d . ' يوم') : ($d === 1 ? 'yesterday' : $d . ' days ago');
};
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= $T['title'] ?> — First 1 Car</title>
<?php include __DIR__ . '/pwa_head.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<?php include __DIR__ . '/notify_style.php'; ?>
</head>
<body>
<div class="nf-wrap">
    <header class="nf-head">
        <div>
            <h1>🔔 <?= $T['title'] ?></h1>
            <p><?= $T['sub'] ?></p>
        </div>
        <div class="nf-nav">
            <?php if (can('page.notifications_admin')): ?><a class="nf-btn pur" href="notifications_admin.php?lang=<?= $lang ?>">⚙️ <?= $T['admin'] ?></a><?php endif; ?>
            <a class="nf-btn" href="dashboard.php?lang=<?= $lang ?>">🏠 <?= $T['back'] ?></a>
            <a class="nf-btn ghost" href="?lang=<?= $lang === 'ar' ? 'en' : 'ar' ?>"><?= $lang === 'ar' ? 'English' : 'العربية' ?></a>
        </div>
    </header>

    <?php include __DIR__ . '/notify_device_card.php'; ?>

    <div class="nf-grid">
        <section class="nf-card">
            <h2>📱 <?= $T['devices'] ?> <span class="nf-count"><?= count($devices) ?></span></h2>
            <?php if (!$devices): ?><div class="nf-empty"><?= $T['noDev'] ?></div><?php endif; ?>
            <div class="nf-list">
                <?php foreach ($devices as $d): ?>
                <div class="nf-dev" data-id="<?= (int)$d['id'] ?>">
                    <div class="ic"><?= preg_match('/iPhone|iPad/', (string)$d['device']) ? '📱' : (preg_match('/Android/', (string)$d['device']) ? '🤖' : '💻') ?></div>
                    <div class="mn">
                        <b><?= htmlspecialchars((string)$d['device']) ?></b>
                        <small><?= $T['added'] ?> <?= htmlspecialchars($ago($d['age_created'])) ?> · <?= $d['age_ok'] !== null ? $T['lastOk'] . ' ' . htmlspecialchars($ago($d['age_ok'])) : $T['never'] ?></small>
                        <?php if ($d['last_error']): ?><small class="bad">⚠️ <?= htmlspecialchars(mb_substr((string)$d['last_error'], 0, 80)) ?></small><?php endif; ?>
                    </div>
                    <button type="button" class="nf-mini" data-test="<?= (int)$d['id'] ?>">📨</button>
                    <button type="button" class="nf-mini red" data-remove="<?= (int)$d['id'] ?>"><?= $T['remove'] ?></button>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="nf-card">
            <h2>📋 <?= $T['what'] ?></h2>
            <p class="nf-note"><?= $T['whatNote'] ?></p>
            <?php if (!$mine): ?><div class="nf-empty"><?= $T['none'] ?></div><?php endif; ?>
            <div class="nf-chips">
                <?php foreach ($mine as $ev): ?><span><?= $events[$ev][2] ?> <?= htmlspecialchars($lang === 'ar' ? $events[$ev][0] : $events[$ev][1]) ?><?= !empty($mineOwn[$ev]) ? ' <small style="opacity:.7">(' . ($lang === 'ar' ? 'لما يخصك' : 'when it is about you') . ')</small>' : '' ?></span><?php endforeach; ?>
            </div>
        </section>
    </div>

    <section class="nf-card">
        <h2>🕘 <?= $T['inbox'] ?></h2>
        <?php if (!$inbox): ?><div class="nf-empty"><?= $T['noInbox'] ?></div><?php endif; ?>
        <div class="nf-feed">
            <?php foreach ($inbox as $n): ?>
            <a class="nf-item" href="<?= htmlspecialchars((string)$n['url']) ?>">
                <div class="t"><?= htmlspecialchars($n['title']) ?></div>
                <div class="b"><?= nl2br(htmlspecialchars((string)$n['body'])) ?></div>
                <div class="w"><?= htmlspecialchars($ago($n['age'])) ?></div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
</div>
<script>
(function () {
    const CSRF = <?= json_encode($csrf) ?>, LANG = <?= json_encode($lang) ?>;
    document.querySelectorAll('[data-remove]').forEach(b => b.addEventListener('click', async () => {
        await fetch('push_subscribe.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'remove', csrf: CSRF, id: +b.dataset.remove }) });
        b.closest('.nf-dev').remove();
    }));
    document.querySelectorAll('[data-test]').forEach(b => b.addEventListener('click', async () => {
        b.disabled = true; const t = b.textContent; b.textContent = '…';
        try { const r = await F1Push.test(CSRF, LANG, +b.dataset.test); b.textContent = r.ok ? '✅' : '⚠️'; } catch (e) { b.textContent = '⚠️'; }
        setTimeout(() => { b.textContent = t; b.disabled = false; }, 2500);
    }));
})();
</script>
</body>
</html>
