/*
 * other_option.js — «✏️ أخرى…» in a dropdown (add / edit a car).
 *
 * For a car that isn't in the lists (brand, model, trim, year, colour): choose
 * «أخرى», type it, and it is used for THIS car only. The lists themselves
 * (brands / models / colours) are never changed, so it never shows up when
 * adding a car the normal way.
 *
 *   F1Other.attach(select, { needs: parentSelect, numeric: false })
 *   F1Other.ensure(select, value)   // select a typed value (e.g. when refilling the form)
 *
 * The typed value goes in as an ordinary <option>, so the rest of the page
 * (preview, checks, saving) works with it exactly like a value from the list.
 */
(function () {
    var AR = (document.documentElement.lang || 'ar') !== 'en';
    var T = AR ? { other: '✏️ أخرى…', ph: 'اكتب هنا', ok: '✓ إضافة', cancel: '✕', hint: 'تُستخدم لهذه السيارة فقط — لا تُضاف إلى القوائم', below: '✏️ اكتب بالأسفل ↓', year: 'السنة (مثال: 2019)' }
               : { other: '✏️ Other…', ph: 'Type it here', ok: '✓ Add', cancel: '✕', hint: 'Used for this car only — not added to the lists', below: '✏️ Type it below ↓', year: 'Year (e.g. 2019)' };
    var OTHER = '__other__';

    function css() {
        if (document.getElementById('f1OtherCss')) return;
        var s = document.createElement('style'); s.id = 'f1OtherCss';
        s.textContent = '.f1o-box{display:none;margin-top:8px;padding:10px;border-radius:14px;background:rgba(168,85,247,.08);border:1px dashed rgba(168,85,247,.55)}' +
            '.f1o-box.on{display:block}.f1o-row{display:flex;gap:6px}' +
            '.f1o-row input{flex:1;min-width:0;height:44px;border-radius:12px;border:1.5px solid rgba(168,85,247,.6);background:#070d1c;color:#f1f5f9;font:inherit;font-size:15px;padding:0 12px;outline:none}' +
            '.f1o-row button{height:44px;padding:0 14px;border-radius:12px;border:0;font:inherit;font-size:14px;font-weight:900;cursor:pointer;white-space:nowrap}' +
            '.f1o-ok{background:linear-gradient(90deg,#9333ea,#2563eb);color:#fff}.f1o-x{background:rgba(255,255,255,.08);color:#e2e8f0}' +
            '.f1o-hint{margin-top:6px;font-size:11.5px;font-weight:700;color:#c4b5fd}';
        document.head.appendChild(s);
    }

    function hasOther(sel) { return !!sel.querySelector('option[value="' + OTHER + '"]'); }
    function realOptions(sel) { return Array.prototype.filter.call(sel.options, function (o) { return o.value && o.value !== OTHER; }); }

    /** put a typed value in the list (once) and select it */
    function ensure(sel, value, silent) {
        value = String(value || '').trim();
        if (!value || value === OTHER) return false;
        var opt = Array.prototype.find.call(sel.options, function (o) { return o.value === value; });
        if (!opt) {
            opt = document.createElement('option');
            opt.value = value; opt.textContent = '✏️ ' + value; opt.setAttribute('data-custom', '1');
            var o = sel.querySelector('option[value="' + OTHER + '"]');
            sel.insertBefore(opt, o || null);
        }
        sel.disabled = false;
        sel.value = value;
        if (!silent) sel.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    }

    function attach(sel, opts) {
        if (!sel || sel._f1o) return;
        opts = opts || {};
        css();
        var box = document.createElement('div'); box.className = 'f1o-box';
        box.innerHTML = '<div class="f1o-row"><input type="' + (opts.numeric ? 'number' : 'text') + '" maxlength="100" placeholder="' + (opts.numeric ? T.year : T.ph) + '">' +
            '<button type="button" class="f1o-ok">' + T.ok + '</button><button type="button" class="f1o-x">' + T.cancel + '</button></div><div class="f1o-hint">' + T.hint + '</div>';
        var anchor = sel.closest('.field-wrap') || sel;
        anchor.parentNode.insertBefore(box, anchor.nextSibling);
        var input = box.querySelector('input'), prev = sel.value;
        var state = { busy: false };

        function addOther() {
            if (state.busy || hasOther(sel)) return;
            state.busy = true;
            var o = document.createElement('option'); o.value = OTHER; o.textContent = T.other;
            sel.appendChild(o);
            // a list that came back empty (e.g. the brand was typed by hand): still let them type
            if (sel.disabled && (!opts.needs || opts.needs.value)) sel.disabled = false;
            state.busy = false;
        }
        function open() {
            box.classList.add('on');
            input.value = '';
            setTimeout(function () { try { input.focus(); } catch (e) {} }, 30);
        }
        function close() { box.classList.remove('on'); }
        function commit() {
            var v = input.value.trim();
            if (!v) { input.focus(); return; }
            if (opts.numeric && !/^\d{4}$/.test(v)) { input.style.borderColor = '#ef4444'; input.focus(); return; }
            input.style.borderColor = '';
            close();
            ensure(sel, v);
            prev = sel.value;
        }

        // capture: runs before the page's own handler, which must never see "other" itself
        sel.addEventListener('change', function (e) {
            if (sel.value === OTHER) {
                e.stopImmediatePropagation();
                sel.value = realOptions(sel).some(function (o) { return o.value === prev; }) ? prev : '';
                open();
                return;
            }
            prev = sel.value;
            if (sel.value) close();
        }, true);
        box.querySelector('.f1o-ok').addEventListener('click', commit);
        box.querySelector('.f1o-x').addEventListener('click', function () { close(); });
        input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); commit(); } else if (e.key === 'Escape') close(); });

        // the page refills these lists (brand → models → trims): keep «أخرى» at the end every time
        new MutationObserver(function () { addOther(); }).observe(sel, { childList: true });
        // a hand-typed parent (brand / model) has no list below it: open the box straight away
        if (opts.needs) opts.needs.addEventListener('change', function () {
            close();
            var po = opts.needs.options[opts.needs.selectedIndex];
            if (po && po.getAttribute('data-custom') === '1') {
                setTimeout(function () {
                    addOther();
                    if (!realOptions(sel).length) {
                        var ph = sel.options[0]; if (ph && !ph.value) ph.textContent = T.below;   // not «no models»: there is no list for a typed brand
                        open();
                    }
                }, 450);
            }
        });
        addOther();
        sel._f1o = { open: open, close: close };
    }

    window.F1Other = { attach: attach, ensure: ensure, OTHER: OTHER };
})();
