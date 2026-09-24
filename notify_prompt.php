<?php
/*
 * notify_prompt.php — the "turn on notifications" card for everyone
 * (admin, managers, sales). Included at the bottom of dashboard.php.
 *
 *  • Slides in from the left right after login, then again every 20 minutes
 *    while this phone has not allowed notifications.
 *  • Once this phone is subscribed it never shows again (it checks the real
 *    subscription, so a removed phone gets asked again).
 *  • iPhone in Safari → a short "Add to Home Screen" guide (Apple requires it).
 *  • A small bell tab stays on the left edge so it can be opened any time.
 * Needs $lang and $csrfToken from the page.
 */
$npFresh = !empty($_SESSION['np_fresh']);   // set by login.php: show right away after signing in
unset($_SESSION['np_fresh']);
$NP = $lang === 'ar' ? [
    'title' => 'فعّل الإشعارات على موبايلك',
    'text'  => 'ليصلك كل جديد فوراً مثل واتساب — البيع، النقل، الحجز والمزيد.',
    'on'    => '🔔 تفعيل الآن',
    'later' => 'لاحقاً',
    'done'  => '✅ تم التفعيل — وصلك إشعار تجربة',
    'ios_t' => 'على الآيفون: خطوة واحدة أولاً',
    'ios_1' => 'اضغط زر <b>المشاركة</b> ⬆️ في Safari',
    'ios_2' => 'اختر <b>«إضافة إلى الشاشة الرئيسية»</b>',
    'ios_3' => 'افتح <b>First 1 Car</b> من الشاشة الرئيسية وسجّل الدخول — ستظهر لك هذه الرسالة لتفعيلها',
    'den_t' => 'الإشعارات مرفوضة على هذا الجهاز',
    'den_i' => 'فعّلها من <b>الإعدادات ← الإشعارات ← First 1 Car ← السماح</b>، ثم افتح التطبيق من جديد.',
    'den_a' => 'اضغط 🔒 بجانب عنوان الموقع ← <b>الأذونات ← الإشعارات ← سماح</b>، ثم أعد تحميل الصفحة.',
    'fail'  => 'لم يتم التفعيل: ',
    'tab'   => 'الإشعارات',
    'errs'  => ['denied' => 'تم رفض الإذن', 'dismissed' => 'لم يتم اختيار «السماح»', 'server_key' => 'تعذّر الاتصال بالخادم', 'csrf' => 'حدّث الصفحة وحاول مرة أخرى'],
] : [
    'title' => 'Turn on notifications on your phone',
    'text'  => 'Get every update instantly, like WhatsApp — sales, transfers, reservations and more.',
    'on'    => '🔔 Turn on now',
    'later' => 'Later',
    'done'  => '✅ Done — a test notification is on its way',
    'ios_t' => 'On iPhone: one step first',
    'ios_1' => 'Tap <b>Share</b> ⬆️ in Safari',
    'ios_2' => 'Choose <b>"Add to Home Screen"</b>',
    'ios_3' => 'Open <b>First 1 Car</b> from the Home Screen and sign in — this card will appear to turn it on',
    'den_t' => 'Notifications are blocked on this device',
    'den_i' => 'Allow them in <b>Settings → Notifications → First 1 Car</b>, then reopen the app.',
    'den_a' => 'Tap 🔒 next to the address → <b>Permissions → Notifications → Allow</b>, then reload.',
    'fail'  => 'Could not turn on: ',
    'tab'   => 'Notifications',
    'errs'  => ['denied' => 'Permission was denied', 'dismissed' => '"Allow" was not chosen', 'server_key' => 'Could not reach the server', 'csrf' => 'Reload the page and try again'],
];
?>
<style>
.np-card{position:fixed;left:16px;bottom:calc(18px + env(safe-area-inset-bottom));z-index:9000;width:min(360px,calc(100vw - 32px));
  border-radius:22px;padding:16px 16px 14px;color:#f1f5f9;direction:<?= $lang === 'ar' ? 'rtl' : 'ltr' ?>;
  font-family:<?= $lang === 'ar' ? "'Tajawal','IBM Plex Sans Arabic'" : "'Inter'" ?>,system-ui,sans-serif;
  background:linear-gradient(145deg,rgba(22,163,74,.2),rgba(10,17,35,.97) 42%,rgba(147,51,234,.22));backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);
  border:1px solid rgba(34,197,94,.35);box-shadow:0 24px 60px rgba(0,0,0,.6),0 0 40px rgba(34,197,94,.15);
  transform:translateX(calc(-100% - 40px));opacity:0;transition:transform .55s cubic-bezier(.22,1,.36,1),opacity .4s;pointer-events:none}
.np-card.on{transform:none;opacity:1;pointer-events:auto}
.np-card .np-x{position:absolute;top:10px;inset-inline-end:10px;width:28px;height:28px;border-radius:50%;border:0;background:rgba(255,255,255,.08);color:#cbd5e1;font-size:14px;cursor:pointer}
.np-top{display:flex;gap:12px;align-items:center;margin-bottom:10px;padding-inline-end:30px}
.np-bell{width:48px;height:48px;border-radius:16px;flex-shrink:0;display:grid;place-items:center;font-size:24px;background:linear-gradient(135deg,#16a34a,#7c3aed);box-shadow:0 8px 22px rgba(34,197,94,.35);animation:npRing 2.8s ease-in-out infinite}
@keyframes npRing{0%,78%,100%{transform:rotate(0)}82%{transform:rotate(14deg)}86%{transform:rotate(-12deg)}90%{transform:rotate(8deg)}94%{transform:rotate(-4deg)}}
.np-top h4{font-size:15px;font-weight:900;line-height:1.35}
.np-card p{font-size:13px;color:#cbd5e1;line-height:1.7;margin:0 0 12px}
.np-card p b{color:#fde68a}
.np-steps{display:flex;flex-direction:column;gap:6px;margin:0 0 12px;padding:0;list-style:none}
.np-steps li{display:flex;gap:9px;align-items:flex-start;font-size:12.5px;line-height:1.6;color:#e2e8f0;background:rgba(2,6,23,.5);border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:8px 10px}
.np-steps li i{font-style:normal;width:22px;height:22px;border-radius:50%;flex-shrink:0;display:grid;place-items:center;font-size:11px;font-weight:900;background:linear-gradient(135deg,#22c55e,#9333ea)}
.np-steps li b{color:#86efac}
.np-acts{display:flex;gap:8px}
.np-go{flex:1;height:44px;border-radius:14px;border:0;font:inherit;font-size:14px;font-weight:900;color:#fff;cursor:pointer;background:linear-gradient(90deg,#16a34a,#22c55e 45%,#9333ea);box-shadow:0 8px 22px rgba(34,197,94,.3)}
.np-go:disabled{opacity:.6}
.np-later{height:44px;padding:0 16px;border-radius:14px;border:1px solid rgba(255,255,255,.12);background:transparent;color:#cbd5e1;font:inherit;font-size:13px;font-weight:800;cursor:pointer}
.np-msg{font-size:12px;font-weight:800;margin-top:8px;min-height:0}
.np-msg.ok{color:#86efac}.np-msg.bad{color:#fca5a5}
.np-spin{width:15px;height:15px;border-radius:50%;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;display:inline-block;animation:npSp .7s linear infinite;vertical-align:-3px}
@keyframes npSp{to{transform:rotate(360deg)}}
.np-tab{position:fixed;left:0;bottom:calc(120px + env(safe-area-inset-bottom));z-index:8999;display:none;align-items:center;gap:6px;height:42px;padding:0 12px 0 10px;border:0;cursor:pointer;
  border-radius:0 14px 14px 0;color:#fff;font:inherit;font-size:12px;font-weight:900;background:linear-gradient(135deg,#16a34a,#7c3aed);box-shadow:0 8px 24px rgba(0,0,0,.45)}
.np-tab.on{display:inline-flex;animation:npIn .5s cubic-bezier(.22,1,.36,1)}
.np-tab .dot{width:8px;height:8px;border-radius:50%;background:#fde047;box-shadow:0 0 8px #fde047;animation:npBlink 1.6s infinite}
@keyframes npIn{from{transform:translateX(-100%)}}
@keyframes npBlink{50%{opacity:.3}}
@media (prefers-reduced-motion:reduce){.np-card{transition:none}.np-bell,.np-tab .dot{animation:none}}
</style>
<div class="np-card" id="npCard" role="dialog" aria-live="polite" aria-hidden="true">
  <button type="button" class="np-x" id="npX" aria-label="×">✕</button>
  <div class="np-top"><div class="np-bell">🔔</div><h4 id="npH"><?= $NP['title'] ?></h4></div>
  <div id="npBody"></div>
  <div class="np-msg" id="npMsg"></div>
</div>
<button type="button" class="np-tab" id="npTab" aria-label="<?= htmlspecialchars($NP['tab']) ?>">🔔 <span class="dot"></span></button>
<script src="push_client.js"></script>
<script>
(function () {
  if (!window.F1Push) return;
  const NP = <?= json_encode($NP, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>, CSRF = <?= json_encode($csrfToken ?? '') ?>, LANG = <?= json_encode($lang) ?>;
  const FRESH = <?= $npFresh ? 'true' : 'false' ?>, EVERY = 20 * 60 * 1000, KEY = 'f1c_np_next';
  const $ = id => document.getElementById(id), card = $('npCard'), tab = $('npTab');
  const store = { get: () => { try { return +localStorage.getItem(KEY) || 0; } catch (e) { return 0; } }, set: v => { try { localStorage.setItem(KEY, String(v)); } catch (e) {} } };
  let timer = null, current = null;

  function open() { card.classList.add('on'); card.setAttribute('aria-hidden', 'false'); tab.classList.remove('on'); }
  function snooze() {
    card.classList.remove('on'); card.setAttribute('aria-hidden', 'true');
    store.set(Date.now() + EVERY);
    tab.classList.add('on');
    clearTimeout(timer); timer = setTimeout(maybeOpen, EVERY);   // comes back while the page stays open
  }
  function finished() { card.classList.remove('on'); tab.classList.remove('on'); clearTimeout(timer); }
  function msg(t, ok) { const m = $('npMsg'); m.innerHTML = t; m.className = 'np-msg ' + (ok ? 'ok' : 'bad'); }

  function render(s) {
    const b = $('npBody');
    if (s.needsHomeScreen) {
      $('npH').innerHTML = NP.ios_t;
      b.innerHTML = '<ol class="np-steps"><li><i>1</i><span>' + NP.ios_1 + '</span></li><li><i>2</i><span>' + NP.ios_2 + '</span></li><li><i>3</i><span>' + NP.ios_3 + '</span></li></ol>' +
                    '<div class="np-acts"><button type="button" class="np-later" id="npLater" style="flex:1">' + NP.later + '</button></div>';
    } else if (s.permission === 'denied') {
      $('npH').innerHTML = NP.den_t;
      b.innerHTML = '<p>' + (s.isIOS ? NP.den_i : NP.den_a) + '</p><div class="np-acts"><button type="button" class="np-go" id="npGo">' + NP.on + '</button><button type="button" class="np-later" id="npLater">' + NP.later + '</button></div>';
    } else {
      $('npH').innerHTML = NP.title;
      b.innerHTML = '<p>' + NP.text + '</p><div class="np-acts"><button type="button" class="np-go" id="npGo">' + NP.on + '</button><button type="button" class="np-later" id="npLater">' + NP.later + '</button></div>';
    }
    $('npLater').addEventListener('click', snooze);
    const go = $('npGo');
    if (go) go.addEventListener('click', async () => {
      go.disabled = true; go.innerHTML = '<span class="np-spin"></span>';
      try {
        const r = await F1Push.enable(CSRF, LANG);
        msg(NP.done, true);
        setTimeout(finished, 2600);
      } catch (e) {
        const k = String(e && e.message || e);
        msg(NP.fail + (NP.errs[k] || k), false);
        go.disabled = false; go.innerHTML = NP.on;
        if (k === 'denied') { const s2 = await F1Push.state(); render(s2); }
      }
    });
  }

  async function check() {
    if (document.getElementById('f1cWelcome')) { setTimeout(check, 1500); return; }   // wait for the "what's new" welcome
    // quietly re-link this phone if it had allowed notifications before (e.g. after a logout or a renewal)
    try { await F1Push.heal(CSRF, LANG); } catch (e) {}
    const s = await F1Push.state();
    current = s;
    // nothing this phone can do (no https / unsupported / old iOS / in-app browser) → stay quiet
    if (!s.secure || s.tooOldIOS || s.inAppBrowser || (!s.supported && !s.needsHomeScreen)) return null;
    if (s.subscribed && s.permission === 'granted') return null;   // already on — never ask again
    return s;
  }
  async function maybeOpen() {
    const s = await check();
    if (!s) { finished(); return; }
    render(s);
    if (Date.now() >= store.get()) open();
    else { tab.classList.add('on'); clearTimeout(timer); timer = setTimeout(maybeOpen, Math.max(1000, store.get() - Date.now())); }
  }

  $('npX').addEventListener('click', snooze);
  tab.addEventListener('click', async () => { if (!current) current = await check(); if (current) { render(current); open(); } });

  (async () => {
    if (FRESH) store.set(0);                 // right after login: show now
    setTimeout(maybeOpen, FRESH ? 1200 : 2500);
  })();
})();
</script>
