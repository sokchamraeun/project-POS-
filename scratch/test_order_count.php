<?php
require_once __DIR__ . '/../config.php';

$d_start = '2026-08-01';
$d_end   = '2026-08-31';

// 1. Dashboard old query:
$q1 = $conn->query("SELECT COUNT(*) AS total_orders FROM orders WHERE DATE(order_date) BETWEEN '$d_start' AND '$d_end' AND " . paid_orders_where());
echo "1. Dashboard old paid_orders_where query: " . $q1->fetch_assoc()['total_orders'] . "\n";

// 2. daily_report query (matches report page):
$q2 = $conn->query("
    SELECT COUNT(o.order_id) AS total_orders 
    FROM orders o 
    LEFT JOIN order_cancellations oc ON oc.order_id = o.order_id 
    WHERE o.order_date BETWEEN '$d_start 00:00:00' AND '$d_end 23:59:59' 
      AND oc.order_id IS NULL
");
echo "2. Daily report query: " . $q2->fetch_assoc()['total_orders'] . "\n";
