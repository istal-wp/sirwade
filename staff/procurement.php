<?php
/**
 * staff/procurement.php
 * Staff — Procurement (Purchase Requests, POs, Goods Receipts)
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

            case 'create_pr':
                $count = (int)db_scalar("SELECT COUNT(*)+1 FROM purchase_requests");
                $prnum = 'PR-' . date('Y') . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
                db()->prepare("INSERT INTO purchase_requests
                    (pr_number,title,description,department,priority,required_date,
                     estimated_budget,requested_by,status)
                    VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $prnum, trim($_POST['title']), trim($_POST['description'] ?? ''),
                    trim($_POST['department'] ?? ''), $_POST['priority'] ?? 'medium',
                    $_POST['required_date'] ?: null, (float)($_POST['estimated_budget'] ?? 0),
                    $actor, 'pending'
                ]);
                $pr_id = (int)db()->lastInsertId();
                // Add line items if provided
                if (!empty($_POST['item_name']) && is_array($_POST['item_name'])) {
                    $stmt = db()->prepare("INSERT INTO purchase_request_items
                        (pr_id,item_name,description,quantity,unit,estimated_unit_price)
                        VALUES (?,?,?,?,?,?)");
                    foreach ($_POST['item_name'] as $i => $iname) {
                        if (!trim($iname)) continue;
                        $stmt->execute([
                            $pr_id, trim($iname),
                            trim($_POST['item_desc'][$i] ?? ''),
                            (float)($_POST['item_qty'][$i] ?? 1),
                            trim($_POST['item_unit'][$i] ?? 'pcs'),
                            (float)($_POST['item_price'][$i] ?? 0)
                        ]);
                    }
                }
                log_activity('create_purchase_request', 'purchase_requests', $pr_id, $prnum);
                redirect_with_flash('procurement.php', 'success', "Purchase request {$prnum} created.");

            case 'create_po':
                $count = (int)db_scalar("SELECT COUNT(*)+1 FROM purchase_orders");
                $ponum = 'PO-' . date('Y') . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
                db()->prepare("INSERT INTO purchase_orders
                    (po_number,supplier_id,order_date,expected_delivery_date,
                     payment_terms,delivery_address,notes,status,created_by,total_amount)
                    VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $ponum, (int)$_POST['supplier_id'], date('Y-m-d'),
                    $_POST['expected_delivery_date'] ?: null,
                    trim($_POST['payment_terms'] ?? 'Net 30'),
                    trim($_POST['delivery_address'] ?? ''),
                    trim($_POST['notes'] ?? ''), 'draft', $actor,
                    (float)($_POST['total_amount'] ?? 0)
                ]);
                $po_id = (int)db()->lastInsertId();
                log_activity('create_purchase_order', 'purchase_orders', $po_id, $ponum);
                redirect_with_flash('procurement.php', 'success', "Purchase order {$ponum} created.");

            case 'update_po_status':
                db()->prepare("UPDATE purchase_orders SET status=? WHERE id=?")
                     ->execute([$_POST['status'], (int)$_POST['po_id']]);
                log_activity('update_po_status', 'purchase_orders', (int)$_POST['po_id'], $_POST['status']);
                redirect_with_flash('procurement.php', 'success', "PO status updated to " . ucfirst($_POST['status']) . ".");

            case 'receive_goods':
                $po = db_one("SELECT * FROM purchase_orders WHERE id=?", [(int)$_POST['po_id']]);
                if (!$po) redirect_with_flash('procurement.php', 'error', 'PO not found.');
                $grnum = 'GR-' . date('YmdHis');
                db()->prepare("INSERT INTO goods_receipts
                    (gr_number,po_id,supplier_id,received_date,received_by,notes,status)
                    VALUES (?,?,?,?,?,?,?)")
                ->execute([
                    $grnum, $po['id'], $po['supplier_id'],
                    date('Y-m-d'), $actor,
                    trim($_POST['notes'] ?? ''), 'complete'
                ]);
                db()->prepare("UPDATE purchase_orders SET status='completed' WHERE id=?")->execute([$po['id']]);
                log_activity('receive_goods', 'goods_receipts', $po['id'], $grnum);
                redirect_with_flash('procurement.php', 'success', "Goods received. Receipt {$grnum} created.");

            case 'cancel_pr':
                db()->prepare("UPDATE purchase_requests SET status='cancelled' WHERE id=?")->execute([(int)$_POST['pr_id']]);
                log_activity('cancel_pr', 'purchase_requests', (int)$_POST['pr_id'], '');
                redirect_with_flash('procurement.php', 'success', "Purchase request cancelled.");
        }
    } catch (Throwable $e) {
        error_log('Staff procurement error: ' . $e->getMessage());
        redirect_with_flash('procurement.php', 'error', 'Operation failed.');
    }
}

// ── FETCH ─────────────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'pr';

$stats = [
    'total_prs'  => db_scalar("SELECT COUNT(*) FROM purchase_requests"),
    'pending_prs'=> db_scalar("SELECT COUNT(*) FROM purchase_requests WHERE status='pending'"),
    'total_pos'  => db_scalar("SELECT COUNT(*) FROM purchase_orders"),
    'draft_pos'  => db_scalar("SELECT COUNT(*) FROM purchase_orders WHERE status='draft'"),
    'pending_pos'=> db_scalar("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('draft','sent')"),
    'total_spend'=> db_scalar("SELECT COALESCE(SUM(total_amount),0) FROM purchase_orders WHERE status='completed'"),
];

$suppliers_list = db_all("SELECT id,supplier_name FROM suppliers WHERE status='active' ORDER BY supplier_name");

// PRs
$prs = db_all("SELECT * FROM purchase_requests ORDER BY created_at DESC LIMIT 30");
// POs with supplier name
$pos = db_all("SELECT po.*,s.supplier_name FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id=s.id ORDER BY po.created_at DESC LIMIT 30");
// Recent GRs
$grs = db_all("SELECT gr.*,po.po_number,s.supplier_name FROM goods_receipts gr
    JOIN purchase_orders po ON gr.po_id=po.id
    LEFT JOIN suppliers s ON gr.supplier_id=s.id
    ORDER BY gr.received_date DESC LIMIT 15");

$page_title = 'Procurement'; $page_sub = 'Procurement'; $back_url = 'dashboard.php';
require_once '../includes/staff/header.php';
?>
<main class="main">
<div class="page-title"><h1>Procurement Management</h1><p>Manage purchase requests, purchase orders, and goods receipts</p></div>
<?php echo render_flash(); ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Total PRs</span><div class="stat-badge"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div></div><div class="stat-value"><?php echo (int)$stats['total_prs']; ?></div><div class="stat-sub">Purchase requests</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Pending PRs</span><div class="stat-badge warn-badge"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div></div><div class="stat-value"><?php echo (int)$stats['pending_prs']; ?></div><div class="stat-sub warn">Awaiting approval</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Total POs</span><div class="stat-badge"><svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 001.97-1.67L23 6H6"/></svg></div></div><div class="stat-value"><?php echo (int)$stats['total_pos']; ?></div><div class="stat-sub">Purchase orders</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Open POs</span><div class="stat-badge warn-badge"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div></div><div class="stat-value"><?php echo (int)$stats['pending_pos']; ?></div><div class="stat-sub warn">Draft / sent</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Total Spend</span><div class="stat-badge success-badge"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div></div><div class="stat-value" style="font-size:1.3rem"><?php echo peso((float)$stats['total_spend']); ?></div><div class="stat-sub good">Completed POs</div></div>
</div>

<!-- Tabs -->
<div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1.5rem">
    <?php foreach (['pr'=>'Purchase Requests','po'=>'Purchase Orders','gr'=>'Goods Receipts'] as $t=>$label): ?>
    <a href="?tab=<?php echo $t; ?>" style="display:flex;align-items:center;gap:7px;padding:.6rem 1.1rem;border:1.5px solid <?php echo $tab===$t?'var(--navy)':'var(--border)'; ?>;border-radius:8px;background:<?php echo $tab===$t?'var(--navy)':'var(--white)'; ?>;font-size:.84rem;font-weight:500;color:<?php echo $tab===$t?'#fff':'var(--muted)'; ?>;text-decoration:none"><?php echo $label; ?></a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'pr'): ?>
<div class="panel">
    <div class="controls-bar"><h2>Purchase Requests</h2>
        <button onclick="openModal('addPRModal')" class="btn btn-primary">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>New Request</button>
    </div>
</div>
<div class="panel" style="padding:0;overflow:hidden">
  <table class="data-table">
    <thead><tr><th>PR Number</th><th>Title</th><th>Department</th><th>Priority</th><th>Required By</th><th>Budget</th><th>Status</th><th>Requested By</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (empty($prs)): ?>
    <tr><td colspan="9"><div class="empty-state"><h3>No purchase requests yet</h3></div></td></tr>
    <?php else: ?>
    <?php foreach ($prs as $pr): ?>
    <tr>
        <td><span style="font-family:'DM Mono',monospace;font-size:.8rem"><?php echo h($pr['pr_number']); ?></span></td>
        <td><?php echo h($pr['title']); ?></td>
        <td><?php echo h($pr['department'] ?? '—'); ?></td>
        <td><?php echo render_badge($pr['priority'] ?? 'medium'); ?></td>
        <td><?php echo $pr['required_date'] ? fmt_date($pr['required_date']) : '—'; ?></td>
        <td><?php echo peso((float)$pr['estimated_budget']); ?></td>
        <td><?php echo render_badge($pr['status']); ?></td>
        <td><?php echo h($pr['requested_by']); ?></td>
        <td>
            <?php if ($pr['status'] === 'pending'): ?>
            <button onclick="cancelPR(<?php echo (int)$pr['id']; ?>)" class="btn btn-danger btn-sm">Cancel</button>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($tab === 'po'): ?>
<div class="panel">
    <div class="controls-bar"><h2>Purchase Orders</h2>
        <button onclick="openModal('addPOModal')" class="btn btn-primary">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Create PO</button>
    </div>
</div>
<div class="panel" style="padding:0;overflow:hidden">
  <table class="data-table">
    <thead><tr><th>PO Number</th><th>Supplier</th><th>Order Date</th><th>Expected Delivery</th><th>Total Amount</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (empty($pos)): ?>
    <tr><td colspan="7"><div class="empty-state"><h3>No purchase orders yet</h3></div></td></tr>
    <?php else: ?>
    <?php foreach ($pos as $po): ?>
    <tr>
        <td><span style="font-family:'DM Mono',monospace;font-size:.8rem"><?php echo h($po['po_number']); ?></span></td>
        <td><?php echo h($po['supplier_name'] ?? '—'); ?></td>
        <td><?php echo fmt_date($po['order_date'] ?? $po['created_at']); ?></td>
        <td><?php echo $po['expected_delivery_date'] ? fmt_date($po['expected_delivery_date']) : '—'; ?></td>
        <td><strong><?php echo peso((float)$po['total_amount']); ?></strong></td>
        <td><?php echo render_badge($po['status']); ?></td>
        <td>
            <div style="display:flex;gap:.4rem">
            <?php if (in_array($po['status'], ['draft','sent'])): ?>
            <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="update_po_status">
                <input type="hidden" name="po_id" value="<?php echo (int)$po['id']; ?>">
                <select name="status" onchange="this.form.submit()" class="form-control" style="width:auto;padding:.3rem .5rem;font-size:.78rem">
                    <?php foreach (['draft','sent','confirmed','completed','cancelled'] as $st): ?>
                    <option value="<?php echo $st; ?>"<?php echo $po['status']===$st?' selected':''; ?>><?php echo ucfirst($st); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
            <?php if (in_array($po['status'], ['confirmed','sent'])): ?>
            <button onclick="receiveGoods(<?php echo (int)$po['id']; ?>)" class="btn btn-success btn-sm">Receive</button>
            <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($tab === 'gr'): ?>
<div class="panel">
    <div class="controls-bar"><h2>Goods Receipts</h2></div>
</div>
<div class="panel" style="padding:0;overflow:hidden">
  <table class="data-table">
    <thead><tr><th>GR Number</th><th>PO Number</th><th>Supplier</th><th>Received Date</th><th>Received By</th><th>Status</th></tr></thead>
    <tbody>
    <?php if (empty($grs)): ?>
    <tr><td colspan="6"><div class="empty-state"><h3>No goods receipts yet</h3></div></td></tr>
    <?php else: ?>
    <?php foreach ($grs as $gr): ?>
    <tr>
        <td><span style="font-family:'DM Mono',monospace;font-size:.8rem"><?php echo h($gr['gr_number']); ?></span></td>
        <td><?php echo h($gr['po_number']); ?></td>
        <td><?php echo h($gr['supplier_name'] ?? '—'); ?></td>
        <td><?php echo fmt_date($gr['received_date']); ?></td>
        <td><?php echo h($gr['received_by']); ?></td>
        <td><?php echo render_badge($gr['status']); ?></td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

</main>

<!-- ADD PR MODAL -->
<div id="addPRModal" class="modal">
  <div class="modal-box" style="max-width:620px">
    <div class="modal-head"><h3>New Purchase Request</h3><button class="modal-close" onclick="closeModal('addPRModal')">&times;</button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="create_pr">
        <div class="form-grid-2">
          <div class="form-field" style="grid-column:1/-1"><label>Title *</label><input type="text" name="title" class="form-control" required></div>
          <div class="form-field"><label>Department</label><input type="text" name="department" class="form-control"></div>
          <div class="form-field"><label>Priority</label><select name="priority" class="form-control"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="critical">Critical</option></select></div>
          <div class="form-field"><label>Required By</label><input type="date" name="required_date" class="form-control"></div>
          <div class="form-field"><label>Estimated Budget</label><input type="number" name="estimated_budget" class="form-control" step="0.01" min="0" value="0"></div>
        </div>
        <div class="form-field"><label>Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
        <div style="margin-bottom:.75rem">
            <div style="font-size:.85rem;font-weight:600;color:var(--navy);margin-bottom:.5rem">Line Items</div>
            <table style="width:100%;border-collapse:collapse;font-size:.83rem" id="prItemsTable">
                <thead><tr style="background:var(--off)"><th style="padding:.4rem .5rem;text-align:left">Item</th><th style="padding:.4rem .5rem">Qty</th><th style="padding:.4rem .5rem">Unit</th><th style="padding:.4rem .5rem">Unit Price</th></tr></thead>
                <tbody id="prItemsBody">
                    <tr><td><input type="text" name="item_name[]" class="form-control" placeholder="Item name"></td><td><input type="number" name="item_qty[]" class="form-control" value="1" min="1" style="width:70px"></td><td><input type="text" name="item_unit[]" class="form-control" value="pcs" style="width:70px"></td><td><input type="number" name="item_price[]" class="form-control" value="0" step="0.01" style="width:100px"></td></tr>
                </tbody>
            </table>
            <button type="button" onclick="addPRRow()" class="btn btn-outline btn-sm" style="margin-top:.5rem">+ Add Row</button>
        </div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('addPRModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-primary">Submit Request</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- CREATE PO MODAL -->
<div id="addPOModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><h3>Create Purchase Order</h3><button class="modal-close" onclick="closeModal('addPOModal')">&times;</button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="create_po">
        <div class="form-field"><label>Supplier *</label>
          <select name="supplier_id" class="form-control" required>
            <option value="">— Select Supplier —</option>
            <?php foreach ($suppliers_list as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo h($s['supplier_name']); ?></option><?php endforeach; ?>
          </select></div>
        <div class="form-grid-2">
          <div class="form-field"><label>Expected Delivery</label><input type="date" name="expected_delivery_date" class="form-control"></div>
          <div class="form-field"><label>Payment Terms</label><select name="payment_terms" class="form-control"><option value="Net 30">Net 30</option><option value="Net 60">Net 60</option><option value="COD">COD</option><option value="Advance">Advance</option></select></div>
          <div class="form-field" style="grid-column:1/-1"><label>Total Amount</label><input type="number" name="total_amount" class="form-control" step="0.01" min="0" value="0"></div>
        </div>
        <div class="form-field"><label>Delivery Address</label><textarea name="delivery_address" class="form-control" rows="2"></textarea></div>
        <div class="form-field"><label>Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('addPOModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-primary">Create PO</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- RECEIVE GOODS MODAL -->
<div id="receiveModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><h3>Receive Goods</h3><button class="modal-close" onclick="closeModal('receiveModal')">&times;</button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="receive_goods">
        <input type="hidden" id="recv_po_id" name="po_id">
        <div class="form-field"><label>Notes / Remarks</label><textarea name="notes" class="form-control" rows="3" placeholder="Delivery condition, missing items, discrepancies…"></textarea></div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('receiveModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-success">Confirm Receipt</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function addPRRow(){
    var tb=document.getElementById('prItemsBody');
    var tr=document.createElement('tr');
    tr.innerHTML='<td><input type="text" name="item_name[]" class="form-control" placeholder="Item name"></td><td><input type="number" name="item_qty[]" class="form-control" value="1" min="1" style="width:70px"></td><td><input type="text" name="item_unit[]" class="form-control" value="pcs" style="width:70px"></td><td><input type="number" name="item_price[]" class="form-control" value="0" step="0.01" style="width:100px"></td>';
    tb.appendChild(tr);
}
function receiveGoods(poId){
    document.getElementById('recv_po_id').value=poId;
    openModal('receiveModal');
}
function cancelPR(id){
    if(confirm('Cancel this purchase request?')){
        var f=document.createElement('form');f.method='POST';
        f.innerHTML='<input type="hidden" name="action" value="cancel_pr"><input type="hidden" name="pr_id" value="'+id+'">';
        document.body.appendChild(f);f.submit();
    }
}
</script>
<?php require_once '../includes/staff/footer.php'; ?>
