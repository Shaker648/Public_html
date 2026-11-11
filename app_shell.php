<?php
/*
 * app_shell.php — the parts every page shares, added in ONE place.
 *
 * auth.php starts it, so every signed-in page gets, without being edited:
 *   - app.css  : one Arabic font (Cairo) for the whole system, the bottom bar's
 *                look, the text-size setting, the search sheet, the offline notice
 *   - app.js   : search from any page, text size, "no connection" notice
 *   - the bottom bar (الرئيسية / تقرير المخزون / إضافة سيارة / السعر / بصمة / المزيد)
 *     on the everyday pages that did not have one
 *
 * It works on the finished page (output buffer): the two tags go in before
 * </head> and the bar before </body>. Anything that is not a full HTML page
 * (JSON, downloads) passes through untouched.
 */

/** Pages that get the bottom bar, and which item glows on them. */
function app_shell_nav_pages(): array
{
    return [
        'stock_report.php' => 'report', 'prices.php' => 'prices', 'add_vehicle.php' => 'add',
        'installments.php' => '', 'incoming_cars.php' => '', 'sold_inventory.php' => '', 'transfer_receive.php' => '',
        'vehicle_timeline.php' => '', 'sales_analytics.php' => '', 'price_history.php' => '', 'notifications.php' => '',
    ];
}

/** The bottom bar as a string (same look and rules as bottom_nav.php). */
function app_shell_nav(string $active, string $lang): string
{
    if (!function_exists('can')) return '';
    $ar = $lang !== 'en';
    $L = $ar ? ['home' => 'الرئيسية', 'report' => 'تقرير المخزون', 'add' => 'إضافة سيارة', 'prices' => 'السعر', 'basma' => 'بصمة', 'more' => 'المزيد']
             : ['home' => 'Dashboard', 'report' => 'Stock report', 'add' => 'Add vehicle', 'prices' => 'Prices', 'basma' => 'Basma', 'more' => 'More'];
    $P = [
        'home'   => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/>',
        'report' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 17v-3M12 17v-6M15 17v-2"/>',
        'prices' => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'add'    => '<path d="M12 5v14M5 12h14"/>',
        'basma'  => '<path d="M12 11v4a7 7 0 0 1-1.3 4"/><path d="M8.5 7.5A5 5 0 0 1 17 11v2.5a12 12 0 0 1-.6 3.8"/><path d="M6.3 10A6.6 6.6 0 0 1 7 8"/><path d="M5 15.5a9 9 0 0 0 1-4.5"/><path d="M9 13.5v1.5a9 9 0 0 1-1.6 5"/><path d="M5.6 5.5A9 9 0 0 1 21 11v1"/>',
        'more'   => '<rect x="4" y="4" width="6.5" height="6.5" rx="1.8"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.8"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.8"/><circle cx="16.75" cy="16.75" r="3.25"/>',
    ];
    $svg = fn($k) => '<svg class="bn-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $P[$k] . '</svg>';
    $q = 'lang=' . urlencode($lang);
    $item = function (string $k, string $href) use ($active, $L, $svg): string {
        $on = $active === $k;
        return '<a href="' . $href . '" class="bn-link' . ($on ? ' on' : '') . '"' . ($on ? ' aria-current="page"' : '') . '><span class="bn-ic">' . $svg($k) . '</span><span class="bn-lbl">' . $L[$k] . '</span></a>';
    };
    $h = '<div class="bn2-space" aria-hidden="true"></div><nav class="bn2" role="navigation">' . $item('home', 'dashboard.php?' . $q);
    if (can('page.stock_report')) $h .= $item('report', 'stock_report.php?' . $q);
    if (can('page.add_vehicle'))  $h .= '<a href="add_vehicle.php?' . $q . '" class="bn-link bn-add' . ($active === 'add' ? ' on' : '') . '" aria-label="' . htmlspecialchars($L['add']) . '"><span class="bn-fab">' . $svg('add') . '</span><span class="bn-lbl">' . $L['add'] . '</span></a>';
    if (can('page.prices'))       $h .= $item('prices', 'prices.php?' . $q);
    if (can('dash.nav_attendance') && can('page.attendance')) $h .= $item('basma', 'attendance.php?' . $q);
    return $h . $item('more', 'dashboard.php?' . $q . '&more=1') . '</nav>';
}

/** The output-buffer callback: add the shared tags (and the bar) to a full HTML page. */
function app_shell_inject(string $html): string
{
    try {
        $head = stripos($html, '</head>');
        if ($head === false || stripos($html, '<html') === false) return $html;        // not a page
        if (strpos($html, 'id="f1cAppCss"') !== false) return $html;                   // already there
        $v = @filemtime(__DIR__ . '/app.css') . '-' . @filemtime(__DIR__ . '/app.js');
        $lang = (($_GET['lang'] ?? 'ar') === 'en') ? 'en' : 'ar';
        $tags = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
              . '<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">'
              . '<link rel="stylesheet" id="f1cAppCss" href="app.css?v=' . $v . '">'
              . '<script>try{if(localStorage.getItem("f1cTextSize")==="big")document.documentElement.classList.add("f1c-big")}catch(e){}</script>'
              . '<script src="app.js?v=' . $v . '" defer data-lang="' . $lang . '"></script>';
        $html = substr($html, 0, $head) . $tags . substr($html, $head);

        $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $nav  = app_shell_nav_pages();
        if (isset($nav[$page]) && strpos($html, 'class="bn2"') === false && strpos($html, 'bottom-nav') === false && ($body = strripos($html, '</body>')) !== false) {
            $html = substr($html, 0, $body) . app_shell_nav($nav[$page], $lang) . substr($html, $body);
        }
    } catch (Throwable $e) { error_log('app_shell: ' . $e->getMessage()); }
    return $html;
}

function app_shell_start(): void
{
    static $on = false;
    if ($on || PHP_SAPI === 'cli') return;
    $on = true;
    ob_start('app_shell_inject');
}
