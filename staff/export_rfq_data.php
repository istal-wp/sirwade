<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_role'] !== 'staff') {
    header("Location: ../login.php");
    exit();
}

$host = 'localhost';
$dbname = 'loogistics';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

$stmt = $pdo->prepare("
    SELECT 
        rr.rfq_number,
        rr.title,
        rr.description,
        rr.request_date,
        rr.response_deadline,
        rr.delivery_required_date,
        rr.status,
        rr.created_at,
        COUNT(rs.id) as supplier_count,
        COUNT(CASE WHEN rs.response_received = 1 THEN 1 END) as responses_received,
        COUNT(sq.id) as quotations_count,
        COALESCE(AVG(sq.total_amount), 0) as avg_quotation_amount,
        COALESCE(MIN(sq.total_amount), 0) as min_quotation_amount,
        COALESCE(MAX(sq.total_amount), 0) as max_quotation_amount
    FROM rfq_requests rr
    LEFT JOIN rfq_suppliers rs ON rr.id = rs.rfq_id
    LEFT JOIN supplier_quotations sq ON rr.id = sq.rfq_id
    GROUP BY rr.id
    ORDER BY rr.created_at DESC
");
$stmt->execute();
$rfqs = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="rfq_report_' . date('Y-m-d') . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

fputcsv($output, [
    'RFQ Number',
    'Title',
    'Description',
    'Request Date',
    'Response Deadline',
    'Delivery Required Date',
    'Status',
    'Created Date',
    'Suppliers Invited',
    'Responses Received',
    'Response Rate (%)',
    'Quotations Count',
    'Average Quote Amount',
    'Minimum Quote Amount',
    'Maximum Quote Amount'
]);

foreach ($rfqs as $rfq) {
    $response_rate = $rfq['supplier_count'] > 0 ? 
        round(($rfq['responses_received'] / $rfq['supplier_count']) * 100, 2) : 0;
    
    fputcsv($output, [
        $rfq['rfq_number'],
        $rfq['title'],
        $rfq['description'],
        date('Y-m-d', strtotime($rfq['request_date'])),
        date('Y-m-d', strtotime($rfq['response_deadline'])),
        $rfq['delivery_required_date'] ? date('Y-m-d', strtotime($rfq['delivery_required_date'])) : '',
        ucwords(str_replace('_', ' ', $rfq['status'])),
        date('Y-m-d H:i:s', strtotime($rfq['created_at'])),
        $rfq['supplier_count'],
        $rfq['responses_received'],
        $response_rate . '%',
        $rfq['quotations_count'],
        number_format($rfq['avg_quotation_amount'], 2),
        number_format($rfq['min_quotation_amount'], 2),
        number_format($rfq['max_quotation_amount'], 2)
    ]);
}

fclose($output);
exit();