<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
ob_clean();

try {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_role'] !== 'staff') {
        echo json_encode(['success' => false, 'error' => 'Not logged in or insufficient permissions']);
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $calculation_date = $input['calculation_date'] ?? date('Y-m-d');

    $servername   = getenv('MYSQLHOST')     ?: getenv('DB_HOST')     ?: 'localhost';


    $db_port      = getenv('MYSQLPORT')     ?: getenv('DB_PORT')     ?: '3306';


    $username     = getenv('MYSQLUSER')     ?: getenv('DB_USER')     ?: 'root';


    $password     = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS')     ?: '';


    $dbname       = getenv('MYSQLDATABASE') ?: getenv('DB_NAME')     ?: 'loogistics';

    $pdo = new PDO("mysql:host=$servername;port=$db_port;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $assets_query = "
        SELECT 
            a.*,
            s.supplier_name,
            DATEDIFF(?, COALESCE(a.purchase_date, a.created_at)) as days_owned,
            ROUND(DATEDIFF(?, COALESCE(a.purchase_date, a.created_at)) / 365.25, 2) as years_owned,
            CASE 
                WHEN a.depreciation_method = 'straight_line' THEN
                    GREATEST(0, a.purchase_cost - (a.purchase_cost * LEAST(1, DATEDIFF(?, COALESCE(a.purchase_date, a.created_at)) / 365.25 / a.useful_life_years)))
                WHEN a.depreciation_method = 'declining_balance' THEN
                    a.purchase_cost * POWER(1 - (2.0 / a.useful_life_years), DATEDIFF(?, COALESCE(a.purchase_date, a.created_at)) / 365.25)
                ELSE
                    a.current_value
            END as calculated_value,
            CASE 
                WHEN a.depreciation_method = 'straight_line' THEN
                    LEAST(a.purchase_cost, a.purchase_cost * DATEDIFF(?, COALESCE(a.purchase_date, a.created_at)) / 365.25 / a.useful_life_years)
                WHEN a.depreciation_method = 'declining_balance' THEN
                    a.purchase_cost - (a.purchase_cost * POWER(1 - (2.0 / a.useful_life_years), DATEDIFF(?, COALESCE(a.purchase_date, a.created_at)) / 365.25))
                ELSE
                    a.purchase_cost - a.current_value
            END as total_depreciation,
            CASE 
                WHEN DATEDIFF(?, COALESCE(a.purchase_date, a.created_at)) / 365.25 >= a.useful_life_years THEN 'Fully Depreciated'
                WHEN DATEDIFF(?, COALESCE(a.purchase_date, a.created_at)) / 365.25 >= a.useful_life_years * 0.8 THEN 'Near End of Life'
                ELSE 'Active Depreciation'
            END as depreciation_status
        FROM assets a
        LEFT JOIN suppliers s ON a.supplier_id = s.id
        WHERE a.status IN ('active', 'maintenance', 'retired')
        AND a.purchase_cost > 0
        ORDER BY a.category, a.asset_name
    ";
    
    $stmt = $pdo->prepare($assets_query);
    $stmt->execute(array_fill(0, 8, $calculation_date));
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_original_value = array_sum(array_column($assets, 'purchase_cost'));
    $total_current_value = array_sum(array_column($assets, 'calculated_value'));
    $total_depreciation = $total_original_value - $total_current_value;
    $avg_depreciation_rate = $total_original_value > 0 ? ($total_depreciation / $total_original_value) * 100 : 0;

    $categories = [];
    foreach ($assets as $asset) {
        $cat = $asset['category'];
        if (!isset($categories[$cat])) {
            $categories[$cat] = [
                'count' => 0,
                'original_value' => 0,
                'current_value' => 0,
                'depreciation' => 0
            ];
        }
        $categories[$cat]['count']++;
        $categories[$cat]['original_value'] += $asset['purchase_cost'];
        $categories[$cat]['current_value'] += $asset['calculated_value'];
        $categories[$cat]['depreciation'] += $asset['total_depreciation'];
    }

    $html_content = generateDepreciationHTML($assets, $categories, $total_original_value, $total_current_value, $total_depreciation, $avg_depreciation_rate, $calculation_date);

    echo json_encode([
        'success' => true,
        'html_content' => $html_content,
        'export_options' => [
            ['format' => 'pdf', 'label' => 'Export PDF'],
            ['format' => 'excel', 'label' => 'Export Excel'],
            ['format' => 'csv', 'label' => 'Export CSV']
        ]
    ]);

} catch(PDOException $e) {
    error_log("Depreciation report PDO error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
} catch(Exception $e) {
    error_log("Depreciation report error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An error occurred while generating the report']);
}

function generateDepreciationHTML($assets, $categories, $total_original, $total_current, $total_depreciation, $avg_rate, $calculation_date) {
    $html = '<div class="report-container">';
    
    $html .= '<div style="text-align: center; margin-bottom: 2rem;">';
    $html .= '<h2>Asset Depreciation Report</h2>';
    $html .= '<p>Calculation Date: ' . date('F j, Y', strtotime($calculation_date)) . '</p>';
    $html .= '<p>Generated on: ' . date('F j, Y g:i A') . '</p>';
    $html .= '</div>';

    $html .= '<div class="report-section">';
    $html .= '<h3>Depreciation Summary</h3>';
    $html .= '<div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-bottom: 2rem;">';
    
    $stats_items = [
        ['label' => 'Original Value', 'value' => '₱' . number_format($total_original, 2), 'color' => '#007bff'],
        ['label' => 'Current Value', 'value' => '₱' . number_format($total_current, 2), 'color' => '#28a745'],
        ['label' => 'Total Depreciation', 'value' => '₱' . number_format($total_depreciation, 2), 'color' => '#dc3545'],
        ['label' => 'Average Depreciation Rate', 'value' => number_format($avg_rate, 2) . '%', 'color' => '#ffc107']
    ];
    
    foreach ($stats_items as $item) {
        $html .= '<div style="background: white; padding: 1rem; border-radius: 8px; border-left: 4px solid ' . $item['color'] . '; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        $html .= '<div style="font-size: 24px; font-weight: bold; color: ' . $item['color'] . ';">' . $item['value'] . '</div>';
        $html .= '<div style="color: #666; font-size: 14px;">' . $item['label'] . '</div>';
        $html .= '</div>';
    }
    
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<div class="report-section" style="margin-bottom: 2rem;">';
    $html .= '<h3>Depreciation by Category</h3>';
    $html .= '<table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">';
    $html .= '<thead><tr style="background: #f8f9fa;">';
    $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: left;">Category</th>';
    $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">Assets</th>';
    $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">Original Value</th>';
    $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">Current Value</th>';
    $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">Depreciation</th>';
    $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">Rate</th>';
    $html .= '</tr></thead><tbody>';
    
    foreach ($categories as $category => $data) {
        $rate = $data['original_value'] > 0 ? ($data['depreciation'] / $data['original_value']) * 100 : 0;
        $html .= '<tr>';
        $html .= '<td style="padding: 1rem; border: 1px solid #dee2e6;">' . ucfirst($category) . '</td>';
        $html .= '<td style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">' . $data['count'] . '</td>';
        $html .= '<td style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">₱' . number_format($data['current_value'], 2) . '</td>';
        $html .= '<td style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">₱' . number_format($data['depreciation'], 2) . '</td>';
        $html .= '<td style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">' . number_format($rate, 2) . '%</td>';
        $html .= '</tr>';
    }
    
    $html .= '</tbody></table>';
    $html .= '</div>';

    $html .= '<div class="report-section" style="margin-bottom: 2rem;">';
    $html .= '<h3>Detailed Asset Depreciation</h3>';
    $html .= '<table style="width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 14px;">';
    $html .= '<thead><tr style="background: #f8f9fa;">';
    $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: left;">Asset Code</th>';
    $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: left;">Asset Name</th>';
    $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: center;">Category</th>';
    $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: center;">Age (Years)</th>';
    $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: right;">Original Value</th>';
    $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: right;">Current Value</th>';
    $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: right;">Depreciation</th>';
    $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: center;">Status</th>';
    $html .= '</tr></thead><tbody>';
    
    foreach ($assets as $asset) {
        $status_color = getDepreciationStatusColor($asset['depreciation_status']);
        $html .= '<tr>';
        $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6;">' . htmlspecialchars($asset['asset_code']) . '</td>';
        $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6;">' . htmlspecialchars($asset['asset_name']) . '</td>';
        $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: center;">' . ucfirst($asset['category']) . '</td>';
        $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: center;">' . $asset['years_owned'] . '</td>';
        $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: right;">₱' . number_format($asset['purchase_cost'], 2) . '</td>';
        $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: right;">₱' . number_format($asset['calculated_value'], 2) . '</td>';
        $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: right;">₱' . number_format($asset['total_depreciation'], 2) . '</td>';
        $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: center;"><span style="padding: 4px 8px; border-radius: 4px; font-size: 12px; background: ' . $status_color . '; color: white;">' . $asset['depreciation_status'] . '</span></td>';
        $html .= '</tr>';
    }
    
    $html .= '</tbody></table>';
    $html .= '</div>';

    $html .= '</div>';
    
    return $html;
}

function getDepreciationStatusColor($status) {
    switch ($status) {
        case 'Active Depreciation': return '#28a745';
        case 'Near End of Life': return '#ffc107';
        case 'Fully Depreciated': return '#dc3545';
        default: return '#6c757d';
    }
}

ob_end_flush();
?> 