<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/db.php";

    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();

if (!isset($_GET['rfq_id']) || !is_numeric($_GET['rfq_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid RFQ ID']);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT * FROM rfq_items WHERE rfq_id = ? ORDER BY id");
    $stmt->execute([$_GET['rfq_id']]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($items);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch RFQ items']);
}
?>