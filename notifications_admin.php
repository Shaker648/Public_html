<?php
/*
 * notifications_admin.php — the notification control room (admin).
 * Reached from the Permissions page. Decide, per event, which roles and which
 * people get a phone notification; see every linked phone; send tests; set
 * quiet hours and the message language; read the delivery log.
 */
require 'auth.php';
require 'config.php';
require_once __DIR__ . '/notify_smart.php';

perm_require('page.notifications_admin');

$lang = ($_GET['lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
$dir  = $lang === 'ar' ? 'rtl' : 'ltr';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
push_tables($pdo);
push_vapid($pdo);   // make sure the server keys exist
$events = notify_events();
$roles  = ['admin', 'manager', 'sales'];

/* ─── JSON actions ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($csrf, (string)($in['csrf'] ?? ''))) { echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }
    $by = (string)$_SESSION['username'];
    try {
        switch ($in['action'] ?? '') {
            case 'save_rules':
                $clean = [];
                foreach ($events as $ev => $meta) {
                    $r = (array)($in['rules'][$ev] ?? []);
                    $clean[$ev] = [
                        'on'    => !empty($r['on']),
                        'roles' => array_values(array_intersect((array)($r['roles'] ?? []), $roles)),
                        'plus'  => array_values(array_unique(array_map('intval', (array)($r['plus'] ?? [])))),
                        'minus' => array_values(array_unique(array_map('intval', (array)($r['minus'] ?? [])))),
                        'owner' => ($meta[5] ?? null) === null ? null : !empty($r['owner']),
                    ];
                }
                push_setting_set($pdo, 'notify_rules', json_encode($clean), $by);
                echo json_encode(['ok' => true]); exit;
            case 'save_options':
                $o = (array)($in['options'] ?? []);
                push_setting_set($pdo, 'notify_options', json_encode([
                    'self'       => !empty($o['self']),
                    'quiet'      => !empty($o['quiet']),
                    'quiet_from' => preg_match('/^\d{2}:\d{2}$/', $o['quiet_from'] ?? '') ? $o['quiet_from'] : '23:00',
                    'quiet_to'   => preg_match('/^\d{2}:\d{2}$/', $o['quiet_to'] ?? '') ? $o['quiet_to'] : '08:00',
                    'lang'       => ($o['lang'] ?? 'ar') === 'en' ? 'en' : 'ar',
                ]), $by);
                echo json_encode(['ok' => true]); exit;
            case 'test_sub':
                $st = $pdo->prepare("SELECT user_id FROM push_subscriptions WHERE id = ?");
                $st->execute([(int)($in['id'] ?? 0)]);
                $u = (int)$st->fetchColumn();
                $res = $u ? notify_test($pdo, $u, (int)$in['id'], notify_options($pdo)['lang']) : [];
                $r = $res ? array_values($res)[0] : [0, 'not found'];
                echo json_encode(['ok' => $r[0] >= 200 && $r[0] < 300, 'code' => $r[0], 'error' => $r[1]]); exit;
            case 'test_target':
                $type = (string)($in['type'] ?? 'all'); $val = (string)($in['value'] ?? '');
                $sql = "SELECT u.id FROM users u WHERE u.active = 1";
                $args = [];
                if ($type === 'role' && in_array($val, $roles, true)) { $sql .= " AND u.role = ?"; $args[] = $val; }
                if ($type === 'user') { $sql .= " AND u.id = ?"; $args[] = (int)$val; }
                $st = $pdo->prepare($sql); $st->execute($args);
                $sent = 0; $total = 0; $errs = [];
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                    foreach (notify_test($pdo, (int)$uid, null, notify_options($pdo)['lang']) as [$code, $err]) {
                        $total++;
                        if ($code >= 200 && $code < 300) $sent++; else $errs[] = $code . ' ' . $err;
                    }
                }
                echo json_encode(['ok' => $sent > 0, 'sent' => $sent, 'total' => $total, 'errors' => array_slice($errs, 0, 5)], JSON_UNESCAPED_UNICODE); exit;
            case 'save_smart':
                $o = (array)($in['smart'] ?? []);
                push_setting_set($pdo, 'notify_smart', json_encode([
                    'reserve_days' => (int)($o['reserve_days'] ?? 5), 'aged_days' => (int)($o['aged_days'] ?? 90), 'aged_dow' => (int)($o['aged_dow'] ?? 6),
                    'amana_days' => (int)($o['amana_days'] ?? 14), 'bank_days' => (int)($o['bank_days'] ?? 3),
                    'clockout_at' => preg_match('/^\d{2}:\d{2}$/', $o['clockout_at'] ?? '') ? $o['clockout_at'] : '23:00',
                    'fail_count' => (int)($o['fail_count'] ?? 5),
                ]), $by);
                echo json_encode(['ok' => true, 'smart' => notify_smart($pdo)]); exit;
            case 'save_branches':
                $valid = $pdo->query("SELECT name FROM branches")->fetchAll(PDO::FETCH_COLUMN);
                $map = [];
                foreach ((array)($in['map'] ?? []) as $id => $b) if ((int)$id > 0 && in_array($b, $valid, true)) $map[(int)$id] = $b;
                push_setting_set($pdo, 'user_branches', json_encode((object)$map, JSON_UNESCAPED_UNICODE), $by);
                echo json_encode(['ok' => true]); exit;
            case 'save_templates':
                $tpl = [];
                foreach (array_slice((array)($in['templates'] ?? []), 0, 30) as $x) {
                    $tb = mb_substr(trim(str_replace("\r", '', (string)($x['body'] ?? ''))), 0, 500);
                    if ($tb !== '') $tpl[] = ['title' => mb_substr(trim((string)($x['title'] ?? '')), 0, 80), 'body' => $tb];
                }
                push_setting_set($pdo, 'notify_templates', json_encode($tpl, JSON_UNESCAPED_UNICODE), $by);
                echo json_encode(['ok' => true]); exit;
            case 'cancel_scheduled':
                $pdo->prepare("UPDATE notify_scheduled SET cancelled = 1 WHERE id = ? AND sent_log_id IS NULL")->execute([(int)($in['id'] ?? 0)]);
                echo json_encode(['ok' => true]); exit;
            case 'remind_off':
                $ids = array_map('intval', (array)($in['ids'] ?? []));
                if (!$ids) { echo json_encode(['ok' => false, 'error' => 'nobody']); exit; }
                $r = notify_custom($pdo, $ids, $lang === 'ar' ? '🔔 فعّل الإشعارات على موبايلك' : '🔔 Turn on notifications on your phone',
                    $lang === 'ar' ? "علشان يوصلك كل جديد أول بأول:\n1) افتح النظام من موبايلك\n2) ادخل «إشعاراتي» 🔔\n3) اضغط «تفعيل الإشعارات» ووافق\n\nآيفون: لازم تضيف النظام للشاشة الرئيسية الأول (زر المشاركة ← إضافة إلى الشاشة الرئيسية)."
                                   : "So you get everything as it happens:\n1) Open the system on your phone\n2) Go to \"My notifications\" 🔔\n3) Tap \"Turn on notifications\" and allow\n\niPhone: add the system to the Home Screen first (Share → Add to Home Screen).", $by);
                echo json_encode(['ok' => $r[0] > 0, 'people' => $r[0]]); exit;
            case 'send_message':
                $title = trim(preg_replace('/\s+/u', ' ', (string)($in['title'] ?? '')));
                $body  = trim(str_replace("\r", '', (string)($in['body'] ?? '')));
                if ($title === '') $title = $lang === 'ar' ? '📢 رسالة من الإدارة' : '📢 Message from management';
                if ($body === '') { echo json_encode(['ok' => false, 'error' => 'empty']); exit; }
                $title = mb_substr($title, 0, 80); $body = mb_substr($body, 0, 500);
                $ids = array_values(array_unique(array_filter(array_map('intval', (array)($in['ids'] ?? [])))));
                if (!$ids) { echo json_encode(['ok' => false, 'error' => 'nobody']); exit; }
                $needAck = !empty($in['need_ack']);
                $at = (string)($in['send_at'] ?? '');
                if ($at !== '') {                                   // later: the reminders job sends it on time
                    $ts = strtotime($at);
                    if (!$ts || $ts < time() - 60) { echo json_encode(['ok' => false, 'error' => 'time']); exit; }
                    $pdo->prepare("INSERT INTO notify_scheduled (title, body, user_ids, need_ack, send_at, created_by) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$title, $body, json_encode($ids), $needAck ? 1 : 0, date('Y-m-d H:i:s', $ts), $by]);
                    echo json_encode(['ok' => true, 'scheduled' => date('Y-m-d H:i', $ts), 'people' => count($ids)]); exit;
                }
                [$people, $devices, $ok, $bad] = notify_custom($pdo, $ids, $title, $body, $by, $needAck);
                echo json_encode(['ok' => $people > 0, 'people' => $people, 'devices' => $devices, 'sent' => $ok, 'failed' => $bad]); exit;
            case 'remove_sub':
                $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?")->execute([(int)($in['id'] ?? 0)]);
                echo json_encode(['ok' => true]); exit;
        }
    } catch (Throwable $e) {
        error_log('notifications_admin: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'server']); exit;
    }
    echo json_encode(['ok' => false, 'error' => 'unknown']); exit;
}

/* ─── page data ─── */
$rules   = notify_rules($pdo);
$opts    = notify_options($pdo);
$users   = $pdo->query("SELECT id, username, role, active FROM users ORDER BY FIELD(role,'admin','manager','sales'), username")->fetchAll(PDO::FETCH_ASSOC);
$subs    = $pdo->query("SELECT s.id, s.user_id, s.device, s.last_error, s.fail_count, u.username, u.role,
                               TIMESTAMPDIFF(SECOND, s.created_at, NOW()) AS age_created,
                               TIMESTAMPDIFF(SECOND, s.last_ok_at, NOW()) AS age_ok
                        FROM push_subscriptions s LEFT JOIN users u ON u.id = s.user_id ORDER BY s.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$log     = $pdo->query("SELECT *, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age FROM notify_log ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
$today   = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(delivered),0) d, COALESCE(SUM(failed),0) f FROM notify_log WHERE created_at >= CURDATE()")->fetch(PDO::FETCH_ASSOC);
$nIos    = count(array_filter($subs, fn($s) => preg_match('/iPhone|iPad/', (string)$s['device'])));
$nAnd    = count(array_filter($subs, fn($s) => preg_match('/Android/', (string)$s['device'])));
$withDev = count(array_unique(array_column($subs, 'user_id')));
$devPer  = array_count_values(array_map('intval', array_column($subs, 'user_id')));   // user id => linked phones
$smart   = notify_smart($pdo);
$branchL = $pdo->query("SELECT name, name_ar, name_en FROM branches ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$ubr     = notify_user_branches($pdo);
$tpls    = json_decode(push_setting($pdo, 'notify_templates', ''), true) ?: [];
$lastRun = push_setting($pdo, 'smart_last_run', '');
$lastAge = $lastRun !== '' ? (int)$pdo->query("SELECT TIMESTAMPDIFF(SECOND, " . $pdo->quote($lastRun) . ", NOW())")->fetchColumn() : null;
// messages sent: who got it, who opened it, who pressed «تمام»
$sent = $pdo->query("SELECT l.id, l.title, l.body, l.need_ack, l.actor, TIMESTAMPDIFF(SECOND, l.created_at, NOW()) AS age, COUNT(i.id) AS n,
                            SUM(i.read_at IS NOT NULL) AS nread, SUM(i.ack_at IS NOT NULL) AS nack
                     FROM notify_log l JOIN notify_inbox i ON i.log_id = l.id WHERE l.event = 'message'
                     GROUP BY l.id ORDER BY l.id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
$sentWho = [];
if ($sent) {
    $inS = implode(',', array_map('intval', array_column($sent, 'id')));
    foreach ($pdo->query("SELECT i.log_id, u.username, i.read_at, i.ack_at FROM notify_inbox i JOIN users u ON u.id = i.user_id
                          WHERE i.log_id IN ($inS) ORDER BY (i.ack_at IS NULL), (i.read_at IS NULL), u.username") as $r) {
        $sentWho[(int)$r['log_id']][] = $r;
    }
}
$sched = $pdo->query("SELECT * FROM notify_scheduled WHERE sent_log_id IS NULL AND cancelled = 0 ORDER BY send_at")->fetchAll(PDO::FETCH_ASSOC);
$noDev = array_values(array_filter($users, fn($u) => (int)$u['active'] && empty($devPer[(int)$u['id']])));

$T = $lang === 'ar' ? [
    'title' => 'التحكم في الإشعارات', 'sub' => 'حدد من يستلم إشعار كل حدث على موبايله — آيفون وأندرويد',
    'perm' => 'الصلاحيات', 'mine' => 'إشعاراتي', 'dash' => 'الرئيسية',
    'k_dev' => 'جهاز مربوط', 'k_ios' => 'آيفون', 'k_and' => 'أندرويد', 'k_today' => 'إشعار اليوم', 'k_rate' => 'نسبة الوصول',
    'rules' => 'من يستلم ماذا', 'rulesNote' => 'فعّل الحدث ثم اختر الأدوار. «أشخاص» لإضافة شخص معين دائماً أو استبعاده.',
    'event' => 'الحدث', 'on' => 'تشغيل', 'r_admin' => 'مدير النظام', 'r_manager' => 'المدراء', 'r_sales' => 'المبيعات', 'people' => 'أشخاص',
    'save' => '💾 حفظ', 'saved' => '✓ تم الحفظ', 'preset' => 'اختيار سريع:', 'p_admin' => 'كل شيء للمدير فقط', 'p_mgr' => 'المدير + المدراء', 'p_all' => 'الكل يستلم الكل', 'p_none' => 'إيقاف الكل',
    'devices' => 'الأجهزة المربوطة', 'noDev' => 'لا توجد أجهزة مربوطة بعد — كل شخص يفتح «إشعاراتي» من موبايله ويضغط تفعيل',
    'user' => 'المستخدم', 'device' => 'الجهاز', 'added' => 'أضيف', 'lastOk' => 'آخر وصول', 'never' => 'لم يصل بعد', 'remove' => 'إزالة', 'test' => 'تجربة',
    'send' => 'إرسال إشعار تجربة', 'to' => 'إلى', 'everyone' => 'الكل', 'role' => 'دور', 'sendBtn' => '📨 إرسال الآن', 'res' => fn($s, $t) => "وصل إلى $s من $t جهاز",
    'opts' => 'الإعدادات', 'self' => 'أرسل الإشعار أيضاً للشخص الذي قام بالعملية', 'quiet' => 'ساعات الهدوء (لا إشعارات ليلاً — تبقى في السجل)', 'from' => 'من', 'until' => 'إلى', 'mlang' => 'لغة رسائل الإشعارات',
    'log' => 'سجل الإشعارات', 'noLog' => 'لم يُرسل أي إشعار بعد', 'rcp' => 'مستلم', 'dev' => 'جهاز', 'ok' => 'وصل', 'fail' => 'فشل',
    'always' => 'دائماً', 'never_' => 'أبداً', 'byRole' => 'حسب الدور', 'close' => 'تم', 'pickFor' => 'أشخاص لـ',
    'ago' => ['الآن', 'منذ %d دقيقة', 'منذ %d ساعة', 'أمس', 'منذ %d يوم'],
    'm_h' => 'إرسال رسالة', 'm_note' => 'اكتب رسالتك واختر من يستلمها — تصل فوراً على موبايلاتهم مع اللوجو',
    'm_title' => 'العنوان (اختياري)', 'm_titlePh' => '📢 رسالة من الإدارة', 'm_body' => 'الرسالة', 'm_bodyPh' => 'مثال: اجتماع الساعة 10 صباحاً في الفرع الرئيسي',
    'm_to' => 'إلى من؟', 'm_all' => 'الكل', 'm_search' => 'ابحث باسم الشخص…', 'm_noPhone' => 'لا يوجد موبايل مفعّل', 'm_phones' => 'موبايل',
    'm_preview' => 'معاينة على الموبايل', 'm_now' => 'الآن', 'm_send' => '🚀 إرسال الرسالة', 'm_clear' => 'مسح',
    'm_sum' => 'سيصل إلى %p شخص · %d موبايل', 'm_sumNo' => '(%n بدون موبايل مفعّل — ستظهر في سجلهم فقط)', 'm_pick' => 'اختر شخصاً واحداً على الأقل',
    'm_confirm' => 'إرسال الرسالة إلى %p شخص؟', 'm_done' => '✅ تم الإرسال — وصلت إلى %s من %d موبايل', 'm_doneNo' => '✅ تم الحفظ — لا يوجد موبايل مفعّل عند المستلمين بعد',
    'm_empty' => 'اكتب الرسالة أولاً',
] : [
    'title' => 'Notification control', 'sub' => 'Choose who gets a phone notification for each event — iPhone and Android',
    'perm' => 'Permissions', 'mine' => 'My notifications', 'dash' => 'Dashboard',
    'k_dev' => 'linked devices', 'k_ios' => 'iPhone', 'k_and' => 'Android', 'k_today' => 'sent today', 'k_rate' => 'delivered',
    'rules' => 'Who gets what', 'rulesNote' => 'Switch an event on, then pick roles. "People" adds or excludes specific people.',
    'event' => 'Event', 'on' => 'On', 'r_admin' => 'Admin', 'r_manager' => 'Managers', 'r_sales' => 'Sales', 'people' => 'People',
    'save' => '💾 Save', 'saved' => '✓ Saved', 'preset' => 'Quick pick:', 'p_admin' => 'Everything to admin only', 'p_mgr' => 'Admin + managers', 'p_all' => 'Everyone gets everything', 'p_none' => 'Turn all off',
    'devices' => 'Linked devices', 'noDev' => 'No devices yet — each person opens "My notifications" on their phone and taps turn on',
    'user' => 'User', 'device' => 'Device', 'added' => 'Added', 'lastOk' => 'Last delivered', 'never' => 'Nothing yet', 'remove' => 'Remove', 'test' => 'Test',
    'send' => 'Send a test notification', 'to' => 'To', 'everyone' => 'Everyone', 'role' => 'Role', 'sendBtn' => '📨 Send now', 'res' => fn($s, $t) => "Delivered to $s of $t devices",
    'opts' => 'Settings', 'self' => 'Also notify the person who did the action', 'quiet' => 'Quiet hours (no notifications at night — kept in the log)', 'from' => 'From', 'until' => 'To', 'mlang' => 'Notification message language',
    'log' => 'Notification log', 'noLog' => 'Nothing sent yet', 'rcp' => 'recipients', 'dev' => 'devices', 'ok' => 'delivered', 'fail' => 'failed',
    'always' => 'Always', 'never_' => 'Never', 'byRole' => 'By role', 'close' => 'Done', 'pickFor' => 'People for',
    'ago' => ['just now', '%d min ago', '%d h ago', 'yesterday', '%d days ago'],
    'm_h' => 'Send a message', 'm_note' => 'Write your message and pick who gets it — it arrives on their phones right away, with the logo',
    'm_title' => 'Title (optional)', 'm_titlePh' => '📢 Message from management', 'm_body' => 'Message', 'm_bodyPh' => 'e.g. Meeting at 10 am in the main branch',
    'm_to' => 'Send to', 'm_all' => 'Everyone', 'm_search' => 'Search by name…', 'm_noPhone' => 'no phone linked', 'm_phones' => 'phone',
    'm_preview' => 'Phone preview', 'm_now' => 'now', 'm_send' => '🚀 Send message', 'm_clear' => 'Clear',
    'm_sum' => 'Goes to %p people · %d phones', 'm_sumNo' => '(%n without a linked phone — it will only be in their history)', 'm_pick' => 'Pick at least one person',
    'm_confirm' => 'Send this message to %p people?', 'm_done' => '✅ Sent — delivered to %s of %d phones', 'm_doneNo' => '✅ Saved — none of them has a linked phone yet',
    'm_empty' => 'Write the message first',
];
$T += $lang === 'ar' ? [
    'g_live' => '⚡ أحداث فورية', 'g_remind' => '⏰ تذكيرات تلقائية', 'g_security' => '🛡️ الأمان', 'owner' => 'المعني بالأمر',
    'ownerTip' => 'اللي العملية تخصه: اللي حجز العربية، موظفين الفرع اللي العربية رايحاله، صاحب طلب التقسيط…',
    's_h' => 'إعدادات التذكيرات التلقائية', 's_note' => 'التذكيرات بتتبعت في مواعيد الشغل (١٠ ص – ٩ م) — ما عدا تذكير الانصراف',
    's_res' => 'ذكّر بالحجز بعد', 's_aged' => 'عربية «قديمة» لو بقالها أكتر من', 's_dow' => 'تقرير العربيات القديمة كل', 's_amana' => 'ذكّر بالأمانة بعد',
    's_bank' => 'ذكّر لو البنك ما ردّش بعد', 's_co' => 'تذكير «سجّل انصراف» الساعة', 's_fail' => 'نبّه الأدمن بعد محاولات دخول غلط', 'days' => 'يوم', 'times' => 'محاولات',
    'dows' => ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'],
    's_run' => 'آخر تشغيل للتذكيرات:', 's_never' => 'لسه ما اشتغلتش', 's_cron' => 'علشان تذكير الانصراف بالليل يشتغل حتى لو محدش فاتح النظام: ضيف Cron Job كل ١٥ دقيقة على notify_cron.php (زي الملخص اليومي).',
    'b_h' => 'فرع كل موظف', 'b_note' => 'لما عربية تتنقل لفرع، إشعار «عربية جاية لفرعك» + زر «استلمت» بيوصل لـ: اللي مسجّل حضور في الفرع ده النهارده + اللي محدد فرعه هنا.',
    'b_none' => '— بدون فرع ثابت —', 'b_open' => '🚚 عربيات في الطريق',
    't_h' => 'قوالب جاهزة', 't_save' => '⭐ حفظ كقالب', 't_empty' => 'مفيش قوالب — اكتب رسالة واضغط «حفظ كقالب»', 't_del' => 'حذف القالب؟',
    'ack_t' => 'اطلب تأكيد «👍 تمام» من كل واحد', 'when' => 'وقت الإرسال', 'w_now' => 'دلوقتي', 'w_later' => 'في وقت محدد', 'sch_ok' => '🕐 اتجدولت — هتتبعت %t لـ %p شخص', 'sch_bad' => 'اختار وقت في المستقبل',
    'sch_btn' => '🕐 جدولة الرسالة',
    'r_h' => 'رسائلك المرسلة', 'r_note' => '✔✔ قرأها · 👍 ضغط «تمام» · ⏳ لسه', 'r_read' => 'قرأها', 'r_ack' => 'تمام', 'r_none' => 'لسه ما بعتش رسائل', 'r_show' => 'مين قرأ؟',
    'q_h' => 'رسائل متجدولة', 'q_cancel' => 'إلغاء', 'q_cancelQ' => 'إلغاء الرسالة المتجدولة؟',
    'o_h' => 'الإشعارات مقفولة عندهم', 'o_note' => 'دول ما فعّلوش الإشعارات على أي موبايل — مش هيوصلهم غير جوه النظام', 'o_all' => '📣 ذكّرهم كلهم', 'o_one' => 'ذكّره', 'o_done' => '✅ اتبعتلهم رسالة بالخطوات', 'o_none' => '🎉 كل الناس مفعّلين الإشعارات',
] : [
    'g_live' => '⚡ Live events', 'g_remind' => '⏰ Automatic reminders', 'g_security' => '🛡️ Security', 'owner' => 'Person concerned',
    'ownerTip' => 'Whoever it is about: who reserved the car, the staff of the branch it is going to, the salesperson of the bank request…',
    's_h' => 'Automatic reminder settings', 's_note' => 'Reminders go out in working hours (10 am – 9 pm) — except the clock-out one',
    's_res' => 'Remind about a reservation after', 's_aged' => 'A car is "old" after', 's_dow' => 'Old-cars report every', 's_amana' => 'Remind about a consignment after',
    's_bank' => 'Remind if the bank has not replied after', 's_co' => '"Clock out" reminder at', 's_fail' => 'Tell the admin after wrong passwords', 'days' => 'days', 'times' => 'tries',
    'dows' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
    's_run' => 'Reminders last ran:', 's_never' => 'not yet', 's_cron' => 'So the late clock-out reminder works even when nobody has the system open, add a Cron Job every 15 minutes for notify_cron.php (like the daily summary).',
    'b_h' => "Each person's branch", 'b_note' => 'When a car moves to a branch, "a car is on its way" + the Received button goes to: whoever clocked in at that branch today + the people assigned to it here.',
    'b_none' => '— no fixed branch —', 'b_open' => '🚚 Cars on the way',
    't_h' => 'Templates', 't_save' => '⭐ Save as template', 't_empty' => 'No templates yet — write a message and tap "Save as template"', 't_del' => 'Delete this template?',
    'ack_t' => 'Ask everyone to confirm with "👍 OK"', 'when' => 'Send', 'w_now' => 'now', 'w_later' => 'at a set time', 'sch_ok' => '🕐 Scheduled — goes out %t to %p people', 'sch_bad' => 'Pick a time in the future',
    'sch_btn' => '🕐 Schedule message',
    'r_h' => 'Messages you sent', 'r_note' => '✔✔ read · 👍 pressed OK · ⏳ not yet', 'r_read' => 'read', 'r_ack' => 'OK', 'r_none' => 'No messages sent yet', 'r_show' => 'Who read it?',
    'q_h' => 'Scheduled messages', 'q_cancel' => 'Cancel', 'q_cancelQ' => 'Cancel this scheduled message?',
    'o_h' => 'Notifications turned off', 'o_note' => 'These people have no phone with notifications on — they only see things inside the system', 'o_all' => '📣 Remind them all', 'o_one' => 'Remind', 'o_done' => '✅ Sent them a message with the steps', 'o_none' => '🎉 Everyone has notifications on',
];
$ago = function ($s) use ($T): string {
    if ($s === null || $s === '') return '';
    $s = max(0, (int)$s);
    if ($s < 60) return $T['ago'][0];
    if ($s < 3600) return sprintf($T['ago'][1], intdiv($s, 60));
    if ($s < 86400) return sprintf($T['ago'][2], intdiv($s, 3600));
    $d = intdiv($s, 86400);
    return $d === 1 ? $T['ago'][3] : sprintf($T['ago'][4], $d);
};
$rate = ($today['d'] + $today['f']) > 0 ? round($today['d'] * 100 / ($today['d'] + $today['f'])) . '%' : '—';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= $T['title'] ?> — First 1 Car</title>
<?php include __DIR__ . '/pwa_head.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<?php include __DIR__ . '/notify_style.php'; ?>
<style>
.na-kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}
.na-k{padding:14px 16px;border-radius:18px;background:var(--card);border:1px solid var(--line);position:relative;overflow:hidden}
.na-k::before{content:'';position:absolute;inset-inline:0;top:0;height:3px;background:var(--kc,#22c55e)}
.na-k b{display:block;font-size:26px;font-weight:900;color:var(--kc,#22c55e);font-variant-numeric:tabular-nums}
.na-k span{font-size:12px;color:var(--mut);font-weight:700}
.na-presets{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:12px;font-size:12px;color:var(--mut);font-weight:700}
.na-presets button{height:30px;padding:0 11px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--txt);font:inherit;font-size:12px;font-weight:700;cursor:pointer}
.na-presets button:hover{border-color:rgba(168,85,247,.45)}
.na-tbl{width:100%;border-collapse:separate;border-spacing:0 6px}
.na-tbl th{font-size:11px;color:var(--mut);font-weight:800;text-align:center;padding:0 6px 4px;white-space:nowrap}
.na-tbl th:first-child{text-align:start}
.na-tbl td{background:rgba(255,255,255,.03);border-top:1px solid var(--line);border-bottom:1px solid var(--line);padding:9px 8px;text-align:center;vertical-align:middle}
.na-tbl td:first-child{text-align:start;border-inline-start:1px solid var(--line);border-start-start-radius:14px;border-end-start-radius:14px}
.na-tbl td:last-child{border-inline-end:1px solid var(--line);border-start-end-radius:14px;border-end-end-radius:14px}
.na-tbl tr.off td{opacity:.5}
.na-tbl tr.off td:nth-child(2){opacity:1}
.na-ev{display:flex;align-items:center;gap:10px;font-size:14px;font-weight:800}
.na-ev i{font-style:normal;width:34px;height:34px;border-radius:11px;display:grid;place-items:center;background:rgba(255,255,255,.05);font-size:17px;flex-shrink:0}
.tg{position:relative;display:inline-block;width:42px;height:24px;cursor:pointer}
.tg input{position:absolute;opacity:0;width:1px;height:1px}
.tg span{position:absolute;inset:0;border-radius:999px;background:rgba(255,255,255,.1);transition:.2s}
.tg span::after{content:'';position:absolute;top:3px;inset-inline-start:3px;width:18px;height:18px;border-radius:50%;background:#cbd5e1;transition:.2s}
.tg input:checked+span{background:linear-gradient(90deg,#22c55e,#9333ea)}
.tg input:checked+span::after{inset-inline-start:21px;background:#fff}
.chk{width:30px;height:30px;border-radius:10px;border:1.5px solid rgba(255,255,255,.14);background:transparent;cursor:pointer;display:inline-grid;place-items:center;color:transparent;font-weight:900;transition:.15s}
.chk.on{background:linear-gradient(135deg,#16a34a,#22c55e);border-color:transparent;color:#fff;box-shadow:0 4px 14px rgba(34,197,94,.3)}
.ppl{height:30px;padding:0 10px;border-radius:10px;border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--txt);font:inherit;font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap}
.ppl .p{color:#86efac}.ppl .m{color:#fca5a5}
.na-save{position:sticky;bottom:12px;z-index:5;display:flex;justify-content:flex-end;gap:10px;align-items:center;margin-top:12px}
.na-save .nf-msg{margin:0}
.na-devs{width:100%;border-collapse:collapse;font-size:13px}
.na-devs th{font-size:11px;color:var(--mut);text-align:start;padding:6px 8px;border-bottom:1px solid var(--line)}
.na-devs td{padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle}
.na-devs small{color:var(--mut)}
.na-devs .bad{color:#fca5a5;font-size:11px;display:block}
.role{font-size:10px;font-weight:800;padding:2px 8px;border-radius:999px;background:rgba(168,85,247,.15);color:#d8b4fe;margin-inline-start:4px}
.na-form{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.na-form select,.na-form input[type=time]{height:40px;border-radius:12px;border:1px solid var(--line);background:#0b1426;color:var(--txt);font:inherit;font-size:13px;padding:0 10px}
.na-opt{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:13px;font-weight:700;flex-wrap:wrap}
.na-opt:last-child{border-bottom:0}
.na-log .nf-item{cursor:default}
.na-log .stats{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}
.na-log .stats span{font-size:11px;font-weight:800;padding:2px 8px;border-radius:999px;background:rgba(255,255,255,.05)}
.na-log .stats .g{color:#86efac}.na-log .stats .r{color:#fca5a5}
.ov{position:fixed;inset:0;z-index:50;background:rgba(2,6,23,.78);backdrop-filter:blur(8px);display:none;align-items:center;justify-content:center;padding:16px}
.ov.on{display:flex}
.sheet{width:100%;max-width:440px;max-height:86vh;overflow-y:auto;border-radius:24px;padding:20px;background:linear-gradient(170deg,#121a33,#0a1122);border:1px solid rgba(168,85,247,.3)}
.sheet h3{font-size:16px;font-weight:900;margin-bottom:12px}
.pu{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:12px;border:1px solid var(--line);margin-bottom:6px}
.pu b{flex:1;font-size:14px}
.seg{display:inline-flex;border-radius:10px;overflow:hidden;border:1px solid var(--line)}
.seg button{border:0;background:transparent;color:var(--mut);font:inherit;font-size:11px;font-weight:800;padding:6px 9px;cursor:pointer}
.seg button.on[data-v="plus"]{background:rgba(34,197,94,.2);color:#86efac}
.seg button.on[data-v="minus"]{background:rgba(239,68,68,.2);color:#fca5a5}
.seg button.on[data-v=""]{background:rgba(255,255,255,.08);color:var(--txt)}
/* send a message */
.nm{border-color:rgba(34,197,94,.35);background:linear-gradient(160deg,rgba(22,163,74,.10),rgba(147,51,234,.08) 60%,var(--card))}
.nm-grid{display:grid;grid-template-columns:1.35fr 1fr;gap:18px;align-items:start}
.nm label.l{display:block;font-size:12px;font-weight:800;color:var(--mut);margin:0 0 6px}
.nm input.in,.nm textarea{width:100%;border-radius:14px;border:1px solid var(--line);background:#0b1426;color:var(--txt);font:inherit;font-size:14px;padding:11px 13px;outline:none;transition:border-color .15s,box-shadow .15s}
.nm textarea{min-height:110px;resize:vertical;line-height:1.7}
.nm input.in:focus,.nm textarea:focus{border-color:rgba(34,197,94,.6);box-shadow:0 0 0 3px rgba(34,197,94,.15)}
.nm .cnt{font-size:11px;color:var(--mut);text-align:end;margin-top:4px;font-variant-numeric:tabular-nums}
.nm .fld{margin-bottom:12px}
.nm-roles{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px}
.nm-roles button{height:34px;padding:0 13px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--txt);font:inherit;font-size:13px;font-weight:800;cursor:pointer;transition:.15s}
.nm-roles button.on{background:linear-gradient(90deg,#16a34a,#22c55e);border-color:transparent;color:#fff;box-shadow:0 4px 14px rgba(34,197,94,.3)}
.nm-roles button.part{border-color:rgba(34,197,94,.6);color:#86efac}
.nm-search{width:100%;height:38px;border-radius:12px;border:1px solid var(--line);background:#0b1426;color:var(--txt);font:inherit;font-size:13px;padding:0 12px;margin-bottom:8px;outline:none}
.nm-people{max-height:230px;overflow-y:auto;display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:6px;padding:2px}
.nm-p{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.03);cursor:pointer;user-select:none;transition:.15s}
.nm-p.on{border-color:rgba(34,197,94,.55);background:rgba(34,197,94,.1)}
.nm-p .bx{width:20px;height:20px;border-radius:7px;border:1.5px solid rgba(255,255,255,.2);display:grid;place-items:center;font-size:12px;font-weight:900;color:transparent;flex-shrink:0}
.nm-p.on .bx{background:#22c55e;border-color:#22c55e;color:#fff}
.nm-p .nm-n{flex:1;min-width:0}
.nm-p b{display:block;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.nm-p small{font-size:10.5px;color:var(--mut)}
.nm-p small.no{color:#fca5a5}
.nm-sum{margin-top:10px;font-size:12.5px;font-weight:800;color:#86efac}
.nm-sum small{display:block;color:var(--mut);font-weight:700;margin-top:2px}
.nm-phone{border-radius:30px;padding:16px 12px 22px;background:linear-gradient(160deg,#1e293b,#020617);border:1px solid rgba(255,255,255,.08);box-shadow:inset 0 0 0 6px #0b1120,0 20px 50px rgba(0,0,0,.4);min-height:250px;position:relative;overflow:hidden}
.nm-phone::before{content:'';display:block;width:90px;height:22px;border-radius:999px;background:#000;margin:0 auto 14px}
.nm-phone .clock{text-align:center;font-size:40px;font-weight:300;color:#e2e8f0;letter-spacing:1px;margin-bottom:14px;font-family:Inter,sans-serif}
.nm-bub{display:flex;gap:10px;padding:11px 12px;border-radius:18px;background:rgba(241,245,249,.9);color:#0f172a;box-shadow:0 8px 24px rgba(0,0,0,.35);animation:nmIn .35s ease}
.nm-bub img{width:38px;height:38px;border-radius:9px;flex-shrink:0}
.nm-bub .hd{display:flex;justify-content:space-between;gap:8px;font-size:11px;color:#64748b;font-weight:700}
.nm-bub .tt{font-size:13.5px;font-weight:800;margin-top:1px;word-break:break-word}
.nm-bub .bd{font-size:13px;line-height:1.5;white-space:pre-wrap;word-break:break-word;max-height:120px;overflow:hidden}
.nm-bub .bd:empty::before{content:'…';color:#94a3b8}
@keyframes nmIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:none}}
.nm-acts{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px}
.nm-acts .nf-msg{margin:0;flex:1}
.nm-go{height:48px;padding:0 22px;border:0;border-radius:14px;background:linear-gradient(90deg,#16a34a,#9333ea);color:#fff;font:inherit;font-size:15px;font-weight:900;cursor:pointer;box-shadow:0 10px 26px rgba(34,197,94,.25)}
.nm-go:disabled{opacity:.55;cursor:default}
/* groups, person concerned */
.na-tbl tr.na-grp td{background:none;border:0;padding:14px 4px 2px;font-size:13px;font-weight:900;color:#c4b5fd;text-align:start}
.na-dash{color:var(--mut);font-weight:800}
.chk.own.on{background:linear-gradient(135deg,#0ea5e9,#6366f1);box-shadow:0 4px 14px rgba(56,189,248,.3)}
.na-num{width:74px;height:40px;border-radius:12px;border:1px solid var(--line);background:#0b1426;color:var(--txt);font:inherit;font-size:14px;font-weight:800;padding:0 10px;text-align:center}
.na-run{margin-top:10px;padding:10px 12px;border-radius:12px;background:rgba(56,189,248,.07);border:1px solid rgba(56,189,248,.2);font-size:12.5px;font-weight:700;color:var(--mut)}
.na-run b{color:#7dd3fc}.na-run small{display:block;margin-top:4px;line-height:1.6}
.na-brs{display:grid;gap:6px;max-height:340px;overflow-y:auto;padding:2px}
.na-br{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 10px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.03)}
.na-br b{font-size:13.5px}
.na-br select{height:36px;border-radius:10px;border:1px solid var(--line);background:#0b1426;color:var(--txt);font:inherit;font-size:13px;padding:0 8px;max-width:55%}
.na-off{display:flex;flex-wrap:wrap;gap:8px}
.na-o{display:flex;align-items:center;gap:8px;padding:7px 8px 7px 12px;border-radius:12px;border:1px solid rgba(239,68,68,.3);background:rgba(239,68,68,.06)}
.na-o b{font-size:13.5px}
/* templates, ack, schedule */
.nm-tpls{display:flex;gap:6px;flex-wrap:wrap}
.nm-tpls .tp{display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 6px 0 12px;border-radius:999px;border:1px solid rgba(250,204,21,.35);background:rgba(250,204,21,.08);color:#fde68a;font:inherit;font-size:12.5px;font-weight:800;cursor:pointer;max-width:240px}
[dir=rtl] .nm-tpls .tp{padding:0 12px 0 6px}
.nm-tpls .tp span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.nm-tpls .tp i{font-style:normal;width:20px;height:20px;border-radius:50%;display:grid;place-items:center;background:rgba(255,255,255,.08);color:#fca5a5;font-size:11px}
.nm-tpls .emp{font-size:12px;color:var(--mut);font-weight:700}
.nm-opts{margin-top:12px;display:grid;gap:10px}
.nm-opt{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:13px;font-weight:800;cursor:pointer}
.nm-at{height:36px;border-radius:10px;border:1px solid var(--line);background:#0b1426;color:var(--txt);font:inherit;font-size:13px;padding:0 10px;color-scheme:dark}
#nmWhen button.on{background:rgba(34,197,94,.2);color:#86efac}
/* receipts */
.rc-list{display:grid;gap:8px}
.rc{border:1px solid var(--line);border-radius:16px;background:rgba(255,255,255,.03);overflow:hidden}
.rc summary{list-style:none;cursor:pointer;display:grid;grid-template-columns:1fr auto;gap:6px 12px;padding:12px 14px;position:relative}
.rc summary::-webkit-details-marker{display:none}
.rc-t b{display:block;font-size:14px}.rc-t small{display:block;font-size:12px;color:var(--mut);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rc-s{display:flex;align-items:center;gap:6px;flex-wrap:wrap;justify-content:flex-end}
.rc-s span{font-size:12px;font-weight:900;padding:3px 9px;border-radius:999px}
.rc-s .rd{background:rgba(56,189,248,.14);color:#7dd3fc}.rc-s .ak{background:rgba(250,204,21,.14);color:#fde68a}
.rc-s small{font-size:11px;color:var(--mut);font-weight:700}
.rc-bar{grid-column:1/-1;height:4px;border-radius:4px;background:rgba(255,255,255,.06);overflow:hidden}
.rc-bar i{display:block;height:100%;background:linear-gradient(90deg,#22c55e,#38bdf8);border-radius:4px}
.rc-who{display:flex;flex-wrap:wrap;gap:6px;padding:0 14px 14px}
.rc-who span{font-size:12px;font-weight:800;padding:4px 10px;border-radius:999px;border:1px solid var(--line)}
.rc-who .ak{background:rgba(250,204,21,.1);color:#fde68a;border-color:rgba(250,204,21,.3)}
.rc-who .rd{background:rgba(56,189,248,.1);color:#7dd3fc;border-color:rgba(56,189,248,.3)}
.rc-who .wt{color:var(--mut)}
.rc-sched{display:grid;gap:6px;margin-bottom:12px;padding:12px;border-radius:14px;background:rgba(168,85,247,.07);border:1px solid rgba(168,85,247,.25)}
.rc-sched > b{font-size:13px;color:#d8b4fe}
.rc-q{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12.5px}
.rc-q .tm{font-family:Inter,sans-serif;font-weight:800;color:#c4b5fd}.rc-q .tt{flex:1;min-width:150px;font-weight:700}.rc-q .nn{color:var(--mut);font-weight:800}
@media (max-width:900px){.nm-grid{grid-template-columns:1fr}.nm-phone{min-height:0}.nm-phone .clock{font-size:30px;margin-bottom:10px}}
@media (max-width:900px){.na-kpis{grid-template-columns:repeat(3,1fr)}}
@media (max-width:760px){
  .na-kpis{grid-template-columns:repeat(2,1fr)}
  .na-tbl thead{display:none}
  .na-tbl,.na-tbl tbody,.na-tbl tr,.na-tbl td{display:block;width:100%}
  .na-tbl tr{margin-bottom:10px;border:1px solid var(--line);border-radius:16px;background:rgba(255,255,255,.03);padding:10px 12px}
  .na-tbl td{border:0!important;background:none;padding:4px 0;text-align:start;display:flex;align-items:center;justify-content:space-between;border-radius:0!important}
  .na-tbl td[data-l]::before{content:attr(data-l);font-size:12px;color:var(--mut);font-weight:700}
  .na-devs thead{display:none}.na-devs tr{display:grid;grid-template-columns:1fr auto;gap:4px;padding:10px 0;border-bottom:1px solid var(--line)}.na-devs td{padding:0;border:0}
}
</style>
</head>
<body>
<div class="nf-wrap">
    <header class="nf-head">
        <div><h1>🔔 <?= $T['title'] ?></h1><p><?= $T['sub'] ?></p></div>
        <div class="nf-nav">
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?><a class="nf-btn pur" href="permissions_admin.php?lang=<?= $lang ?>">🔐 <?= $T['perm'] ?></a><?php endif; ?>
            <a class="nf-btn" href="notifications.php?lang=<?= $lang ?>">📥 <?= $T['mine'] ?></a>
            <a class="nf-btn" href="dashboard.php?lang=<?= $lang ?>">🏠 <?= $T['dash'] ?></a>
            <a class="nf-btn ghost" href="?lang=<?= $lang === 'ar' ? 'en' : 'ar' ?>"><?= $lang === 'ar' ? 'English' : 'العربية' ?></a>
        </div>
    </header>

    <?php include __DIR__ . '/notify_device_card.php'; ?>

    <div class="na-kpis">
        <div class="na-k"><b><?= count($subs) ?></b><span>📲 <?= $T['k_dev'] ?></span></div>
        <div class="na-k" style="--kc:#e2e8f0"><b><?= $nIos ?></b><span>📱 <?= $T['k_ios'] ?></span></div>
        <div class="na-k" style="--kc:#4ade80"><b><?= $nAnd ?></b><span>🤖 <?= $T['k_and'] ?></span></div>
        <div class="na-k" style="--kc:#a855f7"><b><?= (int)$today['c'] ?></b><span>🔔 <?= $T['k_today'] ?></span></div>
        <div class="na-k" style="--kc:#22d3ee"><b><?= $rate ?></b><span>✅ <?= $T['k_rate'] ?></span></div>
    </div>

    <!-- send a message -->
    <section class="nf-card nm" id="nmCard">
        <h2>✉️ <?= $T['m_h'] ?></h2>
        <p class="nf-note"><?= $T['m_note'] ?></p>
        <div class="nm-grid">
            <div>
                <div class="fld"><label class="l">⭐ <?= $T['t_h'] ?></label><div class="nm-tpls" id="nmTpls"></div></div>
                <div class="fld"><label class="l" for="nmTitle"><?= $T['m_title'] ?></label>
                    <input class="in" id="nmTitle" maxlength="80" placeholder="<?= htmlspecialchars($T['m_titlePh']) ?>"></div>
                <div class="fld"><label class="l" for="nmBody"><?= $T['m_body'] ?></label>
                    <textarea id="nmBody" maxlength="500" placeholder="<?= htmlspecialchars($T['m_bodyPh']) ?>"></textarea>
                    <div class="cnt"><span id="nmCnt">0</span> / 500</div></div>
                <label class="l"><?= $T['m_to'] ?></label>
                <div class="nm-roles" id="nmRoles">
                    <button type="button" data-r="*">👥 <?= $T['m_all'] ?></button>
                    <button type="button" data-r="admin">👑 <?= $T['r_admin'] ?></button>
                    <button type="button" data-r="manager">🧑‍💼 <?= $T['r_manager'] ?></button>
                    <button type="button" data-r="sales">🛒 <?= $T['r_sales'] ?></button>
                </div>
                <input class="nm-search" id="nmSearch" placeholder="🔍 <?= htmlspecialchars($T['m_search']) ?>">
                <div class="nm-people" id="nmPeople">
                    <?php foreach ($users as $u): if (!(int)$u['active']) continue; $n = $devPer[(int)$u['id']] ?? 0; ?>
                    <div class="nm-p" data-id="<?= (int)$u['id'] ?>" data-role="<?= htmlspecialchars($u['role']) ?>" data-dev="<?= $n ?>" data-name="<?= htmlspecialchars(mb_strtolower($u['username'])) ?>" role="checkbox" aria-checked="false" tabindex="0">
                        <span class="bx">✓</span>
                        <span class="nm-n"><b><?= htmlspecialchars($u['username']) ?></b>
                        <small class="<?= $n ? '' : 'no' ?>"><?= htmlspecialchars($T['r_' . $u['role']] ?? $u['role']) ?> · <?= $n ? '📲 ' . $n . ' ' . $T['m_phones'] : $T['m_noPhone'] ?></small></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="nm-sum" id="nmSum"></div>
                <div class="nm-opts">
                    <label class="nm-opt"><span class="tg"><input type="checkbox" id="nmAck"><span></span></span> <?= $T['ack_t'] ?></label>
                    <div class="nm-opt"><span><?= $T['when'] ?>:</span>
                        <div class="seg" id="nmWhen"><button type="button" data-v="now" class="on"><?= $T['w_now'] ?></button><button type="button" data-v="later"><?= $T['w_later'] ?></button></div>
                        <input type="datetime-local" id="nmAt" class="nm-at" hidden>
                    </div>
                </div>
            </div>
            <div>
                <label class="l"><?= $T['m_preview'] ?></label>
                <div class="nm-phone">
                    <div class="clock" id="nmClock"></div>
                    <div class="nm-bub" id="nmBub">
                        <img src="icons/icon-192.png?v=4" alt="">
                        <div style="flex:1;min-width:0">
                            <div class="hd"><span>First 1 Car</span><span><?= $T['m_now'] ?></span></div>
                            <div class="tt" id="nmPt"></div>
                            <div class="bd" id="nmPb"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="nm-acts">
            <button type="button" class="nm-go" id="nmSend"><?= $T['m_send'] ?></button>
            <button type="button" class="nf-btn ghost" id="nmClear"><?= $T['m_clear'] ?></button>
            <button type="button" class="nf-btn ghost" id="nmTplSave"><?= $T['t_save'] ?></button>
            <div class="nf-msg" id="nmMsg"></div>
        </div>
    </section>

    <!-- messages sent: receipts -->
    <section class="nf-card">
        <h2>📬 <?= $T['r_h'] ?></h2>
        <p class="nf-note"><?= $T['r_note'] ?></p>
        <?php if ($sched): ?>
        <div class="rc-sched">
            <b>🕐 <?= $T['q_h'] ?></b>
            <?php foreach ($sched as $q): $qn = count((array)json_decode((string)$q['user_ids'], true)); ?>
            <div class="rc-q" data-q="<?= (int)$q['id'] ?>"><span class="tm"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($q['send_at']))) ?></span>
                <span class="tt"><?= htmlspecialchars($q['title']) ?> — <?= htmlspecialchars(mb_substr($q['body'], 0, 60)) ?></span>
                <span class="nn">👥 <?= $qn ?><?= (int)$q['need_ack'] ? ' · 👍' : '' ?></span>
                <button type="button" class="nf-mini red" data-cancel="<?= (int)$q['id'] ?>"><?= $T['q_cancel'] ?></button></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (!$sent): ?><div class="nf-empty"><?= $T['r_none'] ?></div><?php endif; ?>
        <div class="rc-list">
            <?php foreach ($sent as $m): $n = max(1, (int)$m['n']); $pr = round((int)$m['nread'] * 100 / $n); $pa = round((int)$m['nack'] * 100 / $n); ?>
            <details class="rc">
                <summary>
                    <div class="rc-t"><b><?= htmlspecialchars($m['title']) ?></b><small><?= htmlspecialchars(mb_substr((string)$m['body'], 0, 80)) ?></small></div>
                    <div class="rc-s">
                        <span class="rd">✔✔ <?= (int)$m['nread'] ?>/<?= (int)$m['n'] ?></span>
                        <?php if ((int)$m['need_ack']): ?><span class="ak">👍 <?= (int)$m['nack'] ?>/<?= (int)$m['n'] ?></span><?php endif; ?>
                        <small><?= htmlspecialchars($ago($m['age'])) ?></small>
                    </div>
                    <div class="rc-bar"><i style="width:<?= (int)$m['need_ack'] ? $pa : $pr ?>%"></i></div>
                </summary>
                <div class="rc-who">
                    <?php foreach ($sentWho[(int)$m['id']] ?? [] as $w): $st = $w['ack_at'] ? 'ak' : ($w['read_at'] ? 'rd' : 'wt'); ?>
                    <span class="<?= $st ?>"><?= $st === 'ak' ? '👍' : ($st === 'rd' ? '✔✔' : '⏳') ?> <?= htmlspecialchars($w['username']) ?></span>
                    <?php endforeach; ?>
                </div>
            </details>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- who gets what -->
    <section class="nf-card">
        <h2>🎯 <?= $T['rules'] ?></h2>
        <p class="nf-note"><?= $T['rulesNote'] ?></p>
        <div class="na-presets"><?= $T['preset'] ?>
            <button type="button" data-preset="admin"><?= $T['p_admin'] ?></button>
            <button type="button" data-preset="mgr"><?= $T['p_mgr'] ?></button>
            <button type="button" data-preset="all"><?= $T['p_all'] ?></button>
            <button type="button" data-preset="none"><?= $T['p_none'] ?></button>
        </div>
        <table class="na-tbl" id="rulesTbl">
            <thead><tr><th><?= $T['event'] ?></th><th><?= $T['on'] ?></th><th>👑 <?= $T['r_admin'] ?></th><th>🧑‍💼 <?= $T['r_manager'] ?></th><th>🛒 <?= $T['r_sales'] ?></th><th title="<?= htmlspecialchars($T['ownerTip']) ?>">🎯 <?= $T['owner'] ?></th><th>👤 <?= $T['people'] ?></th></tr></thead>
            <tbody>
            <?php $grp = ''; foreach ($events as $ev => $meta): if (($meta[6] ?? 'live') !== $grp): $grp = $meta[6] ?? 'live'; ?>
            <tr class="na-grp"><td colspan="7"><?= $T['g_' . $grp] ?></td></tr>
            <?php endif; ?>
            <tr data-ev="<?= $ev ?>">
                <td><div class="na-ev"><i><?= $meta[2] ?></i><?= htmlspecialchars($lang === 'ar' ? $meta[0] : $meta[1]) ?></div></td>
                <td data-l="<?= $T['on'] ?>"><label class="tg"><input type="checkbox" class="evon"><span></span></label></td>
                <?php foreach ($roles as $r): ?>
                <td data-l="<?= $T['r_' . $r] ?>"><button type="button" class="chk" data-role="<?= $r ?>">✓</button></td>
                <?php endforeach; ?>
                <td data-l="<?= $T['owner'] ?>"><?php if (($meta[5] ?? null) === null): ?><span class="na-dash">—</span><?php else: ?><button type="button" class="chk own" title="<?= htmlspecialchars($T['ownerTip']) ?>">✓</button><?php endif; ?></td>
                <td data-l="<?= $T['people'] ?>"><button type="button" class="ppl">👤 <span class="p"></span> <span class="m"></span></button></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="na-save"><div class="nf-msg" id="rulesMsg"></div><button type="button" class="nf-btn grn" id="saveRules"><?= $T['save'] ?></button></div>
    </section>

    <div class="nf-grid">
        <!-- automatic reminders -->
        <section class="nf-card">
            <h2>⏰ <?= $T['s_h'] ?></h2>
            <p class="nf-note"><?= $T['s_note'] ?></p>
            <div class="na-opt"><span><?= $T['s_res'] ?></span><div class="na-form"><input type="number" class="na-num" id="sRes" min="1" max="60" value="<?= $smart['reserve_days'] ?>"> <?= $T['days'] ?></div></div>
            <div class="na-opt"><span><?= $T['s_aged'] ?></span><div class="na-form"><input type="number" class="na-num" id="sAged" min="15" max="365" value="<?= $smart['aged_days'] ?>"> <?= $T['days'] ?></div></div>
            <div class="na-opt"><span><?= $T['s_dow'] ?></span><div class="na-form"><select id="sDow"><?php foreach ($T['dows'] as $i => $dn): ?><option value="<?= $i ?>" <?= $smart['aged_dow'] === $i ? 'selected' : '' ?>><?= $dn ?></option><?php endforeach; ?></select></div></div>
            <div class="na-opt"><span><?= $T['s_amana'] ?></span><div class="na-form"><input type="number" class="na-num" id="sAmana" min="1" max="120" value="<?= $smart['amana_days'] ?>"> <?= $T['days'] ?></div></div>
            <div class="na-opt"><span><?= $T['s_bank'] ?></span><div class="na-form"><input type="number" class="na-num" id="sBank" min="1" max="30" value="<?= $smart['bank_days'] ?>"> <?= $T['days'] ?></div></div>
            <div class="na-opt"><span><?= $T['s_co'] ?></span><div class="na-form"><input type="time" id="sCo" value="<?= $smart['clockout_at'] ?>"></div></div>
            <div class="na-opt"><span><?= $T['s_fail'] ?></span><div class="na-form"><input type="number" class="na-num" id="sFail" min="3" max="20" value="<?= $smart['fail_count'] ?>"> <?= $T['times'] ?></div></div>
            <div class="na-run">🔄 <?= $T['s_run'] ?> <b><?= $lastAge !== null && $lastRun > '2001' ? htmlspecialchars($ago($lastAge)) : $T['s_never'] ?></b><small><?= $T['s_cron'] ?></small></div>
            <div class="na-save" style="position:static"><div class="nf-msg" id="smartMsg"></div><button type="button" class="nf-btn grn" id="saveSmart"><?= $T['save'] ?></button></div>
        </section>

        <!-- each person's branch -->
        <section class="nf-card">
            <h2>🏢 <?= $T['b_h'] ?></h2>
            <p class="nf-note"><?= $T['b_note'] ?></p>
            <div class="na-brs">
                <?php foreach ($users as $u): if (!(int)$u['active']) continue; ?>
                <div class="na-br"><b><?= htmlspecialchars($u['username']) ?> <span class="role"><?= htmlspecialchars($T['r_' . $u['role']] ?? $u['role']) ?></span></b>
                    <select data-ub="<?= (int)$u['id'] ?>"><option value=""><?= $T['b_none'] ?></option>
                        <?php foreach ($branchL as $b): ?><option value="<?= htmlspecialchars($b['name']) ?>" <?= ($ubr[(int)$u['id']] ?? '') === $b['name'] ? 'selected' : '' ?>><?= htmlspecialchars(($lang === 'ar' ? $b['name_ar'] : $b['name_en']) ?: $b['name']) ?></option><?php endforeach; ?>
                    </select></div>
                <?php endforeach; ?>
            </div>
            <div class="na-save" style="position:static"><a class="nf-btn ghost" href="transfer_receive.php?lang=<?= $lang ?>"><?= $T['b_open'] ?></a><div class="nf-msg" id="brMsg"></div><button type="button" class="nf-btn grn" id="saveBr"><?= $T['save'] ?></button></div>
        </section>
    </div>

    <!-- people with notifications off -->
    <section class="nf-card">
        <h2>🔕 <?= $T['o_h'] ?> <span class="nf-count"><?= count($noDev) ?></span></h2>
        <?php if (!$noDev): ?><div class="nf-empty"><?= $T['o_none'] ?></div><?php else: ?>
        <p class="nf-note"><?= $T['o_note'] ?></p>
        <div class="na-off">
            <?php foreach ($noDev as $u): ?>
            <div class="na-o"><b><?= htmlspecialchars($u['username']) ?></b><span class="role"><?= htmlspecialchars($T['r_' . $u['role']] ?? $u['role']) ?></span>
                <button type="button" class="nf-mini" data-remind="<?= (int)$u['id'] ?>">🔔 <?= $T['o_one'] ?></button></div>
            <?php endforeach; ?>
        </div>
        <div class="na-save" style="position:static"><div class="nf-msg" id="offMsg"></div><button type="button" class="nf-btn pur" id="remindAll" data-ids="<?= htmlspecialchars(json_encode(array_map(fn($u) => (int)$u['id'], $noDev))) ?>"><?= $T['o_all'] ?></button></div>
        <?php endif; ?>
    </section>

    <div class="nf-grid">
        <!-- test -->
        <section class="nf-card">
            <h2>📨 <?= $T['send'] ?></h2>
            <div class="na-form">
                <span style="font-size:13px;font-weight:700;color:var(--mut)"><?= $T['to'] ?></span>
                <select id="tgt">
                    <option value="all:"><?= $T['everyone'] ?></option>
                    <?php foreach ($roles as $r): ?><option value="role:<?= $r ?>"><?= $T['role'] ?>: <?= $T['r_' . $r] ?></option><?php endforeach; ?>
                    <?php foreach ($users as $u): if (!(int)$u['active']) continue; ?><option value="user:<?= (int)$u['id'] ?>">👤 <?= htmlspecialchars($u['username']) ?></option><?php endforeach; ?>
                </select>
                <button type="button" class="nf-btn pur" id="sendTest"><?= $T['sendBtn'] ?></button>
            </div>
            <div class="nf-msg" id="testMsg"></div>
        </section>

        <!-- options -->
        <section class="nf-card">
            <h2>⚙️ <?= $T['opts'] ?></h2>
            <div class="na-opt"><span><?= $T['self'] ?></span><label class="tg"><input type="checkbox" id="oSelf" <?= $opts['self'] ? 'checked' : '' ?>><span></span></label></div>
            <div class="na-opt"><span><?= $T['quiet'] ?></span><label class="tg"><input type="checkbox" id="oQuiet" <?= $opts['quiet'] ? 'checked' : '' ?>><span></span></label>
                <div class="na-form" style="width:100%"><?= $T['from'] ?> <input type="time" id="oFrom" value="<?= $opts['quiet_from'] ?>"> <?= $T['until'] ?> <input type="time" id="oTo" value="<?= $opts['quiet_to'] ?>"></div></div>
            <div class="na-opt"><span><?= $T['mlang'] ?></span><div class="na-form"><select id="oLang"><option value="ar" <?= $opts['lang'] === 'ar' ? 'selected' : '' ?>>العربية</option><option value="en" <?= $opts['lang'] === 'en' ? 'selected' : '' ?>>English</option></select></div></div>
            <div class="na-save" style="position:static"><div class="nf-msg" id="optMsg"></div><button type="button" class="nf-btn grn" id="saveOpts"><?= $T['save'] ?></button></div>
        </section>
    </div>

    <!-- devices -->
    <section class="nf-card">
        <h2>📲 <?= $T['devices'] ?> <span class="nf-count"><?= count($subs) ?> · <?= $withDev ?> 👤</span></h2>
        <?php if (!$subs): ?><div class="nf-empty"><?= $T['noDev'] ?></div><?php else: ?>
        <table class="na-devs">
            <thead><tr><th><?= $T['user'] ?></th><th><?= $T['device'] ?></th><th><?= $T['added'] ?></th><th><?= $T['lastOk'] ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($subs as $s): ?>
            <tr data-sub="<?= (int)$s['id'] ?>">
                <td><b><?= htmlspecialchars((string)$s['username']) ?></b><span class="role"><?= htmlspecialchars((string)$s['role']) ?></span></td>
                <td><?= preg_match('/iPhone|iPad/', (string)$s['device']) ? '📱' : (preg_match('/Android/', (string)$s['device']) ? '🤖' : '💻') ?> <?= htmlspecialchars((string)$s['device']) ?>
                    <?php if ($s['last_error']): ?><span class="bad">⚠️ <?= htmlspecialchars(mb_substr((string)$s['last_error'], 0, 70)) ?></span><?php endif; ?></td>
                <td><small><?= htmlspecialchars($ago($s['age_created'])) ?></small></td>
                <td><small><?= $s['age_ok'] !== null ? htmlspecialchars($ago($s['age_ok'])) : $T['never'] ?></small></td>
                <td style="white-space:nowrap"><button type="button" class="nf-mini" data-test="<?= (int)$s['id'] ?>">📨 <?= $T['test'] ?></button> <button type="button" class="nf-mini red" data-rm="<?= (int)$s['id'] ?>"><?= $T['remove'] ?></button></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <!-- log -->
    <section class="nf-card na-log">
        <h2>🕘 <?= $T['log'] ?></h2>
        <?php if (!$log): ?><div class="nf-empty"><?= $T['noLog'] ?></div><?php endif; ?>
        <div class="nf-feed">
            <?php foreach ($log as $n): ?>
            <div class="nf-item">
                <div class="t"><?= htmlspecialchars($n['title']) ?></div>
                <div class="b"><?= nl2br(htmlspecialchars((string)$n['body'])) ?></div>
                <div class="stats"><span>👥 <?= (int)$n['recipients'] ?> <?= $T['rcp'] ?></span><span>📲 <?= (int)$n['devices'] ?> <?= $T['dev'] ?></span>
                    <span class="g">✓ <?= (int)$n['delivered'] ?> <?= $T['ok'] ?></span><?php if ((int)$n['failed']): ?><span class="r">✗ <?= (int)$n['failed'] ?> <?= $T['fail'] ?></span><?php endif; ?>
                    <span>🕐 <?= htmlspecialchars($ago($n['age'])) ?></span></div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<div class="ov" id="pplOv"><div class="sheet"><h3 id="pplH"></h3><div id="pplList"></div><button type="button" class="nf-btn grn" id="pplDone" style="width:100%;justify-content:center;margin-top:8px"><?= $T['close'] ?></button></div></div>

<script>
(function () {
    const CSRF = <?= json_encode($csrf) ?>, T = <?= json_encode(['saved' => $T['saved'], 'always' => $T['always'], 'never' => $T['never_'], 'byRole' => $T['byRole'], 'pickFor' => $T['pickFor'], 'fail' => $lang === 'ar' ? '✗ لم يتم: ' : '✗ Failed: '], JSON_UNESCAPED_UNICODE) ?>;
    const RES = <?= json_encode($lang) ?> === 'ar' ? (s, t) => 'وصل إلى ' + s + ' من ' + t + ' جهاز' : (s, t) => 'Delivered to ' + s + ' of ' + t + ' devices';
    const RULES = <?= json_encode($rules) ?>;
    const USERS = <?= json_encode(array_map(fn($u) => ['id' => (int)$u['id'], 'name' => $u['username'], 'role' => $u['role'], 'active' => (int)$u['active']], $users), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const EVN = <?= json_encode(array_map(fn($m) => $m[2] . ' ' . ($lang === 'ar' ? $m[0] : $m[1]), $events), JSON_UNESCAPED_UNICODE) ?>;
    const $ = id => document.getElementById(id);
    const post = d => fetch(location.pathname, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ csrf: CSRF }, d)) }).then(r => r.json());
    const msg = (id, t, ok) => { const m = $(id); m.textContent = t; m.className = 'nf-msg ' + (ok ? 'ok' : 'bad'); if (ok) setTimeout(() => { if (m.textContent === t) m.textContent = ''; }, 3000); };

    /* ── rules table ── */
    function paintRow(tr) {
        const r = RULES[tr.dataset.ev];
        tr.querySelector('.evon').checked = r.on;
        tr.classList.toggle('off', !r.on);
        tr.querySelectorAll('.chk:not(.own)').forEach(b => b.classList.toggle('on', r.roles.includes(b.dataset.role)));
        tr.querySelector('.ppl .p').textContent = r.plus.length ? '+' + r.plus.length : '';
        tr.querySelector('.ppl .m').textContent = r.minus.length ? '−' + r.minus.length : '';
        const ow = tr.querySelector('.chk.own'); if (ow) ow.classList.toggle('on', !!r.owner);
    }
    const rows = [...document.querySelectorAll('#rulesTbl tr[data-ev]')];
    rows.forEach(tr => {
        paintRow(tr);
        const r = RULES[tr.dataset.ev];
        tr.querySelector('.evon').addEventListener('change', e => { r.on = e.target.checked; paintRow(tr); });
        tr.querySelectorAll('.chk.own').forEach(b => b.addEventListener('click', () => { r.owner = !r.owner; if (r.owner) r.on = true; paintRow(tr); }));
        tr.querySelectorAll('.chk:not(.own)').forEach(b => b.addEventListener('click', () => {
            const i = r.roles.indexOf(b.dataset.role); i >= 0 ? r.roles.splice(i, 1) : r.roles.push(b.dataset.role);
            if (r.roles.length && !r.on) r.on = true;
            paintRow(tr);
        }));
        tr.querySelector('.ppl').addEventListener('click', () => openPeople(tr.dataset.ev, tr));
    });
    document.querySelectorAll('[data-preset]').forEach(b => b.addEventListener('click', () => {
        const p = b.dataset.preset;
        Object.values(RULES).forEach(r => {
            r.on = p !== 'none';
            r.roles = p === 'admin' ? ['admin'] : p === 'mgr' ? ['admin', 'manager'] : p === 'all' ? ['admin', 'manager', 'sales'] : r.roles;
        });
        rows.forEach(paintRow);
    }));
    $('saveRules').addEventListener('click', async () => {
        const b = $('saveRules'); b.disabled = true;
        try { const r = await post({ action: 'save_rules', rules: RULES }); r.ok ? msg('rulesMsg', T.saved, true) : msg('rulesMsg', T.fail + r.error, false); }
        catch (e) { msg('rulesMsg', T.fail + e, false); }
        b.disabled = false;
    });

    /* ── people per event: always / by role / never ── */
    let pplEv = null, pplTr = null;
    function openPeople(ev, tr) {
        pplEv = ev; pplTr = tr;
        const r = RULES[ev];
        $('pplH').textContent = T.pickFor + ' ' + EVN[ev];
        $('pplList').innerHTML = USERS.filter(u => u.active).map(u => {
            const v = r.plus.includes(u.id) ? 'plus' : r.minus.includes(u.id) ? 'minus' : '';
            return '<div class="pu" data-u="' + u.id + '"><b>' + u.name.replace(/[<>&]/g, '') + ' <span class="role">' + u.role + '</span></b><div class="seg">' +
                '<button type="button" data-v="plus"' + (v === 'plus' ? ' class="on"' : '') + '>' + T.always + '</button>' +
                '<button type="button" data-v=""' + (v === '' ? ' class="on"' : '') + '>' + T.byRole + '</button>' +
                '<button type="button" data-v="minus"' + (v === 'minus' ? ' class="on"' : '') + '>' + T.never + '</button></div></div>';
        }).join('');
        $('pplList').querySelectorAll('.pu').forEach(row => row.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
            const id = +row.dataset.u;
            r.plus = r.plus.filter(x => x !== id); r.minus = r.minus.filter(x => x !== id);
            if (b.dataset.v === 'plus') { r.plus.push(id); r.on = true; }
            if (b.dataset.v === 'minus') r.minus.push(id);
            row.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
            paintRow(pplTr);
        })));
        $('pplOv').classList.add('on');
    }
    $('pplDone').addEventListener('click', () => $('pplOv').classList.remove('on'));
    $('pplOv').addEventListener('click', e => { if (e.target.id === 'pplOv') $('pplOv').classList.remove('on'); });

    /* ── options ── */
    $('saveOpts').addEventListener('click', async () => {
        const r = await post({ action: 'save_options', options: { self: $('oSelf').checked, quiet: $('oQuiet').checked, quiet_from: $('oFrom').value, quiet_to: $('oTo').value, lang: $('oLang').value } });
        r.ok ? msg('optMsg', T.saved, true) : msg('optMsg', T.fail + r.error, false);
    });

    /* ── send a message ── */
    (function () {
        const M = <?= json_encode(array_intersect_key($T, array_flip(['m_titlePh', 'm_sum', 'm_sumNo', 'm_pick', 'm_confirm', 'm_done', 'm_doneNo', 'm_empty', 'm_send', 'sch_btn', 'sch_ok', 'sch_bad', 't_empty', 't_del'])), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
        let TPL = <?= json_encode(array_values($tpls), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
        let later = false;
        const whenBtns = [...document.querySelectorAll('#nmWhen button')];
        whenBtns.forEach(b => b.addEventListener('click', () => {
            later = b.dataset.v === 'later';
            whenBtns.forEach(x => x.classList.toggle('on', x === b));
            $('nmAt').hidden = !later;
            if (later && !$('nmAt').value) {   // tomorrow 9:00 by default
                const d = new Date(); d.setDate(d.getDate() + 1); d.setHours(9, 0, 0, 0);
                $('nmAt').value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0') + 'T09:00';
            }
            $('nmSend').textContent = later ? M.sch_btn : M.m_send;
        }));
        /* templates */
        function drawTpl() {
            const box = $('nmTpls'); box.innerHTML = '';
            if (!TPL.length) { box.innerHTML = '<span class="emp"></span>'; box.firstChild.textContent = M.t_empty; return; }
            TPL.forEach((t, i) => {
                const b = document.createElement('button'); b.type = 'button'; b.className = 'tp';
                const sp = document.createElement('span'); sp.textContent = t.title || t.body.split('\n')[0]; b.appendChild(sp);
                const x = document.createElement('i'); x.textContent = '✕'; b.appendChild(x);
                b.addEventListener('click', e => {
                    if (e.target === x) { if (confirm(M.t_del)) { TPL.splice(i, 1); drawTpl(); post({ action: 'save_templates', templates: TPL }); } return; }
                    title.value = t.title || ''; body.value = t.body; preview(); body.focus();
                });
                box.appendChild(b);
            });
        }
        $('nmTplSave').addEventListener('click', async () => {
            if (!body.value.trim()) { msg('nmMsg', M.m_empty, false); body.focus(); return; }
            TPL.unshift({ title: title.value.trim(), body: body.value.trim() }); TPL = TPL.slice(0, 30); drawTpl();
            const r = await post({ action: 'save_templates', templates: TPL }); r.ok ? msg('nmMsg', T.saved, true) : msg('nmMsg', T.fail + r.error, false);
        });
        drawTpl();
        const people = [...document.querySelectorAll('#nmPeople .nm-p')], roleBtns = [...document.querySelectorAll('#nmRoles button')];
        const sel = new Set();
        const title = $('nmTitle'), body = $('nmBody');
        const dirOf = t => /[\u0600-\u06FF]/.test(t) ? 'rtl' : 'ltr';
        function preview() {
            if ($('nmMsg').classList.contains('bad')) $('nmMsg').textContent = '';
            const t = title.value.trim() || M.m_titlePh, b = body.value;
            $('nmPt').textContent = t; $('nmPb').textContent = b;
            $('nmPt').dir = dirOf(t); $('nmPb').dir = dirOf(b || t);
            $('nmCnt').textContent = b.length;
        }
        function paint() {
            if ($('nmMsg').classList.contains('bad')) $('nmMsg').textContent = '';   // an old warning goes once they fix it
            people.forEach(p => { const on = sel.has(+p.dataset.id); p.classList.toggle('on', on); p.setAttribute('aria-checked', on); });
            roleBtns.forEach(b => {
                const grp = people.filter(p => b.dataset.r === '*' || p.dataset.role === b.dataset.r);
                const n = grp.filter(p => sel.has(+p.dataset.id)).length;
                b.classList.toggle('on', grp.length > 0 && n === grp.length);
                b.classList.toggle('part', n > 0 && n < grp.length);
            });
            const chosen = people.filter(p => sel.has(+p.dataset.id));
            const devs = chosen.reduce((a, p) => a + +p.dataset.dev, 0), none = chosen.filter(p => !+p.dataset.dev).length;
            $('nmSum').innerHTML = chosen.length ? M.m_sum.replace('%p', chosen.length).replace('%d', devs) + (none ? '<small>' + M.m_sumNo.replace('%n', none) + '</small>' : '') : '';
        }
        people.forEach(p => {
            const flip = () => { const id = +p.dataset.id; sel.has(id) ? sel.delete(id) : sel.add(id); paint(); };
            p.addEventListener('click', flip);
            p.addEventListener('keydown', e => { if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); flip(); } });
        });
        roleBtns.forEach(b => b.addEventListener('click', () => {
            const grp = people.filter(p => b.dataset.r === '*' || p.dataset.role === b.dataset.r);
            const all = grp.every(p => sel.has(+p.dataset.id));
            grp.forEach(p => all ? sel.delete(+p.dataset.id) : sel.add(+p.dataset.id));
            paint();
        }));
        $('nmSearch').addEventListener('input', e => {
            const q = e.target.value.trim().toLowerCase();
            people.forEach(p => { p.style.display = !q || p.dataset.name.includes(q) ? '' : 'none'; });
        });
        title.addEventListener('input', preview); body.addEventListener('input', preview);
        $('nmClear').addEventListener('click', () => { title.value = ''; body.value = ''; sel.clear(); paint(); preview(); $('nmMsg').textContent = ''; });
        $('nmSend').addEventListener('click', async () => {
            if (!body.value.trim()) { msg('nmMsg', M.m_empty, false); body.focus(); return; }
            if (!sel.size) { msg('nmMsg', M.m_pick, false); return; }
            if (later && (!$('nmAt').value || new Date($('nmAt').value) < new Date())) { msg('nmMsg', M.sch_bad, false); $('nmAt').focus(); return; }
            if (!later && !confirm(M.m_confirm.replace('%p', sel.size))) return;
            const b = $('nmSend'), label = b.innerHTML; b.disabled = true; b.innerHTML = '<span class="nf-spin"></span>';
            try {
                const r = await post({ action: 'send_message', title: title.value, body: body.value, ids: [...sel], need_ack: $('nmAck').checked, send_at: later ? $('nmAt').value : '' });
                if (r.ok && r.scheduled) {
                    msg('nmMsg', M.sch_ok.replace('%t', r.scheduled).replace('%p', r.people), true);
                    title.value = ''; body.value = ''; preview();
                    setTimeout(() => location.reload(), 1600);
                } else if (r.ok) {
                    const m = $('nmMsg');
                    m.textContent = r.devices ? M.m_done.replace('%s', r.sent).replace('%d', r.devices) : M.m_doneNo;
                    m.className = 'nf-msg ' + (r.devices && !r.sent ? 'bad' : 'ok');
                    title.value = ''; body.value = ''; preview();
                } else msg('nmMsg', r.error === 'empty' ? M.m_empty : r.error === 'nobody' ? M.m_pick : r.error === 'time' ? M.sch_bad : T.fail + r.error, false);
            } catch (e) { msg('nmMsg', T.fail + e, false); }
            b.disabled = false; b.innerHTML = label;
        });
        const tick = () => { const d = new Date(); $('nmClock').textContent = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); };
        tick(); setInterval(tick, 30000);
        preview(); paint();
    })();

    /* ── automatic reminders ── */
    $('saveSmart').addEventListener('click', async () => {
        const r = await post({ action: 'save_smart', smart: { reserve_days: +$('sRes').value, aged_days: +$('sAged').value, aged_dow: +$('sDow').value,
            amana_days: +$('sAmana').value, bank_days: +$('sBank').value, clockout_at: $('sCo').value, fail_count: +$('sFail').value } });
        if (r.ok) {
            msg('smartMsg', T.saved, true);
            const s = r.smart; $('sRes').value = s.reserve_days; $('sAged').value = s.aged_days; $('sAmana').value = s.amana_days; $('sBank').value = s.bank_days; $('sFail').value = s.fail_count;
        } else msg('smartMsg', T.fail + r.error, false);
    });
    /* ── each person's branch ── */
    $('saveBr').addEventListener('click', async () => {
        const map = {};
        document.querySelectorAll('[data-ub]').forEach(s => { if (s.value) map[s.dataset.ub] = s.value; });
        const r = await post({ action: 'save_branches', map });
        r.ok ? msg('brMsg', T.saved, true) : msg('brMsg', T.fail + r.error, false);
    });
    /* ── scheduled messages ── */
    document.querySelectorAll('[data-cancel]').forEach(b => b.addEventListener('click', async () => {
        if (!confirm(<?= json_encode($T['q_cancelQ'], JSON_UNESCAPED_UNICODE) ?>)) return;
        const r = await post({ action: 'cancel_scheduled', id: +b.dataset.cancel });
        if (r.ok) b.closest('.rc-q').remove();
    }));
    /* ── people with notifications off ── */
    async function remind(ids, btn) {
        btn.disabled = true;
        const r = await post({ action: 'remind_off', ids });
        r.ok ? msg('offMsg', <?= json_encode($T['o_done'], JSON_UNESCAPED_UNICODE) ?>, true) : msg('offMsg', T.fail + r.error, false);
        if (r.ok && btn.dataset.remind) btn.textContent = '✅';
    }
    document.querySelectorAll('[data-remind]').forEach(b => b.addEventListener('click', () => remind([+b.dataset.remind], b)));
    if ($('remindAll')) $('remindAll').addEventListener('click', () => remind(JSON.parse($('remindAll').dataset.ids), $('remindAll')));

    /* ── tests & devices ── */
    $('sendTest').addEventListener('click', async () => {
        const b = $('sendTest'); b.disabled = true;
        const [type, value] = $('tgt').value.split(':');
        try { const r = await post({ action: 'test_target', type, value }); msg('testMsg', (r.total ? RES(r.sent, r.total) : RES(0, 0)) + (r.errors && r.errors.length ? ' — ' + r.errors[0] : ''), r.ok); }
        catch (e) { msg('testMsg', T.fail + e, false); }
        b.disabled = false;
    });
    document.querySelectorAll('[data-test]').forEach(b => b.addEventListener('click', async () => {
        b.disabled = true; const t = b.textContent;
        try { const r = await post({ action: 'test_sub', id: +b.dataset.test }); b.textContent = r.ok ? '✅' : '⚠️ ' + (r.code || ''); }
        catch (e) { b.textContent = '⚠️'; }
        setTimeout(() => { b.textContent = t; b.disabled = false; }, 3000);
    }));
    document.querySelectorAll('[data-rm]').forEach(b => b.addEventListener('click', async () => {
        const r = await post({ action: 'remove_sub', id: +b.dataset.rm }); if (r.ok) b.closest('tr').remove();
    }));
})();
</script>
</body>
</html>
