<?php
/*
 * pricing_helpers.php — keeps the pricing table to one row per car
 * (brand + model + trim + year).
 *
 * If the table ever lost its unique key, every save on the prices page added
 * a new row instead of updating the old one, so old prices stayed behind and
 * some pages (the dashboard) could show an old price instead of the one on
 * the prices page. This keeps only the latest saved row of each car and puts
 * the unique key back. It runs once — afterwards it only checks the key exists.
 */
function pricing_fix_duplicates(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $has = $pdo->query("SHOW INDEX FROM pricing WHERE Non_unique = 0 AND Key_name <> 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
        $cols = array_map(fn($r) => strtolower($r['Column_name']), $has);
        if (!array_diff(['brand', 'model_name', 'trim_name', 'car_year'], $cols)) return;   // already one row per car

        // keep the latest saved row of each car, drop the older copies
        $pdo->exec("DELETE p1 FROM pricing p1
                    JOIN pricing p2 ON p2.brand <=> p1.brand AND p2.model_name <=> p1.model_name
                                   AND p2.trim_name <=> p1.trim_name AND p2.car_year <=> p1.car_year
                                   AND (COALESCE(p2.updated_at, '1970-01-01') > COALESCE(p1.updated_at, '1970-01-01')
                                        OR (COALESCE(p2.updated_at, '1970-01-01') = COALESCE(p1.updated_at, '1970-01-01') AND p2.id > p1.id))");
        $pdo->exec("ALTER TABLE pricing ADD UNIQUE KEY uq_pricing_car (brand, model_name, trim_name, car_year)");
    } catch (Throwable $e) { error_log('pricing_fix_duplicates: ' . $e->getMessage()); }
}
