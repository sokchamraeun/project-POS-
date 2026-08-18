<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config.php';

// Prevent any previous output/warnings from corrupting JSON
if (ob_get_level()) {
    ob_clean();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

try {
    $payload = get_cart_payload($conn);
    session_write_close();
    echo json_encode($payload);
} catch (Throwable $e) {
    error_log("cart_refresh.php error: " . $e->getMessage());
    echo json_encode([
        'items'          => [],
        'count'          => 0,
        'subtotal'       => '0.00',
        'item_promos'    => '0.00',
        'buy3'           => '0.00',
        'buy3_name'      => '',
        'buy3_price'     => '0.00',
        'buy3_count'     => 3,
        'happy_hour'     => '0.00',
        'happy_hour_pct' => 0,
        'manual'         => '0.00',
        'manual_label'   => '',
        'tax'            => '0.00',
        'total'          => '0.00',
    ]);
}
exit;
