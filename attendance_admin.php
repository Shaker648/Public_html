<?php
/* ============================================================
   attendance_admin.php — Admin attendance log (grouped by employee)
   Redesigned to match First 1 Car dashboard aesthetic.
   ============================================================ */

require 'auth.php';
require 'config.php';

ini_set('display_errors', 0);

$lang = $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar','en'])) $lang = 'ar';
$role = $_SESSION['role'] ?? '';

// Permission-gated (default: admin only) — editable from permissions_admin.php
perm_require('page.attendance_admin');

$isRTL = $lang === 'ar';
$dir   = $isRTL ? 'rtl' : 'ltr';

// Geofence: punches farther than this many metres from the branch get a red flag.
// Set generously to absorb normal GPS drift. Lower it to be stricter.
$GEOFENCE_M = 50;

/* ---------------- Filters ---------------- */
$filterBranch = $_GET['branch'] ?? '';
$filterUser   = $_GET['user_id'] ?? '';
$filterFrom   = $_GET['from'] ?? date('Y-m-d');
$filterTo     = $_GET['to'] ?? date('Y-m-d');

$where  = ["DATE(a.clock_in) BETWEEN ? AND ?"];
$params = [$filterFrom, $filterTo];
if ($filterBranch !== '') { $where[] = "a.branch_name = ?"; $params[] = $filterBranch; }
if ($filterUser !== '')   { $where[] = "a.user_id = ?";     $params[] = (int)$filterUser; }
$whereSql = implode(' AND ', $where);

/* Auto clock-out forgotten sessions (>14h) */
$pdo->exec(
    "UPDATE attendance_logs
     SET clock_out = DATE_ADD(clock_in, INTERVAL 14 HOUR),
         status = 'completed', auto_closed = 1
     WHERE status = 'active' AND clock_in < DATE_SUB(NOW(), INTERVAL 14 HOUR)"
);

$sql = "SELECT a.*, u.username, u.role AS user_role,
               b.name_ar AS branch_ar, b.name_en AS branch_en,
               TIMESTAMPDIFF(SECOND, a.clock_in, COALESCE(a.clock_out, NOW())) AS dur_secs
        FROM attendance_logs a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN branches b ON b.name = a.branch_name
        WHERE $whereSql
        ORDER BY a.clock_in DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------------- Group by employee ---------------- */
$people = [];   // user_id => ['name','role','total','sessions'=>[], 'active'=>bool]
$grandTotal = 0;
$activeNow = 0;
foreach ($logs as $log) {
    $uid = $log['user_id'];
    if (!isset($people[$uid])) {
        $people[$uid] = [
            'name'     => $log['username'] ?? ('#' . $uid),
            'role'     => $log['user_role'] ?? '',
            'total'    => 0,
            'sessions' => [],
            'active'   => false,
        ];
    }
    $secs = max(0, (int)$log['dur_secs']);
    $people[$uid]['total'] += $secs;
    $people[$uid]['sessions'][] = $log;
    if ($log['status'] === 'active') { $people[$uid]['active'] = true; $activeNow++; }
    $grandTotal += $secs;
}
// Sort people by most hours first
uasort($people, fn($a,$b) => $b['total'] <=> $a['total']);

$branches = $pdo->query("SELECT name, name_ar, name_en FROM branches ORDER BY name_en")->fetchAll(PDO::FETCH_ASSOC);
$users    = $pdo->query("SELECT id, username FROM users WHERE active = 1 ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

function fmtDur($secs) {
    return sprintf('%dh %02dm', floor($secs / 3600), floor(($secs % 3600) / 60));
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f172a">
<title><?= $isRTL ? 'سجل البصمة' : 'Attendance Log' ?></title>
<style>
  *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
  body{
    font-family:'Segoe UI',Tahoma,Arial,sans-serif;
    background:linear-gradient(135deg,#020617,#0f172a);
    color:#fff; min-height:100vh; padding:20px 16px 50px;
  }
  .wrap{ max-width:820px; margin:0 auto; }

  .topbar{
    display:flex; align-items:center; justify-content:space-between; gap:12px;
    background:rgba(15,23,42,.88); border:1px solid rgba(255,255,255,.08);
    border-radius:20px; padding:16px 20px; margin-bottom:16px; backdrop-filter:blur(20px);
  }
  .topbar h1{ font-size:19px; font-weight:800; }
  .lang-switch{ display:flex; gap:6px; }
  .lang-switch a{ text-decoration:none; padding:6px 11px; border-radius:9px; background:#111827; color:#fff; font-weight:700; font-size:12px; }
  .lang-active{ background:#9333ea !important; }

  /* Summary stat cards */
  .stats{ display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:16px; }
  .stat{
    background:rgba(15,23,42,.88); border:1px solid rgba(255,255,255,.08);
    border-radius:18px; padding:16px; text-align:center; backdrop-filter:blur(20px);
  }
  .stat-num{ font-size:26px; font-weight:800; line-height:1; }
  .stat-num.green{ color:#22c55e; } .stat-num.purple{ color:#a855f7; } .stat-num.amber{ color:#f59e0b; }
  .stat-lbl{ font-size:11px; color:#94a3b8; font-weight:700; margin-top:6px; }

  /* Filters */
  .filters{
    display:flex; flex-wrap:wrap; gap:8px; margin-bottom:18px;
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
  .p-meta{ font-size:12px; color:#94a3b8; margin-top:4px; }
  .p-total{ text-align:end; flex-shrink:0; }
  .p-total-num{ font-size:18px; font-weight:800; color:#a3e635; font-variant-numeric:tabular-nums; }
  .p-total-lbl{ font-size:10px; color:#64748b; font-weight:700; }
  .p-chevron{ margin-inline-start:6px; color:#64748b; font-size:14px; transition:transform .25s; flex-shrink:0; }
  .person.open .p-chevron{ transform:rotate(180deg); }

  .person-body{ display:none; padding:0 14px 14px; }
  .person.open .person-body{ display:block; }
  .sess{
    display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;
    background:rgba(0,0,0,.22); border:1px solid rgba(255,255,255,.05);
    border-radius:13px; padding:11px 14px; margin-bottom:8px;
  }
  .sess:last-child{ margin-bottom:0; }
  .sess-left{ display:flex; flex-direction:column; gap:3px; min-width:0; }
  .sess-time{ font-size:13px; color:#e2e8f0; font-weight:700; font-variant-numeric:tabular-nums; }
  .sess-branch{ font-size:11px; color:#94a3b8; }
  .sess-right{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
  .sess-dur{ font-size:13px; font-weight:800; color:#a3e635; font-variant-numeric:tabular-nums; }
  .badge{ padding:3px 9px; border-radius:999px; font-size:10px; font-weight:800; }
  .badge.active{ background:rgba(34,197,94,.15); color:#22c55e; border:1px solid rgba(34,197,94,.3); }
  .badge.done{ background:rgba(255,255,255,.06); color:#94a3b8; }
  .badge.auto{ background:rgba(245,158,11,.15); color:#fbbf24; border:1px solid rgba(245,158,11,.3); }
  .badge.denied{ background:rgba(239,68,68,.15); color:#f87171; border:1px solid rgba(239,68,68,.3); }
  .loc-link{ color:#5aa9ff; text-decoration:none; font-size:11px; font-weight:700; }
  .loc-link:hover{ text-decoration:underline; }

  .empty{
    background:rgba(15,23,42,.88); border:1px solid rgba(255,255,255,.08);
    border-radius:20px; padding:50px 30px; text-align:center; color:#64748b; backdrop-filter:blur(20px);
  }
  .empty h2{ font-size:18px; margin-bottom:8px; color:#94a3b8; }

  .back-link{ display:block; text-align:center; margin-top:20px; color:#64748b; font-size:13px; text-decoration:none; font-weight:700; }
  .back-link:hover{ color:#94a3b8; }
</style>
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <h1>🕐 <?= $isRTL ? 'سجل البصمة' : 'Attendance Log' ?></h1>
    <div class="lang-switch">
      <a href="branch_geo.php?lang=<?= htmlspecialchars($lang) ?>" title="<?= $isRTL ? 'مواقع الفروع' : 'Branch locations' ?>">📍</a>
      <a href="?lang=ar&from=<?= $filterFrom ?>&to=<?= $filterTo ?>" class="<?= $isRTL ? 'lang-active' : '' ?>">ع</a>
      <a href="?lang=en&from=<?= $filterFrom ?>&to=<?= $filterTo ?>" class="<?= !$isRTL ? 'lang-active' : '' ?>">EN</a>
    </div>
  </div>

  <div class="stats">
    <div class="stat"><div class="stat-num green"><?= count($people) ?></div><div class="stat-lbl"><?= $isRTL ? 'موظفين' : 'Employees' ?></div></div>
    <div class="stat"><div class="stat-num amber"><?= $activeNow ?></div><div class="stat-lbl"><?= $isRTL ? 'متواجد الآن' : 'Active now' ?></div></div>
    <div class="stat"><div class="stat-num purple"><?= fmtDur($grandTotal) ?></div><div class="stat-lbl"><?= $isRTL ? 'إجمالي الفترة' : 'Range total' ?></div></div>
  </div>

  <form class="filters" method="GET">
    <input type="hidden" name="lang" value="<?= htmlspecialchars($lang) ?>">
    <input type="date" name="from" value="<?= htmlspecialchars($filterFrom) ?>">
    <input type="date" name="to" value="<?= htmlspecialchars($filterTo) ?>">
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

  <?php if (!$people): ?>
    <div class="empty">
      <h2>📭 <?= $isRTL ? 'لا توجد سجلات' : 'No records' ?></h2>
      <p><?= $isRTL ? 'جرّب تغيير التاريخ أو الفلاتر' : 'Try changing the date or filters' ?></p>
    </div>
  <?php else: foreach ($people as $uid => $p):
      $initial = mb_strtoupper(mb_substr($p['name'], 0, 1));
  ?>
    <div class="person <?= $p['active'] ? 'is-active' : '' ?>" data-person>
      <div class="person-head" onclick="this.parentElement.classList.toggle('open')">
        <div class="p-avatar"><?= htmlspecialchars($initial) ?></div>
        <div class="p-info">
          <div class="p-name">
            <?= htmlspecialchars($p['name']) ?>
            <?php if ($p['role']): ?><span class="p-role"><?= htmlspecialchars($p['role']) ?></span><?php endif; ?>
            <?php if ($p['active']): ?><span class="p-live">● <?= $isRTL ? 'متواجد' : 'LIVE' ?></span><?php endif; ?>
          </div>
          <div class="p-meta"><?= count($p['sessions']) ?> <?= $isRTL ? 'جلسة' : 'sessions' ?></div>
        </div>
        <div class="p-total">
          <div class="p-total-num"><?= fmtDur($p['total']) ?></div>
          <div class="p-total-lbl"><?= $isRTL ? 'إجمالي' : 'total' ?></div>
        </div>
        <span class="p-chevron">▼</span>
      </div>

      <div class="person-body">
        <?php foreach ($p['sessions'] as $log):
          $branchDisp = $isRTL ? ($log['branch_ar'] ?? $log['branch_name']) : ($log['branch_en'] ?? $log['branch_name']);
          $secs = max(0, (int)$log['dur_secs']);
        ?>
          <div class="sess">
            <div class="sess-left">
              <div class="sess-time">
                <?= date('d/m · h:i A', strtotime($log['clock_in'])) ?>
                <?= $log['clock_out'] ? ' → ' . date('h:i A', strtotime($log['clock_out'])) : ' → …' ?>
              </div>
              <?php if ($branchDisp): ?><div class="sess-branch">📍 <?= htmlspecialchars($branchDisp) ?></div><?php endif; ?>
            </div>
            <div class="sess-right">
              <span class="sess-dur"><?= fmtDur($secs) ?></span>
              <?php if (!empty($log['auto_closed'])): ?>
                <span class="badge auto">⏰ <?= $isRTL ? 'تلقائي' : 'Auto' ?></span>
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

              <?php if (isset($log['in_dist_m']) && $log['in_dist_m'] !== null && (int)$log['in_dist_m'] > $GEOFENCE_M): ?>
                <span class="badge denied">🛑 <?= $isRTL ? 'دخول بعيد' : 'In far' ?> <?= (int)$log['in_dist_m'] ?>m</span>
              <?php endif; ?>
              <?php if (isset($log['out_dist_m']) && $log['out_dist_m'] !== null && (int)$log['out_dist_m'] > $GEOFENCE_M): ?>
                <span class="badge denied">🛑 <?= $isRTL ? 'خروج بعيد' : 'Out far' ?> <?= (int)$log['out_dist_m'] ?>m</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; endif; ?>

  <a class="back-link" href="dashboard.php?lang=<?= htmlspecialchars($lang) ?>">← <?= $isRTL ? 'الرئيسية' : 'Dashboard' ?></a>
</div>

<script>
// Auto-expand the first (top) employee card, and anyone currently active
document.querySelectorAll('[data-person]').forEach((el, i) => {
  if (i === 0 || el.classList.contains('is-active')) el.classList.add('open');
});
</script>
</body>
</html>
