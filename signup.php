<?php
session_start();

$servername   = getenv('MYSQLHOST')     ?: getenv('DB_HOST')     ?: 'localhost';
$db_port      = getenv('MYSQLPORT')     ?: getenv('DB_PORT')     ?: '3306';
$db_username  = getenv('MYSQLUSER')     ?: getenv('DB_USER')     ?: 'root';
$db_password  = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS')     ?: '';
$dbname       = getenv('MYSQLDATABASE') ?: getenv('DB_NAME')     ?: 'loogistics';

$success = false;
$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    if (empty($first_name) || empty($last_name) || empty($email) || empty($password) || empty($confirm_password)) {
        $error = "empty";
    } elseif ($password !== $confirm_password) {
        $error = "password_mismatch";
    } elseif (strlen($password) < 8) {
        $error = "password_short";
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $error = "password_weak";
    } elseif (!preg_match('/[a-z]/', $password)) {
        $error = "password_weak";
    } elseif (!preg_match('/[0-9]/', $password)) {
        $error = "password_weak";
    } elseif (!preg_match('/[\W_]/', $password)) {
        $error = "password_weak";
    } else {
        try {
                $pdo = new PDO("mysql:host=$servername;port=$db_port;dbname=$dbname;charset=utf8mb4", $db_username, $db_password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $checkStmt->execute([$email]);
            
            if ($checkStmt->fetch()) {
                $error = "email_exists";
            } else {
                $resume_path = null;
                $resume_filename = null;
                
                if (isset($_FILES['resume']) && $_FILES['resume']['error'] == 0) {
                    $allowed_types = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                    $max_size = 5 * 1024 * 1024;
                    
                    if (!in_array($_FILES['resume']['type'], $allowed_types)) {
                        $error = "invalid_file_type";
                    } elseif ($_FILES['resume']['size'] > $max_size) {
                        $error = "file_too_large";
                    } else {
                        $upload_dir = 'uploads/resumes/';
                        if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                        
                        $file_extension = pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION);
                        $resume_filename = $_FILES['resume']['name'];
                        $unique_filename = uniqid() . '_' . time() . '.' . $file_extension;
                        $resume_path = $upload_dir . $unique_filename;
                        
                        if (!move_uploaded_file($_FILES['resume']['tmp_name'], $resume_path)) {
                            $error = "upload_failed";
                        }
                    }
                }
                
                if (empty($error)) {
                    $insertStmt = $pdo->prepare("INSERT INTO users (first_name, last_name, email, phone, password, role, status, application_status, resume_path, resume_filename, application_date, created_at) VALUES (?, ?, ?, ?, ?, 'staff', 'inactive', 'pending', ?, ?, NOW(), NOW())");
                    $insertStmt->execute([$first_name, $last_name, $email, $phone, $password, $resume_path, $resume_filename]);
                    $success = true;
                }
            }
            
        } catch(PDOException $e) {
            error_log("Signup error: " . $e->getMessage());
            $error = "database";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loogistics — Staff Application</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --navy:   #0f1f3d;
            --blue:   #1a3a6e;
            --accent: #3d7fff;
            --white:  #ffffff;
            --off:    #f4f6fb;
            --border: #dde3ef;
            --text:   #1a2540;
            --muted:  #6b7a99;
            --error:  #c53030;
            --success:#15803d;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--navy);
            min-height: 100vh;
            display: flex;
            overflow: hidden;
        }

        /* ── LEFT ─────────────────────────────── */
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
        .brand-tag {
            font-family: 'DM Mono', monospace;
            font-size: 0.7rem; color: rgba(255,255,255,.45);
            letter-spacing: 0.18em; text-transform: uppercase; margin-left: 58px;
        }

        .hero-text { position: relative; max-width: 480px; }
        .hero-text h1 { font-size: clamp(1.8rem, 3vw, 2.6rem); font-weight: 300; color: var(--white); line-height: 1.3; margin-bottom: 1rem; }
        .hero-text h1 strong { font-weight: 600; color: #7eb3ff; }
        .hero-text p { font-size: 0.95rem; color: rgba(255,255,255,.6); line-height: 1.7; margin-bottom: 2.5rem; }

        .steps { display: flex; flex-direction: column; gap: 1rem; }
        .step { display: flex; align-items: flex-start; gap: 14px; }
        .step-num {
            width: 26px; height: 26px; border-radius: 50%;
            background: rgba(61,127,255,.2); border: 1px solid rgba(61,127,255,.4);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.72rem; font-weight: 600; color: #7eb3ff;
            flex-shrink: 0; margin-top: 2px;
            font-family: 'DM Mono', monospace;
        }
        .step-text { font-size: 0.88rem; color: rgba(255,255,255,.7); line-height: 1.5; }
        .step-text strong { color: rgba(255,255,255,.9); font-weight: 500; display: block; }

        /* ── RIGHT ─────────────────────────────── */
        .right-panel {
            width: 480px;
            background: var(--white);
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 2.5rem 2.75rem;
            box-shadow: -8px 0 40px rgba(0,0,0,.25);
            overflow-y: auto;
        }

        .form-box { width: 100%; max-width: 380px; padding: 0.5rem 0; }

        .form-head { margin-bottom: 1.75rem; }
        .form-head h2 { font-size: 1.6rem; font-weight: 600; color: var(--text); margin-bottom: 0.3rem; }
        .form-head p { font-size: 0.88rem; color: var(--muted); }

        .field { margin-bottom: 1rem; }
        .field label {
            display: block; font-size: 0.82rem; font-weight: 500;
            color: var(--text); margin-bottom: 0.4rem;
        }

        .input-wrap { position: relative; }
        .input-wrap svg {
            position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
            width: 15px; height: 15px; stroke: var(--muted); pointer-events: none;
        }
        .input-wrap input {
            width: 100%;
            padding: 0.68rem 0.85rem 0.68rem 2.5rem;
            border: 1.5px solid var(--border); border-radius: 9px;
            font-size: 0.88rem; font-family: 'DM Sans', sans-serif;
            color: var(--text); background: var(--off);
            transition: border-color .2s, box-shadow .2s, background .2s;
        }
        .input-wrap input:focus {
            outline: none; border-color: var(--accent); background: var(--white);
            box-shadow: 0 0 0 3px rgba(61,127,255,.12);
        }
        .input-wrap input::placeholder { color: #b0bacf; }

        .field-hint { font-size: 0.76rem; color: var(--muted); margin-top: 0.3rem; }

        /* File upload */
        .file-zone {
            display: flex; align-items: center; gap: 10px;
            padding: 0.75rem 1rem;
            border: 1.5px dashed var(--border); border-radius: 9px;
            background: var(--off); cursor: pointer;
            transition: border-color .2s, background .2s;
        }
        .file-zone:hover { border-color: var(--accent); background: rgba(61,127,255,.04); }
        .file-zone svg { width: 16px; height: 16px; stroke: var(--muted); flex-shrink: 0; }
        .file-zone-text { font-size: 0.84rem; color: var(--muted); }
        .file-input { display: none; }
        .file-selected { font-size: 0.78rem; color: var(--success); font-weight: 500; margin-top: 0.35rem; display: flex; align-items: center; gap: 5px; }
        .file-selected svg { width: 13px; height: 13px; stroke: var(--success); }

        .btn-primary {
            width: 100%; padding: 0.8rem;
            background: linear-gradient(135deg, var(--navy) 0%, var(--blue) 100%);
            border: none; border-radius: 9px;
            color: var(--white); font-size: 0.92rem; font-weight: 600;
            font-family: 'DM Sans', sans-serif;
            cursor: pointer; transition: opacity .2s, transform .15s, box-shadow .2s;
            margin-top: 1rem;
        }
        .btn-primary:hover { opacity: .9; transform: translateY(-1px); box-shadow: 0 6px 18px rgba(15,31,61,.3); }

        .divider { display: flex; align-items: center; gap: 0.75rem; margin: 1.25rem 0; color: var(--muted); font-size: 0.8rem; }
        .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }

        .form-footer { text-align: center; font-size: 0.83rem; color: var(--muted); line-height: 1.9; }
        .form-footer a { color: var(--blue); font-weight: 600; text-decoration: none; }
        .form-footer a:hover { color: var(--accent); }

        .alert {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 0.8rem 1rem; border-radius: 9px;
            font-size: 0.83rem; line-height: 1.5; margin-top: 1rem;
        }
        .alert svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 2px; stroke: currentColor; }
        .alert-error { background: #fff5f5; border: 1px solid #fed7d7; color: var(--error); }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: var(--success); }
        .alert a { color: inherit; font-weight: 600; }

        /* ── PASSWORD STRENGTH ─────────────────── */
        .s-bar {
            flex: 1; height: 4px; border-radius: 4px;
            background: var(--border); transition: background .25s;
        }
        .s-bar.weak   { background: #e53e3e; }
        .s-bar.fair   { background: #dd6b20; }
        .s-bar.good   { background: #d69e2e; }
        .s-bar.strong { background: #38a169; }

        .req {
            display: flex; align-items: center; gap: 6px;
            font-size: 0.78rem; color: var(--muted); transition: color .2s;
        }
        .req.met { color: #38a169; }
        .req-dot { font-size: 0.8rem; line-height: 1; }
    
            body { flex-direction: column; overflow: auto; }
            .left-panel { padding: 2.5rem 2rem; flex: none; min-height: 38vh; }
            .right-panel { width: 100%; }
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
        <h1>Apply to join<br><strong>our team</strong></h1>
        <p>Submit your application with your resume. Our admin team will review your qualifications and contact you soon.</p>

        <div class="steps">
            <div class="step">
                <div class="step-num">01</div>
                <div class="step-text"><strong>Complete the form</strong>Fill in your personal details and create a secure password.</div>
            </div>
            <div class="step">
                <div class="step-num">02</div>
                <div class="step-text"><strong>Upload your résumé</strong>Attach a PDF or Word document to strengthen your application.</div>
            </div>
            <div class="step">
                <div class="step-num">03</div>
                <div class="step-text"><strong>Await review</strong>Our team will evaluate your application and notify you by email.</div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ RIGHT PANEL ══════════════════════════════════════════════════ -->
<div class="right-panel">
    <div class="form-box">
        <div class="form-head">
            <h2>Staff Application</h2>
            <p>Fill in your details and upload your résumé</p>
        </div>

        <?php if($success): ?>
        <div class="alert alert-success">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
            <span>Application submitted successfully! <a href="applicant_status.php">Check your status</a> or <a href="login.php">sign in</a> to view updates.</span>
        </div>
        <?php else: ?>

        <form action="signup.php" method="POST" enctype="multipart/form-data">
            <div class="field">
                <label>First Name *</label>
                <div class="input-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>
                    </svg>
                    <input type="text" id="first_name" name="first_name" placeholder="e.g. Maria" required
                           value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>">
                </div>
            </div>

            <div class="field">
                <label>Last Name *</label>
                <div class="input-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>
                    </svg>
                    <input type="text" id="last_name" name="last_name" placeholder="e.g. Santos" required
                           value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>">
                </div>
            </div>

            <div class="field">
                <label>Email Address *</label>
                <div class="input-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 7l10 7 10-7"/>
                    </svg>
                    <input type="email" id="email" name="email" placeholder="you@example.com" required
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
            </div>

            <div class="field">
                <label>Phone Number</label>
                <div class="input-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 16.92v3a2 2 0 01-2.18 2A19.79 19.79 0 013.09 4.18 2 2 0 015.06 2h3a2 2 0 012 1.72c.12.96.36 1.9.7 2.81a2 2 0 01-.45 2.11L9.09 9.91a16 16 0 006.99 7l1.27-1.27a2 2 0 012.11-.45c.91.34 1.85.58 2.81.7A2 2 0 0122 16.92z"/>
                    </svg>
                    <input type="tel" id="phone" name="phone" placeholder="+63 9XX XXX XXXX"
                           value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                </div>
                <div class="field-hint">Optional — helps us reach you faster</div>
            </div>

            <div class="field">
                <label>Password *</label>
                <div class="input-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
                    </svg>
                    <input type="password" id="password" name="password" placeholder="Min. 8 chars with upper, lower, number & symbol" required>
                </div>
                <!-- strength bar -->
                <div id="strength-wrap" style="margin-top:8px;display:none;">
                    <div style="display:flex;gap:4px;margin-bottom:6px;">
                        <div class="s-bar" id="s1"></div>
                        <div class="s-bar" id="s2"></div>
                        <div class="s-bar" id="s3"></div>
                        <div class="s-bar" id="s4"></div>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:3px;">
                        <div class="req" id="req-len">  <span class="req-dot">○</span> At least 8 characters</div>
                        <div class="req" id="req-upper"><span class="req-dot">○</span> Uppercase letter (A–Z)</div>
                        <div class="req" id="req-lower"><span class="req-dot">○</span> Lowercase letter (a–z)</div>
                        <div class="req" id="req-num">  <span class="req-dot">○</span> Number (0–9)</div>
                        <div class="req" id="req-sym">  <span class="req-dot">○</span> Special character (!@#$%…)</div>
                    </div>
                </div>
            </div>

            <div class="field">
                <label>Confirm Password *</label>
                <div class="input-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
                    </svg>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter your password" required>
                </div>
            </div>

            <div class="field">
                <label>Upload Résumé</label>
                <label for="resume" class="file-zone">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/>
                    </svg>
                    <span class="file-zone-text" id="file-label">Choose PDF or Word document (max 5 MB)</span>
                </label>
                <input type="file" id="resume" name="resume" class="file-input" accept=".pdf,.doc,.docx">
                <div id="file-display" class="file-selected" style="display:none">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    <span id="file-name"></span>
                </div>
                <div class="field-hint">Optional — strongly recommended for faster approval</div>
            </div>

            <button type="submit" class="btn-primary">Submit Application</button>
        </form>

        <?php if(!empty($error)): ?>
        <div class="alert alert-error">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <span>
            <?php 
            switch($error) {
                case 'empty':             echo 'Please fill in all required fields.'; break;
                case 'password_mismatch': echo 'Passwords do not match.'; break;
                case 'password_short':    echo 'Password must be at least 8 characters.'; break;
                case 'password_weak':     echo 'Password must contain uppercase, lowercase, a number, and a special character (e.g. !@#$%).'; break;
                case 'email_exists':      echo 'This email is already registered. <a href="login.php">Sign in instead</a>.'; break;
                case 'invalid_file_type': echo 'Invalid file type. Please upload a PDF or Word document.'; break;
                case 'file_too_large':    echo 'File exceeds the 5 MB limit.'; break;
                case 'upload_failed':     echo 'Failed to upload résumé. Please try again.'; break;
                case 'database':          echo 'Database connection error. Please try again later.'; break;
                default:                  echo 'An error occurred. Please try again.';
            }
            ?>
            </span>
        </div>
        <?php endif; ?>

        <?php endif; ?>

        <div class="divider"><span>or</span></div>

        <div class="form-footer">
            Already have an account? <a href="login.php">Sign in here</a><br>
            <a href="applicant_status.php">Check Application Status</a>
        </div>
    </div>
</div>

<script>
document.getElementById('resume').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const display = document.getElementById('file-display');
    const name = document.getElementById('file-name');
    const label = document.getElementById('file-label');

    if (file) {
        display.style.display = 'flex';
        name.textContent = file.name;
        label.textContent = 'Change file';
    } else {
        display.style.display = 'none';
        label.textContent = 'Choose PDF or Word document (max 5 MB)';
    }
});

// ── PASSWORD STRENGTH CHECKER ─────────────────────────────────────────
(function () {
    const input   = document.getElementById('password');
    const wrap    = document.getElementById('strength-wrap');
    const bars    = [document.getElementById('s1'), document.getElementById('s2'),
                     document.getElementById('s3'), document.getElementById('s4')];
    const reqs = {
        len:   document.getElementById('req-len'),
        upper: document.getElementById('req-upper'),
        lower: document.getElementById('req-lower'),
        num:   document.getElementById('req-num'),
        sym:   document.getElementById('req-sym'),
    };

    function check(val) {
        return {
            len:   val.length >= 8,
            upper: /[A-Z]/.test(val),
            lower: /[a-z]/.test(val),
            num:   /[0-9]/.test(val),
            sym:   /[\W_]/.test(val),
        };
    }

    const colors = ['weak', 'fair', 'good', 'strong'];
    const labels = ['Weak', 'Fair', 'Good', 'Strong'];

    input.addEventListener('input', function () {
        const val = this.value;
        if (!val) { wrap.style.display = 'none'; return; }
        wrap.style.display = 'block';

        const r = check(val);
        const score = Object.values(r).filter(Boolean).length; // 0–5

        // update requirement list
        Object.entries(r).forEach(([k, met]) => {
            reqs[k].classList.toggle('met', met);
            reqs[k].querySelector('.req-dot').textContent = met ? '●' : '○';
        });

        // update bars (score 1-2 = weak, 3 = fair, 4 = good, 5 = strong)
        const level = score <= 2 ? 0 : score === 3 ? 1 : score === 4 ? 2 : 3;
        bars.forEach((b, i) => {
            b.className = 's-bar';
            if (i <= level) b.classList.add(colors[level]);
        });
    });
})();
</script>
</body>
</html>