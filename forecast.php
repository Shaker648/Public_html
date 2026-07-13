<?php
/*
 * forecast.php — Inventory Intelligence & Market Insight (admin only)
 *
 * A deep, written-analysis dashboard focused entirely on the CARS:
 *   - What sells fastest / slowest
 *   - What to reorder and what to stop buying (with reasons)
 *   - Which colors move and which stall
 *   - Stock aging and dead stock
 *   - Branch performance patterns
 *   - حالة السوق (market state) — an auto-written narrative
 *
 * NO cost / revenue / profit. Units, velocity, colors, aging only.
 * Self-contained: a built-in analysis engine writes the narratives.
 * No external API, no schema changes.
 */

require 'auth.php';
require 'config.php';

perm_require('page.forecast');

$lang = $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar', 'en'], true)) $lang = 'ar';
$dir = $lang === 'ar' ? 'rtl' : 'ltr';

/* ─── Time window ─── */
$windowOptions = [90, 180, 365];
$window = (int)($_GET['win'] ?? 90);
if (!in_array($window, $windowOptions, true)) $window = 90;
$months = max(1, $window / 30.0);
$since  = date('Y-m-d H:i:s', strtotime("-{$window} days"));

/* ════════════════════════════════════════════════════════════
   DATA COLLECTION
════════════════════════════════════════════════════════════ */

// Current available stock
$stockNow = (int)$pdo->query("SELECT COUNT(*) FROM cars WHERE status='available'")->fetchColumn();

// Sold in window
$soldStmt = $pdo->prepare("SELECT COUNT(*) FROM sold_cars WHERE sold_at >= ?");
$soldStmt->execute([$since]);
$soldWindow = (int)$soldStmt->fetchColumn();

// Previous window (for trend comparison)
$prevSince = date('Y-m-d H:i:s', strtotime("-" . ($window * 2) . " days"));
$prevStmt  = $pdo->prepare("SELECT COUNT(*) FROM sold_cars WHERE sold_at >= ? AND sold_at < ?");
$prevStmt->execute([$prevSince, $since]);
$soldPrev = (int)$prevStmt->fetchColumn();

// Avg days-to-sell
$avgStmt = $pdo->prepare("
    SELECT AVG(DATEDIFF(sc.sold_at, c.created_at)) AS avg_days,
           MIN(DATEDIFF(sc.sold_at, c.created_at)) AS min_days,
           MAX(DATEDIFF(sc.sold_at, c.created_at)) AS max_days
    FROM sold_cars sc JOIN cars c ON c.id = sc.car_id
    WHERE sc.sold_at >= ? AND c.created_at IS NOT NULL
");
$avgStmt->execute([$since]);
$daysRow = $avgStmt->fetch(PDO::FETCH_ASSOC);
$avgDays = $daysRow['avg_days'] !== null ? round((float)$daysRow['avg_days']) : null;
$minDays = $daysRow['min_days'] !== null ? (int)$daysRow['min_days'] : null;
$maxDays = $daysRow['max_days'] !== null ? (int)$daysRow['max_days'] : null;

$runRate  = $months > 0 ? $soldWindow / $months : 0;
$coverage = $runRate > 0 ? $stockNow / $runRate : null;
$trendPct = $soldPrev > 0 ? round(($soldWindow - $soldPrev) / $soldPrev * 100) : ($soldWindow > 0 ? 100 : 0);

/* ─── Sold per (brand,model) in window ─── */
$velStmt = $pdo->prepare("
    SELECT c.brand, c.model, COUNT(*) AS sold,
           AVG(DATEDIFF(sc.sold_at, c.created_at)) AS avg_dts
    FROM sold_cars sc JOIN cars c ON c.id = sc.car_id
    WHERE sc.sold_at >= ?
    GROUP BY c.brand, c.model
");
$velStmt->execute([$since]);
$soldByModel = [];
foreach ($velStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $soldByModel[$r['brand'].'||'.$r['model']] = [
        'sold' => (int)$r['sold'],
        'dts'  => $r['avg_dts'] !== null ? round((float)$r['avg_dts']) : null,
    ];
}

// Current stock per (brand,model)
$stockByModel = [];
foreach ($pdo->query("SELECT brand, model, COUNT(*) AS cnt FROM cars WHERE status='available' GROUP BY brand, model")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $stockByModel[$r['brand'].'||'.$r['model']] = (int)$r['cnt'];
}

$allKeys = array_unique(array_merge(array_keys($soldByModel), array_keys($stockByModel)));
$velocity = [];
foreach ($allKeys as $k) {
    [$brand, $model] = explode('||', $k);
    $sold  = $soldByModel[$k]['sold'] ?? 0;
    $dts   = $soldByModel[$k]['dts'] ?? null;
    $stock = $stockByModel[$k] ?? 0;
    if ($sold === 0 && $stock === 0) continue;   // skip empty rows entirely
    $rate  = $months > 0 ? $sold / $months : 0;
    $cover = $rate > 0 ? $stock / $rate : null;
    $velocity[] = compact('brand','model','sold','stock','rate','cover','dts');
}
usort($velocity, fn($a, $b) => ($b['sold'] <=> $a['sold']) ?: ($b['rate'] <=> $a['rate']));

/* ─── Reorder classification (with detailed reasoning) ─── */
$reorder = ['restock' => [], 'hold' => [], 'watch' => [], 'dead' => []];
foreach ($velocity as $v) {
    $rate = $v['rate']; $stock = $v['stock']; $cover = $v['cover']; $sold = $v['sold'];

    if ($sold === 0 && $stock === 0) continue;

    if ($sold === 0 && $stock >= 1) {
        // Never sold in window but sitting in stock = dead stock risk
        $reorder['dead'][] = $v;
    } elseif ($rate >= 1 && ($stock === 0 || ($cover !== null && $cover < 1.0))) {
        $reorder['restock'][] = $v;
    } elseif ($rate < 0.5 && $stock >= 3) {
        $reorder['hold'][] = $v;
    } else {
        $reorder['watch'][] = $v;
    }
}

/* ─── STOCK vs DEMAND MISMATCH (most actionable) ───
   Overstocked: high stock, slow sales (capital tied up).
   Understocked: fast sales, thin stock (about to lose sales). */
$overstocked  = [];  // stock high relative to its own sell rate
$understocked = [];  // selling fast but coverage < 1.5 months
foreach ($velocity as $v) {
    // Understocked: selling AND less than ~1.5 months of cover
    if ($v['sold'] > 0 && $v['cover'] !== null && $v['cover'] < 1.5) {
        $understocked[] = $v;
    }
    // Overstocked: 4+ in stock AND more than 4 months cover (or slow + stocked)
    if ($v['stock'] >= 4 && ($v['cover'] === null || $v['cover'] > 4)) {
        $overstocked[] = $v;
    }
}
// Sort: understocked by rate desc (most urgent), overstocked by stock desc
usort($understocked, fn($a, $b) => $b['rate'] <=> $a['rate']);
usort($overstocked,  fn($a, $b) => $b['stock'] <=> $a['stock']);
// Sold by color in window
$colorSoldStmt = $pdo->prepare("
    SELECT COALESCE(col.color_ar, c.color) AS c_ar,
           COALESCE(col.color_en, c.color) AS c_en,
           c.color AS raw,
           COUNT(*) AS sold,
           AVG(DATEDIFF(sc.sold_at, c.created_at)) AS dts
    FROM sold_cars sc
    JOIN cars c ON c.id = sc.car_id
    LEFT JOIN colors col ON c.color = col.color_en
    WHERE sc.sold_at >= ?
    GROUP BY c.color
    ORDER BY sold DESC
");
$colorSoldStmt->execute([$since]);
$colorSold = $colorSoldStmt->fetchAll(PDO::FETCH_ASSOC);

// Available stock by color
$colorStock = [];
foreach ($pdo->query("SELECT color, COUNT(*) AS cnt FROM cars WHERE status='available' GROUP BY color")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $colorStock[$r['color']] = (int)$r['cnt'];
}
$totalColorSold = array_sum(array_column($colorSold, 'sold'));

// Build merged color insight
$colorInsight = [];
foreach ($colorSold as $cs) {
    $raw = $cs['raw'];
    $colorInsight[$raw] = [
        'ar'    => $cs['c_ar'],
        'en'    => $cs['c_en'],
        'sold'  => (int)$cs['sold'],
        'stock' => $colorStock[$raw] ?? 0,
        'dts'   => $cs['dts'] !== null ? round((float)$cs['dts']) : null,
        'share' => $totalColorSold > 0 ? round((int)$cs['sold'] / $totalColorSold * 100) : 0,
    ];
}
// Colors sitting in stock but never sold in window
foreach ($colorStock as $raw => $cnt) {
    if (!isset($colorInsight[$raw])) {
        $colorInsight[$raw] = ['ar'=>$raw,'en'=>$raw,'sold'=>0,'stock'=>$cnt,'dts'=>null,'share'=>0];
    }
}
uasort($colorInsight, fn($a, $b) => $b['sold'] <=> $a['sold']);

/* ─── SLOW MOVERS (aging) ─── */
$slowMovers = $pdo->query("
    SELECT c.brand, c.model, c.trim_name, c.chassis, c.branch, c.color, c.created_at,
           DATEDIFF(NOW(), c.created_at) AS age_days,
           b.name_ar, b.name_en, col.color_ar, col.color_en
    FROM cars c
    LEFT JOIN branches b ON b.name = c.branch
    LEFT JOIN colors col ON c.color = col.color_en
    WHERE c.status='available' AND c.created_at IS NOT NULL
    ORDER BY c.created_at ASC
    LIMIT 12
")->fetchAll(PDO::FETCH_ASSOC);

// Aging buckets (for whole available stock)
$ageBuckets = ['fresh'=>0,'normal'=>0,'aging'=>0,'stale'=>0];
foreach ($pdo->query("SELECT DATEDIFF(NOW(), created_at) AS d FROM cars WHERE status='available' AND created_at IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $d) {
    $d = (int)$d;
    if ($d < 30)       $ageBuckets['fresh']++;
    elseif ($d < 60)   $ageBuckets['normal']++;
    elseif ($d < 120)  $ageBuckets['aging']++;
    else               $ageBuckets['stale']++;
}

/* ─── MONTHLY TREND (12 months) ─── */
$trendRows = $pdo->query("
    SELECT DATE_FORMAT(sold_at,'%Y-%m') AS ym, COUNT(*) AS cnt
    FROM sold_cars WHERE sold_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY ym ORDER BY ym ASC
")->fetchAll(PDO::FETCH_ASSOC);
$trendMap = [];
foreach ($trendRows as $r) $trendMap[$r['ym']] = (int)$r['cnt'];
$trendLabels = []; $trendData = [];
for ($i = 11; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-{$i} months"));
    $trendLabels[] = date('M y', strtotime($ym.'-01'));
    $trendData[]   = $trendMap[$ym] ?? 0;
}

/* ─── BY BRAND (window) ─── */
$brandStmt = $pdo->prepare("
    SELECT c.brand, COUNT(*) AS sold
    FROM sold_cars sc JOIN cars c ON c.id = sc.car_id
    WHERE sc.sold_at >= ? GROUP BY c.brand ORDER BY sold DESC
");
$brandStmt->execute([$since]);
$byBrand = $brandStmt->fetchAll(PDO::FETCH_ASSOC);

// Stock by brand (to compute brand-level coverage)
$stockByBrand = [];
foreach ($pdo->query("SELECT brand, COUNT(*) AS c FROM cars WHERE status='available' GROUP BY brand")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $stockByBrand[$r['brand']] = (int)$r['c'];
}

/* ─── BY BRANCH (window) ─── */
$branchStmt = $pdo->prepare("
    SELECT sc.sold_branch, COUNT(*) AS sold, b.name_ar, b.name_en
    FROM sold_cars sc LEFT JOIN branches b ON b.name = sc.sold_branch
    WHERE sc.sold_at >= ? GROUP BY sc.sold_branch ORDER BY sold DESC
");
$branchStmt->execute([$since]);
$byBranch = $branchStmt->fetchAll(PDO::FETCH_ASSOC);
$stockByBranch = [];
foreach ($pdo->query("SELECT branch, COUNT(*) AS c FROM cars WHERE status='available' GROUP BY branch")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $stockByBranch[$r['branch']] = (int)$r['c'];
}

/* ─── Top salesman (window) — context only ─── */
$topSeller = $pdo->prepare("
    SELECT salesman, COUNT(*) AS sold FROM sold_cars
    WHERE sold_at >= ? AND salesman <> '' GROUP BY salesman ORDER BY sold DESC LIMIT 1
");
$topSeller->execute([$since]);
$topSellerRow = $topSeller->fetch(PDO::FETCH_ASSOC);

/* ════════════════════════════════════════════════════════════
   ANALYSIS ENGINE — writes professional narratives from the data.
   Bilingual. Each function returns ready HTML paragraphs.
════════════════════════════════════════════════════════════ */

function fmtRate($r) {
    if ($r <= 0) return '0';
    if ($r < 0.1) return '<0.1';
    return number_format($r, 1);
}

/* ── Human-readable sell pace: never shows impossible decimals like "7.7 cars" ──
   Fast (>=1/mo): "~8/mo" (whole cars).
   Slow (<1/mo): flips to "1 every ~3 mo" — how people actually think.
   $short=true → compact for table cells/badges. */
function paceHuman($ratePerMonth, $lang, $short = false) {
    if ($ratePerMonth <= 0) {
        return $lang === 'ar' ? ($short ? '—' : 'لا مبيعات') : ($short ? '—' : 'no sales');
    }
    // Fast: roughly one or more per month → round to whole cars/month.
    // (0.65+ rounds up to ~1/mo rather than the awkward "1 every 2 months".)
    if ($ratePerMonth >= 0.65) {
        $n = (int) round($ratePerMonth);
        if ($n < 1) $n = 1;
        if ($lang === 'ar') return $short ? "~{$n}/شهر" : "~{$n} سيارة/شهر";
        return $short ? "~{$n}/mo" : "~{$n} cars/mo";
    }
    // Slow: clearly less than one per month → "1 every N months"
    $everyMonths = (int) round(1 / $ratePerMonth);
    if ($everyMonths < 2) $everyMonths = 2;
    if ($everyMonths >= 12) {
        return $lang === 'ar' ? ($short ? '1/سنة' : 'سيارة كل سنة') : ($short ? '1/yr' : '1 per year');
    }
    if ($lang === 'ar') return $short ? "1 كل {$everyMonths}ش" : "سيارة كل ~{$everyMonths} أشهر";
    return $short ? "1 / {$everyMonths}mo" : "1 every ~{$everyMonths} months";
}
function ageClass($d) {
    if ($d >= 120) return 'age-late';
    if ($d >= 60)  return 'age-warn';
    return 'age-ok';
}

/* ── Human-readable coverage: turn "0.7 months" into plain language ──
   Converts a months value into weeks/days/months that anyone understands.
   $short=true gives a compact version for tight spaces (tables/badges). */
function coverHuman($months, $lang, $short = false) {
    if ($months === null) {
        return $lang === 'ar' ? ($short ? 'وفير' : 'مخزون وفير') : ($short ? 'ample' : 'ample stock');
    }
    $days = (int) round($months * 30);

    if ($lang === 'ar') {
        if ($days <= 3)   return $short ? 'أيام' : 'أيام معدودة';
        if ($days <= 10)  return $short ? "~{$days} يوم" : "حوالي {$days} أيام";
        if ($days < 25) { $w = max(1, (int)round($days / 7)); return $short ? "~{$w} أسابيع" : "حوالي {$w} أسابيع"; }
        if ($days < 45)  return $short ? 'شهر' : 'حوالي شهر';
        $m = $months;
        if ($m < 1.75)   return $short ? 'شهر ونصف' : 'حوالي شهر ونصف';
        if ($m < 2.5)    return $short ? 'شهرين' : 'حوالي شهرين';
        if ($m < 3.5)    return $short ? '~3 أشهر' : 'حوالي 3 أشهر';
        if ($m < 4.5)    return $short ? '~4 أشهر' : 'حوالي 4 أشهر';
        if ($m < 6.5)    return $short ? '~' . round($m) . ' أشهر' : 'حوالي ' . round($m) . ' أشهر';
        if ($m < 12)     return $short ? '~' . round($m) . ' شهر' : 'حوالي ' . round($m) . ' شهر';
        return $short ? 'أكثر من سنة' : 'أكثر من سنة';
    } else {
        if ($days <= 3)   return $short ? 'days' : 'a few days';
        if ($days <= 10)  return $short ? "~{$days} days" : "about {$days} days";
        if ($days < 25) { $w = max(1, (int)round($days / 7)); return $short ? "~{$w} wks" : "about {$w} weeks"; }
        if ($days < 45)  return $short ? '~1 mo' : 'about a month';
        $m = $months;
        if ($m < 1.75)   return $short ? '~6 wks' : 'about 6 weeks';
        if ($m < 2.5)    return $short ? '~2 mo' : 'about 2 months';
        if ($m < 3.5)    return $short ? '~3 mo' : 'about 3 months';
        if ($m < 4.5)    return $short ? '~4 mo' : 'about 4 months';
        if ($m < 6.5)    return $short ? '~' . round($m) . ' mo' : 'about ' . round($m) . ' months';
        if ($m < 12)     return $short ? '~' . round($m) . ' mo' : 'about ' . round($m) . ' months';
        return $short ? '1 yr+' : 'over a year';
    }
}

/* ── 1. MARKET STATE (حالة السوق) — the headline narrative ── */
function marketStateNarrative($lang, $soldWindow, $soldPrev, $trendPct, $avgDays, $coverage, $window, $velocity, $reorder, $stockNow) {
    $fast = $velocity[0] ?? null;
    $restockN = count($reorder['restock']);
    $deadN    = count($reorder['dead']);
    $winTxt   = $window === 90 ? ($lang==='ar'?'٩٠ يوم':'90 days') : ($window===180 ? ($lang==='ar'?'٦ أشهر':'6 months') : ($lang==='ar'?'سنة':'12 months'));

    // ── Empty state: no sales at all in this window ──
    if ($soldWindow === 0) {
        if ($lang === 'ar') {
            $p = ["لا توجد مبيعات مسجّلة خلال آخر {$winTxt}."];
            if ($stockNow > 0) {
                $p[] = "لديك حالياً <strong>{$stockNow} سيارة</strong> في المخزون المتاح. بمجرد تسجيل عمليات بيع، ستظهر هنا تحليلات تفصيلية عن أسرع الموديلات والألوان مبيعاً وتوصيات الطلب.";
            } else {
                $p[] = "لا يوجد مخزون متاح حالياً أيضاً. أضف سيارات وسجّل المبيعات لبدء التحليل.";
            }
            $p[] = "💡 جرّب توسيع الفترة الزمنية (٦ أشهر أو سنة) إن كانت لديك مبيعات أقدم.";
            return $p;
        } else {
            $p = ["No sales recorded in the last {$winTxt}."];
            if ($stockNow > 0) {
                $p[] = "You currently hold <strong>{$stockNow} cars</strong> in available stock. Once sales are recorded, detailed analysis of your fastest models, colors, and reorder guidance will appear here.";
            } else {
                $p[] = "There's also no available stock right now. Add cars and record sales to begin the analysis.";
            }
            $p[] = "💡 Try widening the time window (6 months or 1 year) if you have older sales.";
            return $p;
        }
    }

    // Determine overall market temperature
    $temp = 'stable';
    if ($trendPct >= 20) $temp = 'hot';
    elseif ($trendPct <= -20) $temp = 'cold';

    if ($lang === 'ar') {
        $p = [];
        // Opening — temperature
        if ($temp === 'hot') {
            $p[] = "السوق في حالة <strong>نشطة وصاعدة</strong> خلال آخر {$winTxt}. ارتفعت المبيعات بنسبة <strong>{$trendPct}%</strong> مقارنة بالفترة السابقة (بيعت {$soldWindow} سيارة مقابل {$soldPrev}). هذا زخم إيجابي قوي، والقرار الأهم الآن هو ضمان عدم نفاد الموديلات الأكثر طلباً.";
        } elseif ($temp === 'cold') {
            $absPct = abs($trendPct);
            $p[] = "السوق في حالة <strong>تباطؤ</strong> خلال آخر {$winTxt}. انخفضت المبيعات بنسبة <strong>{$absPct}%</strong> مقارنة بالفترة السابقة ({$soldWindow} سيارة مقابل {$soldPrev}). هذا يستدعي الحذر في الطلبات الجديدة والتركيز على تصريف المخزون الراكد قبل ضخ سيارات إضافية.";
        } else {
            $p[] = "السوق في حالة <strong>مستقرة</strong> خلال آخر {$winTxt}، حيث بيعت {$soldWindow} سيارة بمعدل قريب من الفترة السابقة ({$soldPrev}). الاستقرار فرصة جيدة لإعادة التوازن بين الموديلات بدلاً من التوسع العشوائي.";
        }

        // Fastest model spotlight
        if ($fast && $fast['sold'] > 0) {
            $p[] = "🏆 الموديل الأكثر مبيعاً هو <strong>{$fast['brand']} {$fast['model']}</strong> بـ <strong>{$fast['sold']}</strong> سيارة" . ($fast['dts'] !== null ? " (متوسط بيع {$fast['dts']} يوم)" : "") . ". احرص على توفّره دائماً.";
        }

        // Speed of sale
        if ($avgDays !== null) {
            if ($avgDays <= 30) {
                $p[] = "متوسط مدة بيع السيارة <strong>{$avgDays} يوم فقط</strong> — وهذا ممتاز ويدل على أن التسعير والاختيار في محلهما. السيارات لا تبقى طويلاً، لذا سرعة إعادة التوريد هي ما يحكم حجم مبيعاتك.";
            } elseif ($avgDays <= 60) {
                $p[] = "متوسط مدة بيع السيارة <strong>{$avgDays} يوم</strong> — معدل صحي ومقبول. هناك مجال لتحسينه عبر التركيز على الموديلات والألوان الأسرع دوراناً.";
            } else {
                $p[] = "متوسط مدة بيع السيارة <strong>{$avgDays} يوم</strong> — وهي فترة طويلة نسبياً تعني أن رأس المال محتجز في المخزون لفترات أطول. يُنصح بمراجعة الموديلات بطيئة الحركة والتفاوض على أسعارها.";
            }
        }

        // Coverage
        if ($coverage !== null) {
            if ($coverage < 1) {
                $p[] = "⚠️ <strong>تنبيه مخزون:</strong> بالمعدل الحالي، مخزونك يكفي أقل من <strong>شهر واحد</strong> فقط. أنت معرّض لفقدان مبيعات بسبب نفاد السيارات. التوريد العاجل ضروري، خصوصاً للموديلات المذكورة في قسم التوصيات.";
            } elseif ($coverage <= 3) {
                $p[] = "مخزونك الحالي يكفي <strong>" . coverHuman($coverage,$lang) . "</strong> بالمعدل الحالي — وهو توازن جيد بين عدم التكدّس وعدم النفاد.";
            } else {
                $p[] = "مخزونك يكفي <strong>" . coverHuman($coverage,$lang) . "</strong> — وهو مخزون مرتفع نسبياً. التوسع في الشراء الآن قد يزيد من تكدّس رأس المال؛ الأولوية لتصريف الموجود.";
            }
        } elseif ($stockNow > 0) {
            $p[] = "لديك <strong>{$stockNow} سيارة</strong> في المخزون، ولكن لا توجد مبيعات حديثة لحساب معدل التغطية. راجع الموديلات الراكدة في الأسفل.";
        }

        // Action summary
        $bits = [];
        if ($restockN > 0) $bits[] = "<strong>{$restockN}</strong> موديل بحاجة لإعادة طلب عاجلة";
        if ($deadN > 0)    $bits[] = "<strong>{$deadN}</strong> موديل راكد لم يُبَع نهائياً";
        if ($bits) {
            $p[] = "الخلاصة التنفيذية: " . implode("، و", $bits) . ". التفاصيل والأسباب في الأقسام التالية.";
        }
        return $p;
    } else {
        $p = [];
        if ($temp === 'hot') {
            $p[] = "The market is <strong>active and rising</strong> over the last {$winTxt}. Sales are up <strong>{$trendPct}%</strong> versus the prior period ({$soldWindow} cars vs {$soldPrev}). This is strong positive momentum — the priority now is making sure your fastest models don't run out.";
        } elseif ($temp === 'cold') {
            $absPct = abs($trendPct);
            $p[] = "The market is <strong>slowing</strong> over the last {$winTxt}. Sales fell <strong>{$absPct}%</strong> versus the prior period ({$soldWindow} vs {$soldPrev}). Be cautious with new orders and prioritise clearing slow stock before adding more units.";
        } else {
            $p[] = "The market is <strong>stable</strong> over the last {$winTxt}, with {$soldWindow} cars sold — close to the prior period ({$soldPrev}). Stability is a good moment to rebalance your model mix rather than expand blindly.";
        }
        // Fastest model spotlight
        if ($fast && $fast['sold'] > 0) {
            $p[] = "🏆 Your best-selling model is the <strong>{$fast['brand']} {$fast['model']}</strong> with <strong>{$fast['sold']}</strong> sold" . ($fast['dts'] !== null ? " (avg {$fast['dts']} days to sell)" : "") . ". Keep it in stock at all times.";
        }
        if ($avgDays !== null) {
            if ($avgDays <= 30) $p[] = "Average time-to-sell is just <strong>{$avgDays} days</strong> — excellent, and a sign your pricing and selection are on point. Cars don't sit long, so your restock speed is what caps your sales volume.";
            elseif ($avgDays <= 60) $p[] = "Average time-to-sell is <strong>{$avgDays} days</strong> — a healthy pace, with room to improve by leaning into faster-turning models and colors.";
            else $p[] = "Average time-to-sell is <strong>{$avgDays} days</strong> — relatively long, meaning capital is tied up in stock. Review slow models and renegotiate their pricing.";
        }
        if ($coverage !== null) {
            if ($coverage < 1) $p[] = "⚠️ <strong>Stock alert:</strong> at the current pace your stock lasts under <strong>one month</strong>. You risk losing sales to stockouts — urgent restocking is needed, especially for the models in the recommendations below.";
            elseif ($coverage <= 3) $p[] = "Current stock covers <strong>" . coverHuman($coverage,$lang) . "</strong> at this pace — a good balance between overstock and stockout.";
            else $p[] = "Stock covers <strong>" . coverHuman($coverage,$lang) . "</strong> — relatively high. Expanding purchases now risks tying up capital; prioritise clearing what you have.";
        } elseif ($stockNow > 0) {
            $p[] = "You hold <strong>{$stockNow} cars</strong> in stock, but there are no recent sales to compute a coverage rate. Review the dead-stock models below.";
        }
        $bits = [];
        if ($restockN > 0) $bits[] = "<strong>{$restockN}</strong> models need urgent reordering";
        if ($deadN > 0)    $bits[] = "<strong>{$deadN}</strong> dead-stock models with zero sales";
        if ($bits) $p[] = "Executive summary: " . implode(", and ", $bits) . ". Details and reasoning follow below.";
        return $p;
    }
}

/* ── 2. Reorder reasoning per item ── */
function reorderReason($v, $bucket, $lang) {
    $stock = $v['stock']; $sold = $v['sold'];
    $pace  = paceHuman($v['rate'], $lang, true);
    $cover = coverHuman($v['cover'], $lang, true);
    if ($lang === 'ar') {
        switch ($bucket) {
            case 'restock': return "بيع منه {$sold} ومخزونه {$stock} فقط — يكفي {$cover}. اطلب الآن قبل النفاد.";
            case 'hold':    return "بطيء الحركة ({$pace}) ومخزونه مرتفع ({$stock}). لا تطلب المزيد؛ ركّز على تصريفه.";
            case 'dead':    return "لم يُبَع نهائياً خلال الفترة ومخزونه {$stock}. رأس مال مجمّد — فكّر في خصم أو نقله بين الفروع.";
            default:        return "بيع منه {$sold} ومخزونه {$stock}. وضع متوازن — راقب دون إجراء عاجل.";
        }
    } else {
        switch ($bucket) {
            case 'restock': return "Sold {$sold} with only {$stock} in stock — lasts {$cover}. Order now before stockout.";
            case 'hold':    return "Slow mover ({$pace}) with high stock ({$stock}). Don't order more; focus on clearing it.";
            case 'dead':    return "Zero sales in the window with {$stock} in stock. Frozen capital — consider a discount or branch transfer.";
            default:        return "Sold {$sold} with {$stock} in stock. Balanced — monitor, no urgent action.";
        }
    }
}

/* ── 3. Color narrative ── */
function colorNarrative($lang, $colorInsight, $totalColorSold) {
    if ($totalColorSold === 0) return [];
    $arr = array_values($colorInsight);
    $top = $arr[0] ?? null;
    $top2 = $arr[1] ?? null;
    // Find a color with stock but no/low sales
    $stale = null;
    foreach ($arr as $c) { if ($c['sold'] === 0 && $c['stock'] >= 2) { $stale = $c; break; } }

    $p = [];
    if ($lang === 'ar') {
        if ($top) {
            $name = $top['ar'];
            $p[] = "اللون الأكثر مبيعاً هو <strong>{$name}</strong> بنسبة <strong>{$top['share']}%</strong> من إجمالي المبيعات ({$top['sold']} سيارة). عند الطلب الجديد، اجعل هذا اللون أولوية لأنه يدور بسرعة." . ($top['stock'] > 0 ? " متوفر منه حالياً {$top['stock']} في المخزون." : " ⚠️ <strong>لا يوجد منه مخزون حالياً</strong> — فرصة بيع ضائعة.");
        }
        if ($top2 && $top2['sold'] > 0) {
            $p[] = "يليه <strong>{$top2['ar']}</strong> ({$top2['share']}% — {$top2['sold']} سيارة). هذان اللونان يشكّلان العمود الفقري للطلب؛ ركّز عليهما في التوريد.";
        }
        if ($stale) {
            $p[] = "في المقابل، لون <strong>{$stale['ar']}</strong> لديه {$stale['stock']} سيارة في المخزون دون أي مبيعات خلال الفترة. هذا اللون بطيء — تجنّب طلب المزيد منه، وفكّر في تسعير تشجيعي لتصريفه.";
        }
    } else {
        if ($top) {
            $name = $top['en'];
            $p[] = "The best-selling color is <strong>{$name}</strong> at <strong>{$top['share']}%</strong> of all sales ({$top['sold']} cars). Make it a priority when ordering — it turns over fast." . ($top['stock'] > 0 ? " You currently hold {$top['stock']} in stock." : " ⚠️ <strong>You have none in stock</strong> — a missed sales opportunity.");
        }
        if ($top2 && $top2['sold'] > 0) {
            $p[] = "Next is <strong>{$top2['en']}</strong> ({$top2['share']}% — {$top2['sold']} cars). These two colors form the backbone of demand; lean into them when restocking.";
        }
        if ($stale) {
            $p[] = "By contrast, <strong>{$stale['en']}</strong> has {$stale['stock']} units in stock with zero sales this period. It's a slow color — avoid ordering more and consider an incentive price to clear it.";
        }
    }
    return $p;
}

/* ── 4. Aging narrative ── */
function agingNarrative($lang, $ageBuckets, $slowMovers, $stockNow) {
    $stale = $ageBuckets['stale']; $aging = $ageBuckets['aging'];
    $oldest = $slowMovers[0] ?? null;
    $p = [];
    if ($lang === 'ar') {
        if ($stale > 0) {
            $pct = $stockNow > 0 ? round($stale / $stockNow * 100) : 0;
            $p[] = "لديك <strong>{$stale} سيارة</strong> (≈{$pct}% من المخزون) مضى عليها أكثر من <strong>١٢٠ يوم</strong> دون بيع. هذه سيارات راكدة تجمّد رأس المال وقد تحتاج إلى خصومات أو إعادة توزيع بين الفروع لتحريكها.";
        }
        if ($aging > 0) {
            $p[] = "كما أن <strong>{$aging} سيارة</strong> في الفئة العمرية ٦٠–١٢٠ يوم — راقبها عن قرب قبل أن تتحول إلى مخزون راكد.";
        }
        if ($oldest) {
            $bn = $lang==='ar' ? ($oldest['name_ar'] ?: $oldest['branch']) : ($oldest['name_en'] ?: $oldest['branch']);
            $p[] = "أقدم سيارة في المخزون هي <strong>{$oldest['brand']} {$oldest['model']}</strong> ({$oldest['age_days']} يوم) في فرع {$bn}. ابدأ بها كأولوية للتصريف.";
        }
        if (empty($p)) $p[] = "مخزونك صحي عمرياً — لا توجد سيارات راكدة لفترات طويلة. استمر على هذا الانضباط.";
    } else {
        if ($stale > 0) {
            $pct = $stockNow > 0 ? round($stale / $stockNow * 100) : 0;
            $p[] = "You have <strong>{$stale} cars</strong> (≈{$pct}% of stock) sitting over <strong>120 days</strong> unsold. This is dead stock freezing capital — it may need discounts or branch redistribution to move.";
        }
        if ($aging > 0) $p[] = "Another <strong>{$aging} cars</strong> are in the 60–120 day range — watch them closely before they turn into dead stock.";
        if ($oldest) {
            $bn = $oldest['name_en'] ?: $oldest['branch'];
            $p[] = "Your oldest unit is a <strong>{$oldest['brand']} {$oldest['model']}</strong> ({$oldest['age_days']} days) at {$bn}. Start with it as your clearance priority.";
        }
        if (empty($p)) $p[] = "Your stock is healthy by age — no long-sitting units. Keep up the discipline.";
    }
    return $p;
}

/* ── 5. Branch narrative ── */
function branchNarrative($lang, $byBranch, $stockByBranch) {
    if (empty($byBranch)) return [];
    $top = $byBranch[0];
    $topName = $lang==='ar' ? ($top['name_ar'] ?: $top['sold_branch']) : ($top['name_en'] ?: $top['sold_branch']);
    $p = [];
    if ($lang === 'ar') {
        $p[] = "الفرع الأعلى مبيعاً هو <strong>{$topName}</strong> بـ {$top['sold']} سيارة خلال الفترة. تأكد أن هذا الفرع يحصل على حصة كافية من الموديلات سريعة الحركة.";
        // Branch with stock but low sales
        foreach ($byBranch as $b) {
            $nm = $b['name_ar'] ?: $b['sold_branch'];
            $st = $stockByBranch[$b['sold_branch']] ?? 0;
            if ($b['sold'] <= 1 && $st >= 5) {
                $p[] = "فرع <strong>{$nm}</strong> لديه {$st} سيارة في المخزون لكن مبيعاته منخفضة ({$b['sold']}). قد يحتاج لمراجعة المخزون المعروض أو دعم بيعي.";
                break;
            }
        }
    } else {
        $p[] = "Top-selling branch is <strong>{$topName}</strong> with {$top['sold']} cars this period. Make sure it gets enough of the fast-moving models.";
        foreach ($byBranch as $b) {
            $nm = $b['name_en'] ?: $b['sold_branch'];
            $st = $stockByBranch[$b['sold_branch']] ?? 0;
            if ($b['sold'] <= 1 && $st >= 5) {
                $p[] = "Branch <strong>{$nm}</strong> holds {$st} cars but sold only {$b['sold']} — it may need a stock review or sales support.";
                break;
            }
        }
    }
    return $p;
}

/* ── 6. Stock vs demand mismatch narrative ── */
function mismatchNarrative($lang, $understocked, $overstocked) {
    $p = [];
    $u = $understocked[0] ?? null;
    $o = $overstocked[0] ?? null;
    if ($lang === 'ar') {
        if ($u) {
            $cov = coverHuman($u['cover'], $lang);
            $p[] = "🔥 <strong>{$u['brand']} {$u['model']}</strong> يُباع بسرعة ولكن مخزونه على وشك النفاد (يكفي {$cov} فقط). هذا أخطر نوع — كل يوم نفاد = بيعة ضائعة. أعطه أولوية قصوى في الطلب.";
        }
        if ($o) {
            $p[] = "🧊 في المقابل، <strong>{$o['brand']} {$o['model']}</strong> متكدّس ({$o['stock']} في المخزون) وحركته بطيئة. هذا رأس مال نائم — أوقف طلبه وفكّر في تحفيز بيعه أو توزيعه على فروع أخرى.";
        }
        if (!$u && !$o) {
            $p[] = "مخزونك متوازن نسبياً مع الطلب — لا توجد اختلالات حادة بين المعروض والمطلوب حالياً. استمر في المتابعة الدورية.";
        }
    } else {
        if ($u) {
            $cov = coverHuman($u['cover'], $lang);
            $p[] = "🔥 <strong>{$u['brand']} {$u['model']}</strong> is selling fast but nearly out of stock (only {$cov} left). This is the most dangerous gap — every day out of stock is a lost sale. Make it your top reorder priority.";
        }
        if ($o) {
            $p[] = "🧊 By contrast, the <strong>{$o['brand']} {$o['model']}</strong> is overstocked ({$o['stock']} units) and moving slowly. That's sleeping capital — stop ordering it and consider an incentive or redistributing it to other branches.";
        }
        if (!$u && !$o) {
            $p[] = "Your stock is reasonably balanced against demand — no sharp supply/demand gaps right now. Keep monitoring periodically.";
        }
    }
    return $p;
}

$marketState   = marketStateNarrative($lang, $soldWindow, $soldPrev, $trendPct, $avgDays, $coverage, $window, $velocity, $reorder, $stockNow);
$colorStory    = colorNarrative($lang, $colorInsight, $totalColorSold);
$agingStory    = agingNarrative($lang, $ageBuckets, $slowMovers, $stockNow);
$branchStory   = branchNarrative($lang, $byBranch, $stockByBranch);
$mismatchStory = mismatchNarrative($lang, $understocked, $overstocked);

$other_lang = $lang === 'ar' ? 'en' : 'ar';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#020617">
<title>First 1 Car — <?= $lang==='ar'?'تحليل المخزون والسوق':'Inventory & Market Insight' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root {
    --bg-deep:#020617; --bg-card:rgba(15,23,42,.94); --border:rgba(255,255,255,.07);
    --green:#22c55e; --green-d:rgba(34,197,94,.12);
    --purple:#9333ea; --purple-d:rgba(147,51,234,.12);
    --blue:#2563eb; --blue-d:rgba(37,99,235,.12);
    --amber:#f59e0b; --amber-d:rgba(245,158,11,.12);
    --red:#ef4444; --red-d:rgba(239,68,68,.12);
    --cyan:#06b6d4;
    --text:#f1f5f9; --muted:#64748b; --muted-l:#94a3b8;
    --r-card:22px; --r-btn:12px; --shadow:0 4px 30px rgba(0,0,0,.45);
}
html[lang="ar"] body { font-family:'Cairo',sans-serif; }
html[lang="en"] body { font-family:'Inter',sans-serif; }
body { background:linear-gradient(160deg,#020617 0%,#0a0f1f 55%,#06111f 100%); min-height:100vh; color:var(--text); padding:20px 16px 90px; line-height:1.6; }
.wrap { max-width:1080px; margin:0 auto; }

.top { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:18px; flex-wrap:wrap; }
.top h1 { font-size:25px; font-weight:900; display:flex; align-items:center; gap:10px; letter-spacing:-.01em; }
.top .sub { font-size:13px; color:var(--muted-l); margin-top:3px; font-weight:600; }
.lang-btn { text-decoration:none; padding:8px 14px; border-radius:var(--r-btn); border:1px solid var(--border); color:var(--muted-l); font-size:13px; font-weight:700; background:var(--bg-card); }

.win-bar { display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap; align-items:center; }
.win-label { font-size:13px; color:var(--muted-l); font-weight:700; margin-inline-end:4px; }
.win-bar a { text-decoration:none; padding:8px 16px; border-radius:var(--r-btn); border:1px solid var(--border); color:var(--muted-l); font-size:13px; font-weight:700; background:var(--bg-card); transition:all .2s; }
.win-bar a.active { background:var(--purple-d); border-color:rgba(147,51,234,.4); color:#c084fc; }

/* Hero — market state */
.hero {
    background:linear-gradient(135deg, rgba(147,51,234,.10), rgba(37,99,235,.06));
    border:1px solid rgba(147,51,234,.22); border-radius:var(--r-card);
    padding:24px 26px; margin-bottom:20px; box-shadow:var(--shadow);
}
.hero-tag { display:inline-flex; align-items:center; gap:7px; font-size:12px; font-weight:800; color:#c084fc; background:rgba(147,51,234,.15); padding:5px 13px; border-radius:50px; margin-bottom:14px; letter-spacing:.04em; }
.hero p { font-size:14.5px; color:#cbd5e1; margin-bottom:12px; }
.hero p:last-child { margin-bottom:0; }
.hero strong { color:#fff; font-weight:800; }

/* KPI strip */
.kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:13px; margin-bottom:20px; }
.kpi { background:var(--bg-card); border:1px solid var(--border); border-radius:18px; padding:16px; box-shadow:var(--shadow); }
.kpi-title { font-size:11.5px; color:var(--muted-l); font-weight:700; margin-bottom:7px; }
.kpi-num { font-size:28px; font-weight:900; line-height:1; }
.kpi-unit { font-size:12px; color:var(--muted); font-weight:600; margin-top:5px; }
.kpi-trend { font-size:12px; font-weight:800; margin-top:5px; }
.k-green{color:var(--green);} .k-blue{color:#60a5fa;} .k-amber{color:var(--amber);} .k-purple{color:#c084fc;} .k-red{color:#f87171;} .k-cyan{color:#22d3ee;}
.t-up{color:var(--green);} .t-down{color:#f87171;} .t-flat{color:var(--muted-l);}

/* Sections */
.section { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-card); padding:22px; margin-bottom:18px; box-shadow:var(--shadow); }
.section-h { margin-bottom:5px; font-size:18px; font-weight:800; display:flex; align-items:center; gap:9px; }
.section-sub { font-size:12.5px; color:var(--muted-l); margin-bottom:16px; }

/* Analysis prose block */
.analysis { background:rgba(255,255,255,.02); border-inline-start:3px solid var(--purple); border-radius:0 12px 12px 0; padding:14px 18px; margin-bottom:18px; }
html[dir="rtl"] .analysis { border-radius:12px 0 0 12px; }
.analysis p { font-size:13.5px; color:#cbd5e1; margin-bottom:10px; }
.analysis p:last-child { margin-bottom:0; }
.analysis strong { color:#fff; font-weight:800; }

/* Tables */
.tbl { width:100%; border-collapse:collapse; font-size:13px; }
.tbl th { text-align:start; padding:10px 8px; color:var(--muted-l); font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:.04em; border-bottom:1px solid var(--border); white-space:nowrap; }
.tbl td { padding:11px 8px; border-bottom:1px solid rgba(255,255,255,.04); }
.tbl tr:last-child td { border-bottom:none; }
.tbl .t-model { font-weight:700; color:var(--text); }
.tbl .t-brand { color:var(--muted-l); font-size:12px; }
.num { font-weight:800; font-variant-numeric:tabular-nums; }
.pill { display:inline-block; padding:3px 9px; border-radius:20px; font-size:11px; font-weight:800; }
.rank { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:7px; background:rgba(147,51,234,.15); color:#c084fc; font-size:11px; font-weight:800; }
.rank.r1{background:rgba(245,158,11,.2);color:#fbbf24;} .rank.r2{background:rgba(148,163,184,.2);color:#cbd5e1;} .rank.r3{background:rgba(180,83,9,.2);color:#fb923c;}
.cover-ok{background:var(--green-d);color:var(--green);} .cover-warn{background:var(--amber-d);color:var(--amber);} .cover-late{background:var(--red-d);color:#f87171;}
.speed-fast{color:var(--green);font-weight:800;} .speed-mid{color:var(--amber);font-weight:800;} .speed-slow{color:#f87171;font-weight:800;}

/* Reorder columns */
.reorder-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:14px; }
.ro-col { border:1px solid var(--border); border-radius:16px; overflow:hidden; display:flex; flex-direction:column; }
.ro-head { padding:13px 15px; font-weight:800; font-size:14px; display:flex; align-items:center; gap:8px; }
.ro-restock .ro-head { background:var(--green-d); color:var(--green); }
.ro-hold .ro-head { background:var(--amber-d); color:var(--amber); }
.ro-dead .ro-head { background:var(--red-d); color:#f87171; }
.ro-watch .ro-head { background:var(--blue-d); color:#60a5fa; }
.ro-count { margin-inline-start:auto; font-size:12px; opacity:.85; }
.ro-list { padding:4px 0; }
.ro-item { padding:12px 15px; border-bottom:1px solid rgba(255,255,255,.04); }
.ro-item:last-child { border-bottom:none; }
.ro-item .ro-name { font-weight:800; font-size:13.5px; }
.ro-item .ro-brand { font-size:11px; color:var(--muted); margin-bottom:5px; }
.ro-item .ro-why { font-size:12px; color:var(--muted-l); line-height:1.5; }
.ro-empty { padding:20px 15px; text-align:center; color:var(--muted); font-size:12px; }

/* Supply vs demand mismatch */
.mismatch-section { border-color:rgba(245,158,11,.3); }
.mismatch-cols { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.mismatch-col { border:1px solid var(--border); border-radius:14px; overflow:hidden; }
.mm-head { padding:11px 14px; font-weight:800; font-size:13px; }
.mm-under { background:var(--red-d); color:#f87171; }
.mm-over { background:var(--blue-d); color:#60a5fa; }
.mm-item { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 14px; border-bottom:1px solid rgba(255,255,255,.04); font-size:13px; }
.mm-item:last-child { border-bottom:none; }
.mm-name { font-weight:700; }
.mm-stat { font-size:11px; color:var(--muted-l); font-weight:700; white-space:nowrap; }
@media (max-width:600px) { .mismatch-cols { grid-template-columns:1fr; } }

/* Color analysis */
.color-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:11px; margin-top:6px; }
.color-card { background:rgba(255,255,255,.02); border:1px solid var(--border); border-radius:14px; padding:13px; text-align:center; }
.color-dot { width:34px; height:34px; border-radius:50%; margin:0 auto 9px; border:2px solid rgba(255,255,255,.15); box-shadow:0 2px 8px rgba(0,0,0,.3); }
.color-name { font-size:12.5px; font-weight:800; margin-bottom:6px; }
.color-sold { font-size:20px; font-weight:900; color:var(--green); line-height:1; }
.color-meta { font-size:10.5px; color:var(--muted); margin-top:5px; }
.color-share-bar { height:5px; background:rgba(255,255,255,.06); border-radius:3px; margin-top:8px; overflow:hidden; }
.color-share-fill { height:100%; background:linear-gradient(90deg,var(--green),#86efac); border-radius:3px; }
.color-zero .color-sold { color:var(--muted); }
.color-zero .color-share-fill { background:var(--muted); }

/* Age buckets */
.age-buckets { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px; }
.age-bk { background:rgba(255,255,255,.02); border:1px solid var(--border); border-radius:14px; padding:14px; text-align:center; }
.age-bk-num { font-size:26px; font-weight:900; line-height:1; }
.age-bk-lbl { font-size:11px; color:var(--muted-l); margin-top:6px; font-weight:600; }
.bk-fresh .age-bk-num{color:var(--green);} .bk-normal .age-bk-num{color:#60a5fa;} .bk-aging .age-bk-num{color:var(--amber);} .bk-stale .age-bk-num{color:#f87171;}

.age { display:inline-block; padding:3px 9px; border-radius:20px; font-size:11px; font-weight:800; font-variant-numeric:tabular-nums; }
.age-ok{background:var(--green-d);color:var(--green);} .age-warn{background:var(--amber-d);color:var(--amber);} .age-late{background:var(--red-d);color:#f87171;}

/* Charts */
.chart-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
.chart-box { position:relative; height:280px; }
.chart-box-full { position:relative; height:300px; }

.empty { text-align:center; color:var(--muted); padding:24px; font-size:13px; }
.disclaimer { font-size:11.5px; color:var(--muted); text-align:center; margin-top:8px; padding:0 10px; line-height:1.6; }

.bottom-nav { position:fixed; bottom:0; inset-inline:0; background:rgba(2,6,23,.96); backdrop-filter:blur(12px); border-top:1px solid var(--border); display:flex; justify-content:center; gap:8px; padding:10px; z-index:50; }
.bottom-nav a { text-decoration:none; color:var(--muted-l); font-size:12px; font-weight:700; padding:8px 16px; border-radius:var(--r-btn); }
.bottom-nav a:hover { background:var(--purple-d); color:#c084fc; }

@media (max-width:760px) {
    .chart-grid { grid-template-columns:1fr; }
    .age-buckets { grid-template-columns:1fr 1fr; }
    .top h1 { font-size:21px; }
}

/* ── Print: clean white report ── */
@media print {
    body { background:#fff !important; color:#111 !important; padding:0 !important; }
    .no-print, .bottom-nav, .win-bar { display:none !important; }
    .wrap { max-width:100% !important; }
    .section, .hero, .kpi { background:#fff !important; border:1px solid #ccc !important; box-shadow:none !important; break-inside:avoid; page-break-inside:avoid; }
    .hero { background:#f8f5ff !important; }
    .section-h, .top h1, .kpi-num { color:#111 !important; }
    .analysis { background:#faf8ff !important; }
    .analysis p, .hero p, .section-sub, .kpi-title { color:#333 !important; }
    .analysis strong, .hero strong { color:#000 !important; }
    .tbl th, .tbl td { color:#222 !important; border-color:#ddd !important; }
    canvas { max-height:260px; }
    .chart-box, .chart-box-full { height:260px !important; }
    .k-green,.k-blue,.k-amber,.k-purple,.k-red,.k-cyan { color:#111 !important; }
    a[href]::after { content:none !important; }
}
</style>
</head>
<body>
<div class="wrap">

    <div class="top">
        <div>
            <h1>🧠 <?= $lang==='ar'?'تحليل المخزون والسوق':'Inventory & Market Insight' ?></h1>
            <div class="sub"><?= $lang==='ar'?'ماذا يُباع، ماذا تطلب، وأي الألوان تتحرك':'What sells, what to order, which colors move' ?></div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <button onclick="window.print()" class="lang-btn no-print" style="cursor:pointer;font-family:inherit;">🖨 <?= $lang==='ar'?'طباعة':'Print' ?></button>
            <a class="lang-btn no-print" href="?lang=<?= $other_lang ?>&win=<?= $window ?>"><?= $lang==='ar'?'EN':'عربي' ?></a>
        </div>
    </div>

    <div class="win-bar">
        <span class="win-label"><?= $lang==='ar'?'الفترة':'Window' ?>:</span>
        <a href="?lang=<?= $lang ?>&win=90"  class="<?= $window===90?'active':'' ?>"><?= $lang==='ar'?'٩٠ يوم':'90 days' ?></a>
        <a href="?lang=<?= $lang ?>&win=180" class="<?= $window===180?'active':'' ?>"><?= $lang==='ar'?'٦ أشهر':'6 months' ?></a>
        <a href="?lang=<?= $lang ?>&win=365" class="<?= $window===365?'active':'' ?>"><?= $lang==='ar'?'سنة':'1 year' ?></a>
    </div>

    <!-- ════ HERO: MARKET STATE ════ -->
    <div class="hero">
        <div class="hero-tag">📡 <?= $lang==='ar'?'حالة السوق':'Market State' ?></div>
        <?php foreach ($marketState as $para): ?>
        <p><?= $para ?></p>
        <?php endforeach; ?>
    </div>

    <!-- ════ KPIs ════ -->
    <div class="kpis">
        <div class="kpi">
            <div class="kpi-title">✅ <?= $lang==='ar'?'المخزون المتاح':'Available Stock' ?></div>
            <div class="kpi-num k-green"><?= $stockNow ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-title">💰 <?= $lang==='ar'?'مبيعات الفترة':'Sold in Window' ?></div>
            <div class="kpi-num k-blue"><?= $soldWindow ?></div>
            <?php if ($soldPrev > 0 || $soldWindow > 0): ?>
            <div class="kpi-trend <?= $trendPct>0?'t-up':($trendPct<0?'t-down':'t-flat') ?>">
                <?= $trendPct>0?'▲':($trendPct<0?'▼':'▬') ?> <?= abs($trendPct) ?>% <?= $lang==='ar'?'عن السابق':'vs prev' ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="kpi">
            <div class="kpi-title">⏱ <?= $lang==='ar'?'متوسط أيام البيع':'Avg Days to Sell' ?></div>
            <div class="kpi-num k-amber"><?= $avgDays !== null ? $avgDays : '—' ?></div>
            <?php if ($avgDays !== null): ?><div class="kpi-unit"><?= $lang==='ar'?'يوم':'days' ?><?php if($minDays!==null): ?> · <?= $minDays ?>–<?= $maxDays ?><?php endif; ?></div><?php endif; ?>
        </div>
        <div class="kpi">
            <div class="kpi-title">📈 <?= $lang==='ar'?'معدل البيع':'Run Rate' ?></div>
            <div class="kpi-num k-purple" style="font-size:22px;line-height:1.3;"><?= htmlspecialchars(paceHuman($runRate, $lang, true)) ?></div>
            <div class="kpi-unit"><?= $lang==='ar'?'بالمعدل الحالي':'at current pace' ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-title">📦 <?= $lang==='ar'?'تغطية المخزون':'Stock lasts' ?></div>
            <div class="kpi-num <?= ($coverage!==null && $coverage<1)?'k-red':'k-cyan' ?>" style="font-size:19px;line-height:1.3;"><?= htmlspecialchars(coverHuman($coverage, $lang)) ?></div>
            <div class="kpi-unit"><?= $lang==='ar'?'بمعدل البيع الحالي':'at current pace' ?></div>
        </div>
    </div>

    <!-- ════ STOCK vs DEMAND MISMATCH (priority callout) ════ -->
    <?php if (!empty($understocked) || !empty($overstocked)): ?>
    <div class="section mismatch-section">
        <div class="section-h">⚖️ <?= $lang==='ar'?'الفجوة بين المعروض والطلب':'Supply vs Demand Gap' ?></div>
        <div class="section-sub"><?= $lang==='ar'?'أهم نقطة للقرار: ماذا ينفد وماذا يتكدّس':'The #1 decision point: what is running out and what is piling up' ?></div>
        <?php if (!empty($mismatchStory)): ?>
        <div class="analysis" style="border-color:var(--amber);">
            <?php foreach ($mismatchStory as $para): ?><p><?= $para ?></p><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="mismatch-cols">
            <div class="mismatch-col">
                <div class="mm-head mm-under">🔥 <?= $lang==='ar'?'ينفد بسرعة (اطلب)':'Running Out (order)' ?></div>
                <?php if (empty($understocked)): ?>
                    <div class="ro-empty">—</div>
                <?php else: foreach (array_slice($understocked, 0, 6) as $v): ?>
                    <div class="mm-item">
                        <span class="mm-name"><?= htmlspecialchars($v['brand']) ?> <?= htmlspecialchars($v['model']) ?></span>
                        <span class="mm-stat"><?= $lang==='ar'?'متاح':'stock' ?> <?= $v['stock'] ?> · <?= htmlspecialchars(paceHuman($v['rate'], $lang, true)) ?></span>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            <div class="mismatch-col">
                <div class="mm-head mm-over">🧊 <?= $lang==='ar'?'متكدّس (أوقف)':'Overstocked (hold)' ?></div>
                <?php if (empty($overstocked)): ?>
                    <div class="ro-empty">—</div>
                <?php else: foreach (array_slice($overstocked, 0, 6) as $v): ?>
                    <div class="mm-item">
                        <span class="mm-name"><?= htmlspecialchars($v['brand']) ?> <?= htmlspecialchars($v['model']) ?></span>
                        <span class="mm-stat"><?= $lang==='ar'?'متاح':'stock' ?> <?= $v['stock'] ?> · <?= htmlspecialchars(coverHuman($v['cover'], $lang, true)) ?></span>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ════ REORDER RECOMMENDATIONS ════ -->
    <div class="section">
        <div class="section-h">🎯 <?= $lang==='ar'?'توصيات الطلب والقرار':'Reorder & Decision Guide' ?></div>
        <div class="section-sub"><?= $lang==='ar'?'لكل موديل: ماذا تفعل ولماذا — بناءً على سرعة البيع مقابل المخزون':'For each model: what to do and why — based on sell-rate vs stock' ?></div>
        <div class="reorder-grid">
            <?php
            $roCols = [
                ['key'=>'restock','cls'=>'ro-restock','label'=>$lang==='ar'?'اطلب الآن':'Restock Now','icon'=>'🔺'],
                ['key'=>'dead',   'cls'=>'ro-dead',   'label'=>$lang==='ar'?'راكد — تحرّك':'Dead Stock','icon'=>'🛑'],
                ['key'=>'hold',   'cls'=>'ro-hold',   'label'=>$lang==='ar'?'لا تطلب':'Hold','icon'=>'✋'],
                ['key'=>'watch',  'cls'=>'ro-watch',  'label'=>$lang==='ar'?'راقب':'Watch','icon'=>'👀'],
            ];
            foreach ($roCols as $col):
                $items = $reorder[$col['key']];
            ?>
            <div class="ro-col <?= $col['cls'] ?>">
                <div class="ro-head">
                    <span><?= $col['icon'] ?> <?= $col['label'] ?></span>
                    <span class="ro-count"><?= count($items) ?></span>
                </div>
                <div class="ro-list">
                    <?php if (empty($items)): ?>
                        <div class="ro-empty">—</div>
                    <?php else: foreach (array_slice($items, 0, 8) as $v): ?>
                        <div class="ro-item">
                            <div class="ro-name"><?= htmlspecialchars($v['model']) ?></div>
                            <div class="ro-brand"><?= htmlspecialchars($v['brand']) ?></div>
                            <div class="ro-why"><?= reorderReason($v, $col['key'], $lang) ?></div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ════ VELOCITY ════ -->
    <div class="section">
        <div class="section-h">⚡ <?= $lang==='ar'?'سرعة البيع حسب الموديل':'Sales Velocity by Model' ?></div>
        <div class="section-sub"><?= $lang==='ar'?'الأسرع مبيعاً أولاً — مع متوسط أيام البيع وكفاية المخزون':'Fastest sellers first — with avg days-to-sell and stock coverage' ?></div>
        <?php if (empty($velocity) || $soldWindow === 0): ?>
            <div class="empty">🚗 <?= $lang==='ar'?'لا توجد بيانات كافية بعد':'Not enough data yet' ?></div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="tbl">
            <thead>
                <tr>
                    <th>#</th>
                    <th><?= $lang==='ar'?'الموديل':'Model' ?></th>
                    <th><?= $lang==='ar'?'مبيعات':'Sold' ?></th>
                    <th><?= $lang==='ar'?'المعدل':'Pace' ?></th>
                    <th><?= $lang==='ar'?'سرعة':'Speed' ?></th>
                    <th><?= $lang==='ar'?'متاح':'Stock' ?></th>
                    <th><?= $lang==='ar'?'تغطية':'Cover' ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($velocity, 0, 20) as $i => $v):
                    $coverCls = 'cover-ok';
                    if ($v['cover'] !== null && $v['cover'] < 1) $coverCls = 'cover-late';
                    elseif ($v['cover'] !== null && $v['cover'] < 2) $coverCls = 'cover-warn';
                    $rankCls = $i===0?'r1':($i===1?'r2':($i===2?'r3':''));
                    // speed label
                    $sp = $v['dts'];
                    if ($sp === null) { $spTxt='—'; $spCls=''; }
                    elseif ($sp <= 30) { $spTxt=($lang==='ar'?'سريع':'Fast'); $spCls='speed-fast'; }
                    elseif ($sp <= 60) { $spTxt=($lang==='ar'?'متوسط':'Mid'); $spCls='speed-mid'; }
                    else { $spTxt=($lang==='ar'?'بطيء':'Slow'); $spCls='speed-slow'; }
                ?>
                <tr>
                    <td><span class="rank <?= $rankCls ?>"><?= $i+1 ?></span></td>
                    <td>
                        <div class="t-model"><?= htmlspecialchars($v['model']) ?></div>
                        <div class="t-brand"><?= htmlspecialchars($v['brand']) ?></div>
                    </td>
                    <td class="num"><?= $v['sold'] ?></td>
                    <td style="font-size:12px;color:var(--muted-l);font-weight:700;"><?= htmlspecialchars(paceHuman($v['rate'], $lang, true)) ?></td>
                    <td class="<?= $spCls ?>"><?= $spTxt ?><?php if($sp!==null): ?> <span style="font-size:10px;color:var(--muted);">(<?= $sp ?>d)</span><?php endif; ?></td>
                    <td class="num"><?= $v['stock'] ?></td>
                    <td><span class="pill <?= $coverCls ?>"><?= htmlspecialchars(coverHuman($v['cover'], $lang, true)) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ════ COLOR INTELLIGENCE ════ -->
    <div class="section">
        <div class="section-h">🎨 <?= $lang==='ar'?'تحليل الألوان':'Color Intelligence' ?></div>
        <div class="section-sub"><?= $lang==='ar'?'أي الألوان تُباع بسرعة وأيها يتكدّس':'Which colors sell fast and which pile up' ?></div>
        <?php if (!empty($colorStory)): ?>
        <div class="analysis">
            <?php foreach ($colorStory as $para): ?><p><?= $para ?></p><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($totalColorSold > 0): ?>
        <div class="color-grid">
            <?php
            $colorHex = [
                'white'=>'#f8fafc','أبيض'=>'#f8fafc','black'=>'#1e293b','أسود'=>'#1e293b',
                'silver'=>'#cbd5e1','فضي'=>'#cbd5e1','gray'=>'#94a3b8','grey'=>'#94a3b8','رمادي'=>'#94a3b8','اسمنتي'=>'#9ca3af','cement'=>'#9ca3af',
                'red'=>'#ef4444','أحمر'=>'#ef4444','blue'=>'#3b82f6','أزرق'=>'#3b82f6','كحلي'=>'#1e3a8a','navy'=>'#1e3a8a',
                'green'=>'#22c55e','أخضر'=>'#22c55e','gold'=>'#d4af37','ذهبي'=>'#d4af37',
                'brown'=>'#92400e','بني'=>'#92400e','beige'=>'#d6c7a1','بيج'=>'#d6c7a1',
                'orange'=>'#f97316','برتقالي'=>'#f97316','yellow'=>'#eab308','أصفر'=>'#eab308',
                'bronze'=>'#a97142','نحاسي'=>'#a97142','purple'=>'#9333ea','بنفسجي'=>'#9333ea',
            ];
            // Smart swatch: exact match, else substring match (handles "Cement Gray", "Pearl White", etc.)
            function pickHex($en, $ar, $map) {
                $en = strtolower(trim($en)); $ar = trim($ar);
                if (isset($map[$en])) return $map[$en];
                if (isset($map[$ar])) return $map[$ar];
                foreach ($map as $kw => $hex) {
                    if ($kw !== '' && (mb_strpos($en, $kw) !== false || mb_strpos($ar, $kw) !== false)) return $hex;
                }
                return '#64748b';
            }
            $maxColorSold = max(array_column($colorInsight,'sold')) ?: 1;
            foreach (array_slice($colorInsight, 0, 10, true) as $raw => $c):
                $hex = pickHex($c['en'], $c['ar'], $colorHex);
                $zero = $c['sold'] === 0 ? 'color-zero' : '';
            ?>
            <div class="color-card <?= $zero ?>">
                <div class="color-dot" style="background:<?= $hex ?>;"></div>
                <div class="color-name"><?= htmlspecialchars($lang==='ar'?$c['ar']:$c['en']) ?></div>
                <div class="color-sold"><?= $c['sold'] ?></div>
                <div class="color-meta"><?= $lang==='ar'?'بيع':'sold' ?> · <?= $lang==='ar'?'متاح':'stock' ?> <?= $c['stock'] ?></div>
                <div class="color-share-bar"><div class="color-share-fill" style="width:<?= round($c['sold']/$maxColorSold*100) ?>%;"></div></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="empty">🎨 <?= $lang==='ar'?'لا توجد بيانات ألوان كافية':'Not enough color data' ?></div>
        <?php endif; ?>
    </div>

    <!-- ════ STOCK AGING ════ -->
    <div class="section">
        <div class="section-h">🐌 <?= $lang==='ar'?'صحة عمر المخزون':'Stock Aging Health' ?></div>
        <div class="section-sub"><?= $lang==='ar'?'منذ متى والسيارات في المخزون — والأبطأ حركة':'How long cars have been in stock — and slowest movers' ?></div>
        <div class="age-buckets">
            <div class="age-bk bk-fresh"><div class="age-bk-num"><?= $ageBuckets['fresh'] ?></div><div class="age-bk-lbl">&lt; 30 <?= $lang==='ar'?'يوم':'days' ?></div></div>
            <div class="age-bk bk-normal"><div class="age-bk-num"><?= $ageBuckets['normal'] ?></div><div class="age-bk-lbl">30–60 <?= $lang==='ar'?'يوم':'days' ?></div></div>
            <div class="age-bk bk-aging"><div class="age-bk-num"><?= $ageBuckets['aging'] ?></div><div class="age-bk-lbl">60–120 <?= $lang==='ar'?'يوم':'days' ?></div></div>
            <div class="age-bk bk-stale"><div class="age-bk-num"><?= $ageBuckets['stale'] ?></div><div class="age-bk-lbl">&gt; 120 <?= $lang==='ar'?'يوم':'days' ?></div></div>
        </div>
        <?php if (!empty($agingStory)): ?>
        <div class="analysis">
            <?php foreach ($agingStory as $para): ?><p><?= $para ?></p><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($slowMovers)): ?>
        <div style="overflow-x:auto;">
        <table class="tbl">
            <thead>
                <tr>
                    <th><?= $lang==='ar'?'الماركة':'Brand' ?></th>
                    <th><?= $lang==='ar'?'الموديل':'Model' ?></th>
                    <th><?= $lang==='ar'?'اللون':'Color' ?></th>
                    <th><?= $lang==='ar'?'الفرع':'Branch' ?></th>
                    <th><?= $lang==='ar'?'بالمخزون':'In stock' ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($slowMovers as $s):
                    $bn = $lang==='ar' ? ($s['name_ar'] ?: $s['branch']) : ($s['name_en'] ?: $s['branch']);
                    $cn = $lang==='ar' ? ($s['color_ar'] ?: $s['color']) : ($s['color_en'] ?: $s['color']);
                    $age = (int)$s['age_days'];
                ?>
                <tr>
                    <td class="t-brand"><?= htmlspecialchars($s['brand']) ?></td>
                    <td class="t-model"><?= htmlspecialchars($s['model']) ?> <span class="t-brand"><?= htmlspecialchars($s['trim_name'] ?? '') ?></span></td>
                    <td class="t-brand"><?= htmlspecialchars($cn) ?></td>
                    <td class="t-brand"><?= htmlspecialchars($bn) ?></td>
                    <td><span class="age <?= ageClass($age) ?>"><?= $age ?> <?= $lang==='ar'?'يوم':'d' ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ════ BRANCH PERFORMANCE ════ -->
    <?php if (!empty($byBranch)): ?>
    <div class="section">
        <div class="section-h">📍 <?= $lang==='ar'?'أداء الفروع':'Branch Performance' ?></div>
        <div class="section-sub"><?= $lang==='ar'?'أي فرع يبيع أكثر وأين المخزون عالق':'Which branch sells most and where stock is stuck' ?></div>
        <?php if (!empty($branchStory)): ?>
        <div class="analysis">
            <?php foreach ($branchStory as $para): ?><p><?= $para ?></p><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="chart-box"><canvas id="branchChart"></canvas></div>
    </div>
    <?php endif; ?>

    <!-- ════ TREND + BRAND CHARTS ════ -->
    <div class="section">
        <div class="section-h">📅 <?= $lang==='ar'?'اتجاه المبيعات (١٢ شهر)':'Sales Trend (12 months)' ?></div>
        <div class="section-sub">&nbsp;</div>
        <div class="chart-box-full"><canvas id="trendChart"></canvas></div>
    </div>

    <?php if (!empty($byBrand)): ?>
    <div class="section">
        <div class="section-h">🏷️ <?= $lang==='ar'?'المبيعات حسب الماركة':'Sales by Brand' ?></div>
        <div class="section-sub">&nbsp;</div>
        <div class="chart-box-full"><canvas id="brandChart"></canvas></div>
    </div>
    <?php endif; ?>

    <div class="disclaimer">
        🧠 <?= $lang==='ar'
            ? 'هذا التحليل مبني على بيانات المبيعات والمخزون الفعلية لآخر '.($window===90?'٩٠ يوم':($window===180?'٦ أشهر':'سنة')).'. التوصيات إرشادية لمساعدة القرار وليست بديلاً عن خبرة الإدارة.'
            : 'This analysis is built from your real sales and stock data for the last '.($window===90?'90 days':($window===180?'6 months':'year')).'. Recommendations are advisory to support decisions, not a replacement for management judgment.' ?>
        <br><?= $lang==='ar'?'آخر تحديث':'Updated' ?>: <?= date('d M Y · h:i A') ?>
    </div>

</div>

<nav class="bottom-nav">
    <a href="dashboard.php?lang=<?= $lang ?>">🏠 <?= $lang==='ar'?'الرئيسية':'Dashboard' ?></a>
    <a href="sold_inventory.php?lang=<?= $lang ?>">📈 <?= $lang==='ar'?'تحليلات المبيعات':'Sales Analytics' ?></a>
    <a href="stock_report.php?lang=<?= $lang ?>">📄 <?= $lang==='ar'?'تقرير المخزون':'Stock Report' ?></a>
</nav>

<script>
Chart.defaults.color = '#94a3b8';
Chart.defaults.font.family = "<?= $lang==='ar'?'Cairo':'Inter' ?>, sans-serif";
const gridColor = 'rgba(255,255,255,.05)';
const SALES_LABEL = '<?= $lang==='ar'?'المبيعات':'Sales' ?>';

new Chart(document.getElementById('trendChart'), {
    type:'line',
    data:{ labels:<?= json_encode($trendLabels) ?>, datasets:[{
        label:SALES_LABEL, data:<?= json_encode($trendData) ?>,
        borderColor:'#9333ea', backgroundColor:'rgba(147,51,234,.12)',
        fill:true, tension:.35, borderWidth:2, pointBackgroundColor:'#c084fc', pointRadius:3
    }]},
    options:{ responsive:true, maintainAspectRatio:false,
        plugins:{legend:{display:false}},
        scales:{ x:{grid:{color:gridColor}}, y:{grid:{color:gridColor}, beginAtZero:true, ticks:{precision:0}} } }
});

<?php if (!empty($byBrand)): ?>
new Chart(document.getElementById('brandChart'), {
    type:'bar',
    data:{ labels:<?= json_encode(array_column($byBrand,'brand')) ?>, datasets:[{
        label:SALES_LABEL, data:<?= json_encode(array_map('intval', array_column($byBrand,'sold'))) ?>,
        backgroundColor:'rgba(34,197,94,.6)', borderColor:'#22c55e', borderWidth:1, borderRadius:6
    }]},
    options:{ responsive:true, maintainAspectRatio:false, indexAxis:'y',
        plugins:{legend:{display:false}},
        scales:{ x:{grid:{color:gridColor}, beginAtZero:true, ticks:{precision:0}}, y:{grid:{display:false}} } }
});
<?php endif; ?>

<?php if (!empty($byBranch)):
    $branchLabels = array_map(fn($r) => $lang==='ar' ? ($r['name_ar'] ?: $r['sold_branch']) : ($r['name_en'] ?: $r['sold_branch']), $byBranch);
?>
new Chart(document.getElementById('branchChart'), {
    type:'doughnut',
    data:{ labels:<?= json_encode(array_values($branchLabels)) ?>, datasets:[{
        data:<?= json_encode(array_map('intval', array_column($byBranch,'sold'))) ?>,
        backgroundColor:['#9333ea','#22c55e','#2563eb','#f59e0b','#ef4444','#06b6d4','#ec4899','#84cc16'],
        borderColor:'#020617', borderWidth:2
    }]},
    options:{ responsive:true, maintainAspectRatio:false,
        plugins:{legend:{position:'bottom', labels:{padding:12, font:{size:11}}}} }
});
<?php endif; ?>
</script>
</body>
</html>
