<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

/* ── FORM HANDLING ──────────────────────────────────────────── */
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'create':
            try {
                $count = $pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();
                $supplier_code = 'SUP' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
                $tax_id = 'TAX-' . date('Y') . str_pad($count + 1, 8, '0', STR_PAD_LEFT);
                $stmt = $pdo->prepare("INSERT INTO suppliers (supplier_code, supplier_name, contact_person, email, phone, address, payment_terms, tax_id, status) VALUES (?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$supplier_code, $_POST['supplier_name'], $_POST['contact_person'], $_POST['email'], $_POST['phone'], $_POST['address'], $_POST['payment_terms'], $tax_id, $_POST['status']]);
                $message = 'Supplier created successfully!'; $message_type = 'success';
            } catch (PDOException $e) { $message = 'Error: ' . $e->getMessage(); $message_type = 'error'; }
            break;
        case 'update':
            try {
                $stmt = $pdo->prepare("UPDATE suppliers SET supplier_name=?, contact_person=?, email=?, phone=?, address=?, payment_terms=?, tax_id=?, status=?, rating=? WHERE id=?");
                $stmt->execute([$_POST['supplier_name'], $_POST['contact_person'], $_POST['email'], $_POST['phone'], $_POST['address'], $_POST['payment_terms'], $_POST['tax_id'], $_POST['status'], $_POST['rating'] ?: null, $_POST['supplier_id']]);
                $message = 'Supplier updated successfully!'; $message_type = 'success';
            } catch (PDOException $e) { $message = 'Error: ' . $e->getMessage(); $message_type = 'error'; }
            break;
        case 'delete':
            try {
                $pdo->prepare("UPDATE suppliers SET status='inactive' WHERE id=?")->execute([$_POST['supplier_id']]);
                $message = 'Supplier deactivated.'; $message_type = 'success';
            } catch (PDOException $e) { $message = 'Error: ' . $e->getMessage(); $message_type = 'error'; }
            break;
    }
}

/* ── PAGE DATA ──────────────────────────────────────────────── */
$search        = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? 'all';
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 10;
$offset        = ($page - 1) * $per_page;

$where_conds = []; $params = [];
if ($search) {
    $where_conds[] = "(supplier_name LIKE ? OR supplier_code LIKE ? OR contact_person LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
if ($status_filter !== 'all') { $where_conds[] = "status = ?"; $params[] = $status_filter; }
$where = $where_conds ? 'WHERE ' . implode(' AND ', $where_conds) : '';

$total_count = $pdo->prepare("SELECT COUNT(*) FROM suppliers $where");
$total_count->execute($params);
$total_pages = max(1, ceil($total_count->fetchColumn() / $per_page));

$stmt = $pdo->prepare("SELECT * FROM suppliers $where ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$suppliers = $stmt->fetchAll();

$stats = [
    'active'      => $pdo->query("SELECT COUNT(*) FROM suppliers WHERE status='active'")->fetchColumn(),
    'inactive'    => $pdo->query("SELECT COUNT(*) FROM suppliers WHERE status='inactive'")->fetchColumn(),
    'blacklisted' => $pdo->query("SELECT COUNT(*) FROM suppliers WHERE status='blacklisted'")->fetchColumn(),
    'avg_rating'  => round($pdo->query("SELECT COALESCE(AVG(rating),0) FROM suppliers WHERE rating IS NOT NULL")->fetchColumn(), 1),
];

$edit_supplier = null;
if (!empty($_GET['edit'])) {
    $es = $pdo->prepare("SELECT * FROM suppliers WHERE id=?");
    $es->execute([$_GET['edit']]);
    $edit_supplier = $es->fetch();
}

$page_title      = 'Supplier Management';
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
        <span class="page-title-tag">Procurement / Suppliers</span>
        <h1>Supplier <strong>Management</strong></h1>
        <p>Register, evaluate, and manage your supplier database.</p>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
        <svg viewBox="0 0 24 24"><?php echo $message_type === 'success' ? '<polyline points="20 6 9 17 4 12"/>' : '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>'; ?></svg>
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="stats-row">
        <div class="stat-card success">
            <div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg></div>
            <div class="stat-value"><?php echo $stats['active']; ?></div>
            <div class="stat-label">Active Suppliers</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg></div>
            <div class="stat-value"><?php echo $stats['inactive']; ?></div>
            <div class="stat-label">Inactive Suppliers</div>
        </div>
        <div class="stat-card <?php echo $stats['blacklisted'] > 0 ? 'danger' : ''; ?>">
            <div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg></div>
            <div class="stat-value"><?php echo $stats['blacklisted']; ?></div>
            <div class="stat-label">Blacklisted</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></div>
            <div class="stat-value"><?php echo $stats['avg_rating'] ?: '—'; ?></div>
            <div class="stat-label">Avg. Rating</div>
        </div>
    </div>

    <!-- SUPPLIER TABLE PANEL -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">Suppliers</span>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <input type="text" name="search" class="form-input" placeholder="Search suppliers…" value="<?php echo htmlspecialchars($search); ?>" style="width:200px;padding:.42rem .8rem;">
                    <select name="status" class="form-select" style="width:140px;padding:.42rem .8rem;">
                        <option value="all" <?php echo $status_filter==='all'?'selected':''; ?>>All Status</option>
                        <option value="active"      <?php echo $status_filter==='active'?'selected':''; ?>>Active</option>
                        <option value="inactive"    <?php echo $status_filter==='inactive'?'selected':''; ?>>Inactive</option>
                        <option value="blacklisted" <?php echo $status_filter==='blacklisted'?'selected':''; ?>>Blacklisted</option>
                    </select>
                    <button type="submit" class="btn btn-ghost">Filter</button>
                    <?php if ($search || $status_filter !== 'all'): ?>
                    <a href="supplier_management.php" class="btn btn-outline">Clear</a>
                    <?php endif; ?>
                </form>
                <button class="btn btn-primary" onclick="openModal('addSupplierModal')">
                    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Add Supplier
                </button>
            </div>
        </div>
        <div class="tbl-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Code</th><th>Supplier Name</th><th>Contact</th><th>Email</th>
                        <th>Phone</th><th>Payment Terms</th><th>Rating</th><th>Status</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($suppliers)): ?>
                    <tr><td colspan="9" class="empty-td">No suppliers found. <a href="#" onclick="openModal('addSupplierModal');return false;">Add your first supplier →</a></td></tr>
                    <?php else: foreach ($suppliers as $s): ?>
                    <tr>
                        <td><span class="item-code"><?php echo htmlspecialchars($s['supplier_code']); ?></span></td>
                        <td><span class="item-name"><?php echo htmlspecialchars($s['supplier_name']); ?></span></td>
                        <td><?php echo htmlspecialchars($s['contact_person'] ?? '—'); ?></td>
                        <td style="font-size:.78rem;"><?php echo htmlspecialchars($s['email'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($s['phone'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($s['payment_terms'] ?? '—'); ?></td>
                        <td><?php if ($s['rating']): echo str_repeat('★', (int)$s['rating']) . str_repeat('☆', 5 - (int)$s['rating']); else: echo '—'; endif; ?></td>
                        <td><span class="badge badge-<?php echo $s['status']; ?>"><?php echo ucfirst($s['status']); ?></span></td>
                        <td>
                            <div class="btn-row">
                                <button class="btn btn-green btn-sm" onclick="editSupplier(<?php echo htmlspecialchars(json_encode($s)); ?>)">Edit</button>
                                <?php if ($s['status'] !== 'inactive'): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Deactivate this supplier?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="supplier_id" value="<?php echo $s['id']; ?>">
                                    <button type="submit" class="btn btn-red btn-sm">Deactivate</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total_pages > 1): ?>
        <div class="panel-footer" style="display:flex;gap:.4rem;justify-content:center;flex-wrap:wrap;">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>"
               class="btn <?php echo $i === $page ? 'btn-primary' : 'btn-outline'; ?> btn-sm"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</main>
</div>

<!-- ADD SUPPLIER MODAL -->
<div id="addSupplierModal" class="modal <?php echo (!$message && $edit_supplier === null && isset($_POST['action']) && $_POST['action']==='create' && $message_type==='error') ? 'open' : ''; ?>">
    <div class="modal-box">
        <div class="modal-head">
            <h3>Add New Supplier</h3>
            <button class="modal-close" onclick="closeModal('addSupplierModal')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="create">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Supplier Name <span class="req">*</span></label>
                        <input type="text" name="supplier_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contact_person" class="form-input">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-input">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-textarea" rows="2"></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Payment Terms</label>
                        <select name="payment_terms" class="form-select">
                            <option value="Net 30">Net 30</option><option value="Net 60">Net 60</option>
                            <option value="Net 90">Net 90</option><option value="COD">COD</option>
                            <option value="Prepaid">Prepaid</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="blacklisted">Blacklisted</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="submit-btn">Add Supplier</button>
            </form>
        </div>
    </div>
</div>

<!-- EDIT SUPPLIER MODAL -->
<div id="editSupplierModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h3>Edit Supplier</h3>
            <button class="modal-close" onclick="closeModal('editSupplierModal')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="supplier_id" id="edit_id">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Supplier Name <span class="req">*</span></label>
                        <input type="text" name="supplier_name" id="edit_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contact_person" id="edit_contact" class="form-input">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="edit_email" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" id="edit_phone" class="form-input">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <textarea name="address" id="edit_address" class="form-textarea" rows="2"></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Tax ID</label>
                        <input type="text" name="tax_id" id="edit_tax_id" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Payment Terms</label>
                        <select name="payment_terms" id="edit_terms" class="form-select">
                            <option value="Net 30">Net 30</option><option value="Net 60">Net 60</option>
                            <option value="Net 90">Net 90</option><option value="COD">COD</option><option value="Prepaid">Prepaid</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Rating (1-5)</label>
                        <select name="rating" id="edit_rating" class="form-select">
                            <option value="">No Rating</option>
                            <option value="1">1 ★</option><option value="2">2 ★★</option>
                            <option value="3">3 ★★★</option><option value="4">4 ★★★★</option>
                            <option value="5">5 ★★★★★</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" id="edit_status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="blacklisted">Blacklisted</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="submit-btn">Save Changes</button>
            </form>
        </div>
    </div>
</div>

<script>
function editSupplier(s) {
    document.getElementById('edit_id').value      = s.id;
    document.getElementById('edit_name').value    = s.supplier_name || '';
    document.getElementById('edit_contact').value = s.contact_person || '';
    document.getElementById('edit_email').value   = s.email || '';
    document.getElementById('edit_phone').value   = s.phone || '';
    document.getElementById('edit_address').value = s.address || '';
    document.getElementById('edit_tax_id').value  = s.tax_id || '';
    document.getElementById('edit_terms').value   = s.payment_terms || 'Net 30';
    document.getElementById('edit_rating').value  = s.rating || '';
    document.getElementById('edit_status').value  = s.status || 'active';
    openModal('editSupplierModal');
}
</script>
<?php include 'includes/footer.php'; ?>
