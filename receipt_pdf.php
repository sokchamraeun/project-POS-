<?php
require 'config.php';
require 'dompdf/dompdf/autoload.inc.php';
date_default_timezone_set('Asia/Phnom_Penh');

// Ensure columns exist (safe no-op after first run)
if ($conn->query("SHOW COLUMNS FROM orders LIKE 'order_type'")->num_rows === 0)
    $conn->query("ALTER TABLE orders ADD COLUMN order_type ENUM('drink_in','drink_out') NOT NULL DEFAULT 'drink_in'");
if ($conn->query("SHOW COLUMNS FROM orders LIKE 'completed_at'")->num_rows === 0)
    $conn->query("ALTER TABLE orders ADD COLUMN completed_at DATETIME NULL");

use Dompdf\Dompdf;
use Dompdf\Options;

// ── Fix UTF-8 encoding ──
mb_internal_encoding('UTF-8');

$order_id = (int)($_GET['order_id'] ?? 0);

if ($order_id <= 0) {
    die("Invalid order");
}

// ── FETCH ORDER ──
$stmt = $conn->prepare("
    SELECT order_id, customer_name, total, order_date, daily_order_no, promotion_discount, token_number,
           manual_discount, manual_discount_reason, status, employee_name,
           IFNULL(order_type,'drink_in') AS order_type, completed_at, table_number, started_at
    FROM orders
    WHERE order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    die("Order not found");
}

// ── Backfill completed_at for paid orders that predate the column ──
if (empty($order['completed_at']) && in_array($order['status'], ['Paid', 'Completed'])) {
    $now = date('Y-m-d H:i:s');
    $conn->query("UPDATE orders SET completed_at = '$now' WHERE order_id = $order_id AND completed_at IS NULL");
    $order['completed_at'] = $now;
}

// ── FETCH ITEMS ──
$stmt = $conn->prepare("
    SELECT product_name, price, quantity, sweetness, ice, milk, size_label, addons_snapshot, promo_percent
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

while ($item = $items->fetch_assoc()) {
    if (strpos($item['product_name'], '(Loyalty)') !== false) {
        // This is a loyalty reward
        $rewards[] = $item;
    } else {
        // This is a regular drink
        $drinks[] = $item;
        $subtotal += $item['price'] * $item['quantity'];
        $total_qty += $item['quantity'];
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
$manual_discount_receipt = (float)($order['manual_discount'] ?? 0);
$manual_reason_receipt   = trim($order['manual_discount_reason'] ?? '');
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

// Free-item override (Settings > Free Drink) — use the configured product, matching menu.php.
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

// ── AUTHORITATIVE TOTAL: always use the stored value to stay in sync with what was charged ──
$computed_subtotal_after = $subtotal - $discount;
$computed_total = round($computed_subtotal_after * (1 + $tax_rate), 2);

if (abs($computed_total - $stored_total) > 0.009) {
    // Stored total differs (e.g. rounding or manual edit) — derive everything from stored total
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
$time_out_label   = !empty($order['completed_at']) ? date("d-m-Y g:i A", strtotime($order['completed_at'])) : '';

// ── GENERATE HTML FOR PDF ──
$html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Receipt #' . $order['daily_order_no'] . '</title>
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
        .item-detail {
            font-size: 9px;
            padding-right: 0;
            color: #555;
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
    
    // Add customization lines
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
            <td style="text-align: left;"><span class="item-name">' . htmlspecialchars($free_item_name) . '</span> <span class="promo-badge" style="background:#27ae60;">FREE</span></td>
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

if ($manual_discount_receipt > 0) {
    $label = $manual_reason_receipt ?: 'Cashier Discount';
    $html .= '
    <div class="row discount">
        <span class="label">' . htmlspecialchars($label) . '</span>
        <span class="value">-' . number_format($manual_discount_receipt, 2) . '</span>
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
        // Remove "(Loyalty)" and "FREE" suffix from name to avoid duplication
        $reward_name = str_replace([' (Loyalty)', ' FREE', 'FREE'], '', $reward['product_name']);
        $reward_name = trim($reward_name);
        // Replace emoji with text label
        $reward_name = str_replace('🎁', '[GIFT]', $reward_name);
        $html .= '
        <div class="reward-item">
            <span class="name">' . htmlspecialchars($reward_name) . '</span>
            <span class="value">FREE</span>
        </div>';
    }
    
    $html .= '</div>';
}

// ── PAYMENT BREAKDOWN ──
// Include all methods with amount > 0 regardless of status — receipt is only
// generated for paid orders, and pending Bakong records (split payments where
// QR wasn't scanned yet) should still appear so the customer sees both methods.
$stmt = $conn->prepare("
    SELECT payment_method, amount, payment_status, reference
    FROM order_payments
    WHERE order_id = ? AND amount > 0
    ORDER BY FIELD(payment_method, 'cash', 'bakong', 'paylater', 'riel')
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

if (!empty($payments)) {
    // Decides whether the PAYMENT block is suppressed. Deliberately NOT widened:
    // a settled pay-later receipt must keep showing how it was paid.
    $is_solo_cash = count($payments) === 1 && $payments[0]['payment_method'] === 'cash';

    if ($is_solo_cash) {
        // Solo cash: no PAYMENT block — just show change details if cashier entered a received amount
        $ref_val      = (string)($payments[0]['reference'] ?? '');
        // tender_parts() accepts exactly two shapes where is_numeric() would
        // have taken a bare number written by anything. A riel-only tender
        // reads "0.00|5500", which is not numeric — the old is_numeric() test
        // here made $tendered_usd 0 and silently dropped the entire
        // Received/Change block, which is exactly the path a riel-only cash
        // sale takes (this is the single most common cash branch).
        $tender_p     = tender_parts($ref_val);
        $tendered_usd = tender_usd_total($ref_val);
        if ($tendered_usd > 0) {
            // Against the order total, not the payment row — see the note on the
            // split branch below: a single payment row can carry a stale amount.
            $ch = tender_change($tendered_usd, $stored_total);
            // Both labels come from config.php so the receipt and the counter
            // screen cannot drift. They did: this site used to print the raw
            // received-minus-owed difference in the no-riel branch, which misses
            // tender_change()'s carry — $5.33 on a $1.34 bill printed $3.99 here
            // and $4.00 on screen.
            $received_label = tender_received_text($tender_p, $tendered_usd);
            $change_label   = tender_change_text($ch);
            $html .= '
<div style="margin-top:6px;padding-top:4px;border-top:1px dashed #000;">
    <table width="100%" style="font-size:10px;border:none;border-collapse:collapse;">
        <tr>
            <td style="border:none;color:#666;">Received</td>
            <td align="right" style="border:none;color:#666;">' . $received_label . '</td>
        </tr>
        <tr>
            <td style="border:none;font-weight:700;">Change</td>
            <td align="right" style="border:none;font-weight:700;">' . $change_label . '</td>
        </tr>
    </table>
</div>';
        }
    } else {
        // Split or non-cash: show full PAYMENT block
        $method_labels = [
            'bakong'   => 'Bakong (KHQR)',
            'cash'     => 'Cash',
            'paylater' => 'Pay Later',
            'riel'     => 'Riel (KHR)',
        ];
        $html .= '
<div style="margin-top:6px;padding-top:4px;border-top:1px dashed #000;">
    <div style="font-weight:700;font-size:10px;text-align:center;margin-bottom:4px;letter-spacing:1.5px;text-transform:uppercase;">Payment</div>';
        foreach ($payments as $pay) {
            $label      = $method_labels[$pay['payment_method']] ?? ucfirst($pay['payment_method']);
            $ref        = (string)($pay['reference'] ?? '');
            $pay_amount = (float)$pay['amount'];
            $change_rows = '';

            if ($pay['payment_method'] === 'riel') {
                $tendered_khr = (int)($ref !== '' ? $ref : 0);
                $order_khr    = (int)(round($pay_amount * KHR_RATE / 100) * 100);
                if ($tendered_khr <= 0) $tendered_khr = $order_khr;
                $amount_display = 'KHR ' . number_format($order_khr);
                $change_khr     = $tendered_khr - $order_khr;
                if ($change_khr > 0) {
                    $change_rows = '
            <tr>
                <td style="border:none;border-top:1px dashed #bbb;padding-top:3px;padding-left:10px;font-size:9px;color:#666;">Received (KHR)</td>
                <td align="right" style="border:none;border-top:1px dashed #bbb;padding-top:3px;font-size:9px;color:#666;">KHR ' . number_format($tendered_khr) . '</td>
            </tr>
            <tr>
                <td style="border:none;padding-left:10px;font-size:9px;font-weight:700;">Change (KHR)</td>
                <td align="right" style="border:none;font-size:9px;font-weight:700;">KHR ' . number_format($change_khr) . '</td>
            </tr>
            <tr>
                <td style="border:none;padding-left:10px;font-size:9px;font-weight:700;">Change ($)</td>
                <td align="right" style="border:none;font-size:9px;font-weight:700;">$' . number_format($change_khr / KHR_RATE, 2) . '</td>
            </tr>';
                }
            } else {
                $amount_display = '$' . number_format($pay_amount, 2);
                // Keyed on a recorded tender rather than on the method. A pay-later
                // tab settled in cash at the counter deliberately keeps
                // payment_method='paylater' so its reporting bucket survives, and
                // requiring 'cash' here is why those receipts printed no change
                // lines. Bakong cannot misfire: its reference is never numeric.
                // tender_parts() accepts exactly two shapes where is_numeric()
                // would have taken a bare number written by anything. The riel
                // branch above still handles the 4 historical payment_method='riel'
                // rows; this branch now handles riel taken as cash.
                $tender_p     = tender_parts($ref);
                $tendered_usd = tender_usd_total($ref);
                // Change is measured against what the customer owed, not against
                // this row. A pay-later row is written when the tab opens and is
                // never updated as items are added — 18 orders carry a stale
                // amount — so on a single-row payment the order total is the
                // truth. A split's legs are per-method and genuinely correct.
                $owed_for_change = count($payments) === 1 ? $stored_total : $pay_amount;
                if ($tendered_usd > 0) {
                    $ch = tender_change($tendered_usd, $owed_for_change);
                    // Shared with the counter screen — see the note on the
                    // single-payment branch above.
                    $received_label = tender_received_text($tender_p, $tendered_usd);
                    $change_label   = tender_change_text($ch);
                    $change_rows = '
            <tr>
                <td style="border:none;border-top:1px dashed #bbb;padding-top:3px;padding-left:10px;font-size:9px;color:#666;">Received</td>
                <td align="right" style="border:none;border-top:1px dashed #bbb;padding-top:3px;font-size:9px;color:#666;">' . $received_label . '</td>
            </tr>
            <tr>
                <td style="border:none;padding-left:10px;font-size:9px;font-weight:700;">Change</td>
                <td align="right" style="border:none;font-size:9px;font-weight:700;">' . $change_label . '</td>
            </tr>';
                }
            }

            $html .= '
    <table width="100%" style="font-size:10px;border:none;border-collapse:collapse;margin-bottom:5px;">
        <tr>
            <td style="border:none;">' . $label . '</td>
            <td align="right" style="border:none;">' . $amount_display . '</td>
        </tr>' . $change_rows . '
    </table>';
        }
        $html .= '
</div>';
    }
}

// ── LOYALTY SECTION ──
$points_balance = 0;
$points_earned = 0;
$points_redeemed = 0;

$stmt = $conn->prepare("SELECT loyalty_card_id FROM orders WHERE order_id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order_loyalty = $stmt->get_result()->fetch_assoc();

if ($order_loyalty['loyalty_card_id']) {
    $stmt = $conn->prepare("SELECT points FROM loyalty_cards WHERE card_id = ?");
    $stmt->bind_param("i", $order_loyalty['loyalty_card_id']);
    $stmt->execute();
    $card = $stmt->get_result()->fetch_assoc();
    $points_balance = (int)($card['points'] ?? 0);
    
    // Get earned and redeemed points separately for this order
    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN points_change > 0 THEN points_change ELSE 0 END), 0) AS earned,
            COALESCE(ABS(SUM(CASE WHEN points_change < 0 THEN points_change ELSE 0 END)), 0) AS redeemed
        FROM loyalty_history
        WHERE order_id = ? AND card_id = ?
    ");
    if ($stmt) {
        $stmt->bind_param("ii", $order_id, $order_loyalty['loyalty_card_id']);
        $stmt->execute();
        $history = $stmt->get_result()->fetch_assoc();
        $points_earned   = (int)($history['earned']   ?? 0);
        $points_redeemed = (int)($history['redeemed'] ?? 0);
    }
}

if ($order_loyalty['loyalty_card_id']) {
    $html .= '
<div style="margin-top: 10px; padding-top: 8px; border-top: 1px dashed #000;">
    <div style="font-weight: 700; font-size: 10px; text-align: center; margin-bottom: 5px; letter-spacing: 1.5px; text-transform: uppercase;">Loyalty Points</div>
    <div style="display: flex; justify-content: space-between; font-size: 10px; padding: 2px 0;">
        <span>Points Earned</span>
        <span>+' . $points_earned . '</span>
    </div>';
    if ($points_redeemed > 0) {
        $html .= '
    <div style="display: flex; justify-content: space-between; font-size: 10px; padding: 2px 0;">
        <span>Points Redeemed</span>
        <span>-' . $points_redeemed . '</span>
    </div>';
    }
    $html .= '
    <div style="display: flex; justify-content: space-between; font-size: 11px; font-weight: 600; padding: 4px 0; border-top: 1px solid #000;">
        <span>Points Balance</span>
        <span>' . $points_balance . '</span>
    </div>
</div>';
}

$html .= '
<div class="footer">
    <p>Thank you for being here!</p>
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
$dompdf->stream("receipt_" . $order['daily_order_no'] . ".pdf", array("Attachment" => false));
?>