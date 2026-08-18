<?php
require 'auth.php';
require 'config.php';

$order_id   = (int)($_GET['order_id'] ?? 0);
$new_status = $_GET['status'] ?? '';
$is_ajax    = isset($_GET['ajax']);

/** Respond as JSON for AJAX callers, else fall back to a redirect. Always exits. */
function us_respond(bool $ok, string $msg, bool $is_ajax, string $err_qs = ''): void {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok, 'error' => $ok ? null : $msg]);
    } else {
        header('Location: dashboard.php' . ($ok ? '' : $err_qs));
    }
    exit;
}

// ── Allowed transitions ──
$allowed_transitions = [
    'Preparing'      => ['Paid', 'Completed', 'Cancelled'],
    'Paid'           => ['Completed', 'Cancelled'],
    'PendingPayment' => ['Paid', 'Cancelled'],
];

if ($order_id <= 0 || empty($new_status)) {
    us_respond(false, 'Invalid request.', $is_ajax);
}

// ── Authorization (per action) ──
// Marking a drink "Completed" is the barista-station action → anyone holding
// barista_station (barista, manager, admin). Any other transition (Paid,
// Cancelled) stays manager/admin-only.
$role   = $_SESSION['role'] ?? '';
$is_mgr = in_array($role, ['admin', 'manager'], true);
$authorized = ($new_status === 'Completed') ? can('barista_station') : $is_mgr;

if (!$authorized) {
    http_response_code(403);
    us_respond(false, 'You are not allowed to perform this action.', $is_ajax, '?denied=1');
}

// Mark every drink made on completion — bring made_qty up to quantity (and stamp made_at)
if ($new_status === 'Completed') {
    $stmt_made = $conn->prepare("UPDATE order_items SET made_qty = quantity, made_at = NOW() WHERE order_id = ? AND made_qty < quantity");
    $stmt_made->bind_param("i", $order_id);
    $stmt_made->execute();
}

us_respond(true, '', $is_ajax);
