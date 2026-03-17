<?php
/**
 * staff/dashboard.php
 * Staff Portal — Dashboard
 * Monitoring + task entry point for all staff modules.
 */
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/helpers.php';
require_role('staff');

// ── Dynamic KPIs from DB ──────────────────────────────────────────
try {
    $stats = [];
    $stats['my_tasks']        = db_scalar("SELECT COUNT(*) FROM project_tasks WHERE assigned_to = ? AND status NOT IN ('completed','cancelled')", [$_SESSION['user_name'] ?? '']);
    $stats['total_projects']  = db_scalar("SELECT COUNT(*) FROM projects WHERE status = 'active'");
    $stats['low_stock']       = db_scalar("SELECT COUNT(*) FROM inventory_items WHERE quantity <= reorder_level AND status = 'active'");
    $stats['assets']          = db_scalar("SELECT COUNT(*) FROM assets WHERE status = 'active'");
    $stats['pending_pos']     = db_scalar("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('draft','sent')");
    $stats['maint_due']       = db_scalar("SELECT COUNT(*) FROM assets WHERE next_maintenance <= CURDATE() AND status = 'active'");
    $stats['open_workflows']  = db_scalar("SELECT COUNT(*) FROM document_workflows WHERE status = 'pending'");
    $stats['my_open_requests']= db_scalar("SELECT COUNT(*) FROM purchase_requests WHERE requested_by = ? AND status = 'pending'", [$_SESSION['user_name'] ?? '']);

    // Recent activity feed (last 8 actions across key tables)
    $recent = db_all("
        SELECT 'Inventory' as module, item_name as label, created_at, status
          FROM inventory_items ORDER BY created_at DESC LIMIT 3
    ");
    $recent2 = db_all("
        SELECT 'Project Task' as module, task_name as label, created_at, status
          FROM project_tasks ORDER BY created_at DESC LIMIT 3
    ");
    $recent3 = db_all("
        SELECT 'Asset' as module, asset_name as label, created_at, status
          FROM assets ORDER BY created_at DESC LIMIT 2
    ");
    $feed = array_merge($recent, $recent2, $recent3);
    usort($feed, fn($a,$b) => strtotime($b['created_at']) - strtotime($a['created_at']));
    $feed = array_slice($feed, 0, 8);

    // Alerts
    $alerts = [];
    if ($stats['low_stock'] > 0)
        $alerts[] = ['warn', "⚠ {$stats['low_stock']} inventory item(s) are below reorder level."];
    if ($stats['maint_due'] > 0)
        $alerts[] = ['warn', "⚠ {$stats['maint_due']} asset(s) have maintenance due today or overdue."];
    if ($stats['open_workflows'] > 0)
        $alerts[] = ['info', "ℹ {$stats['open_workflows']} document workflow step(s) are pending approval."];

} catch (Throwable $e) {
    error_log('Staff dashboard error: ' . $e->getMessage());
    $stats = array_fill_keys(['my_tasks','total_projects','low_stock','assets','pending_pos','maint_due','open_workflows','my_open_requests'], 0);
    $feed = []; $alerts = [];
}

$page_title = 'Staff Dashboard';
$page_sub   = 'Staff Portal';
require_once '../includes/staff/header.php';
?>
<main class="main">

<div class="page-title">
    <h1>Welcome, <?php echo current_user_name(); ?></h1>
    <p>Staff operations portal — manage inventory, assets, projects, procurement and documents</p>
</div>

<?php foreach ($alerts as [$type, $msg]): ?>
<div class="alert alert-<?php echo $type; ?>">
    <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <?php echo h($msg); ?>
</div>
<?php endforeach; ?>

<?php echo render_flash(); ?>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">My Open Tasks</span>
            <div class="stat-badge"><svg viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg></div></div>
        <div class="stat-value"><?php echo $stats['my_tasks']; ?></div>
        <div class="stat-sub pend"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Assigned to me</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Active Projects</span>
            <div class="stat-badge"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div></div>
        <div class="stat-value"><?php echo $stats['total_projects']; ?></div>
        <div class="stat-sub good"><svg viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/></svg>In progress</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Low Stock Items</span>
            <div class="stat-badge warn-badge"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div></div>
        <div class="stat-value"><?php echo $stats['low_stock']; ?></div>
        <div class="stat-sub warn">Need reordering</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Active Assets</span>
            <div class="stat-badge"><svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/></svg></div></div>
        <div class="stat-value"><?php echo $stats['assets']; ?></div>
        <div class="stat-sub good">Under management</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Pending POs</span>
            <div class="stat-badge"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div></div>
        <div class="stat-value"><?php echo $stats['pending_pos']; ?></div>
        <div class="stat-sub pend">Awaiting action</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Maintenance Due</span>
            <div class="stat-badge error-badge"><svg viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg></div></div>
        <div class="stat-value"><?php echo $stats['maint_due']; ?></div>
        <div class="stat-sub <?php echo $stats['maint_due'] > 0 ? 'error' : 'good'; ?>">Assets due/overdue</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Open Workflows</span>
            <div class="stat-badge"><svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div></div>
        <div class="stat-value"><?php echo $stats['open_workflows']; ?></div>
        <div class="stat-sub pend">Pending approval</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">My Requests</span>
            <div class="stat-badge success-badge"><svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 001.97-1.67L23 6H6"/></svg></div></div>
        <div class="stat-value"><?php echo $stats['my_open_requests']; ?></div>
        <div class="stat-sub pend">Open purchase requests</div>
    </div>
</div>

<!-- Module Navigation -->
<div class="section-label">Staff Modules</div>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;margin-bottom:2rem">

    <a href="inventory.php" style="display:block;background:#fff;border:1px solid var(--border);border-radius:12px;padding:1.25rem 1.4rem;text-decoration:none;color:inherit;transition:box-shadow .2s,transform .15s,border-color .2s;" onmouseover="this.style.boxShadow='0 6px 22px rgba(15,31,61,.1)';this.style.transform='translateY(-2px)';this.style.borderColor='rgba(61,127,255,.3)'" onmouseout="this.style.boxShadow='';this.style.transform='';this.style.borderColor=''">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:.7rem">
            <div style="width:38px;height:38px;background:linear-gradient(135deg,#0f1f3d,#2c4a8a);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:rgba(255,255,255,.9);fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
            </div>
            <h3 style="font-size:.95rem;font-weight:600;color:#0f1f3d">Inventory Management</h3>
        </div>
        <p style="font-size:.82rem;color:#6b7a99;line-height:1.55">Add/edit items, manage stock levels, record movements, and handle reorder alerts.</p>
    </a>

    <a href="assets.php" style="display:block;background:#fff;border:1px solid var(--border);border-radius:12px;padding:1.25rem 1.4rem;text-decoration:none;color:inherit;transition:box-shadow .2s,transform .15s,border-color .2s;" onmouseover="this.style.boxShadow='0 6px 22px rgba(15,31,61,.1)';this.style.transform='translateY(-2px)';this.style.borderColor='rgba(61,127,255,.3)'" onmouseout="this.style.boxShadow='';this.style.transform='';this.style.borderColor=''">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:.7rem">
            <div style="width:38px;height:38px;background:linear-gradient(135deg,#0f1f3d,#2c4a8a);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:rgba(255,255,255,.9);fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/></svg>
            </div>
            <h3 style="font-size:.95rem;font-weight:600;color:#0f1f3d">Asset Management</h3>
        </div>
        <p style="font-size:.82rem;color:#6b7a99;line-height:1.55">Register assets, schedule maintenance, track check-in/out, and record movements.</p>
    </a>

    <a href="projects.php" style="display:block;background:#fff;border:1px solid var(--border);border-radius:12px;padding:1.25rem 1.4rem;text-decoration:none;color:inherit;transition:box-shadow .2s,transform .15s,border-color .2s;" onmouseover="this.style.boxShadow='0 6px 22px rgba(15,31,61,.1)';this.style.transform='translateY(-2px)';this.style.borderColor='rgba(61,127,255,.3)'" onmouseout="this.style.boxShadow='';this.style.transform='';this.style.borderColor=''">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:.7rem">
            <div style="width:38px;height:38px;background:linear-gradient(135deg,#0f1f3d,#2c4a8a);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:rgba(255,255,255,.9);fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
            <h3 style="font-size:.95rem;font-weight:600;color:#0f1f3d">Project Management</h3>
        </div>
        <p style="font-size:.82rem;color:#6b7a99;line-height:1.55">Create projects, manage tasks and milestones, track progress and resource allocation.</p>
    </a>

    <a href="suppliers.php" style="display:block;background:#fff;border:1px solid var(--border);border-radius:12px;padding:1.25rem 1.4rem;text-decoration:none;color:inherit;transition:box-shadow .2s,transform .15s,border-color .2s;" onmouseover="this.style.boxShadow='0 6px 22px rgba(15,31,61,.1)';this.style.transform='translateY(-2px)';this.style.borderColor='rgba(61,127,255,.3)'" onmouseout="this.style.boxShadow='';this.style.transform='';this.style.borderColor=''">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:.7rem">
            <div style="width:38px;height:38px;background:linear-gradient(135deg,#0f1f3d,#2c4a8a);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:rgba(255,255,255,.9);fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            </div>
            <h3 style="font-size:.95rem;font-weight:600;color:#0f1f3d">Supplier Management</h3>
        </div>
        <p style="font-size:.82rem;color:#6b7a99;line-height:1.55">Add suppliers, manage contacts, submit RFQs, and evaluate supplier performance.</p>
    </a>

    <a href="procurement.php" style="display:block;background:#fff;border:1px solid var(--border);border-radius:12px;padding:1.25rem 1.4rem;text-decoration:none;color:inherit;transition:box-shadow .2s,transform .15s,border-color .2s;" onmouseover="this.style.boxShadow='0 6px 22px rgba(15,31,61,.1)';this.style.transform='translateY(-2px)';this.style.borderColor='rgba(61,127,255,.3)'" onmouseout="this.style.boxShadow='';this.style.transform='';this.style.borderColor=''">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:.7rem">
            <div style="width:38px;height:38px;background:linear-gradient(135deg,#0f1f3d,#2c4a8a);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:rgba(255,255,255,.9);fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 001.97-1.67L23 6H6"/></svg>
            </div>
            <h3 style="font-size:.95rem;font-weight:600;color:#0f1f3d">Procurement</h3>
        </div>
        <p style="font-size:.82rem;color:#6b7a99;line-height:1.55">Create purchase requests, manage RFQs, process purchase orders and goods receipts.</p>
    </a>

    <a href="documents.php" style="display:block;background:#fff;border:1px solid var(--border);border-radius:12px;padding:1.25rem 1.4rem;text-decoration:none;color:inherit;transition:box-shadow .2s,transform .15s,border-color .2s;" onmouseover="this.style.boxShadow='0 6px 22px rgba(15,31,61,.1)';this.style.transform='translateY(-2px)';this.style.borderColor='rgba(61,127,255,.3)'" onmouseout="this.style.boxShadow='';this.style.transform='';this.style.borderColor=''">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:.7rem">
            <div style="width:38px;height:38px;background:linear-gradient(135deg,#0f1f3d,#2c4a8a);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:rgba(255,255,255,.9);fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
            </div>
            <h3 style="font-size:.95rem;font-weight:600;color:#0f1f3d">Documents & Compliance</h3>
        </div>
        <p style="font-size:.82rem;color:#6b7a99;line-height:1.55">Upload/manage documents, track compliance requirements, and handle workflow steps.</p>
    </a>

    <a href="reports.php" style="display:block;background:#fff;border:1px solid var(--border);border-radius:12px;padding:1.25rem 1.4rem;text-decoration:none;color:inherit;transition:box-shadow .2s,transform .15s,border-color .2s;" onmouseover="this.style.boxShadow='0 6px 22px rgba(15,31,61,.1)';this.style.transform='translateY(-2px)';this.style.borderColor='rgba(61,127,255,.3)'" onmouseout="this.style.boxShadow='';this.style.transform='';this.style.borderColor=''">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:.7rem">
            <div style="width:38px;height:38px;background:linear-gradient(135deg,#0f1f3d,#2c4a8a);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:rgba(255,255,255,.9);fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            </div>
            <h3 style="font-size:.95rem;font-weight:600;color:#0f1f3d">My Reports</h3>
        </div>
        <p style="font-size:.82rem;color:#6b7a99;line-height:1.55">View operational reports, generate activity summaries, and export data.</p>
    </a>

</div>

<!-- Recent Activity Feed -->
<div class="panel">
    <div class="panel-head">
        <h2>
            <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            Recent Activity
        </h2>
    </div>
    <?php if (!empty($feed)): ?>
        <?php foreach ($feed as $row): ?>
        <div class="feed-row">
            <div class="feed-icon">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="feed-body">
                <h4><?php echo h($row['module']); ?>: <?php echo h($row['label'] ?? '—'); ?></h4>
                <p>Status: <?php echo h(ucfirst($row['status'] ?? 'N/A')); ?></p>
            </div>
            <div class="feed-time"><?php echo fmt_date($row['created_at'], 'M j, Y'); ?></div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="no-data">No recent activity to display.</div>
    <?php endif; ?>
</div>

</main>
<?php require_once '../includes/staff/footer.php'; ?>
