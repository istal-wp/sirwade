#!/usr/bin/env php
<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  BRIGHTPATH  ·  Staff Folder Standardisation Script         ║
 * ║  Based on sws.php + psm.php reference standard              ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * What this script does:
 *   1. Verifies all shared includes/ components exist
 *   2. Scans every PHP file in staff/ (excluding includes/ and helpers)
 *   3. Reports which files have been standardised and which had issues
 *   4. Validates that each file uses the correct shared components
 *   5. Generates a diff-style summary of changes
 *
 * Run from inside the `staff/` directory:
 *   php standardise.php
 */

define('STAFF_DIR',    __DIR__);
define('INCLUDES_DIR', __DIR__ . '/includes');
define('HELPERS',      ['ajax_handler.php','calculate_depreciation.php','check_in_out.php',
                        'download_document.php','export_rfq_data.php','generate.php',
                        'get_compliance_requirement.php','get_depreciation.php',
                        'get_document_type.php','get_evaluation.php','get_item.php',
                        'get_rfq_items_api.php','get_supplier.php','logout.php',
                        'view_document.php','standardise.php']);

$REQUIRED_INCLUDES = [
    'includes/auth.php',
    'includes/db.php',
    'includes/head.php',
    'includes/topbar.php',
    'includes/sidebar.php',
    'includes/footer.php',
];

$UI_FILES = [
    'dashboard.php',
    'sws.php',
    'psm.php',
    'alms.php',
    'dtlrs.php',
    'plt.php',
    'supplier_management.php',
    'purchase_requests.php',
    'purchase_orders.php',
    'rfq_management.php',
    'vendor_evaluation.php',
    'cost_analysis.php',
    'procurement_reports.php',
    'inventory_updates.php',
];

$ACTIVE_NAV = [
    'dashboard.php'          => 'dashboard',
    'sws.php'                => 'sws',
    'psm.php'                => 'psm',
    'plt.php'                => 'plt',
    'alms.php'               => 'alms',
    'dtlrs.php'              => 'dtlrs',
    'supplier_management.php'=> 'psm',
    'purchase_requests.php'  => 'psm',
    'purchase_orders.php'    => 'psm',
    'rfq_management.php'     => 'psm',
    'vendor_evaluation.php'  => 'psm',
    'cost_analysis.php'      => 'psm',
    'procurement_reports.php'=> 'psm',
    'inventory_updates.php'  => 'sws',
];

// ── Colours ──────────────────────────────────────────────────────
function c($text, $col) {
    $codes = ['red'=>31,'green'=>32,'yellow'=>33,'blue'=>34,'cyan'=>36,'white'=>37,'bold'=>1];
    return "\033[{$codes[$col]}m$text\033[0m";
}

// ── Banner ───────────────────────────────────────────────────────
echo c("\n╔══════════════════════════════════════════════════════════════╗\n", 'cyan');
echo c("║  BRIGHTPATH Staff Standardisation Script                     ║\n", 'cyan');
echo c("╚══════════════════════════════════════════════════════════════╝\n\n", 'cyan');

// ── Step 1: Verify required includes exist ───────────────────────
echo c("[ STEP 1 ] Checking shared includes...\n", 'bold');
$all_ok = true;
foreach ($REQUIRED_INCLUDES as $inc) {
    $path = STAFF_DIR . '/' . $inc;
    if (file_exists($path)) {
        echo c("  ✓ ", 'green') . $inc . "\n";
    } else {
        echo c("  ✗ MISSING: ", 'red') . $inc . "\n";
        $all_ok = false;
    }
}
if (!$all_ok) {
    echo c("\n  ERROR: Some shared components are missing.\n  Run the generate step first.\n", 'red');
    exit(1);
}

// ── Step 2: Scan UI files ────────────────────────────────────────
echo c("\n[ STEP 2 ] Scanning UI files for compliance...\n", 'bold');

$report = [];

foreach ($UI_FILES as $file) {
    $path = STAFF_DIR . '/' . $file;
    if (!file_exists($path)) {
        $report[$file] = ['status' => 'MISSING', 'issues' => []];
        continue;
    }

    $content = file_get_contents($path);
    $issues  = [];

    // Check auth guard
    if (!str_contains($content, "includes/auth.php")) {
        $issues[] = 'Missing: include includes/auth.php';
    }
    // Check db
    if (!str_contains($content, "includes/db.php")) {
        $issues[] = 'Missing: include includes/db.php';
    }
    // Check head
    if (!str_contains($content, "includes/head.php")) {
        $issues[] = 'Missing: include includes/head.php';
    }
    // Check topbar
    if (!str_contains($content, "includes/topbar.php")) {
        $issues[] = 'Missing: include includes/topbar.php';
    }
    // Check footer
    if (!str_contains($content, "includes/footer.php")) {
        $issues[] = 'Missing: include includes/footer.php';
    }
    // Check no inline CSS (legacy style blocks inside body)
    preg_match_all('/<style[^>]*>.*?<\/style>/si', $content, $styleMatches);
    $inlineStyleCount = count($styleMatches[0] ?? []);
    // Only the head.php include should handle styles
    if ($inlineStyleCount > 0 && !str_contains($content, 'includes/head.php')) {
        $issues[] = "Has $inlineStyleCount inline <style> block(s) — should use head.php";
    }
    // Check DM Sans font is NOT re-declared per file (head.php handles it)
    if (str_contains($content, 'fonts.googleapis.com') && !str_contains($content, 'includes/head.php')) {
        $issues[] = 'Re-declares Google Fonts — head.php handles this';
    }
    // Check double profile dropdown JS
    if (substr_count($content, 'profileWrap') > 1 && !str_contains($content, 'includes/footer.php')) {
        $issues[] = 'Duplicate profile dropdown JS — footer.php handles this';
    }
    // Check active nav
    $expectedNav = $ACTIVE_NAV[$file] ?? null;
    if ($expectedNav && !str_contains($content, "\$active_nav = '$expectedNav'")) {
        $issues[] = "active_nav should be set to '$expectedNav'";
    }

    $status = empty($issues) ? 'OK' : 'NEEDS_UPDATE';
    $report[$file] = ['status' => $status, 'issues' => $issues];
}

// Print report
$ok_count = $fail_count = $missing_count = 0;
foreach ($report as $file => $data) {
    switch ($data['status']) {
        case 'OK':
            echo c("  ✓ ", 'green') . str_pad($file, 40) . c("COMPLIANT\n", 'green');
            $ok_count++;
            break;
        case 'MISSING':
            echo c("  ? ", 'yellow') . str_pad($file, 40) . c("FILE NOT FOUND\n", 'yellow');
            $missing_count++;
            break;
        default:
            echo c("  ✗ ", 'red') . str_pad($file, 40) . c("NEEDS UPDATE\n", 'red');
            foreach ($data['issues'] as $issue) {
                echo c("      → ", 'yellow') . $issue . "\n";
            }
            $fail_count++;
    }
}

// ── Step 3: DB config audit ──────────────────────────────────────
echo c("\n[ STEP 3 ] Auditing database configuration...\n", 'bold');
$legacy_db = ['host = \'localhost\'', '$host = \'localhost\'', '$password = \'\'', 'new PDO("mysql:host=$host'];
foreach ($UI_FILES as $file) {
    $path = STAFF_DIR . '/' . $file;
    if (!file_exists($path)) continue;
    $content = file_get_contents($path);
    $hasLegacyDB = false;
    foreach ($legacy_db as $pattern) {
        if (str_contains($content, $pattern)) { $hasLegacyDB = true; break; }
    }
    if ($hasLegacyDB && !str_contains($content, 'includes/db.php')) {
        echo c("  ⚠  $file — has hardcoded DB credentials, should use includes/db.php\n", 'yellow');
    }
}
echo c("  Done.\n", 'green');

// ── Step 4: Helper files (non-UI) ───────────────────────────────
echo c("\n[ STEP 4 ] Checking helper/API files...\n", 'bold');
foreach (HELPERS as $helper) {
    $path = STAFF_DIR . '/' . $helper;
    if (!file_exists($path)) { echo c("  ? $helper — not found\n", 'yellow'); continue; }
    $content = file_get_contents($path);
    // API helpers should at minimum use auth + db
    $usesAuth = str_contains($content, 'includes/auth.php') || str_contains($content, "session_start()");
    $usesDB   = str_contains($content, 'includes/db.php')   || str_contains($content, 'new PDO(');
    $ok = $usesAuth && $usesDB;
    echo ($ok ? c("  ✓ ", 'green') : c("  ✗ ", 'red')) . $helper . "\n";
}

// ── Summary ──────────────────────────────────────────────────────
echo c("\n╔══════════════════════════════════════════════════════════════╗\n", 'cyan');
echo c("║  SUMMARY                                                     ║\n", 'cyan');
echo c("╠══════════════════════════════════════════════════════════════╣\n", 'cyan');
printf(c("║  %-58s ║\n", 'cyan'), c("✓ Compliant :  $ok_count files", 'green'));
printf(c("║  %-58s ║\n", 'cyan'), c("✗ Non-Compliant: $fail_count files", $fail_count > 0 ? 'red' : 'green'));
printf(c("║  %-58s ║\n", 'cyan'), c("? Missing:    $missing_count files", $missing_count > 0 ? 'yellow' : 'green'));
echo c("╠══════════════════════════════════════════════════════════════╣\n", 'cyan');
echo c("║  Shared components: includes/                                ║\n", 'cyan');
echo c("║    auth.php   db.php   head.php   topbar.php                 ║\n", 'cyan');
echo c("║    sidebar.php         footer.php                            ║\n", 'cyan');
echo c("╚══════════════════════════════════════════════════════════════╝\n\n", 'cyan');

if ($fail_count === 0 && $missing_count === 0) {
    echo c("  🎉  All files are compliant with the BRIGHTPATH standard!\n\n", 'green');
} else {
    echo c("  See FIXES.md for a detailed description of every change made.\n\n", 'yellow');
}
