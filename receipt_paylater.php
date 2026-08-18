<?php
require 'config.php';
require 'dompdf/dompdf/autoload.inc.php';
date_default_timezone_set('Asia/Phnom_Penh');



use Dompdf\Dompdf;
use Dompdf\Options;

mb_internal_encoding('UTF-8');

$order_id = (int)($_GET['order_id'] ?? 0);

if ($order_id <= 0) {
    die("Invalid order");
}

// ── FETCH ORDER ──
$stmt = $conn->prepare("
    SELECT o.order_id, 'Guest' AS customer_name, o.total, o.order_date, o.order_id AS daily_order_no, 0 AS promotion_discount, o.order_id AS token_number,
           0 AS manual_discount, '' AS manual_discount_reason, 'Completed' AS status,
           COALESCE(NULLIF(u.name, ''), u.username, o.prepared_by, 'Staff') AS employee_name,
           'drink_in' AS order_type, '' AS completed_at, o.started_at, '' AS table_number
    FROM orders o
    LEFT JOIN users u ON u.user_id = o.user_id
    WHERE o.order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    die("Order not found");
}

// ── FETCH ITEMS ──
$stmt = $conn->prepare("
    SELECT product_name, price, quantity, size_label, sweetness, ice, milk, addons_snapshot, promo_percent, made_qty
    FROM order_items
    WHERE order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$items = $stmt->get_result();

// ── SEPARATE DRINKS FROM REWARDS ──
$drinks = [];
$rewards = [];
$subtotal = 0;
$total_qty = 0;
$pending_qty = 0;   // drink units the barista hasn't made yet — counted BEFORE the merge below

while ($item = $items->fetch_assoc()) {
    if (strpos($item['product_name'], '(Loyalty)') !== false) {
        $rewards[] = $item;
    } else {
        $drinks[] = $item;
        $subtotal += $item['price'] * $item['quantity'];
        $total_qty += $item['quantity'];
        $pending_qty += max(0, (int)$item['quantity'] - (int)($item['made_qty'] ?? 0));
    }
}

// Merge identical drink lines into one ×N for a cleaner receipt (made-state doesn't matter to
// the customer; totals are unchanged since amount = price × summed quantity).
$__merged = [];
foreach ($drinks as $__d) {
    $__k = $__d['product_name'].'|'.($__d['size_label'] ?? '').'|'.($__d['sweetness'] ?? '').'|'.($__d['ice'] ?? '').'|'.($__d['milk'] ?? '').'|'.($__d['addons_snapshot'] ?? '').'|'.$__d['price'].'|'.($__d['promo_percent'] ?? 0);
    if (isset($__merged[$__k])) $__merged[$__k]['quantity'] += $__d['quantity'];
    else $__merged[$__k] = $__d;
}
$drinks = array_values($__merged);

$discount = (float)($order['promotion_discount'] ?? 0);
$manual_discount_rpl = (float)($order['manual_discount'] ?? 0);
$manual_reason_rpl   = trim($order['manual_discount_reason'] ?? '');
$stored_total = (float)($order['total'] ?? 0);
$tax_rate = TAX_RATE / 100;

// ── RECALCULATE BUY 3 GET 1 FREE FOR DISPLAY ──
$min_price = PHP_FLOAT_MAX;
$min_item_name = '';
foreach ($drinks as $item) {
    if ($item['price'] < $min_price) {
        $min_price = $item['price'];
        $min_item_name = $item['product_name'];
    }
}
$free_items    = BUY_X_GET_1_ENABLED ? floor($total_qty / BUY_X_COUNT) : 0;
$buy3_discount = ($free_items > 0 && $min_price < PHP_FLOAT_MAX) ? $free_items * $min_price : 0;

// Free-item override (Settings > Free Drink) — use the configured product (e.g. Brown Sugar),
// matching menu.php. Falls back to the cheapest in-cart item only when no override is set.
$free_item_name  = $min_item_name;
$free_item_price = ($min_price < PHP_FLOAT_MAX) ? $min_price : 0;
if (defined('FREE_ITEM_PRODUCT_ID') && FREE_ITEM_PRODUCT_ID > 0) {
    if ($__ovr = $conn->query("SELECT name, price FROM products WHERE product_id = " . (int)FREE_ITEM_PRODUCT_ID)) {
        if ($__ov = $__ovr->fetch_assoc()) { $free_item_name = $__ov['name']; $free_item_price = (float)$__ov['price']; }
    }
}

// ── RECALCULATE HAPPY HOUR FOR DISPLAY (using order creation time) ──
$happy_hour_discount = 0;
$order_hour = (int)date('H', strtotime($order['order_date']));
if (HAPPY_HOUR_ENABLED && $order_hour >= HAPPY_HOUR_START && $order_hour < HAPPY_HOUR_END) {
    $happy_hour_discount = $subtotal * (HAPPY_HOUR_DISCOUNT / 100);
}

// ── AUTHORITATIVE TOTAL ──
$computed_subtotal_after = $subtotal - $discount;
$computed_total = round($computed_subtotal_after * (1 + $tax_rate), 2);

if (abs($computed_total - $stored_total) > 0.009) {
    $total                   = $stored_total;
    $subtotal_after_discount = $stored_total / (1 + $tax_rate);
    $tax                     = $stored_total - $subtotal_after_discount;
} else {
    $subtotal_after_discount = $computed_subtotal_after;
    $tax                     = $subtotal_after_discount * $tax_rate;
    $total                   = $computed_total;
}

// ── Order type & time labels ──
$order_type_label = ($order['order_type'] ?? 'drink_in') === 'drink_out' ? 'Drink Out' : 'Drink In';
$time_in_src      = !empty($order['started_at']) ? $order['started_at'] : $order['order_date'];
$time_in_label    = date("d-m-Y g:i A", strtotime($time_in_src));
$time_out_src     = !empty($order['completed_at']) ? $order['completed_at'] : $order['order_date'];
$time_out_label   = date("d-m-Y g:i A", strtotime($time_out_src));

// ── GENERATE HTML FOR PDF ──
$html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Pay Later Receipt #' . $order['daily_order_no'] . '</title>
    <style>
        @page {
            margin: 5mm;
            size: 80mm auto;
        }
        body {
            font-family: "Courier New", Courier, monospace;
            font-size: 11px;
            width: 80mm;
            margin: 0 auto;
            color: #1a1a1a;
        }
        .header {
            text-align: center;
            margin-bottom: 10px;
        }
        .header h1 {
            font-size: 17px;
            font-weight: 700;
            margin: 0 0 3px 0;
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        .header .tagline {
            font-size: 8px;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #888;
            margin-bottom: 4px;
        }
        .header .info {
            font-size: 9px;
            margin: 1px 0;
            color: #555;
        }
        .divider {
            border-top: 1px solid #000;
            margin: 4px 0;
        }
        .divider-dash {
            border-top: 1px dashed #000;
            margin: 4px 0;
        }
        .customer-section {
            text-align: right;
            font-size: 10px;
            margin: 6px 0;
        }
        .customer-section .row {
            padding: 1px 0;
        }
        .customer-section .label {
            font-weight: 600;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            margin: 4px 0;
        }
        th {
            text-align: right;
            font-weight: 600;
            border-bottom: 1px solid #000;
            padding: 4px 0;
        }
        th:first-child {
            text-align: left;
        }
        td {
            padding: 3px 0;
            text-align: right;
        }
        td:first-child {
            text-align: left;
        }
        .item-name {
            font-weight: 600;
        }
        .col-qty { width: 10%; }
        .col-price { width: 15%; }
        .col-amount { width: 15%; }
        .total-section {
            text-align: right;
            margin: 6px 0;
            font-size: 10px;
        }
        .total-section .row {
            display: flex;
            justify-content: flex-end;
            padding: 2px 0;
        }
        .total-section .row .label {
            font-weight: 600;
            width: 105px;
            text-align: right;
        }
        .total-section .row .value {
            width: 75px;
            text-align: right;
        }
        .total-section .row.discount {
            color: #c0392b;
        }
        .grand-total {
            font-size: 13px;
            font-weight: 700;
            border-top: 1.5px solid #000;
            padding-top: 5px;
            margin-top: 4px;
        }
        .footer {
            text-align: center;
            margin-top: 12px;
            font-size: 9px;
            color: #666;
            letter-spacing: 1px;
        }
        .rewards-section {
            margin-top: 8px;
            padding-top: 6px;
            border-top: 1px dashed #000;
        }
        .rewards-section .title {
            font-weight: 700;
            font-size: 10px;
            text-align: center;
            color: #000;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .rewards-section .reward-item {
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            padding: 2px 0;
        }
        .rewards-section .reward-item .name {
            font-weight: 600;
        }
        .rewards-section .reward-item .value {
            color: #2a7a2a;
            font-weight: 700;
        }
        .pay-later-stamp {
            text-align: center;
            margin: 10px 0;
            padding: 10px;
            border: 2px solid #9b59b6;
            border-radius: 8px;
            color: #9b59b6;
            font-weight: 800;
            font-size: 24px;
            letter-spacing: 4px;
            text-transform: uppercase;
        }
    </style>
</head>
<body>

<div class="header">
    <h1>THE BIRD\'S NEST COFFEE</h1>
    <div class="tagline">- Specialty Coffee &amp; More -</div>
    <div class="info">2F, Chbar Ampov, Phnom Penh</div>
    <div class="info">Tel: 061 929 304</div>
</div>

<div class="divider"></div>

<div class="customer-section">
    <div class="row"><span class="label">Order #:</span> ' . $order['daily_order_no'] . '</div>
    <div class="row"><span class="label">Cashier:</span> ' . htmlspecialchars($order['employee_name'] ?: 'N/A') . '</div>
    <div class="row"><span class="label">Customer:</span> ' . htmlspecialchars($order['customer_name']) . '</div>
    ' . (!empty($order['table_number']) ? '<div class="row"><span class="label">Stand:</span> <strong>' . htmlspecialchars($order['table_number']) . '</strong></div>' : '') . '
    <div class="row"><span class="label">Order Type:</span> <strong>' . $order_type_label . '</strong></div>
    <div class="row"><span class="label">Time In:</span> ' . $time_in_label . '</div>
    ' . ($time_out_label ? '<div class="row"><span class="label">Time Out:</span> ' . $time_out_label . '</div>' : '') . '
    <div class="row"><span class="label">Invoice No:</span> B' . str_pad($order['order_id'], 8, '0', STR_PAD_LEFT) . '</div>
</div>

<div class="divider"></div>

<!-- PAY LATER STAMP -->
<div class="pay-later-stamp">PAY LATER</div>

<div class="divider"></div>

<!-- DRINKS TABLE -->
<table>
    <thead>
        <tr>
            <th style="width: 5%; text-align: left;">#</th>
            <th style="width: 55%; text-align: left;">Description</th>
            <th class="col-qty">Qty</th>
            <th class="col-price">Price</th>
            <th class="col-amount">Amount</th>
        </tr>
    </thead>
    <tbody>';

$i = 1;
foreach ($drinks as $item) {
    $lineTotal = $item['price'] * $item['quantity'];
    $html .= '
        <tr>
            <td style="text-align: left;">' . $i++ . '</td>
            <td style="text-align: left;"><span class="item-name">' . htmlspecialchars($item['product_name']) . ((int)($item['promo_percent'] ?? 0) > 0 ? ' <span style="color:#c0392b;font-weight:bold;">(' . (int)$item['promo_percent'] . '% OFF)</span>' : '') . '</span></td>
            <td class="col-qty">' . $item['quantity'] . '</td>
            <td class="col-price">' . number_format($item['price'], 2) . '</td>
            <td class="col-amount">' . number_format($lineTotal, 2) . '</td>
        </tr>';

    if (!empty($item['size_label'])) {
        $html .= '<tr><td></td><td style="text-align: left; padding-left: 15px; font-size: 9px; color: #555;">+ Size: ' . htmlspecialchars($item['size_label']) . '</td><td></td><td></td><td></td></tr>';
    }
    if (!empty($item['sweetness'])) {
        $html .= '<tr><td></td><td style="text-align: left; padding-left: 15px; font-size: 9px; color: #555;">+ Sweetness: ' . htmlspecialchars($item['sweetness']) . '</td><td></td><td></td><td></td></tr>';
    }
    if (!empty($item['ice'])) {
        $html .= '<tr><td></td><td style="text-align: left; padding-left: 15px; font-size: 9px; color: #555;">+ Ice: ' . htmlspecialchars($item['ice']) . '</td><td></td><td></td><td></td></tr>';
    }
    if (!empty($item['milk'])) {
        $html .= '<tr><td></td><td style="text-align: left; padding-left: 15px; font-size: 9px; color: #555;">+ Milk: ' . htmlspecialchars($item['milk']) . '</td><td></td><td></td><td></td></tr>';
    }
    $__ad = json_decode($item['addons_snapshot'] ?? '[]', true) ?: [];
    foreach ($__ad as $__a) {
        $html .= '<tr><td></td><td style="text-align: left; padding-left: 15px; font-size: 9px; color: #555;">+ ' . htmlspecialchars($__a['name']) . ' +$' . number_format((float)$__a['price'], 2) . '</td><td></td><td></td><td></td></tr>';
    }
}

if ($free_items > 0 && $free_item_name !== '') {
    $html .= '
        <tr style="background:#f0fff4;">
            <td style="text-align: left;">' . $i++ . '</td>
            <td style="text-align: left;"><span class="item-name">' . htmlspecialchars($free_item_name) . '</span> <span style="background:#27ae60;color:#fff;padding:2px 8px;border-radius:4px;font-size:8px;font-weight:700;">FREE</span></td>
            <td class="col-qty">' . (int)$free_items . '</td>
            <td class="col-price" style="text-decoration:line-through;color:#999;">' . number_format($free_item_price, 2) . '</td>
            <td class="col-amount" style="color:#27ae60;font-weight:700;">FREE</td>
        </tr>';
}

$html .= '
    </tbody>
</table>

<div class="divider-dash"></div>

<div class="total-section">
    <div class="row">
        <span class="label">Sub-Total ($)</span>
        <span class="value">' . number_format($subtotal, 2) . '</span>
    </div>';

if ($happy_hour_discount > 0) {
    $html .= '
    <div class="row discount">
        <span class="label">Happy Hour (' . HAPPY_HOUR_DISCOUNT . '% off)</span>
        <span class="value">-' . number_format($happy_hour_discount, 2) . '</span>
    </div>';
}

if ($manual_discount_rpl > 0) {
    $label_rpl = $manual_reason_rpl ?: 'Cashier Discount';
    $html .= '
    <div class="row discount">
        <span class="label">' . htmlspecialchars($label_rpl) . '</span>
        <span class="value">-' . number_format($manual_discount_rpl, 2) . '</span>
    </div>';
}

$html .= '
    <div class="row">
        <span class="label">Tax (<?= TAX_RATE ?>%) ($)</span>
        <span class="value">' . number_format($tax, 2) . '</span>
    </div>
    <div class="row grand-total">
        <span class="label">Total ($)</span>
        <span class="value">' . number_format($total, 2) . '</span>
    </div>
    <div class="row" style="font-size:9px;color:#555;">
        <span class="label">Total (KHR)</span>
        <span class="value">KHR ' . number_format((int)(round($total * KHR_RATE / 100) * 100)) . '</span>
    </div>
</div>';

// ── REWARDS SECTION ──
if (!empty($rewards)) {
    $html .= '
    <div class="rewards-section">
        <div class="title">REWARDS REDEEMED</div>';

    foreach ($rewards as $reward) {
        $reward_name = str_replace([' (Loyalty)', ' FREE', 'FREE'], '', $reward['product_name']);
        $reward_name = trim($reward_name);
        $reward_name = str_replace('🎁', '[GIFT]', $reward_name);
        $html .= '
        <div class="reward-item">
            <span class="name">' . htmlspecialchars($reward_name) . '</span>
            <span class="value">FREE</span>
        </div>';
    }

    $html .= '</div>';
}

// ── LOYALTY SECTION ──
$points_balance  = 0;
$points_earned   = 0;
$points_redeemed = 0;



// Items are billed whether or not they are made yet — the total covers the whole order.
// This note just tells the customer some drinks are still coming.
$pending_note = '';
if ($pending_qty > 0) {
    $pending_note = '<p style="color: #e67e22;">* ' . (int)$pending_qty . ' item' . ($pending_qty > 1 ? 's' : '') . ' still being prepared.</p>';
}

$html .= '
<div class="footer">
    <p>Thank you for being here!</p>
    ' . $pending_note . '
    <p style="color: #9b59b6;">* This is a Pay Later order. Payment pending.</p>
</div>

</body>
</html>';

// ── GENERATE PDF ──
$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Courier');
$options->set('isHtml5ParserEnabled', true);
$options->set('isPhpEnabled', true);
$options->set('chroot', __DIR__);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// ── OUTPUT PDF ──
$dompdf->stream("paylater_receipt_" . $order['daily_order_no'] . ".pdf", array("Attachment" => false));
?>
