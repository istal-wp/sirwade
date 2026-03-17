<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_role('admin');

require_once __DIR__ . '/../includes/db.php';
$pdo = db(); // Shared singleton from includes/db.php
try {
    $analytics = [];
    $stmt = $pdo->query("SELECT role, COUNT(*) as count FROM users WHERE status='active' GROUP BY role"); $analytics['users_by_role'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->query("SELECT status, COUNT(*) as count, AVG(progress_percentage) as avg_progress, SUM(budget) as total_budget, SUM(actual_cost) as total_spent FROM projects GROUP BY status"); $analytics['project_stats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->query("SELECT s.supplier_name, s.rating, COUNT(po.id) as total_orders, SUM(po.total_amount) as total_value, s.status FROM suppliers s LEFT JOIN purchase_orders po ON s.id=po.supplier_id WHERE s.status='active' GROUP BY s.id ORDER BY total_value DESC LIMIT 10"); $analytics['top_suppliers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->query("SELECT category, COUNT(*) as item_count, SUM(quantity*unit_price) as category_value, COUNT(CASE WHEN quantity<=reorder_level THEN 1 END) as low_stock_items FROM inventory_items WHERE status='active' GROUP BY category ORDER BY category_value DESC"); $analytics['inventory_by_category'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->query("SELECT status, COUNT(*) as count, SUM(total_amount) as total_value, AVG(total_amount) as avg_value FROM purchase_orders GROUP BY status"); $analytics['po_analysis'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->query("SELECT COUNT(DISTINCT u.id) as total_users, COUNT(DISTINCT p.id) as total_projects, COUNT(DISTINCT s.id) as total_suppliers, COUNT(DISTINCT ii.id) as total_inventory_items, COUNT(DISTINCT a.id) as total_assets, COALESCE(SUM(p.budget),0) as total_project_budget, COALESCE(SUM(ii.quantity*ii.unit_price),0) as total_inventory_value, COALESCE(SUM(a.current_value),0) as total_asset_value FROM users u LEFT JOIN projects p ON 1=1 LEFT JOIN suppliers s ON s.status='active' LEFT JOIN inventory_items ii ON ii.status='active' LEFT JOIN assets a ON a.status='active' WHERE u.status='active'"); $analytics['system_summary'] = $stmt->fetch(PDO::FETCH_ASSOC);
} catch(PDOException $e) { error_log("Reports error: ".$e->getMessage()); $analytics = ['users_by_role'=>[],'project_stats'=>[],'top_suppliers'=>[],'inventory_by_category'=>[],'po_analysis'=>[],'system_summary'=>[]]; }
$summary = $analytics['system_summary'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports &amp; Analytics — BRIGHTPATH</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
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
        
        *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
        :root{--navy:#0f1f3d;--blue:#1a3a6e;--accent:#3d7fff;--steel:#2c4a8a;--white:#fff;--off:#f4f6fb;--border:#dde3ef;--text:#1a2540;--muted:#6b7a99;--success:#15803d;--warn:#b45309;--error:#c53030}
        body{font-family:'DM Sans',sans-serif;background:var(--off);min-height:100vh;color:var(--text)}
        .header{background:var(--white);border-bottom:1px solid var(--border);padding:0 2rem;position:sticky;top:0;z-index:100;box-shadow:0 1px 8px rgba(15,31,61,.07)}
        .header-inner{display:flex;justify-content:space-between;align-items:center;max-width:1400px;margin:0 auto;height:64px}
        .brand{display:flex;align-items:center;gap:12px;text-decoration:none}
        .brand-mark{width:38px;height:38px;background:linear-gradient(135deg,var(--navy),var(--steel));border-radius:9px;display:flex;align-items:center;justify-content:center}
        .brand-mark svg{width:20px;height:20px;stroke:rgba(255,255,255,.9)}
        .brand-text h1{font-size:1rem;font-weight:600;color:var(--navy);letter-spacing:.05em}
        .brand-text p{font-size:.7rem;color:var(--muted);letter-spacing:.08em;text-transform:uppercase;font-family:'DM Mono',monospace}
        .header-right{display:flex;align-items:center;gap:1.25rem}
        
        .user-avatar{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--navy),var(--steel));display:flex;align-items:center;justify-content:center}
        .user-avatar svg{width:14px;height:14px;stroke:rgba(255,255,255,.85)}
        .user-name{font-size:.83rem;font-weight:500;color:var(--text)}
        .btn-back{display:flex;align-items:center;gap:7px;padding:.5rem 1rem;background:none;border:1.5px solid var(--border);border-radius:8px;font-size:.82rem;font-weight:500;font-family:'DM Sans',sans-serif;color:var(--muted);cursor:pointer;text-decoration:none;transition:border-color .2s,color .2s}
        .btn-back svg{width:14px;height:14px;stroke:currentColor}
        .btn-back:hover{border-color:var(--accent);color:var(--accent)}
        .main{max-width:1400px;margin:0 auto;padding:2rem 2rem 3rem}
        .page-title{margin-bottom:1.75rem}
        .page-title h1{font-size:1.6rem;font-weight:600;color:var(--navy);margin-bottom:.25rem}
        .page-title p{font-size:.88rem;color:var(--muted)}
        .section-label{font-size:.78rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:.85rem}
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin-bottom:2rem}
        .stat-card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:1.25rem 1.4rem;transition:box-shadow .2s,transform .15s}
        .stat-card:hover{box-shadow:0 4px 18px rgba(15,31,61,.08);transform:translateY(-2px)}
        .stat-top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:.75rem}
        .stat-label{font-size:.78rem;font-weight:500;color:var(--muted);text-transform:uppercase;letter-spacing:.06em}
        .stat-badge{width:36px;height:36px;background:rgba(61,127,255,.09);border-radius:9px;display:flex;align-items:center;justify-content:center}
        .stat-badge svg{width:17px;height:17px;stroke:var(--accent)}
        .stat-value{font-size:2rem;font-weight:600;color:var(--navy);line-height:1;margin-bottom:.4rem}
        .stat-sub{font-size:.78rem;color:var(--muted)}
        .charts-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:2rem}
        .chart-card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:1.4rem}
        .chart-title{font-size:.92rem;font-weight:600;color:var(--navy);margin-bottom:1.25rem}
        .chart-wrap{position:relative;height:240px}
        table{width:100%;border-collapse:collapse}
        thead th{padding:.8rem 1.1rem;text-align:left;font-size:.75rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;background:var(--off);border-bottom:1px solid var(--border);white-space:nowrap}
        tbody td{padding:.85rem 1.1rem;border-bottom:1px solid var(--off);font-size:.84rem;color:var(--text)}
        tbody tr:last-child td{border-bottom:none}
        tbody tr:hover td{background:rgba(61,127,255,.03)}
        .badge{display:inline-block;padding:.25rem .65rem;border-radius:99px;font-size:.74rem;font-weight:600}
        .badge-ok{background:rgba(21,128,61,.1);color:var(--success)}
        .badge-warn{background:rgba(180,83,9,.1);color:var(--warn)}
        .badge-pend{background:rgba(99,102,241,.1);color:#6366f1}
        .card{background:var(--white);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:1.25rem}
        .card-head{display:flex;align-items:center;gap:10px;padding:1.1rem 1.4rem;border-bottom:1px solid var(--border)}
        .card-icon{width:34px;height:34px;background:linear-gradient(135deg,var(--navy),var(--steel));border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .card-icon svg{width:16px;height:16px;stroke:rgba(255,255,255,.9)}
        .card-head h3{font-size:.92rem;font-weight:600;color:var(--navy)}
        .num-col{font-family:'DM Mono',monospace}
        @media(max-width:900px){.charts-grid{grid-template-columns:1fr}}
        @media(max-width:768px){.main{padding:1.25rem 1rem 2rem}.stats-grid{grid-template-columns:1fr 1fr}}
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
                    <p>Reports & Analytics</p>
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
    <div class="page-title">
        <h1>Reports &amp; Analytics</h1>
        <p>Comprehensive system performance metrics and data insights</p>
    </div>

    <!-- KPI Summary -->
    <div class="section-label">Key Performance Indicators</div>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-top"><span class="stat-label">Total Users</span><div class="stat-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/></svg></div></div>
            <div class="stat-value"><?php echo number_format($summary['total_users'] ?? 0); ?></div>
            <div class="stat-sub">Active accounts</div>
        </div>
        <div class="stat-card">
            <div class="stat-top"><span class="stat-label">Projects</span><div class="stat-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg></div></div>
            <div class="stat-value"><?php echo number_format($summary['total_projects'] ?? 0); ?></div>
            <div class="stat-sub">Total projects</div>
        </div>
        <div class="stat-card">
            <div class="stat-top"><span class="stat-label">Suppliers</span><div class="stat-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg></div></div>
            <div class="stat-value"><?php echo number_format($summary['total_suppliers'] ?? 0); ?></div>
            <div class="stat-sub">Active suppliers</div>
        </div>
        <div class="stat-card">
            <div class="stat-top"><span class="stat-label">Inventory Value</span><div class="stat-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div></div>
            <div class="stat-value">₱<?php echo number_format(($summary['total_inventory_value'] ?? 0)/1000, 1); ?>K</div>
            <div class="stat-sub">Total stock value</div>
        </div>
        <div class="stat-card">
            <div class="stat-top"><span class="stat-label">Asset Value</span><div class="stat-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/></svg></div></div>
            <div class="stat-value">₱<?php echo number_format(($summary['total_asset_value'] ?? 0)/1000, 1); ?>K</div>
            <div class="stat-sub">Current asset value</div>
        </div>
        <div class="stat-card">
            <div class="stat-top"><span class="stat-label">Budget</span><div class="stat-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 1 18"/></svg></div></div>
            <div class="stat-value">₱<?php echo number_format(($summary['total_project_budget'] ?? 0)/1000, 1); ?>K</div>
            <div class="stat-sub">Project budget total</div>
        </div>
    </div>

    <!-- Charts -->
    <div class="section-label">Visual Analytics</div>
    <div class="charts-grid">
        <div class="chart-card">
            <div class="chart-title">User Distribution by Role</div>
            <div class="chart-wrap"><canvas id="usersChart"></canvas></div>
        </div>
        <div class="chart-card">
            <div class="chart-title">Project Status Overview</div>
            <div class="chart-wrap"><canvas id="projectsChart"></canvas></div>
        </div>
    </div>

    <!-- Suppliers Table -->
    <div class="card">
        <div class="card-head">
            <div class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></div>
            <h3>Top Supplier Performance</h3>
        </div>
        <table>
            <thead><tr><th>Supplier</th><th>Rating</th><th>Orders</th><th>Total Value</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($analytics['top_suppliers'] as $s): ?>
                <tr>
                    <td><?php echo htmlspecialchars($s['supplier_name']); ?></td>
                    <td class="num-col"><?php echo $s['rating'] ? number_format($s['rating'],1).'/5' : '—'; ?></td>
                    <td class="num-col"><?php echo number_format($s['total_orders']); ?></td>
                    <td class="num-col">₱<?php echo number_format($s['total_value'] ?? 0, 2); ?></td>
                    <td><span class="badge badge-ok"><?php echo ucfirst($s['status']); ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($analytics['top_suppliers'])): ?><tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--muted)">No supplier data available.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Inventory by Category -->
    <div class="card">
        <div class="card-head">
            <div class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg></div>
            <h3>Inventory by Category</h3>
        </div>
        <table>
            <thead><tr><th>Category</th><th>Items</th><th>Total Value</th><th>Low Stock</th></tr></thead>
            <tbody>
                <?php foreach ($analytics['inventory_by_category'] as $inv): ?>
                <tr>
                    <td><?php echo htmlspecialchars($inv['category'] ?? 'Uncategorized'); ?></td>
                    <td class="num-col"><?php echo number_format($inv['item_count']); ?></td>
                    <td class="num-col">₱<?php echo number_format($inv['category_value'] ?? 0, 2); ?></td>
                    <td><?php if ($inv['low_stock_items'] > 0): ?><span class="badge badge-warn"><?php echo $inv['low_stock_items']; ?> items</span><?php else: ?><span class="badge badge-ok">OK</span><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($analytics['inventory_by_category'])): ?><tr><td colspan="4" style="text-align:center;padding:2rem;color:var(--muted)">No inventory data available.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Purchase Orders -->
    <div class="card">
        <div class="card-head">
            <div class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
            <h3>Purchase Order Analysis</h3>
        </div>
        <table>
            <thead><tr><th>Status</th><th>Count</th><th>Total Value</th><th>Avg Value</th></tr></thead>
            <tbody>
                <?php foreach ($analytics['po_analysis'] as $po): ?>
                <tr>
                    <td><span class="badge <?php echo $po['status']==='completed'?'badge-ok':($po['status']==='cancelled'?'badge-warn':'badge-pend'); ?>"><?php echo ucfirst($po['status']); ?></span></td>
                    <td class="num-col"><?php echo number_format($po['count']); ?></td>
                    <td class="num-col">₱<?php echo number_format($po['total_value'] ?? 0, 2); ?></td>
                    <td class="num-col">₱<?php echo number_format($po['avg_value'] ?? 0, 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($analytics['po_analysis'])): ?><tr><td colspan="4" style="text-align:center;padding:2rem;color:var(--muted)">No purchase order data available.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<script>
const navy = '#0f1f3d', steel = '#2c4a8a', accent = '#3d7fff';
const colors = ['#3d7fff','#0f1f3d','#2c4a8a','#6b7a99','#15803d','#b45309'];

const usersData = <?php echo json_encode($analytics['users_by_role']); ?>;
if (usersData.length) {
    new Chart(document.getElementById('usersChart'), {
        type: 'doughnut',
        data: { labels: usersData.map(d => d.role.charAt(0).toUpperCase()+d.role.slice(1)), datasets: [{ data: usersData.map(d => d.count), backgroundColor: colors, borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { family: "'DM Sans'" }, padding: 16 } } } }
    });
}

const projData = <?php echo json_encode($analytics['project_stats']); ?>;
if (projData.length) {
    new Chart(document.getElementById('projectsChart'), {
        type: 'bar',
        data: { labels: projData.map(d => d.status.charAt(0).toUpperCase()+d.status.slice(1)), datasets: [{ label: 'Projects', data: projData.map(d => d.count), backgroundColor: colors, borderRadius: 6, borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: '#dde3ef' }, ticks: { font: { family: "'DM Mono'" } } }, x: { grid: { display: false }, ticks: { font: { family: "'DM Sans'" } } } } }
    });
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