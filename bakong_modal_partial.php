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
<div id="receipt-modal" class="fixed inset-0 bg-black/65 backdrop-blur-md z-[9999] flex items-center justify-center p-4" onclick="if(event.target===this) window.closeReceiptModal();">
  <div class="bg-[#121215]/95 border border-amber-500/20 rounded-3xl max-w-md w-full shadow-2xl relative overflow-hidden p-6 text-white font-sans">
    <!-- Top Accent Bar -->
    <div class="h-1 w-full bg-gradient-to-r from-amber-500/0 via-amber-500 to-amber-500/0 absolute top-0 left-0"></div>

    <!-- Close Button -->
    <a href="cancel_bakong_order.php?order_id=<?= (int)$bakong_order_id ?>" id="btnCloseReceipt" title="Close" onclick="window.location.href=this.href;return false;"
       style="position:absolute;top:16px;right:16px;width:36px;height:36px;border-radius:50%;border:1px solid rgba(255,255,255,0.2);background:rgba(255,255,255,0.1);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:99999;text-decoration:none;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.1)'">
        <i class="fa-solid fa-xmark" style="font-size:18px;"></i>
    </a>

    <!-- Header & Brand -->
    <div class="receipt-header text-center mb-4">
        <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-amber-500/15 text-amber-500 text-xl mb-2">
            <i class="fa-solid fa-receipt"></i>
        </div>
        <h2 class="text-xl font-bold text-white m-0">Bird's Nest POS Receipt</h2>
        <p class="text-xs text-gray-400 mt-1">Scan Bakong KHQR to Complete Order</p>
    </div>

    <!-- 1. Order Header Badges (Single Row) -->
    <div class="receipt-badges" style="display:flex;flex-wrap:nowrap;align-items:center;justify-content:space-between;gap:5px;margin-bottom:16px;padding:8px 6px;background:rgba(255,255,255,0.03);border-radius:12px;border:1px solid rgba(255,255,255,0.05);">
        <span class="rc-badge" style="display:inline-flex;align-items:center;justify-content:center;gap:3px;white-space:nowrap;padding:4px 6px;border-radius:8px;background:rgba(209,144,75,0.15);color:#f59e0b;font-size:11px;font-weight:600;flex:1;">
            <i class="fa-solid fa-hashtag" style="font-size:10px;"></i> Order #<?= (int)$order['daily_order_no'] ?>
        </span>
        <?php if (!empty($order['table_number']) && $order['table_number'] !== 'N/A'): ?>
        <span class="rc-badge" style="display:inline-flex;align-items:center;justify-content:center;gap:3px;white-space:nowrap;padding:4px 6px;border-radius:8px;background:rgba(59,130,246,0.15);color:#60a5fa;font-size:11px;font-weight:600;flex:1;">
            <i class="fa-solid fa-chair" style="font-size:10px;"></i> Stand #<?= htmlspecialchars($order['table_number']) ?>
        </span>
        <?php endif; ?>
        <span class="rc-badge" style="display:inline-flex;align-items:center;justify-content:center;gap:3px;white-space:nowrap;padding:4px 6px;border-radius:8px;background:rgba(16,185,129,0.15);color:#34d399;font-size:11px;font-weight:600;flex:1;">
            <i class="fa-solid fa-<?= ($order['order_type'] ?? '') === 'drink_out' ? 'bag-shopping' : 'mug-hot' ?>" style="font-size:10px;"></i> <?= ($order['order_type'] ?? '') === 'drink_out' ? 'Drink Out' : 'Drink In' ?>
        </span>
        <span class="rc-badge" style="display:inline-flex;align-items:center;justify-content:center;gap:3px;white-space:nowrap;padding:4px 6px;border-radius:8px;background:rgba(239,68,68,0.15);color:#f87171;font-size:11px;font-weight:600;flex:1;">
            <i class="fa-solid fa-qrcode" style="font-size:10px;"></i> Bakong KHQR
        </span>
    </div>

    <!-- 2. Detailed Items List -->
    <div class="receipt-items-section mb-4">
        <div class="text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-2">Ordered Items</div>
        <div class="receipt-items-list max-h-40 overflow-y-auto pr-1 flex flex-col gap-2">
            <?php if (!empty($order_items)): ?>
            <?php foreach ($order_items as $item):
                $meta = array_filter([
                    !empty($item['size_label']) ? 'Size: '.$item['size_label'] : '',
                    !empty($item['sweetness'])  ? 'Sweet: '.$item['sweetness']  : '',
                    !empty($item['ice'])        ? 'Ice: '.$item['ice']          : '',
                    !empty($item['milk'])       ? 'Milk: '.$item['milk']        : '',
                ]);
                $line_total = (float)($item['price'] ?? 0) * (int)($item['qty'] ?? 1);
            ?>
            <div class="receipt-item-row flex items-start justify-between p-2.5 bg-white/[0.02] rounded-xl border border-white/[0.04]">
                <div class="flex-1 pr-2">
                    <div class="text-xs font-semibold text-gray-200"><?= htmlspecialchars($item['product_name'] ?? '') ?> <span class="text-[11px] text-amber-500">x<?= (int)($item['qty'] ?? 1) ?></span></div>
                    <?php if ($meta): ?>
                    <div class="text-[11px] text-gray-400 mt-0.5"><?= htmlspecialchars(implode(' • ', $meta)) ?></div>
                    <?php endif; ?>
                </div>
                <div class="text-xs font-bold text-white whitespace-nowrap">$<?= number_format($line_total, 2) ?></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- 3. Order Totals Summary -->
    <div class="receipt-summary p-3 bg-white/[0.03] rounded-2xl border border-white/[0.05] mb-4">
        <div class="flex justify-between text-xs text-gray-400 mb-1">
            <span>Subtotal</span>
            <span>$<?= number_format($subtotal, 2) ?></span>
        </div>
        <div class="flex justify-between text-xs text-gray-400 mb-2">
            <span>Tax (<?= TAX_RATE ?>%)</span>
            <span>$<?= number_format($tax_amount, 2) ?></span>
        </div>
        <div class="flex justify-between items-center pt-2 border-t border-dashed border-white/10">
            <span class="text-sm font-bold text-white">Final Total</span>
            <div class="text-right">
                <div class="text-lg font-extrabold text-emerald-400">$<?= number_format($total, 2) ?></div>
                <div class="text-[11px] text-gray-400">KHR <?= number_format($khr_bakong) ?></div>
            </div>
        </div>
    </div>

    <!-- 4. KHQR Code & Status -->
    <div class="receipt-qr-section text-center" id="qrSection">
        <div class="qr-box inline-block p-3 bg-white rounded-2xl shadow-xl mb-3">
            <img src="<?= htmlspecialchars($qrUrl) ?>" alt="Bakong KHQR" class="w-40 h-40 block">
        </div>
        <div class="status-indicator flex items-center justify-center gap-2 text-xs text-amber-500 mb-2" id="statusIndicator">
            <span class="spinner"></span>
            <span class="status-text" id="statusText">Waiting for Bakong payment...</span>
        </div>
        <button type="button" id="btnManualConfirmBakong" onclick="confirmBakongManual(<?= (int)$bakong_order_id ?>)"
                style="width:100%;margin-top:8px;padding:10px 14px;border-radius:12px;border:none;background:linear-gradient(135deg,#f59e0b 0%,#d97706 100%);color:#000;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;box-shadow:0 4px 12px rgba(245,158,11,0.25);transition:all 0.2s;">
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
    if (typeof window.printReceipt === 'function') {
        window.printReceipt(orderId);
    } else {
        var printWin = window.open('receipt_print.php?order_id=' + orderId, 'receipt_win', 'width=460,height=720,top=100,left=100,scrollbars=yes');
        if (printWin) { try { printWin.focus(); } catch(e) {} }
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
    }, 5000);
})();
</script>
