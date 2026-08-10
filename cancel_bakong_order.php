<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$order_id = (int)($_GET['order_id'] ?? $_GET['bakong_order_id'] ?? 0);

// 1. Restore cart items
if (!empty($_SESSION['bakong_cart_stash'])) {
    $_SESSION['cart'] = $_SESSION['bakong_cart_stash'];
    unset($_SESSION['bakong_cart_stash']);
} elseif ($order_id > 0) {
    // Fallback: rebuild cart from order_items in DB
    $stmt = $conn->prepare("
        SELECT product_id, product_name, price, quantity, size_label, sweetness, ice, milk, addons_snapshot, promo_percent, orig_price, earns_points
        FROM order_items
        WHERE order_id = ?
    ");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (!empty($items)) {
        $_SESSION['cart'] = [];
        foreach ($items as $it) {
            $_SESSION['cart'][] = [
                'product_id'    => (int)$it['product_id'],
                'name'          => $it['product_name'],
                'price'         => (float)$it['price'],
                'qty'           => (int)$it['quantity'],
                'size_label'    => $it['size_label'] ?? '',
                'sweetness'     => $it['sweetness'] ?? '',
                'ice'           => $it['ice'] ?? '',
                'milk'          => $it['milk'] ?? '',
                'addons'        => json_decode($it['addons_snapshot'] ?? '[]', true) ?: [],
                'promo_percent' => (int)($it['promo_percent'] ?? 0),
                'orig_price'    => (float)($it['orig_price'] ?? $it['price']),
                'earns_points'  => (int)($it['earns_points'] ?? 1),
            ];
        }
    }
}

// 2. Remove unpaid pending order
if ($order_id > 0) {
    $stmt_del_items = $conn->prepare("DELETE FROM order_items WHERE order_id = ?");
    $stmt_del_items->bind_param("i", $order_id);
    $stmt_del_items->execute();

    $stmt_del_pay = $conn->prepare("DELETE FROM order_payments WHERE order_id = ?");
    $stmt_del_pay->bind_param("i", $order_id);
    $stmt_del_pay->execute();

    $stmt_del_ord = $conn->prepare("DELETE FROM orders WHERE order_id = ? AND status != 'Completed'");
    $stmt_del_ord->bind_param("i", $order_id);
    $stmt_del_ord->execute();
}

header("Location: menu.php");
exit;
