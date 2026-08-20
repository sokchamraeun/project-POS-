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
