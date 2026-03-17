<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

/* ── FILTERS ────────────────────────────────────────────────── */
$current_month = date('Y-m'); $current_year = date('Y'); $last_month = date('Y-m', strtotime('-1 month'));
$selected_period   = $_GET['period']      ?? 'current_year';
$selected_supplier = $_GET['supplier_id'] ?? 'all';

$period_map = [
    'current_month' => ["DATE_FORMAT(po.created_at,'%Y-%m')='$current_month'", 'Current Month'],
    'last_month'    => ["DATE_FORMAT(po.created_at,'%Y-%m')='$last_month'", 'Last Month'],
    'last_3_months' => ["po.created_at>=DATE_SUB(CURDATE(),INTERVAL 3 MONTH)", 'Last 3 Months'],
    'last_6_months' => ["po.created_at>=DATE_SUB(CURDATE(),INTERVAL 6 MONTH)", 'Last 6 Months'],
    'current_year'  => ["YEAR(po.created_at)=$current_year", 'Current Year'],
];
[$date_condition, $period_label] = $period_map[$selected_period] ?? $period_map['current_year'];
$sup_cond = $selected_supplier !== 'all' ? ' AND po.supplier_id=' . intval($selected_supplier) : '';

/* ── QUERIES ────────────────────────────────────────────────── */
$overview = $pdo->prepare("SELECT COUNT(*) as total_orders, COALESCE(SUM(po.total_amount),0) as total_spend, COALESCE(AVG(po.total_amount),0) as avg_order_value, COALESCE(SUM(po.tax_amount),0) as total_tax, COALESCE(SUM(po.shipping_cost),0) as total_shipping FROM purchase_orders po WHERE $date_condition $sup_cond");
$overview->execute(); $cost_overview = $overview->fetch();

$mtrend = $pdo->prepare("SELECT DATE_FORMAT(po.created_at,'%Y-%m') as month, DATE_FORMAT(po.created_at,'%M %Y') as month_label, COALESCE(SUM(po.total_amount),0) as total_amount, COUNT(*) as order_count FROM purchase_orders po WHERE po.created_at>=DATE_SUB(CURDATE(),INTERVAL 12 MONTH) GROUP BY DATE_FORMAT(po.created_at,'%Y-%m') ORDER BY month ASC");
$mtrend->execute(); $monthly_trends = $mtrend->fetchAll();

$scomp = $pdo->prepare("SELECT s.supplier_name, s.id as supplier_id, COUNT(po.id) as order_count, COALESCE(SUM(po.total_amount),0) as total_spend, COALESCE(AVG(po.total_amount),0) as avg_order_value FROM suppliers s LEFT JOIN purchase_orders po ON s.id=po.supplier_id AND $date_condition WHERE s.status='active' GROUP BY s.id,s.supplier_name HAVING total_spend>0 ORDER BY total_spend DESC LIMIT 10");
$scomp->execute(); $supplier_comparison = $scomp->fetchAll();

$catsp = $pdo->prepare("SELECT COALESCE(ii.category,'Uncategorized') as category, COUNT(poi.id) as item_count, COALESCE(SUM(poi.total_price),0) as total_spend, COALESCE(AVG(poi.unit_price),0) as avg_unit_price FROM purchase_order_items poi JOIN purchase_orders po ON poi.po_id=po.id LEFT JOIN inventory_items ii ON poi.item_id=ii.id WHERE $date_condition $sup_cond GROUP BY ii.category ORDER BY total_spend DESC");
$catsp->execute(); $category_spending = $catsp->fetchAll();

$topitems = $pdo->prepare("SELECT COALESCE(ii.item_name,'Unknown') as item_name, ii.item_code, SUM(poi.quantity_ordered) as total_quantity, COALESCE(SUM(poi.total_price),0) as total_spend, COALESCE(AVG(poi.unit_price),0) as avg_unit_price FROM purchase_order_items poi JOIN purchase_orders po ON poi.po_id=po.id LEFT JOIN inventory_items ii ON poi.item_id=ii.id WHERE $date_condition $sup_cond GROUP BY poi.item_id,ii.item_name,ii.item_code ORDER BY total_spend DESC LIMIT 15");
$topitems->execute(); $top_items = $topitems->fetchAll();

$all_suppliers = $pdo->query("SELECT id,supplier_name FROM suppliers WHERE status='active' ORDER BY supplier_name")->fetchAll();

$avg_monthly = count($monthly_trends) > 0 ? array_sum(array_column($monthly_trends,'total_amount')) / count($monthly_trends) : 0;

$page_title = 'Cost Analysis'; $module_subtitle = 'Procurement'; $back_btn_href = 'psm.php'; $back_btn_label = 'Procurement'; $active_nav = 'psm';
include 'includes/head.php';
?>
<body>
<?php include 'includes/topbar.php'; ?>
<div class="layout">
<?php include 'includes/sidebar.php'; ?>
<main class="main-content">

    <div class="page-title">
        <span class="page-title-tag">Procurement / Analysis</span>
        <h1>Cost <strong>Analysis &amp; Optimization</strong></h1>
        <p>Analyse procurement costs, compare supplier pricing, and identify savings opportunities.</p>
    </div>

    <!-- FILTER BAR -->
    <form method="GET" style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:1rem 1.4rem;margin-bottom:1.6rem;display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end;">
        <div class="form-group" style="margin:0;flex:1;min-width:150px;">
            <label class="form-label">Period</label>
            <select name="period" class="form-select" style="padding:.42rem .8rem;">
                <option value="current_month" <?php echo $selected_period==='current_month'?'selected':''; ?>>Current Month</option>
                <option value="last_month" <?php echo $selected_period==='last_month'?'selected':''; ?>>Last Month</option>
                <option value="last_3_months" <?php echo $selected_period==='last_3_months'?'selected':''; ?>>Last 3 Months</option>
                <option value="last_6_months" <?php echo $selected_period==='last_6_months'?'selected':''; ?>>Last 6 Months</option>
                <option value="current_year" <?php echo $selected_period==='current_year'?'selected':''; ?>>Current Year</option>
            </select>
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:200px;">
            <label class="form-label">Supplier</label>
            <select name="supplier_id" class="form-select" style="padding:.42rem .8rem;">
                <option value="all">All Suppliers</option>
                <?php foreach ($all_suppliers as $s): ?><option value="<?php echo $s['id']; ?>" <?php echo $selected_supplier==$s['id']?'selected':''; ?>><?php echo htmlspecialchars($s['supplier_name']); ?></option><?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Apply Filter</button>
        <a href="cost_analysis.php" class="btn btn-outline">Reset</a>
    </form>

    <!-- STATS -->
    <div class="stats-row">
        <div class="stat-card"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div><div class="stat-value" style="font-size:1.3rem;">&#8369;<?php echo number_format($cost_overview['total_spend'],0); ?></div><div class="stat-label">Total Spend — <?php echo $period_label; ?></div></div>
        <div class="stat-card"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div><div class="stat-value"><?php echo number_format($cost_overview['total_orders']); ?></div><div class="stat-label">Total POs</div></div>
        <div class="stat-card"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div><div class="stat-value" style="font-size:1.3rem;">&#8369;<?php echo number_format($cost_overview['avg_order_value'],0); ?></div><div class="stat-label">Avg. Order Value</div></div>
        <div class="stat-card"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><div class="stat-value" style="font-size:1.3rem;">&#8369;<?php echo number_format($avg_monthly,0); ?></div><div class="stat-label">Avg. Monthly Spend</div></div>
    </div>

    <!-- MONTHLY TREND + SUPPLIER COMPARISON -->
    <div class="main-grid">
        <div class="panel">
            <div class="panel-header"><span class="panel-title">Monthly Spend Trend</span><span class="panel-badge">12 months</span></div>
            <div style="padding:1.2rem 1.6rem;">
                <?php if (empty($monthly_trends)): ?>
                <div class="empty-td">No spend data available.</div>
                <?php else: $max_spend = max(array_column($monthly_trends,'total_amount'))?:1; foreach ($monthly_trends as $mt): $pct = round(($mt['total_amount']/$max_spend)*100); ?>
                <div style="display:flex;align-items:center;gap:.8rem;margin-bottom:.6rem;">
                    <span style="font-size:.72rem;color:var(--muted);min-width:80px;font-family:'DM Mono',monospace;"><?php echo $mt['month_label']; ?></span>
                    <div style="flex:1;background:var(--off);border-radius:99px;height:10px;overflow:hidden;"><div style="width:<?php echo $pct; ?>%;height:100%;background:linear-gradient(90deg,var(--accent),var(--steel));border-radius:99px;"></div></div>
                    <span style="font-size:.78rem;font-weight:600;color:var(--text);min-width:90px;text-align:right;">&#8369;<?php echo number_format($mt['total_amount'],0); ?></span>
                    <span style="font-size:.72rem;color:var(--muted);"><?php echo $mt['order_count']; ?> POs</span>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <div class="panel">
            <div class="panel-header"><span class="panel-title">Top Suppliers by Spend</span><span class="panel-badge">Top 10</span></div>
            <?php if (empty($supplier_comparison)): ?>
            <div class="empty-td" style="padding:2rem;text-align:center;">No data.</div>
            <?php else: foreach ($supplier_comparison as $sc): ?>
            <div class="list-item">
                <div><div class="li-title"><?php echo htmlspecialchars($sc['supplier_name']); ?></div><div class="li-sub"><?php echo $sc['order_count']; ?> orders</div></div>
                <div class="li-value">&#8369;<?php echo number_format($sc['total_spend'],0); ?></div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- CATEGORY + TOP ITEMS -->
    <div class="main-grid" style="margin-bottom:0;">
        <div class="panel">
            <div class="panel-header"><span class="panel-title">Spending by Category</span></div>
            <div class="tbl-wrap">
                <table class="data-table">
                    <thead><tr><th>Category</th><th>Items</th><th>Total Spend</th><th>Avg Unit Price</th></tr></thead>
                    <tbody>
                        <?php if (empty($category_spending)): ?><tr><td colspan="4" class="empty-td">No data.</td></tr>
                        <?php else: foreach ($category_spending as $cs): ?>
                        <tr><td><strong><?php echo htmlspecialchars($cs['category']); ?></strong></td><td><?php echo $cs['item_count']; ?></td><td><strong>&#8369;<?php echo number_format($cs['total_spend'],2); ?></strong></td><td>&#8369;<?php echo number_format($cs['avg_unit_price'],2); ?></td></tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="panel">
            <div class="panel-header"><span class="panel-title">Top Items by Spend</span><span class="panel-badge">Top 15</span></div>
            <div class="tbl-wrap">
                <table class="data-table">
                    <thead><tr><th>Item</th><th>Qty</th><th>Total Spend</th></tr></thead>
                    <tbody>
                        <?php if (empty($top_items)): ?><tr><td colspan="3" class="empty-td">No data.</td></tr>
                        <?php else: foreach ($top_items as $ti): ?>
                        <tr><td><?php echo htmlspecialchars($ti['item_name']); ?><?php if ($ti['item_code']): ?><br><span class="item-code"><?php echo $ti['item_code']; ?></span><?php endif; ?></td><td><?php echo number_format($ti['total_quantity']); ?></td><td><strong>&#8369;<?php echo number_format($ti['total_spend'],0); ?></strong></td></tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>
</div>
<?php include 'includes/footer.php'; ?>
