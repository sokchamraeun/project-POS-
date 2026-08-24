<?php
require_once __DIR__ . '/../config.php';
$pdo = new PDO("mysql:host={$servername};dbname={$dbname}", $username, $password);

// Check if columns exist
$cols = $pdo->query("SHOW COLUMNS FROM stock_items")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('selling_price_per_unit', $cols)) {
    echo "Adding selling_price_per_unit column...\n";
    $pdo->exec("ALTER TABLE stock_items ADD COLUMN selling_price_per_unit DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER cost_per_purchase_unit");
} else {
    echo "selling_price_per_unit already exists.\n";
}

if (!in_array('selling_price_per_box', $cols)) {
    echo "Adding selling_price_per_box column...\n";
    $pdo->exec("ALTER TABLE stock_items ADD COLUMN selling_price_per_box DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER selling_price_per_unit");
} else {
    echo "selling_price_per_box already exists.\n";
}

// Sync default prices from products table if available
echo "Syncing direct drink prices from products table...\n";
$stmt = $pdo->query("SELECT s.item_id, s.item_name, s.conversion_rate, s.selling_price_per_unit, s.selling_price_per_box, p.price 
                     FROM stock_items s 
                     LEFT JOIN products p ON LOWER(REPLACE(s.item_name, ' ', '')) = LOWER(REPLACE(p.name, ' ', '')) 
                     WHERE s.item_type = 'direct_drink'");
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($items as $it) {
    $rate = max(1.0, (float)$it['conversion_rate']);
    $prodPrice = (float)($it['price'] ?? 0);
    $curUnit = (float)$it['selling_price_per_unit'];
    $curBox = (float)$it['selling_price_per_box'];

    $newUnit = ($curUnit > 0) ? $curUnit : (($prodPrice > 0) ? $prodPrice : 1.00);
    $newBox = ($curBox > 0) ? $curBox : ($newUnit * $rate);

    $upd = $pdo->prepare("UPDATE stock_items SET selling_price_per_unit = ?, selling_price_per_box = ? WHERE item_id = ?");
    $upd->execute([$newUnit, $newBox, $it['item_id']]);
    echo "Updated item_id {$it['item_id']} ({$it['item_name']}): Unit=\${$newUnit}, Box=\${$newBox}\n";
}

echo "Migration finished successfully.\n";
