<?php
require 'config.php';
require 'auth.php';
require __DIR__ . '/bakong-khqr-php-main/vendor/autoload.php';

use KHQR\BakongKHQR;
use KHQR\Models\IndividualInfo;

$config = require __DIR__ . '/bakong_config.php';

$order_id = (int)($_GET['order_id'] ?? 0);

if ($order_id <= 0) {
    die('Invalid order.');
}

// ── Try QR Again: clear md5 so a fresh QR is generated ──
if (($_GET['action'] ?? '') === 'refresh') {
    $s = $conn->prepare("UPDATE orders SET bakong_md5 = NULL WHERE order_id = ?");
    $s->bind_param("i", $order_id);
    $s->execute();
    header("Location: payment.php?order_id=$order_id");
    exit;
}

// ── Switch to Cash: swap bakong → cash, move order to Preparing ──
if (($_GET['action'] ?? '') === 'switch_cash') {
    $conn->begin_transaction();
    try {
        $conn->query("DELETE FROM order_payments WHERE order_id=$order_id AND payment_method='bakong'");
        $s = $conn->prepare("
            INSERT INTO order_payments (order_id, payment_method, amount, payment_status)
            SELECT ?, 'cash', total, 'paid' FROM orders WHERE order_id = ?
            ON DUPLICATE KEY UPDATE payment_method='cash', payment_status='paid'
        ");
        $s->bind_param("ii", $order_id, $order_id);
        $s->execute();
        $conn->query("UPDATE orders SET status='Preparing', payment_method='cash', bakong_md5=NULL WHERE order_id=$order_id");
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
    }
    header("Location: payment_cash.php?order_id=$order_id");
    exit;
}

// ── Get order and payment data ──
$stmt = $conn->prepare("
    SELECT o.order_id, o.customer_name, o.total, o.status, o.bakong_md5, o.daily_order_no,
           op.payment_id, op.amount AS bakong_amount, op.payment_status,
           (SELECT COUNT(*) FROM order_payments WHERE order_id = o.order_id AND payment_method = 'cash' AND payment_status = 'pending') AS has_cash_pending,
           (SELECT COUNT(*) FROM order_payments WHERE order_id = o.order_id) AS total_payment_methods
    FROM orders o
    LEFT JOIN order_payments op ON o.order_id = op.order_id AND op.payment_method = 'bakong'
    WHERE o.order_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    die('Order not found.');
}

// If Bakong is already paid, redirect to menu
if ($order['payment_status'] === 'paid') {
    header("Location: menu.php?msg=Bakong+paid");
    exit;
}

// ── Get Bakong payment amount from order_payments ──
$bakong_amount = (float)($order['bakong_amount'] ?? 0);

// If no bakong payment record found, use total (fallback)
if ($bakong_amount <= 0) {
    $bakong_amount = (float)$order['total'];
}

$khr_bakong = (int)(round($bakong_amount * KHR_RATE / 100) * 100);

// --- CALCULATE TAX FOR DISPLAY PURPOSES ---
$total = (float)$order['total'];
$tax_rate = TAX_RATE / 100;
$subtotal = round($total / (1 + $tax_rate), 2);
$tax_amount = round($total - $subtotal, 2);

/*
 |------------------------------------------------------------
 | Generate KHQR for BAKONG AMOUNT only (not full total)
 |------------------------------------------------------------
*/
$qrString = '';
$md5 = $order['bakong_md5'] ?? null;

if (empty($md5)) {
    $individualInfo = new IndividualInfo(
        bakongAccountID: $config['bakong_id'],
        merchantName: $config['merchant_name'],
        merchantCity: $config['merchant_city'],
        currency: $config['currency'],
        amount: $bakong_amount,
        billNumber: 'ORDER_' . $order_id,
        storeLabel: 'ObsidianCafe',
        terminalLabel: 'POS1',
        mobileNumber: $config['mobile_number'],
        expirationTimestamp: strval((time() + 15 * 60) * 1000)
    );

    $khqrResponse = BakongKHQR::generateIndividual($individualInfo);

    if (($khqrResponse->status['code'] ?? 1) !== 0 || empty($khqrResponse->data['qr']) || empty($khqrResponse->data['md5'])) {
        echo '<h2 style="color:red;text-align:center;">Failed to generate KHQR.</h2>';
        exit;
    }

    $qrString = $khqrResponse->data['qr'];
    $md5 = $khqrResponse->data['md5'];

    $stmt = $conn->prepare("UPDATE orders SET bakong_md5 = ? WHERE order_id = ?");
    $stmt->bind_param("si", $md5, $order_id);
    $stmt->execute();
} else {
    $individualInfo = new IndividualInfo(
        bakongAccountID: $config['bakong_id'],
        merchantName: $config['merchant_name'],
        merchantCity: $config['merchant_city'],
        currency: $config['currency'],
        amount: $bakong_amount,
        billNumber: 'ORDER_' . $order_id,
        storeLabel: 'ObsidianCafe',
        terminalLabel: 'POS1',
        mobileNumber: $config['mobile_number'],
        expirationTimestamp: strval((time() + 15 * 60) * 1000)
    );

    $khqrResponse = BakongKHQR::generateIndividual($individualInfo);
    $qrString = $khqrResponse->data['qr'] ?? '';
    // A fresh KHQR carries a NEW md5; keep the stored value in sync with the QR
    // actually displayed, or check_payment.php would verify a stale md5 and never
    // detect the payment on a reload / return visit.
    $newMd5 = $khqrResponse->data['md5'] ?? '';
    if (!empty($newMd5) && $newMd5 !== $md5) {
        $md5 = $newMd5;
        $stmt = $conn->prepare("UPDATE orders SET bakong_md5 = ? WHERE order_id = ?");
        $stmt->bind_param("si", $md5, $order_id);
        $stmt->execute();
    }
}

$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($qrString);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>(function(){if(localStorage.getItem('theme')==='dark'){document.documentElement.setAttribute('data-theme','dark');}})();</script>
    <title>Bakong KHQR Payment | Bird's Nest Coffee</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ── RESET & ROOT ── */
        :root {
            --bg-body: #f2ede8;
            --bg-card: #fffdf9;
            --bg-card-hover: #fdf7f0;
            --border: #e0d5c8;
            --border-hover: #c9b89f;
            --orange: #d1904b;
            --orange-dark: #a0702a;
            --orange-light: #e8b87a;
            --text-primary: #1a1410;
            --text-secondary: #5a4a3a;
            --text-muted: #9a8070;
            --text-inverse: #ffffff;
            --success: #55e087;
            --shadow-sm: 0 2px 10px rgba(90,60,20,0.07);
            --shadow-md: 0 6px 28px rgba(90,60,20,0.11);
            --shadow-lg: 0 12px 48px rgba(90,60,20,0.16);
            --shadow-orange-sm: 0 4px 16px rgba(209,144,75,0.22);
            --shadow-orange-md: 0 8px 32px rgba(209,144,75,0.28);
            --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
            --radius: 16px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: var(--bg-body);
            color: var(--text-primary);
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background-image:
                radial-gradient(ellipse at 20% 10%, rgba(209,144,75,0.06) 0%, transparent 60%),
                radial-gradient(ellipse at 80% 90%, rgba(209,144,75,0.04) 0%, transparent 60%);
        }

        /* ── Card Container ── */
        .payment-card {
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 32px 36px;
            max-width: 480px;
            width: 100%;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            animation: cardFadeIn 0.7s ease both;
            transition: var(--transition);
        }

        .payment-card:hover {
            border-color: var(--border-hover);
            box-shadow: var(--shadow-lg);
        }

        @keyframes cardFadeIn {
            from { opacity: 0; transform: translateY(30px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .payment-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--orange), var(--orange-light), var(--orange));
            background-size: 200% 100%;
            animation: shimmer 3s ease-in-out infinite;
        }

        @keyframes shimmer {
            0%, 100% { background-position: 0% 0%; }
            50% { background-position: 100% 0%; }
        }

        /* ── Header ── */
        .header {
            text-align: center;
            margin-bottom: 16px;
            animation: fadeInUp 0.7s ease 0.1s both;
        }

        .header .icon-wrapper {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: rgba(209,144,75,0.12);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            border: 1px solid rgba(209,144,75,0.15);
            transition: var(--transition);
        }

        .header .icon-wrapper i {
            font-size: 28px;
            color: var(--orange-light);
        }

        .header h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 2px;
            letter-spacing: -0.5px;
        }

        .header h1 span {
            background: linear-gradient(135deg, var(--orange-light), var(--orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .header p {
            color: var(--text-secondary);
            font-size: 13px;
        }

        /* ── Info Grid ── */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            background: rgba(255,255,255,0.03);
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 10px;
            border: 1px solid var(--border);
            animation: fadeInUp 0.7s ease 0.2s both;
            transition: var(--transition);
        }

        .info-grid:hover {
            border-color: var(--border-hover);
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-item .label {
            font-size: 10px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .info-item .value {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            margin-top: 2px;
        }

        .info-item .value.highlight {
            color: var(--orange);
        }

        /* ── Amount Breakdown ── */
        .amount-breakdown {
            background: rgba(255,255,255,0.03);
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 10px;
            border: 1px solid var(--border);
            animation: fadeInUp 0.7s ease 0.3s both;
            transition: var(--transition);
        }

        .amount-breakdown:hover {
            border-color: var(--border-hover);
        }

        .breakdown-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            color: var(--text-secondary);
            font-size: 13px;
        }

        .breakdown-row.discount {
            color: #e74c3c;
        }

        .breakdown-row.total {
            border-top: 1px solid var(--border);
            margin-top: 4px;
            padding-top: 10px;
            color: var(--text-primary);
            font-size: 16px;
            font-weight: 700;
        }

        .breakdown-row.total span:last-child {
            color: var(--orange);
        }

        .breakdown-row.bakong-amount {
            border-top: 1px solid var(--border);
            margin-top: 4px;
            padding-top: 8px;
            color: var(--orange);
            font-weight: 600;
        }

        /* ── QR Section ── */
        .qr-section {
            display: flex;
            justify-content: center;
            margin-top: 10px;
            animation: fadeInUp 0.7s ease 0.4s both;
        }

        .qr-box {
            background: #ffffff;
            display: inline-block;
            padding: 12px;
            border-radius: 12px;
            box-shadow: var(--shadow-orange-sm);
            position: relative;
            transition: var(--transition);
        }

        .qr-box:hover {
            transform: scale(1.02);
            box-shadow: var(--shadow-orange-md);
        }

        .qr-box img {
            display: block;
            width: 220px;
            height: 220px;
            border-radius: 8px;
        }

        .qr-box .expiry-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--orange);
            color: var(--text-inverse);
            font-size: 10px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 20px;
            box-shadow: var(--shadow-orange-sm);
        }

        /* ── Status ── */
        .status-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
            max-width: 420px;
            margin: 14px auto 0;
            animation: fadeInUp 0.7s ease 0.5s both;
        }

        .verify-error-card {
            width: 100%;
            background: rgba(239, 68, 68, 0.08);
            border: 1px solid rgba(239, 68, 68, 0.25);
            border-radius: 14px;
            padding: 16px 18px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.25);
            animation: fadeInUp 0.3s ease both;
        }
        .verify-error-title {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: #ef4444;
            font-size: 13.5px;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .verify-error-title i {
            font-size: 15px;
        }
        .verify-error-msg {
            color: var(--text, #f8fafc);
            font-size: 12.5px;
            line-height: 1.5;
            margin-bottom: 12px;
        }
        .btn-manual-confirm {
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 18px;
            border-radius: 10px;
            border: none;
            background: linear-gradient(135deg, #d1904b, #e8b87a);
            color: #000;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 16px rgba(209, 144, 75, 0.25);
            transition: all 0.2s cubic-bezier(.4,0,.2,1);
            margin-bottom: 8px;
        }
        .btn-manual-confirm:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(209, 144, 75, 0.38);
            filter: brightness(1.06);
        }
        .btn-manual-confirm:active {
            transform: translateY(0);
        }
        .btn-manual-confirm:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        .verify-error-hint {
            font-size: 11.5px;
            color: var(--text-muted, #888);
            margin-top: 4px;
        }

        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 18px;
            border-radius: 50px;
            background: rgba(209,144,75,0.08);
            color: var(--orange);
            border: 1px solid rgba(209,144,75,0.15);
            font-size: 12px;
            font-weight: 500;
            transition: var(--transition);
        }

        .status-indicator:hover {
            background: rgba(209,144,75,0.15);
            border-color: var(--orange);
        }

        .status-indicator .spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(209,144,75,0.2);
            border-top-color: var(--orange);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .status-indicator.success {
            background: rgba(85,224,135,0.08);
            border-color: rgba(85,224,135,0.15);
            color: var(--success);
        }

        .status-indicator.success .spinner {
            border-color: rgba(85,224,135,0.2);
            border-top-color: var(--success);
        }

        /* ── Success Check ── */
        .success-check {
            display: none;
            justify-content: center;
            margin: 10px 0;
            animation: fadeInUp 0.5s ease both;
        }
        .success-check .circle {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: rgba(85,224,135,0.12);
            border: 2px solid var(--success);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .success-check .circle i {
            font-size: 24px;
            color: var(--success);
        }


        /* ── Custom Confirm Modal ── */
        .custom-confirm-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            backdrop-filter: blur(4px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        .custom-confirm-overlay.active { display: flex; }
        .custom-confirm-box {
            background: var(--bg-card);
            border-radius: 20px;
            padding: 28px 28px 22px;
            max-width: 340px;
            width: 90%;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
            animation: fadeInUp 0.2s ease both;
        }
        .custom-confirm-icon {
            width: 44px; height: 44px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px;
            margin: 0 auto 14px;
        }
        .custom-confirm-icon.warn { background: rgba(209,144,75,.12); color: var(--orange); }
        .custom-confirm-icon.info { background: rgba(52,152,219,.12); color: #3498db; }
        .custom-confirm-title {
            font-size: 15px; font-weight: 700;
            color: var(--text-primary);
            text-align: center; margin-bottom: 8px;
        }
        .custom-confirm-msg {
            font-size: 12.5px; color: var(--text-secondary);
            text-align: center; line-height: 1.6; margin-bottom: 20px;
        }
        .custom-confirm-btns {
            display: flex; gap: 8px;
        }
        .custom-confirm-btns button {
            flex: 1; padding: 10px; border-radius: 10px;
            border: none; font-weight: 600; font-size: 13px;
            cursor: pointer; transition: var(--transition); font-family: inherit;
        }
        .ccb-ok   { background: var(--orange); color: #fff; }
        .ccb-ok:hover { background: var(--orange-dark); }
        .ccb-cancel { background: transparent; color: var(--text-secondary); border: 1.5px solid var(--border) !important; }
        .ccb-cancel:hover { border-color: var(--border-hover) !important; color: var(--text-primary); }

        /* ── Actions ── */
        #fallbackHint {
            text-align: center;
            font-size: 0.78rem;
            color: var(--text-secondary);
            margin-top: 10px;
            min-height: 18px;
        }
        .actions {
            display: none;
            flex-direction: column;
            gap: 6px;
            margin-top: 10px;
        }

        .btn {
            padding: 10px;
            border-radius: 10px;
            border: none;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-decoration: none;
            font-family: 'Poppins', sans-serif;
            text-align: center;
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .btn-primary {
            background: var(--orange);
            color: var(--text-inverse);
        }

        .btn-primary:hover {
            box-shadow: var(--shadow-orange-md);
            filter: brightness(1.1);
        }

        .btn-print {
            background: #c0392b;
            color: var(--text-inverse);
        }

        .btn-print:hover {
            box-shadow: 0 4px 20px rgba(192,57,43,0.3);
            filter: brightness(1.1);
        }

        .btn-secondary {
            background: transparent;
            color: var(--text-secondary);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            border-color: var(--orange);
            color: var(--text-primary);
        }

        .btn-retry {
            background: linear-gradient(135deg,#2980b9,#3498db);
            color: #fff;
        }

        .btn-retry:hover {
            box-shadow: 0 4px 20px rgba(52,152,219,0.35);
            filter: brightness(1.1);
        }

        .btn-switch-cash {
            background: linear-gradient(135deg,#27ae60,#55e087);
            color: #000;
        }

        .btn-switch-cash:hover {
            box-shadow: 0 4px 20px rgba(85,224,135,0.35);
            filter: brightness(1.05);
        }

        /* ── Animations ── */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ── Responsive ── */
        @media (max-width: 600px) {
            .payment-card {
                padding: 24px 18px;
                max-width: 100%;
            }
            
            .info-grid {
                padding: 12px 14px;
            }
            
            .header h1 {
                font-size: 20px;
            }
            
            .qr-box img {
                width: 180px;
                height: 180px;
            }
            
            .btn {
                padding: 9px;
                font-size: 12px;
            }
        }

        @media (max-width: 400px) {
            .payment-card {
                padding: 16px 12px;
            }
            
            .header .icon-wrapper {
                width: 44px;
                height: 44px;
            }
            
            .header .icon-wrapper i {
                font-size: 20px;
            }
            
            .qr-box img {
                width: 150px;
                height: 150px;
            }
            
            .info-item .value {
                font-size: 14px;
            }
        }

        /* ── DARK THEME ── */
        [data-theme="dark"] {
            --bg-body: #0c0c0c;
            --bg-card: #161616;
            --bg-card-hover: #1e1e1e;
            --border: #252525;
            --border-hover: #333;
            --text-primary: #f0f0f0;
            --text-secondary: #aaa;
            --text-muted: #666;
            --shadow-sm: 0 2px 10px rgba(0,0,0,0.5);
            --shadow-md: 0 6px 28px rgba(0,0,0,0.6);
            --shadow-lg: 0 12px 48px rgba(0,0,0,0.7);
        }
        [data-theme="dark"] body { background-image: none; }
        [data-theme="dark"] .info-grid,
        [data-theme="dark"] .amount-breakdown,
        [data-theme="dark"] .qr-box { background: rgba(255,255,255,0.02); }
        [data-theme="dark"] .qr-box canvas,
        [data-theme="dark"] .qr-box img { filter: invert(0); }
    </style>
</head>
<body>

<div class="payment-card" style="position:relative;">
    <!-- Always-available close (never trap the cashier) -->
    <button type="button" id="btnClose" title="Close — order waits in Pending Payment"
            style="position:absolute;top:14px;right:14px;width:34px;height:34px;border-radius:50%;
                   border:1px solid var(--border,#333);background:transparent;color:var(--text-secondary,#999);
                   font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:5;">
        <i class="fa-solid fa-xmark"></i>
    </button>
    <!-- Header -->
    <div class="header">
        <div class="icon-wrapper">
            <i class="fa-solid fa-qrcode"></i>
        </div>
        <h1><span>Scan to Pay</span></h1>
        <p>Bakong KHQR Payment</p>
    </div>

    <!-- Info Grid -->
    <div class="info-grid">
        <div class="info-item">
            <span class="label">Order #</span>
            <span class="value highlight">#<?php echo (int)$order['daily_order_no']; ?></span>
        </div>
        <div class="info-item" style="align-items: flex-end;">
            <span class="label">Customer</span>
            <span class="value" style="text-align: right;"><?php echo htmlspecialchars($order['customer_name']); ?></span>
        </div>
    </div>

    <!-- Amount Breakdown -->
    <div class="amount-breakdown">
        <div class="breakdown-row">
            <span>Subtotal</span>
            <span>$<?php echo number_format($subtotal, 2); ?></span>
        </div>
        <div class="breakdown-row">
            <span>Tax (<?= TAX_RATE ?>%)</span>
            <span>$<?php echo number_format($tax_amount, 2); ?></span>
        </div>
        <div class="breakdown-row total">
            <span>Total</span>
            <span>$<?php echo number_format($total, 2); ?></span>
        </div>
        <div class="breakdown-row bakong-amount">
            <span>Bakong Amount</span>
            <span>$<?php echo number_format($bakong_amount, 2); ?></span>
        </div>
        <div class="breakdown-row" style="color:var(--text-muted);font-size:12px;">
            <span>KHR Equivalent</span>
            <span>KHR <?php echo number_format($khr_bakong); ?></span>
        </div>
    </div>

    <!-- Success Check (shown after payment confirmed) -->
    <div class="success-check" id="successCheck">
        <div class="circle"><i class="fa-solid fa-check"></i></div>
    </div>

    <!-- QR Section -->
    <div class="qr-section" id="qrSection">
        <div class="qr-box">
            <img src="<?php echo htmlspecialchars($qrUrl); ?>" alt="Bakong KHQR">
        </div>
    </div>

    <!-- Status -->
    <div class="status-section">
        <div class="status-indicator" id="statusIndicator">
            <span class="spinner"></span>
            <span class="status-text" id="statusText">Waiting for Bakong payment...</span>
        </div>
    </div>


    <!-- Escape is the ✕ in the card corner. Cash / regenerate / print all live on
         Find Orders → Pending Payment, so they're intentionally not duplicated here. -->
</div>

<!-- Custom Confirm Modal -->
<div class="custom-confirm-overlay" id="customConfirm">
    <div class="custom-confirm-box">
        <div class="custom-confirm-icon" id="ccIcon"><i id="ccIconI"></i></div>
        <div class="custom-confirm-title" id="ccTitle"></div>
        <div class="custom-confirm-msg" id="ccMsg"></div>
        <div class="custom-confirm-btns">
            <button class="ccb-cancel" id="ccCancel">Cancel</button>
            <button class="ccb-ok" id="ccOk">Confirm</button>
        </div>
    </div>
</div>

<script>
// ── Custom confirm helper ──
function showConfirm({ icon, iconType, title, msg, okText, onOk }) {
    document.getElementById('ccIconI').className = icon;
    document.getElementById('ccIcon').className = 'custom-confirm-icon ' + (iconType || 'warn');
    document.getElementById('ccTitle').textContent = title;
    document.getElementById('ccMsg').textContent = msg;
    document.getElementById('ccOk').textContent = okText || 'Confirm';
    document.getElementById('customConfirm').classList.add('active');

    const ok = document.getElementById('ccOk');
    const cancel = document.getElementById('ccCancel');
    const close = () => document.getElementById('customConfirm').classList.remove('active');

    ok.onclick = () => { close(); onOk(); };
    cancel.onclick = close;
}

document.addEventListener('DOMContentLoaded', function() {
    // The single ✕ is the only way off this page. The order parks in Pending
    // Payment; cash / regenerate / print are all handled from Find Orders.
    document.getElementById('btnClose').addEventListener('click', function(e) {
        e.preventDefault();
        showConfirm({
            icon: 'fa-solid fa-clock',
            iconType: 'info',
            title: 'Leave this QR?',
            msg: 'The order will wait in Find Orders → Pending Payment. You can collect it there later by Cash or Bakong — nothing is lost.',
            okText: 'OK, leave',
            onOk: () => window.location.href = 'menu.php'
        });
    });
});

const orderId = <?php echo (int)$order_id; ?>;
let checkInterval = null;

// ── Go to premium success page ──
function showPaymentSuccess() {
    clearInterval(checkInterval);

    const indicator = document.getElementById('statusIndicator');
    indicator.className = 'status-indicator success';
    indicator.innerHTML = '<i class="fa-solid fa-circle-check"></i><span>Payment confirmed! Loading...</span>';

    setTimeout(() => {
        window.location.href = 'payment_cash.php?order_id=' + orderId;
    }, 800);
}

// ── Show a verification error (e.g. expired token) instead of spinning forever ──
function he(str) {
    const d = document.createElement('div');
    d.textContent = (str || '');
    return d.innerHTML;
}

function showVerifyError(msg, errType) {
    const statusSection = document.querySelector('.status-section');
    if (!statusSection) return;

    const showManualBtn = (errType === 'rate_limited' || errType === 'api_error' || errType === 'timeout');

    statusSection.innerHTML = `
        <div class="verify-error-card">
            <div class="verify-error-title">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span>Payment Verification Unavailable</span>
            </div>
            <div class="verify-error-msg">${he(msg)}</div>
            ${showManualBtn ? `
                <button type="button" class="btn-manual-confirm" id="btnManualConfirm">
                    <i class="fa-solid fa-check-double"></i>
                    <span>Manually Confirm Payment (Bank Verified)</span>
                </button>
            ` : ''}
            <div class="verify-error-hint">
                Close this (&times;) and collect from Find Orders &rarr; Pending Payment.
            </div>
        </div>
    `;

    if (showManualBtn) {
        const btn = document.getElementById('btnManualConfirm');
        if (btn) {
            btn.onclick = async function() {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Confirming...</span>';
                try {
                    const formData = new FormData();
                    formData.append('action', 'manual_confirm');
                    const res = await fetch('check_payment.php?order_id=' + orderId, { method: 'POST', body: formData });
                    const data = await res.json();
                    if (data.paid) {
                        showPaymentSuccess();
                    } else {
                        alert(data.error || 'Failed to confirm payment.');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa-solid fa-check-double"></i> <span>Manually Confirm Payment (Bank Verified)</span>';
                    }
                } catch (e) {
                    alert('Network error confirming payment.');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-check-double"></i> <span>Manually Confirm Payment (Bank Verified)</span>';
                }
            };
        }
    }
}

let pollCount = 0;
const MAX_POLLS = 24; // 24 attempts @ 5s = 120 seconds (2 minutes) max polling window

async function checkPayment() {
    pollCount++;
    try {
        const res = await fetch('check_payment.php?order_id=' + orderId, { cache: 'no-store' });
        const data = await res.json();
        if (data.paid) { showPaymentSuccess(); return; }
        if (data.error) {
            showVerifyError(data.message || 'Payment verification unavailable.', data.error);
            // Server-side errors (expired token, rate limit, auth) are persistent — stop polling.
            clearInterval(checkInterval);
            return;
        }
    } catch (e) {
        console.error('Payment check error:', e);
    }

    if (pollCount >= MAX_POLLS) {
        clearInterval(checkInterval);
        showVerifyError('Payment verification window timed out (2 minutes). Check your Bakong app to confirm payment.', 'timeout');
    }
}

// Poll every 5000ms (5 seconds) instead of 2000ms to conserve Bakong daily API quota
checkInterval = setInterval(checkPayment, 5000);
checkPayment();
</script>

</body>
</html>