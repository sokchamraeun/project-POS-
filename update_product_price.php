<?php
require 'admin_only.php';
require 'config.php';
header('Content-Type: application/json');

$id    = (int)($_POST['product_id'] ?? 0);
$price = round((float)($_POST['price'] ?? -1), 2);

if ($id <= 0 || $price < 0 || $price > 9999.99) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
}

$stmt = $conn->prepare("UPDATE products SET price = ? WHERE product_id = ?");
$stmt->bind_param('di', $price, $id);
$stmt->execute();
$stmt->close();

// Fetch product details to auto-sync to Stock Drink (stock_items)
$pQuery = $conn->prepare("SELECT name, cost_price FROM products WHERE product_id = ?");
if ($pQuery) {
    $pQuery->bind_param('i', $id);
    $pQuery->execute();
    $pRow = $pQuery->get_result()->fetch_assoc();
    $pQuery->close();

    if ($pRow && function_exists('sync_product_to_stock_item')) {
        sync_product_to_stock_item($conn, $pRow['name'], $price, $pRow['cost_price'] ?? null, null, $id);
    }
}

echo json_encode(['ok' => true, 'price' => $price]);
