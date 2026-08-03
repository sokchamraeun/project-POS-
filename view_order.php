<?php
require 'auth.php';
require 'config.php';

date_default_timezone_set("Asia/Phnom_Penh");

$now = new DateTime();
if ((int)$now->format("H") < 6) {
    $business_date = $now->modify("-1 day")->format("Y-m-d");
} else {
    $business_date = $now->format("Y-m-d");
}

$action = $_GET['action'] ?? "";

$_flash_welcome = !empty($_SESSION['flash_welcome']); unset($_SESSION['flash_welcome']);
unset($_SESSION['flash_stock_alert']); // not applicable on this page

// Greeting + role display
$_hour = (int)(new DateTime())->format('H');
$_greeting = $_hour < 12 ? 'Good morning' : ($_hour < 18 ? 'Good afternoon' : 'Good evening');
$_vo_username = htmlspecialchars($_SESSION['username'] ?? 'User', ENT_QUOTES, 'UTF-8');
$_vo_role    = $_SESSION['role'] ?? 'staff';
$_all_roles  = [];
$_ar_res = $conn->query("SELECT slug, name, color, icon FROM roles");
while ($_ar = $_ar_res->fetch_assoc()) $_all_roles[$_ar['slug']] = $_ar;
$_role_label = $_all_roles[$_vo_role]['name']  ?? ucfirst(str_replace('_', ' ', $_vo_role));
$_role_color = $_all_roles[$_vo_role]['color'] ?? '#888888';
$_date_str    = date('l, d F Y');

/* ── MILK OPTIONS (admin-managed via manage_milk.php) ──
   The remake modal used to hard-code this list, so anything added in Manage Milk was
   invisible here and a retired option stayed selectable. Mirrors menu.php exactly. */
$_remake_milk = [];
$_mk = $conn->query("SELECT name FROM milk_options WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
if ($_mk) while ($_m = $_mk->fetch_assoc()) $_remake_milk[] = $_m['name'];

/* Add-ons the barista can toggle on a remake, keyed by product. Mirrors the ordering
   path's gate (add_to_cart.php): only add-ons assigned to that product, in a category
   that offers add-ons. Offering the whole library here would let a remake put Extra Shot
   on a juice that can't be ordered with one. */
$_remake_addons = [];
$_ad = $conn->query("
    SELECT pa.product_id, a.id, a.name, a.price
    FROM product_addons pa
    JOIN addons a     ON a.id = pa.addon_id
    JOIN products pr  ON pr.product_id = pa.product_id
    JOIN categories c ON c.slug = pr.category
    WHERE a.is_active = 1 AND c.offer_addons = 1
    ORDER BY a.display_order ASC, a.id ASC
");
if ($_ad) while ($_a = $_ad->fetch_assoc()) {
    $_remake_addons[(int)$_a['product_id']][] = [
        'id'    => (int)$_a['id'],
        'name'  => $_a['name'],
        'price' => (float)$_a['price'],
    ];
}

// Clock-in status
$_is_clocked_in = false;
$_clock_since   = null;
$_att_check = $conn->query("SHOW TABLES LIKE 'attendance'");
if ($_att_check && $_att_check->num_rows > 0) {
    $_cs = $conn->prepare("SELECT clock_in FROM attendance WHERE user_id = ? AND date = CURDATE() AND clock_out IS NULL ORDER BY clock_in DESC LIMIT 1");
    $_cs->bind_param('i', $_SESSION['user_id']);
    $_cs->execute();
    $_crow = $_cs->get_result()->fetch_assoc();
    if ($_crow) { $_is_clocked_in = true; $_clock_since = date('g:i A', strtotime($_crow['clock_in'])); }
}

// Fetch active announcements
$_ann_check = $conn->query("SHOW TABLES LIKE 'announcements'");
$_announcements = [];
if ($_ann_check && $_ann_check->num_rows > 0) {
    $_ann_res = $conn->query("SELECT id, title, message, type FROM announcements WHERE is_active = 1 AND (expires_at IS NULL OR expires_at >= CURDATE()) AND (starts_at IS NULL OR starts_at <= CURDATE()) ORDER BY created_at DESC");
    if ($_ann_res) $_announcements = $_ann_res->fetch_all(MYSQLI_ASSOC);
}

// ── Define Socket URL in one place ──
if (!defined('SOCKET_URL')) {
    define('SOCKET_URL', 'http://localhost:3000');
}
// Check if socket server is reachable (suppress browser console errors when offline)
$_socketAvailable = false;
$_fp = @fsockopen('localhost', 3000, $_errno, $_errstr, 0.3);
if ($_fp) { $_socketAvailable = true; fclose($_fp); }

/* ===============================
   MAIN PAGE
================================ */
if ($action === ""):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bird's Nest Coffee — Orders</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script>(function(){try{if(localStorage.getItem("theme")==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    
    <style>
    /* ── RESET & ROOT ── */
    :root {
        --accent: #d1904b;
        --accent-light: #e8b87a;
        --accent-dark: #a0702a;
        --bg: #0c0c0c;
        --bg-card: #121212;
        --bg-card-hover: #181818;
        --border: #1f1f1f;
        --border-hover: #2a2a2a;
        --text: #f5f5f5;
        --text-muted: #888888;
        --text-light: #ffffff;
        --success: #55e087;
        --warning: #f39c12;
        --danger: #ff5c5c;
        
        /* ── Shadow System ── */
        --shadow-sm: 0 2px 8px rgba(0,0,0,0.3);
        --shadow-md: 0 4px 20px rgba(0,0,0,0.4);
        --shadow-lg: 0 8px 40px rgba(0,0,0,0.5);
        --shadow-accent: 0 4px 20px rgba(209, 144, 75, 0.15);
        
        /* ── Transitions ── */
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    * { 
        box-sizing: border-box; 
        margin: 0; 
        padding: 0; 
    }

    /* ── PREMIUM DARK BACKGROUND ── */
    body {
        background-color: #09090b;
        background-image:
            radial-gradient(ellipse 90% 60% at 15% -10%, rgba(80,80,120,0.10) 0%, transparent 55%),
            radial-gradient(ellipse 70% 60% at 85% 110%, rgba(60,60,100,0.08) 0%, transparent 55%),
            radial-gradient(ellipse 50% 50% at 50% 50%, rgba(209,144,75,0.03) 0%, transparent 60%),
            linear-gradient(rgba(255,255,255,0.012) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255,255,255,0.012) 1px, transparent 1px);
        background-size: auto, auto, auto, 72px 72px, 72px 72px;
        background-attachment: fixed;
        font-family: 'Poppins', sans-serif;
        color: var(--text);
        margin: 0;
        padding: 40px;
        min-height: 100vh;
        position: relative;
        overflow-x: hidden;
    }

    /* ── Coffee Steam Animation ── */
    .steam-container {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        pointer-events: none;
        z-index: 0;
        overflow: hidden;
    }

    .steam {
        position: absolute;
        bottom: -50px;
        width: 20px;
        height: 20px;
        background: rgba(200, 200, 230, 0.025);
        border-radius: 50%;
        filter: blur(24px);
        animation: rise linear infinite;
    }

    .steam:nth-child(1) {
        left: 10%;
        width: 40px;
        height: 40px;
        animation-duration: 12s;
        animation-delay: 0s;
    }
    .steam:nth-child(2) {
        left: 20%;
        width: 30px;
        height: 30px;
        animation-duration: 15s;
        animation-delay: 2s;
    }
    .steam:nth-child(3) {
        left: 35%;
        width: 50px;
        height: 50px;
        animation-duration: 18s;
        animation-delay: 4s;
    }
    .steam:nth-child(4) {
        left: 50%;
        width: 35px;
        height: 35px;
        animation-duration: 14s;
        animation-delay: 1s;
    }
    .steam:nth-child(5) {
        left: 65%;
        width: 45px;
        height: 45px;
        animation-duration: 16s;
        animation-delay: 3s;
    }
    .steam:nth-child(6) {
        left: 80%;
        width: 25px;
        height: 25px;
        animation-duration: 13s;
        animation-delay: 5s;
    }
    .steam:nth-child(7) {
        left: 90%;
        width: 35px;
        height: 35px;
        animation-duration: 17s;
        animation-delay: 6s;
    }

    @keyframes rise {
        0% {
            transform: translateY(0) scale(0.5) rotate(0deg);
            opacity: 0;
        }
        25% {
            opacity: 0.5;
        }
        50% {
            transform: translateY(-50vh) scale(1.5) rotate(180deg);
            opacity: 0.3;
        }
        100% {
            transform: translateY(-100vh) scale(2) rotate(360deg);
            opacity: 0;
        }
    }

    /* ── Custom Scrollbar ── */
    ::-webkit-scrollbar { width: 8px; }
    ::-webkit-scrollbar-track { background: var(--bg); }
    ::-webkit-scrollbar-thumb {
        background: var(--accent);
        border-radius: 10px;
    }
    ::-webkit-scrollbar-thumb:hover { background: var(--accent-dark); }

    /* ── Back Button ── */
    .back {
        position: fixed;
        top: 24px;
        left: 24px;
        color: #d1904b;
        text-decoration: none;
        font-weight: 600;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 7px;
        padding: 10px 18px;
        background: rgba(209,144,75,.08);
        border: 1px solid rgba(209,144,75,.35);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border-radius: 50px;
        border: 1px solid rgba(255,255,255,0.1);
        transition: var(--transition);
        z-index: 100;
        box-shadow: 0 2px 12px rgba(0,0,0,0.4), inset 0 1px 0 rgba(255,255,255,0.06);
    }

    .back:hover {
        border-color: var(--accent);
        box-shadow: var(--shadow-accent);
        transform: translateX(-4px);
    }

    .back i {
        font-size: 16px;
    }

    /* ── Page Header ── */
    .vo-page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        max-width: 1100px;
        margin: 0 auto 32px;
        padding: 20px 28px;
        background: rgba(255,255,255,0.03);
        border: 1px solid rgba(255,255,255,0.07);
        border-radius: 18px;
        backdrop-filter: blur(12px);
        box-shadow: 0 4px 30px rgba(0,0,0,0.3), inset 0 1px 0 rgba(255,255,255,0.05);
    }
    .vo-page-header-left h2 {
        font-size: 17px;
        font-weight: 700;
        color: var(--text);
        margin: 0 0 4px;
        letter-spacing: .2px;
    }
    .vo-page-header-left h2 span { color: var(--accent); }
    .vo-page-header-left small {
        font-size: 11px;
        color: var(--text-muted);
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .vo-page-header-center {
        text-align: center;
    }
    .vo-page-header-center h1 {
        font-size: 22px;
        font-weight: 800;
        color: var(--accent);
        margin: 0 0 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 9px;
        text-shadow: 0 0 24px rgba(209,144,75,.2);
        letter-spacing: .3px;
    }
    .vo-live-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 3px 12px;
        background: rgba(85,224,135,0.1);
        border: 1px solid rgba(85,224,135,0.2);
        border-radius: 50px;
        font-size: 11px;
        color: var(--success);
        font-weight: 500;
    }
    .vo-live-badge .dot {
        width: 7px; height: 7px;
        background: var(--success);
        border-radius: 50%;
        animation: pulse-dot 1.5s infinite;
    }
    .vo-role-badge {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 6px 16px;
        border-radius: 50px;
        font-size: 12px;
        font-weight: 600;
        background: rgba(255,255,255,0.05);
        border: 1px solid rgba(255,255,255,0.1);
        white-space: nowrap;
        letter-spacing: .4px;
    }
    .vo-role-badge .dot {
        width: 8px; height: 8px;
        border-radius: 50%;
        animation: pulse-dot 2s infinite;
    }

    @keyframes pulse-dot {
        0%, 100% { opacity: 1; transform: scale(1); }
        50% { opacity: 0.5; transform: scale(0.8); }
    }

    /* keep .header class for old mobile overrides that reference it */
    .header { display: none; }

    /* ── STATUS TABS ── */
    .status-tabs {
        display: flex;
        justify-content: center;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 24px;
        position: relative;
        z-index: 1;
    }

    .status-tab {
        padding: 8px 24px;
        border-radius: 50px;
        background: rgba(255,255,255,0.04);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255,255,255,0.08);
        color: var(--text-muted);
        text-decoration: none;
        font-weight: 500;
        font-size: 14px;
        transition: var(--transition);
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    }

    .status-tab:hover {
        border-color: var(--accent);
        color: var(--text);
    }

    .status-tab.active {
        background: rgba(209, 144, 75, 0.2);
        border-color: var(--accent);
        color: var(--text-light);
    }

    .status-tab .badge {
        background: rgba(255,255,255,0.1);
        padding: 2px 10px;
        border-radius: 50px;
        font-size: 12px;
        font-weight: 600;
    }

    .status-tab.active .badge {
        background: rgba(0,0,0,0.2);
        color: var(--text-light);
    }

    /* ── Toggle Button ── */
    .toggle-container {
        display: flex;
        justify-content: center;
        margin-bottom: 20px;
        position: relative;
        z-index: 1;
    }

    .toggle-btn {
        padding: 8px 20px;
        border-radius: 50px;
        border: 1px solid rgba(255,255,255,0.08);
        background: rgba(255,255,255,0.04);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        color: var(--text);
        font-size: 13px;
        cursor: pointer;
        transition: all 0.3s ease;
        font-family: 'Poppins', sans-serif;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    }

    .toggle-btn:hover {
        border-color: var(--accent);
        box-shadow: var(--shadow-accent);
    }

    /* ── Container ── */
    .container {
        max-width: 1400px;
        margin: auto;
        position: relative;
        z-index: 1;
    }

    /* ── Orders Grid ── */
    .orders-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
        gap: 18px;
    }

    /* ── Order Card ── */
    @keyframes cardIn {
        from { opacity: 0; transform: translateY(14px) scale(0.98); }
        to   { opacity: 1; transform: translateY(0) scale(1); }
    }

    .order-card {
        background: linear-gradient(145deg,
            rgba(255,255,255,0.055) 0%,
            rgba(255,255,255,0.028) 50%,
            rgba(255,255,255,0.018) 100%);
        border: 1px solid rgba(255,255,255,0.09);
        border-radius: 18px;
        padding: 20px;
        position: relative;
        overflow: hidden;
        transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
        animation: cardIn 0.35s ease both;
        backdrop-filter: blur(28px) saturate(150%);
        -webkit-backdrop-filter: blur(28px) saturate(150%);
        box-shadow: 0 4px 24px rgba(0,0,0,0.45), inset 0 1px 0 rgba(255,255,255,0.06);
    }

    /* Status accent strip on left */
    .order-card::before {
        content: '';
        position: absolute;
        left: 0; top: 0; bottom: 0;
        width: 4px;
        border-radius: 18px 0 0 18px;
        background: var(--sc, var(--accent));
    }

    /* Subtle top glow matching status */
    .order-card::after {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 1px;
        background: linear-gradient(90deg, var(--sc, var(--accent)), transparent);
        opacity: 0.5;
    }

    .order-card[data-status="PendingPayment"] { --sc: #d1904b; }
    .order-card[data-status="Paid"]           { --sc: #3498db; }
    .order-card[data-status="Preparing"]      { --sc: #f1c40f; }
    .order-card[data-status="Completed"]      { --sc: #55e087; }
    .order-card[data-status="Cancelled"]      { --sc: #ff5c5c; }
    .order-card[data-status="Refunded"]       { --sc: #9b59b6; }

    .order-card:hover {
        transform: translateY(-4px);
        border-color: rgba(255,255,255,0.16);
        box-shadow: 0 16px 48px rgba(0,0,0,0.55), inset 0 1px 0 rgba(255,255,255,0.1);
    }

    /* ── Card Header ── */
    .card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 12px;
        padding-left: 8px;
    }

    .card-order-num {
        font-size: 20px;
        font-weight: 800;
        color: var(--sc, var(--accent));
        letter-spacing: -0.5px;
        line-height: 1;
    }

    .card-header-right {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .card-total {
        font-size: 17px;
        font-weight: 700;
        color: var(--text-light);
        letter-spacing: -0.3px;
    }

    /* ── Card Customer Row ── */
    .card-customer-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 8px;
        margin-bottom: 4px;
    }

    .card-customer-name {
        display: flex;
        align-items: center;
        gap: 7px;
        font-size: 14px;
        font-weight: 600;
        color: var(--text);
    }

    .card-customer-name i {
        color: var(--text-muted);
        font-size: 13px;
    }

    .card-time {
        display: flex;
        align-items: center;
        gap: 5px;
        font-size: 12px;
        color: var(--text-muted);
    }

    .card-table-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 8px;
        border-radius: 6px;
        background: rgba(209,144,75,0.12);
        border: 1px solid rgba(209,144,75,0.2);
        color: var(--accent);
        font-size: 11px;
        font-weight: 600;
        margin: 4px 0 4px 8px;
    }

    /* ── Card Divider ── */
    .card-divider {
        height: 1px;
        background: linear-gradient(90deg, rgba(255,255,255,0.05) 0%, rgba(255,255,255,0.08) 50%, rgba(255,255,255,0.05) 100%);
        margin: 12px 0;
    }

    /* ── Card Footer ── */
    .card-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 2px;
        margin-top: 10px;
    }

    .card-employee {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 11.5px;
        color: var(--text-muted);
    }

    /* ── Card Actions ── */
    .card-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 7px;
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px solid rgba(255,255,255,0.05);
    }

    .card-actions button {
        flex: 1;
        min-width: 80px;
        padding: 8px 12px;
        border-radius: 10px;
        border: 1px solid transparent;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        font-family: 'Poppins', sans-serif;
        transition: var(--transition);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        white-space: nowrap;
    }

    .card-actions button:hover { transform: translateY(-1px); filter: brightness(1.15); }
    .card-actions button:disabled { opacity: 0.3; cursor: not-allowed; transform: none; }

    .card-actions .call-btn   { background: rgba(209,144,75,.12); color: var(--accent); border-color: rgba(209,144,75,.2); }
    .card-actions .paid-btn   { background: rgba(52,152,219,.12); color: #3498db; border-color: rgba(52,152,219,.2); }
    .card-actions .prepare-btn{ background: rgba(241,196,15,.12); color: #f1c40f; border-color: rgba(241,196,15,.2); }
    .card-actions .complete-btn{background: rgba(85,224,135,.12); color: var(--success); border-color: rgba(85,224,135,.2);}
    .card-actions .cancel-btn { background: rgba(255,92,92,.12);  color: var(--danger); border-color: rgba(255,92,92,.2); }
    .card-actions .refund-btn { background: rgba(155,89,182,.12); color: #9b59b6; border-color: rgba(155,89,182,.2); }
    .card-actions .remake-btn { background: rgba(52,152,219,.12); color: #3498db; border-color: rgba(52,152,219,.2); }
    /* ── Reason notes on cards ── */
    .card-reason { font-size: 12px; font-style: italic; padding: 5px 10px; border-radius: 8px; margin: 8px 0 2px; display: flex; align-items: flex-start; gap: 7px; line-height: 1.5; }
    .card-reason i { margin-top: 3px; flex-shrink: 0; font-size: 11px; }
    .reason-list { margin: 0; padding-left: 14px; list-style: disc; }
    .reason-list li { margin-bottom: 2px; line-height: 1.4; }
    .card-reason.cancel-reason  { background: rgba(255,92,92,.08);   color: #ff7070; border-left: 2px solid rgba(255,92,92,.3); }
    .card-reason.refund-reason  { background: rgba(155,89,182,.08);  color: #b07fd4; border-left: 2px solid rgba(155,89,182,.3); }
    .card-reason.remake-reason  { background: rgba(241,196,15,.08);  color: #e8c63a; border-left: 2px solid rgba(241,196,15,.3); }
    /* ── Remade card highlight (Preparing only) ── */
    .order-card.is-remade[data-status="Preparing"] {
        border-color: rgba(241,196,15,.6) !important;
        animation: remade-glow 2s ease-in-out infinite;
    }
    @keyframes remade-glow {
        0%,100% { box-shadow: 0 0 0 1px rgba(241,196,15,.25), 0 4px 20px rgba(241,196,15,.1); }
        50%      { box-shadow: 0 0 0 2px rgba(241,196,15,.5),  0 4px 28px rgba(241,196,15,.25); }
    }
    /* ── Remake adjustment pills ── */
    .remake-item-block { background: rgba(52,152,219,.06); border: 1px solid rgba(52,152,219,.15); border-radius: 12px; padding: 12px 14px; margin-bottom: 10px; text-align: left; }
    .remake-item-block + .remake-item-block { margin-top: 8px; }
    .remake-item-name { font-size: 12px; font-weight: 600; color: #3498db; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 10px; }
    .remake-adj-group { margin-bottom: 8px; }
    .remake-adj-group:last-child { margin-bottom: 0; }
    .remake-adj-label { font-size: 10px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 5px; }
    .pill-group { display: flex; flex-wrap: wrap; gap: 5px; }
    .pill-opt { padding: 4px 11px; border-radius: 20px; border: 1px solid var(--border); background: transparent; color: var(--text); font-size: 12px; cursor: pointer; transition: all 0.15s; font-family: inherit; }
    .pill-opt:hover { border-color: #3498db; color: #3498db; }
    .pill-opt.selected { background: #3498db; border-color: #3498db; color: #fff; font-weight: 600; }
    #remakeAdjustments { max-height: 280px; overflow-y: auto; margin-bottom: 4px; }
    .badge-remade { display:inline-flex; align-items:center; gap:4px; background:rgba(52,152,219,.15); color:#3498db; border:1px solid rgba(52,152,219,.3); border-radius:6px; font-size:10px; font-weight:600; padding:2px 7px; margin-left:6px; }
    .bcard-badge.remade { background:rgba(52,152,219,.18); color:#3498db; border:1px solid rgba(52,152,219,.35); }
    .age-badge { display:inline-flex; align-items:center; gap:3px; border-radius:20px; font-size:10px; font-weight:600; padding:2px 7px; }
    .age-badge:empty { display:none; }
    .age-badge.age-warn  { background:rgba(255,193,7,.18); color:#f0ad4e; border:1px solid rgba(255,193,7,.35); }
    .age-badge.age-alert { background:rgba(239,68,68,.15); color:#ef4444; border:1px solid rgba(239,68,68,.3); animation:age-pulse 1.4s ease-in-out infinite; }
    @keyframes age-pulse { 0%,100%{opacity:1} 50%{opacity:.55} }
    .card-actions .delete-btn { background: rgba(255,92,92,.12);  color: var(--danger); border-color: rgba(255,92,92,.2); padding: 8px 14px; min-width: auto; flex: 0; }

    /* ── Order ID (legacy, keep for search) ── */
    .order-id {
        color: var(--accent);
        font-weight: 600;
        font-size: 14px;
    }

    /* ── Items List ── */
    .items-list {
        display: flex;
        flex-direction: column;
        gap: 5px;
        min-width: 200px;
    }

    .item-line {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        padding: 6px 8px;
        background: rgba(255,255,255,0.04);
        border-radius: 8px;
        border-left: 3px solid var(--accent);
    }

    .item-qty-badge {
        background: var(--accent);
        color: #000;
        font-size: 11px;
        font-weight: 700;
        min-width: 22px;
        height: 22px;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        margin-top: 1px;
    }

    .item-body {
        flex: 1;
        min-width: 0;
    }

    .item-name {
        font-size: 13px;
        font-weight: 600;
        color: var(--text-light);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .item-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 3px;
        margin-top: 3px;
    }

    .item-chip {
        font-size: 10px;
        padding: 1px 6px;
        border-radius: 4px;
        background: rgba(255,255,255,0.07);
        color: var(--text-muted);
        white-space: nowrap;
    }

    /* ── Price (legacy) ── */
    .price {
        font-weight: 700;
        color: var(--accent);
        font-size: 15px;
    }

    /* ── Status Badge ── */
    .status {
        display: inline-block;
        padding: 6px 16px;
        border-radius: 50px;
        font-weight: 600;
        font-size: 12px;
        min-width: 90px;
        letter-spacing: 0.3px;
        transition: var(--transition);
    }

    .status.PendingPayment {
        background: rgba(209, 144, 75, 0.2);
        color: var(--accent);
        border: 1px solid rgba(209, 144, 75, 0.2);
    }

    .status.Paid {
        background: rgba(52, 152, 219, 0.2);
        color: #3498db;
        border: 1px solid rgba(52, 152, 219, 0.2);
    }

    .status.Preparing {
        background: rgba(241, 196, 15, 0.2);
        color: #f1c40f;
        border: 1px solid rgba(241, 196, 15, 0.2);
    }

    .status.Completed {
        background: rgba(85, 224, 135, 0.2);
        color: var(--success);
        border: 1px solid rgba(85, 224, 135, 0.2);
    }

    .status.Cancelled {
        background: rgba(255, 92, 92, 0.2);
        color: var(--danger);
        border: 1px solid rgba(255, 92, 92, 0.2);
    }

    .status.Refunded {
        background: rgba(155, 89, 182, 0.2);
        color: #9b59b6;
        border: 1px solid rgba(155, 89, 182, 0.2);
    }

    .status:hover {
        transform: scale(1.05);
    }

    /* ── Date Cell (legacy) ── */
    .date-cell {
        color: var(--text-muted);
        font-size: 12px;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    /* ── Fade Out Animation ── */
    .fade-out {
        opacity: 0;
        transform: translateX(20px);
        transition: all 0.4s ease;
    }

    /* ── Empty State ── */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: var(--text-muted);
    }

    .empty-state i {
        font-size: 48px;
        display: block;
        margin-bottom: 16px;
        color: var(--border);
    }

    .empty-state h3 {
        color: var(--text);
        font-weight: 600;
        margin-bottom: 8px;
    }

    .empty-state p {
        font-size: 14px;
    }

    /* ── Call Notification Modal ── */
    .call-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.8);
        backdrop-filter: blur(10px);
        z-index: 9999;
        justify-content: center;
        align-items: center;
    }

    .call-modal.active {
        display: flex;
    }

    .call-modal-content {
        background: rgba(18, 18, 18, 0.95);
        backdrop-filter: blur(20px);
        border-radius: 24px;
        padding: 40px;
        text-align: center;
        max-width: 400px;
        border: 2px solid var(--accent);
        box-shadow: var(--shadow-lg);
        animation: modalPop 0.3s ease;
    }

    @keyframes modalPop {
        from {
            opacity: 0;
            transform: scale(0.9);
        }
        to {
            opacity: 1;
            transform: scale(1);
        }
    }

    .call-modal-content h2 {
        font-size: 48px;
        color: var(--accent);
        margin-bottom: 8px;
    }

    .call-modal-content .order-number {
        font-size: 72px;
        font-weight: 800;
        color: var(--text-light);
        margin: 16px 0;
    }

    .call-modal-content p {
        color: var(--text-muted);
        margin-bottom: 20px;
    }

    .call-modal-content .btn-dismiss {
        padding: 12px 40px;
        border-radius: 50px;
        border: none;
        background: var(--accent);
        color: #000;
        font-weight: 600;
        font-size: 16px;
        cursor: pointer;
        transition: var(--transition);
    }

    .call-modal-content .btn-dismiss:hover {
        transform: scale(1.05);
        box-shadow: var(--shadow-accent);
    }

    /* ── Cancel Modal ── */
    .cancel-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.8);
        backdrop-filter: blur(10px);
        z-index: 9999;
        justify-content: center;
        align-items: center;
    }

    .cancel-modal.active {
        display: flex;
    }

    .cancel-modal-content {
        background: rgba(18, 18, 18, 0.95);
        backdrop-filter: blur(20px);
        border-radius: 24px;
        padding: 40px;
        text-align: center;
        max-width: 500px;
        border: 2px solid var(--danger);
        box-shadow: var(--shadow-lg);
        animation: modalPop 0.3s ease;
        width: 100%;
    }

    .cancel-modal-content h2 {
        font-size: 28px;
        color: var(--danger);
        margin-bottom: 8px;
    }

    .cancel-modal-content .order-number {
        font-size: 32px;
        font-weight: 700;
        color: var(--text-light);
        margin: 8px 0;
    }

    .cancel-modal-content textarea {
        width: 100%;
        padding: 12px;
        border-radius: 10px;
        border: 1px solid var(--border);
        background: rgba(255,255,255,0.03);
        color: var(--text);
        font-family: 'Poppins', sans-serif;
        font-size: 14px;
        min-height: 80px;
        resize: vertical;
        margin: 12px 0;
    }

    .cancel-modal-content textarea:focus {
        outline: none;
        border-color: var(--danger);
    }

    .cancel-modal-content .btn-group {
        display: flex;
        gap: 12px;
        margin-top: 16px;
    }

    .cancel-modal-content .btn-group button {
        flex: 1;
        padding: 12px;
        border-radius: 10px;
        border: none;
        font-weight: 600;
        cursor: pointer;
        font-family: 'Poppins', sans-serif;
        font-size: 14px;
        transition: var(--transition);
    }

    .cancel-modal-content .btn-group .btn-cancel-yes {
        background: var(--danger);
        color: #fff;
    }

    .cancel-modal-content .btn-group .btn-cancel-yes:hover {
        background: #cc0000;
        transform: scale(1.02);
    }

    .cancel-modal-content .btn-group .btn-cancel-no {
        background: rgba(255,255,255,0.05);
        color: var(--text);
        border: 1px solid var(--border);
    }

    .cancel-modal-content .btn-group .btn-cancel-no:hover {
        border-color: var(--accent);
    }

    /* ── Refund Modal ── */
    .refund-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.8);
        backdrop-filter: blur(10px);
        z-index: 9999;
        justify-content: center;
        align-items: center;
    }

    .refund-modal.active {
        display: flex;
    }

    .refund-modal-content {
        background: rgba(18, 18, 18, 0.95);
        backdrop-filter: blur(20px);
        border-radius: 24px;
        padding: 40px;
        text-align: center;
        max-width: 500px;
        border: 2px solid #9b59b6;
        box-shadow: var(--shadow-lg);
        animation: modalPop 0.3s ease;
        width: 100%;
    }

    .refund-modal-content h2 {
        font-size: 28px;
        color: #9b59b6;
        margin-bottom: 8px;
    }

    .refund-modal-content .order-number {
        font-size: 32px;
        font-weight: 700;
        color: var(--text-light);
        margin: 8px 0;
    }

    .refund-modal-content .form-group {
        margin: 12px 0;
        text-align: left;
    }

    .refund-modal-content .form-group label {
        display: block;
        color: var(--text-muted);
        font-size: 13px;
        margin-bottom: 4px;
    }

    .refund-modal-content .form-group input,
    .refund-modal-content .form-group textarea {
        width: 100%;
        padding: 10px 14px;
        border-radius: 10px;
        border: 1px solid var(--border);
        background: rgba(255,255,255,0.03);
        color: var(--text);
        font-family: 'Poppins', sans-serif;
        font-size: 14px;
    }

    .refund-modal-content .form-group textarea {
        min-height: 60px;
        resize: vertical;
    }

    .refund-modal-content .form-group input:focus,
    .refund-modal-content .form-group textarea:focus {
        outline: none;
        border-color: #9b59b6;
    }

    .refund-modal-content .btn-group {
        display: flex;
        gap: 12px;
        margin-top: 16px;
    }

    .refund-modal-content .btn-group button {
        flex: 1;
        padding: 12px;
        border-radius: 10px;
        border: none;
        font-weight: 600;
        cursor: pointer;
        font-family: 'Poppins', sans-serif;
        font-size: 14px;
        transition: var(--transition);
    }

    .refund-modal-content .btn-group .btn-refund-yes {
        background: #9b59b6;
        color: #fff;
    }

    .refund-modal-content .btn-group .btn-refund-yes:hover {
        background: #8e44ad;
        transform: scale(1.02);
    }

    .refund-modal-content .btn-group .btn-refund-no {
        background: rgba(255,255,255,0.05);
        color: var(--text);
        border: 1px solid var(--border);
    }

    .refund-modal-content .btn-group .btn-refund-no:hover {
        border-color: var(--accent);
    }

    /* ── Responsive ── */
    @media (max-width: 1024px) {
        body { padding: 24px; }
        .orders-grid { grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); }
    }

    @media (max-width: 768px) {
        body { padding: 16px; }
        .orders-grid { grid-template-columns: 1fr; }
        .back { top: 16px; left: 16px; padding: 8px 14px; font-size: 12px; }

        .header h1 {
            font-size: 24px;
        }

        .container {
            padding: 16px;
            border-radius: 14px;
        }

        .orders-grid { grid-template-columns: 1fr; }

        .status {
            font-size: 11px;
            padding: 4px 12px;
        }
    }

    @media (max-width: 480px) {
        body {
            padding: 10px;
        }

        .header h1 {
            font-size: 20px;
        }

        .header h1 i {
            font-size: 20px;
        }

        .container {
            padding: 12px;
        }
    }
/* Light theme (follows shared localStorage theme) */
[data-theme="light"]{--bg:#ECEEF2;--bg-card:#FFFFFF;--bg-card-hover:#F5F7FA;--border:#E2E5EA;--border-hover:#CDD0D8;--text:#111827;--text-muted:#5A6373;--text-light:#0B0F19;}
[data-theme="light"] body{
    background-color:#ECEEF2;
    background-image:
        radial-gradient(ellipse 90% 60% at 15% -10%, rgba(120,120,160,0.06) 0%, transparent 55%),
        radial-gradient(ellipse 70% 60% at 85% 110%, rgba(100,100,140,0.05) 0%, transparent 55%),
        linear-gradient(rgba(0,0,0,0.028) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0,0,0,0.028) 1px, transparent 1px);
    background-size:auto, auto, 72px 72px, 72px 72px;
}
[data-theme="light"] .order-card{
    background:#FFFFFF; border-color:#E2E5EA;
    box-shadow:0 4px 20px rgba(0,0,0,.08), 0 1px 3px rgba(0,0,0,.05);
    backdrop-filter:none; -webkit-backdrop-filter:none;
}
[data-theme="light"] .order-card:hover{ border-color:#CDD0D8; box-shadow:0 12px 34px rgba(0,0,0,.12); }
[data-theme="light"] .back,
[data-theme="light"] .status-tab,
[data-theme="light"] .toggle-btn,
[data-theme="light"] .toggle-container{ background:#FFFFFF; border-color:#E2E5EA; }
[data-theme="light"] .status-tab .badge{ background:#ECEEF2; color:#5A6373; }
[data-theme="light"] .order-item,
[data-theme="light"] .customizations,
[data-theme="light"] .custom-chip,
[data-theme="light"] .chip{ background:#F5F7FA; border-color:#E2E5EA; }

/* ── Barista Station Shell ── */
body.barista-mode { padding: 0; }
.bstation { display: flex; min-height: 100vh; }
.bsidebar {
    width: 250px; flex-shrink: 0; position: sticky; top: 0; align-self: flex-start;
    height: 100vh; overflow-y: auto;
    background: rgba(255,255,255,0.02); border-right: 1px solid var(--border);
    display: flex; flex-direction: column; gap: 22px; padding: 22px 18px;
}
.bsidebar-brand { display:flex; align-items:center; gap:11px; font-weight:800; font-size:16px; color:var(--text); }
.bsidebar-brand .logo { width:36px;height:36px;border-radius:11px;background:linear-gradient(135deg,var(--accent),var(--accent-dark));display:flex;align-items:center;justify-content:center;color:#000;flex-shrink:0; }
.buser { display:flex; align-items:center; gap:11px; }
.buser .avatar { width:40px;height:40px;border-radius:11px;background:rgba(209,144,75,.15);border:1px solid rgba(209,144,75,.25);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--accent); }
.bclock-pill { display:inline-flex;align-items:center;gap:7px;font-size:12px;font-weight:600;padding:6px 12px;border-radius:9px; }
.bstats { display:flex; flex-direction:column; gap:9px; }
.bstats-label { font-size:10px;letter-spacing:.09em;text-transform:uppercase;color:var(--text-muted);font-weight:700; }
.bstat-row { display:flex; align-items:center; justify-content:space-between; padding:9px 12px; border-radius:10px; background:rgba(255,255,255,.03); border:1px solid var(--border); transition:var(--transition); }
.bstat-row .k { font-size:12.5px; color:var(--text-muted); display:flex; align-items:center; gap:8px; }
.bstat-row .v { font-size:16px; font-weight:800; color:var(--text); }
.bstat-row.is-alert { border-color:rgba(255,92,92,.35); background:rgba(255,92,92,.06); }
.bstat-row.is-alert .v { color: var(--danger); }
.bnav { display:flex; flex-direction:column; gap:6px; margin-top:auto; }
.bnav a, .bnav button {
    display:flex; align-items:center; gap:10px; text-decoration:none;
    font-size:13px; font-weight:600; color:var(--text-muted);
    padding:10px 12px; border-radius:10px; border:1px solid transparent;
    background:none; cursor:pointer; font-family:'Poppins',sans-serif; text-align:left; width:100%;
    transition:all .18s;
}
.bnav a:hover, .bnav button:hover { color:var(--text); background:rgba(255,255,255,.04); }
/* Clock-In is the first action of a shift — make it an obvious amber CTA until clocked in */
.bnav #clockBtn[data-clocked="0"] { background:rgba(209,144,75,.18) !important; color:var(--accent) !important; border-color:rgba(209,144,75,.35) !important; font-weight:700; }
.bnav #clockBtn[data-clocked="0"]:hover { background:rgba(209,144,75,.28) !important; }
.bmain { flex:1; min-width:0; padding: 24px 28px 60px; }
.bmain-head { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:14px; margin-bottom:20px; }
.bmain-head h1 { font-size:22px;font-weight:800;color:var(--text);display:flex;align-items:center;gap:10px; }
.bhead-right { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.bclock { display:flex; align-items:center; gap:7px; font-size:13px; font-weight:600; color:var(--text-muted); padding:9px 13px; border-radius:10px; background:rgba(255,255,255,.03); border:1px solid var(--border); white-space:nowrap; }
.bclock #bClockTime { color:var(--text); }
.bclock-date { opacity:.65; font-weight:500; }
.bhead-btn { position:relative; width:40px; height:40px; border-radius:10px; border:1px solid var(--border); background:rgba(255,255,255,.03); color:var(--text-muted); display:flex; align-items:center; justify-content:center; cursor:pointer; text-decoration:none; font-size:15px; transition:all .18s; }
.bhead-btn:hover { color:var(--text); border-color:rgba(255,255,255,.15); background:rgba(255,255,255,.06); }
.bhead-badge { position:absolute; top:-5px; right:-5px; min-width:17px; height:17px; padding:0 4px; border-radius:9px; background:var(--danger); color:#fff; font-size:10px; font-weight:700; display:flex; align-items:center; justify-content:center; line-height:1; }
/* Notifications dropdown (barista bell) */
.bnotif-wrap { position:relative; }
@keyframes bnotifIn { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:translateY(0)} }
.bnotif-panel { display:none; position:absolute; top:48px; right:0; width:340px; max-width:86vw; max-height:62vh; overflow-y:auto; background:var(--bg-card); border:1px solid var(--border); border-radius:14px; padding:12px; box-shadow:0 14px 36px rgba(0,0,0,.55); z-index:200; }
.bnotif-panel.open { display:block; animation:bnotifIn .18s ease both; }
.bnotif-head { font-size:13px; font-weight:700; color:var(--text); padding:2px 4px 10px; border-bottom:1px solid var(--border); margin-bottom:8px; }
.bnotif-panel .ann-banner { padding:10px 12px !important; margin-bottom:8px !important; border-radius:10px !important; }
.bnotif-empty { text-align:center; color:var(--text-muted); font-size:12.5px; padding:20px 8px; }
.bnotif-foot { border-top:1px solid var(--border); margin-top:6px; padding-top:8px; text-align:center; }
.bnotif-foot button { background:none; border:none; color:var(--text-muted); font-size:12px; cursor:pointer; font-family:'Poppins',sans-serif; }
.bnotif-foot button:hover { color:var(--accent); }
@media (max-width:820px){ .bclock-date{ display:none; } }
.barista-mode .orders-grid { grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); }
@media (max-width: 820px) {
    .bstation { flex-direction: column; }
    .bsidebar { width:100%; height:auto; position:static; flex-direction:row; flex-wrap:wrap; align-items:center; }
    .bnav { flex-direction:row; margin-top:0; flex-wrap:wrap; }
}
[data-theme="light"] .bsidebar { background:#F5F7FA; }

/* ── Barista Card ── */
.order-card.bcard { padding: 16px 18px 14px; }
.order-card.bcard.is-overdue { --sc: var(--danger); }
.order-card.bcard.is-warn    { --sc: var(--warning); }
.bcard-top { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; margin-bottom:10px; padding-left:8px; }
.bcard-num { font-size:15px; font-weight:700; color:var(--sc, var(--accent)); letter-spacing:.02em; line-height:1.3; flex-shrink:0; }
.bcard-badge { font-size:10.5px; font-weight:700; letter-spacing:.03em; padding:4px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:5px; white-space:nowrap; }
.bcard-badge.overdue { background:rgba(255,92,92,.13); color:var(--danger); }
.bcard-badge.prep    { background:rgba(243,156,18,.13); color:var(--warning); }
.bcard-badge.returning { background:rgba(155,89,182,.16); color:#b07cc6; border:1px solid rgba(155,89,182,.4); }
.bcard-sub { display:flex; align-items:center; justify-content:space-between; font-size:12px; color:var(--text-muted); margin-bottom:12px; padding:0 8px; }
.bcard-sub i { font-size:11px; margin-right:4px; opacity:.8; }
.bitem { padding:0 8px; margin-bottom:12px; }
.bitem:last-of-type { margin-bottom:0; }
.bitem-name { font-size:17px; font-weight:700; color:var(--text-light); letter-spacing:-.01em; line-height:1.25; }
.bcat { font-size:9.5px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; padding:2px 8px; border-radius:5px; background:rgba(209,144,75,.14); color:var(--accent); flex-shrink:0; }
.bchips { display:flex; flex-wrap:wrap; gap:6px; margin-top:7px; }
.bchip { font-size:11px; font-weight:500; padding:3px 10px; border-radius:20px; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.06); color:var(--text-muted); }
.bchip-more { background:transparent; border-style:dashed; font-style:normal; opacity:.7; }
    </style>
</head>
<body>

<!-- Coffee Steam Animation -->
<div class="steam-container">
    <div class="steam"></div>
    <div class="steam"></div>
    <div class="steam"></div>
    <div class="steam"></div>
    <div class="steam"></div>
    <div class="steam"></div>
    <div class="steam"></div>
</div>

<?php $r = $_SESSION['role'] ?? ''; ?>
<style>
@keyframes annIn { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:translateY(0)} }
</style>
<script>
var _annColors = {
    info:    ['#5bc0de','rgba(91,192,222,.08)','rgba(91,192,222,.2)','info-circle'],
    warning: ['#f39c12','rgba(243,156,18,.08)','rgba(243,156,18,.2)','triangle-exclamation'],
    urgent:  ['#e74c3c','rgba(231,76,60,.1)',  'rgba(231,76,60,.25)','circle-exclamation'],
};
function _annDismissed() { return JSON.parse(localStorage.getItem('ann_dismissed') || '[]'); }
function dismissAnn(btn, id) {
    id = parseInt(id);
    var banner = btn.closest('.ann-banner');
    banner.style.transition = 'opacity .3s, transform .3s';
    banner.style.opacity = '0';
    banner.style.transform = 'translateY(-6px)';
    setTimeout(function(){ banner.remove(); if (typeof updateBaristaBell === 'function') updateBaristaBell(); }, 300);
    var dismissed = _annDismissed();
    if (!dismissed.includes(id)) { dismissed.push(id); localStorage.setItem('ann_dismissed', JSON.stringify(dismissed)); }
}
function _buildAnnBanner(ann) {
    var c = _annColors[ann.type] || _annColors.info;
    var ac = c[0], abg = c[1], abr = c[2], ai = c[3];
    var div = document.createElement('div');
    div.className = 'ann-banner';
    div.dataset.id = ann.id;
    div.style.cssText = 'display:flex;align-items:flex-start;gap:14px;padding:14px 18px;margin-bottom:10px;border-radius:14px;background:'+abg+';border:1px solid '+abr+';animation:annIn .4s ease both;';
    div.innerHTML =
        '<i class="fa-solid fa-'+ai+'" style="color:'+ac+';font-size:16px;margin-top:2px;flex-shrink:0"></i>' +
        '<div style="flex:1;min-width:0">' +
          '<div style="font-size:13px;font-weight:700;color:#f5f5f5;margin-bottom:3px">'+_escAnn(ann.title)+'</div>' +
          '<div style="font-size:12.5px;color:#aaa;line-height:1.55">'+_escAnn(ann.message).replace(/\n/g,'<br>')+'</div>' +
        '</div>' +
        '<button onclick="dismissAnn(this,'+ann.id+')" style="background:none;border:none;color:#555;cursor:pointer;font-size:14px;padding:2px 4px;flex-shrink:0;margin-top:1px;transition:color .2s" title="Dismiss"><i class="fa-solid fa-xmark"></i></button>';
    return div;
}
function _escAnn(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function updateAnnouncements(list) {
    var container = document.getElementById('annContainer');
    if (!container) return;
    var dismissed = _annDismissed().map(function(x){ return parseInt(x); });
    var activeIds = list.map(function(a){ return parseInt(a.id); }).filter(function(id){ return !dismissed.includes(id); });
    // Remove banners no longer active or hidden
    container.querySelectorAll('.ann-banner').forEach(function(b){
        if (!activeIds.includes(parseInt(b.dataset.id))) {
            b.style.transition = 'opacity .3s';
            b.style.opacity = '0';
            setTimeout(function(){ b.remove(); }, 300);
        }
    });
    // Add new banners not yet shown
    var shownIds = Array.from(container.querySelectorAll('.ann-banner')).map(function(b){ return parseInt(b.dataset.id); });
    list.forEach(function(ann) {
        var id = parseInt(ann.id);
        if (!dismissed.includes(id) && !shownIds.includes(id)) {
            container.insertBefore(_buildAnnBanner(ann), container.firstChild);
        }
    });
    if (typeof updateBaristaBell === 'function') updateBaristaBell();
}
</script>

<script>
async function toggleClock() {
    var btn = document.getElementById('clockBtn');
    var clocked = btn.dataset.clocked === '1';
    btn.disabled = true;
    btn.style.opacity = '.6';

    try {
        var resp = await fetch('attendance_action.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=' + (clocked ? 'clock_out' : 'clock_in')
        });
        var data = await resp.json();

        if (data.ok) {
            // Sidebar clock pill (barista station) — keep in sync with the nav button
            var pill = document.getElementById('bClockPill');
            var pillText = document.getElementById('bClockPillText');
            if (!clocked) {
                btn.dataset.clocked = '1';
                btn.innerHTML = '<i class="fa-solid fa-right-from-bracket"></i> Clock Out';
                btn.style.background = 'rgba(255,95,95,.08)';
                btn.style.borderColor = 'rgba(255,95,95,.25)';
                btn.style.color = '#ff6b6b';
                btn.title = 'Clocked in at ' + (data.time || '');
                if (pill) { pill.style.background = 'rgba(85,224,135,.1)'; pill.style.color = '#55e087'; }
                if (pillText) { pillText.textContent = 'Clocked in ' + (data.time || ''); }
            } else {
                btn.dataset.clocked = '0';
                btn.innerHTML = '<i class="fa-solid fa-fingerprint"></i> Clock In';
                btn.style.background = 'rgba(85,224,135,.08)';
                btn.style.borderColor = 'rgba(85,224,135,.25)';
                btn.style.color = '#55e087';
                btn.title = 'Not clocked in';
                if (pill) { pill.style.background = 'rgba(255,95,95,.08)'; pill.style.color = '#ff6b6b'; }
                if (pillText) { pillText.textContent = 'Not clocked in'; }
            }
            showClockToast(data.msg, false);
        } else {
            showClockToast(data.msg, true);
        }
    } catch(e) {
        showClockToast('Connection error.', true);
    }

    btn.disabled = false;
    btn.style.opacity = '1';
}

function showClockToast(msg, isErr) {
    var el = document.createElement('div');
    el.style.cssText = 'position:fixed;bottom:28px;left:50%;transform:translateX(-50%);' +
        'padding:12px 22px;border-radius:12px;font-size:13px;font-weight:600;z-index:9999;' +
        'animation:toastIn .35s ease both;box-shadow:0 8px 32px rgba(0,0,0,.5);' +
        (isErr
            ? 'background:#3a1a1a;border:1px solid rgba(231,76,60,.3);color:#e87070;'
            : 'background:#1a3a28;border:1px solid rgba(85,224,135,.3);color:#55e087;');
    el.innerHTML = (isErr ? '<i class="fa-solid fa-circle-exclamation"></i> ' : '<i class="fa-solid fa-circle-check"></i> ') + msg;
    document.body.appendChild(el);
    setTimeout(function(){ el.style.animation='toastOut .35s ease forwards'; setTimeout(()=>el.remove(),350); }, 3000);
}
</script>

<?php if ($r === 'barista'): ?>
<script>document.body.classList.add('barista-mode');</script>
<div class="bstation">
  <aside class="bsidebar">
     <div class="bsidebar-brand"><span class="logo"><i class="fa-solid fa-mug-hot"></i></span> Bird's Nest</div>
     <div class="buser">
        <div class="avatar"><?php $__ph = current_user_photo($conn); if ($__ph): ?><img src="<?= htmlspecialchars($__ph) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;display:block"><?php else: ?><?= strtoupper(substr($_vo_username,0,1)) ?><?php endif; ?></div>
        <div>
           <div style="font-weight:700;font-size:14px;color:var(--text)"><?= $_vo_username ?></div>
           <div style="font-size:11px;color:<?= $_role_color ?>"><?= $_role_label ?></div>
        </div>
     </div>
     <?php
        $bc = $_is_clocked_in;
        $bcBg = $bc ? 'rgba(85,224,135,.1)' : 'rgba(255,95,95,.08)';
        $bcCol = $bc ? '#55e087' : '#ff6b6b';
     ?>
     <div class="bclock-pill" id="bClockPill" style="background:<?= $bcBg ?>;color:<?= $bcCol ?>">
        <span style="width:7px;height:7px;border-radius:50%;background:currentColor"></span>
        <span id="bClockPillText"><?= $bc ? ('Clocked in '.htmlspecialchars($_clock_since)) : 'Not clocked in' ?></span>
     </div>
     <div class="bstats">
        <div class="bstats-label">Today</div>
        <div class="bstat-row" id="stat-queue-row"><span class="k"><i class="fa-solid fa-hourglass-half"></i> In Queue</span><span class="v" id="stat-queue">0</span></div>
        <div class="bstat-row" id="stat-overdue-row"><span class="k"><i class="fa-solid fa-triangle-exclamation"></i> Overdue</span><span class="v" id="stat-overdue">0</span></div>
        <div class="bstat-row" id="stat-done-row"><span class="k"><i class="fa-solid fa-check"></i> Done Today</span><span class="v" id="stat-done">0</span></div>
        <div class="bstat-row" id="stat-avg-row"><span class="k"><i class="fa-regular fa-clock"></i> Avg Wait</span><span class="v" id="stat-avgwait">—</span></div>
     </div>
     <nav class="bnav">
        <a href="recipes_view.php"><i class="fa-solid fa-book-open"></i> Drink Recipes</a>
        <?php if (can('my_profile')): ?><a href="profile.php"><i class="fa-solid fa-circle-user"></i> Profile</a><?php endif; ?>
        <button id="clockBtn" data-clocked="<?= $_is_clocked_in ? '1':'0' ?>" onclick="toggleClock()">
           <i class="fa-solid fa-<?= $_is_clocked_in ? 'right-from-bracket':'fingerprint' ?>"></i> <?= $_is_clocked_in ? 'Clock Out':'Clock In' ?>
        </button>
        <a href="logout.php" style="color:#ff6b6b" title="Log out &amp; clock out"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
     </nav>
  </aside>
  <main class="bmain">
     <div class="bmain-head">
        <h1><i class="fa-solid fa-receipt"></i> Orders <span class="vo-live-badge"><span class="dot"></span> Live</span></h1>
        <div class="bhead-right">
           <input type="text" id="searchInput" placeholder="Search name, order #, drink…" oninput="searchOrders()"
                  style="width:240px;max-width:100%;padding:10px 16px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,.05);color:var(--text);font-family:'Poppins',sans-serif;font-size:14px;outline:none">
           <div class="bclock" title="Current time"><i class="fa-regular fa-clock"></i> <span id="bClockTime">—</span> <span class="bclock-date" id="bClockDate"></span></div>
           <div class="bnotif-wrap">
              <button class="bhead-btn" id="bBell" type="button" onclick="toggleBaristaNotif(event)" title="Notifications">
                 <i class="fa-regular fa-bell"></i>
                 <span class="bhead-badge" id="bBellCount" style="display:none">0</span>
              </button>
              <div class="bnotif-panel" id="bNotifPanel">
                 <div class="bnotif-head">Notifications</div>
                 <div id="annContainer"></div>
                 <div class="bnotif-empty" id="bNotifEmpty">You're all caught up.</div>
                 <div class="bnotif-foot"><button type="button" onclick="baristaMarkAllRead()">Mark all as read</button></div>
              </div>
           </div>
           <a class="bhead-btn" href="profile.php" title="My Profile"><i class="fa-regular fa-circle-user"></i></a>
        </div>
     </div>
     <div class="container" style="max-width:none;margin:0">
        <div class="orders-grid" id="ordersBody"></div>
     </div>
  </main>
</div>
<?php else: ?>
<!-- Top-left: Nav + Identity -->
<div style="position:fixed;top:24px;left:24px;display:flex;flex-direction:column;gap:18px;z-index:100;">
    <div style="display:flex;gap:10px;">
        <?php if (($_SESSION['role'] ?? '') !== 'barista'): ?>
        <a href="menu.php" class="back" style="position:static;">
            <i class="fa-solid fa-mug-hot"></i> Menu
        </a>
        <?php endif; ?>
        <?php if (($_SESSION['role'] ?? '') === 'barista'): ?>
        <a href="recipes_view.php" class="back" style="position:static;">
            <i class="fa-solid fa-book-open"></i> Drink Recipe
        </a>
        <?php else: ?>
        <a href="dashboard.php" class="back" style="position:static;">
            <i class="fa-solid fa-gauge"></i> Dashboard
        </a>
        <?php endif; ?>
    </div>
    <div style="padding-left:4px;">
        <div style="font-size:16px;font-weight:700;color:var(--text);line-height:1.3;margin-bottom:3px;">
            <?= $_greeting ?>, <span style="color:var(--accent);"><?= $_vo_username ?></span>
        </div>
        <div style="font-size:12px;color:var(--text-muted);display:flex;align-items:center;gap:5px;margin-bottom:7px;">
            <i class="fa-regular fa-calendar" style="font-size:11px;"></i> <?= $_date_str ?>
        </div>
        <div style="display:inline-flex;align-items:center;gap:6px;
                    padding:4px 12px;border-radius:50px;font-size:12px;font-weight:600;
                    color:<?= $_role_color ?>;
                    background:<?= $_role_color ?>18;
                    border:1px solid <?= $_role_color ?>40;
                    letter-spacing:.3px;">
            <span style="width:7px;height:7px;border-radius:50%;background:<?= $_role_color ?>;
                         box-shadow:0 0 5px <?= $_role_color ?>;
                         animation:pulse-dot 2s infinite;display:inline-block;"></span>
            <?= $_role_label ?>
        </div>
    </div>
</div>

<!-- Top-right: Clock + Profile + Logout -->
<div style="position:fixed;top:24px;right:24px;display:flex;gap:8px;z-index:100;">
    <?php
    $clocked = $_is_clocked_in;
    $clkBg    = $clocked ? 'rgba(255,95,95,.08)'   : 'rgba(85,224,135,.08)';
    $clkBr    = $clocked ? 'rgba(255,95,95,.25)'   : 'rgba(85,224,135,.25)';
    $clkColor = $clocked ? '#ff6b6b'               : '#55e087';
    $clkIcon  = $clocked ? 'right-from-bracket'    : 'fingerprint';
    $clkLabel = $clocked ? 'Clock Out'             : 'Clock In';
    $clkTitle = $clocked ? 'Clocked in at ' . $_clock_since : 'Not clocked in';
    ?>
    <button id="clockBtn" data-clocked="<?= $clocked ? '1' : '0' ?>"
        onclick="toggleClock()"
        title="<?= htmlspecialchars($clkTitle) ?>"
        style="position:static;display:inline-flex;align-items:center;gap:7px;
               padding:7px 14px;border-radius:8px;font-size:13px;font-family:'Poppins',sans-serif;font-weight:500;cursor:pointer;
               background:<?= $clkBg ?>;border:1px solid <?= $clkBr ?>;color:<?= $clkColor ?>;transition:all .2s;">
        <i class="fa-solid fa-<?= $clkIcon ?>"></i> <?= $clkLabel ?>
    </button>
    <?php if (can('my_profile')): ?>
    <a href="profile.php" class="back" style="position:static;" title="My Profile">
        <i class="fa-solid fa-circle-user"></i> Profile
    </a>
    <?php endif; ?>
    <a href="shift_report.php" class="back" style="position:static;background:rgba(255,95,95,.08);border-color:rgba(255,95,95,.25);color:#ff6b6b;" title="View shift report &amp; log out">
        <i class="fa-solid fa-right-from-bracket"></i> Logout
    </a>
</div>

<!-- Header -->
<div style="text-align:center;padding-top:28px;margin-bottom:32px;">
    <h1 style="color:var(--accent);font-size:28px;font-weight:800;display:inline-flex;align-items:center;
               gap:10px;margin:0 0 8px;text-shadow:0 0 24px rgba(209,144,75,.2);">
        <i class="fa-solid fa-receipt"></i> Orders
    </h1>
    <br>
    <div class="vo-live-badge" style="display:inline-flex;">
        <span class="dot"></span> Live
    </div>
</div>

<!-- Announcements -->
<div id="annContainer" style="max-width:900px;margin:0 auto 0;padding:0 20px;"></div>

<!-- Status Tabs -->
<div class="status-tabs" id="statusTabs">
    <?php if ($r === 'admin' || $r === 'manager'): ?>
    <button class="status-tab active" data-status="all" onclick="filterStatus('all')">
        📋 All <span class="badge" id="count-all">0</span>
    </button>
    <?php endif; ?>
    <button class="status-tab <?= $r === 'barista' ? 'active' : '' ?>" data-status="Preparing" onclick="filterStatus('Preparing')">
        👨‍🍳 Preparing <span class="badge" id="count-Preparing">0</span>
    </button>
    <button class="status-tab <?= $r === 'staff' ? 'active' : '' ?>" data-status="Completed" onclick="filterStatus('Completed')">
        ✅ Completed <span class="badge" id="count-Completed">0</span>
    </button>
    <button class="status-tab" data-status="Cancelled" onclick="filterStatus('Cancelled')">
        ❌ Cancelled <span class="badge" id="count-Cancelled">0</span>
    </button>
    <?php if ($r === 'admin' || $r === 'manager'): ?>
    <button class="status-tab" data-status="Refunded" onclick="filterStatus('Refunded')">
        🔄 Refunded <span class="badge" id="count-Refunded">0</span>
    </button>
    <?php endif; ?>
</div>

<!-- Search Bar -->
<div class="search-bar" style="display:flex; justify-content:center; gap:12px; margin-bottom:20px; align-items:center; flex-wrap:wrap;">
    <input type="text" id="searchInput" placeholder="Search by customer name, order #, or status..."
           oninput="searchOrders()" onkeydown="if(event.key==='Escape')clearSearch()"
           style="width:300px; padding:10px 16px; border-radius:10px; border:1px solid rgba(255,255,255,0.09); background:rgba(255,255,255,0.05); backdrop-filter:blur(20px); -webkit-backdrop-filter:blur(20px); color:var(--text); font-family:'Poppins',sans-serif; font-size:14px; outline:none; transition:var(--transition); box-shadow:0 2px 8px rgba(0,0,0,0.3), inset 0 1px 0 rgba(255,255,255,0.06);">
    <button class="btn" onclick="searchOrders()" 
            style="padding:10px 20px; border-radius:10px; border:none; background:var(--accent); color:#000; font-weight:600; cursor:pointer; transition:var(--transition); font-family:'Poppins',sans-serif; font-size:14px; display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-magnifying-glass"></i> Search
    </button>
    <button class="btn-clear" onclick="clearSearch()" 
            style="padding:10px 20px; border-radius:10px; border:1px solid var(--border); background:rgba(18, 18, 18, 0.5); backdrop-filter:blur(10px); color:var(--text); font-weight:500; cursor:pointer; transition:var(--transition); font-family:'Poppins',sans-serif; font-size:14px; display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-xmark"></i> Clear
    </button>
</div>


<!-- Orders Grid -->
<div class="container">
    <div class="orders-grid" id="ordersBody"></div>
</div>
<?php endif; ?>

<!-- Call Notification Modal -->
<div class="call-modal" id="callModal">
    <div class="call-modal-content">
        <h2>🔔 Order Ready!</h2>
        <div class="order-number" id="callOrderNumber">#001</div>
        <p id="callCustomerName">Customer</p>
        <button class="btn-dismiss" onclick="dismissCall()">Dismiss</button>
    </div>
</div>

<!-- Cancel Modal -->
<div class="cancel-modal" id="cancelModal">
    <div class="cancel-modal-content">
        <h2><i class="fa-solid fa-ban"></i> Cancel Order</h2>
        <div class="order-number" id="cancelOrderNumber">#001</div>
        <p style="color: var(--text-muted);">Please provide a reason for cancellation:</p>
        <textarea id="cancelReason" placeholder="Why is this order being cancelled?"></textarea>
        <div class="btn-group">
            <button class="btn-cancel-yes" onclick="confirmCancel()">
                <i class="fa-solid fa-check"></i> Yes, Cancel
            </button>
            <button class="btn-cancel-no" onclick="closeCancelModal()">
                <i class="fa-solid fa-times"></i> Back
            </button>
        </div>
    </div>
</div>

<!-- Remake Modal -->
<div class="refund-modal" id="remakeModal">
    <div class="refund-modal-content" style="border-color:rgba(52,152,219,.4);max-width:520px;">
        <h2 style="color:#3498db;"><i class="fa-solid fa-repeat"></i> Remake Order</h2>
        <div class="order-number" id="remakeOrderNumber">#001</div>
        <p style="color: var(--text-muted); margin-bottom: 14px;">Adjust the drink and enter the reason:</p>
        <div id="remakeAdjustments"></div>
        <div class="form-group">
            <label>Reason for Remake</label>
            <textarea id="remakeReason" placeholder="e.g. Too sweet, wrong milk, customer not satisfied..."></textarea>
        </div>
        <div class="btn-group">
            <button class="btn-refund-yes" style="background:#3498db;" onclick="confirmRemake()">
                <i class="fa-solid fa-repeat"></i> Log Remake
            </button>
            <button class="btn-refund-no" onclick="closeRemakeModal()">
                <i class="fa-solid fa-times"></i> Cancel
            </button>
        </div>
    </div>
</div>

<!-- Refund Modal -->
<div class="refund-modal" id="refundModal">
    <div class="refund-modal-content">
        <h2><i class="fa-solid fa-rotate-left"></i> Refund Order</h2>
        <div class="order-number" id="refundOrderNumber">#001</div>
        <p style="color: var(--text-muted);">Enter refund details:</p>
        
        <div class="form-group">
            <label>Refund Amount ($)</label>
            <input type="number" step="0.01" id="refundAmount" value="">
        </div>
        
        <div class="form-group">
            <label>Reason for Refund</label>
            <textarea id="refundReason" placeholder="Why is this order being refunded?"></textarea>
        </div>
        
        <div class="btn-group">
            <button class="btn-refund-yes" onclick="confirmRefund()">
                <i class="fa-solid fa-check"></i> Process Refund
            </button>
            <button class="btn-refund-no" onclick="closeRefundModal()">
                <i class="fa-solid fa-times"></i> Cancel
            </button>
        </div>
    </div>
</div>

<!-- Audio Notifications -->
<audio id="bell">
    <source src="audio/bell.wav" type="audio/wav">
</audio>

<audio id="action">
    <source src="audio/order_arrived.wav" type="audio/wav">
</audio>

<audio id="callSound">
    <source src="audio/order_arrived.wav" type="audio/wav">
</audio>

<script>
const tbody = document.getElementById("ordersBody");
const known = new Set();
let currentFilter = '<?= (in_array($_SESSION['role'] ?? '', ['admin', 'manager'])) ? 'all' : (($_SESSION['role'] ?? '') === 'barista' ? 'Preparing' : 'Completed') ?>';
let showCompleted = true;
let searchQuery = '';
let currentCancelId = 0;
let currentRefundId = 0;
let currentRemakeId = 0;
let allOrders = [];

// ── Get user role from PHP ──
const userRole = "<?= $_SESSION['role'] ?? 'staff' ?>";
const OVERDUE_MINUTES = <?= (int)OVERDUE_MINUTES ?>;
const isAdmin = userRole === 'admin';
const canManageOrders = userRole === 'admin' || userRole === 'manager';
const canRemake = userRole === 'admin' || userRole === 'manager' || userRole === 'staff';

// ── Play Sound ──
function play(id) {
    const a = document.getElementById(id);
    if (a) { a.currentTime = 0; a.play().catch(() => {}); }
}

// ── Escape HTML ──
function escapeHtml(text) {
    return String(text ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function truncReason(text, max = 80) {
    const s = String(text ?? '').trim();
    return s.length > max ? s.slice(0, max) + '…' : s;
}

function roleLabel(role) {
    const map = <?= json_encode(array_map(fn($r) => $r['name'], $_all_roles), JSON_UNESCAPED_UNICODE) ?>;
    return map[role] || role;
}

// ── Empty-state markup (role-aware, circle-wrapped icon) ──
function ordersEmptyHtml() {
    const barista = userRole === 'barista';
    const iconClass = barista ? 'fa-solid fa-mug-hot' : 'fa-regular fa-rectangle-list';
    const title = barista ? 'All caught up' : 'No Orders Yet';
    const sub   = barista ? "No orders in the queue right now — take a breather." : 'Orders will appear here in real-time.';
    return '<div style="width:72px;height:72px;border-radius:50%;background:rgba(255,255,255,.04);display:flex;align-items:center;justify-content:center;margin:0 auto 18px">'
        + '<i class="' + iconClass + '" style="font-size:30px;color:var(--text-muted);opacity:.75"></i></div>'
        + '<h3 style="color:var(--text);margin-bottom:6px;font-size:18px">' + title + '</h3>'
        + '<p style="font-size:13.5px">' + sub + '</p>';
}

// ── Build Items HTML ──
function buildItems(items) {
    if (!items || items.length === 0) {
        return '<div style="color:var(--text-muted);font-size:12px;padding:4px 0;">No items</div>';
    }
    let html = '<div class="items-list">';
    items.forEach(i => {
        const chips = [];
        if (i.size)      chips.push(`<span class="item-chip">📏 Size: ${escapeHtml(i.size)}</span>`);
        if (i.sweetness) chips.push(`<span class="item-chip">🍬 ${escapeHtml(i.sweetness)}</span>`);
        if (i.ice)       chips.push(`<span class="item-chip">🧊 ${escapeHtml(i.ice)}</span>`);
        if (i.milk)      chips.push(`<span class="item-chip">🥛 ${escapeHtml(i.milk)}</span>`);
        if (i.addons && i.addons.length) chips.push(`<span class="item-chip">➕ Add-ons: ${escapeHtml(i.addons.join(', '))}</span>`);

        html += `<div class="item-line">
            <div class="item-qty-badge">×${escapeHtml(String(i.quantity))}</div>
            <div class="item-body">
                <div class="item-name">${escapeHtml(i.product_name)}</div>
                ${chips.length ? `<div class="item-chips">${chips.join('')}</div>` : ''}
            </div>
        </div>`;
    });
    html += '</div>';
    return html;
}

// ── Board state ──
// This board is about FULFILMENT; money lives in find_order.php. `orders.status`
// mixes both concerns, so 'Paid' would otherwise surface as a money-word tab here.
// Translate it into what it means for making drinks:
//   Paid + is_open=0 → settled and closed, nothing left to do  → Completed
//   Paid + is_open=1 → paid, tab still open, not yet made      → Preparing (work queue)
// Everything else already names a fulfilment state. Action buttons deliberately keep
// using the REAL o.status — only display/filtering is translated.
function boardState(o) {
    if (o.status === 'Paid') return Number(o.is_open) === 1 ? 'Preparing' : 'Completed';
    return o.status;
}

// ── Determine Status Badge ──
function getStatusBadge(status) {
    let statusClass = status;
    let statusText = status;

    if (status === 'PendingPayment') {
        statusText = '⏳ Pending';
    } else if (status === 'Paid') {
        statusText = '💵 Paid';
    } else if (status === 'Preparing') {
        statusText = '👨‍🍳 Preparing';
    } else if (status === 'Completed') {
        statusText = '✅ Completed';
    } else if (status === 'Cancelled') {
        statusText = '❌ Cancelled';
    } else if (status === 'Refunded') {
        statusText = '🔄 Refunded';
    }
    
    return `<span class="status ${statusClass}">${statusText}</span>`;
}

// ── Get Time Ago ──
function timeAgo(dateString) {
    const now = new Date();
    const past = new Date(dateString);
    const diff = Math.floor((now - past) / 1000);
    
    if (diff < 60) return diff + ' sec ago';
    if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
    if (diff < 86400) return Math.floor(diff / 3600) + ' hr ago';
    return Math.floor(diff / 86400) + ' day ago';
}

// ── Barista helpers ──
// Readable elapsed: minutes → "Xm", "Xh", "Xh Ym", "Xd"
function elapsedShort(ts) {
    if (!ts) return '';
    const m = Math.max(0, Math.floor((Date.now() - new Date(ts.replace(' ','T'))) / 60000));
    if (m < 60) return m + 'm';
    if (m < 1440) { const h = Math.floor(m/60), r = m%60; return r ? `${h}h ${r}m` : `${h}h`; }
    return Math.floor(m/1440) + 'd';
}
// Age basis: started_at when present else order_date. Guard invalid/missing dates → 0 (never NaN).
function orderAgeMin(o) {
    const basis = o.started_at || o.order_date;
    if (!basis) return 0;
    const t = new Date(String(basis).replace(' ','T')).getTime();
    if (isNaN(t)) return 0;
    return Math.max(0, Math.floor((Date.now() - t) / 60000));
}
// Chips for one item, capped with "+N more"
function baristaItemChips(i) {
    const chips = [];
    if (i.size)      chips.push(escapeHtml(i.size));
    if (i.sweetness) chips.push(escapeHtml(i.sweetness));
    if (i.ice)       chips.push(escapeHtml(i.ice));
    if (i.milk)      chips.push(escapeHtml(i.milk));
    if (i.addons && i.addons.length) i.addons.forEach(a => chips.push(escapeHtml(a)));
    const CAP = 5;
    let shown = chips.slice(0, CAP).map(c => `<span class="bchip">${c}</span>`).join('');
    if (chips.length > CAP) shown += `<span class="bchip bchip-more">+${chips.length - CAP} more</span>`;
    return shown;
}

// ── Build Card Inner HTML ──
function buildCardInner(o) {
    if (userRole === 'barista') return buildBaristaCardInner(o);
    const tableTag = o.table_number
        ? `<span class="card-table-badge"><i class="fa-solid fa-ticket" style="font-size:9px"></i> Stand ${escapeHtml(o.table_number)}</span>`
        : '';
    return `
        <div class="card-header">
            <div class="card-order-num">#${escapeHtml(String(o.daily_order_no))}</div>
            <div class="card-header-right">
                ${getStatusBadge(boardState(o))}
                ${ageBadgeHtml(o.order_date, o.status)}
                ${o.remake_count > 0 ? `<span class="badge-remade"><i class="fa-solid fa-repeat" style="font-size:9px"></i> Remade${o.remake_count > 1 ? ` ×${o.remake_count}` : ''}</span>` : ''}
                <div class="card-total">$${parseFloat(o.total || 0).toFixed(2)}</div>
            </div>
        </div>
        <div class="card-customer-row">
            <div class="card-customer-name">
                <i class="fa-regular fa-user"></i>${escapeHtml(o.customer_name || 'Guest')}
            </div>
            <div class="card-time">
                <i class="fa-regular fa-clock"></i>
                <span data-timestamp="${escapeHtml(o.order_date)}">${timeAgo(o.order_date)}</span>
            </div>
        </div>
        ${tableTag}
        <div class="card-divider"></div>
        ${buildItems(o.items || [])}
        <div class="card-footer">
            <div class="card-employee">
                <i class="fa-solid fa-cash-register" style="font-size:11px;opacity:.85"></i>
                <span style="opacity:.65;font-size:10px;">Taken by:</span>
                ${escapeHtml(o.employee_name || 'Unknown')}
                ${o.employee_role ? `<span style="opacity:.55;font-size:10px;">(${roleLabel(o.employee_role)})</span>` : ''}
            </div>
            ${o.prepared_by ? `
            <div class="card-employee" style="margin-top:3px;">
                <i class="fa-solid fa-mug-hot" style="font-size:11px;opacity:.85;color:#d1904b"></i>
                <span style="opacity:.65;font-size:10px;">Prepared by:</span>
                ${escapeHtml(o.prepared_by)}
                ${o.prepared_by_role ? `<span style="opacity:.55;font-size:10px;">(${roleLabel(o.prepared_by_role)})</span>` : ''}
            </div>` : ''}
        </div>
        ${o.status === 'Cancelled' && o.cancel_reason ? `<div class="card-reason cancel-reason" title="${escapeHtml(o.cancel_reason)}"><i class="fa-solid fa-ban"></i><span>${escapeHtml(truncReason(o.cancel_reason))}${o.cancelled_by ? ` <span style="opacity:.6;font-style:normal;">— ${escapeHtml(o.cancelled_by)}</span>` : ''}</span></div>` : ''}
        ${o.status === 'Refunded' && o.refund_reason ? `<div class="card-reason refund-reason" title="${escapeHtml(o.refund_reason)}"><i class="fa-solid fa-rotate-left"></i><span>${escapeHtml(truncReason(o.refund_reason))}${o.refunded_by ? ` <span style="opacity:.6;font-style:normal;">— ${escapeHtml(o.refunded_by)}</span>` : ''}</span></div>` : ''}
        ${o.remake_count > 0 && o.remake_reasons && o.remake_reasons.length > 0 ? `<div class="card-reason remake-reason"><i class="fa-solid fa-repeat"></i>${
            o.remake_reasons.length === 1
                ? `<span>${escapeHtml(truncReason(o.remake_reasons[0]))}</span>`
                : `<ul class="reason-list">${o.remake_reasons.map(r => `<li>${escapeHtml(truncReason(r))}</li>`).join('')}</ul>`
        }</div>` : ''}
        <div class="card-actions">${getActionButtons(o)}</div>
    `;
}

// ── Build Barista Card Inner HTML (station view) ──
function buildBaristaCardInner(o) {
    const age = orderAgeMin(o);
    // Board state throughout: the badge already used it, so reading the raw status
    // for the overdue test could have shown a "Preparing" badge on a card the same
    // function decided was Preparing by a different rule.
    const isQueued = boardState(o) === 'Preparing';
    const overdue = isQueued && age >= OVERDUE_MINUTES;
    const badge = isQueued
        ? `<span class="bcard-badge ${overdue ? 'overdue' : 'prep'}">${overdue ? '<i class="fa-solid fa-circle-exclamation"></i> Overdue' : '<i class="fa-solid fa-hourglass-half"></i> Preparing'}</span>`
        : getStatusBadge(boardState(o));
    // Barista sees only UNMADE drinks — made ones are hidden so the queue shows just what's
    // left to make (baristas don't triage new-vs-old, they only make what's shown). On a
    // re-opened tab the already-made drinks drop off; only the newly-added ones remain.
    // Fall back to the full list if somehow every drink is already made (never an empty card).
    const _all = o.items || [];
    const _unmade = _all.filter(i => !i.is_made);
    const _shown = _unmade.length ? _unmade : _all;
    const items = _shown.map(i => `
        <div class="bitem">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span class="bitem-name">${escapeHtml(String(i.quantity))}× ${escapeHtml(i.product_name)}</span>
                ${i.category ? `<span class="bcat">${escapeHtml(i.category)}</span>` : ''}
            </div>
            <div class="bchips">${baristaItemChips(i)}</div>
        </div>`).join('') || '<div style="color:var(--text-muted);font-size:12px;padding-left:8px">No items</div>';
    // Fulfilment type from order_type (drink_in / drink_out); real name + stand no. when present
    const isOut     = o.order_type === 'drink_out';
    const typeLabel = isOut ? 'Drink Out' : 'Drink In';
    const typeIcon  = isOut ? 'fa-bag-shopping' : 'fa-mug-hot';
    const standTag  = (!isOut && o.table_number) ? ` · Stand ${escapeHtml(o.table_number)}` : '';
    const realName  = (o.customer_name && o.customer_name !== 'Guest') ? ` · ${escapeHtml(o.customer_name)}` : '';
    return `
        <div class="bcard-top">
            <span class="bcard-num">#${escapeHtml(String(o.daily_order_no))}</span>
            ${badge}
            ${o.remake_count > 0 ? `<span class="bcard-badge remade"><i class="fa-solid fa-repeat" style="font-size:9px"></i> Remade${o.remake_count > 1 ? ` ×${o.remake_count}` : ''}</span>` : ''}
            ${o.is_returning ? '<span class="bcard-badge returning"><i class="fa-solid fa-rotate-right"></i> Returning tab</span>' : ''}
        </div>
        <div class="bcard-sub">
            <span><i class="fa-solid ${typeIcon}"></i> ${typeLabel}${standTag}${realName}</span>
            <span data-bts="${escapeHtml(o.started_at || o.order_date)}"><i class="fa-regular fa-clock"></i>${elapsedShort(o.started_at || o.order_date)}</span>
        </div>
        ${items}
        <div class="card-footer" style="margin-top:8px">
            <div class="card-employee"><span style="opacity:.6;font-size:10px">Taken by:</span> ${escapeHtml(o.employee_name || 'Unknown')}</div>
        </div>
        ${o.remake_count > 0 && o.remake_reasons && o.remake_reasons.length > 0 ? `<div class="card-reason remake-reason" style="margin:6px 0 2px;"><i class="fa-solid fa-repeat"></i>${
            o.remake_reasons.length === 1
                ? `<span>${escapeHtml(truncReason(o.remake_reasons[0]))}</span>`
                : `<ul class="reason-list">${o.remake_reasons.map(r => `<li>${escapeHtml(truncReason(r))}</li>`).join('')}</ul>`
        }</div>` : ''}
        <div class="card-actions">${getActionButtons(o)}</div>
    `;
}

// ── Barista header: live clock + announcements bell ──
function updateBaristaClock() {
    const t = document.getElementById('bClockTime');
    if (!t) return;
    const now = new Date();
    t.textContent = now.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    const d = document.getElementById('bClockDate');
    if (d) d.textContent = '· ' + now.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' });
}
function updateBaristaBell() {
    const badge = document.getElementById('bBellCount');
    if (!badge) return;
    const n = document.querySelectorAll('#annContainer .ann-banner').length;
    badge.textContent = n;
    badge.style.display = n > 0 ? 'flex' : 'none';
    const empty = document.getElementById('bNotifEmpty');
    const foot  = document.querySelector('.bnotif-foot');
    if (empty) empty.style.display = n > 0 ? 'none' : 'block';
    if (foot)  foot.style.display  = n > 0 ? 'block' : 'none';
}
function toggleBaristaNotif(e) {
    if (e) e.stopPropagation();
    const p = document.getElementById('bNotifPanel');
    if (!p) return;
    const open = !p.classList.contains('open');
    p.classList.toggle('open', open);
    if (open) setTimeout(() => document.addEventListener('click', _closeNotifOutside), 0);
    else document.removeEventListener('click', _closeNotifOutside);
}
function _closeNotifOutside(e) {
    const w = document.querySelector('.bnotif-wrap');
    const p = document.getElementById('bNotifPanel');
    if (w && !w.contains(e.target)) { if (p) p.classList.remove('open'); document.removeEventListener('click', _closeNotifOutside); }
}
function baristaMarkAllRead() {
    document.querySelectorAll('#annContainer .ann-banner').forEach(b => {
        const id = parseInt(b.dataset.id);
        const dismissed = _annDismissed();
        if (!dismissed.includes(id)) { dismissed.push(id); localStorage.setItem('ann_dismissed', JSON.stringify(dismissed)); }
        b.remove();
    });
    updateBaristaBell();
    const p = document.getElementById('bNotifPanel'); if (p) p.classList.remove('open');
    document.removeEventListener('click', _closeNotifOutside);
}
if (userRole === 'barista') {
    updateBaristaClock();
    setInterval(updateBaristaClock, 15000);
    updateBaristaBell();
}

// ── Barista sidebar stats (queue, overdue, done today, avg wait) ──
function updateBaristaStats() {
    if (userRole !== 'barista') return;
    const el = id => document.getElementById(id);
    if (!el('stat-queue')) return;
    let queue = 0, overdue = 0, done = 0, waitSum = 0, waitN = 0;
    (allOrders || []).forEach(o => {
        /* Board state, not raw status. These stats count DRINKS MADE, and a settled
           order carries status='Paid' while the board shows it as Completed — 191
           orders here — so counting on the raw status left them in neither bucket
           and under-reported the day's work. Symmetrically, a paid-but-still-open
           order belongs in the queue. */
        const st = boardState(o);
        if (st === 'Preparing') {
            queue++;
            if (orderAgeMin(o) >= OVERDUE_MINUTES) overdue++;
        } else if (st === 'Completed') {
            done++;
            const basis = o.started_at || o.order_date;
            if (o.completed_at && basis) {
                const mins = (new Date(String(o.completed_at).replace(' ','T')) - new Date(String(basis).replace(' ','T'))) / 60000;
                if (mins >= 0) { waitSum += mins; waitN++; }
            }
        }
    });
    el('stat-queue').textContent   = queue;
    el('stat-overdue').textContent = overdue;
    el('stat-done').textContent    = done;
    el('stat-avgwait').textContent = waitN ? (waitSum / waitN).toFixed(1) + 'm' : '—';
    el('stat-overdue-row').classList.toggle('is-alert', overdue > 0);
}

// ── Add Row ──
function addRow(o) {
    const card = document.createElement("div");
    card.id = "row-" + o.order_id;
    card.className = "order-card" + (o.remake_count > 0 ? " is-remade" : "");
    card.dataset.status = boardState(o);
    card.dataset.orderId = o.order_id;
    if (userRole === 'barista') {
        card.classList.add('bcard');
        // Board state: a paid-but-open order is still work to make, so it can go
        // overdue like anything else in the queue.
        if (boardState(o) === 'Preparing') {
            const age = orderAgeMin(o);
            if (age >= OVERDUE_MINUTES) card.classList.add('is-overdue');
            else if (age >= Math.floor(OVERDUE_MINUTES * 0.7)) card.classList.add('is-warn');
        }
    }
    /* Hide what the card SAYS it is. On the raw status a settled order reads 'Paid'
       and survived the toggle, so 191 cards labelled "Completed" stayed on screen
       with "show completed" off — the filter disagreeing with its own badges. */
    if ((boardState(o) === 'Completed' || o.status === 'Refunded') && !showCompleted) {
        card.style.display = 'none';
    }
    card.innerHTML = buildCardInner(o);
    tbody.appendChild(card);
}

// ── Get Action Buttons ──
function getActionButtons(o) {
    let buttons = '';
    
    /* These read the BOARD state, not the raw status. boardState() shows a settled
       'Paid' order as "Completed", so reading o.status here made the buttons describe
       a different order from the badge above them: Call and Cancel on a finished sale,
       with Refund and Remake — the ones actually wanted — never rendering. */
    const state = boardState(o);

    // Call button - only for an order still in the queue
    if (state === 'Preparing') {
        buttons += `
            <button class="call-btn" onclick="callOrder(${Number(o.order_id)}, '${escapeHtml(o.customer_name)}', ${Number(o.daily_order_no)})" title="Call customer">
                <i class="fa-solid fa-bell"></i> Call
            </button>
        `;
    }
    
    // Paid button - only for PendingPayment, and not for barista (cashier/manager job)
    if (o.status === 'PendingPayment' && userRole !== 'barista') {
        buttons += `
            <button class="paid-btn" onclick="markPaid(${Number(o.order_id)})" title="Mark as paid">
                <i class="fa-solid fa-credit-card"></i> Paid
            </button>
        `;
    }
    
    // Complete button - only for an order still being made
    if (state === 'Preparing') {
        buttons += `
            <button class="complete-btn" onclick="completeOrder(${Number(o.order_id)})" title="Complete order">
                <i class="fa-solid fa-check"></i> Complete
            </button>
        `;
    }
    
    // Cancel button - staff(cashier): Pending only; others: anything except Completed/Cancelled/Refunded
    // Never on a settled sale: cancel_order.php refuses it, because cancelling a paid
    // order records no refund and drops it out of revenue with the cash still taken.
    const canCancel = state !== 'Completed' && o.status !== 'Paid'
        && o.status !== 'Cancelled' && o.status !== 'Refunded' && userRole !== 'barista'
        && (userRole !== 'staff' || o.status === 'PendingPayment');
    if (canCancel) {
        buttons += `
            <button class="cancel-btn" onclick="showCancelModal(${Number(o.order_id)}, ${Number(o.daily_order_no)})" title="Cancel order">
                <i class="fa-solid fa-ban"></i> Cancel
            </button>
        `;
    }
    
    if (state === 'Completed') {
        // An open pay-later tab has taken no money — 'Completed' there means made-but-owing.
        // Refunding it would erase the debt and record cash that was never collected.
        const unpaidTab = o.payment_method === 'paylater' && Number(o.is_open) === 1;
        // Refund only for admin/manager, and only if not already remade
        if (canManageOrders && !o.is_remade && !unpaidTab) {
            buttons += `
                <button class="refund-btn" onclick="showRefundModal(${Number(o.order_id)}, ${Number(o.daily_order_no)}, ${parseFloat(o.total).toFixed(2)})" title="Refund order">
                    <i class="fa-solid fa-rotate-left"></i> Refund
                </button>
            `;
        }
        if (canRemake) {
            buttons += `
                <button class="remake-btn" onclick="showRemakeModal(${Number(o.order_id)}, ${Number(o.daily_order_no)})" title="Log remake">
                    <i class="fa-solid fa-repeat"></i> Remake
                </button>
            `;
        }
    }
    
    // Delete button - only for PendingPayment or Cancelled (admin only)
    if ((o.status === 'PendingPayment' || o.status === 'Cancelled') && isAdmin) {
        buttons += `
            <button class="delete-btn" onclick="removeOrder(${Number(o.order_id)})" title="Delete order">
                <i class="fa-solid fa-trash-can"></i>
            </button>
        `;
    }
    
    return buttons || `<span style="color:var(--text-muted);font-size:12px;padding:4px 0;">—</span>`;
}

// ── Update Existing Row ──
function updateExistingRow(o) {
    const card = document.getElementById("row-" + o.order_id);
    if (!card) return;

    // Play bell when a remade order transitions back to Preparing. dataset.status
    // already holds the BOARD state, so compare like with like.
    if (boardState(o) === 'Preparing' && o.remake_count > 0 && card.dataset.status !== 'Preparing') {
        play('bell');
    }

    // Same rule as addRow: hide what the card says it is, not the raw status.
    if ((boardState(o) === 'Completed' || o.status === 'Refunded') && !showCompleted) {
        card.style.display = 'none';
    } else {
        card.style.display = '';
    }
    card.dataset.status = boardState(o);
    card.className = "order-card" + (o.remake_count > 0 ? " is-remade" : "");
    if (userRole === 'barista') {
        card.classList.add('bcard');
        if (boardState(o) === 'Preparing') {
            const age = orderAgeMin(o);
            if (age >= OVERDUE_MINUTES) card.classList.add('is-overdue');
            else if (age >= Math.floor(OVERDUE_MINUTES * 0.7)) card.classList.add('is-warn');
        }
    }
    card.innerHTML = buildCardInner(o);
}

// ── Apply Filters ──
function applyFilters() {
    const cards = document.querySelectorAll('#ordersBody .order-card');
    const query = searchQuery.toLowerCase().trim();

    cards.forEach(card => {
        const cardStatus = card.dataset.status;
        let visible = true;

        if (query) {
            // While searching, cross all tabs — match on text content
            const text = card.textContent.toLowerCase();
            visible = text.includes(query);
            // Barista queue is Preparing-only: search must not resurrect Completed/Cancelled cards
            if (userRole === 'barista' && cardStatus !== 'Preparing') visible = false;
        } else {
            // Normal tab + showCompleted filtering
            if (currentFilter !== 'all' && cardStatus !== currentFilter) visible = false;
            if ((cardStatus === 'Completed' || cardStatus === 'Refunded') && !showCompleted) visible = false;
        }

        card.style.display = visible ? '' : 'none';
    });

    // Show empty state if no visible cards
    const anyVisible = Array.from(cards).some(c => c.style.display !== 'none');
    let emptyEl = document.getElementById('ordersEmptyState');
    if (!anyVisible && cards.length > 0) {
        if (!emptyEl) {
            emptyEl = document.createElement('div');
            emptyEl.id = 'ordersEmptyState';
            emptyEl.style.cssText = 'grid-column:1/-1;text-align:center;padding:70px 20px;color:var(--text-muted);';
            emptyEl.innerHTML = ordersEmptyHtml();
            tbody.appendChild(emptyEl);
        }
    } else if (emptyEl) {
        emptyEl.remove();
    }
}

// ── Filter Status ──
function filterStatus(status) {
    currentFilter = status;
    
    // Update active tab
    document.querySelectorAll('.status-tab').forEach(tab => {
        tab.classList.toggle('active', tab.dataset.status === status);
    });
    
    applyFilters();
}

// ── Update Counts ──
function updateCounts(data) {
    const counts = { 
        all: 0, 
        PendingPayment: 0, 
        Paid: 0, 
        Preparing: 0, 
        Completed: 0, 
        Cancelled: 0,
        Refunded: 0
    };
    
    // Count by BOARD state, not raw status, so the tabs always sum to All.
    data.forEach(o => {
        counts.all++;
        const s = boardState(o);
        if (counts[s] !== undefined) {
            counts[s]++;
        }
    });

    const setCount = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    setCount('count-all', counts.all);
    // No PendingPayment tab (worked in find_order.php) and no Paid tab (a money word on a
    // fulfilment board) — boardState folds Paid into Preparing/Completed.
    setCount('count-Preparing', counts.Preparing);
    setCount('count-Completed', counts.Completed);
    setCount('count-Cancelled', counts.Cancelled);
    setCount('count-Refunded', counts.Refunded);
}


// ── Load Orders ──
async function loadOrders() {
    try {
        const r = await fetch("view_order.php?action=fetch", { cache: "no-store" });
        const raw = await r.json();

        // Support both old array format and new {orders, announcements} format
        const data = Array.isArray(raw) ? raw : (raw.orders || []);
        allOrders = data;
        if (raw.announcements !== undefined) updateAnnouncements(raw.announcements);

        const currentIds = new Set();

        if (data.length === 0) {
            if (tbody.children.length === 0) {
                const empty = document.createElement("div");
                empty.id = "ordersEmptyState";
                empty.style.cssText = 'grid-column:1/-1;text-align:center;padding:70px 20px;color:var(--text-muted);';
                empty.innerHTML = ordersEmptyHtml();
                tbody.appendChild(empty);
            }
            return;
        }

        // Remove empty state if present
        const emptyEl = document.getElementById('ordersEmptyState');
        if (emptyEl) emptyEl.remove();

        // Update counts
        updateCounts(data);

        data.forEach(o => {
            const id = String(o.order_id);
            currentIds.add(id);

            if (known.has(id)) {
                updateExistingRow(o);
            } else {
                known.add(id);
                addRow(o);
            }
        });

        // Remove rows that no longer exist
        Array.from(known).forEach(id => {
            if (!currentIds.has(id)) {
                const row = document.getElementById("row-" + id);
                if (row) {
                    row.classList.add("fade-out");
                    setTimeout(() => {
                        row.remove();
                        known.delete(id);
                    }, 400);
                }
            }
        });
        
        // Apply filters after loading
        applyFilters();
        updateBaristaStats();
    } catch (err) {
        console.error("Fetch failed:", err);
    }
}

// ── Call Order ──
function callOrder(id, customerName, orderNumber) {
    play("callSound");
    document.getElementById('callOrderNumber').textContent = '#' + orderNumber;
    document.getElementById('callCustomerName').textContent = 'Customer: ' + customerName;
    document.getElementById('callModal').classList.add('active');
}

// ── Dismiss Call ──
function dismissCall() {
    document.getElementById('callModal').classList.remove('active');
}

// ── Show Cancel Modal ──
function showCancelModal(id, orderNumber) {
    currentCancelId = id;
    document.getElementById('cancelOrderNumber').textContent = '#' + orderNumber;
    document.getElementById('cancelReason').value = '';
    document.getElementById('cancelModal').classList.add('active');
}

// ── Close Cancel Modal ──
function closeCancelModal() {
    document.getElementById('cancelModal').classList.remove('active');
    currentCancelId = 0;
}

// ── Confirm Cancel ──
async function confirmCancel() {
    const reason = document.getElementById('cancelReason').value.trim();
    if (!reason) {
        showToast('Please provide a reason for cancellation.', 'error');
        return;
    }
    
    const id = currentCancelId;
    if (!id) return;
    
    closeCancelModal();
    
    try {
        const formData = new FormData();
        formData.append('cancel_reason', reason);
        formData.append('restore_stock', '1');
        
        const r = await fetch(`cancel_order.php?order_id=${id}`, {
            method: 'POST',
            body: formData
        });
        
        if (!r.ok) {
            const text = await r.text();
            console.error("Server returned error:", text);
            showToast('❌ Server error: ' + r.status, 'error');
            return;
        }
        
        let res;
        try {
            res = await r.json();
        } catch (e) {
            console.error("Failed to parse JSON:", e);
            showToast('❌ Invalid response from server', 'error');
            return;
        }

        if (res.ok) {
            await loadOrders();
            showToast('✅ ' + (res.message || 'Order cancelled successfully'));
        } else {
            showToast('❌ Failed: ' + (res.error || 'Unknown error'), 'error');
        }
    } catch (err) {
        console.error("Fetch error:", err);
        showToast('❌ Request failed: ' + err.message, 'error');
    }
}

// ── Show Refund Modal ──
function showRefundModal(id, orderNumber, total) {
    currentRefundId = id;
    document.getElementById('refundOrderNumber').textContent = '#' + orderNumber;
    document.getElementById('refundAmount').value = total;
    document.getElementById('refundReason').value = '';
    document.getElementById('refundModal').classList.add('active');
}

// ── Close Refund Modal ──
function closeRefundModal() {
    document.getElementById('refundModal').classList.remove('active');
    currentRefundId = 0;
}

// ── Remake Modal ──
const SWEETNESS_OPTS = ['0%', '25%', '50%', '75%', '100%'];
const ICE_OPTS       = ['No Ice', 'Less Ice', 'Normal Ice', 'More Ice'];
// Sourced from milk_options / addons, not hard-coded — Manage Milk and the add-on
// library are the single source of truth for what a drink can be remade with.
const MILK_OPTS        = <?= json_encode($_remake_milk,   JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG) ?>;
// (object) on the outer map only — JSON_FORCE_OBJECT would recurse and turn each
// product's add-on LIST into an object too, leaving it with no .length.
const ADDONS_BY_PRODUCT = <?= json_encode((object)$_remake_addons, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG) ?>;

function buildPillGroup(type, options, current) {
    let html = `<div class="remake-adj-group"><div class="remake-adj-label">${type.toUpperCase()}</div><div class="pill-group">`;
    options.forEach(opt => {
        const sel = opt === current ? ' selected' : '';
        html += `<button type="button" class="pill-opt${sel}" onclick="selectPill(this)" data-type="${type}">${escapeHtml(opt)}</button>`;
    });
    return html + '</div></div>';
}

function selectPill(btn) {
    btn.closest('.pill-group').querySelectorAll('.pill-opt').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
}

// Add-ons are multi-select, unlike the single-choice groups above. Options come from
// what THIS product may be ordered with, not the whole library.
function buildAddonGroup(productId, current) {
    const opts = ADDONS_BY_PRODUCT[productId] || [];
    if (!opts.length) return '';
    const cur = new Set(current || []);
    let html = '<div class="remake-adj-group"><div class="remake-adj-label">ADD-ONS</div><div class="pill-group">';
    opts.forEach(a => {
        const sel = cur.has(a.name) ? ' selected' : '';
        html += `<button type="button" class="pill-opt${sel}" onclick="toggleAddonPill(this)" data-type="addon" data-name="${escapeHtml(a.name)}">${escapeHtml(a.name)}</button>`;
    });
    return html + '</div></div>';
}

function toggleAddonPill(btn) { btn.classList.toggle('selected'); }

/* Quantity and options apply only to a drink being remade, so they stay hidden
   until it is ticked. A visible option pill on an unticked drink implies it will
   be applied, and the server deliberately ignores it. */
function toggleRemakePick(cb) {
    const block = cb.closest('.remake-item-block');
    const on = cb.checked;
    block.querySelector('.remake-qty-row').style.display = on ? '' : 'none';
    block.querySelector('.remake-opts').style.display    = on ? '' : 'none';
    block.style.opacity = on ? '1' : '.55';
}

function showRemakeModal(id, orderNumber) {
    currentRemakeId = id;
    document.getElementById('remakeOrderNumber').textContent = '#' + orderNumber;
    document.getElementById('remakeReason').value = '';

    const order = allOrders.find(o => o.order_id == id);
    const adjDiv = document.getElementById('remakeAdjustments');
    adjDiv.innerHTML = '';

    if (order && order.items && order.items.length > 0) {
        order.items.forEach(item => {
            const block = document.createElement('div');
            block.className = 'remake-item-block';
            block.dataset.itemId = item.item_id;
            // Each remade drink is poured again and deducts real ingredients, so
            // the barista picks which ones and how many rather than the whole order.
            const maxQty = parseInt(item.quantity, 10) || 1;
            block.innerHTML =
                `<div class="remake-item-name">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" class="remake-pick" onchange="toggleRemakePick(this)">
                        <i class="fa-solid fa-mug-hot"></i>
                        ${escapeHtml(item.product_name)}
                        ${maxQty > 1 ? `<span style="opacity:.6;font-weight:400;text-transform:none">×${maxQty} ordered</span>` : ''}
                    </label>
                 </div>` +
                `<div class="remake-qty-row" style="display:none;margin-bottom:8px">
                    <div class="remake-adj-label">How many to remake</div>
                    <input type="number" class="remake-qty" value="1" min="1" max="${maxQty}"
                           style="width:74px;padding:5px 8px;border-radius:6px;
                                  border:1px solid rgba(52,152,219,.25);
                                  background:rgba(0,0,0,.25);color:inherit;
                                  font-family:inherit;font-size:13px">
                 </div>` +
                `<div class="remake-opts" style="display:none">` +
                buildPillGroup('sweetness', SWEETNESS_OPTS, item.sweetness) +
                buildPillGroup('ice',       ICE_OPTS,       item.ice) +
                buildPillGroup('milk',      MILK_OPTS,      item.milk) +
                buildAddonGroup(item.product_id, item.addons) +
                `</div>`;
            block.style.opacity = '.55';
            adjDiv.appendChild(block);
        });
    }

    document.getElementById('remakeModal').classList.add('active');
}

function closeRemakeModal() {
    document.getElementById('remakeModal').classList.remove('active');
    currentRemakeId = 0;
}

async function confirmRemake() {
    const reason = document.getElementById('remakeReason').value.trim();
    if (!reason) { showToast('Please enter a reason for the remake.', 'error'); return; }

    const btn = document.querySelector('#remakeModal .btn-refund-yes');
    btn.disabled = true;

    const adjustments = [];
    const items = [];
    document.querySelectorAll('#remakeAdjustments .remake-item-block').forEach(block => {
        // Only ticked drinks are remade — the rest are neither deducted nor adjusted.
        if (!block.querySelector('.remake-pick').checked) return;
        const itemId = block.dataset.itemId;
        const qty    = parseInt(block.querySelector('.remake-qty').value, 10) || 1;
        items.push({ item_id: itemId, qty: qty });
        adjustments.push({
            item_id:   itemId,
            sweetness: block.querySelector('[data-type="sweetness"].selected')?.textContent || '',
            ice:       block.querySelector('[data-type="ice"].selected')?.textContent || '',
            milk:      block.querySelector('[data-type="milk"].selected')?.textContent || '',
            // Names only — the server re-looks-up prices so the client can't set them.
            addons:    Array.from(block.querySelectorAll('[data-type="addon"].selected'))
                            .map(b => b.dataset.name)
        });
    });

    if (!items.length) {
        showToast('Pick at least one drink to remake.', 'error');
        btn.disabled = false;
        return;
    }

    try {
        const formData = new FormData();
        formData.append('reason', reason);
        formData.append('items', JSON.stringify(items));
        formData.append('adjustments', JSON.stringify(adjustments));
        const r = await fetch(`remake_order.php?order_id=${currentRemakeId}`, { method: 'POST', body: formData });
        const data = await r.json();
        if (data.ok) {
            closeRemakeModal();
            showToast('🔁 ' + data.message);
            if (data.shortfalls && data.shortfalls.length) {
                // Same warning the ordering flow gives. The remake is recorded
                // either way — the drink is already poured — but the shop is short.
                const names = [...new Set(data.shortfalls.map(s => s.name))].join(', ');
                showToast('⚠️ Not enough stock: ' + names, 'error');
            }
            await loadOrders();
        } else {
            showToast('❌ ' + (data.error || 'Failed to log remake.'), 'error');
        }
    } catch(e) {
        showToast('❌ Network error. Please try again.', 'error');
    } finally {
        btn.disabled = false;
    }
}

// ── Confirm Refund ──
async function confirmRefund() {
    const amount = parseFloat(document.getElementById('refundAmount').value);
    const reason = document.getElementById('refundReason').value.trim();
    
    if (!amount || amount <= 0) {
        showToast('Please enter a valid refund amount.', 'error');
        return;
    }
    
    if (!reason) {
        showToast('Please provide a reason for refund.', 'error');
        return;
    }
    
    const id = currentRefundId;
    if (!id) return;
    
    closeRefundModal();
    
    try {
        const formData = new FormData();
        formData.append('refund_amount', amount);
        formData.append('refund_reason', reason);
        
        const r = await fetch(`refund_order.php?order_id=${id}`, {
            method: 'POST',
            body: formData
        });
        
        if (!r.ok) {
            const text = await r.text();
            console.error("Server returned error:", text);
            showToast('❌ Server error: ' + r.status, 'error');
            return;
        }
        
        let res;
        try {
            res = await r.json();
        } catch (e) {
            console.error("Failed to parse JSON:", e);
            showToast('❌ Invalid response from server', 'error');
            return;
        }

        if (res.ok) {
            await loadOrders();
            showToast('✅ ' + (res.message || 'Order refunded successfully'));
        } else {
            showToast('❌ Failed: ' + (res.error || 'Unknown error'), 'error');
        }
    } catch (err) {
        console.error("Fetch error:", err);
        showToast('❌ Request failed: ' + err.message, 'error');
    }
}

// ── Toast Function ──
function showToast(message, type = 'success') {
    Toastify({
        text: message,
        duration: 3000,
        gravity: "top",
        position: "right",
        offset: { y: 70 },
        style: {
            background: type === 'success' ? '#55e087' : '#ff5c5c',
            color: '#000',
            fontWeight: '600',
            borderRadius: '10px',
            boxShadow: '0 6px 20px rgba(0,0,0,0.45)',
        }
    }).showToast();
}

// ── Mark as Paid ──
async function markPaid(id) {
    const btn = document.querySelector(`#row-${id} .paid-btn`);
    if (btn) btn.disabled = true;

    try {
        const r = await fetch(`view_order.php?action=paid&id=${id}`, { cache: "no-store" });
        const res = await r.json();

        if (res.ok) {
            await loadOrders();
            showToast("✅ Order marked as paid");
        } else {
            showToast("❌ Failed: " + (res.error || "Unknown error"), 'error');
        }
    } catch (err) {
        console.error(err);
        showToast("❌ Request failed", 'error');
    } finally {
        if (btn) btn.disabled = false;
    }
}

// ── Mark as Prepare ──
async function markPrepare(id) {
    const btn = document.querySelector(`#row-${id} .prepare-btn`);
    if (btn) btn.disabled = true;

    try {
        const r = await fetch(`view_order.php?action=prepare&id=${id}`, { cache: "no-store" });
        const res = await r.json();

        if (res.ok) {
            await loadOrders();
            showToast("👨‍🍳 Order marked as preparing");
        } else {
            showToast("❌ Failed: " + (res.error || "Unknown error"), 'error');
        }
    } catch (err) {
        console.error(err);
        showToast("❌ Request failed", 'error');
    } finally {
        if (btn) btn.disabled = false;
    }
}

// ── Complete Order ──
async function completeOrder(id) {
    const btn = document.querySelector(`#row-${id} .complete-btn`);
    if (btn) {
        if (btn.dataset.confirming === 'true') {
            // Second click — confirm
            btn.dataset.confirming = 'false';
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Complete';
            btn.style.background = 'rgba(85, 224, 135, 0.15)';
            btn.style.color = 'var(--success)';
            btn.style.border = '1px solid rgba(85, 224, 135, 0.2)';
            
            btn.disabled = true;

            try {
                const r = await fetch(`view_order.php?action=complete&id=${id}`, { cache: "no-store" });
                const res = await r.json();

                if (res.ok) {
                    const _o = (allOrders || []).find(x => Number(x.order_id) === Number(id));
                    const orderNumber = _o ? _o.daily_order_no : id;
                    const customerName = _o ? (_o.customer_name || 'Guest') : 'Customer';
                    callOrder(id, customerName, orderNumber);
                    await loadOrders();
                    showToast("✅ Order completed");
                } else {
                    showToast("❌ Failed: " + (res.error || "Unknown error"), 'error');
                }
            } catch (err) {
                console.error(err);
                showToast("❌ Request failed", 'error');
            } finally {
                if (btn) btn.disabled = false;
            }
            return;
        }
        
        // First click — show confirmation
        btn.dataset.confirming = 'true';
        btn.textContent = '⚠️ Confirm?';
        btn.style.background = 'var(--danger)';
        btn.style.color = '#fff';
        btn.style.border = '1px solid var(--danger)';
        
        // Auto-cancel after 3 seconds
        setTimeout(() => {
            if (btn.dataset.confirming === 'true') {
                btn.dataset.confirming = 'false';
                btn.innerHTML = '<i class="fa-solid fa-check"></i> Complete';
                btn.style.background = 'rgba(85, 224, 135, 0.15)';
                btn.style.color = 'var(--success)';
                btn.style.border = '1px solid rgba(85, 224, 135, 0.2)';
            }
        }, 3000);
    }
}

// ── Delete Order ──
async function removeOrder(id) {
    const btn = document.querySelector(`#row-${id} .delete-btn`);
    if (btn) {
        if (btn.dataset.confirming === 'true') {
            // Second click — confirm
            btn.dataset.confirming = 'false';
            btn.innerHTML = '<i class="fa-solid fa-trash-can"></i>';
            btn.style.cssText = 'background: rgba(255, 92, 92, 0.15); color: var(--danger); border: 1px solid rgba(255, 92, 92, 0.2); font-size: 13px; padding: 6px 14px; border-radius: 6px; width: auto; height: auto;';
            
            btn.disabled = true;
            
            try {
                const r = await fetch(`view_order.php?action=delete&id=${id}`, { cache: "no-store" });
                const res = await r.json();

                if (res.ok) {
                    const row = document.getElementById("row-" + id);
                    if (row) {
                        row.classList.add("fade-out");
                        setTimeout(() => row.remove(), 400);
                    }
                    known.delete(String(id));
                    showToast("🗑️ Order deleted");
                } else {
                    showToast("❌ Failed: " + (res.error || "Unknown error"), 'error');
                }
            } catch (err) {
                console.error(err);
                showToast("❌ Request failed", 'error');
            } finally {
                if (btn) btn.disabled = false;
            }
            return;
        }
        
        // First click — show confirmation
        btn.dataset.confirming = 'true';
        btn.innerHTML = 'Confirm?';
        btn.style.cssText = 'background: var(--danger); color: #fff; border: 1px solid var(--danger); font-size: 13px; padding: 6px 14px; border-radius: 6px; width: auto; height: auto;';
        
        // Auto-cancel after 3 seconds
        setTimeout(() => {
            if (btn.dataset.confirming === 'true') {
                btn.dataset.confirming = 'false';
                btn.innerHTML = '<i class="fa-solid fa-trash-can"></i>';
                btn.style.cssText = 'background: rgba(255, 92, 92, 0.15); color: var(--danger); border: 1px solid rgba(255, 92, 92, 0.2); font-size: 13px; padding: 6px 14px; border-radius: 6px; width: auto; height: auto;';
            }
        }, 3000);
    }
}

// ── Search Orders ──
function searchOrders() {
    searchQuery = document.getElementById('searchInput').value;
    // Dim tab pills while a search is active so user knows tabs are bypassed
    document.querySelectorAll('.status-tab').forEach(t => {
        t.style.opacity = searchQuery ? '0.45' : '';
    });
    applyFilters();
}

// ── Clear Search ──
function clearSearch() {
    searchQuery = '';
    document.getElementById('searchInput').value = '';
    document.querySelectorAll('.status-tab').forEach(t => { t.style.opacity = ''; });
    applyFilters();
}

// ── Initial Load ──
loadOrders().then(() => {
    const params    = new URLSearchParams(window.location.search);
    const tab       = params.get('tab');
    const highlight = params.get('highlight');

    if (tab) filterStatus(tab);
    else if (userRole === 'barista') filterStatus('Preparing');

    if (highlight) {
        const el = document.getElementById('row-' + highlight);
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            el.style.outline = '2px solid var(--accent)';
            el.style.boxShadow = '0 0 0 4px rgba(209,144,75,.2)';
            setTimeout(() => { el.style.outline = ''; el.style.boxShadow = ''; }, 2500);
        }
    }
});
setInterval(loadOrders, 4000);

// ── Real-time Socket ──
let socket;
<?php if ($_socketAvailable): ?>
(function() {
    const s = document.createElement('script');
    s.src = "<?= SOCKET_URL ?>/socket.io/socket.io.js";
    s.onload = function() {
        try {
            socket = io("<?= SOCKET_URL ?>");
            socket.on("connect", () => { console.log("Connected to realtime server"); });
            socket.on("disconnect", () => { console.log("Disconnected from realtime server"); });
            socket.on("new_order", async (data) => {
                if (data && data.order_id) {
                    play("bell");
                    await loadOrders();
                    const card = document.getElementById("row-" + data.order_id);
                    const orderNum = card?.querySelector('.card-order-num')?.textContent || ('#' + data.order_id);
                    showToast("🔔 New order " + orderNum + " received!");
                }
            });
        } catch (e) {
            console.warn("Realtime server unavailable:", e.message);
        }
    };
    document.body.appendChild(s);
})();
<?php endif; ?>

// ── Keyboard shortcut for dismissing call ──
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('callModal').classList.contains('active')) {
        dismissCall();
    }
    if (e.key === 'Escape' && document.getElementById('cancelModal').classList.contains('active')) {
        closeCancelModal();
    }
    if (e.key === 'Escape' && document.getElementById('refundModal').classList.contains('active')) {
        closeRefundModal();
    }
    if (e.key === 'Escape' && document.getElementById('remakeModal').classList.contains('active')) {
        closeRemakeModal();
    }
});

// ── Order age badge helpers ──
const AGE_ACTIVE = new Set(['Pending','Processing','Preparing','PendingPayment']);

function ageMinutes(ts) {
    return Math.floor((Date.now() - new Date(ts)) / 60000);
}

function ageBadgeHtml(ts, status) {
    if (!AGE_ACTIVE.has(status)) return '';
    const m = ageMinutes(ts);
    const cls  = m >= 15 ? 'age-alert' : m >= 10 ? 'age-warn' : '';
    const icon = m >= 15 ? 'fa-circle-exclamation' : 'fa-hourglass-half';
    const html = m >= 10
        ? `<i class="fa-solid ${icon}" style="font-size:9px"></i> ${m}m`
        : (m >= 5 ? `<i class="fa-regular fa-clock" style="font-size:9px"></i> ${m}m` : '');
    return `<span class="age-badge ${cls}" data-order-ts="${ts}" data-order-status="${status}">${html}</span>`;
}

function refreshAgeBadges() {
    document.querySelectorAll('.age-badge[data-order-ts]').forEach(el => {
        const ts     = el.dataset.orderTs;
        const status = el.dataset.orderStatus;
        if (!AGE_ACTIVE.has(status)) { el.innerHTML = ''; el.className = 'age-badge'; return; }
        const m    = ageMinutes(ts);
        const cls  = m >= 15 ? 'age-alert' : m >= 10 ? 'age-warn' : '';
        const icon = m >= 15 ? 'fa-circle-exclamation' : 'fa-hourglass-half';
        const html = m >= 10
            ? `<i class="fa-solid ${icon}" style="font-size:9px"></i> ${m}m`
            : (m >= 5 ? `<i class="fa-regular fa-clock" style="font-size:9px"></i> ${m}m` : '');
        el.className = `age-badge ${cls}`;
        el.innerHTML = html;
    });
}

// ── Time ago + age badge auto-refresh ──
setInterval(() => {
    document.querySelectorAll('[data-timestamp]').forEach(el => {
        el.textContent = timeAgo(el.dataset.timestamp);
    });
    refreshAgeBadges();
    updateBaristaStats();
}, 30000);
</script>
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<?php if ($_flash_welcome): ?>
<script>document.addEventListener('DOMContentLoaded',()=>showToast('Welcome back, <?= htmlspecialchars($_SESSION['username'] ?? 'User', ENT_QUOTES) ?>!','success'));</script>
<?php endif; ?>
</body>
</html>
<?php
exit;
endif;

/* ===============================
   FETCH ORDERS
================================ */
if ($action === "fetch") {
    header('Content-Type: application/json');

    $stmt = $conn->prepare("
        SELECT
            o.order_id,
            o.daily_order_no,
            o.customer_name,
            o.total,
            o.status,
            o.order_date,
            o.started_at,
            o.completed_at,
            o.token_number,
            o.employee_id,
            o.employee_name,
            ro.slug AS employee_role,
            o.prepared_by,
            o.prepared_by_role,
            o.table_number,
            o.order_type,
            o.payment_method,
            o.is_open,
            COUNT(rm.id) AS remake_count,
            oc.cancel_reason,
            oc.cancelled_by,
            orr.refund_reason,
            orr.refunded_by,
            (SELECT GROUP_CONCAT(reason ORDER BY remade_at ASC SEPARATOR '|||') FROM order_remakes rml WHERE rml.order_id = o.order_id) AS remake_reasons,
            oi.item_id,
            oi.product_name,
            oi.sweetness,
            oi.ice,
            oi.milk,
            oi.size_label,
            oi.addons_snapshot,
            oi.quantity,
            oi.made_at,
            oi.made_qty,
            oi.product_id,
            p.category
        FROM orders o
        LEFT JOIN employees emp ON emp.employee_id = o.employee_id
        LEFT JOIN users u ON u.user_id = emp.user_id
        LEFT JOIN roles ro ON ro.id = u.role_id
        LEFT JOIN order_remakes rm ON rm.order_id = o.order_id
        LEFT JOIN order_items oi ON o.order_id = oi.order_id
        LEFT JOIN products p ON p.product_id = oi.product_id
        LEFT JOIN order_cancellations oc ON oc.order_id = o.order_id
        LEFT JOIN order_refunds orr ON orr.order_id = o.order_id
        -- Show today's orders, PLUS any order whose terminal action happened today
        -- (a pay-later tab opened days ago but completed/cancelled/refunded today still
        --  belongs on today's board — otherwise it silently never appears).
        -- PendingPayment is deliberately excluded: awaiting-payment orders are worked in
        -- find_order.php (its own 'pending' tab). This board is for fulfilment. Leaving
        -- them in would put them in the All list with no tab to filter by.
        WHERE o.status <> 'PendingPayment'
          AND ( o.business_date = ?
             OR DATE(o.completed_at)  = ?
             OR DATE(oc.cancelled_at) = ?
             OR DATE(orr.refunded_at) = ? )
        GROUP BY o.order_id, oi.item_id, oi.product_name, oi.sweetness, oi.ice, oi.milk, oi.size_label, oi.addons_snapshot, oi.quantity, oi.made_at, oi.made_qty, oi.product_id, p.category
        ORDER BY
            CASE o.status
                WHEN 'PendingPayment' THEN 1
                WHEN 'Paid' THEN 2
                WHEN 'Preparing' THEN 3
                WHEN 'Completed' THEN 4
                WHEN 'Cancelled' THEN 5
                WHEN 'Refunded' THEN 6
            END,
            o.order_id ASC
    ");

    $stmt->bind_param("ssss", $business_date, $business_date, $business_date, $business_date);
    $stmt->execute();
    $result = $stmt->get_result();

    $map = [];
    $__isBarista = ($_SESSION['role'] ?? '') === 'barista';
    while ($r = $result->fetch_assoc()) {
        $id = $r['order_id'];

        if (!isset($map[$id])) {
            $map[$id] = [
                "order_id" => $id,
                "daily_order_no" => $r['daily_order_no'],
                "customer_name" => $r['customer_name'],
                "total" => $r['total'],
                "status" => $r['status'],
                "order_date" => $r['order_date'],
                "token_number" => $r['token_number'],
                "employee_id" => $r['employee_id'],
                "employee_name" => $r['employee_name'],
                "employee_role" => $r['employee_role'] ?? '',
                "prepared_by" => $r['prepared_by'] ?? '',
                "prepared_by_role" => $r['prepared_by_role'] ?? '',
                "table_number" => $r['table_number'],
                "remake_count"         => (int)$r['remake_count'],
                "is_remade"            => (int)$r['remake_count'] > 0 ? 1 : 0,
                "cancel_reason"        => $r['cancel_reason'] ?? '',
                "cancelled_by"         => $r['cancelled_by'] ?? '',
                "refund_reason"        => $r['refund_reason'] ?? '',
                "refunded_by"          => $r['refunded_by'] ?? '',
                "remake_reasons" => $r['remake_reasons'] ? explode('|||', $r['remake_reasons']) : [],
                // Needed by getStatusBadge to tell a queued 'Paid' order (is_open=1) from a
                // settled pay-later tab (is_open=0) — same status, opposite meanings.
                "is_open" => (int)($r['is_open'] ?? 0),
                // Needed to hide Refund on a pay-later tab that hasn't been settled yet.
                "payment_method" => $r['payment_method'] ?? '',
                // Genuinely re-opened tab = was completed once (completed_at stamped) and is
                // Preparing again. NOT derived from made_qty — with per-drink marking a made
                // row no longer implies a re-open (tapping one drink would false-positive).
                // Re-opened tab = a PAY-LATER order that was completed then re-opened (Preparing +
                // completed_at set). Gate on paylater: cash/bakong orders get completed_at stamped
                // at creation (confirm_order), so without this they'd all false-show "Returning tab".
                "is_returning" => ((($r['status'] ?? '') === 'Preparing') && !empty($r['completed_at']) && (($r['payment_method'] ?? '') === 'paylater')) ? 1 : 0,
                "items" => []
            ];
            if ($__isBarista) {
                $map[$id]["started_at"]   = $r['started_at'];
                $map[$id]["completed_at"] = $r['completed_at'];
                $map[$id]["order_type"]   = $r['order_type'];
            }
        }

        if (!empty($r['product_name'])) {
            $qty      = (int)$r["quantity"];
            $made_qty = (int)$r["made_qty"];
            $is_made  = ($qty > 0 && $made_qty >= $qty) ? 1 : 0;   // row fully made — from the count, not made_at
            $item = [
                "item_id"      => (int)$r["item_id"],
                "product_id"   => (int)$r["product_id"],   // picks this drink's allowed add-ons on remake
                "product_name" => $r["product_name"],
                "size"         => $r["size_label"],
                "sweetness"    => $r["sweetness"],
                "ice"          => $r["ice"],
                "milk"         => $r["milk"],
                "addons"       => array_map(fn($a) => $a['name'], json_decode($r["addons_snapshot"] ?? '[]', true) ?: []),
                "quantity"     => $qty,
                "made_qty"     => $made_qty,
                "is_made"      => $is_made
            ];
            if ($__isBarista) { $item["category"] = $r["category"] ?? ''; }
            $map[$id]["items"][] = $item;
        }
    }

    // Fetch active announcements alongside orders
    $_ann_data = [];
    $_ann_res2 = $conn->query("SELECT id, title, message, type FROM announcements WHERE is_active = 1 AND (expires_at IS NULL OR expires_at >= CURDATE()) AND (starts_at IS NULL OR starts_at <= CURDATE()) ORDER BY created_at DESC");
    if ($_ann_res2) {
        foreach ($_ann_res2->fetch_all(MYSQLI_ASSOC) as $_a) {
            $_ann_data[] = ['id' => (int)$_a['id'], 'title' => $_a['title'], 'message' => $_a['message'], 'type' => $_a['type']];
        }
    }

    echo json_encode(["orders" => array_values($map), "announcements" => $_ann_data]);
    exit;
}

/* ===============================
   MARK AS PAID
================================ */
if ($action === "paid") {
    header('Content-Type: application/json');

    if (($_SESSION['role'] ?? '') === 'barista') {
        echo json_encode(["ok" => 0, "error" => "Unauthorized"]);
        exit;
    }

    $order_id = (int)($_GET['id'] ?? 0);
    if ($order_id <= 0) {
        echo json_encode(["ok" => 0, "error" => "Invalid order id"]);
        exit;
    }

    $conn->begin_transaction();
    try {
        $s1 = $conn->prepare("UPDATE orders SET status = 'Preparing' WHERE order_id = ?");
        $s1->bind_param("i", $order_id);
        $s1->execute();

        // Sync any pending payment records so order_payments stays consistent
        $s2 = $conn->prepare("UPDATE order_payments SET payment_status = 'paid' WHERE order_id = ? AND payment_status != 'paid'");
        $s2->bind_param("i", $order_id);
        $s2->execute();

        $conn->commit();
        echo json_encode(["ok" => 1]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["ok" => 0, "error" => $e->getMessage()]);
    }
    exit;
}

/* ===============================
   MARK AS PREPARE
================================ */
if ($action === "prepare") {
    header('Content-Type: application/json');

    $order_id = (int)($_GET['id'] ?? 0);
    if ($order_id <= 0) {
        echo json_encode(["ok" => 0, "error" => "Invalid order id"]);
        exit;
    }

    $stmt = $conn->prepare("UPDATE orders SET status = 'Preparing' WHERE order_id = ?");
    $stmt->bind_param("i", $order_id);
    
    if ($stmt->execute()) {
        echo json_encode(["ok" => 1]);
    } else {
        echo json_encode(["ok" => 0, "error" => "Failed to update status"]);
    }
    exit;
}

/* ===============================
   COMPLETE ORDER
================================ */
if ($action === "complete") {
    header('Content-Type: application/json');

    $order_id = (int)($_GET['id'] ?? 0);
    if ($order_id <= 0) {
        echo json_encode(["ok" => 0, "error" => "Invalid order id"]);
        exit;
    }

    $conn->begin_transaction();

    try {
        // Lock order
        $stmt_check = $conn->prepare("SELECT status FROM orders WHERE order_id = ? FOR UPDATE");
        $stmt_check->bind_param("i", $order_id);
        $stmt_check->execute();
        $check_res = $stmt_check->get_result();

        if ($check_res->num_rows === 0) {
            throw new Exception("Order not found");
        }

        $order = $check_res->fetch_assoc();

        if ($order['status'] === 'Completed') {
            $conn->commit();
            echo json_encode(["ok" => 1]);
            exit;
        }

        // Stock was already deducted at order creation (confirm_order.php).
        // Deducting again here would double-consume ingredients and cause
        // "Not enough stock" errors on busy days. Only mark the order complete.
        $prepared_by      = $_SESSION['username'] ?? '';
        $prepared_by_role = $_SESSION['role']     ?? '';
        $stmt_update = $conn->prepare("UPDATE orders SET status = 'Completed', prepared_by = ?, prepared_by_role = ?, completed_at = NOW() WHERE order_id = ?");
        $stmt_update->bind_param("ssi", $prepared_by, $prepared_by_role, $order_id);
        $stmt_update->execute();

        // Whole-order Complete = every drink made. Stamp made_at on still-unmade rows and
        // bring made_qty up to quantity so per-unit state agrees with the completed order.
        $stmt_made = $conn->prepare("UPDATE order_items SET made_qty = quantity, made_at = NOW() WHERE order_id = ? AND made_qty < quantity");
        $stmt_made->bind_param("i", $order_id);
        $stmt_made->execute();

        $conn->commit();
        echo json_encode(["ok" => 1]);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["ok" => 0, "error" => $e->getMessage()]);
        exit;
    }
}

/* ===============================
   DELETE ORDER
================================ */
if ($action === "delete") {
    header('Content-Type: application/json');

    $order_id = (int)($_GET['id'] ?? 0);
    if ($order_id <= 0) {
        echo json_encode(["ok" => 0, "error" => "Invalid order id"]);
        exit;
    }

    // ── Role check ──
    if ($_SESSION['role'] !== 'admin') {
        echo json_encode(["ok" => 0, "error" => "Unauthorized"]);
        exit;
    }

    $conn->begin_transaction();

    try {
        $stmt_items = $conn->prepare("DELETE FROM order_items WHERE order_id = ?");
        $stmt_items->bind_param("i", $order_id);
        $stmt_items->execute();

        $stmt_order = $conn->prepare("DELETE FROM orders WHERE order_id = ?");
        $stmt_order->bind_param("i", $order_id);
        $stmt_order->execute();

        $conn->commit();
        echo json_encode(["ok" => 1]);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["ok" => 0, "error" => $e->getMessage()]);
        exit;
    }
}
?>