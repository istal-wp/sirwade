<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

$user_id = $_SESSION['user_id'] ?? 1;
$message = ''; $messageType = '';

/* ── FORM HANDLING ──────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['create_rfq'])) {
        try {
            $pdo->beginTransaction();
            $rfq_number = 'RFQ' . date('Y') . str_pad(rand(1,9999), 4, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare("INSERT INTO rfq_requests (rfq_number,title,description,request_date,response_deadline,delivery_required_date,terms_conditions,created_by,status) VALUES (?,?,?,?,?,?,?,?,'draft')");
            $stmt->execute([$rfq_number, $_POST['title'], $_POST['description'], $_POST['request_date'], $_POST['response_deadline'], $_POST['delivery_required_date']?:null, $_POST['terms_conditions']?:null, $user_id]);
            $rfq_id = $pdo->lastInsertId();
            if (!empty($_POST['items'])) {
                $is = $pdo->prepare("INSERT INTO rfq_items (rfq_id,item_description,quantity,unit_of_measure,specifications,delivery_date) VALUES (?,?,?,?,?,?)");
                foreach ($_POST['items'] as $item) {
                    if (!empty($item['description']) && !empty($item['quantity']))
                        $is->execute([$rfq_id, $item['description'], $item['quantity'], $item['unit_of_measure']?:'pcs', $item['specifications']?:null, $item['delivery_date']?:null]);
                }
            }
            if (!empty($_POST['suppliers'])) {
                $ss = $pdo->prepare("INSERT INTO rfq_suppliers (rfq_id,supplier_id,sent_date) VALUES (?,?,?)");
                foreach ($_POST['suppliers'] as $sid) $ss->execute([$rfq_id, $sid, date('Y-m-d')]);
            }
            $pdo->commit(); $message = "RFQ #{$rfq_number} created!"; $messageType = 'success';
        } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollback(); $message = $e->getMessage(); $messageType = 'error'; }
    }

    if (isset($_POST['update_status'])) {
        try {
            $pdo->prepare("UPDATE rfq_requests SET status=? WHERE id=?")->execute([$_POST['new_status'], $_POST['rfq_id']]);
            if ($_POST['new_status'] === 'sent')
                $pdo->prepare("UPDATE rfq_suppliers SET sent_date=CURRENT_DATE WHERE rfq_id=? AND sent_date IS NULL")->execute([$_POST['rfq_id']]);
            $message = "RFQ status updated!"; $messageType = 'success';
        } catch (Exception $e) { $message = $e->getMessage(); $messageType = 'error'; }
    }

    if (isset($_POST['delete_rfq'])) {
        try {
            $pdo->prepare("DELETE FROM rfq_requests WHERE id=?")->execute([$_POST['rfq_id']]);
            $message = "RFQ deleted."; $messageType = 'success';
        } catch (Exception $e) { $message = $e->getMessage(); $messageType = 'error'; }
    }
}

/* ── PAGE DATA ──────────────────────────────────────────────── */
$stats = [
    'total'    => $pdo->query("SELECT COUNT(*) FROM rfq_requests")->fetchColumn(),
    'active'   => $pdo->query("SELECT COUNT(*) FROM rfq_requests WHERE status IN ('draft','sent','under_review')")->fetchColumn(),
    'pending'  => $pdo->query("SELECT COUNT(*) FROM rfq_suppliers rs JOIN rfq_requests rr ON rs.rfq_id=rr.id WHERE rs.response_received=0 AND rr.status='sent'")->fetchColumn(),
    'awarded'  => $pdo->query("SELECT COUNT(*) FROM rfq_requests WHERE status='awarded'")->fetchColumn(),
];
$rfqs = $pdo->query("SELECT rr.*, (SELECT COUNT(*) FROM rfq_suppliers rs WHERE rs.rfq_id=rr.id) as supplier_count, (SELECT COUNT(*) FROM rfq_items ri WHERE ri.rfq_id=rr.id) as item_count FROM rfq_requests rr ORDER BY rr.created_at DESC")->fetchAll();
$suppliers = $pdo->query("SELECT id,supplier_name,supplier_code FROM suppliers WHERE status='active' ORDER BY supplier_name")->fetchAll();

$rfq_detail = $rfq_items_data = $rfq_suppliers_data = $quotations = null;
if (!empty($_GET['view']) && is_numeric($_GET['view'])) {
    $rfq_id = (int)$_GET['view'];
    $ds = $pdo->prepare("SELECT * FROM rfq_requests WHERE id=?"); $ds->execute([$rfq_id]); $rfq_detail = $ds->fetch();
    if ($rfq_detail) {
        $is = $pdo->prepare("SELECT * FROM rfq_items WHERE rfq_id=?"); $is->execute([$rfq_id]); $rfq_items_data = $is->fetchAll();
        $ss = $pdo->prepare("SELECT rs.*,s.supplier_name FROM rfq_suppliers rs JOIN suppliers s ON rs.supplier_id=s.id WHERE rs.rfq_id=?"); $ss->execute([$rfq_id]); $rfq_suppliers_data = $ss->fetchAll();
        $qs = $pdo->prepare("SELECT sq.*,s.supplier_name FROM supplier_quotations sq JOIN suppliers s ON sq.supplier_id=s.id WHERE sq.rfq_id=?"); $qs->execute([$rfq_id]); $quotations = $qs->fetchAll();
    }
}

$page_title = 'RFQ Management'; $module_subtitle = 'Procurement'; $back_btn_href = 'psm.php'; $back_btn_label = 'Procurement'; $active_nav = 'psm';
include 'includes/head.php';
?>
<body>
<?php include 'includes/topbar.php'; ?>
<div class="layout">
<?php include 'includes/sidebar.php'; ?>
<main class="main-content">

    <div class="page-title">
        <span class="page-title-tag">Procurement / RFQ</span>
        <h1>RFQ <strong>Management</strong></h1>
        <p>Create and manage Requests for Quotation, compare vendor proposals, and streamline sourcing.</p>
    </div>

    <?php if ($message): ?><div class="alert alert-<?php echo $messageType==='success'?'success':'error'; ?>"><svg viewBox="0 0 24 24"><?php echo $messageType==='success'?'<polyline points="20 6 9 17 4 12"/>':'<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>'; ?></svg><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

    <div class="stats-row">
        <div class="stat-card"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></div><div class="stat-value"><?php echo $stats['total']; ?></div><div class="stat-label">Total RFQs</div></div>
        <div class="stat-card <?php echo $stats['active']>0?'warn':''; ?>"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div><div class="stat-value"><?php echo $stats['active']; ?></div><div class="stat-label">Active RFQs</div></div>
        <div class="stat-card <?php echo $stats['pending']>0?'warn':''; ?>"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div><div class="stat-value"><?php echo $stats['pending']; ?></div><div class="stat-label">Pending Responses</div></div>
        <div class="stat-card success"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></div><div class="stat-value"><?php echo $stats['awarded']; ?></div><div class="stat-label">Awarded</div></div>
    </div>

    <?php if ($rfq_detail): ?>
    <!-- RFQ DETAIL VIEW -->
    <div class="panel" style="margin-bottom:1.4rem;">
        <div class="panel-header">
            <span class="panel-title">RFQ: <?php echo htmlspecialchars($rfq_detail['rfq_number']); ?> — <?php echo htmlspecialchars($rfq_detail['title']); ?></span>
            <div style="display:flex;gap:.5rem;">
                <form method="POST" style="display:inline">
                    <input type="hidden" name="rfq_id" value="<?php echo $rfq_detail['id']; ?>">
                    <input type="hidden" name="new_status" value="sent">
                    <?php if ($rfq_detail['status']==='draft'): ?>
                    <button type="submit" name="update_status" class="btn btn-primary">Send to Suppliers</button>
                    <?php endif; ?>
                    <?php if ($rfq_detail['status']==='sent'): ?>
                    <input type="hidden" name="new_status" value="under_review">
                    <button type="submit" name="update_status" class="btn btn-ghost">Mark Under Review</button>
                    <?php endif; ?>
                </form>
                <a href="rfq_management.php" class="btn btn-outline">← Back</a>
            </div>
        </div>
        <div style="padding:1.4rem 1.6rem;">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem;margin-bottom:1.4rem;">
                <div><span style="font-size:.72rem;color:var(--muted);text-transform:uppercase;">Status</span><div style="margin-top:.3rem;"><span class="badge badge-<?php echo $rfq_detail['status']; ?>"><?php echo ucfirst(str_replace('_',' ',$rfq_detail['status'])); ?></span></div></div>
                <div><span style="font-size:.72rem;color:var(--muted);text-transform:uppercase;">Request Date</span><div style="font-weight:600;margin-top:.3rem;"><?php echo date('M d, Y', strtotime($rfq_detail['request_date'])); ?></div></div>
                <div><span style="font-size:.72rem;color:var(--muted);text-transform:uppercase;">Deadline</span><div style="font-weight:600;margin-top:.3rem;"><?php echo date('M d, Y', strtotime($rfq_detail['response_deadline'])); ?></div></div>
            </div>
            <?php if ($rfq_items_data): ?>
            <div style="margin-bottom:1.2rem;"><div style="font-size:.8rem;font-weight:600;margin-bottom:.6rem;">Items Requested</div>
            <div class="tbl-wrap"><table class="data-table"><thead><tr><th>Description</th><th>Qty</th><th>Unit</th><th>Specs</th></tr></thead><tbody>
                <?php foreach ($rfq_items_data as $ri): ?>
                <tr><td><?php echo htmlspecialchars($ri['item_description']); ?></td><td><?php echo $ri['quantity']; ?></td><td><?php echo $ri['unit_of_measure']; ?></td><td><?php echo htmlspecialchars($ri['specifications']??'—'); ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div></div>
            <?php endif; ?>
            <?php if ($rfq_suppliers_data): ?>
            <div><div style="font-size:.8rem;font-weight:600;margin-bottom:.6rem;">Invited Suppliers</div>
            <div class="tbl-wrap"><table class="data-table"><thead><tr><th>Supplier</th><th>Sent Date</th><th>Response</th></tr></thead><tbody>
                <?php foreach ($rfq_suppliers_data as $rs): ?>
                <tr><td><?php echo htmlspecialchars($rs['supplier_name']); ?></td><td><?php echo $rs['sent_date'] ? date('M d, Y',strtotime($rs['sent_date'])) : '—'; ?></td><td><span class="badge <?php echo $rs['response_received']?'badge-normal':'badge-warn'; ?>"><?php echo $rs['response_received']?'Received':'Pending'; ?></span></td></tr>
                <?php endforeach; ?>
            </tbody></table></div></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- RFQ LIST -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">RFQ List</span>
            <button class="btn btn-primary" onclick="openModal('createRFQModal')">
                <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Create RFQ
            </button>
        </div>
        <div class="tbl-wrap">
            <table class="data-table">
                <thead><tr><th>RFQ #</th><th>Title</th><th>Request Date</th><th>Deadline</th><th>Suppliers</th><th>Items</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (empty($rfqs)): ?>
                    <tr><td colspan="8" class="empty-td">No RFQs created yet. <a href="#" onclick="openModal('createRFQModal');return false;">Create your first →</a></td></tr>
                    <?php else: foreach ($rfqs as $rfq): ?>
                    <tr>
                        <td><span class="item-code"><?php echo htmlspecialchars($rfq['rfq_number']); ?></span></td>
                        <td><strong><?php echo htmlspecialchars($rfq['title']); ?></strong></td>
                        <td><?php echo date('M d, Y', strtotime($rfq['request_date'])); ?></td>
                        <td><?php echo date('M d, Y', strtotime($rfq['response_deadline'])); ?></td>
                        <td><?php echo $rfq['supplier_count']; ?></td>
                        <td><?php echo $rfq['item_count']; ?></td>
                        <td><span class="badge badge-<?php echo $rfq['status']; ?>"><?php echo ucfirst(str_replace('_',' ',$rfq['status'])); ?></span></td>
                        <td>
                            <div class="btn-row">
                                <a href="?view=<?php echo $rfq['id']; ?>" class="btn btn-ghost btn-sm">View</a>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this RFQ?')">
                                    <input type="hidden" name="rfq_id" value="<?php echo $rfq['id']; ?>">
                                    <button type="submit" name="delete_rfq" class="btn btn-red btn-sm">Del</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
</div>

<!-- CREATE RFQ MODAL -->
<div id="createRFQModal" class="modal">
    <div class="modal-box wide">
        <div class="modal-head"><h3>Create Request for Quotation</h3><button class="modal-close" onclick="closeModal('createRFQModal')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
        <div class="modal-body">
            <form method="POST">
                <div class="form-group"><label class="form-label">Title <span class="req">*</span></label><input type="text" name="title" class="form-input" required></div>
                <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-textarea"></textarea></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Request Date <span class="req">*</span></label><input type="date" name="request_date" class="form-input" value="<?php echo date('Y-m-d'); ?>" required></div>
                    <div class="form-group"><label class="form-label">Response Deadline <span class="req">*</span></label><input type="date" name="response_deadline" class="form-input" required></div>
                </div>
                <div class="form-group"><label class="form-label">Delivery Required Date</label><input type="date" name="delivery_required_date" class="form-input"></div>

                <div style="margin:1.2rem 0 .6rem;font-size:.85rem;font-weight:600;color:var(--text);">Items to Quote</div>
                <div id="rfq-items-container">
                    <div class="rfq-item form-row" style="margin-bottom:.5rem;">
                        <div class="form-group"><label class="form-label">Description <span class="req">*</span></label><input type="text" name="items[0][description]" class="form-input"></div>
                        <div class="form-group"><label class="form-label">Qty <span class="req">*</span></label><input type="number" name="items[0][quantity]" class="form-input" min="1"></div>
                    </div>
                </div>
                <button type="button" class="btn btn-ghost btn-sm" onclick="addRFQItem()" style="margin-bottom:1rem;">+ Add Item</button>

                <div style="margin:.6rem 0;font-size:.85rem;font-weight:600;color:var(--text);">Invite Suppliers</div>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.5rem;max-height:160px;overflow-y:auto;border:1.5px solid var(--border);border-radius:8px;padding:.7rem;">
                    <?php foreach ($suppliers as $s): ?>
                    <label style="display:flex;align-items:center;gap:.5rem;font-size:.82rem;cursor:pointer;">
                        <input type="checkbox" name="suppliers[]" value="<?php echo $s['id']; ?>">
                        <?php echo htmlspecialchars($s['supplier_name']); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="form-group" style="margin-top:1rem;"><label class="form-label">Terms &amp; Conditions</label><textarea name="terms_conditions" class="form-textarea" rows="2"></textarea></div>
                <button type="submit" name="create_rfq" class="submit-btn">Create RFQ</button>
            </form>
        </div>
    </div>
</div>

<script>
var rfqItemIdx = 1;
function addRFQItem() {
    var c = document.getElementById('rfq-items-container');
    var d = document.createElement('div');
    d.className = 'rfq-item form-row'; d.style.marginBottom = '.5rem';
    d.innerHTML = '<div class="form-group"><label class="form-label">Description</label><input type="text" name="items[' + rfqItemIdx + '][description]" class="form-input"></div>' +
                  '<div class="form-group"><label class="form-label">Qty</label><input type="number" name="items[' + rfqItemIdx + '][quantity]" class="form-input" min="1"><br><button type="button" class="btn btn-red btn-sm" style="margin-top:.3rem;" onclick="this.closest(\'.rfq-item\').remove()">Remove</button></div>';
    c.appendChild(d); rfqItemIdx++;
}
</script>
<?php include 'includes/footer.php'; ?>
