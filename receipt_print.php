<?php
require 'config.php';
date_default_timezone_set('Asia/Phnom_Penh');

// Ensure columns exist (safe no-op after first run)
if ($conn->query("SHOW COLUMNS FROM orders LIKE 'order_type'")->num_rows === 0)
    $conn->query("ALTER TABLE orders ADD COLUMN order_type ENUM('drink_in','drink_out') NOT NULL DEFAULT 'drink_in'");
if ($conn->query("SHOW COLUMNS FROM orders LIKE 'completed_at'")->num_rows === 0)
    $conn->query("ALTER TABLE orders ADD COLUMN completed_at DATETIME NULL");

$order_id = (int)($_GET['order_id'] ?? 0);

if ($order_id <= 0) {
    die("Invalid order ID");
}

// ── FETCH ORDER ──
$stmt = $conn->prepare("
    SELECT order_id, customer_name, total, order_date, daily_order_no, status, employee_name,
           IFNULL(order_type,'drink_in') AS order_type, completed_at, table_number, started_at,
           IFNULL(promotion_discount, 0) AS promotion_discount, IFNULL(manual_discount, 0) AS manual_discount
    FROM orders
    WHERE order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    die("Order not found");
}

// Backfill completed_at for paid orders that predate the column
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
$items_res = $stmt->get_result();

$items = [];
$subtotal = 0;
while ($row = $items_res->fetch_assoc()) {
    $items[] = $row;
    $subtotal += $row['price'] * $row['quantity'];
}

$discount = (float)($order['promotion_discount'] ?? 0) + (float)($order['manual_discount'] ?? 0);
$total = (float)$order['total'] > 0 ? (float)$order['total'] : max(0, $subtotal - $discount);
$discount_percent = ($subtotal > 0 && $discount > 0) ? round(($discount / $subtotal) * 100) : 0;

$khr_rate = defined('KHR_RATE') ? KHR_RATE : 4000;
$total_khr = (int)(round($total * $khr_rate / 100) * 100);

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
                $ch = function_exists('tender_change') ? tender_change($tendered_usd, $total, $is_riel) : max(0, $tendered_usd - $total);
                $change_usd = is_array($ch) ? (float)($ch['usd'] ?? 0) : (float)$ch;
                $change_khr = is_array($ch) ? (int)($ch['khr'] ?? 0) : (int)(round($change_usd * $khr_rate / 100) * 100);
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

$change_total_usd = max(0, $tendered_usd - $total);
$mixed_usd = floor($change_total_usd / 5) * 5;
$mixed_khr = (int)round(($change_total_usd - $mixed_usd) * $khr_rate / 100) * 100;

if ($change_total_usd > 0) {
    if ($mixed_usd > 0 && $mixed_khr > 0) {
        $change_disp = 'USD ' . number_format($mixed_usd, 2) . ' + KHR ' . number_format($mixed_khr);
    } elseif ($mixed_usd > 0) {
        $change_disp = 'USD ' . number_format($mixed_usd, 2);
    } else {
        $change_disp = 'KHR ' . number_format($mixed_khr);
    }
} else {
    $change_disp = 'USD 0.00';
}

$wifi_pass = defined('WIFI_PASSWORD') ? WIFI_PASSWORD : '';
$order_time = date("d-m-Y h:i A", strtotime(!empty($order['started_at']) ? $order['started_at'] : $order['order_date']));
$invoice_no = str_pad($order['daily_order_no'], 8, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="km">
<head>
<meta charset="UTF-8">
<title>វិក្កយបត្រ #<?= htmlspecialchars($order['daily_order_no']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,400;0,600;0,700;1,400&family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<style>
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}
body {
    font-family: 'Kantumruy Pro', 'Poppins', sans-serif;
    font-size: 11px;
    color: #000;
    background: #fff;
    line-height: 1.35;
}

@media print {
    @page {
        width: 80mm;
        margin: 0;
    }
    body {
        width: 80mm;
        margin: 0;
    }
    .no-print {
        display: none !important;
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
    font-size: 10.5px;
    border-collapse: collapse;
}
.meta-table td {
    padding: 1px 0;
    vertical-align: top;
}
.meta-table td.right {
    text-align: right;
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

<div class="no-print" style="display:flex; justify-content:center; gap:10px; padding:10px 12px; background:#f4f4f5; border-bottom:1px solid #e4e4e7; margin-bottom:10px;">
    <button onclick="window.print()" style="padding:7px 16px; background:#18181b; color:#fff; border:none; border-radius:8px; font-weight:600; font-size:12px; cursor:pointer; font-family:'Poppins',sans-serif; display:flex; align-items:center; gap:6px;">
        🖨️ Print Receipt
    </button>
    <a href="menu.php" style="padding:7px 16px; background:#d1904b; color:#fff; border:none; border-radius:8px; font-weight:600; font-size:12px; cursor:pointer; font-family:'Poppins',sans-serif; display:flex; align-items:center; gap:6px; text-decoration:none;">
        ⬅️ Back to POS
    </a>
</div>

<div class="receipt">
    <!-- Header -->
    <div class="text-center">
        <div class="shop-name">The Bird Nest Cafe</div>
        <div class="shop-sub">Phnom Penh</div>
        <div class="receipt-title">វិក្កយបត្រ</div>
    </div>

    <!-- Metadata Section -->
    <table class="meta-table">
        <tr>
            <td style="width: 55%;">អ្នកគិតលុយ : <?= htmlspecialchars($order['employee_name'] ?: 'admin') ?></td>
            <td class="right" style="width: 45%;">លេខវិក្កយបត្រ : <?= htmlspecialchars($invoice_no) ?></td>
        </tr>
        <tr>
            <td>អតិថិជន : <?= htmlspecialchars($order['customer_name'] ?: 'General Customer') ?></td>
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
                <th style="width: 7%;">ល.រ</th>
                <th style="width: 43%;">បរិយាយ</th>
                <th style="width: 12%;">ចំនួន</th>
                <th style="width: 12%;">តម្លៃ</th>
                <th style="width: 13%;">បញ្ចុះតម្លៃ</th>
                <th style="width: 13%;">សរុប</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $idx = 1;
            foreach ($items as $item):
                $lineTotal = $item['price'] * $item['quantity'];
                $promoPct  = (int)($item['promo_percent'] ?? 0);
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
                <td class="col-price"><?= number_format($item['price'], 2) ?></td>
                <td class="col-disc"><?= $promoPct > 0 ? $promoPct.'%' : '0%' ?></td>
                <td class="col-total"><?= number_format($lineTotal, 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Totals & Payment Summary -->
    <div class="totals-area">
        <div class="totals-row">
            <span>ប្រាក់សរុប :</span>
            <span>USD <?= number_format($subtotal, 2) ?></span>
        </div>
        <div class="totals-row">
            <span>បញ្ចុះតម្លៃ (<?= (int)$discount_percent ?>%) :</span>
            <span>USD <?= number_format($discount, 2) ?></span>
        </div>
        <div class="totals-row bold">
            <span>ប្រាក់សរុបចុងក្រោយ :</span>
            <span>USD <?= number_format($total, 2) ?></span>
        </div>
        <div class="totals-sub-khr">
            KHR <?= number_format($total_khr) ?>
        </div>

        <div style="height: 4px;"></div>

        <div class="totals-row">
            <span>ប្រាក់ទទួល :</span>
            <span><?= htmlspecialchars(function_exists('tender_received_text') ? tender_received_text($tender_parts, $tendered_usd) : ('USD ' . number_format($tendered_usd, 2))) ?></span>
        </div>
        <?php if ($is_cash_order): ?>
        <div class="totals-row bold">
            <span>ប្រាក់អាប់ :</span>
            <span><?= htmlspecialchars($change_disp) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <div class="dotted-divider"></div>
    <div class="footer-wifi">
        Password WiFi: <?= htmlspecialchars($wifi_pass) ?>
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

    if (document.readyState === 'complete') {
        setTimeout(triggerPrint, 250);
    } else {
        window.addEventListener('load', function() {
            setTimeout(triggerPrint, 250);
        });
    }
})();
</script>
<?php endif; ?>
</body>
</html>