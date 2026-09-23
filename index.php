<?php
require_once __DIR__ . '/auth_remember.php';
f1c_session_start();

$lang = $_GET['lang'] ?? 'ar';
$lang = in_array($lang, ['ar','en']) ? $lang : 'ar';
$dir  = $lang === 'ar' ? 'rtl' : 'ltr';
$next = f1c_safe_next($_GET['next'] ?? '');

/* Already signed in (or a remembered device) → straight in */
if (!isset($_SESSION['user_id']) && !empty($_COOKIE[F1C_REMEMBER_COOKIE])) {
    try { require_once __DIR__ . '/config.php'; f1c_remember_login($pdo); } catch (Throwable $e) {}
}
if (isset($_SESSION['user_id'])) {
    header('Location: ' . ($next !== '' ? $next : 'dashboard.php?lang=' . $lang));
    exit;
}

/* Form token for the login POST */
if (empty($_SESSION['login_csrf'])) $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['login_csrf'];

/* What the last attempt said (set by login.php) */
$err       = $_SESSION['login_err']  ?? null;
$typedUser = $_SESSION['login_user'] ?? '';
unset($_SESSION['login_err'], $_SESSION['login_user']);
$lastUser  = preg_replace('/[^\p{L}\p{N}_.\-@ ]/u', '', (string)($_COOKIE[F1C_LASTUSER_COOKIE] ?? ''));
$lastUser  = mb_substr($lastUser, 0, 60);
$prefill   = $typedUser !== '' ? $typedUser : $lastUser;
$disabled  = isset($_GET['disabled']);

$t = [
    'ar' => [
        'title'    => 'تسجيل الدخول',
        'subtitle' => 'نظام إدارة مخزون First 1 Car',
        'username' => 'اسم المستخدم',
        'password' => 'كلمة المرور',
        'remember' => 'تذكرني على هذا الجهاز',
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
        'morning'  => 'صباح الخير',
        'evening'  => 'مساء الخير',
        'hello'    => 'مرحباً',
        'not_you'  => 'مستخدم آخر؟',
        'caps'     => 'زر Caps Lock مفعّل',
        'offline'  => 'لا يوجد اتصال بالإنترنت — تأكد من الشبكة ثم حاول مرة أخرى',
        'e_bad'    => 'اسم المستخدم أو كلمة المرور غير صحيحة',
        'e_left'   => 'متبقي %d محاولات قبل القفل المؤقت',
        'e_left1'  => 'متبقية محاولة واحدة قبل القفل المؤقت',
        'e_locked' => 'تم إيقاف المحاولات مؤقتاً بسبب كثرة الأخطاء',
        'e_wait'   => 'حاول مرة أخرى بعد',
        'e_open'   => 'يمكنك المحاولة الآن',
        'e_expired'=> 'انتهت صلاحية الصفحة — حاول مرة أخرى',
        'e_empty'  => 'اكتب اسم المستخدم وكلمة المرور',
        'e_disabled'=> 'تم إيقاف هذا الحساب — تواصل مع المدير',
        'next_note'=> 'سجّل الدخول للمتابعة إلى الصفحة التي فتحتها',
        'forgot_t' => 'نسيت كلمة المرور؟',
        'forgot_b' => 'لأمان النظام لا يمكن استعادة كلمة المرور من هنا. تواصل مع مدير النظام وسيعيد تعيينها لك من صفحة المستخدمين خلال دقيقة.',
        'ok'       => 'حسناً',
        'tag1'     => 'المخزون لحظة بلحظة',
        'tag2'     => 'الأسعار والعروض',
        'tag3'     => 'رحلة كل سيارة',
        'tag4'     => 'تقارير الفروع',
        'hero_t'   => 'كل سيارة. كل فرع.',
        'hero_t2'  => 'في مكان واحد.',
        'secure'   => 'اتصال آمن',
    ],
    'en' => [
        'title'    => 'Sign In',
        'subtitle' => 'First 1 Car Inventory System',
        'username' => 'Username',
        'password' => 'Password',
        'remember' => 'Remember me on this device',
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
        'morning'  => 'Good morning',
        'evening'  => 'Good evening',
        'hello'    => 'Hello',
        'not_you'  => 'Not you?',
        'caps'     => 'Caps Lock is on',
        'offline'  => 'No internet connection — check the network and try again',
        'e_bad'    => 'Invalid username or password',
        'e_left'   => '%d attempts left before a temporary lock',
        'e_left1'  => '1 attempt left before a temporary lock',
        'e_locked' => 'Sign-in is paused after too many failed attempts',
        'e_wait'   => 'Try again in',
        'e_open'   => 'You can try again now',
        'e_expired'=> 'This page expired — please try again',
        'e_empty'  => 'Enter your username and password',
        'e_disabled'=> 'This account has been disabled — contact the administrator',
        'next_note'=> 'Sign in to continue to the page you opened',
        'forgot_t' => 'Forgot your password?',
        'forgot_b' => 'For security, passwords cannot be recovered here. Ask the system administrator — they can reset it for you from the Users page in a minute.',
        'ok'       => 'OK',
        'tag1'     => 'Live inventory',
        'tag2'     => 'Prices & offers',
        'tag3'     => 'Every car\'s journey',
        'tag4'     => 'Branch reports',
        'hero_t'   => 'Every car. Every branch.',
        'hero_t2'  => 'One place.',
        'secure'   => 'Secure connection',
    ],
];
$T = $t[$lang];

/* the message box (one language, no raw HTML) */
$msg = null;
if ($disabled) {
    $msg = ['kind' => 'warn', 'text' => $T['e_disabled']];
} elseif ($err) {
    switch ($err['code'] ?? '') {
        case 'bad':
            $left = (int)($err['left'] ?? 0);
            $msg = ['kind' => 'err', 'text' => $T['e_bad'], 'sub' => $left > 0 && $left < 5 ? ($left === 1 ? $T['e_left1'] : sprintf($T['e_left'], $left)) : ''];
            break;
        case 'locked':
            $msg = ['kind' => 'lock', 'text' => $T['e_locked'], 'until' => (int)($err['until'] ?? 0)];
            break;
        case 'expired': $msg = ['kind' => 'warn', 'text' => $T['e_expired']]; break;
        case 'empty':   $msg = ['kind' => 'warn', 'text' => $T['e_empty']];   break;
    }
}
$lockSecs = ($msg && $msg['kind'] === 'lock') ? max(0, $msg['until'] - time()) : 0;
$qsNext   = $next !== '' ? '&next=' . urlencode($next) : '';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<?php include __DIR__ . '/pwa_head.php'; ?>
<title>First 1 Car – <?= $T['title'] ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Tajawal:wght@300;400;500;700;800;900&display=swap" rel="stylesheet">
<style>
/* ============================================================
   First 1 Car · Login — "night showroom"
   Aurora sky · neon road · glowing sports car · robot↔car mascot
   ============================================================ */
:root{
  --green:#22c55e; --green-2:#4ade80; --lime:#a3e635; --purple:#9333ea; --purple-2:#a855f7; --cyan:#22d3ee;
  --bg:#020617; --surface:rgba(10,17,35,.72); --surface2:#0b1426;
  --border:rgba(255,255,255,.08); --border-hi:rgba(255,255,255,.16);
  --text:#f1f5f9; --muted:#94a3b8; --faint:#475569; --danger:#ef4444; --amber:#f59e0b;
  --radius:20px; --radius-sm:14px;
  --font-en:'Inter',sans-serif; --font-ar:'Tajawal',sans-serif;
  --tr:.28s cubic-bezier(.4,0,.2,1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:16px}
body{
  min-height:100vh;min-height:100dvh;background:var(--bg);color:var(--text);
  font-family:<?= $lang === 'ar' ? "var(--font-ar)" : "var(--font-en)" ?>;
  -webkit-font-smoothing:antialiased;overflow-x:hidden;position:relative;
}

/* ── sky: aurora + stars + grid ── */
.layer{position:fixed;inset:0;pointer-events:none}
#stars{z-index:0}
.aurora{z-index:0;overflow:hidden;filter:blur(60px) saturate(1.3);opacity:.85}
.aurora i{position:absolute;border-radius:50%;mix-blend-mode:screen;will-change:transform}
.aurora i:nth-child(1){width:60vmax;height:60vmax;left:-18vmax;top:-26vmax;background:radial-gradient(circle,rgba(34,197,94,.34),transparent 62%);animation:au1 18s ease-in-out infinite alternate}
.aurora i:nth-child(2){width:62vmax;height:62vmax;right:-22vmax;top:-10vmax;background:radial-gradient(circle,rgba(147,51,234,.36),transparent 62%);animation:au2 22s ease-in-out infinite alternate}
.aurora i:nth-child(3){width:46vmax;height:46vmax;left:30%;bottom:-24vmax;background:radial-gradient(circle,rgba(34,211,238,.18),transparent 62%);animation:au3 26s ease-in-out infinite alternate}
@keyframes au1{to{transform:translate(12vmax,10vmax) scale(1.15)}}
@keyframes au2{to{transform:translate(-14vmax,12vmax) scale(.9)}}
@keyframes au3{to{transform:translate(-10vmax,-8vmax) scale(1.2)}}
.grid{z-index:1;
  background-image:linear-gradient(rgba(148,163,184,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(148,163,184,.045) 1px,transparent 1px);
  background-size:56px 56px;
  -webkit-mask-image:radial-gradient(circle at 50% 40%,#000 0%,transparent 75%);mask-image:radial-gradient(circle at 50% 40%,#000 0%,transparent 75%);
  will-change:transform}
/* neon road floor running toward the viewer */
.floor{position:fixed;left:-50%;right:-50%;bottom:0;height:42vh;z-index:1;pointer-events:none;
  perspective:420px;-webkit-mask-image:linear-gradient(to top,#000 30%,transparent 100%);mask-image:linear-gradient(to top,#000 30%,transparent 100%)}
.floor::before{content:'';position:absolute;inset:0;transform:rotateX(62deg);transform-origin:50% 100%;
  background-image:linear-gradient(rgba(168,85,247,.55) 2px,transparent 2px),linear-gradient(90deg,rgba(34,197,94,.45) 2px,transparent 2px);
  background-size:80px 80px;animation:roadrun 1.6s linear infinite;filter:drop-shadow(0 0 6px rgba(168,85,247,.6))}
@keyframes roadrun{to{background-position:0 80px}}
.horizon{position:fixed;left:0;right:0;bottom:42vh;height:2px;z-index:1;pointer-events:none;
  background:linear-gradient(90deg,transparent,rgba(34,197,94,.7),rgba(168,85,247,.8),transparent);box-shadow:0 0 30px 6px rgba(147,51,234,.35);opacity:.7}

/* ── layout ── */
.shell{position:relative;z-index:5;min-height:100vh;min-height:100dvh;display:grid;grid-template-columns:1.15fr minmax(380px,460px);
  align-items:center;gap:clamp(24px,5vw,80px);max-width:1280px;margin:0 auto;padding:28px clamp(18px,4vw,56px)}

/* ── hero (showroom side) ── */
.hero{position:relative;display:flex;flex-direction:column;gap:22px;min-width:0}
.hero-top{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.hero-logo{display:inline-flex;align-items:center;gap:10px;padding:8px 14px 8px 8px;border-radius:999px;background:rgba(255,255,255,.04);border:1px solid var(--border);backdrop-filter:blur(10px)}
html[dir=rtl] .hero-logo{padding:8px 8px 8px 14px}
.hero-logo .mk{width:34px;height:34px;border-radius:50%;display:grid;place-items:center;background:linear-gradient(135deg,var(--green),var(--purple));font-weight:900;font-size:.9rem;box-shadow:0 0 18px rgba(34,197,94,.4)}
.hero-logo span{font-weight:800;font-size:.9rem;letter-spacing:.02em}
.secure{display:inline-flex;align-items:center;gap:6px;font-size:.74rem;font-weight:700;color:var(--green-2);padding:6px 12px;border-radius:999px;background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.22)}
.secure i{width:7px;height:7px;border-radius:50%;background:var(--green);box-shadow:0 0 10px var(--green);animation:blink 2s infinite}
@keyframes blink{50%{opacity:.35}}
.wordmark{font-size:clamp(3rem,7.2vw,6.2rem);font-weight:900;line-height:.95;letter-spacing:-.045em;direction:ltr;text-align:start}
html[dir=rtl] .wordmark{text-align:right}
.wordmark span{background:linear-gradient(100deg,#fff 0%,#d9f99d 18%,var(--green) 36%,var(--cyan) 52%,var(--purple-2) 70%,#fff 90%);background-size:220% 100%;
  -webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;animation:shine 7s linear infinite;
  filter:drop-shadow(0 6px 30px rgba(34,197,94,.25))}
@keyframes shine{to{background-position:-220% 0}}
.hero-line{font-size:clamp(1.15rem,2.1vw,1.7rem);font-weight:800;line-height:1.35;color:#e2e8f0}
.hero-line b{color:var(--green-2);font-weight:900}
.clock{display:flex;align-items:baseline;gap:14px;flex-wrap:wrap}
.clock .tm{font-size:clamp(2rem,3.6vw,3rem);font-weight:900;font-variant-numeric:tabular-nums;letter-spacing:-.02em;direction:ltr}
.clock .tm small{font-size:.45em;color:var(--muted);margin-inline-start:6px;font-weight:700}
.clock .dt{font-size:.95rem;color:var(--muted);font-weight:600}
.tags{display:flex;flex-wrap:wrap;gap:8px}
.tags span{display:inline-flex;align-items:center;gap:8px;font-size:.8rem;font-weight:700;padding:8px 14px;border-radius:12px;background:rgba(255,255,255,.035);border:1px solid var(--border);
  backdrop-filter:blur(8px);animation:rise .6s cubic-bezier(.22,1,.36,1) both}
.tags span:nth-child(2){animation-delay:.08s}.tags span:nth-child(3){animation-delay:.16s}.tags span:nth-child(4){animation-delay:.24s}
@keyframes rise{from{opacity:0;transform:translateY(12px)}}

/* the showroom car */
.showcar{position:relative;height:clamp(150px,19vw,230px);margin-top:4px}
.showcar .beam{position:absolute;top:38%;inset-inline-end:-6%;width:46%;height:48%;
  background:linear-gradient(90deg,rgba(254,240,138,.28),transparent);clip-path:polygon(0 38%,100% 0,100% 100%,0 62%);filter:blur(6px);animation:beam 3.2s ease-in-out infinite}
html[dir=rtl] .showcar .beam{background:linear-gradient(270deg,rgba(254,240,138,.28),transparent);clip-path:polygon(0 0,100% 38%,100% 62%,0 100%)}
@keyframes beam{50%{opacity:.55}}
.showcar svg{position:absolute;inset:0;width:100%;height:100%;overflow:visible;animation:cruise 3.2s ease-in-out infinite}
html[dir=rtl] .showcar svg{transform:scaleX(-1)}
html[dir=rtl] .showcar svg{animation-name:cruiseR}
@keyframes cruise{50%{transform:translateY(-3px)}}
@keyframes cruiseR{0%,100%{transform:scaleX(-1)}50%{transform:scaleX(-1) translateY(-3px)}}
.showcar .wh{transform-box:fill-box;transform-origin:center;animation:roll .5s linear infinite}
.showcar .glow{position:absolute;left:12%;right:12%;bottom:6%;height:22%;border-radius:50%;background:radial-gradient(ellipse,rgba(34,197,94,.55),rgba(147,51,234,.35) 45%,transparent 70%);filter:blur(14px);animation:under 2.4s ease-in-out infinite}
@keyframes under{50%{opacity:.6;transform:scaleX(.94)}}
.showcar .lines{position:absolute;left:0;right:0;bottom:9%;height:4px;overflow:hidden;-webkit-mask-image:linear-gradient(90deg,transparent,#000 20%,#000 80%,transparent);mask-image:linear-gradient(90deg,transparent,#000 20%,#000 80%,transparent)}
.showcar .lines::before{content:'';position:absolute;inset:0;width:200%;background:repeating-linear-gradient(90deg,rgba(255,255,255,.55) 0 40px,transparent 40px 90px);animation:lanes .6s linear infinite}
html[dir=rtl] .showcar .lines::before{animation-name:lanesR}
@keyframes lanes{to{transform:translateX(-90px)}}
@keyframes lanesR{from{transform:translateX(-90px)}to{transform:translateX(0)}}

/* ── card side ── */
.stage{position:relative;perspective:1400px;width:100%}
.tilt{transform-style:preserve-3d;transition:transform .18s ease-out;will-change:transform}

/* mascot (unchanged look) */
.robot-wrap{display:flex;justify-content:center;margin-bottom:-30px;transform:translateZ(60px);position:relative;z-index:6}
.robot{width:120px;height:120px;position:relative;animation:float 4.2s ease-in-out infinite;cursor:pointer}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-9px)}}
.stack{position:absolute;inset:0;transform-style:preserve-3d;will-change:transform}
.stack svg{position:absolute;inset:0;width:100%;height:100%;overflow:visible;
  filter:drop-shadow(0 14px 22px rgba(0,0,0,.55)) drop-shadow(0 0 18px rgba(34,197,94,.22));transition:opacity .18s ease}
.carsvg{opacity:0;pointer-events:none}
.robot.is-car .botsvg{opacity:0}.robot.is-car .carsvg{opacity:1}
.stack.tf{animation:transform .72s cubic-bezier(.5,0,.5,1)}
@keyframes transform{0%{transform:rotateY(0) scale(1)}45%{transform:rotateY(160deg) scale(.55,1.18)}55%{transform:rotateY(200deg) scale(.55,1.18)}100%{transform:rotateY(360deg) scale(1)}}
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
.speed b:nth-child(1){top:-10px;width:40px}.speed b:nth-child(2){top:0;width:60px;background:linear-gradient(90deg,transparent,var(--purple))}.speed b:nth-child(3){top:10px;width:34px}
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
.bubble{position:absolute;top:-4px;left:50%;transform:translateX(-50%) scale(.9);background:linear-gradient(135deg,rgba(34,197,94,.16),rgba(147,51,234,.16));
  border:1px solid var(--border-hi);backdrop-filter:blur(8px);padding:.38rem .78rem;border-radius:12px;white-space:nowrap;font-size:.72rem;
  font-weight:700;color:var(--text);opacity:0;pointer-events:none;transition:opacity .4s var(--tr),transform .4s var(--tr)}
.bubble.show{opacity:1;transform:translateX(-50%) scale(1)}
.bubble::after{content:'';position:absolute;bottom:-5px;left:50%;transform:translateX(-50%) rotate(45deg);width:9px;height:9px;background:rgba(147,51,234,.16);border-right:1px solid var(--border-hi);border-bottom:1px solid var(--border-hi)}

/* card with a rotating neon border */
.card{position:relative;border-radius:28px;padding:2.6rem 1.9rem 1.5rem;transform:translateZ(20px);isolation:isolate;
  background:var(--surface);backdrop-filter:blur(28px) saturate(1.5);-webkit-backdrop-filter:blur(28px) saturate(1.5);
  box-shadow:0 40px 100px rgba(0,0,0,.65),0 0 0 1px rgba(255,255,255,.04) inset,0 0 80px rgba(147,51,234,.12)}
.card::before{content:'';position:absolute;inset:-1.5px;border-radius:29.5px;z-index:-1;padding:1.5px;
  background:conic-gradient(from var(--ang),rgba(34,197,94,0) 0deg,var(--green) 60deg,var(--cyan) 110deg,var(--purple-2) 170deg,rgba(147,51,234,0) 230deg,rgba(34,197,94,0) 360deg);
  -webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);-webkit-mask-composite:xor;mask-composite:exclude;animation:spinring 6s linear infinite}
@property --ang{syntax:'<angle>';initial-value:0deg;inherits:false}
@keyframes spinring{to{--ang:360deg}}
.card::after{content:'';position:absolute;inset:0;border-radius:28px;pointer-events:none;
  background:linear-gradient(115deg,transparent 30%,rgba(255,255,255,.05) 48%,transparent 62%);background-size:250% 250%;animation:sheen 7s ease-in-out infinite}
@keyframes sheen{0%{background-position:120% 0}55%,100%{background-position:-40% 0}}

.lang-bar{display:flex;justify-content:center;gap:.4rem;margin-bottom:1.2rem;padding:4px;border-radius:12px;background:rgba(255,255,255,.03);border:1px solid var(--border);width:max-content;margin-inline:auto}
.lang-bar a{text-decoration:none;padding:.34rem 1rem;border-radius:9px;color:var(--muted);font-size:.8rem;font-weight:700;transition:var(--tr)}
.lang-bar a:hover{color:var(--text)}
.lang-bar a.active{background:linear-gradient(135deg,rgba(34,197,94,.22),rgba(147,51,234,.22));color:#fff;box-shadow:0 0 0 1px rgba(34,197,94,.4) inset}

.greet{text-align:center;margin-bottom:1.25rem}
.greet .hi{font-size:.85rem;font-weight:700;color:var(--muted);display:flex;align-items:center;justify-content:center;gap:6px}
.greet h2{font-size:1.55rem;font-weight:900;margin-top:.2rem;letter-spacing:-.01em;
  background:linear-gradient(90deg,var(--green-2),var(--lime) 45%,var(--purple-2));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.greet p{font-size:.8rem;color:var(--muted);margin-top:.25rem}

/* remembered user */
.me{display:flex;align-items:center;gap:.8rem;padding:.7rem .85rem;border-radius:16px;margin-bottom:1rem;
  background:linear-gradient(135deg,rgba(34,197,94,.1),rgba(147,51,234,.1));border:1px solid rgba(34,197,94,.25);animation:pop .4s ease both}
.me .av{width:42px;height:42px;border-radius:50%;flex-shrink:0;display:grid;place-items:center;font-weight:900;font-size:1.1rem;color:#fff;
  background:linear-gradient(135deg,var(--green),var(--purple));box-shadow:0 0 0 3px rgba(255,255,255,.06),0 0 18px rgba(34,197,94,.35)}
.me .nm{flex:1;min-width:0}
.me .nm small{display:block;font-size:.72rem;color:var(--muted);font-weight:600}
.me .nm b{display:block;font-size:1rem;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.me button{border:1px solid var(--border-hi);background:rgba(255,255,255,.04);color:var(--muted);border-radius:10px;height:32px;padding:0 .7rem;font:inherit;font-size:.74rem;font-weight:700;cursor:pointer;white-space:nowrap}
.me button:hover{color:var(--text)}
.has-me #userGroup{display:none}

/* messages */
.note{display:flex;align-items:flex-start;gap:.6rem;border-radius:var(--radius-sm);padding:.75rem .95rem;margin-bottom:1.1rem;font-size:.82rem;font-weight:700;animation:pop .35s ease both}
.note svg{flex-shrink:0;margin-top:1px}
.note small{display:block;font-weight:600;opacity:.85;margin-top:2px}
.note.err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#fca5a5;animation:pop .35s ease both,shake .45s .1s ease}
.note.warn{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);color:#fcd34d}
.note.info{background:rgba(34,211,238,.08);border:1px solid rgba(34,211,238,.25);color:#a5f3fc}
.note.lock{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fecaca;align-items:center}
.note.lock.open{background:rgba(34,197,94,.1);border-color:rgba(34,197,94,.35);color:#bbf7d0}
.ring{width:46px;height:46px;flex-shrink:0;position:relative}
.ring svg{width:100%;height:100%;transform:rotate(-90deg)}
.ring b{position:absolute;inset:0;display:grid;place-items:center;font-size:.68rem;font-weight:900;direction:ltr;font-variant-numeric:tabular-nums}
@keyframes pop{from{opacity:0;transform:scale(.96) translateY(-4px)}}
@keyframes shake{20%,60%{transform:translateX(-6px)}40%,80%{transform:translateX(6px)}}
.offline{display:none}
.is-offline .offline{display:flex}

/* fields with floating labels */
.form-group{margin-bottom:1rem;position:relative}
.input-wrap{position:relative;display:flex;align-items:center}
.input-icon{position:absolute;inset-inline-start:1rem;color:var(--faint);pointer-events:none;display:flex;transition:color var(--tr);z-index:2}
.input-wrap input{width:100%;height:58px;background:rgba(11,20,38,.85);border:1px solid var(--border);border-radius:16px;outline:none;color:var(--text);
  font-size:1rem;font-family:inherit;-webkit-appearance:none;padding:1.15rem 3.2rem .35rem 3.1rem;transition:border-color var(--tr),box-shadow var(--tr),background var(--tr)}
html[dir=rtl] .input-wrap input{padding:1.15rem 3.1rem .35rem 3.2rem;text-align:right}
.input-wrap input:focus{border-color:rgba(34,197,94,.7);box-shadow:0 0 0 4px rgba(34,197,94,.12),0 0 24px rgba(34,197,94,.12);background:#0c1b30}
.flabel{position:absolute;inset-inline-start:3.1rem;top:50%;transform:translateY(-50%);font-size:.92rem;color:var(--faint);font-weight:600;pointer-events:none;transition:all .22s cubic-bezier(.4,0,.2,1);z-index:2}
.input-wrap input:focus + .flabel,.input-wrap input:not(:placeholder-shown) + .flabel{top:.72rem;transform:none;font-size:.68rem;color:var(--green-2);font-weight:800;letter-spacing:.02em}
.input-wrap input::placeholder{color:transparent}
.form-group:focus-within .input-icon{color:var(--green)}
.eye-btn{position:absolute;inset-inline-end:.85rem;background:none;border:none;cursor:pointer;color:var(--faint);padding:6px;display:flex;border-radius:8px;transition:color var(--tr),background var(--tr);z-index:2}
.eye-btn:hover{color:var(--text);background:rgba(255,255,255,.05)}
.caps{display:none;align-items:center;gap:6px;margin-top:.45rem;font-size:.74rem;font-weight:800;color:#fcd34d}
.caps.on{display:flex;animation:pop .25s ease}
.caps kbd{font:inherit;font-size:.68rem;padding:1px 6px;border-radius:5px;border:1px solid rgba(252,211,77,.4);background:rgba(252,211,77,.08)}

.extras{display:flex;justify-content:space-between;align-items:center;margin:.25rem 0 1.3rem;font-size:.8rem;flex-wrap:wrap;gap:.6rem}
.switch{display:flex;align-items:center;gap:.55rem;cursor:pointer;color:var(--muted);font-weight:600;user-select:none}
.switch input{position:absolute;opacity:0;width:1px;height:1px}
.switch .sw{width:38px;height:22px;border-radius:999px;background:rgba(255,255,255,.1);position:relative;transition:var(--tr);flex-shrink:0}
.switch .sw::after{content:'';position:absolute;top:3px;inset-inline-start:3px;width:16px;height:16px;border-radius:50%;background:#cbd5e1;transition:var(--tr)}
.switch input:checked + .sw{background:linear-gradient(90deg,var(--green),var(--purple))}
.switch input:checked + .sw::after{inset-inline-start:19px;background:#fff}
.switch input:focus-visible + .sw{outline:2px solid var(--green);outline-offset:2px}
.switch:has(input:checked){color:var(--text)}
.forgot-link{background:none;border:0;font:inherit;cursor:pointer;color:var(--green-2);font-weight:800;transition:color var(--tr)}
.forgot-link:hover{color:var(--lime)}

.btn-submit{width:100%;height:60px;border:none;cursor:pointer;border-radius:18px;font-size:1.08rem;font-weight:900;color:#fff;font-family:inherit;position:relative;overflow:hidden;
  background:linear-gradient(90deg,#16a34a,#22c55e 38%,#06b6d4 62%,#9333ea);background-size:220% 100%;background-position:right center;
  transition:background-position .6s,transform var(--tr),box-shadow var(--tr),opacity var(--tr);
  box-shadow:0 10px 34px rgba(34,197,94,.3),0 0 0 1px rgba(255,255,255,.08) inset}
.btn-submit::before{content:'';position:absolute;inset:0;background:linear-gradient(180deg,rgba(255,255,255,.16),transparent 55%);pointer-events:none}
.btn-submit::after{content:'';position:absolute;top:0;bottom:0;width:40%;left:-60%;background:linear-gradient(100deg,transparent,rgba(255,255,255,.35),transparent);transform:skewX(-20deg);animation:sweep 3.6s ease-in-out infinite}
@keyframes sweep{0%,60%{left:-60%}100%{left:130%}}
.btn-submit:hover:not(:disabled){background-position:left center;transform:translateY(-2px);box-shadow:0 14px 44px rgba(34,197,94,.4),0 10px 40px rgba(147,51,234,.3)}
.btn-submit:active:not(:disabled){transform:translateY(0) scale(.985)}
.btn-submit:disabled{opacity:.6;cursor:not-allowed}
.btn-submit.loading{cursor:progress;opacity:.85}
.btn-inner{display:flex;align-items:center;justify-content:center;gap:.55rem;position:relative;z-index:1}
.btn-spinner{display:none;width:20px;height:20px;border:2.5px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .65s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.btn-submit.loading .btn-text{display:none}.btn-submit.loading .btn-spinner{display:block}
.ripple{position:absolute;border-radius:50%;background:rgba(255,255,255,.35);transform:scale(0);animation:rip .6s ease-out;pointer-events:none}
@keyframes rip{to{transform:scale(4);opacity:0}}

.card-footer{margin-top:1.3rem;text-align:center;color:var(--faint);font-size:.72rem;line-height:1.7}
:focus-visible{outline:2px solid var(--green);outline-offset:2px}

/* forgot sheet */
.ov{position:fixed;inset:0;z-index:50;background:rgba(2,6,23,.75);backdrop-filter:blur(8px);display:none;align-items:center;justify-content:center;padding:18px}
.ov.on{display:flex;animation:fade .2s ease}
@keyframes fade{from{opacity:0}}
.sheet{width:100%;max-width:400px;border-radius:24px;padding:1.6rem 1.5rem 1.3rem;text-align:center;background:linear-gradient(170deg,#121a33,#0a1122);border:1px solid rgba(168,85,247,.3);box-shadow:0 40px 100px rgba(0,0,0,.6);animation:pop .3s ease}
.sheet .ic{width:64px;height:64px;border-radius:20px;margin:0 auto .8rem;display:grid;place-items:center;font-size:1.8rem;background:linear-gradient(135deg,rgba(34,197,94,.2),rgba(147,51,234,.2));border:1px solid var(--border-hi)}
.sheet h3{font-size:1.15rem;font-weight:900;margin-bottom:.5rem}
.sheet p{font-size:.88rem;color:var(--muted);line-height:1.8;margin-bottom:1.1rem}
.sheet button{width:100%;height:48px;border-radius:14px;border:0;font:inherit;font-weight:800;font-size:.95rem;color:#fff;cursor:pointer;background:linear-gradient(90deg,var(--green),var(--purple))}

/* ── responsive ── */
@media (max-width:980px){
  .shell{grid-template-columns:1fr;gap:10px;padding:18px 16px 28px;align-content:start;max-width:520px}
  .hero{gap:10px;align-items:center;text-align:center}
  .hero-top{justify-content:center}
  .wordmark{font-size:clamp(2.5rem,12vw,3.6rem);text-align:center!important}
  .hero-line{font-size:1rem}
  .clock{justify-content:center}.clock .tm{font-size:1.6rem}
  .tags,.hero .showcar{display:none}
  .floor{height:30vh}.horizon{bottom:30vh}
}
@media (max-width:480px){
  html{font-size:15px}
  .card{padding:2.3rem 1.2rem 1.35rem;border-radius:24px}
  .card::before{border-radius:25.5px}.card::after{border-radius:24px}
  .robot{width:104px;height:104px}.robot-wrap{margin-bottom:-24px}
  .hero-line{display:none}
}
@media (max-height:720px) and (min-width:981px){ .showcar{height:130px} .tags{display:none} }
@media (prefers-reduced-motion:reduce){*{animation-duration:.001ms!important;animation-iteration-count:1!important;transition-duration:.05ms!important}}
</style>
</head>
<body class="<?= $prefill !== '' && $lastUser !== '' && $typedUser === '' ? 'has-me' : '' ?>">

<canvas id="stars" class="layer" aria-hidden="true"></canvas>
<div class="aurora layer" aria-hidden="true"><i></i><i></i><i></i></div>
<div class="grid layer" aria-hidden="true" id="grid"></div>
<div class="horizon" aria-hidden="true"></div>
<div class="floor" aria-hidden="true"></div>
<span id="o1" hidden></span><span id="o2" hidden></span>

<div class="shell">

  <!-- ── showroom side ── -->
  <section class="hero" aria-hidden="false">
    <div class="hero-top">
      <div class="hero-logo"><span class="mk">F1</span><span>First 1 Car</span></div>
      <div class="secure"><i></i><?= htmlspecialchars($T['secure']) ?></div>
    </div>
    <h1 class="wordmark"><span>First 1 Car</span></h1>
    <div class="hero-line"><?= htmlspecialchars($T['hero_t']) ?><br><b><?= htmlspecialchars($T['hero_t2']) ?></b></div>
    <div class="clock"><div class="tm" id="clockTm">--:--</div><div class="dt" id="clockDt"></div></div>
    <div class="tags">
      <span>🚗 <?= htmlspecialchars($T['tag1']) ?></span>
      <span>💰 <?= htmlspecialchars($T['tag2']) ?></span>
      <span>🗺 <?= htmlspecialchars($T['tag3']) ?></span>
      <span>📊 <?= htmlspecialchars($T['tag4']) ?></span>
    </div>
    <div class="showcar" aria-hidden="true">
      <div class="glow"></div>
      <div class="beam"></div>
      <div class="lines"></div>
      <svg viewBox="0 0 520 190" preserveAspectRatio="xMidYMid meet">
        <defs>
          <linearGradient id="scBody" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#3b4a66"/><stop offset=".45" stop-color="#1e293b"/><stop offset="1" stop-color="#0b1220"/>
          </linearGradient>
          <linearGradient id="scStripe" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#22c55e"/><stop offset=".55" stop-color="#22d3ee"/><stop offset="1" stop-color="#a855f7"/>
          </linearGradient>
          <linearGradient id="scGlass" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#1e3a5f"/><stop offset="1" stop-color="#0a1424"/>
          </linearGradient>
          <radialGradient id="scRim" cx="50%" cy="50%" r="50%"><stop offset="0" stop-color="#e2e8f0"/><stop offset=".6" stop-color="#64748b"/><stop offset="1" stop-color="#1e293b"/></radialGradient>
        </defs>
        <ellipse cx="262" cy="170" rx="210" ry="10" fill="#000" opacity=".55"/>
        <!-- body -->
        <path d="M40 140 L42 118 Q46 104 72 100 L150 92 L208 58 Q222 50 244 49 L318 49 Q342 50 360 64 L402 94 L462 101 Q490 106 494 124 L496 140 Q496 150 486 150 L438 150 A36 36 0 0 0 366 150 L178 150 A36 36 0 0 0 106 150 L50 150 Q40 150 40 140 Z"
              fill="url(#scBody)" stroke="#51607c" stroke-width="1.6" stroke-linejoin="round"/>
        <!-- glass -->
        <path d="M168 94 L214 64 Q226 57 244 57 L284 57 L284 94 Z" fill="url(#scGlass)" stroke="#2b3b58" stroke-width="1.2"/>
        <path d="M294 57 L318 57 Q338 58 352 68 L388 94 L294 94 Z" fill="url(#scGlass)" stroke="#2b3b58" stroke-width="1.2"/>
        <path d="M176 90 L214 66" stroke="rgba(255,255,255,.25)" stroke-width="3" stroke-linecap="round"/>
        <!-- neon stripe & details -->
        <path d="M52 124 L486 116" stroke="url(#scStripe)" stroke-width="4" stroke-linecap="round" filter="drop-shadow(0 0 6px rgba(34,211,238,.8))"/>
        <path d="M288 100 L288 146" stroke="#2b3b58" stroke-width="1.4"/>
        <rect x="300" y="104" width="22" height="4" rx="2" fill="#475569"/>
        <!-- lights -->
        <path d="M470 106 Q488 108 492 120 L474 118 Z" fill="#fef9c3" filter="drop-shadow(0 0 10px #fde047)"/>
        <path d="M42 116 L58 112 L58 124 L44 126 Z" fill="#f43f5e" filter="drop-shadow(0 0 8px #f43f5e)"/>
        <!-- wheels -->
        <g transform="translate(142 150)"><circle r="31" fill="#05080f" stroke="#1e293b" stroke-width="5"/><g class="wh"><circle r="19" fill="url(#scRim)"/><path d="M0 -19 L0 19 M-19 0 L19 0 M-13 -13 L13 13 M13 -13 L-13 13" stroke="#0f172a" stroke-width="3"/><circle r="5" fill="#22c55e"/></g></g>
        <g transform="translate(402 150)"><circle r="31" fill="#05080f" stroke="#1e293b" stroke-width="5"/><g class="wh"><circle r="19" fill="url(#scRim)"/><path d="M0 -19 L0 19 M-19 0 L19 0 M-13 -13 L13 13 M13 -13 L-13 13" stroke="#0f172a" stroke-width="3"/><circle r="5" fill="#a855f7"/></g></g>
      </svg>
    </div>
  </section>

  <!-- ── sign-in side ── -->
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
          <a href="?lang=ar<?= $qsNext ?>" class="<?= $lang === 'ar' ? 'active' : '' ?>"><?= $T['lang_ar'] ?></a>
          <a href="?lang=en<?= $qsNext ?>" class="<?= $lang === 'en' ? 'active' : '' ?>"><?= $T['lang_en'] ?></a>
        </nav>

        <div class="greet">
          <div class="hi" id="greetHi"><span id="greetIc">✨</span><span id="greetTx"><?= htmlspecialchars($T['hello']) ?></span></div>
          <h2><?= htmlspecialchars($T['welcome']) ?></h2>
          <p><?= htmlspecialchars($T['subtitle']) ?></p>
        </div>

        <div class="note warn offline" role="status">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 1l22 22M16.72 11.06A10.94 10.94 0 0 1 19 12.55M5 12.55a10.94 10.94 0 0 1 5.17-2.39M10.71 5.05A16 16 0 0 1 22.58 9M1.42 9a15.91 15.91 0 0 1 4.7-2.88M8.53 16.11a6 6 0 0 1 6.95 0M12 20h.01"/></svg>
          <div><?= htmlspecialchars($T['offline']) ?></div>
        </div>

        <?php if ($next !== '' && !$msg): ?>
        <div class="note info" role="status">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6M10 14L21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>
          <div><?= htmlspecialchars($T['next_note']) ?></div>
        </div>
        <?php endif; ?>

        <?php if ($msg && $msg['kind'] === 'lock'): ?>
        <div class="note lock" role="alert" id="lockBox" data-secs="<?= (int)$lockSecs ?>">
          <div class="ring"><svg viewBox="0 0 46 46"><circle cx="23" cy="23" r="19" fill="none" stroke="rgba(255,255,255,.1)" stroke-width="4"/><circle id="lockArc" cx="23" cy="23" r="19" fill="none" stroke="#f87171" stroke-width="4" stroke-linecap="round" stroke-dasharray="119.4" stroke-dashoffset="0"/></svg><b id="lockTxt">--:--</b></div>
          <div><span id="lockMsg"><?= htmlspecialchars($msg['text']) ?></span><small id="lockSub"><?= htmlspecialchars($T['e_wait']) ?> <span id="lockTxt2">--:--</span></small></div>
        </div>
        <?php elseif ($msg): ?>
        <div class="note <?= $msg['kind'] === 'err' ? 'err' : 'warn' ?>" role="alert">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <div><?= htmlspecialchars($msg['text']) ?><?php if (!empty($msg['sub'])): ?><small><?= htmlspecialchars($msg['sub']) ?></small><?php endif; ?></div>
        </div>
        <?php endif; ?>

        <?php if ($lastUser !== '' && $typedUser === ''): ?>
        <div class="me" id="meCard">
          <div class="av"><?= htmlspecialchars(mb_strtoupper(mb_substr($lastUser, 0, 1))) ?></div>
          <div class="nm"><small id="meHi"><?= htmlspecialchars($T['hello']) ?> 👋</small><b><?= htmlspecialchars($lastUser) ?></b></div>
          <button type="button" id="notMe"><?= htmlspecialchars($T['not_you']) ?></button>
        </div>
        <?php endif; ?>

        <form action="login.php" method="POST" id="login-form" novalidate>
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="lang" value="<?= $lang ?>">
          <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

          <div class="form-group" id="userGroup">
            <div class="input-wrap">
              <span class="input-icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              </span>
              <input type="text" id="username" name="username" autocomplete="username" spellcheck="false" autocapitalize="none" required placeholder=" "
                     value="<?= htmlspecialchars($prefill) ?>">
              <label class="flabel" for="username"><?= htmlspecialchars($T['username']) ?></label>
            </div>
          </div>

          <div class="form-group">
            <div class="input-wrap">
              <span class="input-icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              </span>
              <input type="password" id="password" name="password" autocomplete="current-password" required placeholder=" ">
              <label class="flabel" for="password"><?= htmlspecialchars($T['password']) ?></label>
              <button type="button" class="eye-btn" id="eye-btn" aria-label="<?= htmlspecialchars($T['show_pw']) ?>">
                <svg id="eye-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
            <div class="caps" id="capsWarn">⚠️ <kbd>Caps Lock</kbd> <?= htmlspecialchars($T['caps']) ?></div>
          </div>

          <div class="extras">
            <label class="switch">
              <input type="checkbox" name="remember" value="1"><span class="sw"></span><?= htmlspecialchars($T['remember']) ?>
            </label>
            <button type="button" class="forgot-link" id="forgotBtn"><?= htmlspecialchars($T['forgot']) ?></button>
          </div>

          <button type="submit" class="btn-submit" id="submit-btn"<?= $lockSecs > 0 ? ' disabled' : '' ?>>
            <div class="btn-inner">
              <svg class="btn-text" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>
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
</div>

<div class="ov" id="forgotOv" aria-hidden="true">
  <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="forgotT">
    <div class="ic">🔑</div>
    <h3 id="forgotT"><?= htmlspecialchars($T['forgot_t']) ?></h3>
    <p><?= htmlspecialchars($T['forgot_b']) ?></p>
    <button type="button" id="forgotOk"><?= htmlspecialchars($T['ok']) ?></button>
  </div>
</div>

<script>
const LANG = <?= json_encode($lang) ?>;
const TX = <?= json_encode(['morning' => $T['morning'], 'evening' => $T['evening'], 'hello' => $T['hello'], 'hide' => $T['hide_pw'], 'show' => $T['show_pw'], 'open' => $T['e_open']], JSON_UNESCAPED_UNICODE) ?>;
const MSG = {
  hi:   LANG==='ar' ? 'أهلاً 👋'            : 'Hi there 👋',
  car:  LANG==='ar' ? 'بتحوّل لعربية! 🚗'   : 'I turn into a car! 🚗',
  look: LANG==='ar' ? 'اكتب اسمك...'        : 'Type your name...',
  shy:  LANG==='ar' ? 'مش هبصّ! 🙈'         : "Not looking! 🙈",
  bye:  LANG==='ar' ? 'يلا بينا! 🚀'        : "Let's go! 🚀"
};
const u=document.getElementById('username'),p=document.getElementById('password');

/* ---------- greeting + live clock (Cairo time) ---------- */
(function(){
  const tm=document.getElementById('clockTm'),dt=document.getElementById('clockDt');
  const loc=LANG==='ar'?'ar-EG':'en-GB';
  function tick(){
    const now=new Date();
    const h=+new Intl.DateTimeFormat('en-GB',{hour:'numeric',hour12:false,timeZone:'Africa/Cairo'}).format(now);
    const t=new Intl.DateTimeFormat('en-GB',{hour:'2-digit',minute:'2-digit',hour12:true,timeZone:'Africa/Cairo'}).formatToParts(now);
    const g=k=>(t.find(x=>x.type===k)||{}).value||'';
    tm.innerHTML=g('hour')+':'+g('minute')+'<small>'+(g('dayPeriod')||'').toUpperCase()+'</small>';
    dt.textContent=new Intl.DateTimeFormat(loc,{weekday:'long',day:'numeric',month:'long',timeZone:'Africa/Cairo'}).format(now);
    const morning=h>=5&&h<12;
    document.getElementById('greetTx').textContent=morning?TX.morning:TX.evening;
    document.getElementById('greetIc').textContent=morning?'☀️':(h>=18||h<5?'🌙':'🌇');
    const meHi=document.getElementById('meHi'); if(meHi) meHi.textContent=(morning?TX.morning:TX.evening)+' 👋';
  }
  tick(); setInterval(tick,15000);
})();

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
const tilt=document.getElementById('tilt'),grid=document.getElementById('grid');
let tx=0,ty=0;
addEventListener('pointermove',e=>{
  if(e.pointerType==='touch')return;
  const gx=(e.clientX/innerWidth-.5), gy=(e.clientY/innerHeight-.5);
  tx=gx;ty=gy;
  tilt.style.transform=`rotateY(${(gx*8).toFixed(2)}deg) rotateX(${(-gy*8).toFixed(2)}deg)`;
  grid.style.transform=`translate(${gx*-26}px,${gy*-26}px)`;
});
document.addEventListener('pointerleave',()=>{tilt.style.transform='rotateY(0) rotateX(0)';});

/* ---------- form interactions ---------- */
u.addEventListener('focus',()=>{if(isCar)transformTo(false);shy=false;robot.classList.remove('shy');
  base.forEach(b=>b.el.setAttribute('transform','translate(0,4)'));say(MSG.look,0);});
u.addEventListener('blur',()=>{if(document.activeElement!==p)bubble.classList.remove('show');});
p.addEventListener('focus',()=>{if(isCar)transformTo(false);shy=true;robot.classList.add('shy');say(MSG.shy,0);});
p.addEventListener('blur',()=>{shy=false;robot.classList.remove('shy');bubble.classList.remove('show');});

/* remembered user: one tap to switch */
const notMe=document.getElementById('notMe');
if(notMe) notMe.addEventListener('click',()=>{
  document.body.classList.remove('has-me');
  const me=document.getElementById('meCard'); if(me) me.remove();
  u.value=''; u.focus();
  document.cookie='<?= F1C_LASTUSER_COOKIE ?>=; Max-Age=0; path=/';
});
if(document.body.classList.contains('has-me')) setTimeout(()=>p.focus({preventScroll:true}),600);
else if(u.value) setTimeout(()=>p.focus({preventScroll:true}),600);

/* caps lock */
const caps=document.getElementById('capsWarn');
['keydown','keyup'].forEach(ev=>p.addEventListener(ev,e=>{ if(e.getModifierState) caps.classList.toggle('on',e.getModifierState('CapsLock')); }));
p.addEventListener('blur',()=>caps.classList.remove('on'));

/* password visibility toggle */
const eyeBtn=document.getElementById('eye-btn'),eyeIcon=document.getElementById('eye-icon');
const EYE_OPEN=`<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
const EYE_CLOSED=`<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M1 1l22 22"/>`;
eyeBtn.addEventListener('click',()=>{const s=p.type==='password';p.type=s?'text':'password';
  eyeIcon.innerHTML=s?EYE_CLOSED:EYE_OPEN;eyeBtn.setAttribute('aria-label',s?TX.hide:TX.show);});

/* forgot password */
const fo=document.getElementById('forgotOv');
document.getElementById('forgotBtn').addEventListener('click',()=>{fo.classList.add('on');fo.setAttribute('aria-hidden','false');document.getElementById('forgotOk').focus();});
function closeFo(){fo.classList.remove('on');fo.setAttribute('aria-hidden','true');}
document.getElementById('forgotOk').addEventListener('click',closeFo);
fo.addEventListener('click',e=>{if(e.target===fo)closeFo();});
addEventListener('keydown',e=>{if(e.key==='Escape')closeFo();});

/* offline */
function net(){document.body.classList.toggle('is-offline',!navigator.onLine);}
addEventListener('online',net);addEventListener('offline',net);net();

/* lockout countdown */
const lockBox=document.getElementById('lockBox');
if(lockBox){
  const total=+lockBox.dataset.secs||0, end=Date.now()+total*1000, arc=document.getElementById('lockArc'), btn=document.getElementById('submit-btn');
  const fmt=s=>String(Math.floor(s/60)).padStart(2,'0')+':'+String(s%60).padStart(2,'0');
  (function t(){
    const left=Math.max(0,Math.round((end-Date.now())/1000));
    document.getElementById('lockTxt').textContent=fmt(left);
    document.getElementById('lockTxt2').textContent=fmt(left);
    arc.setAttribute('stroke-dashoffset',(119.4*(1-(total?left/total:0))).toFixed(1));
    if(left<=0){lockBox.classList.add('open');document.getElementById('lockMsg').textContent=TX.open;document.getElementById('lockSub').style.display='none';arc.setAttribute('stroke','#4ade80');btn.disabled=false;return;}
    setTimeout(t,1000);
  })();
}

/* submit — ripple, transform into a car and drive off, then really submit */
const form=document.getElementById('login-form'),btn=document.getElementById('submit-btn');
btn.addEventListener('pointerdown',e=>{const r=btn.getBoundingClientRect(),s=Math.max(r.width,r.height),d=document.createElement('span');
  d.className='ripple';d.style.cssText=`width:${s}px;height:${s}px;left:${e.clientX-r.left-s/2}px;top:${e.clientY-r.top-s/2}px`;btn.appendChild(d);setTimeout(()=>d.remove(),650);});
form.addEventListener('submit',e=>{
  if(!navigator.onLine){e.preventDefault();net();return;}
  if(!u.value.trim()||!p.value){e.preventDefault();(u.value.trim()?p:u).focus();
    const g=(u.value.trim()?p:u).closest('.input-wrap');g.animate([{transform:'translateX(0)'},{transform:'translateX(-6px)'},{transform:'translateX(6px)'},{transform:'translateX(0)'}],{duration:300});return;}
  btn.classList.add('loading');btn.disabled=true;
  shy=false;robot.classList.remove('shy','wave');
  say(MSG.bye,0);
  transformTo(true);
  setTimeout(()=>{robot.classList.add('driving');burst();},560);
  /* the real POST proceeds natively */
});

/* ---------- confetti + starfield ---------- */
let confetti=[];
function burst(){const ox=innerWidth/2,oy=innerHeight/2,cols=['#22c55e','#4ade80','#9333ea','#a855f7','#a3e635','#22d3ee'];
  for(let i=0;i<70;i++){const a=Math.random()*6.28,s=2+Math.random()*6;
    confetti.push({x:ox,y:oy,vx:Math.cos(a)*s,vy:Math.sin(a)*s-3,life:1,col:cols[i%cols.length]});}}
const cv=document.getElementById('stars'),cx=cv.getContext('2d');
let stars=[];
function resize(){cv.width=innerWidth;cv.height=innerHeight;
  stars=Array.from({length:Math.min(160,Math.round(innerWidth/9))},()=>({
    x:Math.random()*cv.width,y:Math.random()*cv.height,z:Math.random(),r:Math.random()*1.4+.3,tw:Math.random()*6.28}));}
resize();addEventListener('resize',resize);
const reduce=matchMedia('(prefers-reduced-motion:reduce)').matches;
(function draw(){
  cx.clearRect(0,0,cv.width,cv.height);
  for(const s of stars){
    if(!reduce){s.y+=s.z*.22;s.tw+=.03;if(s.y>cv.height){s.y=0;s.x=Math.random()*cv.width;}}
    const px=s.x+tx*s.z*34, py=s.y+ty*s.z*34;
    cx.globalAlpha=(.3+s.z*.5)*(.75+Math.sin(s.tw)*.25);
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
