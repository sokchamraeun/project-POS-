<?php
session_start();
require 'config.php';
require __DIR__ . '/bakong-khqr-php-main/vendor/autoload.php';

use KHQR\BakongKHQR;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['paid' => false, 'error' => 'Unauthorized']);
    exit;
}

$config = require __DIR__ . '/bakong_config.php';

$order_id = (int)($_GET['order_id'] ?? 0);

if ($order_id <= 0) {
    echo json_encode(['paid' => false, 'error' => 'Invalid order id']);
    exit;
}

$stmt = $conn->prepare("
    SELECT o.order_id, o.status, o.is_open, o.bakong_md5, o.payment_method, o.loyalty_card_id, o.points_earned,
           op.payment_id, op.payment_status
    FROM orders o
    LEFT JOIN order_payments op ON o.order_id = op.order_id AND op.payment_method = 'bakong'
    WHERE o.order_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order || empty($order['bakong_md5'])) {
    echo json_encode(['paid' => false]);
    exit;
}

// If Bakong already marked as paid, return true
if ($order['payment_status'] === 'paid') {
    echo json_encode(['paid' => true]);
    exit;
}

/* Short-circuit for an order that is genuinely settled already.
   'Completed' means DIFFERENT things depending on how the order is paid:
     - cash / bakong at checkout → Completed = done, money taken.
     - pay-later               → Completed = drinks MADE and the customer still OWES
                                 (is_open stays 1). Settlement sets 'Paid', is_open=0.
   Treating Completed as paid for a pay-later tab reported "Payment Successful" for
   money that was never collected, without ever asking Bakong — the cashier would let
   the customer walk and the tab would silently stay open.
   'Preparing' is excluded for the same reason: it is not a settled state. */
$is_paylater = ($order['payment_method'] ?? '') === 'paylater';
$already_settled = $is_paylater
    ? ($order['status'] === 'Paid' && (int)$order['is_open'] === 0)
    : ($order['status'] === 'Completed');

if ($already_settled) {
    echo json_encode(['paid' => true]);
    exit;
}

// ── Bakong API check (no manual override — payments must be verified by Bakong) ──
try {
    // Guard: catch an expired/invalid token up front so we report it clearly
    // instead of silently spinning on "Waiting for payment..." forever.
    try {
        $tokenExp = \KHQR\Helpers\Utils::getExpirationDateFromJwtPayload($config['token']);
    } catch (Throwable $e) {
        $tokenExp = null; // malformed/placeholder token — let the API call surface it
    }
    if ($tokenExp !== null && $tokenExp < time()) {
        echo json_encode([
            'paid' => false,
            'error' => 'token_expired',
            'message' => 'Bakong token expired — payments cannot be verified until it is renewed.'
        ]);
        exit;
    }

    $bakong = new BakongKHQR($config['token']);
    $response = $bakong->checkTransactionByMD5($order['bakong_md5']);

    $isPaid =
        (isset($response['responseCode']) && (int)$response['responseCode'] === 0)
        || (isset($response['data']) && !empty($response['data']));

    if ($isPaid) {
        $conn->begin_transaction();

        try {
            // 1. Mark Bakong payment as paid in order_payments
            $stmt_payment = $conn->prepare("
                UPDATE order_payments
                SET payment_status = 'paid'
                WHERE order_id = ? AND payment_method = 'bakong'
            ");
            $stmt_payment->bind_param("i", $order_id);
            $stmt_payment->execute();

            // 2. Check if all payments for this order are now paid
            $stmt_check = $conn->prepare("
                SELECT COUNT(*) AS pending_count
                FROM order_payments
                WHERE order_id = ? AND payment_status != 'paid'
            ");
            $stmt_check->bind_param("i", $order_id);
            $stmt_check->execute();
            $result = $stmt_check->get_result();
            $pending = $result->fetch_assoc();

            // 3. If no pending payments, advance order status
            if ($pending['pending_count'] == 0) {
                if ($order['payment_method'] === 'paylater') {
                    // Paylater settled via Bakong → 'Paid' + closed. 'Paid' is the SETTLED
                    // state; find_order.php treats paylater orders on 'Completed' as still
                    // owing, so this must not be changed to preserve fulfilment state.
                    $stmt_status = $conn->prepare("
                        UPDATE orders SET status = 'Paid', is_open = 0
                        WHERE order_id = ?
                    ");
                } else {
                    // Regular Bakong order → move to Preparing (kitchen view)
                    $stmt_status = $conn->prepare("
                        UPDATE orders SET status = 'Preparing'
                        WHERE order_id = ? AND status = 'PendingPayment'
                    ");
                }
                $stmt_status->bind_param("i", $order_id);
                $stmt_status->execute();

                // Award loyalty points for paylater orders settled via Bakong (once).
                if ($order['payment_method'] === 'paylater' && (int)($order['points_earned'] ?? 0) === 0) {
                    $lc_id = (int)($order['loyalty_card_id'] ?? 0);
                    if ($lc_id > 0) {
                        // Was SUM(quantity) with no filter — see admin_pay_cash.php.
                        $qty = loyalty_earning_qty($conn, $order_id);
                        if ($qty > 0) {
                            loyalty_sync($conn, $lc_id, $order_id, $qty, 'Points earned from Pay Later order');
                        }
                    }
                }
            }

            $conn->commit();

            // Notify Node server
            $data = json_encode([
                "type" => "new_order",
                "payload" => [
                    "order_id" => $order_id
                ]
            ]);

            $ch = curl_init("http://localhost:3000/notify");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "Content-Length: " . strlen($data)
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_exec($ch);
            curl_close($ch);

            echo json_encode(['paid' => true]);
            exit;

        } catch (Throwable $e) {
            $conn->rollback();
            echo json_encode(['paid' => false, 'error' => 'Payment confirmation failed. Please try again.']);
            exit;
        }
    }

    echo json_encode(['paid' => false]);
} catch (Throwable $e) {
    // API rejected the request (bad/expired token, auth or network issue) —
    // surface that it failed, but keep the raw detail server-side only.
    error_log('check_payment.php Bakong verify failed (order ' . $order_id . '): ' . $e->getMessage());
    echo json_encode([
        'paid' => false,
        'error' => 'api_error',
        'message' => 'Could not verify payment with Bakong. Please try again or use another option.'
    ]);
}
?>