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

$branches = $pdo->query("SELECT name, name_ar, name_en FROM branches ORDER BY name_en")->fetchAll(PDO::FETCH_ASSOC);

/* Today's completed sessions */
$todayStmt = $pdo->prepare(
    "SELECT clock_in, clock_out, auto_closed,
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
    color:#fff; min-height:100vh; padding:20px 16px 40px;
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

  .big-btn{
    width:100%; margin-top:20px; padding:19px; border:none; border-radius:16px;
    font-size:18px; font-weight:800; font-family:inherit; cursor:pointer; color:#fff;
    display:flex; align-items:center; justify-content:center; gap:9px;
    transition:transform .15s, box-shadow .2s; -webkit-tap-highlight-color:transparent;
  }
  .big-btn:active{ transform:scale(.97); }
  .big-btn.in{ background:linear-gradient(135deg,#16a34a,#22c55e); color:#022c14; box-shadow:0 10px 30px rgba(34,197,94,.3); }
  .big-btn.out{ background:linear-gradient(135deg,#dc2626,#ef4444); box-shadow:0 10px 30px rgba(239,68,68,.3); }
  .big-btn:disabled{ opacity:.6; cursor:wait; }

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
  .today-empty{ text-align:center; color:#64748b; font-size:13px; padding:14px; }

  /* this week */
  .week-bars{ display:grid; grid-template-columns:repeat(7,1fr); gap:6px; align-items:end; height:120px; }
  .wb{ display:flex; flex-direction:column; align-items:center; justify-content:flex-end; height:100%; gap:5px; }
  .wb .v{ font-size:10px; font-weight:800; color:#a3e635; font-variant-numeric:tabular-nums; min-height:12px; }
  .wb .col{ width:100%; max-width:30px; border-radius:8px 8px 4px 4px; background:rgba(255,255,255,.06); position:relative; overflow:hidden; height:70px; }
  .wb .col i{ position:absolute; left:0; right:0; bottom:0; border-radius:8px 8px 4px 4px; background:linear-gradient(180deg,#4ade80,#16a34a); height:0; transition:height .9s cubic-bezier(.22,1,.36,1); }
  .wb.today .col{ outline:2px solid rgba(168,85,247,.6); outline-offset:1px; }
  .wb.future .col{ background:rgba(255,255,255,.025); }
  .wb .n{ font-size:11px; font-weight:800; color:#94a3b8; }
  .wb.today .n{ color:#d8b4fe; }
  .week-sub{ font-size:12px; color:#94a3b8; font-weight:700; margin-top:12px; text-align:center; }

  .back-link{ display:block; text-align:center; margin-top:6px; color:#64748b; font-size:13px; text-decoration:none; font-weight:700; }
  .back-link:hover{ color:#94a3b8; }

  /* sheets: confirm clock-out, success */
  .ov{ position:fixed; inset:0; z-index:100; background:rgba(2,6,23,.8); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); display:none; align-items:flex-end; justify-content:center; padding:16px; padding-bottom:calc(16px + env(safe-area-inset-bottom)); }
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
  .done-sheet .ok{ width:100%; height:50px; border-radius:15px; border:0; margin-top:18px; font:inherit; font-size:16px; font-weight:800; cursor:pointer; background:rgba(255,255,255,.08); color:#e2e8f0; }
  @keyframes draw{ to{ stroke-dashoffset:0; } }
  @keyframes fadeIn{ from{ opacity:0; } }
  @keyframes up{ from{ opacity:0; transform:translateY(30px); } }
  @media (prefers-reduced-motion:reduce){ .check path{ animation:none; stroke-dashoffset:0; } .sheet{ animation:none; } }
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

  <div class="clock-card <?= $isClockedIn ? 'is-in' : '' ?>">
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
        </defs>
        <circle class="ring-bg" cx="115" cy="115" r="104"/>
        <circle class="ring-fg" id="ring" cx="115" cy="115" r="104"
                stroke-dasharray="653.45" stroke-dashoffset="653.45"/>
      </svg>
      <div class="ring-center">
        <div class="timer <?= $isClockedIn ? '' : 'idle' ?>" id="timer"><?= $isClockedIn ? '00:00:00' : '--:--:--' ?></div>
        <div class="timer-label"><?= $isClockedIn ? ($isRTL ? 'وقت الجلسة الحالية' : 'Current session') : ($isRTL ? 'ابدأ الحضور' : 'Start your shift') ?></div>
        <?php if ($isClockedIn): ?><div class="since"><?= $isRTL ? 'منذ' : 'since' ?> <?= htmlspecialchars(fmtT($active['clock_in'])) ?></div><?php endif; ?>
      </div>
    </div>

    <?php if ($isClockedIn && $activeBranchDisp): ?>
      <div class="branch-now">📍 <?= $isRTL ? 'الفرع' : 'Branch' ?>: <b><?= htmlspecialchars($activeBranchDisp) ?></b></div>
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

      <div class="field">
        <label><?= $isRTL ? 'اختر الفرع' : 'Select branch' ?></label>
        <select name="branch_name" required>
          <option value=""><?= $isRTL ? '— اختر —' : '— choose —' ?></option>
          <?php foreach ($branches as $b):
            $pick = $isClockedIn ? ($active['branch_name'] ?? '') : $lastBranch;   // clock-in branch / the one used last time
            $sel = $pick === $b['name'] ? ' selected' : '';
          ?>
            <option value="<?= htmlspecialchars($b['name']) ?>"<?= $sel ?>><?= htmlspecialchars($isRTL ? $b['name_ar'] : $b['name_en']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <button type="submit" id="submitBtn" class="big-btn <?= $isClockedIn ? 'out' : 'in' ?>">
        <?= $isClockedIn ? '🔴 ' . ($isRTL ? 'تسجيل انصراف' : 'Clock Out') : '🟢 ' . ($isRTL ? 'تسجيل حضور' : 'Clock In') ?>
      </button>
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
    <?php if ($todayLogs): ?>
      <?php foreach ($todayLogs as $t):
        $d = max(0, (int)$t['dur_secs']);
      ?>
        <div class="sess-row">
          <span class="sess-time"><?= htmlspecialchars(fmtT($t['clock_in'])) ?> → <?= htmlspecialchars(fmtT($t['clock_out'])) ?>
            <?php if ($t['auto_closed']): ?><span class="sess-auto">⏰ <?= $isRTL ? 'نسيت تسجيل الانصراف — لا تُحسب' : 'Forgot to clock out — not counted' ?></span><?php endif; ?></span>
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
      <h3>🗓️ <?= $isRTL ? 'هذا الأسبوع' : 'This week' ?></h3>
      <span class="today-total" id="weekTotal">⏱ <?= fmtHM($weekTotal) ?></span>
    </div>
    <?php
      $wdn = $isRTL ? ['ح', 'ن', 'ث', 'ر', 'خ', 'ج', 'س'] : ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
      $maxW = max(8 * 3600, $weekBy ? max($weekBy) : 0);
    ?>
    <div class="week-bars">
      <?php for ($i = 0; $i < 7; $i++):
        $d = date('Y-m-d', strtotime("+$i day", strtotime($weekStart)));
        $secs = $weekBy[$d] ?? 0;
        $cls = $d === $today ? 'today' : ($d > $today ? 'future' : '');
      ?>
        <div class="wb <?= $cls ?>" <?= $d === $today ? 'id="wbToday"' : '' ?>>
          <span class="v"><?= $secs >= 180 ? round($secs / 3600, 1) : '' ?></span>
          <span class="col"><i data-h="<?= round($secs / $maxW * 100, 1) ?>"></i></span>
          <span class="n"><?= $wdn[(int)date('w', strtotime($d))] ?></span>
        </div>
      <?php endfor; ?>
    </div>
    <div class="week-sub"><?= $weekDays ?> <?= $isRTL ? ($weekDays === 1 ? 'يوم حضور' : 'أيام حضور') : ($weekDays === 1 ? 'day worked' : 'days worked') ?><?= $weekDays ? ' · ⌀ ' . fmtHM(intdiv($weekTotal, max(1, $weekDays))) . ($isRTL ? ' يومياً' : ' / day') : '' ?></div>
  </div>

  <a class="back-link" href="dashboard.php?lang=<?= htmlspecialchars($lang) ?>">← <?= $isRTL ? 'الرئيسية' : 'Dashboard' ?></a>
</div>

<!-- confirm clock-out -->
<div class="ov" id="outOv">
  <div class="sheet">
    <div class="ic">🔴</div>
    <h3><?= $isRTL ? 'تسجيل الانصراف الآن؟' : 'Clock out now?' ?></h3>
    <p><?= $isRTL ? 'مدة جلستك الحالية' : 'Your current session' ?></p>
    <div class="big" id="outDur">—</div>
    <div class="acts">
      <button type="button" class="no" id="outNo"><?= $isRTL ? 'رجوع' : 'Back' ?></button>
      <button type="button" class="yes" id="outYes"><?= $isRTL ? 'نعم، انصراف' : 'Yes, clock out' ?></button>
    </div>
  </div>
</div>

<?php if ($flash && in_array($flash['type'], ['in', 'out'], true)): $isIn = $flash['type'] === 'in'; ?>
<!-- done -->
<div class="ov on" id="doneOv">
  <div class="sheet done-sheet <?= $isIn ? '' : 'out' ?>">
    <div class="check"><svg viewBox="0 0 50 50"><path d="M13 26 l8 8 l16 -18"/></svg></div>
    <h3><?= $isIn ? ($isRTL ? 'تم تسجيل الحضور' : 'Clocked in') : ($isRTL ? 'تم تسجيل الانصراف' : 'Clocked out') ?></h3>
    <div class="when"><?= htmlspecialchars(fmtT($flash['time'])) ?></div>
    <div class="where">📍 <?= htmlspecialchars($branchLabel($flash['branch'])) ?></div>
    <?php if (!$isIn): ?><div class="dur">⏱️ <?= $isRTL ? 'مدة الجلسة' : 'Session' ?> <?= fmtHM((int)$flash['secs']) ?></div><?php endif; ?>
    <button type="button" class="ok" id="doneOk"><?= $isIn ? ($isRTL ? 'يوم موفق 👋' : 'Have a good day 👋') : ($isRTL ? 'تمام ✓' : 'OK ✓') ?></button>
  </div>
</div>
<?php endif; ?>

<script>
const isClockedIn = <?= $isClockedIn ? 'true' : 'false' ?>;
let elapsed = <?= (int)$elapsedSecs ?>;
const pageLoadedAt = Date.now();
const lang = <?= json_encode($lang) ?>;
const AR = lang === 'ar';
const todayBase = <?= (int)($todayTotal - $elapsedSecs) ?>, weekBase = <?= (int)($weekTotal - ($isClockedIn ? $elapsedSecs : 0)) ?>;

const RING_LEN = 653.45;
const ring = document.getElementById('ring');

function pad(n){ return String(n).padStart(2,'0'); }
const hm = s => AR ? Math.floor(s / 3600) + 'س ' + pad(Math.floor(s % 3600 / 60)) + 'د' : Math.floor(s / 3600) + 'h ' + pad(Math.floor(s % 3600 / 60)) + 'm';
function sessionSecs(){ return elapsed + Math.floor((Date.now() - pageLoadedAt) / 1000); }
function tick(){
  if (!isClockedIn) return;
  const total = sessionSecs();
  const h = Math.floor(total/3600), m = Math.floor((total%3600)/60), s = total%60;
  document.getElementById('timer').textContent = `${pad(h)}:${pad(m)}:${pad(s)}`;
  const frac = Math.min(1, total / (8*3600));
  if (ring) ring.style.strokeDashoffset = String(RING_LEN * (1 - frac));
  if (s === 0 || total === elapsed) {    // today / week totals move with the session
    document.getElementById('todayTotal').textContent = '⏱ ' + hm(todayBase + total);
    document.getElementById('weekTotal').textContent = '⏱ ' + hm(weekBase + total);
  }
  const od = document.getElementById('outDur'); if (od) od.textContent = hm(total);
}
if (isClockedIn) { tick(); setInterval(tick, 1000); }

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

const form = document.getElementById('attForm');
const submitBtn = document.getElementById('submitBtn');
const locStatus = document.getElementById('locStatus');
const locBlock = document.getElementById('locBlock');

// high accuracy first; if it can't get a fix (indoors), try again with normal accuracy
function requestLocation(cb){
  if (!navigator.geolocation) {
    locStatus.textContent = AR ? '⚠️ الموقع غير مدعوم على هذا الجهاز' : '⚠️ Geolocation not supported on this device';
    locStatus.className = 'loc-status loc-bad';
    if (locBlock) locBlock.style.display = 'block';
    cb(false); return;
  }
  const ok = (pos) => {
    document.getElementById('lat').value = pos.coords.latitude;
    document.getElementById('lng').value = pos.coords.longitude;
    const acc = Math.round(pos.coords.accuracy || 0);
    locStatus.textContent = (AR ? '✓ تم تحديد الموقع' : '✓ Location captured') + (acc ? (AR ? ' (دقة ±' + acc + ' م)' : ' (±' + acc + ' m)') : '');
    locStatus.className = 'loc-status loc-ok';
    if (locBlock) locBlock.style.display = 'none';
    cb(true);
  };
  const fail = (err) => {
    const denied = err && err.code === 1; // 1 = PERMISSION_DENIED
    locStatus.textContent = denied
      ? (AR ? '⛔ تم رفض الموقع — لا يمكن تسجيل البصمة' : '⛔ Location denied — cannot clock in/out')
      : (AR ? '⚠️ تعذر تحديد الموقع — تأكد من تشغيل GPS وحاول مرة أخرى' : '⚠️ Location unavailable — make sure GPS is on and try again');
    locStatus.className = 'loc-status loc-bad';
    if (locBlock) locBlock.style.display = 'block';
    cb(false);
  };
  navigator.geolocation.getCurrentPosition(ok, (err) => {
    if (err && err.code === 1) return fail(err);
    locStatus.textContent = AR ? '📡 جاري تحديد الموقع…' : '📡 Getting your location…';
    locStatus.className = 'loc-status loc-wait';
    navigator.geolocation.getCurrentPosition(ok, fail, { enableHighAccuracy: false, timeout: 15000, maximumAge: 60000 });
  }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 });
}

// Ask for permission as soon as the page loads
requestLocation(() => {});

function punch(){
  const originalLabel = submitBtn.innerHTML;
  submitBtn.disabled = true;
  submitBtn.textContent = AR ? '📍 جاري تحديد الموقع...' : '📍 Getting location...';
  requestLocation((ok) => {
    if (ok) {
      submitBtn.textContent = AR ? '⏳ جاري التسجيل...' : '⏳ Saving...';
      form.submit();
    } else {
      // Location is mandatory — do NOT submit. Let them fix permission and retry.
      submitBtn.disabled = false;
      submitBtn.innerHTML = originalLabel;
      if (locBlock) locBlock.style.display = 'block';
    }
  });
}

const outOv = document.getElementById('outOv');
form.addEventListener('submit', function(e){
  e.preventDefault();
  const sel = form.querySelector('select[name="branch_name"]');
  if (sel && !sel.value) { sel.focus(); return; }
  if (isClockedIn) { tick(); outOv.classList.add('on'); return; }   // one wrong tap shouldn't end the shift
  punch();
});
document.getElementById('outNo').addEventListener('click', () => outOv.classList.remove('on'));
outOv.addEventListener('click', e => { if (e.target === outOv) outOv.classList.remove('on'); });
document.getElementById('outYes').addEventListener('click', () => { outOv.classList.remove('on'); punch(); });

const doneOv = document.getElementById('doneOv');
if (doneOv) {
  const close = () => doneOv.classList.remove('on');
  document.getElementById('doneOk').addEventListener('click', close);
  doneOv.addEventListener('click', e => { if (e.target === doneOv) close(); });
  if (navigator.vibrate) navigator.vibrate(60);
  setTimeout(close, 6000);
}
</script>
</body>
</html>
