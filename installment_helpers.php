<?php
/*
 * installment_helpers.php — shared pieces for the تقسيط (bank approvals) page.
 *
 * Flow:
 *   sales fills ONE request  (customer + car)
 *     └── and picks one or more banks, each with its own مقدم %
 *          └── every bank line waits for a manager to accept / reject it
 *
 * Two tables, auto-created on first use (same safe pattern used elsewhere in
 * the app), so there is no manual SQL step:
 *   installment_requests       — the customer + car (shared across banks)
 *   installment_bank_requests  — one row per bank, with its own % and status
 */

/** The banks / finance companies a request can be sent to. */
function inst_banks(): array
{
    return [
        'abk'      => ['ar' => 'الأهلي الكويتي',                  'en' => 'Al Ahli Kuwaiti (ABK)'],
        'nbk'      => ['ar' => 'الوطني الكويتي',                   'en' => 'Kuwaiti National (NBK)'],
        'egbank'   => ['ar' => 'إيجي بنك',                        'en' => 'EG Bank'],
        'misr'     => ['ar' => 'بنك مصر',                         'en' => 'Banque Misr'],
        'cairo'    => ['ar' => 'بنك القاهرة',                     'en' => 'Banque du Caire'],
        'saib'     => ['ar' => 'بنك سايب',                        'en' => 'SAIB Bank'],
        'drive'    => ['ar' => 'شركة درايف',                      'en' => 'Drive Finance'],
        'ebe'      => ['ar' => 'المصري لتنمية الصادرات',           'en' => 'Export Development Bank (EBE)'],
        'agri'     => ['ar' => 'البنك الزراعي المصري',             'en' => 'Agricultural Bank of Egypt'],
        'next'     => ['ar' => 'بنك نكست',                        'en' => 'Next Bank'],
        'agricole' => ['ar' => 'كريدي أجريكول',                   'en' => 'Crédit Agricole'],
        'contact'  => ['ar' => 'كونتكت',                          'en' => 'Contact'],
        'sky'      => ['ar' => 'سكاي',                            'en' => 'Sky'],
    ];
}

/** Bank display name in the current language (falls back to the raw key). */
function inst_bank_name(string $key, string $lang): string
{
    $banks = inst_banks();
    return $banks[$key][$lang] ?? $banks[$key]['ar'] ?? $key;
}

/** Model years offered on an installment request. */
function inst_years(): array
{
    return ['2026', '2027', '2028', '2029', '2030'];
}

/** Allowed مقدم values: 5%, 10%, … 100%. */
function inst_down_payments(): array
{
    $out = [];
    for ($p = 5; $p <= 100; $p += 5) $out[] = $p;
    return $out;
}

/** Create the two tables if they don't exist yet. */
function inst_ensure_tables(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS installment_requests (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                customer_name  VARCHAR(255) NOT NULL,
                customer_phone VARCHAR(50)  NOT NULL,
                brand          VARCHAR(100) NOT NULL,
                model          VARCHAR(100) NOT NULL,
                trim_name      VARCHAR(100) NULL,
                car_year       VARCHAR(20)  NULL,
                created_by     VARCHAR(255) NOT NULL,
                created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS installment_bank_requests (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                request_id    INT          NOT NULL,
                bank_key      VARCHAR(50)  NOT NULL,
                down_payment  INT          NOT NULL,
                status        VARCHAR(20)  NOT NULL DEFAULT 'pending',
                decided_by    VARCHAR(255) NULL,
                decided_at    DATETIME     NULL,
                decision_note TEXT         NULL,
                created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_request (request_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        return $ok = true;
    } catch (Throwable $e) {
        error_log('installment tables failed: ' . $e->getMessage());
        return $ok = false;
    }
}

/**
 * Every brand / model / trim / year the dealership knows about, taken straight
 * from the database (current stock + the price list), for the cascading
 * dropdowns on the request form.
 */
function inst_car_catalog(PDO $pdo): array
{
    $rows = [];

    try {
        $rows = $pdo->query("
            SELECT DISTINCT brand, model, trim_name, car_year
            FROM cars
            WHERE brand IS NOT NULL AND brand <> ''
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('inst catalog (cars) failed: ' . $e->getMessage());
    }

    // The price list also carries models that may not be in stock right now.
    try {
        $more = $pdo->query("
            SELECT DISTINCT brand, model_name AS model, trim_name, car_year
            FROM pricing
            WHERE brand IS NOT NULL AND brand <> ''
        ")->fetchAll(PDO::FETCH_ASSOC);
        $rows = array_merge($rows, $more);
    } catch (Throwable $e) {
        // pricing table is optional — stock alone is fine
    }

    // De-duplicate on the full combination.
    $seen = [];
    $out  = [];
    foreach ($rows as $r) {
        $brand = trim((string)($r['brand'] ?? ''));
        $model = trim((string)($r['model'] ?? ''));
        if ($brand === '' || $model === '') continue;
        $trim = trim((string)($r['trim_name'] ?? ''));
        $year = trim((string)($r['car_year']  ?? ''));
        $k = "$brand|$model|$trim|$year";
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = ['brand' => $brand, 'model' => $model, 'trim' => $trim, 'year' => $year];
    }

    usort($out, fn($a, $b) =>
        [$a['brand'], $a['model'], $a['trim'], $a['year']] <=> [$b['brand'], $b['model'], $b['trim'], $b['year']]
    );

    return $out;
}

/** Overall state of a request, derived from its bank lines. */
function inst_overall_status(array $bankRows): string
{
    $has = ['approved' => false, 'pending' => false, 'rejected' => false];
    foreach ($bankRows as $b) {
        $s = $b['status'] ?? 'pending';
        if (isset($has[$s])) $has[$s] = true;
    }
    if ($has['approved']) return 'approved';   // at least one bank said yes
    if ($has['pending'])  return 'pending';    // still waiting on someone
    if ($has['rejected']) return 'rejected';   // everyone said no
    return 'pending';
}
