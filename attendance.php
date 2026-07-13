<?php
/* ============================================================
   attendance.php — Employee Clock In / Clock Out (بصمة)
   Redesigned to match First 1 Car dashboard aesthetic.
   ============================================================ */

require 'auth.php';
require 'config.php';

perm_require('page.attendance');

ini_set('display_errors', 0);

$lang = $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar','en'])) $lang = 'ar';

$userId   = $_SESSION['user_id'];
$userName = $_SESSION['username'];
$role     = $_SESSION['role'] ?? '';

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
        if (!$check->fetch()) {
            $stmt = $pdo->prepare(
                "INSERT INTO attendance_logs
                 (user_id, branch_name, clock_in, clock_in_lat, clock_in_lng, in_loc_denied, in_dist_m, status)
                 VALUES (?, ?, NOW(), ?, ?, ?, ?, 'active')"
            );
            $stmt->execute([$userId, $branchName, $lat, $lng, $locDenied, $inDist]);
        }
    } elseif ($_POST['action'] === 'clock_out') {
        $outBranch = trim($_POST['branch_name'] ?? '');
        if ($outBranch === '') {
            header('Location: attendance.php?lang=' . urlencode($lang) . '&err=branch');
            exit;
        }
        $outDist = branchDistance($pdo, $outBranch, $lat, $lng);
        $stmt = $pdo->prepare(
            "UPDATE attendance_logs
             SET clock_out = NOW(), clock_out_lat = ?, clock_out_lng = ?, out_loc_denied = ?,
                 out_branch_name = ?, out_dist_m = ?, status = 'completed'
             WHERE user_id = ? AND status = 'active'"
        );
        $stmt->execute([$lat, $lng, $locDenied, $outBranch, $outDist, $userId]);
    }
    header('Location: attendance.php?lang=' . urlencode($lang));
    exit;
}

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
foreach ($todayLogs as $t) $todayTotal += max(0, (int)$t['dur_secs']);
if ($isClockedIn) $todayTotal += $elapsedSecs;

function fmtHM($secs) {
    return sprintf('%dh %02dm', floor($secs / 3600), floor(($secs % 3600) / 60));
}

$isRTL = $lang === 'ar';
$dir   = $isRTL ? 'rtl' : 'ltr';

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
<meta name="theme-color" content="#0f172a">
<title><?= $isRTL ? 'البصمة — الحضور والانصراف' : 'Attendance' ?></title>
<style>
  *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
  body{
    font-family:'Segoe UI',Tahoma,Arial,sans-serif;
    background:linear-gradient(135deg,#020617,#0f172a);
    color:#fff; min-height:100vh; padding:20px 16px 40px;
    display:flex; flex-direction:column; align-items:center;
  }
  .wrap{ width:100%; max-width:440px; }

  .head{
    display:flex; align-items:center; justify-content:space-between; gap:12px;
    background:rgba(15,23,42,.88); border:1px solid rgba(255,255,255,.08);
    border-radius:20px; padding:14px 18px; margin-bottom:18px; backdrop-filter:blur(20px);
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
  .lang-switch a{ text-decoration:none; padding:6px 11px; border-radius:9px; background:#111827; color:#fff; font-weight:700; font-size:12px; }
  .lang-active{ background:#9333ea !important; }

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
  .timer{ font-size:42px; font-weight:800; letter-spacing:1px; font-variant-numeric:tabular-nums; line-height:1; }
  .timer.idle{ color:#475569; }
  .timer-label{ font-size:12px; color:#64748b; margin-top:8px; font-weight:700; }
  .branch-now{ font-size:13px; color:#94a3b8; margin-top:14px; font-weight:600; }
  .branch-now b{ color:#e2e8f0; }

  .field{ margin-top:20px; text-align:start; }
  .field label{ display:block; font-size:12px; color:#94a3b8; font-weight:700; margin-bottom:8px; }
  select{
    width:100%; padding:15px; border-radius:14px; font-size:15px; font-family:inherit;
    background:#0d1526; color:#f1f5f9; border:1px solid rgba(255,255,255,.1); outline:none;
  }
  select:focus{ border-color:rgba(147,51,234,.5); }

  .big-btn{
    width:100%; margin-top:20px; padding:18px; border:none; border-radius:16px;
    font-size:17px; font-weight:800; font-family:inherit; cursor:pointer; color:#fff;
    display:flex; align-items:center; justify-content:center; gap:9px;
    transition:transform .15s, box-shadow .2s;
  }
  .big-btn:active{ transform:scale(.97); }
  .big-btn.in{ background:linear-gradient(135deg,#16a34a,#22c55e); color:#022c14; box-shadow:0 10px 30px rgba(34,197,94,.3); }
  .big-btn.out{ background:linear-gradient(135deg,#dc2626,#ef4444); box-shadow:0 10px 30px rgba(239,68,68,.3); }
  .big-btn:disabled{ opacity:.6; cursor:wait; }

  .loc-status{ margin-top:14px; font-size:12px; min-height:16px; font-weight:600; }
  .loc-ok{ color:#22c55e; } .loc-bad{ color:#f87171; }
  .loc-note{ margin-top:6px; font-size:11px; color:#64748b; line-height:1.6; }
  .loc-block{
    background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.4);
    color:#fca5a5; font-size:12.5px; font-weight:700; line-height:1.6;
    border-radius:14px; padding:12px 14px; margin-top:16px;
  }

  .today-card{
    background:rgba(15,23,42,.88); border:1px solid rgba(255,255,255,.08);
    border-radius:22px; padding:18px; backdrop-filter:blur(20px);
  }
  .today-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
  .today-head h3{ font-size:14px; font-weight:800; color:#e2e8f0; }
  .today-total{ font-size:13px; font-weight:800; color:#22c55e; background:rgba(34,197,94,.12); padding:5px 12px; border-radius:999px; border:1px solid rgba(34,197,94,.25); }
  .sess-row{
    display:flex; align-items:center; justify-content:space-between; gap:10px;
    background:rgba(0,0,0,.2); border:1px solid rgba(255,255,255,.05);
    border-radius:12px; padding:11px 14px; margin-bottom:8px; font-size:13px;
  }
  .sess-row:last-child{ margin-bottom:0; }
  .sess-time{ color:#cbd5e1; font-weight:600; }
  .sess-dur{ font-weight:800; color:#a3e635; font-variant-numeric:tabular-nums; }
  .sess-auto{ font-size:10px; color:#fbbf24; }
  .today-empty{ text-align:center; color:#64748b; font-size:13px; padding:14px; }

  .back-link{ display:block; text-align:center; margin-top:20px; color:#64748b; font-size:13px; text-decoration:none; font-weight:700; }
  .back-link:hover{ color:#94a3b8; }
</style>
</head>
<body>
<div class="wrap">

  <div class="head">
    <div class="head-user">
      <div class="avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($userName,0,1))) ?></div>
      <div>
        <div class="head-name"><?= htmlspecialchars($userName) ?></div>
        <span class="head-role"><?= htmlspecialchars($role) ?></span>
      </div>
    </div>
    <div class="lang-switch">
      <a href="?lang=ar" class="<?= $isRTL ? 'lang-active' : '' ?>">ع</a>
      <a href="?lang=en" class="<?= !$isRTL ? 'lang-active' : '' ?>">EN</a>
    </div>
  </div>

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
      <input type="hidden" name="lat" id="lat">
      <input type="hidden" name="lng" id="lng">

      <div class="field">
        <label><?= $isRTL ? 'اختر الفرع' : 'Select branch' ?></label>
        <select name="branch_name" required>
          <option value=""><?= $isRTL ? '— اختر —' : '— choose —' ?></option>
          <?php foreach ($branches as $b):
            $sel = ($isClockedIn && ($active['branch_name'] ?? '') === $b['name']) ? ' selected' : '';
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
      <span class="today-total">⏱ <?= fmtHM($todayTotal) ?></span>
    </div>
    <?php if ($todayLogs): ?>
      <?php foreach ($todayLogs as $t):
        $d = max(0, (int)$t['dur_secs']);
      ?>
        <div class="sess-row">
          <span class="sess-time"><?= date('h:i A', strtotime($t['clock_in'])) ?> → <?= date('h:i A', strtotime($t['clock_out'])) ?><?= $t['auto_closed'] ? ' <span class="sess-auto">⏰</span>' : '' ?></span>
          <span class="sess-dur"><?= fmtHM($d) ?></span>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="today-empty"><?= $isRTL ? 'لا توجد جلسات مكتملة اليوم' : 'No completed sessions today' ?></div>
    <?php endif; ?>
  </div>

  <a class="back-link" href="dashboard.php?lang=<?= htmlspecialchars($lang) ?>">← <?= $isRTL ? 'الرئيسية' : 'Dashboard' ?></a>
</div>

<script>
const isClockedIn = <?= $isClockedIn ? 'true' : 'false' ?>;
let elapsed = <?= (int)$elapsedSecs ?>;
const pageLoadedAt = Date.now();
const lang = <?= json_encode($lang) ?>;

const RING_LEN = 653.45;
const ring = document.getElementById('ring');

function pad(n){ return String(n).padStart(2,'0'); }
function tick(){
  if (!isClockedIn) return;
  const total = elapsed + Math.floor((Date.now() - pageLoadedAt) / 1000);
  const h = Math.floor(total/3600), m = Math.floor((total%3600)/60), s = total%60;
  document.getElementById('timer').textContent = `${pad(h)}:${pad(m)}:${pad(s)}`;
  const frac = Math.min(1, total / (8*3600));
  if (ring) ring.style.strokeDashoffset = String(RING_LEN * (1 - frac));
}
if (isClockedIn) { tick(); setInterval(tick, 1000); }

const form = document.getElementById('attForm');
const submitBtn = document.getElementById('submitBtn');
const locStatus = document.getElementById('locStatus');

const locBlock = document.getElementById('locBlock');

function requestLocation(cb){
  if (!navigator.geolocation) {
    locStatus.textContent = lang === 'ar' ? '⚠️ الموقع غير مدعوم على هذا الجهاز' : '⚠️ Geolocation not supported on this device';
    locStatus.className = 'loc-status loc-bad';
    if (locBlock) locBlock.style.display = 'block';
    cb(false); return;
  }
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      document.getElementById('lat').value = pos.coords.latitude;
      document.getElementById('lng').value = pos.coords.longitude;
      locStatus.textContent = lang === 'ar' ? '✓ تم تحديد الموقع' : '✓ Location captured';
      locStatus.className = 'loc-status loc-ok';
      if (locBlock) locBlock.style.display = 'none';
      cb(true);
    },
    (err) => {
      const denied = err && err.code === 1; // 1 = PERMISSION_DENIED
      locStatus.textContent = denied
        ? (lang === 'ar' ? '⛔ تم رفض الموقع — لا يمكن تسجيل البصمة' : '⛔ Location denied — cannot clock in/out')
        : (lang === 'ar' ? '⚠️ تعذر تحديد الموقع — حاول مرة أخرى' : '⚠️ Location unavailable — try again');
      locStatus.className = 'loc-status loc-bad';
      if (locBlock) locBlock.style.display = 'block';
      cb(false);
    },
    { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 }
  );
}

// Ask for permission as soon as the page loads
requestLocation(() => {});

form.addEventListener('submit', function(e){
  e.preventDefault();
  const sel = form.querySelector('select[name="branch_name"]');
  if (sel && !sel.value) { sel.focus(); return; }
  const originalLabel = submitBtn.textContent;
  submitBtn.disabled = true;
  submitBtn.textContent = lang === 'ar' ? '📍 جاري تحديد الموقع...' : '📍 Getting location...';
  requestLocation((ok) => {
    if (ok) {
      form.submit();
    } else {
      // Location is mandatory — do NOT submit. Let them fix permission and retry.
      submitBtn.disabled = false;
      submitBtn.textContent = originalLabel;
      if (locBlock) locBlock.style.display = 'block';
    }
  });
});
</script>
</body>
</html>
