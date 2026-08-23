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
    $cart = $_SESSION['cart'] ?? [];
    $statuses = evaluate_products_stock($conn, $cart);

    // Compute live sidebar alert metrics
    $_stock_drink_alerts = 0;
    $_stock_drink_has_out = false;
    $_ingredient_alerts = 0;
    $_ingredient_has_out = false;

    if (isset($conn) && $conn instanceof mysqli) {
        $q_stock = $conn->query("
            SELECT 
                SUM(CASE WHEN quantity <= alert_level THEN 1 ELSE 0 END) AS alert_cnt,
                SUM(CASE WHEN quantity <= 0 THEN 1 ELSE 0 END) AS out_cnt
            FROM stock_items 
            WHERE item_type = 'direct_drink' AND is_active = 1
        ");
        if ($q_stock && ($r_stock = $q_stock->fetch_assoc())) {
            $_stock_drink_alerts = (int)($r_stock['alert_cnt'] ?? 0);
            $_stock_drink_has_out = (int)($r_stock['out_cnt'] ?? 0) > 0;
        }

        $q_ing = $conn->query("
            SELECT 
                SUM(CASE WHEN quantity <= alert_level THEN 1 ELSE 0 END) AS alert_cnt,
                SUM(CASE WHEN quantity <= 0 THEN 1 ELSE 0 END) AS out_cnt
            FROM stock_items 
            WHERE (item_type = 'ingredient' OR item_type = 'raw_ingredient') 
              AND is_active = 1 
              AND (item_name NOT LIKE '%Packaging Set%' AND item_name NOT LIKE '%ឈុត%')
        ");
        if ($q_ing && ($r_ing = $q_ing->fetch_assoc())) {
            $_ingredient_alerts = (int)($r_ing['alert_cnt'] ?? 0);
            $_ingredient_has_out = (int)($r_ing['out_cnt'] ?? 0) > 0;
        }
    }

    echo json_encode([
        'success'        => true,
        'timestamp'      => time(),
        'statuses'       => $statuses,
        'sidebar_alerts' => [
            'stock_drinks' => [
                'count'   => $_stock_drink_alerts,
                'has_out' => $_stock_drink_has_out
            ],
            'ingredients' => [
                'count'   => $_ingredient_alerts,
                'has_out' => $_ingredient_has_out
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
