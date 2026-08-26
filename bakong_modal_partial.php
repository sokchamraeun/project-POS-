<?php
// bakong_modal_partial.php
if (!defined('AUTH_OK')) {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/auth.php';
}

$bakong_order_id = (int)($_GET['bakong_order_id'] ?? $_GET['order_id'] ?? $bakong_order_id ?? 0);
if ($bakong_order_id <= 0) return;

if (file_exists(__DIR__ . '/bakong-khqr-php-main/autoload.php')) {
    require_once __DIR__ . '/bakong-khqr-php-main/autoload.php';
} elseif (file_exists(__DIR__ . '/bakong-khqr-php-main/vendor/autoload.php')) {
    require_once __DIR__ . '/bakong-khqr-php-main/vendor/autoload.php';
}
use KHQR\BakongKHQR;
use KHQR\Models\IndividualInfo;

$config = require __DIR__ . '/bakong_config.php';

// ── Get order and payment data ──
$stmt = $conn->prepare("
    SELECT o.order_id, 'Guest' AS customer_name, o.total, 'Completed' AS status,
           o.order_id AS daily_order_no, 'drink_in' AS order_type, '' AS table_number, o.payment_method,
           o.order_date,
           COALESCE(NULLIF(u.name, ''), u.username, o.prepared_by, 'Staff') AS cashier_name,
           op.payment_id, op.amount AS bakong_amount, op.payment_status, op.reference AS bakong_md5
    FROM orders o
    LEFT JOIN users u ON u.user_id = o.user_id
    LEFT JOIN order_payments op ON o.order_id = op.order_id AND op.payment_method = 'bakong'
    WHERE o.order_id = ?
    LIMIT 1
");
if (!$stmt) return;
$stmt->bind_param("i", $bakong_order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order || ($order['payment_status'] ?? '') === 'paid') return;

// Fetch order items for receipt list
$stmt_items = $conn->prepare("SELECT product_name, quantity AS qty, price, size_label, sweetness, ice, milk FROM order_items WHERE order_id = ?");
if ($stmt_items) {
    $stmt_items->bind_param("i", $bakong_order_id);
    $stmt_items->execute();
    $order_items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $order_items = [];
}

$bakong_amount = (float)($order['bakong_amount'] ?? 0);
if ($bakong_amount <= 0) $bakong_amount = (float)$order['total'];
$khr_bakong = (int)(round($bakong_amount * KHR_RATE / 100) * 100);

$total = (float)$order['total'];
$tax_rate = defined('TAX_RATE') ? (TAX_RATE / 100) : 0;
$subtotal = round($total / (1 + $tax_rate), 2);
$tax_amount = round($total - $subtotal, 2);

$qrString = $_SESSION['bakong_qr_' . $bakong_order_id] ?? '';
$md5 = $order['bakong_md5'] ?? '';
$qrError = '';

if (empty($qrString) || empty($md5)) {
    $order_ts = !empty($order['order_date']) ? strtotime($order['order_date']) : time();
    $exp_ts   = strval(($order_ts + 15 * 60) * 1000);

    try {
        $individualInfo = new IndividualInfo(
            bakongAccountID: $config['bakong_id'],
            merchantName: $config['merchant_name'],
            merchantCity: $config['merchant_city'],
            currency: $config['currency'],
            amount: $bakong_amount,
            billNumber: 'ORDER_' . $bakong_order_id,
            storeLabel: 'BirdNestCafe',
            terminalLabel: 'POS1',
            mobileNumber: $config['mobile_number'],
            expirationTimestamp: $exp_ts
        );

        $khqrResponse = BakongKHQR::generateIndividual($individualInfo);

        if (($khqrResponse->status['code'] ?? 1) === 0 && !empty($khqrResponse->data['qr'])) {
            $qrString = $khqrResponse->data['qr'];
            $md5 = $khqrResponse->data['md5'] ?? '';
            $_SESSION['bakong_qr_' . $bakong_order_id] = $qrString;

            if (!empty($md5)) {
                $stmt_u = $conn->prepare("UPDATE order_payments SET reference = ? WHERE order_id = ? AND payment_method = 'bakong'");
                if ($stmt_u) {
                    $stmt_u->bind_param("si", $md5, $bakong_order_id);
                    $stmt_u->execute();
                }
            }
        } else {
            $qrError = $khqrResponse->status['message'] ?? 'Failed to generate Bakong KHQR.';
        }
    } catch (Throwable $e) {
        error_log("Bakong QR generation error: " . $e->getMessage());
        $qrError = $e->getMessage();
    }
}

$qrUrl = !empty($qrString) ? 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=' . urlencode($qrString) : '';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<div id="receipt-modal" class="fixed inset-0 bg-black/80 backdrop-blur-md z-[9999] flex items-center justify-center p-4 overflow-y-auto" onclick="if(event.target===this) confirmCloseBakongModal();">
  <div class="bg-white rounded-3xl max-w-sm w-full shadow-2xl relative overflow-hidden p-6 text-black font-sans my-auto border border-gray-100 animate-in fade-in zoom-in-95 duration-200" style="font-family: 'Kantumruy Pro', 'Noto Sans Khmer', 'Poppins', 'Siemreap', sans-serif; max-height: 94vh; overflow-y: auto;">
    
    <!-- Close Button -->
    <button type="button" id="btnCloseReceipt" title="Cancel & Return to Cart" onclick="confirmCloseBakongModal();"
       style="position:absolute;top:14px;right:14px;width:34px;height:34px;border-radius:50%;border:1px solid #e4e4e7;background:#f4f4f5;color:#52525b;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:99999;transition:all 0.15s ease;"
       onmouseover="this.style.background='#fee2e2';this.style.color='#ef4444';"
       onmouseout="this.style.background='#f4f4f5';this.style.color='#52525b';">
        <i class="fa-solid fa-xmark" style="font-size:16px;"></i>
    </button>

    <!-- Header -->
    <div style="text-align:center; margin-bottom:12px;">
        <div style="width:42px;height:42px;margin:0 auto 6px;border-radius:12px;background:#fef3c7;display:flex;align-items:center;justify-content:center;color:#d97706;font-size:20px;">
            <i class="fa-solid fa-mug-hot"></i>
        </div>
        <h1 style="font-size:17px; font-weight:700; margin:0; color:#18181b;">The Bird's Nest Coffee</h1>
        <p style="font-size:11px; color:#71717a; margin:2px 0 0;">Phnom Penh &bull; Bakong Payment</p>
        <div style="display:inline-block; margin-top:6px; padding:2px 10px; border-radius:20px; background:#e0454a; color:#fff; font-size:11px; font-weight:700; letter-spacing:0.5px;">
            KHQR PAYMENT
        </div>
    </div>

    <!-- Metadata Grid -->
    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:8px 10px; margin-bottom:10px; font-size:10.5px; color:#334155;">
        <div style="display:flex; justify-content:space-between; margin-bottom:3px;">
            <span>អ្នកគិតលុយ: <b><?= htmlspecialchars($order['cashier_name'] ?? 'Staff') ?></b></span>
            <span>វិក្កយបត្រ: <b>#<?= str_pad((string)$bakong_order_id, 4, '0', STR_PAD_LEFT) ?></b></span>
        </div>
        <div style="display:flex; justify-content:space-between;">
            <span>ម៉ោង: <b><?= date("d/m/Y h:i A") ?></b></span>
            <span>អត្រា: <b>1$ = <?= number_format(KHR_RATE) ?>៛</b></span>
        </div>
    </div>

    <!-- KHQR Code Box -->
    <div style="text-align:center; margin-bottom:14px;" id="qrSection">
        <div style="display:inline-block; padding:12px 14px; background:#fff; border:2px solid #e0454a; border-radius:20px; box-shadow:0 8px 25px rgba(224,69,74,0.12); position:relative;">
            
            <div style="color:#e0454a; font-weight:800; font-size:13px; margin-bottom:8px; display:flex; align-items:center; justify-content:center; gap:6px;">
                <i class="fa-solid fa-qrcode"></i> KHQR SCAN TO PAY
            </div>

            <?php if (!empty($qrString)): ?>
                <div id="qrCanvasWrap" style="display:flex; align-items:center; justify-content:center; min-height:165px; min-width:165px; margin:0 auto;">
                    <div id="bakongQrJs" style="display:inline-block; border-radius:8px; overflow:hidden;"></div>
                </div>
            <?php else: ?>
                <div style="padding:20px 10px; color:#dc2626; font-size:12px;">
                    <i class="fa-solid fa-triangle-exclamation" style="font-size:24px; margin-bottom:6px;"></i><br>
                    <strong>KHQR Error</strong><br>
                    <span><?= htmlspecialchars($qrError ?: 'Could not generate Bakong QR.') ?></span>
                </div>
            <?php endif; ?>

            <div style="margin-top:8px; padding-top:6px; border-top:1px dashed #fecaca; display:flex; justify-content:center; align-items:baseline; gap:8px;">
                <span style="font-size:18px; font-weight:800; color:#18181b;">$<?= number_format($bakong_amount, 2) ?></span>
                <span style="font-size:12px; font-weight:700; color:#dc2626;">(៛<?= number_format($khr_bakong) ?>)</span>
            </div>
            <div style="font-size:10px; color:#71717a; margin-top:2px;">Merchant: <?= htmlspecialchars($config['merchant_name'] ?? 'OBSIDIAN CAFE') ?></div>
        </div>
    </div>

    <!-- Items Table Collapsible / Summary -->
    <?php if (!empty($order_items)): ?>
    <details style="margin-bottom:12px; background:#fafafa; border:1px solid #f4f4f5; border-radius:10px; padding:6px 10px; font-size:10.5px;">
        <summary style="cursor:pointer; font-weight:600; color:#52525b; user-select:none; display:flex; justify-content:space-between; align-items:center;">
            <span><i class="fa-solid fa-list-check" style="color:#d97706; margin-right:4px;"></i> View Ordered Items (<?= count($order_items) ?>)</span>
            <span style="color:#a1a1aa; font-size:9px;">click to expand</span>
        </summary>
        <table style="width:100%; border-collapse:collapse; margin-top:6px; font-size:10px;">
            <tbody>
                <?php foreach ($order_items as $item): 
                    $u_p = (float)($item['price'] ?? 0);
                    $q = (float)($item['qty'] ?? 1);
                ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:3px 0; text-align:left;">
                        <b><?= htmlspecialchars($item['product_name'] ?? '') ?></b>
                        <?php if (!empty($item['size_label'])): ?><span style="color:#71717a;">(<?= htmlspecialchars($item['size_label']) ?>)</span><?php endif; ?>
                    </td>
                    <td style="padding:3px 4px; text-align:center; color:#71717a;">x<?= (int)$q ?></td>
                    <td style="padding:3px 0; text-align:right; font-weight:600;">$<?= number_format($u_p * $q, 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </details>
    <?php endif; ?>

    <!-- Status & Action Buttons -->
    <div style="text-align:center;" id="statusIndicator">
        <div style="display:flex; align-items:center; justify-content:center; gap:8px; font-size:12.5px; color:#d97706; font-weight:600; margin-bottom:10px; padding:8px 12px; background:#fffbeb; border:1px solid #fef3c7; border-radius:12px;" id="statusRow">
            <i class="fa-solid fa-spinner fa-spin" id="statusSpinner"></i>
            <span class="status-text" id="statusText">Waiting for Bakong payment...</span>
        </div>

        <div style="display:flex; flex-direction:column; gap:8px;" id="actionButtonsDefault">
            <button type="button" id="btnManualConfirmBakong" onclick="confirmBakongManual(<?= (int)$bakong_order_id ?>)"
                    style="width:100%; padding:11px 16px; border-radius:14px; border:none; background:linear-gradient(135deg,#10b981 0%,#059669 100%); color:#fff; font-size:13.5px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; box-shadow:0 4px 14px rgba(16,185,129,0.3); transition:all 0.2s;">
                <i class="fa-solid fa-circle-check"></i> Confirm Payment Received
            </button>

            <button type="button" onclick="confirmCloseBakongModal();"
                    style="width:100%; padding:8px 14px; border-radius:12px; border:1px solid #e4e4e7; background:#fff; color:#71717a; font-size:12px; font-weight:600; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:6px; transition:all 0.15s;">
                <i class="fa-solid fa-arrow-left"></i> Cancel & Return to Cart
            </button>
        </div>
    </div>

    <!-- Success Celebration Box (Appears upon paid) -->
    <div id="paymentSuccessBox" style="display:none; text-align:center; padding:18px 10px 10px;">
        <div style="width:64px; height:64px; border-radius:50%; background:#dcfce7; color:#16a34a; display:inline-flex; align-items:center; justify-content:center; font-size:32px; margin-bottom:12px; box-shadow:0 6px 16px rgba(22,163,74,0.2);">
            <i class="fa-solid fa-check"></i>
        </div>
        <h3 style="font-size:18px; font-weight:700; color:#15803d; margin-bottom:6px;">Payment Received!</h3>
        <p style="font-size:13px; color:#52525b; margin-bottom:14px;">Order #<?= str_pad((string)$bakong_order_id, 4, '0', STR_PAD_LEFT) ?> ($<?= number_format($bakong_amount, 2) ?>) has been settled via Bakong KHQR.</p>

        <div id="autoRedirectText" style="font-size:12px; color:#71717a; margin-top:8px; font-weight:500;">
            <i class="fa-regular fa-clock"></i> Auto-returning to Menu in <span id="countdownSec" style="font-weight:700; color:#18181b;">2</span>s...
        </div>
    </div>

  </div>
</div>

<script>
var QR_STRING = <?= json_encode($qrString) ?>;
var BAKONG_ORDER_ID = <?= (int)$bakong_order_id ?>;
var pollIntervalTimer = null;
var redirectCountdownTimer = null;

function renderBakongQR() {
    var jsWrap = document.getElementById('bakongQrJs');
    if (jsWrap && QR_STRING && typeof QRCode !== 'undefined') {
        jsWrap.innerHTML = '';
        new QRCode(jsWrap, {
            text: QR_STRING,
            width: 165,
            height: 165,
            colorDark : "#000000",
            colorLight : "#ffffff",
            correctLevel : QRCode.CorrectLevel.M
        });
    }
}
renderBakongQR();

function confirmCloseBakongModal() {
    if (confirm('Do you want to cancel this Bakong payment and return items to cart?')) {
        if (pollIntervalTimer) clearInterval(pollIntervalTimer);
        if (redirectCountdownTimer) clearInterval(redirectCountdownTimer);
        window.location.href = 'cancel_bakong_order.php?order_id=' + BAKONG_ORDER_ID;
    }
}

function closeReceiptModalOnly() {
    if (pollIntervalTimer) clearInterval(pollIntervalTimer);
    if (redirectCountdownTimer) clearInterval(redirectCountdownTimer);
    var modal = document.getElementById('receipt-modal');
    if (modal) modal.remove();
    if (window.history && window.history.replaceState) {
        var url = new URL(window.location.href);
        url.searchParams.delete('bakong_order_id');
        url.searchParams.delete('order_id');
        window.history.replaceState({}, '', url.toString());
    }
}

function handlePaymentSuccess(orderId, existingWin) {
    if (pollIntervalTimer) clearInterval(pollIntervalTimer);
    if (redirectCountdownTimer) clearInterval(redirectCountdownTimer);

    // Hide Bakong QR modal if present
    var modal = document.getElementById('receipt-modal');
    if (modal) modal.style.display = 'none';

    // Clean up URL in background
    if (window.history && window.history.replaceState) {
        var url = new URL(window.location.href);
        url.searchParams.delete('bakong_order_id');
        url.searchParams.delete('order_id');
        window.history.replaceState({}, '', url.toString());
    }

    // Silently clear cart
    if (typeof cpSilentClearCart === 'function') {
        cpSilentClearCart();
    }

    var receiptUrl = 'receipt_print.php?order_id=' + Number(orderId);

    // If popup window already open, navigate and focus it
    if (existingWin && !existingWin.closed) {
        try {
            existingWin.location.href = receiptUrl;
            existingWin.focus();
            return;
        } catch(e) {}
    }

    // Open receipt print window
    var win = null;
    try {
        win = window.open(receiptUrl, 'receipt_win', 'width=460,height=720,top=100,left=100,scrollbars=yes');
        if (win) {
            try { win.focus(); } catch(e) {}
        }
    } catch(e) {}

    // Fallback to hidden iframe
    if (!win || win.closed || typeof win.closed === 'undefined') {
        var iframe = document.getElementById('receiptPrintFrame');
        if (!iframe) {
            iframe = document.createElement('iframe');
            iframe.id = 'receiptPrintFrame';
            iframe.style.position = 'fixed';
            iframe.style.right = '0';
            iframe.style.bottom = '0';
            iframe.style.width = '0';
            iframe.style.height = '0';
            iframe.style.border = '0';
            document.body.appendChild(iframe);
        }
        iframe.src = receiptUrl;
    }
}

function confirmBakongManual(orderId) {
    var btn = document.getElementById('btnManualConfirmBakong');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Verifying...';
    }

    var formData = new FormData();
    formData.append('order_id', orderId);
    formData.append('action', 'manual_confirm');
    fetch('check_payment.php', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.paid) {
                handlePaymentSuccess(orderId);
            } else {
                alert(res.error || 'Payment confirmation failed');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Confirm Payment Received';
                }
            }
        })
        .catch(function(e) {
            alert('Network error while confirming payment.');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Confirm Payment Received';
            }
        });
}

(function() {
    if (!BAKONG_ORDER_ID) return;

    pollIntervalTimer = setInterval(function() {
        fetch('check_payment.php?order_id=' + BAKONG_ORDER_ID)
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res && res.paid) {
                    handlePaymentSuccess(BAKONG_ORDER_ID);
                } else if (res && res.error === 'rate_limited') {
                    if (pollIntervalTimer) clearInterval(pollIntervalTimer);
                    var st = document.getElementById('statusText');
                    var spinner = document.getElementById('statusSpinner');
                    if (spinner) spinner.className = 'fa-solid fa-circle-info';
                    if (st) {
                        st.textContent = 'Bakong daily API limit reached. Click Confirm Payment below.';
                        st.style.color = '#ef4444';
                    }
                }
            })
            .catch(function() {});
    }, 3000);
})();
</script>
