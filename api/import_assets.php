<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');

session_start();

try {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        throw new Exception('Unauthorized access');
    }

    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error');
    }

    $file = $_FILES['import_file'];
    $fileName = $file['name'];
    $fileTmpPath = $file['tmp_name'];
    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if (!in_array($fileExtension, ['csv', 'xlsx'])) {
        throw new Exception('Invalid file type. Only CSV and XLSX files are allowed.');
    }

    $servername   = getenv('MYSQLHOST')     ?: getenv('DB_HOST')     ?: 'localhost';


    $db_port      = getenv('MYSQLPORT')     ?: getenv('DB_PORT')     ?: '3306';


    $username     = getenv('MYSQLUSER')     ?: getenv('DB_USER')     ?: 'root';


    $password     = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS')     ?: '';


    $dbname       = getenv('MYSQLDATABASE') ?: getenv('DB_NAME')     ?: 'loogistics';

    $pdo = new PDO("mysql:host=$servername;port=$db_port;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $imported_count = 0;
    $errors = [];

    if ($fileExtension === 'csv') {
        if (($handle = fopen($fileTmpPath, "r")) !== FALSE) {
            $header = fgetcsv($handle);
            
            $expectedColumns = [
                'asset_name', 'category', 'brand', 'model', 'serial_number', 
                'purchase_date', 'purchase_cost', 'location', 'condition_rating'
            ];
            
            $rowNumber = 1;
            while (($data = fgetcsv($handle)) !== FALSE) {
                $rowNumber++;
                
                if (count($data) < count($expectedColumns)) {
                    $errors[] = "Row $rowNumber: Insufficient columns";
                    continue;
                }
                
                try {
                    $year = date('Y');
                    $stmt = $pdo->prepare("SELECT COUNT(*) + 1 as next_num FROM assets WHERE asset_code LIKE ?");
                    $stmt->execute(["AST-$year-%"]);
                    $nextNum = $stmt->fetch(PDO::FETCH_ASSOC)['next_num'];
                    $assetCode = sprintf("AST-%s-%04d", $year, $nextNum);
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO assets (
                            asset_code, asset_name, category, brand, model, serial_number,
                            purchase_date, purchase_cost, location, condition_rating, 
                            current_value, created_by, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $purchaseDate = !empty($data[5]) ? date('Y-m-d', strtotime($data[5])) : null;
                    $purchaseCost = !empty($data[6]) ? floatval($data[6]) : 0;
                    
                    $stmt->execute([
                        $assetCode,
                        $data[0],
                        $data[1] ?: 'equipment',
                        $data[2] ?: null,
                        $data[3] ?: null,
                        $data[4] ?: null,
                        $purchaseDate,
                        $purchaseCost,
                        $data[7] ?: 'Unknown',
                        $data[8] ?: 'good',
                        $purchaseCost,
                        $_SESSION['user_name']
                    ]);
                    
                    $imported_count++;
                    
                } catch (Exception $e) {
                    $errors[] = "Row $rowNumber: " . $e->getMessage();
                }
            }
            fclose($handle);
        }
    } elseif ($fileExtension === 'xlsx') {
        throw new Exception('XLSX import not yet implemented. Please convert to CSV format.');
    }

    ob_clean();
    
    echo json_encode([
        'success' => true,
        'imported_count' => $imported_count,
        'errors' => $errors,
        'message' => "Successfully imported $imported_count assets" . 
                   (count($errors) > 0 ? " with " . count($errors) . " errors" : "")
    ]);

} catch (Exception $e) {
    ob_clean();
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'imported_count' => $imported_count ?? 0
    ]);
}

ob_end_flush();
?>