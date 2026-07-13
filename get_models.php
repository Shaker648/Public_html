<?php

require 'config.php';

$brand = $_GET['brand'] ?? '';

$stmt = $pdo->prepare("
SELECT DISTINCT model_name
FROM models
WHERE brand = ?
ORDER BY model_name
");

$stmt->execute([$brand]);

echo json_encode(
    $stmt->fetchAll(PDO::FETCH_COLUMN)
);

?>