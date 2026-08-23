<?php
require_once 'auth.php';
require_once 'config.php';
date_default_timezone_set('Asia/Phnom_Penh');
header('Content-Type: application/json; charset=utf-8');

$order_id = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
if ($order_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid order ID']);
    exit;
}

// 1. Fetch Order
$stmt = $conn->prepare("
    SELECT o.order_id, 'General Customer' AS customer_name,
           o.total, o.order_date, o.order_id AS daily_order_no, 'Completed' AS status,
           COALESCE(NULLIF(u.name, ''), u.username, o.prepared_by, 'Staff') AS employee_name,
           u.user_id AS employee_id,
           COALESCE(NULLIF(u.name, ''), u.username, o.prepared_by, 'Staff') AS emp_real_name,
           'drink_in' AS order_type, '' AS table_number,
           0 AS promotion_discount, 0 AS manual_discount
    FROM orders o
    LEFT JOIN users u ON u.user_id = o.user_id
    WHERE o.order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    echo json_encode(['success' => false, 'error' => 'Order not found']);
    exit;
}

$c_name = $order['employee_name'] ?: 'Staff';
$e_real = trim($order['emp_real_name'] ?? '');
if (!empty($e_real) && strtolower($e_real) !== strtolower($c_name)) {
    $cashier_display = $c_name . '(' . $e_real . ')';
} else {
    $cashier_display = $c_name;
}

// 2. Fetch Items
$stmt = $conn->prepare("
    SELECT product_name, price, quantity, sweetness, ice, milk, size_label, addons_snapshot, promo_percent, orig_price
    FROM order_items
    WHERE order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$items_res = $stmt->get_result();

$raw_items = [];
$subtotal = 0;
while ($row = $items_res->fetch_assoc()) {
    $raw_items[] = $row;
    $itemOrig  = (float)($row['orig_price'] ?? 0);
    $itemPrice = (float)($row['price'] ?? 0);
    $qty       = (float)($row['quantity'] ?? 1);
    if ($itemOrig > $itemPrice) {
        $subtotal += $itemOrig * $qty;
    } elseif ((int)($row['promo_percent'] ?? 0) > 0 && (int)$row['promo_percent'] < 100) {
        $subtotal += ($itemPrice / (1 - (int)$row['promo_percent'] / 100)) * $qty;
    } else {
        $subtotal += $itemPrice * $qty;
    }
}

// Format items
$items = [];
foreach ($raw_items as $ri) {
    $opts = [];
    if (!empty($ri['size_label'])) $opts[] = $ri['size_label'];
    if (!empty($ri['sweetness']) && $ri['sweetness'] !== '100%') $opts[] = $ri['sweetness'];
    if (!empty($ri['ice']) && $ri['ice'] !== 'Normal Ice') $opts[] = $ri['ice'];
    if (!empty($ri['milk'])) $opts[] = $ri['milk'];
    
    if (!empty($ri['addons_snapshot'])) {
        $addons = is_array($ri['addons_snapshot']) ? $ri['addons_snapshot'] : json_decode($ri['addons_snapshot'], true);
        if (is_array($addons)) {
            foreach ($addons as $ad) {
                if (isset($ad['name'])) $opts[] = '+ ' . $ad['name'];
            }
        }
    }

    $items[] = [
        'product_name'   => $ri['product_name'] ?? 'Item',
        'price'          => (float)($ri['price'] ?? 0),
        'quantity'       => (float)($ri['quantity'] ?? 1),
        'promo_percent'  => (int)($ri['promo_percent'] ?? 0),
        'note'           => '',
        'options_text'   => implode(', ', $opts)
    ];
}

$discount = (float)($order['promotion_discount'] ?? 0) + (float)($order['manual_discount'] ?? 0);
$total = (float)$order['total'] > 0 ? (float)$order['total'] : max(0, $subtotal - $discount);
$discount_percent = ($subtotal > 0 && $discount > 0) ? round(($discount / $subtotal) * 100) : 0;

$khr_rate = defined('KHR_RATE') ? KHR_RATE : 4000;
$total_khr = (int)(round($total * $khr_rate / 100) * 100);

// 3. Fetch Payments
$pay_stmt = $conn->prepare("
    SELECT payment_method, amount, reference
    FROM order_payments
    WHERE order_id = ? AND amount > 0
    ORDER BY FIELD(payment_method, 'cash', 'bakong', 'paylater', 'riel')
");
$pay_stmt->bind_param("i", $order_id);
$pay_stmt->execute();
$payments = $pay_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$pay_method_label = 'Bakong KHQR';
$tendered_usd = $total;
$change_usd = 0;
$change_khr = 0;

if (!empty($payments)) {
    $methods = [];
    foreach ($payments as $p) {
        $pm = strtolower($p['payment_method']);
        if ($pm === 'cash') $methods[] = 'Cash-$';
        elseif ($pm === 'bakong') $methods[] = 'Bakong KHQR';
        elseif ($pm === 'riel') $methods[] = 'Cash-KHR';
        else $methods[] = ucfirst($pm);

        if ($pm === 'cash' || $pm === 'riel') {
            $ref_val = (string)($p['reference'] ?? '');
            $t_usd   = function_exists('tender_usd_total') ? tender_usd_total($ref_val) : (is_numeric($ref_val) ? (float)$ref_val : 0);
            if ($t_usd > 0) {
                $tendered_usd = $t_usd;
                $is_riel = function_exists('tender_is_riel_only') ? tender_is_riel_only(tender_parts($ref_val)) : false;
                $ch = function_exists('tender_change') ? tender_change($tendered_usd, $total, $is_riel) : ['usd' => 0, 'khr' => 0];
                $change_usd = (float)($ch['usd'] ?? 0);
                $change_khr = (int)($ch['khr'] ?? 0);
            }
        }
    }
    if (!empty($methods)) {
        $pay_method_label = implode(', ', array_unique($methods));
    }
}

// 4. Return Payload
echo json_encode([
    'success'          => true,
    'order_id'         => $order_id,
    'daily_order_no'   => $order['daily_order_no'] ?? $order_id,
    'shop_name'        => defined('RECEIPT_SHOP_NAME') ? RECEIPT_SHOP_NAME : 'The Bird Nest Cafe',
    'shop_location'    => defined('RECEIPT_LOCATION') ? RECEIPT_LOCATION : 'Phnom Penh',
    'shop_phone'       => defined('RECEIPT_PHONE') ? RECEIPT_PHONE : '',
    'employee_name'    => $cashier_display,
    'customer_name'    => $order['customer_name'] ?? 'General Customer',
    'order_time'       => date('d-m-Y h:i A', strtotime($order['order_date'])),
    'payment_method'   => $pay_method_label,
    'items'            => $items,
    'subtotal'         => $subtotal,
    'discount'         => $discount,
    'discount_percent' => $discount_percent,
    'total'            => $total,
    'total_khr'        => number_format($total_khr),
    'received'         => $tendered_usd,
    'received_text'    => 'USD ' . number_format($tendered_usd, 2),
    'change'           => $change_usd,
    'change_khr'       => $change_khr,
    'wifi_pass'        => defined('WIFI_PASSWORD') ? WIFI_PASSWORD : (defined('RECEIPT_WIFI_PASS') ? RECEIPT_WIFI_PASS : '')
], JSON_UNESCAPED_UNICODE);
