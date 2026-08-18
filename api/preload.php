<?php
session_start();
require '../config.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

header('Content-Type: application/json');

$role = $_SESSION['role'] ?? 'staff';
$data = ['role' => $role, 'loaded_at' => time()];

switch ($role) {
    case 'admin':
    case 'manager':
    case 'supervisor':
    case 'staff':
        $r = $conn->query("SELECT IFNULL(SUM(total),0) AS sales, COUNT(*) AS orders FROM orders WHERE DATE(order_date)=CURDATE() AND " . paid_orders_where());
        $row = $r->fetch_assoc();
        $data['sales_today']   = (float)$row['sales'];
        $data['orders_today']  = (int)$row['orders'];

        $r2 = $conn->query("SELECT COUNT(*) AS cnt FROM orders WHERE status IN ('PendingPayment','Preparing')");
        $data['active_orders'] = (int)$r2->fetch_assoc()['cnt'];

        $data['low_stock'] = 0;
        break;

    case 'barista':
        $r = $conn->query("SELECT order_id, daily_order_no, customer_name, token_number, status, order_date FROM orders WHERE status IN ('PendingPayment','Preparing') ORDER BY order_date ASC LIMIT 15");
        $orders = [];
        while ($row = $r->fetch_assoc()) $orders[] = $row;
        $data['queue']       = $orders;
        $data['queue_count'] = count($orders);
        break;

    case 'inventory_clerk':
        $data['total_ingredients'] = 0;
        $data['low_stock'] = 0;
        break;
}

echo json_encode($data);
