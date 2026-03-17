<?php
header('Content-Type: application/json');
require_once('../config.php');

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';
$response = ['success' => false, 'message' => 'Invalid action'];

switch($action) {
    case 'create_purchase_request':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            
            $stmt = $pdo->prepare("INSERT INTO purchase_requests (request_number, requester_id, department, request_date, status) 
                                 VALUES (?, ?, ?, NOW(), 'pending')");
            
            $request_number = 'PR-' . date('Ymd') . '-' . rand(1000, 9999);
            
            try {
                $stmt->execute([$request_number, $_SESSION['user_id'], $data['department']]);
                $response = ['success' => true, 'request_number' => $request_number];
            } catch(PDOException $e) {
                $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }
        break;

    case 'evaluate_supplier':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            
            $stmt = $pdo->prepare("UPDATE suppliers SET 
                                 evaluation_score = ?,
                                 last_evaluation_date = NOW(),
                                 evaluated_by = ?
                                 WHERE id = ?");
            
            try {
                $stmt->execute([$data['score'], $_SESSION['user_id'], $data['supplier_id']]);
                $response = ['success' => true];
            } catch(PDOException $e) {
                $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }
        break;

    case 'update_inventory':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            
            $stmt = $pdo->prepare("UPDATE assets SET 
                                 quantity = quantity + ?,
                                 last_updated = NOW(),
                                 updated_by = ?
                                 WHERE id = ?");
            
            try {
                $stmt->execute([$data['quantity_change'], $_SESSION['user_id'], $data['asset_id']]);
                $response = ['success' => true];
            } catch(PDOException $e) {
                $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }
        break;
}

echo json_encode($response);