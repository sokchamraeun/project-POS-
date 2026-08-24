<?php
require 'config.php';
date_default_timezone_set('Asia/Phnom_Penh');

$order_id = (int)($_GET['order_id'] ?? 0);

if ($order_id <= 0) {
    die("Invalid order ID");
}

// ── FETCH ORDER ──
$stmt = $conn->prepare("
    SELECT o.order_id, 'Guest' AS customer_name, o.total, o.order_date, o.order_id AS daily_order_no, 'Completed' AS status,
           COALESCE(NULLIF(u.name, ''), u.username, o.prepared_by, 'Staff') AS employee_name,
           u.user_id AS employee_id,
           COALESCE(NULLIF(u.name, ''), u.username, o.prepared_by, 'Staff') AS emp_real_name,
           'drink_in' AS order_type, '' AS completed_at, '' AS table_number, o.started_at,
           0 AS promotion_discount, 0 AS manual_discount
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

$c_name = $order['employee_name'] ?: 'admin';
$e_real = trim($order['emp_real_name'] ?? '');
if (!empty($e_real) && strtolower($e_real) !== strtolower($c_name)) {
    $cashier_display = $c_name . '(' . $e_real . ')';
} else {
    $cashier_display = $c_name;
}

// ── FETCH ITEMS ──
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

// Merge identical items into a single line with combined total quantity for a clean receipt
$items = [];
foreach ($raw_items as $ri) {
    $sig = ($ri['product_name'] ?? '').'|'.($ri['size_label'] ?? '').'|'.($ri['sweetness'] ?? '').'|'.($ri['ice'] ?? '').'|'.($ri['milk'] ?? '').'|'.($ri['addons_snapshot'] ?? '').'|'.($ri['price'] ?? 0).'|'.((int)($ri['promo_percent'] ?? 0));
    if (isset($items[$sig])) {
        $items[$sig]['quantity'] += (float)($ri['quantity'] ?? 1);
    } else {
        $items[$sig] = $ri;
    }
}
$items = array_values($items);

$discount = (float)($order['promotion_discount'] ?? 0) + (float)($order['manual_discount'] ?? 0);
$total = (float)$order['total'] > 0 ? (float)$order['total'] : max(0, $subtotal - $discount);
$discount_percent = ($subtotal > 0 && $discount > 0) ? round(($discount / $subtotal) * 100) : 0;

$khr_rate = defined('KHR_RATE') ? KHR_RATE : 4000;
$total_khr = (int)(round($total * $khr_rate / 100) * 100);
if ($total > 0 && $total_khr === 0) {
    $total_khr = (int)round($total * $khr_rate);
}

// ── FETCH PAYMENT DETAILS ──
$pay_stmt = $conn->prepare("
    SELECT payment_method, amount, reference
    FROM order_payments
    WHERE order_id = ? AND amount > 0
    ORDER BY FIELD(payment_method, 'cash', 'bakong', 'paylater', 'riel')
");
$pay_stmt->bind_param("i", $order_id);
$pay_stmt->execute();
$payments = $pay_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$pay_method_label = 'Cash-$';
$tendered_usd = 0;
$change_usd = 0;
$change_khr = 0;
$is_cash_order = false;
$is_riel = false;

$tender_parts = null;
if (!empty($payments)) {
    $methods = [];
    foreach ($payments as $p) {
        $pm = strtolower($p['payment_method']);
        if ($pm === 'cash' || $pm === 'riel') $is_cash_order = true;
        if ($pm === 'cash') $methods[] = 'Cash-$';
        elseif ($pm === 'bakong') $methods[] = 'KHQR';
        elseif ($pm === 'riel') $methods[] = 'Cash-KHR';
        else $methods[] = ucfirst($pm);

        if ($pm === 'cash' || $pm === 'riel') {
            $ref_val = (string)($p['reference'] ?? '');
            $t_parts = function_exists('tender_parts') ? tender_parts($ref_val) : null;
            if ($t_parts) $tender_parts = $t_parts;
            $t_usd   = function_exists('tender_usd_total') ? tender_usd_total($ref_val) : (is_numeric($ref_val) ? (float)$ref_val : 0);
            if ($t_usd > 0) {
                $tendered_usd = $t_usd;
                $is_riel = function_exists('tender_is_riel_only') ? tender_is_riel_only($t_parts) : false;
                $ch = function_exists('tender_change') ? tender_change($tendered_usd, $total, $is_riel) : ['usd' => 0, 'khr' => 0, 'short' => false];
                $change_usd = (int)($ch['usd'] ?? 0);
                $change_khr = (int)($ch['khr'] ?? 0);
            }
        }
    }
    $pay_method_label = implode(', ', array_unique($methods));
}

if ($tendered_usd <= 0) {
    $tendered_usd = $total;
    $change_usd = 0;
    $change_khr = 0;
}

// Received display
if ($tender_parts !== null && (int)($tender_parts['khr'] ?? 0) > 0) {
    if ((float)($tender_parts['usd'] ?? 0) > 0) {
        $received_disp = 'USD ' . number_format((float)$tender_parts['usd'], 2) . ' + KHR ' . number_format((int)$tender_parts['khr']);
    } else {
        $received_disp = 'KHR ' . number_format((int)$tender_parts['khr']);
    }
} else {
    $received_disp = 'USD ' . number_format($tendered_usd, 2);
}

// Change display
if ($change_usd > 0 && $change_khr > 0) {
    $change_disp_main = 'USD ' . number_format($change_usd, 2) . ' + KHR ' . number_format($change_khr);
    $change_disp_sub  = '';
} elseif ($change_usd > 0) {
    $change_disp_main = 'USD ' . number_format($change_usd, 2);
    $change_disp_sub  = '';
} elseif ($change_khr > 0) {
    $change_disp_main = 'KHR ' . number_format($change_khr);
    $change_disp_sub  = '';
} else {
    $change_disp_main = $is_riel ? 'KHR 0' : 'USD 0.00';
    $change_disp_sub  = '';
}

$wifi_pass = defined('WIFI_PASSWORD') ? WIFI_PASSWORD : '';
$order_time = date("d-m-Y h:i A", strtotime(!empty($order['started_at']) ? $order['started_at'] : $order['order_date']));
$invoice_no = str_pad($order['daily_order_no'], 4, '0', STR_PAD_LEFT);

$is_58 = (isset($_GET['size']) && (string)$_GET['size'] === '58');
$paper_width_mm = $is_58 ? 58 : 80;
$approx_height_mm = max(130, 115 + (count($items) * 10) + ($discount > 0 ? 10 : 0) + ($is_cash_order ? 12 : 0));
?>
<!DOCTYPE html>
<html lang="km">
<head>
<meta charset="UTF-8">
<title>វិក្កយបត្រ #<?= htmlspecialchars($order['daily_order_no']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,400;0,600;0,700;1,400&family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}
body {
    font-family: 'Kantumruy Pro', 'Poppins', sans-serif;
    font-size: <?= $is_58 ? '9.5px' : '11px' ?>;
    color: #000;
    background: #fff;
    line-height: 1.35;
}

@page {
    size: <?= $paper_width_mm ?>mm <?= $approx_height_mm ?>mm;
    margin: 0mm;
}

@media print {
    html, body {
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        box-shadow: none !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .receipt {
        width: 100% !important;
        max-width: 100% !important;
        padding: <?= $is_58 ? '2mm 1mm' : '3mm 2mm' ?> !important;
        margin: 0 auto !important;
        border: none !important;
        box-shadow: none !important;
    }
    .no-print {
        display: none !important;
    }
}

@media screen {
    body {
        background: #e4e4e7;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 24px 12px;
    }
    .receipt {
        width: <?= $is_58 ? '240px' : '320px' ?>;
        background: #ffffff;
        padding: 20px 16px;
        border-radius: 12px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        border: 1px solid #d4d4d8;
    }
    .no-print {
        width: <?= $is_58 ? '240px' : '320px' ?>;
        margin-bottom: 12px;
    }
    .screen-toolbar {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .toolbar-row {
        display: flex;
        justify-content: space-between;
        gap: 8px;
    }
    .btn-tool-print {
        flex: 1;
        background: #18181b;
        color: #fff;
        border: none;
        padding: 9px 14px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 12.5px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        font-family: inherit;
        transition: background 0.15s;
    }
    .btn-tool-print:hover {
        background: #27272a;
    }
    .btn-tool-close {
        background: #e4e4e7;
        color: #3f3f46;
        border: none;
        padding: 9px 14px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 12.5px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        font-family: inherit;
        transition: background 0.15s;
    }
    .btn-tool-close:hover {
        background: #d4d4d8;
    }
    .size-toggle-group {
        display: flex;
        align-items: center;
        justify-content: center;
        background: #d4d4d8;
        border-radius: 8px;
        padding: 3px;
        gap: 4px;
    }
    .size-btn {
        flex: 1;
        padding: 4px 8px;
        font-size: 11.5px;
        font-weight: 600;
        text-align: center;
        color: #52525b;
        text-decoration: none;
        border-radius: 6px;
        transition: all 0.15s;
    }
    .size-btn.active {
        background: #ffffff;
        color: #18181b;
        box-shadow: 0 1px 4px rgba(0,0,0,0.1);
    }
}

.receipt {
    width: 80mm;
    padding: 10px 12px;
    margin: 0 auto;
}

.text-center { text-align: center; }
.text-left { text-align: left; }
.text-right { text-align: right; }
.bold { font-weight: 700; }

/* ── HEADER ── */
.shop-name {
    font-size: 17px;
    font-weight: 700;
    line-height: 1.2;
}
.shop-sub {
    font-size: 11px;
    margin-top: 2px;
}
.receipt-title {
    font-size: 16px;
    font-weight: 700;
    margin: 10px 0 8px;
    letter-spacing: 0.5px;
}

/* ── METADATA GRID ── */
.meta-table {
    width: 100%;
    margin-bottom: 8px;
    font-size: 10px;
    border-collapse: collapse;
}
.meta-table td {
    padding: 1.5px 0;
    vertical-align: top;
}
.meta-table td.right {
    text-align: right;
    white-space: nowrap;
}

/* ── ITEM TABLE ── */
.items-table {
    width: 100%;
    border-collapse: collapse;
    margin: 6px 0 10px;
}
.items-table th, .items-table td {
    border: 1px solid #000;
    padding: 4px 3px;
    font-size: 10px;
    vertical-align: middle;
}
.items-table th {
    font-weight: 700;
    text-align: center;
    background: #fff;
}
.items-table td.col-no { text-align: center; }
.items-table td.col-qty { text-align: center; }
.items-table td.col-price { text-align: center; }
.items-table td.col-disc { text-align: center; }
.items-table td.col-total { text-align: center; }

.item-desc {
    text-align: left;
}
.item-title {
    font-weight: 700;
    color: #000;
}
.item-sub {
    font-size: 9px;
    color: #333;
    margin-top: 1px;
}

/* ── TOTALS AREA ── */
.totals-area {
    width: 100%;
    margin-top: 6px;
    font-size: 11px;
}
.totals-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 2px 0;
}
.totals-row.bold span {
    font-weight: 700;
    font-size: 12px;
}
.totals-sub-khr {
    text-align: right;
    font-weight: 700;
    font-size: 11px;
    padding-bottom: 4px;
}

.dotted-divider {
    border-top: 1px dotted #000;
    margin: 10px 0 6px;
}

.footer-wifi {
    font-size: 11px;
    text-align: center;
}
</style>
</head>
<body>

<div class="no-print screen-toolbar">
    <div class="toolbar-row">
        <button type="button" onclick="window.print()" class="btn-tool-print"><i class="fa-solid fa-print"></i> Print Receipt</button>
        <button type="button" onclick="window.close()" class="btn-tool-close"><i class="fa-solid fa-xmark"></i> Close</button>
    </div>
    <div class="size-toggle-group">
        <a href="receipt_print.php?order_id=<?= $order_id ?>&size=80<?= isset($_GET['no_auto']) ? '&no_auto=1' : '' ?>" class="size-btn <?= !$is_58 ? 'active' : '' ?>">80mm Thermal (Standard)</a>
        <a href="receipt_print.php?order_id=<?= $order_id ?>&size=58<?= isset($_GET['no_auto']) ? '&no_auto=1' : '' ?>" class="size-btn <?= $is_58 ? 'active' : '' ?>">58mm Thermal (Mini)</a>
    </div>
</div>

<div class="receipt">
    <!-- Header -->
    <div class="text-center">
        <div class="shop-name"><?= htmlspecialchars(defined('RECEIPT_SHOP_NAME') ? RECEIPT_SHOP_NAME : 'The Bird Nest Cafe') ?></div>
        <div class="shop-sub"><?= htmlspecialchars(defined('RECEIPT_LOCATION') ? RECEIPT_LOCATION : 'Phnom Penh') ?></div>
        <?php if (defined('RECEIPT_PHONE') && RECEIPT_PHONE !== ''): ?>
        <div class="shop-sub"><?= htmlspecialchars(RECEIPT_PHONE) ?></div>
        <?php endif; ?>
        <div class="receipt-title">វិក្កយបត្រ</div>
    </div>

    <!-- Metadata Section -->
    <table class="meta-table">
        <tr>
            <td style="width: 48%;">អ្នកគិតលុយ : <?= htmlspecialchars($cashier_display) ?></td>
            <td class="right" style="width: 52%;">លេខវិក្កយបត្រ : <?= htmlspecialchars($invoice_no) ?></td>
        </tr>
        <tr>
            <td>អតិថិជន : <?= htmlspecialchars($order['customer_name'] ?: 'Guest') ?></td>
            <td class="right">ម៉ោងចេញ : <?= htmlspecialchars($order_time) ?></td>
        </tr>
        <tr>
            <td>បង់តាម : <?= htmlspecialchars($pay_method_label) ?></td>
            <td class="right">អត្រាប្តូរប្រាក់ : 1$ = <?= number_format($khr_rate) ?> ៛</td>
        </tr>
    </table>

    <!-- Items Grid Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 8%;">ល.រ</th>
                <th style="width: 48%;">បរិយាយ</th>
                <th style="width: 14%;">ចំនួន</th>
                <th style="width: 15%;">តម្លៃ</th>
                <th style="width: 15%;">សរុប</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $idx = 1;
            foreach ($items as $item):
                $unitPrice = (float)($item['price'] ?? 0);
                $origPrice = (float)($item['orig_price'] ?? 0);
                $promoPct  = (int)($item['promo_percent'] ?? 0);
                $displayUnitPrice = ($origPrice > $unitPrice) ? $origPrice : $unitPrice;
                if ($promoPct > 0 && $displayUnitPrice == $unitPrice && $promoPct < 100) {
                    $displayUnitPrice = $unitPrice / (1 - $promoPct / 100);
                }
                $lineTotal = $unitPrice * $item['quantity'];
            ?>
            <tr>
                <td class="col-no"><?= $idx++ ?></td>
                <td class="item-desc">
                    <div class="item-title"><?= htmlspecialchars($item['product_name']) ?></div>

                    <?php
                    $__ad = json_decode($item['addons_snapshot'] ?? '[]', true) ?: [];
                    foreach ($__ad as $__a):
                    ?>
                        <div class="item-sub">+ <?= htmlspecialchars($__a['name']) ?> ($<?= number_format((float)$__a['price'], 2) ?>)</div>
                    <?php endforeach; ?>
                    <?php
                    $opts = array_filter([
                        !empty($item['sweetness']) ? 'sugar : '.$item['sweetness'] : '',
                        !empty($item['ice'])       ? 'ice : '.strtolower($item['ice']) : '',
                        !empty($item['milk'])      ? 'milk : '.strtolower($item['milk']) : '',
                    ]);
                    if (!empty($opts)):
                        foreach ($opts as $opt):
                    ?>
                        <div class="item-sub"><?= htmlspecialchars($opt) ?></div>
                    <?php 
                        endforeach;
                    endif; 
                    ?>
                </td>
                <td class="col-qty"><?= number_format($item['quantity'], 1) ?></td>
                <td class="col-price"><?= number_format($displayUnitPrice, 2) ?></td>
                <td class="col-total"><?= number_format($lineTotal, 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Totals & Payment Summary -->
    <div class="totals-area">
        <?php if ($discount > 0): ?>
        <div class="totals-row">
            <span>ប្រាក់សរុប :</span>
            <span>USD <?= number_format($subtotal, 2) ?></span>
        </div>
        <div class="totals-row">
            <span>បញ្ចុះតម្លៃ (<?= (int)$discount_percent ?>%) :</span>
            <span>USD <?= number_format($discount, 2) ?></span>
        </div>
        <?php endif; ?>
        <div class="totals-row bold">
            <span>ប្រាក់សរុបចុងក្រោយ :</span>
            <span>USD <?= number_format($total, 2) ?></span>
        </div>
        <div class="totals-sub-khr">
            KHR <?= number_format($total_khr) ?>
        </div>

        <?php if ($is_cash_order && $tender_parts !== null): ?>
        <div class="totals-row">
            <span>ប្រាក់ទទួល :</span>
            <span><?= $received_disp ?></span>
        </div>
        <div class="totals-row bold">
            <span>ប្រាក់អាប់ :</span>
            <span><?= $change_disp_main ?></span>
        </div>
        <?php if (!empty($change_disp_sub)): ?>
        <div class="totals-sub-khr">
            <?= $change_disp_sub ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Footer / Thank You -->
    <div class="dotted-divider"></div>
    <div style="text-align: center; margin-top: 6px; font-weight: 700; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">
        <?= htmlspecialchars(defined('RECEIPT_FOOTER_MSG') ? RECEIPT_FOOTER_MSG : 'Thank You!') ?>
    </div>
</div>

<?php if (!isset($_GET['no_auto']) || $_GET['no_auto'] != '1'): ?>
<script>
(function() {
    var hasPrinted = false;
    function triggerPrint() {
        if (hasPrinted) return;
        hasPrinted = true;
        try {
            window.focus();
            window.print();
        } catch(e) {
            console.error("Auto print error:", e);
        }
    }

    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        setTimeout(triggerPrint, 50);
    }
    document.addEventListener('DOMContentLoaded', triggerPrint);
    window.addEventListener('load', triggerPrint);
    setTimeout(triggerPrint, 300);
})();
</script>
<?php endif; ?>
</body>
</html>