
<?php

require 'auth.php';
require 'config.php';

perm_require('page.users');

// Feature flags inside this page (defaults: admin only — editable from the permissions page)
$canAddUser    = can('page.add_user');
$canEditUser   = can('page.edit_user');
$canResetPass  = can('page.reset_user_password');
$canToggleUser = can('action.toggle_user');
$canActivity   = can('page.user_activity');
$isAdminRole   = (($_SESSION['role'] ?? '') === 'admin');   // permissions page itself is admin-only

// ── Generate CSRF token (used by toggle forms / future POST callers) ──
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$lang = $_GET['lang'] ?? 'ar';
$dir  = $lang === 'ar' ? 'rtl' : 'ltr';

$t = [
    'ar' => [
        'title'          => 'مركز إدارة المستخدمين',
        'subtitle'       => 'إدارة الموظفين والصلاحيات',
        'total_users'    => 'إجمالي المستخدمين',
        'active_users'   => 'النشطون',
        'inactive_users' => 'المعطّلون',
        'admins'         => 'المشرفون',
        'activity'       => 'النشاط',
        'search'         => 'ابحث باسم المستخدم أو الدور...',
        'users'          => 'المستخدمون',
        'dashboard'      => 'الرئيسية',
        'inventory'      => 'المخزون',
        'sold'           => 'المبيعات',
        'add_user'       => 'إضافة مستخدم',
        'active'         => 'نشط',
        'inactive'       => 'معطّل',
        'admin'          => 'مدير النظام',
        'manager'        => 'مدير',
        'sales'          => 'مبيعات',
        'created'        => 'تاريخ الإنشاء',
        'last_login'     => 'آخر دخول',
        'never'          => 'لم يسجل دخولاً',
        'edit'           => 'تعديل',
        'reset_password' => 'إعادة كلمة المرور',
        'activate'       => 'تفعيل',
        'disable'        => 'تعطيل',
        'protected'      => 'حساب محمي — لا يمكن تعطيله',
        'no_users'       => 'لا يوجد مستخدمون',
        'all_roles'      => 'كل الأدوار',
        'all_status'     => 'كل الحالات',
        'filter'         => 'تصفية',
        'reset'          => 'إعادة تعيين',
        'view_activity'  => 'عرض النشاط',
        'id_label'       => 'المعرّف',
        'confirm_disable'=> 'هل تريد تعطيل هذا المستخدم؟',
        'confirm_enable' => 'هل تريد تفعيل هذا المستخدم؟',
        'permissions'    => 'الصلاحيات',
        'perm_center'    => 'مركز الصلاحيات',
    ],
    'en' => [
        'title'          => 'User Command Center',
        'subtitle'       => 'Manage Staff & Permissions',
        'total_users'    => 'Total Users',
        'active_users'   => 'Active',
        'inactive_users' => 'Inactive',
        'admins'         => 'Admins',
        'activity'       => 'Activity',
        'search'         => 'Search username or role...',
        'users'          => 'Users',
        'dashboard'      => 'Dashboard',
        'inventory'      => 'Inventory',
        'sold'           => 'Sold',
        'add_user'       => 'Add User',
        'active'         => 'Active',
        'inactive'       => 'Inactive',
        'admin'          => 'Admin',
        'manager'        => 'Manager',
        'sales'          => 'Sales',
        'created'        => 'Created',
        'last_login'     => 'Last Login',
        'never'          => 'Never logged in',
        'edit'           => 'Edit',
        'reset_password' => 'Reset Password',
        'activate'       => 'Activate',
        'disable'        => 'Disable',
        'protected'      => 'Protected — cannot be disabled',
        'no_users'       => 'No Users Found',
        'all_roles'      => 'All Roles',
        'all_status'     => 'All Status',
        'filter'         => 'Filter',
        'reset'          => 'Reset',
        'view_activity'  => 'View Activity',
        'id_label'       => 'ID',
        'confirm_disable'=> 'Disable this user?',
        'confirm_enable' => 'Activate this user?',
        'permissions'    => 'Permissions',
        'perm_center'    => 'Permission Center',
    ],
];

/* ── Filters ── */
$search        = trim($_GET['search'] ?? '');
$filterRole    = trim($_GET['role']   ?? '');
$filterStatus  = trim($_GET['status'] ?? '');

$where  = [];
$params = [];

if ($search !== '') {
    $where[]  = "(username LIKE ? OR role LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filterRole !== '') {
    $where[]  = "role = ?";
    $params[] = $filterRole;
}
if ($filterStatus !== '') {
    $where[]  = "active = ?";
    $params[] = $filterStatus === 'active' ? 1 : 0;
}

$sql = "SELECT * FROM users" . ($where ? " WHERE " . implode(' AND ', $where) : '') . " ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ── Stats (always from full table, not filtered) ── */
$allUsers      = $pdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
$totalUsers    = count($allUsers);
$activeUsers   = count(array_filter($allUsers, fn($u) => $u['active']));
$inactiveUsers = $totalUsers - $activeUsers;
$totalAdmins   = count(array_filter($allUsers, fn($u) => $u['role'] === 'admin'));

/* ── Avatar colour by role ── */
$roleGradients = [
    'admin'   => 'linear-gradient(135deg,#9333ea,#6d28d9)',
    'manager' => 'linear-gradient(135deg,#22c55e,#16a34a)',
    'sales'   => 'linear-gradient(135deg,#2563eb,#1d4ed8)',
];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $t[$lang]['title'] ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ══════════════════════════════════════
   TOKENS
══════════════════════════════════════ */
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root {
    --bg-deep:    #020617;
    --bg-card:    rgba(15,23,42,.93);
    --border:     rgba(255,255,255,.07);
    --green:      #22c55e;
    --green-dim:  rgba(34,197,94,.12);
    --purple:     #9333ea;
    --purple-dim: rgba(147,51,234,.12);
    --blue:       #2563eb;
    --blue-dim:   rgba(37,99,235,.12);
    --red:        #ef4444;
    --red-dim:    rgba(239,68,68,.12);
    --amber:      #f59e0b;
    --text:       #f1f5f9;
    --muted:      #64748b;
    --muted-l:    #94a3b8;
    --r-card:     22px;
    --r-btn:      12px;
    --shadow:     0 4px 28px rgba(0,0,0,.45);
}
html[lang="ar"] body { font-family:'Cairo',sans-serif; }
html[lang="en"] body { font-family:'Inter',sans-serif; }

body {
    background: linear-gradient(150deg,#020617 0%,#0a0f1e 55%,#05101f 100%);
    color: var(--text);
    min-height: 100vh;
    padding-bottom: 80px;
}

.wrap { max-width:1600px; margin:auto; padding:16px 20px; }

/* ── Header ── */
.header {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--r-card);
    padding: 22px 26px;
    margin-bottom: 18px;
    backdrop-filter: blur(20px);
    box-shadow: var(--shadow);
}
.header-top { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; }
.page-title {
    font-size:28px; font-weight:900;
    background:linear-gradient(90deg,var(--green),#86efac);
    -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text;
}
.page-subtitle { font-size:13px; color:var(--muted-l); margin-top:4px; }
.nav-group { display:flex; gap:8px; flex-wrap:wrap; }
.nav-btn {
    text-decoration:none; padding:9px 16px; border-radius:var(--r-btn);
    font-weight:700; font-size:13px; color:white;
    display:flex; align-items:center; gap:6px;
    transition:transform .2s, box-shadow .2s;
}
.nav-btn:hover { transform:translateY(-2px); }
.nav-btn.dash   { background:var(--blue); }
.nav-btn.inv    { background:var(--green); color:#002b14; }
.nav-btn.sold   { background:var(--red); }
.nav-btn.add    { background:var(--purple); }
.nav-btn.perm   { background:linear-gradient(90deg,#7e22ce,#9333ea); box-shadow:0 4px 18px rgba(147,51,234,.35); }
.lang-row { display:flex; gap:8px; margin-top:14px; }
.lang-btn {
    text-decoration:none; padding:7px 14px; border-radius:10px;
    background:#111827; color:var(--muted-l);
    font-size:12px; font-weight:700;
    border:1px solid var(--border); transition:background .2s;
}
.lang-btn.active { background:var(--purple); color:white; border-color:transparent; }

/* ── Stats ── */
.stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:18px; }
.stat-card {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:var(--r-card); padding:20px 18px; text-align:center;
    box-shadow:var(--shadow); position:relative; overflow:hidden;
}
.stat-card::after { content:''; position:absolute; bottom:0; left:0; right:0; height:3px; }
.stat-card.c-total::after    { background:linear-gradient(90deg,#f1f5f9,#94a3b8); }
.stat-card.c-active::after   { background:linear-gradient(90deg,var(--green),#86efac); }
.stat-card.c-inactive::after { background:linear-gradient(90deg,var(--red),#fca5a5); }
.stat-card.c-admin::after    { background:linear-gradient(90deg,var(--purple),#c084fc); }
.stat-label { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); font-weight:700; }
.stat-number { font-size:46px; font-weight:900; margin-top:6px; line-height:1; }
.c-total    .stat-number { color:var(--text); }
.c-active   .stat-number { color:var(--green); }
.c-inactive .stat-number { color:var(--red); }
.c-admin    .stat-number { color:var(--purple); }

/* ── Filters ── */
.filters-card {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:var(--r-card); padding:18px 22px;
    margin-bottom:22px; box-shadow:var(--shadow);
}
.filter-grid { display:grid; grid-template-columns:2fr 1fr 1fr auto auto; gap:10px; align-items:end; }
.filter-group { display:flex; flex-direction:column; gap:5px; }
.filter-label { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; }
input[type="text"], select {
    width:100%; height:46px;
    border:1px solid rgba(255,255,255,.08); outline:none;
    background:#0d1526; color:var(--text);
    padding:0 14px; border-radius:12px;
    font-size:14px; font-family:inherit;
    transition:border-color .2s, box-shadow .2s;
    -webkit-appearance:none; appearance:none;
}
input:focus, select:focus { border-color:var(--green); box-shadow:0 0 0 3px rgba(34,197,94,.12); }
select option { background:#0d1526; }
.btn-filter {
    height:46px; padding:0 20px; border:none; border-radius:var(--r-btn);
    font-weight:800; font-size:14px; cursor:pointer;
    background:linear-gradient(90deg,var(--green),#16a34a);
    color:#002b14; font-family:inherit;
    transition:transform .2s, box-shadow .2s;
}
.btn-filter:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(34,197,94,.3); }
.btn-reset-filter {
    height:46px; padding:0 16px; border:1px solid var(--border); border-radius:var(--r-btn);
    font-weight:700; font-size:13px; cursor:pointer;
    background:#111827; color:var(--muted-l);
    text-decoration:none; display:flex; align-items:center; gap:6px;
    font-family:inherit; white-space:nowrap; transition:border-color .2s;
}
.btn-reset-filter:hover { border-color:var(--muted); }

/* ── Result count ── */
.result-count {
    font-size:13px; color:var(--muted-l); margin-bottom:16px;
    display:flex; align-items:center; gap:8px;
}
.result-count strong { color:var(--green); }

/* ── Users grid ── */
.users-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(360px,1fr)); gap:18px; }

.user-card {
    background:var(--bg-card);
    border:1px solid var(--border);
    border-radius:var(--r-card);
    overflow:hidden;
    position:relative;
    transition:transform .25s, border-color .25s, box-shadow .25s;
    box-shadow:var(--shadow);
    display:flex; flex-direction:column;
}
.user-card:hover { transform:translateY(-5px); box-shadow:0 16px 48px rgba(0,0,0,.5); }
.user-card.role-admin   { border-color:rgba(147,51,234,.25); }
.user-card.role-admin:hover   { border-color:rgba(147,51,234,.55); }
.user-card.role-manager { border-color:rgba(34,197,94,.18); }
.user-card.role-manager:hover { border-color:rgba(34,197,94,.5); }
.user-card.role-sales   { border-color:rgba(37,99,235,.18); }
.user-card.role-sales:hover   { border-color:rgba(37,99,235,.5); }

.card-stripe { height:4px; width:100%; }
.role-admin   .card-stripe { background:linear-gradient(90deg,var(--purple),#c084fc); }
.role-manager .card-stripe { background:linear-gradient(90deg,var(--green),#86efac); }
.role-sales   .card-stripe { background:linear-gradient(90deg,var(--blue),#93c5fd); }

.card-body { padding:20px 22px; flex:1; display:flex; flex-direction:column; gap:0; }

.user-top { display:flex; align-items:center; gap:15px; margin-bottom:18px; }
.avatar {
    width:60px; height:60px; border-radius:16px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    font-size:22px; font-weight:900; color:white;
    position:relative; overflow:hidden;
    box-shadow:0 4px 16px rgba(0,0,0,.4);
}
.avatar-inner { position:relative; z-index:1; }
.avatar::after {
    content:'';
    position:absolute; inset:0;
    background:linear-gradient(145deg,rgba(255,255,255,.18),transparent 60%);
}
.user-meta { flex:1; min-width:0; }
.username { font-size:20px; font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.badges { display:flex; gap:6px; flex-wrap:wrap; margin-top:6px; }
.badge {
    display:inline-flex; align-items:center; gap:4px;
    padding:4px 12px; border-radius:50px;
    font-size:11px; font-weight:700;
}
.badge-admin   { background:var(--purple-dim); color:#c084fc; border:1px solid rgba(147,51,234,.25); }
.badge-manager { background:var(--green-dim);  color:var(--green); border:1px solid rgba(34,197,94,.25); }
.badge-sales   { background:var(--blue-dim);   color:#60a5fa; border:1px solid rgba(37,99,235,.25); }
.badge-active   { background:rgba(34,197,94,.08); color:var(--green); border:1px solid rgba(34,197,94,.2); }
.badge-inactive { background:var(--red-dim);   color:#fca5a5; border:1px solid rgba(239,68,68,.2); }

.info-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:16px; }
.info-item {
    background:#0a1120; border-radius:12px; padding:10px 14px;
    border:1px solid rgba(255,255,255,.05);
}
.info-item-label { font-size:10px; color:var(--muted); font-weight:700; text-transform:uppercase; letter-spacing:.05em; margin-bottom:3px; }
.info-item-value { font-size:13px; font-weight:700; color:var(--muted-l); }
.info-item-value.highlight { color:var(--text); }

.protected-banner {
    display:flex; align-items:center; gap:8px;
    background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.22);
    border-radius:12px; padding:10px 14px;
    color:var(--amber); font-size:12px; font-weight:700;
    margin-bottom:14px;
}

/* ── Card actions ── */
.card-actions {
    display:grid; gap:8px;
    margin-top:auto; padding-top:16px;
    border-top:1px solid rgba(255,255,255,.05);
}
.actions-row1 { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.actions-row2 { display:grid; grid-template-columns:1fr 1fr; gap:8px; }

.btn {
    display:flex; align-items:center; justify-content:center; gap:6px;
    height:42px; border:none; border-radius:var(--r-btn);
    cursor:pointer; font-weight:700; font-size:13px;
    color:white; font-family:inherit;
    transition:transform .18s, box-shadow .18s, filter .18s;
    text-decoration:none; white-space:nowrap;
    width:100%;
}
.btn:hover { transform:translateY(-2px); filter:brightness(1.1); }
.btn-edit    { background:linear-gradient(90deg,#1d4ed8,var(--blue)); }
.btn-reset   { background:linear-gradient(90deg,#7e22ce,var(--purple)); }
.btn-enable  { background:linear-gradient(90deg,#15803d,var(--green)); color:#002b14; }
.btn-disable { background:linear-gradient(90deg,#b91c1c,var(--red)); }
.btn-activity { background:#1e2a3a; border:1px solid rgba(255,255,255,.08); color:var(--muted-l); }
.btn-activity:hover { color:white; }

/* Toggle forms — make them look identical to buttons */
.toggle-form { margin:0; padding:0; display:contents; }

/* Empty state */
.empty-state { grid-column:1/-1; text-align:center; padding:70px 20px; color:var(--muted); }
.empty-state .e-icon { font-size:56px; margin-bottom:14px; }
.empty-state p { font-size:17px; }

/* ── Responsive ── */
@media(max-width:1100px) { .stats-row { grid-template-columns:repeat(2,1fr); } }
@media(max-width:700px) {
    .stats-row { grid-template-columns:1fr 1fr; }
    .filter-grid { grid-template-columns:1fr 1fr; }
    .header-top { flex-direction:column; align-items:flex-start; }
    .users-grid { grid-template-columns:1fr; }
    .page-title { font-size:22px; }
    .stat-number { font-size:36px; }
    .info-grid { grid-template-columns:1fr; }
}
@media(max-width:450px) {
    .filter-grid { grid-template-columns:1fr; }
    .stats-row { grid-template-columns:1fr; }
}
</style>
</head>
<body>
<div class="wrap">

<!-- ── HEADER ─────────────────────────────── -->
<div class="header">
    <div class="header-top">
        <div>
            <div class="page-title">👥 <?= $t[$lang]['title'] ?></div>
            <div class="page-subtitle"><?= $t[$lang]['subtitle'] ?></div>
        </div>
        <div class="nav-group">
            <a href="dashboard.php?lang=<?= $lang ?>"       class="nav-btn dash">🏠 <?= $t[$lang]['dashboard'] ?></a>
            <a href="inventory.php?lang=<?= $lang ?>"        class="nav-btn inv">🚗 <?= $t[$lang]['inventory'] ?></a>
            <?php if (can('page.sold_inventory')): ?>
            <a href="sold_inventory.php?lang=<?= $lang ?>"   class="nav-btn sold">💰 <?= $t[$lang]['sold'] ?></a>
            <?php endif; ?>
            <?php if ($canAddUser): ?>
            <a href="add_user.php?lang=<?= $lang ?>"         class="nav-btn add">➕ <?= $t[$lang]['add_user'] ?></a>
            <?php endif; ?>
            <?php if ($isAdminRole): ?>
            <a href="permissions_admin.php?lang=<?= $lang ?>" class="nav-btn perm">🔐 <?= $t[$lang]['perm_center'] ?></a>
            <?php endif; ?>
        </div>
    </div>
    <div class="lang-row">
        <a href="?lang=ar&search=<?= urlencode($search) ?>&role=<?= urlencode($filterRole) ?>&status=<?= urlencode($filterStatus) ?>"
           class="lang-btn <?= $lang==='ar'?'active':'' ?>">🇪🇬 العربية</a>
        <a href="?lang=en&search=<?= urlencode($search) ?>&role=<?= urlencode($filterRole) ?>&status=<?= urlencode($filterStatus) ?>"
           class="lang-btn <?= $lang==='en'?'active':'' ?>">🇺🇸 English</a>
    </div>
</div>

<!-- ── STATS ──────────────────────────────── -->
<div class="stats-row">
    <div class="stat-card c-total">
        <div class="stat-label"><?= $t[$lang]['total_users'] ?></div>
        <div class="stat-number"><?= $totalUsers ?></div>
    </div>
    <div class="stat-card c-active">
        <div class="stat-label"><?= $t[$lang]['active_users'] ?></div>
        <div class="stat-number"><?= $activeUsers ?></div>
    </div>
    <div class="stat-card c-inactive">
        <div class="stat-label"><?= $t[$lang]['inactive_users'] ?></div>
        <div class="stat-number"><?= $inactiveUsers ?></div>
    </div>
    <div class="stat-card c-admin">
        <div class="stat-label"><?= $t[$lang]['admins'] ?></div>
        <div class="stat-number"><?= $totalAdmins ?></div>
    </div>
</div>

<!-- ── FILTERS ────────────────────────────── -->
<form method="GET" class="filters-card">
    <input type="hidden" name="lang" value="<?= $lang ?>">
    <div class="filter-grid">
        <div class="filter-group">
            <span class="filter-label">🔍 <?= $t[$lang]['search'] ?></span>
            <input type="text" name="search"
                   placeholder="<?= $t[$lang]['search'] ?>"
                   value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="filter-group">
            <span class="filter-label">🎭 <?= $lang==='ar'?'الدور':'Role' ?></span>
            <select name="role">
                <option value=""><?= $t[$lang]['all_roles'] ?></option>
                <option value="admin"   <?= $filterRole==='admin'  ?'selected':'' ?>><?= $t[$lang]['admin'] ?></option>
                <option value="manager" <?= $filterRole==='manager'?'selected':'' ?>><?= $t[$lang]['manager'] ?></option>
                <option value="sales"   <?= $filterRole==='sales'  ?'selected':'' ?>><?= $t[$lang]['sales'] ?></option>
            </select>
        </div>
        <div class="filter-group">
            <span class="filter-label">⚡ <?= $lang==='ar'?'الحالة':'Status' ?></span>
            <select name="status">
                <option value=""><?= $t[$lang]['all_status'] ?></option>
                <option value="active"   <?= $filterStatus==='active'  ?'selected':'' ?>><?= $t[$lang]['active'] ?></option>
                <option value="inactive" <?= $filterStatus==='inactive'?'selected':'' ?>><?= $t[$lang]['inactive'] ?></option>
            </select>
        </div>
        <button type="submit" class="btn-filter">🔍 <?= $t[$lang]['filter'] ?></button>
        <a href="?lang=<?= $lang ?>" class="btn-reset-filter">✕ <?= $t[$lang]['reset'] ?></a>
    </div>
</form>

<!-- ── RESULT COUNT ───────────────────────── -->
<div class="result-count">
    <span><?= $lang==='ar'?'عرض':'Showing' ?></span>
    <strong><?= count($users) ?></strong>
    <span><?= $lang==='ar'?'مستخدم':'users' ?></span>
    <?php if ($search || $filterRole || $filterStatus): ?>
    <span style="color:var(--muted);"><?= $lang==='ar'?'(مُصفّاة)':'(filtered)' ?></span>
    <?php endif; ?>
</div>

<!-- ── USERS GRID ─────────────────────────── -->
<div class="users-grid">

<?php if (empty($users)): ?>
    <div class="empty-state">
        <div class="e-icon">👥</div>
        <p><?= $t[$lang]['no_users'] ?></p>
    </div>
<?php endif; ?>

<?php foreach ($users as $user):
    $role      = $user['role'];
    $isProtected = ($user['id'] == 1); // Primary admin cannot be disabled
    $initial   = strtoupper(mb_substr($user['username'], 0, 1, 'UTF-8'));
    $grad      = $roleGradients[$role] ?? 'linear-gradient(135deg,#334155,#1e293b)';
    $badgeCls  = 'badge-' . $role;
    $statusCls = $user['active'] ? 'badge-active' : 'badge-inactive';
    $statusLabel = $user['active'] ? $t[$lang]['active'] : $t[$lang]['inactive'];
    $statusDot   = $user['active'] ? '🟢' : '🔴';

    $createdFmt   = date('d M Y', strtotime($user['created_at']));
    $lastLoginFmt = $user['last_login']
        ? date('d M Y · h:i A', strtotime($user['last_login']))
        : $t[$lang]['never'];
?>
<div class="user-card role-<?= $role ?>">

    <div class="card-stripe"></div>

    <div class="card-body">

        <!-- Top row: avatar + name + badges -->
        <div class="user-top">
            <div class="avatar" style="background:<?= $grad ?>">
                <span class="avatar-inner"><?= $initial ?></span>
            </div>
            <div class="user-meta">
                <div class="username"><?= htmlspecialchars($user['username']) ?></div>
                <div class="badges">
                    <span class="badge <?= $badgeCls ?>">
                        <?php $roleIcons = ['admin'=>'👑','manager'=>'🛡','sales'=>'💼']; echo $roleIcons[$role] ?? '•'; ?>&nbsp;<?= $t[$lang][$role] ?>
                    </span>
                    <span class="badge <?= $statusCls ?>"><?= $statusDot ?>&nbsp;<?= $statusLabel ?></span>
                </div>
            </div>
        </div>

        <!-- Info grid -->
        <div class="info-grid">
            <div class="info-item">
                <div class="info-item-label"><?= $t[$lang]['id_label'] ?></div>
                <div class="info-item-value highlight">#<?= $user['id'] ?></div>
            </div>
            <div class="info-item">
                <div class="info-item-label"><?= $t[$lang]['created'] ?></div>
                <div class="info-item-value"><?= $createdFmt ?></div>
            </div>
            <div class="info-item" style="grid-column:1/-1">
                <div class="info-item-label"><?= $t[$lang]['last_login'] ?></div>
                <div class="info-item-value <?= $user['last_login'] ? 'highlight' : '' ?>">
                    <?= $lastLoginFmt ?>
                </div>
            </div>
        </div>

        <!-- Protected notice -->
        <?php if ($isProtected): ?>
        <div class="protected-banner">
            🔒 <?= $t[$lang]['protected'] ?>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="card-actions">
            <div class="actions-row1">
                <?php if ($canEditUser): ?>
                <a href="edit_user.php?id=<?= $user['id'] ?>&lang=<?= $lang ?>" class="btn btn-edit">
                    ✏️ <?= $t[$lang]['edit'] ?>
                </a>
                <?php endif; ?>
                <?php if ($canResetPass): ?>
                <a href="reset_user_password.php?id=<?= $user['id'] ?>&lang=<?= $lang ?>" class="btn btn-reset">
                    🔑 <?= $t[$lang]['reset_password'] ?>
                </a>
                <?php endif; ?>
            </div>
            <div class="actions-row2">

                <?php if (!$isProtected && $canToggleUser): ?>
                    <?php
                    /*
                     * FIX: Toggle is now a POST <form> so toggle_user.php
                     * receives the CSRF token and does not reject the request.
                     * The button looks and behaves identically to the old link.
                     */
                    $toggleAction = $user['active'] ? 'disable' : 'enable';
                    $toggleLabel  = $user['active'] ? $t[$lang]['disable'] : $t[$lang]['activate'];
                    $toggleIcon   = $user['active'] ? '🔴' : '🟢';
                    $toggleClass  = $user['active'] ? 'btn-disable' : 'btn-enable';
                    $confirmMsg   = $user['active'] ? $t[$lang]['confirm_disable'] : $t[$lang]['confirm_enable'];
                    ?>
                    <form method="POST" action="toggle_user.php" class="toggle-form"
                          onsubmit="return confirm('<?= addslashes($confirmMsg) ?>')">
                        <input type="hidden" name="id"         value="<?= $user['id'] ?>">
                        <input type="hidden" name="action"     value="<?= $toggleAction ?>">
                        <input type="hidden" name="lang"       value="<?= $lang ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <button type="submit" class="btn <?= $toggleClass ?>">
                            <?= $toggleIcon ?> <?= $toggleLabel ?>
                        </button>
                    </form>
                <?php else: ?>
                    <span class="btn" style="background:#111827;color:var(--muted);cursor:default;border:1px solid var(--border);">
                        🔒 <?= $isProtected ? ($lang==='ar'?'محمي':'Protected') : ($lang==='ar'?'مقفول':'Locked') ?>
                    </span>
                <?php endif; ?>

                <?php if ($canActivity): ?>
                <a href="user_activity.php?id=<?= $user['id'] ?>&lang=<?= $lang ?>" class="btn btn-activity">
                    📊 <?= $t[$lang]['activity'] ?>
                </a>
                <?php endif; ?>
            </div>
            <?php if ($isAdminRole): ?>
            <a href="permissions_admin.php?lang=<?= $lang ?>&tab=user&user_id=<?= $user['id'] ?>"
               class="btn" style="background:linear-gradient(90deg,#7e22ce,var(--purple));">
                🔐 <?= $t[$lang]['permissions'] ?>
            </a>
            <?php endif; ?>
        </div>

    </div><!-- /.card-body -->
</div><!-- /.user-card -->
<?php endforeach; ?>

</div><!-- /.users-grid -->
</div><!-- /.wrap -->
</body>
</html>
