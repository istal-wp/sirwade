<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_role('admin');
// ═══ ADMIN USER MANAGEMENT — RESTRICTED ═══════════════════════════════════
// Admin can approve/deactivate users. All other write ops moved to staff/.
// ═══════════════════════════════════════════════════════════════════════════


require_once __DIR__ . '/../includes/db.php';
$pdo = db(); // Shared singleton from includes/db.php
try {
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    switch ($_POST['action']) {
        case 'approve_applicant':
            try {
                $stmt = $pdo->prepare("UPDATE users SET application_status = 'approved', status = 'active', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
                $stmt->execute([$_SESSION['user_name'] ?? $_SESSION['email'], $_POST['user_id']]);
                echo json_encode(['success' => true, 'message' => 'Applicant approved successfully']);
            } catch(PDOException $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
            exit();
        case 'reject_applicant':
            try {
                $stmt = $pdo->prepare("UPDATE users SET application_status = 'rejected', status = 'inactive', reviewed_by = ?, reviewed_at = NOW(), rejection_reason = ? WHERE id = ?");
                $stmt->execute([$_SESSION['user_name'] ?? $_SESSION['email'], $_POST['rejection_reason'] ?? '', $_POST['user_id']]);
                echo json_encode(['success' => true, 'message' => 'Applicant rejected']);
            } catch(PDOException $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
            exit();
        case 'add_user':
            try {
                $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, email, phone, role, password, status, application_status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'active', 'approved', NOW())");
                $stmt->execute([$_POST['first_name'], $_POST['last_name'], $_POST['email'], $_POST['phone'], $_POST['role'], $_POST['password']]);
                echo json_encode(['success' => true, 'message' => 'User added successfully']);
            } catch(PDOException $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
            exit();
        case 'edit_user':
            try {
                $sql = "UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?, role = ?, updated_at = NOW()";
                $params = [$_POST['first_name'], $_POST['last_name'], $_POST['email'], $_POST['phone'], $_POST['role']];
                if (!empty($_POST['password'])) { $sql .= ", password = ?"; $params[] = $_POST['password']; }
                $sql .= " WHERE id = ?"; $params[] = $_POST['user_id'];
                $stmt = $pdo->prepare($sql); $stmt->execute($params);
                echo json_encode(['success' => true, 'message' => 'User updated successfully']);
            } catch(PDOException $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
            exit();
        case 'delete_user':
            try {
                $stmt = $pdo->prepare("UPDATE users SET status = 'inactive', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$_POST['user_id']]);
                echo json_encode(['success' => true, 'message' => 'User deactivated successfully']);
            } catch(PDOException $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
            exit();
        case 'activate_user':
            try {
                $stmt = $pdo->prepare("UPDATE users SET status = 'active', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$_POST['user_id']]);
                echo json_encode(['success' => true, 'message' => 'User activated successfully']);
            } catch(PDOException $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
            exit();
        case 'get_user':
            try {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$_POST['user_id']]);
                echo json_encode(['success' => true, 'user' => $stmt->fetch(PDO::FETCH_ASSOC)]);
            } catch(PDOException $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
            exit();
        case 'get_applicant_details':
            try {
                $stmt = $pdo->prepare("SELECT u.*, GROUP_CONCAT(ad.file_name SEPARATOR ', ') as additional_docs FROM users u LEFT JOIN application_documents ad ON u.id = ad.user_id WHERE u.id = ? GROUP BY u.id");
                $stmt->execute([$_POST['user_id']]);
                $applicant = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt2 = $pdo->prepare("SELECT * FROM application_documents WHERE user_id = ?");
                $stmt2->execute([$_POST['user_id']]);
                $documents = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'applicant' => $applicant, 'documents' => $documents]);
            } catch(PDOException $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
            exit();
    }
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;
$search = isset($_GET['search']) ? $_GET['search'] : '';
$role_filter = isset($_GET['role']) ? $_GET['role'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'approved';

$where_conditions = [];
$params = [];
if ($tab === 'pending') $where_conditions[] = "application_status = 'pending'";
elseif ($tab === 'approved') $where_conditions[] = "application_status = 'approved'";
elseif ($tab === 'rejected') $where_conditions[] = "application_status = 'rejected'";
if ($search) {
    $where_conditions[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $sp = "%$search%";
    $params = array_merge($params, [$sp, $sp, $sp, $sp]);
}
if ($role_filter) { $where_conditions[] = "role = ?"; $params[] = $role_filter; }
if ($status_filter) { $where_conditions[] = "status = ?"; $params[] = $status_filter; }
$where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$count_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM users $where_clause");
$count_stmt->execute($params);
$total_users = $count_stmt->fetch()['total'];
$total_pages = ceil($total_users / $per_page);

$stmt = $pdo->prepare("SELECT * FROM users $where_clause ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stats_query = $pdo->query("SELECT COUNT(*) as total_users, SUM(CASE WHEN status='active' AND application_status='approved' THEN 1 ELSE 0 END) as active_users, SUM(CASE WHEN status='inactive' THEN 1 ELSE 0 END) as inactive_users, SUM(CASE WHEN application_status='pending' THEN 1 ELSE 0 END) as pending_applicants, SUM(CASE WHEN application_status='rejected' THEN 1 ELSE 0 END) as rejected_applicants, SUM(CASE WHEN role='admin' THEN 1 ELSE 0 END) as admin_users, SUM(CASE WHEN role='employee' THEN 1 ELSE 0 END) as employee_users, SUM(CASE WHEN role='manager' THEN 1 ELSE 0 END) as manager_users, SUM(CASE WHEN role='staff' THEN 1 ELSE 0 END) as staff_users FROM users");
$stats = $stats_query->fetch(PDO::FETCH_ASSOC);
$roles = ['admin', 'employee', 'manager', 'staff'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management — BRIGHTPATH</title>
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
        }
        body { font-family: 'DM Sans', sans-serif; background: var(--off); min-height: 100vh; color: var(--text); }

        /* HEADER */
        .header { background: var(--white); border-bottom: 1px solid var(--border); padding: 0 2rem; position: sticky; top: 0; z-index: 100; box-shadow: 0 1px 8px rgba(15,31,61,.07); }
        .header-inner { display: flex; justify-content: space-between; align-items: center; max-width: 1400px; margin: 0 auto; height: 64px; }
        .brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .brand-mark { width: 38px; height: 38px; background: linear-gradient(135deg, var(--navy), var(--steel)); border-radius: 9px; display: flex; align-items: center; justify-content: center; }
        .brand-mark svg { width: 20px; height: 20px; stroke: rgba(255,255,255,.9); }
        .brand-text h1 { font-size: 1rem; font-weight: 600; color: var(--navy); letter-spacing: 0.05em; }
        .brand-text p { font-size: 0.7rem; color: var(--muted); letter-spacing: 0.08em; text-transform: uppercase; font-family: 'DM Mono', monospace; }
        .header-right { display: flex; align-items: center; gap: 1.25rem; }
        
        .user-avatar { width: 28px; height: 28px; border-radius: 50%; background: linear-gradient(135deg, var(--navy), var(--steel)); display: flex; align-items: center; justify-content: center; }
        .user-avatar svg { width: 14px; height: 14px; stroke: rgba(255,255,255,.85); }
        .user-name { font-size: 0.83rem; font-weight: 500; color: var(--text); }
        .btn-back { display: flex; align-items: center; gap: 7px; padding: 0.5rem 1rem; background: none; border: 1.5px solid var(--border); border-radius: 8px; font-size: 0.82rem; font-weight: 500; font-family: 'DM Sans', sans-serif; color: var(--muted); cursor: pointer; text-decoration: none; transition: border-color .2s, color .2s; }
        .btn-back svg { width: 14px; height: 14px; stroke: currentColor; }
        .btn-back:hover { border-color: var(--accent); color: var(--accent); }

        /* MAIN */
        .main { max-width: 1400px; margin: 0 auto; padding: 2rem 2rem 3rem; }
        .page-title { margin-bottom: 1.75rem; }
        .page-title h1 { font-size: 1.6rem; font-weight: 600; color: var(--navy); margin-bottom: 0.25rem; }
        .page-title p { font-size: 0.88rem; color: var(--muted); }

        /* ALERTS */
        .alert { padding: 0.85rem 1.1rem; border-radius: 10px; margin-bottom: 1rem; font-size: 0.87rem; display: none; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: var(--success); }
        .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: var(--error); }

        /* TABS */
        .tabs { display: flex; gap: 0.5rem; margin-bottom: 1.75rem; border-bottom: 1px solid var(--border); padding-bottom: 0; }
        .tab-link { display: flex; align-items: center; gap: 7px; padding: 0.7rem 1.1rem; font-size: 0.87rem; font-weight: 500; color: var(--muted); text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px; transition: color .2s, border-color .2s; white-space: nowrap; }
        .tab-link:hover { color: var(--accent); }
        .tab-link.active { color: var(--accent); border-bottom-color: var(--accent); font-weight: 600; }
        .tab-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 20px; height: 20px; padding: 0 5px; border-radius: 99px; background: rgba(61,127,255,.12); color: var(--accent); font-size: 0.72rem; font-weight: 600; font-family: 'DM Mono', monospace; }
        .tab-badge.warn { background: rgba(180,83,9,.1); color: var(--warn); }

        /* STATS */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.75rem; }
        .stat-card { background: var(--white); border: 1px solid var(--border); border-radius: 12px; padding: 1.25rem 1.4rem; transition: box-shadow .2s, transform .15s; }
        .stat-card:hover { box-shadow: 0 4px 18px rgba(15,31,61,.08); transform: translateY(-2px); }
        .stat-label { font-size: 0.78rem; font-weight: 500; color: var(--muted); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 0.5rem; }
        .stat-value { font-size: 2rem; font-weight: 600; color: var(--navy); line-height: 1; }

        /* CONTROLS */
        .control-bar { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
        .search-row { display: flex; gap: 0.65rem; flex-wrap: wrap; flex: 1; }
        .search-input, .filter-select {
            padding: 0.55rem 0.9rem; border: 1.5px solid var(--border); border-radius: 9px;
            font-size: 0.84rem; font-family: 'DM Sans', sans-serif; color: var(--text);
            background: var(--white); outline: none; transition: border-color .2s;
        }
        .search-input { min-width: 220px; }
        .search-input:focus, .filter-select:focus { border-color: var(--accent); }
        .btn-add { display: flex; align-items: center; gap: 7px; padding: 0.55rem 1.1rem; background: linear-gradient(135deg, var(--navy), var(--steel)); color: var(--white); border: none; border-radius: 9px; font-size: 0.84rem; font-weight: 600; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: box-shadow .2s, transform .15s; white-space: nowrap; }
        .btn-add:hover { box-shadow: 0 4px 14px rgba(15,31,61,.25); transform: translateY(-1px); }

        /* TABLE */
        .table-wrap { background: var(--white); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        thead th { padding: 0.85rem 1.1rem; text-align: left; font-size: 0.75rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.07em; background: var(--off); border-bottom: 1px solid var(--border); white-space: nowrap; }
        tbody td { padding: 0.95rem 1.1rem; border-bottom: 1px solid var(--off); font-size: 0.87rem; vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: rgba(61,127,255,.03); }

        .user-cell { display: flex; align-items: center; gap: 10px; }
        .avatar { width: 36px; height: 36px; border-radius: 9px; background: linear-gradient(135deg, var(--navy), var(--steel)); display: flex; align-items: center; justify-content: center; font-size: 0.78rem; font-weight: 600; color: white; font-family: 'DM Mono', monospace; flex-shrink: 0; }
        .user-name-text { font-weight: 500; color: var(--text); font-size: 0.88rem; }
        .user-email { font-size: 0.77rem; color: var(--muted); margin-top: 1px; }

        .badge { display: inline-block; padding: 0.25rem 0.65rem; border-radius: 99px; font-size: 0.75rem; font-weight: 600; }
        .badge-role { background: rgba(61,127,255,.1); color: var(--accent); }
        .badge-active { background: rgba(21,128,61,.1); color: var(--success); }
        .badge-inactive { background: rgba(108,117,125,.1); color: #6c757d; }
        .badge-pending { background: rgba(180,83,9,.1); color: var(--warn); }
        .badge-rejected { background: rgba(197,48,48,.1); color: var(--error); }

        .action-row { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .btn-sm { padding: 0.3rem 0.7rem; border-radius: 7px; font-size: 0.78rem; font-weight: 500; font-family: 'DM Sans', sans-serif; cursor: pointer; border: 1.5px solid transparent; transition: all .15s; }
        .btn-edit { background: rgba(61,127,255,.08); color: var(--accent); border-color: rgba(61,127,255,.2); }
        .btn-edit:hover { background: var(--accent); color: white; border-color: var(--accent); }
        .btn-deactivate { background: rgba(180,83,9,.08); color: var(--warn); border-color: rgba(180,83,9,.2); }
        .btn-deactivate:hover { background: var(--warn); color: white; border-color: var(--warn); }
        .btn-activate { background: rgba(21,128,61,.08); color: var(--success); border-color: rgba(21,128,61,.2); }
        .btn-activate:hover { background: var(--success); color: white; }
        .btn-approve { background: rgba(21,128,61,.08); color: var(--success); border-color: rgba(21,128,61,.2); }
        .btn-approve:hover { background: var(--success); color: white; }
        .btn-reject { background: rgba(197,48,48,.08); color: var(--error); border-color: rgba(197,48,48,.2); }
        .btn-reject:hover { background: var(--error); color: white; }
        .btn-view { background: rgba(15,31,61,.06); color: var(--text); border-color: var(--border); }
        .btn-view:hover { background: var(--navy); color: white; border-color: var(--navy); }

        /* PAGINATION */
        .pagination { display: flex; align-items: center; gap: 0.4rem; padding: 1.1rem 1.4rem; border-top: 1px solid var(--border); flex-wrap: wrap; }
        .pagination a, .pagination .pg-current { display: inline-flex; align-items: center; justify-content: center; min-width: 32px; height: 32px; padding: 0 8px; border-radius: 7px; font-size: 0.82rem; text-decoration: none; transition: all .15s; }
        .pagination a { color: var(--text); border: 1.5px solid var(--border); background: var(--white); }
        .pagination a:hover { border-color: var(--accent); color: var(--accent); }
        .pagination .pg-current { background: var(--navy); color: white; font-weight: 600; border: none; }
        .pagination .pg-info { font-size: 0.78rem; color: var(--muted); margin-left: auto; font-family: 'DM Mono', monospace; }

        /* MODAL */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(15,31,61,.45); z-index: 999; align-items: center; justify-content: center; }
        .modal-overlay.open { display: flex; }
        .modal-box { background: var(--white); border-radius: 16px; width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(15,31,61,.25); }
        .modal-head { display: flex; justify-content: space-between; align-items: center; padding: 1.4rem 1.6rem 1.1rem; border-bottom: 1px solid var(--border); }
        .modal-head h2 { font-size: 1.05rem; font-weight: 600; color: var(--navy); }
        .modal-close { width: 30px; height: 30px; border: none; background: var(--off); border-radius: 7px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1rem; color: var(--muted); transition: background .15s; }
        .modal-close:hover { background: #e9ecf5; }
        .modal-body { padding: 1.4rem 1.6rem; }
        .modal-foot { padding: 1rem 1.6rem; border-top: 1px solid var(--border); display: flex; gap: 0.75rem; justify-content: flex-end; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; font-size: 0.8rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.4rem; }
        .form-group input, .form-group select { width: 100%; padding: 0.6rem 0.85rem; border: 1.5px solid var(--border); border-radius: 9px; font-size: 0.87rem; font-family: 'DM Sans', sans-serif; color: var(--text); outline: none; transition: border-color .2s; background: var(--white); }
        .form-group input:focus, .form-group select:focus { border-color: var(--accent); }

        .btn-primary { padding: 0.6rem 1.3rem; background: linear-gradient(135deg, var(--navy), var(--steel)); color: white; border: none; border-radius: 9px; font-size: 0.87rem; font-weight: 600; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: box-shadow .2s; }
        .btn-primary:hover { box-shadow: 0 4px 14px rgba(15,31,61,.25); }
        .btn-secondary { padding: 0.6rem 1.3rem; background: var(--off); color: var(--muted); border: 1.5px solid var(--border); border-radius: 9px; font-size: 0.87rem; font-weight: 500; font-family: 'DM Sans', sans-serif; cursor: pointer; }
        .btn-danger { padding: 0.6rem 1.3rem; background: var(--error); color: white; border: none; border-radius: 9px; font-size: 0.87rem; font-weight: 600; font-family: 'DM Sans', sans-serif; cursor: pointer; }

        .empty-state { text-align: center; padding: 3rem; color: var(--muted); font-size: 0.88rem; }
        .doc-resume { display: inline-flex; align-items: center; gap: 5px; color: var(--success); font-size: 0.82rem; font-weight: 500; }
        .doc-none { color: var(--muted); font-size: 0.82rem; font-style: italic; }

        @media (max-width: 768px) {
            .main { padding: 1.25rem 1rem 2rem; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .form-row { grid-template-columns: 1fr; }
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
                    <p>User Management</p>
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
    <div class="page-title">
        <h1>User Management</h1>
        <p>Manage user accounts, pending applicants, roles, and permissions</p>
    </div>

    <div id="alert-success" class="alert alert-success"></div>
    <div id="alert-error" class="alert alert-error"></div>

    <!-- Tabs -->
    <div class="tabs">
        <a href="?tab=pending" class="tab-link <?php echo $tab==='pending'?'active':''; ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Pending Applicants
            <?php if ($stats['pending_applicants'] > 0): ?><span class="tab-badge warn"><?php echo $stats['pending_applicants']; ?></span><?php endif; ?>
        </a>
        <a href="?tab=approved" class="tab-link <?php echo $tab==='approved'?'active':''; ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            Approved Users
            <span class="tab-badge"><?php echo $stats['active_users']; ?></span>
        </a>
        <a href="?tab=rejected" class="tab-link <?php echo $tab==='rejected'?'active':''; ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            Rejected
            <?php if ($stats['rejected_applicants'] > 0): ?><span class="tab-badge"><?php echo $stats['rejected_applicants']; ?></span><?php endif; ?>
        </a>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-label">Pending</div><div class="stat-value"><?php echo number_format($stats['pending_applicants']); ?></div></div>
        <div class="stat-card"><div class="stat-label">Active Users</div><div class="stat-value"><?php echo number_format($stats['active_users']); ?></div></div>
        <div class="stat-card"><div class="stat-label">Managers</div><div class="stat-value"><?php echo number_format($stats['manager_users']); ?></div></div>
        <div class="stat-card"><div class="stat-label">Staff</div><div class="stat-value"><?php echo number_format($stats['staff_users']); ?></div></div>
    </div>

    <!-- Controls -->
    <div class="control-bar">
        <div class="search-row">
            <input type="text" id="search-input" class="search-input" placeholder="Search users..." value="<?php echo htmlspecialchars($search); ?>">
            <select id="role-filter" class="filter-select">
                <option value="">All Roles</option>
                <?php foreach ($roles as $role): ?>
                <option value="<?php echo $role; ?>" <?php echo $role_filter===$role?'selected':''; ?>><?php echo ucfirst($role); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($tab !== 'pending'): ?>
            <select id="status-filter" class="filter-select">
                <option value="">All Status</option>
                <option value="active" <?php echo $status_filter==='active'?'selected':''; ?>>Active</option>
                <option value="inactive" <?php echo $status_filter==='inactive'?'selected':''; ?>>Inactive</option>
            </select>
            <?php endif; ?>
        </div>
        <?php if ($tab === 'approved'): ?>
        <button class="btn-add" onclick="openAddModal()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add User
        </button>
        <?php endif; ?>
    </div>

    <!-- Table -->
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>User</th>
                    <th>Phone</th>
                    <th>Role</th>
                    <?php if ($tab === 'pending'): ?>
                    <th>Applied</th>
                    <th>Documents</th>
                    <?php else: ?>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Last Login</th>
                    <?php endif; ?>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                <tr><td colspan="7"><div class="empty-state">No users found matching your criteria.</div></td></tr>
                <?php else: ?>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td>
                        <div class="user-cell">
                            <div class="avatar"><?php echo strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1)); ?></div>
                            <div>
                                <div class="user-name-text"><?php echo htmlspecialchars($user['first_name'].' '.$user['last_name']); ?></div>
                                <div class="user-email"><?php echo htmlspecialchars($user['email']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></td>
                    <td><span class="badge badge-role"><?php echo ucfirst(htmlspecialchars($user['role'])); ?></span></td>
                    <?php if ($tab === 'pending'): ?>
                    <td><?php echo isset($user['application_date']) && $user['application_date'] ? date('M j, Y', strtotime($user['application_date'])) : date('M j, Y', strtotime($user['created_at'])); ?></td>
                    <td>
                        <?php if (!empty($user['resume_filename'])): ?>
                        <span class="doc-resume"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Resume uploaded</span>
                        <?php else: ?><span class="doc-none">No documents</span><?php endif; ?>
                    </td>
                    <?php else: ?>
                    <td>
                        <span class="badge badge-<?php echo $user['status']; ?>"><?php echo ucfirst($user['status']); ?></span>
                        <?php if ($user['application_status'] === 'rejected'): ?><span class="badge badge-rejected" style="margin-left:4px;">Rejected</span><?php endif; ?>
                    </td>
                    <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                    <td><?php echo $user['last_login'] ? date('M j, Y', strtotime($user['last_login'])) : '<span class="doc-none">Never</span>'; ?></td>
                    <?php endif; ?>
                    <td>
                        <div class="action-row">
                            <?php if ($tab === 'pending'): ?>
                            <button class="btn-sm btn-view" onclick="viewApplicant(<?php echo $user['id']; ?>)">View</button>
                            <button class="btn-sm btn-approve" onclick="approveApplicant(<?php echo $user['id']; ?>)">Approve</button>
                            <button class="btn-sm btn-reject" onclick="rejectApplicant(<?php echo $user['id']; ?>)">Reject</button>
                            <?php elseif ($tab === 'rejected'): ?>
                            <button class="btn-sm btn-view" onclick="viewApplicant(<?php echo $user['id']; ?>)">View Details</button>
                            <?php else: ?>
                            <button class="btn-sm btn-edit" onclick="editUser(<?php echo $user['id']; ?>)">Edit</button>
                            <?php if ($user['status'] === 'active'): ?>
                            <button class="btn-sm btn-deactivate" onclick="deactivateUser(<?php echo $user['id']; ?>)">Deactivate</button>
                            <?php else: ?>
                            <button class="btn-sm btn-activate" onclick="activateUser(<?php echo $user['id']; ?>)">Activate</button>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?><a href="?page=<?php echo $page-1; ?>&tab=<?php echo $tab; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role_filter); ?>&status=<?php echo urlencode($status_filter); ?>">← Prev</a><?php endif; ?>
            <?php for ($i = max(1,$page-2); $i <= min($total_pages,$page+2); $i++): ?>
                <?php if ($i===$page): ?><span class="pg-current"><?php echo $i; ?></span>
                <?php else: ?><a href="?page=<?php echo $i; ?>&tab=<?php echo $tab; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role_filter); ?>&status=<?php echo urlencode($status_filter); ?>"><?php echo $i; ?></a><?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?><a href="?page=<?php echo $page+1; ?>&tab=<?php echo $tab; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role_filter); ?>&status=<?php echo urlencode($status_filter); ?>">Next →</a><?php endif; ?>
            <span class="pg-info">Page <?php echo $page; ?> of <?php echo $total_pages; ?> &nbsp;·&nbsp; <?php echo $total_users; ?> users</span>
        </div>
        <?php endif; ?>
    </div>
</main>

<!-- Add/Edit User Modal -->
<div id="userModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-head">
            <h2 id="modal-title">Add New User</h2>
            <button class="modal-close" onclick="closeModal('userModal')">✕</button>
        </div>
        <div class="modal-body">
            <form id="userForm">
                <input type="hidden" id="user_id" name="user_id">
                <input type="hidden" id="action" name="action" value="add_user">
                <div class="form-row">
                    <div class="form-group"><label>First Name</label><input type="text" id="first_name" name="first_name" required></div>
                    <div class="form-group"><label>Last Name</label><input type="text" id="last_name" name="last_name" required></div>
                </div>
                <div class="form-group"><label>Email Address</label><input type="email" id="email" name="email" required></div>
                <div class="form-row">
                    <div class="form-group"><label>Phone</label><input type="text" id="phone" name="phone"></div>
                    <div class="form-group"><label>Role</label>
                        <select id="role" name="role">
                            <?php foreach ($roles as $role): ?><option value="<?php echo $role; ?>"><?php echo ucfirst($role); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group"><label>Password <span id="pw-hint" style="font-weight:400;text-transform:none;">(leave blank to keep current)</span></label><input type="password" id="password" name="password"></div>
            </form>
        </div>
        <div class="modal-foot">
            <button class="btn-secondary" onclick="closeModal('userModal')">Cancel</button>
            <button class="btn-primary" onclick="submitUserForm()">Save User</button>
        </div>
    </div>
</div>

<!-- Applicant Detail Modal -->
<div id="applicantModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-head">
            <h2>Applicant Details</h2>
            <button class="modal-close" onclick="closeModal('applicantModal')">✕</button>
        </div>
        <div class="modal-body" id="applicant-details-content"><p style="color:var(--muted)">Loading…</p></div>
        <div class="modal-foot">
            <button class="btn-secondary" onclick="closeModal('applicantModal')">Close</button>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal-overlay">
    <div class="modal-box" style="max-width:420px">
        <div class="modal-head">
            <h2>Reject Applicant</h2>
            <button class="modal-close" onclick="closeModal('rejectModal')">✕</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="reject_user_id">
            <div class="form-group"><label>Rejection Reason</label><input type="text" id="rejection_reason" name="rejection_reason" placeholder="Optional reason..."></div>
        </div>
        <div class="modal-foot">
            <button class="btn-secondary" onclick="closeModal('rejectModal')">Cancel</button>
            <button class="btn-danger" onclick="submitReject()">Confirm Reject</button>
        </div>
    </div>
</div>

<script>
function showAlert(type, msg) {
    const el = document.getElementById('alert-' + type);
    el.textContent = msg; el.style.display = 'block';
    setTimeout(() => el.style.display = 'none', 4000);
}
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function openAddModal() {
    document.getElementById('modal-title').textContent = 'Add New User';
    document.getElementById('action').value = 'add_user';
    document.getElementById('userForm').reset();
    document.getElementById('pw-hint').style.display = 'none';
    openModal('userModal');
}
function editUser(id) {
    fetch('', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=get_user&user_id='+id})
    .then(r => r.json()).then(data => {
        if (data.success) {
            const u = data.user;
            document.getElementById('modal-title').textContent = 'Edit User';
            document.getElementById('action').value = 'edit_user';
            document.getElementById('user_id').value = u.id;
            document.getElementById('first_name').value = u.first_name;
            document.getElementById('last_name').value = u.last_name;
            document.getElementById('email').value = u.email;
            document.getElementById('phone').value = u.phone || '';
            document.getElementById('role').value = u.role;
            document.getElementById('pw-hint').style.display = '';
            openModal('userModal');
        }
    });
}
function submitUserForm() {
    const fd = new FormData(document.getElementById('userForm'));
    const body = new URLSearchParams(fd).toString();
    fetch('', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body})
    .then(r => r.json()).then(data => {
        closeModal('userModal');
        if (data.success) { showAlert('success', data.message); setTimeout(() => location.reload(), 1200); }
        else showAlert('error', data.message);
    });
}
function approveApplicant(id) {
    if (!confirm('Approve this applicant?')) return;
    fetch('', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=approve_applicant&user_id='+id})
    .then(r => r.json()).then(data => {
        if (data.success) { showAlert('success', data.message); setTimeout(() => location.reload(), 1200); }
        else showAlert('error', data.message);
    });
}
function rejectApplicant(id) {
    document.getElementById('reject_user_id').value = id;
    document.getElementById('rejection_reason').value = '';
    openModal('rejectModal');
}
function submitReject() {
    const id = document.getElementById('reject_user_id').value;
    const reason = document.getElementById('rejection_reason').value;
    fetch('', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=reject_applicant&user_id='+id+'&rejection_reason='+encodeURIComponent(reason)})
    .then(r => r.json()).then(data => {
        closeModal('rejectModal');
        if (data.success) { showAlert('success', data.message); setTimeout(() => location.reload(), 1200); }
        else showAlert('error', data.message);
    });
}
function deactivateUser(id) {
    if (!confirm('Deactivate this user?')) return;
    fetch('', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=delete_user&user_id='+id})
    .then(r => r.json()).then(data => {
        if (data.success) { showAlert('success', data.message); setTimeout(() => location.reload(), 1200); }
        else showAlert('error', data.message);
    });
}
function activateUser(id) {
    fetch('', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=activate_user&user_id='+id})
    .then(r => r.json()).then(data => {
        if (data.success) { showAlert('success', data.message); setTimeout(() => location.reload(), 1200); }
        else showAlert('error', data.message);
    });
}
function viewApplicant(id) {
    document.getElementById('applicant-details-content').innerHTML = '<p style="color:var(--muted)">Loading…</p>';
    openModal('applicantModal');
    fetch('', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=get_applicant_details&user_id='+id})
    .then(r => r.json()).then(data => {
        if (data.success) {
            const a = data.applicant;
            let html = `<div style="display:grid;gap:.75rem">
                <div><strong>${a.first_name} ${a.last_name}</strong> <span class="badge badge-role">${a.role}</span></div>
                <div style="font-size:.85rem;color:var(--muted)">${a.email} &nbsp;·&nbsp; ${a.phone||'No phone'}</div>
                <div style="font-size:.83rem;color:var(--muted)">Applied: ${a.application_date||a.created_at}</div>`;
            if (a.resume_filename) html += `<div><a href="download_resume.php?file=${encodeURIComponent(a.resume_path)}" style="color:var(--accent);font-size:.85rem;">⬇ Download Resume: ${a.resume_filename}</a></div>`;
            if (a.rejection_reason) html += `<div style="background:#fef2f2;padding:.75rem;border-radius:8px;font-size:.83rem;color:var(--error)"><strong>Rejection reason:</strong> ${a.rejection_reason}</div>`;
            html += '</div>';
            document.getElementById('applicant-details-content').innerHTML = html;
        }
    });
}

// Search/filter with URL update
let searchTimer;
document.getElementById('search-input')?.addEventListener('input', function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => updateFilters(), 400);
});
document.getElementById('role-filter')?.addEventListener('change', updateFilters);
document.getElementById('status-filter')?.addEventListener('change', updateFilters);
function updateFilters() {
    const params = new URLSearchParams(window.location.search);
    const s = document.getElementById('search-input')?.value || '';
    const r = document.getElementById('role-filter')?.value || '';
    const st = document.getElementById('status-filter')?.value || '';
    s ? params.set('search', s) : params.delete('search');
    r ? params.set('role', r) : params.delete('role');
    st ? params.set('status', st) : params.delete('status');
    params.delete('page');
    window.location.search = params.toString();
}

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('open'); });
});
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