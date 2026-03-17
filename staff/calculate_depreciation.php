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
    
    $stmt = $pdo->query("
        SELECT id, purchase_cost, purchase_date, useful_life_years, depreciation_method
        FROM assets 
        WHERE purchase_cost > 0 AND purchase_date IS NOT NULL
    ");
    
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $updated = 0;
    
    foreach ($assets as $asset) {
        $purchaseCost = floatval($asset['purchase_cost']);
        $usefulLife = intval($asset['useful_life_years']);
        $purchaseDate = new DateTime($asset['purchase_date']);
        $today = new DateTime();
        $yearsElapsed = $purchaseDate->diff($today)->y + ($purchaseDate->diff($today)->m / 12);
        
        $currentValue = $purchaseCost;
        
        switch ($asset['depreciation_method']) {
            case 'straight_line':
                $annualDepreciation = $purchaseCost / $usefulLife;
                $totalDepreciation = $annualDepreciation * $yearsElapsed;
                $currentValue = max(0, $purchaseCost - $totalDepreciation);
                break;
                
            case 'declining_balance':
                $rate = 2 / $usefulLife;
                $currentValue = $purchaseCost * pow((1 - $rate), $yearsElapsed);
                break;
                
            case 'sum_of_years':
                $sumOfYears = ($usefulLife * ($usefulLife + 1)) / 2;
                $currentValue = $purchaseCost;
                for ($year = 1; $year <= min(floor($yearsElapsed), $usefulLife); $year++) {
                    $yearDepreciation = ($purchaseCost * ($usefulLife - $year + 1)) / $sumOfYears;
                    $currentValue -= $yearDepreciation;
                }
                break;
        }
        
        $updateStmt = $pdo->prepare("UPDATE assets SET current_value = ? WHERE id = ?");
        $updateStmt->execute([max(0, $currentValue), $asset['id']]);
        $updated++;
    }
    
    echo json_encode(['success' => true, 'updated' => $updated]);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>