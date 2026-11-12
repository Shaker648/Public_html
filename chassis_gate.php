<?php
/*
 * chassis_gate.php — confirming a car by TYPING its chassis number.
 *
 * Used when a transferred car is received («تم الاستلام») and when a car is
 * marked «موجودة» in a surprise stock check. The person never sees the number
 * on the screen: they read it off the car and type it.
 *
 *   - zeros at the start are skipped: 000123 on the car is typed as 123
 *     (typing the zeros works too)
 *   - right number  → confirmed
 *   - wrong number  → a warning the first time; the second wrong number stops
 *     the person's system (kind «chassis»). What they typed and the real
 *     number are kept in chassis_tries — shown to admins only, on lockdown.php.
 *
 * Needs notify_smart.php (smart_lock_user).
 */

const CHASSIS_MAX_WRONG = 2;

function chassis_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS chassis_tries (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        user_id      INT NOT NULL,
        username     VARCHAR(100) NOT NULL,
        ctx          VARCHAR(20) NOT NULL,
        ref_id       INT NOT NULL DEFAULT 0,
        car_id       INT NOT NULL DEFAULT 0,
        label        VARCHAR(255) NULL,
        place        VARCHAR(100) NULL,
        typed        VARCHAR(100) NOT NULL,
        real_chassis VARCHAR(100) NULL,
        ok           TINYINT NOT NULL DEFAULT 0,
        cleared      TINYINT NOT NULL DEFAULT 0,
        lock_id      INT NULL,
        created_at   DATETIME NOT NULL,
        KEY k_user (user_id, cleared), KEY k_lock (lock_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** A chassis as it is compared: Latin digits, letters in capitals, no spaces or dashes, no zeros at the start. */
function chassis_norm(string $s): string
{
    $s = strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                    '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    $s = strtoupper(preg_replace('/[^0-9A-Za-z]+/', '', $s));
    return ltrim($s, '0');
}

function chassis_match(string $typed, string $real): bool
{
    $t = chassis_norm($typed);
    return $t !== '' && $t === chassis_norm($real);
}

/** How many dots to show instead of the number (never its real length). */
function chassis_mask(): string
{
    return '••••••';
}

/**
 * One typed number.
 *   $ctx        'receive' | 'check'
 *   $cands      [ref id => ['car_id' =>, 'chassis' =>, 'label' =>]] — every car this number may confirm
 *   $rowRef     the row the person typed it on
 * Returns ['ok' => bool, 'ref' => confirmed ref id, 'locked' => bool, 'left' => wrong tries left, 'empty' => bool].
 */
function chassis_try(PDO $pdo, int $uid, string $uname, string $ctx, array $cands, int $rowRef, string $typed, string $place = ''): array
{
    chassis_tables($pdo);
    if (chassis_norm($typed) === '') return ['ok' => false, 'ref' => 0, 'locked' => false, 'left' => CHASSIS_MAX_WRONG, 'empty' => true];
    $hit = 0;
    if (isset($cands[$rowRef]) && chassis_match($typed, (string)$cands[$rowRef]['chassis'])) $hit = $rowRef;
    else foreach ($cands as $ref => $c) if (chassis_match($typed, (string)$c['chassis'])) { $hit = (int)$ref; break; }   // same model twice: the number decides which one

    $row = $cands[$hit ?: $rowRef] ?? ['car_id' => 0, 'chassis' => '', 'label' => ''];
    $pdo->prepare("INSERT INTO chassis_tries (user_id, username, ctx, ref_id, car_id, label, place, typed, real_chassis, ok, cleared, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$uid, $uname, $ctx, $hit ?: $rowRef, (int)$row['car_id'], mb_substr((string)$row['label'], 0, 250), mb_substr($place, 0, 100), mb_substr(trim($typed), 0, 100), (string)$row['chassis'], $hit ? 1 : 0, $hit ? 1 : 0]);
    if ($hit) {
        $pdo->prepare("UPDATE chassis_tries SET cleared = 1 WHERE user_id = ? AND cleared = 0")->execute([$uid]);   // a right number wipes the slate
        return ['ok' => true, 'ref' => $hit, 'locked' => false, 'left' => CHASSIS_MAX_WRONG, 'empty' => false];
    }
    $n = $pdo->prepare("SELECT COUNT(*) FROM chassis_tries WHERE user_id = ? AND ok = 0 AND cleared = 0");
    $n->execute([$uid]);
    $wrong = (int)$n->fetchColumn();
    if ($wrong < CHASSIS_MAX_WRONG) return ['ok' => false, 'ref' => 0, 'locked' => false, 'left' => CHASSIS_MAX_WRONG - $wrong, 'empty' => false];

    // the system stops. The reason everyone may read has no numbers in it — those are for admins (lockdown.php).
    $lang = function_exists('notify_options') ? notify_options($pdo)['lang'] : 'ar';
    $what = $ctx === 'check' ? ($lang === 'ar' ? 'أثناء الجرد المفاجئ' : 'during the stock check') : ($lang === 'ar' ? 'عند تأكيد استلام سيارة منقولة' : 'when receiving a transferred car');
    $why  = $lang === 'ar' ? 'إدخال رقم شاسيه غير صحيح مرتين ' . $what . ((string)$row['label'] !== '' ? ' (' . $row['label'] . ')' : '')
                           : 'Wrong chassis number typed twice ' . $what . ((string)$row['label'] !== '' ? ' (' . $row['label'] . ')' : '');
    $lockId = smart_lock_user($pdo, $uid, 'chassis', $why, '');
    $pdo->prepare("UPDATE chassis_tries SET cleared = 1, lock_id = ? WHERE user_id = ? AND ok = 0 AND cleared = 0")->execute([$lockId ?: null, $uid]);
    return ['ok' => false, 'ref' => 0, 'locked' => $lockId > 0, 'left' => 0, 'empty' => false];
}

/** Admin only: what was typed against the real number, for a stop (or the latest tries of everyone). */
function chassis_tries_for_lock(PDO $pdo, int $lockId): array
{
    try {
        chassis_tables($pdo);
        $st = $pdo->prepare("SELECT * FROM chassis_tries WHERE lock_id = ? ORDER BY id");
        $st->execute([$lockId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** Is this person stopped because of wrong chassis numbers? (then nothing can be confirmed until the admin opens the system) */
function chassis_locked(PDO $pdo, int $uid): bool
{
    try {
        $st = $pdo->prepare("SELECT 1 FROM user_locks WHERE user_id = ? AND unlocked_at IS NULL AND kind = 'chassis' LIMIT 1");
        $st->execute([$uid]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/**
 * Cars whose chassis this person must not see yet: cars on their way to a
 * branch and not confirmed, and the cars of a stock check they are doing.
 * Admins see everything.
 */
function chassis_hidden_ids(PDO $pdo, int $uid, string $role): array
{
    static $memo = [];
    if ($role === 'admin') return [];
    if (isset($memo[$uid])) return $memo[$uid];
    $ids = [];
    try {
        if (function_exists('smart_pending_transfers')) foreach (smart_pending_transfers($pdo, '', 7) as $p) $ids[(int)$p['car_id']] = true;
    } catch (Throwable $e) {}
    try {
        $st = $pdo->prepare("SELECT i.car_id FROM stock_check_items i JOIN stock_checks c ON c.id = i.check_id JOIN stock_check_users u ON u.check_id = c.id
                             WHERE u.user_id = ? AND c.status IN ('active', 'expired') AND i.car_id > 0 AND (i.state IS NULL OR i.state <> 'present')");
        $st->execute([$uid]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[(int)$id] = true;
    } catch (Throwable $e) {}
    return $memo[$uid] = $ids;
}

/** Replace the chassis of every row this person must not see yet with dots (rows keyed by car id in $idKey). */
function chassis_mask_rows(PDO $pdo, array &$rows, string $idKey = 'id'): void
{
    try {
        $role = (string)($_SESSION['role'] ?? '');
        if ($role === 'admin' || !$rows) return;
        if (!function_exists('smart_pending_transfers') && is_file(__DIR__ . '/notify_smart.php')) require_once __DIR__ . '/notify_smart.php';
        $hide = chassis_hidden_ids($pdo, (int)($_SESSION['user_id'] ?? 0), $role);
        if (!$hide) return;
        foreach ($rows as &$r) if (isset($r[$idKey], $r['chassis']) && isset($hide[(int)$r[$idKey]])) $r['chassis'] = chassis_mask();
        unset($r);
    } catch (Throwable $e) { error_log('chassis_mask_rows: ' . $e->getMessage()); }
}

/* ════════ the stock check starts with a question: how many cars are at the branch? ════════ */
/** The two columns that keep each person's answer (added on first use). */
function check_count_cols(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try { $pdo->query("SELECT counted FROM stock_check_users LIMIT 1"); }
    catch (Throwable $e) { try { $pdo->exec("ALTER TABLE stock_check_users ADD counted INT NULL, ADD counted_at DATETIME NULL"); } catch (Throwable $e2) {} }
}

/** This person's answer for a check: the number they counted, or null if they have not answered yet. */
function check_counted(PDO $pdo, int $checkId, int $uid): ?int
{
    try {
        check_count_cols($pdo);
        $st = $pdo->prepare("SELECT counted FROM stock_check_users WHERE check_id = ? AND user_id = ?");
        $st->execute([$checkId, $uid]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null) ? null : (int)$v;
    } catch (Throwable $e) { return null; }
}

/** Has anyone doing this check not answered yet? (then the number of cars must not be sent to them) */
function check_count_pending(PDO $pdo, int $checkId): bool
{
    try {
        check_count_cols($pdo);
        $st = $pdo->prepare("SELECT 1 FROM stock_check_users s JOIN users u ON u.id = s.user_id WHERE s.check_id = ? AND s.counted IS NULL AND u.role <> 'admin' LIMIT 1");
        $st->execute([$checkId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}
