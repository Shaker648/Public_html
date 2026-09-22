<?php
/*
 * car_images_helpers.php — the car image library (صور السيارات).
 *
 * THE IDEA
 *  An image does NOT belong to one car. It belongs to a MODEL IN A COLOUR.
 *  Upload one official photo of a white Tiggo 8, and every white Tiggo 8 in the
 *  system shows it — today's cars and every one received from now on. Nobody has
 *  to travel between branches with a camera, and the work shrinks to zero as the
 *  library fills up.
 *
 * SCOPE, FROM GENERAL TO SPECIFIC
 *  An image row can be scoped as narrowly or as loosely as you like. Empty
 *  string means "any", so one row can cover a whole model:
 *
 *      brand + model                          → the model's fallback image
 *      brand + model + colour                 → the normal case
 *      brand + model + colour + year          → a facelift year looks different
 *      brand + model + colour + year + trim   → the sportier trim looks different
 *
 *  car_image_resolve() walks from the most specific match to the loosest and
 *  returns the first hit, so a car always shows the best image available.
 *
 *  Empty string is used rather than NULL on purpose: MySQL unique indexes treat
 *  two NULLs as different values, so NULL wildcards would allow duplicate rows.
 *
 * STORAGE
 *  Files live in uploads/car_images/. Uploads are validated as real images, then
 *  re-encoded through GD to a normalised web size plus a thumbnail, under a
 *  random filename. The folder gets an .htaccess that forbids script execution,
 *  so nothing in there can ever be run even if something odd is uploaded.
 */

/** Folder (on disk) that holds the image files. */
function car_images_dir(): string
{
    return __DIR__ . '/uploads/car_images';
}

/** Folder (as a browser URL path) that holds the image files. */
function car_images_url_base(): string
{
    return 'uploads/car_images';
}

/**
 * Create the table, the upload folder and its hardening rules.
 * Cached per request. Returns false if the library isn't usable.
 */
function car_images_ensure(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS car_model_images (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                brand       VARCHAR(100) NOT NULL,
                model_name  VARCHAR(150) NOT NULL,
                trim_name   VARCHAR(150) NOT NULL DEFAULT '',
                car_year    VARCHAR(20)  NOT NULL DEFAULT '',
                color_en    VARCHAR(100) NOT NULL DEFAULT '',
                image_path  VARCHAR(255) NOT NULL,
                thumb_path  VARCHAR(255) NULL,
                width       INT NULL,
                height      INT NULL,
                bytes       INT NULL,
                uploaded_by VARCHAR(100) NULL,
                uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_scope (brand, model_name, trim_name, car_year, color_en),
                KEY idx_model (brand, model_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        error_log('car images: table create failed: ' . $e->getMessage());
        return $ok = false;
    }

    // Upload folder + hardening. Never fatal: the library still reads fine
    // without write access, you just can't add new images.
    try {
        $dir = car_images_dir();
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $ht = $dir . '/.htaccess';
        if (is_dir($dir) && !file_exists($ht)) {
            @file_put_contents($ht, implode("\n", [
                '# Uploaded images only — never let anything here be executed.',
                'php_flag engine off',
                'RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phps',
                'RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .phps',
                '<FilesMatch "\.(?i:php\d?|phtml|phps|cgi|pl|py|sh|htaccess)$">',
                '    Require all denied',
                '</FilesMatch>',
                '',
            ]));
        }
    } catch (Throwable $e) {
        error_log('car images: upload dir setup failed: ' . $e->getMessage());
    }

    return $ok = true;
}

/** Normalise a scope value for use in a lookup key. */
function car_image_norm($v): string
{
    return mb_strtolower(trim((string)$v));
}

/**
 * Load the whole library into one lookup map.
 *
 * The dashboard renders many cars at once, so the images are fetched ONCE and
 * matched in PHP rather than one query per card.
 *
 * @return array  key "brand|model|trim|year|color" => image row
 */
function car_images_map(PDO $pdo): array
{
    static $map = null;
    if ($map !== null) return $map;

    $map = [];
    if (!car_images_ensure($pdo)) return $map;

    try {
        $rows = $pdo->query("
            SELECT brand, model_name, trim_name, car_year, color_en, image_path, thumb_path
            FROM car_model_images
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $key = car_image_norm($r['brand']) . '|' . car_image_norm($r['model_name']) . '|'
                 . car_image_norm($r['trim_name']) . '|' . car_image_norm($r['car_year']) . '|'
                 . car_image_norm($r['color_en']);
            $map[$key] = $r;
        }
    } catch (Throwable $e) {
        error_log('car images: map load failed: ' . $e->getMessage());
    }

    return $map;
}

/**
 * Best image for one car, from the most specific scope to the loosest.
 *
 * @param array $map  from car_images_map()
 * @param array $car  needs brand, model, and ideally trim_name, car_year, color
 * @return array|null the image row, or null when the library has nothing yet
 */
function car_image_resolve(array $map, array $car): ?array
{
    if (empty($map)) return null;

    $brand = car_image_norm($car['brand']    ?? '');
    $model = car_image_norm($car['model']    ?? ($car['model_name'] ?? ''));
    $trim  = car_image_norm($car['trim_name'] ?? '');
    $year  = car_image_norm($car['car_year']  ?? '');
    $color = car_image_norm($car['color']     ?? ($car['color_en'] ?? ''));

    if ($brand === '' || $model === '') return null;

    $p = $brand . '|' . $model . '|';

    // Most specific first. '' in a slot means the row applies to any value there.
    $candidates = [
        $p . $trim . '|' . $year . '|' . $color,   // exact trim + year + colour
        $p . $trim . '||' . $color,                // trim + colour, any year
        $p . '|' . $year . '|' . $color,           // year + colour, any trim
        $p . '||' . $color,                        // colour only  ← the normal case
        $p . $trim . '|' . $year . '|',            // trim + year, any colour
        $p . $trim . '||',                         // trim, any colour
        $p . '|' . $year . '|',                    // year, any colour
        $p . '||',                                 // the model's fallback image
    ];

    foreach ($candidates as $key) {
        if (isset($map[$key])) return $map[$key];
    }
    return null;
}

/**
 * Browser URL for an image row, preferring the thumbnail where one exists.
 * Returns '' when the file is missing from disk, so a deleted file degrades to
 * "no image" rather than a broken picture.
 */
function car_image_url(?array $row, bool $thumb = false): string
{
    if (!$row) return '';
    $file = $thumb && !empty($row['thumb_path']) ? $row['thumb_path'] : ($row['image_path'] ?? '');
    if ($file === '') return '';
    if (!is_file(car_images_dir() . '/' . $file)) return '';
    return car_images_url_base() . '/' . rawurlencode($file);
}

/** Convenience: resolve and return a URL in one call. */
function car_image_url_for(array $map, array $car, bool $thumb = false): string
{
    return car_image_url(car_image_resolve($map, $car), $thumb);
}

/* ══════════════════════════ upload handling ══════════════════════════ */

/** Image types the library accepts. */
function car_images_allowed(): array
{
    return [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];
}

/** Parse a php.ini size string such as "8M" or "512K" into bytes. */
function car_images_ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '') return 0;
    $unit = strtolower($v[strlen($v) - 1]);
    $n    = (int)$v;
    switch ($unit) {
        case 'g': $n *= 1024; // fall through
        case 'm': $n *= 1024; // fall through
        case 'k': $n *= 1024;
    }
    return $n;
}

/**
 * Largest upload this server will actually accept.
 *
 * Our own ceiling is 8 MB, but PHP's upload_max_filesize and post_max_size can
 * be lower, and on shared hosting they usually are. Taking the smallest means
 * the limit shown on screen is the truth rather than a promise the server
 * cannot keep.
 */
function car_images_max_bytes(): int
{
    $limits = [8 * 1024 * 1024];
    foreach (['upload_max_filesize', 'post_max_size'] as $k) {
        $b = car_images_ini_bytes((string)ini_get($k));
        if ($b > 0) $limits[] = $b;
    }
    return min($limits);
}

/** The upload ceiling as a human string, e.g. "8 MB". */
function car_images_max_label(): string
{
    $mb = car_images_max_bytes() / (1024 * 1024);
    return ($mb >= 1 ? round($mb) : round($mb, 1)) . ' MB';
}

/**
 * True when the request body was thrown away for exceeding post_max_size.
 *
 * PHP silently empties $_POST and $_FILES in that case, so without this check a
 * large phone photo would surface as "Invalid CSRF token" rather than "too
 * large", which is baffling for the person uploading.
 */
function car_images_post_overflow(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return false;
    if (!empty($_POST) || !empty($_FILES)) return false;
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $max = car_images_ini_bytes((string)ini_get('post_max_size'));
    return $len > 0 && $max > 0 && $len > $max;
}

/**
 * Validate and store one uploaded image.
 *
 * The file is checked as a REAL image (not just by extension or by the
 * browser-supplied type), then re-encoded through GD at a sane web size with a
 * thumbnail alongside it, under a random filename. Re-encoding means anything
 * hidden inside the original file does not survive the trip.
 *
 * @return array{ok:bool, error?:string, image?:string, thumb?:string, w?:int, h?:int, bytes?:int}
 */
function car_images_store(array $file): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'error' => 'bad_upload'];
    }
    if ($file['error'] === UPLOAD_ERR_NO_FILE)   return ['ok' => false, 'error' => 'no_file'];
    if ($file['error'] === UPLOAD_ERR_INI_SIZE
        || $file['error'] === UPLOAD_ERR_FORM_SIZE) return ['ok' => false, 'error' => 'too_big'];
    if ($file['error'] !== UPLOAD_ERR_OK)        return ['ok' => false, 'error' => 'bad_upload'];
    if (($file['size'] ?? 0) > car_images_max_bytes()) return ['ok' => false, 'error' => 'too_big'];
    if (!is_uploaded_file($file['tmp_name']))    return ['ok' => false, 'error' => 'bad_upload'];

    // Must actually BE an image, and one of the types we accept.
    $info = @getimagesize($file['tmp_name']);
    if (!$info || empty($info[0]) || empty($info[1])) {
        return ['ok' => false, 'error' => 'not_image'];
    }
    $allowed = car_images_allowed();
    $type    = $info[2] ?? 0;
    if (!isset($allowed[$type])) return ['ok' => false, 'error' => 'bad_type'];

    $dir = car_images_dir();
    if (!is_dir($dir) || !is_writable($dir)) return ['ok' => false, 'error' => 'not_writable'];

    $stem     = bin2hex(random_bytes(16));
    $srcW     = (int)$info[0];
    $srcH     = (int)$info[1];
    $useWebp  = function_exists('imagewebp');
    $ext      = $useWebp ? 'webp' : 'jpg';
    $imgName  = $stem . '.' . $ext;
    $thumbNm  = $stem . '_t.' . $ext;

    // Without GD we still accept the file — it has been validated as a real
    // image — we just store it as-is under our own safe extension.
    if (!extension_loaded('gd')) {
        $plainName = $stem . '.' . $allowed[$type];
        if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $plainName)) {
            return ['ok' => false, 'error' => 'move_failed'];
        }
        @chmod($dir . '/' . $plainName, 0644);
        return ['ok' => true, 'image' => $plainName, 'thumb' => null,
                'w' => $srcW, 'h' => $srcH, 'bytes' => (int)($file['size'] ?? 0)];
    }

    $src = null;
    switch ($type) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($file['tmp_name']); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($file['tmp_name']);  break;
        case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($file['tmp_name']); break;
    }
    if (!$src) return ['ok' => false, 'error' => 'decode_failed'];

    $write = function ($im, string $path) use ($useWebp): bool {
        return $useWebp ? @imagewebp($im, $path, 82) : @imagejpeg($im, $path, 86);
    };

    /* Resize onto a white canvas: transparent PNGs would otherwise turn black
       when flattened, which looks broken on a dark card. */
    $resize = function ($src, int $sw, int $sh, int $maxW) {
        $scale = min(1, $maxW / max(1, $sw));
        $w = max(1, (int)round($sw * $scale));
        $h = max(1, (int)round($sh * $scale));
        $dst = imagecreatetruecolor($w, $h);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $sw, $sh);
        return [$dst, $w, $h];
    };

    try {
        [$big, $bw, $bh] = $resize($src, $srcW, $srcH, 1280);
        $okBig = $write($big, $dir . '/' . $imgName);
        imagedestroy($big);

        if (!$okBig) {
            imagedestroy($src);
            return ['ok' => false, 'error' => 'encode_failed'];
        }

        [$small, , ] = $resize($src, $srcW, $srcH, 420);
        if (!$write($small, $dir . '/' . $thumbNm)) $thumbNm = null;
        imagedestroy($small);
    } catch (Throwable $e) {
        error_log('car images: processing failed: ' . $e->getMessage());
        imagedestroy($src);
        return ['ok' => false, 'error' => 'encode_failed'];
    }

    imagedestroy($src);
    @chmod($dir . '/' . $imgName, 0644);
    if ($thumbNm) @chmod($dir . '/' . $thumbNm, 0644);

    return [
        'ok'    => true,
        'image' => $imgName,
        'thumb' => $thumbNm,
        'w'     => $bw,
        'h'     => $bh,
        'bytes' => (int)@filesize($dir . '/' . $imgName),
    ];
}

/** Delete an image row's files from disk. Safe to call on missing files. */
function car_images_unlink(?string $image, ?string $thumb): void
{
    $dir = car_images_dir();
    foreach ([$image, $thumb] as $f) {
        if (!$f) continue;
        // Defence in depth: only ever touch a bare filename inside our folder.
        if (basename($f) !== $f) continue;
        $p = $dir . '/' . $f;
        if (is_file($p)) @unlink($p);
    }
}
