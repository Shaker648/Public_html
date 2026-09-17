<?php
/*
 * ads_action.php — create / close / renew an ad, and edit platform settings.
 *
 * Every action is POST-only, CSRF-protected and permission-gated, following the
 * same pattern as sale_action.php and reserve_action.php.
 *
 *   action=create        : post a new ad for a car on a platform
 *   action=close         : end a live ad (reason: manual / sold / expired)
 *   action=renew         : close the current ad as 'renewed' and open a fresh
 *                          one that points back at it, so the re-post history
 *                          of a car stays readable
 *   action=platform_save : edit the platform list (days, cost type, whether a
 *                          salesman is required)
 *
 * Always redirects back to ads.php.
 */

require 'auth.php';
require 'config.php';
require 'ads_helpers.php';

$lang = $_POST['lang'] ?? $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar', 'en'], true)) $lang = 'ar';

function ads_back(string $lang, string $flash = ''): void
{
    header('Location: ads.php?lang=' . $lang . ($flash !== '' ? '&flash=' . urlencode($flash) : ''));
    exit;
}

// The page permission is the door; each action has its own gate below.
if (!can('page.ads')) { http_response_code(403); die('Access Denied'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ads_back($lang);
}

ads_csrf_check();

if (!ads_ensure_tables($pdo)) {
    ads_back($lang, 'err');
}

$action   = $_POST['action'] ?? '';
$username = $_SESSION['username'] ?? '';

/* ─────────────────────────── CREATE ─────────────────────────── */
if ($action === 'create') {

    if (!can('ads.create')) { http_response_code(403); die('Access Denied'); }

    $car_id      = (int)($_POST['car_id'] ?? 0);
    $platform_id = (int)($_POST['platform_id'] ?? 0);
    $salesman    = trim($_POST['salesman'] ?? '');
    $startRaw    = trim($_POST['started_at'] ?? '');
    $daysRaw     = trim($_POST['days'] ?? '');
    $costPoints  = trim($_POST['cost_points'] ?? '');
    $costAmount  = trim($_POST['cost_amount'] ?? '');
    $url         = trim($_POST['listing_url'] ?? '');
    $notes       = trim($_POST['notes'] ?? '');

    if ($car_id <= 0 || $platform_id <= 0) {
        ads_back($lang, 'missing');
    }

    // Car must exist
    $cs = $pdo->prepare("SELECT id, status FROM cars WHERE id = ? LIMIT 1");
    $cs->execute([$car_id]);
    if (!$cs->fetch(PDO::FETCH_ASSOC)) {
        ads_back($lang, 'nocar');
    }

    // Platform must exist and be active
    $plat = ads_platform($pdo, $platform_id);
    if (!$plat || (int)$plat['active'] !== 1) {
        ads_back($lang, 'noplatform');
    }

    /* Dubizzle / Contact carry the salesman the ad was posted for. Only accept
       a name that is actually an eligible seller in the system. */
    if ((int)$plat['requires_salesman'] === 1) {
        $eligible = ads_salesmen($pdo);
        if ($salesman === '' || !in_array($salesman, $eligible, true)) {
            ads_back($lang, 'nosalesman');
        }
    } else {
        $salesman = '';   // manual platforms don't carry one
    }

    // Start date — defaults to now, which is what "the date I added it" means.
    $startTs = ($startRaw !== '') ? strtotime($startRaw) : time();
    if ($startTs === false) $startTs = time();
    $started_at = date('Y-m-d H:i:s', $startTs);

    /* End date. A fixed-duration platform computes it from the package length.
       A manual platform leaves it empty and the ad stays live until closed. */
    $planned_end = null;
    $days = ($daysRaw !== '' && ctype_digit($daysRaw)) ? (int)$daysRaw : null;
    if ($days === null && $plat['duration_type'] === 'fixed' && $plat['default_days'] !== null) {
        $days = (int)$plat['default_days'];
    }
    if ($days !== null && $days > 0 && $days <= 3650) {
        $planned_end = date('Y-m-d', strtotime("+{$days} days", $startTs));
    }

    // One live ad per car per platform — re-posting goes through Renew.
    $dupe = $pdo->prepare("
        SELECT id FROM car_listings
        WHERE car_id = ? AND platform_id = ? AND closed_at IS NULL
          AND (planned_end IS NULL OR planned_end >= CURDATE())
        LIMIT 1
    ");
    $dupe->execute([$car_id, $platform_id]);
    if ($dupe->fetch()) {
        ads_back($lang, 'dupe');
    }

    try {
        $pdo->prepare("
            INSERT INTO car_listings
                (car_id, platform_id, salesman, started_at, planned_end,
                 cost_points, cost_amount, listing_url, notes, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $car_id,
            $platform_id,
            ($salesman !== '' ? $salesman : null),
            $started_at,
            $planned_end,
            ($costPoints !== '' && is_numeric($costPoints)) ? (float)$costPoints : null,
            ($costAmount !== '' && is_numeric($costAmount)) ? (float)$costAmount : null,
            ($url !== '' ? mb_substr($url, 0, 500) : null),
            ($notes !== '' ? $notes : null),
            $username,
        ]);
    } catch (Throwable $e) {
        error_log('ads create failed: ' . $e->getMessage());
        ads_back($lang, 'err');
    }

    ads_back($lang, 'created');
}

/* ──────────────────────────── CLOSE ─────────────────────────── */
if ($action === 'close') {

    if (!can('ads.close')) { http_response_code(403); die('Access Denied'); }

    $listing_id = (int)($_POST['listing_id'] ?? 0);
    $reason     = $_POST['close_reason'] ?? 'manual';
    if (!in_array($reason, ['manual', 'sold', 'expired'], true)) $reason = 'manual';

    if ($listing_id <= 0) ads_back($lang);

    try {
        // Only an ad that is still open can be closed.
        $pdo->prepare("
            UPDATE car_listings
            SET closed_at = NOW(), close_reason = ?, closed_by = ?
            WHERE id = ? AND closed_at IS NULL
        ")->execute([$reason, $username, $listing_id]);
    } catch (Throwable $e) {
        error_log('ads close failed: ' . $e->getMessage());
        ads_back($lang, 'err');
    }

    ads_back($lang, 'closed');
}

/* ──────────────────────────── RENEW ─────────────────────────── */
if ($action === 'renew') {

    if (!can('ads.create')) { http_response_code(403); die('Access Denied'); }

    $listing_id = (int)($_POST['listing_id'] ?? 0);
    $daysRaw    = trim($_POST['days'] ?? '');
    $costPoints = trim($_POST['cost_points'] ?? '');
    $costAmount = trim($_POST['cost_amount'] ?? '');

    if ($listing_id <= 0) ads_back($lang);

    $ls = $pdo->prepare("SELECT * FROM car_listings WHERE id = ? LIMIT 1");
    $ls->execute([$listing_id]);
    $old = $ls->fetch(PDO::FETCH_ASSOC);
    if (!$old) ads_back($lang);

    $plat = ads_platform($pdo, (int)$old['platform_id']);
    if (!$plat) ads_back($lang, 'noplatform');

    $days = ($daysRaw !== '' && ctype_digit($daysRaw)) ? (int)$daysRaw : null;
    if ($days === null && $plat['duration_type'] === 'fixed' && $plat['default_days'] !== null) {
        $days = (int)$plat['default_days'];
    }
    $planned_end = ($days !== null && $days > 0 && $days <= 3650)
        ? date('Y-m-d', strtotime("+{$days} days"))
        : null;

    try {
        $pdo->beginTransaction();

        // Close the old ad as 'renewed' so the history reads as a chain.
        $pdo->prepare("
            UPDATE car_listings
            SET closed_at = NOW(), close_reason = 'renewed', closed_by = ?
            WHERE id = ? AND closed_at IS NULL
        ")->execute([$username, $listing_id]);

        // Open the replacement, pointing back at the ad it replaces.
        $pdo->prepare("
            INSERT INTO car_listings
                (car_id, platform_id, salesman, started_at, planned_end,
                 cost_points, cost_amount, listing_url, notes,
                 renewed_from_id, created_by, created_at)
            VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            (int)$old['car_id'],
            (int)$old['platform_id'],
            $old['salesman'],
            $planned_end,
            ($costPoints !== '' && is_numeric($costPoints)) ? (float)$costPoints : $old['cost_points'],
            ($costAmount !== '' && is_numeric($costAmount)) ? (float)$costAmount : $old['cost_amount'],
            $old['listing_url'],
            $old['notes'],
            $listing_id,
            $username,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('ads renew failed: ' . $e->getMessage());
        ads_back($lang, 'err');
    }

    ads_back($lang, 'renewed');
}

/* ─────────────────────── PLATFORM SETTINGS ──────────────────── */
if ($action === 'platform_save') {

    if (!can('ads.platforms')) { http_response_code(403); die('Access Denied'); }

    $ids      = $_POST['p_id']       ?? [];
    $days     = $_POST['p_days']     ?? [];
    $types    = $_POST['p_type']     ?? [];
    $costs    = $_POST['p_cost']     ?? [];
    $needsMan = $_POST['p_salesman'] ?? [];
    $actives  = $_POST['p_active']   ?? [];

    try {
        $upd = $pdo->prepare("
            UPDATE ad_platforms
            SET duration_type = ?, default_days = ?, cost_type = ?,
                requires_salesman = ?, active = ?
            WHERE id = ?
        ");
        foreach ($ids as $pid) {
            $pid = (int)$pid;
            if ($pid <= 0) continue;

            $type = ($types[$pid] ?? 'fixed') === 'manual' ? 'manual' : 'fixed';
            $cost = $costs[$pid] ?? 'free';
            if (!in_array($cost, ['points', 'per_ad', 'free'], true)) $cost = 'free';

            $d = trim((string)($days[$pid] ?? ''));
            $dVal = ($d !== '' && ctype_digit($d) && (int)$d > 0 && (int)$d <= 3650) ? (int)$d : null;
            if ($type === 'manual') $dVal = null;   // manual ads have no package length

            $upd->execute([
                $type,
                $dVal,
                $cost,
                !empty($needsMan[$pid]) ? 1 : 0,
                !empty($actives[$pid])  ? 1 : 0,
                $pid,
            ]);
        }
    } catch (Throwable $e) {
        error_log('ads platform save failed: ' . $e->getMessage());
        ads_back($lang, 'err');
    }

    ads_back($lang, 'platforms');
}

ads_back($lang);
