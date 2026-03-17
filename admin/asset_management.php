<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_role('admin');


require_once __DIR__ . '/../includes/db.php';
$pdo = db(); // Shared singleton from includes/db.php
try {
    
    $stats = [];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM assets WHERE status != 'disposed'");
    $stats['total_assets'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM assets WHERE status = 'active'");
    $stats['active_assets'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM assets WHERE next_maintenance <= CURDATE() AND status = 'active'");
    $stats['maintenance_due'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM assets WHERE status = 'maintenance'");
    $stats['in_maintenance'] = $stmt->fetch()['count'];

    $stmt = $pdo->query("SELECT SUM(purchase_cost) as total FROM assets WHERE status != 'disposed'");
    $stats['total_value'] = $stmt->fetch()['total'] ?: 0;
    
    $stmt = $pdo->query("SELECT SUM(current_value) as total FROM assets WHERE status != 'disposed'");
    $stats['current_value'] = $stmt->fetch()['total'] ?: 0;
    
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $per_page = 10;
    $offset = ($page - 1) * $per_page;
    
    $search = isset($_GET['search']) ? $_GET['search'] : '';
    $category_filter = isset($_GET['category']) ? $_GET['category'] : '';
    $status_filter = isset($_GET['status']) ? $_GET['status'] : '';
    
    $where_conditions = [];
    $params = [];
    
    if ($search) {
        $where_conditions[] = "(a.asset_name LIKE ? OR a.asset_code LIKE ? OR a.brand LIKE ? OR a.model LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    if ($category_filter) {
        $where_conditions[] = "a.category = ?";
        $params[] = $category_filter;
    }
    
    if ($status_filter) {
        $where_conditions[] = "a.status = ?";
        $params[] = $status_filter;
    }
    
    $where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    $stmt = $pdo->prepare("SELECT a.*, s.supplier_name 
                          FROM assets a 
                          LEFT JOIN suppliers s ON a.supplier_id = s.id 
                          $where_clause 
                          ORDER BY a.created_at DESC 
                          LIMIT $per_page OFFSET $offset");
    $stmt->execute($params);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM assets a $where_clause");
    $stmt->execute($params);
    $total_assets = $stmt->fetch()['total'];
    $total_pages = ceil($total_assets / $per_page);
    
    $stmt = $pdo->query("SELECT DISTINCT category FROM assets WHERE category IS NOT NULL ORDER BY category");
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $stmt = $pdo->query("SELECT ms.*, a.asset_name, a.asset_code 
                        FROM maintenance_schedules ms 
                        JOIN assets a ON ms.asset_id = a.id 
                        WHERE ms.status IN ('scheduled', 'in_progress') 
                        ORDER BY ms.scheduled_date ASC 
                        LIMIT 5");
    $upcoming_maintenance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    error_log("Asset Management error: " . $e->getMessage());
    $stats = ['total_assets' => 0, 'active_assets' => 0, 'maintenance_due' => 0, 'in_maintenance' => 0, 'total_value' => 0, 'current_value' => 0];
    $assets = [];
    $categories = [];
    $upcoming_maintenance = [];
}
?>

<!DOCTYPE html>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asset Management — BRIGHTPATH</title>
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
<style>
.condition-excellent { background:#d1fae5;color:#065f46; }
.condition-good      { background:#dbeafe;color:#1e40af; }
.condition-fair      { background:#fef3c7;color:#92400e; }
.condition-poor      { background:#fee2e2;color:#dc2626; }
.detail-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.75rem;margin-bottom:1rem; }
.detail-item { padding:.85rem 1rem;background:var(--off);border-radius:8px;border:1px solid var(--border); }
.detail-label { font-size:.72rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.35rem; }
.detail-value { font-size:.9rem;color:var(--text); }
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
                    <p>Asset Management</p>
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
<main class="main">
  <div class="page-title"><h1>Asset Management</h1><p>Track, maintain, and manage all company assets</p></div>

  <!-- Stats -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Total Assets</span><div class="stat-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['total_assets']); ?></div>
      <div class="stat-sub">All assets</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Active Assets</span><div class="stat-badge success-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['active_assets']); ?></div>
      <div class="stat-sub good">In service</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Maintenance Due</span><div class="stat-badge warn-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['maintenance_due']); ?></div>
      <div class="stat-sub warn">Requires attention</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">In Maintenance</span><div class="stat-badge warn-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['in_maintenance']); ?></div>
      <div class="stat-sub warn">Being serviced</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Total Value</span><div class="stat-badge success-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div></div>
      <div class="stat-value" style="font-size:1.35rem">₱<?php echo number_format($stats['total_value'], 2); ?></div>
      <div class="stat-sub good">Purchase cost</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Current Value</span><div class="stat-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg></div></div>
      <div class="stat-value" style="font-size:1.35rem">₱<?php echo number_format($stats['current_value'], 2); ?></div>
      <div class="stat-sub">After depreciation</div>
    </div>
  </div>

  <!-- Asset Table -->
  <div class="panel">
    <div class="panel-head"><h2><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>Asset Inventory</h2></div>

    <div style="display:flex;gap:.65rem;flex-wrap:wrap;margin-bottom:1.25rem;align-items:center">
      <input type="text" id="searchInput" class="form-control" style="flex:2;min-width:200px" placeholder="Search by name, code, brand, model…" value="<?php echo htmlspecialchars($search); ?>" onkeypress="if(event.key==='Enter') filterAssets()">
      <select id="categoryFilter" class="form-control" style="flex:1;min-width:140px" onchange="filterAssets()">
        <option value="">All Categories</option>
        <?php foreach ($categories as $c): ?><option value="<?php echo htmlspecialchars($c); ?>"<?php echo $category_filter === $c ? ' selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option><?php endforeach; ?>
      </select>
      <select id="statusFilter" class="form-control" style="flex:1;min-width:130px" onchange="filterAssets()">
        <option value="">All Status</option>
        <option value="active"<?php echo $status_filter === 'active' ? ' selected' : ''; ?>>Active</option>
        <option value="maintenance"<?php echo $status_filter === 'maintenance' ? ' selected' : ''; ?>>Maintenance</option>
        <option value="retired"<?php echo $status_filter === 'retired' ? ' selected' : ''; ?>>Retired</option>
        <option value="disposed"<?php echo $status_filter === 'disposed' ? ' selected' : ''; ?>>Disposed</option>
      </select>
      <button onclick="clearFilters()" class="btn btn-outline btn-sm">Clear</button>
      <button onclick="exportAssets()" class="btn btn-outline btn-sm">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Export CSV
      </button>
    </div>

    <div style="overflow-x:auto">
      <table class="data-table">
        <thead><tr><th>Asset Code</th><th>Asset Name</th><th>Category</th><th>Brand / Model</th><th>Location</th><th>Status</th><th>Condition</th><th>Value</th><th>Details</th></tr></thead>
        <tbody>
          <?php if (empty($assets)): ?>
          <tr><td colspan="9"><div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg><h3>No assets found</h3><p>Adjust your filters or add a new asset.</p></div></td></tr>
          <?php else: ?>
          <?php foreach ($assets as $a): ?>
          <tr>
            <td><strong style="font-family:'DM Mono',monospace;font-size:.82rem"><?php echo htmlspecialchars($a['asset_code']); ?></strong></td>
            <td><?php echo htmlspecialchars($a['asset_name']); ?></td>
            <td><?php echo htmlspecialchars($a['category']); ?></td>
            <td><?php echo htmlspecialchars(trim(($a['brand'] ?? '') . ' ' . ($a['model'] ?? '')) ?: 'N/A'); ?></td>
            <td><?php echo htmlspecialchars($a['location'] ?? 'N/A'); ?></td>
            <td><span class="badge status-<?php echo $a['status']; ?>"><?php echo ucfirst($a['status']); ?></span></td>
            <td><span class="badge condition-<?php echo $a['condition_rating']; ?>"><?php echo ucfirst($a['condition_rating']); ?></span></td>
            <td>₱<?php echo number_format($a['purchase_cost'], 2); ?></td>
            <td><button onclick="viewAssetDetails(<?php echo htmlspecialchars(json_encode($a)); ?>)" class="btn btn-primary btn-sm">View Details</button></td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
      <?php if ($page > 1): ?>
      <a href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($category_filter); ?>&status=<?php echo urlencode($status_filter); ?>" class="pagination-prev">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        Prev
      </a>
      <?php endif; ?>
      <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
        <?php if ($i === $page): ?><span class="current"><?php echo $i; ?></span>
        <?php else: ?><a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($category_filter); ?>&status=<?php echo urlencode($status_filter); ?>"><?php echo $i; ?></a><?php endif; ?>
      <?php endfor; ?>
      <?php if ($page < $total_pages): ?>
      <a href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($category_filter); ?>&status=<?php echo urlencode($status_filter); ?>" class="pagination-next">
        Next
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Upcoming Maintenance -->
  <?php if (!empty($upcoming_maintenance)): ?>
  <div class="panel">
    <div class="panel-head">
      <h2><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>Upcoming Maintenance</h2>
      <span class="badge badge-pending">View Only</span>
    </div>
    <div style="overflow-x:auto">
      <table class="data-table">
        <thead><tr><th>Asset</th><th>Type</th><th>Title</th><th>Scheduled Date</th><th>Priority</th><th>Status</th><th>Details</th></tr></thead>
        <tbody>
          <?php foreach ($upcoming_maintenance as $m): ?>
          <tr>
            <td><strong><?php echo htmlspecialchars($m['asset_code']); ?></strong><br><small style="color:var(--muted)"><?php echo htmlspecialchars($m['asset_name']); ?></small></td>
            <td><?php echo ucfirst($m['maintenance_type']); ?></td>
            <td><?php echo htmlspecialchars($m['maintenance_title']); ?></td>
            <td><?php echo date('M j, Y', strtotime($m['scheduled_date'])); ?></td>
            <td><span class="badge priority-<?php echo $m['priority']; ?>"><?php echo ucfirst($m['priority']); ?></span></td>
            <td><span class="badge status-<?php echo $m['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $m['status'])); ?></span></td>
            <td><button onclick="viewMaintenanceDetails(<?php echo htmlspecialchars(json_encode($m)); ?>)" class="btn btn-outline btn-sm">View</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</main>

<!-- Asset Details Modal -->
<div id="assetDetailsModal" class="modal">
  <div class="modal-box" style="max-width:720px">
    <div class="modal-head"><h3>Asset Details</h3><button class="modal-close" onclick="closeModal('assetDetailsModal')">&times;</button></div>
    <div class="modal-body" id="assetDetailsContent"></div>
  </div>
</div>

<!-- Maintenance Details Modal -->
<div id="maintenanceDetailsModal" class="modal">
  <div class="modal-box" style="max-width:620px">
    <div class="modal-head"><h3>Maintenance Details</h3><button class="modal-close" onclick="closeModal('maintenanceDetailsModal')">&times;</button></div>
    <div class="modal-body" id="maintenanceDetailsContent"></div>
  </div>
</div>

<script>
function openModal(id) { document.getElementById(id).style.display = 'block'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
window.onclick = e => { if (e.target.classList.contains('modal')) e.target.style.display = 'none'; }

function filterAssets() {
    const s = document.getElementById('searchInput').value;
    const c = document.getElementById('categoryFilter').value;
    const st = document.getElementById('statusFilter').value;
    window.location.href = `?search=${encodeURIComponent(s)}&category=${encodeURIComponent(c)}&status=${encodeURIComponent(st)}`;
}
function clearFilters() { window.location.href = '?'; }
function exportAssets() {
    const p = new URLSearchParams(window.location.search);
    window.open(`assets/export.php?${p.toString()}`, '_blank');
}

function viewAssetDetails(asset) {
    const pd = asset.purchase_date ? new Date(asset.purchase_date).toLocaleDateString() : 'N/A';
    const we = asset.warranty_expiry ? new Date(asset.warranty_expiry).toLocaleDateString() : 'N/A';
    document.getElementById('assetDetailsContent').innerHTML = `
        <div class="detail-grid">
            <div class="detail-item"><div class="detail-label">Asset Code</div><div class="detail-value" style="font-family:'DM Mono',monospace">${asset.asset_code || 'N/A'}</div></div>
            <div class="detail-item"><div class="detail-label">Asset Name</div><div class="detail-value">${asset.asset_name || 'N/A'}</div></div>
            <div class="detail-item"><div class="detail-label">Category</div><div class="detail-value">${asset.category || 'N/A'}</div></div>
            <div class="detail-item"><div class="detail-label">Brand</div><div class="detail-value">${asset.brand || 'N/A'}</div></div>
            <div class="detail-item"><div class="detail-label">Model</div><div class="detail-value">${asset.model || 'N/A'}</div></div>
            <div class="detail-item"><div class="detail-label">Serial Number</div><div class="detail-value" style="font-family:'DM Mono',monospace">${asset.serial_number || 'N/A'}</div></div>
            <div class="detail-item"><div class="detail-label">Location</div><div class="detail-value">${asset.location || 'N/A'}</div></div>
            <div class="detail-item"><div class="detail-label">Status</div><div class="detail-value"><span class="badge status-${asset.status}">${asset.status ? asset.status.charAt(0).toUpperCase() + asset.status.slice(1) : 'N/A'}</span></div></div>
            <div class="detail-item"><div class="detail-label">Condition</div><div class="detail-value"><span class="badge condition-${asset.condition_rating}">${asset.condition_rating ? asset.condition_rating.charAt(0).toUpperCase() + asset.condition_rating.slice(1) : 'N/A'}</span></div></div>
            <div class="detail-item"><div class="detail-label">Purchase Date</div><div class="detail-value">${pd}</div></div>
            <div class="detail-item"><div class="detail-label">Purchase Cost</div><div class="detail-value">₱${parseFloat(asset.purchase_cost || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}</div></div>
            <div class="detail-item"><div class="detail-label">Current Value</div><div class="detail-value">₱${parseFloat(asset.current_value || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}</div></div>
            <div class="detail-item"><div class="detail-label">Warranty Expiry</div><div class="detail-value">${we}</div></div>
            <div class="detail-item"><div class="detail-label">Useful Life (yrs)</div><div class="detail-value">${asset.useful_life_years || 'N/A'}</div></div>
            <div class="detail-item"><div class="detail-label">Assigned To</div><div class="detail-value">${asset.assigned_to || 'Unassigned'}</div></div>
        </div>
        ${asset.description ? `<div style="margin-top:.75rem"><div class="detail-label">Description</div><p style="font-size:.88rem;color:var(--muted);line-height:1.6;margin-top:.35rem">${asset.description}</p></div>` : ''}
    `;
    openModal('assetDetailsModal');
}

function viewMaintenanceDetails(m) {
    const sd = m.scheduled_date ? new Date(m.scheduled_date).toLocaleDateString() : 'N/A';
    document.getElementById('maintenanceDetailsContent').innerHTML = `
        <div class="detail-grid">
            <div class="detail-item"><div class="detail-label">Asset Code</div><div class="detail-value" style="font-family:'DM Mono',monospace">${m.asset_code || 'N/A'}</div></div>
            <div class="detail-item"><div class="detail-label">Asset Name</div><div class="detail-value">${m.asset_name || 'N/A'}</div></div>
            <div class="detail-item"><div class="detail-label">Type</div><div class="detail-value">${m.maintenance_type ? m.maintenance_type.charAt(0).toUpperCase() + m.maintenance_type.slice(1) : 'N/A'}</div></div>
            <div class="detail-item"><div class="detail-label">Title</div><div class="detail-value">${m.maintenance_title || 'N/A'}</div></div>
            <div class="detail-item"><div class="detail-label">Scheduled Date</div><div class="detail-value">${sd}</div></div>
            <div class="detail-item"><div class="detail-label">Priority</div><div class="detail-value"><span class="badge priority-${m.priority}">${m.priority ? m.priority.charAt(0).toUpperCase() + m.priority.slice(1) : 'N/A'}</span></div></div>
            <div class="detail-item"><div class="detail-label">Status</div><div class="detail-value"><span class="badge status-${m.status}">${m.status ? m.status.replace('_',' ').replace(/\b\w/g, l => l.toUpperCase()) : 'N/A'}</span></div></div>
            <div class="detail-item"><div class="detail-label">Estimated Cost</div><div class="detail-value">₱${parseFloat(m.estimated_cost || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}</div></div>
        </div>
        ${m.description ? `<div style="margin-top:.75rem"><div class="detail-label">Description</div><p style="font-size:.88rem;color:var(--muted);line-height:1.6;margin-top:.35rem">${m.description}</p></div>` : ''}
    `;
    openModal('maintenanceDetailsModal');
}
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