<?php
require 'auth.php';
require_once 'config.php';

$_SESSION['cart'] = [];

$order_id = (int)($_GET['order_id'] ?? 0);
if ($order_id <= 0) die('Invalid order.');

// ── Fetch order ──
$stmt = $conn->prepare("
    SELECT order_id, customer_name, total, status, daily_order_no,
           promotion_discount, manual_discount, order_type, payment_method,
           completed_at, order_date, employee_name, points_earned, table_number
    FROM orders WHERE order_id = ? LIMIT 1
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
if (!$order) die('Order not found.');

// ── Fetch items ──
$stmt = $conn->prepare("
    SELECT product_name, quantity, price, size_label, sweetness, ice, milk, addons_snapshot
    FROM order_items WHERE order_id = ? ORDER BY item_id
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Fetch payments ──
$stmt = $conn->prepare("
    SELECT payment_method, amount, reference, payment_status
    FROM order_payments WHERE order_id = ? AND amount > 0
    ORDER BY FIELD(payment_method,'cash','bakong','riel','paylater')
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Derived values ──
// Pay-later settles start from the Pay Later queue — its success screen shows only
// Print Receipt + a Back-to-Pay-Later button (New Order / View All Orders are hidden,
// since the cashier is working the queue). Regular cash orders keep all three actions.
$is_paylater = ($order['payment_method'] ?? '') === 'paylater';

/* Where the cashier came from decides where "back" goes. Settling a Pending Payment
   order from Find Orders and then being dropped at the menu loses the queue they were
   working; only a fresh menu checkout should offer New Order.
     from=pending    → collected from Find Orders → Pending Payment (cash or Bakong)
     from=all        → collected from Find Orders → All Active
     from=dashboard  → collected via the dashboard shortcut
     (absent)        → a fresh order taken at the menu
   No 'paylater' entry: a settled pay-later order keeps payment_method='paylater',
   so the $is_paylater branch below wins before this map is read. */
$from = $_GET['from'] ?? '';
$back_targets = [
    'pending'   => ['find_order.php?tab=pending', 'Back to Pending Payment', 'fa-arrow-left'],
    'all'       => ['find_order.php?tab=all',     'Back to Active Orders',   'fa-arrow-left'],
    'dashboard' => ['dashboard.php',              'Back to Dashboard',       'fa-arrow-left'],
];
$back_from = $back_targets[$from] ?? null;
$total       = (float)$order['total'];
$discount    = (float)$order['promotion_discount'];
$manual_disc = (float)$order['manual_discount'];
$khr_total   = (int)(round($total * KHR_RATE / 100) * 100);
$paid_at     = $order['completed_at'] ?: $order['order_date'];
$paid_time   = $paid_at ? date('g:i A', strtotime($paid_at)) : '';
$paid_date   = $paid_at ? date('M j, Y', strtotime($paid_at)) : '';
$is_drink_out = $order['order_type'] === 'drink_out';
$points      = (int)$order['points_earned'];
$cashier     = trim($order['employee_name'] ?? '');

// Payment method meta
function payMeta(string $m): array {
    return match($m) {
        'cash'     => ['Cash', 'fa-money-bill-wave', '#27ae60'],
        'bakong'   => ['Bakong (KHQR)', 'fa-qrcode', '#d1904b'],
        'riel'     => ['Riel (KHR)', 'fa-coins', '#f39c12'],
        'paylater' => ['Pay Later', 'fa-clock', '#3498db'],
        default    => [ucfirst($m), 'fa-credit-card', '#888'],
    };
}

// Determine page title from methods
$method_names = array_column($payments, 'payment_method');
$is_bakong    = in_array('bakong', $method_names);
$is_cash      = in_array('cash',   $method_names);
$is_split     = $is_bakong && $is_cash;
$page_title   = $is_split ? 'Split Payment' : ($is_bakong ? 'Bakong Payment' : 'Cash Payment');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script>(function(){if(localStorage.getItem('theme')==='dark')document.documentElement.setAttribute('data-theme','dark');})()</script>
<title><?= htmlspecialchars($page_title) ?> | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --bg-body:   #f0ebe4;
    --bg-card:   #fffdf9;
    --border:    #e4d9cc;
    --border-h:  #c9b89f;
    --orange:    #d1904b;
    --orange-d:  #a0702a;
    --orange-l:  #e8b87a;
    --green:     #2ecc71;
    --green-l:   #55e087;
    --text-1:    #1a1410;
    --text-2:    #5a4a3a;
    --text-3:    #9a8070;
    --white:     #ffffff;
    --shadow-sm: 0 2px 12px rgba(90,60,20,0.08);
    --shadow-md: 0 8px 32px rgba(90,60,20,0.13);
    --shadow-lg: 0 16px 56px rgba(90,60,20,0.18);
    --tr: all 0.28s cubic-bezier(0.4,0,0.2,1);
    --r: 18px;
}
* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    background: var(--bg-body);
    font-family: 'Poppins', sans-serif;
    min-height: 100vh;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 24px 16px 40px;
    background-image:
        radial-gradient(ellipse at 15% 5%,  rgba(46,204,113,0.07) 0%, transparent 55%),
        radial-gradient(ellipse at 85% 95%, rgba(209,144,75,0.06) 0%, transparent 55%);
}

/* ── Canvas ── */
#confettiCanvas {
    position: fixed; inset: 0;
    pointer-events: none; z-index: 9999;
    width: 100%; height: 100%;
}

/* ── Card ── */
.card {
    background: var(--bg-card);
    border-radius: var(--r);
    width: 100%; max-width: 460px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-md);
    overflow: hidden;
    animation: cardIn 0.65s cubic-bezier(0.22,1,0.36,1) both;
}
@keyframes cardIn {
    from { opacity:0; transform: translateY(40px) scale(0.96); }
    to   { opacity:1; transform: none; }
}

/* ── Top accent bar ── */
.card-accent {
    height: 4px;
    background: linear-gradient(90deg, var(--green), var(--orange-l), var(--green-l), var(--orange));
    background-size: 300% 100%;
    animation: accentFlow 4s ease infinite;
}
@keyframes accentFlow {
    0%,100% { background-position: 0% 0%; }
    50%      { background-position: 100% 0%; }
}

.card-body { padding: 28px 28px 24px; }

/* ── Success Hero ── */
.success-hero {
    text-align: center;
    padding-bottom: 20px;
    border-bottom: 1px dashed var(--border);
    margin-bottom: 20px;
}

.check-wrap {
    position: relative;
    width: 88px; height: 88px;
    margin: 0 auto 14px;
}
.ring {
    position: absolute; inset: 0;
    border-radius: 50%;
    border: 2px solid rgba(46,204,113,0.35);
    animation: ringOut 2.4s ease-out infinite;
}
.ring:nth-child(2) { animation-delay: 0.7s; }
.ring:nth-child(3) { animation-delay: 1.4s; }
@keyframes ringOut {
    0%   { transform: scale(1);   opacity: 0.7; }
    100% { transform: scale(2.2); opacity: 0; }
}
.check-circle {
    position: absolute; inset: 0;
    background: rgba(46,204,113,0.1);
    border-radius: 50%;
    border: 2.5px solid var(--green);
    display: flex; align-items: center; justify-content: center;
    animation: checkPop 0.55s cubic-bezier(0.36,0.07,0.19,0.97) 0.2s both;
}
@keyframes checkPop {
    0%  { transform: scale(0); opacity:0; }
    60% { transform: scale(1.18); }
    80% { transform: scale(0.93); }
    100%{ transform: scale(1); opacity:1; }
}
.check-circle i { font-size: 34px; color: var(--green); }

.success-hero h2 {
    font-size: 22px; font-weight: 800;
    color: var(--text-1); letter-spacing: -0.4px;
    margin-bottom: 4px;
}
.success-hero h2 span {
    background: linear-gradient(135deg, var(--green), var(--orange-l));
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    background-clip: text;
}
.thankyou {
    font-size: 14px; color: var(--text-2);
    font-weight: 500;
}
.thankyou strong { color: var(--orange); }

/* ── Meta bar ── */
.meta-bar {
    display: flex; align-items: center; flex-wrap: wrap;
    gap: 8px; margin-bottom: 18px;
    font-size: 12px; color: var(--text-3);
}
.meta-bar .order-no {
    font-size: 15px; font-weight: 700; color: var(--orange);
    margin-right: 4px;
}
.badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px; border-radius: 20px;
    font-size: 11px; font-weight: 600;
    border: 1px solid;
}
.badge-in  { background: rgba(52,152,219,0.08); color:#2980b9; border-color:rgba(52,152,219,0.2); }
.badge-out { background: rgba(230,126,34,0.1);  color:#d35400; border-color:rgba(230,126,34,0.2); }
.meta-dot { color: var(--border-h); }

/* ── Section label ── */
.sec-label {
    font-size: 10px; font-weight: 700; letter-spacing: 1px;
    text-transform: uppercase; color: var(--text-3);
    margin-bottom: 8px;
}

/* ── Items ── */
.items-wrap { position: relative; margin-bottom: 18px; }
.items-list {
    max-height: 224px;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: var(--border) transparent;
    padding-right: 2px;
}
.items-list::-webkit-scrollbar { width: 3px; }
.items-list::-webkit-scrollbar-track { background: transparent; }
.items-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }
.items-fade {
    position: absolute; bottom: 0; left: 0; right: 0; height: 36px;
    background: linear-gradient(to bottom, transparent, var(--card-bg));
    pointer-events: none;
    border-radius: 0 0 8px 8px;
    transition: opacity .2s;
}
.items-count {
    font-size: 10px; font-weight: 600; color: var(--accent);
    background: rgba(209,144,75,.12); border-radius: 8px;
    padding: 1px 6px; margin-left: 6px; vertical-align: middle;
}
.item-row {
    display: flex; align-items: flex-start;
    justify-content: space-between;
    padding: 7px 0;
    border-bottom: 1px solid var(--border);
    gap: 10px;
}
.item-row:last-child { border-bottom: none; }
.item-left { flex: 1; min-width: 0; }
.item-name {
    font-size: 13px; font-weight: 600; color: var(--text-1);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    display: block;
}
.item-opts {
    display: flex; flex-wrap: wrap; gap: 4px; margin-top: 3px;
}
.opt-pill {
    font-size: 10px; color: var(--text-3);
    background: rgba(154,128,112,0.1);
    border-radius: 10px; padding: 1px 7px;
}
.item-right {
    display: flex; align-items: center; gap: 8px;
    white-space: nowrap; flex-shrink: 0;
}
.item-qty {
    font-size: 12px; font-weight: 600; color: var(--text-3);
    background: rgba(209,144,75,0.1); border-radius: 6px;
    padding: 1px 7px;
}
.item-price {
    font-size: 13px; font-weight: 600; color: var(--text-1);
    min-width: 50px; text-align: right;
}

/* ── Totals ── */
.totals-block { margin-bottom: 18px; }
.total-row {
    display: flex; justify-content: space-between;
    align-items: center; padding: 4px 0;
    font-size: 13px; color: var(--text-2);
}
.total-row.discount { color: #e74c3c; }
.total-row.main {
    border-top: 1px solid var(--border);
    margin-top: 6px; padding-top: 10px;
    font-size: 17px; font-weight: 800; color: var(--text-1);
}
.total-row.main span:last-child { color: var(--orange); }
.total-row.khr { font-size: 12px; color: var(--text-3); padding-top: 2px; }

/* ── Payments ── */
.payments-block { margin-bottom: 4px; }
.pay-row {
    display: flex; align-items: center;
    padding: 8px 12px; border-radius: 10px;
    border: 1px solid var(--border);
    margin-bottom: 6px; gap: 10px;
    background: rgba(255,255,255,0.5);
    transition: var(--tr);
}
.pay-row:hover { border-color: var(--border-h); }
.pay-icon {
    width: 32px; height: 32px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; flex-shrink: 0;
}
.pay-label { flex: 1; font-size: 13px; font-weight: 600; color: var(--text-1); }
.pay-amount { font-size: 14px; font-weight: 700; color: var(--text-1); }

/* ── Change ── */
.change-block {
    background: rgba(46,204,113,0.05);
    border: 1px solid rgba(46,204,113,0.2);
    border-radius: 10px; padding: 10px 14px;
    margin-top: 6px; margin-bottom: 18px;
}
.chg-row {
    display: flex; justify-content: space-between;
    font-size: 13px; padding: 3px 0; color: var(--text-2);
}
.chg-row.received { color: var(--text-3); font-size: 12px; }
.chg-row.change-amt { font-weight: 700; color: var(--text-1); font-size: 15px; }
.chg-row.change-khr { color: var(--text-3); font-size: 12px; }

/* ── Loyalty ── */
.loyalty-badge {
    display: flex; align-items: center; gap: 8px;
    background: linear-gradient(135deg, rgba(209,144,75,0.1), rgba(232,184,122,0.08));
    border: 1px solid rgba(209,144,75,0.25);
    border-radius: 12px; padding: 10px 14px;
    margin-bottom: 18px;
    animation: fadeInUp 0.5s ease 0.8s both;
}
.loyalty-badge .star-icon {
    width: 34px; height: 34px; border-radius: 8px;
    background: rgba(209,144,75,0.15);
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; color: var(--orange); flex-shrink: 0;
}
.loyalty-badge .loy-text { flex:1; }
.loyalty-badge .loy-text strong {
    display: block; font-size: 13px; font-weight: 700; color: var(--orange);
}
.loyalty-badge .loy-text span {
    font-size: 11px; color: var(--text-3);
}

/* ── Divider ── */
.divider {
    border: none; border-top: 1px dashed var(--border);
    margin: 16px 0;
}

/* ── Footer meta ── */
.footer-meta {
    display: flex; justify-content: space-between; align-items: center;
    font-size: 11px; color: var(--text-3);
    margin-bottom: 18px;
}
.footer-meta i { margin-right: 3px; }

/* ── Actions ── */
.actions { display: flex; flex-direction: column; gap: 8px; }
.btn {
    padding: 11px 16px; border-radius: 12px; border: none;
    font-weight: 600; font-size: 13px; cursor: pointer;
    transition: var(--tr); display: flex; align-items: center;
    justify-content: center; gap: 8px; text-decoration: none;
    font-family: 'Poppins', sans-serif; text-align: center;
}
.btn:hover { transform: translateY(-2px); }
.btn-new {
    background: linear-gradient(135deg, var(--orange), var(--orange-d));
    color: #fff; box-shadow: 0 4px 16px rgba(209,144,75,0.3);
    font-size: 14px; padding: 13px 16px;
}
.btn-new:hover { box-shadow: 0 6px 24px rgba(209,144,75,0.45); filter: brightness(1.05); }
.btn-print {
    background: #c0392b; color: #fff;
}
.btn-print:hover { box-shadow: 0 4px 16px rgba(192,57,43,0.35); filter: brightness(1.1); }
.btn-sec {
    background: transparent; color: var(--text-2);
    border: 1px solid var(--border);
}
.btn-sec:hover { border-color: var(--orange); color: var(--text-1); }

/* ── Animations ── */
@keyframes fadeInUp {
    from { opacity:0; transform: translateY(12px); }
    to   { opacity:1; transform: none; }
}
.anim-1 { animation: fadeInUp 0.5s ease 0.1s both; }
.anim-2 { animation: fadeInUp 0.5s ease 0.2s both; }
.anim-3 { animation: fadeInUp 0.5s ease 0.3s both; }
.anim-4 { animation: fadeInUp 0.5s ease 0.4s both; }
.anim-5 { animation: fadeInUp 0.5s ease 0.5s both; }
.anim-6 { animation: fadeInUp 0.5s ease 0.6s both; }

/* ── Dark mode ── */
[data-theme="dark"] {
    --bg-body: #0a0a0a; --bg-card: #141414;
    --border: #222; --border-h: #333;
    --text-1: #f0f0f0; --text-2: #aaa; --text-3: #666;
    --shadow-md: 0 8px 32px rgba(0,0,0,0.6);
    --shadow-lg: 0 16px 56px rgba(0,0,0,0.7);
}
[data-theme="dark"] body { background-image: none; }
[data-theme="dark"] .pay-row { background: rgba(255,255,255,0.02); }

/* ── Responsive ── */
@media (max-width: 500px) {
    .card-body { padding: 20px 18px 18px; }
    .check-wrap { width: 72px; height: 72px; }
    .check-circle i { font-size: 28px; }
    .success-hero h2 { font-size: 19px; }
}
</style>
</head>
<body>

<canvas id="confettiCanvas"></canvas>

<div class="card">
    <div class="card-accent"></div>
    <div class="card-body">

        <!-- ── Success Hero ── -->
        <div class="success-hero">
            <div class="check-wrap">
                <div class="ring"></div>
                <div class="ring"></div>
                <div class="ring"></div>
                <div class="check-circle"><i class="fa-solid fa-check"></i></div>
            </div>
            <h2><span>Payment Successful!</span></h2>
            <p class="thankyou">Thank you, <strong><?= htmlspecialchars($order['customer_name']) ?></strong></p>
        </div>

        <!-- ── Meta bar ── -->
        <div class="meta-bar anim-1">
            <span class="order-no">#<?= (int)$order['daily_order_no'] ?></span>
            <span class="meta-dot">·</span>
            <span class="badge <?= $is_drink_out ? 'badge-out' : 'badge-in' ?>">
                <i class="fa-solid <?= $is_drink_out ? 'fa-bag-shopping' : 'fa-mug-hot' ?>"></i>
                <?= $is_drink_out ? 'Drink Out' : 'Drink In' ?>
            </span>
            <?php if ($paid_time): ?>
                <span class="meta-dot">·</span>
                <span><i class="fa-regular fa-clock" style="margin-right:3px;"></i><?= htmlspecialchars($paid_time) ?></span>
            <?php endif; ?>
            <?php if ($cashier): ?>
                <span class="meta-dot">·</span>
                <span><?= htmlspecialchars($cashier) ?></span>
            <?php endif; ?>
            <?php if (!empty($order['table_number'])): ?>
                <span class="meta-dot">·</span>
                <span style="background:rgba(209,144,75,.15);border:1px solid rgba(209,144,75,.25);color:#d1904b;padding:2px 8px;border-radius:6px;font-size:12px;font-weight:600;">
                    <i class="fa-solid fa-hashtag" style="font-size:10px;"></i><?= htmlspecialchars($order['table_number']) ?>
                </span>
            <?php endif; ?>
        </div>

        <!-- ── Items ── -->
        <div class="items-wrap anim-2">
            <div class="sec-label">
                Order Summary
                <?php if (count($items) > 0): ?>
                <span class="items-count"><?= count($items) ?> item<?= count($items) > 1 ? 's' : '' ?></span>
                <?php endif; ?>
            </div>
            <div class="items-list" id="itemsList">
            <?php foreach ($items as $item):
                $opts = [];
                if (!empty($item['size_label'])) $opts[] = $item['size_label'];
                foreach (json_decode($item['addons_snapshot'] ?? '[]', true) ?: [] as $__a) { if (!empty($__a['name'])) $opts[] = $__a['name']; }
                if (!empty($item['sweetness']) && strtolower($item['sweetness']) !== 'normal') $opts[] = $item['sweetness'];
                if (!empty($item['ice'])       && strtolower($item['ice'])       !== 'normal') $opts[] = $item['ice'];
                if (!empty($item['milk'])      && strtolower($item['milk'])      !== 'none' && $item['milk'] !== '') $opts[] = $item['milk'];
                $line_total = (float)$item['price'] * (int)$item['quantity'];
            ?>
            <div class="item-row">
                <div class="item-left">
                    <span class="item-name"><?= htmlspecialchars($item['product_name']) ?></span>
                    <?php if ($opts): ?>
                    <div class="item-opts">
                        <?php foreach ($opts as $o): ?>
                        <span class="opt-pill"><?= htmlspecialchars($o) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="item-right">
                    <span class="item-qty">×<?= (int)$item['quantity'] ?></span>
                    <span class="item-price">$<?= number_format($line_total, 2) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            </div><!-- /items-list -->
            <div class="items-fade" id="itemsFade"></div>
        </div><!-- /items-wrap -->

        <hr class="divider">

        <!-- ── Totals ── -->
        <div class="totals-block anim-3">
            <?php if ($discount > 0): ?>
            <div class="total-row discount">
                <span><i class="fa-solid fa-tag" style="margin-right:5px;"></i>Promotion Discount</span>
                <span>-$<?= number_format($discount, 2) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($manual_disc > 0): ?>
            <div class="total-row discount">
                <span><i class="fa-solid fa-scissors" style="margin-right:5px;"></i>Manual Discount</span>
                <span>-$<?= number_format($manual_disc, 2) ?></span>
            </div>
            <?php endif; ?>
            <div class="total-row main">
                <span>Total</span>
                <span>$<?= number_format($total, 2) ?></span>
            </div>
            <div class="total-row khr">
                <span>KHR Equivalent</span>
                <span>KHR <?= number_format($khr_total) ?></span>
            </div>
        </div>

        <hr class="divider">

        <!-- ── Payment Methods ── -->
        <div class="payments-block anim-4">
            <div class="sec-label">Payment</div>
            <?php foreach ($payments as $pay):
                [$label, $icon, $color] = payMeta($pay['payment_method']);
            ?>
            <div class="pay-row">
                <div class="pay-icon" style="background:<?= $color ?>22; color:<?= $color ?>;">
                    <i class="fa-solid <?= $icon ?>"></i>
                </div>
                <span class="pay-label"><?= htmlspecialchars($label) ?></span>
                <span class="pay-amount">$<?= number_format((float)$pay['amount'], 2) ?></span>
            </div>

            <?php
            // ── Change block, shown whenever a tender was recorded ──
            // Gated on the tender rather than on the method: a settled pay-later tab
            // deliberately keeps payment_method='paylater' so the reporting bucket
            // survives, and would otherwise never show its change. Bakong cannot
            // misfire here — its reference is never numeric.
            if (is_numeric($pay['reference']) && (float)$pay['reference'] > 0):
                $received   = (float)$pay['reference'];
                // Change is what the customer is owed back, so it is measured
                // against what they owed. On a single-row payment that is the
                // order total: a pay-later row is written when the tab opens and
                // is NOT updated as items are added, so 18 orders here carry a
                // stale amount (order 1908: row says $1.34, order totals $19.78).
                // Using the row would have printed $18.66 change on a $0.22
                // reality. A split's legs are per-method and genuinely correct.
                $owed_for_change = count($payments) === 1 ? $total : (float)$pay['amount'];
                $change_usd = round(max(0, $received - $owed_for_change), 2);
                $change_khr = (int)(round($change_usd * KHR_RATE / 100) * 100);
            ?>
            <div class="change-block">
                <div class="chg-row received">
                    <span><i class="fa-solid fa-hand-holding-dollar"></i> Received</span>
                    <span>$<?= number_format($received, 2) ?></span>
                </div>
                <div class="chg-row change-amt">
                    <span>Change</span>
                    <span>$<?= number_format($change_usd, 2) ?></span>
                </div>
                <?php if ($change_khr > 0): ?>
                <div class="chg-row change-khr">
                    <span>Change (KHR)</span>
                    <span>KHR <?= number_format($change_khr) ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php endforeach; ?>
        </div>

        <?php if ($points > 0): ?>
        <!-- ── Loyalty ── -->
        <div class="loyalty-badge">
            <div class="star-icon"><i class="fa-solid fa-star"></i></div>
            <div class="loy-text">
                <strong>+<?= $points ?> points earned!</strong>
                <span>Loyalty rewards keep growing</span>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Footer date ── -->
        <?php if ($paid_date): ?>
        <div class="footer-meta anim-5">
            <span><i class="fa-regular fa-calendar"></i><?= htmlspecialchars($paid_date) ?></span>
            <span>Order #<?= (int)$order['daily_order_no'] ?></span>
        </div>
        <?php endif; ?>

        <!-- ── Actions ── -->
        <div class="actions anim-6">
            <a href="receipt_pdf.php?order_id=<?= $order_id ?>" target="_blank" class="btn btn-print">
                <i class="fa-solid fa-file-pdf"></i> Print Receipt
            </a>
            <?php if ($is_paylater): ?>
            <a href="find_order.php?tab=paylater" class="btn btn-new">
                <i class="fa-solid fa-arrow-left"></i> Back to Pay Later
            </a>
            <?php elseif ($back_from): ?>
            <a href="<?= htmlspecialchars($back_from[0]) ?>" class="btn btn-new">
                <i class="fa-solid <?= $back_from[2] ?>"></i> <?= htmlspecialchars($back_from[1]) ?>
            </a>
            <?php else: ?>
            <a href="menu.php" class="btn btn-new">
                <i class="fa-solid fa-plus"></i> New Order
            </a>
            <a href="view_order.php" class="btn btn-sec">
                <i class="fa-solid fa-list"></i> View All Orders
            </a>
            <?php endif; ?>
        </div>

    </div><!-- /.card-body -->
</div><!-- /.card -->

<script>
// ── Confetti burst ──
(function() {
    const canvas = document.getElementById('confettiCanvas');
    const ctx = canvas.getContext('2d');
    canvas.width  = window.innerWidth;
    canvas.height = window.innerHeight;

    const colors = ['#d1904b','#55e087','#e8b87a','#f1c40f','#2ecc71','#e74c3c','#3498db'];
    const pieces = Array.from({length: 110}, () => ({
        x:     Math.random() * canvas.width,
        y:     -20 - Math.random() * 120,
        w:     Math.random() * 11 + 5,
        h:     Math.random() * 5  + 3,
        color: colors[Math.floor(Math.random() * colors.length)],
        vx:    (Math.random() - 0.5) * 3.5,
        vy:    Math.random() * 3 + 2.5,
        angle: Math.random() * 360,
        spin:  (Math.random() - 0.5) * 9,
        alpha: 1
    }));

    let frame = 0;
    function draw() {
        frame++;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        let alive = false;
        pieces.forEach(p => {
            if (p.alpha <= 0) return;
            alive = true;
            p.x    += p.vx;
            p.y    += p.vy;
            p.vy   += 0.07;
            p.angle += p.spin;
            if (p.y > canvas.height * 0.65) p.alpha -= 0.022;
            ctx.save();
            ctx.globalAlpha = Math.max(0, p.alpha);
            ctx.translate(p.x, p.y);
            ctx.rotate(p.angle * Math.PI / 180);
            ctx.fillStyle = p.color;
            ctx.fillRect(-p.w/2, -p.h/2, p.w, p.h);
            ctx.restore();
        });
        if (alive && frame < 320) requestAnimationFrame(draw);
        else canvas.style.display = 'none';
    }

    setTimeout(draw, 350);
    window.addEventListener('resize', () => {
        canvas.width  = window.innerWidth;
        canvas.height = window.innerHeight;
    });
})();

// ── Items scroll fade ──
(function() {
    const list = document.getElementById('itemsList');
    const fade = document.getElementById('itemsFade');
    if (!list || !fade) return;
    function updateFade() {
        const atBottom = list.scrollHeight - list.scrollTop <= list.clientHeight + 4;
        fade.style.opacity = atBottom ? '0' : '1';
    }
    updateFade();
    list.addEventListener('scroll', updateFade);
})();
</script>

</body>
</html>
