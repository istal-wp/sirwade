<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_role('admin');
// ═══ ADMIN MONITORING-ONLY GUARD ═══════════════════════════════════════════
// Write operations (INSERT/UPDATE/DELETE) have been moved to staff/projects.php
// Admin is read-only/monitoring. Redirect any POST attempts.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['flash_type']    = 'warning';
    $_SESSION['flash_message'] = 'Admin is monitoring-only. Use the Staff Portal for data modifications.';
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}
// ═══════════════════════════════════════════════════════════════════════════


require_once __DIR__ . '/../includes/db.php';
$pdo = db(); // Shared singleton from includes/db.php
try {
    
    $message = '';
    $error = '';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_project'])) {
        $project_code = $_POST['project_code'];
        $project_name = $_POST['project_name'];
        $description = $_POST['description'];
        $client_name = $_POST['client_name'];
        $start_date = $_POST['start_date'];
        $expected_end_date = $_POST['expected_end_date'];
        $status = $_POST['status'];
        $priority = $_POST['priority'];
        $budget = $_POST['budget'];
        $location = $_POST['location'];
        $notes = $_POST['notes'];
        
        $stmt = $pdo->prepare("INSERT INTO projects (project_code, project_name, description, client_name, start_date, expected_end_date, status, priority, budget, location, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$project_code, $project_name, $description, $client_name, $start_date, $expected_end_date, $status, $priority, $budget, $location, $notes, 0])) {
            $message = "Project added successfully!";
        } else {
            $error = "Error adding project.";
        }
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_task'])) {
        $project_id = $_POST['project_id'];
        $task_name = $_POST['task_name'];
        $description = $_POST['task_description'];
        $start_date = $_POST['task_start_date'];
        $due_date = $_POST['task_due_date'];
        $priority = $_POST['task_priority'];
        $estimated_hours = $_POST['estimated_hours'];
        $notes = $_POST['task_notes'];
        
        $stmt = $pdo->prepare("INSERT INTO project_tasks (project_id, task_name, description, start_date, due_date, priority, estimated_hours, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$project_id, $task_name, $description, $start_date, $due_date, $priority, $estimated_hours, $notes])) {
            $message = "Task added successfully!";
        } else {
            $error = "Error adding task.";
        }
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_milestone'])) {
        $project_id = $_POST['milestone_project_id'];
        $milestone_name = $_POST['milestone_name'];
        $description = $_POST['milestone_description'];
        $due_date = $_POST['milestone_due_date'];
        $deliverables = $_POST['deliverables'];
        
        $stmt = $pdo->prepare("INSERT INTO project_milestones (project_id, milestone_name, description, due_date, deliverables) VALUES (?, ?, ?, ?, ?)");
        if ($stmt->execute([$project_id, $milestone_name, $description, $due_date, $deliverables])) {
            $message = "Milestone added successfully!";
        } else {
            $error = "Error adding milestone.";
        }
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_resource'])) {
        $project_id = $_POST['resource_project_id'];
        $resource_type = $_POST['resource_type'];
        $resource_name = $_POST['resource_name'];
        $quantity_required = $_POST['quantity_required'];
        $unit_cost = $_POST['unit_cost'];
        $allocation_date = $_POST['allocation_date'];
        $notes = $_POST['resource_notes'];
        
        $stmt = $pdo->prepare("INSERT INTO project_resources (project_id, resource_type, resource_name, quantity_required, unit_cost, allocation_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$project_id, $resource_type, $resource_name, $quantity_required, $unit_cost, $allocation_date, $notes])) {
            $message = "Resource added successfully!";
        } else {
            $error = "Error adding resource.";
        }
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
        $project_id = $_POST['status_project_id'];
        $new_status = $_POST['new_status'];
        $progress = $_POST['progress_percentage'];
        
        $stmt = $pdo->prepare("UPDATE projects SET status = ?, progress_percentage = ? WHERE id = ?");
        if ($stmt->execute([$new_status, $progress, $project_id])) {
            $message = "Project status updated successfully!";
        } else {
            $error = "Error updating project status.";
        }
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_task_status'])) {
        $task_id = $_POST['task_id'];
        $new_status = $_POST['task_new_status'];
        $progress = $_POST['task_progress_percentage'];
        $actual_hours = $_POST['actual_hours'];
        
        $completion_date = ($new_status === 'completed') ? date('Y-m-d') : null;
        
        $stmt = $pdo->prepare("UPDATE project_tasks SET status = ?, progress_percentage = ?, actual_hours = ?, completion_date = ? WHERE id = ?");
        if ($stmt->execute([$new_status, $progress, $actual_hours, $completion_date, $task_id])) {
            $message = "Task status updated successfully!";
        } else {
            $error = "Error updating task status.";
        }
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_milestone'])) {
        $milestone_id = $_POST['milestone_id'];
        $completion_notes = $_POST['completion_notes'];
        
        $stmt = $pdo->prepare("UPDATE project_milestones SET status = 'completed', completion_date = CURDATE(), completion_notes = ? WHERE id = ?");
        if ($stmt->execute([$completion_notes, $milestone_id])) {
            $message = "Milestone completed successfully!";
        } else {
            $error = "Error completing milestone.";
        }
    }
    
    $projects = $pdo->query("
        SELECT p.*, 
               COUNT(DISTINCT pt.id) as total_tasks,
               COUNT(DISTINCT CASE WHEN pt.status = 'completed' THEN pt.id END) as completed_tasks,
               COUNT(DISTINCT pm.id) as total_milestones,
               COUNT(DISTINCT CASE WHEN pm.status = 'completed' THEN pm.id END) as completed_milestones,
               COALESCE(SUM(pr.quantity_used * pr.unit_cost), 0) as actual_cost
        FROM projects p
        LEFT JOIN project_tasks pt ON p.id = pt.project_id
        LEFT JOIN project_milestones pm ON p.id = pm.project_id
        LEFT JOIN project_resources pr ON p.id = pr.project_id
        GROUP BY p.id
        ORDER BY p.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $stats = [];
    
    $stats['total_projects'] = $pdo->query("SELECT COUNT(*) as count FROM projects")->fetch()['count'];
    $stats['active_projects'] = $pdo->query("SELECT COUNT(*) as count FROM projects WHERE status = 'active'")->fetch()['count'];
    $stats['completed_projects'] = $pdo->query("SELECT COUNT(*) as count FROM projects WHERE status = 'completed'")->fetch()['count'];
    $stats['overdue_projects'] = $pdo->query("SELECT COUNT(*) as count FROM projects WHERE expected_end_date < CURDATE() AND status NOT IN ('completed', 'cancelled')")->fetch()['count'];
    $stats['total_tasks'] = $pdo->query("SELECT COUNT(*) as count FROM project_tasks")->fetch()['count'];
    $stats['pending_tasks'] = $pdo->query("SELECT COUNT(*) as count FROM project_tasks WHERE status IN ('pending', 'in_progress')")->fetch()['count'];
    $stats['overdue_tasks'] = $pdo->query("SELECT COUNT(*) as count FROM project_tasks WHERE due_date < CURDATE() AND status NOT IN ('completed', 'cancelled')")->fetch()['count'];
    $stats['total_budget'] = $pdo->query("SELECT COALESCE(SUM(budget), 0) as total FROM projects")->fetch()['total'];

} catch(PDOException $e) {
    error_log("Project Management error: " . $e->getMessage());
    $error = "Database connection failed.";
    $projects = [];
    $stats = array_fill_keys(['total_projects', 'active_projects', 'completed_projects', 'overdue_projects', 'total_tasks', 'pending_tasks', 'overdue_tasks', 'total_budget'], 0);
}
?>

<!DOCTYPE html>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Management — BRIGHTPATH</title>
<!-- SHARED DESIGN SYSTEM: DM Sans + DM Mono, navy/blue/accent palette -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
        /* ═══ UNIVERSAL TOPBAR (STAFF) ═══════════════════════════════════ */
        .header {
            background: #ffffff !important;
            border-bottom: 1px solid #dde3ef;
            padding: 0 2rem;
            position: sticky; top: 0; z-index: 500;
            box-shadow: 0 1px 8px rgba(15,31,61,.09);
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
        }
        .header-inner {
            display: flex; justify-content: space-between; align-items: center;
            max-width: 1600px; margin: 0 auto; height: 64px;
        }
        .header-left { display: flex; align-items: center; gap: 12px; }
        .brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .brand-mark {
            width: 38px; height: 38px;
            background: linear-gradient(135deg, #0f1f3d, #2c4a8a);
            border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .brand-mark svg { width: 20px; height: 20px; stroke: rgba(255,255,255,.9); fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .brand-text h1 { font-size: 1rem; font-weight: 600; color: #0f1f3d; letter-spacing: .05em; }
        .brand-text p  { font-size: .68rem; color: #6b7a99; letter-spacing: .08em; text-transform: uppercase; font-family: 'DM Mono', monospace; }
        .header-right { display: flex; align-items: center; gap: .85rem; }
        .btn-back {
            display: flex; align-items: center; gap: 7px;
            padding: .48rem 1rem; background: none; border: 1.5px solid #dde3ef; border-radius: 8px;
            font-size: .82rem; font-weight: 500; font-family: 'DM Sans', sans-serif;
            color: #6b7a99; cursor: pointer; text-decoration: none; transition: border-color .2s, color .2s;
        }
        .btn-back svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .btn-back:hover { border-color: #3d7fff; color: #3d7fff; }
        /* Profile pill */
        .profile-wrap { position: relative; }
        .user-pill {
            display: flex; align-items: center; gap: 9px;
            padding: .38rem .8rem .38rem .38rem;
            background: #f4f6fb; border: 1.5px solid #dde3ef; border-radius: 99px;
            cursor: pointer; transition: border-color .2s, box-shadow .2s; user-select: none;
        }
        .user-pill:hover { border-color: #3d7fff; box-shadow: 0 2px 12px rgba(61,127,255,.12); }
        .user-avatar {
            width: 28px; height: 28px; border-radius: 50%;
            background: linear-gradient(135deg, #0f1f3d, #2c4a8a);
            display: flex; align-items: center; justify-content: center;
            font-family: 'DM Mono', monospace; font-size: .7rem; font-weight: 600; color: #fff; flex-shrink: 0;
        }
        .user-name { font-size: .83rem; font-weight: 500; color: #1a2540; }
        .pill-caret { width: 14px; height: 14px; stroke: #6b7a99; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; transition: transform .2s; flex-shrink: 0; }
        .profile-wrap.open .pill-caret { transform: rotate(180deg); }
        /* Profile dropdown */
        .profile-dropdown {
            display: none; position: absolute; top: calc(100% + 10px); right: 0;
            width: 280px; background: #ffffff; border: 1px solid #dde3ef;
            border-radius: 14px; box-shadow: 0 12px 40px rgba(15,31,61,.2);
            z-index: 600; overflow: hidden; animation: dropIn .18s ease;
        }
        .profile-wrap.open .profile-dropdown { display: block; }
        @keyframes dropIn { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
        .pd-head {
            padding: 1.2rem 1.4rem 1rem;
            background: linear-gradient(135deg, #0f1f3d, #2c4a8a);
            display: flex; align-items: center; gap: 12px;
        }
        .pd-avatar {
            width: 44px; height: 44px; border-radius: 50%;
            background: rgba(255,255,255,.18); border: 2px solid rgba(255,255,255,.3);
            display: flex; align-items: center; justify-content: center;
            font-family: 'DM Mono', monospace; font-size: .9rem; font-weight: 700; color: #fff; flex-shrink: 0;
        }
        .pd-info-name  { font-size: .95rem; font-weight: 600; color: #fff; }
        .pd-info-email { font-size: .75rem; color: rgba(255,255,255,.6); margin-top: 1px; word-break: break-all; }
        .pd-body { padding: .75rem 1.4rem; }
        .pd-row {
            display: flex; align-items: center; gap: 10px;
            padding: .55rem 0; border-bottom: 1px solid #f4f6fb; font-size: .82rem;
        }
        .pd-row:last-child { border-bottom: none; }
        .pd-row svg { width: 14px; height: 14px; stroke: #6b7a99; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }
        .pd-row-label { color: #6b7a99; min-width: 60px; }
        .pd-row-val   { color: #1a2540; font-weight: 500; margin-left: auto; text-align: right; }
        .pd-role-badge { display: inline-block; padding: .18rem .55rem; border-radius: 99px; font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; background: rgba(61,127,255,.1); color: #3d7fff; }
        .pd-role-badge.staff { background: rgba(21,128,61,.1); color: #15803d; }
        .pd-foot { padding: .75rem 1.4rem 1rem; border-top: 1px solid #dde3ef; }
        .pd-logout {
            display: flex; align-items: center; justify-content: center; gap: 7px;
            width: 100%; padding: .6rem; border-radius: 8px;
            background: rgba(197,48,48,.07); border: 1.5px solid rgba(197,48,48,.2);
            color: #c53030; font-size: .84rem; font-weight: 600; font-family: 'DM Sans', sans-serif;
            cursor: pointer; text-decoration: none; transition: background .2s, border-color .2s;
        }
        .pd-logout svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .pd-logout:hover { background: rgba(197,48,48,.14); border-color: #c53030; }
        /* ═══ END TOPBAR ════════════════════════════════════════════════ */
        
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
:root {
    --navy: #0f1f3d; --blue: #1a3a6e; --accent: #3d7fff; --steel: #2c4a8a;
    --white: #ffffff; --off: #f4f6fb; --border: #dde3ef;
    --text: #1a2540; --muted: #6b7a99;
    --success: #15803d; --warn: #b45309; --error: #c53030;
    --success-bg: #f0fdf4; --success-border: #bbf7d0;
    --warn-bg: #fffbeb; --warn-border: #fde68a;
    --error-bg: #fff5f5; --error-border: #fed7d7;
    --info-bg: #eff6ff; --info-border: #bfdbfe; --info: #1d4ed8;
}
body { font-family: 'DM Sans', sans-serif; background: var(--off); min-height: 100vh; color: var(--text); }

/* HEADER */
.header { background: var(--white); border-bottom: 1px solid var(--border); padding: 0 2rem; position: sticky; top: 0; z-index: 100; box-shadow: 0 1px 8px rgba(15,31,61,.07); }
.header-inner { display: flex; justify-content: space-between; align-items: center; max-width: 1400px; margin: 0 auto; height: 64px; }
.brand { display: flex; align-items: center; gap: 12px; }
.brand-mark { width: 38px; height: 38px; background: linear-gradient(135deg, var(--navy), var(--steel)); border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.brand-mark svg { width: 20px; height: 20px; stroke: rgba(255,255,255,.9); }
.brand-text h1 { font-size: 1rem; font-weight: 600; color: var(--navy); letter-spacing: 0.05em; }
.brand-text p  { font-size: 0.7rem; color: var(--muted); letter-spacing: 0.08em; text-transform: uppercase; font-family: 'DM Mono', monospace; }
.btn-home { display: flex; align-items: center; gap: 7px; padding: 0.5rem 1rem; background: none; border: 1.5px solid var(--border); border-radius: 8px; font-size: 0.82rem; font-weight: 500; font-family: 'DM Sans', sans-serif; color: var(--muted); cursor: pointer; transition: all .2s; text-decoration: none; }
.btn-home svg { width: 14px; height: 14px; stroke: currentColor; }
.btn-home:hover { border-color: var(--accent); color: var(--accent); }

/* MAIN */
.main { max-width: 1400px; margin: 0 auto; padding: 2rem 2rem 3rem; }
.page-title { margin-bottom: 1.75rem; }
.page-title h1 { font-size: 1.6rem; font-weight: 600; color: var(--navy); margin-bottom: 0.25rem; }
.page-title p  { font-size: 0.88rem; color: var(--muted); }

/* ALERTS */
.alert { display: flex; align-items: flex-start; gap: 10px; padding: 0.85rem 1.1rem; border-radius: 10px; font-size: 0.87rem; line-height: 1.5; margin-bottom: 1rem; }
.alert svg { width: 16px; height: 16px; stroke: currentColor; flex-shrink: 0; margin-top: 1px; }
.alert-success { background: var(--success-bg); border: 1px solid var(--success-border); color: var(--success); }
.alert-error   { background: var(--error-bg);   border: 1px solid var(--error-border);   color: var(--error); }
.alert-warn    { background: var(--warn-bg);     border: 1px solid var(--warn-border);     color: var(--warn); }
.alert-info    { background: var(--info-bg);     border: 1px solid var(--info-border);     color: var(--info); }
.alert a { color: inherit; font-weight: 600; }

/* STATS GRID */
.stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
.stat-card { background: var(--white); border: 1px solid var(--border); border-radius: 12px; padding: 1.25rem 1.4rem; transition: box-shadow .2s, transform .15s; }
.stat-card:hover { box-shadow: 0 4px 18px rgba(15,31,61,.08); transform: translateY(-2px); }
.stat-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem; }
.stat-label { font-size: 0.75rem; font-weight: 500; color: var(--muted); text-transform: uppercase; letter-spacing: 0.06em; }
.stat-badge { width: 36px; height: 36px; background: rgba(61,127,255,.09); border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.stat-badge svg { width: 17px; height: 17px; stroke: var(--accent); }
.stat-badge.warn-badge { background: rgba(180,83,9,.09); } .stat-badge.warn-badge svg { stroke: var(--warn); }
.stat-badge.success-badge { background: rgba(21,128,61,.09); } .stat-badge.success-badge svg { stroke: var(--success); }
.stat-badge.error-badge { background: rgba(197,48,48,.09); } .stat-badge.error-badge svg { stroke: var(--error); }
.stat-value { font-size: 1.9rem; font-weight: 600; color: var(--navy); line-height: 1; margin-bottom: 0.4rem; }
.stat-sub { display: flex; align-items: center; gap: 5px; font-size: 0.76rem; color: var(--muted); }
.stat-sub svg { width: 11px; height: 11px; stroke: currentColor; }
.stat-sub.good { color: var(--success); } .stat-sub.warn { color: var(--warn); } .stat-sub.bad { color: var(--error); }

/* PANELS / TABLES */
.panel { background: var(--white); border: 1px solid var(--border); border-radius: 12px; padding: 1.5rem 1.6rem; margin-bottom: 1.5rem; }
.panel-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border); }
.panel-head h2 { font-size: 1rem; font-weight: 600; color: var(--navy); display: flex; align-items: center; gap: 8px; }
.panel-head h2 svg { width: 16px; height: 16px; stroke: var(--accent); }
.panel-head a, .panel-head span { font-size: 0.82rem; font-weight: 500; color: var(--accent); text-decoration: none; }

.section-label { font-size: 0.75rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.85rem; }

/* TABLE */
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { padding: 0.85rem 1rem; text-align: left; border-bottom: 1px solid var(--off); font-size: 0.85rem; }
.data-table th { background: var(--off); font-weight: 600; color: var(--muted); font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.07em; border-bottom: 1px solid var(--border); }
.data-table td { color: var(--text); }
.data-table tbody tr:hover { background: rgba(61,127,255,.03); }
.data-table tbody tr:last-child td { border-bottom: none; }
.no-data { text-align: center; padding: 2rem; color: var(--muted); font-size: 0.88rem; font-style: italic; }

/* CONTROLS */
.controls-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem; }
.controls-bar h2 { font-size: 1rem; font-weight: 600; color: var(--navy); }
.btn-row { display: flex; gap: 0.65rem; flex-wrap: wrap; }

/* BUTTONS */
.btn { display: inline-flex; align-items: center; gap: 7px; padding: 0.6rem 1.1rem; border: none; border-radius: 8px; font-size: 0.84rem; font-weight: 500; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: opacity .2s, transform .15s, box-shadow .2s; text-decoration: none; white-space: nowrap; }
.btn svg { width: 14px; height: 14px; stroke: currentColor; flex-shrink: 0; }
.btn-primary   { background: linear-gradient(135deg, var(--navy), var(--blue)); color: var(--white); }
.btn-success   { background: linear-gradient(135deg, #16a34a, #15803d); color: var(--white); }
.btn-warning   { background: linear-gradient(135deg, #d97706, #b45309); color: var(--white); }
.btn-danger    { background: linear-gradient(135deg, #dc2626, #b91c1c); color: var(--white); }
.btn-outline   { background: var(--white); border: 1.5px solid var(--border); color: var(--text); }
.btn:hover { opacity: .9; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(15,31,61,.15); }
.btn-sm { padding: 0.4rem 0.7rem; font-size: 0.78rem; }

/* SEARCH */
.search-grid { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem; }
.field label { display: block; font-size: 0.78rem; font-weight: 500; color: var(--text); margin-bottom: 0.35rem; }
.form-control { width: 100%; padding: 0.62rem 0.85rem; border: 1.5px solid var(--border); border-radius: 8px; font-size: 0.86rem; font-family: 'DM Sans', sans-serif; color: var(--text); background: var(--white); transition: border-color .2s, box-shadow .2s; }
.form-control:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(61,127,255,.12); }

/* STATUS BADGES */
.badge { padding: 0.22rem 0.65rem; border-radius: 99px; font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; display: inline-flex; align-items: center; gap: 4px; }
.badge svg { width: 10px; height: 10px; stroke: currentColor; }
.badge-active,   .status-active    { background: #dcfce7; color: #15803d; }
.badge-inactive, .status-inactive  { background: #fee2e2; color: #dc2626; }
.badge-pending,  .status-pending   { background: #fef3c7; color: #92400e; }
.badge-draft,    .status-draft     { background: #fef3c7; color: #92400e; }
.badge-sent,     .status-sent      { background: #dbeafe; color: #1e40af; }
.badge-confirmed,.status-confirmed { background: #d1fae5; color: #065f46; }
.badge-completed,.status-completed { background: #dcfce7; color: #166534; }
.badge-cancelled,.status-cancelled { background: #f3f4f6; color: #374151; }
.badge-received, .status-received  { background: #e0e7ff; color: #3730a3; }
.badge-planning, .status-planning  { background: #fef3c7; color: #92400e; }
.status-on_hold                    { background: #fef2f2; color: #991b1b; }
.status-maintenance                { background: #fef3c7; color: #92400e; }
.status-retired                    { background: #f3f4f6; color: #374151; }
.status-under_review               { background: #fef3c7; color: #92400e; }
.status-accepted                   { background: #d1fae5; color: #065f46; }
.status-rejected                   { background: #fee2e2; color: #991b1b; }
.status-partial                    { background: #fef3c7; color: #92400e; }
.status-low-stock                  { background: #fef3c7; color: #92400e; }
.status-out-stock, .badge-out      { background: #fee2e2; color: #dc2626; }
.status-expired                    { background: #fee2e2; color: #dc2626; }
.priority-low      { background: #f0f9ff; color: #0c4a6e; }
.priority-medium   { background: #fef3c7; color: #92400e; }
.priority-high     { background: #fef2f2; color: #991b1b; }
.priority-critical { background: #fdf2f8; color: #be185d; }

/* MODAL */
.modal { display: none; position: fixed; inset: 0; z-index: 1000; background: rgba(15,31,61,.55); backdrop-filter: blur(4px); }
.modal-box { background: var(--white); margin: 4% auto; border-radius: 14px; width: 90%; max-width: 580px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 50px rgba(15,31,61,.25); }
.modal-head { background: linear-gradient(135deg, var(--navy), var(--steel)); color: var(--white); padding: 1.25rem 1.6rem; border-radius: 14px 14px 0 0; display: flex; justify-content: space-between; align-items: center; }
.modal-head h3 { font-size: 1rem; font-weight: 600; }
.modal-close { background: none; border: none; color: rgba(255,255,255,.8); font-size: 1.4rem; cursor: pointer; line-height: 1; padding: 0 4px; }
.modal-close:hover { color: var(--white); }
.modal-body { padding: 1.6rem; }
.form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem; }
.form-field { margin-bottom: 0.9rem; }
.form-field label { display: block; font-size: 0.8rem; font-weight: 500; color: var(--text); margin-bottom: 0.38rem; }
.form-field .form-control { width: 100%; }
textarea.form-control { min-height: 90px; resize: vertical; }
.modal-footer { display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.5rem; }

/* ACTIVITY / FEED */
.feed-row { display: flex; align-items: center; gap: 12px; padding: 0.85rem 0; border-bottom: 1px solid var(--off); }
.feed-row:last-child { border-bottom: none; }
.feed-icon { width: 36px; height: 36px; border-radius: 9px; background: linear-gradient(135deg, var(--navy), var(--steel)); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.feed-icon svg { width: 16px; height: 16px; stroke: rgba(255,255,255,.9); }
.feed-body h4 { font-size: 0.88rem; font-weight: 500; color: var(--text); margin-bottom: 0.15rem; }
.feed-body p  { font-size: 0.78rem; color: var(--muted); }
.feed-time { margin-left: auto; font-size: 0.75rem; color: var(--muted); font-family: 'DM Mono', monospace; white-space: nowrap; }

/* EMPTY STATE */
.empty-state { text-align: center; padding: 3rem 2rem; }
.empty-state svg { width: 48px; height: 48px; stroke: var(--border); margin-bottom: 1rem; }
.empty-state h3 { font-size: 0.95rem; color: var(--text); margin-bottom: 0.4rem; }
.empty-state p  { font-size: 0.83rem; color: var(--muted); }

/* TABS */
.tabs { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
.tab-btn { display: flex; align-items: center; gap: 7px; padding: 0.6rem 1.1rem; border: 1.5px solid var(--border); border-radius: 8px; background: var(--white); font-size: 0.84rem; font-weight: 500; font-family: 'DM Sans', sans-serif; color: var(--muted); cursor: pointer; transition: all .2s; }
.tab-btn svg { width: 14px; height: 14px; stroke: currentColor; }
.tab-btn.active { background: var(--navy); color: var(--white); border-color: var(--navy); }
.tab-btn:hover:not(.active) { border-color: var(--accent); color: var(--accent); }
.tab-panel { display: none; } .tab-panel.active { display: block; }

/* PROJECT CARD */
.project-card { background: var(--white); border: 1px solid var(--border); border-radius: 12px; padding: 1.4rem; position: relative; overflow: hidden; transition: box-shadow .2s, transform .15s; }
.project-card:hover { box-shadow: 0 6px 22px rgba(15,31,61,.1); transform: translateY(-2px); }
.project-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(135deg, var(--navy), var(--accent)); }
.projects-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 1rem; }
.project-title { font-size: 0.98rem; font-weight: 600; color: var(--navy); margin-bottom: 0.3rem; }
.project-code { font-size: 0.78rem; color: var(--muted); font-family: 'DM Mono', monospace; margin-bottom: 0.65rem; }
.project-desc { font-size: 0.83rem; color: var(--muted); line-height: 1.55; margin-bottom: 1rem; }
.project-stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.5rem; margin-bottom: 1rem; }
.pstat { text-align: center; padding: 0.4rem; background: var(--off); border-radius: 7px; }
.pstat-val { font-size: 1.1rem; font-weight: 600; color: var(--navy); }
.pstat-lbl { font-size: 0.65rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em; }
.progress-bar { height: 6px; background: var(--border); border-radius: 99px; overflow: hidden; margin: 0.75rem 0; }
.progress-fill { height: 100%; background: linear-gradient(90deg, var(--navy), var(--accent)); transition: width .3s; }
.progress-label { text-align: right; font-size: 0.72rem; color: var(--muted); font-family: 'DM Mono', monospace; margin-bottom: 0.75rem; }
.project-meta { font-size: 0.8rem; color: var(--muted); display: grid; grid-template-columns: 1fr 1fr; gap: 0.3rem; margin-bottom: 1rem; }
.project-meta strong { color: var(--text); }
.project-actions-row { display: flex; gap: 0.5rem; flex-wrap: wrap; }

/* SUPPLIER / DOCUMENT SPECIFICS */
.score-stars { color: #f59e0b; font-size: 0.9rem; letter-spacing: 1px; }
.doc-item { display: flex; align-items: flex-start; gap: 12px; padding: 0.9rem 0; border-bottom: 1px solid var(--off); }
.doc-item:last-child { border-bottom: none; }
.doc-icon-box { width: 36px; height: 36px; background: rgba(61,127,255,.09); border: 1px solid rgba(61,127,255,.2); border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.doc-icon-box svg { width: 16px; height: 16px; stroke: var(--accent); }
.doc-body h4 { font-size: 0.88rem; font-weight: 500; color: var(--text); margin-bottom: 0.2rem; }
.doc-body p  { font-size: 0.76rem; color: var(--muted); margin-bottom: 0.3rem; }
.doc-tags { display: flex; gap: 0.4rem; flex-wrap: wrap; }
.compliance-item { padding: 0.85rem 0; border-bottom: 1px solid var(--off); }
.compliance-item:last-child { border-bottom: none; }
.compliance-name { font-size: 0.88rem; font-weight: 500; color: var(--text); margin-bottom: 0.5rem; }
.compliance-stats { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.comp-stat { display: inline-flex; align-items: center; gap: 5px; font-size: 0.76rem; font-weight: 500; padding: 0.2rem 0.6rem; border-radius: 99px; }
.comp-stat svg { width: 11px; height: 11px; stroke: currentColor; }
.comp-stat.compliant    { background: #dcfce7; color: var(--success); }
.comp-stat.non-compliant { background: #fee2e2; color: var(--error); }
.comp-stat.pending-comp { background: #fef3c7; color: var(--warn); }
.workflow-item { display: flex; align-items: flex-start; gap: 12px; padding: 0.9rem 0; border-bottom: 1px solid var(--off); }
.workflow-item:last-child { border-bottom: none; }
.wf-status-dot { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.wf-status-dot.pending  { background: var(--warn-bg); }
.wf-status-dot.pending svg { stroke: var(--warn); }
.wf-status-dot.rejected { background: var(--error-bg); }
.wf-status-dot.rejected svg { stroke: var(--error); }
.wf-status-dot svg { width: 13px; height: 13px; stroke: currentColor; }
.wf-body h4 { font-size: 0.88rem; font-weight: 500; color: var(--text); margin-bottom: 0.15rem; }
.wf-body p  { font-size: 0.76rem; color: var(--muted); }
.wf-time { margin-left: auto; text-align: right; font-size: 0.75rem; color: var(--muted); font-family: 'DM Mono', monospace; white-space: nowrap; }
.content-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem; }

/* PAGINATION */
.pagination { display: flex; align-items: center; gap: 0.4rem; justify-content: center; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border); }
.pagination a, .pagination .current { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 7px; font-size: 0.82rem; font-weight: 500; text-decoration: none; transition: all .2s; border: 1.5px solid var(--border); color: var(--text); background: var(--white); }
.pagination a:hover { border-color: var(--accent); color: var(--accent); }
.pagination .current { background: var(--navy); color: var(--white); border-color: var(--navy); }
.pagination-prev, .pagination-next { width: auto; padding: 0 0.75rem; gap: 5px; }
.pagination-prev svg, .pagination-next svg { width: 13px; height: 13px; stroke: currentColor; }

@media (max-width: 900px) {
    .main { padding: 1.25rem 1rem 2rem; }
    .stats-grid { grid-template-columns: 1fr 1fr; }
    .search-grid { grid-template-columns: 1fr; }
    .form-grid-2 { grid-template-columns: 1fr; }
    .projects-grid { grid-template-columns: 1fr; }
    .content-grid { grid-template-columns: 1fr; }
    .header-inner { flex-wrap: wrap; height: auto; padding: 0.75rem 0; gap: 0.75rem; }
}
</style>
</head>
<body>
<header class="header">
    <div class="header-inner">
        <div class="header-left">
            <a href="dashboard.php" class="brand">
                <div class="brand-mark">
                    <svg viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                </div>
                <div class="brand-text">
                    <h1>BRIGHTPATH</h1>
                    <p>Project Management</p>
                </div>
            </a>
            <a href="dashboard.php" class="btn-back">
                <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
                Dashboard
            </a>
        </div>
        <div class="header-right">
            <div class="profile-wrap" id="profileWrap">
                <div class="user-pill">
                    <div class="user-avatar"><?php echo current_user_initials(); ?></div>
                    <span class="user-name"><?php echo current_user_name(); ?></span>
                    <svg class="pill-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
                <div class="profile-dropdown">
                    <div class="pd-head">
                        <div class="pd-avatar"><?php echo current_user_initials(); ?></div>
                        <div>
                            <div class="pd-info-name"><?php echo current_user_name(); ?></div>
                            <div class="pd-info-email"><?php echo current_user_email(); ?></div>
                        </div>
                    </div>
                    <div class="pd-body">
                        <div class="pd-row">
                            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <span class="pd-row-label">Role</span>
                            <span class="pd-row-val"><span class="pd-role-badge"><?php echo current_user_role(); ?></span></span>
                        </div>
                        <div class="pd-row">
                            <svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 7l10 7 10-7"/></svg>
                            <span class="pd-row-label">Email</span>
                            <span class="pd-row-val" style="font-size:.75rem;word-break:break-all"><?php echo current_user_email(); ?></span>
                        </div>
                        <div class="pd-row">
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <span class="pd-row-label">Session</span>
                            <span class="pd-row-val" style="font-size:.74rem;font-family:'DM Mono',monospace"><?php echo date('M j, g:i A'); ?></span>
                        </div>
                    </div>
                    <div class="pd-foot">
                        <a href="logout.php" class="pd-logout">
                            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                            Sign Out
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>
<main class="main">
<!-- admin: staff-redirect-banner -->
<?php if (isset($_SESSION['flash_message'])): ?>
<div style="display:flex;align-items:center;gap:10px;padding:.85rem 1.1rem;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;margin-bottom:1.5rem;font-size:.87rem;color:#b45309">
    <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;flex-shrink:0"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <?php echo htmlspecialchars($_SESSION['flash_message']); unset($_SESSION['flash_message'],$_SESSION['flash_type']); ?>
</div>
<?php endif; ?>
<div style="display:flex;align-items:center;gap:12px;padding:1rem 1.25rem;background:#eff6ff;border:1px solid #93c5fd;border-radius:10px;margin-bottom:1.5rem">
    <svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:#2563eb;fill:none;stroke-width:2;flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <span style="font-size:.87rem;color:#1d4ed8;flex:1"><strong>Admin View:</strong> This is a read-only monitoring view. To add or modify Projects data, go to the Staff Portal.</span>
    <a href="../staff/projects.php" style="display:inline-flex;align-items:center;gap:6px;padding:.45rem .9rem;background:#2563eb;color:#fff;border-radius:8px;font-size:.82rem;font-weight:600;text-decoration:none">
        <svg viewBox="0 0 24 24" style="width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2"><polyline points="9 18 15 12 9 6"/></svg>Staff Portal
    </a>
</div>


  <div class="page-title"><h1>Project Management</h1><p>Comprehensive project oversight and control system</p></div>

  <?php if ($message): ?>
  <div class="alert alert-success">
    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    <span><strong>Success!</strong> <?php echo htmlspecialchars($message); ?></span>
  </div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="alert alert-error">
    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <span><strong>Error!</strong> <?php echo htmlspecialchars($error); ?></span>
  </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Total Projects</span><div class="stat-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['total_projects']); ?></div>
      <div class="stat-sub">All projects</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Active Projects</span><div class="stat-badge success-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['active_projects']); ?></div>
      <div class="stat-sub good">In progress</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Completed</span><div class="stat-badge success-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['completed_projects']); ?></div>
      <div class="stat-sub good">Successfully finished</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Overdue Projects</span><div class="stat-badge error-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['overdue_projects']); ?></div>
      <div class="stat-sub bad">Need attention</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Total Tasks</span><div class="stat-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['total_tasks']); ?></div>
      <div class="stat-sub">All tasks</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Pending Tasks</span><div class="stat-badge warn-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['pending_tasks']); ?></div>
      <div class="stat-sub warn">In queue</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Overdue Tasks</span><div class="stat-badge error-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div></div>
      <div class="stat-value"><?php echo number_format($stats['overdue_tasks']); ?></div>
      <div class="stat-sub bad">Critical</div>
    </div>
    <div class="stat-card">
      <div class="stat-top"><span class="stat-label">Total Budget</span><div class="stat-badge success-badge"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg></div></div>
      <div class="stat-value" style="font-size:1.35rem">₱<?php echo number_format($stats['total_budget'], 0); ?></div>
      <div class="stat-sub good">All projects</div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="tabs">
    <button class="tab-btn active" onclick="showTab('projects', this)">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      View Projects
    </button>
    <button class="tab-btn" onclick="showTab('add-project', this)">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add Project
    </button>
    <button class="tab-btn" onclick="showTab('add-task', this)">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/></svg>
      Add Task
    </button>
    <button class="tab-btn" onclick="showTab('add-milestone', this)">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
      Add Milestone
    </button>
    <button class="tab-btn" onclick="showTab('add-resource', this)">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
      Add Resource
    </button>
    <button class="tab-btn" onclick="showTab('status-update', this)">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>
      Update Status
    </button>
  </div>

  <!-- Projects Grid -->
  <div id="projects" class="tab-panel active">
    <div class="projects-grid">
      <?php if (empty($projects)): ?>
      <div class="panel" style="grid-column:1/-1">
        <div class="empty-state">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          <h3>No projects found</h3><p>Get started by adding your first project.</p>
        </div>
      </div>
      <?php else: ?>
      <?php foreach ($projects as $p): ?>
      <div class="project-card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:.75rem">
          <div>
            <div class="project-title"><?php echo htmlspecialchars($p['project_name']); ?></div>
            <div class="project-code"><?php echo htmlspecialchars($p['project_code']); ?></div>
          </div>
          <div style="display:flex;gap:.35rem;flex-wrap:wrap;justify-content:flex-end">
            <span class="badge status-<?php echo $p['status']; ?>"><?php echo ucfirst($p['status']); ?></span>
            <span class="badge priority-<?php echo $p['priority']; ?>"><?php echo ucfirst($p['priority']); ?></span>
          </div>
        </div>
        <p class="project-desc"><?php echo htmlspecialchars($p['description']); ?></p>
        <div class="project-stats-row">
          <div class="pstat"><div class="pstat-val"><?php echo $p['total_tasks']; ?></div><div class="pstat-lbl">Tasks</div></div>
          <div class="pstat"><div class="pstat-val"><?php echo $p['completed_tasks']; ?></div><div class="pstat-lbl">Done</div></div>
          <div class="pstat"><div class="pstat-val"><?php echo $p['total_milestones']; ?></div><div class="pstat-lbl">Milestones</div></div>
          <div class="pstat"><div class="pstat-val" style="font-size:.85rem">₱<?php echo number_format($p['budget'], 0); ?></div><div class="pstat-lbl">Budget</div></div>
        </div>
        <div class="progress-bar"><div class="progress-fill" style="width:<?php echo $p['progress_percentage']; ?>%"></div></div>
        <div class="progress-label"><?php echo number_format($p['progress_percentage'], 1); ?>% complete</div>
        <div class="project-meta">
          <div><strong>Client:</strong> <?php echo htmlspecialchars($p['client_name']); ?></div>
          <div><strong>Location:</strong> <?php echo htmlspecialchars($p['location']); ?></div>
          <div><strong>Start:</strong> <?php echo date('M j, Y', strtotime($p['start_date'])); ?></div>
          <div><strong>Due:</strong> <?php echo date('M j, Y', strtotime($p['expected_end_date'])); ?></div>
        </div>
        <div class="project-actions-row">
          <button class="btn btn-primary btn-sm" onclick="openProjectDetails(<?php echo $p['id']; ?>)">View Details</button>
          <button class="btn btn-outline btn-sm" onclick="openTaskModal(<?php echo $p['id']; ?>)">Tasks</button>
          <button class="btn btn-outline btn-sm" onclick="openStatusModal(<?php echo $p['id']; ?>)">Update Status</button>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Add Project Form -->
  <div id="add-project" class="tab-panel">
    <div class="panel">
      <div class="panel-head"><h2><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Add New Project</h2></div>
      <form method="POST">
        <div class="form-grid-2">
          <div class="form-field"><label>Project Code *</label><input type="text" name="project_code" class="form-control" required></div>
          <div class="form-field"><label>Project Name *</label><input type="text" name="project_name" class="form-control" required></div>
          <div class="form-field"><label>Client Name *</label><input type="text" name="client_name" class="form-control" required></div>
          <div class="form-field"><label>Location</label><input type="text" name="location" class="form-control"></div>
          <div class="form-field"><label>Start Date *</label><input type="date" id="start_date" name="start_date" class="form-control" required></div>
          <div class="form-field"><label>Expected End Date *</label><input type="date" id="expected_end_date" name="expected_end_date" class="form-control" required></div>
          <div class="form-field"><label>Budget *</label><input type="number" name="budget" class="form-control" step="0.01" min="0" required></div>
          <div class="form-field"><label>Status *</label><select name="status" class="form-control" required><option value="planning">Planning</option><option value="active">Active</option><option value="on_hold">On Hold</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></div>
          <div class="form-field"><label>Priority *</label><select name="priority" class="form-control" required><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option><option value="critical">Critical</option></select></div>
        </div>
        <div class="form-field"><label>Description</label><textarea name="description" class="form-control" rows="3"></textarea></div>
        <div class="form-field"><label>Notes</label><textarea name="notes" class="form-control" rows="3"></textarea></div>
        <button type="submit" name="add_project" class="btn btn-primary">Add Project</button>
      </form>
    </div>
  </div>

  <!-- Add Task Form -->
  <div id="add-task" class="tab-panel">
    <div class="panel">
      <div class="panel-head"><h2><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/></svg>Add New Task</h2></div>
      <form method="POST">
        <div class="form-grid-2">
          <div class="form-field"><label>Select Project *</label><select name="project_id" class="form-control" required><option value="">Choose a project…</option><?php foreach ($projects as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['project_code'] . ' — ' . $p['project_name']); ?></option><?php endforeach; ?></select></div>
          <div class="form-field"><label>Task Name *</label><input type="text" name="task_name" class="form-control" required></div>
          <div class="form-field"><label>Start Date *</label><input type="date" id="task_start_date" name="task_start_date" class="form-control" required></div>
          <div class="form-field"><label>Due Date *</label><input type="date" id="task_due_date" name="task_due_date" class="form-control" required></div>
          <div class="form-field"><label>Priority *</label><select name="task_priority" class="form-control" required><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option><option value="critical">Critical</option></select></div>
          <div class="form-field"><label>Estimated Hours</label><input type="number" name="estimated_hours" class="form-control" step="0.5" min="0"></div>
        </div>
        <div class="form-field"><label>Description</label><textarea name="task_description" class="form-control" rows="3"></textarea></div>
        <div class="form-field"><label>Notes</label><textarea name="task_notes" class="form-control" rows="3"></textarea></div>
        <button type="submit" name="add_task" class="btn btn-primary">Add Task</button>
      </form>
    </div>
  </div>

  <!-- Add Milestone Form -->
  <div id="add-milestone" class="tab-panel">
    <div class="panel">
      <div class="panel-head"><h2><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>Add Milestone</h2></div>
      <form method="POST">
        <div class="form-grid-2">
          <div class="form-field"><label>Select Project *</label><select name="milestone_project_id" class="form-control" required><option value="">Choose a project…</option><?php foreach ($projects as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['project_code'] . ' — ' . $p['project_name']); ?></option><?php endforeach; ?></select></div>
          <div class="form-field"><label>Milestone Name *</label><input type="text" name="milestone_name" class="form-control" required></div>
          <div class="form-field"><label>Due Date *</label><input type="date" name="milestone_due_date" class="form-control" required></div>
        </div>
        <div class="form-field"><label>Description</label><textarea name="milestone_description" class="form-control" rows="3"></textarea></div>
        <div class="form-field"><label>Deliverables</label><textarea name="deliverables" class="form-control" rows="3"></textarea></div>
        <button type="submit" name="add_milestone" class="btn btn-primary">Add Milestone</button>
      </form>
    </div>
  </div>

  <!-- Add Resource Form -->
  <div id="add-resource" class="tab-panel">
    <div class="panel">
      <div class="panel-head"><h2><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>Add Resource</h2></div>
      <form method="POST">
        <div class="form-grid-2">
          <div class="form-field"><label>Select Project *</label><select name="resource_project_id" class="form-control" required><option value="">Choose a project…</option><?php foreach ($projects as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['project_code'] . ' — ' . $p['project_name']); ?></option><?php endforeach; ?></select></div>
          <div class="form-field"><label>Resource Type *</label><select name="resource_type" class="form-control" required><option value="inventory_item">Inventory Item</option><option value="human">Human Resource</option><option value="equipment">Equipment</option><option value="service">Service</option></select></div>
          <div class="form-field"><label>Resource Name *</label><input type="text" name="resource_name" class="form-control" required></div>
          <div class="form-field"><label>Quantity Required *</label><input type="number" name="quantity_required" class="form-control" step="0.01" min="0" required></div>
          <div class="form-field"><label>Unit Cost *</label><input type="number" name="unit_cost" class="form-control" step="0.01" min="0" required></div>
          <div class="form-field"><label>Allocation Date</label><input type="date" name="allocation_date" class="form-control"></div>
        </div>
        <div class="form-field"><label>Notes</label><textarea name="resource_notes" class="form-control" rows="3"></textarea></div>
        <button type="submit" name="add_resource" class="btn btn-primary">Add Resource</button>
      </form>
    </div>
  </div>

  <!-- Status Update -->
  <div id="status-update" class="tab-panel">
    <div class="panel">
      <div class="panel-head"><h2><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>Update Project Status</h2></div>
      <form method="POST">
        <div class="form-grid-2">
          <div class="form-field"><label>Select Project *</label><select name="status_project_id" class="form-control" required><option value="">Choose a project…</option><?php foreach ($projects as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['project_code'] . ' — ' . $p['project_name']); ?></option><?php endforeach; ?></select></div>
          <div class="form-field"><label>New Status *</label><select name="new_status" class="form-control" required><option value="planning">Planning</option><option value="active">Active</option><option value="on_hold">On Hold</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></div>
          <div class="form-field"><label>Progress % (0–100) *</label><input type="number" name="progress_percentage" class="form-control" min="0" max="100" step="0.1" required></div>
        </div>
        <button type="submit" name="update_status" class="btn btn-primary">Update Project Status</button>
      </form>

      <hr style="margin:1.75rem 0;border:none;height:1px;background:var(--border)">

      <div class="panel-head" style="border:none;padding:0;margin-bottom:1rem"><h2><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/></svg>Update Task Status</h2></div>
      <form method="POST">
        <div class="form-grid-2">
          <div class="form-field"><label>Task ID *</label><input type="number" name="task_id" class="form-control" required placeholder="Enter Task ID"></div>
          <div class="form-field"><label>New Status *</label><select name="task_new_status" class="form-control" required><option value="pending">Pending</option><option value="in_progress">In Progress</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option><option value="overdue">Overdue</option></select></div>
          <div class="form-field"><label>Progress % (0–100)</label><input type="number" name="task_progress_percentage" class="form-control" min="0" max="100" step="0.1"></div>
          <div class="form-field"><label>Actual Hours Worked</label><input type="number" name="actual_hours" class="form-control" step="0.5" min="0"></div>
        </div>
        <button type="submit" name="update_task_status" class="btn btn-primary">Update Task Status</button>
      </form>

      <hr style="margin:1.75rem 0;border:none;height:1px;background:var(--border)">

      <div class="panel-head" style="border:none;padding:0;margin-bottom:1rem"><h2><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>Complete Milestone</h2></div>
      <form method="POST">
        <div class="form-grid-2">
          <div class="form-field"><label>Milestone ID *</label><input type="number" name="milestone_id" class="form-control" required placeholder="Enter Milestone ID"></div>
        </div>
        <div class="form-field"><label>Completion Notes</label><textarea name="completion_notes" class="form-control" rows="3"></textarea></div>
        <button type="submit" name="complete_milestone" class="btn btn-success">Complete Milestone</button>
      </form>
    </div>
  </div>
</main>

<!-- Modals for project details/tasks/status -->
<div id="projectModal" class="modal"><div class="modal-box"><div class="modal-head"><h3>Project Details</h3><button class="modal-close" onclick="closeModal('projectModal')">&times;</button></div><div class="modal-body" id="projectModalContent"></div></div></div>
<div id="taskModal" class="modal"><div class="modal-box"><div class="modal-head"><h3>Project Tasks</h3><button class="modal-close" onclick="closeModal('taskModal')">&times;</button></div><div class="modal-body" id="taskModalContent"></div></div></div>
<div id="statusModal" class="modal"><div class="modal-box"><div class="modal-head"><h3>Quick Status Update</h3><button class="modal-close" onclick="closeModal('statusModal')">&times;</button></div><div class="modal-body" id="statusModalContent"></div></div></div>

<script>
function showTab(name, btn) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById(name).classList.add('active');
    btn.classList.add('active');
}
function openProjectDetails(id) { document.getElementById('projectModal').style.display = 'block'; document.getElementById('projectModalContent').innerHTML = '<p style="color:var(--muted);font-size:.88rem">Loading project ' + id + '…</p>'; }
function openTaskModal(id) { document.getElementById('taskModal').style.display = 'block'; document.getElementById('taskModalContent').innerHTML = '<p style="color:var(--muted);font-size:.88rem">Loading tasks for project ' + id + '…</p>'; }
function openStatusModal(id) {
    document.getElementById('statusModal').style.display = 'block';
    document.getElementById('statusModalContent').innerHTML = `<form method="POST" style="margin-top:.5rem"><input type="hidden" name="status_project_id" value="${id}"><div class="form-field"><label>New Status</label><select name="new_status" class="form-control" required><option value="planning">Planning</option><option value="active">Active</option><option value="on_hold">On Hold</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></div><div class="form-field"><label>Progress %</label><input type="number" name="progress_percentage" class="form-control" min="0" max="100" step="0.1" required></div><div class="modal-footer"><button type="submit" name="update_status" class="btn btn-primary">Update</button></div></form>`;
}
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
window.onclick = e => { if (e.target.classList.contains('modal')) e.target.style.display = 'none'; }
document.getElementById('start_date')?.addEventListener('change', function() { const e = document.getElementById('expected_end_date'); if (e) e.min = this.value; });
document.getElementById('task_start_date')?.addEventListener('change', function() { const e = document.getElementById('task_due_date'); if (e) e.min = this.value; });
setTimeout(() => location.reload(), 300000);
</script>
<script>
(function(){
    var wrap = document.getElementById('profileWrap');
    if(!wrap) return;
    wrap.querySelector('.user-pill').addEventListener('click', function(e){
        e.stopPropagation();
        wrap.classList.toggle('open');
    });
    document.addEventListener('click', function(e){
        if(wrap && !wrap.contains(e.target)) wrap.classList.remove('open');
    });
    document.addEventListener('keydown', function(e){
        if(e.key==='Escape' && wrap) wrap.classList.remove('open');
    });
})();
</script>
</body>
</html>