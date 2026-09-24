<?php
/*
 * attendance_helpers.php — attendance settings (work start, grace, days off,
 * geofence) and the late-arrival rule. Used by attendance.php (alerts on
 * clock-in / clock-out) and attendance_admin.php (the log).
 */
require_once __DIR__ . '/push_helpers.php';

/** Saved settings, with defaults: start 10:00, 15 min grace, no days off, 50 m geofence. */
function att_settings(PDO $pdo): array
{
    push_tables($pdo);   // the settings table lives there
    $s = json_decode(push_setting($pdo, 'attendance_settings', ''), true);
    $s = is_array($s) ? $s : [];
    $start = array_key_exists('start', $s) ? (string)$s['start'] : '10:00';
    return [
        'start' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) ? $start : '',   // '' = late tracking off
        'grace' => max(0, min(180, (int)($s['grace'] ?? 15))),
        'off'   => array_values(array_unique(array_intersect(array_map('intval', (array)($s['off'] ?? [])), range(0, 6)))),   // 0 = Sunday … 6 = Saturday
        'fence' => max(10, min(5000, (int)($s['fence'] ?? 50))),
    ];
}

function att_settings_save(PDO $pdo, array $in, string $by): void
{
    $start = trim((string)($in['start'] ?? ''));
    push_setting_set($pdo, 'attendance_settings', json_encode([
        'start' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) ? $start : '',
        'grace' => max(0, min(180, (int)($in['grace'] ?? 15))),
        'off'   => array_values(array_unique(array_intersect(array_map('intval', (array)($in['off'] ?? [])), range(0, 6)))),
        'fence' => max(10, min(5000, (int)($in['fence'] ?? 50))),
    ]), $by);
}

/** Is this date (Y-m-d) one of the weekly days off? */
function att_is_off(array $set, string $date): bool
{
    return in_array((int)date('w', strtotime($date)), $set['off'], true);
}

/**
 * Minutes late for a day's FIRST clock-in ('Y-m-d H:i:s'), counted from the
 * start time; 0 when on time (within grace), on a day off, or tracking is off.
 */
function att_late_min(array $set, string $clockIn): int
{
    if ($set['start'] === '' || $clockIn === '') return 0;
    $ts = strtotime($clockIn);
    if ($ts === false || att_is_off($set, date('Y-m-d', $ts))) return 0;
    $m = intdiv($ts - strtotime(date('Y-m-d', $ts) . ' ' . $set['start'] . ':00'), 60);
    return $m > $set['grace'] ? $m : 0;
}
