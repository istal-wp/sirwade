<?php
/**
 * includes/admin/header.php
 * Reusable admin page header / topbar.
 *
 * Variables expected to be set BEFORE including this file:
 *   $page_title  string  – <title> text, e.g. "Dashboard"
 *   $back_url    string  – optional back-button href (leave empty to hide)
 *   $extra_css   string  – optional additional <style> block content
 */
$page_title = $page_title ?? 'Admin';
$back_url   = $back_url   ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($page_title); ?> — BRIGHTPATH</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        /* ── DESIGN TOKENS ────────────────────────────────────────── */
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
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'DM Sans',sans-serif; background:var(--off); min-height:100vh; color:var(--text); }

        /* ── TOPBAR ───────────────────────────────────────────────── */
        .header {
            background:#ffffff !important;
            border-bottom:1px solid var(--border);
            padding:0 2rem;
            position:sticky; top:0; z-index:500;
            box-shadow:0 1px 8px rgba(15,31,61,.09);
        }
        .header-inner {
            display:flex; justify-content:space-between; align-items:center;
            max-width:1600px; margin:0 auto; height:64px;
        }
        .header-left  { display:flex; align-items:center; gap:12px; }
        .brand        { display:flex; align-items:center; gap:12px; text-decoration:none; }
        .brand-mark {
            width:38px; height:38px;
            background:linear-gradient(135deg,#0f1f3d,#2c4a8a);
            border-radius:9px; display:flex; align-items:center; justify-content:center; flex-shrink:0;
        }
        .brand-mark svg { width:20px; height:20px; stroke:rgba(255,255,255,.9); fill:none; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
        .brand-text h1  { font-size:1rem; font-weight:600; color:#0f1f3d; letter-spacing:.05em; }
        .brand-text p   { font-size:.68rem; color:#6b7a99; letter-spacing:.08em; text-transform:uppercase; font-family:'DM Mono',monospace; }
        .header-right   { display:flex; align-items:center; gap:.85rem; }
        .btn-back {
            display:flex; align-items:center; gap:7px;
            padding:.48rem 1rem; background:none; border:1.5px solid var(--border); border-radius:8px;
            font-size:.82rem; font-weight:500; font-family:'DM Sans',sans-serif;
            color:var(--muted); cursor:pointer; text-decoration:none; transition:border-color .2s,color .2s;
        }
        .btn-back svg { width:14px; height:14px; stroke:currentColor; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
        .btn-back:hover { border-color:var(--accent); color:var(--accent); }
        /* Profile pill */
        .profile-wrap { position:relative; }
        .user-pill {
            display:flex; align-items:center; gap:9px;
            padding:.38rem .8rem .38rem .38rem;
            background:#f4f6fb; border:1.5px solid var(--border); border-radius:99px;
            cursor:pointer; transition:border-color .2s,box-shadow .2s; user-select:none;
        }
        .user-pill:hover { border-color:var(--accent); box-shadow:0 2px 12px rgba(61,127,255,.12); }
        .user-avatar {
            width:28px; height:28px; border-radius:50%;
            background:linear-gradient(135deg,#0f1f3d,#2c4a8a);
            display:flex; align-items:center; justify-content:center;
            font-family:'DM Mono',monospace; font-size:.7rem; font-weight:600; color:#fff; flex-shrink:0;
        }
        .user-name   { font-size:.83rem; font-weight:500; color:#1a2540; }
        .pill-caret  { width:14px; height:14px; stroke:#6b7a99; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; transition:transform .2s; flex-shrink:0; }
        .profile-wrap.open .pill-caret { transform:rotate(180deg); }
        .profile-dropdown {
            display:none; position:absolute; top:calc(100% + 10px); right:0;
            width:280px; background:#fff; border:1px solid var(--border);
            border-radius:14px; box-shadow:0 12px 40px rgba(15,31,61,.2);
            z-index:600; overflow:hidden; animation:dropIn .18s ease;
        }
        .profile-wrap.open .profile-dropdown { display:block; }
        @keyframes dropIn { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:translateY(0)} }
        .pd-head { padding:1.2rem 1.4rem 1rem; background:linear-gradient(135deg,#0f1f3d,#2c4a8a); display:flex; align-items:center; gap:12px; }
        .pd-avatar { width:44px; height:44px; border-radius:50%; background:rgba(255,255,255,.18); border:2px solid rgba(255,255,255,.3); display:flex; align-items:center; justify-content:center; font-family:'DM Mono',monospace; font-size:.9rem; font-weight:700; color:#fff; flex-shrink:0; }
        .pd-info-name  { font-size:.95rem; font-weight:600; color:#fff; }
        .pd-info-email { font-size:.75rem; color:rgba(255,255,255,.6); margin-top:1px; word-break:break-all; }
        .pd-body { padding:.75rem 1.4rem; }
        .pd-row { display:flex; align-items:center; gap:10px; padding:.55rem 0; border-bottom:1px solid #f4f6fb; font-size:.82rem; }
        .pd-row:last-child { border-bottom:none; }
        .pd-row svg { width:14px; height:14px; stroke:#6b7a99; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; flex-shrink:0; }
        .pd-row-label { color:#6b7a99; min-width:60px; }
        .pd-row-val   { color:#1a2540; font-weight:500; margin-left:auto; text-align:right; }
        .pd-role-badge { display:inline-block; padding:.18rem .55rem; border-radius:99px; font-size:.7rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; background:rgba(61,127,255,.1); color:#3d7fff; }
        .pd-foot   { padding:.75rem 1.4rem 1rem; border-top:1px solid var(--border); }
        .pd-logout {
            display:flex; align-items:center; justify-content:center; gap:7px;
            width:100%; padding:.6rem; border-radius:8px;
            background:rgba(197,48,48,.07); border:1.5px solid rgba(197,48,48,.2);
            color:#c53030; font-size:.84rem; font-weight:600; font-family:'DM Sans',sans-serif;
            cursor:pointer; text-decoration:none; transition:background .2s,border-color .2s;
        }
        .pd-logout svg { width:14px; height:14px; stroke:currentColor; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
        .pd-logout:hover { background:rgba(197,48,48,.14); border-color:#c53030; }
        /* ── COMMON LAYOUT ────────────────────────────────────────── */
        .main  { max-width:1600px; margin:0 auto; padding:2rem 2rem 3rem; }
        .page-title { margin-bottom:1.75rem; }
        .page-title h1 { font-size:1.6rem; font-weight:600; color:var(--navy); margin-bottom:.25rem; }
        .page-title p  { font-size:.88rem; color:var(--muted); }
        /* Flash banners */
        .banner { display:flex; align-items:center; gap:10px; padding:.85rem 1.1rem; border-radius:10px; margin-bottom:1.5rem; font-size:.87rem; }
        .banner.warn  { background:#fffbeb; border:1px solid #fde68a; color:var(--warn); }
        .banner.success{ background:#f0fdf4; border:1px solid #86efac; color:var(--success); }
        .banner.error  { background:#fef2f2; border:1px solid #fca5a5; color:var(--error); }
        .banner svg { width:16px; height:16px; stroke:currentColor; flex-shrink:0; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
        /* Stat cards */
        .stats-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(210px,1fr)); gap:1rem; margin-bottom:2rem; }
        .stat-card { background:var(--white); border:1px solid var(--border); border-radius:12px; padding:1.25rem 1.4rem; transition:box-shadow .2s,transform .15s; }
        .stat-card:hover { box-shadow:0 4px 18px rgba(15,31,61,.08); transform:translateY(-2px); }
        .stat-top { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:.75rem; }
        .stat-label { font-size:.78rem; font-weight:500; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; }
        .stat-badge { width:36px; height:36px; background:rgba(61,127,255,.09); border-radius:9px; display:flex; align-items:center; justify-content:center; }
        .stat-badge svg { width:17px; height:17px; stroke:var(--accent); fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
        .stat-value { font-size:2rem; font-weight:600; color:var(--navy); line-height:1; margin-bottom:.4rem; }
        .stat-sub { display:flex; align-items:center; gap:5px; font-size:.78rem; color:var(--muted); }
        .stat-sub svg { width:12px; height:12px; stroke:currentColor; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
        .stat-sub.warn    { color:var(--warn); }
        .stat-sub.good    { color:var(--success); }
        .stat-sub.pend    { color:#6366f1; }
        .stat-sub.error   { color:var(--error); }
        /* Section labels */
        .section-label { font-size:.78rem; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.1em; margin-bottom:.85rem; }
        /* Tables */
        .table-wrap { background:var(--white); border:1px solid var(--border); border-radius:12px; overflow:hidden; }
        .table-head { display:flex; justify-content:space-between; align-items:center; padding:1.25rem 1.5rem; border-bottom:1px solid var(--border); }
        .table-head h2 { font-size:1rem; font-weight:600; color:var(--navy); }
        table.data-table { width:100%; border-collapse:collapse; }
        table.data-table th { padding:.75rem 1rem; text-align:left; font-size:.76rem; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; border-bottom:1px solid var(--border); background:#fafbfe; white-space:nowrap; }
        table.data-table td { padding:.9rem 1rem; font-size:.86rem; color:var(--text); border-bottom:1px solid var(--off); vertical-align:middle; }
        table.data-table tr:last-child td { border-bottom:none; }
        table.data-table tr:hover td { background:#fafbfe; }
        /* Read-only badge */
        .readonly-badge { display:inline-flex; align-items:center; gap:5px; padding:.25rem .65rem; background:rgba(61,127,255,.08); border:1px solid rgba(61,127,255,.2); border-radius:99px; font-size:.72rem; font-weight:600; color:var(--accent); }
        .readonly-badge svg { width:12px; height:12px; stroke:currentColor; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
        /* Responsive */
        @media(max-width:768px){
            .header-inner{flex-wrap:wrap;height:auto;padding:.75rem 0;gap:.75rem;}
            .main{padding:1.25rem 1rem 2rem;}
            .stats-grid{grid-template-columns:1fr 1fr;}
        }
        <?php echo $extra_css ?? ''; ?>
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
                    <p>Admin Panel</p>
                </div>
            </a>
        </div>
        <div class="header-right">
            <?php if ($back_url): ?>
            <a href="<?php echo h($back_url); ?>" class="btn-back">
                <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
                Back
            </a>
            <?php endif; ?>
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
<script>
(function(){
    var w=document.getElementById('profileWrap');
    if(!w)return;
    w.querySelector('.user-pill').addEventListener('click',function(e){e.stopPropagation();w.classList.toggle('open');});
    document.addEventListener('click',function(e){if(w&&!w.contains(e.target))w.classList.remove('open');});
    document.addEventListener('keydown',function(e){if(e.key==='Escape'&&w)w.classList.remove('open');});
})();
</script>
