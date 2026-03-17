<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

/* ── AJAX ───────────────────────────────────────────────────── */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    if ($_GET['ajax'] === 'suppliers') {
        echo json_encode($pdo->query("SELECT id,supplier_name FROM suppliers WHERE status='active' ORDER BY supplier_name")->fetchAll());
    } elseif ($_GET['ajax'] === 'items') {
        echo json_encode($pdo->query("SELECT id,item_name,unit_price FROM inventory_items WHERE status='active' ORDER BY item_name")->fetchAll());
    }
    exit;
}

/* ── FORM HANDLING ──────────────────────────────────────────── */
$message = ''; $messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($_POST['action'] ?? '') {
        case 'create':
            try {
                $pdo->beginTransaction();
                $next = $pdo->query("SELECT COUNT(*)+1 FROM purchase_orders")->fetchColumn();
                $po_number = 'PO-' . date('Y') . '-' . str_pad($next, 4, '0', STR_PAD_LEFT);
                $stmt = $pdo->prepare("INSERT INTO purchase_orders (po_number,supplier_id,order_date,expected_delivery,status,total_amount,tax_amount,shipping_cost,discount_amount,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$po_number, $_POST['supplier_id'], $_POST['order_date'], $_POST['expected_delivery']?:null, $_POST['status']??'draft', $_POST['total_amount'], $_POST['tax_amount']??0, $_POST['shipping_cost']??0, $_POST['discount_amount']??0, $_POST['notes']??'', $user_email]);
                $po_id = $pdo->lastInsertId();
                if (!empty($_POST['items'])) {
                    $is = $pdo->prepare("INSERT INTO purchase_order_items (po_id,item_id,quantity_ordered,unit_price,total_price,notes) VALUES (?,?,?,?,?,?)");
                    foreach ($_POST['items'] as $item) {
                        if (!empty($item['item_id']) && !empty($item['quantity']) && !empty($item['unit_price']))
                            $is->execute([$po_id, $item['item_id'], $item['quantity'], $item['unit_price'], $item['quantity']*$item['unit_price'], $item['notes']??'']);
                    }
                }
                $pdo->commit(); $message = 'Purchase order created!'; $messageType = 'success';
            } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $message = $e->getMessage(); $messageType = 'error'; }
            break;
        case 'update':
            try {
                $pdo->prepare("UPDATE purchase_orders SET supplier_id=?,order_date=?,expected_delivery=?,status=?,total_amount=?,tax_amount=?,shipping_cost=?,discount_amount=?,notes=? WHERE id=?")
                    ->execute([$_POST['supplier_id'], $_POST['order_date'], $_POST['expected_delivery']?:null, $_POST['status'], $_POST['total_amount'], $_POST['tax_amount']??0, $_POST['shipping_cost']??0, $_POST['discount_amount']??0, $_POST['notes']??'', $_POST['po_id']]);
                $message = 'Purchase order updated!'; $messageType = 'success';
            } catch (Exception $e) { $message = $e->getMessage(); $messageType = 'error'; }
            break;
        case 'delete':
            try {
                $pdo->prepare("DELETE FROM purchase_orders WHERE id=?")->execute([$_POST['po_id']]);
                $message = 'Purchase order deleted.'; $messageType = 'success';
            } catch (Exception $e) { $message = $e->getMessage(); $messageType = 'error'; }
            break;
    }
}

/* ── PAGE DATA ──────────────────────────────────────────────── */
$search        = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$page          = max(1,(int)($_GET['page'] ?? 1));
$per_page = 10; $offset = ($page-1)*$per_page;

$conds = ['1=1']; $params = [];
if ($search) { $conds[] = "(po.po_number LIKE ? OR s.supplier_name LIKE ?)"; $params = array_merge($params, ["%$search%", "%$search%"]); }
if ($status_filter) { $conds[] = "po.status=?"; $params[] = $status_filter; }
$where = implode(' AND ', $conds);

$total_pages = max(1, ceil($pdo->prepare("SELECT COUNT(*) FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id=s.id WHERE $where")->execute($params)?$pdo->prepare("SELECT COUNT(*) FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id=s.id WHERE $where")->fetchColumn():0 / $per_page));

$cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id=s.id WHERE $where");
$cnt_stmt->execute($params); $total_records = $cnt_stmt->fetchColumn();
$total_pages = max(1, ceil($total_records / $per_page));

$stmt = $pdo->prepare("SELECT po.*, s.supplier_name, (SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.po_id=po.id) as item_count FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id=s.id WHERE $where ORDER BY po.created_at DESC LIMIT $per_page OFFSET $offset");
$stmt->execute($params); $purchase_orders = $stmt->fetchAll();

$suppliers = $pdo->query("SELECT id,supplier_name FROM suppliers WHERE status='active' ORDER BY supplier_name")->fetchAll();
$inv_items = $pdo->query("SELECT id,item_name,item_code,unit_price FROM inventory_items WHERE status='active' ORDER BY item_name")->fetchAll();

$stats = [
    'total'     => $pdo->query("SELECT COUNT(*) FROM purchase_orders")->fetchColumn(),
    'draft'     => $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status='draft'")->fetchColumn(),
    'confirmed' => $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status='confirmed'")->fetchColumn(),
    'value'     => $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM purchase_orders WHERE MONTH(created_at)=MONTH(CURRENT_DATE())")->fetchColumn(),
];

$page_title      = 'Purchase Orders';
$module_subtitle = 'Procurement';
$back_btn_href   = 'psm.php';
$back_btn_label  = 'Procurement';
$active_nav      = 'psm';
include 'includes/head.php';
?>
<body>
<?php include 'includes/topbar.php'; ?>
<div class="layout">
<?php include 'includes/sidebar.php'; ?>
<main class="main-content">

    <div class="page-title">
        <span class="page-title-tag">Procurement / Orders</span>
        <h1>Purchase <strong>Orders</strong></h1>
        <p>Create, track, and manage all purchase orders.</p>
    </div>

    <?php if ($message): ?><div class="alert alert-<?php echo $messageType==='success'?'success':'error'; ?>"><svg viewBox="0 0 24 24"><?php echo $messageType==='success'?'<polyline points="20 6 9 17 4 12"/>':'<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>'; ?></svg><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

    <!-- STATS -->
    <div class="stats-row">
        <div class="stat-card"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div><div class="stat-value"><?php echo $stats['total']; ?></div><div class="stat-label">Total POs</div></div>
        <div class="stat-card warn"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div><div class="stat-value"><?php echo $stats['draft']; ?></div><div class="stat-label">Draft POs</div></div>
        <div class="stat-card success"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></div><div class="stat-value"><?php echo $stats['confirmed']; ?></div><div class="stat-label">Confirmed</div></div>
        <div class="stat-card"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div><div class="stat-value" style="font-size:1.3rem;">&#8369;<?php echo number_format($stats['value'],0); ?></div><div class="stat-label">This Month</div></div>
    </div>

    <!-- TABLE PANEL -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">Purchase Orders</span>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <input type="text" name="search" class="form-input" placeholder="Search PO or supplier…" value="<?php echo htmlspecialchars($search); ?>" style="width:200px;padding:.42rem .8rem;">
                    <select name="status" class="form-select" style="width:150px;padding:.42rem .8rem;">
                        <option value="">All Status</option>
                        <option value="draft" <?php echo $status_filter==='draft'?'selected':''; ?>>Draft</option>
                        <option value="sent" <?php echo $status_filter==='sent'?'selected':''; ?>>Sent</option>
                        <option value="confirmed" <?php echo $status_filter==='confirmed'?'selected':''; ?>>Confirmed</option>
                        <option value="partial" <?php echo $status_filter==='partial'?'selected':''; ?>>Partial</option>
                        <option value="completed" <?php echo $status_filter==='completed'?'selected':''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $status_filter==='cancelled'?'selected':''; ?>>Cancelled</option>
                    </select>
                    <button type="submit" class="btn btn-ghost">Filter</button>
                    <?php if ($search||$status_filter): ?><a href="purchase_orders.php" class="btn btn-outline">Clear</a><?php endif; ?>
                </form>
                <button class="btn btn-primary" onclick="openModal('createPOModal')">
                    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Create PO
                </button>
            </div>
        </div>
        <div class="tbl-wrap">
            <table class="data-table">
                <thead><tr><th>PO Number</th><th>Supplier</th><th>Order Date</th><th>Delivery</th><th>Total</th><th>Items</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (empty($purchase_orders)): ?>
                    <tr><td colspan="8" class="empty-td">No purchase orders yet. <a href="#" onclick="openModal('createPOModal');return false;">Create your first PO →</a></td></tr>
                    <?php else: foreach ($purchase_orders as $po): ?>
                    <tr>
                        <td><span class="item-code"><?php echo htmlspecialchars($po['po_number']); ?></span></td>
                        <td><?php echo htmlspecialchars($po['supplier_name']??'—'); ?></td>
                        <td><?php echo date('M d, Y', strtotime($po['order_date'])); ?></td>
                        <td><?php echo $po['expected_delivery'] ? date('M d, Y', strtotime($po['expected_delivery'])) : '—'; ?></td>
                        <td><strong>&#8369;<?php echo number_format($po['total_amount'],2); ?></strong></td>
                        <td><?php echo $po['item_count']; ?> items</td>
                        <td><span class="badge badge-<?php echo $po['status']; ?>"><?php echo ucfirst($po['status']); ?></span></td>
                        <td>
                            <div class="btn-row">
                                <button class="btn btn-green btn-sm" onclick="editPO(<?php echo htmlspecialchars(json_encode($po)); ?>)">Edit</button>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this PO?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="po_id" value="<?php echo $po['id']; ?>">
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
            <?php for ($i=1;$i<=$total_pages;$i++): ?>
            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" class="btn <?php echo $i===$page?'btn-primary':'btn-outline'; ?> btn-sm"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</main>
</div>

<!-- CREATE PO MODAL -->
<div id="createPOModal" class="modal">
    <div class="modal-box wide">
        <div class="modal-head"><h3>Create Purchase Order</h3><button class="modal-close" onclick="closeModal('createPOModal')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="create">
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Supplier <span class="req">*</span></label>
                        <select name="supplier_id" class="form-select" required>
                            <option value="">— Select Supplier —</option>
                            <?php foreach ($suppliers as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['supplier_name']); ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="form-group"><label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="draft">Draft</option><option value="sent">Sent</option>
                            <option value="confirmed">Confirmed</option>
                        </select></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Order Date <span class="req">*</span></label><input type="date" name="order_date" class="form-input" value="<?php echo date('Y-m-d'); ?>" required></div>
                    <div class="form-group"><label class="form-label">Expected Delivery</label><input type="date" name="expected_delivery" class="form-input"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Total Amount (₱) <span class="req">*</span></label><input type="number" name="total_amount" class="form-input" step="0.01" min="0" required></div>
                    <div class="form-group"><label class="form-label">Tax Amount (₱)</label><input type="number" name="tax_amount" class="form-input" step="0.01" min="0" value="0"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Shipping Cost (₱)</label><input type="number" name="shipping_cost" class="form-input" step="0.01" min="0" value="0"></div>
                    <div class="form-group"><label class="form-label">Discount (₱)</label><input type="number" name="discount_amount" class="form-input" step="0.01" min="0" value="0"></div>
                </div>
                <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" class="form-textarea"></textarea></div>
                <button type="submit" class="submit-btn">Create Purchase Order</button>
            </form>
        </div>
    </div>
</div>

<!-- EDIT PO MODAL -->
<div id="editPOModal" class="modal">
    <div class="modal-box wide">
        <div class="modal-head"><h3>Edit Purchase Order</h3><button class="modal-close" onclick="closeModal('editPOModal')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="po_id" id="ep_id">
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Supplier</label>
                        <select name="supplier_id" id="ep_supplier" class="form-select">
                            <?php foreach ($suppliers as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['supplier_name']); ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="form-group"><label class="form-label">Status</label>
                        <select name="status" id="ep_status" class="form-select">
                            <option value="draft">Draft</option><option value="sent">Sent</option>
                            <option value="confirmed">Confirmed</option><option value="partial">Partial</option>
                            <option value="completed">Completed</option><option value="cancelled">Cancelled</option>
                        </select></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Order Date</label><input type="date" name="order_date" id="ep_date" class="form-input"></div>
                    <div class="form-group"><label class="form-label">Expected Delivery</label><input type="date" name="expected_delivery" id="ep_delivery" class="form-input"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Total Amount (₱)</label><input type="number" name="total_amount" id="ep_total" class="form-input" step="0.01"></div>
                    <div class="form-group"><label class="form-label">Tax Amount (₱)</label><input type="number" name="tax_amount" id="ep_tax" class="form-input" step="0.01"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Shipping Cost (₱)</label><input type="number" name="shipping_cost" id="ep_ship" class="form-input" step="0.01"></div>
                    <div class="form-group"><label class="form-label">Discount (₱)</label><input type="number" name="discount_amount" id="ep_disc" class="form-input" step="0.01"></div>
                </div>
                <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" id="ep_notes" class="form-textarea"></textarea></div>
                <button type="submit" class="submit-btn">Save Changes</button>
            </form>
        </div>
    </div>
</div>

<script>
function editPO(po) {
    document.getElementById('ep_id').value       = po.id;
    document.getElementById('ep_supplier').value = po.supplier_id;
    document.getElementById('ep_status').value   = po.status;
    document.getElementById('ep_date').value     = po.order_date ? po.order_date.substring(0,10) : '';
    document.getElementById('ep_delivery').value = po.expected_delivery ? po.expected_delivery.substring(0,10) : '';
    document.getElementById('ep_total').value    = po.total_amount;
    document.getElementById('ep_tax').value      = po.tax_amount || 0;
    document.getElementById('ep_ship').value     = po.shipping_cost || 0;
    document.getElementById('ep_disc').value     = po.discount_amount || 0;
    document.getElementById('ep_notes').value    = po.notes || '';
    openModal('editPOModal');
}
</script>
<?php include 'includes/footer.php'; ?>
