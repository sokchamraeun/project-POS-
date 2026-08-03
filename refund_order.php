<?php
session_start();
require 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["ok" => 0, "error" => "Please login first"]);
    exit;
}

if (!in_array($_SESSION['role'], ['admin', 'manager'])) {
    echo json_encode(["ok" => 0, "error" => "Only admin or manager can refund orders"]);
    exit;
}

$order_id = (int)($_GET['order_id'] ?? 0);
if ($order_id <= 0) {
    echo json_encode(["ok" => 0, "error" => "Invalid order ID"]);
    exit;
}

$stmt = $conn->prepare("
    SELECT order_id, daily_order_no, customer_name, total, status,
           loyalty_card_id, points_earned, payment_method, is_open
    FROM orders WHERE order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    echo json_encode(["ok" => 0, "error" => "Order not found"]);
    exit;
}

if ($order['status'] === 'Refunded') {
    echo json_encode(["ok" => 0, "error" => "This order has already been refunded"]);
    exit;
}

/* Refundable once money has been taken or the drinks are being made.
   'Paid' was missing, which made every settled order un-refundable — 191 of them —
   while the rejection message said "Only paid or Completed orders can be refunded
   (current status: Paid)", naming the status it was refusing. The comment below
   already assumed Paid was refundable, so the list was simply wrong.
   A settled sale is exactly the case a refund exists for. */
$refundable_statuses = ['Completed', 'Preparing', 'Paid'];
if (!in_array($order['status'], $refundable_statuses)) {
    echo json_encode(["ok" => 0, "error" => "This order cannot be refunded (current status: {$order['status']})"]);
    exit;
}

/* An open pay-later tab has taken no money yet: 'Completed' there means the drinks were
   made and the customer still OWES. Refunding it would write a refund record for cash
   that was never collected and clear is_open, erasing the debt from Find Orders.
   Settlement sets status='Paid', so an unsettled tab is never refundable. */
if ($order['payment_method'] === 'paylater' && (int)$order['is_open'] === 1) {
    echo json_encode([
        "ok"    => 0,
        "error" => "This pay-later order hasn't been paid yet — there is nothing to refund. Settle it first, or cancel the order."
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["ok" => 0, "error" => "Invalid request method"]);
    exit;
}

$refund_amount  = (float)($_POST['refund_amount']  ?? 0);
$refund_reason  = trim($_POST['refund_reason']      ?? '');
$restore_stock  = 0; // Drink already made — ingredients cannot be restored on refund

if ($refund_amount <= 0 || $refund_amount > $order['total'] + 0.01) {
    echo json_encode(["ok" => 0, "error" => "Invalid refund amount. Max: $" . number_format($order['total'], 2)]);
    exit;
}

if (empty($refund_reason)) {
    echo json_encode(["ok" => 0, "error" => "Please provide a reason for refund"]);
    exit;
}

$conn->begin_transaction();

try {
    $stmt_upd = $conn->prepare("UPDATE orders SET status = 'Refunded', is_open = 0 WHERE order_id = ?");
    $stmt_upd->bind_param("i", $order_id);
    $stmt_upd->execute();
    $stmt_ref = $conn->prepare("INSERT INTO order_refunds (order_id, refund_amount, refund_reason, refunded_at, refunded_by) VALUES (?, ?, ?, NOW(), ?)");
    $stmt_ref->bind_param("idss", $order_id, $refund_amount, $refund_reason, $_SESSION['username']);
    $stmt_ref->execute();

    if ($restore_stock) {
        _restore_stock($conn, $order_id);
    }

    // ── LOYALTY: reverse points for this order (inside the transaction so it
    //    rolls back with everything else if anything fails) ──
    $card_id = (int)($order['loyalty_card_id'] ?? 0);
    if ($card_id > 0) {
        // 1) Reverse earned points: syncing to zero earning drinks undoes both points and progress
        loyalty_sync($conn, $card_id, $order_id, 0, 'Points reversed — order refunded');

        // 2) Refund points the customer SPENT redeeming rewards on this order
        $stmt_sp = $conn->prepare("SELECT COALESCE(SUM(-points_change), 0) AS spent FROM loyalty_history WHERE order_id = ? AND type = 'redeemed' AND points_change < 0");
        $stmt_sp->bind_param("i", $order_id);
        $stmt_sp->execute();
        $spent = (int)$stmt_sp->get_result()->fetch_assoc()['spent'];
        if ($spent > 0) {
            $target_card_id = loyalty_resolve_card_id($conn, $card_id);
            if ($target_card_id > 0) {
                $stmt_ref = $conn->prepare("UPDATE loyalty_cards SET points = points + ?, last_used = NOW() WHERE card_id = ?");
                $stmt_ref->bind_param("ii", $spent, $target_card_id);
                $stmt_ref->execute();
                $stmt_rh = $conn->prepare("INSERT INTO loyalty_history (card_id, order_id, points_change, type, description) VALUES (?, ?, ?, 'adjusted_add', 'Redeemed points refunded — order refunded')");
                $stmt_rh->bind_param("iii", $target_card_id, $order_id, $spent);
                $stmt_rh->execute();
            }
        }
    }

    $conn->commit();

    echo json_encode(["ok" => 1, "message" => "Order #{$order['daily_order_no']} refunded $" . number_format($refund_amount, 2) . " successfully"]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["ok" => 0, "error" => $e->getMessage()]);
}
exit;

function _restore_stock(mysqli $conn, int $order_id): void {
    $stmt_items = $conn->prepare("SELECT product_id, quantity, milk FROM order_items WHERE order_id = ?");
    $stmt_items->bind_param("i", $order_id);
    $stmt_items->execute();
    $items = $stmt_items->get_result();

    $created_by = $_SESSION['username'] ?? null;
    $ref        = "Order #$order_id";

    while ($item = $items->fetch_assoc()) {
        $product_id  = (int)$item['product_id'];
        $qty         = (int)$item['quantity'];
        $milk_choice = trim((string)$item['milk']);

        $stmt_recipe = $conn->prepare("
            SELECT pi.ingredient_id, pi.amount_used, i.ingredient_name
            FROM product_ingredients pi
            JOIN ingredients i ON i.ingredient_id = pi.ingredient_id
            WHERE pi.product_id = ?
        ");
        $stmt_recipe->bind_param("i", $product_id);
        $stmt_recipe->execute();
        $recipe = $stmt_recipe->get_result();

        while ($row = $recipe->fetch_assoc()) {
            $ing_id   = (int)$row['ingredient_id'];
            $amount   = (float)$row['amount_used'] * $qty;
            $ing_name = strtolower(trim($row['ingredient_name']));

            if (strpos($ing_name, 'milk') !== false && !empty($milk_choice)) {
                $stmt_milk = $conn->prepare("SELECT ingredient_id FROM ingredients WHERE LOWER(ingredient_name) = LOWER(?) LIMIT 1");
                $stmt_milk->bind_param("s", $milk_choice);
                $stmt_milk->execute();
                $milk_row = $stmt_milk->get_result()->fetch_assoc();
                if ($milk_row) $ing_id = (int)$milk_row['ingredient_id'];
            }

            $stmt_restore = $conn->prepare("UPDATE ingredients SET stock_quantity = stock_quantity + ? WHERE ingredient_id = ?");
            $stmt_restore->bind_param("di", $amount, $ing_id);
            $stmt_restore->execute();

            $sh = $conn->prepare("INSERT INTO ingredient_history (ingredient_id, change_type, amount, order_id, reference, created_by) VALUES (?, 'order_restore', ?, ?, ?, ?)");
            $sh->bind_param("idiss", $ing_id, $amount, $order_id, $ref, $created_by);
            $sh->execute();
        }
    }
}
