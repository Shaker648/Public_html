<?php
/* ============================================================
   attendance.php — Employee Clock In / Clock Out (بصمة)
   Redesigned to match First 1 Car dashboard aesthetic.
   ============================================================ */

require 'auth.php';
require 'config.php';
// phone alerts are optional: clocking in must work even if push_helpers.php is missing or old
if (is_file(__DIR__ . '/push_helpers.php')) require_once __DIR__ . '/push_helpers.php';
/* ---------------- Attendance settings (kept inside this page, no extra file needed) ---------------- */
if (!function_exists('att_settings')) {
    function att_settings_table(PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            setting_key   VARCHAR(64) PRIMARY KEY,
            setting_value TEXT,
            updated_by    VARCHAR(64),
            updated_at    DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    }
    /** Saved settings, with defaults: no weekly days off, 50 m geofence. */
    function att_settings(PDO $pdo): array
    {
        $s = [];
        try {
            att_settings_table($pdo);
            $q = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'attendance_settings' LIMIT 1");
            $q->execute();
            $s = json_decode((string)$q->fetchColumn(), true);
        } catch (Throwable $e) { error_log('att_settings: ' . $e->getMessage()); }
        $s = is_array($s) ? $s : [];
        return [
            'off'   => array_values(array_unique(array_intersect(array_map('intval', (array)($s['off'] ?? [])), range(0, 6)))),   // 0 = Sunday … 6 = Saturday
            'fence' => max(10, min(5000, (int)($s['fence'] ?? 50))),
            'target' => max(1, min(16, (float)($s['target'] ?? 10))),   // daily hours: the ring fills to this, then turns gold
        ];
    }
    function att_settings_save(PDO $pdo, array $in, string $by): void
    {
        att_settings_table($pdo);
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value, updated_by, updated_at) VALUES ('attendance_settings', ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()")
            ->execute([json_encode([
                'off'   => array_values(array_unique(array_intersect(array_map('intval', (array)($in['off'] ?? [])), range(0, 6)))),
                'fence' => max(10, min(5000, (int)($in['fence'] ?? 50))),
                'target' => max(1, min(16, (float)($in['target'] ?? 10))),
            ]), $by]);
    }
    /** Is this date (Y-m-d) one of the weekly days off? */
    function att_is_off(array $set, string $date): bool
    {
        return in_array((int)date('w', strtotime($date)), $set['off'], true);
    }
}
$attAlerts = function_exists('notify_event');

perm_require('page.attendance');

ini_set('display_errors', 0);

$lang = $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar','en'])) $lang = 'ar';

$userId   = $_SESSION['user_id'];
$userName = $_SESSION['username'];
$role     = $_SESSION['role'] ?? '';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$back = function (string $q = '') use ($lang) { header('Location: attendance.php?lang=' . urlencode($lang) . $q); exit; };

/* ---------------- Geofence helpers ---------------- */
function haversine_m($lat1, $lng1, $lat2, $lng2) {
    $R = 6371000; // earth radius in metres
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2)*sin($dLat/2) + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLng/2)*sin($dLng/2);
    return (int) round($R * 2 * atan2(sqrt($a), sqrt(1 - $a)));
}
function branchDistance($pdo, $branchName, $lat, $lng) {
    if ($lat === null || $lng === null || $branchName === '') return null;
    $q = $pdo->prepare("SELECT lat, lng FROM branches WHERE name = ? LIMIT 1");
    $q->execute([$branchName]);
    $b = $q->fetch(PDO::FETCH_ASSOC);
    if (!$b || $b['lat'] === null || $b['lng'] === null) return null; // branch has no coords set yet
    return haversine_m((float)$lat, (float)$lng, (float)$b['lat'], (float)$b['lng']);
}

/* ---------------- Handle Clock In / Clock Out POST ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // the page's own token — a link from outside can't punch for someone
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $back('&err=expired');
    $lat = (isset($_POST['lat']) && $_POST['lat'] !== '') ? (float)$_POST['lat'] : null;
    $lng = (isset($_POST['lng']) && $_POST['lng'] !== '') ? (float)$_POST['lng'] : null;
    $locDenied = ($lat === null || $lng === null) ? 1 : 0;

    // Location is MANDATORY — refuse the punch entirely if coordinates are missing.
    // (Server-side guard so disabling JS can't bypass it.)
    if ($lat === null || $lng === null) {
        header('Location: attendance.php?lang=' . urlencode($lang) . '&err=loc');
        exit;
    }

    if ($_POST['action'] === 'clock_in') {
        $branchName = trim($_POST['branch_name'] ?? '');
        if ($branchName === '') {
            header('Location: attendance.php?lang=' . urlencode($lang) . '&err=branch');
            exit;
        }
        $inDist = branchDistance($pdo, $branchName, $lat, $lng);
        $check = $pdo->prepare("SELECT id FROM attendance_logs WHERE user_id = ? AND status = 'active' LIMIT 1");
        $check->execute([$userId]);
        if ($check->fetch()) {
            $_SESSION['att_done'] = ['type' => 'already_in'];   // clocked in already (e.g. from another phone / an old page)
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO attendance_logs
                 (user_id, branch_name, clock_in, clock_in_lat, clock_in_lng, in_loc_denied, in_dist_m, status)
                 VALUES (?, ?, NOW(), ?, ?, ?, ?, 'active')"
            );
            $stmt->execute([$userId, $branchName, $lat, $lng, $locDenied, $inDist]);
            $newId = (int)$pdo->lastInsertId();
            $q = $pdo->prepare("SELECT clock_in FROM attendance_logs WHERE id = ?");
            $q->execute([$newId]);
            $_SESSION['att_done'] = ['type' => 'in', 'time' => (string)$q->fetchColumn(), 'branch' => $branchName];

            // phone alerts (who gets them is set on notifications_admin.php)
            if ($attAlerts) try {
            $set = att_settings($pdo);
            notify_event($pdo, 'att_in', ['actor' => $userName, 'branch' => $branchName, 'time' => $_SESSION['att_done']['time'],
                                          'dist' => $inDist, 'far' => $inDist !== null && $inDist > $set['fence']]);
            } catch (Throwable $e) { error_log('attendance alert: ' . $e->getMessage()); }
            // cars waiting at this branch for «استلمت»: the time to confirm starts now
            if (is_file(__DIR__ . '/notify_smart.php')) try {
                require_once __DIR__ . '/notify_smart.php';
                smart_duty_scan($pdo);
            } catch (Throwable $e) { error_log('attendance duty: ' . $e->getMessage()); }
        }
    } elseif ($_POST['action'] === 'clock_out') {
        $outBranch = trim($_POST['branch_name'] ?? '');
        if ($outBranch === '') {
            header('Location: attendance.php?lang=' . urlencode($lang) . '&err=branch');
            exit;
        }
        $outDist = branchDistance($pdo, $outBranch, $lat, $lng);
        $cur = $pdo->prepare("SELECT id FROM attendance_logs WHERE user_id = ? AND status = 'active' LIMIT 1");
        $cur->execute([$userId]);
        $curId = (int)$cur->fetchColumn();
        $stmt = $pdo->prepare(
            "UPDATE attendance_logs
             SET clock_out = NOW(), clock_out_lat = ?, clock_out_lng = ?, out_loc_denied = ?,
                 out_branch_name = ?, out_dist_m = ?, status = 'completed'
             WHERE user_id = ? AND status = 'active'"
        );
        $stmt->execute([$lat, $lng, $locDenied, $outBranch, $outDist, $userId]);
        if ($curId && $stmt->rowCount()) {
            $q = $pdo->prepare("SELECT clock_out, TIMESTAMPDIFF(SECOND, clock_in, clock_out) AS secs FROM attendance_logs WHERE id = ?");
            $q->execute([$curId]);
            $done = $q->fetch(PDO::FETCH_ASSOC) ?: ['clock_out' => '', 'secs' => 0];
            $_SESSION['att_done'] = ['type' => 'out', 'time' => (string)$done['clock_out'], 'branch' => $outBranch, 'secs' => (int)$done['secs']];
        } else {
            $_SESSION['att_done'] = ['type' => 'not_in'];   // already clocked out (old page)
        }

        if ($attAlerts && $curId && $stmt->rowCount()) try {
            $row = $done;
            $set = att_settings($pdo);
            notify_event($pdo, 'att_out', ['actor' => $userName, 'branch' => $outBranch, 'time' => $row['clock_out'], 'secs' => (int)$row['secs'],
                                           'dist' => $outDist, 'far' => $outDist !== null && $outDist > $set['fence']]);
        } catch (Throwable $e) { error_log('attendance alert: ' . $e->getMessage()); }
    }
    $back();
}
$flash = $_SESSION['att_done'] ?? null; unset($_SESSION['att_done']);

/* ---------------- Auto clock-out forgotten sessions (>14h) ---------------- */
$pdo->exec(
    "UPDATE attendance_logs
     SET clock_out = DATE_ADD(clock_in, INTERVAL 14 HOUR),
         status = 'completed', auto_closed = 1
     WHERE status = 'active' AND clock_in < DATE_SUB(NOW(), INTERVAL 14 HOUR)"
);

/* ---------------- Current active session ---------------- */
$stmt = $pdo->prepare(
    "SELECT id, clock_in, branch_name,
            TIMESTAMPDIFF(SECOND, clock_in, NOW()) AS elapsed_secs
     FROM attendance_logs WHERE user_id = ? AND status = 'active' LIMIT 1"
);
$stmt->execute([$userId]);
$active = $stmt->fetch(PDO::FETCH_ASSOC);

$isClockedIn = (bool)$active;
$elapsedSecs = $isClockedIn ? max(0, (int)$active['elapsed_secs']) : 0;

$branches = $pdo->query("SELECT name, name_ar, name_en, lat, lng FROM branches ORDER BY name_en")->fetchAll(PDO::FETCH_ASSOC);
$attSet     = att_settings($pdo);
$targetSecs = (int)round($attSet['target'] * 3600);   // the day's hours: the ring fills to this, then turns gold

/* Today's completed sessions */
$todayStmt = $pdo->prepare(
    "SELECT *,
            TIMESTAMPDIFF(SECOND, clock_in, clock_out) AS dur_secs
     FROM attendance_logs
     WHERE user_id = ? AND status = 'completed' AND DATE(clock_in) = CURDATE()
     ORDER BY clock_in DESC"
);
$todayStmt->execute([$userId]);
$todayLogs = $todayStmt->fetchAll(PDO::FETCH_ASSOC);

$todayTotal = 0;
foreach ($todayLogs as $t) if (empty($t['auto_closed'])) $todayTotal += max(0, (int)$t['dur_secs']);   // a forgotten clock-out closed by the system isn't worked time
if ($isClockedIn) $todayTotal += $elapsedSecs;

/* This week (from Saturday): hours per day */
$clock  = $pdo->query("SELECT CURDATE() AS d, NOW() AS n")->fetch(PDO::FETCH_ASSOC);
$today  = $clock['d'];
$weekStart = date('Y-m-d', strtotime('-' . (((int)date('w', strtotime($today)) + 1) % 7) . ' day', strtotime($today)));
$wk = $pdo->prepare("SELECT DATE(clock_in) AS d,
                            SUM(CASE WHEN auto_closed = 1 THEN 0 ELSE TIMESTAMPDIFF(SECOND, clock_in, COALESCE(clock_out, NOW())) END) AS secs
                     FROM attendance_logs WHERE user_id = ? AND DATE(clock_in) BETWEEN ? AND ? GROUP BY DATE(clock_in)");
$wk->execute([$userId, $weekStart, $today]);
$weekBy = [];
foreach ($wk as $r) $weekBy[$r['d']] = max(0, (int)$r['secs']);
$weekTotal = array_sum($weekBy);
$weekDays  = count(array_filter($weekBy));

/* every session of the week (and today) — for the day details and today's timeline */
$ws = $pdo->prepare("SELECT DATE(clock_in) AS d, clock_in, COALESCE(clock_out, NOW()) AS clock_out, branch_name, auto_closed, status
                     FROM attendance_logs WHERE user_id = ? AND DATE(clock_in) BETWEEN ? AND ? ORDER BY clock_in");
$ws->execute([$userId, $weekStart, $today]);
$weekSess = []; $weekAuto = [];
foreach ($ws->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $weekSess[$r['d']][] = $r;
    if (!empty($r['auto_closed'])) $weekAuto[$r['d']] = true;
}
$todaySess = $weekSess[$today] ?? [];   // the week starts on Saturday, so today is always in it

/* this month, day by day — and last month up to the same day, to compare */
$monthStart = date('Y-m-01', strtotime($today));
$mq = $pdo->prepare("SELECT DATE(clock_in) AS d, SUM(CASE WHEN auto_closed = 1 THEN 0 ELSE TIMESTAMPDIFF(SECOND, clock_in, COALESCE(clock_out, NOW())) END) AS secs, MAX(auto_closed) AS auto
                     FROM attendance_logs WHERE user_id = ? AND DATE(clock_in) BETWEEN ? AND ? GROUP BY DATE(clock_in)");
$mq->execute([$userId, $monthStart, $today]);
$monthBy = []; $monthAuto = [];
foreach ($mq as $r) { $monthBy[$r['d']] = max(0, (int)$r['secs']); if ((int)$r['auto']) $monthAuto[$r['d']] = true; }
$monthTotal = array_sum($monthBy);
$monthDays  = count(array_filter($monthBy));
$prevStart  = date('Y-m-01', strtotime($monthStart . ' -1 month'));
$prevEnd    = date('Y-m-d', min(strtotime($prevStart . ' +' . ((int)date('j', strtotime($today)) - 1) . ' day'), strtotime(date('Y-m-t', strtotime($prevStart)))));
$pq = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN auto_closed = 1 THEN 0 ELSE TIMESTAMPDIFF(SECOND, clock_in, clock_out) END), 0) FROM attendance_logs WHERE user_id = ? AND DATE(clock_in) BETWEEN ? AND ?");
$pq->execute([$userId, $prevStart, $prevEnd]);
$prevTotal = (int)$pq->fetchColumn();

/* The branch they used last (pre-selected — nothing about where they are right now) */
$lb = $pdo->prepare("SELECT branch_name FROM attendance_logs WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$lb->execute([$userId]);
$lastBranch = (string)$lb->fetchColumn();

$isRTL = $lang === 'ar';
$dir   = $isRTL ? 'rtl' : 'ltr';

function fmtHM($secs) {
    global $isRTL;
    return sprintf($isRTL ? '%dس %02dد' : '%dh %02dm', floor($secs / 3600), floor(($secs % 3600) / 60));
}
function fmtT($dt) {
    global $isRTL;
    if (!$dt) return '';
    $ts = strtotime($dt);
    return date('h:i', $ts) . ($isRTL ? (date('A', $ts) === 'AM' ? ' ص' : ' م') : ' ' . date('A', $ts));
}
$roleName = $isRTL ? (['admin' => 'أدمن', 'manager' => 'مدير', 'sales' => 'مبيعات'][$role] ?? $role) : ucfirst((string)$role);
$branchLabel = function ($name) use ($branches, $isRTL) {
    foreach ($branches as $b) if ($b['name'] === $name) return $isRTL ? $b['name_ar'] : $b['name_en'];
    return (string)$name;
};

$dayFull = $isRTL ? ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'] : ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/* the week, day by day: hours, day off, forgotten clock-out, and each session (shown when a day is tapped) */
$weekView = [];
for ($i = 0; $i < 7; $i++) {
    $d = date('Y-m-d', strtotime("+$i day", strtotime($weekStart)));
    $sess = [];
    foreach ($weekSess[$d] ?? [] as $r) $sess[] = [
        'in' => fmtT($r['clock_in']), 'out' => $r['status'] === 'active' ? '' : fmtT($r['clock_out']),
        'br' => $branchLabel($r['branch_name']), 'auto' => (int)$r['auto_closed'],
        'secs' => max(0, strtotime($r['clock_out']) - strtotime($r['clock_in'])),
    ];
    $weekView[] = ['d' => $d, 'name' => $dayFull[(int)date('w', strtotime($d))], 'secs' => $weekBy[$d] ?? 0,
                   'off' => att_is_off($attSet, $d), 'auto' => !empty($weekAuto[$d]), 'future' => $d > $today, 'today' => $d === $today, 's' => $sess];
}

/* today's timeline: from 9 AM (or earlier) to 9 PM (or later) */
$tlSegs = [];
$tlFrom = strtotime($today . ' 09:00'); $tlTo = strtotime($today . ' 21:00');
foreach ($todaySess as $r) {
    $a = strtotime($r['clock_in']); $b = max($a, strtotime($r['clock_out']));
    $tlFrom = min($tlFrom, (int)(floor($a / 3600) * 3600)); $tlTo = max($tlTo, (int)(ceil($b / 3600) * 3600));
    $tlSegs[] = ['a' => $a, 'b' => $b, 'auto' => (int)$r['auto_closed'], 'live' => $r['status'] === 'active', 'br' => $branchLabel($r['branch_name']),
                 'tip' => fmtT($r['clock_in']) . ' → ' . ($r['status'] === 'active' ? ($isRTL ? 'الآن' : 'now') : fmtT($r['clock_out']))];
}
$tlFrom = max(strtotime($today . ' 00:00'), $tlFrom); $tlTo = min(strtotime($today . ' 23:59:59') + 1, $tlTo);
$tlSpan = max(3600, $tlTo - $tlFrom);
$hourLbl = function (int $ts) use ($isRTL): string { $h = (int)date('G', $ts); return ($h % 12 ?: 12) . ($isRTL ? ($h < 12 ? 'ص' : 'م') : ($h < 12 ? 'a' : 'p')); };

/* the month calendar (weeks start on Saturday, like the week chart) */
$monthCells = [];
$lead = ((int)date('w', strtotime($monthStart)) + 1) % 7;   // empty cells before the 1st
for ($i = 0; $i < $lead; $i++) $monthCells[] = null;
$daysInMonth = (int)date('t', strtotime($monthStart));
for ($j = 1; $j <= $daysInMonth; $j++) {
    $d = date('Y-m-', strtotime($monthStart)) . sprintf('%02d', $j);
    $monthCells[] = ['j' => $j, 'd' => $d, 'secs' => $monthBy[$d] ?? 0, 'off' => att_is_off($attSet, $d), 'auto' => !empty($monthAuto[$d]), 'future' => $d > $today, 'today' => $d === $today];
}
$monthName = $isRTL ? ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'][(int)date('n', strtotime($monthStart)) - 1] : date('F', strtotime($monthStart));
$cmpPct = $prevTotal > 0 ? (int)round(($monthTotal - $prevTotal) / $prevTotal * 100) : null;

/* branches with a location, for «nearest branch» on the phone */
$branchGeo = [];
foreach ($branches as $b) if ($b['lat'] !== null && $b['lng'] !== null && $b['lat'] !== '' && $b['lng'] !== '')
    $branchGeo[] = ['name' => $b['name'], 'label' => $isRTL ? $b['name_ar'] : $b['name_en'], 'lat' => (float)$b['lat'], 'lng' => (float)$b['lng']];

$activeBranchDisp = '';
if ($isClockedIn && $active['branch_name']) {
    $activeBranchDisp = $active['branch_name'];
    foreach ($branches as $b) {
        if ($b['name'] === $active['branch_name']) { $activeBranchDisp = $isRTL ? $b['name_ar'] : $b['name_en']; break; }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php include __DIR__ . '/pwa_head.php'; ?>
<title><?= $isRTL ? 'البصمة — الحضور والانصراف' : 'Attendance' ?></title>
<style>
  *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
  body{
    font-family:'Segoe UI',Tahoma,Arial,sans-serif;
    background:linear-gradient(135deg,#020617,#0f172a);
    color:#fff; min-height:100vh; padding:20px 16px calc(110px + env(safe-area-inset-bottom));
    padding-top:calc(20px + env(safe-area-inset-top));
    display:flex; flex-direction:column; align-items:center;
  }
  .wrap{ width:100%; max-width:440px; }

  .head{
    display:flex; align-items:center; justify-content:space-between; gap:12px;
    background:rgba(15,23,42,.88); border:1px solid rgba(255,255,255,.08);
    border-radius:20px; padding:14px 18px; margin-bottom:12px; backdrop-filter:blur(20px);
  }
  .head-user{ display:flex; align-items:center; gap:12px; }
  .avatar{
    width:44px; height:44px; border-radius:14px; flex-shrink:0;
    background:linear-gradient(135deg,#9333ea,#22c55e);
    display:flex; align-items:center; justify-content:center;
    font-weight:800; font-size:19px; color:#fff;
  }
  .head-name{ font-size:15px; font-weight:800; }
  .head-role{ font-size:11px; color:#a855f7; font-weight:700; background:rgba(147,51,234,.18); padding:2px 9px; border-radius:20px; display:inline-block; margin-top:3px; }
  .lang-switch{ display:flex; gap:6px; }
  .lang-switch a{ text-decoration:none; padding:8px 12px; border-radius:9px; background:#111827; color:#fff; font-weight:700; font-size:12px; }
  .lang-active{ background:#9333ea !important; }

  /* live clock */
  .now{ display:flex; align-items:baseline; justify-content:space-between; gap:10px; padding:4px 6px 14px; }
  .now .t{ font-size:34px; font-weight:800; letter-spacing:.5px; font-variant-numeric:tabular-nums; background:linear-gradient(90deg,#e2e8f0,#a5b4fc); -webkit-background-clip:text; background-clip:text; color:transparent; }
  .now .t small{ font-size:15px; margin-inline-start:4px; }
  .now .d{ font-size:13px; color:#94a3b8; font-weight:700; text-align:end; }

  .notice{ border-radius:14px; padding:12px 14px; font-size:13px; font-weight:700; line-height:1.6; margin-bottom:12px; }
  .notice.warn{ background:rgba(245,158,11,.12); border:1px solid rgba(245,158,11,.35); color:#fcd34d; }

  .clock-card{
    background:rgba(15,23,42,.88); border:1px solid rgba(255,255,255,.08);
    border-radius:28px; padding:30px 24px; text-align:center; backdrop-filter:blur(20px);
    position:relative; overflow:hidden; margin-bottom:16px;
  }
  .clock-card.is-in{ border-color:rgba(34,197,94,.35); }
  .status-pill{
    display:inline-flex; align-items:center; gap:7px;
    padding:7px 18px; border-radius:999px; font-size:13px; font-weight:800; margin-bottom:22px;
  }
  .status-pill.in{ background:rgba(34,197,94,.15); color:#22c55e; border:1px solid rgba(34,197,94,.3); }
  .status-pill.out{ background:rgba(255,255,255,.06); color:#94a3b8; border:1px solid rgba(255,255,255,.1); }
  .dot{ width:8px; height:8px; border-radius:50%; }
  .dot.g{ background:#22c55e; animation:pulse 2s infinite; }
  .dot.gray{ background:#64748b; }
  @keyframes pulse{ 0%{box-shadow:0 0 0 0 rgba(34,197,94,.5);} 70%{box-shadow:0 0 0 10px rgba(34,197,94,0);} 100%{box-shadow:0 0 0 0 rgba(34,197,94,0);} }

  .ring-wrap{ position:relative; width:230px; height:230px; margin:0 auto 8px; }
  .ring-wrap svg{ transform:rotate(-90deg); }
  .ring-bg{ fill:none; stroke:rgba(255,255,255,.06); stroke-width:12; }
  .ring-fg{ fill:none; stroke:url(#grad); stroke-width:12; stroke-linecap:round; transition:stroke-dashoffset 1s linear; }
  .ring-center{ position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; }
  .timer{ font-size:42px; font-weight:800; letter-spacing:1px; font-variant-numeric:tabular-nums; line-height:1; direction:ltr; }
  .timer.idle{ color:#475569; }
  .timer-label{ font-size:12px; color:#64748b; margin-top:8px; font-weight:700; }
  .since{ font-size:12px; color:#94a3b8; margin-top:6px; font-weight:700; }
  .branch-now{ font-size:13px; color:#94a3b8; margin-top:14px; font-weight:600; }
  .branch-now b{ color:#e2e8f0; }

  .field{ margin-top:20px; text-align:start; }
  .field label{ display:block; font-size:12px; color:#94a3b8; font-weight:700; margin-bottom:8px; }
  select{
    width:100%; padding:15px; border-radius:14px; font-size:16px; font-family:inherit;
    background:#0d1526; color:#f1f5f9; border:1px solid rgba(255,255,255,.1); outline:none;
  }
  select:focus{ border-color:rgba(147,51,234,.5); }

  /* swipe to punch */
  .swipe{ position:relative; margin-top:18px; height:64px; border-radius:999px; overflow:hidden; user-select:none; -webkit-user-select:none; touch-action:pan-y;
    background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); }
  .swipe.in{ background:rgba(34,197,94,.1); border-color:rgba(34,197,94,.35); }
  .swipe.out{ background:rgba(239,68,68,.1); border-color:rgba(239,68,68,.35); }
  .swipe-fill{ position:absolute; top:0; bottom:0; inset-inline-start:0; width:0; border-radius:999px; }
  .swipe.in .swipe-fill{ background:linear-gradient(90deg,rgba(34,197,94,.55),rgba(34,197,94,.25)); }
  .swipe.out .swipe-fill{ background:linear-gradient(90deg,rgba(239,68,68,.55),rgba(239,68,68,.25)); }
  .swipe-txt{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center; gap:8px; padding-inline-start:56px; font-size:16px; font-weight:800; color:#e2e8f0; pointer-events:none; }
  .swipe-arrows{ letter-spacing:-2px; animation:arr 1.6s infinite; }
  .swipe.in .swipe-arrows{ color:#4ade80; } .swipe.out .swipe-arrows{ color:#f87171; }
  @keyframes arr{ 0%,100%{ opacity:.35; } 50%{ opacity:1; } }
  .swipe-knob{ position:absolute; top:4px; inset-inline-start:4px; width:56px; height:56px; border-radius:50%; border:0; cursor:grab; font-size:24px; touch-action:none;
    display:grid; place-items:center; color:#fff; -webkit-tap-highlight-color:transparent; z-index:2; }
  .swipe.in .swipe-knob{ background:linear-gradient(135deg,#16a34a,#22c55e); box-shadow:0 6px 18px rgba(34,197,94,.45); }
  .swipe.out .swipe-knob{ background:linear-gradient(135deg,#dc2626,#ef4444); box-shadow:0 6px 18px rgba(239,68,68,.45); }
  .swipe.drag .swipe-knob{ cursor:grabbing; }
  .swipe.back .swipe-knob, .swipe.back .swipe-fill{ transition:transform .25s ease, width .25s ease; }
  .swipe.busy .swipe-knob{ animation:pulse 1.2s infinite; }
  .swipe.nudge .swipe-knob{ animation:nudge .5s ease; }
  @keyframes nudge{ 30%{ transform:translateX(calc(18px * var(--sg,1))); } 60%{ transform:translateX(calc(-4px * var(--sg,1))); } }
  [dir="rtl"] .swipe{ --sg:-1; }

  /* where you are */
  .here{ margin-top:18px; text-align:start; border-radius:16px; padding:12px 14px; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08); }
  .here.ok{ background:rgba(34,197,94,.08); border-color:rgba(34,197,94,.3); }
  .here.far{ background:rgba(245,158,11,.1); border-color:rgba(245,158,11,.4); }
  .here-main{ font-size:14px; font-weight:800; color:#e2e8f0; line-height:1.5; }
  .here.ok .here-main b{ color:#4ade80; } .here.far .here-main{ color:#fcd34d; }
  .here-main small{ color:#94a3b8; font-weight:700; }
  .here-sub{ display:flex; flex-wrap:wrap; align-items:center; gap:6px 8px; margin-top:8px; font-size:11.5px; color:#94a3b8; font-weight:700; }
  .here-sub #hereAcc{ flex:1 1 auto; }
  .here-sub #hereAcc.weak{ color:#fbbf24; }
  .here-btn{ border:1px solid rgba(255,255,255,.12); background:rgba(255,255,255,.05); color:#cbd5e1; font:inherit; font-size:11.5px; font-weight:800; padding:6px 10px; border-radius:999px; cursor:pointer; }
  .here-btn:active{ transform:scale(.95); }

  /* the target under the ring */
  .goal{ font-size:13px; font-weight:700; color:#94a3b8; margin-top:4px; min-height:20px; }
  .goal b{ color:#e2e8f0; font-variant-numeric:tabular-nums; }
  .goal.over{ color:#fcd34d; } .goal.over b{ color:#fde68a; }
  .ring-ot{ fill:none; stroke:#fff7d6; stroke-width:5; stroke-linecap:round; opacity:.85; transition:stroke-dashoffset 1s linear; }
  .clock-card.gold{ border-color:rgba(245,158,11,.5); box-shadow:0 0 40px rgba(245,158,11,.12) inset; }
  .clock-card.gold .ring-fg{ stroke:url(#gold); }
  .clock-card.gold .ring-wrap svg{ overflow:visible; filter:drop-shadow(0 0 8px rgba(245,158,11,.5)); }
  .clock-card.gold .timer:not(.idle){ color:#fde68a; }
  .clock-card.party{ animation:party 1.4s ease; }
  @keyframes party{ 0%{ box-shadow:0 0 0 0 rgba(250,204,21,.0); } 25%{ box-shadow:0 0 0 6px rgba(250,204,21,.55), 0 0 60px rgba(250,204,21,.45); } 100%{ box-shadow:0 0 0 0 rgba(250,204,21,0); } }
  .toast{ position:fixed; z-index:1200; left:50%; bottom:calc(96px + env(safe-area-inset-bottom)); transform:translateX(-50%); max-width:calc(100% - 32px);
    background:linear-gradient(135deg,#f59e0b,#d97706); color:#1c1002; font-weight:900; font-size:14px; padding:12px 18px; border-radius:16px; box-shadow:0 14px 34px rgba(0,0,0,.45); text-align:center; }
  .toast.on{ animation:up .4s cubic-bezier(.22,1,.36,1); }

  .loc-status{ margin-top:14px; font-size:12px; min-height:16px; font-weight:600; }
  .loc-ok{ color:#22c55e; } .loc-bad{ color:#f87171; } .loc-wait{ color:#94a3b8; }
  .loc-note{ margin-top:6px; font-size:11px; color:#64748b; line-height:1.6; }
  .loc-block{
    background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.4);
    color:#fca5a5; font-size:12.5px; font-weight:700; line-height:1.6;
    border-radius:14px; padding:12px 14px; margin-top:16px;
  }

  .today-card,.week-card{
    background:rgba(15,23,42,.88); border:1px solid rgba(255,255,255,.08);
    border-radius:22px; padding:18px; backdrop-filter:blur(20px); margin-bottom:16px;
  }
  .today-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; gap:8px; }
  .today-head h3{ font-size:14px; font-weight:800; color:#e2e8f0; }
  .today-total{ font-size:13px; font-weight:800; color:#22c55e; background:rgba(34,197,94,.12); padding:5px 12px; border-radius:999px; border:1px solid rgba(34,197,94,.25); font-variant-numeric:tabular-nums; white-space:nowrap; }
  .sess-row{
    display:flex; align-items:center; justify-content:space-between; gap:10px;
    background:rgba(0,0,0,.2); border:1px solid rgba(255,255,255,.05);
    border-radius:12px; padding:11px 14px; margin-bottom:8px; font-size:13px;
  }
  .sess-row:last-child{ margin-bottom:0; }
  .sess-time{ color:#cbd5e1; font-weight:600; }
  .sess-dur{ font-weight:800; color:#a3e635; font-variant-numeric:tabular-nums; white-space:nowrap; }
  .sess-dur.x{ color:#64748b; text-decoration:line-through; }
  .sess-auto{ font-size:10.5px; color:#fbbf24; font-weight:700; display:block; margin-top:2px; }
  .sess-br{ color:#94a3b8; font-weight:700; }
  /* today's timeline */
  .tl{ margin:0 0 14px; }
  .tl-bar{ position:relative; height:26px; border-radius:9px; background:repeating-linear-gradient(90deg,rgba(255,255,255,.05) 0 1px,transparent 1px 8.333%),rgba(255,255,255,.04); overflow:hidden; }
  .tl-bar i{ position:absolute; top:3px; bottom:3px; border-radius:6px; background:linear-gradient(90deg,#16a34a,#22c55e); display:flex; align-items:center; justify-content:center; overflow:hidden; }
  .tl-bar i span{ font-size:10px; font-weight:800; color:#022c14; white-space:nowrap; padding:0 4px; font-style:normal; }
  .tl-bar i.live{ background:linear-gradient(90deg,#16a34a,#4ade80); animation:livebar 2s infinite; }
  .tl-bar i.auto{ background:repeating-linear-gradient(45deg,#f59e0b 0 6px,#b45309 6px 12px); }
  .tl-bar i.auto span{ color:#1c1002; }
  @keyframes livebar{ 50%{ filter:brightness(1.25); } }
  .tl-now{ position:absolute; top:0; bottom:0; width:2px; margin-inline-start:-1px; background:#c084fc; box-shadow:0 0 8px #c084fc; }
  .tl-ticks{ display:flex; justify-content:space-between; margin-top:5px; font-size:10.5px; color:#64748b; font-weight:800; }
  .today-empty{ text-align:center; color:#64748b; font-size:13px; padding:14px; }

  /* this week / month */
  .seg{ display:inline-flex; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.08); border-radius:999px; padding:3px; }
  .seg button{ border:0; background:transparent; color:#94a3b8; font:inherit; font-size:13px; font-weight:800; padding:6px 14px; border-radius:999px; cursor:pointer; }
  .seg button.on{ background:rgba(147,51,234,.3); color:#f3e8ff; }
  .week-bars{ position:relative; display:grid; grid-template-columns:repeat(7,1fr); gap:4px; align-items:end; height:132px; }
  .wb-target{ position:absolute; inset-inline:0; bottom:calc(var(--nb,18px) + 70px * var(--tgt)); border-top:1.5px dashed rgba(250,204,21,.45); pointer-events:none; z-index:1; }
  .wb{ display:flex; flex-direction:column; align-items:center; justify-content:flex-end; height:100%; gap:5px; border:0; background:none; color:inherit; font:inherit; cursor:pointer; padding:0; border-radius:10px; min-width:0; -webkit-tap-highlight-color:transparent; }
  .wb .v{ font-size:10px; font-weight:800; color:#a3e635; font-variant-numeric:tabular-nums; min-height:12px; }
  .wb .col{ width:100%; max-width:30px; border-radius:8px 8px 4px 4px; background:rgba(255,255,255,.06); position:relative; overflow:hidden; height:70px; }
  .wb .col i{ position:absolute; left:0; right:0; bottom:0; border-radius:8px 8px 4px 4px; background:linear-gradient(180deg,#4ade80,#16a34a); height:0; transition:height .9s cubic-bezier(.22,1,.36,1); }
  .wb.gold .col i{ background:linear-gradient(180deg,#fde68a,#f59e0b); box-shadow:0 0 10px rgba(245,158,11,.5); }
  .wb.gold .v{ color:#fcd34d; }
  .wb.auto .col i{ background:repeating-linear-gradient(45deg,#f59e0b 0 5px,#b45309 5px 10px); }
  .wb.auto .v{ color:#fbbf24; }
  .wb .col em{ position:absolute; inset:0; display:grid; place-items:center; font-style:normal; font-size:9.5px; font-weight:800; color:#94a3b8; }
  .wb.off .col{ background:repeating-linear-gradient(135deg,rgba(255,255,255,.03) 0 4px,rgba(255,255,255,.07) 4px 8px); }
  .wb.today .col{ outline:2px solid rgba(168,85,247,.6); outline-offset:1px; }
  .wb.future .col{ background:rgba(255,255,255,.025); }
  .wb.sel .col{ outline:2px solid #e2e8f0; outline-offset:1px; }
  .wb .n{ font-size:10px; font-weight:800; color:#94a3b8; white-space:nowrap; max-width:100%; overflow:hidden; text-overflow:ellipsis; }
  .wb.today .n{ color:#d8b4fe; }
  .wb.sel .n{ color:#fff; }
  .day-info{ margin-top:12px; background:rgba(0,0,0,.22); border:1px solid rgba(255,255,255,.07); border-radius:14px; padding:10px 12px; font-size:13px; animation:up .25s ease; }
  .di-h{ display:flex; justify-content:space-between; font-weight:800; margin-bottom:6px; color:#e2e8f0; }
  .di-h span{ color:#a3e635; font-variant-numeric:tabular-nums; }
  .di-r{ display:flex; justify-content:space-between; gap:8px; padding:6px 0; border-top:1px solid rgba(255,255,255,.05); color:#cbd5e1; font-weight:600; }
  .di-r small{ color:#94a3b8; }
  .di-r b{ color:#a3e635; font-variant-numeric:tabular-nums; white-space:nowrap; }
  .di-r em{ color:#fbbf24; font-style:normal; font-size:11.5px; font-weight:800; white-space:nowrap; }
  .di-e{ color:#64748b; text-align:center; padding:6px; font-weight:700; }
  .week-sub{ font-size:12px; color:#94a3b8; font-weight:700; margin-top:12px; text-align:center; }

  .cal-title{ text-align:center; font-size:13px; font-weight:800; color:#cbd5e1; margin-bottom:8px; }
  .cal{ display:grid; grid-template-columns:repeat(7,1fr); gap:4px; }
  .cal-h{ text-align:center; font-size:10.5px; font-weight:800; color:#64748b; padding-bottom:2px; }
  .cd{ aspect-ratio:1; border-radius:9px; background:rgba(255,255,255,.04); display:flex; flex-direction:column; align-items:center; justify-content:center; gap:1px; position:relative; }
  .cd b{ font-size:12px; font-weight:800; color:#94a3b8; line-height:1; }
  .cd small{ font-size:9px; font-weight:800; color:rgba(255,255,255,.75); font-variant-numeric:tabular-nums; }
  .cd.empty{ background:none; }
  .cd.l1{ background:rgba(34,197,94,.18); } .cd.l2{ background:rgba(34,197,94,.35); } .cd.l3{ background:rgba(34,197,94,.6); }
  .cd.l1 b,.cd.l2 b,.cd.l3 b{ color:#f0fdf4; }
  .cd.l4{ background:linear-gradient(135deg,#fde68a,#f59e0b); } .cd.l4 b,.cd.l4 small{ color:#1c1002; }
  .cd.off{ background:repeating-linear-gradient(135deg,rgba(255,255,255,.02) 0 4px,rgba(255,255,255,.06) 4px 8px); }
  .cd.auto{ box-shadow:inset 0 0 0 2px #f59e0b; }
  .cd.future{ opacity:.35; }
  .cd.today{ outline:2px solid #c084fc; outline-offset:1px; }
  .cal-legend{ display:flex; flex-wrap:wrap; justify-content:center; gap:6px 12px; margin-top:10px; font-size:11px; color:#94a3b8; font-weight:700; }
  .cal-legend span{ display:inline-flex; align-items:center; gap:5px; }
  .cal-legend .cd{ width:14px; height:14px; aspect-ratio:auto; border-radius:4px; display:inline-block; }
  .m-stats{ display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-top:14px; }
  .m-stats div{ background:rgba(0,0,0,.22); border:1px solid rgba(255,255,255,.06); border-radius:14px; padding:10px 6px; text-align:center; }
  .m-stats b{ display:block; font-size:15px; font-weight:900; color:#e2e8f0; font-variant-numeric:tabular-nums; }
  .m-stats span{ display:block; font-size:11px; color:#94a3b8; font-weight:700; margin-top:3px; }
  .m-cmp{ margin-top:10px; text-align:center; font-size:12.5px; font-weight:800; color:#94a3b8; padding:9px; border-radius:12px; background:rgba(255,255,255,.04); }
  .m-cmp.up{ color:#4ade80; background:rgba(34,197,94,.08); } .m-cmp.down{ color:#f87171; background:rgba(239,68,68,.08); }

  /* sheets: confirm clock-out, success */
  .ov{ position:fixed; inset:0; z-index:1100; background:rgba(2,6,23,.8); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); display:none; align-items:flex-end; justify-content:center; padding:16px; padding-bottom:calc(16px + env(safe-area-inset-bottom)); }
  .ov.on{ display:flex; animation:fadeIn .2s ease; }
  .sheet{ width:100%; max-width:420px; border-radius:26px; padding:24px 20px 20px; text-align:center; background:linear-gradient(170deg,#141c35,#0a1122); border:1px solid rgba(255,255,255,.1); box-shadow:0 30px 70px rgba(0,0,0,.55); animation:up .35s cubic-bezier(.22,1,.36,1); }
  .sheet .ic{ font-size:44px; line-height:1; }
  .sheet h3{ font-size:19px; font-weight:900; margin:10px 0 6px; }
  .sheet p{ font-size:14px; color:#94a3b8; line-height:1.7; }
  .sheet .big{ font-size:30px; font-weight:900; color:#a3e635; font-variant-numeric:tabular-nums; margin:8px 0 2px; }
  .sheet .acts{ display:flex; gap:10px; margin-top:18px; }
  .sheet .acts button{ flex:1; height:52px; border-radius:15px; border:0; font:inherit; font-size:16px; font-weight:800; cursor:pointer; }
  .sheet .acts .no{ background:rgba(255,255,255,.07); color:#cbd5e1; }
  .sheet .acts .yes{ background:linear-gradient(135deg,#dc2626,#ef4444); color:#fff; box-shadow:0 10px 26px rgba(239,68,68,.3); }
  .done-sheet{ border-color:rgba(34,197,94,.4); }
  .done-sheet.out{ border-color:rgba(56,189,248,.4); }
  .check{ width:86px; height:86px; margin:0 auto; border-radius:50%; display:grid; place-items:center; background:radial-gradient(circle,rgba(34,197,94,.25),rgba(34,197,94,.05)); box-shadow:0 0 0 10px rgba(34,197,94,.08); }
  .done-sheet.out .check{ background:radial-gradient(circle,rgba(56,189,248,.25),rgba(56,189,248,.05)); box-shadow:0 0 0 10px rgba(56,189,248,.08); }
  .check svg{ width:46px; height:46px; }
  .check path{ fill:none; stroke:#22c55e; stroke-width:5; stroke-linecap:round; stroke-linejoin:round; stroke-dasharray:60; stroke-dashoffset:60; animation:draw .6s .15s ease forwards; }
  .done-sheet.out .check path{ stroke:#38bdf8; }
  .done-sheet .when{ font-size:30px; font-weight:900; margin-top:12px; font-variant-numeric:tabular-nums; }
  .done-sheet .where{ font-size:14px; color:#94a3b8; font-weight:700; margin-top:4px; }
  .done-sheet .dur{ display:inline-block; margin-top:10px; font-size:14px; font-weight:800; color:#a3e635; background:rgba(163,230,53,.1); border:1px solid rgba(163,230,53,.25); padding:6px 14px; border-radius:999px; }
  .done-sheet .dur.gold{ color:#fcd34d; background:rgba(245,158,11,.12); border-color:rgba(245,158,11,.35); margin-inline-start:6px; }
  .done-sheet .ok{ width:100%; height:50px; border-radius:15px; border:0; margin-top:18px; font:inherit; font-size:16px; font-weight:800; cursor:pointer; background:rgba(255,255,255,.08); color:#e2e8f0; }
  @keyframes draw{ to{ stroke-dashoffset:0; } }
  @keyframes fadeIn{ from{ opacity:0; } }
  @keyframes up{ from{ opacity:0; transform:translateY(30px); } }
  @media (prefers-reduced-motion:reduce){ .check path{ animation:none; stroke-dashoffset:0; } .sheet{ animation:none; } .swipe-arrows,.tl-bar i.live,.clock-card.party{ animation:none; } }
</style>
</head>
<body>
<div class="wrap">

  <div class="head">
    <div class="head-user">
      <div class="avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($userName,0,1))) ?></div>
      <div>
        <div class="head-name"><?= htmlspecialchars($userName) ?></div>
        <span class="head-role"><?= htmlspecialchars($roleName) ?></span>
      </div>
    </div>
    <div class="lang-switch">
      <a href="?lang=ar" class="<?= $isRTL ? 'lang-active' : '' ?>">ع</a>
      <a href="?lang=en" class="<?= !$isRTL ? 'lang-active' : '' ?>">EN</a>
    </div>
  </div>

  <div class="now"><div class="t" id="nowT">--:--</div><div class="d" id="nowD"></div></div>

  <?php if ($flash && $flash['type'] === 'already_in'): ?>
    <div class="notice warn">ℹ️ <?= $isRTL ? 'أنت مسجّل حضور بالفعل — لم يتم تسجيل حضور جديد.' : 'You are already clocked in — no new clock-in was recorded.' ?></div>
  <?php elseif ($flash && $flash['type'] === 'not_in'): ?>
    <div class="notice warn">ℹ️ <?= $isRTL ? 'أنت مسجّل انصراف بالفعل.' : 'You are already clocked out.' ?></div>
  <?php elseif (($_GET['err'] ?? '') === 'expired'): ?>
    <div class="notice warn">↻ <?= $isRTL ? 'انتهت صلاحية الصفحة — اضغط الزر مرة أخرى.' : 'The page expired — tap the button again.' ?></div>
  <?php endif; ?>

  <div class="clock-card <?= $isClockedIn ? 'is-in' : '' ?>" id="clockCard">
    <div class="status-pill <?= $isClockedIn ? 'in' : 'out' ?>">
      <span class="dot <?= $isClockedIn ? 'g' : 'gray' ?>"></span>
      <?= $isClockedIn ? ($isRTL ? 'متواجد الآن' : 'Clocked In') : ($isRTL ? 'غير متواجد' : 'Not Clocked In') ?>
    </div>

    <div class="ring-wrap">
      <svg width="230" height="230" viewBox="0 0 230 230">
        <defs>
          <linearGradient id="grad" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#22c55e"/>
            <stop offset="100%" stop-color="#9333ea"/>
          </linearGradient>
          <linearGradient id="gold" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#fde68a"/>
            <stop offset="55%" stop-color="#f59e0b"/>
            <stop offset="100%" stop-color="#d97706"/>
          </linearGradient>
        </defs>
        <circle class="ring-bg" cx="115" cy="115" r="104"/>
        <circle class="ring-fg" id="ring" cx="115" cy="115" r="104" stroke-dasharray="653.45" stroke-dashoffset="653.45"/>
        <circle class="ring-ot" id="ringOt" cx="115" cy="115" r="104" stroke-dasharray="653.45" stroke-dashoffset="653.45"/>
      </svg>
      <div class="ring-center">
        <div class="timer <?= $isClockedIn ? '' : 'idle' ?>" id="timer"><?= $isClockedIn ? '00:00:00' : '--:--:--' ?></div>
        <div class="timer-label"><?= $isClockedIn ? ($isRTL ? 'وقت الجلسة الحالية' : 'Current session') : ($isRTL ? 'ابدأ الحضور' : 'Start your shift') ?></div>
        <?php if ($isClockedIn): ?><div class="since"><?= $isRTL ? 'منذ' : 'since' ?> <?= htmlspecialchars(fmtT($active['clock_in'])) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="goal" id="goal"></div>

    <?php if ($isClockedIn && $activeBranchDisp): ?>
      <div class="branch-now">📍 <?= $isRTL ? 'سجّلت الحضور في' : 'Clocked in at' ?> <b><?= htmlspecialchars($activeBranchDisp) ?></b></div>
    <?php endif; ?>

    <div class="loc-block" id="locBlock"<?= (isset($_GET['err']) && $_GET['err'] === 'loc') ? '' : ' style="display:none;"' ?>>
      ⛔ <?= $isRTL ? 'يجب السماح بالوصول إلى الموقع حتى تتمكن من تسجيل الحضور أو الانصراف.' : 'You must allow location access to clock in or out.' ?>
    </div>
    <?php if (isset($_GET['err']) && $_GET['err'] === 'branch'): ?>
    <div class="loc-block">⚠️ <?= $isRTL ? 'يجب اختيار الفرع أولاً' : 'You must select a branch first' ?></div>
    <?php endif; ?>

    <form id="attForm" method="POST">
      <input type="hidden" name="action" value="<?= $isClockedIn ? 'clock_out' : 'clock_in' ?>">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="lat" id="lat">
      <input type="hidden" name="lng" id="lng">

      <!-- where you are: the nearest branch, picked from the phone's location -->
      <div class="here" id="here">
        <div class="here-main" id="hereMain">📡 <?= $isRTL ? 'جاري تحديد موقعك…' : 'Finding where you are…' ?></div>
        <div class="here-sub">
          <span id="hereAcc"></span>
          <button type="button" class="here-btn" id="relocBtn">🔄 <?= $isRTL ? 'إعادة تحديد الموقع' : 'Locate again' ?></button>
          <button type="button" class="here-btn" id="chgBtn">✏️ <?= $isRTL ? 'تغيير الفرع' : 'Change branch' ?></button>
        </div>
      </div>

      <div class="field" id="branchField"<?= $isClockedIn ? ' hidden' : '' ?>>
        <label for="branchSel"><?= $isRTL ? 'الفرع' : 'Branch' ?></label>
        <select name="branch_name" id="branchSel" required>
          <option value=""><?= $isRTL ? '— اختر —' : '— choose —' ?></option>
          <?php foreach ($branches as $b):
            $pick = $isClockedIn ? ($active['branch_name'] ?? '') : $lastBranch;   // clock-in branch / the one used last time (until the location picks the nearest)
            $sel = $pick === $b['name'] ? ' selected' : '';
          ?>
            <option value="<?= htmlspecialchars($b['name']) ?>"<?= $sel ?>><?= htmlspecialchars($isRTL ? $b['name_ar'] : $b['name_en']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- swipe to punch: no accidental taps, no confirm box -->
      <div class="swipe <?= $isClockedIn ? 'out' : 'in' ?>" id="swipe">
        <div class="swipe-fill" id="swipeFill"></div>
        <span class="swipe-txt" id="swipeTxt"><?= $isClockedIn ? ($isRTL ? 'اسحب لتسجيل الانصراف' : 'Swipe to clock out') : ($isRTL ? 'اسحب لتسجيل الحضور' : 'Swipe to clock in') ?> <b class="swipe-arrows" dir="ltr"><?= $isRTL ? '‹‹‹' : '›››' ?></b></span>
        <button type="button" class="swipe-knob" id="swipeKnob" aria-label="<?= $isClockedIn ? ($isRTL ? 'تسجيل انصراف' : 'Clock out') : ($isRTL ? 'تسجيل حضور' : 'Clock in') ?>"><?= $isClockedIn ? '🔴' : '🟢' ?></button>
      </div>
    </form>

    <div class="loc-status" id="locStatus"></div>
    <div class="loc-note">
      <?= $isRTL ? '📍 الموقع مطلوب — لن يتم تسجيل البصمة بدون السماح بالوصول للموقع' : '📍 Location is required — attendance will not be recorded without it' ?>
    </div>
  </div>

  <div class="today-card">
    <div class="today-head">
      <h3>📅 <?= $isRTL ? 'اليوم' : 'Today' ?></h3>
      <span class="today-total" id="todayTotal">⏱ <?= fmtHM($todayTotal) ?></span>
    </div>
    <?php if ($tlSegs): ?>
    <div class="tl" aria-hidden="true">
      <div class="tl-bar">
        <?php foreach ($tlSegs as $g):
          $l = ($g['a'] - $tlFrom) / $tlSpan * 100; $w = max(1.2, ($g['b'] - $g['a']) / $tlSpan * 100);
        ?><i class="<?= $g['auto'] ? 'auto' : ($g['live'] ? 'live' : '') ?>" style="inset-inline-start:<?= round($l, 2) ?>%;width:<?= round(min($w, 100 - $l), 2) ?>%" title="<?= htmlspecialchars($g['tip'] . ' · ' . $g['br']) ?>"><span><?= htmlspecialchars($g['br']) ?></span></i><?php endforeach; ?>
        <?php $nowTs = strtotime($clock['n']); if ($nowTs >= $tlFrom && $nowTs <= $tlTo): ?><b class="tl-now" style="inset-inline-start:<?= round(($nowTs - $tlFrom) / $tlSpan * 100, 2) ?>%"></b><?php endif; ?>
      </div>
      <div class="tl-ticks"><span><?= $hourLbl($tlFrom) ?></span><span><?= $hourLbl($tlFrom + intdiv($tlSpan, 2)) ?></span><span><?= $hourLbl($tlTo) ?></span></div>
    </div>
    <?php endif; ?>
    <?php if ($todayLogs): ?>
      <?php foreach ($todayLogs as $t):
        $d = max(0, (int)$t['dur_secs']);
      ?>
        <div class="sess-row">
          <span class="sess-time"><?= htmlspecialchars(fmtT($t['clock_in'])) ?> → <?= htmlspecialchars(fmtT($t['clock_out'])) ?> <small class="sess-br">· <?= htmlspecialchars($branchLabel($t['branch_name'])) ?></small>
            <?php if ($t['auto_closed']): ?><span class="sess-auto">⏰ <?= $isRTL ? 'نسيت تسجيل الانصراف — لا تُحسب' : 'Forgot to clock out — not counted' ?></span><?php endif; ?>
            <?php if (!empty($t['stop_reason'])): ?><span class="sess-auto" style="white-space:normal" title="<?= htmlspecialchars($t['stop_reason']) ?>">🔒 <?= $isRTL ? 'أُوقفت البصمة' : 'Clock-in stopped' ?>: <?= htmlspecialchars(mb_strlen($t['stop_reason']) > 140 ? mb_substr($t['stop_reason'], 0, 140) . '…' : $t['stop_reason']) ?></span><?php endif; ?></span>
          <span class="sess-dur <?= $t['auto_closed'] ? 'x' : '' ?>"><?= fmtHM($d) ?></span>
        </div>
      <?php endforeach; ?>
    <?php elseif (!$isClockedIn): ?>
      <div class="today-empty"><?= $isRTL ? 'لا توجد جلسات مكتملة اليوم' : 'No completed sessions today' ?></div>
    <?php else: ?>
      <div class="today-empty"><?= $isRTL ? 'جلستك الحالية تُحسب الآن ⏱' : 'Your current session is counting ⏱' ?></div>
    <?php endif; ?>
  </div>

  <div class="week-card">
    <div class="today-head">
      <div class="seg" role="tablist">
        <button type="button" class="on" data-tab="week" role="tab"><?= $isRTL ? 'أسبوعي' : 'Week' ?></button>
        <button type="button" data-tab="month" role="tab"><?= $isRTL ? 'شهري' : 'Month' ?></button>
      </div>
      <span class="today-total" id="weekTotal" data-pane="week">⏱ <?= fmtHM($weekTotal) ?></span>
      <span class="today-total" data-pane="month" hidden>⏱ <?= fmtHM($monthTotal) ?></span>
    </div>

    <div data-pane="week">
    <?php $maxW = max($targetSecs * 1.2, $weekBy ? max($weekBy) : 0); ?>
    <div class="week-bars" style="--tgt:<?= round($targetSecs / $maxW, 3) ?>">
      <span class="wb-target" title="<?= $isRTL ? 'هدف اليوم' : 'Daily target' ?>"></span>
      <?php foreach ($weekView as $i => $w):
        $cls = trim(($w['today'] ? 'today ' : '') . ($w['future'] ? 'future ' : '') . ($w['secs'] >= $targetSecs ? 'gold ' : '') . ($w['auto'] && !$w['secs'] ? 'auto ' : '') . ($w['off'] && !$w['secs'] && !$w['auto'] ? 'off' : ''));
        $h = $w['secs'] ? $w['secs'] / $maxW * 100 : ($w['auto'] ? 14 : 0);
      ?>
        <button type="button" class="wb <?= $cls ?>" data-i="<?= $i ?>" <?= $w['today'] ? 'id="wbToday"' : '' ?>>
          <span class="v"><?= $w['secs'] >= 180 ? round($w['secs'] / 3600, 1) : ($w['auto'] ? '⏰' : '') ?></span>
          <span class="col"><i data-h="<?= round($h, 1) ?>"></i><?php if ($w['off'] && !$w['secs'] && !$w['auto']): ?><em><?= $isRTL ? 'إجازة' : 'Off' ?></em><?php endif; ?></span>
          <span class="n"><?= $w['name'] ?></span>
        </button>
      <?php endforeach; ?>
    </div>
    <div class="day-info" id="dayInfo" hidden></div>
    <div class="week-sub"><?= $weekDays ?> <?= $isRTL ? ($weekDays === 1 ? 'يوم حضور' : 'أيام حضور') : ($weekDays === 1 ? 'day worked' : 'days worked') ?><?= $weekDays ? ' · ⌀ ' . fmtHM(intdiv($weekTotal, max(1, $weekDays))) . ($isRTL ? ' يومياً' : ' / day') : '' ?> · <?= $isRTL ? 'اضغط على يوم لعرض التفاصيل' : 'tap a day for details' ?></div>
    </div>

    <div data-pane="month" hidden>
      <div class="cal-title"><?= htmlspecialchars($monthName . ' ' . date('Y', strtotime($monthStart))) ?></div>
      <div class="cal">
        <?php foreach ([6, 0, 1, 2, 3, 4, 5] as $wd): ?><span class="cal-h"><?= $isRTL ? mb_substr(str_replace('ال', '', $dayFull[$wd]), 0, 2) : $dayFull[$wd] ?></span><?php endforeach; ?>
        <?php foreach ($monthCells as $c):
          if ($c === null) { echo '<span class="cd empty"></span>'; continue; }
          $lv = $c['secs'] >= $targetSecs ? 'l4' : ($c['secs'] >= $targetSecs * .75 ? 'l3' : ($c['secs'] >= $targetSecs * .4 ? 'l2' : ($c['secs'] > 0 ? 'l1' : '')));
          $cls = trim($lv . ($c['auto'] ? ' auto' : '') . ($c['off'] && !$c['secs'] ? ' off' : '') . ($c['future'] ? ' future' : '') . ($c['today'] ? ' today' : ''));
          $tip = $c['secs'] ? fmtHM($c['secs']) : ($c['off'] ? ($isRTL ? 'إجازة' : 'Off') : '');
        ?><span class="cd <?= $cls ?>" title="<?= htmlspecialchars($tip) ?>"><b><?= $c['j'] ?></b><?php if ($c['secs'] >= 180): ?><small><?= round($c['secs'] / 3600, 1) ?></small><?php endif; ?></span><?php endforeach; ?>
      </div>
      <div class="cal-legend">
        <span><i class="cd l1"></i><?= $isRTL ? 'قليل' : 'Less' ?></span><span><i class="cd l3"></i></span><span><i class="cd l4"></i><?= $isRTL ? 'أكمل ' . rtrim(rtrim(number_format($attSet['target'], 1), '0'), '.') . ' ساعات' : 'Full ' . rtrim(rtrim(number_format($attSet['target'], 1), '0'), '.') . 'h' ?></span><span><i class="cd auto"></i><?= $isRTL ? 'نسي الانصراف' : 'Forgot out' ?></span>
      </div>
      <div class="m-stats">
        <div><b><?= fmtHM($monthTotal) ?></b><span><?= $isRTL ? 'مجموع الشهر' : 'Month total' ?></span></div>
        <div><b><?= $monthDays ?></b><span><?= $isRTL ? 'أيام حضور' : 'Days worked' ?></span></div>
        <div><b><?= $monthDays ? fmtHM(intdiv($monthTotal, $monthDays)) : '—' ?></b><span><?= $isRTL ? 'المتوسط اليومي' : 'Daily average' ?></span></div>
      </div>
      <div class="m-cmp <?= $cmpPct === null ? '' : ($cmpPct >= 0 ? 'up' : 'down') ?>">
        <?php if ($cmpPct === null): ?>
          <?= $isRTL ? 'لا توجد ساعات في نفس الفترة من الشهر الماضي للمقارنة' : 'No hours in the same days last month to compare' ?>
        <?php else: ?>
          <?= $cmpPct >= 0 ? '▲' : '▼' ?> <?= abs($cmpPct) ?>% <?= $isRTL ? ($cmpPct >= 0 ? 'أكثر من' : 'أقل من') . ' نفس الفترة من الشهر الماضي' : ($cmpPct >= 0 ? 'more' : 'less') . ' than the same days last month' ?> (<?= fmtHM($prevTotal) ?>)
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- far from the branch: ask before punching -->
<div class="ov" id="farOv">
  <div class="sheet">
    <div class="ic">📍</div>
    <h3><?= $isRTL ? 'أنت بعيد عن الفرع' : 'You are far from the branch' ?></h3>
    <p id="farTxt"></p>
    <div class="acts">
      <button type="button" class="no" id="farNo"><?= $isRTL ? 'رجوع' : 'Back' ?></button>
      <button type="button" class="yes" id="farYes"><?= $isRTL ? 'تسجيل رغم ذلك' : 'Punch anyway' ?></button>
    </div>
  </div>
</div>

<div class="toast" id="toast" hidden></div>

<?php if ($flash && in_array($flash['type'], ['in', 'out'], true)): $isIn = $flash['type'] === 'in'; ?>
<!-- done -->
<div class="ov on" id="doneOv">
  <div class="sheet done-sheet <?= $isIn ? '' : 'out' ?>">
    <div class="check"><svg viewBox="0 0 50 50"><path d="M13 26 l8 8 l16 -18"/></svg></div>
    <h3><?= $isIn ? ($isRTL ? 'تم تسجيل الحضور' : 'Clocked in') : ($isRTL ? 'تم تسجيل الانصراف' : 'Clocked out') ?></h3>
    <div class="when"><?= htmlspecialchars(fmtT($flash['time'])) ?></div>
    <div class="where">📍 <?= htmlspecialchars($branchLabel($flash['branch'])) ?></div>
    <?php if (!$isIn): ?><div class="dur">⏱️ <?= $isRTL ? 'مدة الجلسة' : 'Session' ?> <?= fmtHM((int)$flash['secs']) ?></div><?php endif; ?>
    <?php if (!$isIn && $todayTotal >= $targetSecs): ?><div class="dur gold">🏆 <?= $isRTL ? 'أكملت ساعات اليوم' : 'Full day done' ?> · <?= fmtHM($todayTotal) ?></div><?php endif; ?>
    <button type="button" class="ok" id="doneOk"><?= $isIn ? ($isRTL ? 'يوم موفق 👋' : 'Have a good day 👋') : ($isRTL ? 'تمام ✓' : 'OK ✓') ?></button>
  </div>
</div>
<?php endif; ?>

<?php $bnActive = 'basma'; include __DIR__ . '/bottom_nav.php'; ?>

<script>
const isClockedIn = <?= $isClockedIn ? 'true' : 'false' ?>;
let elapsed = <?= (int)$elapsedSecs ?>;
const pageLoadedAt = Date.now();
const lang = <?= json_encode($lang) ?>;
const AR = lang === 'ar';
const todayBase = <?= (int)($todayTotal - $elapsedSecs) ?>, weekBase = <?= (int)($weekTotal - ($isClockedIn ? $elapsedSecs : 0)) ?>;
const TARGET = <?= (int)$targetSecs ?>, FENCE = <?= (int)$attSet['fence'] ?>;
const BRANCHES = <?= json_encode($branchGeo, JSON_UNESCAPED_UNICODE) ?>;
const WEEK = <?= json_encode($weekView, JSON_UNESCAPED_UNICODE) ?>;

const RING_LEN = 653.45;
const ring = document.getElementById('ring'), ringOt = document.getElementById('ringOt');
const card = document.getElementById('clockCard');

function pad(n){ return String(n).padStart(2,'0'); }
const hm = s => AR ? Math.floor(s / 3600) + 'س ' + pad(Math.floor(s % 3600 / 60)) + 'د' : Math.floor(s / 3600) + 'h ' + pad(Math.floor(s % 3600 / 60)) + 'm';
function sessionSecs(){ return isClockedIn ? elapsed + Math.floor((Date.now() - pageLoadedAt) / 1000) : 0; }

/* the ring: today's hours toward the target; past it the ring turns gold and a second lap keeps going */
let wasGold = todayBase + sessionSecs() >= TARGET;
function drawRing(){
  const day = todayBase + sessionSecs(), f = day / TARGET;
  const gold = f >= 1;
  ring.style.strokeDashoffset = String(RING_LEN * (1 - Math.min(1, f)));
  ringOt.style.strokeDashoffset = String(RING_LEN * (1 - (gold ? Math.min(1, f - 1) : 0)));
  card.classList.toggle('gold', gold);
  const g = document.getElementById('goal');
  g.className = 'goal' + (gold ? ' over' : '');
  g.innerHTML = gold
    ? '🏆 ' + (AR ? 'أكملت ساعات اليوم · ' : 'Full day done · ') + '<b>+' + hm(day - TARGET) + '</b> ' + (AR ? 'إضافي' : 'extra')
    : (AR ? 'باقي <b>' + hm(TARGET - day) + '</b> لإكمال ' + (TARGET / 3600) + ' ساعات اليوم' : '<b>' + hm(TARGET - day) + '</b> left of today\'s ' + (TARGET / 3600) + 'h');
  if (gold && !wasGold) celebrate(AR ? '🏆 أحسنت! أكملت ' + (TARGET / 3600) + ' ساعات اليوم' : '🏆 Well done! ' + (TARGET / 3600) + ' hours today');
  wasGold = gold;
}
function tick(){
  const total = sessionSecs();
  if (isClockedIn) {
    const h = Math.floor(total/3600), m = Math.floor((total%3600)/60), s = total%60;
    document.getElementById('timer').textContent = `${pad(h)}:${pad(m)}:${pad(s)}`;
    if (s === 0 || total === elapsed) {    // today / week totals move with the session
      document.getElementById('todayTotal').textContent = '⏱ ' + hm(todayBase + total);
      document.getElementById('weekTotal').textContent = '⏱ ' + hm(weekBase + total);
    }
  }
  drawRing();
}
tick();
if (isClockedIn) setInterval(tick, 1000);

/* a small celebration: vibration + glow + a toast */
function celebrate(msg){
  if (navigator.vibrate) navigator.vibrate([40, 60, 40, 60, 90]);
  card.classList.remove('party'); void card.offsetWidth; card.classList.add('party');
  if (msg) toast(msg);
}
function toast(msg){
  const t = document.getElementById('toast');
  t.textContent = msg; t.hidden = false; t.classList.remove('on'); void t.offsetWidth; t.classList.add('on');
  clearTimeout(toast._t); toast._t = setTimeout(() => { t.hidden = true; }, 4200);
}

// live clock
const days = AR ? ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'] : ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
const months = AR ? ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'] : ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
function clockNow(){
  const d = new Date(), h = d.getHours(), h12 = h % 12 || 12;
  document.getElementById('nowT').innerHTML = pad(h12) + ':' + pad(d.getMinutes()) + '<small>' + (AR ? (h < 12 ? 'ص' : 'م') : (h < 12 ? 'AM' : 'PM')) + '</small>';
  document.getElementById('nowD').textContent = days[d.getDay()] + ' ' + d.getDate() + ' ' + months[d.getMonth()];
}
clockNow(); setInterval(clockNow, 10000);

// week bars grow in
requestAnimationFrame(() => setTimeout(() => document.querySelectorAll('.wb .col i').forEach(i => i.style.height = i.dataset.h + '%'), 150));

// week / month
document.querySelectorAll('.seg button').forEach(b => b.addEventListener('click', () => {
  document.querySelectorAll('.seg button').forEach(x => x.classList.toggle('on', x === b));
  document.querySelectorAll('.week-card [data-pane]').forEach(p => p.hidden = p.dataset.pane !== b.dataset.tab);
  try { localStorage.setItem('f1cAttTab', b.dataset.tab); } catch (e) {}
}));
try { if (localStorage.getItem('f1cAttTab') === 'month') document.querySelector('.seg [data-tab="month"]').click(); } catch (e) {}

// tap a day: its clock-in / clock-out times
const dayInfo = document.getElementById('dayInfo');
const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
document.querySelectorAll('.wb').forEach(b => b.addEventListener('click', () => {
  const w = WEEK[+b.dataset.i];
  const was = b.classList.contains('sel');
  document.querySelectorAll('.wb').forEach(x => x.classList.remove('sel'));
  if (was) { dayInfo.hidden = true; return; }
  b.classList.add('sel');
  let h = '<div class="di-h"><b>' + esc(w.name) + '</b><span>' + (w.secs ? hm(w.secs) : '') + '</span></div>';
  if (w.s.length) h += w.s.map(x => '<div class="di-r' + (x.auto ? ' auto' : '') + '"><span>' + esc(x.in) + ' → ' + (x.out ? esc(x.out) : (AR ? 'الآن' : 'now')) + ' <small>· ' + esc(x.br) + '</small></span>' +
      (x.auto ? '<em>⏰ ' + (AR ? 'نسي الانصراف — لا تُحسب' : 'Forgot to clock out — not counted') + '</em>' : '<b>' + hm(x.secs) + '</b>') + '</div>').join('');
  else h += '<div class="di-e">' + (w.off ? (AR ? '🌴 إجازة' : '🌴 Day off') : w.future ? (AR ? 'لم يأتِ بعد' : 'Not yet') : (AR ? 'لا يوجد حضور' : 'No attendance')) + '</div>';
  dayInfo.innerHTML = h; dayInfo.hidden = false;
}));

const form = document.getElementById('attForm');
const sel = document.getElementById('branchSel');
const locStatus = document.getElementById('locStatus');
const locBlock = document.getElementById('locBlock');
const hereMain = document.getElementById('hereMain'), hereAcc = document.getElementById('hereAcc'), here = document.getElementById('here');

/* nearest branch from the phone's location */
function distM(a, b, c, d){
  const R = 6371000, r = x => x * Math.PI / 180;
  const dLa = r(c - a), dLo = r(d - b);
  const h = Math.sin(dLa/2) ** 2 + Math.cos(r(a)) * Math.cos(r(c)) * Math.sin(dLo/2) ** 2;
  return Math.round(R * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h)));
}
const fmtDist = m => m < 1000 ? m + (AR ? ' م' : ' m') : (m / 1000).toFixed(m < 10000 ? 1 : 0) + (AR ? ' كم' : ' km');
let pos = null, userPicked = false;
sel.addEventListener('change', () => { userPicked = true; showHere(); });
function distTo(name){
  const b = BRANCHES.find(x => x.name === name);
  return (b && pos) ? distM(pos.lat, pos.lng, b.lat, b.lng) : null;
}
function showHere(){
  if (!pos) return;
  if (!userPicked && BRANCHES.length) {
    let best = null;
    BRANCHES.forEach(b => { const d = distM(pos.lat, pos.lng, b.lat, b.lng); if (!best || d < best.d) best = { b, d }; });
    if (best && [...sel.options].some(o => o.value === best.b.name)) sel.value = best.b.name;
  }
  const opt = sel.options[sel.selectedIndex];
  const d = distTo(sel.value);
  here.classList.remove('ok', 'far');
  if (!sel.value) { hereMain.textContent = AR ? '📍 اختر الفرع' : '📍 Choose the branch'; document.getElementById('branchField').hidden = false; }
  else if (d === null) hereMain.innerHTML = '📍 ' + esc(opt.text) + ' <small>· ' + (AR ? 'موقع الفرع غير محدد' : 'branch location not set') + '</small>';
  else if (d <= FENCE) { here.classList.add('ok'); hereMain.innerHTML = '📍 ' + (AR ? 'أنت في ' : 'You are at ') + '<b>' + esc(opt.text) + '</b> · ' + (AR ? 'على بعد ' : '') + fmtDist(d) + ' ✓'; }
  else { here.classList.add('far'); hereMain.innerHTML = '⚠️ ' + (AR ? 'أنت بعيد عن ' : 'You are far from ') + '<b>' + esc(opt.text) + '</b> · ' + fmtDist(d); }
  hereAcc.textContent = pos.acc ? (AR ? 'دقة الموقع ±' + fmtDist(pos.acc) : 'Accuracy ±' + fmtDist(pos.acc)) : '';
  hereAcc.className = pos.acc > 100 ? 'weak' : '';
}
document.getElementById('chgBtn').addEventListener('click', () => {
  const f = document.getElementById('branchField'); f.hidden = !f.hidden; if (!f.hidden) sel.focus();
});

// high accuracy first; if it can't get a fix (indoors), try again with normal accuracy
function requestLocation(cb, fresh){
  if (!navigator.geolocation) {
    locStatus.textContent = AR ? '⚠️ الموقع غير مدعوم على هذا الجهاز' : '⚠️ Geolocation not supported on this device';
    locStatus.className = 'loc-status loc-bad';
    if (locBlock) locBlock.style.display = 'block';
    cb(false); return;
  }
  const ok = (p) => {
    document.getElementById('lat').value = p.coords.latitude;
    document.getElementById('lng').value = p.coords.longitude;
    pos = { lat: p.coords.latitude, lng: p.coords.longitude, acc: Math.round(p.coords.accuracy || 0) };
    locStatus.textContent = ''; locStatus.className = 'loc-status';
    if (locBlock) locBlock.style.display = 'none';
    showHere();
    cb(true);
  };
  const fail = (err) => {
    const denied = err && err.code === 1; // 1 = PERMISSION_DENIED
    locStatus.textContent = denied
      ? (AR ? '⛔ تم رفض الموقع — لا يمكن تسجيل البصمة' : '⛔ Location denied — cannot clock in/out')
      : (AR ? '⚠️ تعذر تحديد الموقع — تأكد من تشغيل GPS وحاول مرة أخرى' : '⚠️ Location unavailable — make sure GPS is on and try again');
    locStatus.className = 'loc-status loc-bad';
    hereMain.textContent = AR ? '📍 لم يتم تحديد موقعك' : '📍 Location not found';
    if (locBlock) locBlock.style.display = 'block';
    cb(false);
  };
  hereMain.textContent = AR ? '📡 جاري تحديد موقعك…' : '📡 Finding where you are…';
  navigator.geolocation.getCurrentPosition(ok, (err) => {
    if (err && err.code === 1) return fail(err);
    navigator.geolocation.getCurrentPosition(ok, fail, { enableHighAccuracy: false, timeout: 15000, maximumAge: fresh ? 0 : 60000 });
  }, { enableHighAccuracy: true, timeout: 12000, maximumAge: fresh ? 0 : 30000 });
}

// Ask for permission as soon as the page loads
requestLocation(() => {});
document.getElementById('relocBtn').addEventListener('click', () => requestLocation(() => {}, true));

/* swipe to punch */
const swipe = document.getElementById('swipe'), knob = document.getElementById('swipeKnob'), fill = document.getElementById('swipeFill'), swTxt = document.getElementById('swipeTxt');
const dirSign = getComputedStyle(swipe).direction === 'rtl' ? -1 : 1;
let drag = null, busy = false;
const maxX = () => swipe.clientWidth - knob.offsetWidth - 8;
function setX(x){ knob.style.transform = 'translateX(' + (x * dirSign) + 'px)'; fill.style.width = (x + knob.offsetWidth / 2 + 4) + 'px'; swTxt.style.opacity = String(Math.max(0, 1 - x / (maxX() * .6))); }
function resetSwipe(){ swipe.classList.add('back'); setX(0); setTimeout(() => swipe.classList.remove('back'), 260); }
knob.addEventListener('pointerdown', e => { if (busy) return; drag = { x0: e.clientX }; knob.setPointerCapture(e.pointerId); swipe.classList.add('drag'); });
knob.addEventListener('pointermove', e => { if (!drag) return; setX(Math.max(0, Math.min(maxX(), (e.clientX - drag.x0) * dirSign))); });
function endDrag(e){
  if (!drag) return;
  const x = Math.max(0, Math.min(maxX(), (e.clientX - drag.x0) * dirSign));
  drag = null; swipe.classList.remove('drag');
  if (x >= maxX() * .85) { setX(maxX()); start(); }
  else { resetSwipe(); if (x < 6) { swipe.classList.remove('nudge'); void swipe.offsetWidth; swipe.classList.add('nudge'); } }   // a tap: show it has to be swiped
}
knob.addEventListener('pointerup', endDrag);
knob.addEventListener('pointercancel', () => { drag = null; swipe.classList.remove('drag'); resetSwipe(); });
knob.addEventListener('keydown', e => { if ((e.key === 'Enter' || e.key === ' ') && !busy) { e.preventDefault(); setX(maxX()); start(); } });

function fail(){ busy = false; swipe.classList.remove('busy'); swTxt.innerHTML = swTxt.dataset.orig; resetSwipe(); }
function start(){
  if (busy) return;
  if (!sel.value) { resetSwipe(); document.getElementById('branchField').hidden = false; sel.focus(); return; }
  busy = true; swipe.classList.add('busy');
  swTxt.dataset.orig = swTxt.dataset.orig || swTxt.innerHTML;
  swTxt.style.opacity = '1'; swTxt.textContent = AR ? '📍 جاري تحديد الموقع…' : '📍 Getting location…';
  requestLocation(ok => {
    if (!ok) { if (locBlock) locBlock.style.display = 'block'; return fail(); }   // location is mandatory
    const d = distTo(sel.value);
    if (d !== null && d > FENCE) {
      document.getElementById('farTxt').textContent = AR
        ? 'أنت على بعد ' + fmtDist(d) + ' من فرع ' + sel.options[sel.selectedIndex].text + '. سيظهر هذا للإدارة. هل تريد التسجيل رغم ذلك؟'
        : 'You are ' + fmtDist(d) + ' from ' + sel.options[sel.selectedIndex].text + '. Management will see this. Punch anyway?';
      document.getElementById('farOv').classList.add('on');
      return;
    }
    submitNow();
  }, true);
}
function submitNow(){ swTxt.textContent = AR ? '⏳ جاري التسجيل…' : '⏳ Saving…'; if (navigator.vibrate) navigator.vibrate(30); form.submit(); }
const farOv = document.getElementById('farOv');
document.getElementById('farNo').addEventListener('click', () => { farOv.classList.remove('on'); fail(); });
farOv.addEventListener('click', e => { if (e.target === farOv) { farOv.classList.remove('on'); fail(); } });
document.getElementById('farYes').addEventListener('click', () => { farOv.classList.remove('on'); submitNow(); });
form.addEventListener('submit', e => { if (!busy) e.preventDefault(); });   // Enter in the form never punches by itself

const doneOv = document.getElementById('doneOv');
if (doneOv) {
  const close = () => doneOv.classList.remove('on');
  document.getElementById('doneOk').addEventListener('click', close);
  doneOv.addEventListener('click', e => { if (e.target === doneOv) close(); });
  celebrate();
  setTimeout(close, 6000);
}
</script>
</body>
</html>
