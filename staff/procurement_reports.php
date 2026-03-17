<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

/* ── FILTERS ────────────────────────────────────────────────── */
$report_type = $_GET['report']      ?? 'overview';
$date_from   = $_GET['date_from']   ?? date('Y-01-01');
$date_to     = $_GET['date_to']     ?? date('Y-m-d');
$supplier_id = $_GET['supplier_id'] ?? '';

$suppliers = $pdo->query("SELECT id,supplier_name FROM suppliers WHERE status='active' ORDER BY supplier_name")->fetchAll();

/* ── REPORT FUNCTIONS ───────────────────────────────────────── */
function getOverview($pdo, $df, $dt) {
    $r = [];
    $r['total_orders'] = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE order_date BETWEEN ? AND ?")->execute([$df,$dt]) ? $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE order_date BETWEEN ? AND ?")->fetchColumn() : 0;
    $s1 = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE order_date BETWEEN ? AND ?"); $s1->execute([$df,$dt]); $r['total_orders'] = $s1->fetchColumn();
    $s2 = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM purchase_orders WHERE order_date BETWEEN ? AND ?"); $s2->execute([$df,$dt]); $r['total_value'] = $s2->fetchColumn();
    $r['avg_order_value'] = $r['total_orders']>0 ? $r['total_value']/$r['total_orders'] : 0;
    $s3 = $pdo->prepare("SELECT status,COUNT(*) as count FROM purchase_orders WHERE order_date BETWEEN ? AND ? GROUP BY status"); $s3->execute([$df,$dt]); $r['by_status'] = $s3->fetchAll();
    $s4 = $pdo->prepare("SELECT DATE_FORMAT(order_date,'%Y-%m') as month, DATE_FORMAT(order_date,'%M %Y') as label, COUNT(*) as order_count, SUM(total_amount) as total_value FROM purchase_orders WHERE order_date BETWEEN ? AND ? GROUP BY DATE_FORMAT(order_date,'%Y-%m') ORDER BY month"); $s4->execute([$df,$dt]); $r['monthly_trend'] = $s4->fetchAll();
    return $r;
}
function getSupplierPerf($pdo, $df, $dt, $sid) {
    $where = "WHERE po.order_date BETWEEN ? AND ?"; $params = [$df,$dt];
    if ($sid) { $where .= " AND s.id=?"; $params[] = $sid; }
    $s = $pdo->prepare("SELECT s.id,s.supplier_name,s.rating,COUNT(po.id) as total_orders, COALESCE(SUM(po.total_amount),0) as total_value, COALESCE(AVG(po.total_amount),0) as avg_order_value, SUM(CASE WHEN po.status='completed' THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN po.status='cancelled' THEN 1 ELSE 0 END) as cancelled, ROUND((SUM(CASE WHEN po.status='completed' THEN 1 ELSE 0 END)/NULLIF(COUNT(po.id),0))*100,1) as completion_rate FROM suppliers s LEFT JOIN purchase_orders po ON s.id=po.supplier_id $where GROUP BY s.id,s.supplier_name,s.rating HAVING total_orders>0 ORDER BY total_value DESC");
    $s->execute($params); return $s->fetchAll();
}
function getCostAnalysis($pdo, $df, $dt) {
    $s = $pdo->prepare("SELECT COALESCE(ii.category,'Uncategorized') as category, COALESCE(SUM(poi.total_price),0) as total_cost, COUNT(poi.id) as item_count, COALESCE(AVG(poi.unit_price),0) as avg_unit_price FROM purchase_order_items poi JOIN purchase_orders po ON poi.po_id=po.id LEFT JOIN inventory_items ii ON poi.item_id=ii.id WHERE po.order_date BETWEEN ? AND ? GROUP BY ii.category ORDER BY total_cost DESC");
    $s->execute([$df,$dt]); return $s->fetchAll();
}
function getRFQAnalysis($pdo, $df, $dt) {
    $s1 = $pdo->prepare("SELECT COUNT(*) FROM rfq_requests WHERE request_date BETWEEN ? AND ?"); $s1->execute([$df,$dt]);
    $s2 = $pdo->prepare("SELECT status,COUNT(*) as count FROM rfq_requests WHERE request_date BETWEEN ? AND ? GROUP BY status"); $s2->execute([$df,$dt]);
    $s3 = $pdo->prepare("SELECT rr.rfq_number,rr.title,rr.status, COUNT(rs.id) as suppliers_invited, SUM(rs.response_received) as responses FROM rfq_requests rr LEFT JOIN rfq_suppliers rs ON rr.id=rs.rfq_id WHERE rr.request_date BETWEEN ? AND ? GROUP BY rr.id ORDER BY rr.created_at DESC LIMIT 20"); $s3->execute([$df,$dt]);
    return ['total'=>$s1->fetchColumn(),'by_status'=>$s2->fetchAll(),'rfqs'=>$s3->fetchAll()];
}

// Load data for selected report
$data = [];
switch ($report_type) {
    case 'overview':            $data = getOverview($pdo,$date_from,$date_to); break;
    case 'supplier_performance':$data = getSupplierPerf($pdo,$date_from,$date_to,$supplier_id); break;
    case 'cost_analysis':       $data = getCostAnalysis($pdo,$date_from,$date_to); break;
    case 'rfq_analysis':        $data = getRFQAnalysis($pdo,$date_from,$date_to); break;
}

$page_title = 'Procurement Reports'; $module_subtitle = 'Procurement'; $back_btn_href = 'psm.php'; $back_btn_label = 'Procurement'; $active_nav = 'psm';
include 'includes/head.php';
?>
<body>
<?php include 'includes/topbar.php'; ?>
<div class="layout">
<?php include 'includes/sidebar.php'; ?>
<main class="main-content">

    <div class="page-title">
        <span class="page-title-tag">Procurement / Reports</span>
        <h1>Procurement <strong>Reports &amp; Analytics</strong></h1>
        <p>Generate comprehensive reports on procurement activities, spend, and supplier performance.</p>
    </div>

    <!-- FILTER BAR -->
    <form method="GET" style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:1rem 1.4rem;margin-bottom:1.6rem;display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end;">
        <div class="form-group" style="margin:0;">
            <label class="form-label">Report Type</label>
            <select name="report" class="form-select" style="padding:.42rem .8rem;">
                <option value="overview" <?php echo $report_type==='overview'?'selected':''; ?>>Procurement Overview</option>
                <option value="supplier_performance" <?php echo $report_type==='supplier_performance'?'selected':''; ?>>Supplier Performance</option>
                <option value="cost_analysis" <?php echo $report_type==='cost_analysis'?'selected':''; ?>>Cost by Category</option>
                <option value="rfq_analysis" <?php echo $report_type==='rfq_analysis'?'selected':''; ?>>RFQ Analysis</option>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label class="form-label">Date From</label>
            <input type="date" name="date_from" class="form-input" value="<?php echo $date_from; ?>" style="padding:.42rem .8rem;">
        </div>
        <div class="form-group" style="margin:0;">
            <label class="form-label">Date To</label>
            <input type="date" name="date_to" class="form-input" value="<?php echo $date_to; ?>" style="padding:.42rem .8rem;">
        </div>
        <?php if ($report_type === 'supplier_performance'): ?>
        <div class="form-group" style="margin:0;min-width:200px;">
            <label class="form-label">Supplier</label>
            <select name="supplier_id" class="form-select" style="padding:.42rem .8rem;">
                <option value="">All Suppliers</option>
                <?php foreach ($suppliers as $s): ?><option value="<?php echo $s['id']; ?>" <?php echo $supplier_id==$s['id']?'selected':''; ?>><?php echo htmlspecialchars($s['supplier_name']); ?></option><?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">Generate Report</button>
    </form>

    <!-- OVERVIEW REPORT -->
    <?php if ($report_type === 'overview'): ?>
    <div class="stats-row" style="margin-bottom:1.6rem;">
        <div class="stat-card"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div><div class="stat-value"><?php echo number_format($data['total_orders']); ?></div><div class="stat-label">Total Orders</div></div>
        <div class="stat-card"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div><div class="stat-value" style="font-size:1.3rem;">&#8369;<?php echo number_format($data['total_value'],0); ?></div><div class="stat-label">Total Value</div></div>
        <div class="stat-card"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div><div class="stat-value" style="font-size:1.3rem;">&#8369;<?php echo number_format($data['avg_order_value'],0); ?></div><div class="stat-label">Avg. Order Value</div></div>
        <div class="stat-card"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><div class="stat-value"><?php echo count($data['monthly_trend']); ?></div><div class="stat-label">Active Months</div></div>
    </div>
    <div class="main-grid">
        <div class="panel">
            <div class="panel-header"><span class="panel-title">Monthly Trend</span></div>
            <div class="tbl-wrap"><table class="data-table"><thead><tr><th>Month</th><th>Orders</th><th>Total Value</th></tr></thead><tbody>
                <?php if (empty($data['monthly_trend'])): ?><tr><td colspan="3" class="empty-td">No data.</td></tr>
                <?php else: foreach ($data['monthly_trend'] as $mt): ?>
                <tr><td><?php echo $mt['label']; ?></td><td><?php echo $mt['order_count']; ?></td><td><strong>&#8369;<?php echo number_format($mt['total_value'],2); ?></strong></td></tr>
                <?php endforeach; endif; ?>
            </tbody></table></div>
        </div>
        <div class="panel">
            <div class="panel-header"><span class="panel-title">Orders by Status</span></div>
            <?php foreach ($data['by_status'] as $bs): ?>
            <div class="list-item"><div class="li-title"><span class="badge badge-<?php echo $bs['status']; ?>"><?php echo ucfirst($bs['status']); ?></span></div><div class="li-value"><?php echo $bs['count']; ?></div></div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- SUPPLIER PERFORMANCE -->
    <?php elseif ($report_type === 'supplier_performance'): ?>
    <div class="panel">
        <div class="panel-header"><span class="panel-title">Supplier Performance</span><span class="panel-badge"><?php echo date('M d, Y',strtotime($date_from)); ?> – <?php echo date('M d, Y',strtotime($date_to)); ?></span></div>
        <div class="tbl-wrap"><table class="data-table">
            <thead><tr><th>Supplier</th><th>Rating</th><th>Total Orders</th><th>Completed</th><th>Cancelled</th><th>Completion Rate</th><th>Total Value</th></tr></thead>
            <tbody>
                <?php if (empty($data)): ?><tr><td colspan="7" class="empty-td">No performance data for this period.</td></tr>
                <?php else: foreach ($data as $sp): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($sp['supplier_name']); ?></strong></td>
                    <td><?php echo $sp['rating'] ? number_format($sp['rating'],1).'★' : '—'; ?></td>
                    <td><?php echo $sp['total_orders']; ?></td>
                    <td><?php echo $sp['completed']; ?></td>
                    <td><?php echo $sp['cancelled']; ?></td>
                    <td><span class="badge badge-<?php echo $sp['completion_rate']>=80?'normal':($sp['completion_rate']>=50?'warn':'danger'); ?>"><?php echo $sp['completion_rate']; ?>%</span></td>
                    <td><strong>&#8369;<?php echo number_format($sp['total_value'],2); ?></strong></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <!-- COST BY CATEGORY -->
    <?php elseif ($report_type === 'cost_analysis'): ?>
    <div class="panel">
        <div class="panel-header"><span class="panel-title">Cost by Category</span></div>
        <div class="tbl-wrap"><table class="data-table">
            <thead><tr><th>Category</th><th>Items Ordered</th><th>Total Cost</th><th>Avg Unit Price</th></tr></thead>
            <tbody>
                <?php if (empty($data)): ?><tr><td colspan="4" class="empty-td">No data.</td></tr>
                <?php else: foreach ($data as $ca): ?>
                <tr><td><strong><?php echo htmlspecialchars($ca['category']); ?></strong></td><td><?php echo $ca['item_count']; ?></td><td><strong>&#8369;<?php echo number_format($ca['total_cost'],2); ?></strong></td><td>&#8369;<?php echo number_format($ca['avg_unit_price'],2); ?></td></tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <!-- RFQ ANALYSIS -->
    <?php elseif ($report_type === 'rfq_analysis'): ?>
    <div class="stats-row" style="margin-bottom:1.4rem;">
        <div class="stat-card"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></div><div class="stat-value"><?php echo $data['total']; ?></div><div class="stat-label">Total RFQs</div></div>
        <?php foreach ($data['by_status'] as $bs): ?><div class="stat-card"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg></div><div class="stat-value"><?php echo $bs['count']; ?></div><div class="stat-label"><?php echo ucfirst(str_replace('_',' ',$bs['status'])); ?></div></div><?php endforeach; ?>
    </div>
    <div class="panel">
        <div class="panel-header"><span class="panel-title">RFQ Details</span></div>
        <div class="tbl-wrap"><table class="data-table">
            <thead><tr><th>RFQ #</th><th>Title</th><th>Status</th><th>Suppliers Invited</th><th>Responses</th></tr></thead>
            <tbody>
                <?php if (empty($data['rfqs'])): ?><tr><td colspan="5" class="empty-td">No RFQs in this period.</td></tr>
                <?php else: foreach ($data['rfqs'] as $rfq): ?>
                <tr><td><span class="item-code"><?php echo htmlspecialchars($rfq['rfq_number']); ?></span></td><td><?php echo htmlspecialchars($rfq['title']); ?></td><td><span class="badge badge-<?php echo $rfq['status']; ?>"><?php echo ucfirst(str_replace('_',' ',$rfq['status'])); ?></span></td><td><?php echo $rfq['suppliers_invited']; ?></td><td><?php echo $rfq['responses']; ?></td></tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>
</main>
</div>
<?php include 'includes/footer.php'; ?>
