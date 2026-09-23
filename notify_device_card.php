<?php
/*
 * notify_device_card.php — the "this device" card (included by
 * notifications.php and notifications_admin.php). Needs $lang and $csrf.
 * Shows exactly what to do on this phone: iPhone → add to Home Screen first,
 * Android → one tap. Then a test notification is sent straight away.
 */
$DC = $lang === 'ar' ? [
    'h'        => 'هذا الجهاز',
    'checking' => 'جارٍ فحص الجهاز…',
    'on'       => 'الإشعارات مفعّلة على هذا الجهاز ✅',
    'onSub'    => 'ستصلك الإشعارات حتى والتطبيق مغلق والموبايل مقفول',
    'enable'   => '🔔 تفعيل الإشعارات',
    'offSub'   => 'اضغط التفعيل ثم اختر «السماح» — وسيصلك إشعار تجربة فوراً',
    'test'     => '📨 إشعار تجربة',
    'off'      => 'إيقاف على هذا الجهاز',
    'sent'     => '✓ تم الإرسال — انظر إلى الإشعارات على الموبايل',
    'fail'     => '✗ لم يصل الإشعار: ',
    'ios_h'    => 'على الآيفون: أضف التطبيق للشاشة الرئيسية أولاً',
    'ios_sub'  => 'أبل تسمح بالإشعارات فقط للتطبيق المفتوح من الشاشة الرئيسية — خطوة واحدة فقط',
    's1'       => 'افتح الموقع في <b>Safari</b> واضغط زر <b>المشاركة</b> بالأسفل',
    's2'       => 'اختر <b>«إضافة إلى الشاشة الرئيسية»</b> ثم <b>«إضافة»</b>',
    's3'       => 'افتح <b>First 1 Car</b> من الشاشة الرئيسية وسجّل الدخول، ثم ادخل هنا واضغط <b>تفعيل</b>',
    'add'      => 'إضافة إلى الشاشة الرئيسية',
    'old_ios'  => 'نسخة iOS على هذا الآيفون قديمة — الإشعارات تحتاج iOS 16.4 أو أحدث. حدّث الموبايل من الإعدادات ← عام ← تحديث البرنامج.',
    'inapp'    => 'أنت داخل متصفح تطبيق (واتساب / فيسبوك). افتح الرابط في <b>Safari</b> على الآيفون أو <b>Chrome</b> على أندرويد.',
    'insecure' => 'الموقع مفتوح بدون https — الإشعارات تحتاج اتصالاً آمناً (🔒). افتح الموقع بـ <b>https://</b>',
    'unsup'    => 'هذا المتصفح لا يدعم الإشعارات. على أندرويد استخدم <b>Chrome</b>، وعلى الآيفون أضف الموقع للشاشة الرئيسية من <b>Safari</b>.',
    'denied_h' => 'الإشعارات مرفوضة على هذا الجهاز',
    'den_ios'  => 'افتح <b>الإعدادات</b> ← <b>الإشعارات</b> ← <b>First 1 Car</b> ← فعّل <b>السماح بالإشعارات</b>، ثم ارجع هنا واضغط تفعيل.',
    'den_and'  => 'اضغط 🔒 بجانب عنوان الموقع ← <b>الأذونات</b> ← <b>الإشعارات</b> ← <b>سماح</b>، ثم أعد تحميل الصفحة واضغط تفعيل.',
    'errs'     => ['denied' => 'تم رفض الإذن', 'dismissed' => 'لم يتم اختيار «السماح»', 'server_key' => 'تعذّر الاتصال بالخادم', 'csrf' => 'انتهت الصفحة — حدّثها', 'bad_subscription' => 'بيانات الجهاز غير صالحة', 'unsupported' => 'الجهاز لا يدعم الإشعارات', 'insecure' => 'الموقع يحتاج https'],
] : [
    'h'        => 'This device',
    'checking' => 'Checking this device…',
    'on'       => 'Notifications are on for this device ✅',
    'onSub'    => 'They arrive even when the app is closed and the phone is locked',
    'enable'   => '🔔 Turn on notifications',
    'offSub'   => 'Tap turn on, then choose "Allow" — a test notification arrives right away',
    'test'     => '📨 Test notification',
    'off'      => 'Turn off on this device',
    'sent'     => '✓ Sent — check your phone\'s notifications',
    'fail'     => '✗ Not delivered: ',
    'ios_h'    => 'On iPhone: add the app to your Home Screen first',
    'ios_sub'  => 'Apple only allows notifications for the app opened from the Home Screen — one step',
    's1'       => 'Open the site in <b>Safari</b> and tap the <b>Share</b> button',
    's2'       => 'Choose <b>"Add to Home Screen"</b>, then <b>"Add"</b>',
    's3'       => 'Open <b>First 1 Car</b> from the Home Screen, sign in, come back here and tap <b>Turn on</b>',
    'add'      => 'Add to Home Screen',
    'old_ios'  => 'This iPhone runs an old iOS — notifications need iOS 16.4 or newer. Update from Settings → General → Software Update.',
    'inapp'    => 'You are inside an app\'s browser (WhatsApp / Facebook). Open the link in <b>Safari</b> on iPhone or <b>Chrome</b> on Android.',
    'insecure' => 'The site is open without https — notifications need a secure (🔒) connection. Open it with <b>https://</b>',
    'unsup'    => 'This browser doesn\'t support notifications. On Android use <b>Chrome</b>; on iPhone add the site to the Home Screen from <b>Safari</b>.',
    'denied_h' => 'Notifications are blocked on this device',
    'den_ios'  => 'Open <b>Settings</b> → <b>Notifications</b> → <b>First 1 Car</b> → turn on <b>Allow Notifications</b>, then come back and tap turn on.',
    'den_and'  => 'Tap 🔒 next to the address → <b>Permissions</b> → <b>Notifications</b> → <b>Allow</b>, then reload and tap turn on.',
    'errs'     => ['denied' => 'Permission was denied', 'dismissed' => '"Allow" was not chosen', 'server_key' => 'Could not reach the server', 'csrf' => 'Page expired — reload it', 'bad_subscription' => 'Invalid device data', 'unsupported' => 'Device not supported', 'insecure' => 'The site needs https'],
];
?>
<section class="nf-dc" id="nfDc">
    <div class="top">
        <div class="bell">🔔</div>
        <div><h3 id="dcH"><?= $DC['h'] ?></h3><div class="st" id="dcSt"><span class="nf-spin"></span> <?= $DC['checking'] ?></div></div>
    </div>
    <div id="dcBody"></div>
    <div class="nf-msg" id="dcMsg"></div>
</section>
<script src="push_client.js"></script>
<script>
(function () {
    const CSRF = <?= json_encode($csrf) ?>, LANG = <?= json_encode($lang) ?>, DC = <?= json_encode($DC, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const $ = id => document.getElementById(id), card = $('nfDc');
    const shareSvg = '<svg viewBox="0 0 40 52" fill="none" stroke="#0a84ff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 4v28M11 13l9-9 9 9"/><path d="M13 22H7v26h26V22h-6"/></svg>';
    function msg(t, ok) { const m = $('dcMsg'); m.innerHTML = t; m.className = 'nf-msg ' + (ok ? 'ok' : 'bad'); }
    function setHead(h, st, cls) { $('dcH').innerHTML = h; $('dcSt').innerHTML = st; card.className = 'nf-dc ' + (cls || ''); }

    async function render() {
        const s = await F1Push.state();
        const body = $('dcBody');
        if (s.inAppBrowser) { setHead(DC.h, '', 'warn'); body.innerHTML = '<div class="nf-help">' + DC.inapp + '</div>'; return; }
        if (!s.secure) { setHead(DC.h, '', 'bad'); body.innerHTML = '<div class="nf-help">' + DC.insecure + '</div>'; return; }
        if (s.tooOldIOS) { setHead(DC.h, '', 'bad'); body.innerHTML = '<div class="nf-help">' + DC.old_ios + '</div>'; return; }
        if (s.needsHomeScreen) {
            setHead(DC.ios_h, DC.ios_sub, 'warn');
            body.innerHTML = '<div class="nf-steps">' +
                '<div class="nf-step"><div class="n">1</div><div class="v">' + shareSvg + '</div><p>' + DC.s1 + '</p></div>' +
                '<div class="nf-step"><div class="n">2</div><div class="v"><span class="pill">＋ ' + DC.add + '</span></div><p>' + DC.s2 + '</p></div>' +
                '<div class="nf-step"><div class="n">3</div><div class="v"><span class="app"></span></div><p>' + DC.s3 + '</p></div></div>';
            return;
        }
        if (!s.supported) { setHead(DC.h, '', 'bad'); body.innerHTML = '<div class="nf-help">' + DC.unsup + '</div>'; return; }
        if (s.permission === 'denied') {
            setHead(DC.denied_h, '', 'bad');
            body.innerHTML = '<div class="nf-help">' + (s.isIOS ? DC.den_ios : DC.den_and) + '</div><div class="nf-acts"><button class="nf-big" id="dcOn">' + DC.enable + '</button></div>';
        } else if (s.subscribed && s.permission === 'granted') {
            setHead(DC.on, DC.onSub, 'on');
            body.innerHTML = '<div class="nf-acts"><button class="nf-btn grn" id="dcTest">' + DC.test + '</button><button class="nf-btn ghost" id="dcOff">' + DC.off + '</button></div>';
        } else {
            setHead(DC.h, DC.offSub, '');
            body.innerHTML = '<div class="nf-acts"><button class="nf-big" id="dcOn">' + DC.enable + '</button></div>';
        }
        const on = $('dcOn'), test = $('dcTest'), off = $('dcOff');
        if (on) on.addEventListener('click', async () => {
            on.disabled = true; on.innerHTML = '<span class="nf-spin"></span>';
            try {
                const r = await F1Push.enable(CSRF, LANG);
                const t = r.test;   // [httpCode, error] of the test sent to this phone
                await render();
                if (t && t[0] >= 200 && t[0] < 300) msg(DC.sent, true); else msg(DC.fail + (t ? (t[0] + ' ' + (t[1] || '')) : ''), false);
            } catch (e) {
                const k = String(e && e.message || e);
                on.disabled = false; on.innerHTML = DC.enable;
                msg(DC.fail + (DC.errs[k] || k), false);
                if (k === 'denied') render();
            }
        });
        if (test) test.addEventListener('click', async () => {
            test.disabled = true;
            try { const r = await F1Push.test(CSRF, LANG); r.ok ? msg(DC.sent, true) : msg(DC.fail + ((r.errors || [])[0] || r.error || ''), false); }
            catch (e) { msg(DC.fail + e, false); }
            test.disabled = false;
        });
        if (off) off.addEventListener('click', async () => { off.disabled = true; try { await F1Push.disable(CSRF); } catch (e) {} msg('', true); render(); });
    }
    render();
})();
</script>
