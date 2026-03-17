<?php
session_start();

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit();
}

$host         = getenv('MYSQLHOST')     ?: getenv('DB_HOST')     ?: 'localhost';
$db_port      = getenv('MYSQLPORT')     ?: getenv('DB_PORT')     ?: '3306';
$dbname       = getenv('MYSQLDATABASE') ?: getenv('DB_NAME')     ?: 'loogistics';
$username     = getenv('MYSQLUSER')     ?: getenv('DB_USER')     ?: 'root';
$password     = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS')     ?: '';

try {
    $pdo = new PDO("mysql:host=$host;port=$db_port;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit();
}

if (isset($_GET["id"]) && is_numeric($_GET["id"])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM supplier_evaluations WHERE id = ?");
        $stmt->execute([$_GET["id"]]);
        $evaluation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($evaluation) {
            echo json_encode(["success" => true, "evaluation" => $evaluation]);
        } else {
            echo json_encode(["success" => false, "message" => "Evaluation not found"]);
        }
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Database error"]);
    }
} else {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid evaluation ID"]);
}
?>