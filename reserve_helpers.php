<?php
/*
 * reserve_helpers.php — shared helpers for حجز السيارة (car reservation).
 *
 * A reserved car keeps its branch and stays visible in stock everywhere —
 * it is just flagged with status 'reserved' so every page can paint it gold.
 * From there it can either be released (إلغاء الحجز) or sold (تم البيع),
 * which goes through the normal sale flow exactly like any other car.
 *
 * No extra table is needed: who reserved it and when are read back from the
 * 'reserved' movement that is logged on the vehicle timeline.
 */

/**
 * Make sure cars.status can actually hold the value 'reserved'.
 *
 * If the column is an ENUM that predates this feature, the value is appended
 * to the ENUM automatically (same safe auto-migrate pattern used elsewhere in
 * the app). VARCHAR columns need nothing. Returns false if the column can't be
 * prepared, so callers can fail loudly instead of silently doing nothing.
 */
function ensure_reserved_status(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;

    try {
        $col = $pdo->query("SHOW COLUMNS FROM cars LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) return $ok = false;

        $rawType = (string)($col['Type'] ?? '');
        $type    = strtolower($rawType);

        // Only ENUM columns need widening; VARCHAR/TEXT already accept anything.
        if (strncmp($type, 'enum(', 5) === 0 && strpos($type, "'reserved'") === false) {
            $newType = rtrim($rawType, ')') . ",'reserved')";
            $nullSql = (($col['Null'] ?? 'YES') === 'YES') ? 'NULL' : 'NOT NULL';
            $defSql  = ($col['Default'] !== null && $col['Default'] !== '')
                ? ' DEFAULT ' . $pdo->quote($col['Default'])
                : '';
            $pdo->exec("ALTER TABLE cars MODIFY status $newType $nullSql$defSql");
        }

        return $ok = true;
    } catch (Throwable $e) {
        error_log('reserve migrate failed: ' . $e->getMessage());
        return $ok = false;
    }
}

/**
 * Who reserved which car, and when.
 * Reads the most recent 'reserved' movement per car.
 *
 * @param  int[] $carIds
 * @return array  [car_id => ['by' => string, 'at' => string]]
 */
function reservation_info(PDO $pdo, array $carIds): array
{
    $carIds = array_values(array_filter(array_map('intval', $carIds)));
    if (empty($carIds)) return [];

    $out = [];
    try {
        $in   = implode(',', array_fill(0, count($carIds), '?'));
        $stmt = $pdo->prepare("
            SELECT car_id, moved_by, created_at
            FROM movements
            WHERE event_type = 'reserved' AND car_id IN ($in)
            ORDER BY id ASC
        ");
        $stmt->execute($carIds);
        // Later rows overwrite earlier ones, so each car ends up with its latest.
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['car_id']] = ['by' => $r['moved_by'], 'at' => $r['created_at']];
        }
    } catch (Throwable $e) {
        error_log('reservation_info failed: ' . $e->getMessage());
    }

    return $out;
}
