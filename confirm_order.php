<?php
require 'auth.php';

// ── Migrate: add order_type and completed_at if missing ──
if ($conn->query("SHOW COLUMNS FROM orders LIKE 'order_type'")->num_rows === 0) {
    $conn->query("ALTER TABLE orders ADD COLUMN order_type ENUM('drink_in','drink_out') NOT NULL DEFAULT 'drink_in'");
}
if ($conn->query("SHOW COLUMNS FROM orders LIKE 'completed_at'")->num_rows === 0) {
    $conn->query("ALTER TABLE orders ADD COLUMN completed_at DATETIME NULL");
}

if (empty($_SESSION['cart'])) {
    header("Location: menu.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: cart.php");
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    die("Invalid request. Please try again from the cart page.");
}

// Declared up-front: adding to an existing order must not re-apply order-level fields
// (customer name, stand) that already belong to that order.
$is_add_to_order = ($_POST['is_add_to_order'] ?? '0') === '1';

$customer_name = trim($_POST['customer_name'] ?? '');
if (strlen($customer_name) < 1 || strlen($customer_name) > 120) {
    $customer_name = 'Guest';
}
if (!$is_add_to_order) {
    $_SESSION['customer_name'] = $customer_name;
}

$order_type    = in_array($_POST['order_type'] ?? '', ['drink_in','drink_out']) ? $_POST['order_type'] : 'drink_in';
$table_number  = ($order_type === 'drink_in') ? (substr(trim($_POST['table_number'] ?? ''), 0, 10) ?: null) : null;
if ($is_add_to_order) {
    $table_number = null;   // never re-stamp or re-validate the stand on an add
}

// ── STAND DUPLICATE BLOCK ──
if (!empty($table_number)) {
    // Token-driven: a stand is taken until its placard is returned (released),
    // so block reuse while any non-cancelled order today still holds it.
    $s = $conn->prepare("SELECT daily_order_no, customer_name, status FROM orders WHERE UPPER(table_number) = UPPER(?) AND status NOT IN ('Cancelled','Refunded','Void') AND business_date = CURDATE() LIMIT 1");
    $s->bind_param("s", $table_number);
    $s->execute();
    $dup = $s->get_result()->fetch_assoc();
    if ($dup) {
        $by = $dup['customer_name'] ? ' (' . htmlspecialchars($dup['customer_name']) . ')' : '';
        die('<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Stand In Use</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Poppins,sans-serif;background:#fdf4f4;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#fff;border:1.5px solid #f5c6cb;border-radius:18px;padding:40px 36px;max-width:440px;width:100%;text-align:center;box-shadow:0 8px 32px rgba(220,53,69,.1)}
.icon{font-size:52px;color:#dc3545;margin-bottom:16px}
h1{font-size:22px;font-weight:700;color:#1a1410;margin-bottom:8px}
p{font-size:14px;color:#5a4a3a;line-height:1.6;margin-bottom:6px}
.highlight{display:inline-block;margin:12px 0;padding:10px 18px;background:#fff3cd;border:1px solid #ffc107;border-radius:10px;color:#856404;font-size:13px;font-weight:600}
.btn{display:inline-flex;align-items:center;gap:8px;margin-top:20px;padding:12px 28px;background:#d1904b;color:#fff;border:none;border-radius:50px;font-size:14px;font-weight:600;text-decoration:none;cursor:pointer;font-family:Poppins,sans-serif;transition:all .2s}
.btn:hover{filter:brightness(1.1);transform:translateY(-1px)}
</style></head><body>
<div class="card">
  <div class="icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
  <h1>Stand Already In Use</h1>
  <p>Stand number <strong>' . htmlspecialchars($table_number) . '</strong> is currently assigned to another active order.</p>
  <div class="highlight"><i class="fa-solid fa-ticket"></i> Order #' . htmlspecialchars($dup['daily_order_no']) . $by . ' &mdash; ' . htmlspecialchars($dup['status']) . '</div>
  <p>Please give the customer a different stand, or wait until the current order is completed.</p>
  <a href="javascript:history.back()" class="btn"><i class="fa-solid fa-arrow-left"></i> Go Back</a>
</div>
</body></html>');
    }
}

$payment_methods   = isset($_POST['payment_methods'])   ? $_POST['payment_methods']   : [];
$payment_amounts   = isset($_POST['payment_amounts'])   ? $_POST['payment_amounts']   : [];
$payment_references = isset($_POST['payment_references']) ? $_POST['payment_references'] : [];

// ── VALIDATION: payment methods must not mix paylater with others ──
if (in_array('paylater', $payment_methods) && count($payment_methods) > 1) {
    die("Pay Later cannot be combined with other payment methods. <a href='cart.php'>Go back</a>");
}
// ── VALIDATION: riel cannot be combined with other payment methods ──
if (in_array('riel', $payment_methods) && count($payment_methods) > 1) {
    die("Riel payment cannot be combined with other payment methods. <a href='cart.php'>Go back</a>");
}

// ── EXISTING ORDER (add more items) ──
// Only honour the session var when the form explicitly declares add-to-order mode.
// A stale $_SESSION['add_to_order_id'] left over from a previous, abandoned
// add-to-order flow would otherwise hijack every subsequent normal checkout.
$existing_order_id = ($is_add_to_order && isset($_SESSION['add_to_order_id']))
    ? (int)$_SESSION['add_to_order_id']
    : 0;

if ($existing_order_id > 0) {
    $stmt = $conn->prepare("
        SELECT order_id, customer_name, total, promotion_discount, manual_discount, is_open, order_date, loyalty_card_id, points_earned
        FROM orders
        WHERE order_id = ?
          AND is_open = 1
          AND (status IN ('Preparing', 'Paid') OR (payment_method = 'paylater' AND status = 'Completed'))
    ");
    $stmt->bind_param("i", $existing_order_id);
    $stmt->execute();
    $existing_order = $stmt->get_result()->fetch_assoc();

    if (!$existing_order) {
        $_SESSION['cart'] = [];
        unset($_SESSION['add_to_order_id'], $_SESSION['add_to_daily_no']);
        header("Location: menu.php?error=order_closed");
        exit;
    }

    // Fetch existing items so promotion can be recalculated over all items combined
    $stmt_ei = $conn->prepare("SELECT price, quantity, earns_points FROM order_items WHERE order_id = ?");
    $stmt_ei->bind_param("i", $existing_order_id);
    $stmt_ei->execute();
    $existing_items = $stmt_ei->get_result()->fetch_all(MYSQLI_ASSOC);

    // Preserve happy hour based on original order time (same logic as edit_order_items.php)
    $orig_hour      = (int)date('H', strtotime($existing_order['order_date']));
    $was_happy_hour = ($orig_hour >= HAPPY_HOUR_START && $orig_hour < HAPPY_HOUR_END);

    // Combine existing + new items for full recalculation
    $subtotal = 0; $total_qty = 0; $min_price = PHP_FLOAT_MAX; $points_qty = 0;
    foreach ($existing_items as $ei) {
        $p = (float)$ei['price']; $q = (int)$ei['quantity'];
        $subtotal += $p * $q; $total_qty += $q;
        if ($p > 0 && (int)($ei['earns_points'] ?? 1) === 1) $points_qty += $q;
        if ($p < $min_price) $min_price = $p;
    }
    foreach ($_SESSION['cart'] as $item) {
        $p = (float)($item['price'] ?? 0.0); $q = max(1, (int)($item['qty'] ?? 1));
        $subtotal += $p * $q; $total_qty += $q;
        if ($p > 0 && (int)($item['earns_points'] ?? 1) === 1) $points_qty += $q;
        if ($p < $min_price) $min_price = $p;
    }

    // Buy X Get 1 Free is a GIFT, not a discount — the free drink is an *extra* on top.
    // The customer pays full price for every ordered drink, so it must NOT reduce the total.
    // (Matches the new-order path below; only happy hour + manual discount reduce the charge.)
    $happy_hour = 0;
    if ($was_happy_hour && HAPPY_HOUR_ENABLED) {
        $happy_hour = $subtotal * (HAPPY_HOUR_DISCOUNT / 100);
    }
    // Re-apply the manual discount the order already had — otherwise adding items
    // silently drops it and the customer's total jumps back up.
    $manual_existing = (float)($existing_order['manual_discount'] ?? 0);
    $final_discount = $happy_hour;                               // promotions only (happy hour); stored in promotion_discount
    $after          = $subtotal - $final_discount - $manual_existing;
    if ($after < 0) $after = 0;
    $final_total    = round($after + ($after * (TAX_RATE / 100)), 2);

    $conn->begin_transaction();

    try {
        // Reset paylater status to Preparing inside the transaction (safe: rolls back if items fail)
        if (!empty($_SESSION['paylater_reopen'])) {
            $stmt_reset = $conn->prepare("UPDATE orders SET status = 'Preparing' WHERE order_id = ? AND status = 'Completed'");
            $stmt_reset->bind_param("i", $existing_order_id);
            $stmt_reset->execute();
        }

        $stmt_upd = $conn->prepare("UPDATE orders SET total = ?, promotion_discount = ? WHERE order_id = ?");
        $stmt_upd->bind_param("ddi", $final_total, $final_discount, $existing_order_id);
        $stmt_upd->execute();

        // ── LOYALTY: sync points with new combined drink count (adding items earns more) ──
        $lc_id = (int)($existing_order['loyalty_card_id'] ?? 0);
        if ($lc_id > 0) {
            // $points_qty here is the COMBINED earning quantity of the existing
            // order plus the items being added — computed at line 131 in this
            // branch. loyalty_sync takes that total and works out the delta
            // itself against what the order already recorded.
            //
            // NOTE: this branch's $points_qty is a different variable from the
            // new-order branch's $point_qty further down. Both are correct.
            loyalty_sync($conn, $lc_id, $existing_order_id, $points_qty,
                         "Order #{$existing_order_id} items added — points adjusted");
        }

        $stmt_item = $conn->prepare("
            INSERT INTO order_items (order_id, product_id, product_name, price, quantity, sweetness, ice, milk, size_code, size_label, addons_snapshot, promo_percent, orig_price, earns_points)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        // Merge-on-add: if this exact drink (same product + all options) is already on the order
        // as an UNMADE line, bump its quantity instead of inserting a duplicate row. Made lines are
        // locked (made_qty >= quantity) so they never merge — a repeat of a made drink starts a new
        // unmade line, and only that new one flows to the barista.
        $stmt_match = $conn->prepare("
            SELECT item_id FROM order_items
            WHERE order_id = ? AND product_id = ? AND price = ? AND sweetness = ? AND ice = ? AND milk = ?
              AND size_code = ? AND size_label = ? AND addons_snapshot = ? AND promo_percent = ? AND earns_points = ?
              AND made_qty < quantity
            LIMIT 1
        ");
        $stmt_bump = $conn->prepare("UPDATE order_items SET quantity = quantity + ? WHERE item_id = ?");

        $stock_warnings = [];
        foreach ($_SESSION['cart'] as $item) {
            $qty        = max(1, (int)($item['qty'] ?? 1));
            $price      = (float)($item['price'] ?? 0.0);
            $product_id = (int)($item['product_id'] ?? 0);
            $pname      = $item['product_name'] ?? '';
            $sweet      = $item['sweetness'] ?? '';
            $ice        = $item['ice'] ?? '';
            $milk       = $item['milk'] ?? '';
            $scode      = $item['size_code'] ?? '';
            $slabel     = $item['size_label'] ?? '';
            $sfactor    = (float)($item['size_factor'] ?? 1.0);
            $promo_pct  = (int)($item['promo_percent'] ?? 0);
            $orig_price = (float)($item['orig_price'] ?? $price);
            $earns_pts  = (int)($item['earns_points'] ?? 1);
            $addons_json = json_encode($item['addons'] ?? []);

            $stmt_match->bind_param("iidssssssii", $existing_order_id, $product_id, $price, $sweet, $ice, $milk, $scode, $slabel, $addons_json, $promo_pct, $earns_pts);
            $stmt_match->execute();
            $match = $stmt_match->get_result()->fetch_assoc();
            if ($match) {
                $stmt_bump->bind_param("ii", $qty, $match['item_id']);
                $stmt_bump->execute();
            } else {
                $stmt_item->bind_param("iisdissssssidi", $existing_order_id, $product_id, $pname, $price, $qty, $sweet, $ice, $milk, $scode, $slabel, $addons_json, $promo_pct, $orig_price, $earns_pts);
                $stmt_item->execute();
            }

            // ── STOCK: deduct at order creation time ──
            if ($product_id > 0) {
                $stock_warnings = array_merge($stock_warnings, _deduct_stock($conn, $product_id, $qty, $milk, $existing_order_id, $sfactor));
            }
        }

        $conn->commit();
        _stash_stock_warning($stock_warnings);
        $_SESSION['cart'] = [];
        unset($_SESSION['add_to_order_id'], $_SESSION['add_to_daily_no'], $_SESSION['paylater_reopen']);

        // from=paylater → this tab was reached by adding items from the Pay Later queue,
        // so the confirmation's back button returns there (a fresh menu order omits it → Back to Menu).
        header("Location: payment_paylater.php?order_id=" . $existing_order_id . "&from=paylater");
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        unset($_SESSION['paylater_reopen']);
        header("Location: menu.php?error=add_failed");
        exit;
    }
}

// ── NEW ORDER ──
$subtotal    = 0.0;
$total_qty   = 0;
$min_price   = PHP_FLOAT_MAX;

foreach ($_SESSION['cart'] as $item) {
    $qty   = max(1, (int)($item['qty'] ?? 1));
    $price = (float)($item['price'] ?? 0.0);
    $subtotal  += $price * $qty;
    $total_qty += $qty;
    if ($price < $min_price) $min_price = $price;
}

$happy_hour_discount = 0;
$current_hour        = (int)date('H');
if (HAPPY_HOUR_ENABLED && $current_hour >= HAPPY_HOUR_START && $current_hour < HAPPY_HOUR_END) {
    $happy_hour_discount = $subtotal * (HAPPY_HOUR_DISCOUNT / 100);
}

$after_promos_co = $subtotal - $happy_hour_discount;
$md_co = $_SESSION['manual_discount'] ?? null;
$manual_discount_co = 0.0;
$manual_reason_co   = '';
if ($md_co && (float)($md_co['amount'] ?? 0) > 0) {
    $manual_discount_co = $md_co['type'] === 'flat'
        ? min((float)$md_co['amount'], max(0, $after_promos_co))
        : max(0, $after_promos_co) * ((float)$md_co['amount'] / 100.0);
    $manual_reason_co = substr(trim($md_co['reason'] ?? ''), 0, 100);
    $after_promos_co -= $manual_discount_co;
}

// NOTE: Buy X Get 1 Free is NOT subtracted here — intentional by design.
// The free drink is an *extra* gift on top of what the customer ordered.
// Customer pays full price for all ordered drinks; the free drink costs the cafe $0 to give.
// Only happy_hour and manual discounts reduce the chargeable total.
// promotion_discount stores PROMOTIONS ONLY (happy hour). The manual discount is stored
// separately in the manual_discount column — do NOT bundle it in here, or receipts that
// render both promotion_discount and manual_discount as separate lines double-show it.
// (The charged total below is unaffected: $after_promos_co already subtracted the manual.)
$total_discount      = $happy_hour_discount;
$subtotal_after      = $after_promos_co;
$tax                 = $subtotal_after * (TAX_RATE / 100);
$total               = round($subtotal_after + $tax, 2);

// ── PAYMENT VALIDATION ──
if (empty($payment_methods) || empty($payment_amounts)) {
    $payment_methods    = ['bakong'];
    $payment_amounts    = [$total];
    $payment_references = [''];
}

// Pay Later: customer pays at pickup — the client-submitted amount may be stale
// (race condition: user clicks confirm while loadCartPanel() is still in-flight).
// Force the amount to the server-calculated total so there is never a mismatch.
if (in_array('paylater', $payment_methods)) {
    $payment_methods    = ['paylater'];
    $payment_amounts    = [$total];
    $payment_references = [''];
}
// Riel: KHR→USD conversion has inherent rounding; trust the server total for the
// stored USD amount while keeping the raw KHR reference for receipt display.
if (count($payment_methods) === 1 && $payment_methods[0] === 'riel') {
    $payment_amounts[0] = $total;
}

$total_paid = 0;
foreach ($payment_amounts as $amt) {
    $total_paid += (float)$amt;
}

if (abs($total_paid - $total) > 0.01) {
    die("Payment amount mismatch. Expected $" . number_format($total, 2) . ", got $" . number_format($total_paid, 2) . ". <a href='cart.php'>Go back</a>");
}

// ── ORDER STATUS LOGIC ──
// Pay Later → Preparing, open (customer settles later at counter)
// Bakong    → PendingPayment (awaiting QR scan)
// Cash only → Preparing, closed (paid immediately, goes straight to kitchen)
// Split (cash+bakong) → PendingPayment (Bakong leg must complete)
$has_paylater = in_array('paylater', $payment_methods);
$has_bakong   = in_array('bakong',   $payment_methods);

if ($has_paylater) {
    $order_status = 'Preparing';
    $is_open      = 1;
} elseif ($has_bakong) {
    $order_status = 'PendingPayment';
    $is_open      = 0;
} else {
    $order_status = 'Preparing';
    $is_open      = 0;
}

/* Validated against the known set. This value went to orders.payment_method
   verbatim, which is how 195 orders came to store the literal string '0' — they
   count as revenue but match no method, so a by-method split misses them entirely.
   Coerced rather than rejected: the customer's money is already committed by this
   point, so failing here would be worse than recording a sane default. */
$primary_method = order_payment_method_or($payment_methods[0] ?? null, 'cash');

// ── DAILY ORDER NUMBER ──
date_default_timezone_set('Asia/Phnom_Penh');
$now       = new DateTime();
$today6am  = (clone $now)->setTime(6, 0, 0);
if ($now < $today6am) $today6am->modify('-1 day');

$start = $today6am->format('Y-m-d H:i:s');
$end   = (clone $today6am)->modify('+1 day -1 second')->format('Y-m-d H:i:s');

$stmt = $conn->prepare("SELECT COALESCE(MAX(daily_order_no), 0) + 1 AS next_no FROM orders WHERE order_date >= ? AND order_date <= ?");
$stmt->bind_param("ss", $start, $end);
$stmt->execute();
$daily_no = (int)$stmt->get_result()->fetch_assoc()['next_no'];

$conn->begin_transaction();

try {
    $business_date = $today6am->format('Y-m-d');

    // ── UNIQUE TOKEN ──
    $token_number  = rand(1, 999);
    $stmt_tok = $conn->prepare("SELECT COUNT(*) FROM orders WHERE token_number = ? AND DATE(order_date) = CURDATE()");
    $stmt_tok->bind_param("i", $token_number);
    do {
        $token_number = rand(1, 999);
        $stmt_tok->bind_param("i", $token_number);
        $stmt_tok->execute();
        $tok_count = (int)$stmt_tok->get_result()->fetch_row()[0];
    } while ($tok_count > 0);

    $employee_name = $_SESSION['username'] ?? 'Unknown';
    $_uid = (int)($_SESSION['user_id'] ?? 0);
    $_emp_r = $conn->prepare("SELECT employee_id FROM employees WHERE user_id = ? LIMIT 1");
    $_emp_r->bind_param("i", $_uid); $_emp_r->execute();
    $_emp_row = $_emp_r->get_result()->fetch_assoc();
    $employee_id = $_emp_row ? (int)$_emp_row['employee_id'] : null;

    // ── IMPORTANT: Cast variables to the correct types ──
    $customer_name   = (string)$customer_name;
    $total           = (float)$total;
    $daily_no        = (int)$daily_no;
    $order_status    = (string)$order_status;
    $business_date   = (string)$business_date;
    $primary_method  = (string)$primary_method;
    $total_discount  = (float)$total_discount;
    $is_open         = (int)$is_open;
    $token_number    = (int)$token_number;
    // employee_id already resolved above (int or null)
    $employee_name   = (string)$employee_name;

    // Only stamp completed_at for fully-paid orders (not paylater which is still open)
    $completed_at = ($order_status === 'Preparing' && $is_open === 0) ? date('Y-m-d H:i:s') : null;

    // started_at = when first item was added to the session cart
    $started_at = $_SESSION['cart_started_at'] ?? date('Y-m-d H:i:s');

    $stmt_order = $conn->prepare("
        INSERT INTO orders
        (customer_name, total, daily_order_no, status, business_date, payment_method,
         promotion_discount, is_open, token_number, employee_id, employee_name,
         manual_discount, manual_discount_reason, order_type, completed_at, table_number, started_at, user_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt_order->bind_param(
        "sdisssdiiisdsssssi",
        $customer_name, $total, $daily_no, $order_status, $business_date,
        $primary_method, $total_discount, $is_open, $token_number,
        $employee_id, $employee_name,
        $manual_discount_co, $manual_reason_co, $order_type, $completed_at, $table_number, $started_at, $_uid
    );
    $stmt_order->execute();
    $order_id = $conn->insert_id;

    // ── PAYMENT RECORDS ──
    $stmt_pay = $conn->prepare("
        INSERT INTO order_payments (order_id, payment_method, amount, reference, payment_status)
        VALUES (?, ?, ?, ?, ?)
    ");
    for ($i = 0; $i < count($payment_methods); $i++) {
        // Same allow-list as orders.payment_method above: the two must agree, or a
        // split leg could be recorded under a method the order itself never names.
        $method    = order_payment_method_or($payment_methods[$i] ?? null, 'cash');
        $amount    = (float)$payment_amounts[$i];
        $reference = (string)($payment_references[$i] ?? '');
        // A cash tender is re-emitted through tender_ref() so the stored string
        // is always canonical, whatever the POST contained. Same guard pattern
        // as f5aea86, which stopped orders.payment_method being written verbatim
        // from a POST and leaving 195 rows reading '0'.
        //
        // Only the cash leg: a Bakong reference is a transaction id and must
        // pass through untouched, and tender_parts() would return null for it
        // anyway.
        if ($method === 'cash') {
            $parts     = tender_parts($reference);
            $reference = $parts === null ? '' : tender_ref($parts['usd'], $parts['khr']);
        }
        $pay_status = in_array($method, ['bakong', 'paylater']) ? 'pending' : 'paid';

        if ($amount > 0) {
            $stmt_pay->bind_param("isdss", $order_id, $method, $amount, $reference, $pay_status);
            $stmt_pay->execute();
        }
    }

    // ── ORDER ITEMS + STOCK DEDUCTION ──
    $stmt_item = $conn->prepare("
        INSERT INTO order_items (order_id, product_id, product_name, price, quantity, sweetness, ice, milk, size_code, size_label, addons_snapshot, promo_percent, orig_price, earns_points)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stock_warnings = [];
    $point_qty = 0;   // loyalty: earning (drink) + chargeable qty; computed here before the cart is cleared
    foreach ($_SESSION['cart'] as $item) {
        $qty        = max(1, (int)($item['qty'] ?? 1));
        $price      = (float)($item['price'] ?? 0.0);
        $product_id = (int)($item['product_id'] ?? 0);
        $pname      = $item['product_name'] ?? '';
        $sweet      = $item['sweetness'] ?? '';
        $ice        = $item['ice'] ?? '';
        $milk       = $item['milk'] ?? '';
        $scode      = $item['size_code'] ?? '';
        $slabel     = $item['size_label'] ?? '';
        $sfactor    = (float)($item['size_factor'] ?? 1.0);
        $promo_pct  = (int)($item['promo_percent'] ?? 0);
        $orig_price = (float)($item['orig_price'] ?? $price);
        $earns_pts  = (int)($item['earns_points'] ?? 1);
        if ($earns_pts === 1 && $price > 0) $point_qty += $qty;
        $addons_json = json_encode($item['addons'] ?? []);

        // price is the NET (post-promo) unit price; promo is already baked in. Do not re-discount.
        $stmt_item->bind_param("iisdissssssidi", $order_id, $product_id, $pname, $price, $qty, $sweet, $ice, $milk, $scode, $slabel, $addons_json, $promo_pct, $orig_price, $earns_pts);
        $stmt_item->execute();

        if ($product_id > 0) {
            $stock_warnings = array_merge($stock_warnings, _deduct_stock($conn, $product_id, $qty, $milk, $order_id, $sfactor));
        }
    }

    // ── ADD REDEEMED REWARDS TO ORDER + deduct points now that order is confirmed ──
    if (!empty($_SESSION['redeemed_rewards'])) {
        $stmt_reward = $conn->prepare("
            INSERT INTO order_items (order_id, product_id, product_name, price, quantity, sweetness, ice, milk, size_code, size_label, addons_snapshot, promo_percent, orig_price, earns_points)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt_deduct   = $conn->prepare("UPDATE loyalty_cards SET points = GREATEST(0, points - ?), last_used = NOW() WHERE card_id = ?");
        $stmt_hist     = $conn->prepare("
            INSERT INTO loyalty_history (card_id, order_id, points_change, type, reward_name, description)
            VALUES (?, ?, ?, 'redeemed', ?, ?)
        ");

        foreach ($_SESSION['redeemed_rewards'] as $reward) {
            // Add free item to order
            $rid        = 0;
            $rname      = "[GIFT] {$reward['reward_name']} (Loyalty)";
            $rprice     = 0.0;
            $rqty       = 1;
            $rempty     = '';
            $addons_json = json_encode($reward['addons'] ?? []);
            $rpromo = 0; $rorig = 0.0; $rearn = 0;
            $stmt_reward->bind_param("iisdissssssidi", $order_id, $rid, $rname, $rprice, $rqty, $rempty, $rempty, $rempty, $rempty, $rempty, $addons_json, $rpromo, $rorig, $rearn);
            $stmt_reward->execute();

            // Deduct points from card (the deduction that loyalty_redeem.php now defers)
            $pts_used   = (int)$reward['points_required'];
            $card_db_id = (int)($reward['card_id_int'] ?? 0);
            if ($card_db_id > 0 && $pts_used > 0) {
                $stmt_deduct->bind_param("ii", $pts_used, $card_db_id);
                $stmt_deduct->execute();

                $neg_pts   = -$pts_used;
                $desc      = "Redeemed {$reward['reward_name']} for {$pts_used} points";
                $rwd_name  = $reward['reward_name'];
                $stmt_hist->bind_param("iiiss", $card_db_id, $order_id, $neg_pts, $rwd_name, $desc);
                $stmt_hist->execute();
            }
        }

        // Clear session — rewards are now committed to the DB
        $_SESSION['redeemed_rewards'] = [];
    }

    // ── LINK CUSTOMER ──
    if ($customer_name !== 'Guest' && $customer_name !== '') {
        $stmt_cf = $conn->prepare("SELECT customer_id FROM customers WHERE name = ? LIMIT 1");
        $stmt_cf->bind_param("s", $customer_name);
        $stmt_cf->execute();
        $cust_row = $stmt_cf->get_result()->fetch_assoc();
        if ($cust_row) {
            $cust_id = (int)$cust_row['customer_id'];
        } else {
            $stmt_cn = $conn->prepare("INSERT INTO customers (name) VALUES (?)");
            $stmt_cn->bind_param("s", $customer_name);
            $stmt_cn->execute();
            $cust_id = $conn->insert_id;
        }
        $stmt_cu = $conn->prepare("UPDATE orders SET customer_id = ? WHERE order_id = ?");
        $stmt_cu->bind_param("ii", $cust_id, $order_id);
        $stmt_cu->execute();
    }

    $conn->commit();
    _stash_stock_warning($stock_warnings);
    unset($_SESSION['csrf_token']);
    $_SESSION['cart'] = [];
    unset($_SESSION['manual_discount']);
    unset($_SESSION['cart_started_at']);

    // ── LOYALTY POINTS ──
    // Rate and carry-forward both live in loyalty_sync(); this site only says
    // which card, which order, and how many earning drinks.
    $loyalty_card_id = isset($_SESSION['loyalty_card_id']) ? (int)$_SESSION['loyalty_card_id'] : 0;
    if ($loyalty_card_id > 0) {
        $units = loyalty_earning_units($conn, $order_id);
        if ($units > 0) {
            loyalty_sync($conn, $loyalty_card_id, $order_id, $units, 'Points earned from order');
        }

        // Link the card to this order even when it earned nothing (e.g. a
        // merch-only order) — refund_order.php and cancel_order.php key off
        // orders.loyalty_card_id to know which card to reverse points on.
        // loyalty_sync() only ever writes points_earned/points_qty, never this
        // column, so it still needs setting here.
        $stmt_link = $conn->prepare("UPDATE orders SET loyalty_card_id = ? WHERE order_id = ?");
        if ($stmt_link) { $stmt_link->bind_param("ii", $loyalty_card_id, $order_id); $stmt_link->execute(); }

        // Clear card from session so the next customer's order doesn't accidentally inherit it
        unset($_SESSION['loyalty_card_id']);
    }

    // ── REDIRECT ──
    if ($has_bakong) {
        header("Location: payment.php?order_id=" . $order_id);
    } elseif ($has_paylater) {
        header("Location: payment_paylater.php?order_id=" . $order_id);
    } else {
        header("Location: payment_cash.php?order_id=" . $order_id);
    }
    exit;

} catch (Exception $e) {
    $conn->rollback();
    echo "<h1 style='color:red;font-family:sans-serif'>Order Failed</h1>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><a href='cart.php'>Back to Cart</a></p>";
    exit;
}

// ── HELPER: deduct ingredients, respecting milk substitution ──
/**
 * Persist a one-shot stock-shortfall notice for staff. Shown (and cleared) on the
 * next page that renders it (menu.php). No-op when nothing ran short.
 */
function _stash_stock_warning(array $shortfalls): void {
    if (empty($shortfalls)) return;
    // Collapse to one line per ingredient ("Milk: needed 3, had 1").
    $byName = [];
    foreach ($shortfalls as $s) {
        $n = $s['name'];
        if (!isset($byName[$n])) $byName[$n] = ['need' => 0.0, 'had' => $s['had']];
        $byName[$n]['need'] += $s['need'];
        $byName[$n]['had']   = min($byName[$n]['had'], $s['had']);
    }
    $msgs = [];
    foreach ($byName as $n => $v) {
        $msgs[] = $n . ': needed ' . rtrim(rtrim(number_format($v['need'], 2), '0'), '.')
                . ', had ' . rtrim(rtrim(number_format($v['had'], 2), '0'), '.');
    }
    $_SESSION['stock_warning'] = $msgs;
}

/* _deduct_stock() lives in config.php so remake_order.php can share it — a
   remake pours real drinks and must deduct the same way, including the milk
   substitution. One writer, never a copy. */
?>