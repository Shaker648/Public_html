<?php
/*
 * consignment_return.php — returns an امانة (consignment) car to stock.
 *
 * Admin/manager only. Called from the dashboard "Return Car" modal.
 *   - Verifies the car has an ACTIVE consignment.
 *   - Moves the car to the chosen destination branch.
 *   - Sets status back to 'available' (reappears normally for everyone).
 *   - Closes the consignment row (status='returned').
 *   - Logs a 'amana_return' movement for the timeline.
 */

require 'auth.php';
require 'config.php';

$lang = $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar', 'en'], true)) $lang = 'ar';

// Permission-gated (default: admin / manager)
perm_require('page.consignment_return');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php?lang=' . $lang);
    exit;
}

$car_id        = (int)($_POST['car_id'] ?? 0);
$return_branch = trim($_POST['return_branch'] ?? '');

if ($car_id <= 0 || $return_branch === '') {
    header('Location: dashboard.php?lang=' . $lang);
    exit;
}

// Validate the destination branch exists
$bChk = $pdo->prepare("SELECT name FROM branches WHERE name = ? LIMIT 1");
$bChk->execute([$return_branch]);
if (!$bChk->fetchColumn()) {
    header('Location: dashboard.php?lang=' . $lang);
    exit;
}

// Car must currently be on consignment
$stmt = $pdo->prepare("SELECT * FROM cars WHERE id = ? AND status = 'consignment' LIMIT 1");
$stmt->execute([$car_id]);
$car = $stmt->fetch(PDO::FETCH_ASSOC);

if ($car) {
    try {
        $pdo->beginTransaction();

        $from_branch = $car['branch'];

        // 1) Car back to available, at the chosen branch
        $pdo->prepare("UPDATE cars SET status = 'available', branch = ? WHERE id = ?")
            ->execute([$return_branch, $car_id]);

        // 2) Close the active consignment
        $pdo->prepare("
            UPDATE consignments
            SET status = 'returned', closed_at = NOW(), closed_by = ?, return_branch = ?
            WHERE car_id = ? AND status = 'active'
        ")->execute([$_SESSION['username'], $return_branch, $car_id]);

        // 3) Movement log for the timeline
        $pdo->prepare("
            INSERT INTO movements
                (car_id, from_branch, to_branch, moved_by, notes, event_type)
            VALUES (?, ?, ?, ?, ?, 'amana_return')
        ")->execute([
            $car_id,
            $from_branch,
            $return_branch,
            $_SESSION['username'],
            '',
        ]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Consignment return failed: ' . $e->getMessage());
    }
}

header('Location: dashboard.php?lang=' . $lang);
exit;
