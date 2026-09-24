<?php
/* ============================================================
   attendance_admin.php — Admin attendance log (grouped by employee)
   Redesigned to match First 1 Car dashboard aesthetic.
   Today board (at work / finished / absent), quick dates, search,
   problems-only filter, a day bar per person, live timers, late
   arrivals, and settings (start time, grace, days off, geofence).
   ============================================================ */

require 'auth.php';
require 'config.php';

ini_set('display_errors', 0);

// If anything goes wrong, show the admin exactly what (this page is admin-only) instead of a blank page.
function att_admin_problem(string $msg): void
{
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/html; charset=utf-8'); }
    echo '<div style="font-family:Segoe UI,Tahoma,Arial,sans-serif;max-width:640px;margin:40px auto;padding:20px 22px;border-radius:16px;background:#1e1b2e;border:1px solid #ef4444;color:#fecaca;direction:ltr;text-align:left">'
       . '<div style="font-size:18px;font-weight:800;color:#fff;margin-bottom:6px" dir="rtl">⚠️ صفحة سجل البصمة لم تفتح</div>'
       . '<div style="font-size:13px;color:#fca5a5;margin-bottom:12px" dir="rtl">انسخ هذه الرسالة وأرسلها كما هي:</div>'
       . '<pre style="white-space:pre-wrap;word-break:break-word;background:#0f0d1a;padding:12px;border-radius:10px;font-size:12.5px;color:#fde68a">' . htmlspecialchars($msg) . '</pre>'
       . '<a href="dashboard.php" style="color:#a5b4fc;font-weight:700">← Dashboard</a></div>';
}
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        att_admin_problem($e['message'] . "\n" . basename($e['file']) . ':' . $e['line'] . "\nPHP " . PHP_VERSION);
    }
});
foreach (['attendance_helpers.php', 'push_helpers.php'] as $need) {
    if (!is_file(__DIR__ . '/' . $need)) { att_admin_problem("Missing file: $need\nUpload it to the same folder as attendance_admin.php\nPHP " . PHP_VERSION); exit; }
}
require_once __DIR__ . '/attendance_helpers.php';
foreach (['att_settings', 'push_setting', 'push_setting_set', 'push_tables'] as $fn) {
    if (!function_exists($fn)) { att_admin_problem("Old file: $fn() not found — upload the new push_helpers.php and attendance_helpers.php\nPHP " . PHP_VERSION); exit; }
}

$lang = $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar','en'])) $lang = 'ar';
$role = $_SESSION['role'] ?? '';

// Permission-gated (default: admin only) — editable from permissions_admin.php
perm_require('page.attendance_admin');

$isRTL = $lang === 'ar';
$dir   = $isRTL ? 'rtl' : 'ltr';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

/* ---------------- Settings (start time, grace, days off, geofence) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'settings') {
    if (hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        att_settings_save($pdo, [
            'start' => !empty($_POST['late_on']) ? ($_POST['start'] ?? '') : '',
            'grace' => $_POST['grace'] ?? 15,
            'off'   => $_POST['off'] ?? [],
            'fence' => $_POST['fence'] ?? 50,
        ], (string)$_SESSION['username']);
        $_SESSION['att_flash'] = 'saved';
    }
    header('Location: attendance_admin.php?' . http_build_query(array_diff_key($_GET, ['x' => 1])));
    exit;
}
$flash = $_SESSION['att_flash'] ?? ''; unset($_SESSION['att_flash']);
$set = att_settings($pdo);

// Geofence: punches farther than this many metres from the branch get a red flag.
// Set generously to absorb normal GPS drift. Change it from ⚙️ on this page.
$GEOFENCE_M = $set['fence'];

/* ---------------- Dates come from the database clock (the same clock the punches use) ---------------- */
$clock = $pdo->query("SELECT CURDATE() AS d, NOW() AS n")->fetch(PDO::FETCH_ASSOC);
$today = $clock['d'];
$dayTs = strtotime($today);
$quick = [
    'today' => [$today, $today],
    'yday'  => [date('Y-m-d', strtotime('-1 day', $dayTs)), date('Y-m-d', strtotime('-1 day', $dayTs))],
    'week'  => [date('Y-m-d', strtotime('-' . (((int)date('w', $dayTs) + 1) % 7) . ' day', $dayTs)), $today],   // week starts Saturday
    'month' => [date('Y-m-01', $dayTs), $today],
];

/* ---------------- Filters ---------------- */
$isDate = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
$filterBranch = (string)($_GET['branch'] ?? '');
$filterUser   = (string)($_GET['user_id'] ?? '');
$filterFrom   = $isDate($_GET['from'] ?? null) ? $_GET['from'] : $today;
$filterTo     = $isDate($_GET['to'] ?? null) ? $_GET['to'] : $today;
$swapped = false;
if ($filterFrom > $filterTo) { [$filterFrom, $filterTo] = [$filterTo, $filterFrom]; $swapped = true; }
$onlyIssues = !empty($_GET['issues']);
$quickOn = '';
foreach ($quick as $k => [$f, $t]) if ($f === $filterFrom && $t === $filterTo) $quickOn = $k;
$oneDay = $filterFrom === $filterTo;

// keeps every filter when switching language, dates, etc.
$qs = function (array $over = []) use ($lang, $filterFrom, $filterTo, $filterBranch, $filterUser, $onlyIssues): string {
    $q = array_merge(['lang' => $lang, 'from' => $filterFrom, 'to' => $filterTo, 'branch' => $filterBranch, 'user_id' => $filterUser, 'issues' => $onlyIssues ? 1 : ''], $over);
    return '?' . http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
};

// some older databases don't have every column yet — work with what is there
$cols   = array_flip($pdo->query("SHOW COLUMNS FROM attendance_logs")->fetchAll(PDO::FETCH_COLUMN));
$hasOut = isset($cols['out_branch_name']);

$where  = ["DATE(a.clock_in) BETWEEN ? AND ?"];
$params = [$filterFrom, $filterTo];
if ($filterBranch !== '') {
    if ($hasOut) { $where[] = "(a.branch_name = ? OR a.out_branch_name = ?)"; $params[] = $filterBranch; $params[] = $filterBranch; }
    else         { $where[] = "a.branch_name = ?"; $params[] = $filterBranch; }
}
if ($filterUser !== '')   { $where[] = "a.user_id = ?";     $params[] = (int)$filterUser; }
$whereSql = implode(' AND ', $where);

/* Auto clock-out forgotten sessions (>14h) */
$pdo->exec(
    "UPDATE attendance_logs
     SET clock_out = DATE_ADD(clock_in, INTERVAL 14 HOUR),
         status = 'completed', auto_closed = 1
     WHERE status = 'active' AND clock_in < DATE_SUB(NOW(), INTERVAL 14 HOUR)"
);

$outSel  = $hasOut ? "b2.name_ar AS out_ar, b2.name_en AS out_en," : "NULL AS out_ar, NULL AS out_en,";
$outJoin = $hasOut ? "LEFT JOIN branches b2 ON b2.name = a.out_branch_name" : "";
$sql = "SELECT a.*, u.username, u.role AS user_role,
               b.name_ar AS branch_ar, b.name_en AS branch_en,
               $outSel
               TIMESTAMPDIFF(SECOND, a.clock_in, COALESCE(a.clock_out, NOW())) AS dur_secs
        FROM attendance_logs a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN branches b ON b.name = a.branch_name
        $outJoin
        WHERE $whereSql
        ORDER BY a.clock_in DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Each day's first clock-in per person (for late arrivals) — over the whole day, not just the filtered rows */
$firstIn = [];
$st = $pdo->prepare("SELECT user_id, DATE(clock_in) d, MIN(clock_in) f FROM attendance_logs WHERE DATE(clock_in) BETWEEN ? AND ? GROUP BY user_id, DATE(clock_in)");
$st->execute([$filterFrom, $filterTo]);
foreach ($st as $r) $firstIn[$r['user_id'] . '|' . $r['d']] = $r['f'];

/* ---------------- Group by employee ---------------- */
$people = [];   // user_id => ['name','role','total','sessions'=>[], 'active'=>bool, ...]
$grandTotal = 0;
$autoCount = 0; $lateCount = 0; $issueCount = 0;
foreach ($logs as $log) {
    $log += ['in_dist_m' => null, 'out_dist_m' => null, 'out_branch_name' => null, 'auto_closed' => 0, 'in_loc_denied' => 0, 'out_loc_denied' => 0,
             'clock_in_lat' => null, 'clock_in_lng' => null, 'clock_out_lat' => null, 'clock_out_lng' => null];
    $uid  = $log['user_id'];
    $day  = substr((string)$log['clock_in'], 0, 10);
    $secs = max(0, (int)$log['dur_secs']);
    $auto = !empty($log['auto_closed']);
    $far  = [];
    if ($log['in_dist_m'] !== null && (int)$log['in_dist_m'] > $GEOFENCE_M) $far[] = 'in';
    if ($log['out_dist_m'] !== null && (int)$log['out_dist_m'] > $GEOFENCE_M) $far[] = 'out';
    $late = (($firstIn[$uid . '|' . $day] ?? '') === $log['clock_in']) ? att_late_min($set, (string)$log['clock_in']) : 0;
    $denied = !empty($log['in_loc_denied']) || ($log['clock_out'] && !empty($log['out_loc_denied']));
    $log['_late'] = $late; $log['_far'] = $far; $log['_issue'] = $auto || $far || $denied || $late;
    if ($onlyIssues && !$log['_issue']) continue;

    if (!isset($people[$uid])) {
        $people[$uid] = [
            'name'     => $log['username'] ?? ('#' . $uid),
            'role'     => $log['user_role'] ?? '',
            'total'    => 0,
            'sessions' => [],
            'active'   => false,
            'days'     => [],
            'auto' => 0, 'late' => 0, 'lateMin' => 0, 'issues' => 0, 'first' => null, 'last' => null, 'liveSecs' => 0,
        ];
    }
    $P = &$people[$uid];
    // a forgotten clock-out closed by the system is NOT counted as worked hours
    if (!$auto) { $P['total'] += $secs; $grandTotal += $secs; }
    $P['sessions'][] = $log;
    $P['days'][$day][] = $log;
    if ($auto) { $P['auto']++; $autoCount++; }
    if ($late) { $P['late']++; $P['lateMin'] += $late; $lateCount++; }
    if ($log['_issue']) { $P['issues']++; $issueCount++; }
    if ($log['status'] === 'active') { $P['active'] = true; $P['liveSecs'] += $secs; }
    if ($P['first'] === null || $log['clock_in'] < $P['first']) $P['first'] = $log['clock_in'];
    $end = $log['clock_out'] ?: null;
    if ($end && ($P['last'] === null || $end > $P['last'])) $P['last'] = $end;
    unset($P);
}
// Sort people by most hours first
uasort($people, fn($a,$b) => $b['total'] <=> $a['total']);

/* ---------------- Right now (whatever dates are filtered) ---------------- */
$activeRows = $pdo->query("SELECT a.user_id, u.username, a.branch_name, b.name_ar, b.name_en, TIMESTAMPDIFF(SECOND, a.clock_in, NOW()) AS el, a.clock_in
                           FROM attendance_logs a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN branches b ON b.name = a.branch_name
                           WHERE a.status = 'active' ORDER BY a.clock_in")->fetchAll(PDO::FETCH_ASSOC);
$activeNow = count($activeRows);

/* ---------------- Today board: at work / finished / didn't come ---------------- */
$todayRows = $pdo->query("SELECT a.user_id, u.username, MIN(a.clock_in) AS first_in, MAX(a.clock_out) AS last_out,
                                 SUM(a.status = 'active') AS act,
                                 SUM(CASE WHEN a.auto_closed = 1 THEN 0 ELSE TIMESTAMPDIFF(SECOND, a.clock_in, COALESCE(a.clock_out, NOW())) END) AS secs
                          FROM attendance_logs a LEFT JOIN users u ON u.id = a.user_id
                          WHERE DATE(a.clock_in) = CURDATE() GROUP BY a.user_id, u.username")->fetchAll(PDO::FETCH_ASSOC);
$todayBy = [];
foreach ($todayRows as $r) $todayBy[(int)$r['user_id']] = $r;
// who is expected: active users allowed to clock in who have clocked in during the last 60 days
$recent = array_map('intval', $pdo->query("SELECT DISTINCT user_id FROM attendance_logs WHERE clock_in >= DATE_SUB(NOW(), INTERVAL 60 DAY)")->fetchAll(PDO::FETCH_COLUMN));
$absent = [];
foreach ($pdo->query("SELECT id, username, role FROM users WHERE active = 1 ORDER BY username") as $u) {
    $id = (int)$u['id'];
    if (isset($todayBy[$id]) || !in_array($id, $recent, true)) continue;
    try { if (empty(perm_effective($pdo, $id, (string)$u['role'])['page.attendance'])) continue; } catch (Throwable $e) {}
    $absent[] = $u;
}
$todayOff = att_is_off($set, $today);
$finished = array_filter($todayRows, fn($r) => !(int)$r['act']);
$todayLate = 0;
foreach ($todayRows as $r) if (att_late_min($set, (string)$r['first_in'])) $todayLate++;

$branches = $pdo->query("SELECT name, name_ar, name_en FROM branches ORDER BY name_en")->fetchAll(PDO::FETCH_ASSOC);
$users    = $pdo->query("SELECT id, username FROM users WHERE active = 1 ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

function fmtDur($secs) {
    global $isRTL;
    return sprintf($isRTL ? '%dس %02dد' : '%dh %02dm', floor($secs / 3600), floor(($secs % 3600) / 60));
}
function fmtT($dt) {
    global $isRTL;
    if (!$dt) return '';
    $ts = strtotime($dt);
    return date('h:i', $ts) . ($isRTL ? (date('A', $ts) === 'AM' ? ' ص' : ' م') : ' ' . date('A', $ts));
}
function dayLabel($d) {
    global $isRTL, $today;
    $ts = strtotime($d);
    $wd = $isRTL ? ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'][(int)date('w', $ts)] : date('l', $ts);
    $rel = $d === $today ? ($isRTL ? 'اليوم' : 'Today') : ($d === date('Y-m-d', strtotime('-1 day', strtotime($today))) ? ($isRTL ? 'أمس' : 'Yesterday') : '');
    return ($rel !== '' ? $rel . ' · ' : '') . $wd . ' ' . date('d/m', $ts);
}
$roleName = fn($r) => $isRTL ? (['admin' => 'أدمن', 'manager' => 'مدير', 'sales' => 'مبيعات'][$r] ?? $r) : ucfirst((string)$r);

/* the day bar: 06:00 → midnight */
$BAR_FROM = 6; $BAR_HOURS = 18;
$barPct = function (string $day, string $dt) use ($BAR_FROM, $BAR_HOURS): float {
    $p = (strtotime($dt) - strtotime($day . ' ' . sprintf('%02d', $BAR_FROM) . ':00:00')) / ($BAR_HOURS * 3600) * 100;
    return max(0, min(100, $p));
};
$nowStr = $clock['n'];
$daysInRange = (int)round((strtotime($filterTo) - strtotime($filterFrom)) / 86400) + 1;
$wdNames = $isRTL ? [6 => 'السبت', 0 => 'الأحد', 1 => 'الاثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة']
                  : [6 => 'Sat', 0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php include __DIR__ . '/pwa_head.php'; ?>
<title><?= $isRTL ? 'سجل البصمة' : 'Attendance Log' ?></title>
<style>
  *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
  body{
    font-family:'Segoe UI',Tahoma,Arial,sans-serif;
    background:linear-gradient(135deg,#020617,#0f172a);
    color:#fff; min-height:100vh; padding:20px 16px 50px;
  }
  .wrap{ max-width:900px; margin:0 auto; }

  .topbar{
    display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
    background:rgba(15,23,42,.88); border:1px solid rgba(255,255,255,.08);
    border-radius:20px; padding:16px 20px; margin-bottom:16px; backdrop-filter:blur(20px);
  }
  .topbar h1{ font-size:19px; font-weight:800; }
  .topbar .sub{ font-size:12px; color:#94a3b8; font-weight:600; margin-top:3px; }
  .lang-switch{ display:flex; gap:6px; flex-wrap:wrap; }
  .lang-switch a,.lang-switch button{ text-decoration:none; padding:6px 11px; border-radius:9px; background:#111827; color:#fff; font-weight:700; font-size:12px; border:0; cursor:pointer; font-family:inherit; }
  .lang-switch a:hover,.lang-switch button:hover{ background:#1f2937; }
  .lang-active{ background:#9333ea !important; }

  .flash{ background:rgba(34,197,94,.12); border:1px solid rgba(34,197,94,.3); color:#86efac; border-radius:14px; padding:10px 14px; font-size:13px; font-weight:700; margin-bottom:14px; }
  .note{ background:rgba(245,158,11,.1); border:1px solid rgba(245,158,11,.3); color:#fcd34d; border-radius:14px; padding:10px 14px; font-size:12.5px; font-weight:700; margin-bottom:14px; }

  /* Today board */
  .board{ display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:16px; }
  .bcol{ background:rgba(15,23,42,.88); border:1px solid rgba(255,255,255,.08); border-radius:18px; padding:14px; backdrop-filter:blur(20px); position:relative; overflow:hidden; min-height:118px; }
  .bcol::before{ content:''; position:absolute; inset-inline:0; top:0; height:3px; background:var(--c); }
  .bcol h3{ font-size:13px; font-weight:800; display:flex; align-items:center; gap:8px; margin-bottom:10px; color:#e2e8f0; }
  .bcol h3 b{ margin-inline-start:auto; font-size:20px; color:var(--c); font-variant-numeric:tabular-nums; }
  .bcol .dot{ width:9px; height:9px; border-radius:50%; background:var(--c); box-shadow:0 0 0 4px color-mix(in srgb,var(--c) 22%,transparent); }
  .bcol.live .dot{ animation:pulse 1.6s infinite; }
  @keyframes pulse{ 50%{ box-shadow:0 0 0 7px transparent; } }
  .bl{ display:flex; flex-direction:column; gap:6px; max-height:190px; overflow-y:auto; }
  .bi{ display:flex; align-items:center; gap:8px; font-size:12.5px; padding:6px 8px; border-radius:10px; background:rgba(255,255,255,.035); }
  .bi .av{ width:24px; height:24px; border-radius:8px; display:grid; place-items:center; font-size:11px; font-weight:900; background:linear-gradient(135deg,#9333ea,#22c55e); flex-shrink:0; }
  .bi .nm{ flex:1; min-width:0; font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .bi .nm small{ display:block; font-size:10.5px; color:#94a3b8; font-weight:600; }
  .bi .tm{ font-size:12px; font-weight:800; color:var(--c); font-variant-numeric:tabular-nums; white-space:nowrap; }
  .bi .lt{ font-size:10px; font-weight:800; color:#fbbf24; background:rgba(245,158,11,.14); padding:1px 6px; border-radius:999px; }
  .bempty{ font-size:12px; color:#64748b; padding:10px 4px; }

  /* Summary stat cards */
  .stats{ display:grid; grid-template-columns:repeat(5,1fr); gap:12px; margin-bottom:16px; }
  .stat{
    background:rgba(15,23,42,.88); border:1px solid rgba(255,255,255,.08);
    border-radius:18px; padding:16px; text-align:center; backdrop-filter:blur(20px);
  }
  .stat-num{ font-size:26px; font-weight:800; line-height:1; font-variant-numeric:tabular-nums; }
  .stat-num.green{ color:#22c55e; } .stat-num.purple{ color:#a855f7; } .stat-num.amber{ color:#f59e0b; } .stat-num.red{ color:#f87171; } .stat-num.sky{ color:#38bdf8; }
  .stat-lbl{ font-size:11px; color:#94a3b8; font-weight:700; margin-top:6px; }
  .stat-sub{ font-size:10px; color:#64748b; margin-top:3px; font-weight:600; }

  /* Filters */
  .filters{
    display:flex; flex-wrap:wrap; gap:8px; margin-bottom:10px;
    background:rgba(15,23,42,.88); border:1px solid rgba(255,255,255,.08);
    border-radius:18px; padding:14px; backdrop-filter:blur(20px);
  }
  .filters input,.filters select{
    background:#0d1526; border:1px solid rgba(255,255,255,.1); color:#f1f5f9;
    padding:9px 11px; border-radius:10px; font-family:inherit; font-size:13px; flex:1; min-width:120px; outline:none;
  }
  .filters input:focus,.filters select:focus{ border-color:rgba(147,51,234,.5); }
  .filters button{
    background:linear-gradient(135deg,#9333ea,#2563eb); border:none; color:#fff; font-weight:800;
    padding:9px 22px; border-radius:10px; cursor:pointer; font-family:inherit; font-size:13px;
  }
  .dl-btn{
    background:linear-gradient(135deg,#16a34a,#22c55e); color:#022c14; font-weight:800;
    padding:9px 18px; border-radius:10px; text-decoration:none; font-size:13px;
    display:inline-flex; align-items:center; gap:5px; white-space:nowrap;
    box-shadow:0 6px 18px rgba(34,197,94,.25);
  }
  .dl-btn:hover{ filter:brightness(1.08); }
  .quick{ display:flex; gap:6px; flex-wrap:wrap; align-items:center; margin-bottom:16px; }
  .quick a,.quick label{ text-decoration:none; height:34px; display:inline-flex; align-items:center; gap:6px; padding:0 13px; border-radius:999px; border:1px solid rgba(255,255,255,.1); background:rgba(15,23,42,.88); color:#cbd5e1; font-size:12.5px; font-weight:800; cursor:pointer; white-space:nowrap; }
  .quick a.on{ background:linear-gradient(135deg,#9333ea,#2563eb); border-color:transparent; color:#fff; }
  .quick a.iss.on{ background:linear-gradient(135deg,#dc2626,#f97316); }
  .quick .srch{ flex:1; min-width:160px; height:34px; border-radius:999px; border:1px solid rgba(255,255,255,.1); background:#0d1526; color:#f1f5f9; padding:0 14px; font-family:inherit; font-size:12.5px; outline:none; }
  .quick .srch:focus{ border-color:rgba(147,51,234,.5); }

  /* Employee cards */
  .person{
    background:rgba(15,23,42,.88); border:1px solid rgba(255,255,255,.08);
    border-radius:20px; margin-bottom:12px; backdrop-filter:blur(20px); overflow:hidden;
    transition:border-color .2s;
  }
  .person.is-active{ border-color:rgba(34,197,94,.35); }
  .person-head{
    display:flex; align-items:center; gap:14px; padding:16px 18px; cursor:pointer;
    user-select:none; transition:background .2s;
  }
  .person-head:hover{ background:rgba(255,255,255,.03); }
  .p-avatar{
    width:46px; height:46px; border-radius:14px; flex-shrink:0;
    background:linear-gradient(135deg,#9333ea,#22c55e);
    display:flex; align-items:center; justify-content:center; font-weight:800; font-size:20px;
  }
  .p-info{ flex:1; min-width:0; }
  .p-name{ font-size:16px; font-weight:800; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
  .p-role{ font-size:10px; color:#a855f7; font-weight:700; background:rgba(147,51,234,.18); padding:2px 8px; border-radius:20px; }
  .p-live{ font-size:10px; color:#22c55e; font-weight:800; background:rgba(34,197,94,.15); padding:2px 8px; border-radius:20px; border:1px solid rgba(34,197,94,.3); }
  .p-meta{ font-size:12px; color:#94a3b8; margin-top:4px; display:flex; flex-wrap:wrap; gap:4px 10px; }
  .p-flags{ display:flex; flex-wrap:wrap; gap:5px; margin-top:6px; }
  .p-total{ text-align:end; flex-shrink:0; }
  .p-total-num{ font-size:18px; font-weight:800; color:#a3e635; font-variant-numeric:tabular-nums; }
  .p-total-lbl{ font-size:10px; color:#64748b; font-weight:700; }
  .p-chevron{ margin-inline-start:6px; color:#64748b; font-size:14px; transition:transform .25s; flex-shrink:0; }
  .person.open .p-chevron{ transform:rotate(180deg); }

  .person-body{ display:none; padding:0 14px 14px; }
  .person.open .person-body{ display:block; }
  .day{ margin-bottom:12px; }
  .day:last-child{ margin-bottom:0; }
  .day-h{ display:flex; align-items:center; justify-content:space-between; gap:8px; font-size:12px; font-weight:800; color:#cbd5e1; margin:4px 2px 8px; }
  .day-h span{ color:#a3e635; font-variant-numeric:tabular-nums; }
  .track{ position:relative; height:30px; border-radius:10px; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.06); margin-bottom:8px; overflow:hidden; }
  .track .hr{ position:absolute; top:0; bottom:0; width:1px; background:rgba(255,255,255,.07); }
  .track .hr i{ position:absolute; bottom:1px; inset-inline-start:3px; font-style:normal; font-size:9px; color:#475569; font-weight:700; }
  .track .st{ position:absolute; top:0; bottom:0; width:2px; background:#f59e0b; box-shadow:0 0 8px #f59e0b; }
  .track .seg{ position:absolute; top:5px; bottom:9px; border-radius:6px; background:linear-gradient(90deg,#16a34a,#22c55e); min-width:4px; }
  .track .seg.live{ background:linear-gradient(90deg,#16a34a,#4ade80); animation:glow 1.6s infinite; }
  .track .seg.auto{ background:repeating-linear-gradient(45deg,#b45309 0 6px,#f59e0b 6px 12px); opacity:.8; }
  @keyframes glow{ 50%{ filter:brightness(1.3); } }
  .sess{
    display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;
    background:rgba(0,0,0,.22); border:1px solid rgba(255,255,255,.05);
    border-radius:13px; padding:11px 14px; margin-bottom:8px;
  }
  .sess.bad{ border-color:rgba(239,68,68,.25); }
  .sess:last-child{ margin-bottom:0; }
  .sess-left{ display:flex; flex-direction:column; gap:3px; min-width:0; }
  .sess-time{ font-size:13px; color:#e2e8f0; font-weight:700; font-variant-numeric:tabular-nums; }
  .sess-branch{ font-size:11px; color:#94a3b8; }
  .sess-right{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
  .sess-dur{ font-size:13px; font-weight:800; color:#a3e635; font-variant-numeric:tabular-nums; }
  .sess-dur.x{ color:#64748b; text-decoration:line-through; }
  .badge{ padding:3px 9px; border-radius:999px; font-size:10px; font-weight:800; white-space:nowrap; }
  .badge.active{ background:rgba(34,197,94,.15); color:#22c55e; border:1px solid rgba(34,197,94,.3); }
  .badge.done{ background:rgba(255,255,255,.06); color:#94a3b8; }
  .badge.auto{ background:rgba(245,158,11,.15); color:#fbbf24; border:1px solid rgba(245,158,11,.3); }
  .badge.late{ background:rgba(245,158,11,.15); color:#fbbf24; border:1px solid rgba(245,158,11,.3); }
  .badge.denied{ background:rgba(239,68,68,.15); color:#f87171; border:1px solid rgba(239,68,68,.3); }
  .loc-link{ color:#5aa9ff; text-decoration:none; font-size:11px; font-weight:700; }
  .loc-link:hover{ text-decoration:underline; }

  .empty{
    background:rgba(15,23,42,.88); border:1px solid rgba(255,255,255,.08);
    border-radius:20px; padding:50px 30px; text-align:center; color:#64748b; backdrop-filter:blur(20px);
  }
  .empty h2{ font-size:18px; margin-bottom:8px; color:#94a3b8; }
  .nomatch{ display:none; }

  .back-link{ display:block; text-align:center; margin-top:20px; color:#64748b; font-size:13px; text-decoration:none; font-weight:700; }
  .back-link:hover{ color:#94a3b8; }

  /* Settings sheet */
  .ov{ position:fixed; inset:0; z-index:50; background:rgba(2,6,23,.78); backdrop-filter:blur(8px); display:none; align-items:center; justify-content:center; padding:16px; }
  .ov.on{ display:flex; }
  .sheet{ width:100%; max-width:460px; max-height:90vh; overflow-y:auto; border-radius:22px; padding:20px; background:linear-gradient(170deg,#121a33,#0a1122); border:1px solid rgba(168,85,247,.3); }
  .sheet h3{ font-size:17px; font-weight:900; margin-bottom:4px; }
  .sheet .hint{ font-size:12px; color:#94a3b8; margin-bottom:14px; line-height:1.6; }
  .fl{ margin-bottom:14px; }
  .fl > label{ display:block; font-size:12px; font-weight:800; color:#cbd5e1; margin-bottom:6px; }
  .fl input[type=time],.fl input[type=number]{ width:100%; background:#0d1526; border:1px solid rgba(255,255,255,.1); color:#f1f5f9; padding:10px 12px; border-radius:11px; font-family:inherit; font-size:14px; outline:none; }
  .fl small{ display:block; font-size:11px; color:#64748b; margin-top:4px; }
  .sw{ display:flex; align-items:center; gap:10px; font-size:13px; font-weight:700; cursor:pointer; }
  .sw input{ width:18px; height:18px; accent-color:#22c55e; }
  .dchips{ display:flex; flex-wrap:wrap; gap:6px; }
  .dchips label{ cursor:pointer; }
  .dchips input{ display:none; }
  .dchips span{ display:inline-block; padding:7px 12px; border-radius:999px; border:1px solid rgba(255,255,255,.12); font-size:12px; font-weight:800; color:#cbd5e1; }
  .dchips input:checked + span{ background:rgba(239,68,68,.18); border-color:rgba(239,68,68,.45); color:#fca5a5; }
  .sheet-acts{ display:flex; gap:8px; margin-top:6px; }
  .sheet-acts button,.sheet-acts a{ flex:1; height:44px; border-radius:12px; border:0; font-family:inherit; font-size:14px; font-weight:800; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; text-decoration:none; }
  .sheet-acts .ok{ background:linear-gradient(135deg,#16a34a,#22c55e); color:#fff; }
  .sheet-acts .no{ background:rgba(255,255,255,.06); color:#cbd5e1; }
  .sheet .alerts{ display:block; margin-top:12px; font-size:12px; color:#a5b4fc; text-align:center; text-decoration:none; font-weight:700; }

  @media (max-width:760px){
    .board{ grid-template-columns:1fr; }
    .stats{ grid-template-columns:repeat(2,1fr); }
    .stats .stat:first-child{ grid-column:1 / -1; }
    .person-head{ padding:14px; gap:10px; }
    .p-avatar{ width:40px; height:40px; font-size:17px; }
    .p-total-num{ font-size:16px; }
    .sess{ padding:10px 12px; }
  }
  @media print{
    body{ background:#fff; color:#000; padding:0; }
    .lang-switch,.filters,.quick,.back-link,.p-chevron,.ov,.board{ display:none !important; }
    .topbar,.stat,.person,.sess,.bcol{ background:#fff !important; border-color:#ccc !important; color:#000; backdrop-filter:none; }
    .person-body{ display:block !important; }
    .person{ break-inside:avoid; }
    .stat-lbl,.p-meta,.sess-branch,.sess-time{ color:#333 !important; }
  }
</style>
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <div>
      <h1>🕐 <?= $isRTL ? 'سجل البصمة' : 'Attendance Log' ?></h1>
      <div class="sub">
        <?= htmlspecialchars(dayLabel($today)) ?>
        <?php if ($set['start'] !== ''): ?> · <?= $isRTL ? 'الموعد' : 'Start' ?> <?= htmlspecialchars(fmtT($today . ' ' . $set['start'])) ?> (+<?= $set['grace'] ?> <?= $isRTL ? 'د' : 'min' ?>)<?php endif; ?>
      </div>
    </div>
    <div class="lang-switch">
      <button type="button" id="openSet" title="<?= $isRTL ? 'الإعدادات' : 'Settings' ?>">⚙️</button>
      <button type="button" onclick="window.print()" title="<?= $isRTL ? 'طباعة' : 'Print' ?>">🖨️</button>
      <a href="branch_geo.php?lang=<?= htmlspecialchars($lang) ?>" title="<?= $isRTL ? 'مواقع الفروع' : 'Branch locations' ?>">📍</a>
      <a href="<?= htmlspecialchars($qs(['lang' => 'ar'])) ?>" class="<?= $isRTL ? 'lang-active' : '' ?>">ع</a>
      <a href="<?= htmlspecialchars($qs(['lang' => 'en'])) ?>" class="<?= !$isRTL ? 'lang-active' : '' ?>">EN</a>
    </div>
  </div>

  <?php if ($flash === 'saved'): ?><div class="flash">✓ <?= $isRTL ? 'تم حفظ الإعدادات' : 'Settings saved' ?></div><?php endif; ?>
  <?php if ($swapped): ?><div class="note">↔️ <?= $isRTL ? 'تاريخ البداية كان بعد تاريخ النهاية — تم عكسهما' : 'The start date was after the end date — they were swapped' ?></div><?php endif; ?>

  <!-- Today board -->
  <div class="board">
    <div class="bcol live" style="--c:#22c55e">
      <h3><span class="dot"></span><?= $isRTL ? 'في العمل الآن' : 'At work now' ?><b><?= $activeNow ?></b></h3>
      <div class="bl">
        <?php if (!$activeRows): ?><div class="bempty"><?= $isRTL ? 'لا أحد مسجّل حضور الآن' : 'Nobody is clocked in' ?></div><?php endif; ?>
        <?php foreach ($activeRows as $r): $lt = att_late_min($set, (string)($todayBy[(int)$r['user_id']]['first_in'] ?? '')); ?>
        <div class="bi">
          <span class="av"><?= htmlspecialchars(mb_strtoupper(mb_substr((string)$r['username'], 0, 1))) ?></span>
          <span class="nm"><?= htmlspecialchars((string)$r['username']) ?> <?php if ($lt): ?><span class="lt">⏰ <?= $lt ?><?= $isRTL ? 'د' : 'm' ?></span><?php endif; ?>
            <small>📍 <?= htmlspecialchars((string)(($isRTL ? $r['name_ar'] : $r['name_en']) ?: $r['branch_name'])) ?> · <?= htmlspecialchars(fmtT($r['clock_in'])) ?></small></span>
          <span class="tm" data-live="<?= max(0, (int)$r['el']) ?>"><?= fmtDur(max(0, (int)$r['el'])) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="bcol" style="--c:#38bdf8">
      <h3><span class="dot"></span><?= $isRTL ? 'انصرفوا اليوم' : 'Finished today' ?><b><?= count($finished) ?></b></h3>
      <div class="bl">
        <?php if (!$finished): ?><div class="bempty"><?= $isRTL ? 'لا يوجد بعد' : 'None yet' ?></div><?php endif; ?>
        <?php foreach ($finished as $r): ?>
        <div class="bi">
          <span class="av"><?= htmlspecialchars(mb_strtoupper(mb_substr((string)$r['username'], 0, 1))) ?></span>
          <span class="nm"><?= htmlspecialchars((string)$r['username']) ?><small><?= htmlspecialchars(fmtT($r['first_in'])) ?> → <?= htmlspecialchars(fmtT($r['last_out'])) ?></small></span>
          <span class="tm"><?= fmtDur(max(0, (int)$r['secs'])) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="bcol" style="--c:<?= $todayOff ? '#a855f7' : '#f87171' ?>">
      <h3><span class="dot"></span><?= $isRTL ? 'لم يحضروا اليوم' : "Didn't come today" ?><b><?= $todayOff ? '—' : count($absent) ?></b></h3>
      <div class="bl">
        <?php if ($todayOff): ?><div class="bempty">🌙 <?= $isRTL ? 'اليوم إجازة أسبوعية' : 'Today is a weekly day off' ?></div>
        <?php elseif (!$absent): ?><div class="bempty">🎉 <?= $isRTL ? 'الكل حضر' : 'Everyone came in' ?></div><?php endif; ?>
        <?php if (!$todayOff) foreach ($absent as $u): ?>
        <div class="bi">
          <span class="av" style="background:linear-gradient(135deg,#7f1d1d,#ef4444)"><?= htmlspecialchars(mb_strtoupper(mb_substr((string)$u['username'], 0, 1))) ?></span>
          <span class="nm"><?= htmlspecialchars((string)$u['username']) ?><small><?= htmlspecialchars($roleName($u['role'])) ?></small></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="stats">
    <div class="stat"><div class="stat-num purple"><?= fmtDur($grandTotal) ?></div><div class="stat-lbl"><?= $isRTL ? 'إجمالي الفترة' : 'Range total' ?></div><div class="stat-sub"><?= $isRTL ? 'بدون الخروج التلقائي' : 'excluding auto clock-outs' ?></div></div>
    <div class="stat"><div class="stat-num green"><?= count($people) ?></div><div class="stat-lbl"><?= $isRTL ? 'موظفين' : 'Employees' ?></div></div>
    <div class="stat"><div class="stat-num sky"><?= $activeNow ?></div><div class="stat-lbl"><?= $isRTL ? 'متواجد الآن' : 'Active now' ?></div></div>
    <div class="stat"><div class="stat-num amber"><?= $set['start'] !== '' ? $lateCount : '—' ?></div><div class="stat-lbl"><?= $isRTL ? 'تأخير' : 'Late arrivals' ?></div></div>
    <div class="stat"><div class="stat-num red"><?= $issueCount ?></div><div class="stat-lbl"><?= $isRTL ? 'ملاحظات' : 'Issues' ?></div><?php if ($autoCount): ?><div class="stat-sub">⏰ <?= $autoCount ?> <?= $isRTL ? 'نسي الخروج' : 'forgot to clock out' ?></div><?php endif; ?></div>
  </div>

  <form class="filters" method="GET">
    <input type="hidden" name="lang" value="<?= htmlspecialchars($lang) ?>">
    <?php if ($onlyIssues): ?><input type="hidden" name="issues" value="1"><?php endif; ?>
    <input type="date" name="from" value="<?= htmlspecialchars($filterFrom) ?>" max="<?= htmlspecialchars($today) ?>">
    <input type="date" name="to" value="<?= htmlspecialchars($filterTo) ?>" max="<?= htmlspecialchars($today) ?>">
    <select name="branch">
      <option value=""><?= $isRTL ? 'كل الفروع' : 'All Branches' ?></option>
      <?php foreach ($branches as $b): ?>
        <option value="<?= htmlspecialchars($b['name']) ?>" <?= $filterBranch === $b['name'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($isRTL ? $b['name_ar'] : $b['name_en']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <select name="user_id">
      <option value=""><?= $isRTL ? 'كل الموظفين' : 'All Employees' ?></option>
      <?php foreach ($users as $u): ?>
        <option value="<?= $u['id'] ?>" <?= $filterUser == $u['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($u['username']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit"><?= $isRTL ? 'تصفية' : 'Filter' ?></button>
    <a class="dl-btn" href="attendance_export.php?lang=<?= htmlspecialchars($lang) ?>&from=<?= htmlspecialchars($filterFrom) ?>&to=<?= htmlspecialchars($filterTo) ?><?= $filterBranch !== '' ? '&branch=' . urlencode($filterBranch) : '' ?><?= $filterUser !== '' ? '&user_id=' . urlencode($filterUser) : '' ?>">
      ⬇ <?= $isRTL ? 'تحميل Excel' : 'Download Excel' ?>
    </a>
  </form>

  <div class="quick">
    <?php foreach (['today' => ['اليوم', 'Today'], 'yday' => ['أمس', 'Yesterday'], 'week' => ['هذا الأسبوع', 'This week'], 'month' => ['هذا الشهر', 'This month']] as $k => $lb): ?>
      <a href="<?= htmlspecialchars($qs(['from' => $quick[$k][0], 'to' => $quick[$k][1]])) ?>" class="<?= $quickOn === $k ? 'on' : '' ?>"><?= $isRTL ? $lb[0] : $lb[1] ?></a>
    <?php endforeach; ?>
    <a href="<?= htmlspecialchars($qs(['issues' => $onlyIssues ? '' : 1])) ?>" class="iss <?= $onlyIssues ? 'on' : '' ?>">⚠️ <?= $isRTL ? 'الملاحظات فقط' : 'Issues only' ?></a>
    <input class="srch" id="srch" type="search" placeholder="🔍 <?= $isRTL ? 'ابحث عن موظف…' : 'Find an employee…' ?>">
  </div>

  <?php if (!$people): ?>
    <div class="empty">
      <h2><?= $onlyIssues ? '✅ ' . ($isRTL ? 'لا توجد ملاحظات في هذه الفترة' : 'No issues in this period') : '📭 ' . ($isRTL ? 'لا توجد سجلات' : 'No records') ?></h2>
      <p><?= $isRTL ? 'جرّب تغيير التاريخ أو الفلاتر' : 'Try changing the date or filters' ?></p>
    </div>
  <?php else: foreach ($people as $uid => $p):
      $initial = mb_strtoupper(mb_substr($p['name'], 0, 1));
      $nDays = count($p['days']);
      $worked = array_filter(array_map(fn($ss) => array_sum(array_map(fn($l) => empty($l['auto_closed']) ? max(0, (int)$l['dur_secs']) : 0, $ss)), $p['days']));
      $avg = $worked ? intdiv(array_sum($worked), count($worked)) : 0;
  ?>
    <div class="person <?= $p['active'] ? 'is-active' : '' ?>" data-person data-name="<?= htmlspecialchars(mb_strtolower($p['name'])) ?>">
      <div class="person-head" onclick="this.parentElement.classList.toggle('open')">
        <div class="p-avatar"><?= htmlspecialchars($initial) ?></div>
        <div class="p-info">
          <div class="p-name">
            <?= htmlspecialchars($p['name']) ?>
            <?php if ($p['role']): ?><span class="p-role"><?= htmlspecialchars($roleName($p['role'])) ?></span><?php endif; ?>
            <?php if ($p['active']): ?><span class="p-live">● <?= $isRTL ? 'متواجد' : 'LIVE' ?></span><?php endif; ?>
          </div>
          <div class="p-meta">
            <span><?= count($p['sessions']) ?> <?= $isRTL ? 'جلسة' : 'sessions' ?></span>
            <?php if ($oneDay): ?>
              <span>🟢 <?= htmlspecialchars(fmtT($p['first'])) ?></span>
              <?php if ($p['last'] && !$p['active']): ?><span>🔵 <?= htmlspecialchars(fmtT($p['last'])) ?></span><?php endif; ?>
            <?php else: ?>
              <span>📅 <?= $nDays ?> <?= $isRTL ? ($nDays === 1 ? 'يوم' : 'أيام') : ($nDays === 1 ? 'day' : 'days') ?><?= $daysInRange > 1 ? ' / ' . $daysInRange : '' ?></span>
              <?php if ($avg): ?><span>⌀ <?= fmtDur($avg) ?> <?= $isRTL ? 'يومياً' : '/ day' ?></span><?php endif; ?>
            <?php endif; ?>
          </div>
          <?php if ($p['late'] || $p['auto'] || ($p['issues'] - $p['late'] - $p['auto']) > 0): ?>
          <div class="p-flags">
            <?php if ($p['late']): ?><span class="badge late">⏰ <?= $isRTL ? 'تأخير' : 'Late' ?> <?= $p['late'] > 1 ? '×' . $p['late'] . ' · ' : '' ?><?= $p['lateMin'] ?> <?= $isRTL ? 'د' : 'min' ?></span><?php endif; ?>
            <?php if ($p['auto']): ?><span class="badge auto">⏰ <?= $isRTL ? 'نسي الخروج' : 'Forgot clock-out' ?><?= $p['auto'] > 1 ? ' ×' . $p['auto'] : '' ?></span><?php endif; ?>
            <?php $other = count(array_filter($p['sessions'], fn($l) => $l['_far'] || !empty($l['in_loc_denied']) || ($l['clock_out'] && !empty($l['out_loc_denied'])))); if ($other): ?><span class="badge denied">🛑 <?= $isRTL ? 'موقع' : 'Location' ?> ×<?= $other ?></span><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <div class="p-total">
          <div class="p-total-num" <?= $p['active'] ? 'data-live="' . $p['total'] . '"' : '' ?>><?= fmtDur($p['total']) ?></div>
          <div class="p-total-lbl"><?= $isRTL ? 'إجمالي' : 'total' ?></div>
        </div>
        <span class="p-chevron">▼</span>
      </div>

      <div class="person-body">
        <?php foreach ($p['days'] as $day => $sessions):
          $daySecs = array_sum(array_map(fn($l) => empty($l['auto_closed']) ? max(0, (int)$l['dur_secs']) : 0, $sessions));
        ?>
        <div class="day">
          <div class="day-h"><?= htmlspecialchars(dayLabel($day)) ?> <span><?= fmtDur($daySecs) ?></span></div>
          <div class="track" aria-hidden="true">
            <?php for ($h = $BAR_FROM + 2; $h < $BAR_FROM + $BAR_HOURS; $h += 4): ?>
              <span class="hr" style="inset-inline-start:<?= round(($h - $BAR_FROM) / $BAR_HOURS * 100, 2) ?>%"><i><?= ($h > 12 ? $h - 12 : $h) . ($isRTL ? ($h < 12 ? 'ص' : 'م') : ($h < 12 ? 'a' : 'p')) ?></i></span>
            <?php endfor; ?>
            <?php if ($set['start'] !== '' && !att_is_off($set, $day)): ?><span class="st" style="inset-inline-start:<?= round($barPct($day, $day . ' ' . $set['start'] . ':00'), 2) ?>%"></span><?php endif; ?>
            <?php foreach ($sessions as $log):
              $a = $barPct($day, $log['clock_in']);
              $b = $barPct($day, $log['clock_out'] ?: $nowStr);
            ?>
              <span class="seg <?= $log['status'] === 'active' ? 'live' : '' ?> <?= !empty($log['auto_closed']) ? 'auto' : '' ?>" style="inset-inline-start:<?= round($a, 2) ?>%;width:<?= round(max(0.6, $b - $a), 2) ?>%" title="<?= htmlspecialchars(fmtT($log['clock_in']) . ' → ' . ($log['clock_out'] ? fmtT($log['clock_out']) : '…')) ?>"></span>
            <?php endforeach; ?>
          </div>
          <?php foreach ($sessions as $log):
            $branchDisp = $isRTL ? ($log['branch_ar'] ?? $log['branch_name']) : ($log['branch_en'] ?? $log['branch_name']);
            $outDisp = $log['out_branch_name'] ? ($isRTL ? ($log['out_ar'] ?? $log['out_branch_name']) : ($log['out_en'] ?? $log['out_branch_name'])) : '';
            $secs = max(0, (int)$log['dur_secs']);
          ?>
          <div class="sess <?= $log['_issue'] ? 'bad' : '' ?>">
            <div class="sess-left">
              <div class="sess-time">
                <?= htmlspecialchars(fmtT($log['clock_in'])) ?>
                <?= $log['clock_out'] ? ' → ' . htmlspecialchars(fmtT($log['clock_out'])) : ' → …' ?>
              </div>
              <?php if ($branchDisp): ?>
                <div class="sess-branch">📍 <?= htmlspecialchars($branchDisp) ?><?php if ($outDisp !== '' && $log['out_branch_name'] !== $log['branch_name']): ?> ← <?= $isRTL ? 'خروج من' : 'out at' ?> <?= htmlspecialchars($outDisp) ?><?php endif; ?></div>
              <?php endif; ?>
            </div>
            <div class="sess-right">
              <span class="sess-dur <?= !empty($log['auto_closed']) ? 'x' : '' ?>" <?= $log['status'] === 'active' ? 'data-live="' . $secs . '"' : '' ?> <?= !empty($log['auto_closed']) ? 'title="' . ($isRTL ? 'لا تُحسب — خروج تلقائي' : 'Not counted — auto clock-out') . '"' : '' ?>><?= fmtDur($secs) ?></span>
              <?php if ($log['_late']): ?>
                <span class="badge late">⏰ <?= $isRTL ? 'متأخر' : 'Late' ?> <?= (int)$log['_late'] ?> <?= $isRTL ? 'د' : 'min' ?></span>
              <?php endif; ?>
              <?php if (!empty($log['auto_closed'])): ?>
                <span class="badge auto">⏰ <?= $isRTL ? 'نسي الخروج' : 'Forgot clock-out' ?></span>
              <?php elseif ($log['status'] === 'active'): ?>
                <span class="badge active">● <?= $isRTL ? 'متواجد' : 'Active' ?></span>
              <?php else: ?>
                <span class="badge done"><?= $isRTL ? 'منتهي' : 'Done' ?></span>
              <?php endif; ?>

              <?php if ($log['clock_in_lat'] !== null): ?>
                <a class="loc-link" target="_blank" rel="noopener" href="https://www.google.com/maps?q=<?= $log['clock_in_lat'] ?>,<?= $log['clock_in_lng'] ?>">📍<?= $isRTL ? 'دخول' : 'In' ?></a>
              <?php elseif ($log['in_loc_denied']): ?>
                <span class="badge denied">⚠️<?= $isRTL ? 'رفض' : 'In?' ?></span>
              <?php endif; ?>
              <?php if ($log['clock_out']): ?>
                <?php if ($log['clock_out_lat'] !== null): ?>
                  <a class="loc-link" target="_blank" rel="noopener" href="https://www.google.com/maps?q=<?= $log['clock_out_lat'] ?>,<?= $log['clock_out_lng'] ?>">📍<?= $isRTL ? 'خروج' : 'Out' ?></a>
                <?php elseif ($log['out_loc_denied']): ?>
                  <span class="badge denied">⚠️<?= $isRTL ? 'رفض' : 'Out?' ?></span>
                <?php endif; ?>
              <?php endif; ?>

              <?php if (in_array('in', $log['_far'], true)): ?>
                <span class="badge denied">🛑 <?= $isRTL ? 'دخول بعيد' : 'In far' ?> <?= (int)$log['in_dist_m'] ?>m</span>
              <?php endif; ?>
              <?php if (in_array('out', $log['_far'], true)): ?>
                <span class="badge denied">🛑 <?= $isRTL ? 'خروج بعيد' : 'Out far' ?> <?= (int)$log['out_dist_m'] ?>m</span>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
    <div class="empty nomatch" id="nomatch"><h2>🔍 <?= $isRTL ? 'لا يوجد موظف بهذا الاسم' : 'No employee matches' ?></h2></div>
  <?php endif; ?>

  <a class="back-link" href="dashboard.php?lang=<?= htmlspecialchars($lang) ?>">← <?= $isRTL ? 'الرئيسية' : 'Dashboard' ?></a>
</div>

<!-- Settings -->
<div class="ov" id="setOv">
  <form class="sheet" method="POST" action="<?= htmlspecialchars('attendance_admin.php' . $qs()) ?>">
    <input type="hidden" name="action" value="settings">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <h3>⚙️ <?= $isRTL ? 'إعدادات الحضور' : 'Attendance settings' ?></h3>
    <p class="hint"><?= $isRTL ? 'موعد بداية العمل يُستخدم لحساب التأخير. الإجازة الأسبوعية لا يُحسب فيها تأخير ولا غياب.' : 'The start time is used for late arrivals. Weekly days off count no lateness or absence.' ?></p>
    <div class="fl">
      <label class="sw"><input type="checkbox" name="late_on" value="1" id="lateOn" <?= $set['start'] !== '' ? 'checked' : '' ?>> <?= $isRTL ? 'حساب التأخير' : 'Track late arrivals' ?></label>
    </div>
    <div class="fl" id="lateBox">
      <label><?= $isRTL ? 'موعد بداية العمل' : 'Work start time' ?></label>
      <input type="time" name="start" value="<?= htmlspecialchars($set['start'] !== '' ? $set['start'] : '10:00') ?>">
    </div>
    <div class="fl" id="graceBox">
      <label><?= $isRTL ? 'دقائق السماح' : 'Grace minutes' ?></label>
      <input type="number" name="grace" min="0" max="180" value="<?= (int)$set['grace'] ?>">
      <small><?= $isRTL ? 'مثال: 15 = الحضور حتى 10:15 لا يُعتبر تأخيراً' : 'e.g. 15 = arriving by 10:15 is not late' ?></small>
    </div>
    <div class="fl">
      <label><?= $isRTL ? 'الإجازة الأسبوعية' : 'Weekly days off' ?></label>
      <div class="dchips">
        <?php foreach ($wdNames as $n => $wn): ?>
          <label><input type="checkbox" name="off[]" value="<?= $n ?>" <?= in_array($n, $set['off'], true) ? 'checked' : '' ?>><span><?= $wn ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="fl">
      <label><?= $isRTL ? 'المسافة المسموحة من الفرع (متر)' : 'Allowed distance from branch (metres)' ?></label>
      <input type="number" name="fence" min="10" max="5000" value="<?= (int)$set['fence'] ?>">
      <small><?= $isRTL ? 'البصمة أبعد من ذلك تظهر بعلامة 🛑' : 'Punches farther than this get a 🛑 flag' ?></small>
    </div>
    <div class="sheet-acts">
      <button type="submit" class="ok">💾 <?= $isRTL ? 'حفظ' : 'Save' ?></button>
      <button type="button" class="no" id="closeSet"><?= $isRTL ? 'إلغاء' : 'Cancel' ?></button>
    </div>
    <?php if (can('page.notifications_admin')): ?>
      <a class="alerts" href="notifications_admin.php?lang=<?= htmlspecialchars($lang) ?>">🔔 <?= $isRTL ? 'تنبيهات الموبايل للتأخير والبصمة البعيدة ←' : 'Phone alerts for late and far punches →' ?></a>
    <?php endif; ?>
  </form>
</div>

<script>
// Auto-expand the first (top) employee card, and anyone currently active
document.querySelectorAll('[data-person]').forEach((el, i) => {
  if (i === 0 || el.classList.contains('is-active')) el.classList.add('open');
});

(function () {
  const AR = <?= json_encode($isRTL) ?>;
  const fmt = s => { const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60); return AR ? h + 'س ' + String(m).padStart(2, '0') + 'د' : h + 'h ' + String(m).padStart(2, '0') + 'm'; };

  // live timers for people at work right now
  const live = [...document.querySelectorAll('[data-live]')].map(el => ({ el, base: +el.dataset.live }));
  const t0 = Date.now();
  if (live.length) setInterval(() => { const add = Math.floor((Date.now() - t0) / 1000); live.forEach(x => x.el.textContent = fmt(x.base + add)); }, 20000);

  // employee search
  const s = document.getElementById('srch'), cards = [...document.querySelectorAll('[data-person]')], nm = document.getElementById('nomatch');
  if (s) s.addEventListener('input', () => {
    const q = s.value.trim().toLowerCase(); let n = 0;
    cards.forEach(c => { const on = !q || c.dataset.name.includes(q); c.style.display = on ? '' : 'none'; if (on) n++; });
    if (nm) nm.style.display = n ? 'none' : 'block';
  });

  // settings sheet
  const ov = document.getElementById('setOv');
  document.getElementById('openSet').addEventListener('click', () => ov.classList.add('on'));
  document.getElementById('closeSet').addEventListener('click', () => ov.classList.remove('on'));
  ov.addEventListener('click', e => { if (e.target === ov) ov.classList.remove('on'); });
  const lateOn = document.getElementById('lateOn');
  const syncLate = () => ['lateBox', 'graceBox'].forEach(id => document.getElementById(id).style.opacity = lateOn.checked ? 1 : .4);
  lateOn.addEventListener('change', syncLate); syncLate();
})();
</script>
</body>
</html>
