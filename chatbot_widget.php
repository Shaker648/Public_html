<?php
/**
 * chatbot_widget.php
 * First 1 Car — Data Assistant floating widget (v4 "command center")
 *
 * INSTALL: unchanged — one line before </body> in dashboard.php:
 *     <?php include 'chatbot_widget.php'; ?>
 *
 * v4:
 *  - The robot stays (3D CSS, floats, blinks, turns into a car) but is calmer:
 *    one act every 30-60 s, never while someone is typing/scrolling, naps when
 *    the tab is hidden, lives on the right and flies home when notification
 *    cards appear (they are on the left).
 *  - New chat panel: animated aurora border, glass body, greeting hero, the
 *    menu as coloured tiles, bot answers with a heading + typing reveal,
 *    tappable CAR CARDS (photo, status, colour, branch, chassis → timeline),
 *    suggestion chips, 🎤 voice questions, new-chat / expand buttons,
 *    full-screen sheet on phones.
 *  - The conversation is kept while the person stays logged in (sessionStorage),
 *    and the last few messages go to the brain so follow-ups work.
 */
$__lang = (isset($lang) && in_array($lang, ['ar', 'en'], true)) ? $lang : 'ar';
$__isAr = ($__lang === 'ar');
$__side = 'right';   // always on the right — notifications live on the left
?>
<style>
/* ═════════ THE ROBOT ═════════ */
#f1c-chat-bubble {
    position: fixed;
    bottom: calc(76px + env(safe-area-inset-bottom, 0px) + 14px);
    <?= $__side ?>: 16px;
    width: 78px; height: 122px;
    cursor: pointer; z-index: 998;
    perspective: 520px;
    -webkit-tap-highlight-color: transparent;
    will-change: transform;
}
#f1c-chat-bubble.f1c-flying .f1c-bot-body { animation-play-state: paused; transform: rotateZ(var(--f1c-tilt, 0deg)); transition: transform .4s; }
#f1c-chat-bubble.f1c-flying .f1c-jet::after { animation-duration: .12s; height: 26px; filter: brightness(1.5) saturate(1.3); }
#f1c-chat-bubble.f1c-flying .f1c-bot-shadow { opacity: 0; }
#f1c-chat-bubble.f1c-hidden { display: none; }

.f1c-bot-body {
    position: relative; width: 60px; height: 62px; margin: 0 auto;
    transform-style: preserve-3d;
    animation: f1cFloat 3.4s ease-in-out infinite;
}
@keyframes f1cFloat {
    0%, 100% { transform: translateY(0)    rotateY(-8deg) rotateZ(-1.5deg); }
    50%      { transform: translateY(-8px) rotateY(8deg)  rotateZ(1.5deg); }
}
#f1c-chat-bubble:active .f1c-bot-body { animation-play-state: paused; transform: scale(.92); }

/* antenna */
.f1c-bot-ant {
    position: absolute; top: -11px; left: 50%; margin-left: -1.5px;
    width: 3px; height: 12px; background: #94a3b8; border-radius: 2px;
}
.f1c-bot-ant::after {
    content: ''; position: absolute; top: -8px; left: 50%;
    width: 9px; height: 9px; margin-left: -4.5px; border-radius: 50%;
    background: #38bdf8;
    animation: f1cAntGlow 1.8s ease-in-out infinite;
}
@keyframes f1cAntGlow {
    0%, 100% { box-shadow: 0 0 5px #38bdf8;  background: #38bdf8; }
    50%      { box-shadow: 0 0 14px #a855f7; background: #a855f7; }
}
/* expanding signal ring from the antenna tip */
.f1c-ping {
    position: absolute; top: -12px; left: 50%; width: 16px; height: 16px;
    margin-left: -8px; border-radius: 50%;
    border: 1.5px solid rgba(56,189,248,.8);
    animation: f1cPing 3.2s ease-out infinite;
    pointer-events: none;
}
@keyframes f1cPing {
    0%, 55% { transform: scale(.25); opacity: 0; }
    60%     { opacity: .8; }
    100%    { transform: scale(2.1); opacity: 0; }
}

/* head */
.f1c-bot-head {
    position: relative; width: 60px; height: 48px;
    background: linear-gradient(145deg, #e2e8f0, #8ea0b8);
    border-radius: 17px;
    box-shadow: inset 0 -7px 12px rgba(2,6,23,.30),
                inset 0 3px 6px rgba(255,255,255,.75),
                0 10px 26px rgba(37,99,235,.40);
}
/* ears */
.f1c-bot-head::before, .f1c-bot-head::after {
    content: ''; position: absolute; top: 15px;
    width: 6px; height: 17px; background: linear-gradient(145deg,#cbd5e1,#7b8ea6);
    border-radius: 4px;
}
.f1c-bot-head::before { left: -7px; }
.f1c-bot-head::after  { right: -7px; }

/* face screen */
.f1c-bot-face {
    position: absolute; inset: 8px 8px 9px;
    background: radial-gradient(circle at 50% 20%, #16233f, #0b1120);
    border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    overflow: hidden;
}
/* holographic light sweep across the visor */
.f1c-bot-face::before {
    content: ''; position: absolute; top: -60%; left: -40%;
    width: 34%; height: 220%;
    background: linear-gradient(115deg, transparent, rgba(148,220,255,.22), transparent);
    transform: rotate(0deg);
    animation: f1cVisor 5.5s ease-in-out infinite;
}
@keyframes f1cVisor {
    0%, 55%  { left: -45%; }
    75%, 100% { left: 115%; }
}
/* eyes look around while still blinking */
.f1c-eyes { display: flex; gap: 10px; animation: f1cLook 8.5s ease-in-out infinite; }
@keyframes f1cLook {
    0%, 34%, 100% { transform: translateX(0); }
    40%, 52%      { transform: translateX(3.5px); }
    58%, 60%      { transform: translateX(0); }
    72%, 84%      { transform: translateX(-3.5px); }
}
.f1c-bot-eye {
    width: 9px; height: 13px; border-radius: 4.5px;
    background: #38bdf8; box-shadow: 0 0 9px #38bdf8;
    animation: f1cBlink 4.4s infinite;
}
.f1c-bot-eye.f1c-e2 { animation-delay: .06s; }
/* soft glow cheeks */
.f1c-cheek {
    position: absolute; bottom: 12px; width: 7px; height: 4px;
    border-radius: 50%; background: rgba(168,85,247,.55); filter: blur(1.5px);
}
.f1c-cheek.f1c-cl { left: 7px; }
.f1c-cheek.f1c-cr { right: 7px; }
/* glossy highlight on the head shell */
.f1c-bot-glare {
    position: absolute; top: 4px; left: 8px; width: 18px; height: 8px;
    border-radius: 50%;
    background: linear-gradient(120deg, rgba(255,255,255,.95), rgba(255,255,255,0));
    filter: blur(.6px); pointer-events: none;
}
@keyframes f1cBlink {
    0%, 90%, 100% { transform: scaleY(1); }
    93%           { transform: scaleY(.08); }
    96%           { transform: scaleY(1); }
}
/* smile */
.f1c-bot-face::after {
    content: ''; position: absolute; bottom: 6px; left: 50%;
    width: 14px; height: 6px; margin-left: -7px;
    border-bottom: 2.5px solid #38bdf8; border-radius: 0 0 10px 10px;
    box-shadow: 0 2px 6px rgba(56,189,248,.6);
}

/* ── white t-shirt, full-chest First 1 Car logo print ── */
.f1c-bot-torso {
    position: relative; width: 52px; height: 34px; margin: 2px auto 0;
    background: linear-gradient(160deg, #ffffff, #dbe3ee 70%, #b9c6d8);
    border-radius: 8px 8px 12px 12px;
    box-shadow: inset 0 -6px 9px rgba(2,6,23,.22),
                inset 0 3px 5px rgba(255,255,255,.9),
                0 6px 16px rgba(15,23,42,.35);
    display: flex; align-items: center; justify-content: center;
    overflow: hidden;
}
/* collar */
.f1c-bot-collar {
    position: absolute; top: 0; left: 50%; width: 22px; height: 6px;
    margin-left: -11px; border-radius: 0 0 10px 10px;
    background: #16a34a;
    box-shadow: 0 1px 3px rgba(2,6,23,.25);
}
/* sleeves */
.f1c-bot-sleeve {
    position: absolute; top: 3px; width: 11px; height: 17px;
    background: linear-gradient(160deg, #ffffff, #c3cfdd);
    border-radius: 6px;
    box-shadow: inset 0 -3px 5px rgba(2,6,23,.22);
    z-index: -1;
}
.f1c-bot-sleeve.f1c-sl { <?= $__isAr ? 'right' : 'left' ?>: 3px;  transform: rotate(<?= $__isAr ? '-16deg' : '16deg' ?>); }
.f1c-bot-sleeve.f1c-sr { <?= $__isAr ? 'left' : 'right' ?>: 3px; transform: rotate(<?= $__isAr ? '16deg' : '-16deg' ?>); }
/* FULL-CHEST logo print — like a real printed tee */
.f1c-bot-logo {
    width: 86%; height: 66%; margin-top: 5px;
    object-fit: contain;
    background: #0b1120;           /* unify with the logo's own dark bg  */
    border-radius: 6px;            /* → reads as a printed chest patch   */
    padding: 1.5px;
    box-shadow: 0 1px 3px rgba(2,6,23,.35), inset 0 0 0 1px rgba(255,255,255,.14);
}
.f1c-bot-logo-txt {
    display: none; align-items: center; justify-content: center;
    width: 84%; height: 70%; margin-top: 4px;
    color: #16a34a; font-size: 11px; font-weight: 900; letter-spacing: .5px;
}

/* ── jet thrusters + flames ── */
.f1c-bot-jets { display: flex; justify-content: center; gap: 12px; margin-top: 1px; height: 26px; }
.f1c-jet {
    position: relative; width: 9px; height: 8px;
    background: linear-gradient(145deg, #cbd5e1, #64748b);
    border-radius: 0 0 4px 4px;
}
.f1c-jet::after {          /* the flame */
    content: ''; position: absolute; top: 8px; left: 50%;
    width: 8px; height: 15px; margin-left: -4px;
    background: linear-gradient(180deg, #a855f7, #38bdf8 45%, rgba(56,189,248,0));
    border-radius: 50% 50% 50% 50% / 20% 20% 80% 80%;
    filter: blur(.4px) drop-shadow(0 3px 7px rgba(56,189,248,.7));
    transform-origin: top center;
    animation: f1cFlame .22s ease-in-out infinite alternate;
}
.f1c-jet.f1c-j2::after { animation-delay: .1s; }
@keyframes f1cFlame {
    from { transform: scaleY(.75) scaleX(.9);  opacity: .8; }
    to   { transform: scaleY(1.15) scaleX(1.05); opacity: 1; }
}

/* chest */
.f1c-bot-chest {
    position: absolute; bottom: 0; left: 50%; margin-left: -14px;
    width: 28px; height: 12px;
    background: linear-gradient(145deg, #cbd5e1, #7b8ea6);
    border-radius: 0 0 9px 9px;
    box-shadow: inset 0 -3px 5px rgba(2,6,23,.35);
}
.f1c-bot-chest::after {
    content: ''; position: absolute; top: 3px; left: 50%; margin-left: -3px;
    width: 6px; height: 6px; border-radius: 50%;
    background: #a855f7; box-shadow: 0 0 6px #a855f7;
    animation: f1cAntGlow 1.8s ease-in-out infinite reverse;
}

/* floating shadow under the robot */
.f1c-bot-shadow {
    width: 48px; height: 13px; margin: 5px auto 0;
    border-radius: 50%;
    border: 1.5px solid rgba(56,189,248,.45);
    background: radial-gradient(ellipse at center, rgba(56,189,248,.28), rgba(168,85,247,.10) 55%, transparent 75%);
    box-shadow: 0 0 12px rgba(56,189,248,.35), inset 0 0 8px rgba(168,85,247,.30);
    animation: f1cShadow 3.4s ease-in-out infinite;
}
@keyframes f1cShadow {
    0%, 100% { transform: scaleX(1);   opacity: .85; }
    50%      { transform: scaleX(.62); opacity: .45; }
}

/* ── sleeping mode ── */
#f1c-chat-bubble.f1c-sleep .f1c-bot-eye {
    animation: none; transform: scaleY(.08); box-shadow: none; background: #64748b;
}
#f1c-chat-bubble.f1c-sleep .f1c-bot-ant::after { animation: none; opacity: .3; box-shadow: none; }
#f1c-chat-bubble.f1c-sleep .f1c-jet::after { opacity: .18; animation-duration: 1.4s; }
#f1c-chat-bubble.f1c-sleep .f1c-bot-body { animation-duration: 6.5s; }
#f1c-chat-bubble.f1c-sleep .f1c-bot-face::after { border-radius: 10px 10px 0 0; border-bottom: none; border-top: 2.5px solid #64748b; box-shadow: none; }

/* ── fireworks sparks ── */
.f1c-spark {
    position: absolute; top: 30px; left: 50%; width: 7px; height: 7px;
    border-radius: 50%; pointer-events: none; z-index: 3;
    background: var(--clr, #22c55e);
    box-shadow: 0 0 8px var(--clr, #22c55e);
    animation: f1cSpark .95s ease-out forwards;
}
@keyframes f1cSpark {
    0%   { transform: translate(0,0) scale(1);   opacity: 1; }
    100% { transform: translate(var(--dx), var(--dy)) scale(.1); opacity: 0; }
}
#f1c-chat-bubble.f1c-party .f1c-bot-body { animation: f1cPartySpin .9s ease-in-out; }
@keyframes f1cPartySpin {
    0%   { transform: rotateY(0)      translateY(0); }
    50%  { transform: rotateY(180deg) translateY(-14px); }
    100% { transform: rotateY(360deg) translateY(0); }
}

/* ═════════ CAR TRANSFORM MODE ═════════ */
.f1c-car {
    position: absolute; top: 24px; left: 50%; margin-left: -40px;
    width: 80px; height: 42px; display: none;
    filter: drop-shadow(0 8px 14px rgba(37,99,235,.4));
}
#f1c-chat-bubble.f1c-ascar .f1c-bot-body,
#f1c-chat-bubble.f1c-ascar .f1c-bot-shadow { display: none; }
#f1c-chat-bubble.f1c-ascar .f1c-car { display: block; }
.f1c-car.f1c-face-left { transform: scaleX(-1); }
.f1c-car-roof {
    position: absolute; bottom: 19px; left: 20px; width: 42px; height: 16px;
    background: linear-gradient(160deg, #cbd5e1, #7b8ea6); border-radius: 10px 13px 0 0;
}
.f1c-car-win {
    position: absolute; bottom: 21px; left: 24px; width: 34px; height: 11px;
    background: radial-gradient(circle at 50% 20%, #16233f, #0b1120); border-radius: 6px 9px 0 0;
}
.f1c-car-body {
    position: absolute; bottom: 7px; left: 0; width: 80px; height: 21px;
    background: linear-gradient(160deg, #e2e8f0, #8ea0b8); border-radius: 9px 12px 6px 6px;
    box-shadow: inset 0 -4px 6px rgba(2,6,23,.3), inset 0 2px 3px rgba(255,255,255,.7);
}
.f1c-car-stripe {
    position: absolute; bottom: 15px; left: 5px; width: 70px; height: 3px; border-radius: 3px;
    background: linear-gradient(90deg, #22c55e, #a855f7);
}
.f1c-car-light {
    position: absolute; bottom: 11px; right: 1px; width: 6px; height: 6px; border-radius: 50%;
    background: #a3e635; box-shadow: 0 0 9px #22c55e;
}
.f1c-wheel2 {
    position: absolute; bottom: 0; width: 16px; height: 16px; border-radius: 50%;
    background: #0b1120; border: 2px solid #64748b;
}
.f1c-wheel2::after {
    content: ''; position: absolute; inset: 3px; border-radius: 50%;
    border: 1.6px solid #22c55e; border-right-color: transparent; border-top-color: transparent;
}
.f1c-wheel2.f1c-w-back  { left: 11px; }
.f1c-wheel2.f1c-w-front { right: 11px; }
#f1c-chat-bubble.f1c-ascar .f1c-wheel2 { animation: f1cRoll .3s linear infinite; }
@keyframes f1cRoll { to { transform: rotate(360deg); } }

/* ═════════ GREETING SPEECH BUBBLE ═════════ */
#f1c-bot-say {
    position: absolute;
    bottom: calc(100% + 8px);
    <?= $__side ?>: -4px;
    width: max-content;
    max-width: 220px;
    background: #fff; color: #0f172a;
    font-size: 12.5px; font-weight: 800; line-height: 1.55;
    border-radius: 15px; padding: 11px 14px;
    box-shadow: 0 10px 30px rgba(0,0,0,.45);
    z-index: 998; cursor: pointer;
    opacity: 0; transform: translateY(10px) scale(.9);
    transition: opacity .3s, transform .3s cubic-bezier(.2,1.4,.4,1);
    pointer-events: none;
    direction: <?= $__isAr ? 'rtl' : 'ltr' ?>;
}
#f1c-bot-say.show { opacity: 1; transform: none; pointer-events: auto; }
#f1c-chat-bubble.f1c-far #f1c-bot-say { <?= $__side ?>: auto; <?= $__isAr ? 'left' : 'right' ?>: -4px; }
#f1c-chat-bubble.f1c-far #f1c-bot-say::after { <?= $__side ?>: auto; <?= $__isAr ? 'left' : 'right' ?>: 22px; }
#f1c-bot-say::after {           /* tail toward the robot */
    content: ''; position: absolute; bottom: -7px; <?= $__side ?>: 22px;
    width: 14px; height: 14px; background: #fff;
    transform: rotate(45deg); border-radius: 3px;
}


/* ═════════════════════ CHAT PANEL v4 — "command center" ═════════════════════ */
#f1c-chat-panel {
    --f1c-a: #22c55e; --f1c-b: #a855f7; --f1c-c: #38bdf8;
    position: fixed;
    bottom: calc(76px + env(safe-area-inset-bottom, 0px) + 16px);
    <?= $__side ?>: 18px;
    width: min(410px, calc(100vw - 36px));
    height: min(640px, calc(100vh - 170px));
    border-radius: 26px;
    display: none; flex-direction: column;
    z-index: 9150;   /* above the notification cards while open; the message reader (9200) still comes first */
    font-family: 'Tajawal', 'Segoe UI', Tahoma, Arial, sans-serif;
    direction: <?= $__isAr ? 'rtl' : 'ltr' ?>;
    color: #e2e8f0;
    isolation: isolate;
    transform-origin: bottom <?= $__side ?>;
    opacity: 0; transform: translateY(24px) scale(.94);
    transition: transform .38s cubic-bezier(.2,1.2,.3,1), opacity .25s, width .35s cubic-bezier(.2,.9,.3,1), height .35s cubic-bezier(.2,.9,.3,1);
}
#f1c-chat-panel.open { display: flex; }
#f1c-chat-panel.show { opacity: 1; transform: none; }
#f1c-chat-panel.f1c-max { width: min(760px, calc(100vw - 36px)); height: min(820px, calc(100vh - 120px)); }
/* animated aurora border */
#f1c-chat-panel::before {
    content: ''; position: absolute; inset: -1.5px; border-radius: 27.5px; z-index: -2;
    background: conic-gradient(from var(--f1c-ang, 0deg), var(--f1c-a), var(--f1c-c), var(--f1c-b), #f472b6, var(--f1c-a));
    animation: f1cSpinBorder 6s linear infinite;
    filter: saturate(1.2);
}
@property --f1c-ang { syntax: '<angle>'; inherits: false; initial-value: 0deg; }
@keyframes f1cSpinBorder { to { --f1c-ang: 360deg; } }
/* glass body with a soft grid + drifting aurora */
#f1c-chat-panel::after {
    content: ''; position: absolute; inset: 0; border-radius: 26px; z-index: -1;
    background:
        radial-gradient(120% 60% at 110% -10%, rgba(168,85,247,.28), transparent 55%),
        radial-gradient(90% 55% at -15% 110%, rgba(34,197,94,.20), transparent 55%),
        linear-gradient(rgba(148,163,184,.045) 1px, transparent 1px) 0 0 / 22px 22px,
        linear-gradient(90deg, rgba(148,163,184,.045) 1px, transparent 1px) 0 0 / 22px 22px,
        linear-gradient(170deg, rgba(10,16,34,.97), rgba(5,8,20,.985));
    box-shadow: 0 30px 80px rgba(0,0,0,.65), 0 0 60px rgba(168,85,247,.18), inset 0 1px 0 rgba(255,255,255,.08);
}
.f1c-in { position: relative; display: flex; flex-direction: column; height: 100%; border-radius: 26px; overflow: hidden; }

/* header */
#f1c-chat-header {
    position: relative; display: flex; align-items: center; gap: 12px;
    padding: 14px 14px 12px 16px;
    background: linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,0));
    border-bottom: 1px solid rgba(255,255,255,.07);
}
.f1c-ava {
    position: relative; width: 44px; height: 44px; border-radius: 15px; flex-shrink: 0;
    background: linear-gradient(145deg, #eef2f7, #8ea0b8);
    box-shadow: 0 6px 18px rgba(56,189,248,.35), inset 0 -4px 8px rgba(2,6,23,.3), inset 0 2px 4px rgba(255,255,255,.8);
}
.f1c-ava::before {
    content: ''; position: absolute; inset: 6px; border-radius: 10px;
    background: radial-gradient(circle at 50% 20%, #16233f, #0b1120);
}
.f1c-ava i {
    position: absolute; top: 17px; width: 6px; height: 9px; border-radius: 3px;
    background: var(--f1c-c); box-shadow: 0 0 8px var(--f1c-c);
    animation: f1cBlink 4.4s infinite;
}
.f1c-ava i:nth-child(1) { left: 14px; } .f1c-ava i:nth-child(2) { right: 14px; }
.f1c-ava b {
    position: absolute; bottom: 10px; left: 50%; width: 10px; height: 4px; margin-left: -5px;
    border-bottom: 2px solid var(--f1c-c); border-radius: 0 0 8px 8px;
}
.f1c-ava.think i { animation: f1cThinkEyes .9s ease-in-out infinite alternate; }
@keyframes f1cThinkEyes { from { transform: translateX(-2px); } to { transform: translateX(2px); } }
.f1c-ava::after {   /* online dot */
    content: ''; position: absolute; bottom: -2px; <?= $__isAr ? 'left' : 'right' ?>: -2px; width: 12px; height: 12px; border-radius: 50%;
    background: #22c55e; border: 2.5px solid #0a1022; box-shadow: 0 0 8px #22c55e;
}
.f1c-h-txt { flex: 1; min-width: 0; }
.f1c-h-title {
    font-size: 16.5px; font-weight: 900; letter-spacing: .2px;
    background: linear-gradient(90deg, #f8fafc, #c4b5fd 55%, #86efac);
    -webkit-background-clip: text; background-clip: text; color: transparent;
}
.f1c-h-sub { font-size: 11.5px; color: #94a3b8; font-weight: 700; display: flex; align-items: center; gap: 6px; margin-top: 2px; }
.f1c-h-sub::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 6px #22c55e; animation: f1cPulseDot 2s infinite; }
@keyframes f1cPulseDot { 50% { opacity: .35; } }
.f1c-hb {
    width: 34px; height: 34px; border-radius: 11px; border: 1px solid rgba(255,255,255,.08);
    background: rgba(255,255,255,.04); color: #cbd5e1; cursor: pointer; font-size: 15px;
    display: grid; place-items: center; transition: background .15s, transform .15s; flex-shrink: 0; padding: 0; font-family: inherit;
}
.f1c-hb:hover { background: rgba(255,255,255,.1); transform: translateY(-1px); }
.f1c-hb:active { transform: scale(.92); }
@media (max-width: 560px) { .f1c-hb.f1c-exp { display: none; } }

/* messages */
#f1c-chat-messages {
    flex: 1; overflow-y: auto; overflow-x: hidden; padding: 16px 14px 10px;
    display: flex; flex-direction: column; gap: 12px;
    scroll-behavior: smooth; overscroll-behavior: contain;
    scrollbar-width: thin; scrollbar-color: rgba(148,163,184,.25) transparent;
}
#f1c-chat-messages > * { flex-shrink: 0; }
.f1c-more { display: inline-flex; align-items: center; gap: 5px; margin-top: 8px; border: 0; background: rgba(56,189,248,.12); color: #7dd3fc; font: inherit; font-size: 12px; font-weight: 800; padding: 5px 11px; border-radius: 999px; cursor: pointer; }
.f1c-more:hover { background: rgba(56,189,248,.22); }
.f1c-msg .full { display: none; }
.f1c-msg.expanded .full { display: block; }
.f1c-msg.expanded .short { display: none; }
.f1c-hero { text-align: center; padding: 8px 6px 4px; animation: f1cIn .5s ease both; }
.f1c-orb {
    width: 92px; height: 92px; margin: 4px auto 12px; border-radius: 50%; position: relative;
    background: radial-gradient(circle at 35% 30%, #fff 0 6%, transparent 7%),
                radial-gradient(circle at 50% 50%, rgba(56,189,248,.55), rgba(168,85,247,.35) 45%, rgba(34,197,94,.15) 70%, transparent 72%);
    box-shadow: 0 0 40px rgba(168,85,247,.45), inset 0 0 30px rgba(56,189,248,.45);
    animation: f1cOrb 5s ease-in-out infinite;
}
.f1c-orb::before, .f1c-orb::after {
    content: ''; position: absolute; inset: -8px; border-radius: 50%;
    border: 1.5px solid rgba(56,189,248,.35); border-top-color: transparent; border-bottom-color: transparent;
    animation: f1cRing 4s linear infinite;
}
.f1c-orb::after { inset: -16px; border-color: rgba(168,85,247,.3); border-left-color: transparent; border-right-color: transparent; animation-duration: 7s; animation-direction: reverse; }
@keyframes f1cOrb { 50% { transform: translateY(-5px) scale(1.03); } }
@keyframes f1cRing { to { transform: rotate(360deg); } }
.f1c-orb span { position: absolute; inset: 0; display: grid; place-items: center; font-size: 34px; filter: drop-shadow(0 4px 10px rgba(0,0,0,.4)); }
.f1c-hero h2 {
    font-size: 21px; font-weight: 900; margin: 0 0 4px;
    background: linear-gradient(90deg, #fff, #c4b5fd, #86efac, #fff); background-size: 250% 100%;
    -webkit-background-clip: text; background-clip: text; color: transparent;
    animation: f1cShine 6s linear infinite;
}
@keyframes f1cShine { to { background-position: -250% 0; } }
.f1c-hero p { font-size: 13px; color: #94a3b8; margin: 0; line-height: 1.7; font-weight: 600; }

.f1c-row { display: flex; gap: 8px; align-items: flex-end; max-width: 100%; animation: f1cIn .32s cubic-bezier(.2,1,.3,1) both; }
.f1c-row.user { flex-direction: row-reverse; }
@keyframes f1cIn { from { opacity: 0; transform: translateY(10px) scale(.98); } to { opacity: 1; transform: none; } }
.f1c-mini {
    width: 26px; height: 26px; border-radius: 9px; flex-shrink: 0; position: relative;
    background: linear-gradient(145deg, #eef2f7, #8ea0b8);
}
.f1c-mini::before { content: ''; position: absolute; inset: 4px; border-radius: 6px; background: #0b1120;
    background-image: radial-gradient(circle 2px at 33% 50%, #38bdf8 99%, transparent), radial-gradient(circle 2px at 67% 50%, #38bdf8 99%, transparent); }
.f1c-msg {
    max-width: 84%; padding: 11px 14px; border-radius: 18px;
    font-size: 13.5px; line-height: 1.75; white-space: pre-wrap; word-break: break-word;
}
.f1c-msg.bot {
    background: linear-gradient(160deg, rgba(30,41,59,.92), rgba(15,23,42,.92));
    border: 1px solid rgba(255,255,255,.07); color: #e5e7eb;
    border-end-start-radius: 6px;
    box-shadow: 0 6px 18px rgba(0,0,0,.25);
}
.f1c-msg.bot .hd { display: block; font-weight: 900; color: #fff; margin-bottom: 2px; }
.f1c-msg.user {
    background: linear-gradient(135deg, #7c3aed, #2563eb 60%, #0891b2); color: #fff;
    border-end-end-radius: 6px; font-weight: 600;
    box-shadow: 0 8px 22px rgba(124,58,237,.35);
}
.f1c-time { font-size: 10px; color: #64748b; font-weight: 700; margin: 0 34px; margin-top: -6px; }
.f1c-row.user + .f1c-time { text-align: end; margin: -6px 2px 0; }

/* option chips + tiles */
.f1c-options { display: flex; flex-wrap: wrap; gap: 7px; padding-inline-start: 34px; animation: f1cIn .4s ease both; }
.f1c-chip {
    position: relative; overflow: hidden;
    background: rgba(168,85,247,.12); border: 1px solid rgba(168,85,247,.35);
    color: #e9d5ff; border-radius: 999px; padding: 8px 14px;
    font-size: 12.5px; font-weight: 800; cursor: pointer; font-family: inherit;
    transition: background .15s, transform .12s, border-color .15s; white-space: nowrap;
}
.f1c-chip:hover { background: rgba(168,85,247,.25); border-color: rgba(196,181,253,.6); transform: translateY(-1px); }
.f1c-chip:active { transform: scale(.95); }
.f1c-chip.f1c-chip-back { background: rgba(255,255,255,.04); border-color: rgba(255,255,255,.12); color: #94a3b8; }
.f1c-tiles { display: grid; grid-template-columns: repeat(2, 1fr); gap: 9px; padding: 0 2px; animation: f1cIn .45s ease both; }
.f1c-tile {
    position: relative; overflow: hidden; text-align: start;
    border-radius: 18px; padding: 13px 13px 12px; cursor: pointer; font-family: inherit; color: #f1f5f9;
    background: linear-gradient(150deg, rgba(255,255,255,.07), rgba(255,255,255,.02));
    border: 1px solid rgba(255,255,255,.09);
    transition: transform .18s cubic-bezier(.2,1.2,.3,1), border-color .2s, box-shadow .2s;
    animation: f1cTileIn .5s cubic-bezier(.2,1.2,.3,1) both;
}
.f1c-tile:nth-child(2) { animation-delay: .05s; } .f1c-tile:nth-child(3) { animation-delay: .1s; } .f1c-tile:nth-child(4) { animation-delay: .15s; }
.f1c-tile:nth-child(5) { animation-delay: .2s; } .f1c-tile:nth-child(6) { animation-delay: .25s; } .f1c-tile:nth-child(n+7) { animation-delay: .3s; }
@keyframes f1cTileIn { from { opacity: 0; transform: translateY(14px) scale(.94); } }
.f1c-tile::before {
    content: ''; position: absolute; width: 90px; height: 90px; border-radius: 50%; top: -40px; <?= $__isAr ? 'left' : 'right' ?>: -30px;
    background: var(--tc, #a855f7); opacity: .22; filter: blur(18px); transition: opacity .2s;
}
.f1c-tile:hover { transform: translateY(-3px); border-color: color-mix(in srgb, var(--tc, #a855f7) 60%, transparent); box-shadow: 0 12px 26px rgba(0,0,0,.35), 0 0 22px color-mix(in srgb, var(--tc, #a855f7) 30%, transparent); }
.f1c-tile:hover::before { opacity: .4; }
.f1c-tile:active { transform: scale(.96); }
.f1c-tile .ic { display: grid; place-items: center; width: 36px; height: 36px; border-radius: 12px; font-size: 19px; margin-bottom: 9px;
    background: color-mix(in srgb, var(--tc, #a855f7) 22%, transparent); border: 1px solid color-mix(in srgb, var(--tc, #a855f7) 40%, transparent); }
.f1c-tile .lb { position: relative; display: block; font-size: 13px; font-weight: 800; line-height: 1.35; }

/* car cards */
.f1c-cards { display: flex; gap: 10px; overflow-x: auto; padding: 4px 2px 10px 34px; scroll-snap-type: x mandatory; scrollbar-width: none; animation: f1cIn .45s ease both; }
[dir="rtl"] .f1c-cards { padding: 4px 34px 10px 2px; }
.f1c-cards::-webkit-scrollbar { display: none; }
.f1c-card {
    flex: 0 0 180px; scroll-snap-align: start; text-decoration: none; color: inherit;
    border-radius: 18px; overflow: hidden; position: relative;
    background: linear-gradient(170deg, rgba(30,41,59,.95), rgba(10,15,30,.95));
    border: 1px solid rgba(255,255,255,.08);
    box-shadow: 0 10px 24px rgba(0,0,0,.35);
    transition: transform .2s cubic-bezier(.2,1.2,.3,1), border-color .2s;
    animation: f1cTileIn .5s cubic-bezier(.2,1.2,.3,1) both;
}
.f1c-card:nth-child(2) { animation-delay: .07s; } .f1c-card:nth-child(3) { animation-delay: .14s; } .f1c-card:nth-child(n+4) { animation-delay: .2s; }
.f1c-card:hover { transform: translateY(-4px); border-color: rgba(56,189,248,.5); }
.f1c-card .ph {
    position: relative; height: 100px; display: grid; place-items: center; overflow: hidden;
    background: radial-gradient(120% 90% at 50% 110%, rgba(56,189,248,.35), transparent 60%), linear-gradient(180deg, #1e293b, #0b1120);
}
.f1c-card .ph img { width: 100%; height: 100%; object-fit: contain; padding: 8px 10px 4px; filter: drop-shadow(0 8px 10px rgba(0,0,0,.45)); }
.f1c-card .ph svg { width: 118px; opacity: .85; }
.f1c-card .ph::after { content: ''; position: absolute; left: 12%; right: 12%; bottom: 8px; height: 8px; border-radius: 50%; background: radial-gradient(rgba(0,0,0,.5), transparent 70%); }
.f1c-card .st {
    position: absolute; top: 8px; <?= $__isAr ? 'right' : 'left' ?>: 8px; z-index: 1;
    font-size: 10.5px; font-weight: 900; padding: 3px 9px; border-radius: 999px; backdrop-filter: blur(6px);
}
.f1c-card .st.available { background: rgba(34,197,94,.22); color: #86efac; border: 1px solid rgba(34,197,94,.4); }
.f1c-card .st.reserved { background: rgba(245,158,11,.22); color: #fcd34d; border: 1px solid rgba(245,158,11,.4); }
.f1c-card .st.consignment { background: rgba(56,189,248,.22); color: #7dd3fc; border: 1px solid rgba(56,189,248,.4); }
.f1c-card .st.sold { background: rgba(244,63,94,.2); color: #fda4af; border: 1px solid rgba(244,63,94,.4); }
.f1c-card .bd { padding: 10px 12px 12px; }
.f1c-card .tt { font-size: 13.5px; font-weight: 900; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.f1c-card .sb { font-size: 11.5px; color: #a5b4fc; font-weight: 700; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.f1c-card .mt { display: flex; flex-wrap: wrap; gap: 4px 8px; margin-top: 7px; font-size: 11px; color: #94a3b8; font-weight: 700; }
.f1c-card .ch { margin-top: 7px; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 11px; color: #a3e635; letter-spacing: .5px; direction: ltr; text-align: start; }
.f1c-card .go { position: absolute; bottom: 10px; <?= $__isAr ? 'left' : 'right' ?>: 10px; font-size: 13px; color: #38bdf8; opacity: .7; }

/* thinking indicator */
#f1c-chat-typing { display: none; padding: 0 14px 10px; }
#f1c-chat-typing.on { display: flex; gap: 8px; align-items: flex-end; animation: f1cIn .25s ease both; }
.f1c-think {
    position: relative; overflow: hidden; display: inline-flex; align-items: center; gap: 8px;
    padding: 11px 16px; border-radius: 18px; border-end-start-radius: 6px;
    background: linear-gradient(160deg, rgba(30,41,59,.92), rgba(15,23,42,.92)); border: 1px solid rgba(255,255,255,.07);
    font-size: 12px; color: #94a3b8; font-weight: 800;
}
.f1c-think::after { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, transparent, rgba(56,189,248,.18), transparent); transform: translateX(-100%); animation: f1cScan 1.3s linear infinite; }
@keyframes f1cScan { to { transform: translateX(100%); } }
.f1c-think b { display: inline-flex; gap: 4px; }
.f1c-think b i { width: 6px; height: 6px; border-radius: 50%; background: var(--f1c-c); animation: f1cBounce 1.1s infinite; }
.f1c-think b i:nth-child(2) { animation-delay: .15s; background: var(--f1c-b); } .f1c-think b i:nth-child(3) { animation-delay: .3s; background: var(--f1c-a); }
@keyframes f1cBounce { 0%, 60%, 100% { transform: translateY(0); opacity: .5; } 30% { transform: translateY(-5px); opacity: 1; } }

/* suggestion ticker */
.f1c-sugg { display: flex; gap: 6px; overflow-x: auto; padding: 8px 12px 0; scrollbar-width: none; mask-image: linear-gradient(90deg, transparent, #000 14px, #000 calc(100% - 14px), transparent); }
.f1c-sugg::-webkit-scrollbar { display: none; }
.f1c-sugg button {
    flex-shrink: 0; border: 1px dashed rgba(148,163,184,.3); background: transparent; color: #94a3b8;
    font-family: inherit; font-size: 11.5px; font-weight: 700; padding: 6px 11px; border-radius: 999px; cursor: pointer; transition: color .15s, border-color .15s;
}
.f1c-sugg button:hover { color: #e2e8f0; border-color: rgba(196,181,253,.6); }

/* input bar */
#f1c-chat-inputbar { display: flex; gap: 8px; align-items: center; padding: 10px 12px calc(12px + env(safe-area-inset-bottom, 0px)); }
.f1c-field {
    flex: 1; display: flex; align-items: center; gap: 6px; min-width: 0;
    background: rgba(15,23,42,.9); border: 1px solid rgba(255,255,255,.1); border-radius: 18px; padding: 4px 6px 4px 14px;
    transition: border-color .2s, box-shadow .2s;
}
[dir="rtl"] .f1c-field { padding: 4px 14px 4px 6px; }
.f1c-field:focus-within { border-color: rgba(168,85,247,.6); box-shadow: 0 0 0 4px rgba(168,85,247,.14), 0 0 26px rgba(168,85,247,.2); }
#f1c-chat-input { flex: 1; min-width: 0; background: transparent; border: 0; color: #fff; font-size: 14.5px; font-family: inherit; outline: none; padding: 9px 0; }
#f1c-chat-input::placeholder { color: #64748b; }
.f1c-mic {
    position: relative; width: 38px; height: 38px; border-radius: 13px; border: 0; cursor: pointer; flex-shrink: 0;
    background: rgba(255,255,255,.06); color: #cbd5e1; display: grid; place-items: center; transition: background .2s;
}
.f1c-mic svg { width: 18px; height: 18px; }
.f1c-mic:hover { background: rgba(255,255,255,.12); }
.f1c-mic.on { background: linear-gradient(135deg, #ef4444, #f97316); color: #fff; }
.f1c-mic.on::before, .f1c-mic.on::after { content: ''; position: absolute; inset: -4px; border-radius: 16px; border: 2px solid rgba(239,68,68,.55); animation: f1cMicRing 1.2s ease-out infinite; }
.f1c-mic.on::after { animation-delay: .6s; }
@keyframes f1cMicRing { from { transform: scale(.9); opacity: 1; } to { transform: scale(1.35); opacity: 0; } }
#f1c-chat-send {
    width: 48px; height: 48px; border-radius: 16px; border: 0; cursor: pointer; flex-shrink: 0; color: #fff;
    background: linear-gradient(135deg, #22c55e, #0ea5e9 55%, #a855f7); background-size: 180% 180%;
    display: grid; place-items: center;
    box-shadow: 0 10px 24px rgba(14,165,233,.35);
    transition: transform .15s, box-shadow .2s, background-position .4s;
}
#f1c-chat-send svg { width: 20px; height: 20px; <?= $__isAr ? 'transform: scaleX(-1);' : '' ?> }
#f1c-chat-send:hover { background-position: 100% 0; transform: translateY(-1px); box-shadow: 0 14px 30px rgba(168,85,247,.4); }
#f1c-chat-send:active { transform: scale(.92); }
#f1c-chat-send:disabled { opacity: .5; cursor: default; }
.f1c-listen { display: none; align-items: center; gap: 3px; height: 20px; }
.f1c-field.listening .f1c-listen { display: inline-flex; }
.f1c-field.listening #f1c-chat-input::placeholder { color: #fca5a5; }
.f1c-listen i { width: 3px; border-radius: 3px; background: #f87171; animation: f1cWave .9s ease-in-out infinite; }
.f1c-listen i:nth-child(1) { height: 8px; } .f1c-listen i:nth-child(2) { height: 16px; animation-delay: .15s; } .f1c-listen i:nth-child(3) { height: 11px; animation-delay: .3s; } .f1c-listen i:nth-child(4) { height: 18px; animation-delay: .45s; }
@keyframes f1cWave { 50% { transform: scaleY(.35); } }

/* phones: a full-height sheet */
@media (max-width: 560px) {
    #f1c-chat-panel { left: 8px !important; right: 8px !important; width: auto; bottom: calc(8px + env(safe-area-inset-bottom, 0px)); height: calc(100dvh - 24px - env(safe-area-inset-top, 0px)); border-radius: 24px; transform-origin: bottom center; }
    #f1c-chat-panel::after, .f1c-in { border-radius: 24px; }
    #f1c-chat-panel::before { border-radius: 25.5px; }
    .f1c-msg { font-size: 14.5px; }
}
@media (prefers-reduced-motion: reduce) {
    #f1c-chat-panel::before, .f1c-orb, .f1c-orb::before, .f1c-orb::after, .f1c-hero h2 { animation: none; }
    .f1c-row, .f1c-tile, .f1c-card, .f1c-options { animation: none; }
}
@media print { #f1c-chat-bubble, #f1c-chat-panel { display: none !important; } }
</style>

<!-- ═════════ the robot ═════════ -->
<div id="f1c-chat-bubble" onclick="F1CChat.toggle()" title="<?= $__isAr ? 'مساعد المخزون' : 'Stock Assistant' ?>">
    <div class="f1c-bot-body">
        <div class="f1c-bot-ant"><span class="f1c-ping"></span></div>
        <div class="f1c-bot-head">
            <span class="f1c-bot-glare"></span>
            <div class="f1c-bot-face">
                <span class="f1c-eyes">
                    <span class="f1c-bot-eye"></span>
                    <span class="f1c-bot-eye f1c-e2"></span>
                </span>
                <span class="f1c-cheek f1c-cl"></span>
                <span class="f1c-cheek f1c-cr"></span>
            </div>
        </div>
        <div style="position:relative">
            <span class="f1c-bot-sleeve f1c-sl"></span>
            <span class="f1c-bot-sleeve f1c-sr"></span>
            <div class="f1c-bot-torso">
                <span class="f1c-bot-collar"></span>
                <img class="f1c-bot-logo" src="pwa/icon-192.png" alt=""
                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                <span class="f1c-bot-logo-txt">FIRST 1 CAR</span>
            </div>
        </div>
        <div class="f1c-bot-jets">
            <span class="f1c-jet"></span>
            <span class="f1c-jet f1c-j2"></span>
        </div>
    </div>
    <div class="f1c-bot-shadow"></div>
    <!-- car transform mode (hidden until he transforms) -->
    <div class="f1c-car" aria-hidden="true">
        <div class="f1c-car-roof"></div>
        <div class="f1c-car-win"></div>
        <div class="f1c-car-body"></div>
        <div class="f1c-car-stripe"></div>
        <div class="f1c-car-light"></div>
        <div class="f1c-wheel2 f1c-w-back"></div>
        <div class="f1c-wheel2 f1c-w-front"></div>
    </div>
    <!-- speech bubble travels with the robot -->
    <div id="f1c-bot-say"></div>
</div>
<div id="f1c-chat-panel" role="dialog" aria-label="<?= $__isAr ? 'المساعد الذكي' : 'Smart assistant' ?>">
  <div class="f1c-in">
    <div id="f1c-chat-header">
        <div class="f1c-ava" id="f1cAva"><i></i><i></i><b></b></div>
        <div class="f1c-h-txt">
            <div class="f1c-h-title"><?= $__isAr ? 'مساعد فيرست 1 كار' : 'First 1 Car Assistant' ?></div>
            <div class="f1c-h-sub"><?= $__isAr ? 'متصل · بيانات حية من المخزون' : 'Online · live stock data' ?></div>
        </div>
        <button type="button" class="f1c-hb" id="f1cNew" title="<?= $__isAr ? 'محادثة جديدة' : 'New chat' ?>">✨</button>
        <button type="button" class="f1c-hb f1c-exp" id="f1cExp" title="<?= $__isAr ? 'تكبير' : 'Expand' ?>">⤢</button>
        <button type="button" class="f1c-hb" id="f1c-chat-close" onclick="F1CChat.toggle()" title="<?= $__isAr ? 'إغلاق' : 'Close' ?>">✕</button>
    </div>
    <div id="f1c-chat-messages" aria-live="polite"></div>
    <div id="f1c-chat-typing"><span class="f1c-mini"></span><span class="f1c-think"><b><i></i><i></i><i></i></b><span id="f1cThinkTxt"></span></span></div>
    <div class="f1c-sugg" id="f1cSugg"></div>
    <div id="f1c-chat-inputbar">
        <div class="f1c-field" id="f1cField">
            <span class="f1c-listen" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
            <input id="f1c-chat-input" type="text" enterkeyhint="send" autocomplete="off" placeholder="<?= $__isAr ? 'اسأل عن أي عربية، سعر، أو رقم شاسيه…' : 'Ask about any car, price or chassis…' ?>" />
            <button type="button" class="f1c-mic" id="f1cMic" title="<?= $__isAr ? 'اسأل بصوتك' : 'Ask by voice' ?>" aria-label="<?= $__isAr ? 'اسأل بصوتك' : 'Ask by voice' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/></svg>
            </button>
        </div>
        <button id="f1c-chat-send" onclick="F1CChat.send()" aria-label="<?= $__isAr ? 'إرسال' : 'Send' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h13M13 6l6 6-6 6"/></svg>
        </button>
    </div>
  </div>
</div>

<script>
const F1CChat = {
    opened: false, started: false,
    lang: <?= json_encode($__lang) ?>,
    me: <?= json_encode((string)($_SESSION['username'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
    ctx: {},
    log: [],          // what's on screen, kept for this login (sessionStorage)
    lastAct: Date.now(),

    T: <?= json_encode($__isAr ? [
        'morning' => 'صباح الخير', 'evening' => 'مساء الخير', 'night' => 'سهرانين',
        'hero' => 'أنا مساعدك الذكي — اسألني عن المخزون، الأسعار، الشاسيه، أو حضورك.',
        'thinking' => ['بدوّر في المخزون…', 'بجمع البيانات…', 'ثانية واحدة…', 'بفكّر…'],
        'listening' => 'بسمعك… اتكلم',
        'noVoice' => 'المتصفح ده مش بيدعم الكلام — جرّب Chrome 🎤',
        'err' => 'حصل خطأ، حاول تاني.', 'net' => 'مشكلة في الاتصال 📡',
        'sugg' => ['في تيجو 7 أسود؟', 'بكام الامجراند؟', 'قارن تيجو 7 وتيجو 8', 'كل حاجة عن جوليون', 'الأكثر رواجاً', 'ساعاتي الأسبوع ده'],
        'suggAdmin' => ['مين في الشغل؟', 'مين ما جاش النهارده؟'],
    ] : [
        'morning' => 'Good morning', 'evening' => 'Good evening', 'night' => 'Working late',
        'hero' => "I'm your smart assistant — ask me about stock, prices, chassis numbers or your attendance.",
        'thinking' => ['Searching the stock…', 'Pulling the data…', 'One second…', 'Thinking…'],
        'listening' => 'Listening… speak now',
        'noVoice' => "This browser can't do speech — try Chrome 🎤",
        'err' => 'Something went wrong.', 'net' => 'Connection problem 📡',
        'sugg' => ['black Tiggo 7?', 'Emgrand price?', 'compare Tiggo 7 and Tiggo 8', 'everything about Jolion', "what's hot", 'my hours this week'],
        'suggAdmin' => ["who's at work?", "who didn't come today?"],
    ], JSON_UNESCAPED_UNICODE) ?>,
    isAdmin: <?= json_encode(function_exists('can') && can('page.attendance_admin')) ?>,

    key() { return 'f1cChat4:' + this.me; },
    save() {
        try { sessionStorage.setItem(this.key(), JSON.stringify({ ctx: this.ctx, log: this.log.slice(-40) })); } catch (e) {}
    },
    restore() {
        try {
            const d = JSON.parse(sessionStorage.getItem(this.key()) || 'null');
            if (d && Array.isArray(d.log) && d.log.length) { this.ctx = d.ctx || {}; this.log = d.log; return true; }
        } catch (e) {}
        return false;
    },

    toggle() {
        this.landNow();
        const panel = document.getElementById('f1c-chat-panel');
        this.opened = !this.opened;
        if (this.opened && (this.pos.x !== 0 || this.pos.y !== 0)) {
            /* snap the robot home behind the opening panel */
            const el = document.getElementById('f1c-chat-bubble');
            el.style.transform = 'translate(0,0)';
            this.pos = { x: 0, y: 0 };
        }
        if (this.opened) { panel.classList.add('open'); requestAnimationFrame(() => requestAnimationFrame(() => panel.classList.add('show'))); }
        else { panel.classList.remove('show'); setTimeout(() => { if (!this.opened) panel.classList.remove('open'); }, 260); }
        document.getElementById('f1c-chat-bubble').classList.toggle('f1c-hidden', this.opened);
        this.hideGreeting();
        if (this.opened && !this.started) {
            this.started = true;
            if (this.restore()) this.replay();
            else { this.hero(); this.call({}); }
            this.renderSugg();
        }
        if (this.opened && window.innerWidth > 560) setTimeout(() => document.getElementById('f1c-chat-input').focus(), 300);
    },

    fromGreeting() { this.hideGreeting(); if (!this.opened) this.toggle(); },

    hideGreeting() {
        const say = document.getElementById('f1c-bot-say');
        if (say) say.classList.remove('show');
    },

    /* ══ MASCOT BRAIN v6: calmer, and steps aside for notifications ══ */
    MSGS: <?= json_encode($__isAr ? [
        'أهلاً! 🤖 أنا مساعد فيرست 1 كار — اسألني عن أي عربية ✨',
        'استخدمتني النهارده؟ 😄 جرّبني!',
        'فيرست 1 كار الأفضل! 🏆🚗',
        'اكتبلي: "في تيجو 7 أسود؟" وشوف السحر 🔮',
        'عندي كل الأسعار محدّثة لحظة بلحظة 💰',
        'ابعتلي رقم شاسيه وأجيبلك العربية في ثانية 🔍',
        'قول "كل حاجة عن جوليون" وهعملك ملف كامل 🧠',
        'قارنلك بين موديلين؟ قول "قارن تيجو 7 وتيجو 8" ⚖️',
        'تقدر تسألني بصوتك كمان 🎤',
        'عايز تعرف ساعاتك الأسبوع ده؟ اسألني ⏱️',
        'شفت القادم في الطريق؟ اسألني عن الشحنات 🚚',
        'مفيش عربية تخبى عني 👀',
        'يلا نبيع عربيات! 💪',
    ] : [
        "Hey! 🤖 I'm the First 1 Car assistant — ask me about any car ✨",
        'Did you use me yet today? 😄 Try me!',
        'First 1 Car is the best! 🏆🚗',
        'Type: "black Tiggo 7?" and watch the magic 🔮',
        'All prices, live and up to date 💰',
        'Send me a chassis number — car found in a second 🔍',
        'Say "everything about Jolion" for a full profile 🧠',
        'Compare models? Say "compare Tiggo 7 and Tiggo 8" ⚖️',
        'You can ask me by voice too 🎤',
        'Want your hours this week? Just ask ⏱️',
        'Curious what\'s arriving? Ask me about shipments 🚚',
        'No car can hide from me 👀',
        "Let's sell some cars! 💪",
    ], JSON_UNESCAPED_UNICODE) ?>,
    SLEEP_MSGS: <?= json_encode($__isAr
        ? ['Zzz... 😴', 'ثواني بشحن... 🔋😴', 'غفوة سريعة... 💤']
        : ['Zzz... 😴', 'Quick recharge... 🔋😴', 'Power nap... 💤'], JSON_UNESCAPED_UNICODE) ?>,
    WAKE_MSGS: <?= json_encode($__isAr
        ? ['صحيت! 😄 محتاج حاجة؟', 'رجعت بطاقة 100% 🔋⚡', 'كنت بحلم بتيجو 7 🚗💭']
        : ["I'm up! 😄 Need anything?", 'Back at 100% battery 🔋⚡', 'I was dreaming of a Tiggo 7 🚗💭'], JSON_UNESCAPED_UNICODE) ?>,
    PARTY_MSGS: <?= json_encode($__isAr
        ? ['فيرست 1 كار الأفضل! 🎆🏆', 'يلا نكسّر الدنيا مبيعات! 🎇💪', 'أحلى فريق وأحلى عربيات 🎉🚗']
        : ['First 1 Car is the best! 🎆🏆', "Let's crush it today! 🎇💪", 'Best team, best cars 🎉🚗'], JSON_UNESCAPED_UNICODE) ?>,
    MORNING: <?= json_encode($__isAr ? 'صباح الفل! ☀️ يلا يوم مبيعات جامد' : 'Good morning! ☀️ Big sales day ahead', JSON_UNESCAPED_UNICODE) ?>,
    NIGHT:   <?= json_encode($__isAr ? 'سهرانين؟ 🌙 أنا معاك' : 'Working late? 🌙 I\'m with you', JSON_UNESCAPED_UNICODE) ?>,

    pos: { x: 0, y: 0 },
    _lastMsg: -1,

    randMsg(arr) {
        if (arr.length < 2) return arr[0];
        let i;
        do { i = Math.floor(Math.random() * arr.length); } while (i === this._lastMsg);
        this._lastMsg = i;
        return arr[i];
    },

    /* notification cards / the "turn on notifications" card are on screen → keep out of their way */
    screenBusy() {
        return !!document.querySelector('#ntStack .nt-card:not(.out), #ntOv.on, #npCard.on, .ov.on');
    },

    say(text, ms) {
        const el = document.getElementById('f1c-bot-say');
        if (!el || this.opened || this.screenBusy()) return;
        el.textContent = text;
        el.classList.add('show');
        clearTimeout(this._sayT);
        this._sayT = setTimeout(() => el.classList.remove('show'), ms || 7500);
    },

    greet() {
        let greeted = false;
        try {
            greeted = !!sessionStorage.getItem('f1cBotGreeted');
            sessionStorage.setItem('f1cBotGreeted', '1');
        } catch (e) {}
        if (!greeted) {
            const h = new Date().getHours();
            const first = (h >= 5 && h < 12) ? this.MORNING : (h >= 21 || h < 5) ? this.NIGHT : this.MSGS[0];
            setTimeout(() => this.say(first, 8500), 2600);
        }
        ['pointerdown', 'keydown', 'scroll', 'touchstart'].forEach(ev => addEventListener(ev, () => { this.lastAct = Date.now(); }, { passive: true, capture: true }));
        document.addEventListener('visibilitychange', () => { if (document.hidden) this.landNow(); });
        this.scheduleAct();
        this.watchScreen();
    },

    /* ── behavior engine: a calm act every 30-60s, never while someone is busy ── */
    scheduleAct() {
        const wait = 30000 + Math.random() * 30000;
        setTimeout(() => {
            const idle = Date.now() - this.lastAct > 15000;
            if (!this.opened && !document.hidden && !this._flying && !this._sleeping && idle && !this.screenBusy()) this.act();
            this.scheduleAct();
        }, wait);
    },

    /* when notification cards show up, the robot flies home (they live on the other side) */
    watchScreen() {
        setInterval(() => {
            if (this.screenBusy()) {
                this.hideGreeting();
                if (!this._flying && (this.pos.x !== 0 || this.pos.y !== 0)) this.goHome();
            }
        }, 1200);
    },

    act() {
        const r = Math.random();
        if      (r < 0.26) this.wander();
        else if (r < 0.42) this.carDrive();        /* 🤖→🚗 transform + drive */
        else if (r < 0.60) this.say(this.randMsg(this.MSGS));
        else if (r < 0.72) this.sleep();
        else if (r < 0.82) this.party();
        else if (r < 0.92) this.trick();
        else               this.goHome();
    },

    /* ── 🤖→🚗 transform into a car and drive across the screen, then back ── */
    carDrive() {
        const el = document.getElementById('f1c-chat-bubble');
        if (!el || this._flying || this.opened) return;
        this._flying = true;
        this.hideGreeting();
        const isAr = this.lang === 'ar';
        const vw   = window.innerWidth;
        const dir  = -1;   /* the robot lives on the right → drives to the left and back */
        const far  = dir * Math.max(60, vw - 118);
        const car  = el.querySelector('.f1c-car');
        const hype = this.randMsg(isAr
            ? ['وروووم! 🚗💨 فيرست 1 كار!', 'اتحولت لعربية! 🚗', 'أسرع معرض في المدينة 🏁']
            : ['Vroom! 🚗💨 First 1 Car!', 'Transformed! 🚗', 'Fastest dealer in town 🏁']);
        el.classList.add('f1c-party');
        setTimeout(() => {
            el.classList.remove('f1c-party');
            el.classList.add('f1c-ascar');
            if (car) car.classList.toggle('f1c-face-left', dir < 0);
            this.say(hype, 4000);
            const home = 'translate(0px,0px)', away = 'translate(' + far + 'px,0px)';
            const a1 = el.animate([{ transform: home }, { transform: away }], { duration: 1500, easing: 'ease-in-out' });
            this._anim = a1;
            a1.onfinish = () => {
                if (car) car.classList.toggle('f1c-face-left', dir > 0);
                const a2 = el.animate([{ transform: away }, { transform: home }], { duration: 1500, easing: 'ease-in-out' });
                this._anim = a2;
                a2.onfinish = a2.oncancel = () => {
                    el.style.transform = home;
                    this.pos = { x: 0, y: 0 };
                    el.classList.add('f1c-party');
                    el.classList.remove('f1c-ascar');
                    if (car) car.classList.remove('f1c-face-left');
                    setTimeout(() => el.classList.remove('f1c-party'), 900);
                    this._flying = false;
                };
            };
        }, 500);
    },

    /* fly to a waypoint on his own (right) side and stay there — never over the notifications (left) */
    wander(target) {
        const el = document.getElementById('f1c-chat-bubble');
        if (!el || this._flying) return;
        this._flying = true;
        this.hideGreeting();
        const vw    = window.innerWidth;
        const phone = vw < 560;
        const maxX  = Math.max(60, vw - 118);
        const maxY  = Math.max(120, window.innerHeight - 230);
        let tx, ty, tries = 0;
        if (target) { tx = target.x; ty = target.y; }
        else {
            do {
                tx = phone ? 0 : -(Math.random() * maxX * 0.35);      /* right third of the screen */
                ty = -(60 + Math.random() * (maxY - 60));
                tries++;
            } while (tries < 6 && Math.hypot(tx - this.pos.x, ty - this.pos.y) < 130);
        }
        const dist = Math.hypot(tx - this.pos.x, ty - this.pos.y);
        const dur  = Math.min(2800, Math.max(900, dist * 3.2));
        el.style.setProperty('--f1c-tilt', ((tx - this.pos.x) > 0 ? 9 : -9) + 'deg');
        el.classList.add('f1c-flying');
        const from = `translate(${this.pos.x}px, ${this.pos.y}px)`;
        const to   = `translate(${tx}px, ${ty}px)`;
        const anim = el.animate([{ transform: from }, { transform: to }], { duration: dur, easing: 'ease-in-out' });
        this._anim = anim;
        anim.onfinish = anim.oncancel = () => {
            el.style.transform = to;
            this.pos = { x: tx, y: ty };
            el.classList.remove('f1c-flying');
            el.classList.toggle('f1c-far', Math.abs(tx) > vw * 0.5);
            this._flying = false;
            if (!this.opened && !target && Math.random() < 0.4) setTimeout(() => this.say(this.randMsg(this.MSGS), 6500), 400);
        };
    },

    goHome() { this.wander({ x: 0, y: 0 }); },

    sleep() {
        const el = document.getElementById('f1c-chat-bubble');
        if (!el) return;
        this._sleeping = true;
        el.classList.add('f1c-sleep');
        this.say(this.randMsg(this.SLEEP_MSGS), 12000);
        setTimeout(() => {
            el.classList.remove('f1c-sleep');
            this._sleeping = false;
            if (!this.opened) this.say(this.randMsg(this.WAKE_MSGS), 5500);
        }, 7000 + Math.random() * 5000);
    },

    party() {
        const el = document.getElementById('f1c-chat-bubble');
        if (!el) return;
        el.classList.add('f1c-party');
        setTimeout(() => el.classList.remove('f1c-party'), 950);
        const colors = ['#22c55e', '#38bdf8', '#a855f7', '#f59e0b', '#f43f5e'];
        for (let i = 0; i < 16; i++) {
            const sp = document.createElement('span');
            sp.className = 'f1c-spark';
            const ang = Math.random() * Math.PI * 2;
            const d = 45 + Math.random() * 55;
            sp.style.setProperty('--dx', Math.cos(ang) * d + 'px');
            sp.style.setProperty('--dy', (Math.sin(ang) * d - 25) + 'px');
            sp.style.setProperty('--clr', colors[i % colors.length]);
            sp.style.animationDelay = (Math.random() * .18) + 's';
            el.appendChild(sp);
            setTimeout(() => sp.remove(), 1400);
        }
        this.say(this.randMsg(this.PARTY_MSGS), 6500);
    },

    trick() {
        const el = document.getElementById('f1c-chat-bubble');
        if (!el) return;
        el.classList.add('f1c-party');
        setTimeout(() => el.classList.remove('f1c-party'), 950);
    },

    landNow() {
        if (this._anim && this._flying) { try { this._anim.cancel(); } catch (e) {} }
        const el = document.getElementById('f1c-chat-bubble');
        if (el) {
            const wasCar = el.classList.contains('f1c-ascar');
            el.classList.remove('f1c-flying', 'f1c-sleep', 'f1c-party', 'f1c-far', 'f1c-ascar');
            const car = el.querySelector('.f1c-car');
            if (car) car.classList.remove('f1c-face-left');
            if (wasCar) { el.style.transform = 'translate(0,0)'; this.pos = { x: 0, y: 0 }; }
        }
        this._flying = false; this._sleeping = false;
    },

    /* ═════════ chat rendering ═════════ */
    box() { return document.getElementById('f1c-chat-messages'); },
    scroll() { const b = this.box(); requestAnimationFrame(() => { b.scrollTop = b.scrollHeight; }); },
    clock() { const d = new Date(); let h = d.getHours(); const ap = this.lang === 'ar' ? (h < 12 ? 'ص' : 'م') : (h < 12 ? 'AM' : 'PM'); h = h % 12 || 12; return h + ':' + String(d.getMinutes()).padStart(2, '0') + ' ' + ap; },

    hero(replay) {
        const h = new Date().getHours();
        const hi = (h >= 5 && h < 12) ? this.T.morning : (h >= 21 || h < 5) ? this.T.night : this.T.evening;
        const d = document.createElement('div');
        d.className = 'f1c-hero';
        d.innerHTML = '<div class="f1c-orb"><span>🤖</span></div><h2></h2><p></p>';
        d.querySelector('h2').textContent = hi + (this.me ? '، ' + this.me : '') + ' 👋';
        if (this.lang !== 'ar') d.querySelector('h2').textContent = hi + (this.me ? ', ' + this.me : '') + ' 👋';
        d.querySelector('p').textContent = this.T.hero;
        this.box().appendChild(d);
        if (!replay) this.log.push({ t: 'hero' });
    },

    addText(who, text, opts) {
        opts = opts || {};
        const row = document.createElement('div');
        row.className = 'f1c-row ' + who;
        if (who === 'bot') row.appendChild(Object.assign(document.createElement('span'), { className: 'f1c-mini' }));
        const div = document.createElement('div');
        div.className = 'f1c-msg ' + who;
        div.dir = /[؀-ۿ]/.test(text) ? 'rtl' : (this.lang === 'ar' ? 'rtl' : 'ltr');
        row.appendChild(div);
        this.box().appendChild(row);
        const tm = document.createElement('div'); tm.className = 'f1c-time'; tm.textContent = opts.time || this.clock();
        this.box().appendChild(tm);
        if (who === 'bot' && opts.cards && text.split('\n').length > 6) this.compactText(div, text);
        else if (who === 'bot') this.botText(div, text, !opts.instant); else div.textContent = text;
        if (!opts.replay) { this.log.push({ t: 'text', who, text, time: tm.textContent, cards: !!opts.cards }); this.save(); }
        this.scroll();
    },

    /* cars are shown as cards → heading + summary, full list behind "details" */
    compactText(div, text) {
        const lines = text.split('\n');
        const si = lines.findIndex(l => /^(الخلاصة|Summary)\s*:/.test(l.trim()));
        const summary = si >= 0 ? lines.slice(si + 1).join('\n') : '';
        const hd = Object.assign(document.createElement('span'), { className: 'hd', textContent: lines[0] });
        const sh = Object.assign(document.createElement('span'), { className: 'short', textContent: summary });
        const full = Object.assign(document.createElement('span'), { className: 'full', textContent: lines.slice(1).join('\n') });
        const more = Object.assign(document.createElement('button'), { type: 'button', className: 'f1c-more', textContent: this.lang === 'ar' ? '▾ التفاصيل' : '▾ Details' });
        more.onclick = () => { const on = div.classList.toggle('expanded'); more.textContent = on ? (this.lang === 'ar' ? '▴ إخفاء' : '▴ Hide') : (this.lang === 'ar' ? '▾ التفاصيل' : '▾ Details'); };
        div.append(hd, sh, full, more);
    },

    /* first line bold as a heading, then a quick "typing" reveal */
    botText(div, text, animate) {
        const nl = text.indexOf('\n');
        const head = nl > 0 && nl < 70 ? text.slice(0, nl) : '';
        const body = head ? text.slice(nl + 1) : text;
        const hd = document.createElement('span'); hd.className = 'hd';
        const bd = document.createElement('span');
        if (head) div.appendChild(hd);
        div.appendChild(bd);
        if (!animate || matchMedia('(prefers-reduced-motion: reduce)').matches) { hd.textContent = head; bd.textContent = body; return; }
        const full = head + '\u0000' + body, total = full.length, dur = Math.min(900, 180 + total * 4), t0 = performance.now();
        const step = (now) => {
            const n = Math.min(total, Math.ceil((now - t0) / dur * total));
            const shown = full.slice(0, n), cut = shown.indexOf('\u0000');
            if (head) { hd.textContent = cut >= 0 ? head : shown; bd.textContent = cut >= 0 ? shown.slice(cut + 1) : ''; }
            else bd.textContent = shown.replace('\u0000', '');
            if (n < total) { requestAnimationFrame(step); if (!this._pinTop) this.box().scrollTop = this.box().scrollHeight; }
            else if (this._pinTop) this.box().scrollTop = 0;
        };
        requestAnimationFrame(step);
    },

    TILE_COLORS: ['#22c55e', '#38bdf8', '#a855f7', '#f59e0b', '#f472b6', '#2dd4bf', '#fb923c', '#818cf8', '#facc15'],
    isMenu(options) { return options.length >= 5 && !options.some(o => o.keepModel); },

    addOptions(options, replay) {
        const menu = this.isMenu(options);
        const wrap = document.createElement('div');
        wrap.className = menu ? 'f1c-tiles' : 'f1c-options';
        options.forEach((opt, i) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            const isBack = (opt.field === '__reset__' || opt.value === null);
            if (menu && !isBack) {
                const m = String(opt.label).match(/^(\p{Extended_Pictographic}️?)\s*(.*)$/u);
                btn.className = 'f1c-tile';
                btn.style.setProperty('--tc', this.TILE_COLORS[i % this.TILE_COLORS.length]);
                btn.innerHTML = '<span class="ic"></span><span class="lb"></span>';
                btn.querySelector('.ic').textContent = m ? m[1] : '✨';
                btn.querySelector('.lb').textContent = m ? m[2] : opt.label;
            } else {
                btn.className = 'f1c-chip' + (isBack ? ' f1c-chip-back' : '');
                btn.textContent = opt.label;
            }
            btn.onclick = () => { wrap.querySelectorAll('button').forEach(b => b.disabled = true); this.tap(opt); };
            wrap.appendChild(btn);
        });
        this.box().appendChild(wrap);
        if (!replay) { this.log.push({ t: 'opts', options }); this.save(); }
        if (menu && this.box().querySelectorAll('.f1c-row.user').length === 0) {
            this._pinTop = true;                                   // first screen: greeting + tiles, from the top
            requestAnimationFrame(() => { this.box().scrollTop = 0; });
            setTimeout(() => { this._pinTop = false; }, 1000);
        }
        else this.scroll();
    },

    CAR_SVG: '<svg viewBox="0 0 120 50" fill="none"><defs><linearGradient id="f1cg" x1="0" x2="1"><stop offset="0" stop-color="#22c55e"/><stop offset=".5" stop-color="#38bdf8"/><stop offset="1" stop-color="#a855f7"/></linearGradient></defs><path d="M8 36c0-6 4-9 10-10l14-3 14-11c3-2 6-3 10-3h22c5 0 9 2 12 5l10 10 10 2c4 1 6 4 6 8v4c0 2-2 4-4 4H12c-2 0-4-2-4-4z" stroke="url(#f1cg)" stroke-width="2.4" fill="rgba(56,189,248,.08)"/><path d="M40 23l12-9c2-1 4-2 6-2h20c3 0 6 1 8 3l7 8z" stroke="url(#f1cg)" stroke-width="2" fill="rgba(168,85,247,.12)"/><circle cx="32" cy="42" r="7" fill="#0b1120" stroke="#64748b" stroke-width="2.4"/><circle cx="92" cy="42" r="7" fill="#0b1120" stroke="#64748b" stroke-width="2.4"/></svg>',

    addCards(cards, replay) {
        if (!cards || !cards.length) return;
        const wrap = document.createElement('div');
        wrap.className = 'f1c-cards';
        const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        cards.forEach(c => {
            const a = document.createElement('a');
            a.className = 'f1c-card';
            a.href = c.url;
            a.innerHTML = '<div class="ph">' + (c.img ? '<img loading="lazy" alt="" src="' + esc(c.img) + '">' : this.CAR_SVG) +
                '<span class="st ' + esc(c.status) + '">' + esc(c.statusL) + '</span></div>' +
                '<div class="bd"><div class="tt">' + esc(c.title) + '</div><div class="sb">' + esc(c.sub) + '</div>' +
                '<div class="mt"><span>🎨 ' + esc(c.color) + '</span><span>📍 ' + esc(c.branch) + '</span></div>' +
                (c.chassis ? '<div class="ch">🔩 ' + esc(c.chassis) + '</div>' : '') + '</div><span class="go">' + (this.lang === 'ar' ? '←' : '→') + '</span>';
            const img = a.querySelector('img');
            if (img) img.onerror = () => { img.outerHTML = this.CAR_SVG; };
            wrap.appendChild(a);
        });
        this.box().appendChild(wrap);
        if (!replay) { this.log.push({ t: 'cards', cards }); this.save(); }
        this.scroll();
    },

    replay() {
        this.log.forEach(e => {
            if (e.t === 'hero') this.hero(true);
            else if (e.t === 'text') this.addText(e.who, e.text, { replay: true, instant: true, time: e.time, cards: e.cards });
            else if (e.t === 'cards') this.addCards(e.cards, true);
        });
        const lastOpts = [...this.log].reverse().find(e => e.t === 'opts');
        if (lastOpts) this.addOptions(lastOpts.options, true);
    },

    newChat() {
        this.log = []; this.ctx = {};
        try { sessionStorage.removeItem(this.key()); } catch (e) {}
        this.box().innerHTML = '';
        this.hero(); this.call({});
    },

    renderSugg() {
        const s = document.getElementById('f1cSugg');
        const list = this.T.sugg.concat(this.isAdmin ? this.T.suggAdmin : []);
        s.innerHTML = '';
        list.forEach(q => {
            const b = document.createElement('button'); b.type = 'button'; b.textContent = q;
            b.onclick = () => { document.getElementById('f1c-chat-input').value = q; this.send(); };
            s.appendChild(b);
        });
    },

    tap(opt) { this.addText('user', opt.label); this.call({}, opt); },

    send() {
        const input = document.getElementById('f1c-chat-input');
        const text = input.value.trim();
        if (!text) return;
        this.stopVoice();
        this.addText('user', text);
        input.value = '';
        this.call({ text });
    },

    history() {
        return this.log.filter(e => e.t === 'text').slice(-7, -1).map(e => ({ role: e.who === 'user' ? 'user' : 'bot', text: e.text }));
    },

    async call(extra, tap) {
        const sendBtn = document.getElementById('f1c-chat-send');
        const typing = document.getElementById('f1c-chat-typing');
        const ava = document.getElementById('f1cAva');
        sendBtn.disabled = true;
        document.getElementById('f1cThinkTxt').textContent = this.T.thinking[Math.floor(Math.random() * this.T.thinking.length)];
        typing.classList.add('on'); ava.classList.add('think');
        this.scroll();
        const started = Date.now();
        try {
            const body = Object.assign({ lang: this.lang, ctx: this.ctx, hist: this.history() }, extra);
            if (tap) body.tap = tap;
            const res = await fetch('chatbot_api.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            const data = await res.json();
            const elapsed = Date.now() - started;
            if (elapsed < 450) await new Promise(r => setTimeout(r, 450 - elapsed));
            typing.classList.remove('on');
            this.ctx = data.ctx || {};
            if (data.cards && data.cards.length) this.addText('bot', data.message || this.T.err, { cards: true });
            else this.addText('bot', data.message || this.T.err);
            if (data.cards && data.cards.length) this.addCards(data.cards);
            if (data.options && data.options.length) this.addOptions(data.options);
            this.save();
        } catch (e) {
            typing.classList.remove('on');
            this.addText('bot', this.T.net);
        } finally {
            sendBtn.disabled = false;
            typing.classList.remove('on'); ava.classList.remove('think');
        }
    },

    /* ═════════ 🎤 voice questions (Chrome / Android; iPhone where Safari supports it) ═════════ */
    rec: null,
    toggleVoice() {
        if (this.rec) return this.stopVoice();
        const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        const input = document.getElementById('f1c-chat-input');
        if (!SR) { this.addText('bot', this.T.noVoice); return; }
        const r = new SR();
        r.lang = this.lang === 'ar' ? 'ar-EG' : 'en-US';
        r.interimResults = true; r.maxAlternatives = 1; r.continuous = false;
        const field = document.getElementById('f1cField'), mic = document.getElementById('f1cMic');
        const ph = input.placeholder;
        let finalText = '';
        r.onresult = (ev) => {
            let t = '';
            for (let i = ev.resultIndex; i < ev.results.length; i++) { t += ev.results[i][0].transcript; if (ev.results[i].isFinal) finalText = t; }
            input.value = finalText || t;
        };
        r.onend = () => {
            field.classList.remove('listening'); mic.classList.remove('on'); input.placeholder = ph; this.rec = null;
            if (input.value.trim()) this.send();
        };
        r.onerror = () => { field.classList.remove('listening'); mic.classList.remove('on'); input.placeholder = ph; this.rec = null; };
        this.rec = r;
        field.classList.add('listening'); mic.classList.add('on'); input.value = ''; input.placeholder = this.T.listening;
        try { r.start(); } catch (e) { r.onerror(); }
    },
    stopVoice() { if (this.rec) { try { this.rec.stop(); } catch (e) {} } },
};
document.getElementById('f1c-chat-input')?.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); F1CChat.send(); }
});
document.getElementById('f1cMic')?.addEventListener('click', () => F1CChat.toggleVoice());
document.getElementById('f1cNew')?.addEventListener('click', () => F1CChat.newChat());
document.getElementById('f1cExp')?.addEventListener('click', () => document.getElementById('f1c-chat-panel').classList.toggle('f1c-max'));
document.addEventListener('keydown', e => { if (e.key === 'Escape' && F1CChat.opened) F1CChat.toggle(); });
F1CChat.greet();
</script>
