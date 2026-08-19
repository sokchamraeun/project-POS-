<?php
/**
 * Bird's Nest Coffee POS - Real-Time Stock Status API
 * Returns stock availability per product based on Recipe / BOM ingredients
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

try {
    $pdo_dsn = "mysql:host={$servername};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($pdo_dsn, $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // 1. Query all products and evaluate stock against recipes
    $sql = "SELECT 
        p.product_id,
        p.name,
        p.price,
        p.is_available,
        COUNT(r.recipe_id) AS recipe_count,
        MIN(CASE WHEN s.item_id IS NOT NULL AND (s.item_name NOT LIKE '%Packaging Set%' AND s.item_name NOT LIKE '%ឈុត%' AND s.category != 'Packaging') AND r.quantity_required > 0 THEN FLOOR(s.quantity / r.quantity_required) ELSE NULL END) AS max_servings,
        SUM(CASE WHEN s.item_id IS NOT NULL AND (s.item_name NOT LIKE '%Packaging Set%' AND s.item_name NOT LIKE '%ឈុត%' AND s.category != 'Packaging') AND s.quantity <= 0 THEN 1 ELSE 0 END) AS out_of_stock_ingredients,
        SUM(CASE WHEN s.item_id IS NOT NULL AND (s.item_name NOT LIKE '%Packaging Set%' AND s.item_name NOT LIKE '%ឈុត%' AND s.category != 'Packaging') AND s.quantity > 0 AND s.quantity <= s.alert_level THEN 1 ELSE 0 END) AS low_stock_ingredients,
        GROUP_CONCAT(CASE WHEN s.item_id IS NOT NULL AND (s.item_name NOT LIKE '%Packaging Set%' AND s.item_name NOT LIKE '%ឈុត%' AND s.category != 'Packaging') AND s.quantity <= 0 THEN s.item_name ELSE NULL END SEPARATOR ', ') AS missing_ingredients,
        GROUP_CONCAT(CASE WHEN s.item_id IS NOT NULL AND (s.item_name NOT LIKE '%Packaging Set%' AND s.item_name NOT LIKE '%ឈុត%' AND s.category != 'Packaging') AND s.quantity > 0 AND s.quantity <= s.alert_level THEN s.item_name ELSE NULL END SEPARATOR ', ') AS low_ingredients
    FROM products p
    LEFT JOIN product_recipes r ON p.product_id = r.product_id
    LEFT JOIN stock_items s ON r.item_id = s.item_id AND s.is_active = 1
    GROUP BY p.product_id";

    $stmt = $pdo->query($sql);
    $products = $stmt->fetchAll();

    $statuses = [];
    foreach ($products as $p) {
        $pId = (int)$p['product_id'];
        $isAvail = (int)$p['is_available'];
        $recipeCount = (int)$p['recipe_count'];
        $outCount = (int)$p['out_of_stock_ingredients'];
        $lowCount = (int)$p['low_stock_ingredients'];
        $maxServings = $p['max_servings'] !== null ? (int)$p['max_servings'] : null;

        $status = 'in_stock';
        $reason = '';

        if ($isAvail === 0) {
            $status = 'out_of_stock';
            $reason = 'Item marked unavailable';
        } elseif ($recipeCount > 0 && $outCount > 0) {
            $status = 'out_of_stock';
            $reason = 'Out of ' . ($p['missing_ingredients'] ?: 'ingredients');
        } elseif ($recipeCount > 0 && $lowCount > 0) {
            $status = 'low_stock';
            $reason = 'Low on ' . ($p['low_ingredients'] ?: 'stock');
        }

        $statuses[$pId] = [
            'product_id'   => $pId,
            'status'       => $status,
            'reason'       => $reason,
            'max_servings' => $maxServings,
            'is_available' => ($status !== 'out_of_stock')
        ];
    }

    echo json_encode([
        'success'   => true,
        'timestamp' => time(),
        'statuses'  => $statuses
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
