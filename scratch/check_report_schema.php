<?php
require_once __DIR__ . '/../config.php';

echo "=== PRODUCTS SCHEMA ===\n";
$r = $conn->query("DESCRIBE products");
while ($row = $r->fetch_assoc()) {
    echo "{$row['Field']} ({$row['Type']})\n";
}

echo "\n=== ORDER_ITEMS SCHEMA ===\n";
$r = $conn->query("DESCRIBE order_items");
while ($row = $r->fetch_assoc()) {
    echo "{$row['Field']} ({$row['Type']})\n";
}

echo "\n=== CATEGORIES SCHEMA ===\n";
$r = $conn->query("DESCRIBE categories");
while ($row = $r->fetch_assoc()) {
    echo "{$row['Field']} ({$row['Type']})\n";
}
