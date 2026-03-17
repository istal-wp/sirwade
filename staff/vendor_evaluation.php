<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

$message = ''; $message_type = '';

/* ── FORM HANDLING ──────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'create_evaluation':
            try {
                $overall = (floatval($_POST['quality_rating']) + floatval($_POST['delivery_rating']) + floatval($_POST['service_rating']) + floatval($_POST['price_rating'])) / 4;
                $pdo->prepare("INSERT INTO supplier_evaluations (supplier_id,evaluation_period_start,evaluation_period_end,quality_rating,delivery_rating,service_rating,price_rating,overall_rating,total_orders,on_time_deliveries,total_value,quality_issues,comments,evaluated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$_POST['supplier_id'],$_POST['evaluation_period_start'],$_POST['evaluation_period_end'],$_POST['quality_rating'],$_POST['delivery_rating'],$_POST['service_rating'],$_POST['price_rating'],$overall,$_POST['total_orders'],$_POST['on_time_deliveries'],$_POST['total_value'],$_POST['quality_issues'],$_POST['comments'],$_SESSION['user_id']??1]);
                $pdo->prepare("UPDATE suppliers SET rating=(SELECT AVG(overall_rating) FROM supplier_evaluations WHERE supplier_id=?) WHERE id=?")->execute([$_POST['supplier_id'],$_POST['supplier_id']]);
                $message = 'Evaluation created!'; $message_type = 'success';
            } catch (PDOException $e) { $message = $e->getMessage(); $message_type = 'error'; }
            break;
        case 'update_evaluation':
            try {
                $overall = (floatval($_POST['quality_rating']) + floatval($_POST['delivery_rating']) + floatval($_POST['service_rating']) + floatval($_POST['price_rating'])) / 4;
                $pdo->prepare("UPDATE supplier_evaluations SET supplier_id=?,evaluation_period_start=?,evaluation_period_end=?,quality_rating=?,delivery_rating=?,service_rating=?,price_rating=?,overall_rating=?,total_orders=?,on_time_deliveries=?,total_value=?,quality_issues=?,comments=? WHERE id=?")
                    ->execute([$_POST['supplier_id'],$_POST['evaluation_period_start'],$_POST['evaluation_period_end'],$_POST['quality_rating'],$_POST['delivery_rating'],$_POST['service_rating'],$_POST['price_rating'],$overall,$_POST['total_orders'],$_POST['on_time_deliveries'],$_POST['total_value'],$_POST['quality_issues'],$_POST['comments'],$_POST['evaluation_id']]);
                $pdo->prepare("UPDATE suppliers SET rating=(SELECT AVG(overall_rating) FROM supplier_evaluations WHERE supplier_id=?) WHERE id=?")->execute([$_POST['supplier_id'],$_POST['supplier_id']]);
                $message = 'Evaluation updated!'; $message_type = 'success';
            } catch (PDOException $e) { $message = $e->getMessage(); $message_type = 'error'; }
            break;
        case 'delete_evaluation':
            try {
                $pdo->prepare("DELETE FROM supplier_evaluations WHERE id=?")->execute([$_POST['evaluation_id']]);
                $message = 'Evaluation deleted.'; $message_type = 'success';
            } catch (PDOException $e) { $message = $e->getMessage(); $message_type = 'error'; }
            break;
    }
}

/* ── PAGE DATA ──────────────────────────────────────────────── */
$search          = trim($_GET['search'] ?? '');
$supplier_filter = $_GET['supplier'] ?? '';
$period_filter   = $_GET['period'] ?? '';
$page            = max(1,(int)($_GET['page']??1));
$per_page = 10; $offset = ($page-1)*$per_page;

$conds = []; $params = [];
if ($search) { $conds[] = "(s.supplier_name LIKE ? OR se.comments LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%"]); }
if ($supplier_filter) { $conds[] = "se.supplier_id=?"; $params[] = $supplier_filter; }
if ($period_filter) {
    $intervals = ['last_month'=>'1 MONTH','last_quarter'=>'3 MONTH','last_year'=>'1 YEAR'];
    if (isset($intervals[$period_filter])) { $conds[] = "se.evaluation_period_end >= DATE_SUB(CURDATE(), INTERVAL {$intervals[$period_filter]})"; }
}
$where = $conds ? 'WHERE '.implode(' AND ',$conds) : '';

$cnt = $pdo->prepare("SELECT COUNT(*) FROM supplier_evaluations se JOIN suppliers s ON se.supplier_id=s.id $where"); $cnt->execute($params);
$total_pages = max(1, ceil($cnt->fetchColumn() / $per_page));

$stmt = $pdo->prepare("SELECT se.*, s.supplier_name, s.supplier_code, CASE WHEN se.overall_rating>=4.5 THEN 'Excellent' WHEN se.overall_rating>=4 THEN 'Good' WHEN se.overall_rating>=3 THEN 'Average' WHEN se.overall_rating>=2 THEN 'Below Average' ELSE 'Poor' END as performance_grade FROM supplier_evaluations se JOIN suppliers s ON se.supplier_id=s.id $where ORDER BY se.created_at DESC LIMIT $per_page OFFSET $offset");
$stmt->execute($params); $evaluations = $stmt->fetchAll();

$suppliers_list = $pdo->query("SELECT id,supplier_name,supplier_code FROM suppliers WHERE status='active' ORDER BY supplier_name")->fetchAll();

$stats = [
    'total'      => $pdo->query("SELECT COUNT(*) FROM supplier_evaluations")->fetchColumn(),
    'avg_rating' => round($pdo->query("SELECT COALESCE(AVG(overall_rating),0) FROM supplier_evaluations")->fetchColumn(), 2),
    'recent'     => $pdo->query("SELECT COUNT(*) FROM supplier_evaluations WHERE MONTH(created_at)=MONTH(CURRENT_DATE()) AND YEAR(created_at)=YEAR(CURRENT_DATE())")->fetchColumn(),
];
$top = $pdo->query("SELECT s.supplier_name, AVG(se.overall_rating) as avg FROM suppliers s JOIN supplier_evaluations se ON s.id=se.supplier_id GROUP BY s.id ORDER BY avg DESC LIMIT 1")->fetch();
$stats['top_supplier'] = $top ? $top['supplier_name'] : 'N/A';

$edit_evaluation = null;
if (!empty($_GET['edit']) && is_numeric($_GET['edit'])) {
    $es = $pdo->prepare("SELECT * FROM supplier_evaluations WHERE id=?"); $es->execute([$_GET['edit']]); $edit_evaluation = $es->fetch();
}

$page_title = 'Vendor Evaluation'; $module_subtitle = 'Procurement'; $back_btn_href = 'psm.php'; $back_btn_label = 'Procurement'; $active_nav = 'psm';
include 'includes/head.php';
?>
<body>
<?php include 'includes/topbar.php'; ?>
<div class="layout">
<?php include 'includes/sidebar.php'; ?>
<main class="main-content">

    <div class="page-title">
        <span class="page-title-tag">Procurement / Evaluation</span>
        <h1>Vendor <strong>Performance Evaluation</strong></h1>
        <p>Evaluate supplier performance on quality, delivery, service, and pricing.</p>
    </div>

    <?php if ($message): ?><div class="alert alert-<?php echo $message_type==='success'?'success':'error'; ?>"><svg viewBox="0 0 24 24"><?php echo $message_type==='success'?'<polyline points="20 6 9 17 4 12"/>':'<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>'; ?></svg><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

    <div class="stats-row">
        <div class="stat-card"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></div><div class="stat-value"><?php echo $stats['total']; ?></div><div class="stat-label">Total Evaluations</div></div>
        <div class="stat-card success"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div><div class="stat-value"><?php echo $stats['avg_rating']; ?></div><div class="stat-label">Avg. Rating</div></div>
        <div class="stat-card"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div><div class="stat-value" style="font-size:1rem;"><?php echo htmlspecialchars($stats['top_supplier']); ?></div><div class="stat-label">Top Supplier</div></div>
        <div class="stat-card"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><div class="stat-value"><?php echo $stats['recent']; ?></div><div class="stat-label">This Month</div></div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">Evaluations</span>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <input type="text" name="search" class="form-input" placeholder="Search…" value="<?php echo htmlspecialchars($search); ?>" style="width:170px;padding:.42rem .8rem;">
                    <select name="supplier" class="form-select" style="width:180px;padding:.42rem .8rem;">
                        <option value="">All Suppliers</option>
                        <?php foreach ($suppliers_list as $s): ?><option value="<?php echo $s['id']; ?>" <?php echo $supplier_filter==$s['id']?'selected':''; ?>><?php echo htmlspecialchars($s['supplier_name']); ?></option><?php endforeach; ?>
                    </select>
                    <select name="period" class="form-select" style="width:150px;padding:.42rem .8rem;">
                        <option value="">All Time</option>
                        <option value="last_month" <?php echo $period_filter==='last_month'?'selected':''; ?>>Last Month</option>
                        <option value="last_quarter" <?php echo $period_filter==='last_quarter'?'selected':''; ?>>Last Quarter</option>
                        <option value="last_year" <?php echo $period_filter==='last_year'?'selected':''; ?>>Last Year</option>
                    </select>
                    <button type="submit" class="btn btn-ghost">Filter</button>
                    <?php if ($search||$supplier_filter||$period_filter): ?><a href="vendor_evaluation.php" class="btn btn-outline">Clear</a><?php endif; ?>
                </form>
                <button class="btn btn-primary" onclick="openModal('createEvalModal')">
                    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    New Evaluation
                </button>
            </div>
        </div>
        <div class="tbl-wrap">
            <table class="data-table">
                <thead><tr><th>Supplier</th><th>Period</th><th>Quality</th><th>Delivery</th><th>Service</th><th>Price</th><th>Overall</th><th>Grade</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (empty($evaluations)): ?>
                    <tr><td colspan="9" class="empty-td">No evaluations yet. <a href="#" onclick="openModal('createEvalModal');return false;">Create your first →</a></td></tr>
                    <?php else: foreach ($evaluations as $ev): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($ev['supplier_name']); ?></strong><br><span class="item-code"><?php echo $ev['supplier_code']; ?></span></td>
                        <td style="font-size:.78rem;"><?php echo date('M Y', strtotime($ev['evaluation_period_start'])); ?> – <?php echo date('M Y', strtotime($ev['evaluation_period_end'])); ?></td>
                        <td><?php echo number_format($ev['quality_rating'],1); ?></td>
                        <td><?php echo number_format($ev['delivery_rating'],1); ?></td>
                        <td><?php echo number_format($ev['service_rating'],1); ?></td>
                        <td><?php echo number_format($ev['price_rating'],1); ?></td>
                        <td><strong style="color:<?php echo $ev['overall_rating']>=4?'var(--success)':($ev['overall_rating']>=3?'var(--warn)':'var(--error)'); ?>"><?php echo number_format($ev['overall_rating'],2); ?></strong></td>
                        <td><span class="badge badge-<?php echo $ev['overall_rating']>=4?'normal':($ev['overall_rating']>=3?'warn':'danger'); ?>"><?php echo $ev['performance_grade']; ?></span></td>
                        <td>
                            <div class="btn-row">
                                <button class="btn btn-green btn-sm" onclick="editEval(<?php echo htmlspecialchars(json_encode($ev)); ?>)">Edit</button>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this evaluation?')">
                                    <input type="hidden" name="action" value="delete_evaluation">
                                    <input type="hidden" name="evaluation_id" value="<?php echo $ev['id']; ?>">
                                    <button type="submit" class="btn btn-red btn-sm">Del</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total_pages > 1): ?>
        <div class="panel-footer" style="display:flex;gap:.4rem;justify-content:center;flex-wrap:wrap;">
            <?php for ($i=1;$i<=$total_pages;$i++): ?><a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&supplier=<?php echo urlencode($supplier_filter); ?>&period=<?php echo urlencode($period_filter); ?>" class="btn <?php echo $i===$page?'btn-primary':'btn-outline'; ?> btn-sm"><?php echo $i; ?></a><?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</main>
</div>

<!-- CREATE EVAL MODAL -->
<div id="createEvalModal" class="modal">
    <div class="modal-box">
        <div class="modal-head"><h3>New Vendor Evaluation</h3><button class="modal-close" onclick="closeModal('createEvalModal')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="create_evaluation">
                <div class="form-group"><label class="form-label">Supplier <span class="req">*</span></label>
                    <select name="supplier_id" class="form-select" required>
                        <option value="">— Select Supplier —</option>
                        <?php foreach ($suppliers_list as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['supplier_name']); ?></option><?php endforeach; ?>
                    </select></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Period Start <span class="req">*</span></label><input type="date" name="evaluation_period_start" class="form-input" required></div>
                    <div class="form-group"><label class="form-label">Period End <span class="req">*</span></label><input type="date" name="evaluation_period_end" class="form-input" required></div>
                </div>
                <div style="background:var(--off);border:1px solid var(--border);border-radius:8px;padding:1rem;margin-bottom:1rem;">
                    <div style="font-size:.8rem;font-weight:600;margin-bottom:.8rem;">Ratings (1–5)</div>
                    <?php foreach (['quality'=>'Quality','delivery'=>'Delivery','service'=>'Service','price'=>'Price/Value'] as $k=>$label): ?>
                    <div class="form-row" style="margin-bottom:.5rem;">
                        <label class="form-label" style="align-self:center;"><?php echo $label; ?> <span class="req">*</span></label>
                        <select name="<?php echo $k; ?>_rating" class="form-select" required>
                            <?php for ($i=1;$i<=5;$i++): ?><option value="<?php echo $i; ?>"><?php echo $i; ?> — <?php echo ['','Poor','Below Average','Average','Good','Excellent'][$i]; ?></option><?php endfor; ?>
                        </select>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Total Orders</label><input type="number" name="total_orders" class="form-input" min="0" value="0"></div>
                    <div class="form-group"><label class="form-label">On-Time Deliveries</label><input type="number" name="on_time_deliveries" class="form-input" min="0" value="0"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Total Value (₱)</label><input type="number" name="total_value" class="form-input" step="0.01" min="0" value="0"></div>
                    <div class="form-group"><label class="form-label">Quality Issues</label><input type="number" name="quality_issues" class="form-input" min="0" value="0"></div>
                </div>
                <div class="form-group"><label class="form-label">Comments</label><textarea name="comments" class="form-textarea"></textarea></div>
                <button type="submit" class="submit-btn">Save Evaluation</button>
            </form>
        </div>
    </div>
</div>

<!-- EDIT EVAL MODAL -->
<div id="editEvalModal" class="modal">
    <div class="modal-box">
        <div class="modal-head"><h3>Edit Evaluation</h3><button class="modal-close" onclick="closeModal('editEvalModal')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="update_evaluation">
                <input type="hidden" name="evaluation_id" id="ee_id">
                <div class="form-group"><label class="form-label">Supplier</label>
                    <select name="supplier_id" id="ee_supplier" class="form-select">
                        <?php foreach ($suppliers_list as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['supplier_name']); ?></option><?php endforeach; ?>
                    </select></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Period Start</label><input type="date" name="evaluation_period_start" id="ee_start" class="form-input"></div>
                    <div class="form-group"><label class="form-label">Period End</label><input type="date" name="evaluation_period_end" id="ee_end" class="form-input"></div>
                </div>
                <?php foreach (['quality'=>'Quality','delivery'=>'Delivery','service'=>'Service','price'=>'Price/Value'] as $k=>$label): ?>
                <div class="form-row" style="margin-bottom:.5rem;align-items:center;">
                    <label class="form-label" style="align-self:center;"><?php echo $label; ?></label>
                    <select name="<?php echo $k; ?>_rating" id="ee_<?php echo $k; ?>" class="form-select">
                        <?php for ($i=1;$i<=5;$i++): ?><option value="<?php echo $i; ?>"><?php echo $i; ?></option><?php endfor; ?>
                    </select>
                </div>
                <?php endforeach; ?>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Total Orders</label><input type="number" name="total_orders" id="ee_orders" class="form-input" min="0"></div>
                    <div class="form-group"><label class="form-label">On-Time Deliveries</label><input type="number" name="on_time_deliveries" id="ee_ontime" class="form-input" min="0"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Total Value (₱)</label><input type="number" name="total_value" id="ee_value" class="form-input" step="0.01"></div>
                    <div class="form-group"><label class="form-label">Quality Issues</label><input type="number" name="quality_issues" id="ee_issues" class="form-input" min="0"></div>
                </div>
                <div class="form-group"><label class="form-label">Comments</label><textarea name="comments" id="ee_comments" class="form-textarea"></textarea></div>
                <button type="submit" class="submit-btn">Save Changes</button>
            </form>
        </div>
    </div>
</div>

<script>
function editEval(ev) {
    document.getElementById('ee_id').value       = ev.id;
    document.getElementById('ee_supplier').value = ev.supplier_id;
    document.getElementById('ee_start').value    = ev.evaluation_period_start ? ev.evaluation_period_start.substring(0,10) : '';
    document.getElementById('ee_end').value      = ev.evaluation_period_end   ? ev.evaluation_period_end.substring(0,10)   : '';
    document.getElementById('ee_quality').value  = ev.quality_rating;
    document.getElementById('ee_delivery').value = ev.delivery_rating;
    document.getElementById('ee_service').value  = ev.service_rating;
    document.getElementById('ee_price').value    = ev.price_rating;
    document.getElementById('ee_orders').value   = ev.total_orders  || 0;
    document.getElementById('ee_ontime').value   = ev.on_time_deliveries || 0;
    document.getElementById('ee_value').value    = ev.total_value   || 0;
    document.getElementById('ee_issues').value   = ev.quality_issues || 0;
    document.getElementById('ee_comments').value = ev.comments || '';
    openModal('editEvalModal');
}
</script>
<?php include 'includes/footer.php'; ?>
