<?php
require 'auth.php'; // starts session, loads config ($conn), re-syncs $_SESSION['role']
// Cashiers (staff) collect pay-later payments from find_order.php — allow them alongside admin/manager
if (!in_array($_SESSION['role'] ?? '', ['admin', 'manager', 'staff'])) {
    header("Location: dashboard.php?denied=1");
    exit;
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$order_id = (int)($_GET['order_id'] ?? 0);
// Validated, never interpolated: this string ends up in a Location: header.
$return_tab  = pay_return_tab($_POST['return'] ?? $_GET['return'] ?? null);
$return_page = pay_return_url($return_tab);

if ($order_id <= 0) {
    header("Location: $return_page");
    exit;
}

// Settling spends money, so it needs a submit. As a GET this page charged the
// customer while it loaded: a refresh, a back-button or a browser link prefetch
// was indistinguishable from a deliberate click, and the cashier had nowhere to
// enter what the customer actually handed over.
$is_settle = ($_SERVER['REQUEST_METHOD'] === 'POST');
if ($is_settle && !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    header("Location: $return_page");
    exit;
}

// Fetch order details
$stmt = $conn->prepare("
    SELECT order_id, daily_order_no, customer_name, total, status, payment_method, loyalty_card_id, points_earned
    FROM orders
    WHERE order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    header("Location: $return_page");
    exit;
}

// A GET renders the tender screen and writes nothing. Everything below this line
// runs only for a POST that carried a valid token, so the settlement logic stays
// exactly as it was rather than being re-indented into a branch.
if (!$is_settle) {
    // partial=1 returns just the tender panel, for the find_order.php modal, so the
    // markup has exactly one source. Without it the full standalone page renders and
    // still works for direct links and with JavaScript off.
    $tender_fragment = (($_GET['partial'] ?? '') === '1');
    include '_cash_tender.php';
    exit;
}

// Mark order as Paid, close it, and sync all payment records atomically
$conn->begin_transaction();
try {
    // Paylater settled at the counter → 'Paid' (drops it from the unpaid list);
    // otherwise advance normally (matches admin_pay_confirm.php).
    $new_status = ($order['payment_method'] === 'paylater')
        ? 'Paid'
        : (($order['status'] === 'PendingPayment') ? 'Preparing' : 'Completed');
    $stmt = $conn->prepare("UPDATE orders SET status = ?, is_open = 0, completed_at = NOW() WHERE order_id = ?");
    $stmt->bind_param("si", $new_status, $order_id);
    $stmt->execute();

    $stmt = $conn->prepare("
        UPDATE order_payments SET payment_status = 'paid'
        WHERE order_id = ? AND payment_status != 'paid'
    ");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();

    // This button means "the customer is handing over cash", so cash is what
    // has to be recorded. Marking the existing row paid without touching its
    // method booked the money under whatever was chosen at checkout: an order
    // placed as Bakong and then paid in cash was stored as Bakong on both
    // orders.payment_method and order_payments, silently. Nothing downstream
    // could tell — it leaves no trace to search for — so the day's Bakong
    // figure absorbed money that is physically in the drawer.
    //
    // payment.php:30-38 has always done this correctly for the same decision
    // made at the checkout screen. This is that behaviour, applied to the
    // counter.
    //
    // Pay-later is deliberately excluded: settling a tab in cash does not stop
    // it being a pay-later sale, and the reports keep a separate "pay later,
    // settled" bucket that reads orders.payment_method.
    if (($order['payment_method'] ?? '') !== 'paylater') {
        // A split tender cannot be rewritten as cash — 96 orders in this
        // database are genuine splits (e.g. $1.00 Bakong + $2.30 cash), and
        // collapsing them to one method would assert how money we never saw
        // was handed over. Convert only when a single method is in play.
        $chk = $conn->prepare("SELECT COUNT(DISTINCT payment_method) FROM order_payments WHERE order_id = ?");
        $chk->bind_param("i", $order_id);
        $chk->execute();
        $methodCount = (int)$chk->get_result()->fetch_row()[0];

        if ($methodCount <= 1) {
            $stmt = $conn->prepare("
                UPDATE order_payments
                SET payment_method = 'cash', payment_status = 'paid'
                WHERE order_id = ?
            ");
            $stmt->bind_param("i", $order_id);
            $stmt->execute();

            $stmt = $conn->prepare("
                UPDATE orders SET payment_method = 'cash', bakong_md5 = NULL WHERE order_id = ?
            ");
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
        }
    }

    // The tender is what lets a receipt print Received / Change. It lives in
    // order_payments.reference — the same column menu.php writes at checkout via
    // payment_references[] (confirm_order.php:446) and that receipt_pdf.php reads
    // back. Without it, a counter settlement produced a receipt with no change
    // lines while an identical checkout sale produced one with them.
    //
    // Single-row payments only: on a split the change belongs to one leg, and
    // writing it across every row would assert how money we never saw was handed
    // over. Storing it never alters the amount settled.
    $rows = $conn->prepare("SELECT COUNT(*) FROM order_payments WHERE order_id = ?");
    $rows->bind_param("i", $order_id);
    $rows->execute();
    $rowCount = (int)$rows->get_result()->fetch_row()[0];

    // Re-emitted through tender_ref() so the stored string is canonical whatever
    // the POST contained, exactly as confirm_order.php does for the checkout leg.
    $tender = tender_ref(
        (float)($_POST['cash_received']     ?? 0),
        (int)  ($_POST['cash_received_khr'] ?? 0)
    );
    if ($rowCount === 1) {
        // Bring the row up to what was actually collected. A pay-later row is
        // written for the tab's OPENING total and never updated as items are
        // added, so it understates the sale — order 1908 reads $1.34 against a
        // $19.78 order. At settlement the amount paid is the order total, so the
        // record is made to say that. Only for a single row: a split's legs are
        // per-method and already correct.
        $amt  = (float)$order['total'];
        $sync = $conn->prepare("UPDATE order_payments SET amount = ? WHERE order_id = ?");
        $sync->bind_param("di", $amt, $order_id);
        $sync->execute();

        // Gated on tender_parts(), not is_numeric(): "0.00|5500" is a valid
        // riel-only tender and is not numeric, so the old gate would have
        // written NO reference at all — silently — and the receipt would print
        // with no Received/Change lines, which is the gap this block closes.
        if (tender_parts($tender) !== null && tender_usd_total($tender) > 0) {
            $rf = $conn->prepare("UPDATE order_payments SET reference = ? WHERE order_id = ?");
            $rf->bind_param("si", $tender, $order_id);
            $rf->execute();
        }
    }

    // Award loyalty points only for Pay Later orders settled at the counter.
    // Regular orders already receive points at confirm_order.php (creation time).
    // Guard: skip if points were already credited (e.g. items added earlier
    // already awarded them via confirm_order.php), mirroring check_payment.php.
    $lc_id = (int)($order['loyalty_card_id'] ?? 0);
    if ($lc_id > 0 && ($order['payment_method'] ?? '') === 'paylater' && (int)($order['points_earned'] ?? 0) === 0) {
        // Was SUM(quantity) with no filter, which awarded points for merch and
        // for the free gift drink itself — while the same basket paid up front
        // awarded neither. loyalty_earning_qty() is the one definition.
        $qty = loyalty_earning_qty($conn, $order_id);
        if ($qty > 0) {
            loyalty_sync($conn, $lc_id, $order_id, $qty, 'Points earned from Pay Later order');
        }
    }

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    header("Location: $return_page");
    exit;
}

// Show the same success screen as the regular checkout (identical UI). Carry where the
// cashier came from so its back button returns to the queue instead of the menu.
header("Location: payment_cash.php?order_id=" . $order_id . "&from=" . $return_tab);
exit;
