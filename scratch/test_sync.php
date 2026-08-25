<?php
require_once __DIR__ . '/../config.php';

$res = $conn->query("SELECT product_id, name, price, cost_price, image FROM products");
$synced = 0;
while ($p = $res->fetch_assoc()) {
    if (sync_product_to_stock_item($conn, $p['name'], (float)$p['price'], (float)$p['cost_price'], $p['image'], (int)$p['product_id'])) {
        $synced++;
        echo "Synced: {$p['name']} -> \${$p['price']}\n";
    }
}
echo "Total direct drink items synced: {$synced}\n";
