<?php
/*
 * my_day.php — each person's own day on the dashboard: hours today toward the
 * daily target, the streak of working days attended in a row, and the badges
 * earned from attendance. Also the numbers of the morning brief.
 *
 * Everything here comes from attendance and stock only.
 */

/** Attendance settings without loading attendance.php: weekly days off + daily target hours. */
function my_day_settings(PDO $pdo): array
{
    $s = [];
    try { $s = json_decode((string)$pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'attendance_settings'")->fetchColumn(), true) ?: []; } catch (Throwable $e) {}
    return [
        'off'    => array_values(array_intersect(array_map('intval', (array)($s['off'] ?? [])), range(0, 6))),
        'target' => max(1, min(16, (float)($s['target'] ?? 10))),
    ];
}

/**
 * ['in' => clocked in now, 'today' => seconds today, 'target' => seconds, 'streak' => days in a row,
 *  'month_days' =>, 'full_days' => days this month that reached the target, 'forgot' => forgotten clock-outs this month,
 *  'badges' => [[icon, text]], 'next' => text | '']
 */
function my_day(PDO $pdo, int $uid, string $lang): array
{
    $ar = $lang === 'ar';
    $set = my_day_settings($pdo);
    $out = ['in' => false, 'today' => 0, 'target' => (int)round($set['target'] * 3600), 'streak' => 0, 'month_days' => 0, 'full_days' => 0, 'forgot' => 0, 'badges' => [], 'next' => ''];
    try {
        $today = (string)$pdo->query("SELECT CURDATE()")->fetchColumn();
        $st = $pdo->prepare("SELECT DATE(clock_in) AS d, MAX(status = 'active') AS live, MAX(auto_closed) AS forgot,
                                    SUM(CASE WHEN auto_closed = 1 THEN 0 ELSE TIMESTAMPDIFF(SECOND, clock_in, COALESCE(clock_out, NOW())) END) AS secs
                             FROM attendance_logs WHERE user_id = ? AND clock_in >= CURDATE() - INTERVAL 150 DAY GROUP BY DATE(clock_in)");
        $st->execute([$uid]);
        $days = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $days[$r['d']] = $r;
        if (isset($days[$today])) { $out['today'] = max(0, (int)$days[$today]['secs']); $out['in'] = (bool)$days[$today]['live']; }

        // the streak: working days in a row (weekly days off do not break it; today counts once they have clocked in)
        $d = strtotime($today);
        if (!isset($days[$today])) $d -= 86400;
        for ($i = 0; $i < 150; $i++, $d -= 86400) {
            $k = date('Y-m-d', $d);
            if (isset($days[$k])) { $out['streak']++; continue; }
            if (in_array((int)date('w', $d), $set['off'], true)) continue;
            break;
        }
        $m = substr($today, 0, 7);
        foreach ($days as $k => $r) if (substr($k, 0, 7) === $m) {
            $out['month_days']++;
            if ((int)$r['secs'] >= $out['target']) $out['full_days']++;
            if ((int)$r['forgot']) $out['forgot']++;
        }

        $tg = rtrim(rtrim(number_format($set['target'], 1), '0'), '.');
        $s = $out['streak'];
        foreach ([30 => '🏆', 14 => '🥇', 7 => '🔥', 3 => '✨'] as $n => $ic) if ($s >= $n) { $out['badges'][] = [$ic, $ar ? ($n <= 10 ? $n . ' أيام متتالية' : $n . ' يوماً متتالياً') : $n . ' days in a row']; break; }
        foreach ([20, 10, 5] as $n) if ($out['full_days'] >= $n) { $out['badges'][] = ['⏱️', $ar ? 'أكملت ' . $tg . ' ساعات ' . ($n <= 10 ? $n . ' مرات' : $n . ' مرة') . ' هذا الشهر' : $n . ' full ' . $tg . 'h days this month']; break; }
        if ($out['month_days'] >= 20) $out['badges'][] = ['📅', $ar ? 'حضور 20 يوماً هذا الشهر' : '20 days this month'];
        if ($out['month_days'] >= 5 && !$out['forgot']) $out['badges'][] = ['🌟', $ar ? 'لم تنسَ تسجيل الانصراف هذا الشهر' : 'Never forgot to clock out this month'];
        foreach ([3, 7, 14, 30] as $n) if ($s < $n) {
            $left = $n - $s;
            $out['next'] = $ar ? 'باقي ' . ($left === 1 ? 'يوم واحد' : ($left === 2 ? 'يومان' : $left . ($left <= 10 ? ' أيام' : ' يوماً'))) . ' للوصول إلى ' . $n . ' يوماً متتالياً'
                               : $left . ' more day' . ($left === 1 ? '' : 's') . ' to reach ' . $n . ' in a row';
            break;
        }
    } catch (Throwable $e) { error_log('my_day: ' . $e->getMessage()); }
    return $out;
}

/** The morning brief: what changed since yesterday. Counts only — no names. */
function my_day_brief(PDO $pdo): array
{
    $b = ['sold' => 0, 'added' => 0, 'road' => 0, 'present' => 0, 'aged' => 0];
    $one = function (string $sql) use ($pdo): int { try { return (int)$pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; } };
    $b['sold']    = $one("SELECT COUNT(*) FROM sold_cars WHERE DATE(sold_at) = CURDATE() - INTERVAL 1 DAY AND returned_at IS NULL");
    $b['added']   = $one("SELECT COUNT(*) FROM cars WHERE DATE(created_at) = CURDATE() - INTERVAL 1 DAY");
    $b['present'] = $one("SELECT COUNT(DISTINCT user_id) FROM attendance_logs WHERE status = 'active'");
    $b['aged']    = $one("SELECT COUNT(*) FROM cars WHERE status IN ('available', 'reserved') AND created_at <= NOW() - INTERVAL 60 DAY");
    try { if (function_exists('smart_pending_transfers')) $b['road'] = count(smart_pending_transfers($pdo, '', 7)); } catch (Throwable $e) {}
    return $b;
}

/**
 * «دقة الجرد»: how well this person did the stock checks they have ALREADY finished
 * (last 60 days) — on time and without wrong entries. null when they have none.
 * It never looks at checks that are running or planned.
 */
function my_check_score(PDO $pdo, int $uid): ?int
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) AS n, SUM(c.finished_at <= c.deadline) AS ontime FROM stock_checks c JOIN stock_check_users s ON s.check_id = c.id
                             WHERE s.user_id = ? AND c.status = 'done' AND c.finished_at >= NOW() - INTERVAL 60 DAY");
        $st->execute([$uid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || !(int)$r['n']) return null;
        $wrong = 0;
        try {
            $w = $pdo->prepare("SELECT COUNT(*) FROM chassis_tries WHERE user_id = ? AND ok = 0 AND ctx IN ('check', 'count') AND created_at >= NOW() - INTERVAL 60 DAY");
            $w->execute([$uid]);
            $wrong = (int)$w->fetchColumn();
        } catch (Throwable $e) {}
        return max(0, min(100, (int)round(100 * (int)$r['ontime'] / (int)$r['n']) - 10 * $wrong));
    } catch (Throwable $e) { return null; }
}

/**
 * The attendance board: everyone (not admins) by their streak of days in a row,
 * then by full days this month. Each row: name, streak, full days, check score (or null).
 */
function my_day_board(PDO $pdo, string $lang, int $limit = 8): array
{
    $rows = [];
    try {
        foreach ($pdo->query("SELECT id, username FROM users WHERE active = 1 AND role <> 'admin'")->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $d = my_day($pdo, (int)$u['id'], $lang);
            if (!$d['streak'] && !$d['month_days']) continue;
            $rows[] = ['id' => (int)$u['id'], 'name' => (string)$u['username'], 'streak' => (int)$d['streak'], 'full' => (int)$d['full_days'], 'days' => (int)$d['month_days'], 'check' => my_check_score($pdo, (int)$u['id'])];
        }
        usort($rows, fn($a, $b) => [$b['streak'], $b['full'], $b['days']] <=> [$a['streak'], $a['full'], $a['days']] ?: strcmp($a['name'], $b['name']));
    } catch (Throwable $e) { error_log('my_day_board: ' . $e->getMessage()); }
    return array_slice($rows, 0, $limit);
}
