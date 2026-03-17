<?php
/**
 * includes/auth.php
 * Session startup + role-based access guards.
 * Usage:  require_role('admin');   or   require_role('staff');
 */

if (session_status() === PHP_SESSION_NONE) {
    $is_https = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
                 $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
                (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_set_cookie_params([
        'lifetime' => 3600,
        'path'     => '/',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', 3600);
    session_start();
}

/**
 * Ensure the visitor is logged in with the required role.
 * Redirects to login.php if not authenticated.
 *
 * @param string $role  'admin' | 'staff' | '*' (any authenticated user)
 */
function require_role(string $role = '*'): void {
    if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header('Location: ../login.php');
        exit();
    }
    if ($role !== '*' && ($_SESSION['user_role'] ?? '') !== $role) {
        // Wrong role – send to their own dashboard
        $redirect = ($_SESSION['user_role'] === 'admin') ? '../admin/dashboard.php' : '../staff/dashboard.php';
        header("Location: $redirect");
        exit();
    }
}

/** Returns the currently logged-in user's name (safe for HTML output). */
function current_user_name(): string {
    return htmlspecialchars($_SESSION['user_name'] ?? 'User', ENT_QUOTES);
}

/** Returns initials for avatar display. */
function current_user_initials(): string {
    $name = $_SESSION['user_name'] ?? 'U';
    $parts = explode(' ', trim($name));
    $initials = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) {
        $initials .= strtoupper(substr(end($parts), 0, 1));
    }
    return $initials;
}

/** Returns the current user's email (safe for HTML output). */
function current_user_email(): string {
    return htmlspecialchars($_SESSION['user_email'] ?? '', ENT_QUOTES);
}

/** Returns the current user's role (safe for HTML output). */
function current_user_role(): string {
    return htmlspecialchars(ucfirst($_SESSION['user_role'] ?? 'user'), ENT_QUOTES);
}

/** Returns the current user's ID. */
function current_user_id(): int|string {
    return $_SESSION['user_id'] ?? 0;
}
