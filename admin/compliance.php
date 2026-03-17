<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_role('admin');
// ═══ ADMIN MONITORING-ONLY GUARD ═══════════════════════════════════════════
// Write operations (INSERT/UPDATE/DELETE) have been moved to staff/documents.php
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
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_requirement':
                $stmt = $pdo->prepare("INSERT INTO compliance_requirements (requirement_name, description, regulatory_body, requirement_type, applicable_document_types, deadline_type, recurring_period_days, penalty_description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $applicable_types = isset($_POST['applicable_document_types']) ? json_encode($_POST['applicable_document_types']) : null;
                $stmt->execute([$_POST['requirement_name'], $_POST['description'], $_POST['regulatory_body'], $_POST['requirement_type'], $applicable_types, $_POST['deadline_type'], $_POST['recurring_period_days'] ?: null, $_POST['penalty_description']]);
                $_SESSION['success_message'] = "Compliance requirement created successfully!"; break;
            case 'delete_requirement':
                $stmt = $pdo->prepare("UPDATE compliance_requirements SET is_active=0 WHERE id=?");
                $stmt->execute([$_POST['requirement_id']]);
                $_SESSION['success_message'] = "Compliance requirement deactivated."; break;
            case 'update_compliance':
                $stmt = $pdo->prepare("UPDATE document_compliance SET compliance_status=?, due_date=?, completed_date=?, notes=?, last_reviewed_by=?, last_reviewed_at=NOW() WHERE id=?");
                $completed_date = ($_POST['compliance_status'] === 'compliant') ? date('Y-m-d') : null;
                $stmt->execute([$_POST['compliance_status'], $_POST['due_date'], $completed_date, $_POST['notes'], $_SESSION['user_name'], $_POST['compliance_id']]);
                $_SESSION['success_message'] = "Compliance status updated!"; break;
            case 'bulk_assign':
                if (isset($_POST['document_ids'], $_POST['requirement_id'])) {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO document_compliance (document_id, requirement_id, due_date) VALUES (?, ?, ?)");
                    foreach ($_POST['document_ids'] as $did) { $stmt->execute([$did, $_POST['requirement_id'], $_POST['due_date']]); }
                    $_SESSION['success_message'] = "Compliance requirements assigned!";
                } break;
        }
        header("Location: compliance.php"); exit();
    }
    $stmt = $pdo->query("SELECT cr.*, COUNT(dc.id) as total_assignments, SUM(CASE WHEN dc.compliance_status='compliant' THEN 1 ELSE 0 END) as compliant_count, SUM(CASE WHEN dc.compliance_status='non_compliant' THEN 1 ELSE 0 END) as non_compliant_count, SUM(CASE WHEN dc.compliance_status='pending' THEN 1 ELSE 0 END) as pending_count FROM compliance_requirements cr LEFT JOIN document_compliance dc ON cr.id=dc.requirement_id WHERE cr.is_active=1 GROUP BY cr.id ORDER BY cr.requirement_name");
    $requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->query("SELECT dc.*, d.title as document_title, d.document_code, cr.requirement_name, CASE WHEN dc.due_date < CURDATE() AND dc.compliance_status!='compliant' THEN 'overdue' WHEN dc.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND dc.compliance_status!='compliant' THEN 'due_soon' ELSE 'on_track' END as urgency_status FROM document_compliance dc JOIN documents d ON dc.document_id=d.id JOIN compliance_requirements cr ON dc.requirement_id=cr.id WHERE cr.is_active=1 ORDER BY dc.due_date ASC");
    $compliance_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->query("SELECT id, title, document_code FROM documents WHERE status='active' ORDER BY title");
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stats = [];
    $stats['total_requirements'] = $pdo->query("SELECT COUNT(*) FROM compliance_requirements WHERE is_active=1")->fetchColumn();
    $stats['compliant'] = $pdo->query("SELECT COUNT(*) FROM document_compliance WHERE compliance_status='compliant'")->fetchColumn();
    $stats['non_compliant'] = $pdo->query("SELECT COUNT(*) FROM document_compliance WHERE compliance_status='non_compliant'")->fetchColumn();
    $stats['pending'] = $pdo->query("SELECT COUNT(*) FROM document_compliance WHERE compliance_status='pending'")->fetchColumn();
    $stats['overdue'] = $pdo->query("SELECT COUNT(*) FROM document_compliance WHERE due_date<CURDATE() AND compliance_status!='compliant'")->fetchColumn();
} catch(PDOException $e) { error_log("Compliance error: ".$e->getMessage()); $requirements=[]; $compliance_items=[]; $documents=[]; $stats=['total_requirements'=>0,'compliant'=>0,'non_compliant'=>0,'pending'=>0,'overdue'=>0]; }
$success = $_SESSION['success_message'] ?? ''; unset($_SESSION['success_message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compliance Management — BRIGHTPATH</title>
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
        .alert-success{padding:.85rem 1.1rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;margin-bottom:1.25rem;font-size:.87rem;color:var(--success)}
        .section-label{font-size:.78rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:.85rem;margin-top:2rem}
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:1rem;margin-bottom:1.75rem}
        .stat-card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:1.25rem 1.4rem;transition:box-shadow .2s,transform .15s}
        .stat-card:hover{box-shadow:0 4px 18px rgba(15,31,61,.08);transform:translateY(-2px)}
        .stat-label{font-size:.78rem;font-weight:500;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem}
        .stat-value{font-size:2rem;font-weight:600;color:var(--navy);line-height:1}
        .stat-value.good{color:var(--success)}.stat-value.warn{color:var(--warn)}.stat-value.err{color:var(--error)}
        .control-bar{display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem}
        .btn-add{display:flex;align-items:center;gap:7px;padding:.55rem 1.1rem;background:linear-gradient(135deg,var(--navy),var(--steel));color:var(--white);border:none;border-radius:9px;font-size:.84rem;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;transition:box-shadow .2s}
        .btn-add:hover{box-shadow:0 4px 14px rgba(15,31,61,.25)}
        .btn-bulk{display:flex;align-items:center;gap:7px;padding:.55rem 1.1rem;background:rgba(61,127,255,.09);color:var(--accent);border:1.5px solid rgba(61,127,255,.2);border-radius:9px;font-size:.84rem;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;transition:all .15s}
        .btn-bulk:hover{background:var(--accent);color:white;border-color:var(--accent)}
        .table-wrap{background:var(--white);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:1.5rem}
        table{width:100%;border-collapse:collapse}
        thead th{padding:.85rem 1.1rem;text-align:left;font-size:.75rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;background:var(--off);border-bottom:1px solid var(--border);white-space:nowrap}
        tbody td{padding:.9rem 1.1rem;border-bottom:1px solid var(--off);font-size:.85rem;vertical-align:middle}
        tbody tr:last-child td{border-bottom:none}
        tbody tr:hover td{background:rgba(61,127,255,.03)}
        .badge{display:inline-block;padding:.25rem .65rem;border-radius:99px;font-size:.74rem;font-weight:600}
        .badge-compliant{background:rgba(21,128,61,.1);color:var(--success)}
        .badge-pending{background:rgba(180,83,9,.1);color:var(--warn)}
        .badge-non-compliant{background:rgba(197,48,48,.1);color:var(--error)}
        .badge-overdue{background:rgba(197,48,48,.1);color:var(--error)}
        .badge-due-soon{background:rgba(180,83,9,.1);color:var(--warn)}
        .badge-on-track{background:rgba(21,128,61,.1);color:var(--success)}
        .action-row{display:flex;gap:.5rem;flex-wrap:wrap}
        .btn-sm{padding:.3rem .7rem;border-radius:7px;font-size:.78rem;font-weight:500;font-family:'DM Sans',sans-serif;cursor:pointer;border:1.5px solid transparent;transition:all .15s}
        .btn-edit{background:rgba(61,127,255,.08);color:var(--accent);border-color:rgba(61,127,255,.2)}
        .btn-edit:hover{background:var(--accent);color:white}
        .btn-del{background:rgba(108,117,125,.08);color:var(--muted);border-color:var(--border)}
        .btn-del:hover{background:var(--error);color:white;border-color:var(--error)}
        .empty-state{text-align:center;padding:3rem;color:var(--muted);font-size:.88rem}
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,31,61,.45);z-index:999;align-items:center;justify-content:center}
        .modal-overlay.open{display:flex}
        .modal-box{background:var(--white);border-radius:16px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(15,31,61,.25)}
        .modal-head{display:flex;justify-content:space-between;align-items:center;padding:1.4rem 1.6rem 1.1rem;border-bottom:1px solid var(--border)}
        .modal-head h2{font-size:1.05rem;font-weight:600;color:var(--navy)}
        .modal-close{width:30px;height:30px;border:none;background:var(--off);border-radius:7px;cursor:pointer;font-size:1rem;color:var(--muted)}
        .modal-body{padding:1.4rem 1.6rem}
        .modal-foot{padding:1rem 1.6rem;border-top:1px solid var(--border);display:flex;gap:.75rem;justify-content:flex-end}
        .form-group{margin-bottom:1rem}
        .form-group label{display:block;font-size:.8rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.4rem}
        .form-group input,.form-group select,.form-group textarea{width:100%;padding:.6rem .85rem;border:1.5px solid var(--border);border-radius:9px;font-size:.87rem;font-family:'DM Sans',sans-serif;color:var(--text);outline:none;transition:border-color .2s;background:var(--white)}
        .form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--accent)}
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
        .btn-primary{padding:.6rem 1.3rem;background:linear-gradient(135deg,var(--navy),var(--steel));color:white;border:none;border-radius:9px;font-size:.87rem;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer}
        .btn-secondary{padding:.6rem 1.3rem;background:var(--off);color:var(--muted);border:1.5px solid var(--border);border-radius:9px;font-size:.87rem;font-family:'DM Sans',sans-serif;cursor:pointer}
        @media(max-width:768px){.main{padding:1.25rem 1rem 2rem}.stats-grid{grid-template-columns:1fr 1fr}.form-row{grid-template-columns:1fr}}
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
                    <p>Compliance</p>
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
<!-- admin: staff-redirect-banner -->
<?php if (isset($_SESSION['flash_message'])): ?>
<div style="display:flex;align-items:center;gap:10px;padding:.85rem 1.1rem;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;margin-bottom:1.5rem;font-size:.87rem;color:#b45309">
    <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;flex-shrink:0"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <?php echo htmlspecialchars($_SESSION['flash_message']); unset($_SESSION['flash_message'],$_SESSION['flash_type']); ?>
</div>
<?php endif; ?>
<div style="display:flex;align-items:center;gap:12px;padding:1rem 1.25rem;background:#eff6ff;border:1px solid #93c5fd;border-radius:10px;margin-bottom:1.5rem">
    <svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:#2563eb;fill:none;stroke-width:2;flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <span style="font-size:.87rem;color:#1d4ed8;flex:1"><strong>Admin View:</strong> This is a read-only monitoring view. To add or modify Documents & Compliance data, go to the Staff Portal.</span>
    <a href="../staff/documents.php" style="display:inline-flex;align-items:center;gap:6px;padding:.45rem .9rem;background:#2563eb;color:#fff;border-radius:8px;font-size:.82rem;font-weight:600;text-decoration:none">
        <svg viewBox="0 0 24 24" style="width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2"><polyline points="9 18 15 12 9 6"/></svg>Staff Portal
    </a>
</div>


    <div class="page-title">
        <h1>Compliance Management</h1>
        <p>Manage compliance requirements, track document compliance status, and monitor deadlines</p>
    </div>

    <?php if ($success): ?><div class="alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card"><div class="stat-label">Requirements</div><div class="stat-value"><?php echo number_format($stats['total_requirements']); ?></div></div>
        <div class="stat-card"><div class="stat-label">Compliant</div><div class="stat-value good"><?php echo number_format($stats['compliant']); ?></div></div>
        <div class="stat-card"><div class="stat-label">Non-Compliant</div><div class="stat-value err"><?php echo number_format($stats['non_compliant']); ?></div></div>
        <div class="stat-card"><div class="stat-label">Pending</div><div class="stat-value warn"><?php echo number_format($stats['pending']); ?></div></div>
        <div class="stat-card"><div class="stat-label">Overdue</div><div class="stat-value err"><?php echo number_format($stats['overdue']); ?></div></div>
    </div>

    <!-- Requirements -->
    <div class="section-label">Compliance Requirements</div>
    <div class="control-bar">
        <div style="display:flex;gap:.75rem">
            <button class="btn-add" onclick="openModal('reqModal')">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                New Requirement
            </button>
            <button class="btn-bulk" onclick="openModal('bulkModal')">Bulk Assign</button>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Requirement</th><th>Regulatory Body</th><th>Type</th><th>Compliant</th><th>Non-Compliant</th><th>Pending</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($requirements as $req): ?>
                <tr>
                    <td>
                        <div style="font-weight:500"><?php echo htmlspecialchars($req['requirement_name']); ?></div>
                        <?php if (!empty($req['description'])): ?><div style="font-size:.77rem;color:var(--muted)"><?php echo htmlspecialchars(substr($req['description'],0,80)); ?><?php echo strlen($req['description'])>80?'…':''; ?></div><?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($req['regulatory_body'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($req['requirement_type'] ?? '—'); ?></td>
                    <td><span class="badge badge-compliant"><?php echo number_format($req['compliant_count']); ?></span></td>
                    <td><span class="badge badge-non-compliant"><?php echo number_format($req['non_compliant_count']); ?></span></td>
                    <td><span class="badge badge-pending"><?php echo number_format($req['pending_count']); ?></span></td>
                    <td>
                        <div class="action-row">
                            <form method="POST" style="display:inline" onsubmit="return confirm('Deactivate this requirement?')">
                                <input type="hidden" name="action" value="delete_requirement">
                                <input type="hidden" name="requirement_id" value="<?php echo $req['id']; ?>">
                                <button type="submit" class="btn-sm btn-del">Deactivate</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($requirements)): ?><tr><td colspan="7"><div class="empty-state">No compliance requirements found.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Compliance Items -->
    <div class="section-label">Document Compliance Status</div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Document</th><th>Requirement</th><th>Status</th><th>Due Date</th><th>Urgency</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($compliance_items as $item): ?>
                <tr>
                    <td>
                        <div style="font-weight:500"><?php echo htmlspecialchars($item['document_title']); ?></div>
                        <div style="font-size:.76rem;color:var(--muted);font-family:'DM Mono',monospace"><?php echo htmlspecialchars($item['document_code'] ?? ''); ?></div>
                    </td>
                    <td><?php echo htmlspecialchars($item['requirement_name']); ?></td>
                    <td>
                        <?php $cs = str_replace('_','-',$item['compliance_status']); ?>
                        <span class="badge badge-<?php echo $cs; ?>"><?php echo ucwords(str_replace('_',' ',$item['compliance_status'])); ?></span>
                    </td>
                    <td style="font-family:'DM Mono',monospace;font-size:.83rem"><?php echo $item['due_date'] ? date('M j, Y', strtotime($item['due_date'])) : '—'; ?></td>
                    <td>
                        <?php $u = str_replace('_','-',$item['urgency_status']); ?>
                        <span class="badge badge-<?php echo $u; ?>"><?php echo ucwords(str_replace('_',' ',$item['urgency_status'])); ?></span>
                    </td>
                    <td>
                        <button class="btn-sm btn-edit" onclick="openUpdateModal(<?php echo $item['id']; ?>,'<?php echo addslashes($item['compliance_status']); ?>','<?php echo $item['due_date']; ?>')">Update</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($compliance_items)): ?><tr><td colspan="6"><div class="empty-state">No compliance items found.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<!-- New Requirement Modal -->
<div id="reqModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-head"><h2>New Compliance Requirement</h2><button class="modal-close" onclick="closeModal('reqModal')">✕</button></div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="create_requirement">
            <div class="modal-body">
                <div class="form-group"><label>Requirement Name</label><input type="text" name="requirement_name" required></div>
                <div class="form-row">
                    <div class="form-group"><label>Regulatory Body</label><input type="text" name="regulatory_body"></div>
                    <div class="form-group"><label>Type</label>
                        <select name="requirement_type">
                            <option value="mandatory">Mandatory</option>
                            <option value="optional">Optional</option>
                            <option value="periodic">Periodic</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Deadline Type</label>
                        <select name="deadline_type"><option value="fixed">Fixed</option><option value="recurring">Recurring</option></select>
                    </div>
                    <div class="form-group"><label>Recurring Period (days)</label><input type="number" name="recurring_period_days" min="1"></div>
                </div>
                <div class="form-group"><label>Description</label><textarea name="description" rows="3" style="resize:vertical"></textarea></div>
                <div class="form-group"><label>Penalty Description</label><textarea name="penalty_description" rows="2" style="resize:vertical"></textarea></div>
            </div>
            <div class="modal-foot"><button type="button" class="btn-secondary" onclick="closeModal('reqModal')">Cancel</button><button type="submit" class="btn-primary">Create</button></div>
        </form>
    </div>
</div>

<!-- Update Compliance Modal -->
<div id="updateModal" class="modal-overlay">
    <div class="modal-box" style="max-width:420px">
        <div class="modal-head"><h2>Update Compliance Status</h2><button class="modal-close" onclick="closeModal('updateModal')">✕</button></div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="update_compliance">
            <input type="hidden" name="compliance_id" id="upd-id">
            <div class="modal-body">
                <div class="form-group"><label>Status</label>
                    <select name="compliance_status" id="upd-status">
                        <option value="pending">Pending</option>
                        <option value="compliant">Compliant</option>
                        <option value="non_compliant">Non-Compliant</option>
                    </select>
                </div>
                <div class="form-group"><label>Due Date</label><input type="date" name="due_date" id="upd-due"></div>
                <div class="form-group"><label>Notes</label><textarea name="notes" rows="3" style="resize:vertical"></textarea></div>
            </div>
            <div class="modal-foot"><button type="button" class="btn-secondary" onclick="closeModal('updateModal')">Cancel</button><button type="submit" class="btn-primary">Update</button></div>
        </form>
    </div>
</div>

<!-- Bulk Assign Modal -->
<div id="bulkModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-head"><h2>Bulk Assign Compliance</h2><button class="modal-close" onclick="closeModal('bulkModal')">✕</button></div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="bulk_assign">
            <div class="modal-body">
                <div class="form-group"><label>Requirement</label>
                    <select name="requirement_id" required>
                        <option value="">Select Requirement</option>
                        <?php foreach ($requirements as $req): ?><option value="<?php echo $req['id']; ?>"><?php echo htmlspecialchars($req['requirement_name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Due Date</label><input type="date" name="due_date" required></div>
                <div class="form-group"><label>Documents (hold Ctrl/Cmd to select multiple)</label>
                    <select name="document_ids[]" multiple style="height:160px">
                        <?php foreach ($documents as $doc): ?><option value="<?php echo $doc['id']; ?>"><?php echo htmlspecialchars($doc['title']); ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn-secondary" onclick="closeModal('bulkModal')">Cancel</button><button type="submit" class="btn-primary">Assign</button></div>
        </form>
    </div>
</div>

<script>
function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}
function openUpdateModal(id,status,dueDate){
    document.getElementById('upd-id').value=id;
    document.getElementById('upd-status').value=status;
    document.getElementById('upd-due').value=dueDate||'';
    openModal('updateModal');
}
document.querySelectorAll('.modal-overlay').forEach(el=>{
    el.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});
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