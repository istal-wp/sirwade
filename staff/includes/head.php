<?php
/**
 * BRIGHTPATH — Shared <head> component.
 * Usage:  $page_title = 'My Page'; include 'includes/head.php';
 */
$page_title = $page_title ?? 'BRIGHTPATH';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> — BRIGHTPATH</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        /* ════════════════════════════════════════════════════════════
           BRIGHTPATH STAFF  ·  UNIVERSAL DESIGN SYSTEM
           Based on sws.php + psm.php reference standard
        ════════════════════════════════════════════════════════════ */

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --navy:    #0f1f3d;
            --blue:    #1a3a6e;
            --accent:  #3d7fff;
            --steel:   #2c4a8a;
            --white:   #ffffff;
            --off:     #f4f6fb;
            --border:  #dde3ef;
            --text:    #1a2540;
            --muted:   #6b7a99;
            --success: #15803d;
            --warn:    #b45309;
            --error:   #c53030;
            --card-bg: rgba(255,255,255,0.97);
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--navy);
            min-height: 100vh;
            color: var(--text);
        }

        /* Grid background */
        body::before {
            content: '';
            position: fixed; inset: 0;
            background-image:
                linear-gradient(rgba(61,127,255,.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(61,127,255,.05) 1px, transparent 1px);
            background-size: 48px 48px;
            pointer-events: none; z-index: 0;
        }
        /* Glow blob */
        body::after {
            content: '';
            position: fixed;
            width: 600px; height: 600px; border-radius: 50%;
            background: radial-gradient(circle, rgba(61,127,255,.13) 0%, transparent 70%);
            top: -150px; right: -150px;
            pointer-events: none; z-index: 0;
        }

        /* ── TOPBAR ─────────────────────────────────────────────── */
        .header {
            background: #ffffff !important;
            border-bottom: 1px solid #dde3ef;
            padding: 0 2rem;
            position: sticky; top: 0; z-index: 500;
            box-shadow: 0 1px 8px rgba(15,31,61,.09);
            backdrop-filter: none !important;
        }
        .header-inner {
            display: flex; justify-content: space-between; align-items: center;
            max-width: 1600px; margin: 0 auto; height: 64px;
        }
        .header-left  { display: flex; align-items: center; gap: 12px; }
        .header-right { display: flex; align-items: center; gap: .85rem; }

        /* Brand */
        .brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .brand-mark {
            width: 38px; height: 38px;
            background: linear-gradient(135deg, #0f1f3d, #2c4a8a);
            border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .brand-mark svg { width: 20px; height: 20px; stroke: rgba(255,255,255,.9); fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .brand-text h1 { font-size: 1rem; font-weight: 600; color: #0f1f3d; letter-spacing: .05em; }
        .brand-text p  { font-size: .68rem; color: #6b7a99; letter-spacing: .08em; text-transform: uppercase; font-family: 'DM Mono', monospace; }

        /* Back button */
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
        .user-name  { font-size: .83rem; font-weight: 500; color: #1a2540; }
        .pill-caret { width: 14px; height: 14px; stroke: #6b7a99; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; transition: transform .2s; flex-shrink: 0; }
        .profile-wrap.open .pill-caret { transform: rotate(180deg); }

        /* Profile dropdown */
        .profile-dropdown {
            display: none; position: absolute; top: calc(100% + 10px); right: 0;
            width: 280px; background: #ffffff; border: 1px solid #dde3ef;
            border-radius: 14px; box-shadow: 0 12px 40px rgba(15,31,61,.2);
            z-index: 600; overflow: hidden;
        }
        .profile-wrap.open .profile-dropdown { display: block; animation: dropIn .18s ease; }
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
        .pd-role-badge { display: inline-block; padding: .18rem .55rem; border-radius: 99px; font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
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

        /* ── LAYOUT ─────────────────────────────────────────────── */
        .layout { display: flex; min-height: calc(100vh - 64px); position: relative; z-index: 1; }

        /* ── SIDEBAR ────────────────────────────────────────────── */
        .sidebar {
            width: 240px; flex-shrink: 0;
            background: rgba(15,31,61,.75); backdrop-filter: blur(16px);
            border-right: 1px solid rgba(61,127,255,.12);
            overflow-y: auto;
            position: sticky; top: 64px;
            height: calc(100vh - 64px);
        }
        .sidebar-inner  { padding: 1.5rem 1rem; }
        .sidebar-label  { font-family: 'DM Mono', monospace; font-size: .65rem; font-weight: 500; text-transform: uppercase; letter-spacing: .12em; color: rgba(255,255,255,.3); padding: .5rem .65rem; margin-bottom: .4rem; display: block; }
        .nav-link {
            display: flex; align-items: center; gap: .75rem;
            padding: .65rem .9rem; border-radius: 10px;
            color: rgba(255,255,255,.55); text-decoration: none;
            font-size: .84rem; font-weight: 500; margin-bottom: .2rem;
            transition: all .2s; border: none; background: transparent;
            width: 100%; text-align: left; cursor: pointer; font-family: 'DM Sans', sans-serif;
        }
        .nav-link:hover  { background: rgba(255,255,255,.07); color: rgba(255,255,255,.85); }
        .nav-link.active { background: rgba(61,127,255,.2); color: #fff; border-left: 3px solid var(--accent); padding-left: calc(.9rem - 3px); }
        .nav-icon { width: 18px; height: 18px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }

        /* ── MAIN CONTENT ────────────────────────────────────────── */
        .container { max-width: 1400px; margin: 0 auto; padding: 2.5rem 2.5rem 4rem; position: relative; z-index: 1; }
        .main-content { flex: 1; padding: 2.5rem 2.5rem 4rem; overflow-y: auto; }

        /* ── PAGE TITLE ─────────────────────────────────────────── */
        .page-title        { margin-bottom: 2.5rem; }
        .page-title-tag    { font-family: 'DM Mono', monospace; font-size: .68rem; color: var(--accent); letter-spacing: .2em; text-transform: uppercase; margin-bottom: .5rem; display: block; }
        .page-title h1     { font-size: clamp(1.6rem, 2.5vw, 2.2rem); font-weight: 300; color: var(--white); line-height: 1.2; }
        .page-title h1 strong { font-weight: 600; color: #7eb3ff; }
        .page-title p      { font-size: .9rem; color: rgba(255,255,255,.5); margin-top: .4rem; }

        /* ── STATS ROW ──────────────────────────────────────────── */
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.2rem; margin-bottom: 2.5rem; }

        .stat-card {
            background: var(--card-bg); border: 1px solid var(--border);
            border-radius: 14px; padding: 1.5rem 1.6rem;
            position: relative; overflow: hidden;
            transition: transform .2s, box-shadow .2s;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 12px 32px rgba(0,0,0,.18); }
        .stat-card::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, var(--accent), var(--steel)); border-radius: 14px 14px 0 0; }
        .stat-card.warn::after    { background: linear-gradient(90deg, #f59e0b, #d97706); }
        .stat-card.danger::after  { background: linear-gradient(90deg, #ef4444, #dc2626); }
        .stat-card.success::after { background: linear-gradient(90deg, #22c55e, #16a34a); }

        .stat-icon-wrap {
            width: 42px; height: 42px;
            background: rgba(61,127,255,.1); border: 1px solid rgba(61,127,255,.2);
            border-radius: 10px; display: flex; align-items: center; justify-content: center;
            margin-bottom: 1rem;
        }
        .stat-card.warn    .stat-icon-wrap { background: rgba(245,158,11,.1); border-color: rgba(245,158,11,.2); }
        .stat-card.danger  .stat-icon-wrap { background: rgba(239,68,68,.1);  border-color: rgba(239,68,68,.2); }
        .stat-card.success .stat-icon-wrap { background: rgba(34,197,94,.1);  border-color: rgba(34,197,94,.2); }
        .stat-icon-wrap svg { width: 20px; height: 20px; stroke: var(--accent); fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .stat-card.warn    .stat-icon-wrap svg { stroke: #f59e0b; }
        .stat-card.danger  .stat-icon-wrap svg { stroke: #ef4444; }
        .stat-card.success .stat-icon-wrap svg { stroke: #22c55e; }

        .stat-value { font-size: 1.9rem; font-weight: 600; color: var(--text); line-height: 1; margin-bottom: .35rem; }
        .stat-label { font-family: 'DM Mono', monospace; font-size: .65rem; color: var(--muted); letter-spacing: .14em; text-transform: uppercase; }

        /* ── PANEL ──────────────────────────────────────────────── */
        .panel { background: var(--card-bg); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
        .panel-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 1.2rem 1.6rem; border-bottom: 1px solid var(--border);
            flex-wrap: wrap; gap: .7rem;
        }
        .panel-title  { font-size: .92rem; font-weight: 600; color: var(--text); }
        .panel-badge  { font-family: 'DM Mono', monospace; font-size: .62rem; padding: .25rem .7rem; background: rgba(61,127,255,.08); border: 1px solid rgba(61,127,255,.18); border-radius: 99px; color: var(--accent); letter-spacing: .1em; text-transform: uppercase; }
        .panel-footer { padding: .9rem 1.4rem; border-top: 1px solid var(--border); }

        /* ── FUNCTION CARDS (psm.php pattern) ──────────────────── */
        .section-label { font-family: 'DM Mono', monospace; font-size: .65rem; color: rgba(255,255,255,.4); letter-spacing: .18em; text-transform: uppercase; margin-bottom: 1.2rem; display: block; }
        .functions-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.2rem; margin-bottom: 2.5rem; }
        .fn-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 14px; padding: 1.6rem; cursor: pointer; transition: transform .22s, box-shadow .22s, border-color .22s; display: flex; flex-direction: column; gap: 1rem; }
        .fn-card:hover { transform: translateY(-4px); box-shadow: 0 16px 40px rgba(0,0,0,.2); border-color: rgba(61,127,255,.4); }
        .fn-card-top { display: flex; align-items: flex-start; gap: 1rem; }
        .fn-icon { width: 50px; height: 50px; flex-shrink: 0; background: rgba(61,127,255,.08); border: 1px solid rgba(61,127,255,.18); border-radius: 12px; display: flex; align-items: center; justify-content: center; }
        .fn-icon svg { width: 24px; height: 24px; stroke: var(--accent); fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .fn-title { font-size: .97rem; font-weight: 600; color: var(--text); margin-bottom: .25rem; }
        .fn-desc  { font-size: .82rem; color: var(--muted); line-height: 1.55; }
        .fn-card-foot { display: flex; align-items: center; justify-content: space-between; padding-top: .9rem; border-top: 1px solid var(--border); }
        .fn-btn { padding: .45rem 1.1rem; background: rgba(61,127,255,.1); border: 1px solid rgba(61,127,255,.25); border-radius: 7px; font-size: .78rem; font-weight: 600; color: var(--accent); cursor: pointer; transition: background .2s; font-family: 'DM Sans', sans-serif; }
        .fn-btn:hover { background: rgba(61,127,255,.2); }
        .fn-status { display: flex; align-items: center; gap: 6px; font-family: 'DM Mono', monospace; font-size: .63rem; color: var(--success); letter-spacing: .1em; text-transform: uppercase; }
        .fn-status-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--success); animation: pulse-dot 2s infinite; }
        @keyframes pulse-dot { 0%,100%{opacity:1} 50%{opacity:.4} }

        /* ── BOTTOM GRID ────────────────────────────────────────── */
        .bottom-grid       { display: grid; grid-template-columns: 2fr 1fr; gap: 1.4rem; }
        .main-grid         { display: grid; grid-template-columns: 1fr 340px; gap: 1.4rem; margin-bottom: 1.4rem; }
        .sidebar-stack     { display: flex; flex-direction: column; gap: 1.4rem; }

        /* ── TABLE ──────────────────────────────────────────────── */
        .tbl-wrap { overflow-x: auto; }
        .data-table { width: 100%; border-collapse: collapse; min-width: 640px; }
        .data-table th { padding: .65rem 1rem; font-family: 'DM Mono', monospace; font-size: .6rem; letter-spacing: .14em; text-transform: uppercase; color: var(--muted); text-align: left; background: var(--off); border-bottom: 1px solid var(--border); white-space: nowrap; }
        .data-table td { padding: .8rem 1rem; font-size: .84rem; color: var(--text); border-bottom: 1px solid var(--border); vertical-align: middle; }
        .data-table tbody tr:last-child td { border-bottom: none; }
        .data-table tbody tr:hover td { background: rgba(61,127,255,.025); }
        .empty-td { text-align: center; color: var(--muted); padding: 2.5rem !important; font-size: .84rem; }
        .empty-td a { color: var(--accent); text-decoration: none; }
        .item-code { font-family: 'DM Mono', monospace; font-size: .72rem; color: var(--accent); font-weight: 500; }
        .item-name { font-weight: 500; }
        .btn-row   { display: flex; gap: .35rem; flex-wrap: wrap; }

        /* ── BUTTONS ────────────────────────────────────────────── */
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: .45rem 1rem; border-radius: 7px; font-size: .78rem; font-weight: 600; cursor: pointer; border: 1px solid transparent; transition: all .2s; font-family: 'DM Sans', sans-serif; text-decoration: none; white-space: nowrap; }
        .btn svg { width: 13px; height: 13px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        .btn-primary  { background: var(--accent);              border-color: var(--accent);              color: #fff; }
        .btn-primary:hover { background: #2d6ee6; }
        .btn-ghost    { background: rgba(61,127,255,.08);        border-color: rgba(61,127,255,.2);        color: var(--accent); }
        .btn-ghost:hover { background: rgba(61,127,255,.18); }
        .btn-green    { background: rgba(21,128,61,.1);          border-color: rgba(21,128,61,.2);         color: #16a34a; }
        .btn-green:hover { background: rgba(21,128,61,.2); }
        .btn-amber    { background: rgba(217,119,6,.1);          border-color: rgba(217,119,6,.2);         color: #d97706; }
        .btn-amber:hover { background: rgba(217,119,6,.2); }
        .btn-red      { background: rgba(197,48,48,.1);          border-color: rgba(197,48,48,.2);         color: #dc2626; }
        .btn-red:hover { background: rgba(197,48,48,.2); }
        .btn-teal     { background: rgba(13,148,136,.1);         border-color: rgba(13,148,136,.2);        color: #0d9488; }
        .btn-teal:hover { background: rgba(13,148,136,.2); }
        .btn-outline  { background: var(--white);                border: 1.5px solid var(--border);        color: var(--text); }
        .btn-outline:hover { border-color: var(--accent); color: var(--accent); }
        .btn-sm { padding: .32rem .7rem; font-size: .74rem; }

        /* ── BADGES ─────────────────────────────────────────────── */
        .badge { display: inline-block; padding: .2rem .6rem; border-radius: 99px; font-family: 'DM Mono', monospace; font-size: .58rem; font-weight: 500; letter-spacing: .07em; text-transform: uppercase; }
        .badge-low,      .badge-danger,     .badge-error,      .badge-overdue,  .badge-cancelled, .badge-rejected  { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; }
        .badge-normal,   .badge-success,    .badge-active,     .badge-approved, .badge-completed, .badge-received  { background: #dcfce7; color: #16a34a; border: 1px solid #86efac; }
        .badge-high,     .badge-info,       .badge-sent,       .badge-confirmed                                     { background: #dbeafe; color: #2563eb; border: 1px solid #93c5fd; }
        .badge-warn,     .badge-warning,    .badge-pending,    .badge-draft,    .badge-scheduled, .badge-partial   { background: #fef9c3; color: #854d0e; border: 1px solid #fde68a; }
        .badge-inactive                                                                                              { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }

        /* ── MODAL ──────────────────────────────────────────────── */
        .modal { display: none; position: fixed; z-index: 1000; inset: 0; background: rgba(10,18,40,.65); backdrop-filter: blur(4px); align-items: flex-start; justify-content: center; overflow-y: auto; padding: 2rem 1rem; }
        .modal.open { display: flex; }
        .modal-box  { background: #fff; border-radius: 16px; width: 100%; max-width: 580px; box-shadow: 0 24px 60px rgba(0,0,0,.3); margin: auto; }
        .modal-box.wide { max-width: 820px; }
        .modal-head { display: flex; justify-content: space-between; align-items: center; padding: 1.4rem 1.6rem 1rem; border-bottom: 1px solid var(--border); }
        .modal-head h3 { font-size: 1rem; font-weight: 600; color: var(--text); }
        .modal-close { background: none; border: none; cursor: pointer; width: 28px; height: 28px; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: var(--muted); transition: background .15s, color .15s; }
        .modal-close:hover { background: var(--off); color: var(--text); }
        .modal-close svg { width: 16px; height: 16px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .modal-body { padding: 1.4rem 1.6rem 1.8rem; overflow-y: auto; max-height: calc(90vh - 130px); }
        .modal-body::-webkit-scrollbar { width: 6px; }
        .modal-body::-webkit-scrollbar-track { background: var(--off); border-radius: 99px; }
        .modal-body::-webkit-scrollbar-thumb { background: var(--border); border-radius: 99px; }
        .modal-foot { padding: 1rem 1.6rem; border-top: 1px solid var(--border); display: flex; gap: .75rem; justify-content: flex-end; }

        /* ── FORM ───────────────────────────────────────────────── */
        .form-row   { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .form-group { display: flex; flex-direction: column; gap: .4rem; margin-bottom: 1rem; }
        .form-group:last-child { margin-bottom: 0; }
        .form-label { font-size: .8rem; font-weight: 600; color: var(--text); }
        .form-label .req { color: #dc2626; margin-left: 2px; }
        .form-hint  { font-size: .72rem; color: var(--muted); }

        .form-input, .form-select, .form-textarea {
            width: 100%; padding: .6rem .85rem;
            border: 1.5px solid var(--border); border-radius: 8px;
            font-size: .84rem; font-family: 'DM Sans', sans-serif;
            background: var(--white); color: var(--text);
            transition: border-color .2s; outline: none;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(61,127,255,.1); }
        .form-input[readonly] { background: var(--off); cursor: not-allowed; color: var(--muted); }
        .form-textarea { resize: vertical; min-height: 72px; }

        .item-info-banner { background: var(--off); border: 1px solid var(--border); border-radius: 8px; padding: .75rem 1rem; font-size: .82rem; color: var(--muted); margin-bottom: 1.2rem; }
        .item-info-banner strong { color: var(--text); }

        .submit-btn { width: 100%; margin-top: 1rem; padding: .8rem; background: var(--accent); color: #fff; border: none; border-radius: 9px; font-size: .9rem; font-weight: 600; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: background .2s; }
        .submit-btn:hover { background: #2d6ee6; }
        .submit-btn:disabled { opacity: .6; cursor: not-allowed; }

        /* ── TOAST ──────────────────────────────────────────────── */
        #toast-container { position: fixed; top: 1.2rem; right: 1.2rem; z-index: 9999; display: flex; flex-direction: column; gap: .5rem; }
        .toast { display: flex; align-items: center; gap: 10px; padding: .85rem 1.2rem; border-radius: 10px; font-size: .84rem; font-weight: 500; max-width: 380px; box-shadow: 0 8px 24px rgba(0,0,0,.18); animation: toast-in .3s ease; cursor: pointer; }
        .toast svg { width: 16px; height: 16px; flex-shrink: 0; }
        .toast.success { background: #f0fdf4; border: 1px solid #86efac; color: #15803d; }
        .toast.error   { background: #fff1f2; border: 1px solid #fca5a5; color: #b91c1c; }
        .toast.info    { background: #eff6ff; border: 1px solid #93c5fd; color: #1d4ed8; }
        @keyframes toast-in { from{opacity:0;transform:translateX(20px)} to{opacity:1;transform:translateX(0)} }

        /* ── QUICK ACTIONS SIDEBAR ──────────────────────────────── */
        .qa-list  { display: flex; flex-direction: column; gap: .7rem; padding: 1.3rem 1.6rem; }
        .qa-btn   { display: flex; align-items: center; gap: 10px; padding: .75rem 1rem; border-radius: 9px; font-size: .84rem; font-weight: 500; cursor: pointer; border: 1px solid transparent; transition: all .2s; text-align: left; font-family: 'DM Sans', sans-serif; }
        .qa-btn svg { width: 16px; height: 16px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }
        .qa-blue   { background: rgba(61,127,255,.1);  border-color: rgba(61,127,255,.2);  color: var(--accent); }
        .qa-blue:hover   { background: rgba(61,127,255,.2); }
        .qa-green  { background: rgba(21,128,61,.1);   border-color: rgba(21,128,61,.2);   color: #16a34a; }
        .qa-green:hover  { background: rgba(21,128,61,.2); }
        .qa-orange { background: rgba(180,83,9,.1);    border-color: rgba(180,83,9,.2);    color: #d97706; }
        .qa-orange:hover { background: rgba(180,83,9,.2); }
        .qa-teal   { background: rgba(13,148,136,.1);  border-color: rgba(13,148,136,.2);  color: #0d9488; }
        .qa-teal:hover   { background: rgba(13,148,136,.2); }
        .qa-red    { background: rgba(197,48,48,.1);   border-color: rgba(197,48,48,.2);   color: #dc2626; }
        .qa-red:hover    { background: rgba(197,48,48,.2); }

        /* ── ALERT BANNERS ──────────────────────────────────────── */
        .alert { display: flex; align-items: flex-start; gap: 10px; padding: .85rem 1.1rem; border-radius: 10px; font-size: .87rem; line-height: 1.5; margin-bottom: 1.2rem; }
        .alert svg { width: 16px; height: 16px; stroke: currentColor; fill: none; stroke-width: 2; flex-shrink: 0; margin-top: 2px; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: var(--success); }
        .alert-error   { background: #fff5f5; border: 1px solid #fed7d7; color: var(--error); }
        .alert-warn    { background: #fffbeb; border: 1px solid #fde68a; color: var(--warn); }
        .alert-info    { background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8; }

        /* ── REPORT ─────────────────────────────────────────────── */
        .report-table { width: 100%; border-collapse: collapse; font-size: .8rem; }
        .report-table th { padding: .55rem .8rem; background: var(--off); border-bottom: 1px solid var(--border); text-align: left; font-weight: 600; color: var(--text); }
        .report-table td { padding: .55rem .8rem; border-bottom: 1px solid var(--border); color: var(--text); }
        .report-table tbody tr:last-child td { border-bottom: none; }
        .report-header  { display: flex; gap: .6rem; margin-bottom: 1.2rem; flex-wrap: wrap; }
        .report-tab     { padding: .42rem .9rem; border-radius: 7px; font-size: .78rem; font-weight: 600; cursor: pointer; border: 1px solid var(--border); background: var(--off); color: var(--muted); transition: all .2s; font-family: 'DM Sans', sans-serif; }
        .report-tab.active { background: var(--accent); border-color: var(--accent); color: #fff; }
        .report-empty   { text-align: center; padding: 2rem; color: var(--muted); font-size: .84rem; }

        /* ── MISC ───────────────────────────────────────────────── */
        .kbd-hint { font-family: 'DM Mono', monospace; font-size: .6rem; color: rgba(255,255,255,.3); padding: .15rem .4rem; border: 1px solid rgba(255,255,255,.1); border-radius: 4px; }
        .separator { height: 1px; background: var(--border); margin: 1.4rem 0; }

        /* Supplier / movement list items */
        .list-item { display: flex; justify-content: space-between; align-items: center; padding: .85rem 1.6rem; border-bottom: 1px solid var(--border); }
        .list-item:last-child { border-bottom: none; }
        .li-title  { font-size: .86rem; font-weight: 600; color: var(--text); }
        .li-sub    { font-size: .75rem; color: var(--muted); margin-top: 2px; }
        .li-value  { font-family: 'DM Mono', monospace; font-size: .84rem; font-weight: 500; color: var(--accent); }

        /* Movement badges */
        .mi-badge    { padding: .2rem .7rem; border-radius: 99px; font-family: 'DM Mono', monospace; font-size: .65rem; font-weight: 600; white-space: nowrap; }
        .mi-in       { background: #dcfce7; color: #16a34a; }
        .mi-out      { background: #fee2e2; color: #dc2626; }
        .mi-transfer { background: #dbeafe; color: #2563eb; }

        /* ── RESPONSIVE ─────────────────────────────────────────── */
        @media (max-width: 1100px) {
            .main-grid, .bottom-grid { grid-template-columns: 1fr; }
            .stats-row { grid-template-columns: repeat(2,1fr); }
        }
        @media (max-width: 700px) {
            .container, .main-content { padding: 1.5rem 1rem 3rem; }
            .header { padding: .9rem 1rem; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .form-row  { grid-template-columns: 1fr; }
        }
        @media (max-width: 500px) {
            .stats-row, .functions-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
