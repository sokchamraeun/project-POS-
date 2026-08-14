<?php
// bakong_modal_partial.php
if (!defined('AUTH_OK')) {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/auth.php';
}

$bakong_order_id = (int)($_GET['bakong_order_id'] ?? $_GET['order_id'] ?? $bakong_order_id ?? 0);
if ($bakong_order_id <= 0) return;

require_once __DIR__ . '/bakong-khqr-php-main/vendor/autoload.php';
use KHQR\BakongKHQR;
use KHQR\Models\IndividualInfo;

$config = require __DIR__ . '/bakong_config.php';

// ── Get order and payment data ──
$stmt = $conn->prepare("
    SELECT o.order_id, o.customer_name, o.total, o.status, o.bakong_md5, o.daily_order_no,
           o.order_type, o.table_number, o.payment_method,
           op.payment_id, op.amount AS bakong_amount, op.payment_status
    FROM orders o
    LEFT JOIN order_payments op ON o.order_id = op.order_id AND op.payment_method = 'bakong'
    WHERE o.order_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $bakong_order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order || ($order['payment_status'] ?? '') === 'paid') return;

// Fetch order items for the receipt list
$stmt_items = $conn->prepare("SELECT product_name, quantity AS qty, price, size_label, sweetness, ice, milk FROM order_items WHERE order_id = ?");
$stmt_items->bind_param("i", $bakong_order_id);
$stmt_items->execute();
$order_items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);

$bakong_amount = (float)($order['bakong_amount'] ?? 0);
if ($bakong_amount <= 0) $bakong_amount = (float)$order['total'];
$khr_bakong = (int)(round($bakong_amount * KHR_RATE / 100) * 100);

$total = (float)$order['total'];
$tax_rate = TAX_RATE / 100;
$subtotal = round($total / (1 + $tax_rate), 2);
$tax_amount = round($total - $subtotal, 2);

$qrString = '';
$md5 = $order['bakong_md5'] ?? null;

try {
    if (empty($md5)) {
        $individualInfo = new IndividualInfo(
            bakongAccountID: $config['bakong_id'],
            merchantName: $config['merchant_name'],
            merchantCity: $config['merchant_city'],
            currency: $config['currency'],
            amount: $bakong_amount,
            billNumber: 'ORDER_' . $bakong_order_id,
            storeLabel: 'ObsidianCafe',
            terminalLabel: 'POS1',
            mobileNumber: $config['mobile_number'],
            expirationTimestamp: strval((time() + 15 * 60) * 1000)
        );

        $khqrResponse = BakongKHQR::generateIndividual($individualInfo);

        if (($khqrResponse->status['code'] ?? 1) === 0 && !empty($khqrResponse->data['qr']) && !empty($khqrResponse->data['md5'])) {
            $qrString = $khqrResponse->data['qr'];
            $md5 = $khqrResponse->data['md5'];

            $stmt_u = $conn->prepare("UPDATE orders SET bakong_md5 = ? WHERE order_id = ?");
            $stmt_u->bind_param("si", $md5, $bakong_order_id);
            $stmt_u->execute();
        }
    } else {
        $individualInfo = new IndividualInfo(
            bakongAccountID: $config['bakong_id'],
            merchantName: $config['merchant_name'],
            merchantCity: $config['merchant_city'],
            currency: $config['currency'],
            amount: $bakong_amount,
            billNumber: 'ORDER_' . $bakong_order_id,
            storeLabel: 'ObsidianCafe',
            terminalLabel: 'POS1',
            mobileNumber: $config['mobile_number'],
            expirationTimestamp: strval((time() + 15 * 60) * 1000)
        );
        $khqrResponse = BakongKHQR::generateIndividual($individualInfo);
        $qrString = $khqrResponse->data['qr'] ?? '';
    }
} catch (Throwable $e) {
    header("Location: cancel_bakong_order.php?order_id=" . $bakong_order_id);
    exit;
}

if (empty($qrString)) {
    header("Location: cancel_bakong_order.php?order_id=" . $bakong_order_id);
    exit;
}

$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($qrString);
?>
<script>
window.closeReceiptModal = function() {
    var orderId = <?= (int)$bakong_order_id ?>;
    window.location.href = 'cancel_bakong_order.php?order_id=' + orderId;
};
</script>
<link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,400;0,600;0,700;1,400&family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<div id="receipt-modal" class="fixed inset-0 bg-black/75 backdrop-blur-md z-[9999] flex items-center justify-center p-4 overflow-y-auto" onclick="if(event.target===this) window.closeReceiptModal();">
  <div class="bg-white rounded-3xl max-w-sm w-full shadow-2xl relative overflow-hidden p-6 text-black font-sans my-4" style="font-family: 'Kantumruy Pro', 'Poppins', sans-serif;">
    
    <!-- Close Button -->
    <a href="cancel_bakong_order.php?order_id=<?= (int)$bakong_order_id ?>" id="btnCloseReceipt" title="Close" onclick="window.location.href=this.href;return false;"
       style="position:absolute;top:14px;right:14px;width:32px;height:32px;border-radius:50%;border:1px solid #ddd;background:#f4f4f5;color:#333;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:99999;text-decoration:none;">
        <i class="fa-solid fa-xmark" style="font-size:16px;"></i>
    </a>

    <!-- Header -->
    <div style="text-align:center; margin-bottom:12px;">
        <h1 style="font-size:18px; font-weight:700; margin:0; color:#000;">The Bird Nest Cafe</h1>
        <p style="font-size:11px; color:#555; margin:2px 0 0;">Phnom Penh</p>
        <h2 style="font-size:16px; font-weight:700; margin:8px 0 6px; color:#000; letter-spacing:0.5px;">វិក្កយបត្រ</h2>
    </div>

    <!-- Metadata Grid -->
    <table style="width:100%; border-collapse:collapse; margin-bottom:10px; font-size:10.5px; color:#000;">
        <tr>
            <td style="width:50%; padding:1px 0;">អ្នកគិតលុយ : <strong><?= htmlspecialchars($_SESSION['username'] ?? 'Root') ?></strong></td>
            <td style="width:50%; text-align:right; padding:1px 0;">លេខវិក្កយបត្រ : <strong><?= str_pad($order['daily_order_no'], 4, '0', STR_PAD_LEFT) ?></strong></td>
        </tr>
        <tr>
            <td style="padding:1px 0;">អតិថិជន : <strong><?= htmlspecialchars($order['customer_name'] ?: 'Guest') ?></strong></td>
            <td style="text-align:right; padding:1px 0;">ម៉ោងចេញ : <strong><?= date("d-m-Y h:i A") ?></strong></td>
        </tr>
        <tr>
            <td style="padding:1px 0;">បង់តាម : <strong>KHQR</strong></td>
            <td style="text-align:right; padding:1px 0;">អត្រាប្តូរប្រាក់ : <strong>1$ = <?= number_format(KHR_RATE) ?> ៛</strong></td>
        </tr>
    </table>

    <!-- Items Table -->
    <table style="width:100%; border-collapse:collapse; margin-bottom:12px; font-size:10px; border:1px solid #000;">
        <thead>
            <tr style="background:#fff; border-bottom:1px solid #000;">
                <th style="border:1px solid #000; padding:4px 3px; text-align:center; font-weight:700; width:10%;">ល.រ</th>
                <th style="border:1px solid #000; padding:4px 4px; text-align:center; font-weight:700; width:44%;">បរិយាយ</th>
                <th style="border:1px solid #000; padding:4px 3px; text-align:center; font-weight:700; width:14%;">ចំនួន</th>
                <th style="border:1px solid #000; padding:4px 3px; text-align:center; font-weight:700; width:16%;">តម្លៃ</th>
                <th style="border:1px solid #000; padding:4px 3px; text-align:center; font-weight:700; width:16%;">សរុប</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $idx = 1;
            if (!empty($order_items)):
            foreach ($order_items as $item):
                $unit_price = (float)($item['price'] ?? 0);
                $qty = (float)($item['qty'] ?? 1);
                $line_total = $unit_price * $qty;
                $opts = array_filter([
                    !empty($item['sweetness']) ? 'sugar : '.$item['sweetness'] : '',
                    !empty($item['ice'])       ? 'ice : '.strtolower($item['ice']) : '',
                    !empty($item['milk'])      ? 'milk : '.strtolower($item['milk']) : '',
                ]);
            ?>
            <tr>
                <td style="border:1px solid #000; padding:4px 3px; text-align:center;"><?= $idx++ ?></td>
                <td style="border:1px solid #000; padding:4px 4px; text-align:left;">
                    <div style="font-weight:700; color:#000; font-size:10px;"><?= htmlspecialchars($item['product_name'] ?? '') ?></div>
                    <?php foreach ($opts as $opt): ?>
                    <div style="font-size:8.5px; color:#333; line-height:1.25; margin-top:1px;"><?= htmlspecialchars($opt) ?></div>
                    <?php endforeach; ?>
                </td>
                <td style="border:1px solid #000; padding:4px 3px; text-align:center;"><?= number_format($qty, 1) ?></td>
                <td style="border:1px solid #000; padding:4px 3px; text-align:center;"><?= number_format($unit_price, 2) ?></td>
                <td style="border:1px solid #000; padding:4px 3px; text-align:center;"><?= number_format($line_total, 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Totals -->
    <div style="width:100%; font-size:11px; margin-top:4px; color:#000;">
        <div style="display:flex; justify-content:space-between; margin-bottom:2px;">
            <span>ប្រាក់សរុប :</span>
            <span>USD <?= number_format($total, 2) ?></span>
        </div>
        <div style="display:flex; justify-content:space-between; font-weight:700; font-size:12px; margin-bottom:2px;">
            <span>ប្រាក់សរុបចុងក្រោយ :</span>
            <span>USD <?= number_format($total, 2) ?></span>
        </div>
        <div style="text-align:right; font-weight:700; font-size:11px; margin-bottom:6px;">
            KHR <?= number_format($khr_bakong) ?>
        </div>
    </div>

    <!-- Dotted Divider -->
    <div style="border-top:1px dotted #000; margin:10px 0 8px;"></div>

    <!-- Thank You -->
    <div style="text-align:center; font-weight:700; font-size:13px; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px; color:#000;">
        THANK YOU!
    </div>

    <!-- KHQR Box -->
    <div style="text-align:center; margin-bottom:12px;" id="qrSection">
        <div style="display:inline-block; padding:10px; background:#fff; border:2px solid #000; border-radius:18px; box-shadow:0 4px 14px rgba(0,0,0,0.06);">
            <img src="<?= htmlspecialchars($qrUrl) ?>" alt="Bakong KHQR" style="width:160px; height:160px; display:block; margin:0 auto;">
            <div style="font-size:11px; font-weight:700; color:#000; margin-top:6px; letter-spacing:0.5px; text-transform:uppercase;">BAKONG KHQR</div>
        </div>
    </div>

    <!-- Status & Confirm Button -->
    <div style="text-align:center;" id="statusIndicator">
        <div style="display:flex; align-items:center; justify-content:center; gap:6px; font-size:12px; color:#d97706; font-weight:600; margin-bottom:8px;" id="statusRow">
            <span class="status-text" id="statusText">Waiting for Bakong payment...</span>
        </div>
        <button type="button" id="btnManualConfirmBakong" onclick="confirmBakongManual(<?= (int)$bakong_order_id ?>)"
                style="width:100%; padding:10px 14px; border-radius:12px; border:none; background:linear-gradient(135deg,#f59e0b 0%,#d97706 100%); color:#000; font-size:13px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:6px; box-shadow:0 4px 12px rgba(245,158,11,0.25); transition:all 0.2s;">
            <i class="fa-solid fa-circle-check"></i> Confirm Payment Received
        </button>
    </div>
  </div>
</div>

<script>
function closeReceiptModalOnly() {
    var modal = document.getElementById('receipt-modal');
    if (modal) modal.remove();
    if (window.history && window.history.replaceState) {
        var url = new URL(window.location.href);
        url.searchParams.delete('bakong_order_id');
        url.searchParams.delete('order_id');
        window.history.replaceState({}, '', url.toString());
    }
}

function openReceiptPopup(orderId) {
    closeReceiptModalOnly();
    if (window.name === 'receipt_win') {
        window.location.href = 'receipt_print.php?order_id=' + orderId;
    } else {
        var printWin = window.open('receipt_print.php?order_id=' + orderId, 'receipt_win', 'width=460,height=720,top=100,left=100,scrollbars=yes');
        if (printWin) {
            try { printWin.focus(); } catch(e) {}
        } else {
            window.location.href = 'receipt_print.php?order_id=' + orderId;
        }
    }
}

function confirmBakongManual(orderId) {
    var btn = document.getElementById('btnManualConfirmBakong');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Confirming...'; }
    var formData = new FormData();
    formData.append('order_id', orderId);
    formData.append('action', 'manual_confirm');
    fetch('check_payment.php', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.paid) {
                var st = document.getElementById('statusText');
                if (st) st.textContent = 'Payment Confirmed!';
                setTimeout(function() {
                    openReceiptPopup(orderId);
                }, 400);
            } else {
                alert(res.error || 'Failed to confirm payment');
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Confirm Payment Received'; }
            }
        })
        .catch(function(e) {
            alert('Connection error');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Confirm Payment Received'; }
        });
}

function closeReceiptModal() {
    var orderId = <?= (int)$bakong_order_id ?>;
    window.location.href = 'cancel_bakong_order.php?order_id=' + orderId;
}

(function() {
    var orderId = <?= (int)$bakong_order_id ?>;
    if (!orderId) return;

    var pollTimer = setInterval(function() {
        fetch('check_payment.php?order_id=' + orderId)
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res && res.paid) {
                    clearInterval(pollTimer);
                    var st = document.getElementById('statusText');
                    if (st) st.textContent = 'Payment Confirmed!';
                    setTimeout(function() {
                        openReceiptPopup(orderId);
                    }, 400);
                } else if (res && res.error === 'rate_limited') {
                    clearInterval(pollTimer);
                    var st = document.getElementById('statusText');
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
