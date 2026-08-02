<?php
session_start();
require 'config.php';

// ── FIX: Reset redirect counter to prevent infinite loops ──
$_SESSION['redirect_count'] = 0;

// Generate CSRF token if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- DETECT ADD TO ORDER MODE ---
$add_to_order_id = isset($_SESSION['add_to_order_id']) ? (int)$_SESSION['add_to_order_id'] : 0;

// ── LINKED LOYALTY CARD (session) ──
$linked_loyalty = null;
$linked_loyalty_id_int = isset($_SESSION['loyalty_card_id']) ? (int)$_SESSION['loyalty_card_id'] : 0;
if ($linked_loyalty_id_int > 0) {
    $lc_stmt = $conn->prepare("SELECT loyalty_id, points FROM loyalty_cards WHERE card_id = ?");
    if ($lc_stmt) {
        $lc_stmt->bind_param("i", $linked_loyalty_id_int);
        $lc_stmt->execute();
        $linked_loyalty = $lc_stmt->get_result()->fetch_assoc();
    }
}

$cart = $_SESSION['cart'] ?? [];

/* ======================
   AJAX REMOVE ITEM (NO RELOAD)
====================== */
if (isset($_POST['ajax_remove'])) {
    $i = intval($_POST['index']);
    if (isset($_SESSION['cart'][$i])) {
        unset($_SESSION['cart'][$i]);
        $_SESSION['cart'] = array_values($_SESSION['cart']);
        if (empty($_SESSION['cart'])) unset($_SESSION['cart_started_at']);
    }
    $subtotal = 0; $total_qty = 0; $min_price = PHP_FLOAT_MAX; $min_item_name_aj = '';
    $_fpid_aj = defined('FREE_ITEM_PRODUCT_ID') ? (int)FREE_ITEM_PRODUCT_ID : 0;
    $_fname_aj = ''; $_fprice_aj = 0.0;
    foreach ($_SESSION['cart'] as $item) {
        $q = (int)($item['qty'] ?? 1); $p = (float)($item['price'] ?? 0);
        $subtotal += $p * $q; $total_qty += $q;
        if ($p < $min_price) { $min_price = $p; $min_item_name_aj = $item['product_name'] ?? ''; }
        if ($_fpid_aj > 0 && (int)($item['product_id'] ?? 0) === $_fpid_aj && $_fname_aj === '') {
            $_fname_aj = $item['product_name'] ?? ''; $_fprice_aj = $p;
        }
    }
    if ($_fpid_aj > 0 && $_fname_aj === '') {
        $_fp_s = $conn->prepare("SELECT name, price FROM products WHERE product_id = ?");
        if ($_fp_s) { $_fp_s->bind_param("i", $_fpid_aj); $_fp_s->execute();
            if ($_fp_r = $_fp_s->get_result()->fetch_assoc()) { $_fname_aj = $_fp_r['name']; $_fprice_aj = (float)$_fp_r['price']; }
            $_fp_s->close(); }
    }
    $_free_name_aj  = ($_fpid_aj > 0 && $_fname_aj !== '') ? $_fname_aj : $min_item_name_aj;
    $_free_price_aj = ($_fpid_aj > 0 && $_fprice_aj > 0) ? $_fprice_aj : $min_price;
    $buy3 = (BUY_X_GET_1_ENABLED && $total_qty >= BUY_X_COUNT && $min_price < PHP_FLOAT_MAX) ? floor($total_qty / BUY_X_COUNT) * $_free_price_aj : 0;
    $hh   = 0;
    if (HAPPY_HOUR_ENABLED && (int)date('H') >= HAPPY_HOUR_START && (int)date('H') < HAPPY_HOUR_END)
        $hh = $subtotal * (HAPPY_HOUR_DISCOUNT / 100);
    $after = $subtotal - $hh;
    $md = $_SESSION['manual_discount'] ?? null;
    $manual_disc = 0.0; $manual_label = '';
    if ($md && (float)($md['amount'] ?? 0) > 0) {
        $manual_disc = $md['type'] === 'flat' ? min((float)$md['amount'], max(0, $after)) : max(0, $after) * ((float)$md['amount'] / 100.0);
        $r = trim($md['reason'] ?? ''); $manual_label = $r ?: 'Discount';
        if ($md['type'] === 'percent') $manual_label .= ' (' . (int)$md['amount'] . '% off)';
        $after -= $manual_disc;
    }
    $tax   = $after * (TAX_RATE / 100);
    $total = round($after + $tax, 2);
    header('Content-Type: application/json');
    echo json_encode([
        'success'              => true,
        'cartCount'            => $total_qty,
        'cartSubtotal'         => number_format($subtotal, 2, '.', ''),
        'buy3_discount'        => number_format($buy3, 2, '.', ''),
        'free_item_name'       => $buy3 > 0 ? $_free_name_aj : '',
        'free_item_price'      => $buy3 > 0 ? number_format($_free_price_aj < PHP_FLOAT_MAX ? $_free_price_aj : 0, 2, '.', '') : '0.00',
        'happy_hour_discount'  => number_format($hh, 2, '.', ''),
        'manual_discount'      => number_format($manual_disc, 2, '.', ''),
        'manual_discount_label'=> $manual_label,
        'tax'                  => number_format($tax, 2, '.', ''),
        'cartTotal'            => number_format($total, 2, '.', ''),
        'remaining'            => count($_SESSION['cart']),
    ]);
    exit;
}

/* ======================
   AJAX CLEAR CART
====================== */
if (isset($_POST['ajax_clear'])) {
    $_SESSION['cart'] = [];
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

/* ======================
   AJAX APPLY MANUAL DISCOUNT
====================== */
if (isset($_POST['ajax_apply_discount'])) {
    header('Content-Type: application/json');
    $type   = in_array($_POST['type'] ?? '', ['percent', 'flat']) ? $_POST['type'] : 'percent';
    $amount = max(0, (float)($_POST['amount'] ?? 0));
    $reason = substr(trim($_POST['reason'] ?? ''), 0, 100);
    if ($type === 'percent') $amount = min(100, $amount);
    if ($amount > 0) {
        $_SESSION['manual_discount'] = ['type' => $type, 'amount' => $amount, 'reason' => $reason];
    } else {
        unset($_SESSION['manual_discount']);
    }
    echo json_encode(['success' => true]);
    exit;
}

/* ======================
   AJAX CLEAR MANUAL DISCOUNT
====================== */
if (isset($_POST['ajax_clear_discount'])) {
    unset($_SESSION['manual_discount']);
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

/* ======================
   AJAX UPDATE QTY (NO RELOAD)
====================== */
if (isset($_POST['ajax_update'])) {
    $i = intval($_POST['index']);
    $qty = max(1, intval($_POST['qty']));

    if (isset($_SESSION['cart'][$i])) {
        $_SESSION['cart'][$i]['qty'] = $qty;
    }

    $subtotal = 0;
    $total_qty = 0;
    $min_price = PHP_FLOAT_MAX;
    $cheapest_item_index = -1;
    $_fpid_upd = defined('FREE_ITEM_PRODUCT_ID') ? (int)FREE_ITEM_PRODUCT_ID : 0;
    $_fname_upd = ''; $_fprice_upd = 0.0; $_findex_upd = -1;

    foreach ($_SESSION['cart'] as $index => $item) {
        $q = isset($item['qty']) ? (int)$item['qty'] : 1;
        $p = (float)($item['price'] ?? 0.0);
        $subtotal += $p * $q;
        $total_qty += $q;

        if ($p < $min_price) {
            $min_price = $p;
            $cheapest_item_index = $index;
        }
        if ($_fpid_upd > 0 && (int)($item['product_id'] ?? 0) === $_fpid_upd && $_findex_upd < 0) {
            $_findex_upd = $index; $_fname_upd = $item['product_name'] ?? ''; $_fprice_upd = $p;
        }
    }
    if ($_fpid_upd > 0 && $_fname_upd === '') {
        $_fp_s = $conn->prepare("SELECT name, price FROM products WHERE product_id = ?");
        if ($_fp_s) { $_fp_s->bind_param("i", $_fpid_upd); $_fp_s->execute();
            if ($_fp_r = $_fp_s->get_result()->fetch_assoc()) { $_fname_upd = $_fp_r['name']; $_fprice_upd = (float)$_fp_r['price']; }
            $_fp_s->close(); }
    }
    $_free_name_upd  = ($_fpid_upd > 0 && $_fname_upd !== '') ? $_fname_upd
        : (($cheapest_item_index >= 0 && isset($_SESSION['cart'][$cheapest_item_index])) ? ($_SESSION['cart'][$cheapest_item_index]['product_name'] ?? '') : '');
    $_free_price_upd = ($_fpid_upd > 0 && $_fprice_upd > 0) ? $_fprice_upd : $min_price;

    $buy3_discount = 0;
    if (BUY_X_GET_1_ENABLED && $total_qty >= BUY_X_COUNT) {
        $free_items = floor($total_qty / BUY_X_COUNT);
        $_use_index = ($_fpid_upd > 0 && $_findex_upd >= 0) ? $_findex_upd : $cheapest_item_index;
        if ($free_items > 0 && $_use_index >= 0) {
            $buy3_discount = $free_items * $_free_price_upd;
        }
    }

    // ── HAPPY HOUR (applies regardless of quantity) ──
    $happy_hour_discount = 0;
    $current_hour = (int)date('H');
    if (HAPPY_HOUR_ENABLED && $current_hour >= HAPPY_HOUR_START && $current_hour < HAPPY_HOUR_END) {
        $happy_hour_discount = $subtotal * (HAPPY_HOUR_DISCOUNT / 100);
    }

    $after_promos = $subtotal - $happy_hour_discount;
    $md_aj = $_SESSION['manual_discount'] ?? null;
    $manual_disc_aj = 0.0; $manual_label_aj = '';
    if ($md_aj && (float)($md_aj['amount'] ?? 0) > 0) {
        $manual_disc_aj = $md_aj['type'] === 'flat' ? min((float)$md_aj['amount'], max(0, $after_promos)) : max(0, $after_promos) * ((float)$md_aj['amount'] / 100.0);
        $r_aj = trim($md_aj['reason'] ?? ''); $manual_label_aj = $r_aj ?: 'Discount';
        if ($md_aj['type'] === 'percent') $manual_label_aj .= ' (' . (int)$md_aj['amount'] . '% off)';
        $after_promos -= $manual_disc_aj;
    }
    $subtotal_after_discount = $after_promos;
    $tax = $subtotal_after_discount * (TAX_RATE / 100);
    $total = round($subtotal_after_discount + $tax, 2);

    $updated_item = $_SESSION['cart'][$i] ?? null;
    $item_line_total = $updated_item
        ? (float)($updated_item['price'] ?? 0) * $qty
        : 0;

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'itemLineTotal'         => number_format($item_line_total, 2, '.', ''),
        'cartSubtotal'          => number_format($subtotal, 2, '.', ''),
        'cartTotal'             => number_format($total, 2, '.', ''),
        'buy3_discount'         => number_format($buy3_discount, 2, '.', ''),
        'free_item_name'        => $buy3_discount > 0 ? $_free_name_upd : '',
        'free_item_price'       => $buy3_discount > 0 ? number_format($_free_price_upd < PHP_FLOAT_MAX ? $_free_price_upd : 0, 2, '.', '') : '0.00',
        'happy_hour_discount'   => number_format($happy_hour_discount, 2, '.', ''),
        'manual_discount'       => number_format($manual_disc_aj, 2, '.', ''),
        'manual_discount_label' => $manual_label_aj,
        'subtotal'              => number_format($subtotal_after_discount, 2, '.', ''),
        'tax'                   => number_format($tax, 2, '.', ''),
        'cartCount'             => $total_qty
    ]);
    exit;
}

/* ======================
   REMOVE ITEM - FIXED (No redirect loop)
====================== */
if (isset($_GET['remove'])) {
    $i = intval($_GET['remove']);
    if (isset($_SESSION['cart'][$i])) {
        unset($_SESSION['cart'][$i]);
        $_SESSION['cart'] = array_values($_SESSION['cart']);
        if (empty($_SESSION['cart'])) unset($_SESSION['cart_started_at']);
    }
    echo "<script>window.location.href = 'cart.php';</script>";
    exit;
}

/* ======================
   TOTAL WITH PROMOTION - FIXED
====================== */
$subtotal = 0;
$total_qty = 0;
$min_price = PHP_FLOAT_MAX;
$cheapest_item_index = -1;
$_fpid_pg = defined('FREE_ITEM_PRODUCT_ID') ? (int)FREE_ITEM_PRODUCT_ID : 0;
$_fname_pg = ''; $_fprice_pg = 0.0; $_findex_pg = -1;

foreach ($cart as $i => $item) {
    if (!isset($item['qty']) || !isset($item['price'])) {
        continue;
    }

    $qty = (int)$item['qty'];
    $price = (float)$item['price'];

    if ($qty < 1) $qty = 1;

    $subtotal += $price * $qty;
    $total_qty += $qty;

    if ($price < $min_price) {
        $min_price = $price;
        $cheapest_item_index = $i;
    }
    if ($_fpid_pg > 0 && (int)($item['product_id'] ?? 0) === $_fpid_pg && $_findex_pg < 0) {
        $_findex_pg = $i; $_fname_pg = $item['product_name'] ?? ''; $_fprice_pg = $price;
    }
}
if ($_fpid_pg > 0 && $_fname_pg === '') {
    $_fp_s = $conn->prepare("SELECT name, price FROM products WHERE product_id = ?");
    if ($_fp_s) { $_fp_s->bind_param("i", $_fpid_pg); $_fp_s->execute();
        if ($_fp_r = $_fp_s->get_result()->fetch_assoc()) { $_fname_pg = $_fp_r['name']; $_fprice_pg = (float)$_fp_r['price']; }
        $_fp_s->close(); }
}
$cheapest_item_name = ($cheapest_item_index >= 0 && isset($cart[$cheapest_item_index]))
    ? ($cart[$cheapest_item_index]['product_name'] ?? '') : '';
$_free_item_name_pg  = ($_fpid_pg > 0 && $_fname_pg !== '') ? $_fname_pg : $cheapest_item_name;
$_free_item_price_pg = ($_fpid_pg > 0 && $_fprice_pg > 0) ? $_fprice_pg : $min_price;

// ── Buy X Get 1 Free ──
$buy3_discount_amount = 0;
if (BUY_X_GET_1_ENABLED && $total_qty >= BUY_X_COUNT) {
    $free_items = floor($total_qty / BUY_X_COUNT);
    $_use_index_pg = ($_fpid_pg > 0 && $_findex_pg >= 0) ? $_findex_pg : $cheapest_item_index;
    if ($free_items > 0 && $_use_index_pg >= 0) {
        $buy3_discount_amount = $free_items * $_free_item_price_pg;
    }
}

// ── Happy Hour (applies regardless of quantity) ──
$happy_hour_discount_amount = 0;
$current_hour = (int)date('H');
if (HAPPY_HOUR_ENABLED && $current_hour >= HAPPY_HOUR_START && $current_hour < HAPPY_HOUR_END) {
    $happy_hour_discount_amount = $subtotal * (HAPPY_HOUR_DISCOUNT / 100);
}

$after_promos = $subtotal - $happy_hour_discount_amount;
$md_pg = $_SESSION['manual_discount'] ?? null;
$manual_discount_amount = 0.0;
$manual_discount_label  = '';
if ($md_pg && (float)($md_pg['amount'] ?? 0) > 0) {
    $manual_discount_amount = $md_pg['type'] === 'flat'
        ? min((float)$md_pg['amount'], max(0, $after_promos))
        : max(0, $after_promos) * ((float)$md_pg['amount'] / 100.0);
    $r_pg = trim($md_pg['reason'] ?? '');
    $manual_discount_label = $r_pg ?: 'Discount';
    if ($md_pg['type'] === 'percent') $manual_discount_label .= ' (' . (int)$md_pg['amount'] . '% off)';
    $after_promos -= $manual_discount_amount;
}
$subtotal_after_discount = $after_promos;
$tax   = $subtotal_after_discount * (TAX_RATE / 100);
$total = round($subtotal_after_discount + $tax, 2);

$total_formatted                = number_format($total, 2);
$subtotal_formatted             = number_format($subtotal, 2);
$tax_formatted                  = number_format($tax, 2);
$buy3_discount_formatted        = number_format($buy3_discount_amount, 2);
$happy_hour_discount_formatted  = number_format($happy_hour_discount_amount, 2);
$manual_discount_formatted      = number_format($manual_discount_amount, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Your Cart | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<!-- Apply saved theme before first paint to avoid flash -->
<script>(function(){if(localStorage.getItem('theme')==='dark'){document.documentElement.setAttribute('data-theme','dark');}})();</script>

<style>
:root {
    --bg-body: #f2ede8;
    --bg-card: #fffdf9;
    --bg-card-hover: #fdf7f0;
    --border: #e0d5c8;
    --border-hover: #c9b89f;
    --orange: #d1904b;
    --orange-dark: #a0702a;
    --orange-light: #e8b87a;
    --text-primary: #1a1410;
    --text-secondary: #5a4a3a;
    --text-muted: #9a8070;
    --text-inverse: #ffffff;
    --danger: #e74c3c;
    --success: #55e087;
    --shadow-sm: 0 2px 10px rgba(90,60,20,0.07);
    --shadow-md: 0 6px 28px rgba(90,60,20,0.11);
    --shadow-lg: 0 12px 48px rgba(90,60,20,0.16);
    --shadow-orange-sm: 0 4px 16px rgba(209,144,75,0.22);
    --shadow-orange-md: 0 8px 32px rgba(209,144,75,0.28);
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    --radius: 16px;
}

/* ── Dark theme overrides ── */
[data-theme="dark"] {
    --bg-body:      #0c0c0c;
    --bg-card:      #161616;
    --bg-card-hover:#1e1e1e;
    --border:       #252525;
    --border-hover: #363636;
    --text-primary: #f0f0f0;
    --text-secondary:#aaaaaa;
    --text-muted:   #666666;
    --shadow-sm:    0 2px 10px rgba(0,0,0,0.5);
    --shadow-md:    0 6px 28px rgba(0,0,0,0.55);
    --shadow-lg:    0 12px 48px rgba(0,0,0,0.6);
}
[data-theme="dark"] body {
    background-image: none;
}
[data-theme="dark"] input,
[data-theme="dark"] select {
    background: #1a1a1a !important;
    color: var(--text-primary) !important;
    border-color: var(--border) !important;
    color-scheme: dark;
}
[data-theme="dark"] .top-bar {
    background: var(--bg-card);
    border-color: var(--border);
}
[data-theme="dark"] .cart-items,
[data-theme="dark"] .order-summary {
    background: var(--bg-card);
    border-color: var(--border);
}
[data-theme="dark"] .cart-item:hover {
    background: var(--bg-card-hover);
}
[data-theme="dark"] .qty-control {
    background: #1a1a1a;
    border-color: var(--border);
}
[data-theme="dark"] .payment-method {
    background: #1a1a1a;
    border-color: var(--border);
    color: var(--text-primary);
}
[data-theme="dark"] .payment-method.selected {
    background: rgba(209,144,75,0.12);
}
[data-theme="dark"] #loyaltyModal > div {
    background: var(--bg-card) !important;
    border-color: var(--border) !important;
}
[data-theme="dark"] #loyaltyIdInput {
    background: #1a1a1a !important;
    color: var(--text-primary) !important;
    border-color: var(--border) !important;
}
[data-theme="dark"] #loyaltyResult {
    background: rgba(255,255,255,0.03) !important;
    border-color: var(--border) !important;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    background: var(--bg-body);
    color: var(--text-primary);
    font-family: 'Poppins', sans-serif;
    min-height: 100vh;
    padding: 20px;
    background-image:
        radial-gradient(ellipse at 20% 10%, rgba(209,144,75,0.06) 0%, transparent 60%),
        radial-gradient(ellipse at 80% 90%, rgba(209,144,75,0.04) 0%, transparent 60%);
}

.top-bar {
    max-width: 1200px;
    margin: 0 auto 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    padding: 12px 16px;
    background: var(--bg-card);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    box-shadow: var(--shadow-sm);
    transition: var(--transition);
}

.top-bar:hover {
    border-color: var(--border-hover);
    box-shadow: var(--shadow-md);
}

.top-bar a {
    color: var(--orange);
    text-decoration: none;
    font-weight: 600;
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 8px;
    background: var(--bg-body);
    border: 1px solid transparent;
}

.top-bar a:hover {
    background: var(--orange);
    color: var(--text-inverse);
    border-color: var(--orange);
    transform: translateX(-4px);
    box-shadow: var(--shadow-orange-sm);
}

.cart-container {
    max-width: 1200px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: 1fr 380px;
    gap: 24px;
}

.cart-items {
    background: var(--bg-card);
    border-radius: var(--radius);
    padding: 24px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-sm);
    transition: var(--transition);
}

.cart-items:hover {
    border-color: var(--border-hover);
    box-shadow: var(--shadow-md);
}

.cart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border);
}

.cart-header h1 {
    color: var(--text-primary);
    font-size: 22px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
}

.cart-header h1 i { color: var(--orange); }

.empty-cart {
    text-align: center;
    padding: 60px 20px;
}

.empty-cart i { font-size: 48px; color: var(--text-muted); margin-bottom: 16px; }
.empty-cart h2 { color: var(--text-primary); font-size: 20px; margin-bottom: 8px; }
.empty-cart p { color: var(--text-secondary); margin-bottom: 20px; }

.empty-cart .btn-shop {
    display: inline-block;
    background: var(--orange);
    color: var(--text-inverse);
    padding: 12px 28px;
    border-radius: 50px;
    text-decoration: none;
    font-weight: 600;
    transition: var(--transition);
}

.empty-cart .btn-shop:hover {
    background: var(--orange-dark);
    transform: translateY(-3px);
    box-shadow: var(--shadow-orange-sm);
}

.cart-item {
    display: flex;
    gap: 16px;
    align-items: center;
    padding: 14px 0;
    border-bottom: 1px solid var(--border);
    transition: var(--transition);
}

.cart-item:last-child { border-bottom: none; }

.cart-item:hover {
    background: var(--bg-card-hover);
    padding: 14px 12px;
    border-radius: 12px;
    margin: 0 -12px;
}

.cart-item img {
    width: 64px;
    height: 64px;
    object-fit: cover;
    border-radius: 10px;
    border: 1px solid var(--border);
    flex-shrink: 0;
}

.cart-item .item-info { flex: 1; min-width: 0; }
.cart-item .item-info h3 { color: var(--text-primary); font-size: 15px; font-weight: 600; margin: 0 0 2px 0; }
.cart-item .item-info .item-meta { color: var(--text-secondary); font-size: 12px; }
.cart-item .item-info .item-price { color: var(--orange); font-weight: 600; font-size: 14px; margin-top: 2px; }

.item-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}

.qty-control {
    display: flex;
    align-items: center;
    background: var(--bg-body);
    border: 1px solid var(--border);
    border-radius: 50px;
    overflow: hidden;
}

.qty-control button {
    width: 28px;
    height: 28px;
    background: none;
    border: none;
    color: var(--orange);
    font-size: 16px;
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
}

.qty-control button:hover {
    background: rgba(209,144,75,0.15);
}

.qty-control input[type="number"] {
    width: 36px;
    text-align: center;
    font-weight: 600;
    color: var(--text-primary);
    font-size: 13px;
    background: transparent;
    border: none;
    outline: none;
    font-family: 'Poppins', sans-serif;
    padding: 0;
    -moz-appearance: textfield;
}

.qty-control input[type="number"]::-webkit-inner-spin-button,
.qty-control input[type="number"]::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

.remove-btn {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(231,76,60,0.1);
    color: var(--danger);
    border-radius: 50%;
    text-decoration: none;
    font-size: 14px;
    transition: var(--transition);
    border: 1px solid transparent;
}

.remove-btn:hover {
    background: var(--danger);
    color: var(--text-inverse);
    border-color: var(--danger);
    transform: scale(1.1);
}

.order-summary {
    background: var(--bg-card);
    border-radius: var(--radius);
    padding: 24px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-sm);
    position: sticky;
    top: 20px;
    height: fit-content;
    transition: var(--transition);
}

.order-summary:hover {
    border-color: var(--border-hover);
    box-shadow: var(--shadow-md);
}

.order-summary h2 {
    color: var(--text-primary);
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 10px;
}

.order-summary h2 i { color: var(--orange); }

.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    color: var(--text-secondary);
    font-size: 14px;
}

.summary-row.discount { color: var(--danger); }

/* ── Manual Discount Panel ── */
#discount-panel { margin: 6px 0 2px; }
.discount-toggle-btn {
    width: 100%;
    padding: 8px 12px;
    border-radius: 10px;
    border: 1px dashed var(--border-hover);
    background: transparent;
    color: var(--orange);
    font-family: 'Poppins', sans-serif;
    font-size: 12px; font-weight: 600;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 6px;
    transition: all 0.2s;
}
.discount-toggle-btn:hover { background: rgba(209,144,75,0.07); border-color: var(--orange); }
.discount-toggle-btn.remove { color: var(--danger); border-color: rgba(231,76,60,0.35); }
.discount-toggle-btn.remove:hover { background: rgba(231,76,60,0.07); border-color: var(--danger); }

#discount-form {
    background: rgba(209,144,75,0.04);
    border: 1px solid rgba(209,144,75,0.18);
    border-radius: 12px;
    padding: 14px;
    margin-top: 6px;
    animation: fadeIn 0.2s ease;
}
@keyframes fadeIn { from{opacity:0;transform:translateY(-4px);} to{opacity:1;transform:translateY(0);} }

.discount-type-row { display: flex; gap: 6px; margin-bottom: 10px; }
.dtype-btn {
    flex: 1; padding: 6px 0;
    border-radius: 8px;
    border: 1px solid var(--border-hover);
    background: transparent;
    color: var(--text-secondary);
    font-family: 'Poppins', sans-serif;
    font-size: 12px; font-weight: 600;
    cursor: pointer; transition: all 0.2s;
}
.dtype-btn.active { background: var(--orange); color: #000; border-color: var(--orange); }

.discount-inputs { display: flex; flex-direction: column; gap: 8px; margin-bottom: 10px; }
.discount-inputs input {
    width: 100%; padding: 8px 12px;
    border-radius: 8px;
    border: 1px solid var(--border-hover);
    background: var(--bg-card);
    color: var(--text-primary);
    font-family: 'Poppins', sans-serif;
    font-size: 13px; outline: none;
    transition: border-color 0.2s;
}
.discount-inputs input:focus { border-color: var(--orange); }

.discount-form-actions { display: flex; gap: 8px; }
.btn-apply-discount {
    flex: 1; padding: 8px 0;
    background: var(--orange); color: #000;
    border: none; border-radius: 8px;
    font-family: 'Poppins', sans-serif;
    font-size: 13px; font-weight: 700;
    cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;
    transition: opacity 0.2s;
}
.btn-apply-discount:hover { opacity: 0.88; }
.btn-cancel-discount {
    padding: 8px 14px;
    background: transparent; color: var(--text-muted);
    border: 1px solid var(--border-hover); border-radius: 8px;
    font-family: 'Poppins', sans-serif;
    font-size: 12px; cursor: pointer; transition: all 0.2s;
}
.btn-cancel-discount:hover { border-color: var(--text-muted); }

.summary-row.total {
    border-top: 1px solid var(--border);
    margin-top: 8px;
    padding-top: 12px;
    color: var(--text-primary);
    font-size: 18px;
    font-weight: 700;
}

.summary-row.total span:last-child { color: var(--orange); }

.payment-section {
    margin-top: 16px;
    padding-top: 12px;
    border-top: 1px solid var(--border);
}

.payment-section h3 {
    color: var(--text-primary);
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.payment-section h3 i { color: var(--orange); }

.payment-methods {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 10px;
}

.payment-method {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    border: 2px solid var(--border);
    border-radius: 10px;
    cursor: pointer;
    transition: var(--transition);
    background: var(--bg-body);
    font-size: 14px;
    flex: 0 1 auto;
    min-width: 120px;
}

.payment-method:hover { border-color: var(--border-hover); }

.payment-method.selected {
    border-color: var(--orange);
    background: rgba(209,144,75,0.08);
}

.payment-method input[type="checkbox"] { display: none; }

.payment-split-inputs {
    display: none;
    margin-top: 10px;
    padding: 12px;
    background: var(--bg-body);
    border-radius: 10px;
    border: 1px solid var(--border);
}

.payment-split-inputs.active { display: block; }

.split-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 6px;
}

.split-row:last-child { margin-bottom: 0; }

.split-row label {
    font-size: 13px;
    color: var(--text-secondary);
    min-width: 70px;
}

.split-row input {
    flex: 1;
    padding: 6px 10px;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: var(--bg-body);
    color: var(--text-primary);
    font-size: 13px;
    font-family: 'Poppins', sans-serif;
}

.split-row input:focus {
    outline: none;
    border-color: var(--orange);
}

.checkout-form {
    margin-top: 16px;
}

.drink-type-toggle {
    display: flex;
    gap: 8px;
    margin-top: 6px;
}

.drink-type-btn {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 10px 14px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: var(--bg-body);
    color: var(--text-secondary);
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: var(--transition);
}

.drink-type-btn:hover { border-color: var(--border-hover); }

.drink-type-btn.active {
    border-color: var(--orange);
    background: rgba(209,144,75,0.10);
    color: var(--orange);
    font-weight: 600;
}

.checkout-form .form-group { margin-bottom: 12px; }

.checkout-form label {
    display: block;
    color: var(--text-secondary);
    font-size: 13px;
    margin-bottom: 4px;
    font-weight: 500;
}

.checkout-form input {
    width: 100%;
    padding: 10px 14px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: var(--bg-body);
    color: var(--text-primary);
    font-size: 14px;
    font-family: 'Poppins', sans-serif;
    transition: var(--transition);
}

.checkout-form input:focus {
    outline: none;
    border-color: var(--orange);
}

.btn-checkout {
    width: 100%;
    padding: 12px;
    background: var(--orange);
    border: none;
    border-radius: 10px;
    color: var(--text-inverse);
    font-weight: 700;
    font-size: 15px;
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-family: 'Poppins', sans-serif;
    margin-top: 4px;
}

.btn-checkout:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-orange-md);
    filter: brightness(1.1);
}

.btn-checkout:active {
    transform: translateY(0);
}

.loyalty-section {
    margin-top: 16px;
    padding-top: 12px;
    border-top: 1px solid var(--border);
}

.loyalty-section .btn-loyalty {
    background: var(--orange);
    color: var(--text-inverse);
    border: none;
    padding: 8px 16px;
    border-radius: 50px;
    font-weight: 600;
    font-size: 12px;
    cursor: pointer;
    transition: var(--transition);
    font-family: 'Poppins', sans-serif;
}

.loyalty-section .btn-loyalty:hover {
    background: var(--orange-dark);
    transform: translateY(-2px);
    box-shadow: var(--shadow-orange-sm);
}

@media (max-width: 1024px) {
    .cart-container { grid-template-columns: 1fr; }
    .order-summary { position: static; }
}

@media (max-width: 768px) {
    body { padding: 12px; }
    .cart-container { gap: 16px; }
    .cart-items { padding: 16px; }
    .cart-header h1 { font-size: 18px; }
    .cart-item img { width: 48px; height: 48px; }
    .item-actions { width: 100%; justify-content: flex-end; padding-top: 8px; }
    .order-summary { padding: 16px; }
    .order-summary h2 { font-size: 16px; }
    .payment-methods { flex-direction: row; justify-content: center; }
    .payment-method { min-width: 80px; padding: 8px 12px; font-size: 13px; }
}

@media (max-width: 480px) {
    body { padding: 8px; }
    .cart-items { padding: 12px; }
    .cart-header { flex-direction: column; align-items: flex-start; gap: 4px; }
    .cart-header h1 { font-size: 16px; }
    .cart-item img { width: 40px; height: 40px; }
    .cart-item .item-info h3 { font-size: 13px; }
    .qty-control button { width: 24px; height: 24px; font-size: 14px; }
    .qty-control input[type="number"] { width: 30px; font-size: 12px; }
    .remove-btn { width: 28px; height: 28px; font-size: 12px; }
    .top-bar { flex-direction: column; align-items: stretch; }
    .top-bar a { justify-content: center; }
    .payment-method { flex: 1 1 100%; min-width: unset; padding: 10px; font-size: 13px; }
    .summary-row { font-size: 13px; }
    .summary-row.total { font-size: 16px; }
}

/* ── Clear Cart ── */
.btn-clear-cart {
    display: inline-flex; align-items: center; gap: 5px;
    background: transparent; border: 1px solid var(--danger);
    color: var(--danger); padding: 5px 12px; border-radius: 8px;
    font-size: 12px; font-weight: 600; cursor: pointer;
    transition: var(--transition); font-family: 'Poppins', sans-serif;
}
.btn-clear-cart:hover { background: var(--danger); color: #fff; }

/* ── Cart item remove animation ── */
.cart-item.removing {
    opacity: 0; transform: translateX(30px);
    transition: all 0.3s ease;
    pointer-events: none;
}

/* ── Name presets ── */
.name-presets {
    display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px;
}
.preset-btn {
    padding: 4px 11px; border-radius: 20px;
    border: 1px solid var(--border); background: transparent;
    color: var(--text-secondary); font-size: 11px; font-weight: 600;
    cursor: pointer; transition: var(--transition);
    font-family: 'Poppins', sans-serif; white-space: nowrap;
}
.preset-btn:hover {
    border-color: var(--orange); color: var(--orange);
    background: rgba(209,144,75,0.08);
}
.preset-btn.active {
    background: var(--orange); border-color: var(--orange); color: #000;
}

/* ── Cash change calculator ── */
.change-calc {
    display: none; margin-top: 10px; padding: 14px;
    background: rgba(85,224,135,0.05); border-radius: 12px;
    border: 1px solid rgba(85,224,135,0.2);
    animation: fadeInUp 0.25s ease both;
}
.change-calc.visible { display: block; }
.change-calc label {
    font-size: 12px; color: var(--text-secondary); font-weight: 600;
    display: block; margin-bottom: 6px;
}
.change-calc input {
    width: 100%; padding: 9px 12px; border-radius: 8px;
    border: 1px solid rgba(85,224,135,0.35);
    background: var(--bg-body); color: var(--text-primary);
    font-size: 16px; font-weight: 700; font-family: 'Poppins', sans-serif;
    outline: none; text-align: right;
}
.change-calc input:focus { border-color: #55e087; box-shadow: 0 0 0 3px rgba(85,224,135,0.1); }
/* Quick tender — the note the customer actually handed over, one tap */
.tender-quick { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
.tender-btn {
    flex: 1 1 auto; min-width: 52px; padding: 7px 9px; border-radius: 8px;
    border: 1.5px solid rgba(85,224,135,0.3); background: var(--bg-input, transparent);
    color: var(--text-secondary); font-family: 'Poppins',sans-serif;
    font-size: 12.5px; font-weight: 700; cursor: pointer; transition: all .15s;
}
.tender-btn:hover { border-color: #55e087; color: #2e9c5a; }
.tender-btn.active {
    background: rgba(85,224,135,0.16); border-color: #55e087; color: #2e9c5a;
    box-shadow: 0 0 0 2px rgba(85,224,135,0.15);
}
.change-row {
    display: flex; justify-content: space-between; align-items: center;
    margin-top: 10px; padding-top: 8px; border-top: 1px solid rgba(85,224,135,0.15);
}
.change-row .change-label { font-size: 12px; font-weight: 600; color: var(--text-secondary); }
.change-row .change-amount { font-size: 20px; font-weight: 800; color: #55e087; }
.change-row .change-amount.not-enough { color: var(--danger); font-size: 14px; }

/* ── Keyboard shortcuts bar ── */
.shortcuts-bar {
    display: flex; flex-wrap: wrap; gap: 8px 14px;
    padding: 10px 0 2px; margin-top: 10px;
    border-top: 1px solid var(--border);
}
.sc {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; color: var(--text-muted);
}
.sc kbd {
    background: var(--bg-body); border: 1px solid var(--border);
    border-radius: 4px; padding: 1px 6px;
    font-size: 10px; font-weight: 700; color: var(--text-secondary);
    font-family: 'Poppins', sans-serif;
}

/* ── Dynamic confirm button ── */
.btn-checkout.bakong-selected { background: #3498db; }
.btn-checkout.cash-selected   { background: #55e087; color: #000; }
.btn-checkout.paylater-selected { background: #9b59b6; }
.btn-checkout.riel-selected   { background: linear-gradient(135deg, #e74c3c, #c0392b); }
.btn-checkout.split-selected  { background: linear-gradient(135deg, #3498db, #55e087); }

@keyframes fadeInUp {
    from { opacity:0; transform:translateY(8px); }
    to   { opacity:1; transform:translateY(0); }
}

/* Stand picker cells: scrolls (STAND_COUNT is configurable) but no visible
   scrollbar. overflow-x hidden or the reserved gutter pushes the columns wide
   enough to add a horizontal bar too. */
.stand-cells {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(42px, 1fr));
    gap: 6px; max-height: 190px; overflow-y: auto; overflow-x: hidden;
    scrollbar-width: none; -ms-overflow-style: none;
}
.stand-cells::-webkit-scrollbar { display: none; }
</style>
</head>

<body>

<!-- TOP BAR -->
<div class="top-bar">
    <a href="menu.php">
        <i class="fa-solid fa-arrow-left"></i> Continue Ordering
    </a>
    <span style="color: var(--text-secondary); font-size: 14px;">
        <i class="fa-solid fa-cart-shopping" style="color: var(--orange);"></i> 
        <span class="cart-count"><?= $total_qty ?></span> items in cart
    </span>
</div>

<div class="cart-container">

    <!-- CART ITEMS -->
    <div class="cart-items">
        <div class="cart-header">
            <h1><i class="fa-solid fa-cart-shopping"></i> Your Cart</h1>
            <div style="display:flex;align-items:center;gap:10px;">
                <span class="item-count" id="header-item-count"><?= $total_qty ?> item<?= $total_qty != 1 ? 's' : '' ?></span>
                <?php if(!empty($cart)): ?>
                <button class="btn-clear-cart" onclick="clearCart()" title="Clear all items">
                    <i class="fa-solid fa-trash"></i> Clear All
                </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if(empty($cart)): ?>
            <div class="empty-cart">
                <i class="fa-regular fa-face-smile"></i>
                <h2>Your cart is empty</h2>
                <p>Looks like you haven't added anything to your cart yet.</p>
                <a href="menu.php" class="btn-shop">
                    <i class="fa-solid fa-mug-hot"></i> Browse Menu
                </a>
            </div>
        <?php else: ?>

            <?php foreach($cart as $i => $item): 
                $qty = isset($item['qty']) ? (int)$item['qty'] : 1;
                $lineTotal = $item['price'] * $qty;
            ?>
            <div class="cart-item">
                <img src="<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['product_name']) ?>">
                
                <div class="item-info">
                    <h3><?= htmlspecialchars($item['product_name']) ?></h3>
                    <div class="item-meta">
                        <?php if (!empty($item['size_label'])): ?>
                            <span>Size: <?= htmlspecialchars($item['size_label']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($item['sweetness'])): ?>
                            <span>Sweet: <?= htmlspecialchars($item['sweetness']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($item['ice'])): ?>
                            <span> • Ice: <?= htmlspecialchars($item['ice']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($item['milk'])): ?>
                            <span> • Milk: <?= htmlspecialchars($item['milk']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($item['addons'])): ?>
                            <span> • <?= htmlspecialchars(implode(', ', array_map(fn($a) => $a['name'], $item['addons']))) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="item-price">
                        $<span id="item-total-<?= $i ?>"><?= number_format($lineTotal, 2) ?></span>
                    </div>
                </div>

                <div class="item-actions">
                    <div class="qty-control">
                        <button type="button" onclick="changeQty(<?= $i ?>, -1)">−</button>
                        <input type="number" id="qty-<?= $i ?>" value="<?= $qty ?>" min="1" 
                               onchange="updateQtyInput(<?= $i ?>, this.value)"
                               oninput="validateQtyInput(this)">
                        <button type="button" onclick="changeQty(<?= $i ?>, 1)">+</button>
                    </div>
                    <button type="button" class="remove-btn" onclick="removeItem(<?= $i ?>, this)" title="Remove item">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="cart-item" id="free-item-row"
                 style="<?= $buy3_discount_amount > 0 ? 'border-top:1px dashed #27ae60;margin-top:4px;padding-top:14px;' : 'display:none' ?>">
                <div style="width:64px;height:64px;display:flex;align-items:center;justify-content:center;font-size:28px;background:linear-gradient(135deg,#e8f5e9,#f0fff4);border-radius:12px;flex-shrink:0;">🎁</div>
                <div class="item-info">
                    <h3><span id="free-item-name-text"><?= htmlspecialchars($_free_item_name_pg) ?></span> <span style="background:#27ae60;color:#fff;font-size:10px;padding:2px 8px;border-radius:20px;font-weight:700;letter-spacing:0.5px;vertical-align:middle;">FREE</span></h3>
                    <div class="item-meta">Buy <?= BUY_X_COUNT ?> Get 1 Free</div>
                    <div class="item-price" id="free-item-price-display" style="color:#27ae60;">FREE <span style="text-decoration:line-through;color:#aaa;font-size:12px;font-weight:400;">was $<?= number_format($_free_item_price_pg < PHP_FLOAT_MAX ? $_free_item_price_pg : 0, 2) ?></span></div>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <!-- ORDER SUMMARY -->
    <?php if(!empty($cart)): ?>
    <div class="order-summary">
        <h2><i class="fa-solid fa-receipt"></i> Order Summary</h2>
        
        <div class="summary-row">
            <span>Subtotal</span>
            <span id="summary-subtotal">$<?= $subtotal_formatted ?></span>
        </div>
        
        <div class="summary-row discount" id="buy3-discount-row" style="display:none">
            <span>🎉 Buy <?= BUY_X_COUNT ?> Get 1 Free</span>
            <span id="buy3-discount-amount">-$<?= $buy3_discount_formatted ?></span>
        </div>
        
        <div class="summary-row discount" id="happy-hour-discount-row"
             style="<?= $happy_hour_discount_amount > 0 ? '' : 'display:none' ?>">
            <span>🌅 Happy Hour (<?= HAPPY_HOUR_DISCOUNT ?>% off)</span>
            <span id="happy-hour-discount-amount">-$<?= $happy_hour_discount_formatted ?></span>
        </div>

        <!-- Manual cashier discount -->
        <div class="summary-row discount" id="manual-discount-row"
             style="<?= $manual_discount_amount > 0 ? '' : 'display:none' ?>">
            <span id="manual-discount-label">🏷️ <?= htmlspecialchars($manual_discount_label) ?></span>
            <span id="manual-discount-amount">-$<?= $manual_discount_formatted ?></span>
        </div>

        <!-- Add Discount Panel -->
        <div id="discount-panel">
            <?php if ($manual_discount_amount > 0): ?>
            <button type="button" class="discount-toggle-btn remove" onclick="clearDiscount()">
                <i class="fa-solid fa-xmark"></i> Remove Discount
            </button>
            <?php else: ?>
            <button type="button" class="discount-toggle-btn" id="addDiscountBtn" onclick="openDiscountForm()">
                <i class="fa-solid fa-tag"></i> Add Discount
            </button>
            <?php endif; ?>

            <div id="discount-form" style="display:none">
                <div class="discount-type-row">
                    <button type="button" class="dtype-btn active" id="dtype-percent" onclick="setDType('percent')">% Percent</button>
                    <button type="button" class="dtype-btn" id="dtype-flat" onclick="setDType('flat')">$ Flat</button>
                </div>
                <div class="discount-inputs">
                    <input type="number" id="discount-amount-input" placeholder="0" min="0" step="0.01">
                    <input type="text"   id="discount-reason-input" placeholder="Reason (e.g. Staff, VIP)" maxlength="100">
                </div>
                <div class="discount-form-actions">
                    <button type="button" class="btn-apply-discount" onclick="applyDiscount()">
                        <i class="fa-solid fa-check"></i> Apply
                    </button>
                    <button type="button" class="btn-cancel-discount" onclick="closeDiscountForm()">Cancel</button>
                </div>
            </div>
        </div>

        <div class="summary-row">
            <span>Tax (<?= TAX_RATE ?>%)</span>
            <span id="summary-tax">$<?= $tax_formatted ?></span>
        </div>
        
        <div class="summary-row total">
            <span>Total</span>
            <span id="summary-total">$<?= $total_formatted ?></span>
        </div>

        <!-- ── LOYALTY SECTION ── -->
        <div class="loyalty-section" id="loyaltySectionCart">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h3 style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin: 0; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-star" style="color: var(--orange);"></i>
                        Loyalty Card
                    </h3>
                    <p id="loyaltyCartStatus" style="font-size: 12px; margin: 4px 0 0; color: <?= $linked_loyalty ? 'var(--orange)' : 'var(--text-secondary)' ?>;">
                        <?php if ($linked_loyalty): ?>
                            <i class="fa-solid fa-circle-check"></i>
                            <?= htmlspecialchars($linked_loyalty['loyalty_id']) ?> &mdash; <?= (int)$linked_loyalty['points'] ?> pts
                        <?php else: ?>
                            Earn 1 point per drink. Redeem for free items!
                        <?php endif; ?>
                    </p>
                </div>
                <button class="btn-loyalty" onclick="openLoyaltyModal()" id="loyaltyCartBtn">
                    <?php if ($linked_loyalty): ?>
                        <i class="fa-solid fa-circle-check"></i> Linked
                    <?php else: ?>
                        <i class="fa-solid fa-credit-card"></i> Loyalty
                    <?php endif; ?>
                </button>
            </div>
        </div>

        <!-- ── PAYMENT METHODS ── -->
        <div class="payment-section">
            <h3><i class="fa-solid fa-credit-card"></i> Payment</h3>
            
            <div class="payment-methods" id="paymentMethods">
                <div class="payment-method" onclick="togglePayment(this)">
                    <input type="checkbox" value="bakong">
                    <span class="method-icon">📱</span>
                    <span class="method-label">Bakong</span>
                </div>

                <div class="payment-method" onclick="togglePayment(this)">
                    <input type="checkbox" value="cash">
                    <span class="method-icon">💵</span>
                    <span class="method-label">Cash</span>
                </div>

                <div class="payment-method" onclick="togglePayment(this)">
                    <input type="checkbox" value="paylater">
                    <span class="method-icon">⏰</span>
                    <span class="method-label">Pay Later</span>
                </div>

                <?php /* Riel is taken inside the Cash tender on menu.php's payment modal now.
                         cart.php is a duplicate checkout surface whose entry link was dropped in
                         fec47c6, so it gets no riel field of its own. Hidden rather than deleted
                         because riel is still a valid payment_method for 4 historical orders.
                         To restore, change false back to true. */ ?>
                <?php if (false): ?>
                <div class="payment-method" onclick="togglePayment(this)">
                    <input type="checkbox" value="riel">
                    <span class="method-icon">🇰🇭</span>
                    <span class="method-label">Riel ៛</span>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- ── Split Payment Inputs ── -->
            <div class="payment-split-inputs" id="splitInputs">
                <div id="splitRows"></div>
            </div>
        </div>

        <!-- Cash change calculator (shown when Cash is selected) -->
        <div class="change-calc" id="changeCalc">
            <label><i class="fa-solid fa-money-bill-wave" style="color:#55e087;margin-right:5px;"></i>Amount Received</label>
            <!-- oninput never fires for a programmatic .value set, so this flag marks
                 only a cashier-entered amount and stops the prefill overwriting it. -->
            <input type="number" id="cashReceived" step="0.01" min="0" placeholder="0.00"
                   oninput="this.dataset.touched='1'; calcChange(); markActiveTender(this.value)" onfocus="this.select()">
            <div class="tender-quick" id="tenderQuick"></div>
            <div class="change-row">
                <span class="change-label">Change to give back</span>
                <span class="change-amount" id="changeAmount">$0.00</span>
            </div>
        </div>

        <?php /* Riel calculator — hidden with its tile above. */ ?>
        <?php if (false): ?>
        <!-- Riel calculator (shown when Riel solo is selected) -->
        <div class="change-calc" id="rielCalc">
            <label><i class="fa-solid fa-coins" style="color:#e74c3c;margin-right:5px;"></i>Amount in Riel (KHR)</label>
            <input type="number" id="rielReceived" step="1" min="0" placeholder="0"
                   oninput="calcRielChange()" onfocus="this.select()">
            <div class="change-row">
                <span class="change-label">USD Equivalent</span>
                <span class="change-amount" id="rielUsdEquiv">$0.00</span>
            </div>
            <div class="change-row" id="rielChangeRow" style="display:none;">
                <span class="change-label">Change (KHR)</span>
                <span class="change-amount" id="rielChangeKhr">&#x17DB;0</span>
            </div>
        </div>
        <?php endif; ?>

        <form method="post" action="confirm_order.php" class="checkout-form" id="checkoutForm">
            <div class="form-group">
                <label><i class="fa-solid fa-mug-hot"></i> Order Type</label>
                <div class="drink-type-toggle">
                    <button type="button" class="drink-type-btn active" id="btnDrinkIn" onclick="setDrinkType('drink_in')">
                        <i class="fa-solid fa-mug-hot"></i> Drink In
                    </button>
                    <button type="button" class="drink-type-btn" id="btnDrinkOut" onclick="setDrinkType('drink_out')">
                        <i class="fa-solid fa-bag-shopping"></i> Drink Out
                    </button>
                </div>
                <input type="hidden" name="order_type" id="orderTypeInput" value="drink_in">
            </div>
            <div class="form-group">
                <label for="customer_name"><i class="fa-regular fa-user"></i> Customer Name</label>
                <input type="text" name="customer_name" id="customer_name"
                       placeholder="Leave blank for Guest">
            </div>
            <div class="form-group" id="tableNumberGroup">
                <label for="table_number" style="display:flex;align-items:center;gap:4px;"><i class="fa-solid fa-ticket"></i> Stand Number <span style="color:#f0ad4e;font-weight:600;font-size:12px;">(required for Drink In)</span><button type="button" onclick="toggleStandGrid('standGrid','table_number','standWarn','standWarnText')" style="margin-left:auto;background:none;border:1px solid var(--border-color,#ccc);border-radius:6px;padding:2px 8px;font-size:11px;cursor:pointer;color:var(--text-secondary,#888);display:flex;align-items:center;gap:4px;"><i class="fa-solid fa-table-cells-large"></i> Pick</button></label>
                <input type="text" name="table_number" id="table_number" maxlength="10"
                       placeholder="e.g. 1, 7, 12..." onblur="checkStandNumber(this.value,'standWarn','standWarnText')">
                <div id="standWarn" style="display:none;margin-top:6px;padding:7px 10px;background:rgba(255,193,7,.12);border:1px solid rgba(255,193,7,.4);border-radius:8px;font-size:12px;color:#856404;align-items:center;gap:6px;"><i class="fa-solid fa-triangle-exclamation" style="color:#f0ad4e;"></i> <span id="standWarnText"></span></div>
                <div id="standGrid" style="display:none;margin-top:6px;background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:10px;padding:10px;box-shadow:0 4px 16px rgba(0,0,0,.1);"></div>
            </div>

            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <?php /* Mode comes from the session on purpose: menu.php clears
                     add_to_order_id the moment the cashier returns to a plain menu,
                     so a stale value cannot survive to hijack a normal checkout.
                     Do NOT add a second session read here to "fix" this — deriving
                     the flag from the same key confirm_order.php:95 is guarding
                     against is what made that guard circular in the first place.
                     Accepted gap: reaching this page through a payment-error
                     "Go back" link while still in add-to-order mode keeps the flag.
                     See docs/superpowers/specs/2026-08-02-add-to-order-cart-stash-design.md §5 */ ?>
            <input type="hidden" name="is_add_to_order" value="<?= $add_to_order_id > 0 ? '1' : '0' ?>">
            <div id="paymentInputs"></div>

            <button type="submit" class="btn-checkout" id="confirmBtn">
                <i class="fa-solid fa-credit-card" id="confirmIcon"></i>
                <span id="confirmText">Confirm Order</span>
            </button>

            <!-- Keyboard shortcut hints -->
            <div class="shortcuts-bar">
                <span class="sc"><kbd>B</kbd> Bakong</span>
                <span class="sc"><kbd>C</kbd> Cash</span>
                <span class="sc"><kbd>P</kbd> Pay Later</span>
                <span class="sc"><kbd>Enter</kbd> Confirm</span>
                <span class="sc"><kbd>Esc</kbd> Back</span>
            </div>
        </form>
    </div>
    <?php endif; ?>

</div>

<!-- ── LOYALTY MODAL ── -->
<div id="loyaltyModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(8px); z-index: 9999; justify-content: center; align-items: center;">
    <div class="modal-content" style="background: var(--bg-card); border-radius: 16px; padding: 32px; max-width: 420px; width: 90%; position: relative; border: 1px solid var(--border); box-shadow: var(--shadow-lg); animation: modalIn 0.3s ease;">
        <span onclick="closeLoyaltyModal()" style="position: absolute; right: 16px; top: 12px; font-size: 24px; color: var(--text-muted); cursor: pointer; transition: var(--transition);">
            <i class="fa-solid fa-xmark"></i>
        </span>
        
        <div style="text-align: center; margin-bottom: 20px;">
            <i class="fa-solid fa-star" style="font-size: 40px; color: var(--orange);"></i>
            <h2 style="font-size: 20px; margin: 8px 0 4px; color: var(--text-primary);">Loyalty Card</h2>
            <p style="font-size: 13px; color: var(--text-secondary);">Enter your loyalty ID to view points and redeem rewards</p>
        </div>
        
        <div style="display: flex; gap: 10px; margin-bottom: 16px;">
            <input type="text" id="loyaltyIdInput" placeholder="Enter loyalty ID (e.g., CARD-12345)" 
                   style="flex: 1; padding: 10px 14px; border-radius: 10px; border: 1px solid var(--border); background: var(--bg-body); color: var(--text-primary); font-family: 'Poppins', sans-serif; font-size: 14px; outline: none;">
            <button onclick="lookupLoyalty()" style="background: var(--orange); color: var(--text-inverse); border: none; padding: 10px 20px; border-radius: 10px; font-weight: 600; cursor: pointer; transition: var(--transition); font-family: 'Poppins', sans-serif;">
                <i class="fa-solid fa-magnifying-glass"></i>
            </button>
        </div>
        
        <div id="loyaltyResult" style="display: none; padding: 16px; background: rgba(255,255,255,0.03); border-radius: 12px; border: 1px solid var(--border);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <div>
                    <div style="font-size: 12px; color: var(--text-secondary);">Loyalty ID</div>
                    <div id="loyaltyDisplayId" style="font-size: 16px; font-weight: 700; color: var(--orange);"></div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 12px; color: var(--text-secondary);">Points</div>
                    <div id="loyaltyPoints" style="font-size: 20px; font-weight: 700; color: var(--text-primary);">0</div>
                </div>
            </div>
            
            <div id="loyaltyRewards" style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px;">
                <!-- Rewards will be loaded here -->
            </div>
            
            <div id="loyaltyHistory" style="margin-top: 12px; max-height: 120px; overflow-y: auto; font-size: 12px; color: var(--text-secondary); border-top: 1px solid var(--border); padding-top: 8px;">
                <!-- History will be loaded here -->
            </div>
        </div>
        
        <div id="loyaltyError" style="display: none; padding: 12px; background: rgba(231,76,60,0.08); border-radius: 10px; border: 1px solid rgba(231,76,60,0.2); color: #e74c3c; text-align: center; font-size: 13px;">
            <i class="fa-solid fa-circle-exclamation"></i> Card not found. Please check the ID and try again.
        </div>
    </div>
</div>

<script>
const KHR_RATE = <?= defined('KHR_RATE') ? (int)KHR_RATE : 4100 ?>;

// ── Change Quantity ──
function changeQty(index, delta) {
    const input = document.getElementById("qty-" + index);
    let qty = parseInt(input.value || "1", 10) + delta;
    if (qty < 1) qty = 1;
    input.value = qty;
    updateQtyAjax(index, qty);
}

function updateQtyInput(index, value) {
    let qty = parseInt(value || "1", 10);
    if (isNaN(qty) || qty < 1) qty = 1;
    document.getElementById("qty-" + index).value = qty;
    updateQtyAjax(index, qty);
}

function validateQtyInput(input) {
    if (input.value < 1) input.value = 1;
    if (input.value > 999) input.value = 999;
}

// ── Update Qty AJAX ──
function updateQtyAjax(index, qty) {
    fetch("cart.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `ajax_update=1&index=${index}&qty=${qty}`
    })
    .then(res => res.json())
    .then(data => {
        if (!data) return;
        const el = document.getElementById("item-total-" + index);
        if (el && data.itemLineTotal !== undefined) el.textContent = data.itemLineTotal;
        refreshSummaryFromData(data);
        updateSplitAmounts();
    });
}

function getCartTotal() {
    const totalEl = document.getElementById('summary-total');
    if (!totalEl) return 0;
    return parseFloat(totalEl.textContent.replace('$', '').replace(/,/g, ''));
}

// ── AJAX Remove Item ──
function removeItem(index, btn) {
    const row = btn.closest('.cart-item');
    row.classList.add('removing');
    setTimeout(() => {
        fetch('cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `ajax_remove=1&index=${index}`
        })
        .then(r => r.json())
        .then(data => {
            row.remove();
            refreshSummaryFromData(data);
            updateSplitAmounts();
            // If cart empty, reload to show empty state
            if (data.remaining === 0) location.reload();
        });
    }, 280); // let the CSS transition play
}

// ── Clear Cart ──
function clearCart() {
    if (!confirm('Remove all items from the cart?')) return;
    fetch('cart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax_clear=1'
    }).then(() => location.reload());
}

// ── Shared summary updater ──
function refreshSummaryFromData(data) {
    const setEl = (id, val) => { const el = document.getElementById(id); if(el) el.textContent = val; };
    setEl('summary-subtotal', '$' + data.cartSubtotal);
    setEl('summary-tax',      '$' + data.tax);
    setEl('summary-total',    '$' + data.cartTotal);

    const badge = document.querySelector('.cart-count');
    if (badge) badge.textContent = data.cartCount;
    const hc = document.getElementById('header-item-count');
    if (hc) hc.textContent = data.cartCount + ' item' + (data.cartCount != 1 ? 's' : '');

    const b3r = document.getElementById('buy3-discount-row');
    if (b3r) b3r.style.display = 'none';
    const freeRow = document.getElementById('free-item-row');
    if (freeRow) {
        const b3v = parseFloat(data.buy3_discount || 0);
        if (b3v > 0) {
            freeRow.style.display = 'flex';
            freeRow.style.borderTop = '1px dashed #27ae60';
            freeRow.style.marginTop = '4px';
            freeRow.style.paddingTop = '14px';
            const nameEl = document.getElementById('free-item-name-text');
            if (nameEl && data.free_item_name) nameEl.textContent = data.free_item_name;
            const priceEl = document.getElementById('free-item-price-display');
            if (priceEl && data.free_item_price) priceEl.innerHTML = 'FREE <span style="text-decoration:line-through;color:#aaa;font-size:12px;font-weight:400;">was $' + data.free_item_price + '</span>';
        } else {
            freeRow.style.display = 'none';
        }
    }
    const hhr = document.getElementById('happy-hour-discount-row');
    const hha = document.getElementById('happy-hour-discount-amount');
    if (hhr && hha) {
        const v = parseFloat(data.happy_hour_discount);
        hha.textContent = '-$' + data.happy_hour_discount;
        hhr.style.display = v > 0 ? 'flex' : 'none';
    }
    const mdr = document.getElementById('manual-discount-row');
    const mda = document.getElementById('manual-discount-amount');
    const mdl = document.getElementById('manual-discount-label');
    if (mdr && mda) {
        const v = parseFloat(data.manual_discount || '0');
        mda.textContent = '-$' + (data.manual_discount || '0.00');
        if (mdl && data.manual_discount_label) mdl.textContent = '🏷️ ' + data.manual_discount_label;
        mdr.style.display = v > 0 ? 'flex' : 'none';
    }
    calcChange();
}

// ── Manual Discount ──
let _discountType = 'percent';

function openDiscountForm() {
    document.getElementById('addDiscountBtn').style.display = 'none';
    document.getElementById('discount-form').style.display = 'block';
    document.getElementById('discount-amount-input').focus();
}
function closeDiscountForm() {
    document.getElementById('discount-form').style.display = 'none';
    const btn = document.getElementById('addDiscountBtn');
    if (btn) btn.style.display = 'flex';
}
function setDType(type) {
    _discountType = type;
    document.getElementById('dtype-percent').classList.toggle('active', type === 'percent');
    document.getElementById('dtype-flat').classList.toggle('active', type === 'flat');
    document.getElementById('discount-amount-input').placeholder = type === 'percent' ? '0  (e.g. 10 = 10%)' : '0.00  (e.g. 5.00)';
}
function applyDiscount() {
    const amount = parseFloat(document.getElementById('discount-amount-input').value) || 0;
    const reason = document.getElementById('discount-reason-input').value.trim();
    if (amount <= 0) { alert('Please enter a discount amount.'); return; }
    fetch('cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'ajax_apply_discount=1&type=' + encodeURIComponent(_discountType) + '&amount=' + amount + '&reason=' + encodeURIComponent(reason)
    }).then(() => location.reload());
}
function clearDiscount() {
    fetch('cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'ajax_clear_discount=1'
    }).then(() => location.reload());
}

// ── Name presets ──
function setName(name, btn) {
    document.getElementById('customer_name').value = name;
    document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}
function clearPresetActive() {
    document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
}

/* Seed "Amount Received" with what is actually owed in cash — see menu.php for the
   reasoning. Only fills an untouched field, so a typed amount is never overwritten. */
function owedInCash() {
    const split = document.querySelector('.split-amount[data-method="cash"]');
    if (split) return parseFloat(split.value) || 0;
    return getCartTotal();
}

function prefillCashReceived() {
    const cr = document.getElementById('cashReceived');
    if (!cr) return;
    if (cr.dataset.touched !== '1') {
        const owed = owedInCash();
        cr.value = owed > 0 ? owed.toFixed(2) : '';
        calcChange();
    }
    renderTenderQuick();
}

/* One tap for the note actually handed over — see menu.php for the reasoning. */
function renderTenderQuick() {
    const wrap = document.getElementById('tenderQuick');
    if (!wrap) return;
    wrap.innerHTML = '';

    const owed = owedInCash();
    if (owed <= 0) return;

    const mk = (label, val) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'tender-btn';
        b.textContent = label;
        b.dataset.tender = val.toFixed(2);
        b.addEventListener('click', () => setTender(val));
        return b;
    };

    wrap.appendChild(mk('Exact', owed));
    [1, 5, 10, 20, 50, 100].filter(d => d > owed).slice(0, 4)
                           .forEach(d => wrap.appendChild(mk('$' + d, d)));

    markActiveTender(parseFloat(document.getElementById('cashReceived').value) || 0);
}

function setTender(val) {
    const cr = document.getElementById('cashReceived');
    if (!cr) return;
    cr.value = Number(val).toFixed(2);
    cr.dataset.touched = '1';
    calcChange();
    markActiveTender(val);
}

function markActiveTender(val) {
    const v = Number(val).toFixed(2);
    document.querySelectorAll('#tenderQuick .tender-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.tender === v);
    });
}

// ── Cash change calculator ──
function calcChange() {
    const received = parseFloat(document.getElementById('cashReceived')?.value) || 0;
    const total    = getCartTotal();
    const change   = received - total;
    const el       = document.getElementById('changeAmount');
    if (!el) return;
    if (received === 0) { el.textContent = '$0.00'; el.className = 'change-amount'; return; }
    if (change < 0) {
        el.textContent = 'Need $' + Math.abs(change).toFixed(2) + ' more';
        el.className   = 'change-amount not-enough';
    } else {
        el.textContent = '$' + change.toFixed(2);
        el.className   = 'change-amount';
    }
}

// ── Riel (KHR) calculator ──
function calcRielChange() {
    const khr      = parseFloat(document.getElementById('rielReceived')?.value) || 0;
    const usdEl    = document.getElementById('rielUsdEquiv');
    const changeRow = document.getElementById('rielChangeRow');
    const changeEl  = document.getElementById('rielChangeKhr');
    if (usdEl) usdEl.textContent = '$' + (khr / KHR_RATE).toFixed(2);
    if (changeRow && changeEl) {
        const orderKhr = Math.round(getCartTotal() * KHR_RATE / 100) * 100;
        const diff     = Math.round(khr) - orderKhr;
        if (khr > 0 && diff < 0) {
            changeEl.textContent = 'Need ៛' + Math.abs(diff).toLocaleString();
            changeEl.className   = 'change-amount not-enough';
            changeRow.style.display = 'flex';
        } else if (khr > 0 && diff >= 0) {
            changeEl.textContent = '៛' + diff.toLocaleString();
            changeEl.className   = 'change-amount';
            changeRow.style.display = 'flex';
        } else {
            changeRow.style.display = 'none';
        }
    }
}

// ── Dynamic confirm button ──
function updateConfirmBtn(selected) {
    const btn      = document.getElementById('confirmBtn');
    const icon     = document.getElementById('confirmIcon');
    const text     = document.getElementById('confirmText');
    const calc     = document.getElementById('changeCalc');
    const rielCalc = document.getElementById('rielCalc');
    if (!btn) return;

    btn.className = 'btn-checkout';
    if (calc)     calc.classList.remove('visible');
    if (rielCalc) rielCalc.classList.remove('visible');

    if (selected.includes('paylater')) {
        icon.className = 'fa-solid fa-clock';
        text.textContent = 'Place Pay Later Order';
        btn.classList.add('paylater-selected');
    } else if (selected.length > 1) {
        // any split combination
        icon.className = 'fa-solid fa-layer-group';
        text.textContent = 'Confirm Split Payment';
        btn.classList.add('split-selected');
        if (selected.includes('cash') && calc) {
            calc.classList.add('visible');
            prefillCashReceived();
            setTimeout(() => { const c = document.getElementById('cashReceived'); if (c) { c.focus(); c.select(); } }, 50);
        }
    } else if (selected.includes('riel')) {
        icon.className = 'fa-solid fa-coins';
        text.textContent = 'Confirm Riel Payment';
        btn.classList.add('riel-selected');
        if (rielCalc) {
            rielCalc.classList.add('visible');
            // Pre-fill KHR equivalent of total
            const total = getCartTotal();
            const rielInput = document.getElementById('rielReceived');
            if (rielInput && !rielInput.value) rielInput.value = Math.round(total * KHR_RATE / 100) * 100;
            calcRielChange();
            setTimeout(() => rielInput?.focus(), 50);
        }
    } else if (selected.includes('cash')) {
        icon.className = 'fa-solid fa-money-bill-wave';
        text.textContent = 'Confirm Cash Payment';
        btn.classList.add('cash-selected');
        if (calc) calc.classList.add('visible');
        prefillCashReceived();
        setTimeout(() => { const c = document.getElementById('cashReceived'); if (c) { c.focus(); c.select(); } }, 50);
    } else if (selected.includes('bakong')) {
        icon.className = 'fa-solid fa-qrcode';
        text.textContent = 'Generate Bakong QR';
        btn.classList.add('bakong-selected');
    } else {
        icon.className = 'fa-solid fa-credit-card';
        text.textContent = 'Confirm Order';
    }
}

// ── Payment Methods ──
function togglePayment(label) {
    const cb = label.querySelector('input[type="checkbox"]');
    const value = cb.value;
    
    // ── Pay Later and Riel are solo-only ──
    if (value === 'paylater' || value === 'riel') {
        document.querySelectorAll('.payment-method input[type="checkbox"]').forEach(c => {
            c.checked = false;
        });
        cb.checked = true;
    } else {
        const payLater = document.querySelector('.payment-method input[value="paylater"]');
        if (payLater && payLater.checked) payLater.checked = false;
        const rielCb = document.querySelector('.payment-method input[value="riel"]');
        if (rielCb && rielCb.checked) rielCb.checked = false;
        cb.checked = !cb.checked;
    }
    // Reset solo-riel KHR input when riel is deselected
    if (value === 'riel' && !cb.checked) {
        const ri = document.getElementById('rielReceived');
        if (ri) ri.value = '';
    }
    
    document.querySelectorAll('.payment-method').forEach(el => {
        el.classList.remove('selected');
        if (el.querySelector('input[type="checkbox"]').checked) {
            el.classList.add('selected');
        }
    });
    
    // Update confirm button and change calculator
    const selected = [];
    document.querySelectorAll('.payment-method input[type="checkbox"]:checked').forEach(cb => {
        selected.push(cb.value);
    });
    updateConfirmBtn(selected);
    updateSplitInputs();
}

// ── Update Split Inputs ──
function updateSplitInputs() {
    const selected = [];
    document.querySelectorAll('.payment-method input[type="checkbox"]:checked').forEach(cb => {
        selected.push(cb.value);
    });
    const splitInputs = document.getElementById('splitInputs');
    const splitRows = document.getElementById('splitRows');
    
    if (selected.includes('paylater') || selected.length <= 1) {
        splitInputs.classList.remove('active');
        splitRows.innerHTML = '';
        return;
    }
    
    splitInputs.classList.add('active');
    const total = getCartTotal();
    
    const splitAmount = Math.floor((total / selected.length) * 100) / 100;
    const remainder = Math.round((total - (splitAmount * selected.length)) * 100) / 100;
    
    let html = '';
    selected.forEach((method, index) => {
        const usdAmount = index === selected.length - 1
            ? (splitAmount + remainder).toFixed(2)
            : splitAmount.toFixed(2);
        if (method === 'riel') {
            const khrAmount = Math.round(parseFloat(usdAmount) * KHR_RATE / 100) * 100;
            html += `
                <div class="split-row">
                    <label>Riel ៛</label>
                    <div style="display:flex;flex-direction:column;gap:3px;flex:1;">
                        <input type="number" step="100" class="split-amount"
                               value="${khrAmount}"
                               data-method="riel" data-currency="khr"
                               oninput="onSplitInputChange(this)">
                        <span class="khr-usd-display" style="font-size:11px;color:var(--text-muted);">≈ $${usdAmount}</span>
                    </div>
                </div>
            `;
        } else {
            const label = method.charAt(0).toUpperCase() + method.slice(1);
            html += `
                <div class="split-row">
                    <label>${label}</label>
                    <input type="number" step="0.01" class="split-amount"
                           value="${usdAmount}"
                           data-method="${method}"
                           oninput="onSplitInputChange(this)">
                </div>
            `;
        }
    });
    splitRows.innerHTML = html;
}

// ── Convert input to USD regardless of currency ──
function inputToUsd(inp) {
    const val = Math.max(0, parseFloat(inp.value) || 0);
    return inp.dataset.currency === 'khr' ? val / KHR_RATE : val;
}

// ── Set input value (handles KHR vs USD) ──
function setInputUsd(inp, usd) {
    if (inp.dataset.currency === 'khr') {
        inp.value = Math.round(usd * KHR_RATE / 100) * 100;
        const display = inp.parentElement?.querySelector('.khr-usd-display');
        if (display) display.textContent = '≈ $' + usd.toFixed(2);
    } else {
        inp.value = usd.toFixed(2);
    }
}

// ── Handle manual split input changes ──
function onSplitInputChange(changedInput) {
    const total = getCartTotal();
    const inputs = [...document.querySelectorAll('.split-amount')];
    const changedUsd = inputToUsd(changedInput);
    const others = inputs.filter(inp => inp !== changedInput);

    // Update KHR display for the changed input
    if (changedInput.dataset.currency === 'khr') {
        const display = changedInput.parentElement?.querySelector('.khr-usd-display');
        if (display) display.textContent = '≈ $' + changedUsd.toFixed(2);
    }

    if (others.length === 1) {
        const remaining = total - changedUsd;
        if (remaining < 0) {
            setInputUsd(changedInput, total);
            setInputUsd(others[0], 0);
        } else {
            setInputUsd(others[0], remaining);
        }
    } else if (others.length > 1) {
        const remaining = Math.max(0, total - changedUsd);
        const each = Math.floor(remaining / others.length * 100) / 100;
        const lastAmt = Math.round((remaining - each * (others.length - 1)) * 100) / 100;
        others.forEach((inp, i) => {
            setInputUsd(inp, i === others.length - 1 ? lastAmt : each);
        });
    }
}

// ── Update Split Amounts ──
function updateSplitAmounts() {
    const total = getCartTotal();
    const selected = [];
    
    document.querySelectorAll('.payment-method input[type="checkbox"]:checked').forEach(cb => {
        selected.push(cb.value);
    });
    
    if (selected.length >= 2) {
        const splitInputs = document.getElementById('splitInputs');
        const splitRows = document.getElementById('splitRows');
        
        splitInputs.classList.add('active');
        
        const splitAmount = Math.floor((total / selected.length) * 100) / 100;
        const remainder = Math.round((total - (splitAmount * selected.length)) * 100) / 100;
        
        let html = '';
        selected.forEach((method, index) => {
            const usdAmount = index === selected.length - 1
                ? (splitAmount + remainder).toFixed(2)
                : splitAmount.toFixed(2);
            if (method === 'riel') {
                const khrAmount = Math.round(parseFloat(usdAmount) * KHR_RATE / 100) * 100;
                html += `
                    <div class="split-row">
                        <label>Riel ៛</label>
                        <div style="display:flex;flex-direction:column;gap:3px;flex:1;">
                            <input type="number" step="100" class="split-amount"
                                   value="${khrAmount}"
                                   data-method="riel" data-currency="khr"
                                   oninput="onSplitInputChange(this)">
                            <span class="khr-usd-display" style="font-size:11px;color:var(--text-muted);">≈ $${usdAmount}</span>
                        </div>
                    </div>
                `;
            } else {
                const label = method.charAt(0).toUpperCase() + method.slice(1);
                html += `
                    <div class="split-row">
                        <label>${label}</label>
                        <input type="number" step="0.01" class="split-amount"
                               value="${usdAmount}"
                               data-method="${method}"
                               oninput="onSplitInputChange(this)">
                    </div>
                `;
            }
        });
        splitRows.innerHTML = html;
    }
}

// ── Checkout Form Submit ──
// ── STAND NUMBER GATE ──
// Mirrors menu.php: a Drink In order with no stand can't be delivered, so it blocks.
// Softens to an override only when every stand is occupied (or occupancy can't be
// read), so the till is never stuck. See standGateShow below.
let __standChecked = false;

document.getElementById('checkoutForm').addEventListener('submit', function(e) {
    e.preventDefault();

    if (!__standChecked) {
        const __form = this;
        // requestSubmit() is ignored while this submit event is still firing, so the
        // resubmit is deferred a tick — the gate's sync path (stand already filled)
        // would otherwise silently do nothing.
        requireStand(function() {
            __standChecked = true;
            setTimeout(function() { __form.requestSubmit(); }, 0);
        });
        return;
    }
    __standChecked = false;   // reset so the next attempt is re-checked

    const selected = [];
    const selectedAmounts = [];
    
    document.querySelectorAll('.payment-method input[type="checkbox"]:checked').forEach(cb => {
        selected.push(cb.value);
    });
    
    if (selected.length === 0) {
        alert('Please select at least one payment method');
        return;
    }
    
    const total = getCartTotal();
    const splits = document.querySelectorAll('.split-amount');

    if (splits.length > 0 && document.getElementById('splitInputs').classList.contains('active')) {
        // Sum in USD (KHR inputs converted)
        let sumUsd = 0;
        splits.forEach(inp => { sumUsd += inputToUsd(inp); });
        if (Math.abs(sumUsd - total) > 0.005) {
            const last = splits[splits.length - 1];
            const diff = total - sumUsd;
            if (last.dataset.currency === 'khr') {
                last.value = Math.max(0, Math.round(((parseFloat(last.value) || 0) + diff * KHR_RATE) / 100) * 100);
            } else {
                last.value = Math.max(0, parseFloat(last.value) + diff).toFixed(2);
            }
        }
        splits.forEach(inp => {
            selectedAmounts.push(parseFloat(inp.value).toFixed(2));
        });
    } else {
        selected.forEach(() => {
            selectedAmounts.push(total.toFixed(2));
        });
    }
    
    const container = document.getElementById('paymentInputs');
    container.innerHTML = '';

    selected.forEach((method, index) => {
        let usdAmount = selectedAmounts[index] || '0';
        let reference = '';

        if (method === 'riel') {
            // For solo riel: read from #rielReceived; for split: from the KHR split input
            const khrInput = selected.length > 1
                ? document.querySelector('.split-amount[data-method="riel"]')
                : document.getElementById('rielReceived');
            const khrVal = Math.max(0, parseFloat(khrInput?.value) || 0);
            usdAmount = (khrVal / KHR_RATE).toFixed(2);
            reference = Math.round(khrVal).toString();
        }
        if (method === 'cash') {
            const received = parseFloat(document.getElementById('cashReceived')?.value) || 0;
            if (received > 0) reference = received.toFixed(2);
        }

        const h1 = document.createElement('input');
        h1.type = 'hidden'; h1.name = 'payment_methods[]'; h1.value = method;
        container.appendChild(h1);

        const h2 = document.createElement('input');
        h2.type = 'hidden'; h2.name = 'payment_amounts[]'; h2.value = usdAmount;
        container.appendChild(h2);

        const h3 = document.createElement('input');
        h3.type = 'hidden'; h3.name = 'payment_references[]'; h3.value = reference;
        container.appendChild(h3);
    });

    this.submit();
});

// ── DOM Ready ──
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.payment-method input[type="checkbox"]').forEach(cb => {
        cb.checked = false;
    });
    updateSplitInputs();

    // ── Keyboard shortcuts ──
    document.addEventListener('keydown', function(e) {
        // Ignore when typing in an input/textarea
        const tag = document.activeElement.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

        switch (e.key.toLowerCase()) {
            case 'b': // Bakong
                e.preventDefault();
                clickPaymentLabel('bakong');
                break;
            case 'c': // Cash
                e.preventDefault();
                clickPaymentLabel('cash');
                break;
            case 'p': // Pay Later
                e.preventDefault();
                clickPaymentLabel('paylater');
                break;
            case 'r': // Riel
                e.preventDefault();
                clickPaymentLabel('riel');
                break;
            case 'escape':
                window.location.href = 'menu.php';
                break;
        }
    });

    // Enter key submits the form when no input is focused
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            const tag = document.activeElement.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA') return;
            const form = document.getElementById('checkoutForm');
            if (form) form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        }
    });
});

function setDrinkType(type) {
    document.getElementById('orderTypeInput').value = type;
    document.getElementById('btnDrinkIn').classList.toggle('active', type === 'drink_in');
    document.getElementById('btnDrinkOut').classList.toggle('active', type === 'drink_out');
    document.getElementById('tableNumberGroup').style.display = (type === 'drink_in') ? '' : 'none';
    if (type === 'drink_out') document.getElementById('table_number').value = '';
}

function clickPaymentLabel(method) {
    const el = document.querySelector(`.payment-method input[value="${method}"]`)?.closest('.payment-method');
    if (el) el.click();
}

// ── LOYALTY FUNCTIONS ──

function openLoyaltyModal() {
    document.getElementById('loyaltyModal').style.display = 'flex';
    document.getElementById('loyaltyIdInput').focus();
}

function closeLoyaltyModal() {
    document.getElementById('loyaltyModal').style.display = 'none';
    document.getElementById('loyaltyResult').style.display = 'none';
    document.getElementById('loyaltyError').style.display = 'none';
}

async function lookupLoyalty() {
    const loyaltyId = document.getElementById('loyaltyIdInput').value.trim();
    if (!loyaltyId) {
        showToast('Please enter a loyalty ID', 'error');
        return;
    }
    
    try {
        const res = await fetch(`loyalty_lookup.php?loyalty_id=${encodeURIComponent(loyaltyId)}`);
        const data = await res.json();
        
        if (!data.found) {
            document.getElementById('loyaltyResult').style.display = 'none';
            document.getElementById('loyaltyError').style.display = 'block';
            return;
        }
        
        document.getElementById('loyaltyError').style.display = 'none';
        document.getElementById('loyaltyResult').style.display = 'block';
        document.getElementById('loyaltyDisplayId').textContent = data.loyalty_id;
        document.getElementById('loyaltyPoints').textContent = data.points;
        
        // Load rewards
        const rewardsHtml = data.rewards.map(reward => {
            const canRedeem = data.points >= reward.points_required;
            return `
                <div style="padding: 12px; border-radius: 8px; border: 1px solid ${canRedeem ? 'var(--orange)' : 'var(--border)'}; text-align: center; background: ${canRedeem ? 'rgba(209,144,75,0.08)' : 'rgba(0,0,0,0.02)'};">
                    <div style="font-weight: 600; color: var(--text-primary);">${reward.reward_name}</div>
                    <div style="font-size: 12px; color: var(--text-secondary);">${reward.points_required} pts</div>
                    ${canRedeem ? 
                        `<button onclick="redeemReward('${reward.reward_name}', ${reward.points_required})" style="margin-top: 4px; padding: 4px 12px; border-radius: 50px; border: none; background: var(--orange); color: #000; font-weight: 600; font-size: 11px; cursor: pointer; font-family: 'Poppins', sans-serif;">
                            Redeem
                        </button>` : 
                        `<button disabled style="margin-top: 4px; padding: 4px 12px; border-radius: 50px; border: none; background: #ccc; color: #666; font-weight: 600; font-size: 11px; cursor: not-allowed; font-family: 'Poppins', sans-serif;">
                            Need ${reward.points_required} pts
                        </button>`
                    }
                </div>
            `;
        }).join('');
        document.getElementById('loyaltyRewards').innerHTML = rewardsHtml;
        
        // Load history
        const historyHtml = data.history.map(h => {
            const sign = h.points_change > 0 ? '+' : '';
            const color = h.points_change > 0 ? 'var(--success)' : 'var(--danger)';
            return `
                <div style="display: flex; justify-content: space-between; padding: 4px 0; border-bottom: 1px solid var(--border);">
                    <span style="color: var(--text-secondary);">${h.type.charAt(0).toUpperCase() + h.type.slice(1)} ${h.reward_name ? ' - ' + h.reward_name : ''}</span>
                    <span style="color: ${color}; font-weight: 600;">${sign}${h.points_change}</span>
                </div>
            `;
        }).join('');
        document.getElementById('loyaltyHistory').innerHTML = historyHtml || '<div style="text-align: center; color: var(--text-muted);">No history yet</div>';

        // Update the cart section indicator to show the linked card
        const statusEl = document.getElementById('loyaltyCartStatus');
        const btnEl    = document.getElementById('loyaltyCartBtn');
        if (statusEl) {
            statusEl.style.color = 'var(--orange)';
            statusEl.innerHTML = '<i class="fa-solid fa-circle-check"></i> ' + data.loyalty_id + ' &mdash; ' + data.points + ' pts';
        }
        if (btnEl) {
            btnEl.innerHTML = '<i class="fa-solid fa-circle-check"></i> Linked';
        }

    } catch (error) {
        console.error('Loyalty lookup error:', error);
        showToast('Error looking up loyalty card', 'error');
    }
}

async function redeemReward(rewardName, pointsRequired) {
    const loyaltyId = document.getElementById('loyaltyDisplayId').textContent;
    
    if (!confirm(`Redeem ${rewardName} for ${pointsRequired} points?`)) {
        return;
    }
    
    try {
        const res = await fetch('loyalty_redeem.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `loyalty_id=${encodeURIComponent(loyaltyId)}&reward_name=${encodeURIComponent(rewardName)}`
        });
        const data = await res.json();
        
        if (data.success) {
            // ── UPDATE UI IN REAL-TIME ──
            // Update points display
            document.getElementById('loyaltyPoints').textContent = data.new_points;
            
            // Re-render rewards with updated points
            const rewardsContainer = document.getElementById('loyaltyRewards');
            rewardsContainer.innerHTML = '';
            
            data.rewards.forEach(reward => {
                const canRedeem = data.new_points >= reward.points_required;
                const div = document.createElement('div');
                div.style.cssText = `
                    padding: 12px;
                    border-radius: 8px;
                    border: 1px solid ${canRedeem ? 'var(--orange)' : 'var(--border)'};
                    text-align: center;
                    background: ${canRedeem ? 'rgba(209,144,75,0.08)' : 'rgba(0,0,0,0.02)'};
                `;
                div.innerHTML = `
                    <div style="font-weight: 600; color: var(--text-primary);">${reward.reward_name}</div>
                    <div style="font-size: 12px; color: var(--text-secondary);">${reward.points_required} pts</div>
                    <button ${canRedeem ? `onclick="redeemReward('${reward.reward_name}', ${reward.points_required})"` : 'disabled'}
                            style="
                                margin-top: 4px;
                                padding: 4px 12px;
                                border-radius: 50px;
                                border: none;
                                background: ${canRedeem ? 'var(--orange)' : '#ccc'};
                                color: ${canRedeem ? '#000' : '#666'};
                                font-weight: 600;
                                font-size: 11px;
                                cursor: ${canRedeem ? 'pointer' : 'not-allowed'};
                                font-family: 'Poppins', sans-serif;
                            ">
                        ${canRedeem ? 'Redeem' : `Need ${reward.points_required} pts`}
                    </button>
                `;
                rewardsContainer.appendChild(div);
            });
            
            // Add to history
            const historyList = document.getElementById('loyaltyHistory');
            const historyItem = document.createElement('div');
            historyItem.style.cssText = 'display: flex; justify-content: space-between; padding: 4px 0; border-bottom: 1px solid var(--border);';
            historyItem.innerHTML = `
                <span style="color: var(--text-secondary);">Redeemed - ${data.reward_name}</span>
                <span style="color: var(--danger); font-weight: 600;">-${data.points_required}</span>
            `;
            historyList.prepend(historyItem);
            
            // ── DO NOT reload the page ──
            // The rewards are stored in session, not in cart
            // They will be added to the order when confirmed
            
            showToast('✅ ' + data.message, 'success');
        } else {
            showToast(data.message || 'Error redeeming reward', 'error');
        }
    } catch (error) {
        console.error('Redeem error:', error);
        showToast('Error redeeming reward', 'error');
    }
}

// ── Stand occupancy grid ──
var STAND_MAX = <?= STAND_COUNT ?>;

// ── Stand number gate (see the submit handler above) ──
// Mirrors menu.php. The only way past is the takeaway path, which REQUIRES a
// customer name — the barista card shows a name only when it isn't 'Guest'
// (view_order.php), so a nameless drink_out order is still a mystery drink.
function standGateShow(freeCount, onTakeaway) {
    var allBusy = freeCount === 0;
    var old = document.getElementById('standGate');
    if (old) old.remove();
    var wrap = document.createElement('div');
    wrap.id = 'standGate';
    wrap.style.cssText = 'position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.7);display:flex;align-items:center;justify-content:center;padding:20px;';
    wrap.innerHTML =
      '<div style="background:var(--bg-card,#161616);border:1px solid rgba(255,193,7,.35);border-radius:16px;padding:26px 24px;max-width:380px;width:100%;text-align:center;box-shadow:0 12px 40px rgba(0,0,0,.45);">' +
        '<div style="font-size:38px;color:#f0ad4e;margin-bottom:12px;"><i class="fa-solid fa-hashtag"></i></div>' +
        '<div style="font-size:17px;font-weight:700;color:var(--text-primary,#fff);margin-bottom:8px;">Stand Number Required</div>' +
        '<div style="font-size:13px;color:var(--text-muted,#9a9a9a);line-height:1.6;margin-bottom:18px;">' +
          (allBusy
            ? 'Every stand is currently in use. Ask staff to release returned placards on the Stands page, or hand this order over the counter.'
            : 'This is a <b>Drink In</b> order. Without a stand number, staff won\'t know where to deliver it.') +
        '</div>' +
        '<div id="standGateChoices" style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">' +
          '<button type="button" id="standGateSet" style="padding:11px 20px;border-radius:50px;border:none;background:#d1904b;color:#fff;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;">' +
            '<i class="fa-solid fa-table-cells-large"></i> Set Stand</button>' +
          '<button type="button" id="standGateTa" style="padding:11px 20px;border-radius:50px;border:1px solid var(--border-color,rgba(255,255,255,.2));background:transparent;color:var(--text-muted,#bbb);font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;">' +
            '<i class="fa-solid fa-bag-shopping"></i> No stand — Takeaway</button>' +
        '</div>' +
        '<div id="standGateName" style="display:none;margin-top:16px;text-align:left;">' +
          '<div style="font-size:12px;color:var(--text-muted,#9a9a9a);margin-bottom:6px;">Customer name so the barista can call it out:</div>' +
          '<input type="text" id="standGateNameInput" maxlength="120" placeholder="e.g. Sokha" ' +
            'style="width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--border-color,rgba(255,255,255,.15));background:var(--bg-card-hover,#101010);color:var(--text-primary,#eee);font-size:13px;font-family:inherit;">' +
          '<div id="standGateNameErr" style="display:none;margin-top:6px;font-size:11.5px;color:#ff6b6b;"></div>' +
          '<button type="button" id="standGateTaGo" style="margin-top:10px;width:100%;padding:11px 20px;border-radius:50px;border:none;background:#d1904b;color:#fff;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;">' +
            '<i class="fa-solid fa-check"></i> Confirm Takeaway</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(wrap);
    function close() { wrap.remove(); }
    wrap.addEventListener('click', function(e) { if (e.target === wrap) close(); });

    document.getElementById('standGateSet').onclick = function() {
        close();
        var inp = document.getElementById('table_number');
        if (inp) inp.focus();
        var grid = document.getElementById('standGrid');
        if (grid && grid.style.display === 'none') toggleStandGrid('standGrid', 'table_number', 'standWarn', 'standWarnText');
    };

    document.getElementById('standGateTa').onclick = function() {
        document.getElementById('standGateChoices').style.display = 'none';
        document.getElementById('standGateName').style.display = 'block';
        var ni = document.getElementById('standGateNameInput');
        var existing = document.querySelector('[name="customer_name"]');
        if (existing && existing.value.trim()) ni.value = existing.value.trim();
        ni.focus();
    };

    function submitTakeaway() {
        var ni  = document.getElementById('standGateNameInput');
        var err = document.getElementById('standGateNameErr');
        var name = ni.value.trim();
        if (name === '' || name.toLowerCase() === 'guest') {
            err.textContent = name === '' ? 'Enter a name — the barista has no stand to deliver to.'
                                          : '"Guest" won\'t help the barista find them. Use a real name.';
            err.style.display = 'block';
            ni.focus();
            return;
        }
        var cn = document.querySelector('[name="customer_name"]');
        if (cn) cn.value = name;
        var tn = document.getElementById('table_number');
        if (tn) tn.value = '';
        var ot = document.getElementById('orderTypeInput');
        if (ot) ot.value = 'drink_out';
        if (typeof setDrinkType === 'function') { try { setDrinkType('drink_out'); } catch (e) {} }
        close();
        onTakeaway();
    }
    document.getElementById('standGateTaGo').onclick = submitTakeaway;
    document.getElementById('standGateNameInput').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); submitTakeaway(); }
    });
}

function requireStand(onOk) {
    var typeEl = document.getElementById('orderTypeInput');
    if (!typeEl || typeEl.value !== 'drink_in') { onOk(); return; }
    var inp = document.getElementById('table_number');
    if (inp && inp.value.trim() !== '') { onOk(); return; }
    fetch('get_stands.php')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var active = d.stands || {}, free = 0;
            for (var i = 1; i <= STAND_MAX; i++) { if (!active[String(i)]) free++; }
            standGateShow(free, onOk);
        })
        .catch(function() { standGateShow(0, onOk); });  // lookup failed — allow override
}

function toggleStandGrid(gridId, inputId, warnId, warnTextId) {
    var grid = document.getElementById(gridId);
    if (!grid) return;
    if (grid.style.display !== 'none') { grid.style.display = 'none'; return; }
    loadStandGrid(gridId, inputId, warnId, warnTextId);
}

function loadStandGrid(gridId, inputId, warnId, warnTextId) {
    var grid = document.getElementById(gridId);
    grid.innerHTML = '<div style="text-align:center;padding:10px;color:#888;font-size:12px;"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div>';
    grid.style.display = 'block';
    fetch('get_stands.php')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var active = data.stands || {};
            var cells = '';
            for (var i = 1; i <= STAND_MAX; i++) {
                var key = String(i);
                var info = active[key];
                if (info) {
                    var tip = 'Order #' + info.order_no + (info.customer ? ' (' + info.customer + ')' : '') + ' — ' + info.status;
                    cells += '<div title="' + tip.replace(/"/g, '&quot;') + '" style="display:flex;align-items:center;justify-content:center;height:36px;border-radius:7px;font-size:13px;font-weight:600;cursor:not-allowed;background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;position:relative;">' + i + '<span style="position:absolute;top:-2px;right:-2px;width:8px;height:8px;border-radius:50%;background:#ef4444;border:1px solid #fff;"></span></div>';
                } else {
                    cells += '<div onclick="pickStand(' + i + ',\'' + inputId + '\',\'' + gridId + '\',\'' + warnId + '\',\'' + warnTextId + '\')" style="display:flex;align-items:center;justify-content:center;height:36px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;background:#dcfce7;color:#15803d;border:1px solid #86efac;transition:transform .1s;" onmouseover="this.style.transform=\'scale(1.1)\'" onmouseout="this.style.transform=\'\'">' + i + '</div>';
                }
            }
            // Cells scroll inside a capped box (.stand-cells) — STAND_COUNT is
            // configurable (70+ is valid) and an uncapped grid runs off the page.
            grid.innerHTML =
                '<div class="stand-cells">' + cells + '</div>' +
                '<div style="margin-top:8px;font-size:10px;color:#888;display:flex;gap:12px;">' +
                '<span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#dcfce7;border:1px solid #86efac;vertical-align:middle;margin-right:3px;"></span>Free — click to pick</span>' +
                '<span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#fee2e2;border:1px solid #fca5a5;vertical-align:middle;margin-right:3px;"></span>In use — hover for details</span>' +
                '</div>';
        })
        .catch(function() {
            grid.innerHTML = '<div style="text-align:center;padding:10px;color:#ef4444;font-size:12px;">Could not load stands</div>';
        });
}

function pickStand(num, inputId, gridId, warnId, warnTextId) {
    var inp = document.getElementById(inputId);
    if (inp) { inp.value = num; checkStandNumber(String(num), warnId, warnTextId); }
    var grid = document.getElementById(gridId);
    if (grid) grid.style.display = 'none';
}

// ── Stand number duplicate check ──
function checkStandNumber(val, warnId, warnTextId) {
    var warn = document.getElementById(warnId);
    if (!warn) return;
    val = (val || '').trim();
    if (!val) { warn.style.display = 'none'; return; }
    fetch('check_stand.php?stand=' + encodeURIComponent(val))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.in_use) {
                document.getElementById(warnTextId).textContent =
                    'Stand ' + val + ' is in use by Order #' + data.order_no +
                    (data.customer ? ' (' + data.customer + ')' : '') + ' – ' + data.status;
                warn.style.display = 'flex';
            } else {
                warn.style.display = 'none';
            }
        })
        .catch(function() { warn.style.display = 'none'; });
}

function showToast(message, type = 'success') {
    const container = document.getElementById('toast-container');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    container.appendChild(toast);

    requestAnimationFrame(() => toast.classList.add('show'));

    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 400);
    }, 3000);
}
</script>

</body>
</html>