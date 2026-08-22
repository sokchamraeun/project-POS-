<?php
require_once __DIR__ . '/../config.php';

$tables = ['product_ingredients', 'ingredients', 'recipes', 'recipe_items'];
foreach ($tables as $t) {
    $res = $conn->query("SHOW TABLES LIKE '$t'");
    if ($res && $res->num_rows > 0) {
        echo "Table $t EXISTS!\n";
        $d = $conn->query("DESCRIBE $t");
        while ($row = $d->fetch_assoc()) {
            echo "  - {$row['Field']} ({$row['Type']})\n";
        }
    }
}
