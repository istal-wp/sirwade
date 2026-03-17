<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

/* ── HELPERS ────────────────────────────────────────────────── */
function generateProjectCode($pdo) {
    $year = date('Y'); $month = date('m'); $count = 1;
    do {
        $code = sprintf("PROJ-%s-%s-%04d", $year, $month, $count);
        $ex = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE project_code=?"); $ex->execute([$code]);
        if ($ex->fetchColumn() == 0) return $code;
        $count++;
    } while (true);
}
function updateProjectProgress($project_id, $pdo) {
    $ms = $pdo->prepare("SELECT COUNT(*) as t, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as c FROM project_milestones WHERE project_id=?");
    $ms->execute([$project_id]); $md = $ms->fetch();
    $mp = ($md['t'] > 0) ? ($md['c'] / $md['t']) * 100 : 0;
    $ts = $pdo->prepare("SELECT AVG(progress_percentage) FROM project_tasks WHERE project_id=?");
    $ts->execute([$project_id]); $tp = $ts->fetchColumn() ?? 0;
    $pdo->prepare("UPDATE projects SET progress_percentage=? WHERE id=?")->execute([$mp*0.6+$tp*0.4, $project_id]);
}

/* ── FORM HANDLING ──────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'create_project':
                $code = generateProjectCode($pdo);
                $pdo->prepare("INSERT INTO projects (project_code,project_name,description,client_name,start_date,expected_end_date,status,priority,budget,location,country,region,city,notes,created_by,progress_percentage) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$code,$_POST['project_name'],$_POST['description'],$_POST['client_name'],$_POST['start_date'],$_POST['expected_end_date'],$_POST['status'],$_POST['priority'],$_POST['budget'],$_POST['location'],$_POST['country']??'Philippines',$_POST['region'],$_POST['city'],$_POST['notes'],$_SESSION['user_id']??1,0]);
                $_SESSION['success_message'] = "Project created: $code";
                header("Location: ".$_SERVER['PHP_SELF']); exit;
            case 'edit_project':
                $pdo->prepare("UPDATE projects SET project_name=?,description=?,client_name=?,start_date=?,expected_end_date=?,status=?,priority=?,budget=?,location=?,country=?,region=?,city=?,notes=? WHERE id=?")
                    ->execute([$_POST['project_name'],$_POST['description'],$_POST['client_name'],$_POST['start_date'],$_POST['expected_end_date'],$_POST['status'],$_POST['priority'],$_POST['budget'],$_POST['location'],$_POST['country']??'Philippines',$_POST['region'],$_POST['city'],$_POST['notes'],$_POST['project_id']]);
                $_SESSION['success_message'] = "Project updated!"; header("Location: ".$_SERVER['PHP_SELF']); exit;
            case 'delete_project':
                $pdo->prepare("DELETE FROM projects WHERE id=?")->execute([$_POST['project_id']]);
                $_SESSION['success_message'] = "Project deleted."; header("Location: ".$_SERVER['PHP_SELF']); exit;
            case 'create_task':
                $pdo->prepare("INSERT INTO project_tasks (project_id,task_name,description,start_date,due_date,status,priority,estimated_hours,notes) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$_POST['project_id'],$_POST['task_name'],$_POST['description'],$_POST['start_date'],$_POST['due_date'],$_POST['status'],$_POST['priority'],$_POST['estimated_hours'],$_POST['notes']]);
                $_SESSION['success_message'] = "Task created!"; header("Location: ".$_SERVER['PHP_SELF']); exit;
            case 'edit_task':
                $pdo->prepare("UPDATE project_tasks SET task_name=?,description=?,start_date=?,due_date=?,status=?,priority=?,estimated_hours=?,notes=? WHERE id=?")
                    ->execute([$_POST['task_name'],$_POST['description'],$_POST['start_date'],$_POST['due_date'],$_POST['status'],$_POST['priority'],$_POST['estimated_hours'],$_POST['notes'],$_POST['task_id']]);
                $_SESSION['success_message'] = "Task updated!"; header("Location: ".$_SERVER['PHP_SELF']); exit;
            case 'delete_task':
                $pdo->prepare("DELETE FROM project_tasks WHERE id=?")->execute([$_POST['task_id']]);
                $_SESSION['success_message'] = "Task deleted."; header("Location: ".$_SERVER['PHP_SELF']); exit;
            case 'create_milestone':
                $pdo->prepare("INSERT INTO project_milestones (project_id,milestone_name,description,due_date,deliverables,priority) VALUES (?,?,?,?,?,?)")
                    ->execute([$_POST['project_id'],$_POST['milestone_name'],$_POST['description'],$_POST['due_date'],$_POST['deliverables'],$_POST['priority']??'medium']);
                updateProjectProgress($_POST['project_id'],$pdo);
                $_SESSION['success_message'] = "Milestone created!"; header("Location: ".$_SERVER['PHP_SELF']); exit;
            case 'edit_milestone':
                $pdo->prepare("UPDATE project_milestones SET milestone_name=?,description=?,due_date=?,deliverables=?,priority=? WHERE id=?")
                    ->execute([$_POST['milestone_name'],$_POST['description'],$_POST['due_date'],$_POST['deliverables'],$_POST['priority'],$_POST['milestone_id']]);
                updateProjectProgress($_POST['project_id'],$pdo);
                $_SESSION['success_message'] = "Milestone updated!"; header("Location: ".$_SERVER['PHP_SELF']); exit;
            case 'delete_milestone':
                $pdo->prepare("DELETE FROM project_milestones WHERE id=?")->execute([$_POST['milestone_id']]);
                updateProjectProgress($_POST['project_id'],$pdo);
                $_SESSION['success_message'] = "Milestone deleted."; header("Location: ".$_SERVER['PHP_SELF']); exit;
            case 'update_task_progress':
                $pdo->prepare("UPDATE project_tasks SET progress_percentage=?,status=?,actual_hours=? WHERE id=?")->execute([$_POST['progress_percentage'],$_POST['status'],$_POST['actual_hours'],$_POST['task_id']]);
                if (!empty($_POST['project_id'])) updateProjectProgress($_POST['project_id'],$pdo);
                $_SESSION['success_message'] = "Progress updated!"; header("Location: ".$_SERVER['PHP_SELF']); exit;
            case 'complete_milestone':
                $pdo->prepare("UPDATE project_milestones SET status='completed',completion_date=CURDATE(),completion_notes=? WHERE id=?")->execute([$_POST['completion_notes'],$_POST['milestone_id']]);
                if (!empty($_POST['project_id'])) updateProjectProgress($_POST['project_id'],$pdo);
                $_SESSION['success_message'] = "Milestone completed!"; header("Location: ".$_SERVER['PHP_SELF']); exit;
            case 'allocate_resource':
                $pdo->prepare("INSERT INTO project_resources (project_id,resource_type,resource_name,quantity_required,quantity_allocated,unit_cost,allocation_date,status,notes,location,region,city) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$_POST['project_id'],$_POST['resource_type'],$_POST['resource_name'],$_POST['quantity_required'],$_POST['quantity_allocated'],$_POST['unit_cost'],$_POST['allocation_date'],$_POST['status']??'allocated',$_POST['notes'],$_POST['location'],$_POST['region'],$_POST['city']]);
                $_SESSION['success_message'] = "Resource allocated!"; header("Location: ".$_SERVER['PHP_SELF']); exit;
            case 'delete_resource':
                $pdo->prepare("DELETE FROM project_resources WHERE id=?")->execute([$_POST['resource_id']]);
                $_SESSION['success_message'] = "Resource removed."; header("Location: ".$_SERVER['PHP_SELF']); exit;
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
        header("Location: ".$_SERVER['PHP_SELF']); exit;
    }
}

/* ── PAGE DATA ──────────────────────────────────────────────── */
$success_message = $_SESSION['success_message'] ?? ''; unset($_SESSION['success_message']);
$error_message   = $_SESSION['error_message']   ?? ''; unset($_SESSION['error_message']);

try {
    $active_projects   = $pdo->query("SELECT COUNT(*) FROM projects WHERE status IN ('planning','active')")->fetchColumn();
    $pending_tasks     = $pdo->query("SELECT COUNT(*) FROM project_tasks WHERE status IN ('pending','in_progress')")->fetchColumn();
    $overdue_tasks     = $pdo->query("SELECT COUNT(*) FROM project_tasks WHERE due_date < CURDATE() AND status != 'completed'")->fetchColumn();
    $pending_milestones= $pdo->query("SELECT COUNT(*) FROM project_milestones WHERE status='pending'")->fetchColumn();
    $total_budget      = $pdo->query("SELECT COALESCE(SUM(budget),0) FROM projects WHERE status IN ('planning','active')")->fetchColumn();
    $avg_progress      = round($pdo->query("SELECT COALESCE(AVG(progress_percentage),0) FROM projects WHERE status='active'")->fetchColumn(), 1);
    $projects          = $pdo->query("SELECT * FROM projects ORDER BY created_at DESC")->fetchAll();
    $all_tasks         = $pdo->query("SELECT t.*,p.project_name FROM project_tasks t JOIN projects p ON t.project_id=p.id ORDER BY t.due_date ASC")->fetchAll();
    $milestones        = $pdo->query("SELECT m.*,p.project_name FROM project_milestones m JOIN projects p ON m.project_id=p.id ORDER BY m.due_date ASC")->fetchAll();
} catch (PDOException $e) {
    $active_projects = $pending_tasks = $overdue_tasks = $pending_milestones = $total_budget = $avg_progress = 0;
    $projects = $all_tasks = $milestones = [];
    $error_message = "Database error: " . $e->getMessage();
}

$view_project = null; $project_tasks = $project_milestones = $project_resources = [];
if (!empty($_GET['project']) && is_numeric($_GET['project'])) {
    $ps = $pdo->prepare("SELECT * FROM projects WHERE id=?"); $ps->execute([$_GET['project']]); $view_project = $ps->fetch();
    if ($view_project) {
        $ts = $pdo->prepare("SELECT * FROM project_tasks WHERE project_id=? ORDER BY due_date ASC"); $ts->execute([$_GET['project']]); $project_tasks = $ts->fetchAll();
        $ms = $pdo->prepare("SELECT * FROM project_milestones WHERE project_id=? ORDER BY due_date ASC"); $ms->execute([$_GET['project']]); $project_milestones = $ms->fetchAll();
        try { $rs = $pdo->prepare("SELECT * FROM project_resources WHERE project_id=? ORDER BY allocation_date DESC"); $rs->execute([$_GET['project']]); $project_resources = $rs->fetchAll(); } catch (Exception $e) {}
    }
}

$page_title = 'Project Logistics Tracker'; $module_subtitle = 'Project Tracker'; $back_btn_href = 'dashboard.php'; $active_nav = 'plt';
include 'includes/head.php';
?>
<style>
.progress-bar { background:var(--off); border-radius:99px; height:8px; overflow:hidden; }
.progress-fill { height:100%; background:linear-gradient(90deg,var(--accent),var(--steel)); border-radius:99px; transition:width .4s ease; }
</style>
<body>
<?php include 'includes/topbar.php'; ?>
<div class="layout">
<?php include 'includes/sidebar.php'; ?>
<main class="main-content">

    <div class="page-title">
        <span class="page-title-tag">Module / Projects</span>
        <h1>Project <strong>Logistics Tracker</strong></h1>
        <p>Track projects, milestones, resource allocation, and team progress.</p>
    </div>

    <?php if ($success_message): ?><div class="alert alert-success"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
    <?php if ($error_message):   ?><div class="alert alert-error"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/></svg><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

    <!-- STATS -->
    <div class="stats-row">
        <div class="stat-card success"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><div class="stat-value"><?php echo $active_projects; ?></div><div class="stat-label">Active Projects</div></div>
        <div class="stat-card <?php echo $overdue_tasks>0?'danger':''; ?>"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div><div class="stat-value"><?php echo $overdue_tasks; ?></div><div class="stat-label">Overdue Tasks</div></div>
        <div class="stat-card <?php echo $pending_milestones>0?'warn':''; ?>"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></div><div class="stat-value"><?php echo $pending_milestones; ?></div><div class="stat-label">Pending Milestones</div></div>
        <div class="stat-card"><div class="stat-icon-wrap"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div><div class="stat-value" style="font-size:1.3rem;">&#8369;<?php echo number_format($total_budget,0); ?></div><div class="stat-label">Active Budget</div></div>
    </div>

    <?php if ($view_project): ?>
    <!-- PROJECT DETAIL VIEW -->
    <div class="panel" style="margin-bottom:1.4rem;">
        <div class="panel-header">
            <span class="panel-title">
                <span class="item-code"><?php echo htmlspecialchars($view_project['project_code']); ?></span>
                &nbsp;<?php echo htmlspecialchars($view_project['project_name']); ?>
            </span>
            <div style="display:flex;gap:.5rem;">
                <button class="btn btn-primary btn-sm" onclick="openModal('addTaskModal')">+ Task</button>
                <button class="btn btn-ghost btn-sm" onclick="openModal('addMilestoneModal')">+ Milestone</button>
                <a href="plt.php" class="btn btn-outline btn-sm">← Back</a>
            </div>
        </div>
        <div style="padding:1.2rem 1.6rem;">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:1rem;margin-bottom:1.4rem;">
                <div><span style="font-size:.72rem;color:var(--muted);text-transform:uppercase;">Status</span><div style="margin-top:.3rem;"><span class="badge badge-<?php echo in_array($view_project['status'],['active','completed'])?'normal':($view_project['status']==='planning'?'warn':'inactive'); ?>"><?php echo ucfirst($view_project['status']); ?></span></div></div>
                <div><span style="font-size:.72rem;color:var(--muted);text-transform:uppercase;">Priority</span><div style="margin-top:.3rem;"><span class="badge badge-<?php echo $view_project['priority']==='critical'?'danger':($view_project['priority']==='high'?'warn':'normal'); ?>"><?php echo ucfirst($view_project['priority']); ?></span></div></div>
                <div><span style="font-size:.72rem;color:var(--muted);text-transform:uppercase;">Client</span><div style="font-weight:600;margin-top:.3rem;"><?php echo htmlspecialchars($view_project['client_name']??'—'); ?></div></div>
                <div><span style="font-size:.72rem;color:var(--muted);text-transform:uppercase;">Budget</span><div style="font-weight:600;margin-top:.3rem;">&#8369;<?php echo number_format($view_project['budget']??0,0); ?></div></div>
                <div><span style="font-size:.72rem;color:var(--muted);text-transform:uppercase;">Start Date</span><div style="font-weight:600;margin-top:.3rem;"><?php echo $view_project['start_date'] ? date('M d, Y',strtotime($view_project['start_date'])) : '—'; ?></div></div>
                <div><span style="font-size:.72rem;color:var(--muted);text-transform:uppercase;">End Date</span><div style="font-weight:600;margin-top:.3rem;"><?php echo $view_project['expected_end_date'] ? date('M d, Y',strtotime($view_project['expected_end_date'])) : '—'; ?></div></div>
            </div>
            <div style="margin-bottom:1.4rem;">
                <div style="display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:.4rem;"><span>Progress</span><span><?php echo round($view_project['progress_percentage']??0,1); ?>%</span></div>
                <div class="progress-bar"><div class="progress-fill" style="width:<?php echo min(100,round($view_project['progress_percentage']??0)); ?>%"></div></div>
            </div>

            <!-- Tasks -->
            <div style="font-size:.85rem;font-weight:600;margin:.8rem 0 .6rem;">Tasks (<?php echo count($project_tasks); ?>)</div>
            <div class="tbl-wrap"><table class="data-table"><thead><tr><th>Task</th><th>Due</th><th>Priority</th><th>Progress</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                <?php if (empty($project_tasks)): ?><tr><td colspan="6" class="empty-td">No tasks yet.</td></tr>
                <?php else: foreach ($project_tasks as $task): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($task['task_name']); ?></strong></td>
                    <td style="font-size:.78rem;"><?php echo $task['due_date'] ? date('M d, Y',strtotime($task['due_date'])) : '—'; ?></td>
                    <td><span class="badge badge-<?php echo $task['priority']==='critical'?'danger':($task['priority']==='high'?'warn':'normal'); ?>"><?php echo ucfirst($task['priority']); ?></span></td>
                    <td style="min-width:100px;"><div class="progress-bar" style="height:6px;"><div class="progress-fill" style="width:<?php echo $task['progress_percentage']??0; ?>%"></div></div><div style="font-size:.72rem;color:var(--muted);margin-top:2px;"><?php echo $task['progress_percentage']??0; ?>%</div></td>
                    <td><span class="badge badge-<?php echo $task['status']==='completed'?'normal':($task['status']==='in_progress'?'high':($task['status']==='overdue'?'danger':'warn')); ?>"><?php echo ucfirst(str_replace('_',' ',$task['status'])); ?></span></td>
                    <td>
                        <div class="btn-row">
                            <button class="btn btn-green btn-sm" onclick="editTask(<?php echo htmlspecialchars(json_encode($task)); ?>)">Edit</button>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete task?')"><input type="hidden" name="action" value="delete_task"><input type="hidden" name="task_id" value="<?php echo $task['id']; ?>"><button type="submit" class="btn btn-red btn-sm">Del</button></form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody></table></div>

            <!-- Milestones -->
            <div style="font-size:.85rem;font-weight:600;margin:1.2rem 0 .6rem;">Milestones (<?php echo count($project_milestones); ?>)</div>
            <div class="tbl-wrap"><table class="data-table"><thead><tr><th>Milestone</th><th>Due</th><th>Priority</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                <?php if (empty($project_milestones)): ?><tr><td colspan="5" class="empty-td">No milestones yet.</td></tr>
                <?php else: foreach ($project_milestones as $ms): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($ms['milestone_name']); ?></strong><?php if ($ms['deliverables']): ?><br><span style="font-size:.74rem;color:var(--muted);"><?php echo htmlspecialchars($ms['deliverables']); ?></span><?php endif; ?></td>
                    <td style="font-size:.78rem;"><?php echo $ms['due_date'] ? date('M d, Y',strtotime($ms['due_date'])) : '—'; ?></td>
                    <td><span class="badge badge-<?php echo $ms['priority']==='critical'?'danger':($ms['priority']==='high'?'warn':'normal'); ?>"><?php echo ucfirst($ms['priority']??'medium'); ?></span></td>
                    <td><span class="badge badge-<?php echo $ms['status']==='completed'?'normal':($ms['status']==='overdue'?'danger':'warn'); ?>"><?php echo ucfirst($ms['status']??'pending'); ?></span></td>
                    <td>
                        <div class="btn-row">
                            <?php if ($ms['status'] !== 'completed'): ?>
                            <form method="POST" style="display:inline"><input type="hidden" name="action" value="complete_milestone"><input type="hidden" name="milestone_id" value="<?php echo $ms['id']; ?>"><input type="hidden" name="project_id" value="<?php echo $view_project['id']; ?>"><input type="hidden" name="completion_notes" value="Completed via quick action"><button type="submit" class="btn btn-green btn-sm" onclick="return confirm('Mark as completed?')">Complete</button></form>
                            <?php endif; ?>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete milestone?')"><input type="hidden" name="action" value="delete_milestone"><input type="hidden" name="milestone_id" value="<?php echo $ms['id']; ?>"><input type="hidden" name="project_id" value="<?php echo $view_project['id']; ?>"><button type="submit" class="btn btn-red btn-sm">Del</button></form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody></table></div>

            <?php if (!empty($project_resources)): ?>
            <div style="font-size:.85rem;font-weight:600;margin:1.2rem 0 .6rem;">Resources</div>
            <div class="tbl-wrap"><table class="data-table"><thead><tr><th>Resource</th><th>Type</th><th>Qty Required</th><th>Qty Allocated</th><th>Unit Cost</th><th>Status</th></tr></thead><tbody>
                <?php foreach ($project_resources as $res): ?>
                <tr><td><?php echo htmlspecialchars($res['resource_name']); ?></td><td><?php echo htmlspecialchars($res['resource_type']); ?></td><td><?php echo $res['quantity_required']; ?></td><td><?php echo $res['quantity_allocated']; ?></td><td>&#8369;<?php echo number_format($res['unit_cost'],2); ?></td><td><span class="badge badge-<?php echo $res['status']==='allocated'?'normal':'warn'; ?>"><?php echo ucfirst($res['status']); ?></span></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- HIDDEN FORMS for tasks/milestones (project id) -->
    <input type="hidden" id="current_project_id" value="<?php echo $view_project['id']; ?>">

    <?php else: ?>
    <!-- PROJECTS LIST -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">Projects</span>
            <button class="btn btn-primary" onclick="openModal('createProjectModal')">
                <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                New Project
            </button>
        </div>
        <div class="tbl-wrap">
            <table class="data-table">
                <thead><tr><th>Code</th><th>Project Name</th><th>Client</th><th>Priority</th><th>Progress</th><th>Due Date</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (empty($projects)): ?><tr><td colspan="8" class="empty-td">No projects yet. <a href="#" onclick="openModal('createProjectModal');return false;">Create your first →</a></td></tr>
                    <?php else: foreach ($projects as $proj): ?>
                    <tr>
                        <td><span class="item-code"><?php echo htmlspecialchars($proj['project_code']); ?></span></td>
                        <td><strong><?php echo htmlspecialchars($proj['project_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($proj['client_name']??'—'); ?></td>
                        <td><span class="badge badge-<?php echo $proj['priority']==='critical'?'danger':($proj['priority']==='high'?'warn':'normal'); ?>"><?php echo ucfirst($proj['priority']); ?></span></td>
                        <td style="min-width:120px;">
                            <div class="progress-bar"><div class="progress-fill" style="width:<?php echo min(100,round($proj['progress_percentage']??0)); ?>%"></div></div>
                            <div style="font-size:.72rem;color:var(--muted);margin-top:2px;"><?php echo round($proj['progress_percentage']??0,1); ?>%</div>
                        </td>
                        <td style="font-size:.78rem;"><?php echo $proj['expected_end_date'] ? date('M d, Y',strtotime($proj['expected_end_date'])) : '—'; ?></td>
                        <td><span class="badge badge-<?php echo in_array($proj['status'],['active','completed'])?'normal':($proj['status']==='planning'?'warn':'inactive'); ?>"><?php echo ucfirst($proj['status']); ?></span></td>
                        <td>
                            <div class="btn-row">
                                <a href="?project=<?php echo $proj['id']; ?>" class="btn btn-ghost btn-sm">View</a>
                                <button class="btn btn-green btn-sm" onclick="editProject(<?php echo htmlspecialchars(json_encode($proj)); ?>)">Edit</button>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Delete project?')"><input type="hidden" name="action" value="delete_project"><input type="hidden" name="project_id" value="<?php echo $proj['id']; ?>"><button type="submit" class="btn btn-red btn-sm">Del</button></form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- RECENT TASKS / MILESTONES -->
    <div class="main-grid" style="margin-top:1.4rem;">
        <div class="panel">
            <div class="panel-header"><span class="panel-title">Upcoming Tasks</span><span class="panel-badge"><?php echo $overdue_tasks; ?> overdue</span></div>
            <div class="tbl-wrap"><table class="data-table"><thead><tr><th>Task</th><th>Project</th><th>Due</th><th>Status</th></tr></thead><tbody>
                <?php if (empty($all_tasks)): ?><tr><td colspan="4" class="empty-td">No tasks.</td></tr>
                <?php else: foreach (array_slice($all_tasks,0,8) as $task): ?>
                <tr><td><?php echo htmlspecialchars($task['task_name']); ?></td><td style="font-size:.78rem;"><?php echo htmlspecialchars($task['project_name']); ?></td><td style="font-size:.78rem;"><?php echo $task['due_date']?date('M d',strtotime($task['due_date'])):'—'; ?></td><td><span class="badge badge-<?php echo $task['status']==='completed'?'normal':($task['due_date']&&strtotime($task['due_date'])<time()&&$task['status']!='completed'?'danger':'warn'); ?>"><?php echo ucfirst(str_replace('_',' ',$task['status'])); ?></span></td></tr>
                <?php endforeach; endif; ?>
            </tbody></table></div>
        </div>
        <div class="panel">
            <div class="panel-header"><span class="panel-title">Milestones</span></div>
            <?php if (empty($milestones)): ?><div class="empty-td" style="padding:2rem;text-align:center;">No milestones.</div>
            <?php else: foreach (array_slice($milestones,0,6) as $ms): ?>
            <div class="list-item">
                <div><div class="li-title"><?php echo htmlspecialchars($ms['milestone_name']); ?></div><div class="li-sub"><?php echo htmlspecialchars($ms['project_name']); ?></div></div>
                <span class="badge badge-<?php echo $ms['status']==='completed'?'normal':($ms['due_date']&&strtotime($ms['due_date'])<time()&&$ms['status']!='completed'?'danger':'warn'); ?>"><?php echo ucfirst($ms['status']??'pending'); ?></span>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
    <?php endif; ?>
</main>
</div>

<!-- CREATE PROJECT MODAL -->
<div id="createProjectModal" class="modal">
    <div class="modal-box wide">
        <div class="modal-head"><h3>New Project</h3><button class="modal-close" onclick="closeModal('createProjectModal')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="create_project">
                <div class="form-row"><div class="form-group"><label class="form-label">Project Name <span class="req">*</span></label><input type="text" name="project_name" class="form-input" required></div>
                <div class="form-group"><label class="form-label">Client Name</label><input type="text" name="client_name" class="form-input"></div></div>
                <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-textarea"></textarea></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Start Date</label><input type="date" name="start_date" class="form-input" value="<?php echo date('Y-m-d'); ?>"></div>
                <div class="form-group"><label class="form-label">Expected End Date</label><input type="date" name="expected_end_date" class="form-input"></div></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Status</label>
                    <select name="status" class="form-select"><option value="planning">Planning</option><option value="active">Active</option><option value="on_hold">On Hold</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></div>
                <div class="form-group"><label class="form-label">Priority</label>
                    <select name="priority" class="form-select"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="critical">Critical</option></select></div></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Budget (₱)</label><input type="number" name="budget" class="form-input" step="0.01" min="0" value="0"></div>
                <div class="form-group"><label class="form-label">Location</label><input type="text" name="location" class="form-input"></div></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Region</label><input type="text" name="region" class="form-input"></div>
                <div class="form-group"><label class="form-label">City</label><input type="text" name="city" class="form-input"></div></div>
                <input type="hidden" name="country" value="Philippines">
                <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" class="form-textarea" rows="2"></textarea></div>
                <button type="submit" class="submit-btn">Create Project</button>
            </form>
        </div>
    </div>
</div>

<!-- EDIT PROJECT MODAL -->
<div id="editProjectModal" class="modal">
    <div class="modal-box wide">
        <div class="modal-head"><h3>Edit Project</h3><button class="modal-close" onclick="closeModal('editProjectModal')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="edit_project">
                <input type="hidden" name="project_id" id="ep2_id">
                <div class="form-row"><div class="form-group"><label class="form-label">Project Name</label><input type="text" name="project_name" id="ep2_name" class="form-input" required></div>
                <div class="form-group"><label class="form-label">Client Name</label><input type="text" name="client_name" id="ep2_client" class="form-input"></div></div>
                <div class="form-group"><label class="form-label">Description</label><textarea name="description" id="ep2_desc" class="form-textarea"></textarea></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Start Date</label><input type="date" name="start_date" id="ep2_start" class="form-input"></div>
                <div class="form-group"><label class="form-label">End Date</label><input type="date" name="expected_end_date" id="ep2_end" class="form-input"></div></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Status</label><select name="status" id="ep2_status" class="form-select"><option value="planning">Planning</option><option value="active">Active</option><option value="on_hold">On Hold</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></div>
                <div class="form-group"><label class="form-label">Priority</label><select name="priority" id="ep2_priority" class="form-select"><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option><option value="critical">Critical</option></select></div></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Budget (₱)</label><input type="number" name="budget" id="ep2_budget" class="form-input" step="0.01"></div>
                <div class="form-group"><label class="form-label">Location</label><input type="text" name="location" id="ep2_location" class="form-input"></div></div>
                <input type="hidden" name="country" value="Philippines">
                <div class="form-row"><div class="form-group"><label class="form-label">Region</label><input type="text" name="region" id="ep2_region" class="form-input"></div>
                <div class="form-group"><label class="form-label">City</label><input type="text" name="city" id="ep2_city" class="form-input"></div></div>
                <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" id="ep2_notes" class="form-textarea" rows="2"></textarea></div>
                <button type="submit" class="submit-btn">Save Changes</button>
            </form>
        </div>
    </div>
</div>

<!-- ADD TASK MODAL (used inside project view) -->
<div id="addTaskModal" class="modal">
    <div class="modal-box">
        <div class="modal-head"><h3>Add Task</h3><button class="modal-close" onclick="closeModal('addTaskModal')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="create_task">
                <input type="hidden" name="project_id" id="task_project_id">
                <div class="form-group"><label class="form-label">Task Name <span class="req">*</span></label><input type="text" name="task_name" class="form-input" required></div>
                <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-textarea" rows="2"></textarea></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Start Date</label><input type="date" name="start_date" class="form-input"></div>
                <div class="form-group"><label class="form-label">Due Date</label><input type="date" name="due_date" class="form-input"></div></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Status</label><select name="status" class="form-select"><option value="pending">Pending</option><option value="in_progress">In Progress</option><option value="completed">Completed</option></select></div>
                <div class="form-group"><label class="form-label">Priority</label><select name="priority" class="form-select"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="critical">Critical</option></select></div></div>
                <div class="form-group"><label class="form-label">Estimated Hours</label><input type="number" name="estimated_hours" class="form-input" min="0" step="0.5" value="0"></div>
                <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" class="form-textarea" rows="2"></textarea></div>
                <button type="submit" class="submit-btn">Add Task</button>
            </form>
        </div>
    </div>
</div>

<!-- EDIT TASK MODAL -->
<div id="editTaskModal" class="modal">
    <div class="modal-box">
        <div class="modal-head"><h3>Edit Task</h3><button class="modal-close" onclick="closeModal('editTaskModal')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="edit_task">
                <input type="hidden" name="task_id" id="et_id">
                <div class="form-group"><label class="form-label">Task Name</label><input type="text" name="task_name" id="et_name" class="form-input" required></div>
                <div class="form-group"><label class="form-label">Description</label><textarea name="description" id="et_desc" class="form-textarea" rows="2"></textarea></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Start Date</label><input type="date" name="start_date" id="et_start" class="form-input"></div>
                <div class="form-group"><label class="form-label">Due Date</label><input type="date" name="due_date" id="et_due" class="form-input"></div></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Status</label><select name="status" id="et_status" class="form-select"><option value="pending">Pending</option><option value="in_progress">In Progress</option><option value="completed">Completed</option></select></div>
                <div class="form-group"><label class="form-label">Priority</label><select name="priority" id="et_priority" class="form-select"><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option><option value="critical">Critical</option></select></div></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Estimated Hours</label><input type="number" name="estimated_hours" id="et_est" class="form-input" min="0" step="0.5"></div>
                <div class="form-group"><label class="form-label">Progress %</label><input type="number" name="progress_percentage" id="et_prog" class="form-input" min="0" max="100"></div></div>
                <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" id="et_notes" class="form-textarea" rows="2"></textarea></div>
                <button type="submit" class="submit-btn">Save Changes</button>
            </form>
        </div>
    </div>
</div>

<!-- ADD MILESTONE MODAL -->
<div id="addMilestoneModal" class="modal">
    <div class="modal-box">
        <div class="modal-head"><h3>Add Milestone</h3><button class="modal-close" onclick="closeModal('addMilestoneModal')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="create_milestone">
                <input type="hidden" name="project_id" id="ms_project_id">
                <div class="form-group"><label class="form-label">Milestone Name <span class="req">*</span></label><input type="text" name="milestone_name" class="form-input" required></div>
                <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-textarea" rows="2"></textarea></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Due Date <span class="req">*</span></label><input type="date" name="due_date" class="form-input" required></div>
                <div class="form-group"><label class="form-label">Priority</label><select name="priority" class="form-select"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="critical">Critical</option></select></div></div>
                <div class="form-group"><label class="form-label">Deliverables</label><textarea name="deliverables" class="form-textarea" rows="2" placeholder="List key deliverables…"></textarea></div>
                <button type="submit" class="submit-btn">Add Milestone</button>
            </form>
        </div>
    </div>
</div>

<script>
function editProject(p) {
    document.getElementById('ep2_id').value       = p.id;
    document.getElementById('ep2_name').value     = p.project_name;
    document.getElementById('ep2_client').value   = p.client_name || '';
    document.getElementById('ep2_desc').value     = p.description || '';
    document.getElementById('ep2_start').value    = p.start_date ? p.start_date.substring(0,10) : '';
    document.getElementById('ep2_end').value      = p.expected_end_date ? p.expected_end_date.substring(0,10) : '';
    document.getElementById('ep2_status').value   = p.status;
    document.getElementById('ep2_priority').value = p.priority;
    document.getElementById('ep2_budget').value   = p.budget || 0;
    document.getElementById('ep2_location').value = p.location || '';
    document.getElementById('ep2_region').value   = p.region || '';
    document.getElementById('ep2_city').value     = p.city || '';
    document.getElementById('ep2_notes').value    = p.notes || '';
    openModal('editProjectModal');
}
function editTask(t) {
    document.getElementById('et_id').value       = t.id;
    document.getElementById('et_name').value     = t.task_name;
    document.getElementById('et_desc').value     = t.description || '';
    document.getElementById('et_start').value    = t.start_date ? t.start_date.substring(0,10) : '';
    document.getElementById('et_due').value      = t.due_date ? t.due_date.substring(0,10) : '';
    document.getElementById('et_status').value   = t.status;
    document.getElementById('et_priority').value = t.priority;
    document.getElementById('et_est').value      = t.estimated_hours || 0;
    document.getElementById('et_prog').value     = t.progress_percentage || 0;
    document.getElementById('et_notes').value    = t.notes || '';
    openModal('editTaskModal');
}
// Inject project_id into task/milestone forms when in project view
document.addEventListener('DOMContentLoaded', function() {
    var pid = document.getElementById('current_project_id');
    if (pid) {
        var pidVal = pid.value;
        ['task_project_id','ms_project_id'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.value = pidVal;
        });
    }
});
</script>
<?php include 'includes/footer.php'; ?>
