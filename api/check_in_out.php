<?php
ob_start();

session_start();
header('Content-Type: application/json');

ob_clean();

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
    $action = $_POST['action'] ?? null;
    $action_date = $_POST['action_date'] ?? null;
    $assigned_to = $_POST['assigned_to'] ?? null;
    $location = $_POST['location'] ?? null;
    $notes = $_POST['notes'] ?? '';
    
    if (empty($asset_id) || empty($action) || empty($action_date)) {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Missing required fields: asset_id, action, and action_date are required']);
        exit();
    }
    
    if (!in_array($action, ['check_in', 'check_out'])) {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Invalid action type. Must be check_in or check_out']);
        exit();
    }
    
    $date_obj = DateTime::createFromFormat('Y-m-d', $action_date);
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    
    if (!$date_obj || $date_obj->format('Y-m-d') !== $action_date) {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD']);
        exit();
    }
    
    if ($date_obj > $today) {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Cannot use future date. Date must be today or earlier']);
        exit();
    }
    
    $stmt = $pdo->prepare("SELECT id, asset_name, asset_code, status, location, assigned_to, condition_rating FROM assets WHERE id = ?");
    $stmt->execute([$asset_id]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$asset) {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Asset not found']);
        exit();
    }
    
    if ($asset['status'] === 'disposed' || $asset['status'] === 'retired') {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Cannot check in/out a disposed or retired asset']);
        exit();
    }
    
    $pdo->beginTransaction();
    
    try {
        $previous_assigned_to = $asset['assigned_to'];
        $previous_location = $asset['location'];
        
        $stmt = $pdo->prepare("
            INSERT INTO check_in_out_history 
            (asset_id, action, action_date, assigned_to, previous_assigned_to, 
             location, previous_location, notes, performed_by, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $performed_by = $_SESSION['user_email'] ?? $_SESSION['user_name'] ?? 'System';
        
        $stmt->execute([
            $asset_id,
            $action,
            $action_date,
            $assigned_to,
            $previous_assigned_to,
            $location,
            $previous_location,
            $notes,
            $performed_by
        ]);
        
        $update_fields = [];
        $update_values = [];
        
        if ($action === 'check_out') {
            if (!empty($assigned_to)) {
                $update_fields[] = "assigned_to = ?";
                $update_values[] = $assigned_to;
            }
            if (!empty($location)) {
                $update_fields[] = "location = ?";
                $update_values[] = $location;
            }
            if ($asset['status'] !== 'maintenance') {
                $update_fields[] = "status = ?";
                $update_values[] = 'active';
            }
        } else {
            $update_fields[] = "assigned_to = NULL";
            if (!empty($location)) {
                $update_fields[] = "location = ?";
                $update_values[] = $location;
            }
        }
        
        if (!empty($update_fields)) {
            $update_values[] = $asset_id;
            $sql = "UPDATE assets SET " . implode(", ", $update_fields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($update_values);
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO asset_movements 
            (asset_id, movement_type, from_location, to_location, from_person, to_person,
             movement_date, reason, condition_before, condition_after, notes, performed_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $movement_type = $action === 'check_out' ? 'deployment' : 'return';
        $reason = 'Asset Check-' . ($action === 'check_out' ? 'Out' : 'In');
        
        $stmt->execute([
            $asset_id,
            $movement_type,
            $previous_location,
            $location,
            $previous_assigned_to,
            $assigned_to,
            $action_date,
            $reason,
            $asset['condition_rating'] ?? null,
            $asset['condition_rating'] ?? null,
            $notes,
            $performed_by
        ]);
        
        $pdo->commit();
        
        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Asset ' . ($action === 'check_out' ? 'checked out' : 'checked in') . ' successfully',
            'data' => [
                'asset_code' => $asset['asset_code'],
                'asset_name' => $asset['asset_name'],
                'action' => $action,
                'date' => $action_date,
                'assigned_to' => $assigned_to,
                'location' => $location
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Transaction failed: ' . $e->getMessage()]);
    }
    
} catch(PDOException $e) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch(Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Unexpected error: ' . $e->getMessage()]);
}

ob_end_flush();
?>