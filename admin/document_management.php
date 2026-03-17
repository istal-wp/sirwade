<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_role('admin');


require_once __DIR__ . '/../includes/db.php';
$pdo = db(); // Shared singleton from includes/db.php
try {
    
    $stats = [];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM documents");
    $stats['total_documents'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM documents WHERE status = 'active'");
    $stats['active_documents'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM documents WHERE status = 'pending_approval'");
    $stats['pending_approval'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM documents WHERE status = 'expired'");
    $stats['expired_documents'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM document_types WHERE is_active = 1");
    $stats['document_types'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM compliance_requirements WHERE is_active = 1");
    $stats['compliance_requirements'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM documents WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND status != 'expired'");
    $stats['expiring_soon'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM document_workflows WHERE status = 'pending'");
    $stats['pending_workflows'] = $stmt->fetch()['count'];

    $stmt = $pdo->prepare("
        SELECT d.*, dt.type_name, 
               CASE 
                   WHEN d.expiry_date <= CURDATE() THEN 'Expired'
                   WHEN d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'Expiring Soon'
                   ELSE 'Valid'
               END as expiry_status
        FROM documents d
        LEFT JOIN document_types dt ON d.document_type_id = dt.id
        ORDER BY d.created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $recent_documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT * FROM document_types WHERE is_active = 1 ORDER BY type_name");
    $document_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("
        SELECT cr.requirement_name, 
               COUNT(dc.id) as total_documents,
               SUM(CASE WHEN dc.compliance_status = 'compliant' THEN 1 ELSE 0 END) as compliant,
               SUM(CASE WHEN dc.compliance_status = 'non_compliant' THEN 1 ELSE 0 END) as non_compliant,
               SUM(CASE WHEN dc.compliance_status = 'pending' THEN 1 ELSE 0 END) as pending
        FROM compliance_requirements cr
        LEFT JOIN document_compliance dc ON cr.id = dc.requirement_id
        WHERE cr.is_active = 1
        GROUP BY cr.id, cr.requirement_name
        ORDER BY cr.requirement_name
        LIMIT 5
    ");
    $stmt->execute();
    $compliance_overview = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("
        SELECT d.title, dw.step_name, dw.assigned_to, dw.status, dw.due_date, dw.created_at
        FROM document_workflows dw
        JOIN documents d ON dw.document_id = d.id
        WHERE dw.status IN ('pending', 'rejected')
        ORDER BY dw.due_date ASC, dw.created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $workflow_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    error_log("Document Management error: " . $e->getMessage());
    $stats = [
        'total_documents' => 0, 'active_documents' => 0, 'pending_approval' => 0,
        'expired_documents' => 0, 'document_types' => 0, 'compliance_requirements' => 0,
        'expiring_soon' => 0, 'pending_workflows' => 0
    ];
    $recent_documents = [];
    $document_types = [];
    $compliance_overview = [];
    $workflow_items = [];
}
?>

<!DOCTYPE html>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Management — BRIGHTPATH</title>
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
                    <p>Document Management</p>
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
  <div class="page-title"><h1>Document Management</h1><p>Centralized document control, workflows, and compliance management</p></div>

  <?php if ($stats['expiring_soon'] > 0): ?>
  <div class="alert alert-warn">
    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <span><strong>Expiry Alert:</strong> <?php echo $stats['expiring_soon']; ?> documents are expiring within the next 30 days.</span>
  </div>
  <?php endif; ?>

  <?php if ($stats['pending_workflows'] > 0): ?>
  <div class="alert alert-info">
    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <span><strong>Workflow Alert:</strong> <?php echo $stats['pending_workflows']; ?> workflow approvals are pending action.</span>
  </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Total Documents</span><div class="stat-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['total_documents']); ?></div>
      <div class="stat-sub">All documents</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Active Documents</span><div class="stat-badge success-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['active_documents']); ?></div>
      <div class="stat-sub good">Currently active</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Pending Approval</span><div class="stat-badge warn-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['pending_approval']); ?></div>
      <div class="stat-sub warn">Awaiting approval</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Expiring Soon</span><div class="stat-badge error-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['expiring_soon']); ?></div>
      <div class="stat-sub bad">Next 30 days</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Document Types</span><div class="stat-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['document_types']); ?></div>
      <div class="stat-sub">Categories</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Compliance Rules</span><div class="stat-badge success-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['compliance_requirements']); ?></div>
      <div class="stat-sub good">Active requirements</div>
    </div>
  </div>

  <!-- Two-column: Recent Docs + Compliance -->
  <div class="content-grid">
    <!-- Recent Documents -->
    <div class="panel">
      <div class="panel-head">
        <h2><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Recent Documents</h2>
        <a href="list_documents.php">View All</a>
      </div>
      <?php if (!empty($recent_documents)): ?>
        <?php foreach ($recent_documents as $doc): ?>
        <div class="doc-item">
          <div class="doc-icon-box">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
          </div>
          <div class="doc-body">
            <h4><?php echo htmlspecialchars($doc['title']); ?></h4>
            <p>Type: <?php echo htmlspecialchars($doc['type_name'] ?? 'N/A'); ?> &nbsp;|&nbsp; <?php echo date('M j, Y', strtotime($doc['created_at'])); ?></p>
            <div class="doc-tags">
              <span class="badge status-<?php echo $doc['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $doc['status'])); ?></span>
              <span class="badge priority-<?php echo $doc['priority']; ?>"><?php echo ucfirst($doc['priority']); ?></span>
              <?php if ($doc['expiry_date']): ?>
              <span class="badge <?php echo $doc['expiry_status'] === 'Expired' ? 'status-inactive' : ($doc['expiry_status'] === 'Expiring Soon' ? 'status-pending' : 'status-active'); ?>"><?php echo $doc['expiry_status']; ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
      <div class="no-data">No documents found.</div>
      <?php endif; ?>
    </div>

    <!-- Compliance Overview -->
    <div class="panel">
      <div class="panel-head">
        <h2><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>Compliance Overview</h2>
        <a href="compliance.php">View Details</a>
      </div>
      <?php if (!empty($compliance_overview)): ?>
        <?php foreach ($compliance_overview as $c): ?>
        <div class="compliance-item">
          <div class="compliance-name"><?php echo htmlspecialchars($c['requirement_name']); ?></div>
          <div class="compliance-stats">
            <?php if ($c['compliant'] > 0): ?>
            <span class="comp-stat compliant">
              <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
              <?php echo $c['compliant']; ?> Compliant
            </span>
            <?php endif; ?>
            <?php if ($c['non_compliant'] > 0): ?>
            <span class="comp-stat non-compliant">
              <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              <?php echo $c['non_compliant']; ?> Non-compliant
            </span>
            <?php endif; ?>
            <?php if ($c['pending'] > 0): ?>
            <span class="comp-stat pending-comp">
              <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              <?php echo $c['pending']; ?> Pending
            </span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
      <div class="no-data">No compliance requirements found.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Workflow Status -->
  <div class="panel">
    <div class="panel-head">
      <h2><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>Workflow Status</h2>
      <a href="workflows.php">View All Workflows</a>
    </div>
    <?php if (!empty($workflow_items)): ?>
      <?php foreach ($workflow_items as $wf): ?>
      <div class="workflow-item">
        <div class="wf-status-dot <?php echo $wf['status']; ?>">
          <?php if ($wf['status'] === 'pending'): ?>
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          <?php else: ?>
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          <?php endif; ?>
        </div>
        <div class="wf-body">
          <h4><?php echo htmlspecialchars($wf['title']); ?></h4>
          <p>Step: <?php echo htmlspecialchars($wf['step_name']); ?> &nbsp;|&nbsp; Assigned to: <?php echo htmlspecialchars($wf['assigned_to']); ?></p>
        </div>
        <div class="wf-time">
          <?php if ($wf['due_date']): ?><p>Due: <?php echo date('M j, Y', strtotime($wf['due_date'])); ?></p><?php endif; ?>
          <p><?php echo date('M j, Y', strtotime($wf['created_at'])); ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    <?php else: ?>
    <div class="no-data">No pending workflow items.</div>
    <?php endif; ?>
  </div>
</main>

<script>
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