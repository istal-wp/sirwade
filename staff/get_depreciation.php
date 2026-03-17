<?php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode([]);
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
            id,
            asset_code,
            asset_name,
            category,
            purchase_cost,
            current_value,
            depreciation_method,
            useful_life_years,
            purchase_date,
            TIMESTAMPDIFF(YEAR, purchase_date, CURDATE()) as age_years,
            useful_life_years - TIMESTAMPDIFF(YEAR, purchase_date, CURDATE()) as remaining_life
        FROM assets 
        WHERE status IN ('active', 'maintenance')
        AND purchase_cost > 0
        ORDER BY purchase_date DESC
    ");
    
    $depreciation = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($depreciation);
    
} catch(PDOException $e) {
    echo json_encode([]);
}
?>