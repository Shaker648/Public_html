<?php
/*
 * notify_smart.php — the "smart" notifications: automatic reminders and the
 * extra alerts that follow a sale, a transfer, a price change, a bank reply,
 * a wrong password or a sensitive edit.
 *
 *   smart_run($pdo)        all the timed reminders (called by notify_cron.php
 *                          every 15 minutes, and — as a backup — at most every
 *                          5 minutes while somebody has the system open)
 *   smart_after_sale(...)  sale celebration + "last car of this model"
 *   smart_transfer(...)    "a car is on its way to your branch" (+ «استلمت»)
 *   smart_receive(...)     a branch confirms it received transferred cars
 *   smart_price(...)       price changed on a reserved car / price cut
 *   smart_bank(...)        bank approved / rejected → the salesperson
 *   smart_login_failed(...)  many wrong passwords → admin
 *   smart_car_edit(...)    chassis / sold-car edits → admin
 *
 * Every function is safe: it never throws, so it can never break a sale or a
 * transfer. Every reminder is sent once (notify_once).
 */
require_once __DIR__ . '/push_helpers.php';

/* ════════════════════════ helpers ════════════════════════ */
function smart_line(array $c): string
{
    return trim(($c['brand'] ?? '') . ' ' . ($c['model'] ?? '') . ' ' . ($c['trim_name'] ?? ''));
}

/** Reminders only go out in working hours, so nobody is woken up by them. */
function smart_daytime(): bool
{
    $h = (int)date('G');
    return $h >= 10 && $h < 21;
}

/* ════════════════════════ after a sale ════════════════════════ */
function smart_after_sale(PDO $pdo, array $car, string $event): void
{
    try {
        // 🎉 the whole team celebrates — without the seller's name
        if (in_array($event, ['car_sold', 'amana_closed'], true)) {
            notify_event($pdo, 'sale_celebrate', ['car' => $car, 'actor' => '']);
        }
        // ⚠️ was that the last one?
        $st = $pdo->prepare("SELECT COUNT(*) AS m, COALESCE(SUM(LOWER(color) = LOWER(?)), 0) AS c FROM cars
                             WHERE brand = ? AND model = ? AND status IN ('available', 'reserved') AND id <> ?");
        $st->execute([(string)($car['color'] ?? ''), (string)($car['brand'] ?? ''), (string)($car['model'] ?? ''), (int)($car['id'] ?? 0)]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $m = (int)$r['m']; $c = (int)$r['c'];
        if ($m === 0)      notify_event($pdo, 'last_car', ['car' => $car, 'kind' => 'model']);
        elseif ($c === 0)  notify_event($pdo, 'last_car', ['car' => $car, 'kind' => 'color', 'left' => $m]);
    } catch (Throwable $e) { error_log('smart_after_sale: ' . $e->getMessage()); }
}

/* ════════════════════════ transfers ════════════════════════ */
/**
 * $moved = [['id' => carId, 'mid' => movementId, 'brand' …, 'from' => branch], …]
 * The people of the receiving branch get a notification with a «استلمت» button.
 */
function smart_transfer(PDO $pdo, array $moved, string $to): void
{
    try {
        if (!$moved) return;
        push_tables($pdo);
        if (!transfer_needs_confirm($pdo, $to)) return;   // storage: nobody there to press «استلمت»
        $staff = notify_branch_staff($pdo, $to);
        $mids  = array_values(array_filter(array_map(fn($c) => (int)($c['mid'] ?? 0), $moved)));
        $froms = array_values(array_unique(array_column($moved, 'from')));
        $data  = ['owners' => $staff, 'to' => $to, 'from' => count($froms) === 1 ? $froms[0] : '', 'ref' => 'mv:' . implode(',', $mids)];
        if (count($moved) === 1) $data['car'] = $moved[0] + ['branch' => $to];
        else { $data['count'] = count($moved); $data['names'] = array_map(fn($c) => trim($c['brand'] . ' ' . $c['model']) . ' (' . $c['chassis'] . ')', $moved); }
        notify_event($pdo, 'transfer_incoming', $data);
        smart_duty_scan($pdo);   // whoever is already clocked in there: their time starts now
    } catch (Throwable $e) { error_log('smart_transfer: ' . $e->getMessage()); }
}

/** Transfers still waiting for the receiving branch. $branch = '' → all branches. */
function smart_pending_transfers(PDO $pdo, string $branch = '', int $days = 7): array
{
    push_tables($pdo);
    $since = push_setting($pdo, 'smart_since', '');
    $sql = "SELECT m.id AS mid, m.car_id, m.from_branch, m.to_branch, m.moved_by, m.created_at,
                   TIMESTAMPDIFF(HOUR, m.created_at, NOW()) AS hours,
                   c.brand, c.model, c.trim_name, c.car_year, c.color, c.chassis
            FROM movements m
            JOIN cars c ON c.id = m.car_id
            LEFT JOIN transfer_receipts r ON r.movement_id = m.id
            WHERE m.event_type = 'transfer' AND r.movement_id IS NULL
              AND m.created_at >= NOW() - INTERVAL " . (int)$days . " DAY
              AND c.branch = m.to_branch AND c.status IN ('available', 'reserved')
              AND m.id = (SELECT MAX(m2.id) FROM movements m2 WHERE m2.car_id = m.car_id AND m2.event_type = 'transfer')";
    $args = [];
    if ($since !== '') { $sql .= " AND m.created_at >= ?"; $args[] = $since; }
    if ($branch !== '') { $sql .= " AND m.to_branch = ?"; $args[] = $branch; }
    $st = $pdo->prepare($sql . " ORDER BY m.id DESC");
    $st->execute($args);
    // only places that confirm (a storage has nobody to press «استلمت»)
    return array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), fn($r) => transfer_needs_confirm($pdo, (string)$r['to_branch'])));
}

/** A branch confirms it received cars. Returns how many were newly confirmed. */
function smart_receive(PDO $pdo, array $mids, string $by): int
{
    try {
        push_tables($pdo);
        $mids = array_values(array_unique(array_filter(array_map('intval', $mids))));
        if (!$mids) return 0;
        $in = implode(',', $mids);
        $rows = $pdo->query("SELECT m.id AS mid, m.car_id, m.to_branch, m.moved_by, c.id, c.brand, c.model, c.trim_name, c.car_year, c.color, c.chassis
                             FROM movements m JOIN cars c ON c.id = m.car_id WHERE m.event_type = 'transfer' AND m.id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
        $ins = $pdo->prepare("INSERT IGNORE INTO transfer_receipts (movement_id, received_by, received_at) VALUES (?, ?, NOW())");
        $new = [];
        foreach ($rows as $r) { $ins->execute([(int)$r['mid'], $by]); if ($ins->rowCount()) $new[] = $r; }
        if (!$new) return 0;
        // tell whoever moved them (grouped by the branch they reached)
        $byTo = [];
        foreach ($new as $r) $byTo[$r['to_branch']][] = $r;
        foreach ($byTo as $to => $cars) {
            $data = ['actor' => $by, 'to' => $to, 'owners' => array_values(array_unique(array_column($cars, 'moved_by')))];
            if (count($cars) === 1) $data['car'] = $cars[0] + ['branch' => $to];
            else { $data['count'] = count($cars); $data['names'] = array_map(fn($c) => trim($c['brand'] . ' ' . $c['model']) . ' (' . $c['chassis'] . ')', $cars); }
            notify_event($pdo, 'transfer_received', $data);
        }
        smart_duty_scan($pdo);   // done / auto-unlock
        return count($new);
    } catch (Throwable $e) { error_log('smart_receive: ' . $e->getMessage()); return 0; }
}

/* ════════════════════════ «استلمت» on time — or the system locks ════════════════════════ */
/** Is this person's system locked right now? Returns the lock row or null. */
function user_lock_active(PDO $pdo, int $uid): ?array
{
    try {
        $st = $pdo->prepare("SELECT * FROM user_locks WHERE user_id = ? AND unlocked_at IS NULL ORDER BY id DESC LIMIT 1");
        $st->execute([$uid]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

/** Cars this person still has to confirm (their countdown is running or over). */
function smart_my_duties(PDO $pdo, int $uid): array
{
    $st = $pdo->prepare("SELECT d.movement_id AS mid, d.deadline, TIMESTAMPDIFF(SECOND, NOW(), d.deadline) AS secs, m.to_branch, m.from_branch, m.moved_by,
                                c.id AS car_id, c.brand, c.model, c.trim_name, c.car_year, c.color, c.chassis
                         FROM transfer_duty d JOIN movements m ON m.id = d.movement_id JOIN cars c ON c.id = m.car_id
                         LEFT JOIN transfer_receipts r ON r.movement_id = d.movement_id
                         WHERE d.user_id = ? AND r.movement_id IS NULL
                           AND c.branch = m.to_branch AND c.status IN ('available', 'reserved')
                         ORDER BY d.deadline");
    $st->execute([$uid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The receiving clock. Safe to call often (cron, clock-in, transfer, «استلمت»):
 *  1. whoever has to confirm and is at the branch gets a countdown (starts once)
 *  2. N minutes before the end: the person and the admin are warned
 *  3. time is up: the person's system locks (never an admin) — both are told
 *  4. everything confirmed: the admin is told (or it unlocks by itself if set)
 */
function smart_duty_scan(PDO $pdo): array
{
    $log = [];
    try {
        push_tables($pdo);
        $rules = transfer_rules($pdo);
        $lang  = notify_options($pdo)['lang'];
        $fmt   = fn($ts) => date('h:i', $ts) . ($lang === 'ar' ? (date('A', $ts) === 'AM' ? ' ص' : ' م') : ' ' . date('A', $ts));
        $names = fn($rows) => array_map(fn($c) => trim($c['brand'] . ' ' . $c['model']) . ' (' . $c['chassis'] . ')', $rows);
        $roles = [];
        foreach ($pdo->query("SELECT id, username, role FROM users") as $u) $roles[(int)$u['id']] = [$u['username'], $u['role']];

        /* 1 — start the clocks */
        $pend = smart_pending_transfers($pdo, '', 7);
        $byBranch = [];
        foreach ($pend as $p) $byBranch[$p['to_branch']][] = $p;
        $ins = $pdo->prepare("INSERT IGNORE INTO transfer_duty (movement_id, user_id, started_at, deadline) VALUES (?, ?, NOW(), NOW() + INTERVAL ? MINUTE)");
        $started = [];
        foreach ($byBranch as $branch => $rows) {
            foreach (transfer_responsible($pdo, $branch) as $uid => $uname) {
                foreach ($rows as $r) {
                    $ins->execute([(int)$r['mid'], $uid, (int)round($rules['hours'] * 60)]);
                    if ($ins->rowCount()) $started[$uid][$branch][] = $r;
                }
            }
        }
        foreach ($started as $uid => $br) foreach ($br as $branch => $rows) {
            notify_event($pdo, 'duty_start', ['user' => $roles[$uid][0] ?? '', 'owners' => [$roles[$uid][0] ?? ''], 'branch' => $branch, 'count' => count($rows),
                'names' => $names($rows), 'until' => $fmt(time() + (int)round($rules['hours'] * 3600)), 'lock' => $rules['lock'], 'actor' => '', 'force' => true, 'now' => true]);
            $log[] = 'duty_start ' . ($roles[$uid][0] ?? $uid) . ' ×' . count($rows);
        }

        $open = "FROM transfer_duty d JOIN movements m ON m.id = d.movement_id JOIN cars c ON c.id = m.car_id
                 LEFT JOIN transfer_receipts r ON r.movement_id = d.movement_id
                 WHERE r.movement_id IS NULL AND c.branch = m.to_branch AND c.status IN ('available', 'reserved')";
        $cols = "d.movement_id, d.user_id, UNIX_TIMESTAMP(d.deadline) AS dl, m.to_branch, c.brand, c.model, c.chassis";

        /* 2 — warnings */
        $st = $pdo->prepare("SELECT $cols $open AND d.warned = 0 AND d.deadline > NOW() AND d.deadline <= NOW() + INTERVAL ? MINUTE");
        $st->execute([$rules['warn']]);
        $warn = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $warn[(int)$r['user_id']][] = $r;
        $mark = $pdo->prepare("UPDATE transfer_duty SET warned = 1 WHERE movement_id = ? AND user_id = ?");
        foreach ($warn as $uid => $rows) {
            foreach ($rows as $r) $mark->execute([(int)$r['movement_id'], $uid]);
            if (!$rules['lock']) continue;                                   // nothing will lock — no need to warn
            $dl = min(array_map(fn($r) => (int)$r['dl'], $rows));
            notify_event($pdo, 'duty_warn', ['user' => $roles[$uid][0] ?? '', 'owners' => [$roles[$uid][0] ?? ''], 'branch' => $rows[0]['to_branch'],
                'count' => count($rows), 'mins' => max(1, (int)ceil(($dl - time()) / 60)), 'until' => $fmt($dl), 'actor' => '', 'force' => true, 'now' => true]);
            $log[] = 'duty_warn ' . ($roles[$uid][0] ?? $uid);
        }

        /* 3 — time is up */
        if ($rules['lock']) {
            $late = [];
            foreach ($pdo->query("SELECT $cols $open AND d.locked = 0 AND d.deadline <= NOW()")->fetchAll(PDO::FETCH_ASSOC) as $r) $late[(int)$r['user_id']][] = $r;
            $mark = $pdo->prepare("UPDATE transfer_duty SET locked = 1 WHERE movement_id = ? AND user_id = ?");
            foreach ($late as $uid => $rows) {
                foreach ($rows as $r) $mark->execute([(int)$r['movement_id'], $uid]);
                if (($roles[$uid][1] ?? '') === 'admin') continue;           // an admin is never locked out
                if (!user_lock_active($pdo, $uid)) {
                    $pdo->prepare("INSERT INTO user_locks (user_id, reason, locked_at) VALUES (?, ?, NOW())")
                        ->execute([$uid, mb_substr(implode(', ', $names($rows)), 0, 250)]);
                    notify_event($pdo, 'duty_locked', ['user' => $roles[$uid][0] ?? '', 'owners' => [$roles[$uid][0] ?? ''], 'branch' => $rows[0]['to_branch'],
                        'count' => count($rows), 'actor' => '', 'force' => true, 'now' => true]);
                    $log[] = 'duty_locked ' . ($roles[$uid][0] ?? $uid);
                }
            }
        }

        /* 4 — a locked person confirmed every car and finished every stock check */
        foreach ($pdo->query("SELECT * FROM user_locks WHERE unlocked_at IS NULL AND done_at IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $lk) {
            $uid = (int)$lk['user_id'];
            if (smart_my_duties($pdo, $uid) || smart_checks_for_user($pdo, $uid)) continue;
            $pdo->prepare("UPDATE user_locks SET done_at = NOW() WHERE id = ?")->execute([(int)$lk['id']]);
            if ($rules['auto_unlock']) { smart_unlock($pdo, $uid, 'system'); $log[] = 'auto_unlock ' . ($roles[$uid][0] ?? $uid); }
            else {
                notify_event($pdo, 'duty_done', ['user' => $roles[$uid][0] ?? '', 'actor' => '', 'force' => true, 'now' => true]);
                $log[] = 'duty_done ' . ($roles[$uid][0] ?? $uid);
            }
        }
    } catch (Throwable $e) { error_log('smart_duty_scan: ' . $e->getMessage()); }
    return $log;
}

/** The admin opens the system for someone: anything still unconfirmed gets a fresh full period. */
function smart_unlock(PDO $pdo, int $uid, string $by): void
{
    push_tables($pdo);
    $rules = transfer_rules($pdo);
    $pdo->prepare("UPDATE user_locks SET unlocked_at = NOW(), unlocked_by = ? WHERE user_id = ? AND unlocked_at IS NULL")->execute([$by, $uid]);
    $left = smart_my_duties($pdo, $uid);
    if ($left) {
        $pdo->prepare("UPDATE transfer_duty SET deadline = NOW() + INTERVAL ? MINUTE, warned = 0, locked = 0 WHERE user_id = ?")
            ->execute([(int)round($rules['hours'] * 60), $uid]);
    }
    $st = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $st->execute([$uid]);
    $lang = notify_options($pdo)['lang'];
    $ts = time() + (int)round($rules['hours'] * 3600);
    notify_event($pdo, 'duty_unlocked', ['owners' => [(string)$st->fetchColumn()], 'by' => $by === 'system' ? ($lang === 'ar' ? 'النظام' : 'the system') : $by,
        'left' => count($left), 'until' => date('h:i', $ts) . ($lang === 'ar' ? (date('A', $ts) === 'AM' ? ' ص' : ' م') : ' ' . date('A', $ts)),
        'actor' => $by === 'system' ? '' : $by, 'force' => true, 'now' => true]);
}

/** More time for someone (minutes added to everything they still have to confirm). */
function smart_extend(PDO $pdo, int $uid, int $minutes): void
{
    push_tables($pdo);
    $pdo->prepare("UPDATE transfer_duty d LEFT JOIN transfer_receipts r ON r.movement_id = d.movement_id
                   SET d.deadline = GREATEST(d.deadline, NOW()) + INTERVAL ? MINUTE, d.warned = 0, d.locked = 0
                   WHERE d.user_id = ? AND r.movement_id IS NULL")->execute([max(5, min(1440, $minutes)), $uid]);
}

/* ════════════════════════ surprise stock check (جرد مفاجئ) ════════════════════════ */
function smart_time_label(int $ts, string $lang): string
{
    return date('h:i', $ts) . ($lang === 'ar' ? (date('A', $ts) === 'AM' ? ' ص' : ' م') : ' ' . date('A', $ts));
}

/** Names of the people a check is assigned to: [userId => username]. */
function smart_check_users(PDO $pdo, int $checkId): array
{
    $st = $pdo->prepare("SELECT u.id, u.username FROM stock_check_users s JOIN users u ON u.id = s.user_id WHERE s.check_id = ?");
    $st->execute([$checkId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = (string)$r['username'];
    return $out;
}

/** Counts for a check: [total, marked, present, missing, extra]. */
function smart_check_counts(PDO $pdo, int $checkId): array
{
    $st = $pdo->prepare("SELECT SUM(car_id > 0) AS t, SUM(car_id > 0 AND state IS NOT NULL) AS m, SUM(state = 'present') AS p,
                                SUM(state = 'missing') AS x, SUM(state = 'extra') AS e FROM stock_check_items WHERE check_id = ?");
    $st->execute([$checkId]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return [(int)($r['t'] ?? 0), (int)($r['m'] ?? 0), (int)($r['p'] ?? 0), (int)($r['x'] ?? 0), (int)($r['e'] ?? 0)];
}

/**
 * Start a surprise check: the cars in the branch right now are the list;
 * the people chosen get it at once (phone + in the system) with the deadline.
 */
function smart_check_create(PDO $pdo, string $branch, array $userIds, int $minutes, string $note, bool $lock, string $by): int
{
    push_tables($pdo);
    $minutes = max(5, min(1440, $minutes));
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    $st = $pdo->prepare("SELECT id, brand, model, trim_name, car_year, color, chassis FROM cars WHERE branch = ? AND status IN ('available', 'reserved') ORDER BY brand, model, chassis");
    $st->execute([$branch]);
    $cars = $st->fetchAll(PDO::FETCH_ASSOC);
    $pdo->prepare("INSERT INTO stock_checks (branch, note, minutes, lock_on, created_by, created_at, deadline) VALUES (?, ?, ?, ?, ?, NOW(), NOW() + INTERVAL ? MINUTE)")
        ->execute([$branch, mb_substr($note, 0, 250), $minutes, $lock ? 1 : 0, $by, $minutes]);
    $id = (int)$pdo->lastInsertId();
    $iu = $pdo->prepare("INSERT IGNORE INTO stock_check_users (check_id, user_id) VALUES (?, ?)");
    foreach ($userIds as $u) $iu->execute([$id, $u]);
    $lang = notify_options($pdo)['lang'];
    $ii = $pdo->prepare("INSERT INTO stock_check_items (check_id, car_id, label, chassis) VALUES (?, ?, ?, ?)");
    foreach ($cars as $c) {
        $ii->execute([$id, (int)$c['id'], mb_substr(trim($c['brand'] . ' ' . $c['model'] . ' ' . $c['trim_name'] . ' ' . $c['car_year'] . ' · ' . push_color_label($pdo, (string)$c['color'], $lang)), 0, 200), $c['chassis']]);
    }
    $names = smart_check_users($pdo, $id);
    notify_event($pdo, 'check_start', ['id' => $id, 'branch' => $branch, 'count' => count($cars), 'until' => smart_time_label(time() + $minutes * 60, $lang),
        'users' => implode('، ', $names), 'note' => $note, 'lock' => $lock, 'owners' => array_values($names), 'actor' => $by, 'force' => true, 'now' => true]);
    return $id;
}

/** Checks this person still has to finish (running, or out of time but not finished). */
function smart_checks_for_user(PDO $pdo, int $uid): array
{
    try {
        $st = $pdo->prepare("SELECT c.*, TIMESTAMPDIFF(SECOND, NOW(), c.deadline) AS secs FROM stock_checks c JOIN stock_check_users u ON u.check_id = c.id
                             WHERE u.user_id = ? AND c.status IN ('active', 'expired') ORDER BY c.deadline");
        $st->execute([$uid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** Finish a check (every car marked). Tells the admin the result. */
function smart_check_finish(PDO $pdo, int $checkId, string $by): bool
{
    $st = $pdo->prepare("SELECT * FROM stock_checks WHERE id = ? AND status IN ('active', 'expired')");
    $st->execute([$checkId]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) return false;
    [$t, $m, $p, $x, $e] = smart_check_counts($pdo, $checkId);
    if ($m < $t) return false;
    $pdo->prepare("UPDATE stock_checks SET status = 'done', finished_at = NOW(), finished_by = ? WHERE id = ?")->execute([$by, $checkId]);
    $ml = $pdo->prepare("SELECT label, chassis FROM stock_check_items WHERE check_id = ? AND state = 'missing'");
    $ml->execute([$checkId]);
    notify_event($pdo, 'check_done', ['id' => $checkId, 'branch' => $c['branch'], 'present' => $p, 'missing' => $x, 'extra' => $e, 'by' => $by,
        'late' => $c['status'] === 'expired', 'missing_list' => array_map(fn($r) => $r['chassis'], $ml->fetchAll(PDO::FETCH_ASSOC)), 'actor' => $by, 'force' => true, 'now' => true]);
    smart_duty_scan($pdo);   // someone locked only because of this check → the admin is told
    return true;
}

/** Warn before the end; when time is up the people who did not finish are locked. */
function smart_check_scan(PDO $pdo): array
{
    $log = [];
    try {
        push_tables($pdo);
        $lang = notify_options($pdo)['lang'];
        $warnMax = transfer_rules($pdo)['warn'];
        $roles = [];
        foreach ($pdo->query("SELECT id, role FROM users") as $u) $roles[(int)$u['id']] = $u['role'];
        foreach ($pdo->query("SELECT *, UNIX_TIMESTAMP(deadline) AS dl, TIMESTAMPDIFF(SECOND, NOW(), deadline) AS secs FROM stock_checks WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $id = (int)$c['id'];
            $names = smart_check_users($pdo, $id);
            [$t, $m] = smart_check_counts($pdo, $id);
            $warnAt = min($warnMax, max(5, intdiv((int)$c['minutes'], 3))) * 60;
            if (!(int)$c['warned'] && (int)$c['secs'] > 0 && (int)$c['secs'] <= $warnAt) {
                $pdo->prepare("UPDATE stock_checks SET warned = 1 WHERE id = ?")->execute([$id]);
                notify_event($pdo, 'check_warn', ['id' => $id, 'branch' => $c['branch'], 'count' => $t, 'done' => $m, 'mins' => max(1, (int)ceil((int)$c['secs'] / 60)),
                    'users' => implode('، ', $names), 'owners' => array_values($names), 'actor' => '', 'force' => true, 'now' => true]);
                $log[] = 'check_warn #' . $id;
            }
            if ((int)$c['secs'] <= 0) {
                $pdo->prepare("UPDATE stock_checks SET status = 'expired' WHERE id = ? AND status = 'active'")->execute([$id]);
                if ((int)$c['lock_on']) {
                    $locked = [];
                    foreach ($names as $uid => $un) {
                        if (($roles[$uid] ?? '') === 'admin' || user_lock_active($pdo, $uid)) continue;   // never an admin
                        $pdo->prepare("INSERT INTO user_locks (user_id, reason, locked_at) VALUES (?, ?, NOW())")
                            ->execute([$uid, mb_substr(($lang === 'ar' ? 'جرد مفاجئ لم يكتمل — ' : 'Unfinished stock check — ') . push_branch_label($pdo, (string)$c['branch'], $lang), 0, 250)]);
                        $locked[] = $un;
                    }
                    if ($locked) notify_event($pdo, 'check_locked', ['id' => $id, 'branch' => $c['branch'], 'count' => $t, 'done' => $m,
                        'users' => implode('، ', $locked), 'owners' => $locked, 'actor' => '', 'force' => true, 'now' => true]);
                }
                $log[] = 'check_expired #' . $id;
            }
        }
    } catch (Throwable $e) { error_log('smart_check_scan: ' . $e->getMessage()); }
    return $log;
}

/* ════════════════════════ prices ════════════════════════ */
function smart_price(PDO $pdo, string $brand, string $model, string $trim, string $year, array $old, array $new): void
{
    try {
        $offChanged  = ($old['official'] ?? '') !== '' && ($old['official'] ?? '') !== ($new['official'] ?? '');
        $custChanged = ($old['customer'] ?? '') !== '' && ($old['customer'] ?? '') !== ($new['customer'] ?? '');
        if (!$offChanged && !$custChanged) return;

        // the people who reserved a car of this model / trim / year
        $st = $pdo->prepare("SELECT c.id, (SELECT m.moved_by FROM movements m WHERE m.car_id = c.id AND m.event_type = 'reserved' ORDER BY m.id DESC LIMIT 1) AS who
                             FROM cars c WHERE c.status = 'reserved' AND c.brand = ? AND c.model = ? AND c.trim_name = ? AND c.car_year = ?");
        $st->execute([$brand, $model, $trim, $year]);
        $owners = array_values(array_unique(array_filter(array_column($st->fetchAll(PDO::FETCH_ASSOC), 'who'))));
        if ($owners) {
            notify_event($pdo, 'price_reserved', ['brand' => $brand, 'model' => $model, 'trim' => $trim, 'year' => $year,
                'old' => $offChanged ? $old['official'] : '', 'new' => $offChanged ? $new['official'] : '', 'owners' => $owners]);
        }
        // 🚨 official price went down
        if ($offChanged && (float)$new['official'] > 0 && (float)$new['official'] < (float)$old['official']) {
            $lang = notify_options($pdo)['lang'];
            $pct = round(((float)$old['official'] - (float)$new['official']) * 100 / (float)$old['official'], 1);
            notify_event($pdo, 'sensitive_change', ['brand' => $brand, 'model' => trim($model . ' ' . $trim . ' ' . $year),
                'lines' => [($lang === 'ar' ? '📉 تخفيض السعر الرسمي: ' : '📉 Official price cut: ') . number_format((float)$old['official']) . ($lang === 'ar' ? ' ← ' : ' → ') . number_format((float)$new['official']) . ' (−' . $pct . '%)'],
                'url' => 'price_history.php?lang=' . $lang]);
        }
    } catch (Throwable $e) { error_log('smart_price: ' . $e->getMessage()); }
}

/* ════════════════════════ bank decisions ════════════════════════ */
function smart_bank(PDO $pdo, int $lineId, string $decision, string $note): void
{
    try {
        $st = $pdo->prepare("SELECT b.bank_key, r.customer_name, r.brand, r.model, r.trim_name, r.car_year, r.created_by
                             FROM installment_bank_requests b JOIN installment_requests r ON r.id = b.request_id WHERE b.id = ?");
        $st->execute([$lineId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return;
        $lang = notify_options($pdo)['lang'];
        if (!function_exists('inst_bank_name') && is_file(__DIR__ . '/installment_helpers.php')) require_once __DIR__ . '/installment_helpers.php';
        $bank = function_exists('inst_bank_name') ? inst_bank_name((string)$r['bank_key'], $lang) : (string)$r['bank_key'];
        notify_event($pdo, 'bank_decision', ['decision' => $decision, 'note' => $note, 'bank' => $bank, 'customer' => $r['customer_name'],
            'car' => trim($r['brand'] . ' ' . $r['model'] . ' ' . $r['trim_name'] . ' ' . $r['car_year']), 'owners' => [(string)$r['created_by']]]);
    } catch (Throwable $e) { error_log('smart_bank: ' . $e->getMessage()); }
}

/* ════════════════════════ security ════════════════════════ */
function smart_login_failed(PDO $pdo, string $username, string $ip): void
{
    try {
        $username = mb_strtolower(trim($username));
        if ($username === '') return;
        $st = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = ? LIMIT 1");
        $st->execute([$username]);
        if (!$st->fetchColumn()) return;                  // only real accounts
        push_tables($pdo);
        $need = notify_smart($pdo)['fail_count'];
        $st = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE username = ? AND success = 0 AND attempted_at >= NOW() - INTERVAL 60 MINUTE
                               AND attempted_at > COALESCE((SELECT MAX(attempted_at) FROM login_attempts s WHERE s.username = ? AND s.success = 1), '1970-01-01')");
        $st->execute([$username, $username]);
        $n = (int)$st->fetchColumn();
        if ($n < $need || !notify_once($pdo, 'lf:' . $username . ':' . date('YmdH'))) return;
        notify_event($pdo, 'login_failed', ['username' => $username, 'count' => $n, 'ip' => $ip, 'actor' => '', 'force' => true]);
    } catch (Throwable $e) { error_log('smart_login_failed: ' . $e->getMessage()); }
}

/** $car = the car before the edit, $changes = [field => [old, new]] */
function smart_car_edit(PDO $pdo, array $car, array $changes): void
{
    try {
        $lang = notify_options($pdo)['lang'];
        $ar = $lang === 'ar';
        $lbl = $ar ? ['chassis' => 'الشاسيه', 'brand' => 'الماركة', 'model' => 'الموديل', 'car_year' => 'السنة', 'trim_name' => 'الفئة', 'color' => 'اللون', 'branch' => 'الفرع', 'notes' => 'ملاحظات']
                   : ['chassis' => 'Chassis', 'brand' => 'Brand', 'model' => 'Model', 'car_year' => 'Year', 'trim_name' => 'Trim', 'color' => 'Colour', 'branch' => 'Branch', 'notes' => 'Notes'];
        $sold = ($car['status'] ?? '') === 'sold';
        $keys = $sold ? array_keys($changes) : array_values(array_intersect(array_keys($changes), ['chassis', 'brand', 'model', 'car_year']));
        if (!$keys) return;
        $lines = [];
        if ($sold) $lines[] = $ar ? '⚠️ تعديل على سيارة مباعة' : '⚠️ Edit on a sold car';
        foreach (array_slice($keys, 0, 4) as $k) {
            $lines[] = ($lbl[$k] ?? $k) . ': ' . mb_substr((string)($changes[$k][0] ?: '—'), 0, 30) . ($ar ? ' ← ' : ' → ') . mb_substr((string)($changes[$k][1] ?: '—'), 0, 30);
        }
        notify_event($pdo, 'sensitive_change', ['car' => $car, 'lines' => $lines, 'url' => 'vehicle_timeline.php?id=' . (int)$car['id'] . '&lang=' . $lang]);
    } catch (Throwable $e) { error_log('smart_car_edit: ' . $e->getMessage()); }
}

/* ════════════════════════ the timed reminders ════════════════════════ */
function smart_run(PDO $pdo): array
{
    $done = [];
    try {
        push_tables($pdo);
        if (push_setting($pdo, 'smart_since', '') === '') {   // start counting from the day this was installed
            push_setting_set($pdo, 'smart_since', date('Y-m-d H:i:s'), 'system');
        }
        $s = notify_smart($pdo);
        $day = smart_daytime();

        /* 📋 surprise stock checks: warnings, time up → lock */
        $done = array_merge($done, smart_check_scan($pdo));
        /* 🚚 receiving transferred cars: countdowns, warnings, locks (any time of day) */
        $done = array_merge($done, smart_duty_scan($pdo));

        /* ⏰ old reservations: after N days, then every N days — to the one who reserved it */
        if ($day) {
            $rows = $pdo->query("SELECT c.*, m.id AS mid, m.moved_by, DATEDIFF(NOW(), m.created_at) AS days
                                 FROM cars c JOIN movements m ON m.id = (SELECT MAX(x.id) FROM movements x WHERE x.car_id = c.id AND x.event_type = 'reserved')
                                 WHERE c.status = 'reserved'")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $d = (int)$r['days'];
                if ($d < $s['reserve_days']) continue;
                if (!notify_once($pdo, 'res:' . $r['mid'] . ':' . intdiv($d, $s['reserve_days']))) continue;
                notify_event($pdo, 'reserve_old', ['car' => $r, 'days' => $d, 'owner' => $r['moved_by'], 'owners' => [(string)$r['moved_by']], 'actor' => '', 'now' => true]);
                $done[] = 'reserve_old #' . $r['id'];
            }
        }

        /* 🐢 weekly: cars in stock for too long */
        if ($day && (int)date('w') === $s['aged_dow'] && notify_once($pdo, 'aged:' . date('oW'))) {
            $st = $pdo->prepare("SELECT brand, model, color, DATEDIFF(NOW(), created_at) AS days FROM cars
                                 WHERE status IN ('available', 'reserved') AND created_at IS NOT NULL AND created_at < NOW() - INTERVAL ? DAY ORDER BY created_at");
            $st->execute([$s['aged_days']]);
            $old = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($old) {
                $lang = notify_options($pdo)['lang'];
                notify_event($pdo, 'stock_aged', ['count' => count($old), 'days' => $s['aged_days'], 'actor' => '', 'now' => true,
                    'names' => array_map(fn($c) => '• ' . trim($c['brand'] . ' ' . $c['model'] . ' ' . push_color_label($pdo, (string)$c['color'], $lang)) . ' — ' . (int)$c['days'] . ($lang === 'ar' ? ' يوماً' : ' d'), $old)]);
                $done[] = 'stock_aged ' . count($old);
            }
        }

        /* ⏳ transfers nobody confirmed within 24 hours */
        if ($day) {
            $withDuty = array_map('intval', $pdo->query("SELECT DISTINCT movement_id FROM transfer_duty")->fetchAll(PDO::FETCH_COLUMN));
            foreach (smart_pending_transfers($pdo, '', 3) as $t) {
                // somebody was there → the countdown / lock handles it; this is for "nobody was at the branch"
                if ((int)$t['hours'] < 24 || in_array((int)$t['mid'], $withDuty, true) || !notify_once($pdo, 'tru:' . $t['mid'])) continue;
                notify_event($pdo, 'transfer_unconfirmed', ['car' => ['id' => $t['car_id']] + $t, 'to' => $t['to_branch'], 'hours' => (int)$t['hours'],
                    'mover' => $t['moved_by'], 'actor' => '', 'now' => true]);
                $done[] = 'transfer_unconfirmed #' . $t['mid'];
            }
        }

        /* 🔶 consignments out for too long — to whoever handles it */
        if ($day) {
            try {
                $rows = $pdo->query("SELECT k.id AS kid, k.dealer_name, k.salesman, k.started_by, DATEDIFF(NOW(), k.started_at) AS days, c.*
                                     FROM consignments k JOIN cars c ON c.id = k.car_id WHERE k.status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $d = (int)$r['days'];
                    if ($d < $s['amana_days'] || !notify_once($pdo, 'amana:' . $r['kid'] . ':' . intdiv($d, $s['amana_days']))) continue;
                    notify_event($pdo, 'amana_long', ['car' => $r, 'days' => $d, 'dealer' => $r['dealer_name'], 'salesman' => $r['salesman'],
                        'owners' => array_values(array_filter([(string)$r['salesman'], (string)$r['started_by']])), 'actor' => '', 'now' => true]);
                    $done[] = 'amana_long #' . $r['kid'];
                }
            } catch (Throwable $e) {}   // no consignments table yet
        }

        /* ⌛ bank requests with no reply */
        if ($day) {
            try {
                $st = $pdo->prepare("SELECT b.id, b.bank_key, DATEDIFF(NOW(), b.created_at) AS days, r.customer_name, r.model
                                     FROM installment_bank_requests b JOIN installment_requests r ON r.id = b.request_id
                                     WHERE b.status = 'pending' AND b.created_at < NOW() - INTERVAL ? DAY");
                $st->execute([$s['bank_days']]);
                $lang = notify_options($pdo)['lang'];
                if (!function_exists('inst_bank_name') && is_file(__DIR__ . '/installment_helpers.php')) require_once __DIR__ . '/installment_helpers.php';
                $due = [];
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if (notify_once($pdo, 'bank:' . $r['id'] . ':' . intdiv((int)$r['days'], $s['bank_days']))) {
                        $due[] = '• ' . $r['customer_name'] . ' — ' . (function_exists('inst_bank_name') ? inst_bank_name((string)$r['bank_key'], $lang) : $r['bank_key'])
                               . ' (' . (int)$r['days'] . ($lang === 'ar' ? ' يوماً)' : ' d)');
                    }
                }
                if ($due) {
                    notify_event($pdo, 'bank_waiting', ['count' => count($due), 'days' => $s['bank_days'], 'names' => $due, 'actor' => '', 'now' => true]);
                    $done[] = 'bank_waiting ' . count($due);
                }
            } catch (Throwable $e) {}   // no installments tables yet
        }

        /* 🌙 still clocked in late at night — only to the employee */
        if (date('H:i') >= $s['clockout_at']) {
            try {
                $rows = $pdo->query("SELECT a.id, a.user_id, u.username FROM attendance_logs a JOIN users u ON u.id = a.user_id
                                     WHERE a.clock_out IS NULL AND a.clock_in >= CURDATE() AND u.active = 1")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    if (user_lock_active($pdo, (int)($r['user_id'] ?? 0))) continue;    // locked: cannot clock out anyway
                    if (!notify_once($pdo, 'co:' . $r['username'] . ':' . date('Ymd'))) continue;   // once a night per person
                    notify_event($pdo, 'clockout_forgot', ['owners' => [(string)$r['username']], 'actor' => '', 'force' => true, 'now' => true]);
                    $done[] = 'clockout_forgot ' . $r['username'];
                }
            } catch (Throwable $e) {}   // no attendance table yet
        }

        /* ✉️ scheduled messages that are due */
        $due = $pdo->query("SELECT * FROM notify_scheduled WHERE sent_log_id IS NULL AND cancelled = 0 AND send_at <= NOW() ORDER BY send_at LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($due as $m) {
            $claim = $pdo->prepare("UPDATE notify_scheduled SET sent_log_id = 0 WHERE id = ? AND sent_log_id IS NULL");
            $claim->execute([(int)$m['id']]);
            if (!$claim->rowCount()) continue;               // another run took it
            $r = notify_custom($pdo, (array)json_decode((string)$m['user_ids'], true), (string)$m['title'], (string)$m['body'], (string)$m['created_by'], (bool)$m['need_ack']);
            $pdo->prepare("UPDATE notify_scheduled SET sent_log_id = ? WHERE id = ?")->execute([(int)($r[4] ?? 0), (int)$m['id']]);
            $done[] = 'scheduled #' . $m['id'];
        }

        /* housekeeping */
        $pdo->exec("DELETE FROM activity_log WHERE created_at < NOW() - INTERVAL 30 DAY");
        $pdo->exec("DELETE FROM notify_once WHERE at < NOW() - INTERVAL 180 DAY");
    } catch (Throwable $e) {
        error_log('smart_run: ' . $e->getMessage());
    }
    return $done;
}

/**
 * Backup for the cron job: run the reminders at most every 5 minutes while
 * somebody has the system open (after the page has answered — nobody waits).
 */
function smart_run_maybe(PDO $pdo): void
{
    try {
        push_tables($pdo);
        $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '' && push_setting($pdo, 'site_host', '') !== $host) push_setting_set($pdo, 'site_host', $host, 'system');
        $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value, updated_by, updated_at) VALUES ('smart_last_run', '2000-01-01 00:00:00', 'system', NOW())");
        $st = $pdo->prepare("UPDATE settings SET setting_value = NOW(), updated_at = NOW() WHERE setting_key = 'smart_last_run' AND setting_value < NOW() - INTERVAL 5 MINUTE");
        $st->execute();
        if (!$st->rowCount()) return;
        register_shutdown_function(function () use ($pdo) {
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
            ignore_user_abort(true);
            @set_time_limit(90);
            smart_run($pdo);
        });
    } catch (Throwable $e) { error_log('smart_run_maybe: ' . $e->getMessage()); }
}
