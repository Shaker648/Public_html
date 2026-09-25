<?php
/*
 * stock_check.php — الجرد المفاجئ (surprise stock check).
 *
 * The admin (page.stock_check) picks a branch, the person or people who do the
 * check and how long they have. The cars in that branch at that moment become
 * the list. The people chosen get a notification at once and go through the
 * list here: «موجودة» / «غير موجودة» for every car, plus any car that is there
 * but not in the system. Before the time ends they and the admin are warned;
 * if it is not finished in time their system stops until the admin unlocks it.
 * The admin gets the result (what is missing) the moment it is finished.
 */
require 'auth.php';
require 'config.php';
require_once __DIR__ . '/notify_smart.php';

$lang = ($_GET['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
$ar   = $lang === 'ar';
$dir  = $ar ? 'rtl' : 'ltr';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid  = (int)$_SESSION['user_id'];
$me   = (string)$_SESSION['username'];
$isBoss = can('page.stock_check');
push_tables($pdo);
smart_check_scan($pdo);

$myChecks = smart_checks_for_user($pdo, $uid);
$myIds = array_map(fn($c) => (int)$c['id'], $myChecks);
if (!$isBoss && !$myChecks && !isset($_GET['id'])) { header('Location: dashboard.php?lang=' . $lang); exit; }

/** May this person work on / see this check? */
function sc_access(PDO $pdo, int $id, int $uid, bool $boss): bool
{
    if ($boss) return true;
    $st = $pdo->prepare("SELECT 1 FROM stock_check_users WHERE check_id = ? AND user_id = ?");
    $st->execute([$id, $uid]);
    return (bool)$st->fetchColumn();
}

/* ─── JSON actions ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($csrf, (string)($in['csrf'] ?? ''))) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }
    $cid = (int)($in['check'] ?? 0);
    try {
        switch ($in['action'] ?? '') {
            case 'create':
                if (!$isBoss) break;
                $branch = (string)($in['branch'] ?? '');
                $users  = array_map('intval', (array)($in['users'] ?? []));
                $okB = $pdo->prepare("SELECT 1 FROM branches WHERE name = ?"); $okB->execute([$branch]);
                if (!$okB->fetchColumn()) { echo json_encode(['ok' => false, 'error' => 'branch']); exit; }
                if (!$users) { echo json_encode(['ok' => false, 'error' => 'nobody']); exit; }
                $id = smart_check_create($pdo, $branch, $users, (int)($in['minutes'] ?? 30), trim((string)($in['note'] ?? '')), !empty($in['lock']), $me);
                echo json_encode(['ok' => true, 'id' => $id]); exit;
            case 'mark':
                if (!sc_access($pdo, $cid, $uid, false) && !$isBoss) break;
                $state = in_array($in['state'] ?? '', ['present', 'missing'], true) ? $in['state'] : null;
                $note  = mb_substr(trim((string)($in['note'] ?? '')), 0, 250);
                $prev = $pdo->prepare("SELECT i.state, i.note, i.label, i.chassis, c.branch FROM stock_check_items i JOIN stock_checks c ON c.id = i.check_id WHERE i.id = ? AND i.check_id = ?");
                $prev->execute([(int)($in['item'] ?? 0), $cid]);
                $prev = $prev->fetch(PDO::FETCH_ASSOC);
                $pdo->prepare("UPDATE stock_check_items i JOIN stock_checks c ON c.id = i.check_id SET i.state = ?, i.note = ?, i.marked_by = ?, i.marked_at = NOW()
                               WHERE i.id = ? AND i.check_id = ? AND i.car_id > 0 AND c.status IN ('active', 'expired')")
                    ->execute([$state, $note ?: null, $me, (int)($in['item'] ?? 0), $cid]);
                $counts = smart_check_counts($pdo, $cid);
                // the admin follows the check car by car (one notification per check on the phone, updated each time)
                if ($prev && $state && ($prev['state'] !== $state || ($state === 'missing' && $note !== '' && $note !== (string)$prev['note']))) {
                    notify_event($pdo, 'check_item', ['id' => $cid, 'branch' => $prev['branch'], 'label' => $prev['label'], 'chassis' => $prev['chassis'], 'state' => $state,
                        'note' => $note, 'done' => $counts[1], 'total' => $counts[0], 'missing' => $counts[3], 'actor' => $me, 'tag' => 'check-' . $cid]);
                }
                echo json_encode(['ok' => true, 'counts' => $counts]); exit;
            case 'extra':
                if (!sc_access($pdo, $cid, $uid, false) && !$isBoss) break;
                $txt = mb_substr(trim((string)($in['text'] ?? '')), 0, 200);
                if ($txt === '') { echo json_encode(['ok' => false, 'error' => 'empty']); exit; }
                $pdo->prepare("INSERT INTO stock_check_items (check_id, car_id, label, state, marked_by, marked_at) VALUES (?, 0, ?, 'extra', ?, NOW())")->execute([$cid, $txt, $me]);
                echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]); exit;
            case 'unextra':
                $pdo->prepare("DELETE FROM stock_check_items WHERE id = ? AND check_id = ? AND car_id = 0")->execute([(int)($in['item'] ?? 0), $cid]);
                echo json_encode(['ok' => true]); exit;
            case 'finish':
                if (!sc_access($pdo, $cid, $uid, false) && !$isBoss) break;
                echo json_encode(['ok' => smart_check_finish($pdo, $cid, $me)]); exit;
            case 'cancel':
                if (!$isBoss) break;
                $pdo->prepare("UPDATE stock_checks SET status = 'cancelled', finished_at = NOW(), finished_by = ? WHERE id = ? AND status IN ('active', 'expired')")->execute([$me, $cid]);
                smart_duty_scan($pdo);
                echo json_encode(['ok' => true]); exit;
            case 'unlock':
                if (!$isBoss) break;
                smart_unlock($pdo, (int)($in['uid'] ?? 0), $me);
                echo json_encode(['ok' => true]); exit;
        }
    } catch (Throwable $e) {
        error_log('stock_check: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'server']); exit;
    }
    echo json_encode(['ok' => false, 'error' => 'denied']); exit;
}

/* ─── which view ─── */
$viewId = (int)($_GET['id'] ?? 0);
if (!$viewId && !$isBoss && $myChecks) $viewId = (int)$myChecks[0]['id'];
$check = null;
if ($viewId) {
    if (!sc_access($pdo, $viewId, $uid, $isBoss)) { header('Location: dashboard.php?lang=' . $lang); exit; }
    $st = $pdo->prepare("SELECT *, TIMESTAMPDIFF(SECOND, NOW(), deadline) AS secs FROM stock_checks WHERE id = ?");
    $st->execute([$viewId]);
    $check = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$doMode = $check && in_array((int)$check['id'], $myIds, true) && in_array($check['status'], ['active', 'expired'], true);

$br  = fn($n) => push_branch_label($pdo, (string)$n, $lang);
$stLbl = $ar ? ['active' => '⏳ جارٍ', 'expired' => '⌛ انتهى الوقت', 'done' => '✅ مكتمل', 'cancelled' => '✖ ملغى']
             : ['active' => '⏳ running', 'expired' => '⌛ time up', 'done' => '✅ done', 'cancelled' => '✖ cancelled'];

if ($isBoss && !$check) {
    $branches = $pdo->query("SELECT b.name, b.name_ar, b.name_en, (SELECT COUNT(*) FROM cars c WHERE c.branch = b.name AND c.status IN ('available', 'reserved')) AS n FROM branches b ORDER BY b.id")->fetchAll(PDO::FETCH_ASSOC);
    $people = $pdo->query("SELECT id, username, role FROM users WHERE active = 1 ORDER BY FIELD(role,'manager','sales','admin'), username")->fetchAll(PDO::FETCH_ASSOC);
    $here = [];   // who is clocked in where right now
    try { foreach ($pdo->query("SELECT user_id, branch_name FROM attendance_logs WHERE clock_in >= CURDATE() AND clock_out IS NULL") as $r) $here[(int)$r['user_id']] = $r['branch_name']; } catch (Throwable $e) {}
    $list = $pdo->query("SELECT c.*, TIMESTAMPDIFF(SECOND, NOW(), c.deadline) AS secs,
                                (SELECT COUNT(*) FROM stock_check_items i WHERE i.check_id = c.id AND i.car_id > 0) AS t,
                                (SELECT COUNT(*) FROM stock_check_items i WHERE i.check_id = c.id AND i.car_id > 0 AND i.state IS NOT NULL) AS m,
                                (SELECT COUNT(*) FROM stock_check_items i WHERE i.check_id = c.id AND i.state = 'missing') AS x,
                                (SELECT GROUP_CONCAT(u.username SEPARATOR '، ') FROM stock_check_users s JOIN users u ON u.id = s.user_id WHERE s.check_id = c.id) AS who
                         FROM stock_checks c ORDER BY c.id DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
}
if ($check) {
    $items = $pdo->prepare("SELECT * FROM stock_check_items WHERE check_id = ? ORDER BY car_id = 0, label, chassis");
    $items->execute([(int)$check['id']]);
    $items = $items->fetchAll(PDO::FETCH_ASSOC);
    $assigned = smart_check_users($pdo, (int)$check['id']);
    $lockedIds = [];
    if ($assigned) {
        $in = implode(',', array_keys($assigned));
        $lockedIds = array_map('intval', $pdo->query("SELECT user_id FROM user_locks WHERE unlocked_at IS NULL AND user_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
    }
    [$cT, $cM, $cP, $cX, $cE] = smart_check_counts($pdo, (int)$check['id']);
}
$ago = function ($s) use ($ar): string {
    $s = max(0, (int)$s);
    if ($s < 60) return $ar ? 'الآن' : 'just now';
    if ($s < 3600) { $m = intdiv($s, 60); return $ar ? 'منذ ' . $m . ' دقيقة' : $m . ' min ago'; }
    if ($s < 86400) { $h = intdiv($s, 3600); return $ar ? 'منذ ' . $h . ' ساعة' : $h . ' h ago'; }
    $d = intdiv($s, 86400); return $ar ? 'منذ ' . $d . ' يوم' : $d . ' days ago';
};
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= $ar ? 'الجرد المفاجئ' : 'Surprise stock check' ?> — First 1 Car</title>
<?php include __DIR__ . '/pwa_head.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<?php include __DIR__ . '/notify_style.php'; ?>
<style>
.sc-form{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.sc-l{display:block;font-size:12.5px;font-weight:800;color:var(--mut);margin:0 0 8px}
.sc-brs{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px}
.sc-b{padding:12px;border-radius:14px;border:1px solid var(--line);background:rgba(255,255,255,.03);color:var(--txt);font:inherit;cursor:pointer;text-align:start}
.sc-b b{display:block;font-size:15px}.sc-b small{font-size:12px;color:var(--mut);font-weight:700}
.sc-b.on{border-color:rgba(56,189,248,.7);background:linear-gradient(150deg,rgba(14,165,233,.2),rgba(99,102,241,.12));box-shadow:0 8px 22px rgba(14,165,233,.2)}
.sc-ppl{display:flex;flex-wrap:wrap;gap:6px}
.sc-p{height:36px;padding:0 13px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--txt);font:inherit;font-size:13px;font-weight:800;cursor:pointer}
.sc-p small{font-size:10.5px;color:#86efac;margin-inline-start:4px}
.sc-p.on{background:linear-gradient(90deg,#0ea5e9,#6366f1);border-color:transparent;color:#fff}.sc-p.on small{color:#e0f2fe}
.sc-times{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.sc-t{height:36px;padding:0 13px;border-radius:10px;border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--txt);font:inherit;font-size:13px;font-weight:800;cursor:pointer}
.sc-t.on{background:rgba(245,158,11,.2);border-color:rgba(245,158,11,.6);color:#fde68a}
.sc-in{height:40px;border-radius:12px;border:1px solid var(--line);background:#0b1426;color:var(--txt);font:inherit;font-size:14px;padding:0 12px}
.sc-num{width:90px;text-align:center;font-weight:800}
.sc-note{width:100%}
.sc-opt{display:flex;align-items:center;gap:10px;margin-top:12px;font-size:13.5px;font-weight:800}
.sc-go{margin-top:16px;width:100%;height:54px;border:0;border-radius:16px;background:linear-gradient(90deg,#dc2626,#9333ea);color:#fff;font:inherit;font-size:17px;font-weight:900;cursor:pointer;box-shadow:0 12px 30px rgba(220,38,38,.3)}
.sc-go:disabled{opacity:.6}
.sc-list{display:grid;gap:8px}
.sc-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 14px;border-radius:14px;border:1px solid var(--line);background:rgba(255,255,255,.03);text-decoration:none;color:var(--txt)}
.sc-row .i{flex:1;min-width:180px}.sc-row b{font-size:14.5px}.sc-row small{display:block;font-size:12px;color:var(--mut);font-weight:700;margin-top:2px}
.sc-st{font-size:12px;font-weight:900;padding:4px 10px;border-radius:999px;background:rgba(255,255,255,.06)}
.sc-st.active{background:rgba(56,189,248,.15);color:#7dd3fc}.sc-st.expired{background:rgba(239,68,68,.15);color:#fca5a5}.sc-st.done{background:rgba(34,197,94,.15);color:#86efac}
.sc-miss{font-size:12px;font-weight:900;color:#fca5a5}
.sc-bar{height:8px;border-radius:8px;background:rgba(255,255,255,.07);overflow:hidden;margin-top:6px}.sc-bar i{display:block;height:100%;background:linear-gradient(90deg,#22c55e,#38bdf8);transition:width .3s}
/* doing the check */
.sc-top{position:sticky;top:0;z-index:5;padding:12px 14px;border-radius:0 0 18px 18px;margin:0 -2px 14px;background:linear-gradient(160deg,rgba(15,23,42,.97),rgba(30,27,75,.97));border:1px solid rgba(255,255,255,.08);border-top:0;backdrop-filter:blur(10px)}
.sc-top .r1{display:flex;align-items:center;gap:10px;justify-content:space-between}
.sc-cd{font-family:Inter,sans-serif;font-size:22px;font-weight:900;font-variant-numeric:tabular-nums;color:#7dd3fc}
.sc-cd.low{color:#fbbf24}.sc-cd.over{color:#f87171}
.sc-prog{font-size:13px;font-weight:800;color:var(--mut)}
.sc-search{width:100%;margin-top:8px}
.sc-it{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:12px;border-radius:14px;border:1px solid var(--line);background:rgba(255,255,255,.03);margin-bottom:8px}
.sc-it .i{flex:1;min-width:160px}.sc-it b{display:block;font-size:14.5px}.sc-it .ch{font-family:Inter,monospace;color:#a3e635;font-weight:800;font-size:13px}
.sc-it.present{border-color:rgba(34,197,94,.5);background:rgba(34,197,94,.07)}.sc-it.missing{border-color:rgba(239,68,68,.55);background:rgba(239,68,68,.08)}
.sc-it .bt{display:flex;gap:6px}
.sc-it button{height:40px;padding:0 12px;border-radius:11px;border:1px solid var(--line);background:rgba(255,255,255,.05);color:var(--txt);font:inherit;font-size:13px;font-weight:900;cursor:pointer}
.sc-it button.y.on{background:#16a34a;border-color:#16a34a;color:#fff}.sc-it button.n.on{background:#dc2626;border-color:#dc2626;color:#fff}
.sc-it .nt{width:100%;display:none}.sc-it.missing .nt{display:block}
.sc-it .by{font-size:11px;color:var(--mut);font-weight:700}
.sc-ex{display:flex;gap:8px;margin-top:6px}.sc-ex input{flex:1}
.sc-exl span{display:inline-flex;align-items:center;gap:6px;margin:6px 6px 0 0;padding:5px 10px;border-radius:999px;background:rgba(250,204,21,.1);border:1px solid rgba(250,204,21,.35);color:#fde68a;font-size:12.5px;font-weight:800}
.sc-exl span button{border:0;background:none;color:#fca5a5;cursor:pointer;font-size:13px}
.sc-fin{width:100%;height:54px;margin-top:12px;border:0;border-radius:16px;background:linear-gradient(90deg,#16a34a,#0ea5e9);color:#fff;font:inherit;font-size:17px;font-weight:900;cursor:pointer}
.sc-fin:disabled{opacity:.45;cursor:default}
.sc-ppl2 span{display:inline-flex;align-items:center;gap:8px;margin:0 6px 6px 0;padding:6px 10px;border-radius:12px;border:1px solid var(--line);font-weight:800;font-size:13px}
@media (max-width:760px){.sc-form{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="nf-wrap">
    <header class="nf-head">
        <div><h1>📋 <?= $ar ? 'الجرد المفاجئ' : 'Surprise stock check' ?></h1>
            <p><?= $ar ? 'تأكيد وجود كل سيارة في الفرع خلال وقت محدد' : 'Confirm every car at a branch within a set time' ?></p></div>
        <div class="nf-nav">
            <?php if ($isBoss): ?><a class="nf-btn pur" href="permissions_admin.php?lang=<?= $lang ?>">🔐 <?= $ar ? 'الصلاحيات' : 'Permissions' ?></a><?php endif; ?>
            <?php if ($isBoss && $check): ?><a class="nf-btn" href="stock_check.php?lang=<?= $lang ?>">📋 <?= $ar ? 'كل عمليات الجرد' : 'All checks' ?></a><?php endif; ?>
            <?php if (!user_lock_active($pdo, $uid)): ?><a class="nf-btn" href="dashboard.php?lang=<?= $lang ?>">🏠 <?= $ar ? 'الرئيسية' : 'Dashboard' ?></a><?php else: ?><a class="nf-btn" href="transfer_lock.php?lang=<?= $lang ?>">🔒 <?= $ar ? 'رجوع' : 'Back' ?></a><?php endif; ?>
            <a class="nf-btn ghost" href="?lang=<?= $ar ? 'en' : 'ar' ?><?= $check ? '&id=' . (int)$check['id'] : '' ?>"><?= $ar ? 'English' : 'العربية' ?></a>
        </div>
    </header>

<?php if ($isBoss && !$check): ?>
    <section class="nf-card">
        <h2>🚨 <?= $ar ? 'جرد مفاجئ جديد' : 'New surprise check' ?></h2>
        <p class="nf-note"><?= $ar ? 'يصل للمكلَّف فوراً على موبايله وداخل النظام. قائمة السيارات هي الموجودة في الفرع لحظة الإرسال.' : 'It reaches the people chosen at once, on their phone and in the system. The list is the cars at the branch right now.' ?></p>
        <div class="sc-form">
            <div>
                <label class="sc-l">1) <?= $ar ? 'الفرع' : 'Branch' ?></label>
                <div class="sc-brs">
                    <?php foreach ($branches as $b): ?>
                    <button type="button" class="sc-b" data-b="<?= htmlspecialchars($b['name']) ?>"><b>📍 <?= htmlspecialchars(($ar ? $b['name_ar'] : $b['name_en']) ?: $b['name']) ?></b><small>🚗 <?= $ar ? ((int)$b['n'] ? push_ar_cars((int)$b['n']) : 'لا توجد سيارات') : (int)$b['n'] . ' cars' ?></small></button>
                    <?php endforeach; ?>
                </div>
                <label class="sc-l" style="margin-top:16px">3) <?= $ar ? 'المدة المسموحة' : 'Time allowed' ?></label>
                <div class="sc-times">
                    <?php foreach ([15, 30, 45, 60, 90, 120] as $m): ?><button type="button" class="sc-t<?= $m === 30 ? ' on' : '' ?>" data-m="<?= $m ?>"><?= $ar ? ([15 => '15 دقيقة', 30 => '30 دقيقة', 45 => '45 دقيقة', 60 => 'ساعة', 90 => 'ساعة ونصف', 120 => 'ساعتان'][$m]) : ($m < 60 ? $m . ' min' : ($m / 60) . ' h') ?></button><?php endforeach; ?>
                    <input type="number" class="sc-in sc-num" id="scMin" min="5" max="1440" value="30"> <?= $ar ? 'دقيقة' : 'min' ?>
                </div>
            </div>
            <div>
                <label class="sc-l">2) <?= $ar ? 'المكلَّف بالجرد' : 'Who does the check' ?></label>
                <div class="sc-ppl">
                    <?php foreach ($people as $p): ?>
                    <button type="button" class="sc-p" data-u="<?= (int)$p['id'] ?>" data-here="<?= htmlspecialchars($here[(int)$p['id']] ?? '') ?>"><?= htmlspecialchars($p['username']) ?><small></small></button>
                    <?php endforeach; ?>
                </div>
                <label class="sc-l" style="margin-top:16px">4) <?= $ar ? 'ملاحظة (اختياري)' : 'Note (optional)' ?></label>
                <input class="sc-in sc-note" id="scNote" maxlength="200" placeholder="<?= $ar ? 'مثال: يرجى التأكد من الشاسيه لكل سيارة' : 'e.g. check every chassis number' ?>">
                <label class="sc-opt"><span class="tg"><input type="checkbox" id="scLock" checked><span></span></span> 🔒 <?= $ar ? 'إيقاف النظام عن المكلَّف إذا لم يكتمل الجرد في الوقت' : 'Lock the system for them if it is not finished in time' ?></label>
            </div>
        </div>
        <button type="button" class="sc-go" id="scGo">🚨 <?= $ar ? 'إرسال الجرد الآن' : 'Send the check now' ?></button>
        <div class="nf-msg" id="scMsg"></div>
    </section>

    <section class="nf-card">
        <h2>🗂️ <?= $ar ? 'عمليات الجرد' : 'Stock checks' ?></h2>
        <?php if (!$list): ?><div class="nf-empty"><?= $ar ? 'لم يتم إرسال أي جرد بعد' : 'No checks yet' ?></div><?php endif; ?>
        <div class="sc-list">
            <?php foreach ($list as $c): $pct = (int)$c['t'] ? round((int)$c['m'] * 100 / (int)$c['t']) : 100; ?>
            <a class="sc-row" href="?lang=<?= $lang ?>&id=<?= (int)$c['id'] ?>">
                <div class="i"><b>📍 <?= htmlspecialchars($br($c['branch'])) ?></b>
                    <small>👤 <?= htmlspecialchars((string)$c['who']) ?> · <?= htmlspecialchars($ago(-(int)$c['secs'] + (int)$c['minutes'] * 60)) ?></small>
                    <div class="sc-bar"><i style="width:<?= $pct ?>%"></i></div></div>
                <span class="sc-prog"><?= (int)$c['m'] ?>/<?= (int)$c['t'] ?></span>
                <?php if ((int)$c['x']): ?><span class="sc-miss">❌ <?= (int)$c['x'] ?></span><?php endif; ?>
                <span class="sc-st <?= $c['status'] ?>"><?= $stLbl[$c['status']] ?? $c['status'] ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </section>

<?php elseif ($check): ?>
    <div class="sc-top">
        <div class="r1">
            <div><b style="font-size:16px">📍 <?= htmlspecialchars($br($check['branch'])) ?></b>
                <div class="sc-prog"><span id="scDone"><?= $cM ?></span> / <?= $cT ?> · <span class="sc-st <?= $check['status'] ?>"><?= $stLbl[$check['status']] ?? '' ?></span></div></div>
            <?php if (in_array($check['status'], ['active', 'expired'], true)): ?><div class="sc-cd" id="scCd" data-secs="<?= (int)$check['secs'] ?>">—</div><?php endif; ?>
        </div>
        <div class="sc-bar"><i id="scBar" style="width:<?= $cT ? round($cM * 100 / $cT) : 100 ?>%"></i></div>
        <?php if ($doMode): ?><input class="sc-in sc-search" id="scQ" placeholder="🔍 <?= $ar ? 'ابحث بالشاسيه أو الموديل…' : 'Search chassis or model…' ?>"><?php endif; ?>
    </div>

    <?php if (!empty($check['note'])): ?><div class="nf-card" style="padding:12px 16px">📝 <?= htmlspecialchars($check['note']) ?></div><?php endif; ?>

    <?php if ($isBoss): ?>
    <section class="nf-card">
        <h2>👤 <?= $ar ? 'المكلَّفون' : 'Assigned' ?></h2>
        <div class="sc-ppl2">
            <?php foreach ($assigned as $aid => $an): ?>
            <span><?= htmlspecialchars($an) ?><?php if (in_array($aid, $lockedIds, true)): ?> 🔒 <button type="button" class="nf-mini" data-unlock="<?= $aid ?>"><?= $ar ? '🔓 فتح النظام' : '🔓 Unlock' ?></button><?php endif; ?></span>
            <?php endforeach; ?>
        </div>
        <?php if (in_array($check['status'], ['active', 'expired'], true)): ?><button type="button" class="nf-btn ghost" id="scCancel" style="margin-top:8px">✖ <?= $ar ? 'إلغاء الجرد' : 'Cancel check' ?></button><?php endif; ?>
        <?php if ($check['status'] === 'done'): ?><p class="nf-note">✅ <?= $ar ? 'أنهاه ' : 'Finished by ' ?><?= htmlspecialchars((string)$check['finished_by']) ?> · <?= htmlspecialchars((string)$check['finished_at']) ?> — <?= $ar ? 'موجودة ' : 'present ' ?><?= $cP ?> · <?= $ar ? 'غير موجودة ' : 'missing ' ?><?= $cX ?> · <?= $ar ? 'زائدة ' : 'extra ' ?><?= $cE ?></p><?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="nf-card" id="scItems">
        <?php foreach ($items as $it): if ((int)$it['car_id'] === 0) continue; ?>
        <div class="sc-it <?= htmlspecialchars((string)$it['state']) ?>" data-id="<?= (int)$it['id'] ?>" data-q="<?= htmlspecialchars(mb_strtolower($it['label'] . ' ' . $it['chassis'])) ?>">
            <div class="i"><b>🚗 <?= htmlspecialchars($it['label']) ?></b><span class="ch"><?= htmlspecialchars((string)$it['chassis']) ?></span>
                <?php if ($it['marked_by']): ?><div class="by">✍️ <?= htmlspecialchars($it['marked_by']) ?><?= $it['note'] ? ' · 📝 ' . htmlspecialchars($it['note']) : '' ?></div><?php endif; ?></div>
            <?php if ($doMode): ?>
            <div class="bt"><button type="button" class="y<?= $it['state'] === 'present' ? ' on' : '' ?>">✅ <?= $ar ? 'موجودة' : 'Here' ?></button><button type="button" class="n<?= $it['state'] === 'missing' ? ' on' : '' ?>">❌ <?= $ar ? 'غير موجودة' : 'Missing' ?></button></div>
            <input class="sc-in nt" maxlength="200" placeholder="<?= $ar ? 'ملاحظة: أين هي؟ (اختياري)' : 'Note: where is it? (optional)' ?>" value="<?= htmlspecialchars((string)$it['note']) ?>">
            <?php else: ?>
            <span class="sc-st <?= $it['state'] === 'present' ? 'done' : ($it['state'] === 'missing' ? 'expired' : '') ?>"><?= $it['state'] === 'present' ? ($ar ? '✅ موجودة' : '✅ here') : ($it['state'] === 'missing' ? ($ar ? '❌ غير موجودة' : '❌ missing') : ($ar ? '⏳ لم تُراجع' : '⏳ not checked')) ?></span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (!$cT): ?><div class="nf-empty"><?= $ar ? 'لا توجد سيارات مسجلة في هذا الفرع' : 'No cars recorded at this branch' ?></div><?php endif; ?>

        <h2 style="margin-top:14px">➕ <?= $ar ? 'سيارات موجودة في الفرع وغير مسجلة' : 'Cars at the branch but not in the system' ?></h2>
        <div class="sc-exl" id="scExl">
            <?php foreach ($items as $it): if ((int)$it['car_id'] !== 0) continue; ?>
            <span data-id="<?= (int)$it['id'] ?>"><?= htmlspecialchars($it['label']) ?><?php if ($doMode): ?> <button type="button">✕</button><?php endif; ?></span>
            <?php endforeach; ?>
        </div>
        <?php if ($doMode): ?>
        <div class="sc-ex"><input class="sc-in" id="scExIn" maxlength="200" placeholder="<?= $ar ? 'الموديل واللون ورقم الشاسيه' : 'Model, colour and chassis' ?>"><button type="button" class="nf-btn" id="scExAdd"><?= $ar ? 'إضافة' : 'Add' ?></button></div>
        <button type="button" class="sc-fin" id="scFin" <?= $cM < $cT ? 'disabled' : '' ?>>🏁 <?= $ar ? 'إنهاء الجرد وإرسال النتيجة' : 'Finish and send the result' ?></button>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="nf-card"><div class="nf-empty"><?= $ar ? 'لا يوجد جرد مطلوب منك الآن' : 'No stock check for you right now' ?></div></section>
<?php endif; ?>
</div>
<script>
(function () {
    const CSRF = <?= json_encode($csrf) ?>, AR = <?= json_encode($ar) ?>, CHECK = <?= (int)($check['id'] ?? 0) ?>;
    const $ = id => document.getElementById(id);
    const post = d => fetch(location.pathname, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ csrf: CSRF, check: CHECK }, d)) }).then(r => r.json());

    /* ── new check ── */
    if ($('scGo')) {
        let branch = '', mins = 30;
        const ppl = [...document.querySelectorAll('.sc-p')];
        const paintHere = () => ppl.forEach(p => { p.querySelector('small').textContent = branch && p.dataset.here === branch ? (AR ? '• في الفرع الآن' : '• at the branch') : ''; });
        document.querySelectorAll('.sc-b').forEach(b => b.addEventListener('click', () => { branch = b.dataset.b; document.querySelectorAll('.sc-b').forEach(x => x.classList.toggle('on', x === b)); paintHere(); }));
        ppl.forEach(p => p.addEventListener('click', () => p.classList.toggle('on')));
        document.querySelectorAll('.sc-t').forEach(t => t.addEventListener('click', () => { mins = +t.dataset.m; $('scMin').value = mins; document.querySelectorAll('.sc-t').forEach(x => x.classList.toggle('on', x === t)); }));
        $('scMin').addEventListener('input', () => { mins = +$('scMin').value; document.querySelectorAll('.sc-t').forEach(x => x.classList.toggle('on', +x.dataset.m === mins)); });
        $('scGo').addEventListener('click', async () => {
            const users = ppl.filter(p => p.classList.contains('on')).map(p => +p.dataset.u), m = $('scMsg');
            if (!branch) { m.textContent = AR ? 'اختر الفرع' : 'Pick the branch'; m.className = 'nf-msg bad'; return; }
            if (!users.length) { m.textContent = AR ? 'اختر المكلَّف بالجرد' : 'Pick who does the check'; m.className = 'nf-msg bad'; return; }
            if (!confirm(AR ? 'إرسال الجرد المفاجئ الآن؟' : 'Send the surprise check now?')) return;
            $('scGo').disabled = true;
            const r = await post({ action: 'create', branch, users, minutes: +$('scMin').value || 30, note: $('scNote').value, lock: $('scLock').checked });
            if (r.ok) location.href = '?lang=<?= $lang ?>&id=' + r.id; else { $('scGo').disabled = false; m.textContent = '✗ ' + r.error; m.className = 'nf-msg bad'; }
        });
    }

    /* ── countdown ── */
    const cd = $('scCd');
    if (cd) {
        const t0 = Date.now(), total = +cd.dataset.secs;
        const tick = () => { const s = total - Math.floor((Date.now() - t0) / 1000), a = Math.abs(s);
            cd.textContent = (s < 0 ? '−' : '') + Math.floor(a / 3600) + ':' + String(Math.floor(a % 3600 / 60)).padStart(2, '0') + ':' + String(a % 60).padStart(2, '0');
            cd.classList.toggle('over', s <= 0); cd.classList.toggle('low', s > 0 && s < 600); };
        tick(); setInterval(tick, 1000);
    }

    /* ── doing the check ── */
    const items = [...document.querySelectorAll('.sc-it')];
    function counts(c) { if (!c) return; $('scDone').textContent = c[1]; $('scBar').style.width = (c[0] ? Math.round(c[1] * 100 / c[0]) : 100) + '%'; if ($('scFin')) $('scFin').disabled = c[1] < c[0]; }
    items.forEach(it => {
        const y = it.querySelector('.y'), n = it.querySelector('.n'), nt = it.querySelector('.nt');
        if (!y) return;
        const set = async (state) => {
            it.classList.remove('present', 'missing'); it.classList.add(state);
            y.classList.toggle('on', state === 'present'); n.classList.toggle('on', state === 'missing');
            const r = await post({ action: 'mark', item: +it.dataset.id, state, note: state === 'missing' ? nt.value : '' });
            if (r.ok) counts(r.counts);
            if (state === 'missing') nt.focus();
        };
        y.addEventListener('click', () => set('present'));
        n.addEventListener('click', () => set('missing'));
        nt.addEventListener('change', () => post({ action: 'mark', item: +it.dataset.id, state: 'missing', note: nt.value }));
    });
    if ($('scQ')) $('scQ').addEventListener('input', e => { const q = e.target.value.trim().toLowerCase(); items.forEach(it => { it.style.display = !q || it.dataset.q.includes(q) ? '' : 'none'; }); });
    function bindX(span) { const b = span.querySelector('button'); if (b) b.addEventListener('click', async () => { await post({ action: 'unextra', item: +span.dataset.id }); span.remove(); }); }
    document.querySelectorAll('#scExl span').forEach(bindX);
    if ($('scExAdd')) $('scExAdd').addEventListener('click', async () => {
        const v = $('scExIn').value.trim(); if (!v) return;
        const r = await post({ action: 'extra', text: v });
        if (r.ok) { const s = document.createElement('span'); s.dataset.id = r.id; s.textContent = v + ' '; const b = document.createElement('button'); b.type = 'button'; b.textContent = '✕'; s.appendChild(b); $('scExl').appendChild(s); bindX(s); $('scExIn').value = ''; }
    });
    if ($('scFin')) $('scFin').addEventListener('click', async () => {
        if (!confirm(AR ? 'إنهاء الجرد وإرسال النتيجة للإدارة؟' : 'Finish and send the result?')) return;
        $('scFin').disabled = true;
        const r = await post({ action: 'finish' });
        if (r.ok) location.href = <?= json_encode(user_lock_active($pdo, $uid) ? 'transfer_lock.php?lang=' . $lang : 'dashboard.php?lang=' . $lang) ?>; else $('scFin').disabled = false;
    });

    /* ── admin ── */
    document.querySelectorAll('[data-unlock]').forEach(b => b.addEventListener('click', async () => { b.disabled = true; const r = await post({ action: 'unlock', uid: +b.dataset.unlock }); if (r.ok) location.reload(); }));
    if ($('scCancel')) $('scCancel').addEventListener('click', async () => { if (!confirm(AR ? 'إلغاء هذا الجرد؟' : 'Cancel this check?')) return; const r = await post({ action: 'cancel' }); if (r.ok) location.reload(); });
})();
</script>
</body>
</html>
