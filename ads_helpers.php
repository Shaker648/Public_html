<?php
/*
 * ads_helpers.php — shared helpers for إدارة الإعلانات (ad / listing management).
 *
 * THE IDEA
 *  Every ad is the same four things: a car, a platform, a start date and an end
 *  date. The ONLY thing that differs between Dubizzle, Contact and Facebook is
 *  who decides the end date:
 *
 *    duration_type = 'fixed'   → the system fills it in  (start + package days)
 *                                e.g. Dubizzle 7/10-day point packages
 *    duration_type = 'manual'  → nobody decides up front; the ad stays live
 *                                until you close it       e.g. Facebook / Instagram
 *
 *  So there is ONE table for all platforms and no special cases in the code.
 *
 * STATUS IS NEVER STORED — it is derived, so it can never drift out of date:
 *    closed_at set                 → closed
 *    planned_end in the past       → expired
 *    otherwise                     → live   (a manual ad with no end stays live)
 *
 * Tables are created on first use (the same safe auto-migrate pattern the app
 * already uses for settings / permissions / pricing_history), so there is no
 * manual SQL step on the server.
 */

/**
 * Create the ad tables if they don't exist and seed the starting platforms.
 * Cached per request. Returns false if the schema isn't usable, so callers can
 * fail loudly instead of silently doing nothing.
 */
function ads_ensure_tables(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ad_platforms (
                id                INT AUTO_INCREMENT PRIMARY KEY,
                name_ar           VARCHAR(100) NOT NULL,
                name_en           VARCHAR(100) NOT NULL,
                icon              VARCHAR(16)  NOT NULL DEFAULT '',
                duration_type     ENUM('fixed','manual') NOT NULL DEFAULT 'fixed',
                default_days      INT          NULL,
                cost_type         ENUM('points','per_ad','free') NOT NULL DEFAULT 'free',
                requires_salesman TINYINT(1)   NOT NULL DEFAULT 0,
                sort_order        INT          NOT NULL DEFAULT 0,
                active            TINYINT(1)   NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS car_listings (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                car_id          INT          NOT NULL,
                platform_id     INT          NOT NULL,
                salesman        VARCHAR(255) NULL,
                started_at      DATETIME     NOT NULL,
                planned_end     DATE         NULL,
                closed_at       DATETIME     NULL,
                close_reason    ENUM('expired','renewed','sold','manual') NULL,
                closed_by       VARCHAR(255) NULL,
                cost_points     DECIMAL(10,2) NULL,
                cost_amount     DECIMAL(10,2) NULL,
                listing_url     VARCHAR(500) NULL,
                renewed_from_id INT          NULL,
                notes           TEXT         NULL,
                created_by      VARCHAR(255) NULL,
                created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_car (car_id),
                KEY idx_platform (platform_id),
                KEY idx_end (planned_end),
                KEY idx_open (closed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        /* Seed the starting platforms ONLY when the table is empty, so the
           admin's own edits are never overwritten on a later page load. */
        $have = (int)$pdo->query("SELECT COUNT(*) FROM ad_platforms")->fetchColumn();
        if ($have === 0) {
            $seed = $pdo->prepare("
                INSERT INTO ad_platforms
                    (name_ar, name_en, icon, duration_type, default_days,
                     cost_type, requires_salesman, sort_order, active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            // Dubizzle + Contact carry a salesman (the ad is posted for a
            // specific seller). Meta pages are opened and closed by hand.
            $seed->execute(['دوبيزل',    'Dubizzle',  '🟡', 'fixed',  7,    'points', 1, 1]);
            $seed->execute(['كونتكت',    'Contact',   '🔵', 'fixed',  30,   'per_ad', 1, 2]);
            $seed->execute(['فيسبوك',    'Facebook',  '🔷', 'manual', null, 'free',   0, 3]);
            $seed->execute(['إنستجرام',  'Instagram', '🟣', 'manual', null, 'free',   0, 4]);
        }

        return $ok = true;
    } catch (Throwable $e) {
        error_log('ads: schema setup failed: ' . $e->getMessage());
        return $ok = false;
    }
}

/**
 * SQL CASE that derives an ad's status. Status is never stored.
 * $a is the car_listings alias used in the query.
 */
function ads_status_sql(string $a = 'l'): string
{
    return "CASE
                WHEN {$a}.closed_at IS NOT NULL THEN 'closed'
                WHEN {$a}.planned_end IS NOT NULL AND {$a}.planned_end < CURDATE() THEN 'expired'
                ELSE 'live'
            END";
}

/** All platforms, ordered for display. */
function ads_platforms(PDO $pdo, bool $activeOnly = false): array
{
    try {
        $sql = "SELECT * FROM ad_platforms " . ($activeOnly ? "WHERE active = 1 " : "")
             . "ORDER BY sort_order, id";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('ads: platform read failed: ' . $e->getMessage());
        return [];
    }
}

/** One platform by id, or null. */
function ads_platform(PDO $pdo, int $id): ?array
{
    try {
        $s = $pdo->prepare("SELECT * FROM ad_platforms WHERE id = ? LIMIT 1");
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Salesmen an ad can be posted for — the same eligible list the sale page uses
 * (managers + sales, active only, never admins).
 */
function ads_salesmen(PDO $pdo): array
{
    try {
        return $pdo->query("
            SELECT username FROM users
            WHERE role IN ('manager','sales') AND active = 1
            ORDER BY username
        ")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }
}

/** CSRF token for this session (shared with the rest of the app). */
function ads_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Verify a posted CSRF token, or stop the request. */
function ads_csrf_check(): void
{
    $sent = (string)($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        http_response_code(403);
        die('Invalid CSRF token');
    }
}

/** Platform display name in the active language. */
function ads_platform_name(array $p, string $lang): string
{
    return $lang === 'ar' ? (string)$p['name_ar'] : (string)$p['name_en'];
}

/**
 * Whole days from today until $date (a Y-m-d string).
 * Negative when the date has already passed. Null when there is no date.
 */
function ads_days_left(?string $date): ?int
{
    if ($date === null || $date === '') return null;
    $end = strtotime($date . ' 23:59:59');
    if ($end === false) return null;
    return (int)floor(($end - time()) / 86400);
}
