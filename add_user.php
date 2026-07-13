<?php
/*
 * add_user.php — Admin-only: create a new user account.
 *
 * Security applied:
 *  1. Admin-only access gate.
 *  2. CSRF token on every POST.
 *  3. password_hash(PASSWORD_BCRYPT) — passwords are NEVER stored plain-text.
 *  4. Username uniqueness checked before insert.
 *  5. Password strength enforced server-side (min 8 chars).
 *  6. All output escaped with htmlspecialchars().
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'auth.php';
require 'config.php';

// ── Permission gate (default: admin only) ──
perm_require('page.add_user');

$lang  = $_GET['lang'] ?? 'ar';
$isRTL = ($lang === 'ar');

// ── Generate CSRF token if none exists ──
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$t = [
    'ar' => [
        'page_title'       => 'إضافة مستخدم جديد',
        'page_subtitle'    => 'إنشاء حساب مستخدم في النظام',
        'back_users'       => 'العودة للمستخدمين',
        'dashboard'        => 'الرئيسية',
        'section_identity' => 'بيانات الهوية',
        'section_security' => 'الأمان والصلاحية',
        'username'         => 'اسم المستخدم',
        'username_hint'    => 'أحرف إنجليزية وأرقام فقط، لا مسافات',
        'role'             => 'الصلاحية',
        'admin'            => 'مدير النظام',
        'manager'          => 'مدير',
        'sales'            => 'مبيعات',
        'password'         => 'كلمة المرور',
        'confirm'          => 'تأكيد كلمة المرور',
        'pw_hint'          => '٨ أحرف على الأقل',
        'show'             => 'إظهار',
        'hide'             => 'إخفاء',
        'save'             => 'إنشاء المستخدم',
        'success'          => 'تم إنشاء المستخدم بنجاح! 🎉',
        'exists'           => 'اسم المستخدم مستخدم بالفعل',
        'mismatch'         => 'كلمتا المرور غير متطابقتين',
        'short_pw'         => 'كلمة المرور قصيرة جداً — ٨ أحرف على الأقل',
        'fill_required'    => 'يرجى استكمال جميع الحقول المطلوبة',
        'csrf_error'       => 'طلب غير صالح — أعد المحاولة',
        'strength_weak'    => 'ضعيف',
        'strength_fair'    => 'مقبول',
        'strength_good'    => 'جيد',
        'strength_strong'  => 'قوي',
        'role_desc_admin'  => 'صلاحية كاملة على النظام',
        'role_desc_manager'=> 'إدارة المخزون والتقارير',
        'role_desc_sales'  => 'عرض المخزون وتسجيل الصفقات',
        'add_another'      => 'إضافة مستخدم آخر',
        'view_users'       => 'عرض المستخدمين',
        'created_by'       => 'أُنشئ بواسطة',
        'preview_title'    => 'معاينة الحساب',
        'preview_role'     => 'الصلاحية',
        'preview_status'   => 'الحالة',
        'preview_active'   => 'نشط',
        'preview_user'     => 'المستخدم',
    ],
    'en' => [
        'page_title'       => 'Add New User',
        'page_subtitle'    => 'Create a system user account',
        'back_users'       => 'Back to Users',
        'dashboard'        => 'Dashboard',
        'section_identity' => 'Identity',
        'section_security' => 'Security & Role',
        'username'         => 'Username',
        'username_hint'    => 'Letters and numbers only, no spaces',
        'role'             => 'Role',
        'admin'            => 'Admin',
        'manager'          => 'Manager',
        'sales'            => 'Sales',
        'password'         => 'Password',
        'confirm'          => 'Confirm Password',
        'pw_hint'          => 'Minimum 8 characters',
        'show'             => 'Show',
        'hide'             => 'Hide',
        'save'             => 'Create User',
        'success'          => 'User created successfully! 🎉',
        'exists'           => 'Username already taken',
        'mismatch'         => 'Passwords do not match',
        'short_pw'         => 'Password too short — minimum 8 characters',
        'fill_required'    => 'Please complete all required fields',
        'csrf_error'       => 'Invalid request — please try again',
        'strength_weak'    => 'Weak',
        'strength_fair'    => 'Fair',
        'strength_good'    => 'Good',
        'strength_strong'  => 'Strong',
        'role_desc_admin'  => 'Full system access',
        'role_desc_manager'=> 'Inventory & reports management',
        'role_desc_sales'  => 'View inventory & log deals',
        'add_another'      => 'Add Another User',
        'view_users'       => 'View Users',
        'created_by'       => 'Created by',
        'preview_title'    => 'Account Preview',
        'preview_role'     => 'Role',
        'preview_status'   => 'Status',
        'preview_active'   => 'Active',
        'preview_user'     => 'User',
    ],
];

$success = '';
$error   = '';
$createdUsername = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── CSRF ──
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = $t[$lang]['csrf_error'];
    } else {

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password']        ?? '';
        $confirm  = $_POST['confirm']         ?? '';
        $role     = trim($_POST['role']       ?? '');

        $allowedRoles = ['admin', 'manager', 'sales'];

        if (empty($username) || empty($password) || empty($confirm) || empty($role)) {
            $error = $t[$lang]['fill_required'];

        } elseif (!in_array($role, $allowedRoles, true)) {
            $error = $t[$lang]['fill_required'];

        } elseif (strlen($password) < 8) {
            $error = $t[$lang]['short_pw'];

        } elseif ($password !== $confirm) {
            $error = $t[$lang]['mismatch'];

        } else {
            // Check uniqueness
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);

            if ($stmt->fetch()) {
                $error = $t[$lang]['exists'];
            } else {
                // Hash the password — NEVER store plain text
                $hashed = password_hash($password, PASSWORD_BCRYPT);

                $stmt = $pdo->prepare("
                    INSERT INTO users (username, password, role, active)
                    VALUES (?, ?, ?, 1)
                ");
                $stmt->execute([$username, $hashed, $role]);

                $success         = $t[$lang]['success'];
                $createdUsername = $username;

                // Rotate CSRF token after successful action
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
        }
    }
}

$roleIcons = ['admin' => '👑', 'manager' => '📊', 'sales' => '🤝'];
$roleColors = ['admin' => 'var(--purple)', 'manager' => 'var(--blue)', 'sales' => 'var(--green)'];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $isRTL ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $t[$lang]['page_title'] ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">

<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

:root {
    --green:       #22c55e;
    --green-dim:   rgba(34,197,94,0.10);
    --green-glow:  rgba(34,197,94,0.25);
    --purple:      #a855f7;
    --purple-dim:  rgba(168,85,247,0.10);
    --blue:        #3b82f6;
    --blue-dim:    rgba(59,130,246,0.10);
    --amber:       #f59e0b;
    --amber-dim:   rgba(245,158,11,0.10);
    --red:         #ef4444;
    --red-dim:     rgba(239,68,68,0.10);
    --bg:          #020617;
    --bg-card:     rgba(9,17,38,0.90);
    --border:      rgba(255,255,255,0.07);
    --text:        #f1f5f9;
    --text-muted:  #64748b;
    --text-soft:   #94a3b8;
    --radius:      16px;
    --radius-lg:   22px;
    --radius-xl:   28px;
    --ease:        cubic-bezier(0.4,0,0.2,1);
    --shadow:      0 24px 60px rgba(0,0,0,0.5);
    --ff-en:       'Inter', sans-serif;
    --ff-ar:       'Tajawal', sans-serif;
}

html[lang="ar"] * { font-family: var(--ff-ar); }
html[lang="en"] * { font-family: var(--ff-en); }

body {
    background: var(--bg);
    background-image:
        radial-gradient(ellipse 70% 50% at 15% 0%, rgba(168,85,247,0.07) 0%, transparent 60%),
        radial-gradient(ellipse 60% 40% at 85% 100%, rgba(34,197,94,0.06) 0%, transparent 55%),
        linear-gradient(rgba(168,85,247,0.02) 1px, transparent 1px),
        linear-gradient(90deg, rgba(168,85,247,0.02) 1px, transparent 1px);
    background-size: 100% 100%, 100% 100%, 55px 55px, 55px 55px;
    color: var(--text);
    min-height: 100vh;
    padding-bottom: 80px;
}

/* ── CONTAINER ───────────────────────────────────────── */
.container {
    max-width: 1300px;
    margin: 0 auto;
    padding: 24px 16px;
}

/* ── TOPBAR ──────────────────────────────────────────── */
.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 14px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
    padding: 18px 24px;
    margin-bottom: 22px;
    backdrop-filter: blur(20px);
    box-shadow: var(--shadow);
}

.topbar-left { display: flex; align-items: center; gap: 16px; }

.logo-mark {
    width: 50px; height: 50px;
    background: linear-gradient(135deg, var(--purple), #7c3aed);
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
    box-shadow: 0 0 24px rgba(168,85,247,0.3);
}

.page-title {
    font-size: 22px;
    font-weight: 900;
    background: linear-gradient(90deg, var(--purple), var(--green));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.page-subtitle { font-size: 13px; color: var(--text-muted); margin-top: 3px; }

.topbar-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

.nav-btn {
    text-decoration: none;
    padding: 9px 16px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 13px;
    transition: all 0.2s var(--ease);
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}

.nav-btn:hover { transform: translateY(-1px); }

.btn-dashboard { background: var(--blue-dim); border: 1px solid rgba(59,130,246,0.25); color: #60a5fa; }
.btn-users     { background: var(--purple-dim); border: 1px solid rgba(168,85,247,0.25); color: var(--purple); }

.btn-dashboard:hover { background: rgba(59,130,246,0.18); }
.btn-users:hover     { background: rgba(168,85,247,0.18); }

.lang-switch { display: flex; gap: 6px; }

.lang-btn {
    text-decoration: none;
    padding: 8px 14px;
    border-radius: 10px;
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--border);
    color: var(--text-muted);
    font-weight: 700;
    font-size: 12px;
    transition: all 0.2s;
    display: flex; align-items: center; gap: 5px;
}

.lang-btn:hover { background: rgba(255,255,255,0.08); color: var(--text); }
.lang-active { background: var(--purple-dim) !important; border-color: rgba(168,85,247,0.4) !important; color: var(--purple) !important; }

/* ── BREADCRUMB ──────────────────────────────────────── */
.breadcrumb {
    display: flex; align-items: center; gap: 6px;
    font-size: 13px; color: var(--text-muted);
    margin-bottom: 22px; padding: 0 4px;
}

.breadcrumb a { color: var(--text-muted); text-decoration: none; transition: color 0.2s; }
.breadcrumb a:hover { color: var(--text); }
.breadcrumb-sep { opacity: 0.4; }
.breadcrumb-current { color: var(--purple); font-weight: 600; }

/* ── ALERTS ──────────────────────────────────────────── */
.alert {
    padding: 15px 18px;
    border-radius: var(--radius);
    margin-bottom: 20px;
    font-weight: 600;
    font-size: 14px;
    display: flex; align-items: flex-start; gap: 10px;
    animation: alertIn 0.35s var(--ease);
}

@keyframes alertIn {
    from { opacity: 0; transform: translateY(-10px); }
    to   { opacity: 1; transform: translateY(0); }
}

.alert-error   { background: var(--red-dim);   border: 1px solid rgba(239,68,68,0.25);  color: #fca5a5; }
.alert-success { background: var(--green-dim);  border: 1px solid rgba(34,197,94,0.3);   color: #86efac; }

/* ── SUCCESS STATE ───────────────────────────────────── */
.success-card {
    background: var(--bg-card);
    border: 1px solid rgba(34,197,94,0.25);
    border-radius: var(--radius-xl);
    padding: 40px;
    text-align: center;
    box-shadow: 0 0 60px rgba(34,197,94,0.08);
    animation: alertIn 0.4s var(--ease);
}

.success-icon {
    width: 80px; height: 80px;
    background: var(--green-dim);
    border: 2px solid rgba(34,197,94,0.3);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 36px;
    margin: 0 auto 20px;
    box-shadow: 0 0 40px rgba(34,197,94,0.2);
}

.success-title { font-size: 24px; font-weight: 900; color: var(--green); margin-bottom: 8px; }
.success-sub   { font-size: 15px; color: var(--text-soft); margin-bottom: 30px; }

.success-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }

.action-btn {
    text-decoration: none;
    padding: 12px 22px;
    border-radius: 14px;
    font-weight: 700;
    font-size: 14px;
    transition: all 0.2s var(--ease);
    display: flex; align-items: center; gap: 7px;
}

.action-btn:hover { transform: translateY(-2px); }
.action-primary   { background: linear-gradient(135deg, var(--green), #16a34a); color: white; box-shadow: 0 4px 20px rgba(34,197,94,0.25); }
.action-secondary { background: var(--purple-dim); border: 1px solid rgba(168,85,247,0.3); color: var(--purple); }

/* ── MAIN GRID ───────────────────────────────────────── */
.main-grid {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 18px;
    align-items: start;
}

/* ── CARD ────────────────────────────────────────────── */
.card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
    padding: 28px;
    backdrop-filter: blur(20px);
    box-shadow: var(--shadow);
}

/* ── SECTION HEADER ──────────────────────────────────── */
.section-head {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 22px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border);
}

.section-icon {
    width: 38px; height: 38px;
    border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.icon-purple { background: var(--purple-dim); border: 1px solid rgba(168,85,247,0.2); }
.icon-green  { background: var(--green-dim);  border: 1px solid rgba(34,197,94,0.2);  }

.section-name { font-size: 16px; font-weight: 800; color: var(--text); }
.section-desc { font-size: 12px; color: var(--text-muted); margin-top: 2px; }

/* ── FORM FIELDS ─────────────────────────────────────── */
.form-group { margin-bottom: 16px; }

.field-label {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 8px;
}

.field-label label {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-soft);
    display: flex; align-items: center; gap: 6px;
}

.field-hint { font-size: 11px; color: var(--text-muted); }

.field-wrap { position: relative; }

.field-icon {
    position: absolute;
    top: 50%; transform: translateY(-50%);
    font-size: 16px;
    color: var(--text-muted);
    pointer-events: none;
    z-index: 2;
    transition: color 0.2s;
}

html[dir="ltr"] .field-icon { left: 15px; }
html[dir="rtl"] .field-icon { right: 15px; }

input[type="text"],
input[type="password"],
select {
    width: 100%;
    height: 52px;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 14px;
    color: var(--text);
    font-size: 14px;
    font-family: inherit;
    outline: none;
    transition: all 0.2s var(--ease);
    -webkit-appearance: none;
    appearance: none;
}

html[dir="ltr"] input[type="text"],
html[dir="ltr"] input[type="password"] { padding: 0 16px 0 46px; }
html[dir="rtl"] input[type="text"],
html[dir="rtl"] input[type="password"] { padding: 0 46px 0 16px; }

html[dir="ltr"] select { padding: 0 40px 0 46px; background-position: right 14px center; }
html[dir="rtl"] select { padding: 0 46px 0 40px; background-position: left 14px center; }

select {
    cursor: pointer;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
}

input:focus, select:focus {
    border-color: rgba(168,85,247,0.5);
    background: rgba(168,85,247,0.05);
    box-shadow: 0 0 0 3px rgba(168,85,247,0.1);
}

.field-wrap:focus-within .field-icon { color: var(--purple); }
input::placeholder { color: var(--text-muted); }

/* Password toggle */
.pw-toggle {
    position: absolute;
    top: 50%; transform: translateY(-50%);
    background: none;
    border: none;
    cursor: pointer;
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 700;
    font-family: inherit;
    padding: 4px 8px;
    border-radius: 8px;
    transition: all 0.2s;
    z-index: 3;
}

html[dir="ltr"] .pw-toggle { right: 12px; }
html[dir="rtl"] .pw-toggle { left: 12px; }

.pw-toggle:hover { color: var(--purple); background: rgba(168,85,247,0.1); }

/* Password strength bar */
.strength-bar {
    display: flex;
    gap: 4px;
    margin-top: 7px;
    align-items: center;
}

.strength-seg {
    height: 4px;
    flex: 1;
    border-radius: 2px;
    background: rgba(255,255,255,0.07);
    transition: background 0.3s;
}

.strength-label {
    font-size: 11px;
    font-weight: 700;
    min-width: 48px;
    color: var(--text-muted);
    text-align: end;
    transition: color 0.3s;
}

html[dir="rtl"] .strength-label { text-align: start; }

/* Match indicator */
.match-hint {
    font-size: 11px;
    margin-top: 5px;
    min-height: 16px;
    transition: all 0.2s;
    color: var(--text-muted);
}
.match-ok  { color: var(--green); }
.match-bad { color: var(--red); }

/* Role cards */
.role-cards { display: grid; grid-template-columns: repeat(3,1fr); gap: 8px; }

.role-card {
    padding: 12px 10px;
    border: 2px solid rgba(255,255,255,0.06);
    border-radius: 14px;
    cursor: pointer;
    transition: all 0.2s var(--ease);
    text-align: center;
    user-select: none;
    background: rgba(255,255,255,0.02);
    position: relative;
}

.role-card:hover { border-color: rgba(255,255,255,0.12); background: rgba(255,255,255,0.04); }

.role-card input[type="radio"] {
    position: absolute;
    opacity: 0;
    width: 0; height: 0;
}

.role-card.selected-admin   { border-color: rgba(168,85,247,0.5); background: rgba(168,85,247,0.08); }
.role-card.selected-manager { border-color: rgba(59,130,246,0.5);  background: rgba(59,130,246,0.08);  }
.role-card.selected-sales   { border-color: rgba(34,197,94,0.5);   background: rgba(34,197,94,0.08);   }

.role-icon { font-size: 22px; margin-bottom: 6px; display: block; }
.role-name { font-size: 12px; font-weight: 800; color: var(--text); margin-bottom: 3px; }
.role-desc { font-size: 10px; color: var(--text-muted); line-height: 1.4; }

/* Section divider */
.section-gap { margin-bottom: 24px; }

/* Submit */
.submit-btn {
    width: 100%;
    height: 56px;
    border: none;
    cursor: pointer;
    border-radius: 16px;
    font-size: 16px;
    font-weight: 800;
    color: white;
    font-family: inherit;
    position: relative;
    overflow: hidden;
    transition: all 0.25s var(--ease);
    display: flex; align-items: center; justify-content: center; gap: 8px;
    margin-top: 6px;
}

.btn-bg {
    position: absolute; inset: 0;
    background: linear-gradient(135deg, var(--purple), #7c3aed 40%, var(--green));
    background-size: 200% 100%;
    background-position: 0%;
    transition: background-position 0.4s, opacity 0.3s;
}

.btn-shine {
    position: absolute; inset: 0;
    background: linear-gradient(90deg, transparent 30%, rgba(255,255,255,0.12) 50%, transparent 70%);
    transform: translateX(-100%);
    transition: transform 0.5s;
}

.submit-btn:hover .btn-shine { transform: translateX(100%); }
.submit-btn:hover .btn-bg { background-position: 100%; }
.submit-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(168,85,247,0.3); }
.submit-btn:active { transform: translateY(0); }

.btn-label { position: relative; z-index: 1; display: flex; align-items: center; gap: 8px; }

/* ── PREVIEW SIDEBAR ─────────────────────────────────── */
.preview-card {
    position: sticky;
    top: 20px;
    height: fit-content;
}

.avatar-circle {
    width: 70px; height: 70px;
    background: linear-gradient(135deg, var(--purple-dim), rgba(168,85,247,0.2));
    border: 2px solid rgba(168,85,247,0.25);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 30px;
    margin: 0 auto 14px;
    box-shadow: 0 0 30px rgba(168,85,247,0.15);
    transition: all 0.3s;
}

.preview-username {
    font-size: 20px;
    font-weight: 900;
    text-align: center;
    color: var(--text);
    margin-bottom: 4px;
    min-height: 28px;
    transition: all 0.2s;
}

.preview-role-badge {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 5px 14px;
    border-radius: 100px;
    font-size: 12px;
    font-weight: 700;
    margin: 0 auto 20px;
    width: fit-content;
    transition: all 0.3s;
    background: var(--purple-dim);
    border: 1px solid rgba(168,85,247,0.3);
    color: var(--purple);
}

.preview-items { display: flex; flex-direction: column; gap: 0; }

.preview-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid var(--border);
    gap: 10px;
}

.preview-item:last-child { border-bottom: none; }

.preview-lbl {
    font-size: 11px;
    color: var(--text-muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
}

.preview-val {
    font-size: 13px;
    font-weight: 700;
    color: var(--text);
    text-align: end;
    transition: all 0.2s;
}

html[dir="rtl"] .preview-val { text-align: start; }

.preview-val.empty { color: var(--text-muted); font-weight: 400; }

/* Active status */
.status-active {
    display: flex;
    align-items: center;
    gap: 5px;
    color: var(--green);
}

.status-dot {
    width: 7px; height: 7px;
    background: var(--green);
    border-radius: 50%;
    animation: sdPulse 2s infinite;
}

@keyframes sdPulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(34,197,94,0.4); }
    50%       { box-shadow: 0 0 0 4px rgba(34,197,94,0); }
}

/* ── SECURITY NOTES ──────────────────────────────────── */
.security-notes {
    margin-top: 18px;
    padding: 14px;
    background: rgba(34,197,94,0.05);
    border: 1px solid rgba(34,197,94,0.12);
    border-radius: var(--radius);
}

.sec-note-title {
    font-size: 11px;
    font-weight: 700;
    color: var(--green);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-bottom: 8px;
}

.sec-note {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 11px;
    color: var(--text-muted);
    margin-bottom: 5px;
    font-weight: 500;
}

.sec-note:last-child { margin-bottom: 0; }

/* ── RESPONSIVE ──────────────────────────────────────── */
@media (max-width: 1000px) {
    .main-grid { grid-template-columns: 1fr; }
    .preview-card { position: static; }
}

@media (max-width: 768px) {
    .topbar { flex-direction: column; align-items: flex-start; }
    .topbar-right { width: 100%; justify-content: flex-start; }
    .role-cards { grid-template-columns: 1fr; gap: 6px; }
    .role-card { display: flex; align-items: center; gap: 12px; text-align: start; }
    html[dir="rtl"] .role-card { text-align: end; }
    .role-icon { margin-bottom: 0; font-size: 20px; flex-shrink: 0; }
    .card { padding: 20px 16px; }
}

@media (max-width: 480px) {
    .container { padding: 14px 12px; }
    .page-title { font-size: 18px; }
    .success-actions { flex-direction: column; }
    .action-btn { justify-content: center; }
}
</style>
</head>

<body>
<div class="container">

    <!-- ── TOPBAR ─────────────────────────────────── -->
    <div class="topbar">
        <div class="topbar-left">
            <div class="logo-mark">👤</div>
            <div>
                <div class="page-title"><?= $t[$lang]['page_title'] ?></div>
                <div class="page-subtitle">
                    👑 <?= $t[$lang]['created_by'] ?>: <strong><?= htmlspecialchars($_SESSION['username']) ?></strong>
                </div>
            </div>
        </div>
        <div class="topbar-right">
            <a href="dashboard.php?lang=<?= $lang ?>" class="nav-btn btn-dashboard">🏠 <?= $t[$lang]['dashboard'] ?></a>
            <a href="users.php?lang=<?= $lang ?>"     class="nav-btn btn-users">👥 <?= $t[$lang]['back_users'] ?></a>
            <div class="lang-switch">
                <a href="?lang=ar" class="lang-btn <?= $lang === 'ar' ? 'lang-active' : '' ?>">🇪🇬 AR</a>
                <a href="?lang=en" class="lang-btn <?= $lang === 'en' ? 'lang-active' : '' ?>">🇺🇸 EN</a>
            </div>
        </div>
    </div>

    <!-- ── BREADCRUMB ─────────────────────────────── -->
    <div class="breadcrumb">
        <a href="dashboard.php?lang=<?= $lang ?>"><?= $t[$lang]['dashboard'] ?></a>
        <span class="breadcrumb-sep">›</span>
        <a href="users.php?lang=<?= $lang ?>"><?= $lang === 'ar' ? 'المستخدمون' : 'Users' ?></a>
        <span class="breadcrumb-sep">›</span>
        <span class="breadcrumb-current"><?= $t[$lang]['page_title'] ?></span>
    </div>

    <!-- ── ALERTS ─────────────────────────────────── -->
    <?php if ($error): ?>
    <div class="alert alert-error"><span style="flex-shrink:0">❌</span><div><?= htmlspecialchars($error) ?></div></div>
    <?php endif; ?>

    <!-- ── SUCCESS STATE ──────────────────────────── -->
    <?php if ($success): ?>
    <div class="success-card">
        <div class="success-icon">✅</div>
        <div class="success-title"><?= $t[$lang]['success'] ?></div>
        <div class="success-sub">
            @<?= htmlspecialchars($createdUsername) ?>
        </div>
        <div class="success-actions">
            <a href="add_user.php?lang=<?= $lang ?>" class="action-btn action-primary">➕ <?= $t[$lang]['add_another'] ?></a>
            <a href="users.php?lang=<?= $lang ?>"    class="action-btn action-secondary">👥 <?= $t[$lang]['view_users'] ?></a>
        </div>
    </div>

    <?php else: ?>

    <!-- ── MAIN GRID ───────────────────────────────── -->
    <div class="main-grid">

        <!-- FORM CARD -->
        <div class="card">

            <!-- Section: Identity -->
            <div class="section-head">
                <div class="section-icon icon-purple">🪪</div>
                <div>
                    <div class="section-name"><?= $t[$lang]['section_identity'] ?></div>
                    <div class="section-desc"><?= $lang === 'ar' ? 'اسم المستخدم والصلاحية' : 'Username and role assignment' ?></div>
                </div>
            </div>

            <form method="POST" id="addUserForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <!-- Username -->
                <div class="form-group">
                    <div class="field-label">
                        <label for="username">👤 <?= $t[$lang]['username'] ?></label>
                        <span class="field-hint"><?= $t[$lang]['username_hint'] ?></span>
                    </div>
                    <div class="field-wrap">
                        <span class="field-icon">@</span>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            required
                            autocomplete="off"
                            spellcheck="false"
                            autocapitalize="none"
                            placeholder="<?= $lang === 'ar' ? 'مثال: ahmed_sales' : 'e.g. john_manager' ?>"
                            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                        >
                    </div>
                </div>

                <!-- Role cards -->
                <div class="form-group section-gap">
                    <div class="field-label">
                        <label>🎭 <?= $t[$lang]['role'] ?></label>
                    </div>
                    <div class="role-cards" id="roleCards">

                        <label class="role-card" id="card-admin">
                            <input type="radio" name="role" value="admin" <?= ($_POST['role'] ?? '') === 'admin' ? 'checked' : '' ?>>
                            <span class="role-icon">👑</span>
                            <div>
                                <div class="role-name"><?= $t[$lang]['admin'] ?></div>
                                <div class="role-desc"><?= $t[$lang]['role_desc_admin'] ?></div>
                            </div>
                        </label>

                        <label class="role-card" id="card-manager">
                            <input type="radio" name="role" value="manager" <?= ($_POST['role'] ?? '') === 'manager' ? 'checked' : '' ?>>
                            <span class="role-icon">📊</span>
                            <div>
                                <div class="role-name"><?= $t[$lang]['manager'] ?></div>
                                <div class="role-desc"><?= $t[$lang]['role_desc_manager'] ?></div>
                            </div>
                        </label>

                        <label class="role-card" id="card-sales">
                            <input type="radio" name="role" value="sales" <?= ($_POST['role'] ?? '') === 'sales' ? 'checked' : '' ?>>
                            <span class="role-icon">🤝</span>
                            <div>
                                <div class="role-name"><?= $t[$lang]['sales'] ?></div>
                                <div class="role-desc"><?= $t[$lang]['role_desc_sales'] ?></div>
                            </div>
                        </label>

                    </div>
                </div>

                <!-- Divider -->
                <div style="height:1px;background:var(--border);margin:20px 0 24px"></div>

                <!-- Section: Security -->
                <div class="section-head" style="margin-bottom:18px">
                    <div class="section-icon icon-green">🔐</div>
                    <div>
                        <div class="section-name"><?= $t[$lang]['section_security'] ?></div>
                        <div class="section-desc"><?= $lang === 'ar' ? 'يُشفَّر بـ bcrypt تلقائياً' : 'Auto-hashed with bcrypt' ?></div>
                    </div>
                </div>

                <!-- Password -->
                <div class="form-group">
                    <div class="field-label">
                        <label for="password">🔑 <?= $t[$lang]['password'] ?></label>
                        <span class="field-hint"><?= $t[$lang]['pw_hint'] ?></span>
                    </div>
                    <div class="field-wrap">
                        <span class="field-icon">🔑</span>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            required
                            autocomplete="new-password"
                            placeholder="••••••••••••"
                        >
                        <button type="button" class="pw-toggle" id="pw1Toggle"><?= $t[$lang]['show'] ?></button>
                    </div>
                    <div class="strength-bar">
                        <div class="strength-seg" id="seg1"></div>
                        <div class="strength-seg" id="seg2"></div>
                        <div class="strength-seg" id="seg3"></div>
                        <div class="strength-seg" id="seg4"></div>
                        <div class="strength-label" id="strengthLabel"></div>
                    </div>
                </div>

                <!-- Confirm password -->
                <div class="form-group">
                    <div class="field-label">
                        <label for="confirm">✅ <?= $t[$lang]['confirm'] ?></label>
                    </div>
                    <div class="field-wrap">
                        <span class="field-icon">🔒</span>
                        <input
                            type="password"
                            id="confirm"
                            name="confirm"
                            required
                            autocomplete="new-password"
                            placeholder="••••••••••••"
                        >
                        <button type="button" class="pw-toggle" id="pw2Toggle"><?= $t[$lang]['show'] ?></button>
                    </div>
                    <div class="match-hint" id="matchHint"></div>
                </div>

                <button type="submit" class="submit-btn" id="submitBtn">
                    <div class="btn-bg"></div>
                    <div class="btn-shine"></div>
                    <div class="btn-label"><span>➕</span><span><?= $t[$lang]['save'] ?></span></div>
                </button>

            </form>
        </div>

        <!-- PREVIEW SIDEBAR -->
        <div class="card preview-card">
            <div class="section-head">
                <div class="section-icon icon-purple">👁</div>
                <div>
                    <div class="section-name"><?= $t[$lang]['preview_title'] ?></div>
                    <div class="section-desc"><?= $lang === 'ar' ? 'معاينة فورية' : 'Live preview' ?></div>
                </div>
            </div>

            <div class="avatar-circle" id="previewAvatar">👤</div>
            <div class="preview-username empty" id="previewUsername">—</div>
            <div class="preview-role-badge" id="previewRoleBadge">— <?= $t[$lang]['preview_role'] ?></div>

            <div class="preview-items">
                <div class="preview-item">
                    <span class="preview-lbl"><?= $t[$lang]['preview_user'] ?></span>
                    <span class="preview-val empty" id="prevUser">—</span>
                </div>
                <div class="preview-item">
                    <span class="preview-lbl"><?= $t[$lang]['preview_role'] ?></span>
                    <span class="preview-val empty" id="prevRole">—</span>
                </div>
                <div class="preview-item">
                    <span class="preview-lbl"><?= $t[$lang]['preview_status'] ?></span>
                    <span class="preview-val">
                        <span class="status-active">
                            <span class="status-dot"></span>
                            <?= $t[$lang]['preview_active'] ?>
                        </span>
                    </span>
                </div>
                <div class="preview-item">
                    <span class="preview-lbl"><?= $t[$lang]['created_by'] ?></span>
                    <span class="preview-val"><?= htmlspecialchars($_SESSION['username']) ?></span>
                </div>
                <div class="preview-item">
                    <span class="preview-lbl"><?= $lang === 'ar' ? 'كلمة المرور' : 'Password' ?></span>
                    <span class="preview-val" id="prevPwStrength" style="color:var(--text-muted)">—</span>
                </div>
            </div>

            <!-- Security notes -->
            <div class="security-notes">
                <div class="sec-note-title">🛡️ <?= $lang === 'ar' ? 'حماية مطبّقة' : 'Security applied' ?></div>
                <div class="sec-note">✅ <?= $lang === 'ar' ? 'bcrypt — لا تُخزَّن كلمة المرور أبداً بنص صريح' : 'bcrypt — password never stored plain' ?></div>
                <div class="sec-note">✅ <?= $lang === 'ar' ? 'CSRF token محمي' : 'CSRF token protected' ?></div>
                <div class="sec-note">✅ <?= $lang === 'ar' ? 'للمديرين فقط' : 'Admin-only access gate' ?></div>
                <div class="sec-note">✅ <?= $lang === 'ar' ? 'التحقق من التكرار' : 'Duplicate username check' ?></div>
            </div>
        </div>

    </div><!-- /main-grid -->
    <?php endif; ?>

</div><!-- /container -->

<script>
(function () {
    'use strict';

    /* ── TRANSLATIONS used in JS ─────────────────────── */
    const T = {
        show:    '<?= $t[$lang]['show'] ?>',
        hide:    '<?= $t[$lang]['hide'] ?>',
        weak:    '<?= $t[$lang]['strength_weak'] ?>',
        fair:    '<?= $t[$lang]['strength_fair'] ?>',
        good:    '<?= $t[$lang]['strength_good'] ?>',
        strong:  '<?= $t[$lang]['strength_strong'] ?>',
        matchOk: '<?= $lang === 'ar' ? '✓ متطابقة' : '✓ Passwords match' ?>',
        matchNo: '<?= $lang === 'ar' ? '✗ غير متطابقة' : '✗ Does not match' ?>',
        roleLabels: {
            admin:   '<?= $t[$lang]['admin'] ?>',
            manager: '<?= $t[$lang]['manager'] ?>',
            sales:   '<?= $t[$lang]['sales'] ?>',
        },
        roleIcons:  { admin: '👑', manager: '📊', sales: '🤝' },
        roleColors: { admin: 'var(--purple)', manager: 'var(--blue)', sales: 'var(--green)' },
        roleBorderColors: {
            admin:   'rgba(168,85,247,0.3)',
            manager: 'rgba(59,130,246,0.3)',
            sales:   'rgba(34,197,94,0.3)',
        },
    };

    /* ── ELEMENTS ─────────────────────────────────────── */
    const usernameEl = document.getElementById('username');
    const passwordEl = document.getElementById('password');
    const confirmEl  = document.getElementById('confirm');
    const roleRadios = document.querySelectorAll('input[name="role"]');

    const previewAvatar   = document.getElementById('previewAvatar');
    const previewUsername = document.getElementById('previewUsername');
    const previewRoleBadge= document.getElementById('previewRoleBadge');
    const prevUser        = document.getElementById('prevUser');
    const prevRole        = document.getElementById('prevRole');
    const prevPwStrength  = document.getElementById('prevPwStrength');

    const segs = [
        document.getElementById('seg1'),
        document.getElementById('seg2'),
        document.getElementById('seg3'),
        document.getElementById('seg4'),
    ];
    const strengthLabel = document.getElementById('strengthLabel');
    const matchHint     = document.getElementById('matchHint');

    /* ── PASSWORD TOGGLE ──────────────────────────────── */
    function makePwToggle(inputId, btnId) {
        const input = document.getElementById(inputId);
        const btn   = document.getElementById(btnId);
        if (!input || !btn) return;
        btn.addEventListener('click', function () {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.textContent = show ? T.hide : T.show;
        });
    }
    makePwToggle('password', 'pw1Toggle');
    makePwToggle('confirm',  'pw2Toggle');

    /* ── PASSWORD STRENGTH ────────────────────────────── */
    const strengthColors = ['var(--red)', 'var(--amber)', 'var(--blue)', 'var(--green)'];
    const strengthTexts  = [T.weak, T.fair, T.good, T.strong];

    function scorePassword(pw) {
        let score = 0;
        if (!pw) return 0;
        if (pw.length >= 8)  score++;
        if (pw.length >= 12) score++;
        if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
        if (/\d/.test(pw)) score++;
        if (/[^A-Za-z0-9]/.test(pw)) score++;
        return Math.min(4, Math.ceil(score * 4 / 5));
    }

    function updateStrength() {
        const score = scorePassword(passwordEl.value);
        segs.forEach((s, i) => {
            s.style.background = i < score
                ? strengthColors[Math.min(score - 1, 3)]
                : 'rgba(255,255,255,0.07)';
        });
        if (passwordEl.value.length === 0) {
            strengthLabel.textContent = '';
            strengthLabel.style.color = 'var(--text-muted)';
        } else {
            strengthLabel.textContent = strengthTexts[Math.min(score - 1, 3)];
            strengthLabel.style.color = strengthColors[Math.min(score - 1, 3)];
        }

        // Update preview
        if (passwordEl.value.length === 0) {
            prevPwStrength.textContent = '—';
            prevPwStrength.style.color = 'var(--text-muted)';
        } else {
            prevPwStrength.textContent = strengthTexts[Math.min(score - 1, 3)];
            prevPwStrength.style.color = strengthColors[Math.min(score - 1, 3)];
        }

        updateMatch();
    }

    function updateMatch() {
        const pw = passwordEl.value;
        const cn = confirmEl.value;
        if (!cn.length) {
            matchHint.textContent = '';
            matchHint.className   = 'match-hint';
            return;
        }
        if (pw === cn) {
            matchHint.textContent = T.matchOk;
            matchHint.className   = 'match-hint match-ok';
        } else {
            matchHint.textContent = T.matchNo;
            matchHint.className   = 'match-hint match-bad';
        }
    }

    passwordEl && passwordEl.addEventListener('input', updateStrength);
    confirmEl  && confirmEl.addEventListener('input',  updateMatch);

    /* ── ROLE CARDS ───────────────────────────────────── */
    function updateRoleCards() {
        const selected = document.querySelector('input[name="role"]:checked');
        const roleVal  = selected ? selected.value : null;

        ['admin', 'manager', 'sales'].forEach(r => {
            const card = document.getElementById('card-' + r);
            if (card) {
                card.classList.remove('selected-admin', 'selected-manager', 'selected-sales');
                if (r === roleVal) card.classList.add('selected-' + r);
            }
        });

        // Update preview
        if (roleVal) {
            const icon  = T.roleIcons[roleVal]  || '👤';
            const label = T.roleLabels[roleVal] || roleVal;
            const color = T.roleColors[roleVal] || 'var(--purple)';
            const bcolor= T.roleBorderColors[roleVal] || 'rgba(168,85,247,0.3)';

            previewAvatar.textContent = icon;
            previewAvatar.style.borderColor = bcolor;
            previewRoleBadge.textContent = icon + ' ' + label;
            previewRoleBadge.style.color        = color;
            previewRoleBadge.style.borderColor   = bcolor;
            previewRoleBadge.style.background    = color.replace(')', ',0.1)').replace('var(--','rgba(').replace('--','');

            prevRole.textContent = icon + ' ' + label;
            prevRole.style.color = color;
            prevRole.classList.remove('empty');
        } else {
            previewAvatar.textContent = '👤';
            previewRoleBadge.textContent = '— <?= $t[$lang]['preview_role'] ?>';
            prevRole.textContent = '—';
            prevRole.classList.add('empty');
        }
    }

    roleRadios.forEach(r => r.addEventListener('change', updateRoleCards));
    updateRoleCards(); // init on page load

    /* ── USERNAME PREVIEW ─────────────────────────────── */
    usernameEl && usernameEl.addEventListener('input', function () {
        const val = this.value.trim();
        if (val) {
            previewUsername.textContent = '@' + val;
            previewUsername.classList.remove('empty');
            prevUser.textContent = val;
            prevUser.classList.remove('empty');
        } else {
            previewUsername.textContent = '—';
            previewUsername.classList.add('empty');
            prevUser.textContent = '—';
            prevUser.classList.add('empty');
        }
    });

    /* ── FORM VALIDATION ──────────────────────────────── */
    const form = document.getElementById('addUserForm');
    form && form.addEventListener('submit', function (e) {
        const pw = passwordEl ? passwordEl.value : '';
        const cn = confirmEl  ? confirmEl.value  : '';
        const un = usernameEl ? usernameEl.value.trim() : '';
        const role = document.querySelector('input[name="role"]:checked');

        let ok = true;

        if (!un)   { ok = false; usernameEl && (usernameEl.style.borderColor = 'rgba(239,68,68,0.5)'); }
        else         { usernameEl && (usernameEl.style.borderColor = ''); }

        if (!role) { ok = false; }

        if (pw.length < 8) {
            ok = false;
            passwordEl && (passwordEl.style.borderColor = 'rgba(239,68,68,0.5)');
        } else {
            passwordEl && (passwordEl.style.borderColor = '');
        }

        if (pw !== cn) {
            ok = false;
            confirmEl && (confirmEl.style.borderColor = 'rgba(239,68,68,0.5)');
        } else {
            confirmEl && (confirmEl.style.borderColor = '');
        }

        if (!ok) {
            e.preventDefault();
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
        }

        // Loading state
        const btn   = document.getElementById('submitBtn');
        const label = btn && btn.querySelector('.btn-label');
        if (label) {
            label.innerHTML = '<span>⏳</span><span><?= $lang === 'ar' ? 'جاري الإنشاء...' : 'Creating...' ?></span>';
        }
        if (btn) btn.disabled = true;
        setTimeout(() => { if (btn) btn.disabled = false; }, 5000);
    });

    /* ── AUTO-FOCUS ───────────────────────────────────── */
    if (usernameEl) usernameEl.focus();

})();
</script>

</body>
</html>