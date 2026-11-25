<?php
/*
 * stock_check_extras.php — more for the surprise stock check (الجرد المفاجئ).
 *
 *   📍 location    every car confirmed carries the phone's position. Far from the branch → the car
 *                  is not accepted and it counts as a wrong answer (two in a row stop the system).
 *                  A vague reading (indoors) is kept but never held against anyone.
 *   ⚡ speed       CHECK_FAST_PER_MIN cars or more confirmed within one minute → the admins are told
 *                  once («مشبوه»). The same for cars confirmed without any location.
 *   🔢 questions   besides «how many cars are at the branch», count questions the system suggests
 *                  and the admin chooses: «كم سيارة لونها أبيض؟» «كم سيارة Toyota؟» — checked the same way.
 *   📷 photos      only when the admin asks for it, and of what the admin chooses: the chassis plate, the
 *                  car itself, or both (photo_req bits: 1 chassis · 2 car). A few cars picked at random,
 *                  unannounced (the person learns it only after confirming that car); a spot check: its
 *                  one car. Taken with the camera inside the page — never from the gallery.
 *   🔎 cases       every car marked «غير موجودة» opens a case the admins close (found / moved / sold /
 *                  lost); the next day the system proposes a re-check of that car by someone else.
 *   👥 pairs       a double check: each person does the same check alone; the admins see every difference.
 *   ⏳ more time   the person doing it asks for more minutes; an admin approves or refuses — on the
 *                  page or straight from the phone notification.
 *
 * Loaded by notify_smart.php.
 */
require_once __DIR__ . '/color_helpers.php';

const CHECK_FAST_PER_MIN = 6;    // this many cars confirmed within 60 seconds is not physically possible
const CHECK_NOLOC_ALERT  = 3;    // cars confirmed without a location before the admins are told
const CHECK_NEAR_MIN_M   = 150;  // cars stand around the branch (its yard): this close always counts as «at the branch»
const CHECK_WEAK_ACC_M   = 300;  // a reading vaguer than this proves nothing either way
const CHECK_TIME_MAX_ASK = 3;    // requests for more time per person per check

function check_extra_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (function_exists('check_pick_cols')) check_pick_cols($pdo);
    if (function_exists('check_auto_tables')) check_auto_tables($pdo);
    $add = function (string $table, string $probe, string $alter) use ($pdo) {
        try { $pdo->query("SELECT $probe FROM $table LIMIT 1"); }
        catch (Throwable $e) { try { $pdo->exec("ALTER TABLE $table $alter"); } catch (Throwable $e2) { error_log('check_extra_tables: ' . $e2->getMessage()); } }
    };
    $add('stock_checks', 'group_id', 'ADD group_id INT NULL');
    $add('stock_check_items', 'photo', 'ADD photo VARCHAR(80) NULL, ADD photo_at DATETIME NULL');
    $add('stock_check_items', 'car_photo', 'ADD car_photo VARCHAR(80) NULL, ADD car_photo_at DATETIME NULL');   // 🚗 the car itself (photo = the chassis plate)
    $add('stock_check_items', 'photo_req', 'ADD photo_req TINYINT NOT NULL DEFAULT 0, ADD lat DECIMAL(10,7) NULL, ADD lng DECIMAL(10,7) NULL, ADD acc INT NULL, ADD dist_m INT NULL, ADD loc_flag VARCHAR(10) NULL');
    $add('stock_check_users', 'fast_max', 'ADD fast_max INT NOT NULL DEFAULT 0, ADD fast_told TINYINT NOT NULL DEFAULT 0, ADD noloc_told TINYINT NOT NULL DEFAULT 0');
    $add('stock_check_plans', 'car_id', 'ADD car_id INT NULL, ADD case_id INT NULL, ADD avoid_user INT NULL');
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_check_q (
        id INT AUTO_INCREMENT PRIMARY KEY,
        check_id INT NOT NULL,
        kind VARCHAR(10) NOT NULL,
        qkey VARCHAR(100) NOT NULL,
        label_ar VARCHAR(160) NOT NULL,
        label_en VARCHAR(160) NOT NULL,
        tag_ar VARCHAR(80) NULL,
        tag_en VARCHAR(80) NULL,
        expected INT NOT NULL,
        sort INT NOT NULL DEFAULT 0,
        KEY k_check (check_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_check_qa (
        q_id INT NOT NULL,
        user_id INT NOT NULL,
        answer INT NOT NULL,
        ok TINYINT NOT NULL DEFAULT 0,
        answered_at DATETIME NOT NULL,
        PRIMARY KEY (q_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS check_time_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        check_id INT NOT NULL,
        user_id INT NOT NULL,
        minutes INT NOT NULL,
        reason VARCHAR(250) NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'pending',
        asked_at DATETIME NOT NULL,
        decided_by VARCHAR(100) NULL,
        decided_at DATETIME NULL,
        KEY k_check (check_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS check_cases (
        id INT AUTO_INCREMENT PRIMARY KEY,
        check_id INT NOT NULL,
        item_id INT NOT NULL,
        car_id INT NOT NULL,
        branch VARCHAR(100) NOT NULL,
        label VARCHAR(200) NOT NULL,
        chassis VARCHAR(100) NULL,
        note VARCHAR(255) NULL,
        marked_by VARCHAR(100) NULL,
        opened_at DATETIME NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'open',
        decided_by VARCHAR(100) NULL,
        decided_at DATETIME NULL,
        decision_note VARCHAR(255) NULL,
        recheck_id INT NULL,
        recheck_state VARCHAR(10) NULL,
        UNIQUE KEY u_item (item_id), KEY k_status (status), KEY k_car (car_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Usernames of the active admins (who get the check's alerts). */
function check_admin_names(PDO $pdo): array
{
    return array_map('strval', $pdo->query("SELECT username FROM users WHERE active = 1 AND role = 'admin'")->fetchAll(PDO::FETCH_COLUMN));
}

/** A distance as people say it: 85 م · 1.4 كم */
function check_m(int $m, string $lang): string
{
    return $m < 1000 ? $m . ($lang === 'ar' ? ' م' : ' m') : round($m / 1000, 1) . ($lang === 'ar' ? ' كم' : ' km');
}

/* ════════════════════════ 📍 where each car was confirmed from ════════════════════════ */
/** Has this branch a place on the map? Only then is the location of each car checked. */
function check_loc_needed(PDO $pdo, string $branch): bool
{
    static $memo = [];
    if (array_key_exists($branch, $memo)) return $memo[$branch];
    try {
        $st = $pdo->prepare("SELECT lat IS NOT NULL AND lng IS NOT NULL FROM branches WHERE name = ?");
        $st->execute([$branch]);
        return $memo[$branch] = (bool)$st->fetchColumn();
    } catch (Throwable $e) { return $memo[$branch] = false; }
}

/**
 * Where a car was confirmed from, against the branch. «At the branch» is at least CHECK_NEAR_MIN_M
 * (or twice the clock-in fence), plus the phone's own inaccuracy up to 150 m. A reading vaguer than
 * CHECK_WEAK_ACC_M counts as far only when even its whole error cannot bring it near.
 * Returns ['flag' => 'ok'|'far'|'weak'|'none'|'nomap', 'dist' => ?int, 'allowed' => ?int, 'acc' => ?int]
 */
function check_loc_judge(PDO $pdo, string $branch, ?float $lat, ?float $lng, ?int $acc): array
{
    $out = ['flag' => 'none', 'dist' => null, 'allowed' => null, 'acc' => $acc];
    if (!check_loc_needed($pdo, $branch)) return ['flag' => 'nomap'] + $out;
    if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) return $out;
    $st = $pdo->prepare("SELECT lat, lng FROM branches WHERE name = ?");
    $st->execute([$branch]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    $R = 6371000; $dLat = deg2rad((float)$b['lat'] - $lat); $dLng = deg2rad((float)$b['lng'] - $lng);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad((float)$b['lat'])) * sin($dLng / 2) ** 2;
    $dist = (int)round($R * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    $acc = max(0, (int)$acc);
    $near = max(CHECK_NEAR_MIN_M, (function_exists('loc_fence') ? loc_fence($pdo) : 50) * 2);
    $allowed = $near + min(150, $acc);
    if ($acc > CHECK_WEAK_ACC_M) return ['flag' => $dist - $acc > $near + 150 ? 'far' : 'weak', 'dist' => $dist, 'allowed' => $allowed, 'acc' => $acc];
    return ['flag' => $dist > $allowed ? 'far' : 'ok', 'dist' => $dist, 'allowed' => $allowed, 'acc' => $acc];
}

/**
 * A car confirmed from far away: not accepted, the admins are told, and it counts as a wrong answer —
 * the second wrong answer in a row (far, chassis, colour or count) stops the system.
 * Returns ['ok' => false, 'locked' => bool, 'left' => wrong answers left].
 */
function check_far_try(PDO $pdo, int $uid, string $uname, int $checkId, int $itemId, int $carId, string $label, string $branch, int $dist, int $allowed): array
{
    chassis_tables($pdo);
    $pdo->prepare("INSERT INTO chassis_tries (user_id, username, ctx, ref_id, car_id, label, place, typed, real_chassis, ok, cleared, created_at) VALUES (?, ?, 'far', ?, ?, ?, ?, ?, ?, 0, 0, NOW())")
        ->execute([$uid, $uname, $itemId, $carId, mb_substr($label, 0, 250), mb_substr($branch, 0, 100), (string)$dist, (string)$allowed]);
    check_suspect_tell($pdo, $checkId, $uname, $branch, 'far', ['dist' => $dist, 'label' => $label]);
    $n = $pdo->prepare("SELECT COUNT(*) AS n, SUM(ctx = 'far') AS f FROM chassis_tries WHERE user_id = ? AND ok = 0 AND cleared = 0");
    $n->execute([$uid]);
    $w = $n->fetch(PDO::FETCH_ASSOC) ?: ['n' => 1, 'f' => 1];
    if ((int)$w['n'] < CHASSIS_MAX_WRONG) return ['ok' => false, 'locked' => false, 'left' => CHASSIS_MAX_WRONG - (int)$w['n']];
    $lang = notify_options($pdo)['lang'];
    $where = $label . ' — ' . check_m($dist, $lang);
    $why = $lang === 'ar'
        ? ((int)$w['f'] >= (int)$w['n'] ? 'تأكيد سيارات من مكان بعيد عن الفرع مرتين أثناء الجرد المفاجئ' : 'إدخال غير صحيح مرتين أثناء الجرد المفاجئ — آخرها تأكيد سيارة من مكان بعيد عن الفرع') . ' (' . $where . ')'
        : ((int)$w['f'] >= (int)$w['n'] ? 'Cars confirmed twice from far away during the stock check' : 'Two wrong answers during the stock check — the last one a car confirmed from far away') . ' (' . $where . ')';
    $lockId = smart_lock_user($pdo, $uid, 'chassis', $why, '');
    $pdo->prepare("UPDATE chassis_tries SET cleared = 1, lock_id = ? WHERE user_id = ? AND ok = 0 AND cleared = 0")->execute([$lockId ?: null, $uid]);
    return ['ok' => false, 'locked' => $lockId > 0, 'left' => 0];
}

/* ════════════════════════ ⚡ too fast · 📵 no location ════════════════════════ */
/** The admins are told about something odd in a check (once for each kind, person and check — far: every time). */
function check_suspect_tell(PDO $pdo, int $checkId, string $uname, string $branch, string $kind, array $d = []): void
{
    try {
        $admins = check_admin_names($pdo);
        if ($admins) notify_event($pdo, 'check_suspect', $d + ['id' => $checkId, 'kind' => $kind, 'user' => $uname, 'branch' => $branch, 'only' => $admins,
            'actor' => '', 'tag' => 'chk-sus-' . $checkId . '-' . $kind . '-' . $uname . ($kind === 'far' ? '-' . time() : ''), 'force' => true, 'now' => true]);
    } catch (Throwable $e) { error_log('check_suspect_tell: ' . $e->getMessage()); }
}

/** After someone (not an admin) confirms a car: too many in one minute, or too many without a location → the admins are told once. */
function check_after_confirm(PDO $pdo, int $checkId, int $uid, string $uname, string $branch, string $flag): void
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM stock_check_items WHERE check_id = ? AND marked_by = ? AND state = 'present' AND marked_at >= NOW() - INTERVAL 60 SECOND");
        $st->execute([$checkId, $uname]);
        $n = (int)$st->fetchColumn();
        $pdo->prepare("UPDATE stock_check_users SET fast_max = GREATEST(fast_max, ?) WHERE check_id = ? AND user_id = ?")->execute([$n, $checkId, $uid]);
        if ($n >= CHECK_FAST_PER_MIN) {
            $up = $pdo->prepare("UPDATE stock_check_users SET fast_told = 1 WHERE check_id = ? AND user_id = ? AND fast_told = 0");
            $up->execute([$checkId, $uid]);
            if ($up->rowCount()) check_suspect_tell($pdo, $checkId, $uname, $branch, 'fast', ['n' => $n]);
        }
        if ($flag === 'none') {
            $st = $pdo->prepare("SELECT COUNT(*) FROM stock_check_items WHERE check_id = ? AND marked_by = ? AND state = 'present' AND loc_flag = 'none'");
            $st->execute([$checkId, $uname]);
            $k = (int)$st->fetchColumn();
            if ($k >= CHECK_NOLOC_ALERT) {
                $up = $pdo->prepare("UPDATE stock_check_users SET noloc_told = 1 WHERE check_id = ? AND user_id = ? AND noloc_told = 0");
                $up->execute([$checkId, $uid]);
                if ($up->rowCount()) check_suspect_tell($pdo, $checkId, $uname, $branch, 'noloc', ['n' => $k]);
            }
        }
    } catch (Throwable $e) { error_log('check_after_confirm: ' . $e->getMessage()); }
}

/* ════════════════════════ 🔢 count questions by colour or brand ════════════════════════ */
/**
 * The colour family of a car colour, as a person sees it: «Pearl White» and «White» are both white,
 * «Dark Blue» and «Navy» are blue. Unknown colours are their own family. ['key', 'ar', 'en', 'hex']
 */
function check_color_family(PDO $pdo, string $name): array
{
    static $fam = [
        'white'  => ['أبيض', 'White', ['white', 'أبيض', 'ابيض']],
        'black'  => ['أسود', 'Black', ['black', 'أسود', 'اسود']],
        'silver' => ['فضي', 'Silver', ['silver', 'فضي']],
        'grey'   => ['رمادي', 'Grey', ['grey', 'gray', 'رمادي', 'رصاصي']],
        'red'    => ['أحمر', 'Red', ['red', 'maroon', 'burgundy', 'أحمر', 'احمر', 'نبيتي']],
        'blue'   => ['أزرق', 'Blue', ['blue', 'navy', 'أزرق', 'ازرق', 'كحلي', 'لبني']],
        'green'  => ['أخضر', 'Green', ['green', 'أخضر', 'اخضر', 'زيتي']],
        'brown'  => ['بني', 'Brown', ['brown', 'bronze', 'بني', 'برونزي']],
        'beige'  => ['بيج', 'Beige', ['beige', 'champagne', 'بيج', 'شامبين']],
        'gold'   => ['ذهبي', 'Gold', ['gold', 'ذهبي']],
        'orange' => ['برتقالي', 'Orange', ['orange', 'برتقالي']],
        'yellow' => ['أصفر', 'Yellow', ['yellow', 'أصفر', 'اصفر']],
        'purple' => ['بنفسجي', 'Purple', ['purple', 'violet', 'بنفسجي']],
    ];
    $n = mb_strtolower(trim($name));
    $ar = mb_strtolower(push_color_label($pdo, $name, 'ar'));
    foreach ($fam as $k => [$fa, $fe, $words]) {
        foreach ($words as $w) if (($n !== '' && mb_strpos($n, $w) !== false) || ($ar !== '' && mb_strpos($ar, $w) !== false)) return ['key' => $k, 'ar' => $fa, 'en' => $fe, 'hex' => car_color_hex($fe)];
    }
    return ['key' => 'x:' . $n, 'ar' => push_color_label($pdo, $name, 'ar') ?: '—', 'en' => $name !== '' ? $name : '—', 'hex' => car_color_hex($name)];
}

function check_q_text(string $kind, string $what, string $lang): string
{
    if ($kind === 'color') return $lang === 'ar' ? 'كم سيارة لونها ' . $what . ' في الفرع؟' : 'How many ' . mb_strtolower($what) . ' cars are at the branch?';
    return $lang === 'ar' ? 'كم سيارة ' . $what . ' في الفرع؟' : 'How many ' . $what . ' cars are at the branch?';
}

/**
 * Count questions the system suggests for a branch right now, each with the system's answer (for the
 * admin's eyes only): one per colour family and one per brand at the branch, the biggest first.
 * [['kind' => 'color'|'brand', 'key', 'q' => the question, 'tag', 'hex', 'n', 'shades' => [shade => n]], …]
 */
function check_q_suggest(PDO $pdo, string $branch, string $lang): array
{
    $st = $pdo->prepare("SELECT brand, color FROM cars WHERE branch = ? AND status IN ('available', 'reserved')");
    $st->execute([$branch]);
    $col = []; $brd = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $f = check_color_family($pdo, (string)$c['color']);
        $col[$f['key']] ??= $f + ['n' => 0, 'shades' => []];
        $col[$f['key']]['n']++;
        $shade = push_color_label($pdo, (string)$c['color'], $lang);
        $col[$f['key']]['shades'][$shade] = ($col[$f['key']]['shades'][$shade] ?? 0) + 1;
        $b = trim((string)$c['brand']);
        if ($b !== '') { $bk = mb_strtolower($b); $brd[$bk] ??= ['name' => $b, 'n' => 0]; $brd[$bk]['n']++; }
    }
    $out = [];
    foreach ($col as $k => $f) {
        $what = $lang === 'ar' ? $f['ar'] : $f['en'];
        $out[] = ['kind' => 'color', 'key' => (string)$k, 'q' => check_q_text('color', $what, $lang), 'tag' => $what, 'hex' => $f['hex'], 'n' => $f['n'], 'shades' => $f['shades']];
    }
    foreach ($brd as $b) $out[] = ['kind' => 'brand', 'key' => $b['name'], 'q' => check_q_text('brand', $b['name'], $lang), 'tag' => $b['name'], 'hex' => '', 'n' => $b['n'], 'shades' => []];
    usort($out, fn($a, $b) => [$a['kind'] === 'brand', -$a['n'], $a['tag']] <=> [$b['kind'] === 'brand', -$b['n'], $b['tag']]);
    return $out;
}

/** The questions the admin chose for a new check (up to 4), each with the branch's answer right now. Returns how many. */
function check_q_create(PDO $pdo, int $checkId, string $branch, array $wanted): int
{
    if (!$wanted) return 0;
    $idx = function (array $list) { $o = []; foreach ($list as $s) $o[$s['kind'] . '|' . mb_strtolower($s['key'])] = $s; return $o; };
    $ar = $idx(check_q_suggest($pdo, $branch, 'ar'));
    $en = $idx(check_q_suggest($pdo, $branch, 'en'));
    $ins = $pdo->prepare("INSERT INTO stock_check_q (check_id, kind, qkey, label_ar, label_en, tag_ar, tag_en, expected, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $seen = []; $i = 0;
    foreach ($wanted as $w) {
        if ($i >= 4) break;
        $kind = ($w['kind'] ?? '') === 'brand' ? 'brand' : 'color';
        $k = $kind . '|' . mb_strtolower((string)($w['key'] ?? ''));
        if (!isset($ar[$k]) || isset($seen[$k])) continue;
        $seen[$k] = true;
        $ins->execute([$checkId, $kind, mb_substr((string)$ar[$k]['key'], 0, 100), mb_substr($ar[$k]['q'], 0, 160), mb_substr($en[$k]['q'] ?? $ar[$k]['q'], 0, 160),
                       mb_substr((string)$ar[$k]['tag'], 0, 80), mb_substr((string)($en[$k]['tag'] ?? $ar[$k]['tag']), 0, 80), (int)$ar[$k]['n'], $i++]);
    }
    return $i;
}

/** All the questions of a check, in order. */
function check_q_list(PDO $pdo, int $checkId): array
{
    try { $st = $pdo->prepare("SELECT * FROM stock_check_q WHERE check_id = ? ORDER BY sort, id"); $st->execute([$checkId]); return $st->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { return []; }
}

/** The questions this person still has to answer, in order. */
function check_q_pending(PDO $pdo, int $checkId, int $uid): array
{
    try {
        $st = $pdo->prepare("SELECT q.* FROM stock_check_q q LEFT JOIN stock_check_qa a ON a.q_id = q.id AND a.user_id = ? WHERE q.check_id = ? AND a.q_id IS NULL ORDER BY q.sort, q.id");
        $st->execute([$uid, $checkId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** Everyone's answers: [user id => [question id => ['answer' => n, 'ok' => bool]]]. */
function check_q_answers(PDO $pdo, int $checkId): array
{
    $out = [];
    try {
        $st = $pdo->prepare("SELECT a.* FROM stock_check_qa a JOIN stock_check_q q ON q.id = a.q_id WHERE q.check_id = ?");
        $st->execute([$checkId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['user_id']][(int)$r['q_id']] = ['answer' => (int)$r['answer'], 'ok' => (bool)$r['ok']];
    } catch (Throwable $e) {}
    return $out;
}

/** Has this person answered the whole first step (the total and every question)? */
function check_step1_done(PDO $pdo, int $checkId, int $uid): bool
{
    return check_counted($pdo, $checkId, $uid) !== null && !check_q_pending($pdo, $checkId, $uid);
}

/**
 * One answer to a count question, against the branch when the check was sent. Same rule and record as the
 * total: right → saved; wrong → a warning, and the second wrong answer in a row stops the system (the
 * typed number is then kept as their answer, so once the system is opened they go on).
 * Returns ['ok' => bool, 'locked' => bool].
 */
function check_q_try(PDO $pdo, int $checkId, int $qId, int $uid, string $uname, int $typed): array
{
    chassis_tables($pdo);
    $st = $pdo->prepare("SELECT q.*, c.branch FROM stock_check_q q JOIN stock_checks c ON c.id = q.check_id WHERE q.id = ? AND q.check_id = ?");
    $st->execute([$qId, $checkId]);
    $q = $st->fetch(PDO::FETCH_ASSOC);
    if (!$q) return ['ok' => false, 'locked' => false, 'gone' => true];
    $lang = notify_options($pdo)['lang'];
    $label = $lang === 'ar' ? (string)$q['label_ar'] : (string)$q['label_en'];
    $ok = $typed === (int)$q['expected'];
    $pdo->prepare("INSERT INTO chassis_tries (user_id, username, ctx, ref_id, car_id, label, place, typed, real_chassis, ok, cleared, created_at) VALUES (?, ?, 'count', ?, 0, ?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$uid, $uname, $checkId, mb_substr($label, 0, 250), mb_substr((string)$q['branch'], 0, 100), (string)$typed, (string)$q['expected'], $ok ? 1 : 0, $ok ? 1 : 0]);
    $save = $pdo->prepare("INSERT IGNORE INTO stock_check_qa (q_id, user_id, answer, ok, answered_at) VALUES (?, ?, ?, ?, NOW())");
    if ($ok) {
        $save->execute([$qId, $uid, $typed, 1]);
        $pdo->prepare("UPDATE chassis_tries SET cleared = 1 WHERE user_id = ? AND cleared = 0")->execute([$uid]);
        return ['ok' => true, 'locked' => false];
    }
    $n = $pdo->prepare("SELECT COUNT(*) FROM chassis_tries WHERE user_id = ? AND ok = 0 AND cleared = 0");
    $n->execute([$uid]);
    if ((int)$n->fetchColumn() < CHASSIS_MAX_WRONG) return ['ok' => false, 'locked' => false];
    $save->execute([$qId, $uid, $typed, 0]);
    $why = $lang === 'ar' ? 'إدخال عدد غير صحيح مرتين أثناء الجرد المفاجئ (' . $label . ')' : 'Wrong number typed twice during the stock check (' . $label . ')';
    $lockId = smart_lock_user($pdo, $uid, 'chassis', $why, '');
    $pdo->prepare("UPDATE chassis_tries SET cleared = 1, lock_id = ? WHERE user_id = ? AND ok = 0 AND cleared = 0")->execute([$lockId ?: null, $uid]);
    return ['ok' => false, 'locked' => $lockId > 0];
}

/* ════════════════════════ 📷 photos asked at random ════════════════════════ */
const CHECK_PHOTO_CHASSIS = 1;   // photo_req bits: the chassis plate…
const CHECK_PHOTO_CAR     = 2;   // …and/or the car itself

/** What to photograph, as the admin chose it: 1 the chassis plate · 2 the car itself · 3 both · 0 nothing. */
function check_photo_kind($v): int
{
    return max(0, min(3, (int)$v));
}

/**
 * Cars of a check that also need photos — only when the admin asked ($n > 0): $n picked at random,
 * or every car ($all, a spot check). $kind: what to photograph (check_photo_kind).
 */
function check_photo_pick(PDO $pdo, int $checkId, int $n, bool $all = false, int $kind = CHECK_PHOTO_CHASSIS): void
{
    $kind = check_photo_kind($kind);
    if ($n < 1 || !$kind) return;
    if ($all) { $pdo->prepare("UPDATE stock_check_items SET photo_req = ? WHERE check_id = ? AND car_id > 0")->execute([$kind, $checkId]); return; }
    $st = $pdo->prepare("SELECT id FROM stock_check_items WHERE check_id = ? AND car_id > 0");
    $st->execute([$checkId]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    for ($i = count($ids) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]]; }
    $ids = array_slice($ids, 0, $n);
    if ($ids) $pdo->exec("UPDATE stock_check_items SET photo_req = " . $kind . " WHERE id IN (" . implode(',', $ids) . ")");
}

/* ════════════════════════ 🔎 cars not found: a case for the admins + a re-check ════════════════════════ */
/**
 * A check was finished:
 *   - it was a re-check sent for a case → the car found closes the case; not found → the admins are told;
 *   - every car marked «غير موجودة» opens a case (one open case per car), and a re-check of that car
 *     by someone else is planned for the next day (proposed to the admins like the other checks).
 * Returns how many cases were opened.
 */
function check_cases_after_finish(PDO $pdo, int $checkId): int
{
    $opened = 0;
    try {
        check_extra_tables($pdo);
        $lang = notify_options($pdo)['lang'];
        $admins = check_admin_names($pdo);
        $item = $pdo->prepare("SELECT state, marked_by FROM stock_check_items WHERE check_id = ? AND car_id = ? LIMIT 1");
        $cs = $pdo->prepare("SELECT * FROM check_cases WHERE recheck_id = ? AND status = 'open'");
        $cs->execute([$checkId]);
        foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $case) {
            $item->execute([$checkId, (int)$case['car_id']]);
            $it = $item->fetch(PDO::FETCH_ASSOC) ?: ['state' => null, 'marked_by' => ''];
            if ($it['state'] === 'present') {
                $pdo->prepare("UPDATE check_cases SET status = 'found', decided_by = 'system', decided_at = NOW(), decision_note = ?, recheck_state = 'found' WHERE id = ?")
                    ->execute([mb_substr(($lang === 'ar' ? 'وُجدت في إعادة الفحص — ' : 'Found in the re-check — ') . $it['marked_by'], 0, 255), (int)$case['id']]);
            } else {
                $pdo->prepare("UPDATE check_cases SET recheck_state = 'missing' WHERE id = ?")->execute([(int)$case['id']]);
            }
            if ($admins) notify_event($pdo, 'check_case', ['kind' => $it['state'] === 'present' ? 'found' : 'still', 'label' => $case['label'], 'chassis' => $case['chassis'],
                'branch' => $case['branch'], 'by' => (string)$it['marked_by'], 'only' => $admins, 'actor' => '', 'tag' => 'case-' . (int)$case['id'], 'force' => true, 'now' => true]);
        }
        $st = $pdo->prepare("SELECT i.*, c.branch FROM stock_check_items i JOIN stock_checks c ON c.id = i.check_id WHERE i.check_id = ? AND i.car_id > 0 AND i.state = 'missing'");
        $st->execute([$checkId]);
        $has = $pdo->prepare("SELECT 1 FROM check_cases WHERE car_id = ? AND status = 'open' LIMIT 1");
        $ins = $pdo->prepare("INSERT IGNORE INTO check_cases (check_id, item_id, car_id, branch, label, chassis, note, marked_by, opened_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $uidOf = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $has->execute([(int)$r['car_id']]);
            if ($has->fetchColumn()) continue;   // already followed (this was its re-check, or another check found it missing)
            $ins->execute([$checkId, (int)$r['id'], (int)$r['car_id'], (string)$r['branch'], (string)$r['label'], $r['chassis'], $r['note'], $r['marked_by']]);
            if (!$ins->rowCount()) continue;
            $caseId = (int)$pdo->lastInsertId();
            $opened++;
            $uidOf->execute([(string)$r['marked_by']]);
            check_case_plan_recheck($pdo, $caseId, (string)$r['branch'], (int)$r['car_id'], (int)$uidOf->fetchColumn());
        }
    } catch (Throwable $e) { error_log('check_cases_after_finish: ' . $e->getMessage()); }
    return $opened;
}

/** The next day, at a random time within the hours checks are proposed: re-check this car, by someone else. */
function check_case_plan_recheck(PDO $pdo, int $caseId, string $branch, int $carId, int $avoidUser): void
{
    $r = check_auto_rules($pdo);
    $now = (int)strtotime((string)$pdo->query("SELECT NOW()")->fetchColumn());
    $at = strtotime(date('Y-m-d', $now + 86400)) + random_int($r['from'] * 3600, max($r['from'] * 3600 + 900, $r['to'] * 3600 - 1));
    $pdo->prepare("INSERT INTO stock_check_plans (kind, branch, week_key, due_at, car_id, case_id, avoid_user) VALUES ('recheck', ?, ?, ?, ?, ?, ?)")
        ->execute([$branch, date('o-W', $at), date('Y-m-d H:i:s', $at), $carId, $caseId, $avoidUser ?: null]);
}

/** Open cases, oldest first, with where their re-check stands. */
function check_cases_open(PDO $pdo): array
{
    try {
        check_extra_tables($pdo);
        return $pdo->query("SELECT k.*, TIMESTAMPDIFF(MINUTE, k.opened_at, NOW()) AS mins,
                                   (SELECT p.status FROM stock_check_plans p WHERE p.case_id = k.id ORDER BY p.id DESC LIMIT 1) AS plan_status,
                                   (SELECT p.due_at FROM stock_check_plans p WHERE p.case_id = k.id ORDER BY p.id DESC LIMIT 1) AS plan_due,
                                   (SELECT u.username FROM stock_check_users s JOIN users u ON u.id = s.user_id WHERE s.check_id = k.recheck_id LIMIT 1) AS recheck_by
                            FROM check_cases k WHERE k.status = 'open' ORDER BY k.opened_at")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** The admin's decision on a case: found · moved (to another branch) · sold (not recorded) · lost. */
function check_case_decide(PDO $pdo, int $caseId, string $how, string $note, string $by): bool
{
    if (!in_array($how, ['found', 'moved', 'sold', 'lost'], true)) return false;
    $up = $pdo->prepare("UPDATE check_cases SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ? AND status = 'open'");
    $up->execute([$how, $by, mb_substr(trim($note), 0, 255) ?: null, $caseId]);
    if (!$up->rowCount()) return false;
    $pdo->prepare("UPDATE stock_check_plans SET status = 'skipped', decided_by = ?, decided_at = NOW() WHERE case_id = ? AND status IN ('planned', 'pending')")->execute([$by, $caseId]);
    return true;
}

/** Re-check a case's car now: a one-car spot check for the person chosen ($photo: what to photograph — check_photo_kind). Returns the check id (0 = the car is no longer there). */
function check_case_recheck_now(PDO $pdo, int $caseId, int $uid, int $minutes, string $by, int $photo = 0): int
{
    $st = $pdo->prepare("SELECT * FROM check_cases WHERE id = ? AND status = 'open'");
    $st->execute([$caseId]);
    $case = $st->fetch(PDO::FETCH_ASSOC);
    if (!$case || $uid < 1) return 0;
    $cid = smart_check_create($pdo, (string)$case['branch'], [$uid], max(3, min(60, $minutes)), '', true, $by, [], [(int)$case['car_id']], true, [], ['photos' => $photo ? 1 : 0, 'photo_kind' => $photo]);
    if ($cid) {
        $pdo->prepare("UPDATE check_cases SET recheck_id = ?, recheck_state = 'sent' WHERE id = ?")->execute([$cid, $caseId]);
        $pdo->prepare("UPDATE stock_check_plans SET status = 'skipped', decided_by = ?, decided_at = NOW() WHERE case_id = ? AND status IN ('planned', 'pending')")->execute([$by, $caseId]);
    }
    return $cid;
}

/** The decision taken on a car of a check, if any (for the check's page). [item id => case row] */
function check_cases_for_check(PDO $pdo, int $checkId): array
{
    $out = [];
    try { $st = $pdo->prepare("SELECT * FROM check_cases WHERE check_id = ?"); $st->execute([$checkId]); foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['item_id']] = $r; }
    catch (Throwable $e) {}
    return $out;
}

/* ════════════════════════ 👥 a double check ════════════════════════ */
/** The checks of a double check (each person's own), or [] for an ordinary check. */
function check_pair_ids(PDO $pdo, int $checkId): array
{
    try {
        $st = $pdo->prepare("SELECT COALESCE(group_id, 0) FROM stock_checks WHERE id = ?");
        $st->execute([$checkId]);
        $g = (int)$st->fetchColumn();
        if (!$g) return [];
        $st = $pdo->prepare("SELECT id FROM stock_checks WHERE id = ? OR group_id = ? ORDER BY id");
        $st->execute([$g, $g]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return []; }
}

/**
 * Each person's answers side by side.
 * ['ids' => [...], 'who' => [check => name], 'status' => [check => status], 'counted' => [check => n|null],
 *  'cars' => [car id => ['label', 'chassis', 'by' => [check => state|null], 'diff' => bool]],
 *  'extras' => [check => [labels]], 'diff' => cars that differ, 'done' => everyone finished]
 */
function check_pair_compare(PDO $pdo, int $checkId): array
{
    $ids = check_pair_ids($pdo, $checkId);
    $out = ['ids' => $ids, 'who' => [], 'status' => [], 'counted' => [], 'cars' => [], 'extras' => [], 'diff' => 0, 'done' => true];
    if (!$ids) return $out;
    $in = implode(',', $ids);
    foreach ($pdo->query("SELECT c.id, c.status, u.username, s.counted FROM stock_checks c LEFT JOIN stock_check_users s ON s.check_id = c.id LEFT JOIN users u ON u.id = s.user_id WHERE c.id IN ($in)") as $r) {
        $out['who'][(int)$r['id']] = (string)$r['username'];
        $out['status'][(int)$r['id']] = (string)$r['status'];
        $out['counted'][(int)$r['id']] = $r['counted'] === null ? null : (int)$r['counted'];
        if (!in_array($r['status'], ['done', 'cancelled'], true)) $out['done'] = false;
    }
    foreach ($pdo->query("SELECT check_id, car_id, label, chassis, state FROM stock_check_items WHERE check_id IN ($in) ORDER BY label") as $r) {
        if ((int)$r['car_id'] === 0) { $out['extras'][(int)$r['check_id']][] = (string)$r['label']; continue; }
        $c = (int)$r['car_id'];
        $out['cars'][$c] ??= ['label' => (string)$r['label'], 'chassis' => (string)$r['chassis'], 'by' => array_fill_keys($ids, null), 'diff' => false];
        $out['cars'][$c]['by'][(int)$r['check_id']] = $r['state'];
    }
    foreach ($out['cars'] as &$c) { $c['diff'] = count(array_unique(array_map(fn($s) => (string)$s, $c['by']))) > 1; if ($c['diff']) $out['diff']++; }
    unset($c);
    return $out;
}

/** The last check of a double check was finished: the admins get the comparison. */
function check_pair_finished(PDO $pdo, int $checkId): void
{
    try {
        $p = check_pair_compare($pdo, $checkId);
        if (!$p['ids'] || !$p['done']) return;
        $admins = check_admin_names($pdo);
        $st = $pdo->prepare("SELECT branch FROM stock_checks WHERE id = ?");
        $st->execute([$p['ids'][0]]);
        $lines = [];
        foreach ($p['cars'] as $c) {
            if (!$c['diff']) continue;
            $parts = [];
            foreach ($c['by'] as $cid => $s) $parts[] = ($p['who'][$cid] ?? '') . ': ' . ($s === 'present' ? '✅' : ($s === 'missing' ? '❌' : '—'));
            $lines[] = $c['label'] . ' (' . implode(' · ', $parts) . ')';
        }
        if ($admins) notify_event($pdo, 'check_pair', ['id' => $p['ids'][0], 'branch' => (string)$st->fetchColumn(), 'who' => implode('، ', array_unique(array_values($p['who']))),
            'diff' => $p['diff'], 'lines' => $lines, 'only' => $admins, 'actor' => '', 'tag' => 'chk-pair-' . $p['ids'][0], 'force' => true, 'now' => true]);
    } catch (Throwable $e) { error_log('check_pair_finished: ' . $e->getMessage()); }
}

/* ════════════════════════ ⏳ more time ════════════════════════ */
/**
 * The person doing a check asks for more minutes (10, 15 or 30). The admins get it with «موافقة» / «رفض»
 * buttons on the phone. One open request at a time, CHECK_TIME_MAX_ASK per check.
 * Returns ['ok' => bool, 'error' => 'pending'|'max'|'closed'].
 */
function check_time_ask(PDO $pdo, int $checkId, int $uid, string $uname, int $minutes, string $reason): array
{
    check_extra_tables($pdo);
    $minutes = in_array($minutes, [10, 15, 30], true) ? $minutes : 15;
    $st = $pdo->prepare("SELECT c.*, TIMESTAMPDIFF(SECOND, NOW(), c.deadline) AS secs FROM stock_checks c JOIN stock_check_users s ON s.check_id = c.id WHERE c.id = ? AND s.user_id = ? AND c.status IN ('active', 'expired')");
    $st->execute([$checkId, $uid]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) return ['ok' => false, 'error' => 'closed'];
    $st = $pdo->prepare("SELECT SUM(status = 'pending') AS p, COUNT(*) AS n FROM check_time_requests WHERE check_id = ? AND user_id = ?");
    $st->execute([$checkId, $uid]);
    $n = $st->fetch(PDO::FETCH_ASSOC) ?: ['p' => 0, 'n' => 0];
    if ((int)$n['p']) return ['ok' => false, 'error' => 'pending'];
    if ((int)$n['n'] >= CHECK_TIME_MAX_ASK) return ['ok' => false, 'error' => 'max'];
    $reason = mb_substr(trim(preg_replace('/\s+/u', ' ', $reason)), 0, 250);
    $pdo->prepare("INSERT INTO check_time_requests (check_id, user_id, minutes, reason, asked_at) VALUES (?, ?, ?, ?, NOW())")->execute([$checkId, $uid, $minutes, $reason ?: null]);
    $id = (int)$pdo->lastInsertId();
    [$t, $m] = smart_check_counts($pdo, $checkId);
    $admins = check_admin_names($pdo);
    if ($admins) notify_event($pdo, 'check_time_req', ['id' => $checkId, 'req' => $id, 'user' => $uname, 'branch' => (string)$c['branch'], 'minutes' => $minutes, 'reason' => $reason,
        'done' => $m, 'total' => $t, 'secs' => (int)$c['secs'], 'ref' => 'xt:' . $id, 'only' => $admins, 'actor' => '', 'tag' => 'chk-xt-' . $id, 'force' => true, 'now' => true]);
    return ['ok' => true, 'id' => $id];
}

/** An admin's answer to a request for more time. */
function check_time_decide(PDO $pdo, int $reqId, bool $yes, string $by): bool
{
    check_extra_tables($pdo);
    $st = $pdo->prepare("SELECT r.*, u.username FROM check_time_requests r JOIN users u ON u.id = r.user_id WHERE r.id = ? AND r.status = 'pending'");
    $st->execute([$reqId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return false;
    $up = $pdo->prepare("UPDATE check_time_requests SET status = ?, decided_by = ?, decided_at = NOW() WHERE id = ? AND status = 'pending'");
    $up->execute([$yes ? 'approved' : 'refused', $by, $reqId]);
    if (!$up->rowCount()) return false;
    if ($yes) return check_time_add($pdo, (int)$r['check_id'], (int)$r['minutes'], $by, 'approved') !== null;
    notify_event($pdo, 'check_time_ans', ['id' => (int)$r['check_id'], 'how' => 'refused', 'minutes' => (int)$r['minutes'], 'by' => $by,
        'only' => [(string)$r['username']], 'actor' => '', 'tag' => 'chk-xt-' . $reqId, 'force' => true, 'now' => true]);
    return true;
}

/**
 * More minutes for a check: a check whose time ran out runs again, its warning comes again before the
 * new end, and a stop that came from running out of time is lifted. The people doing it are told.
 * $how: 'approved' (their request) | 'admin' (an admin added it). Returns the new end time (null = not running).
 */
function check_time_add(PDO $pdo, int $checkId, int $minutes, string $by, string $how = 'admin'): ?string
{
    $minutes = max(5, min(240, $minutes));
    $up = $pdo->prepare("UPDATE stock_checks SET deadline = GREATEST(deadline, NOW()) + INTERVAL ? MINUTE, status = IF(status = 'expired', 'active', status), warned = 0 WHERE id = ? AND status IN ('active', 'expired')");
    $up->execute([$minutes, $checkId]);
    if (!$up->rowCount()) return null;
    $names = smart_check_users($pdo, $checkId);
    $ul = $pdo->prepare("UPDATE user_locks SET unlocked_at = NOW(), unlocked_by = ? WHERE user_id = ? AND unlocked_at IS NULL AND kind = 'check'");
    foreach (array_keys($names) as $uid) $ul->execute([$by, $uid]);
    $st = $pdo->prepare("SELECT UNIX_TIMESTAMP(deadline) FROM stock_checks WHERE id = ?");
    $st->execute([$checkId]);
    $lang = notify_options($pdo)['lang'];
    $until = smart_time_label((int)$st->fetchColumn(), $lang);
    if ($names) notify_event($pdo, 'check_time_ans', ['id' => $checkId, 'how' => $how, 'minutes' => $minutes, 'until' => $until, 'by' => $by,
        'only' => array_values($names), 'actor' => '', 'tag' => 'chk-' . $checkId . '-time', 'force' => true, 'now' => true]);
    return $until;
}

/** For the page of the person doing a check: seconds left, and their last request for more time. */
function check_time_state(PDO $pdo, int $checkId, int $uid): array
{
    check_extra_tables($pdo);
    $st = $pdo->prepare("SELECT status, TIMESTAMPDIFF(SECOND, NOW(), deadline) AS secs FROM stock_checks WHERE id = ?");
    $st->execute([$checkId]);
    $c = $st->fetch(PDO::FETCH_ASSOC) ?: ['status' => '', 'secs' => 0];
    $st = $pdo->prepare("SELECT id, status, minutes FROM check_time_requests WHERE check_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$checkId, $uid]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $n = $pdo->prepare("SELECT COUNT(*) FROM check_time_requests WHERE check_id = ? AND user_id = ?");
    $n->execute([$checkId, $uid]);
    return ['status' => (string)$c['status'], 'secs' => (int)$c['secs'], 'req' => $r ? ['id' => (int)$r['id'], 'status' => $r['status'], 'minutes' => (int)$r['minutes']] : null,
            'left' => max(0, CHECK_TIME_MAX_ASK - (int)$n->fetchColumn())];
}

/** Requests waiting for an admin, for a check's page. */
function check_time_pending(PDO $pdo, int $checkId): array
{
    try {
        check_extra_tables($pdo);
        $st = $pdo->prepare("SELECT r.*, u.username, TIMESTAMPDIFF(MINUTE, r.asked_at, NOW()) AS mins FROM check_time_requests r JOIN users u ON u.id = r.user_id WHERE r.check_id = ? AND r.status = 'pending' ORDER BY r.id");
        $st->execute([$checkId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}
