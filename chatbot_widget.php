<?php
/**
 * chatbot_widget.php
 * First 1 Car — Data Assistant floating widget (v3 "the robot")
 *
 * INSTALL: unchanged — one line before </body> in dashboard.php:
 *     <?php include 'chatbot_widget.php'; ?>
 *
 * v3:
 *  - The bubble is now a 3D CSS ROBOT 🤖: floats, tilts in 3D,
 *    eyes glow and blink, antenna pulses — zero images, zero CDN.
 *  - Greeting speech bubble on page load ("أنا هنا أساعدك ✨"),
 *    once per session, tap it (or the robot) to open the chat.
 *  - Copy updated for the v3 brain: free-text questions, chassis
 *    lookup, comparisons, deep dives.
 *  - Same API contract with chatbot_api.php — nothing else changes.
 */
$__lang = (isset($lang) && in_array($lang, ['ar', 'en'], true)) ? $lang : 'ar';
$__isAr = ($__lang === 'ar');
$__side = $__isAr ? 'right' : 'left';
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

/* ═════════ CHAT PANEL (v2 base, refreshed) ═════════ */
#f1c-chat-panel {
    position: fixed;
    bottom: calc(76px + env(safe-area-inset-bottom, 0px) + 16px);
    <?= $__side ?>: 18px;
    width: min(370px, calc(100vw - 36px));
    height: min(560px, calc(100vh - 190px));
    background: #0b1120;
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 20px;
    box-shadow: 0 16px 48px rgba(0,0,0,.6);
    display: none; flex-direction: column; overflow: hidden;
    z-index: 999; font-family: inherit;
    direction: <?= $__isAr ? 'rtl' : 'ltr' ?>;
    transform: translateY(20px) scale(.96); opacity: 0;
    transition: transform .22s cubic-bezier(.2,.8,.2,1), opacity .22s;
}
#f1c-chat-panel.open { display: flex; transform: translateY(0) scale(1); opacity: 1; }

#f1c-chat-header {
    background: linear-gradient(135deg, #1e1b4b, #172554);
    color: #fff; padding: 13px 16px;
    display: flex; justify-content: space-between; align-items: center;
    border-bottom: 1px solid rgba(255,255,255,.08);
}
#f1c-chat-header .f1c-h-title { font-size: 15px; font-weight: 800; display: flex; align-items: center; gap: 8px; }
#f1c-chat-header .f1c-h-sub { font-size: 11px; color: #a5b4fc; font-weight: 500; margin-top: 2px; }
.f1c-h-bot {
    width: 30px; height: 26px; border-radius: 9px; flex-shrink: 0;
    background: linear-gradient(145deg, #e2e8f0, #8ea0b8);
    position: relative;
}
.f1c-h-bot::after {
    content: ''; position: absolute; inset: 4px;
    background: #0b1120; border-radius: 6px;
    background-image: radial-gradient(circle 2.5px at 33% 50%, #38bdf8 99%, transparent),
                      radial-gradient(circle 2.5px at 67% 50%, #38bdf8 99%, transparent);
}
#f1c-chat-close { cursor: pointer; opacity: .7; font-size: 22px; line-height: 1; }
#f1c-chat-close:hover { opacity: 1; }

#f1c-chat-messages {
    flex: 1; overflow-y: auto; padding: 14px;
    display: flex; flex-direction: column; gap: 10px;
    background:
      radial-gradient(circle at 20% 0%, rgba(147,51,234,.06), transparent 40%),
      radial-gradient(circle at 80% 100%, rgba(37,99,235,.06), transparent 40%);
}
.f1c-msg {
    max-width: 92%; padding: 10px 13px; border-radius: 15px;
    font-size: 13px; line-height: 1.65; white-space: pre-wrap;
    animation: f1cIn .25s ease;
}
@keyframes f1cIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
.f1c-msg.user {
    background: linear-gradient(90deg, #9333ea, #2563eb); color: #fff;
    align-self: flex-end; border-bottom-right-radius: 4px;
}
.f1c-msg.bot {
    background: #1e293b; color: #e5e7eb;
    align-self: flex-start; border-bottom-left-radius: 4px;
}
[dir="rtl"] .f1c-msg.user { align-self: flex-start; border-bottom-right-radius: 15px; border-bottom-left-radius: 4px; }
[dir="rtl"] .f1c-msg.bot  { align-self: flex-end;  border-bottom-left-radius: 15px; border-bottom-right-radius: 4px; }

.f1c-options { display: flex; flex-wrap: wrap; gap: 7px; align-self: flex-start; max-width: 100%; animation: f1cIn .3s ease; }
[dir="rtl"] .f1c-options { align-self: flex-end; }
.f1c-chip {
    background: rgba(147,51,234,.14); border: 1px solid rgba(147,51,234,.35);
    color: #d8b4fe; border-radius: 22px; padding: 8px 15px;
    font-size: 12.5px; font-weight: 700; cursor: pointer; font-family: inherit;
    transition: background .15s, transform .1s; white-space: nowrap;
}
.f1c-chip:hover { background: rgba(147,51,234,.26); }
.f1c-chip:active { transform: scale(.95); }
.f1c-chip.f1c-chip-back { background: rgba(255,255,255,.05); border-color: rgba(255,255,255,.12); color: #94a3b8; }

#f1c-chat-typing { padding: 4px 14px 8px; display: none; align-self: flex-start; }
[dir="rtl"] #f1c-chat-typing { align-self: flex-end; }
.f1c-typing-bubble { background: #1e293b; border-radius: 15px; padding: 11px 14px; display: inline-flex; gap: 4px; }
.f1c-dot-t { width: 7px; height: 7px; border-radius: 50%; background: #94a3b8; animation: f1cBounce 1.2s infinite; }
.f1c-dot-t:nth-child(2) { animation-delay: .2s; }
.f1c-dot-t:nth-child(3) { animation-delay: .4s; }
@keyframes f1cBounce { 0%,60%,100% { transform: translateY(0); opacity: .5; } 30% { transform: translateY(-5px); opacity: 1; } }

#f1c-chat-inputbar { display: flex; gap: 8px; padding: 10px; border-top: 1px solid rgba(255,255,255,.08); background: #0b1120; }
#f1c-chat-input {
    flex: 1; background: #1e293b; border: 1px solid rgba(255,255,255,.1);
    color: #fff; border-radius: 22px; padding: 9px 15px; font-size: 13px; outline: none;
}
#f1c-chat-input:focus { border-color: rgba(147,51,234,.5); }
#f1c-chat-send {
    background: linear-gradient(90deg, #9333ea, #2563eb); color: #fff; border: none;
    border-radius: 22px; padding: 9px 17px; font-size: 13px; font-weight: 700; cursor: pointer; flex-shrink: 0;
}
#f1c-chat-send:disabled { opacity: .5; cursor: default; }
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

<div id="f1c-chat-panel">
    <div id="f1c-chat-header">
        <div style="display:flex;align-items:center;gap:10px">
            <div class="f1c-h-bot"></div>
            <div>
                <div class="f1c-h-title"><?= $__isAr ? 'مساعد المخزون الذكي' : 'Smart Stock Assistant' ?></div>
                <div class="f1c-h-sub"><?= $__isAr ? 'اسألني أي حاجة — بيانات حية' : 'Ask me anything — live data' ?></div>
            </div>
        </div>
        <span id="f1c-chat-close" onclick="F1CChat.toggle()">&times;</span>
    </div>
    <div id="f1c-chat-messages"></div>
    <div id="f1c-chat-typing">
        <div class="f1c-typing-bubble"><span class="f1c-dot-t"></span><span class="f1c-dot-t"></span><span class="f1c-dot-t"></span></div>
    </div>
    <div id="f1c-chat-inputbar">
        <input id="f1c-chat-input" type="text" placeholder="<?= $__isAr ? 'تيجو 7 أسود؟ · بكام الامجراند؟ · 991628' : 'black Tiggo 7? · Emgrand price? · 991628' ?>" />
        <button id="f1c-chat-send" onclick="F1CChat.send()"><?= $__isAr ? 'إرسال' : 'Send' ?></button>
    </div>
</div>

<script>
const F1CChat = {
    opened: false, started: false,
    lang: <?= json_encode($__lang) ?>,
    ctx: {},

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
        panel.classList.toggle('open', this.opened);
        document.getElementById('f1c-chat-bubble').classList.toggle('f1c-hidden', this.opened);
        this.hideGreeting();
        if (this.opened && !this.started) { this.started = true; this.call({}); }
        if (this.opened) setTimeout(() => document.getElementById('f1c-chat-input').focus(), 250);
    },

    fromGreeting() { this.hideGreeting(); if (!this.opened) this.toggle(); },

    hideGreeting() {
        const say = document.getElementById('f1c-bot-say');
        if (say) say.classList.remove('show');
    },

    /* ══ MASCOT BRAIN v5: wanders the screen, sleeps, parties, hypes ══ */
    MSGS: <?= json_encode($__isAr ? [
        'أهلاً! 🤖 أنا مساعد فيرست 1 كار — اسألني عن أي عربية ✨',
        'استخدمتني النهارده؟ 😄 جرّبني!',
        'فيرست 1 كار الأفضل! 🏆🚗',
        'أحسن معرض؟ أكيد فيرست 1 كار 😉',
        'احنا الأسرع والأدق في السوق 🚀',
        'اكتبلي: "في تيجو 7 أسود؟" وشوف السحر 🔮',
        'عندي كل الأسعار محدّثة لحظة بلحظة 💰',
        'ابعتلي رقم شاسيه وأجيبلك العربية في ثانية 🔍',
        'عايز تعرف الأكثر مبيعاً؟ اسألني ⭐',
        'قول "كل حاجة عن جوليون" وهعملك ملف كامل 🧠',
        'قارنلك بين موديلين؟ قول "قارن تيجو 7 وتيجو 8" ⚖️',
        'أنا أسرع من إنك تدوّر بنفسك 😎',
        'بشحن نفسي بالقهوة الرقمية ☕🔋',
        'لو تايه بين الموديلات — أنا دليلك 🗺️',
        'شفت القادم في الطريق؟ اسألني عن الشحنات 🚚',
        'مفيش عربية تخبى عني 👀',
        'دوس عليا وجرّب — مش هتندم 👆',
        'بحلم بعربيات وأنا صاحي 🚗💭',
        'انا موجود ٢٤ ساعة — مبناش 😴 (تقريباً)',
        'العميل سأل عن لون؟ أنا أجهز في ثانية 🎨',
        'محدّش بيعرف المخزون أكتر مني 🤓',
        'يلا نبيع عربيات! 💪',
    ] : [
        "Hey! 🤖 I'm the First 1 Car assistant — ask me about any car ✨",
        'Did you use me yet today? 😄 Try me!',
        'First 1 Car is the best! 🏆🚗',
        'Best showroom? First 1 Car, obviously 😉',
        "We're the fastest and sharpest in the market 🚀",
        'Type: "black Tiggo 7?" and watch the magic 🔮',
        'All prices, live and up to date 💰',
        'Send me a chassis number — car found in a second 🔍',
        "Want what's hot right now? Just ask ⭐",
        'Say "everything about Jolion" for a full profile 🧠',
        'Compare models? Say "compare Tiggo 7 and Tiggo 8" ⚖️',
        "I'm faster than searching yourself 😎",
        'Recharging on digital coffee ☕🔋',
        'Lost between models? I\'m your map 🗺️',
        'Curious what\'s arriving? Ask me about shipments 🚚',
        'No car can hide from me 👀',
        'Tap me and try — you won\'t regret it 👆',
        'I dream about cars while awake 🚗💭',
        "I'm here 24/7 — I never sleep 😴 (almost)",
        'Customer asked about a color? Ready in one second 🎨',
        'Nobody knows the stock better than me 🤓',
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

    say(text, ms) {
        const el = document.getElementById('f1c-bot-say');
        if (!el || this.opened) return;
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
            setTimeout(() => this.say(first, 8500), 1400);
        }
        this.scheduleAct();
    },

    /* ── behavior engine: every 16-40s pick a random act ── */
    scheduleAct() {
        const wait = 7000 + Math.random() * 9000;   /* every 7-16s — lively */
        setTimeout(() => {
            if (!this.opened && !document.hidden && !this._flying && !this._sleeping) this.act();
            this.scheduleAct();
        }, wait);
    },

    act() {
        const r = Math.random();
        if      (r < 0.30) this.wander();
        else if (r < 0.48) this.carDrive();        /* 🤖→🚗 transform + drive */
        else if (r < 0.64) this.say(this.randMsg(this.MSGS));
        else if (r < 0.75) this.sleep();
        else if (r < 0.85) this.party();
        else if (r < 0.93) this.trick();
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
        const dir  = isAr ? -1 : 1;
        const far  = dir * Math.max(60, vw - 118);
        const car  = el.querySelector('.f1c-car');
        const hype = this.randMsg(isAr
            ? ['وروووم! 🚗💨 فيرست 1 كار!', 'اتحولت لعربية! 🚗', 'أسرع معرض في المدينة 🏁']
            : ['Vroom! 🚗💨 First 1 Car!', 'Transformed! 🚗', 'Fastest dealer in town 🏁']);
        /* spin, then morph to a car */
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
                if (car) car.classList.toggle('f1c-face-left', dir > 0);   /* face back home */
                const a2 = el.animate([{ transform: away }, { transform: home }], { duration: 1500, easing: 'ease-in-out' });
                this._anim = a2;
                a2.onfinish = a2.oncancel = () => {
                    el.style.transform = home;
                    this.pos = { x: 0, y: 0 };
                    el.classList.add('f1c-party');           /* spin back into a robot */
                    el.classList.remove('f1c-ascar');
                    if (car) car.classList.remove('f1c-face-left');
                    setTimeout(() => el.classList.remove('f1c-party'), 900);
                    this._flying = false;
                };
            };
        }, 500);
    },

    /* fly to a random waypoint and STAY there */
    wander(target) {
        const el = document.getElementById('f1c-chat-bubble');
        if (!el || this._flying) return;
        this._flying = true;
        this.hideGreeting();

        const isAr  = this.lang === 'ar';
        const vw    = window.innerWidth;
        const phone = vw < 560;
        const maxX  = Math.max(60, vw - 118);              /* TRUE far edge */
        const maxY  = Math.max(120, window.innerHeight - 230);
        const dir   = isAr ? -1 : 1;
        let tx, ty, tries = 0;
        if (target) { tx = target.x; ty = target.y; }
        else if (phone) {
            /* PHONES: vertical patrol on his OWN edge only — never the middle */
            tx = 0;
            do {
                ty = -(60 + Math.random() * (maxY - 60));
                tries++;
            } while (tries < 6 && Math.abs(ty - this.pos.y) < 130);
        } else {
            /* DESKTOP: patrol the full frame — 4 edges, never the middle */
            do {
                const edge = ['home-side', 'far-side', 'top', 'bottom'][Math.floor(Math.random() * 4)];
                if (edge === 'home-side') {
                    tx = 0;
                    ty = -(70 + Math.random() * (maxY - 70));
                } else if (edge === 'far-side') {
                    tx = dir * maxX;
                    ty = -(70 + Math.random() * (maxY - 70));
                } else if (edge === 'top') {
                    tx = dir * (Math.random() < 0.5 ? Math.random() * maxX * 0.3 : maxX * (0.7 + Math.random() * 0.3));
                    ty = -maxY;
                } else {
                    tx = dir * (Math.random() < 0.5 ? Math.random() * maxX * 0.3 : maxX * (0.7 + Math.random() * 0.3));
                    ty = -(Math.random() * 60);
                }
                tries++;
            } while (tries < 6 && Math.hypot(tx - this.pos.x, ty - this.pos.y) < 140);
        }
        const dist = Math.hypot(tx - this.pos.x, ty - this.pos.y);
        const dur  = Math.min(2800, Math.max(900, dist * 3.2));
        el.style.setProperty('--f1c-tilt', ((tx - this.pos.x) > 0 ? 9 : -9) + 'deg');
        el.classList.add('f1c-flying');

        const from = `translate(${this.pos.x}px, ${this.pos.y}px)`;
        const to   = `translate(${tx}px, ${ty}px)`;
        const anim = el.animate(
            [{ transform: from }, { transform: to }],
            { duration: dur, easing: 'ease-in-out' }
        );
        this._anim = anim;
        anim.onfinish = anim.oncancel = () => {
            el.style.transform = to;
            this.pos = { x: tx, y: ty };
            el.classList.remove('f1c-flying');
            /* bubble opens toward the screen — flip it on the far half */
            el.classList.toggle('f1c-far', Math.abs(tx) > vw * 0.5);
            this._flying = false;
            if (!this.opened && Math.random() < 0.45) {
                setTimeout(() => this.say(this.randMsg(this.MSGS), 6500), 400);
            }
        };
    },

    goHome() { this.wander({ x: 0, y: 0 }); },

    /* nap: eyes close, jets dim, Zzz — wakes after 7-12s */
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

    /* fireworks + spin + brand hype */
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

    /* quick 360 spin in place */
    trick() {
        const el = document.getElementById('f1c-chat-bubble');
        if (!el) return;
        el.classList.add('f1c-party');
        setTimeout(() => el.classList.remove('f1c-party'), 950);
    },

    landNow() {
        if (this._anim && this._flying) { try { this._anim.cancel(); } catch(e){} }
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

    addText(who, text) {
        const box = document.getElementById('f1c-chat-messages');
        const div = document.createElement('div');
        div.className = 'f1c-msg ' + who;
        div.textContent = text;
        box.appendChild(div);
        box.scrollTop = box.scrollHeight;
    },

    addOptions(options) {
        const box = document.getElementById('f1c-chat-messages');
        const wrap = document.createElement('div');
        wrap.className = 'f1c-options';
        options.forEach(opt => {
            const btn = document.createElement('button');
            const isBack = (opt.field === '__reset__' || opt.value === null);
            btn.className = 'f1c-chip' + (isBack ? ' f1c-chip-back' : '');
            btn.textContent = opt.label;
            btn.onclick = () => this.tap(opt);
            wrap.appendChild(btn);
        });
        box.appendChild(wrap);
        box.scrollTop = box.scrollHeight;
    },

    tap(opt) { this.addText('user', opt.label); this.call({}, opt); },

    send() {
        const input = document.getElementById('f1c-chat-input');
        const text = input.value.trim();
        if (!text) return;
        this.addText('user', text);
        input.value = '';
        this.call({ text });
    },

    async call(extra, tap) {
        const sendBtn = document.getElementById('f1c-chat-send');
        const typing = document.getElementById('f1c-chat-typing');
        sendBtn.disabled = true;
        typing.style.display = 'block';
        document.getElementById('f1c-chat-messages').scrollTop = 9e9;
        const started = Date.now();
        try {
            const body = Object.assign({ lang: this.lang, ctx: this.ctx }, extra);
            if (tap) body.tap = tap;
            const res = await fetch('chatbot_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            const data = await res.json();
            const elapsed = Date.now() - started;
            if (elapsed < 350) await new Promise(r => setTimeout(r, 350 - elapsed));
            this.ctx = data.ctx || {};
            this.addText('bot', data.message || (this.lang === 'ar' ? 'حصل خطأ، حاول تاني.' : 'Something went wrong.'));
            if (data.options && data.options.length) this.addOptions(data.options);
        } catch (e) {
            this.addText('bot', this.lang === 'ar' ? 'مشكلة في الاتصال 📡' : 'Connection problem 📡');
        } finally {
            sendBtn.disabled = false;
            typing.style.display = 'none';
        }
    }
};
document.getElementById('f1c-chat-input')?.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') F1CChat.send();
});
F1CChat.greet();
</script>
