<?php
require_once __DIR__ . '/../config.php';

$conn->begin_transaction();
try {
    $lossOrders = [];
    $q = $conn->query("
        SELECT o.order_id, o.total,
               (SUM(oi.quantity * oi.price) - SUM(oi.quantity * COALESCE(p.cost_price, 0))) AS total_profit
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.order_id
        LEFT JOIN products p ON p.product_id = oi.product_id
        GROUP BY o.order_id, o.total
        HAVING total_profit < 0
    ");

    while ($r = $q->fetch_assoc()) {
        $lossOrders[] = (int)$r['order_id'];
    }

    if (!empty($lossOrders)) {
        $in = implode(',', $lossOrders);
        $conn->query("DELETE FROM order_items WHERE order_id IN ($in)");
        $conn->query("DELETE FROM order_payments WHERE order_id IN ($in)");
        $conn->query("DELETE FROM order_cancellations WHERE order_id IN ($in)");
        $conn->query("DELETE FROM inventory_stock_logs WHERE order_id IN ($in)");
        $conn->query("DELETE FROM stock_logs WHERE order_id IN ($in)");
        $conn->query("DELETE FROM orders WHERE order_id IN ($in)");
        $conn->commit();
        echo "Successfully deleted " . count($lossOrders) . " loss orders: " . $in . "\n";
    } else {
        $conn->commit();
        echo "No loss orders found to delete.\n";
    }
} catch (Exception $e) {
    $conn->rollback();
    echo "Error deleting loss orders: " . $e->getMessage() . "\n";
}
