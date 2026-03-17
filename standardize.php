#!/usr/bin/env php
<?php
/**
 * standardize.php
 * ───────────────────────────────────────────────────────────────────────────
 * BRIGHTPATH — Full Standardization Automation Script
 *
 * Run from project root:
 *   php standardize.php [--dry-run]
 *
 * What it does:
 *   1. Scans admin/ and staff/ PHP files
 *   2. Replaces inline DB connection blocks with require_once + db()
 *   3. Replaces raw session_start() + manual role guards with require_role()
 *   4. Injects require_once for helpers.php and activity_log.php
 *   5. Reports files changed / skipped
 *   6. Adds monitoring-only guard comment to admin CRUD blocks
 * ───────────────────────────────────────────────────────────────────────────
 */

$dry_run = in_array('--dry-run', $argv ?? [], true);
$root    = __DIR__;
$changed = 0; $skipped = 0; $errors = 0;

echo "\n╔══════════════════════════════════════════════════╗\n";
echo "║  BRIGHTPATH Standardization Script               ║\n";
echo "╚══════════════════════════════════════════════════╝\n";
echo $dry_run ? "  MODE: DRY RUN (no files written)\n\n" : "  MODE: LIVE (files will be updated)\n\n";

// ── Files to process ──────────────────────────────────────────────
$scan_dirs = ['admin', 'staff'];
$php_files = [];
foreach ($scan_dirs as $dir) {
    $full = $root . '/' . $dir;
    if (!is_dir($full)) continue;
    foreach (glob($full . '/*.php') as $f) {
        $php_files[] = $f;
    }
}

echo "  Found " . count($php_files) . " PHP files to process.\n\n";

// ── Transformation patterns ────────────────────────────────────────

/**
 * Pattern 1: Replace inline getenv() DB connection block with require_once.
 * Targets the 5-line block repeated in every file.
 */
function replace_db_block(string $src, string $role_context): string {
    $depth     = ($role_context === 'admin') ? '../../' : '../../';
    $depth_inc = ($role_context === 'admin') ? '../includes/' : '../includes/';

    // The repeated inline block
    $pattern = '/\$servername\s*=\s*getenv[^;]+;\s*'
             . '\$db_port\s*=\s*getenv[^;]+;\s*'
             . '\$db_username\s*=\s*getenv[^;]+;\s*'
             . '\$db_password\s*=\s*getenv[^;]+;\s*'
             . '\$dbname\s*=\s*getenv[^;]+;\s*'
             . '(try\s*\{)?\s*'
             . '\$pdo\s*=\s*new PDO\([^;]+\);\s*'
             . '\$pdo->setAttribute[^;]+;\s*/s';

    if (!preg_match($pattern, $src)) return $src;

    $replacement = "// ── DB + Helpers loaded via shared includes ──\n"
                 . "require_once __DIR__ . '/{$depth_inc}db.php';\n"
                 . "require_once __DIR__ . '/{$depth_inc}helpers.php';\n"
                 . "require_once __DIR__ . '/{$depth_inc}activity_log.php';\n"
                 . "\$pdo = db(); // Singleton PDO via includes/db.php\n"
                 . "try {\n";

    return preg_replace($pattern, $replacement, $src, 1);
}

/**
 * Pattern 2: Replace raw session_start() + manual auth guard with require_role().
 */
function replace_auth_guard(string $src, string $role): string {
    // Already standardised
    if (str_contains($src, 'require_role(')) return $src;

    $depth_inc = '../includes/';
    $replacement = "require_once __DIR__ . '/{$depth_inc}auth.php';\n"
                 . "require_role('{$role}');\n";

    // Remove the old session_start() + if(!isset($_SESSION…) block
    $pattern = '/session_start\(\);\s*'
             . 'if\s*\(\s*!isset\(\$_SESSION\[.logged_in.\]\)[^}]+\}\s*/s';
    $src = preg_replace($pattern, $replacement, $src, 1);

    // Remove standalone session_start() if it still exists
    $src = preg_replace('/^session_start\(\);\s*/m', '', $src);

    return $src;
}

/**
 * Pattern 3: Ensure a file opens with <?php and doc comment.
 * Adds standardised file-header if missing.
 */
function ensure_php_open(string $src, string $filename): string {
    if (!str_starts_with(ltrim($src), '<?php')) {
        return "<?php\n/** {$filename} — standardized by standardize.php */\n" . $src;
    }
    return $src;
}

/**
 * Pattern 4: Replace htmlspecialchars($x) short form with h($x)
 * for consistency (only for simple scalar variables).
 */
function replace_h_calls(string $src): string {
    // Only replace when helpers.php is already included
    if (!str_contains($src, "helpers.php")) return $src;
    return preg_replace(
        '/htmlspecialchars\(\s*(\$[a-zA-Z_][a-zA-Z0-9_\'\"\[\]]*)\s*\)/m',
        'h($1)',
        $src
    );
}

/**
 * Pattern 5: Flag admin CRUD blocks for review.
 * Adds a comment above POST handlers in admin files.
 */
function flag_admin_crud(string $src, string $filepath): string {
    if (!str_contains($filepath, '/admin/')) return $src;

    $crud_warning = "\n    /* ⚠ ADMIN-MONITORING-ONLY: Write operations below should be moved to staff/.\n"
                  . "       This block is preserved for backward-compat but access-controlled. */\n";

    // Mark write ops that still exist in admin
    $patterns = [
        "/(\bINSERT\s+INTO\b)/i" => "/* [MOVE→staff] */ INSERT INTO",
        "/(\bUPDATE\s+\w+\s+SET\b)/i" => "/* [MOVE→staff] */ UPDATE",
    ];

    // Add deprecation comment before the first POST block in admin
    if (preg_match('/(\$_SERVER\[.REQUEST_METHOD.\]\s*===\s*.POST.)/m', $src)
        && !str_contains($src, 'ADMIN-MONITORING-ONLY')) {
        $src = preg_replace(
            '/(\$_SERVER\[.REQUEST_METHOD.\]\s*===\s*.POST.)/m',
            $crud_warning . '$1',
            $src,
            1
        );
    }

    return $src;
}

/**
 * Pattern 6: Replace repeated number_format($stats['key']) with already-computed var
 * — just ensures consistency by wrapping bare stat values.
 */
function standardize_stat_output(string $src): string {
    // Replace echo $stats['x'] (bare) with echo number_format($stats['x'])
    return preg_replace(
        '/echo\s+\$stats\[([\'"][a-z_]+[\'"])\](?!\s*[,\)])/m',
        'echo number_format((int)$stats[$1])',
        $src
    );
}

// ── Process each file ─────────────────────────────────────────────
foreach ($php_files as $filepath) {
    $relpath = str_replace($root . '/', '', $filepath);
    $role    = str_contains($filepath, '/admin/') ? 'admin' : 'staff';
    $basename = basename($filepath);

    // Skip files that don't need modification
    $skip_files = ['logout.php', 'download_resume.php'];
    if (in_array($basename, $skip_files, true)) {
        echo "  ⊙ SKIP   $relpath (excluded)\n";
        $skipped++;
        continue;
    }

    $original = file_get_contents($filepath);
    if ($original === false) {
        echo "  ✗ ERROR  $relpath (cannot read)\n";
        $errors++;
        continue;
    }

    $src = $original;

    // Apply transforms
    $src = replace_auth_guard($src, $role);
    $src = replace_db_block($src, $role);
    $src = replace_h_calls($src);
    $src = flag_admin_crud($src, $filepath);
    $src = standardize_stat_output($src);

    if ($src === $original) {
        echo "  · CLEAN  $relpath\n";
        $skipped++;
        continue;
    }

    if (!$dry_run) {
        if (file_put_contents($filepath, $src) === false) {
            echo "  ✗ ERROR  $relpath (write failed)\n";
            $errors++;
            continue;
        }
    }

    echo "  ✔ UPDATE $relpath\n";
    $changed++;
}

// ── Admin CRUD audit report ────────────────────────────────────────
echo "\n── Admin CRUD Audit ────────────────────────────────────────────\n";
$admin_dir = $root . '/admin';
if (is_dir($admin_dir)) {
    foreach (glob($admin_dir . '/*.php') as $f) {
        $content = file_get_contents($f);
        $writes  = preg_match_all('/\b(INSERT INTO|UPDATE \w+ SET|DELETE FROM)\b/i', $content);
        if ($writes > 0) {
            $fn = basename($f);
            echo "  ⚠  admin/$fn has $writes write operation(s) — consider moving to staff/\n";
        }
    }
}

// ── Check staff/ completeness ──────────────────────────────────────
echo "\n── Staff Module Coverage ───────────────────────────────────────\n";
$expected_staff = [
    'dashboard.php'  => 'Staff Dashboard',
    'inventory.php'  => 'Inventory CRUD',
    'suppliers.php'  => 'Supplier CRUD',
    'projects.php'   => 'Project CRUD',
    'assets.php'     => 'Asset CRUD',
    'procurement.php'=> 'Procurement CRUD',
    'documents.php'  => 'Document CRUD',
    'reports.php'    => 'Staff Reports',
    'logout.php'     => 'Logout',
];
foreach ($expected_staff as $file => $label) {
    $exists = file_exists($root . '/staff/' . $file);
    echo ($exists ? "  ✔ " : "  ✗ ") . "staff/{$file} — {$label}\n";
}

// ── Include file coverage ─────────────────────────────────────────
echo "\n── Shared Include Coverage ─────────────────────────────────────\n";
$expected_includes = [
    'includes/db.php'           => 'DB singleton',
    'includes/auth.php'         => 'Auth/session guard',
    'includes/helpers.php'      => 'Utility helpers',
    'includes/activity_log.php' => 'Audit log writer',
    'includes/admin/header.php' => 'Admin header',
    'includes/admin/footer.php' => 'Admin footer',
    'includes/staff/header.php' => 'Staff header',
    'includes/staff/footer.php' => 'Staff footer',
];
foreach ($expected_includes as $file => $label) {
    $exists = file_exists($root . '/' . $file);
    echo ($exists ? "  ✔ " : "  ✗ ") . "{$file} — {$label}\n";
}

// ── Summary ───────────────────────────────────────────────────────
echo "\n╔══════════════════════════════════════════════════╗\n";
echo "║  SUMMARY                                         ║\n";
echo "╠══════════════════════════════════════════════════╣\n";
printf("║  %-20s %25d  ║\n", 'Files updated:', $changed);
printf("║  %-20s %25d  ║\n", 'Files skipped:', $skipped);
printf("║  %-20s %25d  ║\n", 'Errors:', $errors);
echo "╚══════════════════════════════════════════════════╝\n\n";

if ($dry_run) {
    echo "  ⓘ  Dry-run complete. Re-run without --dry-run to apply changes.\n\n";
}

exit($errors > 0 ? 1 : 0);
