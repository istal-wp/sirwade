<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_role('admin');


require_once __DIR__ . '/../includes/db.php';
$pdo = db(); // Shared singleton from includes/db.php
try {
    
    $stats = [];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM purchase_orders");
    $stats['total_pos'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM purchase_orders WHERE status = 'draft'");
    $stats['draft_pos'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM purchase_orders WHERE status = 'confirmed'");
    $stats['confirmed_pos'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM purchase_orders WHERE status = 'completed'");
    $stats['completed_pos'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM rfq_requests");
    $stats['total_rfqs'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM rfq_requests WHERE status IN ('sent', 'under_review')");
    $stats['active_rfqs'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM supplier_quotations");
    $stats['total_quotations'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM supplier_quotations WHERE status IN ('received', 'under_review')");
    $stats['pending_quotations'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM goods_receipts");
    $stats['total_receipts'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM purchase_orders WHERE status != 'cancelled'");
    $stats['total_po_value'] = $stmt->fetch()['total'];

    $stmt = $pdo->prepare("
        SELECT po.po_number, po.order_date, po.status, po.total_amount,
               s.supplier_name, po.created_by
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        ORDER BY po.created_at DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $recent_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT rfq_number, title, request_date, response_deadline, status, created_by
        FROM rfq_requests
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $recent_rfqs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT sq.quotation_number, sq.quotation_date, sq.total_amount, sq.status,
               s.supplier_name, rq.title as rfq_title
        FROM supplier_quotations sq
        LEFT JOIN suppliers s ON sq.supplier_id = s.id
        LEFT JOIN rfq_requests rq ON sq.rfq_id = rq.id
        ORDER BY sq.created_at DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $recent_quotations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT gr.receipt_number, gr.receipt_date, gr.status,
               po.po_number, s.supplier_name, gr.received_by
        FROM goods_receipts gr
        LEFT JOIN purchase_orders po ON gr.po_id = po.id
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        ORDER BY gr.created_at DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $recent_receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    error_log("Procurement management error: " . $e->getMessage());
    $stats = [
        'total_pos' => 0, 'draft_pos' => 0, 'confirmed_pos' => 0, 'completed_pos' => 0,
        'total_rfqs' => 0, 'active_rfqs' => 0, 'total_quotations' => 0, 'pending_quotations' => 0,
        'total_receipts' => 0, 'total_po_value' => 0
    ];
    $recent_pos = [];
    $recent_rfqs = [];
    $recent_quotations = [];
    $recent_receipts = [];
}
?>

<!DOCTYPE html>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurement Management — BRIGHTPATH</title>
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
  <div class="page-title"><h1>Procurement Management</h1><p>Complete procurement data and analytics</p></div>

  <!-- Stats -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Total Purchase Orders</span><div class="stat-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['total_pos']); ?></div>
      <div class="stat-sub">All POs in system</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Draft POs</span><div class="stat-badge warn-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['draft_pos']); ?></div>
      <div class="stat-sub warn">Pending completion</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Confirmed POs</span><div class="stat-badge success-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['confirmed_pos']); ?></div>
      <div class="stat-sub good">Active orders</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">RFQ Requests</span><div class="stat-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['total_rfqs']); ?></div>
      <div class="stat-sub"><?php echo $stats['active_rfqs']; ?> active</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Supplier Quotations</span><div class="stat-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['total_quotations']); ?></div>
      <div class="stat-sub warn"><?php echo $stats['pending_quotations']; ?> pending review</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Total PO Value</span><div class="stat-badge success-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></div></div>
      <div class="stat-value" style="font-size:1.35rem">₱<?php echo number_format($stats['total_po_value'], 2); ?></div>
      <div class="stat-sub good">Current orders</div>
    </div>
  </div>

  <!-- Recent POs -->
  <div class="panel">
    <div class="panel-head">
      <h2><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Recent Purchase Orders</h2>
      <span>Latest 10 records</span>
    </div>
    <?php if (!empty($recent_pos)): ?>
    <table class="data-table">
      <thead><tr><th>PO Number</th><th>Supplier</th><th>Order Date</th><th>Status</th><th>Total Amount</th><th>Created By</th></tr></thead>
      <tbody>
        <?php foreach ($recent_pos as $po): ?>
        <tr>
          <td><strong style="font-family:'DM Mono',monospace;font-size:.82rem"><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
          <td><?php echo htmlspecialchars($po['supplier_name'] ?? 'N/A'); ?></td>
          <td><?php echo date('M j, Y', strtotime($po['order_date'])); ?></td>
          <td><span class="badge status-<?php echo $po['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $po['status'])); ?></span></td>
          <td style="font-weight:600;color:var(--success)">₱<?php echo number_format($po['total_amount'], 2); ?></td>
          <td><?php echo htmlspecialchars($po['created_by']); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?><div class="no-data">No purchase orders found.</div><?php endif; ?>
  </div>

  <!-- Recent RFQs -->
  <div class="panel">
    <div class="panel-head">
      <h2><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Recent RFQ Requests</h2>
      <span>Latest 10 records</span>
    </div>
    <?php if (!empty($recent_rfqs)): ?>
    <table class="data-table">
      <thead><tr><th>RFQ Number</th><th>Title</th><th>Request Date</th><th>Response Deadline</th><th>Status</th><th>Created By</th></tr></thead>
      <tbody>
        <?php foreach ($recent_rfqs as $rfq): ?>
        <tr>
          <td><strong style="font-family:'DM Mono',monospace;font-size:.82rem"><?php echo htmlspecialchars($rfq['rfq_number']); ?></strong></td>
          <td><?php echo htmlspecialchars($rfq['title']); ?></td>
          <td><?php echo date('M j, Y', strtotime($rfq['request_date'])); ?></td>
          <td><?php echo date('M j, Y', strtotime($rfq['response_deadline'])); ?></td>
          <td><span class="badge status-<?php echo $rfq['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $rfq['status'])); ?></span></td>
          <td><?php echo htmlspecialchars($rfq['created_by'] ?? 'N/A'); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?><div class="no-data">No RFQ requests found.</div><?php endif; ?>
  </div>

  <!-- Recent Quotations -->
  <div class="panel">
    <div class="panel-head">
      <h2><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>Recent Supplier Quotations</h2>
      <span>Latest 10 records</span>
    </div>
    <?php if (!empty($recent_quotations)): ?>
    <table class="data-table">
      <thead><tr><th>Quotation #</th><th>Supplier</th><th>RFQ Title</th><th>Date</th><th>Total Amount</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($recent_quotations as $quote): ?>
        <tr>
          <td><strong style="font-family:'DM Mono',monospace;font-size:.82rem"><?php echo htmlspecialchars($quote['quotation_number'] ?? 'N/A'); ?></strong></td>
          <td><?php echo htmlspecialchars($quote['supplier_name'] ?? 'N/A'); ?></td>
          <td><?php echo htmlspecialchars($quote['rfq_title'] ?? 'N/A'); ?></td>
          <td><?php echo date('M j, Y', strtotime($quote['quotation_date'])); ?></td>
          <td style="font-weight:600;color:var(--success)">₱<?php echo number_format($quote['total_amount'], 2); ?></td>
          <td><span class="badge status-<?php echo $quote['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $quote['status'])); ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?><div class="no-data">No supplier quotations found.</div><?php endif; ?>
  </div>

  <!-- Recent Receipts -->
  <div class="panel">
    <div class="panel-head">
      <h2><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>Recent Goods Receipts</h2>
      <span>Latest 10 records</span>
    </div>
    <?php if (!empty($recent_receipts)): ?>
    <table class="data-table">
      <thead><tr><th>Receipt #</th><th>PO Number</th><th>Supplier</th><th>Receipt Date</th><th>Status</th><th>Received By</th></tr></thead>
      <tbody>
        <?php foreach ($recent_receipts as $r): ?>
        <tr>
          <td><strong style="font-family:'DM Mono',monospace;font-size:.82rem"><?php echo htmlspecialchars($r['receipt_number']); ?></strong></td>
          <td><?php echo htmlspecialchars($r['po_number'] ?? 'N/A'); ?></td>
          <td><?php echo htmlspecialchars($r['supplier_name'] ?? 'N/A'); ?></td>
          <td><?php echo date('M j, Y', strtotime($r['receipt_date'])); ?></td>
          <td><span class="badge status-<?php echo $r['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $r['status'])); ?></span></td>
          <td><?php echo htmlspecialchars($r['received_by'] ?? 'N/A'); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?><div class="no-data">No goods receipts found.</div><?php endif; ?>
  </div>
</main>

<script>
document.querySelectorAll('.data-table tbody tr').forEach(row => {
    row.addEventListener('mouseenter', () => { row.style.transition = 'background .15s'; });
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