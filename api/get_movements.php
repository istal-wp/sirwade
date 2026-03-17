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
    
    $stmt = $pdo->query("
        SELECT 
            am.id, am.asset_id, am.movement_type, am.from_location, am.to_location,
            am.from_person, am.to_person, am.movement_date, am.reason,
            am.condition_before, am.condition_after, am.notes, am.performed_by,
            a.asset_name, a.asset_code
        FROM asset_movements am
        LEFT JOIN assets a ON am.asset_id = a.id
        ORDER BY am.movement_date DESC, am.created_at DESC
    ");
    
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($movements as &$movement) {
        if (isset($movement['movement_date'])) {
            $date = new DateTime($movement['movement_date']);
            $movement['movement_date'] = $date->format('Y-m-d');
        }
    }
    
    echo json_encode($movements);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>