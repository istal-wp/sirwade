<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_role('admin');
// ═══ ADMIN MONITORING-ONLY GUARD ═══════════════════════════════════════════
// Write operations (INSERT/UPDATE/DELETE) have been moved to staff/inventory.php
// Admin is read-only/monitoring. Redirect any POST attempts.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['flash_type']    = 'warning';
    $_SESSION['flash_message'] = 'Admin is monitoring-only. Use the Staff Portal for data modifications.';
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}
// ═══════════════════════════════════════════════════════════════════════════


require_once __DIR__ . '/../includes/db.php';
$pdo = db(); // Shared singleton from includes/db.php
try {
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        
        if (isset($_POST['action']) && $_POST['action'] === 'add_item') {
            $item_code = $_POST['item_code'];
            $item_name = $_POST['item_name'];
            $category = $_POST['category'];
            $quantity = (int)$_POST['quantity'];
            $unit_price = (float)$_POST['unit_price'];
            $supplier = $_POST['supplier'];
            $location = $_POST['location'];
            $reorder_level = (int)$_POST['reorder_level'];
            $description = $_POST['description'];
            $status = $_POST['status'];
            $created_by = $_SESSION['user_name'];
            
            $stmt = $pdo->prepare("INSERT INTO inventory_items (item_code, item_name, category, quantity, unit_price, supplier, location, reorder_level, description, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$item_code, $item_name, $category, $quantity, $unit_price, $supplier, $location, $reorder_level, $description, $status, $created_by]);
            
            $item_id = $pdo->lastInsertId();
            if ($quantity > 0) {
                $stmt = $pdo->prepare("INSERT INTO stock_movements (item_id, movement_type, quantity, reason, performed_by, unit_cost, total_cost) VALUES (?, 'IN', ?, 'Initial stock', ?, ?, ?)");
                $stmt->execute([$item_id, $quantity, $created_by, $unit_price, $quantity * $unit_price]);
            }
            
            $success_message = "Item added successfully!";
        }
        
        elseif (isset($_POST['action']) && $_POST['action'] === 'update_item') {
            $id = (int)$_POST['item_id'];
            $item_name = $_POST['item_name'];
            $category = $_POST['category'];
            $unit_price = (float)$_POST['unit_price'];
            $supplier = $_POST['supplier'];
            $location = $_POST['location'];
            $reorder_level = (int)$_POST['reorder_level'];
            $description = $_POST['description'];
            $status = $_POST['status'];
            
            $stmt = $pdo->prepare("UPDATE inventory_items SET item_name = ?, category = ?, unit_price = ?, supplier = ?, location = ?, reorder_level = ?, description = ?, status = ? WHERE id = ?");
            $stmt->execute([$item_name, $category, $unit_price, $supplier, $location, $reorder_level, $description, $status, $id]);
            
            $success_message = "Item updated successfully!";
        }
        
        elseif (isset($_POST['action']) && $_POST['action'] === 'stock_movement') {
            $item_id = (int)$_POST['item_id'];
            $movement_type = $_POST['movement_type'];
            $quantity = (int)$_POST['quantity'];
            $reason = $_POST['reason'];
            $reference_number = $_POST['reference_number'];
            $from_location = $_POST['from_location'];
            $to_location = $_POST['to_location'];
            $unit_cost = (float)$_POST['unit_cost'];
            $performed_by = $_SESSION['user_name'];
            
            $stmt = $pdo->prepare("SELECT quantity FROM inventory_items WHERE id = ?");
            $stmt->execute([$item_id]);
            $current_qty = $stmt->fetchColumn();
            
            $new_qty = $current_qty;
            if ($movement_type === 'IN') {
                $new_qty = $current_qty + $quantity;
            } elseif ($movement_type === 'OUT') {
                $new_qty = $current_qty - $quantity;
                if ($new_qty < 0) {
                    throw new Exception("Insufficient stock. Current quantity: $current_qty");
                }
            } elseif ($movement_type === 'ADJUSTMENT') {
                $new_qty = $quantity;
                $quantity = $new_qty - $current_qty;
            }
            
            $stmt = $pdo->prepare("UPDATE inventory_items SET quantity = ? WHERE id = ?");
            $stmt->execute([$new_qty, $item_id]);
            
            $total_cost = abs($quantity) * $unit_cost;
            $stmt = $pdo->prepare("INSERT INTO stock_movements (item_id, movement_type, quantity, reason, reference_number, from_location, to_location, unit_cost, total_cost, performed_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$item_id, $movement_type, $quantity, $reason, $reference_number, $from_location, $to_location, $unit_cost, $total_cost, $performed_by]);
            
            $success_message = "Stock movement recorded successfully!";
        }
        
        elseif (isset($_POST['action']) && $_POST['action'] === 'delete_item') {
            $id = (int)$_POST['item_id'];
            
            $stmt = $pdo->prepare("UPDATE inventory_items SET status = 'inactive' WHERE id = ?");
            $stmt->execute([$id]);
            
            $success_message = "Item deactivated successfully!";
        }
    }
    
    $stats = [];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM inventory_items WHERE status = 'active'");
    $stats['total_items'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT SUM(quantity * unit_price) as value FROM inventory_items WHERE status = 'active'");
    $stats['total_value'] = $stmt->fetchColumn() ?: 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM inventory_items WHERE quantity <= reorder_level AND status = 'active'");
    $stats['low_stock'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM inventory_items WHERE quantity = 0 AND status = 'active'");
    $stats['out_of_stock'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(DISTINCT category) as count FROM inventory_items WHERE status = 'active'");
    $stats['categories'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(DISTINCT location) as count FROM inventory_items WHERE status = 'active'");
    $stats['locations'] = $stmt->fetchColumn();

    $search = isset($_GET['search']) ? '%' . $_GET['search'] . '%' : '%%';
    $category_filter = isset($_GET['category']) && $_GET['category'] !== '' ? $_GET['category'] : null;
    $location_filter = isset($_GET['location']) && $_GET['location'] !== '' ? $_GET['location'] : null;
    $status_filter = isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : 'active';
    
    $where_conditions = ["status = ?"];
    $params = [$status_filter];
    
    if ($search !== '%%') {
        $where_conditions[] = "(item_name LIKE ? OR item_code LIKE ? OR description LIKE ?)";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }
    
    if ($category_filter) {
        $where_conditions[] = "category = ?";
        $params[] = $category_filter;
    }
    
    if ($location_filter) {
        $where_conditions[] = "location = ?";
        $params[] = $location_filter;
    }
    
    $where_clause = implode(" AND ", $where_conditions);
    
    $stmt = $pdo->prepare("SELECT * FROM inventory_items WHERE $where_clause ORDER BY created_at DESC");
    $stmt->execute($params);
    $inventory_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT DISTINCT category FROM inventory_items WHERE status = 'active' ORDER BY category");
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $stmt = $pdo->query("SELECT DISTINCT location FROM inventory_items WHERE status = 'active' ORDER BY location");
    $locations = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $stmt = $pdo->query("SELECT supplier_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name");
    $suppliers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $stmt = $pdo->prepare("
        SELECT sm.*, ii.item_name, ii.item_code 
        FROM stock_movements sm 
        JOIN inventory_items ii ON sm.item_id = ii.id 
        ORDER BY sm.created_at DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $recent_movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
    $stats = [
        'total_items' => 0, 'total_value' => 0, 'low_stock' => 0, 
        'out_of_stock' => 0, 'categories' => 0, 'locations' => 0
    ];
    $inventory_items = [];
    $categories = [];
    $locations = [];
    $suppliers = [];
    $recent_movements = [];
} catch(Exception $e) {
    $error_message = $e->getMessage();
}
?>

<!DOCTYPE html>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management — BRIGHTPATH</title>
<!-- SHARED DESIGN SYSTEM: DM Sans + DM Mono, navy/blue/accent palette -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
        /* ═══ UNIVERSAL TOPBAR (STAFF) ═══════════════════════════════════ */
        .header {
            background: #ffffff !important;
            border-bottom: 1px solid #dde3ef;
            padding: 0 2rem;
            position: sticky; top: 0; z-index: 500;
            box-shadow: 0 1px 8px rgba(15,31,61,.09);
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
        }
        .header-inner {
            display: flex; justify-content: space-between; align-items: center;
            max-width: 1600px; margin: 0 auto; height: 64px;
        }
        .header-left { display: flex; align-items: center; gap: 12px; }
        .brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .brand-mark {
            width: 38px; height: 38px;
            background: linear-gradient(135deg, #0f1f3d, #2c4a8a);
            border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .brand-mark svg { width: 20px; height: 20px; stroke: rgba(255,255,255,.9); fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .brand-text h1 { font-size: 1rem; font-weight: 600; color: #0f1f3d; letter-spacing: .05em; }
        .brand-text p  { font-size: .68rem; color: #6b7a99; letter-spacing: .08em; text-transform: uppercase; font-family: 'DM Mono', monospace; }
        .header-right { display: flex; align-items: center; gap: .85rem; }
        .btn-back {
            display: flex; align-items: center; gap: 7px;
            padding: .48rem 1rem; background: none; border: 1.5px solid #dde3ef; border-radius: 8px;
            font-size: .82rem; font-weight: 500; font-family: 'DM Sans', sans-serif;
            color: #6b7a99; cursor: pointer; text-decoration: none; transition: border-color .2s, color .2s;
        }
        .btn-back svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .btn-back:hover { border-color: #3d7fff; color: #3d7fff; }
        /* Profile pill */
        .profile-wrap { position: relative; }
        .user-pill {
            display: flex; align-items: center; gap: 9px;
            padding: .38rem .8rem .38rem .38rem;
            background: #f4f6fb; border: 1.5px solid #dde3ef; border-radius: 99px;
            cursor: pointer; transition: border-color .2s, box-shadow .2s; user-select: none;
        }
        .user-pill:hover { border-color: #3d7fff; box-shadow: 0 2px 12px rgba(61,127,255,.12); }
        .user-avatar {
            width: 28px; height: 28px; border-radius: 50%;
            background: linear-gradient(135deg, #0f1f3d, #2c4a8a);
            display: flex; align-items: center; justify-content: center;
            font-family: 'DM Mono', monospace; font-size: .7rem; font-weight: 600; color: #fff; flex-shrink: 0;
        }
        .user-name { font-size: .83rem; font-weight: 500; color: #1a2540; }
        .pill-caret { width: 14px; height: 14px; stroke: #6b7a99; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; transition: transform .2s; flex-shrink: 0; }
        .profile-wrap.open .pill-caret { transform: rotate(180deg); }
        /* Profile dropdown */
        .profile-dropdown {
            display: none; position: absolute; top: calc(100% + 10px); right: 0;
            width: 280px; background: #ffffff; border: 1px solid #dde3ef;
            border-radius: 14px; box-shadow: 0 12px 40px rgba(15,31,61,.2);
            z-index: 600; overflow: hidden; animation: dropIn .18s ease;
        }
        .profile-wrap.open .profile-dropdown { display: block; }
        @keyframes dropIn { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
        .pd-head {
            padding: 1.2rem 1.4rem 1rem;
            background: linear-gradient(135deg, #0f1f3d, #2c4a8a);
            display: flex; align-items: center; gap: 12px;
        }
        .pd-avatar {
            width: 44px; height: 44px; border-radius: 50%;
            background: rgba(255,255,255,.18); border: 2px solid rgba(255,255,255,.3);
            display: flex; align-items: center; justify-content: center;
            font-family: 'DM Mono', monospace; font-size: .9rem; font-weight: 700; color: #fff; flex-shrink: 0;
        }
        .pd-info-name  { font-size: .95rem; font-weight: 600; color: #fff; }
        .pd-info-email { font-size: .75rem; color: rgba(255,255,255,.6); margin-top: 1px; word-break: break-all; }
        .pd-body { padding: .75rem 1.4rem; }
        .pd-row {
            display: flex; align-items: center; gap: 10px;
            padding: .55rem 0; border-bottom: 1px solid #f4f6fb; font-size: .82rem;
        }
        .pd-row:last-child { border-bottom: none; }
        .pd-row svg { width: 14px; height: 14px; stroke: #6b7a99; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }
        .pd-row-label { color: #6b7a99; min-width: 60px; }
        .pd-row-val   { color: #1a2540; font-weight: 500; margin-left: auto; text-align: right; }
        .pd-role-badge { display: inline-block; padding: .18rem .55rem; border-radius: 99px; font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; background: rgba(61,127,255,.1); color: #3d7fff; }
        .pd-role-badge.staff { background: rgba(21,128,61,.1); color: #15803d; }
        .pd-foot { padding: .75rem 1.4rem 1rem; border-top: 1px solid #dde3ef; }
        .pd-logout {
            display: flex; align-items: center; justify-content: center; gap: 7px;
            width: 100%; padding: .6rem; border-radius: 8px;
            background: rgba(197,48,48,.07); border: 1.5px solid rgba(197,48,48,.2);
            color: #c53030; font-size: .84rem; font-weight: 600; font-family: 'DM Sans', sans-serif;
            cursor: pointer; text-decoration: none; transition: background .2s, border-color .2s;
        }
        .pd-logout svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .pd-logout:hover { background: rgba(197,48,48,.14); border-color: #c53030; }
        /* ═══ END TOPBAR ════════════════════════════════════════════════ */
        
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
:root {
    --navy: #0f1f3d; --blue: #1a3a6e; --accent: #3d7fff; --steel: #2c4a8a;
    --white: #ffffff; --off: #f4f6fb; --border: #dde3ef;
    --text: #1a2540; --muted: #6b7a99;
    --success: #15803d; --warn: #b45309; --error: #c53030;
    --success-bg: #f0fdf4; --success-border: #bbf7d0;
    --warn-bg: #fffbeb; --warn-border: #fde68a;
    --error-bg: #fff5f5; --error-border: #fed7d7;
    --info-bg: #eff6ff; --info-border: #bfdbfe; --info: #1d4ed8;
}
body { font-family: 'DM Sans', sans-serif; background: var(--off); min-height: 100vh; color: var(--text); }

/* HEADER */
.header { background: var(--white); border-bottom: 1px solid var(--border); padding: 0 2rem; position: sticky; top: 0; z-index: 100; box-shadow: 0 1px 8px rgba(15,31,61,.07); }
.header-inner { display: flex; justify-content: space-between; align-items: center; max-width: 1400px; margin: 0 auto; height: 64px; }
.brand { display: flex; align-items: center; gap: 12px; }
.brand-mark { width: 38px; height: 38px; background: linear-gradient(135deg, var(--navy), var(--steel)); border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.brand-mark svg { width: 20px; height: 20px; stroke: rgba(255,255,255,.9); }
.brand-text h1 { font-size: 1rem; font-weight: 600; color: var(--navy); letter-spacing: 0.05em; }
.brand-text p  { font-size: 0.7rem; color: var(--muted); letter-spacing: 0.08em; text-transform: uppercase; font-family: 'DM Mono', monospace; }
.btn-home { display: flex; align-items: center; gap: 7px; padding: 0.5rem 1rem; background: none; border: 1.5px solid var(--border); border-radius: 8px; font-size: 0.82rem; font-weight: 500; font-family: 'DM Sans', sans-serif; color: var(--muted); cursor: pointer; transition: all .2s; text-decoration: none; }
.btn-home svg { width: 14px; height: 14px; stroke: currentColor; }
.btn-home:hover { border-color: var(--accent); color: var(--accent); }

/* MAIN */
.main { max-width: 1400px; margin: 0 auto; padding: 2rem 2rem 3rem; }
.page-title { margin-bottom: 1.75rem; }
.page-title h1 { font-size: 1.6rem; font-weight: 600; color: var(--navy); margin-bottom: 0.25rem; }
.page-title p  { font-size: 0.88rem; color: var(--muted); }

/* ALERTS */
.alert { display: flex; align-items: flex-start; gap: 10px; padding: 0.85rem 1.1rem; border-radius: 10px; font-size: 0.87rem; line-height: 1.5; margin-bottom: 1rem; }
.alert svg { width: 16px; height: 16px; stroke: currentColor; flex-shrink: 0; margin-top: 1px; }
.alert-success { background: var(--success-bg); border: 1px solid var(--success-border); color: var(--success); }
.alert-error   { background: var(--error-bg);   border: 1px solid var(--error-border);   color: var(--error); }
.alert-warn    { background: var(--warn-bg);     border: 1px solid var(--warn-border);     color: var(--warn); }
.alert-info    { background: var(--info-bg);     border: 1px solid var(--info-border);     color: var(--info); }
.alert a { color: inherit; font-weight: 600; }

/* STATS GRID */
.stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
.stat-card { background: var(--white); border: 1px solid var(--border); border-radius: 12px; padding: 1.25rem 1.4rem; transition: box-shadow .2s, transform .15s; }
.stat-card:hover { box-shadow: 0 4px 18px rgba(15,31,61,.08); transform: translateY(-2px); }
.stat-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem; }
.stat-label { font-size: 0.75rem; font-weight: 500; color: var(--muted); text-transform: uppercase; letter-spacing: 0.06em; }
.stat-badge { width: 36px; height: 36px; background: rgba(61,127,255,.09); border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.stat-badge svg { width: 17px; height: 17px; stroke: var(--accent); }
.stat-badge.warn-badge { background: rgba(180,83,9,.09); } .stat-badge.warn-badge svg { stroke: var(--warn); }
.stat-badge.success-badge { background: rgba(21,128,61,.09); } .stat-badge.success-badge svg { stroke: var(--success); }
.stat-badge.error-badge { background: rgba(197,48,48,.09); } .stat-badge.error-badge svg { stroke: var(--error); }
.stat-value { font-size: 1.9rem; font-weight: 600; color: var(--navy); line-height: 1; margin-bottom: 0.4rem; }
.stat-sub { display: flex; align-items: center; gap: 5px; font-size: 0.76rem; color: var(--muted); }
.stat-sub svg { width: 11px; height: 11px; stroke: currentColor; }
.stat-sub.good { color: var(--success); } .stat-sub.warn { color: var(--warn); } .stat-sub.bad { color: var(--error); }

/* PANELS / TABLES */
.panel { background: var(--white); border: 1px solid var(--border); border-radius: 12px; padding: 1.5rem 1.6rem; margin-bottom: 1.5rem; }
.panel-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border); }
.panel-head h2 { font-size: 1rem; font-weight: 600; color: var(--navy); display: flex; align-items: center; gap: 8px; }
.panel-head h2 svg { width: 16px; height: 16px; stroke: var(--accent); }
.panel-head a, .panel-head span { font-size: 0.82rem; font-weight: 500; color: var(--accent); text-decoration: none; }

.section-label { font-size: 0.75rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.85rem; }

/* TABLE */
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { padding: 0.85rem 1rem; text-align: left; border-bottom: 1px solid var(--off); font-size: 0.85rem; }
.data-table th { background: var(--off); font-weight: 600; color: var(--muted); font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.07em; border-bottom: 1px solid var(--border); }
.data-table td { color: var(--text); }
.data-table tbody tr:hover { background: rgba(61,127,255,.03); }
.data-table tbody tr:last-child td { border-bottom: none; }
.no-data { text-align: center; padding: 2rem; color: var(--muted); font-size: 0.88rem; font-style: italic; }

/* CONTROLS */
.controls-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem; }
.controls-bar h2 { font-size: 1rem; font-weight: 600; color: var(--navy); }
.btn-row { display: flex; gap: 0.65rem; flex-wrap: wrap; }

/* BUTTONS */
.btn { display: inline-flex; align-items: center; gap: 7px; padding: 0.6rem 1.1rem; border: none; border-radius: 8px; font-size: 0.84rem; font-weight: 500; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: opacity .2s, transform .15s, box-shadow .2s; text-decoration: none; white-space: nowrap; }
.btn svg { width: 14px; height: 14px; stroke: currentColor; flex-shrink: 0; }
.btn-primary   { background: linear-gradient(135deg, var(--navy), var(--blue)); color: var(--white); }
.btn-success   { background: linear-gradient(135deg, #16a34a, #15803d); color: var(--white); }
.btn-warning   { background: linear-gradient(135deg, #d97706, #b45309); color: var(--white); }
.btn-danger    { background: linear-gradient(135deg, #dc2626, #b91c1c); color: var(--white); }
.btn-outline   { background: var(--white); border: 1.5px solid var(--border); color: var(--text); }
.btn:hover { opacity: .9; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(15,31,61,.15); }
.btn-sm { padding: 0.4rem 0.7rem; font-size: 0.78rem; }

/* SEARCH */
.search-grid { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem; }
.field label { display: block; font-size: 0.78rem; font-weight: 500; color: var(--text); margin-bottom: 0.35rem; }
.form-control { width: 100%; padding: 0.62rem 0.85rem; border: 1.5px solid var(--border); border-radius: 8px; font-size: 0.86rem; font-family: 'DM Sans', sans-serif; color: var(--text); background: var(--white); transition: border-color .2s, box-shadow .2s; }
.form-control:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(61,127,255,.12); }

/* STATUS BADGES */
.badge { padding: 0.22rem 0.65rem; border-radius: 99px; font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; display: inline-flex; align-items: center; gap: 4px; }
.badge svg { width: 10px; height: 10px; stroke: currentColor; }
.badge-active,   .status-active    { background: #dcfce7; color: #15803d; }
.badge-inactive, .status-inactive  { background: #fee2e2; color: #dc2626; }
.badge-pending,  .status-pending   { background: #fef3c7; color: #92400e; }
.badge-draft,    .status-draft     { background: #fef3c7; color: #92400e; }
.badge-sent,     .status-sent      { background: #dbeafe; color: #1e40af; }
.badge-confirmed,.status-confirmed { background: #d1fae5; color: #065f46; }
.badge-completed,.status-completed { background: #dcfce7; color: #166534; }
.badge-cancelled,.status-cancelled { background: #f3f4f6; color: #374151; }
.badge-received, .status-received  { background: #e0e7ff; color: #3730a3; }
.badge-planning, .status-planning  { background: #fef3c7; color: #92400e; }
.status-on_hold                    { background: #fef2f2; color: #991b1b; }
.status-maintenance                { background: #fef3c7; color: #92400e; }
.status-retired                    { background: #f3f4f6; color: #374151; }
.status-under_review               { background: #fef3c7; color: #92400e; }
.status-accepted                   { background: #d1fae5; color: #065f46; }
.status-rejected                   { background: #fee2e2; color: #991b1b; }
.status-partial                    { background: #fef3c7; color: #92400e; }
.status-low-stock                  { background: #fef3c7; color: #92400e; }
.status-out-stock, .badge-out      { background: #fee2e2; color: #dc2626; }
.status-expired                    { background: #fee2e2; color: #dc2626; }
.priority-low      { background: #f0f9ff; color: #0c4a6e; }
.priority-medium   { background: #fef3c7; color: #92400e; }
.priority-high     { background: #fef2f2; color: #991b1b; }
.priority-critical { background: #fdf2f8; color: #be185d; }

/* MODAL */
.modal { display: none; position: fixed; inset: 0; z-index: 1000; background: rgba(15,31,61,.55); backdrop-filter: blur(4px); }
.modal-box { background: var(--white); margin: 4% auto; border-radius: 14px; width: 90%; max-width: 580px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 50px rgba(15,31,61,.25); }
.modal-head { background: linear-gradient(135deg, var(--navy), var(--steel)); color: var(--white); padding: 1.25rem 1.6rem; border-radius: 14px 14px 0 0; display: flex; justify-content: space-between; align-items: center; }
.modal-head h3 { font-size: 1rem; font-weight: 600; }
.modal-close { background: none; border: none; color: rgba(255,255,255,.8); font-size: 1.4rem; cursor: pointer; line-height: 1; padding: 0 4px; }
.modal-close:hover { color: var(--white); }
.modal-body { padding: 1.6rem; }
.form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem; }
.form-field { margin-bottom: 0.9rem; }
.form-field label { display: block; font-size: 0.8rem; font-weight: 500; color: var(--text); margin-bottom: 0.38rem; }
.form-field .form-control { width: 100%; }
textarea.form-control { min-height: 90px; resize: vertical; }
.modal-footer { display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.5rem; }

/* ACTIVITY / FEED */
.feed-row { display: flex; align-items: center; gap: 12px; padding: 0.85rem 0; border-bottom: 1px solid var(--off); }
.feed-row:last-child { border-bottom: none; }
.feed-icon { width: 36px; height: 36px; border-radius: 9px; background: linear-gradient(135deg, var(--navy), var(--steel)); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.feed-icon svg { width: 16px; height: 16px; stroke: rgba(255,255,255,.9); }
.feed-body h4 { font-size: 0.88rem; font-weight: 500; color: var(--text); margin-bottom: 0.15rem; }
.feed-body p  { font-size: 0.78rem; color: var(--muted); }
.feed-time { margin-left: auto; font-size: 0.75rem; color: var(--muted); font-family: 'DM Mono', monospace; white-space: nowrap; }

/* EMPTY STATE */
.empty-state { text-align: center; padding: 3rem 2rem; }
.empty-state svg { width: 48px; height: 48px; stroke: var(--border); margin-bottom: 1rem; }
.empty-state h3 { font-size: 0.95rem; color: var(--text); margin-bottom: 0.4rem; }
.empty-state p  { font-size: 0.83rem; color: var(--muted); }

/* TABS */
.tabs { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
.tab-btn { display: flex; align-items: center; gap: 7px; padding: 0.6rem 1.1rem; border: 1.5px solid var(--border); border-radius: 8px; background: var(--white); font-size: 0.84rem; font-weight: 500; font-family: 'DM Sans', sans-serif; color: var(--muted); cursor: pointer; transition: all .2s; }
.tab-btn svg { width: 14px; height: 14px; stroke: currentColor; }
.tab-btn.active { background: var(--navy); color: var(--white); border-color: var(--navy); }
.tab-btn:hover:not(.active) { border-color: var(--accent); color: var(--accent); }
.tab-panel { display: none; } .tab-panel.active { display: block; }

/* PROJECT CARD */
.project-card { background: var(--white); border: 1px solid var(--border); border-radius: 12px; padding: 1.4rem; position: relative; overflow: hidden; transition: box-shadow .2s, transform .15s; }
.project-card:hover { box-shadow: 0 6px 22px rgba(15,31,61,.1); transform: translateY(-2px); }
.project-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(135deg, var(--navy), var(--accent)); }
.projects-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 1rem; }
.project-title { font-size: 0.98rem; font-weight: 600; color: var(--navy); margin-bottom: 0.3rem; }
.project-code { font-size: 0.78rem; color: var(--muted); font-family: 'DM Mono', monospace; margin-bottom: 0.65rem; }
.project-desc { font-size: 0.83rem; color: var(--muted); line-height: 1.55; margin-bottom: 1rem; }
.project-stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.5rem; margin-bottom: 1rem; }
.pstat { text-align: center; padding: 0.4rem; background: var(--off); border-radius: 7px; }
.pstat-val { font-size: 1.1rem; font-weight: 600; color: var(--navy); }
.pstat-lbl { font-size: 0.65rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em; }
.progress-bar { height: 6px; background: var(--border); border-radius: 99px; overflow: hidden; margin: 0.75rem 0; }
.progress-fill { height: 100%; background: linear-gradient(90deg, var(--navy), var(--accent)); transition: width .3s; }
.progress-label { text-align: right; font-size: 0.72rem; color: var(--muted); font-family: 'DM Mono', monospace; margin-bottom: 0.75rem; }
.project-meta { font-size: 0.8rem; color: var(--muted); display: grid; grid-template-columns: 1fr 1fr; gap: 0.3rem; margin-bottom: 1rem; }
.project-meta strong { color: var(--text); }
.project-actions-row { display: flex; gap: 0.5rem; flex-wrap: wrap; }

/* SUPPLIER / DOCUMENT SPECIFICS */
.score-stars { color: #f59e0b; font-size: 0.9rem; letter-spacing: 1px; }
.doc-item { display: flex; align-items: flex-start; gap: 12px; padding: 0.9rem 0; border-bottom: 1px solid var(--off); }
.doc-item:last-child { border-bottom: none; }
.doc-icon-box { width: 36px; height: 36px; background: rgba(61,127,255,.09); border: 1px solid rgba(61,127,255,.2); border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.doc-icon-box svg { width: 16px; height: 16px; stroke: var(--accent); }
.doc-body h4 { font-size: 0.88rem; font-weight: 500; color: var(--text); margin-bottom: 0.2rem; }
.doc-body p  { font-size: 0.76rem; color: var(--muted); margin-bottom: 0.3rem; }
.doc-tags { display: flex; gap: 0.4rem; flex-wrap: wrap; }
.compliance-item { padding: 0.85rem 0; border-bottom: 1px solid var(--off); }
.compliance-item:last-child { border-bottom: none; }
.compliance-name { font-size: 0.88rem; font-weight: 500; color: var(--text); margin-bottom: 0.5rem; }
.compliance-stats { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.comp-stat { display: inline-flex; align-items: center; gap: 5px; font-size: 0.76rem; font-weight: 500; padding: 0.2rem 0.6rem; border-radius: 99px; }
.comp-stat svg { width: 11px; height: 11px; stroke: currentColor; }
.comp-stat.compliant    { background: #dcfce7; color: var(--success); }
.comp-stat.non-compliant { background: #fee2e2; color: var(--error); }
.comp-stat.pending-comp { background: #fef3c7; color: var(--warn); }
.workflow-item { display: flex; align-items: flex-start; gap: 12px; padding: 0.9rem 0; border-bottom: 1px solid var(--off); }
.workflow-item:last-child { border-bottom: none; }
.wf-status-dot { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.wf-status-dot.pending  { background: var(--warn-bg); }
.wf-status-dot.pending svg { stroke: var(--warn); }
.wf-status-dot.rejected { background: var(--error-bg); }
.wf-status-dot.rejected svg { stroke: var(--error); }
.wf-status-dot svg { width: 13px; height: 13px; stroke: currentColor; }
.wf-body h4 { font-size: 0.88rem; font-weight: 500; color: var(--text); margin-bottom: 0.15rem; }
.wf-body p  { font-size: 0.76rem; color: var(--muted); }
.wf-time { margin-left: auto; text-align: right; font-size: 0.75rem; color: var(--muted); font-family: 'DM Mono', monospace; white-space: nowrap; }
.content-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem; }

/* PAGINATION */
.pagination { display: flex; align-items: center; gap: 0.4rem; justify-content: center; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border); }
.pagination a, .pagination .current { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 7px; font-size: 0.82rem; font-weight: 500; text-decoration: none; transition: all .2s; border: 1.5px solid var(--border); color: var(--text); background: var(--white); }
.pagination a:hover { border-color: var(--accent); color: var(--accent); }
.pagination .current { background: var(--navy); color: var(--white); border-color: var(--navy); }
.pagination-prev, .pagination-next { width: auto; padding: 0 0.75rem; gap: 5px; }
.pagination-prev svg, .pagination-next svg { width: 13px; height: 13px; stroke: currentColor; }

@media (max-width: 900px) {
    .main { padding: 1.25rem 1rem 2rem; }
    .stats-grid { grid-template-columns: 1fr 1fr; }
    .search-grid { grid-template-columns: 1fr; }
    .form-grid-2 { grid-template-columns: 1fr; }
    .projects-grid { grid-template-columns: 1fr; }
    .content-grid { grid-template-columns: 1fr; }
    .header-inner { flex-wrap: wrap; height: auto; padding: 0.75rem 0; gap: 0.75rem; }
}
</style>
</head>
<body>
<header class="header">
    <div class="header-inner">
        <div class="header-left">
            <a href="dashboard.php" class="brand">
                <div class="brand-mark">
                    <svg viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                </div>
                <div class="brand-text">
                    <h1>BRIGHTPATH</h1>
                    <p>Inventory Management</p>
                </div>
            </a>
            <a href="dashboard.php" class="btn-back">
                <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
                Dashboard
            </a>
        </div>
        <div class="header-right">
            <div class="profile-wrap" id="profileWrap">
                <div class="user-pill">
                    <div class="user-avatar"><?php echo current_user_initials(); ?></div>
                    <span class="user-name"><?php echo current_user_name(); ?></span>
                    <svg class="pill-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
                <div class="profile-dropdown">
                    <div class="pd-head">
                        <div class="pd-avatar"><?php echo current_user_initials(); ?></div>
                        <div>
                            <div class="pd-info-name"><?php echo current_user_name(); ?></div>
                            <div class="pd-info-email"><?php echo current_user_email(); ?></div>
                        </div>
                    </div>
                    <div class="pd-body">
                        <div class="pd-row">
                            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <span class="pd-row-label">Role</span>
                            <span class="pd-row-val"><span class="pd-role-badge"><?php echo current_user_role(); ?></span></span>
                        </div>
                        <div class="pd-row">
                            <svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 7l10 7 10-7"/></svg>
                            <span class="pd-row-label">Email</span>
                            <span class="pd-row-val" style="font-size:.75rem;word-break:break-all"><?php echo current_user_email(); ?></span>
                        </div>
                        <div class="pd-row">
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <span class="pd-row-label">Session</span>
                            <span class="pd-row-val" style="font-size:.74rem;font-family:'DM Mono',monospace"><?php echo date('M j, g:i A'); ?></span>
                        </div>
                    </div>
                    <div class="pd-foot">
                        <a href="logout.php" class="pd-logout">
                            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                            Sign Out
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>
<!-- HEADER -->
<main class="main">
<!-- admin: staff-redirect-banner -->
<?php if (isset($_SESSION['flash_message'])): ?>
<div style="display:flex;align-items:center;gap:10px;padding:.85rem 1.1rem;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;margin-bottom:1.5rem;font-size:.87rem;color:#b45309">
    <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;flex-shrink:0"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <?php echo htmlspecialchars($_SESSION['flash_message']); unset($_SESSION['flash_message'],$_SESSION['flash_type']); ?>
</div>
<?php endif; ?>
<div style="display:flex;align-items:center;gap:12px;padding:1rem 1.25rem;background:#eff6ff;border:1px solid #93c5fd;border-radius:10px;margin-bottom:1.5rem">
    <svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:#2563eb;fill:none;stroke-width:2;flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <span style="font-size:.87rem;color:#1d4ed8;flex:1"><strong>Admin View:</strong> This is a read-only monitoring view. To add or modify Inventory data, go to the Staff Portal.</span>
    <a href="../staff/inventory.php" style="display:inline-flex;align-items:center;gap:6px;padding:.45rem .9rem;background:#2563eb;color:#fff;border-radius:8px;font-size:.82rem;font-weight:600;text-decoration:none">
        <svg viewBox="0 0 24 24" style="width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2"><polyline points="9 18 15 12 9 6"/></svg>Staff Portal
    </a>
</div>


  <div class="page-title"><h1>Inventory Management</h1><p>Complete inventory control and stock management system</p></div>

  <?php if (isset($success_message)): ?>
  <div class="alert alert-success">
    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    <span><strong>Success!</strong> <?php echo $success_message; ?></span>
  </div>
  <?php endif; ?>

  <?php if (isset($error_message)): ?>
  <div class="alert alert-error">
    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <span><strong>Error!</strong> <?php echo $error_message; ?></span>
  </div>
  <?php endif; ?>

  <?php if ($stats['low_stock'] > 0): ?>
  <div class="alert alert-warn">
    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <span><strong>Low Stock Alert:</strong> <?php echo $stats['low_stock']; ?> items are running low and need restocking.</span>
  </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Total Items</span><div class="stat-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['total_items']); ?></div>
      <div class="stat-sub good"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/></svg>Active inventory</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Total Value</span><div class="stat-badge success-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div></div>
      <div class="stat-value" style="font-size:1.4rem">₱<?php echo number_format($stats['total_value'], 2); ?></div>
      <div class="stat-sub good">Inventory worth</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Low Stock</span><div class="stat-badge warn-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['low_stock']); ?></div>
      <div class="stat-sub warn">Need reordering</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Out of Stock</span><div class="stat-badge error-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['out_of_stock']); ?></div>
      <div class="stat-sub bad">Zero quantity</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Categories</span><div class="stat-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['categories']); ?></div>
      <div class="stat-sub">Item groups</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Locations</span><div class="stat-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['locations']); ?></div>
      <div class="stat-sub">Storage sites</div>
    </div>
  </div>

  <!-- Controls -->
  <div class="panel">
    <div class="controls-bar">
      <h2>Inventory Control</h2>
      <div class="btn-row">
        <button onclick="openModal('addItemModal')" class="btn btn-primary">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add New Item
        </button>
        <button onclick="openModal('stockMovementModal')" class="btn btn-success">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
          Stock Movement
        </button>
        <button onclick="window.location.href='inventory/reports.php'" class="btn btn-outline">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
          Reports
        </button>
        <button onclick="exportInventory()" class="btn btn-outline">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Export
        </button>
      </div>
    </div>

    <form method="GET" class="search-grid">
      <div class="field"><label>Search Items</label><input type="text" name="search" class="form-control" placeholder="Name, code, or description…" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"></div>
      <div class="field"><label>Category</label><select name="category" class="form-control"><option value="">All Categories</option><?php foreach ($categories as $c): ?><option value="<?php echo htmlspecialchars($c); ?>"<?php echo (($_GET['category'] ?? '') === $c) ? ' selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Location</label><select name="location" class="form-control"><option value="">All Locations</option><?php foreach ($locations as $l): ?><option value="<?php echo htmlspecialchars($l); ?>"<?php echo (($_GET['location'] ?? '') === $l) ? ' selected' : ''; ?>><?php echo htmlspecialchars($l); ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Status</label><select name="status" class="form-control"><option value="active"<?php echo (($_GET['status'] ?? 'active') === 'active') ? ' selected' : ''; ?>>Active</option><option value="inactive"<?php echo (($_GET['status'] ?? '') === 'inactive') ? ' selected' : ''; ?>>Inactive</option><option value=""<?php echo (($_GET['status'] ?? '') === '') ? ' selected' : ''; ?>>All</option></select></div>
    </form>
    <button type="button" onclick="document.querySelector('.search-grid').submit();" class="btn btn-primary btn-sm">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      Search &amp; Filter
    </button>
  </div>

  <!-- Table -->
  <div class="panel" style="padding:0; overflow:hidden;">
    <table class="data-table">
      <thead><tr><th>Item Code</th><th>Item Name</th><th>Category</th><th>Qty</th><th>Unit Price</th><th>Total Value</th><th>Location</th><th>Status</th><th>Stock Level</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($inventory_items)): ?>
        <tr><td colspan="10">
          <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
            <h3>No inventory items found</h3><p>Start by adding your first inventory item.</p>
          </div>
        </td></tr>
        <?php else: ?>
        <?php foreach ($inventory_items as $item): ?>
        <tr>
          <td><strong style="font-family:'DM Mono',monospace;font-size:.82rem"><?php echo htmlspecialchars($item['item_code']); ?></strong></td>
          <td><?php echo htmlspecialchars($item['item_name']); ?></td>
          <td><?php echo htmlspecialchars($item['category']); ?></td>
          <td><strong><?php echo number_format($item['quantity']); ?></strong></td>
          <td>₱<?php echo number_format($item['unit_price'], 2); ?></td>
          <td><strong>₱<?php echo number_format($item['quantity'] * $item['unit_price'], 2); ?></strong></td>
          <td><?php echo htmlspecialchars($item['location']); ?></td>
          <td><span class="badge status-<?php echo $item['status']; ?>"><?php echo ucfirst($item['status']); ?></span></td>
          <td><?php
            if ($item['quantity'] == 0) echo '<span class="badge badge-out">Out of Stock</span>';
            elseif ($item['quantity'] <= $item['reorder_level']) echo '<span class="badge status-low-stock">Low Stock</span>';
            else echo '<span class="badge badge-active">In Stock</span>';
          ?></td>
          <td>
            <div style="display:flex;gap:.4rem">
              <button onclick="editItem(<?php echo htmlspecialchars(json_encode($item)); ?>)" class="btn btn-warning btn-sm" title="Edit">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              </button>
              <button onclick="stockMovement(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['item_name']); ?>')" class="btn btn-success btn-sm" title="Stock Movement">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
              </button>
              <button onclick="deleteItem(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['item_name']); ?>')" class="btn btn-danger btn-sm" title="Deactivate">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Recent Movements -->
  <div class="panel">
    <div class="panel-head">
      <h2>
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>
        Recent Stock Movements
      </h2>
      <a href="inventory/stock_history.php">View All</a>
    </div>
    <?php if (!empty($recent_movements)): ?>
    <?php foreach ($recent_movements as $movement): ?>
    <div class="feed-row">
      <div class="feed-icon">
        <?php if ($movement['movement_type'] === 'IN'): ?>
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        <?php elseif ($movement['movement_type'] === 'OUT'): ?>
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        <?php elseif ($movement['movement_type'] === 'TRANSFER'): ?>
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>
        <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        <?php endif; ?>
      </div>
      <div class="feed-body">
        <h4><?php echo htmlspecialchars($movement['movement_type']); ?>: <?php echo htmlspecialchars($movement['item_name']); ?> (<?php echo htmlspecialchars($movement['item_code']); ?>)</h4>
        <p>Qty: <?php echo number_format($movement['quantity']); ?> &nbsp;|&nbsp; <?php echo htmlspecialchars($movement['reason'] ?: 'N/A'); ?> &nbsp;|&nbsp; <?php echo htmlspecialchars($movement['performed_by']); ?></p>
      </div>
      <div class="feed-time"><?php echo date('M j, Y g:i A', strtotime($movement['created_at'])); ?></div>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="no-data">No recent stock movements to display.</div>
    <?php endif; ?>
  </div>
</main>

<!-- ADD ITEM MODAL -->
<div id="addItemModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><h3>Add New Inventory Item</h3><button class="modal-close" onclick="closeModal('addItemModal')">&times;</button></div>
    <div class="modal-body">
      <form method="POST" id="addItemForm">
        <input type="hidden" name="action" value="add_item">
        <div class="form-grid-2">
          <div class="form-field"><label>Item Code *</label><input type="text" name="item_code" id="item_code" class="form-control" required></div>
          <div class="form-field"><label>Item Name *</label><input type="text" name="item_name" id="item_name" class="form-control" required></div>
          <div class="form-field"><label>Category *</label><input type="text" name="category" id="category" class="form-control" required list="categoryList"><datalist id="categoryList"><?php foreach ($categories as $c): ?><option value="<?php echo htmlspecialchars($c); ?>"><?php endforeach; ?></datalist></div>
          <div class="form-field"><label>Supplier</label><input type="text" name="supplier" class="form-control" list="supplierList"><datalist id="supplierList"><?php foreach ($suppliers as $s): ?><option value="<?php echo htmlspecialchars($s); ?>"><?php endforeach; ?></datalist></div>
          <div class="form-field"><label>Initial Quantity</label><input type="number" name="quantity" class="form-control" min="0" value="0"></div>
          <div class="form-field"><label>Unit Price</label><input type="number" name="unit_price" class="form-control" step="0.01" min="0" value="0"></div>
          <div class="form-field"><label>Storage Location *</label><input type="text" name="location" class="form-control" required list="locationList"><datalist id="locationList"><?php foreach ($locations as $l): ?><option value="<?php echo htmlspecialchars($l); ?>"><?php endforeach; ?></datalist></div>
          <div class="form-field"><label>Reorder Level</label><input type="number" name="reorder_level" class="form-control" min="0" value="10"></div>
        </div>
        <div class="form-field"><label>Status</label><select name="status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
        <div class="form-field"><label>Description</label><textarea name="description" class="form-control" placeholder="Item description, specifications, notes…"></textarea></div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('addItemModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-primary">Add Item</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- EDIT ITEM MODAL -->
<div id="editItemModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><h3>Edit Inventory Item</h3><button class="modal-close" onclick="closeModal('editItemModal')">&times;</button></div>
    <div class="modal-body">
      <form method="POST" id="editItemForm">
        <input type="hidden" name="action" value="update_item">
        <input type="hidden" id="edit_item_id" name="item_id">
        <div class="form-grid-2">
          <div class="form-field"><label>Item Code</label><input type="text" id="edit_item_code" class="form-control" readonly style="background:var(--off)"></div>
          <div class="form-field"><label>Item Name *</label><input type="text" id="edit_item_name" name="item_name" class="form-control" required></div>
          <div class="form-field"><label>Category *</label><input type="text" id="edit_category" name="category" class="form-control" required list="categoryList"></div>
          <div class="form-field"><label>Supplier</label><input type="text" id="edit_supplier" name="supplier" class="form-control" list="supplierList"></div>
          <div class="form-field"><label>Current Qty</label><input type="number" id="edit_current_quantity" class="form-control" readonly style="background:var(--off)"><small style="color:var(--muted);font-size:.74rem">Use Stock Movement to change</small></div>
          <div class="form-field"><label>Unit Price</label><input type="number" id="edit_unit_price" name="unit_price" class="form-control" step="0.01" min="0"></div>
          <div class="form-field"><label>Location *</label><input type="text" id="edit_location" name="location" class="form-control" required list="locationList"></div>
          <div class="form-field"><label>Reorder Level</label><input type="number" id="edit_reorder_level" name="reorder_level" class="form-control" min="0"></div>
        </div>
        <div class="form-field"><label>Status</label><select id="edit_status" name="status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
        <div class="form-field"><label>Description</label><textarea id="edit_description" name="description" class="form-control"></textarea></div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('editItemModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-primary">Update Item</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- STOCK MOVEMENT MODAL -->
<div id="stockMovementModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><h3>Stock Movement</h3><button class="modal-close" onclick="closeModal('stockMovementModal')">&times;</button></div>
    <div class="modal-body">
      <form method="POST" id="stockMovementForm">
        <input type="hidden" name="action" value="stock_movement">
        <input type="hidden" id="movement_item_id" name="item_id">
        <div id="selected_item_info" style="background:var(--off);padding:.85rem 1rem;border-radius:8px;margin-bottom:1rem;display:none;font-size:.84rem;color:var(--text)">
          <div style="font-weight:600;margin-bottom:.3rem;color:var(--navy)">Selected Item</div>
          <p id="selected_item_details"></p>
        </div>
        <div class="form-field"><label>Select Item *</label><select id="movement_item_select" class="form-control" required onchange="updateSelectedItem()"><option value="">Select an item…</option><?php foreach ($inventory_items as $item): ?><option value="<?php echo $item['id']; ?>" data-name="<?php echo htmlspecialchars($item['item_name']); ?>" data-code="<?php echo htmlspecialchars($item['item_code']); ?>" data-quantity="<?php echo $item['quantity']; ?>" data-location="<?php echo htmlspecialchars($item['location']); ?>"><?php echo htmlspecialchars($item['item_code'] . ' - ' . $item['item_name'] . ' (Qty: ' . $item['quantity'] . ')'); ?></option><?php endforeach; ?></select></div>
        <div class="form-grid-2">
          <div class="form-field"><label>Movement Type *</label><select id="movement_type" name="movement_type" class="form-control" required onchange="updateMovementFields()"><option value="">Select type…</option><option value="IN">Stock In</option><option value="OUT">Stock Out</option><option value="TRANSFER">Transfer</option><option value="ADJUSTMENT">Adjustment</option></select></div>
          <div class="form-field"><label>Quantity *</label><input type="number" id="movement_quantity" name="quantity" class="form-control" min="1" required><small id="quantity_help" style="color:var(--muted);font-size:.74rem"></small></div>
          <div class="form-field"><label>Unit Cost</label><input type="number" id="movement_unit_cost" name="unit_cost" class="form-control" step="0.01" min="0" value="0"></div>
          <div class="form-field"><label>Reference #</label><input type="text" id="movement_reference" name="reference_number" class="form-control" placeholder="PO#, TR#…"></div>
        </div>
        <div class="form-grid-2" id="location_fields" style="display:none">
          <div class="form-field"><label>From Location</label><input type="text" id="movement_from_location" name="from_location" class="form-control"></div>
          <div class="form-field"><label>To Location</label><input type="text" id="movement_to_location" name="to_location" class="form-control"></div>
        </div>
        <div class="form-field"><label>Reason *</label><input type="text" id="movement_reason" name="reason" class="form-control" required placeholder="Purchase, Sale, Damage…"></div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('stockMovementModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-success">Record Movement</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openModal(id) { document.getElementById(id).style.display = 'block'; document.body.style.overflow = 'hidden'; }
function closeModal(id) {
    document.getElementById(id).style.display = 'none'; document.body.style.overflow = 'auto';
    if (id === 'addItemModal') document.getElementById('addItemForm').reset();
    else if (id === 'editItemModal') document.getElementById('editItemForm').reset();
    else if (id === 'stockMovementModal') { document.getElementById('stockMovementForm').reset(); document.getElementById('selected_item_info').style.display = 'none'; document.getElementById('location_fields').style.display = 'none'; document.getElementById('movement_item_id').value = ''; }
}
window.onclick = e => { if (e.target.classList.contains('modal')) { e.target.style.display = 'none'; document.body.style.overflow = 'auto'; } }
function editItem(item) {
    document.getElementById('edit_item_id').value = item.id;
    document.getElementById('edit_item_code').value = item.item_code;
    document.getElementById('edit_item_name').value = item.item_name;
    document.getElementById('edit_category').value = item.category;
    document.getElementById('edit_supplier').value = item.supplier || '';
    document.getElementById('edit_current_quantity').value = item.quantity;
    document.getElementById('edit_unit_price').value = item.unit_price;
    document.getElementById('edit_location').value = item.location;
    document.getElementById('edit_reorder_level').value = item.reorder_level;
    document.getElementById('edit_status').value = item.status;
    document.getElementById('edit_description').value = item.description || '';
    openModal('editItemModal');
}
function stockMovement(itemId, itemName) { const s = document.getElementById('movement_item_select'); s.value = itemId; updateSelectedItem(); openModal('stockMovementModal'); }
function deleteItem(id, name) { if (confirm(`Deactivate "${name}"?`)) { const f = document.createElement('form'); f.method = 'POST'; f.innerHTML = `<input type="hidden" name="action" value="delete_item"><input type="hidden" name="item_id" value="${id}">`; document.body.appendChild(f); f.submit(); } }
function updateSelectedItem() {
    const s = document.getElementById('movement_item_select'); const o = s.options[s.selectedIndex]; const info = document.getElementById('selected_item_info');
    if (o.value) { document.getElementById('movement_item_id').value = o.value; document.getElementById('movement_from_location').value = o.dataset.location; document.getElementById('selected_item_details').innerHTML = `<strong>${o.dataset.code} — ${o.dataset.name}</strong><br>Current Qty: <strong style="color:var(--navy)">${o.dataset.quantity}</strong> &nbsp;|&nbsp; Location: <strong style="color:var(--navy)">${o.dataset.location}</strong>`; info.style.display = 'block'; }
    else { info.style.display = 'none'; document.getElementById('movement_item_id').value = ''; }
}
function updateMovementFields() {
    const type = document.getElementById('movement_type').value; const lf = document.getElementById('location_fields'); const help = document.getElementById('quantity_help'); const qi = document.getElementById('movement_quantity');
    qi.removeAttribute('max'); qi.setAttribute('min','1');
    if (type === 'IN') { help.textContent = 'Enter quantity to add'; lf.style.display = 'none'; }
    else if (type === 'OUT') { const s = document.getElementById('movement_item_select'); const o = s.options[s.selectedIndex]; if (o.value) { qi.setAttribute('max', o.dataset.quantity); help.textContent = `Max: ${o.dataset.quantity}`; } lf.style.display = 'none'; }
    else if (type === 'TRANSFER') { help.textContent = 'Enter qty to transfer'; lf.style.display = 'grid'; document.getElementById('movement_from_location').required = true; document.getElementById('movement_to_location').required = true; }
    else if (type === 'ADJUSTMENT') { qi.setAttribute('min','0'); help.textContent = 'Enter the new correct quantity'; lf.style.display = 'none'; }
    else { help.textContent = ''; lf.style.display = 'none'; }
}
function exportInventory() { const p = new URLSearchParams(window.location.search); window.open(`inventory/export.php?${p.toString()}`, '_blank'); }
document.getElementById('addItemForm').addEventListener('submit', function(e) {
    const code = document.getElementById('item_code').value.trim();
    if (!/^[A-Z0-9]{3,20}$/.test(code)) { e.preventDefault(); alert('Item Code must be 3–20 uppercase letters/numbers.'); }
});
document.getElementById('stockMovementForm').addEventListener('submit', function(e) {
    const id = document.getElementById('movement_item_id').value, type = document.getElementById('movement_type').value, qty = parseInt(document.getElementById('movement_quantity').value), reason = document.getElementById('movement_reason').value.trim();
    if (!id || !type || !qty || !reason) { e.preventDefault(); alert('Please fill in all required fields.'); return; }
    if (type === 'OUT') { const o = document.getElementById('movement_item_select').options[document.getElementById('movement_item_select').selectedIndex]; if (qty > parseInt(o.dataset.quantity)) { e.preventDefault(); alert(`Cannot remove ${qty} items. Current stock: ${o.dataset.quantity}.`); return; } }
    const s = document.getElementById('movement_item_select'); const name = s.options[s.selectedIndex].dataset.name; if (!confirm(`Confirm movement of ${qty} units for "${name}"?`)) e.preventDefault();
});
setTimeout(() => location.reload(), 300000);
</script>
<script>
(function(){
    var wrap = document.getElementById('profileWrap');
    if(!wrap) return;
    wrap.querySelector('.user-pill').addEventListener('click', function(e){
        e.stopPropagation();
        wrap.classList.toggle('open');
    });
    document.addEventListener('click', function(e){
        if(wrap && !wrap.contains(e.target)) wrap.classList.remove('open');
    });
    document.addEventListener('keydown', function(e){
        if(e.key==='Escape' && wrap) wrap.classList.remove('open');
    });
})();
</script>
</body>
</html>