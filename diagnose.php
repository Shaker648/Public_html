<?php
/*
 * diagnose.php — admin-only health check for "buttons don't work" problems.
 *
 * Checks on the real server: that every system file is the latest version
 * (fingerprint), the PHP version and settings, the database columns the new
 * features need, what the server really sends for lockdown.php, and — in the
 * browser — loads the main pages and reports any script error on them.
 * Safe: it reads only, it changes nothing.
 */
require 'auth.php';
require 'config.php';
if (($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); exit('Admins only'); }

/* the fingerprints of the files as they were sent (filled in when this page was made) */
$EXPECTED = /*EXPECTED*/[
    'lockdown.php' => '1a9451b729db83c8cce3a4d333d542d8',
    'notify_smart.php' => '19bf6e035e80b0dba3819f7cdb73688c',
    'push_helpers.php' => 'c7315220e4a6e256de1506ecb5017dee',
    'notify_act.php' => 'da7427e7817ad9a7e99505760739d88d',
    'auth.php' => '2c76b8218182cb5424c959a4afa8b32b',
    'permissions.php' => 'd1c2a9e20cf174c220224e66d8caac32',
    'permissions_admin.php' => 'c3978e52ce0e8c49a79a9a82363e54d0',
    'incoming_cars.php' => 'b67960b37ae61dd4dd86be6b49b8ac1b',
    'receive_shipment.php' => 'a435693a7ae41ac7773e52cb807df536',
    'transfer_lock.php' => '12b4abe4a6ea914438e6ea75fbfb6986',
    'attendance.php' => '8e63cd1e2d4ed49bbc9179f1101756e8',
    'attendance_admin.php' => '7d54c40176024aa632437f25f77c15b6',
    'notifications_admin.php' => '1c3a75bdaa89bc3fab82a06e0e19f519',
    'dashboard.php' => '2a12d2523c121cf956bc76fc691c1951',
    'prices.php' => '0a1e32c255c2bacab37f16fee0563a23',
    'pricing_helpers.php' => '8a07e7ac7fba1194fd3fce1df9b23d9b',
    'pwa_head.php' => '5bcf1664b82d3459279445d292fbed1f',
    'notify_style.php' => '398c976110ac36a1b23c825f3f228746',
    'stock_check.php' => '2134d2a172798933caeb84f3c4e9a8ac',
    'transfer_receive.php' => '8f93c29aae294380af70381ececd7a5c',
    'notify_popup.php' => '2089e46c934442dd507915216323232b',
    'notify_feed.php' => '2356bb843cbcfb236f54e7da273ce16b',
    'chatbot_widget.php' => '8636fc1054f74536036aa874cb44464c',
    'chatbot_api.php' => 'e41293f9eda11057eb9b34f9b249daa3',
    'sw.js' => '8bba089ed2f6ffdaeb7813240b068975',
    'push_client.js' => 'c957bb62b5f9c54b2866224d99b3271f',
]/*/EXPECTED*/;

$rows = [];
$add = function (string $group, string $name, bool $ok, string $detail) use (&$rows) { $rows[] = [$group, $name, $ok, $detail]; };

/* 1. files */
foreach ($EXPECTED as $f => $md5) {
    $path = __DIR__ . '/' . $f;
    if (!is_file($path)) { $add('📁 الملفات', $f, false, 'الملف غير موجود على السيرفر — ارفعه'); continue; }
    $got = md5_file($path);
    $add('📁 الملفات', $f, $got === $md5, $got === $md5 ? 'أحدث نسخة ✓' : 'نسخة قديمة أو مختلفة — ارفع هذا الملف مرة أخرى (آخر تعديل على السيرفر: ' . date('Y-m-d H:i', filemtime($path)) . ')');
}

/* 2. PHP */
$add('⚙️ PHP', 'الإصدار', version_compare(PHP_VERSION, '8.0', '>='), PHP_VERSION . (version_compare(PHP_VERSION, '8.0', '>=') ? '' : ' — النظام يحتاج PHP 8 أو أحدث (غيّره من hPanel)'));
foreach (['mbstring', 'pdo_mysql', 'openssl', 'curl', 'json'] as $ext) $add('⚙️ PHP', 'إضافة ' . $ext, extension_loaded($ext), extension_loaded($ext) ? 'موجودة' : 'غير مفعّلة — فعّلها من hPanel ← PHP Extensions');
$add('⚙️ PHP', 'display_errors', true, (string)ini_get('display_errors') . ' · output_buffering=' . ini_get('output_buffering') . ' · opcache=' . (function_exists('opcache_get_status') && @opcache_get_status(false) ? 'on' : 'off'));

/* 3. functions the lockdown page needs */
try { require_once __DIR__ . '/notify_smart.php'; } catch (Throwable $e) { $add('🧩 الدوال', 'notify_smart.php', false, $e->getMessage()); }
foreach (['lock_rules', 'smart_lock_user', 'lock_change_reason', 'lock_countdown_set', 'lock_countdown_scan', 'lock_countdown_update', 'lock_basma_decide', 'smart_unlock', 'smart_time_label', 'push_ar_mins', 'push_ar_days', 'user_lock_active', 'smart_duty_scan', 'smart_check_scan', 'push_setting_set'] as $fn)
    $add('🧩 الدوال', $fn, function_exists($fn), function_exists($fn) ? 'موجودة' : 'غير موجودة — ملف notify_smart.php أو push_helpers.php قديم');

/* 4. database columns */
$col = function (string $t, string $c) use ($pdo): bool { try { $pdo->query("SELECT `$c` FROM `$t` LIMIT 0"); return true; } catch (Throwable $e) { return false; } };
try { if (function_exists('push_tables')) push_tables($pdo); } catch (Throwable $e) { $add('🗄️ قاعدة البيانات', 'push_tables', false, $e->getMessage()); }
foreach ([['user_locks', 'kind'], ['user_locks', 'basma'], ['user_locks', 'att_log_id'], ['user_locks', 'locked_by'], ['attendance_logs', 'stop_reason'], ['notify_log', 'ref'], ['notify_inbox', 'tok'], ['incoming_colors', 'interior'], ['user_locks', 'cd_until'], ['user_locks', 'cd_msg']] as [$t, $c])
    $add('🗄️ قاعدة البيانات', "$t.$c", $col($t, $c), $col($t, $c) ? 'موجود' : 'غير موجود');

/* 5. what the server really sends for lockdown.php (with this login) */
$live = ['ok' => null];
if (function_exists('curl_init')) {
    $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/lockdown.php?lang=ar';
    $cookie = session_name() . '=' . session_id();
    session_write_close();
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 15, CURLOPT_COOKIE => $cookie, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_ENCODING => '']);
    $resp = curl_exec($ch);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) $add('🌐 صفحة الإيقاف كما يرسلها السيرفر', 'تحميل', false, 'تعذّر: ' . $err);
    else {
        $head = substr($resp, 0, $hs); $html = substr($resp, $hs);
        $add('🌐 صفحة الإيقاف كما يرسلها السيرفر', 'كود الاستجابة', $code === 200, (string)$code);
        $add('🌐 صفحة الإيقاف كما يرسلها السيرفر', 'الصفحة كاملة حتى النهاية', (bool)preg_match('~</html>\s*$~i', $html), preg_match('~</html>\s*$~i', $html) ? 'نعم' : 'لا — الصفحة تتوقف في المنتصف (خطأ في السيرفر). آخر ما وصل: ' . htmlspecialchars(mb_substr(trim(strip_tags(substr($html, -600))), -200)));
        $add('🌐 صفحة الإيقاف كما يرسلها السيرفر', 'كود الأزرار موجود', strpos($html, 'function ldEditReason') !== false, strpos($html, 'function ldEditReason') !== false ? 'نعم' : 'لا — الملف على السيرفر ليس آخر نسخة أو الصفحة انقطعت');
        preg_match_all('~(Fatal error|Parse error|Warning|Notice|Deprecated|Uncaught)[^<\n]{0,300}~', $html, $m);
        $add('🌐 صفحة الإيقاف كما يرسلها السيرفر', 'أخطاء PHP داخل الصفحة', !$m[0], $m[0] ? implode(' | ', array_slice(array_unique($m[0]), 0, 4)) : 'لا يوجد');
        $hdr = [];
        foreach (explode("\n", $head) as $l) if (preg_match('~^(content-security-policy|x-litespeed-cache|server|cf-|x-hcdn|content-encoding|x-turbo|x-cache)~i', trim($l))) $hdr[] = trim($l);
        $add('🌐 صفحة الإيقاف كما يرسلها السيرفر', 'رؤوس الاستجابة', !preg_grep('~content-security-policy~i', $hdr), $hdr ? implode(' · ', $hdr) : '—');
        $scripts = preg_match_all('~<script\b~i', $html); $rocket = stripos($html, 'rocket') !== false || stripos($html, 'text/rocketscript') !== false;
        $add('🌐 صفحة الإيقاف كما يرسلها السيرفر', 'تعديل السكربتات من الاستضافة', !$rocket, $rocket ? 'الاستضافة تعيد كتابة السكربتات (Rocket Loader / تحسين JS) — أوقفها' : 'لا (' . $scripts . ' سكربت)');
    }
}

$bad = count(array_filter($rows, fn($r) => !$r[2]));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>فحص النظام — First 1 Car</title>
<style>
body{margin:0;background:#020617;color:#e2e8f0;font-family:Tahoma,'Segoe UI',sans-serif}
.w{max-width:900px;margin:auto;padding:18px 14px 60px}
h1{font-size:22px;margin:0 0 6px}.sum{padding:14px;border-radius:14px;font-weight:800;margin:12px 0 18px}
.sum.ok{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.5);color:#bbf7d0}.sum.bad{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.5);color:#fecaca}
h2{font-size:15px;margin:20px 0 8px;color:#c4b5fd}
.r{display:flex;gap:10px;padding:9px 12px;border-radius:10px;background:rgba(255,255,255,.03);margin-bottom:5px;font-size:13.5px;align-items:flex-start}
.r b{min-width:190px;font-family:monospace;direction:ltr;text-align:left}.r span{flex:1;word-break:break-word}
.r.no{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.4)}.r.no span{color:#fecaca;font-weight:700}
.i{width:24px;text-align:center}
@media (max-width:600px){.r{flex-wrap:wrap}.r b{min-width:0;flex:1}.r span:last-child{flex-basis:100%;padding-inline-start:34px}}
iframe{position:absolute;width:390px;height:700px;left:-9999px;top:0;border:0}
</style>
</head>
<body>
<div class="w">
<h1>🩺 فحص النظام</h1>
<div style="color:#94a3b8;font-size:13px">صوّر هذه الصفحة كاملة (أو انسخ النص) وأرسلها — توضّح السبب بالضبط. هذه الصفحة تقرأ فقط ولا تغيّر شيئاً.</div>
<div class="sum <?= $bad ? 'bad' : 'ok' ?>" id="sum"><?= $bad ? '❌ وُجدت ' . $bad . ' مشكلة على السيرفر — مكتوبة بالأحمر بالأسفل' : '✅ السيرفر سليم — جارٍ فحص الأزرار في المتصفح…' ?></div>
<?php $g = ''; foreach ($rows as [$group, $name, $ok, $detail]): if ($group !== $g): $g = $group; ?><h2><?= $group ?></h2><?php endif; ?>
<div class="r <?= $ok ? '' : 'no' ?>"><span class="i"><?= $ok ? '✅' : '❌' ?></span><b><?= htmlspecialchars($name) ?></b><span><?= $detail ?></span></div>
<?php endforeach; ?>
<h2>🖱️ الصفحات في هذا المتصفح (أخطاء السكربت)</h2>
<div id="pages"></div>
<div style="margin-top:16px;color:#64748b;font-size:12px;direction:ltr">UA: <span id="ua"></span></div>
</div>
<script>
document.getElementById('ua').textContent = navigator.userAgent;
var PAGES = [['lockdown.php?lang=ar', 'ldEditReason'], ['dashboard.php?lang=ar', ''], ['incoming_cars.php?lang=ar', 'icSend'], ['permissions_admin.php?lang=ar', ''], ['notifications_admin.php?lang=ar', ''], ['stock_check.php?lang=ar', ''], ['prices.php?lang=ar', '']];
var box = document.getElementById('pages'), anyBad = <?= $bad ? 'true' : 'false' ?>;
function row(ok, name, txt) { var d = document.createElement('div'); d.className = 'r' + (ok ? '' : ' no'); d.innerHTML = '<span class="i">' + (ok ? '✅' : '❌') + '</span><b></b><span></span>'; d.querySelector('b').textContent = name; d.querySelector('span:last-child').textContent = txt; box.appendChild(d); if (!ok) anyBad = true; }
(function next(i) {
    if (i >= PAGES.length) { var s = document.getElementById('sum'); s.className = 'sum ' + (anyBad ? 'bad' : 'ok'); s.textContent = anyBad ? '❌ وُجدت مشكلة — مكتوبة بالأحمر بالأسفل' : '✅ كل شيء سليم على السيرفر وفي هذا المتصفح'; return; }
    var url = PAGES[i][0], fn = PAGES[i][1], name = url.split('?')[0];
    fetch(url, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
        return r.text().then(function (html) { return { r: r, html: html }; });
    }).then(function (x) {
        var info = [], html = x.html;
        if (x.r.status !== 200) info.push('كود ' + x.r.status);
        if (x.r.redirected && /index\.php/.test(x.r.url)) info.push('تحويل لصفحة الدخول');
        if (!/<\/html>\s*$/i.test(html)) info.push('الصفحة تتوقف في المنتصف — آخر ما وصل: «' + html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').slice(-160) + '»');
        var php = html.match(/(Fatal error|Parse error|Uncaught|Warning|Deprecated|Notice)[^<\n]{0,220}/);
        if (php) info.push('PHP: ' + php[0]);
        var base = location.href.replace(/[^\/]*$/, '');
        var probe = '<base href="' + base + '"><script>window.__errs=[];addEventListener("error",function(e){if(!e.message)return;__errs.push((e.message||"error")+(e.lineno?" (سطر "+e.lineno+")":""))},true);<\/script>';
        var doc = /<head[^>]*>/i.test(html) ? html.replace(/<head[^>]*>/i, function (m) { return m + probe; }) : probe + html;
        var f = document.createElement('iframe'), done = false;
        var finish = function () {
            if (done) return; done = true;
            try {
                var w = f.contentWindow;
                (w.__errs || []).forEach(function (e) { info.push('JS: ' + e); });
                if (fn && typeof w[fn] !== 'function') info.push('كود الأزرار (' + fn + ') لم يُحمَّل');
            } catch (e) { info.push('تعذّر الفحص: ' + e.message); }
            row(!info.length, name, info.length ? info.join(' | ') : 'تعمل بدون أخطاء');
            f.remove(); next(i + 1);
        };
        f.addEventListener('load', function () { setTimeout(finish, 2500); });
        setTimeout(finish, 15000);
        document.body.appendChild(f);
        f.srcdoc = doc;
    }).catch(function (e) { row(false, name, 'تعذّر التحميل: ' + e); next(i + 1); });
})(0);
</script>
</body>
</html>
