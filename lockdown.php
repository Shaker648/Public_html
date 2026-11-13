<?php
/*
 * lockdown.php — إيقاف النظام (reached from the permissions page).
 *
 *   • stop the system for anyone, with a reason or without one
 *   • choose: stop their clock-in (بصمة) at the moment of the stop — the reason
 *     is written into their attendance — or keep it running during the stop
 *   • every stop (manual, surprise stock check, transfers not confirmed) is
 *     told to every admin and the people chosen here; for automatic stops the
 *     admin decides about the clock-in (from here or right on the phone)
 *   • open the system again, and see the history of stops
 */
require 'auth.php';
require 'config.php';
require_once __DIR__ . '/notify_smart.php';

perm_require('page.lockdown');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');   // always the latest page (no old copy from a cache)
header('X-LiteSpeed-Cache-Control: no-cache');

$lang = ($_GET['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
$ar   = $lang === 'ar';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$me   = (string)$_SESSION['username'];
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';   // only admins know (and choose) whether a countdown is fake
push_tables($pdo);

/* ─── actions ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($csrf, (string)($in['csrf'] ?? ''))) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }
    try {
        switch ($in['action'] ?? '') {
            case 'ping':     // 📍 ask where a clocked-in person is right now (admins)
                if (!$isAdmin || !function_exists('loc_ping_create')) break;
                $pid = loc_ping_create($pdo, (int)($in['uid'] ?? 0), $me);
                echo json_encode(['ok' => $pid > 0, 'error' => $pid ? '' : 'busy']); exit;
            case 'lock':
                $n = 0;
                foreach (array_unique(array_map('intval', (array)($in['uids'] ?? []))) as $u) {
                    if (user_lock_active($pdo, $u)) continue;
                    $lid = smart_lock_user($pdo, $u, 'manual', mb_substr(trim((string)($in['reason'] ?? '')), 0, 1500), $me, [],
                                        ($in['basma'] ?? '') === 'stop' ? 'stop' : 'keep', array_map('intval', (array)($in['watch'] ?? [])));
                    if (!$lid) continue;
                    $n++;
                    if (($in['basma'] ?? '') === 'countdown') lock_countdown_set($pdo, $lid, (int)($in['cd_mins'] ?? 0), $isAdmin && !empty($in['cd_k']), $me, (string)($in['cd_msg'] ?? ''));
                }
                echo json_encode(['ok' => $n > 0, 'n' => $n]); exit;
            case 'countdown':
                $fk = !empty($in['k']);
                if (!$isAdmin) {   // not an admin: can't see or change the type — keep it as it is (new ones are real)
                    $cf = $pdo->prepare("SELECT cd_fake FROM user_locks WHERE id = ?"); $cf->execute([(int)($in['lock'] ?? 0)]);
                    $fk = !empty($in['edit']) && (bool)$cf->fetchColumn();
                }
                if (!empty($in['edit'])) {
                    echo json_encode(['ok' => lock_countdown_update($pdo, (int)($in['lock'] ?? 0), isset($in['mins']) && $in['mins'] !== null ? (int)$in['mins'] : null, $fk, (string)($in['msg'] ?? ''), $me)]); exit;
                }
                echo json_encode(['ok' => lock_countdown_set($pdo, (int)($in['lock'] ?? 0), (int)($in['mins'] ?? 0), $fk, $me, (string)($in['msg'] ?? ''))]); exit;
            case 'reason':
                echo json_encode(['ok' => lock_change_reason($pdo, (int)($in['lock'] ?? 0), (string)($in['reason'] ?? ''), $me)]); exit;
            case 'unlock':
                smart_unlock($pdo, (int)($in['uid'] ?? 0), $me);
                echo json_encode(['ok' => true]); exit;
            case 'basma':
                echo json_encode(['ok' => lock_basma_decide($pdo, (int)($in['lock'] ?? 0), (string)($in['how'] ?? ''), $me)]); exit;
            case 'rules':
                push_setting_set($pdo, 'lock_rules', json_encode([
                    'watchers'   => array_values(array_unique(array_map('intval', (array)($in['watchers'] ?? [])))),
                    'auto_basma' => in_array($in['auto_basma'] ?? '', ['ask', 'stop', 'keep'], true) ? $in['auto_basma'] : 'ask',
                ]), $me);
                echo json_encode(['ok' => true]); exit;
        }
    } catch (Throwable $e) {
        error_log('lockdown: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'server']); exit;
    }
    echo json_encode(['ok' => false, 'error' => 'unknown']); exit;
}

/* ─── page data ─── */
smart_duty_scan($pdo); smart_check_scan($pdo); lock_countdown_scan($pdo);
if (function_exists('loc_ping_scan')) loc_ping_scan($pdo);   // location checks nobody answered in time
$rules  = lock_rules($pdo);
$people = $pdo->query("SELECT id, username, role FROM users WHERE active = 1 ORDER BY FIELD(role,'admin','manager','sales'), username")->fetchAll(PDO::FETCH_ASSOC);
$here = [];
try { foreach ($pdo->query("SELECT user_id, branch_name FROM attendance_logs WHERE status = 'active'") as $r) $here[(int)$r['user_id']] = $r['branch_name']; } catch (Throwable $e) {}
$active = $pdo->query("SELECT l.*, u.username, TIMESTAMPDIFF(SECOND, l.locked_at, NOW()) AS age, TIMESTAMPDIFF(SECOND, NOW(), l.cd_until) AS cd_left FROM user_locks l JOIN users u ON u.id = l.user_id
                       WHERE l.unlocked_at IS NULL ORDER BY l.locked_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$lockedIds = array_map('intval', array_column($active, 'user_id'));
$history = $pdo->query("SELECT l.*, u.username FROM user_locks l JOIN users u ON u.id = l.user_id WHERE l.unlocked_at IS NOT NULL ORDER BY l.locked_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

$kindLbl = $ar ? ['manual' => '✋ يدوي', 'check' => '📋 جرد مفاجئ', 'transfer' => '🚚 استلام', 'chassis' => '🔑 إدخال غير صحيح'] : ['manual' => '✋ Manual', 'check' => '📋 Stock check', 'transfer' => '🚚 Receiving', 'chassis' => '🔑 Wrong entry'];
/* admins only: what was typed against the real chassis number */
$ctxLbl = $ar ? ['receive' => '🚚 استلام', 'check' => '📋 جرد', 'count' => '🔢 عدد السيارات'] : ['receive' => '🚚 Receiving', 'check' => '📋 Stock check', 'count' => '🔢 Car count'];
$chTries = function (array $rows) use ($ar, $ctxLbl, $pdo, $lang) {
    if (!$rows) return '';
    $h = '<div class="ld-ch"><div class="ld-ch-t">🔑 ' . ($ar ? 'ما أدخله مقابل الصحيح (يظهر للإدارة فقط)' : 'What was typed against the right answer (admins only)') . '</div>';
    foreach ($rows as $t) {
        $h .= '<div class="ld-ch-r' . ((int)$t['ok'] ? ' ok' : '') . '"><div class="c">' . ($t['ctx'] === 'count' ? '🔢 ' : '🚗 ') . htmlspecialchars((string)$t['label']) . ' <small>' . htmlspecialchars(($ctxLbl[$t['ctx']] ?? $t['ctx'])
            . ((string)$t['place'] !== '' ? ' · 📍 ' . push_branch_label($pdo, (string)$t['place'], $lang) : '') . ' · ' . date('Y-m-d', strtotime($t['created_at'])) . ' ' . smart_time_label(strtotime($t['created_at']), $lang)
            . (isset($t['username']) ? ' · 👤 ' . $t['username'] : '')) . '</small></div>'
            . '<div class="v"><span>' . ($ar ? 'أدخل' : 'Typed') . '</span><b class="bad">' . htmlspecialchars((string)$t['typed']) . '</b></div>'
            . '<div class="v"><span>' . ($ar ? 'الحقيقي' : 'Real') . '</span><b class="good">' . htmlspecialchars((string)$t['real_chassis']) . '</b></div></div>';
    }
    return $h . '</div>';
};
/* ── the record (admins only): everything sensitive in one timeline, last 30 days ── */
$box = [];
if ($isAdmin) {
    $bx = function (string $sql, callable $map) use ($pdo, &$box) { try { foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) { $e = $map($r); if ($e) $box[] = $e; } } catch (Throwable $e) { error_log('lockdown box: ' . $e->getMessage()); } };
    $brb = fn($n) => push_branch_label($pdo, (string)$n, $lang);
    $fence = 50;
    try { $fs = json_decode((string)$pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'attendance_settings'")->fetchColumn(), true); if (isset($fs['fence'])) $fence = max(10, (int)$fs['fence']); } catch (Throwable $e) {}
    $bx("SELECT l.locked_at AS t, u.username, l.kind, l.reason, l.unlocked_at FROM user_locks l JOIN users u ON u.id = l.user_id WHERE l.locked_at >= NOW() - INTERVAL 30 DAY",
        fn($r) => ['t' => $r['t'], 'u' => $r['username'], 'k' => 'stop', 'ic' => '⛔', 'x' => ($ar ? 'إيقاف النظام: ' : 'System stopped: ') . trim(strstr($kindLbl[$r['kind']] ?? $r['kind'], ' ')), 'd' => (string)$r['reason']]);
    try { chassis_tables($pdo); } catch (Throwable $e) {}
    $bx("SELECT created_at AS t, username, ctx, label, place, typed, real_chassis FROM chassis_tries WHERE ok = 0 AND created_at >= NOW() - INTERVAL 30 DAY",
        fn($r) => ['t' => $r['t'], 'u' => $r['username'], 'k' => 'wrong', 'ic' => $r['ctx'] === 'count' ? '🔢' : '🔑',
                   'x' => $r['ctx'] === 'count' ? ($ar ? 'عدد سيارات غير صحيح' : 'Wrong car count') : ($ar ? 'رقم شاسيه غير صحيح' : 'Wrong chassis number'),
                   'd' => ($ar ? 'أدخل ' : 'typed ') . $r['typed'] . ($ar ? ' — الصحيح ' : ' — right ') . $r['real_chassis'] . ($r['ctx'] === 'count' ? '' : ' · ' . $r['label']) . ((string)$r['place'] !== '' ? ' · 📍 ' . $brb($r['place']) : '')]);
    $bx("SELECT a.clock_in AS t, u.username, a.branch_name, a.in_dist_m FROM attendance_logs a JOIN users u ON u.id = a.user_id WHERE a.clock_in >= NOW() - INTERVAL 30 DAY AND a.in_dist_m > " . (int)$fence,
        fn($r) => ['t' => $r['t'], 'u' => $r['username'], 'k' => 'far', 'ic' => '📍', 'x' => $ar ? 'تسجيل حضور من مكان بعيد عن الفرع' : 'Clocked in far from the branch',
                   'd' => $brb($r['branch_name']) . ' · ' . ((int)$r['in_dist_m'] < 1000 ? (int)$r['in_dist_m'] . ($ar ? ' م' : ' m') : round((int)$r['in_dist_m'] / 1000, 1) . ($ar ? ' كم' : ' km'))]);
    $bx("SELECT a.clock_out AS t, u.username, a.out_branch_name, a.branch_name, a.out_dist_m FROM attendance_logs a JOIN users u ON u.id = a.user_id WHERE a.clock_out >= NOW() - INTERVAL 30 DAY AND a.auto_closed = 0 AND a.out_dist_m > " . (int)$fence,
        fn($r) => ['t' => $r['t'], 'u' => $r['username'], 'k' => 'far', 'ic' => '📍', 'x' => $ar ? 'تسجيل انصراف من مكان بعيد عن الفرع' : 'Clocked out far from the branch',
                   'd' => $brb($r['out_branch_name'] ?: $r['branch_name']) . ' · ' . ((int)$r['out_dist_m'] < 1000 ? (int)$r['out_dist_m'] . ($ar ? ' م' : ' m') : round((int)$r['out_dist_m'] / 1000, 1) . ($ar ? ' كم' : ' km'))]);
    $bx("SELECT a.clock_in AS t, u.username, a.branch_name FROM attendance_logs a JOIN users u ON u.id = a.user_id WHERE a.clock_in >= NOW() - INTERVAL 30 DAY AND a.auto_closed = 1",
        fn($r) => ['t' => $r['t'], 'u' => $r['username'], 'k' => 'forgot', 'ic' => '⏰', 'x' => $ar ? 'نسي تسجيل الانصراف' : 'Forgot to clock out', 'd' => $brb($r['branch_name'])]);
    $bx("SELECT ti.reported_at AS t, ti.reported_by AS username, ti.note, m.to_branch, c.brand, c.model FROM transfer_issues ti JOIN movements m ON m.id = ti.movement_id JOIN cars c ON c.id = m.car_id WHERE ti.reported_at >= NOW() - INTERVAL 30 DAY",
        fn($r) => ['t' => $r['t'], 'u' => $r['username'], 'k' => 'miss', 'ic' => '❗', 'x' => $ar ? 'بلاغ «لم تصل»' : '"Did not arrive" report', 'd' => trim($r['brand'] . ' ' . $r['model']) . ' · 📍 ' . $brb($r['to_branch']) . ((string)$r['note'] !== '' ? ' · 📝 ' . $r['note'] : '')]);
    if (function_exists('loc_ping_tables')) { try { loc_ping_tables($pdo); } catch (Throwable $e) {} }
    $bx("SELECT p.asked_at AS t, u.username, p.branch, p.status, p.dist_m FROM loc_pings p JOIN users u ON u.id = p.user_id WHERE p.asked_at >= NOW() - INTERVAL 30 DAY AND p.status IN ('far', 'missed', 'denied')",
        fn($r) => ['t' => $r['t'], 'u' => $r['username'], 'k' => 'loc', 'ic' => '📡',
                   'x' => $ar ? ['far' => 'تأكيد تواجد: بعيد عن الفرع', 'missed' => 'تأكيد تواجد: لم يرد', 'denied' => 'تأكيد تواجد: الهاتف رفض إرسال الموقع'][$r['status']]
                              : ['far' => 'Location check: far from the branch', 'missed' => 'Location check: no answer', 'denied' => 'Location check: phone refused'][$r['status']],
                   'd' => $brb($r['branch']) . ($r['dist_m'] !== null ? ' · ' . ((int)$r['dist_m'] < 1000 ? (int)$r['dist_m'] . ($ar ? ' م' : ' m') : round((int)$r['dist_m'] / 1000, 1) . ($ar ? ' كم' : ' km')) : '')]);
    usort($box, fn($a, $b) => strcmp((string)$b['t'], (string)$a['t']));
    $boxAll = count($box);
    $boxBy = array_count_values(array_column($box, 'u')); arsort($boxBy);
    $box = array_slice($box, 0, 150);
}
$looseTries = [];
if ($isAdmin) try {
    chassis_tables($pdo);
    $looseTries = $pdo->query("SELECT * FROM chassis_tries WHERE ok = 0 AND lock_id IS NULL ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
$basmaLbl = $ar ? ['stopped' => '⏹ أُوقفت البصمة', 'running' => '▶ البصمة مستمرة', 'pending' => '❓ بانتظار قرارك', 'none' => '— لم يكن حاضراً']
                : ['stopped' => '⏹ Clock-in stopped', 'running' => '▶ Clock-in running', 'pending' => '❓ Waiting for you', 'none' => '— not clocked in'];
$ago = function ($s) use ($ar): string {
    $s = max(0, (int)$s);
    if ($s < 60) return $ar ? 'الآن' : 'just now';
    if ($s < 3600) { $m = intdiv($s, 60); return $ar ? 'منذ ' . push_ar_mins($m) : $m . ' min ago'; }
    if ($s < 86400) { $h = intdiv($s, 3600); return $ar ? 'منذ ' . $h . ' ساعة' : $h . ' h ago'; }
    $d = intdiv($s, 86400); return $ar ? 'منذ ' . push_ar_days($d) : $d . ' days ago';
};
$fmt = fn($dt) => $dt ? date('Y-m-d', strtotime($dt)) . ' ' . smart_time_label(strtotime($dt), $lang) : '';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $ar ? 'rtl' : 'ltr' ?>">
<head>
<script>
/* shows any script error on the page itself, so a broken button is never silent */
window.addEventListener('error', function (e) {
    if (!e.message) return;   // a picture or font that failed to load is not a script error
    try {
        var m = document.getElementById('ldErr') || document.body && document.body.appendChild(Object.assign(document.createElement('div'), { id: 'ldErr' }));
        if (!m) return;
        m.setAttribute('style', 'display:block;position:fixed;bottom:10px;left:10px;right:10px;z-index:2147483647;padding:10px;border-radius:10px;background:#7f1d1d;color:#fff;font:12px monospace;direction:ltr;white-space:pre-wrap');
        m.textContent += '⚠️ ' + (e.message || 'error') + (e.filename ? ' @ ' + e.filename.split('/').pop() + ':' + e.lineno : '') + '\n';
    } catch (x) {}
}, true);
</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= $ar ? 'إيقاف النظام' : 'Stop the system' ?> — First 1 Car</title>
<?php include __DIR__ . '/pwa_head.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<?php include __DIR__ . '/notify_style.php'; ?>
<style>
.ld-l{display:block;font-size:12.5px;font-weight:800;color:var(--mut);margin:14px 0 8px}
.ld-l:first-child{margin-top:0}
.ld-ppl{display:flex;flex-wrap:wrap;gap:6px}
.ld-p{height:38px;padding:0 13px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--txt);font:inherit;font-size:13px;font-weight:800;cursor:pointer}
.ld-p small{font-size:10.5px;color:#86efac;margin-inline-start:4px}
.ld-p.on{background:linear-gradient(90deg,#b91c1c,#7f1d1d);border-color:transparent;color:#fff}
.ld-p.w.on{background:linear-gradient(90deg,#f59e0b,#eab308);color:#1c1917}
.ld-p:disabled{opacity:.45;cursor:default}
.ld-in{width:100%;min-height:150px;line-height:1.8;border-radius:14px;border:1px solid var(--line);background:#0b1426;color:var(--txt);font:inherit;font-size:14px;padding:10px 12px;resize:vertical}
.ld-opts{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.ld-o{display:flex;gap:10px;align-items:flex-start;padding:12px;border-radius:14px;border:1px solid var(--line);background:rgba(255,255,255,.03);cursor:pointer}
.ld-o input{margin-top:3px;accent-color:#ef4444}
.ld-o b{display:block;font-size:14px}.ld-o small{display:block;font-size:12px;color:var(--mut);font-weight:700;margin-top:2px;line-height:1.6}
.ld-o.on{border-color:rgba(239,68,68,.6);background:rgba(239,68,68,.08)}
.ld-fix{font-size:12px;color:#fde68a;font-weight:800;margin-top:6px}
.ld-go{margin-top:16px;width:100%;height:54px;border:0;border-radius:16px;background:linear-gradient(90deg,#b91c1c,#7f1d1d);color:#fff;font:inherit;font-size:17px;font-weight:900;cursor:pointer;box-shadow:0 12px 30px rgba(185,28,28,.35)}
.ld-row{display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:12px 14px;border-radius:14px;border:1px solid rgba(239,68,68,.4);background:rgba(239,68,68,.06);margin-bottom:8px}
.ld-row .i{flex:1;min-width:220px}.ld-row b{font-size:15px}.ld-row small{display:block;font-size:12.5px;color:var(--mut);font-weight:700;margin-top:3px}
.ld-row small.r{color:#fecaca}
.ld-k{font-size:11.5px;font-weight:900;padding:3px 9px;border-radius:999px;background:rgba(255,255,255,.07);margin-inline-start:6px}
.ld-b{font-size:12px;font-weight:900;padding:4px 10px;border-radius:999px;background:rgba(255,255,255,.06)}
.ld-b.pending{background:rgba(250,204,21,.15);color:#fde68a}.ld-b.stopped{background:rgba(239,68,68,.15);color:#fca5a5}.ld-b.running{background:rgba(34,197,94,.15);color:#86efac}
.ld-acts{display:flex;flex-wrap:wrap;gap:6px}
.ld-acts button{height:36px;padding:0 12px;border-radius:10px;border:1px solid var(--line);background:rgba(255,255,255,.05);color:var(--txt);font:inherit;font-size:12.5px;font-weight:900;cursor:pointer}
.ld-acts .cd{border-color:rgba(250,204,21,.55);color:#fde68a;background:rgba(250,204,21,.08)}
.ld-cd{font-size:12.5px;font-weight:900;padding:5px 11px;border-radius:999px;background:rgba(250,204,21,.14);color:#fde68a;border:1px solid rgba(250,204,21,.4)}
.bx-ppl,.bx-kinds{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px}
.bx-p,.bx-k{height:34px;padding:0 12px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--txt);font:inherit;font-size:12.5px;font-weight:800;cursor:pointer}
.bx-p b{color:#fca5a5;margin-inline-start:4px}.bx-p.on{background:linear-gradient(90deg,#dc2626,#9333ea);border-color:transparent;color:#fff}.bx-p.on b{color:#fff}
.bx-k.on{background:rgba(250,204,21,.18);border-color:rgba(250,204,21,.6);color:#fde68a}
.bx-list{max-height:520px;overflow:auto;border-radius:14px;border:1px solid rgba(255,255,255,.07)}
.bx-e{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.05);border-inline-start:3px solid #64748b}
.bx-e[hidden]{display:none}
.bx-e.stop{border-inline-start-color:#ef4444}.bx-e.wrong{border-inline-start-color:#a3e635}.bx-e.far{border-inline-start-color:#f59e0b}.bx-e.forgot{border-inline-start-color:#38bdf8}.bx-e.miss{border-inline-start-color:#c084fc}.bx-e.loc{border-inline-start-color:#2dd4bf}
.lp-ppl{display:flex;flex-wrap:wrap;gap:8px}
.lp-p{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.03);font-size:13.5px;font-weight:800}
.lp-p small{color:var(--mut);font-weight:700}.lp-p button{height:32px;padding:0 11px;border-radius:9px;border:0;background:linear-gradient(90deg,#0d9488,#0ea5e9);color:#fff;font:inherit;font-size:12.5px;font-weight:900;cursor:pointer}
.lp-p button:disabled{opacity:.5}
.lp-r{display:flex;gap:10px;align-items:center;padding:8px 2px;border-top:1px solid rgba(255,255,255,.06);font-size:13px;font-weight:700}
.lp-r b{flex:1}.lp-r time{color:var(--mut);font-size:11.5px;white-space:nowrap}
.lp-s{font-size:11.5px;font-weight:900;padding:3px 9px;border-radius:999px;white-space:nowrap}
.lp-s.ok{background:rgba(34,197,94,.15);color:#86efac}.lp-s.far,.lp-s.denied{background:rgba(239,68,68,.16);color:#fca5a5}.lp-s.missed{background:rgba(245,158,11,.16);color:#fcd34d}.lp-s.pending{background:rgba(56,189,248,.15);color:#7dd3fc}
.bx-e .ic{font-size:18px}.bx-e .t{flex:1;min-width:0}.bx-e b{display:block;font-size:13.5px}.bx-e small{display:block;font-size:12px;color:var(--mut);font-weight:700;margin-top:2px;overflow-wrap:anywhere}
.bx-e time{font-size:11.5px;color:var(--mut);font-weight:700;white-space:nowrap}
.ld-ch{flex-basis:100%;padding:10px 12px;border-radius:12px;background:rgba(2,6,23,.5);border:1px solid rgba(163,230,53,.35)}
.ld-ch-t{font-size:12.5px;font-weight:900;color:#d9f99d;margin-bottom:6px}
.ld-ch-r{display:flex;flex-wrap:wrap;gap:8px 14px;align-items:center;padding:8px 0;border-top:1px solid rgba(255,255,255,.07)}
.ld-ch-r .c{flex:1;min-width:180px;font-size:13.5px;font-weight:800}.ld-ch-r .c small{display:block;color:#94a3b8;font-size:11.5px;font-weight:700;margin-top:2px}
.ld-ch-r .v{display:flex;flex-direction:column;gap:2px;min-width:110px}.ld-ch-r .v span{font-size:10.5px;color:#94a3b8;font-weight:800}
.ld-ch-r .v b{font-family:Inter,monospace;font-size:16px;letter-spacing:1px;direction:ltr;text-align:start}
.ld-ch-r .v b.bad{color:#fca5a5}.ld-ch-r .v b.good{color:#a3e635}
.ld-cdm{flex-basis:100%;padding:8px 12px;border-radius:12px;background:rgba(250,204,21,.07);border:1px dashed rgba(250,204,21,.4);color:#fde68a;font-size:13px;font-weight:800;line-height:1.7}
.ld-cd b{font-family:Inter,monospace;font-size:14px}
.ld-cdp{display:none;margin-top:10px;padding:12px;border-radius:14px;border:1px solid rgba(250,204,21,.4);background:rgba(250,204,21,.06)}
.ld-cdp.open{display:block}
.ld-cdp .row{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:10px}
.ld-cdp .row:last-child{margin-bottom:0}
.ld-cdp button,.ld-cdp input{height:38px;padding:0 13px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.05);color:var(--txt);font:inherit;font-size:13px;font-weight:800;cursor:pointer}
.ld-cdp input{width:90px;border-radius:12px;cursor:text;text-align:center}
.ld-cdp button.on{background:linear-gradient(90deg,#f59e0b,#eab308);color:#1c1917;border-color:transparent}
.ld-cdp .ty button.on.fk{background:linear-gradient(90deg,#9333ea,#6366f1);color:#fff}
.ld-cdp small{display:block;color:var(--mut);font-size:12px;font-weight:700;line-height:1.7}
.ld-acts .un{background:linear-gradient(90deg,#16a34a,#22c55e);border:0;color:#fff}
.ld-acts .st{border-color:rgba(239,68,68,.5);color:#fca5a5}.ld-acts .kp{border-color:rgba(34,197,94,.5);color:#86efac}
.ld-h{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:9px 12px;border-bottom:1px solid rgba(255,255,255,.05);font-size:13px}
.ld-h .i{flex:1;min-width:200px}.ld-h small{display:block;color:var(--mut);font-weight:700}
.ld-opts.three{grid-template-columns:repeat(3,1fr)}
@media (max-width:640px){.ld-opts,.ld-opts.three{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="nf-wrap">
    <header class="nf-head">
        <div><h1>🔒 <?= $ar ? 'إيقاف النظام' : 'Stop the system' ?></h1>
            <p><?= $ar ? 'إيقاف النظام عن أي موظف، والتحكم في بصمته أثناء الإيقاف' : 'Stop the system for anyone and control their clock-in during the stop' ?></p></div>
        <div class="nf-nav">
            <a class="nf-btn pur" href="permissions_admin.php?lang=<?= $lang ?>">🔐 <?= $ar ? 'الصلاحيات' : 'Permissions' ?></a>
            <a class="nf-btn" href="dashboard.php?lang=<?= $lang ?>">🏠 <?= $ar ? 'الرئيسية' : 'Dashboard' ?></a>
            <a class="nf-btn ghost" href="?lang=<?= $ar ? 'en' : 'ar' ?>"><?= $ar ? 'English' : 'العربية' ?></a>
        </div>
    </header>

    <!-- stopped right now -->
    <section class="nf-card">
        <h2>⛔ <?= $ar ? 'موقوف عنهم النظام الآن' : 'Stopped right now' ?> <span class="nf-count"><?= count($active) ?></span></h2>
        <?php if (!$active): ?><div class="nf-empty"><?= $ar ? 'لا يوجد أحد موقوف عنه النظام ✅' : 'Nobody is stopped ✅' ?></div><?php endif; ?>
        <?php foreach ($active as $l): $bs = $l['basma'] ?: 'none'; ?>
        <div class="ld-row">
            <div class="i"><b>🔒 <?= htmlspecialchars($l['username']) ?></b><span class="ld-k"><?= $kindLbl[$l['kind']] ?? $l['kind'] ?></span>
                <small class="r">📝 <?= htmlspecialchars((string)$l['reason'] !== '' ? $l['reason'] : ($l['kind'] === 'manual' ? ($ar ? 'بدون سبب مكتوب' : 'no reason given') : trim(strstr($kindLbl[$l['kind']] ?? '', ' ')))) ?></small>
                <small>🕐 <?= htmlspecialchars($fmt($l['locked_at'])) ?> · <?= htmlspecialchars($ago($l['age'])) ?><?= $l['locked_by'] ? ' · 👤 ' . htmlspecialchars($l['locked_by']) : '' ?><?= $l['done_at'] ? ' · ' . ($ar ? '✅ أنجز المطلوب' : '✅ finished what was asked') : '' ?></small></div>
            <span class="ld-b <?= $bs ?>"><?= $basmaLbl[$bs] ?? $bs ?></span>
            <?php $cdOn = $l['cd_until'] && !$l['cd_done']; if ($cdOn): ?>
            <span class="ld-cd" data-left="<?= max(0, (int)$l['cd_left']) ?>">⏳ <b>--:--</b><?= $isAdmin ? ' · ' . ($l['cd_fake'] ? ($ar ? '🎭 وهمي' : '🎭 fake') : ($ar ? '⏹ حقيقي' : '⏹ real')) : '' ?></span>
            <?php if ((string)$l['cd_msg'] !== ''): ?><div class="ld-cdm">📣 <?= nl2br(htmlspecialchars((string)$l['cd_msg'])) ?></div><?php endif; ?>
            <?php elseif ($isAdmin && $l['cd_until'] && $l['cd_fake']): ?><span class="ld-b"><?= $ar ? '🎭 انتهى العدّاد الوهمي' : '🎭 fake countdown ended' ?></span><?php endif; ?>
            <div class="ld-acts">
                <?php if ($cdOn): ?><button type="button" class="cd" onclick="ldCountdown(this)" data-edit="1" data-lock="<?= (int)$l['id'] ?>" data-name="<?= htmlspecialchars($l['username']) ?>" <?= $isAdmin ? 'data-k="' . (int)$l['cd_fake'] . '"' : '' ?> data-msg="<?= htmlspecialchars((string)$l['cd_msg']) ?>">✏️ <?= $ar ? 'تعديل العدّاد' : 'Edit countdown' ?></button>
                <button type="button" class="st" data-cdcancel="<?= (int)$l['id'] ?>">✕ <?= $ar ? 'إلغاء العدّاد' : 'Cancel countdown' ?></button>
                <?php elseif (in_array($bs, ['pending', 'running'], true)): ?><button type="button" class="cd" onclick="ldCountdown(this)" data-lock="<?= (int)$l['id'] ?>" data-name="<?= htmlspecialchars($l['username']) ?>">⏳ <?= $ar ? 'عدّاد إيقاف البصمة' : 'Clock-in countdown' ?></button><?php endif; ?>
                <?php if (in_array($bs, ['pending', 'running'], true)): ?><button type="button" class="st" data-basma="stop" data-lock="<?= (int)$l['id'] ?>">⏹ <?= $ar ? 'إيقاف البصمة من وقت الإيقاف' : 'Stop clock-in at the stop' ?></button><?php endif; ?>
                <?php if ($bs === 'pending'): ?><button type="button" class="kp" data-basma="keep" data-lock="<?= (int)$l['id'] ?>">▶ <?= $ar ? 'استمرار البصمة' : 'Keep it running' ?></button><?php endif; ?>
                <?php if ($l['kind'] === 'manual'): ?><button type="button" onclick="ldEditReason(this)" data-reason="<?= (int)$l['id'] ?>" data-cur="<?= htmlspecialchars((string)$l['reason']) ?>">✏️ <?= $ar ? 'تعديل السبب' : 'Edit reason' ?></button><?php endif; ?>
                <button type="button" class="un" data-unlock="<?= (int)$l['user_id'] ?>" data-name="<?= htmlspecialchars($l['username']) ?>">🔓 <?= $ar ? 'فتح النظام' : 'Unlock' ?></button>
            </div>
            <?php if ($isAdmin && $l['kind'] === 'chassis') echo $chTries(chassis_tries_for_lock($pdo, (int)$l['id'])); ?>
        </div>
        <?php endforeach; ?>
    </section>

    <?php if ($isAdmin && function_exists('loc_ping_create')):
        loc_ping_scan($pdo);
        $lpHere = [];
        try { $lpHere = $pdo->query("SELECT u.id, u.username, a.branch_name FROM attendance_logs a JOIN users u ON u.id = a.user_id WHERE a.status = 'active' AND u.active = 1 AND u.role <> 'admin' ORDER BY a.branch_name, u.username")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
        $lpLast = [];
        try { $lpLast = $pdo->query("SELECT p.*, u.username FROM loc_pings p JOIN users u ON u.id = p.user_id ORDER BY p.id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
        $lpBusy = array_map('intval', array_column(array_filter($lpLast, fn($x) => $x['status'] === 'pending'), 'user_id'));
        $lpS = $ar ? ['ok' => '✅ في الفرع', 'far' => '⚠️ بعيد', 'missed' => '⏰ لم يرد', 'denied' => '⛔ رفض الهاتف', 'pending' => '⏳ بانتظار الرد']
                   : ['ok' => '✅ at the branch', 'far' => '⚠️ far', 'missed' => '⏰ no answer', 'denied' => '⛔ refused', 'pending' => '⏳ waiting']; ?>
    <!-- 📍 location checks: admins only -->
    <section class="nf-card" id="ldPing">
        <h2>📍 <?= $ar ? 'تأكيد التواجد في الفرع' : 'Location checks' ?></h2>
        <p class="nf-note"><?= $ar ? 'اطلب موقع موظف مسجَّل حضوره الآن. إذا كان النظام مفتوحاً على هاتفه يُرسَل الموقع تلقائياً دون أن يفعل شيئاً، وإلا يصله إشعار ليفتح النظام. إذا لم يرد خلال ' . LOC_PING_MINUTES . ' دقائق يُسجَّل «لم يرد».'
                                   : 'Ask where a clocked-in person is right now. If the system is open on their phone it answers by itself; otherwise they get a notification. No answer in ' . LOC_PING_MINUTES . ' minutes is recorded.' ?></p>
        <?php if (!$lpHere): ?><div class="nf-empty"><?= $ar ? 'لا يوجد أحد مسجَّل حضوره الآن' : 'Nobody is clocked in right now' ?></div><?php endif; ?>
        <div class="lp-ppl">
            <?php foreach ($lpHere as $h): ?>
            <span class="lp-p">👤 <?= htmlspecialchars($h['username']) ?> <small>📍 <?= htmlspecialchars(push_branch_label($pdo, (string)$h['branch_name'], $lang)) ?></small>
                <button type="button" data-ping="<?= (int)$h['id'] ?>" <?= in_array((int)$h['id'], $lpBusy, true) ? 'disabled' : '' ?>>📡 <?= $ar ? 'اطلب الموقع' : 'Ask' ?></button></span>
            <?php endforeach; ?>
        </div>
        <?php if ($lpLast): ?><div style="margin-top:12px">
            <?php foreach ($lpLast as $r): ?>
            <div class="lp-r"><b><?= htmlspecialchars($r['username']) ?> <small style="color:var(--mut)">· <?= htmlspecialchars(push_branch_label($pdo, (string)$r['branch'], $lang)) ?><?= $r['dist_m'] !== null ? ' · ' . ((int)$r['dist_m'] < 1000 ? (int)$r['dist_m'] . ($ar ? ' م' : ' m') : round((int)$r['dist_m'] / 1000, 1) . ($ar ? ' كم' : ' km')) : '' ?></small></b>
                <span class="lp-s <?= htmlspecialchars($r['status']) ?>"><?= $lpS[$r['status']] ?? htmlspecialchars($r['status']) ?></span><time><?= htmlspecialchars($fmt($r['asked_at'])) ?></time></div>
            <?php endforeach; ?>
        </div><?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- new stop -->
    <section class="nf-card">
        <h2>✋ <?= $ar ? 'إيقاف يدوي' : 'Stop manually' ?></h2>
        <label class="ld-l"><?= $ar ? 'الموظف' : 'Who' ?></label>
        <div class="ld-ppl">
            <?php foreach ($people as $p): if ($p['role'] === 'admin') continue; $pid = (int)$p['id']; $lk = in_array($pid, $lockedIds, true); ?>
            <button type="button" class="ld-p pick" data-u="<?= $pid ?>" <?= $lk ? 'disabled' : '' ?>><?= htmlspecialchars($p['username']) ?><small><?= $lk ? ($ar ? '🔒 موقوف' : '🔒 stopped') : (isset($here[$pid]) ? ($ar ? '🟢 حاضر' : '🟢 in') : '') ?></small></button>
            <?php endforeach; ?>
        </div>
        <label class="ld-l"><?= $ar ? 'السبب (اختياري — يظهر له ويُكتب في سجل البصمة إذا أوقفتها)' : 'Reason (optional — shown to them and written into attendance if you stop the clock-in)' ?></label>
        <textarea class="ld-in" id="ldReason" maxlength="1500" placeholder="<?= $ar ? 'مثال: مراجعة عهدة الفرع — برجاء التواصل مع المدير' : 'e.g. branch audit — please contact your manager' ?>"></textarea>
        <label class="ld-l"><?= $ar ? 'البصمة أثناء الإيقاف' : 'Clock-in during the stop' ?></label>
        <div class="ld-opts">
            <label class="ld-o on"><input type="radio" name="ldB" value="stop" checked><span><b>⏹ <?= $ar ? 'إيقاف البصمة الآن' : 'Stop the clock-in now' ?></b><small><?= $ar ? 'يُسجَّل انصرافه لحظة الإيقاف، ويُكتب «إيقاف مفاجئ من الإدارة» والسبب في سجل البصمة' : 'Clocked out at the moment of the stop; "sudden stop by management" and the reason are written into attendance' ?></small></span></label>
            <label class="ld-o"><input type="radio" name="ldB" value="countdown"><span><b>⏳ <?= $ar ? 'عدّاد ثم إيقاف البصمة' : 'Countdown, then stop' ?></b><small><?= $ar ? 'يظهر له عدّاد تنازلي «ستتوقف بصمتك خلال …»' . ($isAdmin ? ' — حقيقي أو وهمي للضغط فقط' : ' وعند انتهائه تتوقف بصمته') : 'They see «your clock-in stops in …»' . ($isAdmin ? ' — real, or fake for pressure only' : ' and when it ends the clock-in stops') ?></small></span></label>
            <label class="ld-o"><input type="radio" name="ldB" value="keep"><span><b>▶ <?= $ar ? 'استمرار البصمة' : 'Keep it running' ?></b><small><?= $ar ? 'تستمر ساعات حضوره أثناء الإيقاف، ولا يستطيع تسجيل الانصراف حتى يُفتح النظام' : 'Hours keep counting during the stop; they cannot clock out until unlocked' ?></small></span></label>
        </div>
        <div class="ld-cdp" id="ldCdp">
            <div class="row" id="ldCdM"><?php foreach ([5, 10, 15, 30, 60] as $mm): ?><button type="button" data-m="<?= $mm ?>" class="<?= $mm === 15 ? 'on' : '' ?>"><?= $ar ? push_ar_mins($mm) : $mm . ' min' ?></button><?php endforeach; ?>
                <input type="number" id="ldCdC" min="1" max="600" placeholder="<?= $ar ? 'دقائق' : 'min' ?>"></div>
<?php if ($isAdmin): ?>
            <div class="row ty" id="ldCdK"><button type="button" data-f="0" class="on">⏹ <?= $ar ? 'حقيقي' : 'Real' ?></button><button type="button" data-f="1" class="fk">🎭 <?= $ar ? 'وهمي' : 'Fake' ?></button></div>
<?php endif; ?>
            <textarea class="ld-in" id="ldCdMsg" maxlength="1000" style="min-height:90px;margin-bottom:8px" placeholder="<?= $ar ? '📣 رسالة تظهر له مع العدّاد (اختياري) — مثال: لديك عملاء بدون متابعة، حدّث الـ CRM الآن' : '📣 Message shown with the countdown (optional)' ?>"></textarea>
<?php if ($isAdmin): ?>
            <small id="ldCdH"><?= $ar ? '⏹ حقيقي: عند انتهاء العدّاد تتوقف بصمته فعلاً ويُكتب السبب في سجل البصمة.' : '⏹ Real: when it ends the clock-in really stops and the reason is written into attendance.' ?></small>
            <small><?= $ar ? '👁️ الموظف يرى نفس العدّاد في الحالتين — لا يعرف إن كان وهمياً. إن لم يكن حاضراً فلا يظهر العدّاد.' : '👁️ The person sees the same countdown either way. If they are not clocked in, no countdown is shown.' ?></small>
<?php endif; ?>
        </div>
        <label class="ld-l">🔔 <?= $ar ? 'من يستلم الإشعار؟' : 'Who is notified?' ?></label>
        <div class="ld-ppl">
            <?php foreach ($people as $p): if ($p['role'] === 'admin') continue; ?>
            <button type="button" class="ld-p w<?= in_array((int)$p['id'], $rules['watchers'], true) ? ' on' : '' ?>" data-u="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['username']) ?></button>
            <?php endforeach; ?>
        </div>
        <div class="ld-fix">👑 <?= $ar ? 'كل الأدمن يستلمون الإشعار دائماً على كل أجهزتهم — والموظف نفسه أيضاً' : 'Every admin always gets it on all their devices — and the person too' ?></div>
        <button type="button" class="ld-go" id="ldGo">🔒 <?= $ar ? 'إيقاف النظام الآن' : 'Stop the system now' ?></button>
        <div class="nf-msg" id="ldMsg"></div>
    </section>

    <!-- automatic stops -->
    <section class="nf-card">
        <h2>⚙️ <?= $ar ? 'الإيقاف التلقائي (الجرد المفاجئ والاستلام)' : 'Automatic stops (stock check & receiving)' ?></h2>
        <label class="ld-l"><?= $ar ? 'البصمة عند الإيقاف التلقائي' : 'Clock-in on an automatic stop' ?></label>
        <div class="ld-opts three">
            <?php foreach (($ar ? ['ask' => ['❓ اسألني', 'تستمر البصمة حتى تختار — يصلك إشعار فيه زرّا «إيقاف البصمة» و«استمرار البصمة»'],
                                   'stop' => ['⏹ إيقافها تلقائياً', 'يُسجَّل انصرافه لحظة الإيقاف، ويُكتب السبب (الجرد / عدم تأكيد الاستلام) في سجل البصمة'],
                                   'keep' => ['▶ استمرارها دائماً', 'تستمر ساعات حضوره أثناء الإيقاف']]
                                : ['ask' => ['❓ Ask me', 'Keeps running until you choose — the notification has "stop" and "keep" buttons'],
                                   'stop' => ['⏹ Stop automatically', 'Clocked out at the moment of the stop, the reason written into attendance'],
                                   'keep' => ['▶ Always keep it', 'Hours keep counting during the stop']]) as $k => [$t, $s]): ?>
            <label class="ld-o<?= $rules['auto_basma'] === $k ? ' on' : '' ?>"><input type="radio" name="ldA" value="<?= $k ?>" <?= $rules['auto_basma'] === $k ? 'checked' : '' ?>><span><b><?= $t ?></b><small><?= $s ?></small></span></label>
            <?php endforeach; ?>
        </div>
        <label class="ld-l">🔔 <?= $ar ? 'من يستلم إشعارات كل إيقاف (يدوي أو تلقائي) بجانب الأدمن؟' : 'Who else gets every stop (manual or automatic) besides the admins?' ?></label>
        <div class="ld-ppl">
            <?php foreach ($people as $p): if ($p['role'] === 'admin') continue; ?>
            <button type="button" class="ld-p w rw<?= in_array((int)$p['id'], $rules['watchers'], true) ? ' on' : '' ?>" data-u="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['username']) ?></button>
            <?php endforeach; ?>
        </div>
        <div class="na-save" style="position:static;display:flex;gap:10px;align-items:center;justify-content:flex-end;margin-top:12px"><div class="nf-msg" id="rMsg"></div><button type="button" class="nf-btn grn" id="rSave">💾 <?= $ar ? 'حفظ' : 'Save' ?></button></div>
    </section>

    <?php if ($looseTries): ?>
    <!-- wrong chassis numbers that did not stop anyone (the first mistake) — admins only -->
    <section class="nf-card">
        <h2>🔑 <?= $ar ? 'إدخالات غير صحيحة لم توقف النظام' : 'Wrong entries that did not stop the system' ?> <span class="nf-count"><?= count($looseTries) ?></span></h2>
        <p class="nf-note"><?= $ar ? 'أول رقم شاسيه أو عدد سيارات غير صحيح يُسجَّل هنا فقط؛ الثاني يوقف النظام.' : 'A first wrong chassis or car count is only recorded here; the second one stops the system.' ?></p>
        <?= $chTries($looseTries) ?>
    </section>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <!-- the record: admins only -->
    <section class="nf-card" id="ldBox">
        <h2>🗃️ <?= $ar ? 'السجل الخاص بالإدارة' : 'The admin record' ?> <span class="nf-count"><?= (int)$boxAll ?></span></h2>
        <p class="nf-note"><?= $ar ? 'كل ما يستحق المراجعة خلال آخر 30 يوماً في مكان واحد: الإيقافات، الإدخالات غير الصحيحة، البصمة من مكان بعيد، نسيان الانصراف، وبلاغات «لم تصل». يظهر للإدارة فقط. اضغط على اسم لعرض سجله وحده.'
                                   : 'Everything worth a look in the last 30 days: stops, wrong entries, far clock-ins, forgotten clock-outs and "did not arrive" reports. Admins only. Tap a name to see only that person.' ?></p>
        <?php if (!$box): ?><div class="nf-empty"><?= $ar ? 'لا يوجد شيء مسجَّل خلال آخر 30 يوماً ✅' : 'Nothing recorded in the last 30 days ✅' ?></div><?php else: ?>
        <div class="bx-ppl">
            <button type="button" class="bx-p on" data-u=""><?= $ar ? 'الكل' : 'All' ?> <b><?= (int)$boxAll ?></b></button>
            <?php foreach ($boxBy as $bu => $bn): ?><button type="button" class="bx-p" data-u="<?= htmlspecialchars((string)$bu) ?>"><?= htmlspecialchars((string)$bu) ?> <b><?= (int)$bn ?></b></button><?php endforeach; ?>
        </div>
        <div class="bx-kinds">
            <?php foreach (($ar ? ['' => 'كل الأنواع', 'stop' => '⛔ إيقاف', 'wrong' => '🔑 إدخال غير صحيح', 'far' => '📍 بعيد عن الفرع', 'forgot' => '⏰ نسي الانصراف', 'miss' => '❗ لم تصل', 'loc' => '📡 تأكيد التواجد']
                               : ['' => 'All kinds', 'stop' => '⛔ Stops', 'wrong' => '🔑 Wrong entries', 'far' => '📍 Far away', 'forgot' => '⏰ Forgot out', 'miss' => '❗ Did not arrive', 'loc' => '📡 Location checks']) as $kk => $kl): ?>
            <button type="button" class="bx-k<?= $kk === '' ? ' on' : '' ?>" data-k="<?= $kk ?>"><?= $kl ?></button><?php endforeach; ?>
        </div>
        <div class="bx-list">
            <?php foreach ($box as $e): ?>
            <div class="bx-e <?= $e['k'] ?>" data-u="<?= htmlspecialchars((string)$e['u']) ?>" data-k="<?= $e['k'] ?>">
                <span class="ic"><?= $e['ic'] ?></span>
                <div class="t"><b><?= htmlspecialchars((string)$e['u']) ?> — <?= htmlspecialchars($e['x']) ?></b><?php if ((string)$e['d'] !== ''): ?><small><?= htmlspecialchars(mb_strlen($e['d']) > 220 ? mb_substr($e['d'], 0, 220) . '…' : $e['d']) ?></small><?php endif; ?></div>
                <time><?= htmlspecialchars($fmt($e['t'])) ?></time>
            </div>
            <?php endforeach; ?>
            <div class="nf-empty" id="bxNone" hidden><?= $ar ? 'لا يوجد شيء بهذا الاختيار' : 'Nothing for this choice' ?></div>
        </div>
        <?php endif; ?>
    </section>
    <script>
    (function () {
        var u = '', k = '', rows = [].slice.call(document.querySelectorAll('.bx-e'));
        function paint() { var n = 0; rows.forEach(function (r) { var ok = (!u || r.dataset.u === u) && (!k || r.dataset.k === k); r.hidden = !ok; if (ok) n++; }); var e = document.getElementById('bxNone'); if (e) e.hidden = n > 0; }
        document.querySelectorAll('.bx-p').forEach(function (b) { b.addEventListener('click', function () { u = b.dataset.u; document.querySelectorAll('.bx-p').forEach(function (x) { x.classList.toggle('on', x === b); }); paint(); }); });
        document.querySelectorAll('.bx-k').forEach(function (b) { b.addEventListener('click', function () { k = b.dataset.k; document.querySelectorAll('.bx-k').forEach(function (x) { x.classList.toggle('on', x === b); }); paint(); }); });
    })();
    </script>
    <?php endif; ?>

    <!-- history -->
    <section class="nf-card">
        <h2>🗂️ <?= $ar ? 'سجل الإيقافات' : 'Stop history' ?></h2>
        <?php if (!$history): ?><div class="nf-empty"><?= $ar ? 'لا يوجد سجل بعد' : 'Nothing yet' ?></div><?php endif; ?>
        <?php foreach ($history as $h): ?>
        <div class="ld-h">
            <div class="i"><b><?= htmlspecialchars($h['username']) ?></b> <span class="ld-k"><?= $kindLbl[$h['kind']] ?? $h['kind'] ?></span>
                <small>📝 <?= htmlspecialchars((string)$h['reason'] !== '' ? $h['reason'] : ($ar ? 'بدون سبب' : 'no reason')) ?></small>
                <small>🔒 <?= htmlspecialchars($fmt($h['locked_at'])) ?> → 🔓 <?= htmlspecialchars($fmt($h['unlocked_at'])) ?> · <?= htmlspecialchars((string)$h['unlocked_by']) ?></small></div>
            <span class="ld-b <?= $h['basma'] ?: 'none' ?>"><?= $basmaLbl[$h['basma'] ?: 'none'] ?? '' ?></span>
            <?php if ($isAdmin && $h['kind'] === 'chassis') echo $chTries(chassis_tries_for_lock($pdo, (int)$h['id'])); ?>
        </div>
        <?php endforeach; ?>
    </section>
</div>
<script>
/* ✏️ edit the reason — self-contained: builds its own box on click, so nothing
   else on the page can stop it; if anything fails it falls back to a simple prompt. */
/* ⏳ start a countdown on a lock that is already active */
function ldCountdown(btn) {
    var AR = <?= json_encode($ar) ?>, CSRF = <?= json_encode($csrf) ?>, lock = +btn.getAttribute('data-lock');
    var edit = btn.getAttribute('data-edit') === '1', mins = edit ? null : 15, kd = btn.getAttribute('data-k') === '1', ADM = <?= json_encode($isAdmin) ?>;
    var old = document.getElementById('ldCdBox'); if (old) old.remove();
    var ov = document.createElement('div'); ov.id = 'ldCdBox';
    ov.setAttribute('style', 'position:fixed;top:0;left:0;right:0;bottom:0;z-index:2147483647;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(2,6,23,.78)');
    var chip = 'height:42px;padding:0 14px;border-radius:999px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.05);color:#f1f5f9;font:inherit;font-size:14px;font-weight:800;cursor:pointer';
    var M = AR ? ['5 دقائق', '10 دقائق', '15 دقيقة', '30 دقيقة', '60 دقيقة'] : ['5 min', '10 min', '15 min', '30 min', '60 min'];
    ov.innerHTML = '<div style="width:100%;max-width:520px;border-radius:24px;padding:22px;background:#0f1a30;border:1px solid rgba(250,204,21,.5);box-shadow:0 30px 80px rgba(0,0,0,.6);color:#f1f5f9;direction:' + (AR ? 'rtl' : 'ltr') + '">' +
        '<div style="font-size:20px;font-weight:900;margin-bottom:4px">' + (edit ? '✏️ ' + (AR ? 'تعديل العدّاد' : 'Edit countdown') : '⏳ ' + (AR ? 'عدّاد إيقاف البصمة' : 'Clock-in countdown')) + '</div>' +
        '<div data-x="who" style="color:#fca5a5;font-weight:800;font-size:14px;margin-bottom:14px"></div>' +
        '<div style="font-size:13px;font-weight:800;color:#94a3b8;margin-bottom:8px">' + (AR ? 'المدة' : 'Time') + '</div>' +
        '<div data-x="m" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px">' + (edit ? '<button type="button" data-m="0" style="' + chip + '">⏸ ' + (AR ? 'بدون تغيير الوقت' : 'Keep the time') + '</button>' : '') + [5, 10, 15, 30, 60].map(function (m, i) { return '<button type="button" data-m="' + m + '" style="' + chip + '">' + M[i] + '</button>'; }).join('') +
        '<input data-x="c" type="number" min="1" max="600" placeholder="' + (AR ? 'دقائق' : 'min') + '" style="' + chip + ';width:100px;border-radius:12px;text-align:center;cursor:text"></div>' +
<?php if ($isAdmin): ?>
        '<div style="font-size:13px;font-weight:800;color:#94a3b8;margin:14px 0 8px">' + (AR ? 'النوع' : 'Type') + '</div>' +
        '<div data-x="t" style="display:flex;gap:6px"><button type="button" data-f="0" style="' + chip + ';flex:1">⏹ ' + (AR ? 'حقيقي' : 'Real') + '</button><button type="button" data-f="1" style="' + chip + ';flex:1">🎭 ' + (AR ? 'وهمي' : 'Fake') + '</button></div>' +
        '<div data-x="h" style="margin-top:10px;font-size:12.5px;color:#94a3b8;font-weight:700;line-height:1.7"></div>' +
<?php endif; ?>
        '<div style="font-size:13px;font-weight:800;color:#94a3b8;margin:14px 0 8px">📣 ' + (AR ? 'رسالة تظهر له مع العدّاد (اختياري)' : 'Message shown with the countdown (optional)') + '</div>' +
        '<textarea data-x="msg" maxlength="1000" style="box-sizing:border-box;width:100%;min-height:110px;border-radius:14px;border:1.5px solid rgba(250,204,21,.55);background:#070d1c;color:#f1f5f9;font:inherit;font-size:15px;line-height:1.8;padding:12px 14px;resize:vertical;outline:none" placeholder="' + (AR ? 'مثال: لديك عملاء بدون متابعة — حدّث الـ CRM الآن' : 'e.g. update the CRM now') + '"></textarea>' +
        '<div style="font-size:12px;color:#94a3b8;font-weight:700;margin-top:6px">🔔 ' + (AR ? 'كل تعديل يصل كإشعار للأدمن ومن اخترتهم' : 'Every change notifies the admins and the people you chose') + '</div>' +
        '<div style="display:flex;gap:10px;margin-top:16px"><button type="button" data-x="go" style="flex:1;min-height:52px;border:0;border-radius:14px;background:linear-gradient(90deg,#f59e0b,#dc2626);color:#fff;font:inherit;font-size:16px;font-weight:900;cursor:pointer">' + (edit ? '💾 ' + (AR ? 'حفظ التعديل' : 'Save changes') : '⏳ ' + (AR ? 'ابدأ العدّاد' : 'Start')) + '</button>' +
        '<button type="button" data-x="no" style="flex:1;min-height:52px;border:1px solid rgba(255,255,255,.15);border-radius:14px;background:rgba(255,255,255,.06);color:#f1f5f9;font:inherit;font-size:16px;font-weight:900;cursor:pointer">' + (AR ? 'إلغاء' : 'Cancel') + '</button></div></div>';
    var q = function (k) { return ov.querySelector('[data-x="' + k + '"]'); };
    q('who').textContent = '🔒 ' + (btn.getAttribute('data-name') || '');
    q('msg').value = btn.getAttribute('data-msg') || '';
    var paint = function () {
        ov.querySelectorAll('[data-m]').forEach(function (b) { var on = +b.getAttribute('data-m') === (mins === null ? 0 : mins) && !q('c').value; b.style.background = on ? 'linear-gradient(90deg,#f59e0b,#eab308)' : 'rgba(255,255,255,.05)'; b.style.color = on ? '#1c1917' : '#f1f5f9'; });
<?php if ($isAdmin): ?>
        ov.querySelectorAll('[data-f]').forEach(function (b) { var on = (b.getAttribute('data-f') === '1') === kd; b.style.background = on ? (kd ? 'linear-gradient(90deg,#9333ea,#6366f1)' : 'linear-gradient(90deg,#dc2626,#f59e0b)') : 'rgba(255,255,255,.05)'; });
        q('h').textContent = kd ? (AR ? '🎭 وهمي: يراه الموظف كأنه حقيقي، لكن عند انتهائه لا يحدث شيء — البصمة تستمر ويصلك إشعار.' : '🎭 Fake: looks real to them, nothing happens at the end — you are told.')
                                  : (AR ? '⏹ حقيقي: عند انتهائه تتوقف بصمته فعلاً من تلك اللحظة ويُكتب السبب في سجل البصمة.' : '⏹ Real: when it ends the clock-in stops from that moment, reason written into attendance.');
<?php endif; ?>
    };
    ov.querySelectorAll('[data-m]').forEach(function (b) { b.addEventListener('click', function () { mins = +b.getAttribute('data-m') || null; q('c').value = ''; paint(); }); });
    q('c').addEventListener('input', function () { if (+q('c').value > 0) mins = +q('c').value; paint(); });
<?php if ($isAdmin): ?>
    ov.querySelectorAll('[data-f]').forEach(function (b) { b.addEventListener('click', function () { kd = b.getAttribute('data-f') === '1'; paint(); }); });
<?php endif; ?>
    var close = function () { ov.remove(); };
    q('no').addEventListener('click', close);
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    q('go').addEventListener('click', function () {
        q('go').disabled = true;
        fetch(location.pathname, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ csrf: CSRF, action: 'countdown', lock: lock, mins: mins, k: kd, msg: q('msg').value, edit: edit ? 1 : 0 }) })
            .then(function (r) { return r.json(); })
            .then(function (r) { if (r.ok) location.reload(); else { q('go').disabled = false; alert(AR ? 'لا يمكن — بصمته ليست مستمرة الآن' : 'Not possible — the clock-in is not running'); } })
            .catch(function (e) { q('go').disabled = false; alert(e); });
    });
    paint();
    document.body.appendChild(ov);
}

function ldEditReason(btn) {
    var AR = <?= json_encode($ar) ?>, CSRF = <?= json_encode($csrf) ?>;
    var lock = +btn.getAttribute('data-reason'), cur = btn.getAttribute('data-cur') || '';
    function save(v, done) {
        fetch(location.pathname, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf: CSRF, action: 'reason', lock: lock, reason: v }) })
            .then(function (r) { return r.json(); })
            .then(function (r) { if (r.ok) location.reload(); else { alert((AR ? 'لم يتم الحفظ' : 'Not saved') + (r.error ? ' (' + r.error + ')' : '')); done && done(); } })
            .catch(function (e) { alert((AR ? 'تعذّر الحفظ: ' : 'Could not save: ') + e); done && done(); });
    }
    function fallback(err) {
        if (err) { var m = document.getElementById('ldErr'); if (m) { m.textContent = '⚠️ ' + err; m.style.display = 'block'; } }
        var v = prompt(AR ? 'السبب الجديد:' : 'New reason:', cur);
        if (v !== null && v.trim() !== cur.trim()) save(v);
    }
    try {
        var old = document.getElementById('ldRsBox'); if (old) old.remove();
        var row = btn.closest ? btn.closest('.ld-row') : null, nm = row ? row.querySelector('b') : null;
        var ov = document.createElement('div'); ov.id = 'ldRsBox';
        ov.setAttribute('style', 'position:fixed;top:0;left:0;right:0;bottom:0;z-index:2147483647;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(2,6,23,.78)');
        ov.innerHTML =
            '<div style="width:100%;max-width:560px;border-radius:24px;padding:22px;background:#0f1a30;border:1px solid rgba(147,51,234,.5);box-shadow:0 30px 80px rgba(0,0,0,.6);color:#f1f5f9;font-family:inherit;direction:' + (AR ? 'rtl' : 'ltr') + '">' +
            '<div style="font-size:20px;font-weight:900;margin-bottom:4px">✏️ ' + (AR ? 'تعديل سبب الإيقاف' : 'Edit the stop reason') + '</div>' +
            '<div data-x="who" style="color:#fca5a5;font-weight:800;font-size:14px;margin-bottom:12px"></div>' +
            '<div data-x="old" style="padding:10px 12px;border-radius:12px;background:rgba(255,255,255,.05);border:1px dashed rgba(255,255,255,.18);color:#94a3b8;font-size:13.5px;font-weight:700;margin-bottom:12px;line-height:1.7;white-space:pre-wrap"></div>' +
            '<textarea data-x="ta" maxlength="1500" style="box-sizing:border-box;width:100%;min-height:260px;max-height:60vh;border-radius:16px;border:1.5px solid #a855f7;background:#070d1c;color:#f1f5f9;font:inherit;font-size:16px;line-height:1.8;padding:14px 16px;resize:vertical;outline:none"></textarea>' +
            '<div style="display:flex;justify-content:space-between;gap:10px;margin-top:8px;font-size:12.5px;color:#94a3b8;font-weight:700"><span>🔔 ' + (AR ? 'سيصل إشعار لكل من استلم إشعار الإيقاف' : 'Everyone who was told about the stop is notified') + '</span><span data-x="cnt" style="white-space:nowrap"></span></div>' +
            '<div style="display:flex;gap:10px;margin-top:16px">' +
            '<button type="button" data-x="save" style="flex:1;min-height:52px;border:0;border-radius:14px;background:linear-gradient(90deg,#9333ea,#2563eb);color:#fff;font:inherit;font-size:16px;font-weight:900;cursor:pointer">💾 ' + (AR ? 'حفظ وإرسال' : 'Save & notify') + '</button>' +
            '<button type="button" data-x="cancel" style="flex:1;min-height:52px;border:1px solid rgba(255,255,255,.15);border-radius:14px;background:rgba(255,255,255,.06);color:#f1f5f9;font:inherit;font-size:16px;font-weight:900;cursor:pointer">' + (AR ? 'إلغاء' : 'Cancel') + '</button>' +
            '</div></div>';
        var q = function (k) { return ov.querySelector('[data-x="' + k + '"]'); };
        q('who').textContent = nm ? '🔒 ' + nm.textContent.replace('🔒', '').trim() : '';
        q('old').textContent = (AR ? 'السبب الحالي: ' : 'Current reason: ') + (cur || (AR ? 'بدون سبب مكتوب' : 'no reason given'));
        var ta = q('ta'), sv = q('save');
        ta.value = cur;
        var upd = function () { q('cnt').textContent = ta.value.length + ' / 1500'; sv.disabled = ta.value.trim() === cur.trim(); sv.style.opacity = sv.disabled ? '.5' : '1'; };
        upd(); ta.addEventListener('input', upd);
        var close = function () { ov.remove(); document.body.style.overflow = ''; };
        q('cancel').addEventListener('click', close);
        ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
        sv.addEventListener('click', function () { sv.disabled = true; save(ta.value, function () { sv.disabled = false; }); });
        document.body.appendChild(ov); document.body.style.overflow = 'hidden';
        setTimeout(function () { try { ta.focus(); } catch (x) {} }, 50);
    } catch (e) { fallback(e && e.message ? e.message : String(e)); }
}
</script>
<div id="ldErr" style="display:none;position:fixed;bottom:10px;left:10px;right:10px;z-index:2147483647;padding:10px;border-radius:10px;background:#7f1d1d;color:#fff;font-size:12px;direction:ltr"></div>
<script>
(function () {
    const CSRF = <?= json_encode($csrf) ?>, AR = <?= json_encode($ar) ?>;
    const $ = id => document.getElementById(id);
    const post = d => fetch(location.pathname, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ csrf: CSRF }, d)) }).then(r => r.json());
    const msg = (id, t, ok) => { const m = $(id); m.textContent = t; m.className = 'nf-msg ' + (ok ? 'ok' : 'bad'); };
    document.querySelectorAll('.ld-p:not(:disabled)').forEach(b => b.addEventListener('click', () => b.classList.toggle('on')));
    document.querySelectorAll('.ld-o input').forEach(r => r.addEventListener('change', () => document.querySelectorAll('input[name="' + r.name + '"]').forEach(x => x.closest('.ld-o').classList.toggle('on', x.checked))));
    /* ⏳ countdown panel in the form */
    const cdMins = () => +$('ldCdC').value || +(document.querySelector('#ldCdM button.on') || {}).dataset?.m || 15;
<?php if ($isAdmin): ?>
    const cdK = () => { const b = document.querySelector('#ldCdK button.on'); return !!b && b.dataset.f === '1'; };
<?php else: ?>
    const cdK = () => false;
<?php endif; ?>
    document.querySelectorAll('input[name=ldB]').forEach(r => r.addEventListener('change', () => $('ldCdp').classList.toggle('open', document.querySelector('input[name=ldB]:checked').value === 'countdown')));
    document.querySelectorAll('#ldCdM button').forEach(b => b.addEventListener('click', () => { document.querySelectorAll('#ldCdM button').forEach(x => x.classList.toggle('on', x === b)); $('ldCdC').value = ''; }));
    $('ldCdC').addEventListener('input', () => document.querySelectorAll('#ldCdM button').forEach(x => x.classList.remove('on')));
<?php if ($isAdmin): ?>
    document.querySelectorAll('#ldCdK button').forEach(b => b.addEventListener('click', () => {
        document.querySelectorAll('#ldCdK button').forEach(x => x.classList.toggle('on', x === b));
        $('ldCdH').textContent = b.dataset.f === '1'
            ? (AR ? '🎭 وهمي: يراه الموظف كأنه حقيقي، لكن عند انتهائه لا يحدث شيء — البصمة تستمر، ويصلك إشعار بانتهائه.' : '🎭 Fake: looks real to them, but nothing happens when it ends — the clock-in keeps running; you are told it ended.')
            : (AR ? '⏹ حقيقي: عند انتهاء العدّاد تتوقف بصمته فعلاً ويُكتب السبب في سجل البصمة.' : '⏹ Real: when it ends the clock-in really stops and the reason is written into attendance.');
    }));
<?php endif; ?>
    /* live countdowns in the list */
    const tick = () => document.querySelectorAll('.ld-cd').forEach(el => {
        const left = Math.max(0, (+el.dataset.left) - Math.floor((Date.now() - T0) / 1000));
        el.querySelector('b').textContent = String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0');
        if (left === 0 && !el.dataset.done) { el.dataset.done = 1; setTimeout(() => location.reload(), 2500); }
    });
    const T0 = Date.now(); tick(); setInterval(tick, 1000);
    document.querySelectorAll('[data-cdcancel]').forEach(b => b.addEventListener('click', async () => {
        if (!confirm(AR ? 'إلغاء العدّاد؟' : 'Cancel the countdown?')) return;
        b.disabled = true; const r = await post({ action: 'countdown', lock: +b.dataset.cdcancel, mins: 0 }); if (r.ok) location.reload(); else b.disabled = false;
    }));
    $('ldGo').addEventListener('click', async () => {
        const uids = [...document.querySelectorAll('.ld-p.pick.on')].map(b => +b.dataset.u);
        if (!uids.length) { msg('ldMsg', AR ? 'اختر الموظف أولاً' : 'Pick who first', false); return; }
        const basma = document.querySelector('input[name=ldB]:checked').value;
        if (!confirm(AR ? 'إيقاف النظام عن ' + uids.length + ' موظف الآن؟' : 'Stop the system for ' + uids.length + ' now?')) return;
        $('ldGo').disabled = true;
        const r = await post({ action: 'lock', uids, reason: $('ldReason').value, basma, cd_mins: cdMins(), cd_k: cdK(), cd_msg: $('ldCdMsg').value, watch: [...document.querySelectorAll('.ld-p.w:not(.rw).on')].map(b => +b.dataset.u) });
        if (r.ok) location.reload(); else { $('ldGo').disabled = false; msg('ldMsg', '✗ ' + (r.error || ''), false); }
    });
    document.querySelectorAll('[data-ping]').forEach(b => b.addEventListener('click', async () => {
        b.disabled = true;
        const r = await post({ action: 'ping', uid: +b.dataset.ping });
        if (r.ok) setTimeout(() => location.reload(), 600); else { b.disabled = false; alert(AR ? 'تعذّر إرسال الطلب (ربما سجّل انصرافه أو يوجد طلب قائم)' : 'Could not send (clocked out, or already being asked)'); }
    }));
    document.querySelectorAll('[data-unlock]').forEach(b => b.addEventListener('click', async () => {
        if (!confirm((AR ? 'فتح النظام لـ ' : 'Unlock ') + b.dataset.name + '؟')) return;
        b.disabled = true; const r = await post({ action: 'unlock', uid: +b.dataset.unlock }); if (r.ok) location.reload(); else b.disabled = false;
    }));
    document.querySelectorAll('[data-basma]').forEach(b => b.addEventListener('click', async () => {
        b.disabled = true; const r = await post({ action: 'basma', lock: +b.dataset.lock, how: b.dataset.basma }); if (r.ok) location.reload(); else b.disabled = false;
    }));
    $('rSave').addEventListener('click', async () => {
        const r = await post({ action: 'rules', auto_basma: document.querySelector('input[name=ldA]:checked').value, watchers: [...document.querySelectorAll('.ld-p.rw.on')].map(b => +b.dataset.u) });
        r.ok ? msg('rMsg', AR ? '✓ تم الحفظ' : '✓ Saved', true) : msg('rMsg', '✗', false);
    });
})();
</script>
</body>
</html>
