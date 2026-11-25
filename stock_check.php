<?php
/*
 * stock_check.php — الجرد المفاجئ (surprise stock check).
 *
 * The admin (page.stock_check) picks a branch, the person or people who do the
 * check and how long they have. The people chosen get a notification at once.
 * First they type how many cars are at the branch; then, depending on what the
 * admin chose:
 *   - «كل السيارات»: every car of the branch, each confirmed by typing its chassis
 *     («موجودة» / «غير موجودة»), plus any car that is there but not in the system;
 *   - «سيارات أختارها»: only the cars the admin picked — for each one the admin
 *     asks its chassis (typed off the car) or its colour (picked by scrolling the
 *     colours list, the car shown by its chassis). Before the time ends they and the admin are warned;
 * if it is not finished in time their system stops until the admin unlocks it.
 * The admin gets the result (what is missing) the moment it is finished.
 */
require 'auth.php';
require 'config.php';
require_once __DIR__ . '/notify_smart.php';
require_once __DIR__ . '/color_helpers.php';

$lang = ($_GET['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
$ar   = $lang === 'ar';
$dir  = $ar ? 'rtl' : 'ltr';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid  = (int)$_SESSION['user_id'];
$me   = (string)$_SESSION['username'];
$isBoss = can('page.stock_check');
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';   // admins see the chassis; everyone else types it off the car
push_tables($pdo);
check_pick_cols($pdo);
check_extra_tables($pdo);
smart_check_scan($pdo);

$myChecks = smart_checks_for_user($pdo, $uid);
$myIds = array_map(fn($c) => (int)$c['id'], $myChecks);
// while someone (not an admin) has a check to do, this page is ONLY that check for them: no list of
// other checks, no older results (they show chassis numbers), no starting / cancelling / unlocking
$inCheck = $myChecks && !$isAdmin;
if ($inCheck) $isBoss = false;
if (!$isBoss && !$myChecks && !isset($_GET['id'])) { header('Location: dashboard.php?lang=' . $lang); exit; }

/** May this person work on / see this check? */
function sc_access(PDO $pdo, int $id, int $uid, bool $boss): bool
{
    if ($boss) return true;
    $st = $pdo->prepare("SELECT 1 FROM stock_check_users WHERE check_id = ? AND user_id = ?");
    $st->execute([$id, $uid]);
    return (bool)$st->fetchColumn();
}

/** Is this check a one-car spot check? (it has no counting step) */
function sc_is_spot(PDO $pdo, int $id): bool
{
    try { $st = $pdo->prepare("SELECT spot FROM stock_checks WHERE id = ?"); $st->execute([$id]); return (bool)$st->fetchColumn(); } catch (Throwable $e) { return false; }
}

/* ─── 📷 photo proof (spot checks): kept outside the public folders' reach and shown only to whoever runs the checks ─── */
function sc_photo_dir(): string
{
    $d = __DIR__ . '/uploads/check_photos';
    if (!is_dir($d)) { @mkdir($d, 0755, true); @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n"); @file_put_contents($d . '/index.html', ''); }
    return $d;
}
function sc_photo_col(PDO $pdo): void
{
    static $done = false; if ($done) return; $done = true;
    try { $pdo->query("SELECT photo FROM stock_check_items LIMIT 1"); }
    catch (Throwable $e) { try { $pdo->exec("ALTER TABLE stock_check_items ADD photo VARCHAR(80) NULL, ADD photo_at DATETIME NULL"); } catch (Throwable $e2) {} }
}
if (isset($_GET['photo'])) {            // show one photo: the chassis plate, or the car itself (&k=car)
    sc_photo_col($pdo);
    $st = $pdo->prepare("SELECT " . (($_GET['k'] ?? '') === 'car' ? 'car_photo' : 'photo') . " FROM stock_check_items WHERE id = ?"); $st->execute([(int)$_GET['photo']]);
    $f = (string)$st->fetchColumn();
    if (!$isBoss || !preg_match('/^[a-f0-9]{32}\.jpg$/', $f) || !is_file(sc_photo_dir() . '/' . $f)) { http_response_code(404); exit('Not found'); }
    header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=3600'); header('X-Content-Type-Options: nosniff');
    readfile(sc_photo_dir() . '/' . $f); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo'])) {   // take one
    header('Content-Type: application/json; charset=utf-8');
    $cid = (int)($_POST['check'] ?? 0); $item = (int)($_POST['item'] ?? 0);
    $fail = function (string $e) { echo json_encode(['ok' => false, 'error' => $e]); exit; };
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $fail('csrf');
    if (!sc_access($pdo, $cid, $uid, false)) $fail('denied');
    if (chassis_locked($pdo, $uid)) $fail('locked');
    sc_photo_col($pdo);
    // taken with the camera inside the page (never a file from the phone's gallery: an older page that still offers one is refused)
    if (($_POST['src'] ?? '') !== 'camera') $fail('camera');
    // which photo: the chassis plate (photo) or the car itself (car_photo) — only one the admin asked for, of a car this person already confirmed, in a running check
    $isCar = ($_POST['kind'] ?? '') === 'car';
    $col = $isCar ? 'car_photo' : 'photo';
    $st = $pdo->prepare("SELECT i.id FROM stock_check_items i JOIN stock_checks c ON c.id = i.check_id WHERE i.id = ? AND i.check_id = ? AND i.state = 'present' AND (i.photo_req & ?) <> 0 AND c.status IN ('active', 'expired')");
    $st->execute([$item, $cid, $isCar ? 2 : 1]);
    if (!$st->fetchColumn()) $fail('item');
    $up = $_FILES['photo'];
    if (($up['error'] ?? 1) !== UPLOAD_ERR_OK || ($up['size'] ?? 0) < 2000 || $up['size'] > 6 * 1024 * 1024) $fail('file');
    $info = @getimagesize($up['tmp_name']);
    if (!$info || $info[2] !== IMAGETYPE_JPEG || $info[0] < 200 || $info[1] < 200) $fail('image');
    $name = bin2hex(random_bytes(16)) . '.jpg';
    if (!@move_uploaded_file($up['tmp_name'], sc_photo_dir() . '/' . $name)) $fail('save');
    $old = $pdo->prepare("SELECT $col FROM stock_check_items WHERE id = ?"); $old->execute([$item]);
    $oldF = (string)$old->fetchColumn();
    $pdo->prepare("UPDATE stock_check_items SET $col = ?, {$col}_at = NOW(), {$col}_by = ? WHERE id = ?")->execute([$name, $me, $item]);
    if (preg_match('/^[a-f0-9]{32}\.jpg$/', $oldF)) @unlink(sc_photo_dir() . '/' . $oldF);
    echo json_encode(['ok' => true]); exit;
}

/* ─── JSON actions ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($csrf, (string)($in['csrf'] ?? ''))) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }
    if (in_array($in['lang'] ?? '', ['ar', 'en'], true)) { $lang = $in['lang']; $ar = $lang === 'ar'; }   // the page's language, for the names it gets back
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
                $picks = []; $shots = [];   // «سيارات أختارها»: car id => 'chassis' | 'color' · and car id => the photo the admin chose for it (check_photo_kind)
                if (($in['mode'] ?? 'all') === 'pick') {
                    foreach ((array)($in['picks'] ?? []) as $pk) {
                        $pc = (int)($pk['car'] ?? 0); if ($pc < 1) continue;
                        $picks[$pc] = ($pk['ask'] ?? '') === 'color' ? 'color' : 'chassis';
                        if ($pk2 = check_photo_kind($pk['photo'] ?? 0)) $shots[$pc] = $pk2;
                    }
                    if (!$picks) { echo json_encode(['ok' => false, 'error' => 'nocars']); exit; }
                }
                $opt = ['photos' => max(0, min(3, (int)($in['photos'] ?? 0))), 'photo_kind' => check_photo_kind($in['photo_kind'] ?? 1) ?: 1, 'questions' => []];
                if ($shots) { $opt['photos'] = 0; $opt['photo_cars'] = $shots; }   // 📷 the admin chose the cars and what to photograph: nothing at random
                foreach (array_slice((array)($in['questions'] ?? []), 0, 4) as $q) $opt['questions'][] = ['kind' => ($q['kind'] ?? '') === 'brand' ? 'brand' : 'color', 'key' => mb_substr((string)($q['key'] ?? ''), 0, 100)];
                $users = array_values(array_unique(array_filter($users)));
                $args = fn(array $who, array $o) => [$pdo, $branch, $who, (int)($in['minutes'] ?? 30), trim((string)($in['note'] ?? '')), !empty($in['lock']), $me, array_map('intval', (array)($in['watch'] ?? [])),
                                                    array_keys($picks), false, array_keys(array_filter($picks, fn($a) => $a === 'color')), $o];
                if (!empty($in['double']) && count($users) >= 2) {
                    // 👥 a double check: the same check for each person alone (the followers are told once)
                    $id = smart_check_create(...$args([$users[0]], $opt));
                    if ($id) {
                        $pdo->prepare("UPDATE stock_checks SET group_id = ? WHERE id = ?")->execute([$id, $id]);
                        foreach (array_slice($users, 1) as $u2) smart_check_create(...$args([$u2], $opt + ['group' => $id, 'quiet' => true]));
                    }
                } else {
                    $id = smart_check_create(...$args($users, $opt));
                }
                if (!$id) { echo json_encode(['ok' => false, 'error' => 'gone']); exit; }   // none of the chosen cars is at the branch any more
                echo json_encode(['ok' => true, 'id' => $id]); exit;
            case 'cars':     // a branch's cars, to choose which ones to check: when each was added and when it last arrived
                if (!$isBoss) break;
                car_seen_cols($pdo);
                $st = $pdo->prepare("SELECT c.id, c.brand, c.model, c.trim_name, c.car_year, c.color, c.chassis, c.status,
                                            DATE_FORMAT(c.created_at, '%Y-%m-%d') AS added, TIMESTAMPDIFF(DAY, c.created_at, NOW()) AS added_d,
                                            m.event_type AS mv_type, m.from_branch AS mv_from, DATE_FORMAT(m.created_at, '%Y-%m-%d') AS mv, TIMESTAMPDIFF(DAY, m.created_at, NOW()) AS mv_d,
                                            TIMESTAMPDIFF(DAY, c.last_seen_at, NOW()) AS seen_d,
                                            UNIX_TIMESTAMP(GREATEST(COALESCE(c.created_at, '2000-01-01'), COALESCE(m.created_at, c.created_at, '2000-01-01'))) AS arr
                                     FROM cars c
                                     LEFT JOIN (SELECT car_id, to_branch, MAX(id) AS mid FROM movements WHERE event_type IN ('transfer', 'amana_return', 'sale_return') GROUP BY car_id, to_branch) lm
                                            ON lm.car_id = c.id AND lm.to_branch = c.branch
                                     LEFT JOIN movements m ON m.id = lm.mid
                                     WHERE c.branch = ? AND c.status IN ('available', 'reserved') ORDER BY arr DESC, c.id DESC");
                $st->execute([(string)($in['branch'] ?? '')]);
                $out = [];
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = [
                    'id' => (int)$r['id'], 'name' => trim($r['brand'] . ' ' . $r['model'] . ' ' . $r['trim_name'] . ' ' . $r['car_year']),
                    'color' => push_color_label($pdo, (string)$r['color'], $lang), 'hex' => car_color_hex((string)$r['color']), 'chassis' => (string)$r['chassis'],
                    'res' => $r['status'] === 'reserved', 'added' => $r['added'], 'added_d' => $r['added_d'] === null ? null : (int)$r['added_d'],
                    'mv' => $r['mv'], 'mv_d' => $r['mv_d'] === null ? null : (int)$r['mv_d'], 'mv_type' => (string)$r['mv_type'],
                    'mv_from' => $r['mv_from'] ? push_branch_label($pdo, (string)$r['mv_from'], $lang) : '',
                    'seen_d' => $r['seen_d'] === null ? null : (int)$r['seen_d'], 'arr' => (int)$r['arr'],
                ];
                echo json_encode(['ok' => true, 'cars' => $out, 'suggest' => check_q_suggest($pdo, (string)($in['branch'] ?? ''), $lang)]); exit;
            case 'spot':     // ⚡ one random car of the branch, for one person
                if (!$isBoss) break;
                $branch = (string)($in['branch'] ?? ''); $who = (int)($in['user'] ?? 0);
                $okB = $pdo->prepare("SELECT 1 FROM branches WHERE name = ?"); $okB->execute([$branch]);
                if (!$okB->fetchColumn() || !$who) { echo json_encode(['ok' => false, 'error' => 'pick']); exit; }
                $id = check_spot_create($pdo, $branch, $who, (int)($in['minutes'] ?? 5), $me, check_photo_kind($in['photo'] ?? 0));
                echo json_encode($id ? ['ok' => true, 'id' => $id] : ['ok' => false, 'error' => 'nocar']); exit;
            case 'plan':     // the admin's answer to a check the system proposed
                if (!$isAdmin) break;
                $id = check_auto_decide($pdo, (int)($in['plan'] ?? 0), !empty($in['send']), $me, (int)($in['minutes'] ?? 0), check_photo_kind($in['photo'] ?? 0));
                echo json_encode(['ok' => true, 'id' => $id]); exit;
            case 'auto_rules':
                if (!$isAdmin) break;
                push_setting_set($pdo, 'check_auto', json_encode(['on' => !empty($in['on']), 'full' => max(0, min(7, (int)($in['full'] ?? 1))), 'spot' => max(0, min(14, (int)($in['spot'] ?? 2))), 'ping' => max(0, min(21, (int)($in['ping'] ?? 0))),
                    'from' => max(0, min(22, (int)($in['from'] ?? 11))), 'to' => max(1, min(23, (int)($in['to'] ?? 20)))]), $me);
                // this week's plans that were not asked yet are planned again with the new numbers
                check_auto_tables($pdo); $pdo->exec("DELETE FROM stock_check_plans WHERE status = 'planned' AND kind <> 'recheck'");
                $wk = $pdo->prepare("UPDATE stock_check_plans SET week_key = CONCAT(week_key, 'x') WHERE week_key = ? AND kind <> 'recheck'"); $wk->execute([date('o-W')]);
                echo json_encode(['ok' => true]); exit;
            case 'count':
                // the first step: how many cars are at the branch? (answered once; the admin sees it against the system)
                if ($isAdmin || !sc_access($pdo, $cid, $uid, false)) break;
                if (chassis_locked($pdo, $uid)) { echo json_encode(['ok' => false, 'error' => 'locked', 'go' => 'transfer_lock.php?lang=' . $lang]); exit; }
                $n = (int)($in['n'] ?? -1);
                if (!is_numeric($in['n'] ?? null) || $n < 0 || $n > 5000) { echo json_encode(['ok' => false, 'error' => 'number']); exit; }
                if (check_counted($pdo, $cid, $uid) !== null) { echo json_encode(['ok' => true]); exit; }
                $ck = $pdo->prepare("SELECT branch FROM stock_checks WHERE id = ? AND status IN ('active', 'expired')"); $ck->execute([$cid]);
                $branchOf = $ck->fetchColumn();
                if ($branchOf === false) break;
                $bTotal = check_branch_total($pdo, $cid);   // the whole branch, also when only chosen cars are checked
                // the number must match the system: a wrong one warns once, the second stops the system (the admin sees typed against real)
                $t = check_count_try($pdo, $cid, $uid, $me, $n, $bTotal, (string)$branchOf);
                if ($t['ok'] || $t['locked']) {
                    // told to the people following the check — never to the people doing it (it carries the system's number)
                    $doing = array_values(smart_check_users($pdo, $cid));
                    $tell = array_values(array_diff(array_unique(array_merge(
                        array_map('strval', $pdo->query("SELECT username FROM users WHERE active = 1 AND role = 'admin'")->fetchAll(PDO::FETCH_COLUMN)), smart_check_watchers($pdo, $cid))), $doing));
                    if ($tell) notify_event($pdo, 'check_item', ['id' => $cid, 'branch' => (string)$branchOf, 'counted' => $n, 'total' => $bTotal, 'actor_name' => $me,
                        'actor' => $me, 'only' => $tell, 'tag' => 'check-count-' . $cid . '-' . $uid, 'force' => true, 'now' => true]);
                }
                if (!$t['ok']) { echo json_encode(['ok' => false, 'error' => 'count', 'locked' => $t['locked'], 'go' => $t['locked'] ? 'transfer_lock.php?lang=' . $lang : '']); exit; }
                echo json_encode(['ok' => true]); exit;
            case 'qcount':   // 🔢 a count question (by colour or brand), after the total
                if ($isAdmin || !sc_access($pdo, $cid, $uid, false)) break;
                if (chassis_locked($pdo, $uid)) { echo json_encode(['ok' => false, 'error' => 'locked', 'go' => 'transfer_lock.php?lang=' . $lang]); exit; }
                $n = (int)($in['n'] ?? -1);
                if (!is_numeric($in['n'] ?? null) || $n < 0 || $n > 5000) { echo json_encode(['ok' => false, 'error' => 'number']); exit; }
                $ck = $pdo->prepare("SELECT 1 FROM stock_checks WHERE id = ? AND status IN ('active', 'expired')"); $ck->execute([$cid]);
                if (!$ck->fetchColumn() || check_counted($pdo, $cid, $uid) === null) break;
                $qid = (int)($in['q'] ?? 0);
                if (!in_array($qid, array_map(fn($q) => (int)$q['id'], check_q_pending($pdo, $cid, $uid)), true)) { echo json_encode(['ok' => true]); exit; }   // answered already
                $t = check_q_try($pdo, $cid, $qid, $uid, $me, $n);
                if (!$t['ok']) { echo json_encode(['ok' => false, 'error' => 'count', 'locked' => $t['locked'], 'go' => $t['locked'] ? 'transfer_lock.php?lang=' . $lang : '']); exit; }
                echo json_encode(['ok' => true]); exit;
            case 'mark':
                if (!sc_access($pdo, $cid, $uid, false) && !$isBoss) break;
                if (!$isAdmin && !sc_is_spot($pdo, $cid) && !check_step1_done($pdo, $cid, $uid) && sc_access($pdo, $cid, $uid, false)) { echo json_encode(['ok' => false, 'error' => 'count_first']); exit; }
                $state = in_array($in['state'] ?? '', ['present', 'missing'], true) ? $in['state'] : null;
                $note  = mb_substr(trim((string)($in['note'] ?? '')), 0, 250);
                if (!$isAdmin && chassis_locked($pdo, $uid)) { echo json_encode(['ok' => false, 'error' => 'locked', 'go' => 'transfer_lock.php?lang=' . $lang]); exit; }
                $askQ = $pdo->prepare("SELECT i.ask, i.label, i.car_id, c.branch FROM stock_check_items i JOIN stock_checks c ON c.id = i.check_id WHERE i.id = ? AND i.check_id = ?");
                $askQ->execute([(int)($in['item'] ?? 0), $cid]);
                $askRow = $askQ->fetch(PDO::FETCH_ASSOC) ?: ['ask' => '', 'label' => '', 'car_id' => 0, 'branch' => ''];
                $ask = (string)$askRow['ask'];
                $answer = null;
                $loc = null;
                if (!$isAdmin && $state === 'present' && $askRow['branch'] !== '') {
                    // 📍 the phone's position when the car is confirmed: far from the branch → not accepted, and it counts as a wrong answer
                    $num = fn($k) => is_numeric($in[$k] ?? null) ? (float)$in[$k] : null;
                    $loc = check_loc_judge($pdo, (string)$askRow['branch'], $num('lat'), $num('lng'), is_numeric($in['acc'] ?? null) ? (int)round((float)$in['acc']) : null);
                    if ($loc['flag'] === 'far') {
                        $t = check_far_try($pdo, $uid, $me, $cid, (int)($in['item'] ?? 0), (int)$askRow['car_id'], (string)$askRow['label'], (string)$askRow['branch'], (int)$loc['dist'], (int)$loc['allowed']);
                        echo json_encode(['ok' => false, 'error' => 'far', 'dist' => check_m((int)$loc['dist'], $lang), 'locked' => $t['locked'], 'left' => $t['left'], 'go' => $t['locked'] ? 'transfer_lock.php?lang=' . $lang : '']); exit;
                    }
                }
                if (!$isAdmin && $state === 'present' && $ask === 'color') {
                    // «موجودة» for a car asked by its colour = the colour picked from the list, checked against the car
                    $ci = $pdo->prepare("SELECT i.id, i.car_id, i.label, i.color, c.branch FROM stock_check_items i JOIN stock_checks c ON c.id = i.check_id
                                         WHERE i.id = ? AND i.check_id = ? AND i.car_id > 0 AND i.ask = 'color' AND c.status IN ('active', 'expired') AND (i.state IS NULL OR i.state <> 'present')");
                    $ci->execute([(int)($in['item'] ?? 0), $cid]);
                    $row = $ci->fetch(PDO::FETCH_ASSOC);
                    if (!$row) { echo json_encode(['ok' => false, 'error' => 'gone']); exit; }
                    $picked = mb_substr(trim((string)($in['color'] ?? '')), 0, 100);
                    if ($picked === '') { echo json_encode(['ok' => false, 'error' => 'empty']); exit; }
                    $t = check_color_try($pdo, $uid, $me, (int)$row['id'], (int)$row['car_id'], $picked, (string)$row['color'], (string)$row['label'], (string)$row['branch']);
                    if (!$t['ok']) {
                        echo json_encode(['ok' => false, 'error' => 'color', 'locked' => $t['locked'], 'left' => $t['left'], 'go' => $t['locked'] ? 'transfer_lock.php?lang=' . $lang : '']); exit;
                    }
                    $answer = $picked;
                } elseif (!$isAdmin && $state === 'present') {
                    // «موجودة» = the chassis typed off the car. It may be any car of this check whose chassis is asked and that is not confirmed yet (two cars of the same model).
                    $cs = $pdo->prepare("SELECT i.id, i.car_id, i.label, i.chassis, c.branch FROM stock_check_items i JOIN stock_checks c ON c.id = i.check_id
                                         WHERE i.check_id = ? AND i.car_id > 0 AND i.ask = 'chassis' AND c.status IN ('active', 'expired') AND (i.state IS NULL OR i.state <> 'present')");
                    $cs->execute([$cid]);
                    $cands = []; $place = '';
                    foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $r) { $cands[(int)$r['id']] = ['car_id' => (int)$r['car_id'], 'chassis' => (string)$r['chassis'], 'label' => (string)$r['label']]; $place = (string)$r['branch']; }
                    $row = (int)($in['item'] ?? 0);
                    if (!isset($cands[$row])) { echo json_encode(['ok' => false, 'error' => 'gone']); exit; }
                    $t = chassis_try($pdo, $uid, $me, 'check', $cands, $row, (string)($in['chassis'] ?? ''), $place);
                    if (!$t['ok']) {
                        echo json_encode(['ok' => false, 'error' => $t['empty'] ? 'empty' : 'chassis', 'locked' => $t['locked'], 'left' => $t['left'], 'go' => $t['locked'] ? 'transfer_lock.php?lang=' . $lang : '']); exit;
                    }
                    $in['item'] = $t['ref'];   // the car this number belongs to
                    $shown = (string)$cands[$t['ref']]['chassis'];
                }
                $prev = $pdo->prepare("SELECT i.state, i.note, i.label, i.chassis, i.ask, i.color, c.branch FROM stock_check_items i JOIN stock_checks c ON c.id = i.check_id WHERE i.id = ? AND i.check_id = ?");
                $prev->execute([(int)($in['item'] ?? 0), $cid]);
                $prev = $prev->fetch(PDO::FETCH_ASSOC);
                $pdo->prepare("UPDATE stock_check_items i JOIN stock_checks c ON c.id = i.check_id SET i.state = ?, i.note = ?, i.marked_by = ?, i.marked_at = NOW(), i.answer = ?
                               WHERE i.id = ? AND i.check_id = ? AND i.car_id > 0 AND c.status IN ('active', 'expired')")
                    ->execute([$state, $note ?: null, $me, $state === 'present' ? $answer : null, (int)($in['item'] ?? 0), $cid]);
                if ($state === 'present' && $prev) {   // physically confirmed: remembered on the car itself
                    $ci = $pdo->prepare("SELECT car_id FROM stock_check_items WHERE id = ? AND check_id = ?"); $ci->execute([(int)($in['item'] ?? 0), $cid]);
                    car_seen($pdo, (int)$ci->fetchColumn(), $me, sc_is_spot($pdo, $cid) ? 'spot' : 'check');
                }
                $needPhoto = 0;
                if ($loc && $state === 'present') {
                    $pdo->prepare("UPDATE stock_check_items SET lat = ?, lng = ?, acc = ?, dist_m = ?, loc_flag = ? WHERE id = ? AND check_id = ?")
                        ->execute([is_numeric($in['lat'] ?? null) ? (float)$in['lat'] : null, is_numeric($in['lng'] ?? null) ? (float)$in['lng'] : null, $loc['acc'], $loc['dist'], $loc['flag'], (int)($in['item'] ?? 0), $cid]);
                    check_after_confirm($pdo, $cid, $uid, $me, (string)$askRow['branch'], (string)$loc['flag']);   // ⚡ too fast / 📵 no location → the admins
                    $pq = $pdo->prepare("SELECT IF((photo_req & 1) <> 0 AND photo IS NULL, 1, 0) + IF((photo_req & 2) <> 0 AND car_photo IS NULL, 2, 0) FROM stock_check_items WHERE id = ? AND check_id = ?");
                    $pq->execute([(int)($in['item'] ?? 0), $cid]);
                    $needPhoto = (int)$pq->fetchColumn();   // 📷 told only now, after the car was confirmed: 1 chassis · 2 car · 3 both
                }
                $counts = smart_check_counts($pdo, $cid);
                // the admin follows the check car by car (one notification per check on the phone, updated each time)
                if ($prev && $state && ($prev['state'] !== $state || ($state === 'missing' && $note !== '' && $note !== (string)$prev['note']))) {
                    notify_event($pdo, 'check_item', ['id' => $cid, 'branch' => $prev['branch'], 'label' => $prev['label'], 'chassis' => $state === 'present' ? $prev['chassis'] : '', 'state' => $state,
                        'color' => $state === 'present' && ($prev['ask'] ?? '') === 'color' ? push_color_label($pdo, (string)$prev['color'], notify_options($pdo)['lang']) : '',
                        'note' => $note, 'done' => $counts[1], 'total' => $counts[0], 'missing' => $counts[3], 'actor' => $me, 'tag' => 'check-' . $cid]
                        + (($w = smart_check_watchers($pdo, $cid)) ? ['only' => $w] : []));
                }
                echo json_encode(['ok' => true, 'counts' => $counts, 'item' => (int)($in['item'] ?? 0), 'chassis' => $shown ?? null,
                                  'color' => $answer !== null ? push_color_label($pdo, $answer, $lang) : null, 'photo' => $needPhoto]); exit;
            case 'extra':
                if (!sc_access($pdo, $cid, $uid, false) && !$isBoss) break;
                if (!$isAdmin && !sc_is_spot($pdo, $cid) && !check_step1_done($pdo, $cid, $uid) && sc_access($pdo, $cid, $uid, false)) { echo json_encode(['ok' => false, 'error' => 'count_first']); exit; }
                $txt = mb_substr(trim((string)($in['text'] ?? '')), 0, 200);
                if ($txt === '') { echo json_encode(['ok' => false, 'error' => 'empty']); exit; }
                $pdo->prepare("INSERT INTO stock_check_items (check_id, car_id, label, state, marked_by, marked_at) VALUES (?, 0, ?, 'extra', ?, NOW())")->execute([$cid, $txt, $me]);
                echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]); exit;
            case 'unextra':
                if (!sc_access($pdo, $cid, $uid, false) && !$isBoss) break;
                $pdo->prepare("DELETE FROM stock_check_items WHERE id = ? AND check_id = ? AND car_id = 0")->execute([(int)($in['item'] ?? 0), $cid]);
                echo json_encode(['ok' => true]); exit;
            case 'finish':
                if (!sc_access($pdo, $cid, $uid, false) && !$isBoss) break;
                if (!$isAdmin && !sc_is_spot($pdo, $cid) && !check_step1_done($pdo, $cid, $uid) && sc_access($pdo, $cid, $uid, false)) { echo json_encode(['ok' => false, 'error' => 'count_first']); exit; }
                if (!$isAdmin) {   // a photo of the chassis plate: only the cars the admin asked one of
                    sc_photo_col($pdo);
                    $np = $pdo->prepare("SELECT COUNT(*) FROM stock_check_items WHERE check_id = ? AND car_id > 0 AND state = 'present' AND (((photo_req & 1) <> 0 AND photo IS NULL) OR ((photo_req & 2) <> 0 AND car_photo IS NULL))");
                    $np->execute([$cid]);
                    if ((int)$np->fetchColumn()) { echo json_encode(['ok' => false, 'error' => 'photo']); exit; }
                }
                echo json_encode(['ok' => smart_check_finish($pdo, $cid, $me)]); exit;
            case 'time_req':     // ⏳ the person doing it asks for more minutes
                if ($isAdmin || !sc_access($pdo, $cid, $uid, false)) break;
                $r = check_time_ask($pdo, $cid, $uid, $me, (int)($in['minutes'] ?? 15), (string)($in['reason'] ?? ''));
                echo json_encode($r + ['state' => check_time_state($pdo, $cid, $uid)]); exit;
            case 'state':        // their page asks now and then: time left, and the answer to their request
                if (!sc_access($pdo, $cid, $uid, false)) break;
                echo json_encode(['ok' => true] + check_time_state($pdo, $cid, $uid)); exit;
            case 'time_decide':
                if (!$isBoss) break;
                echo json_encode(['ok' => check_time_decide($pdo, (int)($in['req'] ?? 0), !empty($in['yes']), $me)]); exit;
            case 'add_time':
                if (!$isBoss) break;
                $until = check_time_add($pdo, $cid, (int)($in['minutes'] ?? 15), $me, 'admin');
                echo json_encode(['ok' => $until !== null, 'until' => $until]); exit;
            case 'case':         // 🔎 the admin's decision on a car not found
                if (!$isAdmin) break;
                echo json_encode(['ok' => check_case_decide($pdo, (int)($in['id'] ?? 0), (string)($in['how'] ?? ''), (string)($in['note'] ?? ''), $me)]); exit;
            case 'case_recheck': // 🔁 check that car again now, with someone else
                if (!$isAdmin) break;
                $nid = check_case_recheck_now($pdo, (int)($in['id'] ?? 0), (int)($in['user'] ?? 0), (int)($in['minutes'] ?? 5), $me, check_photo_kind($in['photo'] ?? 0));
                echo json_encode($nid ? ['ok' => true, 'id' => $nid] : ['ok' => false, 'error' => 'nocar']); exit;
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
if ($inCheck && !in_array($viewId, $myIds, true)) $viewId = (int)$myChecks[0]['id'];   // only their own running check
$check = null;
if ($viewId) {
    if (!sc_access($pdo, $viewId, $uid, $isBoss)) { header('Location: dashboard.php?lang=' . $lang); exit; }
    $st = $pdo->prepare("SELECT *, TIMESTAMPDIFF(SECOND, NOW(), deadline) AS secs FROM stock_checks WHERE id = ?");
    $st->execute([$viewId]);
    $check = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$doMode = $check && in_array((int)$check['id'], $myIds, true) && in_array($check['status'], ['active', 'expired'], true);
$isPick = $check && ($check['mode'] ?? 'all') === 'pick';   // «سيارات أختارها»: only the cars the admin chose, each by chassis or colour
// the first step for whoever does the check: count the cars. Until they answer, the list and its totals stay hidden.
$isSpot = $check && !empty($check['spot']);
$needTotal = $doMode && !$isAdmin && !$isSpot && check_counted($pdo, (int)$check['id'], $uid) === null;
$pendingQ = $doMode && !$isAdmin && !$isSpot && !$needTotal ? check_q_pending($pdo, (int)$check['id'], $uid) : [];   // 🔢 the count questions the admin added, after the total
$needCount = $needTotal || (bool)$pendingQ;
$adminView = $check && ($isAdmin || ($isBoss && !$doMode));   // whoever runs the checks looking at one — never the person doing it

$br  = fn($n) => push_branch_label($pdo, (string)$n, $lang);
$stLbl = $ar ? ['active' => '⏳ جارٍ', 'expired' => '⌛ انتهى الوقت', 'done' => '✅ مكتمل', 'cancelled' => '✖ ملغى']
             : ['active' => '⏳ running', 'expired' => '⌛ time up', 'done' => '✅ done', 'cancelled' => '✖ cancelled'];

if ($isBoss && !$check) {
    $branches = $pdo->query("SELECT b.name, b.name_ar, b.name_en, (SELECT COUNT(*) FROM cars c WHERE c.branch = b.name AND c.status IN ('available', 'reserved')) AS n FROM branches b ORDER BY b.id")->fetchAll(PDO::FETCH_ASSOC);
    $people = $pdo->query("SELECT id, username, role FROM users WHERE active = 1 ORDER BY FIELD(role,'manager','sales','admin'), username")->fetchAll(PDO::FETCH_ASSOC);
    $phones = [];   // user id => phones with notifications turned on
    try { foreach ($pdo->query("SELECT user_id, COUNT(*) n FROM push_subscriptions GROUP BY user_id") as $r) $phones[(int)$r['user_id']] = (int)$r['n']; } catch (Throwable $e) {}
    $here = [];   // who is clocked in where right now
    try { foreach ($pdo->query("SELECT user_id, branch_name FROM attendance_logs WHERE clock_in >= CURDATE() AND clock_out IS NULL") as $r) $here[(int)$r['user_id']] = $r['branch_name']; } catch (Throwable $e) {}
    $list = $pdo->query("SELECT c.*, TIMESTAMPDIFF(SECOND, NOW(), c.deadline) AS secs,
                                (SELECT COUNT(*) FROM stock_check_items i WHERE i.check_id = c.id AND i.car_id > 0) AS t,
                                (SELECT COUNT(*) FROM stock_check_items i WHERE i.check_id = c.id AND i.car_id > 0 AND i.state IS NOT NULL) AS m,
                                (SELECT COUNT(*) FROM stock_check_items i WHERE i.check_id = c.id AND i.state = 'missing') AS x,
                                (SELECT COALESCE(SUM((i.photo IS NOT NULL) + (i.car_photo IS NOT NULL)), 0) FROM stock_check_items i WHERE i.check_id = c.id) AS ph,
                                (SELECT GROUP_CONCAT(u.username SEPARATOR '، ') FROM stock_check_users s JOIN users u ON u.id = s.user_id WHERE s.check_id = c.id) AS who
                         FROM stock_checks c ORDER BY c.id DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
    $casesOpen = $isAdmin ? check_cases_open($pdo) : [];   // 🔎 cars not found, waiting for a decision
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
    $bTotal = check_branch_total($pdo, (int)$check['id']);   // what the counting step is checked against
    $nColor = count(array_filter($items, fn($it) => (int)$it['car_id'] > 0 && ($it['ask'] ?? '') === 'color'));
    $nPhoto = count(array_filter($items, fn($it) => (int)($it['photo_req'] ?? 0) > 0));   // for the admins only: the person learns it car by car
    $colorOpts = $doMode && $nColor ? check_color_options($pdo, $lang) : [];   // the same list for every car: it gives nothing away
    $allQ = check_q_list($pdo, (int)$check['id']);
    $pairIds = check_pair_ids($pdo, (int)$check['id']);
    $running = in_array($check['status'], ['active', 'expired'], true);
    $needLoc = $doMode && !$isAdmin && !$needCount && $running && check_loc_needed($pdo, (string)$check['branch']);   // 📍 each car carries where it was confirmed from
    $timeSt = $doMode && !$isAdmin && $running ? check_time_state($pdo, (int)$check['id'], $uid) : null;
    if ($adminView) {
        $qAns = check_q_answers($pdo, (int)$check['id']);
        $fastOf = [];
        try { $fs = $pdo->prepare("SELECT user_id, fast_max FROM stock_check_users WHERE check_id = ?"); $fs->execute([(int)$check['id']]); foreach ($fs->fetchAll(PDO::FETCH_ASSOC) as $r) $fastOf[(int)$r['user_id']] = (int)$r['fast_max']; } catch (Throwable $e) {}
        $caseOf = check_cases_for_check($pdo, (int)$check['id']);
        $xtPending = $running ? check_time_pending($pdo, (int)$check['id']) : [];
        $pairCmp = $pairIds ? check_pair_compare($pdo, (int)$check['id']) : null;
    }
}
/* 📷 what to photograph, as the admin chooses it (photo_req bits: 1 the chassis plate · 2 the car itself · 3 both) */
$phOpts = function (bool $none = true) use ($ar): string {
    $o = ($none ? [0 => $ar ? '📷 بدون صورة' : '📷 No photo'] : [])
       + [1 => $ar ? '📷 صورة رقم الشاسيه' : '📷 Chassis plate', 2 => $ar ? '🚗 صورة السيارة نفسها' : '🚗 The car itself', 3 => $ar ? '📷+🚗 الاثنين' : '📷+🚗 Both'];
    $h = '';
    foreach ($o as $v => $t) $h .= '<option value="' . $v . '">' . htmlspecialchars($t) . '</option>';
    return $h;
};
$phNames = $ar ? [1 => 'رقم الشاسيه', 2 => 'السيارة', 3 => 'الشاسيه + السيارة'] : [1 => 'chassis plate', 2 => 'the car', 3 => 'chassis + car'];
/* a photo's address in this page: the chassis plate or the car (&k=car) — changes when it is retaken, so the old one is never shown from the cache */
$phSrc = fn(array $it, string $k): string => '?photo=' . (int)$it['id'] . ($k === 'car' ? '&k=car' : '') . '&v=' . substr((string)($it[$k === 'car' ? 'car_photo' : 'photo'] ?? ''), 0, 8);
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
.sc-watch{margin-top:16px;padding:12px 14px;border-radius:14px;border:1px solid rgba(250,204,21,.3);background:rgba(250,204,21,.05)}
.sc-w{height:36px;padding:0 13px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--txt);font:inherit;font-size:13px;font-weight:800;cursor:pointer}
.sc-w.on{background:linear-gradient(90deg,#f59e0b,#eab308);border-color:transparent;color:#1c1917}
.sc-grp{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 8px}
.sc-grp button{height:30px;padding:0 11px;border-radius:9px;border:1px solid rgba(250,204,21,.35);background:rgba(250,204,21,.08);color:#fde68a;font:inherit;font-size:12px;font-weight:800;cursor:pointer}
.sc-w i{font-style:normal;font-size:12px;margin-inline-start:2px}
.sc-w i.no{opacity:.75}
.sc-hint{display:block;margin-top:6px;font-size:12px;color:var(--mut);font-weight:700}
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
.sc-phc{font-size:12px;font-weight:900;color:#7dd3fc}
.sc-gal-g{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}
.sc-gi{display:flex;flex-direction:column;padding:0;border-radius:14px;border:1px solid rgba(56,189,248,.3);background:rgba(255,255,255,.03);overflow:hidden;text-align:start;color:var(--txt);font:inherit;cursor:zoom-in;transition:border-color .15s}
.sc-gi:hover{border-color:rgba(56,189,248,.7)}
.sc-gi img{display:block;width:100%;aspect-ratio:4/3;object-fit:cover;background:#0b1220}
.sc-gi .cap{display:grid;gap:2px;padding:8px 10px;min-width:0}
.sc-gi .cap b{font-size:12.5px;font-weight:900;color:#bae6fd}
.sc-gi .cap small{font-size:11.5px;color:var(--mut);font-weight:700;line-height:1.5;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.sc-gi .cap small:empty{display:none}
.sc-gi.none{cursor:default;border:1px dashed var(--line)}
.sc-gi.none .ph{display:grid;place-items:center;aspect-ratio:4/3;font-size:12.5px;font-weight:800;color:var(--mut);background:rgba(255,255,255,.02)}
.sc-lb{position:fixed;inset:0;z-index:101;display:none;flex-direction:column;background:#020617;color:#fff}
.sc-lb.on{display:flex}
.sc-lb-top{display:flex;align-items:center;gap:10px;padding:calc(10px + env(safe-area-inset-top)) 14px 10px}
.sc-lb-t{flex:1;min-width:0;font-size:13.5px;font-weight:800;color:#e2e8f0;line-height:1.55}
.sc-lb-x{flex:none;width:42px;height:42px;border-radius:50%;border:0;background:rgba(255,255,255,.12);color:#fff;font-size:18px;cursor:pointer}
.sc-lb-box{flex:1;min-height:0;display:flex;align-items:center;justify-content:center;overflow:hidden;padding:0 8px;direction:ltr}
.sc-lb-box img{max-width:100%;max-height:100%;object-fit:contain;border-radius:10px;cursor:zoom-in;user-select:none;-webkit-user-select:none}
.sc-lb-box.zoom{display:block;overflow:auto;padding:0;-webkit-overflow-scrolling:touch}
.sc-lb-box.zoom img{max-width:none;max-height:none;border-radius:0;cursor:zoom-out}
.sc-lb-bar{display:flex;align-items:center;justify-content:center;gap:18px;padding:10px 14px}
.sc-lb-bar[hidden]{display:none}
.sc-lb-bar button{width:48px;height:48px;border-radius:50%;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.08);color:#fff;font-size:26px;line-height:1;cursor:pointer}
.sc-lb-n{min-width:60px;text-align:center;font-size:13px;font-weight:800;color:#cbd5e1}
.sc-lb-hint{padding:0 14px calc(12px + env(safe-area-inset-bottom));text-align:center;font-size:12px;font-weight:700;color:#94a3b8}
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
.sc-plans{border-color:rgba(250,204,21,.45);background:linear-gradient(160deg,rgba(250,204,21,.08),rgba(255,255,255,.02))}
.sc-plan{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:12px;border-radius:14px;border:1px solid rgba(250,204,21,.3);background:rgba(2,6,23,.4);margin-bottom:8px}
.sc-plan .i{flex:1;min-width:200px}.sc-plan b{display:block;font-size:14.5px}.sc-plan small{display:block;font-size:12.5px;color:var(--mut);font-weight:700;margin-top:3px}
.sc-spot{display:flex;flex-wrap:wrap;gap:8px}.sc-spot select{flex:1;min-width:130px}
.sc-auto{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;margin:12px 0}
.sc-auto label{display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:13px;font-weight:800;color:var(--mut)}
.sc-count{text-align:center;padding:26px 18px}
.sc-count h2{font-size:20px;line-height:1.6;margin:6px 0 8px}
.sc-step{display:inline-block;font-size:12px;font-weight:900;color:#c4b5fd;background:rgba(147,51,234,.18);border:1px solid rgba(147,51,234,.4);padding:3px 12px;border-radius:999px;direction:ltr}
.sc-cnt-ic{font-size:46px;margin-top:10px}
.sc-cnt-in{display:block;width:100%;max-width:220px;height:72px;margin:16px auto 0;text-align:center;font-family:Inter,sans-serif;font-size:38px;font-weight:900;border-color:rgba(163,230,53,.5);direction:ltr}
.sc-count .sc-fin{max-width:420px}
.sc-cn{font-style:normal;font-size:12px;font-weight:900;padding:3px 9px;border-radius:999px;background:rgba(255,255,255,.06);color:#cbd5e1}
.sc-cn.ok{background:rgba(34,197,94,.15);color:#86efac}.sc-cn.bad{background:rgba(239,68,68,.16);color:#fca5a5}
.sc-ph-a{display:inline-block;margin-top:4px;font-size:12.5px;font-weight:800;color:#7dd3fc;text-decoration:none}
.sc-it .phb{width:100%;display:none;gap:8px}.sc-it.present .phb{display:grid}
.sc-it .phbtn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:50px;padding:8px 12px;line-height:1.45;text-align:center;border-radius:13px;border:1.5px dashed rgba(56,189,248,.6);background:rgba(56,189,248,.08);color:#bae6fd;font:inherit;font-size:14.5px;font-weight:900;cursor:pointer}
/* 📷 the camera inside the page (no file picker, no gallery) */
.cam{position:fixed;inset:0;z-index:100;display:none;flex-direction:column;background:#000;color:#fff}
.cam.on{display:flex}
.cam-top{display:flex;align-items:center;gap:10px;padding:calc(10px + env(safe-area-inset-top)) 14px 10px}
.cam-t{flex:1;min-width:0;font-size:14px;font-weight:900;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cam-x{width:42px;height:42px;flex-shrink:0;border-radius:50%;border:0;background:rgba(255,255,255,.16);color:#fff;font-size:18px;cursor:pointer}
.cam-view{position:relative;flex:1;min-height:0;overflow:hidden;background:#000}
.cam-view video{width:100%;height:100%;object-fit:cover;display:block}
.cam-view .cam-img{width:100%;height:100%;object-fit:contain;display:block}
.cam-view [hidden]{display:none!important}
.cam-frame{position:absolute;inset:0;display:grid;place-items:center;pointer-events:none}
.cam-frame span{width:84%;height:24%;border:3px solid rgba(255,255,255,.9);border-radius:16px;box-shadow:0 0 0 9999px rgba(0,0,0,.38)}
.cam.car .cam-frame span{width:92%;height:58%;border-radius:22px;box-shadow:0 0 0 9999px rgba(0,0,0,.25)}
.sc-phrow{display:flex;flex-wrap:wrap;gap:8px}.sc-phrow select{flex:1;min-width:140px;max-width:none}
.sc-phrow select:disabled{opacity:.45}.sc-phrow[hidden]{display:none}
.sc-xo-i small.sc-phpick{color:#bae6fd}
.sc-phs{max-width:190px}
.cam-msg{position:absolute;inset-inline:16px;bottom:16px;display:grid;gap:8px;padding:14px;border-radius:16px;background:rgba(15,23,42,.96);border:1px solid rgba(245,158,11,.5);color:#fde68a;font-size:13.5px;font-weight:700;line-height:1.8}
.cam-msg b{display:block;color:#fff;font-size:15px}
.cam-retry{justify-self:start;height:40px;padding:0 14px;border-radius:12px;border:0;background:#f59e0b;color:#1c1917;font:inherit;font-weight:900;cursor:pointer}
.cam-hint{text-align:center;font-size:13px;font-weight:800;color:#cbd5e1;padding:10px 16px 0}
.cam-bar{display:flex;align-items:center;justify-content:center;gap:12px;padding:12px 16px calc(18px + env(safe-area-inset-bottom))}
.cam-bar [hidden]{display:none}
.cam-shot{width:78px;height:78px;border-radius:50%;border:5px solid #fff;background:transparent;cursor:pointer;display:grid;place-items:center;padding:0}
.cam-shot i{width:60px;height:60px;border-radius:50%;background:#fff;transition:transform .1s}
.cam-shot:active i{transform:scale(.88)}
.cam-shot:disabled{opacity:.35;cursor:default}
.cam-again,.cam-use{height:54px;padding:0 20px;border-radius:16px;border:0;font:inherit;font-size:16px;font-weight:900;cursor:pointer}
.cam-again{background:rgba(255,255,255,.16);color:#fff}.cam-use{flex:1;max-width:260px;background:linear-gradient(90deg,#16a34a,#0ea5e9);color:#fff}
.sc-pp{display:inline-flex;align-items:center;gap:7px;font-size:12.5px;font-weight:800;color:var(--mut);cursor:pointer;white-space:nowrap}
.sc-it .phbtn.done{border-style:solid;border-color:rgba(34,197,94,.55);background:rgba(34,197,94,.1);color:#bbf7d0}
.sc-it .phbtn.busy{opacity:.6;pointer-events:none}
.sc-it .pherr{font-size:13px;font-weight:800;color:#fca5a5;margin-top:6px}.sc-it .pherr:empty{display:none}
.sc-how{margin-bottom:14px;padding:12px 16px;border-radius:14px;background:rgba(163,230,53,.07);border:1px solid rgba(163,230,53,.35);color:#d9f99d;font-size:13.5px;font-weight:700;line-height:1.8}
.sc-how b{display:block;color:#fff;font-size:14.5px}
.sc-it .chb{width:100%;display:none;flex-wrap:wrap;gap:8px}.sc-it.asking .chb{display:flex}
.sc-it .chin{flex:1;min-width:0;height:44px;border-color:rgba(163,230,53,.45);font-family:Inter,monospace;font-size:16px;font-weight:800;letter-spacing:1px;text-transform:uppercase;direction:ltr;text-align:center}
.sc-it .chin::placeholder{font-family:Tajawal,sans-serif;font-size:13px;font-weight:700;letter-spacing:0;text-transform:none}
.sc-it .chok{height:44px;background:#16a34a;border-color:#16a34a;color:#fff}
.sc-it .cherr{flex-basis:100%;font-size:13px;font-weight:800;color:#fca5a5;line-height:1.7}.sc-it .cherr:empty{display:none}
.sc-it.shake{animation:scShake .4s}
@keyframes scShake{25%{transform:translateX(-6px)}50%{transform:translateX(6px)}75%{transform:translateX(-3px)}}
.sc-ex{display:flex;gap:8px;margin-top:6px}.sc-ex input{flex:1}
.sc-exl span{display:inline-flex;align-items:center;gap:6px;margin:6px 6px 0 0;padding:5px 10px;border-radius:999px;background:rgba(250,204,21,.1);border:1px solid rgba(250,204,21,.35);color:#fde68a;font-size:12.5px;font-weight:800}
.sc-exl span button{border:0;background:none;color:#fca5a5;cursor:pointer;font-size:13px}
.sc-fin{width:100%;height:54px;margin-top:12px;border:0;border-radius:16px;background:linear-gradient(90deg,#16a34a,#0ea5e9);color:#fff;font:inherit;font-size:17px;font-weight:900;cursor:pointer}
.sc-fin:disabled{opacity:.45;cursor:default}
.sc-ppl2 span{display:inline-flex;align-items:center;gap:8px;margin:0 6px 6px 0;padding:6px 10px;border-radius:12px;border:1px solid var(--line);font-weight:800;font-size:13px}
.sc-pickinfo{padding:11px 16px;border-radius:14px;background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.35);color:#e0f2fe;font-size:13.5px;font-weight:800}
.sc-how .ln{display:block;margin-top:4px}
.sc-ask{display:inline-block;margin-top:6px;margin-inline-end:6px;font-size:11.5px;font-weight:900;padding:3px 10px;border-radius:999px}
.sc-ask.k{background:rgba(132,204,22,.12);border:1px solid rgba(132,204,22,.4);color:#d9f99d}
.sc-ask.c{background:rgba(244,114,182,.12);border:1px solid rgba(244,114,182,.42);color:#fbcfe8}
.sc-col{display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-size:12.5px;font-weight:900;padding:3px 10px;border-radius:999px;background:rgba(255,255,255,.06);color:#e2e8f0}
.sc-col i{width:12px;height:12px;border-radius:50%;border:1px solid rgba(255,255,255,.4)}
.sc-col.ok{background:rgba(34,197,94,.14);color:#bbf7d0}
.sc-col[hidden]{display:none}
.sc-it.bycolor .ch{color:#7dd3fc;direction:ltr;unicode-bidi:isolate}
/* 🎨 the colour picker: scroll the list, the colour in the middle band is the answer */
.sc-it .cwb{width:100%;display:none}.sc-it.asking .cwb{display:block}
.cw-t{font-size:13px;font-weight:800;color:#fbcfe8;margin:2px 0 8px}
.cw{position:relative;height:240px;border-radius:16px;border:1px solid rgba(244,114,182,.35);background:#0b1426;overflow:hidden}
.cw-band{position:absolute;inset-inline:8px;top:96px;height:48px;border-radius:12px;border:2px solid rgba(244,114,182,.75);background:rgba(244,114,182,.12);pointer-events:none;box-shadow:0 0 0 999px rgba(2,6,23,.38)}
.cw-win{position:relative;height:100%;overflow-y:scroll;scroll-snap-type:y mandatory;overscroll-behavior:contain;scrollbar-width:none;outline:none;-webkit-overflow-scrolling:touch;
  -webkit-mask-image:linear-gradient(180deg,transparent 0,#000 30%,#000 70%,transparent 100%);mask-image:linear-gradient(180deg,transparent 0,#000 30%,#000 70%,transparent 100%)}
.cw-win::-webkit-scrollbar{display:none}
.cw-pad{height:96px}
.cw-o{height:48px;box-sizing:border-box;display:flex;align-items:center;gap:10px;padding:0 20px;scroll-snap-align:center;font-size:16px;font-weight:800;color:#94a3b8;white-space:nowrap;overflow:hidden;cursor:pointer;transition:color .12s,transform .12s}
.cw-o i{width:20px;height:20px;flex-shrink:0;border-radius:50%;border:1.5px solid rgba(255,255,255,.4)}
.cw-o small{margin-inline-start:auto;font-size:12px;font-weight:700;color:#64748b;direction:ltr}
.cw-o.ph{color:#64748b;font-size:14px}.cw-o.ph i{display:none}
.cw-o.on{color:#fff;transform:scale(1.04)}.cw-o.on small{color:#cbd5e1}
.cw-ok{width:100%;height:50px;margin-top:10px;border:0;border-radius:14px;background:linear-gradient(90deg,#db2777,#9333ea);color:#fff;font:inherit;font-size:15.5px;font-weight:900;cursor:pointer}
.cw-ok:disabled{background:rgba(255,255,255,.07);color:#64748b;cursor:default}
.cw-ok b{display:inline;font-weight:900}
.sc-it .cwb .cherr{margin-top:8px}
/* the on/off switch (same as notifications_admin.php) */
.tg{position:relative;display:inline-block;width:42px;height:24px;cursor:pointer}
.tg input{position:absolute;opacity:0;width:1px;height:1px}
.tg span{position:absolute;inset:0;border-radius:999px;background:rgba(255,255,255,.1);transition:.2s}
.tg span::after{content:'';position:absolute;top:3px;inset-inline-start:3px;width:18px;height:18px;border-radius:50%;background:#cbd5e1;transition:.2s}
.tg input:checked+span{background:linear-gradient(90deg,#22c55e,#9333ea)}
.tg input:checked+span::after{inset-inline-start:21px;background:#fff}
.sc-mode{margin-top:18px}
/* 🔢 extra count questions (the admin chooses) · 📷 random photos · 👥 double check */
.sc-xq{margin-top:18px}
.sc-xq-list{display:flex;flex-wrap:wrap;gap:8px}
.sc-xq-list:empty{display:none}
.xq{display:inline-flex;align-items:center;gap:8px;min-height:40px;padding:6px 12px;border-radius:12px;border:1.5px solid var(--line);background:rgba(255,255,255,.03);color:var(--txt);font:inherit;font-size:13px;font-weight:800;cursor:pointer;text-align:start}
.xq i{width:14px;height:14px;flex-shrink:0;border-radius:50%;border:1px solid rgba(255,255,255,.4)}
.xq em{font-style:normal;font-size:11.5px;font-weight:900;padding:2px 8px;border-radius:999px;background:rgba(255,255,255,.07);color:var(--mut);white-space:nowrap}
.xq.on{border-color:rgba(250,204,21,.75);background:rgba(250,204,21,.1)}.xq.on em{background:rgba(250,204,21,.2);color:#fde68a}
.xq:disabled{opacity:.4;cursor:default}
.sc-xo{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px}
.sc-xo-i{display:flex;flex-direction:column;gap:6px;padding:12px 14px;border-radius:14px;border:1px solid var(--line);background:rgba(255,255,255,.02);font-size:13.5px;font-weight:800}
.sc-xo-i small{font-size:11.5px;color:var(--mut);font-weight:700;line-height:1.7}
.sc-xo-i select{max-width:200px}
.sc-dbl.off{opacity:.65}
/* whoever does it: location · more time · count questions */
.sc-tools{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.sc-loc,.sc-xtb{height:32px;padding:0 11px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.05);color:var(--mut);font:inherit;font-size:12px;font-weight:900;cursor:pointer}
.sc-loc.ok{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.4);color:#bbf7d0}
.sc-loc.weak{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.4);color:#fde68a}
.sc-loc.bad{background:rgba(239,68,68,.14);border-color:rgba(239,68,68,.5);color:#fecaca}
.sc-xtb{margin-inline-start:auto;color:#e9d5ff;border-color:rgba(168,85,247,.45);background:rgba(168,85,247,.12)}
.sc-xtb.wait{color:#fde68a;border-color:rgba(245,158,11,.45);background:rgba(245,158,11,.1)}
.sc-xt-box{display:grid;gap:10px;padding:14px 16px}.sc-xt-box[hidden]{display:none}
.sc-xt-box>b{font-size:15px}
.sc-xt-m{display:flex;gap:6px}
.sc-xt-m button{flex:1;height:40px;border-radius:11px;border:1.5px solid var(--line);background:rgba(255,255,255,.04);color:var(--txt);font:inherit;font-size:13.5px;font-weight:900;cursor:pointer}
.sc-xt-m button.on{border-color:#a855f7;background:rgba(168,85,247,.18);color:#f3e8ff}
.sc-xt-box .sc-fin{margin-top:0}
.sc-loch{padding:12px 16px;border-radius:14px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.45);color:#fde68a;font-size:13px;font-weight:700;line-height:1.9}
.sc-loch[hidden]{display:none}
.sc-loch b{display:block;color:#fff;font-size:14.5px;margin-bottom:4px}.sc-loch .nf-btn{margin-top:8px}
.sc-qprog{display:flex;justify-content:center;gap:6px;margin:10px 0 2px}
.sc-qprog i{width:26px;height:6px;border-radius:6px;background:rgba(255,255,255,.12)}
.sc-qprog i.ok{background:#22c55e}.sc-qprog i.on{background:#a855f7}
.sc-qno{display:block;font-size:12px;font-weight:800;color:var(--mut)}
/* whoever runs the checks: time, per car, the double check */
.sc-time{display:grid;gap:8px;margin-top:12px}
.sc-xtr{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:10px 12px;border-radius:14px;border:1px solid rgba(168,85,247,.45);background:rgba(168,85,247,.08)}
.sc-xtr span{flex:1;min-width:200px;font-size:13.5px;font-weight:700}.sc-xtr small{color:var(--mut)}.sc-xtr small.why{display:block;color:#e9d5ff;margin-top:2px}
.sc-addt{display:flex;flex-wrap:wrap;align-items:center;gap:6px;font-size:12.5px;font-weight:800;color:var(--mut)}
.sc-meta{display:flex;flex-wrap:wrap;gap:5px;margin-top:6px}
.sc-meta span{font-size:11.5px;font-weight:800;padding:2px 9px;border-radius:999px;background:rgba(255,255,255,.05);color:var(--mut)}
.sc-meta .ok{background:rgba(34,197,94,.1);color:#bbf7d0}.sc-meta .mid{background:rgba(245,158,11,.1);color:#fde68a}.sc-meta .bad{background:rgba(239,68,68,.12);color:#fecaca}
.sc-meta .ph{background:rgba(56,189,248,.1);color:#bae6fd}
.sc-meta .case{background:rgba(239,68,68,.12);color:#fecaca}.sc-meta .case.found{background:rgba(34,197,94,.12);color:#bbf7d0}
.sc-pair-who{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px}
.sc-pair-who a{padding:8px 12px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.03);color:var(--txt);text-decoration:none;font-size:13.5px;font-weight:900}
.sc-pair-who a.on{border-color:rgba(56,189,248,.6);background:rgba(56,189,248,.08)}.sc-pair-who small{color:var(--mut);font-weight:700;margin-inline-start:4px}
.sc-pair-t{display:grid;gap:4px}
.sc-pair-t .h,.sc-pair-t .r{display:grid;grid-template-columns:minmax(0,1fr) repeat(<?= max(2, count($pairIds ?? [])) ?>,74px);gap:6px;align-items:center;padding:8px 10px;border-radius:11px}
.sc-pair-t .h{font-size:12px;font-weight:900;color:var(--mut)}
.sc-pair-t .r{background:rgba(255,255,255,.03);border:1px solid var(--line);font-size:13px;font-weight:800}
.sc-pair-t .r small{display:block;font-family:Inter,monospace;font-size:11px;color:var(--mut);direction:ltr;text-align:start}
.sc-pair-t .r.diff{border-color:rgba(239,68,68,.55);background:rgba(239,68,68,.08)}
.sc-pair-t .v{text-align:center;font-size:15px}
/* 🔎 cars not found */
.sc-cases{border-color:rgba(239,68,68,.4);background:linear-gradient(160deg,rgba(239,68,68,.07),rgba(255,255,255,.02))}
.sc-case{display:grid;gap:8px;padding:12px;border-radius:14px;border:1px solid rgba(239,68,68,.3);background:rgba(2,6,23,.4);margin-bottom:8px}
.sc-case .i b{display:block;font-size:14.5px}.sc-case .i small{display:block;font-size:12px;color:var(--mut);font-weight:700;margin-top:3px}
.sc-case .i small.rc{color:#c4b5fd}.sc-case .i small.rc:empty{display:none}
.sc-case-acts{display:flex;flex-wrap:wrap;gap:6px}
.sc-case-acts button{height:38px;padding:0 12px;border-radius:11px;border:1px solid var(--line);background:rgba(255,255,255,.05);color:var(--txt);font:inherit;font-size:13px;font-weight:900;cursor:pointer}
.sc-case-acts button[data-how="found"]{border-color:rgba(34,197,94,.45);color:#bbf7d0}.sc-case-acts button[data-how="lost"]{border-color:rgba(239,68,68,.5);color:#fecaca}
.sc-case-re{display:flex;flex-wrap:wrap;gap:8px}.sc-case-re select{flex:1;min-width:160px}
.sc-plan.rc{border-color:rgba(168,85,247,.45)}
.sc-it .phq{font-size:13px;font-weight:900;color:#bae6fd;margin-bottom:6px}
.sc-toast{position:fixed;left:50%;bottom:calc(84px + env(safe-area-inset-bottom));z-index:60;width:max-content;max-width:min(92vw,420px);padding:12px 16px;border-radius:14px;font-size:14px;font-weight:800;line-height:1.6;text-align:center;
  transform:translate(-50%,20px);opacity:0;transition:all .3s;box-shadow:0 14px 34px rgba(0,0,0,.45);pointer-events:none}
.sc-toast.on{transform:translate(-50%,0);opacity:1}
.sc-toast.ok{background:#14532d;border:1px solid #22c55e;color:#dcfce7}.sc-toast.bad{background:#450a0a;border:1px solid #ef4444;color:#fee2e2}
@media (max-width:640px){.sc-xo{grid-template-columns:1fr}.sc-pair-t .h,.sc-pair-t .r{grid-template-columns:minmax(0,1fr) repeat(<?= max(2, count($pairIds ?? [])) ?>,54px)}}
.sc-mc{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.sc-mc button{display:grid;grid-template-columns:auto 1fr;grid-template-rows:auto auto;column-gap:12px;align-items:center;text-align:start;padding:14px 16px;border-radius:16px;border:1.5px solid var(--line);background:rgba(255,255,255,.03);color:var(--txt);font:inherit;cursor:pointer;transition:border-color .15s,background .15s}
.sc-mc .ic{grid-row:span 2;width:44px;height:44px;border-radius:13px;display:grid;place-items:center;font-size:22px;background:rgba(255,255,255,.06)}
.sc-mc b{font-size:15px;font-weight:900}.sc-mc small{font-size:12px;color:var(--mut);font-weight:700;line-height:1.6}
.sc-mc button.on{border-color:rgba(56,189,248,.75);background:linear-gradient(150deg,rgba(14,165,233,.16),rgba(99,102,241,.1));box-shadow:0 8px 22px rgba(14,165,233,.16)}
.sc-mc button.on .ic{background:linear-gradient(135deg,#0ea5e9,#6366f1)}
.sc-pk{margin-top:12px;padding:12px;border-radius:18px;border:1px solid rgba(56,189,248,.3);background:rgba(2,6,23,.4)}
.sc-pk[hidden]{display:none}
.sc-pk-empty{padding:18px;text-align:center;color:var(--mut);font-size:13.5px;font-weight:800;border:1px dashed var(--line);border-radius:14px}
.sc-pk-bar{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px}.sc-pk-bar[hidden]{display:none}
.sc-pk-q{flex:1;min-width:200px}
.sc-pk-sort{display:flex;gap:4px;padding:3px;border-radius:12px;background:rgba(255,255,255,.04);border:1px solid var(--line)}
.sc-pk-sort button{height:32px;padding:0 11px;border:0;border-radius:9px;background:none;color:var(--mut);font:inherit;font-size:12.5px;font-weight:800;cursor:pointer;white-space:nowrap}
.sc-pk-sort button.on{background:rgba(56,189,248,.18);color:#e0f2fe}
.sc-pk-list{display:grid;gap:8px;max-height:460px;overflow-y:auto;padding:2px;overscroll-behavior:contain}
.pk-car{display:grid;grid-template-columns:auto 1fr;gap:4px 12px;align-items:start;padding:12px;border-radius:15px;border:1.5px solid var(--line);background:rgba(255,255,255,.025);cursor:pointer;transition:border-color .15s,background .15s;-webkit-tap-highlight-color:transparent}
.pk-car:hover{border-color:rgba(148,163,184,.35)}
.pk-car.on{border-color:rgba(56,189,248,.7);background:rgba(14,165,233,.08)}
.pk-car[hidden]{display:none}
.pk-tick{width:26px;height:26px;margin-top:2px;border-radius:8px;border:2px solid rgba(148,163,184,.45);display:grid;place-items:center;font-size:14px;font-weight:900;color:transparent;transition:all .15s}
.pk-car.on .pk-tick{background:#0ea5e9;border-color:#0ea5e9;color:#fff}
.pk-i{min-width:0}
.pk-i b{display:block;font-size:14.5px;font-weight:900}
.pk-res{font-style:normal;font-size:11px;font-weight:800;color:#fcd34d;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.35);padding:1px 7px;border-radius:999px;margin-inline-start:6px;vertical-align:middle}
.pk-m{display:flex;flex-wrap:wrap;gap:6px 12px;margin-top:4px;font-size:12.5px;font-weight:800;color:#cbd5e1}
.pk-col{display:inline-flex;align-items:center;gap:6px}.pk-col i{width:12px;height:12px;border-radius:50%;border:1px solid rgba(255,255,255,.35)}
.pk-ch{font-family:Inter,monospace;color:#a3e635;letter-spacing:.3px;direction:ltr;unicode-bidi:isolate}
.pk-d{display:flex;flex-wrap:wrap;gap:6px;margin-top:7px}
.pk-d span{font-size:11.5px;font-weight:800;padding:3px 9px;border-radius:999px;background:rgba(255,255,255,.05);color:var(--mut)}
.pk-d .mv{background:rgba(168,85,247,.13);color:#e9d5ff}.pk-d .nw{background:rgba(34,197,94,.12);color:#bbf7d0}
.pk-d .sn{background:rgba(56,189,248,.1);color:#bae6fd}.pk-d .sn.no{background:rgba(239,68,68,.1);color:#fecaca}
.pk-ask{grid-column:2;display:none;gap:6px;margin-top:8px}
.pk-car.on .pk-ask{display:flex}
.pk-ask button{flex:1;min-height:40px;padding:4px 8px;line-height:1.35;border-radius:11px;border:1.5px solid var(--line);background:rgba(255,255,255,.04);color:var(--mut);font:inherit;font-size:13.5px;font-weight:900;cursor:pointer}
.pk-ask button.on[data-a="chassis"]{border-color:#84cc16;background:rgba(132,204,22,.14);color:#d9f99d}
.pk-ask button.on[data-a="color"]{border-color:#f472b6;background:rgba(244,114,182,.14);color:#fbcfe8}
.pk-ph{grid-column:2;display:none;gap:6px}
.pk-car.on .pk-ph{display:flex}
.pk-ph button{position:relative;flex:1;min-height:36px;padding:4px 8px;border-radius:11px;border:1.5px dashed rgba(148,163,184,.4);background:none;color:var(--mut);font:inherit;font-size:12.5px;font-weight:900;cursor:pointer}
.pk-ph button.on{border-style:solid;border-color:#38bdf8;background:rgba(56,189,248,.14);color:#bae6fd}
.pk-ph button.on::after{content:'✓';position:absolute;top:-8px;inset-inline-end:-6px;width:19px;height:19px;border-radius:50%;background:#0ea5e9;color:#fff;font-size:11px;line-height:19px;text-align:center;box-shadow:0 0 0 2px #0b1220}
.sc-pk-sum{position:sticky;bottom:0;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px;margin-top:10px;padding:10px 12px;border-radius:14px;background:rgba(15,23,42,.96);border:1px solid rgba(56,189,248,.35);font-size:13.5px;font-weight:900}
.sc-pk-sum[hidden]{display:none}
.sc-pk-all{display:flex;flex-wrap:wrap;gap:6px}
.sc-pk-all button{height:32px;padding:0 10px;border-radius:9px;border:1px solid var(--line);background:rgba(255,255,255,.05);color:var(--txt);font:inherit;font-size:12px;font-weight:800;cursor:pointer}
.sc-pk-load{padding:18px;text-align:center;color:var(--mut);font-weight:800}
@media (max-width:760px){.sc-form{grid-template-columns:1fr}}
@media (max-width:560px){.sc-mc{grid-template-columns:1fr}.pk-ask,.pk-ph{grid-column:1 / -1}}
</style>
</head>
<body>
<div class="nf-wrap">
    <header class="nf-head">
        <div><h1>📋 <?= $ar ? 'الجرد المفاجئ' : 'Surprise stock check' ?></h1>
            <p><?= !empty($isSpot) ? ($ar ? '⚡ فحص سريع: اذهب إلى السيارة المطلوبة واكتب رقم الشاسيه الموجود عليها' : '⚡ Spot check: go to the car asked for and type its chassis')
                  : (!empty($isPick) ? ($ar ? '🎯 تأكيد وجود سيارات محددة في الفرع خلال وقت محدد' : '🎯 Confirm chosen cars at a branch within a set time')
                  : ($ar ? 'تأكيد وجود كل سيارة في الفرع خلال وقت محدد' : 'Confirm every car at a branch within a set time')) ?></p></div>
        <div class="nf-nav">
            <?php if ($isBoss): ?><a class="nf-btn pur" href="permissions_admin.php?lang=<?= $lang ?>">🔐 <?= $ar ? 'الصلاحيات' : 'Permissions' ?></a><?php endif; ?>
            <?php if ($isBoss && $check): ?><a class="nf-btn" href="stock_check.php?lang=<?= $lang ?>">📋 <?= $ar ? 'كل عمليات الجرد' : 'All checks' ?></a><?php endif; ?>
            <?php if ($myChecks && !$isAdmin && !user_lock_active($pdo, $uid)): /* the system is closed until the check is finished */ ?><?php elseif (!user_lock_active($pdo, $uid)): ?><a class="nf-btn" href="dashboard.php?lang=<?= $lang ?>">🏠 <?= $ar ? 'الرئيسية' : 'Dashboard' ?></a><?php else: ?><a class="nf-btn" href="transfer_lock.php?lang=<?= $lang ?>">🔒 <?= $ar ? 'رجوع' : 'Back' ?></a><?php endif; ?>
            <a class="nf-btn ghost" href="?lang=<?= $ar ? 'en' : 'ar' ?><?= $check ? '&id=' . (int)$check['id'] : '' ?>"><?= $ar ? 'English' : 'العربية' ?></a>
        </div>
    </header>

<?php if ($isBoss && !$check): ?>
    <?php $plans = $isAdmin ? check_auto_pending($pdo) : []; $autoR = check_auto_rules($pdo); ?>
    <?php if ($plans): ?>
    <section class="nf-card sc-plans" id="plans">
        <h2>🎲 <?= $ar ? 'اقتراحات النظام — بانتظار موافقتك' : 'Proposed by the system — waiting for you' ?> <span class="nf-count"><?= count($plans) ?></span></h2>
        <p class="nf-note"><?= $ar ? 'لم يُرسل أي شيء للفرع. اضغط «إرسال الآن» ليبدأ الجرد فوراً، أو «تجاهل». ينتهي الاقتراح تلقائياً بعد ساعتين.' : 'Nothing has been sent to the branch. Press "Send now" to start it at once, or "Skip". A proposal expires by itself after two hours.' ?></p>
        <?php foreach ($plans as $pl): $sp = $pl['kind'] === 'spot'; $pg = $pl['kind'] === 'ping'; $rc = $pl['kind'] === 'recheck'; ?>
        <div class="sc-plan<?= $rc ? ' rc' : '' ?>">
            <div class="i"><b><?= $rc ? '🔁 ' . ($ar ? 'إعادة فحص سيارة لم تُوجد' : 'Re-check a missing car') : ($pg ? '📍 ' . ($ar ? 'تأكيد تواجد (موقع الهاتف)' : 'Location check') : ($sp ? '⚡ ' . ($ar ? 'فحص سريع لسيارة واحدة' : 'Spot check, one car') : '📋 ' . ($ar ? 'جرد مفاجئ كامل' : 'Full surprise check'))) ?> — 📍 <?= htmlspecialchars($br($pl['branch'])) ?></b>
                <?php if ($rc && !empty($pl['car_label'])): ?><small>🚗 <?= htmlspecialchars((string)$pl['car_label']) ?> · 🔑 <?= htmlspecialchars((string)$pl['car_chassis']) ?></small><?php endif; ?>
                <small>👤 <?= $ar ? 'المكلَّف المقترح: ' : 'Proposed for: ' ?><?= htmlspecialchars((string)$pl['username']) ?><?= $rc ? ($ar ? ' (غير من لم يجدها)' : ' (not the one who missed it)') : '' ?> · <?= $ar ? 'منذ ' . max(1, (int)$pl['mins']) . ' دقيقة' : max(1, (int)$pl['mins']) . ' min ago' ?></small></div>
            <select class="sc-in" data-pm<?= $pg ? ' hidden' : '' ?>><?php foreach ($sp || $rc ? [3, 5, 10, 15] : [15, 30, 45, 60, 90] as $mm): ?><option value="<?= $mm ?>"<?= $mm === ($sp || $rc ? 5 : 30) ? ' selected' : '' ?>><?= $mm ?> <?= $ar ? 'دقيقة' : 'min' ?></option><?php endforeach; ?></select>
            <?php if (!$pg): ?><select class="sc-in sc-phs" data-pp title="<?= $ar ? 'صورة بكاميرا الهاتف: رقم الشاسيه أو السيارة أو الاثنين' : 'A phone-camera photo: the chassis plate, the car or both' ?>"><?= $phOpts() ?></select><?php endif; ?>
            <button type="button" class="nf-btn" data-plan="<?= (int)$pl['id'] ?>" data-send="1">🚨 <?= $ar ? 'إرسال الآن' : 'Send now' ?></button>
            <button type="button" class="nf-btn ghost" data-plan="<?= (int)$pl['id'] ?>" data-send="0">✖ <?= $ar ? 'تجاهل' : 'Skip' ?></button>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php if ($casesOpen): ?>
    <section class="nf-card sc-cases" id="cases">
        <h2>🔎 <?= $ar ? 'سيارات لم تُوجد في الجرد — تنتظر قرارك' : 'Cars not found in a check — waiting for you' ?> <span class="nf-count"><?= count($casesOpen) ?></span></h2>
        <p class="nf-note"><?= $ar ? 'كل سيارة أُشير إليها «غير موجودة». اختر ما حدث لها. في اليوم التالي يقترح النظام عليك إعادة فحصها مع موظف آخر، وإذا وُجدت تُغلق المتابعة وحدها.' : 'Every car marked «missing». Choose what happened to it. The next day the system proposes a re-check by someone else; if it is found the case closes by itself.' ?></p>
        <?php foreach ($casesOpen as $k): ?>
        <div class="sc-case" data-case="<?= (int)$k['id'] ?>" data-branch="<?= htmlspecialchars((string)$k['branch']) ?>">
            <div class="i"><b>🚗 <?= htmlspecialchars((string)$k['label']) ?></b>
                <small>🔑 <?= htmlspecialchars((string)$k['chassis']) ?> · 📍 <?= htmlspecialchars($br($k['branch'])) ?> · ✍️ <?= htmlspecialchars((string)$k['marked_by']) ?> · <?= htmlspecialchars($ago((int)$k['mins'] * 60)) ?></small>
                <?php if (!empty($k['note'])): ?><small>📝 <?= htmlspecialchars((string)$k['note']) ?></small><?php endif; ?>
                <small class="rc"><?php
                    if ($k['recheck_state'] === 'missing') echo '❗ ' . ($ar ? 'أُعيد فحصها: ما زالت غير موجودة' : 'Re-checked: still missing');
                    elseif ($k['recheck_state'] === 'sent') echo '🔁 ' . ($ar ? 'أُرسلت إعادة فحص إلى ' : 'Re-check sent to ') . htmlspecialchars((string)$k['recheck_by']);
                    elseif ($k['plan_status'] === 'planned') echo '🔁 ' . ($ar ? 'إعادة فحص مقترحة ' : 'Re-check proposed ') . htmlspecialchars(date('d/m', strtotime((string)$k['plan_due']))) . ' ' . htmlspecialchars(smart_time_label(strtotime((string)$k['plan_due']), $lang));
                    elseif ($k['plan_status'] === 'pending') echo '🔁 ' . ($ar ? 'اقتراح إعادة الفحص بانتظار موافقتك أعلى الصفحة' : 'The re-check proposal is waiting for you above');
                ?></small></div>
            <div class="sc-case-acts">
                <button type="button" data-how="found">✅ <?= $ar ? 'وُجدت' : 'Found' ?></button>
                <button type="button" data-how="moved">🔄 <?= $ar ? 'في فرع آخر' : 'At another branch' ?></button>
                <button type="button" data-how="sold">💰 <?= $ar ? 'بيعت ولم تُسجَّل' : 'Sold, not recorded' ?></button>
                <button type="button" data-how="lost">🚨 <?= $ar ? 'مفقودة' : 'Lost' ?></button>
            </div>
            <input class="sc-in sc-case-note" maxlength="250" placeholder="<?= $ar ? 'ملاحظة مع القرار (اختياري)' : 'A note with the decision (optional)' ?>">
            <div class="sc-case-re">
                <select class="sc-in" data-who><option value=""><?= $ar ? '— إعادة الفحص الآن مع… —' : '— re-check now with… —' ?></option><?php foreach ($people as $p3): if ($p3['role'] === 'admin') continue; ?><option value="<?= (int)$p3['id'] ?>"><?= htmlspecialchars($p3['username']) ?><?= ($here[(int)$p3['id']] ?? '') === $k['branch'] ? ($ar ? ' 🟢 في الفرع' : ' 🟢 at the branch') : '' ?></option><?php endforeach; ?></select>
                <select class="sc-in sc-phs" data-cph><?= $phOpts() ?></select>
                <button type="button" class="nf-btn" data-recheck>🔁 <?= $ar ? 'أعد فحصها الآن' : 'Re-check now' ?></button>
            </div>
            <div class="nf-msg" data-cmsg></div>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <section class="nf-card">
        <h2>⚡ <?= $ar ? 'فحص سريع لسيارة واحدة' : 'Spot check, one car' ?></h2>
        <p class="nf-note"><?= $ar ? 'يختار النظام سيارة واحدة عشوائياً من الفرع، وعلى المكلَّف كتابة رقم الشاسيه الموجود عليها خلال دقائق. دقيقة واحدة من وقته، وتتأكد أن السيارة موجودة فعلاً.' : 'The system picks one random car of the branch; the person types the chassis on it within minutes.' ?></p>
        <div class="sc-spot">
            <select class="sc-in" id="spBranch"><option value=""><?= $ar ? '— الفرع —' : '— branch —' ?></option><?php foreach ($branches as $b): if (!(int)$b['n']) continue; ?><option value="<?= htmlspecialchars($b['name']) ?>"><?= htmlspecialchars(($ar ? $b['name_ar'] : $b['name_en']) ?: $b['name']) ?> (<?= (int)$b['n'] ?>)</option><?php endforeach; ?></select>
            <select class="sc-in" id="spUser"><option value=""><?= $ar ? '— المكلَّف —' : '— who —' ?></option><?php foreach ($people as $p2): if ($p2['role'] === 'admin') continue; ?><option value="<?= (int)$p2['id'] ?>" data-here="<?= htmlspecialchars($here[(int)$p2['id']] ?? '') ?>"><?= htmlspecialchars($p2['username']) ?></option><?php endforeach; ?></select>
            <select class="sc-in" id="spMin"><?php foreach ([3, 5, 10, 15] as $mm): ?><option value="<?= $mm ?>"<?= $mm === 5 ? ' selected' : '' ?>><?= $mm ?> <?= $ar ? 'دقائق' : 'min' ?></option><?php endforeach; ?></select>
            <select class="sc-in sc-phs" id="spPh"><?= $phOpts() ?></select>
            <button type="button" class="nf-btn" id="spGo">⚡ <?= $ar ? 'إرسال الفحص' : 'Send' ?></button>
        </div>
        <div class="nf-msg" id="spMsg"></div>
    </section>

    <?php if ($isAdmin): ?>
    <section class="nf-card">
        <h2>🎲 <?= $ar ? 'اقتراحات تلقائية بموافقتك' : 'Automatic proposals, with your approval' ?></h2>
        <p class="nf-note"><?= $ar ? 'يختار النظام أياماً وأوقاتاً عشوائية خلال الأسبوع لكل فرع، ويختار موظفاً حاضراً في الفرع لحظتها، ثم يسألك أنت أولاً. لا يُرسل أي شيء للفرع إلا بعد ضغطك «إرسال الآن»، فلا يستطيع أحد توقّع الموعد.' : 'The system picks random days and times through the week for each branch and a person who is there at that moment, then asks you first. Nothing reaches the branch until you press "Send now".' ?></p>
        <label class="sc-opt"><span class="tg"><input type="checkbox" id="auOn" <?= $autoR['on'] ? 'checked' : '' ?>><span></span></span> <?= $ar ? 'تفعيل الاقتراحات التلقائية' : 'Turn automatic proposals on' ?></label>
        <div class="sc-auto">
            <label><?= $ar ? 'جرد كامل لكل فرع أسبوعياً' : 'Full checks per branch per week' ?> <input type="number" class="sc-in sc-num" id="auFull" min="0" max="7" value="<?= $autoR['full'] ?>"></label>
            <label><?= $ar ? 'فحص سريع لكل فرع أسبوعياً' : 'Spot checks per branch per week' ?> <input type="number" class="sc-in sc-num" id="auSpot" min="0" max="14" value="<?= $autoR['spot'] ?>"></label>
            <label><?= $ar ? 'تأكيد تواجد لكل فرع أسبوعياً' : 'Location checks per branch per week' ?> <input type="number" class="sc-in sc-num" id="auPing" min="0" max="21" value="<?= $autoR['ping'] ?>"></label>
            <label><?= $ar ? 'من الساعة' : 'From hour' ?> <input type="number" class="sc-in sc-num" id="auFrom" min="0" max="22" value="<?= $autoR['from'] ?>"></label>
            <label><?= $ar ? 'إلى الساعة' : 'To hour' ?> <input type="number" class="sc-in sc-num" id="auTo" min="1" max="23" value="<?= $autoR['to'] ?>"></label>
        </div>
        <button type="button" class="nf-btn" id="auSave">💾 <?= $ar ? 'حفظ' : 'Save' ?></button>
        <div class="nf-msg" id="auMsg"></div>
    </section>
    <?php endif; ?>

    <section class="nf-card">
        <h2>🚨 <?= $ar ? 'جرد مفاجئ جديد' : 'New surprise check' ?></h2>
        <p class="nf-note"><?= $ar ? 'يصل للمكلَّف فوراً على موبايله وداخل النظام. قائمة السيارات هي الموجودة في الفرع لحظة الإرسال.' : 'It reaches the people chosen at once, on their phone and in the system. The list is the cars at the branch right now.' ?></p>
        <div class="sc-form">
            <div>
                <label class="sc-l"><?= $ar ? 'الفرع' : 'Branch' ?></label>
                <div class="sc-brs">
                    <?php foreach ($branches as $b): ?>
                    <button type="button" class="sc-b" data-b="<?= htmlspecialchars($b['name']) ?>"><b>📍 <?= htmlspecialchars(($ar ? $b['name_ar'] : $b['name_en']) ?: $b['name']) ?></b><small>🚗 <?= $ar ? ((int)$b['n'] ? push_ar_cars((int)$b['n']) : 'لا توجد سيارات') : (int)$b['n'] . ' cars' ?></small></button>
                    <?php endforeach; ?>
                </div>
                <label class="sc-l" style="margin-top:16px"><?= $ar ? 'المدة المسموحة' : 'Time allowed' ?></label>
                <div class="sc-times">
                    <?php foreach ([15, 30, 45, 60, 90, 120] as $m): ?><button type="button" class="sc-t<?= $m === 30 ? ' on' : '' ?>" data-m="<?= $m ?>"><?= $ar ? ([15 => '15 دقيقة', 30 => '30 دقيقة', 45 => '45 دقيقة', 60 => 'ساعة', 90 => 'ساعة ونصف', 120 => 'ساعتان'][$m]) : ($m < 60 ? $m . ' min' : ($m / 60) . ' h') ?></button><?php endforeach; ?>
                    <input type="number" class="sc-in sc-num" id="scMin" min="5" max="1440" value="30"> <?= $ar ? 'دقيقة' : 'min' ?>
                </div>
            </div>
            <div>
                <label class="sc-l"><?= $ar ? 'المكلَّف بالجرد' : 'Who does the check' ?></label>
                <div class="sc-ppl">
                    <?php foreach ($people as $p): ?>
                    <button type="button" class="sc-p" data-u="<?= (int)$p['id'] ?>" data-here="<?= htmlspecialchars($here[(int)$p['id']] ?? '') ?>"><?= htmlspecialchars($p['username']) ?><small></small></button>
                    <?php endforeach; ?>
                </div>
                <label class="sc-l" style="margin-top:16px"><?= $ar ? 'ملاحظة (اختياري)' : 'Note (optional)' ?></label>
                <input class="sc-in sc-note" id="scNote" maxlength="200" placeholder="<?= $ar ? 'مثال: يرجى التأكد من الشاسيه لكل سيارة' : 'e.g. check every chassis number' ?>">
                <label class="sc-opt"><span class="tg"><input type="checkbox" id="scLock" checked><span></span></span> 🔒 <?= $ar ? 'إيقاف النظام عن المكلَّف إذا لم يكتمل الجرد في الوقت' : 'Lock the system for them if it is not finished in time' ?></label>
            </div>
        </div>
        <div class="sc-mode">
            <label class="sc-l">🎯 <?= $ar ? 'ماذا يُطلب منه بعد أن يكتب عدد السيارات في الفرع؟' : 'After they type how many cars are at the branch, what do they confirm?' ?></label>
            <div class="sc-mc" id="scMode" role="radiogroup">
                <button type="button" class="on" data-mode="all" role="radio" aria-checked="true"><span class="ic">🚗</span><b><?= $ar ? 'كل السيارات' : 'Every car' ?></b><small><?= $ar ? 'يكتب رقم الشاسيه لكل سيارة في الفرع' : 'They type the chassis of every car at the branch' ?></small></button>
                <button type="button" data-mode="pick" role="radio" aria-checked="false"><span class="ic">🎯</span><b><?= $ar ? 'سيارات أختارها' : 'Cars I choose' ?></b><small><?= $ar ? 'تختار السيارات، ولكل سيارة: الشاسيه أو اللون، وصورة إن أردت' : 'You choose the cars, and for each: its chassis or its colour, and a photo if you want' ?></small></button>
            </div>
            <div class="sc-pk" id="scPick" hidden>
                <div class="sc-pk-empty" id="scPkNoB">📍 <?= $ar ? 'اختر الفرع أولاً لتظهر سياراته هنا' : 'Pick the branch first to see its cars here' ?></div>
                <div class="sc-pk-bar" id="scPkBar" hidden>
                    <input class="sc-in sc-pk-q" id="scPkQ" placeholder="🔍 <?= $ar ? 'ابحث بالموديل أو اللون أو الشاسيه…' : 'Search model, colour or chassis…' ?>">
                    <div class="sc-pk-sort" id="scPkSort" role="group"><button type="button" data-s="new" class="on">🕒 <?= $ar ? 'الأحدث وصولاً' : 'Newest arrivals' ?></button><button type="button" data-s="old">⏳ <?= $ar ? 'الأقدم' : 'Oldest' ?></button></div>
                </div>
                <div class="sc-pk-list" id="scPkList"></div>
                <div class="sc-pk-sum" id="scPkSum" hidden>
                    <span id="scPkSumT"></span>
                    <span class="sc-pk-all"><button type="button" data-all="chassis">🔑 <?= $ar ? 'الكل بالشاسيه' : 'All by chassis' ?></button><button type="button" data-all="color">🎨 <?= $ar ? 'الكل باللون' : 'All by colour' ?></button><button type="button" data-all="none">✕ <?= $ar ? 'إلغاء الاختيار' : 'Clear' ?></button></span>
                </div>
            </div>
        </div>
        <div class="sc-xq">
            <label class="sc-l">🔢 <?= $ar ? 'أسئلة عدّ إضافية بعد العدد الكلي (اختياري — أنت تختار من الاقتراحات)' : 'Extra count questions after the total (optional — you choose from the suggestions)' ?></label>
            <div class="sc-pk-empty" id="scXqNoB">📍 <?= $ar ? 'اختر الفرع أولاً لتظهر الاقتراحات' : 'Pick the branch first to see the suggestions' ?></div>
            <div class="sc-xq-list" id="scXq"></div>
            <small class="sc-hint"><?= $ar ? 'الرقم بجانب كل سؤال هو جواب النظام — يظهر لك أنت فقط. يجيب المكلَّف عن كل سؤال تختاره بعد العدد الكلي (حتى 4 أسئلة)، والعدد الخاطئ مرتين يوقف النظام.' : 'The number next to each question is the system\'s answer — shown to you only. They answer each question you choose after the total (up to 4); two wrong numbers stop the system.' ?></small>
        </div>
        <div class="sc-xo">
            <div class="sc-xo-i"><span>📷 <?= $ar ? 'صور بكاميرا الهاتف — أنت تختار' : 'Phone-camera photos — your choice' ?></span>
                <span class="sc-phrow" id="scPhRow">
                    <select class="sc-in" id="scPh"><?php foreach ([0, 1, 2, 3] as $n): ?><option value="<?= $n ?>"<?= $n === 0 ? ' selected' : '' ?>><?= $ar ? [0 => 'بدون صور', 1 => 'لسيارة واحدة', 2 => 'لسيارتين', 3 => 'لـ 3 سيارات'][$n] : ($n ? 'for ' . $n . ' car' . ($n > 1 ? 's' : '') : 'no photos') ?></option><?php endforeach; ?></select>
                    <select class="sc-in" id="scPhK" disabled><?= $phOpts(false) ?></select>
                </span>
                <small id="scPhHint"><?= $ar ? 'بدون صور إلا إذا اخترت. اختر عدد السيارات، ثم ماذا يصوّر: رقم الشاسيه أو السيارة نفسها أو الاثنين (صورتان). يحدد النظام السيارات عشوائياً، ولا يعرف المكلَّف أنها تحتاج صورة حتى يؤكدها، ويلتقطها بكاميرا الهاتف مباشرة — لا يمكنه اختيار صورة من المعرض.'
                                : 'No photos unless you choose. Pick how many cars, then what: the chassis plate, the car itself or both (two photos). The system picks the cars at random; they only learn a car needs a photo once they confirm it, and take it with the phone camera — never from the gallery.' ?></small>
                <small id="scPhPick" class="sc-phpick" hidden><?= $ar ? '🎯 في «سيارات أختارها» لا يختار النظام شيئاً عشوائياً: أنت تحدد الصورة لكل سيارة في قائمة السيارات أعلاه (📷 صورة الشاسيه · 🚗 صورة السيارة · أو الاثنين). لا يعرف المكلَّف أنها مطلوبة حتى يؤكد السيارة، ويلتقطها بكاميرا الهاتف مباشرة.'
                                : '🎯 With «Cars I choose» nothing is random: you set the photo of each car in the list above (📷 chassis photo · 🚗 car photo · or both). They only learn it once they confirm that car, and take it with the phone camera.' ?></small></div>
            <label class="sc-xo-i sc-dbl" id="scDblL"><span class="sc-opt" style="margin:0"><span class="tg"><input type="checkbox" id="scDbl" disabled><span></span></span> 👥 <?= $ar ? 'جرد مزدوج' : 'Double check' ?></span>
                <small id="scDblH"><?= $ar ? 'اختر شخصين أو أكثر: كل واحد يجرد وحده دون أن يرى عمل الآخر، ثم ترى كل اختلاف بينهما.' : 'Choose two people or more: each checks alone without seeing the other\'s work, then you see every difference.' ?></small></label>
        </div>
        <div class="sc-watch">
            <label class="sc-l">🔔 <?= $ar ? 'من يستلم إشعارات هذا الجرد؟ (البدء، الوقت المتبقي، كل سيارة، النتيجة)' : 'Who gets this check\'s notifications? (start, time left, every car, result)' ?></label>
            <div class="sc-grp">
                <button type="button" data-g="admin">👑 <?= $ar ? 'الإدارة' : 'Admins' ?></button>
                <button type="button" data-g="manager">🧑‍💼 <?= $ar ? 'المدراء' : 'Managers' ?></button>
                <button type="button" data-g="sales">🛒 <?= $ar ? 'المبيعات' : 'Sales' ?></button>
                <button type="button" data-g="*">👥 <?= $ar ? 'الكل' : 'Everyone' ?></button>
                <button type="button" data-g="none">✖ <?= $ar ? 'إلغاء التحديد' : 'Clear' ?></button>
            </div>
            <div class="sc-ppl">
                <?php foreach ($people as $p): $pid = (int)$p['id']; $on = $pid === $uid || in_array($p['role'], ['admin', 'manager'], true); $ph = $phones[$pid] ?? 0; ?>
                <button type="button" class="sc-w<?= $on ? ' on' : '' ?>" data-u="<?= $pid ?>" data-role="<?= htmlspecialchars($p['role']) ?>" title="<?= $ph ? ($ar ? 'الإشعارات مفعّلة على ' . $ph . ' جهاز' : $ph . ' device(s) with notifications on') : ($ar ? 'لم يفعّل الإشعارات على أي هاتف — ستصله داخل النظام فقط' : 'No phone with notifications on — in the system only') ?>"><?= $pid === $uid ? '⭐ ' . ($ar ? 'أنا' : 'Me') . ' (' . htmlspecialchars($p['username']) . ')' : htmlspecialchars($p['username']) ?> <i class="<?= $ph ? 'ok' : 'no' ?>"><?= $ph ? '📲' . ($ph > 1 ? $ph : '') : '🔕' ?></i></button>
                <?php endforeach; ?>
            </div>
            <small class="sc-hint"><?= $ar ? 'المكلَّف بالجرد يستلم إشعاراته دائماً. 📲 = الإشعارات مفعّلة على هاتفه · 🔕 = لم يفعّلها بعد (تصله داخل النظام فقط)، ويستلم كل شخص الإشعار على كل هاتف فعّل عليه الإشعارات.' : 'The people doing the check always get it. 📲 = notifications on · 🔕 = not turned on yet (in the system only). Each person gets it on every phone where they turned notifications on.' ?></small>
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
                    <small>👤 <?= htmlspecialchars((string)$c['who']) ?> · <?= htmlspecialchars($ago(-(int)$c['secs'] + (int)$c['minutes'] * 60)) ?><?= ($c['mode'] ?? '') === 'pick' ? ' · 🎯 ' . ($ar ? 'سيارات مختارة' : 'chosen cars') : (!empty($c['spot']) ? ' · ⚡ ' . ($ar ? 'فحص سريع' : 'spot check') : '') ?><?= !empty($c['group_id']) ? ' · 👥 ' . ($ar ? 'جرد مزدوج' : 'double check') : '' ?></small>
                    <div class="sc-bar"><i style="width:<?= $pct ?>%"></i></div></div>
                <span class="sc-prog"><?= (int)$c['m'] ?>/<?= (int)$c['t'] ?></span>
                <?php if ((int)$c['x']): ?><span class="sc-miss">❌ <?= (int)$c['x'] ?></span><?php endif; ?>
                <?php if ((int)($c['ph'] ?? 0)): ?><span class="sc-phc" title="<?= $ar ? 'صور الجرد' : 'Photos' ?>">📷 <?= (int)$c['ph'] ?></span><?php endif; ?>
                <span class="sc-st <?= $c['status'] ?>"><?= $stLbl[$c['status']] ?? $c['status'] ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </section>

<?php elseif ($check): ?>
    <div class="sc-top">
        <div class="r1">
            <div><b style="font-size:16px">📍 <?= htmlspecialchars($br($check['branch'])) ?></b>
                <div class="sc-prog"><?php if (!$needCount): ?><span id="scDone"><?= $cM ?></span> / <?= $cT ?> · <?php endif; ?><span class="sc-st <?= $check['status'] ?>"><?= $stLbl[$check['status']] ?? '' ?></span></div></div>
            <?php if (in_array($check['status'], ['active', 'expired'], true)): ?><div class="sc-cd" id="scCd" data-secs="<?= (int)$check['secs'] ?>">—</div><?php endif; ?>
        </div>
        <?php if (!$needCount): ?><div class="sc-bar"><i id="scBar" style="width:<?= $cT ? round($cM * 100 / $cT) : 100 ?>%"></i></div><?php endif; ?>
        <?php if ($doMode && !$needCount): ?><input class="sc-in sc-search" id="scQ" placeholder="🔍 <?= $isAdmin ? ($ar ? 'ابحث بالشاسيه أو الموديل…' : 'Search chassis or model…') : ($ar ? 'ابحث بالموديل أو اللون…' : 'Search model or colour…') ?>"><?php endif; ?>
        <?php if ($needLoc || $timeSt): ?>
        <div class="sc-tools">
            <?php if ($needLoc): ?><button type="button" class="sc-loc wait" id="scLoc">📍 <?= $ar ? 'جاري تحديد موقعك…' : 'Finding your location…' ?></button><?php endif; ?>
            <?php if ($timeSt): ?><button type="button" class="sc-xtb" id="scXtB">⏳ <?= $ar ? 'طلب وقت إضافي' : 'Ask for more time' ?></button><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($timeSt): ?>
    <div class="nf-card sc-xt-box" id="scXt" hidden>
        <b>⏳ <?= $ar ? 'طلب وقت إضافي لإكمال الجرد' : 'Ask for more time to finish the check' ?></b>
        <div class="sc-xt-m" id="scXtM" role="radiogroup">
            <?php foreach ([10, 15, 30] as $m): ?><button type="button" data-m="<?= $m ?>"<?= $m === 15 ? ' class="on"' : '' ?>><?= $ar ? ($m === 10 ? '10 دقائق' : $m . ' دقيقة') : $m . ' min' ?></button><?php endforeach; ?>
        </div>
        <input class="sc-in" id="scXtR" maxlength="250" placeholder="<?= $ar ? 'السبب (اختياري) — مثال: سيارات كثيرة في الساحة الخلفية' : 'Why (optional) — e.g. many cars in the back yard' ?>">
        <button type="button" class="sc-fin" id="scXtGo">📨 <?= $ar ? 'إرسال الطلب للإدارة' : 'Send the request to management' ?></button>
        <div class="nf-msg" id="scXtMsg"></div>
    </div>
    <?php endif; ?>
    <?php if ($needLoc): ?>
    <div class="sc-loch" id="scLocHelp" hidden>
        <b>📍 <?= $ar ? 'السماح بالموقع مطلوب لتأكيد السيارات' : 'Location is needed to confirm the cars' ?></b>
        <?= $ar ? 'كل سيارة تؤكدها يُحفظ معها مكانك، للتأكد أنك أمامها فعلاً.<br>• آيفون: الإعدادات ← الخصوصية والأمان ← خدمات الموقع ← تشغيل، ثم اختر المتصفح أو التطبيق ← «أثناء الاستخدام».<br>• أندرويد: اضغط 🔒 بجانب عنوان الموقع ← الأذونات ← الموقع ← سماح، وتأكد أن الموقع (GPS) مفعّل.'
                : 'Each car you confirm keeps where you were, to make sure you are in front of it.<br>• iPhone: Settings → Privacy & Security → Location Services → On, then the browser or app → "While Using".<br>• Android: tap 🔒 next to the address → Permissions → Location → Allow, and make sure GPS is on.' ?>
        <button type="button" class="nf-btn" id="scLocRetry">🔄 <?= $ar ? 'حاول مرة أخرى' : 'Try again' ?></button>
    </div>
    <?php endif; ?>

    <?php if ($isPick && (!$doMode || !$needCount)): ?>
    <div class="sc-pickinfo">🎯 <?= $ar ? 'سيارات مختارة: ' . push_ar_cars($cT) . ' — 🔑 ' . ($cT - $nColor) . ' بالشاسيه · 🎨 ' . $nColor . ' باللون' : 'Chosen cars: ' . $cT . ' — 🔑 ' . ($cT - $nColor) . ' by chassis · 🎨 ' . $nColor . ' by colour' ?><?= $adminView && $nPhoto ? ($ar ? ' · 📷 ' . $nPhoto . ' بصور' : ' · 📷 ' . $nPhoto . ' with photos') : '' ?></div>
    <?php endif; ?>
    <?php if ($needTotal): ?>
    <section class="nf-card sc-count">
        <div class="sc-step">1 / 2</div>
        <div class="sc-cnt-ic">🔢</div>
        <h2><?= $ar ? 'كم عدد السيارات الموجودة في الفرع الآن؟' : 'How many cars are at the branch right now?' ?></h2>
        <p class="nf-note"><?= $ar ? 'عُدّ السيارات الموجودة أمامك في الفرع بدقة واكتب العدد. بعدها تبدأ تأكيد السيارات. العدد غير الصحيح مرتين يوقف النظام.' : 'Count the cars in front of you carefully and type the number. Then you confirm the cars. Two wrong numbers stop your system.' ?></p>
        <input class="sc-in sc-cnt-in" id="scCntIn" type="number" inputmode="numeric" min="0" max="5000" placeholder="0" autocomplete="off">
        <button type="button" class="sc-fin" id="scCntGo">✅ <?= $ar ? 'تأكيد العدد والبدء' : 'Confirm the number and start' ?></button>
        <div class="nf-msg" id="scCntMsg"></div>
    </section>
    <?php elseif ($pendingQ): $q0 = $pendingQ[0]; $qNo = count($allQ) - count($pendingQ) + 1; $qColor = $q0['kind'] === 'color'; ?>
    <section class="nf-card sc-count">
        <div class="sc-step">1 / 2</div>
        <div class="sc-qprog"><?php foreach ($allQ as $k => $qq): ?><i class="<?= $k + 1 < $qNo ? 'ok' : ($k + 1 === $qNo ? 'on' : '') ?>"></i><?php endforeach; ?></div>
        <small class="sc-qno">🔢 <?= $ar ? 'سؤال عدّ ' . $qNo . ' من ' . count($allQ) : 'Count question ' . $qNo . ' of ' . count($allQ) ?></small>
        <div class="sc-cnt-ic"><?= $qColor ? '🎨' : '🚗' ?></div>
        <h2><?= htmlspecialchars($ar ? $q0['label_ar'] : $q0['label_en']) ?></h2>
        <p class="nf-note"><?= $qColor ? ($ar ? 'احسب كل درجات هذا اللون (الفاتح والغامق واللؤلؤي…). العدد غير الصحيح مرتين يوقف النظام.' : 'Count every shade of this colour (light, dark, pearl…). Two wrong numbers stop your system.')
                                       : ($ar ? 'احسب كل سيارات هذه الماركة الموجودة في الفرع. العدد غير الصحيح مرتين يوقف النظام.' : 'Count every car of this make at the branch. Two wrong numbers stop your system.') ?></p>
        <input class="sc-in sc-cnt-in" id="scQIn" type="number" inputmode="numeric" min="0" max="5000" placeholder="0" autocomplete="off">
        <button type="button" class="sc-fin" id="scQGo" data-q="<?= (int)$q0['id'] ?>">✅ <?= $ar ? 'تأكيد العدد' : 'Confirm the number' ?></button>
        <div class="nf-msg" id="scQMsg"></div>
    </section>
    <?php endif; ?>
    <?php if ($doMode && !$isAdmin && !$needCount && !$isPick): ?>
    <div class="sc-how"><b>🔑 <?= $ar ? 'طريقة تأكيد وجود السيارة' : 'How to confirm a car' ?></b>
        <?= $ar ? 'اضغط «موجودة» ثم اكتب رقم الشاسيه من السيارة نفسها. إذا كان الرقم يبدأ بأصفار فتجاوزها واكتب الأرقام التي بعدها فقط (مثال: 000482 تُكتب 482). الرقم غير الصحيح مرتين يوقف النظام.'
                : 'Tap "Here", then type the chassis number off the car itself. If it starts with zeros, skip them (000482 → 482). Two wrong numbers stop your system.' ?></div>
    <?php elseif ($doMode && !$isAdmin && !$needCount): ?>
    <div class="sc-how"><b>🎯 <?= $ar ? 'المطلوب: تأكيد السيارات التالية فقط' : 'Confirm only the cars below' ?></b>
        <?php if ($cT - $nColor > 0): ?><span class="ln">🔑 <?= $ar ? 'سيارة مكتوب عليها «اكتب الشاسيه»: اضغط «موجودة» ثم اكتب رقم الشاسيه من السيارة نفسها (الأصفار في بدايته تُتجاوز: 000482 تُكتب 482).' : 'A car marked "type the chassis": tap "Here", then type the chassis off the car (skip zeros at the start: 000482 → 482).' ?></span><?php endif; ?>
        <?php if ($nColor): ?><span class="ln">🎨 <?= $ar ? 'سيارة مكتوب عليها «اختر اللون»: اعثر عليها برقم الشاسيه الظاهر، اضغط «موجودة» ثم مرّر القائمة واختر لونها كما تراه أمامك.' : 'A car marked "pick the colour": find it by the chassis shown, tap "Here", then scroll the list and pick its colour as you see it.' ?></span><?php endif; ?>
        <span class="ln">⛔ <?= $ar ? 'أي إدخال غير صحيح مرتين يوقف النظام.' : 'Two wrong answers stop your system.' ?></span></div>
    <?php endif; ?>
    <?php if (!empty($check['note'])): ?><div class="nf-card" style="padding:12px 16px">📝 <?= htmlspecialchars($check['note']) ?></div><?php endif; ?>

    <?php if ($isBoss): ?>
    <section class="nf-card">
        <?php $wn = smart_check_watchers($pdo, (int)$check['id']); if ($wn): ?><p class="nf-note">🔔 <?= $ar ? 'يستلم الإشعارات: ' : 'Notified: ' ?><?= htmlspecialchars(implode('، ', $wn)) ?></p><?php endif; ?>
        <h2>👤 <?= $ar ? 'المكلَّفون' : 'Assigned' ?></h2>
        <div class="sc-ppl2">
            <?php foreach ($assigned as $aid => $an): ?>
            <span><?= htmlspecialchars($an) ?><?php $cn = $isSpot ? -1 : check_counted($pdo, (int)$check['id'], (int)$aid); if ($cn === -1): ?><?php elseif ($cn !== null): ?> <em class="sc-cn <?= $cn === $bTotal ? 'ok' : 'bad' ?>">🔢 <?= $ar ? 'عدّ ' . $cn . ' — النظام ' . $bTotal : 'counted ' . $cn . ' — system ' . $bTotal ?> <?= $cn === $bTotal ? '✓' : '✗' ?></em><?php elseif (in_array($check['status'], ['active', 'expired'], true)): ?> <em class="sc-cn"><?= $ar ? '🔢 لم يُدخل العدد بعد' : '🔢 not counted yet' ?></em><?php endif; ?>
            <?php foreach ($adminView ? $allQ : [] as $qq): $qa = $qAns[(int)$aid][(int)$qq['id']] ?? null; if (!$qa) continue; ?> <em class="sc-cn <?= $qa['ok'] ? 'ok' : 'bad' ?>"><?= $qq['kind'] === 'color' ? '🎨' : '🚗' ?> <?= htmlspecialchars((string)($ar ? $qq['tag_ar'] : $qq['tag_en'])) ?>: <?= (int)$qa['answer'] ?><?= $qa['ok'] ? ' ✓' : ' ✗ (' . ($ar ? 'النظام ' : 'system ') . (int)$qq['expected'] . ')' ?></em><?php endforeach; ?>
            <?php if ($adminView && ($fastOf[(int)$aid] ?? 0) >= CHECK_FAST_PER_MIN): ?> <em class="sc-cn bad" title="<?= $ar ? 'أكبر عدد سيارات أكّدها خلال دقيقة واحدة' : 'Most cars confirmed within one minute' ?>">⚡ <?= $ar ? push_ar_cars($fastOf[(int)$aid]) . ' في دقيقة' : $fastOf[(int)$aid] . ' cars in a minute' ?></em><?php endif; ?>
            <?php if (in_array($aid, $lockedIds, true)): ?> 🔒 <button type="button" class="nf-mini" data-unlock="<?= $aid ?>"><?= $ar ? '🔓 فتح النظام' : '🔓 Unlock' ?></button><?php endif; ?></span>
            <?php endforeach; ?>
        </div>
        <?php if (in_array($check['status'], ['active', 'expired'], true)): ?><button type="button" class="nf-btn ghost" id="scCancel" style="margin-top:8px">✖ <?= $ar ? 'إلغاء الجرد' : 'Cancel check' ?></button><?php endif; ?>
        <?php if ($check['status'] === 'done'): ?><p class="nf-note">✅ <?= $ar ? 'أنهاه ' : 'Finished by ' ?><?= htmlspecialchars((string)$check['finished_by']) ?> · <?= htmlspecialchars((string)$check['finished_at']) ?> — <?= $ar ? 'موجودة ' : 'present ' ?><?= $cP ?> · <?= $ar ? 'غير موجودة ' : 'missing ' ?><?= $cX ?> · <?= $ar ? 'زائدة ' : 'extra ' ?><?= $cE ?></p><?php endif; ?>
        <?php if ($adminView && $running): ?>
        <div class="sc-time">
            <?php foreach ($xtPending as $xr): ?>
            <div class="sc-xtr" data-req="<?= (int)$xr['id'] ?>">
                <span>⏳ <b><?= htmlspecialchars((string)$xr['username']) ?></b> <?= $ar ? 'يطلب ' . push_ar_mins((int)$xr['minutes']) . ' إضافية' : 'asks for ' . (int)$xr['minutes'] . ' more minutes' ?> <small>· <?= $ar ? 'منذ ' . max(1, (int)$xr['mins']) . ' دقيقة' : max(1, (int)$xr['mins']) . ' min ago' ?></small><?php if (!empty($xr['reason'])): ?><small class="why">📝 <?= htmlspecialchars((string)$xr['reason']) ?></small><?php endif; ?></span>
                <button type="button" class="nf-btn" data-xt="1">✅ <?= $ar ? 'موافقة' : 'Approve' ?></button><button type="button" class="nf-btn ghost" data-xt="0">✖ <?= $ar ? 'رفض' : 'Refuse' ?></button>
            </div>
            <?php endforeach; ?>
            <div class="sc-addt"><span>⏱️ <?= $ar ? 'إضافة وقت للجرد:' : 'Add time:' ?></span><?php foreach ([10, 15, 30] as $m): ?><button type="button" class="nf-mini" data-addt="<?= $m ?>">+<?= $m ?> <?= $ar ? 'د' : 'min' ?></button><?php endforeach; ?></div>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
    <?php if ($adminView && $isBoss && $nPhoto):
        $gal = [];   // 📷 every photo asked in this check, taken or not
        foreach ($items as $it) {
            $pr = (int)($it['photo_req'] ?? 0);
            if (!$pr || (int)$it['car_id'] === 0) continue;
            foreach ([1 => 'chassis', 2 => 'car'] as $bit => $k) {
                if (!($pr & $bit)) continue;
                $col = $k === 'car' ? 'car_photo' : 'photo';
                $gal[] = ['it' => $it, 'k' => $k, 'has' => !empty($it[$col]), 'at' => (string)($it[$col . '_at'] ?? ''), 'by' => (string)(($it[$col . '_by'] ?? '') ?: $it['marked_by'])];
            }
        }
        $galN = count(array_filter($gal, fn($g) => $g['has'])); ?>
    <section class="nf-card sc-gal" id="photos">
        <h2>📷 <?= $ar ? 'صور الجرد' : 'Photos' ?> <span class="nf-count"><?= $galN ?> / <?= count($gal) ?></span></h2>
        <p class="nf-note"><?= $ar ? 'اضغط على أي صورة لعرضها بالحجم الكامل داخل النظام، ثم اضغط عليها مرة أخرى للتكبير (لقراءة رقم الشاسيه).' : 'Tap a photo to see it full size inside the system; tap it again to zoom in (to read the chassis number).' ?></p>
        <div class="sc-gal-g">
            <?php foreach ($gal as $g): $it = $g['it'];
                  $kind = $g['k'] === 'car' ? '🚗 ' . ($ar ? 'السيارة' : 'The car') : '📷 ' . ($ar ? 'رقم الشاسيه' : 'Chassis plate');
                  $when = $g['at'] ? smart_time_label(strtotime($g['at']), $lang) : '';
                  $cap = $kind . ' — ' . $it['label'] . ($when ? ' · ' . $when : '') . ($g['by'] ? ' · 📸 ' . $g['by'] : ''); ?>
            <?php if ($g['has']): ?>
            <button type="button" class="sc-gi" data-src="<?= htmlspecialchars($phSrc($it, $g['k'])) ?>" data-cap="<?= htmlspecialchars($cap) ?>">
                <img src="<?= htmlspecialchars($phSrc($it, $g['k'])) ?>" loading="lazy" alt="<?= htmlspecialchars($cap) ?>">
                <span class="cap"><b><?= $kind ?></b><small><?= htmlspecialchars((string)$it['label']) ?></small><small><?= htmlspecialchars(trim(($when ? '🕒 ' . $when : '') . ($g['by'] ? ' · 📸 ' . $g['by'] : ''), ' ·')) ?></small></span>
            </button>
            <?php else: ?>
            <div class="sc-gi none"><span class="ph"><?= $running ? '⏳ ' . ($ar ? 'لم تُرسل بعد' : 'Not sent yet') : '✖ ' . ($ar ? 'لم تُرسل' : 'Never sent') ?></span>
                <span class="cap"><b><?= $kind ?></b><small><?= htmlspecialchars((string)$it['label']) ?></small></span></div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
    <?php if ($adminView && !empty($pairCmp['ids'])): $pc = $pairCmp; ?>
    <section class="nf-card sc-pair">
        <h2>👥 <?= $ar ? 'الجرد المزدوج — مقارنة' : 'Double check — comparison' ?> <span class="nf-count"><?= $pc['diff'] ? ($ar ? 'اختلاف في ' . ($pc['diff'] === 2 ? 'سيارتين' : push_ar_cars($pc['diff'])) : $pc['diff'] . ' differ') : ($ar ? 'متطابق' : 'match') ?></span></h2>
        <p class="nf-note"><?= $ar ? 'كل مكلَّف جرد وحده دون أن يرى عمل الآخر. الصفوف الملوّنة بالأحمر = اختلاف بينهما.' : 'Each person checked alone without seeing the other\'s work. Red rows = they differ.' ?></p>
        <div class="sc-pair-who"><?php foreach ($pc['ids'] as $pid): ?><a href="?lang=<?= $lang ?>&id=<?= $pid ?>" class="<?= $pid === (int)$check['id'] ? 'on' : '' ?>">👤 <?= htmlspecialchars($pc['who'][$pid] ?? '') ?> <small><?= $stLbl[$pc['status'][$pid] ?? ''] ?? '' ?><?= ($pc['counted'][$pid] ?? null) !== null ? ' · 🔢 ' . (int)$pc['counted'][$pid] : '' ?></small></a><?php endforeach; ?></div>
        <div class="sc-pair-t">
            <div class="h"><span><?= $ar ? 'السيارة' : 'Car' ?></span><?php foreach ($pc['ids'] as $pid): ?><span><?= htmlspecialchars($pc['who'][$pid] ?? '') ?></span><?php endforeach; ?></div>
            <?php foreach ($pc['cars'] as $pcar): ?>
            <div class="r<?= $pcar['diff'] ? ' diff' : '' ?>"><span><?= htmlspecialchars($pcar['label']) ?><small><?= htmlspecialchars($pcar['chassis']) ?></small></span><?php foreach ($pc['ids'] as $pid): $sv = $pcar['by'][$pid] ?? null; ?><span class="v <?= $sv ?: 'none' ?>"><?= $sv === 'present' ? '✅' : ($sv === 'missing' ? '❌' : '—') ?></span><?php endforeach; ?></div>
            <?php endforeach; ?>
        </div>
        <?php foreach ($pc['ids'] as $pid): if (empty($pc['extras'][$pid])): continue; endif; ?><p class="nf-note">➕ <?= htmlspecialchars($pc['who'][$pid] ?? '') ?>: <?= htmlspecialchars(implode('، ', $pc['extras'][$pid])) ?></p><?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php if (!$needCount): ?>
    <section class="nf-card" id="scItems">
        <?php foreach ($items as $it): if ((int)$it['car_id'] === 0) continue; ?>
        <?php $byColor = ($it['ask'] ?? 'chassis') === 'color';   // asked by its colour: the chassis is how they find the car, the colour is the question
              $open = $isAdmin || !$doMode && $isBoss || $it['state'] === 'present' || !in_array($check['status'], ['active', 'expired'], true);   // the answer stays hidden from whoever is doing the check until they give it
              $seeCh = $byColor || $open;
              $colorTxt = $byColor ? push_color_label($pdo, (string)($it['state'] === 'present' && !empty($it['answer']) ? $it['answer'] : $it['color']), $lang) : ''; ?>
        <div class="sc-it <?= htmlspecialchars((string)$it['state']) ?><?= $byColor ? ' bycolor' : '' ?>" data-id="<?= (int)$it['id'] ?>" data-q="<?= htmlspecialchars(mb_strtolower($it['label'] . ($seeCh ? ' ' . $it['chassis'] : '') . ($byColor && $open ? ' ' . $colorTxt : ''))) ?>">
            <div class="i"><b>🚗 <?= htmlspecialchars($it['label']) ?></b><span class="ch"><?= $seeCh ? htmlspecialchars((string)$it['chassis']) : '🔑 ' . chassis_mask() ?></span>
                <?php if ($isPick): $doer = $doMode && !$isAdmin; /* an instruction for whoever does it, what was asked for everyone else */ ?><span class="sc-ask <?= $byColor ? 'c' : 'k' ?>"><?= $byColor ? '🎨 ' . ($doer ? ($ar ? 'اختر اللون' : 'Pick the colour') : ($ar ? 'المطلوب: اللون' : 'Asked: colour'))
                                                                                                  : '🔑 ' . ($doer ? ($ar ? 'اكتب الشاسيه' : 'Type the chassis') : ($ar ? 'المطلوب: الشاسيه' : 'Asked: chassis')) ?></span><?php endif; ?>
                <?php if ($byColor && $open): ?><span class="sc-col<?= $it['state'] === 'present' ? ' ok' : '' ?>"><i style="background:<?= htmlspecialchars(car_color_hex((string)($it['answer'] ?: $it['color']))) ?>"></i><?= htmlspecialchars($colorTxt) ?><?= $it['state'] === 'present' ? ' ✓' : '' ?></span>
                <?php elseif ($byColor): /* nothing about the colour in the page until they pick it */ ?><span class="sc-col" hidden><i></i></span><?php endif; ?>
                <?php if (!empty($it['photo']) && $isBoss): ?><a class="sc-ph-a" href="<?= htmlspecialchars($phSrc($it, 'chassis')) ?>">📷 <?= $ar ? 'صورة رقم الشاسيه' : 'Chassis plate photo' ?><?= !empty($it['photo_at']) ? ' · ' . htmlspecialchars(smart_time_label(strtotime($it['photo_at']), $lang)) : '' ?></a><?php endif; ?>
                <?php if (!empty($it['car_photo']) && $isBoss): ?><a class="sc-ph-a" href="<?= htmlspecialchars($phSrc($it, 'car')) ?>">🚗 <?= $ar ? 'صورة السيارة' : 'Car photo' ?><?= !empty($it['car_photo_at']) ? ' · ' . htmlspecialchars(smart_time_label(strtotime($it['car_photo_at']), $lang)) : '' ?></a><?php endif; ?>
                <?php if ($it['marked_by']): ?><div class="by">✍️ <?= htmlspecialchars($it['marked_by']) ?><?= $it['note'] ? ' · 📝 ' . htmlspecialchars($it['note']) : '' ?></div><?php endif; ?>
                <?php if ($adminView): $lf = (string)($it['loc_flag'] ?? ''); $kc = $caseOf[(int)$it['id']] ?? null; ?><div class="sc-meta">
                    <?php if ($lf === 'ok'): ?><span class="ok">📍 <?= htmlspecialchars(check_m((int)$it['dist_m'], $lang)) ?></span>
                    <?php elseif ($lf === 'weak'): ?><span class="mid" title="<?= $ar ? 'دقة الموقع ضعيفة' : 'Vague location' ?>">📍 <?= htmlspecialchars(check_m((int)$it['dist_m'], $lang)) ?> ±<?= htmlspecialchars(check_m((int)$it['acc'], $lang)) ?></span>
                    <?php elseif ($lf === 'none'): ?><span class="bad">📵 <?= $ar ? 'بدون موقع' : 'no location' ?></span><?php endif; ?>
                    <?php $pr = (int)($it['photo_req'] ?? 0); if ($pr): $phDone = (!($pr & 1) || !empty($it['photo'])) && (!($pr & 2) || !empty($it['car_photo'])); ?><span class="ph">📷 <?= ($isSpot || $isPick ? ($ar ? 'صورة مطلوبة: ' : 'photo asked: ') : ($ar ? 'صورة عشوائية: ' : 'random photo: ')) . htmlspecialchars($phNames[$pr] ?? '') ?><?= $phDone ? ' ✓' : '' ?></span><?php endif; ?>
                    <?php if ($kc): ?><span class="case <?= htmlspecialchars((string)$kc['status']) ?>">🔎 <?= htmlspecialchars(($ar ? ['open' => 'تنتظر قرارك', 'found' => 'وُجدت', 'moved' => 'في فرع آخر', 'sold' => 'بيعت ولم تُسجَّل', 'lost' => 'مفقودة'] : ['open' => 'waiting for you', 'found' => 'found', 'moved' => 'at another branch', 'sold' => 'sold, not recorded', 'lost' => 'lost'])[$kc['status']] ?? $kc['status']) ?><?= $kc['decided_by'] ? ' · ' . htmlspecialchars((string)$kc['decided_by']) : '' ?></span><?php endif; ?>
                </div><?php endif; ?></div>
            <?php if ($doMode): ?>
            <div class="bt"><button type="button" class="y<?= $it['state'] === 'present' ? ' on' : '' ?>">✅ <?= $ar ? 'موجودة' : 'Here' ?></button><button type="button" class="n<?= $it['state'] === 'missing' ? ' on' : '' ?>">❌ <?= $ar ? 'غير موجودة' : 'Missing' ?></button></div>
            <?php if (!$isAdmin && $byColor): ?><div class="cwb"></div>
            <?php elseif (!$isAdmin): ?><div class="chb"><input class="sc-in chin" autocapitalize="characters" autocomplete="off" autocorrect="off" spellcheck="false" maxlength="40" placeholder="<?= $ar ? 'اكتب رقم الشاسيه من السيارة' : 'Type the chassis from the car' ?>"><button type="button" class="chok">✅ <?= $ar ? 'تأكيد' : 'Confirm' ?></button><div class="cherr"></div></div><?php endif; ?>
            <?php $pr = (int)($it['photo_req'] ?? 0); if (!$isAdmin && $pr && ($isSpot || $it['state'] === 'present')): /* 📷 asked by the admin; a random one is shown only once the car is confirmed */ ?><div class="phb">
                <?php if ($pr & 1): $dn = !empty($it['photo']); ?><button type="button" class="phbtn<?= $dn ? ' done' : '' ?>" data-k="chassis">📷 <span><?= $dn ? ($ar ? 'تم إرسال صورة الشاسيه ✓ — إعادة' : 'Chassis plate photo sent ✓ — retake') : ($ar ? 'التقط صورة لرقم الشاسيه' : 'Photograph the chassis plate') ?></span></button><?php endif; ?>
                <?php if ($pr & 2): $dn = !empty($it['car_photo']); ?><button type="button" class="phbtn<?= $dn ? ' done' : '' ?>" data-k="car">🚗 <span><?= $dn ? ($ar ? 'تم إرسال صورة السيارة ✓ — إعادة' : 'Car photo sent ✓ — retake') : ($ar ? 'التقط صورة للسيارة كاملة' : 'Photograph the whole car') ?></span></button><?php endif; ?>
                <div class="pherr"></div></div><?php endif; ?>
            <input class="sc-in nt" maxlength="200" placeholder="<?= $ar ? 'ملاحظة: أين هي؟ (اختياري)' : 'Note: where is it? (optional)' ?>" value="<?= htmlspecialchars((string)$it['note']) ?>">
            <?php else: ?>
            <span class="sc-st <?= $it['state'] === 'present' ? 'done' : ($it['state'] === 'missing' ? 'expired' : '') ?>"><?= $it['state'] === 'present' ? ($ar ? '✅ موجودة' : '✅ here') : ($it['state'] === 'missing' ? ($ar ? '❌ غير موجودة' : '❌ missing') : ($ar ? '⏳ لم تُراجع' : '⏳ not checked')) ?></span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (!$cT): ?><div class="nf-empty"><?= $ar ? 'لا توجد سيارات مسجلة في هذا الفرع' : 'No cars recorded at this branch' ?></div><?php endif; ?>

        <?php if (!$isPick): ?>
        <h2 style="margin-top:14px">➕ <?= $ar ? 'سيارات موجودة في الفرع وغير مسجلة' : 'Cars at the branch but not in the system' ?></h2>
        <div class="sc-exl" id="scExl">
            <?php foreach ($items as $it): if ((int)$it['car_id'] !== 0) continue; ?>
            <span data-id="<?= (int)$it['id'] ?>"><?= htmlspecialchars($it['label']) ?><?php if ($doMode): ?> <button type="button">✕</button><?php endif; ?></span>
            <?php endforeach; ?>
        </div>
        <?php if ($doMode): ?>
        <div class="sc-ex"><input class="sc-in" id="scExIn" maxlength="200" placeholder="<?= $ar ? 'الموديل واللون ورقم الشاسيه' : 'Model, colour and chassis' ?>"><button type="button" class="nf-btn" id="scExAdd"><?= $ar ? 'إضافة' : 'Add' ?></button></div>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($doMode): ?>
        <button type="button" class="sc-fin" id="scFin" <?= $cM < $cT ? 'disabled' : '' ?>>🏁 <?= $ar ? 'إنهاء الجرد وإرسال النتيجة' : 'Finish and send the result' ?></button>
        <?php endif; ?>
    </section>
    <?php endif; ?>
<?php else: ?>
    <section class="nf-card"><div class="nf-empty"><?= $ar ? 'لا يوجد جرد مطلوب منك الآن' : 'No stock check for you right now' ?></div></section>
<?php endif; ?>
</div>
<script>
(function () {
    const CSRF = <?= json_encode($csrf) ?>, AR = <?= json_encode($ar) ?>, CHECK = <?= (int)($check['id'] ?? 0) ?>;
    const $ = id => document.getElementById(id);
    const post = d => fetch(location.pathname, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ csrf: CSRF, check: CHECK, lang: <?= json_encode($lang) ?> }, d)) }).then(r => r.json());

    const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const arN = (n, w) => n === 1 ? w[0] : n === 2 ? w[1] : n + ' ' + (n % 100 >= 3 && n % 100 <= 10 ? w[2] : (n % 100 <= 2 && n >= 100 ? w[4] : w[3]));
    const arCars = n => arN(n, ['سيارة واحدة', 'سيارتان', 'سيارات', 'سيارة', 'سيارة']);
    const ago = n => n == null ? '' : AR ? (n <= 0 ? 'اليوم' : n === 1 ? 'أمس' : 'منذ ' + arN(n, ['يوم', 'يومين', 'أيام', 'يوماً', 'يوم'])) : (n <= 0 ? 'today' : n === 1 ? 'yesterday' : n + ' days ago');
    const day = ymd => { if (!ymd) return ''; const [y, m, d] = ymd.split('-'); return d + '/' + m + (+y !== new Date().getFullYear() ? '/' + y : ''); };

    /* ── new check ── */
    if ($('scGo')) {
        let branch = '', mins = 30, mode = 'all';
        const ppl = [...document.querySelectorAll('.sc-p')];
        const paintHere = () => ppl.forEach(p => { p.querySelector('small').textContent = branch && p.dataset.here === branch ? (AR ? '• في الفرع الآن' : '• at the branch') : ''; });
        document.querySelectorAll('.sc-b').forEach(b => b.addEventListener('click', () => { branch = b.dataset.b; document.querySelectorAll('.sc-b').forEach(x => x.classList.toggle('on', x === b)); paintHere(); loadCars(); }));

        /* «سيارات أختارها»: the branch's cars, with when each was added and when it last arrived; each one chosen is asked by its chassis or its colour */
        let cars = [], suggest = [], loadedFor = '', sortBy = 'new';
        const picks = new Map();   // car id → 'chassis' | 'color'
        const shots = new Map();   // 📷 of the chosen cars: car id → what to photograph (1 the chassis plate · 2 the car · 3 both)
        const qs = new Map();      // 🔢 the count questions chosen: 'kind|key' → {kind, key}
        const list = $('scPkList');
        document.querySelectorAll('#scMode button').forEach(b => b.addEventListener('click', () => {
            mode = b.dataset.mode;
            document.querySelectorAll('#scMode button').forEach(x => { x.classList.toggle('on', x === b); x.setAttribute('aria-checked', x === b ? 'true' : 'false'); });
            $('scPick').hidden = mode !== 'pick';
            $('scPhRow').hidden = mode === 'pick'; $('scPhHint').hidden = mode === 'pick'; $('scPhPick').hidden = mode !== 'pick';   // 📷 chosen car by car, never at random
            loadCars();
        }));
        /* the branch's cars (for choosing) and the count questions the system suggests for it */
        async function loadCars() {
            $('scPkNoB').hidden = !!branch; $('scPkBar').hidden = !branch; $('scXqNoB').hidden = !!branch;
            if (!branch) { list.innerHTML = ''; paintSum(); return; }
            if (loadedFor === branch) return;
            loadedFor = branch; picks.clear(); shots.clear(); qs.clear(); cars = []; suggest = [];
            list.innerHTML = '<div class="sc-pk-load"><span class="nf-spin"></span></div>'; $('scXq').innerHTML = '';
            const r = await post({ action: 'cars', branch }).catch(() => ({ ok: false }));
            if (loadedFor !== branch) return;   // another branch was chosen meanwhile
            if (!r.ok) { loadedFor = ''; list.innerHTML = '<div class="sc-pk-empty">' + (AR ? 'تعذّر تحميل السيارات — حاول مرة أخرى' : 'Could not load the cars — try again') + '</div>'; return; }
            cars = r.cars; suggest = r.suggest || []; draw(); drawQs();
        }
        function drawQs() {
            const box = $('scXq');
            if (!suggest.length) { box.innerHTML = '<div class="sc-pk-empty">' + (AR ? 'لا توجد سيارات في هذا الفرع' : 'No cars at this branch') + '</div>'; return; }
            box.innerHTML = suggest.map((q, i) => '<button type="button" class="xq" data-i="' + i + '" aria-pressed="false" title="' + esc(q.kind === 'color' && Object.keys(q.shades).length > 1 ? Object.entries(q.shades).map(([k, v]) => k + ' ×' + v).join(' + ') : '') + '">'
                + (q.kind === 'color' ? '<i style="background:' + esc(q.hex) + '"></i>' : '🚗 ') + esc(q.q) + ' <em>' + (AR ? 'النظام: ' : 'system: ') + q.n + '</em></button>').join('');
            box.querySelectorAll('.xq').forEach(b => b.addEventListener('click', () => {
                const q = suggest[+b.dataset.i], k = q.kind + '|' + q.key;
                if (qs.has(k)) qs.delete(k); else if (qs.size < 4) qs.set(k, { kind: q.kind, key: q.key });
                paintQs();
            }));
            paintQs();
        }
        function paintQs() {
            $('scXq').querySelectorAll('.xq').forEach(b => { const q = suggest[+b.dataset.i], on = qs.has(q.kind + '|' + q.key);
                b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); b.disabled = !on && qs.size >= 4; });
        }
        $('scPh').addEventListener('change', () => { $('scPhK').disabled = !+$('scPh').value; });   // 📷 of what: only with photos
        /* 👥 a double check needs two people or more */
        const paintDbl = () => { const n = ppl.filter(p => p.classList.contains('on')).length; $('scDbl').disabled = n < 2; if (n < 2) $('scDbl').checked = false; $('scDblL').classList.toggle('off', n < 2); };
        ppl.forEach(p => p.addEventListener('click', () => setTimeout(paintDbl, 0)));   // after the person is (un)selected
        function chips(c) {
            const out = [];
            if (c.mv) {
                const t = c.mv_type === 'amana_return' ? (AR ? '↩️ رجعت من الأمانة' : '↩️ Back from consignment')
                        : c.mv_type === 'sale_return' ? (AR ? '⏪ رجعت بعد البيع' : '⏪ Returned after sale')
                        : (AR ? '🔄 وصلت بالنقل' + (c.mv_from ? ' من ' + c.mv_from : '') : '🔄 Transferred in' + (c.mv_from ? ' from ' + c.mv_from : ''));
                out.push('<span class="mv">' + esc(t + ' · ' + day(c.mv) + ' · ' + ago(c.mv_d)) + '</span>');
            }
            if (c.added) out.push('<span class="' + (c.added_d != null && c.added_d <= 7 ? 'nw' : '') + '">' + esc((AR ? '🆕 أُضيفت ' : '🆕 Added ') + day(c.added) + ' · ' + ago(c.added_d)) + '</span>');
            out.push(c.seen_d == null ? '<span class="sn no">' + (AR ? '👁️ لم يُؤكَّد وجودها فعلياً بعد' : '👁️ Never physically confirmed') + '</span>'
                                      : '<span class="sn">' + esc((AR ? '👁️ آخر تأكيد فعلي ' : '👁️ Last confirmed ') + ago(c.seen_d)) + '</span>');
            return out.join('');
        }
        function draw() {
            if (!cars.length) { list.innerHTML = '<div class="sc-pk-empty">' + (AR ? 'لا توجد سيارات في هذا الفرع' : 'No cars at this branch') + '</div>'; paintSum(); return; }
            const rows = cars.slice().sort((a, b) => sortBy === 'new' ? b.arr - a.arr || b.id - a.id : a.arr - b.arr || a.id - b.id);
            list.innerHTML = rows.map(c => '<div class="pk-car" data-id="' + c.id + '" role="checkbox" aria-checked="false" tabindex="0" data-q="' + esc((c.name + ' ' + c.color + ' ' + c.chassis).toLowerCase()) + '">'
                + '<span class="pk-tick" aria-hidden="true">✓</span>'
                + '<div class="pk-i"><b>🚗 ' + esc(c.name) + (c.res ? '<em class="pk-res">' + (AR ? 'محجوزة' : 'reserved') + '</em>' : '') + '</b>'
                + '<div class="pk-m"><span class="pk-col"><i style="background:' + esc(c.hex) + '"></i>' + esc(c.color) + '</span><span class="pk-ch">🔑 ' + esc(c.chassis) + '</span></div>'
                + '<div class="pk-d">' + chips(c) + '</div></div>'
                + '<div class="pk-ask" role="radiogroup"><button type="button" data-a="chassis" role="radio">🔑 ' + (AR ? 'يكتب الشاسيه' : 'Type the chassis') + '</button><button type="button" data-a="color" role="radio">🎨 ' + (AR ? 'يختار اللون' : 'Pick the colour') + '</button></div>'
                + '<div class="pk-ph" role="group" aria-label="' + (AR ? 'صورة بكاميرا الهاتف (اختياري)' : 'Phone-camera photo (optional)') + '"><button type="button" data-p="1" aria-pressed="false">📷 ' + (AR ? 'صورة الشاسيه' : 'Chassis photo') + '</button><button type="button" data-p="2" aria-pressed="false">🚗 ' + (AR ? 'صورة السيارة' : 'Car photo') + '</button></div>'
                + '</div>').join('');
            list.querySelectorAll('.pk-car').forEach(el => {
                const id = +el.dataset.id;
                const flip = () => { if (picks.has(id)) { picks.delete(id); shots.delete(id); } else picks.set(id, 'chassis'); paintRow(el); paintSum(); };
                el.addEventListener('click', e => { if (!e.target.closest('.pk-ask, .pk-ph')) flip(); });
                el.addEventListener('keydown', e => { if ((e.key === ' ' || e.key === 'Enter') && e.target === el) { e.preventDefault(); flip(); } });
                el.querySelectorAll('.pk-ask button').forEach(b => b.addEventListener('click', () => { picks.set(id, b.dataset.a); paintRow(el); paintSum(); }));
                el.querySelectorAll('.pk-ph button').forEach(b => b.addEventListener('click', () => {   // 📷 the chassis plate and/or the car: each on or off
                    const v = (shots.get(id) || 0) ^ +b.dataset.p;
                    if (v) shots.set(id, v); else shots.delete(id);
                    paintRow(el); paintSum();
                }));
                paintRow(el);
            });
            filter(); paintSum();
        }
        function paintRow(el) {
            const a = picks.get(+el.dataset.id);
            el.classList.toggle('on', !!a); el.setAttribute('aria-checked', a ? 'true' : 'false');
            el.querySelectorAll('.pk-ask button').forEach(b => { b.classList.toggle('on', b.dataset.a === a); b.setAttribute('aria-checked', b.dataset.a === a ? 'true' : 'false'); });
            const ph = shots.get(+el.dataset.id) || 0;
            el.querySelectorAll('.pk-ph button').forEach(b => { const on = !!(ph & +b.dataset.p); b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
        }
        function paintSum() {
            const n = picks.size, k = [...picks.values()].filter(v => v === 'color').length, p = shots.size;
            $('scPkSum').hidden = !n;
            $('scPkSumT').textContent = AR ? '✓ ' + arCars(n) + ' · 🔑 ' + (n - k) + ' بالشاسيه · 🎨 ' + k + ' باللون' + (p ? ' · 📷 ' + p + ' بصور' : '')
                                           : '✓ ' + n + (n === 1 ? ' car' : ' cars') + ' · 🔑 ' + (n - k) + ' by chassis · 🎨 ' + k + ' by colour' + (p ? ' · 📷 ' + p + ' with photos' : '');
        }
        function filter() {
            const q = $('scPkQ').value.trim().toLowerCase();
            list.querySelectorAll('.pk-car').forEach(el => { el.hidden = !!q && !el.dataset.q.includes(q); });
        }
        $('scPkQ').addEventListener('input', filter);
        document.querySelectorAll('#scPkSort button').forEach(b => b.addEventListener('click', () => { sortBy = b.dataset.s; document.querySelectorAll('#scPkSort button').forEach(x => x.classList.toggle('on', x === b)); draw(); }));
        document.querySelectorAll('[data-all]').forEach(b => b.addEventListener('click', () => {
            if (b.dataset.all === 'none') { picks.clear(); shots.clear(); } else picks.forEach((v, id) => picks.set(id, b.dataset.all));
            list.querySelectorAll('.pk-car').forEach(paintRow); paintSum();
        }));
        ppl.forEach(p => p.addEventListener('click', () => p.classList.toggle('on')));
        document.querySelectorAll('.sc-w').forEach(w => w.addEventListener('click', () => w.classList.toggle('on')));
        document.querySelectorAll('.sc-grp button').forEach(g => g.addEventListener('click', () => {
            const ws = [...document.querySelectorAll('.sc-w')];
            if (g.dataset.g === 'none') { ws.forEach(w => w.classList.remove('on')); return; }
            const grp = ws.filter(w => g.dataset.g === '*' || w.dataset.role === g.dataset.g);
            const all = grp.every(w => w.classList.contains('on'));
            grp.forEach(w => w.classList.toggle('on', !all));
        }));
        document.querySelectorAll('.sc-t').forEach(t => t.addEventListener('click', () => { mins = +t.dataset.m; $('scMin').value = mins; document.querySelectorAll('.sc-t').forEach(x => x.classList.toggle('on', x === t)); }));
        $('scMin').addEventListener('input', () => { mins = +$('scMin').value; document.querySelectorAll('.sc-t').forEach(x => x.classList.toggle('on', +x.dataset.m === mins)); });
        $('scGo').addEventListener('click', async () => {
            const users = ppl.filter(p => p.classList.contains('on')).map(p => +p.dataset.u), m = $('scMsg');
            if (!branch) { m.textContent = AR ? 'اختر الفرع' : 'Pick the branch'; m.className = 'nf-msg bad'; return; }
            if (!users.length) { m.textContent = AR ? 'اختر المكلَّف بالجرد' : 'Pick who does the check'; m.className = 'nf-msg bad'; return; }
            const n = picks.size, k = [...picks.values()].filter(v => v === 'color').length, p = shots.size;
            if (mode === 'pick' && !n) { m.textContent = AR ? 'اختر السيارات المطلوب تأكيدها' : 'Choose the cars to confirm'; m.className = 'nf-msg bad'; $('scPick').scrollIntoView({ block: 'center', behavior: 'smooth' }); return; }
            const extra = (mode === 'pick' && p ? (AR ? '\n📷 سيارات تحتاج صوراً: ' + p + ' — لا يعرف المكلَّف بها إلا بعد تأكيد كل سيارة' : '\n📷 Cars needing photos: ' + p + ' — they only learn it once they confirm each car') : '') + (qs.size ? (AR ? '\n🔢 أسئلة عدّ إضافية: ' + qs.size : '\n🔢 Extra count questions: ' + qs.size) : '') + ($('scDbl').checked ? (AR ? '\n👥 جرد مزدوج: كل واحد يجرد وحده' : '\n👥 Double check: each one alone') : '');
            if (!confirm((mode === 'pick' ? (AR ? 'إرسال الجرد المفاجئ الآن؟\nبعد عدّ السيارات يؤكد السيارات المختارة (' + arCars(n) + '): ' + (n - k) + ' بالشاسيه و' + k + ' باللون.' : 'Send the surprise check now?\nAfter counting, they confirm ' + n + ' cars: ' + (n - k) + ' by chassis, ' + k + ' by colour.')
                                          : (AR ? 'إرسال الجرد المفاجئ الآن؟' : 'Send the surprise check now?')) + extra)) return;
            $('scGo').disabled = true;
            const r = await post({ action: 'create', branch, users, minutes: +$('scMin').value || 30, note: $('scNote').value, lock: $('scLock').checked,
                watch: [...document.querySelectorAll('.sc-w.on')].map(w => +w.dataset.u), mode, picks: mode === 'pick' ? [...picks].map(([car, ask]) => ({ car, ask, photo: shots.get(car) || 0 })) : [],
                questions: [...qs.values()], photos: mode === 'pick' ? 0 : +$('scPh').value, photo_kind: +$('scPhK').value, double: $('scDbl').checked });
            if (r.ok) location.href = '?lang=<?= $lang ?>&id=' + r.id;
            else { $('scGo').disabled = false; m.className = 'nf-msg bad';
                m.textContent = r.error === 'nocars' ? (AR ? 'اختر السيارات المطلوب تأكيدها' : 'Choose the cars to confirm') : r.error === 'gone' ? (AR ? 'السيارات المختارة لم تعد في هذا الفرع — حدّث الصفحة' : 'The chosen cars are no longer at this branch — reload') : '✗ ' + r.error; }
        });
    }

    /* ── proposals, spot check, automatic proposals ── */
    document.querySelectorAll('[data-plan]').forEach(b => b.addEventListener('click', async () => {
        const send = b.dataset.send === '1', row = b.closest('.sc-plan');
        if (send && !confirm(AR ? 'إرسال هذا الطلب الآن؟' : 'Send this now?')) return;
        row.querySelectorAll('button').forEach(x => x.disabled = true);
        const pp = row.querySelector('[data-pp]');
        const r = await post({ action: 'plan', plan: +b.dataset.plan, send, minutes: +row.querySelector('[data-pm]').value, photo: pp ? +pp.value : 0 });
        if (r.ok && r.id) location.href = '?lang=<?= $lang ?>&id=' + r.id; else location.reload();
    }));
    if ($('spGo')) {
        const sb = $('spBranch'), su = $('spUser');
        sb.addEventListener('change', () => { [...su.options].forEach(o => { if (o.value) o.textContent = o.textContent.replace(/ 🟢.*$/, '') + (sb.value && o.dataset.here === sb.value ? (AR ? ' 🟢 في الفرع الآن' : ' 🟢 at the branch') : ''); }); });
        $('spGo').addEventListener('click', async () => {
            const m = $('spMsg');
            if (!sb.value || !su.value) { m.textContent = AR ? 'اختر الفرع والمكلَّف' : 'Pick the branch and the person'; m.className = 'nf-msg bad'; return; }
            if (!confirm(AR ? 'إرسال فحص سريع لسيارة واحدة الآن؟' : 'Send a one-car spot check now?')) return;
            $('spGo').disabled = true;
            const r = await post({ action: 'spot', branch: sb.value, user: +su.value, minutes: +$('spMin').value, photo: +$('spPh').value });
            if (r.ok) location.href = '?lang=<?= $lang ?>&id=' + r.id; else { $('spGo').disabled = false; m.textContent = r.error === 'nocar' ? (AR ? 'لا توجد سيارات في هذا الفرع' : 'No cars at this branch') : '✗ ' + r.error; m.className = 'nf-msg bad'; }
        });
    }
    if ($('auSave')) $('auSave').addEventListener('click', async () => {
        const r = await post({ action: 'auto_rules', on: $('auOn').checked, full: +$('auFull').value, spot: +$('auSpot').value, ping: +$('auPing').value, from: +$('auFrom').value, to: +$('auTo').value }), m = $('auMsg');
        m.textContent = r.ok ? (AR ? '✓ تم الحفظ' : '✓ Saved') : '✗'; m.className = 'nf-msg ' + (r.ok ? 'ok' : 'bad');
    });

    /* ── countdown (it moves when more time is added) ── */
    const cd = $('scCd');
    let cdEnd = cd ? Date.now() + (+cd.dataset.secs) * 1000 : 0;
    const setSecs = s => { cdEnd = Date.now() + s * 1000; };
    if (cd) {
        const tick = () => { const s = Math.round((cdEnd - Date.now()) / 1000), a = Math.abs(s);
            cd.textContent = (s < 0 ? '−' : '') + Math.floor(a / 3600) + ':' + String(Math.floor(a % 3600 / 60)).padStart(2, '0') + ':' + String(a % 60).padStart(2, '0');
            cd.classList.toggle('over', s <= 0); cd.classList.toggle('low', s > 0 && s < 600); };
        tick(); setInterval(tick, 1000);
    }

    /* ── step 1: how many cars are at the branch ── */
    if ($('scCntGo')) {
        const go = async () => {
            const v = $('scCntIn').value.trim(), m = $('scCntMsg');
            if (v === '' || !/^\d+$/.test(v)) { m.textContent = AR ? 'اكتب عدد السيارات بالأرقام' : 'Type the number of cars'; m.className = 'nf-msg bad'; $('scCntIn').focus(); return; }
            if (!confirm(AR ? 'تأكيد أن عدد السيارات في الفرع هو ' + v + '؟' : 'Confirm ' + v + ' cars at the branch?')) return;
            $('scCntGo').disabled = true;
            const r = await post({ action: 'count', n: +v }).catch(() => ({ ok: false }));
            if (r.ok) location.reload(); else if (r.go) location.href = r.go; else { $('scCntGo').disabled = false; m.className = 'nf-msg bad';
                m.textContent = r.error === 'count' ? (AR ? '❌ العدد غير صحيح. أعد عدّ السيارات بدقة — إذا أُدخل عدد غير صحيح مرة أخرى يتوقف النظام.' : '❌ Wrong number. Count the cars again carefully — one more wrong number stops your system.') : (AR ? 'تعذّر الحفظ — حاول مرة أخرى' : 'Could not save — try again');
                if (r.error === 'count') { $('scCntIn').value = ''; $('scCntIn').focus(); if (window.F1App) F1App.sound('bad'); } }
        };
        $('scCntGo').addEventListener('click', go);
        $('scCntIn').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); go(); } });
    }
    /* 🔢 the count questions the admin added (by colour or brand), one after the other */
    if ($('scQGo')) {
        const go = async () => {
            const v = $('scQIn').value.trim(), m = $('scQMsg');
            if (v === '' || !/^\d+$/.test(v)) { m.textContent = AR ? 'اكتب العدد بالأرقام' : 'Type the number'; m.className = 'nf-msg bad'; $('scQIn').focus(); return; }
            if (!confirm(AR ? 'تأكيد أن العدد هو ' + v + '؟' : 'Confirm ' + v + '?')) return;
            $('scQGo').disabled = true;
            const r = await post({ action: 'qcount', q: +$('scQGo').dataset.q, n: +v }).catch(() => ({ ok: false }));
            if (r.ok) location.reload(); else if (r.go) location.href = r.go; else { $('scQGo').disabled = false; m.className = 'nf-msg bad';
                m.textContent = r.error === 'count' ? (AR ? '❌ العدد غير صحيح. أعد العدّ بدقة — إذا أُدخل عدد غير صحيح مرة أخرى يتوقف النظام.' : '❌ Wrong number. Count again carefully — one more wrong number stops your system.') : (AR ? 'تعذّر الحفظ — حاول مرة أخرى' : 'Could not save — try again');
                if (r.error === 'count') { $('scQIn').value = ''; $('scQIn').focus(); if (window.F1App) F1App.sound('bad'); } }
        };
        $('scQGo').addEventListener('click', go);
        $('scQIn').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); go(); } });
    }

    /* a short message at the bottom of the screen */
    const toast = (t, ok) => { const d = document.createElement('div'); d.className = 'sc-toast ' + (ok ? 'ok' : 'bad'); d.textContent = t; document.body.appendChild(d);
        requestAnimationFrame(() => d.classList.add('on')); setTimeout(() => { d.classList.remove('on'); setTimeout(() => d.remove(), 400); }, 4200); };

    /* 📍 every car carries the phone's position: far from the branch is not accepted */
    const NEED_LOC = <?= json_encode(!empty($needLoc)) ?>;
    let fix = null, locErr = 0;
    const locBtn = $('scLoc'), locHelp = $('scLocHelp');
    const paintLoc = () => {
        if (!locBtn) return;
        locBtn.className = 'sc-loc ' + (locErr === 1 ? 'bad' : fix ? (fix.acc > 300 ? 'weak' : 'ok') : locErr ? 'bad' : 'wait');
        locBtn.textContent = locErr === 1 ? (AR ? '⛔ الموقع غير مسموح — اضغط هنا' : '⛔ Location blocked — tap here')
            : fix ? (AR ? '📍 الموقع جاهز ✓' : '📍 Location ready ✓') + (fix.acc > 300 ? ' (±' + fix.acc + (AR ? ' م)' : ' m)') : '')
            : locErr ? (AR ? '📵 تعذّر تحديد الموقع' : '📵 No location yet') : (AR ? '📍 جاري تحديد موقعك…' : '📍 Finding your location…');
    };
    const takeFix = p => { fix = { lat: p.coords.latitude, lng: p.coords.longitude, acc: Math.round(p.coords.accuracy || 0), t: Date.now() }; locErr = 0; if (locHelp) locHelp.hidden = true; paintLoc(); };
    const locFail = e => { locErr = (e && e.code) || 2; paintLoc(); if (locErr === 1 && locHelp) locHelp.hidden = false; };
    if (NEED_LOC && navigator.geolocation) navigator.geolocation.watchPosition(takeFix, locFail, { enableHighAccuracy: true, maximumAge: 10000, timeout: 20000 });
    /* the position sent with a car — null: the phone refuses (nothing is sent, the help shows) · {}: none to be had (sent without; the admins see it) */
    const getLoc = () => new Promise(res => {
        if (!NEED_LOC || !navigator.geolocation) return res({});
        if (fix && Date.now() - fix.t < 30000) return res({ lat: fix.lat, lng: fix.lng, acc: fix.acc });
        navigator.geolocation.getCurrentPosition(p => { takeFix(p); res({ lat: fix.lat, lng: fix.lng, acc: fix.acc }); },
            e => { locFail(e); if (locErr === 1) { if (locHelp) locHelp.scrollIntoView({ block: 'center', behavior: 'smooth' }); res(null); } else res({}); },
            { enableHighAccuracy: true, timeout: 12000, maximumAge: 10000 });
    });
    if (locBtn) locBtn.addEventListener('click', () => { if (locHelp) locHelp.hidden = !locHelp.hidden; });
    if ($('scLocRetry')) $('scLocRetry').addEventListener('click', () => getLoc());
    const farMsg = r => AR ? '📍 أنت على بعد ' + r.dist + ' من الفرع — لم تُقبل السيارة. اذهب إلى السيارة نفسها ثم أكّدها. مرة أخرى ويتوقف النظام.'
                           : '📍 You are ' + r.dist + ' from the branch — the car was not accepted. Go to the car itself, then confirm it. Once more and your system stops.';
    const noLocMsg = AR ? '📍 اسمح للهاتف بإرسال الموقع أولاً (التعليمات أعلى الصفحة)' : '📍 Allow the phone to share its location first (see above)';

    /* ⏳ more time: ask management — the page follows the answer by itself */
    if ($('scXtB')) {
        let xtMin = 15, last = <?= json_encode($timeSt['req']['status'] ?? '') ?>;
        const box = $('scXt'), btn = $('scXtB');
        const paintXt = st => {
            if (!st) return;
            if (typeof st.secs === 'number') setSecs(st.secs);
            const pend = st.req && st.req.status === 'pending';
            btn.classList.toggle('wait', !!pend); btn.disabled = pend || !st.left;
            btn.textContent = pend ? (AR ? '⏳ طلبك بانتظار موافقة الإدارة' : '⏳ Waiting for management') : st.left ? (AR ? '⏳ طلب وقت إضافي' : '⏳ Ask for more time') : (AR ? '⏳ استُخدمت كل الطلبات' : '⏳ No requests left');
            if (pend) box.hidden = true;
            if (st.status === 'active') document.querySelectorAll('.sc-top .sc-st.expired').forEach(x => { x.className = 'sc-st active'; x.textContent = AR ? '⏳ جارٍ' : '⏳ running'; });
        };
        btn.addEventListener('click', () => { box.hidden = !box.hidden; if (!box.hidden) $('scXtR').focus(); });
        $('scXtM').querySelectorAll('button').forEach(b => b.addEventListener('click', () => { xtMin = +b.dataset.m; $('scXtM').querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b)); }));
        $('scXtGo').addEventListener('click', async () => {
            const m = $('scXtMsg'); $('scXtGo').disabled = true;
            const r = await post({ action: 'time_req', minutes: xtMin, reason: $('scXtR').value }).catch(() => ({ ok: false }));
            $('scXtGo').disabled = false;
            if (r.ok) { toast(AR ? '📨 أُرسل الطلب — ستصلك الإجابة هنا وعلى الهاتف' : '📨 Sent — the answer comes here and on your phone', true); last = 'pending'; $('scXtR').value = ''; paintXt(r.state); }
            else { m.className = 'nf-msg bad'; m.textContent = r.error === 'pending' ? (AR ? 'لديك طلب بانتظار الرد' : 'A request is already waiting') : r.error === 'max' ? (AR ? 'استُخدمت كل الطلبات المتاحة لهذا الجرد' : 'No requests left for this check') : (AR ? 'تعذّر الإرسال — حاول مرة أخرى' : 'Could not send — try again'); }
        });
        paintXt(<?= json_encode($timeSt ?? null) ?>);
        setInterval(async () => {
            if (document.hidden) return;
            const st = await post({ action: 'state' }).catch(() => null);
            if (!st || !st.ok) return;
            if (last === 'pending' && st.req && st.req.status !== 'pending') {
                const yes = st.req.status === 'approved';
                toast(yes ? (AR ? '✅ تمت الموافقة على ' + st.req.minutes + ' دقيقة إضافية' : '✅ ' + st.req.minutes + ' more minutes approved') : (AR ? '✖ رُفض طلب الوقت الإضافي' : '✖ The request was refused'), yes);
                if (window.F1App) F1App.sound(yes ? 'ok' : 'bad');
            }
            last = st.req ? st.req.status : '';
            paintXt(st);
        }, 20000);
    }

    /* ── doing the check ── */
    const items = [...document.querySelectorAll('.sc-it')];
    const COLORS = <?= json_encode($colorOpts ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    /* 🎨 a car asked by its colour: scroll the list — the colour in the middle band is the answer. It starts on
       «مرّر لاختيار اللون», so nothing is picked until the person scrolls to a colour themselves. */
    function colorWheel(box, onPick) {
        const ROW = 48;
        const opts = [{ v: '', label: AR ? '— مرّر لاختيار اللون —' : '— scroll to pick —' }].concat(COLORS.map(c => Object.assign({ label: AR ? c.ar : c.en }, c)));
        box.innerHTML = '<div class="cw-t">🎨 ' + (AR ? 'مرّر القائمة واختر لون السيارة كما تراه أمامك' : 'Scroll the list and pick the colour you see on the car') + '</div>'
            + '<div class="cw"><div class="cw-band"></div><div class="cw-win" tabindex="0" role="listbox" aria-label="' + (AR ? 'لون السيارة' : 'Colour of the car') + '"><div class="cw-pad"></div>'
            + opts.map((o, i) => '<div class="cw-o' + (o.v ? '' : ' ph') + '" role="option" aria-selected="false" data-i="' + i + '">' + (o.v ? '<i style="background:' + esc(o.hex) + '"></i>' : '')
                + '<span>' + esc(o.label) + '</span>' + (o.v && AR && o.en && o.en !== o.ar ? '<small>' + esc(o.en) + '</small>' : '') + '</div>').join('')
            + '<div class="cw-pad"></div></div></div><button type="button" class="cw-ok" disabled></button><div class="cherr"></div>';
        const win = box.querySelector('.cw-win'), ok = box.querySelector('.cw-ok'), rows = [...box.querySelectorAll('.cw-o')];
        let cur = -1, raf = 0;
        const show = i => {
            if (i === cur) return; cur = i;
            rows.forEach((r, k) => { r.classList.toggle('on', k === i); r.setAttribute('aria-selected', k === i ? 'true' : 'false'); });
            ok.disabled = !opts[i].v;
            ok.innerHTML = opts[i].v ? '✅ ' + (AR ? 'تأكيد اللون: ' : 'Confirm the colour: ') + '<b>' + esc(opts[i].label) + '</b>' : (AR ? 'مرّر واختر اللون أولاً' : 'Scroll and pick the colour first');
        };
        const mid = () => Math.max(0, Math.min(opts.length - 1, Math.round(win.scrollTop / ROW)));
        const go = (i, smooth) => win.scrollTo({ top: i * ROW, behavior: smooth ? 'smooth' : 'auto' });
        win.addEventListener('scroll', () => { if (!raf) raf = requestAnimationFrame(() => { raf = 0; show(mid()); }); }, { passive: true });
        rows.forEach((r, i) => r.addEventListener('click', () => go(i, true)));
        win.addEventListener('keydown', e => {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); go(Math.max(0, Math.min(opts.length - 1, mid() + (e.key === 'ArrowDown' ? 1 : -1))), true); }
            else if (e.key === 'Enter' && !ok.disabled) { e.preventDefault(); ok.click(); }
        });
        ok.addEventListener('click', () => { if (opts[cur] && opts[cur].v) onPick(opts[cur].v); });
        show(0);
        return { reset: () => { go(0, false); show(0); }, focus: () => win.focus({ preventScroll: true }), err: box.querySelector('.cherr'), ok };
    }
    function counts(c) { if (!c) return; $('scDone').textContent = c[1]; $('scBar').style.width = (c[0] ? Math.round(c[1] * 100 / c[0]) : 100) + '%'; if ($('scFin')) $('scFin').disabled = c[1] < c[0]; }
    /* 📷 the camera inside the page: the photo is taken now — there is no file picker, so nothing can come from the gallery */
    const ME = <?= json_encode($me) ?>;
    const SKEW = <?= time() ?> * 1000 - Date.now(), CAIRO = <?= (int)date('Z') ?> * 1000;   // the stamp uses the server's clock and Cairo time, never the phone's
    const cam = (() => {
        let el = null, stream = null, onUse = null, shot = null, lbl = '', kind = 'chassis';
        const TXT = {
            chassis: { live: AR ? 'ضع رقم الشاسيه داخل الإطار ثم اضغط زر التصوير' : 'Fit the chassis plate inside the frame, then press the shutter',
                       check: AR ? 'تأكد أن رقم الشاسيه واضح ومقروء في الصورة' : 'Make sure the chassis number is clear and readable',
                       title: AR ? '📷 رقم الشاسيه — ' : '📷 Chassis plate — ', stamp: AR ? 'رقم الشاسيه' : 'chassis plate' },
            car:     { live: AR ? 'ابتعد حتى تظهر السيارة كاملة داخل الإطار ثم اضغط زر التصوير' : 'Step back until the whole car is inside the frame, then press the shutter',
                       check: AR ? 'تأكد أن السيارة كاملة وواضحة في الصورة' : 'Make sure the whole car is clear in the photo',
                       title: AR ? '🚗 السيارة — ' : '🚗 The car — ', stamp: AR ? 'صورة السيارة' : 'car' },
        };
        const q = sel => el.querySelector(sel);
        const stop = () => { if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; } if (el) q('video').srcObject = null; };
        const close = () => { stop(); if (el) el.classList.remove('on'); document.documentElement.style.overflow = ''; };
        const mode = live => {
            q('video').hidden = !live; q('.cam-img').hidden = live; q('.cam-frame').hidden = !live;
            q('.cam-shot').hidden = !live; q('.cam-again').hidden = live; q('.cam-use').hidden = live;
            q('.cam-hint').textContent = live ? TXT[kind].live : TXT[kind].check;
        };
        const fail = kind => {
            stop(); q('.cam-shot').hidden = true; q('.cam-frame').hidden = true; q('.cam-hint').textContent = '';
            const m = q('.cam-msg'); m.hidden = false;
            m.innerHTML = (kind === 'denied'
                ? (AR ? '<b>📷 الكاميرا غير مسموحة</b>اسمح باستخدام الكاميرا ثم اضغط «حاول مرة أخرى»:<br>• آيفون: الإعدادات ← Safari (أو التطبيق) ← الكاميرا ← سماح.<br>• أندرويد: اضغط 🔒 بجانب عنوان الموقع ← الأذونات ← الكاميرا ← سماح.'
                      : '<b>📷 The camera is blocked</b>Allow the camera, then press "Try again":<br>• iPhone: Settings → Safari (or the app) → Camera → Allow.<br>• Android: tap 🔒 next to the address → Permissions → Camera → Allow.')
                : kind === 'nocam' ? (AR ? '<b>📷 لا توجد كاميرا متاحة</b>افتح النظام من الهاتف (Safari أو Chrome) لتصوير رقم الشاسيه.' : '<b>📷 No camera available</b>Open the system on the phone (Safari or Chrome) to photograph the chassis plate.')
                : (AR ? '<b>📷 تعذّر تشغيل الكاميرا</b>تأكد أن الكاميرا مسموحة لهذا الموقع (من إعدادات المتصفح)، وأغلق أي تطبيق آخر يستخدمها، ثم حاول مرة أخرى.'
                      : '<b>📷 The camera did not start</b>Make sure the camera is allowed for this site (browser settings) and close any other app using it, then try again.'))
                + '<button type="button" class="cam-retry">🔄 ' + (AR ? 'حاول مرة أخرى' : 'Try again') + '</button>';
            m.querySelector('.cam-retry').addEventListener('click', start);
        };
        async function start() {
            const sh = q('.cam-shot');
            q('.cam-msg').hidden = true; shot = null; mode(true);
            sh.disabled = true;   // until the camera really shows a picture
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return fail('nocam');
            try {
                stop();
                stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } } });
                if (!el.classList.contains('on')) return stop();   // closed while asking
                const v = q('video');
                v.onloadeddata = () => { sh.disabled = !v.videoWidth; };
                v.srcObject = stream; await v.play().catch(() => {});
                if (v.videoWidth) sh.disabled = false;
            } catch (e) {
                const n = e && e.name;
                fail(n === 'NotAllowedError' || n === 'SecurityError' ? 'denied' : n === 'NotFoundError' || n === 'OverconstrainedError' ? 'nocam' : 'err');
            }
        }
        function take() {
            const v = q('video');
            if (!v.videoWidth) return;
            const k = Math.min(1, 1600 / Math.max(v.videoWidth, v.videoHeight)), w = Math.round(v.videoWidth * k), h = Math.round(v.videoHeight * k);
            const cv = document.createElement('canvas'); cv.width = w; cv.height = h;
            const g = cv.getContext('2d'); g.drawImage(v, 0, 0, w, h);
            // when, and by whom, written on the photo itself
            const band = Math.max(28, Math.round(h * .055)), d = new Date(Date.now() + SKEW + CAIRO), pad = n => String(n).padStart(2, '0');
            g.fillStyle = 'rgba(0,0,0,.62)'; g.fillRect(0, h - band, w, band);
            g.fillStyle = '#fff'; g.font = '700 ' + Math.round(band * .48) + 'px sans-serif'; g.textBaseline = 'middle';
            g.fillText(d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()) + ' ' + pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()) + ' · ' + ME + ' · ' + lbl + ' · ' + TXT[kind].stamp, Math.round(band * .4), h - band / 2, w - Math.round(band * .8));
            shot = cv; q('.cam-img').src = cv.toDataURL('image/jpeg', .85);
            stop(); mode(false);
            if (navigator.vibrate) navigator.vibrate(30);
        }
        function build() {
            el = document.createElement('div'); el.className = 'cam'; el.setAttribute('role', 'dialog'); el.setAttribute('aria-modal', 'true');
            el.innerHTML = '<div class="cam-top"><b class="cam-t"></b><button type="button" class="cam-x" aria-label="' + (AR ? 'إغلاق' : 'Close') + '">✕</button></div>'
                + '<div class="cam-view"><video playsinline muted autoplay></video><img class="cam-img" alt="" hidden><div class="cam-frame"><span></span></div><div class="cam-msg" hidden></div></div>'
                + '<div class="cam-hint"></div>'
                + '<div class="cam-bar"><button type="button" class="cam-shot" aria-label="' + (AR ? 'التقاط الصورة' : 'Take the photo') + '"><i></i></button>'
                + '<button type="button" class="cam-again" hidden>🔄 ' + (AR ? 'إعادة التصوير' : 'Retake') + '</button><button type="button" class="cam-use" hidden>✅ ' + (AR ? 'إرسال الصورة' : 'Send the photo') + '</button></div>';
            document.body.appendChild(el);
            q('.cam-x').addEventListener('click', close);
            q('.cam-shot').addEventListener('click', take);
            q('.cam-again').addEventListener('click', start);
            q('.cam-use').addEventListener('click', () => { const cv = shot; if (!cv) return; cv.toBlob(b => { close(); if (b && onUse) onUse(b); }, 'image/jpeg', .85); });
            document.addEventListener('keydown', e => { if (e.key === 'Escape' && el.classList.contains('on')) close(); });
        }
        return { open(label, use, what) {
            if (!el) build();
            lbl = label; onUse = use; kind = what === 'car' ? 'car' : 'chassis';
            q('.cam-t').textContent = TXT[kind].title + label; el.classList.toggle('car', kind === 'car');
            el.classList.add('on'); document.documentElement.style.overflow = 'hidden'; start();
        } };
    })();
    const PH_TXT = {
        chassis: { take: AR ? 'التقط صورة لرقم الشاسيه' : 'Photograph the chassis plate', done: AR ? 'تم إرسال صورة الشاسيه ✓ — إعادة' : 'Chassis plate photo sent ✓ — retake' },
        car:     { take: AR ? 'التقط صورة للسيارة كاملة' : 'Photograph the whole car',     done: AR ? 'تم إرسال صورة السيارة ✓ — إعادة' : 'Car photo sent ✓ — retake' },
    };
    async function sendPhoto(it, btn, blob) {
        const k = btn.dataset.k === 'car' ? 'car' : 'chassis', err = it.querySelector('.phb .pherr'), lbl = btn.querySelector('span');
        btn.classList.add('busy'); err.textContent = ''; lbl.textContent = AR ? 'جاري إرسال الصورة…' : 'Sending the photo…';
        try {
            const fd = new FormData(); fd.append('csrf', CSRF); fd.append('check', CHECK); fd.append('item', it.dataset.id); fd.append('src', 'camera'); fd.append('kind', k); fd.append('photo', blob, 'p.jpg');
            const r = await (await fetch(location.pathname, { method: 'POST', credentials: 'same-origin', body: fd })).json();
            if (!r.ok) throw new Error(r.error);
            btn.classList.add('done'); lbl.textContent = PH_TXT[k].done; if (window.F1App) F1App.sound('ok');
        } catch (x) {
            lbl.textContent = btn.classList.contains('done') ? PH_TXT[k].done : PH_TXT[k].take;
            err.textContent = AR ? 'تعذّر إرسال الصورة — حاول مرة أخرى' : 'Could not send the photo — try again';
        }
        btn.classList.remove('busy');
    }
    function bindPhoto(it) {
        const name = ((it.querySelector('.i b') || {}).textContent || '').replace(/^🚗\s*/, '').trim();
        it.querySelectorAll('.phb .phbtn').forEach(btn => {
            if (btn.dataset.bound) return;
            btn.dataset.bound = '1';
            btn.addEventListener('click', () => cam.open(name, blob => sendPhoto(it, btn, blob), btn.dataset.k));
        });
    }
    /* 📷 this car was picked at random for photos: it is said only now, after it was confirmed. bits: 1 the chassis plate · 2 the car */
    function askPhoto(el, bits) {
        if (el.querySelector('.phb')) return;
        const d = document.createElement('div'); d.className = 'phb';
        d.innerHTML = '<div class="phq">📷 ' + (AR ? 'مطلوب لهذه السيارة: ' + (bits === 3 ? 'صورة لرقم الشاسيه وصورة للسيارة كاملة' : bits === 2 ? 'صورة للسيارة كاملة' : 'صورة لرقم الشاسيه')
                                                   : 'This car needs ' + (bits === 3 ? 'a photo of its chassis plate and one of the whole car' : bits === 2 ? 'a photo of the whole car' : 'a photo of its chassis plate')) + '</div>'
            + (bits & 1 ? '<button type="button" class="phbtn" data-k="chassis">📷 <span>' + PH_TXT.chassis.take + '</span></button>' : '')
            + (bits & 2 ? '<button type="button" class="phbtn" data-k="car">🚗 <span>' + PH_TXT.car.take + '</span></button>' : '')
            + '<div class="pherr"></div>';
        el.insertBefore(d, el.querySelector('.nt'));
        bindPhoto(el);
        if (navigator.vibrate) navigator.vibrate([60, 40, 60]);
        d.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
    items.forEach(it => {
        const y = it.querySelector('.y'), n = it.querySelector('.n'), nt = it.querySelector('.nt'), cwb = it.querySelector('.cwb');
        if (!y) return;
        const paint = (el, state) => {
            el.classList.remove('present', 'missing', 'asking'); el.classList.add(state);
            el.querySelector('.y').classList.toggle('on', state === 'present'); el.querySelector('.n').classList.toggle('on', state === 'missing');
        };
        const set = async (state) => {
            paint(it, state);
            if (state === 'missing' && cwb) { const col = it.querySelector('.sc-col'); col.hidden = true; col.innerHTML = '<i></i>'; }
            const r = await post({ action: 'mark', item: +it.dataset.id, state, note: state === 'missing' ? nt.value : '' });
            if (r.ok) counts(r.counts); else if (r.go) location.href = r.go;
            if (state === 'missing') nt.focus();
        };
        /* «موجودة»: the chassis is typed off the car (admins confirm with one tap) */
        const chin = it.querySelector('.chin'), cherr = it.querySelector('.cherr');
        const ask = () => { if (it.classList.contains('present')) return; it.classList.add('asking'); cherr.textContent = ''; chin.focus(); };
        const send = async () => {
            if (!chin.value.trim()) { chin.focus(); return; }
            const ok = it.querySelector('.chok'); ok.disabled = true;
            const loc = await getLoc();
            if (loc === null) { ok.disabled = false; cherr.textContent = noLocMsg; return; }
            const r = await post(Object.assign({ action: 'mark', item: +it.dataset.id, state: 'present', chassis: chin.value }, loc)).catch(() => ({ ok: false, error: 'net' }));
            ok.disabled = false;
            if (r.ok) {
                const el = items.find(x => +x.dataset.id === r.item) || it;      // two cars of the same model: the number decides which one
                paint(el, 'present'); it.classList.remove('asking'); chin.value = '';
                if (window.F1App) F1App.sound('ok');
                if (r.chassis) el.querySelector('.ch').textContent = r.chassis;
                if (el !== it) { el.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
                counts(r.counts);
                if (r.photo) askPhoto(el, r.photo);
            } else if (r.go) { location.href = r.go; }
            else {
                cherr.textContent = r.error === 'chassis' ? (AR ? '❌ رقم الشاسيه غير صحيح. راجع الرقم على السيارة نفسها — إذا أُدخل رقم غير صحيح مرة أخرى يتوقف النظام.' : '❌ Wrong chassis number. Check the number on the car — one more wrong number stops your system.')
                                : r.error === 'far' ? farMsg(r) : (AR ? 'تعذّر الحفظ — حاول مرة أخرى' : 'Could not save — try again');
                it.classList.remove('shake'); void it.offsetWidth; it.classList.add('shake'); chin.select();
                if (window.F1App) F1App.sound('bad');
            }
        };
        bindPhoto(it);
        if (chin) { it.querySelector('.chok').addEventListener('click', send); chin.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); send(); } }); }
        /* 🎨 «موجودة» on a car asked by its colour: the colour picked from the list is checked against the car */
        let wheel = null;
        const askColor = () => {
            if (it.classList.contains('present')) return;
            if (!wheel) wheel = colorWheel(cwb, sendColor);
            it.classList.add('asking'); wheel.err.textContent = ''; wheel.reset();
            requestAnimationFrame(() => { cwb.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); wheel.focus(); });
        };
        const sendColor = async v => {
            wheel.ok.disabled = true;
            const loc = await getLoc();
            if (loc === null) { wheel.ok.disabled = false; wheel.err.textContent = noLocMsg; return; }
            const r = await post(Object.assign({ action: 'mark', item: +it.dataset.id, state: 'present', color: v }, loc)).catch(() => ({ ok: false, error: 'net' }));
            if (r.ok) {
                paint(it, 'present');
                const col = it.querySelector('.sc-col'), o = COLORS.find(c => c.v === v);
                col.hidden = false; col.classList.add('ok');
                col.innerHTML = '<i style="background:' + esc(o ? o.hex : '#64748b') + '"></i>' + esc(r.color || (o ? (AR ? o.ar : o.en) : v)) + ' ✓';
                if (window.F1App) F1App.sound('ok');
                counts(r.counts);
                if (r.photo) askPhoto(it, r.photo);
            } else if (r.go) { location.href = r.go; }
            else {
                wheel.err.textContent = r.error === 'color' ? (AR ? '❌ اللون غير صحيح. انظر إلى السيارة نفسها مرة أخرى — إذا اخترت لوناً غير صحيح مرة أخرى يتوقف النظام.' : '❌ Wrong colour. Look at the car itself again — one more wrong answer stops your system.')
                                     : r.error === 'far' ? farMsg(r) : (AR ? 'تعذّر الحفظ — حاول مرة أخرى' : 'Could not save — try again');
                it.classList.remove('shake'); void it.offsetWidth; it.classList.add('shake');
                if (r.error === 'color' || r.error === 'far') wheel.reset(); else wheel.ok.disabled = false;
                if (window.F1App) F1App.sound('bad');
            }
        };
        y.addEventListener('click', () => cwb ? askColor() : chin ? ask() : set('present'));
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
        if (r.error === 'photo') {
            alert(AR ? 'التقط الصور المطلوبة أولاً (📷 / 🚗)' : 'Take the photos asked for first (📷 / 🚗)');
            const need = document.querySelector('.phbtn:not(.done)'); if (need) need.scrollIntoView({ block: 'center', behavior: 'smooth' });
            $('scFin').disabled = false; return;
        }
        if (r.ok) location.href = <?= json_encode(user_lock_active($pdo, $uid) ? 'transfer_lock.php?lang=' . $lang : 'dashboard.php?lang=' . $lang) ?>; else $('scFin').disabled = false;
    });

    /* ── admin ── */
    document.querySelectorAll('[data-unlock]').forEach(b => b.addEventListener('click', async () => { b.disabled = true; const r = await post({ action: 'unlock', uid: +b.dataset.unlock }); if (r.ok) location.reload(); }));
    /* ⏳ a request for more time, or minutes added directly */
    document.querySelectorAll('[data-xt]').forEach(b => b.addEventListener('click', async () => {
        const row = b.closest('.sc-xtr'); row.querySelectorAll('button').forEach(x => x.disabled = true);
        await post({ action: 'time_decide', req: +row.dataset.req, yes: b.dataset.xt === '1' }).catch(() => ({ ok: false }));
        location.reload();
    }));
    document.querySelectorAll('[data-addt]').forEach(b => b.addEventListener('click', async () => {
        if (!confirm(AR ? 'إضافة ' + b.dataset.addt + ' دقيقة لوقت الجرد؟' : 'Add ' + b.dataset.addt + ' minutes to the check?')) return;
        b.disabled = true; await post({ action: 'add_time', minutes: +b.dataset.addt }).catch(() => ({ ok: false })); location.reload();
    }));
    /* 🔎 a car not found: the decision, or a re-check now with someone else */
    document.querySelectorAll('.sc-case').forEach(card => {
        const id = +card.dataset.case, msg = card.querySelector('[data-cmsg]');
        const say = (t, ok) => { msg.textContent = t; msg.className = 'nf-msg ' + (ok ? 'ok' : 'bad'); };
        card.querySelectorAll('[data-how]').forEach(b => b.addEventListener('click', async () => {
            if (!confirm((AR ? 'تسجيل القرار: ' : 'Record: ') + b.textContent.trim() + '؟')) return;
            card.querySelectorAll('button').forEach(x => x.disabled = true);
            const r = await post({ action: 'case', id, how: b.dataset.how, note: card.querySelector('.sc-case-note').value }).catch(() => ({ ok: false }));
            if (r.ok) { say('✓ ' + (AR ? 'تم الحفظ' : 'Saved'), true); card.style.opacity = '.55'; setTimeout(() => location.reload(), 700); }
            else { card.querySelectorAll('button').forEach(x => x.disabled = false); say(AR ? 'تعذّر الحفظ — حاول مرة أخرى' : 'Could not save — try again', false); }
        }));
        const re = card.querySelector('[data-recheck]');
        re.addEventListener('click', async () => {
            const who = +card.querySelector('[data-who]').value;
            if (!who) { say(AR ? 'اختر من يعيد فحصها' : 'Choose who re-checks it', false); return; }
            if (!confirm(AR ? 'إرسال فحص سريع لهذه السيارة الآن؟' : 'Send a spot check of this car now?')) return;
            re.disabled = true;
            const r = await post({ action: 'case_recheck', id, user: who, minutes: 5, photo: +card.querySelector('[data-cph]').value }).catch(() => ({ ok: false }));
            if (r.ok) location.href = '?lang=<?= $lang ?>&id=' + r.id;
            else { re.disabled = false; say(r.error === 'nocar' ? (AR ? 'السيارة لم تعد في هذا الفرع' : 'The car is no longer at this branch') : (AR ? 'تعذّر الإرسال' : 'Could not send'), false); }
        });
    });
    /* 📷 the check's photos, full screen inside the page — never a new window (an installed app would open it in the
       browser, without the login, and show the login page instead of the photo). Tap to zoom, swipe or arrows for the next. */
    const galItems = [...document.querySelectorAll('.sc-gi[data-src]')].map(b => ({ src: b.dataset.src, cap: b.dataset.cap }));
    const viewer = (() => {
        let el = null, list = [], at = 0, x0 = null;
        const q = s => el.querySelector(s);
        function build() {
            el = document.createElement('div'); el.className = 'sc-lb'; el.setAttribute('role', 'dialog'); el.setAttribute('aria-modal', 'true');
            el.innerHTML = '<div class="sc-lb-top"><span class="sc-lb-t"></span><button type="button" class="sc-lb-x" aria-label="' + (AR ? 'إغلاق' : 'Close') + '">✕</button></div>'
                + '<div class="sc-lb-box"><img alt=""></div>'
                // laid out left to right on purpose (an arrow is mirrored inside Arabic text): in Arabic the next photo is on the left
                + '<div class="sc-lb-bar" dir="ltr"><button type="button" data-d="' + (AR ? 1 : -1) + '" aria-label="' + (AR ? 'التالية' : 'Previous') + '">‹</button>'
                + '<span class="sc-lb-n" dir="' + (AR ? 'rtl' : 'ltr') + '"></span>'
                + '<button type="button" data-d="' + (AR ? -1 : 1) + '" aria-label="' + (AR ? 'السابقة' : 'Next') + '">›</button></div>'
                + '<div class="sc-lb-hint">' + (AR ? 'اضغط على الصورة للتكبير · اسحب للصورة التالية' : 'Tap the photo to zoom · swipe for the next one') + '</div>';
            document.body.appendChild(el);
            const box = q('.sc-lb-box'), img = q('.sc-lb-box img');
            q('.sc-lb-x').addEventListener('click', close);
            el.querySelectorAll('[data-d]').forEach(b => b.addEventListener('click', () => go(+b.dataset.d)));
            img.addEventListener('click', e => {   // fit ⇄ the photo at full size, around the point tapped
                if (box.classList.contains('zoom')) { box.classList.remove('zoom'); img.style.width = ''; return; }
                const r = img.getBoundingClientRect(), fx = (e.clientX - r.left) / r.width, fy = (e.clientY - r.top) / r.height;
                box.classList.add('zoom'); img.style.width = Math.max(img.naturalWidth, box.clientWidth * 2) + 'px';
                box.scrollLeft = fx * img.offsetWidth - box.clientWidth / 2; box.scrollTop = fy * img.offsetHeight - box.clientHeight / 2;
            });
            box.addEventListener('touchstart', e => { x0 = box.classList.contains('zoom') || e.touches.length > 1 ? null : e.touches[0].clientX; }, { passive: true });
            box.addEventListener('touchend', e => {
                if (x0 == null) return;
                const dx = e.changedTouches[0].clientX - x0; x0 = null;
                if (Math.abs(dx) > 50) go((dx < 0) === AR ? -1 : 1);   // like turning pages: the next one comes from the side the text goes to
            }, { passive: true });
            document.addEventListener('keydown', e => {
                if (!el.classList.contains('on')) return;
                if (e.key === 'Escape') close(); else if (e.key === 'ArrowLeft') go(AR ? 1 : -1); else if (e.key === 'ArrowRight') go(AR ? -1 : 1);
            });
        }
        function show() {
            const p = list[at], img = q('.sc-lb-box img');
            q('.sc-lb-box').classList.remove('zoom'); img.style.width = '';
            img.src = p.src; img.alt = p.cap; q('.sc-lb-t').textContent = p.cap;
            q('.sc-lb-n').textContent = (at + 1) + (AR ? ' من ' : ' / ') + list.length; q('.sc-lb-bar').hidden = list.length < 2;
        }
        function go(d) { if (list.length < 2) return; at = (at + d + list.length) % list.length; show(); }
        function close() { el.classList.remove('on'); document.documentElement.style.overflow = ''; q('.sc-lb-box img').removeAttribute('src'); }
        return { open(items, i) { if (!el) build(); list = items; at = i; show(); el.classList.add('on'); document.documentElement.style.overflow = 'hidden'; } };
    })();
    document.querySelectorAll('.sc-gi[data-src]').forEach((b, i) => b.addEventListener('click', () => viewer.open(galItems, i)));
    document.querySelectorAll('a.sc-ph-a').forEach(a => a.addEventListener('click', e => {   // the links on a car's row: the same viewer
        e.preventDefault();
        const src = a.getAttribute('href'), i = galItems.findIndex(g => g.src === src);
        if (i >= 0) viewer.open(galItems, i); else viewer.open([{ src, cap: a.textContent.trim() }], 0);
    }));
    if ($('scCancel')) $('scCancel').addEventListener('click', async () => { if (!confirm(AR ? 'إلغاء هذا الجرد؟' : 'Cancel this check?')) return; const r = await post({ action: 'cancel' }); if (r.ok) location.reload(); });
})();
</script>
</body>
</html>
