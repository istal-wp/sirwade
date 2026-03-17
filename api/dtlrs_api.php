<?php
header('Content-Type: application/json');
require_once('../config.php');

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';
$response = ['success' => false, 'message' => 'Invalid action'];

switch($action) {
    case 'upload_document':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($_FILES['document'])) {
                $response = ['success' => false, 'message' => 'No file uploaded'];
                break;
            }

            $document = $_FILES['document'];
            $document_type = $_POST['document_type'] ?? '';
            $related_to = $_POST['related_to'] ?? '';
            $related_id = $_POST['related_id'] ?? '';
            
            $document_code = 'DOC-' . date('dmY') . '-' . rand(1000, 9999);
            
            $ext = pathinfo($document['name'], PATHINFO_EXTENSION);
            
            $file_path = "../uploads/documents/{$document_code}.{$ext}";
            
            try {
                if (move_uploaded_file($document['tmp_name'], $file_path)) {
                    $stmt = $pdo->prepare("INSERT INTO documents 
                                         (document_code, document_type, title, related_to, related_id, 
                                          file_path, uploaded_by, upload_date)
                                         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                    
                    $stmt->execute([
                        $document_code,
                        $document_type,
                        $document['name'],
                        $related_to,
                        $related_id,
                        $file_path,
                        $_SESSION['user_id']
                    ]);
                    
                    $response = ['success' => true, 'document_code' => $document_code];
                } else {
                    $response = ['success' => false, 'message' => 'Failed to move uploaded file'];
                }
            } catch(PDOException $e) {
                $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }
        break;

    case 'get_document':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $document_code = $_GET['document_code'] ?? '';
            
            if ($document_code) {
                $stmt = $pdo->prepare("SELECT * FROM documents WHERE document_code = ?");
                
                try {
                    $stmt->execute([$document_code]);
                    $document = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($document) {
                        $file_path = $document['file_path'];
                        if (file_exists($file_path)) {
                            header('Content-Type: application/octet-stream');
                            header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
                            readfile($file_path);
                            exit;
                        } else {
                            $response = ['success' => false, 'message' => 'File not found'];
                        }
                    } else {
                        $response = ['success' => false, 'message' => 'Document not found'];
                    }
                } catch(PDOException $e) {
                    $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
                }
            }
        }
        break;

    case 'generate_audit_trail':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $end_date = $_GET['end_date'] ?? date('Y-m-d');
            
            try {
                $stmt = $pdo->prepare("SELECT d.*, u.username as uploaded_by_name
                                     FROM documents d
                                     JOIN users u ON d.uploaded_by = u.id
                                     WHERE d.upload_date BETWEEN ? AND ?
                                     ORDER BY d.upload_date DESC");
                
                $stmt->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
                $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $response = [
                    'success' => true,
                    'data' => [
                        'period' => [
                            'start' => $start_date,
                            'end' => $end_date
                        ],
                        'documents' => $documents
                    ]
                ];
            } catch(PDOException $e) {
                $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }
        }
        break;
}

echo json_encode($response);