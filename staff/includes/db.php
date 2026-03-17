<?php
/**
 * BRIGHTPATH — Shared Database Connection
 * Supports both local (localhost/root) and Railway (env vars) environments.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db_host = getenv('MYSQLHOST')     ?: getenv('DB_HOST')     ?: 'localhost';
$db_port = getenv('MYSQLPORT')     ?: getenv('DB_PORT')     ?: '3306';
$db_user = getenv('MYSQLUSER')     ?: getenv('DB_USER')     ?: 'root';
$db_pass = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS')     ?: '';
$db_name = getenv('MYSQLDATABASE') ?: getenv('DB_NAME')     ?: 'loogistics';

try {
    $pdo = new PDO(
        "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'message' => 'Database connection failed.']));
}
