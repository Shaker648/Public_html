<?php
/**
 * receive_shipment.php — First 1 Car
 * ═══════════════════════════════════════════════════════════════
 * Converts an incoming shipment (incoming_cars + incoming_colors)
 * into real stock (cars + movements) — no more re-typing in add_vehicle.
 *
 * FLOW
 *  1. Pick a shipment (or arrive via ?id=X from incoming_cars.php).
 *  2. Choose the destination branch + model year once for the batch.
 *  3. Tap a color chip for each physical car that arrived
 *     → a row appears → type OR 📷 SCAN the chassis barcode.
 *  4. Confirm → one transaction:
 *       • duplicate-chassis check against the whole cars table
 *       • INSERT INTO cars   (same columns as add_vehicle.php)
 *       • INSERT INTO movements (event_type 'created')
 *       • consume the matching incoming_colors row + quantity −1
 *       • shipment auto-deletes when quantity reaches 0
 *  5. Partial receiving is fine — receive 3 of 10 today, 7 later.
 *
 * SAFETY
 *  - Admin/manager only.
 *  - ALL-OR-NOTHING: if any chassis already exists, the whole batch
 *    is rejected and the duplicates are listed — nothing half-saved.
 *  - Barcode scanner is pure client-side (html5-qrcode via CDN),
 *    reads Code 39 / Code 128 (standard VIN plate barcodes) + QR.
 *
 * Stack: same as every page — auth.php + config.php → $pdo.
 */

require 'auth.php';
require 'config.php';

$lang = $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar', 'en'], true)) $lang = 'ar';
$dir = $lang === 'ar' ? 'rtl' : 'ltr';

/* ─── Permission-gated (default: admin / manager) ─────────── */
$role = $_SESSION['role'] ?? 'sales';
perm_require('page.receive_shipment');
$username = $_SESSION['username'] ?? '';

/* ═══════════════════════════════════════════════════════════
   AJAX ACTION — receive batch (JSON in, JSON out)
═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (($body['action'] ?? '') !== 'receive') {
        echo json_encode(['ok' => false, 'error' => 'bad_action']); exit;
    }

    $shipId = (int)($body['shipment_id'] ?? 0);
    $branch = trim((string)($body['branch'] ?? ''));
    $year   = trim((string)($body['year'] ?? ''));
    $units  = is_array($body['units'] ?? null) ? $body['units'] : [];

    if ($shipId <= 0 || $branch === '' || $year === '' || empty($units)) {
        echo json_encode(['ok' => false, 'error' => 'missing_fields']); exit;
    }
    if (count($units) > 200) {   // sanity cap
        echo json_encode(['ok' => false, 'error' => 'too_many']); exit;
    }

    /* Shipment must exist */
    $sStmt = $pdo->prepare("SELECT * FROM incoming_cars WHERE id = ? LIMIT 1");
    $sStmt->execute([$shipId]);
    $ship = $sStmt->fetch(PDO::FETCH_ASSOC);
    if (!$ship) {
        echo json_encode(['ok' => false, 'error' => 'ship_not_found']); exit;
    }

    /* Branch must exist */
    $bChk = $pdo->prepare("SELECT name FROM branches WHERE name = ? LIMIT 1");
    $bChk->execute([$branch]);
    if (!$bChk->fetchColumn()) {
        echo json_encode(['ok' => false, 'error' => 'bad_branch']); exit;
    }

    /* Year must be one of the shipment's listed years */
    $validYears = [ (string)$ship['year1'] ];
    if (!empty($ship['year2'])) $validYears[] = (string)$ship['year2'];
    if (!in_array($year, $validYears, true)) {
        echo json_encode(['ok' => false, 'error' => 'bad_year']); exit;
    }

    /* Normalize + validate units */
    $clean = [];
    $seen  = [];
    foreach ($units as $u) {
        $chassis = strtoupper(trim((string)($u['chassis'] ?? '')));
        $color   = trim((string)($u['color'] ?? ''));
        $rowId   = (int)($u['color_row_id'] ?? 0);   // 0 = unassigned unit
        if ($chassis === '' || $color === '') {
            echo json_encode(['ok' => false, 'error' => 'unit_missing']); exit;
        }
        if (isset($seen[$chassis])) {
            echo json_encode([
                'ok' => false, 'error' => 'dup_in_batch',
                'duplicates' => [$chassis]
            ], JSON_UNESCAPED_UNICODE); exit;
        }
        $seen[$chassis] = true;
        $clean[] = ['chassis' => $chassis, 'color' => $color, 'row_id' => $rowId];
    }

    /* Not more units than the shipment still has */
    if (count($clean) > (int)$ship['quantity']) {
        echo json_encode(['ok' => false, 'error' => 'exceeds_qty']); exit;
    }

    /* ── ALL-OR-NOTHING duplicate check against cars ── */
    $ph  = implode(',', array_fill(0, count($clean), 'UPPER(?)'));
    $chk = $pdo->prepare("SELECT UPPER(chassis) FROM cars WHERE UPPER(chassis) IN ($ph)");
    $chk->execute(array_column($clean, 'chassis'));
    $existing = $chk->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($existing)) {
        echo json_encode([
            'ok' => false, 'error' => 'dup_in_system',
            'duplicates' => array_values($existing)
        ], JSON_UNESCAPED_UNICODE); exit;
    }

    /* ── Transaction: everything succeeds or nothing does ── */
    try {
        $pdo->beginTransaction();

        $insCar = $pdo->prepare("
            INSERT INTO cars
                (brand, model, car_year, trim_name, color, chassis,
                 branch, original_branch, status, notes, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, 'available', ?, ?)
        ");
        $insMov = $pdo->prepare("
            INSERT INTO movements
                (car_id, from_branch, to_branch, moved_by, notes, event_type)
            VALUES
                (?, ?, ?, ?, ?, 'created')
        ");
        $delRowById   = $pdo->prepare("DELETE FROM incoming_colors WHERE id = ? AND incoming_id = ?");
        $delRowByColor= $pdo->prepare("
            DELETE FROM incoming_colors
            WHERE incoming_id = ? AND color = ?
            ORDER BY id ASC LIMIT 1
        ");

        $note    = 'Received from incoming shipment: '
                 . $ship['brand'] . ' ' . $ship['model'] . ' ' . $ship['trim_name'];
        $added   = [];

        foreach ($clean as $u) {
            $insCar->execute([
                $ship['brand'],
                $ship['model'],
                $year,
                $ship['trim_name'],
                $u['color'],
                $u['chassis'],
                $branch,
                $branch,          // original_branch = branch at creation
                $note,
                $username,
            ]);
            $carId = (int)$pdo->lastInsertId();

            $insMov->execute([$carId, $branch, $branch, $username, $note]);

            /* Consume the incoming color row (specific row if we have its id,
               otherwise the oldest row of that color; unassigned units have
               no row to consume — quantity alone goes down). */
            if ($u['row_id'] > 0) {
                $delRowById->execute([$u['row_id'], $shipId]);
                if ($delRowById->rowCount() === 0) {
                    // Row was already consumed elsewhere — try by color instead
                    $delRowByColor->execute([$shipId, $u['color']]);
                }
            } else {
                $delRowByColor->execute([$shipId, $u['color']]);
            }

            $added[] = ['chassis' => $u['chassis'], 'color' => $u['color']];
        }

        /* Quantity down by number received (never below 0) */
        $pdo->prepare("UPDATE incoming_cars SET quantity = GREATEST(0, quantity - ?) WHERE id = ?")
            ->execute([count($clean), $shipId]);

        /* Auto-clean: shipment fully received → remove it + any leftover rows */
        $qLeft = (int)$pdo->query("SELECT quantity FROM incoming_cars WHERE id = " . (int)$shipId)->fetchColumn();
        $shipDeleted = false;
        if ($qLeft <= 0) {
            $pdo->prepare("DELETE FROM incoming_colors WHERE incoming_id = ?")->execute([$shipId]);
            $pdo->prepare("DELETE FROM incoming_cars   WHERE id = ?")->execute([$shipId]);
            $shipDeleted = true;
        }

        $pdo->commit();

        echo json_encode([
            'ok'            => true,
            'added'         => $added,
            'remaining_qty' => max(0, $qLeft),
            'ship_deleted'  => $shipDeleted,
            'branch'        => $branch,
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('receive_shipment failed: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'save_failed']);
    }
    exit;
}

/* ═══════════════════════════════════════════════════════════
   PAGE DATA
═══════════════════════════════════════════════════════════ */
$preselect = (int)($_GET['id'] ?? 0);

$shipments = $pdo->query("
    SELECT * FROM incoming_cars
    WHERE quantity > 0
    ORDER BY sort_order ASC, id ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* color rows per shipment */
$colorRows = [];
foreach ($pdo->query("SELECT * FROM incoming_colors ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $colorRows[$r['incoming_id']][] = $r;
}

/* Arabic color names */
$colorName = [];
$allColors = $pdo->query("SELECT color_en, color_ar FROM colors ORDER BY color_en")->fetchAll(PDO::FETCH_ASSOC);
foreach ($allColors as $c) $colorName[$c['color_en']] = $c['color_ar'];

$branches = $pdo->query("SELECT * FROM branches ORDER BY name_en")->fetchAll(PDO::FETCH_ASSOC);

/* ─── Translations ─────────────────────────────────────────── */
$t = [
    'ar' => [
        'title'          => 'استلام شحنة',
        'subtitle'       => 'حوّل الشحنة القادمة إلى مخزون فعلي في ثوانٍ',
        'back'           => 'العودة للوارد',
        'dashboard'      => 'الرئيسية',
        'pick_ship'      => 'اختر الشحنة الواصلة',
        'no_ships'       => '📭 لا توجد شحنات قادمة حالياً',
        'qty'            => 'الكمية',
        'trim'           => 'الفئة',
        'select'         => 'استلام',
        'batch_settings' => 'بيانات الاستلام',
        'dest_branch'    => 'الفرع المستلِم',
        'select_branch'  => 'اختر الفرع',
        'model_year'     => 'سنة الصنع',
        'units_title'    => 'السيارات الواصلة',
        'units_hint'     => 'اضغط على لون لكل سيارة وصلت فعلياً، ثم امسح أو اكتب الشاسيه',
        'unassigned'     => 'بدون لون محدد',
        'pick_color'     => 'اختر اللون',
        'chassis_ph'     => 'رقم الشاسيه...',
        'scan'           => 'مسح',
        'remove'         => 'حذف',
        'no_units'       => 'لم تُضف أي سيارة بعد — اضغط على الألوان بالأعلى',
        'confirm'        => '📦 تأكيد الاستلام',
        'confirming'     => 'جاري الحفظ...',
        'left_after'     => 'متبقي بالشحنة بعد الاستلام',
        'receiving_n'    => 'سيتم استلام',
        'cars_word'      => 'سيارة',
        'err_branch'     => 'اختر الفرع المستلِم أولاً',
        'err_chassis'    => 'أكمل أرقام الشاسيه لكل السيارات',
        'err_dup_batch'  => 'رقم شاسيه مكرر داخل نفس القائمة:',
        'err_dup_sys'    => 'هذه الشاسيهات موجودة بالفعل في النظام — لم يتم حفظ أي شيء:',
        'err_generic'    => 'حدث خطأ أثناء الحفظ — لم يتم استلام أي سيارة. حاول مرة أخرى.',
        'success_title'  => 'تم الاستلام بنجاح! 🎉',
        'success_sub'    => 'أُضيفت السيارات التالية إلى مخزون',
        'ship_done'      => '✅ اكتملت الشحنة بالكامل وتم إغلاقها',
        'ship_left'      => 'متبقي في الشحنة',
        'receive_more'   => 'استلام المزيد',
        'go_dashboard'   => 'الذهاب للرئيسية',
        'go_incoming'    => 'العودة للوارد',
        'scan_title'     => 'مسح باركود الشاسيه',
        'scan_hint'      => 'التقط صورة قريبة وواضحة لباركود الشاسيه — قرّب حتى يملأ الباركود الصورة وتجنّب الانعكاسات',
        'scan_close'     => 'إغلاق',
        'scan_fail'      => 'تعذّر تشغيل الكاميرا — تأكد من السماح بالوصول للكاميرا',
        'scan_photo'     => 'من صورة',
        'scan_photo_fail'=> 'لم يتم التعرف على الباركود في الصورة — جرّب صورة أقرب وأوضح وبإضاءة جيدة',
        'scan_use_photo' => '📸 التقط صورة للباركود',
        'scan_reading'   => '⏳ جاري قراءة الصورة...',
        'scan_ocr'       => '⏳ لم يوجد باركود — جاري قراءة النص من الصورة (قد يستغرق ثوانٍ)...',
        'scan_select'    => '👆 ظلّل رقم الشاسيه بإصبعك على الصورة ثم اضغط قراءة',
        'scan_confirm'   => '✅ قراءة التحديد',
        'scan_new'       => '📸 صورة جديدة',
        'switch_lang'    => 'English',
        'chip_hint'      => 'اضغط لإضافة سيارة بهذا اللون',
    ],
    'en' => [
        'title'          => 'Receive Shipment',
        'subtitle'       => 'Turn an incoming shipment into real stock in seconds',
        'back'           => 'Back to Incoming',
        'dashboard'      => 'Dashboard',
        'pick_ship'      => 'Pick the arrived shipment',
        'no_ships'       => '📭 No incoming shipments right now',
        'qty'            => 'Qty',
        'trim'           => 'Trim',
        'select'         => 'Receive',
        'batch_settings' => 'Receiving Details',
        'dest_branch'    => 'Receiving Branch',
        'select_branch'  => 'Select branch',
        'model_year'     => 'Model Year',
        'units_title'    => 'Arrived Cars',
        'units_hint'     => 'Tap a color for each car that physically arrived, then scan or type its chassis',
        'unassigned'     => 'No color assigned',
        'pick_color'     => 'Pick color',
        'chassis_ph'     => 'Chassis number...',
        'scan'           => 'Scan',
        'remove'         => 'Remove',
        'no_units'       => 'No cars added yet — tap the colors above',
        'confirm'        => '📦 Confirm Receiving',
        'confirming'     => 'Saving...',
        'left_after'     => 'Left in shipment after receiving',
        'receiving_n'    => 'Receiving',
        'cars_word'      => 'cars',
        'err_branch'     => 'Choose the receiving branch first',
        'err_chassis'    => 'Fill in the chassis for every car',
        'err_dup_batch'  => 'Duplicate chassis inside this list:',
        'err_dup_sys'    => 'These chassis already exist in the system — nothing was saved:',
        'err_generic'    => 'Something went wrong — no cars were received. Please try again.',
        'success_title'  => 'Received Successfully! 🎉',
        'success_sub'    => 'The following cars were added to the stock of',
        'ship_done'      => '✅ Shipment fully received and closed',
        'ship_left'      => 'Left in shipment',
        'receive_more'   => 'Receive More',
        'go_dashboard'   => 'Go to Dashboard',
        'go_incoming'    => 'Back to Incoming',
        'scan_title'     => 'Scan Chassis Barcode',
        'scan_hint'      => 'Take a close, sharp photo of the chassis barcode — fill the frame with it and avoid reflections',
        'scan_close'     => 'Close',
        'scan_fail'      => 'Camera could not start — make sure camera access is allowed',
        'scan_photo'     => 'From Photo',
        'scan_photo_fail'=> 'Could not read a barcode from that photo — try closer, sharper, well-lit',
        'scan_use_photo' => '📸 Take a photo of the barcode',
        'scan_reading'   => '⏳ Reading the photo...',
        'scan_ocr'       => '⏳ No barcode found — reading the TEXT from the photo (may take a few seconds)...',
        'scan_select'    => '👆 Highlight the chassis number with your finger, then press Read',
        'scan_confirm'   => '✅ Read selection',
        'scan_new'       => '📸 New photo',
        'switch_lang'    => 'العربية',
        'chip_hint'      => 'Tap to add a car of this color',
    ],
];
$T = $t[$lang];

/* Build JS-ready shipment data */
$jsShipments = [];
foreach ($shipments as $s) {
    $rows = $colorRows[$s['id']] ?? [];
    $rowsOut = [];
    foreach ($rows as $r) {
        $rowsOut[] = [
            'id'    => (int)$r['id'],
            'color' => $r['color'],
            'label' => $colorName[$r['color']] ?? $r['color'],
        ];
    }
    $years = [ (string)$s['year1'] ];
    if (!empty($s['year2'])) $years[] = (string)$s['year2'];
    $jsShipments[] = [
        'id'         => (int)$s['id'],
        'brand'      => $s['brand'],
        'model'      => $s['model'],
        'trim'       => $s['trim_name'],
        'years'      => $years,
        'qty'        => (int)$s['quantity'],
        'rows'       => $rowsOut,
        'unassigned' => max(0, (int)$s['quantity'] - count($rowsOut)),
    ];
}

$jsColors = [];
foreach ($allColors as $c) {
    $jsColors[] = ['en' => $c['color_en'], 'label' => $lang === 'ar' ? $c['color_ar'] : $c['color_en']];
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#0f172a">
<title><?= $T['title'] ?></title>
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
    color:var(--text); min-height:100vh; padding-bottom:120px;
}
.container { max-width:860px; margin:auto; padding:18px; }

/* ── Header ── */
.page-head {
    display:flex; align-items:center; justify-content:space-between;
    gap:12px; margin-bottom:18px; flex-wrap:wrap;
}
.page-title { font-size:24px; font-weight:900; display:flex; align-items:center; gap:10px; }
.page-sub   { color:var(--muted); font-size:13px; margin-top:2px; }
.head-links { display:flex; gap:8px; flex-wrap:wrap; }
.head-links a {
    color:var(--text); text-decoration:none; font-size:13px; font-weight:700;
    background:rgba(255,255,255,.05); border:1px solid var(--border);
    padding:8px 14px; border-radius:12px; transition:.2s;
}
.head-links a:hover { background:rgba(255,255,255,.1); }

/* ── Cards ── */
.card {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:20px; padding:18px; margin-bottom:16px;
    box-shadow:0 8px 32px rgba(0,0,0,.35);
}
.card h3 {
    font-size:15px; font-weight:800; margin-bottom:12px;
    display:flex; align-items:center; gap:8px;
}
.hint { color:var(--muted); font-size:12.5px; margin-bottom:12px; line-height:1.6; }

/* ── Shipment picker ── */
.ship-pick {
    display:flex; align-items:center; justify-content:space-between; gap:12px;
    background:rgba(255,255,255,.03); border:1px solid var(--border);
    border-radius:16px; padding:14px 16px; margin-bottom:10px;
    cursor:pointer; transition:.2s;
}
.ship-pick:hover { border-color:rgba(34,197,94,.45); background:rgba(34,197,94,.05); }
.ship-pick .sp-name { font-weight:800; font-size:15px; }
.ship-pick .sp-meta { color:var(--muted); font-size:12.5px; margin-top:3px; display:flex; gap:10px; flex-wrap:wrap; }
.sp-btn {
    background:linear-gradient(135deg,var(--green),#16a34a); color:#fff;
    border:none; border-radius:12px; padding:9px 18px; font-weight:800;
    font-size:13.5px; cursor:pointer; font-family:inherit; white-space:nowrap;
}
.empty-state { text-align:center; color:var(--muted); padding:38px 10px; font-size:15px; }

/* ── Selected shipment banner ── */
.sel-banner {
    display:flex; align-items:center; gap:12px;
    background:linear-gradient(120deg,rgba(34,197,94,.10),rgba(37,99,235,.08));
    border:1px solid rgba(34,197,94,.3); border-radius:18px;
    padding:14px 16px; margin-bottom:16px;
}
.sel-banner .sb-icon { font-size:26px; }
.sel-banner .sb-name { font-weight:900; font-size:16px; }
.sel-banner .sb-meta { color:var(--muted); font-size:12.5px; margin-top:2px; }
.sb-change {
    margin-inline-start:auto; background:rgba(255,255,255,.06);
    border:1px solid var(--border); color:var(--text);
    border-radius:10px; padding:7px 12px; font-size:12px; font-weight:700;
    cursor:pointer; font-family:inherit; white-space:nowrap;
}

/* ── Batch settings ── */
.settings-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
@media (max-width:560px){ .settings-grid { grid-template-columns:1fr; } }
.field label { display:block; font-size:12.5px; font-weight:700; color:var(--muted); margin-bottom:6px; }
.field select {
    width:100%; background:#0b1120; color:var(--text);
    border:1px solid var(--border); border-radius:12px;
    padding:12px 14px; font-size:14px; font-family:inherit; outline:none;
}
.field select:focus { border-color:var(--green); }

/* ── Color chips ── */
.chips { display:flex; flex-wrap:wrap; gap:9px; }
.chip {
    display:inline-flex; align-items:center; gap:7px;
    background:rgba(255,255,255,.05); border:1px solid var(--border);
    border-radius:999px; padding:9px 15px; font-size:13.5px; font-weight:700;
    cursor:pointer; transition:.15s; font-family:inherit; color:var(--text);
    user-select:none;
}
.chip:hover  { border-color:var(--green); background:rgba(34,197,94,.08); transform:translateY(-1px); }
.chip:active { transform:scale(.96); }
.chip.used   { opacity:.32; pointer-events:none; }
.chip .swatch {
    width:15px; height:15px; border-radius:50%;
    border:2px solid rgba(255,255,255,.35); flex-shrink:0;
}
.chip .cnt {
    background:rgba(34,197,94,.18); color:var(--green);
    border-radius:999px; font-size:11px; padding:1px 8px; font-weight:800;
}
.chip.chip-unassigned { border-style:dashed; color:var(--muted); }

/* ── Unit rows ── */
.units-list { display:flex; flex-direction:column; gap:10px; margin-top:14px; }
.unit-row {
    display:flex; align-items:center; gap:9px;
    background:rgba(255,255,255,.03); border:1px solid var(--border);
    border-radius:14px; padding:10px 12px;
    animation:slideIn .22s ease both;
}
@keyframes slideIn { from{opacity:0; transform:translateY(6px);} to{opacity:1; transform:none;} }
.unit-color {
    display:flex; align-items:center; gap:6px; font-size:12.5px; font-weight:800;
    white-space:nowrap; min-width:0; flex-shrink:0; max-width:130px; overflow:hidden;
}
.unit-color .swatch { width:13px; height:13px; border-radius:50%; border:2px solid rgba(255,255,255,.35); flex-shrink:0; }
.unit-color span.lbl { overflow:hidden; text-overflow:ellipsis; }
.unit-row select.mini-color {
    background:#0b1120; color:var(--text); border:1px solid var(--border);
    border-radius:10px; padding:9px 10px; font-size:12.5px; font-family:inherit;
    max-width:130px; flex-shrink:0; outline:none;
}
.unit-row input.chassis {
    flex:1; min-width:0; background:#0b1120; color:var(--text);
    border:1px solid var(--border); border-radius:10px;
    padding:11px 12px; font-size:14px; font-family:'Inter',monospace;
    letter-spacing:1px; text-transform:uppercase; outline:none;
}
.unit-row input.chassis:focus { border-color:var(--green); }
.unit-row input.chassis.dup   { border-color:var(--red); background:rgba(239,68,68,.08); }
.btn-scan {
    background:rgba(37,99,235,.15); border:1px solid rgba(37,99,235,.4);
    color:#93c5fd; border-radius:10px; padding:10px 12px; font-size:15px;
    cursor:pointer; font-family:inherit; flex-shrink:0; transition:.15s;
}
.btn-scan:hover { background:rgba(37,99,235,.28); }
.btn-del {
    background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.3);
    color:#fca5a5; border-radius:10px; padding:10px 12px; font-size:13px;
    cursor:pointer; font-family:inherit; flex-shrink:0;
}
.units-empty { text-align:center; color:var(--muted-d); font-size:13px; padding:22px 8px; }

/* ── Sticky confirm bar ── */
.confirm-bar {
    position:fixed; bottom:0; left:0; right:0; z-index:50;
    background:rgba(2,6,23,.92); backdrop-filter:blur(14px);
    border-top:1px solid var(--border); padding:12px 16px;
    display:none;
}
.confirm-bar.show { display:block; }
.confirm-inner {
    max-width:860px; margin:auto; display:flex; align-items:center; gap:14px;
}
.confirm-count { font-size:13px; color:var(--muted); line-height:1.5; }
.confirm-count strong { color:var(--green); font-size:16px; }
.btn-confirm {
    margin-inline-start:auto;
    background:linear-gradient(135deg,var(--green),#16a34a); color:#fff;
    border:none; border-radius:14px; padding:14px 26px;
    font-size:15px; font-weight:900; cursor:pointer; font-family:inherit;
    box-shadow:0 6px 20px rgba(34,197,94,.35); transition:.2s; white-space:nowrap;
}
.btn-confirm:disabled { opacity:.5; cursor:not-allowed; }

/* ── Error box ── */
.err-box {
    display:none; background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.4);
    color:#fecaca; border-radius:14px; padding:13px 15px; font-size:13.5px;
    margin-bottom:14px; line-height:1.7; white-space:pre-line;
}
.err-box.show { display:block; }

/* ── Success screen ── */
.success-wrap { display:none; text-align:center; padding-top:24px; }
.success-wrap.show { display:block; }
.success-icon { font-size:58px; margin-bottom:10px; animation:pop .45s cubic-bezier(.2,1.6,.4,1) both; }
@keyframes pop { from{transform:scale(.3); opacity:0;} to{transform:scale(1); opacity:1;} }
.success-title { font-size:23px; font-weight:900; margin-bottom:6px; }
.success-sub   { color:var(--muted); font-size:14px; margin-bottom:18px; }
.added-list {
    max-width:520px; margin:0 auto 18px; text-align:start;
    display:flex; flex-direction:column; gap:8px;
}
.added-item {
    display:flex; align-items:center; gap:10px;
    background:rgba(34,197,94,.07); border:1px solid rgba(34,197,94,.25);
    border-radius:12px; padding:10px 14px; font-size:13.5px;
    animation:slideIn .25s ease both;
}
.added-item .swatch { width:13px; height:13px; border-radius:50%; border:2px solid rgba(255,255,255,.35); }
.added-item .ch { font-family:'Inter',monospace; letter-spacing:1px; font-weight:700; }
.ship-status {
    font-size:13.5px; font-weight:800; margin-bottom:20px;
    color:var(--green);
}
.success-actions { display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }
.success-actions a, .success-actions button {
    text-decoration:none; font-family:inherit; cursor:pointer;
    font-size:14px; font-weight:800; border-radius:14px; padding:12px 22px;
    border:1px solid var(--border); background:rgba(255,255,255,.05); color:var(--text);
}
.success-actions .primary {
    background:linear-gradient(135deg,var(--green),#16a34a); color:#fff; border:none;
}

/* ── Scanner modal ── */
.scan-modal {
    display:none; position:fixed; inset:0; z-index:100;
    background:rgba(2,6,23,.94); backdrop-filter:blur(6px);
    align-items:center; justify-content:center; padding:18px;
}
.scan-modal.show { display:flex; }
.scan-box {
    width:100%; max-width:440px; background:var(--bg-card);
    border:1px solid var(--border); border-radius:22px; overflow:hidden;
}
.scan-head { padding:15px 18px; display:flex; align-items:center; justify-content:space-between; }
.scan-head .st { font-weight:900; font-size:15px; }
.scan-close {
    background:rgba(255,255,255,.07); border:1px solid var(--border); color:var(--text);
    border-radius:10px; padding:6px 13px; font-size:13px; font-weight:700;
    cursor:pointer; font-family:inherit;
}
#scanner-view { width:100%; min-height:280px; background:#000; }
.scan-hint { padding:12px 18px 16px; color:var(--muted); font-size:12.5px; text-align:center; line-height:1.6; }
.scan-err  { color:#fca5a5; font-size:12.5px; text-align:center; padding:0 18px 14px; display:none; }
.scan-tools {
    display:flex; align-items:center; justify-content:center; gap:10px;
    padding:12px 18px 0; flex-wrap:wrap;
}
.scan-tool-btn {
    background:rgba(37,99,235,.15); border:1px solid rgba(37,99,235,.4);
    color:#93c5fd; border-radius:12px; padding:9px 16px;
    font-size:13px; font-weight:700; cursor:pointer; font-family:inherit;
}
.scan-tool-btn:hover { background:rgba(37,99,235,.28); color:#fff; }
.zoom-row { display:flex; gap:6px; }
.zoom-btn {
    background:rgba(255,255,255,.07); border:1px solid var(--border); color:var(--text);
    border-radius:10px; padding:8px 13px; font-size:12.5px; font-weight:800;
    cursor:pointer; font-family:inherit;
}
.zoom-btn.on { border-color:var(--green); color:var(--green); }
.scan-hero-btn {
    display:block; width:calc(100% - 36px); margin:60px auto;
    background:linear-gradient(135deg,var(--green),#16a34a); color:#fff;
    border:none; border-radius:16px; padding:20px 18px;
    font-size:16px; font-weight:900; cursor:pointer; font-family:inherit;
    box-shadow:0 6px 20px rgba(34,197,94,.35);
}
</style>
</head>
<body>
<div class="container">

    <!-- ══ Header ══ -->
    <div class="page-head">
        <div>
            <div class="page-title">📦 <?= $T['title'] ?></div>
            <div class="page-sub"><?= $T['subtitle'] ?></div>
        </div>
        <div class="head-links">
            <a href="incoming_cars.php?lang=<?= $lang ?>">← <?= $T['back'] ?></a>
            <a href="dashboard.php?lang=<?= $lang ?>">🏠 <?= $T['dashboard'] ?></a>
            <a href="?lang=<?= $lang === 'ar' ? 'en' : 'ar' ?><?= $preselect ? '&id='.$preselect : '' ?>">🌐 <?= $T['switch_lang'] ?></a>
        </div>
    </div>

    <div class="err-box" id="errBox"></div>

    <!-- ══ STEP 1: shipment picker ══ -->
    <div class="card" id="pickerCard">
        <h3>🚚 <?= $T['pick_ship'] ?></h3>
        <div id="pickerList"></div>
    </div>

    <!-- ══ STEP 2: receiving UI ══ -->
    <div id="receiveUI" style="display:none">

        <div class="sel-banner">
            <div class="sb-icon">🚗</div>
            <div>
                <div class="sb-name" id="selName"></div>
                <div class="sb-meta" id="selMeta"></div>
            </div>
            <button class="sb-change" id="btnChange">⇄</button>
        </div>

        <div class="card">
            <h3>⚙️ <?= $T['batch_settings'] ?></h3>
            <div class="settings-grid">
                <div class="field">
                    <label><?= $T['dest_branch'] ?></label>
                    <select id="selBranch">
                        <option value=""><?= $T['select_branch'] ?></option>
                        <?php foreach ($branches as $b): ?>
                        <option value="<?= htmlspecialchars($b['name'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars($lang === 'ar' ? ($b['name_ar'] ?: $b['name']) : ($b['name_en'] ?: $b['name'])) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label><?= $T['model_year'] ?></label>
                    <select id="selYear"></select>
                </div>
            </div>
        </div>

        <div class="card">
            <h3>🎨 <?= $T['units_title'] ?></h3>
            <div class="hint"><?= $T['units_hint'] ?></div>
            <div class="chips" id="chipRow"></div>
            <div class="units-list" id="unitsList">
                <div class="units-empty" id="unitsEmpty"><?= $T['no_units'] ?></div>
            </div>
        </div>
    </div>

    <!-- ══ STEP 3: success ══ -->
    <div class="success-wrap" id="successWrap">
        <div class="success-icon">🎉</div>
        <div class="success-title"><?= $T['success_title'] ?></div>
        <div class="success-sub"><?= $T['success_sub'] ?> <strong id="succBranch"></strong></div>
        <div class="added-list" id="addedList"></div>
        <div class="ship-status" id="shipStatus"></div>
        <div class="success-actions">
            <button class="primary" id="btnMore"><?= $T['receive_more'] ?></button>
            <a href="incoming_cars.php?lang=<?= $lang ?>"><?= $T['go_incoming'] ?></a>
            <a href="dashboard.php?lang=<?= $lang ?>"><?= $T['go_dashboard'] ?></a>
        </div>
    </div>

</div><!-- /container -->

<!-- ══ Sticky confirm bar ══ -->
<div class="confirm-bar" id="confirmBar">
    <div class="confirm-inner">
        <div class="confirm-count">
            <?= $T['receiving_n'] ?> <strong id="cntNum">0</strong> <?= $T['cars_word'] ?><br>
            <span id="leftAfter"></span>
        </div>
        <button class="btn-confirm" id="btnConfirm"><?= $T['confirm'] ?></button>
    </div>
</div>

<!-- ══ Scanner modal ══ -->
<div class="scan-modal" id="scanModal">
    <div class="scan-box">
        <div class="scan-head">
            <div class="st">📷 <?= $T['scan_title'] ?></div>
            <button class="scan-close" id="scanClose"><?= $T['scan_close'] ?></button>
        </div>
        <div id="scanner-view"></div>
        <div class="scan-err" id="scanErr"
             data-msg="<?= htmlspecialchars($T['scan_fail'], ENT_QUOTES) ?>"
             data-msg-photo="<?= htmlspecialchars($T['scan_photo_fail'], ENT_QUOTES) ?>"><?= $T['scan_fail'] ?></div>
        <div class="scan-tools">
            <button type="button" class="scan-tool-btn" id="btnScanPhoto">🖼 <?= $T['scan_photo'] ?></button>
            <div class="zoom-row" id="zoomRow"></div>
        </div>
        <input type="file" accept="image/*" capture="environment" id="scanPhotoInput" style="display:none"
               data-hero="<?= htmlspecialchars($T['scan_use_photo'], ENT_QUOTES) ?>"
               data-reading="<?= htmlspecialchars($T['scan_reading'], ENT_QUOTES) ?>"
               data-ocr="<?= htmlspecialchars($T['scan_ocr'], ENT_QUOTES) ?>"
               data-select="<?= htmlspecialchars($T['scan_select'], ENT_QUOTES) ?>"
               data-confirm="<?= htmlspecialchars($T['scan_confirm'], ENT_QUOTES) ?>"
               data-new="<?= htmlspecialchars($T['scan_new'], ENT_QUOTES) ?>">
        <div class="scan-hint"><?= $T['scan_hint'] ?></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
/* ══════════════════════════════════════════════════════════
   DATA from PHP
══════════════════════════════════════════════════════════ */
const SHIPMENTS  = <?= json_encode($jsShipments, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const ALL_COLORS = <?= json_encode($jsColors,    JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const LANG       = <?= json_encode($lang) ?>;
const PRESELECT  = <?= (int)$preselect ?>;
const TXT = {
    qty:        <?= json_encode($T['qty'],        JSON_UNESCAPED_UNICODE) ?>,
    trim:       <?= json_encode($T['trim'],       JSON_UNESCAPED_UNICODE) ?>,
    select:     <?= json_encode($T['select'],     JSON_UNESCAPED_UNICODE) ?>,
    noShips:    <?= json_encode($T['no_ships'],   JSON_UNESCAPED_UNICODE) ?>,
    unassigned: <?= json_encode($T['unassigned'], JSON_UNESCAPED_UNICODE) ?>,
    pickColor:  <?= json_encode($T['pick_color'], JSON_UNESCAPED_UNICODE) ?>,
    chassisPh:  <?= json_encode($T['chassis_ph'], JSON_UNESCAPED_UNICODE) ?>,
    remove:     <?= json_encode($T['remove'],     JSON_UNESCAPED_UNICODE) ?>,
    scan:       <?= json_encode($T['scan'],       JSON_UNESCAPED_UNICODE) ?>,
    leftAfter:  <?= json_encode($T['left_after'], JSON_UNESCAPED_UNICODE) ?>,
    errBranch:  <?= json_encode($T['err_branch'], JSON_UNESCAPED_UNICODE) ?>,
    errChassis: <?= json_encode($T['err_chassis'],JSON_UNESCAPED_UNICODE) ?>,
    errDupB:    <?= json_encode($T['err_dup_batch'], JSON_UNESCAPED_UNICODE) ?>,
    errDupS:    <?= json_encode($T['err_dup_sys'],   JSON_UNESCAPED_UNICODE) ?>,
    errGeneric: <?= json_encode($T['err_generic'],   JSON_UNESCAPED_UNICODE) ?>,
    confirming: <?= json_encode($T['confirming'],    JSON_UNESCAPED_UNICODE) ?>,
    confirmLbl: <?= json_encode($T['confirm'],       JSON_UNESCAPED_UNICODE) ?>,
    shipDone:   <?= json_encode($T['ship_done'],     JSON_UNESCAPED_UNICODE) ?>,
    shipLeft:   <?= json_encode($T['ship_left'],     JSON_UNESCAPED_UNICODE) ?>,
    carsWord:   <?= json_encode($T['cars_word'],     JSON_UNESCAPED_UNICODE) ?>,
    chipHint:   <?= json_encode($T['chip_hint'],     JSON_UNESCAPED_UNICODE) ?>,
};

/* ══════════════════════════════════════════════════════════
   STATE
══════════════════════════════════════════════════════════ */
let ship      = null;    // selected shipment object
let units     = [];      // {uid, rowId, color, label}
let uidSeq    = 1;
let scanTarget= null;    // uid whose chassis field the scanner fills
let qrScanner = null;

const $ = id => document.getElementById(id);
const esc = s => String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
const swatchCss = c => String(c).toLowerCase().replace(/\s+/g,'');

/* ══════════════════════════════════════════════════════════
   STEP 1 — picker
══════════════════════════════════════════════════════════ */
function renderPicker(){
    const list = $('pickerList');
    if (!SHIPMENTS.length){
        list.innerHTML = `<div class="empty-state">${TXT.noShips}</div>`;
        return;
    }
    list.innerHTML = SHIPMENTS.map(s => `
        <div class="ship-pick" data-id="${s.id}">
            <div>
                <div class="sp-name">🚗 ${esc(s.brand)} ${esc(s.model)}</div>
                <div class="sp-meta">
                    <span>${TXT.trim}: <strong>${esc(s.trim)}</strong></span>
                    <span>📅 ${s.years.join(' / ')}</span>
                    <span>${TXT.qty}: <strong>${s.qty}</strong></span>
                </div>
            </div>
            <button class="sp-btn">📦 ${TXT.select}</button>
        </div>
    `).join('');
    list.querySelectorAll('.ship-pick').forEach(el =>
        el.addEventListener('click', () => selectShipment(+el.dataset.id)));
}

function selectShipment(id){
    ship = SHIPMENTS.find(s => s.id === id);
    if (!ship) return;
    units = [];
    $('pickerCard').style.display = 'none';
    $('successWrap').classList.remove('show');
    $('receiveUI').style.display  = 'block';
    $('selName').textContent = `${ship.brand} ${ship.model}`;
    $('selMeta').textContent = `${TXT.trim}: ${ship.trim} · 📅 ${ship.years.join(' / ')} · ${TXT.qty}: ${ship.qty}`;

    /* year selector */
    $('selYear').innerHTML = ship.years.map(y => `<option value="${esc(y)}">${esc(y)}</option>`).join('');

    renderChips();
    renderUnits();
    hideErr();
    window.scrollTo({top:0, behavior:'smooth'});
}

$('btnChange').addEventListener('click', () => {
    ship = null; units = [];
    $('receiveUI').style.display = 'none';
    $('pickerCard').style.display = 'block';
    updateBar();
});

/* ══════════════════════════════════════════════════════════
   STEP 2 — chips & units
══════════════════════════════════════════════════════════ */
function renderChips(){
    const usedRowIds = units.filter(u => u.rowId > 0).map(u => u.rowId);
    /* group remaining color rows */
    const groups = {};
    ship.rows.forEach(r => {
        if (usedRowIds.includes(r.id)) return;
        (groups[r.color] = groups[r.color] || {label:r.label, ids:[]}).ids.push(r.id);
    });

    let html = Object.entries(groups).map(([color, g]) => `
        <button type="button" class="chip" title="${esc(TXT.chipHint)}"
                data-color="${esc(color)}" data-rowid="${g.ids[0]}">
            <span class="swatch" style="background:${swatchCss(color)}"></span>
            ${esc(g.label)}
            ${g.ids.length > 1 ? `<span class="cnt">x${g.ids.length}</span>` : ''}
        </button>
    `).join('');

    /* unassigned units (quantity beyond assigned color rows) */
    const usedUnassigned = units.filter(u => u.rowId === 0).length;
    const freeUnassigned = Math.max(0, ship.unassigned - usedUnassigned);
    if (freeUnassigned > 0){
        html += `
        <button type="button" class="chip chip-unassigned" data-color="" data-rowid="0">
            <span class="swatch" style="background:repeating-linear-gradient(45deg,#334155,#334155 3px,#0b1120 3px,#0b1120 6px)"></span>
            ${TXT.unassigned}
            ${freeUnassigned > 1 ? `<span class="cnt">x${freeUnassigned}</span>` : ''}
        </button>`;
    }
    $('chipRow').innerHTML = html || `<span class="hint" style="margin:0">—</span>`;
    $('chipRow').querySelectorAll('.chip').forEach(c =>
        c.addEventListener('click', () => addUnit(c.dataset.color, +c.dataset.rowid)));
}

function addUnit(color, rowId){
    /* total cap = shipment quantity */
    if (units.length >= ship.qty) return;
    let label = color;
    if (rowId > 0){
        /* pick the first not-yet-used row of this color */
        const free = ship.rows.find(x => x.color === color && !units.some(u => u.rowId === x.id));
        if (!free) return;
        rowId = free.id;
        label = free.label;
    }
    units.push({ uid: uidSeq++, rowId, color, label });
    renderChips();
    renderUnits(true);
    updateBar();
}

function removeUnit(uid){
    units = units.filter(u => u.uid !== uid);
    renderChips();
    renderUnits();
    updateBar();
}

function renderUnits(focusLast = false){
    const list = $('unitsList');
    /* preserve typed chassis values */
    const typed = {};
    list.querySelectorAll('input.chassis').forEach(i => typed[i.dataset.uid] = i.value);

    if (!units.length){
        list.innerHTML = `<div class="units-empty"><?= $T['no_units'] ?></div>`;
        return;
    }

    list.innerHTML = units.map(u => `
        <div class="unit-row" data-uid="${u.uid}">
            ${u.rowId > 0 ? `
                <div class="unit-color">
                    <span class="swatch" style="background:${swatchCss(u.color)}"></span>
                    <span class="lbl">${esc(u.label)}</span>
                </div>` : `
                <select class="mini-color" data-uid="${u.uid}">
                    <option value="">${TXT.pickColor}</option>
                    ${ALL_COLORS.map(c => `<option value="${esc(c.en)}" ${u.color===c.en?'selected':''}>${esc(c.label)}</option>`).join('')}
                </select>`}
            <input class="chassis" data-uid="${u.uid}" placeholder="${esc(TXT.chassisPh)}"
                   value="${esc(typed[u.uid] || '')}" autocomplete="off" spellcheck="false">
            <button type="button" class="btn-scan" data-uid="${u.uid}" title="${esc(TXT.scan)}">📷</button>
            <button type="button" class="btn-del" data-uid="${u.uid}" title="${esc(TXT.remove)}">✕</button>
        </div>
    `).join('');

    list.querySelectorAll('.btn-del').forEach(b =>
        b.addEventListener('click', () => removeUnit(+b.dataset.uid)));
    list.querySelectorAll('.btn-scan').forEach(b =>
        b.addEventListener('click', () => openScanner(+b.dataset.uid)));
    list.querySelectorAll('select.mini-color').forEach(s =>
        s.addEventListener('change', () => {
            const u = units.find(x => x.uid === +s.dataset.uid);
            if (u){ u.color = s.value; u.label = s.options[s.selectedIndex].text; }
        }));
    list.querySelectorAll('input.chassis').forEach(i => {
        i.addEventListener('input', () => {
            i.value = i.value.toUpperCase();
            markDuplicates();
        });
    });

    if (focusLast){
        const inputs = list.querySelectorAll('input.chassis');
        if (inputs.length) inputs[inputs.length - 1].focus();
    }
    markDuplicates();
}

function markDuplicates(){
    const inputs = [...document.querySelectorAll('input.chassis')];
    const counts = {};
    inputs.forEach(i => { const v = i.value.trim(); if (v) counts[v] = (counts[v]||0)+1; });
    inputs.forEach(i => {
        const v = i.value.trim();
        i.classList.toggle('dup', v !== '' && counts[v] > 1);
    });
}

function updateBar(){
    const bar = $('confirmBar');
    if (!ship || !units.length){ bar.classList.remove('show'); return; }
    bar.classList.add('show');
    $('cntNum').textContent = units.length;
    $('leftAfter').textContent = `${TXT.leftAfter}: ${Math.max(0, ship.qty - units.length)}`;
}

/* ══════════════════════════════════════════════════════════
   Errors
══════════════════════════════════════════════════════════ */
function showErr(msg){
    const b = $('errBox');
    b.textContent = msg;
    b.classList.add('show');
    window.scrollTo({top:0, behavior:'smooth'});
}
function hideErr(){ $('errBox').classList.remove('show'); }

/* ══════════════════════════════════════════════════════════
   STEP 3 — submit
══════════════════════════════════════════════════════════ */
$('btnConfirm').addEventListener('click', async () => {
    hideErr();
    const branch = $('selBranch').value;
    const year   = $('selYear').value;
    if (!branch){ showErr(TXT.errBranch); return; }

    /* collect chassis values */
    const payloadUnits = [];
    let missing = false, dupInBatch = [];
    const seen = {};
    units.forEach(u => {
        const input = document.querySelector(`input.chassis[data-uid="${u.uid}"]`);
        const ch = (input?.value || '').trim().toUpperCase();
        const sel = document.querySelector(`select.mini-color[data-uid="${u.uid}"]`);
        const color = u.rowId > 0 ? u.color : (sel?.value || '');
        if (!ch || !color){ missing = true; return; }
        if (seen[ch]) dupInBatch.push(ch);
        seen[ch] = true;
        payloadUnits.push({ chassis: ch, color, color_row_id: u.rowId });
    });
    if (missing){ showErr(TXT.errChassis); return; }
    if (dupInBatch.length){ showErr(TXT.errDupB + '\n' + [...new Set(dupInBatch)].join('\n')); return; }

    const btn = $('btnConfirm');
    btn.disabled = true; btn.textContent = TXT.confirming;

    try {
        const res  = await fetch(`receive_shipment.php?lang=${LANG}`, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                action: 'receive',
                shipment_id: ship.id,
                branch, year,
                units: payloadUnits,
            })
        });
        const data = await res.json();

        if (!data.ok){
            if (data.error === 'dup_in_system' && data.duplicates){
                showErr(TXT.errDupS + '\n' + data.duplicates.join('\n'));
            } else if (data.error === 'dup_in_batch' && data.duplicates){
                showErr(TXT.errDupB + '\n' + data.duplicates.join('\n'));
            } else {
                showErr(TXT.errGeneric);
            }
            return;
        }

        /* success screen */
        const branchSel = $('selBranch');
        $('succBranch').textContent = branchSel.options[branchSel.selectedIndex].text;
        $('addedList').innerHTML = data.added.map((a,i) => `
            <div class="added-item" style="animation-delay:${i*60}ms">
                <span class="swatch" style="background:${swatchCss(a.color)}"></span>
                <span class="ch">${esc(a.chassis)}</span>
                <span style="color:var(--muted);font-size:12px;margin-inline-start:auto">${esc(a.color)}</span>
            </div>
        `).join('');
        $('shipStatus').textContent = data.ship_deleted
            ? TXT.shipDone
            : `${TXT.shipLeft}: ${data.remaining_qty} ${TXT.carsWord}`;

        $('receiveUI').style.display = 'none';
        $('confirmBar').classList.remove('show');
        $('successWrap').classList.add('show');
        window.scrollTo({top:0, behavior:'smooth'});

        /* refresh local shipment data so "Receive More" works without reload */
        if (data.ship_deleted){
            const idx = SHIPMENTS.findIndex(s => s.id === ship.id);
            if (idx > -1) SHIPMENTS.splice(idx, 1);
        } else {
            const usedRowIds = payloadUnits.filter(u => u.color_row_id > 0).map(u => u.color_row_id);
            ship.rows = ship.rows.filter(r => !usedRowIds.includes(r.id));
            const unassignedUsed = payloadUnits.filter(u => u.color_row_id === 0).length;
            ship.unassigned = Math.max(0, ship.unassigned - unassignedUsed);
            ship.qty = data.remaining_qty;
        }
        units = [];
        renderPicker();

    } catch (e) {
        showErr(TXT.errGeneric);
    } finally {
        btn.disabled = false; btn.textContent = TXT.confirmLbl;
    }
});

$('btnMore').addEventListener('click', () => {
    $('successWrap').classList.remove('show');
    $('pickerCard').style.display = 'block';
    hideErr();
});

/* ══════════════════════════════════════════════════════════
   BARCODE SCANNER (html5-qrcode)
   Reads Code 39 / Code 128 (VIN plate barcodes) + QR.
══════════════════════════════════════════════════════════ */
function showScanErr(extra){
    const b = $('scanErr');
    b.textContent = b.getAttribute('data-msg') + (extra ? ' [' + extra + ']' : '');
    b.style.display = 'block';
}
function showPhotoErr(){
    const b = $('scanErr');
    b.textContent = b.getAttribute('data-msg-photo');
    b.style.display = 'block';
}

/* Ensure the scanner library loaded. Primary: jsdelivr. Fallback: unpkg. */
function ensureLib(){
    return new Promise((resolve, reject) => {
        if (typeof Html5Qrcode !== 'undefined'){ resolve(); return; }
        const s = document.createElement('script');
        s.src = 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js';
        s.onload  = () => (typeof Html5Qrcode !== 'undefined') ? resolve() : reject('lib');
        s.onerror = () => reject('lib');
        document.head.appendChild(s);
    });
}

function getScanConfig(){
    return {
        formatsToSupport: [
            Html5QrcodeSupportedFormats.CODE_39,
            Html5QrcodeSupportedFormats.CODE_128,
            Html5QrcodeSupportedFormats.QR_CODE,
            Html5QrcodeSupportedFormats.DATA_MATRIX,
        ],
        /* THE key line for VIN barcodes: use the phone's native
           ML Kit detector when the browser has one (Android Chrome). */
        experimentalFeatures: { useBarCodeDetectorIfSupported: true },
        verbose: false,
    };
}

function cleanVin(decoded){
    /* Clean typical VIN barcode payloads: some encode a leading "I" or "*" */
    let v = String(decoded).trim().toUpperCase();
    v = v.replace(/^\*+|\*+$/g, '');
    if (v.length === 18 && v.startsWith('I')) v = v.slice(1);

    /* First 1 Car business rule: the internal chassis number is the
       digit group AFTER the last letters of the full VIN.
       e.g. LS5A3DKE2TA991628 -> 991628 */
    const m = v.match(/([0-9]+)$/);
    if (m && m[1].length >= 4) v = m[1];
    return v;
}

    /* ═══ ZBAR ENGINE (WebAssembly build of the ZBar scanner) ═══
       Much stronger at long/thin 1D barcodes (VIN plates) than the
       JS decoder — used as the primary engine for live frames on
       phones without a native detector (iPhone!) and for photos. */
    var zbarLoading = null;
    function ensureZbar(){
        if (window.zbarWasm) return Promise.resolve();
        if (zbarLoading) return zbarLoading;
        zbarLoading = new Promise(function (resolve, reject) {
            var urls = [
                'https://cdn.jsdelivr.net/npm/@undecaf/zbar-wasm@0.11.0/dist/index.js',
                'https://unpkg.com/@undecaf/zbar-wasm@0.11.0/dist/index.js'
            ];
            (function tryUrl(i){
                if (i >= urls.length) { zbarLoading = null; reject('zbar'); return; }
                var s = document.createElement('script');
                s.src = urls[i];
                s.onload  = function(){ window.zbarWasm ? resolve() : tryUrl(i + 1); };
                s.onerror = function(){ tryUrl(i + 1); };
                document.head.appendChild(s);
            })(0);
        });
        return zbarLoading;
    }

    function looksLikeChassis(v){
        return typeof v === 'string' && /^[A-Z0-9]{6,25}$/.test(v);
    }

    /* Scan an ImageData with zbar; return best VIN-like string or null */
    function zbarScan(imageData){
        return window.zbarWasm.scanImageData(imageData).then(function (symbols) {
            var best = null;
            (symbols || []).forEach(function (sym) {
                try {
                    var v = cleanVin(sym.decode());
                    if (looksLikeChassis(v) && (!best || v.length > best.length)) best = v;
                } catch (e) {}
            });
            return best;
        }).catch(function(){ return null; });
    }

    /* ── Live frame loop: grabs the camera frame every 400ms and runs
       zbar on the middle band, in parallel with the built-in decoder ── */
    var zbarTimer = null, zbarBusy = false, zbarTick = 0;
    function startZbarLoop(){
        stopZbarLoop();
        ensureZbar().then(function(){
            zbarTimer = setInterval(function(){
                if (zbarBusy) return;
                var video = document.querySelector('#scanner-view video');
                if (!video || video.readyState < 2 || !video.videoWidth) return;
                zbarBusy = true;
                try {
                    /* grab the middle band of the frame at full stream resolution */
                    var vw = video.videoWidth, vh = video.videoHeight;
                    var bandH = Math.floor(vh * 0.5), bandY = Math.floor((vh - bandH) / 2);
                    var band = document.createElement('canvas');
                    band.width = vw; band.height = bandH;
                    var ctx = band.getContext('2d', { willReadFrequently: true });
                    ctx.drawImage(video, 0, bandY, vw, bandH, 0, 0, vw, bandH);

                    /* cycle enhancement variants — same tricks that make
                       photo mode succeed, applied to live frames */
                    var variant;
                    switch (zbarTick++ % 3) {
                        case 0:  variant = band; break;
                        case 1:  variant = contrastStretch(band); break;
                        default: variant = contrastStretch(centerBand(band, 1.6)); break;
                    }
                    zbarScan(canvasData(variant)).then(function (v) {
                        zbarBusy = false;
                        if (v){ stopZbarLoop(); fillChassis(v); }
                    });
                } catch (e) { zbarBusy = false; }
            }, 350);
        }).catch(function(){ /* zbar unavailable: built-in decoder still runs */ });
    }
    function stopZbarLoop(){
        if (zbarTimer){ clearInterval(zbarTimer); zbarTimer = null; }
        zbarBusy = false;
    }

    /* ── Photo decoding: multiple enhanced variants through zbar ── */
    function fileToImage(file){
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload  = function(){ resolve(img); };
            img.onerror = function(){ URL.revokeObjectURL(url); reject('img'); };
            img.src = url;
        });
    }
    function drawScaled(img, maxSide){
        var scale = Math.min(1, maxSide / Math.max(img.naturalWidth, img.naturalHeight));
        /* never downscale below 1200 on the long side; upscale small images */
        var minSide = 1200;
        if (Math.max(img.naturalWidth, img.naturalHeight) * scale < minSide) {
            scale = minSide / Math.max(img.naturalWidth, img.naturalHeight);
        }
        var c = document.createElement('canvas');
        c.width  = Math.round(img.naturalWidth  * scale);
        c.height = Math.round(img.naturalHeight * scale);
        var ctx = c.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(img, 0, 0, c.width, c.height);
        return c;
    }
    function contrastStretch(canvas){
        var ctx = canvas.getContext('2d', { willReadFrequently: true });
        var d = ctx.getImageData(0, 0, canvas.width, canvas.height);
        var p = d.data, min = 255, max = 0, i, g;
        for (i = 0; i < p.length; i += 4){
            g = (p[i] * 0.299 + p[i+1] * 0.587 + p[i+2] * 0.114) | 0;
            if (g < min) min = g;
            if (g > max) max = g;
        }
        var range = Math.max(1, max - min);
        var c2 = document.createElement('canvas');
        c2.width = canvas.width; c2.height = canvas.height;
        var ctx2 = c2.getContext('2d', { willReadFrequently: true });
        var out = ctx2.createImageData(canvas.width, canvas.height);
        for (i = 0; i < p.length; i += 4){
            g = (p[i] * 0.299 + p[i+1] * 0.587 + p[i+2] * 0.114) | 0;
            g = Math.max(0, Math.min(255, ((g - min) * 255 / range) | 0));
            out.data[i] = out.data[i+1] = out.data[i+2] = g;
            out.data[i+3] = 255;
        }
        ctx2.putImageData(out, 0, 0);
        return c2;
    }
    function centerBand(canvas, factor){
        var bandH = Math.floor(canvas.height * 0.6);
        var y = Math.floor((canvas.height - bandH) / 2);
        var c2 = document.createElement('canvas');
        c2.width  = Math.floor(canvas.width * factor);
        c2.height = Math.floor(bandH * factor);
        var ctx2 = c2.getContext('2d', { willReadFrequently: true });
        ctx2.imageSmoothingEnabled = true;
        ctx2.drawImage(canvas, 0, y, canvas.width, bandH, 0, 0, c2.width, c2.height);
        return c2;
    }
    function canvasData(c){
        return c.getContext('2d', { willReadFrequently: true })
                .getImageData(0, 0, c.width, c.height);
    }
    function decodePhoto(file){
        return Promise.all([ensureZbar(), fileToImage(file)]).then(function (r) {
            var img  = r[1];
            var base = drawScaled(img, 2400);
            var variants = [
                base,
                contrastStretch(base),
                centerBand(base, 1.5),
                contrastStretch(centerBand(base, 2)),
                drawScaled(img, 3600)
            ];
            return (function tryVariant(i){
                if (i >= variants.length) return null;
                return zbarScan(canvasData(variants[i])).then(function (v) {
                    return v ? v : tryVariant(i + 1);
                });
            })(0);
        });
    }

    /* ═══ OCR ENGINE (Tesseract.js) — for text-only plates ═══
       Some plates (e.g. this photo's Changan style) have NO barcode,
       only etched text. When both barcode engines find nothing,
       the photo is read as TEXT. Loaded lazily; cached after first use. */
    var ocrWorker = null, ocrLoading = null;
    function ensureOcr(){
        if (ocrWorker) return Promise.resolve(ocrWorker);
        if (ocrLoading) return ocrLoading;
        ocrLoading = new Promise(function (resolve, reject) {
            function boot(){
                window.Tesseract.createWorker('eng').then(function (w) {
                    return w.setParameters({
                        tessedit_char_whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789*'
                    }).then(function(){ ocrWorker = w; resolve(w); });
                }).catch(function(){ ocrLoading = null; reject('ocr'); });
            }
            if (window.Tesseract){ boot(); return; }
            var urls = [
                'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js',
                'https://unpkg.com/tesseract.js@5/dist/tesseract.min.js'
            ];
            (function tryUrl(i){
                if (i >= urls.length){ ocrLoading = null; reject('ocr'); return; }
                var s = document.createElement('script');
                s.src = urls[i];
                s.onload  = function(){ window.Tesseract ? boot() : tryUrl(i + 1); };
                s.onerror = function(){ tryUrl(i + 1); };
                document.head.appendChild(s);
            })(0);
        });
        return ocrLoading;
    }

    function extractChassisFromText(text){
        var t = String(text || '').toUpperCase();
        var tokens = t.replace(/[^A-Z0-9]+/g, ' ').split(' ').filter(Boolean);
        /* OCR sometimes splits the VIN with a stray space — try merged too */
        if (tokens.length > 1) tokens.push(tokens.join(''));
        var best = null;
        tokens.forEach(function (tok) {
            /* VIN plates never contain I, O or Q — fix classic OCR confusions */
            var v = tok.replace(/O/g, '0').replace(/I/g, '1').replace(/Q/g, '0');
            if (!/^[A-Z0-9]{10,20}$/.test(v)) return;   /* VIN-length token */
            if (!/[0-9]{4,}$/.test(v)) return;          /* must end in the digit group */
            if (!best || v.length > best.length) best = v;
        });
        return best ? cleanVin(best) : null;
    }

    function invertCanvas(canvas){
        var c2 = document.createElement('canvas');
        c2.width = canvas.width; c2.height = canvas.height;
        var ctx2 = c2.getContext('2d', { willReadFrequently: true });
        ctx2.drawImage(canvas, 0, 0);
        var d = ctx2.getImageData(0, 0, c2.width, c2.height), p = d.data;
        for (var i = 0; i < p.length; i += 4){
            p[i] = 255 - p[i]; p[i+1] = 255 - p[i+1]; p[i+2] = 255 - p[i+2];
        }
        ctx2.putImageData(d, 0, 0);
        return c2;
    }

    function ocrCanvas(canvas, psm){
        return ensureOcr().then(function (w) {
            var pre = psm ? w.setParameters({ tessedit_pageseg_mode: psm }) : Promise.resolve();
            return pre.then(function(){
                return w.recognize(canvas);
            }).then(function (res) {
                var v = extractChassisFromText(res && res.data ? res.data.text : '');
                if (!psm) return v;
                /* restore default block mode for whole-photo passes */
                return w.setParameters({ tessedit_pageseg_mode: '6' }).then(function(){ return v; });
            });
        }).catch(function(){ return null; });
    }

    function ocrPhoto(img){
        var base = drawScaled(img, 2000);
        var stretched = contrastStretch(base);
        var variants = [
            stretched,                                   /* enhanced photo   */
            invertCanvas(stretched),                     /* light-on-dark    */
            contrastStretch(centerBand(base, 1.4))       /* zoomed mid strip */
        ];
        return (function tryVariant(i){
            if (i >= variants.length) return Promise.resolve(null);
            return ocrCanvas(variants[i]).then(function (v) {
                return v ? v : tryVariant(i + 1);
            });
        })(0);
    }

    /* ═══ MANUAL TARGETING (last-resort, strongest mode) ═══
       When auto-decode fails, the photo is shown and the user
       highlights the chassis with a finger. Only that strip is
       decoded — upscaled 4x with adaptive thresholding, which
       specifically defeats uneven glare/reflections. */
    var cropImg = null, cropSel = null, cropCanvas = null, cropScale = 1, cropBusy = false;

    /* Adaptive (local mean) threshold — kills uneven reflections */
    function adaptiveThreshold(canvas){
        var ctx = canvas.getContext('2d', { willReadFrequently: true });
        var w = canvas.width, h = canvas.height;
        var d = ctx.getImageData(0, 0, w, h).data;
        var gray = new Float64Array(w * h);
        for (var i = 0, j = 0; i < d.length; i += 4, j++)
            gray[j] = d[i] * 0.299 + d[i+1] * 0.587 + d[i+2] * 0.114;
        var W = w + 1;
        var integ = new Float64Array(W * (h + 1));
        for (var y = 0; y < h; y++){
            var rs = 0;
            for (var x = 0; x < w; x++){
                rs += gray[y * w + x];
                integ[(y + 1) * W + x + 1] = integ[y * W + x + 1] + rs;
            }
        }
        var win = Math.max(15, (Math.min(w, h) / 12) | 0), half = (win / 2) | 0, C = 8;
        var out = document.createElement('canvas');
        out.width = w; out.height = h;
        var octx = out.getContext('2d', { willReadFrequently: true });
        var od = octx.createImageData(w, h);
        for (var y2 = 0; y2 < h; y2++){
            var ya = Math.max(0, y2 - half), yb = Math.min(h - 1, y2 + half);
            for (var x2 = 0; x2 < w; x2++){
                var xa = Math.max(0, x2 - half), xb = Math.min(w - 1, x2 + half);
                var area = (xb - xa + 1) * (yb - ya + 1);
                var sum = integ[(yb + 1) * W + xb + 1] - integ[ya * W + xb + 1]
                        - integ[(yb + 1) * W + xa]     + integ[ya * W + xa];
                var v = gray[y2 * w + x2] > (sum / area - C) ? 255 : 0;
                var k = (y2 * w + x2) * 4;
                od.data[k] = od.data[k+1] = od.data[k+2] = v; od.data[k+3] = 255;
            }
        }
        octx.putImageData(od, 0, 0);
        return out;
    }

    function normSel(){
        if (!cropSel) return null;
        var x = Math.min(cropSel.x0, cropSel.x1), y = Math.min(cropSel.y0, cropSel.y1);
        var w = Math.abs(cropSel.x1 - cropSel.x0), h = Math.abs(cropSel.y1 - cropSel.y0);
        return { x: x, y: y, w: w, h: h };
    }

    function cropDraw(){
        var ctx = cropCanvas.getContext('2d');
        ctx.drawImage(cropImg, 0, 0, cropCanvas.width, cropCanvas.height);
        var s = normSel();
        if (!s) return;
        ctx.fillStyle = 'rgba(0,0,0,0.5)';
        ctx.fillRect(0, 0, cropCanvas.width, s.y);
        ctx.fillRect(0, s.y, s.x, s.h);
        ctx.fillRect(s.x + s.w, s.y, cropCanvas.width - s.x - s.w, s.h);
        ctx.fillRect(0, s.y + s.h, cropCanvas.width, cropCanvas.height - s.y - s.h);
        ctx.strokeStyle = '#22c55e'; ctx.lineWidth = 3;
        ctx.strokeRect(s.x, s.y, s.w, s.h);
    }

    function showCropUI(img){
        cropImg = img; cropSel = null; cropBusy = false;
        var input = document.getElementById('scanPhotoInput');
        var view  = document.getElementById('scanner-view');
        view.innerHTML = '';

        var hint = document.createElement('div');
        hint.style.cssText = 'color:#94a3b8;font-size:12.5px;text-align:center;padding:10px 16px 6px;line-height:1.6;';
        hint.textContent = input.getAttribute('data-select');
        view.appendChild(hint);

        cropCanvas = document.createElement('canvas');
        var maxW = Math.max(280, (view.clientWidth || 400) - 24);
        cropScale = maxW / img.naturalWidth;
        cropCanvas.width  = maxW;
        cropCanvas.height = Math.round(img.naturalHeight * cropScale);
        cropCanvas.style.cssText = 'display:block;width:calc(100% - 24px);margin:0 auto;border-radius:12px;touch-action:none;cursor:crosshair;max-height:52vh;object-fit:contain;';
        view.appendChild(cropCanvas);

        var row = document.createElement('div');
        row.style.cssText = 'display:flex;gap:10px;justify-content:center;padding:12px;flex-wrap:wrap;';
        var ok = document.createElement('button');
        ok.type = 'button'; ok.className = 'scan-hero-btn';
        ok.style.cssText = 'margin:0;width:auto;padding:13px 22px;font-size:14px;';
        ok.textContent = input.getAttribute('data-confirm');
        ok.addEventListener('click', decodeCrop);
        var again = document.createElement('button');
        again.type = 'button'; again.className = 'scan-tool-btn';
        again.textContent = input.getAttribute('data-new');
        again.addEventListener('click', function(){ input.click(); });
        row.appendChild(ok); row.appendChild(again);
        view.appendChild(row);

        function pos(e){
            var r = cropCanvas.getBoundingClientRect();
            return {
                x: (e.clientX - r.left) * (cropCanvas.width  / r.width),
                y: (e.clientY - r.top)  * (cropCanvas.height / r.height)
            };
        }
        var dragging = false;
        cropCanvas.addEventListener('pointerdown', function(e){
            e.preventDefault();
            cropCanvas.setPointerCapture(e.pointerId);
            var p = pos(e);
            cropSel = { x0: p.x, y0: p.y, x1: p.x, y1: p.y };
            dragging = true; cropDraw();
        });
        cropCanvas.addEventListener('pointermove', function(e){
            if (!dragging) return;
            var p = pos(e);
            cropSel.x1 = Math.max(0, Math.min(cropCanvas.width,  p.x));
            cropSel.y1 = Math.max(0, Math.min(cropCanvas.height, p.y));
            cropDraw();
        });
        cropCanvas.addEventListener('pointerup', function(){ dragging = false; });

        cropDraw();
    }

    function decodeCrop(){
        if (cropBusy) return;
        var s = normSel();
        if (!s || s.w < 25 || s.h < 10) return;   /* need a real selection */
        cropBusy = true;

        /* map selection back to the ORIGINAL full-resolution photo */
        var sx = s.x / cropScale, sy = s.y / cropScale;
        var sw = s.w / cropScale, sh = s.h / cropScale;
        var f  = Math.min(6, Math.max(1.5, 1800 / sw));   /* heavy upscale */
        var c  = document.createElement('canvas');
        c.width  = Math.round(sw * f);
        c.height = Math.round(sh * f);
        c.getContext('2d', { willReadFrequently: true })
         .drawImage(cropImg, sx, sy, sw, sh, 0, 0, c.width, c.height);

        var input = document.getElementById('scanPhotoInput');
        var view  = document.getElementById('scanner-view');
        view.innerHTML = '<div style="color:#94a3b8;font-size:13px;text-align:center;padding:60px 18px;line-height:1.6;">'
                       + input.getAttribute('data-reading') + '</div>';

        var stretched = contrastStretch(c);
        var thresh    = adaptiveThreshold(c);

        /* barcode engines on the strip first (fast), then OCR in
           single-line mode — the strip IS a single line */
        (function run(){
            return zbarScan(canvasData(c)).then(function (v) {
                return v || zbarScan(canvasData(stretched));
            }).then(function (v) {
                return v || zbarScan(canvasData(thresh));
            }).then(function (v) {
                if (v) return v;
                return ocrCanvas(stretched, '7').then(function (v2) {
                    return v2 || ocrCanvas(invertCanvas(stretched), '7');
                }).then(function (v2) {
                    return v2 || ocrCanvas(thresh, '7');
                });
            });
        })().then(function (v) {
            cropBusy = false;
            if (v){ fillChassis(v); return; }
            showPhotoErr();
            showCropUI(cropImg);   /* keep the photo — try a tighter selection */
        }).catch(function(){
            cropBusy = false;
            showPhotoErr();
            showCropUI(cropImg);
        });
    }




function fillChassis(v){
    const input = document.querySelector(`input.chassis[data-uid="${scanTarget}"]`);
    if (input){ input.value = v; markDuplicates(); }
    if (navigator.vibrate) navigator.vibrate(80);
    closeScanner();
}

function setupZoom(){
    const row = $('zoomRow');
    row.innerHTML = '';
    try {
        const caps = qrScanner.getRunningTrackCapabilities();
        if (!caps || !caps.zoom) return;
        const zmin = caps.zoom.min || 1, zmax = caps.zoom.max || 1;
        let first = true;
        [1, 2, 3, 4].forEach(z => {
            if (z < zmin || z > zmax) return;
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'zoom-btn' + (first ? ' on' : '');
            first = false;
            b.textContent = z + 'x';
            b.addEventListener('click', () => {
                try {
                    qrScanner.applyVideoConstraints({ advanced: [{ zoom: z }] });
                    row.querySelectorAll('.zoom-btn').forEach(x => x.classList.remove('on'));
                    b.classList.add('on');
                } catch(e){}
            });
            row.appendChild(b);
        });
    } catch(e){}
}

function startCamera(){
    const runCfg   = { fps: 10, qrbox: (w, h) => ({ width: Math.floor(w * .9), height: Math.floor(h * .5) }) };
    const onDecode = d => fillChassis(cleanVin(d));

    /* Retry chain — some phones reject resolution constraints,
       some reject facingMode; try progressively simpler configs,
       then an explicit camera id, before giving up on live video. */
    const attempts = [
        /* 4K first — modern phones deliver it and thin VIN bars need pixels */
        { facingMode: 'environment', width: { ideal: 3840 }, height: { ideal: 2160 } },
        { facingMode: 'environment', width: { ideal: 1920 }, height: { ideal: 1080 } },
        { facingMode: 'environment' },
    ];
    let lastErr = null;

    const cleanupScanner = () => {
        if (qrScanner){ try { qrScanner.clear(); } catch(e){} qrScanner = null; }
    };
    const tryAt = i => {
        if (i >= attempts.length){ tryById(); return; }
        qrScanner = new Html5Qrcode('scanner-view', getScanConfig());
        qrScanner.start(attempts[i], runCfg, onDecode, () => {})
            .then(function(){ setupZoom(); startZbarLoop(); })
            .catch(err => { lastErr = err; cleanupScanner(); tryAt(i + 1); });
    };
    const tryById = () => {
        Html5Qrcode.getCameras().then(cams => {
            if (!cams || !cams.length) throw (lastErr || new Error('no camera'));
            /* last device is almost always the main back camera */
            qrScanner = new Html5Qrcode('scanner-view', getScanConfig());
            return qrScanner.start(cams[cams.length - 1].id, runCfg, onDecode, () => {})
                            .then(function(){ setupZoom(); startZbarLoop(); });
        }).catch(err => {
            cleanupScanner();
            liveFailed(err || lastErr);
        });
    };
    tryAt(0);
}

/* Live video is impossible on this device/browser →
   switch to native photo capture, which always works. */
function showPhotoHero(){
    const view = document.getElementById('scanner-view');
    view.innerHTML = '';
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'scan-hero-btn';
    b.textContent = $('scanPhotoInput').getAttribute('data-hero');
    b.addEventListener('click', () => $('scanPhotoInput').click());
    view.appendChild(b);
}
function liveFailed(err){
    showScanErr(err && err.name ? err.name : String(err).slice(0, 80));
    showPhotoHero();
}

function stopCamera(){
    stopZbarLoop();
    return new Promise(resolve => {
        if (!qrScanner){ resolve(); return; }
        const s = qrScanner; qrScanner = null;
        try {
            s.stop().then(() => { s.clear(); resolve(); }).catch(() => resolve());
        } catch(e){ resolve(); }
    });
}

function openScanner(uid){
    /* PHOTO-FIRST: live-video decoding of VIN plates proved unreliable,
       so the 📷 button now goes straight to the phone's native camera.
       The modal stays behind as the retry / progress / error surface. */
    scanTarget = uid;
    $('scanErr').style.display = 'none';
    $('zoomRow').innerHTML = '';
    $('scanModal').classList.add('show');
    showPhotoHero();
    $('scanPhotoInput').click();
}

function closeScanner(){
    $('scanModal').classList.remove('show');
    stopCamera();
}

/* ── 🖼 Scan from a photo (most reliable for VIN plates) ── */
$('btnScanPhoto').addEventListener('click', () => {
    ensureLib().then(() => $('scanPhotoInput').click())
               .catch(() => showScanErr('library blocked — check internet / ad blocker'));
});
$('scanPhotoInput').addEventListener('change', function(){
    const file = this.files && this.files[0];
    this.value = '';
    if (!file) return;
    $('scanErr').style.display = 'none';
    stopCamera().then(() => {
        const view = document.getElementById('scanner-view');
        view.innerHTML = '<div class="scan-hint" style="padding:60px 18px">'
                       + $('scanPhotoInput').getAttribute('data-reading') + '</div>';

        /* 1st engine: zbar (multiple enhanced variants of the photo) */
        decodePhoto(file).then(v => {
            if (v){ fillChassis(v); return; }

            /* 2nd engine: html5-qrcode scanFile as fallback */
            view.innerHTML = '';
            const fs = new Html5Qrcode('scanner-view', getScanConfig());
            fs.scanFile(file, /* showImage */ true)
              .then(decoded => {
                  try { fs.clear(); } catch(e){}
                  fillChassis(cleanVin(decoded));
              })
              .catch(() => {
                  try { fs.clear(); } catch(e){}
                  /* 3rd engine: OCR — the plate may have no barcode
                     at all (text-only plates). Read it as text. */
                  view.innerHTML = '<div class="scan-hint" style="padding:60px 18px">'
                                 + $('scanPhotoInput').getAttribute('data-ocr') + '</div>';
                  fileToImage(file)
                    .then(img => ocrPhoto(img).then(v => {
                        if (v){ fillChassis(v); return; }
                        /* 4th stage: MANUAL TARGETING — show the photo,
                           user highlights the chassis, only that strip
                           is decoded with heavy enhancement. */
                        showCropUI(img);
                    }))
                    .catch(() => {
                        showPhotoErr();
                        showPhotoHero();
                    });
              });
        }).catch(() => {
            showPhotoErr();
            showPhotoHero();
        });
    });
});
$('scanClose').addEventListener('click', closeScanner);
$('scanModal').addEventListener('click', e => { if (e.target === $('scanModal')) closeScanner(); });

/* ══════════════════════════════════════════════════════════
   INIT
══════════════════════════════════════════════════════════ */
renderPicker();
if (PRESELECT > 0 && SHIPMENTS.some(s => s.id === PRESELECT)) selectShipment(PRESELECT);
</script>
</body>
</html>
