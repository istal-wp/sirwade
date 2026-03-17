<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_role('admin');


require_once __DIR__ . '/../includes/db.php';
$pdo = db(); // Shared singleton from includes/db.php
try {

    $stats = [];
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE status = 'active'");
    $stats['users'] = $stmt->fetch()['count'];
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM projects");
    $stats['projects'] = $stmt->fetch()['count'];
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM projects WHERE status = 'active'");
    $stats['active_projects'] = $stmt->fetch()['count'];
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM suppliers WHERE status = 'active'");
    $stats['suppliers'] = $stmt->fetch()['count'];
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM inventory_items WHERE status = 'active'");
    $stats['inventory_items'] = $stmt->fetch()['count'];
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM inventory_items WHERE quantity <= reorder_level AND status = 'active'");
    $stats['low_stock'] = $stmt->fetch()['count'];
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM assets WHERE status = 'active'");
    $stats['assets'] = $stmt->fetch()['count'];
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM purchase_orders WHERE status IN ('draft', 'sent')");
    $stats['pending_pos'] = $stmt->fetch()['count'];

    $recent_activities = [];
    $stmt = $pdo->query("SELECT 'Project' as type, project_name as name, created_at, status FROM projects ORDER BY created_at DESC LIMIT 3");
    $recent_activities = array_merge($recent_activities, $stmt->fetchAll(PDO::FETCH_ASSOC));
    $stmt = $pdo->query("SELECT 'User' as type, CONCAT(first_name, ' ', last_name) as name, created_at, status FROM users ORDER BY created_at DESC LIMIT 2");
    $recent_activities = array_merge($recent_activities, $stmt->fetchAll(PDO::FETCH_ASSOC));
    usort($recent_activities, function($a, $b) { return strtotime($b['created_at']) - strtotime($a['created_at']); });
    $recent_activities = array_slice($recent_activities, 0, 5);

} catch(PDOException $e) {
    error_log("Dashboard error: " . $e->getMessage());
    $stats = ['users'=>0,'projects'=>0,'active_projects'=>0,'suppliers'=>0,'inventory_items'=>0,'low_stock'=>0,'assets'=>0,'pending_pos'=>0];
    $recent_activities = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — BRIGHTPATH</title>
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
            --navy:   #0f1f3d;
            --blue:   #1a3a6e;
            --accent: #3d7fff;
            --steel:  #2c4a8a;
            --white:  #ffffff;
            --off:    #f4f6fb;
            --border: #dde3ef;
            --text:   #1a2540;
            --muted:  #6b7a99;
            --success:#15803d;
            --warn:   #b45309;
            --error:  #c53030;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--off);
            min-height: 100vh;
            color: var(--text);
        }

        /* ── HEADER ──────────────────────────────── */
        .header {
            background: var(--white);
            border-bottom: 1px solid var(--border);
            padding: 0 2rem;
            position: sticky; top: 0; z-index: 100;
            box-shadow: 0 1px 8px rgba(15,31,61,.07);
        }

        .header-inner {
            display: flex; justify-content: space-between; align-items: center;
            max-width: 1400px; margin: 0 auto; height: 64px;
        }

        .brand {
            display: flex; align-items: center; gap: 12px;
        }

        .brand-mark {
            width: 38px; height: 38px;
            background: linear-gradient(135deg, var(--navy), var(--steel));
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
        }

        .brand-mark svg { width: 20px; height: 20px; stroke: rgba(255,255,255,.9); }

        .brand-text h1 { font-size: 1rem; font-weight: 600; color: var(--navy); letter-spacing: 0.05em; }
        .brand-text p  { font-size: 0.7rem; color: var(--muted); letter-spacing: 0.08em; text-transform: uppercase; font-family: 'DM Mono', monospace; }

        .header-right {
            display: flex; align-items: center; gap: 1.25rem;
        }

        

        .user-avatar {
            width: 28px; height: 28px; border-radius: 50%;
            background: linear-gradient(135deg, var(--navy), var(--steel));
            display: flex; align-items: center; justify-content: center;
        }

        .user-avatar svg { width: 14px; height: 14px; stroke: rgba(255,255,255,.85); }
        .user-name { font-size: 0.83rem; font-weight: 500; color: var(--text); }

        .btn-logout {
            display: flex; align-items: center; gap: 7px;
            padding: 0.5rem 1rem;
            background: none; border: 1.5px solid var(--border); border-radius: 8px;
            font-size: 0.82rem; font-weight: 500; font-family: 'DM Sans', sans-serif;
            color: var(--muted); cursor: pointer;
            transition: border-color .2s, color .2s;
            text-decoration: none;
        }

        .btn-logout svg { width: 14px; height: 14px; stroke: currentColor; }
        .btn-logout:hover { border-color: var(--error); color: var(--error); }

        /* ── MAIN ──────────────────────────────────── */
        .main {
            max-width: 1400px; margin: 0 auto;
            padding: 2rem 2rem 3rem;
        }

        .page-title { margin-bottom: 1.75rem; }
        .page-title h1 { font-size: 1.6rem; font-weight: 600; color: var(--navy); margin-bottom: 0.25rem; }
        .page-title p  { font-size: 0.88rem; color: var(--muted); }

        /* ── ALERT ──────────────────────────────────── */
        .banner {
            display: flex; align-items: center; gap: 10px;
            padding: 0.85rem 1.1rem;
            background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px;
            margin-bottom: 1.5rem;
            font-size: 0.87rem; color: var(--warn);
        }
        .banner svg { width: 16px; height: 16px; stroke: currentColor; flex-shrink: 0; }

        /* ── QUICK ACTIONS ──────────────────────────── */
        .quick-actions {
            display: flex; gap: 0.75rem; flex-wrap: wrap;
            margin-bottom: 2rem;
        }

        .qa-btn {
            display: flex; align-items: center; gap: 8px;
            padding: 0.6rem 1.1rem;
            background: var(--white); border: 1.5px solid var(--border); border-radius: 9px;
            font-size: 0.84rem; font-weight: 500; font-family: 'DM Sans', sans-serif;
            color: var(--text); cursor: pointer; text-decoration: none;
            transition: border-color .2s, box-shadow .2s, color .2s;
        }
        .qa-btn svg { width: 15px; height: 15px; stroke: var(--accent); flex-shrink: 0; }
        .qa-btn:hover { border-color: var(--accent); color: var(--accent); box-shadow: 0 2px 12px rgba(61,127,255,.12); }

        /* ── STATS GRID ─────────────────────────────── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white); border: 1px solid var(--border); border-radius: 12px;
            padding: 1.25rem 1.4rem;
            transition: box-shadow .2s, transform .15s;
        }
        .stat-card:hover { box-shadow: 0 4px 18px rgba(15,31,61,.08); transform: translateY(-2px); }

        .stat-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem; }
        .stat-label { font-size: 0.78rem; font-weight: 500; color: var(--muted); text-transform: uppercase; letter-spacing: 0.06em; }
        .stat-badge {
            width: 36px; height: 36px;
            background: rgba(61,127,255,.09); border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
        }
        .stat-badge svg { width: 17px; height: 17px; stroke: var(--accent); }

        .stat-value { font-size: 2rem; font-weight: 600; color: var(--navy); line-height: 1; margin-bottom: 0.4rem; }

        .stat-sub {
            display: flex; align-items: center; gap: 5px;
            font-size: 0.78rem; color: var(--muted);
        }
        .stat-sub svg { width: 12px; height: 12px; stroke: currentColor; }
        .stat-sub.warn { color: var(--warn); }
        .stat-sub.good { color: var(--success); }
        .stat-sub.pend { color: #6366f1; }

        /* ── NAV CARDS ──────────────────────────────── */
        .section-label {
            font-size: 0.78rem; font-weight: 600; color: var(--muted);
            text-transform: uppercase; letter-spacing: 0.1em;
            margin-bottom: 0.85rem;
        }

        .nav-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .nav-card {
            display: block;
            background: var(--white); border: 1px solid var(--border); border-radius: 12px;
            padding: 1.25rem 1.4rem;
            text-decoration: none; color: inherit;
            transition: box-shadow .2s, transform .15s, border-color .2s;
        }
        .nav-card:hover { box-shadow: 0 6px 22px rgba(15,31,61,.1); transform: translateY(-2px); border-color: rgba(61,127,255,.3); }

        .nav-card-top {
            display: flex; align-items: center; gap: 12px; margin-bottom: 0.7rem;
        }

        .nav-icon {
            width: 38px; height: 38px;
            background: linear-gradient(135deg, var(--navy), var(--steel));
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .nav-icon svg { width: 18px; height: 18px; stroke: rgba(255,255,255,.9); }

        .nav-card h3 { font-size: 0.95rem; font-weight: 600; color: var(--navy); }
        .nav-card p  { font-size: 0.82rem; color: var(--muted); line-height: 1.55; }

        /* ── ACTIVITY ───────────────────────────────── */
        .activity-panel {
            background: var(--white); border: 1px solid var(--border); border-radius: 12px;
            padding: 1.5rem 1.6rem;
        }

        .activity-head {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 1.25rem; padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }
        .activity-head h2 { font-size: 1rem; font-weight: 600; color: var(--navy); }
        .activity-head a  { font-size: 0.82rem; font-weight: 500; color: var(--accent); text-decoration: none; }

        .activity-row {
            display: flex; align-items: center; gap: 12px;
            padding: 0.85rem 0;
            border-bottom: 1px solid var(--off);
        }
        .activity-row:last-child { border-bottom: none; }

        .act-icon {
            width: 36px; height: 36px; border-radius: 9px;
            background: linear-gradient(135deg, var(--navy), var(--steel));
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .act-icon svg { width: 16px; height: 16px; stroke: rgba(255,255,255,.9); }

        .act-body h4 { font-size: 0.88rem; font-weight: 500; color: var(--text); margin-bottom: 0.15rem; }
        .act-body p  { font-size: 0.78rem; color: var(--muted); }

        .act-time {
            margin-left: auto; font-size: 0.75rem; color: var(--muted);
            font-family: 'DM Mono', monospace; white-space: nowrap;
        }

        .empty-state {
            text-align: center; padding: 2rem; color: var(--muted); font-size: 0.88rem;
        }

        @media (max-width: 768px) {
            .header-inner { flex-wrap: wrap; height: auto; padding: 0.75rem 0; gap: 0.75rem; }
            .main { padding: 1.25rem 1rem 2rem; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .nav-grid { grid-template-columns: 1fr; }
            .quick-actions { gap: 0.5rem; }
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
                    <p>Admin Dashboard</p>
                </div>
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
<!-- ═══ HEADER ═══════════════════════════════════════════════════════ -->
<!-- ═══ MAIN ══════════════════════════════════════════════════════════ -->
<main class="main">

    <div class="page-title">
        <h1>Dashboard Overview</h1>
        <p>Complete system management and analytics</p>
    </div>

    <?php if ($stats['low_stock'] > 0): ?>
    <div class="banner">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        <strong>Low Stock Alert:</strong>&nbsp;<?php echo $stats['low_stock']; ?> items are running low and need restocking.
    </div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div class="quick-actions">
        <a href="manage_users.php" class="qa-btn" title="Admin: User approval only">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>
            </svg>
            Add User
        </a>
        <a href="../staff/projects.php" class="qa-btn" title="Go to Staff Portal">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="14" x2="8.01" y2="14"/><line x1="12" y1="14" x2="12.01" y2="14"/>
            </svg>
            New Project
        </a>
        <a href="../staff/inventory.php" class="qa-btn" title="Go to Staff Portal">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/>
            </svg>
            Add Inventory
        </a>
        <a href="../staff/suppliers.php" class="qa-btn" title="Go to Staff Portal">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>
            </svg>
            Add Supplier
        </a>
    </div>

    <!-- Stats -->
    <div class="stats-grid">

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Total Users</span>
                <div class="stat-badge">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($stats['users']); ?></div>
            <div class="stat-sub good">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                Active accounts
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Total Projects</span>
                <div class="stat-badge">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($stats['projects']); ?></div>
            <div class="stat-sub good">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <?php echo $stats['active_projects']; ?> active
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Suppliers</span>
                <div class="stat-badge">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($stats['suppliers']); ?></div>
            <div class="stat-sub good">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>
                Trusted partners
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Inventory Items</span>
                <div class="stat-badge">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($stats['inventory_items']); ?></div>
            <div class="stat-sub <?php echo $stats['low_stock'] > 0 ? 'warn' : 'good'; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                <?php echo $stats['low_stock']; ?> low stock
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Assets</span>
                <div class="stat-badge">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 010 14.14M4.93 4.93a10 10 0 000 14.14"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($stats['assets']); ?></div>
            <div class="stat-sub good">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
                Under management
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Pending POs</span>
                <div class="stat-badge">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($stats['pending_pos']); ?></div>
            <div class="stat-sub pend">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Awaiting approval
            </div>
        </div>

    </div>

    <!-- Module Navigation -->
    <div class="section-label">System Modules</div>
    <div class="nav-grid">

        <a href="manage_users.php" class="nav-card">
            <div class="nav-card-top">
                <div class="nav-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>
                    </svg>
                </div>
                <h3>User Management</h3>
            </div>
            <p>Manage user accounts, roles, permissions, and access control. Add, edit, or deactivate user accounts.</p>
        </a>

        <a href="project_management.php" class="nav-card">
            <div class="nav-card-top">
                <div class="nav-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                </div>
                <h3>Project Management</h3>
            </div>
            <p>Oversee all projects, milestones, tasks, and resource allocation. Track progress and deadlines.</p>
        </a>

        <a href="inventory_management.php" class="nav-card">
            <div class="nav-card-top">
                <div class="nav-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>
                    </svg>
                </div>
                <h3>Inventory Management</h3>
            </div>
            <p>Control stock levels, track movements, manage locations, and set reorder alerts for all inventory items.</p>
        </a>

        <a href="supplier_management.php" class="nav-card">
            <div class="nav-card-top">
                <div class="nav-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>
                    </svg>
                </div>
                <h3>Supplier Management</h3>
            </div>
            <p>Manage supplier information, evaluate performance, handle quotations and purchase orders.</p>
        </a>

        <a href="asset_management.php" class="nav-card">
            <div class="nav-card-top">
                <div class="nav-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/>
                    </svg>
                </div>
                <h3>Asset Management</h3>
            </div>
            <p>Track company assets, schedule maintenance, monitor depreciation, and manage asset lifecycle.</p>
        </a>

        <a href="document_management.php" class="nav-card">
            <div class="nav-card-top">
                <div class="nav-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/>
                    </svg>
                </div>
                <h3>Document Management</h3>
            </div>
            <p>Organize and control document workflows, compliance tracking, and version management.</p>
        </a>

        <a href="procurement_management.php" class="nav-card">
            <div class="nav-card-top">
                <div class="nav-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 001.97-1.67L23 6H6"/>
                    </svg>
                </div>
                <h3>Procurement</h3>
            </div>
            <p>Handle RFQs, purchase orders, goods receipts, and supplier quotation management.</p>
        </a>

        <a href="reports.php" class="nav-card">
            <div class="nav-card-top">
                <div class="nav-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>
                    </svg>
                </div>
                <h3>Reports &amp; Analytics</h3>
            </div>
            <p>Generate comprehensive reports, analyze performance metrics, and export data insights.</p>
        </a>

        <a href="settings.php" class="nav-card">
            <div class="nav-card-top">
                <div class="nav-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 010 14.14M4.93 4.93a10 10 0 000 14.14M12 2v2m0 16v2m8-10h-2M6 12H4m13.66-5.66l-1.42 1.42M7.76 16.24l-1.42 1.42m0-9.9L7.76 7.76m8.48 8.48l1.42 1.42"/>
                    </svg>
                </div>
                <h3>System Settings</h3>
            </div>
            <p>Configure system parameters, manage backups, audit logs, and general application settings.</p>
        </a>

    </div>

    <!-- Recent Activity -->
    <div class="activity-panel">
        <div class="activity-head">
            <h2>Recent Activity</h2>
            <a href="reports/activity_log.php">View All</a>
        </div>

        <?php if (!empty($recent_activities)): ?>
            <?php foreach ($recent_activities as $activity): ?>
            <div class="activity-row">
                <div class="act-icon">
                    <?php if ($activity['type'] === 'Project'): ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                    <?php elseif ($activity['type'] === 'User'): ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>
                    </svg>
                    <?php else: ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/>
                    </svg>
                    <?php endif; ?>
                </div>
                <div class="act-body">
                    <h4><?php echo ucfirst($activity['type']); ?>: <?php echo htmlspecialchars($activity['name']); ?></h4>
                    <p>Status: <?php echo ucfirst(htmlspecialchars($activity['status'])); ?></p>
                </div>
                <div class="act-time"><?php echo date('M j, Y', strtotime($activity['created_at'])); ?></div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">No recent activity to display.</div>
        <?php endif; ?>
    </div>

</main>

<script>
// Auto-refresh every 5 minutes
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