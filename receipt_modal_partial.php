<!-- ── THERMAL RECEIPT MODAL PARTIAL ── -->
<style>
@import url('https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap');

@media print {
    @page {
        size: 80mm auto;
        margin: 0;
    }
    html, body {
        width: 80mm !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
    }
    body * {
        visibility: hidden !important;
    }
    #receipt-printable-area, #receipt-printable-area * {
        visibility: visible !important;
    }
    #receipt-printable-area {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 80mm !important;
        padding: 4mm 3mm !important;
        box-shadow: none !important;
        background: #fff !important;
        color: #000 !important;
        margin: 0 !important;
    }
    .no-print {
        display: none !important;
    }
}
</style>

<div id="receiptModal" class="fixed inset-0 z-50 hidden bg-black/75 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto no-print">
    <div class="bg-gray-900 border border-gray-800 rounded-2xl max-w-md w-full p-6 text-white shadow-2xl relative my-8">
        <!-- Modal Header -->
        <div class="flex items-center justify-between pb-4 mb-4 border-b border-gray-800 no-print">
            <h3 class="text-base font-semibold text-gray-200 flex items-center gap-2">
                <i class="fa-solid fa-receipt text-amber-500"></i> Thermal Receipt Preview (80mm)
            </h3>
            <button onclick="closeReceiptModal()" class="text-gray-400 hover:text-white transition-colors p-1" title="Close">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <!-- 80mm Thermal Receipt Canvas -->
        <div class="bg-white text-black p-4 rounded shadow-inner text-xs mx-auto overflow-hidden" style="width: 80mm; font-family: 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', 'Poppins', sans-serif;" id="receipt-printable-area">
            <!-- Header -->
            <div class="text-center mb-2">
                <h1 class="text-lg font-bold tracking-tight text-black leading-tight" id="rcpt-shop-name"><?= htmlspecialchars(defined('RECEIPT_SHOP_NAME') ? RECEIPT_SHOP_NAME : 'The Bird Nest Cafe') ?></h1>
                <p class="text-[11px] text-gray-800" id="rcpt-shop-location"><?= htmlspecialchars(defined('RECEIPT_LOCATION') ? RECEIPT_LOCATION : 'Phnom Penh') ?></p>
                <?php if (defined('RECEIPT_PHONE') && RECEIPT_PHONE !== ''): ?>
                <p class="text-[11px] text-gray-800" id="rcpt-shop-phone"><?= htmlspecialchars(RECEIPT_PHONE) ?></p>
                <?php endif; ?>
                <h2 class="text-base font-bold text-black mt-2 mb-1 tracking-wide">វិក្កយបត្រ</h2>
            </div>

            <!-- Meta Data Grid -->
            <div class="text-[10.5px] leading-tight mb-2 space-y-0.5">
                <div class="flex justify-between">
                    <span>អ្នកគិតលុយ : <span id="rcpt-cashier" class="font-semibold">admin</span></span>
                    <span>លេខវិក្កយបត្រ : <span id="rcpt-inv-no" class="font-semibold">26000043</span></span>
                </div>
                <div class="flex justify-between">
                    <span>អតិថិជន : <span id="rcpt-customer" class="font-semibold">General Customer</span></span>
                    <span>ម៉ោងចេញ : <span id="rcpt-datetime" class="font-semibold">05-08-2026 10:14 AM</span></span>
                </div>
                <div class="flex justify-between">
                    <span>បង់តាម : <span id="rcpt-payment-method" class="font-semibold">Cash-$</span></span>
                </div>
            </div>

            <!-- Bordered Item Table -->
            <table class="w-full border-collapse border border-black text-[10px] my-2">
                <thead>
                    <tr class="bg-white text-black border-b border-black">
                        <th class="border border-black p-1 text-center font-bold w-[8%]">ល.រ</th>
                        <th class="border border-black p-1 text-left font-bold w-[42%]">បរិយាយ</th>
                        <th class="border border-black p-1 text-center font-bold w-[12.5%]">ចំនួន</th>
                        <th class="border border-black p-1 text-center font-bold w-[12.5%]">តម្លៃ</th>
                        <th class="border border-black p-1 text-center font-bold w-[12.5%]">បញ្ចុះតម្លៃ</th>
                        <th class="border border-black p-1 text-center font-bold w-[12.5%]">សរុប</th>
                    </tr>
                </thead>
                <tbody id="rcpt-items-body">
                    <tr>
                        <td class="border border-black p-1 text-center">1</td>
                        <td class="border border-black p-1 text-left">
                            <div class="font-bold">សំបុកសសៃសរលាយ</div>
                            <div class="text-[9px] text-gray-700">(1 ប្រអប់ 0.5g = 85$)</div>
                        </td>
                        <td class="border border-black p-1 text-center">1.0</td>
                        <td class="border border-black p-1 text-center">85.00</td>
                        <td class="border border-black p-1 text-center">0%</td>
                        <td class="border border-black p-1 text-center">85.00</td>
                    </tr>
                </tbody>
            </table>

            <!-- Totals Section -->
            <div class="text-[11px] leading-snug mt-2 space-y-0.5">
                <div class="flex justify-between" id="rcpt-subtotal-row" style="display:none;">
                    <span>ប្រាក់សរុប :</span>
                    <span id="rcpt-subtotal">USD 85.00</span>
                </div>
                <div class="flex justify-between" id="rcpt-discount-row" style="display:none;">
                    <span>បញ្ចុះតម្លៃ (<span id="rcpt-disc-pct">0</span>%) :</span>
                    <span id="rcpt-discount">USD 0.00</span>
                </div>
                <div class="flex justify-between font-bold text-[11.5px]">
                    <span>ប្រាក់សរុបចុងក្រោយ :</span>
                    <span id="rcpt-total">USD 85.00</span>
                </div>
                <div class="text-right font-bold text-[10.5px]">
                    <span id="rcpt-total-khr">KHR 340,000</span>
                </div>
                <div class="h-1"></div>
                <div class="flex justify-between">
                    <span>ប្រាក់ទទួល :</span>
                    <span id="rcpt-received">USD 85.00</span>
                </div>
                <div class="flex justify-between font-bold text-[11.5px]">
                    <span>ប្រាក់អាប់ :</span>
                    <span id="rcpt-change">USD 0.00</span>
                </div>
                <div class="text-right font-bold text-[10.5px]" id="rcpt-change-khr-row" style="display:none;">
                    <span id="rcpt-change-khr">KHR 0</span>
                </div>
            </div>

            <!-- Footer Divider -->
            <div class="border-t border-dashed border-black my-3"></div>
            <div class="text-center text-[10.5px]">
                Password WiFi: <span id="rcpt-wifi"></span>
            </div>
        </div>

        <!-- Footer Buttons -->
        <div class="flex items-center justify-between gap-3 mt-6 pt-4 border-t border-gray-800 no-print">
            <button type="button" onclick="closeReceiptModal()" class="px-4 py-2.5 rounded-xl bg-gray-800 text-gray-300 hover:bg-gray-700 hover:text-white font-bold text-sm transition-all cursor-pointer">
                <?= defined('CURRENT_LANG') && CURRENT_LANG === 'km' ? 'រួចរាល់' : 'Done / Close' ?>
            </button>
            <div class="flex items-center gap-2">
                <button type="button" onclick="openReceiptPrintTab()" class="px-3.5 py-2.5 rounded-xl bg-gray-800 text-gray-300 hover:bg-gray-700 hover:text-white font-bold text-sm transition-all cursor-pointer" title="Open in dedicated tab">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                </button>
                <button type="button" onclick="printThermalReceipt()" class="px-5 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-600 active:scale-95 text-white font-extrabold text-sm shadow-lg shadow-emerald-500/25 flex items-center gap-2 transition-all cursor-pointer">
                    <i class="fa-solid fa-print"></i> <?= defined('CURRENT_LANG') && CURRENT_LANG === 'km' ? 'បោះពុម្ពវិក្កយបត្រ' : 'Print Receipt' ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
var _currentReceiptOrderId = null;

function openReceiptModal() {
    var modal = document.getElementById('receiptModal');
    if (modal) {
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
    }
}

function closeReceiptModal() {
    var modal = document.getElementById('receiptModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
    }
    if (window._cpmReloadOnClose) {
        window._cpmReloadOnClose = false;
        window.location.href = 'menu.php';
    }
}

function printThermalReceipt() {
    window.print();
}

function openReceiptPrintTab() {
    if (_currentReceiptOrderId) {
        window.open('receipt_print.php?order_id=' + _currentReceiptOrderId, '_blank', 'width=460,height=720,scrollbars=yes');
    }
}

function showReceiptModalPopup(orderId, autoPrint) {
    if (!orderId) return;
    _currentReceiptOrderId = orderId;

    fetch('api_receipt.php?order_id=' + Number(orderId))
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.success) {
                renderReceiptModal(res);
                if (autoPrint) {
                    setTimeout(function() {
                        printThermalReceipt();
                    }, 400);
                }
            } else {
                console.error('Failed to fetch receipt data:', res ? res.error : 'Unknown error');
                // Fallback to receipt_print.php in window
                openReceiptPrintTab();
            }
        })
        .catch(function(err) {
            console.error('Network error loading receipt:', err);
            openReceiptPrintTab();
        });
}

function printReceipt(orderData) {
    if (!orderData) {
        openReceiptModal();
        return;
    }

    if (typeof orderData === 'number' || typeof orderData === 'string') {
        showReceiptModalPopup(Number(orderData));
        return;
    }

    renderReceiptModal(orderData);
}

function renderReceiptModal(data) {
    if (!data) return;
    _currentReceiptOrderId = data.order_id || null;

    if (document.getElementById('rcpt-shop-name') && data.shop_name) {
        document.getElementById('rcpt-shop-name').innerText = data.shop_name;
    }
    if (document.getElementById('rcpt-shop-location') && data.shop_location) {
        document.getElementById('rcpt-shop-location').innerText = data.shop_location;
    }
    if (document.getElementById('rcpt-shop-phone') && data.shop_phone) {
        document.getElementById('rcpt-shop-phone').innerText = data.shop_phone;
    }
    if (document.getElementById('rcpt-cashier')) {
        document.getElementById('rcpt-cashier').innerText = data.employee_name || 'Staff';
    }
    if (document.getElementById('rcpt-inv-no')) {
        document.getElementById('rcpt-inv-no').innerText = String(data.daily_order_no || data.order_id || '00000001').padStart(8, '0');
    }
    if (document.getElementById('rcpt-customer')) {
        document.getElementById('rcpt-customer').innerText = data.customer_name || 'General Customer';
    }
    if (document.getElementById('rcpt-datetime')) {
        document.getElementById('rcpt-datetime').innerText = data.order_time || data.order_date || 'Today';
    }
    if (document.getElementById('rcpt-payment-method')) {
        document.getElementById('rcpt-payment-method').innerText = data.payment_method || 'Bakong KHQR';
    }

    if (data.items && Array.isArray(data.items)) {
        var tbody = document.getElementById('rcpt-items-body');
        if (tbody) {
            tbody.innerHTML = data.items.map(function(item, idx) {
                var lineTotal = (parseFloat(item.price) || 0) * (parseFloat(item.quantity) || 1);
                var discPct = parseInt(item.promo_percent || 0, 10);
                var subtext = [
                    item.note ? '(' + item.note + ')' : '',
                    item.options_text || ''
                ].filter(Boolean).join(' ');

                return '<tr>' +
                    '<td class="border border-black p-1 text-center font-bold">' + (idx + 1) + '</td>' +
                    '<td class="border border-black p-1 text-left">' +
                        '<div class="font-bold text-[10.5px]">' + escapeHtml(item.product_name) + '</div>' +
                        (subtext ? '<div class="text-[9px] text-gray-700 leading-tight">' + escapeHtml(subtext) + '</div>' : '') +
                    '</td>' +
                    '<td class="border border-black p-1 text-center font-bold">' + parseFloat(item.quantity).toFixed(1) + '</td>' +
                    '<td class="border border-black p-1 text-center">' + parseFloat(item.price).toFixed(2) + '</td>' +
                    '<td class="border border-black p-1 text-center">' + (discPct > 0 ? discPct + '%' : '0%') + '</td>' +
                    '<td class="border border-black p-1 text-center font-bold">' + lineTotal.toFixed(2) + '</td>' +
                '</tr>';
            }).join('');
        }
    }

    var discVal = parseFloat(data.discount) || 0;
    var subRow = document.getElementById('rcpt-subtotal-row');
    var discRow = document.getElementById('rcpt-discount-row');
    if (subRow && discRow) {
        if (discVal > 0) {
            subRow.style.display = 'flex';
            discRow.style.display = 'flex';
        } else {
            subRow.style.display = 'none';
            discRow.style.display = 'none';
        }
    }

    var chUsd = parseFloat(data.change) || 0;
    var chKhr = parseInt(data.change_khr || 0, 10);
    var chKhrRow = document.getElementById('rcpt-change-khr-row');

    if (document.getElementById('rcpt-subtotal')) document.getElementById('rcpt-subtotal').innerText = 'USD ' + (parseFloat(data.subtotal) || 0).toFixed(2);
    if (document.getElementById('rcpt-disc-pct')) document.getElementById('rcpt-disc-pct').innerText = data.discount_percent || '0';
    if (document.getElementById('rcpt-discount')) document.getElementById('rcpt-discount').innerText = 'USD ' + discVal.toFixed(2);
    if (document.getElementById('rcpt-total')) document.getElementById('rcpt-total').innerText = 'USD ' + (parseFloat(data.total) || 0).toFixed(2);
    if (document.getElementById('rcpt-total-khr')) document.getElementById('rcpt-total-khr').innerText = 'KHR ' + (data.total_khr || '0');
    if (document.getElementById('rcpt-received')) document.getElementById('rcpt-received').innerText = data.received_text || ('USD ' + (parseFloat(data.received) || parseFloat(data.total) || 0).toFixed(2));

    if (document.getElementById('rcpt-change')) {
        if (chUsd > 0 && chKhr > 0) {
            document.getElementById('rcpt-change').innerText = 'USD ' + chUsd.toFixed(2);
            if (document.getElementById('rcpt-change-khr')) document.getElementById('rcpt-change-khr').innerText = 'KHR ' + chKhr.toLocaleString();
            if (chKhrRow) chKhrRow.style.display = 'block';
        } else if (chKhr > 0) {
            document.getElementById('rcpt-change').innerText = 'KHR ' + chKhr.toLocaleString();
            if (chKhrRow) chKhrRow.style.display = 'none';
        } else if (chUsd > 0) {
            document.getElementById('rcpt-change').innerText = 'USD ' + chUsd.toFixed(2);
            if (chKhrRow) chKhrRow.style.display = 'none';
        } else {
            document.getElementById('rcpt-change').innerText = 'USD 0.00';
            if (chKhrRow) chKhrRow.style.display = 'none';
        }
    }
    if (document.getElementById('rcpt-wifi')) document.getElementById('rcpt-wifi').innerText = data.wifi_pass || '';

    openReceiptModal();
}

function escapeHtml(str) {
    return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>
