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
    die("Invalid order");
}

// ORDER
$stmt = $conn->prepare("
    SELECT order_id, customer_name, total, order_date, daily_order_no, status, employee_name,
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

// Backfill completed_at for paid orders that predate the column
if (empty($order['completed_at']) && in_array($order['status'], ['Paid', 'Completed'])) {
    $now = date('Y-m-d H:i:s');
    $conn->query("UPDATE orders SET completed_at = '$now' WHERE order_id = $order_id AND completed_at IS NULL");
    $order['completed_at'] = $now;
}

// ITEMS
$stmt = $conn->prepare("
    SELECT product_name, price, quantity, sweetness, ice, milk, size_label, addons_snapshot, promo_percent
    FROM order_items
    WHERE order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$items = $stmt->get_result();

// Calculate tax (10%) and subtotal
$subtotal = 0;
$tax = 0;
$discount = 0; // You can change this to actual discount if you have it

while ($item = $items->fetch_assoc()) {
    $subtotal += $item['price'] * $item['quantity'];
}
$tax = $subtotal * (TAX_RATE / 100);
$total = $subtotal + $tax - $discount;

// Reset items pointer for display
$stmt->execute();
$items = $stmt->get_result();

// Generate QR code URL (using qrserver.com)
$trackUrl = 'http://localhost/Cafe/track_order.php?order_id=' . $order_id;
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($trackUrl);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Receipt #<?php echo $order['daily_order_no']; ?></title>
<style>
body{
    margin:0;
    font-family:monospace;
    background:#fff;
}

/* 🔥 EXACT 80mm PRINT */
@media print {
    @page {
        width: 80mm;
        margin: 0;
    }

    body {
        width: 80mm;
        margin: 0;
    }
}

.receipt{
    width:80mm;
    padding:10px;
}

/* CENTER */
.center{
    text-align:center;
}

/* LOGO */
.logo{
    width:60px;
    height:60px;
    border-radius:50%;
    object-fit:cover;
    margin:0 auto 5px;
    display:block;
}

.shop{
    font-size:16px;
    font-weight:bold;
}

.small{
    font-size:11px;
}

/* LINE */
.line{
    border-top:1px dashed #000;
    margin:8px 0;
}

/* ITEM */
.item{
    margin-bottom:6px;
}

.row{
    display:flex;
    justify-content:space-between;
}

/* TOTAL */
.total{
    font-weight:bold;
    font-size:14px;
}

/* QR CODE */
.qr-container{
    display:flex;
    justify-content:center;
    margin:8px 0;
}

.qr-container img{
    width:80px;
    height:80px;
}

/* BARCODE */
.barcode-container{
    text-align:center;
    margin:4px 0;
}

.barcode{
    font-family:'Libre Barcode 39', 'Code39', monospace;
    font-size:32px;
    letter-spacing:2px;
}

/* TAX BREAKDOWN */
.tax-row{
    display:flex;
    justify-content:space-between;
    font-size:11px;
    color:#666;
}

.discount-row{
    display:flex;
    justify-content:space-between;
    font-size:11px;
    color:#c0392b;
}
</style>
<link href="https://fonts.googleapis.com/css2?family=Libre+Barcode+39&display=swap" rel="stylesheet">
</head>

<body onload="window.print()">

<div class="receipt">

    <div class="center">
        <img src="images/logo.png" class="logo">
        <div class="shop">Obsidian Cafe</div>
        <div class="small">Phnom Penh, Cambodia</div>
        <div class="small">Tel: +855 123 456 789</div>
    </div>

    <div class="line"></div>

    <div class="small">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
            <div>Receipt #: <strong><?= $order['daily_order_no'] ?></strong></div>
            <div style="font-weight:bold;font-size:12px;"><?= $order['order_type'] === 'drink_out' ? 'Drink Out' : 'Drink In' ?></div>
        </div>
        <div>Cashier: <?= htmlspecialchars($order['employee_name'] ?: 'N/A') ?></div>
        <div>Customer: <?= htmlspecialchars($order['customer_name']) ?></div>
        <?php if (!empty($order['table_number'])): ?>
        <div>Stand: <strong><?= htmlspecialchars($order['table_number']) ?></strong></div>
        <?php endif; ?>
        <div>Time In: <?= date("d/m/Y g:i A", strtotime(!empty($order['started_at']) ? $order['started_at'] : $order['order_date'])) ?></div>
        <?php if (!empty($order['completed_at'])): ?>
        <div>Time Out: <?= date("d/m/Y g:i A", strtotime($order['completed_at'])) ?></div>
        <?php endif; ?>
    </div>

    <!-- BARCODE -->
    <div class="barcode-container">
        <div class="barcode"><?= $order['daily_order_no'] ?></div>
    </div>

    <div class="line"></div>

    <?php while($item = $items->fetch_assoc()): ?>
        <div class="item">
            <div><strong><?= htmlspecialchars($item['product_name']) ?></strong><?php if ((int)($item['promo_percent'] ?? 0) > 0): ?> <span style="color:#c0392b;font-weight:700;font-size:11px;">(<?= (int)$item['promo_percent'] ?>% OFF)</span><?php endif; ?></div>

            <?php if (!empty($item['size_label'])): ?>
            <div class="small">Size: <?= htmlspecialchars($item['size_label']) ?></div>
            <?php endif; ?>

            <?php $__ad = json_decode($item['addons_snapshot'] ?? '[]', true) ?: []; ?>
            <?php foreach ($__ad as $__a): ?>
            <div class="small">+ <?= htmlspecialchars($__a['name']) ?> +$<?= number_format((float)$__a['price'], 2) ?></div>
            <?php endforeach; ?>

            <div class="small">
                Sweet: <?= $item['sweetness'] ?> |
                Ice: <?= $item['ice'] ?> |
                Milk: <?= $item['milk'] ?>
            </div>

            <div class="row">
                <div><?= $item['quantity'] ?> x $<?= number_format($item['price'],2) ?></div>
                <div>$<?= number_format($item['price'] * $item['quantity'],2) ?></div>
            </div>
        </div>
    <?php endwhile; ?>

    <div class="line"></div>

    <!-- TAX BREAKDOWN -->
    <div class="tax-row">
        <span>Subtotal</span>
        <span>$<?= number_format($subtotal, 2) ?></span>
    </div>

    <div class="tax-row">
        <span>Tax (<?= TAX_RATE ?>%)</span>
        <span>$<?= number_format($tax, 2) ?></span>
    </div>

    <?php if ($discount > 0): ?>
    <div class="discount-row">
        <span>Discount</span>
        <span>-$<?= number_format($discount, 2) ?></span>
    </div>
    <?php endif; ?>

    <div class="row total">
        <div>TOTAL</div>
        <div>$<?= number_format($total, 2) ?></div>
    </div>

    <?php
    // Loyalty card summary on receipt
    $loy_card_stmt = $conn->prepare("SELECT c.card_number, c.points, c.points_progress FROM orders o JOIN loyalty_cards c ON c.card_id = o.loyalty_card_id WHERE o.order_id = ?");
    if ($loy_card_stmt) {
        $loy_card_stmt->bind_param('i', $order_id);
        $loy_card_stmt->execute();
        $loy_row = $loy_card_stmt->get_result()->fetch_assoc();
        if ($loy_row) {
            $l_mode = LOYALTY_MODE;
            $l_req  = LOYALTY_POINTS_DRINKS;
            $l_prog = ($l_mode === 'spend') ? '$' . $loy_row['points_progress'] . '/$' . $l_req : $loy_row['points_progress'] . '/' . $l_req;
            ?>
            <div class="line"></div>
            <div class="small center" style="font-weight:bold;">
                ★ Loyalty Card: <?= htmlspecialchars($loy_row['card_number']) ?><br>
                Balance: <?= (int)$loy_row['points'] ?> pts (Progress: <?= $l_prog ?>)
            </div>
            <?php
        }
    }
    ?>

    <div class="line"></div>

    <!-- QR CODE -->
    <div class="qr-container">
        <img src="<?= $qrUrl ?>" alt="Track Order QR">
    </div>

    <div class="center small">
        Scan to track your order<br>
        Thank you for your order!<br>
        Please come again ☕
    </div>

</div>

</body>
</html>