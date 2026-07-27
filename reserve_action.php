<?php
/*
 * reserve_action.php — حجز السيارة / إلغاء الحجز
 *
 * POST-only, CSRF-protected, permission-gated. No data entry is required:
 * one click reserves the car, one click releases it.
 *
 *   action=reserve : available car  → 'reserved'  (stays in the same branch,
 *                    stays visible in stock, painted gold everywhere)
 *   action=cancel  : reserved car   → 'available' (back to normal)
 *
 * Both log a movement so the vehicle's journey shows who reserved / released
 * it and when. Selling a reserved car is NOT handled here — the "تم البيع"
 * button just opens the normal sale page (sold_vehicle.php).
 */

require 'auth.php';
require 'config.php';
require 'reserve_helpers.php';

$lang = $_POST['lang'] ?? $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar', 'en'], true)) $lang = 'ar';

$back = $_POST['back'] ?? 'dashboard.php';
// Only allow returning to known in-app pages (never an attacker-supplied URL).
if (!in_array($back, ['dashboard.php', 'stock_report.php'], true)) {
    $back = 'dashboard.php';
}

function reserve_redirect(string $back, string $lang, string $flash = ''): void
{
    header('Location: ' . $back . '?lang=' . $lang . ($flash !== '' ? '&' . $flash . '=1' : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reserve_redirect($back, $lang);
}

// CSRF
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    die('Invalid CSRF token');
}

$action = $_POST['action'] ?? '';
$car_id = (int)($_POST['car_id'] ?? 0);

if ($car_id <= 0) {
    reserve_redirect($back, $lang);
}

$cs = $pdo->prepare("SELECT * FROM cars WHERE id = ? LIMIT 1");
$cs->execute([$car_id]);
$car = $cs->fetch(PDO::FETCH_ASSOC);

if (!$car) {
    reserve_redirect($back, $lang);
}

/* ─────────────────────────── RESERVE ─────────────────────────── */
if ($action === 'reserve') {

    if (!can('reserve.create')) { http_response_code(403); die('Access Denied'); }

    // Only a normally-available car can be reserved.
    if (($car['status'] ?? '') !== 'available') {
        reserve_redirect($back, $lang);
    }

    if (!ensure_reserved_status($pdo)) {
        error_log('reserve: cars.status cannot store "reserved"');
        reserve_redirect($back, $lang, 'res_err');
    }

    try {
        $pdo->beginTransaction();

        // Status only — the car does NOT move, it stays in its branch.
        $pdo->prepare("UPDATE cars SET status = 'reserved' WHERE id = ?")->execute([$car_id]);

        $pdo->prepare("
            INSERT INTO movements
                (car_id, from_branch, to_branch, moved_by, notes, event_type)
            VALUES (?, ?, ?, ?, '', 'reserved')
        ")->execute([$car_id, $car['branch'], $car['branch'], $_SESSION['username'] ?? '']);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Reserve failed: ' . $e->getMessage());
        reserve_redirect($back, $lang, 'res_err');
    }

    reserve_redirect($back, $lang, 'reserved');
}

/* ─────────────────────────── CANCEL ──────────────────────────── */
if ($action === 'cancel') {

    if (!can('reserve.cancel')) { http_response_code(403); die('Access Denied'); }

    // Only a reserved car can be released.
    if (($car['status'] ?? '') !== 'reserved') {
        reserve_redirect($back, $lang);
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare("UPDATE cars SET status = 'available' WHERE id = ?")->execute([$car_id]);

        $pdo->prepare("
            INSERT INTO movements
                (car_id, from_branch, to_branch, moved_by, notes, event_type)
            VALUES (?, ?, ?, ?, '', 'reserve_cancel')
        ")->execute([$car_id, $car['branch'], $car['branch'], $_SESSION['username'] ?? '']);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Reserve cancel failed: ' . $e->getMessage());
        reserve_redirect($back, $lang, 'res_err');
    }

    reserve_redirect($back, $lang, 'unreserved');
}

reserve_redirect($back, $lang);
