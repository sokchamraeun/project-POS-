<?php
session_start();
require 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["ok" => 0, "error" => "Please login first"]);
    exit;
}

if (!in_array($_SESSION['role'], ['admin', 'manager', 'supervisor', 'staff'])) {
    echo json_encode(["ok" => 0, "error" => "You don't have permission to cancel orders"]);
    exit;
}

$order_id = (int)($_GET['order_id'] ?? 0);
if ($order_id <= 0) {
    echo json_encode(["ok" => 0, "error" => "Invalid order ID"]);
    exit;
}

$stmt = $conn->prepare("SELECT order_id, daily_order_no, customer_name, total, status, is_open, loyalty_card_id, points_earned FROM orders WHERE order_id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    echo json_encode(["ok" => 0, "error" => "Order not found"]);
    exit;
}

// ── Only allow cancellation of non-terminal statuses ──
//
// 'Paid' belongs here: the money has been collected. Cancelling records no refund
// and no return of cash, and paid_orders_where() excludes Cancelled — so the sale
// vanishes from revenue while the cash is still in the drawer. The board makes this
// easy to hit by accident, because boardState() displays a settled 'Paid' order as
// "Completed"; the card says finished, the raw status said cancellable.
//
// The remedy for a paid order is a REFUND, which leaves a record of the money going
// back. Cancel is for orders where nothing was collected.
$non_cancellable = ['Cancelled', 'Refunded', 'Completed', 'Paid'];
if (in_array($order['status'], $non_cancellable)) {
    $why = $order['status'] === 'Paid'
        ? "This order has already been paid. Refund it instead — cancelling would leave the money unaccounted for."
        : "Cannot cancel an order with status: {$order['status']}";
    echo json_encode(["ok" => 0, "error" => $why]);
    exit;
}

// ── Only admin can cancel Paid/Completed orders ──
if ($order['status'] === 'Preparing' && $order['is_open'] == 0 && !in_array($_SESSION['role'], ['admin', 'manager'])) {
    echo json_encode(["ok" => 0, "error" => "Only admin or manager can cancel orders already being prepared"]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["ok" => 0, "error" => "Invalid request method"]);
    exit;
}

$reason        = trim($_POST['cancel_reason'] ?? '');
$restore_stock = isset($_POST['restore_stock']) ? 1 : 0;

if (empty($reason)) {
    echo json_encode(["ok" => 0, "error" => "Please provide a reason for cancellation"]);
    exit;
}

$conn->begin_transaction();

try {
    $stmt_upd = $conn->prepare("UPDATE orders SET status = 'Cancelled', is_open = 0 WHERE order_id = ?");
    $stmt_upd->bind_param("i", $order_id);
    $stmt_upd->execute();
    $stmt_can = $conn->prepare("INSERT INTO order_cancellations (order_id, cancel_reason, cancelled_at, cancelled_by) VALUES (?, ?, NOW(), ?)");
    $stmt_can->bind_param("iss", $order_id, $reason, $_SESSION['username']);
    $stmt_can->execute();

    if ($restore_stock) {
        _restore_stock($conn, $order_id);
    }

    // ── LOYALTY: reverse points for this order (inside the transaction so it
    //    rolls back with everything else if anything fails) ──
    $card_id    = (int)($order['loyalty_card_id'] ?? 0);
    $pts_earned = (int)($order['points_earned']   ?? 0);
    if ($card_id > 0) {
        // 1) Reverse the points EARNED on this order.
        //    Syncing to zero earning drinks undoes both the points and the
        //    progress, restoring the card to exactly where it stood before this
        //    order — including any part-progress toward the next point, which a
        //    points-only claw-back would silently take from the customer.
        loyalty_sync($conn, $card_id, $order_id, 0, 'Points reversed — order cancelled');

        // 2) Refund points the customer SPENT redeeming rewards on this order
        //    (type='redeemed' rows are real reward redemptions; edit-sync uses adjusted_*)
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
                $stmt_rh = $conn->prepare("INSERT INTO loyalty_history (card_id, order_id, points_change, type, description) VALUES (?, ?, ?, 'adjusted_add', 'Redeemed points refunded — order cancelled')");
                $stmt_rh->bind_param("iii", $target_card_id, $order_id, $spent);
                $stmt_rh->execute();
            }
        }
    }

    $conn->commit();

    echo json_encode(["ok" => 1, "message" => "Order #{$order['daily_order_no']} cancelled successfully"]);

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
        $product_id = (int)$item['product_id'];
        $qty        = (int)$item['quantity'];
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
