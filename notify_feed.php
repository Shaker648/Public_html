<?php
/*
 * notify_feed.php — the in-system side of notifications (used by notify_popup.php).
 *
 *   list  → what this person hasn't seen yet in the system:
 *             • normal notifications: returned ONCE, then marked seen
 *             • custom messages from the admin: returned until opened (read)
 *           (?msg=ID also returns that one message, so a tap on the phone
 *            notification opens it even if it was already read)
 *   read  → a custom message was opened: it won't show again
 *   ack   → «تمام» on a message that asks for it
 *   recv  → «استلمت» on "a car is on its way to your branch"
 *
 * Also answers the unread count (the red number on the app icon) and, as a
 * backup for the cron job, lets the automatic reminders run.
 */
require 'auth.php';
require 'config.php';
require_once __DIR__ . '/notify_smart.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function feed_out(array $a): void { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$uid = (int)($_SESSION['user_id'] ?? 0);
if (!$uid) feed_out(['ok' => false, 'error' => 'auth']);

try {
    push_tables($pdo);
    $action = $_GET['action'] ?? '';
    $in = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = (string)($in['action'] ?? '');
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)($in['csrf'] ?? ''))) feed_out(['ok' => false, 'error' => 'csrf']);
    }

    $cols = "i.id, i.log_id, l.event, l.title, l.body, l.url, l.actor, l.need_ack, i.ack_at, TIMESTAMPDIFF(SECOND, l.created_at, NOW()) AS age";

    if ($action === 'list') {
        // custom messages still waiting to be read (newest first)
        $st = $pdo->prepare("SELECT $cols FROM notify_inbox i JOIN notify_log l ON l.id = i.log_id
                             WHERE i.user_id = ? AND l.event = 'message' AND (i.read_at IS NULL OR (l.need_ack = 1 AND i.ack_at IS NULL))
                             ORDER BY l.id DESC LIMIT 10");
        $st->execute([$uid]);
        $msgs = $st->fetchAll(PDO::FETCH_ASSOC);

        // the one tapped on the phone, even if read before
        $open = (int)($_GET['msg'] ?? 0);
        if ($open && !in_array($open, array_map('intval', array_column($msgs, 'log_id')), true)) {
            $st = $pdo->prepare("SELECT $cols FROM notify_inbox i JOIN notify_log l ON l.id = i.log_id
                                 WHERE i.user_id = ? AND l.event = 'message' AND l.id = ? LIMIT 1");
            $st->execute([$uid, $open]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) array_unshift($msgs, $r);
        }

        // normal notifications not shown in the system yet — shown once
        $st = $pdo->prepare("SELECT $cols FROM notify_inbox i JOIN notify_log l ON l.id = i.log_id
                             WHERE i.user_id = ? AND i.seen_at IS NULL AND l.event NOT IN ('message', 'test') ORDER BY l.id DESC LIMIT 6");
        $st->execute([$uid]);
        $events = $st->fetchAll(PDO::FETCH_ASSOC);
        // a stock check sends one update per car — show only the latest one for each check
        $seenChk = [];
        $events = array_values(array_filter($events, function ($e) use (&$seenChk) {
            if ($e['event'] !== 'check_item') return true;
            if (isset($seenChk[$e['url']])) return false;
            return $seenChk[$e['url']] = true;
        }));
        $st = $pdo->prepare("SELECT COUNT(*) FROM notify_inbox i JOIN notify_log l ON l.id = i.log_id
                             WHERE i.user_id = ? AND i.seen_at IS NULL AND l.event NOT IN ('message', 'test')");
        $st->execute([$uid]);
        $total = (int)$st->fetchColumn();
        // everything returned (and anything older still waiting) now counts as seen
        $pdo->prepare("UPDATE notify_inbox i JOIN notify_log l ON l.id = i.log_id SET i.seen_at = NOW()
                       WHERE i.user_id = ? AND i.seen_at IS NULL AND l.event <> 'message'")->execute([$uid]);
        $pdo->prepare("UPDATE notify_inbox i JOIN notify_log l ON l.id = i.log_id SET i.seen_at = NOW()
                       WHERE i.user_id = ? AND i.seen_at IS NULL AND l.event = 'message'")->execute([$uid]);

        $clean = function (array $r): array {
            return ['id' => (int)$r['id'], 'log' => (int)$r['log_id'], 'event' => $r['event'], 'title' => (string)$r['title'],
                    'body' => (string)$r['body'], 'url' => (string)$r['url'], 'by' => (string)$r['actor'], 'age' => max(0, (int)$r['age']),
                    'ack' => (int)$r['need_ack'] === 1 && $r['ack_at'] === null];
        };
        smart_run_maybe($pdo);
        feed_out(['ok' => true, 'messages' => array_map($clean, $msgs), 'events' => array_map($clean, $events), 'more' => max(0, $total - count($events)),
                  'badge' => notify_unread_counts($pdo, [$uid])[$uid] ?? 0]);
    }

    if ($action === 'ack') {
        $pdo->prepare("UPDATE notify_inbox SET ack_at = COALESCE(ack_at, NOW()), read_at = COALESCE(read_at, NOW()), seen_at = COALESCE(seen_at, NOW()) WHERE id = ? AND user_id = ?")
            ->execute([(int)($in['id'] ?? 0), $uid]);
        feed_out(['ok' => true]);
    }

    if ($action === 'recv') {
        $st = $pdo->prepare("SELECT l.ref FROM notify_inbox i JOIN notify_log l ON l.id = i.log_id WHERE i.id = ? AND i.user_id = ? AND l.event = 'transfer_incoming'");
        $st->execute([(int)($in['id'] ?? 0), $uid]);
        $ref = (string)$st->fetchColumn();
        $n = strpos($ref, 'mv:') === 0 ? smart_receive($pdo, explode(',', substr($ref, 3)), (string)$_SESSION['username']) : 0;
        feed_out(['ok' => true, 'n' => $n]);
    }

    if ($action === 'missing') {
        $st = $pdo->prepare("SELECT l.ref FROM notify_inbox i JOIN notify_log l ON l.id = i.log_id WHERE i.id = ? AND i.user_id = ? AND l.event = 'transfer_incoming'");
        $st->execute([(int)($in['id'] ?? 0), $uid]);
        $ref = (string)$st->fetchColumn();
        $n = 0;
        if (strpos($ref, 'mv:') === 0) foreach (explode(',', substr($ref, 3)) as $mid) $n += smart_report_missing($pdo, (int)$mid, (string)$_SESSION['username'], (string)($in['note'] ?? '')) ? 1 : 0;
        feed_out(['ok' => true, 'n' => $n]);
    }

    if ($action === 'read') {
        $pdo->prepare("UPDATE notify_inbox SET read_at = COALESCE(read_at, NOW()), seen_at = COALESCE(seen_at, NOW()) WHERE id = ? AND user_id = ?")
            ->execute([(int)($in['id'] ?? 0), $uid]);
        feed_out(['ok' => true]);
    }

    feed_out(['ok' => false, 'error' => 'unknown']);
} catch (Throwable $e) {
    error_log('notify_feed: ' . $e->getMessage());
    feed_out(['ok' => false, 'error' => 'server']);
}
