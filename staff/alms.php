<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

/* ── PAGE DATA ──────────────────────────────────────────────── */
try {
    $total_active_assets   = $pdo->query("SELECT COUNT(*) FROM assets WHERE status='active'")->fetchColumn();
    $assets_in_maintenance = $pdo->query("SELECT COUNT(*) FROM assets WHERE status='maintenance'")->fetchColumn();
    $upcoming_maintenance  = $pdo->query("SELECT COUNT(*) FROM maintenance_schedule WHERE status='scheduled' AND scheduled_date <= CURDATE() + INTERVAL 7 DAY")->fetchColumn();
    $overdue_maintenance   = $pdo->query("SELECT COUNT(*) FROM maintenance_schedule WHERE status='overdue' OR (status='scheduled' AND scheduled_date < CURDATE())")->fetchColumn();
    $total_asset_value     = $pdo->query("SELECT COALESCE(SUM(current_value),0) FROM assets WHERE status IN ('active','maintenance')")->fetchColumn();
    $completed_this_month  = $pdo->query("SELECT COUNT(*) FROM maintenance_schedule WHERE status='completed' AND MONTH(completed_date)=MONTH(CURDATE()) AND YEAR(completed_date)=YEAR(CURDATE())")->fetchColumn();
    $db_ok = true;
} catch (PDOException $e) {
    $total_active_assets = $assets_in_maintenance = $upcoming_maintenance = $overdue_maintenance = $total_asset_value = $completed_this_month = 0;
    $db_ok = false;
}

$page_title = 'Asset Lifecycle & Maintenance'; $module_subtitle = 'Asset Lifecycle'; $back_btn_href = 'dashboard.php'; $active_nav = 'alms';
include 'includes/head.php';
?>
<body>
<?php include 'includes/topbar.php'; ?>
<div class="layout">
<?php include 'includes/sidebar.php'; ?>
<main class="main-content">

    <div class="page-title">
        <span class="page-title-tag">Module / Assets</span>
        <h1>Asset Lifecycle &amp; <strong>Maintenance System</strong></h1>
        <p>Track assets, schedule maintenance, manage depreciation, and control check-in/out operations.</p>
    </div>

    <?php if (!$db_ok): ?>
    <div class="alert alert-warn"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/></svg>Database connection issue — some data may not display correctly.</div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="stats-row">
        <div class="stat-card success">
            <div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/></svg></div>
            <div class="stat-value"><?php echo number_format($total_active_assets); ?></div>
            <div class="stat-label">Active Assets</div>
        </div>
        <div class="stat-card <?php echo $assets_in_maintenance>0?'warn':''; ?>">
            <div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg></div>
            <div class="stat-value"><?php echo number_format($assets_in_maintenance); ?></div>
            <div class="stat-label">In Maintenance</div>
        </div>
        <div class="stat-card <?php echo $overdue_maintenance>0?'danger':''; ?>">
            <div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
            <div class="stat-value"><?php echo number_format($overdue_maintenance); ?></div>
            <div class="stat-label">Overdue Tasks</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
            <div class="stat-value" style="font-size:1.3rem;">&#8369;<?php echo number_format($total_asset_value,0); ?></div>
            <div class="stat-label">Total Asset Value</div>
        </div>
    </div>

    <!-- FUNCTION CARDS -->
    <span class="section-label">Core Functions</span>
    <div class="functions-grid">
        <div class="fn-card" onclick="window.location.href='check_in_out.php'">
            <div class="fn-card-top">
                <div class="fn-icon"><svg viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg></div>
                <div><div class="fn-title">Asset Check In / Out</div><div class="fn-desc">Log asset movements, record check-in and check-out events, and track who has which assets at any time.</div></div>
            </div>
            <div class="fn-card-foot"><button class="fn-btn" onclick="event.stopPropagation();window.location.href='check_in_out.php'">Manage Check In/Out</button><div class="fn-status"><div class="fn-status-dot"></div>Active</div></div>
        </div>
        <div class="fn-card" onclick="window.location.href='calculate_depreciation.php'">
            <div class="fn-card-top">
                <div class="fn-icon"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
                <div><div class="fn-title">Depreciation Calculator</div><div class="fn-desc">Calculate asset depreciation using straight-line, declining balance, or units-of-production methods. Generate depreciation schedules.</div></div>
            </div>
            <div class="fn-card-foot"><button class="fn-btn" onclick="event.stopPropagation();window.location.href='calculate_depreciation.php'">Calculate Depreciation</button><div class="fn-status"><div class="fn-status-dot"></div>Active</div></div>
        </div>
        <div class="fn-card" onclick="window.location.href='ajax_handler.php?view=assets'">
            <div class="fn-card-top">
                <div class="fn-icon"><svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/></svg></div>
                <div><div class="fn-title">Asset Registry</div><div class="fn-desc">Register new assets, manage asset details, track current value, and maintain a complete asset catalogue.</div></div>
            </div>
            <div class="fn-card-foot"><button class="fn-btn" onclick="event.stopPropagation();openModal('addAssetModal')">Add New Asset</button><div class="fn-status"><div class="fn-status-dot"></div>Active</div></div>
        </div>
        <div class="fn-card" onclick="openModal('scheduleMaintenanceModal')">
            <div class="fn-card-top">
                <div class="fn-icon"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
                <div><div class="fn-title">Schedule Maintenance</div><div class="fn-desc">Schedule preventive or corrective maintenance tasks, assign technicians, and track maintenance history.</div></div>
            </div>
            <div class="fn-card-foot"><button class="fn-btn" onclick="event.stopPropagation();openModal('scheduleMaintenanceModal')">Schedule Task</button><div class="fn-status"><div class="fn-status-dot"></div><?php echo $upcoming_maintenance; ?> Upcoming</div></div>
        </div>
    </div>

    <!-- BOTTOM GRID -->
    <div class="bottom-grid">
        <!-- Overdue / Upcoming maintenance table -->
        <div class="panel">
            <div class="panel-header">
                <span class="panel-title">Maintenance Schedule</span>
                <span class="panel-badge"><?php echo $overdue_maintenance; ?> overdue</span>
            </div>
            <div class="tbl-wrap">
                <table class="data-table">
                    <thead><tr><th>Asset</th><th>Type</th><th>Scheduled</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php
                    try {
                        $ms = $pdo->query("SELECT ms.*, a.asset_name, a.asset_code FROM maintenance_schedule ms LEFT JOIN assets a ON ms.asset_id=a.id ORDER BY ms.scheduled_date ASC LIMIT 10")->fetchAll();
                        if (empty($ms)): ?><tr><td colspan="4" class="empty-td">No maintenance scheduled.</td></tr><?php
                        else: foreach ($ms as $m): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($m['asset_name']??'Unknown'); ?><br><span class="item-code"><?php echo $m['asset_code']??''; ?></span></td>
                            <td><?php echo htmlspecialchars($m['maintenance_type']??'General'); ?></td>
                            <td><?php echo date('M d, Y',strtotime($m['scheduled_date'])); ?></td>
                            <td><span class="badge badge-<?php echo in_array($m['status'],['completed','active'])?'normal':(($m['status']==='overdue'||strtotime($m['scheduled_date'])<time())?'danger':'warn'); ?>"><?php echo ucfirst($m['status']??'scheduled'); ?></span></td>
                        </tr>
                        <?php endforeach; endif;
                    } catch (Exception $e) { echo '<tr><td colspan="4" class="empty-td">Could not load maintenance data.</td></tr>'; }
                    ?>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Quick Actions -->
        <div class="sidebar-stack">
            <div class="panel">
                <div class="panel-header"><span class="panel-title">Summary</span></div>
                <div style="padding:1rem 1.6rem;">
                    <div class="list-item"><div class="li-title">Active Assets</div><div class="li-value"><?php echo number_format($total_active_assets); ?></div></div>
                    <div class="list-item"><div class="li-title">In Maintenance</div><div class="li-value"><?php echo number_format($assets_in_maintenance); ?></div></div>
                    <div class="list-item"><div class="li-title">Upcoming (7 days)</div><div class="li-value"><?php echo number_format($upcoming_maintenance); ?></div></div>
                    <div class="list-item"><div class="li-title">Completed This Month</div><div class="li-value" style="color:var(--success);"><?php echo number_format($completed_this_month); ?></div></div>
                </div>
            </div>
            <div class="panel">
                <div class="panel-header"><span class="panel-title">Quick Actions</span></div>
                <div class="qa-list">
                    <button class="qa-btn qa-blue" onclick="openModal('addAssetModal')"><svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Register New Asset</button>
                    <button class="qa-btn qa-green" onclick="window.location.href='check_in_out.php'"><svg viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>Asset Check In/Out</button>
                    <button class="qa-btn qa-orange" onclick="openModal('scheduleMaintenanceModal')"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Schedule Maintenance</button>
                    <button class="qa-btn qa-teal" onclick="window.location.href='calculate_depreciation.php'"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>Calculate Depreciation</button>
                </div>
            </div>
        </div>
    </div>
</main>
</div>

<!-- ADD ASSET MODAL -->
<div id="addAssetModal" class="modal">
    <div class="modal-box">
        <div class="modal-head"><h3>Register New Asset</h3><button class="modal-close" onclick="closeModal('addAssetModal')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
        <div class="modal-body">
            <form method="POST" action="ajax_handler.php">
                <input type="hidden" name="action" value="add_asset">
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Asset Name <span class="req">*</span></label><input type="text" name="asset_name" class="form-input" required></div>
                    <div class="form-group"><label class="form-label">Asset Code</label><input type="text" name="asset_code" class="form-input" placeholder="Auto-generated if empty"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Category <span class="req">*</span></label>
                        <select name="category" class="form-select" required><option value="">Select…</option><option>IT Equipment</option><option>Furniture</option><option>Vehicles</option><option>Machinery</option><option>Office Equipment</option><option>Tools</option><option>Other</option></select></div>
                    <div class="form-group"><label class="form-label">Status</label>
                        <select name="status" class="form-select"><option value="active">Active</option><option value="maintenance">In Maintenance</option><option value="retired">Retired</option></select></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Purchase Value (₱)</label><input type="number" name="purchase_value" class="form-input" step="0.01" min="0"></div>
                    <div class="form-group"><label class="form-label">Current Value (₱)</label><input type="number" name="current_value" class="form-input" step="0.01" min="0"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Purchase Date</label><input type="date" name="purchase_date" class="form-input"></div>
                    <div class="form-group"><label class="form-label">Location</label><input type="text" name="location" class="form-input"></div>
                </div>
                <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-textarea"></textarea></div>
                <button type="submit" class="submit-btn">Register Asset</button>
            </form>
        </div>
    </div>
</div>

<!-- SCHEDULE MAINTENANCE MODAL -->
<div id="scheduleMaintenanceModal" class="modal">
    <div class="modal-box">
        <div class="modal-head"><h3>Schedule Maintenance</h3><button class="modal-close" onclick="closeModal('scheduleMaintenanceModal')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
        <div class="modal-body">
            <form method="POST" action="ajax_handler.php">
                <input type="hidden" name="action" value="schedule_maintenance">
                <div class="form-group"><label class="form-label">Asset <span class="req">*</span></label>
                    <select name="asset_id" class="form-select" required>
                        <option value="">— Select Asset —</option>
                        <?php try { foreach ($pdo->query("SELECT id,asset_name,asset_code FROM assets WHERE status='active' ORDER BY asset_name")->fetchAll() as $a): ?><option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['asset_name']); ?> (<?php echo $a['asset_code']; ?>)</option><?php endforeach; } catch (Exception $e) {} ?>
                    </select></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Maintenance Type</label>
                        <select name="maintenance_type" class="form-select"><option>Preventive</option><option>Corrective</option><option>Inspection</option><option>Cleaning</option><option>Calibration</option><option>Other</option></select></div>
                    <div class="form-group"><label class="form-label">Scheduled Date <span class="req">*</span></label><input type="date" name="scheduled_date" class="form-input" required></div>
                </div>
                <div class="form-group"><label class="form-label">Assigned To</label><input type="text" name="assigned_to" class="form-input" placeholder="Technician / team name"></div>
                <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" class="form-textarea"></textarea></div>
                <button type="submit" class="submit-btn">Schedule Maintenance</button>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
