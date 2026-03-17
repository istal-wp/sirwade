<?php
/**
 * staff/suppliers.php
 * Staff — Supplier Management (CRUD)
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
            case 'add_supplier':
                $count = (int)db_scalar("SELECT COUNT(*) FROM suppliers");
                $code  = 'SUP-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
                db()->prepare("INSERT INTO suppliers
                    (supplier_code, supplier_name, contact_person, email, phone, address,
                     city, country, payment_terms, lead_time_days, status, notes, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $code, trim($_POST['supplier_name']), trim($_POST['contact_person'] ?? ''),
                    trim($_POST['email'] ?? ''), trim($_POST['phone'] ?? ''),
                    trim($_POST['address'] ?? ''), trim($_POST['city'] ?? ''),
                    trim($_POST['country'] ?? 'Philippines'),
                    trim($_POST['payment_terms'] ?? 'Net 30'),
                    (int)($_POST['lead_time_days'] ?? 7),
                    $_POST['status'] ?? 'active',
                    trim($_POST['notes'] ?? ''), $actor
                ]);
                $sid = (int)db()->lastInsertId();
                log_activity('add_supplier', 'suppliers', $sid, $_POST['supplier_name']);
                redirect_with_flash('suppliers.php', 'success', "Supplier '{$_POST['supplier_name']}' added.");
                break;

            case 'update_supplier':
                db()->prepare("UPDATE suppliers SET supplier_name=?, contact_person=?, email=?,
                    phone=?, address=?, city=?, country=?, payment_terms=?,
                    lead_time_days=?, status=?, notes=? WHERE id=?")
                ->execute([
                    trim($_POST['supplier_name']), trim($_POST['contact_person'] ?? ''),
                    trim($_POST['email'] ?? ''), trim($_POST['phone'] ?? ''),
                    trim($_POST['address'] ?? ''), trim($_POST['city'] ?? ''),
                    trim($_POST['country'] ?? ''), trim($_POST['payment_terms'] ?? ''),
                    (int)$_POST['lead_time_days'], $_POST['status'],
                    trim($_POST['notes'] ?? ''), (int)$_POST['supplier_id']
                ]);
                log_activity('update_supplier', 'suppliers', (int)$_POST['supplier_id'], $_POST['supplier_name']);
                redirect_with_flash('suppliers.php', 'success', "Supplier updated.");
                break;

            case 'delete_supplier':
                db()->prepare("UPDATE suppliers SET status='inactive' WHERE id=?")->execute([(int)$_POST['supplier_id']]);
                log_activity('deactivate_supplier', 'suppliers', (int)$_POST['supplier_id'], '');
                redirect_with_flash('suppliers.php', 'success', "Supplier deactivated.");
                break;

            case 'add_evaluation':
                db()->prepare("INSERT INTO supplier_evaluations
                    (supplier_id, evaluation_date, quality_score, delivery_score,
                     price_score, service_score, overall_score, comments, evaluated_by)
                    VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([
                    (int)$_POST['supplier_id'], $_POST['evaluation_date'],
                    (int)$_POST['quality_score'], (int)$_POST['delivery_score'],
                    (int)$_POST['price_score'],   (int)$_POST['service_score'],
                    round(((int)$_POST['quality_score'] + (int)$_POST['delivery_score'] +
                           (int)$_POST['price_score']   + (int)$_POST['service_score']) / 4, 1),
                    trim($_POST['comments'] ?? ''), $actor
                ]);
                log_activity('add_supplier_evaluation', 'supplier_evaluations', (int)$_POST['supplier_id'], '');
                redirect_with_flash('suppliers.php', 'success', "Evaluation recorded.");
                break;
        }
    } catch (Throwable $e) {
        error_log('Staff suppliers error: ' . $e->getMessage());
        redirect_with_flash('suppliers.php', 'error', 'Operation failed.');
    }
}

// Fetch
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? 'active';
$where  = ['1=1']; $params = [];
if ($search) { $where[] = "(supplier_name LIKE ? OR contact_person LIKE ? OR email LIKE ?)"; $params = ["%$search%","%$search%","%$search%"]; }
if ($status) { $where[] = "status=?"; $params[] = $status; }

$total  = (int)db_scalar("SELECT COUNT(*) FROM suppliers WHERE " . implode(' AND ', $where), $params);
$pg     = paginate($total, 20);
$suppliers = db_all("SELECT s.*,
    (SELECT COUNT(*) FROM purchase_orders WHERE supplier_id=s.id) as total_orders,
    (SELECT COALESCE(SUM(total_amount),0) FROM purchase_orders WHERE supplier_id=s.id) as total_value,
    (SELECT AVG(overall_score) FROM supplier_evaluations WHERE supplier_id=s.id) as avg_rating
    FROM suppliers s WHERE " . implode(' AND ', $where) . "
    ORDER BY s.supplier_name LIMIT {$pg['limit']} OFFSET {$pg['offset']}", $params);

$stats = [
    'total'    => db_scalar("SELECT COUNT(*) FROM suppliers"),
    'active'   => db_scalar("SELECT COUNT(*) FROM suppliers WHERE status='active'"),
    'total_orders' => db_scalar("SELECT COUNT(*) FROM purchase_orders"),
    'total_value'  => db_scalar("SELECT COALESCE(SUM(total_amount),0) FROM purchase_orders"),
];

$page_title = 'Supplier Management'; $page_sub = 'Supplier Management'; $back_url = 'dashboard.php';
require_once '../includes/staff/header.php';
?>
<main class="main">
<div class="page-title"><h1>Supplier Management</h1><p>Add and manage supplier information, evaluations, and performance data</p></div>
<?php echo render_flash(); ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Total Suppliers</span><div class="stat-badge"><svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></div></div><div class="stat-value"><?php echo $stats['total']; ?></div><div class="stat-sub good">Registered</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Active</span><div class="stat-badge success-badge"><svg viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg></div></div><div class="stat-value"><?php echo $stats['active']; ?></div><div class="stat-sub good">In service</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Total Orders</span><div class="stat-badge"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div></div><div class="stat-value"><?php echo $stats['total_orders']; ?></div><div class="stat-sub">Purchase orders</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Total PO Value</span><div class="stat-badge success-badge"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div></div><div class="stat-value" style="font-size:1.3rem"><?php echo peso((float)$stats['total_value']); ?></div><div class="stat-sub good">Procurement spend</div></div>
</div>

<div class="panel">
    <div class="controls-bar">
        <h2>Suppliers</h2>
        <div class="btn-row">
            <button onclick="openModal('addSupplierModal')" class="btn btn-primary">
                <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Add Supplier</button>
        </div>
    </div>
    <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem">
        <input type="text" name="search" class="form-control" placeholder="Search suppliers…" value="<?php echo h($search); ?>" style="max-width:280px">
        <select name="status" class="form-control" style="max-width:160px">
            <option value="active"<?php echo $status==='active'?' selected':''; ?>>Active</option>
            <option value="inactive"<?php echo $status==='inactive'?' selected':''; ?>>Inactive</option>
            <option value=""<?php echo $status===''?' selected':''; ?>>All</option>
        </select>
        <button type="submit" class="btn btn-outline">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Filter</button>
    </form>
</div>

<div class="panel" style="padding:0;overflow:hidden">
  <table class="data-table">
    <thead><tr><th>Code</th><th>Supplier Name</th><th>Contact</th><th>Email</th><th>Phone</th><th>Orders</th><th>Total Value</th><th>Rating</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (empty($suppliers)): ?>
    <tr><td colspan="10"><div class="empty-state"><svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg><h3>No suppliers found</h3></div></td></tr>
    <?php else: ?>
    <?php foreach ($suppliers as $s): ?>
    <tr>
        <td><span style="font-family:'DM Mono',monospace;font-size:.8rem"><?php echo h($s['supplier_code'] ?? '—'); ?></span></td>
        <td><strong><?php echo h($s['supplier_name']); ?></strong></td>
        <td><?php echo h($s['contact_person'] ?? '—'); ?></td>
        <td><a href="mailto:<?php echo h($s['email'] ?? ''); ?>" style="color:var(--accent)"><?php echo h($s['email'] ?? '—'); ?></a></td>
        <td><?php echo h($s['phone'] ?? '—'); ?></td>
        <td><?php echo (int)$s['total_orders']; ?></td>
        <td><?php echo peso((float)$s['total_value']); ?></td>
        <td><?php $r = round((float)($s['avg_rating'] ?? 0), 1); echo $r > 0 ? "⭐ {$r}" : '—'; ?></td>
        <td><?php echo render_badge($s['status']); ?></td>
        <td>
            <div style="display:flex;gap:.4rem">
                <button onclick="editSupplier(<?php echo h(json_encode($s)); ?>)" class="btn btn-warning btn-sm" title="Edit">
                    <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </button>
                <button onclick="evalSupplier(<?php echo (int)$s['id']; ?>, '<?php echo h($s['supplier_name']); ?>')" class="btn btn-success btn-sm" title="Evaluate">
                    <svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                </button>
                <button onclick="deleteSupplier(<?php echo (int)$s['id']; ?>, '<?php echo h($s['supplier_name']); ?>')" class="btn btn-danger btn-sm" title="Deactivate">
                    <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                </button>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>
</main>

<!-- ADD SUPPLIER MODAL -->
<div id="addSupplierModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><h3>Add New Supplier</h3><button class="modal-close" onclick="closeModal('addSupplierModal')">&times;</button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="add_supplier">
        <div class="form-grid-2">
          <div class="form-field" style="grid-column:1/-1"><label>Supplier Name *</label><input type="text" name="supplier_name" class="form-control" required></div>
          <div class="form-field"><label>Contact Person</label><input type="text" name="contact_person" class="form-control"></div>
          <div class="form-field"><label>Email</label><input type="email" name="email" class="form-control"></div>
          <div class="form-field"><label>Phone</label><input type="text" name="phone" class="form-control"></div>
          <div class="form-field"><label>Payment Terms</label>
            <select name="payment_terms" class="form-control"><option value="Net 30">Net 30</option><option value="Net 60">Net 60</option><option value="COD">COD</option><option value="Advance">Advance</option></select></div>
          <div class="form-field"><label>Lead Time (days)</label><input type="number" name="lead_time_days" class="form-control" value="7" min="0"></div>
          <div class="form-field"><label>City</label><input type="text" name="city" class="form-control"></div>
          <div class="form-field"><label>Country</label><input type="text" name="country" class="form-control" value="Philippines"></div>
        </div>
        <div class="form-field"><label>Address</label><textarea name="address" class="form-control" rows="2"></textarea></div>
        <div class="form-field"><label>Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('addSupplierModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-primary">Add Supplier</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- EDIT SUPPLIER MODAL -->
<div id="editSupplierModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><h3>Edit Supplier</h3><button class="modal-close" onclick="closeModal('editSupplierModal')">&times;</button></div>
    <div class="modal-body">
      <form method="POST" id="editSupplierForm">
        <input type="hidden" name="action" value="update_supplier">
        <input type="hidden" id="es_id" name="supplier_id">
        <div class="form-grid-2">
          <div class="form-field" style="grid-column:1/-1"><label>Supplier Name *</label><input type="text" id="es_name" name="supplier_name" class="form-control" required></div>
          <div class="form-field"><label>Contact Person</label><input type="text" id="es_contact" name="contact_person" class="form-control"></div>
          <div class="form-field"><label>Email</label><input type="email" id="es_email" name="email" class="form-control"></div>
          <div class="form-field"><label>Phone</label><input type="text" id="es_phone" name="phone" class="form-control"></div>
          <div class="form-field"><label>Payment Terms</label><select id="es_payment" name="payment_terms" class="form-control"><option value="Net 30">Net 30</option><option value="Net 60">Net 60</option><option value="COD">COD</option><option value="Advance">Advance</option></select></div>
          <div class="form-field"><label>Lead Time (days)</label><input type="number" id="es_lead" name="lead_time_days" class="form-control" min="0"></div>
          <div class="form-field"><label>City</label><input type="text" id="es_city" name="city" class="form-control"></div>
          <div class="form-field"><label>Country</label><input type="text" id="es_country" name="country" class="form-control"></div>
        </div>
        <div class="form-field"><label>Address</label><textarea id="es_address" name="address" class="form-control" rows="2"></textarea></div>
        <div class="form-field"><label>Status</label><select id="es_status" name="status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
        <div class="form-field"><label>Notes</label><textarea id="es_notes" name="notes" class="form-control" rows="2"></textarea></div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('editSupplierModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-primary">Update Supplier</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- EVALUATION MODAL -->
<div id="evalModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><h3>Supplier Evaluation</h3><button class="modal-close" onclick="closeModal('evalModal')">&times;</button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="add_evaluation">
        <input type="hidden" id="eval_supplier_id" name="supplier_id">
        <div class="form-field"><label>Supplier</label><input type="text" id="eval_supplier_name" class="form-control" readonly style="background:var(--off)"></div>
        <div class="form-field"><label>Evaluation Date *</label><input type="date" name="evaluation_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
        <div class="form-grid-2">
          <div class="form-field"><label>Quality Score (1-10)</label><input type="number" name="quality_score" class="form-control" min="1" max="10" required></div>
          <div class="form-field"><label>Delivery Score (1-10)</label><input type="number" name="delivery_score" class="form-control" min="1" max="10" required></div>
          <div class="form-field"><label>Price Score (1-10)</label><input type="number" name="price_score" class="form-control" min="1" max="10" required></div>
          <div class="form-field"><label>Service Score (1-10)</label><input type="number" name="service_score" class="form-control" min="1" max="10" required></div>
        </div>
        <div class="form-field"><label>Comments</label><textarea name="comments" class="form-control"></textarea></div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('evalModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-primary">Submit Evaluation</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function editSupplier(s){
    document.getElementById('es_id').value=s.id;
    document.getElementById('es_name').value=s.supplier_name||'';
    document.getElementById('es_contact').value=s.contact_person||'';
    document.getElementById('es_email').value=s.email||'';
    document.getElementById('es_phone').value=s.phone||'';
    document.getElementById('es_payment').value=s.payment_terms||'Net 30';
    document.getElementById('es_lead').value=s.lead_time_days||7;
    document.getElementById('es_city').value=s.city||'';
    document.getElementById('es_country').value=s.country||'Philippines';
    document.getElementById('es_address').value=s.address||'';
    document.getElementById('es_status').value=s.status||'active';
    document.getElementById('es_notes').value=s.notes||'';
    openModal('editSupplierModal');
}
function evalSupplier(id,name){
    document.getElementById('eval_supplier_id').value=id;
    document.getElementById('eval_supplier_name').value=name;
    openModal('evalModal');
}
function deleteSupplier(id,name){
    if(confirm('Deactivate supplier "'+name+'"?')){
        var f=document.createElement('form');f.method='POST';
        f.innerHTML='<input type="hidden" name="action" value="delete_supplier"><input type="hidden" name="supplier_id" value="'+id+'">';
        document.body.appendChild(f);f.submit();
    }
}
</script>
<?php require_once '../includes/staff/footer.php'; ?>
