<?php
/**
 * staff/projects.php
 * Staff — Project Management (CRUD)
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
            case 'add_project':
                $count = (int)db_scalar("SELECT COUNT(*) FROM projects") + 1;
                $code  = $_POST['project_code'] ?: ('PRJ-' . date('Y') . '-' . str_pad($count, 4, '0', STR_PAD_LEFT));
                db()->prepare("INSERT INTO projects
                    (project_code, project_name, description, client_name, start_date,
                     expected_end_date, status, priority, budget, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $code, trim($_POST['project_name']), trim($_POST['description'] ?? ''),
                    trim($_POST['client_name'] ?? ''), $_POST['start_date'],
                    $_POST['expected_end_date'] ?: null, $_POST['status'] ?? 'planning',
                    $_POST['priority'] ?? 'medium', (float)($_POST['budget'] ?? 0), $actor
                ]);
                $pid = (int)db()->lastInsertId();
                log_activity('create_project', 'projects', $pid, $_POST['project_name']);
                redirect_with_flash('projects.php', 'success', "Project '{$_POST['project_name']}' created.");
                break;

            case 'update_project':
                db()->prepare("UPDATE projects SET project_name=?, description=?, client_name=?,
                    start_date=?, expected_end_date=?, status=?, priority=?, budget=?,
                    progress_percentage=? WHERE id=?")
                ->execute([
                    trim($_POST['project_name']), trim($_POST['description'] ?? ''),
                    trim($_POST['client_name'] ?? ''), $_POST['start_date'],
                    $_POST['expected_end_date'] ?: null, $_POST['status'],
                    $_POST['priority'], (float)$_POST['budget'],
                    min(100, max(0, (int)$_POST['progress_percentage'])),
                    (int)$_POST['project_id']
                ]);
                log_activity('update_project', 'projects', (int)$_POST['project_id'], $_POST['project_name']);
                redirect_with_flash('projects.php', 'success', "Project updated.");
                break;

            case 'add_task':
                db()->prepare("INSERT INTO project_tasks
                    (project_id, task_name, description, assigned_to, start_date,
                     due_date, priority, status, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([
                    (int)$_POST['project_id'], trim($_POST['task_name']),
                    trim($_POST['description'] ?? ''), trim($_POST['assigned_to'] ?? ''),
                    $_POST['start_date'] ?: null, $_POST['due_date'] ?: null,
                    $_POST['priority'] ?? 'medium', 'todo', $actor
                ]);
                log_activity('add_task', 'project_tasks', (int)$_POST['project_id'], $_POST['task_name']);
                redirect_with_flash('projects.php', 'success', "Task '{$_POST['task_name']}' added.");
                break;

            case 'update_task_status':
                db()->prepare("UPDATE project_tasks SET status=? WHERE id=?")
                     ->execute([$_POST['status'], (int)$_POST['task_id']]);
                log_activity('update_task_status', 'project_tasks', (int)$_POST['task_id'], $_POST['status']);
                redirect_with_flash('projects.php', 'success', "Task status updated.");
                break;

            case 'delete_project':
                db()->prepare("UPDATE projects SET status='cancelled' WHERE id=?")->execute([(int)$_POST['project_id']]);
                log_activity('cancel_project', 'projects', (int)$_POST['project_id'], '');
                redirect_with_flash('projects.php', 'success', "Project cancelled.");
                break;
        }
    } catch (Throwable $e) {
        error_log('Staff projects error: ' . $e->getMessage());
        redirect_with_flash('projects.php', 'error', 'Operation failed.');
    }
}

// Fetch projects
$status_filter = $_GET['status'] ?? '';
$search  = trim($_GET['search'] ?? '');
$where   = ['1=1']; $params = [];
if ($search)        { $where[] = "(project_name LIKE ? OR project_code LIKE ? OR client_name LIKE ?)"; $params = ["%$search%","%$search%","%$search%"]; }
if ($status_filter) { $where[] = "status=?"; $params[] = $status_filter; }

$projects = db_all("SELECT p.*,
    (SELECT COUNT(*) FROM project_tasks WHERE project_id=p.id) as task_count,
    (SELECT COUNT(*) FROM project_tasks WHERE project_id=p.id AND status='completed') as completed_tasks
    FROM projects p WHERE " . implode(' AND ', $where) . " ORDER BY p.created_at DESC", $params);

$stats = [
    'total'       => db_scalar("SELECT COUNT(*) FROM projects"),
    'active'      => db_scalar("SELECT COUNT(*) FROM projects WHERE status='active'"),
    'completed'   => db_scalar("SELECT COUNT(*) FROM projects WHERE status='completed'"),
    'overdue'     => db_scalar("SELECT COUNT(*) FROM projects WHERE expected_end_date < CURDATE() AND status NOT IN ('completed','cancelled')"),
    'total_budget'=> db_scalar("SELECT COALESCE(SUM(budget),0) FROM projects"),
];

// Staff list for task assignment (dynamic)
$staff_list = db_all("SELECT CONCAT(first_name,' ',last_name) as full_name FROM users WHERE status='active' ORDER BY first_name");
$staff_names = array_column($staff_list, 'full_name');

$page_title = 'Project Management'; $page_sub = 'Project Management'; $back_url = 'dashboard.php';
require_once '../includes/staff/header.php';
?>
<main class="main">
<div class="page-title"><h1>Project Management</h1><p>Create and manage projects, tasks, milestones, and resource allocation</p></div>
<?php echo render_flash(); ?>

<?php if ($stats['overdue'] > 0): ?>
<div class="alert alert-warn">
    <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <strong>Overdue Projects:</strong>&nbsp;<?php echo (int)$stats['overdue']; ?> project(s) have passed their expected end date.
</div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Total Projects</span><div class="stat-badge"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div></div><div class="stat-value"><?php echo $stats['total']; ?></div><div class="stat-sub">All time</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Active</span><div class="stat-badge success-badge"><svg viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/></svg></div></div><div class="stat-value"><?php echo $stats['active']; ?></div><div class="stat-sub good">In progress</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Completed</span><div class="stat-badge success-badge"><svg viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg></div></div><div class="stat-value"><?php echo $stats['completed']; ?></div><div class="stat-sub good">Finished</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Overdue</span><div class="stat-badge error-badge"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div></div><div class="stat-value"><?php echo $stats['overdue']; ?></div><div class="stat-sub <?php echo $stats['overdue']>0?'error':'good'; ?>">Past deadline</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Total Budget</span><div class="stat-badge"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div></div><div class="stat-value" style="font-size:1.3rem"><?php echo peso((float)$stats['total_budget']); ?></div><div class="stat-sub good">Allocated</div></div>
</div>

<div class="panel">
    <div class="controls-bar">
        <h2>Projects</h2>
        <div class="btn-row">
            <button onclick="openModal('addProjectModal')" class="btn btn-primary">
                <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>New Project</button>
        </div>
    </div>
    <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem">
        <input type="text" name="search" class="form-control" placeholder="Search projects…" value="<?php echo h($search); ?>" style="max-width:280px">
        <select name="status" class="form-control" style="max-width:180px">
            <option value="">All Status</option>
            <?php foreach (['planning','active','on_hold','completed','cancelled'] as $st): ?>
            <option value="<?php echo $st; ?>"<?php echo $status_filter===$st?' selected':''; ?>><?php echo ucfirst(str_replace('_',' ',$st)); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Filter</button>
    </form>
</div>

<!-- Projects grid -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:1rem;margin-bottom:1.5rem">
<?php if (empty($projects)): ?>
<div class="panel"><div class="empty-state"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg><h3>No projects found</h3><p>Create your first project to get started.</p></div></div>
<?php else: ?>
<?php foreach ($projects as $p):
    $pct = (int)($p['progress_percentage'] ?? 0);
?>
<div style="background:#fff;border:1px solid var(--border);border-radius:12px;padding:1.4rem;position:relative;overflow:hidden;transition:box-shadow .2s,transform .15s" onmouseover="this.style.boxShadow='0 6px 22px rgba(15,31,61,.1)';this.style.transform='translateY(-2px)'" onmouseout="this.style.boxShadow='';this.style.transform=''">
    <div style="position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(135deg,var(--navy),var(--accent))"></div>
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:.5rem">
        <div>
            <div style="font-size:.98rem;font-weight:600;color:var(--navy)"><?php echo h($p['project_name']); ?></div>
            <div style="font-size:.78rem;color:var(--muted);font-family:'DM Mono',monospace"><?php echo h($p['project_code']); ?></div>
        </div>
        <?php echo render_badge($p['status']); ?>
    </div>
    <div style="font-size:.83rem;color:var(--muted);margin-bottom:.75rem;line-height:1.5"><?php echo h(mb_strimwidth($p['description'] ?? '', 0, 100, '…')); ?></div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem;margin-bottom:.75rem">
        <div style="text-align:center;padding:.4rem;background:var(--off);border-radius:7px"><div style="font-size:1.1rem;font-weight:600;color:var(--navy)"><?php echo (int)$p['task_count']; ?></div><div style="font-size:.65rem;color:var(--muted);text-transform:uppercase">Tasks</div></div>
        <div style="text-align:center;padding:.4rem;background:var(--off);border-radius:7px"><div style="font-size:1.1rem;font-weight:600;color:var(--success)"><?php echo (int)$p['completed_tasks']; ?></div><div style="font-size:.65rem;color:var(--muted);text-transform:uppercase">Done</div></div>
        <div style="text-align:center;padding:.4rem;background:var(--off);border-radius:7px"><div style="font-size:1.1rem;font-weight:600;color:var(--navy)"><?php echo peso((float)$p['budget']); ?></div><div style="font-size:.65rem;color:var(--muted);text-transform:uppercase">Budget</div></div>
    </div>
    <div style="height:6px;background:var(--border);border-radius:99px;overflow:hidden;margin-bottom:.25rem"><div style="height:100%;background:linear-gradient(90deg,var(--navy),var(--accent));width:<?php echo $pct; ?>%;transition:width .3s"></div></div>
    <div style="text-align:right;font-size:.72rem;color:var(--muted);font-family:'DM Mono',monospace;margin-bottom:.75rem"><?php echo $pct; ?>% complete</div>
    <div style="font-size:.8rem;color:var(--muted);display:grid;grid-template-columns:1fr 1fr;gap:.3rem;margin-bottom:.75rem">
        <span>Client: <strong style="color:var(--text)"><?php echo h($p['client_name'] ?? '—'); ?></strong></span>
        <span>Priority: <strong style="color:var(--text)"><?php echo ucfirst($p['priority'] ?? '—'); ?></strong></span>
        <span>Start: <strong style="color:var(--text)"><?php echo fmt_date($p['start_date']); ?></strong></span>
        <span>End: <strong style="color:var(--text)"><?php echo fmt_date($p['expected_end_date']); ?></strong></span>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button onclick="editProject(<?php echo h(json_encode($p)); ?>)" class="btn btn-warning btn-sm">
            <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit</button>
        <button onclick="addTask(<?php echo (int)$p['id']; ?>, '<?php echo h($p['project_name']); ?>')" class="btn btn-success btn-sm">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Add Task</button>
        <button onclick="deleteProject(<?php echo (int)$p['id']; ?>, '<?php echo h($p['project_name']); ?>')" class="btn btn-danger btn-sm">
            <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>Cancel</button>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>

</main>

<!-- ADD PROJECT MODAL -->
<div id="addProjectModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><h3>Create New Project</h3><button class="modal-close" onclick="closeModal('addProjectModal')">&times;</button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="add_project">
        <div class="form-grid-2">
          <div class="form-field"><label>Project Code</label><input type="text" name="project_code" class="form-control" placeholder="Auto-generated if blank"></div>
          <div class="form-field"><label>Project Name *</label><input type="text" name="project_name" class="form-control" required></div>
          <div class="form-field"><label>Client Name</label><input type="text" name="client_name" class="form-control"></div>
          <div class="form-field"><label>Priority</label><select name="priority" class="form-control"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="critical">Critical</option></select></div>
          <div class="form-field"><label>Start Date</label><input type="date" name="start_date" class="form-control" value="<?php echo date('Y-m-d'); ?>"></div>
          <div class="form-field"><label>Expected End Date</label><input type="date" name="expected_end_date" class="form-control"></div>
          <div class="form-field"><label>Budget</label><input type="number" name="budget" class="form-control" step="0.01" min="0" value="0"></div>
          <div class="form-field"><label>Status</label><select name="status" class="form-control"><option value="planning">Planning</option><option value="active">Active</option><option value="on_hold">On Hold</option></select></div>
        </div>
        <div class="form-field"><label>Description</label><textarea name="description" class="form-control" rows="3"></textarea></div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('addProjectModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-primary">Create Project</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- EDIT PROJECT MODAL -->
<div id="editProjectModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><h3>Edit Project</h3><button class="modal-close" onclick="closeModal('editProjectModal')">&times;</button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="update_project">
        <input type="hidden" id="ep_id" name="project_id">
        <div class="form-grid-2">
          <div class="form-field" style="grid-column:1/-1"><label>Project Name *</label><input type="text" id="ep_name" name="project_name" class="form-control" required></div>
          <div class="form-field"><label>Client Name</label><input type="text" id="ep_client" name="client_name" class="form-control"></div>
          <div class="form-field"><label>Status</label><select id="ep_status" name="status" class="form-control"><option value="planning">Planning</option><option value="active">Active</option><option value="on_hold">On Hold</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></div>
          <div class="form-field"><label>Priority</label><select id="ep_priority" name="priority" class="form-control"><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option><option value="critical">Critical</option></select></div>
          <div class="form-field"><label>Progress %</label><input type="number" id="ep_progress" name="progress_percentage" class="form-control" min="0" max="100"></div>
          <div class="form-field"><label>Budget</label><input type="number" id="ep_budget" name="budget" class="form-control" step="0.01" min="0"></div>
          <div class="form-field"><label>Start Date</label><input type="date" id="ep_start" name="start_date" class="form-control"></div>
          <div class="form-field"><label>Expected End</label><input type="date" id="ep_end" name="expected_end_date" class="form-control"></div>
        </div>
        <div class="form-field"><label>Description</label><textarea id="ep_desc" name="description" class="form-control" rows="3"></textarea></div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('editProjectModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-primary">Update Project</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ADD TASK MODAL -->
<div id="addTaskModal" class="modal">
  <div class="modal-box">
    <div class="modal-head"><h3>Add Task</h3><button class="modal-close" onclick="closeModal('addTaskModal')">&times;</button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="add_task">
        <input type="hidden" id="task_project_id" name="project_id">
        <div class="form-field"><label>Project</label><input type="text" id="task_project_name" class="form-control" readonly style="background:var(--off)"></div>
        <div class="form-grid-2">
          <div class="form-field" style="grid-column:1/-1"><label>Task Name *</label><input type="text" name="task_name" class="form-control" required></div>
          <div class="form-field"><label>Assigned To</label><input type="text" name="assigned_to" class="form-control" list="staffList">
            <datalist id="staffList"><?php foreach($staff_names as $sn): ?><option value="<?php echo h($sn); ?>"><?php endforeach; ?></datalist></div>
          <div class="form-field"><label>Priority</label><select name="priority" class="form-control"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option></select></div>
          <div class="form-field"><label>Start Date</label><input type="date" name="start_date" class="form-control"></div>
          <div class="form-field"><label>Due Date</label><input type="date" name="due_date" class="form-control"></div>
        </div>
        <div class="form-field"><label>Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
        <div class="modal-footer">
          <button type="button" onclick="closeModal('addTaskModal')" class="btn btn-outline">Cancel</button>
          <button type="submit" class="btn btn-primary">Add Task</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function editProject(p){
    document.getElementById('ep_id').value=p.id;
    document.getElementById('ep_name').value=p.project_name||'';
    document.getElementById('ep_client').value=p.client_name||'';
    document.getElementById('ep_status').value=p.status||'active';
    document.getElementById('ep_priority').value=p.priority||'medium';
    document.getElementById('ep_progress').value=p.progress_percentage||0;
    document.getElementById('ep_budget').value=p.budget||0;
    document.getElementById('ep_start').value=p.start_date||'';
    document.getElementById('ep_end').value=p.expected_end_date||'';
    document.getElementById('ep_desc').value=p.description||'';
    openModal('editProjectModal');
}
function addTask(pid,pname){
    document.getElementById('task_project_id').value=pid;
    document.getElementById('task_project_name').value=pname;
    openModal('addTaskModal');
}
function deleteProject(id,name){
    if(confirm('Cancel project "'+name+'"?')){
        var f=document.createElement('form');f.method='POST';
        f.innerHTML='<input type="hidden" name="action" value="delete_project"><input type="hidden" name="project_id" value="'+id+'">';
        document.body.appendChild(f);f.submit();
    }
}
</script>
<?php require_once '../includes/staff/footer.php'; ?>
