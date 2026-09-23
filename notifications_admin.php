<?php
/*
 * notifications_admin.php — the notification control room (admin).
 * Reached from the Permissions page. Decide, per event, which roles and which
 * people get a phone notification; see every linked phone; send tests; set
 * quiet hours and the message language; read the delivery log.
 */
require 'auth.php';
require 'config.php';
require_once 'push_helpers.php';

perm_require('page.notifications_admin');

$lang = ($_GET['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
$dir  = $lang === 'ar' ? 'rtl' : 'ltr';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
push_tables($pdo);
push_vapid($pdo);   // make sure the server keys exist
$events = notify_events();
$roles  = ['admin', 'manager', 'sales'];

/* ─── JSON actions ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($csrf, (string)($in['csrf'] ?? ''))) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }
    $by = (string)$_SESSION['username'];
    try {
        switch ($in['action'] ?? '') {
            case 'save_rules':
                $clean = [];
                foreach ($events as $ev => $meta) {
                    $r = (array)($in['rules'][$ev] ?? []);
                    $clean[$ev] = [
                        'on'    => !empty($r['on']),
                        'roles' => array_values(array_intersect((array)($r['roles'] ?? []), $roles)),
                        'plus'  => array_values(array_unique(array_map('intval', (array)($r['plus'] ?? [])))),
                        'minus' => array_values(array_unique(array_map('intval', (array)($r['minus'] ?? [])))),
                    ];
                }
                push_setting_set($pdo, 'notify_rules', json_encode($clean), $by);
                echo json_encode(['ok' => true]); exit;
            case 'save_options':
                $o = (array)($in['options'] ?? []);
                push_setting_set($pdo, 'notify_options', json_encode([
                    'self'       => !empty($o['self']),
                    'quiet'      => !empty($o['quiet']),
                    'quiet_from' => preg_match('/^\d{2}:\d{2}$/', $o['quiet_from'] ?? '') ? $o['quiet_from'] : '23:00',
                    'quiet_to'   => preg_match('/^\d{2}:\d{2}$/', $o['quiet_to'] ?? '') ? $o['quiet_to'] : '08:00',
                    'lang'       => ($o['lang'] ?? 'ar') === 'en' ? 'en' : 'ar',
                ]), $by);
                echo json_encode(['ok' => true]); exit;
            case 'test_sub':
                $st = $pdo->prepare("SELECT user_id FROM push_subscriptions WHERE id = ?");
                $st->execute([(int)($in['id'] ?? 0)]);
                $u = (int)$st->fetchColumn();
                $res = $u ? notify_test($pdo, $u, (int)$in['id'], notify_options($pdo)['lang']) : [];
                $r = $res ? array_values($res)[0] : [0, 'not found'];
                echo json_encode(['ok' => $r[0] >= 200 && $r[0] < 300, 'code' => $r[0], 'error' => $r[1]]); exit;
            case 'test_target':
                $type = (string)($in['type'] ?? 'all'); $val = (string)($in['value'] ?? '');
                $sql = "SELECT u.id FROM users u WHERE u.active = 1";
                $args = [];
                if ($type === 'role' && in_array($val, $roles, true)) { $sql .= " AND u.role = ?"; $args[] = $val; }
                if ($type === 'user') { $sql .= " AND u.id = ?"; $args[] = (int)$val; }
                $st = $pdo->prepare($sql); $st->execute($args);
                $sent = 0; $total = 0; $errs = [];
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                    foreach (notify_test($pdo, (int)$uid, null, notify_options($pdo)['lang']) as [$code, $err]) {
                        $total++;
                        if ($code >= 200 && $code < 300) $sent++; else $errs[] = $code . ' ' . $err;
                    }
                }
                echo json_encode(['ok' => $sent > 0, 'sent' => $sent, 'total' => $total, 'errors' => array_slice($errs, 0, 5)], JSON_UNESCAPED_UNICODE); exit;
            case 'remove_sub':
                $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?")->execute([(int)($in['id'] ?? 0)]);
                echo json_encode(['ok' => true]); exit;
        }
    } catch (Throwable $e) {
        error_log('notifications_admin: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'server']); exit;
    }
    echo json_encode(['ok' => false, 'error' => 'unknown']); exit;
}

/* ─── page data ─── */
$rules   = notify_rules($pdo);
$opts    = notify_options($pdo);
$users   = $pdo->query("SELECT id, username, role, active FROM users ORDER BY FIELD(role,'admin','manager','sales'), username")->fetchAll(PDO::FETCH_ASSOC);
$subs    = $pdo->query("SELECT s.id, s.user_id, s.device, s.last_error, s.fail_count, u.username, u.role,
                               TIMESTAMPDIFF(SECOND, s.created_at, NOW()) AS age_created,
                               TIMESTAMPDIFF(SECOND, s.last_ok_at, NOW()) AS age_ok
                        FROM push_subscriptions s LEFT JOIN users u ON u.id = s.user_id ORDER BY s.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$log     = $pdo->query("SELECT *, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age FROM notify_log ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
$today   = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(delivered),0) d, COALESCE(SUM(failed),0) f FROM notify_log WHERE created_at >= CURDATE()")->fetch(PDO::FETCH_ASSOC);
$nIos    = count(array_filter($subs, fn($s) => preg_match('/iPhone|iPad/', (string)$s['device'])));
$nAnd    = count(array_filter($subs, fn($s) => preg_match('/Android/', (string)$s['device'])));
$withDev = count(array_unique(array_column($subs, 'user_id')));

$T = $lang === 'ar' ? [
    'title' => 'التحكم في الإشعارات', 'sub' => 'حدد من يستلم إشعار كل حدث على موبايله — آيفون وأندرويد',
    'perm' => 'الصلاحيات', 'mine' => 'إشعاراتي', 'dash' => 'الرئيسية',
    'k_dev' => 'جهاز مربوط', 'k_ios' => 'آيفون', 'k_and' => 'أندرويد', 'k_today' => 'إشعار اليوم', 'k_rate' => 'نسبة الوصول',
    'rules' => 'من يستلم ماذا', 'rulesNote' => 'فعّل الحدث ثم اختر الأدوار. «أشخاص» لإضافة شخص معين دائماً أو استبعاده.',
    'event' => 'الحدث', 'on' => 'تشغيل', 'r_admin' => 'مدير النظام', 'r_manager' => 'المدراء', 'r_sales' => 'المبيعات', 'people' => 'أشخاص',
    'save' => '💾 حفظ', 'saved' => '✓ تم الحفظ', 'preset' => 'اختيار سريع:', 'p_admin' => 'كل شيء للمدير فقط', 'p_mgr' => 'المدير + المدراء', 'p_all' => 'الكل يستلم الكل', 'p_none' => 'إيقاف الكل',
    'devices' => 'الأجهزة المربوطة', 'noDev' => 'لا توجد أجهزة مربوطة بعد — كل شخص يفتح «إشعاراتي» من موبايله ويضغط تفعيل',
    'user' => 'المستخدم', 'device' => 'الجهاز', 'added' => 'أضيف', 'lastOk' => 'آخر وصول', 'never' => 'لم يصل بعد', 'remove' => 'إزالة', 'test' => 'تجربة',
    'send' => 'إرسال إشعار تجربة', 'to' => 'إلى', 'everyone' => 'الكل', 'role' => 'دور', 'sendBtn' => '📨 إرسال الآن', 'res' => fn($s, $t) => "وصل إلى $s من $t جهاز",
    'opts' => 'الإعدادات', 'self' => 'أرسل الإشعار أيضاً للشخص الذي قام بالعملية', 'quiet' => 'ساعات الهدوء (لا إشعارات ليلاً — تبقى في السجل)', 'from' => 'من', 'until' => 'إلى', 'mlang' => 'لغة رسائل الإشعارات',
    'log' => 'سجل الإشعارات', 'noLog' => 'لم يُرسل أي إشعار بعد', 'rcp' => 'مستلم', 'dev' => 'جهاز', 'ok' => 'وصل', 'fail' => 'فشل',
    'always' => 'دائماً', 'never_' => 'أبداً', 'byRole' => 'حسب الدور', 'close' => 'تم', 'pickFor' => 'أشخاص لـ',
    'ago' => ['الآن', 'منذ %d دقيقة', 'منذ %d ساعة', 'أمس', 'منذ %d يوم'],
] : [
    'title' => 'Notification control', 'sub' => 'Choose who gets a phone notification for each event — iPhone and Android',
    'perm' => 'Permissions', 'mine' => 'My notifications', 'dash' => 'Dashboard',
    'k_dev' => 'linked devices', 'k_ios' => 'iPhone', 'k_and' => 'Android', 'k_today' => 'sent today', 'k_rate' => 'delivered',
    'rules' => 'Who gets what', 'rulesNote' => 'Switch an event on, then pick roles. "People" adds or excludes specific people.',
    'event' => 'Event', 'on' => 'On', 'r_admin' => 'Admin', 'r_manager' => 'Managers', 'r_sales' => 'Sales', 'people' => 'People',
    'save' => '💾 Save', 'saved' => '✓ Saved', 'preset' => 'Quick pick:', 'p_admin' => 'Everything to admin only', 'p_mgr' => 'Admin + managers', 'p_all' => 'Everyone gets everything', 'p_none' => 'Turn all off',
    'devices' => 'Linked devices', 'noDev' => 'No devices yet — each person opens "My notifications" on their phone and taps turn on',
    'user' => 'User', 'device' => 'Device', 'added' => 'Added', 'lastOk' => 'Last delivered', 'never' => 'Nothing yet', 'remove' => 'Remove', 'test' => 'Test',
    'send' => 'Send a test notification', 'to' => 'To', 'everyone' => 'Everyone', 'role' => 'Role', 'sendBtn' => '📨 Send now', 'res' => fn($s, $t) => "Delivered to $s of $t devices",
    'opts' => 'Settings', 'self' => 'Also notify the person who did the action', 'quiet' => 'Quiet hours (no notifications at night — kept in the log)', 'from' => 'From', 'until' => 'To', 'mlang' => 'Notification message language',
    'log' => 'Notification log', 'noLog' => 'Nothing sent yet', 'rcp' => 'recipients', 'dev' => 'devices', 'ok' => 'delivered', 'fail' => 'failed',
    'always' => 'Always', 'never_' => 'Never', 'byRole' => 'By role', 'close' => 'Done', 'pickFor' => 'People for',
    'ago' => ['just now', '%d min ago', '%d h ago', 'yesterday', '%d days ago'],
];
$ago = function ($s) use ($T): string {
    if ($s === null || $s === '') return '';
    $s = max(0, (int)$s);
    if ($s < 60) return $T['ago'][0];
    if ($s < 3600) return sprintf($T['ago'][1], intdiv($s, 60));
    if ($s < 86400) return sprintf($T['ago'][2], intdiv($s, 3600));
    $d = intdiv($s, 86400);
    return $d === 1 ? $T['ago'][3] : sprintf($T['ago'][4], $d);
};
$rate = ($today['d'] + $today['f']) > 0 ? round($today['d'] * 100 / ($today['d'] + $today['f'])) . '%' : '—';
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
<style>
.na-kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}
.na-k{padding:14px 16px;border-radius:18px;background:var(--card);border:1px solid var(--line);position:relative;overflow:hidden}
.na-k::before{content:'';position:absolute;inset-inline:0;top:0;height:3px;background:var(--kc,#22c55e)}
.na-k b{display:block;font-size:26px;font-weight:900;color:var(--kc,#22c55e);font-variant-numeric:tabular-nums}
.na-k span{font-size:12px;color:var(--mut);font-weight:700}
.na-presets{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:12px;font-size:12px;color:var(--mut);font-weight:700}
.na-presets button{height:30px;padding:0 11px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--txt);font:inherit;font-size:12px;font-weight:700;cursor:pointer}
.na-presets button:hover{border-color:rgba(168,85,247,.45)}
.na-tbl{width:100%;border-collapse:separate;border-spacing:0 6px}
.na-tbl th{font-size:11px;color:var(--mut);font-weight:800;text-align:center;padding:0 6px 4px;white-space:nowrap}
.na-tbl th:first-child{text-align:start}
.na-tbl td{background:rgba(255,255,255,.03);border-top:1px solid var(--line);border-bottom:1px solid var(--line);padding:9px 8px;text-align:center;vertical-align:middle}
.na-tbl td:first-child{text-align:start;border-inline-start:1px solid var(--line);border-start-start-radius:14px;border-end-start-radius:14px}
.na-tbl td:last-child{border-inline-end:1px solid var(--line);border-start-end-radius:14px;border-end-end-radius:14px}
.na-tbl tr.off td{opacity:.5}
.na-tbl tr.off td:nth-child(2){opacity:1}
.na-ev{display:flex;align-items:center;gap:10px;font-size:14px;font-weight:800}
.na-ev i{font-style:normal;width:34px;height:34px;border-radius:11px;display:grid;place-items:center;background:rgba(255,255,255,.05);font-size:17px;flex-shrink:0}
.tg{position:relative;display:inline-block;width:42px;height:24px;cursor:pointer}
.tg input{position:absolute;opacity:0;width:1px;height:1px}
.tg span{position:absolute;inset:0;border-radius:999px;background:rgba(255,255,255,.1);transition:.2s}
.tg span::after{content:'';position:absolute;top:3px;inset-inline-start:3px;width:18px;height:18px;border-radius:50%;background:#cbd5e1;transition:.2s}
.tg input:checked+span{background:linear-gradient(90deg,#22c55e,#9333ea)}
.tg input:checked+span::after{inset-inline-start:21px;background:#fff}
.chk{width:30px;height:30px;border-radius:10px;border:1.5px solid rgba(255,255,255,.14);background:transparent;cursor:pointer;display:inline-grid;place-items:center;color:transparent;font-weight:900;transition:.15s}
.chk.on{background:linear-gradient(135deg,#16a34a,#22c55e);border-color:transparent;color:#fff;box-shadow:0 4px 14px rgba(34,197,94,.3)}
.ppl{height:30px;padding:0 10px;border-radius:10px;border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--txt);font:inherit;font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap}
.ppl .p{color:#86efac}.ppl .m{color:#fca5a5}
.na-save{position:sticky;bottom:12px;z-index:5;display:flex;justify-content:flex-end;gap:10px;align-items:center;margin-top:12px}
.na-save .nf-msg{margin:0}
.na-devs{width:100%;border-collapse:collapse;font-size:13px}
.na-devs th{font-size:11px;color:var(--mut);text-align:start;padding:6px 8px;border-bottom:1px solid var(--line)}
.na-devs td{padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle}
.na-devs small{color:var(--mut)}
.na-devs .bad{color:#fca5a5;font-size:11px;display:block}
.role{font-size:10px;font-weight:800;padding:2px 8px;border-radius:999px;background:rgba(168,85,247,.15);color:#d8b4fe;margin-inline-start:4px}
.na-form{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.na-form select,.na-form input[type=time]{height:40px;border-radius:12px;border:1px solid var(--line);background:#0b1426;color:var(--txt);font:inherit;font-size:13px;padding:0 10px}
.na-opt{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:13px;font-weight:700;flex-wrap:wrap}
.na-opt:last-child{border-bottom:0}
.na-log .nf-item{cursor:default}
.na-log .stats{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}
.na-log .stats span{font-size:11px;font-weight:800;padding:2px 8px;border-radius:999px;background:rgba(255,255,255,.05)}
.na-log .stats .g{color:#86efac}.na-log .stats .r{color:#fca5a5}
.ov{position:fixed;inset:0;z-index:50;background:rgba(2,6,23,.78);backdrop-filter:blur(8px);display:none;align-items:center;justify-content:center;padding:16px}
.ov.on{display:flex}
.sheet{width:100%;max-width:440px;max-height:86vh;overflow-y:auto;border-radius:24px;padding:20px;background:linear-gradient(170deg,#121a33,#0a1122);border:1px solid rgba(168,85,247,.3)}
.sheet h3{font-size:16px;font-weight:900;margin-bottom:12px}
.pu{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:12px;border:1px solid var(--line);margin-bottom:6px}
.pu b{flex:1;font-size:14px}
.seg{display:inline-flex;border-radius:10px;overflow:hidden;border:1px solid var(--line)}
.seg button{border:0;background:transparent;color:var(--mut);font:inherit;font-size:11px;font-weight:800;padding:6px 9px;cursor:pointer}
.seg button.on[data-v="plus"]{background:rgba(34,197,94,.2);color:#86efac}
.seg button.on[data-v="minus"]{background:rgba(239,68,68,.2);color:#fca5a5}
.seg button.on[data-v=""]{background:rgba(255,255,255,.08);color:var(--txt)}
@media (max-width:900px){.na-kpis{grid-template-columns:repeat(3,1fr)}}
@media (max-width:760px){
  .na-kpis{grid-template-columns:repeat(2,1fr)}
  .na-tbl thead{display:none}
  .na-tbl,.na-tbl tbody,.na-tbl tr,.na-tbl td{display:block;width:100%}
  .na-tbl tr{margin-bottom:10px;border:1px solid var(--line);border-radius:16px;background:rgba(255,255,255,.03);padding:10px 12px}
  .na-tbl td{border:0!important;background:none;padding:4px 0;text-align:start;display:flex;align-items:center;justify-content:space-between;border-radius:0!important}
  .na-tbl td[data-l]::before{content:attr(data-l);font-size:12px;color:var(--mut);font-weight:700}
  .na-devs thead{display:none}.na-devs tr{display:grid;grid-template-columns:1fr auto;gap:4px;padding:10px 0;border-bottom:1px solid var(--line)}.na-devs td{padding:0;border:0}
}
</style>
</head>
<body>
<div class="nf-wrap">
    <header class="nf-head">
        <div><h1>🔔 <?= $T['title'] ?></h1><p><?= $T['sub'] ?></p></div>
        <div class="nf-nav">
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?><a class="nf-btn pur" href="permissions_admin.php?lang=<?= $lang ?>">🔐 <?= $T['perm'] ?></a><?php endif; ?>
            <a class="nf-btn" href="notifications.php?lang=<?= $lang ?>">📥 <?= $T['mine'] ?></a>
            <a class="nf-btn" href="dashboard.php?lang=<?= $lang ?>">🏠 <?= $T['dash'] ?></a>
            <a class="nf-btn ghost" href="?lang=<?= $lang === 'ar' ? 'en' : 'ar' ?>"><?= $lang === 'ar' ? 'English' : 'العربية' ?></a>
        </div>
    </header>

    <?php include __DIR__ . '/notify_device_card.php'; ?>

    <div class="na-kpis">
        <div class="na-k"><b><?= count($subs) ?></b><span>📲 <?= $T['k_dev'] ?></span></div>
        <div class="na-k" style="--kc:#e2e8f0"><b><?= $nIos ?></b><span>📱 <?= $T['k_ios'] ?></span></div>
        <div class="na-k" style="--kc:#4ade80"><b><?= $nAnd ?></b><span>🤖 <?= $T['k_and'] ?></span></div>
        <div class="na-k" style="--kc:#a855f7"><b><?= (int)$today['c'] ?></b><span>🔔 <?= $T['k_today'] ?></span></div>
        <div class="na-k" style="--kc:#22d3ee"><b><?= $rate ?></b><span>✅ <?= $T['k_rate'] ?></span></div>
    </div>

    <!-- who gets what -->
    <section class="nf-card">
        <h2>🎯 <?= $T['rules'] ?></h2>
        <p class="nf-note"><?= $T['rulesNote'] ?></p>
        <div class="na-presets"><?= $T['preset'] ?>
            <button type="button" data-preset="admin"><?= $T['p_admin'] ?></button>
            <button type="button" data-preset="mgr"><?= $T['p_mgr'] ?></button>
            <button type="button" data-preset="all"><?= $T['p_all'] ?></button>
            <button type="button" data-preset="none"><?= $T['p_none'] ?></button>
        </div>
        <table class="na-tbl" id="rulesTbl">
            <thead><tr><th><?= $T['event'] ?></th><th><?= $T['on'] ?></th><th>👑 <?= $T['r_admin'] ?></th><th>🧑‍💼 <?= $T['r_manager'] ?></th><th>🛒 <?= $T['r_sales'] ?></th><th>👤 <?= $T['people'] ?></th></tr></thead>
            <tbody>
            <?php foreach ($events as $ev => $meta): ?>
            <tr data-ev="<?= $ev ?>">
                <td><div class="na-ev"><i><?= $meta[2] ?></i><?= htmlspecialchars($lang === 'ar' ? $meta[0] : $meta[1]) ?></div></td>
                <td data-l="<?= $T['on'] ?>"><label class="tg"><input type="checkbox" class="evon"><span></span></label></td>
                <?php foreach ($roles as $r): ?>
                <td data-l="<?= $T['r_' . $r] ?>"><button type="button" class="chk" data-role="<?= $r ?>">✓</button></td>
                <?php endforeach; ?>
                <td data-l="<?= $T['people'] ?>"><button type="button" class="ppl">👤 <span class="p"></span> <span class="m"></span></button></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="na-save"><div class="nf-msg" id="rulesMsg"></div><button type="button" class="nf-btn grn" id="saveRules"><?= $T['save'] ?></button></div>
    </section>

    <div class="nf-grid">
        <!-- test -->
        <section class="nf-card">
            <h2>📨 <?= $T['send'] ?></h2>
            <div class="na-form">
                <span style="font-size:13px;font-weight:700;color:var(--mut)"><?= $T['to'] ?></span>
                <select id="tgt">
                    <option value="all:"><?= $T['everyone'] ?></option>
                    <?php foreach ($roles as $r): ?><option value="role:<?= $r ?>"><?= $T['role'] ?>: <?= $T['r_' . $r] ?></option><?php endforeach; ?>
                    <?php foreach ($users as $u): if (!(int)$u['active']) continue; ?><option value="user:<?= (int)$u['id'] ?>">👤 <?= htmlspecialchars($u['username']) ?></option><?php endforeach; ?>
                </select>
                <button type="button" class="nf-btn pur" id="sendTest"><?= $T['sendBtn'] ?></button>
            </div>
            <div class="nf-msg" id="testMsg"></div>
        </section>

        <!-- options -->
        <section class="nf-card">
            <h2>⚙️ <?= $T['opts'] ?></h2>
            <div class="na-opt"><span><?= $T['self'] ?></span><label class="tg"><input type="checkbox" id="oSelf" <?= $opts['self'] ? 'checked' : '' ?>><span></span></label></div>
            <div class="na-opt"><span><?= $T['quiet'] ?></span><label class="tg"><input type="checkbox" id="oQuiet" <?= $opts['quiet'] ? 'checked' : '' ?>><span></span></label>
                <div class="na-form" style="width:100%"><?= $T['from'] ?> <input type="time" id="oFrom" value="<?= $opts['quiet_from'] ?>"> <?= $T['until'] ?> <input type="time" id="oTo" value="<?= $opts['quiet_to'] ?>"></div></div>
            <div class="na-opt"><span><?= $T['mlang'] ?></span><div class="na-form"><select id="oLang"><option value="ar" <?= $opts['lang'] === 'ar' ? 'selected' : '' ?>>العربية</option><option value="en" <?= $opts['lang'] === 'en' ? 'selected' : '' ?>>English</option></select></div></div>
            <div class="na-save" style="position:static"><div class="nf-msg" id="optMsg"></div><button type="button" class="nf-btn grn" id="saveOpts"><?= $T['save'] ?></button></div>
        </section>
    </div>

    <!-- devices -->
    <section class="nf-card">
        <h2>📲 <?= $T['devices'] ?> <span class="nf-count"><?= count($subs) ?> · <?= $withDev ?> 👤</span></h2>
        <?php if (!$subs): ?><div class="nf-empty"><?= $T['noDev'] ?></div><?php else: ?>
        <table class="na-devs">
            <thead><tr><th><?= $T['user'] ?></th><th><?= $T['device'] ?></th><th><?= $T['added'] ?></th><th><?= $T['lastOk'] ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($subs as $s): ?>
            <tr data-sub="<?= (int)$s['id'] ?>">
                <td><b><?= htmlspecialchars((string)$s['username']) ?></b><span class="role"><?= htmlspecialchars((string)$s['role']) ?></span></td>
                <td><?= preg_match('/iPhone|iPad/', (string)$s['device']) ? '📱' : (preg_match('/Android/', (string)$s['device']) ? '🤖' : '💻') ?> <?= htmlspecialchars((string)$s['device']) ?>
                    <?php if ($s['last_error']): ?><span class="bad">⚠️ <?= htmlspecialchars(mb_substr((string)$s['last_error'], 0, 70)) ?></span><?php endif; ?></td>
                <td><small><?= htmlspecialchars($ago($s['age_created'])) ?></small></td>
                <td><small><?= $s['age_ok'] !== null ? htmlspecialchars($ago($s['age_ok'])) : $T['never'] ?></small></td>
                <td style="white-space:nowrap"><button type="button" class="nf-mini" data-test="<?= (int)$s['id'] ?>">📨 <?= $T['test'] ?></button> <button type="button" class="nf-mini red" data-rm="<?= (int)$s['id'] ?>"><?= $T['remove'] ?></button></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <!-- log -->
    <section class="nf-card na-log">
        <h2>🕘 <?= $T['log'] ?></h2>
        <?php if (!$log): ?><div class="nf-empty"><?= $T['noLog'] ?></div><?php endif; ?>
        <div class="nf-feed">
            <?php foreach ($log as $n): ?>
            <div class="nf-item">
                <div class="t"><?= htmlspecialchars($n['title']) ?></div>
                <div class="b"><?= nl2br(htmlspecialchars((string)$n['body'])) ?></div>
                <div class="stats"><span>👥 <?= (int)$n['recipients'] ?> <?= $T['rcp'] ?></span><span>📲 <?= (int)$n['devices'] ?> <?= $T['dev'] ?></span>
                    <span class="g">✓ <?= (int)$n['delivered'] ?> <?= $T['ok'] ?></span><?php if ((int)$n['failed']): ?><span class="r">✗ <?= (int)$n['failed'] ?> <?= $T['fail'] ?></span><?php endif; ?>
                    <span>🕐 <?= htmlspecialchars($ago($n['age'])) ?></span></div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<div class="ov" id="pplOv"><div class="sheet"><h3 id="pplH"></h3><div id="pplList"></div><button type="button" class="nf-btn grn" id="pplDone" style="width:100%;justify-content:center;margin-top:8px"><?= $T['close'] ?></button></div></div>

<script>
(function () {
    const CSRF = <?= json_encode($csrf) ?>, T = <?= json_encode(['saved' => $T['saved'], 'always' => $T['always'], 'never' => $T['never_'], 'byRole' => $T['byRole'], 'pickFor' => $T['pickFor'], 'fail' => $lang === 'ar' ? '✗ لم يتم: ' : '✗ Failed: '], JSON_UNESCAPED_UNICODE) ?>;
    const RES = <?= json_encode($lang) ?> === 'ar' ? (s, t) => 'وصل إلى ' + s + ' من ' + t + ' جهاز' : (s, t) => 'Delivered to ' + s + ' of ' + t + ' devices';
    const RULES = <?= json_encode($rules) ?>;
    const USERS = <?= json_encode(array_map(fn($u) => ['id' => (int)$u['id'], 'name' => $u['username'], 'role' => $u['role'], 'active' => (int)$u['active']], $users), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const EVN = <?= json_encode(array_map(fn($m) => $m[2] . ' ' . ($lang === 'ar' ? $m[0] : $m[1]), $events), JSON_UNESCAPED_UNICODE) ?>;
    const $ = id => document.getElementById(id);
    const post = d => fetch(location.pathname, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ csrf: CSRF }, d)) }).then(r => r.json());
    const msg = (id, t, ok) => { const m = $(id); m.textContent = t; m.className = 'nf-msg ' + (ok ? 'ok' : 'bad'); if (ok) setTimeout(() => { if (m.textContent === t) m.textContent = ''; }, 3000); };

    /* ── rules table ── */
    function paintRow(tr) {
        const r = RULES[tr.dataset.ev];
        tr.querySelector('.evon').checked = r.on;
        tr.classList.toggle('off', !r.on);
        tr.querySelectorAll('.chk').forEach(b => b.classList.toggle('on', r.roles.includes(b.dataset.role)));
        tr.querySelector('.ppl .p').textContent = r.plus.length ? '+' + r.plus.length : '';
        tr.querySelector('.ppl .m').textContent = r.minus.length ? '−' + r.minus.length : '';
    }
    const rows = [...document.querySelectorAll('#rulesTbl tr[data-ev]')];
    rows.forEach(tr => {
        paintRow(tr);
        const r = RULES[tr.dataset.ev];
        tr.querySelector('.evon').addEventListener('change', e => { r.on = e.target.checked; paintRow(tr); });
        tr.querySelectorAll('.chk').forEach(b => b.addEventListener('click', () => {
            const i = r.roles.indexOf(b.dataset.role); i >= 0 ? r.roles.splice(i, 1) : r.roles.push(b.dataset.role);
            if (r.roles.length && !r.on) r.on = true;
            paintRow(tr);
        }));
        tr.querySelector('.ppl').addEventListener('click', () => openPeople(tr.dataset.ev, tr));
    });
    document.querySelectorAll('[data-preset]').forEach(b => b.addEventListener('click', () => {
        const p = b.dataset.preset;
        Object.values(RULES).forEach(r => {
            r.on = p !== 'none';
            r.roles = p === 'admin' ? ['admin'] : p === 'mgr' ? ['admin', 'manager'] : p === 'all' ? ['admin', 'manager', 'sales'] : r.roles;
        });
        rows.forEach(paintRow);
    }));
    $('saveRules').addEventListener('click', async () => {
        const b = $('saveRules'); b.disabled = true;
        try { const r = await post({ action: 'save_rules', rules: RULES }); r.ok ? msg('rulesMsg', T.saved, true) : msg('rulesMsg', T.fail + r.error, false); }
        catch (e) { msg('rulesMsg', T.fail + e, false); }
        b.disabled = false;
    });

    /* ── people per event: always / by role / never ── */
    let pplEv = null, pplTr = null;
    function openPeople(ev, tr) {
        pplEv = ev; pplTr = tr;
        const r = RULES[ev];
        $('pplH').textContent = T.pickFor + ' ' + EVN[ev];
        $('pplList').innerHTML = USERS.filter(u => u.active).map(u => {
            const v = r.plus.includes(u.id) ? 'plus' : r.minus.includes(u.id) ? 'minus' : '';
            return '<div class="pu" data-u="' + u.id + '"><b>' + u.name.replace(/[<>&]/g, '') + ' <span class="role">' + u.role + '</span></b><div class="seg">' +
                '<button type="button" data-v="plus"' + (v === 'plus' ? ' class="on"' : '') + '>' + T.always + '</button>' +
                '<button type="button" data-v=""' + (v === '' ? ' class="on"' : '') + '>' + T.byRole + '</button>' +
                '<button type="button" data-v="minus"' + (v === 'minus' ? ' class="on"' : '') + '>' + T.never + '</button></div></div>';
        }).join('');
        $('pplList').querySelectorAll('.pu').forEach(row => row.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
            const id = +row.dataset.u;
            r.plus = r.plus.filter(x => x !== id); r.minus = r.minus.filter(x => x !== id);
            if (b.dataset.v === 'plus') { r.plus.push(id); r.on = true; }
            if (b.dataset.v === 'minus') r.minus.push(id);
            row.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
            paintRow(pplTr);
        })));
        $('pplOv').classList.add('on');
    }
    $('pplDone').addEventListener('click', () => $('pplOv').classList.remove('on'));
    $('pplOv').addEventListener('click', e => { if (e.target.id === 'pplOv') $('pplOv').classList.remove('on'); });

    /* ── options ── */
    $('saveOpts').addEventListener('click', async () => {
        const r = await post({ action: 'save_options', options: { self: $('oSelf').checked, quiet: $('oQuiet').checked, quiet_from: $('oFrom').value, quiet_to: $('oTo').value, lang: $('oLang').value } });
        r.ok ? msg('optMsg', T.saved, true) : msg('optMsg', T.fail + r.error, false);
    });

    /* ── tests & devices ── */
    $('sendTest').addEventListener('click', async () => {
        const b = $('sendTest'); b.disabled = true;
        const [type, value] = $('tgt').value.split(':');
        try { const r = await post({ action: 'test_target', type, value }); msg('testMsg', (r.total ? RES(r.sent, r.total) : RES(0, 0)) + (r.errors && r.errors.length ? ' — ' + r.errors[0] : ''), r.ok); }
        catch (e) { msg('testMsg', T.fail + e, false); }
        b.disabled = false;
    });
    document.querySelectorAll('[data-test]').forEach(b => b.addEventListener('click', async () => {
        b.disabled = true; const t = b.textContent;
        try { const r = await post({ action: 'test_sub', id: +b.dataset.test }); b.textContent = r.ok ? '✅' : '⚠️ ' + (r.code || ''); }
        catch (e) { b.textContent = '⚠️'; }
        setTimeout(() => { b.textContent = t; b.disabled = false; }, 3000);
    }));
    document.querySelectorAll('[data-rm]').forEach(b => b.addEventListener('click', async () => {
        const r = await post({ action: 'remove_sub', id: +b.dataset.rm }); if (r.ok) b.closest('tr').remove();
    }));
})();
</script>
</body>
</html>
