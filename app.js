/*
 * app.js — what every page shares (added by app_shell.php):
 *   - search from any page: a chassis number, a model, a colour or a branch
 *   - text size: عادي / كبير (remembered on this device)
 *   - a notice when the connection drops, and when it is back
 *
 *   F1App.search()        open the search sheet
 *   F1App.textSize('big' | 'normal')
 */
(function () {
    if (window.F1App) return;
    var me = document.currentScript;
    var LANG = (me && me.getAttribute('data-lang')) || document.documentElement.lang || 'ar';
    var AR = LANG !== 'en';
    var T = AR ? { ph: 'ابحث: رقم الشاسيه، الموديل، اللون، الفرع…', hint: 'اكتب حرفين على الأقل للبحث في السيارات الموجودة', none: 'لا توجد سيارات مطابقة', wait: 'جاري البحث…', fail: 'تعذّر البحث — تحقق من الاتصال', search: 'بحث',
                   size: 'حجم الخط', normal: 'عادي', big: 'كبير', off: '⚠️ لا يوجد اتصال بالإنترنت — لن تُحفظ التغييرات حتى يعود الاتصال', back: '✓ عاد الاتصال' }
               : { ph: 'Search: chassis, model, colour, branch…', hint: 'Type at least two letters to search the cars in stock', none: 'No matching cars', wait: 'Searching…', fail: 'Search failed — check the connection', search: 'Search',
                   size: 'Text size', normal: 'Normal', big: 'Large', off: '⚠️ No internet connection — changes will not be saved until it is back', back: '✓ Back online' };
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

    /* ── search ── */
    var ov, input, list, timer, seq = 0;
    function build() {
        if (ov) return;
        ov = document.createElement('div'); ov.className = 'f1s-ov'; ov.setAttribute('dir', AR ? 'rtl' : 'ltr');
        ov.innerHTML = '<div class="f1s-box" role="dialog" aria-label="' + esc(T.search) + '"><div class="f1s-top"><input class="f1s-in" type="search" autocomplete="off" autocorrect="off" spellcheck="false" placeholder="' + esc(T.ph) + '">' +
            '<button type="button" class="f1s-x" aria-label="close">✕</button></div><div class="f1s-list"><div class="f1s-msg">' + esc(T.hint) + '</div></div>' +
            '<div class="f1s-foot"><span>' + esc(T.size) + '</span><span class="f1s-seg"><button type="button" data-f1size="normal">' + esc(T.normal) + '</button><button type="button" data-f1size="big">' + esc(T.big) + '</button></span></div></div>';
        document.body.appendChild(ov);
        input = ov.querySelector('.f1s-in'); list = ov.querySelector('.f1s-list');
        ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
        ov.querySelector('.f1s-x').addEventListener('click', close);
        ov.querySelectorAll('[data-f1size]').forEach(function (b) { b.addEventListener('click', function () { textSize(b.getAttribute('data-f1size')); }); });
        textSize(isBig() ? 'big' : 'normal');
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

    window.F1App = { search: open, textSize: textSize, isBig: isBig };
})();
