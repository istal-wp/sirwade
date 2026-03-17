<?php
/**
 * staff/reports.php
 * Staff — Operational Reports (read-only view)
 */
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/helpers.php';
require_role('staff');

// ── All data fetched dynamically from DB ──────────────────────────
try {
    $inventory_summary = db_all("
        SELECT category,
               COUNT(*) as item_count,
               SUM(quantity) as total_qty,
               SUM(quantity*unit_price) as total_value,
               SUM(CASE WHEN quantity<=reorder_level THEN 1 ELSE 0 END) as low_stock
        FROM inventory_items WHERE status='active'
        GROUP BY category ORDER BY total_value DESC");

    $recent_movements = db_all("
        SELECT sm.movement_type, sm.quantity, sm.reason, sm.performed_by, sm.created_at,
               ii.item_name, ii.item_code
        FROM stock_movements sm
        JOIN inventory_items ii ON sm.item_id=ii.id
        ORDER BY sm.created_at DESC LIMIT 15");

    $project_summary = db_all("
        SELECT status,
               COUNT(*) as count,
               AVG(progress_percentage) as avg_progress,
               SUM(budget) as total_budget,
               SUM(actual_cost) as total_spent
        FROM projects GROUP BY status");

    $asset_by_category = db_all("
        SELECT category,
               COUNT(*) as count,
               SUM(current_value) as total_value,
               SUM(CASE WHEN status='maintenance' THEN 1 ELSE 0 END) as in_maint
        FROM assets WHERE status!='disposed'
        GROUP BY category ORDER BY total_value DESC");

    $upcoming_maintenance = db_all("
        SELECT a.asset_name, a.asset_code, a.next_maintenance,
               a.location, a.category,
               DATEDIFF(a.next_maintenance, CURDATE()) as days_until
        FROM assets a
        WHERE a.next_maintenance IS NOT NULL
          AND a.next_maintenance >= CURDATE()
          AND a.status='active'
        ORDER BY a.next_maintenance ASC LIMIT 10");

    $po_summary = db_all("
        SELECT status, COUNT(*) as count,
               SUM(total_amount) as total_value,
               AVG(total_amount) as avg_value
        FROM purchase_orders GROUP BY status");

    $top_suppliers = db_all("
        SELECT s.supplier_name, s.rating,
               COUNT(po.id) as orders,
               COALESCE(SUM(po.total_amount),0) as total_spend
        FROM suppliers s
        LEFT JOIN purchase_orders po ON s.id=po.supplier_id
        WHERE s.status='active'
        GROUP BY s.id ORDER BY total_spend DESC LIMIT 8");

    $my_tasks = db_all("
        SELECT pt.task_name, pt.status, pt.priority, pt.due_date,
               p.project_name
        FROM project_tasks pt
        JOIN projects p ON pt.project_id=p.id
        WHERE pt.assigned_to=?
        ORDER BY pt.due_date ASC, pt.priority DESC LIMIT 15",
        [$_SESSION['user_name'] ?? '']);

    $activity_log = db_all("
        SELECT action, table_name, performed_by, ip_address, created_at, details
        FROM audit_logs ORDER BY created_at DESC LIMIT 20");

    $totals = [
        'inventory_value' => db_scalar("SELECT COALESCE(SUM(quantity*unit_price),0) FROM inventory_items WHERE status='active'"),
        'asset_value'     => db_scalar("SELECT COALESCE(SUM(current_value),0) FROM assets WHERE status='active'"),
        'active_projects' => db_scalar("SELECT COUNT(*) FROM projects WHERE status='active'"),
        'pending_pos'     => db_scalar("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('draft','sent')"),
        'open_tasks'      => db_scalar("SELECT COUNT(*) FROM project_tasks WHERE status NOT IN ('completed','cancelled')"),
        'low_stock_items' => db_scalar("SELECT COUNT(*) FROM inventory_items WHERE quantity<=reorder_level AND status='active'"),
    ];

} catch (Throwable $e) {
    error_log('Staff reports error: ' . $e->getMessage());
    $inventory_summary = $recent_movements = $project_summary = [];
    $asset_by_category = $upcoming_maintenance = $po_summary = [];
    $top_suppliers = $my_tasks = $activity_log = [];
    $totals = array_fill_keys(['inventory_value','asset_value','active_projects','pending_pos','open_tasks','low_stock_items'], 0);
}

$page_title = 'Staff Reports'; $page_sub = 'Reports'; $back_url = 'dashboard.php';
require_once '../includes/staff/header.php';
?>
<main class="main">
<div class="page-title">
    <h1>Operational Reports</h1>
    <p>System-wide performance overview — all data fetched live from the database</p>
</div>

<!-- Summary KPIs -->
<div class="stats-grid">
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Inventory Value</span><div class="stat-badge"><svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg></div></div><div class="stat-value" style="font-size:1.3rem"><?php echo peso((float)$totals['inventory_value']); ?></div><div class="stat-sub good">Active stock</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Asset Value</span><div class="stat-badge"><svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/></svg></div></div><div class="stat-value" style="font-size:1.3rem"><?php echo peso((float)$totals['asset_value']); ?></div><div class="stat-sub good">Book value</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Active Projects</span><div class="stat-badge success-badge"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div></div><div class="stat-value"><?php echo (int)$totals['active_projects']; ?></div><div class="stat-sub good">In progress</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Open POs</span><div class="stat-badge warn-badge"><svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 001.97-1.67L23 6H6"/></svg></div></div><div class="stat-value"><?php echo (int)$totals['pending_pos']; ?></div><div class="stat-sub warn">Awaiting action</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Open Tasks</span><div class="stat-badge"><svg viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg></div></div><div class="stat-value"><?php echo (int)$totals['open_tasks']; ?></div><div class="stat-sub">System-wide</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Low Stock</span><div class="stat-badge error-badge"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div></div><div class="stat-value"><?php echo (int)$totals['low_stock_items']; ?></div><div class="stat-sub <?php echo $totals['low_stock_items']>0?'error':'good'; ?>">Need reorder</div></div>
</div>

<!-- My Tasks -->
<?php if (!empty($my_tasks)): ?>
<div class="panel">
    <div class="panel-head"><h2><svg viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>My Tasks</h2></div>
    <table class="data-table">
        <thead><tr><th>Task</th><th>Project</th><th>Priority</th><th>Due Date</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($my_tasks as $t): ?>
        <tr>
            <td><?php echo h($t['task_name']); ?></td>
            <td><?php echo h($t['project_name']); ?></td>
            <td><?php echo render_badge($t['priority'] ?? 'medium'); ?></td>
            <td><?php if ($t['due_date']): $overdue = strtotime($t['due_date'])<time() && $t['status']!=='completed'; ?><span style="color:<?php echo $overdue?'var(--error)':'var(--text)'; ?>"><?php echo fmt_date($t['due_date']); ?></span><?php else: echo '—'; endif; ?></td>
            <td><?php echo render_badge($t['status']); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Two-column layout for detailed reports -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem">

<!-- Inventory by Category -->
<div class="panel">
    <div class="panel-head"><h2><svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>Inventory by Category</h2></div>
    <?php if (empty($inventory_summary)): ?><div class="no-data">No data.</div>
    <?php else: ?>
    <table class="data-table">
        <thead><tr><th>Category</th><th>Items</th><th>Total Qty</th><th>Value</th><th>Low Stock</th></tr></thead>
        <tbody>
        <?php foreach ($inventory_summary as $row): ?>
        <tr>
            <td><?php echo h($row['category']); ?></td>
            <td><?php echo (int)$row['item_count']; ?></td>
            <td><?php echo number_format((int)$row['total_qty']); ?></td>
            <td><?php echo peso((float)$row['total_value']); ?></td>
            <td><?php echo (int)$row['low_stock'] > 0 ? '<span class="badge badge-warn">'.(int)$row['low_stock'].'</span>' : '<span class="badge badge-active">0</span>'; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Assets by Category -->
<div class="panel">
    <div class="panel-head"><h2><svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/></svg>Assets by Category</h2></div>
    <?php if (empty($asset_by_category)): ?><div class="no-data">No data.</div>
    <?php else: ?>
    <table class="data-table">
        <thead><tr><th>Category</th><th>Count</th><th>Value</th><th>In Maint.</th></tr></thead>
        <tbody>
        <?php foreach ($asset_by_category as $row): ?>
        <tr>
            <td><?php echo h($row['category']); ?></td>
            <td><?php echo (int)$row['count']; ?></td>
            <td><?php echo peso((float)$row['total_value']); ?></td>
            <td><?php echo (int)$row['in_maint'] > 0 ? '<span class="badge badge-warn">'.(int)$row['in_maint'].'</span>' : '0'; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

</div><!-- end 2-col grid -->

<!-- Project Status & Upcoming Maintenance side by side -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem">

<div class="panel">
    <div class="panel-head"><h2><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Project Status Summary</h2></div>
    <?php if (empty($project_summary)): ?><div class="no-data">No data.</div>
    <?php else: ?>
    <table class="data-table">
        <thead><tr><th>Status</th><th>Count</th><th>Avg Progress</th><th>Budget</th></tr></thead>
        <tbody>
        <?php foreach ($project_summary as $row): ?>
        <tr>
            <td><?php echo render_badge($row['status']); ?></td>
            <td><?php echo (int)$row['count']; ?></td>
            <td><?php echo round((float)$row['avg_progress'], 1); ?>%</td>
            <td><?php echo peso((float)$row['total_budget']); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="panel-head"><h2><svg viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>Upcoming Maintenance</h2></div>
    <?php if (empty($upcoming_maintenance)): ?><div class="no-data">No upcoming maintenance scheduled.</div>
    <?php else: ?>
    <?php foreach ($upcoming_maintenance as $m): ?>
    <div class="feed-row">
        <div class="feed-icon"><svg viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg></div>
        <div class="feed-body">
            <h4><?php echo h($m['asset_name']); ?></h4>
            <p><?php echo h($m['category']); ?> &nbsp;|&nbsp; <?php echo h($m['location'] ?? '—'); ?></p>
        </div>
        <div class="feed-time" style="text-align:right">
            <?php echo fmt_date($m['next_maintenance']); ?><br>
            <small style="color:<?php echo (int)$m['days_until']<=7?'var(--error)':'var(--warn)'; ?>"><?php echo (int)$m['days_until']; ?>d</small>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

</div><!-- end 2-col -->

<!-- Top Suppliers -->
<div class="panel">
    <div class="panel-head"><h2><svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>Top Suppliers by Spend</h2></div>
    <?php if (empty($top_suppliers)): ?><div class="no-data">No data.</div>
    <?php else: ?>
    <table class="data-table">
        <thead><tr><th>Supplier</th><th>Orders</th><th>Total Spend</th><th>Rating</th></tr></thead>
        <tbody>
        <?php foreach ($top_suppliers as $s): ?>
        <tr>
            <td><strong><?php echo h($s['supplier_name']); ?></strong></td>
            <td><?php echo (int)$s['orders']; ?></td>
            <td><?php echo peso((float)$s['total_spend']); ?></td>
            <td><?php $r = round((float)($s['rating'] ?? 0), 1); echo $r > 0 ? "⭐ {$r}" : '—'; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Recent Movements -->
<div class="panel">
    <div class="panel-head"><h2><svg viewBox="0 0 24 24"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>Recent Stock Movements</h2></div>
    <?php if (empty($recent_movements)): ?><div class="no-data">No recent movements.</div>
    <?php else: ?>
    <?php foreach ($recent_movements as $m): ?>
    <div class="feed-row">
        <div class="feed-icon"><svg viewBox="0 0 24 24"><?php echo $m['movement_type']==='IN'?'<polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>':'<polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>'; ?></svg></div>
        <div class="feed-body">
            <h4><?php echo h($m['movement_type']); ?>: <?php echo h($m['item_name']); ?></h4>
            <p>Qty: <?php echo number_format((int)$m['quantity']); ?> &nbsp;|&nbsp; <?php echo h($m['reason'] ?: '—'); ?> &nbsp;|&nbsp; <?php echo h($m['performed_by']); ?></p>
        </div>
        <div class="feed-time"><?php echo time_ago($m['created_at']); ?></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Audit / Activity Log -->
<div class="panel">
    <div class="panel-head"><h2><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>Recent Activity Log</h2></div>
    <?php if (empty($activity_log)): ?><div class="no-data">No activity logged yet.</div>
    <?php else: ?>
    <table class="data-table">
        <thead><tr><th>Action</th><th>Table</th><th>Performed By</th><th>Time</th></tr></thead>
        <tbody>
        <?php foreach ($activity_log as $log): ?>
        <tr>
            <td><span style="font-family:'DM Mono',monospace;font-size:.78rem;background:var(--off);padding:.2rem .45rem;border-radius:5px"><?php echo h($log['action']); ?></span></td>
            <td><?php echo h($log['table_name'] ?? '—'); ?></td>
            <td><?php echo h($log['performed_by']); ?></td>
            <td><span style="font-size:.76rem;color:var(--muted)"><?php echo time_ago($log['created_at']); ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

</main>
<?php require_once '../includes/staff/footer.php'; ?>
