<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

$success_message = $error_message = '';

/* ── FORM HANDLING ──────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'add_document_type':
                $name = trim($_POST['type_name']);
                $chk = $pdo->prepare("SELECT COUNT(*) FROM document_types WHERE type_name=?"); $chk->execute([$name]);
                if ($chk->fetchColumn() > 0) { $error_message = "Document type '$name' already exists."; break; }
                $pdo->prepare("INSERT INTO document_types (type_name,description,retention_period_days,required_fields) VALUES (?,?,?,?)")
                    ->execute([$name,$_POST['description'],$_POST['retention_period_days'],json_encode($_POST['required_fields']??[])]);
                $success_message = "Document type added!";
                break;
            case 'edit_document_type':
                $name = trim($_POST['type_name']);
                $chk = $pdo->prepare("SELECT COUNT(*) FROM document_types WHERE type_name=? AND id!=?"); $chk->execute([$name,$_POST['type_id']]);
                if ($chk->fetchColumn() > 0) { $error_message = "Document type '$name' already exists."; break; }
                $pdo->prepare("UPDATE document_types SET type_name=?,description=?,retention_period_days=?,required_fields=?,is_active=? WHERE id=?")
                    ->execute([$name,$_POST['description'],$_POST['retention_period_days'],json_encode($_POST['required_fields']??[]),$_POST['is_active'],$_POST['type_id']]);
                $success_message = "Document type updated!";
                break;
            case 'add_document':
                $doc_code = 'DOC-' . date('Ymd') . '-' . str_pad(rand(1,9999), 4, '0', STR_PAD_LEFT);
                $file_path = $file_name = $file_size = $file_type = null;
                if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = 'uploads/documents/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                    $file_name = $_FILES['document_file']['name'];
                    $file_type = $_FILES['document_file']['type'];
                    $file_size = $_FILES['document_file']['size'];
                    $ext = pathinfo($file_name, PATHINFO_EXTENSION);
                    $file_path = $upload_dir . $doc_code . '.' . $ext;
                    move_uploaded_file($_FILES['document_file']['tmp_name'], $file_path);
                }
                $pdo->prepare("INSERT INTO documents (document_code,document_type_id,title,description,file_path,file_name,file_size,file_type,status,priority,tags,created_by,expiry_date,related_project_id,related_po_id,related_supplier_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$doc_code,$_POST['document_type_id'],$_POST['title'],$_POST['description'],$file_path,$file_name,$file_size,$file_type,$_POST['status'],$_POST['priority'],$_POST['tags'],$user_name,$_POST['expiry_date']?:null,$_POST['related_project_id']?:null,$_POST['related_po_id']?:null,$_POST['related_supplier_id']?:null]);
                $success_message = "Document added: $doc_code";
                break;
            case 'update_document_status':
                $approved_at = ($_POST['new_status']==='active') ? date('Y-m-d H:i:s') : null;
                $approved_by = ($_POST['new_status']==='active') ? $user_name : null;
                $pdo->prepare("UPDATE documents SET status=?,approved_by=?,approved_at=? WHERE id=?")->execute([$_POST['new_status'],$approved_by,$approved_at,$_POST['document_id']]);
                try { $pdo->prepare("INSERT INTO document_access_logs (document_id,accessed_by,access_type,access_details) VALUES (?,?,?,?)")->execute([$_POST['document_id'],$user_name,'edit','Status updated to: '.$_POST['new_status']]); } catch (Exception $e) {}
                $success_message = "Document status updated!";
                break;
            case 'add_compliance_requirement':
                $pdo->prepare("INSERT INTO compliance_requirements (requirement_name,description,regulatory_body,requirement_type,applicable_document_types,deadline_type,recurring_period_days,penalty_description) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$_POST['requirement_name'],$_POST['description'],$_POST['regulatory_body'],$_POST['requirement_type'],json_encode($_POST['applicable_document_types']??[]),$_POST['deadline_type'],$_POST['recurring_period_days']?:null,$_POST['penalty_description']]);
                $success_message = "Compliance requirement added!";
                break;
            case 'add_compliance_tracking':
                $pdo->prepare("INSERT INTO document_compliance (document_id,requirement_id,compliance_status,due_date,notes) VALUES (?,?,?,?,?)")
                    ->execute([$_POST['document_id'],$_POST['requirement_id'],$_POST['compliance_status'],$_POST['due_date']?:null,$_POST['notes']]);
                $success_message = "Compliance tracking added!";
                break;
            case 'update_compliance_status':
                $comp_date = ($_POST['compliance_status']==='compliant') ? date('Y-m-d') : null;
                $pdo->prepare("UPDATE document_compliance SET compliance_status=?,completed_date=?,notes=?,last_reviewed_by=?,last_reviewed_at=NOW() WHERE id=?")
                    ->execute([$_POST['compliance_status'],$comp_date,$_POST['notes'],$user_name,$_POST['compliance_id']]);
                $success_message = "Compliance status updated!";
                break;
        }
    } catch (PDOException $e) { $error_message = $e->getMessage(); }
}

/* ── PAGE DATA ──────────────────────────────────────────────── */
try {
    $active_documents      = $pdo->query("SELECT COUNT(*) FROM documents WHERE status='active'")->fetchColumn();
    $pending_approval      = $pdo->query("SELECT COUNT(*) FROM documents WHERE status='pending_approval'")->fetchColumn();
    $expiring_soon         = $pdo->query("SELECT COUNT(*) FROM documents WHERE expiry_date IS NOT NULL AND expiry_date<=DATE_ADD(CURDATE(),INTERVAL 30 DAY) AND status='active'")->fetchColumn();
    $compliance_reqs_count = $pdo->query("SELECT COUNT(*) FROM compliance_requirements WHERE is_active=1")->fetchColumn();
    $recent_documents      = $pdo->query("SELECT d.*,dt.type_name FROM documents d LEFT JOIN document_types dt ON d.document_type_id=dt.id ORDER BY d.created_at DESC LIMIT 10")->fetchAll();
    $document_types        = $pdo->query("SELECT * FROM document_types WHERE is_active=1 ORDER BY type_name")->fetchAll();
    $compliance_reqs       = $pdo->query("SELECT * FROM compliance_requirements WHERE is_active=1 ORDER BY requirement_name")->fetchAll();
    $compliance_tracking   = $pdo->query("SELECT dc.*,d.title as document_title,d.document_code,cr.requirement_name FROM document_compliance dc JOIN documents d ON dc.document_id=d.id JOIN compliance_requirements cr ON dc.requirement_id=cr.id ORDER BY dc.due_date ASC,dc.compliance_status ASC")->fetchAll();
    $projects_list         = $pdo->query("SELECT id,project_code,project_name FROM projects ORDER BY project_name")->fetchAll();
    $po_list               = $pdo->query("SELECT id,po_number FROM purchase_orders ORDER BY po_number")->fetchAll();
    $suppliers_list        = $pdo->query("SELECT id,supplier_code,supplier_name FROM suppliers WHERE status='active' ORDER BY supplier_name")->fetchAll();
    try { $workflows = $pdo->query("SELECT dw.*,d.title as document_title,d.document_code FROM document_workflows dw JOIN documents d ON dw.document_id=d.id WHERE dw.status IN ('pending','approved') ORDER BY dw.due_date ASC,dw.step_order ASC")->fetchAll(); } catch (Exception $e) { $workflows = []; }
} catch (PDOException $e) {
    $active_documents = $pending_approval = $expiring_soon = $compliance_reqs_count = 0;
    $recent_documents = $document_types = $compliance_reqs = $compliance_tracking = $projects_list = $po_list = $suppliers_list = $workflows = [];
    $error_message = "Database error: " . $e->getMessage();
}

$page_title = 'Document Tracking'; $module_subtitle = 'Document Tracking'; $back_btn_href = 'dashboard.php'; $active_nav = 'dtlrs';
include 'includes/head.php';
?>
<body>
<?php include 'includes/topbar.php'; ?>
<div class="layout">
<?php include 'includes/sidebar.php'; ?>
<main class="main-content">

    <div class="page-title">
        <span class="page-title-tag">Module / Documents</span>
        <h1>Document <strong>Tracking &amp; Compliance</strong></h1>
        <p>Manage documents, track compliance requirements, and automate approval workflows.</p>
    </div>

    <?php if ($success_message): ?><div class="alert alert-success"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
    <?php if ($error_message):   ?><div class="alert alert-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/></svg><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

    <!-- STATS -->
    <div class="stats-row">
        <div class="stat-card success"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div><div class="stat-value"><?php echo $active_documents; ?></div><div class="stat-label">Active Documents</div></div>
        <div class="stat-card <?php echo $pending_approval>0?'warn':''; ?>"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div><div class="stat-value"><?php echo $pending_approval; ?></div><div class="stat-label">Pending Approval</div></div>
        <div class="stat-card <?php echo $expiring_soon>0?'danger':''; ?>"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><div class="stat-value"><?php echo $expiring_soon; ?></div><div class="stat-label">Expiring (30 days)</div></div>
        <div class="stat-card"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></div><div class="stat-value"><?php echo $compliance_reqs_count; ?></div><div class="stat-label">Compliance Rules</div></div>
    </div>

    <!-- FUNCTION CARDS -->
    <span class="section-label">Core Functions</span>
    <div class="functions-grid">
        <div class="fn-card" onclick="openModal('addDocumentModal')">
            <div class="fn-card-top"><div class="fn-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div>
            <div><div class="fn-title">Document Management</div><div class="fn-desc">Upload, categorize, and manage all business documents. Track versions and control access.</div></div></div>
            <div class="fn-card-foot"><button class="fn-btn" onclick="event.stopPropagation();openModal('addDocumentModal')">Add Document</button><div class="fn-status"><div class="fn-status-dot"></div><?php echo $active_documents; ?> Active</div></div>
        </div>
        <div class="fn-card" onclick="openModal('addDocTypeModal')">
            <div class="fn-card-top"><div class="fn-icon"><svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg></div>
            <div><div class="fn-title">Document Types</div><div class="fn-desc">Define document categories, retention periods, and required fields for each type.</div></div></div>
            <div class="fn-card-foot"><button class="fn-btn" onclick="event.stopPropagation();openModal('addDocTypeModal')">Manage Types</button><div class="fn-status"><div class="fn-status-dot"></div><?php echo count($document_types); ?> Types</div></div>
        </div>
        <div class="fn-card" onclick="openModal('addComplianceModal')">
            <div class="fn-card-top"><div class="fn-icon"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></div>
            <div><div class="fn-title">Compliance Requirements</div><div class="fn-desc">Track regulatory requirements, deadlines, and ensure organizational compliance.</div></div></div>
            <div class="fn-card-foot"><button class="fn-btn" onclick="event.stopPropagation();openModal('addComplianceModal')">Add Requirement</button><div class="fn-status"><div class="fn-status-dot"></div><?php echo $compliance_reqs_count; ?> Rules</div></div>
        </div>
        <div class="fn-card" onclick="window.location.href='view_document.php'">
            <div class="fn-card-top"><div class="fn-icon"><svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></div>
            <div><div class="fn-title">View &amp; Download Documents</div><div class="fn-desc">Browse, search, and download all uploaded documents. View document history and access logs.</div></div></div>
            <div class="fn-card-foot"><button class="fn-btn" onclick="event.stopPropagation();window.location.href='view_document.php'">Browse Documents</button><div class="fn-status"><div class="fn-status-dot"></div>Active</div></div>
        </div>
    </div>

    <!-- BOTTOM GRID -->
    <div class="bottom-grid">
        <!-- Recent Documents Table -->
        <div class="panel">
            <div class="panel-header">
                <span class="panel-title">Recent Documents</span>
                <span class="panel-badge"><?php echo $pending_approval; ?> pending</span>
            </div>
            <div class="tbl-wrap">
                <table class="data-table">
                    <thead><tr><th>Code</th><th>Title</th><th>Type</th><th>Priority</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php if (empty($recent_documents)): ?><tr><td colspan="6" class="empty-td">No documents yet. <a href="#" onclick="openModal('addDocumentModal');return false;">Add your first →</a></td></tr>
                        <?php else: foreach ($recent_documents as $doc): ?>
                        <tr>
                            <td><span class="item-code"><?php echo htmlspecialchars($doc['document_code']); ?></span></td>
                            <td><strong><?php echo htmlspecialchars($doc['title']); ?></strong></td>
                            <td style="font-size:.78rem;"><?php echo htmlspecialchars($doc['type_name']??'—'); ?></td>
                            <td><span class="badge badge-<?php echo $doc['priority']==='urgent'?'danger':($doc['priority']==='high'?'warn':'normal'); ?>"><?php echo ucfirst($doc['priority']??'normal'); ?></span></td>
                            <td><span class="badge badge-<?php echo $doc['status']==='active'?'normal':($doc['status']==='pending_approval'?'warn':($doc['status']==='expired'?'danger':'inactive')); ?>"><?php echo ucfirst(str_replace('_',' ',$doc['status'])); ?></span></td>
                            <td>
                                <div class="btn-row">
                                    <?php if ($doc['file_path']): ?><a href="download_document.php?id=<?php echo $doc['id']; ?>" class="btn btn-ghost btn-sm">Download</a><?php endif; ?>
                                    <?php if ($doc['status']==='pending_approval'): ?>
                                    <form method="POST" style="display:inline"><input type="hidden" name="action" value="update_document_status"><input type="hidden" name="document_id" value="<?php echo $doc['id']; ?>"><input type="hidden" name="new_status" value="active"><button type="submit" class="btn btn-green btn-sm" onclick="return confirm('Approve this document?')">Approve</button></form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Sidebar Stack -->
        <div class="sidebar-stack">
            <!-- Compliance Summary -->
            <div class="panel">
                <div class="panel-header"><span class="panel-title">Compliance Summary</span></div>
                <?php if (empty($compliance_tracking)): ?><div class="empty-td" style="padding:1.5rem;text-align:center;">No compliance tracking data.</div>
                <?php else: foreach (array_slice($compliance_tracking,0,6) as $ct): ?>
                <div class="list-item">
                    <div><div class="li-title" style="font-size:.82rem;"><?php echo htmlspecialchars($ct['document_code']); ?></div><div class="li-sub"><?php echo htmlspecialchars($ct['requirement_name']); ?></div></div>
                    <span class="badge badge-<?php echo $ct['compliance_status']==='compliant'?'normal':($ct['compliance_status']==='non_compliant'?'danger':'warn'); ?>"><?php echo ucfirst(str_replace('_',' ',$ct['compliance_status'])); ?></span>
                </div>
                <?php endforeach; endif; ?>
            </div>
            <!-- Quick Actions -->
            <div class="panel">
                <div class="panel-header"><span class="panel-title">Quick Actions</span></div>
                <div class="qa-list">
                    <button class="qa-btn qa-blue" onclick="openModal('addDocumentModal')"><svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Add Document</button>
                    <button class="qa-btn qa-green" onclick="openModal('addDocTypeModal')"><svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>New Document Type</button>
                    <button class="qa-btn qa-orange" onclick="openModal('addComplianceModal')"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>Add Compliance Rule</button>
                    <a href="view_document.php" class="qa-btn qa-teal"><svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>Browse All Documents</a>
                </div>
            </div>
        </div>
    </div>
</main>
</div>

<!-- ADD DOCUMENT MODAL -->
<div id="addDocumentModal" class="modal">
    <div class="modal-box wide">
        <div class="modal-head"><h3>Add Document</h3><button class="modal-close" onclick="closeModal('addDocumentModal')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
        <div class="modal-body">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_document">
                <div class="form-row"><div class="form-group"><label class="form-label">Title <span class="req">*</span></label><input type="text" name="title" class="form-input" required></div>
                <div class="form-group"><label class="form-label">Document Type <span class="req">*</span></label>
                    <select name="document_type_id" class="form-select" required><option value="">— Select Type —</option><?php foreach ($document_types as $dt): ?><option value="<?php echo $dt['id']; ?>"><?php echo htmlspecialchars($dt['type_name']); ?></option><?php endforeach; ?></select></div></div>
                <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-textarea" rows="2"></textarea></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Status</label>
                    <select name="status" class="form-select"><option value="pending_approval">Pending Approval</option><option value="active">Active</option><option value="draft">Draft</option></select></div>
                <div class="form-group"><label class="form-label">Priority</label>
                    <select name="priority" class="form-select"><option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></div></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Expiry Date</label><input type="date" name="expiry_date" class="form-input"></div>
                <div class="form-group"><label class="form-label">Tags</label><input type="text" name="tags" class="form-input" placeholder="Comma separated"></div></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Related Project</label><select name="related_project_id" class="form-select"><option value="">None</option><?php foreach ($projects_list as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['project_code'].' - '.$p['project_name']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label class="form-label">Related Supplier</label><select name="related_supplier_id" class="form-select"><option value="">None</option><?php foreach ($suppliers_list as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['supplier_name']); ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="form-group"><label class="form-label">File Upload</label><input type="file" name="document_file" class="form-input" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.png"></div>
                <button type="submit" class="submit-btn">Save Document</button>
            </form>
        </div>
    </div>
</div>

<!-- ADD DOCUMENT TYPE MODAL -->
<div id="addDocTypeModal" class="modal">
    <div class="modal-box">
        <div class="modal-head"><h3>New Document Type</h3><button class="modal-close" onclick="closeModal('addDocTypeModal')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="add_document_type">
                <div class="form-group"><label class="form-label">Type Name <span class="req">*</span></label><input type="text" name="type_name" class="form-input" required></div>
                <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-textarea" rows="2"></textarea></div>
                <div class="form-group"><label class="form-label">Retention Period (days)</label><input type="number" name="retention_period_days" class="form-input" min="0" value="365"></div>
                <button type="submit" class="submit-btn">Add Document Type</button>
            </form>
        </div>
    </div>
</div>

<!-- ADD COMPLIANCE REQUIREMENT MODAL -->
<div id="addComplianceModal" class="modal">
    <div class="modal-box">
        <div class="modal-head"><h3>New Compliance Requirement</h3><button class="modal-close" onclick="closeModal('addComplianceModal')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="add_compliance_requirement">
                <div class="form-group"><label class="form-label">Requirement Name <span class="req">*</span></label><input type="text" name="requirement_name" class="form-input" required></div>
                <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-textarea" rows="2"></textarea></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Regulatory Body</label><input type="text" name="regulatory_body" class="form-input"></div>
                <div class="form-group"><label class="form-label">Requirement Type</label>
                    <select name="requirement_type" class="form-select"><option value="mandatory">Mandatory</option><option value="optional">Optional</option><option value="conditional">Conditional</option></select></div></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Deadline Type</label>
                    <select name="deadline_type" class="form-select"><option value="one_time">One-Time</option><option value="recurring">Recurring</option><option value="ongoing">Ongoing</option></select></div>
                <div class="form-group"><label class="form-label">Recurring Days</label><input type="number" name="recurring_period_days" class="form-input" min="0" placeholder="e.g. 365 for annual"></div></div>
                <div class="form-group"><label class="form-label">Penalty Description</label><textarea name="penalty_description" class="form-textarea" rows="2"></textarea></div>
                <button type="submit" class="submit-btn">Add Requirement</button>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
