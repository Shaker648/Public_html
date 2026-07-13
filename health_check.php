<?php
/*
 * health_check.php — TEMPORARY diagnostic page.
 *
 * Upload this with the rest of the files, then open in your browser:
 *      https://YOUR-DOMAIN/health_check.php?key=first1car
 *
 * It checks every layer one by one (PHP → files → database → permission
 * tables) and tells you exactly which one is broken.
 *
 * ⚠ DELETE THIS FILE from the server once everything works.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// tiny gate so random visitors can't open it
if (($_GET['key'] ?? '') !== 'first1car') {
    http_response_code(404);
    die('Not found');
}

header('Content-Type: text/html; charset=utf-8');

function row($label, $ok, $detail = '') {
    $icon  = $ok ? '✅' : '❌';
    $color = $ok ? '#22c55e' : '#ef4444';
    echo "<div style='padding:10px 14px;border-bottom:1px solid #eee;'>"
       . "<span style='color:$color;font-weight:bold'>$icon $label</span>"
       . ($detail !== '' ? "<div style='color:#555;font-size:13px;margin-top:4px'>" . htmlspecialchars($detail) . "</div>" : '')
       . "</div>";
}

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Health Check</title></head>
<body style='font-family:Arial,sans-serif;max-width:720px;margin:30px auto;'>
<h2>🔍 First 1 Car — Health Check</h2>";

/* 1. PHP itself runs (if you can read this page, it does) */
row('PHP is running', true, 'PHP version: ' . PHP_VERSION
    . (PHP_VERSION_ID < 70400 ? '  ⚠ WARNING: this site needs PHP 7.4 or newer — change it in hPanel → PHP Configuration' : ''));

/* 2. Required files are in the same folder */
$need = ['auth.php', 'config.php', 'permissions.php', 'permissions_admin.php', 'dashboard.php', 'users.php', 'index.php'];
$missing = array_filter($need, fn($f) => !file_exists(__DIR__ . '/' . $f));
row('All required files uploaded next to this file', empty($missing),
    empty($missing)
        ? 'Folder: ' . __DIR__
        : 'MISSING: ' . implode(', ', $missing) . '  → the ZIP was probably extracted into a SUBFOLDER. All files must sit directly in public_html, not inside another folder.');

/* 3. permissions.php loads without a fatal error */
$permOk = true; $permErr = '';
try {
    require_once __DIR__ . '/permissions.php';
    $keys = function_exists('perm_all_keys') ? count(perm_all_keys()) : 0;
    $permOk = $keys > 0;
    $permErr = "$keys permission keys loaded";
} catch (Throwable $e) {
    $permOk = false; $permErr = $e->getMessage();
}
row('permissions.php loads', $permOk, $permErr);

/* 4. Database connects */
$pdo = null;
try {
    require_once __DIR__ . '/config.php';   // dies with 503 text if it can't connect
    row('Database connection', isset($pdo) && $pdo instanceof PDO, 'Connected OK');
} catch (Throwable $e) {
    row('Database connection', false, $e->getMessage());
}

/* 5. Permission tables exist / can be created */
if ($pdo instanceof PDO && function_exists('perm_ensure_tables')) {
    try {
        perm_ensure_tables($pdo);
        $r = $pdo->query("SELECT COUNT(*) FROM role_permissions")->fetchColumn();
        $u = $pdo->query("SELECT COUNT(*) FROM user_permissions")->fetchColumn();
        row('Permission tables ready', true, "role_permissions rows: $r · user_permissions rows: $u (0 rows = pure defaults, that's fine)");
    } catch (Throwable $e) {
        row('Permission tables ready', false, $e->getMessage());
    }

    /* 6. Users table reachable + effective permissions compute */
    try {
        $admin = $pdo->query("SELECT id, username, role FROM users WHERE role='admin' AND active=1 LIMIT 1")->fetch();
        if ($admin) {
            $eff = perm_effective($pdo, (int)$admin['id'], $admin['role']);
            row('Permission engine works', !empty($eff['page.users']),
                "Tested with admin '{$admin['username']}' — users page allowed: " . (!empty($eff['page.users']) ? 'yes' : 'NO (bug!)'));
        } else {
            row('Permission engine works', false, 'No active admin user found in the users table');
        }
    } catch (Throwable $e) {
        row('Permission engine works', false, $e->getMessage());
    }
}

echo "<p style='color:#999;font-size:12px;margin-top:20px'>⚠ Delete health_check.php from the server when done.</p>
</body></html>";
