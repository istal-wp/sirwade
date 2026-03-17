<?php
/**
 * includes/db.php
 * Centralised PDO connection helper (singleton).
 * Replaces the repeated inline getenv() blocks in every file.
 */

if (!defined('DB_HOST')) {
    // Support Railway MYSQL* env vars first, then generic DB_* fallback
    function _env_db(string $key, string $default = ''): string {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }
    define('DB_HOST', _env_db('MYSQLHOST',     _env_db('DB_HOST',     'localhost')));
    define('DB_PORT', _env_db('MYSQLPORT',     _env_db('DB_PORT',     '3306')));
    define('DB_USER', _env_db('MYSQLUSER',     _env_db('DB_USER',     'root')));
    define('DB_PASS', _env_db('MYSQLPASSWORD', _env_db('DB_PASS',     '')));
    define('DB_NAME', _env_db('MYSQLDATABASE', _env_db('DB_NAME',     'loogistics')));
    define('APP_ENV',  _env_db('APP_ENV',  'production'));
}

/**
 * Returns a shared PDO instance (singleton pattern).
 */
function db(): PDO {
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
    } catch (PDOException $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        if ((APP_ENV ?? 'production') === 'development') {
            die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
        }
        http_response_code(503);
        die('Service temporarily unavailable.');
    }
    return $pdo;
}

/**
 * Convenience: fetch a single scalar value.
 */
function db_scalar(string $sql, array $params = []): mixed {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

/**
 * Convenience: fetch all rows.
 */
function db_all(string $sql, array $params = []): array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Convenience: fetch one row.
 */
function db_one(string $sql, array $params = []): ?array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}
