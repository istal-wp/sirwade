<?php
/**
 * BRIGHTPATH — Shared Topbar/Header component.
 * Usage: $module_subtitle = 'Procurement'; include 'includes/topbar.php';
 *
 * Variables consumed (optional, with defaults):
 *   $module_subtitle  — small text under BRIGHTPATH logo
 *   $show_back_btn    — bool (default true)
 *   $back_btn_href    — string (default 'dashboard.php')
 *   $back_btn_label   — string (default 'Dashboard')
 */
$module_subtitle = $module_subtitle ?? 'Staff Portal';
$show_back_btn   = $show_back_btn   ?? true;
$back_btn_href   = $back_btn_href   ?? 'dashboard.php';
$back_btn_label  = $back_btn_label  ?? 'Dashboard';

$__initial = strtoupper(substr($user_name ?? 'U', 0, 1));
?>
<header class="header">
    <div class="header-inner">
        <div class="header-left">
            <a href="dashboard.php" class="brand">
                <div class="brand-mark">
                    <svg viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                </div>
                <div class="brand-text">
                    <h1>BRIGHTPATH</h1>
                    <p><?php echo htmlspecialchars($module_subtitle); ?></p>
                </div>
            </a>
            <?php if ($show_back_btn): ?>
            <a href="<?php echo htmlspecialchars($back_btn_href); ?>" class="btn-back">
                <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
                <?php echo htmlspecialchars($back_btn_label); ?>
            </a>
            <?php endif; ?>
        </div>
        <div class="header-right">
            <div class="profile-wrap" id="profileWrap">
                <div class="user-pill">
                    <div class="user-avatar"><?php echo $__initial; ?></div>
                    <span class="user-name"><?php echo htmlspecialchars($user_name ?? 'User'); ?></span>
                    <svg class="pill-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
                <div class="profile-dropdown">
                    <div class="pd-head">
                        <div class="pd-avatar"><?php echo $__initial; ?></div>
                        <div>
                            <div class="pd-info-name"><?php echo htmlspecialchars($user_name ?? ''); ?></div>
                            <div class="pd-info-email"><?php echo htmlspecialchars($user_email ?? ''); ?></div>
                        </div>
                    </div>
                    <div class="pd-body">
                        <div class="pd-row">
                            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <span class="pd-row-label">Role</span>
                            <span class="pd-row-val">
                                <span class="pd-role-badge staff"><?php echo ucfirst($user_role ?? 'staff'); ?></span>
                            </span>
                        </div>
                        <div class="pd-row">
                            <svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 7l10 7 10-7"/></svg>
                            <span class="pd-row-label">Email</span>
                            <span class="pd-row-val" style="font-size:.75rem;word-break:break-all"><?php echo htmlspecialchars($user_email ?? '—'); ?></span>
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
<!-- Toast container -->
<div id="toast-container"></div>
