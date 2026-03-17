<?php
/**
 * BRIGHTPATH — Shared Sidebar Navigation.
 * Usage: $active_nav = 'sws'; include 'includes/sidebar.php';
 *
 * Valid $active_nav values:
 *   dashboard | sws | psm | plt | alms | dtlrs
 */
$active_nav = $active_nav ?? 'dashboard';

$nav_items = [
    'dashboard' => [
        'href'  => 'dashboard.php',
        'label' => 'Dashboard',
        'icon'  => '<path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
    ],
    'sws' => [
        'href'  => 'sws.php',
        'label' => 'Smart Warehousing',
        'icon'  => '<path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
    ],
    'psm' => [
        'href'  => 'psm.php',
        'label' => 'Procurement',
        'icon'  => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 001.97-1.67L23 6H6"/>',
    ],
    'plt' => [
        'href'  => 'plt.php',
        'label' => 'Project Tracker',
        'icon'  => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    ],
    'alms' => [
        'href'  => 'alms.php',
        'label' => 'Asset Lifecycle',
        'icon'  => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/>',
    ],
    'dtlrs' => [
        'href'  => 'dtlrs.php',
        'label' => 'Document Tracking',
        'icon'  => '<path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/>',
    ],
];
?>
<nav class="sidebar" id="sidebar">
    <div class="sidebar-inner">
        <span class="sidebar-label">Navigation</span>
        <?php foreach ($nav_items as $key => $item): ?>
        <a href="<?php echo $item['href']; ?>" class="nav-link <?php echo ($active_nav === $key) ? 'active' : ''; ?>">
            <svg class="nav-icon" viewBox="0 0 24 24"><?php echo $item['icon']; ?></svg>
            <?php echo $item['label']; ?>
        </a>
        <?php endforeach; ?>
    </div>
</nav>
