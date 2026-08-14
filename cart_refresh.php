<?php
session_start();
require 'config.php';

$cart = $_SESSION['cart'] ?? [];
$subtotal = 0.0; $total_qty = 0; $item_promos = 0.0;
$min_price = PHP_FLOAT_MAX; $cheapest_idx = -1;
$_fpid = defined('FREE_ITEM_PRODUCT_ID') ? (int)FREE_ITEM_PRODUCT_ID : 0;
$_fname = ''; $_fprice = 0.0; $_fidx = -1;

$items_out = [];
foreach ($cart as $i => $item) {
    $q = (int)($item['qty'] ?? 1);
    $p = (float)($item['price'] ?? 0);
    $itemLineTotal = $p * $q;
    $subtotal += $itemLineTotal;
    $total_qty += $q;

    // Per-item manual discount
    $itemDisc = 0.0;
    $discType = $item['discount_type'] ?? '';
    $discAmt  = (float)($item['discount_amount'] ?? 0);

    if ($discAmt > 0) {
        if ($discType === 'flat') {
            $itemDisc = min($itemLineTotal, $discAmt);
        } else {
            $itemDisc = $itemLineTotal * (min(100, $discAmt) / 100.0);
        }
    } elseif ((int)($item['promo_percent'] ?? 0) > 0) {
        $origP = (float)($item['orig_price'] ?? $p);
        if ($origP > $p) {
            $itemDisc = ($origP - $p) * $q;
        }
    }

    $item_promos += $itemDisc;

    if ($p < $min_price) { $min_price = $p; $cheapest_idx = $i; }
    if ($_fpid > 0 && (int)($item['product_id'] ?? 0) === $_fpid && $_fidx < 0) {
        $_fidx = $i; $_fname = $item['product_name'] ?? ''; $_fprice = $p;
    }

    $items_out[] = [
        'index'           => $i,
        'product_name'    => $item['product_name'] ?? '',
        'price'           => $p,
        'orig_price'      => (float)($item['orig_price'] ?? $p),
        'promo_percent'   => (int)($item['promo_percent'] ?? 0),
        'qty'             => $q,
        'image'           => $item['image'] ?? '',
        'size_code'       => $item['size_code']  ?? '',
        'size_label'      => $item['size_label'] ?? '',
        'sweetness'       => $item['sweetness'] ?? '',
        'ice'             => $item['ice'] ?? '',
        'milk'            => $item['milk'] ?? '',
        'addons'          => $item['addons'] ?? [],
        'discount_type'   => $discType,
        'discount_amount' => $discAmt,
        'item_discount'   => round($itemDisc, 2),
        'lineTotal'       => round($itemLineTotal, 2),
    ];
}

// If configured free item isn't in cart, fetch its name/price from DB
if ($_fpid > 0 && $_fname === '') {
    $_fp_s = $conn->prepare("SELECT name, price FROM products WHERE product_id = ?");
    if ($_fp_s) { $_fp_s->bind_param("i", $_fpid); $_fp_s->execute();
        if ($_fp_r = $_fp_s->get_result()->fetch_assoc()) { $_fname = $_fp_r['name']; $_fprice = (float)$_fp_r['price']; }
        $_fp_s->close(); }
}
$cheapest_name  = ($cheapest_idx >= 0) ? ($cart[$cheapest_idx]['product_name'] ?? '') : '';
$cheapest_price = ($cheapest_idx >= 0 && $min_price < PHP_FLOAT_MAX) ? $min_price : 0.0;
$free_name  = ($_fpid > 0 && $_fname !== '') ? $_fname : $cheapest_name;
$free_price = ($_fpid > 0 && $_fprice > 0) ? $_fprice : $cheapest_price;

$_free_idx = ($_fpid > 0 && $_fidx >= 0) ? $_fidx : $cheapest_idx;
$buy3 = (BUY_X_GET_1_ENABLED && $total_qty >= BUY_X_COUNT && $min_price < PHP_FLOAT_MAX && $_free_idx >= 0)
    ? floor($total_qty / BUY_X_COUNT) * $free_price : 0.0;

$hh = 0.0;
if (HAPPY_HOUR_ENABLED && (int)date('H') >= HAPPY_HOUR_START && (int)date('H') < HAPPY_HOUR_END)
    $hh = ($subtotal - $item_promos) * (HAPPY_HOUR_DISCOUNT / 100);

$after = max(0, $subtotal - $item_promos - $hh);
$md = $_SESSION['manual_discount'] ?? null;
$manual = 0.0; $manual_label = '';
if ($md && (float)($md['amount'] ?? 0) > 0) {
    $manual = $md['type'] === 'flat'
        ? min((float)$md['amount'], max(0, $after))
        : max(0, $after) * ((float)$md['amount'] / 100.0);
    $r = trim($md['reason'] ?? ''); $manual_label = $r ?: 'Discount';
    if ($md['type'] === 'percent') $manual_label .= ' (' . (int)$md['amount'] . '% off)';
    $after -= $manual;
}
$tax   = $after * (TAX_RATE / 100);
$total = round($after + $tax, 2);

header('Content-Type: application/json');
echo json_encode([
    'items'        => $items_out,
    'count'        => $total_qty,
    'subtotal'     => number_format($subtotal, 2, '.', ''),
    'item_promos'  => number_format($item_promos, 2, '.', ''),
    'buy3'         => number_format($buy3, 2, '.', ''),
    'buy3_name'    => $free_name,
    'buy3_price'   => number_format($free_price, 2, '.', ''),
    'buy3_count'   => BUY_X_COUNT,
    'happy_hour'   => number_format($hh, 2, '.', ''),
    'happy_hour_pct' => HAPPY_HOUR_DISCOUNT,
    'manual'       => number_format($manual, 2, '.', ''),
    'manual_label' => $manual_label,
    'tax'          => number_format($tax, 2, '.', ''),
    'total'        => number_format($total, 2, '.', ''),
]);
