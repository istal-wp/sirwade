<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_role('admin');


$user_name = $_SESSION['user_name'] ?? 'Unknown';
$user_id = $_SESSION['user_id'] ?? 'Unknown';
$goodbye_name = $_SESSION['user_name'] ?? 'Admin';

$_SESSION = array();

if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time()-3600, '/');
}

session_destroy();

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logged Out — BRIGHTPATH</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --navy: #0f1f3d; --blue: #1a3a6e; --accent: #3d7fff; --steel: #2c4a8a;
            --white: #ffffff; --off: #f4f6fb; --border: #dde3ef;
            --text: #1a2540; --muted: #6b7a99; --success: #15803d;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--off);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--text);
            overflow: hidden;
        }

        /* Subtle animated background dots */
        .bg {
            position: fixed; inset: 0; z-index: 0; overflow: hidden;
        }
        .bg-dot {
            position: absolute; border-radius: 50%;
            background: linear-gradient(135deg, var(--navy), var(--steel));
            opacity: 0.045;
            animation: drift linear infinite;
        }
        .bg-dot:nth-child(1) { width:320px; height:320px; top:-80px; left:-80px; animation-duration:30s; }
        .bg-dot:nth-child(2) { width:200px; height:200px; bottom:10%; right:5%; animation-duration:22s; animation-delay:-8s; }
        .bg-dot:nth-child(3) { width:140px; height:140px; top:55%; left:60%; animation-duration:18s; animation-delay:-14s; }
        @keyframes drift {
            0%   { transform: translateY(0) scale(1); }
            50%  { transform: translateY(-28px) scale(1.04); }
            100% { transform: translateY(0) scale(1); }
        }

        /* Confetti */
        .confetti-container {
            position: fixed; inset: 0; pointer-events: none; z-index: 999; overflow: hidden;
        }
        .confetti {
            position: absolute; width: 10px; height: 10px; top: -10px; opacity: 1;
            animation: confetti-fall linear forwards;
        }
        @keyframes confetti-fall {
            to { transform: translateY(100vh) rotate(720deg); opacity: 0; }
        }

        /* Card */
        .card {
            position: relative; z-index: 10;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 3rem 3rem 2.5rem;
            max-width: 460px; width: 90%;
            text-align: center;
            box-shadow: 0 8px 40px rgba(15,31,61,.10);
            animation: popIn .45s cubic-bezier(.34,1.56,.64,1) both;
        }
        @keyframes popIn {
            from { transform: scale(.85); opacity: 0; }
            to   { transform: scale(1);   opacity: 1; }
        }

        /* Brand at top */
        .brand {
            display: flex; align-items: center; justify-content: center;
            gap: 10px; margin-bottom: 2rem;
        }
        .brand-mark {
            width: 38px; height: 38px;
            background: linear-gradient(135deg, var(--navy), var(--steel));
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
        }
        .brand-mark svg { width: 20px; height: 20px; stroke: rgba(255,255,255,.9); }
        .brand-name { font-size: 1rem; font-weight: 600; color: var(--navy); letter-spacing: .05em; }
        .brand-sub  { font-size: .68rem; color: var(--muted); letter-spacing: .09em; text-transform: uppercase; font-family: 'DM Mono', monospace; }

        /* Icon */
        .icon-wrap {
            width: 72px; height: 72px;
            background: var(--off); border: 1px solid var(--border);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 30px;
            animation: wave 2.2s ease-in-out infinite;
        }
        @keyframes wave {
            0%,100% { transform: rotate(0deg); }
            25%      { transform: rotate(-18deg); }
            75%      { transform: rotate(18deg); }
        }

        h1 {
            font-size: 1.55rem; font-weight: 600; color: var(--navy);
            margin-bottom: .6rem;
        }
        .message {
            font-size: .9rem; color: var(--muted); line-height: 1.65;
            margin-bottom: 2rem;
        }
        .message strong { color: var(--navy); font-weight: 600; }

        /* Buttons */
        .btns { display: flex; gap: .75rem; justify-content: center; flex-wrap: wrap; margin-bottom: 1.75rem; }
        .btn {
            display: inline-flex; align-items: center; gap: 7px;
            padding: .65rem 1.3rem; border-radius: 10px;
            font-size: .87rem; font-weight: 600; font-family: 'DM Sans', sans-serif;
            text-decoration: none; transition: all .2s; cursor: pointer; border: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--navy), var(--steel));
            color: white;
        }
        .btn-primary:hover { box-shadow: 0 6px 18px rgba(15,31,61,.25); transform: translateY(-2px); }
        .btn-secondary {
            background: var(--off); color: var(--muted);
            border: 1.5px solid var(--border);
        }
        .btn-secondary:hover { border-color: var(--accent); color: var(--accent); transform: translateY(-2px); }

        /* Security notice */
        .notice {
            display: flex; align-items: flex-start; gap: 9px;
            padding: .9rem 1rem;
            background: rgba(21,128,61,.07); border: 1px solid rgba(21,128,61,.18);
            border-radius: 10px; font-size: .82rem; color: #155724;
            text-align: left; margin-bottom: 1.25rem;
        }
        .notice svg { width: 15px; height: 15px; stroke: #15803d; flex-shrink: 0; margin-top: 1px; }

        /* Countdown */
        .redirect {
            font-size: .78rem; color: var(--muted);
            font-family: 'DM Mono', monospace;
        }
        .countdown { font-weight: 600; color: var(--accent); }

        @media (max-width: 480px) {
            .card { padding: 2rem 1.5rem; }
            .btns { flex-direction: column; }
            .btn { justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="bg">
        <div class="bg-dot"></div>
        <div class="bg-dot"></div>
        <div class="bg-dot"></div>
    </div>

    <div class="confetti-container" id="celebrationContainer"></div>

    <div class="card">
        <div class="brand">
            <div class="brand-mark">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>
                </svg>
            </div>
            <div>
                <div class="brand-name">BRIGHTPATH</div>
                <div class="brand-sub">Logistics Admin</div>
            </div>
        </div>

        <div class="icon-wrap">👋</div>

        <h1>Successfully Logged Out</h1>

        <p class="message">
            Goodbye, <strong><?php echo htmlspecialchars($goodbye_name); ?></strong>!<br>
            You've been safely signed out of the BRIGHTPATH Admin account.
        </p>

        <div class="btns">
            <a href="../login.php" class="btn btn-primary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                Back to Login
            </a>
            <a href="../login.php" class="btn btn-secondary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                Login Again
            </a>
        </div>

        <div class="notice">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <span><strong>Security Notice:</strong> Please close your browser if you're using a shared or public computer.</span>
        </div>

        <div class="redirect">
            Redirecting to login in <span class="countdown" id="countdown">10</span>s…
        </div>
    </div>

    <script>
        // Countdown + redirect
        let seconds = 10;
        const countdownEl = document.getElementById('countdown');
        const timer = setInterval(() => {
            seconds--;
            countdownEl.textContent = seconds;
            if (seconds <= 0) { clearInterval(timer); window.location.href = '../login.php'; }
        }, 1000);

        // Clear storage
        try { sessionStorage.clear(); localStorage.clear(); } catch(e) {}

        // Prevent back navigation
        window.history.forward();

        // Confetti
        const container = document.getElementById('celebrationContainer');
        const colors = ['#3d7fff','#0f1f3d','#2c4a8a','#6b7a99','#15803d','#b45309','#6366f1','#f59e0b'];

        function createConfetti() {
            const el = document.createElement('div');
            el.className = 'confetti';
            const size = Math.random() * 9 + 5;
            const duration = Math.random() * 3 + 2;
            const delay = Math.random() * 1.5;
            el.style.left = (Math.random() * 100) + '%';
            el.style.width = el.style.height = size + 'px';
            el.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
            el.style.animationDuration = duration + 's';
            el.style.animationDelay = delay + 's';
            el.style.borderRadius = Math.random() > .5 ? '50%' : '2px';
            container.appendChild(el);
            setTimeout(() => el.remove(), (duration + delay) * 1000);
        }

        window.addEventListener('load', () => {
            setTimeout(() => {
                for (let i = 0; i < 80; i++) setTimeout(createConfetti, i * 30);
                const iv = setInterval(createConfetti, 80);
                setTimeout(() => clearInterval(iv), 4000);
            }, 200);
        });
    </script>
</body>
</html>