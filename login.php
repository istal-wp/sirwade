<?php
session_start();

$servername   = getenv('MYSQLHOST')     ?: getenv('DB_HOST')     ?: 'localhost';
$db_port      = getenv('MYSQLPORT')     ?: getenv('DB_PORT')     ?: '3306';
$db_username  = getenv('MYSQLUSER')     ?: getenv('DB_USER')     ?: 'root';
$db_password  = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS')     ?: '';
$dbname       = getenv('MYSQLDATABASE') ?: getenv('DB_NAME')     ?: 'loogistics';

// ── LOGIN ATTEMPT TRACKING ───────────────────────────────────────────
define('MAX_LOGIN_ATTEMPTS', 3);
define('LOCKOUT_DURATION', 15 * 60); // 15 minutes in seconds

// Check if currently locked out
$lockout_time      = isset($_SESSION['lockout_time']) ? $_SESSION['lockout_time'] : 0;
$login_attempts    = isset($_SESSION['login_attempts']) ? $_SESSION['login_attempts'] : 0;
$is_locked_out     = false;
$lockout_remaining = 0;
$attempts_left     = MAX_LOGIN_ATTEMPTS;

if ($lockout_time > 0) {
    $elapsed = time() - $lockout_time;
    if ($elapsed < LOCKOUT_DURATION) {
        $is_locked_out     = true;
        $lockout_remaining = LOCKOUT_DURATION - $elapsed;
    } else {
        // Lockout expired — reset
        $_SESSION['login_attempts'] = 0;
        $_SESSION['lockout_time']   = 0;
        $login_attempts = 0;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    if ($is_locked_out) {
        $error = "locked";
    } else {

        $email = trim($_POST['email']);
        $user_password = $_POST['password'];
        
        if (empty($email) || empty($user_password)) {
            $error = "empty";
        } else {
            try {
                    $pdo = new PDO("mysql:host=$servername;port=$db_port;dbname=$dbname;charset=utf8mb4", $db_username, $db_password);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                $stmt = $pdo->prepare("SELECT id, email, password, first_name, last_name, role, status, application_status FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && $user_password === $user['password']) {

                    // Successful auth — reset attempt counter
                    $_SESSION['login_attempts'] = 0;
                    $_SESSION['lockout_time']   = 0;

                    if ($user['application_status'] === 'pending') {
                        $error = "pending";
                    } elseif ($user['application_status'] === 'rejected') {
                        $error = "rejected";
                    } elseif ($user['status'] !== 'active') {
                        $error = "inactive";
                    } else {
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                        $_SESSION['user_role'] = $user['role'];
                        $_SESSION['logged_in'] = true;
                        
                        $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                        $updateStmt->execute([$user['id']]);
                        
                        if ($user['role'] === 'admin') {
                            $redirect_url = "admin/dashboard.php";
                        } elseif ($user['role'] === 'staff') {
                            $redirect_url = "staff/dashboard.php";
                        } else {
                            $redirect_url = "dashboard.php";
                        }
                        
                        if (!file_exists($redirect_url)) {
                            header("Location: dashboard.php");
                        } else {
                            header("Location: " . $redirect_url);
                        }
                        exit();
                    }
                } else {
                    // Failed login — increment counter
                    $_SESSION['login_attempts'] = $login_attempts + 1;
                    $login_attempts = $_SESSION['login_attempts'];

                    if ($login_attempts >= MAX_LOGIN_ATTEMPTS) {
                        $_SESSION['lockout_time'] = time();
                        $is_locked_out     = true;
                        $lockout_remaining = LOCKOUT_DURATION;
                        $error = "locked";
                    } else {
                        $attempts_left = MAX_LOGIN_ATTEMPTS - $login_attempts;
                        $error = "invalid";
                    }
                }
                
            } catch(PDOException $e) {
                error_log("Login error: " . $e->getMessage());
                $error = "database";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loogistics — Sign In</title>
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
            --error:  #c53030;
            --warn:   #b45309;
            --success:#15803d;
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

        /* subtle grid overlay */
        .left-panel::before {
            content: '';
            position: absolute; inset: 0;
            background-image:
                linear-gradient(rgba(61,127,255,.06) 1px, transparent 1px),
                linear-gradient(90deg, rgba(61,127,255,.06) 1px, transparent 1px);
            background-size: 48px 48px;
            pointer-events: none;
        }

        /* glowing circle */
        .glow {
            position: absolute;
            width: 520px; height: 520px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(61,127,255,.18) 0%, transparent 70%);
            top: -80px; left: -120px;
            pointer-events: none;
        }

        .brand {
            position: relative;
            margin-bottom: 3.5rem;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 0.5rem;
        }

        .brand-logo svg {
            width: 44px; height: 44px;
            flex-shrink: 0;
        }

        .brand-name {
            font-size: 1.6rem;
            font-weight: 600;
            color: var(--white);
            letter-spacing: 0.06em;
        }

        .brand-tag {
            font-family: 'DM Mono', monospace;
            font-size: 0.7rem;
            color: rgba(255,255,255,.45);
            letter-spacing: 0.18em;
            text-transform: uppercase;
            margin-left: 58px;
        }

        .hero-text {
            position: relative;
            max-width: 480px;
        }

        .hero-text h1 {
            font-size: clamp(2rem, 3.2vw, 2.8rem);
            font-weight: 300;
            color: var(--white);
            line-height: 1.25;
            margin-bottom: 1.2rem;
        }

        .hero-text h1 strong {
            font-weight: 600;
            color: #7eb3ff;
        }

        .hero-text p {
            font-size: 0.95rem;
            color: rgba(255,255,255,.6);
            line-height: 1.7;
            margin-bottom: 2.5rem;
        }

        .feature-list {
            display: flex;
            flex-direction: column;
            gap: 0.9rem;
        }

        .feature-item {
            display: flex;
            align-items: center;
            gap: 12px;
            color: rgba(255,255,255,.75);
            font-size: 0.9rem;
        }

        .feature-item .fi-icon {
            width: 32px; height: 32px;
            background: rgba(61,127,255,.15);
            border: 1px solid rgba(61,127,255,.25);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }

        .feature-item .fi-icon svg {
            width: 15px; height: 15px;
            stroke: #7eb3ff;
        }

        /* ── RIGHT PANEL ─────────────────────────────── */
        .right-panel {
            width: 440px;
            background: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem 2.75rem;
            box-shadow: -8px 0 40px rgba(0,0,0,.25);
        }

        .form-box {
            width: 100%;
            max-width: 360px;
        }

        .form-head {
            margin-bottom: 2rem;
        }

        .form-head h2 {
            font-size: 1.65rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 0.3rem;
        }

        .form-head p {
            font-size: 0.88rem;
            color: var(--muted);
        }

        .field {
            margin-bottom: 1.15rem;
        }

        .field label {
            display: block;
            font-size: 0.82rem;
            font-weight: 500;
            color: var(--text);
            margin-bottom: 0.45rem;
            letter-spacing: 0.01em;
        }

        .input-wrap {
            position: relative;
        }

        .input-wrap svg {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px; height: 16px;
            stroke: var(--muted);
            pointer-events: none;
        }

        .input-wrap input {
            width: 100%;
            padding: 0.72rem 0.9rem 0.72rem 2.6rem;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            font-size: 0.9rem;
            font-family: 'DM Sans', sans-serif;
            color: var(--text);
            background: var(--off);
            transition: border-color .2s, box-shadow .2s, background .2s;
        }

        .input-wrap input:focus {
            outline: none;
            border-color: var(--accent);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(61,127,255,.12);
        }

        .input-wrap input::placeholder { color: #b0bacf; }

        .row-forgot {
            text-align: right;
            margin-bottom: 1.5rem;
        }

        .row-forgot a {
            font-size: 0.82rem;
            color: var(--accent);
            text-decoration: none;
            font-weight: 500;
        }

        .btn-primary {
            width: 100%;
            padding: 0.82rem;
            background: linear-gradient(135deg, var(--navy) 0%, var(--blue) 100%);
            border: none;
            border-radius: 9px;
            color: var(--white);
            font-size: 0.92rem;
            font-weight: 600;
            font-family: 'DM Sans', sans-serif;
            cursor: pointer;
            transition: opacity .2s, transform .15s, box-shadow .2s;
            letter-spacing: 0.02em;
        }

        .btn-primary:hover { opacity: .9; transform: translateY(-1px); box-shadow: 0 6px 18px rgba(15,31,61,.3); }
        .btn-primary:active { transform: translateY(0); }
        .btn-primary:disabled { opacity: .6; cursor: not-allowed; transform: none; }

        .divider {
            display: flex; align-items: center;
            gap: 0.75rem;
            margin: 1.5rem 0;
            color: var(--muted);
            font-size: 0.8rem;
        }
        .divider::before, .divider::after {
            content: ''; flex: 1; height: 1px; background: var(--border);
        }

        .social-row {
            display: flex; gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .btn-social {
            flex: 1;
            display: flex; align-items: center; justify-content: center;
            gap: 8px;
            padding: 0.65rem;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            background: var(--white);
            font-size: 0.82rem;
            font-weight: 500;
            font-family: 'DM Sans', sans-serif;
            color: var(--text);
            cursor: pointer;
            transition: border-color .2s, box-shadow .2s;
        }

        .btn-social svg { width: 16px; height: 16px; flex-shrink: 0; }
        .btn-social:hover { border-color: var(--accent); box-shadow: 0 2px 10px rgba(61,127,255,.1); }

        .form-footer {
            text-align: center;
            font-size: 0.83rem;
            color: var(--muted);
            line-height: 1.9;
        }

        .form-footer a { color: var(--blue); font-weight: 600; text-decoration: none; }
        .form-footer a:hover { color: var(--accent); }

        /* ── ALERTS ──────────────────────────────────── */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 0.8rem 1rem;
            border-radius: 9px;
            font-size: 0.84rem;
            line-height: 1.5;
            margin-top: 1rem;
        }

        .alert svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; }

        .alert-error {
            background: #fff5f5;
            border: 1px solid #fed7d7;
            color: var(--error);
        }

        .alert-warn {
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: var(--warn);
        }

        .alert a { color: inherit; font-weight: 600; }

        @media (max-width: 900px) {
            body { flex-direction: column; overflow: auto; }
            .left-panel { padding: 2.5rem 2rem; flex: none; min-height: 42vh; }
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
            <!-- Truck / logistics mark -->
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
        <h1>Optimizing supply chains<br>through <strong>smart technology</strong></h1>
        <p>Access comprehensive logistics management solutions designed to optimize your supply chain operations, procurement, and asset tracking.</p>

        <div class="feature-list">
            <div class="feature-item">
                <div class="fi-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>
                    </svg>
                </div>
                <span>Smart Warehousing</span>
            </div>
            <div class="feature-item">
                <div class="fi-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>
                    </svg>
                </div>
                <span>Fleet Tracking</span>
            </div>
            <div class="feature-item">
                <div class="fi-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>
                    </svg>
                </div>
                <span>Analytics Dashboard</span>
            </div>
        </div>
    </div>
</div>

<!-- ═══ RIGHT PANEL ══════════════════════════════════════════════════ -->
<div class="right-panel">
    <div class="form-box">
        <div class="form-head">
            <h2>Sign In</h2>
            <p>Access your logistics dashboard</p>
        </div>

        <form action="login.php" method="POST">
            <div class="field">
                <label for="email">Email Address</label>
                <div class="input-wrap">
                    <!-- mail icon -->
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 7l10 7 10-7"/>
                    </svg>
                    <input type="email" id="email" name="email" placeholder="you@company.com" required
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="input-wrap">
                    <!-- lock icon -->
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
                    </svg>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                </div>
            </div>

            <div class="row-forgot">
                <a href="forgot-password.php">Forgot Password?</a>
            </div>

            <button type="submit" class="btn-primary" id="loginBtn" <?php echo $is_locked_out ? 'disabled style="opacity:.5;cursor:not-allowed;"' : ''; ?>>
                <?php echo $is_locked_out ? 'Account Locked' : 'Sign In'; ?>
            </button>
        </form>

        <?php if ($is_locked_out && !isset($error)): $error = "locked"; endif; ?>
        <?php if(isset($error)): ?>
        <div class="alert <?php echo ($error === 'pending' || $error === 'rejected') ? 'alert-warn' : 'alert-error'; ?>">
            <?php if ($error === 'pending' || $error === 'rejected'): ?>
            <!-- clock icon -->
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" stroke="currentColor">
                <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
            </svg>
            <?php elseif ($error === 'locked'): ?>
            <!-- lock icon -->
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" stroke="currentColor">
                <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
            </svg>
            <?php else: ?>
            <!-- alert circle icon -->
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" stroke="currentColor">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <?php endif; ?>
            <span>
            <?php 
            switch($error) {
                case 'invalid':
                    echo 'Invalid email or password. ';
                    echo '<strong>' . $attempts_left . ' attempt' . ($attempts_left === 1 ? '' : 's') . ' remaining</strong> before your account is temporarily locked.';
                    break;
                case 'locked':
                    $mins = ceil($lockout_remaining / 60);
                    echo 'Too many failed login attempts. Please try again in <strong>' . $mins . ' minute' . ($mins === 1 ? '' : 's') . '</strong>.';
                    break;
                case 'empty':    echo 'Please fill in all fields.'; break;
                case 'pending':  echo 'Your application is still pending review. <a href="applicant_status.php">Check status</a> or wait for admin approval.'; break;
                case 'rejected': echo 'Your application was not approved. <a href="applicant_status.php">Check status</a> for more details.'; break;
                case 'inactive': echo 'Your account is inactive. Please contact the administrator.'; break;
                case 'database': echo 'Database connection error. Please try again later.'; break;
                default:         echo 'An error occurred. Please try again.';
            }
            ?>
            </span>
        </div>
        <?php endif; ?>

        <div class="divider"><span>or continue with</span></div>

        <div class="social-row">
            <button class="btn-social">
                <!-- Google G mark -->
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                    <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                    <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05"/>
                    <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                </svg>
                Google
            </button>
            <button class="btn-social">
                <!-- Microsoft squares -->
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="1" y="1" width="10" height="10" fill="#F25022"/>
                    <rect x="13" y="1" width="10" height="10" fill="#7FBA00"/>
                    <rect x="1" y="13" width="10" height="10" fill="#00A4EF"/>
                    <rect x="13" y="13" width="10" height="10" fill="#FFB900"/>
                </svg>
                Microsoft
            </button>
        </div>

        <div class="form-footer">
            Staff Registration? <a href="signup.php">Sign up here</a><br>
            <a href="applicant_status.php">Check Application Status</a>
        </div>
    </div>
</div>

<script>
document.querySelector('form').addEventListener('submit', function() {
    const btn = document.getElementById('loginBtn');
    btn.textContent = 'Signing in…';
    btn.disabled = true;
});
</script>
</body>
</html>