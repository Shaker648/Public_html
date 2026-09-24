<?php
/*
 * notify_popup.php — new notifications slide in from the left when someone
 * opens the system (included on the dashboard).
 *
 *   • normal notifications (sold, transfer, clock-in…): shown ONCE, then they
 *     fade away by themselves and never come back
 *   • custom messages from the admin: stay until tapped; tapping opens the
 *     message right here, and once read it is gone
 *   • a tap on a phone notification for a message opens the system with
 *     ?msg=ID and the message opens straight away
 *
 * Checks again every minute while the page is open. Needs $lang.
 */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$ntLang = (isset($lang) && $lang === 'en') ? 'en' : 'ar';
$NT = $ntLang === 'ar' ? [
    'new'   => 'إشعارات جديدة', 'one' => 'إشعار جديد', 'closeAll' => 'إغلاق الكل', 'more' => 'و %d إشعارات أخرى',
    'msg'   => 'رسالة من الإدارة', 'tap' => 'اضغط لقراءة الرسالة', 'from' => 'من', 'done' => 'تم ✓', 'close' => 'إغلاق',
    'ago'   => ['الآن', 'منذ %d دقيقة', 'منذ %d ساعة', 'أمس', 'منذ %d يوم'],
] : [
    'new'   => 'new notifications', 'one' => 'new notification', 'closeAll' => 'Close all', 'more' => 'and %d more',
    'msg'   => 'Message from management', 'tap' => 'Tap to read the message', 'from' => 'From', 'done' => 'Done ✓', 'close' => 'Close',
    'ago'   => ['just now', '%d min ago', '%d h ago', 'yesterday', '%d days ago'],
];
?>
<style>
.nt-stack{position:fixed;left:16px;top:calc(16px + env(safe-area-inset-top));z-index:9100;width:min(370px,calc(100vw - 32px));display:flex;flex-direction:column;gap:10px;pointer-events:none;font-family:'Tajawal','Segoe UI',Tahoma,Arial,sans-serif}
.nt-stack > *{pointer-events:auto}
.nt-head{display:flex;align-items:center;gap:8px;padding:8px 10px 8px 14px;border-radius:14px;background:rgba(2,6,23,.88);border:1px solid rgba(255,255,255,.1);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);color:#e2e8f0;font-size:13px;font-weight:800;box-shadow:0 10px 30px rgba(0,0,0,.35);transform:translateX(-120%);opacity:0;transition:transform .45s cubic-bezier(.22,1,.36,1),opacity .3s}
.nt-head.on{transform:none;opacity:1}
.nt-head .dot{width:9px;height:9px;border-radius:50%;background:#22c55e;box-shadow:0 0 10px #22c55e;animation:ntBlink 1.4s infinite}
.nt-head button{margin-inline-start:auto;border:0;background:rgba(255,255,255,.08);color:#cbd5e1;font:inherit;font-size:12px;font-weight:800;padding:5px 10px;border-radius:9px;cursor:pointer}
.nt-card{position:relative;display:flex;gap:11px;padding:12px 38px 12px 12px;border-radius:18px;background:linear-gradient(160deg,rgba(15,23,42,.97),rgba(2,6,23,.97));border:1px solid rgba(255,255,255,.1);color:#e2e8f0;box-shadow:0 16px 40px rgba(0,0,0,.45);cursor:pointer;overflow:hidden;
  transform:translateX(-120%);opacity:0;transition:transform .5s cubic-bezier(.22,1,.36,1),opacity .35s,max-height .35s,margin .35s,padding .35s;max-height:220px;text-decoration:none}
[dir=rtl] .nt-card{padding:12px 12px 12px 38px}
.nt-card.on{transform:none;opacity:1}
.nt-card.out{transform:translateX(-120%);opacity:0}
.nt-card.gone{max-height:0;padding-top:0;padding-bottom:0;margin-top:-10px;border-width:0}
.nt-card:hover{border-color:rgba(34,197,94,.45)}
.nt-card img{width:42px;height:42px;border-radius:11px;flex-shrink:0;box-shadow:0 4px 12px rgba(0,0,0,.4)}
.nt-card .tx{flex:1;min-width:0}
.nt-card .tt{font-size:14px;font-weight:800;line-height:1.45;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.nt-card .bd{font-size:12.5px;color:#94a3b8;line-height:1.55;margin-top:2px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;white-space:pre-line}
.nt-card .ag{font-size:11px;color:#64748b;font-weight:700;margin-top:4px}
.nt-card .x{position:absolute;top:8px;inset-inline-end:8px;width:26px;height:26px;border-radius:50%;border:0;background:rgba(255,255,255,.07);color:#94a3b8;font-size:13px;cursor:pointer;line-height:26px;text-align:center;padding:0}
.nt-card .bar{position:absolute;left:0;right:0;bottom:0;height:3px;background:linear-gradient(90deg,#22c55e,#9333ea);transform-origin:left;animation:ntBar var(--t,14s) linear forwards}
[dir=rtl] .nt-card .bar{transform-origin:right}
.nt-card:hover .bar{animation-play-state:paused}
.nt-card.msg{background:linear-gradient(150deg,rgba(88,28,135,.96),rgba(30,27,75,.97) 60%,rgba(15,23,42,.97));border-color:rgba(250,204,21,.45);box-shadow:0 16px 44px rgba(147,51,234,.35)}
.nt-card.msg .lbl{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:900;color:#fde68a;background:rgba(250,204,21,.14);border:1px solid rgba(250,204,21,.35);padding:2px 9px;border-radius:999px;margin-bottom:5px}
.nt-card.msg .tap{font-size:12px;font-weight:800;color:#fde68a;margin-top:6px;display:flex;align-items:center;gap:6px}
.nt-card.msg .tap::before{content:'';width:7px;height:7px;border-radius:50%;background:#fde047;box-shadow:0 0 8px #fde047;animation:ntBlink 1.4s infinite}
.nt-more{align-self:flex-start;font-size:12px;font-weight:800;color:#cbd5e1;background:rgba(2,6,23,.85);border:1px solid rgba(255,255,255,.1);padding:6px 12px;border-radius:999px;opacity:0;transition:opacity .4s}
.nt-more.on{opacity:1}
/* message reader */
.nt-ov{position:fixed;inset:0;z-index:9200;background:rgba(2,6,23,.8);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);display:none;align-items:center;justify-content:center;padding:16px;font-family:'Tajawal','Segoe UI',Tahoma,Arial,sans-serif}
.nt-ov.on{display:flex;animation:ntFade .25s ease}
.nt-read{width:100%;max-width:480px;max-height:88vh;display:flex;flex-direction:column;border-radius:24px;overflow:hidden;background:linear-gradient(170deg,#1a1036,#0b1022);border:1px solid rgba(250,204,21,.35);box-shadow:0 30px 80px rgba(0,0,0,.6);animation:ntPop .35s cubic-bezier(.22,1,.36,1)}
.nt-read .hd{display:flex;gap:12px;align-items:center;padding:18px 20px;background:linear-gradient(120deg,rgba(147,51,234,.35),rgba(34,197,94,.12));border-bottom:1px solid rgba(255,255,255,.08)}
.nt-read .hd img{width:48px;height:48px;border-radius:13px}
.nt-read .hd .l{font-size:11px;font-weight:900;color:#fde68a}
.nt-read .hd h3{font-size:18px;font-weight:900;color:#fff;margin:2px 0 0;line-height:1.4}
.nt-read .meta{font-size:12px;color:#94a3b8;font-weight:700;padding:12px 20px 0}
.nt-read .txt{padding:14px 20px 18px;font-size:16px;line-height:1.9;color:#f1f5f9;white-space:pre-wrap;word-break:break-word;overflow-y:auto}
.nt-read .ft{padding:14px 20px 18px;border-top:1px solid rgba(255,255,255,.06)}
.nt-read .ft button{width:100%;height:48px;border:0;border-radius:14px;background:linear-gradient(90deg,#16a34a,#22c55e);color:#fff;font:inherit;font-size:16px;font-weight:900;cursor:pointer;box-shadow:0 10px 26px rgba(34,197,94,.3)}
@keyframes ntBlink{50%{opacity:.35}}
@keyframes ntBar{from{transform:scaleX(1)}to{transform:scaleX(0)}}
@keyframes ntFade{from{opacity:0}}
@keyframes ntPop{from{opacity:0;transform:translateY(16px) scale(.97)}}
@media (max-width:560px){.nt-stack{left:10px;width:calc(100vw - 20px);top:calc(10px + env(safe-area-inset-top))}}
@media (prefers-reduced-motion:reduce){.nt-card,.nt-head{transition:none}.nt-card .bar{animation:none}}
@media print{.nt-stack,.nt-ov{display:none!important}}
</style>
<div class="nt-stack" id="ntStack" aria-live="polite"></div>
<div class="nt-ov" id="ntOv" role="dialog" aria-modal="true">
  <div class="nt-read">
    <div class="hd"><img src="icons/icon-192.png?v=4" alt=""><div><div class="l">✉️ <?= htmlspecialchars($NT['msg']) ?></div><h3 id="ntRT"></h3></div></div>
    <div class="meta" id="ntRM"></div>
    <div class="txt" id="ntRB"></div>
    <div class="ft"><button type="button" id="ntRDone"><?= htmlspecialchars($NT['done']) ?></button></div>
  </div>
</div>
<script>
(function () {
  const NT = <?= json_encode($NT, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>, CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;
  const stack = document.getElementById('ntStack'), ov = document.getElementById('ntOv');
  const shown = new Set();           // inbox ids already on screen
  const openMsg = +(new URLSearchParams(location.search).get('msg') || 0);
  let reading = null, head = null;

  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const ago = s => s < 60 ? NT.ago[0] : s < 3600 ? NT.ago[1].replace('%d', Math.floor(s / 60)) : s < 86400 ? NT.ago[2].replace('%d', Math.floor(s / 3600))
                 : (Math.floor(s / 86400) === 1 ? NT.ago[3] : NT.ago[4].replace('%d', Math.floor(s / 86400)));
  const dirOf = t => /[؀-ۿ]/.test(t) ? 'rtl' : 'ltr';

  function remove(card) {
    if (!card || card.classList.contains('out')) return;
    card.classList.add('out');
    setTimeout(() => { card.classList.add('gone'); }, 380);
    setTimeout(() => { card.remove(); syncHead(); }, 760);
  }
  function syncHead() {
    const n = stack.querySelectorAll('.nt-card:not(.out)').length;
    if (head && n < 2) { head.classList.remove('on'); const h = head; head = null; setTimeout(() => h.remove(), 450); }
    if (!n) stack.querySelectorAll('.nt-more').forEach(m => m.remove());
  }
  function ensureHead(total) {
    if (total < 2) return;
    if (!head) {
      head = document.createElement('div'); head.className = 'nt-head';
      head.innerHTML = '<span class="dot"></span><span class="n"></span><button type="button">' + esc(NT.closeAll) + '</button>';
      head.querySelector('button').addEventListener('click', () => stack.querySelectorAll('.nt-card').forEach(remove));
      stack.prepend(head); requestAnimationFrame(() => head.classList.add('on'));
    }
    head.querySelector('.n').textContent = '🔔 ' + total + ' ' + NT.new;
  }

  function card(item, i) {
    const isMsg = item.event === 'message';
    const el = document.createElement(isMsg || !item.url ? 'div' : 'a');
    el.className = 'nt-card' + (isMsg ? ' msg' : '');
    if (!isMsg && item.url) el.href = item.url;
    el.dir = dirOf(item.title);
    const body = isMsg ? item.body.split('\n')[0] : item.body;
    el.innerHTML = '<img src="icons/icon-192.png?v=4" alt=""><div class="tx">' +
      (isMsg ? '<span class="lbl">✉️ ' + esc(NT.msg) + '</span>' : '') +
      '<div class="tt">' + esc(item.title) + '</div>' +
      (body ? '<div class="bd">' + esc(body) + '</div>' : '') +
      (isMsg ? '<div class="tap">' + esc(NT.tap) + '</div>' : '<div class="ag">' + esc(ago(item.age)) + '</div>') +
      '</div><button type="button" class="x" aria-label="' + esc(NT.close) + '">✕</button>' + (isMsg ? '' : '<span class="bar" style="--t:' + (14 + i * 2) + 's"></span>');
    el.querySelector('.x').addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); if (isMsg) markRead(item); remove(el); });
    if (isMsg) el.addEventListener('click', () => openReader(item, el));
    else el.querySelector('.bar').addEventListener('animationend', () => remove(el));   // fades away by itself (pauses while the mouse is on it)
    return el;
  }

  function markRead(item) {
    fetch('notify_feed.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'read', csrf: CSRF, id: item.id }) }).catch(() => {});
  }
  function openReader(item, el) {
    reading = { item, el };
    document.getElementById('ntRT').textContent = item.title;
    document.getElementById('ntRT').dir = dirOf(item.title);
    document.getElementById('ntRM').textContent = (item.by ? NT.from + ' ' + item.by + ' · ' : '') + ago(item.age);
    const b = document.getElementById('ntRB'); b.textContent = item.body; b.dir = dirOf(item.body || item.title);
    ov.classList.add('on');
    markRead(item);                    // opened = read
  }
  function closeReader() {
    ov.classList.remove('on');
    if (reading) { remove(reading.el); reading = null; }
    if (openMsg && history.replaceState) { const u = new URL(location.href); u.searchParams.delete('msg'); history.replaceState(null, '', u); }
  }
  document.getElementById('ntRDone').addEventListener('click', closeReader);
  ov.addEventListener('click', e => { if (e.target === ov) closeReader(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && ov.classList.contains('on')) closeReader(); });

  async function check(first) {
    let r;
    try {
      const u = 'notify_feed.php?action=list' + (first && openMsg ? '&msg=' + openMsg : '');
      r = await (await fetch(u, { credentials: 'same-origin', cache: 'no-store' })).json();
    } catch (e) { return; }
    if (!r || !r.ok) return;
    const items = [...r.messages, ...r.events].filter(x => !shown.has(x.id));
    items.forEach(x => shown.add(x.id));
    if (!items.length) return;
    const total = stack.querySelectorAll('.nt-card:not(.out)').length + items.length;
    ensureHead(total);
    items.forEach((x, i) => {
      const el = card(x, i);
      stack.appendChild(el);
      setTimeout(() => el.classList.add('on'), 250 + i * 160);
      if (first && openMsg && x.log === openMsg) setTimeout(() => openReader(x, el), 500);
    });
    if (r.more > 0) {
      const m = document.createElement('div'); m.className = 'nt-more'; m.textContent = NT.more.replace('%d', r.more);
      stack.appendChild(m); setTimeout(() => m.classList.add('on'), 400 + items.length * 160);
      setTimeout(() => { m.classList.remove('on'); setTimeout(() => m.remove(), 400); }, 16000);
    }
  }
  setTimeout(() => check(true), 700);
  setInterval(() => { if (!document.hidden) check(false); }, 60000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) check(false); });
})();
</script>
