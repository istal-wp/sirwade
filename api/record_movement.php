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
    $movement_type = $_POST['movement_type'] ?? null;
    $movement_date = $_POST['movement_date'] ?? null;
    $from_location = $_POST['from_location'] ?? null;
    $to_location = $_POST['to_location'] ?? null;
    $from_person = $_POST['from_person'] ?? null;
    $to_person = $_POST['to_person'] ?? null;
    $condition_before = $_POST['condition_before'] ?? null;
    $condition_after = $_POST['condition_after'] ?? null;
    $reason = $_POST['reason'] ?? '';
    $notes = $_POST['notes'] ?? '';
    
    if (empty($asset_id)) {
        echo json_encode(['success' => false, 'error' => 'Asset ID is required']);
        exit();
    }
    
    if (empty($movement_type)) {
        echo json_encode(['success' => false, 'error' => 'Movement type is required']);
        exit();
    }
    
    if (empty($movement_date)) {
        echo json_encode(['success' => false, 'error' => 'Movement date is required']);
        exit();
    }
    
    $valid_types = ['deployment', 'transfer', 'return', 'maintenance', 'disposal'];
    if (!in_array($movement_type, $valid_types)) {
        echo json_encode(['success' => false, 'error' => 'Invalid movement type']);
        exit();
    }
    
    $date_obj = DateTime::createFromFormat('Y-m-d', $movement_date);
    if (!$date_obj || $date_obj->format('Y-m-d') !== $movement_date) {
        echo json_encode(['success' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD']);
        exit();
    }
    
    $today = new DateTime();
    if ($date_obj > $today) {
        echo json_encode(['success' => false, 'error' => 'Movement date cannot be in the future']);
        exit();
    }
    
    $valid_conditions = ['excellent', 'good', 'fair', 'poor', 'critical', ''];
    if (!in_array($condition_before, $valid_conditions)) {
        echo json_encode(['success' => false, 'error' => 'Invalid condition before rating']);
        exit();
    }
    if (!in_array($condition_after, $valid_conditions)) {
        echo json_encode(['success' => false, 'error' => 'Invalid condition after rating']);
        exit();
    }
    
    $stmt = $pdo->prepare("SELECT id, asset_name, asset_code, status, location, condition_rating FROM assets WHERE id = ?");
    $stmt->execute([$asset_id]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$asset) {
        echo json_encode(['success' => false, 'error' => 'Asset not found']);
        exit();
    }
    
    if ($movement_type === 'disposal' && $asset['status'] === 'disposed') {
        echo json_encode(['success' => false, 'error' => 'Asset is already disposed']);
        exit();
    }
    
    $pdo->beginTransaction();
    
    try {
        $movement_datetime = $movement_date . ' ' . date('H:i:s');
        
        $stmt = $pdo->prepare("
            INSERT INTO asset_movements 
            (asset_id, movement_type, from_location, to_location, from_person, to_person, 
             movement_date, reason, condition_before, condition_after, notes, performed_by, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $performed_by = $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'System';
        
        $stmt->execute([
            $asset_id,
            $movement_type,
            $from_location ?: null,
            $to_location ?: null,
            $from_person ?: null,
            $to_person ?: null,
            $movement_datetime,
            $reason,
            $condition_before ?: null,
            $condition_after ?: null,
            $notes,
            $performed_by
        ]);
        
        $update_fields = [];
        $update_values = [];
        
        switch ($movement_type) {
            case 'deployment':
                if ($to_location) {
                    $update_fields[] = "location = ?";
                    $update_values[] = $to_location;
                }
                if ($to_person) {
                    $update_fields[] = "assigned_to = ?";
                    $update_values[] = $to_person;
                }
                $update_fields[] = "status = 'active'";
                break;
                
            case 'transfer':
                if ($to_location) {
                    $update_fields[] = "location = ?";
                    $update_values[] = $to_location;
                }
                if ($to_person) {
                    $update_fields[] = "assigned_to = ?";
                    $update_values[] = $to_person;
                }
                break;
                
            case 'return':
                if ($to_location) {
                    $update_fields[] = "location = ?";
                    $update_values[] = $to_location;
                }
                $update_fields[] = "assigned_to = NULL";
                $update_fields[] = "status = 'active'";
                break;
                
            case 'maintenance':
                $update_fields[] = "status = 'maintenance'";
                if ($to_location) {
                    $update_fields[] = "location = ?";
                    $update_values[] = $to_location;
                }
                break;
                
            case 'disposal':
                $update_fields[] = "status = 'disposed'";
                $update_fields[] = "assigned_to = NULL";
                break;
        }
        
        if ($condition_after && !empty($condition_after)) {
            $update_fields[] = "condition_rating = ?";
            $update_values[] = $condition_after;
        }
        
        if (!empty($update_fields)) {
            $update_values[] = $asset_id;
            $sql = "UPDATE assets SET " . implode(", ", $update_fields) . ", updated_at = NOW() WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($update_values);
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => ucfirst($movement_type) . ' recorded successfully'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Failed to record movement: ' . $e->getMessage()]);
    }
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>