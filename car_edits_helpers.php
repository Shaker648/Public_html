<?php
/*
 * car_edits_helpers.php — who changed what on a vehicle (edit_vehicle.php).
 *
 * Every saved edit writes one row per changed field, kept forever, so the
 * edit page can show its history and the vehicle journey can show the edits.
 * The table creates itself on first use (same auto-migrate pattern the app
 * already uses), so there is no manual SQL step. Logging never breaks a page:
 * reading returns [] if the table is missing.
 */

function car_edits_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS car_edits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        car_id INT NOT NULL,
        field VARCHAR(30) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        edited_by VARCHAR(100) NOT NULL,
        edited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_car (car_id, edited_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** @param array $changes field => [old, new] */
function car_edits_log(PDO $pdo, int $carId, array $changes, string $by): void
{
    if (!$changes) return;
    car_edits_ensure($pdo);
    // one timestamp for the whole save (so it reads as one edit), taken from the
    // database clock like every other journey event
    $at  = (string)$pdo->query("SELECT NOW()")->fetchColumn();
    $ins = $pdo->prepare("INSERT INTO car_edits (car_id, field, old_value, new_value, edited_by, edited_at) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($changes as $field => [$old, $new]) {
        $ins->execute([$carId, $field, $old, $new, $by, $at]);
    }
}

/**
 * The car's edits grouped per save, newest first:
 * [ ['at' => 'Y-m-d H:i:s', 'by' => user, 'fields' => [field => [old, new]]], ... ]
 */
function car_edits_for(PDO $pdo, int $carId, int $limit = 50): array
{
    try {
        $st = $pdo->prepare("SELECT field, old_value, new_value, edited_by, edited_at FROM car_edits WHERE car_id = ? ORDER BY edited_at DESC, id ASC LIMIT " . (int)($limit * 8));
        $st->execute([$carId]);
    } catch (Throwable $e) {
        return [];   // table not created yet: no edits
    }
    $groups = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = $r['edited_at'] . '|' . $r['edited_by'];
        if (!isset($groups[$k])) $groups[$k] = ['at' => $r['edited_at'], 'by' => $r['edited_by'], 'fields' => []];
        $groups[$k]['fields'][$r['field']] = [(string)$r['old_value'], (string)$r['new_value']];
    }
    return array_slice(array_values($groups), 0, $limit);
}
