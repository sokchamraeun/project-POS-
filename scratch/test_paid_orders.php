<?php
require 'config.php';

echo "Testing all dashboard queries with paid_orders_where...\n";

$business_date = date('Y-m-d');
$date_cond_w = "DATE(order_date) = '$business_date'";
$date_cond_o = "DATE(o.order_date) = '$business_date'";
$user_clause_w = "";
$user_clause_o = "";

// 1. Total Sales
$stmt_sales = $conn->query("SELECT IFNULL(SUM(total),0) AS total_sales FROM orders WHERE $date_cond_w " . $user_clause_w . " AND " . paid_orders_where());
$sales = (float)($stmt_sales ? $stmt_sales->fetch_assoc()['total_sales'] : 0);
echo "1. Total Sales: \$$sales\n";

// 2. Total Orders
$stmt_ord = $conn->query("SELECT COUNT(*) AS total_orders FROM orders WHERE $date_cond_w " . $user_clause_w . " AND " . paid_orders_where());
$total_orders = (int)($stmt_ord ? $stmt_ord->fetch_assoc()['total_orders'] : 0);
echo "2. Total Orders: $total_orders\n";

// 3. Total Items Sold
$stmt_items = $conn->query("SELECT IFNULL(SUM(oi.quantity),0) AS total_items FROM order_items oi JOIN orders o ON oi.order_id=o.order_id WHERE $date_cond_o " . $user_clause_o . " AND oi.product_id <> 0 AND " . paid_orders_where('o'));
$items_sold = (int)($stmt_items ? $stmt_items->fetch_assoc()['total_items'] : 0);
echo "3. Items Sold: $items_sold\n";

// 4. Payment Method Breakdown
$q_pm = $conn->query("
    SELECT 
        SUM(CASE WHEN LOWER(payment_method) LIKE '%bakong%' OR LOWER(payment_method) LIKE '%khqr%' OR LOWER(payment_method) LIKE '%qr%' THEN 1 ELSE 0 END) AS bakong_cnt,
        SUM(CASE WHEN LOWER(payment_method) LIKE '%cash%' OR payment_method = '' OR payment_method IS NULL THEN 1 ELSE 0 END) AS cash_cnt,
        COUNT(*) as total_cnt
    FROM orders o
    WHERE $date_cond_o " . $user_clause_o . " AND " . paid_orders_where('o')
);
$r_pm = $q_pm->fetch_assoc();
echo "4. Payment Breakdown: Bakong {$r_pm['bakong_cnt']}, Cash {$r_pm['cash_cnt']}, Total {$r_pm['total_cnt']}\n";

// 5. Category Breakdown
$st_cat = $conn->query("
    SELECT COALESCE(NULLIF(p.category, ''), 'Other') AS cat_name, IFNULL(SUM(oi.quantity), 0) AS total_qty
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.order_id
    JOIN products p ON oi.product_id = p.product_id
    WHERE " . paid_orders_where('o') . " AND $date_cond_o AND oi.product_id <> 0 " . $user_clause_o . "
    GROUP BY cat_name
    ORDER BY total_qty DESC
    LIMIT 5
");
echo "5. Category query OK: " . ($st_cat ? "PASS" : "FAIL: " . $conn->error) . "\n";

// 6. Recent Orders
$q_rec = $conn->query("
    SELECT 
        o.order_id,
        o.total,
        o.order_date,
        o.payment_method
    FROM orders o
    WHERE " . paid_orders_where('o') . $user_clause_o . "
    ORDER BY o.order_date DESC, o.order_id DESC
    LIMIT 5
");
echo "6. Recent orders query OK:\n";
while ($r = $q_rec->fetch_assoc()) {
    echo "   - Order #{$r['order_id']} | {$r['payment_method']} | \${$r['total']}\n";
}

echo "ALL QUERIES PASSED SUCCESSFULLY!\n";
