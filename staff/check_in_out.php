<?php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
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
    
    $assetId = $_POST['asset_id'] ?? null;
    $actionType = $_POST['action_type'] ?? null;
    $location = $_POST['location'] ?? '';
    $assignedPerson = $_POST['assigned_person'] ?? '';
    $notes = $_POST['notes'] ?? '';
    
    if (!$assetId || !$actionType) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit();
    }
    
    $stmt = $pdo->prepare("SELECT asset_code, asset_name, location, assigned_to FROM assets WHERE id = ?");
    $stmt->execute([$assetId]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$asset) {
        echo json_encode(['success' => false, 'error' => 'Asset not found']);
        exit();
    }
    
    $movementType = ($actionType == 'check_out') ? 'deployment' : 'return';
    $fromLocation = ($actionType == 'check_out') ? $asset['location'] : $location;
    $toLocation = ($actionType == 'check_out') ? $location : $asset['location'];
    $fromPerson = ($actionType == 'check_out') ? '' : $assignedPerson;
    $toPerson = ($actionType == 'check_out') ? $assignedPerson : '';
    
    $stmt = $pdo->prepare("
        INSERT INTO asset_movements 
        (asset_id, movement_type, from_location, to_location, from_person, to_person, movement_date, performed_by, notes)
        VALUES (?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)
    ");
    
    $stmt->execute([
        $assetId,
        $movementType,
        $fromLocation,
        $toLocation,
        $fromPerson,
        $toPerson,
        $_SESSION['user_name'],
        $notes
    ]);
    
    if ($actionType == 'check_out') {
        $updateStmt = $pdo->prepare("UPDATE assets SET location = ?, assigned_to = ? WHERE id = ?");
        $updateStmt->execute([$location, $assignedPerson, $assetId]);
    } else {
        $updateStmt = $pdo->prepare("UPDATE assets SET assigned_to = NULL WHERE id = ?");
        $updateStmt->execute([$assetId]);
    }
    
    echo json_encode(['success' => true]);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>