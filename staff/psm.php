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
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

$stats = [];

$stmt = $pdo->query("SELECT COUNT(*) FROM suppliers WHERE status = 'active'");
$stats['total_suppliers'] = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('draft', 'sent', 'confirmed', 'partial')");
$stats['active_orders'] = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM purchase_orders WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
$stats['monthly_value'] = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'draft'");
$stats['pending_approvals'] = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT po.*, s.supplier_name 
    FROM purchase_orders po 
    LEFT JOIN suppliers s ON po.supplier_id = s.id 
    ORDER BY po.created_at DESC 
    LIMIT 5
");
$stmt->execute();
$recent_orders = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT s.supplier_name, COUNT(po.id) as order_count, COALESCE(SUM(po.total_amount), 0) as total_value
    FROM suppliers s 
    LEFT JOIN purchase_orders po ON s.id = po.supplier_id 
    WHERE s.status = 'active'
    GROUP BY s.id, s.supplier_name
    ORDER BY total_value DESC
    LIMIT 5
");
$stmt->execute();
$top_suppliers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurement & Sourcing — BRIGHTPATH</title>
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

        /* ── GRID BG ─── */
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

        /* glow blob */
        body::after {
            content: '';
            position: fixed;
            width: 600px; height: 600px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(61,127,255,.14) 0%, transparent 70%);
            top: -150px; right: -150px;
            pointer-events: none;
            z-index: 0;
        }

        /* ── HEADER ─── */
        

        .header-inner {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        

        .brand-logo svg { width: 38px; height: 38px; flex-shrink: 0; }

        

        

        .header-right {
            display: flex;
            align-items: center;
            gap: 1.4rem;
        }

        
        
        .back-btn svg { width: 14px; height: 14px; }

        

        .user-avatar {
            width: 30px; height: 30px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), var(--steel));
            display: flex; align-items: center; justify-content: center;
            font-size: 0.72rem; font-weight: 600; color: #fff;
        }

        
        

        
        
        .logout-btn svg { width: 14px; height: 14px; }

        /* ── LAYOUT ─── */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2.5rem 2.5rem 4rem;
            position: relative; z-index: 1;
        }

        /* ── PAGE TITLE ─── */
        .page-title {
            margin-bottom: 2.5rem;
        }

        .page-title-tag {
            font-family: 'DM Mono', monospace;
            font-size: 0.68rem;
            color: var(--accent);
            letter-spacing: .2em;
            text-transform: uppercase;
            margin-bottom: 0.5rem;
        }

        .page-title h1 {
            font-size: clamp(1.6rem, 2.5vw, 2.2rem);
            font-weight: 300;
            color: var(--white);
            line-height: 1.2;
        }

        .page-title h1 strong { font-weight: 600; color: #7eb3ff; }
        .page-title p { font-size: 0.9rem; color: rgba(255,255,255,.5); margin-top: 0.4rem; }

        /* ── STATS ROW ─── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.2rem;
            margin-bottom: 2.5rem;
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.5rem 1.6rem;
            position: relative;
            overflow: hidden;
            transition: transform .2s, box-shadow .2s;
        }

        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 12px 32px rgba(0,0,0,.18); }

        .stat-card::after {
            content: '';
            position: absolute; top: 0; left: 0; right: 0; height: 3px;
            background: linear-gradient(90deg, var(--accent), var(--steel));
            border-radius: 14px 14px 0 0;
        }

        .stat-icon-wrap {
            width: 42px; height: 42px;
            background: rgba(61,127,255,.1);
            border: 1px solid rgba(61,127,255,.2);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 1rem;
        }
        .stat-icon-wrap svg { width: 20px; height: 20px; stroke: var(--accent); }

        .stat-value {
            font-size: 1.9rem;
            font-weight: 600;
            color: var(--text);
            line-height: 1;
            margin-bottom: 0.35rem;
        }

        .stat-label {
            font-family: 'DM Mono', monospace;
            font-size: 0.65rem;
            color: var(--muted);
            letter-spacing: .14em;
            text-transform: uppercase;
        }

        /* ── FUNCTION CARDS ─── */
        .section-label {
            font-family: 'DM Mono', monospace;
            font-size: 0.65rem;
            color: rgba(255,255,255,.4);
            letter-spacing: .18em;
            text-transform: uppercase;
            margin-bottom: 1.2rem;
        }

        .functions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.2rem;
            margin-bottom: 2.5rem;
        }

        .fn-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.6rem;
            cursor: pointer;
            transition: transform .22s, box-shadow .22s, border-color .22s;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .fn-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 16px 40px rgba(0,0,0,.2);
            border-color: rgba(61,127,255,.4);
        }

        .fn-card-top {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }

        .fn-icon {
            width: 50px; height: 50px; flex-shrink: 0;
            background: rgba(61,127,255,.08);
            border: 1px solid rgba(61,127,255,.18);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
        }
        .fn-icon svg { width: 24px; height: 24px; stroke: var(--accent); }

        .fn-title { font-size: 0.97rem; font-weight: 600; color: var(--text); margin-bottom: 0.25rem; }
        .fn-desc { font-size: 0.82rem; color: var(--muted); line-height: 1.55; }

        .fn-card-foot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 0.9rem;
            border-top: 1px solid var(--border);
        }

        .fn-btn {
            padding: 0.45rem 1.1rem;
            background: rgba(61,127,255,.1);
            border: 1px solid rgba(61,127,255,.25);
            border-radius: 7px;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--accent);
            cursor: pointer;
            transition: background .2s;
        }
        .fn-btn:hover { background: rgba(61,127,255,.2); }

        .fn-status {
            display: flex;
            align-items: center;
            gap: 6px;
            font-family: 'DM Mono', monospace;
            font-size: 0.63rem;
            color: var(--success);
            letter-spacing: .1em;
            text-transform: uppercase;
        }

        .fn-status-dot {
            width: 6px; height: 6px; border-radius: 50%;
            background: var(--success);
            animation: pulse-dot 2s infinite;
        }

        @keyframes pulse-dot {
            0%, 100% { opacity: 1; }
            50% { opacity: .4; }
        }

        /* ── BOTTOM GRID ─── */
        .bottom-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.4rem;
        }

        .panel {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.3rem 1.6rem;
            border-bottom: 1px solid var(--border);
        }

        .panel-title {
            font-size: 0.92rem;
            font-weight: 600;
            color: var(--text);
        }

        .panel-badge {
            font-family: 'DM Mono', monospace;
            font-size: 0.62rem;
            padding: 0.25rem 0.7rem;
            background: rgba(61,127,255,.08);
            border: 1px solid rgba(61,127,255,.18);
            border-radius: 99px;
            color: var(--accent);
            letter-spacing: .1em;
            text-transform: uppercase;
        }

        /* TABLE */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            padding: 0.75rem 1.6rem;
            font-family: 'DM Mono', monospace;
            font-size: 0.63rem;
            letter-spacing: .14em;
            text-transform: uppercase;
            color: var(--muted);
            text-align: left;
            background: var(--off);
            border-bottom: 1px solid var(--border);
        }

        .data-table td {
            padding: 0.9rem 1.6rem;
            font-size: 0.84rem;
            color: var(--text);
            border-bottom: 1px solid var(--border);
        }

        .data-table tbody tr:last-child td { border-bottom: none; }
        .data-table tbody tr:hover td { background: rgba(61,127,255,.03); }

        .po-num { font-family: 'DM Mono', monospace; font-size: 0.78rem; color: var(--accent); font-weight: 500; }

        .badge {
            display: inline-block;
            padding: 0.22rem 0.7rem;
            border-radius: 99px;
            font-family: 'DM Mono', monospace;
            font-size: 0.6rem;
            font-weight: 500;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .badge-draft    { background: #fef9c3; color: #854d0e; border: 1px solid #fde68a; }
        .badge-sent     { background: #cffafe; color: #155e75; border: 1px solid #a5f3fc; }
        .badge-confirmed{ background: #dcfce7; color: #14532d; border: 1px solid #86efac; }
        .badge-partial  { background: #ffedd5; color: #7c2d12; border: 1px solid #fdba74; }
        .badge-completed{ background: #ede9fe; color: #4c1d95; border: 1px solid #c4b5fd; }
        .badge-cancelled{ background: #fee2e2; color: #7f1d1d; border: 1px solid #fca5a5; }

        .empty-row td {
            text-align: center;
            color: var(--muted);
            padding: 2.5rem !important;
            font-size: 0.84rem;
        }

        .empty-row a { color: var(--accent); text-decoration: none; }
        .empty-row a:hover { text-decoration: underline; }

        /* SIDEBAR PANELS */
        .sidebar-stack { display: flex; flex-direction: column; gap: 1.4rem; }

        .supplier-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.85rem 1.6rem;
            border-bottom: 1px solid var(--border);
        }

        .supplier-item:last-child { border-bottom: none; }

        .supplier-name { font-size: 0.86rem; font-weight: 600; color: var(--text); }
        .supplier-orders { font-size: 0.75rem; color: var(--muted); margin-top: 2px; }

        .supplier-value {
            font-family: 'DM Mono', monospace;
            font-size: 0.84rem;
            font-weight: 500;
            color: var(--accent);
        }

        /* QUICK ACTIONS */
        .qa-list {
            display: flex;
            flex-direction: column;
            gap: 0.7rem;
            padding: 1.3rem 1.6rem;
        }

        .qa-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.75rem 1rem;
            border-radius: 9px;
            font-size: 0.84rem;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all .2s;
            text-align: left;
        }

        .qa-btn svg { width: 16px; height: 16px; flex-shrink: 0; }

        .qa-blue  { background: rgba(61,127,255,.1); border-color: rgba(61,127,255,.2); color: var(--accent); }
        .qa-blue:hover  { background: rgba(61,127,255,.2); }

        .qa-green { background: rgba(21,128,61,.1); border-color: rgba(21,128,61,.2); color: #16a34a; }
        .qa-green:hover { background: rgba(21,128,61,.2); }

        .qa-orange{ background: rgba(180,83,9,.1); border-color: rgba(180,83,9,.2); color: #d97706; }
        .qa-orange:hover{ background: rgba(180,83,9,.2); }

        .qa-teal  { background: rgba(13,148,136,.1); border-color: rgba(13,148,136,.2); color: #0d9488; }
        .qa-teal:hover  { background: rgba(13,148,136,.2); }

        /* RESPONSIVE */
        @media (max-width: 1100px) {
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            .bottom-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 700px) {
            .container { padding: 1.5rem 1rem 3rem; }
            .header { padding: 0.9rem 1rem; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .header-right .back-btn span { display: none; }
        }

        @media (max-width: 500px) {
            .stats-row { grid-template-columns: 1fr; }
            .functions-grid { grid-template-columns: 1fr; }
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
                    <p>Procurement</p>
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
<!-- HEADER -->
<!-- MAIN -->
<div class="container">

    <!-- PAGE TITLE -->
    <div class="page-title">
        <div class="page-title-tag">Module / Procurement</div>
        <h1>Procurement &amp; <strong>Sourcing Management</strong></h1>
        <p>Manage goods and services procurement for loan operations — office supplies, hardware, software, and collateral tools.</p>
    </div>

    <!-- STATS -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>
                    <path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/>
                </svg>
            </div>
            <div class="stat-value"><?php echo number_format($stats['total_suppliers']); ?></div>
            <div class="stat-label">Active Suppliers</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
                </svg>
            </div>
            <div class="stat-value"><?php echo number_format($stats['active_orders']); ?></div>
            <div class="stat-label">Active Orders</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>
                </svg>
            </div>
            <div class="stat-value">₱<?php echo number_format($stats['monthly_value'], 0); ?></div>
            <div class="stat-label">Monthly Value</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                </svg>
            </div>
            <div class="stat-value"><?php echo number_format($stats['pending_approvals']); ?></div>
            <div class="stat-label">Pending Approvals</div>
        </div>
    </div>

    <!-- FUNCTION CARDS -->
    <div class="section-label">Core Functions</div>
    <div class="functions-grid">

        <div class="fn-card" onclick="window.location.href='supplier_management.php'">
            <div class="fn-card-top">
                <div class="fn-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>
                    </svg>
                </div>
                <div>
                    <div class="fn-title">Supplier Registration &amp; Evaluation</div>
                    <div class="fn-desc">Register new suppliers, maintain the vendor database, evaluate performance ratings, and manage relationships for loan operational needs.</div>
                </div>
            </div>
            <div class="fn-card-foot">
                <button class="fn-btn" onclick="event.stopPropagation(); window.location.href='supplier_management.php'">Manage Suppliers</button>
                <div class="fn-status"><div class="fn-status-dot"></div>Active</div>
            </div>
        </div>

        <div class="fn-card" onclick="window.location.href='purchase_requests.php'">
            <div class="fn-card-top">
                <div class="fn-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                </div>
                <div>
                    <div class="fn-title">Purchase Requests / Approvals</div>
                    <div class="fn-desc">Submit purchase requests for office supplies, hardware, software licenses, and collateral tools. Track approval workflows and status updates.</div>
                </div>
            </div>
            <div class="fn-card-foot">
                <button class="fn-btn" onclick="event.stopPropagation(); window.location.href='purchase_requests.php'">Manage Requests</button>
                <div class="fn-status"><div class="fn-status-dot"></div>Active</div>
            </div>
        </div>

        <div class="fn-card" onclick="window.location.href='purchase_orders.php'">
            <div class="fn-card-top">
                <div class="fn-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <polyline points="9 15 11 17 15 13"/>
                    </svg>
                </div>
                <div>
                    <div class="fn-title">Purchase Order Creation &amp; Tracking</div>
                    <div class="fn-desc">Create, manage, and track purchase orders from creation to delivery. Monitor order status and maintain a complete audit trail.</div>
                </div>
            </div>
            <div class="fn-card-foot">
                <button class="fn-btn" onclick="event.stopPropagation(); window.location.href='purchase_orders.php'">Manage Orders</button>
                <div class="fn-status"><div class="fn-status-dot"></div>Active</div>
            </div>
        </div>

        <div class="fn-card" onclick="window.location.href='inventory_updates.php'">
            <div class="fn-card-top">
                <div class="fn-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/>
                        <polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>
                    </svg>
                </div>
                <div>
                    <div class="fn-title">Inventory Updates for Physical Collateral</div>
                    <div class="fn-desc">Track and update inventory of physical collateral and materials for loan operations. Maintain accurate stock levels and location tracking.</div>
                </div>
            </div>
            <div class="fn-card-foot">
                <button class="fn-btn" onclick="event.stopPropagation(); window.location.href='inventory_updates.php'">Update Inventory</button>
                <div class="fn-status"><div class="fn-status-dot"></div>Active</div>
            </div>
        </div>

        <div class="fn-card" onclick="window.location.href='vendor_evaluation.php'">
            <div class="fn-card-top">
                <div class="fn-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                    </svg>
                </div>
                <div>
                    <div class="fn-title">Vendor Performance Evaluation</div>
                    <div class="fn-desc">Evaluate supplier performance based on quality, delivery timelines, pricing, and service. Generate performance reports and ratings.</div>
                </div>
            </div>
            <div class="fn-card-foot">
                <button class="fn-btn" onclick="event.stopPropagation(); window.location.href='vendor_evaluation.php'">Evaluate Vendors</button>
                <div class="fn-status"><div class="fn-status-dot"></div>Active</div>
            </div>
        </div>

        <div class="fn-card" onclick="window.location.href='cost_analysis.php'">
            <div class="fn-card-top">
                <div class="fn-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/>
                        <line x1="6" y1="20" x2="6" y2="14"/><polyline points="22 20 2 20"/>
                    </svg>
                </div>
                <div>
                    <div class="fn-title">Cost Analysis &amp; Optimization</div>
                    <div class="fn-desc">Analyze procurement costs, compare supplier pricing, identify savings opportunities, and generate cost optimization reports for decision making.</div>
                </div>
            </div>
            <div class="fn-card-foot">
                <button class="fn-btn" onclick="event.stopPropagation(); window.location.href='cost_analysis.php'">View Analysis</button>
                <div class="fn-status"><div class="fn-status-dot"></div>Active</div>
            </div>
        </div>

        <div class="fn-card" onclick="window.location.href='rfq_management.php'">
            <div class="fn-card-top">
                <div class="fn-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                        <polyline points="22,6 12,13 2,6"/>
                    </svg>
                </div>
                <div>
                    <div class="fn-title">RFQ Management</div>
                    <div class="fn-desc">Create and manage Request for Quotations, compare vendor proposals, negotiate terms, and streamline the sourcing process.</div>
                </div>
            </div>
            <div class="fn-card-foot">
                <button class="fn-btn" onclick="event.stopPropagation(); window.location.href='rfq_management.php'">Manage RFQs</button>
                <div class="fn-status"><div class="fn-status-dot"></div>Active</div>
            </div>
        </div>

        <div class="fn-card" onclick="window.location.href='procurement_reports.php'">
            <div class="fn-card-top">
                <div class="fn-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>
                    </svg>
                </div>
                <div>
                    <div class="fn-title">Procurement Reports &amp; Analytics</div>
                    <div class="fn-desc">Generate comprehensive reports on procurement activities, spend analysis, supplier performance metrics, and purchasing trends.</div>
                </div>
            </div>
            <div class="fn-card-foot">
                <button class="fn-btn" onclick="event.stopPropagation(); window.location.href='procurement_reports.php'">View Reports</button>
                <div class="fn-status"><div class="fn-status-dot"></div>Active</div>
            </div>
        </div>
    </div>

    <!-- BOTTOM SECTION -->
    <div class="bottom-grid">

        <!-- Recent POs -->
        <div class="panel">
            <div class="panel-header">
                <span class="panel-title">Recent Purchase Orders</span>
                <span class="panel-badge">Last 5</span>
            </div>
            <div class="table-container" style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>PO Number</th>
                            <th>Supplier</th>
                            <th>Order Date</th>
                            <th>Total Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_orders)): ?>
                        <tr class="empty-row">
                            <td colspan="5">No purchase orders found. <a href="purchase_orders.php">Create your first purchase order →</a></td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($recent_orders as $order): ?>
                        <tr>
                            <td><span class="po-num"><?php echo htmlspecialchars($order['po_number']); ?></span></td>
                            <td><?php echo htmlspecialchars($order['supplier_name'] ?? 'N/A'); ?></td>
                            <td><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                            <td><strong>₱<?php echo number_format($order['total_amount'], 2); ?></strong></td>
                            <td><span class="badge badge-<?php echo $order['status']; ?>"><?php echo ucfirst($order['status']); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="sidebar-stack">

            <!-- Top Suppliers -->
            <div class="panel">
                <div class="panel-header">
                    <span class="panel-title">Top Suppliers</span>
                    <span class="panel-badge">By Value</span>
                </div>
                <?php if (empty($top_suppliers)): ?>
                <div style="padding:1.5rem 1.6rem; font-size:0.84rem; color:var(--muted); text-align:center;">
                    No supplier data. <a href="supplier_management.php" style="color:var(--accent);">Add suppliers</a>
                </div>
                <?php else: ?>
                <?php foreach ($top_suppliers as $s): ?>
                <div class="supplier-item">
                    <div>
                        <div class="supplier-name"><?php echo htmlspecialchars($s['supplier_name']); ?></div>
                        <div class="supplier-orders"><?php echo $s['order_count']; ?> orders</div>
                    </div>
                    <div class="supplier-value">₱<?php echo number_format($s['total_value'], 0); ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Quick Actions -->
            <div class="panel">
                <div class="panel-header">
                    <span class="panel-title">Quick Actions</span>
                </div>
                <div class="qa-list">
                    <button class="qa-btn qa-blue" onclick="window.location.href='purchase_orders.php?action=create'">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        Create New PO
                    </button>
                    <button class="qa-btn qa-green" onclick="window.location.href='supplier_management.php?action=create'">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/>
                            <line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/>
                        </svg>
                        Add Supplier
                    </button>
                    <button class="qa-btn qa-orange" onclick="window.location.href='purchase_requests.php?action=create'">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                            <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                        </svg>
                        New Purchase Request
                    </button>
                    <button class="qa-btn qa-teal" onclick="window.location.href='rfq_management.php?action=create'">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                            <polyline points="22,6 12,13 2,6"/>
                        </svg>
                        Create RFQ
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Staggered entrance for function cards
    const cards = document.querySelectorAll('.fn-card');
    cards.forEach((card, i) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        card.style.transition = 'opacity .45s ease, transform .45s ease, box-shadow .22s, border-color .22s';
        setTimeout(() => {
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, 80 + i * 60);
    });

    // Staggered entrance for stat cards
    document.querySelectorAll('.stat-card').forEach((c, i) => {
        c.style.opacity = '0';
        c.style.transform = 'translateY(16px)';
        c.style.transition = 'opacity .4s ease, transform .4s ease, box-shadow .2s';
        setTimeout(() => {
            c.style.opacity = '1';
            c.style.transform = 'translateY(0)';
        }, 40 + i * 50);
    });

    // Quick action buttons feedback
    document.querySelectorAll('.qa-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            this.style.transform = 'scale(.97)';
            setTimeout(() => this.style.transform = '', 150);
        });
    });
});
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