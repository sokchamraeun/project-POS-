<?php
require_once __DIR__ . '/../config.php';

echo "=== PRODUCT_RECIPES ===\n";
$r = $conn->query("DESCRIBE product_recipes");
if ($r) {
    while ($row = $r->fetch_assoc()) echo "{$row['Field']} ({$row['Type']})\n";
}

echo "\n=== INVENTORY_ITEMS ===\n";
$r = $conn->query("DESCRIBE inventory_items");
if ($r) {
    while ($row = $r->fetch_assoc()) echo "{$row['Field']} ({$row['Type']})\n";
}

echo "\n=== SAMPLE PRODUCTS WITH COST ===\n";
$r = $conn->query("SELECT product_id, name, price, cost_price, category FROM products LIMIT 10");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        echo "ID: {$row['product_id']} | Name: {$row['name']} | Price: \${$row['price']} | CostPrice: \${$row['cost_price']} | Cat: {$row['category']}\n";
    }
}
