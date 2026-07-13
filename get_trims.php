<?php

require 'config.php';

$brand = $_GET['brand'] ?? '';
$model = $_GET['model'] ?? '';

if ($brand !== '') {
    // Scope trims to brand + model so the same model name under different
    // brands doesn't bleed trims across brands.
    $stmt = $pdo->prepare("
        SELECT DISTINCT trim_name
        FROM models
        WHERE brand = ? AND model_name = ?
        ORDER BY trim_name
    ");
    $stmt->execute([$brand, $model]);
} else {
    // Backward-compatible: model-only lookup (used by older pages)
    $stmt = $pdo->prepare("
        SELECT DISTINCT trim_name
        FROM models
        WHERE model_name = ?
        ORDER BY trim_name
    ");
    $stmt->execute([$model]);
}

echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));