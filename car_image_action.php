<?php
/*
 * car_image_action.php — add, replace or remove an image in the car image library.
 *
 * POST-only, CSRF-protected, permission-gated, following the same pattern as
 * sale_action.php / reserve_action.php / ads_action.php.
 *
 *   action=upload : store an image for brand + model (+ optional colour, year,
 *                   trim). Uploading over an existing scope REPLACES it and the
 *                   old files are removed from disk, so nothing is orphaned.
 *   action=delete : remove one image and its files.
 *
 * Always redirects back to car_images.php.
 */

require 'auth.php';
require 'config.php';
require 'car_images_helpers.php';

$lang = $_POST['lang'] ?? $_GET['lang'] ?? 'ar';
if (!in_array($lang, ['ar', 'en'], true)) $lang = 'ar';

function ci_back(string $lang, string $flash = '', string $brand = '', string $model = ''): void
{
    $q = ['lang' => $lang];
    if ($flash !== '') $q['flash'] = $flash;
    if ($brand !== '') $q['brand'] = $brand;
    if ($model !== '') $q['model'] = $model;
    header('Location: car_images.php?' . http_build_query($q));
    exit;
}

if (!can('page.car_images')) { http_response_code(403); die('Access Denied'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ci_back($lang);
}

/* An image bigger than post_max_size arrives with an EMPTY body, so this has to
   be checked before CSRF — otherwise a big phone photo reads as a CSRF failure. */
if (car_images_post_overflow()) {
    ci_back($lang, 'toobig');
}

/* CSRF */
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    die('Invalid CSRF token');
}

if (!car_images_ensure($pdo)) {
    ci_back($lang, 'err');
}

$action   = $_POST['action'] ?? '';
$username = $_SESSION['username'] ?? '';

/* ─────────────────────────── UPLOAD ─────────────────────────── */
if ($action === 'upload') {

    if (!can('car_images.upload')) { http_response_code(403); die('Access Denied'); }

    $brand = trim($_POST['brand']      ?? '');
    $model = trim($_POST['model_name'] ?? '');
    $trim  = trim($_POST['trim_name']  ?? '');
    $year  = trim($_POST['car_year']   ?? '');
    $color = trim($_POST['color_en']   ?? '');

    if ($brand === '' || $model === '') {
        ci_back($lang, 'missing');
    }

    /* The scope values must be real values from the system, never free text —
       otherwise a typo creates an image nothing will ever match. */
    try {
        $vb = $pdo->prepare("
            SELECT 1 FROM (
                SELECT brand, model_name FROM models
                UNION SELECT brand, model AS model_name FROM cars
            ) m WHERE m.brand = ? AND m.model_name = ? LIMIT 1
        ");
        $vb->execute([$brand, $model]);
        if (!$vb->fetchColumn()) ci_back($lang, 'badmodel');
    } catch (Throwable $e) {
        error_log('car images: model check failed: ' . $e->getMessage());
        ci_back($lang, 'err');
    }

    if ($color !== '') {
        $vc = $pdo->prepare("SELECT 1 FROM colors WHERE color_en = ? LIMIT 1");
        $vc->execute([$color]);
        if (!$vc->fetchColumn()) ci_back($lang, 'badcolor', $brand, $model);
    }

    if ($year !== '' && !preg_match('/^\d{4}$/', $year)) {
        ci_back($lang, 'badyear', $brand, $model);
    }

    $stored = car_images_store($_FILES['image'] ?? []);
    if (empty($stored['ok'])) {
        $map = [
            'too_big'      => 'toobig',
            'not_image'    => 'notimage',
            'bad_type'     => 'badtype',
            'no_file'      => 'nofile',
            'not_writable' => 'notwritable',
        ];
        ci_back($lang, $map[$stored['error'] ?? ''] ?? 'uploadfail', $brand, $model);
    }

    try {
        // Replacing this exact scope? Remember the old files so they can go.
        $old = $pdo->prepare("
            SELECT image_path, thumb_path FROM car_model_images
            WHERE brand = ? AND model_name = ? AND trim_name = ? AND car_year = ? AND color_en = ?
            LIMIT 1
        ");
        $old->execute([$brand, $model, $trim, $year, $color]);
        $prev = $old->fetch(PDO::FETCH_ASSOC);

        $pdo->prepare("
            INSERT INTO car_model_images
                (brand, model_name, trim_name, car_year, color_en,
                 image_path, thumb_path, width, height, bytes, uploaded_by, uploaded_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                image_path  = VALUES(image_path),
                thumb_path  = VALUES(thumb_path),
                width       = VALUES(width),
                height      = VALUES(height),
                bytes       = VALUES(bytes),
                uploaded_by = VALUES(uploaded_by),
                uploaded_at = NOW()
        ")->execute([
            $brand, $model, $trim, $year, $color,
            $stored['image'], $stored['thumb'],
            $stored['w'] ?? null, $stored['h'] ?? null, $stored['bytes'] ?? null,
            $username,
        ]);

        // Only drop the old files once the new row is safely saved.
        if ($prev) {
            car_images_unlink($prev['image_path'] ?? null, $prev['thumb_path'] ?? null);
        }
    } catch (Throwable $e) {
        error_log('car images: save failed: ' . $e->getMessage());
        car_images_unlink($stored['image'] ?? null, $stored['thumb'] ?? null);
        ci_back($lang, 'err', $brand, $model);
    }

    ci_back($lang, 'saved', $brand, $model);
}

/* ─────────────────────────── DELETE ─────────────────────────── */
if ($action === 'delete') {

    if (!can('car_images.delete')) { http_response_code(403); die('Access Denied'); }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) ci_back($lang);

    try {
        $s = $pdo->prepare("SELECT * FROM car_model_images WHERE id = ? LIMIT 1");
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) ci_back($lang);

        $pdo->prepare("DELETE FROM car_model_images WHERE id = ?")->execute([$id]);
        car_images_unlink($row['image_path'] ?? null, $row['thumb_path'] ?? null);

        ci_back($lang, 'deleted', (string)$row['brand'], (string)$row['model_name']);
    } catch (Throwable $e) {
        error_log('car images: delete failed: ' . $e->getMessage());
        ci_back($lang, 'err');
    }
}

ci_back($lang);
