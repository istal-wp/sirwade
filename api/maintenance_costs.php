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
    $start_date = $input['start_date'] ?? date('Y-m-01');
    $end_date = $input['end_date'] ?? date('Y-m-t');

    $servername   = getenv('MYSQLHOST')     ?: getenv('DB_HOST')     ?: 'localhost';


    $db_port      = getenv('MYSQLPORT')     ?: getenv('DB_PORT')     ?: '3306';


    $username     = getenv('MYSQLUSER')     ?: getenv('DB_USER')     ?: 'root';


    $password     = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS')     ?: '';


    $dbname       = getenv('MYSQLDATABASE') ?: getenv('DB_NAME')     ?: 'loogistics';

    $pdo = new PDO("mysql:host=$servername;port=$db_port;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $asset_costs_query = "
        SELECT 
            a.asset_code,
            a.asset_name,
            a.category,
            a.location,
            a.purchase_cost,
            COUNT(m.id) as maintenance_count,
            SUM(COALESCE(m.actual_cost, m.estimated_cost, 0)) as total_cost,
            AVG(COALESCE(m.actual_cost, m.estimated_cost, 0)) as avg_cost,
            SUM(COALESCE(m.actual_hours, m.estimated_hours, 0)) as total_hours,
            MIN(m.completed_date) as first_maintenance,
            MAX(m.completed_date) as last_maintenance,
            ROUND(SUM(COALESCE(m.actual_cost, m.estimated_cost, 0)) / NULLIF(a.purchase_cost, 0) * 100, 2) as cost_percentage_of_value
        FROM assets a
        LEFT JOIN maintenance_schedules m ON a.id = m.asset_id
        WHERE (m.completed_date BETWEEN ? AND ? OR m.completed_date IS NULL)
        AND m.status = 'completed'
        GROUP BY a.id, a.asset_code, a.asset_name, a.category, a.location, a.purchase_cost
        HAVING maintenance_count > 0
        ORDER BY total_cost DESC
    ";
    
    $stmt = $pdo->prepare($asset_costs_query);
    $stmt->execute([$start_date, $end_date]);
    $asset_costs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $type_costs_query = "
        SELECT 
            m.maintenance_type,
            COUNT(*) as count,
            SUM(COALESCE(m.actual_cost, m.estimated_cost, 0)) as total_cost,
            AVG(COALESCE(m.actual_cost, m.estimated_cost, 0)) as avg_cost,
            SUM(COALESCE(m.actual_hours, m.estimated_hours, 0)) as total_hours
        FROM maintenance_schedules m
        WHERE m.completed_date BETWEEN ? AND ?
        AND m.status = 'completed'
        GROUP BY m.maintenance_type
        ORDER BY total_cost DESC
    ";
    
    $stmt = $pdo->prepare($type_costs_query);
    $stmt->execute([$start_date, $end_date]);
    $type_costs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $monthly_costs_query = "
        SELECT 
            DATE_FORMAT(m.completed_date, '%Y-%m') as month,
            DATE_FORMAT(m.completed_date, '%M %Y') as month_name,
            COUNT(*) as maintenance_count,
            SUM(COALESCE(m.actual_cost, m.estimated_cost, 0)) as total_cost,
            SUM(COALESCE(m.actual_hours, m.estimated_hours, 0)) as total_hours
        FROM maintenance_schedules m
        WHERE m.completed_date BETWEEN ? AND ?
        AND m.status = 'completed'
        GROUP BY DATE_FORMAT(m.completed_date, '%Y-%m')
        ORDER BY month
    ";
    
    $stmt = $pdo->prepare($monthly_costs_query);
    $stmt->execute([$start_date, $end_date]);
    $monthly_costs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $expensive_tasks_query = "
        SELECT 
            m.maintenance_title,
            m.maintenance_type,
            m.completed_date,
            m.actual_cost,
            m.actual_hours,
            a.asset_code,
            a.asset_name,
            m.work_performed
        FROM maintenance_schedules m
        JOIN assets a ON m.asset_id = a.id
        WHERE m.completed_date BETWEEN ? AND ?
        AND m.status = 'completed'
        AND m.actual_cost IS NOT NULL
        ORDER BY m.actual_cost DESC
        LIMIT 10
    ";
    
    $stmt = $pdo->prepare($expensive_tasks_query);
    $stmt->execute([$start_date, $end_date]);
    $expensive_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_maintenance_cost = array_sum(array_column($asset_costs, 'total_cost'));
    $total_maintenance_hours = array_sum(array_column($asset_costs, 'total_hours'));
    $total_maintenance_count = array_sum(array_column($asset_costs, 'maintenance_count'));
    $avg_cost_per_maintenance = $total_maintenance_count > 0 ? $total_maintenance_cost / $total_maintenance_count : 0;

    $html_content = generateMaintenanceCostsHTML($asset_costs, $type_costs, $monthly_costs, $expensive_tasks, $total_maintenance_cost, $total_maintenance_hours, $total_maintenance_count, $avg_cost_per_maintenance, $start_date, $end_date);

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
    error_log("Maintenance costs report PDO error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
} catch(Exception $e) {
    error_log("Maintenance costs report error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An error occurred while generating the report']);
}

function generateMaintenanceCostsHTML($asset_costs, $type_costs, $monthly_costs, $expensive_tasks, $total_cost, $total_hours, $total_count, $avg_cost, $start_date, $end_date) {
    $html = '<div class="report-container">';
    
    $html .= '<div style="text-align: center; margin-bottom: 2rem;">';
    $html .= '<h2>Maintenance Costs Analysis Report</h2>';
    $html .= '<p>Period: ' . date('F j, Y', strtotime($start_date)) . ' - ' . date('F j, Y', strtotime($end_date)) . '</p>';
    $html .= '<p>Generated on: ' . date('F j, Y g:i A') . '</p>';
    $html .= '</div>';

    $html .= '<div class="report-section">';
    $html .= '<h3>Cost Summary</h3>';
    $html .= '<div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem;">';
    
    $stats_items = [
        ['label' => 'Total Maintenance Cost', 'value' => '₱' . number_format($total_cost, 2), 'color' => '#dc3545'],
        ['label' => 'Total Maintenance Hours', 'value' => number_format($total_hours, 1) . ' hrs', 'color' => '#007bff'],
        ['label' => 'Number of Maintenances', 'value' => $total_count, 'color' => '#28a745'],
        ['label' => 'Average Cost per Task', 'value' => '₱' . number_format($avg_cost, 2), 'color' => '#ffc107'],
        ['label' => 'Average Cost per Hour', 'value' => '₱' . number_format($total_hours > 0 ? $total_cost / $total_hours : 0, 2), 'color' => '#17a2b8']
    ];
    
    foreach ($stats_items as $item) {
        $html .= '<div style="background: white; padding: 1rem; border-radius: 8px; border-left: 4px solid ' . $item['color'] . '; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        $html .= '<div style="font-size: 24px; font-weight: bold; color: ' . $item['color'] . ';">' . $item['value'] . '</div>';
        $html .= '<div style="color: #666; font-size: 14px;">' . $item['label'] . '</div>';
        $html .= '</div>';
    }
    
    $html .= '</div>';
    $html .= '</div>';

    if (!empty($type_costs)) {
        $html .= '<div class="report-section" style="margin-bottom: 2rem;">';
        $html .= '<h3>Costs by Maintenance Type</h3>';
        $html .= '<table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">';
        $html .= '<thead><tr style="background: #f8f9fa;">';
        $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: left;">Maintenance Type</th>';
        $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">Count</th>';
        $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">Total Cost</th>';
        $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">Average Cost</th>';
        $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">Total Hours</th>';
        $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">% of Total</th>';
        $html .= '</tr></thead><tbody>';
        
        foreach ($type_costs as $type) {
            $percentage = $total_cost > 0 ? ($type['total_cost'] / $total_cost) * 100 : 0;
            $html .= '<tr>';
            $html .= '<td style="padding: 1rem; border: 1px solid #dee2e6;">' . ucfirst($type['maintenance_type']) . '</td>';
            $html .= '<td style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">' . $type['count'] . '</td>';
            $html .= '<td style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">₱' . number_format($type['total_cost'], 2) . '</td>';
            $html .= '<td style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">₱' . number_format($type['avg_cost'], 2) . '</td>';
            $html .= '<td style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">' . number_format($type['total_hours'], 1) . '</td>';
            $html .= '<td style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">' . number_format($percentage, 1) . '%</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
        $html .= '</div>';
    }

    if (!empty($monthly_costs)) {
        $html .= '<div class="report-section" style="margin-bottom: 2rem;">';
        $html .= '<h3>Monthly Maintenance Costs Trend</h3>';
        $html .= '<table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">';
        $html .= '<thead><tr style="background: #f8f9fa;">';
        $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: left;">Month</th>';
        $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">Tasks</th>';
        $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">Total Cost</th>';
        $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">Hours</th>';
        $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">Avg Cost/Task</th>';
        $html .= '</tr></thead><tbody>';
        
        foreach ($monthly_costs as $month) {
            $avg_task_cost = $month['maintenance_count'] > 0 ? $month['total_cost'] / $month['maintenance_count'] : 0;
            $html .= '<tr>';
            $html .= '<td style="padding: 1rem; border: 1px solid #dee2e6;">' . $month['month_name'] . '</td>';
            $html .= '<td style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">' . $month['maintenance_count'] . '</td>';
            $html .= '<td style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">₱' . number_format($month['total_cost'], 2) . '</td>';
            $html .= '<td style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">' . number_format($month['total_hours'], 1) . '</td>';
            $html .= '<td style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">₱' . number_format($avg_task_cost, 2) . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
        $html .= '</div>';
    }

    if (!empty($expensive_tasks)) {
        $html .= '<div class="report-section" style="margin-bottom: 2rem;">';
        $html .= '<h3>Most Expensive Maintenance Tasks</h3>';
        $html .= '<table style="width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 14px;">';
        $html .= '<thead><tr style="background: #f8f9fa;">';
        $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: left;">Date</th>';
        $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: left;">Asset</th>';
        $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: left;">Task</th>';
        $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: center;">Type</th>';
        $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: right;">Cost</th>';
        $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: right;">Hours</th>';
        $html .= '</tr></thead><tbody>';
        
        foreach ($expensive_tasks as $task) {
            $html .= '<tr>';
            $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6;">' . date('M j, Y', strtotime($task['completed_date'])) . '</td>';
            $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6;"><strong>' . htmlspecialchars($task['asset_code']) . '</strong><br><small>' . htmlspecialchars($task['asset_name']) . '</small></td>';
            $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6;">' . htmlspecialchars($task['maintenance_title']) . '</td>';
            $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: center;">' . ucfirst($task['maintenance_type']) . '</td>';
            $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: right; font-weight: bold; color: #dc3545;">₱' . number_format($task['actual_cost'], 2) . '</td>';
            $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: right;">' . number_format($task['actual_hours'], 1) . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
        $html .= '</div>';
    }

    $html .= '<div class="report-section" style="margin-bottom: 2rem;">';
    $html .= '<h3>Maintenance Costs by Asset</h3>';
    $html .= '<table style="width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 14px;">';
    $html .= '<thead><tr style="background: #f8f9fa;">';
    $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: left;">Asset Code</th>';
    $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: left;">Asset Name</th>';
    $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: center;">Category</th>';
    $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: right;">Maintenances</th>';
    $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: right;">Total Cost</th>';
    $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: right;">Avg Cost</th>';
    $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: right;">% of Asset Value</th>';
    $html .= '</tr></thead><tbody>';
    
    foreach ($asset_costs as $asset) {
        $html .= '<tr>';
        $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6;">' . htmlspecialchars($asset['asset_code']) . '</td>';
        $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6;">' . htmlspecialchars($asset['asset_name']) . '</td>';
        $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: center;">' . ucfirst($asset['category']) . '</td>';
        $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: right;">' . $asset['maintenance_count'] . '</td>';
        $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: right;">₱' . number_format($asset['total_cost'], 2) . '</td>';
        $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: right;">₱' . number_format($asset['avg_cost'], 2) . '</td>';
        $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: right;">' . number_format($asset['cost_percentage_of_value'], 2) . '%</td>';
        $html .= '</tr>';
    }
    
    $html .= '</tbody></table>';
    $html .= '</div>';

    $html .= '</div>';
    
    return $html;
}

ob_end_flush();
?>