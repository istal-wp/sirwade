<?php
session_start();
ob_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_role'] !== 'staff') {
    die('Unauthorized access');
}

if (!defined('FPDF_FONTPATH')) {
    define('FPDF_FONTPATH', '');
}

require_once('../includes/fpdf.php');

$servername   = getenv('MYSQLHOST')     ?: getenv('DB_HOST')     ?: 'localhost';
$db_port      = getenv('MYSQLPORT')     ?: getenv('DB_PORT')     ?: '3306';
$username     = getenv('MYSQLUSER')     ?: getenv('DB_USER')     ?: 'root';
$password     = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS')     ?: '';
$dbname       = getenv('MYSQLDATABASE') ?: getenv('DB_NAME')     ?: 'loogistics';

try {
    $pdo = new PDO("mysql:host=$servername;port=$db_port;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $query = "
        SELECT 
            a.*,
            s.supplier_name,
            DATEDIFF(CURDATE(), COALESCE(a.purchase_date, a.created_at)) / 365.25 as years_owned,
            CASE 
                WHEN a.depreciation_method = 'straight_line' THEN
                    GREATEST(0, a.purchase_cost - (a.purchase_cost * LEAST(1, DATEDIFF(CURDATE(), COALESCE(a.purchase_date, a.created_at)) / 365.25 / a.useful_life_years)))
                ELSE a.current_value
            END as calculated_current_value
        FROM assets a
        LEFT JOIN suppliers s ON a.supplier_id = s.id
        ORDER BY a.category, a.asset_name
    ";

    $stmt = $pdo->query($query);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_assets = count($assets);
    $total_original = array_sum(array_column($assets, 'purchase_cost'));
    $total_current = array_sum(array_column($assets, 'calculated_current_value'));
    $total_depreciation = $total_original - $total_current;

    $status_summary = [];
    foreach ($assets as $asset) {
        $status = $asset['status'];
        if (!isset($status_summary[$status])) {
            $status_summary[$status] = ['count' => 0, 'value' => 0];
        }
        $status_summary[$status]['count']++;
        $status_summary[$status]['value'] += $asset['calculated_current_value'];
    }

    $category_summary = [];
    foreach ($assets as $asset) {
        $cat = $asset['category'];
        if (!isset($category_summary[$cat])) {
            $category_summary[$cat] = ['count' => 0, 'original' => 0, 'current' => 0];
        }
        $category_summary[$cat]['count']++;
        $category_summary[$cat]['original'] += $asset['purchase_cost'];
        $category_summary[$cat]['current'] += $asset['calculated_current_value'];
    }

    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->AddPage();
    
    
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->Cell(0, 10, 'Asset Summary Report', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, 'Generated on: ' . date('F j, Y g:i A'), 0, 1, 'C');
    $pdf->Ln(5);

    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, 'Financial Summary', 0, 1);
    $pdf->SetFont('Arial', '', 9);
    
    $pdf->SetFillColor(248, 249, 250);
    $pdf->Cell(55, 7, 'Total Assets', 1, 0, 'L', true);
    $pdf->Cell(55, 7, 'Original Value', 1, 0, 'L', true);
    $pdf->Cell(55, 7, 'Current Value', 1, 0, 'L', true);
    $pdf->Cell(55, 7, 'Total Depreciation', 1, 0, 'L', true);
    $pdf->Cell(47, 7, 'Retention Rate', 1, 1, 'L', true);
    
    $pdf->Cell(55, 7, $total_assets, 1, 0, 'C');
    $pdf->Cell(55, 7, 'P' . number_format($total_original, 2), 1, 0, 'R');
    $pdf->Cell(55, 7, 'P' . number_format($total_current, 2), 1, 0, 'R');
    $pdf->Cell(55, 7, 'P' . number_format($total_depreciation, 2), 1, 0, 'R');
    $retention = $total_original > 0 ? ($total_current / $total_original) * 100 : 0;
    $pdf->Cell(47, 7, number_format($retention, 2) . '%', 1, 1, 'R');
    $pdf->Ln(5);

    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, 'Assets by Status', 0, 1);
    $pdf->SetFont('Arial', '', 9);
    
    $pdf->SetFillColor(248, 249, 250);
    $pdf->Cell(70, 7, 'Status', 1, 0, 'L', true);
    $pdf->Cell(50, 7, 'Count', 1, 0, 'C', true);
    $pdf->Cell(70, 7, 'Total Value', 1, 0, 'R', true);
    $pdf->Cell(77, 7, 'Percentage', 1, 1, 'R', true);
    
    foreach ($status_summary as $status => $data) {
        $pct = $total_assets > 0 ? ($data['count'] / $total_assets) * 100 : 0;
        $pdf->Cell(70, 7, ucfirst($status), 1, 0, 'L');
        $pdf->Cell(50, 7, $data['count'], 1, 0, 'C');
        $pdf->Cell(70, 7, 'P' . number_format($data['value'], 2), 1, 0, 'R');
        $pdf->Cell(77, 7, number_format($pct, 1) . '%', 1, 1, 'R');
    }
    $pdf->Ln(5);

    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, 'Assets by Category', 0, 1);
    $pdf->SetFont('Arial', '', 9);
    
    $pdf->SetFillColor(248, 249, 250);
    $pdf->Cell(60, 7, 'Category', 1, 0, 'L', true);
    $pdf->Cell(30, 7, 'Count', 1, 0, 'C', true);
    $pdf->Cell(60, 7, 'Original Value', 1, 0, 'R', true);
    $pdf->Cell(60, 7, 'Current Value', 1, 0, 'R', true);
    $pdf->Cell(57, 7, 'Depreciation', 1, 1, 'R', true);
    
    foreach ($category_summary as $cat => $data) {
        $dep = $data['original'] - $data['current'];
        $pdf->Cell(60, 7, ucfirst($cat), 1, 0, 'L');
        $pdf->Cell(30, 7, $data['count'], 1, 0, 'C');
        $pdf->Cell(60, 7, 'P' . number_format($data['original'], 2), 1, 0, 'R');
        $pdf->Cell(60, 7, 'P' . number_format($data['current'], 2), 1, 0, 'R');
        $pdf->Cell(57, 7, 'P' . number_format($dep, 2), 1, 1, 'R');
    }

    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, 'Detailed Asset Inventory', 0, 1);
    $pdf->SetFont('Arial', '', 7);
    
    $pdf->SetFillColor(248, 249, 250);
    $pdf->Cell(25, 6, 'Asset Code', 1, 0, 'L', true);
    $pdf->Cell(50, 6, 'Asset Name', 1, 0, 'L', true);
    $pdf->Cell(30, 6, 'Category', 1, 0, 'L', true);
    $pdf->Cell(22, 6, 'Status', 1, 0, 'C', true);
    $pdf->Cell(22, 6, 'Condition', 1, 0, 'C', true);
    $pdf->Cell(45, 6, 'Location', 1, 0, 'L', true);
    $pdf->Cell(40, 6, 'Current Value', 1, 0, 'R', true);
    $pdf->Cell(33, 6, 'Age (Years)', 1, 1, 'C', true);
    
    foreach ($assets as $asset) {
        $pdf->Cell(25, 6, substr($asset['asset_code'], 0, 12), 1, 0, 'L');
        $pdf->Cell(50, 6, substr($asset['asset_name'], 0, 25), 1, 0, 'L');
        $pdf->Cell(30, 6, ucfirst($asset['category']), 1, 0, 'L');
        $pdf->Cell(22, 6, ucfirst($asset['status']), 1, 0, 'C');
        $pdf->Cell(22, 6, ucfirst($asset['condition_rating']), 1, 0, 'C');
        $pdf->Cell(45, 6, substr($asset['location'], 0, 22), 1, 0, 'L');
        $pdf->Cell(40, 6, 'P' . number_format($asset['calculated_current_value'], 2), 1, 0, 'R');
        $pdf->Cell(33, 6, number_format($asset['years_owned'], 1), 1, 1, 'C');
    }

    while (ob_get_level()) {
        ob_end_clean();
    }

    if (headers_sent($file, $line)) {
        die("Headers already sent in $file on line $line");
    }

    $pdf->Output('I', 'Asset_Summary_Report_' . date('Y-m-d') . '.pdf');
    exit();

} catch (PDOException $e) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    die('Database error: ' . $e->getMessage());
}
?>