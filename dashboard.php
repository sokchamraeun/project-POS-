<?php
require 'auth.php';
require_once "config.php";
require_once __DIR__ . '/nav_menu.php';   // canonical permission->nav registry

$admin_name = $_SESSION['username'] ?? 'Admin';
$_is_mgr = in_array($_SESSION['role'] ?? '', ['admin', 'manager', 'supervisor']);

// Load roles for badge colours and nav icon lookups
$_roles_db = [];
$_rdb = $conn->query("SELECT slug, name, icon, color FROM roles ORDER BY is_system DESC, id ASC");
while ($_rdbr = $_rdb->fetch_assoc()) $_roles_db[$_rdbr['slug']] = $_rdbr;

$_cur_role = $_SESSION['role'] ?? 'staff';
$_cur_role_info = $_roles_db[$_cur_role] ?? null;
$_cur_role_name = $_cur_role_info['name'] ?? ucwords(str_replace('_', ' ', $_cur_role));
$_cur_role_color = $_cur_role_info['color'] ?? '#d1904b';

// Clock-in status (self-service shift tracking — same check as view_order.php)
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

$_now = new DateTime();
$business_date = (int)$_now->format("H") < 6
    ? (clone $_now)->modify("-1 day")->format("Y-m-d")
    : $_now->format("Y-m-d");

// All "today" figures key off business_date (6 AM rollover), NOT DATE(order_date)/CURDATE().
// They used to disagree: between midnight and 6 AM the KPI values counted the calendar day
// while the status pills below them counted the business day, so the same card contradicted
// itself. business_date is what every other page uses — this is the single definition.
$prev_business_date = (new DateTime($business_date))->modify('-1 day')->format('Y-m-d');

$stmt_sales = $conn->prepare("SELECT IFNULL(SUM(total),0) AS total_sales FROM orders WHERE business_date=? AND " . paid_orders_where());
$stmt_sales->bind_param("s", $business_date);
$stmt_sales->execute();
$sales = $stmt_sales->get_result()->fetch_assoc()['total_sales'];

$stmt_yest = $conn->prepare("SELECT IFNULL(SUM(total),0) AS yesterday_sales FROM orders WHERE business_date=? AND " . paid_orders_where());
$stmt_yest->bind_param("s", $prev_business_date);
$stmt_yest->execute();
$yesterday_sales   = $stmt_yest->get_result()->fetch_assoc()['yesterday_sales'];
$sales_trend       = $yesterday_sales > 0 ? round(($sales - $yesterday_sales) / $yesterday_sales * 100, 1) : 0;
$trend_class       = $sales_trend >= 0 ? 'up' : 'down';
$trend_icon        = $sales_trend >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';

$stmt_ord = $conn->prepare("SELECT COUNT(*) AS total_orders FROM orders WHERE business_date=?");
$stmt_ord->bind_param("s", $business_date);
$stmt_ord->execute();
$total_orders = $stmt_ord->get_result()->fetch_assoc()['total_orders'];

$low_result  = mysqli_query($conn, "SELECT COUNT(*) AS low_count FROM ingredients WHERE stock_quantity < minimum_stock");
$low_stock   = mysqli_fetch_assoc($low_result)['low_count'];

// Recipes whose ingredients are running low — surfaced on the "Drink Recipe" tile for prep-facing roles (e.g. barista)
$low_recipe_result = mysqli_query($conn, "
    SELECT COUNT(DISTINCT pi.product_id) AS low_recipe_count
    FROM product_ingredients pi
    JOIN ingredients i ON pi.ingredient_id = i.ingredient_id
    WHERE pi.amount_used > 0 AND i.stock_quantity < i.minimum_stock
");
$low_recipe_count = mysqli_fetch_assoc($low_recipe_result)['low_recipe_count'];

// ── Inventory-clerk dashboard metrics (only computed for that role) ──
$inv_total_products = 0; $inv_pending_po = 0; $inv_out_of_stock = 0;
$inv_low_list = [];      $inv_activity = [];
if (($_SESSION['role'] ?? '') === 'inventory_clerk') {
    $inv_total_products = (int)($conn->query("SELECT COUNT(*) c FROM products")->fetch_assoc()['c'] ?? 0);

    $inv_pending_po = (int)($conn->query(
        "SELECT COUNT(*) c FROM purchase_orders WHERE status IN ('Draft','Ordered')"
    )->fetch_assoc()['c'] ?? 0);

    // Out of stock = ingredients currently at (or below) zero — the "order now" alarm
    $inv_out_of_stock = (int)($conn->query(
        "SELECT COUNT(*) c FROM ingredients WHERE stock_quantity <= 0"
    )->fetch_assoc()['c'] ?? 0);

    $lr = $conn->query(
        "SELECT ingredient_name, stock_quantity, minimum_stock, unit
         FROM ingredients WHERE stock_quantity < minimum_stock
         ORDER BY (stock_quantity/NULLIF(minimum_stock,0)) ASC"
    );
    while ($lr && $row = $lr->fetch_assoc()) $inv_low_list[] = $row;

    $ar = $conn->query(
        "SELECT ih.change_type, ih.amount, ih.created_at, i.ingredient_name
         FROM ingredient_history ih
         JOIN ingredients i ON ih.ingredient_id = i.ingredient_id
         WHERE ih.change_type NOT IN ('order_deduct','order_restore')
         ORDER BY ih.created_at DESC LIMIT 6"
    );
    while ($ar && $row = $ar->fetch_assoc()) $inv_activity[] = $row;
}

// Cash reconciliation alert — short/over records today
$_recon_alerts = 0;
if (can('cash_reconciliation')) {
    $_rar = $conn->query("SELECT COUNT(*) FROM cash_counts WHERE shift_date = CURDATE() AND ABS(difference) >= 0.01");
    if ($_rar) $_recon_alerts = (int)$_rar->fetch_row()[0];
}

// Unread announcements count for current user
$_unread_ann = 0;
if (can('announcements')) {
    $_ar = $conn->prepare("
        SELECT COUNT(*) FROM announcements a
        WHERE a.is_active = 1
          AND (a.expires_at IS NULL OR a.expires_at >= CURDATE())
          AND (a.starts_at IS NULL OR a.starts_at <= CURDATE())
          AND NOT EXISTS (
              SELECT 1 FROM announcement_reads r
              WHERE r.announcement_id = a.id AND r.user_id = ?
          )
    ");
    $_ar->bind_param('i', $_SESSION['user_id']);
    $_ar->execute();
    $_ar->bind_result($_unread_ann);
    $_ar->fetch();
    $_ar->close();
}

$stmt_unpaid = $conn->prepare("SELECT COUNT(*) AS unpaid_count FROM orders WHERE status='PendingPayment' AND business_date=?");
$stmt_unpaid->bind_param("s", $business_date);
$stmt_unpaid->execute();
$unpaid_count = $stmt_unpaid->get_result()->fetch_assoc()['unpaid_count'];

$paylater_result = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM orders WHERE payment_method='paylater' AND status IN ('Preparing','PendingPayment','Completed')");
$paylater_count  = (int)mysqli_fetch_assoc($paylater_result)['cnt'];

$unpaid_orders_result = mysqli_query($conn, "SELECT order_id, daily_order_no, customer_name, total, status, payment_method, order_date, is_open, token_number FROM orders WHERE status='PendingPayment' ORDER BY order_date DESC LIMIT 5");

$paid_open_result = mysqli_query($conn, "SELECT order_id, daily_order_no, customer_name, total, status, payment_method, order_date, is_open, token_number FROM orders WHERE status='Preparing' AND is_open=0 ORDER BY order_date DESC LIMIT 5");

$refund_result = mysqli_query($conn, "SELECT IFNULL(SUM(refund_amount),0) AS total_refunds, COUNT(*) AS refund_count FROM order_refunds WHERE DATE(refunded_at)=CURDATE()");
$refund_data   = mysqli_fetch_assoc($refund_result);
$total_refunds = $refund_data['total_refunds'];
$refund_count  = $refund_data['refund_count'];

$stmt_status = $conn->prepare("SELECT status, COUNT(*) as count FROM orders WHERE business_date=? GROUP BY status");
$stmt_status->bind_param("s", $business_date);
$stmt_status->execute();
$status_result = $stmt_status->get_result();
$status_counts = [];
while ($row = mysqli_fetch_assoc($status_result)) { $status_counts[$row['status']] = $row['count']; }
$pending_count   = $status_counts['PendingPayment'] ?? 0;
$preparing_count = $status_counts['Preparing']      ?? 0;
$completed_count = $status_counts['Completed']      ?? 0;
$cancelled_count = $status_counts['Cancelled']      ?? 0;

// product_id <> 0 drops loyalty redemptions, matching cogs_cups() on the daily
// report this card now links to. Without it the dashboard read 92 items on
// 1 June while the report it opens read 70 cups for the same day.
$stmt_items = $conn->prepare("SELECT IFNULL(SUM(oi.quantity),0) AS total_items FROM order_items oi JOIN orders o ON oi.order_id=o.order_id WHERE o.business_date=? AND oi.product_id <> 0 AND " . paid_orders_where('o'));
$stmt_items->bind_param("s", $business_date);
$stmt_items->execute();
$items_sold = $stmt_items->get_result()->fetch_assoc()['total_items'];

$stmt_kitchen = $conn->prepare("SELECT order_id, daily_order_no, customer_name, total, order_date, token_number FROM orders WHERE business_date=? AND status='Preparing' ORDER BY order_date ASC LIMIT 8");
$stmt_kitchen->bind_param("s", $business_date);
$stmt_kitchen->execute();
$kitchen_result = $stmt_kitchen->get_result();

$stmt_recent = $conn->prepare("SELECT order_id, daily_order_no, customer_name, total, status, order_date FROM orders WHERE business_date=? ORDER BY order_date DESC LIMIT 20");
$stmt_recent->bind_param("s", $business_date);
$stmt_recent->execute();
$recent_orders = $stmt_recent->get_result();

$top_selling_result = mysqli_query($conn, "SELECT p.name, p.image, SUM(oi.quantity) as total_sold, p.price FROM products p JOIN order_items oi ON p.product_id=oi.product_id JOIN orders o ON oi.order_id=o.order_id WHERE " . paid_orders_where('o') . " GROUP BY p.product_id ORDER BY total_sold DESC LIMIT 5");

$activity_result = mysqli_query($conn, "SELECT * FROM (SELECT 'order' as type, order_id as ref_id, customer_name as name, total as amount, status, order_date as date FROM orders UNION ALL SELECT 'stock' as type, ingredient_id as ref_id, ingredient_name as name, purchase_qty as amount, 'restocked' as status, NULL as date FROM ingredients) as activity ORDER BY date DESC LIMIT 5");

$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
if ($filter_status) {
    $stmt_filter = $conn->prepare("SELECT order_id, daily_order_no, customer_name, total, status, order_date FROM orders WHERE business_date=? AND status=? ORDER BY order_date DESC LIMIT 20");
    $stmt_filter->bind_param("ss", $business_date, $filter_status);
    $stmt_filter->execute();
    $recent_orders = $stmt_filter->get_result();
}

// Flash toasts — only show once (right after login), then clear
$_flash_welcome     = !empty($_SESSION['flash_welcome']);     unset($_SESSION['flash_welcome']);
$_flash_stock_alert = !empty($_SESSION['flash_stock_alert']); unset($_SESSION['flash_stock_alert']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* ── TOKENS ── */
:root {
    --bg:           #0b0b0b;
    --surface:      #111111;
    --surface-2:    #181818;
    --glass:        rgba(255,255,255,0.04);
    --glass-hi:     rgba(255,255,255,0.07);
    --border:       #1f1f1f;
    --border-hi:    #2a2a2a;

    --amber:        #d1904b;
    --amber-light:  #e8b87a;
    --amber-dim:    rgba(209,144,75,0.15);
    --amber-glow:   rgba(209,144,75,0.25);

    --emerald:      #55e087;
    --emerald-dim:  rgba(85,224,135,0.13);
    --blue:         #3498db;
    --blue-dim:     rgba(52,152,219,0.13);
    --red:          #ff6b6b;
    --red-dim:      rgba(255,107,107,0.13);
    --purple:       #9b59b6;
    --purple-dim:   rgba(155,89,182,0.13);

    --accent:       var(--amber);
    --success:      var(--emerald);
    --warning:      var(--amber);
    --danger:       var(--red);

    --text:         #f5f5f5;
    --text-muted:   #888888;
    --text-xs:      #444444;

    --sidebar-w:    242px;
    --r:            14px;
    --r-sm:         10px;
    --r-xs:         7px;
    --shadow:       0 4px 24px rgba(0,0,0,.45);
    --shadow-lg:    0 8px 40px rgba(0,0,0,.65);
    --ease:         cubic-bezier(.4,0,.2,1);
    --spring:       cubic-bezier(.34,1.56,.64,1);
}

[data-theme="light"] {
    --bg:        #ECEEF2;
    --surface:   #FFFFFF;
    --surface-2: #F5F7FA;
    --glass:     rgba(255,255,255,.90);
    --glass-hi:  rgba(255,255,255,.98);
    --border:    #E2E5EA;
    --border-hi: #CDD0D8;
    --text:      #111827;
    --text-muted:#5A6373;
    --text-xs:   #9CA3AF;
    --shadow:    0 1px 3px rgba(0,0,0,.07), 0 4px 14px rgba(0,0,0,.06);
    --shadow-lg: 0 4px 20px rgba(0,0,0,.09), 0 1px 4px rgba(0,0,0,.05);
}
[data-theme="light"] body {
    -webkit-font-smoothing: subpixel-antialiased;
    -moz-osx-font-smoothing: auto;
}
/* crisp sidebar border in light mode */
[data-theme="light"] .sidebar {
    border-right-color: #D8DCE3;
    box-shadow: 2px 0 12px rgba(0,0,0,.05);
}
/* cards need a resting shadow in light mode so they lift off the page */
[data-theme="light"] .kpi-card,
[data-theme="light"] .panel {
    box-shadow: 0 1px 3px rgba(0,0,0,.06), 0 4px 14px rgba(0,0,0,.05);
    background: #FFFFFF;
    border-color: #E2E5EA;
}
[data-theme="light"] .kpi-card:hover {
    box-shadow: 0 4px 20px rgba(0,0,0,.10), 0 1px 4px rgba(0,0,0,.06);
    transform: translateY(-2px);
}

/* ── RESET ── */
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html{background:var(--bg);scroll-behavior:smooth;}
body{
    font-family:'Poppins',sans-serif;
    background:var(--bg);
    color:var(--text);
    min-height:100vh;
    overflow-x:hidden;
    line-height:1.5;
    -webkit-font-smoothing:antialiased;
}

/* ambient glow behind main area */
body::before{
    content:'';
    position:fixed;
    top:-160px;
    left:var(--sidebar-w);
    right:0;
    height:480px;
    background:radial-gradient(ellipse at 55% 0%, rgba(209,144,75,.045) 0%, transparent 68%);
    pointer-events:none;
    z-index:0;
}

a{color:inherit;text-decoration:none;}
img{display:block;}
button{font-family:inherit;cursor:pointer;}

/* ── LAYOUT ── */
.layout{display:flex;min-height:100vh;}

.main{
    margin-left:var(--sidebar-w);
    flex:1;
    padding:30px 36px 48px;
    position:relative;
    z-index:1;
    min-width:0;
}

/* collapses the reserved sidebar width for roles without a sidebar */
body.no-sidebar{--sidebar-w:0px;}

/* ── SIDEBAR ── */
.sidebar{
    position:fixed;
    inset:0 auto 0 0;
    width:var(--sidebar-w);
    background:var(--surface);
    border-right:1px solid var(--border);
    display:flex;
    flex-direction:column;
    z-index:100;
    overflow-y:auto;
    overflow-x:hidden;
    scrollbar-width:none;
    transition:transform .3s var(--ease);
}
.sidebar::-webkit-scrollbar{display:none;}

.sidebar-profile{
    display:flex;
    align-items:center;
    gap:10px;
    padding:14px;
    margin:12px 12px 4px;
    border-radius:var(--r-sm);
    background:var(--glass);
    border:1px solid var(--border);
    color:var(--text);
    transition:.2s var(--ease);
}
.sidebar-profile:hover{background:var(--glass-hi);border-color:var(--border-hi);}

.profile-avatar{
    width:34px;height:34px;
    background:var(--amber-dim);
    border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    color:var(--amber);font-size:14px;flex-shrink:0;
}
.profile-info{flex:1;min-width:0;}
.profile-name{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.profile-role{
    display:flex;align-items:center;gap:5px;
    font-size:10.5px;font-weight:500;
    color:var(--text-muted);
    margin-top:2px;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.profile-role::before{
    content:'';flex-shrink:0;
    width:6px;height:6px;border-radius:50%;
    background:var(--role-color,var(--amber));
    box-shadow:0 0 6px var(--role-color,var(--amber));
}
.sidebar-clock{font-size:11px;font-weight:700;color:var(--amber);letter-spacing:.06em;flex-shrink:0;}

.sidebar-header{
    display:flex;align-items:center;gap:9px;
    padding:10px 20px 14px;
}
.sidebar-header i{color:var(--amber);font-size:17px;}
.sidebar-header h2{font-size:16px;font-weight:800;color:var(--text);letter-spacing:-.02em;}

.sidebar-quick-btn{
    display:flex;align-items:center;gap:8px;
    margin:0 12px 6px;
    padding:9px 15px;
    background:var(--amber);
    color:#000;
    border-radius:var(--r-sm);
    font-size:12px;font-weight:700;
    transition:.2s var(--ease);
    box-shadow:0 2px 14px var(--amber-glow);
}
.sidebar-quick-btn:hover{background:var(--amber-light);transform:translateY(-1px);box-shadow:0 4px 18px var(--amber-glow);}

.sidebar-nav{flex:1;padding:6px 12px;}

.nav-group-label{
    font-size:11px;font-weight:700;
    letter-spacing:.08em;text-transform:uppercase;
    color:var(--text-muted);
    padding:14px 10px 6px;
    opacity:.85;
    display:flex;align-items:center;justify-content:space-between;
    cursor:pointer;user-select:none;
    border-radius:var(--r-xs);
    transition:opacity .2s,color .2s;
}
.nav-group-label:hover{opacity:1;color:var(--text);}
.nav-group-label.open{opacity:1;color:var(--amber);}
.nav-chevron{font-size:9px;flex-shrink:0;margin-left:4px;transition:transform .28s var(--ease);}
.nav-group-label.open .nav-chevron{transform:rotate(90deg);}
.nav-group-items{overflow:hidden;max-height:400px;transition:max-height .32s var(--ease),opacity .22s var(--ease);opacity:1;}
.nav-group-items.collapsed{max-height:0!important;opacity:0;pointer-events:none;}

.nav-item{
    display:flex;align-items:center;gap:10px;
    padding:9px 12px;
    border-radius:var(--r-sm);
    color:var(--text-muted);
    font-size:13.5px;font-weight:500;
    transition:background .18s var(--ease),color .18s var(--ease),box-shadow .18s var(--ease),border-color .18s var(--ease);
    margin-bottom:2px;
    border:1px solid transparent;
    position:relative;
}
.nav-item:hover{
    background:linear-gradient(90deg,rgba(209,144,75,.13) 0%,rgba(209,144,75,.04) 100%);
    color:var(--amber);
    border-color:rgba(209,144,75,.22);
    box-shadow:0 2px 16px rgba(209,144,75,.18),inset 0 0 16px rgba(209,144,75,.05);
}
.nav-item.active{
    background:linear-gradient(90deg,rgba(209,144,75,.18) 0%,rgba(209,144,75,.06) 100%);
    color:var(--amber);
    font-weight:600;
    border-color:rgba(209,144,75,.3);
    box-shadow:0 2px 20px rgba(209,144,75,.25),inset 0 0 20px rgba(209,144,75,.07);
}
.nav-item.active i,.nav-item:hover i{color:inherit;}
.nav-item i{width:16px;text-align:center;font-size:13px;flex-shrink:0;}
.nav-label{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}

.order-badge{
    margin-left:auto;flex-shrink:0;
    background:var(--amber);color:#000;
    font-size:9.5px;font-weight:800;
    padding:1px 7px;border-radius:50px;
    min-width:18px;text-align:center;
}

.sidebar-footer{padding:10px 12px;border-top:1px solid var(--border);}
.sidebar-stats{display:flex;gap:6px;margin-bottom:8px;}
.stat-pill{
    flex:1;display:flex;align-items:center;gap:5px;
    background:var(--glass);border:1px solid var(--border);
    border-radius:var(--r-xs);padding:5px 8px;
    font-size:10.5px;color:var(--text-muted);overflow:hidden;
}
.stat-pill i{color:var(--amber);font-size:9px;flex-shrink:0;}
.stat-pill span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}

.nav-logout{color:var(--red) !important;border-color:transparent !important;}
.nav-logout:hover{
    background:linear-gradient(90deg,rgba(255,107,107,.13) 0%,rgba(255,107,107,.04) 100%) !important;
    color:var(--red) !important;
    border-color:rgba(255,107,107,.22) !important;
    box-shadow:0 2px 16px rgba(255,107,107,.18),inset 0 0 16px rgba(255,107,107,.05) !important;
}

/* ── TOPBAR ── */
.dash-header{
    display:flex;align-items:center;justify-content:space-between;
    margin-bottom:22px;
    gap:16px;
}
.dash-header h1{
    font-size:24px;font-weight:800;
    letter-spacing:-.03em;line-height:1.2;
}
.dash-header h1 .name{
    background:linear-gradient(120deg,var(--amber) 0%,var(--amber-light) 100%);
    -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
}
.header-sub{font-size:12px;color:var(--text-muted);margin-top:3px;display:flex;align-items:center;gap:5px;}

.theme-toggle{
    display:flex;align-items:center;gap:7px;
    background:var(--glass);border:1px solid var(--border);
    color:var(--text-muted);
    padding:8px 14px;border-radius:50px;
    font-size:12px;
    transition:.15s var(--ease);
    flex-shrink:0;
}
.theme-toggle:hover{border-color:var(--border-hi);color:var(--text);}

.header-actions{display:flex;align-items:center;gap:10px;flex-shrink:0;}

.role-badge{
    display:inline-flex;align-items:center;gap:8px;
    background:var(--glass);border:1px solid var(--border);
    border-radius:50px;padding:6px 18px;margin-left:10px;
    font-size:14px;font-weight:600;color:var(--text);
    letter-spacing:.02em;
    vertical-align:middle;
}
.role-badge::before{
    content:'';flex-shrink:0;
    width:9px;height:9px;border-radius:50%;
    background:var(--role-color,var(--amber));
    box-shadow:0 0 9px var(--role-color,var(--amber));
}

.logout-btn{
    display:flex;align-items:center;gap:7px;
    background:var(--glass);border:1px solid var(--border);
    color:var(--text-muted);
    padding:8px 14px;border-radius:50px;
    font-size:12px;font-weight:600;
    transition:.15s var(--ease);
    flex-shrink:0;
}
.logout-btn:hover{background:var(--red-dim);border-color:rgba(255,107,107,.35);color:var(--red);}

/* ── ALERT STRIP ── */
.alert-strip{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:20px;}
.alert-pill{
    display:inline-flex;align-items:center;gap:7px;
    padding:7px 14px;border-radius:50px;
    font-size:12px;font-weight:600;
    border:1px solid transparent;
    transition:.15s var(--ease);
}
.alert-pill.danger{background:var(--red-dim);color:var(--red);border-color:rgba(239,68,68,.22);}
.alert-pill.danger:hover{background:rgba(239,68,68,.22);}
.alert-pill.warning{background:var(--amber-dim);color:var(--amber);border-color:rgba(245,158,11,.22);}
.alert-pill.warning:hover{background:rgba(245,158,11,.22);}

/* ── KPI ROW ── */
.kpi-row{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:16px;
    margin-bottom:18px;
}

.kpi-card{
    background:var(--glass);
    border:1px solid var(--border);
    border-radius:var(--r);
    padding:22px 22px 18px;
    position:relative;overflow:hidden;
    transition:.22s var(--ease);
}
.kpi-card:hover{
    border-color:var(--border-hi);
    background:var(--glass-hi);
    transform:translateY(-3px);
    box-shadow:var(--shadow);
}

/* left accent bar */
.kpi-card::before{
    content:'';
    position:absolute;
    top:20%;bottom:20%;left:0;
    width:3px;border-radius:0 3px 3px 0;
    background:var(--kc,var(--amber));
}
/* corner glow */
.kpi-card::after{
    content:'';
    position:absolute;
    top:-40px;right:-40px;
    width:130px;height:130px;
    background:radial-gradient(circle,var(--kg,var(--amber-dim)) 0%,transparent 70%);
    pointer-events:none;
}

.kpi-card.c-amber { --kc:var(--amber);   --kg:var(--amber-dim);   }
.kpi-card.c-green { --kc:var(--emerald); --kg:var(--emerald-dim); }
.kpi-card.c-blue  { --kc:var(--blue);    --kg:var(--blue-dim);    }

/* KPI cards are links to the records behind the number — the figure is never a
   dead end, you can always open the orders it was computed from. */
a.kpi-card { display:block; text-decoration:none; color:inherit; cursor:pointer; }
.kpi-drill{
    position:absolute; right:16px; bottom:14px;
    font-size:11px; font-weight:600; color:var(--kc,var(--amber));
    opacity:0; transform:translateX(-4px);
    transition:.22s var(--ease); pointer-events:none;
}
a.kpi-card:hover .kpi-drill{ opacity:.95; transform:translateX(0); }
@media (hover:none){ .kpi-drill{ opacity:.7; transform:none; } }

.kpi-watermark{
    position:absolute;right:16px;bottom:10px;
    font-size:54px;color:var(--kc,var(--amber));
    opacity:.07;line-height:1;pointer-events:none;
}

.kpi-label{
    font-size:11px;font-weight:700;
    text-transform:uppercase;letter-spacing:.09em;
    color:var(--text-muted);margin-bottom:9px;
}
.kpi-value{
    font-size:36px;font-weight:800;
    letter-spacing:-.03em;line-height:1;
    color:var(--text);margin-bottom:13px;
}
.kpi-pill{
    display:inline-flex;align-items:center;gap:5px;
    font-size:11px;font-weight:600;
    padding:3px 10px;border-radius:50px;
}
.kpi-pill.up  {background:var(--emerald-dim);color:var(--emerald);}
.kpi-pill.down{background:var(--red-dim);    color:var(--red);}
.kpi-pill.flat{background:var(--glass);      color:var(--text-muted);}

/* ── MID GRID ── */
.mid-grid{
    display:grid;
    grid-template-columns:1fr 330px;
    gap:16px;
    margin-bottom:18px;
    align-items:start;
}

/* ── PANELS ── */
.panel{
    background:var(--glass);
    border:1px solid var(--border);
    border-radius:var(--r);
    overflow:hidden;
}

.panel-head{
    display:flex;align-items:center;justify-content:space-between;
    padding:15px 20px;
    border-bottom:1px solid var(--border);
}
.panel-head h3{
    font-size:13px;font-weight:700;
    color:var(--text);
    display:flex;align-items:center;gap:8px;
}
.panel-head h3 i{color:var(--amber);font-size:13px;}

.panel-link{
    font-size:11px;color:var(--text-muted);
    display:flex;align-items:center;gap:4px;
    transition:.15s;
}
.panel-link:hover{color:var(--amber);}

.cnt-badge{
    font-size:11px;font-weight:700;
    padding:2px 10px;border-radius:50px;
    background:var(--glass);border:1px solid var(--border);
    color:var(--text-muted);
}
.cnt-badge.on{background:var(--emerald-dim);color:var(--emerald);border-color:rgba(16,185,129,.25);}

.live-dot{
    width:7px;height:7px;background:var(--emerald);
    border-radius:50%;display:inline-block;flex-shrink:0;
    animation:pdot 2.2s ease-in-out infinite;
}
@keyframes pdot{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.45;transform:scale(.75);}}

/* ── PREMIUM SCROLLBAR ── */
.kitchen-body::-webkit-scrollbar,
.orders-tbl tbody::-webkit-scrollbar { width:4px; }
.kitchen-body::-webkit-scrollbar-track,
.orders-tbl tbody::-webkit-scrollbar-track { background:transparent; }
.kitchen-body::-webkit-scrollbar-thumb,
.orders-tbl tbody::-webkit-scrollbar-thumb {
    background:linear-gradient(180deg,var(--amber),rgba(209,144,75,.35));
    border-radius:99px;
}
.kitchen-body::-webkit-scrollbar-thumb:hover,
.orders-tbl tbody::-webkit-scrollbar-thumb:hover { background:var(--amber-light); }

/* ── KITCHEN ITEMS ── */
.kitchen-body{
    padding:4px 0;
    max-height:245px;
    overflow-y:auto;
    scrollbar-width:thin;
    scrollbar-color:rgba(209,144,75,.35) transparent;
}

.k-item{
    display:flex;align-items:center;gap:12px;
    padding:11px 20px;
    border-bottom:1px solid var(--border);
    transition:.15s var(--ease);
}
.k-item:last-child{border-bottom:none;}
.k-item:hover{background:var(--glass);}

.k-no{
    font-size:14px;font-weight:800;
    color:var(--amber);min-width:42px;
    letter-spacing:-.01em;
}
.k-name{
    flex:1;font-size:13px;font-weight:500;
    color:var(--text);
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.k-total{font-size:13px;font-weight:600;color:var(--text);white-space:nowrap;}

.k-timer{
    font-size:10.5px;font-weight:700;
    padding:3px 9px;border-radius:50px;
    min-width:44px;text-align:center;white-space:nowrap;
}
.k-timer.ok    {background:var(--emerald-dim);color:var(--emerald);}
.k-timer.warn  {background:var(--amber-dim);  color:var(--amber);}
.k-timer.urgent{background:var(--red-dim);    color:var(--red);}

.btn-ready{
    display:inline-flex;align-items:center;gap:5px;
    background:var(--amber);color:#000;
    font-size:11px;font-weight:700;
    padding:6px 11px;border-radius:var(--r-xs);
    transition:.15s var(--ease);flex-shrink:0;
}
.btn-ready:hover{background:var(--amber-light);transform:scale(1.05);}
.k-status-pill{
    display:inline-flex;align-items:center;gap:5px;
    background:rgba(209,144,75,.1);color:var(--amber);
    border:1px solid rgba(209,144,75,.3);
    font-size:11px;font-weight:600;
    padding:5px 11px;border-radius:var(--r-xs);flex-shrink:0;
}

.k-empty{
    display:flex;flex-direction:column;align-items:center;
    gap:9px;padding:40px;color:var(--text-muted);
}
.k-empty i{font-size:26px;color:var(--emerald);opacity:.55;}
.k-empty span{font-size:13px;}

.panel-foot{
    padding:7px 20px;
    border-top:1px solid var(--border);
    display:flex;justify-content:space-between;align-items:center;
    font-size:11px;color:var(--text-muted);
}
.refresh-btn{
    background:var(--glass);border:1px solid var(--border);
    color:var(--text-muted);padding:4px 10px;
    border-radius:var(--r-xs);font-size:11px;
    transition:.15s var(--ease);
}
.refresh-btn:hover{color:var(--text);border-color:var(--border-hi);}

/* ── TOP SELLERS ── */
.sellers-body{padding:4px 0;}
.seller-row{
    display:flex;align-items:center;gap:11px;
    padding:9px 20px;
    transition:.15s var(--ease);
}
.seller-row:hover{background:var(--glass);}

.s-rank{
    font-size:11px;font-weight:800;
    color:var(--text-muted);
    min-width:18px;text-align:center;
}
.s-rank.gold{color:var(--amber);}

.s-img{
    width:38px;height:38px;
    border-radius:var(--r-xs);
    object-fit:cover;
    background:var(--glass);
    border:1px solid var(--border);
    flex-shrink:0;
}

.s-info{flex:1;min-width:0;}
.s-name{
    font-size:12px;font-weight:600;color:var(--text);
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.s-track{height:3px;background:var(--border);border-radius:2px;margin-top:5px;}
.s-bar{height:100%;background:var(--amber);border-radius:2px;transition:width .9s var(--ease);}

.s-count{
    font-size:12px;font-weight:700;
    color:var(--text-muted);
    min-width:36px;text-align:right;
}

/* ── ORDERS TABLE ── */
.orders-tbl{width:100%;border-collapse:collapse;table-layout:fixed;}
.orders-tbl thead{display:table;width:100%;table-layout:fixed;}
.orders-tbl tbody{
    display:block;
    max-height:255px;
    overflow-y:auto;
    scrollbar-width:thin;
    scrollbar-color:rgba(209,144,75,.35) transparent;
}
.orders-tbl tbody tr{display:table;width:100%;table-layout:fixed;}
.orders-tbl th{
    font-size:10.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.08em;
    color:var(--text-muted);
    padding:10px 20px;text-align:left;
    border-bottom:1px solid var(--border);
}
.orders-tbl td{
    padding:12px 20px;font-size:13px;
    color:var(--text);
    border-bottom:1px solid var(--border);
    vertical-align:middle;
}
.orders-tbl tr:last-child td{border-bottom:none;}
.orders-tbl tbody tr{transition:.15s var(--ease);}
.orders-tbl tbody tr:hover{background:var(--glass);}

.o-no{font-size:13px;font-weight:800;color:var(--amber);font-variant-numeric:tabular-nums;}

.cust-cell{display:flex;align-items:center;gap:8px;}
.cust-av{
    width:27px;height:27px;flex-shrink:0;
    background:var(--glass);border:1px solid var(--border);
    border-radius:50%;display:flex;align-items:center;justify-content:center;
    font-size:10px;color:var(--text-muted);
}

/* status badges */
.badge{
    display:inline-flex;align-items:center;gap:4px;
    font-size:10.5px;font-weight:700;
    padding:3px 9px;border-radius:50px;white-space:nowrap;
}
.badge.completed,.badge.paid       {background:var(--emerald-dim);color:var(--emerald);}
.badge.preparing                   {background:var(--amber-dim);  color:var(--amber);}
.badge.pendingpayment,.badge.pending{background:var(--purple-dim); color:var(--purple);}
.badge.cancelled                   {background:var(--red-dim);    color:var(--red);}
.badge.refunded                    {background:var(--blue-dim);   color:var(--blue);}

.tbl-empty{text-align:center;padding:46px;}
.tbl-empty i{font-size:26px;display:block;margin-bottom:10px;color:var(--text-muted);opacity:.4;}
.tbl-empty span{font-size:13px;color:var(--text-muted);}

/* ── QUICK ACCESS GRID (non-admin dashboard) ── */
.qa-grid{display:flex;flex-direction:column;gap:30px;max-width:1280px;width:100%;margin:0 auto;}
.qa-group{display:flex;flex-direction:column;gap:16px;}
.qa-group-label{
    font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;
    color:var(--text-muted);display:flex;align-items:center;gap:7px;
    padding-bottom:8px;border-bottom:1px solid var(--border);
}
.qa-group-label i{color:var(--accent);font-size:13px;}
.qa-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:18px;}
.qa-tile{
    position:relative;
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    gap:18px;padding:34px 24px;
    background:var(--surface);border:1px solid var(--border);
    border-radius:calc(var(--r) + 4px);color:var(--text);text-decoration:none;
    font-size:16px;font-weight:600;min-height:158px;
    box-shadow:0 1px 2px rgba(0,0,0,.18);
    transition:background .2s var(--ease),border-color .2s var(--ease),transform .12s var(--ease),box-shadow .2s var(--ease);
    -webkit-tap-highlight-color:transparent;
}
.qa-tile:hover{background:var(--surface-2);border-color:var(--border-hi);transform:translateY(-3px);box-shadow:0 10px 28px rgba(0,0,0,.32);}
.qa-tile:active{transform:scale(.97);filter:brightness(1.08);}
.qa-tile i{
    font-size:34px;color:var(--accent);
    width:78px;height:78px;flex:0 0 auto;
    display:flex;align-items:center;justify-content:center;
    border-radius:20px;background:var(--amber-glow);
    transition:transform .2s var(--spring);
}
.qa-tile:hover i{transform:scale(1.06);}
.qa-tile-badge{
    position:absolute;top:14px;right:14px;
    background:var(--purple);color:#fff;
    font-size:12px;font-weight:700;
    min-width:24px;height:24px;padding:0 7px;
    border-radius:50px;
    display:flex;align-items:center;justify-content:center;
    box-shadow:0 2px 8px rgba(0,0,0,.3);
}

.qa-hero-btn{
    display:flex;flex-direction:row;align-items:center;justify-content:center;
    gap:20px;padding:28px 40px;width:100%;
    background:linear-gradient(135deg,var(--amber-light) 0%,var(--amber) 100%);
    color:#000;text-decoration:none;
    border:1px solid rgba(255,255,255,.22);
    border-radius:calc(var(--r) + 8px);font-size:22px;font-weight:800;letter-spacing:.015em;
    min-height:104px;
    position:relative;overflow:hidden;
    box-shadow:0 6px 28px var(--amber-glow);
    transition:transform .25s var(--ease),box-shadow .3s var(--ease),filter .15s var(--ease);
    animation:heroGlow 2.8s ease-in-out infinite;
    -webkit-tap-highlight-color:transparent;
}
.qa-hero-btn:active{transform:scale(.99);filter:brightness(1.05);animation-play-state:paused;}
.qa-hero-btn::after{
    content:'';position:absolute;inset:0;
    background:linear-gradient(115deg,transparent 35%,rgba(255,255,255,.45) 50%,transparent 65%);
    background-size:240% 100%;background-position:160% 0;
    transition:background-position .8s ease;pointer-events:none;
}
.qa-hero-btn:hover::after{background-position:-60% 0;}
.qa-hero-btn i{font-size:30px;color:#000;width:58px;height:58px;flex:0 0 auto;display:flex;align-items:center;justify-content:center;border-radius:16px;background:rgba(0,0,0,.10);transition:transform .5s cubic-bezier(.34,1.56,.64,1);}
.qa-hero-btn:hover i{transform:rotate(180deg) scale(1.18);}
.qa-hero-btn:hover{transform:translateY(-3px) scale(1.02);box-shadow:0 14px 42px var(--amber-glow);animation-play-state:paused;}
@keyframes heroGlow{
    0%,100%{box-shadow:0 6px 28px var(--amber-glow),0 0 0 0 rgba(209,144,75,.35);}
    50%{box-shadow:0 6px 28px var(--amber-glow),0 0 0 14px rgba(209,144,75,0);}
}

/* ── REDESIGN: compact pro layout (cashier / inventory only) ── */
.qx-grid{display:flex;flex-direction:column;gap:16px;max-width:1180px;width:100%;margin:0 auto;}

.qx-hero{
    display:flex;align-items:center;justify-content:center;gap:12px;
    align-self:center;width:100%;max-width:320px;min-height:78px;padding:22px;
    background:linear-gradient(135deg,var(--amber-light) 0%,var(--amber) 100%);
    color:#000;text-decoration:none;
    border:1px solid rgba(255,255,255,.22);
    border-radius:var(--r);
    font-size:15px;font-weight:600;letter-spacing:.01em;
    box-shadow:0 4px 16px rgba(209,144,75,.3);
    -webkit-tap-highlight-color:transparent;
}
.qx-hero i{font-size:15px;width:30px;height:30px;flex:0 0 auto;display:flex;align-items:center;justify-content:center;border-radius:9px;background:rgba(0,0,0,.10);}

.qx-group{display:flex;flex-direction:column;gap:12px;}
.qx-group-label{
    font-size:12px;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;
    color:var(--text-muted);display:flex;align-items:center;gap:7px;
    padding-bottom:8px;border-bottom:1px solid var(--border);
}
.qx-group-label i{color:var(--accent);font-size:13px;}

.qx-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;}
.qx-tile{
    position:relative;
    display:flex;align-items:center;gap:14px;
    padding:18px 20px;min-height:96px;
    background:var(--surface);border:1px solid var(--border);
    border-radius:var(--r);color:var(--text);text-decoration:none;
    box-shadow:0 2px 8px rgba(0,0,0,.22);
    transition:background .2s var(--ease),border-color .2s var(--ease),transform .12s var(--ease),box-shadow .2s var(--ease);
    -webkit-tap-highlight-color:transparent;
}
.qx-tile:hover{background:var(--surface-2);border-color:var(--border-hi);transform:translateY(-2px);box-shadow:0 10px 26px rgba(0,0,0,.3);}
.qx-tile:active{transform:scale(.98);filter:brightness(1.06);}
.qx-tile i{
    font-size:22px;color:var(--accent);
    width:48px;height:48px;flex:0 0 auto;
    display:flex;align-items:center;justify-content:center;
    border-radius:13px;background:linear-gradient(135deg,var(--amber-dim) 0%,rgba(209,144,75,.28) 100%);
    transition:transform .2s var(--spring);
}
.qx-tile:hover i{transform:scale(1.06);}
.qx-tile span{font-size:15px;font-weight:600;}
.qx-tile-badge{
    position:absolute;top:12px;right:12px;
    background:var(--purple);color:#fff;
    font-size:11px;font-weight:700;
    min-width:22px;height:22px;padding:0 6px;border-radius:50px;
    display:flex;align-items:center;justify-content:center;
    box-shadow:0 2px 8px rgba(0,0,0,.3);
}

/* ── TOAST ── */
.toast-container{position:fixed;bottom:22px;right:22px;z-index:9999;display:flex;flex-direction:column;gap:9px;}
.toast{
    display:flex;align-items:center;gap:10px;
    background:var(--surface);border:1px solid var(--border);
    border-radius:var(--r-sm);
    padding:11px 15px;font-size:12.5px;color:var(--text);
    box-shadow:var(--shadow-lg);min-width:250px;
    animation:toastIn .3s var(--spring);
}
.toast.success{border-left:3px solid var(--emerald);}
.toast.error  {border-left:3px solid var(--red);}
.toast.success i{color:var(--emerald);}
.toast.error   i{color:var(--red);}
.close-toast{margin-left:auto;color:var(--text-muted);cursor:pointer;transition:.15s;}
.close-toast:hover{color:var(--text);}
@keyframes toastIn{from{opacity:0;transform:translateX(18px);}to{opacity:1;transform:translateX(0);}}

/* ── MOBILE ── */
.menu-toggle{
    display:none;position:fixed;top:14px;left:14px;z-index:200;
    background:var(--surface);border:1px solid var(--border);
    color:var(--text);width:40px;height:40px;
    border-radius:var(--r-sm);align-items:center;justify-content:center;
    font-size:15px;transition:.15s var(--ease);
}
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(4px);z-index:90;}
.overlay.active{display:block;}

/* ── LOGOUT BUTTON ── */
.logout-btn {
    display:inline-flex;align-items:center;gap:7px;
    padding:9px 16px;border-radius:10px;
    font-size:13px;font-family:'Poppins',sans-serif;font-weight:500;cursor:pointer;
    background:rgba(255,107,107,.08);border:1px solid rgba(255,107,107,.25);
    color:var(--red);transition:all .2s;
}
.logout-btn:hover{background:rgba(255,107,107,.14);border-color:rgba(255,107,107,.45);transform:translateY(-1px);}

/* ── ANIMATIONS ── */
.fu{animation:fu .45s var(--ease) both;}
@keyframes fu{from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:translateY(0);}}




/* ── RESPONSIVE ── */
@media(max-width:1100px){.mid-grid{grid-template-columns:1fr;}}
@media(max-width:820px) {.kpi-row{grid-template-columns:repeat(2,1fr);}}
@media(max-width:600px) {.kpi-row{grid-template-columns:1fr;}}
@media(max-width:768px){
    .sidebar{transform:translateX(-100%);}
    .sidebar.open{transform:translateX(0);}
    .menu-toggle{display:flex;}
    .main{margin-left:0;padding:68px 16px 32px;max-width:100vw;}
    .dash-header h1{font-size:19px;}
    .kpi-value{font-size:28px;}
    .qa-tiles{grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:14px;}
    .qa-tile{min-height:138px;padding:26px 16px;}
    .qa-tile i{width:64px;height:64px;font-size:28px;}
    .qa-hero-btn{font-size:19px;padding:24px 26px;min-height:90px;}
    .qx-tiles{grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;}
    .qx-tile{min-height:84px;padding:14px 16px;gap:12px;}
    .qx-tile i{width:42px;height:42px;font-size:20px;}
    .qx-hero{font-size:15px;min-height:54px;padding:14px 20px;max-width:none;}
}

/* ── INVENTORY-CLERK DASHBOARD (StockMate layout) ── */
.inv-shell{display:flex;gap:0;min-height:100vh}
.inv-sidebar{width:230px;flex:0 0 230px;background:var(--surface-2);border-right:1px solid var(--border);display:flex;flex-direction:column;padding:18px 14px;gap:6px}
.inv-brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:18px;color:var(--text);padding:6px 8px 14px}
.inv-brand i{color:var(--amber)}
.inv-nav{display:flex;flex-direction:column;gap:2px;flex:1}
.inv-navitem{display:flex;align-items:center;gap:12px;padding:11px 12px;border-radius:10px;color:var(--text-muted);text-decoration:none;font-size:14px;font-weight:500;transition:background .12s,color .12s}
.inv-navitem i{width:18px;text-align:center}
.inv-navitem:hover{background:var(--border);color:var(--text)}
.inv-navitem.active,.inv-navitem.active:hover{background:var(--amber);color:#1a1205;font-weight:700}
.inv-userchip{display:flex;align-items:center;gap:10px;padding:10px;border-radius:12px;border:1px solid var(--border);text-decoration:none;color:var(--text)}
.inv-avatar{width:36px;height:36px;border-radius:9px;background:var(--amber);color:#1a1205;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px}
.inv-uname{font-size:13px;font-weight:700;color:var(--text)}
.inv-urole{font-size:11px;color:var(--text-muted)}
.inv-main{flex:1;min-width:0;padding:14px 24px;display:flex;flex-direction:column;gap:12px}
.inv-header{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.inv-greet{font-size:22px;font-weight:800;color:var(--text);line-height:1.2}
.inv-greet span{color:var(--amber)}
.inv-date{font-size:13px;color:var(--text-muted);margin-top:2px}
.inv-hcluster{display:flex;align-items:center;gap:10px}
.inv-iconbtn{position:relative;width:38px;height:38px;border-radius:10px;border:1px solid var(--border);background:transparent;color:var(--text-muted);cursor:pointer;font-size:15px}
.inv-iconbtn:hover{color:var(--text);border-color:var(--border-hi)}
.inv-bellwrap{position:relative}
.inv-bellcount{position:absolute;top:-4px;right:-4px;background:var(--red);color:#fff;font-size:10px;font-weight:700;min-width:16px;height:16px;border-radius:8px;display:flex;align-items:center;justify-content:center;padding:0 4px}
.inv-clockbtn{display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border-radius:10px;border:1px solid #2e8b57;background:transparent;color:#3ecf8e;font-weight:600;font-size:13px;cursor:pointer}
.inv-clockbtn[data-clocked="1"]{border-color:var(--amber);color:var(--amber)}
.inv-logoutbtn{display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border-radius:10px;border:1px solid #a33;background:transparent;color:#ff6b6b;font-weight:600;font-size:13px;text-decoration:none}
.inv-notifpanel{position:absolute;top:46px;right:0;width:320px;max-height:420px;overflow:auto;background:var(--surface-2);border:1px solid var(--border-hi);border-radius:14px;box-shadow:0 12px 32px rgba(0,0,0,.4);display:none;z-index:60}
.inv-notifpanel.open{display:block}
.inv-notif-head{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid var(--border);font-weight:700;color:var(--text)}
.inv-notif-clear{border:none;background:transparent;color:var(--amber);font-size:12px;font-weight:600;cursor:pointer}
.inv-notif-item{display:flex;gap:10px;padding:12px 16px;border-bottom:1px solid var(--border)}
.inv-notif-msg{font-size:12px;color:var(--text-muted);margin-top:2px}
.inv-notif-foot{display:block;text-align:center;padding:12px;color:var(--amber);text-decoration:none;font-size:13px}
.inv-banner{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 18px;border-radius:12px;background:rgba(255,107,107,.09);border:1px solid rgba(255,107,107,.28);color:#ff9a9a;text-decoration:none;font-size:14px;font-weight:600}
.inv-banner-cta{color:#ff6b6b;white-space:nowrap}
[data-theme=light] .inv-avatar,[data-theme=light] .inv-navitem.active{color:#3a2600}
[data-theme=light] .inv-banner{background:rgba(214,58,58,.08);border-color:rgba(214,58,58,.32);color:#b91c1c}
[data-theme=light] .inv-banner-cta{color:#b91c1c}
body.inv-mode .main{padding:0;max-width:none;margin:0}
.inv-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
.inv-card{background:var(--surface-2);border:1px solid var(--border);border-radius:16px;padding:14px 18px}
.inv-card-ico{font-size:20px;margin-bottom:14px}
.inv-card-val{font-size:30px;font-weight:800;color:var(--text);font-variant-numeric:tabular-nums;line-height:1}
.inv-card-lbl{font-size:13px;color:var(--text-muted);margin-top:6px}
.inv-card-sub{font-size:12px;color:var(--text-muted);margin-top:8px}
.inv-body{display:grid;grid-template-columns:1fr 360px;gap:18px;align-items:start}
.inv-content{display:flex;flex-direction:column;gap:12px}
.inv-sec-label{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--amber);margin-top:2px}
.inv-tiles{display:flex;flex-direction:column;gap:8px}
.inv-tile{display:flex;align-items:center;gap:14px;padding:10px 16px;background:var(--surface-2);border:1px solid var(--border);border-radius:14px;text-decoration:none;transition:transform .12s,border-color .12s}
.inv-tile:hover{transform:translateY(-1px);border-color:var(--border-hi)}
.inv-tile-ico{flex:0 0 auto;width:38px;height:38px;border-radius:10px;background:rgba(255,255,255,.04);display:flex;align-items:center;justify-content:center;font-size:16px}
.inv-tile>span:nth-child(2){flex:1;display:flex;flex-direction:column;gap:3px}
.inv-tile-t{font-size:15px;font-weight:700;color:var(--text)}
.inv-tile-d{font-size:12px;color:var(--text-muted)}
.inv-tile-arw{color:var(--text-muted);font-size:13px}
.inv-tile-badge{font-size:11px;font-weight:700;color:#ff9a3d;background:rgba(255,138,61,.15);padding:1px 7px;border-radius:8px;margin-left:4px}
.inv-tile-badge.count{color:var(--text-muted);background:rgba(255,255,255,.06)}
body.inv-mode .toast-container{top:20px;bottom:auto;right:20px}
.inv-rail{display:flex;flex-direction:column;gap:16px}
.inv-panel{background:var(--surface-2);border:1px solid var(--border);border-radius:16px;padding:16px}
.inv-panel-head{display:flex;align-items:center;justify-content:space-between;font-weight:700;color:var(--text);font-size:14px;margin-bottom:12px;flex-wrap:wrap;gap:8px}
.inv-filter{display:flex;align-items:center;gap:6px}
.inv-fbtn{border:none;background:transparent;color:var(--text-muted);font-size:12px;font-weight:600;padding:3px 9px;border-radius:7px;cursor:pointer}
.inv-fbtn.active{background:rgba(255,255,255,.08);color:var(--text)}
.inv-viewall{font-size:12px;color:var(--amber);text-decoration:none;margin-left:2px}
.inv-lslist,.inv-actlist{display:flex;flex-direction:column;gap:9px}
.inv-lsrow-top{display:flex;justify-content:space-between;font-size:13px}
.inv-lsname{font-weight:600;color:var(--text)}
.inv-lsqty{color:var(--text-muted)}
.inv-lsbar{height:5px;border-radius:3px;background:var(--border);margin:6px 0 4px;overflow:hidden}
.inv-lsbar span{display:block;height:100%;border-radius:3px}
.inv-lssub{font-size:11px;color:var(--text-muted)}
.inv-actrow{display:flex;gap:10px;align-items:flex-start}
.inv-actdot{flex:0 0 auto;width:8px;height:8px;border-radius:50%;margin-top:5px}
.inv-acttext{font-size:13px;color:var(--text)}
.inv-actago{font-size:11px;color:var(--text-muted);margin-top:2px}
.inv-empty{font-size:13px;color:var(--text-muted);padding:6px 0}
@media(max-width:1000px){
  .inv-body{grid-template-columns:1fr}
  .inv-stats{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:640px){
  .inv-sidebar{display:none}
  .inv-stats{grid-template-columns:1fr}
  .inv-main{padding:16px}
}
</style>
</head>
<?php
$_bodyClasses = [];
if (!$_is_mgr) { $_bodyClasses[] = 'no-sidebar'; }
if (($_SESSION['role'] ?? '') === 'inventory_clerk') { $_bodyClasses[] = 'inv-mode'; }
?>
<body<?= $_bodyClasses ? ' class="' . htmlspecialchars(implode(' ', $_bodyClasses)) . '"' : '' ?>>

<?php if ($_is_mgr): ?>
<button class="menu-toggle" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
<div class="overlay" onclick="toggleSidebar()"></div>
<?php endif; ?>
<div class="toast-container" id="toastContainer"></div>

<div class="layout">

<?php if ($_is_mgr): ?>
<!-- ═══ SIDEBAR ═══ -->
<div class="sidebar" id="sidebar">

    <?php if (can('my_profile')): ?>
    <a href="profile.php" class="sidebar-profile" title="My Profile">
    <?php else: ?>
    <div class="sidebar-profile" style="cursor:default">
    <?php endif; ?>
        <div class="profile-avatar"><?php $__ph = current_user_photo($conn); if ($__ph): ?><img src="<?= htmlspecialchars($__ph) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;display:block"><?php else: ?><i class="fa-solid fa-user"></i><?php endif; ?></div>
        <div class="profile-info">
            <div class="profile-name"><?= htmlspecialchars($admin_name) ?></div>
            <div class="profile-role" style="--role-color:<?= $_cur_role_color ?>;">
                <?= htmlspecialchars($_cur_role_name) ?>
            </div>
        </div>
        <div class="sidebar-clock" id="sidebarClock">--:--</div>
    <?php if (can('my_profile')): ?>
    </a>
    <?php else: ?>
    </div>
    <?php endif; ?>

    <div class="sidebar-header">
        <i class="fa-solid fa-mug-hot"></i>
        <h2>Bird's Nest</h2>
    </div>

    <?php if (can('find_orders')): ?>
    <a href="menu.php" class="sidebar-quick-btn">
        <i class="fa-solid fa-plus"></i> Take New Order
    </a>
    <?php endif; ?>

    <div class="sidebar-nav" id="sidebarNav">

        <!-- OVERVIEW — open by default (active page) -->
        <div class="nav-group-label open" onclick="toggleGroup(this)" data-group="overview">
            <span>Overview</span><i class="fa-solid fa-chevron-right nav-chevron"></i>
        </div>
        <div class="nav-group-items" id="grp-overview">
            <a class="nav-item active" href="dashboard.php">
                <i class="fa-solid fa-chart-pie"></i>
                <span class="nav-label">Dashboard</span>
                <?php if ($pending_count + $preparing_count > 0): $_active_n = $pending_count + $preparing_count; ?>
                <span class="order-badge" title="<?= $_active_n ?> active order<?= $_active_n != 1 ? 's' : '' ?> (unpaid or preparing)"><?= $_active_n ?></span>
                <?php endif; ?>
            </a>
        </div>

        <!-- ORDERS -->
        <?php if (can('find_orders') || can('view_orders')): ?>
        <div class="nav-group-label" onclick="toggleGroup(this)" data-group="orders">
            <span>Orders</span>
            <i class="fa-solid fa-chevron-right nav-chevron"></i>
        </div>
        <div class="nav-group-items collapsed" id="grp-orders">
            <?php if (can('find_orders')): ?>
            <a class="nav-item" href="find_order.php">
                <i class="fa-solid fa-magnifying-glass"></i>
                <span class="nav-label">Find Unpaid Orders</span>
                <?php if ($_SESSION['role'] === 'staff' && $paylater_count > 0): ?>
                <span class="order-badge" style="background:var(--purple);"><?= $paylater_count ?></span>
                <?php elseif ($unpaid_count > 0): ?>
                <span class="order-badge" style="background:var(--purple);"><?= $unpaid_count ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if (can('view_orders')): ?>
            <a class="nav-item" href="view_order.php">
                <i class="fa-solid fa-receipt"></i>
                <span class="nav-label">Orders</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- OPERATIONS -->
        <?php $_can_stands = in_array($_SESSION['role'] ?? '', ['admin','manager','staff'], true); ?>
        <?php if (can('barista_station') || can('customer_display') || $_can_stands): ?>
        <div class="nav-group-label" onclick="toggleGroup(this)" data-group="operations">
            <span>Operations</span><i class="fa-solid fa-chevron-right nav-chevron"></i>
        </div>
        <div class="nav-group-items collapsed" id="grp-operations">
            <?php if (can('barista_station')): ?>
            <a class="nav-item" href="barista_display.php">
                <i class="fa-solid fa-mug-hot"></i>
                <span class="nav-label">Barista Station</span>
            </a>
            <?php endif; ?>
            <?php if (can('customer_display')): ?>
            <a class="nav-item" href="customer_display.php">
                <i class="fa-solid fa-display"></i>
                <span class="nav-label">Customer Display</span>
            </a>
            <?php endif; ?>
            <?php if ($_can_stands): ?>
            <a class="nav-item" href="stands.php">
                <i class="fa-solid fa-table-cells-large"></i>
                <span class="nav-label">Stand Numbers</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- LOYALTY -->
        <?php if (can('loyalty')): ?>
        <div class="nav-group-label" onclick="toggleGroup(this)" data-group="loyalty">
            <span>Loyalty</span><i class="fa-solid fa-chevron-right nav-chevron"></i>
        </div>
        <div class="nav-group-items collapsed" id="grp-loyalty">
            <a class="nav-item" href="loyalty_dashboard.php">
                <i class="fa-solid fa-star"></i>
                <span class="nav-label">Loyalty Card</span>
            </a>
        </div>
        <?php endif; ?>

        <!-- INVENTORY -->
        <?php if (can('products') || can('ingredients') || can('recipes')): ?>
        <div class="nav-group-label" onclick="toggleGroup(this)" data-group="inventory">
            <span>Inventory</span>
            <i class="fa-solid fa-chevron-right nav-chevron"></i>
        </div>
        <div class="nav-group-items collapsed" id="grp-inventory">
            <?php if (can('products')): ?>
            <a class="nav-item" href="products.php">
                <i class="fa-solid fa-cube"></i>
                <span class="nav-label">Products</span>
            </a>
            <?php endif; ?>
            <?php if (can('ingredients')): ?>
            <a class="nav-item" href="ingredients.php">
                <i class="fa-solid fa-boxes-stacked"></i>
                <span class="nav-label">Ingredients</span>
                <?php if ($low_stock > 0): ?><span class="order-badge" style="background:var(--red);margin-left:auto"><?= $low_stock ?></span><?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if (can('recipes')): ?>
            <a class="nav-item" href="recipes_view.php">
                <i class="fa-solid fa-utensils"></i>
                <span class="nav-label">Drink Recipe</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- RECONCILIATION -->
        <?php if (can('cash_reconciliation') || can('stock_count')): ?>
        <div class="nav-group-label" onclick="toggleGroup(this)" data-group="reconciliation">
            <span>Reconciliation</span><i class="fa-solid fa-chevron-right nav-chevron"></i>
        </div>
        <div class="nav-group-items collapsed" id="grp-reconciliation">
            <?php if (can('cash_reconciliation')): ?>
            <a class="nav-item" href="reconciliation_report.php">
                <i class="fa-solid fa-cash-register"></i>
                <span class="nav-label">Cash Count</span>
                <?php if ($_recon_alerts > 0): ?><span class="order-badge" style="background:var(--red);margin-left:auto"><?= $_recon_alerts ?></span><?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if (can('stock_count')): ?>
            <a class="nav-item<?= basename($_SERVER['PHP_SELF']) === 'stock_count.php' ? ' active' : '' ?>" href="stock_count.php">
                <i class="fa-solid fa-clipboard-list"></i>
                <span class="nav-label">Stock Count</span>
            </a>
            <a class="nav-item<?= basename($_SERVER['PHP_SELF']) === 'inventory_count.php' ? ' active' : '' ?>" href="inventory_count.php">
                <i class="fa-solid fa-boxes-stacked"></i>
                <span class="nav-label">Inventory Count</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- PROCUREMENT -->
        <?php if (can('suppliers') || can('purchase_orders')): ?>
        <div class="nav-group-label" onclick="toggleGroup(this)" data-group="procurement">
            <span>Procurement</span><i class="fa-solid fa-chevron-right nav-chevron"></i>
        </div>
        <div class="nav-group-items collapsed" id="grp-procurement">
            <?php if (can('suppliers')): ?>
            <a class="nav-item" href="suppliers.php">
                <i class="fa-solid fa-truck-ramp-box"></i>
                <span class="nav-label">Suppliers</span>
            </a>
            <?php endif; ?>
            <?php if (can('purchase_orders')): ?>
            <a class="nav-item" href="purchase_orders.php">
                <i class="fa-solid fa-file-invoice"></i>
                <span class="nav-label">Purchase Orders</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ANALYTICS -->
        <?php if (can('report') || in_array($_SESSION['role'] ?? '', ['admin','manager'])): ?>
        <div class="nav-group-label" onclick="toggleGroup(this)" data-group="analytics">
            <span>Analytics</span><i class="fa-solid fa-chevron-right nav-chevron"></i>
        </div>
        <div class="nav-group-items collapsed" id="grp-analytics">
            <?php if (can('report')): ?>
            <a class="nav-item" href="daily_report.php">
                <i class="fa-solid fa-chart-simple"></i>
                <span class="nav-label">Daily Report</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- STAFF -->
        <div class="nav-group-label" onclick="toggleGroup(this)" data-group="staff">
            <span>Staff</span><i class="fa-solid fa-chevron-right nav-chevron"></i>
        </div>
        <div class="nav-group-items collapsed" id="grp-staff">
            <?php if (can('employees')): ?>
            <a class="nav-item" href="employees.php">
                <i class="fa-solid fa-user-tie"></i>
                <span class="nav-label">Employees</span>
            </a>
            <?php endif; ?>
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
            <a class="nav-item" href="manage_roles.php">
                <i class="fa-solid fa-shield-halved"></i>
                <span class="nav-label">Manage Roles</span>
            </a>
            <?php endif; ?>
            <?php if (can('reset_password')): ?>
            <a class="nav-item" href="admin_reset_password.php">
                <i class="fa-solid fa-key"></i>
                <span class="nav-label">Reset Password</span>
            </a>
            <?php endif; ?>
            <?php if (can('announcements')): ?>
            <a class="nav-item" href="announcements.php">
                <i class="fa-solid fa-bullhorn"></i>
                <span class="nav-label">Announcements</span>
                <?php if ($_unread_ann > 0): ?><span class="order-badge" style="background:var(--red);margin-left:auto"><?= $_unread_ann ?></span><?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if (can('attendance')): ?>
            <a class="nav-item" href="attendance.php">
                <i class="fa-solid fa-fingerprint"></i>
                <span class="nav-label">Attendance</span>
            </a>
            <?php endif; ?>
            <?php if (can('promotions')): ?>
            <a class="nav-item" href="settings.php">
                <i class="fa-solid fa-sliders"></i>
                <span class="nav-label">Promotions</span>
            </a>
            <?php endif; ?>
            <?php if (can('my_profile')): ?>
            <a class="nav-item" href="profile.php">
                <i class="fa-solid fa-circle-user"></i>
                <span class="nav-label">My Profile</span>
            </a>
            <?php endif; ?>
        </div>

    </div>

    <div class="sidebar-footer">
        <?php if ($_is_mgr): ?>
        <div class="sidebar-stats">
            <div class="stat-pill">
                <i class="fa-solid fa-dollar-sign"></i>
                <span>$<?= number_format($sales, 2) ?></span>
            </div>
            <div class="stat-pill">
                <i class="fa-solid fa-receipt"></i>
                <span><?= (int)$total_orders ?> orders</span>
            </div>
        </div>
        <?php endif; ?>
        <a class="nav-item nav-logout" href="shift_report.php">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span class="nav-label">Logout</span>
        </a>
    </div>

</div>
<!-- end sidebar -->
<?php endif; ?>

<!-- ═══ MAIN ═══ -->
<div class="main">

    <?php if (isset($_GET['denied'])): ?>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const t = document.createElement('div');
        t.className = 'toast error';
        t.innerHTML = '<i class="fa-solid fa-lock"></i><span>You don\'t have permission to access that page.</span><span class="close-toast" onclick="this.parentElement.remove()"><i class="fa-solid fa-xmark"></i></span>';
        document.getElementById('toastContainer').appendChild(t);
        setTimeout(() => { t.style.opacity='0'; t.style.transform='translateX(18px)'; setTimeout(()=>t.remove(),400); }, 4000);
    });
    </script>
    <?php endif; ?>

    <?php if (($_SESSION['role'] ?? '') !== 'inventory_clerk'): ?>
    <!-- HEADER -->
    <div class="dash-header fu" style="animation-delay:.0s">
        <div>
            <h1>
                Good <span id="timeOfDay">morning</span>, <span class="name"><?= htmlspecialchars($admin_name) ?></span>
                <?php if (!$_is_mgr): ?>
                <span class="role-badge" style="--role-color:<?= htmlspecialchars($_cur_role_color) ?>;"><?= htmlspecialchars($_cur_role_name) ?></span>
                <?php endif; ?>
            </h1>
            <p class="header-sub">
                <i class="fa-regular fa-calendar-days"></i>
                <?= date("l, d F Y") ?>
            </p>
        </div>
        <div class="header-actions">
            <button class="theme-toggle" onclick="toggleTheme()">
                <i class="fa-solid fa-moon" id="themeIcon"></i>
                <span id="themeText">Dark</span>
            </button>
            <?php if (!$_is_mgr): ?>
            <?php
            $clocked  = $_is_clocked_in;
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
                style="display:inline-flex;align-items:center;gap:7px;
                       padding:9px 16px;border-radius:10px;font-size:13px;font-family:'Poppins',sans-serif;font-weight:500;cursor:pointer;
                       background:<?= $clkBg ?>;border:1px solid <?= $clkBr ?>;color:<?= $clkColor ?>;transition:all .2s;">
                <i class="fa-solid fa-<?= $clkIcon ?>"></i> <?= $clkLabel ?>
            </button>
            <a href="shift_report.php" class="logout-btn" title="View shift report &amp; log out">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Logout</span>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- ALERT STRIP -->
    <?php if (($low_stock > 0 && can('ingredients')) || ($_is_mgr && $unpaid_count > 0 && can('find_orders'))): ?>
    <div class="alert-strip fu" style="animation-delay:.06s">
        <?php if ($low_stock > 0 && can('ingredients')): ?>
        <a href="ingredients.php" class="alert-pill danger">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <?= $low_stock ?> item<?= $low_stock != 1 ? 's' : '' ?> low on stock — restock needed
        </a>
        <?php endif; ?>
        <?php if ($_is_mgr && $unpaid_count > 0 && can('find_orders')): ?>
        <a href="find_order.php" class="alert-pill warning">
            <i class="fa-solid fa-clock"></i>
            <?= $unpaid_count ?> unpaid order<?= $unpaid_count != 1 ? 's' : '' ?> pending
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($_is_mgr): ?>
    <!-- KPI ROW -->
    <div class="kpi-row fu" style="animation-delay:.1s">

        <!-- Revenue → the day's report (payment breakdown + the orders it sums).
             daily_report.php, not report.php: the daily report is the Report
             destination everywhere else in the app, and report.php answers a
             different question ("Full analytics", reachable from inside it). -->
        <a href="daily_report.php?date=<?= urlencode($business_date) ?>" class="kpi-card c-amber" title="Open today's sales report">
            <i class="kpi-watermark fa-solid fa-dollar-sign"></i>
            <span class="kpi-drill">View report <i class="fa-solid fa-arrow-right"></i></span>
            <div class="kpi-label">Today's Revenue</div>
            <div class="kpi-value">$<span id="kpiRevenue"><?= number_format($sales, 2) ?></span></div>
            <?php if ($sales <= 0): ?>
            <span class="kpi-pill flat"><i class="fa-solid fa-hourglass-start"></i> No sales yet today</span>
            <?php elseif ($sales_trend != 0): ?>
            <span class="kpi-pill <?= $trend_class ?>">
                <i class="fa-solid <?= $trend_icon ?>"></i>
                <?= abs($sales_trend) ?>% vs yesterday
            </span>
            <?php else: ?>
            <span class="kpi-pill flat"><i class="fa-solid fa-minus"></i> No data yesterday</span>
            <?php endif; ?>
        </a>

        <!-- Orders → the order board holding these orders.
             ?tab=all is stated rather than left to the default: the board opens
             on whatever tab the role last implies, so a manager clicking a card
             headed "Orders Today" could land on a filtered, possibly empty list
             and think the link was broken. view_order.php reads ?tab= on load. -->
        <a href="view_order.php?tab=all" class="kpi-card c-green" title="Open the orders board">
            <i class="kpi-watermark fa-solid fa-receipt"></i>
            <span class="kpi-drill">View orders <i class="fa-solid fa-arrow-right"></i></span>
            <div class="kpi-label">Orders Today</div>
            <div class="kpi-value"><span id="kpiOrders"><?= (int)$total_orders ?></span></div>
            <span class="kpi-pill flat">
                <i class="fa-solid fa-circle-check"></i>
                <?= $completed_count ?> completed
            </span>
        </a>

        <!-- Items Sold → the day's report, which carries the best seller and the
             per-order cup counts. The old link went to report.php#kv-items, an
             anchor on a single inline <span> that scrolled nowhere useful. -->
        <a href="daily_report.php?date=<?= urlencode($business_date) ?>" class="kpi-card c-blue" title="Open the item breakdown">
            <i class="kpi-watermark fa-solid fa-mug-hot"></i>
            <span class="kpi-drill">View breakdown <i class="fa-solid fa-arrow-right"></i></span>
            <div class="kpi-label">Items Served</div>
            <div class="kpi-value"><span id="kpiItems"><?= (int)$items_sold ?></span></div>
            <span class="kpi-pill flat">
                <i class="fa-solid fa-box-open"></i>
                from completed orders
            </span>
        </a>

    </div>

    <!-- MID GRID -->
    <div class="mid-grid fu" style="animation-delay:.15s">

        <!-- KITCHEN QUEUE / ACTIVE ORDERS -->
        <div class="panel">
            <div class="panel-head">
                <h3>
                    <span class="live-dot"></span>
                    <i class="fa-solid fa-fire-burner"></i>
                    Active Orders
                </h3>
                <div style="display:flex;align-items:center;gap:8px">
                    <span class="cnt-badge <?= mysqli_num_rows($kitchen_result) > 0 ? 'on' : '' ?>" id="kitchenCount">
                        <?= mysqli_num_rows($kitchen_result) ?> preparing
                    </span>
                    <button class="refresh-btn" onclick="fetchDashboardData()">
                        <i class="fa-solid fa-rotate"></i>
                    </button>
                </div>
            </div>

            <div class="kitchen-body" id="kitchenList">
            <?php
            mysqli_data_seek($kitchen_result, 0);
            $krows = [];
            while ($kr = mysqli_fetch_assoc($kitchen_result)) { $krows[] = $kr; }
            if (count($krows) > 0):
                foreach ($krows as $kr):
                    $mins = floor((time() - strtotime($kr['order_date'])) / 60);
                    $_warn_at = max(1, (int)floor(OVERDUE_MINUTES * 0.7));
                    $tc   = $mins >= OVERDUE_MINUTES ? 'urgent' : ($mins >= $_warn_at ? 'warn' : 'ok');
            ?>
            <div class="k-item">
                <div class="k-no">#<?= (int)$kr['daily_order_no'] ?></div>
                <div class="k-name"><?= htmlspecialchars($kr['customer_name']) ?></div>
                <div class="k-total">$<?= number_format((float)$kr['total'], 2) ?></div>
                <div class="k-timer <?= $tc ?>"><?= $mins ?>m</div>
                <span class="k-status-pill">
                    <i class="fa-solid fa-fire-burner"></i> Preparing
                </span>
            </div>
            <?php endforeach; else: ?>
            <div class="k-empty">
                <i class="fa-solid fa-circle-check"></i>
                <span>All clear — no orders preparing</span>
            </div>
            <?php endif; ?>
            </div>

            <div class="panel-foot">
                <span>Auto-refreshes every 5 s</span>
                <span id="lastUpdated"><?= date("g:i A") ?></span>
            </div>
        </div>

        <!-- TOP SELLERS -->
        <div class="panel">
            <div class="panel-head">
                <h3><i class="fa-solid fa-trophy"></i> Top Sellers</h3>
                <span style="font-size:11px;color:var(--text-muted)">All time</span>
            </div>
            <div class="sellers-body">
            <?php
            $srows = [];
            while ($sr = mysqli_fetch_assoc($top_selling_result)) { $srows[] = $sr; }
            $maxs = count($srows) > 0 ? (int)max(array_column($srows, 'total_sold')) : 1;
            if (count($srows) > 0):
                foreach ($srows as $si => $sr):
                    $pct = $maxs > 0 ? round($sr['total_sold'] / $maxs * 100) : 0;
            ?>
            <div class="seller-row">
                <div class="s-rank <?= $si === 0 ? 'gold' : '' ?>"><?= $si + 1 ?></div>
                <img class="s-img"
                     src="<?= htmlspecialchars($sr['image']) ?>"
                     alt=""
                     onerror="this.style.visibility='hidden'">
                <div class="s-info">
                    <div class="s-name"><?= htmlspecialchars($sr['name']) ?></div>
                    <div class="s-track"><div class="s-bar" style="width:<?= $pct ?>%"></div></div>
                </div>
                <div class="s-count"><?= (int)$sr['total_sold'] ?></div>
            </div>
            <?php endforeach; else: ?>
            <div class="k-empty">
                <i class="fa-regular fa-chart-bar"></i>
                <span>No sales data yet</span>
            </div>
            <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- RECENT ORDERS -->
    <div class="panel fu" style="animation-delay:.2s">
        <div class="panel-head">
            <h3><i class="fa-solid fa-clock-rotate-left"></i> Recent Orders</h3>
            <div style="display:flex;gap:14px;align-items:center;">
                <?php if ($_is_mgr): ?>
                <a href="order_audit.php" class="panel-link" title="Who changed an order after it was placed">Change log <i class="fa-solid fa-shield-halved"></i></a>
                <?php endif; ?>
                <a href="view_order.php" class="panel-link">View all <i class="fa-solid fa-arrow-right"></i></a>
            </div>
        </div>
        <table class="orders-tbl">
            <thead>
                <tr>
                    <th style="width:72px">Order</th>
                    <th>Customer</th>
                    <th style="width:100px;text-align:right">Total</th>
                    <th style="width:155px">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (mysqli_num_rows($recent_orders) > 0): ?>
            <?php while ($ro = mysqli_fetch_assoc($recent_orders)):
                $sc = strtolower(str_replace(['payment',' '], '', $ro['status']));
                if (!in_array($sc, ['pending','paid','preparing','completed','cancelled','refunded','pendingpayment'])) $sc = 'pending';
            ?>
            <tr>
                <td><span class="o-no">#<?= (int)$ro['daily_order_no'] ?></span></td>
                <td>
                    <div class="cust-cell">
                        <div class="cust-av"><i class="fa-regular fa-user"></i></div>
                        <?= htmlspecialchars($ro['customer_name']) ?>
                    </div>
                </td>
                <td style="text-align:right;font-weight:700">$<?= number_format((float)$ro['total'], 2) ?></td>
                <td><span class="badge <?= $sc ?>"><?= htmlspecialchars($ro['status']) ?></span></td>
            </tr>
            <?php endwhile; ?>
            <?php else: ?>
            <tr><td colspan="4"><div class="tbl-empty">
                <i class="fa-regular fa-rectangle-list"></i>
                <span>No orders today yet</span>
            </div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php else: /* non-admin/manager: role-aware focus + quick-access tiles */ ?>
    <?php if (($_SESSION['role'] ?? '') === 'inventory_clerk'): ?>

    <!-- ═══ INVENTORY-CLERK DASHBOARD (StockMate layout) ═══ -->
    <div class="inv-shell">
      <aside class="inv-sidebar">
        <div class="inv-brand"><i class="fa-solid fa-mug-hot"></i><span>Bird's Nest</span></div>
        <nav class="inv-nav">
          <a class="inv-navitem active" href="dashboard.php"><i class="fa-solid fa-gauge-high"></i><span>Dashboard</span></a>
          <?php
          // Permission-driven nav from the canonical registry: any granted permission
          // that has a registry entry surfaces its link here (see nav_menu.php).
          foreach (nav_items($conn) as $it) {
              echo '<a class="inv-navitem" href="'.htmlspecialchars($it['href']).'"><i class="fa-solid '.htmlspecialchars($it['icon']).'"></i><span>'.htmlspecialchars($it['label']).'</span></a>';
          }
          ?>
        </nav>
        <?php if (can('my_profile')): ?>
        <a href="profile.php" class="inv-userchip">
        <?php else: ?><div class="inv-userchip" style="cursor:default"><?php endif; ?>
          <div class="inv-avatar"><?php $__ph = current_user_photo($conn); if ($__ph): ?><img src="<?= htmlspecialchars($__ph) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;display:block"><?php else: ?><?= htmlspecialchars(strtoupper(substr($admin_name,0,2))) ?><?php endif; ?></div>
          <div><div class="inv-uname"><?= htmlspecialchars($admin_name) ?></div>
          <div class="inv-urole"><?= htmlspecialchars($_cur_role_name) ?></div></div>
        <?php if (can('my_profile')): ?></a><?php else: ?></div><?php endif; ?>
      </aside>

      <main class="inv-main">
        <header class="inv-header">
          <div>
            <div class="inv-greet"><?php
              $h=(int)date('G'); echo $h<12?'Good morning':($h<18?'Good afternoon':'Good evening');
            ?>, <span><?= htmlspecialchars($admin_name) ?></span></div>
            <div class="inv-date"><?= date('l, F j, Y') ?></div>
          </div>
          <div class="inv-hcluster">
            <button class="inv-iconbtn" id="invThemeBtn" title="Toggle theme"><i class="fa-solid fa-sun"></i></button>
            <div class="inv-bellwrap">
              <button class="inv-iconbtn" id="invBell"><i class="fa-solid fa-bell"></i>
                <?php if ($_unread_ann > 0): ?><span class="inv-bellcount" id="invBellCount"><?= $_unread_ann ?></span><?php endif; ?>
              </button>
              <div class="inv-notifpanel" id="invNotifPanel">
                <div class="inv-notif-head"><span>Notifications</span><button class="inv-notif-clear" onclick="invMarkAllRead()">Mark all read</button></div>
                <div class="inv-notif-list" id="invNotifList">
                  <?php
                  $nres = $conn->query("SELECT id, title, message, type, created_at FROM announcements
                     WHERE is_active=1 AND (expires_at IS NULL OR expires_at>=CURDATE())
                       AND (starts_at IS NULL OR starts_at<=CURDATE())
                     ORDER BY created_at DESC LIMIT 6");
                  if (!$nres || !$nres->num_rows): ?>
                    <div class="inv-empty" style="padding:14px">You're all caught up.</div>
                  <?php else: while ($n=$nres->fetch_assoc()):
                    $tc = $n['type']==='urgent'?'#ff6b6b':($n['type']==='warning'?'#f0b429':'#5b9bd5'); ?>
                    <div class="inv-notif-item"><span class="inv-actdot" style="background:<?= $tc ?>"></span>
                      <div><div class="inv-acttext"><?= htmlspecialchars($n['title']) ?></div>
                      <div class="inv-notif-msg"><?= htmlspecialchars($n['message']) ?></div></div></div>
                  <?php endwhile; endif; ?>
                </div>
                <a class="inv-notif-foot" href="announcements.php">View all notifications</a>
              </div>
            </div>
            <?php
            $_invClocked  = $_is_clocked_in;
            $_invClkIcon  = $_invClocked ? 'right-from-bracket' : 'fingerprint';
            $_invClkLabel = $_invClocked ? 'Clock Out' : 'Clock In';
            $_invClkTitle = $_invClocked ? 'Clocked in at ' . $_clock_since : 'Not clocked in';
            ?>
            <button class="inv-clockbtn" id="clockBtn" onclick="toggleClock()" data-clocked="<?= $_invClocked ? '1' : '0' ?>" title="<?= htmlspecialchars($_invClkTitle) ?>"><i class="fa-solid fa-<?= $_invClkIcon ?>"></i> <?= $_invClkLabel ?></button>
            <a class="inv-logoutbtn" href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
          </div>
        </header>

        <?php if ((int)$low_stock > 0): ?>
        <a class="inv-banner" href="ingredients.php">
          <span><i class="fa-solid fa-triangle-exclamation"></i> <?= (int)$low_stock ?> item<?= $low_stock==1?'':'s' ?> low on stock — restock needed soon</span>
          <span class="inv-banner-cta">Review Stock <i class="fa-solid fa-chevron-right"></i></span>
        </a>
        <?php endif; ?>

        <section class="inv-stats">
          <div class="inv-card">
            <div class="inv-card-ico" style="color:var(--amber)"><i class="fa-solid fa-cube"></i></div>
            <div class="inv-card-val"><?= number_format($inv_total_products) ?></div>
            <div class="inv-card-lbl">Total Products</div>
            <div class="inv-card-sub">Active catalog</div>
          </div>
          <div class="inv-card">
            <div class="inv-card-ico" style="color:#ff6b6b"><i class="fa-solid fa-arrow-trend-down"></i></div>
            <div class="inv-card-val" style="color:<?= $low_stock>0?'#ff6b6b':'var(--text)' ?>"><?= (int)$low_stock ?></div>
            <div class="inv-card-lbl">Low Stock Items</div>
            <div class="inv-card-sub"><?= $low_stock>0?'Restock needed soon':'Stock levels healthy' ?></div>
          </div>
          <div class="inv-card">
            <div class="inv-card-ico" style="color:#5b9bd5"><i class="fa-solid fa-cart-shopping"></i></div>
            <div class="inv-card-val"><?= (int)$inv_pending_po ?></div>
            <div class="inv-card-lbl">Pending Orders</div>
            <div class="inv-card-sub"><?= (int)$inv_pending_po ?> awaiting delivery</div>
          </div>
          <div class="inv-card">
            <div class="inv-card-ico" style="color:<?= $inv_out_of_stock>0?'#ff4d4d':'#3ecf8e' ?>"><i class="fa-solid fa-ban"></i></div>
            <div class="inv-card-val" style="color:<?= $inv_out_of_stock>0?'#ff4d4d':'var(--text)' ?>"><?= (int)$inv_out_of_stock ?></div>
            <div class="inv-card-lbl">Out of Stock</div>
            <div class="inv-card-sub"><?= $inv_out_of_stock>0 ? 'Items at zero — order now' : 'No shortages — nice work' ?></div>
          </div>
        </section>
        <div class="inv-body">
          <div class="inv-content">
            <?php if (can('products')||can('ingredients')||can('recipes')||can('stock_count')): ?>
            <div class="inv-sec-label">Inventory</div>
            <div class="inv-tiles">
              <?php if (can('products')): ?>
              <a class="inv-tile" href="products.php"><span class="inv-tile-ico" style="color:var(--amber)"><i class="fa-solid fa-cube"></i></span>
                <span><span class="inv-tile-t">Products<?php if ($inv_total_products>0): ?> <span class="inv-tile-badge count"><?= (int)$inv_total_products ?></span><?php endif; ?></span><span class="inv-tile-d">Manage all finished goods</span></span><i class="fa-solid fa-chevron-right inv-tile-arw"></i></a>
              <?php endif; ?>
              <?php if (can('ingredients')): ?>
              <a class="inv-tile" href="ingredients.php"><span class="inv-tile-ico" style="color:#3ecf8e"><i class="fa-solid fa-flask"></i></span>
                <span><span class="inv-tile-t">Ingredients</span><span class="inv-tile-d">Raw materials &amp; components</span></span><i class="fa-solid fa-chevron-right inv-tile-arw"></i></a>
              <?php endif; ?>
              <?php if (can('stock_count')): ?>
              <a class="inv-tile" href="stock_count.php"><span class="inv-tile-ico" style="color:#e0b34a"><i class="fa-solid fa-clipboard-list"></i></span>
                <span><span class="inv-tile-t">Stock Count</span><span class="inv-tile-d">Physical inventory count</span></span><i class="fa-solid fa-chevron-right inv-tile-arw"></i></a>
              <?php endif; ?>
              <?php if (can('recipes')): ?>
              <a class="inv-tile" href="recipes_view.php"><span class="inv-tile-ico" style="color:#b98add"><i class="fa-solid fa-utensils"></i></span>
                <span><span class="inv-tile-t">Drink Recipes<?php if ($low_recipe_count>0): ?> <span class="inv-tile-badge"><?= (int)$low_recipe_count ?> low</span><?php endif; ?></span><span class="inv-tile-d">Beverage formulations</span></span><i class="fa-solid fa-chevron-right inv-tile-arw"></i></a>
              <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (can('suppliers')||can('purchase_orders')): ?>
            <div class="inv-sec-label">Procurement</div>
            <div class="inv-tiles">
              <?php if (can('suppliers')): ?>
              <a class="inv-tile" href="suppliers.php"><span class="inv-tile-ico" style="color:#5b9bd5"><i class="fa-solid fa-truck-ramp-box"></i></span>
                <span><span class="inv-tile-t">Suppliers</span><span class="inv-tile-d">Vendor management</span></span><i class="fa-solid fa-chevron-right inv-tile-arw"></i></a>
              <?php endif; ?>
              <?php if (can('purchase_orders')): ?>
              <a class="inv-tile" href="purchase_orders.php"><span class="inv-tile-ico" style="color:var(--amber)"><i class="fa-solid fa-file-invoice"></i></span>
                <span><span class="inv-tile-t">Purchase Orders</span><span class="inv-tile-d">Track and manage POs</span></span><i class="fa-solid fa-chevron-right inv-tile-arw"></i></a>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <aside class="inv-rail">
            <div class="inv-panel">
              <div class="inv-panel-head">
                <span><i class="fa-solid fa-arrow-trend-down" style="color:#ff6b6b"></i> Low Stock</span>
                <div class="inv-filter">
                  <button class="inv-fbtn active" data-mode="all" onclick="invFilterLow('all',this)">All</button>
                  <button class="inv-fbtn" data-mode="low" onclick="invFilterLow('low',this)">Low</button>
                  <button class="inv-fbtn" data-mode="critical" onclick="invFilterLow('critical',this)">Critical</button>
                  <a class="inv-viewall" href="ingredients.php">View all</a>
                </div>
              </div>
              <div class="inv-lslist" id="invLsList">
                <?php if (!$inv_low_list): ?>
                  <div class="inv-empty">Stock levels look healthy.</div>
                <?php else: foreach (array_slice($inv_low_list, 0, 5) as $it):
                  $min=(float)$it['minimum_stock']; $st=(float)$it['stock_quantity'];
                  $ratio = $min>0 ? max(0,min(1,$st/$min)) : 1;
                  $sev = $ratio < 0.10 ? 'critical' : 'low';
                  $pct = round($ratio*100);
                  $barcol = $ratio<0.10 ? '#ff4d4d' : ($ratio<0.50 ? '#ff8a3d' : '#f0b429');
                  $barw = $ratio<0.10 ? max($pct,6) : $pct; // keep near-zero criticals visibly red
                  $qty = rtrim(rtrim(number_format($st,2,'.',''),'0'),'.');
                ?>
                  <div class="inv-lsrow" data-sev="<?= $sev ?>">
                    <div class="inv-lsrow-top"><span class="inv-lsname"><?= htmlspecialchars($it['ingredient_name']) ?></span>
                      <span class="inv-lsqty"><?= $qty ?> <?= htmlspecialchars($it['unit']) ?></span></div>
                    <div class="inv-lsbar"><span style="width:<?= $barw ?>%;background:<?= $barcol ?>"></span></div>
                    <div class="inv-lssub"><?= $pct ?>% of threshold (<?= rtrim(rtrim(number_format($min,2,'.',''),'0'),'.') ?> <?= htmlspecialchars($it['unit']) ?>)</div>
                  </div>
                <?php endforeach; endif; ?>
              </div>
            </div>
            <div class="inv-panel">
              <div class="inv-panel-head"><span>Recent Activity</span></div>
              <div class="inv-actlist">
                <?php
                $actMap = [
                  'po_received'   => ['Purchase Order received', '#5b9bd5'],
                  'quick_restock' => ['Restocked',               '#3ecf8e'],
                  'count_adjust'  => ['Stock count adjusted',    '#b98add'],
                  'manual_adjust' => ['Stock adjusted',          '#f0b429'],
                ];
                if (!$inv_activity): ?>
                  <div class="inv-empty">No recent stock activity.</div>
                <?php else: foreach (array_slice($inv_activity, 0, 3) as $a):
                  [$label,$dot] = $actMap[$a['change_type']] ?? ['Inventory updated','#888'];
                  $ts = strtotime($a['created_at']); $diff = time()-$ts;
                  $ago = $diff<3600 ? max(1,floor($diff/60)).'m' : ($diff<86400 ? floor($diff/3600).'h' : floor($diff/86400).'d');
                ?>
                  <div class="inv-actrow"><span class="inv-actdot" style="background:<?= $dot ?>"></span>
                    <div><div class="inv-acttext"><?= htmlspecialchars($label) ?> — <?= htmlspecialchars($a['ingredient_name']) ?></div>
                    <div class="inv-actago"><?= $ago ?> ago</div></div></div>
                <?php endforeach; endif; ?>
              </div>
            </div>
          </aside>
        </div>
      </main>
    </div>

    <?php else: ?>

    <?php
    // ── Role-aware focus card: surface each role's single most relevant task on landing ──
    // NOTE: barista_station is granted to both barista AND staff(cashier), so match on
    // role first (barista/inventory), then fall back to permissions for everyone else.
    $_role  = $_SESSION['role'] ?? '';
    $_focus = null;

    // Compact redesign (cashier + inventory). Barista & others keep the legacy .qa-* layout.
    $_redesign = in_array($_role, ['staff', 'inventory_clerk'], true);
    $G = $_redesign ? 'qx' : 'qa';

    $_focus_barista = [
        'icon'  => 'fa-fire-burner',
        'count' => (int)$preparing_count,
        'label' => $preparing_count == 1 ? 'drink to prepare' : 'drinks to prepare',
        'sub'   => $preparing_count > 0 ? 'Orders are waiting in the queue' : 'All caught up — nothing in the queue',
        'href'  => 'barista_display.php',
        'cta'   => 'Open Barista Station',
        'color' => $preparing_count > 0 ? '#ff8a3d' : '#55c97e',
    ];
    $_pending_total = (int)$unpaid_count + (int)$paylater_count;
    $_focus_cashier = [
        'icon'  => 'fa-cash-register',
        'count' => $_pending_total,
        'label' => $_pending_total == 1 ? 'order awaiting payment' : 'orders awaiting payment',
        'sub'   => (int)$unpaid_count . ' unpaid · ' . (int)$paylater_count . ' pay-later',
        'href'  => 'find_order.php',
        'cta'   => 'Find Orders',
        'color' => $_pending_total > 0 ? '#9b59b6' : '#55c97e',
    ];
    $_focus_inventory = [
        'icon'  => 'fa-triangle-exclamation',
        'count' => (int)$low_stock,
        'label' => $low_stock == 1 ? 'item low on stock' : 'items low on stock',
        'sub'   => $low_stock > 0 ? 'Restock needed soon' : 'Stock levels look healthy',
        'href'  => 'ingredients.php',
        'cta'   => 'Review Stock',
        'color' => $low_stock > 0 ? '#ff6b6b' : '#55c97e',
    ];

    if ($_role === 'barista') {
        $_focus = $_focus_barista;                       // barista → drinks to prepare
    } elseif ($_role === 'inventory_clerk') {
        $_focus = $_focus_inventory;                      // inventory clerk → stock
    } elseif (can('find_orders')) {
        $_focus = $_focus_cashier;                        // cashier / order-taking → payments
    } elseif (can('ingredients') || can('products')) {
        $_focus = $_focus_inventory;                      // other stock-facing roles
    } elseif (can('barista_station')) {
        $_focus = $_focus_barista;                        // prep-only roles
    }
    ?>
    <?php
    // Depth/hierarchy pass (icon size, count weight) applies to the redesigned
    // cashier/inventory layout only — barista keeps the original legacy sizing.
    $_iconBox   = $_redesign ? 64 : 56;
    $_iconRad   = $_redesign ? 16 : 14;
    $_iconFont  = $_redesign ? 28 : 25;
    $_iconBg    = $_redesign ? '2e' : '22';
    $_countFont = $_redesign ? 29 : 26;
    $_countWt   = $_redesign ? 800 : 700;
    ?>
    <?php if ($_focus): ?>
    <?php $_iconShadow = $_redesign ? "box-shadow:0 0 0 1px {$_focus['color']}33 inset;" : ''; ?>
    <a href="<?= htmlspecialchars($_focus['href']) ?>" class="fu" style="animation-delay:.06s;display:flex;align-items:center;gap:18px;text-decoration:none;background:var(--surface-2);border:1px solid var(--border);border-left:4px solid <?= $_focus['color'] ?>;border-radius:16px;padding:18px 22px;margin-bottom:22px;transition:transform .15s ease,border-color .15s ease;" onmouseover="this.style.transform='translateY(-2px)';this.style.borderColor='var(--border-hi)'" onmouseout="this.style.transform='';this.style.borderColor='var(--border)'">
        <div style="flex:0 0 auto;width:<?= $_iconBox ?>px;height:<?= $_iconBox ?>px;border-radius:<?= $_iconRad ?>px;display:flex;align-items:center;justify-content:center;font-size:<?= $_iconFont ?>px;color:<?= $_focus['color'] ?>;background:<?= $_focus['color'] ?><?= $_iconBg ?>;<?= $_iconShadow ?>">
            <i class="fa-solid <?= $_focus['icon'] ?>"></i>
        </div>
        <div style="flex:1 1 auto;min-width:0;">
            <div style="font-size:<?= $_countFont ?>px;font-weight:<?= $_countWt ?>;color:var(--text);font-variant-numeric:tabular-nums;line-height:1.1;">
                <?= (int)$_focus['count'] ?> <span style="font-size:15px;font-weight:500;color:var(--text-muted);"><?= htmlspecialchars($_focus['label']) ?></span>
            </div>
            <div style="font-size:13px;color:var(--text-muted);margin-top:4px;"><?= htmlspecialchars($_focus['sub']) ?></div>
        </div>
        <span style="flex:0 0 auto;display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:600;color:<?= $_focus['color'] ?>;white-space:nowrap;">
            <?= htmlspecialchars($_focus['cta']) ?> <i class="fa-solid fa-arrow-right"></i>
        </span>
    </a>
    <?php endif; ?>

    <!-- QUICK ACCESS GRID -->
    <div class="<?= $_redesign ? 'qx-grid' : 'qa-grid' ?> fu" style="animation-delay:.1s">
        <?php if (can('find_orders')): ?>
        <a href="menu.php" class="<?= $_redesign ? 'qx-hero' : 'qa-hero-btn' ?>">
            <i class="fa-solid fa-plus"></i>
            <span>Take New Order</span>
        </a>
        <?php endif; ?>

        <?php
        // ── Permission-driven tiles from the canonical registry (nav_menu.php) ──
        // Any granted, registry-listed permission surfaces here, grouped by section.
        // Curated badges are re-attached by slug so no signal is lost.
        $__nav_items = nav_items($conn);
        $__nav_groups = [];
        foreach ($__nav_items as $__it) $__nav_groups[$__it['section']][] = $__it;
        $__section_icons = [
            'Orders'=>'fa-receipt','Operations'=>'fa-mug-hot','Inventory'=>'fa-boxes-stacked',
            'Procurement'=>'fa-truck-ramp-box','Reconciliation'=>'fa-cash-register',
            'Loyalty'=>'fa-star','Analytics'=>'fa-chart-simple','Staff'=>'fa-users','Admin'=>'fa-shield-halved',
        ];
        ?>
        <?php foreach ($__nav_groups as $__section => $__items): ?>
        <div class="<?= $G ?>-group">
            <div class="<?= $G ?>-group-label"><i class="fa-solid <?= htmlspecialchars($__section_icons[$__section] ?? 'fa-folder') ?>"></i> <?= htmlspecialchars($__section) ?></div>
            <div class="<?= $G ?>-tiles">
                <?php foreach ($__items as $__it): ?>
                <a href="<?= htmlspecialchars($__it['href']) ?>" class="<?= $G ?>-tile" style="position:relative">
                    <?php
                    // Re-attach curated badges by slug (parity with the old hardcoded tiles).
                    if ($__it['slug'] === 'find_orders') {
                        if (($_SESSION['role'] ?? '') === 'staff' && $paylater_count > 0) {
                            echo '<span class="'.$G.'-tile-badge" style="background:var(--purple);">'.$paylater_count.'</span>';
                        } elseif ($unpaid_count > 0) {
                            echo '<span class="'.$G.'-tile-badge">'.$unpaid_count.'</span>';
                        }
                    } elseif ($__it['slug'] === 'recipes' && $low_recipe_count > 0) {
                        echo '<span class="'.$G.'-tile-badge" title="'.$low_recipe_count.' recipe'.($low_recipe_count == 1 ? '' : 's').' low on ingredients">'.$low_recipe_count.'</span>';
                    } elseif ($__it['slug'] === 'announcements' && $_unread_ann > 0) {
                        echo '<span style="position:absolute;top:8px;right:8px;background:var(--red);color:#fff;font-size:10px;font-weight:700;min-width:18px;height:18px;border-radius:9px;display:flex;align-items:center;justify-content:center;padding:0 4px;line-height:1">'.$_unread_ann.'</span>';
                    }
                    ?>
                    <i class="fa-solid <?= htmlspecialchars($__it['icon']) ?>"></i>
                    <span><?= htmlspecialchars($__it['label']) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (!$__nav_items && !$_focus): ?>
        <div class="<?= $G ?>-group fu" style="text-align:center;padding:40px 24px;color:var(--text-muted)">
            <i class="fa-solid fa-lock" style="font-size:26px;opacity:.6"></i>
            <div style="margin-top:12px;font-weight:600;color:var(--text)">No areas assigned yet</div>
            <div style="margin-top:4px">Contact your system administrator to adjust your permissions.</div>
        </div>
        <?php endif; ?>

        <?php if (can('my_profile')): ?>
        <div class="<?= $G ?>-group">
            <div class="<?= $G ?>-group-label"><i class="fa-solid fa-circle-user"></i> Account</div>
            <div class="<?= $G ?>-tiles">
                <a href="profile.php" class="<?= $G ?>-tile">
                    <i class="fa-solid fa-circle-user"></i>
                    <span>My Profile</span>
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php endif; ?>

    <?php endif; /* end admin/manager vs employee view */ ?>

</div><!-- /main -->
</div><!-- /layout -->

<script>
/* ── Sidebar clock ── */
function updateSidebarClock(){
    const el=document.getElementById('sidebarClock');
    if(!el)return;
    const now=new Date();
    let h=now.getHours();
    const ampm=h>=12?'PM':'AM';
    h=h%12; if(h===0)h=12;
    el.textContent=h+':'+String(now.getMinutes()).padStart(2,'0')+' '+ampm;
}
updateSidebarClock();
setInterval(updateSidebarClock,1000);

/* ── Time of day greeting ── */
(function(){
    const el=document.getElementById('timeOfDay');
    if(!el)return;
    const h=new Date().getHours();
    const g=h<12?'morning':h<17?'afternoon':'evening';
    el.textContent=g;
})();


/* ── Mobile sidebar (absent for roles without sidebar access) ── */
function toggleSidebar(){
    const sb = document.getElementById('sidebar');
    const ov = document.querySelector('.overlay');
    if (!sb || !ov) return;
    sb.classList.toggle('open');
    ov.classList.toggle('active');
}
function closeSidebar(){
    const sb = document.getElementById('sidebar');
    const ov = document.querySelector('.overlay');
    if (!sb || !ov) return;
    sb.classList.remove('open');
    ov.classList.remove('active');
}
document.addEventListener('keydown',e=>{
    if(e.key==='Escape') closeSidebar();
});
window.addEventListener('resize',()=>{
    if(window.innerWidth>768) closeSidebar();
});

/* ── Theme toggle ── */
function toggleTheme(){
    const html=document.documentElement;
    const icon=document.getElementById('themeIcon');
    const text=document.getElementById('themeText');
    if(html.getAttribute('data-theme')==='light'){
        html.removeAttribute('data-theme');
        icon.className='fa-solid fa-moon';
        text.textContent='Dark';
        localStorage.setItem('theme','dark');
    } else {
        html.setAttribute('data-theme','light');
        icon.className='fa-solid fa-sun';
        text.textContent='Light';
        localStorage.setItem('theme','light');
    }
}
document.addEventListener('DOMContentLoaded',()=>{
    if(localStorage.getItem('theme')==='light'){
        document.documentElement.setAttribute('data-theme','light');
        const icon=document.getElementById('themeIcon');
        const text=document.getElementById('themeText');
        if(icon) icon.className='fa-solid fa-sun';
        if(text) text.textContent='Light';
    }
});

/* ── Toast ── */
function showToast(msg,type='success'){
    const c=document.getElementById('toastContainer');
    const t=document.createElement('div');
    t.className='toast '+type;
    const ic=type==='success'?'fa-check-circle':'fa-exclamation-circle';
    t.innerHTML=`<i class="fa-solid ${ic}"></i><span>${msg}</span><span class="close-toast" onclick="this.parentElement.remove()"><i class="fa-solid fa-xmark"></i></span>`;
    c.appendChild(t);
    setTimeout(()=>{ if(t.parentElement){ t.style.opacity='0'; t.style.transform='translateX(18px)'; setTimeout(()=>t.remove(),400); }},5000);
}
<?php if ($_flash_welcome): ?>
document.addEventListener('DOMContentLoaded',()=>showToast('Welcome back, <?= htmlspecialchars($admin_name, ENT_QUOTES) ?>!','success'));
<?php endif; ?>

async function toggleClock(){
    var btn = document.getElementById('clockBtn');
    if (!btn) return;
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
            var isInv = btn.classList.contains('inv-clockbtn');
            if (!clocked) {
                btn.dataset.clocked = '1';
                btn.innerHTML = '<i class="fa-solid fa-right-from-bracket"></i> Clock Out';
                if (!isInv) {
                    btn.style.background = 'rgba(255,95,95,.08)';
                    btn.style.borderColor = 'rgba(255,95,95,.25)';
                    btn.style.color = '#ff6b6b';
                }
                btn.title = 'Clocked in at ' + (data.time || '');
            } else {
                btn.dataset.clocked = '0';
                btn.innerHTML = '<i class="fa-solid fa-fingerprint"></i> Clock In';
                if (!isInv) {
                    btn.style.background = 'rgba(85,224,135,.08)';
                    btn.style.borderColor = 'rgba(85,224,135,.25)';
                    btn.style.color = '#55e087';
                }
                btn.title = 'Not clocked in';
            }
            showToast(data.msg, 'success');
        } else {
            showToast(data.msg, 'error');
        }
    } catch(e) {
        showToast('Connection error.', 'error');
    }

    btn.disabled = false;
    btn.style.opacity = '1';
}
<?php /* Low-stock toast removed — already surfaced by the red alert banner up top (no duplicate). */ ?>

/* ── AJAX polling (kitchen + KPIs) ── */
const OVERDUE_MINUTES = <?= (int)OVERDUE_MINUTES ?>;
const WARN_MINUTES    = Math.max(1, Math.floor(OVERDUE_MINUTES * 0.7));
function fetchDashboardData(){
    fetch('dashboard_data.php')
        .then(r=>r.json())
        .then(d=>{
            const lu=document.getElementById('lastUpdated');
            if(lu) lu.textContent=new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});

            const rev=document.getElementById('kpiRevenue');
            const ord=document.getElementById('kpiOrders');
            const itm=document.getElementById('kpiItems');
            if(rev) rev.textContent=parseFloat(d.sales).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
            if(ord) ord.textContent=d.total_orders;
            if(itm && d.items_sold!==undefined) itm.textContent=d.items_sold;

            const kc=document.getElementById('kitchenCount');
            const kl=document.getElementById('kitchenList');
            if(kc && d.kitchen_orders!==undefined){
                kc.textContent=d.kitchen_orders.length+' preparing';
                kc.className='cnt-badge'+(d.kitchen_orders.length>0?' on':'');
            }
            if(kl && d.kitchen_orders!==undefined){
                if(d.kitchen_orders.length>0){
                    kl.innerHTML=d.kitchen_orders.map(o=>{
                        const mins=Math.floor((Date.now()-new Date(o.order_date.replace(' ','T')))/60000);
                        const tc=mins>=OVERDUE_MINUTES?'urgent':mins>=WARN_MINUTES?'warn':'ok';
                        return `<div class="k-item">
                            <div class="k-no">#${o.daily_order_no}</div>
                            <div class="k-name">${o.customer_name}</div>
                            <div class="k-total">$${parseFloat(o.total).toFixed(2)}</div>
                            <div class="k-timer ${tc}">${mins}m</div>
                            <span class="k-status-pill">
                                <i class="fa-solid fa-fire-burner"></i> Preparing
                            </span>
                        </div>`;
                    }).join('');
                } else {
                    kl.innerHTML='<div class="k-empty"><i class="fa-solid fa-circle-check"></i><span>All clear — no orders preparing</span></div>';
                }
            }
        })
        .catch(()=>{});
}
setInterval(fetchDashboardData,5000);
fetchDashboardData();

/* ── Collapsible sidebar groups ── */
function toggleGroup(label) {
    const items = label.nextElementSibling;
    if (!items || !items.classList.contains('nav-group-items')) return;
    const isOpen = label.classList.contains('open');
    label.classList.toggle('open', !isOpen);
    items.classList.toggle('collapsed', isOpen);
    const g = label.dataset.group;
    if (g) localStorage.setItem('nav_' + g, isOpen ? '0' : '1');
}
function initNavGroups() {
    document.querySelectorAll('.nav-group-label[data-group]').forEach(label => {
        const stored = localStorage.getItem('nav_' + label.dataset.group);
        if (stored === null) return; // keep PHP default (overview open, rest collapsed)
        const items = label.nextElementSibling;
        if (!items) return;
        label.classList.toggle('open', stored === '1');
        items.classList.toggle('collapsed', stored !== '1');
    });
}
document.addEventListener('DOMContentLoaded', initNavGroups);

/* ── Idle auto-logout (30 min, warn at 25) ── */
(function(){
    const WARN=25*60*1000, OUT=30*60*1000;
    let wt,lt,wel;
    function reset(){
        clearTimeout(wt);clearTimeout(lt);
        if(wel){wel.remove();wel=null;}
        wt=setTimeout(()=>{
            wel=document.createElement('div');
            wel.style.cssText='position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:99999;background:var(--surface);border:1px solid rgba(245,158,11,.4);border-radius:var(--r);padding:13px 18px;font-family:Inter,sans-serif;font-size:12.5px;color:var(--text);display:flex;align-items:center;gap:10px;box-shadow:var(--shadow-lg);white-space:nowrap';
            wel.innerHTML='<i class="fa-solid fa-clock" style="color:var(--amber)"></i><span>Session expires in <strong>5 minutes</strong> due to inactivity.</span><button onclick="this.parentElement.remove()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:15px;margin-left:8px">×</button>';
            document.body.appendChild(wel);
        },WARN);
        lt=setTimeout(()=>{window.location.href='logout.php?timeout=1';},OUT);
    }
    ['mousemove','keydown','click','scroll','touchstart'].forEach(ev=>document.addEventListener(ev,reset,{passive:true}));
    reset();
})();
</script>
<?php if (($_SESSION['role'] ?? '') === 'inventory_clerk'): ?>
<script>
function invFilterLow(mode, btn){
  document.querySelectorAll('.inv-fbtn').forEach(b=>b.classList.toggle('active', b===btn));
  document.querySelectorAll('#invLsList .inv-lsrow').forEach(r=>{
    const sev=r.dataset.sev; let show = mode==='all' || (mode==='critical'&&sev==='critical') || (mode==='low'&&(sev==='low'||sev==='critical'));
    r.style.display = show ? '' : 'none';
  });
}
</script>
<script>
(function(){
  var bell=document.getElementById('invBell'), panel=document.getElementById('invNotifPanel');
  if(bell){bell.addEventListener('click',function(e){e.stopPropagation();panel.classList.toggle('open');});
    document.addEventListener('click',function(){panel.classList.remove('open');});
    panel.addEventListener('click',function(e){e.stopPropagation();});}
  var tbtn=document.getElementById('invThemeBtn');
  if(tbtn){
    var syncIcon=function(){var i=tbtn.querySelector('i');if(i)i.className='fa-solid '+(document.documentElement.getAttribute('data-theme')==='light'?'fa-moon':'fa-sun');};
    syncIcon();
    tbtn.addEventListener('click',function(){
      var cur=document.documentElement.getAttribute('data-theme')==='light'?'dark':'light';
      document.documentElement.setAttribute('data-theme',cur); localStorage.setItem('theme',cur); syncIcon();});
  }
})();
function invMarkAllRead(){
  var c=document.getElementById('invBellCount'); if(c)c.style.display='none';
  fetch('mark_announcements_read.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'}}).catch(function(){});
  document.getElementById('invNotifPanel').classList.remove('open');
}
</script>
<?php endif; ?>
</body>
</html>
