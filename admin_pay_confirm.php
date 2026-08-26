<?php
require 'auth.php';
require_once 'config.php';

$order_id = (int)($_GET['order_id'] ?? 0);

if ($order_id <= 0) {
    header("Location: find_order.php");
    exit;
}

// Determine correct next status
$stmt_cur = $conn->prepare("SELECT status, payment_method, table_number, completed_at FROM orders WHERE order_id = ?");
$stmt_cur->bind_param("i", $order_id);
$stmt_cur->execute();
$cur = $stmt_cur->get_result()->fetch_assoc();
if ($cur && $cur['payment_method'] === 'paylater') {
    // 'Paid' is the SETTLED state for a pay-later tab and must stay that way:
    // find_order.php lists paylater orders still in ('Preparing','PendingPayment',
    // 'Completed') as outstanding debt, so leaving a settled order on 'Completed'
    // puts it back in the unpaid list. Fulfilment is shown from completed_at, not
    // from this column.
    $new_status = 'Paid';
} else {
    $new_status = ($cur && $cur['status'] === 'PendingPayment') ? 'Preparing' : 'Completed';
}

$stmt = $conn->prepare("UPDATE orders SET status = ?, is_open = 0 WHERE order_id = ?");
$stmt->bind_param("si", $new_status, $order_id);
$stmt->execute();

header("Location: find_order.php?paid=" . $order_id);
exit;
?>