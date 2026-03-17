<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_role'] !== 'staff') {
    header("Location: ../login.php");
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
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$report_type = $_GET['type'] ?? 'asset-summary';
$user_name = $_SESSION['user_name'] ?? 'System User';

header('Content-Type: text/html; charset=utf-8');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ucwords(str_replace('-', ' ', $report_type)); ?> Report</title>
    <style>
        @media print {
            body { margin: 0; }
            .no-print { display: none; }
            .page-break { page-break-before: always; }
        }
        
        body {
            font-family: 'Times New Roman', serif;
            line-height: 1.4;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        
        .report-container {
            background: white;
            max-width: 210mm;
            margin: 0 auto;
            padding: 25mm;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            min-height: 297mm;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 3px solid #1e3c72;
            padding-bottom: 20px;
        }
        
        .company-logo {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            border-radius: 12px;
            margin: 0 auto 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: bold;
        }
        
        .company-name {
            font-size: 28px;
            font-weight: bold;
            color: #1e3c72;
            margin: 10px 0 5px;
        }
        
        .company-tagline {
            font-size: 14px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        
        .report-title {
            font-size: 24px;
            font-weight: bold;
            color: #333;
            margin: 20px 0 10px;
        }
        
        .report-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            padding: 15px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
        }
        
        .report-info div {
            text-align: center;
        }
        
        .report-info strong {
            display: block;
            color: #1e3c72;
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-left: 4px solid #1e3c72;
            padding: 20px;
            text-align: center;
        }
        
        .summary-card h3 {
            margin: 0 0 10px;
            color: #1e3c72;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .summary-card .value {
            font-size: 32px;
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }
        
        .summary-card .label {
            font-size: 12px;
            color: #666;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 12px;
        }
        
        .data-table th {
            background: #1e3c72;
            color: white;
            padding: 12px 8px;
            text-align: left;
            font-weight: bold;
            border: 1px solid #1e3c72;
        }
        
        .data-table td {
            padding: 10px 8px;
            border: 1px solid #dee2e6;
            vertical-align: top;
        }
        
        .data-table tbody tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .status-active { background: #d4edda; color: #155724; }
        .status-maintenance { background: #fff3cd; color: #856404; }
        .status-retired { background: #f8d7da; color: #721c24; }
        .condition-excellent { background: #d4edda; color: #155724; }
        .condition-good { background: #d1ecf1; color: #0c5460; }
        .condition-fair { background: #fff3cd; color: #856404; }
        .condition-poor { background: #f8d7da; color: #721c24; }
        
        .footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 2px solid #1e3c72;
            text-align: center;
            font-size: 12px;
            color: #666;
        }
        
        .signature-section {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
        }
        
        .signature-box {
            width: 200px;
            text-align: center;
        }
        
        .signature-line {
            border-bottom: 1px solid #333;
            margin-bottom: 10px;
            height: 40px;
        }
        
        .controls {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }
        
        .btn {
            background: #1e3c72;
            color: white;
            border: none;
            padding: 10px 20px;
            margin: 5px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn:hover {
            background: #2a5298;
        }
        
        .chart-container {
            margin: 20px 0;
            text-align: center;
        }
        
        .depreciation-chart {
            display: inline-block;
            margin: 10px;
            padding: 15px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            text-align: center;
            min-width: 150px;
        }
    </style>
</head>
<body>
    <div class="controls no-print">
        <button class="btn" onclick="window.print()">Print Report</button>
        <a href="alms.php" class="btn">Back to System</a>
    </div>

    <div class="report-container">
        <div class="header">
            <div class="company-logo">BP</div>
            <div class="company-name">BRIGHTPATH</div>
            <div class="company-tagline">Asset Lifecycle Management System</div>
            <div class="report-title"><?php echo ucwords(str_replace('-', ' ', $report_type)); ?> Report</div>
        </div>

        <div class="report-info">
            <div>
                <strong>Report Date</strong>
                <?php echo date('F j, Y'); ?>
            </div>
            <div>
                <strong>Generated By</strong>
                <?php echo htmlspecialchars($user_name); ?>
            </div>
            <div>
                <strong>Report ID</strong>
                RPT-<?php echo date('Ymd-His'); ?>
            </div>
        </div>

        <?php
        switch($report_type) {
            case 'asset-summary':
                generateAssetSummaryReport($pdo);
                break;
            case 'maintenance-schedule':
                generateMaintenanceScheduleReport($pdo);
                break;
            case 'depreciation':
                generateDepreciationReport($pdo);
                break;
            case 'maintenance-costs':
                generateMaintenanceCostsReport($pdo);
                break;
            default:
                generateAssetSummaryReport($pdo);
        }
        ?>

        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line"></div>
                <strong>Prepared By</strong><br>
                <?php echo htmlspecialchars($user_name); ?><br>
                Asset Management Staff
            </div>
            <div class="signature-box">
                <div class="signature-line"></div>
                <strong>Reviewed By</strong><br>
                ________________<br>
                Department Manager
            </div>
            <div class="signature-box">
                <div class="signature-line"></div>
                <strong>Approved By</strong><br>
                ________________<br>
                Operations Director
            </div>
        </div>

        <div class="footer">
            <p><strong>BRIGHTPATH - Asset Lifecycle Management System</strong></p>
            <p>This report is confidential and proprietary. Generated on <?php echo date('F j, Y \a\t g:i A'); ?></p>
            <p>Page 1 of 1 | Report ID: RPT-<?php echo date('Ymd-His'); ?></p>
        </div>
    </div>
</body>
</html>

<?php

function generateAssetSummaryReport($pdo) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as total_assets FROM inventory_items WHERE status = 'active'");
        $total_assets = $stmt->fetch(PDO::FETCH_ASSOC)['total_assets'] ?? 0;
        
        $stmt = $pdo->query("SELECT COUNT(*) as inactive_assets FROM inventory_items WHERE status = 'inactive'");
        $inactive_assets = $stmt->fetch(PDO::FETCH_ASSOC)['inactive_assets'] ?? 0;
        
        $stmt = $pdo->query("SELECT SUM(quantity * unit_price) as total_value FROM inventory_items WHERE status IN ('active', 'inactive')");
        $total_value = $stmt->fetch(PDO::FETCH_ASSOC)['total_value'] ?? 0;
        
        $stmt = $pdo->query("SELECT COUNT(DISTINCT category) as categories FROM inventory_items");
        $total_categories = $stmt->fetch(PDO::FETCH_ASSOC)['categories'] ?? 0;
        
    } catch(PDOException $e) {
        $total_assets = 0;
        $inactive_assets = 0;
        $total_value = 0;
        $total_categories = 0;
    }
    
    echo '<div class="summary-cards">';
    echo '<div class="summary-card">';
    echo '<h3>Total Assets</h3>';
    echo '<div class="value">' . number_format($total_assets) . '</div>';
    echo '<div class="label">Active Items</div>';
    echo '</div>';
    
    echo '<div class="summary-card">';
    echo '<h3>Inactive Assets</h3>';
    echo '<div class="value">' . number_format($inactive_assets) . '</div>';
    echo '<div class="label">Not in Use</div>';
    echo '</div>';
    
    echo '<div class="summary-card">';
    echo '<h3>Total Value</h3>';
    echo '<div class="value">₱' . number_format($total_value, 2) . '</div>';
    echo '<div class="label">Current Worth</div>';
    echo '</div>';
    
    echo '<div class="summary-card">';
    echo '<h3>Categories</h3>';
    echo '<div class="value">' . number_format($total_categories) . '</div>';
    echo '<div class="label">Asset Types</div>';
    echo '</div>';
    echo '</div>';
    
    echo '<h3>Asset Inventory Details</h3>';
    
    try {
        $stmt = $pdo->query("SELECT item_code, item_name, category, quantity, unit_price, 
                            (quantity * unit_price) as total_value, location, status 
                            FROM inventory_items 
                            ORDER BY total_value DESC LIMIT 50");
        
        echo '<table class="data-table">';
        echo '<thead><tr>';
        echo '<th>Item Code</th>';
        echo '<th>Item Name</th>';
        echo '<th>Category</th>';
        echo '<th>Quantity</th>';
        echo '<th>Unit Price</th>';
        echo '<th>Total Value</th>';
        echo '<th>Location</th>';
        echo '<th>Status</th>';
        echo '</tr></thead><tbody>';
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($row['item_code']) . '</td>';
            echo '<td>' . htmlspecialchars($row['item_name']) . '</td>';
            echo '<td>' . htmlspecialchars($row['category']) . '</td>';
            echo '<td>' . number_format($row['quantity']) . '</td>';
            echo '<td>₱' . number_format($row['unit_price'], 2) . '</td>';
            echo '<td>₱' . number_format($row['total_value'], 2) . '</td>';
            echo '<td>' . htmlspecialchars($row['location']) . '</td>';
            echo '<td><span class="status-badge status-' . $row['status'] . '">' . ucfirst($row['status']) . '</span></td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        
    } catch(PDOException $e) {
        echo '<p>No asset data available at this time.</p>';
    }
}

function generateMaintenanceScheduleReport($pdo) {
    echo '<div class="summary-cards">';
    echo '<div class="summary-card">';
    echo '<h3>Scheduled Tasks</h3>';
    echo '<div class="value">0</div>';
    echo '<div class="label">This Month</div>';
    echo '</div>';
    
    echo '<div class="summary-card">';
    echo '<h3>Overdue Tasks</h3>';
    echo '<div class="value">0</div>';
    echo '<div class="label">Past Due</div>';
    echo '</div>';
    
    echo '<div class="summary-card">';
    echo '<h3>Completed</h3>';
    echo '<div class="value">0</div>';
    echo '<div class="label">This Month</div>';
    echo '</div>';
    
    echo '<div class="summary-card">';
    echo '<h3>Maintenance Cost</h3>';
    echo '<div class="value">₱0.00</div>';
    echo '<div class="label">This Month</div>';
    echo '</div>';
    echo '</div>';
    
    echo '<h3>Upcoming Maintenance Schedule</h3>';
    echo '<table class="data-table">';
    echo '<thead><tr>';
    echo '<th>Asset Code</th>';
    echo '<th>Asset Name</th>';
    echo '<th>Maintenance Type</th>';
    echo '<th>Scheduled Date</th>';
    echo '<th>Priority</th>';
    echo '<th>Assigned To</th>';
    echo '<th>Status</th>';
    echo '</tr></thead><tbody>';
    
    echo '<tr><td colspan="7" style="text-align: center; color: #666; font-style: italic;">No maintenance schedules found. Maintenance tracking will be available once assets are added to the system.</td></tr>';
    
    echo '</tbody></table>';
}

function generateDepreciationReport($pdo) {
    echo '<div class="summary-cards">';
    echo '<div class="summary-card">';
    echo '<h3>Original Value</h3>';
    echo '<div class="value">₱0.00</div>';
    echo '<div class="label">Purchase Cost</div>';
    echo '</div>';
    
    echo '<div class="summary-card">';
    echo '<h3>Current Value</h3>';
    echo '<div class="value">₱0.00</div>';
    echo '<div class="label">Book Value</div>';
    echo '</div>';
    
    echo '<div class="summary-card">';
    echo '<h3>Depreciated</h3>';
    echo '<div class="value">₱0.00</div>';
    echo '<div class="label">Total Loss</div>';
    echo '</div>';
    
    echo '<div class="summary-card">';
    echo '<h3>Avg. Age</h3>';
    echo '<div class="value">0</div>';
    echo '<div class="label">Years</div>';
    echo '</div>';
    echo '</div>';
    
    echo '<h3>Asset Depreciation Analysis</h3>';
    
    try {
        $stmt = $pdo->query("SELECT item_code, item_name, category, 
                            (quantity * unit_price) as current_value,
                            created_at
                            FROM inventory_items 
                            WHERE status IN ('active', 'inactive')
                            ORDER BY current_value DESC");
        
        echo '<table class="data-table">';
        echo '<thead><tr>';
        echo '<th>Asset Code</th>';
        echo '<th>Asset Name</th>';
        echo '<th>Category</th>';
        echo '<th>Current Value</th>';
        echo '<th>Age (Years)</th>';
        echo '<th>Depreciation Rate</th>';
        echo '<th>Annual Depreciation</th>';
        echo '</tr></thead><tbody>';
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $age = (time() - strtotime($row['created_at'])) / (365 * 24 * 3600);
            $age = max(0, round($age, 1));
            $depreciation_rate = 20;
            $annual_depreciation = $row['current_value'] * ($depreciation_rate / 100);
            
            echo '<tr>';
            echo '<td>' . htmlspecialchars($row['item_code']) . '</td>';
            echo '<td>' . htmlspecialchars($row['item_name']) . '</td>';
            echo '<td>' . htmlspecialchars($row['category']) . '</td>';
            echo '<td>₱' . number_format($row['current_value'], 2) . '</td>';
            echo '<td>' . $age . '</td>';
            echo '<td>' . $depreciation_rate . '%</td>';
            echo '<td>₱' . number_format($annual_depreciation, 2) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        
    } catch(PDOException $e) {
        echo '<p>No depreciation data available at this time.</p>';
    }
}

function generateMaintenanceCostsReport($pdo) {
    echo '<div class="summary-cards">';
    echo '<div class="summary-card">';
    echo '<h3>Total Costs</h3>';
    echo '<div class="value">₱0.00</div>';
    echo '<div class="label">This Year</div>';
    echo '</div>';
    
    echo '<div class="summary-card">';
    echo '<h3>Preventive</h3>';
    echo '<div class="value">₱0.00</div>';
    echo '<div class="label">Scheduled</div>';
    echo '</div>';
    
    echo '<div class="summary-card">';
    echo '<h3>Corrective</h3>';
    echo '<div class="value">₱0.00</div>';
    echo '<div class="label">Repairs</div>';
    echo '</div>';
    
    echo '<div class="summary-card">';
    echo '<h3>Emergency</h3>';
    echo '<div class="value">₱0.00</div>';
    echo '<div class="label">Urgent Fixes</div>';
    echo '</div>';
    echo '</div>';
    
    echo '<h3>Maintenance Cost Breakdown by Asset</h3>';
    echo '<table class="data-table">';
    echo '<thead><tr>';
    echo '<th>Asset Code</th>';
    echo '<th>Asset Name</th>';
    echo '<th>Total Cost</th>';
    echo '<th>Preventive</th>';
    echo '<th>Corrective</th>';
    echo '<th>Emergency</th>';
    echo '<th>Last Service</th>';
    echo '</tr></thead><tbody>';
    
    echo '<tr><td colspan="7" style="text-align: center; color: #666; font-style: italic;">No maintenance cost data available. Costs will be tracked once maintenance activities are recorded.</td></tr>';
    
    echo '</tbody></table>';
    
    echo '<div style="margin-top: 30px;">';
    echo '<h3>Cost Analysis Summary</h3>';
    echo '<p><strong>Maintenance Cost Trends:</strong></p>';
    echo '<ul>';
    echo '<li>Preventive maintenance typically costs 3-5x less than corrective maintenance</li>';
    echo '<li>Regular maintenance schedules help reduce emergency repair costs</li>';
    echo '<li>Asset age and condition directly impact maintenance frequency and costs</li>';
    echo '<li>Proper maintenance extends asset life by 20-30% on average</li>';
    echo '</ul>';
    echo '</div>';
}
?>