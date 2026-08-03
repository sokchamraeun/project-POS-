<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'config.php';
require __DIR__ . '/bakong-khqr-php-main/vendor/autoload.php';

use KHQR\BakongKHQR;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['paid' => false, 'error' => 'Unauthorized']);
    exit;
}

$config = require __DIR__ . '/bakong_config.php';

$order_id = (int)($_REQUEST['order_id'] ?? $_GET['order_id'] ?? $_POST['order_id'] ?? 0);

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

if (!$order) {
    echo json_encode(['paid' => false, 'error' => 'Order not found']);
    exit;
}

// Handle manual confirmation by staff/manager (allowed even if bakong_md5 is empty)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'manual_confirm') {
    try {
        _settle_bakong_order($conn, $order, $order_id);
        echo json_encode(['paid' => true, 'message' => 'Payment manually confirmed successfully.']);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['paid' => false, 'error' => 'Manual confirmation failed: ' . $e->getMessage()]);
        exit;
    }
}

if (empty($order['bakong_md5'])) {
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

    $resCode = isset($response['responseCode']) ? (int)$response['responseCode'] : null;
    $resMsg  = (string)($response['responseMessage'] ?? $response['message'] ?? '');

    $isPaid =
        ($resCode === 0)
        || (isset($response['data']) && !empty($response['data']));

    if ($isPaid) {
        try {
            _settle_bakong_order($conn, $order, $order_id);
            echo json_encode(['paid' => true]);
            exit;
        } catch (Throwable $e) {
            echo json_encode(['paid' => false, 'error' => 'Payment confirmation failed. Please try again.']);
            exit;
        }
    }

    // Check if failure is rate-limiting or token/auth issue vs normal "not paid yet"
    if ($resCode !== null && $resCode !== 0) {
        if (stripos($resMsg, 'limit') !== false || stripos($resMsg, 'quota') !== false || stripos($resMsg, 'exceeded') !== false) {
            echo json_encode([
                'paid' => false,
                'error' => 'rate_limited',
                'message' => 'Bakong daily API limit of 100 requests exceeded. Auto-verification is unavailable — please check your Bakong app.'
            ]);
            exit;
        }
        if (stripos($resMsg, 'token') !== false || stripos($resMsg, 'unauthorized') !== false || stripos($resMsg, 'auth') !== false) {
            echo json_encode([
                'paid' => false,
                'error' => 'token_expired',
                'message' => 'Bakong token error — ' . ($resMsg ?: 'cannot verify payment.')
            ]);
            exit;
        }
    }

    echo json_encode(['paid' => false]);
} catch (Throwable $e) {
    // API rejected the request (bad/expired token, auth, quota limit, or network issue)
    $msg = $e->getMessage();
    error_log('check_payment.php Bakong verify failed (order ' . $order_id . '): ' . $msg);
    $isRateLimit = stripos($msg, 'limit') !== false || stripos($msg, 'exceeded') !== false;
    echo json_encode([
        'paid' => false,
        'error' => $isRateLimit ? 'rate_limited' : 'api_error',
        'message' => $isRateLimit
            ? 'Bakong daily limit exceeded. Auto-verification is unavailable — check Bakong app.'
            : 'Could not verify payment with Bakong. Please try again or use another option.'
    ]);
}

function _settle_bakong_order(mysqli $conn, array $order, int $order_id): void {
    $conn->begin_transaction();
    try {
        // 1. Mark payment as paid in order_payments
        $stmt_payment = $conn->prepare("
            UPDATE order_payments
            SET payment_status = 'paid'
            WHERE order_id = ?
        ");
        $stmt_payment->bind_param("i", $order_id);
        $stmt_payment->execute();

        // 2. Advance order status
        if (($order['payment_method'] ?? '') === 'paylater') {
            $stmt_status = $conn->prepare("
                UPDATE orders SET status = 'Paid', is_open = 0
                WHERE order_id = ?
            ");
        } else {
            $stmt_status = $conn->prepare("
                UPDATE orders SET status = 'Preparing'
                WHERE order_id = ? AND status = 'PendingPayment'
            ");
        }
        $stmt_status->bind_param("i", $order_id);
        $stmt_status->execute();

        // Award loyalty points for paylater orders settled via Bakong (once).
        if (($order['payment_method'] ?? '') === 'paylater' && (int)($order['points_earned'] ?? 0) === 0) {
            $lc_id = (int)($order['loyalty_card_id'] ?? 0);
            if ($lc_id > 0) {
                $units = loyalty_earning_units($conn, $order_id);
                if ($units > 0) {
                    loyalty_sync($conn, $lc_id, $order_id, $units, 'Points earned from Pay Later order');
                }
            }
        }

        $conn->commit();

        // Notify Node server (non-fatal if offline)
        try {
            $data = json_encode([
                "type" => "new_order",
                "payload" => [
                    "order_id" => $order_id
                ]
            ]);

            $ch = curl_init("http://localhost:3000/notify");
            if ($ch !== false) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Content-Type: application/json",
                    "Content-Length: " . strlen($data)
                ]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 1);
                curl_exec($ch);
                curl_close($ch);
            }
        } catch (Throwable $e) {
            // Node server notification is optional
        }
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
?>