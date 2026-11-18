<?php
/*
 * must_read_helpers.php — «رسالة إلزامية»: a message the person MUST read.
 *
 * From the moment it is sent, the person's system is closed (auth.php) on one
 * screen, must_read.php, until they:
 *   1. scroll to the end of the message and stay on it for a minimum reading
 *      time (worked out from the length, checked on the server too),
 *   2. answer the question the admin added, if any — either one with a right
 *      answer (it never leaves the server; a wrong one sends them back to read
 *      again) or one with no right answer (they pick, the admin sees each reply),
 *   3. press «قرأت وفهمت».
 * That includes البصمة: nothing opens before it, clocking in neither.
 *
 * Editing a message raises its version: everyone has to read and confirm it again.
 * The admin sees, per person: delivered to a phone, opened, reading time, wrong
 * answers, confirmed (which version, which device) — notifications_admin.php.
 * Admins are never closed out (they get it as a normal message).
 *
 * Needs push_helpers.php.
 */

function must_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    push_tables($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS must_msgs (
        log_id     INT PRIMARY KEY,
        quiz       TEXT NULL,
        min_secs   INT NOT NULL DEFAULT 10,
        ver        INT NOT NULL DEFAULT 1,
        edited_at  DATETIME NULL,
        edited_by  VARCHAR(100) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS must_reads (
        log_id    INT NOT NULL,
        user_id   INT NOT NULL,
        opened_at DATETIME NULL,
        read_secs INT NOT NULL DEFAULT 0,
        wrong_n   INT NOT NULL DEFAULT 0,
        ok_at     DATETIME NULL,
        ok_ver    INT NOT NULL DEFAULT 0,
        device    VARCHAR(120) NULL,
        reminded  INT NOT NULL DEFAULT 0,
        answer    INT NULL,
        PRIMARY KEY (log_id, user_id),
        KEY k_user (user_id, ok_ver)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->query("SELECT answer FROM must_reads LIMIT 1"); }                    // the choice they picked (added later)
    catch (Throwable $e) { try { $pdo->exec("ALTER TABLE must_reads ADD answer INT NULL"); } catch (Throwable $e2) {} }
    try { $pdo->query("SELECT must FROM notify_scheduled LIMIT 1"); }
    catch (Throwable $e) { try { $pdo->exec("ALTER TABLE notify_scheduled ADD must TEXT NULL"); } catch (Throwable $e2) {} }
}

/** Minimum reading time for a text: about 3 words a second, between 8 and 120 seconds. */
function must_min_secs(string $title, string $body): int
{
    $words = count(preg_split('/\s+/u', trim($title . ' ' . $body), -1, PREG_SPLIT_NO_EMPTY));
    return max(8, min(120, (int)ceil($words / 3)));
}

/**
 * The question, cleaned, or null. Two kinds:
 *   with a right answer  ['q' => text, 'choices' => [..], 'right' => index]
 *     — a wrong answer sends them back to read;
 *   no right answer      ['q' => text, 'choices' => [..], 'right' => -1, 'poll' => true]
 *     — they pick what fits them and the admin sees each reply.
 * $in: ['q' => '', 'choices' => ['', '', ''], 'right' => 0, 'poll' => false]
 */
function must_clean_quiz($in): ?array
{
    if (!is_array($in)) return null;
    $q = trim(preg_replace('/\s+/u', ' ', (string)($in['q'] ?? '')));
    $poll = !empty($in['poll']);
    $choices = [];
    $want = $poll ? -1 : (int)($in['right'] ?? -1);
    $right = -1;
    foreach (array_values((array)($in['choices'] ?? [])) as $i => $c) {
        $c = trim(preg_replace('/\s+/u', ' ', (string)$c));
        if ($c === '') continue;                                                    // empty boxes are dropped
        if ($i === $want) $right = count($choices);                                 // …so the right one keeps pointing at its text
        $choices[] = mb_substr($c, 0, 120);
    }
    $choices = array_slice($choices, 0, 4);
    if ($q === '' || count($choices) < 2) return null;
    if ($poll) return ['q' => mb_substr($q, 0, 200), 'choices' => $choices, 'right' => -1, 'poll' => true];
    if ($right < 0 || $right >= count($choices)) return null;
    return ['q' => mb_substr($q, 0, 200), 'choices' => $choices, 'right' => $right];
}

/** A question with no right answer (they just pick)? */
function must_is_poll(?array $quiz): bool
{
    return $quiz !== null && (!empty($quiz['poll']) || (int)($quiz['right'] ?? -1) < 0);
}

/** The person's device in a few words ("iPhone · Safari"). */
function must_device(): string
{
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (function_exists('dev_label')) return mb_substr(dev_label($ua), 0, 120);
    return mb_substr(preg_match('/iPhone|iPad/', $ua) ? 'iPhone' : (preg_match('/Android/', $ua) ? 'Android' : 'Computer'), 0, 120);
}

/**
 * Send a mandatory message now. Admins among $userIds get it as a normal message
 * with «تم الاطلاع». Returns [people, devices, delivered, failed, logId].
 */
function must_send(PDO $pdo, array $userIds, string $title, string $body, string $actor, ?array $quiz): array
{
    must_tables($pdo);
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$ids) return [0, 0, 0, 0, 0];
    $people = $pdo->query("SELECT id, role FROM users WHERE active = 1 AND id IN (" . implode(',', $ids) . ")")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!$people) return [0, 0, 0, 0, 0];
    $ids = array_map('intval', array_keys($people));

    $logId = notify_store($pdo, 'message', ['title' => $title, 'body' => $body, 'url' => 'must_read.php'], $actor, $ids, true);
    $pdo->prepare("UPDATE notify_log SET url = ? WHERE id = ?")->execute(['must_read.php?id=' . $logId, $logId]);
    $pdo->prepare("INSERT INTO must_msgs (log_id, quiz, min_secs, ver) VALUES (?, ?, ?, 1)")
        ->execute([$logId, $quiz ? json_encode($quiz, JSON_UNESCAPED_UNICODE) : null, must_min_secs($title, $body)]);
    $ins = $pdo->prepare("INSERT IGNORE INTO must_reads (log_id, user_id) VALUES (?, ?)");
    foreach ($people as $uid => $role) if ($role !== 'admin') $ins->execute([$logId, (int)$uid]);

    $rtl = (bool)preg_match('/\p{Arabic}/u', $title . $body);
    $ar  = $rtl;
    $short = mb_strlen($body) > 160 ? mb_substr($body, 0, 160) . '…' : $body;    // the full text is read inside the system
    [$devs, $ok, $bad] = notify_deliver($pdo, $logId, 'message', ['title' => '🔒 ' . $title, 'body' => $short . "\n" . ($ar ? '👆 افتح النظام لقراءتها — النظام مغلق حتى تقرأها وتؤكدها' : '👆 Open the system to read it — it stays closed until you confirm'),
        'url' => 'must_read.php?id=' . $logId], $ids, $ar ? 'ar' : 'en', 'must-' . $logId, false, true, ['must' => true]);
    return [count($ids), $devs, $ok, $bad, $logId];
}

/**
 * The mandatory messages this person still has to read (oldest first):
 * new ones, and ones edited since they confirmed. [] when nothing is waiting.
 */
function must_pending(PDO $pdo, int $uid): array
{
    try {
        must_tables($pdo);
        $st = $pdo->prepare("SELECT r.*, m.quiz, m.min_secs, m.ver, m.edited_at, l.title, l.body, l.actor, l.created_at
                             FROM must_reads r JOIN must_msgs m ON m.log_id = r.log_id JOIN notify_log l ON l.id = r.log_id
                             WHERE r.user_id = ? AND r.ok_ver < m.ver ORDER BY r.log_id");
        $st->execute([$uid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** Is anything waiting? (auth.php — one cheap query) */
function must_has_pending(PDO $pdo, int $uid): bool
{
    try {
        $st = $pdo->prepare("SELECT 1 FROM must_reads r JOIN must_msgs m ON m.log_id = r.log_id WHERE r.user_id = ? AND r.ok_ver < m.ver LIMIT 1");
        $st->execute([$uid]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }   // tables not created yet → nothing to read
}

/** The page was opened: first time recorded; the reading clock (server side) starts now for this version. */
function must_opened(PDO $pdo, int $logId, int $uid, int $ver = 1): void
{
    $pdo->prepare("UPDATE must_reads SET opened_at = COALESCE(opened_at, NOW()) WHERE log_id = ? AND user_id = ?")->execute([$logId, $uid]);
    $pdo->prepare("UPDATE notify_inbox SET seen_at = COALESCE(seen_at, NOW()), read_at = COALESCE(read_at, NOW()) WHERE log_id = ? AND user_id = ?")->execute([$logId, $uid]);
    $k = $logId . ':' . $uid;
    if (($_SESSION['must_v'][$k] ?? 0) !== $ver) {                                  // edited while open: read the new text from the start
        unset($_SESSION['must_t'][$k], $_SESSION['must_order'][$k]);
        $_SESSION['must_v'][$k] = $ver;
    }
    if (!isset($_SESSION['must_t'][$k])) $_SESSION['must_t'][$k] = time();
}

/** Seconds spent with the message on screen, sent by the page every few seconds (only ever adds up). */
function must_heartbeat(PDO $pdo, int $logId, int $uid, int $secs): void
{
    $pdo->prepare("UPDATE must_reads SET read_secs = read_secs + ? WHERE log_id = ? AND user_id = ? AND ok_ver < (SELECT ver FROM must_msgs WHERE log_id = ?)")
        ->execute([max(0, min(15, $secs)), $logId, $uid, $logId]);
}

/**
 * «قرأت وفهمت». $choice: the index of the choice they picked, in the order shown (null when there is no question).
 * Returns 'ok' | 'early' (minimum reading time not reached) | 'wrong' (the answer) | 'pick' (no choice picked) | 'none' (nothing to confirm).
 */
function must_confirm(PDO $pdo, int $logId, int $uid, ?int $choice): string
{
    must_tables($pdo);
    $st = $pdo->prepare("SELECT r.*, m.quiz, m.min_secs, m.ver FROM must_reads r JOIN must_msgs m ON m.log_id = r.log_id WHERE r.log_id = ? AND r.user_id = ? AND r.ok_ver < m.ver");
    $st->execute([$logId, $uid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return 'none';
    $k = $logId . ':' . $uid;
    $since = (int)($_SESSION['must_t'][$k] ?? time());
    if (time() - $since < (int)$r['min_secs'] - 2) return 'early';                 // the page cannot be hurried from outside
    $quiz = $r['quiz'] ? json_decode((string)$r['quiz'], true) : null;
    $picked = null;
    if ($quiz) {
        $order = $_SESSION['must_order'][$k] ?? null;                               // the order this person was shown
        $picked = ($choice !== null && is_array($order) && isset($order[$choice])) ? (int)$order[$choice] : -1;
        if (!isset($quiz['choices'][$picked])) $picked = -1;
        if (must_is_poll($quiz)) { if ($picked < 0) return 'pick'; }               // no right answer: any choice, it is recorded
        elseif ($picked !== (int)$quiz['right']) {
            $pdo->prepare("UPDATE must_reads SET wrong_n = wrong_n + 1 WHERE log_id = ? AND user_id = ?")->execute([$logId, $uid]);
            $_SESSION['must_t'][$k] = time() - (int)floor((int)$r['min_secs'] / 2);   // read it again: half the time
            unset($_SESSION['must_order'][$k]);
            return 'wrong';
        }
    }
    $pdo->prepare("UPDATE must_reads SET ok_at = NOW(), ok_ver = ?, device = ?, answer = ? WHERE log_id = ? AND user_id = ?")->execute([(int)$r['ver'], must_device(), $picked, $logId, $uid]);
    $pdo->prepare("UPDATE notify_inbox SET ack_at = COALESCE(ack_at, NOW()), read_at = COALESCE(read_at, NOW()), seen_at = COALESCE(seen_at, NOW()) WHERE log_id = ? AND user_id = ?")->execute([$logId, $uid]);
    unset($_SESSION['must_t'][$k], $_SESSION['must_order'][$k]);
    return 'ok';
}

/** Edit a sent mandatory message: new version → everyone reads and confirms it again (and is told). */
function must_edit(PDO $pdo, int $logId, string $title, string $body, ?array $quiz, string $by): bool
{
    must_tables($pdo);
    $st = $pdo->prepare("SELECT quiz FROM must_msgs WHERE log_id = ?");
    $st->execute([$logId]);
    $old = $st->fetch(PDO::FETCH_ASSOC);
    if (!$old) return false;
    $oldQ = $old['quiz'] ? json_decode((string)$old['quiz'], true) : null;
    if ($oldQ != $quiz) $pdo->prepare("UPDATE must_reads SET answer = NULL WHERE log_id = ?")->execute([$logId]);   // a new question: old answers no longer fit
    $pdo->prepare("UPDATE notify_log SET title = ?, body = ? WHERE id = ?")->execute([$title, $body, $logId]);
    $pdo->prepare("UPDATE must_msgs SET quiz = ?, min_secs = ?, ver = ver + 1, edited_at = NOW(), edited_by = ? WHERE log_id = ?")
        ->execute([$quiz ? json_encode($quiz, JSON_UNESCAPED_UNICODE) : null, must_min_secs($title, $body), $by, $logId]);
    $pdo->prepare("UPDATE notify_inbox SET read_at = NULL, ack_at = NULL WHERE log_id = ?")->execute([$logId]);
    $who = $pdo->prepare("SELECT u.username FROM must_reads r JOIN users u ON u.id = r.user_id WHERE r.log_id = ? AND u.active = 1");
    $who->execute([$logId]);
    $names = $who->fetchAll(PDO::FETCH_COLUMN);
    if ($names) notify_event($pdo, 'must_edited', ['title' => $title, 'id' => $logId, 'only' => array_map('strval', $names), 'actor' => '', 'tag' => 'must-' . $logId, 'force' => true, 'now' => true]);
    return true;
}

/** A reminder to whoever has not confirmed yet. Returns how many were reminded. */
function must_remind(PDO $pdo, int $logId): int
{
    must_tables($pdo);
    $st = $pdo->prepare("SELECT u.username, r.user_id FROM must_reads r JOIN must_msgs m ON m.log_id = r.log_id JOIN users u ON u.id = r.user_id
                         WHERE r.log_id = ? AND r.ok_ver < m.ver AND u.active = 1");
    $st->execute([$logId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0;
    $t = $pdo->prepare("SELECT title FROM notify_log WHERE id = ?");
    $t->execute([$logId]);
    notify_event($pdo, 'must_remind', ['title' => (string)$t->fetchColumn(), 'id' => $logId, 'only' => array_map('strval', array_column($rows, 'username')),
        'actor' => '', 'tag' => 'must-' . $logId, 'force' => true, 'now' => true]);
    $pdo->prepare("UPDATE must_reads SET reminded = reminded + 1 WHERE log_id = ? AND ok_ver < (SELECT ver FROM must_msgs WHERE log_id = ?)")->execute([$logId, $logId]);
    return count($rows);
}

/** The latest mandatory messages for the admin's report: message + one row per person. */
function must_report(PDO $pdo, int $limit = 10): array
{
    try {
        must_tables($pdo);
        $msgs = $pdo->query("SELECT m.*, l.title, l.body, l.actor, l.created_at, TIMESTAMPDIFF(SECOND, l.created_at, NOW()) AS age
                             FROM must_msgs m JOIN notify_log l ON l.id = m.log_id ORDER BY m.log_id DESC LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_ASSOC);
        if (!$msgs) return [];
        $in = implode(',', array_map(fn($m) => (int)$m['log_id'], $msgs));
        $who = [];
        foreach ($pdo->query("SELECT r.*, u.username, u.role, (SELECT COUNT(*) FROM push_subscriptions s WHERE s.user_id = r.user_id) AS phones
                              FROM must_reads r JOIN users u ON u.id = r.user_id WHERE r.log_id IN ($in) ORDER BY u.username") as $r) $who[(int)$r['log_id']][] = $r;
        foreach ($msgs as &$m) {
            $m['people'] = $who[(int)$m['log_id']] ?? [];
            $m['n'] = count($m['people']);
            $m['done'] = count(array_filter($m['people'], fn($p) => (int)$p['ok_ver'] >= (int)$m['ver']));
            $m['quiz'] = $m['quiz'] ? json_decode((string)$m['quiz'], true) : null;
            $m['poll'] = must_is_poll($m['quiz']);
            $m['tally'] = $m['quiz'] ? array_fill(0, count($m['quiz']['choices']), 0) : [];   // how many picked each choice
            $m['answered'] = 0;
            foreach ($m['people'] as $p) if ($p['answer'] !== null && isset($m['tally'][(int)$p['answer']])) { $m['tally'][(int)$p['answer']]++; $m['answered']++; }
        }
        unset($m);
        return $msgs;
    } catch (Throwable $e) { error_log('must_report: ' . $e->getMessage()); return []; }
}
