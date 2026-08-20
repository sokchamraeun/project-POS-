<?php
require 'auth.php';
require 'config.php';
if (!can('view_orders')) { header("Location: dashboard.php?denied=1"); exit; }

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
$_all_roles  = [
    'admin'   => ['slug' => 'admin', 'name' => 'Admin', 'color' => '#d1904b', 'icon' => 'fa-user-shield'],
    'manager' => ['slug' => 'manager', 'name' => 'Manager', 'color' => '#3498db', 'icon' => 'fa-user-tie'],
    'staff'   => ['slug' => 'staff', 'name' => 'Cashier', 'color' => '#55e087', 'icon' => 'fa-user'],
    'barista' => ['slug' => 'barista', 'name' => 'Barista', 'color' => '#d1904b', 'icon' => 'fa-mug-hot'],
];
$_role_label = $_all_roles[$_vo_role]['name']  ?? ucfirst(str_replace('_', ' ', $_vo_role));
$_role_color = $_all_roles[$_vo_role]['color'] ?? '#888888';
$_date_str    = date('l, d F Y');

$_remake_milk = ['Fresh Milk', 'Almond Milk', 'Soy Milk', 'Oat Milk'];

// Add-ons (removed)
$_remake_addons = [];

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

// Announcements (removed)
$_announcements = [];

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
    <script src="https://cdn.tailwindcss.com"></script>
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

    /* ── LIGHT MODE PALETTE ── */
    html[data-theme="light"], [data-theme="light"] {
        --bg: #ECEEF2;
        --bg-card: #FFFFFF;
        --bg-card-hover: #F5F7FA;
        --border: #E2E5EA;
        --border-hover: #CDD0D8;
        --text: #111827;
        --text-muted: #5A6373;
        --text-light: #0B0F19;
    }

    * { 
        box-sizing: border-box; 
        margin: 0; 
        padding: 0; 
    }

    /* ── PREMIUM DARK BACKGROUND ── */
    body {
        background-color: var(--bg, #09090b);
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
        padding: 0;
        height: 100vh;
        width: 100vw;
        position: relative;
        overflow: hidden;
    }

    html[data-theme="light"] body,
    [data-theme="light"] body,
    [data-theme="light"] .app-layout,
    [data-theme="light"] .app-main,
    [data-theme="light"] .vo-page-wrapper {
        background-color: #ECEEF2 !important;
        color: #111827 !important;
        background-image: none !important;
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

    /* ── Header Stat Boxes ── */
    .vo-stats-grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 16px;
        width: 100%;
        max-width: 100%;
        margin: 0 0 24px 0;
        padding: 0;
    }

    @media (max-width: 1200px) {
        .vo-stats-grid {
            grid-template-columns: repeat(3, 1fr);
        }
    }

    @media (max-width: 768px) {
        .vo-stats-grid {
            grid-template-columns: repeat(2, 1fr) !important;
            gap: 10px !important;
            margin-bottom: 16px !important;
        }
    }

    @media (max-width: 480px) {
        .vo-stats-grid {
            grid-template-columns: repeat(2, 1fr) !important;
            gap: 8px !important;
        }
        .search-bar {
            gap: 8px !important;
            margin-bottom: 12px !important;
        }
        .search-bar #searchInput {
            min-width: 100% !important;
            width: 100% !important;
        }
        .search-bar #staffFilterSelect {
            flex: 1 !important;
            min-width: 0 !important;
        }
        .search-bar .btn {
            flex: 1 !important;
            justify-content: center !important;
            padding: 10px 14px !important;
        }
        .search-bar .btn-clear {
            display: none !important;
        }
    }

    .vo-stat-box {
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 16px;
        padding: 18px 20px;
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 16px;
        min-height: 92px;
        cursor: pointer;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.05);
        position: relative;
        overflow: hidden;
    }

    .vo-stat-box:hover {
        transform: translateY(-3px);
        border-color: rgba(255, 255, 255, 0.18);
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.4);
    }

    .vo-stat-box.active {
        border-color: var(--accent);
        background: rgba(209, 144, 75, 0.12);
        box-shadow: 0 0 20px rgba(209, 144, 75, 0.15), inset 0 1px 0 rgba(209, 144, 75, 0.2);
    }

    .vo-stat-content {
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .vo-stat-title {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--text-muted);
    }

    .vo-stat-value {
        font-size: 26px;
        font-weight: 800;
        color: var(--text);
        line-height: 1.2;
    }

    .vo-stat-sub {
        font-size: 11.5px;
        color: var(--text-muted);
        font-weight: 400;
        opacity: 0.85;
    }

    .vo-stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 13px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 21px;
        flex-shrink: 0;
        transition: transform 0.25s ease;
    }

    .vo-stat-box:hover .vo-stat-icon {
        transform: scale(1.08);
    }

    .vo-stat-icon.all-orders {
        background: rgba(139, 92, 246, 0.22);
        color: #a78bfa;
        border: 1px solid rgba(139, 92, 246, 0.4);
    }

    .vo-stat-icon.new-orders {
        background: rgba(59, 130, 246, 0.22);
        color: #60a5fa;
        border: 1px solid rgba(59, 130, 246, 0.4);
    }

    .vo-stat-icon.pending-orders {
        background: rgba(245, 158, 11, 0.22);
        color: #fbbf24;
        border: 1px solid rgba(245, 158, 11, 0.4);
    }

    .vo-stat-icon.complete-orders {
        background: rgba(16, 185, 129, 0.22);
        color: #34d399;
        border: 1px solid rgba(16, 185, 129, 0.4);
    }

    .vo-stat-icon.overdue-orders {
        background: rgba(239, 68, 68, 0.22);
        color: #f87171;
        border: 1px solid rgba(239, 68, 68, 0.4);
    }

    @keyframes pulse-overdue {
        0% {
            box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7), inset 0 1px 0 rgba(255, 255, 255, 0.1);
            border-color: rgba(239, 68, 68, 0.9);
        }
        70% {
            box-shadow: 0 0 0 14px rgba(239, 68, 68, 0), inset 0 1px 0 rgba(255, 255, 255, 0.1);
            border-color: rgba(239, 68, 68, 0.4);
        }
        100% {
            box-shadow: 0 0 0 0 rgba(239, 68, 68, 0), inset 0 1px 0 rgba(255, 255, 255, 0.1);
            border-color: rgba(239, 68, 68, 0.9);
        }
    }

    .vo-stat-box.has-overdue-alert {
        background: rgba(239, 68, 68, 0.18) !important;
        border-color: rgba(239, 68, 68, 0.9) !important;
    }

    .vo-stat-icon.total-price {
        background: rgba(245, 158, 11, 0.22);
        color: #fbbf24;
        border: 1px solid rgba(245, 158, 11, 0.4);
    }

    .vo-stat-icon.total-orders {
        background: rgba(209, 144, 75, 0.15);
        color: #d1904b;
        border: 1px solid rgba(209, 144, 75, 0.25);
    }

    /* ── View Toggle Switcher ── */
    .view-toggle-group {
        display: inline-flex;
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 12px;
        padding: 4px;
        gap: 4px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    }

    .view-btn {
        padding: 8px 16px;
        border-radius: 9px;
        border: none;
        background: transparent;
        color: var(--text-muted);
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-family: 'Poppins', sans-serif;
    }

    .view-btn:hover {
        color: var(--text);
        background: rgba(255,255,255,0.05);
    }

    .view-btn.active {
        background: var(--accent);
        color: #000;
        box-shadow: 0 2px 8px rgba(209, 144, 75, 0.3);
    }

    [data-theme="light"] .view-toggle-group {
        background: #FFFFFF;
        border-color: #E2E5EA;
    }
    [data-theme="light"] .view-btn {
        color: #5A6373;
    }
    [data-theme="light"] .view-btn.active {
        background: var(--accent);
        color: #000;
    }

    /* ── Teal Table View Layout ── */
    .vo-table-wrapper {
        width: 100% !important;
        min-width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box;
        overflow-y: auto !important;
        overflow-x: auto !important;
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 16px;
        box-shadow: 0 4px 24px rgba(0, 0, 0, 0.35);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
    }

    .vo-table {
        width: 100% !important;
        min-width: 100% !important;
        table-layout: fixed !important;
        border-collapse: separate !important;
        border-spacing: 0 !important;
        text-align: left;
        font-size: 13.5px;
    }

    .vo-table thead tr {
        background: #16161a;
        color: var(--accent, #d1904b);
    }

    .vo-table th {
        position: sticky !important;
        top: 0 !important;
        z-index: 10 !important;
        padding: 14px 16px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        white-space: nowrap;
        color: var(--accent, #d1904b);
        background: #16161a !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.12);
    }

    .vo-table th:first-child {
        border-top-left-radius: 15px;
    }

    .vo-table th:last-child {
        border-top-right-radius: 15px;
    }

    .vo-table tbody tr {
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        transition: background 0.2s ease;
    }

    .vo-table tbody tr:hover {
        background: rgba(255, 255, 255, 0.04);
    }

    .vo-table td {
        padding: 14px 16px;
        color: var(--text);
        vertical-align: middle;
    }

    .vo-table .vo-col-order {
        font-weight: 800;
        font-size: 15px;
        color: var(--accent);
    }

    .vo-table .vo-col-stand {
        font-weight: 600;
        color: var(--text-muted);
    }

    .vo-table .vo-col-items {
        max-width: 320px;
    }

    .vo-table .vo-col-total {
        font-weight: 800;
        font-size: 15px;
        color: var(--success, #55e087);
    }

    .vo-table .vo-col-actions {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: nowrap;
        white-space: nowrap;
    }

    /* View Detail Button & Badges */
    .btn-view-detail {
        padding: 7px 16px;
        border-radius: 9px;
        border: 1px solid rgba(209, 144, 75, 0.35);
        background: rgba(209, 144, 75, 0.12);
        color: var(--accent, #d1904b);
        font-size: 12.5px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-family: 'Poppins', sans-serif;
    }

    .btn-view-detail:hover {
        background: rgba(209, 144, 75, 0.25);
        border-color: var(--accent, #d1904b);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(209, 144, 75, 0.2);
    }

    .btn-reprint {
        padding: 7px 16px;
        border-radius: 9px;
        border: 1px solid rgba(59, 130, 246, 0.35);
        background: rgba(59, 130, 246, 0.12);
        color: #60a5fa;
        font-size: 12.5px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-family: 'Poppins', sans-serif;
    }

    .btn-reprint:hover {
        background: rgba(59, 130, 246, 0.25);
        border-color: #60a5fa;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);
    }

    /* Date Filter Pills */
    .vo-date-pill {
        padding: 8px 18px;
        border-radius: 9px;
        border: 1px solid rgba(255, 255, 255, 0.1);
        background: rgba(255, 255, 255, 0.05);
        color: #a1a1aa;
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s ease;
        font-family: 'Poppins', sans-serif;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .vo-date-pill:hover {
        background: rgba(209, 144, 75, 0.15);
        color: #d1904b;
        border-color: rgba(209, 144, 75, 0.35);
        transform: translateY(-1px);
    }

    .vo-date-pill.active {
        background: #d1904b;
        color: #000000;
        border-color: #d1904b;
        font-weight: 700;
        box-shadow: 0 4px 14px rgba(209, 144, 75, 0.3);
    }

    .vo-mobile-select {
        width: 100% !important;
        padding: 10px 16px !important;
        border-radius: 10px !important;
        border: 1px solid rgba(209, 144, 75, 0.4) !important;
        background-color: #161412 !important;
        color: #f3cb98 !important;
        font-weight: 600 !important;
        font-size: 13px !important;
        outline: none !important;
        text-align: center !important;
        text-align-last: center !important;
        appearance: none !important;
        -webkit-appearance: none !important;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23d1904b' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") !important;
        background-repeat: no-repeat !important;
        background-position: right 14px center !important;
    }

    [data-theme="light"] .vo-mobile-select {
        background-color: #fff9f2 !important;
        color: #92581d !important;
        border-color: rgba(209, 144, 75, 0.5) !important;
    }

    /* ── Search & Filter 2-Column Responsive Layout ── */
    .vo-filter-controls {
        display: flex;
        gap: 12px;
        align-items: center;
        margin-bottom: 16px;
        width: 100%;
    }
    .vo-filter-search-box {
        flex: 1;
        min-width: 0;
    }
    .vo-filter-search-box input {
        width: 100%;
        padding: 10px 16px;
        border-radius: 10px;
        border: 1px solid rgba(255,255,255,0.09);
        background: rgba(255,255,255,0.05);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        color: var(--text);
        font-family: 'Poppins', sans-serif;
        font-size: 13.5px;
        outline: none;
        box-sizing: border-box;
        box-shadow: 0 2px 8px rgba(0,0,0,0.3), inset 0 1px 0 rgba(255,255,255,0.06);
    }
    .vo-filter-selects-box {
        display: flex;
        gap: 10px;
        align-items: center;
        flex-shrink: 0;
    }
    .vo-filter-selects-box select {
        padding: 10px 14px;
        border-radius: 10px;
        border: 1px solid rgba(255,255,255,0.12);
        background: #18181c;
        color: var(--text);
        font-family: 'Poppins', sans-serif;
        font-size: 13px;
        font-weight: 500;
        outline: none;
        cursor: pointer;
    }

    @media (max-width: 640px) {
        .vo-stat-box {
            padding: 10px 12px !important;
            gap: 10px !important;
            min-height: 62px !important;
            border-radius: 12px !important;
        }
        .vo-stat-icon {
            width: 34px !important;
            height: 34px !important;
            min-width: 34px !important;
            font-size: 15px !important;
            border-radius: 9px !important;
        }
        .vo-stat-title {
            font-size: 9.5px !important;
            letter-spacing: 0.04em !important;
        }
        .vo-stat-value {
            font-size: 18px !important;
        }

        .vo-date-pills-desktop { display: none !important; }
        .vo-date-select-mobile { display: block !important; flex: 1 !important; width: 100% !important; }
        .vo-filter-controls {
            display: grid !important;
            grid-template-columns: 1fr 1fr !important;
            gap: 10px !important;
            margin-bottom: 14px !important;
            width: 100% !important;
        }
        .vo-filter-search-box {
            width: 100% !important;
        }
        .vo-filter-search-box input {
            width: 100% !important;
            font-size: 12px !important;
            padding: 10px 12px !important;
            height: 42px !important;
        }
        .vo-filter-selects-box {
            width: 100% !important;
            display: flex !important;
            gap: 6px !important;
        }
        .vo-filter-selects-box select,
        .vo-mobile-select {
            width: 100% !important;
            height: 42px !important;
            font-size: 12px !important;
            padding: 9px 8px !important;
            border-radius: 10px !important;
        }
        #staffFilterSelect {
            display: none !important; /* On 2-col mobile, Column 1 is Search, Column 2 is Date Filter */
        }
    }

    .complete-btn {
        padding: 7px 16px;
        min-width: 110px;
        border-radius: 9px;
        border: 1px solid rgba(85, 224, 135, 0.35);
        background: rgba(85, 224, 135, 0.14);
        color: #55e087;
        font-size: 12.5px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        font-family: 'Poppins', sans-serif;
    }

    .complete-btn:hover {
        background: rgba(85, 224, 135, 0.28);
        border-color: #55e087;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(85, 224, 135, 0.2);
    }

    .take-btn {
        padding: 7px 16px;
        min-width: 110px;
        border-radius: 9px;
        border: 1px solid rgba(241, 196, 15, 0.4);
        background: rgba(241, 196, 15, 0.14);
        color: #f1c40f;
        font-size: 12.5px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        font-family: 'Poppins', sans-serif;
    }

    .take-btn:hover {
        background: rgba(241, 196, 15, 0.28);
        border-color: #f1c40f;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(241, 196, 15, 0.2);
    }

    .vo-items-count-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 12px;
        border-radius: 8px;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
        color: var(--text);
        font-weight: 600;
        font-size: 13px;
        white-space: nowrap !important;
    }

    .status-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 14px;
        border-radius: 50px;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.02em;
    }

    .status-pill.status-new {
        background: rgba(245, 158, 11, 0.15);
        color: #f59e0b;
        border: 1px solid rgba(245, 158, 11, 0.3);
    }

    .status-pill.status-making {
        background: rgba(230, 126, 34, 0.15);
        color: #e67e22;
        border: 1px solid rgba(230, 126, 34, 0.3);
    }

    .status-pill.status-complete {
        background: rgba(16, 185, 129, 0.15);
        color: #10b981;
        border: 1px solid rgba(16, 185, 129, 0.3);
    }

    .status-pill.status-pending {
        background: rgba(59, 130, 246, 0.15);
        color: #3b82f6;
        border: 1px solid rgba(59, 130, 246, 0.3);
    }

    .status-pill.status-cancelled {
        background: rgba(239, 68, 68, 0.15);
        color: #ef4444;
        border: 1px solid rgba(239, 68, 68, 0.3);
    }

    .status-pill.status-refunded {
        background: rgba(168, 85, 247, 0.15);
        color: #a855f7;
        border: 1px solid rgba(168, 85, 247, 0.3);
    }

    /* ── Order Detail Modal ── */
    .detail-modal {
        position: fixed;
        inset: 0;
        z-index: 9998;
        background: rgba(0, 0, 0, 0.65);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 24px;
        opacity: 0;
        transition: opacity 0.25s ease;
    }

    .detail-modal.active {
        display: flex !important;
        opacity: 1;
    }

    .detail-modal-content {
        background: #18181b !important;
        border: 1px solid rgba(255, 255, 255, 0.12);
        border-radius: 24px;
        width: 100%;
        max-width: 680px;
        max-height: 90vh;
        overflow-y: auto;
        padding: 32px 36px;
        color: #ffffff !important;
        box-shadow: 0 25px 70px rgba(0,0,0,0.75);
        animation: modalPop 0.25s ease;
    }

    .receipt-btn:hover {
        background: rgba(52, 152, 219, 0.25);
        border-color: #3498db;
        transform: translateY(-1px);
    }

    .print-btn {
        padding: 6px 12px;
        border-radius: 8px;
        border: 1px solid rgba(155, 89, 182, 0.3);
        background: rgba(155, 89, 182, 0.12);
        color: #9b59b6;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .print-btn:hover {
        background: rgba(155, 89, 182, 0.25);
        border-color: #9b59b6;
        transform: translateY(-1px);
    }

    /* Light Theme Comprehensive Overrides */
    [data-theme="light"] .vo-table-wrapper {
        background: #FFFFFF !important;
        border-color: #E2E5EA !important;
        box-shadow: 0 1px 3px rgba(0,0,0,.06), 0 4px 14px rgba(0,0,0,.05) !important;
    }
    [data-theme="light"] .vo-table thead tr,
    [data-theme="light"] .vo-table th {
        background: #F1F5F9 !important;
        color: #1E293B !important;
        border-bottom: 1px solid #E2E8F0 !important;
    }
    [data-theme="light"] .vo-table tbody tr {
        border-bottom-color: #F1F5F9 !important;
    }
    [data-theme="light"] .vo-table tbody tr:hover {
        background: #F8FAFC !important;
    }
    [data-theme="light"] .vo-table td {
        color: #0F172A !important;
    }
    [data-theme="light"] .vo-items-count-badge {
        background: #F1F5F9 !important;
        border-color: #CBD5E1 !important;
        color: #1E293B !important;
    }
    [data-theme="light"] .vo-items-count-badge i {
        color: #d1904b !important;
    }
    [data-theme="light"] .vo-col-stand,
    [data-theme="light"] .vo-col-placed {
        color: #475569 !important;
    }

    /* Stat Cards in Light Mode */
    [data-theme="light"] .vo-stat-box {
        background: #FFFFFF !important;
        border-color: #E2E5EA !important;
        box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 4px 14px rgba(0,0,0,0.05) !important;
    }
    [data-theme="light"] .vo-stat-title {
        color: #64748B !important;
    }
    [data-theme="light"] .vo-stat-value {
        color: #0F172A !important;
    }
    [data-theme="light"] .vo-stat-sub {
        color: #64748B !important;
    }

    /* Search Bar, Staff Select & Clear Button in Light Mode */
    [data-theme="light"] #searchInput {
        background: #FFFFFF !important;
        border-color: #E2E5EA !important;
        color: #0F172A !important;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important;
    }
    [data-theme="light"] #searchInput::placeholder {
        color: #94A3B8 !important;
    }
    [data-theme="light"] #staffFilterSelect {
        background: #FFFFFF !important;
        border-color: #E2E5EA !important;
        color: #0F172A !important;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important;
    }
    [data-theme="light"] #staffFilterSelect option {
        background: #FFFFFF !important;
        color: #0F172A !important;
    }
    [data-theme="light"] .btn-clear {
        background: #FFFFFF !important;
        border-color: #E2E5EA !important;
        color: #475569 !important;
    }
    [data-theme="light"] .btn-clear:hover {
        background: #F1F5F9 !important;
        color: #0F172A !important;
    }

    /* Date Filter Range Pills in Light Mode */
    [data-theme="light"] .vo-date-pill {
        background: #FFFFFF !important;
        border-color: #E2E5EA !important;
        color: #475569 !important;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04) !important;
    }
    [data-theme="light"] .vo-date-pill:hover {
        background: rgba(209, 144, 75, 0.12) !important;
        color: #d1904b !important;
        border-color: rgba(209, 144, 75, 0.4) !important;
    }
    [data-theme="light"] .vo-date-pill.active {
        background: #d1904b !important;
        color: #FFFFFF !important;
        border-color: #d1904b !important;
        box-shadow: 0 2px 8px rgba(209, 144, 75, 0.3) !important;
    }

    /* Action Buttons in Light Mode */
    [data-theme="light"] .complete-btn {
        background: rgba(209, 144, 75, 0.12) !important;
        color: #b37330 !important;
        border-color: rgba(209, 144, 75, 0.35) !important;
    }
    [data-theme="light"] .complete-btn:hover {
        background: rgba(209, 144, 75, 0.22) !important;
    }
    [data-theme="light"] .print-btn {
        background: rgba(59, 130, 246, 0.12) !important;
        color: #2563eb !important;
        border-color: rgba(59, 130, 246, 0.35) !important;
    }
    [data-theme="light"] .print-btn:hover {
        background: rgba(59, 130, 246, 0.22) !important;
    }

    /* ── Receipt Modal Styling ── */
    .receipt-modal {
        position: fixed;
        inset: 0;
        z-index: 9999;
        background: rgba(0, 0, 0, 0.8);
        backdrop-filter: blur(10px);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
        opacity: 0;
        transition: opacity 0.25s ease;
    }

    .receipt-modal.active {
        display: flex !important;
        opacity: 1;
    }

    .receipt-modal-content {
        background: #111111;
        border: 1px solid #2a2a2a;
        border-radius: 20px;
        width: 100%;
        max-width: 440px;
        padding: 24px 28px;
        color: #f5f5f5;
        box-shadow: 0 20px 50px rgba(0,0,0,0.8);
        animation: modalPop 0.25s ease;
    }

    .receipt-header {
        text-align: center;
        padding-bottom: 16px;
        border-bottom: 1px dashed rgba(255,255,255,0.15);
        margin-bottom: 16px;
    }

    .receipt-brand {
        font-size: 20px;
        font-weight: 800;
        color: var(--accent);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .receipt-sub {
        font-size: 12px;
        color: #888888;
        margin-top: 4px;
    }

    .receipt-info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px 16px;
        font-size: 12.5px;
        margin-bottom: 16px;
        padding-bottom: 12px;
        border-bottom: 1px dashed rgba(255,255,255,0.15);
    }

    .receipt-items-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12.5px;
        margin-bottom: 16px;
    }

    .receipt-items-table th {
        text-align: left;
        padding-bottom: 8px;
        color: #888;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        font-size: 11px;
        text-transform: uppercase;
    }

    .receipt-items-table td {
        padding: 8px 0;
        border-bottom: 1px solid rgba(255,255,255,0.04);
    }

    .receipt-totals {
        border-top: 1px dashed rgba(255,255,255,0.2);
        padding-top: 12px;
        font-size: 13px;
    }

    .receipt-total-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 6px;
    }

    .receipt-total-row.grand {
        font-size: 18px;
        font-weight: 800;
        color: var(--accent);
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px solid rgba(255,255,255,0.15);
    }

    .receipt-footer-msg {
        text-align: center;
        font-size: 12px;
        color: #888;
        margin-top: 20px;
        padding-top: 12px;
        border-top: 1px dashed rgba(255,255,255,0.15);
    }

    @media print {
        body * {
            visibility: hidden;
        }
        #printableReceipt, #printableReceipt * {
            visibility: visible;
        }
        #printableReceipt {
            position: absolute;
            left: 0;
            top: 0;
            width: 80mm;
            padding: 10px;
            background: #fff !important;
            color: #000 !important;
            font-family: monospace, sans-serif !important;
            font-size: 12px !important;
        }
        #printableReceipt .receipt-brand { color: #000 !important; }
        #printableReceipt .receipt-total-row.grand { color: #000 !important; }
        #printableReceipt border, #printableReceipt td, #printableReceipt th { border-color: #000 !important; }
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

    /* ── Page Wrapper & Layout ── */
    .vo-page-wrapper {
        padding: 0;
        width: 100%;
        height: 100%;
        display: flex;
        flex-direction: column;
        min-height: 0;
        box-sizing: border-box;
    }

    .app-main, .main-content-container, main {
        padding: 20px 28px !important;
        height: 100vh !important;
        max-height: 100vh !important;
        overflow: hidden !important;
        box-sizing: border-box;
        display: flex;
        flex-direction: column;
    }

    .header-bar, .vo-stats-grid, .search-bar, .vo-date-filter-bar {
        flex: 0 0 auto;
    }

    @media (max-width: 768px) {
        .vo-page-wrapper {
            padding: 0;
        }
        .app-main, .main-content-container, main {
            padding: 14px !important;
        }
    }

    /* ── Container Overrides for 100% Full Width & Table Scroll ── */
    .container, .vo-table-container {
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        position: relative;
        z-index: 1;
        flex: 1 1 0% !important;
        min-height: 0 !important;
        height: 100% !important;
        display: flex !important;
        flex-direction: column !important;
        overflow: hidden !important;
    }

    .orders-table-wrapper-container {
        display: flex !important;
        flex-direction: column !important;
        flex: 1 1 0% !important;
        min-height: 0 !important;
        height: 100% !important;
        width: 100% !important;
        overflow: hidden !important;
    }

    .vo-table-wrapper {
        flex: 1 1 0% !important;
        min-height: 0 !important;
        height: 100% !important;
        max-height: 100% !important;
        overflow-y: auto !important;
        overflow-x: auto !important;
    }
    /* ── Orders Grid ── */
    .orders-grid:not(.orders-table-wrapper-container) {
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
    .order-card[data-status="New"]            { --sc: #f1c40f; }
    .order-card[data-status="Making"]         { --sc: #e67e22; }
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
    .card-actions .take-btn   { background: rgba(241,196,15,.12); color: #f1c40f; border-color: rgba(241,196,15,.2); }
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
    .order-card.is-remade[data-status="New"] {
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

    .status.New {
        background: rgba(241, 196, 15, 0.2);
        color: #f1c40f;
        border: 1px solid rgba(241, 196, 15, 0.2);
    }

    .status.Making {
        background: rgba(230, 126, 34, 0.2);
        color: #e67e22;
        border: 1px solid rgba(230, 126, 34, 0.2);
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

        .status {
            font-size: 11px;
            padding: 4px 12px;
        }
    }

    @media (max-width: 640px) {
        .vo-stat-sub {
            display: none !important;
        }

        /* ── Mobile View: Hide secondary columns (Date/Time & Staff) ── */
        .vo-table th:nth-child(4),
        .vo-table td:nth-child(4),
        .vo-table th:nth-child(5),
        .vo-table td:nth-child(5) {
            display: none !important;
        }

        .vo-table .vo-col-items .vo-items-count-badge {
            white-space: nowrap !important;
            font-size: 11.5px !important;
            padding: 4px 8px !important;
        }

        /* ── Action Buttons: Show icons only on mobile ── */
        .card-actions button span,
        .vo-col-actions button span,
        .btn-view-detail span,
        .btn-reprint span,
        .paid-btn span,
        .take-btn span,
        .complete-btn span,
        .print-btn span {
            display: none !important;
        }

        .vo-table .vo-col-actions {
            display: flex !important;
            flex-direction: row !important;
            flex-wrap: nowrap !important;
            white-space: nowrap !important;
            align-items: center !important;
            gap: 6px !important;
        }

        .card-actions button,
        .vo-col-actions button,
        .btn-view-detail,
        .btn-reprint,
        .paid-btn,
        .take-btn,
        .complete-btn,
        .print-btn {
            padding: 8px 10px !important;
            min-width: 34px !important;
            height: 34px !important;
            border-radius: 10px !important;
            justify-content: center !important;
            display: inline-flex !important;
            align-items: center !important;
        }
        .card-actions button i,
        .vo-col-actions button i,
        .btn-view-detail i,
        .btn-reprint i,
        .paid-btn i,
        .take-btn i,
        .complete-btn i,
        .print-btn i {
            margin: 0 !important;
            font-size: 14px !important;
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
html[data-theme="light"], [data-theme="light"] {
    --bg:#ECEEF2;
    --bg-card:#FFFFFF;
    --bg-card-hover:#F5F7FA;
    --border:#E2E5EA;
    --border-hover:#CDD0D8;
    --text:#111827;
    --text-muted:#5A6373;
    --text-light:#0B0F19;
}
html[data-theme="light"] body,
[data-theme="light"] body,
[data-theme="light"] .app-layout,
[data-theme="light"] .app-main,
[data-theme="light"] .vo-page-wrapper,
[data-theme="light"] .main-content-container {
    background-color:#ECEEF2 !important;
    color:#111827 !important;
    background-image:
        radial-gradient(ellipse 90% 60% at 15% -10%, rgba(120,120,160,0.06) 0%, transparent 55%),
        radial-gradient(ellipse 70% 60% at 85% 110%, rgba(100,100,140,0.05) 0%, transparent 55%),
        linear-gradient(rgba(0,0,0,0.028) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0,0,0,0.028) 1px, transparent 1px) !important;
    background-size:auto, auto, 72px 72px, 72px 72px !important;
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
/* ══ MODAL OVERLAYS & DIALOG STYLES ══ */
.call-modal,
.cancel-modal,
.refund-modal {
    position: fixed;
    inset: 0;
    z-index: 9999;
    background: rgba(0, 0, 0, 0.75);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.25s ease;
}

.call-modal.active,
.cancel-modal.active,
.refund-modal.active {
    display: flex !important;
    opacity: 1;
    pointer-events: auto;
}

.call-modal-content,
.cancel-modal-content,
.refund-modal-content {
    background: #111111;
    border: 1px solid #2a2a2a;
    border-radius: 16px;
    padding: 28px 32px;
    width: 100%;
    max-width: 480px;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.8), 0 0 0 1px rgba(255, 255, 255, 0.05);
    color: #f5f5f5;
    animation: modalPop 0.25s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
}

@keyframes modalPop {
    from { opacity: 0; transform: scale(0.92) translateY(10px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}

.call-modal-content h2,
.cancel-modal-content h2,
.refund-modal-content h2 {
    margin: 0 0 8px 0;
    font-size: 20px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
}

.order-number {
    font-family: var(--font-mono, monospace);
    font-size: 22px;
    font-weight: 800;
    color: var(--amber, #d1904b);
    margin-bottom: 12px;
}

/* Dark Theme Form Inputs & Textareas */
.call-modal-content input,
.call-modal-content textarea,
.cancel-modal-content input,
.cancel-modal-content textarea,
.refund-modal-content input,
.refund-modal-content textarea,
.form-group input,
.form-group textarea,
.form-group select {
    width: 100%;
    background: #181818 !important;
    border: 1px solid #2a2a2a !important;
    border-radius: 10px !important;
    padding: 12px 14px !important;
    color: #f5f5f5 !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 14px !important;
    outline: none !important;
    transition: border-color 0.2s, box-shadow 0.2s !important;
    box-sizing: border-box !important;
}

.call-modal-content input:focus,
.call-modal-content textarea:focus,
.cancel-modal-content input:focus,
.cancel-modal-content textarea:focus,
.refund-modal-content input:focus,
.refund-modal-content textarea:focus,
.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus {
    border-color: var(--amber, #d1904b) !important;
    box-shadow: 0 0 0 3px rgba(209, 144, 75, 0.2) !important;
}

.call-modal-content textarea,
.cancel-modal-content textarea,
.refund-modal-content textarea,
.form-group textarea {
    min-height: 90px;
    resize: vertical;
}

.form-group {
    margin-bottom: 16px;
    text-align: left;
}

.form-group label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #888888;
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

/* Modal Action Buttons */
.btn-group {
    display: flex;
    gap: 12px;
    margin-top: 22px;
    justify-content: flex-end;
}

.btn-dismiss,
.btn-cancel-yes,
.btn-cancel-no,
.btn-refund-yes,
.btn-refund-no {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 11px 20px;
    border-radius: 10px;
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all 0.2s ease;
}

.btn-dismiss {
    background: var(--amber, #d1904b);
    color: #000;
    width: 100%;
    margin-top: 16px;
}

.btn-cancel-yes,
.btn-refund-yes {
    background: #ff6b6b;
    color: #ffffff;
}

.btn-cancel-yes:hover,
.btn-refund-yes:hover {
    background: #ff5252;
    transform: translateY(-1px);
}

.btn-cancel-no,
.btn-refund-no {
    background: #242424;
    border: 1px solid #333333;
    color: #cccccc;
}

.btn-cancel-no:hover,
.btn-refund-no:hover {
    background: #2e2e2e;
    color: #ffffff;
}
    </style>
</head>
<body>
<div class="flex h-screen w-screen overflow-hidden app-layout">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<div class="app-main flex-1 h-full overflow-y-auto">

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
        <a href="products.php"><i class="fa-solid fa-cube"></i> Products</a>
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
<div class="vo-page-wrapper">
<?php $page_title = __('nav_orders', 'Orders'); require __DIR__ . '/header_bar.php'; ?>

<!-- Header Stat Boxes -->
<div class="vo-stats-grid">
    <div class="vo-stat-box" id="statCard-all" onclick="filterStatus('all')">
        <div class="vo-stat-icon all-orders">
            <i class="fa-solid fa-layer-group"></i>
        </div>
        <div class="vo-stat-content">
            <span class="vo-stat-title"><?= __('all_orders', 'All Order') ?></span>
            <span class="vo-stat-value" id="stat-count-all-orders">0</span>
            <span class="vo-stat-sub"><?= __('show_all_orders', 'Show all orders') ?></span>
        </div>
    </div>

    <div class="vo-stat-box" id="statCard-total-price" onclick="filterStatus('all')">
        <div class="vo-stat-icon total-price">
            <i class="fa-solid fa-dollar-sign"></i>
        </div>
        <div class="vo-stat-content">
            <span class="vo-stat-title"><?= __('total_price', 'Total Price') ?></span>
            <span class="vo-stat-value" id="stat-count-total-price">$0.00</span>
            <span class="vo-stat-sub"><?= __('total_sales_today', 'Total sales today') ?></span>
        </div>
    </div>
</div>

<?php
$_init_range = is_string($_GET['range'] ?? null) ? trim($_GET['range']) : 'all';
if (!in_array($_init_range, ['all', 'today', 'week', 'month', 'year'], true)) { $_init_range = 'all'; }
?>
<!-- Search & Filter Controls (2 Column Grid on Mobile) -->
<div class="search-bar vo-filter-controls">
    <div class="vo-filter-search-box">
        <input type="text" id="searchInput" placeholder="<?= __('search_orders_ph', 'Search by customer name, order #, or status...') ?>"
               oninput="searchOrders()" onkeydown="if(event.key==='Escape')clearSearch()">
    </div>
    
    <div class="vo-filter-selects-box">
        <!-- Date Dropdown (Mobile Select / Desktop Secondary) -->
        <div class="vo-date-select-mobile">
            <select id="voMobileDateSelect" class="vo-mobile-select" onchange="filterDateRange(this.value, null)">
                <option value="all" <?= $_init_range === 'all' ? 'selected' : '' ?>><?= __('range_all', 'All Time') ?></option>
                <option value="today" <?= $_init_range === 'today' ? 'selected' : '' ?>><?= __('range_today', 'Today') ?></option>
                <option value="week" <?= $_init_range === 'week' ? 'selected' : '' ?>><?= __('range_this_week', 'This Week') ?></option>
                <option value="month" <?= $_init_range === 'month' ? 'selected' : '' ?>><?= __('range_this_month', 'This Month') ?></option>
                <option value="year" <?= $_init_range === 'year' ? 'selected' : '' ?>><?= __('range_this_year', 'This Year') ?></option>
            </select>
        </div>

        <select id="staffFilterSelect" onchange="filterByStaff(this.value)">
            <option value="all" <?= (($_GET['staff'] ?? 'all') === 'all') ? 'selected' : '' ?>><?= __('filter_all_orders', 'All Orders') ?></option>
            <option value="mine" <?= (($_GET['staff'] ?? '') === 'mine') ? 'selected' : '' ?>><?= __('filter_my_orders', 'My Orders') ?></option>
        </select>
    </div>
</div>

<!-- Desktop Quick Date Filter Pills (Hidden on Mobile) -->
<div class="vo-date-pills-desktop" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:20px;">
    <button type="button" class="vo-date-pill <?= $_init_range === 'all' ? 'active' : '' ?>" onclick="filterDateRange('all', this)">
        <i class="fa-solid fa-layer-group"></i> <?= __('range_all', 'All Time') ?>
    </button>
    <button type="button" class="vo-date-pill <?= $_init_range === 'today' ? 'active' : '' ?>" onclick="filterDateRange('today', this)">
        <i class="fa-solid fa-calendar-day"></i> <?= __('range_today', 'Today') ?>
    </button>
    <button type="button" class="vo-date-pill <?= $_init_range === 'week' ? 'active' : '' ?>" onclick="filterDateRange('week', this)">
        <i class="fa-solid fa-calendar-week"></i> <?= __('range_this_week', 'This Week') ?>
    </button>
    <button type="button" class="vo-date-pill <?= $_init_range === 'month' ? 'active' : '' ?>" onclick="filterDateRange('month', this)">
        <i class="fa-solid fa-calendar-days"></i> <?= __('range_this_month', 'This Month') ?>
    </button>
    <button type="button" class="vo-date-pill <?= $_init_range === 'year' ? 'active' : '' ?>" onclick="filterDateRange('year', this)">
        <i class="fa-regular fa-calendar-check"></i> <?= __('range_this_year', 'This Year') ?>
    </button>
</div>

<!-- Orders Container -->
<div class="vo-table-container" style="width:100% !important; max-width:100% !important; margin:0 !important; padding:0 !important;">
    <div id="ordersBody" class="orders-grid" style="width:100% !important; max-width:100% !important;"></div>
</div>
</div><!-- /.vo-page-wrapper -->
</div><!-- /.app-main -->
</div><!-- /.app-layout -->
<?php endif; ?>

<!-- Printable 80mm thermal receipt container -->
<div id="printableReceipt" style="display:none;"></div>

<!-- Order Detail Modal -->
<div class="detail-modal" id="orderDetailModal">
    <div class="detail-modal-content">
        <div id="orderDetailContent"></div>
    </div>
</div>

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
let currentFilter = '<?= (($_SESSION['role'] ?? '') === 'barista') ? 'New' : 'all' ?>';
let currentStaffFilter = '<?= (isset($_GET['staff']) && $_GET['staff'] === 'mine') ? 'mine' : 'all' ?>';
const myUsername = <?= json_encode($_SESSION['username'] ?? '') ?>;

function filterByStaff(val) {
    currentStaffFilter = val;
    applyFilters();
}
let showCompleted = true;
let searchQuery = '';
let currentCancelId = 0;
let currentRefundId = 0;
let currentRemakeId = 0;
let allOrders = [];

// ── Get user role from PHP ──
var userRole = "<?= $_SESSION['role'] ?? 'staff' ?>";
var OVERDUE_MINUTES = <?= (int)OVERDUE_MINUTES ?>;
var isAdmin = userRole === 'admin';
var canManageOrders = userRole === 'admin' || userRole === 'manager';
var canRemake = userRole === 'admin' || userRole === 'manager' || userRole === 'staff';

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

function getEmployeeDisplayName(o) {
    if (o && o.employee_name && o.employee_name.trim() !== '') {
        return o.employee_name;
    }
    return 'Staff';
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
        if (i.size)      chips.push(`<span class="item-chip"><i class="fa-solid fa-ruler-combined"></i> Size: ${escapeHtml(i.size)}</span>`);
        if (i.sweetness) chips.push(`<span class="item-chip"><i class="fa-solid fa-cube"></i> ${escapeHtml(i.sweetness)}</span>`);
        if (i.ice)       chips.push(`<span class="item-chip"><i class="fa-solid fa-cubes-stacked"></i> ${escapeHtml(i.ice)}</span>`);
        if (i.milk)      chips.push(`<span class="item-chip"><i class="fa-solid fa-glass-water"></i> ${escapeHtml(i.milk)}</span>`);
        if (i.addons && i.addons.length) chips.push(`<span class="item-chip"><i class="fa-solid fa-puzzle-piece"></i> Add-ons: ${escapeHtml(i.addons.join(', '))}</span>`);

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
//   Paid + is_open=1 → paid, tab still open, not yet made      → New (work queue)
//   'Preparing'      → legacy queue rows also read as New
//   'Making'         → taken by a barista, being made
// Everything else already names a fulfilment state. Action buttons deliberately keep
// using the REAL o.status — only display/filtering is translated.
function boardState(o) {
    if (o.status === 'Paid') return Number(o.is_open) === 1 ? 'New' : 'Completed';
    if (o.status === 'Preparing') return 'New';
    if (o.status === 'Making') return 'Making';
    return o.status;
}

// ── Determine Status Badge ──
function getStatusBadge(status) {
    let statusClass = status;
    let statusText = status;

    if (status === 'PendingPayment') {
        statusText = '<i class="fa-solid fa-clock"></i> Pending';
    } else if (status === 'Paid') {
        statusText = '<i class="fa-solid fa-dollar-sign"></i> Paid';
    } else if (status === 'New' || status === 'Preparing') {
        statusText = '<i class="fa-solid fa-mug-hot"></i> New';
    } else if (status === 'Making') {
        statusText = '<i class="fa-solid fa-fire-burner"></i> Making';
    } else if (status === 'Completed') {
        statusText = '<i class="fa-solid fa-circle-check"></i> Completed';
    } else if (status === 'Cancelled') {
        statusText = '<i class="fa-solid fa-circle-xmark"></i> Cancelled';
    } else if (status === 'Refunded') {
        statusText = '<i class="fa-solid fa-rotate-left"></i> Refunded';
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
                ${ageBadgeHtml(o.order_date, boardState(o))}
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
                ${escapeHtml(getEmployeeDisplayName(o))}
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
    const isQueued = boardState(o) === 'New';
    const overdue = isQueued && age >= OVERDUE_MINUTES;
    const badge = isQueued
        ? `<span class="bcard-badge ${overdue ? 'overdue' : 'prep'}">${overdue ? '<i class="fa-solid fa-circle-exclamation"></i> Overdue' : '<i class="fa-solid fa-mug-hot"></i> New'}</span>`
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
            <div class="card-employee"><span style="opacity:.6;font-size:10px">Taken by:</span> ${escapeHtml(getEmployeeDisplayName(o))}</div>
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
        if (st === 'New') {
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
            if (boardState(o) === 'New') {
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

    // Paid button - only for PendingPayment, and not for barista (cashier/manager job)
    if (o.status === 'PendingPayment' && userRole !== 'barista') {
        buttons += `
            <button class="paid-btn" onclick="markPaid(${Number(o.order_id)})" title="Mark as paid">
                <i class="fa-solid fa-credit-card"></i> <span>Paid</span>
            </button>
        `;
    }
    
    // Take button - a New (queued) order is picked up into Making
    if (state === 'New') {
        buttons += `
            <button class="take-btn" onclick="takeOrder(${Number(o.order_id)})" title="Take order into making">
                <i class="fa-solid fa-hand"></i> <span>Take</span>
            </button>
        `;
    }

    // Complete button - only for an order being made
    if (state === 'Making') {
        buttons += `
            <button class="complete-btn" onclick="completeOrder(${Number(o.order_id)})" title="Complete order">
                <i class="fa-solid fa-check"></i> <span>Complete</span>
            </button>
        `;
    }
    
    // Delete button - only for PendingPayment or Cancelled (admin only)
    if ((o.status === 'PendingPayment' || o.status === 'Cancelled') && isAdmin) {
        buttons += `
            <button class="delete-btn" onclick="removeOrder(${Number(o.order_id)})" title="Delete order">
                <i class="fa-solid fa-trash-can"></i>
            </button>
        `;
    }
    
    // Print Receipt Button
    buttons += `
        <button class="print-btn" onclick="printReceipt(${Number(o.order_id)})" title="Print Receipt">
            <i class="fa-solid fa-print"></i> <span>Print</span>
        </button>
    `;

    return buttons || `<span style="color:var(--text-muted);font-size:12px;padding:4px 0;">—</span>`;
}

// ── Update Existing Row ──
function updateExistingRow(o) {
    const card = document.getElementById("row-" + o.order_id);
    if (!card) return;

    // Play bell when a remade order transitions back to New. dataset.status
    // already holds the BOARD state, so compare like with like.
    if (boardState(o) === 'New' && o.remake_count > 0 && card.dataset.status !== 'New') {
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
        if (boardState(o) === 'New') {
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
        const orderId = card.dataset.orderId;
        const o = (allOrders || []).find(item => Number(item.order_id) === Number(orderId));
        let visible = true;

        if (o && !isOrderInDateRange(o, currentDateRange)) {
            visible = false;
        } else if (currentStaffFilter === 'mine' && o && (getEmployeeDisplayName(o) || '').trim().toLowerCase() !== (myUsername || '').trim().toLowerCase()) {
            visible = false;
        } else if (query) {
            // While searching, cross all tabs — match on text content
            const text = card.textContent.toLowerCase();
            visible = text.includes(query);
            // Barista queue is New+Making-only: search must not resurrect Completed/Cancelled cards
            if (userRole === 'barista' && !['New', 'Making'].includes(cardStatus)) visible = false;
        } else {
            // Normal tab + showCompleted filtering
            if (userRole === 'barista') {
                // Barista station has no tabs: show the whole New+Making queue.
                visible = ['New', 'Making'].includes(cardStatus);
            } else if (currentFilter !== 'all' && cardStatus !== currentFilter) {
                visible = false;
            }
            if ((cardStatus === 'Completed' || cardStatus === 'Refunded') && !showCompleted) visible = false;
        }

        card.style.display = visible ? '' : 'none';
    });

    const staffAndDateFiltered = (allOrders || []).filter(o => {
        if (!isOrderInDateRange(o, currentDateRange)) return false;
        if (currentStaffFilter === 'mine') {
            const empName = (getEmployeeDisplayName(o) || '').trim().toLowerCase();
            const myName = (myUsername || '').trim().toLowerCase();
            if (empName !== myName) return false;
        }
        return true;
    });
    updateCounts(staffAndDateFiltered);

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

    // Update active header stat box
    document.querySelectorAll('.vo-stat-box').forEach(box => {
        box.classList.remove('active');
    });
    const activeBox = document.getElementById('statCard-' + status);
    if (activeBox) activeBox.classList.add('active');
    
    if (currentViewMode === 'table') {
        renderTableView();
    } else {
        applyFilters();
    }
}

// ── I18N Translation Object for Orders Table ──
window.I18N = {
    no: '<?= __('col_no', 'No.') ?>',
    item: '<?= __('col_item', 'ITEM') ?>',
    total_price: '<?= __('col_total_price', 'Total Price') ?>',
    time: '<?= __('col_time', 'TIME') ?>',
    status: '<?= __('col_status', 'Status') ?>',
    place_by: '<?= __('col_place_by', 'Place By') ?>',
    action: '<?= __('col_action', 'Action') ?>',
    view_detail: '<?= __('view_detail', 'View Detail') ?>',
    reprint: '<?= __('reprint', 'Reprint') ?>',
    item_single: '<?= __('item_single', 'Item') ?>',
    item_plural: '<?= __('item_plural', 'Items') ?>',
    no_orders_found: '<?= __('no_orders_found', 'No orders found') ?>',
    try_changing_search: '<?= __('try_changing_search', 'Try changing search query or header filter card.') ?>',
    status_new: '<?= __('new_order', 'New') ?>',
    status_completed: '<?= __('status_completed', 'Complete') ?>',
    status_preparing: '<?= __('making', 'Making') ?>',
    status_pending: '<?= __('status_pending', 'Pending') ?>',
    status_cancelled: '<?= __('status_cancelled', 'Cancelled') ?>',
    status_refunded: '<?= __('status_refunded', 'Refunded') ?>'
};

// ── Table Status Badge Renderer (New / Making / Complete) ──
function getTableStatusBadge(boardSt) {
    if (boardSt === 'New' || boardSt === 'Preparing') {
        return `<span class="status-pill status-new"><i class="fa-solid fa-mug-hot"></i> ${window.I18N.status_new}</span>`;
    } else if (boardSt === 'Making') {
        return `<span class="status-pill status-making"><i class="fa-solid fa-fire-burner"></i> ${window.I18N.status_preparing}</span>`;
    } else if (boardSt === 'Completed') {
        return `<span class="status-pill status-complete"><i class="fa-solid fa-circle-check"></i> ${window.I18N.status_completed}</span>`;
    } else if (boardSt === 'PendingPayment') {
        return `<span class="status-pill status-pending"><i class="fa-solid fa-clock"></i> ${window.I18N.status_pending}</span>`;
    } else if (boardSt === 'Cancelled') {
        return `<span class="status-pill status-cancelled"><i class="fa-solid fa-ban"></i> ${window.I18N.status_cancelled}</span>`;
    } else if (boardSt === 'Refunded') {
        return `<span class="status-pill status-refunded"><i class="fa-solid fa-rotate-left"></i> ${window.I18N.status_refunded}</span>`;
    }
    return `<span class="status-pill status-pending">${escapeHtml(boardSt)}</span>`;
}

// ── Format Date & Time (YYYY-MM-DD hh:mm AM/PM) ──
function formatDateTime(dtStr) {
    if (!dtStr) return '—';
    const d = new Date(dtStr.replace(/-/g, '/'));
    if (isNaN(d.getTime())) return dtStr;
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    let hours = d.getHours();
    const minutes = String(d.getMinutes()).padStart(2, '0');
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12;
    const strHours = String(hours).padStart(2, '0');
    return `${year}-${month}-${day} ${strHours}:${minutes} ${ampm}`;
}

// ── Quick Date Range Filtering Logic ──
let currentDateRange = <?= json_encode($_init_range ?? 'all') ?>;

function isOrderInDateRange(o, range) {
    if (!range || range === 'all') return true;
    if (!o || !o.order_date) return true;

    const dt = new Date(String(o.order_date).replace(/-/g, '/'));
    if (isNaN(dt.getTime())) return true;

    const now = new Date();
    const orderYear = dt.getFullYear();
    const orderMonth = dt.getMonth();
    const orderDate = dt.getDate();

    const nowYear = now.getFullYear();
    const nowMonth = now.getMonth();
    const nowDate = now.getDate();

    if (range === 'today') {
        const oDateStr = String(o.order_date).substring(0, 10);
        const pad = n => String(n).padStart(2, '0');
        const todayStr = `${nowYear}-${pad(nowMonth + 1)}-${pad(nowDate)}`;
        return oDateStr === todayStr || (orderYear === nowYear && orderMonth === nowMonth && orderDate === nowDate);
    }

    if (range === 'week') {
        const day = now.getDay();
        const diffToMon = (day === 0 ? -6 : 1 - day);
        const startOfWeek = new Date(now);
        startOfWeek.setDate(now.getDate() + diffToMon);
        startOfWeek.setHours(0, 0, 0, 0);

        const endOfWeek = new Date(startOfWeek);
        endOfWeek.setDate(startOfWeek.getDate() + 6);
        endOfWeek.setHours(23, 59, 59, 999);

        return dt >= startOfWeek && dt <= endOfWeek;
    }

    if (range === 'month') {
        return orderYear === nowYear && orderMonth === nowMonth;
    }

    if (range === 'year') {
        return orderYear === nowYear;
    }

    return true;
}

function updateCounts(orders) {
    let countAll = 0;
    let totalPrice = 0;

    (orders || []).forEach(o => {
        if (isOrderInDateRange(o, currentDateRange)) {
            countAll++;
            totalPrice += parseFloat(o.total || 0);
        }
    });

    const elAll = document.getElementById('stat-count-all-orders');
    if (elAll) elAll.textContent = countAll;

    const elPrice = document.getElementById('stat-count-total-price');
    if (elPrice) elPrice.textContent = '$' + totalPrice.toFixed(2);
}

function filterDateRange(range, btn) {
    currentDateRange = range || 'all';

    // Sync mobile select
    const mobSelect = document.getElementById('voMobileDateSelect');
    if (mobSelect && mobSelect.value !== currentDateRange) {
        mobSelect.value = currentDateRange;
    }

    // Sync desktop pills
    document.querySelectorAll('.vo-date-pill').forEach(b => b.classList.remove('active'));
    if (btn) {
        btn.classList.add('active');
    } else {
        const matchingBtn = Array.from(document.querySelectorAll('.vo-date-pill')).find(b => b.getAttribute('onclick')?.includes(`'${currentDateRange}'`));
        if (matchingBtn) matchingBtn.classList.add('active');
    }

    renderTableView();
    applyFilters();
    loadOrders();
}

// ── Teal Table View Renderer ──
function renderTableView() {
    const container = document.getElementById('ordersBody');
    if (!container) return;
    container.className = 'orders-table-wrapper-container';

    const staffAndDateFiltered = (allOrders || []).filter(o => {
        if (!isOrderInDateRange(o, currentDateRange)) return false;
        if (currentStaffFilter === 'mine') {
            const empName = (getEmployeeDisplayName(o) || '').trim().toLowerCase();
            const myName = (myUsername || '').trim().toLowerCase();
            if (empName !== myName) return false;
        }
        return true;
    });
    updateCounts(staffAndDateFiltered);

    const query = searchQuery.toLowerCase().trim();
    const now = new Date();
    const filteredOrders = (allOrders || []).filter(o => {
        if (!isOrderInDateRange(o, currentDateRange)) return false;
        if (currentStaffFilter === 'mine') {
            const empName = (getEmployeeDisplayName(o) || '').trim().toLowerCase();
            const myName = (myUsername || '').trim().toLowerCase();
            if (empName !== myName) return false;
        }
        const boardSt = boardState(o);
        if (query) {
            const text = (o.daily_order_no + ' ' + (o.customer_name||'') + ' ' + boardSt + ' ' + (o.employee_name||'')).toLowerCase();
            if (!text.includes(query)) return false;
            if (userRole === 'barista' && !['New', 'Making'].includes(boardSt)) return false;
        } else {
            if (userRole === 'barista') {
                if (!['New', 'Making'].includes(boardSt)) return false;
            } else if (currentFilter !== 'all' && boardSt !== currentFilter) {
                return false;
            }
            if ((boardSt === 'Completed' || o.status === 'Refunded') && !showCompleted && currentFilter !== 'Completed') return false;
        }
        return true;
    });

    if (filteredOrders.length === 0) {
        container.innerHTML = `
            <div class="vo-table-wrapper">
                <table class="vo-table">
                    <thead>
                        <tr>
                            <th style="width:10%;">${window.I18N.no}</th>
                            <th style="width:22%;">${window.I18N.item}</th>
                            <th style="width:15%;">${window.I18N.total_price}</th>
                            <th style="width:18%;">${window.I18N.time}</th>
                            <th style="width:15%;">${window.I18N.place_by}</th>
                            <th style="width:20%;">${window.I18N.action}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="6" style="text-align:center;padding:60px 20px;color:var(--text-muted);">
                                <div style="width:56px;height:56px;border-radius:50%;background:rgba(15,118,110,0.15);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;color:#0f766e;font-size:22px;">
                                    <i class="fa-solid fa-mug-hot"></i>
                                </div>
                                <div style="font-weight:700;color:var(--text);margin-bottom:4px;font-size:15px;">${window.I18N.no_orders_found}</div>
                                <div style="font-size:12.5px;">${window.I18N.try_changing_search}</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        `;
        return;
    }

    const rowsHtml = filteredOrders.map(o => {
        const boardSt = boardState(o);
        const totalItemsQty = (o.items || []).reduce((sum, item) => sum + parseInt(item.quantity || 1, 10), 0);
        const itemLabel = totalItemsQty === 1 ? window.I18N.item_single : window.I18N.item_plural;
        const itemsSummary = `<span class="vo-items-count-badge" title="${escapeHtml((o.items || []).map(i => i.quantity + 'x ' + i.product_name).join(', '))}"><i class="fa-solid fa-box"></i> ${totalItemsQty} ${itemLabel}</span>`;
        const dtFormatted = formatDateTime(o.order_date);
        const timeDisplay = `
            <span class="vo-date-time-badge" style="font-size:12px;padding:4px 10px;border-radius:6px;background:rgba(255,255,255,0.06);color:var(--text);display:inline-flex;align-items:center;gap:6px;font-weight:600;white-space:nowrap;">
                <i class="fa-regular fa-clock" style="color:var(--accent);font-size:12px;"></i> ${escapeHtml(dtFormatted)}
            </span>
        `;

        const completeBtnHtml = (boardSt === 'Making') ? `
            <button class="complete-btn" onclick="completeOrder(${Number(o.order_id)})" title="Complete Order">
                <i class="fa-solid fa-check"></i> <span>Complete</span>
            </button>
        ` : '';

        const orderNoPadded = String(o.daily_order_no || o.order_id || '').padStart(4, '0');

        return `
            <tr id="row-${o.order_id}" data-status="${boardSt}">
                <td class="vo-col-order">#${escapeHtml(orderNoPadded)}</td>
                <td class="vo-col-items">${itemsSummary}</td>
                <td class="vo-col-total">$${parseFloat(o.total || 0).toFixed(2)}</td>
                <td class="vo-col-date" title="${escapeHtml(o.order_date || '')}">${timeDisplay}</td>
                <td class="vo-col-placed">${escapeHtml(getEmployeeDisplayName(o))}</td>
                <td class="vo-col-actions" style="display:flex;align-items:center;gap:6px;flex-wrap:nowrap;white-space:nowrap;">
                    <button class="btn-view-detail" onclick="openOrderDetailModal(${Number(o.order_id)})" title="View Order Details">
                        <i class="fa-solid fa-eye"></i> <span>${window.I18N.view_detail}</span>
                    </button>
                    <button class="btn-reprint" onclick="printReceipt(${Number(o.order_id)})" title="Reprint Receipt">
                        <i class="fa-solid fa-print"></i> <span>${window.I18N.reprint}</span>
                    </button>
                    ${completeBtnHtml}
                </td>
            </tr>
        `;
    }).join('');

    const prevWrapper = container.querySelector('.vo-table-wrapper');
    const savedScrollTop = prevWrapper ? prevWrapper.scrollTop : 0;
    const savedScrollLeft = prevWrapper ? prevWrapper.scrollLeft : 0;

    container.innerHTML = `
        <div class="vo-table-wrapper">
            <table class="vo-table">
                <thead>
                    <tr>
                        <th style="width:10%;">${window.I18N.no}</th>
                        <th style="width:22%;">${window.I18N.item}</th>
                        <th style="width:15%;">${window.I18N.total_price}</th>
                        <th style="width:18%;">${window.I18N.time}</th>
                        <th style="width:15%;">${window.I18N.place_by}</th>
                        <th style="width:20%;">${window.I18N.action}</th>
                    </tr>
                </thead>
                <tbody>
                    ${rowsHtml}
                </tbody>
            </table>
        </div>
    `;

    const newWrapper = container.querySelector('.vo-table-wrapper');
    if (newWrapper) {
        newWrapper.scrollTop = savedScrollTop;
        newWrapper.scrollLeft = savedScrollLeft;
    }
}

// ── View Toggle ──
let currentViewMode = localStorage.getItem('orders_view_mode') || 'table';

function setOrdersView(mode) {
    currentViewMode = mode;
    localStorage.setItem('orders_view_mode', mode);

    const btnGrid = document.getElementById('btnViewGrid');
    const btnList = document.getElementById('btnViewList');
    if (btnGrid) btnGrid.classList.toggle('active', mode === 'grid');
    if (btnList) btnList.classList.toggle('active', mode === 'table' || mode === 'list');

    const container = document.getElementById('ordersBody');
    if (container) {
        if (mode === 'table' || mode === 'list') {
            container.className = 'orders-table-wrapper-container';
            renderTableView();
        } else {
            container.className = 'orders-grid';
            known.clear();
            container.innerHTML = '';
            (allOrders || []).forEach(o => addRow(o));
            applyFilters();
        }
    }
}

// Update applyFilters to support Table View
const _oldApplyFilters = applyFilters;
applyFilters = function() {
    if (currentViewMode === 'table' || currentViewMode === 'list') {
        renderTableView();
    } else {
        _oldApplyFilters();
    }
};

let currentModalOrderId = null;

// ── View Order Detail Modal Functions (View-Only Receipt Style) ──
function openOrderDetailModal(orderId) {
    const o = (allOrders || []).find(item => Number(item.order_id) === Number(orderId));
    if (!o) return;
    currentModalOrderId = Number(orderId);

    const modal = document.getElementById('orderDetailModal');
    const content = document.getElementById('orderDetailContent');
    if (!modal || !content) return;

    const orderNoPaddedModal = String(o.daily_order_no || o.order_id || '').padStart(4, '0');
    const promoDisc = parseFloat(o.promotion_discount || 0);
    const manualDisc = parseFloat(o.manual_discount || 0);
    const totalDisc = promoDisc + manualDisc;
    const grandTotal = parseFloat(o.total || 0);
    const subtotalCalc = grandTotal + totalDisc;
    const grandTotalKhr = Math.round(grandTotal * 4000).toLocaleString('en-US');

    const rcptItemsRows = (o.items || []).map((i, idx) => {
        const unitPrice = parseFloat(i.price || 0);
        const qty = parseInt(i.quantity, 10) || 1;
        const lineTotal = unitPrice * qty;

        const sub = [i.size, i.sweetness, i.ice, i.milk].concat(i.addons || []).filter(Boolean).join(', ');
        const noteText = i.note ? `(${i.note})` : '';
        const subtext = [noteText, sub].filter(Boolean).join(' ');

        return `
            <tr style="border-bottom:1px solid #000000;">
                <td style="border:1px solid #000000;padding:5px 3px;text-align:center;font-weight:600;">${idx + 1}</td>
                <td style="border:1px solid #000000;padding:5px 4px;text-align:left;">
                    <div style="font-weight:700;color:#000000;font-size:11.5px;">${escapeHtml(i.product_name)}</div>
                    ${subtext ? `<div style="font-size:9.5px;color:#555555;margin-top:1px;">${escapeHtml(subtext)}</div>` : ''}
                </td>
                <td style="border:1px solid #000000;padding:5px 3px;text-align:center;font-weight:700;font-size:11px;">${qty}</td>
                <td style="border:1px solid #000000;padding:5px 3px;text-align:center;font-size:11px;">$${unitPrice.toFixed(2)}</td>
                <td style="border:1px solid #000000;padding:5px 3px;text-align:center;font-weight:700;font-size:11px;">$${lineTotal.toFixed(2)}</td>
            </tr>
        `;
    }).join('');

    content.innerHTML = `
        <div style="display:flex;align-items:center;justify-content:space-between;padding-bottom:12px;border-bottom:1px solid rgba(255,255,255,0.1);margin-bottom:16px;">
            <h3 style="font-size:18px;font-weight:700;color:#ffffff;margin:0;display:flex;align-items:center;gap:10px;">
                <i class="fa-solid fa-receipt" style="color:#d1904b"></i> Order #${escapeHtml(orderNoPaddedModal)} Receipt Details
            </h3>
            <button onclick="closeOrderDetailModal()" style="background:none;border:none;color:#a1a1aa;font-size:20px;cursor:pointer;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- 80mm Thermal Receipt Canvas (View Only) -->
        <div style="width:100%;max-width:380px;margin:0 auto;background:#ffffff;color:#000000;padding:20px 18px;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,0.5);font-family:'Kantumruy Pro','Poppins',sans-serif;font-size:11.5px;line-height:1.4;">
            <!-- Header -->
            <div style="text-align:center;margin-bottom:12px;">
                <h1 style="font-size:19px;font-weight:800;color:#000000;margin:0;line-height:1.2;"><?= htmlspecialchars(defined('RECEIPT_SHOP_NAME') ? RECEIPT_SHOP_NAME : 'The Bird Nest Cafe') ?></h1>
                <p style="font-size:11px;color:#555555;margin:2px 0 0 0;"><?= htmlspecialchars(defined('RECEIPT_LOCATION') ? RECEIPT_LOCATION : 'Phnom Penh') ?></p>
                <?php if (defined('RECEIPT_PHONE') && RECEIPT_PHONE !== ''): ?>
                <p style="font-size:11px;color:#555555;margin:1px 0 0 0;"><?= htmlspecialchars(RECEIPT_PHONE) ?></p>
                <?php endif; ?>
                <h2 style="font-size:15px;font-weight:700;color:#000000;margin:8px 0 2px 0;letter-spacing:1px;">វិក្កយបត្រ / RECEIPT</h2>
            </div>

            <!-- Meta Data Grid -->
            <div style="font-size:11px;line-height:1.5;margin-bottom:12px;border-bottom:1px dashed #666666;padding-bottom:10px;">
                <div style="display:flex;justify-content:space-between;">
                    <span>អ្នកគិតលុយ : <b>${escapeHtml(getEmployeeDisplayName(o))}</b></span>
                    <span>លេខវិក្កយបត្រ : <b>#${escapeHtml(orderNoPaddedModal)}</b></span>
                </div>
                <div style="display:flex;justify-content:space-between;margin-top:2px;">
                    <span>អតិថិជន : <b>${escapeHtml(o.customer_name || 'General Customer')}</b></span>
                    <span>ម៉ោងចេញ : <b>${escapeHtml(o.order_date || '')}</b></span>
                </div>
                <div style="display:flex;justify-content:space-between;margin-top:2px;">
                    <span>បង់តាម : <b>${escapeHtml(o.payment_method || 'Cash')}</b></span>
                </div>
            </div>

            <!-- Bordered Item Table -->
            <table style="width:100%;border-collapse:collapse;font-size:10.5px;margin:10px 0;border:1px solid #000000;">
                <thead>
                    <tr style="background:#f3f4f6;border-bottom:1px solid #000000;color:#000000;">
                        <th style="border:1px solid #000000;padding:5px 3px;text-align:center;font-weight:700;width:8%;">ល.រ</th>
                        <th style="border:1px solid #000000;padding:5px 4px;text-align:left;font-weight:700;width:48%;">បរិយាយ</th>
                        <th style="border:1px solid #000000;padding:5px 3px;text-align:center;font-weight:700;width:14%;">ចំនួន</th>
                        <th style="border:1px solid #000000;padding:5px 3px;text-align:center;font-weight:700;width:15%;">តម្លៃ</th>
                        <th style="border:1px solid #000000;padding:5px 3px;text-align:center;font-weight:700;width:15%;">សរុប</th>
                    </tr>
                </thead>
                <tbody>
                    ${rcptItemsRows}
                </tbody>
            </table>

            <!-- Totals Section -->
            <div style="font-size:11.5px;line-height:1.6;margin-top:10px;">
                ${totalDisc > 0 ? `
                <div style="display:flex;justify-content:space-between;color:#444444;">
                    <span>ប្រាក់សរុប (Subtotal) :</span>
                    <span>USD $${subtotalCalc.toFixed(2)}</span>
                </div>
                <div style="display:flex;justify-content:space-between;color:#dc2626;">
                    <span>បញ្ចុះតម្លៃ (Discount) :</span>
                    <span>-$${totalDisc.toFixed(2)}</span>
                </div>
                ` : ''}
                <div style="display:flex;justify-content:space-between;font-weight:800;font-size:13px;border-top:1px solid #000000;padding-top:6px;margin-top:4px;">
                    <span>ប្រាក់សរុបចុងក្រោយ :</span>
                    <span>USD $${grandTotal.toFixed(2)}</span>
                </div>
                <div style="text-align:right;font-weight:700;font-size:11px;color:#333333;margin-top:2px;">
                    <span>KHR ${grandTotalKhr}</span>
                </div>
                <div style="display:flex;justify-content:space-between;margin-top:4px;">
                    <span>ប្រាក់ទទួល :</span>
                    <span>USD $${grandTotal.toFixed(2)}</span>
                </div>
                <div style="display:flex;justify-content:space-between;font-weight:800;font-size:12px;">
                    <span>ប្រាក់អាប់ :</span>
                    <span>USD $0.00</span>
                </div>
                <div style="text-align:right;font-weight:700;font-size:11px;color:#333333;">
                    <span>KHR 0</span>
                </div>
            </div>

            <!-- Footer Divider -->
            <div style="border-top:1px dashed #000000;margin:14px 0 8px 0;"></div>
            <div style="text-align:center;font-size:11px;color:#444444;font-weight:600;">
                សូមអរគុណ! Thank You!
            </div>
        </div>

        <!-- Footer Buttons (ONLY VIEW - NO PRINT) -->
        <div style="display:flex;justify-content:flex-end;margin-top:18px;padding-top:14px;border-top:1px solid rgba(255,255,255,0.1);">
            <button onclick="closeOrderDetailModal()" style="padding:10px 24px;border-radius:10px;border:1px solid rgba(255,255,255,0.15);background:rgba(255,255,255,0.1);color:#ffffff;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;font-size:14px;transition:all 0.2s;">
                Close
            </button>
        </div>
    `;

    modal.classList.add('active');
}

function closeOrderDetailModal() {
    currentModalOrderId = null;
    const modal = document.getElementById('orderDetailModal');
    if (modal) modal.classList.remove('active');
}

function closeReceiptModal() {}

function printReceipt(orderId) {
    const o = (allOrders || []).find(item => Number(item.order_id) === Number(orderId));
    const targetId = o ? o.order_id : orderId;
    if (!targetId) return;

    const url = 'receipt_print.php?order_id=' + Number(targetId) + '&auto_print=1';
    
    // Open receipt print window (allowed by browser on direct user click)
    var win = window.open(url, 'receipt_win', 'width=450,height=700,scrollbars=yes');
    if (win) {
        try { win.focus(); } catch(e) {}
    } else {
        // Fallback to hidden iframe if popups are blocked
        var existingFrame = document.getElementById('receiptPrintFrame');
        if (existingFrame) existingFrame.remove();

        var iframe = document.createElement('iframe');
        iframe.id = 'receiptPrintFrame';
        iframe.style.position = 'fixed';
        iframe.style.right = '0';
        iframe.style.bottom = '0';
        iframe.style.width = '10px';
        iframe.style.height = '10px';
        iframe.style.opacity = '0';
        iframe.style.pointerEvents = 'none';
        iframe.style.border = '0';
        iframe.src = url;
        document.body.appendChild(iframe);

        setTimeout(function() {
            if (iframe && iframe.parentNode) iframe.parentNode.removeChild(iframe);
        }, 60000);
    }
}

function buildReceiptHtml(o) {
    const itemsHtml = (o.items || []).map(i => {
        const unitPrice = parseFloat(i.price || 0) || (parseFloat(o.total || 0) / (o.items.length || 1));
        const lineTotal = unitPrice * parseInt(i.quantity, 10);
        const sub = [
            i.sweetness ? 'Sweet: ' + i.sweetness : '',
            i.ice ? 'Ice: ' + i.ice : '',
            i.milk ? 'Milk: ' + i.milk : ''
        ].filter(Boolean).join(', ');
        return `
            <tr>
                <td style="padding:6px 0;vertical-align:top">
                    <div style="font-weight:700;color:var(--text, #000)">${escapeHtml(i.product_name)}</div>
                    ${sub ? `<div style="font-size:10.5px;opacity:.7">${escapeHtml(sub)}</div>` : ''}
                </td>
                <td style="text-align:center;padding:6px;vertical-align:top">×${i.quantity}</td>
                <td style="text-align:right;padding:6px 0;vertical-align:top">$${lineTotal.toFixed(2)}</td>
            </tr>
        `;
    }).join('');

    return `
        <div class="receipt-header">
            <div class="receipt-brand"><i class="fa-solid fa-mug-hot"></i> Bird's Nest Coffee</div>
            <div class="receipt-sub">Phnom Penh, Cambodia · Official Receipt</div>
        </div>

        <div class="receipt-info-grid">
            <div><strong>Order #:</strong> #${escapeHtml(String(o.daily_order_no))}</div>
            <div><strong>Date:</strong> ${escapeHtml(o.order_date || '')}</div>
            <div><strong>Status:</strong> ${escapeHtml(boardState(o))}</div>
            <div><strong>Cashier:</strong> ${escapeHtml(getEmployeeDisplayName(o))}</div>
        </div>

        <table class="receipt-items-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th style="text-align:center">Qty</th>
                    <th style="text-align:right">Price</th>
                </tr>
            </thead>
            <tbody>
                ${itemsHtml}
            </tbody>
        </table>

        <div class="receipt-totals">
            <div class="receipt-total-row">
                <span>Payment Method</span>
                <span style="font-weight:600;text-transform:uppercase">${escapeHtml(o.payment_method || 'Cash')}</span>
            </div>
            <div class="receipt-total-row grand">
                <span>TOTAL</span>
                <span>$${parseFloat(o.total || 0).toFixed(2)}</span>
            </div>
        </div>

        <div class="receipt-footer-msg">
            Thank you for visiting Bird's Nest Coffee!<br>
            Please come again ☕
        </div>
    `;
}

document.addEventListener('DOMContentLoaded', () => {
    setOrdersView(currentViewMode);
    setInterval(() => {
        if (currentViewMode === 'table') {
            renderTableView();
        }
        if (currentModalOrderId) {
            openOrderDetailModal(currentModalOrderId);
        }
    }, 1000);
});

let lastOverdueCount = 0;

function playOverdueAlertSound() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(880, ctx.currentTime);
        osc.frequency.exponentialRampToValueAtTime(440, ctx.currentTime + 0.35);
        gain.gain.setValueAtTime(0.35, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.35);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.35);
    } catch(e) {}
}

function updateCounts(data) {
    if (!data) data = allOrders || [];
    const counts = { all: 0, PendingPayment: 0, New: 0, Making: 0, Completed: 0, Cancelled: 0, Refunded: 0 };
    let totalPrice = 0;

    data.forEach(o => {
        counts.all++;
        const s = boardState(o);
        if (counts[s] !== undefined) {
            counts[s]++;
        }
        if (o.status !== 'Cancelled' && o.status !== 'Refunded') {
            totalPrice += parseFloat(o.total || 0);
        }
    });

    const setCount = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    setCount('count-all', counts.all);
    setCount('count-PendingPayment', counts.PendingPayment);
    setCount('count-New', counts.New);
    setCount('count-Making', counts.Making);
    setCount('count-Completed', counts.Completed);
    setCount('count-Cancelled', counts.Cancelled);
    setCount('count-Refunded', counts.Refunded);

    // Populate 5 Header Box Counts
    setCount('stat-count-all-orders', counts.all);
    setCount('stat-count-new', counts.New);
    setCount('stat-count-making', counts.Making);
    setCount('stat-count-complete', counts.Completed);
    setCount('stat-count-total-price', '$' + totalPrice.toFixed(2));
}
function updateHeaderStats(data) { updateCounts(data); }


// ── Load Orders ──
async function loadOrders() {
    try {
        const r = await fetch("view_order.php?action=fetch&range=" + encodeURIComponent(currentDateRange), { cache: "no-store" });
        const raw = await r.json();

        // Support both old array format and new {orders, announcements} format
        const data = Array.isArray(raw) ? raw : (raw.orders || []);
        allOrders = data;
        if (raw.announcements !== undefined) updateAnnouncements(raw.announcements);

        const currentIds = new Set();

        if (data.length === 0) {
            if (currentViewMode === 'table' || currentViewMode === 'list') {
                renderTableView();
            } else if (tbody.children.length === 0) {
                const empty = document.createElement("div");
                empty.id = "ordersEmptyState";
                empty.style.cssText = 'grid-column:1/-1;text-align:center;padding:70px 20px;color:var(--text-muted);';
                empty.innerHTML = ordersEmptyHtml();
                tbody.appendChild(empty);
            }
            updateCounts([]);
            return;
        }

        // Remove empty state if present
        const emptyEl = document.getElementById('ordersEmptyState');
        if (emptyEl) emptyEl.remove();

        // Update counts
        updateCounts(data);

        if (currentViewMode === 'table' || currentViewMode === 'list') {
            renderTableView();
        } else {
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
            applyFilters();
        }
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

// ── Take Order (New → Making) ──
async function takeOrder(id) {
    const btn = document.querySelector(`#row-${id} .take-btn`);
    if (btn) btn.disabled = true;

    try {
        const r = await fetch(`view_order.php?action=take&id=${id}`, { cache: "no-store" });
        const res = await r.json();

        if (res.ok) {
            await loadOrders();
            showToast("✅ Order taken — making now");
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
    if (btn) btn.disabled = true;

    try {
        const r = await fetch(`view_order.php?action=complete&id=${id}`, { cache: "no-store" });
        const res = await r.json();

        if (res.ok) {
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
    else if (userRole === 'barista') filterStatus('New');

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
const AGE_ACTIVE = new Set(['Pending','Processing','Preparing','PendingPayment','New','Making']);

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
<?php 
$_print_order_no = '';
if (isset($_GET['print_order_id'])) {
    $_p_id = (int)$_GET['print_order_id'];
    $_stmt_p = $conn->prepare("SELECT order_id FROM orders WHERE order_id = ?");
    if ($_stmt_p) {
        $_stmt_p->bind_param("i", $_p_id);
        $_stmt_p->execute();
        $_res_p = $_stmt_p->get_result()->fetch_assoc();
        if ($_res_p && !empty($_res_p['order_id'])) {
            $_print_order_no = sprintf('%04d', (int)$_res_p['order_id']);
        }
        $_stmt_p->close();
    }
}
?>
<?php if (isset($_GET['print_order_id'])): ?>
<style>
.order-toast-right {
    position: fixed;
    top: 24px;
    right: 24px;
    z-index: 999999;
    background: #18181b;
    border: 1px solid rgba(85, 224, 135, 0.4);
    border-left: 5px solid #55e087;
    border-radius: 14px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 12px 35px rgba(0, 0, 0, 0.6);
    animation: slideInRight 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    min-width: 300px;
    max-width: 90vw;
}
@keyframes slideInRight {
    from { transform: translateX(120%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
.order-toast-icon {
    width: 40px;
    height: 40px;
    background: rgba(85, 224, 135, 0.15);
    color: #55e087;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}
.order-toast-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.order-toast-title {
    color: #ffffff;
    font-size: 15px;
    font-weight: 800;
    margin: 0;
    font-family: 'Poppins', sans-serif;
}
.order-toast-msg {
    color: #a1a1aa;
    font-size: 13px;
    margin: 0;
    font-family: 'Poppins', sans-serif;
}
.order-toast-close {
    background: none;
    border: none;
    color: #71717a;
    font-size: 16px;
    cursor: pointer;
    padding: 4px;
    line-height: 1;
    transition: color 0.2s ease;
}
.order-toast-close:hover {
    color: #ffffff;
}
</style>

<div class="order-toast-right" id="orderSuccessAlert">
    <div class="order-toast-icon">
        <i class="fa-solid fa-circle-check"></i>
    </div>
    <div class="order-toast-content">
        <h4 class="order-toast-title">Order #<?= htmlspecialchars($_print_order_no ?: '001') ?></h4>
        <p class="order-toast-msg">Done! Sent to printer 🖨️</p>
    </div>
    <button type="button" class="order-toast-close" onclick="closeOrderSuccessAlert()" title="Close">
        <i class="fa-solid fa-xmark"></i>
    </button>
</div>

<script>
function closeOrderSuccessAlert() {
    var el = document.getElementById('orderSuccessAlert');
    if (el) el.remove();
}

setTimeout(closeOrderSuccessAlert, 5000);

document.addEventListener("DOMContentLoaded", function() {
    var printOrderId = <?= (int)($_GET['print_order_id'] ?? 0) ?>;
    if (printOrderId > 0) {
        var existingFrame = document.getElementById('receiptPrintFrame');
        if (existingFrame) existingFrame.remove();

        var iframe = document.createElement('iframe');
        iframe.id = 'receiptPrintFrame';
        iframe.style.position = 'fixed';
        iframe.style.right = '0';
        iframe.style.bottom = '0';
        iframe.style.width = '10px';
        iframe.style.height = '10px';
        iframe.style.opacity = '0';
        iframe.style.pointerEvents = 'none';
        iframe.style.border = '0';
        iframe.onload = function() {
            try {
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
            } catch(e) {
                console.error("Auto print error:", e);
            }
            setTimeout(function() {
                if (iframe.parentNode) iframe.parentNode.removeChild(iframe);
            }, 60000);
        };
        iframe.src = 'receipt_print.php?order_id=' + printOrderId + '&auto_print=1';
        document.body.appendChild(iframe);
    }

    if (window.history && window.history.replaceState) {
        var url = new URL(window.location.href);
        url.searchParams.delete('print_order_id');
        window.history.replaceState({}, document.title, url.toString());
    }
});
</script>
<?php endif; ?>
</div></div>
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

    $req_range = trim($_GET['range'] ?? 'today');
    $where_sql = "";
    $bind_types = "";
    $bind_params = [];

    if ($req_range === 'all') {
        $where_sql = "1=1";
    } else if ($req_range === 'week') {
        $w_start = date('Y-m-d', strtotime('monday this week'));
        $w_end   = date('Y-m-d', strtotime('sunday this week'));
        $where_sql = "DATE(o.order_date) BETWEEN ? AND ?";
        $bind_types = "ss";
        $bind_params = [$w_start, $w_end];
    } else if ($req_range === 'month') {
        $m_start = date('Y-m-01');
        $m_end   = date('Y-m-t');
        $where_sql = "DATE(o.order_date) BETWEEN ? AND ?";
        $bind_types = "ss";
        $bind_params = [$m_start, $m_end];
    } else if ($req_range === 'year') {
        $y_start = date('Y-01-01');
        $y_end   = date('Y-12-31');
        $where_sql = "DATE(o.order_date) BETWEEN ? AND ?";
        $bind_types = "ss";
        $bind_params = [$y_start, $y_end];
    } else {
        $where_sql = "( DATE(o.order_date) = ? OR DATE(oc.cancelled_at) = ? )";
        $bind_types = "ss";
        $bind_params = [$business_date, $business_date];
    }

    $stmt = $conn->prepare("
        SELECT
            o.order_id,
            o.order_id AS daily_order_no,
            'Guest' AS customer_name,
            o.total,
            'Completed' AS status,
            o.order_date,
            o.started_at,
            NULL AS completed_at,
            o.order_id AS token_number,
            o.user_id AS employee_id,
            COALESCE(NULLIF(u.name, ''), u.username, o.prepared_by, 'Staff') AS employee_name,
            COALESCE(u.role, '') AS employee_role,
            o.prepared_by,
            '' AS prepared_by_role,
            '' AS table_number,
            'drink_in' AS order_type,
            o.payment_method,
            0 AS is_open,
            0 AS promotion_discount,
            0 AS manual_discount,
            0 AS remake_count,
            oc.cancel_reason,
            oc.cancelled_by,
            '' AS refund_reason,
            '' AS refunded_by,
            NULL AS remake_reasons,
            oi.item_id,
            oi.product_name,
            oi.price,
            oi.orig_price,
            oi.promo_percent,
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
        LEFT JOIN users u ON u.user_id = o.user_id
        LEFT JOIN order_items oi ON o.order_id = oi.order_id
        LEFT JOIN products p ON p.product_id = oi.product_id
        LEFT JOIN order_cancellations oc ON oc.order_id = o.order_id
        WHERE {$where_sql}
        GROUP BY o.order_id, u.username, u.role, oi.item_id, oi.product_name, oi.price, oi.orig_price, oi.promo_percent, oi.sweetness, oi.ice, oi.milk, oi.size_label, oi.addons_snapshot, oi.quantity, oi.made_at, oi.made_qty, oi.product_id, p.category
        ORDER BY o.order_id DESC
    ");

    if ($bind_types !== "") {
        $stmt->bind_param($bind_types, ...$bind_params);
    }
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
                "completed_at" => $r['completed_at'] ?? '',
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
                "promotion_discount" => (float)($r['promotion_discount'] ?? 0),
                "manual_discount"    => (float)($r['manual_discount'] ?? 0),
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
                "item_id"       => (int)$r["item_id"],
                "product_id"    => (int)$r["product_id"],
                "product_name"  => $r["product_name"],
                "price"         => (float)($r["price"] ?? 0),
                "orig_price"    => (float)($r["orig_price"] ?? 0),
                "promo_percent" => (int)($r["promo_percent"] ?? 0),
                "size"          => $r["size_label"],
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

    // Active announcements (removed)
    $_ann_data = [];
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
   TAKE ORDER (New -> Making)
=============================== */
if ($action === "take") {
    header('Content-Type: application/json');

    $order_id = (int)($_GET['id'] ?? 0);
    if ($order_id <= 0) {
        echo json_encode(["ok" => 0, "error" => "Invalid order id"]);
        exit;
    }

    $stmt = $conn->prepare("UPDATE orders SET started_at = IFNULL(started_at, NOW()) WHERE order_id = ?");
    $stmt->bind_param("i", $order_id);

    if ($stmt->execute()) {
        echo json_encode(["ok" => 1]);
    } else {
        echo json_encode(["ok" => 0, "error" => "Failed to update status"]);
    }
    exit;
}

/* ===============================
   MARK AS PREPARE
=============================== */
if ($action === "prepare") {
    header('Content-Type: application/json');

    $order_id = (int)($_GET['id'] ?? 0);
    if ($order_id <= 0) {
        echo json_encode(["ok" => 0, "error" => "Invalid order id"]);
        exit;
    }

    echo json_encode(["ok" => 1]);
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
        $prepared_by = $_SESSION['username'] ?? '';
        $stmt_update = $conn->prepare("UPDATE orders SET prepared_by = ? WHERE order_id = ?");
        $stmt_update->bind_param("si", $prepared_by, $order_id);
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