<?php
/*
 * notify_act.php — the buttons inside a phone notification.
 *
 *   ?a=ack&t=…    «👍 تمام» on a message from management
 *   ?a=recv&t=…   «✅ تم الاستلام» on "a car is on its way to your branch"
 *   ?a=miss&t=…   «❌ لم تصل» — the car never arrived: the admin is told
 *
 * t is a private random code that belongs to one person's copy of one
 * notification, so the button works straight from the lock screen — even if
 * the phone was logged out. With &bg=1 (tapped from the notification) it
 * answers JSON; otherwise it opens the system on the right page.
 */
require __DIR__ . '/config.php';
require_once __DIR__ . '/notify_smart.php';

$a  = (string)($_GET['a'] ?? '');
$t  = (string)($_GET['t'] ?? '');
$bg = !empty($_GET['bg']);
$ok = false;
$go = 'dashboard.php';

try {
    push_tables($pdo);
    if (preg_match('/^[a-f0-9]{24}$/', $t)) {
        $st = $pdo->prepare("SELECT i.id, i.user_id, l.event, l.ref, u.username FROM notify_inbox i
                             JOIN notify_log l ON l.id = i.log_id JOIN users u ON u.id = i.user_id
                             WHERE i.tok = ? AND u.active = 1 LIMIT 1");
        $st->execute([$t]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $lang = notify_options($pdo)['lang'];
            if ($a === 'ack' && $row['event'] === 'message') {
                $pdo->prepare("UPDATE notify_inbox SET ack_at = COALESCE(ack_at, NOW()), read_at = COALESCE(read_at, NOW()), seen_at = COALESCE(seen_at, NOW()) WHERE id = ?")
                    ->execute([(int)$row['id']]);
                $ok = true;
            } elseif ($a === 'miss' && $row['event'] === 'transfer_incoming' && strpos((string)$row['ref'], 'mv:') === 0) {
                foreach (explode(',', substr((string)$row['ref'], 3)) as $mid) smart_report_missing($pdo, (int)$mid, (string)$row['username'], '');
                $pdo->prepare("UPDATE notify_inbox SET seen_at = COALESCE(seen_at, NOW()), read_at = COALESCE(read_at, NOW()) WHERE id = ?")->execute([(int)$row['id']]);
                $ok = true;
                $go = 'transfer_receive.php?lang=' . $lang;
            } elseif ($a === 'recv' && $row['event'] === 'transfer_incoming' && strpos((string)$row['ref'], 'mv:') === 0) {
                smart_receive($pdo, explode(',', substr((string)$row['ref'], 3)), (string)$row['username']);
                $pdo->prepare("UPDATE notify_inbox SET seen_at = COALESCE(seen_at, NOW()), read_at = COALESCE(read_at, NOW()) WHERE id = ?")->execute([(int)$row['id']]);
                $ok = true;
                $go = 'transfer_receive.php?lang=' . $lang;
            }
        }
    }
} catch (Throwable $e) {
    error_log('notify_act: ' . $e->getMessage());
}

if ($bg) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => $ok]);
    exit;
}
header('Location: ' . $go);
