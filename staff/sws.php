<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_role'] !== 'staff') {
    header("Location: ../../index.php");
    exit();
}

$user_name = $_SESSION['user_name'];
$user_email = $_SESSION['user_email'];

$host = 'localhost';
$dbname = 'loogistics';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

/* ══════════════════════════════════════════════════════════════
   AJAX HANDLERS
══════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    switch ($action) {

        case 'add_item':
            try {
                $item_code = !empty($_POST['item_code']) ? $_POST['item_code'] :
                    strtoupper(substr($_POST['category'], 0, 3)) . '-' .
                    strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($_POST['item_name'])), 0, 4)) . '-' .
                    substr(time(), -3);

                $check = $pdo->prepare("SELECT COUNT(*) FROM inventory_items WHERE item_code = ?");
                $check->execute([$item_code]);
                if ($check->fetchColumn() > 0) $item_code .= '-' . rand(100, 999);

                $stmt = $pdo->prepare("INSERT INTO inventory_items (item_name, item_code, category, quantity, unit_price, supplier, location, reorder_level, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $result = $stmt->execute([$_POST['item_name'], $item_code, $_POST['category'], $_POST['quantity'], $_POST['unit_price'], $_POST['supplier'], $_POST['location'], $_POST['reorder_level'], $_POST['description'], $user_email]);
                echo json_encode(['success' => $result, 'message' => $result ? 'Item added successfully' : 'Failed to add item', 'item_code' => $item_code]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            exit;

        case 'update_stock':
            try {
                $stmt = $pdo->prepare("UPDATE inventory_items SET quantity = quantity + ?, last_updated = CURRENT_TIMESTAMP WHERE id = ?");
                $result = $stmt->execute([$_POST['quantity_change'], $_POST['item_id']]);
                $type = $_POST['quantity_change'] > 0 ? 'IN' : 'OUT';
                $stmt2 = $pdo->prepare("INSERT INTO stock_movements (item_id, movement_type, quantity, reason, performed_by) VALUES (?, ?, ?, ?, ?)");
                $stmt2->execute([$_POST['item_id'], $type, abs($_POST['quantity_change']), $_POST['reason'], $user_email]);
                echo json_encode(['success' => $result, 'message' => $result ? 'Stock updated successfully' : 'Failed to update stock']);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            exit;

        case 'edit_item':
            try {
                $stmt = $pdo->prepare("UPDATE inventory_items SET item_name=?, category=?, unit_price=?, supplier=?, location=?, reorder_level=?, description=?, last_updated=CURRENT_TIMESTAMP WHERE id=?");
                $result = $stmt->execute([$_POST['item_name'], $_POST['category'], $_POST['unit_price'], $_POST['supplier'], $_POST['location'], $_POST['reorder_level'], $_POST['description'], $_POST['item_id']]);
                echo json_encode(['success' => $result, 'message' => $result ? 'Item updated successfully' : 'Failed to update item']);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            exit;

        case 'delete_item':
            try {
                $check = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE item_id = ?");
                $check->execute([$_POST['item_id']]);
                if ($check->fetchColumn() > 0) {
                    $stmt = $pdo->prepare("UPDATE inventory_items SET status='inactive', last_updated=CURRENT_TIMESTAMP WHERE id=?");
                    $result = $stmt->execute([$_POST['item_id']]);
                    $msg = 'Item marked as inactive (has stock history)';
                } else {
                    $stmt = $pdo->prepare("DELETE FROM inventory_items WHERE id=?");
                    $result = $stmt->execute([$_POST['item_id']]);
                    $msg = 'Item deleted successfully';
                }
                echo json_encode(['success' => $result, 'message' => $result ? $msg : 'Failed to delete item']);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            exit;

        case 'get_item_details':
            try {
                $stmt = $pdo->prepare("SELECT * FROM inventory_items WHERE id = ?");
                $stmt->execute([$_POST['item_id']]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode($item ? ['success' => true, 'data' => $item] : ['success' => false, 'message' => 'Item not found']);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            exit;

        case 'get_stock_history':
            try {
                $stmt = $pdo->prepare("SELECT sm.*, ii.item_name FROM stock_movements sm JOIN inventory_items ii ON sm.item_id=ii.id WHERE sm.item_id=? ORDER BY sm.created_at DESC LIMIT 10");
                $stmt->execute([$_POST['item_id']]);
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            exit;

        case 'check_in_asset':
            try {
                $stmt = $pdo->prepare("INSERT INTO stock_movements (item_id, movement_type, quantity, reason, to_location, performed_by, created_at) VALUES (?, 'IN', ?, 'Asset Check-In', ?, ?, NOW())");
                $result = $stmt->execute([$_POST['item_id'], $_POST['quantity'], $_POST['location'], $user_email]);
                $stmt2 = $pdo->prepare("UPDATE inventory_items SET quantity=quantity+?, location=?, last_updated=CURRENT_TIMESTAMP WHERE id=?");
                $stmt2->execute([$_POST['quantity'], $_POST['location'], $_POST['item_id']]);
                echo json_encode(['success' => $result, 'message' => $result ? 'Asset checked in successfully' : 'Failed to check in asset']);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            exit;

        case 'check_out_asset':
            try {
                $stmt = $pdo->prepare("INSERT INTO stock_movements (item_id, movement_type, quantity, reason, from_location, performed_by, created_at) VALUES (?, 'OUT', ?, 'Asset Check-Out', ?, ?, NOW())");
                $result = $stmt->execute([$_POST['item_id'], $_POST['quantity'], $_POST['location'], $user_email]);
                $stmt2 = $pdo->prepare("UPDATE inventory_items SET quantity=quantity-?, last_updated=CURRENT_TIMESTAMP WHERE id=?");
                $stmt2->execute([$_POST['quantity'], $_POST['item_id']]);
                echo json_encode(['success' => $result, 'message' => $result ? 'Asset checked out successfully' : 'Failed to check out asset']);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            exit;

        case 'transfer_asset':
            try {
                $stmt = $pdo->prepare("INSERT INTO stock_movements (item_id, movement_type, quantity, reason, from_location, to_location, performed_by, created_at) VALUES (?, 'TRANSFER', ?, ?, ?, ?, ?, NOW())");
                $result = $stmt->execute([$_POST['item_id'], $_POST['quantity'], $_POST['reason'] ?? 'Location Transfer', $_POST['from_location'], $_POST['to_location'], $user_email]);
                $stmt2 = $pdo->prepare("UPDATE inventory_items SET location=?, last_updated=CURRENT_TIMESTAMP WHERE id=?");
                $stmt2->execute([$_POST['to_location'], $_POST['item_id']]);
                echo json_encode(['success' => $result, 'message' => $result ? 'Asset transferred successfully' : 'Failed to transfer asset']);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            exit;

        case 'get_supplier_location':
            try {
                $stmt = $pdo->prepare("SELECT address FROM suppliers WHERE supplier_name=? AND status='active'");
                $stmt->execute([$_POST['supplier_name']]);
                $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['success' => !!$supplier, 'location' => $supplier ? $supplier['address'] : '']);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'location' => '']);
            }
            exit;

        case 'get_low_stock':
            try {
                $stmt = $pdo->query("SELECT * FROM inventory_items WHERE quantity <= reorder_level AND status='active' ORDER BY quantity ASC");
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            exit;

        case 'get_recent_movements':
            try {
                $stmt = $pdo->query("SELECT sm.*, ii.item_name, ii.item_code FROM stock_movements sm JOIN inventory_items ii ON sm.item_id=ii.id ORDER BY sm.created_at DESC LIMIT 15");
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            exit;

        case 'get_location_inventory':
            try {
                $loc = $_POST['location'] ?? '';
                if (!$loc) { echo json_encode(['success' => false, 'message' => 'Location is required']); exit; }
                $stmt = $pdo->prepare("SELECT * FROM inventory_items WHERE location=? AND status='active' ORDER BY item_name ASC");
                $stmt->execute([$loc]);
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            exit;

        case 'generate_inventory_report':
            try {
                $type = $_POST['report_type'] ?? 'summary';
                if ($type === 'summary') {
                    $stmt = $pdo->query("SELECT category, COUNT(*) as total_items, SUM(quantity) as total_quantity, SUM(quantity*unit_price) as total_value, AVG(unit_price) as avg_price FROM inventory_items WHERE status='active' GROUP BY category ORDER BY total_value DESC");
                } elseif ($type === 'low_stock') {
                    $stmt = $pdo->query("SELECT * FROM inventory_items WHERE quantity<=reorder_level AND status='active' ORDER BY (quantity/NULLIF(reorder_level,0)) ASC");
                } else {
                    $stmt = $pdo->query("SELECT item_code, item_name, category, quantity, unit_price, (quantity*unit_price) as total_value, location FROM inventory_items WHERE status='active' ORDER BY total_value DESC LIMIT 50");
                }
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            exit;

        case 'get_movement_analytics':
            try {
                $days = $_POST['days'] ?? 30;
                $stmt = $pdo->prepare("SELECT DATE(created_at) as movement_date, movement_type, COUNT(*) as transaction_count, SUM(quantity) as total_quantity FROM stock_movements WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY DATE(created_at), movement_type ORDER BY movement_date DESC");
                $stmt->execute([$days]);
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            exit;
    }
}

/* ══════════════════════════════════════════════════════════════
   PAGE DATA
══════════════════════════════════════════════════════════════ */
try {
    $total_items      = $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE status='active'")->fetchColumn();
    $total_value      = $pdo->query("SELECT COALESCE(SUM(quantity*unit_price),0) FROM inventory_items WHERE status='active'")->fetchColumn();
    $low_stock_count  = $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE quantity<=reorder_level AND status='active'")->fetchColumn();
    $today_movements  = $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE DATE(created_at)=CURDATE()")->fetchColumn();

    $stmt = $pdo->query("SELECT * FROM inventory_items WHERE status='active' ORDER BY created_at DESC LIMIT 10");
    $recent_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT sm.*, ii.item_name FROM stock_movements sm JOIN inventory_items ii ON sm.item_id=ii.id ORDER BY sm.created_at DESC LIMIT 5");
    $recent_movements_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT id, supplier_code, supplier_name, address FROM suppliers WHERE status='active' ORDER BY supplier_name ASC");
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT DISTINCT location FROM inventory_items WHERE status='active' ORDER BY location ASC");
    $locations = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $total_items = $total_value = $low_stock_count = $today_movements = 0;
    $recent_items = $recent_movements_data = $suppliers = $locations = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Warehousing — BRIGHTPATH</title>
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
            --navy:    #0f1f3d;
            --blue:    #1a3a6e;
            --accent:  #3d7fff;
            --steel:   #2c4a8a;
            --white:   #ffffff;
            --off:     #f4f6fb;
            --border:  #dde3ef;
            --text:    #1a2540;
            --muted:   #6b7a99;
            --success: #15803d;
            --warn:    #b45309;
            --error:   #c53030;
            --card-bg: rgba(255,255,255,0.97);
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--navy);
            min-height: 100vh;
            color: var(--text);
        }

        body::before {
            content: '';
            position: fixed; inset: 0;
            background-image:
                linear-gradient(rgba(61,127,255,.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(61,127,255,.05) 1px, transparent 1px);
            background-size: 48px 48px;
            pointer-events: none;
            z-index: 0;
        }

        body::after {
            content: '';
            position: fixed;
            width: 600px; height: 600px; border-radius: 50%;
            background: radial-gradient(circle, rgba(61,127,255,.13) 0%, transparent 70%);
            top: -150px; right: -150px;
            pointer-events: none; z-index: 0;
        }

        /* ── HEADER ─── */
        
        .header-inner { max-width: 1400px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; }
        
        .brand-logo svg { width: 38px; height: 38px; flex-shrink: 0; }
        
        
        .header-right { display: flex; align-items: center; gap: 1.4rem; }

        
        
        .back-btn svg { width: 14px; height: 14px; }

        
        .user-avatar { width: 30px; height: 30px; border-radius: 50%; background: linear-gradient(135deg, var(--accent), var(--steel)); display: flex; align-items: center; justify-content: center; font-size: .72rem; font-weight: 600; color: #fff; }
        
        

        
        
        .logout-btn svg { width: 14px; height: 14px; }

        /* ── LAYOUT ─── */
        .container { max-width: 1400px; margin: 0 auto; padding: 2.5rem 2.5rem 4rem; position: relative; z-index: 1; }

        .page-title { margin-bottom: 2.5rem; }
        .page-title-tag { font-family: 'DM Mono', monospace; font-size: .68rem; color: var(--accent); letter-spacing: .2em; text-transform: uppercase; margin-bottom: .5rem; }
        .page-title h1 { font-size: clamp(1.6rem, 2.5vw, 2.2rem); font-weight: 300; color: var(--white); line-height: 1.2; }
        .page-title h1 strong { font-weight: 600; color: #7eb3ff; }
        .page-title p { font-size: .9rem; color: rgba(255,255,255,.5); margin-top: .4rem; }

        /* ── STATS ─── */
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.2rem; margin-bottom: 2.5rem; }

        .stat-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 14px; padding: 1.5rem 1.6rem; position: relative; overflow: hidden; transition: transform .2s, box-shadow .2s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 12px 32px rgba(0,0,0,.18); }
        .stat-card::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, var(--accent), var(--steel)); border-radius: 14px 14px 0 0; }
        .stat-card.warn::after { background: linear-gradient(90deg, #f59e0b, #d97706); }

        .stat-icon-wrap { width: 42px; height: 42px; background: rgba(61,127,255,.1); border: 1px solid rgba(61,127,255,.2); border-radius: 10px; display: flex; align-items: center; justify-content: center; margin-bottom: 1rem; }
        .stat-card.warn .stat-icon-wrap { background: rgba(245,158,11,.1); border-color: rgba(245,158,11,.2); }
        .stat-icon-wrap svg { width: 20px; height: 20px; stroke: var(--accent); }
        .stat-card.warn .stat-icon-wrap svg { stroke: #f59e0b; }

        .stat-value { font-size: 1.9rem; font-weight: 600; color: var(--text); line-height: 1; margin-bottom: .35rem; }
        .stat-label { font-family: 'DM Mono', monospace; font-size: .65rem; color: var(--muted); letter-spacing: .14em; text-transform: uppercase; }

        /* ── MAIN GRID ─── */
        .main-grid { display: grid; grid-template-columns: 1fr 340px; gap: 1.4rem; margin-bottom: 1.4rem; }

        /* ── PANEL ─── */
        .panel { background: var(--card-bg); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }

        .panel-header { display: flex; justify-content: space-between; align-items: center; padding: 1.2rem 1.6rem; border-bottom: 1px solid var(--border); flex-wrap: wrap; gap: .7rem; }
        .panel-title { font-size: .92rem; font-weight: 600; color: var(--text); }

        /* ── BUTTONS ─── */
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: .45rem 1rem; border-radius: 7px; font-size: .78rem; font-weight: 600; cursor: pointer; border: 1px solid transparent; transition: all .2s; font-family: 'DM Sans', sans-serif; }
        .btn svg { width: 13px; height: 13px; }

        .btn-primary   { background: var(--accent); border-color: var(--accent); color: #fff; }
        .btn-primary:hover { background: #2d6ee6; }

        .btn-ghost     { background: rgba(61,127,255,.08); border-color: rgba(61,127,255,.2); color: var(--accent); }
        .btn-ghost:hover { background: rgba(61,127,255,.18); }

        .btn-green     { background: rgba(21,128,61,.1); border-color: rgba(21,128,61,.2); color: #16a34a; }
        .btn-green:hover { background: rgba(21,128,61,.2); }

        .btn-amber     { background: rgba(217,119,6,.1); border-color: rgba(217,119,6,.2); color: #d97706; }
        .btn-amber:hover { background: rgba(217,119,6,.2); }

        .btn-red       { background: rgba(197,48,48,.1); border-color: rgba(197,48,48,.2); color: #dc2626; }
        .btn-red:hover { background: rgba(197,48,48,.2); }

        .btn-teal      { background: rgba(13,148,136,.1); border-color: rgba(13,148,136,.2); color: #0d9488; }
        .btn-teal:hover { background: rgba(13,148,136,.2); }

        /* ── TABLE ─── */
        .data-table { width: 100%; border-collapse: collapse; min-width: 800px; }
        .data-table th { padding: .65rem 1rem; font-family: 'DM Mono', monospace; font-size: .6rem; letter-spacing: .14em; text-transform: uppercase; color: var(--muted); text-align: left; background: var(--off); border-bottom: 1px solid var(--border); }
        .data-table td { padding: .75rem 1rem; font-size: .82rem; color: var(--text); border-bottom: 1px solid var(--border); vertical-align: middle; }
        .data-table tbody tr:last-child td { border-bottom: none; }
        .data-table tbody tr:hover td { background: rgba(61,127,255,.025); }

        .tbl-wrap { overflow-x: auto; }

        .item-code { font-family: 'DM Mono', monospace; font-size: .72rem; color: var(--accent); font-weight: 500; }
        .item-name { font-weight: 500; }

        .badge { display: inline-block; padding: .2rem .6rem; border-radius: 99px; font-family: 'DM Mono', monospace; font-size: .58rem; font-weight: 500; letter-spacing: .07em; text-transform: uppercase; }
        .badge-low    { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; }
        .badge-normal { background: #dcfce7; color: #16a34a; border: 1px solid #86efac; }
        .badge-high   { background: #dbeafe; color: #2563eb; border: 1px solid #93c5fd; }

        .btn-row { display: flex; gap: .35rem; flex-wrap: wrap; }

        .empty-td { text-align: center; color: var(--muted); padding: 2.5rem !important; font-size: .84rem; }
        .empty-td a { color: var(--accent); text-decoration: none; }

        /* ── MOVEMENTS SIDEBAR ─── */
        .movement-list { padding: .5rem 0; max-height: 420px; overflow-y: auto; }
        .movement-item { display: flex; justify-content: space-between; align-items: center; padding: .85rem 1.4rem; border-bottom: 1px solid var(--border); }
        .movement-item:last-child { border-bottom: none; }
        .mi-name { font-size: .84rem; font-weight: 600; color: var(--text); margin-bottom: 2px; }
        .mi-reason { font-size: .74rem; color: var(--muted); }
        .mi-date { font-family: 'DM Mono', monospace; font-size: .62rem; color: var(--muted); margin-top: 2px; }
        .mi-badge { padding: .2rem .7rem; border-radius: 99px; font-family: 'DM Mono', monospace; font-size: .65rem; font-weight: 600; white-space: nowrap; }
        .mi-in       { background: #dcfce7; color: #16a34a; }
        .mi-out      { background: #fee2e2; color: #dc2626; }
        .mi-transfer { background: #dbeafe; color: #2563eb; }

        .panel-footer { padding: .9rem 1.4rem; border-top: 1px solid var(--border); }

        /* ── MODAL ─── */
        .modal { display: none; position: fixed; z-index: 1000; inset: 0; background: rgba(10,18,40,.65); backdrop-filter: blur(4px); align-items: flex-start; justify-content: center; overflow-y: auto; padding: 2rem 1rem; }
        .modal.open { display: flex; }

        .modal-box { background: #fff; border-radius: 16px; width: 100%; max-width: 580px; box-shadow: 0 24px 60px rgba(0,0,0,.3); margin: auto; }
        .modal-box.wide { max-width: 820px; }

        .modal-head { display: flex; justify-content: space-between; align-items: center; padding: 1.4rem 1.6rem 1rem; border-bottom: 1px solid var(--border); }
        .modal-head h3 { font-size: 1rem; font-weight: 600; color: var(--text); }
        .modal-close { background: none; border: none; cursor: pointer; width: 28px; height: 28px; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: var(--muted); transition: background .15s, color .15s; }
        .modal-close:hover { background: var(--off); color: var(--text); }
        .modal-close svg { width: 16px; height: 16px; }

        .modal-body { padding: 1.4rem 1.6rem 1.8rem; overflow-y: auto; max-height: calc(90vh - 130px); }

        /* scrollbar */
        .modal-body::-webkit-scrollbar { width: 6px; }
        .modal-body::-webkit-scrollbar-track { background: var(--off); border-radius: 99px; }
        .modal-body::-webkit-scrollbar-thumb { background: var(--border); border-radius: 99px; }

        /* ── FORM ─── */
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .form-group { display: flex; flex-direction: column; gap: .4rem; margin-bottom: 1rem; }
        .form-group:last-child { margin-bottom: 0; }
        .form-label { font-size: .8rem; font-weight: 600; color: var(--text); }
        .form-label .req { color: #dc2626; margin-left: 2px; }
        .form-hint { font-size: .72rem; color: var(--muted); }

        .form-input, .form-select, .form-textarea {
            width: 100%; padding: .6rem .85rem; border: 1.5px solid var(--border); border-radius: 8px;
            font-size: .84rem; font-family: 'DM Sans', sans-serif; background: var(--white);
            color: var(--text); transition: border-color .2s;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus { outline: none; border-color: var(--accent); }
        .form-input[readonly] { background: var(--off); cursor: not-allowed; color: var(--muted); }
        .form-textarea { resize: vertical; min-height: 72px; }

        .item-info-banner { background: var(--off); border: 1px solid var(--border); border-radius: 8px; padding: .75rem 1rem; font-size: .82rem; color: var(--muted); margin-bottom: 1.2rem; }
        .item-info-banner strong { color: var(--text); }

        .submit-btn { width: 100%; margin-top: 1rem; padding: .8rem; background: var(--accent); color: #fff; border: none; border-radius: 9px; font-size: .9rem; font-weight: 600; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: background .2s; }
        .submit-btn:hover { background: #2d6ee6; }

        /* ── TOAST ─── */
        #toast-container { position: fixed; top: 1.2rem; right: 1.2rem; z-index: 9999; display: flex; flex-direction: column; gap: .5rem; }
        .toast { display: flex; align-items: center; gap: 10px; padding: .85rem 1.2rem; border-radius: 10px; font-size: .84rem; font-weight: 500; max-width: 380px; box-shadow: 0 8px 24px rgba(0,0,0,.18); animation: toast-in .3s ease; }
        .toast svg { width: 16px; height: 16px; flex-shrink: 0; }
        .toast.success { background: #f0fdf4; border: 1px solid #86efac; color: #15803d; }
        .toast.error   { background: #fff1f2; border: 1px solid #fca5a5; color: #b91c1c; }
        .toast.info    { background: #eff6ff; border: 1px solid #93c5fd; color: #1d4ed8; }
        @keyframes toast-in { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }

        /* ── REPORT TABLE ─── */
        .report-table { width: 100%; border-collapse: collapse; font-size: .8rem; }
        .report-table th { padding: .55rem .8rem; background: var(--off); border-bottom: 1px solid var(--border); text-align: left; font-weight: 600; color: var(--text); }
        .report-table td { padding: .55rem .8rem; border-bottom: 1px solid var(--border); color: var(--text); }
        .report-table tbody tr:last-child td { border-bottom: none; }
        .report-header { display: flex; gap: .6rem; margin-bottom: 1.2rem; flex-wrap: wrap; }
        .report-tab { padding: .42rem .9rem; border-radius: 7px; font-size: .78rem; font-weight: 600; cursor: pointer; border: 1px solid var(--border); background: var(--off); color: var(--muted); transition: all .2s; }
        .report-tab.active { background: var(--accent); border-color: var(--accent); color: #fff; }
        .report-empty { text-align: center; padding: 2rem; color: var(--muted); font-size: .84rem; }

        /* ── MISC ─── */
        .low-stock-item { display: flex; justify-content: space-between; align-items: center; padding: .8rem .2rem; border-bottom: 1px solid var(--border); }
        .low-stock-item:last-child { border-bottom: none; }
        .ls-info .ls-name { font-weight: 600; font-size: .86rem; color: var(--text); }
        .ls-info .ls-loc  { font-size: .74rem; color: var(--muted); margin-top: 2px; }
        .ls-qty { font-family: 'DM Mono', monospace; font-size: .84rem; color: #dc2626; font-weight: 600; }

        /* ── KEYBOARD HINT ─── */
        .kbd-hint { font-family: 'DM Mono', monospace; font-size: .6rem; color: rgba(255,255,255,.3); padding: .15rem .4rem; border: 1px solid rgba(255,255,255,.1); border-radius: 4px; }

        /* ── RESPONSIVE ─── */
        @media (max-width: 1100px) {
            .main-grid { grid-template-columns: 1fr; }
            .stats-row { grid-template-columns: repeat(2,1fr); }
        }
        @media (max-width: 700px) {
            .container { padding: 1.5rem 1rem 3rem; }
            .header { padding: .9rem 1rem; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .form-row { grid-template-columns: 1fr; }
            .header-right 
        }
        @media (max-width: 480px) {
            .stats-row { grid-template-columns: 1fr; }
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
                    <p>Smart Warehousing</p>
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
                    <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name']??'U',0,1)); ?></div>
                    <span class="user-name"><?php echo htmlspecialchars($_SESSION['user_name']??'User'); ?></span>
                    <svg class="pill-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
                <div class="profile-dropdown">
                    <div class="pd-head">
                        <div class="pd-avatar"><?php echo strtoupper(substr($_SESSION['user_name']??'U',0,1)); ?></div>
                        <div>
                            <div class="pd-info-name"><?php echo htmlspecialchars($_SESSION['user_name']??''); ?></div>
                            <div class="pd-info-email"><?php echo htmlspecialchars($_SESSION['user_email']??''); ?></div>
                        </div>
                    </div>
                    <div class="pd-body">
                        <div class="pd-row">
                            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <span class="pd-row-label">Role</span>
                            <span class="pd-row-val"><span class="pd-role-badge staff"><?php echo ucfirst($_SESSION['user_role']??'user'); ?></span></span>
                        </div>
                        <div class="pd-row">
                            <svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 7l10 7 10-7"/></svg>
                            <span class="pd-row-label">Email</span>
                            <span class="pd-row-val" style="font-size:.75rem;word-break:break-all"><?php echo htmlspecialchars($_SESSION['user_email']??'—'); ?></span>
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
<!-- TOAST CONTAINER -->
<div id="toast-container"></div>

<!-- HEADER -->
<div class="container">

    <!-- PAGE TITLE -->
    <div class="page-title">
        <div class="page-title-tag">Module / Warehouse</div>
        <h1>Smart <strong>Warehousing System</strong></h1>
        <p>Track storage, monitor stock levels, manage locations, and control asset movements.
           <span class="kbd-hint">Ctrl+N</span> new item &nbsp; <span class="kbd-hint">Ctrl+L</span> low stock
        </p>
    </div>

    <!-- STATS -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/>
                    <polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>
                </svg>
            </div>
            <div class="stat-value"><?php echo number_format($total_items); ?></div>
            <div class="stat-label">Total Items</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>
                </svg>
            </div>
            <div class="stat-value">₱<?php echo number_format($total_value, 0); ?></div>
            <div class="stat-label">Total Value</div>
        </div>
        <div class="stat-card <?php echo $low_stock_count > 0 ? 'warn' : ''; ?>">
            <div class="stat-icon-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
            </div>
            <div class="stat-value"><?php echo number_format($low_stock_count); ?></div>
            <div class="stat-label">Low Stock Items</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                </svg>
            </div>
            <div class="stat-value"><?php echo number_format($today_movements); ?></div>
            <div class="stat-label">Today's Movements</div>
        </div>
    </div>

    <!-- MAIN GRID -->
    <div class="main-grid">

        <!-- Inventory Panel -->
        <div class="panel">
            <div class="panel-header">
                <span class="panel-title">Inventory Items</span>
                <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
                    <button class="btn btn-amber" onclick="checkLowStock()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        Low Stock
                    </button>
                    <button class="btn btn-ghost" onclick="openLocationManagement()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Locations
                    </button>
                    <button class="btn btn-ghost" onclick="openReportModal()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
                        Reports
                    </button>
                    <button class="btn btn-primary" onclick="openAddItemModal()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Add Item
                    </button>
                </div>
            </div>
            <div class="tbl-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Item Code</th>
                            <th>Item Name</th>
                            <th>Category</th>
                            <th>Qty</th>
                            <th>Unit Price</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_items)): ?>
                        <tr><td colspan="8" class="empty-td">No inventory items yet. <a href="#" onclick="openAddItemModal(); return false;">Add your first item →</a></td></tr>
                        <?php else: foreach ($recent_items as $item): ?>
                        <tr>
                            <td><span class="item-code"><?php echo htmlspecialchars($item['item_code']); ?></span></td>
                            <td><span class="item-name"><?php echo htmlspecialchars($item['item_name']); ?></span></td>
                            <td><?php echo htmlspecialchars($item['category']); ?></td>
                            <td><strong><?php echo $item['quantity']; ?></strong></td>
                            <td>₱<?php echo number_format($item['unit_price'], 2); ?></td>
                            <td><?php echo htmlspecialchars($item['location']); ?></td>
                            <td>
                                <?php if ($item['quantity'] <= $item['reorder_level']): ?>
                                    <span class="badge badge-low">Low</span>
                                <?php elseif ($item['quantity'] > $item['reorder_level'] * 2): ?>
                                    <span class="badge badge-high">High</span>
                                <?php else: ?>
                                    <span class="badge badge-normal">Normal</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-row">
                                    <button class="btn btn-teal" onclick="openAssetCheckInOut(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['item_name'])); ?>')">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                        In/Out
                                    </button>
                                    <button class="btn btn-ghost" onclick="openTransferModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['item_name'])); ?>', '<?php echo htmlspecialchars(addslashes($item['location'])); ?>')">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 16V4m0 0L3 8m4-4l4 4M17 8v12m0 0l4-4m-4 4l-4-4"/></svg>
                                        Transfer
                                    </button>
                                    <button class="btn btn-amber" onclick="openEnhancedStockModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['item_name'])); ?>')">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>
                                        Stock
                                    </button>
                                    <button class="btn btn-green" onclick="openEditItemModal(<?php echo $item['id']; ?>)">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        Edit
                                    </button>
                                    <button class="btn btn-red" onclick="deleteItem(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['item_name'])); ?>')">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6m3 0V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
                                        Del
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Movements Sidebar -->
        <div class="panel">
            <div class="panel-header">
                <span class="panel-title">Recent Movements</span>
                <button class="btn btn-ghost" onclick="refreshRecentMovements()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>
                    Refresh
                </button>
            </div>
            <div class="movement-list" id="recent-movements-container">
                <?php if (empty($recent_movements_data)): ?>
                <div style="text-align:center; padding:2rem; color:var(--muted); font-size:.84rem;">No recent movements.</div>
                <?php else: foreach ($recent_movements_data as $mv): ?>
                <div class="movement-item">
                    <div>
                        <div class="mi-name"><?php echo htmlspecialchars($mv['item_name']); ?></div>
                        <div class="mi-reason"><?php echo htmlspecialchars($mv['reason'] ?? 'Stock adjustment'); ?></div>
                        <div class="mi-date"><?php echo date('M d, Y H:i', strtotime($mv['created_at'])); ?></div>
                    </div>
                    <span class="mi-badge <?php
                        echo $mv['movement_type'] === 'IN' ? 'mi-in' : ($mv['movement_type'] === 'TRANSFER' ? 'mi-transfer' : 'mi-out');
                    ?>">
                        <?php echo $mv['movement_type'] === 'IN' ? '+' : ($mv['movement_type'] === 'TRANSFER' ? '⇄' : '-'); echo $mv['quantity']; ?>
                    </span>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MODALS
═══════════════════════════════════════════════════════════ -->

<!-- ADD ITEM MODAL -->
<div id="addItemModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h3>Add New Item</h3>
            <button class="modal-close" onclick="closeModal('addItemModal')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="modal-body">
            <form id="addItemForm">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Item Name <span class="req">*</span></label>
                        <input type="text" name="item_name" class="form-input" required placeholder="e.g. Office Chair">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Item Code <span style="font-size:.72rem; color:var(--muted);">(auto)</span></label>
                        <input type="text" name="item_code" class="form-input" readonly placeholder="Auto-generated">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Category <span class="req">*</span></label>
                    <select name="category" class="form-select" required>
                        <option value="">Select Category</option>
                        <option>Electronics</option><option>Office Supplies</option><option>Furniture</option>
                        <option>Equipment</option><option>Materials</option><option>Tools</option>
                        <option>Safety</option><option>Consumables</option><option>Other</option>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Initial Quantity <span class="req">*</span></label>
                        <input type="number" name="quantity" class="form-input" min="0" required placeholder="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Unit Price (₱) <span class="req">*</span></label>
                        <input type="number" name="unit_price" class="form-input" step="0.01" min="0" required placeholder="0.00">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Supplier</label>
                        <select name="supplier" class="form-select" onchange="populateSupplierLocation(this.value)">
                            <option value="">Select Supplier</option>
                            <?php foreach ($suppliers as $s): ?>
                            <option value="<?php echo htmlspecialchars($s['supplier_name']); ?>" data-address="<?php echo htmlspecialchars($s['address']); ?>">
                                <?php echo htmlspecialchars($s['supplier_name']); ?> (<?php echo htmlspecialchars($s['supplier_code']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Location <span class="req">*</span></label>
                        <input type="text" name="location" class="form-input" required placeholder="Warehouse location">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Reorder Level <span class="req">*</span></label>
                    <input type="number" name="reorder_level" class="form-input" min="0" required placeholder="Minimum stock level">
                    <span class="form-hint">Alert triggered when stock falls below this level.</span>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-textarea" placeholder="Additional details..."></textarea>
                </div>
                <button type="submit" class="submit-btn">Add Item to Inventory</button>
            </form>
        </div>
    </div>
</div>

<!-- EDIT ITEM MODAL -->
<div id="editItemModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h3>Edit Item</h3>
            <button class="modal-close" onclick="closeModal('editItemModal')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="modal-body">
            <form id="editItemForm">
                <input type="hidden" name="item_id" id="edit_item_id">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Item Name <span class="req">*</span></label>
                        <input type="text" name="item_name" id="edit_item_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Item Code</label>
                        <input type="text" id="edit_item_code" class="form-input" readonly>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Category <span class="req">*</span></label>
                    <select name="category" id="edit_category" class="form-select" required>
                        <option value="">Select Category</option>
                        <option>Electronics</option><option>Office Supplies</option><option>Furniture</option>
                        <option>Equipment</option><option>Materials</option><option>Tools</option>
                        <option>Safety</option><option>Consumables</option><option>Other</option>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Current Quantity</label>
                        <input type="number" id="edit_quantity" class="form-input" readonly>
                        <span class="form-hint">Use "Update Stock" to change quantity.</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Unit Price (₱) <span class="req">*</span></label>
                        <input type="number" name="unit_price" id="edit_unit_price" class="form-input" step="0.01" min="0" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Supplier</label>
                        <select name="supplier" id="edit_supplier" class="form-select" onchange="populateEditSupplierLocation(this.value)">
                            <option value="">Select Supplier</option>
                            <?php foreach ($suppliers as $s): ?>
                            <option value="<?php echo htmlspecialchars($s['supplier_name']); ?>" data-address="<?php echo htmlspecialchars($s['address']); ?>">
                                <?php echo htmlspecialchars($s['supplier_name']); ?> (<?php echo htmlspecialchars($s['supplier_code']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Location <span class="req">*</span></label>
                        <input type="text" name="location" id="edit_location" class="form-input" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Reorder Level <span class="req">*</span></label>
                    <input type="number" name="reorder_level" id="edit_reorder_level" class="form-input" min="0" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="edit_description" class="form-textarea"></textarea>
                </div>
                <button type="submit" class="submit-btn">Save Changes</button>
            </form>
        </div>
    </div>
</div>

<!-- UPDATE STOCK MODAL -->
<div id="updateStockModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h3>Update Stock</h3>
            <button class="modal-close" onclick="closeModal('updateStockModal')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="modal-body">
            <form id="updateStockForm">
                <input type="hidden" name="item_id" id="stock_item_id">
                <div class="item-info-banner">
                    Updating stock for: <strong id="stock_item_name">—</strong>
                </div>
                <div class="form-group">
                    <label class="form-label">Quantity Change <span class="req">*</span></label>
                    <input type="number" name="quantity_change" class="form-input" required placeholder="Use negative values for stock out">
                    <span class="form-hint">Positive = stock in. Negative = stock out.</span>
                </div>
                <div class="form-group">
                    <label class="form-label">Reason</label>
                    <select name="reason" class="form-select">
                        <option>Stock In - Purchase</option>
                        <option>Stock In - Return</option>
                        <option>Stock Out - Sale</option>
                        <option>Stock Out - Damage</option>
                        <option>Stock Out - Loss</option>
                        <option>Adjustment - Inventory Count</option>
                        <option>Transfer</option>
                        <option>Other</option>
                    </select>
                </div>
                <button type="submit" class="submit-btn">Update Stock</button>
            </form>
        </div>
    </div>
</div>

<!-- LOW STOCK MODAL -->
<div id="lowStockModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h3>Low Stock Alert</h3>
            <button class="modal-close" onclick="closeModal('lowStockModal')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="modal-body" id="lowStockContent" style="min-height:80px;">
            <div style="text-align:center; color:var(--muted); font-size:.84rem; padding:2rem;">Loading...</div>
        </div>
    </div>
</div>

<!-- CHECK IN/OUT MODAL -->
<div id="checkInOutModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h3>Asset Check In / Out</h3>
            <button class="modal-close" onclick="closeModal('checkInOutModal')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="checkinout_item_id">
            <div class="item-info-banner">Item: <strong id="checkinout_item_name">—</strong></div>
            <div class="form-group">
                <label class="form-label">Action <span class="req">*</span></label>
                <select id="checkinout_action" class="form-select">
                    <option value="check_in">Check In (Add to warehouse)</option>
                    <option value="check_out">Check Out (Remove from warehouse)</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Quantity <span class="req">*</span></label>
                <input type="number" id="checkinout_quantity" class="form-input" min="1" required placeholder="Enter quantity">
            </div>
            <div class="form-group">
                <label class="form-label">Location <span class="req">*</span></label>
                <input type="text" id="checkinout_location" class="form-input" required placeholder="Warehouse location">
            </div>
            <button class="submit-btn" onclick="submitCheckInOut()">Confirm Action</button>
        </div>
    </div>
</div>

<!-- TRANSFER MODAL -->
<div id="transferModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h3>Transfer Asset</h3>
            <button class="modal-close" onclick="closeModal('transferModal')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="transfer_item_id">
            <div class="item-info-banner">Item: <strong id="transfer_item_name">—</strong></div>
            <div class="form-group">
                <label class="form-label">From Location</label>
                <input type="text" id="transfer_from_location" class="form-input" readonly>
            </div>
            <div class="form-group">
                <label class="form-label">To Location <span class="req">*</span></label>
                <input type="text" id="transfer_to_location" class="form-input" required placeholder="Destination location">
            </div>
            <div class="form-group">
                <label class="form-label">Quantity <span class="req">*</span></label>
                <input type="number" id="transfer_quantity" class="form-input" min="1" required placeholder="Units to transfer">
            </div>
            <div class="form-group">
                <label class="form-label">Reason</label>
                <input type="text" id="transfer_reason" class="form-input" placeholder="Reason for transfer">
            </div>
            <button class="submit-btn" onclick="submitTransfer()">Confirm Transfer</button>
        </div>
    </div>
</div>

<!-- LOCATION MODAL -->
<div id="locationModal" class="modal">
    <div class="modal-box wide">
        <div class="modal-head">
            <h3>Location Inventory</h3>
            <button class="modal-close" onclick="closeModal('locationModal')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="modal-body">
            <div class="form-group" style="margin-bottom:1.4rem;">
                <label class="form-label">Select Location</label>
                <select id="location_select" class="form-select" onchange="loadLocationInventory(this.value)">
                    <option value="">— choose a location —</option>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?php echo htmlspecialchars($loc); ?>"><?php echo htmlspecialchars($loc); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="locationInventoryContent">
                <div style="text-align:center; color:var(--muted); font-size:.84rem; padding:2rem;">Select a location to view inventory.</div>
            </div>
        </div>
    </div>
</div>

<!-- REPORT MODAL -->
<div id="reportModal" class="modal">
    <div class="modal-box wide">
        <div class="modal-head">
            <h3>Inventory Reports</h3>
            <button class="modal-close" onclick="closeModal('reportModal')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="modal-body">
            <div class="report-header">
                <button class="report-tab active" onclick="loadReport('summary', this)">Category Summary</button>
                <button class="report-tab" onclick="loadReport('low_stock', this)">Low Stock</button>
                <button class="report-tab" onclick="loadReport('valuation', this)">Valuation</button>
            </div>
            <div id="reportContent"><div style="text-align:center; color:var(--muted); padding:2rem;">Loading report...</div></div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     JAVASCRIPT
═══════════════════════════════════════════════════════════ -->
<script>
/* ── TOAST ───────────────────────────────────────── */
function showToast(message, type = 'info') {
    const icons = {
        success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
        error:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
        info:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
    };
    const t = document.createElement('div');
    t.className = `toast ${type}`;
    t.innerHTML = (icons[type] || icons.info) + `<span>${message}</span>`;
    document.getElementById('toast-container').appendChild(t);
    setTimeout(() => t.style.opacity = '0', 4200);
    setTimeout(() => t.remove(), 4500);
    t.style.transition = 'opacity .3s ease';
    t.addEventListener('click', () => t.remove());
}

/* ── MODAL ───────────────────────────────────────── */
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    const forms = document.getElementById(id).querySelectorAll('form');
    forms.forEach(f => f.reset());
}

window.addEventListener('click', e => {
    ['addItemModal','editItemModal','updateStockModal','lowStockModal','checkInOutModal','transferModal','locationModal','reportModal'].forEach(id => {
        const el = document.getElementById(id);
        if (e.target === el) closeModal(id);
    });
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        const open = document.querySelector('.modal.open');
        if (open) closeModal(open.id);
    }
    if (e.ctrlKey && e.key === 'n') { e.preventDefault(); openAddItemModal(); }
    if (e.ctrlKey && e.key === 'l') { e.preventDefault(); checkLowStock(); }
});

/* ── AJAX HELPER ─────────────────────────────────── */
function ajax(data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k, v));
    return fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    }).then(r => r.json());
}

/* ── ADD ITEM ────────────────────────────────────── */
function openAddItemModal() { openModal('addItemModal'); }

document.getElementById('addItemForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = this.querySelector('.submit-btn');
    btn.textContent = 'Adding…'; btn.disabled = true;
    const fd = new FormData(this);
    fd.append('action', 'add_item');
    const res = await fetch(window.location.href, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd }).then(r => r.json());
    btn.textContent = 'Add Item to Inventory'; btn.disabled = false;
    if (res.success) {
        showToast(`Item added — code: ${res.item_code}`, 'success');
        closeModal('addItemModal');
        setTimeout(() => location.reload(), 1200);
    } else {
        showToast(res.message || 'Failed to add item', 'error');
    }
});

/* ── SUPPLIER LOCATION AUTO-FILL ─────────────────── */
function populateSupplierLocation(name) {
    const sel = document.querySelector('#addItemModal select[name="supplier"]');
    const opt = sel ? sel.querySelector(`option[value="${name}"]`) : null;
    const locInput = document.querySelector('#addItemModal input[name="location"]');
    if (opt && opt.dataset.address && locInput && !locInput.value) locInput.value = opt.dataset.address;
}

function populateEditSupplierLocation(name) {
    const sel = document.getElementById('edit_supplier');
    const opt = sel ? sel.querySelector(`option[value="${name}"]`) : null;
    const locInput = document.getElementById('edit_location');
    if (opt && opt.dataset.address && locInput && !locInput.value) locInput.value = opt.dataset.address;
}

/* ── EDIT ITEM ───────────────────────────────────── */
async function openEditItemModal(id) {
    const res = await ajax({ action: 'get_item_details', item_id: id });
    if (!res.success) { showToast('Could not load item details', 'error'); return; }
    const d = res.data;
    document.getElementById('edit_item_id').value    = d.id;
    document.getElementById('edit_item_name').value  = d.item_name;
    document.getElementById('edit_item_code').value  = d.item_code;
    document.getElementById('edit_category').value   = d.category;
    document.getElementById('edit_quantity').value   = d.quantity;
    document.getElementById('edit_unit_price').value = d.unit_price;
    document.getElementById('edit_supplier').value   = d.supplier || '';
    document.getElementById('edit_location').value   = d.location;
    document.getElementById('edit_reorder_level').value = d.reorder_level;
    document.getElementById('edit_description').value   = d.description || '';
    openModal('editItemModal');
}

document.getElementById('editItemForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = this.querySelector('.submit-btn');
    btn.textContent = 'Saving…'; btn.disabled = true;
    const fd = new FormData(this);
    fd.append('action', 'edit_item');
    const res = await fetch(window.location.href, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd }).then(r => r.json());
    btn.textContent = 'Save Changes'; btn.disabled = false;
    if (res.success) {
        showToast('Item updated successfully', 'success');
        closeModal('editItemModal');
        setTimeout(() => location.reload(), 1200);
    } else {
        showToast(res.message || 'Failed to update item', 'error');
    }
});

/* ── UPDATE STOCK ────────────────────────────────── */
function openEnhancedStockModal(id, name) {
    document.getElementById('stock_item_id').value   = id;
    document.getElementById('stock_item_name').textContent = name;
    openModal('updateStockModal');
}

document.getElementById('updateStockForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = this.querySelector('.submit-btn');
    btn.textContent = 'Updating…'; btn.disabled = true;
    const fd = new FormData(this);
    fd.append('action', 'update_stock');
    const res = await fetch(window.location.href, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd }).then(r => r.json());
    btn.textContent = 'Update Stock'; btn.disabled = false;
    if (res.success) {
        showToast('Stock updated successfully', 'success');
        closeModal('updateStockModal');
        setTimeout(() => location.reload(), 1200);
    } else {
        showToast(res.message || 'Failed to update stock', 'error');
    }
});

/* ── DELETE ITEM ─────────────────────────────────── */
function deleteItem(id, name) {
    if (!confirm(`Delete "${name}"?\n\nIf it has stock history, it will be marked inactive instead.`)) return;
    ajax({ action: 'delete_item', item_id: id }).then(res => {
        if (res.success) {
            showToast(res.message, 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast(res.message || 'Failed to delete item', 'error');
        }
    });
}

/* ── CHECK IN / OUT ──────────────────────────────── */
function openAssetCheckInOut(id, name) {
    document.getElementById('checkinout_item_id').value = id;
    document.getElementById('checkinout_item_name').textContent = name;
    document.getElementById('checkinout_quantity').value = '';
    document.getElementById('checkinout_location').value = '';
    openModal('checkInOutModal');
}

async function submitCheckInOut() {
    const id  = document.getElementById('checkinout_item_id').value;
    const qty = document.getElementById('checkinout_quantity').value;
    const loc = document.getElementById('checkinout_location').value;
    const act = document.getElementById('checkinout_action').value;
    if (!qty || !loc) { showToast('Please fill in all fields', 'error'); return; }
    const res = await ajax({ action: act === 'check_in' ? 'check_in_asset' : 'check_out_asset', item_id: id, quantity: qty, location: loc });
    if (res.success) {
        showToast(res.message, 'success');
        closeModal('checkInOutModal');
        setTimeout(() => location.reload(), 1200);
    } else {
        showToast(res.message || 'Operation failed', 'error');
    }
}

/* ── TRANSFER ────────────────────────────────────── */
function openTransferModal(id, name, currentLocation) {
    document.getElementById('transfer_item_id').value       = id;
    document.getElementById('transfer_item_name').textContent = name;
    document.getElementById('transfer_from_location').value  = currentLocation;
    document.getElementById('transfer_to_location').value    = '';
    document.getElementById('transfer_quantity').value       = '';
    document.getElementById('transfer_reason').value         = '';
    openModal('transferModal');
}

async function submitTransfer() {
    const id   = document.getElementById('transfer_item_id').value;
    const from = document.getElementById('transfer_from_location').value;
    const to   = document.getElementById('transfer_to_location').value;
    const qty  = document.getElementById('transfer_quantity').value;
    const rsn  = document.getElementById('transfer_reason').value;
    if (!to || !qty) { showToast('Destination and quantity are required', 'error'); return; }
    const res = await ajax({ action: 'transfer_asset', item_id: id, from_location: from, to_location: to, quantity: qty, reason: rsn || 'Location Transfer' });
    if (res.success) {
        showToast(res.message, 'success');
        closeModal('transferModal');
        setTimeout(() => location.reload(), 1200);
    } else {
        showToast(res.message || 'Transfer failed', 'error');
    }
}

/* ── LOW STOCK ───────────────────────────────────── */
async function checkLowStock() {
    openModal('lowStockModal');
    document.getElementById('lowStockContent').innerHTML = '<div style="text-align:center;color:var(--muted);padding:2rem;">Loading...</div>';
    const res = await ajax({ action: 'get_low_stock' });
    if (!res.success) { document.getElementById('lowStockContent').innerHTML = '<div style="text-align:center;color:var(--error);padding:2rem;">Failed to load data.</div>'; return; }
    if (!res.data.length) {
        document.getElementById('lowStockContent').innerHTML = '<div style="text-align:center;color:var(--success);padding:2rem;font-weight:600;">✓ All items are well stocked!</div>';
        return;
    }
    let html = '';
    res.data.forEach(item => {
        html += `<div class="low-stock-item">
            <div class="ls-info">
                <div class="ls-name">${item.item_name}</div>
                <div class="ls-loc">${item.location} · Reorder at ${item.reorder_level}</div>
            </div>
            <span class="ls-qty">${item.quantity} left</span>
        </div>`;
    });
    document.getElementById('lowStockContent').innerHTML = html;
}

/* ── LOCATION MANAGEMENT ─────────────────────────── */
function openLocationManagement() { openModal('locationModal'); }

async function loadLocationInventory(location) {
    if (!location) return;
    const c = document.getElementById('locationInventoryContent');
    c.innerHTML = '<div style="text-align:center;color:var(--muted);padding:1.5rem;">Loading...</div>';
    const res = await ajax({ action: 'get_location_inventory', location });
    if (!res.success || !res.data.length) {
        c.innerHTML = '<div style="text-align:center;color:var(--muted);padding:1.5rem;">No items found in this location.</div>';
        return;
    }
    let html = '<div class="tbl-wrap"><table class="report-table"><thead><tr><th>Code</th><th>Name</th><th>Category</th><th>Qty</th><th>Unit Price</th></tr></thead><tbody>';
    res.data.forEach(i => {
        html += `<tr><td><span class="item-code">${i.item_code}</span></td><td>${i.item_name}</td><td>${i.category}</td><td><strong>${i.quantity}</strong></td><td>₱${parseFloat(i.unit_price).toFixed(2)}</td></tr>`;
    });
    html += '</tbody></table></div>';
    c.innerHTML = html;
}

/* ── REPORTS ─────────────────────────────────────── */
function openReportModal() {
    openModal('reportModal');
    loadReport('summary', document.querySelector('.report-tab'));
}

async function loadReport(type, tabEl) {
    document.querySelectorAll('.report-tab').forEach(t => t.classList.remove('active'));
    if (tabEl) tabEl.classList.add('active');
    const c = document.getElementById('reportContent');
    c.innerHTML = '<div style="text-align:center;color:var(--muted);padding:1.5rem;">Generating report...</div>';
    const res = await ajax({ action: 'generate_inventory_report', report_type: type });
    if (!res.success || !res.data.length) { c.innerHTML = '<div class="report-empty">No data available.</div>'; return; }

    let html = '<div class="tbl-wrap"><table class="report-table"><thead><tr>';
    if (type === 'summary') {
        html += '<th>Category</th><th>Items</th><th>Total Qty</th><th>Total Value</th><th>Avg Price</th>';
        html += '</tr></thead><tbody>';
        res.data.forEach(r => {
            html += `<tr><td><strong>${r.category}</strong></td><td>${r.total_items}</td><td>${r.total_quantity}</td><td>₱${parseFloat(r.total_value).toLocaleString('en-PH', {minimumFractionDigits:2})}</td><td>₱${parseFloat(r.avg_price).toFixed(2)}</td></tr>`;
        });
    } else if (type === 'low_stock') {
        html += '<th>Code</th><th>Name</th><th>Qty</th><th>Reorder At</th><th>Location</th>';
        html += '</tr></thead><tbody>';
        res.data.forEach(r => {
            html += `<tr><td><span class="item-code">${r.item_code}</span></td><td>${r.item_name}</td><td style="color:#dc2626;font-weight:700">${r.quantity}</td><td>${r.reorder_level}</td><td>${r.location}</td></tr>`;
        });
    } else {
        html += '<th>Code</th><th>Name</th><th>Category</th><th>Qty</th><th>Unit Price</th><th>Total Value</th><th>Location</th>';
        html += '</tr></thead><tbody>';
        res.data.forEach(r => {
            html += `<tr><td><span class="item-code">${r.item_code}</span></td><td>${r.item_name}</td><td>${r.category}</td><td>${r.quantity}</td><td>₱${parseFloat(r.unit_price).toFixed(2)}</td><td><strong>₱${parseFloat(r.total_value).toLocaleString('en-PH', {minimumFractionDigits:2})}</strong></td><td>${r.location}</td></tr>`;
        });
    }
    html += '</tbody></table></div>';
    c.innerHTML = html;
}

/* ── REFRESH MOVEMENTS ───────────────────────────── */
async function refreshRecentMovements() {
    const c = document.getElementById('recent-movements-container');
    c.style.opacity = '.5';
    const res = await ajax({ action: 'get_recent_movements' });
    c.style.opacity = '1';
    if (!res.success) { showToast('Failed to refresh movements', 'error'); return; }
    if (!res.data.length) { c.innerHTML = '<div style="text-align:center;color:var(--muted);padding:2rem;font-size:.84rem;">No recent movements.</div>'; return; }
    const typeMap = { IN: 'mi-in', OUT: 'mi-out', TRANSFER: 'mi-transfer' };
    const symMap  = { IN: '+', OUT: '-', TRANSFER: '⇄' };
    c.innerHTML = res.data.map(mv => {
        const d = new Date(mv.created_at);
        const df = d.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric', hour:'2-digit', minute:'2-digit' });
        return `<div class="movement-item">
            <div>
                <div class="mi-name">${mv.item_name}</div>
                <div class="mi-reason">${mv.reason || 'Stock adjustment'}</div>
                <div class="mi-date">${df}</div>
            </div>
            <span class="mi-badge ${typeMap[mv.movement_type] || 'mi-out'}">${symMap[mv.movement_type] || ''}${mv.quantity}</span>
        </div>`;
    }).join('');
    showToast('Movements refreshed', 'info');
}

/* ── PRICE FORMAT ────────────────────────────────── */
document.addEventListener('blur', function(e) {
    if (e.target.type === 'number' && e.target.name === 'unit_price') {
        const v = parseFloat(e.target.value);
        if (!isNaN(v) && v >= 0) e.target.value = v.toFixed(2);
    }
}, true);

/* ── ENTRANCE ANIMATION ──────────────────────────── */
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.stat-card').forEach((c, i) => {
        c.style.opacity = '0';
        c.style.transform = 'translateY(16px)';
        c.style.transition = 'opacity .4s ease, transform .4s ease, box-shadow .2s';
        setTimeout(() => { c.style.opacity = '1'; c.style.transform = ''; }, 60 + i * 50);
    });
});

/* ── AUTO-REFRESH MOVEMENTS EVERY 5 MIN ─────────── */
setInterval(refreshRecentMovements, 300000);
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