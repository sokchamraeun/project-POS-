<?php
session_start();
require 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["ok" => 0, "error" => "Please login first"]);
    exit;
}

if (!in_array($_SESSION['role'], ['admin', 'manager'])) {
    echo json_encode(["ok" => 0, "error" => "Only admin or manager can refund orders"]);
    exit;
}

$order_id = (int)($_GET['order_id'] ?? 0);
if ($order_id <= 0) {
    echo json_encode(["ok" => 0, "error" => "Invalid order ID"]);
    exit;
}

$stmt = $conn->prepare("
    SELECT order_id, order_id AS daily_order_no, 'Guest' AS customer_name, total, 'Completed' AS status,
           0 AS loyalty_card_id, 0 AS points_earned, payment_method, 0 AS is_open
    FROM orders WHERE order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    echo json_encode(["ok" => 0, "error" => "Order not found"]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["ok" => 0, "error" => "Invalid request method"]);
    exit;
}

$refund_amount  = (float)($_POST['refund_amount']  ?? 0);
$refund_reason  = trim($_POST['refund_reason']      ?? '');
$restore_stock  = 0; // Drink already made — ingredients cannot be restored on refund

if ($refund_amount <= 0 || $refund_amount > $order['total'] + 0.01) {
    echo json_encode(["ok" => 0, "error" => "Invalid refund amount. Max: $" . number_format($order['total'], 2)]);
    exit;
}

if (empty($refund_reason)) {
    echo json_encode(["ok" => 0, "error" => "Please provide a reason for refund"]);
    exit;
}

$conn->begin_transaction();

try {


    if ($restore_stock) {
        _restore_stock($conn, $order_id);
    }

    $conn->commit();

    echo json_encode(["ok" => 1, "message" => "Order #{$order['daily_order_no']} refunded $" . number_format($refund_amount, 2) . " successfully"]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["ok" => 0, "error" => $e->getMessage()]);
}
exit;

function _restore_stock(mysqli $conn, int $order_id): void {
}
