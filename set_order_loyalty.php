<?php
require 'auth.php';
if (!in_array($_SESSION['role'], ['admin', 'manager', 'staff'])) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
header('Content-Type: application/json');

$order_id   = (int)($_POST['order_id'] ?? 0);
$loyalty_id = trim($_POST['loyalty_id'] ?? '');

if ($order_id <= 0 || empty($loyalty_id)) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

$card = getLoyaltyCard($conn, $loyalty_id);
if (!$card) {
    echo json_encode(['success' => false, 'message' => 'Card not found or inactive']);
    exit;
}

$card_id = (int)$card['card_id'];

/* Check eligibility by reading the order, NOT by the UPDATE's affected_rows.
   MySQL reports 0 affected rows when a column is set to the value it already
   holds, so re-scanning the card already on the order looked identical to
   "order not found" and returned that error while nothing was wrong. */
$look = $conn->prepare("SELECT status, is_open, payment_method, loyalty_card_id
                        FROM orders WHERE order_id = ? LIMIT 1");
$look->bind_param("i", $order_id);
$look->execute();
$order = $look->get_result()->fetch_assoc();

if (!$order) {
    echo json_encode(['success' => false, 'message' => 'Order not found']);
    exit;
}
if ($order['payment_method'] !== 'paylater') {
    echo json_encode(['success' => false, 'message' => 'Only pay-later tabs can have a card linked here']);
    exit;
}
/* Linkable while the tab is still OPEN and unsettled. 'Completed' on a pay-later
   order means the drinks were made and the customer still owes — points are only
   awarded at settlement (admin_pay_cash.php), so a card added at that point still
   counts. Restricting this to 'Preparing' shut out that whole window.
   Once the tab is settled the points are already booked, so linking is refused. */
$linkable = ((int)$order['is_open'] === 1)
         && in_array($order['status'], ['Preparing', 'Completed'], true);
if (!$linkable) {
    $why = ((int)$order['is_open'] === 0)
        ? 'This tab is already settled — the points have been awarded, so a card cannot be added now.'
        : 'This order is no longer editable (status: ' . $order['status'] . ')';
    echo json_encode(['success' => false, 'message' => $why]);
    exit;
}

// Already the linked card: report success rather than an error. Scanning the same
// card twice is a normal thing for a cashier to do and changes nothing.
if ((int)$order['loyalty_card_id'] !== $card_id) {
    $stmt = $conn->prepare("UPDATE orders SET loyalty_card_id = ? WHERE order_id = ?");
    $stmt->bind_param("ii", $card_id, $order_id);
    $stmt->execute();
}

echo json_encode([
    'success'    => true,
    'card_id'    => $card_id,
    'loyalty_id' => $card['loyalty_id'],
    'points'     => (int)$card['points'],
]);
