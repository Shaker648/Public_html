<?php

require 'auth.php';
require 'config.php';

perm_require('page.incoming_cars');

$lang = $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar', 'en'])) $lang = 'ar';

$t = [
    'ar' => [
        'title'        => 'السيارات القادمة',
        'subtitle'     => 'لوحة تخطيط الشحنات القادمة',
        'dashboard'    => 'الرئيسية',
        'add_car'      => 'إضافة سيارة قادمة',
        'add_title'    => 'إضافة شحنة جديدة',
        'brand'        => 'الماركة',
        'model'        => 'الموديل',
        'trim'         => 'الفئة',
        'year1'        => 'السنة',
        'year2'        => 'سنة ثانية (اختياري)',
        'quantity'     => 'الكمية القادمة',
        'select_brand' => 'اختر الماركة',
        'select_model' => 'اختر الموديل',
        'select_trim'  => 'اختر الفئة',
        'none'         => 'بدون',
        'save'         => 'حفظ',
        'cancel'       => 'إلغاء',
        'empty'        => 'لا توجد سيارات قادمة بعد',
        'empty_sub'    => 'اضغط «إضافة سيارة قادمة» للبدء',
        'remaining'    => 'متبقّي بدون لون',
        'colored'      => 'الألوان المحددة',
        'assign_color' => 'تحديد لون',
        'add_color'    => 'إضافة',
        'all_assigned' => 'تم تحديد كل الألوان ✅',
        'delete'       => 'حذف',
        'confirm_del'  => 'حذف هذه الشحنة وكل ألوانها؟',
        'qty'          => 'الكمية',
        'move_up'      => 'تحريك لأعلى',
        'move_down'    => 'تحريك لأسفل',
        'drag_hint'    => 'اسحب لإعادة الترتيب',
        'drag_tip'     => '💡 امسك علامة ⠿ واسحب الشحنة لأي مكان لإعادة الترتيب — أو استخدم ▲ ▼',
        'order_saving' => 'جارٍ حفظ الترتيب…',
        'order_saved'  => '✓ تم حفظ الترتيب',
        'order_err'    => '⚠️ تعذّر حفظ الترتيب',
        'count_label'  => 'سيارة',
        'select_color' => 'اختر لوناً',
        'edit'         => 'تعديل الكمية',
        'receive'      => 'استلام الشحنة',
        'update'       => 'حفظ',
        'clr_q'        => 'ماذا تريد أن تفعل؟',
        'clr_sold'     => '✅ تم بيع السيارة — احذف اللون',
        'clr_recolor'  => '🔄 تغيير اللون فقط',
        'clr_cancel'   => 'إلغاء',
        'edit_q'           => 'تعديل كمية الشحنة',
        'edit_increase'    => '➕ زيادة العدد',
        'edit_decrease'    => '➖ تقليل العدد',
        'edit_inc_label'   => 'كم سيارة تريد إضافة؟',
        'edit_dec_label'   => 'اختر السيارات التي تريد حذفها',
        'edit_dec_uncolor' => 'سيارة بدون لون',
        'edit_dec_uncolors'=> 'سيارات بدون لون',
        'edit_confirm_dec' => 'تأكيد الحذف',
        'edit_cancel'      => 'إلغاء',
        'edit_add_cars'    => 'إضافة سيارات',
        'edit_current'     => 'الكمية الحالية',
        /* search */
        'search_placeholder' => 'ابحث بالماركة أو الموديل أو الفئة...',
        'search_results'     => 'نتائج البحث',
        'search_clear'       => 'مسح',
        'search_none'        => 'لا توجد نتائج مطابقة',
    ],
    'en' => [
        'title'        => 'Incoming Cars',
        'subtitle'     => 'Planning board for incoming shipments',
        'dashboard'    => 'Dashboard',
        'add_car'      => 'Add Incoming Car',
        'add_title'    => 'Add New Shipment',
        'brand'        => 'Brand',
        'model'        => 'Model',
        'trim'         => 'Trim',
        'year1'        => 'Year',
        'year2'        => 'Second Year (optional)',
        'quantity'     => 'Incoming Quantity',
        'select_brand' => 'Select Brand',
        'select_model' => 'Select Model',
        'select_trim'  => 'Select Trim',
        'none'         => 'None',
        'save'         => 'Save',
        'cancel'       => 'Cancel',
        'empty'        => 'No incoming cars yet',
        'empty_sub'    => 'Click "Add Incoming Car" to start',
        'remaining'    => 'remaining without color',
        'colored'      => 'Assigned colors',
        'assign_color' => 'Assign color',
        'add_color'    => 'Add',
        'all_assigned' => 'All colors assigned ✅',
        'delete'       => 'Delete',
        'confirm_del'  => 'Delete this shipment and all its colors?',
        'qty'          => 'Qty',
        'move_up'      => 'Move up',
        'move_down'    => 'Move down',
        'drag_hint'    => 'Drag to reorder',
        'drag_tip'     => '💡 Hold the ⠿ handle and drag a shipment anywhere to reorder — or use ▲ ▼',
        'order_saving' => 'Saving order…',
        'order_saved'  => '✓ Order saved',
        'order_err'    => '⚠️ Could not save the order',
        'count_label'  => 'cars',
        'select_color' => 'Select a color',
        'edit'         => 'Edit Quantity',
        'receive'      => 'Receive Shipment',
        'update'       => 'Save',
        'clr_q'        => 'What do you want to do?',
        'clr_sold'     => '✅ Car sold — remove color',
        'clr_recolor'  => '🔄 Change color only',
        'clr_cancel'   => 'Cancel',
        'edit_q'           => 'Edit Shipment Quantity',
        'edit_increase'    => '➕ Increase',
        'edit_decrease'    => '➖ Decrease',
        'edit_inc_label'   => 'How many cars to add?',
        'edit_dec_label'   => 'Select cars to remove',
        'edit_dec_uncolor' => 'uncolored car',
        'edit_dec_uncolors'=> 'uncolored cars',
        'edit_confirm_dec' => 'Confirm Removal',
        'edit_cancel'      => 'Cancel',
        'edit_add_cars'    => 'Add Cars',
        'edit_current'     => 'Current quantity',
        /* search */
        'search_placeholder' => 'Search by brand, model or trim...',
        'search_results'     => 'Search Results',
        'search_clear'       => 'Clear',
        'search_none'        => 'No matching shipments',
    ],
];

/* ══════ ACTIONS ══════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $brand = trim($_POST['brand']  ?? '');
        $model = trim($_POST['model']  ?? '');
        $trimN = trim($_POST['trim_name'] ?? '');
        $year1 = trim($_POST['year1']  ?? '');
        $year2 = trim($_POST['year2']  ?? '');
        $qty   = (int)($_POST['quantity'] ?? 0);
        if ($brand && $model && $trimN && $year1 && $qty > 0) {
            $mo = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM incoming_cars")->fetchColumn();
            $pdo->prepare("INSERT INTO incoming_cars(brand,model,trim_name,year1,year2,quantity,sort_order,created_by)VALUES(?,?,?,?,?,?,?,?)")
                ->execute([$brand,$model,$trimN,$year1,$year2!==''?$year2:null,$qty,$mo+1,$_SESSION['username']]);
        }
    }

    if ($action === 'edit_qty') {
        $id  = (int)($_POST['incoming_id'] ?? 0);
        $qty = (int)($_POST['quantity']    ?? 0);
        if ($id > 0 && $qty > 0) {
            $pdo->prepare("UPDATE incoming_cars SET quantity=? WHERE id=?")->execute([$qty,$id]);
        }
    }

    if ($action === 'increase_qty') {
        $id  = (int)($_POST['incoming_id'] ?? 0);
        $add = (int)($_POST['add_count']   ?? 0);
        if ($id > 0 && $add > 0) {
            $pdo->prepare("UPDATE incoming_cars SET quantity = quantity + ? WHERE id=?")->execute([$add, $id]);
        }
    }

    if ($action === 'decrease_qty') {
        $id          = (int)($_POST['incoming_id']   ?? 0);
        $removeColor = trim($_POST['remove_color']   ?? '');
        $removeCount = max(1,(int)($_POST['remove_count'] ?? 1));
        if ($id > 0) {
            if ($removeColor === '__uncolored__') {
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM incoming_colors WHERE incoming_id=?");
                $countStmt->execute([$id]);
                $assignedCount = (int)$countStmt->fetchColumn();
                $curStmt = $pdo->prepare("SELECT quantity FROM incoming_cars WHERE id=?");
                $curStmt->execute([$id]);
                $curQty = (int)$curStmt->fetchColumn();
                $newQty = max($assignedCount, $curQty - $removeCount);
                if ($newQty < $curQty) {
                    $pdo->prepare("UPDATE incoming_cars SET quantity=? WHERE id=?")->execute([$newQty, $id]);
                }
            } else {
                $rows = $pdo->prepare("SELECT id FROM incoming_colors WHERE incoming_id=? AND color=? ORDER BY id ASC LIMIT ?");
                $rows->bindValue(1, $id, PDO::PARAM_INT);
                $rows->bindValue(2, $removeColor, PDO::PARAM_STR);
                $rows->bindValue(3, $removeCount, PDO::PARAM_INT);
                $rows->execute();
                $ids = $rows->fetchAll(PDO::FETCH_COLUMN);
                $deleted = 0;
                foreach ($ids as $rid) {
                    $pdo->prepare("DELETE FROM incoming_colors WHERE id=?")->execute([$rid]);
                    $deleted++;
                }
                if ($deleted > 0) {
                    $curStmt = $pdo->prepare("SELECT quantity FROM incoming_cars WHERE id=?");
                    $curStmt->execute([$id]);
                    $curQty  = (int)$curStmt->fetchColumn();
                    $newQty  = max(0, $curQty - $deleted);
                    $pdo->prepare("UPDATE incoming_cars SET quantity=? WHERE id=?")->execute([$newQty, $id]);
                }
            }
        }
    }

    if ($action === 'add_color') {
        $id    = (int)($_POST['incoming_id'] ?? 0);
        $color = trim($_POST['color'] ?? '');
        if ($id > 0 && $color !== '') {
            $r = $pdo->prepare("SELECT quantity FROM incoming_cars WHERE id=?");
            $r->execute([$id]);
            $qty = (int)$r->fetchColumn();
            $u = $pdo->prepare("SELECT COUNT(*) FROM incoming_colors WHERE incoming_id=?");
            $u->execute([$id]);
            $used = (int)$u->fetchColumn();
            if ($used < $qty) {
                $pdo->prepare("INSERT INTO incoming_colors(incoming_id,color,sold)VALUES(?,?,0)")
                    ->execute([$id,$color]);
            }
        }
    }

    if ($action === 'mark_sold') {
        $id    = (int)($_POST['incoming_id'] ?? 0);
        $color = trim($_POST['color'] ?? '');
        $num   = max(1, (int)($_POST['num'] ?? 1));
        if ($id > 0 && $color !== '') {
            $rows = $pdo->prepare("SELECT id FROM incoming_colors WHERE incoming_id=? AND color=? ORDER BY id ASC LIMIT ?");
            $rows->bindValue(1, $id, PDO::PARAM_INT);
            $rows->bindValue(2, $color, PDO::PARAM_STR);
            $rows->bindValue(3, $num, PDO::PARAM_INT);
            $rows->execute();
            $deleted = 0;
            foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $rid) {
                $pdo->prepare("DELETE FROM incoming_colors WHERE id=?")->execute([$rid]);
                $deleted++;
            }
            if ($deleted > 0) {
                $pdo->prepare("UPDATE incoming_cars SET quantity = GREATEST(0, quantity - ?) WHERE id=?")->execute([$deleted, $id]);
            }
        }
    }

    if ($action === 'remove_color') {
        $id    = (int)($_POST['incoming_id'] ?? 0);
        $color = trim($_POST['color'] ?? '');
        $num   = max(1, (int)($_POST['num'] ?? 1));
        if ($id > 0 && $color !== '') {
            $rows = $pdo->prepare("SELECT id FROM incoming_colors WHERE incoming_id=? AND color=? ORDER BY id ASC LIMIT ?");
            $rows->bindValue(1, $id, PDO::PARAM_INT);
            $rows->bindValue(2, $color, PDO::PARAM_STR);
            $rows->bindValue(3, $num, PDO::PARAM_INT);
            $rows->execute();
            foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $rid) {
                $pdo->prepare("DELETE FROM incoming_colors WHERE id=?")->execute([$rid]);
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['incoming_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM incoming_cars WHERE id=?")->execute([$id]);
        }
    }

    if ($action === 'move') {
        $id  = (int)($_POST['incoming_id'] ?? 0);
        $dir = $_POST['dir'] ?? '';
        $cur = $pdo->prepare("SELECT id,sort_order FROM incoming_cars WHERE id=?");
        $cur->execute([$id]);
        $current = $cur->fetch(PDO::FETCH_ASSOC);
        if ($current) {
            $nbr = $dir === 'up'
                ? $pdo->prepare("SELECT id,sort_order FROM incoming_cars WHERE sort_order<? ORDER BY sort_order DESC LIMIT 1")
                : $pdo->prepare("SELECT id,sort_order FROM incoming_cars WHERE sort_order>? ORDER BY sort_order ASC LIMIT 1");
            $nbr->execute([$current['sort_order']]);
            $neighbor = $nbr->fetch(PDO::FETCH_ASSOC);
            if ($neighbor) {
                $swap = $pdo->prepare("UPDATE incoming_cars SET sort_order=? WHERE id=?");
                $swap->execute([$neighbor['sort_order'], $current['id']]);
                $swap->execute([$current['sort_order'], $neighbor['id']]);
            }
        }
    }

    /* Drag-and-drop reorder — exactly the same concept as ▲▼ (it only writes
       sort_order), except the whole new order is saved in one go instead of
       swapping one neighbour at a time. Answers with JSON, no page reload. */
    if ($action === 'reorder') {
        $ids = $_POST['ids'] ?? [];
        $ok  = false;
        if (is_array($ids) && !empty($ids)) {
            try {
                $pdo->beginTransaction();
                $upd = $pdo->prepare("UPDATE incoming_cars SET sort_order=? WHERE id=?");
                $pos = 10;
                foreach ($ids as $rid) {
                    $rid = (int)$rid;
                    if ($rid > 0) { $upd->execute([$pos, $rid]); $pos += 10; }
                }
                $pdo->commit();
                $ok = true;
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('incoming reorder failed: ' . $e->getMessage());
            }
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok]);
        exit;
    }

    $anchor = isset($_POST['incoming_id']) ? '#ship-'.(int)$_POST['incoming_id'] : '';
    header('Location: incoming_cars.php?lang='.$lang.$anchor);
    exit;
}

/* ══════ LOAD ══════ */
$brands    = $pdo->query("SELECT * FROM brands ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$colors    = $pdo->query("SELECT * FROM colors ORDER BY color_en")->fetchAll(PDO::FETCH_ASSOC);
$shipments = $pdo->query("SELECT * FROM incoming_cars ORDER BY sort_order ASC,id ASC")->fetchAll(PDO::FETCH_ASSOC);

$colorsByShipment = [];
foreach ($pdo->query("SELECT * FROM incoming_colors ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) as $ac) {
    $colorsByShipment[$ac['incoming_id']][] = $ac;
}

$colorName = [];
foreach ($colors as $c) {
    $colorName[$c['color_en']] = ($lang === 'ar') ? $c['color_ar'] : $c['color_en'];
}

$totalShipments = count($shipments);
$totalCars = 0;
foreach ($shipments as $s) $totalCars += (int)$s['quantity'];

/* Per-brand incoming totals (for the clickable breakdown popup) */
$brandTotals = [];
foreach ($shipments as $s) {
    $bn = $s['brand'];
    if (!isset($brandTotals[$bn])) $brandTotals[$bn] = ['cars' => 0, 'shipments' => 0];
    $brandTotals[$bn]['cars']      += (int)$s['quantity'];
    $brandTotals[$bn]['shipments'] += 1;
}
uasort($brandTotals, function($a, $b){ return $b['cars'] <=> $a['cars']; }); // highest count first
$maxBrandCars = 0;
foreach ($brandTotals as $bt) { if ($bt['cars'] > $maxBrandCars) $maxBrandCars = $bt['cars']; }

$cy = (int)date('Y');

/* ══════ MOTIVATIONAL MESSAGES ══════ */
$motivations = $lang === 'ar' ? [
    'القائد الحقيقي لا ينتظر الفرصة — هو يصنعها بيديه ويُثبت للعالم أنه جاء ليبقى.',
    'كل سيارة تدخل المستودع هي خطوة نحو مستقبل أكبر — استمر وابنِ ما يستحق أن يُروى.',
    'الأرقام الكبيرة تبدأ من قرارات صغيرة جريئة — والجرأة هي رأس المال الحقيقي للقادة.',
    'النجاح لا يأتي لمن ينتظره — بل لمن ينزل الميدان كل يوم ويُغيّر قواعد اللعبة.',
    'من أدار مستودعه بإتقان اليوم، بنى إمبراطورية تُحكى عنها الأجيال القادمة.',
    'التخطيط هو روح الأعمال — ومن يُخطّط للشحنة القادمة يُخطّط لمستقبل لا حدود له.',
    'الفارق بين التاجر العادي والقائد الاستثنائي هو تفاصيل يراها الواحد ويتجاهلها الآخر.',
    'كل لون تُحدّده، وكل شحنة تُديرها، هي دليل على أنك تبني شيئاً أكبر من مجرد تجارة.',
    'الوقت يمضي بالنسبة للجميع — لكن فقط الأقوياء يتركون أثراً لا يُمحى.',
    'منافسيك ينامون الآن وأنت تُخطّط — هذا هو الفارق بين من يصنع السوق ومن يتبعه.',
    'الإدارة الذكية للمخزون هي لغة المال التي يتقنها القادة ولا يفهمها الضعفاء.',
    'السيارة القادمة اليوم هي مبيعة الغد — والغد دائماً ينتمي لمن استعد له أكثر من غيره.',
    'لا تعمل من أجل الشهر — اعمل من أجل الإرث الذي ستتركه ويظل يُشهد له بعد سنين.',
    'التجار العظماء لا يخافون الأرقام — هم يُعبدون الطريق لها بالتخطيط والصبر والجرأة.',
    'كل شحنة جديدة هي رهان على نفسك — ولا يُربح هذا الرهان إلا من يؤمن بما يبني.',
    'العالم يتذكر من شيّد وبنى — لا من جلس وانتظر أن تأتيه الفرص على طبق من ذهب.',
    'الصبر ليس ضعفاً — الصبر هو استراتيجية من يعرف أن المعركة الحقيقية تُكسب بالأعصاب.',
    'إدارة الأعمال بدون بيانات مثل قيادة السيارة بعيون مغمضة — أنت تعمل بالبيانات الصحيحة.',
    'التميز ليس ما تفعله في أوقات النجاح — بل ما تفعله حين تكون الأمور أصعب من المتوقع.',
    'القادة الحقيقيون يرون الفرصة في كل تفصيلة صغيرة — وهذه التفاصيل هي التي تصنع الفارق.',
    'حين تُدير مستودعك بهذا الإتقان، فأنت لا تبيع سيارات — أنت تبني مستقبلاً من الفولاذ.',
    'المكتب هو ميدانك والبيانات هي سلاحك — من أتقن استخدام سلاحه لا يُهزم أبداً.',
    'لا تقِس نجاحك بعدد السيارات فقط — قِسه بعدد القرارات الصحيحة التي أسقطت كل الشك.',
    'الجدية في العمل ليست ثقلاً — هي الوقود الذي يجعل كل شيء ممكناً حين يعتقد الآخرون أنه صعب.',
    'كلما زاد الضغط، زاد التألق — الألماس نفسه يُصنع تحت ضغط لا يتحمله إلا الأقوياء.',
] : [
    'A true leader does not wait for opportunity — they create it with their own hands every single day.',
    'Every car in this inventory is a step toward something bigger — keep building what is worth telling.',
    'Great numbers always start from bold small decisions — courage is the real capital of leaders.',
    'Success belongs to those who show up every day and change the rules of the game from within.',
    'The difference between an average dealer and an exceptional leader is the details only one of them sees.',
    'Planning is the soul of business — those who plan today build empires that outlast generations.',
    'Smart inventory management is the language of money that leaders master and followers ignore.',
    'Every color you assign and every shipment you manage proves you are building something beyond trade.',
    'Time passes for everyone — only the strong leave a mark that cannot be erased or forgotten.',
    'Your competitors are sleeping while you are planning — that is the gap between market makers and followers.',
    'The car arriving today is the sale of tomorrow — and tomorrow always belongs to those most prepared.',
    'Do not work for this month — work for the legacy you leave that will speak for you for years to come.',
    'Great merchants do not fear numbers — they pave the way for them with planning, patience, and courage.',
    'Every new shipment is a bet on yourself — and only those who believe in what they build win that bet.',
    'The world remembers builders — not those who sat and waited for opportunity to knock.',
    'Patience is not weakness — patience is the strategy of those who know real battles are won with composure.',
    'Managing business without data is like driving blind — you are working with the right intelligence.',
    'Excellence is not what you do in times of success — it is what you do when things are harder than expected.',
    'Real leaders see opportunity in every small detail — and it is those details that make all the difference.',
    'Pressure does not break the strong — it reveals them. Diamonds are made under the weight others cannot bear.',
];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $lang === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#0f172a">
<title><?= $t[$lang]['title'] ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

:root {
    --green:#22c55e; --purple:#9333ea; --blue:#2563eb; --amber:#f59e0b; --red:#ef4444;
    --bg-card:rgba(15,23,42,.90); --border:rgba(255,255,255,.08);
    --text:#f1f5f9; --muted:#94a3b8; --muted-d:#64748b;
}
html[lang="ar"] body { font-family:'Cairo','Segoe UI',Tahoma,sans-serif; }
html[lang="en"] body { font-family:'Inter','Segoe UI',Tahoma,sans-serif; }

body {
    background:linear-gradient(135deg,#020617,#0f172a);
    color:var(--text); min-height:100vh; padding-bottom:60px;
}
.container { max-width:1400px; margin:auto; padding:20px; }

/* ══════════════════════════════════
   MOTIVATIONAL BANNER
══════════════════════════════════ */
.motiv-banner {
    position:relative; overflow:hidden;
    background:linear-gradient(120deg,rgba(15,23,42,.96),rgba(8,13,28,.98));
    border:1px solid rgba(147,51,234,.22);
    border-radius:24px;
    padding:22px 28px 22px 24px;
    margin-bottom:18px;
    box-shadow:0 0 0 1px rgba(147,51,234,.06), 0 8px 32px rgba(0,0,0,.4);
}
/* animated purple glow edge */
.motiv-banner::before {
    content:'';
    position:absolute; top:0; bottom:0; width:4px;
    background:linear-gradient(180deg,var(--purple),var(--green),var(--purple));
    background-size:100% 200%;
    animation:glowEdge 3s ease-in-out infinite;
    border-radius:4px 0 0 4px;
}
html[dir="rtl"] .motiv-banner::before { left:auto; right:0; border-radius:0 4px 4px 0; }
html[dir="ltr"] .motiv-banner::before { left:0; }
@keyframes glowEdge {
    0%,100% { background-position:0% 0%; }
    50%      { background-position:0% 100%; }
}
/* stars particle bg */
.motiv-banner::after {
    content:'✦ ✧ ✦';
    position:absolute; top:10px;
    font-size:10px; color:rgba(147,51,234,.18);
    pointer-events:none; letter-spacing:16px;
}
html[dir="rtl"] .motiv-banner::after { right:24px; }
html[dir="ltr"] .motiv-banner::after { left:24px; }

.motiv-inner {
    display:flex; align-items:center; gap:18px;
}
.motiv-icon {
    font-size:36px; flex-shrink:0;
    filter:drop-shadow(0 0 10px rgba(147,51,234,.5));
    animation:iconRock 4s ease-in-out infinite;
}
@keyframes iconRock {
    0%,100% { transform:rotate(-4deg) scale(1); }
    50%      { transform:rotate(4deg) scale(1.08); }
}
.motiv-text-wrap { flex:1; min-width:0; }
.motiv-label {
    font-size:10.5px; font-weight:800; letter-spacing:.12em;
    text-transform:uppercase; color:var(--purple); margin-bottom:6px;
    display:flex; align-items:center; gap:6px;
}
.motiv-label::after { content:''; flex:1; height:1px; background:rgba(147,51,234,.2); }

/* THE MESSAGE — big and visible */
.motiv-msg {
    font-size:17px;
    font-weight:800;
    line-height:1.65;
    color:#e2e8f0;
    transition:opacity .5s ease, transform .5s ease;
    min-height:2.8em; /* prevent layout jump */
}
html[lang="ar"] .motiv-msg {
    font-size:18px; /* slightly bigger for Arabic */
    line-height:1.75;
}
.motiv-msg.fade-out { opacity:0; transform:translateY(-6px); }
.motiv-msg.fade-in  { opacity:1; transform:translateY(0); }

.motiv-dots {
    display:flex; gap:5px; margin-top:12px;
}
.motiv-dot {
    width:6px; height:6px; border-radius:50%;
    background:rgba(147,51,234,.25); transition:background .3s;
    cursor:pointer;
}
.motiv-dot.active { background:var(--purple); }

/* ══════════════════════════════════
   SEARCH BOX
══════════════════════════════════ */
.search-card {
    background:var(--bg-card);
    border:1px solid var(--border);
    border-radius:22px;
    padding:16px 20px;
    margin-bottom:18px;
    backdrop-filter:blur(16px);
    box-shadow:0 4px 20px rgba(0,0,0,.3);
}
.search-inner {
    display:flex; align-items:center; gap:12px;
}
.search-icon {
    font-size:20px; flex-shrink:0; color:var(--muted);
}
.search-input {
    flex:1; height:48px;
    background:rgba(0,0,0,.25);
    border:1px solid rgba(255,255,255,.08);
    border-radius:14px;
    color:white;
    padding:0 16px;
    font-size:15px;
    font-family:inherit;
    outline:none;
    transition:border-color .2s, box-shadow .2s;
    -webkit-appearance:none;
    appearance:none;
    width:100%;
}
.search-input::placeholder { color:var(--muted-d); }
.search-input:focus {
    border-color:rgba(147,51,234,.5);
    box-shadow:0 0 0 3px rgba(147,51,234,.12);
}
.search-clear-btn {
    height:40px; padding:0 16px; border:1px solid var(--border); border-radius:11px;
    background:#111827; color:var(--muted); font-size:13px; font-weight:700;
    cursor:pointer; font-family:inherit; white-space:nowrap; transition:.2s;
    display:none; align-items:center; gap:6px;
}
.search-clear-btn:hover { color:white; border-color:var(--muted-d); }
.search-clear-btn.visible { display:flex; }

/* Search result state */
.search-empty-msg {
    display:none; text-align:center; padding:40px 20px;
    color:var(--muted-d); font-size:15px; font-weight:600;
}
.search-empty-msg.visible { display:block; }

/* Highlight matching text */
.ship-card mark {
    background:rgba(147,51,234,.28);
    color:#e2e8f0;
    border-radius:3px;
    padding:0 2px;
}

/* ══════════════════════════════════
   HEADER (unchanged)
══════════════════════════════════ */
.header {
    background:var(--bg-card); border:1px solid var(--border); border-radius:28px;
    padding:24px 28px; margin-bottom:18px; backdrop-filter:blur(20px);
    box-shadow:0 20px 50px rgba(0,0,0,.35);
}
.header-top { display:flex; justify-content:space-between; align-items:center; gap:20px; flex-wrap:wrap; }
.page-title {
    font-size:32px; font-weight:900;
    background:linear-gradient(90deg,var(--green),#86efac);
    -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text;
}
.page-subtitle { margin-top:6px; font-size:14px; color:var(--muted); }
.header-actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
.action-btn {
    text-decoration:none; padding:11px 18px; border-radius:14px; font-weight:700;
    font-size:14px; color:white; transition:transform .2s,box-shadow .2s;
    display:flex; align-items:center; gap:6px; border:none; cursor:pointer; font-family:inherit;
}
.action-btn:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(0,0,0,.3); }
.dashboard-btn { background:var(--blue); }
.add-btn { background:linear-gradient(90deg,var(--green),var(--purple)); font-size:15px; padding:13px 22px; }

.lang-switch { display:flex; gap:8px; margin-top:18px; }
.lang-btn {
    text-decoration:none; padding:9px 16px; border-radius:11px; background:#111827;
    color:var(--muted); font-weight:700; font-size:13px; border:1px solid var(--border);
}
.lang-active { background:var(--purple)!important; color:white!important; border-color:transparent; }

.count-pill {
    background:rgba(147,51,234,.15); color:#c084fc; padding:6px 14px; border-radius:50px;
    font-size:13px; font-weight:800; border:1px solid rgba(147,51,234,.25);
}

/* ══════════════════════════════════
   CLICKABLE COUNT PILL + BRAND BREAKDOWN MODAL
══════════════════════════════════ */
.count-pill-btn {
    cursor:pointer; font-family:inherit; display:inline-flex; align-items:center; gap:7px;
    transition:transform .2s, box-shadow .25s, background .2s, border-color .2s;
    position:relative; overflow:hidden;
}
.count-pill-btn::before {
    content:''; position:absolute; inset:0;
    background:linear-gradient(120deg,transparent 30%,rgba(192,132,252,.25),transparent 70%);
    transform:translateX(-120%); transition:transform .6s;
}
.count-pill-btn:hover {
    transform:translateY(-2px); background:rgba(147,51,234,.25);
    border-color:rgba(147,51,234,.5); box-shadow:0 6px 22px rgba(147,51,234,.3);
}
.count-pill-btn:hover::before { transform:translateX(120%); }
.count-pill-btn:active { transform:translateY(0) scale(.97); }
.count-pill-arrow { font-size:11px; opacity:.8; transition:transform .3s; }
.count-pill-btn:hover .count-pill-arrow { transform:translateY(2px); }

/* Modal overlay */
.bd-overlay {
    position:fixed; inset:0; z-index:6000;
    display:flex; align-items:flex-start; justify-content:center;
    padding:60px 16px 30px;
    background:rgba(2,6,23,.78); backdrop-filter:blur(10px);
    opacity:0; pointer-events:none; transition:opacity .3s;
    overflow-y:auto;
}
.bd-overlay.open { opacity:1; pointer-events:auto; }

.bd-box {
    background:linear-gradient(180deg,#101c33,#0a1322);
    border:1px solid rgba(147,51,234,.25); border-radius:26px;
    width:100%; max-width:460px;
    box-shadow:0 30px 80px rgba(0,0,0,.65), 0 0 0 1px rgba(147,51,234,.08);
    transform:translateY(30px) scale(.94); opacity:0;
    transition:transform .4s cubic-bezier(.2,1.3,.35,1), opacity .35s;
    overflow:hidden;
}
.bd-overlay.open .bd-box { transform:translateY(0) scale(1); opacity:1; }

/* Header with animated gradient */
.bd-header {
    position:relative; padding:24px 26px 20px; overflow:hidden;
    background:linear-gradient(120deg,rgba(147,51,234,.18),rgba(37,99,235,.10));
    border-bottom:1px solid rgba(255,255,255,.07);
}
.bd-header::after {
    content:''; position:absolute; top:-50%; left:-50%; width:200%; height:200%;
    background:radial-gradient(circle,rgba(147,51,234,.12),transparent 60%);
    animation:bdGlow 6s ease-in-out infinite;
}
@keyframes bdGlow { 0%,100%{transform:translate(0,0);} 50%{transform:translate(15%,15%);} }
.bd-title { font-size:20px; font-weight:900; color:#f1f5f9; display:flex; align-items:center; gap:10px; position:relative; z-index:1; }
.bd-sub { font-size:13px; color:var(--muted); margin-top:6px; position:relative; z-index:1; }
.bd-total-chip {
    position:relative; z-index:1; margin-top:14px; display:inline-flex; align-items:center; gap:8px;
    background:rgba(34,197,94,.15); border:1px solid rgba(34,197,94,.3);
    color:#86efac; font-weight:800; font-size:14px; padding:7px 16px; border-radius:50px;
}
.bd-close {
    position:absolute; top:18px; inset-inline-end:18px; z-index:2;
    width:34px; height:34px; border:none; border-radius:10px; cursor:pointer;
    background:rgba(255,255,255,.08); color:#cbd5e1; font-size:18px; font-family:inherit;
    display:flex; align-items:center; justify-content:center; transition:.2s;
}
.bd-close:hover { background:rgba(239,68,68,.2); color:#fca5a5; transform:rotate(90deg); }

/* Brand rows */
.bd-list { padding:14px 18px 22px; display:flex; flex-direction:column; gap:10px; max-height:55vh; overflow-y:auto; }
.bd-row {
    background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.06);
    border-radius:16px; padding:14px 16px;
    opacity:0; transform:translateY(14px);
    animation:bdRowIn .45s cubic-bezier(.2,1.2,.4,1) forwards;
}
@keyframes bdRowIn { to { opacity:1; transform:translateY(0); } }
.bd-row-top { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:10px; }
.bd-brand-name { font-size:16px; font-weight:800; color:#f1f5f9; display:flex; align-items:center; gap:9px; }
.bd-brand-rank {
    width:24px; height:24px; border-radius:8px; flex-shrink:0;
    display:inline-flex; align-items:center; justify-content:center;
    font-size:12px; font-weight:900; background:rgba(147,51,234,.2); color:#c084fc;
}
.bd-row:nth-child(1) .bd-brand-rank { background:rgba(245,158,11,.22); color:#fbbf24; }
.bd-row:nth-child(2) .bd-brand-rank { background:rgba(148,163,184,.22); color:#e2e8f0; }
.bd-row:nth-child(3) .bd-brand-rank { background:rgba(180,83,9,.25); color:#fb923c; }
.bd-brand-count { font-size:15px; font-weight:900; color:#22c55e; white-space:nowrap; }
.bd-brand-count small { color:var(--muted-d); font-weight:700; font-size:11px; }
.bd-bar-track { height:9px; background:rgba(0,0,0,.3); border-radius:50px; overflow:hidden; }
.bd-bar-fill {
    height:100%; border-radius:50px; width:0;
    background:linear-gradient(90deg,var(--purple),var(--green));
    transition:width 1s cubic-bezier(.2,1,.3,1);
    box-shadow:0 0 12px rgba(147,51,234,.4);
}
.bd-row-sub { margin-top:7px; font-size:11.5px; color:var(--muted-d); font-weight:600; }

/* ══════════════════════════════════
   ADD FORM (unchanged)
══════════════════════════════════ */
.add-card {
    background:var(--bg-card); border:1px solid rgba(34,197,94,.25); border-radius:24px;
    padding:24px; margin-bottom:22px; display:none; animation:slideDown .3s ease;
}
.add-card.open { display:block; }
@keyframes slideDown { from{opacity:0;transform:translateY(-10px);} to{opacity:1;transform:none;} }
.add-card h3 { font-size:20px; font-weight:800; margin-bottom:18px; color:var(--green); }
.form-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:18px; }
.form-group { display:flex; flex-direction:column; gap:7px; }
.form-group label { font-size:13px; font-weight:700; color:#cbd5e1; }
input, select {
    width:100%; height:52px; background:#0d1526; border:1px solid rgba(255,255,255,.08);
    outline:none; color:white; padding:0 14px; border-radius:13px; font-size:15px;
    font-family:inherit; transition:border-color .2s,box-shadow .2s; -webkit-appearance:none; appearance:none;
}
input:focus, select:focus { border-color:var(--purple); box-shadow:0 0 0 3px rgba(147,51,234,.18); }
select option { background:#0d1526; }
.form-actions { display:flex; gap:12px; }
.btn-save {
    height:52px; padding:0 28px; border:none; border-radius:13px; font-weight:800; font-size:15px;
    cursor:pointer; background:linear-gradient(90deg,var(--green),#16a34a); color:#002b14; font-family:inherit;
    transition:transform .2s;
}
.btn-save:hover { transform:translateY(-2px); }
.btn-cancel {
    height:52px; padding:0 22px; border:1px solid var(--border); border-radius:13px; font-weight:700;
    font-size:14px; cursor:pointer; background:#111827; color:var(--muted); font-family:inherit;
}

/* ══════════════════════════════════
   SHIP CARDS (unchanged)
══════════════════════════════════ */
.ship-list { display:flex; flex-direction:column; gap:16px; }
.ship-card {
    background:var(--bg-card); border:1px solid var(--border); border-radius:22px;
    padding:22px 24px; box-shadow:0 4px 24px rgba(0,0,0,.3);
    transition:border-color .2s;
}
.ship-card.search-hidden { display:none; }
.ship-head { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; flex-wrap:wrap; }
.ship-title { font-size:24px; font-weight:900; color:#f1f5f9; line-height:1.25; letter-spacing:-.01em; }
.ship-meta {
    font-size:15px; color:var(--muted); margin-top:8px;
    display:flex; gap:12px; flex-wrap:wrap; align-items:center;
}
.ship-meta strong { color:#e2e8f0; }
.ship-meta .year-chip {
    background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1);
    padding:4px 13px; border-radius:9px; color:var(--muted); font-weight:700; font-size:14px;
}
.ship-controls { display:flex; gap:6px; align-items:center; }

/* ══ Drag-and-drop reordering (same sort_order concept as ▲▼) ══ */
.drag-tip {
    background:rgba(147,51,234,.08); border:1px solid rgba(147,51,234,.22);
    border-radius:14px; padding:10px 16px; margin-bottom:14px;
    font-size:12.5px; font-weight:600; color:#c4b5fd; line-height:1.6;
}
.drag-handle {
    cursor:grab; touch-action:none; user-select:none;
    font-size:17px; line-height:1; letter-spacing:-1px;
    color:#a78bfa;
}
.drag-handle:active { cursor:grabbing; }
.drag-handle:hover { background:rgba(147,51,234,.22) !important; color:#fff; }

/* the card being dragged floats above everything */
.ship-card.dragging {
    box-shadow:0 24px 60px rgba(0,0,0,.6), 0 0 0 2px rgba(147,51,234,.55);
    opacity:.97; transform:scale(1.015); cursor:grabbing;
}
/* the gap that shows where it will land */
.drag-placeholder {
    border:2px dashed rgba(147,51,234,.5);
    background:rgba(147,51,234,.06);
    border-radius:20px; margin:0;
}
/* while dragging, dim the rest a touch so the target gap reads clearly */
.ship-list.is-dragging .ship-card:not(.dragging) { opacity:.62; }

/* saving toast */
.order-toast {
    position:fixed; inset-inline-end:20px; bottom:22px; z-index:2000;
    padding:12px 20px; border-radius:14px; font-size:13.5px; font-weight:800;
    background:rgba(15,23,42,.97); border:1px solid rgba(147,51,234,.4);
    color:#e9d5ff; box-shadow:0 12px 40px rgba(0,0,0,.55);
    opacity:0; transform:translateY(12px); pointer-events:none;
    transition:opacity .25s, transform .25s;
}
.order-toast.show { opacity:1; transform:translateY(0); }
.order-toast.ok  { border-color:rgba(34,197,94,.5);  color:#86efac; }
.order-toast.err { border-color:rgba(239,68,68,.5);  color:#fca5a5; }
.icon-btn {
    width:38px; height:38px; border:1px solid var(--border); border-radius:11px; background:#111827;
    color:var(--muted); cursor:pointer; font-size:16px; display:flex; align-items:center;
    justify-content:center; transition:.2s; font-family:inherit;
}
.icon-btn:hover { color:white; border-color:var(--muted-d); }
.icon-btn.danger:hover { color:var(--red); border-color:var(--red); background:rgba(239,68,68,.1); }
.icon-btn.edit-btn:hover { color:var(--amber); border-color:var(--amber); background:rgba(245,158,11,.1); }
.icon-btn:disabled { opacity:.3; cursor:not-allowed; }
a.icon-btn { text-decoration:none; }
.icon-btn.receive-btn {
    color:var(--green); border-color:rgba(34,197,94,.35); background:rgba(34,197,94,.08);
}
.icon-btn.receive-btn:hover {
    color:#fff; border-color:var(--green); background:rgba(34,197,94,.25);
}

.progress-wrap { margin:16px 0 14px; }
.progress-bar { height:10px; background:#0d1526; border-radius:50px; overflow:hidden; border:1px solid rgba(255,255,255,.05); }
.progress-fill { height:100%; background:linear-gradient(90deg,var(--green),var(--purple)); border-radius:50px; transition:width .4s; }
.progress-text { display:flex; justify-content:space-between; margin-top:8px; font-size:13px; }
.remain-num { color:var(--amber); font-weight:800; }
.done-num   { color:var(--green); font-weight:800; }

.color-tags { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
.color-tag {
    display:inline-flex; align-items:center; gap:7px; background:rgba(34,197,94,.10);
    border:1px solid rgba(34,197,94,.22); color:#bbf7d0; padding:6px 10px 6px 12px;
    border-radius:50px; font-size:13px; font-weight:700; cursor:pointer;
    transition:background .2s, border-color .2s; font-family:inherit; outline:none;
}
.color-tag:hover { background:rgba(34,197,94,.18); border-color:rgba(34,197,94,.5); }
.color-swatch { width:14px; height:14px; border-radius:50%; border:1px solid rgba(255,255,255,.3); flex-shrink:0; }
.color-count {
    background:rgba(0,0,0,.3); border-radius:50px; padding:1px 7px;
    font-size:11px; font-weight:900; color:#22c55e; margin-inline-start:2px;
}
.all-done { color:var(--green); font-weight:700; font-size:14px; padding:6px 0; }

.assign-row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.assign-row select { max-width:260px; height:46px; }
.btn-add-color {
    height:46px; padding:0 20px; border:none; border-radius:12px; font-weight:800; font-size:14px;
    cursor:pointer; background:var(--purple); color:white; font-family:inherit; transition:transform .2s;
    display:flex; align-items:center; gap:6px;
}
.btn-add-color:hover { transform:translateY(-2px); }

/* Color dialog */
.clr-dialog {
    position:fixed; inset:0; z-index:5000;
    display:flex; align-items:center; justify-content:center;
    background:rgba(2,6,23,.85); backdrop-filter:blur(8px);
}
.clr-dialog.hidden { display:none; }
.clr-box {
    background:linear-gradient(180deg,#0f1a2e,#0a1220);
    border:1px solid rgba(255,255,255,.1); border-radius:24px;
    padding:28px 24px; max-width:340px; width:calc(100% - 40px);
    text-align:center; box-shadow:0 24px 60px rgba(0,0,0,.6);
    animation:popIn .25s cubic-bezier(.2,1.4,.4,1);
}
@keyframes popIn { from{transform:scale(.85);opacity:0;} to{transform:scale(1);opacity:1;} }
.clr-icon { font-size:40px; margin-bottom:10px; }
.clr-q { font-size:16px; font-weight:800; color:#f1f5f9; margin-bottom:6px; }
.clr-name { font-size:13px; color:var(--muted); margin-bottom:20px; }
.clr-btns { display:flex; flex-direction:column; gap:10px; }
.clr-btn {
    height:50px; border:none; border-radius:14px; font-weight:800; font-size:15px;
    cursor:pointer; font-family:inherit; transition:transform .2s; width:100%;
}
.clr-btn:hover { transform:translateY(-2px); }
.clr-btn-sold    { background:linear-gradient(90deg,#dc2626,#ef4444); color:white; }
.clr-btn-recolor { background:rgba(147,51,234,.2); color:#c084fc; border:1px solid rgba(147,51,234,.3); }
.clr-btn-cancel  { background:#111827; color:var(--muted); border:1px solid var(--border); }
.clr-num-grid { display:flex; flex-wrap:wrap; gap:8px; justify-content:center; margin-bottom:20px; }
.clr-num-btn {
    width:48px; height:48px; border:1px solid var(--border); border-radius:12px;
    background:#0d1526; color:var(--text); font-weight:800; font-size:16px;
    cursor:pointer; font-family:inherit; transition:.2s;
}
.clr-num-btn:hover { border-color:var(--purple); }
.clr-num-btn.active { background:var(--purple); color:white; border-color:var(--purple); }

/* Edit dialog */
.edit-dialog {
    position:fixed; inset:0; z-index:5100;
    display:flex; align-items:center; justify-content:center;
    background:rgba(2,6,23,.88); backdrop-filter:blur(10px);
}
.edit-dialog.hidden { display:none; }
.edit-box {
    background:linear-gradient(180deg,#0f1a2e,#0a1220);
    border:1px solid rgba(255,255,255,.1); border-radius:24px;
    padding:28px 24px; max-width:380px; width:calc(100% - 32px);
    box-shadow:0 24px 60px rgba(0,0,0,.6);
    animation:popIn .25s cubic-bezier(.2,1.4,.4,1);
}
.edit-dialog-title { font-size:17px; font-weight:900; color:#f1f5f9; text-align:center; margin-bottom:4px; }
.edit-dialog-car   { font-size:13px; color:var(--muted); text-align:center; margin-bottom:6px; }
.edit-current-qty  { text-align:center; font-size:13px; color:var(--muted-d); margin-bottom:20px; }
.edit-current-qty strong { color:var(--amber); font-size:16px; }
.edit-step { display:none; }
.edit-step.active { display:block; }
.edit-choice-btns { display:flex; gap:12px; }
.edit-choice-btn {
    flex:1; height:64px; border:1px solid var(--border); border-radius:16px;
    background:#0d1526; color:var(--text); font-weight:800; font-size:15px;
    cursor:pointer; font-family:inherit; transition:.2s;
    display:flex; flex-direction:column; align-items:center; justify-content:center; gap:4px;
}
.edit-choice-btn .cb-icon { font-size:22px; }
.edit-choice-btn.inc:hover { border-color:var(--green); background:rgba(34,197,94,.08); color:var(--green); }
.edit-choice-btn.dec:hover { border-color:var(--red);   background:rgba(239,68,68,.08);  color:var(--red); }
.inc-num-grid { display:flex; flex-wrap:wrap; gap:8px; justify-content:center; margin:16px 0; }
.inc-num-btn {
    width:52px; height:52px; border:1px solid var(--border); border-radius:13px;
    background:#0d1526; color:var(--text); font-weight:800; font-size:17px;
    cursor:pointer; font-family:inherit; transition:.2s;
}
.inc-num-btn:hover { border-color:var(--green); }
.inc-num-btn.active { background:var(--green); color:#002b14; border-color:var(--green); }
.inc-custom-row { display:flex; gap:10px; align-items:center; margin-bottom:16px; }
.inc-custom-row input {
    flex:1; height:46px; background:#0d1526; border:1px solid rgba(255,255,255,.1);
    border-radius:12px; color:white; padding:0 14px; font-size:15px; font-family:inherit; outline:none;
}
.inc-custom-row input:focus { border-color:var(--green); }
.dec-items { display:flex; flex-direction:column; gap:8px; margin:14px 0; }
.dec-item {
    display:flex; align-items:center; justify-content:space-between;
    background:#0a1220; border:1px solid rgba(255,255,255,.07);
    border-radius:13px; padding:12px 16px; gap:12px;
}
.dec-item-left { display:flex; align-items:center; gap:10px; }
.dec-swatch { width:16px; height:16px; border-radius:50%; border:1px solid rgba(255,255,255,.25); flex-shrink:0; }
.dec-item-name { font-size:14px; font-weight:700; }
.dec-item-sub  { font-size:12px; color:var(--muted-d); margin-top:2px; }
.dec-num-row { display:flex; gap:6px; }
.dec-n-btn {
    width:36px; height:36px; border:1px solid var(--border); border-radius:9px;
    background:#0d1526; color:var(--text); font-weight:800; font-size:14px;
    cursor:pointer; font-family:inherit; transition:.2s;
}
.dec-n-btn:hover { border-color:var(--red); color:var(--red); background:rgba(239,68,68,.08); }
.dec-n-btn.active { background:var(--red); color:white; border-color:var(--red); }
.uncolored-item { background:rgba(245,158,11,.06); border:1px solid rgba(245,158,11,.18); }
.uncolored-item .dec-item-name { color:var(--amber); }
.edit-actions { display:flex; gap:10px; margin-top:18px; }
.edit-btn-confirm {
    flex:1; height:50px; border:none; border-radius:14px; font-weight:800; font-size:15px;
    cursor:pointer; font-family:inherit; transition:transform .2s;
    background:linear-gradient(90deg,var(--green),#16a34a); color:#002b14;
}
.edit-btn-confirm:hover { transform:translateY(-2px); }
.edit-btn-confirm.red-confirm { background:linear-gradient(90deg,#b91c1c,var(--red)); color:white; }
.edit-btn-back {
    height:50px; padding:0 18px; border:1px solid var(--border); border-radius:14px;
    font-weight:700; font-size:14px; cursor:pointer; font-family:inherit;
    background:#111827; color:var(--muted);
}
.edit-btn-cancel-all {
    height:50px; padding:0 18px; border:1px solid var(--border); border-radius:14px;
    font-weight:700; font-size:14px; cursor:pointer; font-family:inherit;
    background:#111827; color:var(--muted);
}

/* Empty state */
.empty-state {
    background:var(--bg-card); border:1px dashed rgba(255,255,255,.12); border-radius:24px;
    padding:70px 30px; text-align:center;
}
.empty-state .e-icon { font-size:56px; margin-bottom:14px; }
.empty-state h2 { font-size:22px; font-weight:800; margin-bottom:8px; }
.empty-state p { color:var(--muted-d); font-size:15px; }

@media (max-width:768px) {
    .form-grid { grid-template-columns:1fr; }
    .page-title { font-size:26px; }
    .ship-head { flex-direction:column; }
    .assign-row select { max-width:100%; flex:1; }
    .edit-choice-btns { flex-direction:column; }
    .ship-title { font-size:20px; }
    .motiv-msg { font-size:15px; }
    html[lang="ar"] .motiv-msg { font-size:16px; }
}
@media (max-width:480px) {
    .motiv-inner { flex-direction:column; text-align:center; }
    .motiv-label::after { display:none; }
    .motiv-dots { justify-content:center; }
}
</style>
</head>
<body>
<div class="container">

<!-- ══════════════════════════════════
     MOTIVATIONAL BANNER
══════════════════════════════════ -->
<div class="motiv-banner" id="motivBanner">
    <div class="motiv-inner">
        <div class="motiv-icon" id="motivIcon">🏆</div>
        <div class="motiv-text-wrap">
            <div class="motiv-label">
                <?= $lang === 'ar' ? '✦ رسالة يومية للقائد' : '✦ Daily Message for the Leader' ?>
            </div>
            <div class="motiv-msg fade-in" id="motivMsg"></div>
            <div class="motiv-dots" id="motivDots"></div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════
     HEADER
══════════════════════════════════ -->
<div class="header">
    <div class="header-top">
        <div>
            <div class="page-title">🚚 <?= $t[$lang]['title'] ?></div>
            <div class="page-subtitle"><?= $t[$lang]['subtitle'] ?></div>
        </div>
        <div class="header-actions">
            <?php if ($totalCars > 0): ?>
                <button type="button" class="count-pill count-pill-btn" onclick="openBrandBreakdown()" title="<?= $lang==='ar'?'عرض التوزيع حسب الماركة':'View breakdown by brand' ?>">
                    🚗 <?= $totalCars ?> <?= $t[$lang]['count_label'] ?>
                    <span class="count-pill-arrow">▾</span>
                </button>
            <?php endif; ?>
            <button type="button" class="action-btn add-btn" onclick="toggleAdd()">➕ <?= $t[$lang]['add_car'] ?></button>
            <a href="dashboard.php?lang=<?= $lang ?>" class="action-btn dashboard-btn">🏠 <?= $t[$lang]['dashboard'] ?></a>
        </div>
    </div>
    <div class="lang-switch">
        <a href="?lang=ar" class="lang-btn <?= $lang==='ar'?'lang-active':'' ?>">🇪🇬 العربية</a>
        <a href="?lang=en" class="lang-btn <?= $lang==='en'?'lang-active':'' ?>">🇺🇸 English</a>
    </div>
</div>

<!-- ══════════════════════════════════
     ADD FORM
══════════════════════════════════ -->
<div class="add-card" id="addCard">
    <h3>➕ <?= $t[$lang]['add_title'] ?></h3>
    <form method="POST">
        <input type="hidden" name="action" value="add">
        <div class="form-grid">
            <div class="form-group">
                <label><?= $t[$lang]['brand'] ?></label>
                <select name="brand" id="addBrand" required>
                    <option value=""><?= $t[$lang]['select_brand'] ?></option>
                    <?php foreach ($brands as $b): ?>
                    <option value="<?= htmlspecialchars($b['name']) ?>"><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label><?= $t[$lang]['model'] ?></label>
                <select name="model" id="addModel" required>
                    <option value=""><?= $t[$lang]['select_model'] ?></option>
                </select>
            </div>
            <div class="form-group">
                <label><?= $t[$lang]['trim'] ?></label>
                <select name="trim_name" id="addTrim" required>
                    <option value=""><?= $t[$lang]['select_trim'] ?></option>
                </select>
            </div>
            <div class="form-group">
                <label><?= $t[$lang]['year1'] ?></label>
                <select name="year1" required>
                    <?php for ($y=$cy+5;$y>=$cy-1;$y--): ?>
                    <option value="<?= $y ?>" <?= $y===$cy?'selected':'' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group">
                <label><?= $t[$lang]['year2'] ?></label>
                <select name="year2">
                    <option value="">— <?= $t[$lang]['none'] ?> —</option>
                    <?php for ($y=$cy+5;$y>=$cy-1;$y--): ?>
                    <option value="<?= $y ?>"><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group">
                <label><?= $t[$lang]['quantity'] ?></label>
                <input type="number" name="quantity" min="1" max="999" value="1" required>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn-save">💾 <?= $t[$lang]['save'] ?></button>
            <button type="button" class="btn-cancel" onclick="toggleAdd()"><?= $t[$lang]['cancel'] ?></button>
        </div>
    </form>
</div>

<!-- ══════════════════════════════════
     SEARCH BOX
══════════════════════════════════ -->
<?php if (!empty($shipments)): ?>
<div class="search-card">
    <div class="search-inner">
        <span class="search-icon">🔍</span>
        <input
            type="text"
            class="search-input"
            id="shipSearch"
            placeholder="<?= htmlspecialchars($t[$lang]['search_placeholder']) ?>"
            autocomplete="off"
            inputmode="search"
        >
        <button type="button" class="search-clear-btn" id="searchClearBtn" onclick="clearSearch()">
            ✕ <?= $t[$lang]['search_clear'] ?>
        </button>
    </div>
</div>
<div class="search-empty-msg" id="searchEmptyMsg">
    🚗 <?= $t[$lang]['search_none'] ?>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════
     SHIPMENTS
══════════════════════════════════ -->
<?php if (empty($shipments)): ?>
<div class="empty-state">
    <div class="e-icon">🚚</div>
    <h2><?= $t[$lang]['empty'] ?></h2>
    <p><?= $t[$lang]['empty_sub'] ?></p>
</div>
<?php else: ?>
<?php if (count($shipments) > 1): ?>
<div class="drag-tip"><?= $t[$lang]['drag_tip'] ?></div>
<?php endif; ?>
<div class="ship-list" id="shipList">
<?php foreach ($shipments as $i => $s):
    $assigned    = $colorsByShipment[$s['id']] ?? [];
    $qty         = (int)$s['quantity'];
    $activeTags  = $assigned;
    $totalUsed   = count($activeTags);
    $remaining   = max(0, $qty - $totalUsed);
    $pct         = $qty > 0 ? round(($totalUsed / $qty) * 100) : 0;
    $activeGroups = [];
    foreach ($activeTags as $ac) {
        $activeGroups[$ac['color']][] = $ac;
    }
    $isFirst = ($i === 0);
    $isLast  = ($i === count($shipments) - 1);
    $editColorData = [];
    foreach ($activeGroups as $colorEn => $rows) {
        $editColorData[] = [
            'color' => $colorEn,
            'label' => $colorName[$colorEn] ?? $colorEn,
            'count' => count($rows),
        ];
    }
    $editJsonColors    = json_encode($editColorData, JSON_HEX_APOS | JSON_HEX_QUOT);
    $editJsonRemaining = (int)$remaining;
    /* search data string — used by JS to match */
    $searchData = strtolower($s['brand'] . ' ' . $s['model'] . ' ' . $s['trim_name'] . ' ' . $s['year1'] . ($s['year2'] ? ' '.$s['year2'] : ''));
?>
<div class="ship-card" id="ship-<?= $s['id'] ?>"
     data-search="<?= htmlspecialchars($searchData, ENT_QUOTES) ?>">
    <div class="ship-head">
        <div>
            <div class="ship-title">🚗
                <span class="searchable"><?= htmlspecialchars($s['brand']) ?> <?= htmlspecialchars($s['model']) ?></span>
            </div>
            <div class="ship-meta">
                <span><?= $t[$lang]['trim'] ?>: <strong class="searchable"><?= htmlspecialchars($s['trim_name']) ?></strong></span>
                <span class="year-chip">📅 <?= htmlspecialchars($s['year1']) ?><?= $s['year2'] ? ' / '.htmlspecialchars($s['year2']) : '' ?></span>
                <span class="year-chip"><?= $t[$lang]['qty'] ?>: <?= $qty ?></span>
            </div>
        </div>
        <div class="ship-controls">
            <button type="button" class="icon-btn drag-handle" title="<?= $t[$lang]['drag_hint'] ?>"
                    aria-label="<?= $t[$lang]['drag_hint'] ?>">⠿</button>
            <a class="icon-btn receive-btn" title="<?= $t[$lang]['receive'] ?>"
               href="receive_shipment.php?id=<?= $s['id'] ?>&lang=<?= $lang ?>">📦</a>
            <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="move">
                <input type="hidden" name="incoming_id" value="<?= $s['id'] ?>">
                <input type="hidden" name="dir" value="up">
                <button class="icon-btn" title="<?= $t[$lang]['move_up'] ?>" <?= $isFirst?'disabled':'' ?>>▲</button>
            </form>
            <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="move">
                <input type="hidden" name="incoming_id" value="<?= $s['id'] ?>">
                <input type="hidden" name="dir" value="down">
                <button class="icon-btn" title="<?= $t[$lang]['move_down'] ?>" <?= $isLast?'disabled':'' ?>>▼</button>
            </form>
            <button class="icon-btn edit-btn js-edit-btn"
                title="<?= $t[$lang]['edit'] ?>"
                data-shipid="<?= $s['id'] ?>"
                data-qty="<?= $qty ?>"
                data-remaining="<?= $editJsonRemaining ?>"
                data-colors="<?= htmlspecialchars($editJsonColors, ENT_QUOTES) ?>"
                data-car="<?= htmlspecialchars($s['brand'].' '.$s['model'].' - '.$s['trim_name'], ENT_QUOTES) ?>"
            >✏️</button>
            <form method="POST" style="display:inline"
                onsubmit="return confirm(decodeURIComponent('<?= rawurlencode($t[$lang]['confirm_del']) ?>'))">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="incoming_id" value="<?= $s['id'] ?>">
                <button class="icon-btn danger" title="<?= $t[$lang]['delete'] ?>">🗑</button>
            </form>
        </div>
    </div>

    <div class="progress-wrap">
        <div class="progress-bar">
            <div class="progress-fill" style="width:<?= $pct ?>%"></div>
        </div>
        <div class="progress-text">
            <span class="done-num"><?= count($activeTags) ?> / <?= $qty ?> <?= $t[$lang]['colored'] ?></span>
            <span class="remain-num"><?= $remaining ?> <?= $t[$lang]['remaining'] ?></span>
        </div>
    </div>

    <?php if (!empty($activeGroups)): ?>
    <div class="color-tags">
        <?php foreach ($activeGroups as $colorEn => $rows):
            $dispName = $colorName[$colorEn] ?? $colorEn;
            $cnt      = count($rows);
        ?>
        <button type="button" class="color-tag js-color-tag"
            data-shipid="<?= $s['id'] ?>"
            data-colorraw="<?= htmlspecialchars($colorEn, ENT_QUOTES) ?>"
            data-car="<?= htmlspecialchars($s['brand'].' '.$s['model'], ENT_QUOTES) ?>"
            data-color="<?= htmlspecialchars($dispName, ENT_QUOTES) ?>"
            data-count="<?= $cnt ?>">
            <span class="color-swatch" style="background:<?= strtolower(str_replace(' ','',htmlspecialchars($colorEn))) ?>;"></span>
            <?= htmlspecialchars($dispName) ?>
            <?php if ($cnt > 1): ?>
                <span class="color-count">x<?= $cnt ?></span>
            <?php endif; ?>
            <span style="margin-inline-start:4px;font-size:14px;opacity:.7;">✕</span>
        </button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($remaining > 0): ?>
    <form method="POST" class="assign-row">
        <input type="hidden" name="action" value="add_color">
        <input type="hidden" name="incoming_id" value="<?= $s['id'] ?>">
        <select name="color" required>
            <option value=""><?= $t[$lang]['select_color'] ?></option>
            <?php foreach ($colors as $c): ?>
            <option value="<?= htmlspecialchars($c['color_en']) ?>">
                <?= $lang==='ar' ? htmlspecialchars($c['color_ar']) : htmlspecialchars($c['color_en']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-add-color">🎨 <?= $t[$lang]['add_color'] ?></button>
    </form>
    <?php else: ?>
    <div class="all-done"><?= $t[$lang]['all_assigned'] ?></div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

</div><!-- /container -->

<!-- ══════════════════════════════════
     BRAND BREAKDOWN MODAL
══════════════════════════════════ -->
<?php if ($totalCars > 0): ?>
<div class="bd-overlay" id="bdOverlay" onclick="closeBrandBreakdown(event)">
    <div class="bd-box" onclick="event.stopPropagation()">
        <div class="bd-header">
            <button type="button" class="bd-close" onclick="closeBrandBreakdown()" aria-label="close">✕</button>
            <div class="bd-title">📊 <?= $lang==='ar'?'التوزيع حسب الماركة':'Breakdown by Brand' ?></div>
            <div class="bd-sub"><?= $lang==='ar'?'إجمالي السيارات القادمة موزّعة على كل ماركة':'All incoming cars split across each brand' ?></div>
            <div class="bd-total-chip">🚗 <?= $totalCars ?> <?= $t[$lang]['count_label'] ?> · <?= count($brandTotals) ?> <?= $lang==='ar'?'ماركة':'brands' ?></div>
        </div>
        <div class="bd-list" id="bdList">
            <?php $rank = 1; foreach ($brandTotals as $bn => $bt):
                $pct = $maxBrandCars > 0 ? round($bt['cars'] / $maxBrandCars * 100) : 0;
                $sharePct = $totalCars > 0 ? round($bt['cars'] / $totalCars * 100) : 0;
            ?>
            <div class="bd-row" style="animation-delay:<?= ($rank-1)*0.07 ?>s">
                <div class="bd-row-top">
                    <span class="bd-brand-name">
                        <span class="bd-brand-rank"><?= $rank ?></span>
                        <?= htmlspecialchars($bn) ?>
                    </span>
                    <span class="bd-brand-count"><?= $bt['cars'] ?> <small><?= $t[$lang]['count_label'] ?></small></span>
                </div>
                <div class="bd-bar-track">
                    <div class="bd-bar-fill" data-pct="<?= $pct ?>"></div>
                </div>
                <div class="bd-row-sub">
                    <?= $sharePct ?>% <?= $lang==='ar'?'من الإجمالي':'of total' ?>
                    · <?= $bt['shipments'] ?> <?= $lang==='ar'?($bt['shipments']>1?'شحنات':'شحنة'):('shipment'.($bt['shipments']>1?'s':'')) ?>
                </div>
            </div>
            <?php $rank++; endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Color dialog -->
<div class="clr-dialog hidden" id="clrDialog">
    <div class="clr-box">
        <div class="clr-icon">🎨</div>
        <div class="clr-q" id="clrQ"><?= $t[$lang]['clr_q'] ?></div>
        <div class="clr-name" id="clrName"></div>
        <div id="clrStepCount" style="display:none;">
            <div style="font-size:13px;color:#94a3b8;margin-bottom:12px;" id="clrCountLabel"></div>
            <div class="clr-num-grid" id="clrNumGrid"></div>
        </div>
        <div id="clrStepAction" class="clr-btns">
            <button type="button" class="clr-btn clr-btn-sold"    onclick="doColorAction('mark_sold')"><?= $t[$lang]['clr_sold'] ?></button>
            <button type="button" class="clr-btn clr-btn-recolor" onclick="doColorAction('remove_color')"><?= $t[$lang]['clr_recolor'] ?></button>
            <button type="button" class="clr-btn clr-btn-cancel"  onclick="closeDialog()"><?= $t[$lang]['clr_cancel'] ?></button>
        </div>
    </div>
</div>

<!-- Edit dialog -->
<div class="edit-dialog hidden" id="editDialog">
    <div class="edit-box">
        <div class="edit-dialog-title" id="edTitle"><?= $t[$lang]['edit_q'] ?></div>
        <div class="edit-dialog-car"   id="edCar"></div>
        <div class="edit-current-qty">
            <?= $lang==='ar'?'الكمية الحالية':'Current quantity' ?>:
            <strong id="edCurrentQty">—</strong>
        </div>
        <div class="edit-step active" id="edStep1">
            <div class="edit-choice-btns">
                <button type="button" class="edit-choice-btn inc" onclick="edGoIncrease()">
                    <span class="cb-icon">➕</span>
                    <span><?= $t[$lang]['edit_increase'] ?></span>
                </button>
                <button type="button" class="edit-choice-btn dec" onclick="edGoDecrease()">
                    <span class="cb-icon">➖</span>
                    <span><?= $t[$lang]['edit_decrease'] ?></span>
                </button>
            </div>
            <div class="edit-actions">
                <button type="button" class="edit-btn-cancel-all" onclick="closeEditDialog()"><?= $t[$lang]['edit_cancel'] ?></button>
            </div>
        </div>
        <div class="edit-step" id="edStep2Inc">
            <div style="font-size:13px;color:var(--muted);text-align:center;margin-bottom:12px;">
                <?= $t[$lang]['edit_inc_label'] ?>
            </div>
            <div class="inc-num-grid" id="edIncGrid"></div>
            <div class="inc-custom-row">
                <input type="number" id="edIncCustom" min="1" max="999" placeholder="<?= $lang==='ar'?'أو أدخل رقماً...':'or type a number...' ?>" oninput="edIncCustomChange(this.value)">
            </div>
            <div class="edit-actions">
                <button type="button" class="edit-btn-confirm" id="edIncConfirm" onclick="submitIncrease()"><?= $t[$lang]['edit_add_cars'] ?></button>
                <button type="button" class="edit-btn-back" onclick="edGoStep1()">← <?= $lang==='ar'?'رجوع':'Back' ?></button>
            </div>
        </div>
        <div class="edit-step" id="edStep2Dec">
            <div style="font-size:13px;color:var(--muted);text-align:center;margin-bottom:10px;">
                <?= $t[$lang]['edit_dec_label'] ?>
            </div>
            <div class="dec-items" id="edDecItems"></div>
            <div class="edit-actions">
                <button type="button" class="edit-btn-confirm red-confirm" id="edDecConfirm" onclick="submitDecrease()"><?= $t[$lang]['edit_confirm_dec'] ?></button>
                <button type="button" class="edit-btn-back" onclick="edGoStep1()">← <?= $lang==='ar'?'رجوع':'Back' ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden POST forms -->
<form method="POST" id="clrForm" style="display:none;">
    <input type="hidden" name="action"      id="clrFormAction">
    <input type="hidden" name="incoming_id" id="clrFormShip">
    <input type="hidden" name="color"       id="clrFormColor">
    <input type="hidden" name="num"         id="clrFormNum" value="1">
</form>
<form method="POST" id="edIncForm" style="display:none;">
    <input type="hidden" name="action"      value="increase_qty">
    <input type="hidden" name="incoming_id" id="edIncShip">
    <input type="hidden" name="add_count"   id="edIncCount">
</form>
<form method="POST" id="edDecForm" style="display:none;">
    <input type="hidden" name="action"        value="decrease_qty">
    <input type="hidden" name="incoming_id"   id="edDecShip">
    <input type="hidden" name="remove_color"  id="edDecColor">
    <input type="hidden" name="remove_count"  id="edDecCount">
</form>

<script>
/* ══════════════════════════════════════
   MOTIVATIONAL ROTATOR
══════════════════════════════════════ */
(function () {
    var msgs = <?= json_encode($motivations, JSON_UNESCAPED_UNICODE) ?>;
    var icons = ['🏆','🚀','💎','🔥','⚡','🌟','💡','🎯','👑','🦅','💪','🛡️','✨','🏅','🌊'];
    var current = Math.floor(Math.random() * msgs.length);
    var total   = msgs.length;
    var el      = document.getElementById('motivMsg');
    var iconEl  = document.getElementById('motivIcon');
    var dotsEl  = document.getElementById('motivDots');
    var INTERVAL= 8000; // ms between rotations
    var timer;

    /* Build dot indicators (max 10 dots to avoid overflow) */
    var dotCount = Math.min(total, 10);
    for (var d = 0; d < dotCount; d++) {
        var dot = document.createElement('span');
        dot.className = 'motiv-dot' + (d === 0 ? ' active' : '');
        dot.setAttribute('data-idx', d);
        dot.addEventListener('click', (function(idx){ return function(){ goTo(idx); }; })(d));
        dotsEl.appendChild(dot);
    }

    function updateDots(idx) {
        var dotIdx = Math.floor(idx / Math.ceil(total / dotCount));
        dotsEl.querySelectorAll('.motiv-dot').forEach(function(d, i) {
            d.classList.toggle('active', i === dotIdx);
        });
    }

    function goTo(absoluteIdx) {
        clearInterval(timer);
        current = absoluteIdx % total;
        fadeAndSet();
        timer = setInterval(next, INTERVAL);
    }

    function fadeAndSet() {
        el.classList.remove('fade-in');
        el.classList.add('fade-out');
        setTimeout(function () {
            el.textContent   = msgs[current];
            iconEl.textContent = icons[current % icons.length];
            el.classList.remove('fade-out');
            el.classList.add('fade-in');
            updateDots(current);
        }, 520);
    }

    function next() {
        current = (current + 1) % total;
        fadeAndSet();
    }

    /* initial set (no fade needed) */
    el.textContent     = msgs[current];
    iconEl.textContent = icons[current % icons.length];
    updateDots(current);

    timer = setInterval(next, INTERVAL);
})();

/* ══════════════════════════════════════
   LIVE SEARCH
══════════════════════════════════════ */
(function () {
    var input      = document.getElementById('shipSearch');
    var clearBtn   = document.getElementById('searchClearBtn');
    var emptyMsg   = document.getElementById('searchEmptyMsg');
    var cards      = document.querySelectorAll('#shipList .ship-card');

    if (!input) return; // no shipments rendered

    input.addEventListener('input', function () {
        var q = this.value.trim().toLowerCase();
        clearBtn.classList.toggle('visible', q.length > 0);
        var visible = 0;

        cards.forEach(function (card) {
            var haystack = (card.getAttribute('data-search') || '').toLowerCase();
            var matches  = (q === '' || haystack.indexOf(q) !== -1);
            card.classList.toggle('search-hidden', !matches);
            if (matches) visible++;
        });

        if (emptyMsg) emptyMsg.classList.toggle('visible', q.length > 0 && visible === 0);
    });

    function clearSearch() {
        input.value = '';
        input.dispatchEvent(new Event('input'));
        input.focus();
    }
    /* expose to onclick */
    window.clearSearch = clearSearch;
    if (clearBtn) clearBtn.addEventListener('click', clearSearch);
})();

/* ══════════════════════════════════════
   COLOR DIALOG (unchanged)
══════════════════════════════════════ */
var _clrShip=0,_clrColor='',_clrCount=1;
function openColorDialog(shipId,colorRaw,carName,colorLabel,count){
    _clrShip=shipId; _clrColor=colorRaw; _clrCount=parseInt(count)||1;
    document.getElementById('clrName').textContent=carName+' — '+colorLabel;
    document.getElementById('clrFormShip').value=shipId;
    document.getElementById('clrFormColor').value=colorRaw;
    var stepCount=document.getElementById('clrStepCount');
    var numGrid=document.getElementById('clrNumGrid');
    var countLabel=document.getElementById('clrCountLabel');
    if(_clrCount>1){
        countLabel.textContent=(document.documentElement.lang==='ar')
            ?'كم عدد السيارات؟ (لديك '+_clrCount+')'
            :'How many cars? (you have '+_clrCount+')';
        numGrid.innerHTML='';
        for(var n=1;n<=_clrCount;n++){(function(num){
            var b=document.createElement('button');
            b.type='button'; b.className='clr-num-btn'+(num===1?' active':'');
            b.textContent=num;
            b.onclick=function(){
                document.getElementById('clrFormNum').value=num;
                numGrid.querySelectorAll('.clr-num-btn').forEach(function(x){x.classList.remove('active');});
                b.classList.add('active');
            };
            numGrid.appendChild(b);
        })(n);}
        document.getElementById('clrFormNum').value=1;
        stepCount.style.display='block';
    } else {
        stepCount.style.display='none';
        document.getElementById('clrFormNum').value=1;
    }
    document.getElementById('clrDialog').classList.remove('hidden');
}
function doColorAction(action){
    document.getElementById('clrFormAction').value=action;
    document.getElementById('clrForm').submit();
}
document.addEventListener('click',function(e){
    var tag=e.target.closest('.js-color-tag');
    if(!tag)return;
    e.preventDefault();
    openColorDialog(tag.getAttribute('data-shipid'),tag.getAttribute('data-colorraw'),
        tag.getAttribute('data-car'),tag.getAttribute('data-color'),tag.getAttribute('data-count'));
});
function closeDialog(){document.getElementById('clrDialog').classList.add('hidden');}
document.getElementById('clrDialog').addEventListener('click',function(e){if(e.target===this)closeDialog();});

/* ══════════════════════════════════════
   EDIT QUANTITY DIALOG (unchanged)
══════════════════════════════════════ */
var _edShipId=0,_edQty=0,_edRemaining=0,_edColors=[],_edIncChosen=1,_edDecColor='',_edDecCount=1;
document.addEventListener('click',function(e){
    var btn=e.target.closest('.js-edit-btn');
    if(!btn)return;
    e.preventDefault();
    var shipId=parseInt(btn.getAttribute('data-shipid'));
    var qty=parseInt(btn.getAttribute('data-qty'));
    var remaining=parseInt(btn.getAttribute('data-remaining'));
    var colors=[];
    try{colors=JSON.parse(btn.getAttribute('data-colors'));}catch(err){colors=[];}
    var carLabel=btn.getAttribute('data-car');
    openEditDialog(shipId,qty,remaining,colors,carLabel);
});
function openEditDialog(shipId,qty,remaining,colors,carLabel){
    _edShipId=shipId;_edQty=qty;_edRemaining=remaining;_edColors=colors;
    document.getElementById('edCar').textContent=carLabel;
    document.getElementById('edCurrentQty').textContent=qty;
    edGoStep1();
    document.getElementById('editDialog').classList.remove('hidden');
}
function edGoStep1(){
    document.querySelectorAll('.edit-step').forEach(function(s){s.classList.remove('active');});
    document.getElementById('edStep1').classList.add('active');
}
function edGoIncrease(){
    document.querySelectorAll('.edit-step').forEach(function(s){s.classList.remove('active');});
    var grid=document.getElementById('edIncGrid');
    grid.innerHTML='';_edIncChosen=1;
    for(var n=1;n<=10;n++){(function(num){
        var b=document.createElement('button');
        b.type='button';b.className='inc-num-btn'+(num===1?' active':'');b.textContent=num;
        b.onclick=function(){
            _edIncChosen=num;
            document.getElementById('edIncCustom').value='';
            grid.querySelectorAll('.inc-num-btn').forEach(function(x){x.classList.remove('active');});
            b.classList.add('active');
        };
        grid.appendChild(b);
    })(n);}
    document.getElementById('edIncCustom').value='';
    document.getElementById('edStep2Inc').classList.add('active');
}
function edIncCustomChange(val){
    var n=parseInt(val);
    if(n>0){_edIncChosen=n;document.getElementById('edIncGrid').querySelectorAll('.inc-num-btn').forEach(function(b){b.classList.remove('active');});}
}
function submitIncrease(){
    var customVal=document.getElementById('edIncCustom').value.trim();
    var custom=(customVal!=='')?parseInt(customVal):NaN;
    var final=(!isNaN(custom)&&custom>0)?custom:_edIncChosen;
    if(!final||final<1)return;
    document.getElementById('edIncShip').value=_edShipId;
    document.getElementById('edIncCount').value=final;
    document.getElementById('edIncForm').submit();
}
function edGoDecrease(){
    document.querySelectorAll('.edit-step').forEach(function(s){s.classList.remove('active');});
    var container=document.getElementById('edDecItems');
    container.innerHTML='';
    _edDecColor='';_edDecCount=1;
    var isAr=document.documentElement.lang==='ar';
    _edColors.forEach(function(c){
        var item=document.createElement('div');
        item.className='dec-item';
        var swatchBg=c.color.toLowerCase().replace(/\s+/g,'');
        var numBtns='';
        for(var n=1;n<=c.count;n++){numBtns+='<button type="button" class="dec-n-btn'+(n===1?' active':'')+'" data-color="'+c.color+'" data-num="'+n+'" onclick="decSelectItem(\''+c.color.replace(/'/g,"\\'")+'\',' +n+', this)">'+n+'</button>';}
        item.innerHTML='<div class="dec-item-left"><div class="dec-swatch" style="background:'+swatchBg+'"></div><div><div class="dec-item-name">'+c.label+'</div><div class="dec-item-sub">'+c.count+' '+(isAr?'سيارة':'cars')+'</div></div></div><div class="dec-num-row">'+numBtns+'</div>';
        container.appendChild(item);
    });
    if(_edRemaining>0){
        var uItem=document.createElement('div');
        uItem.className='dec-item uncolored-item';
        var uBtns='';
        for(var n=1;n<=_edRemaining;n++){uBtns+='<button type="button" class="dec-n-btn'+(n===1?' active':'')+'" data-color="__uncolored__" data-num="'+n+'" onclick="decSelectItem(\'__uncolored__\','+n+', this)">'+n+'</button>';}
        var uLabel=isAr?(_edRemaining+' سيارة بدون لون'):(_edRemaining+' uncolored car'+(_edRemaining>1?'s':''));
        uItem.innerHTML='<div class="dec-item-left"><div class="dec-swatch" style="background:rgba(245,158,11,.4)"></div><div><div class="dec-item-name" style="color:var(--amber)">'+(isAr?'بدون لون':'Uncolored')+'</div><div class="dec-item-sub">'+uLabel+'</div></div></div><div class="dec-num-row">'+uBtns+'</div>';
        container.appendChild(uItem);
    }
    if(_edColors.length>0){_edDecColor=_edColors[0].color;_edDecCount=1;}
    else if(_edRemaining>0){_edDecColor='__uncolored__';_edDecCount=1;}
    document.getElementById('edStep2Dec').classList.add('active');
}
function decSelectItem(color,num,btn){
    _edDecColor=color;_edDecCount=num;
    document.querySelectorAll('#edDecItems .dec-n-btn[data-color="'+color+'"]').forEach(function(b){b.classList.remove('active');});
    btn.classList.add('active');
    document.querySelectorAll('#edDecItems .dec-n-btn').forEach(function(b){if(b.getAttribute('data-color')!==color)b.classList.remove('active');});
}
function submitDecrease(){
    if(!_edDecColor)return;
    document.getElementById('edDecShip').value=_edShipId;
    document.getElementById('edDecColor').value=_edDecColor;
    document.getElementById('edDecCount').value=_edDecCount;
    document.getElementById('edDecForm').submit();
}
function closeEditDialog(){document.getElementById('editDialog').classList.add('hidden');}
document.getElementById('editDialog').addEventListener('click',function(e){if(e.target===this)closeEditDialog();});

/* ══════════════════════════════════════
   CASCADING DROPDOWNS
══════════════════════════════════════ */
var addBrand=document.getElementById('addBrand');
var addModel=document.getElementById('addModel');
var addTrim =document.getElementById('addTrim');
var L={model:<?= json_encode($t[$lang]['select_model']) ?>,trim:<?= json_encode($t[$lang]['select_trim']) ?>};
addBrand.addEventListener('change',function(){
    addModel.innerHTML='<option value="">'+L.model+'</option>';
    addTrim.innerHTML='<option value="">'+L.trim+'</option>';
    if(!this.value)return;
    fetch('get_models.php?brand='+encodeURIComponent(this.value))
        .then(function(r){return r.json();}).then(function(data){
            data.forEach(function(m){var o=document.createElement('option');o.value=m;o.textContent=m;addModel.appendChild(o);});
        });
});
addModel.addEventListener('change',function(){
    addTrim.innerHTML='<option value="">'+L.trim+'</option>';
    if(!this.value)return;
    fetch('get_trims.php?brand='+encodeURIComponent(addBrand.value)+'&model='+encodeURIComponent(this.value))
        .then(function(r){return r.json();}).then(function(data){
            data.forEach(function(tr){var o=document.createElement('option');o.value=tr;o.textContent=tr;addTrim.appendChild(o);});
        });
});
function toggleAdd(){document.getElementById('addCard').classList.toggle('open');}

/* ══════════════════════════════════════
   BRAND BREAKDOWN MODAL
══════════════════════════════════════ */
function openBrandBreakdown(){
    var ov=document.getElementById('bdOverlay');
    if(!ov)return;
    ov.classList.add('open');
    document.body.style.overflow='hidden';
    // animate bars after the box appears
    setTimeout(function(){
        ov.querySelectorAll('.bd-bar-fill').forEach(function(bar){
            bar.style.width=(bar.getAttribute('data-pct')||0)+'%';
        });
    },180);
}
function closeBrandBreakdown(e){
    var ov=document.getElementById('bdOverlay');
    if(!ov)return;
    ov.classList.remove('open');
    document.body.style.overflow='';
    // reset bars so they re-animate next open
    setTimeout(function(){
        ov.querySelectorAll('.bd-bar-fill').forEach(function(bar){bar.style.width='0';});
    },300);
}
document.addEventListener('keydown',function(e){
    if(e.key==='Escape'){closeBrandBreakdown();}
});

/* ════════════════════════════════════════════════════════════════
   Drag-and-drop reordering of shipments.

   Nothing about how ordering WORKS changes: the list is still driven
   by sort_order and the ▲ ▼ buttons still do exactly what they did.
   This only adds a nicer way to produce the same result — grab the ⠿
   handle, drop the card where you want it, and the new order is saved
   in the background (no page reload).
   Works with mouse, touch and pen via pointer events.
════════════════════════════════════════════════════════════════ */
(function () {
    const list = document.getElementById('shipList');
    if (!list) return;

    const T_SAVING = <?= json_encode($t[$lang]['order_saving'], JSON_UNESCAPED_UNICODE) ?>;
    const T_SAVED  = <?= json_encode($t[$lang]['order_saved'],  JSON_UNESCAPED_UNICODE) ?>;
    const T_ERR    = <?= json_encode($t[$lang]['order_err'],    JSON_UNESCAPED_UNICODE) ?>;

    let dragEl = null, placeholder = null, grabDY = 0, pointerId = null, scrollTimer = null;

    /* ── little toast in the corner ── */
    let toastEl = null, toastTimer = null;
    function toast(msg, kind) {
        if (!toastEl) {
            toastEl = document.createElement('div');
            toastEl.className = 'order-toast';
            document.body.appendChild(toastEl);
        }
        toastEl.textContent = msg;
        toastEl.className = 'order-toast show' + (kind ? ' ' + kind : '');
        clearTimeout(toastTimer);
        if (kind) toastTimer = setTimeout(() => toastEl.classList.remove('show'), 2200);
    }

    function otherCards() {
        return Array.from(list.querySelectorAll('.ship-card')).filter(c => c !== dragEl);
    }

    /* Put the placeholder where the pointer currently is. */
    function positionPlaceholder(clientY) {
        const cards = otherCards();
        for (const card of cards) {
            const r = card.getBoundingClientRect();
            if (clientY < r.top + r.height / 2) {
                list.insertBefore(placeholder, card);
                return;
            }
        }
        list.appendChild(placeholder);
    }

    /* Auto-scroll when dragging near the top/bottom of the window. */
    function edgeScroll(clientY) {
        const margin = 90, speed = 14;
        clearInterval(scrollTimer);
        let dir = 0;
        if (clientY < margin) dir = -1;
        else if (clientY > window.innerHeight - margin) dir = 1;
        if (dir !== 0) scrollTimer = setInterval(() => window.scrollBy(0, dir * speed), 16);
    }

    list.addEventListener('pointerdown', function (e) {
        const handle = e.target.closest('.drag-handle');
        if (!handle || e.button > 0) return;
        const card = handle.closest('.ship-card');
        if (!card || list.querySelectorAll('.ship-card').length < 2) return;

        e.preventDefault();
        dragEl = card;
        pointerId = e.pointerId;

        const r = dragEl.getBoundingClientRect();
        grabDY = e.clientY - r.top;

        placeholder = document.createElement('div');
        placeholder.className = 'drag-placeholder';
        placeholder.style.height = r.height + 'px';
        list.insertBefore(placeholder, dragEl);

        // lift the card out of the flow so it follows the finger/cursor
        dragEl.classList.add('dragging');
        dragEl.style.position = 'fixed';
        dragEl.style.zIndex = '1500';
        dragEl.style.width = r.width + 'px';
        dragEl.style.left = r.left + 'px';
        dragEl.style.top = r.top + 'px';
        dragEl.style.pointerEvents = 'none';
        list.classList.add('is-dragging');

        handle.setPointerCapture(pointerId);
    });

    list.addEventListener('pointermove', function (e) {
        if (!dragEl || e.pointerId !== pointerId) return;
        e.preventDefault();
        dragEl.style.top = (e.clientY - grabDY) + 'px';
        positionPlaceholder(e.clientY);
        edgeScroll(e.clientY);
    });

    function finishDrag() {
        if (!dragEl) return;
        clearInterval(scrollTimer);

        // drop the card into the placeholder's slot
        list.insertBefore(dragEl, placeholder);
        placeholder.remove();
        placeholder = null;

        dragEl.classList.remove('dragging');
        dragEl.style.position = dragEl.style.zIndex = dragEl.style.width =
            dragEl.style.left = dragEl.style.top = dragEl.style.pointerEvents = '';
        list.classList.remove('is-dragging');
        dragEl = null;
        pointerId = null;

        saveOrder();
    }

    list.addEventListener('pointerup', finishDrag);
    list.addEventListener('pointercancel', finishDrag);

    /* ── persist the new order (same sort_order column as ▲▼) ── */
    function saveOrder() {
        const ids = Array.from(list.querySelectorAll('.ship-card'))
                         .map(c => (c.id || '').replace('ship-', ''))
                         .filter(Boolean);
        if (!ids.length) return;

        toast(T_SAVING, '');

        const fd = new FormData();
        fd.append('action', 'reorder');
        ids.forEach(id => fd.append('ids[]', id));

        fetch('incoming_cars.php?lang=<?= $lang ?>', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d && d.ok) {
                    toast(T_SAVED, 'ok');
                    refreshArrows();
                } else {
                    toast(T_ERR, 'err');
                }
            })
            .catch(() => toast(T_ERR, 'err'));
    }

    /* Keep ▲ / ▼ disabled correctly on the new first/last card. */
    function refreshArrows() {
        const cards = Array.from(list.querySelectorAll('.ship-card'));
        cards.forEach((card, i) => {
            const up   = card.querySelector('input[value="up"]');
            const down = card.querySelector('input[value="down"]');
            if (up   && up.form)   up.form.querySelector('button').disabled   = (i === 0);
            if (down && down.form) down.form.querySelector('button').disabled = (i === cards.length - 1);
        });
    }
})();
</script>
</body>
</html>