<?php
/**
 * staff/assets.php
 * Staff — Asset Management (CRUD + Check-in/out + Maintenance scheduling)
 */
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/helpers.php';
require_once '../includes/activity_log.php';
require_role('staff');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $actor = $_SESSION['user_name'] ?? 'staff';
    try {
        switch ($_POST['action']) {

            case 'add_asset':
                $year  = date('Y');
                $month = date('m');
                $seq   = (int)db_scalar("SELECT COUNT(*)+1 FROM assets WHERE YEAR(created_at)=? AND MONTH(created_at)=?", [$year, $month]);
                $code  = "AST-{$year}-{$month}-" . str_pad($seq, 4, '0', STR_PAD_LEFT);
                db()->prepare("INSERT INTO assets
                    (asset_code,asset_name,category,brand,model,serial_number,purchase_date,
                     purchase_cost,supplier_id,location,status,condition_rating,warranty_expiry,
                     depreciation_method,useful_life_years,current_value,next_maintenance,
                     maintenance_frequency_days,assigned_to,notes,description,created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $code, trim($_POST['asset_name']), $_POST['category'],
                    trim($_POST['brand'] ?? ''), trim($_POST['model'] ?? ''),
                    trim($_POST['serial_number'] ?? ''), $_POST['purchase_date'] ?: null,
                    (float)($_POST['purchase_cost'] ?? 0),
                    ($_POST['supplier_id'] ? (int)$_POST['supplier_id'] : null),
                    trim($_POST['location'] ?? ''), 'active',
                    $_POST['condition_rating'] ?? 'good',
                    $_POST['warranty_expiry'] ?: null,
                    $_POST['depreciation_method'] ?? 'straight_line',
                    (int)($_POST['useful_life_years'] ?? 5),
                    (float)($_POST['purchase_cost'] ?? 0),   // initial current_value = cost
                    $_POST['next_maintenance'] ?: null,
                    (int)($_POST['maintenance_frequency_days'] ?? 90),
                    trim($_POST['assigned_to'] ?? ''),
                    trim($_POST['notes'] ?? ''), trim($_POST['description'] ?? ''),
                    $actor
                ]);
                $aid = (int)db()->lastInsertId();
                log_activity('add_asset', 'assets', $aid, $_POST['asset_name']);
                redirect_with_flash('assets.php', 'success', "Asset '{$_POST['asset_name']}' registered as {$code}.");

            case 'update_asset':
                db()->prepare("UPDATE assets SET asset_name=?,category=?,brand=?,model=?,
                    serial_number=?,location=?,status=?,condition_rating=?,warranty_expiry=?,
                    assigned_to=?,notes=?,description=?,next_maintenance=?,
                    maintenance_frequency_days=? WHERE id=?")
                ->execute([
                    trim($_POST['asset_name']), $_POST['category'],
                    trim($_POST['brand'] ?? ''), trim($_POST['model'] ?? ''),
                    trim($_POST['serial_number'] ?? ''), trim($_POST['location'] ?? ''),
                    $_POST['status'], $_POST['condition_rating'],
                    $_POST['warranty_expiry'] ?: null,
                    trim($_POST['assigned_to'] ?? ''), trim($_POST['notes'] ?? ''),
                    trim($_POST['description'] ?? ''), $_POST['next_maintenance'] ?: null,
                    (int)($_POST['maintenance_frequency_days'] ?? 90),
                    (int)$_POST['asset_id']
                ]);
                log_activity('update_asset', 'assets', (int)$_POST['asset_id'], $_POST['asset_name']);
                redirect_with_flash('assets.php', 'success', "Asset updated.");

            case 'check_in_out':
                $aid    = (int)$_POST['asset_id'];
                $action = $_POST['check_action']; // 'check_out' | 'check_in'
                $asset  = db_one("SELECT * FROM assets WHERE id=?", [$aid]);
                if (!$asset) redirect_with_flash('assets.php', 'error', 'Asset not found.');
                db()->prepare("INSERT INTO check_in_out_history
                    (asset_id,action,performed_by,person_name,department,purpose,
                     expected_return_date,notes,action_date)
                    VALUES (?,?,?,?,?,?,?,?,NOW())")
                ->execute([
                    $aid, $action, $actor,
                    trim($_POST['person_name'] ?? $actor),
                    trim($_POST['department'] ?? ''),
                    trim($_POST['purpose'] ?? ''),
                    $_POST['expected_return_date'] ?: null,
                    trim($_POST['notes'] ?? '')
                ]);
                $new_status = ($action === 'check_out') ? 'active' : 'active';
                $new_assigned = ($action === 'check_out') ? trim($_POST['person_name'] ?? $actor) : null;
                db()->prepare("UPDATE assets SET assigned_to=? WHERE id=?")
                     ->execute([$new_assigned, $aid]);
                log_activity($action, 'check_in_out_history', $aid, "person={$_POST['person_name']}");
                redirect_with_flash('assets.php', 'success', ucfirst(str_replace('_', ' ', $action)) . " recorded.");

            case 'schedule_maintenance':
                $aid = (int)$_POST['asset_id'];
                db()->prepare("INSERT INTO maintenance_schedule
                    (asset_id,maintenance_type,scheduled_date,description,
                     assigned_technician,estimated_cost,status,created_by)
                    VALUES (?,?,?,?,?,?,?,?)")
                ->execute([
                    $aid, trim($_POST['maintenance_type']),
                    $_POST['scheduled_date'],
                    trim($_POST['description'] ?? ''),
                    trim($_POST['assigned_technician'] ?? ''),
                    (float)($_POST['estimated_cost'] ?? 0),
                    'scheduled', $actor
                ]);
                // Update asset's next_maintenance
                db()->prepare("UPDATE assets SET next_maintenance=?, status='maintenance' WHERE id=?")
                     ->execute([$_POST['scheduled_date'], $aid]);
                log_activity('schedule_maintenance', 'maintenance_schedule', $aid, $_POST['maintenance_type']);
                redirect_with_flash('assets.php', 'success', "Maintenance scheduled.");

            case 'complete_maintenance':
                $mid = (int)$_POST['maintenance_id'];
                db()->prepare("UPDATE maintenance_schedule SET status='completed',
                    completed_date=?,actual_cost=?,completion_notes=? WHERE id=?")
                ->execute([
                    date('Y-m-d'), (float)($_POST['actual_cost'] ?? 0),
                    trim($_POST['completion_notes'] ?? ''), $mid
                ]);
                // Reset asset status + set next maintenance
                $ms = db_one("SELECT * FROM maintenance_schedule WHERE id=?", [$mid]);
                if ($ms) {
                    $freq = (int)db_scalar("SELECT maintenance_frequency_days FROM assets WHERE id=?", [$ms['asset_id']]);
                    $next = date('Y-m-d', strtotime("+{$freq} days"));
                    db()->prepare("UPDATE assets SET status='active', next_maintenance=? WHERE id=?")
                         ->execute([$next, $ms['asset_id']]);
                }
                log_activity('complete_maintenance', 'maintenance_schedule', $mid, '');
                redirect_with_flash('assets.php', 'success', "Maintenance completed.");

            case 'delete_asset':
                db()->prepare("UPDATE assets SET status='disposed' WHERE id=?")->execute([(int)$_POST['asset_id']]);
                log_activity('dispose_asset', 'assets', (int)$_POST['asset_id'], '');
                redirect_with_flash('assets.php', 'success', "Asset disposed.");
        }
    } catch (Throwable $e) {
        error_log('Staff assets error: ' . $e->getMessage());
        redirect_with_flash('assets.php', 'error', 'Operation failed: ' . $e->getMessage());
    }
}

// ── FETCH ─────────────────────────────────────────────────────────
$search  = trim($_GET['search']   ?? '');
$cat     = trim($_GET['category'] ?? '');
$status  = $_GET['status'] ?? 'active';
$tab     = $_GET['tab']   ?? 'assets';

$where = ['a.status != "disposed"']; $params = [];
if ($search) { $where[] = "(a.asset_name LIKE ? OR a.asset_code LIKE ? OR a.serial_number LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }
if ($cat)    { $where[] = "a.category=?";  $params[] = $cat; }
if ($status && $status !== 'all') { $where[] = "a.status=?"; $params[] = $status; }

$assets = db_all("SELECT a.*, s.supplier_name FROM assets a
    LEFT JOIN suppliers s ON a.supplier_id=s.id
    WHERE " . implode(' AND ', $where) . " ORDER BY a.created_at DESC", $params);

$stats = [
    'total'       => db_scalar("SELECT COUNT(*) FROM assets WHERE status!='disposed'"),
    'active'      => db_scalar("SELECT COUNT(*) FROM assets WHERE status='active'"),
    'maintenance' => db_scalar("SELECT COUNT(*) FROM assets WHERE status='maintenance'"),
    'maint_due'   => db_scalar("SELECT COUNT(*) FROM assets WHERE next_maintenance<=CURDATE() AND status='active'"),
    'total_value' => db_scalar("SELECT COALESCE(SUM(current_value),0) FROM assets WHERE status='active'"),
    'warranty_exp'=> db_scalar("SELECT COUNT(*) FROM assets WHERE warranty_expiry<=DATE_ADD(CURDATE(),INTERVAL 30 DAY) AND warranty_expiry>=CURDATE() AND status='active'"),
];

$categories = array_column(db_all("SELECT DISTINCT category FROM assets WHERE category!='' ORDER BY category"), 'category');
$suppliers_list = db_all("SELECT id, supplier_name FROM suppliers WHERE status='active' ORDER BY supplier_name");
$staff_names = array_column(db_all("SELECT CONCAT(first_name,' ',last_name) as n FROM users WHERE status='active' ORDER BY first_name"), 'n');

// Maintenance schedule
$maintenance_list = db_all("SELECT ms.*, a.asset_name, a.asset_code
    FROM maintenance_schedule ms JOIN assets a ON ms.asset_id=a.id
    WHERE ms.status IN ('scheduled','overdue') ORDER BY ms.scheduled_date ASC LIMIT 20");

$recent_checkinout = db_all("SELECT h.*, a.asset_name, a.asset_code
    FROM check_in_out_history h JOIN assets a ON h.asset_id=a.id
    ORDER BY h.action_date DESC LIMIT 10");

$page_title = 'Asset Management'; $page_sub = 'Asset Management'; $back_url = 'dashboard.php';
require_once '../includes/staff/header.php';
?>
<main class="main">
<div class="page-title"><h1>Asset Management</h1><p>Register assets, schedule maintenance, track check-in/out and asset lifecycle</p></div>
<?php echo render_flash(); ?>

<?php if ($stats['maint_due'] > 0): ?>
<div class="alert alert-warn">
    <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <strong>Maintenance Due:</strong>&nbsp;<?php echo (int)$stats['maint_due']; ?> asset(s) have maintenance due today or overdue.
</div>
<?php endif; ?>
<?php if ($stats['warranty_exp'] > 0): ?>
<div class="alert alert-info">
    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <strong>Warranty Expiring:</strong>&nbsp;<?php echo (int)$stats['warranty_exp']; ?> asset(s) have warranties expiring within 30 days.
</div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Total Assets</span><div class="stat-badge"><svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/></svg></div></div><div class="stat-value"><?php echo (int)$stats['total']; ?></div><div class="stat-sub">All active assets</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Operational</span><div class="stat-badge success-badge"><svg viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg></div></div><div class="stat-value"><?php echo (int)$stats['active']; ?></div><div class="stat-sub good">In service</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">In Maintenance</span><div class="stat-badge warn-badge"><svg viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg></div></div><div class="stat-value"><?php echo (int)$stats['maintenance']; ?></div><div class="stat-sub warn">Being serviced</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Maint. Due</span><div class="stat-badge error-badge"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div></div><div class="stat-value"><?php echo (int)$stats['maint_due']; ?></div><div class="stat-sub <?php echo $stats['maint_due']>0?'error':'good'; ?>">Overdue/due today</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Total Value</span><div class="stat-badge"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div></div><div class="stat-value" style="font-size:1.3rem"><?php echo peso((float)$stats['total_value']); ?></div><div class="stat-sub good">Current book value</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Warranty Alert</span><div class="stat-badge warn-badge"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div></div><div class="stat-value"><?php echo (int)$stats['warranty_exp']; ?></div><div class="stat-sub warn">Expiring in 30 days</div></div>
</div>

<!-- Tabs -->
<div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1.5rem">
    <?php foreach (['assets'=>'Asset Register','maintenance'=>'Maintenance Schedule','checkinout'=>'Check-in/out History'] as $t=>$label): ?>
    <a href="?tab=<?php echo $t; ?>" style="display:flex;align-items:center;gap:7px;padding:.6rem 1.1rem;border:1.5px solid <?php echo $tab===$t?'var(--navy)':'var(--border)'; ?>;border-radius:8px;background:<?php echo $tab===$t?'var(--navy)':'var(--white)'; ?>;font-size:.84rem;font-weight:500;color:<?php echo $tab===$t?'#fff':'var(--muted)'; ?>;text-decoration:none"><?php echo $label; ?></a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'assets'): ?>
<!-- Asset Register -->
<div class="panel">
    <div class="controls-bar">
        <h2>Asset Register</h2>
        <div class="btn-row">
            <button onclick="openModal('addAssetModal')" class="btn btn-primary">
                <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Register Asset</button>
        </div>
    </div>
    <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem">
        <input type="hidden" name="tab" value="assets">
        <input type="text" name="search" class="form-control" placeholder="Search assets…" value="<?php echo h($search); ?>" style="max-width:260px">
        <select name="category" class="form-control" style="max-width:160px">
            <option value="">All Categories</option>
            <?php foreach ($categories as $c): ?><option value="<?php echo h($c); ?>"<?php echo $cat===$c?' selected':''; ?>><?php echo h($c); ?></option><?php endforeach; ?>
        </select>
        <select name="status" class="form-control" style="max-width:140px">
            <option value="active"<?php echo $status==='active'?' selected':''; ?>>Active</option>
            <option value="maintenance"<?php echo $status==='maintenance'?' selected':''; ?>>Maintenance</option>
            <option value="all"<?php echo $status==='all'?' selected':''; ?>>All</option>
        </select>
        <button type="submit" class="btn btn-outline"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Filter</button>
    </form>
</div>

<div class="panel" style="padding:0;overflow:hidden">
  <table class="data-table">
    <thead><tr><th>Code</th><th>Asset Name</th><th>Category</th><th>Location</th><th>Condition</th><th>Current Value</th><th>Next Maint.</th><th>Assigned To</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (empty($assets)): ?>
    <tr><td colspan="10"><div class="empty-state"><svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/></svg><h3>No assets found</h3></div></td></tr>
    <?php else: ?>
    <?php foreach ($assets as $a): ?>
    <tr>
        <td><span style="font-family:'DM Mono',monospace;font-size:.8rem"><?php echo h($a['asset_code']); ?></span></td>
        <td><strong><?php echo h($a['asset_name']); ?></strong><?php if ($a['brand']): ?><br><small style="color:var(--muted)"><?php echo h($a['brand'].' '.$a['model']); ?></small><?php endif; ?></td>
        <td><?php echo h($a['category']); ?></td>
        <td><?php echo h($a['location'] ?? '—'); ?></td>
        <td><?php echo render_badge($a['condition_rating'] ?? 'good'); ?></td>
        <td><?php echo peso((float)$a['current_value']); ?></td>
        <td>
            <?php if ($a['next_maintenance']): ?>
                <?php $past = strtotime($a['next_maintenance']) < time(); ?>
                <span style="color:<?php echo $past?'var(--error)':'var(--text)'; ?>;font-weight:<?php echo $past?'600':'400'; ?>">
                    <?php echo fmt_date($a['next_maintenance']); ?>
                    <?php if ($past): ?> <span style="font-size:.72rem">(Overdue)</span><?php endif; ?>
                </span>
            <?php else: echo '—'; endif; ?>
        </td>
        <td><?php echo h($a['assigned_to'] ?? '—'); ?></td>
        <td><?php echo render_badge($a['status']); ?></td>
        <td>
            <div style="display:flex;gap:.4rem;flex-wrap:wrap">
                <button onclick="editAsset(<?php echo h(json_encode($a)); ?>)" class="btn btn-warning btn-sm" title="Edit">
                    <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>
                <button onclick="openCheckModal(<?php echo (int)$a['id']; ?>,'<?php echo h($a['asset_name']); ?>')" class="btn btn-success btn-sm" title="Check in/out">
                    <svg viewBox="0 0 24 24"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg></button>
                <button onclick="openMaintModal(<?php echo (int)$a['id']; ?>,'<?php echo h($a['asset_name']); ?>')" class="btn btn-outline btn-sm" title="Schedule maintenance">
                    <svg viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg></button>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($tab === 'maintenance'): ?>
<!-- Maintenance Schedule -->
<div class="panel">
    <div class="controls-bar"><h2>Scheduled Maintenance</h2></div>
    <?php if (empty($maintenance_list)): ?>
    <div class="empty-state"><svg viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg><h3>No scheduled maintenance</h3></div>
    <?php else: ?>
    <table class="data-table">
        <thead><tr><th>Asset</th><th>Type</th><th>Scheduled</th><th>Technician</th><th>Est. Cost</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($maintenance_list as $ms): ?>
        <tr>
            <td><strong><?php echo h($ms['asset_name']); ?></strong><br><small style="font-family:'DM Mono',monospace;color:var(--muted)"><?php echo h($ms['asset_code']); ?></small></td>
            <td><?php echo h($ms['maintenance_type']); ?></td>
            <td><?php $overdue = strtotime($ms['scheduled_date']) < time() && $ms['status']==='scheduled'; ?>
                <span style="color:<?php echo $overdue?'var(--error)':'var(--text)'; ?>;font-weight:<?php echo $overdue?600:400; ?>">
                    <?php echo fmt_date($ms['scheduled_date']); ?><?php if($overdue): ?> (Overdue)<?php endif; ?>
                </span></td>
            <td><?php echo h($ms['assigned_technician'] ?? '—'); ?></td>
            <td><?php echo peso((float)$ms['estimated_cost']); ?></td>
            <td><?php echo render_badge($ms['status']); ?></td>
            <td>
                <?php if ($ms['status'] === 'scheduled'): ?>
                <button onclick="completeMaint(<?php echo (int)$ms['id']; ?>)" class="btn btn-success btn-sm">Mark Complete</button>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'checkinout'): ?>
<!-- Check-in/out History -->
<div class="panel">
    <div class="controls-bar"><h2>Check-in / Check-out History</h2></div>
    <?php if (empty($recent_checkinout)): ?>
    <div class="empty-state"><h3>No check-in/out history</h3></div>
    <?php else: ?>
    <table class="data-table">
        <thead><tr><th>Asset</th><th>Action</th><th>Person</th><th>Department</th><th>Purpose</th><th>Expected Return</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach ($recent_checkinout as $h): ?>
        <tr>
            <td><strong><?php echo h($h['asset_name']); ?></strong><br><small style="font-family:'DM Mono',monospace;color:var(--muted)"><?php echo h($h['asset_code']); ?></small></td>
            <td><?php echo render_badge($h['action']); ?></td>
            <td><?php echo h($h['person_name'] ?? $h['performed_by']); ?></td>
            <td><?php echo h($h['department'] ?? '—'); ?></td>
            <td><?php echo h($h['purpose'] ?? '—'); ?></td>
            <td><?php echo $h['expected_return_date'] ? fmt_date($h['expected_return_date']) : '—'; ?></td>
            <td><?php echo fmt_date($h['action_date'], 'M j, Y g:i A'); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

</main>

<!-- ADD ASSET MODAL -->
<div id="addAssetModal" class="modal">
  <div class="modal-box" style="max-width:640px">
    <div class="modal-head"><h3>Register New Asset</h3><button class="modal-close" onclick="closeModal('addAssetModal')">&times;</button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="add_asset">
        <div class="form-grid-2">
          <div class="form-field" style="grid-column:1/-1"><label>Asset Name *</label><input type="text" name="asset_name" class="form-control" required></div>
          <div class="form-field"><label>Category *</label>
            <select name="category" class="form-control">
                <?php foreach (['equipment','furniture','vehicles','computers','machinery','tools','other'] as $c): ?>
                <option value="<?php echo $c; ?>"><?php echo ucfirst($c); ?></option>
                <?php endforeach; ?>
            </select></div>
          <div class="form-field"><label>Brand</label><input type="text" name="brand" class="form-control"></div>
          <div class="form-field"><label>Model</label><input type="text" name="model" class="form-control"></div>
          <div class="form-field"><label>Serial Number</label><input type="text" name="serial_number" class="form-control"></div>
          <div class="form-field"><label>Purchase Date</label><input type="date" name="purchase_date" class="form-control" value="<?php echo date('Y-m-d'); ?>"></div>
          <div class="form-field"><label>Purchase Cost</label><input type="number" name="purchase_cost" class="form-control" step="0.01" min="0" value="0"></div>
          <div class="form-field"><label>Supplier</label>
            <select name="supplier_id" class="form-control">
              <option value="">— None —</option>
              <?php foreach ($suppliers_list as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo h($s['supplier_name']); ?></option><?php endforeach; ?>
            </select></div>
          <div class="form-field"><label>Location</label><input type="text" name="location" class="form-control"></div>
          <div class="form-field"><label>Condition</label>
            <select name="condition_rating" class="form-control">
              <?php foreach (['excellent','good','fair','poor','critical'] as $c): ?><option value="<?php echo $c; ?>"<?php echo $c==='good'?' selected':''; ?>><?php echo ucfirst($c); ?></option><?php endforeach; ?>
            </select></div>
          <div class="form-field"><label>Warranty Expiry</label><input type="date" name="warranty_expiry" class="form-control"></div>
          <div class="form-field"><label>Depreciation Method</label>
            <select name="depreciation_method" class="form-control">
              <option value="straight_line">Straight Line</option><option value="declining_balance">Declining Balance</option><option value="sum_of_years">Sum of Years</option>
            </select></div>
          <div class="form-field"><label>Useful Life (years)</label><input type="number" name="useful_life_years" class="form-control" value="5" min="1"></div>
          <div class="form-field"><label>Maintenance Frequency (days)</label><input type="number" name="maintenance_frequency_days" class="form-control" value="90" min="1"></div>
          <div class="form-field"><label>Next Maintenance</label><input type="date" name="next_maintenance" class="form-control"></div>
          <div class="form-field"><label>Assigned To</label><input type="text" name="assigned_to" class="form-control" list="staffList">
            <datalist id="staffList"><?php foreach($staff_names as $sn): ?><option value="<?php echo h($sn); ?>"><?php endforeach; ?></datalist></div>
        </div>
        <div class="form-field"><label>Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
        <div class="form-field"><label>Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('addAssetModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-primary">Register Asset</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- EDIT ASSET MODAL -->
<div id="editAssetModal" class="modal">
  <div class="modal-box" style="max-width:580px">
    <div class="modal-head"><h3>Edit Asset</h3><button class="modal-close" onclick="closeModal('editAssetModal')">&times;</button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="update_asset">
        <input type="hidden" id="ea_id" name="asset_id">
        <div class="form-grid-2">
          <div class="form-field" style="grid-column:1/-1"><label>Asset Name *</label><input type="text" id="ea_name" name="asset_name" class="form-control" required></div>
          <div class="form-field"><label>Category</label><select id="ea_cat" name="category" class="form-control">
              <?php foreach (['equipment','furniture','vehicles','computers','machinery','tools','other'] as $c): ?><option value="<?php echo $c; ?>"><?php echo ucfirst($c); ?></option><?php endforeach; ?>
          </select></div>
          <div class="form-field"><label>Status</label><select id="ea_status" name="status" class="form-control">
              <?php foreach (['active','maintenance','retired'] as $s): ?><option value="<?php echo $s; ?>"><?php echo ucfirst($s); ?></option><?php endforeach; ?>
          </select></div>
          <div class="form-field"><label>Condition</label><select id="ea_cond" name="condition_rating" class="form-control">
              <?php foreach (['excellent','good','fair','poor','critical'] as $c): ?><option value="<?php echo $c; ?>"><?php echo ucfirst($c); ?></option><?php endforeach; ?>
          </select></div>
          <div class="form-field"><label>Location</label><input type="text" id="ea_loc" name="location" class="form-control"></div>
          <div class="form-field"><label>Warranty Expiry</label><input type="date" id="ea_warranty" name="warranty_expiry" class="form-control"></div>
          <div class="form-field"><label>Next Maintenance</label><input type="date" id="ea_nextmaint" name="next_maintenance" class="form-control"></div>
          <div class="form-field"><label>Maintenance Freq. (days)</label><input type="number" id="ea_freq" name="maintenance_frequency_days" class="form-control" min="1"></div>
          <div class="form-field" style="grid-column:1/-1"><label>Assigned To</label><input type="text" id="ea_assigned" name="assigned_to" class="form-control" list="staffList"></div>
        </div>
        <div class="form-field"><label>Notes</label><textarea id="ea_notes" name="notes" class="form-control" rows="2"></textarea></div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('editAssetModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-primary">Update Asset</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- CHECK IN/OUT MODAL -->
<div id="checkModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><h3>Asset Check-in / Check-out</h3><button class="modal-close" onclick="closeModal('checkModal')">&times;</button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="check_in_out">
        <input type="hidden" id="ck_asset_id" name="asset_id">
        <div class="form-field"><label>Asset</label><input type="text" id="ck_asset_name" class="form-control" readonly style="background:var(--off)"></div>
        <div class="form-field"><label>Action *</label>
          <select name="check_action" class="form-control">
            <option value="check_out">Check OUT (Issue to person)</option>
            <option value="check_in">Check IN (Return from person)</option>
          </select></div>
        <div class="form-grid-2">
          <div class="form-field"><label>Person Name *</label><input type="text" name="person_name" class="form-control" required list="staffList"></div>
          <div class="form-field"><label>Department</label><input type="text" name="department" class="form-control"></div>
          <div class="form-field"><label>Purpose</label><input type="text" name="purpose" class="form-control"></div>
          <div class="form-field"><label>Expected Return</label><input type="date" name="expected_return_date" class="form-control"></div>
        </div>
        <div class="form-field"><label>Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('checkModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-primary">Record</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- SCHEDULE MAINTENANCE MODAL -->
<div id="maintModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><h3>Schedule Maintenance</h3><button class="modal-close" onclick="closeModal('maintModal')">&times;</button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="schedule_maintenance">
        <input type="hidden" id="maint_asset_id" name="asset_id">
        <div class="form-field"><label>Asset</label><input type="text" id="maint_asset_name" class="form-control" readonly style="background:var(--off)"></div>
        <div class="form-grid-2">
          <div class="form-field"><label>Maintenance Type *</label>
            <select name="maintenance_type" class="form-control">
              <?php foreach (['Preventive','Corrective','Predictive','Inspection','Calibration','Replacement'] as $mt): ?>
              <option value="<?php echo $mt; ?>"><?php echo $mt; ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="form-field"><label>Scheduled Date *</label><input type="date" name="scheduled_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
          <div class="form-field"><label>Technician</label><input type="text" name="assigned_technician" class="form-control"></div>
          <div class="form-field"><label>Estimated Cost</label><input type="number" name="estimated_cost" class="form-control" step="0.01" min="0" value="0"></div>
        </div>
        <div class="form-field"><label>Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('maintModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-success">Schedule</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- COMPLETE MAINTENANCE MODAL -->
<div id="completeMaintModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><h3>Complete Maintenance</h3><button class="modal-close" onclick="closeModal('completeMaintModal')">&times;</button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="complete_maintenance">
        <input type="hidden" id="cm_maint_id" name="maintenance_id">
        <div class="form-field"><label>Actual Cost</label><input type="number" name="actual_cost" class="form-control" step="0.01" min="0" value="0"></div>
        <div class="form-field"><label>Completion Notes</label><textarea name="completion_notes" class="form-control" rows="3"></textarea></div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('completeMaintModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-success">Mark Complete</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function editAsset(a){
    document.getElementById('ea_id').value=a.id;
    document.getElementById('ea_name').value=a.asset_name||'';
    document.getElementById('ea_cat').value=a.category||'equipment';
    document.getElementById('ea_status').value=a.status||'active';
    document.getElementById('ea_cond').value=a.condition_rating||'good';
    document.getElementById('ea_loc').value=a.location||'';
    document.getElementById('ea_warranty').value=a.warranty_expiry||'';
    document.getElementById('ea_nextmaint').value=a.next_maintenance||'';
    document.getElementById('ea_freq').value=a.maintenance_frequency_days||90;
    document.getElementById('ea_assigned').value=a.assigned_to||'';
    document.getElementById('ea_notes').value=a.notes||'';
    openModal('editAssetModal');
}
function openCheckModal(id,name){
    document.getElementById('ck_asset_id').value=id;
    document.getElementById('ck_asset_name').value=name;
    openModal('checkModal');
}
function openMaintModal(id,name){
    document.getElementById('maint_asset_id').value=id;
    document.getElementById('maint_asset_name').value=name;
    openModal('maintModal');
}
function completeMaint(id){
    document.getElementById('cm_maint_id').value=id;
    openModal('completeMaintModal');
}
</script>
<?php require_once '../includes/staff/footer.php'; ?>
