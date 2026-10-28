<?php
/*
 * diagnose.php — admin-only health check for "buttons don't work" problems.
 *
 * Checks on the real server: that every system file is the latest version
 * (fingerprint), the PHP version and settings, the database columns the new
 * features need, what the server really sends for lockdown.php, and — in the
 * browser — loads the main pages and reports any script error on them.
 * Safe: it reads only, it changes nothing — except the «إصلاح» button of the
 * permissions section, which only removes duplicate permission rows and adds
 * the one-row-per-permission rule (asked for, admins only).
 */
require 'auth.php';
require 'config.php';
if (($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); exit('Admins only'); }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

/* ── repair the permission tables (only when asked) ── */
$repairMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'fix_perms' && hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    $done = [];
    foreach (['role_permissions' => 'role', 'user_permissions' => 'user_id'] as $tbl => $who) {
        try {
            // keep only the newest row of every (who, permission)
            $all = $pdo->query("SELECT * FROM $tbl")->fetchAll(PDO::FETCH_ASSOC);
            $keep = [];
            foreach ($all as $r) {
                $k = $r[$who] . '|' . $r['perm_key'];
                if (!isset($keep[$k]) || (string)($r['updated_at'] ?? '') >= (string)($keep[$k]['updated_at'] ?? '')) $keep[$k] = $r;
            }
            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM $tbl");
            $ins = $pdo->prepare("INSERT INTO $tbl ($who, perm_key, allowed) VALUES (?, ?, ?)");
            foreach ($keep as $r) $ins->execute([$r[$who], $r['perm_key'], (int)$r['allowed']]);
            $pdo->commit();
            $done[] = $tbl . ': ' . count($all) . ' → ' . count($keep) . ' صف';
            $hasPk = (bool)$pdo->query("SHOW INDEX FROM $tbl WHERE Key_name = 'PRIMARY'")->fetch();
            if (!$hasPk) { $pdo->exec("ALTER TABLE $tbl ADD PRIMARY KEY ($who, perm_key)"); $done[] = $tbl . ': + PRIMARY KEY'; }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $done[] = $tbl . ': ❌ ' . $e->getMessage();
        }
    }
    $repairMsg = implode(' · ', $done);
}

/* the fingerprints of the files as they were sent (filled in when this page was made) */
$EXPECTED = /*EXPECTED*/[
    'notify_prompt.php' => '14f46f7307b8e7cfbc2612cfb42daf1e',
    'color_helpers.php' => '5784c8aae5a70a539e7eb2b3a295f8a2',
    'qr.php' => 'cf2dfd9d80238fcf35b4aedcc5f69969',
    'vehicle_timeline.php' => '91af998a77656069cf324e5ff72d0db8',
    'other_option.js' => 'a49034c29d984b36705517dfeef04773',
    'edit_vehicle.php' => 'c5b033a2a802ffa99dd1e175899df69c',
    'add_vehicle.php' => 'd1718011bdbbedd2274bb50e92cf7e5b',
    'lockdown.php' => '85c1202599e3ac822cc121080db45f55',
    'notify_smart.php' => '3b9604cc2e195c18d0dfb3aa8c5bc356',
    'push_helpers.php' => 'e549fe35b856d64d9937833ad31cf79d',
    'notify_act.php' => 'da7427e7817ad9a7e99505760739d88d',
    'auth.php' => '2c76b8218182cb5424c959a4afa8b32b',
    'permissions.php' => 'f6c137bf58fe4f53b0dace69a50c3e35',
    'permissions_admin.php' => '8f00c197b0159c9cc3f5542f2fbc4a78',
    'incoming_cars.php' => 'b67960b37ae61dd4dd86be6b49b8ac1b',
    'receive_shipment.php' => 'a435693a7ae41ac7773e52cb807df536',
    'transfer_lock.php' => '12b4abe4a6ea914438e6ea75fbfb6986',
    'attendance.php' => '8e63cd1e2d4ed49bbc9179f1101756e8',
    'attendance_admin.php' => '7d54c40176024aa632437f25f77c15b6',
    'notifications_admin.php' => '79672f13d66dd1d0983713bf6d212e92',
    'dashboard.php' => 'a72c279c7c1e0b309b66f666443e7f92',
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
foreach (['lock_rules', 'smart_lock_user', 'lock_change_reason', 'lock_countdown_set', 'lock_countdown_scan', 'lock_countdown_update', 'lock_cd_tell', 'lock_basma_decide', 'smart_unlock', 'smart_time_label', 'push_ar_mins', 'push_ar_days', 'user_lock_active', 'smart_duty_scan', 'smart_check_scan', 'push_setting_set'] as $fn)
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
        // only a policy that limits scripts can break buttons («upgrade-insecure-requests» is harmless)
        $add('🌐 صفحة الإيقاف كما يرسلها السيرفر', 'رؤوس الاستجابة', !preg_grep('~content-security-policy:.*(script-src|default-src)~i', $hdr), $hdr ? implode(' · ', $hdr) : '—');
        $scripts = preg_match_all('~<script\b~i', $html); $rocket = stripos($html, 'rocket') !== false || stripos($html, 'text/rocketscript') !== false;
        $add('🌐 صفحة الإيقاف كما يرسلها السيرفر', 'تعديل السكربتات من الاستضافة', !$rocket, $rocket ? 'الاستضافة تعيد كتابة السكربتات (Rocket Loader / تحسين JS) — أوقفها' : 'لا (' . $scripts . ' سكربت)');
    }
}

/* 6. permissions: the tables, a write test, and — for one person — why each card button is on or off */
$G = '🔐 الصلاحيات';
$permTblOk = true;
foreach (['role_permissions' => 'role', 'user_permissions' => 'user_id'] as $tbl => $who) {
    try {
        $cols = array_column($pdo->query("SHOW COLUMNS FROM $tbl")->fetchAll(PDO::FETCH_ASSOC), 'Type', 'Field');
        $need = [$who, 'perm_key', 'allowed'];
        $miss = array_diff($need, array_keys($cols));
        $add($G, "$tbl — الأعمدة", !$miss, $miss ? 'ناقص: ' . implode(', ', $miss) . ' — الموجود: ' . implode(', ', array_keys($cols)) : implode(', ', array_map(fn($c, $t) => "$c ($t)", array_keys($cols), $cols)));
        if ($miss) { $permTblOk = false; continue; }
        $pk = $pdo->query("SHOW INDEX FROM $tbl WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
        $add($G, "$tbl — صف واحد لكل صلاحية", (bool)$pk, $pk ? 'نعم (' . implode('+', array_column($pk, 'Column_name')) . ')' : 'لا — كل حفظ يضيف صفاً جديداً، وصف قديم قد يغلب. اضغط «إصلاح» بالأسفل');
        if (!$pk) $permTblOk = false;
        $dup = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT $who, perm_key FROM $tbl GROUP BY $who, perm_key HAVING COUNT(*) > 1) d")->fetchColumn();
        $add($G, "$tbl — صفوف مكررة", $dup === 0, $dup ? "$dup صلاحية محفوظة أكثر من مرة — اضغط «إصلاح»" : 'لا يوجد');
        if ($dup) $permTblOk = false;
        if (preg_match('/\((\d+)\)/', (string)$cols['perm_key'], $mm)) {
            $longest = max(array_map('strlen', perm_all_keys()));
            $add($G, "$tbl — طول اسم الصلاحية", (int)$mm[1] >= $longest, 'المسموح ' . $mm[1] . ' حرف، أطول صلاحية ' . $longest . ' حرف');
        }
    } catch (Throwable $e) { $add($G, $tbl, false, 'الجدول غير موجود أو لا يُقرأ: ' . $e->getMessage()); $permTblOk = false; }
}
// write test (undone straight away): does saving a permission really stick?
try {
    $pdo->beginTransaction();
    $pdo->prepare("REPLACE INTO user_permissions (user_id, perm_key, allowed) VALUES (0, 'diag.test', 1)")->execute();
    $pdo->prepare("REPLACE INTO user_permissions (user_id, perm_key, allowed) VALUES (0, 'diag.test', 0)")->execute();
    $rd = $pdo->query("SELECT allowed FROM user_permissions WHERE user_id = 0 AND perm_key = 'diag.test'")->fetchAll(PDO::FETCH_COLUMN);
    $pdo->rollBack();
    $pdo->exec("DELETE FROM user_permissions WHERE user_id = 0 AND perm_key = 'diag.test'");   // in case the table can't undo
    $add($G, 'تجربة حفظ صلاحية', $rd === ['0'] || $rd === [0], $rd === ['0'] || $rd === [0] ? 'تُحفظ وتُقرأ صحيحاً' : 'بعد حفظ «سماح» ثم «منع» قُرئ: ' . json_encode($rd) . ' — الحفظ لا يستبدل القديم');
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $add($G, 'تجربة حفظ صلاحية', false, $e->getMessage()); try { $pdo->exec("DELETE FROM user_permissions WHERE user_id = 0 AND perm_key = 'diag.test'"); } catch (Throwable $e2) {} }
$add($G, 'محرك الصلاحيات الجديد', function_exists('perm_button_pages'), function_exists('perm_button_pages') ? 'موجود' : 'permissions.php قديم — ارفعه مرة أخرى');

// one person: every card button — role, saved choice, result
$diagUid = (int)($_GET['u'] ?? 0);
$people = $pdo->query("SELECT id, username, role FROM users WHERE active = 1 ORDER BY FIELD(role,'admin','manager','sales'), username")->fetchAll(PDO::FETCH_ASSOC);
$personRows = [];
if ($diagUid) {
    $pu = null; foreach ($people as $pp) if ((int)$pp['id'] === $diagUid) $pu = $pp;
    if ($pu) {
        $defs = perm_defaults()[$pu['role']] ?? [];
        $roleOv = perm_role_overrides($pdo, $pu['role']);
        $rawUser = [];
        try { $st = $pdo->prepare("SELECT perm_key, allowed FROM user_permissions WHERE user_id = ?"); $st->execute([$diagUid]); foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $rawUser[$r['perm_key']][] = (int)$r['allowed']; } catch (Throwable $e) {}
        $eff = perm_effective($pdo, $diagUid, $pu['role']);
        $catalogNames = [];
        foreach (perm_catalog() as $g) foreach ($g['perms'] as $k => $pp) $catalogNames[$k] = $pp['ar'];
        $keys = ['dash.btn_edit' => 'page.edit_vehicle', 'dash.btn_transfer' => 'page.transfer_vehicle', 'dash.btn_sell' => 'page.sold_vehicle',
                 'dash.amana_actions' => 'page.consignment_return', 'reserve.create' => '', 'reserve.cancel' => '', 'dash.wa_customer' => '', 'dash.wa_dealer' => '', 'page.vehicle_timeline' => ''];
        $yn = fn($v) => $v === null ? '—' : ($v ? '✓' : '✕');
        foreach ($keys as $k => $pg) {
            $line = function ($key) use ($defs, $roleOv, $rawUser, $eff, $yn, $catalogNames) {
                $u = $rawUser[$key] ?? null;
                return ['name' => ($catalogNames[$key] ?? $key), 'key' => $key, 'def' => $yn($defs[$key] ?? null), 'role' => $yn(array_key_exists($key, $roleOv) ? $roleOv[$key] : null),
                        'user' => $u === null ? '—' : implode(' / ', array_map(fn($x) => $x ? '✓' : '✕', $u)) . (count($u) > 1 ? ' ⚠️ مكرر' : ''), 'eff' => !empty($eff[$key])];
            };
            $a = $line($k); $b = $pg ? $line($pg) : null;
            $works = $a['eff'] && (!$b || $b['eff']);
            $personRows[] = [$a, $b, $works];
        }
    }
}

/* 7. is the dashboard served from a cache? (an old copy would hide permission changes) */
if (function_exists('curl_init')) {
    $durl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/dashboard.php?lang=ar&diag=' . time();
    $ch = curl_init($durl);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => false, CURLOPT_TIMEOUT => 15, CURLOPT_COOKIE => session_name() . '=' . session_id(), CURLOPT_SSL_VERIFYPEER => false]);
    $dr = @curl_exec($ch); $dh = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    if ($dr !== false) {
        $hdr = substr($dr, 0, $dh); $cached = preg_match('/x-litespeed-cache:\s*hit|x-cache:\s*hit|cf-cache-status:\s*hit|^age:\s*[1-9]/mi', $hdr);
        $add('🌐 التخزين المؤقت (Cache)', 'الرئيسية تُبنى من جديد في كل مرة', !$cached, $cached ? 'الاستضافة ترسل نسخة محفوظة قديمة — أوقف LiteSpeed Cache لهذه الصفحات من hPanel' : 'نعم');
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
<h2>👤 صلاحيات شخص معيّن — لماذا يظهر الزر أو لا يعمل</h2>
<form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
    <select name="u" style="height:42px;border-radius:10px;background:#0b1426;color:#e2e8f0;border:1px solid #334155;padding:0 10px;font:inherit">
        <option value="">— اختر الشخص —</option>
        <?php foreach ($people as $pp): ?><option value="<?= (int)$pp['id'] ?>" <?= (int)$pp['id'] === $diagUid ? 'selected' : '' ?>><?= htmlspecialchars($pp['username'] . ' — ' . $pp['role']) ?></option><?php endforeach; ?>
    </select>
    <button style="height:42px;padding:0 16px;border-radius:10px;border:0;background:#7c3aed;color:#fff;font:inherit;font-weight:800">افحص</button>
</form>
<?php if ($personRows): ?>
<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:560px">
<tr style="color:#c4b5fd;text-align:start"><th style="padding:6px;text-align:start">الصلاحية</th><th>الافتراضي للدور</th><th>محفوظ للدور</th><th>محفوظ لهذا الشخص</th><th>النتيجة</th><th>الزر يعمل؟</th></tr>
<?php foreach ($personRows as [$a, $b, $works]): foreach (array_filter([$a, $b]) as $i => $r): ?>
<tr style="border-top:1px solid #1e293b<?= $i ? ';color:#94a3b8' : '' ?>">
    <td style="padding:6px"><?= $i ? '↳ ' : '' ?><?= htmlspecialchars($r['name']) ?> <small style="color:#64748b;direction:ltr;display:inline-block"><?= $r['key'] ?></small></td>
    <td style="text-align:center"><?= $r['def'] ?></td><td style="text-align:center"><?= $r['role'] ?></td><td style="text-align:center"><?= $r['user'] ?></td>
    <td style="text-align:center"><?= $r['eff'] ? '✅' : '❌' ?></td>
    <?php if (!$i): ?><td rowspan="<?= $b ? 2 : 1 ?>" style="text-align:center;font-weight:900;color:<?= $works ? '#4ade80' : '#f87171' ?>"><?= $works ? 'نعم' : 'لا' ?></td><?php endif; ?>
</tr>
<?php endforeach; endforeach; ?>
</table></div>
<div style="font-size:12px;color:#94a3b8;margin-top:6px">✓ مسموح · ✕ ممنوع · — غير محفوظ (يأخذ الافتراضي). ↳ = الصفحة التي يفتحها الزر.</div>
<?php endif; ?>
<?php if ($repairMsg): ?><div class="sum ok" style="margin-top:12px">🛠️ <?= htmlspecialchars($repairMsg) ?></div><?php endif; ?>
<?php if (!$permTblOk): ?>
<form method="post" style="margin-top:12px" onsubmit="return confirm('إصلاح جداول الصلاحيات؟ يحتفظ بآخر اختيار لكل صلاحية ويحذف المكرر فقط.')">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="do" value="fix_perms">
    <button style="height:46px;padding:0 18px;border-radius:12px;border:0;background:linear-gradient(90deg,#f59e0b,#dc2626);color:#fff;font:inherit;font-weight:900">🛠️ إصلاح جداول الصلاحيات</button>
</form>
<?php endif; ?>
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
