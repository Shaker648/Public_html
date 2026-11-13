<?php
/*
 * check_auto.php — surprise checks the system proposes, and the admin approves.
 *
 *   - Full stock checks and one-car spot checks are planned at random times
 *     through the week, per branch (how many per week is a setting).
 *   - When a planned time comes, a person who is clocked in at that branch right
 *     then is picked at random and the ADMINS are asked. Nothing reaches the
 *     branch until an admin presses «إرسال الآن». A proposal nobody answers
 *     expires after two hours; the branch never knows it existed.
 *   - A spot check: one random car of the branch, its chassis typed within a
 *     few minutes (same rules as the stock check: hidden number, two wrong
 *     numbers stop the system).
 *
 * Also the branch trust score shown to admins on the dashboard.
 * Needs notify_smart.php.
 */

function check_auto_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_check_plans (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        kind       VARCHAR(10) NOT NULL DEFAULT 'full',
        branch     VARCHAR(100) NOT NULL,
        user_id    INT NULL,
        week_key   VARCHAR(10) NOT NULL,
        due_at     DATETIME NOT NULL,
        status     VARCHAR(12) NOT NULL DEFAULT 'planned',
        asked_at   DATETIME NULL,
        decided_by VARCHAR(100) NULL,
        decided_at DATETIME NULL,
        check_id   INT NULL,
        KEY k_status (status, due_at), KEY k_week (week_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->query("SELECT spot FROM stock_checks LIMIT 1"); }
    catch (Throwable $e) { try { $pdo->exec("ALTER TABLE stock_checks ADD spot TINYINT NOT NULL DEFAULT 0"); } catch (Throwable $e2) {} }
}

/** on / how many per week per branch / between which hours */
function check_auto_rules(PDO $pdo): array
{
    $o = json_decode(push_setting($pdo, 'check_auto', ''), true) ?: [];
    return [
        'on'   => !empty($o['on']),
        'full' => max(0, min(7, (int)($o['full'] ?? 1))),
        'spot' => max(0, min(14, (int)($o['spot'] ?? 2))),
        'ping' => max(0, min(21, (int)($o['ping'] ?? 0))),   // «تأكيد التواجد» proposals per branch per week
        'from' => max(0, min(22, (int)($o['from'] ?? 11))),
        'to'   => max(1, min(23, (int)($o['to'] ?? 20))),
    ];
}

/** Branches that can be checked: the ones that confirm transfers (a storage has nobody there). */
function check_auto_branches(PDO $pdo): array
{
    $out = [];
    try { foreach ($pdo->query("SELECT name FROM branches ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $b) if (transfer_needs_confirm($pdo, (string)$b)) $out[] = (string)$b; } catch (Throwable $e) {}
    return $out;
}

/**
 * Called by the timed run (every 15 minutes). Plans the week, and turns a plan
 * whose time has come into a question for the admins. Returns log lines.
 */
function check_auto_scan(PDO $pdo): array
{
    $log = [];
    try {
        check_auto_tables($pdo);
        $r = check_auto_rules($pdo);
        // proposals nobody answered in two hours quietly expire
        $pdo->exec("UPDATE stock_check_plans SET status = 'expired' WHERE status = 'pending' AND asked_at < NOW() - INTERVAL 2 HOUR");
        if (!$r['on']) return $log;
        $now  = (int)strtotime((string)$pdo->query("SELECT NOW()")->fetchColumn());
        $week = date('o-W', $now);

        /* 1 — plan this week once: random days and times from now to the end of the week */
        $has = $pdo->prepare("SELECT COUNT(*) FROM stock_check_plans WHERE week_key = ?");
        $has->execute([$week]);
        if (!(int)$has->fetchColumn()) {
            $ins = $pdo->prepare("INSERT INTO stock_check_plans (kind, branch, week_key, due_at) VALUES (?, ?, ?, ?)");
            $end = strtotime('next monday', $now);                       // ISO weeks end on Sunday night
            $span = max(1, (int)floor(($end - $now) / 86400));
            foreach (check_auto_branches($pdo) as $b) {
                foreach (['full' => $r['full'], 'spot' => $r['spot'], 'ping' => $r['ping']] as $kind => $n) {
                    for ($i = 0; $i < $n; $i++) {
                        $day = strtotime(date('Y-m-d', $now)) + random_int(0, $span - 1) * 86400;
                        $at  = $day + random_int($r['from'] * 3600, max($r['from'] * 3600 + 900, $r['to'] * 3600 - 1));
                        if ($at <= $now) $at = $now + random_int(900, 7200);
                        $ins->execute([$kind, $b, $week, date('Y-m-d H:i:s', $at)]);
                    }
                }
            }
            $log[] = 'check_auto planned ' . $week;
        }

        /* 2 — a plan whose time has come: pick someone who is at the branch now and ask the admins */
        $due = $pdo->query("SELECT * FROM stock_check_plans WHERE status = 'planned' AND due_at <= NOW() ORDER BY due_at LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
        $h = (int)date('G', $now);
        foreach ($due as $p) {
            $id = (int)$p['id'];
            if ($p['week_key'] !== $week) { $pdo->prepare("UPDATE stock_check_plans SET status = 'expired' WHERE id = ?")->execute([$id]); continue; }
            $later = fn() => $pdo->prepare("UPDATE stock_check_plans SET due_at = NOW() + INTERVAL ? MINUTE WHERE id = ?")->execute([random_int(45, 180), $id]);
            if ($h < $r['from'] || $h >= $r['to']) { $later(); continue; }
            // someone there who is free: not an admin, not stopped, not already doing a check
            $here = transfer_clocked_in($pdo, (string)$p['branch']);
            foreach (array_keys($here) as $uid) {
                $st = $pdo->prepare("SELECT (SELECT role FROM users WHERE id = ?) = 'admin' OR EXISTS (SELECT 1 FROM user_locks WHERE user_id = ? AND unlocked_at IS NULL)
                                         OR EXISTS (SELECT 1 FROM stock_check_users s JOIN stock_checks c ON c.id = s.check_id WHERE s.user_id = ? AND c.status IN ('active', 'expired'))");
                $st->execute([$uid, $uid, $uid]);
                if ((int)$st->fetchColumn()) unset($here[$uid]);
            }
            $cars = $pdo->prepare("SELECT COUNT(*) FROM cars WHERE branch = ? AND status IN ('available', 'reserved')");
            $cars->execute([(string)$p['branch']]);
            if (!$here || ($p['kind'] !== 'ping' && !(int)$cars->fetchColumn())) { $later(); continue; }
            $uid = array_rand($here);
            $pdo->prepare("UPDATE stock_check_plans SET status = 'pending', user_id = ?, asked_at = NOW() WHERE id = ? AND status = 'planned'")->execute([$uid, $id]);
            $admins = array_map('strval', $pdo->query("SELECT username FROM users WHERE active = 1 AND role = 'admin'")->fetchAll(PDO::FETCH_COLUMN));
            if ($admins) notify_event($pdo, 'check_plan', ['kind' => $p['kind'], 'branch' => $p['branch'], 'user' => $here[$uid], 'only' => $admins,
                'actor' => '', 'tag' => 'check-plan-' . $id, 'force' => true, 'now' => true]);
            $log[] = 'check_plan ' . $p['kind'] . ' ' . $p['branch'];
        }
    } catch (Throwable $e) { error_log('check_auto_scan: ' . $e->getMessage()); }
    return $log;
}

/** Proposals waiting for an admin. */
function check_auto_pending(PDO $pdo): array
{
    try {
        check_auto_tables($pdo);
        return $pdo->query("SELECT p.*, u.username, TIMESTAMPDIFF(MINUTE, p.asked_at, NOW()) AS mins FROM stock_check_plans p LEFT JOIN users u ON u.id = p.user_id
                            WHERE p.status = 'pending' ORDER BY p.asked_at")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** One random car of a branch, as a spot check for one person. Returns the check id (0 = no car there). */
function check_spot_create(PDO $pdo, string $branch, int $uid, int $minutes, string $by): int
{
    check_auto_tables($pdo);
    // one of the four cars nobody has physically confirmed for the longest time (never confirmed first), at random
    $car = 0;
    try {
        car_seen_cols($pdo);
        $st = $pdo->prepare("SELECT id FROM cars WHERE branch = ? AND status IN ('available', 'reserved') ORDER BY (last_seen_at IS NULL) DESC, last_seen_at ASC, RAND() LIMIT 4");
        $st->execute([$branch]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) $car = (int)$ids[array_rand($ids)];
    } catch (Throwable $e) {}
    if (!$car) {
        $st = $pdo->prepare("SELECT id FROM cars WHERE branch = ? AND status IN ('available', 'reserved') ORDER BY RAND() LIMIT 1");
        $st->execute([$branch]);
        $car = (int)$st->fetchColumn();
    }
    if (!$car) return 0;
    return smart_check_create($pdo, $branch, [$uid], max(3, min(60, $minutes)), '', true, $by, [], [$car], true);
}

/** The admin's answer to a proposal: send it now, or skip it. Returns the new check id (0 = skipped or failed). */
function check_auto_decide(PDO $pdo, int $planId, bool $send, string $by, int $minutes = 0): int
{
    check_auto_tables($pdo);
    $st = $pdo->prepare("SELECT * FROM stock_check_plans WHERE id = ? AND status = 'pending'");
    $st->execute([$planId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) return 0;
    $cid = 0;
    if ($send && $p['kind'] === 'ping') {
        $ok = loc_ping_create($pdo, (int)$p['user_id'], $by);
        $pdo->prepare("UPDATE stock_check_plans SET status = ?, decided_by = ?, decided_at = NOW() WHERE id = ?")->execute([$ok ? 'sent' : 'skipped', $by, $planId]);
        return 0;
    }
    if ($send) {
        $cid = $p['kind'] === 'spot' ? check_spot_create($pdo, (string)$p['branch'], (int)$p['user_id'], $minutes ?: 5, $by)
                                     : smart_check_create($pdo, (string)$p['branch'], [(int)$p['user_id']], $minutes ?: 30, '', true, $by, []);
    }
    $pdo->prepare("UPDATE stock_check_plans SET status = ?, decided_by = ?, decided_at = NOW(), check_id = ? WHERE id = ?")->execute([$cid ? 'sent' : 'skipped', $by, $cid ?: null, $planId]);
    return $cid;
}

/* ════════════════════════ branch trust score (admins) ════════════════════════ */
/**
 * A score out of 100 per branch from the last 30 days of what the system already records.
 * Returns [branch => ['score' => int, 'why' => [[points lost, count, key]]]].
 */
function branch_trust(PDO $pdo): array
{
    $out = [];
    try {
        foreach ($pdo->query("SELECT name FROM branches ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $b) $out[(string)$b] = ['score' => 100, 'why' => []];
        $fence = 50;
        try { $fs = json_decode((string)$pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'attendance_settings'")->fetchColumn(), true); if (isset($fs['fence'])) $fence = max(10, (int)$fs['fence']); } catch (Throwable $e) {}
        // key => [points each, most it can cost, SQL giving branch + n]
        $rules = [
            'late'    => [8, 32, "SELECT m.to_branch AS b, COUNT(DISTINCT d.movement_id) AS n FROM transfer_duty d JOIN movements m ON m.id = d.movement_id WHERE d.locked = 1 AND d.deadline >= NOW() - INTERVAL 30 DAY GROUP BY m.to_branch"],
            'wrong'   => [5, 30, "SELECT place AS b, COUNT(*) AS n FROM chassis_tries WHERE ok = 0 AND created_at >= NOW() - INTERVAL 30 DAY AND place <> '' GROUP BY place"],
            'stopped' => [6, 24, "SELECT place AS b, COUNT(DISTINCT lock_id) AS n FROM chassis_tries WHERE lock_id IS NOT NULL AND created_at >= NOW() - INTERVAL 30 DAY AND place <> '' GROUP BY place"],
            'missing' => [10, 40, "SELECT c.branch AS b, COUNT(*) AS n FROM stock_check_items i JOIN stock_checks c ON c.id = i.check_id WHERE i.state = 'missing' AND c.status = 'done' AND c.created_at >= NOW() - INTERVAL 30 DAY GROUP BY c.branch"],
            'overdue' => [10, 30, "SELECT branch AS b, COUNT(*) AS n FROM stock_checks WHERE created_at >= NOW() - INTERVAL 30 DAY AND (status = 'expired' OR (status = 'done' AND finished_at > deadline)) GROUP BY branch"],
            'far'     => [2, 16, "SELECT branch_name AS b, COUNT(*) AS n FROM attendance_logs WHERE clock_in >= NOW() - INTERVAL 30 DAY AND in_dist_m > " . (int)$fence . " GROUP BY branch_name"],
        ];
        foreach ($rules as $key => [$each, $cap, $sql]) {
            try {
                foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $b = (string)$r['b']; $n = (int)$r['n'];
                    if (!isset($out[$b]) || $n < 1) continue;
                    $lost = min($cap, $n * $each);
                    $out[$b]['score'] -= $lost;
                    $out[$b]['why'][] = [$lost, $n, $key];
                }
            } catch (Throwable $e) {}   // a table that does not exist yet costs nothing
        }
        foreach ($out as &$o) $o['score'] = max(0, (int)$o['score']);
        unset($o);
    } catch (Throwable $e) { error_log('branch_trust: ' . $e->getMessage()); }
    return $out;
}

function branch_trust_label(string $key, int $n, string $lang): string
{
    $ar = $lang === 'ar';
    $t = [
        'late'    => $ar ? 'تأكيد استلام بعد انتهاء المهلة' : 'receipts confirmed late',
        'wrong'   => $ar ? 'إدخال غير صحيح (شاسيه أو عدد)' : 'wrong entries (chassis or count)',
        'stopped' => $ar ? 'إيقاف بسبب إدخال غير صحيح' : 'stops for wrong entries',
        'missing' => $ar ? 'سيارة غير موجودة في الجرد' : 'cars missing in a check',
        'overdue' => $ar ? 'جرد لم يكتمل في وقته' : 'checks not finished in time',
        'far'     => $ar ? 'بصمة من مكان بعيد عن الفرع' : 'clock-ins far from the branch',
    ];
    return $n . ' × ' . ($t[$key] ?? $key);
}

/* ════════════════════════ 📍 location pings (تأكيد التواجد) ════════════════════════ */
/*
 * An admin asks where a clocked-in person is right now. If their app is open it
 * answers by itself; otherwise they get a notification and opening the app
 * answers. No answer within the time → recorded as «لم يرد». The result (how far
 * from the branch) goes to the admins and into the admin record.
 */
const LOC_PING_MINUTES = 10;

function loc_ping_tables(PDO $pdo): void
{
    static $done = false; if ($done) return; $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS loc_pings (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        branch      VARCHAR(100) NULL,
        asked_by    VARCHAR(100) NULL,
        asked_at    DATETIME NOT NULL,
        deadline    DATETIME NOT NULL,
        answered_at DATETIME NULL,
        lat         DECIMAL(10,7) NULL,
        lng         DECIMAL(10,7) NULL,
        acc         INT NULL,
        dist_m      INT NULL,
        status      VARCHAR(10) NOT NULL DEFAULT 'pending',
        KEY k_user (user_id, status), KEY k_time (asked_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function loc_fence(PDO $pdo): int
{
    try { $fs = json_decode((string)$pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'attendance_settings'")->fetchColumn(), true); return max(10, (int)($fs['fence'] ?? 50)); }
    catch (Throwable $e) { return 50; }
}

/** Ask one clocked-in person. Returns the ping id (0 = not clocked in, an admin, or already being asked). */
function loc_ping_create(PDO $pdo, int $uid, string $by): int
{
    loc_ping_tables($pdo);
    $u = $pdo->prepare("SELECT username, role, TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS idle FROM users WHERE id = ? AND active = 1");
    $u->execute([$uid]);
    $u = $u->fetch(PDO::FETCH_ASSOC);
    $att = lock_open_attendance($pdo, $uid);
    if (!$u || $u['role'] === 'admin' || !$att) return 0;
    $p = $pdo->prepare("SELECT 1 FROM loc_pings WHERE user_id = ? AND status = 'pending'");
    $p->execute([$uid]);
    if ($p->fetchColumn()) return 0;
    $pdo->prepare("INSERT INTO loc_pings (user_id, branch, asked_by, asked_at, deadline) VALUES (?, ?, ?, NOW(), NOW() + INTERVAL " . LOC_PING_MINUTES . " MINUTE)")
        ->execute([$uid, (string)$att['branch_name'], $by !== '' ? $by : null]);
    $id = (int)$pdo->lastInsertId();
    // the app answers by itself when it is open; a notification only when it does not look open
    if ($u['idle'] === null || (int)$u['idle'] > 90) {
        notify_event($pdo, 'loc_ping', ['only' => [(string)$u['username']], 'mins' => LOC_PING_MINUTES, 'actor' => '', 'tag' => 'loc-ping-' . $id, 'force' => true, 'now' => true]);
    }
    return $id;
}

/** The ping this person has to answer now (or null). */
function loc_ping_due(PDO $pdo, int $uid): ?array
{
    try {
        loc_ping_tables($pdo);
        $st = $pdo->prepare("SELECT id FROM loc_pings WHERE user_id = ? AND status = 'pending' AND deadline > NOW() ORDER BY id LIMIT 1");
        $st->execute([$uid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

function loc_ping_tell(PDO $pdo, int $pingId): void
{
    $st = $pdo->prepare("SELECT p.*, u.username FROM loc_pings p JOIN users u ON u.id = p.user_id WHERE p.id = ?");
    $st->execute([$pingId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) return;
    $admins = array_map('strval', $pdo->query("SELECT username FROM users WHERE active = 1 AND role = 'admin'")->fetchAll(PDO::FETCH_COLUMN));
    if ($admins) notify_event($pdo, 'loc_result', ['user' => $p['username'], 'branch' => $p['branch'], 'status' => $p['status'], 'dist' => $p['dist_m'], 'acc' => $p['acc'],
        'only' => $admins, 'actor' => '', 'tag' => 'loc-res-' . $pingId, 'force' => true, 'now' => true]);
}

/** The person's phone answers. $lat / $lng null = the phone refused to give the location. */
function loc_ping_answer(PDO $pdo, int $uid, int $pingId, ?float $lat, ?float $lng, int $acc): bool
{
    loc_ping_tables($pdo);
    $st = $pdo->prepare("SELECT * FROM loc_pings WHERE id = ? AND user_id = ? AND status = 'pending'");
    $st->execute([$pingId, $uid]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) return false;
    $status = 'denied'; $dist = null;
    if ($lat !== null && $lng !== null) {
        $b = $pdo->prepare("SELECT lat, lng FROM branches WHERE name = ?");
        $b->execute([(string)$p['branch']]);
        $b = $b->fetch(PDO::FETCH_ASSOC);
        $status = 'ok';
        if ($b && $b['lat'] !== null && $b['lng'] !== null) {
            $R = 6371000; $dLat = deg2rad((float)$b['lat'] - $lat); $dLng = deg2rad((float)$b['lng'] - $lng);
            $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad((float)$b['lat'])) * sin($dLng / 2) ** 2;
            $dist = (int)round($R * 2 * atan2(sqrt($a), sqrt(1 - $a)));
            if ($dist > loc_fence($pdo) + min(150, max(0, $acc))) $status = 'far';   // the phone's own inaccuracy is not held against them
        }
    }
    $pdo->prepare("UPDATE loc_pings SET status = ?, answered_at = NOW(), lat = ?, lng = ?, acc = ?, dist_m = ? WHERE id = ? AND status = 'pending'")
        ->execute([$status, $lat, $lng, $acc ?: null, $dist, $pingId]);
    loc_ping_tell($pdo, $pingId);
    return true;
}

/** Pings nobody answered in time → «لم يرد». Called by the timed run and by the pages that show pings. */
function loc_ping_scan(PDO $pdo): array
{
    $log = [];
    try {
        loc_ping_tables($pdo);
        foreach ($pdo->query("SELECT id FROM loc_pings WHERE status = 'pending' AND deadline <= NOW()")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $up = $pdo->prepare("UPDATE loc_pings SET status = 'missed' WHERE id = ? AND status = 'pending'");
            $up->execute([(int)$id]);
            if ($up->rowCount()) { loc_ping_tell($pdo, (int)$id); $log[] = 'loc_ping missed #' . $id; }
        }
    } catch (Throwable $e) { error_log('loc_ping_scan: ' . $e->getMessage()); }
    return $log;
}

/* ════════════════════════ 🗓️ the weekly report card (Saturday morning, admins) ════════════════════════ */
function weekly_cards(PDO $pdo): array
{
    $log = [];
    try {
        $now = (int)strtotime((string)$pdo->query("SELECT NOW()")->fetchColumn());
        if ((int)date('w', $now) !== 6 || (int)date('G', $now) < 9) return $log;       // Saturday, from 9 in the morning
        $week = date('o-W', $now);
        if (push_setting($pdo, 'weekly_card_week', '') === $week) return $log;
        push_setting_set($pdo, 'weekly_card_week', $week, 'system');
        $admins = array_map('strval', $pdo->query("SELECT username FROM users WHERE active = 1 AND role = 'admin'")->fetchAll(PDO::FETCH_COLUMN));
        if (!$admins) return $log;
        $trust = branch_trust($pdo);
        $prev  = json_decode(push_setting($pdo, 'weekly_card_trust', ''), true) ?: [];
        $one = function (string $sql, array $a) use ($pdo) { try { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); } catch (Throwable $e) { return 0; } };
        foreach (check_auto_branches($pdo) as $b) {
            $secs  = (int)$one("SELECT COALESCE(SUM(CASE WHEN auto_closed = 1 THEN 0 ELSE TIMESTAMPDIFF(SECOND, clock_in, COALESCE(clock_out, NOW())) END), 0) FROM attendance_logs WHERE branch_name = ? AND clock_in >= NOW() - INTERVAL 7 DAY", [$b]);
            $late  = (int)$one("SELECT COUNT(DISTINCT d.movement_id) FROM transfer_duty d JOIN movements m ON m.id = d.movement_id WHERE d.locked = 1 AND m.to_branch = ? AND d.deadline >= NOW() - INTERVAL 7 DAY", [$b]);
            $wrong = (int)$one("SELECT COUNT(*) FROM chassis_tries WHERE ok = 0 AND place = ? AND created_at >= NOW() - INTERVAL 7 DAY", [$b]);
            $ppl = [];
            try {
                $st = $pdo->prepare("SELECT u.username, COUNT(DISTINCT DATE(a.clock_in)) AS d FROM attendance_logs a JOIN users u ON u.id = a.user_id
                                     WHERE a.branch_name = ? AND a.clock_in >= NOW() - INTERVAL 7 DAY AND u.active = 1 AND u.role <> 'admin' GROUP BY u.id ORDER BY d DESC, u.username");
                $st->execute([$b]);
                $ppl = $st->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {}
            $score = (int)($trust[$b]['score'] ?? 100);
            notify_event($pdo, 'weekly_card', ['branch' => $b, 'score' => $score, 'delta' => isset($prev[$b]) ? $score - (int)$prev[$b] : null, 'hours' => (int)round($secs / 3600),
                'late' => $late, 'wrong' => $wrong, 'best' => $ppl ? $ppl[0]['username'] . ' (' . (int)$ppl[0]['d'] . ')' : '',
                'least' => count($ppl) > 1 ? end($ppl)['username'] . ' (' . (int)end($ppl)['d'] . ')' : '',
                'only' => $admins, 'actor' => '', 'tag' => 'weekly-' . $week . '-' . $b, 'force' => true, 'now' => true]);
            $log[] = 'weekly_card ' . $b;
        }
        push_setting_set($pdo, 'weekly_card_trust', json_encode(array_map(fn($t) => (int)$t['score'], $trust)), 'system');
    } catch (Throwable $e) { error_log('weekly_cards: ' . $e->getMessage()); }
    return $log;
}
