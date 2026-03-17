<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_role('admin');


if (!isset($_GET['file'])) {
    exit("No file specified");
}

$servername   = getenv('MYSQLHOST')     ?: getenv('DB_HOST')     ?: 'localhost';
$db_port      = getenv('MYSQLPORT')     ?: getenv('DB_PORT')     ?: '3306';
$db_username  = getenv('MYSQLUSER')     ?: getenv('DB_USER')     ?: 'root';
$db_password  = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS')     ?: '';
$dbname       = getenv('MYSQLDATABASE') ?: getenv('DB_NAME')     ?: 'loogistics';

$file = $_GET['file'];
$filepath = '../' . $file;

if (strpos($file, 'uploads/resumes/') !== 0) {
    exit("Invalid file path");
}

if (!file_exists($filepath)) {
    exit("File not found: " . htmlspecialchars($file));
}

try {
        $pdo = new PDO("mysql:host=$servername;port=$db_port;dbname=$dbname;charset=utf8mb4", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->prepare("SELECT resume_filename FROM users WHERE resume_path = ? LIMIT 1");
    $stmt->execute([$file]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && !empty($user['resume_filename'])) {
        $original_filename = $user['resume_filename'];
    } else {
        $stmt2 = $pdo->prepare("SELECT file_name FROM application_documents WHERE file_path = ? LIMIT 1");
        $stmt2->execute([$file]);
        $doc = $stmt2->fetch(PDO::FETCH_ASSOC);
        
        if ($doc && !empty($doc['file_name'])) {
            $original_filename = $doc['file_name'];
        } else {
            $original_filename = basename($file);
        }
    }
    
} catch(PDOException $e) {
    $original_filename = basename($file);
    error_log("Download resume error: " . $e->getMessage());
}

$filesize = filesize($filepath);

$original_filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $original_filename);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $original_filename . '"');
header('Content-Length: ' . $filesize);
header('Cache-Control: must-revalidate');
header('Pragma: public');

readfile($filepath);
exit();
?>