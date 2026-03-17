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
    case 'check_in_asset':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            
            $stmt = $pdo->prepare("INSERT INTO asset_movements 
                                 (asset_id, to_location, movement_date, moved_by, movement_type, notes)
                                 VALUES (?, ?, NOW(), ?, 'check-in', ?)");
            
            try {
                $stmt->execute([
                    $data['asset_id'],
                    $data['location_id'],
                    $_SESSION['user_id'],
                    $data['notes'] ?? null
                ]);
                $response = ['success' => true];
            } catch(PDOException $e) {
                $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }
        break;

    case 'check_out_asset':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            
            $stmt = $pdo->prepare("INSERT INTO asset_movements 
                                 (asset_id, from_location, to_location, movement_date, moved_by, movement_type, notes)
                                 VALUES (?, ?, ?, NOW(), ?, 'check-out', ?)");
            
            try {
                $stmt->execute([
                    $data['asset_id'],
                    $data['from_location'],
                    $data['to_location'],
                    $_SESSION['user_id'],
                    $data['notes'] ?? null
                ]);
                $response = ['success' => true];
            } catch(PDOException $e) {
                $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }
        break;

    case 'get_location_stock':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $location_id = $_GET['location_id'] ?? null;
            
            if ($location_id) {
                $stmt = $pdo->prepare("SELECT a.*, COUNT(*) as quantity
                                     FROM assets a
                                     JOIN asset_movements am ON a.id = am.asset_id
                                     WHERE am.to_location = ? 
                                     AND am.movement_date = (
                                         SELECT MAX(movement_date)
                                         FROM asset_movements
                                         WHERE asset_id = a.id
                                     )
                                     GROUP BY a.id");
                
                try {
                    $stmt->execute([$location_id]);
                    $response = ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
                } catch(PDOException $e) {
                    $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
                }
            }
        }
        break;
}

echo json_encode($response);