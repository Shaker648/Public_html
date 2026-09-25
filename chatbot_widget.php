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
/* ═════════ THE ROBOT v5 — chrome body, holographic visor, hover ring ═════════ */
#f1c-chat-bubble {
    position: fixed;
    bottom: calc(76px + env(safe-area-inset-bottom, 0px) + 10px);
    <?= $__side ?>: 12px;
    width: 88px; height: 138px;
    cursor: pointer; z-index: 998;
    perspective: 560px;
    -webkit-tap-highlight-color: transparent;
    will-change: transform;
    --ex: 0px; --ey: 0px;
}
#f1c-chat-bubble.f1c-hidden { display: none; }
#f1c-chat-bubble.f1c-flying .f1c-bot-body { animation-play-state: paused; transform: rotateZ(var(--f1c-tilt, 0deg)); transition: transform .4s; }
#f1c-chat-bubble.f1c-flying .f1c-jet::after { animation-duration: .12s; height: 26px; filter: brightness(1.5) saturate(1.3); }
#f1c-chat-bubble.f1c-flying .f1c-bot-shadow { opacity: 0; transform: scale(.5); }
#f1c-chat-bubble.f1c-flying .f1c-aura { opacity: .35; }

/* soft aura behind him */
.f1c-aura {
    position: absolute; left: 50%; top: 18px; width: 110px; height: 110px; margin-left: -55px; border-radius: 50%;
    background: radial-gradient(circle, rgba(56,189,248,.34), rgba(168,85,247,.18) 45%, transparent 70%);
    filter: blur(6px); pointer-events: none; transition: opacity .4s;
    animation: f1cAura 4.5s ease-in-out infinite;
}
@keyframes f1cAura { 50% { transform: scale(1.12); opacity: .7; } }

.f1c-bot-body {
    position: relative; width: 66px; margin: 12px auto 0;
    transform-style: preserve-3d;
    animation: f1cFloat 3.6s ease-in-out infinite;
    transition: transform .25s cubic-bezier(.2,1.4,.4,1);
}
@keyframes f1cFloat {
    0%, 100% { transform: translateY(0)    rotateY(-10deg) rotateZ(-1.5deg); }
    50%      { transform: translateY(-9px) rotateY(10deg)  rotateZ(1.5deg); }
}
@media (hover: hover) { #f1c-chat-bubble:hover .f1c-bot-body { animation-play-state: paused; transform: translateY(-6px) scale(1.07) rotateY(0); } }
#f1c-chat-bubble:active .f1c-bot-body { animation-play-state: paused; transform: scale(.92); }

/* antenna with an orbiting spark */
.f1c-bot-ant { position: absolute; top: -13px; left: 50%; width: 3px; height: 13px; margin-left: -1.5px; border-radius: 2px; background: linear-gradient(#cbd5e1, #64748b); }
.f1c-bot-ant::after {
    content: ''; position: absolute; top: -9px; left: 50%; width: 11px; height: 11px; margin-left: -5.5px; border-radius: 50%;
    background: radial-gradient(circle at 35% 30%, #fff 0 18%, #7dd3fc 22%, #a855f7 100%);
    animation: f1cAntGlow 2s ease-in-out infinite;
}
@keyframes f1cAntGlow { 0%, 100% { box-shadow: 0 0 6px #38bdf8, 0 0 14px rgba(56,189,248,.6); } 50% { box-shadow: 0 0 8px #a855f7, 0 0 22px rgba(168,85,247,.8); } }
.f1c-orbit { position: absolute; top: -9px; left: 50%; width: 26px; height: 10px; margin-left: -13px; animation: f1cOrbit 2.4s linear infinite; pointer-events: none; }
.f1c-orbit::before { content: ''; position: absolute; top: 3px; left: 0; width: 4px; height: 4px; border-radius: 50%; background: #fde047; box-shadow: 0 0 6px #fde047; }
@keyframes f1cOrbit { 0% { transform: rotateY(0) rotateZ(-12deg); } 100% { transform: rotateY(360deg) rotateZ(-12deg); } }
.f1c-ping { position: absolute; top: -12px; left: 50%; width: 18px; height: 18px; margin-left: -9px; border-radius: 50%; border: 1.5px solid rgba(56,189,248,.8); animation: f1cPing 3.4s ease-out infinite; pointer-events: none; }
@keyframes f1cPing { 0%, 55% { transform: scale(.25); opacity: 0; } 60% { opacity: .8; } 100% { transform: scale(2.3); opacity: 0; } }

/* chrome head */
.f1c-bot-head {
    position: relative; width: 66px; height: 54px; border-radius: 23px 23px 19px 19px;
    background: linear-gradient(160deg, #ffffff 0%, #eef2f8 30%, #c3cedd 68%, #8c9db6 100%);
    box-shadow: inset 0 -8px 14px rgba(2,6,23,.28), inset 0 3px 6px rgba(255,255,255,.95),
                0 0 0 1px rgba(255,255,255,.55), 0 14px 30px rgba(56,189,248,.32);
}
.f1c-bot-head::before { /* iridescent rim */
    content: ''; position: absolute; inset: -1px; border-radius: inherit; pointer-events: none;
    background: linear-gradient(120deg, rgba(56,189,248,.55), transparent 30%, transparent 70%, rgba(168,85,247,.55));
    -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0); -webkit-mask-composite: xor; mask-composite: exclude; padding: 1.5px;
}
.f1c-ear { position: absolute; top: 17px; width: 7px; height: 20px; border-radius: 4px; background: linear-gradient(160deg, #e2e8f0, #7b8ea6); }
.f1c-ear::after { content: ''; position: absolute; top: 7px; left: 50%; width: 4px; height: 6px; margin-left: -2px; border-radius: 2px; background: #38bdf8; box-shadow: 0 0 6px #38bdf8; animation: f1cAntGlow 2s ease-in-out infinite reverse; }
.f1c-ear.l { left: -7px; } .f1c-ear.r { right: -7px; }
.f1c-bot-glare { position: absolute; top: 5px; left: 10px; width: 20px; height: 8px; border-radius: 50%; background: linear-gradient(120deg, rgba(255,255,255,1), rgba(255,255,255,0)); filter: blur(.5px); pointer-events: none; z-index: 2; }

/* holographic visor */
.f1c-bot-face {
    position: absolute; inset: 9px 8px 10px; border-radius: 14px; overflow: hidden;
    background: radial-gradient(120% 90% at 50% 0%, #17305c, #0a1430 55%, #050a1c);
    box-shadow: inset 0 0 12px rgba(56,189,248,.35), inset 0 -3px 8px rgba(168,85,247,.25);
    display: flex; align-items: center; justify-content: center;
}
.f1c-bot-face::before { /* light sweep */
    content: ''; position: absolute; top: -60%; left: -45%; width: 34%; height: 220%;
    background: linear-gradient(115deg, transparent, rgba(148,220,255,.28), transparent);
    animation: f1cVisor 5.5s ease-in-out infinite;
}
@keyframes f1cVisor { 0%, 55% { left: -45%; } 75%, 100% { left: 115%; } }
.f1c-bot-face::after { /* scanlines */
    content: ''; position: absolute; inset: 0; pointer-events: none;
    background: repeating-linear-gradient(180deg, rgba(56,189,248,.07) 0 1px, transparent 1px 3px);
}
.f1c-eyes { position: relative; z-index: 1; display: flex; gap: 11px; margin-top: -4px; transform: translate(var(--ex), var(--ey)); transition: transform .18s ease-out; }
.f1c-bot-eye {
    position: relative; width: 11px; height: 15px; border-radius: 6px;
    background: radial-gradient(circle at 35% 28%, #fff 0 16%, #bae6fd 19%, #38bdf8 58%, #0284c7 100%);
    box-shadow: 0 0 8px #38bdf8, 0 0 18px rgba(56,189,248,.55);
    animation: f1cBlink 4.6s infinite; transition: height .2s, border-radius .2s;
}
.f1c-bot-eye.f1c-e2 { animation-delay: .07s; }
@keyframes f1cBlink { 0%, 91%, 100% { transform: scaleY(1); } 94% { transform: scaleY(.08); } 97% { transform: scaleY(1); } }
.f1c-mouth { position: absolute; z-index: 1; bottom: 5px; left: 50%; width: 14px; height: 6px; margin-left: -7px; border-bottom: 2.5px solid #38bdf8; border-radius: 0 0 10px 10px; filter: drop-shadow(0 0 4px rgba(56,189,248,.8)); transition: all .25s; }
.f1c-cheek { position: absolute; z-index: 1; bottom: 9px; width: 8px; height: 4px; border-radius: 50%; background: rgba(244,114,182,.6); filter: blur(1.5px); opacity: .7; transition: opacity .3s; }
.f1c-cheek.f1c-cl { left: 6px; } .f1c-cheek.f1c-cr { right: 6px; }

/* moods */
#f1c-chat-bubble.f1c-happy .f1c-bot-eye, #f1c-chat-bubble:hover .f1c-bot-eye {
    height: 8px; margin-top: 4px; background: transparent; border-top: 3px solid #7dd3fc; border-radius: 12px 12px 0 0;
    box-shadow: none; filter: drop-shadow(0 0 4px #38bdf8); animation: none;
}
#f1c-chat-bubble.f1c-happy .f1c-mouth, #f1c-chat-bubble:hover .f1c-mouth { width: 16px; height: 8px; margin-left: -8px; border: 0; background: linear-gradient(#38bdf8, #0ea5e9); border-radius: 2px 2px 10px 10px; }
#f1c-chat-bubble.f1c-happy .f1c-cheek, #f1c-chat-bubble:hover .f1c-cheek { opacity: 1; }
#f1c-chat-bubble.f1c-love .f1c-bot-eye { background: transparent; box-shadow: none; animation: f1cBeat .6s ease-in-out infinite; }
#f1c-chat-bubble.f1c-love .f1c-bot-eye::before { content: '♥'; position: absolute; inset: -3px -2px; display: grid; place-items: center; font-size: 15px; line-height: 1; color: #f472b6; text-shadow: 0 0 8px #f472b6; }
@keyframes f1cBeat { 50% { transform: scale(1.25); } }
#f1c-chat-bubble.f1c-sleep .f1c-bot-eye { animation: none; height: 3px; margin-top: 6px; background: #64748b; box-shadow: none; border-radius: 3px; }
#f1c-chat-bubble.f1c-sleep .f1c-mouth { width: 8px; margin-left: -4px; border-bottom-color: #64748b; filter: none; border-radius: 0; }
#f1c-chat-bubble.f1c-sleep .f1c-bot-ant::after { animation: none; opacity: .35; box-shadow: none; }
#f1c-chat-bubble.f1c-sleep .f1c-jet::after { opacity: .18; animation-duration: 1.4s; }
#f1c-chat-bubble.f1c-sleep .f1c-bot-body { animation-duration: 6.5s; }
#f1c-chat-bubble.f1c-sleep .f1c-orbit { animation-play-state: paused; opacity: .3; }

/* neck + torso + arms */
.f1c-neck { width: 20px; height: 5px; margin: -1px auto 0; border-radius: 0 0 6px 6px; background: linear-gradient(#475569, #1e293b); box-shadow: 0 0 6px rgba(56,189,248,.4); }
.f1c-torso-wrap { position: relative; width: 54px; margin: 0 auto; }
.f1c-bot-torso {
    position: relative; width: 54px; height: 38px; margin: 0 auto;
    background: linear-gradient(160deg, #ffffff, #e2e8f0 60%, #b6c3d6);
    border-radius: 10px 10px 16px 16px; overflow: hidden;
    box-shadow: inset 0 -6px 10px rgba(2,6,23,.22), inset 0 3px 5px rgba(255,255,255,.95), 0 8px 18px rgba(15,23,42,.4);
    display: flex; align-items: center; justify-content: center;
}
.f1c-bot-torso::after { /* neon belt */
    content: ''; position: absolute; left: 6px; right: 6px; bottom: 4px; height: 3px; border-radius: 3px;
    background: linear-gradient(90deg, #22c55e, #38bdf8, #a855f7); box-shadow: 0 0 6px rgba(56,189,248,.8);
    animation: f1cBelt 2.6s linear infinite; background-size: 200% 100%;
}
@keyframes f1cBelt { to { background-position: -200% 0; } }
.f1c-bot-logo {
    width: 84%; height: 60%; margin-top: -3px; object-fit: contain;
    background: #0b1120; border-radius: 6px; padding: 1.5px;
    box-shadow: 0 1px 3px rgba(2,6,23,.35), inset 0 0 0 1px rgba(255,255,255,.14);
}
.f1c-bot-logo-txt { display: none; align-items: center; justify-content: center; width: 84%; height: 60%; color: #16a34a; font-size: 10px; font-weight: 900; letter-spacing: .5px; }
.f1c-arm { position: absolute; top: 3px; width: 11px; height: 24px; border-radius: 6px; transform-origin: 50% 3px;
    background: linear-gradient(160deg, #ffffff, #b9c6d8); box-shadow: inset 0 -3px 5px rgba(2,6,23,.22); animation: f1cArm 3.6s ease-in-out infinite; }
.f1c-arm::after { content: ''; position: absolute; bottom: -4px; left: 50%; width: 10px; height: 10px; margin-left: -5px; border-radius: 50%;
    background: radial-gradient(circle at 35% 30%, #fff, #94a3b8); box-shadow: 0 2px 4px rgba(2,6,23,.3); }
.f1c-arm.l { left: -9px; transform: rotate(14deg); } .f1c-arm.r { right: -9px; transform: rotate(-14deg); animation-delay: -1.8s; }
@keyframes f1cArm { 50% { rotate: 6deg; } }
#f1c-chat-bubble.f1c-wave .f1c-arm.r { z-index: 3; animation: f1cArmWave .42s ease-in-out 4 alternate; }
@keyframes f1cArmWave { from { transform: rotate(-175deg); rotate: 0deg; } to { transform: rotate(-135deg); rotate: 0deg; } }
#f1c-chat-bubble.f1c-wave .f1c-arm.l { animation-play-state: paused; }

/* thrusters */
.f1c-bot-jets { display: flex; justify-content: center; gap: 14px; margin-top: 1px; height: 22px; }
.f1c-jet { position: relative; width: 9px; height: 7px; background: linear-gradient(145deg, #cbd5e1, #64748b); border-radius: 0 0 4px 4px; }
.f1c-jet::after {
    content: ''; position: absolute; top: 7px; left: 50%; width: 8px; height: 14px; margin-left: -4px;
    background: linear-gradient(180deg, #a855f7, #38bdf8 45%, rgba(56,189,248,0));
    border-radius: 50% 50% 50% 50% / 20% 20% 80% 80%;
    filter: blur(.4px) drop-shadow(0 3px 7px rgba(56,189,248,.7));
    transform-origin: top center; animation: f1cFlame .22s ease-in-out infinite alternate;
}
.f1c-jet.f1c-j2::after { animation-delay: .1s; }
@keyframes f1cFlame { from { transform: scaleY(.75) scaleX(.9); opacity: .8; } to { transform: scaleY(1.15) scaleX(1.05); opacity: 1; } }

/* neon hover ring he floats over */
.f1c-bot-shadow {
    position: relative; width: 64px; height: 16px; margin: 2px auto 0; border-radius: 50%;
    border: 2px solid rgba(56,189,248,.7);
    background: radial-gradient(ellipse at center, rgba(56,189,248,.35), rgba(168,85,247,.12) 55%, transparent 75%);
    box-shadow: 0 0 14px rgba(56,189,248,.55), inset 0 0 10px rgba(168,85,247,.45);
    animation: f1cPad 3.6s ease-in-out infinite; transition: opacity .3s, transform .3s;
}
@keyframes f1cPad { 0%, 100% { transform: scaleX(1); opacity: .95; } 50% { transform: scaleX(.72); opacity: .55; } }
.f1c-bot-shadow i { position: absolute; inset: -2px; border-radius: 50%; border: 2px solid rgba(125,211,252,.9); opacity: 0; pointer-events: none; }
#f1c-chat-bubble.f1c-landing .f1c-bot-shadow i { animation: f1cShock .9s ease-out forwards; }
#f1c-chat-bubble.f1c-landing .f1c-bot-shadow i:nth-child(2) { animation-delay: .15s; border-color: rgba(196,181,253,.9); }
@keyframes f1cShock { from { transform: scale(.4); opacity: 1; } to { transform: scale(2.8); opacity: 0; } }

/* ── entrance when the system opens: flies in with a light trail, lands, waves ── */
#f1c-chat-bubble.f1c-enter .f1c-bot-body { animation: f1cEnter 1.35s cubic-bezier(.25,.9,.3,1.15) both; }
#f1c-chat-bubble.f1c-enter .f1c-bot-shadow { animation: f1cPadIn 1.35s ease both; }
#f1c-chat-bubble.f1c-enter .f1c-aura { animation: f1cPadIn 1.35s ease both; }
@keyframes f1cEnter {
    0%   { transform: translate(170px, -340px) rotate(-28deg) scale(.45); opacity: 0; filter: drop-shadow(40px -80px 18px rgba(56,189,248,.8)); }
    55%  { opacity: 1; filter: drop-shadow(10px -20px 10px rgba(168,85,247,.6)); }
    72%  { transform: translate(-4px, 8px) rotate(5deg) scale(1.08, .92); filter: none; }
    86%  { transform: translate(0, -10px) rotate(-2deg) scale(.98, 1.03); }
    100% { transform: none; opacity: 1; }
}
@keyframes f1cPadIn { 0%, 55% { opacity: 0; transform: scale(.3); } 80% { opacity: 1; transform: scale(1.15); } 100% { opacity: 1; transform: none; } }
.f1c-trail { position: absolute; top: -150px; right: -110px; width: 230px; height: 4px; border-radius: 4px; opacity: 0; pointer-events: none;
    transform-origin: 0 50%; transform: rotate(122deg); background: linear-gradient(90deg, rgba(255,255,255,.95), rgba(56,189,248,.8) 30%, rgba(168,85,247,.5) 60%, transparent); filter: blur(1px); }
#f1c-chat-bubble.f1c-enter .f1c-trail { animation: f1cTrail 1s ease-out .05s both; }
@keyframes f1cTrail { 0% { opacity: 0; transform: rotate(122deg) scaleX(.2); } 30% { opacity: 1; } 100% { opacity: 0; transform: rotate(122deg) scaleX(1.2) translateX(60px); } }
#f1c-chat-bubble.f1c-pop .f1c-bot-body { animation: f1cPop .6s cubic-bezier(.2,1.5,.4,1) both; }
@keyframes f1cPop { from { transform: scale(.2) translateY(30px); opacity: 0; } }

/* ── the 3D robot (robot3d.js) takes over when the device can run it ── */
.f1c-3d { position: absolute; left: 50%; top: -40px; width: 160px; height: 206px; margin-left: -80px; pointer-events: none; z-index: 1; }
#f1c-chat-bubble.f1c-has3d .f1c-bot-body,
#f1c-chat-bubble.f1c-has3d .f1c-bot-shadow,
#f1c-chat-bubble.f1c-has3d .f1c-aura { visibility: hidden; }
#f1c-chat-bubble.f1c-ascar .f1c-3d { display: none; }
#f1c-chat-bubble.f1c-has3d #f1c-bot-say { bottom: calc(100% + 30px); }
#f1c-chat-bubble.f1c-wait { visibility: hidden; }
#f1c-chat-bubble.f1c-has3d.f1c-pop .f1c-3d { animation: f1cPop .6s cubic-bezier(.2,1.5,.4,1) both; }

/* fireworks */
.f1c-spark { position: absolute; top: 34px; left: 50%; width: 7px; height: 7px; border-radius: 50%; pointer-events: none; z-index: 3;
    background: var(--clr, #22c55e); box-shadow: 0 0 8px var(--clr, #22c55e); animation: f1cSpark .95s ease-out forwards; }
@keyframes f1cSpark { 0% { transform: translate(0,0) scale(1); opacity: 1; } 100% { transform: translate(var(--dx), var(--dy)) scale(.1); opacity: 0; } }
#f1c-chat-bubble.f1c-party .f1c-bot-body { animation: f1cPartySpin .9s ease-in-out; }
@keyframes f1cPartySpin { 0% { transform: rotateY(0) translateY(0); } 50% { transform: rotateY(180deg) translateY(-16px); } 100% { transform: rotateY(360deg) translateY(0); } }
#f1c-chat-bubble.f1c-ascar .f1c-aura, #f1c-chat-bubble.f1c-ascar .f1c-trail { display: none; }
@media (prefers-reduced-motion: reduce) {
    .f1c-bot-body, .f1c-aura, .f1c-bot-shadow, .f1c-arm, .f1c-orbit, .f1c-bot-torso::after { animation: none !important; }
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

/* ═════════ SPEECH BUBBLE (glass, gradient edge) ═════════ */
#f1c-bot-say {
    position: absolute;
    bottom: calc(100% + 6px);
    <?= $__side ?>: -2px;
    width: max-content; max-width: 230px;
    background: linear-gradient(160deg, rgba(15,23,42,.96), rgba(30,27,75,.96)) padding-box,
                linear-gradient(120deg, #22c55e, #38bdf8, #a855f7) border-box;
    border: 1.5px solid transparent;
    color: #f1f5f9;
    font-size: 13px; font-weight: 800; line-height: 1.6;
    border-radius: 18px; padding: 11px 15px;
    box-shadow: 0 14px 34px rgba(0,0,0,.5), 0 0 24px rgba(56,189,248,.25);
    z-index: 998; cursor: pointer;
    opacity: 0; transform: translateY(10px) scale(.85); transform-origin: bottom <?= $__side ?>;
    transition: opacity .3s, transform .35s cubic-bezier(.2,1.5,.4,1);
    pointer-events: none;
    direction: <?= $__isAr ? 'rtl' : 'ltr' ?>;
}
#f1c-bot-say b { background: linear-gradient(90deg, #86efac, #7dd3fc); -webkit-background-clip: text; background-clip: text; color: transparent; }
#f1c-bot-say.show { opacity: 1; transform: none; pointer-events: auto; }
#f1c-chat-bubble.f1c-far #f1c-bot-say { <?= $__side ?>: auto; <?= $__isAr ? 'left' : 'right' ?>: -2px; }
#f1c-chat-bubble.f1c-far #f1c-bot-say::after { <?= $__side ?>: auto; <?= $__isAr ? 'left' : 'right' ?>: 26px; }
#f1c-bot-say::after {           /* tail toward the robot */
    content: ''; position: absolute; bottom: -7px; <?= $__side ?>: 30px;
    width: 12px; height: 12px; background: #1e1b4b;
    border-right: 1.5px solid #6366f1; border-bottom: 1.5px solid #6366f1;
    transform: rotate(45deg); border-radius: 0 0 3px 0;
}

/* ═════════ DAILY BRIEFING — "what needs you today" ═════════ */
#f1c-brief {
    position: fixed; z-index: 1001; <?= $__side ?>: 16px; bottom: calc(118px + env(safe-area-inset-bottom));
    width: min(330px, calc(100vw - 32px)); padding: 14px 14px 12px; border-radius: 22px;
    background: linear-gradient(160deg, rgba(15,23,42,.97), rgba(30,27,75,.97)) padding-box,
                linear-gradient(120deg, #22c55e, #38bdf8, #a855f7) border-box;
    border: 1.5px solid transparent; color: #f1f5f9; direction: <?= $__isAr ? 'rtl' : 'ltr' ?>;
    font-family: 'Tajawal', 'Segoe UI', Tahoma, Arial, sans-serif;
    box-shadow: 0 20px 50px rgba(0,0,0,.55), 0 0 30px rgba(56,189,248,.2);
    opacity: 0; transform: translateY(16px) scale(.92); transform-origin: bottom <?= $__side ?>; pointer-events: none;
    transition: opacity .35s, transform .45s cubic-bezier(.2,1.4,.4,1);
}
#f1c-brief.show { opacity: 1; transform: none; pointer-events: auto; }
#f1c-brief .bh { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
#f1c-brief .bh b { flex: 1; font-size: 14.5px; font-weight: 900; background: linear-gradient(90deg, #86efac, #7dd3fc); -webkit-background-clip: text; background-clip: text; color: transparent; }
#f1c-brief .bh button { width: 28px; height: 28px; border-radius: 50%; border: 0; background: rgba(255,255,255,.08); color: #cbd5e1; cursor: pointer; font-size: 12px; }
#f1c-brief a { display: flex; gap: 10px; align-items: flex-start; padding: 9px 10px; margin-top: 5px; border-radius: 13px; text-decoration: none; color: #e2e8f0;
    background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.06); font-size: 13px; font-weight: 800; line-height: 1.55;
    opacity: 0; transform: translateX(<?= $__isAr ? '-' : '' ?>12px); animation: f1cBriefIn .4s ease forwards; }
#f1c-brief a:hover { border-color: rgba(56,189,248,.45); background: rgba(56,189,248,.08); }
#f1c-brief a i { font-style: normal; font-size: 17px; line-height: 1.3; flex-shrink: 0; }
@keyframes f1cBriefIn { to { opacity: 1; transform: none; } }
@media (prefers-reduced-motion: reduce) { #f1c-brief a { animation: none; opacity: 1; transform: none; } }
@media print { #f1c-brief { display: none !important; } }

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

/* ═════════════ "WHAT'S NEW" WELCOME ═════════════ */
html.f1w-lock, html.f1w-lock body { overflow: hidden; }
.f1w { position: fixed; inset: 0; z-index: 9400; display: flex; align-items: center; justify-content: center; overflow: hidden;
    font-family: 'Tajawal', 'Segoe UI', Tahoma, Arial, sans-serif; direction: <?= $__isAr ? 'rtl' : 'ltr' ?>; color: #f1f5f9;
    opacity: 0; transition: opacity .6s ease; }
.f1w.on { opacity: 1; }
.f1w.out { opacity: 0; transition: opacity .7s ease .45s; }
.f1w-bg { position: absolute; inset: 0; background: radial-gradient(120% 80% at 50% 0%, #1e1b4b 0%, #0b1024 45%, #02040d 100%); backdrop-filter: blur(6px); }
.f1w-bg > i { position: absolute; width: 60vmax; height: 60vmax; border-radius: 50%; filter: blur(70px); opacity: .45; mix-blend-mode: screen; animation: f1wBlob 14s ease-in-out infinite alternate; }
.f1w-bg > i:nth-child(1) { background: #22c55e; left: -20vmax; top: -18vmax; }
.f1w-bg > i:nth-child(2) { background: #7c3aed; right: -22vmax; top: 10vmax; animation-duration: 17s; }
.f1w-bg > i:nth-child(3) { background: #0ea5e9; left: 10vmax; bottom: -30vmax; animation-duration: 20s; }
@keyframes f1wBlob { to { transform: translate(8vmax, 6vmax) scale(1.15); } }
.f1w-stars { position: absolute; inset: 0; }
.f1w-stars i { position: absolute; width: 2px; height: 2px; border-radius: 50%; background: #fff; box-shadow: 0 0 6px #fff; animation: f1wTw 3s ease-in-out infinite; }
@keyframes f1wTw { 50% { opacity: .15; transform: scale(.6); } }
.f1w-bg::after { content: ''; position: absolute; left: 50%; bottom: -30vh; width: 160vw; height: 60vh; margin-left: -80vw; transform: perspective(500px) rotateX(62deg);
    background: linear-gradient(rgba(56,189,248,.28) 1px, transparent 1px) 0 0 / 60px 60px, linear-gradient(90deg, rgba(168,85,247,.28) 1px, transparent 1px) 0 0 / 60px 60px;
    mask-image: linear-gradient(transparent, #000 40%); -webkit-mask-image: linear-gradient(transparent, #000 40%); animation: f1wGrid 6s linear infinite; }
@keyframes f1wGrid { to { background-position: 0 60px, 0 0; } }

.f1w-stage { position: relative; width: min(980px, 100%); max-height: 100%; overflow-y: auto; padding: 18px 20px 26px; text-align: center; scrollbar-width: none; }
.f1w-stage::-webkit-scrollbar { display: none; }
.f1w-skip { position: absolute; top: calc(14px + env(safe-area-inset-top)); <?= $__isAr ? 'left' : 'right' ?>: 16px; z-index: 3; border: 1px solid rgba(255,255,255,.18); background: rgba(255,255,255,.06);
    color: #cbd5e1; font: inherit; font-size: 13px; font-weight: 800; padding: 8px 14px; border-radius: 999px; cursor: pointer; backdrop-filter: blur(8px); }
.f1w-skip:hover { background: rgba(255,255,255,.14); }

.f1w-bot { position: relative; width: 250px; height: 290px; margin: 0 auto -6px; }
.f1w-bot::before { content: ''; position: absolute; left: 50%; top: 50%; width: 300px; height: 300px; margin: -150px 0 0 -150px; border-radius: 50%;
    background: radial-gradient(circle, rgba(56,189,248,.35), rgba(168,85,247,.15) 45%, transparent 70%); filter: blur(10px); animation: f1wPulse 4s ease-in-out infinite; }
@keyframes f1wPulse { 50% { transform: scale(1.12); opacity: .7; } }
.f1w-bot3d { position: absolute; inset: 0; }
.f1w-flat .f1w-bot3d { display: grid; place-items: center; }
.f1w-cssbot { --s: 2.4; transform: scale(var(--s)); margin-top: 30px; animation: f1wBotIn 1.3s cubic-bezier(.2,1.2,.3,1) both !important; }
@keyframes f1wBotIn { from { transform: scale(.6) translateY(-120px) rotate(-30deg); opacity: 0; } to { transform: scale(var(--s)); opacity: 1; } }

.f1w-hi { font-size: 16px; font-weight: 800; color: #cbd5e1; opacity: 0; animation: f1wUp .7s ease 1.2s both; }
.f1w-hi b { background: linear-gradient(90deg, #86efac, #7dd3fc); -webkit-background-clip: text; background-clip: text; color: transparent; }
.f1w-title { margin: 6px 0 6px; font-size: clamp(28px, 6vw, 50px); font-weight: 900; line-height: 1.15; letter-spacing: -.5px;
    background: linear-gradient(90deg, #fff 0%, #c4b5fd 25%, #7dd3fc 50%, #86efac 75%, #fff 100%); background-size: 300% 100%;
    -webkit-background-clip: text; background-clip: text; color: transparent;
    filter: drop-shadow(0 6px 30px rgba(124,58,237,.45)); animation: f1wUp .8s cubic-bezier(.2,1.2,.3,1) 1.35s both, f1wShine 7s linear 2s infinite; }
@keyframes f1wShine { to { background-position: -300% 0; } }
.f1w-sub { margin: 0 0 22px; font-size: 15.5px; font-weight: 700; color: #94a3b8; animation: f1wUp .7s ease 1.55s both; }
@keyframes f1wUp { from { opacity: 0; transform: translateY(22px); filter: blur(6px); } to { opacity: 1; transform: none; filter: none; } }

.f1w-feats { display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; perspective: 900px; text-align: start; }
.f1w-feats > .f1w-card { flex: 0 1 calc(33.333% - 8px); }
.f1w-card { position: relative; display: flex; gap: 12px; align-items: flex-start; padding: 15px 15px 16px; border-radius: 20px; overflow: hidden;
    background: linear-gradient(160deg, rgba(255,255,255,.08), rgba(255,255,255,.02)); border: 1px solid rgba(255,255,255,.1);
    box-shadow: 0 16px 40px rgba(0,0,0,.35); backdrop-filter: blur(10px);
    transform: rotateX(var(--rx, 0deg)) rotateY(var(--ry, 0deg)); transition: transform .2s ease, border-color .2s, box-shadow .2s;
    animation: f1wCard .8s cubic-bezier(.2,1.25,.3,1) var(--d, 2s) both; }
@keyframes f1wCard { from { opacity: 0; transform: translateY(40px) rotateX(35deg) scale(.9); } }
.f1w-card::before { content: ''; position: absolute; width: 140px; height: 140px; border-radius: 50%; top: -70px; <?= $__isAr ? 'left' : 'right' ?>: -50px; background: var(--c); opacity: .28; filter: blur(30px); }
.f1w-card::after { content: ''; position: absolute; inset: 0; background: linear-gradient(115deg, transparent 30%, rgba(255,255,255,.13) 45%, transparent 60%); transform: translateX(-120%); animation: f1wSheen 5s ease-in-out calc(var(--d, 2s) + 1s) infinite; pointer-events: none; }
@keyframes f1wSheen { 0%, 70% { transform: translateX(-120%); } 100% { transform: translateX(120%); } }
.f1w-card:hover { border-color: color-mix(in srgb, var(--c) 60%, transparent); box-shadow: 0 20px 44px rgba(0,0,0,.45), 0 0 30px color-mix(in srgb, var(--c) 35%, transparent); }
.f1w-card .ic { position: relative; flex-shrink: 0; display: grid; place-items: center; width: 46px; height: 46px; border-radius: 15px; font-size: 23px;
    background: color-mix(in srgb, var(--c) 22%, transparent); border: 1px solid color-mix(in srgb, var(--c) 45%, transparent); box-shadow: 0 0 20px color-mix(in srgb, var(--c) 30%, transparent); }
.f1w-card .tx { position: relative; }
.f1w-card b { display: block; font-size: 15px; font-weight: 900; color: #fff; margin: 2px 0 4px; }
.f1w-card small { display: block; font-size: 12.5px; color: #94a3b8; line-height: 1.6; font-weight: 600; }

.f1w-go { position: relative; margin-top: 24px; display: inline-flex; align-items: center; gap: 12px; height: 58px; padding: 0 34px; border: 0; border-radius: 20px; cursor: pointer;
    font: inherit; font-size: 18px; font-weight: 900; color: #fff; overflow: hidden;
    background: linear-gradient(135deg, #16a34a, #0ea5e9 50%, #9333ea); background-size: 200% 200%;
    box-shadow: 0 16px 40px rgba(14,165,233,.4), 0 0 0 1px rgba(255,255,255,.15) inset;
    animation: f1wUp .7s ease 2.9s both, f1wBtn 4s ease-in-out 3.6s infinite; transition: transform .15s; }
.f1w-go::before { content: ''; position: absolute; inset: -2px; border-radius: 22px; z-index: -1; background: inherit; filter: blur(16px); opacity: .7; }
.f1w-go:hover { transform: translateY(-2px) scale(1.03); }
.f1w-go:active { transform: scale(.96); }
.f1w-go em { font-style: normal; transition: transform .2s; }
.f1w-go:hover em { transform: translateX(<?= $__isAr ? '-4px' : '4px' ?>); }
@keyframes f1wBtn { 50% { background-position: 100% 100%; box-shadow: 0 16px 46px rgba(147,51,234,.5), 0 0 0 1px rgba(255,255,255,.2) inset; } }

/* leaving: cards fly out, the robot shoots down to its corner */
.f1w.out .f1w-card { animation: f1wCardOut .5s ease-in both; }
.f1w.out .f1w-card:nth-child(2n) { animation-delay: .05s; } .f1w.out .f1w-card:nth-child(3n) { animation-delay: .1s; }
@keyframes f1wCardOut { to { opacity: 0; transform: translateY(60px) scale(.85) rotateX(-25deg); } }
.f1w.out .f1w-hi, .f1w.out .f1w-title, .f1w.out .f1w-sub, .f1w.out .f1w-go, .f1w.out .f1w-skip { animation: f1wFade .35s ease both; }
@keyframes f1wFade { to { opacity: 0; transform: translateY(-14px); } }
.f1w.out .f1w-bot { animation: f1wToCorner 1s cubic-bezier(.5,0,.3,1) .15s both; }
@keyframes f1wToCorner { to { transform: translate(calc(50vw - 90px), calc(50vh - 60px)) scale(.3); opacity: 0; } }

@media (max-width: 820px) { .f1w-feats > .f1w-card { flex-basis: calc(50% - 6px); } }
@media (max-width: 560px) {
    .f1w-stage { padding: 56px 14px 22px; }
    .f1w-bot { width: 200px; height: 230px; }
    .f1w-feats { gap: 9px; }
    .f1w-feats > .f1w-card { flex-basis: 100%; }
    .f1w-card { padding: 12px 13px; }
    .f1w-card .ic { width: 40px; height: 40px; font-size: 20px; }
    .f1w-go { width: 100%; justify-content: center; position: sticky; bottom: calc(10px + env(safe-area-inset-bottom)); z-index: 2; }
    .f1w-cssbot { --s: 1.7; margin-top: 10px; }
    .f1w-flat .f1w-bot { height: 200px; }
}
@media (prefers-reduced-motion: reduce) { .f1w *, .f1w *::before, .f1w *::after { animation-duration: .01s !important; animation-delay: 0s !important; } }

</style>

<!-- ═════════ the robot ═════════ -->
<div id="f1c-chat-bubble" onclick="F1CChat.toggle()" title="<?= $__isAr ? 'مساعد المخزون' : 'Stock Assistant' ?>">
    <span class="f1c-aura" aria-hidden="true"></span>
    <span class="f1c-trail" aria-hidden="true"></span>
    <div class="f1c-bot-body">
        <div class="f1c-bot-ant"><span class="f1c-ping"></span><i class="f1c-orbit"></i></div>
        <div class="f1c-bot-head">
            <span class="f1c-ear l"></span><span class="f1c-ear r"></span>
            <span class="f1c-bot-glare"></span>
            <div class="f1c-bot-face">
                <span class="f1c-eyes">
                    <span class="f1c-bot-eye"></span>
                    <span class="f1c-bot-eye f1c-e2"></span>
                </span>
                <span class="f1c-mouth"></span>
                <span class="f1c-cheek f1c-cl"></span>
                <span class="f1c-cheek f1c-cr"></span>
            </div>
        </div>
        <div class="f1c-neck"></div>
        <div class="f1c-torso-wrap">
            <span class="f1c-arm l"></span>
            <span class="f1c-arm r"></span>
            <div class="f1c-bot-torso">
                <img class="f1c-bot-logo" src="icons/icon-192.png?v=4" alt=""
                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                <span class="f1c-bot-logo-txt">FIRST 1 CAR</span>
            </div>
        </div>
        <div class="f1c-bot-jets">
            <span class="f1c-jet"></span>
            <span class="f1c-jet f1c-j2"></span>
        </div>
    </div>
    <div class="f1c-bot-shadow"><i></i><i></i></div>
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
<div id="f1c-brief" role="status" aria-live="polite"></div>
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
            <input id="f1c-chat-input" type="text" enterkeyhint="send" autocomplete="off" placeholder="<?= $__isAr ? 'اسأل عن أي سيارة أو سعر أو رقم شاسيه…' : 'Ask about any car, price or chassis…' ?>" />
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
        'morning' => 'صباح الخير', 'evening' => 'مساء الخير', 'night' => 'مساء الخير',
        'hero' => 'أنا مساعدك الذكي — اسألني عن المخزون، الأسعار، الشاسيه، أو حضورك.',
        'thinking' => ['أبحث في المخزون…', 'أجمع البيانات…', 'لحظة واحدة…', 'أفكّر…'],
        'listening' => 'أستمع إليك… تحدّث',
        'noVoice' => 'هذا المتصفح لا يدعم الإدخال الصوتي — جرّب Chrome 🎤',
        'err' => 'حدث خطأ، حاول مرة أخرى.', 'net' => 'مشكلة في الاتصال 📡',
        'sugg' => ['هل يوجد تيجو 7 أسود؟', 'كم سعر الامجراند؟', 'قارن تيجو 7 وتيجو 8', 'كل شيء عن جوليون', 'الأكثر رواجاً', 'ساعاتي هذا الأسبوع'],
        'suggAdmin' => ['من في العمل؟', 'من لم يحضر اليوم؟'],
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
        'أهلاً! 🤖 أنا مساعد فيرست 1 كار — اسألني عن أي سيارة ✨',
        'هل استخدمتني اليوم؟ 😄 جرّبني!',
        'فيرست 1 كار الأفضل! 🏆🚗',
        'اكتب: "هل يوجد تيجو 7 أسود؟" وشاهد النتيجة 🔮',
        'لديّ كل الأسعار محدّثة لحظة بلحظة 💰',
        'أرسل رقم شاسيه وأجد لك السيارة في ثانية 🔍',
        'اكتب "كل شيء عن جوليون" وسأعدّ لك ملفاً كاملاً 🧠',
        'تريد مقارنة موديلين؟ اكتب "قارن تيجو 7 وتيجو 8" ⚖️',
        'يمكنك أن تسألني بصوتك أيضاً 🎤',
        'تريد معرفة ساعات عملك هذا الأسبوع؟ اسألني ⏱️',
        'هل رأيت القادم في الطريق؟ اسألني عن الشحنات 🚚',
        'لا توجد سيارة تختبئ مني 👀',
        'هيا نبيع السيارات! 💪',
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
        ? ['Zzz... 😴', 'لحظات لإعادة الشحن... 🔋😴', 'غفوة سريعة... 💤']
        : ['Zzz... 😴', 'Quick recharge... 🔋😴', 'Power nap... 💤'], JSON_UNESCAPED_UNICODE) ?>,
    WAKE_MSGS: <?= json_encode($__isAr
        ? ['استيقظت! 😄 هل تحتاج شيئاً؟', 'عدت بطاقة 100% 🔋⚡', 'كنت أحلم بتيجو 7 🚗💭']
        : ["I'm up! 😄 Need anything?", 'Back at 100% battery 🔋⚡', 'I was dreaming of a Tiggo 7 🚗💭'], JSON_UNESCAPED_UNICODE) ?>,
    PARTY_MSGS: <?= json_encode($__isAr
        ? ['فيرست 1 كار الأفضل! 🎆🏆', 'هيا نحقق أعلى المبيعات! 🎇💪', 'أفضل فريق وأجمل سيارات 🎉🚗']
        : ['First 1 Car is the best! 🎆🏆', "Let's crush it today! 🎇💪", 'Best team, best cars 🎉🚗'], JSON_UNESCAPED_UNICODE) ?>,
    MORNING: <?= json_encode($__isAr ? 'صباح الخير! ☀️ نتمنى يوم مبيعات ممتازاً' : 'Good morning! ☀️ Big sales day ahead', JSON_UNESCAPED_UNICODE) ?>,
    NIGHT:   <?= json_encode($__isAr ? 'تعمل حتى وقت متأخر؟ 🌙 أنا معك' : 'Working late? 🌙 I\'m with you', JSON_UNESCAPED_UNICODE) ?>,

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
        return !!document.querySelector('#ntStack .nt-card:not(.out), #ntOv.on, #npCard.on, .ov.on, #f1cWelcome, #f1c-brief.show, .la-ov.on');
    },

    say(text, ms, name) {
        const el = document.getElementById('f1c-bot-say');
        if (!el || this.opened || this.screenBusy()) return;
        el.textContent = '';
        if (name) {   // "أهلاً <b>ahmed</b>! …"
            const i = text.indexOf('{name}');
            el.append(text.slice(0, i), Object.assign(document.createElement('b'), { textContent: name }), text.slice(i + 6));
        } else el.textContent = text;
        el.classList.add('show');
        clearTimeout(this._sayT);
        this._sayT = setTimeout(() => el.classList.remove('show'), ms || 7500);
    },

    HELLO: <?= json_encode($__isAr
        ? ['morning' => 'صباح الخير يا {name}! ☀️ نتمنى لك يوماً موفقاً', 'day' => 'أهلاً يا {name}! 👋 أنا جاهز — اسألني عن أي سيارة', 'night' => 'مساء الخير يا {name} 🌙 أنا معك']
        : ['morning' => 'Good morning, {name}! ☀️ Big day ahead', 'day' => "Hey {name}! 👋 I'm ready — ask me about any car", 'night' => "Working late, {name}? 🌙 I'm with you"], JSON_UNESCAPED_UNICODE) ?>,

    mood(cls, ms) {
        const el = document.getElementById('f1c-chat-bubble');
        if (!el) return;
        el.classList.add(cls);
        clearTimeout(this['_m' + cls]);
        this['_m' + cls] = setTimeout(() => el.classList.remove(cls), ms || 2000);
    },
    wave() { this.mood('f1c-wave', 1900); this.mood('f1c-happy', 2400); },

    /* eyes follow the mouse / finger */
    trackEyes() {
        const el = document.getElementById('f1c-chat-bubble');
        if (!el || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        let raf = 0, px = 0, py = 0, lastMove = 0;
        const apply = () => {
            raf = 0;
            const r = el.getBoundingClientRect();
            const cx = r.left + r.width / 2, cy = r.top + 44;
            const dx = px - cx, dy = py - cy, d = Math.max(1, Math.hypot(dx, dy));
            const k = Math.min(1, d / 260);
            el.style.setProperty('--ex', (dx / d * 4 * k).toFixed(2) + 'px');
            el.style.setProperty('--ey', (dy / d * 3 * k).toFixed(2) + 'px');
        };
        addEventListener('pointermove', (e) => { px = e.clientX; py = e.clientY; lastMove = Date.now(); if (!raf) raf = requestAnimationFrame(apply); }, { passive: true });
        addEventListener('pointerdown', (e) => { px = e.clientX; py = e.clientY; lastMove = Date.now(); if (!raf) raf = requestAnimationFrame(apply); }, { passive: true });
        /* no pointer for a while → glance around on his own */
        setInterval(() => {
            if (Date.now() - lastMove < 5000 || document.hidden) return;
            const g = [[0, 0], [4, -1], [-4, -1], [0, 2], [3, 2], [-3, 2]][Math.floor(Math.random() * 6)];
            el.style.setProperty('--ex', g[0] + 'px'); el.style.setProperty('--ey', g[1] + 'px');
        }, 2600);
    },

    greet() {
        let greeted = false;
        try {
            greeted = !!sessionStorage.getItem('f1cBotGreeted');
            sessionStorage.setItem('f1cBotGreeted', '1');
        } catch (e) {}
        const bot = document.getElementById('f1c-chat-bubble');
        const still = matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.trackEyes();
        ['pointerdown', 'keydown', 'scroll', 'touchstart'].forEach(ev => addEventListener(ev, () => { this.lastAct = Date.now(); }, { passive: true, capture: true }));
        document.addEventListener('visibilitychange', () => { if (document.hidden) this.landNow(); });
        this.scheduleAct();
        this.watchScreen();

        const start = (force) => {
            if ((!greeted || force) && bot && !still) {
                /* first page after login: fly in, land with a shockwave, wave hello */
                bot.classList.add('f1c-enter');
                setTimeout(() => { bot.classList.add('f1c-landing'); }, 950);
                setTimeout(() => { bot.classList.remove('f1c-enter'); }, 1400);
                setTimeout(() => { bot.classList.remove('f1c-landing'); this.wave(); }, 1900);
            } else if (bot && !still) {
                bot.classList.add('f1c-pop');
                setTimeout(() => bot.classList.remove('f1c-pop'), 700);
            }
            setTimeout(() => this.brief(), (!greeted || force) ? 11500 : 2500);   // once a day: what needs you today
            if (!greeted || force) {
                const h = new Date().getHours();
                const key = (h >= 5 && h < 12) ? 'morning' : (h >= 21 || h < 5) ? 'night' : 'day';
                setTimeout(() => this.me ? this.say(this.HELLO[key], 8500, this.me) : this.say(key === 'morning' ? this.MORNING : key === 'night' ? this.NIGHT : this.MSGS[0], 8500), 2000);
            }
        };
        /* "what's new" welcome once per person (per update); it hands over to the corner robot */
        const go = () => {
            if (window.F1CWelcome && F1CWelcome.shouldShow()) F1CWelcome.show(() => start(true));
            else start(false);
        };
        /* wait for the 3D robot (max 2.5 s) so the entrance plays in 3D */
        if (bot && this.want3d()) {
            bot.classList.add('f1c-wait');
            let done = false;
            const fin = () => { if (done) return; done = true; bot.classList.remove('f1c-wait'); go(); };
            addEventListener('f1c3d-ready', fin, { once: true });
            addEventListener('f1c3d-fail', fin, { once: true });
            setTimeout(fin, 2500);
            this.load3d();
        } else go();
    },

    /* once a day: the robot tells you what needs you (old reservations, cars coming to your branch, bank replies…) */
    async brief(tries) {
        if (!this.me) return;
        const d = new Date(), key = 'f1cBrief:' + this.me + ':' + d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate();
        try { if (localStorage.getItem(key)) return; } catch (e) { return; }
        // wait until the screen is free (max ~2 min) — on a wide screen the "turn on notifications" card sits on the other side
        const busy = innerWidth >= 760
            ? !!document.querySelector('#ntStack .nt-card:not(.out), #ntOv.on, .ov.on, #f1cWelcome, .la-ov.on')
            : this.screenBusy();
        if (this.opened || document.hidden || busy) {
            if ((tries || 0) < 40) setTimeout(() => this.brief((tries || 0) + 1), 3000);
            return;
        }
        let r = null;
        try {
            r = await (await fetch('chatbot_api.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ briefing: 1, lang: this.lang }) })).json();
        } catch (e) { return; }
        try { localStorage.setItem(key, '1'); } catch (e) {}
        if (!r || !r.ok || !r.items || !r.items.length) return;
        const box = document.getElementById('f1c-brief');
        if (!box) return;
        const h = d.getHours();
        box.innerHTML = '<div class="bh"><b></b><button type="button" aria-label="close">✕</button></div>';
        box.querySelector('b').textContent = (this.lang === 'ar' ? (h < 12 ? 'صباح الخير يا ' : 'أهلاً يا ') + this.me + '! هذا ما ينتظرك اليوم 👇' : (h < 12 ? 'Good morning, ' : 'Hi, ') + this.me + '! Here is what needs you 👇');
        r.items.forEach((it, i) => {
            const a = document.createElement('a'); a.href = it.u || '#'; a.style.animationDelay = (0.15 + i * 0.12) + 's';
            a.append(Object.assign(document.createElement('i'), { textContent: it.i }), Object.assign(document.createElement('span'), { textContent: it.t }));
            box.appendChild(a);
        });
        const hide = () => box.classList.remove('show');
        box.querySelector('button').addEventListener('click', hide);
        let t = setTimeout(hide, 30000);
        box.onmouseenter = () => clearTimeout(t);
        box.onmouseleave = () => { t = setTimeout(hide, 8000); };
        box.classList.add('show');
        this.wave();
    },

    /* can this device run the 3D robot? (the 3D file double-checks and also watches the frame rate) */
    want3d() {
        try {
            const pref = localStorage.getItem('f1c3d') || '';     // 'off' = flat robot on this device, 'force' = always 3D
            if (pref === 'off') return false;
            if (pref === 'force') return true;
            if (matchMedia('(prefers-reduced-motion: reduce)').matches) return false;
            if ((navigator.hardwareConcurrency || 4) < 4) return false;
            if (navigator.deviceMemory && navigator.deviceMemory < 3) return false;
            const c = document.createElement('canvas');
            return !!(c.getContext('webgl2') || c.getContext('webgl'));
        } catch (e) { return false; }
    },
    load3d() {
        if (window.F1C3D || document.getElementById('f1c3dScript')) return;
        const sc = document.createElement('script');
        sc.id = 'f1c3dScript'; sc.src = 'robot3d.js?v=1'; sc.async = true;
        sc.onerror = () => dispatchEvent(new Event('f1c3d-fail'));     // file missing → the CSS robot stays
        document.head.appendChild(sc);
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
        if      (r < 0.22) this.wander();
        else if (r < 0.36) this.carDrive();        /* 🤖→🚗 transform + drive */
        else if (r < 0.46) { this.wave(); this.say(this.randMsg(this.MSGS)); }
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
            ? ['وروووم! 🚗💨 فيرست 1 كار!', 'تحوّلت إلى سيارة! 🚗', 'أسرع معرض في المدينة 🏁']
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
        this.mood('f1c-love', 2200);
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
/* ═════════════ "WHAT'S NEW" WELCOME — once per person, per update ═════════════ */
const F1CWelcome = {
    VERSION: 'v8',
    key() { return 'f1cWelcome:' + this.VERSION + ':' + (F1CChat.me || ''); },
    shouldShow() {
        if (!F1CChat.me || new URLSearchParams(location.search).get('msg')) return false;   // opening a message from the phone → straight to it
        try { return !localStorage.getItem(this.key()); } catch (e) { return false; }
    },
    FEATS: <?php
        $__feats = $__isAr ? [
            ['🚚', '«تم الاستلام» للسيارات المنقولة', 'عند وصول سيارة إلى فرعك يصلك إشعار، وعليك تأكيد استلامها خلال المهلة من وقت تسجيل حضورك', '#38bdf8'],
            ['📋', 'الجرد المفاجئ', 'قد يُطلب منك في أي وقت تأكيد وجود كل سيارة في الفرع خلال مدة محددة', '#f97316'],
            ['🔒', 'الالتزام بالمواعيد', 'عدم الإنجاز في الوقت المحدد يوقف النظام والبصمة حتى تفتحهما الإدارة — وستصلك تنبيهات قبل ذلك', '#ef4444'],
            ['⏰', 'تذكيرات ذكية', 'الحجز القديم والأمانة ورد البنك والانصراف — يذكّرك النظام تلقائياً', '#22c55e'],
            ['👍', 'رسائل الإدارة', 'اقرأ الرسالة وأكّد اطلاعك عليها — وتعرف الإدارة من قرأها', '#f59e0b'],
            ['🤖', 'ملخص يومي من المساعد', 'كل يوم عند فتح النظام: ما ينتظرك في لمحة واحدة', '#a855f7'],
        ] : [
            ['🚚', '"Received" for transferred cars', 'A car reaches your branch: you get a notification and confirm it within the time allowed after clocking in', '#38bdf8'],
            ['📋', 'Surprise stock check', 'At any time you may be asked to confirm every car at the branch within a set time', '#f97316'],
            ['🔒', 'On time, every time', 'Not done in time stops the system and clocking in/out until management unlocks it — you are warned first', '#ef4444'],
            ['⏰', 'Smart reminders', 'Old reservations, consignments, bank replies, clocking out — the system reminds you', '#22c55e'],
            ['👍', 'Messages from management', 'Read it and confirm — management knows who read it', '#f59e0b'],
            ['🤖', 'A daily summary', 'Every day when you open: what needs you, at a glance', '#a855f7'],
        ];
        if (function_exists('can') && can('dash.activity')) {
            $__feats[] = $__isAr ? ['⚡', 'ما يحدث الآن', 'من المتصل الآن وآخر نشاط في النظام — على الرئيسية', '#2dd4bf']
                                 : ['⚡', 'Happening now', "Who's online and the latest activity — on the dashboard", '#2dd4bf'];
        }
        echo json_encode($__feats, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    ?>,
    T: <?= json_encode($__isAr
        ? ['hi' => 'أهلاً', 'title' => 'First 1 Car أصبح أذكى 🧠', 'sub' => 'إليك الجديد في النظام', 'go' => 'لنبدأ', 'skip' => 'تخطي']
        : ['hi' => 'Welcome,', 'title' => 'First 1 Car just got smarter 🧠', 'sub' => "Notifications that remind you before you forget — here's what's new", 'go' => "Let's go", 'skip' => 'Skip'], JSON_UNESCAPED_UNICODE) ?>,

    show(done) {
        try { localStorage.setItem(this.key(), '1'); } catch (e) {}
        const ov = document.createElement('div');
        ov.id = 'f1cWelcome'; ov.className = 'f1w';
        ov.innerHTML =
            '<div class="f1w-bg"><i></i><i></i><i></i><b class="f1w-stars"></b></div>' +
            '<button type="button" class="f1w-skip"></button>' +
            '<div class="f1w-stage">' +
              '<div class="f1w-bot"><div class="f1w-bot3d"></div></div>' +
              '<div class="f1w-hi"></div><h1 class="f1w-title"></h1><p class="f1w-sub"></p>' +
              '<div class="f1w-feats"></div>' +
              '<button type="button" class="f1w-go"><span></span><em>→</em></button>' +
            '</div>';
        document.body.appendChild(ov);
        const $ = s => ov.querySelector(s);
        $('.f1w-hi').innerHTML = '';
        $('.f1w-hi').append(this.T.hi + ' ', Object.assign(document.createElement('b'), { textContent: F1CChat.me }), ' 👋');
        $('.f1w-title').textContent = this.T.title;
        $('.f1w-sub').textContent = this.T.sub;
        $('.f1w-go span').textContent = this.T.go;
        $('.f1w-skip').textContent = this.T.skip + ' ✕';
        if (F1CChat.lang === 'ar') $('.f1w-go em').textContent = '←';
        this.FEATS.forEach((f, i) => {
            const c = document.createElement('div');
            c.className = 'f1w-card'; c.style.setProperty('--c', f[3]); c.style.setProperty('--d', (1.9 + i * 0.16) + 's');
            c.innerHTML = '<span class="ic"></span><span class="tx"><b></b><small></small></span>';
            c.querySelector('.ic').textContent = f[0]; c.querySelector('b').textContent = f[1]; c.querySelector('small').textContent = f[2];
            /* 3D tilt toward the finger / mouse */
            c.addEventListener('pointermove', e => { const r = c.getBoundingClientRect(); c.style.setProperty('--rx', ((e.clientY - r.top) / r.height - .5) * -10 + 'deg'); c.style.setProperty('--ry', ((e.clientX - r.left) / r.width - .5) * 12 + 'deg'); });
            c.addEventListener('pointerleave', () => { c.style.setProperty('--rx', '0deg'); c.style.setProperty('--ry', '0deg'); });
            $('.f1w-feats').appendChild(c);
        });
        const stars = $('.f1w-stars');
        for (let i = 0; i < 46; i++) { const s = document.createElement('i'); s.style.cssText = 'left:' + Math.random() * 100 + '%;top:' + Math.random() * 100 + '%;animation-delay:' + (Math.random() * 4).toFixed(2) + 's;animation-duration:' + (2.5 + Math.random() * 3).toFixed(2) + 's'; stars.appendChild(s); }

        /* the big robot: real 3D when available, the CSS robot scaled up otherwise */
        let big = null;
        const host = $('.f1w-bot3d');
        if (window.F1C3D && F1C3D.active) {
            try { big = F1C3D.create(host, { big: true }); big.enter(); setTimeout(() => { big.setMood('happy'); big.wave(); }, 1400); } catch (e) { big = null; }
        }
        if (!big) {
            const src = document.querySelector('#f1c-chat-bubble .f1c-bot-body');
            if (src) { const cl = src.cloneNode(true); cl.classList.add('f1w-cssbot'); host.appendChild(cl); }
            ov.classList.add('f1w-flat');
        }
        document.documentElement.classList.add('f1w-lock');
        requestAnimationFrame(() => requestAnimationFrame(() => ov.classList.add('on')));

        let closing = false;
        const close = () => {
            if (closing) return; closing = true;
            if (big) { big.setMood('happy'); big.flying(true); }
            ov.classList.add('out');                                  // cards fly away, the robot shoots to its corner
            setTimeout(() => {
                ov.remove(); document.documentElement.classList.remove('f1w-lock');
                if (big) big.destroy();
                done && done();
            }, 1150);
        };
        $('.f1w-go').addEventListener('click', close);
        $('.f1w-skip').addEventListener('click', close);
        document.addEventListener('keydown', function esc(e) { if (e.key === 'Escape') { document.removeEventListener('keydown', esc); close(); } });
    },
};
window.F1CWelcome = F1CWelcome;

F1CChat.greet();
</script>
