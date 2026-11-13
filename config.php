<?php
/*
 * config.php — database connection.
 *
 * Fixes applied:
 *  1. Error message no longer leaks DB credentials / host to the browser.
 *  2. Errors go to the server log instead (where only you can see them).
 */

date_default_timezone_set('Africa/Cairo');

// The database name, user and password are NOT in this file (the code repository must never hold them).
// They live in config.local.php, next to this file, on the server only.
$host = 'localhost'; $dbname = ''; $username = ''; $password = '';
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
} else {
    error_log('config.local.php is missing — the database settings are kept in that file');
    http_response_code(503);
    die('Setup incomplete: the file config.local.php is missing on the server.');
}

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    // the database clock follows Cairo time, summer (+03:00) and winter (+02:00) alike
    $pdo->exec("SET time_zone = '" . date('P') . "'");

} catch (PDOException $e) {
    // Log the real error server-side — never expose it to the browser
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(503);
    die('Service temporarily unavailable. Please try again later.');
}