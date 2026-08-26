<?php
require 'auth.php';
require 'config.php';
// Accessible by all authenticated staff

$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if ($order_id <= 0) { header("Location: menu.php"); exit; }

// How this tab was opened decides the secondary back link:
//   from=paylater  → reached by adding items from the Pay Later queue → Back to Pay Later
//   (otherwise)    → a fresh pay-later order taken at the menu        → Back to Menu
$from_paylater = ($_GET['from'] ?? '') === 'paylater';
$back_href     = $from_paylater ? 'find_order.php?tab=paylater' : 'menu.php';
$back_label    = $from_paylater ? 'Back to Pay Later' : 'Back to Menu';

$stmt = $conn->prepare("
    SELECT order_id, order_id AS daily_order_no, 'Guest' AS customer_name, total, 0 AS is_open, order_id AS token_number, 0 AS promotion_discount,
           'drink_in' AS order_type, '' AS table_number
    FROM orders
    WHERE order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) { header("Location: menu.php"); exit; }

// Clear session cart now that order is confirmed
$_SESSION['cart'] = [];
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>" data-lang="<?= current_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>(function(){if(localStorage.getItem('theme')==='dark'){document.documentElement.setAttribute('data-theme','dark');}})();</script>
    <title>Pay Later | Bird's Nest Coffee</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ── RESET & ROOT ── */
        :root {
            --bg-body: #efe8df;
            --bg-card: #fffdf9;
            --surface: #f7f1e8;
            --border: #e6dccd;
            --border-hover: #d4c4ac;
            --orange: #d1904b;
            --orange-dark: #a0702a;
            --text-primary: #1a1410;
            --text-secondary: #5a4a3a;
            --text-muted: #9a8070;
            --text-inverse: #ffffff;
            --purple: #9b59b6;
            --purple-dark: #7d3c98;
            --purple-light: #b07cc6;
            --danger: #c0392b;
            --danger-glow: rgba(192,57,43,0.45);
            --shadow-md: 0 10px 40px rgba(90,60,20,0.12);
            --shadow-lg: 0 18px 60px rgba(90,60,20,0.18);
            --shadow-purple: 0 8px 28px rgba(123,60,152,0.30);
            --ease: cubic-bezier(0.22,1,0.36,1);
            --radius: 20px;
            --font-display: 'Poppins', 'Kantumruy Pro', 'Noto Sans Khmer', sans-serif;
            --font-ui: 'Poppins', 'Kantumruy Pro', 'Noto Sans Khmer', sans-serif;
            --font-mono: 'Poppins', 'Kantumruy Pro', 'Noto Sans Khmer', sans-serif;
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
            font-family: var(--font-ui);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background-image:
                radial-gradient(ellipse at 18% 0%, rgba(155,89,182,0.07) 0%, transparent 55%),
                radial-gradient(ellipse at 82% 100%, rgba(209,144,75,0.06) 0%, transparent 55%);
        }

        /* ── Tab Slip Card ── */
        .tab-card {
            position: relative;
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 30px 32px 30px;
            max-width: 420px;
            width: 100%;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-md);
            animation: cardIn 0.6s var(--ease) both;
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translateY(22px) scale(0.98); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* top accent — static, single hairline */
        .tab-card::before {
            content: '';
            position: absolute;
            top: 0; left: 24px; right: 24px;
            height: 3px;
            border-radius: 0 0 3px 3px;
            background: linear-gradient(90deg, var(--purple), var(--purple-light) 60%, var(--orange));
        }

        /* ── Emblem (single, merged cup + check) ── */
        .emblem {
            position: relative;
            width: 60px; height: 60px;
            margin: 6px auto 14px;
            border-radius: 18px;
            background: linear-gradient(150deg, rgba(155,89,182,0.16), rgba(155,89,182,0.06));
            border: 1px solid rgba(155,89,182,0.20);
            display: flex; align-items: center; justify-content: center;
            animation: emblemIn 0.6s var(--ease) 0.08s both;
        }
        .emblem > i {
            font-size: 26px;
            color: var(--purple);
            transform-origin: 52% 78%;
            animation: mugBob 3.2s ease-in-out 0.7s infinite;
        }
        .emblem .check {
            position: absolute;
            right: -7px; bottom: -7px;
            width: 24px; height: 24px;
            border-radius: 50%;
            background: var(--purple);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px;
            border: 3px solid var(--bg-card);
            box-shadow: var(--shadow-purple);
            animation: checkPop 0.5s var(--ease) 0.55s both;
        }
        @keyframes emblemIn {
            from { opacity: 0; transform: scale(0.6); }
            to   { opacity: 1; transform: scale(1); }
        }
        @keyframes mugBob {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50%      { transform: translateY(-2.5px) rotate(-6deg); }
        }
        @keyframes checkPop {
            0%   { transform: scale(0); opacity: 0; }
            60%  { transform: scale(1.25); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        /* ── Title ── */
        .tab-title {
            text-align: center;
            font-family: var(--font-display);
            font-size: 25px;
            font-weight: 700;
            letter-spacing: -0.5px;
            color: var(--text-primary);
        }
        .tab-sub {
            text-align: center;
            color: var(--text-secondary);
            font-size: 12.5px;
            line-height: 1.45;
            margin: 4px auto 20px;
            max-width: 30ch;
        }

        /* shared fade for staged content */
        .reveal { animation: fadeUp 0.6s var(--ease) both; }
        .reveal.d1 { animation-delay: 0.12s; }
        .reveal.d2 { animation-delay: 0.18s; }
        .reveal.d3 { animation-delay: 0.24s; }
        .reveal.d4 { animation-delay: 0.30s; }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Meta row (order / customer) ── */
        .meta-row {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }
        .meta {
            flex: 1;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 11px 14px;
            display: flex;
            flex-direction: column;
            gap: 3px;
            min-width: 0;
        }
        .meta-label {
            font-size: 9.5px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
            color: var(--text-muted);
        }
        .meta-val {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .mono { font-family: var(--font-mono); letter-spacing: -0.5px; }
        .meta-val.accent { color: var(--orange); }

        /* ── Amount due — the hero ── */
        .due {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 18px;
            margin-bottom: 14px;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(155,89,182,0.10), rgba(155,89,182,0.04));
            border: 1px solid rgba(155,89,182,0.22);
        }
        .due-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            font-weight: 600;
            color: var(--text-secondary);
        }
        .due-amount {
            font-size: 30px;
            font-weight: 700;
            color: var(--purple);
            line-height: 1;
        }

        /* ── Status badge ── */
        .status-row {
            display: flex;
            justify-content: center;
            margin-bottom: 6px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 16px;
            border-radius: 50px;
            background: rgba(209,144,75,0.12);
            color: var(--orange-dark);
            border: 1px solid rgba(209,144,75,0.25);
            font-size: 11.5px;
            font-weight: 600;
        }
        .badge i {
            font-size: 11px;
            animation: hourglassTurn 2.6s ease-in-out infinite;
        }
        @keyframes hourglassTurn {
            0%, 55%   { transform: rotate(0deg); }
            70%, 100% { transform: rotate(180deg); }
        }

        /* ── Receipt tear (signature) ── */
        .tear {
            position: relative;
            height: 22px;
            margin: 4px -32px 14px;
        }
        .tear::before {
            content: '';
            position: absolute;
            top: 50%; left: 26px; right: 26px;
            border-top: 2px dashed var(--border-hover);
        }
        .tear .notch {
            position: absolute;
            top: 50%;
            width: 22px; height: 22px;
            border-radius: 50%;
            background: var(--bg-body);
            border: 1px solid var(--border);
            transform: translateY(-50%);
        }
        .tear .notch.left  { left: -11px; }
        .tear .notch.right { right: -11px; }

        /* ── Actions ── */
        .actions {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .btn {
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid transparent;
            font-weight: 600;
            font-size: 13.5px;
            cursor: pointer;
            transition: transform 0.18s var(--ease), box-shadow 0.18s var(--ease), background 0.18s, border-color 0.18s, color 0.18s, text-shadow 0.18s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            font-family: var(--font-ui);
            letter-spacing: 0.2px;
        }
        .btn:active { transform: translateY(0) scale(0.99); }
        .btn:focus-visible { outline: 2px solid var(--purple); outline-offset: 2px; }

        .btn-primary {
            background: linear-gradient(135deg, var(--purple), var(--purple-dark));
            color: var(--text-inverse);
            box-shadow: var(--shadow-purple);
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 34px rgba(123,60,152,0.40); }

        .btn-add {
            background: rgba(209,144,75,0.12);
            color: var(--orange-dark);
            border-color: rgba(209,144,75,0.30);
        }
        .btn-add:hover {
            transform: translateY(-2px);
            background: linear-gradient(135deg, var(--orange), var(--orange-dark));
            border-color: var(--orange);
            color: #fff;
            text-shadow: 0 0 8px rgba(255,255,255,0.45);
        }

        .btn-print {
            background: transparent;
            color: var(--text-secondary);
            border-color: var(--border);
        }
        .btn-print:hover {
            transform: translateY(-2px);
            background: rgba(192,57,43,0.10);
            border-color: var(--danger);
            color: var(--danger);
            box-shadow: 0 8px 24px var(--danger-glow);
        }

        .btn-secondary {
            background: transparent;
            color: var(--text-muted);
            border-color: transparent;
            font-size: 12.5px;
            padding: 8px;
        }
        .btn-secondary:hover { color: var(--text-primary); }

        /* ── Responsive ── */
        @media (max-width: 440px) {
            .tab-card { padding: 24px 20px; }
            .tear { margin-left: -20px; margin-right: -20px; }
            .due-amount { font-size: 26px; }
            .tab-title { font-size: 22px; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, .tab-card, .emblem, .reveal { animation: none !important; transition: none !important; }
        }

        /* ── DARK THEME ── */
        [data-theme="dark"] {
            --bg-body: #0c0c0c;
            --bg-card: #161616;
            --surface: #1c1c1c;
            --border: #262626;
            --border-hover: #3a3a3a;
            --text-primary: #f0f0f0;
            --text-secondary: #b5b5b5;
            --text-muted: #777;
            --purple-light: #c79ad8;
            --danger: #ff6b5e;
            --danger-glow: rgba(255,107,94,0.30);
            --shadow-md: 0 10px 40px rgba(0,0,0,0.6);
            --shadow-lg: 0 18px 60px rgba(0,0,0,0.7);
        }
        [data-theme="dark"] body { background-image:
            radial-gradient(ellipse at 18% 0%, rgba(155,89,182,0.10) 0%, transparent 55%),
            radial-gradient(ellipse at 82% 100%, rgba(209,144,75,0.06) 0%, transparent 55%); }
        [data-theme="dark"] .due-amount { color: var(--purple-light); }
        [data-theme="dark"] .emblem > i { color: var(--purple-light); }
        [data-theme="dark"] .tear .notch { background: var(--bg-body); }
    </style>
</head>
<body>

<div class="tab-card">

    <!-- Emblem -->
    <div class="emblem">
        <i class="fa-solid fa-mug-hot"></i>
        <span class="check"><i class="fa-solid fa-check"></i></span>
    </div>

    <h1 class="tab-title">Tab opened</h1>
    <p class="tab-sub">Order recorded. Collect payment when the customer is ready.</p>

    <!-- Order / Customer -->
    <div class="meta-row reveal d1">
        <div class="meta">
            <span class="meta-label">Order</span>
            <span class="meta-val mono accent">#<?php echo (int)$order['daily_order_no']; ?></span>
        </div>
        <div class="meta">
            <span class="meta-label">Customer</span>
            <span class="meta-val"><?php echo htmlspecialchars($order['customer_name']); ?></span>
        </div>
        <?php if (!empty($order['table_number'])): ?>
        <div class="meta">
            <span class="meta-label">Stand</span>
            <span class="meta-val mono accent"><?php echo htmlspecialchars($order['table_number']); ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Amount due (hero) -->
    <div class="due reveal d2">
        <span class="due-label">Amount due</span>
        <span class="due-amount mono">$<?php echo number_format((float)$order['total'], 2); ?></span>
    </div>

    <!-- Status -->
    <div class="status-row reveal d3">
        <span class="badge"><i class="fa-solid fa-hourglass-half"></i> Preparing</span>
    </div>

    <!-- Receipt tear -->
    <div class="tear">
        <span class="notch left"></span>
        <span class="notch right"></span>
    </div>

    <!-- Actions -->
    <div class="actions reveal d4">
        <a href="find_order.php?tab=paylater" class="btn btn-primary">
            <i class="fa-solid fa-list"></i> Pay Later Queue
        </a>
        <?php if ($order['is_open'] == 1): ?>
        <a href="add_to_existing_order.php?order_id=<?php echo $order_id; ?>" class="btn btn-add">
            <i class="fa-solid fa-plus"></i> Add More Items
        </a>
        <?php endif; ?>
        <a href="receipt_paylater.php?order_id=<?php echo $order_id; ?>" target="_blank" class="btn btn-print">
            <i class="fa-solid fa-file-pdf"></i> Print Receipt
        </a>
        <a href="<?= $back_href ?>" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i> <?= $back_label ?>
        </a>
    </div>
</div>

</body>
</html>