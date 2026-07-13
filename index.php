<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$lang = $_GET['lang'] ?? 'ar';
$lang = in_array($lang, ['ar','en']) ? $lang : 'ar';
$dir  = $lang === 'ar' ? 'rtl' : 'ltr';

$t = [
    'ar' => [
        'title'    => 'تسجيل الدخول',
        'subtitle' => 'نظام إدارة مخزون First 1 Car',
        'username' => 'اسم المستخدم',
        'password' => 'كلمة المرور',
        'remember' => 'تذكرني',
        'forgot'   => 'نسيت كلمة المرور؟',
        'login'    => 'تسجيل الدخول',
        'welcome'  => 'مرحباً بعودتك',
        'loading'  => 'جارٍ التحقق...',
        'show_pw'  => 'إظهار',
        'hide_pw'  => 'إخفاء',
        'lang_ar'  => 'العربية',
        'lang_en'  => 'English',
        'copy'     => '© جميع الحقوق محفوظة',
        'system'   => 'نظام إدارة المخزون',
    ],
    'en' => [
        'title'    => 'Sign In',
        'subtitle' => 'First 1 Car Inventory System',
        'username' => 'Username',
        'password' => 'Password',
        'remember' => 'Remember Me',
        'forgot'   => 'Forgot Password?',
        'login'    => 'Sign In',
        'welcome'  => 'Welcome Back',
        'loading'  => 'Verifying...',
        'show_pw'  => 'Show',
        'hide_pw'  => 'Hide',
        'lang_ar'  => 'العربية',
        'lang_en'  => 'English',
        'copy'     => '© All rights reserved',
        'system'   => 'Inventory Management System',
    ],
];
$T = $t[$lang];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>First 1 Car – <?= $T['title'] ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Tajawal:wght@300;400;500;700;800&display=swap" rel="stylesheet">
<style>
/* ============================================================
   First 1 Car · Login — "control room at night"
   Auto-transforming robot↔car mascot · 4D parallax
   Form + PHP behaviour unchanged from the original.
   ============================================================ */
:root{
  --green:#22c55e; --green-2:#4ade80; --purple:#9333ea; --purple-2:#a855f7;
  --bg:#020617; --bg2:#0b1220; --surface:rgba(15,23,42,.82); --surface2:#0d1526;
  --border:rgba(255,255,255,.08); --border-hi:rgba(255,255,255,.16);
  --text:#f1f5f9; --muted:#94a3b8; --faint:#475569; --danger:#ef4444;
  --radius:20px; --radius-sm:14px;
  --font-en:'Inter',sans-serif; --font-ar:'Tajawal',sans-serif;
  --tr:.28s cubic-bezier(.4,0,.2,1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:16px}
body{
  min-height:100vh;background:var(--bg);color:var(--text);
  font-family:<?= $lang === 'ar' ? "var(--font-ar)" : "var(--font-en)" ?>;
  -webkit-font-smoothing:antialiased;overflow-x:hidden;overflow-y:auto;position:relative;
  display:flex;align-items:center;justify-content:center;padding:1rem .9rem 1.4rem;
}

/* ── layered atmosphere ── */
.layer{position:fixed;inset:0;pointer-events:none}
#stars{z-index:0}
.grid{
  z-index:1;
  background-image:
    linear-gradient(rgba(148,163,184,.05) 1px,transparent 1px),
    linear-gradient(90deg,rgba(148,163,184,.05) 1px,transparent 1px);
  background-size:54px 54px;
  -webkit-mask-image:radial-gradient(circle at 50% 42%,#000 0%,transparent 78%);
          mask-image:radial-gradient(circle at 50% 42%,#000 0%,transparent 78%);
  will-change:transform;
}
.orb{position:fixed;border-radius:50%;filter:blur(90px);will-change:transform;z-index:1}
.orb-g{width:520px;height:520px;top:-160px;left:-150px;
  background:radial-gradient(circle,rgba(34,197,94,.24),transparent 68%)}
.orb-p{width:560px;height:560px;bottom:-200px;right:-170px;
  background:radial-gradient(circle,rgba(147,51,234,.22),transparent 68%)}
.orb-c{width:300px;height:300px;top:50%;left:50%;transform:translate(-50%,-50%);
  background:radial-gradient(circle,rgba(34,197,94,.08),transparent 70%)}

/* ── 3D stage ── */
.stage{position:relative;z-index:5;perspective:1400px;width:100%;max-width:440px}
.tilt{transform-style:preserve-3d;transition:transform .18s ease-out;will-change:transform}

/* ── mascot ── */
.robot-wrap{display:flex;justify-content:center;margin-bottom:-30px;
  transform:translateZ(60px);position:relative;z-index:6}
.robot{width:120px;height:120px;position:relative;animation:float 4.2s ease-in-out infinite;cursor:pointer}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-9px)}}
.stack{position:absolute;inset:0;transform-style:preserve-3d;will-change:transform}
.stack svg{position:absolute;inset:0;width:100%;height:100%;overflow:visible;
  filter:drop-shadow(0 14px 22px rgba(0,0,0,.55)) drop-shadow(0 0 18px rgba(34,197,94,.22));
  transition:opacity .18s ease}
.carsvg{opacity:0;pointer-events:none}
.robot.is-car .botsvg{opacity:0}
.robot.is-car .carsvg{opacity:1}
.stack.tf{animation:transform .72s cubic-bezier(.5,0,.5,1)}
@keyframes transform{
  0%{transform:rotateY(0) scale(1)}
  45%{transform:rotateY(160deg) scale(.55,1.18)}
  55%{transform:rotateY(200deg) scale(.55,1.18)}
  100%{transform:rotateY(360deg) scale(1)}
}
.wheel{transform-origin:center;transform-box:fill-box}
.robot.is-car .wheel{animation:roll 1.4s linear infinite}
.robot.driving .wheel{animation:roll .28s linear infinite}
@keyframes roll{to{transform:rotate(360deg)}}
.robot.driving{animation:driveoff 1.5s ease-in forwards}
html[dir="rtl"] .robot.driving{animation:driveoff-rtl 1.5s ease-in forwards}
@keyframes driveoff{0%{transform:translateX(0)}30%{transform:translateX(-14px)}100%{transform:translateX(150%);opacity:0}}
@keyframes driveoff-rtl{0%{transform:translateX(0)}30%{transform:translateX(14px)}100%{transform:translateX(-150%);opacity:0}}
.speed{position:absolute;top:52%;inset-inline-end:100%;width:60px;height:2px;opacity:0}
.speed b{position:absolute;height:2px;border-radius:2px;background:linear-gradient(90deg,transparent,var(--green))}
.speed b:nth-child(1){top:-10px;width:40px}
.speed b:nth-child(2){top:0;width:60px;background:linear-gradient(90deg,transparent,var(--purple))}
.speed b:nth-child(3){top:10px;width:34px}
.robot.driving .speed{opacity:1;animation:zoom .3s linear infinite}
@keyframes zoom{from{transform:translateX(20px)}to{transform:translateX(-20px)}}
.pupil{transition:transform .12s ease-out}
.hand{transition:transform .5s cubic-bezier(.34,1.56,.64,1);transform-origin:center}
.robot.shy .hand-l{transform:translate(26px,-40px) rotate(-8deg)}
.robot.shy .hand-r{transform:translate(-26px,-40px) rotate(8deg)}
.robot.wave .arm-r{animation:wave 1.6s ease-in-out}
@keyframes wave{0%,100%{transform:rotate(0)}15%{transform:rotate(-26deg)}30%{transform:rotate(6deg)}45%{transform:rotate(-26deg)}60%{transform:rotate(0)}}
.antenna-tip{animation:tipblink 1.8s ease-in-out infinite}
@keyframes tipblink{0%,100%{opacity:1}50%{opacity:.45}}

/* speech bubble */
.bubble{position:absolute;top:-4px;left:50%;transform:translateX(-50%) scale(.9);
  background:linear-gradient(135deg,rgba(34,197,94,.16),rgba(147,51,234,.16));
  border:1px solid var(--border-hi);backdrop-filter:blur(8px);
  padding:.38rem .78rem;border-radius:12px;white-space:nowrap;font-size:.72rem;
  font-weight:700;color:var(--text);opacity:0;pointer-events:none;
  transition:opacity .4s var(--tr),transform .4s var(--tr)}
.bubble.show{opacity:1;transform:translateX(-50%) scale(1)}
.bubble::after{content:'';position:absolute;bottom:-5px;left:50%;transform:translateX(-50%) rotate(45deg);
  width:9px;height:9px;background:rgba(147,51,234,.16);border-right:1px solid var(--border-hi);border-bottom:1px solid var(--border-hi)}

/* ── card ── */
.card{position:relative;background:var(--surface);backdrop-filter:blur(26px) saturate(1.4);
  -webkit-backdrop-filter:blur(26px) saturate(1.4);
  border:1px solid var(--border);border-radius:26px;
  padding:2.5rem 1.9rem 1.5rem;transform:translateZ(20px);
  box-shadow:0 0 0 1px rgba(34,197,94,.05),0 40px 90px rgba(0,0,0,.6),0 10px 30px rgba(0,0,0,.4)}
.card::before{content:'';position:absolute;top:0;left:2.2rem;right:2.2rem;height:1.5px;border-radius:2px;
  background:linear-gradient(90deg,var(--green),var(--purple));opacity:.8;box-shadow:0 0 14px rgba(34,197,94,.5)}
.card::after{content:'';position:absolute;inset:0;border-radius:26px;pointer-events:none;
  background:linear-gradient(115deg,transparent 30%,rgba(255,255,255,.05) 48%,transparent 62%);
  background-size:250% 250%;animation:sheen 7s ease-in-out infinite}
@keyframes sheen{0%{background-position:120% 0}55%,100%{background-position:-40% 0}}

.lang-bar{display:flex;justify-content:center;gap:.5rem;margin-bottom:1.35rem}
.lang-bar a{text-decoration:none;padding:.34rem 1.05rem;border-radius:10px;border:1px solid var(--border);
  color:var(--muted);font-size:.8rem;font-weight:700;transition:var(--tr);letter-spacing:.02em}
.lang-bar a:hover{border-color:var(--border-hi);color:var(--text)}
.lang-bar a.active{background:rgba(34,197,94,.12);border-color:var(--green);color:var(--green)}

.logo{text-align:center;margin-bottom:1.1rem}
.logo-img-wrap{display:inline-flex;align-items:center;justify-content:center;width:66px;height:66px;
  background:var(--surface2);border:1px solid var(--border-hi);border-radius:20px;position:relative;overflow:hidden}
.logo-img-wrap::after{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(34,197,94,.15),rgba(147,51,234,.15));pointer-events:none}
.logo-img-wrap img{width:46px;height:46px;object-fit:contain;position:relative;z-index:1}
.logo-text-mark{width:46px;height:46px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:800;position:relative;z-index:1;line-height:1}
.brand-name{font-size:1.7rem;font-weight:800;letter-spacing:-.03em;line-height:1;margin-top:.7rem}
.brand-name .g{color:var(--green)}.brand-name .p{color:var(--purple)}
.subtitle{font-size:.8rem;color:var(--muted);margin-top:.35rem}

.welcome-block{text-align:center;margin-bottom:1.35rem}
.welcome-block h2{font-size:1.3rem;font-weight:800;
  background:linear-gradient(90deg,var(--green),#a3e635 50%,var(--purple));
  -webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:.25rem}
.welcome-block p{font-size:.8rem;color:var(--muted)}

.error-box{display:flex;align-items:center;gap:.6rem;background:rgba(239,68,68,.12);
  border:1px solid rgba(239,68,68,.28);border-radius:var(--radius-sm);padding:.72rem 1rem;
  margin-bottom:1.2rem;color:#fca5a5;font-size:.8rem;font-weight:600;animation:pop .3s ease}
.error-box svg{flex-shrink:0}
@keyframes pop{from{opacity:0;transform:scale(.96) translateY(-4px)}to{opacity:1;transform:scale(1) translateY(0)}}

.form-group{margin-bottom:1rem}
.form-group label{display:block;margin-bottom:.42rem;font-size:.8rem;font-weight:700;color:var(--muted);transition:color var(--tr)}
.form-group:focus-within label{color:var(--green)}
.input-wrap{position:relative;display:flex;align-items:center}
.input-icon{position:absolute;inset-inline-start:1rem;color:var(--faint);pointer-events:none;display:flex;transition:color var(--tr)}
.form-group:focus-within .input-icon{color:var(--green)}
.input-wrap input{width:100%;height:53px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);
  outline:none;color:var(--text);font-size:.94rem;font-family:inherit;-webkit-appearance:none;
  transition:border-color var(--tr),box-shadow var(--tr),background var(--tr);padding-inline:3.1rem 3.2rem}
html[dir="rtl"] .input-wrap input{text-align:right}
.input-wrap input::placeholder{color:var(--faint)}
.input-wrap input:focus{border-color:var(--green);box-shadow:0 0 0 3px rgba(34,197,94,.12);background:#0d1a2e}
.eye-btn{position:absolute;inset-inline-end:.9rem;background:none;border:none;cursor:pointer;color:var(--faint);padding:0;display:flex;transition:color var(--tr)}
.eye-btn:hover{color:var(--muted)}

.extras{display:flex;justify-content:space-between;align-items:center;margin:.2rem 0 1.35rem;font-size:.8rem;flex-wrap:wrap;gap:.5rem}
.checkbox-label{display:flex;align-items:center;gap:.45rem;cursor:pointer;color:var(--muted);font-weight:500;user-select:none}
.checkbox-label input[type=checkbox]{width:16px;height:16px;accent-color:var(--green);cursor:pointer}
.forgot-link{text-decoration:none;color:var(--green);font-weight:700;transition:color var(--tr)}
.forgot-link:hover{color:var(--green-2)}

.btn-submit{width:100%;height:56px;border:none;cursor:pointer;border-radius:var(--radius);font-size:1.05rem;font-weight:800;
  color:#fff;font-family:inherit;position:relative;overflow:hidden;
  background:linear-gradient(90deg,#16a34a,#22c55e 42%,#9333ea);background-size:200% 100%;background-position:right center;
  transition:background-position .5s,transform var(--tr),box-shadow var(--tr),opacity var(--tr);
  box-shadow:0 6px 28px rgba(34,197,94,.28),0 1px 6px rgba(0,0,0,.4)}
.btn-submit::before{content:'';position:absolute;inset:0;background:linear-gradient(180deg,rgba(255,255,255,.12),transparent);pointer-events:none}
.btn-submit:hover:not(:disabled){background-position:left center;transform:translateY(-2px);
  box-shadow:0 8px 36px rgba(34,197,94,.38),0 8px 36px rgba(147,51,234,.24)}
.btn-submit:active:not(:disabled){transform:translateY(0) scale(.985)}
.btn-submit:disabled{opacity:.7;cursor:progress}
.btn-inner{display:flex;align-items:center;justify-content:center;gap:.5rem}
.btn-spinner{display:none;width:20px;height:20px;border:2.5px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .65s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.btn-submit.loading .btn-text{display:none}.btn-submit.loading .btn-spinner{display:block}

.card-footer{margin-top:1.35rem;text-align:center;color:var(--faint);font-size:.72rem;line-height:1.7}

:focus-visible{outline:2px solid var(--green);outline-offset:2px}

@media (max-width:480px){
  html{font-size:15px}
  .card{padding:2.3rem 1.25rem 1.4rem;border-radius:22px}
  .robot{width:104px;height:104px}.robot-wrap{margin-bottom:-24px}
  .orb{filter:blur(70px)}
}
@media (max-height:720px){
  .card{padding:2.1rem 1.9rem 1.3rem}
  .robot{width:104px;height:104px}.robot-wrap{margin-bottom:-24px}
  .lang-bar{margin-bottom:1rem}.logo{margin-bottom:.8rem}.welcome-block{margin-bottom:1rem}
  .form-group{margin-bottom:.8rem}.input-wrap input{height:48px}.extras{margin:.1rem 0 1rem}
  .btn-submit{height:52px}.card-footer{margin-top:1rem}
}
@media (max-height:600px){
  body{align-items:flex-start}
  .subtitle,.welcome-block p{display:none}
}
@media (prefers-reduced-motion:reduce){*{animation-duration:.001ms!important;transition-duration:.05ms!important}}
</style>
</head>
<body>

<canvas id="stars" class="layer" aria-hidden="true"></canvas>
<div class="grid layer" aria-hidden="true" id="grid"></div>
<div class="orb orb-g" aria-hidden="true" id="o1"></div>
<div class="orb orb-p" aria-hidden="true" id="o2"></div>
<div class="orb orb-c" aria-hidden="true"></div>

<div class="stage">
  <div class="tilt" id="tilt">

    <div class="robot-wrap">
      <div class="robot wave" id="robot">
        <div class="bubble" id="bubble"></div>
        <div class="speed" aria-hidden="true"><b></b><b></b><b></b></div>
        <div class="stack" id="stack">

          <!-- ROBOT -->
          <svg class="botsvg" viewBox="0 0 200 200" aria-hidden="true">
            <defs>
              <linearGradient id="body" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="#1e293b"/><stop offset="1" stop-color="#0b1220"/>
              </linearGradient>
              <linearGradient id="face" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stop-color="#0f1c33"/><stop offset="1" stop-color="#0a1424"/>
              </linearGradient>
              <radialGradient id="eye" cx="50%" cy="45%" r="60%">
                <stop offset="0" stop-color="#a3e635"/><stop offset="55%" stop-color="#22c55e"/><stop offset="100%" stop-color="#15803d"/>
              </radialGradient>
            </defs>
            <line x1="100" y1="42" x2="100" y2="20" stroke="#334155" stroke-width="3"/>
            <circle class="antenna-tip" cx="100" cy="16" r="5" fill="#22c55e"/>
            <g class="arm-r" style="transform-origin:150px 118px">
              <rect x="146" y="112" width="30" height="12" rx="6" fill="#1e293b" stroke="#334155" stroke-width="1.5"/>
            </g>
            <rect x="24" y="112" width="30" height="12" rx="6" fill="#1e293b" stroke="#334155" stroke-width="1.5"/>
            <rect x="58" y="118" width="84" height="60" rx="18" fill="url(#body)" stroke="#334155" stroke-width="1.5"/>
            <circle cx="82" cy="150" r="4" fill="#22c55e" opacity=".8"/>
            <circle cx="100" cy="150" r="4" fill="#9333ea" opacity=".8"/>
            <circle cx="118" cy="150" r="4" fill="#22c55e" opacity=".8"/>
            <rect x="52" y="46" width="96" height="76" rx="26" fill="url(#body)" stroke="#3b4a63" stroke-width="1.6"/>
            <rect x="62" y="56" width="76" height="56" rx="19" fill="url(#face)" stroke="#22304a" stroke-width="1.2"/>
            <g><circle cx="86" cy="84" r="12" fill="#050b16"/>
               <circle class="pupil" data-eye cx="86" cy="84" r="7.5" fill="url(#eye)"/>
               <circle class="pupil" data-eye cx="83" cy="81" r="2.4" fill="#f0fdf4" opacity=".9"/></g>
            <g><circle cx="114" cy="84" r="12" fill="#050b16"/>
               <circle class="pupil" data-eye cx="114" cy="84" r="7.5" fill="url(#eye)"/>
               <circle class="pupil" data-eye cx="111" cy="81" r="2.4" fill="#f0fdf4" opacity=".9"/></g>
            <path d="M88 102 Q100 110 112 102" stroke="#22c55e" stroke-width="3" fill="none" stroke-linecap="round"/>
            <g class="hand hand-l"><circle cx="60" cy="150" r="13" fill="#1e293b" stroke="#3b4a63" stroke-width="1.5"/></g>
            <g class="hand hand-r"><circle cx="140" cy="150" r="13" fill="#1e293b" stroke="#3b4a63" stroke-width="1.5"/></g>
          </svg>

          <!-- CAR -->
          <svg class="carsvg" viewBox="0 0 200 200" aria-hidden="true">
            <defs>
              <linearGradient id="carbody" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="#334155"/><stop offset="1" stop-color="#0f172a"/>
              </linearGradient>
              <linearGradient id="carstripe" x1="0" y1="0" x2="1" y2="0">
                <stop offset="0" stop-color="#22c55e"/><stop offset="1" stop-color="#9333ea"/>
              </linearGradient>
            </defs>
            <ellipse cx="104" cy="174" rx="72" ry="7" fill="#000" opacity=".35"/>
            <path d="M40 150 L40 133 Q42 124 60 121 L84 106 Q90 102 100 102 L120 102 Q132 103 140 120 L168 126 Q176 129 178 140 Q179 149 170 151 Z"
                  fill="url(#carbody)" stroke="#3b4a63" stroke-width="1.6" stroke-linejoin="round"/>
            <path d="M74 121 L92 108 Q97 105 104 105 L118 105 Q126 106 132 120 Z" fill="#0a1424" stroke="#22304a" stroke-width="1.2"/>
            <path d="M104 106 L104 120" stroke="#22304a" stroke-width="1.4"/>
            <path d="M44 138 L166 132" stroke="url(#carstripe)" stroke-width="3.5" fill="none" stroke-linecap="round" opacity=".9"/>
            <circle cx="171" cy="138" r="9" fill="#22c55e" opacity=".3"/>
            <circle cx="171" cy="138" r="4.5" fill="#a3e635"/>
            <rect x="39" y="134" width="4" height="8" rx="2" fill="#a855f7"/>
            <g class="wheel">
              <circle cx="72" cy="152" r="18" fill="#0b1220" stroke="#334155" stroke-width="3"/>
              <circle cx="72" cy="152" r="7" fill="#1e293b" stroke="#22c55e" stroke-width="1.5"/>
              <path d="M72 138 L72 166 M58 152 L86 152 M62 142 L82 162 M82 142 L62 162" stroke="#475569" stroke-width="2"/>
            </g>
            <g class="wheel">
              <circle cx="150" cy="152" r="18" fill="#0b1220" stroke="#334155" stroke-width="3"/>
              <circle cx="150" cy="152" r="7" fill="#1e293b" stroke="#22c55e" stroke-width="1.5"/>
              <path d="M150 138 L150 166 M136 152 L164 152 M140 142 L160 162 M160 142 L140 162" stroke="#475569" stroke-width="2"/>
            </g>
          </svg>

        </div><!-- /stack -->
      </div>
    </div>

    <main class="card" role="main">

      <nav class="lang-bar" aria-label="Language">
        <a href="?lang=ar" class="<?= $lang === 'ar' ? 'active' : '' ?>"><?= $T['lang_ar'] ?></a>
        <a href="?lang=en" class="<?= $lang === 'en' ? 'active' : '' ?>"><?= $T['lang_en'] ?></a>
      </nav>

      <div class="logo">
        <div class="logo-img-wrap">
          <img src="logo.png" alt="First 1 Car"
               onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
          <div class="logo-text-mark" style="display:none;">
            <span style="color:var(--green)">F</span><span style="color:var(--purple)">1</span>
          </div>
        </div>
        <div class="brand-name"><span class="g">First </span><span class="p">1 </span><span class="g">Car</span></div>
        <div class="subtitle"><?= htmlspecialchars($T['subtitle']) ?></div>
      </div>

      <div class="welcome-block">
        <h2><?= htmlspecialchars($T['welcome']) ?></h2>
        <p><?= htmlspecialchars($T['title']) ?></p>
      </div>

      <?php if (isset($_SESSION['error'])): ?>
      <div class="error-box" role="alert">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <?= htmlspecialchars($_SESSION['error']) ?>
      </div>
      <?php unset($_SESSION['error']); endif; ?>

      <form action="login.php" method="POST" id="login-form" novalidate>
        <div class="form-group">
          <label for="username"><?= htmlspecialchars($T['username']) ?></label>
          <div class="input-wrap">
            <span class="input-icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
              </svg>
            </span>
            <input type="text" id="username" name="username" autocomplete="username" spellcheck="false" required
                   placeholder="<?= $lang === 'ar' ? 'اسم_المستخدم' : 'your_username' ?>">
          </div>
        </div>

        <div class="form-group">
          <label for="password"><?= htmlspecialchars($T['password']) ?></label>
          <div class="input-wrap">
            <span class="input-icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
              </svg>
            </span>
            <input type="password" id="password" name="password" autocomplete="current-password" required placeholder="••••••••">
            <button type="button" class="eye-btn" id="eye-btn" aria-label="<?= htmlspecialchars($T['show_pw']) ?>">
              <svg id="eye-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
              </svg>
            </button>
          </div>
        </div>

        <div class="extras">
          <label class="checkbox-label">
            <input type="checkbox" name="remember"><?= htmlspecialchars($T['remember']) ?>
          </label>
          <a href="forgot_password.php" class="forgot-link"><?= htmlspecialchars($T['forgot']) ?></a>
        </div>

        <button type="submit" class="btn-submit" id="submit-btn">
          <div class="btn-inner">
            <svg class="btn-text" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            <span class="btn-text"><?= htmlspecialchars($T['login']) ?></span>
            <div class="btn-spinner" aria-hidden="true"></div>
          </div>
        </button>
      </form>

      <div class="card-footer">
        <?= $T['copy'] ?> <?= date('Y') ?> · First 1 Car<br>
        <?= htmlspecialchars($T['system']) ?>
      </div>

    </main>
  </div>
</div>

<script>
const LANG = <?= json_encode($lang) ?>;
const MSG = {
  hi:   LANG==='ar' ? 'أهلاً 👋'            : 'Hi there 👋',
  car:  LANG==='ar' ? 'بتحوّل لعربية! 🚗'   : 'I turn into a car! 🚗',
  look: LANG==='ar' ? 'اكتب اسمك...'        : 'Type your name...',
  shy:  LANG==='ar' ? 'مش هبصّ! 🙈'         : "Not looking! 🙈",
  bye:  LANG==='ar' ? 'يلا بينا! 🚀'        : "Let's go! 🚀"
};

/* ---------- mascot bubble ---------- */
const robot=document.getElementById('robot'), bubble=document.getElementById('bubble'), stack=document.getElementById('stack');
let bubbleTimer;
function say(msg,ms){bubble.textContent=msg;bubble.classList.add('show');
  clearTimeout(bubbleTimer);if(ms)bubbleTimer=setTimeout(()=>bubble.classList.remove('show'),ms);}
setTimeout(()=>say(MSG.hi,1900),400);
setTimeout(()=>robot.classList.remove('wave'),1700);
setTimeout(()=>say(MSG.car,2400),2600);

/* ---------- transform robot <-> car ---------- */
let isCar=false,tfLock=false;
function transformTo(car){
  if(tfLock)return;tfLock=true;
  stack.classList.remove('tf');void stack.offsetWidth;stack.classList.add('tf');
  setTimeout(()=>{robot.classList.toggle('is-car',car);isCar=car;},360);
  setTimeout(()=>{tfLock=false;},720);
}
robot.addEventListener('click',()=>{
  if(robot.classList.contains('driving')||tfLock)return;
  transformTo(!isCar);
});
/* auto-transform every 3s — pauses while typing or driving */
setInterval(()=>{
  if(tfLock||robot.classList.contains('driving'))return;
  if(document.activeElement===u||document.activeElement===p)return;
  transformTo(!isCar);
},3000);

/* ---------- eye tracking ---------- */
const pupils=[...document.querySelectorAll('[data-eye]')];
const base=pupils.map(p=>({el:p}));
let mx=.5,my=.4,shy=false;
addEventListener('pointermove',e=>{mx=e.clientX/innerWidth;my=e.clientY/innerHeight;});
(function loop(){
  if(!shy&&!isCar){const dx=(mx-.5)*7, dy=(my-.45)*5;
    base.forEach(b=>b.el.setAttribute('transform',`translate(${dx.toFixed(2)},${dy.toFixed(2)})`));}
  requestAnimationFrame(loop);
})();

/* ---------- 3D tilt + parallax ---------- */
const tilt=document.getElementById('tilt'),grid=document.getElementById('grid'),
      o1=document.getElementById('o1'),o2=document.getElementById('o2');
let tx=0,ty=0;
addEventListener('pointermove',e=>{
  const gx=(e.clientX/innerWidth-.5), gy=(e.clientY/innerHeight-.5);
  tx=gx;ty=gy;
  tilt.style.transform=`rotateY(${(gx*10).toFixed(2)}deg) rotateX(${(-gy*10).toFixed(2)}deg)`;
  grid.style.transform=`translate(${gx*-26}px,${gy*-26}px)`;
  o1.style.transform=`translate(${gx*34}px,${gy*34}px)`;
  o2.style.transform=`translate(${gx*-40}px,${gy*-40}px)`;
});
addEventListener('pointerleave',()=>{tilt.style.transform='rotateY(0) rotateX(0)';});

/* ---------- form interactions ---------- */
const u=document.getElementById('username'),p=document.getElementById('password');
u.addEventListener('focus',()=>{if(isCar)transformTo(false);shy=false;robot.classList.remove('shy');
  base.forEach(b=>b.el.setAttribute('transform','translate(0,4)'));say(MSG.look,0);});
u.addEventListener('blur',()=>{if(document.activeElement!==p)bubble.classList.remove('show');});
p.addEventListener('focus',()=>{if(isCar)transformTo(false);shy=true;robot.classList.add('shy');say(MSG.shy,0);});
p.addEventListener('blur',()=>{shy=false;robot.classList.remove('shy');bubble.classList.remove('show');});

/* password visibility toggle (unchanged behaviour) */
const eyeBtn=document.getElementById('eye-btn'),pwInput=document.getElementById('password'),eyeIcon=document.getElementById('eye-icon');
const EYE_OPEN=`<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
const EYE_CLOSED=`<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M1 1l22 22"/>`;
eyeBtn.addEventListener('click',()=>{const s=pwInput.type==='password';pwInput.type=s?'text':'password';
  eyeIcon.innerHTML=s?EYE_CLOSED:EYE_OPEN;
  eyeBtn.setAttribute('aria-label',s?<?= json_encode($T['hide_pw']) ?>:<?= json_encode($T['show_pw']) ?>);});

/* submit — transform into a car and drive off, then really submit */
document.getElementById('login-form').addEventListener('submit',()=>{
  const btn=document.getElementById('submit-btn');
  btn.classList.add('loading');btn.disabled=true;
  shy=false;robot.classList.remove('shy','wave');
  say(MSG.bye,0);
  transformTo(true);
  setTimeout(()=>{robot.classList.add('driving');burst();},560);
  /* the real POST proceeds natively — no preventDefault */
});

/* ---------- confetti + starfield ---------- */
let confetti=[];
function burst(){const ox=innerWidth/2,oy=innerHeight/2,cols=['#22c55e','#4ade80','#9333ea','#a855f7','#a3e635'];
  for(let i=0;i<60;i++){const a=Math.random()*6.28,s=2+Math.random()*6;
    confetti.push({x:ox,y:oy,vx:Math.cos(a)*s,vy:Math.sin(a)*s-3,life:1,col:cols[i%cols.length]});}}
const cv=document.getElementById('stars'),cx=cv.getContext('2d');
let stars=[];
function resize(){cv.width=innerWidth;cv.height=innerHeight;
  stars=Array.from({length:Math.min(140,Math.round(innerWidth/10))},()=>({
    x:Math.random()*cv.width,y:Math.random()*cv.height,z:Math.random(),r:Math.random()*1.4+.3}));}
resize();addEventListener('resize',resize);
const reduce=matchMedia('(prefers-reduced-motion:reduce)').matches;
(function draw(){
  cx.clearRect(0,0,cv.width,cv.height);
  for(const s of stars){
    if(!reduce){s.y+=s.z*.22;if(s.y>cv.height){s.y=0;s.x=Math.random()*cv.width;}}
    const px=s.x+tx*s.z*34, py=s.y+ty*s.z*34;
    cx.globalAlpha=.3+s.z*.5;
    cx.fillStyle=s.z>.7?'#4ade80':(s.z>.4?'#a855f7':'#e2e8f0');
    cx.beginPath();cx.arc(px,py,s.r,0,6.28);cx.fill();
  }
  if(confetti.length){
    confetti.forEach(pt=>{pt.x+=pt.vx;pt.y+=pt.vy;pt.vy+=.14;pt.life-=.017;
      cx.globalAlpha=Math.max(pt.life,0);cx.fillStyle=pt.col;cx.fillRect(pt.x,pt.y,4,4);});
    confetti=confetti.filter(pt=>pt.life>0);
  }
  cx.globalAlpha=1;
  requestAnimationFrame(draw);
})();
</script>
</body>
</html>
