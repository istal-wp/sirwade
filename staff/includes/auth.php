<?php
/**
 * BRIGHTPATH — Auth Guard
 * Include at the top of every staff page.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    !isset($_SESSION['logged_in'])   ||
    $_SESSION['logged_in'] !== true  ||
    $_SESSION['user_role'] !== 'staff'
) {
    header('Location: ../login.php');
    exit();
}

$user_name  = $_SESSION['user_name']  ?? 'Staff';
$user_email = $_SESSION['user_email'] ?? '';
$user_role  = $_SESSION['user_role']  ?? 'staff';
