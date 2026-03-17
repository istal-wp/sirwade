<?php
/**
 * includes/activity_log.php
 * Write a row to audit_logs.
 * Called after any significant create / update / delete action.
 */

if (!function_exists('log_activity')) {

    /**
     * @param string $action     Human-readable action label, e.g. 'create_project'
     * @param string $table_name Target table, e.g. 'projects'
     * @param int    $record_id  Affected record PK (0 if not applicable)
     * @param string $details    Optional JSON or text details
     */
    function log_activity(
        string $action,
        string $table_name  = '',
        int    $record_id   = 0,
        string $details     = ''
    ): void {
        try {
            // Make sure db() is available
            if (!function_exists('db')) {
                $root = dirname(__DIR__);
                require_once $root . '/includes/db.php';
            }
            $pdo = db();

            // Detect which columns actually exist in audit_logs
            static $cols = null;
            if ($cols === null) {
                $cols = [];
                $stmt = $pdo->query("SHOW COLUMNS FROM audit_logs");
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
                    $cols[] = $col['Field'];
                }
            }

            $performed_by = $_SESSION['user_name'] ?? ($_SESSION['user_email'] ?? 'system');
            $ip_address   = $_SERVER['REMOTE_ADDR'] ?? '';

            // Build insert dynamically based on existing columns
            $data = [
                'action'       => $action,
                'table_name'   => $table_name,
                'record_id'    => $record_id,
                'performed_by' => $performed_by,
                'ip_address'   => $ip_address,
                'details'      => $details,
            ];

            $insertCols = [];
            $insertVals = [];
            foreach ($data as $col => $val) {
                if (in_array($col, $cols, true)) {
                    $insertCols[] = "`$col`";
                    $insertVals[] = $val;
                }
            }

            if (empty($insertCols)) return;

            $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
            $colList      = implode(',', $insertCols);
            $pdo->prepare("INSERT INTO audit_logs ($colList) VALUES ($placeholders)")
                ->execute($insertVals);

        } catch (Throwable $e) {
            // Never crash the main flow over a log failure
            error_log('log_activity failed: ' . $e->getMessage());
        }
    }
}
