<?php
require 'config.php';

$order_id = (int)($_GET['order_id'] ?? 0);
$action = $_GET['action'] ?? '';

if ($order_id <= 0) {
    die("Invalid order.");
}

/* ================================
   AJAX STATUS CHECK
================================ */
if ($action === 'status') {
    header('Content-Type: application/json');

    $stmt = $conn->prepare("
        SELECT order_id, 'Guest' AS customer_name, 'Completed' AS status, 0 AS is_open, total, order_date, order_id AS daily_order_no
        FROM orders
        WHERE order_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result->fetch_assoc();

    echo json_encode([
        "found" => $order ? true : false,
        /* Fulfilment state, not the money state. A customer tracking their drink was
           shown "Paid", which answers a question they did not ask and hides the one
           they did — is it ready? A settled order now reads "Order Ready!". */
        "status" => $order ? order_board_state($order['status'], 0) : 'Unknown',
        "customer_name" => $order['customer_name'] ?? '',
        "total" => $order['total'] ?? 0,
        "order_date" => $order['order_date'] ?? ''
    ]);
    exit;
}

/* ================================
   FIRST PAGE LOAD
=============================== */
$stmt = $conn->prepare("
    SELECT order_id, 'Guest' AS customer_name, 'Completed' AS status, 0 AS is_open, total, order_date, order_id AS daily_order_no
    FROM orders
    WHERE order_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$result = $stmt->get_result();
$order = $result->fetch_assoc();

if (!$order) {
    die("Order not found.");
}

/* This page answers one question — is my drink ready? — so it shows the fulfilment
   state, not the money state. Overwritten here so every label, colour and icon below
   reads the translated value without each having to remember to convert. A settled
   order used to display "💳 Paid", which is true and useless to the customer. */
$order['status'] = order_board_state($order['status'], $order['is_open']);

// ── Check if this is a redirect from payment ──
$from_payment = isset($_GET['from_payment']) ? true : false;

// ── Function to get status label with icon ──
function statusLabel($status) {
    switch ($status) {
        case 'Preparing': return 'Preparing';
        case 'Completed': return 'Order Ready!';
        case 'PendingPayment': return 'Pending Payment';
        case 'Paid': return 'Paid';
        case 'Cancelled': return 'Cancelled';
        case 'Refunded': return 'Refunded';
        default: return $status;
    }
}

// ── Function to get status color ──
function statusColor($status) {
    switch ($status) {
        case 'Preparing': return '#d1904b';
        case 'Completed': return '#55e087';
        case 'PendingPayment': return '#ffb74d';
        case 'Paid': return '#4dd0e1';
        case 'Cancelled': return '#e57373';
        case 'Refunded': return '#ce93d8';
        default: return '#888888';
    }
}

// ── Function to get status background ──
function statusBg($status) {
    switch ($status) {
        case 'Preparing': return 'rgba(209,144,75,0.10)';
        case 'Completed': return 'rgba(85,224,135,0.10)';
        case 'PendingPayment': return 'rgba(255,183,77,0.10)';
        case 'Paid': return 'rgba(77,208,225,0.10)';
        case 'Cancelled': return 'rgba(229,115,115,0.10)';
        case 'Refunded': return 'rgba(206,147,216,0.10)';
        default: return 'rgba(136,136,136,0.10)';
    }
}
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>" data-lang="<?= current_lang() ?>">
<head>
    <script>(function(){var t=localStorage.getItem('theme');if(t==='dark')document.documentElement.setAttribute('data-theme','dark');})();</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Tracking | Bird's Nest Coffee</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap" rel="stylesheet">
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
            --warning: #ffb74d;
            --danger: #e57373;
            --info: #4dd0e1;
            --purple: #ce93d8;
            --shadow-sm: 0 2px 10px rgba(90,60,20,0.07);
            --shadow-md: 0 6px 28px rgba(90,60,20,0.11);
            --shadow-lg: 0 12px 48px rgba(90,60,20,0.16);
            --shadow-orange-sm: 0 4px 16px rgba(209,144,75,0.22);
            --shadow-orange-md: 0 8px 32px rgba(209,144,75,0.28);
            --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
            --radius: 16px;
        }

        [data-theme="dark"] {
            --bg-body: #0b0b0b;
            --bg-card: #121212;
            --bg-card-hover: #1a1a1a;
            --border: #1f1f1f;
            --border-hover: #2a2a2a;
            --text-primary: #f5f5f5;
            --text-secondary: #aaaaaa;
            --text-muted: #666666;
            --shadow-sm: 0 2px 10px rgba(0,0,0,0.4);
            --shadow-md: 0 6px 28px rgba(0,0,0,0.5);
            --shadow-lg: 0 12px 48px rgba(0,0,0,0.6);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body, input, select, textarea, button, table {
            font-family: 'Poppins', 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        :lang(km), [data-lang="km"], html[lang="km"], html[lang="km"] * {
            font-family: 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', 'Khmer OS Siemreap', 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
        }
        html[lang="km"] .fa, html[lang="km"] [class*="fa-"], html[lang="km"] i {
            font-family: 'Font Awesome 6 Free', 'FontAwesome' !important;
        }
        html[lang="km"] .fa-brands, html[lang="km"] [class*="fa-brands"] {
            font-family: 'Font Awesome 6 Brands', 'FontAwesome' !important;
        }

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

        /* ── CONTAINER ── */
        .container {
            width: 100%;
            max-width: 520px;
            animation: slideUp 0.6s ease;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ── CARD ── */
        .card {
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 32px 36px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            transition: var(--transition);
            animation: cardFadeIn 0.7s ease both;
        }

        @keyframes cardFadeIn {
            from { opacity: 0; transform: translateY(30px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .card:hover {
            border-color: var(--border-hover);
            box-shadow: var(--shadow-lg);
        }

        .card::before {
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

        /* ── HEADER ── */
        .header {
            text-align: center;
            margin-bottom: 24px;
            animation: fadeInUp 0.7s ease 0.1s both;
        }

        .header .order-icon {
            font-size: 48px;
            color: var(--orange);
            margin-bottom: 8px;
            display: block;
        }

        .header h1 {
            margin: 0;
            color: var(--text-primary);
            font-size: 28px;
            font-weight: 700;
        }

        .header h1 span {
            color: var(--orange);
        }

        .header .subtitle {
            color: var(--text-secondary);
            font-size: 14px;
            margin-top: 4px;
        }

        .header .live-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
            padding: 4px 16px;
            background: rgba(85,224,135,0.08);
            border-radius: 50px;
            font-size: 12px;
            color: var(--success);
            border: 1px solid rgba(85,224,135,0.15);
        }

        .header .live-indicator .dot {
            width: 8px;
            height: 8px;
            background: var(--success);
            border-radius: 50%;
            animation: pulse-dot 1.5s infinite;
        }

        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(0.8); }
        }

        /* ── ORDER INFO ── */
        .order-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 20px;
        }

        .order-info .info-item {
            background: rgba(255,255,255,0.03);
            border-radius: 12px;
            padding: 14px;
            text-align: center;
            border: 1px solid var(--border);
            transition: var(--transition);
        }

        .order-info .info-item:hover {
            background: rgba(255,255,255,0.05);
            border-color: var(--border-hover);
        }

        .order-info .info-item .label {
            color: var(--text-secondary);
            font-size: 12px;
            font-weight: 500;
            display: block;
            margin-bottom: 4px;
        }

        .order-info .info-item .value {
            color: var(--text-primary);
            font-size: 16px;
            font-weight: 600;
        }

        .order-info .info-item .value.amount {
            color: var(--orange);
            font-size: 20px;
        }

        .order-info .info-item .value.customer {
            color: var(--text-primary);
            font-weight: 700;
        }

        /* ── STATUS BOX ── */
        .status-section {
            margin-bottom: 16px;
        }

        .status-box {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 16px;
            border-radius: 12px;
            font-size: 20px;
            font-weight: 600;
            transition: var(--transition);
            border: 1px solid transparent;
        }

        .status-box .status-icon {
            font-size: 28px;
        }

        .status-box .status-text {
            font-size: 18px;
        }

        /* ── REDIRECT COUNTDOWN ── */
        .redirect-section {
            margin-top: 12px;
            padding: 12px 16px;
            background: rgba(209,144,75,0.06);
            border-radius: 12px;
            border: 1px solid rgba(209,144,75,0.12);
            text-align: center;
            animation: fadeInUp 0.7s ease 0.4s both;
        }

        .redirect-section .countdown {
            color: var(--text-secondary);
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .redirect-section .countdown .timer {
            color: var(--orange);
            font-weight: 700;
            font-size: 16px;
        }

        .redirect-section .redirect-message {
            color: var(--orange);
            font-weight: 500;
            font-size: 14px;
        }

        /* ── FOOTER ── */
        .footer {
            margin-top: 16px;
            text-align: center;
            padding-top: 12px;
            border-top: 1px solid var(--border);
        }

        .footer .hint {
            color: var(--text-secondary);
            font-size: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .footer .hint i {
            color: var(--orange);
        }

        /* ── Animations ── */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ── THEME TOGGLE ── */
        .theme-btn {
            position: fixed;
            top: 16px;
            right: 16px;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: 1px solid var(--border);
            background: var(--bg-card);
            color: var(--text-secondary);
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            z-index: 100;
            box-shadow: var(--shadow-sm);
        }

        .theme-btn:hover {
            border-color: var(--orange);
            color: var(--orange);
        }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            body { padding: 12px; }
            .card { padding: 24px 20px; }
            .header h1 { font-size: 24px; }
            .header .order-icon { font-size: 36px; }
            .order-info { grid-template-columns: 1fr; gap: 8px; }
            .order-info .info-item { padding: 10px; }
            .order-info .info-item .value { font-size: 14px; }
            .order-info .info-item .value.amount { font-size: 18px; }
            .status-box { flex-direction: column; padding: 12px; font-size: 18px; }
            .status-box .status-text { font-size: 16px; }
            .status-box .status-icon { font-size: 24px; }
            .redirect-section { padding: 10px; }
            .redirect-section .countdown { font-size: 13px; }
            .redirect-section .countdown .timer { font-size: 14px; }
        }

        @media (max-width: 480px) {
            .card { padding: 16px 14px; }
            .header h1 { font-size: 20px; }
            .header .order-icon { font-size: 28px; }
            .status-box { font-size: 16px; }
            .status-box .status-text { font-size: 14px; }
        }
    </style>
</head>
<body>

<button class="theme-btn" onclick="toggleTheme()" id="themeBtn" title="Toggle theme">
    <i class="fa-solid fa-moon" id="themeIcon"></i>
</button>

<div class="container">
    <div class="card">
        <!-- Header -->
        <div class="header">
            <i class="fa-solid fa-receipt order-icon"></i>
            <h1>Order <span>#<?php echo (int)$order['daily_order_no']; ?></span></h1>
            <p class="subtitle">Live order tracking</p>
            <div class="live-indicator">
                <span class="dot"></span>
                Live
            </div>
        </div>

        <!-- Order Info -->
        <div class="order-info">
            <div class="info-item">
                <span class="label">Customer</span>
                <div class="value customer" id="customer_name"><?php echo htmlspecialchars($order['customer_name']); ?></div>
            </div>
            <div class="info-item">
                <span class="label">Total</span>
                <div class="value amount" id="total">$<?php echo number_format((float)$order['total'], 2); ?></div>
            </div>
            <div class="info-item">
                <span class="label">Order Time</span>
                <div class="value" id="order_date"><?php echo date("M d, Y g:i A", strtotime($order['order_date'])); ?></div>
            </div>
            <div class="info-item">
                <span class="label">Status</span>
                <div class="value" id="status_label"><?php echo statusLabel($order['status']); ?></div>
            </div>
        </div>

        <!-- Status Box -->
        <div class="status-section">
            <div id="statusBox" class="status-box" style="background: <?php echo statusBg($order['status']); ?>; border-color: <?php echo statusColor($order['status']); ?>;">
                <span class="status-icon" id="statusIcon" style="color: <?php echo statusColor($order['status']); ?>;">
                    <?php
                        if ($order['status'] === 'Preparing') echo '🔄';
                        elseif ($order['status'] === 'Completed') echo '✅';
                        elseif ($order['status'] === 'PendingPayment') echo '⏳';
                        elseif ($order['status'] === 'Paid') echo '💳';
                        elseif ($order['status'] === 'Cancelled') echo '❌';
                        elseif ($order['status'] === 'Refunded') echo '↩️';
                        else echo '📦';
                    ?>
                </span>
                <span class="status-text" id="statusText" style="color: <?php echo statusColor($order['status']); ?>;">
                    <?php echo statusLabel($order['status']); ?>
                </span>
            </div>
        </div>

        <!-- Redirect Countdown (shown when coming from payment) -->
        <?php if ($from_payment): ?>
        <div class="redirect-section" id="redirectSection">
            <div class="countdown">
                <i class="fa-solid fa-clock"></i>
                Redirecting to menu in <span class="timer" id="countdownTimer">3</span> seconds...
            </div>
            <div class="redirect-message">
                <i class="fa-solid fa-check-circle"></i> Payment confirmed!
            </div>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="footer">
            <div class="hint">
                <i class="fa-solid fa-rotate"></i>
                This page updates automatically
            </div>
        </div>
    </div>
</div>

<script>
const orderId = <?php echo (int)$order['order_id']; ?>;

// ── Status Label Mapping ──
function getStatusLabel(status) {
    switch (status) {
        case 'Preparing': return 'Preparing';
        case 'Completed': return 'Order Ready!';
        case 'PendingPayment': return 'Pending Payment';
        case 'Paid': return 'Paid';
        case 'Cancelled': return 'Cancelled';
        case 'Refunded': return 'Refunded';
        default: return status;
    }
}

// ── Status Color Mapping ──
function getStatusColor(status) {
    switch (status) {
        case 'Preparing': return '#d1904b';
        case 'Completed': return '#55e087';
        case 'PendingPayment': return '#ffb74d';
        case 'Paid': return '#4dd0e1';
        case 'Cancelled': return '#e57373';
        case 'Refunded': return '#ce93d8';
        default: return '#888888';
    }
}

// ── Status Background Mapping ──
function getStatusBg(status) {
    switch (status) {
        case 'Preparing': return 'rgba(209,144,75,0.10)';
        case 'Completed': return 'rgba(85,224,135,0.10)';
        case 'PendingPayment': return 'rgba(255,183,77,0.10)';
        case 'Paid': return 'rgba(77,208,225,0.10)';
        case 'Cancelled': return 'rgba(229,115,115,0.10)';
        case 'Refunded': return 'rgba(206,147,216,0.10)';
        default: return 'rgba(136,136,136,0.10)';
    }
}

// ── Countdown: 3 seconds ──
<?php if ($from_payment): ?>
(function startCountdown() {
    const SECONDS = 3;
    const deadline = Date.now() + SECONDS * 1000;
    const timerEl  = document.getElementById('countdownTimer');

    const tick = setInterval(() => {
        const remaining = Math.ceil((deadline - Date.now()) / 1000);

        if (timerEl) timerEl.textContent = Math.max(remaining, 0);

        if (remaining <= 0) {
            clearInterval(tick);
            window.location.href = 'menu.php?payment=success';
        }
    }, 250);
})();
<?php endif; ?>

// ── Update Status (polling) ──
async function updateStatus() {
    try {
        const r = await fetch(`track_order.php?action=status&order_id=${orderId}`, {
            cache: "no-store"
        });
        const data = await r.json();

        if (!data.found) return;

        // Update info fields
        document.getElementById("customer_name").textContent = data.customer_name;
        document.getElementById("total").textContent = '$' + Number(data.total).toFixed(2);
        document.getElementById("order_date").textContent = new Date(data.order_date).toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });

        // Update status box
        const statusBox      = document.getElementById("statusBox");
        const statusTextEl   = document.getElementById("statusText");
        const statusIconEl   = document.getElementById("statusIcon");
        const statusLabelEl  = document.getElementById("status_label");

        statusBox.style.background      = getStatusBg(data.status);
        statusBox.style.borderColor     = getStatusColor(data.status);
        statusTextEl.style.color        = getStatusColor(data.status);
        statusIconEl.style.color        = getStatusColor(data.status);
        statusTextEl.textContent        = getStatusLabel(data.status);
        statusLabelEl.textContent       = getStatusLabel(data.status);

        const iconMap = {
            'Preparing':      '🔄',
            'Completed':      '✅',
            'PendingPayment': '⏳',
            'Paid':           '💳',
            'Cancelled':      '❌',
            'Refunded':       '↩️'
        };
        statusIconEl.textContent = iconMap[data.status] || '📦';

    } catch (err) {
        console.error("Status update failed:", err);
    }
}

// ── Start polling ──
setInterval(updateStatus, 3000);
updateStatus();

// ── Theme ──
function applyTheme(theme) {
    const icon = document.getElementById('themeIcon');
    if (theme === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
        if (icon) icon.className = 'fa-solid fa-sun';
    } else {
        document.documentElement.removeAttribute('data-theme');
        if (icon) icon.className = 'fa-solid fa-moon';
    }
}

function toggleTheme() {
    const current = document.documentElement.getAttribute('data-theme');
    const next = current === 'dark' ? 'light' : 'dark';
    localStorage.setItem('theme', next);
    applyTheme(next);
}

// Sync icon on load
(function() {
    const saved = localStorage.getItem('theme');
    applyTheme(saved === 'dark' ? 'dark' : 'light');
})();

window.addEventListener('storage', function(e) {
    if (e.key === 'theme') applyTheme(e.newValue === 'dark' ? 'dark' : 'light');
});
</script>

</body>
</html>