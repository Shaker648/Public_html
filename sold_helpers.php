<?php
/*
 * sold_helpers.php — shared helpers for the "revert a sold car" feature.
 *
 * A sold car can be sent back into inventory (admin only). To keep the full
 * history — who it was sold to, and that it later came back — we DON'T delete
 * the sold_cars row; we mark it 'returned'. Every sales stat then filters to
 * active ('sold') rows so a reverted car no longer counts as a sale.
 *
 * The extra columns are added automatically on first use (same safe auto-migrate
 * pattern the app already uses for its settings / permission tables), so there
 * is no manual SQL step.
 */

/**
 * Make sure sold_cars has the columns the revert feature needs.
 * Returns true if the `status` column is usable (so callers know they can
 * safely filter on it). Cached per-request.
 */
function ensure_sold_revert_columns(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;

    try {
        $hasStatus = $pdo->query("SHOW COLUMNS FROM sold_cars LIKE 'status'")->fetch();
        if (!$hasStatus) {
            $pdo->exec("ALTER TABLE sold_cars ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'sold'");
        }

        $hasReturned = $pdo->query("SHOW COLUMNS FROM sold_cars LIKE 'returned_at'")->fetch();
        if (!$hasReturned) {
            $pdo->exec("ALTER TABLE sold_cars
                        ADD COLUMN returned_at DATETIME NULL,
                        ADD COLUMN returned_by VARCHAR(255) NULL");
        }

        $ok = true;
    } catch (Throwable $e) {
        // No ALTER privilege (or table missing) — degrade gracefully: the revert
        // still works via cars.status, we just can't flag the historical row.
        error_log('sold revert migrate failed: ' . $e->getMessage());
        $ok = false;
    }

    return $ok;
}

/**
 * SQL fragment that keeps only active (non-reverted) sales.
 * $alias is the table alias used in the query ('sc', 's', or '' for none).
 * When the status column isn't available it returns "1=1" (no-op) so queries
 * never break.
 */
function sold_active_sql(bool $colOk, string $alias = ''): string
{
    if (!$colOk) return '1=1';
    $p = $alias !== '' ? $alias . '.' : '';
    return "COALESCE({$p}status,'sold') = 'sold'";
}
