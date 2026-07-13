<?php
/**
 * quote_save.php
 * Saves or clears the admin-editable dashboard quote banner text.
 *
 * - Admin only (enforced server-side, not just in the UI).
 * - Stores quotes in a `settings` table under key 'dashboard_quotes'.
 *   The table is auto-created on first save, so there's NO manual SQL step.
 * - Sending an empty value deletes the setting → dashboard.php falls back
 *   to its built-in default quotes automatically.
 *
 * Same stack as every other page: require auth.php + config.php → $pdo.
 */

require 'auth.php';
require 'config.php';

header('Content-Type: application/json; charset=utf-8');

// Permission gate — defense in depth (the edit button is permission-gated in the UI too).
if (!can('dash.quotes_edit')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Auto-create the settings table if it doesn't exist yet.
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            setting_key   VARCHAR(64) PRIMARY KEY,
            setting_value TEXT,
            updated_by    VARCHAR(64),
            updated_at    DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'table'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw      = json_decode(file_get_contents('php://input'), true) ?: [];
$value    = trim((string)($raw['value'] ?? ''));
$username = $_SESSION['username'] ?? '';

try {
    if ($value === '') {
        // Clear → revert dashboard to default quotes
        $pdo->prepare("DELETE FROM settings WHERE setting_key = 'dashboard_quotes'")->execute();
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value, updated_by, updated_at)
            VALUES ('dashboard_quotes', ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by    = VALUES(updated_by),
                updated_at    = NOW()
        ");
        $stmt->execute([$value, $username]);
    }
    echo json_encode(['ok' => true, 'value' => $value], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'save'], JSON_UNESCAPED_UNICODE);
}
