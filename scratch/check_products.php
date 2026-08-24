<?php
require 'config.php';

echo "=== ALL PRODUCTS ===\n";
$r = $conn->query("SELECT product_id, name, category, price, is_available FROM products ORDER BY product_id DESC LIMIT 20");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        echo "ID: {$row['product_id']} | Name: {$row['name']} | Category: {$row['category']} | Price: {$row['price']}\n";
    }
}
