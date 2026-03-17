<?php
/**
 * Database configuration
 * Reads from environment variables (Railway / Docker) with
 * sane localhost fallbacks for local dev.
 *
 * Railway MySQL env vars are injected automatically when you
 * add a MySQL service and link it to your app.
 */

function _env(string $key, string $default = ''): string {
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

// Support Railway's MYSQL* naming convention first, then DB_* fallback
define('DB_HOST', _env('MYSQLHOST',     _env('DB_HOST',     'localhost')));
define('DB_PORT', _env('MYSQLPORT',     _env('DB_PORT',     '3306')));
define('DB_USER', _env('MYSQLUSER',     _env('DB_USER',     'root')));
define('DB_PASS', _env('MYSQLPASSWORD', _env('DB_PASS',     '')));
define('DB_NAME', _env('MYSQLDATABASE', _env('DB_NAME',     'loogistics')));

// App environment
define('APP_ENV',    _env('APP_ENV',    'production'));
define('APP_URL',    _env('APP_URL',    ''));
define('APP_SECRET', _env('APP_SECRET', 'change-this-in-production'));

/**
 * Get a PDO database connection (singleton).
 */
function getDBConnection(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST, DB_PORT, DB_NAME
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        if (APP_ENV === 'development') {
            die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
        }
        die('Service temporarily unavailable. Please try again later.');
    }
}

// ── Session configuration ──────────────────────────────────
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
