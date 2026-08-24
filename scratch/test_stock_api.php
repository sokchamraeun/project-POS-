<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['action'] = 'get_stock_data';

// We can test the database queries directly or run via CLI
require_once __DIR__ . '/../config.php';
$pdo = new PDO("mysql:host={$servername};dbname={$dbname}", $username, $password);

$sql = "SELECT s.*, COALESCE(NULLIF(s.image, ''), p.image, '') AS image,
               COALESCE(NULLIF(s.selling_price_per_unit, 0), p.price, 0) AS selling_price_per_unit,
               COALESCE(NULLIF(s.selling_price_per_box, 0), (COALESCE(NULLIF(s.selling_price_per_unit, 0), p.price, 0) * s.conversion_rate), 0) AS selling_price_per_box
        FROM stock_items s 
        LEFT JOIN products p ON LOWER(REPLACE(s.item_name, ' ', '')) = LOWER(REPLACE(p.name, ' ', '')) 
        WHERE s.item_type = 'direct_drink' AND s.is_active = 1
        ORDER BY s.item_name ASC";

$stmt = $pdo->query($sql);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Total Active Direct Drinks: " . count($items) . "\n\n";
foreach ($items as $it) {
    echo sprintf(
        "%-25s | Unit: %-7s | Box: %-5s (x%2d) | Cost: \$%6.2f/box, \$%6.4f/unit | Sell: \$%5.2f/unit, \$%6.2f/box\n",
        $it['item_name'],
        $it['unit'],
        $it['purchase_unit'],
        (int)$it['conversion_rate'],
        (float)$it['cost_per_purchase_unit'],
        (float)$it['cost_per_unit'],
        (float)$it['selling_price_per_unit'],
        (float)$it['selling_price_per_box']
    );
}
