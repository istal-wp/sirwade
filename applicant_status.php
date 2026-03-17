<?php
session_start();

$servername   = getenv('MYSQLHOST')     ?: getenv('DB_HOST')     ?: 'localhost';
$db_port      = getenv('MYSQLPORT')     ?: getenv('DB_PORT')     ?: '3306';
$db_username  = getenv('MYSQLUSER')     ?: getenv('DB_USER')     ?: 'root';
$db_password  = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS')     ?: '';
$dbname       = getenv('MYSQLDATABASE') ?: getenv('DB_NAME')     ?: 'loogistics';

$applicant = null;
$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);

    if (empty($email)) {
        $error = "Please enter your email address.";
    } else {
        try {
                $pdo = new PDO("mysql:host=$servername;port=$db_port;dbname=$dbname;charset=utf8mb4", $db_username, $db_password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND application_status IN ('pending', 'approved', 'rejected')");
            $stmt->execute([$email]);
            $applicant = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$applicant) {
                $error = "No application found with this email address.";
            }

        } catch(PDOException $e) {
            error_log("Status check error: " . $e->getMessage());
            $error = "Database error. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Status — BRIGHTPATH</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --navy:   #0f1f3d;
            --blue:   #1a3a6e;
            --accent: #3d7fff;
            --steel:  #2c4a8a;
            --white:  #ffffff;
            --off:    #f4f6fb;
            --border: #dde3ef;
            --text:   #1a2540;
            --muted:  #6b7a99;
            --success:#15803d;
            --warn:   #b45309;
            --error:  #c53030;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--navy);
            min-height: 100vh;
            display: flex;
            overflow: hidden;
        }

        /* ── LEFT PANEL ─────────────────────────────── */
        .left-panel {
            flex: 1;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-start;
            padding: 4rem 5rem;
            overflow: hidden;
        }

        .left-panel::before {
            content: '';
            position: absolute; inset: 0;
            background-image:
                linear-gradient(rgba(61,127,255,.06) 1px, transparent 1px),
                linear-gradient(90deg, rgba(61,127,255,.06) 1px, transparent 1px);
            background-size: 48px 48px;
            pointer-events: none;
        }

        .glow {
            position: absolute;
            width: 520px; height: 520px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(61,127,255,.18) 0%, transparent 70%);
            top: -80px; left: -120px;
            pointer-events: none;
        }

        .brand { position: relative; margin-bottom: 3.5rem; }

        .brand-logo { display: flex; align-items: center; gap: 14px; margin-bottom: 0.5rem; }
        .brand-logo svg { width: 44px; height: 44px; flex-shrink: 0; }
        .brand-name { font-size: 1.6rem; font-weight: 600; color: var(--white); letter-spacing: 0.06em; }
        .brand-tag { font-family: 'DM Mono', monospace; font-size: 0.7rem; color: rgba(255,255,255,.45); letter-spacing: 0.18em; text-transform: uppercase; margin-left: 58px; }

        .hero-text { position: relative; max-width: 480px; }
        .hero-text h1 { font-size: clamp(2rem, 3.2vw, 2.8rem); font-weight: 300; color: var(--white); line-height: 1.25; margin-bottom: 1.2rem; }
        .hero-text h1 strong { font-weight: 600; color: #7eb3ff; }
        .hero-text p { font-size: 0.95rem; color: rgba(255,255,255,.6); line-height: 1.7; margin-bottom: 2.5rem; }

        .step-list { display: flex; flex-direction: column; gap: 1.1rem; }
        .step-item { display: flex; align-items: flex-start; gap: 14px; }
        .step-num { width: 28px; height: 28px; background: rgba(61,127,255,.2); border: 1px solid rgba(61,127,255,.35); border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-family: 'DM Mono', monospace; font-size: 0.72rem; color: #7eb3ff; font-weight: 600; margin-top: 1px; }
        .step-item span { font-size: 0.88rem; color: rgba(255,255,255,.7); line-height: 1.5; }
        .step-item strong { color: rgba(255,255,255,.9); }

        /* ── RIGHT PANEL ─────────────────────────────── */
        .right-panel {
            width: 460px;
            background: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem 2.75rem;
            box-shadow: -8px 0 40px rgba(0,0,0,.25);
            overflow-y: auto;
        }

        .form-box { width: 100%; max-width: 380px; }

        .form-head { margin-bottom: 2rem; }
        .form-head h2 { font-size: 1.65rem; font-weight: 600; color: var(--text); margin-bottom: 0.3rem; }
        .form-head p  { font-size: 0.88rem; color: var(--muted); }

        .field { margin-bottom: 1.15rem; }
        .field label { display: block; font-size: 0.82rem; font-weight: 500; color: var(--text); margin-bottom: 0.45rem; }

        .input-wrap { position: relative; }
        .input-wrap svg { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; stroke: var(--muted); pointer-events: none; }
        .input-wrap input { width: 100%; padding: 0.72rem 0.9rem 0.72rem 2.6rem; border: 1.5px solid var(--border); border-radius: 9px; font-size: 0.9rem; font-family: 'DM Sans', sans-serif; color: var(--text); background: var(--off); transition: border-color .2s, box-shadow .2s, background .2s; }
        .input-wrap input:focus { outline: none; border-color: var(--accent); background: var(--white); box-shadow: 0 0 0 3px rgba(61,127,255,.12); }
        .input-wrap input::placeholder { color: #b0bacf; }

        .btn-primary { width: 100%; padding: 0.82rem; background: linear-gradient(135deg, var(--navy) 0%, var(--blue) 100%); border: none; border-radius: 9px; color: var(--white); font-size: 0.92rem; font-weight: 600; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: opacity .2s, transform .15s, box-shadow .2s; letter-spacing: 0.02em; }
        .btn-primary:hover { opacity: .9; transform: translateY(-1px); box-shadow: 0 6px 18px rgba(15,31,61,.3); }

        /* STATUS CARD */
        .status-card { background: var(--off); border: 1px solid var(--border); border-radius: 14px; padding: 1.5rem; margin-top: 1.5rem; }

        .status-person { display: flex; align-items: center; gap: 12px; margin-bottom: 1.25rem; padding-bottom: 1.1rem; border-bottom: 1px solid var(--border); }
        .status-avatar { width: 44px; height: 44px; border-radius: 50%; background: linear-gradient(135deg, var(--navy), var(--steel)); display: flex; align-items: center; justify-content: center; font-size: 0.85rem; font-weight: 600; color: white; font-family: 'DM Mono', monospace; flex-shrink: 0; }
        .status-person-info h3 { font-size: 0.97rem; font-weight: 600; color: var(--text); margin-bottom: 0.15rem; }
        .status-person-info p { font-size: 0.8rem; color: var(--muted); }

        .detail-row { display: flex; justify-content: space-between; align-items: center; padding: 0.65rem 0; border-bottom: 1px solid rgba(221,227,239,.7); font-size: 0.85rem; }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { color: var(--muted); font-weight: 500; }
        .detail-value { color: var(--text); font-weight: 500; text-align: right; }

        .status-badge { display: inline-flex; align-items: center; gap: 5px; padding: 0.25rem 0.7rem; border-radius: 99px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
        .badge-pending  { background: #fef3c7; color: #92400e; }
        .badge-approved { background: #dcfce7; color: #166534; }
        .badge-rejected { background: #fee2e2; color: #991b1b; }

        /* ALERTS */
        .alert { display: flex; align-items: flex-start; gap: 10px; padding: 0.85rem 1rem; border-radius: 9px; font-size: 0.84rem; line-height: 1.5; margin-top: 1rem; }
        .alert svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; }
        .alert-error   { background: #fff5f5; border: 1px solid #fed7d7; color: var(--error); }
        .alert-warn    { background: #fffbeb; border: 1px solid #fde68a; color: var(--warn); }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: var(--success); }
        .alert-info    { background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8; }

        .btn-row { display: flex; gap: 0.65rem; margin-top: 1.25rem; }
        .btn-outline { flex: 1; padding: 0.7rem; border: 1.5px solid var(--border); border-radius: 9px; background: var(--white); font-size: 0.85rem; font-weight: 500; font-family: 'DM Sans', sans-serif; color: var(--muted); cursor: pointer; text-decoration: none; text-align: center; transition: border-color .2s, color .2s; }
        .btn-outline:hover { border-color: var(--accent); color: var(--accent); }
        .btn-green { flex: 1; padding: 0.7rem; border: none; border-radius: 9px; background: linear-gradient(135deg, #16a34a, #15803d); font-size: 0.85rem; font-weight: 600; font-family: 'DM Sans', sans-serif; color: white; cursor: pointer; text-decoration: none; text-align: center; transition: opacity .2s, transform .15s; }
        .btn-green:hover { opacity: .9; transform: translateY(-1px); }

        .form-footer { text-align: center; font-size: 0.83rem; color: var(--muted); margin-top: 1.5rem; line-height: 1.9; }
        .form-footer a { color: var(--blue); font-weight: 600; text-decoration: none; }
        .form-footer a:hover { color: var(--accent); }

        @media (max-width: 900px) {
            body { flex-direction: column; overflow: auto; }
            .left-panel { padding: 2.5rem 2rem; flex: none; min-height: 35vh; }
            .right-panel { width: 100%; padding: 2rem 1.5rem; }
        }
    </style>
</head>
<body>

<!-- ═══ LEFT PANEL ═══════════════════════════════════════════════════ -->
<div class="left-panel">
    <div class="glow"></div>

    <div class="brand">
        <div class="brand-logo">
            <svg viewBox="0 0 44 44" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect width="44" height="44" rx="10" fill="rgba(61,127,255,.15)" stroke="rgba(61,127,255,.35)" stroke-width="1"/>
                <path d="M7 16h18v13H7z" stroke="#7eb3ff" stroke-width="1.6" stroke-linejoin="round" fill="none"/>
                <path d="M25 20h5.5L34 24v5h-9V20z" stroke="#7eb3ff" stroke-width="1.6" stroke-linejoin="round" fill="none"/>
                <circle cx="12" cy="31" r="2.2" stroke="#7eb3ff" stroke-width="1.6" fill="none"/>
                <circle cx="30" cy="31" r="2.2" stroke="#7eb3ff" stroke-width="1.6" fill="none"/>
                <path d="M10 22h6M10 25h4" stroke="#7eb3ff" stroke-width="1.4" stroke-linecap="round"/>
            </svg>
            <span class="brand-name">BRIGHTPATH</span>
        </div>
        <div class="brand-tag">Microfinance &amp; Logistics</div>
    </div>

    <div class="hero-text">
        <h1>Track your<br><strong>application status</strong></h1>
        <p>Enter your registered email to check the current status of your staff application. Our admin team reviews all applications promptly.</p>

        <div class="step-list">
            <div class="step-item">
                <div class="step-num">1</div>
                <span><strong>Submit Application</strong> — Fill in your details and upload your resume</span>
            </div>
            <div class="step-item">
                <div class="step-num">2</div>
                <span><strong>Admin Review</strong> — Our team evaluates your application</span>
            </div>
            <div class="step-item">
                <div class="step-num">3</div>
                <span><strong>Get Notified</strong> — Receive your approval and access credentials</span>
            </div>
        </div>
    </div>
</div>

<!-- ═══ RIGHT PANEL ══════════════════════════════════════════════════ -->
<div class="right-panel">
    <div class="form-box">

        <?php if (!$applicant): ?>
        <div class="form-head">
            <h2>Check Status</h2>
            <p>Enter your email to view your application</p>
        </div>

        <form action="applicant_status.php" method="POST">
            <div class="field">
                <label for="email">Email Address</label>
                <div class="input-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 7l10 7 10-7"/>
                    </svg>
                    <input type="email" id="email" name="email" placeholder="you@company.com" required
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
            </div>

            <button type="submit" class="btn-primary">Check My Application</button>
        </form>

        <?php if (!empty($error)): ?>
        <div class="alert alert-error">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" stroke="currentColor">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>

        <?php else: ?>

        <div class="form-head">
            <h2>Application Found</h2>
            <p>Here is the status of your application</p>
        </div>

        <div class="status-card">
            <div class="status-person">
                <div class="status-avatar"><?php echo strtoupper(substr($applicant['first_name'],0,1).substr($applicant['last_name'],0,1)); ?></div>
                <div class="status-person-info">
                    <h3><?php echo htmlspecialchars($applicant['first_name'].' '.$applicant['last_name']); ?></h3>
                    <p><?php echo htmlspecialchars($applicant['email']); ?></p>
                </div>
            </div>

            <div class="detail-row">
                <span class="detail-label">Status</span>
                <span class="detail-value">
                    <span class="status-badge badge-<?php echo $applicant['application_status']; ?>">
                        <?php echo ucfirst($applicant['application_status']); ?>
                    </span>
                </span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Applied On</span>
                <span class="detail-value"><?php echo date('M j, Y', strtotime($applicant['application_date'] ?? $applicant['created_at'])); ?></span>
            </div>

            <?php if ($applicant['application_status'] !== 'pending' && !empty($applicant['reviewed_by'])): ?>
            <div class="detail-row">
                <span class="detail-label">Reviewed By</span>
                <span class="detail-value"><?php echo htmlspecialchars($applicant['reviewed_by']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Reviewed On</span>
                <span class="detail-value"><?php echo $applicant['reviewed_at'] ? date('M j, Y', strtotime($applicant['reviewed_at'])) : 'N/A'; ?></span>
            </div>
            <?php endif; ?>

            <?php if (!empty($applicant['rejection_reason'])): ?>
            <div class="detail-row" style="flex-direction:column;align-items:flex-start;gap:.3rem;">
                <span class="detail-label">Reason</span>
                <span class="detail-value" style="text-align:left;font-size:.82rem;color:var(--muted)"><?php echo htmlspecialchars($applicant['rejection_reason']); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($applicant['application_status'] === 'pending'): ?>
        <div class="alert alert-info">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" stroke="currentColor">
                <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
            </svg>
            <span>Your application is under review. Our team will process it soon.</span>
        </div>

        <?php elseif ($applicant['application_status'] === 'approved'): ?>
        <div class="alert alert-success">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" stroke="currentColor">
                <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
            <span>Congratulations! Your application has been approved. You can now sign in.</span>
        </div>
        <div class="btn-row">
            <a href="login.php" class="btn-green">Sign In Now →</a>
        </div>

        <?php elseif ($applicant['application_status'] === 'rejected'): ?>
        <div class="alert alert-error">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" stroke="currentColor">
                <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
            </svg>
            <span>Your application was not approved at this time. You may apply again with updated credentials.</span>
        </div>
        <div class="btn-row">
            <a href="signup.php" class="btn-green">Apply Again</a>
        </div>
        <?php endif; ?>

        <div class="btn-row" style="margin-top:.75rem">
            <a href="applicant_status.php" class="btn-outline">Check Another</a>
        </div>

        <?php endif; ?>

        <div class="form-footer">
            <a href="login.php">← Back to Sign In</a> &nbsp;·&nbsp;
            <a href="signup.php">New Application</a>
        </div>
    </div>
</div>

</body>
</html>
