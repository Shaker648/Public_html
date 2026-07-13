<?php
/* ============================================================
   branch_geo.php — Set each branch's map location (geofence)
   Admin-only. Replaces editing coordinates by hand in SQL.
   Two ways to set a branch:
     1) Stand at the branch and tap "📍 موقعي" (uses your GPS)
     2) Paste "lat, lng" copied from Google Maps
   ============================================================ */

require 'auth.php';
require 'config.php';

ini_set('display_errors', 0);

$lang = $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar','en'])) $lang = 'ar';
$role = $_SESSION['role'] ?? '';
perm_require('page.branch_geo');

$isRTL = $lang === 'ar';
$dir   = $isRTL ? 'rtl' : 'ltr';
$saved = false;

/* ---------------- Save ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $names = $_POST['name'] ?? [];
    $lats  = $_POST['lat']  ?? [];
    $lngs  = $_POST['lng']  ?? [];
    $upd = $pdo->prepare("UPDATE branches SET lat = ?, lng = ? WHERE name = ?");
    foreach ($names as $i => $bn) {
        $la = trim($lats[$i] ?? '');
        $ln = trim($lngs[$i] ?? '');
        // allow "lat, lng" pasted into the lat box
        if ($la !== '' && strpos($la, ',') !== false && $ln === '') {
            [$la, $ln] = array_map('trim', explode(',', $la, 2));
        }
        $laVal = is_numeric($la) ? (float)$la : null;
        $lnVal = is_numeric($ln) ? (float)$ln : null;
        $upd->execute([$laVal, $lnVal, $bn]);
    }
    header('Location: branch_geo.php?lang='.urlencode($lang).'&saved=1');
    exit;
}

$branches = $pdo->query("SELECT name, name_ar, name_en, lat, lng FROM branches ORDER BY name_en")->fetchAll(PDO::FETCH_ASSOC);
$saved = isset($_GET['saved']);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f172a">
<title><?= $isRTL ? 'مواقع الفروع' : 'Branch Locations' ?></title>
<style>
  *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
  body{ font-family:'Segoe UI',Tahoma,Arial,sans-serif; background:linear-gradient(135deg,#020617,#0f172a);
        color:#fff; min-height:100vh; padding:20px 16px 50px; }
  .wrap{ max-width:640px; margin:0 auto; }
  .topbar{ display:flex; align-items:center; justify-content:space-between; gap:12px;
    background:rgba(15,23,42,.88); border:1px solid rgba(255,255,255,.08);
    border-radius:20px; padding:16px 20px; margin-bottom:14px; backdrop-filter:blur(20px); }
  .topbar h1{ font-size:19px; font-weight:800; }
  .lang-switch{ display:flex; gap:6px; }
  .lang-switch a{ text-decoration:none; padding:6px 11px; border-radius:9px; background:#111827; color:#fff; font-weight:700; font-size:12px; }
  .lang-active{ background:#9333ea !important; }
  .note{ background:rgba(147,51,234,.1); border:1px solid rgba(147,51,234,.25); color:#c4b5fd;
    border-radius:14px; padding:13px 16px; font-size:12.5px; line-height:1.7; margin-bottom:14px; }
  .saved{ background:rgba(34,197,94,.14); border:1px solid rgba(34,197,94,.35); color:#86efac;
    border-radius:14px; padding:12px 16px; font-size:13px; font-weight:700; margin-bottom:14px; text-align:center; }
  .card{ background:rgba(15,23,42,.88); border:1px solid rgba(255,255,255,.08);
    border-radius:18px; padding:16px; margin-bottom:12px; backdrop-filter:blur(20px); }
  .card.unset{ border-color:rgba(245,158,11,.4); }
  .card.ok{ border-color:rgba(34,197,94,.3); }
  .b-name{ font-size:15px; font-weight:800; margin-bottom:4px; }
  .b-status{ font-size:11px; font-weight:700; margin-bottom:12px; }
  .b-status.ok{ color:#22c55e; } .b-status.unset{ color:#f59e0b; }
  .row{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
  .row input{ flex:1; min-width:120px; background:#0d1526; border:1px solid rgba(255,255,255,.1);
    color:#f1f5f9; padding:11px 12px; border-radius:10px; font-family:inherit; font-size:14px; outline:none; }
  .row input:focus{ border-color:rgba(147,51,234,.5); }
  .gps-btn{ background:rgba(34,197,94,.15); color:#4ade80; border:1px solid rgba(34,197,94,.3);
    padding:11px 14px; border-radius:10px; font-weight:800; font-size:13px; cursor:pointer; font-family:inherit; white-space:nowrap; }
  .gps-btn:hover{ background:rgba(34,197,94,.28); }
  .maps-link{ display:inline-block; margin-top:8px; font-size:12px; color:#5aa9ff; text-decoration:none; }
  .save-bar{ position:sticky; bottom:0; padding-top:8px; }
  .save-btn{ width:100%; padding:16px; border:none; border-radius:14px; font-size:16px; font-weight:800;
    font-family:inherit; cursor:pointer; color:#fff; background:linear-gradient(135deg,#9333ea,#2563eb);
    box-shadow:0 10px 30px rgba(147,51,234,.3); }
  .back-link{ display:block; text-align:center; margin-top:16px; color:#64748b; font-size:13px; text-decoration:none; font-weight:700; }
</style>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <h1>📍 <?= $isRTL ? 'مواقع الفروع' : 'Branch Locations' ?></h1>
    <div class="lang-switch">
      <a href="?lang=ar" class="<?= $isRTL ? 'lang-active' : '' ?>">ع</a>
      <a href="?lang=en" class="<?= !$isRTL ? 'lang-active' : '' ?>">EN</a>
    </div>
  </div>

  <?php if ($saved): ?><div class="saved">✓ <?= $isRTL ? 'تم حفظ المواقع' : 'Locations saved' ?></div><?php endif; ?>

  <div class="note">
    <?= $isRTL
      ? '<b>طريقتان لضبط موقع كل فرع:</b><br>1) قف داخل الفرع واضغط «📍 موقعي» ليأخذ إحداثياتك.<br>2) أو من خرائط جوجل: اضغط مطوّلاً على الفرع، ثم انسخ الإحداثيات (مثال: 30.107, 31.34) والصقها في خانة «الإحداثيات».<br>بعد الحفظ، أي بصمة أبعد من ٣٠٠ متر من الفرع ستظهر بعلامة حمراء.'
      : '<b>Two ways to set each branch:</b><br>1) Stand inside the branch and tap "📍 My location".<br>2) Or in Google Maps: long-press the branch, copy the coordinates (e.g. 30.107, 31.34) and paste into the Lat box.<br>After saving, any punch more than 300m from the branch shows a red flag.' ?>
  </div>

  <form method="POST">
    <input type="hidden" name="lang" value="<?= htmlspecialchars($lang) ?>">
    <?php foreach ($branches as $i => $b):
      $hasCoords = $b['lat'] !== null && $b['lng'] !== null;
      // treat the old dummy placeholder as "not set"
      $isDummy = $hasCoords && abs((float)$b['lat'] - 30.0) < 0.00001 && abs((float)$b['lng'] - 31.0) < 0.00001;
      $good = $hasCoords && !$isDummy;
    ?>
      <div class="card <?= $good ? 'ok' : 'unset' ?>">
        <div class="b-name"><?= htmlspecialchars($isRTL ? $b['name_ar'] : $b['name_en']) ?></div>
        <div class="b-status <?= $good ? 'ok' : 'unset' ?>">
          <?= $good ? '✓ ' . ($isRTL ? 'تم الضبط' : 'Set') : '⚠️ ' . ($isRTL ? 'غير مضبوط' : 'Not set') ?>
        </div>
        <input type="hidden" name="name[]" value="<?= htmlspecialchars($b['name']) ?>">
        <div class="row">
          <input type="text" name="lat[]" placeholder="<?= $isRTL ? 'خط العرض (Lat) أو الصق: 30.1, 31.3' : 'Lat (or paste 30.1, 31.3)' ?>"
                 value="<?= $good ? htmlspecialchars($b['lat']) : '' ?>" data-lat>
          <input type="text" name="lng[]" placeholder="<?= $isRTL ? 'خط الطول (Lng)' : 'Lng' ?>"
                 value="<?= $good ? htmlspecialchars($b['lng']) : '' ?>" data-lng>
          <button type="button" class="gps-btn" data-gps>📍 <?= $isRTL ? 'موقعي' : 'My loc' ?></button>
        </div>
        <?php if ($good): ?>
          <a class="maps-link" target="_blank" rel="noopener" href="https://www.google.com/maps?q=<?= $b['lat'] ?>,<?= $b['lng'] ?>">🔗 <?= $isRTL ? 'عرض على الخريطة' : 'View on map' ?></a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <div class="save-bar">
      <button type="submit" class="save-btn">💾 <?= $isRTL ? 'حفظ كل المواقع' : 'Save all locations' ?></button>
    </div>
  </form>

  <a class="back-link" href="attendance_admin.php?lang=<?= htmlspecialchars($lang) ?>">← <?= $isRTL ? 'سجل البصمة' : 'Attendance Log' ?></a>
</div>

<script>
const lang = <?= json_encode($lang) ?>;
// Paste "lat, lng" into the Lat box → auto-split into both boxes
document.querySelectorAll('[data-lat]').forEach(latEl => {
  latEl.addEventListener('input', () => {
    if (latEl.value.includes(',')) {
      const parts = latEl.value.split(',');
      const lngEl = latEl.closest('.row').querySelector('[data-lng]');
      latEl.value = parts[0].trim();
      if (lngEl) lngEl.value = parts[1].trim();
    }
  });
});
// "My location" → fill this branch's boxes from GPS
document.querySelectorAll('[data-gps]').forEach(btn => {
  btn.addEventListener('click', () => {
    if (!navigator.geolocation) { alert(lang==='ar'?'الموقع غير مدعوم':'Geolocation not supported'); return; }
    btn.textContent = '⏳';
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const row = btn.closest('.row');
        row.querySelector('[data-lat]').value = pos.coords.latitude.toFixed(7);
        row.querySelector('[data-lng]').value = pos.coords.longitude.toFixed(7);
        btn.textContent = '✓';
        setTimeout(() => { btn.textContent = '📍 ' + (lang==='ar'?'موقعي':'My loc'); }, 1500);
      },
      () => { btn.textContent = '⛔'; setTimeout(() => { btn.textContent = '📍 ' + (lang==='ar'?'موقعي':'My loc'); }, 1500); },
      { enableHighAccuracy: true, timeout: 10000 }
    );
  });
});
</script>
</body>
</html>
