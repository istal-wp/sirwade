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
    
    $pdo->exec("
        UPDATE maintenance_schedule 
        SET status = 'overdue' 
        WHERE status = 'scheduled' 
        AND scheduled_date < CURDATE()
    ");
    
    $stmt = $pdo->query("
        SELECT 
            ms.id, ms.asset_id, ms.maintenance_title, ms.maintenance_type,
            ms.description, ms.scheduled_date, ms.priority, ms.status,
            ms.estimated_cost, ms.actual_cost, ms.assigned_technician,
            ms.parts_used, ms.work_performed, ms.completed_date,
            ms.next_maintenance_date, ms.notes,
            a.asset_name, a.asset_code
        FROM maintenance_schedule ms
        LEFT JOIN assets a ON ms.asset_id = a.id
        ORDER BY 
            CASE ms.status
                WHEN 'overdue' THEN 1
                WHEN 'in_progress' THEN 2
                WHEN 'scheduled' THEN 3
                WHEN 'completed' THEN 4
                ELSE 5
            END,
            ms.scheduled_date ASC
    ");
    
    $maintenance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($maintenance);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>