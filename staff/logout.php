<?php
/**
 * staff/logout.php
 */
require_once '../includes/auth.php';
$goodbye = $_SESSION['user_name'] ?? 'Staff';
$_SESSION = [];
if (isset($_COOKIE[session_name()])) setcookie(session_name(), '', time()-3600, '/');
session_destroy();
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Logged Out — BRIGHTPATH</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Mono:wght@400&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:#f4f6fb;min-height:100vh;display:flex;align-items:center;justify-content:center}
.card{background:#fff;border:1px solid #dde3ef;border-radius:16px;padding:2.5rem 2rem;max-width:360px;width:100%;text-align:center;box-shadow:0 4px 24px rgba(15,31,61,.08)}
.icon{width:56px;height:56px;background:linear-gradient(135deg,#0f1f3d,#2c4a8a);border-radius:14px;display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem}
.icon svg{width:26px;height:26px;stroke:rgba(255,255,255,.9);fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
h1{font-size:1.3rem;font-weight:600;color:#0f1f3d;margin-bottom:.5rem}
p{font-size:.88rem;color:#6b7a99;margin-bottom:1.5rem}
.btn{display:inline-flex;align-items:center;gap:7px;padding:.65rem 1.4rem;background:#0f1f3d;color:#fff;border-radius:9px;font-size:.88rem;font-weight:500;text-decoration:none;font-family:'DM Sans',sans-serif}
.btn:hover{background:#2c4a8a}
</style>
<meta http-equiv="refresh" content="4;url=../login.php">
</head>
<body>
<div class="card">
    <div class="icon"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></div>
    <h1>Goodbye, <?php echo htmlspecialchars($goodbye); ?>!</h1>
    <p>You have been securely signed out. Redirecting to login…</p>
    <a href="../login.php" class="btn">
        <svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        Sign In Again
    </a>
</div>
</body>
</html>
