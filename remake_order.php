<?php
session_start();
require 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["ok" => 0, "error" => "Please login first"]);
    exit;
}

if (!in_array($_SESSION['role'], ['admin', 'manager', 'staff'])) {
    echo json_encode(["ok" => 0, "error" => "Only admin, manager or cashier can log a remake"]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["ok" => 0, "error" => "Invalid request method"]);
    exit;
}

$order_id = (int)($_GET['order_id'] ?? 0);
if ($order_id <= 0) {
    echo json_encode(["ok" => 0, "error" => "Invalid order ID"]);
    exit;
}

$reason = trim($_POST['reason'] ?? '');
if (empty($reason)) {
    echo json_encode(["ok" => 0, "error" => "Please provide a reason for the remake"]);
    exit;
}

$stmt = $conn->prepare("SELECT order_id, order_id AS daily_order_no, 'Completed' AS status FROM orders WHERE order_id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    echo json_encode(["ok" => 0, "error" => "Order not found"]);
    exit;
}

/* Which drinks were actually re-poured, and how many of each.
   Deducting the whole order would invent consumption for drinks nobody remade —
   on a four-drink order where one was too sweet that is three phantom drinks.
   Deducting nothing, the old behaviour, silently lost the ingredients that really
   were used: the barista pours a second cup and stock says it never happened. */
$items_raw = trim($_POST['items'] ?? '');
$items     = json_decode($items_raw, true);
if (!is_array($items) || !$items) {
    echo json_encode(["ok" => 0, "error" => "Select at least one drink to remake"]);
    exit;
}

// This order's own lines, so an item_id from another order cannot be claimed.
$lines = [];
$lq = $conn->prepare("SELECT item_id, product_id, quantity, size_label, milk
                      FROM order_items WHERE order_id = ?");
$lq->bind_param("i", $order_id);
$lq->execute();
$lr = $lq->get_result();
while ($row = $lr->fetch_assoc()) { $lines[(int)$row['item_id']] = $row; }

$remake = [];
foreach ($items as $it) {
    $iid = (int)($it['item_id'] ?? 0);
    $q   = (int)($it['qty'] ?? 0);
    // Refused outright rather than clamped: a silent clamp hides a broken client
    // and records a quantity nobody chose.
    if (!isset($lines[$iid]) || $q < 1 || $q > (int)$lines[$iid]['quantity']) {
        echo json_encode(["ok" => 0, "error" => "That drink is not on this order, or the quantity is wrong"]);
        exit;
    }
    $remake[$iid] = $q;
}

/* One transaction from here to the status flip. Stock is about to move, and a
   remake whose ingredients were deducted but which was never logged — or logged
   without the stock moving — is worse than one that was refused outright. */
$conn->begin_transaction();
try {

// Throw rather than exit: an exit inside the transaction would skip the rollback
// below and leave the failure half-applied.


// Apply item adjustments if provided
$adjusted_milk   = [];   // item_id => the milk chosen for this remake
$adjustments_raw = trim($_POST['adjustments'] ?? '');
if ($adjustments_raw) {
    $adjustments = json_decode($adjustments_raw, true);
    if (is_array($adjustments)) {
        // Allowed add-ons per product (removed)
        $allowed = [];

        // product_id per item, so an adjustment can't be validated against another drink.
        $item_product = [];
        $ipq = $conn->prepare("SELECT item_id, product_id FROM order_items WHERE order_id = ?");
        $ipq->bind_param("i", $order_id);
        $ipq->execute();
        $ipr = $ipq->get_result();
        while ($row = $ipr->fetch_assoc()) $item_product[(int)$row['item_id']] = (int)$row['product_id'];

        $stmt_adj = $conn->prepare(
            "UPDATE order_items SET sweetness=?, ice=?, milk=?, addons_snapshot=? WHERE item_id=? AND order_id=?"
        );
        $adj_sw = $adj_ic = $adj_ml = $adj_snap = '';
        $adj_item_id = 0;
        $stmt_adj->bind_param("ssssii", $adj_sw, $adj_ic, $adj_ml, $adj_snap, $adj_item_id, $order_id);
        foreach ($adjustments as $adj) {
            $adj_item_id = (int)($adj['item_id'] ?? 0);
            if ($adj_item_id <= 0) continue;
            // Rewriting the options of a drink nobody re-poured would falsify
            // history — only remade drinks are adjustable.
            if (!isset($remake[$adj_item_id])) continue;
            $adj_sw = trim($adj['sweetness'] ?? '');
            $adj_ic = trim($adj['ice'] ?? '');
            $adj_ml = trim($adj['milk'] ?? '');
            // The deduction below must use the milk being poured now, not the one
            // originally ordered.
            $adjusted_milk[$adj_item_id] = $adj_ml;

            /* Same shape and ordering add_to_cart.php writes: [{id, name, price}, ...] in
               display_order. Shape matters — confirm_order.php compares addons_snapshot as
               a string when merging identical drinks, so a different key set would stop
               a re-ordered drink from matching.
               order_items.price is deliberately NOT recomputed: a remake is service
               recovery, so the customer is never re-billed for an add-on change. */
            $pid   = $item_product[$adj_item_id] ?? 0;
            $pool  = $allowed[$pid] ?? [];
            $want  = array_flip(array_map('strval', (array)($adj['addons'] ?? [])));
            $snap  = [];
            foreach ($pool as $name => $meta) {          // $pool is already in display_order
                if (isset($want[$name])) $snap[] = $meta;
            }
            $adj_snap = json_encode($snap);
            $stmt_adj->execute();
        }
    }
}

/* ── STOCK ──
   A remade drink is physically poured a second time: milk, syrup, tea and a cup
   leave the building. Logging the remake without deducting is the phantom-stock
   failure the partial-receiving work removed, arriving through another door —
   recipes deduct against ingredients that are not there, the low-stock alert never
   fires, and it surfaces weeks later as an untraceable shortage. */
$shortfalls = [];
foreach ($remake as $iid => $q) {
    $line = $lines[$iid];
    $pid  = (int)$line['product_id'];

    $sf = 1.0;

    // The milk being poured now, which the adjustments above may have just changed.
    $milk = (string)($adjusted_milk[$iid] ?? $line['milk'] ?? '');

    $shortfalls = array_merge(
        $shortfalls,
        _deduct_stock($conn, $pid, $q, $milk, $order_id, $sf,
                      "Remake of order #" . $order['daily_order_no'])
    );

    /* Decrement rather than zero: on a line of three where one was wrong, the other
       two are still made. made_at clears only when nothing on the line remains made,
       otherwise the barista sees drinks reappear that nobody re-poured. */
    $mu = $conn->prepare("UPDATE order_items
                          SET made_at  = CASE WHEN GREATEST(0, made_qty - ?) = 0
                                              THEN NULL ELSE made_at END,
                              made_qty = GREATEST(0, made_qty - ?)
                          WHERE item_id = ? AND order_id = ?");
    $mu->bind_param("iiii", $q, $q, $iid, $order_id);
    $mu->execute();
}

$stmt3 = $conn->prepare("UPDATE orders SET status='Preparing' WHERE order_id=?");
$stmt3->bind_param("i", $order_id);
$stmt3->execute();

$conn->commit();

echo json_encode([
    "ok"         => 1,
    "message"    => "Order #" . $order['daily_order_no'] . " sent back to Preparing",
    // Same warning the ordering flow gives. The remake is recorded either way —
    // the drink has already been poured — but the shop is short.
    "shortfalls" => $shortfalls,
]);
exit;

} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode(["ok" => 0, "error" => $e->getMessage() ?: "The remake could not be saved. Nothing was changed."]);
    exit;
}
