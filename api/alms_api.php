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
    case 'schedule_maintenance':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            
            $stmt = $pdo->prepare("INSERT INTO maintenance_schedules 
                                 (asset_id, maintenance_type, scheduled_date, status, notes)
                                 VALUES (?, ?, ?, 'scheduled', ?)");
            
            try {
                $stmt->execute([
                    $data['asset_id'],
                    $data['maintenance_type'],
                    $data['scheduled_date'],
                    $data['notes'] ?? null
                ]);
                $response = ['success' => true];
            } catch(PDOException $e) {
                $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }
        break;

    case 'update_maintenance_status':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            
            $stmt = $pdo->prepare("UPDATE maintenance_schedules 
                                 SET status = ?, 
                                     notes = CONCAT(COALESCE(notes,''), '\nUpdated: ', ?)
                                 WHERE id = ?");
            
            try {
                $stmt->execute([
                    $data['status'],
                    $data['update_notes'] ?? '',
                    $data['schedule_id']
                ]);
                $response = ['success' => true];
            } catch(PDOException $e) {
                $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }
        break;

    case 'get_asset_lifecycle':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $asset_id = $_GET['asset_id'] ?? null;
            
            if ($asset_id) {
                try {
                    $stmt = $pdo->prepare("SELECT * FROM assets WHERE id = ?");
                    $stmt->execute([$asset_id]);
                    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $stmt = $pdo->prepare("SELECT * FROM maintenance_schedules WHERE asset_id = ? ORDER BY scheduled_date");
                    $stmt->execute([$asset_id]);
                    $maintenance = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $response = [
                        'success' => true,
                        'data' => [
                            'asset' => $asset,
                            'maintenance_history' => $maintenance
                        ]
                    ];
                } catch(PDOException $e) {
                    $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
                }
            }
        }
        break;
}

echo json_encode($response);