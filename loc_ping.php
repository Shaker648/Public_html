<?php
/*
 * loc_ping.php — «تأكيد التواجد»: the phone's side of a location request.
 *
 * While an admin's request is open, auth.php sends this person to this page
 * whatever they open: the system stays closed until the phone sends its
 * location (see check_auto.php).
 *
 *   page           the screen that asks for the location (and sends it by itself)
 *   GET  ?json=1   → {"ping": id}   (app.js asks quietly while the system is open)
 *   POST {id, lat, lng, acc}        the phone's answer   ({id, denied: true} when it refuses)
 */
require 'auth.php';
require 'config.php';
require_once __DIR__ . '/notify_smart.php';

$lang = ($_GET['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
$ar   = $lang === 'ar';
$uid  = (int)$_SESSION['user_id'];
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$ready = function_exists('loc_ping_due');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!$ready || !hash_equals($csrf, (string)($in['csrf'] ?? ''))) { echo json_encode(['ok' => false]); exit; }
    try {
        $denied = !empty($in['denied']) || !is_numeric($in['lat'] ?? null) || !is_numeric($in['lng'] ?? null);
        $r = loc_ping_answer($pdo, $uid, (int)($in['id'] ?? 0), $denied ? null : (float)$in['lat'], $denied ? null : (float)$in['lng'], (int)round((float)($in['acc'] ?? 0)));
        echo json_encode(['ok' => $r !== '' && $r !== 'denied', 'state' => $r]);
    } catch (Throwable $e) { error_log('loc_ping: ' . $e->getMessage()); echo json_encode(['ok' => false]); }
    exit;
}

$p = $ready ? loc_ping_due($pdo, $uid) : null;
if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ping' => $p ? (int)$p['id'] : 0, 'csrf' => $p ? $csrf : '']);
    exit;
}
if (!$p) { header('Location: dashboard.php?lang=' . $lang); exit; }   // nothing asked (or already answered)
$br = function_exists('push_branch_label') ? push_branch_label($pdo, (string)$p['branch'], $lang) : (string)$p['branch'];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $ar ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= $ar ? 'تأكيد التواجد' : 'Confirm your location' ?> — First 1 Car</title>
<?php include __DIR__ . '/pwa_head.php'; ?>
<?php include __DIR__ . '/notify_style.php'; ?>
<style>
body{min-height:100vh;background:radial-gradient(120% 70% at 50% 0%,#0b3a3a 0%,#0b1024 50%,#02040d 100%)}
.lp{max-width:520px;margin:0 auto;padding:calc(34px + env(safe-area-inset-top)) 18px 40px;text-align:center}
.lp-ic{position:relative;width:118px;height:118px;margin:0 auto 18px;border-radius:50%;display:grid;place-items:center;font-size:56px;
  background:radial-gradient(circle,rgba(45,212,191,.28),rgba(45,212,191,.05));border:1px solid rgba(45,212,191,.5)}
.lp-ic::after{content:'';position:absolute;inset:-10px;border-radius:50%;border:2px solid rgba(45,212,191,.55);animation:lpPulse 1.8s ease-out infinite}
@keyframes lpPulse{from{opacity:.9;transform:scale(.85)}to{opacity:0;transform:scale(1.3)}}
.lp h1{font-size:clamp(23px,6vw,30px);font-weight:900;margin:0 0 10px;line-height:1.5}
.lp p{color:#cbd5e1;font-size:15px;font-weight:700;line-height:1.9;margin:0 0 18px}
.lp-lock{display:inline-block;margin-bottom:18px;padding:8px 14px;border-radius:999px;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.45);color:#fecaca;font-size:13px;font-weight:900}
.lp-go{width:100%;height:60px;border:0;border-radius:18px;background:linear-gradient(135deg,#0d9488,#0ea5e9);color:#fff;font:inherit;font-size:18px;font-weight:900;cursor:pointer;box-shadow:0 14px 34px rgba(13,148,136,.35)}
.lp-go:disabled{opacity:.65;cursor:wait}
.lp-st{margin-top:16px;min-height:24px;font-size:14px;font-weight:800;color:#94a3b8;line-height:1.8}
.lp-st.bad{color:#fca5a5}.lp-st.ok{color:#86efac}
.lp-help{display:none;margin-top:16px;padding:14px;border-radius:16px;text-align:start;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.4);color:#fde68a;font-size:13.5px;font-weight:700;line-height:1.9}
.lp-help.on{display:block}.lp-help b{display:block;color:#fff;font-size:14.5px;margin-bottom:4px}
.lp-t{margin-top:14px;font-size:12.5px;font-weight:800;color:#64748b}
.lp-foot{margin-top:26px}.lp-foot a{color:#94a3b8;font-size:13.5px;font-weight:800;text-decoration:none}
</style>
</head>
<body>
<div class="lp">
    <div class="lp-ic">📍</div>
    <h1><?= $ar ? 'الإدارة طلبت تأكيد تواجدك في الفرع' : 'Management asked you to confirm your location' ?></h1>
    <span class="lp-lock">🔒 <?= $ar ? 'النظام مغلق حتى يُرسل موقعك' : 'The system is closed until your location is sent' ?></span>
    <p><?= $ar ? 'اضغط الزر وسيُرسل موقع هاتفك الحالي إلى الإدارة. بعدها يُفتح النظام فوراً.' : 'Press the button and your phone sends its location to management. The system opens right after.' ?><br>
       <span style="color:#94a3b8">📍 <?= $ar ? 'فرعك المسجَّل: ' : 'Your branch: ' ?><?= htmlspecialchars($br) ?></span></p>
    <button type="button" class="lp-go" id="lpGo">📍 <?= $ar ? 'إرسال موقعي الآن' : 'Send my location now' ?></button>
    <div class="lp-st" id="lpSt"></div>
    <div class="lp-help" id="lpHelp">
        <b>⚙️ <?= $ar ? 'الهاتف رفض إرسال الموقع' : 'The phone refused to share the location' ?></b>
        <?= $ar ? '• آيفون: الإعدادات ← الخصوصية والأمان ← خدمات الموقع ← تشغيل، ثم اختر المتصفح أو التطبيق ← «أثناء الاستخدام».<br>• أندرويد: اضغط 🔒 بجانب عنوان الموقع ← الأذونات ← الموقع ← سماح، وتأكد أن الموقع (GPS) مفعّل.<br>ثم اضغط «إرسال موقعي الآن» مرة أخرى.'
                : '• iPhone: Settings → Privacy & Security → Location Services → On, then the browser or app → "While Using".<br>• Android: tap 🔒 next to the address → Permissions → Location → Allow, and make sure GPS is on.<br>Then press "Send my location now" again.' ?>
    </div>
    <div class="lp-t" id="lpT"></div>
    <div class="lp-foot"><a href="logout.php">🚪 <?= $ar ? 'تسجيل الخروج' : 'Sign out' ?></a></div>
</div>
<script>
(function () {
    const AR = <?= json_encode($ar) ?>, ID = <?= (int)$p['id'] ?>, CSRF = <?= json_encode($csrf) ?>, LANG = <?= json_encode($lang) ?>;
    const go = document.getElementById('lpGo'), st = document.getElementById('lpSt'), help = document.getElementById('lpHelp');
    let busy = false;
    const say = (t, cls) => { st.textContent = t; st.className = 'lp-st' + (cls ? ' ' + cls : ''); };
    const send = body => fetch('loc_ping.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(Object.assign({ id: ID, csrf: CSRF }, body)) }).then(r => r.json());
    function done() { say(AR ? '✓ تم إرسال موقعك — جاري فتح النظام…' : '✓ Location sent — opening the system…', 'ok'); if (window.F1App) F1App.sound('ok'); setTimeout(() => { location.href = 'dashboard.php?lang=' + LANG; }, 900); }
    function fail(denied) {
        busy = false; go.disabled = false;
        if (denied) { say(AR ? '⛔ لم يُسمح بالوصول إلى الموقع' : '⛔ Location access was not allowed', 'bad'); help.classList.add('on'); send({ denied: true }).catch(() => {}); }
        else say(AR ? '⚠️ تعذّر تحديد الموقع — تأكد من تشغيل GPS واضغط مرة أخرى' : '⚠️ Could not get the location — make sure GPS is on and try again', 'bad');
    }
    function locate() {
        if (busy) return;
        if (!navigator.geolocation) return fail(true);
        busy = true; go.disabled = true; say(AR ? '📡 جاري تحديد موقعك…' : '📡 Getting your location…');
        const ok = pos => send({ lat: pos.coords.latitude, lng: pos.coords.longitude, acc: pos.coords.accuracy || 0 })
            .then(r => { if (r && r.ok) done(); else if (r && r.state === '') location.href = 'dashboard.php?lang=' + LANG; else fail(false); })
            .catch(() => fail(false));
        navigator.geolocation.getCurrentPosition(ok, err => {
            if (err && err.code === 1) return fail(true);
            navigator.geolocation.getCurrentPosition(ok, e2 => fail(e2 && e2.code === 1), { enableHighAccuracy: false, timeout: 20000, maximumAge: 0 });   // indoors: try once more with normal accuracy
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
    }
    go.addEventListener('click', locate);
    setTimeout(locate, 400);                                                        // try straight away
    // the admin may cancel the request: the system opens by itself
    setInterval(() => fetch('loc_ping.php?json=1', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } }).then(r => r.json()).then(r => { if (r && !r.ping) location.href = 'dashboard.php?lang=' + LANG; }).catch(() => {}), 8000);
    // time left before management is told there was no answer
    const t = document.getElementById('lpT'), end = Date.now() + <?= max(0, (int)$p['secs']) ?> * 1000;
    const tick = () => { const s = Math.round((end - Date.now()) / 1000);
        t.textContent = s > 0 ? (AR ? '⏳ متبقٍّ ' : '⏳ ') + Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0') + (AR ? ' — بعدها تُبلَّغ الإدارة أنك لم ترد' : ' left — then management is told you did not answer')
                          : (AR ? '⏰ انتهت المهلة وتم إبلاغ الإدارة — أرسل موقعك الآن ليُفتح النظام' : '⏰ Time is up and management was told — send your location now to open the system'); };
    tick(); setInterval(tick, 1000);
})();
</script>
</body>
</html>
