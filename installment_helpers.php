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

/**
 * The banks the system ships with. These are only used to SEED the database
 * the very first time — after that the list lives in `installment_bank_list`
 * and is managed from the page itself (add / delete, Arabic + English).
 */
function inst_default_banks(): array
{
    return [
        'abk'      => ['ar' => 'الأهلي الكويتي',                  'en' => 'Al Ahli Kuwaiti (ABK)'],
        'nbk'      => ['ar' => 'الوطني الكويتي',                   'en' => 'Kuwaiti National (NBK)'],
        'enbd'     => ['ar' => 'الإمارات دبي الوطني',              'en' => 'Emirates NBD'],
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

/**
 * Create the bank table and seed it with the defaults on first use.
 * Returns false if the table can't be prepared (callers then fall back to the
 * built-in list, so the page never breaks).
 */
function inst_ensure_bank_table(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS installment_bank_list (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                bank_key   VARCHAR(50)  NOT NULL UNIQUE,
                name_ar    VARCHAR(255) NOT NULL,
                name_en    VARCHAR(255) NOT NULL,
                sort_order INT          NOT NULL DEFAULT 0,
                active     TINYINT(1)   NOT NULL DEFAULT 1,
                created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Seed once — only when the table is completely empty.
        $count = (int)$pdo->query("SELECT COUNT(*) FROM installment_bank_list")->fetchColumn();
        if ($count === 0) {
            $ins = $pdo->prepare("
                INSERT INTO installment_bank_list (bank_key, name_ar, name_en, sort_order, active)
                VALUES (?, ?, ?, ?, 1)
            ");
            $i = 10;
            foreach (inst_default_banks() as $key => $n) {
                $ins->execute([$key, $n['ar'], $n['en'], $i]);
                $i += 10;
            }
        }

        return $ok = true;
    } catch (Throwable $e) {
        error_log('installment bank list failed: ' . $e->getMessage());
        return $ok = false;
    }
}

/**
 * The banks a request can be sent to, read from the database.
 *
 * @param bool $includeInactive  true = also return removed banks (needed so old
 *                               requests still show the bank name they used).
 * @return array [bank_key => ['ar'=>…, 'en'=>…, 'active'=>bool]]
 */
function inst_banks(bool $includeInactive = false): array
{
    global $pdo;
    static $cache = [];

    $ck = $includeInactive ? 'all' : 'active';
    if (isset($cache[$ck])) return $cache[$ck];

    $out = [];
    if (isset($pdo) && inst_ensure_bank_table($pdo)) {
        try {
            $sql = "SELECT bank_key, name_ar, name_en, active FROM installment_bank_list"
                 . ($includeInactive ? '' : ' WHERE active = 1')
                 . " ORDER BY sort_order ASC, id ASC";
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['bank_key']] = [
                    'ar'     => $r['name_ar'],
                    'en'     => $r['name_en'],
                    'active' => (bool)$r['active'],
                ];
            }
        } catch (Throwable $e) {
            error_log('inst_banks read failed: ' . $e->getMessage());
        }
    }

    // Database unreachable / empty → fall back to the built-in list.
    if (empty($out)) {
        foreach (inst_default_banks() as $key => $n) {
            $out[$key] = ['ar' => $n['ar'], 'en' => $n['en'], 'active' => true];
        }
    }

    return $cache[$ck] = $out;
}

/** Bank display name in the current language (falls back to the raw key). */
function inst_bank_name(string $key, string $lang): string
{
    // Include removed banks so historical requests keep showing a real name.
    $banks = inst_banks(true);
    return $banks[$key][$lang] ?? $banks[$key]['ar'] ?? $key;
}

/** Build a unique, URL-safe key for a newly added bank. */
function inst_make_bank_key(PDO $pdo, string $nameEn, string $nameAr): string
{
    $base = strtolower(trim($nameEn));
    $base = preg_replace('/[^a-z0-9]+/', '_', $base);
    $base = trim((string)$base, '_');
    if ($base === '') $base = 'bank';
    $base = substr($base, 0, 40);

    $key = $base;
    $i   = 2;
    $chk = $pdo->prepare("SELECT 1 FROM installment_bank_list WHERE bank_key = ? LIMIT 1");
    $chk->execute([$key]);
    while ($chk->fetchColumn()) {
        $key = $base . '_' . $i++;
        $chk->execute([$key]);
    }
    return $key;
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
