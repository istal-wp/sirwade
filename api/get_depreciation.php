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
            a.id, a.asset_code, a.asset_name, a.purchase_date, a.purchase_cost,
            a.current_value, a.depreciation_method, a.useful_life_years,
            DATEDIFF(CURDATE(), a.purchase_date) / 365.25 as age_years,
            GREATEST(0, a.useful_life_years - (DATEDIFF(CURDATE(), a.purchase_date) / 365.25)) as remaining_life,
            (a.purchase_cost - a.current_value) as total_depreciation,
            CASE 
                WHEN a.useful_life_years > 0 THEN (a.purchase_cost - a.current_value) / (DATEDIFF(CURDATE(), a.purchase_date) / 365.25)
                ELSE 0
            END as annual_depreciation_rate
        FROM assets a
        WHERE a.status IN ('active', 'maintenance', 'retired')
        AND a.purchase_cost > 0
        AND a.useful_life_years > 0
        AND a.purchase_date IS NOT NULL
        ORDER BY (a.purchase_cost - a.current_value) DESC
    ");
    
    $depreciation = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($depreciation as &$item) {
        $item['age_years'] = round($item['age_years'], 2);
        $item['remaining_life'] = round($item['remaining_life'], 2);
        $item['total_depreciation'] = round($item['total_depreciation'], 2);
        $item['annual_depreciation_rate'] = round($item['annual_depreciation_rate'], 2);
    }
    
    echo json_encode($depreciation);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>