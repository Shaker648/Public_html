<?php
/*
 * config.php — database connection.
 *
 * Fixes applied:
 *  1. Error message no longer leaks DB credentials / host to the browser.
 *  2. Errors go to the server log instead (where only you can see them).
 */

date_default_timezone_set('Africa/Cairo');

$host     = 'localhost';
$dbname   = 'u935149902_inventory';
$username = 'u935149902_Shaker';
$password = 'First1car';

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
    $pdo->exec("SET time_zone = '+03:00'");

} catch (PDOException $e) {
    // Log the real error server-side — never expose it to the browser
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(503);
    die('Service temporarily unavailable. Please try again later.');
}