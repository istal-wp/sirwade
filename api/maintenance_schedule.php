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
    $end_date = $input['end_date'] ?? date('Y-m-t', strtotime('+2 months'));
    $include_overdue = $input['include_overdue'] ?? true;

    $servername   = getenv('MYSQLHOST')     ?: getenv('DB_HOST')     ?: 'localhost';


    $db_port      = getenv('MYSQLPORT')     ?: getenv('DB_PORT')     ?: '3306';


    $username     = getenv('MYSQLUSER')     ?: getenv('DB_USER')     ?: 'root';


    $password     = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS')     ?: '';


    $dbname       = getenv('MYSQLDATABASE') ?: getenv('DB_NAME')     ?: 'loogistics';

    $pdo = new PDO("mysql:host=$servername;port=$db_port;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $scheduled_query = "
        SELECT 
            m.*,
            a.asset_code,
            a.asset_name,
            a.category,
            a.location,
            CASE 
                WHEN m.scheduled_date < CURDATE() AND m.status = 'scheduled' THEN 'overdue'
                ELSE m.status
            END as current_status,
            DATEDIFF(m.scheduled_date, CURDATE()) as days_until_due
        FROM maintenance_schedules m
        JOIN assets a ON m.asset_id = a.id
        WHERE m.scheduled_date BETWEEN ? AND ?
        ORDER BY m.scheduled_date ASC, m.priority DESC
    ";
    
    $stmt = $pdo->prepare($scheduled_query);
    $stmt->execute([$start_date, $end_date]);
    $scheduled_maintenance = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $overdue_maintenance = [];
    if ($include_overdue) {
        $overdue_query = "
            SELECT 
                m.*,
                a.asset_code,
                a.asset_name,
                a.category,
                a.location,
                'overdue' as current_status,
                DATEDIFF(CURDATE(), m.scheduled_date) as days_overdue
            FROM maintenance_schedules m
            JOIN assets a ON m.asset_id = a.id
            WHERE m.scheduled_date < ? AND m.status = 'scheduled'
            ORDER BY m.scheduled_date ASC, m.priority DESC
        ";
        
        $stmt = $pdo->prepare($overdue_query);
        $stmt->execute([$start_date]);
        $overdue_maintenance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stats_query = "
        SELECT 
            COUNT(*) as total_scheduled,
            SUM(CASE WHEN scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as due_this_week,
            SUM(CASE WHEN scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as due_this_month,
            SUM(CASE WHEN priority = 'critical' THEN 1 ELSE 0 END) as critical_priority,
            SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END) as high_priority,
            SUM(estimated_cost) as estimated_total_cost
        FROM maintenance_schedules m
        JOIN assets a ON m.asset_id = a.id
        WHERE m.scheduled_date BETWEEN ? AND ? AND m.status = 'scheduled'
    ";
    
    $stmt = $pdo->prepare($stats_query);
    $stmt->execute([$start_date, $end_date]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    $html_content = generateMaintenanceScheduleHTML($scheduled_maintenance, $overdue_maintenance, $stats, $start_date, $end_date);

    echo json_encode([
        'success' => true,
        'html_content' => $html_content,
        'export_options' => [
            ['format' => 'pdf', 'label' => 'Export PDF'],
            ['format' => 'excel', 'label' => 'Export Excel'],
            ['format' => 'calendar', 'label' => 'Export Calendar']
        ]
    ]);

} catch(PDOException $e) {
    error_log("Maintenance schedule report PDO error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
} catch(Exception $e) {
    error_log("Maintenance schedule report error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An error occurred while generating the report']);
}

function generateMaintenanceScheduleHTML($scheduled, $overdue, $stats, $start_date, $end_date) {
    $html = '<div class="report-container">';
    
    $html .= '<div style="text-align: center; margin-bottom: 2rem;">';
    $html .= '<h2>Maintenance Schedule Report</h2>';
    $html .= '<p>Period: ' . date('F j, Y', strtotime($start_date)) . ' - ' . date('F j, Y', strtotime($end_date)) . '</p>';
    $html .= '<p>Generated on: ' . date('F j, Y g:i A') . '</p>';
    $html .= '</div>';

    $html .= '<div class="report-section">';
    $html .= '<h3>Summary Statistics</h3>';
    $html .= '<div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem;">';
    
    $stats_items = [
        ['label' => 'Total Scheduled', 'value' => $stats['total_scheduled'], 'color' => '#007bff'],
        ['label' => 'Due This Week', 'value' => $stats['due_this_week'], 'color' => '#ffc107'],
        ['label' => 'Due This Month', 'value' => $stats['due_this_month'], 'color' => '#17a2b8'],
        ['label' => 'Critical Priority', 'value' => $stats['critical_priority'], 'color' => '#dc3545'],
        ['label' => 'Overdue Tasks', 'value' => count($overdue), 'color' => '#dc3545'],
        ['label' => 'Estimated Cost', 'value' => '₱' . number_format($stats['estimated_total_cost'], 2), 'color' => '#28a745']
    ];
    
    foreach ($stats_items as $item) {
        $html .= '<div style="background: white; padding: 1rem; border-radius: 8px; border-left: 4px solid ' . $item['color'] . '; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        $html .= '<div style="font-size: 24px; font-weight: bold; color: ' . $item['color'] . ';">' . $item['value'] . '</div>';
        $html .= '<div style="color: #666; font-size: 14px;">' . $item['label'] . '</div>';
        $html .= '</div>';
    }
    
    $html .= '</div>';
    $html .= '</div>';

    if (!empty($overdue)) {
        $html .= '<div class="report-section" style="margin-bottom: 2rem;">';
        $html .= '<h3 style="color: #dc3545;">⚠️ Overdue Maintenance Tasks</h3>';
        $html .= '<table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">';
        $html .= '<thead><tr style="background: #f8d7da;">';
        $html .= '<th style="padding: 1rem; border: 1px solid #f5c6cb; text-align: left;">Asset</th>';
        $html .= '<th style="padding: 1rem; border: 1px solid #f5c6cb; text-align: left;">Task</th>';
        $html .= '<th style="padding: 1rem; border: 1px solid #f5c6cb; text-align: center;">Due Date</th>';
        $html .= '<th style="padding: 1rem; border: 1px solid #f5c6cb; text-align: center;">Days Overdue</th>';
        $html .= '<th style="padding: 1rem; border: 1px solid #f5c6cb; text-align: center;">Priority</th>';
        $html .= '<th style="padding: 1rem; border: 1px solid #f5c6cb; text-align: left;">Technician</th>';
        $html .= '</tr></thead><tbody>';
        
        foreach ($overdue as $task) {
            $html .= '<tr>';
            $html .= '<td style="padding: 1rem; border: 1px solid #f5c6cb;"><strong>' . htmlspecialchars($task['asset_code']) . '</strong><br><small>' . htmlspecialchars($task['asset_name']) . '</small></td>';
            $html .= '<td style="padding: 1rem; border: 1px solid #f5c6cb;">' . htmlspecialchars($task['maintenance_title']) . '</td>';
            $html .= '<td style="padding: 1rem; border: 1px solid #f5c6cb; text-align: center;">' . date('M j, Y', strtotime($task['scheduled_date'])) . '</td>';
            $html .= '<td style="padding: 1rem; border: 1px solid #f5c6cb; text-align: center; color: #dc3545; font-weight: bold;">' . $task['days_overdue'] . '</td>';
            $html .= '<td style="padding: 1rem; border: 1px solid #f5c6cb; text-align: center;"><span class="priority-badge priority-' . $task['priority'] . '">' . ucfirst($task['priority']) . '</span></td>';
            $html .= '<td style="padding: 1rem; border: 1px solid #f5c6cb;">' . ($task['assigned_technician'] ?: 'Not Assigned') . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
        $html .= '</div>';
    }

    $html .= '<div class="report-section" style="margin-bottom: 2rem;">';
    $html .= '<h3>Scheduled Maintenance Tasks</h3>';
    
    if (!empty($scheduled)) {
        $html .= '<table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">';
        $html .= '<thead><tr style="background: #f8f9fa;">';
        $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: left;">Asset</th>';
        $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: left;">Task</th>';
        $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: center;">Scheduled Date</th>';
        $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: center;">Type</th>';
        $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: center;">Priority</th>';
        $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: left;">Technician</th>';
        $html .= '<th style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">Est. Cost</th>';
        $html .= '</tr></thead><tbody>';
        
        foreach ($scheduled as $task) {
            $row_style = '';
            if ($task['current_status'] === 'overdue') {
                $row_style = 'background: #f8d7da;';
            } elseif ($task['days_until_due'] <= 7) {
                $row_style = 'background: #fff3cd;';
            }
            
            $html .= '<tr style="' . $row_style . '">';
            $html .= '<td style="padding: 1rem; border: 1px solid #dee2e6;"><strong>' . htmlspecialchars($task['asset_code']) . '</strong><br><small>' . htmlspecialchars($task['asset_name']) . '</small><br><span style="font-size: 12px; color: #666;">' . htmlspecialchars($task['location']) . '</span></td>';
            $html .= '<td style="padding: 1rem; border: 1px solid #dee2e6;">' . htmlspecialchars($task['maintenance_title']) . '</td>';
            $html .= '<td style="padding: 1rem; border: 1px solid #dee2e6; text-align: center;">' . date('M j, Y', strtotime($task['scheduled_date'])) . '</td>';
            $html .= '<td style="padding: 1rem; border: 1px solid #dee2e6; text-align: center;">' . ucfirst($task['maintenance_type']) . '</td>';
            $html .= '<td style="padding: 1rem; border: 1px solid #dee2e6; text-align: center;"><span class="priority-badge priority-' . $task['priority'] . '">' . ucfirst($task['priority']) . '</span></td>';
            $html .= '<td style="padding: 1rem; border: 1px solid #dee2e6;">' . ($task['assigned_technician'] ?: 'Not Assigned') . '</td>';
            $html .= '<td style="padding: 1rem; border: 1px solid #dee2e6; text-align: right;">₱' . number_format($task['estimated_cost'], 2) . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
    } else {
        $html .= '<p style="text-align: center; padding: 2rem; color: #666;">No maintenance tasks scheduled for the selected period.</p>';
    }
    
    $html .= '</div>';

    $html .= '</div>';
    
    return $html;
}

ob_end_flush();
?>