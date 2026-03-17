<?php
ob_start();

session_start();
header('Content-Type: application/json');

ob_clean();

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
    
    $asset_id = isset($_GET['asset_id']) ? intval($_GET['asset_id']) : null;
    $action_filter = isset($_GET['action']) ? $_GET['action'] : null;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 1000;
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    
    $query = "
        SELECT 
            ch.id,
            ch.asset_id,
            ch.action,
            ch.action_date,
            ch.assigned_to,
            ch.previous_assigned_to,
            ch.location,
            ch.previous_location,
            ch.notes,
            ch.condition_before,
            ch.condition_after,
            ch.performed_by,
            ch.created_at,
            a.asset_code,
            a.asset_name,
            a.category,
            a.location as current_location,
            a.assigned_to as current_assigned_to,
            a.status as current_status,
            a.condition_rating as current_condition
        FROM check_in_out_history ch
        LEFT JOIN assets a ON ch.asset_id = a.id
        WHERE 1=1
    ";
    
    $params = [];
    
    if ($asset_id) {
        $query .= " AND ch.asset_id = :asset_id";
        $params[':asset_id'] = $asset_id;
    }
    
    if ($action_filter && in_array($action_filter, ['check_in', 'check_out'])) {
        $query .= " AND ch.action = :action";
        $params[':action'] = $action_filter;
    }
    
    $query .= " ORDER BY ch.action_date DESC, ch.created_at DESC";
    $query .= " LIMIT :limit OFFSET :offset";
    
    $stmt = $pdo->prepare($query);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $countQuery = "SELECT COUNT(*) as total FROM check_in_out_history ch WHERE 1=1";
    $countParams = [];
    
    if ($asset_id) {
        $countQuery .= " AND ch.asset_id = :asset_id";
        $countParams[':asset_id'] = $asset_id;
    }
    
    if ($action_filter && in_array($action_filter, ['check_in', 'check_out'])) {
        $countQuery .= " AND ch.action = :action";
        $countParams[':action'] = $action_filter;
    }
    
    $countStmt = $pdo->prepare($countQuery);
    foreach ($countParams as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $statsQuery = "
        SELECT 
            COUNT(*) as total_transactions,
            SUM(CASE WHEN action = 'check_out' THEN 1 ELSE 0 END) as total_checkouts,
            SUM(CASE WHEN action = 'check_in' THEN 1 ELSE 0 END) as total_checkins,
            COUNT(DISTINCT asset_id) as unique_assets,
            COUNT(DISTINCT performed_by) as unique_users
        FROM check_in_out_history
    ";
    
    if ($asset_id) {
        $statsQuery .= " WHERE asset_id = :asset_id";
    }
    
    $statsStmt = $pdo->prepare($statsQuery);
    if ($asset_id) {
        $statsStmt->bindValue(':asset_id', $asset_id, PDO::PARAM_INT);
    }
    $statsStmt->execute();
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    ob_clean();
    echo json_encode([
        'success' => true,
        'data' => $history,
        'count' => count($history),
        'total' => intval($total),
        'limit' => $limit,
        'offset' => $offset,
        'stats' => $stats,
        'filters' => [
            'asset_id' => $asset_id,
            'action' => $action_filter
        ]
    ]);
    
} catch(PDOException $e) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch(Exception $e) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'error' => 'Unexpected error: ' . $e->getMessage()
    ]);
}

ob_end_flush();
?>