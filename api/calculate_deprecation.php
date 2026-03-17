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
        SELECT id, asset_code, asset_name, purchase_date, purchase_cost, 
               current_value, depreciation_method, useful_life_years, status,
               last_depreciation_date, accumulated_depreciation
        FROM assets 
        WHERE status IN ('active', 'maintenance') 
        AND purchase_cost > 0 
        AND useful_life_years > 0
        AND purchase_date IS NOT NULL
        AND purchase_date <= CURDATE()
    ");
    
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($assets)) {
        echo json_encode([
            'success' => false, 
            'error' => 'No assets found for depreciation calculation',
            'details' => 'Assets must have valid purchase_date, purchase_cost, and useful_life_years'
        ]);
        exit();
    }
    
    $pdo->beginTransaction();
    
    $updated_count = 0;
    $skipped_count = 0;
    $error_count = 0;
    $calculation_details = [];
    $today = new DateTime();
    
    foreach ($assets as $asset) {
        try {
            $purchase_date = new DateTime($asset['purchase_date']);
            
            $age_interval = $today->diff($purchase_date);
            $age_days = $age_interval->days;
            $age_years = $age_days / 365.25;
            
            if ($age_years < 0.003) {
                $skipped_count++;
                $calculation_details[] = [
                    'asset' => $asset['asset_code'],
                    'status' => 'skipped',
                    'reason' => 'Asset too new (less than 1 day old)'
                ];
                continue;
            }
            
            if ($asset['last_depreciation_date'] === $today->format('Y-m-d')) {
                $skipped_count++;
                $calculation_details[] = [
                    'asset' => $asset['asset_code'],
                    'status' => 'skipped',
                    'reason' => 'Already calculated today'
                ];
                continue;
            }
            
            $purchase_cost = floatval($asset['purchase_cost']);
            $useful_life_years = floatval($asset['useful_life_years']);
            $method = $asset['depreciation_method'];
            $previous_value = floatval($asset['current_value'] ?: $purchase_cost);
            
            $new_value = $purchase_cost;
            $annual_depreciation = 0;
            $depreciation_rate = 0;
            
            switch ($method) {
                case 'straight_line':
                    $annual_depreciation = $purchase_cost / $useful_life_years;
                    $total_depreciation = $annual_depreciation * $age_years;
                    $new_value = max(0, $purchase_cost - $total_depreciation);
                    $depreciation_rate = (1 / $useful_life_years) * 100;
                    break;
                    
                case 'declining_balance':
                    $rate = 2.0 / $useful_life_years;
                    $new_value = $purchase_cost;
                    
                    for ($year = 0; $year < min($age_years, $useful_life_years); $year++) {
                        $year_depreciation = $new_value * $rate;
                        $new_value = max(0, $new_value - $year_depreciation);
                    }
                    
                    $remaining_fraction = $age_years - floor($age_years);
                    if ($remaining_fraction > 0 && $new_value > 0) {
                        $partial_year_depreciation = $new_value * $rate * $remaining_fraction;
                        $new_value = max(0, $new_value - $partial_year_depreciation);
                    }
                    
                    $annual_depreciation = ($purchase_cost - $new_value) / max(1, $age_years);
                    $depreciation_rate = $rate * 100;
                    break;
                    
                case 'sum_of_years':
                    $sum_of_years = ($useful_life_years * ($useful_life_years + 1)) / 2;
                    $total_depreciation = 0;
                    
                    for ($year = 1; $year <= min(ceil($age_years), $useful_life_years); $year++) {
                        $remaining_life = $useful_life_years - $year + 1;
                        $fraction = $remaining_life / $sum_of_years;
                        $year_depreciation = $purchase_cost * $fraction;
                        
                        if ($year == ceil($age_years)) {
                            $partial_year_fraction = $age_years - floor($age_years);
                            if ($partial_year_fraction > 0) {
                                $year_depreciation *= $partial_year_fraction;
                            }
                        }
                        
                        $total_depreciation += $year_depreciation;
                    }
                    
                    $new_value = max(0, $purchase_cost - $total_depreciation);
                    $annual_depreciation = $total_depreciation / max(1, $age_years);
                    $depreciation_rate = ($total_depreciation / $purchase_cost) * 100;
                    break;
                    
                default:
                    throw new Exception("Unknown depreciation method: $method");
            }
            
            $new_value = round($new_value, 2);
            $annual_depreciation = round($annual_depreciation, 2);
            $accumulated_depreciation = round($purchase_cost - $new_value, 2);
            $depreciation_rate = round($depreciation_rate, 4);
            
            $new_value = max(0, min($purchase_cost, $new_value));
            
            $stmt = $pdo->prepare("
                UPDATE assets 
                SET current_value = ?, 
                    accumulated_depreciation = ?,
                    last_depreciation_date = CURDATE(),
                    updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$new_value, $accumulated_depreciation, $asset['id']]);
            
            $stmt = $pdo->prepare("
                INSERT INTO depreciation_calculations 
                (asset_id, calculation_date, method, purchase_cost, age_years, 
                 useful_life_years, previous_value, calculated_value, 
                 annual_depreciation, accumulated_depreciation, depreciation_rate,
                 calculation_notes, created_at)
                VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $calculation_notes = sprintf(
                "Calculated using %s method. Age: %.2f years, Rate: %.2f%%",
                $method,
                $age_years,
                $depreciation_rate
            );
            
            $stmt->execute([
                $asset['id'],
                $method,
                $purchase_cost,
                $age_years,
                $useful_life_years,
                $previous_value,
                $new_value,
                $annual_depreciation,
                $accumulated_depreciation,
                $depreciation_rate,
                $calculation_notes
            ]);
            
            $remaining_life = max(0, $useful_life_years - $age_years);
            
            $stmt = $pdo->prepare("
                INSERT INTO depreciation_history 
                (asset_id, calculation_date, depreciation_method, opening_value, 
                 depreciation_amount, closing_value, accumulated_depreciation, 
                 remaining_life_years, notes, created_at)
                VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $asset['id'],
                $method,
                $previous_value,
                $previous_value - $new_value,
                $new_value,
                $accumulated_depreciation,
                $remaining_life,
                "Automatic calculation - " . $calculation_notes
            ]);
            
            $updated_count++;
            $calculation_details[] = [
                'asset' => $asset['asset_code'],
                'status' => 'success',
                'previous_value' => $previous_value,
                'new_value' => $new_value,
                'depreciation' => $accumulated_depreciation,
                'method' => $method
            ];
            
        } catch (Exception $e) {
            $error_count++;
            $calculation_details[] = [
                'asset' => $asset['asset_code'],
                'status' => 'error',
                'reason' => $e->getMessage()
            ];
            continue;
        }
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "Depreciation calculated for $updated_count asset(s)",
        'summary' => [
            'total_assets' => count($assets),
            'updated' => $updated_count,
            'skipped' => $skipped_count,
            'errors' => $error_count
        ],
        'details' => $calculation_details
    ]);
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false, 
        'error' => 'Database error: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false, 
        'error' => 'Calculation error: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}