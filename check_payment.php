<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'config.php';
if (file_exists(__DIR__ . '/bakong-khqr-php-main/autoload.php')) {
    require_once __DIR__ . '/bakong-khqr-php-main/autoload.php';
} elseif (file_exists(__DIR__ . '/bakong-khqr-php-main/vendor/autoload.php')) {
    require_once __DIR__ . '/bakong-khqr-php-main/vendor/autoload.php';
}

use KHQR\BakongKHQR;

header('Content-Type: application/json');

/**
 * Marks order and payment records as paid, and optionally notifies local Node service.
 */
function _settle_bakong_order(mysqli $conn, array $order, int $order_id): void {
    $conn->begin_transaction();
    try {
        // Mark payment as paid in order_payments
        $stmt_payment = $conn->prepare("
            UPDATE order_payments
            SET payment_status = 'paid'
            WHERE order_id = ? AND payment_method = 'bakong'
        ");
        if ($stmt_payment) {
            $stmt_payment->bind_param("i", $order_id);
            $stmt_payment->execute();
        }

        $conn->commit();

        // Optional local kitchen/display notification
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
            }
        } catch (Throwable $e) {
            // Non-fatal
        }
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

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
    SELECT o.order_id, o.payment_method,
           op.payment_id, op.payment_status, op.reference AS bakong_md5
    FROM orders o
    LEFT JOIN order_payments op ON o.order_id = op.order_id AND op.payment_method = 'bakong'
    WHERE o.order_id = ?
    LIMIT 1
");
if (!$stmt) {
    echo json_encode(['paid' => false, 'error' => 'Database error']);
    exit;
}
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    echo json_encode(['paid' => false, 'error' => 'Order not found']);
    exit;
}

// ── 1. Handle manual confirmation by cashier/staff ──
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'manual_confirm') {
    try {
        _settle_bakong_order($conn, $order, $order_id);
        echo json_encode(['paid' => true, 'message' => 'Payment manually confirmed successfully.']);
        exit;
    } catch (Throwable $e) {
        error_log("check_payment.php: Manual confirmation failed for order #{$order_id}: " . $e->getMessage());
        echo json_encode(['paid' => false, 'error' => 'Manual confirmation failed: ' . $e->getMessage()]);
        exit;
    }
}

// ── 2. If already marked as paid in DB, return paid immediately ──
if (($order['payment_status'] ?? '') === 'paid') {
    echo json_encode(['paid' => true]);
    exit;
}

if (empty($order['bakong_md5'])) {
    echo json_encode(['paid' => false]);
    exit;
}

// ── 3. Check Bakong NBC Open API for MD5 confirmation ──
try {
    try {
        $tokenExp = \KHQR\Helpers\Utils::getExpirationDateFromJwtPayload($config['token']);
    } catch (Throwable $e) {
        $tokenExp = null;
    }
    if ($tokenExp !== null && $tokenExp < time()) {
        echo json_encode([
            'paid' => false,
            'error' => 'token_expired',
            'message' => 'Bakong token expired — payments cannot be verified automatically until it is renewed.'
        ]);
        exit;
    }

    $bakong = new BakongKHQR($config['token']);
    $response = $bakong->checkTransactionByMD5($order['bakong_md5']);

    $resCode = isset($response['responseCode']) ? (int)$response['responseCode'] : null;
    $resMsg  = (string)($response['responseMessage'] ?? $response['message'] ?? '');
    $errCode = isset($response['errorCode']) ? (int)$response['errorCode'] : null;

    $isPaid =
        ($resCode === 0)
        || (isset($response['data']) && !empty($response['data']));

    if ($isPaid) {
        error_log("check_payment.php: Order #{$order_id} verified PAID by Bakong API! MD5: {$order['bakong_md5']}");
        try {
            _settle_bakong_order($conn, $order, $order_id);
            echo json_encode(['paid' => true, 'data' => $response['data'] ?? null]);
            exit;
        } catch (Throwable $e) {
            error_log("check_payment.php: Settle failed for order #{$order_id}: " . $e->getMessage());
            echo json_encode(['paid' => false, 'error' => 'Payment confirmation failed: ' . $e->getMessage()]);
            exit;
        }
    }

    // Handle rate-limiting (e.g. 100 requests per day exceeded)
    if ($errCode === 17 || stripos($resMsg, 'limit') !== false || stripos($resMsg, 'quota') !== false || stripos($resMsg, 'exceeded') !== false) {
        echo json_encode([
            'paid' => false,
            'error' => 'rate_limited',
            'message' => 'Bakong daily API limit reached. Click Confirm Payment below.'
        ]);
        exit;
    }

    if (stripos($resMsg, 'token') !== false || stripos($resMsg, 'unauthorized') !== false || stripos($resMsg, 'auth') !== false) {
        echo json_encode([
            'paid' => false,
            'error' => 'token_expired',
            'message' => 'Bakong token error: ' . ($resMsg ?: 'cannot verify payment.')
        ]);
        exit;
    }

    echo json_encode(['paid' => false]);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    error_log('check_payment.php Bakong verify failed (order ' . $order_id . '): ' . $msg);
    $isRateLimit = stripos($msg, 'limit') !== false || stripos($msg, 'exceeded') !== false;
    echo json_encode([
        'paid' => false,
        'error' => $isRateLimit ? 'rate_limited' : 'api_error',
        'message' => $isRateLimit
            ? 'Bakong daily limit exceeded. Click Confirm Payment below.'
            : 'Could not verify payment with Bakong. Please click Confirm Payment.'
    ]);
}