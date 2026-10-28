<?php
/*
 * bottom_nav.php — the app's bottom bar (same look as the dashboard's) for other pages.
 *
 *   <?php $bnActive = 'basma'; include __DIR__ . '/bottom_nav.php'; ?>
 *
 * $bnActive: home | report | add | prices | basma — the page you are on glows.
 * Only the pages the person can open are shown. «المزيد» opens the dashboard's
 * «المزيد» sheet. Needs can() (auth.php) and $lang.
 */
$bnActive = $bnActive ?? '';
$bnAr = ($lang ?? 'ar') !== 'en';
$bnL = $bnAr ? ['home' => 'الرئيسية', 'report' => 'تقرير المخزون', 'add' => 'إضافة سيارة', 'prices' => 'السعر', 'basma' => 'بصمة', 'more' => 'المزيد']
             : ['home' => 'Dashboard', 'report' => 'Stock report', 'add' => 'Add vehicle', 'prices' => 'Prices', 'basma' => 'Basma', 'more' => 'More'];
$bnIcon = function (string $k): string {
    $p = [
        'home'   => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/>',
        'report' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 17v-3M12 17v-6M15 17v-2"/>',
        'prices' => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'add'    => '<path d="M12 5v14M5 12h14"/>',
        'basma'  => '<path d="M12 11v4a7 7 0 0 1-1.3 4"/><path d="M8.5 7.5A5 5 0 0 1 17 11v2.5a12 12 0 0 1-.6 3.8"/><path d="M6.3 10A6.6 6.6 0 0 1 7 8"/><path d="M5 15.5a9 9 0 0 0 1-4.5"/><path d="M9 13.5v1.5a9 9 0 0 1-1.6 5"/><path d="M5.6 5.5A9 9 0 0 1 21 11v1"/>',
        'more'   => '<rect x="4" y="4" width="6.5" height="6.5" rx="1.8"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.8"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.8"/><circle cx="16.75" cy="16.75" r="3.25"/>',
    ][$k];
    return '<svg class="bn-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
$bnQ = 'lang=' . urlencode($lang ?? 'ar');
$bnItem = function (string $k, string $href) use ($bnActive, $bnL, $bnIcon): string {
    $on = $bnActive === $k;
    return '<a href="' . $href . '" class="bn-link' . ($on ? ' on' : '') . '"' . ($on ? ' aria-current="page"' : '') . '><span class="bn-ic">' . $bnIcon($k) . '</span><span class="bn-lbl">' . $bnL[$k] . '</span></a>';
};
?>
<style>
.bn2 { position:fixed; bottom:0; left:0; right:0; z-index:999; display:flex; justify-content:space-around; align-items:flex-end; gap:2px;
  min-height:70px; padding:6px 6px calc(6px + env(safe-area-inset-bottom)); font-family:inherit;
  background:linear-gradient(180deg,rgba(15,23,42,.92),rgba(2,6,23,.98)); border-top:1px solid rgba(148,163,184,.12);
  box-shadow:0 -10px 30px rgba(0,0,0,.35); backdrop-filter:blur(20px); -webkit-backdrop-filter:blur(20px); }
.bn2 .bn-link { flex:1 1 0; min-width:0; display:flex; flex-direction:column; align-items:center; gap:4px; padding:6px 2px 4px; border-radius:14px;
  color:#7c8aa5; font-size:10.5px; font-weight:800; text-decoration:none; position:relative; -webkit-tap-highlight-color:transparent; transition:color .2s; }
.bn2 .bn-link:hover { color:#e2e8f0; }
.bn2 .bn-ic { width:44px; height:30px; display:grid; place-items:center; border-radius:999px; transition:background .25s, transform .25s cubic-bezier(.2,1.6,.4,1); }
.bn2 .bn-svg { width:23px; height:23px; }
.bn2 .bn-lbl { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%; line-height:1.2; }
.bn2 .bn-link.on { color:#4ade80; }
.bn2 .bn-link.on .bn-ic { background:rgba(34,197,94,.16); box-shadow:0 0 18px rgba(34,197,94,.25); }
.bn2 .bn-link.on::before { content:''; position:absolute; top:-7px; width:26px; height:3px; border-radius:0 0 4px 4px; background:#22c55e; box-shadow:0 0 12px #22c55e; }
.bn2 .bn-link:active .bn-ic { transform:scale(.86); }
.bn2 .bn-add { color:#cbd5e1; }
.bn2 .bn-fab { width:54px; height:54px; margin-top:-26px; border-radius:50%; display:grid; place-items:center; color:#fff;
  background:linear-gradient(135deg,#22c55e,#16a34a 55%,#9333ea); border:4px solid #020617; box-shadow:0 10px 24px rgba(34,197,94,.35); transition:transform .25s cubic-bezier(.2,1.6,.4,1); }
.bn2 .bn-fab .bn-svg { width:26px; height:26px; stroke-width:2.6; }
.bn2 .bn-add:active .bn-fab { transform:scale(.9) rotate(90deg); }
@media (min-width:900px) { .bn2 { left:50%; right:auto; transform:translateX(-50%); width:min(640px, calc(100% - 32px)); bottom:14px; border-radius:24px; border:1px solid rgba(148,163,184,.14); padding-bottom:6px; } }
</style>
<nav class="bn2" role="navigation">
    <?= $bnItem('home', 'dashboard.php?' . $bnQ) ?>
    <?php if (can('page.stock_report')): ?><?= $bnItem('report', 'stock_report.php?' . $bnQ) ?><?php endif; ?>
    <?php if (can('page.add_vehicle')): ?>
    <a href="add_vehicle.php?<?= $bnQ ?>" class="bn-link bn-add" aria-label="<?= htmlspecialchars($bnL['add']) ?>"><span class="bn-fab"><?= $bnIcon('add') ?></span><span class="bn-lbl"><?= $bnL['add'] ?></span></a>
    <?php endif; ?>
    <?php if (can('page.prices')): ?><?= $bnItem('prices', 'prices.php?' . $bnQ) ?><?php endif; ?>
    <?php if ((can('dash.nav_attendance') && can('page.attendance')) || $bnActive === 'basma'): ?><?= $bnItem('basma', 'attendance.php?' . $bnQ) ?><?php endif; ?>
    <?= $bnItem('more', 'dashboard.php?' . $bnQ . '&more=1') ?>
</nav>
