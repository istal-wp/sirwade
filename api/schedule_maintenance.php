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
    
    $asset_id = $_POST['asset_id'] ?? null;
    $maintenance_type = $_POST['maintenance_type'] ?? null;
    $maintenance_title = $_POST['maintenance_title'] ?? null;
    $scheduled_date = $_POST['scheduled_date'] ?? null;
    $priority = $_POST['priority'] ?? 'medium';
    $estimated_cost = $_POST['estimated_cost'] ?? 0;
    $assigned_technician = $_POST['assigned_technician'] ?? null;
    $description = $_POST['description'] ?? '';
    
    if (empty($asset_id) || empty($maintenance_type) || empty($maintenance_title) || empty($scheduled_date)) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit();
    }
    
    $valid_types = ['preventive', 'corrective', 'emergency', 'routine'];
    if (!in_array($maintenance_type, $valid_types)) {
        echo json_encode(['success' => false, 'error' => 'Invalid maintenance type']);
        exit();
    }
    
    $valid_priorities = ['low', 'medium', 'high'];
    if (!in_array($priority, $valid_priorities)) {
        echo json_encode(['success' => false, 'error' => 'Invalid priority level']);
        exit();
    }
    
    $date_obj = DateTime::createFromFormat('Y-m-d', $scheduled_date);
    if (!$date_obj || $date_obj->format('Y-m-d') !== $scheduled_date) {
        echo json_encode(['success' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD']);
        exit();
    }
    
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    
    if ($date_obj < $today) {
        echo json_encode(['success' => false, 'error' => 'Scheduled date cannot be in the past']);
        exit();
    }
    
    $stmt = $pdo->prepare("SELECT id, asset_name, status FROM assets WHERE id = ?");
    $stmt->execute([$asset_id]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$asset) {
        echo json_encode(['success' => false, 'error' => 'Asset not found']);
        exit();
    }
    
    if ($asset['status'] === 'disposed') {
        echo json_encode(['success' => false, 'error' => 'Cannot schedule maintenance for disposed asset']);
        exit();
    }
    
    if (!is_numeric($estimated_cost) || $estimated_cost < 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid estimated cost']);
        exit();
    }
    
    $pdo->beginTransaction();
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO maintenance_schedule 
            (asset_id, maintenance_title, maintenance_type, description, scheduled_date, 
             priority, status, estimated_cost, assigned_technician, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?, ?, NOW(), NOW())
        ");
        
        $stmt->execute([
            $asset_id,
            $maintenance_title,
            $maintenance_type,
            $description,
            $scheduled_date,
            $priority,
            $estimated_cost,
            $assigned_technician
        ]);
        
        $maintenance_id = $pdo->lastInsertId();
        
        $stmt = $pdo->prepare("UPDATE assets SET next_maintenance = ? WHERE id = ?");
        $stmt->execute([$scheduled_date, $asset_id]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Maintenance scheduled successfully',
            'maintenance_id' => $maintenance_id
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Failed to schedule maintenance: ' . $e->getMessage()]);
    }
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>