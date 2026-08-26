<?php
require 'auth.php';
require_once 'config.php';

$order_id = (int)($_GET['order_id'] ?? 0);

if ($order_id <= 0) {
    header("Location: menu.php");
    exit;
}

$stmt = $conn->prepare("
    SELECT order_id, daily_order_no, customer_name, status, is_open, payment_method
    FROM orders
    WHERE order_id = ?
      AND is_open = 1
      AND (
          status IN ('Preparing', 'Paid')
          OR (payment_method = 'paylater' AND status = 'Completed')
      )
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    echo "<!DOCTYPE html>
    <html lang='" . current_lang() . "' data-lang='" . current_lang() . "'>
    <head>
        <title>Order Not Found</title>
        <link rel='preconnect' href='https://fonts.googleapis.com'>
        <link rel='preconnect' href='https://fonts.gstatic.com' crossorigin>
        <link href='https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,400;0,600;0,700;1,400&family=Noto+Sans+Khmer:wght@400;600;700&family=Poppins:wght@400;600;700&display=swap' rel='stylesheet'>
        <style>
            body {
                font-family: 'Poppins', 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', sans-serif;
                background: #f2ede8;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                margin: 0;
                padding: 20px;
            }
            .error-box {
                background: #fffdf9;
                border: 1px solid #e0d5c8;
                border-radius: 16px;
                padding: 40px;
                max-width: 480px;
                width: 100%;
                text-align: center;
                box-shadow: 0 6px 28px rgba(90,60,20,0.11);
            }
            .error-box i {
                font-size: 48px;
                color: #d1904b;
                display: block;
                margin-bottom: 16px;
            }
            .error-box h1 {
                font-size: 22px;
                color: #1a1410;
                margin: 0 0 8px 0;
            }
            .error-box p {
                color: #5a4a3a;
                margin: 0 0 24px 0;
                font-size: 15px;
            }
            .error-box a {
                display: inline-block;
                background: #d1904b;
                color: #fff;
                padding: 12px 28px;
                border-radius: 50px;
                text-decoration: none;
                font-weight: 600;
                transition: all 0.3s ease;
            }
            .error-box a:hover {
                background: #a0702a;
                transform: translateY(-3px);
                box-shadow: 0 4px 16px rgba(209,144,75,0.22);
            }
        </style>
    </head>
    <body>
        <div class='error-box'>
            <i class='fa-solid fa-circle-exclamation'></i>
            <h1>Order Cannot Be Modified</h1>
            <p>This order is closed or no longer available for additions.</p>
            <a href='dashboard.php' style='display:inline-flex;align-items:center;gap:7px;color:#d1904b;text-decoration:none;font-size:13px;font-weight:600;padding:7px 14px;border-radius:10px;border:1px solid rgba(209,144,75,.35);background:rgba(209,144,75,.08);transition:all .2s;'><i class='fa-solid fa-arrow-left'></i> Back to Dashboard</a>
        </div>
    </body>
    </html>";
    exit;
}

// Flag paylater re-opens so confirm_order.php resets status inside its transaction
$_SESSION['paylater_reopen'] = ($order['payment_method'] === 'paylater' && $order['status'] === 'Completed');

/* Hold whatever the cashier was building for a NEW customer, and start the
   add-to-order cart empty. Both jobs share $_SESSION['cart'], so without this a
   drink queued for a walk-in would be added to someone else's tab.
   menu.php puts it back the moment the cashier returns to a plain menu.

   An existing stash is never overwritten: find_order.php offers "Add Items", so
   this file is reachable while add-to-order mode is ALREADY active, and menu.php's
   restore never runs on that path. Overwriting would destroy the held cart —
   the exact failure this is here to prevent. Switching tabs mid-add drops the
   in-flight add-cart instead, which is what abandoning that tab means. */
if (!empty($_SESSION['cart']) && !isset($_SESSION['cart_stash'])) {
    $_SESSION['cart_stash'] = $_SESSION['cart'];
}
$_SESSION['cart'] = [];

// Store the internal order_id in session
$_SESSION['add_to_order_id'] = $order['order_id'];
$_SESSION['add_to_daily_no'] = $order['daily_order_no'];
$_SESSION['customer_name'] = $order['customer_name'];

// Redirect to menu with the daily_order_no for display
header("Location: menu.php?add_to_order=" . $order['daily_order_no']);
exit;
?>