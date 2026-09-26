<?php
/*
 * lockdown.php — إيقاف النظام (reached from the permissions page).
 *
 *   • stop the system for anyone, with a reason or without one
 *   • choose: stop their clock-in (بصمة) at the moment of the stop — the reason
 *     is written into their attendance — or keep it running during the stop
 *   • every stop (manual, surprise stock check, transfers not confirmed) is
 *     told to every admin and the people chosen here; for automatic stops the
 *     admin decides about the clock-in (from here or right on the phone)
 *   • open the system again, and see the history of stops
 */
require 'auth.php';
require 'config.php';
require_once __DIR__ . '/notify_smart.php';

perm_require('page.lockdown');

$lang = ($_GET['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
$ar   = $lang === 'ar';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$me   = (string)$_SESSION['username'];
push_tables($pdo);

/* ─── actions ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($csrf, (string)($in['csrf'] ?? ''))) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }
    try {
        switch ($in['action'] ?? '') {
            case 'lock':
                $n = 0;
                foreach (array_unique(array_map('intval', (array)($in['uids'] ?? []))) as $u) {
                    if (user_lock_active($pdo, $u)) continue;
                    if (smart_lock_user($pdo, $u, 'manual', mb_substr(trim((string)($in['reason'] ?? '')), 0, 240), $me, [],
                                        ($in['basma'] ?? '') === 'stop' ? 'stop' : 'keep', array_map('intval', (array)($in['watch'] ?? [])))) $n++;
                }
                echo json_encode(['ok' => $n > 0, 'n' => $n]); exit;
            case 'unlock':
                smart_unlock($pdo, (int)($in['uid'] ?? 0), $me);
                echo json_encode(['ok' => true]); exit;
            case 'basma':
                echo json_encode(['ok' => lock_basma_decide($pdo, (int)($in['lock'] ?? 0), (string)($in['how'] ?? ''), $me)]); exit;
            case 'rules':
                push_setting_set($pdo, 'lock_rules', json_encode([
                    'watchers'   => array_values(array_unique(array_map('intval', (array)($in['watchers'] ?? [])))),
                    'auto_basma' => in_array($in['auto_basma'] ?? '', ['ask', 'stop', 'keep'], true) ? $in['auto_basma'] : 'ask',
                ]), $me);
                echo json_encode(['ok' => true]); exit;
        }
    } catch (Throwable $e) {
        error_log('lockdown: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'server']); exit;
    }
    echo json_encode(['ok' => false, 'error' => 'unknown']); exit;
}

/* ─── page data ─── */
smart_duty_scan($pdo); smart_check_scan($pdo);
$rules  = lock_rules($pdo);
$people = $pdo->query("SELECT id, username, role FROM users WHERE active = 1 ORDER BY FIELD(role,'admin','manager','sales'), username")->fetchAll(PDO::FETCH_ASSOC);
$here = [];
try { foreach ($pdo->query("SELECT user_id, branch_name FROM attendance_logs WHERE status = 'active'") as $r) $here[(int)$r['user_id']] = $r['branch_name']; } catch (Throwable $e) {}
$active = $pdo->query("SELECT l.*, u.username, TIMESTAMPDIFF(SECOND, l.locked_at, NOW()) AS age FROM user_locks l JOIN users u ON u.id = l.user_id
                       WHERE l.unlocked_at IS NULL ORDER BY l.locked_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$lockedIds = array_map('intval', array_column($active, 'user_id'));
$history = $pdo->query("SELECT l.*, u.username FROM user_locks l JOIN users u ON u.id = l.user_id WHERE l.unlocked_at IS NOT NULL ORDER BY l.locked_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

$kindLbl = $ar ? ['manual' => '✋ يدوي', 'check' => '📋 جرد مفاجئ', 'transfer' => '🚚 استلام'] : ['manual' => '✋ Manual', 'check' => '📋 Stock check', 'transfer' => '🚚 Receiving'];
$basmaLbl = $ar ? ['stopped' => '⏹ أُوقفت البصمة', 'running' => '▶ البصمة مستمرة', 'pending' => '❓ بانتظار قرارك', 'none' => '— لم يكن حاضراً']
                : ['stopped' => '⏹ Clock-in stopped', 'running' => '▶ Clock-in running', 'pending' => '❓ Waiting for you', 'none' => '— not clocked in'];
$ago = function ($s) use ($ar): string {
    $s = max(0, (int)$s);
    if ($s < 60) return $ar ? 'الآن' : 'just now';
    if ($s < 3600) { $m = intdiv($s, 60); return $ar ? 'منذ ' . push_ar_mins($m) : $m . ' min ago'; }
    if ($s < 86400) { $h = intdiv($s, 3600); return $ar ? 'منذ ' . $h . ' ساعة' : $h . ' h ago'; }
    $d = intdiv($s, 86400); return $ar ? 'منذ ' . push_ar_days($d) : $d . ' days ago';
};
$fmt = fn($dt) => $dt ? date('Y-m-d', strtotime($dt)) . ' ' . smart_time_label(strtotime($dt), $lang) : '';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $ar ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= $ar ? 'إيقاف النظام' : 'Stop the system' ?> — First 1 Car</title>
<?php include __DIR__ . '/pwa_head.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<?php include __DIR__ . '/notify_style.php'; ?>
<style>
.ld-l{display:block;font-size:12.5px;font-weight:800;color:var(--mut);margin:14px 0 8px}
.ld-l:first-child{margin-top:0}
.ld-ppl{display:flex;flex-wrap:wrap;gap:6px}
.ld-p{height:38px;padding:0 13px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--txt);font:inherit;font-size:13px;font-weight:800;cursor:pointer}
.ld-p small{font-size:10.5px;color:#86efac;margin-inline-start:4px}
.ld-p.on{background:linear-gradient(90deg,#b91c1c,#7f1d1d);border-color:transparent;color:#fff}
.ld-p.w.on{background:linear-gradient(90deg,#f59e0b,#eab308);color:#1c1917}
.ld-p:disabled{opacity:.45;cursor:default}
.ld-in{width:100%;min-height:80px;border-radius:14px;border:1px solid var(--line);background:#0b1426;color:var(--txt);font:inherit;font-size:14px;padding:10px 12px;resize:vertical}
.ld-opts{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.ld-o{display:flex;gap:10px;align-items:flex-start;padding:12px;border-radius:14px;border:1px solid var(--line);background:rgba(255,255,255,.03);cursor:pointer}
.ld-o input{margin-top:3px;accent-color:#ef4444}
.ld-o b{display:block;font-size:14px}.ld-o small{display:block;font-size:12px;color:var(--mut);font-weight:700;margin-top:2px;line-height:1.6}
.ld-o.on{border-color:rgba(239,68,68,.6);background:rgba(239,68,68,.08)}
.ld-fix{font-size:12px;color:#fde68a;font-weight:800;margin-top:6px}
.ld-go{margin-top:16px;width:100%;height:54px;border:0;border-radius:16px;background:linear-gradient(90deg,#b91c1c,#7f1d1d);color:#fff;font:inherit;font-size:17px;font-weight:900;cursor:pointer;box-shadow:0 12px 30px rgba(185,28,28,.35)}
.ld-row{display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:12px 14px;border-radius:14px;border:1px solid rgba(239,68,68,.4);background:rgba(239,68,68,.06);margin-bottom:8px}
.ld-row .i{flex:1;min-width:220px}.ld-row b{font-size:15px}.ld-row small{display:block;font-size:12.5px;color:var(--mut);font-weight:700;margin-top:3px}
.ld-row small.r{color:#fecaca}
.ld-k{font-size:11.5px;font-weight:900;padding:3px 9px;border-radius:999px;background:rgba(255,255,255,.07);margin-inline-start:6px}
.ld-b{font-size:12px;font-weight:900;padding:4px 10px;border-radius:999px;background:rgba(255,255,255,.06)}
.ld-b.pending{background:rgba(250,204,21,.15);color:#fde68a}.ld-b.stopped{background:rgba(239,68,68,.15);color:#fca5a5}.ld-b.running{background:rgba(34,197,94,.15);color:#86efac}
.ld-acts{display:flex;flex-wrap:wrap;gap:6px}
.ld-acts button{height:36px;padding:0 12px;border-radius:10px;border:1px solid var(--line);background:rgba(255,255,255,.05);color:var(--txt);font:inherit;font-size:12.5px;font-weight:900;cursor:pointer}
.ld-acts .un{background:linear-gradient(90deg,#16a34a,#22c55e);border:0;color:#fff}
.ld-acts .st{border-color:rgba(239,68,68,.5);color:#fca5a5}.ld-acts .kp{border-color:rgba(34,197,94,.5);color:#86efac}
.ld-h{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:9px 12px;border-bottom:1px solid rgba(255,255,255,.05);font-size:13px}
.ld-h .i{flex:1;min-width:200px}.ld-h small{display:block;color:var(--mut);font-weight:700}
.ld-opts.three{grid-template-columns:repeat(3,1fr)}
@media (max-width:640px){.ld-opts,.ld-opts.three{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="nf-wrap">
    <header class="nf-head">
        <div><h1>🔒 <?= $ar ? 'إيقاف النظام' : 'Stop the system' ?></h1>
            <p><?= $ar ? 'إيقاف النظام عن أي موظف، والتحكم في بصمته أثناء الإيقاف' : 'Stop the system for anyone and control their clock-in during the stop' ?></p></div>
        <div class="nf-nav">
            <a class="nf-btn pur" href="permissions_admin.php?lang=<?= $lang ?>">🔐 <?= $ar ? 'الصلاحيات' : 'Permissions' ?></a>
            <a class="nf-btn" href="dashboard.php?lang=<?= $lang ?>">🏠 <?= $ar ? 'الرئيسية' : 'Dashboard' ?></a>
            <a class="nf-btn ghost" href="?lang=<?= $ar ? 'en' : 'ar' ?>"><?= $ar ? 'English' : 'العربية' ?></a>
        </div>
    </header>

    <!-- stopped right now -->
    <section class="nf-card">
        <h2>⛔ <?= $ar ? 'موقوف عنهم النظام الآن' : 'Stopped right now' ?> <span class="nf-count"><?= count($active) ?></span></h2>
        <?php if (!$active): ?><div class="nf-empty"><?= $ar ? 'لا يوجد أحد موقوف عنه النظام ✅' : 'Nobody is stopped ✅' ?></div><?php endif; ?>
        <?php foreach ($active as $l): $bs = $l['basma'] ?: 'none'; ?>
        <div class="ld-row">
            <div class="i"><b>🔒 <?= htmlspecialchars($l['username']) ?></b><span class="ld-k"><?= $kindLbl[$l['kind']] ?? $l['kind'] ?></span>
                <small class="r">📝 <?= htmlspecialchars((string)$l['reason'] !== '' ? $l['reason'] : ($l['kind'] === 'manual' ? ($ar ? 'بدون سبب مكتوب' : 'no reason given') : trim(strstr($kindLbl[$l['kind']] ?? '', ' ')))) ?></small>
                <small>🕐 <?= htmlspecialchars($fmt($l['locked_at'])) ?> · <?= htmlspecialchars($ago($l['age'])) ?><?= $l['locked_by'] ? ' · 👤 ' . htmlspecialchars($l['locked_by']) : '' ?><?= $l['done_at'] ? ' · ' . ($ar ? '✅ أنجز المطلوب' : '✅ finished what was asked') : '' ?></small></div>
            <span class="ld-b <?= $bs ?>"><?= $basmaLbl[$bs] ?? $bs ?></span>
            <div class="ld-acts">
                <?php if (in_array($bs, ['pending', 'running'], true)): ?><button type="button" class="st" data-basma="stop" data-lock="<?= (int)$l['id'] ?>">⏹ <?= $ar ? 'إيقاف البصمة من وقت الإيقاف' : 'Stop clock-in at the stop' ?></button><?php endif; ?>
                <?php if ($bs === 'pending'): ?><button type="button" class="kp" data-basma="keep" data-lock="<?= (int)$l['id'] ?>">▶ <?= $ar ? 'استمرار البصمة' : 'Keep it running' ?></button><?php endif; ?>
                <button type="button" class="un" data-unlock="<?= (int)$l['user_id'] ?>" data-name="<?= htmlspecialchars($l['username']) ?>">🔓 <?= $ar ? 'فتح النظام' : 'Unlock' ?></button>
            </div>
        </div>
        <?php endforeach; ?>
    </section>

    <!-- new stop -->
    <section class="nf-card">
        <h2>✋ <?= $ar ? 'إيقاف يدوي' : 'Stop manually' ?></h2>
        <label class="ld-l"><?= $ar ? 'الموظف' : 'Who' ?></label>
        <div class="ld-ppl">
            <?php foreach ($people as $p): if ($p['role'] === 'admin') continue; $pid = (int)$p['id']; $lk = in_array($pid, $lockedIds, true); ?>
            <button type="button" class="ld-p pick" data-u="<?= $pid ?>" <?= $lk ? 'disabled' : '' ?>><?= htmlspecialchars($p['username']) ?><small><?= $lk ? ($ar ? '🔒 موقوف' : '🔒 stopped') : (isset($here[$pid]) ? ($ar ? '🟢 حاضر' : '🟢 in') : '') ?></small></button>
            <?php endforeach; ?>
        </div>
        <label class="ld-l"><?= $ar ? 'السبب (اختياري — يظهر له ويُكتب في سجل البصمة إذا أوقفتها)' : 'Reason (optional — shown to them and written into attendance if you stop the clock-in)' ?></label>
        <textarea class="ld-in" id="ldReason" maxlength="240" placeholder="<?= $ar ? 'مثال: مراجعة عهدة الفرع — برجاء التواصل مع المدير' : 'e.g. branch audit — please contact your manager' ?>"></textarea>
        <label class="ld-l"><?= $ar ? 'البصمة أثناء الإيقاف' : 'Clock-in during the stop' ?></label>
        <div class="ld-opts">
            <label class="ld-o on"><input type="radio" name="ldB" value="stop" checked><span><b>⏹ <?= $ar ? 'إيقاف البصمة الآن' : 'Stop the clock-in now' ?></b><small><?= $ar ? 'يُسجَّل انصرافه لحظة الإيقاف، ويُكتب «إيقاف مفاجئ من الإدارة» والسبب في سجل البصمة' : 'Clocked out at the moment of the stop; "sudden stop by management" and the reason are written into attendance' ?></small></span></label>
            <label class="ld-o"><input type="radio" name="ldB" value="keep"><span><b>▶ <?= $ar ? 'استمرار البصمة' : 'Keep it running' ?></b><small><?= $ar ? 'تستمر ساعات حضوره أثناء الإيقاف، ولا يستطيع تسجيل الانصراف حتى يُفتح النظام' : 'Hours keep counting during the stop; they cannot clock out until unlocked' ?></small></span></label>
        </div>
        <label class="ld-l">🔔 <?= $ar ? 'من يستلم الإشعار؟' : 'Who is notified?' ?></label>
        <div class="ld-ppl">
            <?php foreach ($people as $p): if ($p['role'] === 'admin') continue; ?>
            <button type="button" class="ld-p w<?= in_array((int)$p['id'], $rules['watchers'], true) ? ' on' : '' ?>" data-u="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['username']) ?></button>
            <?php endforeach; ?>
        </div>
        <div class="ld-fix">👑 <?= $ar ? 'كل الأدمن يستلمون الإشعار دائماً على كل أجهزتهم — والموظف نفسه أيضاً' : 'Every admin always gets it on all their devices — and the person too' ?></div>
        <button type="button" class="ld-go" id="ldGo">🔒 <?= $ar ? 'إيقاف النظام الآن' : 'Stop the system now' ?></button>
        <div class="nf-msg" id="ldMsg"></div>
    </section>

    <!-- automatic stops -->
    <section class="nf-card">
        <h2>⚙️ <?= $ar ? 'الإيقاف التلقائي (الجرد المفاجئ والاستلام)' : 'Automatic stops (stock check & receiving)' ?></h2>
        <label class="ld-l"><?= $ar ? 'البصمة عند الإيقاف التلقائي' : 'Clock-in on an automatic stop' ?></label>
        <div class="ld-opts three">
            <?php foreach (($ar ? ['ask' => ['❓ اسألني', 'تستمر البصمة حتى تختار — يصلك إشعار فيه زرّا «إيقاف البصمة» و«استمرار البصمة»'],
                                   'stop' => ['⏹ إيقافها تلقائياً', 'يُسجَّل انصرافه لحظة الإيقاف، ويُكتب السبب (الجرد / عدم تأكيد الاستلام) في سجل البصمة'],
                                   'keep' => ['▶ استمرارها دائماً', 'تستمر ساعات حضوره أثناء الإيقاف']]
                                : ['ask' => ['❓ Ask me', 'Keeps running until you choose — the notification has "stop" and "keep" buttons'],
                                   'stop' => ['⏹ Stop automatically', 'Clocked out at the moment of the stop, the reason written into attendance'],
                                   'keep' => ['▶ Always keep it', 'Hours keep counting during the stop']]) as $k => [$t, $s]): ?>
            <label class="ld-o<?= $rules['auto_basma'] === $k ? ' on' : '' ?>"><input type="radio" name="ldA" value="<?= $k ?>" <?= $rules['auto_basma'] === $k ? 'checked' : '' ?>><span><b><?= $t ?></b><small><?= $s ?></small></span></label>
            <?php endforeach; ?>
        </div>
        <label class="ld-l">🔔 <?= $ar ? 'من يستلم إشعارات كل إيقاف (يدوي أو تلقائي) بجانب الأدمن؟' : 'Who else gets every stop (manual or automatic) besides the admins?' ?></label>
        <div class="ld-ppl">
            <?php foreach ($people as $p): if ($p['role'] === 'admin') continue; ?>
            <button type="button" class="ld-p w rw<?= in_array((int)$p['id'], $rules['watchers'], true) ? ' on' : '' ?>" data-u="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['username']) ?></button>
            <?php endforeach; ?>
        </div>
        <div class="na-save" style="position:static;display:flex;gap:10px;align-items:center;justify-content:flex-end;margin-top:12px"><div class="nf-msg" id="rMsg"></div><button type="button" class="nf-btn grn" id="rSave">💾 <?= $ar ? 'حفظ' : 'Save' ?></button></div>
    </section>

    <!-- history -->
    <section class="nf-card">
        <h2>🗂️ <?= $ar ? 'سجل الإيقافات' : 'Stop history' ?></h2>
        <?php if (!$history): ?><div class="nf-empty"><?= $ar ? 'لا يوجد سجل بعد' : 'Nothing yet' ?></div><?php endif; ?>
        <?php foreach ($history as $h): ?>
        <div class="ld-h">
            <div class="i"><b><?= htmlspecialchars($h['username']) ?></b> <span class="ld-k"><?= $kindLbl[$h['kind']] ?? $h['kind'] ?></span>
                <small>📝 <?= htmlspecialchars((string)$h['reason'] !== '' ? $h['reason'] : ($ar ? 'بدون سبب' : 'no reason')) ?></small>
                <small>🔒 <?= htmlspecialchars($fmt($h['locked_at'])) ?> → 🔓 <?= htmlspecialchars($fmt($h['unlocked_at'])) ?> · <?= htmlspecialchars((string)$h['unlocked_by']) ?></small></div>
            <span class="ld-b <?= $h['basma'] ?: 'none' ?>"><?= $basmaLbl[$h['basma'] ?: 'none'] ?? '' ?></span>
        </div>
        <?php endforeach; ?>
    </section>
</div>
<script>
(function () {
    const CSRF = <?= json_encode($csrf) ?>, AR = <?= json_encode($ar) ?>;
    const $ = id => document.getElementById(id);
    const post = d => fetch(location.pathname, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ csrf: CSRF }, d)) }).then(r => r.json());
    const msg = (id, t, ok) => { const m = $(id); m.textContent = t; m.className = 'nf-msg ' + (ok ? 'ok' : 'bad'); };
    document.querySelectorAll('.ld-p:not(:disabled)').forEach(b => b.addEventListener('click', () => b.classList.toggle('on')));
    document.querySelectorAll('.ld-o input').forEach(r => r.addEventListener('change', () => document.querySelectorAll('input[name="' + r.name + '"]').forEach(x => x.closest('.ld-o').classList.toggle('on', x.checked))));
    $('ldGo').addEventListener('click', async () => {
        const uids = [...document.querySelectorAll('.ld-p.pick.on')].map(b => +b.dataset.u);
        if (!uids.length) { msg('ldMsg', AR ? 'اختر الموظف أولاً' : 'Pick who first', false); return; }
        const basma = document.querySelector('input[name=ldB]:checked').value;
        if (!confirm(AR ? 'إيقاف النظام عن ' + uids.length + ' موظف الآن؟' : 'Stop the system for ' + uids.length + ' now?')) return;
        $('ldGo').disabled = true;
        const r = await post({ action: 'lock', uids, reason: $('ldReason').value, basma, watch: [...document.querySelectorAll('.ld-p.w:not(.rw).on')].map(b => +b.dataset.u) });
        if (r.ok) location.reload(); else { $('ldGo').disabled = false; msg('ldMsg', '✗ ' + (r.error || ''), false); }
    });
    document.querySelectorAll('[data-unlock]').forEach(b => b.addEventListener('click', async () => {
        if (!confirm((AR ? 'فتح النظام لـ ' : 'Unlock ') + b.dataset.name + '؟')) return;
        b.disabled = true; const r = await post({ action: 'unlock', uid: +b.dataset.unlock }); if (r.ok) location.reload(); else b.disabled = false;
    }));
    document.querySelectorAll('[data-basma]').forEach(b => b.addEventListener('click', async () => {
        b.disabled = true; const r = await post({ action: 'basma', lock: +b.dataset.lock, how: b.dataset.basma }); if (r.ok) location.reload(); else b.disabled = false;
    }));
    $('rSave').addEventListener('click', async () => {
        const r = await post({ action: 'rules', auto_basma: document.querySelector('input[name=ldA]:checked').value, watchers: [...document.querySelectorAll('.ld-p.rw.on')].map(b => +b.dataset.u) });
        r.ok ? msg('rMsg', AR ? '✓ تم الحفظ' : '✓ Saved', true) : msg('rMsg', '✗', false);
    });
})();
</script>
</body>
</html>
