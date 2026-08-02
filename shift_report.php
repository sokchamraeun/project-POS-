<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$uid        = (int)$_SESSION['user_id'];
$uname      = $_SESSION['username'] ?? 'Staff';
$role       = $_SESSION['role']     ?? 'staff';
$login_time = $_SESSION['login_time'] ?? strtotime('today');
$login_dt   = date('Y-m-d H:i:s', $login_time);
$login_fmt  = date('g:i A', $login_time);
$today_fmt  = date('F j, Y');

// ── Orders taken this shift by this user ──
$stmt = $conn->prepare("
    SELECT order_id, daily_order_no, total, status, order_date, customer_name, order_type, table_number
    FROM orders
    WHERE user_id = ?
      AND business_date = CURDATE()
      AND order_date >= ?
    ORDER BY order_date ASC
");
$stmt->bind_param("is", $uid, $login_dt);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Stats ──
$total_revenue   = 0.0;
$cancelled_count = 0;
$paylater_count  = 0;

foreach ($orders as $o) {
    $s = $o['status'];
    if ($s !== 'Cancelled' && $s !== 'Refunded') {
        $total_revenue += (float)$o['total'];
    }
    if ($s === 'Cancelled') $cancelled_count++;
}

$total_orders  = count($orders);
$billed_orders = $total_orders - $cancelled_count;
$avg_value     = $billed_orders > 0 ? $total_revenue / $billed_orders : 0;

// ── Payment breakdown ──
$pay_breakdown = [];
$pay_total     = 0.0;
$order_ids     = array_column($orders, 'order_id');
if ($order_ids) {
    $ph    = implode(',', array_fill(0, count($order_ids), '?'));
    $types = str_repeat('i', count($order_ids));
    $sp    = $conn->prepare("
        SELECT payment_method, SUM(amount) AS total
        FROM order_payments
        WHERE order_id IN ($ph)
        GROUP BY payment_method
        ORDER BY total DESC
    ");
    $sp->bind_param($types, ...$order_ids);
    $sp->execute();
    $res = $sp->get_result();
    while ($r = $res->fetch_assoc()) {
        $pay_breakdown[$r['payment_method']] = (float)$r['total'];
        $pay_total += (float)$r['total'];
    }
}

// The cashier counts the drawer in DOLLARS, and that count is compared against
// this figure, saved to cash_counts and alerted on by dashboard.php. Riel taken
// on a cash sale is booked to the cash bucket for revenue but never entered the
// dollar drawer, so leaving it in would accuse the cashier of a shortage equal
// to every riel sale of the shift. Subtract what the riel side actually kept.
// No max(0.0, ...) clamp here on purpose: a riel-heavy shift can legitimately
// send this negative (e.g. one $1.34 sale tendered ៛20,000 — change pays out
// $3 in notes, so the dollar drawer is down $3 even though the sale itself was
// tiny). Clamping to $0.00 would hide that the drawer really is $3 light,
// which is exactly the phantom-shortage bug this function exists to prevent.
// cash_counts.expected_cash is DECIMAL(10,2), not UNSIGNED, so it stores fine.
$expected_cash = $pay_breakdown['cash'] ?? 0.0;
$riel_share    = tender_riel_share($conn, $order_ids);
$expected_cash = round($expected_cash - $riel_share, 2);

// ── Drinks sold by type this shift ──
$drinks = [];
$total_drinks = 0;
if ($order_ids) {
    $ph2   = implode(',', array_fill(0, count($order_ids), '?'));
    $types2 = str_repeat('i', count($order_ids));
    $sd = $conn->prepare("
        SELECT product_name, SUM(quantity) AS qty
        FROM order_items
        WHERE order_id IN ($ph2)
        GROUP BY product_name
        ORDER BY qty DESC
    ");
    $sd->bind_param($types2, ...$order_ids);
    $sd->execute();
    $dres = $sd->get_result();
    while ($r = $dres->fetch_assoc()) $drinks[] = $r;
    $total_drinks = array_sum(array_column($drinks, 'qty'));
}

// ── Inventory-clerk shift summary (stock activity instead of orders) ──
$is_clerk      = ($role === 'inventory_clerk');
$stock_actions = [];
$restock_count = 0; $adjust_count = 0; $items_touched = 0; $sc_status = 'none';
if ($is_clerk) {
    $sa = $conn->prepare("
        SELECT ih.change_type, ih.amount, ih.created_at, i.ingredient_name
        FROM ingredient_history ih
        JOIN ingredients i ON i.ingredient_id = ih.ingredient_id
        WHERE ih.created_by = ? AND DATE(ih.created_at) = CURDATE()
          AND ih.change_type IN ('quick_restock','po_received','manual_adjust')
        ORDER BY ih.created_at ASC
    ");
    $sa->bind_param("s", $uname);
    $sa->execute();
    $stock_actions = $sa->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($stock_actions as $a) {
        if ($a['change_type'] === 'manual_adjust') $adjust_count++; else $restock_count++;
    }
    $items_touched = count(array_unique(array_column($stock_actions, 'ingredient_name')));
    $scq    = $conn->query("SELECT status FROM stock_counts WHERE business_date = CURDATE() LIMIT 1");
    $scrow  = $scq ? $scq->fetch_assoc() : null;
    $sc_status = $scrow['status'] ?? 'none';
}

// ── AJAX: save cash reconciliation ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actual_cash'])) {
    header('Content-Type: application/json');
    if (in_array($role, ['admin', 'manager', 'supervisor', 'inventory_clerk', 'barista'])) {
        echo json_encode(['ok' => false, 'msg' => 'Not applicable for this role']);
        exit;
    }
    $actual = (float)$_POST['actual_cash'];
    $s = $conn->prepare("INSERT INTO cash_counts (user_id, username, shift_date, login_time, expected_cash, actual_cash) VALUES (?,?,CURDATE(),?,?,?)");
    $s->bind_param("issdd", $uid, $uname, $login_dt, $expected_cash, $actual);
    $s->execute();
    echo json_encode(['ok' => true, 'diff' => round($actual - $expected_cash, 2)]);
    exit;
}

$method_labels = [
    'cash'     => ['Cash',       'fa-money-bill-wave',  '#22c55e'],
    'bakong'   => ['Bakong QR',  'fa-qrcode',           '#3b82f6'],
    'paylater' => ['Pay Later',  'fa-clock',            '#f59e0b'],
    'riel'     => ['Riel (KHR)', 'fa-coins',            '#a78bfa'],
    'card'     => ['Card',       'fa-credit-card',      '#06b6d4'],
];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Shift Report — <?= h($today_fmt) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}

@keyframes fadeInUp  { from{opacity:0;transform:translateY(22px)} to{opacity:1;transform:translateY(0)} }
@keyframes scaleIn   { from{opacity:0;transform:scale(.9)}         to{opacity:1;transform:scale(1)}     }
@keyframes fadeIn    { from{opacity:0}                             to{opacity:1}                        }
@keyframes pulseRed  { 0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.35)} 50%{box-shadow:0 0 0 10px rgba(239,68,68,0)} }

body {
  font-family:'Poppins',sans-serif;
  background: radial-gradient(ellipse 70% 40% at 50% 0%, rgba(209,144,75,.09) 0%, transparent 100%), #0a0a0a;
  color:#e8e8e8;min-height:100vh;
  display:flex;flex-direction:column;align-items:center;
  padding:36px 16px 60px;
  transition:opacity .5s ease;
}
body.fading{opacity:0;pointer-events:none;}
a{text-decoration:none;}

/* ── Header ── */
.page-header{width:100%;max-width:680px;margin-bottom:28px;text-align:center;animation:fadeInUp .55s ease both;}
.page-header .date{font-size:13px;color:#666;margin-bottom:5px;letter-spacing:.6px;}
.page-header h1{font-size:24px;font-weight:700;color:#f0f0f0;margin-bottom:7px;}
.page-header .sub{font-size:13px;color:#888;}
.page-header .sub strong{color:#d1904b;}

/* ── Stat grid ── */
.stat-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;width:100%;max-width:680px;margin-bottom:20px;}
@media(min-width:500px){.stat-grid{grid-template-columns:repeat(4,1fr);}}
.stat-card{
  background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);
  border-radius:16px;padding:18px 14px;text-align:center;
  animation:scaleIn .45s ease both;
  transition:border-color .2s,transform .2s;
}
.stat-card:hover{border-color:rgba(255,255,255,.15);transform:translateY(-2px);}
.stat-card:nth-child(1){animation-delay:.10s}
.stat-card:nth-child(2){animation-delay:.18s}
.stat-card:nth-child(3){animation-delay:.26s}
.stat-card:nth-child(4){animation-delay:.34s}
.stat-card .val{font-size:24px;font-weight:700;margin-bottom:3px;}
.stat-card .lbl{font-size:11px;color:#666;font-weight:400;letter-spacing:.3px;}
.stat-card.revenue .val{color:#22c55e;}
.stat-card.orders  .val{color:#60a5fa;}
.stat-card.avg     .val{color:#d1904b;}
.stat-card.cancels .val{color:#f87171;}

/* ── Sections ── */
.section{
  width:100%;max-width:680px;
  background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);
  border-radius:16px;padding:20px;margin-bottom:16px;
  animation:fadeInUp .5s ease both;
}
.section.s1{animation-delay:.40s}
.section.s2{animation-delay:.48s}
.section.s3{animation-delay:.56s}
.section-title{font-size:11px;font-weight:600;color:#555;letter-spacing:.9px;text-transform:uppercase;margin-bottom:16px;display:flex;align-items:center;gap:7px;}

/* ── Payment bars ── */
.pay-row{display:flex;align-items:center;gap:10px;margin-bottom:12px;}
.pay-row:last-child{margin-bottom:0;}
.pay-icon{width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;}
.pay-info{flex:1;min-width:0;}
.pay-name{font-size:12px;font-weight:500;color:#ccc;margin-bottom:5px;}
.pay-bar-wrap{background:rgba(255,255,255,.06);border-radius:6px;height:5px;overflow:hidden;}
.pay-bar{height:100%;border-radius:6px;width:0;transition:width .85s cubic-bezier(.4,0,.2,1);}
.pay-amount{font-size:13px;font-weight:600;color:#e8e8e8;text-align:right;min-width:64px;flex-shrink:0;}
.pay-pct{font-size:10px;color:#555;text-align:right;min-width:36px;flex-shrink:0;}

/* ── Order list ── */
.order-rows{display:flex;flex-direction:column;gap:6px;max-height:240px;overflow-y:auto;}
.order-rows::-webkit-scrollbar{width:3px;}
.order-rows::-webkit-scrollbar-thumb{background:rgba(255,255,255,.08);border-radius:3px;}
.order-row{
  display:flex;align-items:center;gap:10px;padding:9px 12px;
  border-radius:10px;background:rgba(255,255,255,.025);
  border:1px solid rgba(255,255,255,.05);font-size:12px;
  transition:background .15s;
}
.order-row:hover{background:rgba(255,255,255,.05);}
.order-row .no{font-weight:700;color:#d1904b;min-width:36px;}
.order-row .customer{flex:1;color:#ccc;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.order-row .stand{color:#555;font-size:11px;}
.order-row .amount{font-weight:600;color:#e8e8e8;min-width:54px;text-align:right;}
.order-row .status-pill{font-size:10px;font-weight:600;padding:2px 9px;border-radius:20px;flex-shrink:0;}
.s-Paid,.s-Completed   {background:rgba(34,197,94,.12);color:#4ade80;}
.s-Preparing,.s-Processing{background:rgba(241,196,15,.12);color:#f1c40f;}
.s-Pending             {background:rgba(100,116,139,.12);color:#94a3b8;}
.s-PendingPayment      {background:rgba(59,130,246,.12);color:#60a5fa;}
.s-Cancelled           {background:rgba(248,113,113,.12);color:#f87171;}
.s-Refunded            {background:rgba(155,89,182,.12);color:#c084fc;}
.empty-msg{color:#444;text-align:center;padding:20px;font-size:13px;}

/* ── Drinks by type ── */
.drinks-grid{display:flex;flex-direction:column;gap:7px;max-height:280px;overflow-y:auto;}
.drinks-grid::-webkit-scrollbar{width:3px;}
.drinks-grid::-webkit-scrollbar-thumb{background:rgba(255,255,255,.08);border-radius:3px;}
.drink-row{
  display:flex;align-items:center;justify-content:space-between;
  padding:8px 12px;border-radius:10px;
  background:rgba(255,255,255,.025);border:1px solid rgba(255,255,255,.05);
  font-size:13px;
}
.drink-name{color:#ccc;font-weight:500;}
.drink-qty{
  background:rgba(209,144,75,.15);color:#d1904b;
  font-size:12px;font-weight:700;
  padding:2px 10px;border-radius:20px;
  border:1px solid rgba(209,144,75,.2);
}
.drinks-total{
  display:flex;justify-content:space-between;align-items:center;
  margin-top:10px;padding-top:10px;border-top:1px solid rgba(255,255,255,.06);
  font-size:12px;color:#666;
}
.drinks-total span{font-weight:700;color:#d1904b;}

/* ── Cash reconciliation ── */
.cash-section{
  width:100%;max-width:680px;
  background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);
  border-radius:16px;padding:20px;margin-bottom:16px;
  animation:fadeInUp .5s ease .58s both;
}
.cash-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;}
.cash-label{font-size:13px;color:#888;}
.cash-val{font-size:15px;font-weight:700;color:#e8e8e8;}
.cash-note{font-size:11.5px;line-height:1.5;color:#8a8a8a;margin:-6px 0 14px;}
.cash-input-wrap{display:flex;gap:10px;align-items:center;}
.cash-input{
  flex:1;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);
  border-radius:10px;padding:11px 14px;
  color:#f0f0f0;font-family:inherit;font-size:15px;font-weight:600;
  outline:none;transition:border-color .2s;
}
.cash-input:focus{border-color:rgba(209,144,75,.5);}
.cash-submit{
  padding:11px 20px;border-radius:10px;border:none;
  background:linear-gradient(135deg,#d1904b,#b87333);
  color:#fff;font-family:inherit;font-size:14px;font-weight:600;
  cursor:pointer;transition:all .2s;white-space:nowrap;
}
.cash-submit:hover{opacity:.9;transform:translateY(-1px);}
.cash-result{
  margin-top:14px;padding:12px 16px;border-radius:10px;
  font-size:13px;font-weight:600;text-align:center;
  display:none;
}
.cash-result.match{background:rgba(34,197,94,.12);color:#4ade80;border:1px solid rgba(34,197,94,.2);}
.cash-result.over  {background:rgba(59,130,246,.12);color:#60a5fa;border:1px solid rgba(59,130,246,.2);}
.cash-result.short {background:rgba(239,68,68,.12); color:#f87171;border:1px solid rgba(239,68,68,.2);}
.cash-result.warn  {background:rgba(245,158,11,.1); color:#f59e0b;border:1px solid rgba(245,158,11,.25);}
.cash-result.discrepancy{background:rgba(239,68,68,.1);color:#f87171;border:1px solid rgba(239,68,68,.2);}
.cash-recount{
  display:none;width:100%;margin-top:10px;
  padding:10px;border-radius:10px;border:1px solid rgba(255,255,255,.1);
  background:rgba(255,255,255,.05);color:#ccc;
  font-family:inherit;font-size:13px;font-weight:600;
  cursor:pointer;transition:all .2s;
}
.cash-recount:hover{background:rgba(255,255,255,.09);color:#fff;}

/* ── Farewell (staff only) ── */
.farewell{
  width:100%;max-width:680px;text-align:center;
  padding:10px 0 4px;
  display:none;
}
.farewell.visible{display:block;animation:fadeIn .5s ease both;}
.farewell .bye{font-size:15px;font-weight:600;color:#d1904b;margin-bottom:4px;}
.farewell .sub{font-size:12px;color:#444;}

/* ── Actions (admin/manager only) ── */
.actions{width:100%;max-width:680px;display:flex;gap:10px;animation:fadeInUp .5s ease .62s both;}
.btn{
  display:inline-flex;align-items:center;gap:8px;
  padding:13px 22px;border-radius:13px;
  font-family:inherit;font-size:14px;font-weight:600;
  cursor:pointer;border:none;transition:all .2s;
}
.btn-logout{background:linear-gradient(135deg,#ef4444,#c0392b);color:#fff;flex:1;justify-content:center;}
.btn-logout:hover{background:linear-gradient(135deg,#f87171,#ef4444);transform:translateY(-1px);}
.btn-ghost{background:rgba(255,255,255,.05);color:#777;border:1px solid rgba(255,255,255,.08);flex:0;}
.btn-ghost:hover{background:rgba(255,255,255,.09);color:#e8e8e8;}
</style>
</head>
<body>

<div class="page-header">
    <div class="date"><?= h($today_fmt) ?></div>
    <h1><i class="fa-solid fa-clipboard-check" style="color:#d1904b;margin-right:9px;"></i>End of Shift</h1>
    <div class="sub">
        Logged in as <strong><?= h($uname) ?></strong>
        &nbsp;·&nbsp;
        Shift started <strong><?= h($login_fmt) ?></strong>
    </div>
</div>

<!-- Stat cards -->
<div class="stat-grid">
<?php if ($is_clerk):
    $sc_txt = $sc_status === 'submitted' ? 'Done' : ($sc_status === 'draft' ? 'Open' : '—');
?>
    <div class="stat-card orders"><div class="val"><?= $restock_count ?></div><div class="lbl">Restocks</div></div>
    <div class="stat-card revenue"><div class="val"><?= $items_touched ?></div><div class="lbl">Items Updated</div></div>
    <div class="stat-card avg"><div class="val"><?= $adjust_count ?></div><div class="lbl">Adjustments</div></div>
    <div class="stat-card cancels"><div class="val" style="font-size:20px"><?= $sc_txt ?></div><div class="lbl">Stock Count</div></div>
<?php else: ?>
    <div class="stat-card orders"><div class="val"><?= $total_orders ?></div><div class="lbl">Total Orders</div></div>
    <div class="stat-card revenue"><div class="val">$<?= number_format($total_revenue,2) ?></div><div class="lbl">Revenue</div></div>
    <div class="stat-card avg"><div class="val">$<?= number_format($avg_value,2) ?></div><div class="lbl">Avg Order</div></div>
    <div class="stat-card cancels"><div class="val"><?= $cancelled_count ?></div><div class="lbl">Cancelled</div></div>
<?php endif; ?>
</div>

<?php if ($pay_breakdown): ?>
<div class="section s1">
    <div class="section-title"><i class="fa-solid fa-chart-bar"></i> Payment Breakdown</div>
    <?php foreach ($pay_breakdown as $method => $amount):
        $label = $method_labels[$method][0] ?? ucfirst($method);
        $icon  = $method_labels[$method][1] ?? 'fa-money-bill';
        $color = $method_labels[$method][2] ?? '#888';
        $pct   = $pay_total > 0 ? round($amount / $pay_total * 100) : 0;
    ?>
    <div class="pay-row">
        <div class="pay-icon" style="background:<?= $color ?>22;color:<?= $color ?>;"><i class="fa-solid <?= h($icon) ?>"></i></div>
        <div class="pay-info">
            <div class="pay-name"><?= h($label) ?></div>
            <div class="pay-bar-wrap"><div class="pay-bar" data-w="<?= $pct ?>" style="background:<?= $color ?>;"></div></div>
        </div>
        <div class="pay-amount">$<?= number_format($amount,2) ?></div>
        <div class="pay-pct"><?= $pct ?>%</div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($drinks): ?>
<div class="section s2" style="animation-delay:.48s">
    <div class="section-title"><i class="fa-solid fa-mug-hot"></i> Drinks Sold This Shift</div>
    <div class="drinks-grid">
        <?php foreach ($drinks as $d): ?>
        <div class="drink-row">
            <span class="drink-name"><?= h($d['product_name']) ?></span>
            <span class="drink-qty"><?= (int)$d['qty'] ?>×</span>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="drinks-total">
        <span style="color:#666">Total drinks made</span>
        <span><?= $total_drinks ?></span>
    </div>
</div>
<?php endif; ?>

<div class="section s3" style="animation-delay:.56s">
<?php if ($is_clerk): ?>
    <div class="section-title"><i class="fa-solid fa-boxes-stacked"></i> Stock Activity This Shift</div>
    <?php if ($stock_actions): ?>
    <div class="order-rows">
        <?php foreach (array_reverse($stock_actions) as $a):
            $tlabel = match($a['change_type']) { 'quick_restock'=>'Restock','po_received'=>'PO Received','manual_adjust'=>'Adjust', default=>$a['change_type'] };
            $amt = rtrim(rtrim(number_format((float)$a['amount'],2),'0'),'.');
        ?>
        <div class="order-row">
            <span class="no"><i class="fa-solid fa-box" style="font-size:9px"></i></span>
            <span class="customer"><?= h($a['ingredient_name']) ?></span>
            <span class="amount"><?= h($amt) ?></span>
            <span class="status-pill" style="background:rgba(209,144,75,.14);color:#d1904b"><?= h($tlabel) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-msg"><i class="fa-regular fa-face-meh"></i> No stock changes logged this shift yet.</div>
    <?php endif; ?>
<?php else: ?>
    <div class="section-title"><i class="fa-solid fa-list-ul"></i> Orders This Shift</div>
    <?php if ($orders): ?>
    <div class="order-rows">
        <?php foreach (array_reverse($orders) as $o):
            $status = $o['status'];
            $statusLabel = match($status) {
                'Paid'           => 'Paid',       'Completed'  => 'Done',
                'Preparing'      => 'Preparing',  'Processing' => 'Processing',
                'Pending'        => 'Pending',     'PendingPayment' => 'Pending Pay',
                'Cancelled'      => 'Cancelled',  'Refunded'   => 'Refunded',
                default          => $status,
            };
        ?>
        <div class="order-row">
            <span class="no">#<?= h($o['daily_order_no']) ?></span>
            <span class="customer"><?= h($o['customer_name'] ?: 'Guest') ?></span>
            <?php if ($o['table_number']): ?><span class="stand"><i class="fa-solid fa-ticket" style="font-size:9px"></i> <?= h($o['table_number']) ?></span><?php endif; ?>
            <span class="amount"><?= in_array($status,['Cancelled','Refunded']) ? '<span style="color:#444">—</span>' : '$'.number_format((float)$o['total'],2) ?></span>
            <span class="status-pill s-<?= h($status) ?>"><?= h($statusLabel) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-msg"><i class="fa-regular fa-face-meh"></i> No orders taken this shift yet.</div>
    <?php endif; ?>
<?php endif; ?>
</div>

<?php
$has_countdown  = !in_array($role, ['admin', 'manager', 'supervisor', 'inventory_clerk', 'barista']);
$show_cash_step = $has_countdown; // only cashier/staff run the till; barista (drinks) + inventory_clerk do not handle cash
?>

<?php if ($show_cash_step): ?>
<!-- Cash reconciliation (staff / cashier only) -->
<div class="cash-section">
    <div class="section-title"><i class="fa-solid fa-cash-register"></i> Count Your Drawer</div>
    <div class="cash-row">
        <span class="cash-label">Expected cash (from system)</span>
        <!-- $expected_cash can be negative on a riel-heavy shift (see the
             comment above where it's computed) — render the sign in front of
             the $ (-$3.00), not "$-3.00", to keep it readable in a slot the
             rest of the page treats as a plain amount. -->
        <span class="cash-val"><?= $expected_cash < 0 ? '&minus;$' . number_format(abs($expected_cash), 2) : '$' . number_format($expected_cash, 2) ?></span>
    </div>
    <?php if ($riel_share > 0): ?>
    <!-- Shown only when riel was taken, so the deduction is explainable rather
         than a figure that mysteriously disagrees with the payment breakdown. -->
    <div class="cash-note">
        Excludes $<?= number_format($riel_share, 2) ?> taken in riel &mdash; that money is in the riel drawer, not the dollar drawer.
    </div>
    <?php endif; ?>
    <?php if ($expected_cash < 0): ?>
    <!-- The figure above went negative: dollar notes physically left the
         drawer as change on a riel sale, so the dollar drawer is expected to
         come up short even though every sale this shift was legitimate. -->
    <div class="cash-note">
        Negative because dollar notes were paid out as change on riel sales &mdash; the dollar drawer is expected to be $<?= number_format(abs($expected_cash), 2) ?> lighter than it started, not because anything is missing.
    </div>
    <?php endif; ?>
    <div class="cash-input-wrap">
        <input type="number" class="cash-input" id="actualCash" placeholder="0.00" step="0.01" min="0">
        <button class="cash-submit" id="submitBtn" onclick="submitCash()">Submit</button>
    </div>
    <div class="cash-result" id="cashResult"></div>
    <button class="cash-recount" id="recountBtn" onclick="doRecount()">
        <i class="fa-solid fa-rotate-left"></i> Recount Drawer
    </button>
</div>
<?php endif; ?>

<!-- Farewell message (staff / cashier / barista) -->
<?php if ($has_countdown): ?>
<div class="farewell" id="farewell">
    <div class="bye">See you next shift, <?= h($uname) ?> &#x1F44B;</div>
    <div class="sub">Logging you out&hellip;</div>
</div>
<?php else: ?>
<!-- Action buttons (admin / manager / supervisor) -->
<div class="actions">
    <a href="dashboard.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back</a>
    <a href="logout.php" class="btn btn-logout"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
</div>
<?php endif; ?>

<script>
(function () {
    // Animate pay bars
    requestAnimationFrame(() => {
        setTimeout(() => {
            document.querySelectorAll('.pay-bar').forEach(b => {
                b.style.width = b.dataset.w + '%';
            });
        }, 650);
    });

    <?php if ($has_countdown && !$show_cash_step): ?>
    // No cash step — straight farewell + logout
    startFarewell();
    <?php endif; ?>

    const expected  = <?= number_format($expected_cash, 4, '.', '') ?>;
    let attempt     = 0; // 0 = first try, 1 = recount

    window.submitCash = async function () {
        const input     = document.getElementById('actualCash');
        const result    = document.getElementById('cashResult');
        const submitBtn = document.getElementById('submitBtn');
        const recountBtn= document.getElementById('recountBtn');
        const actual    = parseFloat(input.value);
        if (isNaN(actual) || actual < 0) { input.focus(); return; }

        const diff    = Math.round((actual - expected) * 100) / 100;
        const isMatch = Math.abs(diff) < 0.01;

        if (isMatch || attempt >= 1) {
            // Save to DB
            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving…';
            const fd = new FormData();
            fd.append('actual_cash', actual.toFixed(2));
            await fetch('shift_report.php', { method: 'POST', body: fd });

            recountBtn.style.display = 'none';
            input.disabled = true;
            submitBtn.textContent = 'Saved ✓';
            result.style.display = 'block';

            if (isMatch) {
                result.className = 'cash-result match';
                result.textContent = '✓ Drawer balanced. Great job!';
            } else {
                result.className = 'cash-result discrepancy';
                const dir = diff > 0 ? 'over' : 'short';
                result.textContent = `⚠ Still ${dir} by $${Math.abs(diff).toFixed(2)}. Discrepancy logged — manager will review.`;
            }
            setTimeout(startFarewell, 2000);

        } else {
            // First attempt, off — show recount option, do NOT save yet
            attempt = 1;
            result.style.display = 'block';
            result.className = 'cash-result warn';
            const dir = diff > 0 ? 'over' : 'short';
            result.textContent = `↕ Drawer is ${dir} by $${Math.abs(diff).toFixed(2)}. Please recount your drawer.`;
            recountBtn.style.display = 'block';
            submitBtn.style.display  = 'none';
        }
    };

    window.doRecount = function () {
        const input     = document.getElementById('actualCash');
        const result    = document.getElementById('cashResult');
        const submitBtn = document.getElementById('submitBtn');
        const recountBtn= document.getElementById('recountBtn');
        input.value          = '';
        input.disabled       = false;
        result.style.display = 'none';
        recountBtn.style.display = 'none';
        submitBtn.style.display  = '';
        submitBtn.textContent    = 'Submit Final Count';
        input.focus();
    };

    function startFarewell() {
        <?php if ($has_countdown): ?>
        const fw = document.getElementById('farewell');
        if (fw) fw.classList.add('visible');
        setTimeout(() => {
            document.body.classList.add('fading');
            setTimeout(() => { window.location.href = 'logout.php'; }, 520);
        }, 3000);
        <?php endif; ?>
    }
})();
</script>

</body>
</html>
