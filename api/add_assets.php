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
    
    $asset_code = $_POST['asset_code'] ?? null;
    $asset_name = $_POST['asset_name'] ?? null;
    $category = $_POST['category'] ?? null;
    $brand = $_POST['brand'] ?? '';
    $model = $_POST['model'] ?? '';
    $serial_number = $_POST['serial_number'] ?? '';
    $purchase_date = $_POST['purchase_date'] ?? null;
    $purchase_cost = $_POST['purchase_cost'] ?? 0;
    $current_value = $_POST['current_value'] ?? 0;
    $location = $_POST['location'] ?? '';
    $assigned_to = $_POST['assigned_to'] ?? '';
    $status = $_POST['status'] ?? 'active';
    $condition_rating = $_POST['condition_rating'] ?? 'good';
    $useful_life_years = $_POST['useful_life_years'] ?? 5;
    $depreciation_method = $_POST['depreciation_method'] ?? 'straight_line';
    $description = $_POST['description'] ?? '';
    $notes = $_POST['notes'] ?? '';
    
    if (empty($asset_code) || empty($asset_name) || empty($category)) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit();
    }
    
    $valid_statuses = ['active', 'maintenance', 'retired', 'disposed'];
    if (!in_array($status, $valid_statuses)) {
        echo json_encode(['success' => false, 'error' => 'Invalid status']);
        exit();
    }
    
    $valid_conditions = ['excellent', 'good', 'fair', 'poor', 'critical'];
    if (!in_array($condition_rating, $valid_conditions)) {
        echo json_encode(['success' => false, 'error' => 'Invalid condition rating']);
        exit();
    }
    
    $valid_methods = ['straight_line', 'declining_balance', 'sum_of_years'];
    if (!in_array($depreciation_method, $valid_methods)) {
        echo json_encode(['success' => false, 'error' => 'Invalid depreciation method']);
        exit();
    }
    
    if (!is_numeric($purchase_cost) || $purchase_cost < 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid purchase cost']);
        exit();
    }
    
    if (!is_numeric($current_value) || $current_value < 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid current value']);
        exit();
    }
    
    if (!is_numeric($useful_life_years) || $useful_life_years <= 0 || $useful_life_years > 50) {
        echo json_encode(['success' => false, 'error' => 'Useful life must be between 1 and 50 years']);
        exit();
    }
    
    if ($purchase_date) {
        $date_obj = DateTime::createFromFormat('Y-m-d', $purchase_date);
        if (!$date_obj || $date_obj->format('Y-m-d') !== $purchase_date) {
            echo json_encode(['success' => false, 'error' => 'Invalid purchase date format']);
            exit();
        }
        
        $today = new DateTime();
        if ($date_obj > $today) {
            echo json_encode(['success' => false, 'error' => 'Purchase date cannot be in the future']);
            exit();
        }
    }
    
    $stmt = $pdo->prepare("SELECT id FROM assets WHERE asset_code = ?");
    $stmt->execute([$asset_code]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Asset code already exists']);
        exit();
    }
    
    if (empty($current_value) || $current_value == 0) {
        $current_value = $purchase_cost;
    }
    
    $next_maintenance = date('Y-m-d', strtotime('+90 days'));
    
    $pdo->beginTransaction();
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO assets 
            (asset_code, asset_name, category, brand, model, serial_number,
             purchase_date, purchase_cost, current_value, location, assigned_to,
             status, condition_rating, depreciation_method, useful_life_years,
             next_maintenance, description, notes, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        
        $created_by = $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'System';
        
        $stmt->execute([
            $asset_code,
            $asset_name,
            $category,
            $brand,
            $model,
            $serial_number,
            $purchase_date ?: null,
            $purchase_cost,
            $current_value,
            $location,
            $assigned_to ?: null,
            $status,
            $condition_rating,
            $depreciation_method,
            $useful_life_years,
            $next_maintenance,
            $description,
            $notes,
            $created_by
        ]);
        
        $asset_id = $pdo->lastInsertId();
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Asset added successfully',
            'asset_id' => $asset_id
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Failed to add asset: ' . $e->getMessage()]);
    }
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>