<?php
/*
 * reset_password.php — Admin resets a user's password.
 * Security: bcrypt hashing, admin-only, CSRF protection, session check.
 */

require 'auth.php';
require 'config.php';

perm_require('page.reset_user_password');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: users.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { die('User Not Found'); }

$lang = $_GET['lang'] ?? 'ar';
$dir  = $lang === 'ar' ? 'rtl' : 'ltr';

$t = [
    'ar' => [
        'title'            => 'إعادة تعيين كلمة المرور',
        'subtitle'         => 'تغيير كلمة مرور المستخدم',
        'new_password'     => 'كلمة المرور الجديدة',
        'confirm_password' => 'تأكيد كلمة المرور',
        'save'             => 'حفظ كلمة المرور',
        'back'             => 'العودة للمستخدمين',
        'success'          => 'تم تغيير كلمة المرور بنجاح',
        'mismatch'         => 'كلمتا المرور غير متطابقتين',
        'too_short'        => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل',
        'show'             => 'إظهار',
        'hide'             => 'إخفاء',
        'strength'         => 'قوة كلمة المرور',
        'weak'             => 'ضعيفة',
        'fair'             => 'متوسطة',
        'good'             => 'جيدة',
        'strong'           => 'قوية',
        'editing'          => 'تعديل حساب',
        'role_admin'       => 'مسؤول',
        'role_manager'     => 'مدير',
        'role_sales'       => 'مبيعات',
        'requirements'     => 'متطلبات كلمة المرور',
        'req_length'       => '8 أحرف على الأقل',
        'req_upper'        => 'حرف كبير واحد على الأقل',
        'req_number'       => 'رقم واحد على الأقل',
    ],
    'en' => [
        'title'            => 'Reset Password',
        'subtitle'         => 'Change user account password',
        'new_password'     => 'New Password',
        'confirm_password' => 'Confirm Password',
        'save'             => 'Save Password',
        'back'             => 'Back To Users',
        'success'          => 'Password Updated Successfully',
        'mismatch'         => 'Passwords Do Not Match',
        'too_short'        => 'Password must be at least 8 characters',
        'show'             => 'Show',
        'hide'             => 'Hide',
        'strength'         => 'Password Strength',
        'weak'             => 'Weak',
        'fair'             => 'Fair',
        'good'             => 'Good',
        'strong'           => 'Strong',
        'editing'          => 'Editing account',
        'role_admin'       => 'Admin',
        'role_manager'     => 'Manager',
        'role_sales'       => 'Sales',
        'requirements'     => 'Password Requirements',
        'req_length'       => 'At least 8 characters',
        'req_upper'        => 'At least one uppercase letter',
        'req_number'       => 'At least one number',
    ],
];

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403); die('Invalid request (CSRF)');
    }

    $password = trim($_POST['password'] ?? '');
    $confirm  = trim($_POST['confirm']  ?? '');

    if (strlen($password) < 8) {
        $error = $t[$lang]['too_short'];
    } elseif ($password !== $confirm) {
        $error = $t[$lang]['mismatch'];
    } else {
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $stmt   = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashed, $id]);
        $success = $t[$lang]['success'];
        // Regenerate CSRF after successful action
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

$roleLabels = [
    'admin'   => $t[$lang]['role_admin'],
    'manager' => $t[$lang]['role_manager'],
    'sales'   => $t[$lang]['role_sales'],
];
$userRole = $roleLabels[$user['role']] ?? $user['role'];
$initial  = mb_strtoupper(mb_substr($user['username'], 0, 1, 'UTF-8'), 'UTF-8');
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
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root {
    --bg:       #020617;
    --card:     rgba(13,20,38,.95);
    --border:   rgba(255,255,255,.07);
    --green:    #22c55e;
    --purple:   #9333ea;
    --amber:    #f59e0b;
    --red:      #ef4444;
    --blue:     #3b82f6;
    --text:     #f1f5f9;
    --muted:    #64748b;
    --muted-l:  #94a3b8;
    --input-bg: #0d1526;
}
html[lang="ar"] body { font-family: 'Cairo', sans-serif; }
html[lang="en"] body { font-family: 'Inter', sans-serif; }

body {
    min-height: 100vh;
    background: var(--bg);
    color: var(--text);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px 16px;
    position: relative;
    overflow-x: hidden;
}

/* ── Animated background orbs ── */
.orb {
    position: fixed;
    border-radius: 50%;
    filter: blur(100px);
    pointer-events: none;
    z-index: 0;
    animation: orbFloat 8s ease-in-out infinite;
}
.orb-1 { width:500px; height:500px; background:rgba(147,51,234,.18); top:-200px; left:-200px; animation-delay:0s; }
.orb-2 { width:400px; height:400px; background:rgba(34,197,94,.12);  bottom:-150px; right:-150px; animation-delay:-3s; }
.orb-3 { width:300px; height:300px; background:rgba(245,158,11,.08); top:50%; left:50%; transform:translate(-50%,-50%); animation-delay:-6s; }
@keyframes orbFloat {
    0%,100% { transform: translate(0,0) scale(1); }
    33%      { transform: translate(20px,-20px) scale(1.05); }
    66%      { transform: translate(-15px,15px) scale(.95); }
}

/* ── Grid lines decoration ── */
body::before {
    content:'';
    position:fixed; inset:0; z-index:0;
    background-image:
        linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px);
    background-size: 60px 60px;
    pointer-events:none;
}

/* ── Main layout ── */
.page-wrap {
    position: relative; z-index: 10;
    width: 100%; max-width: 520px;
}

/* ── Back link ── */
.back-link {
    display: inline-flex; align-items: center; gap: 8px;
    color: var(--muted-l); text-decoration: none;
    font-size: 13px; font-weight: 600;
    padding: 8px 14px; border-radius: 10px;
    background: rgba(255,255,255,.04);
    border: 1px solid var(--border);
    margin-bottom: 20px;
    transition: color .2s, background .2s;
}
.back-link:hover { color: var(--text); background: rgba(255,255,255,.08); }

/* ── Card ── */
.card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 28px;
    overflow: hidden;
    box-shadow: 0 32px 80px rgba(0,0,0,.5), 0 0 0 1px rgba(255,255,255,.03);
}

/* ── Card header banner ── */
.card-banner {
    background: linear-gradient(135deg, rgba(147,51,234,.25) 0%, rgba(34,197,94,.15) 100%);
    border-bottom: 1px solid rgba(147,51,234,.2);
    padding: 32px 32px 28px;
    position: relative;
    overflow: hidden;
}
.card-banner::before {
    content:'';
    position:absolute; inset:0;
    background: radial-gradient(ellipse at top left, rgba(147,51,234,.3), transparent 60%);
}
.card-banner::after {
    content:'🔒';
    position:absolute; right:24px; top:50%; transform:translateY(-50%);
    font-size:64px; opacity:.08;
}
html[dir="rtl"] .card-banner::after { right:auto; left:24px; }

.user-profile {
    display: flex; align-items: center; gap: 16px;
    position: relative; z-index: 1;
}
.user-avatar {
    width: 64px; height: 64px; border-radius: 18px;
    background: linear-gradient(135deg, var(--purple), var(--green));
    display: flex; align-items: center; justify-content: center;
    font-size: 26px; font-weight: 900; color: white;
    box-shadow: 0 8px 24px rgba(147,51,234,.4);
    flex-shrink: 0;
}
.user-info .editing-label {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .1em; color: var(--purple); opacity: .9;
    margin-bottom: 4px;
}
.user-info .username {
    font-size: 22px; font-weight: 900; color: var(--text);
    line-height: 1.1;
}
.role-badge {
    display: inline-flex; align-items: center; gap: 5px;
    margin-top: 6px;
    padding: 3px 10px; border-radius: 20px;
    font-size: 11px; font-weight: 800;
    background: rgba(147,51,234,.2);
    border: 1px solid rgba(147,51,234,.35);
    color: #c084fc;
}

/* ── Form body ── */
.card-body { padding: 28px 32px 32px; }

/* ── Alert messages ── */
.alert {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 18px; border-radius: 14px;
    font-size: 14px; font-weight: 700;
    margin-bottom: 22px;
    animation: slideIn .3s ease;
}
@keyframes slideIn { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
.alert-success {
    background: rgba(34,197,94,.1);
    border: 1px solid rgba(34,197,94,.3);
    color: #86efac;
}
.alert-error {
    background: rgba(239,68,68,.1);
    border: 1px solid rgba(239,68,68,.3);
    color: #fca5a5;
}
.alert-icon { font-size: 18px; flex-shrink: 0; }

/* ── Requirements box ── */
.req-box {
    background: rgba(245,158,11,.06);
    border: 1px solid rgba(245,158,11,.15);
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 22px;
}
.req-title {
    font-size: 11px; font-weight: 800; text-transform: uppercase;
    letter-spacing: .08em; color: var(--amber); margin-bottom: 10px;
}
.req-list { list-style: none; display: flex; flex-direction: column; gap: 6px; }
.req-item {
    display: flex; align-items: center; gap: 8px;
    font-size: 12px; font-weight: 600; color: var(--muted-l);
    transition: color .2s;
}
.req-item .req-dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: var(--muted); flex-shrink: 0;
    transition: background .2s;
}
.req-item.met { color: var(--green); }
.req-item.met .req-dot { background: var(--green); }

/* ── Form groups ── */
.form-group { margin-bottom: 20px; }
.form-label {
    display: block; margin-bottom: 8px;
    font-size: 12px; font-weight: 800;
    text-transform: uppercase; letter-spacing: .07em;
    color: var(--muted-l);
}

/* ── Input wrapper ── */
.input-wrap {
    position: relative;
}
.input-wrap input {
    width: 100%; height: 54px;
    background: var(--input-bg);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 14px;
    color: var(--text);
    font-size: 15px; font-family: inherit;
    padding: 0 54px 0 18px;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
}
html[dir="rtl"] .input-wrap input { padding: 0 18px 0 54px; }
.input-wrap input:focus {
    border-color: var(--purple);
    box-shadow: 0 0 0 3px rgba(147,51,234,.15);
}
.input-wrap input.match {
    border-color: var(--green);
    box-shadow: 0 0 0 3px rgba(34,197,94,.1);
}
.input-wrap input.mismatch {
    border-color: var(--red);
    box-shadow: 0 0 0 3px rgba(239,68,68,.1);
}

.toggle-pw {
    position: absolute; right: 16px; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer;
    color: var(--muted); font-size: 18px; line-height: 1;
    padding: 4px; transition: color .2s;
}
html[dir="rtl"] .toggle-pw { right:auto; left:16px; }
.toggle-pw:hover { color: var(--text); }

/* ── Strength bar ── */
.strength-wrap { margin-top: 10px; }
.strength-label {
    display: flex; justify-content: space-between; align-items: center;
    font-size: 11px; font-weight: 700; margin-bottom: 6px;
    color: var(--muted);
}
.strength-label span { transition: color .3s; }
.strength-track {
    height: 4px; background: rgba(255,255,255,.06);
    border-radius: 4px; overflow: hidden;
}
.strength-bar {
    height: 100%; border-radius: 4px;
    width: 0%; transition: width .4s ease, background .4s ease;
}

/* ── Match indicator ── */
.match-hint {
    margin-top: 8px; font-size: 11px; font-weight: 700;
    opacity: 0; transition: opacity .2s;
    display: flex; align-items: center; gap: 5px;
}
.match-hint.visible { opacity: 1; }
.match-hint.ok  { color: var(--green); }
.match-hint.bad { color: var(--red); }

/* ── Submit button ── */
.btn-submit {
    width: 100%; height: 58px;
    border: none; border-radius: 16px; cursor: pointer;
    font-size: 16px; font-weight: 800; font-family: inherit;
    color: white;
    background: linear-gradient(135deg, var(--purple) 0%, var(--green) 100%);
    position: relative; overflow: hidden;
    transition: transform .2s, box-shadow .2s, opacity .2s;
    margin-top: 8px;
}
.btn-submit::before {
    content:''; position:absolute; inset:0;
    background: linear-gradient(135deg, rgba(255,255,255,.1), transparent);
}
.btn-submit:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 32px rgba(147,51,234,.4);
}
.btn-submit:active { transform: translateY(-1px); }

/* ── Divider ── */
.divider {
    height: 1px; background: var(--border);
    margin: 24px 0;
}

/* ── Responsive ── */
@media(max-width:600px) {
    .card-banner { padding: 24px 20px 20px; }
    .card-body   { padding: 20px 20px 24px; }
    .user-avatar { width:52px; height:52px; font-size:20px; border-radius:14px; }
    .user-info .username { font-size:18px; }
}
</style>
</head>
<body>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>
<div class="orb orb-3"></div>

<div class="page-wrap">

    <a href="users.php?lang=<?= $lang ?>" class="back-link">
        <?= $lang === 'ar' ? '→' : '←' ?> <?= $t[$lang]['back'] ?>
    </a>

    <div class="card">

        <!-- Banner -->
        <div class="card-banner">
            <div class="user-profile">
                <div class="user-avatar"><?= htmlspecialchars($initial) ?></div>
                <div class="user-info">
                    <div class="editing-label">🔒 <?= $t[$lang]['editing'] ?></div>
                    <div class="username"><?= htmlspecialchars($user['username']) ?></div>
                    <div class="role-badge">⚙️ <?= htmlspecialchars($userRole) ?></div>
                </div>
            </div>
        </div>

        <!-- Body -->
        <div class="card-body">

            <?php if ($success): ?>
            <div class="alert alert-success">
                <span class="alert-icon">✅</span>
                <?= htmlspecialchars($success) ?>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-error">
                <span class="alert-icon">⚠️</span>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <!-- Requirements -->
            <div class="req-box">
                <div class="req-title">🛡️ <?= $t[$lang]['requirements'] ?></div>
                <ul class="req-list">
                    <li class="req-item" id="req-length"><span class="req-dot"></span><?= $t[$lang]['req_length'] ?></li>
                    <li class="req-item" id="req-upper"> <span class="req-dot"></span><?= $t[$lang]['req_upper'] ?></li>
                    <li class="req-item" id="req-number"><span class="req-dot"></span><?= $t[$lang]['req_number'] ?></li>
                </ul>
            </div>

            <form method="POST" id="pwForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <!-- New password -->
                <div class="form-group">
                    <label class="form-label">🔑 <?= $t[$lang]['new_password'] ?></label>
                    <div class="input-wrap">
                        <input type="password" name="password" id="pw1"
                               autocomplete="new-password" required
                               placeholder="••••••••••••">
                        <button type="button" class="toggle-pw" onclick="togglePw('pw1',this)" tabindex="-1">👁️</button>
                    </div>
                    <!-- Strength bar -->
                    <div class="strength-wrap">
                        <div class="strength-label">
                            <span><?= $t[$lang]['strength'] ?></span>
                            <span id="strength-text" style="color:var(--muted)">—</span>
                        </div>
                        <div class="strength-track"><div class="strength-bar" id="strength-bar"></div></div>
                    </div>
                </div>

                <!-- Confirm password -->
                <div class="form-group">
                    <label class="form-label">🔐 <?= $t[$lang]['confirm_password'] ?></label>
                    <div class="input-wrap">
                        <input type="password" name="confirm" id="pw2"
                               autocomplete="new-password" required
                               placeholder="••••••••••••">
                        <button type="button" class="toggle-pw" onclick="togglePw('pw2',this)" tabindex="-1">👁️</button>
                    </div>
                    <div class="match-hint" id="matchHint"></div>
                </div>

                <div class="divider"></div>

                <button type="submit" class="btn-submit">
                    💾 <?= $t[$lang]['save'] ?>
                </button>
            </form>

        </div>
    </div>
</div>

<script>
const LANG = '<?= $lang ?>';
const TXT = {
    weak:   '<?= addslashes($t[$lang]['weak']) ?>',
    fair:   '<?= addslashes($t[$lang]['fair']) ?>',
    good:   '<?= addslashes($t[$lang]['good']) ?>',
    strong: '<?= addslashes($t[$lang]['strong']) ?>',
    match:  '<?= $lang === "ar" ? "كلمتا المرور متطابقتان ✓" : "Passwords match ✓" ?>',
    noMatch:'<?= $lang === "ar" ? "كلمتا المرور غير متطابقتين" : "Passwords do not match" ?>',
};

const pw1  = document.getElementById('pw1');
const pw2  = document.getElementById('pw2');
const bar  = document.getElementById('strength-bar');
const stxt = document.getElementById('strength-text');
const hint = document.getElementById('matchHint');

/* ── Toggle visibility ── */
function togglePw(inputId, btn) {
    const inp = document.getElementById(inputId);
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    btn.textContent = show ? '🙈' : '👁️';
}

/* ── Strength calculation ── */
function calcStrength(pw) {
    let score = 0;
    if (pw.length >= 8)  score++;
    if (pw.length >= 12) score++;
    if (/[A-Z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;
    return score;
}

/* ── Requirement checks ── */
function updateReqs(pw) {
    const checks = {
        'req-length': pw.length >= 8,
        'req-upper':  /[A-Z]/.test(pw),
        'req-number': /[0-9]/.test(pw),
    };
    Object.entries(checks).forEach(([id, met]) => {
        document.getElementById(id).classList.toggle('met', met);
    });
}

pw1.addEventListener('input', function() {
    const pw  = this.value;
    const score = calcStrength(pw);
    updateReqs(pw);

    const levels = [
        { pct:'0%',   color:'transparent',      label:'—',       textColor:'var(--muted)' },
        { pct:'25%',  color:'var(--red)',        label:TXT.weak,  textColor:'var(--red)' },
        { pct:'50%',  color:'var(--amber)',      label:TXT.fair,  textColor:'var(--amber)' },
        { pct:'75%',  color:'var(--blue)',       label:TXT.good,  textColor:'#60a5fa' },
        { pct:'100%', color:'var(--green)',      label:TXT.strong,textColor:'var(--green)' },
    ];
    const lvl = pw.length === 0 ? levels[0] : levels[Math.min(score, 4)];
    bar.style.width      = lvl.pct;
    bar.style.background = lvl.color;
    stxt.textContent     = lvl.label;
    stxt.style.color     = lvl.textColor;

    // Re-check confirm match
    checkMatch();
});

function checkMatch() {
    const v1 = pw1.value, v2 = pw2.value;
    if (!v2) {
        hint.className = 'match-hint';
        pw2.className  = '';
        return;
    }
    const ok = v1 === v2;
    hint.textContent = ok ? TXT.match : TXT.noMatch;
    hint.className   = 'match-hint visible ' + (ok ? 'ok' : 'bad');
    pw2.className    = ok ? 'match' : 'mismatch';
}

pw2.addEventListener('input', checkMatch);

/* ── Submit guard ── */
document.getElementById('pwForm').addEventListener('submit', function(e) {
    if (pw1.value !== pw2.value || pw1.value.length < 8) {
        e.preventDefault();
        pw2.classList.add('mismatch');
    }
});
</script>
</body>
</html>
