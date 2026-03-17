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
    $format = $input['format'] ?? 'html';
    $filters = $input['filters'] ?? [];

    $servername   = getenv('MYSQLHOST')     ?: getenv('DB_HOST')     ?: 'localhost';


    $db_port      = getenv('MYSQLPORT')     ?: getenv('DB_PORT')     ?: '3306';


    $username     = getenv('MYSQLUSER')     ?: getenv('DB_USER')     ?: 'root';


    $password     = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS')     ?: '';


    $dbname       = getenv('MYSQLDATABASE') ?: getenv('DB_NAME')     ?: 'loogistics';

    $pdo = new PDO("mysql:host=$servername;port=$db_port;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $where_conditions = ['1=1'];
    $params = [];

    if (!empty($filters['status'])) {
        $where_conditions[] = "ms.status = ?";
        $params[] = $filters['status'];
    }

    if (!empty($filters['maintenance_type'])) {
        $where_conditions[] = "ms.maintenance_type = ?";
        $params[] = $filters['maintenance_type'];
    }

    $where_clause = implode(' AND ', $where_conditions);

    $maintenance_query = "
        SELECT 
            ms.*,
            a.asset_code,
            a.asset_name,
            a.category,
            a.location,
            DATEDIFF(ms.scheduled_date, CURDATE()) as days_until_due,
            CASE 
                WHEN ms.status = 'scheduled' AND ms.scheduled_date < CURDATE() THEN 'overdue'
                ELSE ms.status
            END as actual_status
        FROM maintenance_schedules ms
        LEFT JOIN assets a ON ms.asset_id = a.id
        WHERE " . $where_clause . "
        ORDER BY ms.scheduled_date ASC
    ";

    $stmt = $pdo->prepare($maintenance_query);
    $stmt->execute($params);
    $maintenance_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_tasks = count($maintenance_data);
    $scheduled = 0;
    $in_progress = 0;
    $completed = 0;
    $overdue = 0;

    $upcoming_7days = 0;
    $upcoming_30days = 0;

    foreach ($maintenance_data as $task) {
        switch ($task['actual_status']) {
            case 'scheduled': $scheduled++; break;
            case 'in-progress': $in_progress++; break;
            case 'completed': $completed++; break;
            case 'overdue': $overdue++; break;
        }

        if ($task['days_until_due'] >= 0 && $task['days_until_due'] <= 7) {
            $upcoming_7days++;
        }
        if ($task['days_until_due'] >= 0 && $task['days_until_due'] <= 30) {
            $upcoming_30days++;
        }
    }

    $html_content = generateMaintenanceReportHTML($maintenance_data, $total_tasks, $scheduled, $in_progress, $completed, $overdue, $upcoming_7days, $upcoming_30days);

    if ($format === 'html') {
        echo json_encode([
            'success' => true,
            'html_content' => $html_content,
            'export_options' => [
                ['format' => 'pdf', 'label' => 'Export PDF'],
                ['format' => 'excel', 'label' => 'Export Excel']
            ]
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'data' => $maintenance_data,
            'summary' => [
                'total_tasks' => $total_tasks,
                'scheduled' => $scheduled,
                'in_progress' => $in_progress,
                'completed' => $completed,
                'overdue' => $overdue
            ]
        ]);
    }

} catch(PDOException $e) {
    error_log("Generate maintenance report error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
} catch(Exception $e) {
    error_log("Generate maintenance report error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An error occurred while generating the report']);
}

function generateMaintenanceReportHTML($maintenance_data, $total, $scheduled, $in_progress, $completed, $overdue, $upcoming_7, $upcoming_30) {
    $html = '<div class="report-container">';
    
    $html .= '<div style="text-align: center; margin-bottom: 2rem;">';
    $html .= '<h2>📅 Maintenance Schedule Report</h2>';
    $html .= '<p>Generated on: ' . date('F j, Y g:i A') . '</p>';
    $html .= '<p>Total Maintenance Tasks: ' . number_format($total) . '</p>';
    $html .= '</div>';

    $html .= '<div class="report-section">';
    $html .= '<h3>Maintenance Summary</h3>';
    $html .= '<div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem;">';
    
    $stats_items = [
        ['label' => 'Scheduled', 'value' => $scheduled, 'color' => '#007bff'],
        ['label' => 'In Progress', 'value' => $in_progress, 'color' => '#ffc107'],
        ['label' => 'Completed', 'value' => $completed, 'color' => '#28a745'],
        ['label' => 'Overdue', 'value' => $overdue, 'color' => '#dc3545']
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
    $html .= '<h3>Upcoming Maintenance</h3>';
    $html .= '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 2rem;">';
    $html .= '<div style="background: #fff3cd; padding: 1.5rem; border-radius: 8px; border-left: 4px solid #ffc107;">';
    $html .= '<div style="font-size: 32px; font-weight: bold; color: #856404;">' . $upcoming_7 . '</div>';
    $html .= '<div style="color: #856404;">Due in Next 7 Days</div>';
    $html .= '</div>';
    $html .= '<div style="background: #d1ecf1; padding: 1.5rem; border-radius: 8px; border-left: 4px solid #17a2b8;">';
    $html .= '<div style="font-size: 32px; font-weight: bold; color: #0c5460;">' . $upcoming_30 . '</div>';
    $html .= '<div style="color: #0c5460;">Due in Next 30 Days</div>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<div class="report-section" style="margin-bottom: 2rem;">';
    $html .= '<h3>Maintenance Schedule Details</h3>';
    $html .= '<table style="width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 14px;">';
    $html .= '<thead><tr style="background: #f8f9fa;">';
    $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: left;">Asset Code</th>';
    $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: left;">Asset Name</th>';
    $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: left;">Maintenance Type</th>';
    $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: center;">Scheduled Date</th>';
    $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: center;">Days Until Due</th>';
    $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: center;">Status</th>';
    $html .= '<th style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: left;">Location</th>';
    $html .= '</tr></thead><tbody>';
    
    foreach ($maintenance_data as $task) {
        $days_text = '';
        if ($task['days_until_due'] < 0) {
            $days_text = abs($task['days_until_due']) . ' days overdue';
        } elseif ($task['days_until_due'] == 0) {
            $days_text = 'Due today';
        } else {
            $days_text = $task['days_until_due'] . ' days';
        }

        $html .= '<tr>';
        $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6;">' . htmlspecialchars($task['asset_code'] ?? 'N/A') . '</td>';
        $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6;">' . htmlspecialchars($task['asset_name'] ?? 'N/A') . '</td>';
        $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6;">' . htmlspecialchars($task['maintenance_type'] ?? 'N/A') . '</td>';
        $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: center;">' . date('Y-m-d', strtotime($task['scheduled_date'])) . '</td>';
        $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: center;">' . $days_text . '</td>';
        $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6; text-align: center;"><span class="status-badge status-' . $task['actual_status'] . '">' . ucfirst(str_replace('-', ' ', $task['actual_status'])) . '</span></td>';
        $html .= '<td style="padding: 0.75rem; border: 1px solid #dee2e6;">' . htmlspecialchars($task['location'] ?? 'N/A') . '</td>';
        $html .= '</tr>';
    }
    
    $html .= '</tbody></table>';
    $html .= '</div>';

    $html .= '</div>';
    
    return $html;
}

ob_end_flush();
?>