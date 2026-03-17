<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/db.php";

    http_response_code(500);
    exit(json_encode(['error' => 'Database connection failed']));

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid item ID']));
}

try {
    $stmt = $pdo->prepare("SELECT * FROM inventory_items WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        http_response_code(404);
        exit(json_encode(['error' => 'Item not found']));
    }
    
    header('Content-Type: application/json');
    echo json_encode($item);
    
} catch(Exception $e) {
    http_response_code(500);
    exit(json_encode(['error' => 'Error fetching item data']));
}
?>