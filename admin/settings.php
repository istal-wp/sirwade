<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_role('admin');

require_once __DIR__ . '/../includes/db.php';
$pdo = db(); // Shared singleton from includes/db.php
try {
    $system_info = []; $stmt = $pdo->query("SELECT DATABASE() as db_name"); $system_info['database'] = $stmt->fetch()['db_name'];
    $stmt = $pdo->query("SELECT TABLE_NAME, TABLE_ROWS, ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) AS 'Size_MB' FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$dbname' ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC");
    $table_info = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->query("SELECT role, status, COUNT(*) as count FROM users GROUP BY role, status"); $user_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    try { $stmt = $pdo->query("SELECT table_name, action, performed_by, created_at FROM audit_logs ORDER BY created_at DESC LIMIT 10"); $recent_logs = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) { $recent_logs = []; }
    $config_settings = ['PHP Version' => phpversion(), 'Server Software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown', 'MySQL Version' => $pdo->query('SELECT VERSION()')->fetchColumn(), 'Max Upload Size' => ini_get('upload_max_filesize'), 'Max Execution Time' => ini_get('max_execution_time').' seconds', 'Memory Limit' => ini_get('memory_limit'), 'Timezone' => date_default_timezone_get(), 'Server Time' => date('Y-m-d H:i:s')];
    $modules_status = ['Users' => $pdo->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn(), 'Projects' => $pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn(), 'Suppliers' => $pdo->query("SELECT COUNT(*) FROM suppliers WHERE status='active'")->fetchColumn(), 'Assets' => $pdo->query("SELECT COUNT(*) FROM assets WHERE status='active'")->fetchColumn(), 'Inventory' => $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE status='active'")->fetchColumn(), 'Purchase Orders' => $pdo->query("SELECT COUNT(*) FROM purchase_orders")->fetchColumn()];
    $security_info = ['Active Sessions' => 1, 'Failed Logins (24h)' => 0, 'Last Backup' => 'Manual backup required', 'Password Policy' => 'Basic', 'Two-Factor Auth' => 'Not configured', 'Session Timeout' => session_get_cookie_params()['lifetime'] ? session_get_cookie_params()['lifetime'].' seconds' : 'Browser session'];
} catch(PDOException $e) { error_log("Settings error: ".$e->getMessage()); $table_info=[]; $user_stats=[]; $recent_logs=[]; $modules_status=[]; $config_settings=[]; $security_info=[]; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings — BRIGHTPATH</title>
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
        .info-banner{display:flex;align-items:center;gap:10px;padding:.85rem 1.1rem;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;margin-bottom:1.75rem;font-size:.87rem;color:#1d4ed8}
        .info-banner svg{width:16px;height:16px;stroke:currentColor;flex-shrink:0}
        .two-col{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem}
        .card{background:var(--white);border:1px solid var(--border);border-radius:12px;overflow:hidden}
        .card-head{display:flex;align-items:center;gap:10px;padding:1.1rem 1.4rem;border-bottom:1px solid var(--border)}
        .card-icon{width:34px;height:34px;background:linear-gradient(135deg,var(--navy),var(--steel));border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .card-icon svg{width:16px;height:16px;stroke:rgba(255,255,255,.9)}
        .card-head h3{font-size:.92rem;font-weight:600;color:var(--navy)}
        .setting-row{display:flex;justify-content:space-between;align-items:center;padding:.75rem 1.4rem;border-bottom:1px solid var(--off)}
        .setting-row:last-child{border-bottom:none}
        .setting-key{font-size:.83rem;color:var(--muted);font-weight:500}
        .setting-val{font-size:.83rem;color:var(--text);font-weight:500;font-family:'DM Mono',monospace;text-align:right}
        .val-ok{color:var(--success)}
        .val-warn{color:var(--warn)}
        .val-err{color:var(--error)}
        .modules-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;padding:1.1rem 1.4rem}
        .mod-card{background:var(--off);border:1px solid var(--border);border-radius:9px;padding:1rem;text-align:center}
        .mod-num{font-size:1.6rem;font-weight:600;color:var(--navy);line-height:1}
        .mod-label{font-size:.75rem;color:var(--muted);margin-top:.3rem;text-transform:uppercase;letter-spacing:.05em}
        table{width:100%;border-collapse:collapse}
        thead th{padding:.8rem 1.1rem;text-align:left;font-size:.75rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;background:var(--off);border-bottom:1px solid var(--border);white-space:nowrap}
        tbody td{padding:.85rem 1.1rem;border-bottom:1px solid var(--off);font-size:.84rem;font-family:'DM Mono',monospace;color:var(--text)}
        tbody tr:last-child td{border-bottom:none}
        tbody tr:hover td{background:rgba(61,127,255,.03)}
        .badge{display:inline-block;padding:.25rem .65rem;border-radius:99px;font-size:.74rem;font-weight:600;font-family:'DM Sans',sans-serif}
        .badge-ok{background:rgba(21,128,61,.1);color:var(--success)}
        .badge-warn{background:rgba(180,83,9,.1);color:var(--warn)}
        .badge-err{background:rgba(197,48,48,.1);color:var(--error)}
        @media(max-width:768px){.two-col{grid-template-columns:1fr}.modules-grid{grid-template-columns:repeat(2,1fr)}.main{padding:1.25rem 1rem 2rem}}
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
                    <p>System Settings</p>
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
        <h1>System Configuration &amp; Settings</h1>
        <p>Monitor system status, configuration, and performance metrics</p>
    </div>

    <div class="info-banner">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        This is a read-only system settings view. Configuration changes require direct server-level modifications.
    </div>

    <div class="two-col">
        <div class="card">
            <div class="card-head">
                <div class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg></div>
                <h3>System Information</h3>
            </div>
            <?php foreach ($config_settings as $key => $value): ?>
            <div class="setting-row"><span class="setting-key"><?php echo htmlspecialchars($key); ?></span><span class="setting-val"><?php echo htmlspecialchars($value); ?></span></div>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <div class="card-head">
                <div class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg></div>
                <h3>Database Configuration</h3>
            </div>
            <div class="setting-row"><span class="setting-key">Database Name</span><span class="setting-val"><?php echo htmlspecialchars($system_info['database'] ?? 'N/A'); ?></span></div>
            <div class="setting-row"><span class="setting-key">Total Tables</span><span class="setting-val"><?php echo count($table_info); ?></span></div>
            <div class="setting-row"><span class="setting-key">Database Size</span><span class="setting-val"><?php echo array_sum(array_column($table_info, 'Size_MB')); ?> MB</span></div>
            <div class="setting-row"><span class="setting-key">Connection Status</span><span class="setting-val val-ok">Connected</span></div>
            <?php foreach ($security_info as $key => $value): ?>
            <div class="setting-row"><span class="setting-key"><?php echo htmlspecialchars($key); ?></span><span class="setting-val <?php echo strpos($value, 'Not') !== false || strpos($value, 'required') !== false ? 'val-warn' : ''; ?>"><?php echo htmlspecialchars($value); ?></span></div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Active Modules -->
    <div class="card" style="margin-bottom:1.25rem">
        <div class="card-head">
            <div class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></div>
            <h3>Active Modules</h3>
        </div>
        <div class="modules-grid">
            <?php foreach ($modules_status as $module => $count): ?>
            <div class="mod-card"><div class="mod-num"><?php echo number_format($count); ?></div><div class="mod-label"><?php echo htmlspecialchars($module); ?></div></div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- DB Tables -->
    <div class="card" style="margin-bottom:1.25rem">
        <div class="card-head">
            <div class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg></div>
            <h3>Database Tables Overview</h3>
        </div>
        <table>
            <thead><tr><th>Table Name</th><th>Records</th><th>Size (MB)</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($table_info as $table): ?>
                <tr>
                    <td><?php echo htmlspecialchars($table['TABLE_NAME']); ?></td>
                    <td><?php echo number_format($table['TABLE_ROWS']); ?></td>
                    <td><?php echo htmlspecialchars($table['Size_MB']); ?></td>
                    <td><span class="badge badge-ok">Active</span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- User Stats -->
    <div class="card" style="margin-bottom:1.25rem">
        <div class="card-head">
            <div class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg></div>
            <h3>User Account Statistics</h3>
        </div>
        <table>
            <thead><tr><th>Role</th><th>Status</th><th>Count</th></tr></thead>
            <tbody>
                <?php foreach ($user_stats as $stat): ?>
                <tr>
                    <td><?php echo ucfirst(htmlspecialchars($stat['role'])); ?></td>
                    <td><span class="badge <?php echo $stat['status']==='active'?'badge-ok':'badge-warn'; ?>"><?php echo ucfirst($stat['status']); ?></span></td>
                    <td><?php echo number_format($stat['count']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (!empty($recent_logs)): ?>
    <div class="card" style="margin-bottom:1.25rem">
        <div class="card-head">
            <div class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/></svg></div>
            <h3>Recent System Activity</h3>
        </div>
        <table>
            <thead><tr><th>Table</th><th>Action</th><th>User</th><th>Date/Time</th></tr></thead>
            <tbody>
                <?php foreach ($recent_logs as $log): ?>
                <tr>
                    <td><?php echo htmlspecialchars($log['table_name']); ?></td>
                    <td><span class="badge <?php echo $log['action']==='DELETE'?'badge-err':'badge-ok'; ?>"><?php echo htmlspecialchars($log['action']); ?></span></td>
                    <td><?php echo htmlspecialchars($log['performed_by']); ?></td>
                    <td><?php echo date('M j, Y H:i', strtotime($log['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Health -->
    <div class="card">
        <div class="card-head">
            <div class="card-icon"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
            <h3>System Health Status</h3>
        </div>
        <div class="setting-row"><span class="setting-key">Overall System Status</span><span class="setting-val val-ok">Operational</span></div>
        <div class="setting-row"><span class="setting-key">Database Connection</span><span class="setting-val val-ok">Connected</span></div>
        <div class="setting-row"><span class="setting-key">File Permissions</span><span class="setting-val val-ok">Configured</span></div>
        <div class="setting-row"><span class="setting-key">Backup Status</span><span class="setting-val val-warn">Manual backup recommended</span></div>
        <div class="setting-row"><span class="setting-key">Error Logs</span><span class="setting-val">Check server logs for details</span></div>
    </div>
</main>
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