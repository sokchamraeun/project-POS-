<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

// Only admins and managers can run this cleanup
if (!in_array($_SESSION['role'] ?? '', ['admin', 'manager'])) {
    if (isset($_GET['ajax']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
        exit;
    }
    header("Location: dashboard.php?denied=1");
    exit;
}

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

    if ($q) {
        while ($r = $q->fetch_assoc()) {
            $lossOrders[] = (int)$r['order_id'];
        }
    }

    $deletedCount = count($lossOrders);

    if ($deletedCount > 0) {
        $in = implode(',', $lossOrders);
        $conn->query("DELETE FROM order_items WHERE order_id IN ($in)");
        $conn->query("DELETE FROM order_payments WHERE order_id IN ($in)");
        $conn->query("DELETE FROM order_cancellations WHERE order_id IN ($in)");
        $conn->query("DELETE FROM inventory_stock_logs WHERE order_id IN ($in)");
        $conn->query("DELETE FROM stock_logs WHERE order_id IN ($in)");
        $conn->query("DELETE FROM orders WHERE order_id IN ($in)");
        $conn->commit();
        $msg = "Successfully checked and automatically deleted {$deletedCount} loss orders.";
    } else {
        $conn->commit();
        $msg = "Checked: No orders with negative profit found.";
    }

    if (isset($_GET['ajax']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'deleted_count' => $deletedCount,
            'order_ids' => $lossOrders,
            'message' => $msg
        ]);
        exit;
    }

    // Direct browser visit: redirect back to daily_report with success notice
    header("Location: daily_report.php?cleaned=" . $deletedCount);
    exit;

} catch (Exception $e) {
    $conn->rollback();
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
    die("Error during auto-deletion: " . $e->getMessage());
}
