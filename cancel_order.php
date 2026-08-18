<?php
session_start();
require 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["ok" => 0, "error" => "Please login first"]);
    exit;
}

if (!in_array($_SESSION['role'], ['admin', 'manager', 'supervisor', 'staff'])) {
    echo json_encode(["ok" => 0, "error" => "You don't have permission to cancel orders"]);
    exit;
}

$order_id = (int)($_GET['order_id'] ?? 0);
if ($order_id <= 0) {
    echo json_encode(["ok" => 0, "error" => "Invalid order ID"]);
    exit;
}

$stmt = $conn->prepare("SELECT order_id, order_id AS daily_order_no, 'Guest' AS customer_name, total, 'Completed' AS status, 0 AS is_open, 0 AS loyalty_card_id, 0 AS points_earned FROM orders WHERE order_id = ?");
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

$reason        = trim($_POST['cancel_reason'] ?? '');
$restore_stock = isset($_POST['restore_stock']) ? 1 : 0;

if (empty($reason)) {
    echo json_encode(["ok" => 0, "error" => "Please provide a reason for cancellation"]);
    exit;
}

$conn->begin_transaction();

try {
    $stmt_can = $conn->prepare("INSERT INTO order_cancellations (order_id, cancel_reason, cancelled_at, cancelled_by) VALUES (?, ?, NOW(), ?)");
    $stmt_can->bind_param("iss", $order_id, $reason, $_SESSION['username']);
    $stmt_can->execute();

    if ($restore_stock) {
        _restore_stock($conn, $order_id);
    }

    $conn->commit();

    echo json_encode(["ok" => 1, "message" => "Order #{$order['daily_order_no']} cancelled successfully"]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["ok" => 0, "error" => $e->getMessage()]);
}
exit;

function _restore_stock(mysqli $conn, int $order_id): void {
}
