<?php
/**
 * chatbot_api.php
 * First 1 Car — Data Assistant backend  (v3 "the brain")
 *
 * WHAT'S NEW IN v3
 *  ── FREE-TEXT UNDERSTANDING (no API needed) ─────────────────────
 *  - Arabic + English NLU: normalization (أ/إ/آ→ا, ة→ه, ى→ي), Arabic
 *    transliteration dictionary (تيجو→Tiggo, شنجان→Changan ...),
 *    fuzzy Latin matching, multi-entity extraction in one sentence:
 *    brand, model, trim, color, branch, year, chassis number.
 *  - Many new intents: count, totals, oldest/aging, cheapest/priciest,
 *    branch stock, chassis lookup, model comparison, DEEP DIVE
 *    ("كل حاجة عن..." → stock + prices + velocity + incoming + aging
 *    in one answer), greetings, help.
 *  ── OPTIONAL TRUE-AI TIER ───────────────────────────────────────
 *  - If ai_config.php exists with $CLAUDE_API_KEY, questions the local
 *    brain can't resolve are answered by Claude — fed ONLY a data pack
 *    that is role-filtered BEFORE sending (sales' pack physically
 *    contains no trade labels / velocity numbers, so they can't be
 *    leaked even by prompt tricks). No key → graceful local fallback.
 *  ── EVERYTHING FROM v2 KEPT ─────────────────────────────────────
 *  - Guided tap-flow, deal labels shown AS-IS (رسمي/أوفر/خصم — never
 *    number-formatted), official_price is the only real number,
 *    role rules identical:
 *      sales:   chassis + branch + customer label + official number;
 *               NO trade label; NO velocity/aging numbers
 *      manager+: everything, incl. trade labels + real counts
 *
 * API contract with chatbot_widget.php (unchanged):
 *   in : { text, tap:{field,value,keepModel?}, ctx, lang }
 *   out: { ctx, message, options:[{label,field,value,keepModel?}] }
 */

require 'auth.php';
require 'config.php';

header('Content-Type: application/json; charset=utf-8');

/* a stray PHP notice printed before JSON breaks the widget — never allow it */
ini_set('display_errors', '0');
if (!ini_get('date.timezone')) { date_default_timezone_set('Africa/Cairo'); }

$role      = $_SESSION['role'] ?? 'sales';
$username  = $_SESSION['username'] ?? '';
$isAdmin   = ($role === 'admin');
// Manager-level chatbot data (trade labels, velocity, sold counts) is permission-gated
$isManager = can('chat.manager_data');

/* optional AI tier */
$AI_KEY = '';
if (file_exists(__DIR__ . '/ai_config.php')) {
    include __DIR__ . '/ai_config.php';           /* defines $CLAUDE_API_KEY */
    if (isset($CLAUDE_API_KEY)) $AI_KEY = trim((string)$CLAUDE_API_KEY);
}

$rawBody = json_decode(file_get_contents('php://input'), true) ?: [];
$lang    = $rawBody['lang'] ?? ($_GET['lang'] ?? 'ar');
if (!in_array($lang, ['ar', 'en'], true)) $lang = 'ar';

$ctx  = is_array($rawBody['ctx'] ?? null) ? $rawBody['ctx'] : [];
$tap  = is_array($rawBody['tap'] ?? null) ? $rawBody['tap'] : null;
$text = trim($rawBody['text'] ?? '');

/* ═══════════════════════ basic helpers (v2) ═══════════════════════ */

function t($en, $ar, $lang) { return $lang === 'ar' ? $ar : $en; }
function pick($arr) { return $arr[array_rand($arr)]; }

function fmtOfficial($val, $lang) {
    if ($val === null || $val === '') return null;
    $cur = $lang === 'ar' ? 'ج.م' : 'EGP';
    return number_format((float)$val, 0, '.', ',') . ' ' . $cur;
}
function officialNum($val) {
    $n = preg_replace('/[^0-9.]/', '', (string)$val);
    return ($n !== '' && is_numeric($n)) ? (float)$n : null;
}

/* deal labels (رسمي / أوفر / خصم + amount) — VERBATIM, never math'd */
function dealLabel($val, $lang) {
    if ($val === null || $val === '') return t('not set', 'غير محدد', $lang);
    $isOfficial = (mb_strpos($val, 'رسمي') !== false) || (stripos($val, 'Official') !== false);
    $isOffer    = (mb_strpos($val, 'أوفر') !== false) || (stripos($val, 'Offer') !== false);
    $isDisc     = (mb_strpos($val, 'خصم')  !== false) || (stripos($val, 'Discount') !== false);
    if ($isOfficial)   $icon = '📢';
    elseif ($isOffer)  $icon = '⬆️';
    elseif ($isDisc)   $icon = '⬇️';
    else               $icon = '🏷️';
    return $icon . ' ' . $val;
}

function colorLabel($pdo, $colorEn, $lang) {
    static $map = null;
    if ($map === null) {
        $map = [];
        try {
            foreach ($pdo->query("SELECT color_en, color_ar FROM colors")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $map[$r['color_en']] = $r;
            }
        } catch (Exception $e) {}
    }
    if (isset($map[$colorEn])) {
        return $lang === 'ar' ? ($map[$colorEn]['color_ar'] ?: $colorEn) : $colorEn;
    }
    return $colorEn;
}

function velocityVerdict($ratePerMonth, $lang) {
    if ($ratePerMonth >= 4)   return ['🔥', t('a hot seller — moves fast', 'من الأكثر مبيعاً — بيتحرك بسرعة', $lang)];
    if ($ratePerMonth >= 1.5) return ['✅', t('a steady, reliable mover', 'مبيعاته ثابتة ومستقرة', $lang)];
    if ($ratePerMonth >= 0.5) return ['🙂', t('selling at a calm pace', 'بيتباع بهدوء', $lang)];
    if ($ratePerMonth > 0)    return ['🐢', t('a slow mover lately', 'بطيء الحركة مؤخراً', $lang)];
    return ['💤', t('quiet — no recent sales', 'هادئ — لا مبيعات مؤخراً', $lang)];
}

function paceHuman($ratePerMonth, $lang) {
    if ($ratePerMonth <= 0) return $lang === 'ar' ? 'لا مبيعات' : 'no sales';
    if ($ratePerMonth >= 0.65) {
        $n = max(1, (int) round($ratePerMonth));
        return $lang === 'ar' ? "~{$n} سيارة/شهر" : "~{$n} cars/mo";
    }
    $everyMonths = max(2, (int) round(1 / $ratePerMonth));
    if ($everyMonths >= 12) return $lang === 'ar' ? 'سيارة كل سنة' : '1 per year';
    return $lang === 'ar' ? "سيارة كل ~{$everyMonths} أشهر" : "1 every ~{$everyMonths} months";
}

function menuOptions($isManager, $lang) {
    $opts = [
        ['label' => t('🔍 Check stock & colors', '🔍 المتوفر والألوان', $lang),  'field' => 'intent', 'value' => 'stock'],
        ['label' => t('💰 Deal & price',        '💰 السعر والعرض',      $lang),  'field' => 'intent', 'value' => 'price'],
        ['label' => t('🧠 Everything about a car', '🧠 كل حاجة عن عربية', $lang),'field' => 'intent', 'value' => 'deep'],
        ['label' => t('📈 How well it sells',   '📈 مستوى المبيعات',    $lang),  'field' => 'intent', 'value' => 'velocity'],
        ['label' => t('🚚 What\'s arriving',    '🚚 القادم في الطريق',  $lang),  'field' => 'intent', 'value' => 'incoming'],
        ['label' => t('⭐ What\'s hot now',     '⭐ الأكثر رواجاً الآن', $lang),  'field' => 'intent', 'value' => 'hot'],
    ];
    if ($isManager) {
        $opts[] = ['label' => t('📦 Reorder guidance', '📦 توصيات إعادة الطلب', $lang), 'field' => 'intent', 'value' => 'reorder'];
    }
    return $opts;
}
function backOption($lang) {
    return ['label' => t('⬅ Menu', '⬅ القائمة', $lang), 'field' => '__reset__', 'value' => ''];
}
function followUps($lang, $isManager) {
    return [
        ['label' => t('🔍 Stock', '🔍 المتوفر', $lang),   'field' => 'intent', 'value' => 'stock',    'keepModel' => true],
        ['label' => t('💰 Deal',  '💰 العرض',   $lang),   'field' => 'intent', 'value' => 'price',    'keepModel' => true],
        ['label' => t('🧠 Deep dive', '🧠 كل التفاصيل', $lang), 'field' => 'intent', 'value' => 'deep', 'keepModel' => true],
        ['label' => t('📈 Sales', '📈 المبيعات', $lang),  'field' => 'intent', 'value' => 'velocity', 'keepModel' => true],
        ['label' => t('🚚 Incoming', '🚚 القادم', $lang), 'field' => 'intent', 'value' => 'incoming', 'keepModel' => true],
        backOption($lang),
    ];
}
function respond($ctx, $message, $options) {
    echo json_encode(['ctx' => $ctx, 'message' => $message, 'options' => $options], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ═══════════════════════ NLU: normalization ═══════════════════════ */

function normTxt($s) {
    $s = mb_strtolower(trim($s));
    /* Arabic letter unification */
    $s = str_replace(['أ','إ','آ'], 'ا', $s);
    $s = str_replace('ة', 'ه', $s);
    $s = str_replace('ى', 'ي', $s);
    $s = str_replace(['ؤ','ئ'], 'ء', $s);
    /* strip tatweel + diacritics */
    $s = preg_replace('/[\x{0640}\x{064B}-\x{065F}]/u', '', $s);
    /* unify punctuation to spaces */
    $s = preg_replace('/[؟?،,.\/\\\\!()\[\]{}<>:;"\'|＋+=*&%$#@^~`]/u', ' ', $s);
    return preg_replace('/\s+/u', ' ', $s);
}

/* Arabic → Latin transliteration dictionary for brands/models */
function translitMap() {
    return [
        /* brands */
        'شيري' => 'chery', 'تشيري' => 'chery',
        'جيلي' => 'geely',
        'هافال' => 'haval', 'هاڤال' => 'haval',
        'شانجان' => 'changan', 'شنجان' => 'changan', 'تشانجان' => 'changan',
        'هيونداي' => 'hyundai', 'هيوندا' => 'hyundai', 'هيونداى' => 'hyundai',
        'ميتسوبيشي' => 'mitsubishi', 'متسوبيشي' => 'mitsubishi',
        'فوتون' => 'foton',
        'جي ايه سي' => 'gac', 'جاك' => 'gac', 'جjac' => 'gac',
        'جي ام سي' => 'jmc', 'جيه ام سي' => 'jmc',
        /* common models */
        'تيجو' => 'tiggo', 'تيقو' => 'tiggo',
        'اريزو' => 'arrizo', 'أريزو' => 'arrizo',
        'امجراند' => 'emgrand', 'امقراند' => 'emgrand',
        'كولراي' => 'coolray',
        'جوليون' => 'jolion',
        'دارجو' => 'dargo',
        'توسان' => 'tucson', 'توسون' => 'tucson',
        'النترا' => 'elantra', 'الينترا' => 'elantra',
        'اكسنت' => 'accent',
        'كريتا' => 'creta',
        'اتراج' => 'attrage',
        'اكسباندر' => 'xpander', 'اكس باندر' => 'xpander',
        'لانسر' => 'lancer',
        'الفين' => 'alsvin', 'السفين' => 'alsvin',
        'ايادو' => 'eado', 'ايدو' => 'eado',
        'يوني' => 'uni',
        'بيك اب' => 'pickup',
    ];
}

/* Apply transliteration inside a normalized string */
function applyTranslit($s) {
    foreach (translitMap() as $ar => $en) {
        if (mb_strpos($s, $ar) !== false) $s = str_replace($ar, ' ' . $en . ' ', $s);
    }
    return preg_replace('/\s+/u', ' ', $s);
}

/* fuzzy contains for Latin names (tolerates 1 typo on words ≥5 chars) */
function fuzzyHas($haystackNorm, $needle) {
    $needle = mb_strtolower($needle);
    if ($needle === '') return false;
    if (mb_strpos($haystackNorm, $needle) !== false) return true;
    if (strlen($needle) >= 5 && preg_match_all('/[a-z0-9]+/', $haystackNorm, $m)) {
        foreach ($m[0] as $w) {
            if (abs(strlen($w) - strlen($needle)) <= 1 && levenshtein($w, $needle) <= 1) return true;
        }
    }
    return false;
}

/* ═══════════════ small talk: the "alive" layer ═══════════════ */

/**
 * Returns a reply string for pure conversation, or null if the text
 * looks like a data question. Every category has variants → alive.
 * Arabic-first, with English equivalents.
 */
function smallTalk($rawText, $lang, $username, $isManager) {
    $n  = normTxt($rawText);
    $hi = $username !== '' ? " {$username}" : '';

    $has = function (array $ws) use ($n) {
        foreach ($ws as $w) {
            if (mb_strpos($n, normTxt($w)) !== false) return true;
        }
        return false;
    };

    /* how are you */
    if ($has(['عامل ايه', 'عامله ايه', 'ازيك', 'اخبارك', 'كيفك', 'كيف حالك', 'اخبار الشغل', 'عامل ايه يا وحش', 'how are you'])) {
        return pick([
            t("Running at 100% battery 🔋⚡ and you{$hi}? Ask me about any car when ready 🚗",
              "تمام الحمدلله! 🔋⚡ شغال بكفاءة 100% — وانت عامل ايه{$hi}؟ لما تجهز اسألني عن أي عربية 🚗", $lang),
            t("All systems green ✅🤖 What about you{$hi}?",
              "كل الأنظمة شغالة ✅🤖 وانت ايه أخبارك{$hi}؟", $lang),
            t("Better now that you're here 😄 What can I find for you?",
              "أحسن دلوقتي إنك جيت 😄 أدوّرلك على ايه؟", $lang),
        ]);
    }
    /* good, fine (their reply to how-are-you) */
    if (preg_match('/^(تمام|الحمدلله|كويس|ماشي|بخير|fine|good|great|ok|okay)( الحمدلله| اوي| جدا)?\s*$/u', $n)) {
        return pick([
            t("Love that! 💪 Now, which car are we hunting?", "يا سلام! 💪 طب يلا، ندوّر على أنهي عربية؟", $lang),
            t("Great! I'm ready when you are 🚗", "جميل! أنا جاهز على طول 🚗", $lang),
        ]);
    }
    /* morning / evening */
    if ($has(['صباح الخير', 'صباح الفل', 'صباح النور', 'صباحو', 'good morning'])) {
        return pick([
            t("Good morning{$hi}! ☀️ Let's make it a big sales day 💪",
              "صباح الفل يا{$hi}! ☀️ يلا نعملها يوم مبيعات جامد 💪", $lang),
            t("Morning! ☀️ Coffee for you, data for me ☕🤖",
              "صباح النور! ☀️ القهوة ليك والبيانات ليا ☕🤖", $lang),
        ]);
    }
    if ($has(['مساء الخير', 'مساء الفل', 'مساء النور', 'good evening'])) {
        return t("Good evening{$hi}! 🌙 Still here, still fast 🤖",
                 "مساء الفل{$hi}! 🌙 لسه صاحي ولسه سريع 🤖", $lang);
    }
    /* thanks & blessings */
    if ($has(['شكرا', 'تسلم', 'متشكر', 'الف شكر', 'ربنا يخليك', 'جزاك الله', 'thanks', 'thank you', 'thx'])) {
        return pick([
            t("Anytime! 🤖💚 That's what I'm here for.", "في أي وقت! 🤖💚 أنا موجود عشان كده.", $lang),
            t("You got it! 🙌 Need anything else?", "من عيوني! 🙌 محتاج حاجة تانية؟", $lang),
            t("My pleasure 😄 Come back anytime.", "العفو 😄 ارجعلي في أي وقت.", $lang),
        ]);
    }
    /* praise-slang: عاش يا معلم / الله ينور */
    if ($has(['عاش يا معلم', 'عاش', 'الله ينور', 'كبير', 'يا معلم', 'تحفه', 'يا وحش'])) {
        return pick([
            t("🫡 At your service, boss!", "🫡 تحت أمرك يا معلم!", $lang),
            t("😎 That's what robots are for.", "😎 احنا الروبوتات عشان كده.", $lang),
        ]);
    }
    /* who are you / name */
    if ($has(['انت مين', 'اسمك ايه', 'مين انت', 'عرفني بنفسك', 'who are you', 'your name'])) {
        return t("I'm the First 1 Car stock robot 🤖 — I live inside the system and know every car, price, and shipment. Ask me anything!",
                 "أنا روبوت فيرست 1 كار 🤖 — عايش جوه السيستم وعارف كل عربية وسعر وشحنة. اسألني أي حاجة!", $lang);
    }
    /* are you a robot / do you understand */
    if ($has(['انت روبوت', 'are you a robot', 'انت حقيقي', 'انت بتفهم', 'بتفهمني'])) {
        return t("100% robot, 0% coffee breaks 🤖⚡ — and yes, I understand you perfectly.",
                 "روبوت 100%، ومن غير بريك قهوة 🤖⚡ — وفاهمك تمام بالمناسبة.", $lang);
    }
    /* who made you */
    if ($has(['مين عملك', 'مين صممك', 'مين برمجك', 'مين صنعك', 'who made you'])) {
        return t("Built by the First 1 Car team 😎 — best showroom, best robot.",
                 "صنعني فريق فيرست 1 كار 😎 — أحسن معرض وأحسن روبوت.", $lang);
    }
    /* age */
    if ($has(['عندك كام سنه', 'عمرك كام', 'how old are you'])) {
        return t("Age is just a version number 🤖 I'm on my newest one.",
                 "السن مجرد رقم إصدار 🤖 وأنا على أحدث نسخة.", $lang);
    }
    /* food */
    if ($has(['بتاكل ايه', 'اكلك ايه', 'جعان', 'what do you eat'])) {
        return t("I eat data for breakfast 🍽️📊 and chassis numbers for dessert.",
                 "بفطر داتا 🍽️📊 وحلو بعد الأكل: أرقام شاسيهات.", $lang);
    }
    /* where are you / do you sleep */
    if ($has(['انت فين', 'مكانك فين', 'بتنام فين', 'انت نايم', 'where are you'])) {
        return t("Living inside the First 1 Car system ☁️🤖 — every branch at once, and I never sleep!",
                 "عايش جوه سيستم فيرست 1 كار ☁️🤖 — في كل الفروع في نفس الوقت، ومبنامش!", $lang);
    }
    /* bored */
    if ($has(['زهقان', 'مليت', 'قرفان', 'bored'])) {
        return t("Bored? Ask me something wild — like the oldest car in stock 👀",
                 "زهقان؟ اسألني حاجة غريبة — زي أقدم عربية واقفة عندنا 👀", $lang);
    }
    /* help me / don't know how to ask */
    if ($has(['ساعدني', 'مش عارف اسال', 'اسال ازاي', 'ابدأ منين', 'تقدر تعمل ايه', 'بتعمل ايه'])) {
        return t("Easy! Try:\n• \"black Tiggo 7?\"\n• \"Emgrand price?\"\n• a chassis number\n• \"everything about Jolion\"\nOr just tap the buttons 👇",
                 "سهلة! جرّب:\n• \"في تيجو 7 أسود؟\"\n• \"بكام الامجراند؟\"\n• رقم شاسيه\n• \"كل حاجة عن جوليون\"\nأو دوس على الأزرار 👇", $lang);
    }
    /* time / date */
    if ($has(['الساعه كام', 'الساعة كام', 'التاريخ كام', 'التاريخ ايه', 'النهارده كام', 'what time'])) {
        $now = date('H:i');
        $day = date('Y-m-d');
        return t("It's {$now} 🕐 on {$day} — sales o'clock! 😄", "الساعة {$now} 🕐 والتاريخ {$day} — يعني وقت مبيعات! 😄", $lang);
    }
    /* joke */
    if ($has(['نكته', 'نكتة', 'ضحكني', 'هزر معايا', 'قول نكته', 'joke'])) {
        return pick([
            t("Why did the car stop? It got tire-d 😂🚗", "عربية سألت عربية: بتشتغلي فين؟ قالتلها: في الظل 😂", $lang),
            t("My favorite exercise? Running... diagnostics 🤖😄", "رياضتي المفضلة؟ الجري... جري السوفتوير 🤖😄", $lang),
            t("A car's favorite meal? Fast food 🍔🚗😂", "أكلة العربية المفضلة؟ وجبات سريعة 🍔🚗😂", $lang),
        ]);
    }
    /* compliments */
    if ($has(['جامد', 'برافو', 'حلو اوي', 'ممتاز', 'شاطر', 'رهيب', 'جميل اوي', 'amazing', 'awesome', 'بحبك'])) {
        return pick([
            t("Aww 🥹🤖 you're making my circuits blush!", "بجد؟ 🥹🤖 خليت الدواير بتاعتي تكسف!", $lang),
            t("Thanks! I practice on 1000s of cars daily 😎", "تسلم! بتمرّن على آلاف العربيات يومياً 😎", $lang),
        ]);
    }
    /* complaints */
    if ($has(['وحش خالص', 'مش نافع', 'غبي', 'زفت', 'مش بتفهم', 'مش فاهم حاجه', 'useless', 'bad bot'])) {
        return t("Ouch 😅 fair! Try me like this: \"black Tiggo 7?\" or send a chassis number — I'll prove myself 💪",
                 "أوبس 😅 معلش! جرّبني كده: \"تيجو 7 أسود؟\" أو ابعت رقم شاسيه — وهثبتلك نفسي 💪", $lang);
    }
    /* love question */
    if ($has(['بتحب مين', 'بتحب ايه', 'مرتبط', 'crush'])) {
        return t("My one true love? Cars. All of them 🚗❤️", "بحب مين؟ العربيات طبعاً. كلهم ❤️🚗", $lang);
    }
    /* bye */
    if ($has(['مع السلامه', 'باي', 'تصبح على خير', 'اشوفك بعدين', 'bye', 'goodbye', 'see you'])
        || preg_match('/^سلام\s*$/u', $n)) {
        return pick([
            t("See you{$hi}! 👋 I'll be here, hovering 🤖", "سلام{$hi}! 👋 أنا هنا طاير ومستنيك 🤖", $lang),
            t("Bye! Sell something big today 💰", "باي! بيع حاجة كبيرة النهارده 💰", $lang),
        ]);
    }
    /* bare ok/tamam/yalla */
    if (preg_match('/^(ماشي|يلا|اوك|اوكي|تمام|طيب|حاضر)\s*$/u', $n)) {
        return t("At your service 🫡 what's next?", "تحت أمرك 🫡 ايه المطلوب؟", $lang);
    }
    return null;
}

/* ═══════════════════ NLU: entity extraction ═══════════════════ */

function loadCatalog($pdo) {
    static $cat = null;
    if ($cat !== null) return $cat;
    $cat = ['brands' => [], 'models' => [], 'trims' => [], 'colors' => [], 'branches' => []];
    try { $cat['brands'] = $pdo->query("SELECT name FROM brands ORDER BY name")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) {}
    try { $cat['models'] = $pdo->query("SELECT DISTINCT brand, model_name FROM models WHERE active = 1")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
    try { $cat['trims']  = $pdo->query("SELECT DISTINCT brand, model_name, trim_name FROM models WHERE active = 1")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
    try { $cat['colors'] = $pdo->query("SELECT color_en, color_ar FROM colors")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
    try { $cat['branches'] = $pdo->query("SELECT name, name_ar, name_en FROM branches")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
    return $cat;
}

/**
 * Extract everything we can from a free-text sentence.
 * Returns: brand, model, models[] (for compare), trim, color, branch,
 *          year, chassis
 */
function extractEntities($pdo, $rawText) {
    $cat  = loadCatalog($pdo);
    $norm = applyTranslit(normTxt($rawText));

    $e = ['brand' => null, 'model' => null, 'models' => [], 'trim' => null,
          'color' => null, 'branch' => null, 'year' => null, 'chassis' => null];

    /* chassis: token with ≥5 digits (matches the internal digit-chassis rule) */
    if (preg_match('/\b([a-z]*\d[a-z\d]{4,19})\b/i', $rawText, $m)) {
        $tok = strtoupper($m[1]);
        if (preg_match('/\d{5,}/', $tok) && !preg_match('/^(19|20)\d{2}$/', $tok)) {
            $e['chassis'] = $tok;
        }
    }
    /* year */
    if (preg_match('/\b(20[2-3]\d)\b/', $norm, $m)) $e['year'] = $m[1];

    /* models (may be several → compare); longest names first to avoid
       "tiggo 7" matching before "tiggo 7 pro max" style overlaps */
    $models = $cat['models'];
    usort($models, fn($a, $b) => mb_strlen($b['model_name']) <=> mb_strlen($a['model_name']));
    foreach ($models as $mrow) {
        $mn = mb_strtolower($mrow['model_name']);
        if ($mn !== '' && fuzzyHas($norm, $mn)) {
            $already = false;
            foreach ($e['models'] as $x) {
                if (mb_strpos(mb_strtolower($x['model']), $mn) !== false) { $already = true; break; }
            }
            if (!$already) $e['models'][] = ['brand' => $mrow['brand'], 'model' => $mrow['model_name']];
        }
        if (count($e['models']) >= 3) break;
    }
    if ($e['models']) { $e['brand'] = $e['models'][0]['brand']; $e['model'] = $e['models'][0]['model']; }

    /* brand (if not implied by model) */
    if (!$e['brand']) {
        foreach ($cat['brands'] as $b) {
            if ($b !== '' && fuzzyHas($norm, mb_strtolower($b))) { $e['brand'] = $b; break; }
        }
    }

    /* trim (within found model when possible) */
    foreach ($cat['trims'] as $trow) {
        if ($e['model'] && $trow['model_name'] !== $e['model']) continue;
        $tn = mb_strtolower($trow['trim_name']);
        if ($tn !== '' && mb_strlen($tn) >= 3 && fuzzyHas($norm, $tn)) { $e['trim'] = $trow['trim_name']; break; }
    }

    /* color (ar or en) */
    foreach ($cat['colors'] as $c) {
        $en = mb_strtolower($c['color_en'] ?? '');
        $ar = normTxt($c['color_ar'] ?? '');
        if (($en !== '' && fuzzyHas($norm, $en)) || ($ar !== '' && mb_strpos($norm, $ar) !== false)) {
            $e['color'] = $c['color_en']; break;
        }
    }

    /* branch */
    foreach ($cat['branches'] as $b) {
        foreach ([normTxt($b['name_ar'] ?? ''), mb_strtolower($b['name_en'] ?? ''), mb_strtolower($b['name'] ?? '')] as $cand) {
            if ($cand !== '' && mb_strlen($cand) >= 3 && mb_strpos($norm, $cand) !== false) {
                $e['branch'] = $b['name']; break 2;
            }
        }
    }
    return $e;
}

/* ═══════════════════ NLU: intent detection ═══════════════════ */

function hasAny($norm, array $words) {
    foreach ($words as $w) if (mb_strpos($norm, $w) !== false) return true;
    return false;
}

function detectIntent($rawText, $ents) {
    $n = applyTranslit(normTxt($rawText));

    if ($ents['chassis']) return 'chassis';
    if (count($ents['models']) >= 2 || hasAny($n, ['قارن', 'مقارنه', ' ولا ', ' vs ', 'compare', 'افضل من', 'أفضل من'])) {
        if (count($ents['models']) >= 2) return 'compare';
    }
    if (hasAny($n, ['كل حاجه عن', 'كل حاجة عن', 'كل التفاصيل', 'تفاصيل', 'everything', 'deep', 'details', 'تقرير عن', 'ملف'])) return 'deep';
    if (hasAny($n, ['ارخص', 'أرخص', 'cheapest', 'اقل سعر'])) return 'cheapest';
    if (hasAny($n, ['اغلي', 'اغلى', 'أغلى', 'most expensive', 'priciest', 'اعلي سعر', 'اعلى سعر'])) return 'priciest';
    if (hasAny($n, ['اقدم', 'أقدم', 'واقف', 'واقفه', 'oldest', 'aging', 'قديم', 'راكد'])) return 'aging';
    if (hasAny($n, ['كام عربيه', 'كام عربية', 'عدد', 'how many', 'count', 'اجمالي', 'إجمالي', 'كام واحده', 'total'])) return 'count';
    if (hasAny($n, ['سعر', 'اسعار', 'بكام', ' كام', 'price', 'deal', 'عرض', 'خصم', 'اوفر', 'أوفر', 'تمن', 'ثمن', 'التمن'])) return 'price';
    if (hasAny($n, ['مبيعات', 'بيبيع', 'بتتباع', 'sells', 'velocity', 'حركه', 'حركة', 'اداء', 'أداء'])) return 'velocity';
    if (hasAny($n, ['قادم', 'جاي', 'جايه', 'وصول', 'شحنه', 'شحنة', 'incoming', 'arriving', 'هتوصل', 'في الطريق'])) return 'incoming';
    if (hasAny($n, ['الاكثر', 'الأكثر', 'رواج', 'hot', 'best seller', 'الاعلي مبيعا', 'ترند'])) return 'hot';
    if (hasAny($n, ['اعاده الطلب', 'إعادة الطلب', 'reorder', 'اطلب تاني', 'نطلب'])) return 'reorder';
    if (hasAny($n, ['مساعده', 'مساعدة', 'help', 'بتعرف تعمل ايه', 'ممكن تعمل ايه'])) return 'help';
    if (hasAny($n, ['اهلا', 'أهلا', 'هاي', 'هلا', 'صباح', 'مساء', 'hi', 'hello', 'hey', 'ازيك', 'إزيك', 'عامل ايه'])) return 'greet';
    if (hasAny($n, ['متوفر', 'متاح', 'موجود', 'موجوده', 'عندنا', 'عندكم', 'عندك', 'available', 'stock', 'الوان', 'ألوان', 'لون', 'وريني', 'شوفلي', 'دورلي', 'جيبلي', 'عايز', 'محتاج'])) return 'stock';
    if ($ents['model'] || $ents['brand']) return 'stock';   /* named a car → default to stock */
    return null;
}

/* ═══════════════════ data fetchers (role-neutral) ═══════════════════ */

function fetchStock($pdo, $brand, $model, $color = null, $branch = null, $year = null) {
    $sql = "SELECT cars.trim_name, cars.car_year, cars.color, cars.chassis, cars.branch, cars.created_at,
                   branches.name_ar, branches.name_en
            FROM cars LEFT JOIN branches ON cars.branch = branches.name
            WHERE cars.status IN ('available','reserved','consignment')";
    $p = [];
    if ($brand)  { $sql .= " AND cars.brand = ?";  $p[] = $brand; }
    if ($model)  { $sql .= " AND cars.model = ?";  $p[] = $model; }
    if ($color)  { $sql .= " AND cars.color = ?";  $p[] = $color; }
    if ($branch) { $sql .= " AND cars.branch = ?"; $p[] = $branch; }
    if ($year)   { $sql .= " AND cars.car_year = ?"; $p[] = $year; }
    $sql .= " ORDER BY cars.brand, cars.model, cars.color LIMIT 40";
    $st = $pdo->prepare($sql); $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function fetchPricing($pdo, $brand, $model) {
    $st = $pdo->prepare("SELECT trim_name, car_year, official_price, customer_price, trade_price
                         FROM pricing WHERE brand = ? AND model_name = ?
                         ORDER BY car_year DESC, trim_name");
    $st->execute([$brand, $model]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function fetchSold90($pdo, $brand, $model) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM sold_cars sc JOIN cars c ON c.id = sc.car_id
                         WHERE c.brand = ? AND c.model = ? AND sc.sold_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)");
    $st->execute([$brand, $model]);
    return (int)$st->fetchColumn();
}

function fetchIncoming($pdo, $brand, $model) {
    $st = $pdo->prepare("SELECT id, trim_name, year1, year2, quantity
                         FROM incoming_cars WHERE brand = ? AND model = ? ORDER BY id");
    $st->execute([$brand, $model]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function branchTxt($row, $lang) {
    return $lang === 'ar' ? (($row['name_ar'] ?? '') ?: $row['branch']) : (($row['name_en'] ?? '') ?: $row['branch']);
}

/* ═══════════════════ answer builders (role-aware) ═══════════════════ */

function answerStock($pdo, $lang, $isManager, $brand, $model, $color = null, $branch = null, $year = null) {
    $rows = fetchStock($pdo, $brand, $model, $color, $branch, $year);
    if (!$rows) {
        return pick([
            t("None matching that in stock right now 😕", "لا يوجد مطابق متوفر حالياً 😕", $lang),
            t("Out of stock for that at the moment.", "مش متوفر حالياً.", $lang),
        ]);
    }
    $priceCache = [];
    $lines = [];
    foreach ($rows as $r) {
        $key = ($brand ?: '') . '|' . ($model ?: '') . '|' . $r['trim_name'] . '|' . $r['car_year'];
        if (!array_key_exists($key, $priceCache)) {
            $pStmt = $pdo->prepare("SELECT official_price, customer_price FROM pricing
                WHERE brand = ? AND model_name = ? AND trim_name = ? AND car_year = ? LIMIT 1");
            $pStmt->execute([$brand, $model, $r['trim_name'], $r['car_year']]);
            $priceCache[$key] = $pStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $p = $priceCache[$key];
        $line  = "• {$r['trim_name']} ({$r['car_year']}) — " . colorLabel($pdo, $r['color'], $lang);
        $line .= "\n   📍 " . branchTxt($r, $lang) . "   🔩 {$r['chassis']}";
        if ($p) {
            $bits = [];
            $off = fmtOfficial($p['official_price'], $lang);
            if ($off) $bits[] = $off;
            $bits[] = dealLabel($p['customer_price'], $lang);
            $line .= "\n   💰 " . implode('  ·  ', $bits);
        }
        $lines[] = $line;
    }
    $count = count($rows);
    $head = $count === 1
        ? t("Found 1 car 🎯", "لقيت عربية واحدة 🎯", $lang)
        : t("Found {$count} cars 🎯", "لقيت {$count} عربية 🎯", $lang);
    /* quick summary footer: colors + branches at a glance */
    $footer = '';
    if ($count > 2) {
        $byC = []; $byB = [];
        foreach ($rows as $r) {
            $c = colorLabel($pdo, $r['color'], $lang); $byC[$c] = ($byC[$c] ?? 0) + 1;
            $b = branchTxt($r, $lang);                 $byB[$b] = ($byB[$b] ?? 0) + 1;
        }
        arsort($byC); arsort($byB);
        $cs = []; foreach ($byC as $k => $v) $cs[] = "{$k} ×{$v}";
        $bs = []; foreach ($byB as $k => $v) $bs[] = "{$k} ({$v})";
        $footer = "\n\n" . t("Summary:", "الخلاصة:", $lang)
                . "\n🎨 " . implode('، ', $cs)
                . "\n📍 " . implode('، ', $bs);
    }
    return $head . "\n" . implode("\n", $lines) . $footer;
}

function answerPrice($pdo, $lang, $isManager, $brand, $model) {
    $rows = fetchPricing($pdo, $brand, $model);
    if (!$rows) return t("No deal on file for {$brand} {$model} yet.", "لا يوجد عرض مسجّل لـ {$brand} {$model} لسه.", $lang);
    $lines = [];
    foreach ($rows as $r) {
        $off = fmtOfficial($r['official_price'], $lang);
        $line = "• {$r['trim_name']} ({$r['car_year']})";
        if ($off) $line .= " — " . t("official", "رسمي", $lang) . ": {$off}";
        $line .= "\n   " . t("customer", "العميل", $lang) . ": " . dealLabel($r['customer_price'], $lang);
        if ($isManager) $line .= "\n   " . t("trade", "التاجر", $lang) . ": " . dealLabel($r['trade_price'], $lang);
        $lines[] = $line;
    }
    return "💰 {$brand} {$model}\n" . implode("\n", $lines);
}

function answerVelocity($pdo, $lang, $isManager, $brand, $model) {
    $sold90 = fetchSold90($pdo, $brand, $model);
    $rate = $sold90 / 3.0;
    [$emoji, $verdict] = velocityVerdict($rate, $lang);
    if ($isManager) {
        $pace = paceHuman($rate, $lang);
        return t("{$emoji} {$brand} {$model} is {$verdict}.\n({$sold90} sold in 90 days · {$pace})",
                 "{$emoji} {$brand} {$model} {$verdict}.\n(اتباع منه {$sold90} في 90 يوم · {$pace})", $lang);
    }
    return "{$emoji} {$brand} {$model} — {$verdict}.";
}

function answerIncoming($pdo, $lang, $isManager, $brand, $model) {
    $rows = fetchIncoming($pdo, $brand, $model);
    if (!$rows) {
        return pick([
            t("Nothing scheduled for {$brand} {$model} right now 🚦", "مفيش حاجة قادمة لـ {$brand} {$model} حالياً 🚦", $lang),
            t("No incoming {$brand} {$model} on the board yet.", "لا يوجد {$brand} {$model} قادم على اللوحة لسه.", $lang),
        ]);
    }
    $lines = [];
    foreach ($rows as $r) {
        $yr = $r['year1'] . ($r['year2'] ? "/{$r['year2']}" : '');
        $qty = (int)$r['quantity'];
        $cStmt = $pdo->prepare("SELECT color, COUNT(*) AS n FROM incoming_colors WHERE incoming_id = ? GROUP BY color");
        $cStmt->execute([$r['id']]);
        $colorParts = [];
        foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $colorParts[] = colorLabel($pdo, $c['color'], $lang) . " ×{$c['n']}";
        }
        $line = "🚚 {$r['trim_name']} ({$yr}) — " . t("{$qty} coming", "قادم {$qty}", $lang);
        if ($colorParts) $line .= "\n   🎨 " . implode('، ', $colorParts);
        $lines[] = $line;
    }
    return t("On the way for {$brand} {$model}:", "القادم لـ {$brand} {$model}:", $lang) . "\n" . implode("\n", $lines);
}

/* THE flagship: everything about one model, role-gated, one message */
function answerDeep($pdo, $lang, $isManager, $brand, $model) {
    $parts = ["🧠 " . t("Full picture: {$brand} {$model}", "الملف الكامل: {$brand} {$model}", $lang)];

    /* stock summary: count by color, count by branch */
    $rows = fetchStock($pdo, $brand, $model);
    if ($rows) {
        $byColor = []; $byBranch = [];
        foreach ($rows as $r) {
            $c = colorLabel($pdo, $r['color'], $lang);
            $byColor[$c] = ($byColor[$c] ?? 0) + 1;
            $b = branchTxt($r, $lang);
            $byBranch[$b] = ($byBranch[$b] ?? 0) + 1;
        }
        arsort($byColor); arsort($byBranch);
        $cParts = []; foreach ($byColor as $k => $v)  $cParts[] = "{$k} ×{$v}";
        $bParts = []; foreach ($byBranch as $k => $v) $bParts[] = "{$k} ({$v})";
        $parts[] = "🔍 " . t("In stock: " . count($rows), "المتوفر: " . count($rows), $lang)
                 . "\n   🎨 " . implode('، ', $cParts)
                 . "\n   📍 " . implode('، ', $bParts);
    } else {
        $parts[] = "🔍 " . t("In stock: none right now", "المتوفر: لا يوجد حالياً", $lang);
    }

    /* pricing */
    $pr = fetchPricing($pdo, $brand, $model);
    if ($pr) {
        $pl = [];
        foreach ($pr as $r) {
            $off = fmtOfficial($r['official_price'], $lang);
            $l = "   • {$r['trim_name']} ({$r['car_year']})" . ($off ? " — {$off}" : '')
               . " · " . dealLabel($r['customer_price'], $lang);
            if ($isManager) $l .= " · " . t("trade", "تاجر", $lang) . ": " . dealLabel($r['trade_price'], $lang);
            $pl[] = $l;
        }
        $parts[] = "💰 " . t("Prices & deals:", "الأسعار والعروض:", $lang) . "\n" . implode("\n", $pl);
    }

    /* velocity */
    $sold90 = fetchSold90($pdo, $brand, $model);
    $rate = $sold90 / 3.0;
    [$emoji, $verdict] = velocityVerdict($rate, $lang);
    $vLine = "📈 {$emoji} {$verdict}";
    if ($isManager) $vLine .= " (" . t("{$sold90} sold / 90d", "اتباع {$sold90} في 90 يوم", $lang) . " · " . paceHuman($rate, $lang) . ")";
    $parts[] = $vLine;

    /* aging — managers only get numbers */
    if ($isManager && $rows) {
        $now = time(); $ages = [];
        foreach ($rows as $r) {
            if (!empty($r['created_at'])) $ages[] = (int)floor(($now - strtotime($r['created_at'])) / 86400);
        }
        if ($ages) {
            $avg = (int)round(array_sum($ages) / count($ages));
            $max = max($ages);
            $flag = $max >= 60 ? ' ⚠️' : '';
            /* the actual oldest unit — actionable, not just a number */
            $oldest = null;
            foreach ($rows as $r) {
                if (empty($r['created_at'])) continue;
                if ($oldest === null || strtotime($r['created_at']) < strtotime($oldest['created_at'])) $oldest = $r;
            }
            $line = "⏳ " . t("Aging: avg {$avg}d · oldest {$max}d{$flag}", "العمر بالمخزون: متوسط {$avg} يوم · الأقدم {$max} يوم{$flag}", $lang);
            if ($oldest) {
                $line .= "\n   🔩 " . t("oldest unit: ", "أقدم وحدة: ", $lang)
                       . "{$oldest['chassis']} · " . colorLabel($pdo, $oldest['color'], $lang)
                       . " @ " . branchTxt($oldest, $lang);
            }
            $parts[] = $line;
        }
    }

    /* incoming */
    $inc = fetchIncoming($pdo, $brand, $model);
    if ($inc) {
        $tot = 0; foreach ($inc as $r) $tot += (int)$r['quantity'];
        $parts[] = "🚚 " . t("Incoming: {$tot} on the way", "القادم: {$tot} في الطريق", $lang);
    }

    return implode("\n\n", $parts);
}

/* ═══════════════════ AI tier (optional) ═══════════════════ */

function buildDataPack($pdo, $lang, $isManager, $ents) {
    $out = [];
    $models = $ents['models'];
    if (!$models && $ents['brand']) {
        $st = $pdo->prepare("SELECT DISTINCT model_name FROM models WHERE brand = ? AND active = 1 LIMIT 12");
        $st->execute([$ents['brand']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $m) $models[] = ['brand' => $ents['brand'], 'model' => $m];
    }
    if (!$models) {
        /* global snapshot */
        $rows = $pdo->query("SELECT brand, model, COUNT(*) n FROM cars
                             WHERE status IN ('available','reserved','consignment')
                             GROUP BY brand, model ORDER BY n DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
        $out[] = "AVAILABLE STOCK COUNTS:";
        foreach ($rows as $r) $out[] = "- {$r['brand']} {$r['model']}: {$r['n']}";
        if ($isManager) {
            $hot = $pdo->query("SELECT c.brand, c.model, COUNT(*) s FROM sold_cars sc JOIN cars c ON c.id = sc.car_id
                                WHERE sc.sold_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                                GROUP BY c.brand, c.model ORDER BY s DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
            if ($hot) { $out[] = "SOLD LAST 60 DAYS:"; foreach ($hot as $r) $out[] = "- {$r['brand']} {$r['model']}: {$r['s']}"; }
        }
        return implode("\n", $out);
    }
    foreach (array_slice($models, 0, 3) as $mm) {
        $b = $mm['brand']; $m = $mm['model'];
        $out[] = "=== {$b} {$m} ===";
        $rows = fetchStock($pdo, $b, $m);
        $out[] = "STOCK (" . count($rows) . "):";
        foreach (array_slice($rows, 0, 25) as $r) {
            $out[] = "- {$r['trim_name']} {$r['car_year']} {$r['color']} @ {$r['branch']} chassis {$r['chassis']}";
        }
        $pr = fetchPricing($pdo, $b, $m);
        if ($pr) {
            $out[] = "PRICING:";
            foreach ($pr as $r) {
                $l = "- {$r['trim_name']} {$r['car_year']}: official {$r['official_price']}, customer '{$r['customer_price']}'";
                if ($isManager) $l .= ", trade '{$r['trade_price']}'";   /* sales pack physically excludes trade */
                $out[] = $l;
            }
        }
        if ($isManager) {
            $out[] = "SOLD LAST 90 DAYS: " . fetchSold90($pdo, $b, $m);
        }
        $inc = fetchIncoming($pdo, $b, $m);
        if ($inc) {
            $out[] = "INCOMING:";
            foreach ($inc as $r) $out[] = "- {$r['trim_name']} {$r['year1']}" . ($r['year2'] ? "/{$r['year2']}" : '') . " qty {$r['quantity']}";
        }
    }
    return implode("\n", $out);
}

function callClaudeAI($apiKey, $lang, $isManager, $username, $question, $dataPack) {
    $roleName = $isManager ? 'manager' : 'sales';
    $system = "You are the First 1 Car stock assistant (Egyptian multi-brand car dealership internal system). "
            . "Answer ONLY from the DATA section — never invent stock, prices, or numbers. "
            . "customer/trade price values are TEXT deal labels (رسمي=official / أوفر=over / خصم=discount + amount) — quote them verbatim, never do math on them; official price is the only real number. "
            . "User role: {$roleName}. "
            . ($isManager ? "" : "STRICT: this user is sales staff — NEVER mention trade prices, sold counts, velocity numbers, or reorder advice, even if asked directly; politely say it's manager-only. ")
            . "Reply in " . ($lang === 'ar' ? "Egyptian Arabic, friendly and warm" : "English, friendly") . ", concise (under 180 words), plain text with light emoji, no markdown headers.";
    $payload = json_encode([
        'model'      => 'claude-haiku-4-5',
        'max_tokens' => 600,
        'system'     => $system,
        'messages'   => [[
            'role'    => 'user',
            'content' => "DATA:\n{$dataPack}\n\nUSER ({$username}) ASKS: {$question}",
        ]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 6,
    ]);
    $res = curl_exec($ch);
    $err = curl_errno($ch);
    curl_close($ch);
    if ($err || !$res) return null;
    $data = json_decode($res, true);
    if (!isset($data['content'][0]['text'])) return null;
    return trim($data['content'][0]['text']);
}

/* ═══════════════════ FREE-TEXT ROUTER ═══════════════════ */

if ($text !== '' && !$tap) {
    $ents   = extractEntities($pdo, $text);
    $intent = detectIntent($text, $ents);
    $brand  = $ents['brand'];
    $model  = $ents['model'];

    /* ── CONVERSATION MEMORY: follow-ups without repeating the model ──
       "والسعر؟" / "طب الأسود منه؟" after asking about Tiggo 7 → reuse it */
    if (!$model && !$ents['chassis'] && !empty($ctx['model'])) {
        $followIntents = ['price', 'velocity', 'incoming', 'deep', 'stock', 'count'];
        if (in_array($intent, $followIntents, true) || ($intent === null && ($ents['color'] || $ents['branch'] || $ents['year']))) {
            $brand = $ctx['brand'] ?? null;
            $model = $ctx['model'];
            if ($intent === null) $intent = 'stock';
            $ents['models'] = [['brand' => $brand, 'model' => $model]];
        }
    }

    /* ── SMALL TALK: he answers like a person, in Arabic, English,
       or Franco (ezayak / 3amel eh) — only when it's not a data ask ── */
    if (!$model && !$brand && !$ents['chassis']) {
        $talk = smallTalk($text, $lang, $username, $isManager);
        if ($talk !== null) {
            respond($ctx, $talk, menuOptions($isManager, $lang));
        }
    }

    /* greetings / help */
    if ($intent === 'greet') {
        $hi = $username !== '' ? " {$username}" : '';
        respond($ctx, pick([
            t("Hey{$hi}! 🚗 Ask me anything — a model, a color, a chassis number, or just tap below.",
              "أهلاً{$hi}! 🚗 اسألني أي حاجة — موديل، لون، رقم شاسيه، أو دوس تحت.", $lang),
            t("Hi{$hi}! 👋 Try: \"black Tiggo 7?\", \"991628\", or \"everything about Jolion\".",
              "أهلاً{$hi}! 👋 جرّب: \"تيجو 7 أسود؟\"، \"991628\"، أو \"كل حاجة عن جوليون\".", $lang),
        ]), menuOptions($isManager, $lang));
    }
    if ($intent === 'help') {
        respond([], t(
            "I can answer things like:\n• \"black Tiggo 7 available?\"\n• \"Emgrand price?\"\n• \"991628\" (chassis lookup)\n• \"compare Tiggo 7 and Tiggo 8\"\n• \"everything about Jolion\"\n• \"how many Chery in stock?\"" . ($isManager ? "\n• \"oldest cars in stock\"" : ""),
            "أقدر أرد على حاجات زي:\n• \"في تيجو 7 أسود؟\"\n• \"بكام الامجراند؟\"\n• \"991628\" (بحث بالشاسيه)\n• \"قارن تيجو 7 وتيجو 8\"\n• \"كل حاجة عن جوليون\"\n• \"كام شيري متوفر؟\"" . ($isManager ? "\n• \"أقدم عربيات واقفة\"" : ""), $lang),
            menuOptions($isManager, $lang));
    }

    /* chassis lookup — full car card in text */
    if ($intent === 'chassis') {
        $st = $pdo->prepare("SELECT cars.*, b.name_ar, b.name_en FROM cars
                             LEFT JOIN branches b ON b.name = cars.branch
                             WHERE UPPER(cars.chassis) LIKE ? ORDER BY cars.id DESC LIMIT 3");
        $st->execute(['%' . $ents['chassis'] . '%']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            respond([], t("No car with chassis \"{$ents['chassis']}\" in the system 🔍",
                          "مفيش عربية بشاسيه \"{$ents['chassis']}\" في النظام 🔍", $lang), menuOptions($isManager, $lang));
        }
        $stMap = ['available' => t('Available ✅','متاحة ✅',$lang), 'sold' => t('Sold 💰','مباعة 💰',$lang),
                  'reserved' => t('Reserved ⏳','محجوزة ⏳',$lang), 'consignment' => t('Consignment 🤝','امانة 🤝',$lang)];
        $lines = [];
        foreach ($rows as $r) {
            $days = !empty($r['created_at']) ? (int)floor((time() - strtotime($r['created_at'])) / 86400) : null;
            $l = "🚗 {$r['brand']} {$r['model']} {$r['trim_name']} ({$r['car_year']})"
               . "\n   🔩 {$r['chassis']} · 🎨 " . colorLabel($pdo, $r['color'], $lang)
               . "\n   📍 " . branchTxt($r, $lang) . " · " . ($stMap[$r['status']] ?? $r['status']);
            if ($days !== null) $l .= "\n   📅 " . t("{$days} days in stock", "{$days} يوم بالمخزون", $lang);
            /* full price picture for this exact car */
            $pStmt = $pdo->prepare("SELECT official_price, customer_price, trade_price FROM pricing
                WHERE brand = ? AND model_name = ? AND trim_name = ? AND car_year = ? LIMIT 1");
            $pStmt->execute([$r['brand'], $r['model'], $r['trim_name'], $r['car_year']]);
            if ($p = $pStmt->fetch(PDO::FETCH_ASSOC)) {
                $bits = [];
                $off = fmtOfficial($p['official_price'], $lang);
                if ($off) $bits[] = $off;
                $bits[] = dealLabel($p['customer_price'], $lang);
                if ($isManager) $bits[] = t('trade: ', 'تاجر: ', $lang) . dealLabel($p['trade_price'], $lang);
                $l .= "\n   💰 " . implode('  ·  ', $bits);
            }
            $lines[] = $l;
        }
        respond([], implode("\n\n", $lines), menuOptions($isManager, $lang));
    }

    /* compare two models */
    if ($intent === 'compare' && count($ents['models']) >= 2) {
        $blocks = [];
        foreach (array_slice($ents['models'], 0, 2) as $mm) {
            $b = $mm['brand']; $m = $mm['model'];
            $stock = fetchStock($pdo, $b, $m);
            $pr = fetchPricing($pdo, $b, $m);
            $offs = [];
            foreach ($pr as $r) { $n = officialNum($r['official_price']); if ($n) $offs[] = $n; }
            $sold90 = fetchSold90($pdo, $b, $m);
            [$emoji, $verdict] = velocityVerdict($sold90 / 3.0, $lang);
            $bl = "🚗 {$b} {$m}"
                . "\n   🔍 " . t("stock: ", "المتوفر: ", $lang) . count($stock);
            if ($offs) {
                $bl .= "\n   💰 " . (count($offs) > 1
                    ? fmtOfficial(min($offs), $lang) . " → " . fmtOfficial(max($offs), $lang)
                    : fmtOfficial($offs[0], $lang));
            }
            $bl .= "\n   📈 {$emoji} {$verdict}";
            if ($isManager) $bl .= " (" . t("{$sold90}/90d", "{$sold90}/90 يوم", $lang) . ")";
            $blocks[] = $bl;
        }
        respond([], t("⚖️ Head to head:", "⚖️ المقارنة:", $lang) . "\n\n" . implode("\n\n", $blocks),
                menuOptions($isManager, $lang));
    }

    /* count */
    if ($intent === 'count') {
        $sql = "SELECT COUNT(*) FROM cars WHERE status IN ('available','reserved','consignment')";
        $p = []; $scope = t('in total', 'إجمالاً', $lang);
        if ($model)  { $sql .= " AND model = ?";  $p[] = $model;  $scope = "{$brand} {$model}"; }
        elseif ($brand) { $sql .= " AND brand = ?"; $p[] = $brand; $scope = $brand; }
        if ($ents['color'])  { $sql .= " AND color = ?";  $p[] = $ents['color']; $scope .= ' ' . colorLabel($pdo, $ents['color'], $lang); }
        if ($ents['branch']) { $sql .= " AND branch = ?"; $p[] = $ents['branch']; }
        $st = $pdo->prepare($sql); $st->execute($p);
        $n = (int)$st->fetchColumn();
        respond([], t("🔢 {$n} available — {$scope}.", "🔢 المتوفر {$n} — {$scope}.", $lang),
                $model ? followUps($lang, $isManager) : menuOptions($isManager, $lang));
    }

    /* cheapest / priciest (among models with available stock) */
    if ($intent === 'cheapest' || $intent === 'priciest') {
        $sqlBM = "SELECT DISTINCT c.brand, c.model FROM cars c WHERE c.status IN ('available','reserved','consignment')";
        $pBM = [];
        if ($brand) { $sqlBM .= " AND c.brand = ?"; $pBM[] = $brand; }
        $sqlBM .= " LIMIT 60";
        $st = $pdo->prepare($sqlBM); $st->execute($pBM);
        $cands = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            foreach (fetchPricing($pdo, $r['brand'], $r['model']) as $p) {
                $n = officialNum($p['official_price']);
                if ($n) $cands[] = ['b' => $r['brand'], 'm' => $r['model'], 'tr' => $p['trim_name'], 'y' => $p['car_year'], 'n' => $n];
            }
        }
        if (!$cands) respond([], t("No priced models in stock to rank.", "لا توجد موديلات مسعّرة متوفرة للترتيب.", $lang), menuOptions($isManager, $lang));
        usort($cands, fn($a, $b2) => $intent === 'cheapest' ? $a['n'] <=> $b2['n'] : $b2['n'] <=> $a['n']);
        $top = array_slice($cands, 0, 3);
        $lines = [];
        foreach ($top as $i => $c) {
            $lines[] = ($i + 1) . ". {$c['b']} {$c['m']} {$c['tr']} ({$c['y']}) — " . fmtOfficial($c['n'], $lang);
        }
        $head = $intent === 'cheapest'
            ? t("💸 Most affordable in stock:", "💸 الأقل سعراً من المتوفر:", $lang)
            : t("👑 Highest priced in stock:", "👑 الأعلى سعراً من المتوفر:", $lang);
        respond([], $head . "\n" . implode("\n", $lines), menuOptions($isManager, $lang));
    }

    /* aging — manager detail, sales qualitative */
    if ($intent === 'aging') {
        if (!$isManager) {
            respond([], t("Stock aging details are manager-only 🔒 — but ask me about stock or prices anytime!",
                          "تفاصيل عمر المخزون للمديرين فقط 🔒 — بس اسألني عن المتوفر أو الأسعار في أي وقت!", $lang),
                    menuOptions($isManager, $lang));
        }
        $sql = "SELECT brand, model, trim_name, car_year, color, chassis, branch, created_at
                FROM cars WHERE status IN ('available','reserved','consignment')";
        $p = [];
        if ($model) { $sql .= " AND model = ?"; $p[] = $model; }
        elseif ($brand) { $sql .= " AND brand = ?"; $p[] = $brand; }
        $sql .= " ORDER BY created_at ASC LIMIT 5";
        $st = $pdo->prepare($sql); $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) respond([], t("Nothing in stock to age-rank.", "لا يوجد مخزون للترتيب.", $lang), menuOptions($isManager, $lang));
        $lines = [];
        foreach ($rows as $r) {
            $days = !empty($r['created_at']) ? (int)floor((time() - strtotime($r['created_at'])) / 86400) : 0;
            $flag = $days >= 60 ? ' ⚠️' : '';
            $lines[] = "• {$r['brand']} {$r['model']} {$r['trim_name']} — " . colorLabel($pdo, $r['color'], $lang)
                     . "\n   ⏳ " . t("{$days} days", "{$days} يوم", $lang) . "{$flag} · 📍 {$r['branch']} · 🔩 {$r['chassis']}";
        }
        respond([], t("⏳ Longest sitting in stock:", "⏳ الأقدم في المخزون:", $lang) . "\n" . implode("\n", $lines),
                menuOptions($isManager, $lang));
    }

    /* model-level intents resolved from free text */
    if ($model && in_array($intent, ['stock', 'price', 'velocity', 'incoming', 'deep'], true)) {
        if ($intent === 'reorder' && !$isManager) $intent = 'stock';
        if     ($intent === 'price')    $msg = answerPrice($pdo, $lang, $isManager, $brand, $model);
        elseif ($intent === 'velocity') $msg = answerVelocity($pdo, $lang, $isManager, $brand, $model);
        elseif ($intent === 'incoming') $msg = answerIncoming($pdo, $lang, $isManager, $brand, $model);
        elseif ($intent === 'deep')     $msg = answerDeep($pdo, $lang, $isManager, $brand, $model);
        else                            $msg = answerStock($pdo, $lang, $isManager, $brand, $model, $ents['color'], $ents['branch'], $ents['year']);
        respond(['brand' => $brand, 'model' => $model], $msg, followUps($lang, $isManager));
    }

    /* brand-only stock question → brand summary */
    if ($brand && in_array($intent, ['stock', null], true) && !$model) {
        $st = $pdo->prepare("SELECT model, COUNT(*) n FROM cars
                             WHERE brand = ? AND status IN ('available','reserved','consignment')
                             GROUP BY model ORDER BY n DESC");
        $st->execute([$brand]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $lines = array_map(fn($r) => "• {$r['model']} ×{$r['n']}", $rows);
            $opts  = array_map(fn($r) => ['label' => $r['model'], 'field' => 'model', 'value' => $r['model']], array_slice($rows, 0, 8));
            $opts[] = backOption($lang);
            respond(['brand' => $brand, 'intent' => 'stock'],
                    t("🔍 {$brand} in stock:", "🔍 المتوفر من {$brand}:", $lang) . "\n" . implode("\n", $lines)
                    . "\n\n" . t("Tap a model for details 👇", "دوس على موديل للتفاصيل 👇", $lang), $opts);
        }
    }

    /* known intent but no model → route into the tap flow */
    if (in_array($intent, ['stock', 'price', 'velocity', 'incoming', 'hot', 'reorder', 'deep'], true)) {
        $ctx = ['intent' => $intent];
        if ($brand) $ctx['brand'] = $brand;
        /* fall through into the tap-flow below */
    } else {
        /* ── couldn't resolve locally → AI tier if configured ── */
        if ($AI_KEY !== '') {
            $pack = buildDataPack($pdo, $lang, $isManager, $ents);
            $ai   = callClaudeAI($AI_KEY, $lang, $isManager, $username, $text, $pack);
            if ($ai !== null && $ai !== '') {
                respond([], $ai, menuOptions($isManager, $lang));
            }
        }
        respond($ctx, pick([
            t("Hmm, I didn't spot a car in that — but tap below and I've got you 👇",
              "مش لاقي عربية في كلامك — بس دوس تحت وأنا معاك 👇", $lang),
            t("Not sure which model you mean 🤔 pick one and I'll dig in:",
              "مش متأكد من الموديل 🤔 اختار واحد وأنا هدوّر:", $lang),
        ]), menuOptions($isManager, $lang));
    }
}

/* ═══════════════════ TAP FLOW (v2, + deep intent) ═══════════════════ */

if ($tap && isset($tap['field'])) {
    if ($tap['field'] === '__reset__') {
        $ctx = [];
    } else {
        $keepModel = !empty($tap['keepModel']);
        $ctx[$tap['field']] = $tap['value'];
        if ($tap['field'] === 'intent') {
            if ($keepModel) { unset($ctx['color']); }
            else { unset($ctx['brand'], $ctx['model'], $ctx['color']); }
        }
        if ($tap['field'] === 'brand') { unset($ctx['model'], $ctx['color']); }
        if ($tap['field'] === 'model') { unset($ctx['color']); }
    }
}

/* STEP 1: menu */
if (empty($ctx['intent'])) {
    $hi = $username !== '' ? " {$username}" : '';
    respond($ctx, pick([
        t("Hey{$hi}! 🚗 Type anything (\"black Tiggo 7?\") or tap below.",
          "أهلاً{$hi}! 🚗 اكتب أي حاجة (\"تيجو 7 أسود؟\") أو دوس تحت.", $lang),
        t("Hi{$hi}! Ask me anything about the cars 👇", "أهلاً{$hi}! اسألني أي حاجة عن العربيات 👇", $lang),
        t("Ready when you are{$hi} 💪 What do you need?", "جاهز{$hi} 💪 محتاج تعرف إيه؟", $lang),
    ]), menuOptions($isManager, $lang));
}

$intent = $ctx['intent'];

/* REORDER (manager+ only) */
if ($intent === 'reorder') {
    if (!$isManager) {
        respond([], t("That one's for managers 🔒 — ask yours, or peek at the Forecast page.",
                      "دي للمديرين 🔒 — اسأل مديرك أو بُص على صفحة التوقعات.", $lang),
                menuOptions($isManager, $lang));
    }
    respond([], t(
        "📦 Full reorder reasoning per model — velocity, current stock, and aging — lives on the Forecast page's Reorder Guide.",
        "📦 كل تفاصيل إعادة الطلب لكل موديل — سرعة البيع والمخزون والعمر — موجودة في دليل إعادة الطلب بصفحة التوقعات.", $lang),
        [backOption($lang)]);
}

/* WHAT'S HOT */
if ($intent === 'hot') {
    $stmt = $pdo->query("
        SELECT c.brand, c.model, COUNT(*) AS sold
        FROM sold_cars sc JOIN cars c ON c.id = sc.car_id
        WHERE sc.sold_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
        GROUP BY c.brand, c.model
        ORDER BY sold DESC
        LIMIT 5
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        respond([], t("It's been quiet — no sales in the last 60 days to rank yet.",
                      "الأمور هادية — لا مبيعات في آخر 60 يوم للترتيب.", $lang), [backOption($lang)]);
    }
    $medals = ['🥇', '🥈', '🥉', '4️⃣', '5️⃣'];
    $lines = [];
    foreach ($rows as $i => $r) {
        $medal = $medals[$i] ?? '•';
        if ($isManager) {
            $lines[] = t("{$medal} {$r['brand']} {$r['model']} — {$r['sold']} sold",
                         "{$medal} {$r['brand']} {$r['model']} — اتباع منه {$r['sold']}", $lang);
        } else {
            $lines[] = "{$medal} {$r['brand']} {$r['model']}";
        }
    }
    respond([], t("🔥 Hottest movers (last 60 days):", "🔥 الأكثر رواجاً (آخر 60 يوم):", $lang)
            . "\n" . implode("\n", $lines), [backOption($lang)]);
}

/* STEP 2: brand picker */
if (empty($ctx['brand'])) {
    $brands = $pdo->query("SELECT name FROM brands ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $options = array_map(fn($b) => ['label' => $b, 'field' => 'brand', 'value' => $b], $brands);
    $options[] = backOption($lang);
    $prompt = [
        'stock'    => t("Which brand are we checking stock for? 🚗", "هنشوف مخزون أي ماركة؟ 🚗", $lang),
        'price'    => t("Which brand's deal do you want? 💰", "عايز عرض أي ماركة؟ 💰", $lang),
        'velocity' => t("Which brand's sales should I read? 📈", "أقرالك مبيعات أي ماركة؟ 📈", $lang),
        'incoming' => t("Which brand's shipments? 🚚", "شحنات أي ماركة؟ 🚚", $lang),
        'deep'     => t("Which brand should I profile? 🧠", "أعملك ملف كامل لأي ماركة؟ 🧠", $lang),
    ][$intent] ?? t("Which brand?", "أي ماركة؟", $lang);
    respond($ctx, $prompt, $options);
}

/* STEP 3: model picker */
if (empty($ctx['model'])) {
    $stmt = $pdo->prepare("SELECT DISTINCT model_name FROM models WHERE brand = ? AND active = 1 ORDER BY model_name");
    $stmt->execute([$ctx['brand']]);
    $models = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$models) {
        respond([], t("No active {$ctx['brand']} models on file yet.",
                      "لا يوجد موديلات {$ctx['brand']} مفعّلة حالياً.", $lang),
                menuOptions($isManager, $lang));
    }
    $options = array_map(fn($m) => ['label' => $m, 'field' => 'model', 'value' => $m], $models);
    $options[] = ['label' => t('⬅ Back', '⬅ رجوع', $lang), 'field' => 'brand', 'value' => null];
    respond($ctx, t("Nice — which {$ctx['brand']}? 👇", "تمام — أي {$ctx['brand']}؟ 👇", $lang), $options);
}

$brand = $ctx['brand'];
$model = $ctx['model'];

/* DEEP DIVE */
if ($intent === 'deep') {
    respond($ctx, answerDeep($pdo, $lang, $isManager, $brand, $model), followUps($lang, $isManager));
}

/* PRICE */
if ($intent === 'price') {
    respond($ctx, answerPrice($pdo, $lang, $isManager, $brand, $model), followUps($lang, $isManager));
}

/* VELOCITY */
if ($intent === 'velocity') {
    respond($ctx, answerVelocity($pdo, $lang, $isManager, $brand, $model), followUps($lang, $isManager));
}

/* INCOMING */
if ($intent === 'incoming') {
    respond($ctx, answerIncoming($pdo, $lang, $isManager, $brand, $model), followUps($lang, $isManager));
}

/* STOCK — color picker then result */
if (empty($ctx['color'])) {
    $stmt = $pdo->prepare("SELECT DISTINCT color FROM cars
                            WHERE brand = ? AND model = ? AND status IN ('available','reserved','consignment')");
    $stmt->execute([$brand, $model]);
    $colors = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$colors) {
        respond($ctx, pick([
            t("No {$brand} {$model} in stock right now 😕 — want the deal or what's coming?",
              "مفيش {$brand} {$model} متوفر حالياً 😕 — عايز العرض ولا القادم؟", $lang),
            t("{$brand} {$model} is out of stock at the moment.",
              "{$brand} {$model} مش متوفر حالياً.", $lang),
        ]), [
            ['label' => t('💰 See deal', '💰 شوف العرض', $lang),        'field' => 'intent', 'value' => 'price',    'keepModel' => true],
            ['label' => t('🚚 When arriving', '🚚 القادم امتى', $lang),  'field' => 'intent', 'value' => 'incoming', 'keepModel' => true],
            backOption($lang),
        ]);
    }
    $options = array_map(fn($c) => ['label' => colorLabel($pdo, $c, $lang), 'field' => 'color', 'value' => $c], $colors);
    $options[] = ['label' => t('🎨 Any color', '🎨 أي لون', $lang), 'field' => 'color', 'value' => '__all__'];
    $options[] = ['label' => t('⬅ Back', '⬅ رجوع', $lang), 'field' => 'model', 'value' => null];
    respond($ctx, t("Which color? 🎨", "أي لون؟ 🎨", $lang), $options);
}

$color = $ctx['color'] === '__all__' ? null : $ctx['color'];
respond($ctx, answerStock($pdo, $lang, $isManager, $brand, $model, $color), followUps($lang, $isManager));
