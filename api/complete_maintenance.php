<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit();
}

$servername   = getenv('MYSQLHOST')     ?: getenv('DB_HOST')     ?: 'localhost';
$db_port      = getenv('MYSQLPORT')     ?: getenv('DB_PORT')     ?: '3306';
$username     = getenv('MYSQLUSER')     ?: getenv('DB_USER')     ?: 'root';
$password     = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS')     ?: '';
$dbname       = getenv('MYSQLDATABASE') ?: getenv('DB_NAME')     ?: 'loogistics';

try {
    $pdo = new PDO("mysql:host=$servername;port=$db_port;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $maintenance_id = $_POST['maintenance_id'] ?? null;
    $completed_date = $_POST['completed_date'] ?? null;
    $actual_cost = $_POST['actual_cost'] ?? 0;
    $parts_used = $_POST['parts_used'] ?? '';
    $work_performed = $_POST['work_performed'] ?? null;
    $next_maintenance_date = $_POST['next_maintenance_date'] ?? null;
    
    if (empty($maintenance_id) || empty($completed_date) || empty($work_performed)) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit();
    }
    
    $date_obj = DateTime::createFromFormat('Y-m-d', $completed_date);
    if (!$date_obj || $date_obj->format('Y-m-d') !== $completed_date) {
        echo json_encode(['success' => false, 'error' => 'Invalid completed date format']);
        exit();
    }
    
    $today = new DateTime();
    if ($date_obj > $today) {
        echo json_encode(['success' => false, 'error' => 'Completed date cannot be in the future']);
        exit();
    }
    
    if ($next_maintenance_date) {
        $next_date_obj = DateTime::createFromFormat('Y-m-d', $next_maintenance_date);
        if (!$next_date_obj || $next_date_obj->format('Y-m-d') !== $next_maintenance_date) {
            echo json_encode(['success' => false, 'error' => 'Invalid next maintenance date format']);
            exit();
        }
        
        if ($next_date_obj <= $date_obj) {
            echo json_encode(['success' => false, 'error' => 'Next maintenance date must be after completion date']);
            exit();
        }
    }
    
    if (!is_numeric($actual_cost) || $actual_cost < 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid actual cost']);
        exit();
    }
    
    $stmt = $pdo->prepare("SELECT id, asset_id, status, scheduled_date FROM maintenance_schedule WHERE id = ?");
    $stmt->execute([$maintenance_id]);
    $maintenance = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$maintenance) {
        echo json_encode(['success' => false, 'error' => 'Maintenance record not found']);
        exit();
    }
    
    if ($maintenance['status'] === 'completed') {
        echo json_encode(['success' => false, 'error' => 'Maintenance is already completed']);
        exit();
    }
    
    $scheduled_date = new DateTime($maintenance['scheduled_date']);
    if ($date_obj < $scheduled_date) {
        echo json_encode(['success' => false, 'error' => 'Completed date cannot be before scheduled date']);
        exit();
    }
    
    $pdo->beginTransaction();
    
    try {
        $stmt = $pdo->prepare("
            UPDATE maintenance_schedule 
            SET status = 'completed',
                completed_date = ?,
                actual_cost = ?,
                parts_used = ?,
                work_performed = ?,
                next_maintenance_date = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            $completed_date,
            $actual_cost,
            $parts_used,
            $work_performed,
            $next_maintenance_date,
            $maintenance_id
        ]);
        
        $stmt = $pdo->prepare("
            UPDATE assets 
            SET status = 'active',
                next_maintenance = ?,
                updated_at = NOW()
            WHERE id = ? AND status = 'maintenance'
        ");
        
        $stmt->execute([
            $next_maintenance_date,
            $maintenance['asset_id']
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Maintenance completed successfully'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Failed to complete maintenance: ' . $e->getMessage()]);
    }
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>