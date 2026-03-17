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
    
    $year = date('Y');
    $month = date('m');
    
    $stmt = $pdo->prepare("
        SELECT asset_code 
        FROM assets 
        WHERE asset_code LIKE ? 
        ORDER BY asset_code DESC 
        LIMIT 1
    ");
    
    $pattern = "AST-$year-$month-%";
    $stmt->execute([$pattern]);
    $lastAsset = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($lastAsset) {
        $parts = explode('-', $lastAsset['asset_code']);
        $sequence = isset($parts[3]) ? intval($parts[3]) : 0;
        $newSequence = $sequence + 1;
    } else {
        $newSequence = 1;
    }
    
    $assetCode = sprintf("AST-%s-%s-%04d", $year, $month, $newSequence);
    
    echo json_encode([
        'success' => true,
        'asset_code' => $assetCode
    ]);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>