<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_role'] !== 'staff') {
    header("Location: ../login.php");
    exit();
}

$user_name = $_SESSION['user_name'];
$document_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($document_id <= 0) {
    header("Location: dtlrs.php?error=invalid_document_id");
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
    
    $stmt = $pdo->prepare("
        SELECT d.*, dt.type_name 
        FROM documents d 
        LEFT JOIN document_types dt ON d.document_type_id = dt.id 
        WHERE d.id = ?
    ");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$document) {
        header("Location: view_document.php?id=$document_id&error=document_not_found");
        exit();
    }
    
    if (in_array($document['status'], ['archived', 'expired']) && $_SESSION['user_role'] !== 'admin') {
        header("Location: view_document.php?id=$document_id&error=access_denied");
        exit();
    }
    
    if (!$document['file_path'] || !file_exists($document['file_path']) || !is_readable($document['file_path'])) {
        header("Location: view_document.php?id=$document_id&error=file_not_found");
        exit();
    }
    
    $real_file_path = realpath($document['file_path']);
    $upload_dir = realpath('uploads/documents/');
    
    if (!$real_file_path || !$upload_dir || strpos($real_file_path, $upload_dir) !== 0) {
        header("Location: view_document.php?id=$document_id&error=invalid_file_path");
        exit();
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO document_access_logs 
        (document_id, accessed_by, access_type, access_details, ip_address, user_agent) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $document_id,
        $user_name,
        'download',
        'File downloaded: ' . $document['file_name'],
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]);
    
    $file_size = filesize($real_file_path);
    $file_name = $document['file_name'];
    $file_type = $document['file_type'];
    
    $safe_filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file_name);
    
    if (strpos($file_type, 'pdf') !== false) {
        $content_type = 'application/pdf';
    } elseif (strpos($file_type, 'word') !== false || strpos($file_type, 'document') !== false) {
        $content_type = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    } elseif (strpos($file_type, 'excel') !== false || strpos($file_type, 'spreadsheet') !== false) {
        $content_type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    } elseif (strpos($file_type, 'image') !== false) {
        $content_type = $file_type;
    } else {
        $content_type = 'application/octet-stream';
    }
    
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: ' . $content_type);
    header('Content-Disposition: attachment; filename="' . $safe_filename . '"');
    header('Content-Length: ' . $file_size);
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    
    $range = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : '';
    
    if ($range && strpos($range, 'bytes=') === 0) {
        $range = substr($range, 6);
        $ranges = explode(',', $range);
        $range_data = explode('-', $ranges[0]);
        
        $start = intval($range_data[0]);
        $end = isset($range_data[1]) && $range_data[1] !== '' ? intval($range_data[1]) : $file_size - 1;
        
        if ($start >= 0 && $start <= $end && $end < $file_size) {
            $content_length = $end - $start + 1;
            
            header('HTTP/1.1 206 Partial Content');
            header('Accept-Ranges: bytes');
            header('Content-Length: ' . $content_length);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $file_size);
            
            $file = fopen($real_file_path, 'rb');
            fseek($file, $start);
            
            $chunk_size = 8192;
            $bytes_left = $content_length;
            
            while ($bytes_left > 0 && !feof($file)) {
                $bytes_to_read = min($chunk_size, $bytes_left);
                echo fread($file, $bytes_to_read);
                $bytes_left -= $bytes_to_read;
                flush();
            }
            
            fclose($file);
        } else {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header('Content-Range: bytes */' . $file_size);
        }
    } else {
        header('Accept-Ranges: bytes');
        
        if ($file_size > 10 * 1024 * 1024) {
            $file = fopen($real_file_path, 'rb');
            $chunk_size = 8192;
            
            while (!feof($file)) {
                echo fread($file, $chunk_size);
                flush();
            }
            
            fclose($file);
        } else {
            readfile($real_file_path);
        }
    }
    
    exit();
    
} catch (PDOException $e) {
    error_log("Database error in download_document.php: " . $e->getMessage());
    header("Location: view_document.php?id=$document_id&error=database_error");
    exit();
} catch (Exception $e) {
    error_log("General error in download_document.php: " . $e->getMessage());
    header("Location: view_document.php?id=$document_id&error=download_failed");
    exit();
}
?>