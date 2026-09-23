<style>
/* shared look for notifications.php / notifications_admin.php */
:root{--bg:#020617;--card:rgba(12,19,38,.86);--line:rgba(255,255,255,.08);--txt:#f1f5f9;--mut:#94a3b8;--fnt:#64748b;--g:#22c55e;--g2:#4ade80;--p:#9333ea;--p2:#a855f7;--c:#22d3ee;--r:#ef4444;--a:#f59e0b}
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;background:var(--bg);color:var(--txt);font-family:<?= ($lang ?? 'ar') === 'ar' ? "'Tajawal'" : "'Inter'" ?>,system-ui,sans-serif;-webkit-font-smoothing:antialiased;
  background-image:radial-gradient(ellipse 70% 45% at 15% -5%,rgba(34,197,94,.16),transparent),radial-gradient(ellipse 60% 45% at 95% 0%,rgba(147,51,234,.18),transparent);background-attachment:fixed;
  padding:max(18px,env(safe-area-inset-top)) 16px max(28px,env(safe-area-inset-bottom))}
.nf-wrap{max-width:1100px;margin:0 auto;display:flex;flex-direction:column;gap:16px}
.nf-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.nf-head h1{font-size:clamp(22px,4vw,30px);font-weight:900;letter-spacing:-.01em}
.nf-head p{color:var(--mut);font-size:13px;margin-top:3px}
.nf-nav{display:flex;gap:8px;flex-wrap:wrap}
.nf-btn{height:40px;padding:0 15px;border-radius:12px;display:inline-flex;align-items:center;gap:7px;font:inherit;font-size:13px;font-weight:800;text-decoration:none;color:var(--txt);background:rgba(255,255,255,.05);border:1px solid var(--line);cursor:pointer;transition:.2s}
.nf-btn:hover{border-color:rgba(168,85,247,.45)}
.nf-btn.pur{background:linear-gradient(135deg,#7c3aed,#a855f7);border-color:transparent;color:#fff}
.nf-btn.grn{background:linear-gradient(135deg,#16a34a,#22c55e);border-color:transparent;color:#fff}
.nf-btn.ghost{background:transparent}
.nf-btn:disabled{opacity:.5;cursor:not-allowed}
.nf-card{background:var(--card);border:1px solid var(--line);border-radius:24px;padding:20px;backdrop-filter:blur(20px);box-shadow:0 20px 50px rgba(0,0,0,.35)}
.nf-card h2{font-size:16px;font-weight:900;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.nf-count{margin-inline-start:auto;font-size:12px;background:rgba(168,85,247,.15);color:#d8b4fe;border-radius:999px;padding:2px 10px}
.nf-note{font-size:12px;color:var(--mut);margin:-6px 0 12px}
.nf-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.nf-empty{text-align:center;color:var(--fnt);font-size:13px;padding:18px;border:1px dashed var(--line);border-radius:14px}
.nf-list{display:flex;flex-direction:column;gap:8px}
.nf-dev{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:14px;background:rgba(255,255,255,.03);border:1px solid var(--line)}
.nf-dev .ic{width:38px;height:38px;border-radius:12px;display:grid;place-items:center;font-size:18px;background:rgba(34,197,94,.1);flex-shrink:0}
.nf-dev .mn{flex:1;min-width:0}
.nf-dev b{display:block;font-size:14px}
.nf-dev small{display:block;font-size:11px;color:var(--mut);margin-top:2px}
.nf-dev small.bad{color:#fca5a5}
.nf-mini{height:32px;min-width:32px;padding:0 10px;border-radius:10px;border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--txt);font:inherit;font-size:12px;font-weight:800;cursor:pointer}
.nf-mini.red{color:#fca5a5;border-color:rgba(239,68,68,.3)}
.nf-chips{display:flex;flex-wrap:wrap;gap:6px}
.nf-chips span{font-size:12px;font-weight:700;padding:6px 11px;border-radius:999px;background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.22)}
.nf-feed{display:flex;flex-direction:column;gap:8px;max-height:520px;overflow-y:auto}
.nf-item{display:block;text-decoration:none;color:inherit;padding:11px 14px;border-radius:14px;background:rgba(255,255,255,.03);border:1px solid var(--line);transition:.2s}
.nf-item:hover{border-color:rgba(34,197,94,.35)}
.nf-item .t{font-size:14px;font-weight:800}
.nf-item .b{font-size:12px;color:#cbd5e1;margin-top:3px;line-height:1.7}
.nf-item .w{font-size:11px;color:var(--fnt);margin-top:4px}

/* the device card */
.nf-dc{position:relative;overflow:hidden;border-radius:26px;padding:22px;border:1px solid rgba(34,197,94,.25);
  background:linear-gradient(135deg,rgba(34,197,94,.12),rgba(12,19,38,.92) 45%,rgba(147,51,234,.14))}
.nf-dc .top{display:flex;align-items:center;gap:14px;margin-bottom:14px}
.nf-dc .bell{width:62px;height:62px;border-radius:20px;display:grid;place-items:center;font-size:30px;flex-shrink:0;background:linear-gradient(135deg,#16a34a,#7c3aed);box-shadow:0 10px 30px rgba(34,197,94,.3)}
.nf-dc.on .bell{animation:ring 2.6s ease-in-out infinite}
@keyframes ring{0%,80%,100%{transform:rotate(0)}84%{transform:rotate(14deg)}88%{transform:rotate(-12deg)}92%{transform:rotate(8deg)}96%{transform:rotate(-4deg)}}
.nf-dc h3{font-size:18px;font-weight:900}
.nf-dc .st{font-size:13px;color:var(--mut);margin-top:3px}
.nf-dc .st b{color:var(--g2)}
.nf-dc.warn{border-color:rgba(245,158,11,.35);background:linear-gradient(135deg,rgba(245,158,11,.12),rgba(12,19,38,.92) 50%)}
.nf-dc.bad{border-color:rgba(239,68,68,.35);background:linear-gradient(135deg,rgba(239,68,68,.12),rgba(12,19,38,.92) 50%)}
.nf-acts{display:flex;gap:8px;flex-wrap:wrap}
.nf-big{height:54px;padding:0 22px;border-radius:16px;border:0;font:inherit;font-size:16px;font-weight:900;color:#fff;cursor:pointer;
  background:linear-gradient(90deg,#16a34a,#22c55e 40%,#06b6d4 70%,#9333ea);background-size:200% 100%;box-shadow:0 10px 30px rgba(34,197,94,.3);animation:shift 4s ease-in-out infinite}
@keyframes shift{50%{background-position:100% 0}}
.nf-big:disabled{opacity:.6;animation:none}
.nf-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:6px 0 4px}
.nf-step{padding:14px;border-radius:18px;background:rgba(2,6,23,.55);border:1px solid var(--line);text-align:center}
.nf-step .n{width:28px;height:28px;border-radius:50%;margin:0 auto 8px;display:grid;place-items:center;font-size:13px;font-weight:900;background:linear-gradient(135deg,#22c55e,#9333ea)}
.nf-step .v{height:58px;display:grid;place-items:center;margin-bottom:8px}
.nf-step .v svg{height:46px;width:auto}
.nf-step .v .pill{display:inline-flex;align-items:center;gap:6px;white-space:nowrap;padding:8px 11px;border-radius:12px;background:#1c1c1e;color:#fff;font-size:13px;font-weight:600;border:1px solid #3a3a3c}
.nf-step .v .app{width:50px;height:50px;border-radius:13px;background:url(icons/logo.png?v=3) center/cover;box-shadow:0 6px 18px rgba(0,0,0,.4)}
.nf-step p{font-size:13px;line-height:1.6;color:#e2e8f0}
.nf-step p b{color:var(--g2)}
.nf-msg{font-size:13px;font-weight:700;margin-top:10px;min-height:18px}
.nf-msg.ok{color:var(--g2)}.nf-msg.bad{color:#fca5a5}
.nf-help{font-size:13px;line-height:1.8;color:#e2e8f0;background:rgba(2,6,23,.5);border:1px solid var(--line);border-radius:16px;padding:12px 14px;margin-bottom:12px}
.nf-help b{color:#fde68a}
.nf-spin{width:16px;height:16px;border-radius:50%;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;display:inline-block;animation:sp .7s linear infinite;vertical-align:-3px}
@keyframes sp{to{transform:rotate(360deg)}}
@media (max-width:760px){.nf-grid{grid-template-columns:1fr}.nf-steps{grid-template-columns:1fr}.nf-step .v .pill{font-size:11px;padding:7px 9px}.nf-step{display:grid;grid-template-columns:auto 1fr;align-items:center;gap:8px 12px;text-align:start}.nf-step .v{justify-content:start}.nf-step p{grid-column:1/-1}.nf-step .n{margin:0}.nf-step .v{margin:0;height:auto}}
@media (prefers-reduced-motion:reduce){*{animation:none!important}}
</style>
