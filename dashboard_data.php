<?php
require 'auth.php';
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Every "today" below means the trading day (06:00 to 06:00), read from the
// business_date column — the same definition dashboard.php renders with.
//
// This file used to filter on DATE(order_date) = CURDATE(). Because it is
// polled every 5 seconds and writes straight into the KPI cards, it quietly
// replaced the correct figures with calendar-date ones a few seconds after
// every page load. The two disagree on 186 orders, and disagree about every
// order between midnight and 06:00 — the tail of a trading day belongs to the
// day before, which is exactly when a late shift is watching this screen.
$business_date = business_date_today();

// ── TODAY SALES ──
$sales_sql = "
SELECT IFNULL(SUM(total),0) AS total_sales
FROM orders
WHERE business_date = ? AND " . paid_orders_where() . "
";
$stmt = $conn->prepare($sales_sql);
$stmt->bind_param("s", $business_date);
$stmt->execute();
$sales = $stmt->get_result()->fetch_assoc()['total_sales'];

// ── TOTAL ORDERS TODAY ──
$stmt = $conn->prepare("SELECT COUNT(*) AS total_orders FROM orders WHERE business_date = ?");
$stmt->bind_param("s", $business_date);
$stmt->execute();
$total_orders = $stmt->get_result()->fetch_assoc()['total_orders'];

// ── UNPAID ORDERS COUNT ──
$unpaid_sql = "
SELECT COUNT(*) AS unpaid_count
FROM orders
WHERE status = 'PendingPayment'
";
$unpaid_result = mysqli_query($conn, $unpaid_sql);
$unpaid_count = mysqli_fetch_assoc($unpaid_result)['unpaid_count'];

// ── LOW STOCK ──
$low_sql = "
SELECT COUNT(*) AS low_count
FROM ingredients
WHERE stock_quantity < minimum_stock
";
$low_result = mysqli_query($conn, $low_sql);
$low_stock = mysqli_fetch_assoc($low_result)['low_count'];

// ── REFUND DATA (NEW) ──
$refund_sql = "
SELECT
    IFNULL(SUM(refund_amount), 0) AS total_refunds,
    COUNT(*) AS refund_count
FROM order_refunds
WHERE DATE(refunded_at) = CURDATE()
";
$refund_result = mysqli_query($conn, $refund_sql);
$refund_data = mysqli_fetch_assoc($refund_result);
$total_refunds = $refund_data['total_refunds'];
$refund_count = $refund_data['refund_count'];

// ── STATUS COUNTS ──
$stmt = $conn->prepare("SELECT status, COUNT(*) as count FROM orders WHERE business_date = ? GROUP BY status");
$stmt->bind_param("s", $business_date);
$stmt->execute();
$status_result = $stmt->get_result();
$status_counts = [];
while ($row = mysqli_fetch_assoc($status_result)) {
    $status_counts[$row['status']] = $row['count'];
}

$pending_count = $status_counts['PendingPayment'] ?? 0;
$paid_count = $status_counts['Preparing'] ?? 0;
$preparing_count = $status_counts['Preparing'] ?? 0;
$completed_count = $status_counts['Completed'] ?? 0;
$cancelled_count = $status_counts['Cancelled'] ?? 0;

// ── UNPAID ORDERS LIST (LIMIT 5) ──
$unpaid_orders_sql = "
SELECT order_id, daily_order_no, customer_name, total, status, DATE_FORMAT(order_date, '%d %b %H:%i') as date, is_open
FROM orders
WHERE status = 'PendingPayment'
ORDER BY order_date DESC
LIMIT 5
";
$unpaid_orders_result = mysqli_query($conn, $unpaid_orders_sql);
$unpaid_orders = [];
while ($row = mysqli_fetch_assoc($unpaid_orders_result)) {
    $unpaid_orders[] = $row;
}

// ── KITCHEN QUEUE (LIMIT 5) ──
$stmt = $conn->prepare("
SELECT order_id, daily_order_no, customer_name, total, token_number, order_date
FROM orders
WHERE business_date = ?
AND status = 'Preparing'
ORDER BY order_date ASC
LIMIT 5
");
$stmt->bind_param("s", $business_date);
$stmt->execute();
$kitchen_result = $stmt->get_result();
$kitchen_orders = [];
while ($row = mysqli_fetch_assoc($kitchen_result)) {
    $kitchen_orders[] = $row;
}

// ── RETURN JSON ──
header('Content-Type: application/json');
echo json_encode([
    'sales' => number_format($sales, 2),
    'total_orders' => $total_orders,
    'unpaid_count' => $unpaid_count,
    'low_stock' => $low_stock,
    'total_refunds' => number_format($total_refunds, 2),
    'refund_count' => $refund_count,
    'pending_count' => $pending_count,
    'paid_count' => $paid_count,
    'preparing_count' => $preparing_count,
    'completed_count' => $completed_count,
    'cancelled_count' => $cancelled_count,
    'unpaid_orders' => $unpaid_orders,
    'kitchen_orders' => $kitchen_orders
]);
?>