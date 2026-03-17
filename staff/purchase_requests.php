<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

$user_id = $_SESSION['user_id'] ?? 1;

/* ── AJAX: get supplier items ───────────────────────────────── */
if (isset($_GET['action']) && $_GET['action'] === 'get_supplier_items' && isset($_GET['supplier_id'])) {
    header('Content-Type: application/json');
    $stmt = $pdo->prepare("
        SELECT si.id as supplier_item_id, si.supplier_item_code, si.unit_price, si.lead_time_days,
               si.minimum_order_quantity, ii.item_name, ii.item_code, ii.category, ii.description, ii.quantity as stock_quantity
        FROM supplier_items si
        INNER JOIN inventory_items ii ON si.item_id = ii.id
        WHERE si.supplier_id = ? AND si.is_available = 1 AND ii.status = 'active'
        ORDER BY ii.item_name ASC");
    $stmt->execute([(int)$_GET['supplier_id']]);
    echo json_encode($stmt->fetchAll()); exit;
}

/* ── FORM HANDLING ──────────────────────────────────────────── */
$success_message = $error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'create':
            try {
                if (empty($_POST['supplier_id'])) throw new Exception("Please select a supplier.");
                $pdo->beginTransaction();
                $year = date('Y');
                $count = $pdo->query("SELECT COUNT(*) FROM purchase_requests WHERE YEAR(request_date) = $year")->fetchColumn() + 1;
                $request_number = 'PR-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
                $total_amount = 0;
                if (!empty($_POST['selected_items'])) {
                    foreach ($_POST['selected_items'] as $item) {
                        if (!empty($item['supplier_item_id']) && !empty($item['quantity']))
                            $total_amount += floatval($item['quantity']) * floatval($item['unit_price']);
                    }
                }
                $stmt = $pdo->prepare("INSERT INTO purchase_requests (request_number, requester_id, department, supplier_id, request_date, status, total_amount) VALUES (?,?,?,?,NOW(),'pending',?)");
                $stmt->execute([$request_number, $user_id, $_POST['department'], $_POST['supplier_id'], $total_amount]);
                $pr_id = $pdo->lastInsertId();
                if (!empty($_POST['selected_items'])) {
                    $istmt = $pdo->prepare("INSERT INTO purchase_request_items (pr_id, supplier_item_id, item_description, quantity, unit_of_measure, estimated_unit_price, notes) VALUES (?,?,?,?,?,?,?)");
                    foreach ($_POST['selected_items'] as $item) {
                        if (!empty($item['supplier_item_id']) && !empty($item['quantity']))
                            $istmt->execute([$pr_id, $item['supplier_item_id'], $item['item_name'], $item['quantity'], $item['unit'] ?? 'pcs', $item['unit_price'], $item['notes'] ?? '']);
                    }
                }
                $pdo->commit();
                $success_message = "Purchase Request $request_number created successfully!";
            } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error_message = $e->getMessage(); }
            break;
        case 'update_status':
            try {
                $pdo->prepare("UPDATE purchase_requests SET status=?, updated_at=NOW() WHERE id=?")->execute([$_POST['status'], $_POST['pr_id']]);
                $success_message = "Status updated successfully!";
            } catch (Exception $e) { $error_message = $e->getMessage(); }
            break;
        case 'delete':
            try {
                $pdo->prepare("DELETE FROM purchase_requests WHERE id=?")->execute([$_POST['pr_id']]);
                $success_message = "Purchase request deleted.";
            } catch (Exception $e) { $error_message = $e->getMessage(); }
            break;
    }
}

/* ── PAGE DATA ──────────────────────────────────────────────── */
$stats = [
    'pending'       => $pdo->query("SELECT COUNT(*) FROM purchase_requests WHERE status='pending'")->fetchColumn(),
    'approved'      => $pdo->query("SELECT COUNT(*) FROM purchase_requests WHERE status='approved'")->fetchColumn(),
    'rejected'      => $pdo->query("SELECT COUNT(*) FROM purchase_requests WHERE status='rejected'")->fetchColumn(),
    'monthly_total' => $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM purchase_requests WHERE MONTH(request_date)=MONTH(CURRENT_DATE())")->fetchColumn(),
];
$requests  = $pdo->query("SELECT pr.*, s.supplier_name FROM purchase_requests pr LEFT JOIN suppliers s ON pr.supplier_id=s.id ORDER BY pr.request_date DESC")->fetchAll();
$suppliers = $pdo->query("SELECT id,supplier_name,supplier_code FROM suppliers WHERE status='active' ORDER BY supplier_name")->fetchAll();

$request_details = $request_items = null;
if (!empty($_GET['view']) && is_numeric($_GET['view'])) {
    $stmt = $pdo->prepare("SELECT pr.*, s.supplier_name, s.contact_person FROM purchase_requests pr LEFT JOIN suppliers s ON pr.supplier_id=s.id WHERE pr.id=?");
    $stmt->execute([$_GET['view']]);
    $request_details = $stmt->fetch();
    if ($request_details) {
        $stmt = $pdo->prepare("SELECT pri.*, ii.item_name, ii.item_code FROM purchase_request_items pri LEFT JOIN supplier_items si ON pri.supplier_item_id=si.id LEFT JOIN inventory_items ii ON si.item_id=ii.id WHERE pri.pr_id=?");
        $stmt->execute([$_GET['view']]);
        $request_items = $stmt->fetchAll();
    }
}

$page_title      = 'Purchase Requests';
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
        <span class="page-title-tag">Procurement / Requests</span>
        <h1>Purchase <strong>Requests</strong></h1>
        <p>Submit and manage purchase requests for approval.</p>
    </div>

    <?php if ($success_message): ?><div class="alert alert-success"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
    <?php if ($error_message):   ?><div class="alert alert-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

    <!-- STATS -->
    <div class="stats-row">
        <div class="stat-card <?php echo $stats['pending'] > 0 ? 'warn' : ''; ?>">
            <div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
            <div class="stat-value"><?php echo $stats['pending']; ?></div>
            <div class="stat-label">Pending Requests</div>
        </div>
        <div class="stat-card success">
            <div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></div>
            <div class="stat-value"><?php echo $stats['approved']; ?></div>
            <div class="stat-label">Approved</div>
        </div>
        <div class="stat-card <?php echo $stats['rejected'] > 0 ? 'danger' : ''; ?>">
            <div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></div>
            <div class="stat-value"><?php echo $stats['rejected']; ?></div>
            <div class="stat-label">Rejected</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
            <div class="stat-value" style="font-size:1.3rem;">&#8369;<?php echo number_format($stats['monthly_total'],0); ?></div>
            <div class="stat-label">This Month</div>
        </div>
    </div>

    <!-- TABLE -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">Purchase Requests</span>
            <button class="btn btn-primary" onclick="openModal('createPRModal')">
                <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                New Request
            </button>
        </div>
        <div class="tbl-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Request #</th><th>Supplier</th><th>Department</th><th>Date</th><th>Total</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                    <tr><td colspan="7" class="empty-td">No purchase requests yet. <a href="#" onclick="openModal('createPRModal');return false;">Create your first →</a></td></tr>
                    <?php else: foreach ($requests as $r): ?>
                    <tr>
                        <td><span class="item-code"><?php echo htmlspecialchars($r['request_number']); ?></span></td>
                        <td><?php echo htmlspecialchars($r['supplier_name'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($r['department'] ?? '—'); ?></td>
                        <td><?php echo date('M d, Y', strtotime($r['request_date'])); ?></td>
                        <td><strong>&#8369;<?php echo number_format($r['total_amount'], 2); ?></strong></td>
                        <td><span class="badge badge-<?php echo $r['status']; ?>"><?php echo ucfirst($r['status']); ?></span></td>
                        <td>
                            <div class="btn-row">
                                <a href="?view=<?php echo $r['id']; ?>" class="btn btn-ghost btn-sm">View</a>
                                <?php if ($r['status'] === 'pending'): ?>
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="pr_id" value="<?php echo $r['id']; ?>">
                                    <input type="hidden" name="status" value="approved">
                                    <button type="submit" class="btn btn-green btn-sm" onclick="return confirm('Approve this request?')">Approve</button>
                                </form>
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="pr_id" value="<?php echo $r['id']; ?>">
                                    <input type="hidden" name="status" value="rejected">
                                    <button type="submit" class="btn btn-red btn-sm" onclick="return confirm('Reject this request?')">Reject</button>
                                </form>
                                <?php endif; ?>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this request?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="pr_id" value="<?php echo $r['id']; ?>">
                                    <button type="submit" class="btn btn-red btn-sm">Del</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($request_details): ?>
    <!-- REQUEST DETAIL PANEL -->
    <div class="panel" style="margin-top:1.4rem;">
        <div class="panel-header">
            <span class="panel-title">Request Details: <?php echo htmlspecialchars($request_details['request_number']); ?></span>
            <a href="purchase_requests.php" class="btn btn-outline btn-sm">← Back</a>
        </div>
        <div style="padding:1.4rem 1.6rem;">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin-bottom:1.4rem;">
                <div><span style="font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;">Supplier</span><div style="font-weight:600;margin-top:.25rem;"><?php echo htmlspecialchars($request_details['supplier_name']??'—'); ?></div></div>
                <div><span style="font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;">Department</span><div style="font-weight:600;margin-top:.25rem;"><?php echo htmlspecialchars($request_details['department']??'—'); ?></div></div>
                <div><span style="font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;">Status</span><div style="margin-top:.25rem;"><span class="badge badge-<?php echo $request_details['status']; ?>"><?php echo ucfirst($request_details['status']); ?></span></div></div>
                <div><span style="font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;">Total Amount</span><div style="font-weight:600;font-size:1.1rem;margin-top:.25rem;color:var(--accent);">&#8369;<?php echo number_format($request_details['total_amount'],2); ?></div></div>
            </div>
            <?php if ($request_items): ?>
            <div class="tbl-wrap"><table class="data-table"><thead><tr><th>Item</th><th>Code</th><th>Qty</th><th>Unit</th><th>Unit Price</th><th>Total</th></tr></thead><tbody>
                <?php foreach ($request_items as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['item_name']??$item['item_description']??'—'); ?></td>
                    <td><span class="item-code"><?php echo htmlspecialchars($item['item_code']??'—'); ?></span></td>
                    <td><?php echo $item['quantity']; ?></td>
                    <td><?php echo htmlspecialchars($item['unit_of_measure']??'pcs'); ?></td>
                    <td>&#8369;<?php echo number_format($item['estimated_unit_price'],2); ?></td>
                    <td><strong>&#8369;<?php echo number_format($item['quantity'] * $item['estimated_unit_price'],2); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</main>
</div>

<!-- CREATE PR MODAL -->
<div id="createPRModal" class="modal">
    <div class="modal-box wide">
        <div class="modal-head">
            <h3>New Purchase Request</h3>
            <button class="modal-close" onclick="closeModal('createPRModal')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="modal-body">
            <form method="POST" id="prForm">
                <input type="hidden" name="action" value="create">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Supplier <span class="req">*</span></label>
                        <select name="supplier_id" id="pr_supplier" class="form-select" required onchange="loadSupplierItems(this.value)">
                            <option value="">— Select Supplier —</option>
                            <?php foreach ($suppliers as $s): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['supplier_name']); ?> (<?php echo $s['supplier_code']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <select name="department" class="form-select">
                            <option>Operations</option><option>Procurement</option><option>Finance</option>
                            <option>HR</option><option>IT</option><option>Logistics</option><option>Other</option>
                        </select>
                    </div>
                </div>
                <div id="items-section" style="display:none;margin-top:1rem;">
                    <div style="font-size:.8rem;font-weight:600;color:var(--text);margin-bottom:.7rem;">Select Items</div>
                    <div id="items-container">
                        <div style="text-align:center;color:var(--muted);padding:1.5rem;font-size:.84rem;">Loading items…</div>
                    </div>
                </div>
                <div id="selected-items-hidden"></div>
                <div id="pr-total" style="text-align:right;font-weight:600;font-size:1rem;color:var(--accent);margin-top:.8rem;display:none;"></div>
                <button type="submit" class="submit-btn">Submit Purchase Request</button>
            </form>
        </div>
    </div>
</div>

<script>
var selectedItems = {};

async function loadSupplierItems(supplierId) {
    var sec = document.getElementById('items-section');
    var cont = document.getElementById('items-container');
    if (!supplierId) { sec.style.display='none'; return; }
    sec.style.display = 'block';
    cont.innerHTML = '<div style="text-align:center;color:var(--muted);padding:1.5rem;font-size:.84rem;">Loading…</div>';
    try {
        var res = await fetch('purchase_requests.php?action=get_supplier_items&supplier_id=' + supplierId);
        var items = await res.json();
        if (!items.length) { cont.innerHTML = '<div style="text-align:center;color:var(--muted);padding:1.5rem;font-size:.84rem;">No items available for this supplier.</div>'; return; }
        var html = '<div class="tbl-wrap"><table class="data-table"><thead><tr><th>Select</th><th>Item</th><th>Code</th><th>Unit Price</th><th>Min Order</th><th>Qty</th></tr></thead><tbody>';
        items.forEach(function(item) {
            html += '<tr><td><input type="checkbox" class="item-check" data-item="' + encodeURIComponent(JSON.stringify(item)) + '" onchange="updateSelectedItems()"></td>';
            html += '<td>' + item.item_name + '</td><td><span class="item-code">' + item.item_code + '</span></td>';
            html += '<td>&#8369;' + parseFloat(item.unit_price).toFixed(2) + '</td>';
            html += '<td>' + (item.minimum_order_quantity||1) + '</td>';
            html += '<td><input type="number" class="form-input item-qty" data-id="' + item.supplier_item_id + '" min="' + (item.minimum_order_quantity||1) + '" value="' + (item.minimum_order_quantity||1) + '" style="width:80px;padding:.3rem .5rem;" onchange="updateSelectedItems()"></td></tr>';
        });
        html += '</tbody></table></div>';
        cont.innerHTML = html;
    } catch(e) { cont.innerHTML = '<div style="color:var(--error);padding:1rem;font-size:.84rem;">Failed to load items.</div>'; }
}

function updateSelectedItems() {
    var hidden = document.getElementById('selected-items-hidden');
    var total = 0; hidden.innerHTML = '';
    document.querySelectorAll('.item-check:checked').forEach(function(cb) {
        var item = JSON.parse(decodeURIComponent(cb.dataset.item));
        var qty = parseFloat(document.querySelector('.item-qty[data-id="'+item.supplier_item_id+'"]').value) || 1;
        var price = parseFloat(item.unit_price);
        total += qty * price;
        hidden.innerHTML += '<input type="hidden" name="selected_items[' + item.supplier_item_id + '][supplier_item_id]" value="' + item.supplier_item_id + '">';
        hidden.innerHTML += '<input type="hidden" name="selected_items[' + item.supplier_item_id + '][item_name]" value="' + item.item_name + '">';
        hidden.innerHTML += '<input type="hidden" name="selected_items[' + item.supplier_item_id + '][quantity]" value="' + qty + '">';
        hidden.innerHTML += '<input type="hidden" name="selected_items[' + item.supplier_item_id + '][unit_price]" value="' + price + '">';
    });
    var d = document.getElementById('pr-total');
    if (total > 0) { d.style.display='block'; d.textContent = 'Total: ₱' + total.toLocaleString('en-PH',{minimumFractionDigits:2}); }
    else { d.style.display='none'; }
}
</script>
<?php include 'includes/footer.php'; ?>
