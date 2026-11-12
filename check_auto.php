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
                foreach (['full' => $r['full'], 'spot' => $r['spot']] as $kind => $n) {
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
            if (!$here || !(int)$cars->fetchColumn()) { $later(); continue; }
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
    $st = $pdo->prepare("SELECT id FROM cars WHERE branch = ? AND status IN ('available', 'reserved') ORDER BY RAND() LIMIT 1");
    $st->execute([$branch]);
    $car = (int)$st->fetchColumn();
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
