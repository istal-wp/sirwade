<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_role('admin');

require_once __DIR__ . '/../includes/db.php';
$pdo = db(); // Shared singleton from includes/db.php
try {
    $status_filter = $_GET['status'] ?? ''; $type_filter = $_GET['type'] ?? '';
    $priority_filter = $_GET['priority'] ?? ''; $search = $_GET['search'] ?? '';
    $where_conditions = []; $params = [];
    if ($status_filter) { $where_conditions[] = "d.status = :status"; $params[':status'] = $status_filter; }
    if ($type_filter) { $where_conditions[] = "d.document_type_id = :type_id"; $params[':type_id'] = $type_filter; }
    if ($priority_filter) { $where_conditions[] = "d.priority = :priority"; $params[':priority'] = $priority_filter; }
    if ($search) { $where_conditions[] = "(d.title LIKE :search OR d.description LIKE :search OR d.document_code LIKE :search)"; $params[':search'] = "%$search%"; }
    $where_clause = !empty($where_conditions) ? "WHERE ".implode(" AND ", $where_conditions) : "";
    $page = (int)($_GET['page'] ?? 1); $limit = 20; $offset = ($page - 1) * $limit;
    $stmt = $pdo->prepare("SELECT d.*, dt.type_name, s.supplier_name, p.project_name, po.po_number, CASE WHEN d.expiry_date <= CURDATE() THEN 'Expired' WHEN d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'Expiring Soon' ELSE 'Valid' END as expiry_status, COUNT(dw.id) as workflow_count, SUM(CASE WHEN dw.status='pending' THEN 1 ELSE 0 END) as pending_workflows FROM documents d LEFT JOIN document_types dt ON d.document_type_id=dt.id LEFT JOIN suppliers s ON d.related_supplier_id=s.id LEFT JOIN projects p ON d.related_project_id=p.id LEFT JOIN purchase_orders po ON d.related_po_id=po.id LEFT JOIN document_workflows dw ON d.id=dw.document_id $where_clause GROUP BY d.id ORDER BY d.created_at DESC LIMIT $limit OFFSET $offset");
    $stmt->execute($params); $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $count_stmt = $pdo->prepare("SELECT COUNT(DISTINCT d.id) as total FROM documents d LEFT JOIN document_types dt ON d.document_type_id=dt.id LEFT JOIN suppliers s ON d.related_supplier_id=s.id LEFT JOIN projects p ON d.related_project_id=p.id LEFT JOIN purchase_orders po ON d.related_po_id=po.id $where_clause");
    $count_stmt->execute($params); $total_documents = $count_stmt->fetch()['total']; $total_pages = ceil($total_documents / $limit);
    $status_options = ['draft','active','archived','expired','pending_approval'];
    $priority_options = ['low','medium','high','critical'];
    $type_stmt = $pdo->query("SELECT id, type_name FROM document_types WHERE is_active=1 ORDER BY type_name"); $document_types = $type_stmt->fetchAll(PDO::FETCH_ASSOC);
    $stats_stmt = $pdo->query("SELECT COUNT(*) as total_documents, SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active_count, SUM(CASE WHEN status='pending_approval' THEN 1 ELSE 0 END) as pending_count, SUM(CASE WHEN status='expired' THEN 1 ELSE 0 END) as expired_count, SUM(CASE WHEN expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND status!='expired' THEN 1 ELSE 0 END) as expiring_soon FROM documents");
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
} catch(PDOException $e) { error_log("Documents error: ".$e->getMessage()); $documents=[]; $document_types=[]; $total_documents=0; $total_pages=0; $stats=['total_documents'=>0,'active_count'=>0,'pending_count'=>0,'expired_count'=>0,'expiring_soon'=>0]; }
function buildQueryString($overrides = []) {
    $params = ['search' => $_GET['search']??'', 'status' => $_GET['status']??'', 'type' => $_GET['type']??'', 'priority' => $_GET['priority']??'', 'page' => $_GET['page']??1];
    foreach ($overrides as $k => $v) $params[$k] = $v;
    return '?'.http_build_query(array_filter($params));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document List — BRIGHTPATH</title>
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
        .btn-back{display:flex;align-items:center;gap:7px;padding:.5rem 1rem;background:none;border:1.5px solid var(--border);border-radius:8px;font-size:.82rem;font-weight:500;font-family:'DM Sans',sans-serif;color:var(--muted);text-decoration:none;transition:border-color .2s,color .2s}
        .btn-back svg{width:14px;height:14px;stroke:currentColor}
        .btn-back:hover{border-color:var(--accent);color:var(--accent)}
        .main{max-width:1400px;margin:0 auto;padding:2rem 2rem 3rem}
        .page-title{margin-bottom:1.75rem}
        .page-title h1{font-size:1.6rem;font-weight:600;color:var(--navy);margin-bottom:.25rem}
        .page-title p{font-size:.88rem;color:var(--muted)}
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem;margin-bottom:1.75rem}
        .stat-card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:1.25rem 1.4rem;transition:box-shadow .2s,transform .15s}
        .stat-card:hover{box-shadow:0 4px 18px rgba(15,31,61,.08);transform:translateY(-2px)}
        .stat-label{font-size:.78rem;font-weight:500;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem}
        .stat-value{font-size:2rem;font-weight:600;color:var(--navy);line-height:1}
        .stat-value.good{color:var(--success)}.stat-value.warn{color:var(--warn)}.stat-value.err{color:var(--error)}
        .filter-card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:1.25rem 1.4rem;margin-bottom:1.5rem}
        .filter-grid{display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto auto;gap:.75rem;align-items:end}
        .filter-group label{display:block;font-size:.78rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.35rem}
        .filter-group input,.filter-group select{width:100%;padding:.55rem .85rem;border:1.5px solid var(--border);border-radius:9px;font-size:.84rem;font-family:'DM Sans',sans-serif;color:var(--text);outline:none;background:var(--white);transition:border-color .2s}
        .filter-group input:focus,.filter-group select:focus{border-color:var(--accent)}
        .btn-filter{padding:.55rem 1.1rem;background:linear-gradient(135deg,var(--navy),var(--steel));color:white;border:none;border-radius:9px;font-size:.84rem;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;white-space:nowrap;align-self:end}
        .btn-clear{padding:.55rem 1.1rem;background:var(--off);color:var(--muted);border:1.5px solid var(--border);border-radius:9px;font-size:.84rem;font-family:'DM Sans',sans-serif;text-decoration:none;white-space:nowrap;align-self:end;display:inline-flex;align-items:center}
        .table-wrap{background:var(--white);border:1px solid var(--border);border-radius:12px;overflow:hidden}
        table{width:100%;border-collapse:collapse}
        thead th{padding:.85rem 1.1rem;text-align:left;font-size:.75rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;background:var(--off);border-bottom:1px solid var(--border);white-space:nowrap}
        tbody td{padding:.9rem 1.1rem;border-bottom:1px solid var(--off);font-size:.84rem;vertical-align:middle}
        tbody tr:last-child td{border-bottom:none}
        tbody tr:hover td{background:rgba(61,127,255,.03)}
        .doc-title{font-weight:500;color:var(--text)}
        .doc-code{font-size:.75rem;color:var(--muted);font-family:'DM Mono',monospace;margin-top:1px}
        .badge{display:inline-block;padding:.25rem .65rem;border-radius:99px;font-size:.74rem;font-weight:600}
        .badge-active{background:rgba(21,128,61,.1);color:var(--success)}
        .badge-draft{background:rgba(108,117,125,.1);color:#6c757d}
        .badge-archived{background:rgba(15,31,61,.08);color:var(--navy)}
        .badge-expired{background:rgba(197,48,48,.1);color:var(--error)}
        .badge-pending-approval{background:rgba(180,83,9,.1);color:var(--warn)}
        .priority-low{background:rgba(21,128,61,.1);color:var(--success)}
        .priority-medium{background:rgba(61,127,255,.1);color:var(--accent)}
        .priority-high{background:rgba(180,83,9,.1);color:var(--warn)}
        .priority-critical{background:rgba(197,48,48,.1);color:var(--error)}
        .expiry-valid{font-size:.77rem;color:var(--success)}
        .expiry-expiringsoon{font-size:.77rem;color:var(--warn)}
        .expiry-expired{font-size:.77rem;color:var(--error)}
        .workflow-info{font-size:.77rem;color:var(--muted)}
        .workflow-pending{color:var(--warn)}
        .pagination{display:flex;align-items:center;gap:.4rem;padding:1.1rem 1.4rem;border-top:1px solid var(--border);flex-wrap:wrap}
        .pg-btn{display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 8px;border-radius:7px;font-size:.82rem;text-decoration:none;color:var(--text);border:1.5px solid var(--border);background:var(--white);transition:all .15s}
        .pg-btn:hover{border-color:var(--accent);color:var(--accent)}
        .pg-current{background:var(--navy);color:white;font-weight:600;border:none}
        .pg-info{font-size:.78rem;color:var(--muted);margin-left:auto;font-family:'DM Mono',monospace}
        .empty-state{text-align:center;padding:3rem;color:var(--muted);font-size:.88rem}
        @media(max-width:900px){.filter-grid{grid-template-columns:1fr 1fr}}
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
                    <p>Document List</p>
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
        <h1>Document List</h1>
        <p>Browse, search, and filter all documents in the system</p>
    </div>

    <div class="stats-grid">
        <div class="stat-card"><div class="stat-label">Total Documents</div><div class="stat-value"><?php echo number_format($stats['total_documents']); ?></div></div>
        <div class="stat-card"><div class="stat-label">Active</div><div class="stat-value good"><?php echo number_format($stats['active_count']); ?></div></div>
        <div class="stat-card"><div class="stat-label">Pending Approval</div><div class="stat-value warn"><?php echo number_format($stats['pending_count']); ?></div></div>
        <div class="stat-card"><div class="stat-label">Expiring Soon</div><div class="stat-value warn"><?php echo number_format($stats['expiring_soon']); ?></div></div>
        <div class="stat-card"><div class="stat-label">Expired</div><div class="stat-value err"><?php echo number_format($stats['expired_count']); ?></div></div>
    </div>

    <!-- Filters -->
    <div class="filter-card">
        <form method="GET" action="">
            <div class="filter-grid">
                <div class="filter-group"><label>Search</label><input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Title, code, description…"></div>
                <div class="filter-group"><label>Status</label>
                    <select name="status">
                        <option value="">All Statuses</option>
                        <?php foreach ($status_options as $s): ?><option value="<?php echo $s; ?>" <?php echo $status_filter===$s?'selected':''; ?>><?php echo ucwords(str_replace('_',' ',$s)); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group"><label>Document Type</label>
                    <select name="type">
                        <option value="">All Types</option>
                        <?php foreach ($document_types as $dt): ?><option value="<?php echo $dt['id']; ?>" <?php echo $type_filter==$dt['id']?'selected':''; ?>><?php echo htmlspecialchars($dt['type_name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group"><label>Priority</label>
                    <select name="priority">
                        <option value="">All Priorities</option>
                        <?php foreach ($priority_options as $p): ?><option value="<?php echo $p; ?>" <?php echo $priority_filter===$p?'selected':''; ?>><?php echo ucfirst($p); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-filter">Apply</button>
                <a href="list_documents.php" class="btn-clear">Clear</a>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Document</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Priority</th>
                    <th>Expiry</th>
                    <th>Related To</th>
                    <th>Workflows</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($documents)): ?>
                <tr><td colspan="8"><div class="empty-state">No documents found matching your criteria.</div></td></tr>
                <?php else: ?>
                <?php foreach ($documents as $doc): ?>
                <tr>
                    <td>
                        <div class="doc-title"><?php echo htmlspecialchars($doc['title']); ?></div>
                        <div class="doc-code"><?php echo htmlspecialchars($doc['document_code'] ?? ''); ?></div>
                    </td>
                    <td><?php echo htmlspecialchars($doc['type_name'] ?? '—'); ?></td>
                    <td>
                        <?php $sc = str_replace('_','-',$doc['status']); ?>
                        <span class="badge badge-<?php echo $sc; ?>"><?php echo ucwords(str_replace('_',' ',$doc['status'])); ?></span>
                    </td>
                    <td>
                        <?php if (!empty($doc['priority'])): ?>
                        <span class="badge priority-<?php echo $doc['priority']; ?>"><?php echo ucfirst($doc['priority']); ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                        <?php if ($doc['expiry_date']): ?>
                        <div class="expiry-<?php echo strtolower(str_replace(' ','',$doc['expiry_status'])); ?>">
                            <?php echo date('M j, Y', strtotime($doc['expiry_date'])); ?><br>
                            <span style="font-size:.73rem"><?php echo $doc['expiry_status']; ?></span>
                        </div>
                        <?php else: ?><span class="doc-code">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($doc['supplier_name']): ?><div style="font-size:.82rem">📦 <?php echo htmlspecialchars($doc['supplier_name']); ?></div><?php endif; ?>
                        <?php if ($doc['project_name']): ?><div style="font-size:.82rem">📋 <?php echo htmlspecialchars($doc['project_name']); ?></div><?php endif; ?>
                        <?php if ($doc['po_number']): ?><div style="font-size:.82rem">🧾 <?php echo htmlspecialchars($doc['po_number']); ?></div><?php endif; ?>
                        <?php if (!$doc['supplier_name'] && !$doc['project_name'] && !$doc['po_number']): ?>—<?php endif; ?>
                    </td>
                    <td>
                        <?php if ($doc['workflow_count'] > 0): ?>
                        <div class="workflow-info">
                            <?php echo $doc['workflow_count']; ?> step<?php echo $doc['workflow_count']!=1?'s':''; ?>
                            <?php if ($doc['pending_workflows'] > 0): ?><br><span class="workflow-pending"><?php echo $doc['pending_workflows']; ?> pending</span>
                            <?php else: ?><br><span style="color:var(--success)">Complete</span><?php endif; ?>
                        </div>
                        <?php else: ?><span class="doc-code">—</span><?php endif; ?>
                    </td>
                    <td style="font-family:'DM Mono',monospace;font-size:.82rem;color:var(--muted)"><?php echo date('M j, Y', strtotime($doc['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="<?php echo buildQueryString(['page'=>1]); ?>" class="pg-btn">First</a>
            <a href="<?php echo buildQueryString(['page'=>$page-1]); ?>" class="pg-btn">← Prev</a>
            <?php endif; ?>
            <?php for ($i = max(1,$page-2); $i <= min($total_pages,$page+2); $i++): ?>
                <?php if ($i===$page): ?><span class="pg-btn pg-current"><?php echo $i; ?></span>
                <?php else: ?><a href="<?php echo buildQueryString(['page'=>$i]); ?>" class="pg-btn"><?php echo $i; ?></a><?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?>
            <a href="<?php echo buildQueryString(['page'=>$page+1]); ?>" class="pg-btn">Next →</a>
            <a href="<?php echo buildQueryString(['page'=>$total_pages]); ?>" class="pg-btn">Last</a>
            <?php endif; ?>
            <span class="pg-info">Page <?php echo $page; ?> of <?php echo $total_pages; ?> &nbsp;·&nbsp; <?php echo $total_documents; ?> documents</span>
        </div>
        <?php endif; ?>
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