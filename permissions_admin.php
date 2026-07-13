<?php
/*
 * permissions_admin.php — the permission control center.
 *
 * ADMIN ONLY (hard-coded — this page can never be opened by anyone else).
 * Reached from the Users page (👥) via the 🔐 Permissions button.
 *
 * What it does:
 *  - Tab per role (admin / manager / sales): toggle every page & feature.
 *    Rows that differ from the built-in default are highlighted, and each
 *    role can be reset back to its default state with one click.
 *  - Per-user tab: pick any user and override single permissions for that
 *    person only (inherit from role / always allow / always deny).
 *  - Locked permissions (dashboard for everyone, Users page for admins)
 *    are shown but cannot be switched off — so an admin can never lock
 *    themselves out of this page.
 */

require 'auth.php';
require 'config.php';

// Hard admin gate — NOT permission-driven on purpose.
if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: dashboard.php?denied=1');
    exit;
}

perm_ensure_tables($pdo);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$lang = $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar', 'en'], true)) $lang = 'ar';
$dir = $lang === 'ar' ? 'rtl' : 'ltr';

$catalog  = perm_catalog();
$defaults = perm_defaults();
$roles    = ['admin', 'manager', 'sales'];

/* ── Handle saves ─────────────────────────────────────────────── */
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        die('Invalid CSRF token');
    }
    $action = $_POST['action'] ?? '';
    $backTab = 'admin';

    if ($action === 'save_role' && in_array($_POST['role'] ?? '', $roles, true)) {
        $role    = $_POST['role'];
        $backTab = $role;
        $checked = (array)($_POST['perm'] ?? []);
        $ins = $pdo->prepare("REPLACE INTO role_permissions (role, perm_key, allowed) VALUES (?, ?, ?)");
        foreach (perm_all_keys() as $key) {
            $ins->execute([$role, $key, isset($checked[$key]) ? 1 : 0]);
        }
        $flash = 'saved';
    }

    if ($action === 'reset_role' && in_array($_POST['role'] ?? '', $roles, true)) {
        $backTab = $_POST['role'];
        $pdo->prepare("DELETE FROM role_permissions WHERE role = ?")->execute([$backTab]);
        $flash = 'reset';
    }

    if ($action === 'save_user' && (int)($_POST['user_id'] ?? 0) > 0) {
        $uid     = (int)$_POST['user_id'];
        $backTab = 'user';
        $vals    = (array)($_POST['perm'] ?? []);
        $ins = $pdo->prepare("REPLACE INTO user_permissions (user_id, perm_key, allowed) VALUES (?, ?, ?)");
        $del = $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ? AND perm_key = ?");
        foreach (perm_all_keys() as $key) {
            $v = $vals[$key] ?? 'inherit';
            if ($v === 'allow')      $ins->execute([$uid, $key, 1]);
            elseif ($v === 'deny')   $ins->execute([$uid, $key, 0]);
            else                     $del->execute([$uid, $key]);
        }
        $flash = 'saved';
        header("Location: permissions_admin.php?lang=$lang&tab=user&user_id=$uid&flash=$flash");
        exit;
    }

    if ($action === 'reset_user' && (int)($_POST['user_id'] ?? 0) > 0) {
        $uid = (int)$_POST['user_id'];
        $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?")->execute([$uid]);
        header("Location: permissions_admin.php?lang=$lang&tab=user&user_id=$uid&flash=reset");
        exit;
    }

    header("Location: permissions_admin.php?lang=$lang&tab=$backTab&flash=$flash");
    exit;
}

$flash = $_GET['flash'] ?? '';
$tab   = $_GET['tab'] ?? 'manager';
if (!in_array($tab, ['admin', 'manager', 'sales', 'user'], true)) $tab = 'manager';

/* ── Data for rendering ───────────────────────────────────────── */
$roleEffective = [];
$roleOverrides = [];
foreach ($roles as $r) {
    $roleEffective[$r] = perm_effective_role($pdo, $r);
    $roleOverrides[$r] = perm_role_overrides($pdo, $r);
}

$allUsers = $pdo->query("SELECT id, username, role, active FROM users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$selUserId = (int)($_GET['user_id'] ?? 0);
$selUser   = null;
foreach ($allUsers as $u) {
    if ((int)$u['id'] === $selUserId) { $selUser = $u; break; }
}
$selUserOverrides = $selUser ? perm_user_overrides($pdo, $selUserId) : [];
$selUserRoleEff   = $selUser ? perm_effective_role($pdo, $selUser['role']) : [];

/* Count of overrides per role (badge on the tab) */
$ovCount = [];
foreach ($roles as $r) {
    $n = 0;
    foreach ($roleOverrides[$r] as $k => $v) {
        if (isset($defaults[$r][$k]) && $defaults[$r][$k] !== $v) $n++;
    }
    $ovCount[$r] = $n;
}

$t = [
    'ar' => [
        'title'         => 'مركز التحكم في الصلاحيات',
        'subtitle'      => 'تحكم كامل: من يرى كل صفحة وكل تفصيلة — حسب الدور أو لكل مستخدم',
        'users'         => 'المستخدمون',
        'dashboard'     => 'الرئيسية',
        'admin'         => '👑 الأدمن',
        'manager'       => '🛡 المدير',
        'sales'         => '💼 المبيعات',
        'per_user'      => '👤 لكل مستخدم',
        'save'          => '💾 حفظ التغييرات',
        'reset'         => '↩️ إرجاع للوضع الافتراضي',
        'reset_confirm' => 'إرجاع كل صلاحيات هذا الدور للوضع الافتراضي؟',
        'reset_user_confirm' => 'حذف كل التخصيصات الخاصة بهذا المستخدم؟ (سيرث صلاحيات دوره)',
        'default_badge' => 'افتراضي',
        'modified'      => 'مُعدّل',
        'locked'        => '🔒 مقفول — لا يمكن تغييره',
        'allow'         => 'مسموح',
        'deny'          => 'ممنوع',
        'inherit'       => 'يرث من الدور',
        'pick_user'     => 'اختر مستخدماً لتخصيص صلاحياته',
        'user_hint'     => 'التخصيص الفردي يتغلب على صلاحيات الدور. "يرث من الدور" = يتبع إعداد الدور أعلاه.',
        'role_hint'     => 'هذه هي الحالة الافتراضية الحالية للنظام. عدّل ما تريد ثم احفظ — ويمكنك الرجوع للوضع الافتراضي في أي وقت.',
        'saved'         => '✓ تم الحفظ بنجاح',
        'was_reset'     => '↩️ تم الإرجاع للوضع الافتراضي',
        'role_col'      => 'إعداد الدور',
        'user_col'      => 'تخصيص هذا المستخدم',
        'effective'     => 'النتيجة النهائية',
        'overrides'     => 'تعديل',
        'no_user'       => '— اختر مستخدم —',
        'user_role'     => 'الدور',
        'clear_user'    => '🗑 حذف كل تخصيصات المستخدم',
        'default_on'    => 'الافتراضي: مسموح',
        'default_off'   => 'الافتراضي: ممنوع',
        'inactive'      => 'معطّل',
    ],
    'en' => [
        'title'         => 'Permission Control Center',
        'subtitle'      => 'Full control: who sees every page and every detail — per role or per user',
        'users'         => 'Users',
        'dashboard'     => 'Dashboard',
        'admin'         => '👑 Admin',
        'manager'       => '🛡 Manager',
        'sales'         => '💼 Sales',
        'per_user'      => '👤 Per User',
        'save'          => '💾 Save Changes',
        'reset'         => '↩️ Reset to Defaults',
        'reset_confirm' => 'Reset ALL permissions of this role to the default state?',
        'reset_user_confirm' => 'Delete all custom overrides for this user? (they will inherit their role)',
        'default_badge' => 'Default',
        'modified'      => 'Modified',
        'locked'        => '🔒 Locked — cannot be changed',
        'allow'         => 'Allowed',
        'deny'          => 'Denied',
        'inherit'       => 'Inherit from role',
        'pick_user'     => 'Pick a user to customize their permissions',
        'user_hint'     => 'Per-user overrides beat role settings. "Inherit from role" = follows the role setting above.',
        'role_hint'     => 'This is the system\'s current default state. Change anything and save — you can reset to defaults at any time.',
        'saved'         => '✓ Saved successfully',
        'was_reset'     => '↩️ Reset to defaults',
        'role_col'      => 'Role setting',
        'user_col'      => 'This user\'s override',
        'effective'     => 'Final result',
        'overrides'     => 'override(s)',
        'no_user'       => '— pick a user —',
        'user_role'     => 'Role',
        'clear_user'    => '🗑 Clear all user overrides',
        'default_on'    => 'Default: allowed',
        'default_off'   => 'Default: denied',
        'inactive'      => 'inactive',
    ],
];
$L = $t[$lang];

$roleLabels = ['admin' => $L['admin'], 'manager' => $L['manager'], 'sales' => $L['sales']];
$defIdx     = ['admin' => 0, 'manager' => 1, 'sales' => 2];

function perm_is_locked(array $p, string $role): bool
{
    $lock = $p['locked'] ?? '';
    return $lock === 'all' || ($lock === 'admin' && $role === 'admin');
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $L['title'] ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root {
    --bg-card:  rgba(15,23,42,.93);
    --border:   rgba(255,255,255,.07);
    --green:    #22c55e;
    --purple:   #9333ea;
    --blue:     #2563eb;
    --red:      #ef4444;
    --amber:    #f59e0b;
    --text:     #f1f5f9;
    --muted:    #64748b;
    --muted-l:  #94a3b8;
}
html[lang="ar"] body { font-family:'Cairo',sans-serif; }
html[lang="en"] body { font-family:'Inter',sans-serif; }
body {
    background: linear-gradient(150deg,#020617 0%,#0a0f1e 55%,#05101f 100%);
    color: var(--text); min-height:100vh; padding-bottom:60px;
}
.wrap { max-width:1100px; margin:auto; padding:16px 20px; }

.header {
    background:var(--bg-card); border:1px solid var(--border); border-radius:22px;
    padding:22px 26px; margin-bottom:18px; box-shadow:0 4px 28px rgba(0,0,0,.45);
}
.header-top { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; }
.page-title {
    font-size:26px; font-weight:900;
    background:linear-gradient(90deg,#c084fc,var(--purple));
    -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text;
}
.page-subtitle { font-size:13px; color:var(--muted-l); margin-top:4px; max-width:560px; }
.nav-group { display:flex; gap:8px; flex-wrap:wrap; }
.nav-btn {
    text-decoration:none; padding:9px 16px; border-radius:12px;
    font-weight:700; font-size:13px; color:#fff;
    display:flex; align-items:center; gap:6px; transition:transform .2s;
}
.nav-btn:hover { transform:translateY(-2px); }
.nav-btn.dash  { background:var(--blue); }
.nav-btn.users { background:var(--green); color:#002b14; }
.lang-row { display:flex; gap:8px; margin-top:14px; }
.lang-btn {
    text-decoration:none; padding:7px 14px; border-radius:10px;
    background:#111827; color:var(--muted-l); font-size:12px; font-weight:700;
    border:1px solid var(--border);
}
.lang-btn.active { background:var(--purple); color:#fff; border-color:transparent; }

.flash {
    border-radius:14px; padding:13px 18px; font-weight:800; font-size:14px; margin-bottom:16px;
    background:rgba(34,197,94,.1); border:1px solid rgba(34,197,94,.3); color:#4ade80;
}

/* Tabs */
.tabs { display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap; }
.tab {
    text-decoration:none; padding:12px 20px; border-radius:14px; font-weight:800; font-size:14px;
    background:var(--bg-card); border:1px solid var(--border); color:var(--muted-l);
    display:flex; align-items:center; gap:8px; transition:border-color .2s, color .2s;
}
.tab:hover { color:var(--text); border-color:rgba(147,51,234,.4); }
.tab.active { background:linear-gradient(90deg,rgba(147,51,234,.25),rgba(37,99,235,.18)); color:#fff; border-color:rgba(147,51,234,.55); }
.tab .ov-badge {
    background:var(--amber); color:#1c1400; font-size:11px; font-weight:900;
    min-width:20px; height:20px; border-radius:10px; padding:0 6px;
    display:inline-flex; align-items:center; justify-content:center;
}

.hint {
    background:rgba(37,99,235,.08); border:1px solid rgba(37,99,235,.25);
    border-radius:14px; padding:12px 16px; font-size:13px; color:var(--muted-l); margin-bottom:16px; line-height:1.7;
}

/* Groups */
.group {
    background:var(--bg-card); border:1px solid var(--border); border-radius:20px;
    margin-bottom:16px; overflow:hidden; box-shadow:0 4px 28px rgba(0,0,0,.35);
}
.group-head {
    display:flex; align-items:center; gap:10px; padding:15px 20px;
    background:rgba(255,255,255,.03); border-bottom:1px solid var(--border);
    font-weight:900; font-size:15px;
}
.group-head .g-icon { font-size:19px; }
.perm-row {
    display:flex; align-items:center; justify-content:space-between; gap:14px;
    padding:13px 20px; border-bottom:1px solid rgba(255,255,255,.04);
}
.perm-row:last-child { border-bottom:none; }
.perm-row.is-modified { background:rgba(245,158,11,.05); border-inline-start:3px solid var(--amber); }
.perm-info { flex:1; min-width:0; }
.perm-name { font-weight:800; font-size:14px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.perm-desc { font-size:12px; color:var(--muted); margin-top:2px; line-height:1.6; }
.badge {
    font-size:10px; font-weight:800; padding:2px 9px; border-radius:20px; white-space:nowrap;
}
.badge-default-on  { background:rgba(34,197,94,.1);  color:#4ade80; border:1px solid rgba(34,197,94,.25); }
.badge-default-off { background:rgba(100,116,139,.15); color:var(--muted-l); border:1px solid rgba(100,116,139,.3); }
.badge-modified    { background:rgba(245,158,11,.14); color:#fbbf24; border:1px solid rgba(245,158,11,.35); }
.badge-locked      { background:rgba(239,68,68,.1);  color:#fca5a5; border:1px solid rgba(239,68,68,.25); }

/* Toggle switch */
.switch { position:relative; width:52px; height:28px; flex-shrink:0; }
.switch input { opacity:0; width:0; height:0; }
.slider {
    position:absolute; inset:0; border-radius:28px; cursor:pointer;
    background:#1e293b; border:1px solid rgba(255,255,255,.1); transition:background .2s;
}
.slider::before {
    content:''; position:absolute; width:20px; height:20px; border-radius:50%;
    background:#64748b; top:3px; inset-inline-start:4px; transition:transform .2s, background .2s;
}
.switch input:checked + .slider { background:rgba(34,197,94,.35); border-color:rgba(34,197,94,.5); }
.switch input:checked + .slider::before { transform:translateX(24px); background:var(--green); }
[dir="rtl"] .switch input:checked + .slider::before { transform:translateX(-24px); }
.switch input:disabled + .slider { opacity:.55; cursor:not-allowed; }

/* Tri-state select (per-user tab) */
.tri-select {
    height:40px; border-radius:12px; padding:0 12px; font-family:inherit; font-weight:700; font-size:13px;
    background:#0d1526; color:var(--text); border:1px solid rgba(255,255,255,.1); outline:none;
    -webkit-appearance:none; appearance:none; cursor:pointer; min-width:150px;
}
.tri-select.v-allow  { border-color:rgba(34,197,94,.55); color:#4ade80; }
.tri-select.v-deny   { border-color:rgba(239,68,68,.55); color:#fca5a5; }
.eff-pill { font-size:11px; font-weight:800; padding:3px 10px; border-radius:20px; white-space:nowrap; }
.eff-on  { background:rgba(34,197,94,.12); color:#4ade80; border:1px solid rgba(34,197,94,.3); }
.eff-off { background:rgba(239,68,68,.1);  color:#fca5a5; border:1px solid rgba(239,68,68,.28); }
.perm-controls { display:flex; align-items:center; gap:12px; flex-shrink:0; }

/* Action bar */
.action-bar {
    position:sticky; bottom:14px; display:flex; gap:10px; justify-content:flex-end;
    background:rgba(15,23,42,.97); border:1px solid rgba(147,51,234,.35); border-radius:18px;
    padding:14px 18px; box-shadow:0 12px 40px rgba(0,0,0,.55); backdrop-filter:blur(14px);
    z-index:50; flex-wrap:wrap;
}
.btn-save, .btn-reset {
    border:none; cursor:pointer; font-family:inherit; font-weight:800; font-size:14px;
    padding:13px 26px; border-radius:13px; transition:transform .15s;
}
.btn-save  { background:linear-gradient(90deg,var(--purple),var(--blue)); color:#fff; }
.btn-reset { background:rgba(239,68,68,.12); color:#f87171; border:1px solid rgba(239,68,68,.3); }
.btn-save:hover, .btn-reset:hover { transform:translateY(-2px); }

/* user picker */
.user-picker {
    background:var(--bg-card); border:1px solid var(--border); border-radius:18px;
    padding:16px 20px; margin-bottom:16px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;
}
.user-picker label { font-weight:800; font-size:14px; }
.user-picker select {
    height:46px; min-width:260px; border-radius:12px; padding:0 14px;
    background:#0d1526; color:var(--text); border:1px solid rgba(255,255,255,.1);
    font-family:inherit; font-size:14px; font-weight:700; outline:none;
}
.user-chip {
    display:inline-flex; align-items:center; gap:8px; font-weight:800; font-size:13px;
    background:rgba(147,51,234,.12); border:1px solid rgba(147,51,234,.35); color:#c084fc;
    padding:8px 14px; border-radius:12px;
}

@media(max-width:700px) {
    .perm-row { flex-direction:column; align-items:flex-start; gap:10px; }
    .perm-controls { width:100%; justify-content:space-between; }
    .header-top { flex-direction:column; align-items:flex-start; }
}
</style>
</head>
<body>
<div class="wrap">

<div class="header">
    <div class="header-top">
        <div>
            <div class="page-title">🔐 <?= $L['title'] ?></div>
            <div class="page-subtitle"><?= $L['subtitle'] ?></div>
        </div>
        <div class="nav-group">
            <a href="dashboard.php?lang=<?= $lang ?>" class="nav-btn dash">🏠 <?= $L['dashboard'] ?></a>
            <a href="users.php?lang=<?= $lang ?>" class="nav-btn users">👥 <?= $L['users'] ?></a>
        </div>
    </div>
    <div class="lang-row">
        <a href="?lang=ar&tab=<?= $tab ?><?= $selUserId ? '&user_id='.$selUserId : '' ?>" class="lang-btn <?= $lang==='ar'?'active':'' ?>">🇪🇬 العربية</a>
        <a href="?lang=en&tab=<?= $tab ?><?= $selUserId ? '&user_id='.$selUserId : '' ?>" class="lang-btn <?= $lang==='en'?'active':'' ?>">🇺🇸 English</a>
    </div>
</div>

<?php if ($flash === 'saved'): ?>
    <div class="flash"><?= $L['saved'] ?></div>
<?php elseif ($flash === 'reset'): ?>
    <div class="flash"><?= $L['was_reset'] ?></div>
<?php endif; ?>

<!-- Tabs -->
<div class="tabs">
    <?php foreach ($roles as $r): ?>
        <a href="?lang=<?= $lang ?>&tab=<?= $r ?>" class="tab <?= $tab === $r ? 'active' : '' ?>">
            <?= $roleLabels[$r] ?>
            <?php if ($ovCount[$r] > 0): ?><span class="ov-badge"><?= $ovCount[$r] ?></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
    <a href="?lang=<?= $lang ?>&tab=user<?= $selUserId ? '&user_id='.$selUserId : '' ?>" class="tab <?= $tab === 'user' ? 'active' : '' ?>">
        <?= $L['per_user'] ?>
    </a>
</div>

<?php if ($tab !== 'user'): $role = $tab; ?>
<!-- ═══════════ ROLE TAB ═══════════ -->
<div class="hint">💡 <?= $L['role_hint'] ?></div>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="action" value="save_role">
    <input type="hidden" name="role" value="<?= $role ?>">

    <?php foreach ($catalog as $group): ?>
    <div class="group">
        <div class="group-head"><span class="g-icon"><?= $group['icon'] ?></span> <?= $group[$lang] ?></div>
        <?php foreach ($group['perms'] as $key => $p):
            $def     = (bool)$p['d'][$defIdx[$role]];
            $eff     = !empty($roleEffective[$role][$key]);
            $locked  = perm_is_locked($p, $role);
            $modified = !$locked && $eff !== $def;
        ?>
        <div class="perm-row <?= $modified ? 'is-modified' : '' ?>">
            <div class="perm-info">
                <div class="perm-name">
                    <?= htmlspecialchars($lang === 'ar' ? $p['ar'] : $p['en']) ?>
                    <span class="badge <?= $def ? 'badge-default-on' : 'badge-default-off' ?>">
                        <?= $def ? $L['default_on'] : $L['default_off'] ?>
                    </span>
                    <?php if ($modified): ?><span class="badge badge-modified">⚡ <?= $L['modified'] ?></span><?php endif; ?>
                    <?php if ($locked): ?><span class="badge badge-locked"><?= $L['locked'] ?></span><?php endif; ?>
                </div>
                <div class="perm-desc"><?= htmlspecialchars($lang === 'ar' ? $p['dar'] : $p['den']) ?></div>
            </div>
            <div class="perm-controls">
                <label class="switch">
                    <?php if ($locked): ?>
                        <input type="checkbox" checked disabled>
                        <input type="hidden" name="perm[<?= $key ?>]" value="1">
                    <?php else: ?>
                        <input type="checkbox" name="perm[<?= $key ?>]" value="1" <?= $eff ? 'checked' : '' ?>>
                    <?php endif; ?>
                    <span class="slider"></span>
                </label>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <div class="action-bar">
        <button type="submit" class="btn-save"><?= $L['save'] ?></button>
        <button type="submit" class="btn-reset"
                formaction="permissions_admin.php?lang=<?= $lang ?>"
                onclick="return confirm(<?= json_encode($L['reset_confirm'], JSON_UNESCAPED_UNICODE) ?>) && setAction(this.form,'reset_role');">
            <?= $L['reset'] ?>
        </button>
    </div>
</form>

<?php else: ?>
<!-- ═══════════ PER-USER TAB ═══════════ -->
<div class="hint">💡 <?= $L['user_hint'] ?></div>

<form method="GET" class="user-picker">
    <input type="hidden" name="lang" value="<?= $lang ?>">
    <input type="hidden" name="tab" value="user">
    <label>👤 <?= $L['pick_user'] ?></label>
    <select name="user_id" onchange="this.form.submit()">
        <option value="0"><?= $L['no_user'] ?></option>
        <?php foreach ($allUsers as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $selUserId ? 'selected' : '' ?>>
                <?= htmlspecialchars($u['username']) ?> — <?= htmlspecialchars($u['role']) ?><?= $u['active'] ? '' : ' ('.$L['inactive'].')' ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php if ($selUser): ?>
        <span class="user-chip">
            <?= htmlspecialchars($selUser['username']) ?> · <?= $L['user_role'] ?>: <?= htmlspecialchars($selUser['role']) ?>
        </span>
    <?php endif; ?>
</form>

<?php if ($selUser): ?>
<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="action" value="save_user">
    <input type="hidden" name="user_id" value="<?= $selUserId ?>">

    <?php foreach ($catalog as $group): ?>
    <div class="group">
        <div class="group-head"><span class="g-icon"><?= $group['icon'] ?></span> <?= $group[$lang] ?></div>
        <?php foreach ($group['perms'] as $key => $p):
            $locked  = perm_is_locked($p, $selUser['role']);
            $roleVal = !empty($selUserRoleEff[$key]);
            $ov      = array_key_exists($key, $selUserOverrides) ? ($selUserOverrides[$key] ? 'allow' : 'deny') : 'inherit';
            $final   = $locked ? true : ($ov === 'inherit' ? $roleVal : $ov === 'allow');
        ?>
        <div class="perm-row <?= $ov !== 'inherit' ? 'is-modified' : '' ?>">
            <div class="perm-info">
                <div class="perm-name">
                    <?= htmlspecialchars($lang === 'ar' ? $p['ar'] : $p['en']) ?>
                    <span class="badge <?= $roleVal ? 'badge-default-on' : 'badge-default-off' ?>">
                        <?= $L['role_col'] ?>: <?= $roleVal ? $L['allow'] : $L['deny'] ?>
                    </span>
                    <?php if ($ov !== 'inherit'): ?><span class="badge badge-modified">⚡ <?= $L['modified'] ?></span><?php endif; ?>
                    <?php if ($locked): ?><span class="badge badge-locked"><?= $L['locked'] ?></span><?php endif; ?>
                </div>
                <div class="perm-desc"><?= htmlspecialchars($lang === 'ar' ? $p['dar'] : $p['den']) ?></div>
            </div>
            <div class="perm-controls">
                <span class="eff-pill <?= $final ? 'eff-on' : 'eff-off' ?>">
                    <?= $L['effective'] ?>: <?= $final ? '✓ '.$L['allow'] : '✕ '.$L['deny'] ?>
                </span>
                <?php if ($locked): ?>
                    <select class="tri-select" disabled><option><?= $L['allow'] ?> 🔒</option></select>
                <?php else: ?>
                    <select class="tri-select <?= $ov === 'allow' ? 'v-allow' : ($ov === 'deny' ? 'v-deny' : '') ?>"
                            name="perm[<?= $key ?>]" onchange="triColor(this)">
                        <option value="inherit" <?= $ov === 'inherit' ? 'selected' : '' ?>><?= $L['inherit'] ?></option>
                        <option value="allow"   <?= $ov === 'allow'   ? 'selected' : '' ?>>✓ <?= $L['allow'] ?></option>
                        <option value="deny"    <?= $ov === 'deny'    ? 'selected' : '' ?>>✕ <?= $L['deny'] ?></option>
                    </select>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <div class="action-bar">
        <button type="submit" class="btn-save"><?= $L['save'] ?></button>
        <button type="submit" class="btn-reset"
                onclick="return confirm(<?= json_encode($L['reset_user_confirm'], JSON_UNESCAPED_UNICODE) ?>) && setAction(this.form,'reset_user');">
            <?= $L['clear_user'] ?>
        </button>
    </div>
</form>
<?php endif; ?>

<?php endif; ?>

</div><!-- /.wrap -->
<script>
function setAction(form, val) {
    form.querySelector('input[name="action"]').value = val;
    return true;
}
function triColor(sel) {
    sel.classList.remove('v-allow', 'v-deny');
    if (sel.value === 'allow') sel.classList.add('v-allow');
    if (sel.value === 'deny')  sel.classList.add('v-deny');
}
</script>
</body>
</html>
