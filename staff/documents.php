<?php
/**
 * staff/documents.php
 * Staff — Document Management, Compliance & Workflows
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

            case 'add_document':
                $count = (int)db_scalar("SELECT COUNT(*)+1 FROM documents");
                $dcode = 'DOC-' . date('Y') . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
                db()->prepare("INSERT INTO documents
                    (document_code,title,description,document_type_id,status,priority,
                     related_supplier_id,related_project_id,expiry_date,created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $dcode, trim($_POST['title']),
                    trim($_POST['description'] ?? ''),
                    ($_POST['document_type_id'] ? (int)$_POST['document_type_id'] : null),
                    'active', $_POST['priority'] ?? 'medium',
                    ($_POST['related_supplier_id'] ? (int)$_POST['related_supplier_id'] : null),
                    ($_POST['related_project_id']  ? (int)$_POST['related_project_id']  : null),
                    $_POST['expiry_date'] ?: null, $actor
                ]);
                $did = (int)db()->lastInsertId();
                log_activity('add_document', 'documents', $did, $_POST['title']);
                redirect_with_flash('documents.php', 'success', "Document '{$_POST['title']}' added as {$dcode}.");

            case 'update_document_status':
                db()->prepare("UPDATE documents SET status=? WHERE id=?")
                     ->execute([$_POST['status'], (int)$_POST['document_id']]);
                log_activity('update_document_status', 'documents', (int)$_POST['document_id'], $_POST['status']);
                redirect_with_flash('documents.php', 'success', "Document status updated.");

            case 'create_workflow':
                db()->prepare("INSERT INTO document_workflows
                    (document_id,step_name,step_order,assigned_to,due_date,comments,status)
                    VALUES (?,?,?,?,?,?,'pending')")
                ->execute([
                    (int)$_POST['document_id'],
                    trim($_POST['step_name']),
                    (int)($_POST['step_order'] ?? 1),
                    trim($_POST['assigned_to'] ?? $actor),
                    $_POST['due_date'] ?: null,
                    trim($_POST['comments'] ?? '')
                ]);
                log_activity('create_workflow', 'document_workflows', (int)$_POST['document_id'], $_POST['step_name']);
                redirect_with_flash('documents.php?tab=workflows', 'success', "Workflow step created.");

            case 'update_workflow':
                $completed = in_array($_POST['status'], ['approved','rejected']) ? date('Y-m-d H:i:s') : null;
                db()->prepare("UPDATE document_workflows SET status=?,comments=?,completed_at=? WHERE id=?")
                     ->execute([$_POST['status'], trim($_POST['comments'] ?? ''), $completed, (int)$_POST['workflow_id']]);
                log_activity('update_workflow', 'document_workflows', (int)$_POST['workflow_id'], $_POST['status']);
                redirect_with_flash('documents.php?tab=workflows', 'success', "Workflow step updated.");

            case 'create_compliance':
                db()->prepare("INSERT INTO compliance_requirements
                    (requirement_name,description,regulatory_body,requirement_type,deadline_type,penalty_description)
                    VALUES (?,?,?,?,?,?)")
                ->execute([
                    trim($_POST['requirement_name']), trim($_POST['description'] ?? ''),
                    trim($_POST['regulatory_body'] ?? ''), $_POST['requirement_type'] ?? 'internal',
                    $_POST['deadline_type'] ?? 'fixed',
                    trim($_POST['penalty_description'] ?? '')
                ]);
                log_activity('create_compliance_req', 'compliance_requirements', 0, $_POST['requirement_name']);
                redirect_with_flash('documents.php?tab=compliance', 'success', "Compliance requirement created.");
        }
    } catch (Throwable $e) {
        error_log('Staff documents error: ' . $e->getMessage());
        redirect_with_flash('documents.php', 'error', 'Operation failed.');
    }
}

// ── FETCH ─────────────────────────────────────────────────────────
$tab    = $_GET['tab']    ?? 'documents';
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';

$stats = [
    'total'          => db_scalar("SELECT COUNT(*) FROM documents"),
    'active'         => db_scalar("SELECT COUNT(*) FROM documents WHERE status='active'"),
    'pending'        => db_scalar("SELECT COUNT(*) FROM documents WHERE status='pending_approval'"),
    'expired'        => db_scalar("SELECT COUNT(*) FROM documents WHERE status='expired'"),
    'expiring_soon'  => db_scalar("SELECT COUNT(*) FROM documents WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) AND status!='expired'"),
    'open_workflows' => db_scalar("SELECT COUNT(*) FROM document_workflows WHERE status='pending'"),
];

// Dynamic document types (no hard-coding)
$doc_types   = db_all("SELECT id,type_name FROM document_types WHERE is_active=1 ORDER BY type_name");
$suppliers_l = db_all("SELECT id,supplier_name FROM suppliers WHERE status='active' ORDER BY supplier_name");
$projects_l  = db_all("SELECT id,project_name FROM projects WHERE status IN ('active','planning') ORDER BY project_name");

$where = ['1=1']; $params = [];
if ($search) { $where[] = "(title LIKE ? OR document_code LIKE ?)"; $params = ["%$search%","%$search%"]; }
if ($status) { $where[] = "status=?"; $params[] = $status; }

$documents = db_all("SELECT d.*,dt.type_name
    FROM documents d
    LEFT JOIN document_types dt ON d.document_type_id=dt.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY d.created_at DESC LIMIT 30", $params);

$workflows = db_all("SELECT dw.*,d.title as doc_title,d.document_code
    FROM document_workflows dw
    JOIN documents d ON dw.document_id=d.id
    ORDER BY dw.due_date ASC, dw.created_at DESC LIMIT 25");

$compliance_reqs = db_all("SELECT * FROM compliance_requirements WHERE is_active=1 ORDER BY requirement_name");

$page_title = 'Documents & Compliance'; $page_sub = 'Documents'; $back_url = 'dashboard.php';
require_once '../includes/staff/header.php';
?>
<main class="main">
<div class="page-title"><h1>Documents &amp; Compliance</h1><p>Upload and manage documents, track compliance requirements, and handle approval workflows</p></div>
<?php echo render_flash(); ?>

<?php if ($stats['expiring_soon'] > 0): ?>
<div class="alert alert-warn">
    <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <strong>Documents Expiring Soon:</strong>&nbsp;<?php echo (int)$stats['expiring_soon']; ?> document(s) expire within 30 days.
</div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Total Docs</span><div class="stat-badge"><svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg></div></div><div class="stat-value"><?php echo (int)$stats['total']; ?></div><div class="stat-sub">All documents</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Active</span><div class="stat-badge success-badge"><svg viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg></div></div><div class="stat-value"><?php echo (int)$stats['active']; ?></div><div class="stat-sub good">In use</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Pending Approval</span><div class="stat-badge warn-badge"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div></div><div class="stat-value"><?php echo (int)$stats['pending']; ?></div><div class="stat-sub warn">Awaiting review</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Expired</span><div class="stat-badge error-badge"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg></div></div><div class="stat-value"><?php echo (int)$stats['expired']; ?></div><div class="stat-sub error">Need renewal</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Open Workflows</span><div class="stat-badge"><svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div></div><div class="stat-value"><?php echo (int)$stats['open_workflows']; ?></div><div class="stat-sub pend">Pending steps</div></div>
</div>

<!-- Tabs -->
<div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1.5rem">
    <?php foreach (['documents'=>'Documents','workflows'=>'Workflows','compliance'=>'Compliance'] as $t=>$label): ?>
    <a href="?tab=<?php echo $t; ?>" style="display:flex;align-items:center;gap:7px;padding:.6rem 1.1rem;border:1.5px solid <?php echo $tab===$t?'var(--navy)':'var(--border)'; ?>;border-radius:8px;background:<?php echo $tab===$t?'var(--navy)':'var(--white)'; ?>;font-size:.84rem;font-weight:500;color:<?php echo $tab===$t?'#fff':'var(--muted)'; ?>;text-decoration:none"><?php echo $label; ?></a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'documents'): ?>
<div class="panel">
    <div class="controls-bar"><h2>Document Register</h2>
        <button onclick="openModal('addDocModal')" class="btn btn-primary">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Add Document</button>
    </div>
    <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem">
        <input type="hidden" name="tab" value="documents">
        <input type="text" name="search" class="form-control" placeholder="Search documents…" value="<?php echo h($search); ?>" style="max-width:260px">
        <select name="status" class="form-control" style="max-width:180px">
            <option value="">All Status</option>
            <?php foreach (['active','pending_approval','draft','expired','archived'] as $s): ?><option value="<?php echo $s; ?>"<?php echo $status===$s?' selected':''; ?>><?php echo ucfirst(str_replace('_',' ',$s)); ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Filter</button>
    </form>
</div>
<div class="panel" style="padding:0;overflow:hidden">
  <table class="data-table">
    <thead><tr><th>Code</th><th>Title</th><th>Type</th><th>Priority</th><th>Expiry</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (empty($documents)): ?>
    <tr><td colspan="8"><div class="empty-state"><svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg><h3>No documents found</h3></div></td></tr>
    <?php else: ?>
    <?php foreach ($documents as $doc): ?>
    <tr>
        <td><span style="font-family:'DM Mono',monospace;font-size:.8rem"><?php echo h($doc['document_code']); ?></span></td>
        <td><strong><?php echo h($doc['title']); ?></strong></td>
        <td><?php echo h($doc['type_name'] ?? '—'); ?></td>
        <td><?php echo render_badge($doc['priority'] ?? 'medium'); ?></td>
        <td>
            <?php if ($doc['expiry_date']): ?>
            <?php $exp = strtotime($doc['expiry_date']); $soon = $exp < strtotime('+30 days'); ?>
            <span style="color:<?php echo $exp<time()?'var(--error)':($soon?'var(--warn)':'var(--text)'); ?>"><?php echo fmt_date($doc['expiry_date']); ?></span>
            <?php else: echo '—'; endif; ?>
        </td>
        <td><?php echo render_badge($doc['status']); ?></td>
        <td><?php echo fmt_date($doc['created_at']); ?></td>
        <td>
            <div style="display:flex;gap:.4rem">
            <form method="POST" style="display:inline-flex;gap:.4rem">
                <input type="hidden" name="action" value="update_document_status">
                <input type="hidden" name="document_id" value="<?php echo (int)$doc['id']; ?>">
                <select name="status" onchange="this.form.submit()" class="form-control" style="width:auto;padding:.3rem .5rem;font-size:.78rem">
                    <?php foreach (['active','pending_approval','draft','archived','expired'] as $ds): ?><option value="<?php echo $ds; ?>"<?php echo $doc['status']===$ds?' selected':''; ?>><?php echo ucfirst(str_replace('_',' ',$ds)); ?></option><?php endforeach; ?>
                </select>
            </form>
            <button onclick="addWorkflowFor(<?php echo (int)$doc['id']; ?>, '<?php echo h($doc['title']); ?>')" class="btn btn-outline btn-sm" title="Add workflow step">
                <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></button>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($tab === 'workflows'): ?>
<div class="panel">
    <div class="controls-bar"><h2>Document Workflows</h2>
        <button onclick="openModal('addWFModal')" class="btn btn-primary">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>New Workflow Step</button>
    </div>
</div>
<div class="panel" style="padding:0;overflow:hidden">
  <table class="data-table">
    <thead><tr><th>Document</th><th>Step</th><th>Order</th><th>Assigned To</th><th>Due Date</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (empty($workflows)): ?>
    <tr><td colspan="7"><div class="empty-state"><h3>No workflow steps</h3></div></td></tr>
    <?php else: ?>
    <?php foreach ($workflows as $wf): ?>
    <tr>
        <td><strong><?php echo h($wf['doc_title']); ?></strong><br><small style="font-family:'DM Mono',monospace;color:var(--muted)"><?php echo h($wf['document_code']); ?></small></td>
        <td><?php echo h($wf['step_name']); ?></td>
        <td><?php echo (int)$wf['step_order']; ?></td>
        <td><?php echo h($wf['assigned_to'] ?? '—'); ?></td>
        <td><?php echo $wf['due_date'] ? fmt_date($wf['due_date']) : '—'; ?></td>
        <td><?php echo render_badge($wf['status']); ?></td>
        <td>
            <?php if ($wf['status'] === 'pending'): ?>
            <div style="display:flex;gap:.4rem">
            <form method="POST" style="display:inline-flex;gap:.4rem;align-items:center">
                <input type="hidden" name="action" value="update_workflow">
                <input type="hidden" name="workflow_id" value="<?php echo (int)$wf['id']; ?>">
                <input type="hidden" name="comments" value="">
                <button type="submit" name="status" value="approved" class="btn btn-success btn-sm">Approve</button>
                <button type="submit" name="status" value="rejected" class="btn btn-danger btn-sm">Reject</button>
            </form>
            </div>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($tab === 'compliance'): ?>
<div class="panel">
    <div class="controls-bar"><h2>Compliance Requirements</h2>
        <button onclick="openModal('addCompModal')" class="btn btn-primary">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Add Requirement</button>
    </div>
</div>
<div class="panel" style="padding:0;overflow:hidden">
  <table class="data-table">
    <thead><tr><th>Requirement</th><th>Regulatory Body</th><th>Type</th><th>Deadline Type</th><th>Penalty</th></tr></thead>
    <tbody>
    <?php if (empty($compliance_reqs)): ?>
    <tr><td colspan="5"><div class="empty-state"><h3>No compliance requirements</h3></div></td></tr>
    <?php else: ?>
    <?php foreach ($compliance_reqs as $cr): ?>
    <tr>
        <td><strong><?php echo h($cr['requirement_name']); ?></strong><?php if($cr['description']): ?><br><small style="color:var(--muted)"><?php echo h(mb_strimwidth($cr['description'],0,80,'…')); ?></small><?php endif; ?></td>
        <td><?php echo h($cr['regulatory_body'] ?? '—'); ?></td>
        <td><?php echo render_badge($cr['requirement_type'] ?? 'internal'); ?></td>
        <td><?php echo h(ucfirst($cr['deadline_type'] ?? 'fixed')); ?></td>
        <td><?php echo h(mb_strimwidth($cr['penalty_description'] ?? '—', 0, 60, '…')); ?></td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

</main>

<!-- ADD DOCUMENT MODAL -->
<div id="addDocModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><h3>Add Document</h3><button class="modal-close" onclick="closeModal('addDocModal')">&times;</button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="add_document">
        <div class="form-field"><label>Title *</label><input type="text" name="title" class="form-control" required></div>
        <div class="form-grid-2">
          <div class="form-field"><label>Document Type</label>
            <select name="document_type_id" class="form-control"><option value="">— None —</option>
              <?php foreach ($doc_types as $dt): ?><option value="<?php echo (int)$dt['id']; ?>"><?php echo h($dt['type_name']); ?></option><?php endforeach; ?>
            </select></div>
          <div class="form-field"><label>Priority</label><select name="priority" class="form-control"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="critical">Critical</option></select></div>
          <div class="form-field"><label>Related Supplier</label>
            <select name="related_supplier_id" class="form-control"><option value="">— None —</option>
              <?php foreach ($suppliers_l as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo h($s['supplier_name']); ?></option><?php endforeach; ?>
            </select></div>
          <div class="form-field"><label>Related Project</label>
            <select name="related_project_id" class="form-control"><option value="">— None —</option>
              <?php foreach ($projects_l as $p): ?><option value="<?php echo (int)$p['id']; ?>"><?php echo h($p['project_name']); ?></option><?php endforeach; ?>
            </select></div>
          <div class="form-field"><label>Expiry Date</label><input type="date" name="expiry_date" class="form-control"></div>
        </div>
        <div class="form-field"><label>Description</label><textarea name="description" class="form-control" rows="3"></textarea></div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('addDocModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-primary">Add Document</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ADD WORKFLOW MODAL -->
<div id="addWFModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><h3>Create Workflow Step</h3><button class="modal-close" onclick="closeModal('addWFModal')">&times;</button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="create_workflow">
        <div class="form-field"><label>Document *</label>
          <select name="document_id" id="wf_doc_id" class="form-control" required>
            <option value="">— Select Document —</option>
            <?php foreach ($documents as $d): ?><option value="<?php echo (int)$d['id']; ?>"><?php echo h($d['document_code'].' — '.$d['title']); ?></option><?php endforeach; ?>
          </select></div>
        <div class="form-grid-2">
          <div class="form-field"><label>Step Name *</label><input type="text" name="step_name" class="form-control" required></div>
          <div class="form-field"><label>Step Order</label><input type="number" name="step_order" class="form-control" value="1" min="1"></div>
          <div class="form-field"><label>Assign To</label><input type="text" name="assigned_to" class="form-control"></div>
          <div class="form-field"><label>Due Date</label><input type="date" name="due_date" class="form-control"></div>
        </div>
        <div class="form-field"><label>Comments</label><textarea name="comments" class="form-control" rows="2"></textarea></div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('addWFModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-primary">Create Step</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ADD COMPLIANCE MODAL -->
<div id="addCompModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><h3>Add Compliance Requirement</h3><button class="modal-close" onclick="closeModal('addCompModal')">&times;</button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="create_compliance">
        <div class="form-field"><label>Requirement Name *</label><input type="text" name="requirement_name" class="form-control" required></div>
        <div class="form-grid-2">
          <div class="form-field"><label>Regulatory Body</label><input type="text" name="regulatory_body" class="form-control"></div>
          <div class="form-field"><label>Type</label><select name="requirement_type" class="form-control"><option value="regulatory">Regulatory</option><option value="internal">Internal</option><option value="contractual">Contractual</option></select></div>
          <div class="form-field"><label>Deadline Type</label><select name="deadline_type" class="form-control"><option value="fixed">Fixed</option><option value="recurring">Recurring</option></select></div>
        </div>
        <div class="form-field"><label>Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
        <div class="form-field"><label>Penalty Description</label><textarea name="penalty_description" class="form-control" rows="2"></textarea></div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('addCompModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-primary">Add Requirement</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function addWorkflowFor(id, title) {
    document.getElementById('wf_doc_id').value = id;
    openModal('addWFModal');
}
</script>
<?php require_once '../includes/staff/footer.php'; ?>
