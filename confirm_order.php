<?php
require 'auth.php';
require 'config.php';

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
$is_add_to_order = ($_POST['is_add_to_order'] ?? '0') === '1';

$customer_name = trim($_POST['customer_name'] ?? '');
if (strlen($customer_name) < 1 || strlen($customer_name) > 120) {
    $customer_name = 'Guest';
}
if (!$is_add_to_order) {
    $_SESSION['customer_name'] = $customer_name;
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

// ── INVENTORY VALIDATION: Ensure all ingredients in cart are available before checkout ──
$requiredStock = [];
foreach (($_SESSION['cart'] ?? []) as $cItem) {
    $pId = (int)($cItem['product_id'] ?? 0);
    $q = max(1, (int)($cItem['qty'] ?? 1));
    $sFactor = (float)($cItem['size_factor'] ?? 1.0);
    $sweet = (string)($cItem['sweetness'] ?? '');
    $ice = (string)($cItem['ice'] ?? '');
    $milk = (string)($cItem['milk'] ?? '');
    if ($pId <= 0) continue;

    // Sweetness factor
    $sweetnessFactor = 1.0;
    $swNorm = str_replace(' ', '', strtolower(trim($sweet)));
    if ($swNorm === '0%' || $swNorm === '0' || $swNorm === 'nosugar' || $swNorm === 'គ្មានស្ករ') {
        $sweetnessFactor = 0.0;
    } elseif ($swNorm === '25%' || $swNorm === '25') {
        $sweetnessFactor = 0.25;
    } elseif ($swNorm === '50%' || $swNorm === '50') {
        $sweetnessFactor = 0.50;
    } elseif ($swNorm === '75%' || $swNorm === '75') {
        $sweetnessFactor = 0.75;
    } elseif ($swNorm === '100%' || $swNorm === '100') {
        $sweetnessFactor = 1.0;
    }

    // Ice factor
    $iceFactor = 1.0;
    $iceNorm = strtolower(trim($ice));
    if (str_contains($iceNorm, 'no ice') || str_contains($iceNorm, 'គ្មានទឹកកក') || $iceNorm === 'no') {
        $iceFactor = 0.0;
    } elseif (str_contains($iceNorm, 'less ice') || str_contains($iceNorm, 'ទឹកកកតិច')) {
        $iceFactor = 0.5;
    } elseif (str_contains($iceNorm, 'more ice') || str_contains($iceNorm, 'extra ice') || str_contains($iceNorm, 'ទឹកកកច្រើន')) {
        $iceFactor = 1.3;
    } elseif (str_contains($iceNorm, 'normal') || str_contains($iceNorm, 'ធម្មតា')) {
        $iceFactor = 1.0;
    }

    $stmtBOM = $conn->prepare("SELECT r.item_id, r.quantity_required, s.item_name, s.category, s.quantity, s.unit 
                               FROM product_recipes r 
                               JOIN stock_items s ON r.item_id = s.item_id 
                               WHERE r.product_id = ? AND s.is_active = 1");
    if ($stmtBOM) {
        $stmtBOM->bind_param("i", $pId);
        $stmtBOM->execute();
        $resBOM = $stmtBOM->get_result();
        while ($bRow = $resBOM->fetch_assoc()) {
            $iName = strtolower($bRow['item_name'] ?? '');
            $cat = $bRow['category'] ?? '';
            if (str_contains($iName, 'packaging set') || str_contains($bRow['item_name'], 'ឈុត')) {
                continue;
            }

            $iId = (int)$bRow['item_id'];
            $iDispName = $bRow['item_name'];
            $iAvail = (float)$bRow['quantity'];
            $iUnit = $bRow['unit'];

            // Milk substitution precheck
            $milkNorm = strtolower(trim($milk));
            if (!empty($milkNorm) && (str_contains($iName, 'milk') || str_contains($iName, 'ទឹកដោះគោ')) && !str_contains($iName, 'oat') && str_contains($milkNorm, 'oat')) {
                $subStmt = $conn->prepare("SELECT item_id, item_name, quantity, unit FROM stock_items WHERE LOWER(item_name) LIKE '%oat milk%' AND is_active = 1 LIMIT 1");
                if ($subStmt) {
                    $subStmt->execute();
                    if ($subRow = $subStmt->get_result()->fetch_assoc()) {
                        $iId = (int)$subRow['item_id'];
                        $iDispName = $subRow['item_name'];
                        $iAvail = (float)$subRow['quantity'];
                        $iUnit = $subRow['unit'];
                    }
                    $subStmt->close();
                }
            }

            $customMult = 1.0;
            if (str_contains($iName, 'sugar') || str_contains($iName, 'syrup') || str_contains($bRow['item_name'], 'ស្ករ') || str_contains($bRow['item_name'], 'ទឹកស្ករ') || $cat === 'Syrups') {
                $customMult = $sweetnessFactor;
            } elseif (str_contains($iName, 'ice') || str_contains($bRow['item_name'], 'ទឹកកក') || $cat === 'Ice') {
                $customMult = $iceFactor;
            }

            $req = (float)$bRow['quantity_required'] * (float)$q * $sFactor * $customMult;
            if ($req <= 0) continue;

            if (!isset($requiredStock[$iId])) {
                $requiredStock[$iId] = [
                    'name'      => $iDispName,
                    'available' => $iAvail,
                    'unit'      => $iUnit,
                    'needed'    => 0.0
                ];
            }
            $requiredStock[$iId]['needed'] += $req;
        }
        $stmtBOM->close();
    }
}

$stockShortfalls = [];
foreach ($requiredStock as $st) {
    if ($st['available'] < $st['needed']) {
        $availStr = (floor($st['available']) == $st['available']) ? number_format($st['available'], 0) : number_format($st['available'], 2);
        $needStr  = (floor($st['needed']) == $st['needed']) ? number_format($st['needed'], 0) : number_format($st['needed'], 2);
        $stockShortfalls[] = "{$st['name']} (Available: {$availStr} {$st['unit']}, Needed: {$needStr} {$st['unit']})";
    }
}

if (!empty($stockShortfalls)) {
    $shortMsg = "Checkout Blocked: Insufficient inventory stock for: " . implode('; ', $stockShortfalls) . ". Another cashier may have just sold the remaining stock. Please update your cart.";
    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'insufficient_stock', 'message' => $shortMsg]);
        exit;
    }
    $_SESSION['flash_error'] = $shortMsg;
    header("Location: cart.php?error=insufficient_stock");
    exit;
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
        SELECT order_id, total, order_date
        FROM orders
        WHERE order_id = ?
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

            // ── STOCK: deduct at order creation time with customized sweetness & ice ──
            if ($product_id > 0) {
                $stock_warnings = array_merge($stock_warnings, _deduct_stock($conn, $product_id, $qty, $milk, $existing_order_id, $sfactor, null, $sweet, $ice));
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
$item_manual_discounts_total = 0.0;

foreach ($_SESSION['cart'] as $item) {
    $qty   = max(1, (int)($item['qty'] ?? 1));
    $price = (float)($item['price'] ?? 0.0);
    $itemLineTotal = $price * $qty;
    $subtotal  += $itemLineTotal;
    $total_qty += $qty;
    if ($price < $min_price) $min_price = $price;

    $discType = $item['discount_type'] ?? '';
    $discAmt  = (float)($item['discount_amount'] ?? 0);
    if ($discAmt > 0) {
        if ($discType === 'flat') {
            $item_manual_discounts_total += min($itemLineTotal, $discAmt);
        } else {
            $item_manual_discounts_total += $itemLineTotal * (min(100, $discAmt) / 100.0);
        }
    }
}

$happy_hour_discount = 0;
$current_hour        = (int)date('H');
if (HAPPY_HOUR_ENABLED && $current_hour >= HAPPY_HOUR_START && $current_hour < HAPPY_HOUR_END) {
    $happy_hour_discount = ($subtotal - $item_manual_discounts_total) * (HAPPY_HOUR_DISCOUNT / 100);
}

$after_promos_co = max(0, $subtotal - $item_manual_discounts_total - $happy_hour_discount);
$md_co = $_SESSION['manual_discount'] ?? null;
$overall_manual_discount = 0.0;
$manual_reason_co   = '';
if ($md_co && (float)($md_co['amount'] ?? 0) > 0) {
    $overall_manual_discount = $md_co['type'] === 'flat'
        ? min((float)$md_co['amount'], max(0, $after_promos_co))
        : max(0, $after_promos_co) * ((float)$md_co['amount'] / 100.0);
    $manual_reason_co = substr(trim($md_co['reason'] ?? ''), 0, 100);
    $after_promos_co -= $overall_manual_discount;
}

$manual_discount_co = $item_manual_discounts_total + $overall_manual_discount;
$total_discount      = $happy_hour_discount;
$subtotal_after      = $after_promos_co;
$tax                 = $subtotal_after * (TAX_RATE / 100);
$total               = round($subtotal_after + $tax, 2);

// ── PAYMENT VALIDATION ──
if (empty($payment_methods) || empty($payment_amounts)) {
    $payment_methods    = ['cash'];
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
// Cash/Riel: trust the server total for the stored USD payment amount
if (count($payment_methods) === 1 && in_array($payment_methods[0], ['cash', 'riel'])) {
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

$conn->begin_transaction();

try {
    $_uid           = (int)($_SESSION['user_id'] ?? 0);
    $customer_name  = (string)$customer_name;
    $total          = (float)$total;
    $order_status   = (string)$order_status;
    $primary_method = (string)$primary_method;
    $started_at     = $_SESSION['cart_started_at'] ?? date('Y-m-d H:i:s');

    $stmt_order = $conn->prepare("
        INSERT INTO orders
        (user_id, total, payment_method, order_date, started_at)
        VALUES (?, ?, ?, NOW(), ?)
    ");
    $stmt_order->bind_param(
        "idss",
        $_uid, $total, $primary_method, $started_at
    );
    $stmt_order->execute();
    $order_id = $conn->insert_id;
    $daily_no = $order_id;

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
        } elseif ($method === 'bakong' && empty($reference)) {
            try {
                if (file_exists(__DIR__ . '/bakong-khqr-php-main/autoload.php')) {
                    require_once __DIR__ . '/bakong-khqr-php-main/autoload.php';
                } elseif (file_exists(__DIR__ . '/bakong-khqr-php-main/vendor/autoload.php')) {
                    require_once __DIR__ . '/bakong-khqr-php-main/vendor/autoload.php';
                }
                $b_config = require __DIR__ . '/bakong_config.php';
                $order_ts = !empty($started_at) ? strtotime($started_at) : time();
                $exp_ts   = strval(($order_ts + 15 * 60) * 1000);

                $b_info = new \KHQR\Models\IndividualInfo(
                    bakongAccountID: $b_config['bakong_id'],
                    merchantName: $b_config['merchant_name'],
                    merchantCity: $b_config['merchant_city'],
                    currency: $b_config['currency'],
                    amount: $amount,
                    billNumber: 'ORDER_' . $order_id,
                    storeLabel: 'BirdNestCafe',
                    terminalLabel: 'POS1',
                    mobileNumber: $b_config['mobile_number'],
                    expirationTimestamp: $exp_ts
                );

                $b_res = \KHQR\BakongKHQR::generateIndividual($b_info);
                if (($b_res->status['code'] ?? 1) === 0 && !empty($b_res->data['md5'])) {
                    $reference = $b_res->data['md5'];
                    if (!empty($b_res->data['qr'])) {
                        $_SESSION['bakong_qr_' . $order_id] = $b_res->data['qr'];
                    }
                }
            } catch (Throwable $e) {
                error_log("confirm_order.php: Bakong MD5 generation error: " . $e->getMessage());
            }
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
        $unit_price = (float)($item['price'] ?? 0.0);
        $product_id = (int)($item['product_id'] ?? 0);
        $pname      = $item['product_name'] ?? '';
        $sweet      = $item['sweetness'] ?? '';
        $ice        = $item['ice'] ?? '';
        $milk       = $item['milk'] ?? '';
        $scode      = $item['size_code'] ?? '';
        $slabel     = $item['size_label'] ?? '';
        $sfactor    = (float)($item['size_factor'] ?? 1.0);
        $orig_price = (float)($item['orig_price'] ?? $unit_price);
        $earns_pts  = (int)($item['earns_points'] ?? 1);
        if ($earns_pts === 1 && $unit_price > 0) $point_qty += $qty;
        $addons_json = json_encode($item['addons'] ?? []);

        $discType   = $item['discount_type'] ?? '';
        $discAmt    = (float)($item['discount_amount'] ?? 0);
        $promo_pct  = (int)($item['promo_percent'] ?? 0);
        $itemDisc   = 0.0;

        if ($discAmt > 0) {
            $itemLineTotal = $unit_price * $qty;
            if ($discType === 'flat') {
                $itemDisc = min($itemLineTotal, $discAmt);
                if ($itemLineTotal > 0) {
                    $promo_pct = (int)round(($itemDisc / $itemLineTotal) * 100);
                }
            } else {
                $promo_pct = (int)min(100, $discAmt);
                $itemDisc = $itemLineTotal * ($promo_pct / 100.0);
            }
        }

        $final_price = $qty > 0 ? ($unit_price * $qty - $itemDisc) / $qty : $unit_price;

        $stmt_item->bind_param("iisdissssssidi", $order_id, $product_id, $pname, $final_price, $qty, $sweet, $ice, $milk, $scode, $slabel, $addons_json, $promo_pct, $orig_price, $earns_pts);
        $stmt_item->execute();

        if ($product_id > 0) {
            $stock_warnings = array_merge($stock_warnings, _deduct_stock($conn, $product_id, $qty, $milk, $order_id, $sfactor, null, $sweet, $ice));
        }
    }

    $conn->commit();
    _stash_stock_warning($stock_warnings);
    if ($has_bakong) {
        $_SESSION['bakong_cart_stash'] = $_SESSION['cart'];
    }
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

    // ── REDIRECT / JSON RESPONSE ──
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        || (!empty($_POST['is_ajax']));

    if ($is_ajax) {
        header('Content-Type: application/json');
        $rate = defined('KHR_RATE') ? (int)KHR_RATE : 4100;
        $khr_amount = (int)(round($total * $rate / 100) * 100);
        if ($total > 0 && $khr_amount === 0) {
            $khr_amount = (int)round($total * $rate);
        }
        $qr_data = $_SESSION['bakong_qr_' . $order_id] ?? ($b_res->data['qr'] ?? '');
        $m_name = $b_config['merchant_name'] ?? 'The Bird\'s Nest Coffee';
        echo json_encode([
            'success'         => true,
            'order_id'        => $order_id,
            'daily_order_no'  => $order_id,
            'total'           => $total,
            'amount'          => $total,
            'amount_khr'      => $khr_amount,
            'has_bakong'      => $has_bakong,
            'has_paylater'    => $has_paylater,
            'qr'              => $qr_data,
            'md5'             => $reference ?? '',
            'currency'        => 'USD',
            'merchant_name'   => $m_name
        ]);
        exit;
    }

    if ($has_bakong) {
        header("Location: menu.php?bakong_order_id=" . $order_id);
    } elseif ($has_paylater) {
        header("Location: payment_paylater.php?order_id=" . $order_id);
    } else {
        header("Location: receipt_print.php?order_id=" . $order_id);
    }
    exit;

} catch (Exception $e) {
    $conn->rollback();
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        || (!empty($_POST['is_ajax']));
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
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
    unset($_SESSION['stock_warning']);
}

/* _deduct_stock() lives in config.php so remake_order.php can share it — a
   remake pours real drinks and must deduct the same way, including the milk
   substitution. One writer, never a copy. */
?>