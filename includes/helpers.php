<?php
/**
 * includes/helpers.php
 * Shared utility / formatting helpers for both admin and staff modules.
 */

/** Safely output a value as HTML-escaped text. */
function h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Format a number as Philippine Peso. */
function peso(float $amount, int $decimals = 2): string {
    return '₱' . number_format($amount, $decimals);
}

/** Return a human-friendly relative time label. */
function time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', strtotime($datetime));
}

/** Format a date for display. */
function fmt_date(string|null $date, string $format = 'M j, Y'): string {
    if (!$date) return '—';
    return date($format, strtotime($date));
}

/**
 * Return a Bootstrap/Tailwind-style badge class for common status strings.
 * Returns array ['class' => '...', 'label' => '...']
 */
function status_badge(string $status): array {
    $map = [
        'active'           => ['good',  'Active'],
        'inactive'         => ['muted', 'Inactive'],
        'pending'          => ['pend',  'Pending'],
        'pending_approval' => ['pend',  'Pending Approval'],
        'approved'         => ['good',  'Approved'],
        'rejected'         => ['error', 'Rejected'],
        'completed'        => ['good',  'Completed'],
        'in_progress'      => ['blue',  'In Progress'],
        'on_hold'          => ['warn',  'On Hold'],
        'cancelled'        => ['error', 'Cancelled'],
        'draft'            => ['muted', 'Draft'],
        'sent'             => ['blue',  'Sent'],
        'confirmed'        => ['good',  'Confirmed'],
        'expired'          => ['error', 'Expired'],
        'maintenance'      => ['warn',  'Maintenance'],
        'disposed'         => ['muted', 'Disposed'],
        'overdue'          => ['error', 'Overdue'],
        'scheduled'        => ['pend',  'Scheduled'],
        'low'              => ['good',  'Low'],
        'medium'           => ['warn',  'Medium'],
        'high'             => ['error', 'High'],
        'critical'         => ['error', 'Critical'],
    ];
    $status_lower = strtolower($status);
    return $map[$status_lower] ?? ['muted', ucfirst($status)];
}

/** Return inline CSS style badge based on status_badge() result. */
function render_badge(string $status): string {
    $b = status_badge($status);
    $colour_map = [
        'good'  => ['background:#dcfce7;color:#15803d',  'Active'],
        'muted' => ['background:#f1f5f9;color:#6b7a99',  ''],
        'pend'  => ['background:#ede9fe;color:#6366f1',  ''],
        'error' => ['background:#fee2e2;color:#c53030',  ''],
        'warn'  => ['background:#fef3c7;color:#b45309',  ''],
        'blue'  => ['background:#dbeafe;color:#2563eb',  ''],
    ];
    [$style] = $colour_map[$b['class']] ?? ['background:#f1f5f9;color:#6b7a99'];
    $label   = h($b['label']);
    return "<span style=\"display:inline-block;padding:.18rem .55rem;border-radius:99px;font-size:.72rem;font-weight:600;letter-spacing:.04em;$style\">$label</span>";
}

/**
 * Build a safe pagination array.
 * Returns ['page', 'limit', 'offset', 'total_pages']
 */
function paginate(int $total_records, int $limit = 20): array {
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $total = max(1, ceil($total_records / $limit));
    $page  = min($page, $total);
    return [
        'page'        => $page,
        'limit'       => $limit,
        'offset'      => ($page - 1) * $limit,
        'total_pages' => $total,
        'total'       => $total_records,
    ];
}

/** JSON response helper for AJAX endpoints. Exits after output. */
function json_response(bool $success, string $message = '', array $data = []): void {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit();
}

/** Redirect with a flash message stored in session. */
function redirect_with_flash(string $url, string $type, string $message): void {
    $_SESSION['flash_type']    = $type;   // 'success' | 'error' | 'warning'
    $_SESSION['flash_message'] = $message;
    header("Location: $url");
    exit();
}

/** Render and clear any pending flash message. Returns HTML string. */
function render_flash(): string {
    if (empty($_SESSION['flash_message'])) return '';
    $type = $_SESSION['flash_type'] ?? 'success';
    $msg  = h($_SESSION['flash_message']);
    unset($_SESSION['flash_type'], $_SESSION['flash_message']);

    $styles = [
        'success' => 'background:#f0fdf4;border:1px solid #86efac;color:#15803d',
        'error'   => 'background:#fef2f2;border:1px solid #fca5a5;color:#c53030',
        'warning' => 'background:#fffbeb;border:1px solid #fde68a;color:#b45309',
    ];
    $style = $styles[$type] ?? $styles['success'];
    return "<div style=\"display:flex;align-items:center;gap:10px;padding:.85rem 1.1rem;border-radius:10px;margin-bottom:1.5rem;font-size:.87rem;$style\">
        <svg viewBox=\"0 0 24 24\" style=\"width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;flex-shrink:0\">
            <polyline points=\"20 6 9 17 4 12\"/>
        </svg>$msg</div>";
}
