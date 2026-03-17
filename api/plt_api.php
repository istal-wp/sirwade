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
    case 'create_project':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            
            $stmt = $pdo->prepare("INSERT INTO projects 
                                 (project_code, project_name, start_date, end_date, status, project_manager)
                                 VALUES (?, ?, ?, ?, 'planning', ?)");
            
            $project_code = 'PROJ-' . date('Ymd') . '-' . rand(1000, 9999);
            
            try {
                $stmt->execute([
                    $project_code,
                    $data['project_name'],
                    $data['start_date'],
                    $data['end_date'],
                    $_SESSION['user_id']
                ]);
                $response = ['success' => true, 'project_code' => $project_code];
            } catch(PDOException $e) {
                $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }
        break;

    case 'update_project_status':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            
            $stmt = $pdo->prepare("UPDATE projects 
                                 SET status = ?
                                 WHERE id = ?");
            
            try {
                $stmt->execute([$data['status'], $data['project_id']]);
                $response = ['success' => true];
            } catch(PDOException $e) {
                $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }
        break;

    case 'get_project_timeline':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $project_id = $_GET['project_id'] ?? null;
            
            if ($project_id) {
                $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
                
                try {
                    $stmt->execute([$project_id]);
                    $project = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $stmt = $pdo->prepare("SELECT * FROM project_activities WHERE project_id = ? ORDER BY activity_date");
                    $stmt->execute([$project_id]);
                    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $response = [
                        'success' => true,
                        'data' => [
                            'project' => $project,
                            'activities' => $activities
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