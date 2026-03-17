<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_role'] !== 'staff') {
    header("Location: ../index.php");
    exit();
}

$user_name = $_SESSION['user_name'];
$user_email = $_SESSION['user_email'];

$document_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($document_id <= 0) {
    header("Location: dtlrs.php?error=invalid_document_id");
    exit();
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "loogistics";

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->prepare("
        SELECT d.*, 
               dt.type_name, dt.retention_period_days, dt.required_fields,
               p.project_code, p.project_name,
               po.po_number,
               s.supplier_code, s.supplier_name
        FROM documents d 
        LEFT JOIN document_types dt ON d.document_type_id = dt.id
        LEFT JOIN projects p ON d.related_project_id = p.id
        LEFT JOIN purchase_orders po ON d.related_po_id = po.id
        LEFT JOIN suppliers s ON d.related_supplier_id = s.id
        WHERE d.id = ?
    ");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$document) {
        header("Location: dtlrs.php?error=document_not_found");
        exit();
    }
    
    if (in_array($document['status'], ['archived', 'expired']) && $_SESSION['user_role'] !== 'admin') {
        $access_denied = true;
        $access_reason = "This document is " . $document['status'] . " and no longer accessible.";
    } else {
        $access_denied = false;
        
        $stmt = $pdo->prepare("INSERT INTO document_access_logs (document_id, accessed_by, access_type, access_details, ip_address) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $document_id,
            $user_name,
            'view',
            'Document viewed via web interface',
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    }
    
    $stmt = $pdo->prepare("
        SELECT accessed_by, access_type, access_details, accessed_at, ip_address 
        FROM document_access_logs 
        WHERE document_id = ? 
        ORDER BY accessed_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$document_id]);
    $access_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $related_documents = [];
    if ($document['related_project_id']) {
        $stmt = $pdo->prepare("
            SELECT d.id, d.document_code, d.title, dt.type_name 
            FROM documents d 
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            WHERE d.related_project_id = ? AND d.id != ? AND d.status = 'active'
            LIMIT 5
        ");
        $stmt->execute([$document['related_project_id'], $document_id]);
        $related_documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch(PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
    $document = null;
}

function getFileIcon($file_type) {
    if (strpos($file_type, 'pdf') !== false) return '📄';
    if (strpos($file_type, 'word') !== false || strpos($file_type, 'document') !== false) return '📝';
    if (strpos($file_type, 'excel') !== false || strpos($file_type, 'spreadsheet') !== false) return '📊';
    if (strpos($file_type, 'image') !== false) return '🖼️';
    if (strpos($file_type, 'video') !== false) return '🎥';
    return '📎';
}

function formatFileSize($bytes) {
    if ($bytes >= 1048576) {
        $bytes = number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        $bytes = number_format($bytes / 1024, 2) . ' KB';
    } else {
        $bytes = $bytes . ' bytes';
    }
    return $bytes;
}

$file_accessible = false;
$file_path = '';
if ($document && $document['file_path']) {
    $file_path = $document['file_path'];
    $file_accessible = file_exists($file_path) && is_readable($file_path);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $document ? htmlspecialchars($document['title']) . ' - ' : ''; ?>Document Viewer - BrightPath Microfinance</title>
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
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            line-height: 1.6;
        }

        .header {
            background: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 1rem 2rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        

        

        .document-title {
            font-size: 20px;
            font-weight: 700;
            color: #1a1a2e;
        }

        .document-code {
            font-size: 14px;
            color: #666;
            font-family: 'Courier New', monospace;
            background: #f0f0f0;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            margin-left: 1rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 14px;
            color: #666;
        }

        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }

        .document-viewer {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .viewer-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 1.5rem 2rem;
        }

        .viewer-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .viewer-meta {
            display: flex;
            gap: 2rem;
            font-size: 14px;
            opacity: 0.9;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active { background: rgba(40, 167, 69, 0.2); color: #28a745; }
        .status-draft { background: rgba(108, 117, 125, 0.2); color: #6c757d; }
        .status-pending { background: rgba(255, 193, 7, 0.2); color: #856404; }
        .status-archived { background: rgba(23, 162, 184, 0.2); color: #17a2b8; }
        .status-expired { background: rgba(220, 53, 69, 0.2); color: #dc3545; }

        .viewer-content {
            padding: 2rem;
        }

        .access-denied {
            text-align: center;
            padding: 4rem 2rem;
            color: #dc3545;
        }

        .access-denied-icon {
            font-size: 64px;
            margin-bottom: 1rem;
        }

        .access-denied-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .file-preview {
            border: 2px dashed #e0e0e0;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            margin-bottom: 2rem;
        }

        .file-icon {
            font-size: 48px;
            margin-bottom: 1rem;
        }

        .file-info {
            margin-bottom: 1rem;
        }

        .file-name {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .file-details {
            font-size: 14px;
            color: #666;
            margin-bottom: 1rem;
        }

        .download-btn {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            padding: 0.8rem 2rem;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .download-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(40, 167, 69, 0.3);
        }

        .document-details {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            height: fit-content;
        }

        .details-section {
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 16px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .detail-grid {
            display: grid;
            gap: 1rem;
        }

        .detail-item {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 0.5rem;
            padding: 0.8rem;
            background: #f8f9fa;
            border-radius: 6px;
            border-left: 3px solid #1e3c72;
        }

        .detail-label {
            font-weight: 600;
            color: #1a1a2e;
            font-size: 14px;
        }

        .detail-value {
            color: #666;
            font-size: 14px;
        }

        .tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .tag {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 500;
        }

        .related-documents {
            list-style: none;
        }

        .related-document {
            padding: 0.8rem;
            background: #f8f9fa;
            border-radius: 6px;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
        }

        .related-document:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }

        .related-document a {
            text-decoration: none;
            color: #1e3c72;
            font-weight: 500;
            font-size: 14px;
        }

        .access-log {
            max-height: 300px;
            overflow-y: auto;
        }

        .log-entry {
            padding: 0.8rem;
            border-bottom: 1px solid #e9ecef;
            font-size: 13px;
        }

        .log-entry:last-child {
            border-bottom: none;
        }

        .log-user {
            font-weight: 600;
            color: #1e3c72;
        }

        .log-action {
            color: #666;
            margin: 0.2rem 0;
        }

        .log-time {
            font-size: 12px;
            color: #999;
        }

        .expiry-warning {
            background: linear-gradient(135deg, #ff9a56 0%, #ff6b6b 100%);
            color: white;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            text-align: center;
        }

        .warning-icon {
            font-size: 24px;
            margin-bottom: 0.5rem;
        }

        .compliance-status {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .compliance-good {
            background: rgba(40, 167, 69, 0.1);
            border-left: 4px solid #28a745;
            color: #155724;
        }

        .compliance-warning {
            background: rgba(255, 193, 7, 0.1);
            border-left: 4px solid #ffc107;
            color: #856404;
        }

        .iframe-container {
            width: 100%;
            height: 600px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .iframe-container iframe {
            width: 100%;
            height: 100%;
            border: none;
        }

        @media (max-width: 1024px) {
            .main-container {
                grid-template-columns: 1fr;
                padding: 1rem;
            }
            
            .header {
                padding: 1rem;
            }
            
            .viewer-meta {
                flex-direction: column;
                gap: 0.5rem;
            }
        }

        @media print {
            .header, .document-details, 
            
            .main-container {
                grid-template-columns: 1fr;
                padding: 0;
            }
            
            body {
                background: white;
            }
            
            .document-viewer {
                box-shadow: none;
                border: 1px solid #ccc;
            }
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
                    <p>View Document</p>
                </div>
            </a>
            <a href="dtlrs.php" class="btn-back">
                <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
                Dashboard
            </a>
        </div>
        <div class="header-right">
            <div class="profile-wrap" id="profileWrap">
                <div class="user-pill">
                    <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name']??'U',0,1)); ?></div>
                    <span class="user-name"><?php echo htmlspecialchars($_SESSION['user_name']??'User'); ?></span>
                    <svg class="pill-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
                <div class="profile-dropdown">
                    <div class="pd-head">
                        <div class="pd-avatar"><?php echo strtoupper(substr($_SESSION['user_name']??'U',0,1)); ?></div>
                        <div>
                            <div class="pd-info-name"><?php echo htmlspecialchars($_SESSION['user_name']??''); ?></div>
                            <div class="pd-info-email"><?php echo htmlspecialchars($_SESSION['user_email']??''); ?></div>
                        </div>
                    </div>
                    <div class="pd-body">
                        <div class="pd-row">
                            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <span class="pd-row-label">Role</span>
                            <span class="pd-row-val"><span class="pd-role-badge staff"><?php echo ucfirst($_SESSION['user_role']??'user'); ?></span></span>
                        </div>
                        <div class="pd-row">
                            <svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 7l10 7 10-7"/></svg>
                            <span class="pd-row-label">Email</span>
                            <span class="pd-row-val" style="font-size:.75rem;word-break:break-all"><?php echo htmlspecialchars($_SESSION['user_email']??'—'); ?></span>
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
<div class="header">
        <div class="header-content">
            <div class="header-left">
                <a href="dtlrs.php" class="back-btn" title="Back to Document Management">←</a>
                <?php if ($document): ?>
                <div>
                    <div class="document-title"><?php echo htmlspecialchars($document['title']); ?></div>
                    <span class="document-code"><?php echo htmlspecialchars($document['document_code']); ?></span>
                </div>
                <?php else: ?>
                <div class="document-title">Document Not Found</div>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <span>Viewed by: <strong><?php echo htmlspecialchars($user_name); ?></strong></span>
                <span><?php echo date('M d, Y H:i'); ?></span>
            </div>
        </div>
    </div>

    <div class="main-container">
        <div class="document-viewer">
            <?php if (!$document): ?>
            <div class="access-denied">
                <div class="access-denied-icon">❌</div>
                <div class="access-denied-title">Document Not Found</div>
                <p>The requested document could not be found or you don't have permission to access it.</p>
            </div>
            
            <?php elseif ($access_denied): ?>
            <div class="access-denied">
                <div class="access-denied-icon">🔒</div>
                <div class="access-denied-title">Access Denied</div>
                <p><?php echo htmlspecialchars($access_reason); ?></p>
            </div>
            
            <?php else: ?>
            <div class="viewer-header">
                <div class="viewer-title"><?php echo htmlspecialchars($document['title']); ?></div>
                <div class="viewer-meta">
                    <div class="meta-item">
                        <span>📁</span>
                        <span><?php echo htmlspecialchars($document['type_name'] ?? 'Unknown Type'); ?></span>
                    </div>
                    <div class="meta-item">
                        <span>👤</span>
                        <span><?php echo htmlspecialchars($document['created_by']); ?></span>
                    </div>
                    <div class="meta-item">
                        <span>📅</span>
                        <span><?php echo date('M d, Y', strtotime($document['created_at'])); ?></span>
                    </div>
                    <div class="meta-item">
                        <span class="status-badge status-<?php echo $document['status']; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $document['status'])); ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="viewer-content">
                <?php if ($document['expiry_date'] && strtotime($document['expiry_date']) <= strtotime('+30 days')): ?>
                <div class="expiry-warning">
                    <div class="warning-icon">⚠️</div>
                    <strong>Document Expiring Soon</strong><br>
                    This document will expire on <?php echo date('M d, Y', strtotime($document['expiry_date'])); ?>
                </div>
                <?php endif; ?>

                <?php 
                $compliance_class = 'compliance-good';
                $compliance_message = 'Document is in compliance with all requirements.';
                if ($document['status'] === 'expired') {
                    $compliance_class = 'compliance-warning';
                    $compliance_message = 'Document has expired and may require renewal.';
                } elseif ($document['status'] === 'pending_approval') {
                    $compliance_class = 'compliance-warning';
                    $compliance_message = 'Document is pending approval and not yet active.';
                }
                ?>
                <div class="compliance-status <?php echo $compliance_class; ?>">
                    <strong>Compliance Status:</strong> <?php echo $compliance_message; ?>
                </div>

                <?php if ($file_accessible): ?>
                <div class="file-preview">
                    <div class="file-icon"><?php echo getFileIcon($document['file_type']); ?></div>
                    <div class="file-info">
                        <div class="file-name"><?php echo htmlspecialchars($document['file_name']); ?></div>
                        <div class="file-details">
                            <?php echo htmlspecialchars($document['file_type']); ?> • 
                            <?php echo formatFileSize($document['file_size']); ?> • 
                            Uploaded <?php echo date('M d, Y', strtotime($document['created_at'])); ?>
                        </div>
                    </div>
                    
                    <?php if (in_array(strtolower(pathinfo($document['file_name'], PATHINFO_EXTENSION)), ['pdf'])): ?>
                    <div class="iframe-container">
                        <iframe src="<?php echo htmlspecialchars($file_path); ?>#toolbar=1&navpanes=1&scrollbar=1" 
                                type="application/pdf">
                            <p>Your browser does not support PDFs. <a href="<?php echo htmlspecialchars($file_path); ?>">Download the PDF</a>.</p>
                        </iframe>
                    </div>
                    <?php endif; ?>
                    
                    <a href="download_document.php?id=<?php echo $document['id']; ?>" class="download-btn">
                        📥 Download Document
                    </a>
                </div>
                
                <?php elseif ($document['file_path']): ?>
                <div class="file-preview">
                    <div class="file-icon">❌</div>
                    <div class="file-info">
                        <div class="file-name">File Not Available</div>
                        <div class="file-details">The document file could not be accessed.</div>
                    </div>
                </div>
                
                <?php else: ?>
                <div class="file-preview">
                    <div class="file-icon">📄</div>
                    <div class="file-info">
                        <div class="file-name">No File Attached</div>
                        <div class="file-details">This document record does not have an associated file.</div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($document['description']): ?>
                <div style="background: #f8f9fa; padding: 1.5rem; border-radius: 8px; margin-bottom: 2rem;">
                    <h3 style="margin-bottom: 1rem; color: #1a1a2e;">Description</h3>
                    <p style="line-height: 1.6; color: #666;"><?php echo nl2br(htmlspecialchars($document['description'])); ?></p>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($document && !$access_denied): ?>
        <div class="document-details">
            <div class="details-section">
                <h3 class="section-title">📋 Document Information</h3>
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="detail-label">Document Code</div>
                        <div class="detail-value"><?php echo htmlspecialchars($document['document_code']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Type</div>
                        <div class="detail-value"><?php echo htmlspecialchars($document['type_name'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Priority</div>
                        <div class="detail-value"><?php echo ucfirst($document['priority']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Status</div>
                        <div class="detail-value">
                            <span class="status-badge status-<?php echo $document['status']; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $document['status'])); ?>
                            </span>
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Created By</div>
                        <div class="detail-value"><?php echo htmlspecialchars($document['created_by']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Created Date</div>
                        <div class="detail-value"><?php echo date('M d, Y H:i', strtotime($document['created_at'])); ?></div>
                    </div>
                    <?php if ($document['expiry_date']): ?>
                    <div class="detail-item">
                        <div class="detail-label">Expiry Date</div>
                        <div class="detail-value"><?php echo date('M d, Y', strtotime($document['expiry_date'])); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($document['approved_by']): ?>
                    <div class="detail-item">
                        <div class="detail-label">Approved By</div>
                        <div class="detail-value"><?php echo htmlspecialchars($document['approved_by']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Approved Date</div>
                        <div class="detail-value"><?php echo date('M d, Y H:i', strtotime($document['approved_at'])); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($document['tags']): ?>
                <div style="margin-top: 1rem;">
                    <div class="detail-label" style="margin-bottom: 0.5rem;">Tags</div>
                    <div class="tags">
                        <?php 
                        $tags = explode(',', $document['tags']);
                        foreach ($tags as $tag): 
                            $tag = trim($tag);
                            if ($tag):
                        ?>
                        <span class="tag"><?php echo htmlspecialchars($tag); ?></span>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($document['project_code'] || $document['po_number'] || $document['supplier_name']): ?>
            <div class="details-section">
                <h3 class="section-title">🔗 Related Records</h3>
                <div class="detail-grid">
                    <?php if ($document['project_code']): ?>
                    <div class="detail-item">
                        <div class="detail-label">Project</div>
                        <div class="detail-value">
                            <?php echo htmlspecialchars($document['project_code'] . ' - ' . $document['project_name']); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($document['po_number']): ?>
                    <div class="detail-item">
                        <div class="detail-label">Purchase Order</div>
                        <div class="detail-value"><?php echo htmlspecialchars($document['po_number']); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($document['supplier_name']): ?>
                    <div class="detail-item">
                        <div class="detail-label">Supplier</div>
                        <div class="detail-value">
                            <?php echo htmlspecialchars($document['supplier_code'] . ' - ' . $document['supplier_name']); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($related_documents)): ?>
            <div class="details-section">
                <h3 class="section-title">📎 Related Documents</h3>
                <ul class="related-documents">
                    <?php foreach ($related_documents as $related): ?>
                    <li class="related-document">
                        <a href="view_document.php?id=<?php echo $related['id']; ?>">
                            <?php echo htmlspecialchars($related['document_code'] . ' - ' . $related['title']); ?>
                            <br><small><?php echo htmlspecialchars($related['type_name']); ?></small>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="details-section">
                <h3 class="section-title">👁️ Access History</h3>
                <div class="access-log">
                    <?php if (!empty($access_history)): ?>
                        <?php foreach ($access_history as $log): ?>
                        <div class="log-entry">
                            <div class="log-user"><?php echo htmlspecialchars($log['accessed_by']); ?></div>
                            <div class="log-action">
                                <?php 
                                echo ucfirst($log['access_type']) . ': ' . htmlspecialchars($log['access_details']); 
                                ?>
                            </div>
                            <div class="log-time">
                                <?php echo date('M d, Y H:i', strtotime($log['accessed_at'])); ?> 
                                <?php if ($log['ip_address'] && $log['ip_address'] !== 'unknown'): ?>
                                    (<?php echo htmlspecialchars($log['ip_address']); ?>)
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="log-entry" style="text-align: center; color: #666;">
                            No access history available
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="details-section">
                <h3 class="section-title">⚙️ Actions</h3>
                <div style="display: grid; gap: 0.5rem;">
                    <button onclick="window.print()" 
                            style="background: linear-gradient(135deg, #6c757d 0%, #495057 100%); color: white; border: none; padding: 0.8rem; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 0.3s ease;">
                        🖨️ Print Document
                    </button>
                    
                    <?php if ($file_accessible): ?>
                    <a href="download_document.php?id=<?php echo $document['id']; ?>" 
                       style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; border: none; padding: 0.8rem; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 0.3s ease; text-decoration: none; text-align: center; display: block;">
                        📥 Download File
                    </a>
                    <?php endif; ?>
                    
                    <button onclick="shareDocument()" 
                            style="background: linear-gradient(135deg, #17a2b8 0%, #138496 100%); color: white; border: none; padding: 0.8rem; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 0.3s ease;">
                        🔗 Share Link
                    </button>
                    
                    <a href="dtlrs.php?tab=documents&highlight=<?php echo $document['id']; ?>" 
                       style="background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); color: white; border: none; padding: 0.8rem; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 0.3s ease; text-decoration: none; text-align: center; display: block;">
                        ✏️ Edit Document
                    </a>
                </div>
            </div>

            <div class="details-section">
                <h3 class="section-title">📊 Metadata</h3>
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="detail-label">Document ID</div>
                        <div class="detail-value">#<?php echo $document['id']; ?></div>
                    </div>
                    <?php if ($document['retention_period_days']): ?>
                    <div class="detail-item">
                        <div class="detail-label">Retention Period</div>
                        <div class="detail-value"><?php echo $document['retention_period_days']; ?> days</div>
                    </div>
                    <?php endif; ?>
                    <div class="detail-item">
                        <div class="detail-label">Last Modified</div>
                        <div class="detail-value"><?php echo date('M d, Y H:i', strtotime($document['updated_at'] ?? $document['created_at'])); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Access Count</div>
                        <div class="detail-value"><?php echo count($access_history); ?> views</div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div id="shareModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); backdrop-filter: blur(5px);">
        <div style="background-color: white; margin: 10% auto; padding: 2rem; border-radius: 12px; width: 90%; max-width: 500px; text-align: center;">
            <h3 style="margin-bottom: 1rem; color: #1a1a2e;">Share Document</h3>
            <p style="margin-bottom: 1rem; color: #666;">Share this document link with authorized personnel:</p>
            <div style="background: #f8f9fa; padding: 1rem; border-radius: 8px; margin: 1rem 0; word-break: break-all; font-family: monospace; font-size: 14px;">
                <?php echo 'http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']; ?>
            </div>
            <div style="display: flex; gap: 1rem; justify-content: center; margin-top: 2rem;">
                <button onclick="copyToClipboard()" 
                        style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; border: none; padding: 0.8rem 1.5rem; border-radius: 8px; cursor: pointer; font-weight: 600;">
                    📋 Copy Link
                </button>
                <button onclick="closeShareModal()" 
                        style="background: #6c757d; color: white; border: none; padding: 0.8rem 1.5rem; border-radius: 8px; cursor: pointer; font-weight: 600;">
                    Close
                </button>
            </div>
        </div>
    </div>

    <script>
        window.addEventListener('beforeprint', function() {
            const details = document.querySelector('.document-details');
            if (details) {
                details.style.display = 'none';
            }
        });

        window.addEventListener('afterprint', function() {
            const details = document.querySelector('.document-details');
            if (details) {
                details.style.display = 'block';
            }
        });

        function shareDocument() {
            document.getElementById('shareModal').style.display = 'block';
        }

        function closeShareModal() {
            document.getElementById('shareModal').style.display = 'none';
        }

        function copyToClipboard() {
            const url = window.location.href;
            navigator.clipboard.writeText(url).then(function() {
                const button = event.target;
                const originalText = button.innerHTML;
                button.innerHTML = '✅ Copied!';
                button.style.background = 'linear-gradient(135deg, #28a745 0%, #20c997 100%)';
                
                setTimeout(function() {
                    button.innerHTML = originalText;
                    button.style.background = 'linear-gradient(135deg, #28a745 0%, #20c997 100%)';
                }, 2000);
            }, function(err) {
                console.error('Could not copy text: ', err);
                const textArea = document.createElement('textarea');
                textArea.value = url;
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                try {
                    document.execCommand('copy');
                    const button = event.target;
                    const originalText = button.innerHTML;
                    button.innerHTML = '✅ Copied!';
                    setTimeout(function() {
                        button.innerHTML = originalText;
                    }, 2000);
                } catch (err) {
                    console.error('Fallback: Oops, unable to copy', err);
                }
                document.body.removeChild(textArea);
            });
        }

        function refreshAccessLog() {
            if (document.visibilityState === 'visible' && !document.querySelector('.access-denied')) {
                fetch('get_access_log.php?id=<?php echo $document_id; ?>')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const logContainer = document.querySelector('.access-log');
                            if (logContainer && data.logs.length > 0) {
                                let logHtml = '';
                                data.logs.forEach(log => {
                                    logHtml += `
                                        <div class="log-entry">
                                            <div class="log-user">${log.accessed_by}</div>
                                            <div class="log-action">${log.access_type}: ${log.access_details}</div>
                                            <div class="log-time">${log.accessed_at} ${log.ip_address ? '(' + log.ip_address + ')' : ''}</div>
                                        </div>
                                    `;
                                });
                                logContainer.innerHTML = logHtml;
                            }
                        }
                    })
                    .catch(error => {
                        console.warn('Failed to refresh access log:', error);
                    });
            }
        }

        setInterval(refreshAccessLog, 30000);

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeShareModal();
            }
        });

        document.getElementById('shareModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeShareModal();
            }
        });

        let previousActiveElement;

        function shareDocument() {
            previousActiveElement = document.activeElement;
            document.getElementById('shareModal').style.display = 'block';
            setTimeout(() => {
                document.querySelector('#shareModal button').focus();
            }, 100);
        }

        function closeShareModal() {
            document.getElementById('shareModal').style.display = 'none';
            if (previousActiveElement) {
                previousActiveElement.focus();
            }
        }

        if (document.querySelector('iframe')) {
            const iframe = document.querySelector('iframe');
            const container = iframe.parentElement;
            
            const loadingDiv = document.createElement('div');
            loadingDiv.innerHTML = `
                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; color: #666;">
                    <div style="width: 40px; height: 40px; border: 4px solid #f3f3f3; border-top: 4px solid #1e3c72; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 1rem;"></div>
                    <div>Loading document...</div>
                </div>
            `;
            loadingDiv.style.position = 'absolute';
            loadingDiv.style.top = '0';
            loadingDiv.style.left = '0';
            loadingDiv.style.right = '0';
            loadingDiv.style.bottom = '0';
            loadingDiv.style.background = 'rgba(248, 249, 250, 0.9)';
            loadingDiv.style.borderRadius = '8px';
            loadingDiv.id = 'loadingIndicator';
            
            container.style.position = 'relative';
            container.appendChild(loadingDiv);
            
            iframe.addEventListener('load', function() {
                const loading = document.getElementById('loadingIndicator');
                if (loading) {
                    loading.remove();
                }
            });
        }

        const spinStyle = document.createElement('style');
        spinStyle.textContent = `
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        `;
        document.head.appendChild(spinStyle);

        <?php if ($document && $document['priority'] === 'critical'): ?>
        document.addEventListener('contextmenu', function(e) {
            if (e.target.closest('.document-viewer')) {
                e.preventDefault();
                return false;
            }
        });

        document.addEventListener('selectstart', function(e) {
            if (e.target.closest('.document-viewer')) {
                e.preventDefault();
                return false;
            }
        });
        <?php endif; ?>
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