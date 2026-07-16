<?php
/*
 * sale_action.php — revert a sold car back to stock, or edit the buyer on a sale.
 *
 * Both actions are POST-only, CSRF-protected, and permission-gated (admin only
 * by default — see sold.revert / sold.edit in the Permission Center).
 *
 *   action=revert : car goes back to 'available' (keeps its branch); the sold
 *                   record is flagged 'returned' (kept for history); a
 *                   'sale_return' movement is logged so the vehicle timeline
 *                   shows "sold to X → returned to stock".
 *
 *   action=edit   : updates who the car was sold to (customer name/phone or
 *                   dealer name) on the sold record.
 *
 * Always redirects back to the sold page.
 */

require 'auth.php';
require 'config.php';
require 'sold_helpers.php';

$lang = $_POST['lang'] ?? $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar', 'en'], true)) $lang = 'ar';

function back_to_sold(string $lang, string $flash = ''): void
{
    $q = 'sold_inventory.php?lang=' . $lang . ($flash !== '' ? '&' . $flash . '=1' : '');
    header('Location: ' . $q);
    exit;
}

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back_to_sold($lang);
}

// CSRF
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    die('Invalid CSRF token');
}

$action  = $_POST['action']  ?? '';
$sold_id = (int)($_POST['sold_id'] ?? 0);

if ($sold_id <= 0) {
    back_to_sold($lang);
}

$colOk = ensure_sold_revert_columns($pdo);

// Load the sold record
$s = $pdo->prepare("SELECT * FROM sold_cars WHERE id = ? LIMIT 1");
$s->execute([$sold_id]);
$sold = $s->fetch(PDO::FETCH_ASSOC);

if (!$sold) {
    back_to_sold($lang);
}

/* ─────────────────────────── REVERT ─────────────────────────── */
if ($action === 'revert') {

    if (!can('sold.revert')) { http_response_code(403); die('Access Denied'); }

    // Only an active (not already returned) sale can be reverted.
    if ($colOk && ($sold['status'] ?? 'sold') !== 'sold') {
        back_to_sold($lang);
    }

    $car_id = (int)$sold['car_id'];

    $cs = $pdo->prepare("SELECT * FROM cars WHERE id = ? LIMIT 1");
    $cs->execute([$car_id]);
    $car = $cs->fetch(PDO::FETCH_ASSOC);

    // The car must currently be marked sold.
    if (!$car || ($car['status'] ?? '') !== 'sold') {
        back_to_sold($lang);
    }

    // Buyer summary — stored on the movement so the timeline has context.
    $buyer = ($sold['sale_type'] ?? '') === 'dealer'
        ? trim((string)($sold['dealer_name'] ?? ''))
        : trim(((string)($sold['customer_name'] ?? '')) . ' ' . ((string)($sold['customer_phone'] ?? '')));
    $buyer = trim($buyer);

    try {
        $pdo->beginTransaction();

        // 1) Car back to available (stays at its current branch)
        $pdo->prepare("UPDATE cars SET status = 'available' WHERE id = ?")->execute([$car_id]);

        // 2) Flag the sold record as returned (kept for history)
        if ($colOk) {
            $pdo->prepare("
                UPDATE sold_cars
                SET status = 'returned', returned_at = NOW(), returned_by = ?
                WHERE id = ?
            ")->execute([$_SESSION['username'] ?? '', $sold_id]);
        }

        // 3) Log the return on the timeline
        $pdo->prepare("
            INSERT INTO movements
                (car_id, from_branch, to_branch, moved_by, notes, event_type)
            VALUES (?, ?, ?, ?, ?, 'sale_return')
        ")->execute([
            $car_id,
            $car['branch'],
            $car['branch'],
            $_SESSION['username'] ?? '',
            $buyer,
        ]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Sale revert failed: ' . $e->getMessage());
        back_to_sold($lang);
    }

    back_to_sold($lang, 'reverted');
}

/* ──────────────────────────── EDIT ──────────────────────────── */
if ($action === 'edit') {

    if (!can('sold.edit')) { http_response_code(403); die('Access Denied'); }

    $customer_name  = trim($_POST['customer_name']  ?? '');
    $customer_phone = trim($_POST['customer_phone'] ?? '');
    $dealer_name    = trim($_POST['dealer_name']    ?? '');

    try {
        // Only the buyer fields are editable here — nothing else about the sale.
        $pdo->prepare("
            UPDATE sold_cars
            SET customer_name = ?, customer_phone = ?, dealer_name = ?
            WHERE id = ?
        ")->execute([$customer_name, $customer_phone, $dealer_name, $sold_id]);
    } catch (Exception $e) {
        error_log('Sale edit failed: ' . $e->getMessage());
        back_to_sold($lang);
    }

    back_to_sold($lang, 'edited');
}

back_to_sold($lang);
