/*
 * app.js — what every page shares (added by app_shell.php):
 *   - search from any page: a chassis number, a model, a colour or a branch
 *   - text size: عادي / كبير (remembered on this device)
 *   - a notice when the connection drops, and when it is back
 *
 *   - a short sound and vibration for each kind of event (can be turned off)
 *
 *   F1App.search()        open the search sheet
 *   F1App.textSize('big' | 'normal')
 *   F1App.sound('sale' | 'car' | 'move' | 'stop' | 'check' | 'msg' | 'ok' | 'bad')
 *   F1App.soundFor(event) the sound that belongs to a notification event
 *   F1App.sounds(true | false)   turn them on or off (remembered on this device)
 */
(function () {
    if (window.F1App) return;
    var me = document.currentScript;
    var LANG = (me && me.getAttribute('data-lang')) || document.documentElement.lang || 'ar';
    var AR = LANG !== 'en';
    var T = AR ? { ph: 'ابحث: رقم الشاسيه، الموديل، اللون، الفرع…', hint: 'اكتب حرفين على الأقل للبحث في السيارات الموجودة', none: 'لا توجد سيارات مطابقة', wait: 'جاري البحث…', fail: 'تعذّر البحث — تحقق من الاتصال', search: 'بحث',
                   size: 'حجم الخط', normal: 'عادي', big: 'كبير', snd: 'الأصوات', on: 'تشغيل', offS: 'إيقاف', off: '⚠️ لا يوجد اتصال بالإنترنت — لن تُحفظ التغييرات حتى يعود الاتصال', back: '✓ عاد الاتصال' }
               : { ph: 'Search: chassis, model, colour, branch…', hint: 'Type at least two letters to search the cars in stock', none: 'No matching cars', wait: 'Searching…', fail: 'Search failed — check the connection', search: 'Search',
                   size: 'Text size', normal: 'Normal', big: 'Large', snd: 'Sounds', on: 'On', offS: 'Off', off: '⚠️ No internet connection — changes will not be saved until it is back', back: '✓ Back online' };
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var ready = function (fn) { if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn); else fn(); };

    /* ── text size ── */
    function textSize(v) {
        var big = v === 'big';
        document.documentElement.classList.toggle('f1c-big', big);
        try { localStorage.setItem('f1cTextSize', big ? 'big' : 'normal'); } catch (e) {}
        document.querySelectorAll('[data-f1size]').forEach(function (b) { b.classList.toggle('on', (b.getAttribute('data-f1size') === 'big') === big); });
    }
    var isBig = function () { return document.documentElement.classList.contains('f1c-big'); };

    /* ── sounds: made in the browser (no files), one short signature per kind of event ── */
    var ctx = null, sndOn = true;
    try { sndOn = localStorage.getItem('f1cSound') !== 'off'; } catch (e) {}
    // [frequency Hz, start s, length s, wave, volume]
    var TUNES = {
        sale:  { n: [[523, 0, .12], [659, .1, .12], [784, .2, .12], [1047, .3, .28]], w: 'triangle', v: [40, 40, 40, 40, 120] },   // rising: a car was sold
        car:   { n: [[660, 0, .1], [880, .11, .2]], w: 'sine', v: [30, 50, 30] },                                                // two notes up: a new car
        move:  { n: [[440, 0, .1], [554, .1, .1], [440, .2, .14]], w: 'sine', v: [25, 40, 25] },                                  // up and back: a transfer
        stop:  { n: [[196, 0, .22], [147, .24, .34]], w: 'sawtooth', v: [180, 90, 180] },                                         // low and heavy: the system stopped
        check: { n: [[880, 0, .09], [880, .16, .09], [880, .32, .09], [1175, .48, .22]], w: 'square', v: [60, 60, 60, 60, 60, 60, 140] },   // urgent: a stock check
        msg:   { n: [[784, 0, .08], [988, .09, .16]], w: 'sine', v: [20] },                                                       // soft: a message
        ok:    { n: [[659, 0, .08], [988, .08, .18]], w: 'triangle', v: [30] },
        bad:   { n: [[220, 0, .14], [185, .15, .22]], w: 'square', v: [90, 50, 90] }
    };
    function unlock() { try { if (!ctx) { var AC = window.AudioContext || window.webkitAudioContext; if (AC) ctx = new AC(); } if (ctx && ctx.state === 'suspended') ctx.resume(); } catch (e) {} }
    ['pointerdown', 'keydown', 'touchstart'].forEach(function (ev) { window.addEventListener(ev, unlock, { passive: true, capture: true }); });
    function sound(kind) {
        var t = TUNES[kind]; if (!t || !sndOn) return;
        try { if (navigator.vibrate) navigator.vibrate(t.v); } catch (e) {}
        try {
            if (!ctx || ctx.state !== 'running') return;       // the browser allows sound only after the first touch
            var now = ctx.currentTime + .02, loud = t.w === 'sine' || t.w === 'triangle' ? .16 : .07;
            t.n.forEach(function (n) {
                var o = ctx.createOscillator(), g = ctx.createGain();
                o.type = t.w; o.frequency.value = n[0];
                g.gain.setValueAtTime(0, now + n[1]); g.gain.linearRampToValueAtTime(loud, now + n[1] + .015); g.gain.exponentialRampToValueAtTime(.0001, now + n[1] + n[2]);
                o.connect(g); g.connect(ctx.destination); o.start(now + n[1]); o.stop(now + n[1] + n[2] + .03);
            });
        } catch (e) {}
    }
    function soundFor(ev) {
        ev = String(ev || '');
        if (/^sale|sold|last_car/.test(ev)) return sound('sale');
        if (/^car_added|incoming|shipment/.test(ev)) return sound('car');
        if (/^transfer|^duty_(start|done|unlocked)/.test(ev)) return sound('move');
        if (/locked|^lock_|duty_warn|duty_locked/.test(ev)) return sound('stop');
        if (/^check_/.test(ev)) return sound('check');
        return sound('msg');
    }
    function sounds(on) {
        sndOn = !!on; try { localStorage.setItem('f1cSound', sndOn ? 'on' : 'off'); } catch (e) {}
        document.querySelectorAll('[data-f1snd]').forEach(function (b) { b.classList.toggle('on', (b.getAttribute('data-f1snd') === 'on') === sndOn); });
        if (sndOn) { unlock(); sound('ok'); }
    }

    /* ── search ── */
    var ov, input, list, timer, seq = 0;
    function build() {
        if (ov) return;
        ov = document.createElement('div'); ov.className = 'f1s-ov'; ov.setAttribute('dir', AR ? 'rtl' : 'ltr');
        ov.innerHTML = '<div class="f1s-box" role="dialog" aria-label="' + esc(T.search) + '"><div class="f1s-top"><input class="f1s-in" type="search" autocomplete="off" autocorrect="off" spellcheck="false" placeholder="' + esc(T.ph) + '">' +
            '<button type="button" class="f1s-x" aria-label="close">✕</button></div><div class="f1s-list"><div class="f1s-msg">' + esc(T.hint) + '</div></div>' +
            '<div class="f1s-foot"><span>' + esc(T.size) + '</span><span class="f1s-seg"><button type="button" data-f1size="normal">' + esc(T.normal) + '</button><button type="button" data-f1size="big">' + esc(T.big) + '</button></span></div>' +
            '<div class="f1s-foot"><span>🔔 ' + esc(T.snd) + '</span><span class="f1s-seg"><button type="button" data-f1snd="on">' + esc(T.on) + '</button><button type="button" data-f1snd="off">' + esc(T.offS) + '</button></span></div></div>';
        document.body.appendChild(ov);
        input = ov.querySelector('.f1s-in'); list = ov.querySelector('.f1s-list');
        ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
        ov.querySelector('.f1s-x').addEventListener('click', close);
        ov.querySelectorAll('[data-f1size]').forEach(function (b) { b.addEventListener('click', function () { textSize(b.getAttribute('data-f1size')); }); });
        textSize(isBig() ? 'big' : 'normal');
        ov.querySelectorAll('[data-f1snd]').forEach(function (b) { b.classList.toggle('on', (b.getAttribute('data-f1snd') === 'on') === sndOn); b.addEventListener('click', function () { sounds(b.getAttribute('data-f1snd') === 'on'); }); });
        input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 220); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') close();
            if (e.key === 'Enter') { var a = list.querySelector('.f1s-it'); if (a) location.href = a.href; }
        });
    }
    function run() {
        var q = input.value.trim(), my = ++seq;
        if (q.length < 2) { list.innerHTML = '<div class="f1s-msg">' + esc(T.hint) + '</div>'; return; }
        list.innerHTML = '<div class="f1s-msg">' + esc(T.wait) + '</div>';
        fetch('car_search.php?lang=' + LANG + '&q=' + encodeURIComponent(q), { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (r) {
                if (my !== seq) return;
                if (!r || !r.ok) { list.innerHTML = '<div class="f1s-msg">' + esc(T.fail) + '</div>'; return; }
                if (!r.items.length) { list.innerHTML = '<div class="f1s-msg">' + esc(T.none) + '</div>'; return; }
                list.innerHTML = r.items.map(function (c) {
                    return '<a class="f1s-it" href="' + esc(c.url) + '"><span class="t"><b>🚗 ' + esc(c.title) + '</b><small>' + esc(c.color) + ' · 📍 ' + esc(c.branch) + ' · <span class="ch">' + esc(c.chassis) + '</span></small></span>' +
                        '<span class="f1s-st ' + esc(c.status) + '">' + esc(c.statusL) + '</span></a>';
                }).join('');
            })
            .catch(function () { if (my === seq) list.innerHTML = '<div class="f1s-msg">' + esc(T.fail) + '</div>'; });
    }
    function open() { build(); ov.classList.add('on'); input.value = ''; list.innerHTML = '<div class="f1s-msg">' + esc(T.hint) + '</div>'; setTimeout(function () { try { input.focus(); } catch (e) {} }, 40); }
    function close() { if (ov) ov.classList.remove('on'); }

    ready(function () {
        if (document.querySelector('.bn2, .bottom-nav')) document.body.classList.add('f1c-has-bn');
        // the round search button — not on pages that are not part of the everyday system (locked screen, stock check in progress)
        var page = (location.pathname.split('/').pop() || '');
        if (!/^(transfer_lock|stock_check|index|sold_login|qr_stickers|diagnose)\.php$/.test(page)) {
            var b = document.createElement('button'); b.type = 'button'; b.className = 'f1s-fab'; b.setAttribute('aria-label', T.search); b.title = T.search + ' ( / )';
            b.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>';
            b.addEventListener('click', open);
            if (!document.getElementById('searchInput')) document.body.appendChild(b);   // the dashboard has its own search box
            document.addEventListener('keydown', function (e) {
                if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
                var t = e.target; if (t && (/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || t.isContentEditable)) return;
                e.preventDefault(); open();
            });
        }
    });

    /* ── connection notice ── */
    var offEl;
    function net() {
        ready(function () {
            if (!navigator.onLine) {
                if (!offEl) { offEl = document.createElement('div'); document.body.appendChild(offEl); }
                offEl.className = 'f1c-off'; offEl.textContent = T.off; offEl.hidden = false;
            } else if (offEl && !offEl.hidden) {
                offEl.className = 'f1c-off back'; offEl.textContent = T.back;
                setTimeout(function () { if (navigator.onLine && offEl) offEl.hidden = true; }, 2500);
            }
        });
    }
    window.addEventListener('offline', net); window.addEventListener('online', net);
    if (!navigator.onLine) net();

    window.F1App = { search: open, textSize: textSize, isBig: isBig, sound: sound, soundFor: soundFor, sounds: sounds, soundsOn: function () { return sndOn; } };
})();
