<?php
/*
 * must_read.php — the «رسالة إلزامية» screen (see must_read_helpers.php).
 *
 * While a mandatory message is waiting, auth.php sends the person here from
 * every page. One message at a time: read it to the end for the minimum time,
 * answer the question if there is one (or just pick, when it has no right
 * answer), press «قرأت وفهمت». Then the next one,
 * then the system opens.
 *
 *   GET  ?json=1                → {"must": how many are waiting}   (app.js)
 *   POST {action: 'hb', id, s}  seconds on screen
 *   POST {action: 'ok', id, c}  confirm (c = the chosen answer, in the order shown)
 */
require 'auth.php';
require 'config.php';
require_once __DIR__ . '/push_helpers.php';
require_once __DIR__ . '/must_read_helpers.php';

$lang = ($_GET['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
$ar   = $lang === 'ar';
$uid  = (int)$_SESSION['user_id'];
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
must_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($csrf, (string)($in['csrf'] ?? ''))) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }
    try {
        $id = (int)($in['id'] ?? 0);
        if (($in['action'] ?? '') === 'hb') { must_heartbeat($pdo, $id, $uid, (int)($in['s'] ?? 0)); echo json_encode(['ok' => true]); exit; }
        if (($in['action'] ?? '') === 'ok') {
            $r = must_confirm($pdo, $id, $uid, isset($in['c']) && $in['c'] !== null ? (int)$in['c'] : null);
            echo json_encode(['ok' => $r === 'ok', 'state' => $r, 'left' => count(must_pending($pdo, $uid))]); exit;
        }
    } catch (Throwable $e) { error_log('must_read: ' . $e->getMessage()); }
    echo json_encode(['ok' => false, 'error' => 'server']); exit;
}

$pending = must_pending($pdo, $uid);
if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['must' => count($pending)]);
    exit;
}
if (!$pending) { header('Location: dashboard.php?lang=' . $lang); exit; }

// the one asked for (from a phone notification) if it is waiting, otherwise the oldest
$m = $pending[0];
foreach ($pending as $p) if ((int)$p['log_id'] === (int)($_GET['id'] ?? 0)) { $m = $p; break; }
$id = (int)$m['log_id'];
must_opened($pdo, $id, $uid, (int)$m['ver']);
$k = $id . ':' . $uid;
$left = max(0, (int)$m['min_secs'] - (time() - (int)$_SESSION['must_t'][$k]));
$quiz = $m['quiz'] ? json_decode((string)$m['quiz'], true) : null;
$poll = must_is_poll($quiz);
$choices = [];
if ($quiz) {
    $keys = array_keys($quiz['choices']);
    $o = $_SESSION['must_order'][$k] ?? null;
    if ($poll) $o = $keys;                                                          // no right answer: the admin's order
    elseif (!is_array($o) || count($o) !== count($keys) || array_diff($keys, $o)) { $o = $keys; shuffle($o); }   // a right answer: a random order
    $_SESSION['must_order'][$k] = $o;
    foreach ($o as $orig) $choices[] = (string)$quiz['choices'][$orig];             // the right answer's place is never sent
}
$pos = array_search($m, $pending, true) + 1;
$total = count($pending);
$edited = (int)$m['ver'] > 1 && (int)$m['ok_ver'] > 0;      // confirmed an older version
$when = date('Y-m-d', strtotime($m['created_at'])) . ' · ' . date('h:i', strtotime($m['created_at'])) . ($ar ? (date('A', strtotime($m['created_at'])) === 'AM' ? ' ص' : ' م') : ' ' . date('A', strtotime($m['created_at'])));
$wrong = isset($_GET['wrong']);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $ar ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= $ar ? 'رسالة إلزامية' : 'Mandatory message' ?> — First 1 Car</title>
<?php include __DIR__ . '/pwa_head.php'; ?>
<?php include __DIR__ . '/notify_style.php'; ?>
<style>
body{min-height:100vh;background:radial-gradient(120% 60% at 50% 0%,#2a1050 0%,#0b1024 55%,#02040d 100%)}
.mr{max-width:640px;margin:0 auto;padding:calc(22px + env(safe-area-inset-top)) 16px calc(28px + env(safe-area-inset-bottom))}
.mr-top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px}
.mr-lock{display:inline-flex;align-items:center;gap:7px;padding:7px 13px;border-radius:999px;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.45);color:#fecaca;font-size:12.5px;font-weight:900}
.mr-steps{display:flex;gap:6px;align-items:center;font-size:12px;font-weight:900;color:#c4b5fd}
.mr-steps i{width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,.18)}
.mr-steps i.on{background:#a855f7;box-shadow:0 0 10px #a855f7}.mr-steps i.done{background:#22c55e}
.mr-card{position:relative;border-radius:26px;background:linear-gradient(170deg,rgba(30,27,75,.92),rgba(10,17,34,.96));border:1px solid rgba(168,85,247,.35);box-shadow:0 30px 70px rgba(0,0,0,.5),0 0 0 1px rgba(255,255,255,.03) inset;overflow:hidden;animation:mrIn .5s cubic-bezier(.22,1,.36,1)}
@keyframes mrIn{from{opacity:0;transform:translateY(24px) scale(.98)}}
.mr-prog{height:4px;background:rgba(255,255,255,.06)}.mr-prog i{display:block;height:100%;width:0;background:linear-gradient(90deg,#a855f7,#22c55e);transition:width .2s}
.mr-head{display:flex;gap:12px;align-items:center;padding:18px 18px 0}
.mr-ic{width:52px;height:52px;flex-shrink:0;border-radius:16px;display:grid;place-items:center;font-size:26px;background:linear-gradient(135deg,rgba(168,85,247,.35),rgba(239,68,68,.25));border:1px solid rgba(168,85,247,.5)}
.mr-from b{display:block;font-size:13px;color:#c4b5fd;font-weight:900}.mr-from small{display:block;font-size:12px;color:#94a3b8;font-weight:700;margin-top:2px}
.mr-ed{margin:14px 18px 0;padding:10px 13px;border-radius:14px;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.45);color:#fde68a;font-size:13px;font-weight:800;line-height:1.7}
.mr-title{font-size:clamp(20px,5.4vw,26px);font-weight:900;line-height:1.55;margin:14px 18px 10px;color:#fff}
.mr-body-w{position:relative}
.mr-body{max-height:min(52vh,460px);overflow:auto;padding:4px 18px 22px;font-size:17px;font-weight:600;line-height:2.05;color:#e2e8f0;white-space:pre-wrap;overflow-wrap:anywhere;-webkit-overflow-scrolling:touch}
.mr-body-w::after{content:'';position:absolute;left:0;right:0;bottom:0;height:46px;background:linear-gradient(transparent,rgba(10,17,34,.98));pointer-events:none;transition:opacity .3s}
.mr-body-w.end::after{opacity:0}
.mr-more{position:absolute;bottom:8px;left:50%;transform:translateX(-50%);z-index:2;padding:6px 14px;border-radius:999px;background:rgba(168,85,247,.9);color:#fff;font-size:12.5px;font-weight:900;box-shadow:0 8px 20px rgba(0,0,0,.45);animation:mrBob 1.6s ease-in-out infinite;pointer-events:none;white-space:nowrap}
@keyframes mrBob{50%{transform:translate(-50%,-4px)}}
.mr-body-w.end .mr-more{display:none}
.mr-checks{display:grid;grid-template-columns:repeat(auto-fit,minmax(92px,1fr));gap:8px;padding:14px 18px;border-top:1px solid rgba(255,255,255,.06)}
.mr-ck{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:10px 6px;border-radius:14px;font-size:12px;font-weight:800;text-align:center;line-height:1.4;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);color:#94a3b8;transition:all .3s;font-variant-numeric:tabular-nums}
.mr-ck i{font-style:normal;width:24px;height:24px;border-radius:50%;display:grid;place-items:center;font-size:12px;background:rgba(255,255,255,.08)}
.mr-ck.ok{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.45);color:#bbf7d0}.mr-ck.ok i{background:#22c55e;color:#052e16}
.mr-q{padding:4px 18px 18px;transition:opacity .3s}
.mr-q.off{opacity:.38;pointer-events:none;filter:grayscale(.4)}
.mr-q h3{font-size:15.5px;font-weight:900;margin:6px 0 10px;line-height:1.7}
.mr-q h3 small{display:block;font-size:12px;color:#94a3b8;font-weight:700}
.mr-c{display:flex;align-items:center;gap:12px;width:100%;margin-bottom:8px;padding:13px 14px;border-radius:15px;border:1.5px solid rgba(255,255,255,.12);background:rgba(255,255,255,.04);color:#f1f5f9;font:inherit;font-size:15px;font-weight:800;text-align:start;cursor:pointer;transition:border-color .15s,background .15s}
.mr-c .dot{width:22px;height:22px;flex-shrink:0;border-radius:50%;border:2px solid rgba(255,255,255,.3);display:grid;place-items:center}
.mr-c.on{border-color:#a855f7;background:rgba(168,85,247,.16)}.mr-c.on .dot{border-color:#a855f7;background:radial-gradient(circle,#a855f7 45%,transparent 50%)}
.mr-q.poll .mr-c.on{border-color:#38bdf8;background:rgba(56,189,248,.14)}.mr-q.poll .mr-c.on .dot{border-color:#38bdf8;background:radial-gradient(circle,#38bdf8 45%,transparent 50%)}
.mr-q.poll h3 small{color:#7dd3fc}
.mr-wrong{margin:0 18px 14px;padding:12px 14px;border-radius:14px;background:rgba(239,68,68,.14);border:1px solid rgba(239,68,68,.5);color:#fecaca;font-size:14px;font-weight:800;line-height:1.7;animation:mrShake .45s}
@keyframes mrShake{25%{transform:translateX(-6px)}50%{transform:translateX(6px)}75%{transform:translateX(-3px)}}
.mr-go{position:relative;display:flex;align-items:center;justify-content:center;gap:10px;width:calc(100% - 36px);margin:0 18px 18px;height:62px;border:0;border-radius:18px;font:inherit;font-size:18px;font-weight:900;cursor:pointer;color:#fff;background:linear-gradient(135deg,#16a34a,#22c55e);box-shadow:0 14px 34px rgba(34,197,94,.35);transition:transform .15s,filter .2s}
.mr-go:active{transform:scale(.98)}
.mr-go:disabled{cursor:not-allowed;background:rgba(255,255,255,.07);color:#94a3b8;box-shadow:none}
.mr-ring{width:30px;height:30px}.mr-ring circle{fill:none;stroke-width:3.5}.mr-ring .bg{stroke:rgba(255,255,255,.12)}.mr-ring .fg{stroke:#a855f7;stroke-linecap:round;transform:rotate(-90deg);transform-origin:50% 50%;transition:stroke-dashoffset 1s linear}
.mr-done{position:fixed;inset:0;z-index:50;display:none;align-items:center;justify-content:center;flex-direction:column;gap:14px;background:rgba(2,6,23,.88);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);text-align:center;padding:20px}
.mr-done.on{display:flex;animation:mrIn .35s ease}
.mr-done .ck{width:96px;height:96px;border-radius:50%;display:grid;place-items:center;background:radial-gradient(circle,rgba(34,197,94,.3),rgba(34,197,94,.06));box-shadow:0 0 0 12px rgba(34,197,94,.08)}
.mr-done svg{width:52px;height:52px}.mr-done path{fill:none;stroke:#22c55e;stroke-width:5;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:60;stroke-dashoffset:60;animation:mrDraw .6s .1s ease forwards}
@keyframes mrDraw{to{stroke-dashoffset:0}}
.mr-done b{font-size:21px;font-weight:900}.mr-done span{color:#94a3b8;font-size:14px;font-weight:700}
.mr-foot{text-align:center;margin-top:18px}.mr-foot a{color:#64748b;font-size:13px;font-weight:800;text-decoration:none}
@media (prefers-reduced-motion:reduce){.mr-card,.mr-more,.mr-wrong,.mr-done path{animation:none}.mr-done path{stroke-dashoffset:0}}
</style>
</head>
<body>
<div class="mr">
    <div class="mr-top">
        <span class="mr-lock">🔒 <?= $ar ? 'النظام مغلق حتى تقرأ الرسالة وتؤكدها' : 'The system is closed until you read and confirm' ?></span>
        <?php if ($total > 1): ?><span class="mr-steps"><?php for ($i = 1; $i <= $total; $i++): ?><i class="<?= $i === $pos ? 'on' : ($i < $pos ? 'done' : '') ?>"></i><?php endfor; ?> <?= $pos ?> / <?= $total ?></span><?php endif; ?>
    </div>

    <div class="mr-card">
        <div class="mr-prog"><i id="mrProg"></i></div>
        <div class="mr-head">
            <div class="mr-ic">📢</div>
            <div class="mr-from"><b><?= $ar ? 'رسالة إلزامية من الإدارة' : 'Mandatory message from management' ?><?= $m['actor'] ? ' · ' . htmlspecialchars((string)$m['actor']) : '' ?></b><small>🕐 <?= htmlspecialchars($when) ?></small></div>
        </div>
        <?php if ($edited): ?><div class="mr-ed">✏️ <?= $ar ? 'تم تعديل هذه الرسالة بعد أن أكدتها — اقرأها من جديد وأكّدها مرة أخرى.' : 'This message was edited after you confirmed it — read it again and confirm again.' ?></div><?php endif; ?>
        <h1 class="mr-title" dir="auto"><?= htmlspecialchars((string)$m['title']) ?></h1>
        <div class="mr-body-w" id="mrBodyW">
            <div class="mr-body" id="mrBody" dir="auto" tabindex="0"><?= htmlspecialchars((string)$m['body']) ?></div>
            <span class="mr-more">⬇ <?= $ar ? 'مرّر حتى نهاية الرسالة' : 'Scroll to the end' ?></span>
        </div>
        <div class="mr-checks">
            <span class="mr-ck" id="ckEnd"><i>✓</i><?= $ar ? 'قرأت حتى النهاية' : 'Read to the end' ?></span>
            <span class="mr-ck" id="ckTime"><i>✓</i><span id="ckTimeT"><?= $ar ? 'وقت القراءة' : 'Reading time' ?></span></span>
            <?php if ($quiz): ?><span class="mr-ck" id="ckQ"><i>✓</i><?= $poll ? ($ar ? 'اخترت إجابتك' : 'Picked your answer') : ($ar ? 'أجبت على السؤال' : 'Answered the question') ?></span><?php endif; ?>
        </div>
        <?php if ($wrong): ?><div class="mr-wrong">❌ <?= $ar ? 'الإجابة غير صحيحة. اقرأ الرسالة مرة أخرى بعناية ثم أجب من جديد.' : 'Wrong answer. Read the message again carefully, then answer again.' ?></div><?php endif; ?>
        <?php if ($quiz): ?>
        <div class="mr-q off<?= $poll ? ' poll' : '' ?>" id="mrQ">
            <?php if ($poll): ?>
            <h3>💬 <?= htmlspecialchars((string)$quiz['q']) ?><small><?= $ar ? 'لا توجد إجابة صحيحة أو خاطئة — اختر ما يناسبك، وتصل إجابتك إلى الإدارة. يُفتح بعد إكمال القراءة.' : 'There is no right or wrong answer — pick what fits you; management sees your answer. Opens once you have read it.' ?></small></h3>
            <?php else: ?>
            <h3>❓ <?= htmlspecialchars((string)$quiz['q']) ?><small><?= $ar ? 'سؤال للتأكد من قراءة الرسالة — يُفتح بعد إكمال القراءة' : 'To make sure the message was read — opens once you have read it' ?></small></h3>
            <?php endif; ?>
            <?php foreach ($choices as $ci => $ct): ?><button type="button" class="mr-c" data-c="<?= $ci ?>"><span class="dot"></span><span dir="auto"><?= htmlspecialchars($ct) ?></span></button><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <button type="button" class="mr-go" id="mrGo" disabled>
            <svg class="mr-ring" id="mrRing" viewBox="0 0 30 30"><circle class="bg" cx="15" cy="15" r="12"/><circle class="fg" id="mrRingFg" cx="15" cy="15" r="12" stroke-dasharray="75.4" stroke-dashoffset="0"/></svg>
            <span id="mrGoT"><?= $ar ? 'اقرأ الرسالة أولاً' : 'Read the message first' ?></span>
        </button>
    </div>
    <div class="mr-foot"><a href="logout.php">🚪 <?= $ar ? 'تسجيل الخروج' : 'Sign out' ?></a></div>
</div>

<div class="mr-done" id="mrDone"><div class="ck"><svg viewBox="0 0 50 50"><path d="M13 26 l8 8 l16 -18"/></svg></div><b><?= $poll ? ($ar ? 'تم التأكيد وإرسال إجابتك — شكراً لك' : 'Confirmed and your answer was sent — thank you') : ($ar ? 'تم التأكيد — شكراً لك' : 'Confirmed — thank you') ?></b><span id="mrDoneT"></span></div>

<script>
(function () {
    const AR = <?= json_encode($ar) ?>, ID = <?= $id ?>, CSRF = <?= json_encode($csrf) ?>, LANG = <?= json_encode($lang) ?>;
    const NEED = <?= (int)$m['min_secs'] ?>, HAS_Q = <?= $quiz ? 'true' : 'false' ?>, HAS_POLL = <?= $poll ? 'true' : 'false' ?>;
    let left = <?= (int)$left ?>, atEnd = false, choice = null, busy = false, onScreen = 0;
    const $ = id => document.getElementById(id);
    const body = $('mrBody'), wrap = $('mrBodyW'), go = $('mrGo'), goT = $('mrGoT'), fg = $('mrRingFg');
    const post = d => fetch('must_read.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(Object.assign({ csrf: CSRF, id: ID }, d)) }).then(r => r.json());
    const fmt = s => Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');

    function scrolled() {
        const max = body.scrollHeight - body.clientHeight;
        const p = max <= 4 ? 1 : Math.min(1, body.scrollTop / max);
        $('mrProg').style.width = Math.round(p * 100) + '%';
        if (p > .97 && !atEnd) { atEnd = true; wrap.classList.add('end'); paint(); }
    }
    body.addEventListener('scroll', scrolled, { passive: true });

    function paint() {
        $('ckEnd').classList.toggle('ok', atEnd);
        $('ckTime').classList.toggle('ok', left <= 0);
        $('ckTimeT').textContent = left > 0 ? (AR ? 'وقت القراءة ' : 'Reading time ') + fmt(left) : (AR ? 'وقت القراءة كافٍ' : 'Reading time done');
        const read = atEnd && left <= 0;
        if (HAS_Q) { $('mrQ').classList.toggle('off', !read); $('ckQ').classList.toggle('ok', choice !== null); }
        const ready = read && (!HAS_Q || choice !== null);
        go.disabled = !ready || busy;
        fg.style.strokeDashoffset = String(75.4 * (1 - Math.max(0, Math.min(1, (NEED - left) / NEED))));
        $('mrRing').style.display = ready ? 'none' : '';
        goT.textContent = ready ? (AR ? '✅ قرأت وفهمت' : '✅ I have read and understood')
            : !atEnd ? (AR ? 'مرّر حتى نهاية الرسالة' : 'Scroll to the end of the message')
            : left > 0 ? (AR ? 'تابع القراءة… ' : 'Keep reading… ') + fmt(left)
            : HAS_POLL ? (AR ? 'اختر إجابتك' : 'Pick your answer') : (AR ? 'أجب على السؤال' : 'Answer the question');
    }
    // the clock only runs while the message is on screen
    setInterval(() => { if (document.hidden) return; if (left > 0) left--; onScreen++; paint(); }, 1000);
    setInterval(() => { if (onScreen > 0) { const s = onScreen; onScreen = 0; post({ action: 'hb', s }).catch(() => {}); } }, 5000);

    document.querySelectorAll('.mr-c').forEach(b => b.addEventListener('click', () => {
        choice = +b.dataset.c;
        document.querySelectorAll('.mr-c').forEach(x => x.classList.toggle('on', x === b));
        paint();
    }));

    go.addEventListener('click', async () => {
        if (go.disabled) return;
        busy = true; paint(); goT.textContent = AR ? 'جاري التأكيد…' : 'Confirming…';
        let r = null;
        try { if (onScreen > 0) { await post({ action: 'hb', s: onScreen }); onScreen = 0; } r = await post({ action: 'ok', c: HAS_Q ? choice : null }); } catch (e) {}
        if (r && r.ok) {
            if (window.F1App) F1App.sound('ok');
            $('mrDoneT').textContent = r.left ? (AR ? 'بقيت ' + r.left + (r.left === 1 ? ' رسالة' : ' رسائل') + ' — جاري فتح التالية…' : r.left + ' more to read — opening the next…') : (AR ? 'جاري فتح النظام…' : 'Opening the system…');
            $('mrDone').classList.add('on');
            setTimeout(() => { location.href = r.left ? 'must_read.php?lang=' + LANG : 'dashboard.php?lang=' + LANG; }, 1400);
            return;
        }
        if (r && r.state === 'wrong') { if (window.F1App) F1App.sound('bad'); location.href = 'must_read.php?lang=' + LANG + '&id=' + ID + '&wrong=1'; return; }
        if (r && r.state === 'none') { location.href = 'dashboard.php?lang=' + LANG; return; }
        if (r && r.state === 'pick') { choice = null; document.querySelectorAll('.mr-c').forEach(x => x.classList.remove('on')); busy = false; paint(); return; }
        if (r && r.state === 'early') left = Math.max(left, 3);
        busy = false; paint();
        if (!r || r.state !== 'early') goT.textContent = AR ? 'تعذّر الحفظ — حاول مرة أخرى' : 'Could not save — try again';
    });

    requestAnimationFrame(() => { scrolled(); paint(); });
    window.addEventListener('resize', scrolled);
})();
</script>
</body>
</html>
