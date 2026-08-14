<?php
require 'auth.php';
require_once "config.php";
require_once __DIR__ . '/nav_menu.php';   // canonical permission->nav registry

$admin_name = $_SESSION['emp_name'] ?? $_SESSION['username'] ?? 'Admin';
$_is_mgr = in_array($_SESSION['role'] ?? '', ['admin', 'manager']);
$filter_user = 0;
$user_options = [];
if ($_is_mgr) {
    $user_options[0] = 'All Staff';
    $q_users = $conn->query("SELECT u.user_id, u.username, e.name AS emp_name, r.slug AS role FROM users u LEFT JOIN employees e ON e.user_id = u.user_id LEFT JOIN roles r ON r.id = u.role_id ORDER BY COALESCE(NULLIF(e.name, ''), u.username) ASC");
    if ($q_users) {
        while ($ur = $q_users->fetch_assoc()) {
            $displayName = !empty($ur['emp_name']) ? $ur['emp_name'] : $ur['username'];
            $user_options[$ur['user_id']] = $displayName . ' (' . ucfirst($ur['role'] ?? 'staff') . ')';
        }
    }
    $filter_user = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
} else {
    $filter_user = (int)$_SESSION['user_id'];
}

$user_clause_w = $filter_user > 0 ? " AND (user_id = $filter_user OR employee_id = $filter_user)" : "";
$user_clause_o = $filter_user > 0 ? " AND (o.user_id = $filter_user OR o.employee_id = $filter_user)" : "";

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

// ── QUICK RANGE & MONTH FILTER ──
$_has_filter_param = isset($_GET['quick_range']) || isset($_GET['select_month']);
$_quick_range  = trim($_GET['quick_range'] ?? '');
$_select_month = trim($_GET['select_month'] ?? '');

if (!$_has_filter_param) {
    $_quick_range  = 'today';
    $_select_month = (string)date('n');
}

$months_list = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

$date_start = $business_date;
$date_end   = $business_date;
$period_badge_label = 'Today';

if ($_quick_range === 'today') {
    $date_start = $business_date;
    $date_end   = $business_date;
    $period_badge_label = 'Today';
} elseif ($_quick_range === 'this_week' || $_quick_range === 'week') {
    $date_start = date('Y-m-d', strtotime('monday this week'));
    $date_end   = date('Y-m-d', strtotime('sunday this week'));
    $period_badge_label = 'This Week';
} elseif ($_quick_range === 'this_month' || $_quick_range === 'month') {
    $date_start = date('Y-m-01');
    $date_end   = date('Y-m-t');
    $period_badge_label = 'This Month';
} elseif ($_quick_range === 'this_year' || $_quick_range === 'year') {
    $date_start = date('Y-01-01');
    $date_end   = date('Y-12-31');
    $period_badge_label = 'This Year';
} elseif (!empty($_select_month) && isset($months_list[(int)$_select_month])) {
    $m_num = sprintf('%02d', (int)$_select_month);
    $curr_yr = date('Y');
    $date_start = "$curr_yr-$m_num-01";
    $date_end   = date('Y-m-t', strtotime($date_start));
    $period_badge_label = $months_list[(int)$_select_month];
}

if ($date_start === $date_end) {
    $date_cond_w = "business_date = '$date_start'";
    $date_cond_o = "o.business_date = '$date_start'";
} else {
    $date_cond_w = "business_date BETWEEN '$date_start' AND '$date_end'";
    $date_cond_o = "o.business_date BETWEEN '$date_start' AND '$date_end'";
}

$stmt_sales = $conn->query("SELECT IFNULL(SUM(total),0) AS total_sales FROM orders WHERE $date_cond_w " . $user_clause_w . " AND " . paid_orders_where());
$sales = (float)$stmt_sales->fetch_assoc()['total_sales'];

$stmt_yest = $conn->prepare("SELECT IFNULL(SUM(total),0) AS yesterday_sales FROM orders WHERE business_date=? " . $user_clause_w . " AND " . paid_orders_where());
$stmt_yest->bind_param("s", $prev_business_date);
$stmt_yest->execute();
$yesterday_sales   = $stmt_yest->get_result()->fetch_assoc()['yesterday_sales'];
$sales_trend       = $yesterday_sales > 0 ? round(($sales - $yesterday_sales) / $yesterday_sales * 100, 1) : 0;
$trend_class       = $sales_trend >= 0 ? 'up' : 'down';
$trend_icon        = $sales_trend >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';

$stmt_ord = $conn->query("SELECT COUNT(*) AS total_orders FROM orders WHERE $date_cond_w " . $user_clause_w);
$total_orders = (int)$stmt_ord->fetch_assoc()['total_orders'];

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
        "SELECT COUNT(*) c FROM purchase_orders WHERE status IN ('Draft','Ordered','Partially Received')"
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

$stmt_unpaid = $conn->prepare("SELECT COUNT(*) AS unpaid_count FROM orders WHERE status='PendingPayment' AND business_date=?" . $user_clause_w);
$stmt_unpaid->bind_param("s", $business_date);
$stmt_unpaid->execute();
$unpaid_count = $stmt_unpaid->get_result()->fetch_assoc()['unpaid_count'];

$paylater_result = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM orders WHERE payment_method='paylater' AND status IN ('Preparing','PendingPayment','Completed')" . $user_clause_w);
$paylater_count  = (int)mysqli_fetch_assoc($paylater_result)['cnt'];

$unpaid_orders_result = mysqli_query($conn, "SELECT order_id, daily_order_no, customer_name, total, status, payment_method, order_date, is_open, token_number FROM orders WHERE status='PendingPayment'" . $user_clause_w . " ORDER BY order_date DESC LIMIT 5");

$paid_open_result = mysqli_query($conn, "SELECT order_id, daily_order_no, customer_name, total, status, payment_method, order_date, is_open, token_number FROM orders WHERE status='Preparing' AND is_open=0" . $user_clause_w . " ORDER BY order_date DESC LIMIT 5");

$refund_result = mysqli_query($conn, "SELECT IFNULL(SUM(refund_amount),0) AS total_refunds, COUNT(*) AS refund_count FROM order_refunds WHERE DATE(refunded_at)=CURDATE()");
$refund_data   = mysqli_fetch_assoc($refund_result);
$total_refunds = $refund_data['total_refunds'];
$refund_count  = $refund_data['refund_count'];

$stmt_status = $conn->query("SELECT status, COUNT(*) as count FROM orders WHERE $date_cond_w " . $user_clause_w . " GROUP BY status");
$status_counts = [];
if ($stmt_status) {
    while ($row = $stmt_status->fetch_assoc()) { $status_counts[$row['status']] = $row['count']; }
}
$pending_count   = $status_counts['PendingPayment'] ?? 0;
$preparing_count = $status_counts['Preparing']      ?? 0;
$completed_count = $status_counts['Completed']      ?? 0;
$cancelled_count = $status_counts['Cancelled']      ?? 0;

$stmt_items = $conn->query("SELECT IFNULL(SUM(oi.quantity),0) AS total_items FROM order_items oi JOIN orders o ON oi.order_id=o.order_id WHERE $date_cond_o " . $user_clause_o . " AND oi.product_id <> 0 AND " . paid_orders_where('o'));
$items_sold = (int)$stmt_items->fetch_assoc()['total_items'];

// ── Profit Today calculation ──
$stmt_today_ids = $conn->prepare("SELECT o.order_id FROM orders o WHERE o.business_date = ? " . $user_clause_o . " AND " . paid_orders_where('o'));
$stmt_today_ids->bind_param("s", $business_date);
$stmt_today_ids->execute();
$res_today_ids = $stmt_today_ids->get_result();
$today_order_ids = [];
while ($r_oid = $res_today_ids->fetch_assoc()) {
    $today_order_ids[] = (int)$r_oid['order_id'];
}
$costMap_dash = ingredient_cost_map($conn);
$cogs_info_today = order_cogs($conn, $today_order_ids, $costMap_dash);
$cogs_today = (float)$cogs_info_today['total'];
$profit_today = $sales - $cogs_today;
$margin_today = $sales > 0 ? round(($profit_today / $sales) * 100, 1) : 0;

$stmt_kitchen = $conn->prepare("SELECT order_id, daily_order_no, customer_name, total, order_date, token_number FROM orders WHERE business_date=?" . $user_clause_w . " AND status='Preparing' ORDER BY order_date ASC LIMIT 8");
$stmt_kitchen->bind_param("s", $business_date);
$stmt_kitchen->execute();
$kitchen_result = $stmt_kitchen->get_result();

$stmt_recent = $conn->prepare("SELECT order_id, daily_order_no, customer_name, total, status, order_date FROM orders WHERE business_date=?" . $user_clause_w . " ORDER BY order_date DESC LIMIT 20");
$stmt_recent->bind_param("s", $business_date);
$stmt_recent->execute();
$recent_orders = $stmt_recent->get_result();

$top_selling_result = mysqli_query($conn, "SELECT p.name, p.image, SUM(oi.quantity) as total_sold, p.price FROM products p JOIN order_items oi ON p.product_id=oi.product_id JOIN orders o ON oi.order_id=o.order_id WHERE " . paid_orders_where('o') . $user_clause_o . " GROUP BY p.product_id ORDER BY total_sold DESC LIMIT 5");

$activity_result = mysqli_query($conn, "SELECT * FROM (SELECT 'order' as type, order_id as ref_id, customer_name as name, total as amount, status, order_date as date FROM orders WHERE 1=1 " . $user_clause_w . " UNION ALL SELECT 'stock' as type, ingredient_id as ref_id, ingredient_name as name, purchase_qty as amount, 'restocked' as status, NULL as date FROM ingredients) as activity ORDER BY date DESC LIMIT 5");

$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
if ($filter_status) {
    $stmt_filter = $conn->prepare("SELECT order_id, daily_order_no, customer_name, total, status, order_date FROM orders WHERE business_date=? AND status=?" . $user_clause_w . " ORDER BY order_date DESC LIMIT 20");
    $stmt_filter->bind_param("ss", $business_date, $filter_status);
    $stmt_filter->execute();
    $recent_orders = $stmt_filter->get_result();
}

// Flash toasts — only show once (right after login), then clear
$_flash_welcome     = !empty($_SESSION['flash_welcome']);     unset($_SESSION['flash_welcome']);
$_flash_stock_alert = !empty($_SESSION['flash_stock_alert']); unset($_SESSION['flash_stock_alert']);

// ── CHART DATA INITIALIZATION ──
$chart_7days_labels  = [];
$chart_7days_revenue = [];
$chart_7days_profit  = [];
for ($i = 6; $i >= 0; $i--) {
    $d_date = (new DateTime($business_date))->modify("-$i days")->format("Y-m-d");
    $d_label = (new DateTime($d_date))->format("D (j/n)");
    
    $st_rev = $conn->prepare("SELECT IFNULL(SUM(total),0) AS rev FROM orders WHERE business_date=? " . $user_clause_w . " AND " . paid_orders_where());
    $st_rev->bind_param("s", $d_date);
    $st_rev->execute();
    $d_rev = (float)$st_rev->get_result()->fetch_assoc()['rev'];
    
    $st_day_ids = $conn->prepare("SELECT o.order_id FROM orders o WHERE o.business_date = ? " . $user_clause_o . " AND " . paid_orders_where('o'));
    $st_day_ids->bind_param("s", $d_date);
    $st_day_ids->execute();
    $res_day_ids = $st_day_ids->get_result();
    $day_order_ids = [];
    while ($r_oid = $res_day_ids->fetch_assoc()) {
        $day_order_ids[] = (int)$r_oid['order_id'];
    }
    $day_cogs_info = order_cogs($conn, $day_order_ids, $costMap_dash);
    $d_cogs = (float)$day_cogs_info['total'];
    
    $chart_7days_labels[]  = $d_label;
    $chart_7days_revenue[] = round($d_rev, 2);
    $chart_7days_profit[]  = round(max(0, $d_rev - $d_cogs), 2);
}

$chart_cat_labels = [];
$chart_cat_sales  = [];
$st_cat = $conn->query("
    SELECT COALESCE(NULLIF(p.category, ''), 'Other') AS cat_name, IFNULL(SUM(oi.quantity), 0) AS total_qty
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.order_id
    JOIN products p ON oi.product_id = p.product_id
    WHERE " . paid_orders_where('o') . " AND $date_cond_o AND oi.product_id <> 0 " . $user_clause_o . "
    GROUP BY cat_name
    ORDER BY total_qty DESC
    LIMIT 6
");
while ($r_cat = $st_cat->fetch_assoc()) {
    if ((int)$r_cat['total_qty'] > 0) {
        $chart_cat_labels[] = $r_cat['cat_name'];
        $chart_cat_sales[]  = (int)$r_cat['total_qty'];
    }
}
if (empty($chart_cat_labels)) {
    $st_cat_all = $conn->query("
        SELECT DISTINCT COALESCE(NULLIF(category, ''), 'Other') AS cat_name
        FROM products
        ORDER BY cat_name ASC
        LIMIT 5
    ");
    while ($r_cat = $st_cat_all->fetch_assoc()) {
        $chart_cat_labels[] = $r_cat['cat_name'];
        $chart_cat_sales[]  = 0;
    }
}
if (empty($chart_cat_labels)) {
    $chart_cat_labels = ['Coffee', 'Tea', 'Frappe', 'Bakery'];
    $chart_cat_sales  = [0, 0, 0, 0];
}

// ── HOURLY ORDERS BREAKDOWN ──
$hour_slots = [];
for ($h = 6; $h < 24; $h++) {
    $hour_slots[$h] = ['cnt' => 0, 'sales' => 0.0, 'label' => date('g A', mktime($h, 0))];
}
for ($h = 0; $h < 6; $h++) {
    $hour_slots[$h] = ['cnt' => 0, 'sales' => 0.0, 'label' => date('g A', mktime($h, 0))];
}

$stmt_hr = $conn->query("
    SELECT HOUR(order_date) AS hr, COUNT(*) AS cnt, IFNULL(SUM(total), 0) AS total_sales
    FROM orders o
    WHERE $date_cond_o " . $user_clause_o . " AND " . paid_orders_where('o') . "
    GROUP BY hr
");
if ($stmt_hr) {
    while ($r_hr = $stmt_hr->fetch_assoc()) {
        $h = (int)$r_hr['hr'];
        if (isset($hour_slots[$h])) {
            $hour_slots[$h]['cnt']   = (int)$r_hr['cnt'];
            $hour_slots[$h]['sales'] = round((float)$r_hr['total_sales'], 2);
        }
    }
}

$chart_hourly_labels = [];
$chart_hourly_counts = [];
$chart_hourly_sales  = [];
foreach ($hour_slots as $slot) {
    $chart_hourly_labels[] = $slot['label'];
    $chart_hourly_counts[] = $slot['cnt'];
    $chart_hourly_sales[]  = $slot['sales'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@300;400;500;600;700&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
/* ── DASHBOARD CHARTS GRID ── */
.dash-charts-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 18px;
}
@media (max-width: 1024px) {
    .dash-charts-grid { grid-template-columns: 1fr; }
}

.chart-panel {
    background: var(--glass);
    border: 1px solid var(--border);
    border-radius: var(--r);
    overflow: hidden;
}
.chart-panel:hover {
    border-color: var(--border-hi);
    background: var(--glass-hi);
}

.period-badge {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-muted);
    background: var(--glass);
    border: 1px solid var(--border);
    padding: 3px 9px;
    border-radius: 50px;
}

.pnl-val-header {
    display: flex;
    flex-direction: column;
}
.pnl-amount {
    font-size: 28px;
    font-weight: 800;
    color: var(--text);
    line-height: 1.1;
}
.pnl-sub {
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: .08em;
    color: var(--text-muted);
}

.dash-tab-buttons {
    display: flex;
    background: var(--surface-2);
    border: 1px solid var(--border);
    padding: 3px;
    border-radius: 8px;
}
.dash-tab-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 6px;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--text-muted);
    background: transparent;
    border: none;
    cursor: pointer;
    transition: all .15s var(--ease);
}
.dash-tab-btn:hover {
    color: var(--text);
}
.dash-tab-btn.active {
    background: var(--glass-hi);
    color: var(--amber);
    box-shadow: 0 1px 4px rgba(0,0,0,0.2);
}
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
[data-theme="light"] body,
[data-theme="light"] .layout,
[data-theme="light"] .main {
    background-color: #ECEEF2 !important;
    color: #111827 !important;
}
[data-theme="light"] .dash-header h1 { color: #111827 !important; }
[data-theme="light"] .header-sub { color: #5A6373 !important; }
[data-theme="light"] .kpi-label { color: #5A6373 !important; }
[data-theme="light"] .kpi-value { color: #111827 !important; }
[data-theme="light"] .kpi-drill { color: #5A6373 !important; }
[data-theme="light"] .kpi-watermark { opacity: 0.05 !important; color: #000000 !important; }
[data-theme="light"] .kpi-pill { background: #F3F4F6 !important; border-color: #E5E7EB !important; color: #4B5563 !important; }

[data-theme="light"] .chart-panel,
[data-theme="light"] .panel {
    box-shadow: 0 1px 3px rgba(0,0,0,.06), 0 4px 14px rgba(0,0,0,.05) !important;
    background: #FFFFFF !important;
    border-color: #E2E5EA !important;
}
[data-theme="light"] .panel-head h3 { color: #111827 !important; }
[data-theme="light"] .period-badge { background: #F3F4F6 !important; color: #4B5563 !important; border-color: #E5E7EB !important; }
[data-theme="light"] #catTotalLabel { color: #64748b !important; }
[data-theme="light"] #catTotalVal { color: #0f172a !important; }
[data-theme="light"] .dash-tab-buttons { background: #F3F4F6 !important; border-color: #E5E7EB !important; }
[data-theme="light"] .dash-tab-btn { color: #4B5563 !important; }
[data-theme="light"] .dash-tab-btn.active { background: #FFFFFF !important; color: #d1904b !important; box-shadow: 0 1px 3px rgba(0,0,0,0.1) !important; }
[data-theme="light"] .theme-toggle { background: #FFFFFF !important; border-color: #CBD5E1 !important; color: #334155 !important; }
[data-theme="light"] .theme-toggle:hover { border-color: #94A3B8 !important; color: #0F172A !important; }

[data-theme="light"] .k-item,
[data-theme="light"] .recent-row { background: #F8FAFC !important; border-color: #E2E8F0 !important; }
[data-theme="light"] .k-name,
[data-theme="light"] .recent-name { color: #0F172A !important; }
[data-theme="light"] .k-no,
[data-theme="light"] .recent-no { color: #64748B !important; }
[data-theme="light"] .k-total,
[data-theme="light"] .recent-total { color: #0F172A !important; }
[data-theme="light"] .k-empty,
[data-theme="light"] .recent-empty { color: #64748B !important; }

/* ── RESET ── */
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html{background:var(--bg);scroll-behavior:smooth;}
body{
    font-family:'Poppins','Kantumruy Pro',sans-serif;
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

/* ── MAIN ── */

/* ── MAIN ── */
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

/* ── COLORED FILTER BUTTONS / SELECTS ── */
.filter-wrapper {
    position: relative;
    display: inline-flex;
    align-items: center;
}
.filter-wrapper i {
    position: absolute;
    left: 12px;
    pointer-events: none;
    font-size: 12px;
    z-index: 2;
    transition: color .2s var(--ease);
}
.dash-filter-select {
    padding: 8px 30px 8px 32px !important;
    border-radius: 12px !important;
    font-size: 12.5px !important;
    font-weight: 600 !important;
    font-family: 'Poppins', sans-serif !important;
    outline: none !important;
    cursor: pointer !important;
    transition: all .2s var(--ease) !important;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23888888' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: right 10px center !important;
}

/* Quick Range Theme (Amber) */
.filter-wrapper.range-filter i { color: #d1904b; }
.dash-filter-select.filter-range {
    background-color: #161412 !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23d1904b' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") !important;
    border: 1px solid rgba(209, 144, 75, 0.4) !important;
    color: #f3cb98 !important;
    box-shadow: 0 2px 8px rgba(209, 144, 75, 0.1);
}
.dash-filter-select.filter-range:hover, .dash-filter-select.filter-range:focus {
    border-color: #d1904b !important;
    box-shadow: 0 0 14px rgba(209, 144, 75, 0.35);
    background-color: #1e1914 !important;
}

/* Month Filter Theme (Cyan/Blue) */
.filter-wrapper.month-filter i { color: #3498db; }
.dash-filter-select.filter-month {
    background-color: #10161d !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%233498db' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") !important;
    border: 1px solid rgba(52, 152, 219, 0.4) !important;
    color: #a4d8fa !important;
    box-shadow: 0 2px 8px rgba(52, 152, 219, 0.1);
}
.dash-filter-select.filter-month:hover, .dash-filter-select.filter-month:focus {
    border-color: #3498db !important;
    box-shadow: 0 0 14px rgba(52, 152, 219, 0.35);
    background-color: #15202b !important;
}

/* Staff Filter Theme (Purple/Magenta) */
.filter-wrapper.user-filter i { color: #9b59b6; }
.dash-filter-select.filter-user {
    background-color: #16121a !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239b59b6' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") !important;
    border: 1px solid rgba(155, 89, 182, 0.4) !important;
    color: #e5c3f7 !important;
    box-shadow: 0 2px 8px rgba(155, 89, 182, 0.1);
}
.dash-filter-select.filter-user:hover, .dash-filter-select.filter-user:focus {
    border-color: #9b59b6 !important;
    box-shadow: 0 0 14px rgba(155, 89, 182, 0.35);
    background-color: #201726 !important;
}

/* Dropdown option menu colors */
.dash-filter-select option {
    background-color: #181818 !important;
    color: #f5f5f5 !important;
}

/* Light Theme support */
[data-theme="light"] .dash-filter-select option {
    background-color: #ffffff !important;
    color: #111827 !important;
}
[data-theme="light"] .dash-filter-select.filter-range {
    background-color: #fff9f2 !important;
    color: #92581d !important;
    border-color: rgba(209, 144, 75, 0.5) !important;
}
[data-theme="light"] .dash-filter-select.filter-month {
    background-color: #f2f9ff !important;
    color: #186399 !important;
    border-color: rgba(52, 152, 219, 0.5) !important;
}
[data-theme="light"] .dash-filter-select.filter-user {
    background-color: #fcf4ff !important;
    color: #6a2485 !important;
    border-color: rgba(155, 89, 182, 0.5) !important;
}

/* Clear / Reset Filter Button */
.dash-reset-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 12px;
    font-size: 12.5px;
    font-weight: 600;
    font-family: 'Poppins', sans-serif;
    background: rgba(239, 68, 68, 0.12);
    border: 1px solid rgba(239, 68, 68, 0.4);
    color: #ef4444;
    cursor: pointer;
    transition: all .2s var(--ease);
}
.dash-reset-btn:hover {
    background: rgba(239, 68, 68, 0.22);
    border-color: #ef4444;
    box-shadow: 0 0 12px rgba(239, 68, 68, 0.35);
    transform: translateY(-1px);
}
[data-theme="light"] .dash-reset-btn {
    background: #fef2f2;
    color: #dc2626;
    border-color: rgba(239, 68, 68, 0.4);
}

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

.dash-charts-grid{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:16px;
    margin-bottom:18px;
}
@media(max-width:820px){
    .dash-charts-grid{grid-template-columns:1fr;}
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
.kpi-card.c-purple{ --kc:var(--purple);  --kg:var(--purple-dim);  }

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
if (($_SESSION['role'] ?? '') === 'inventory_clerk') { $_bodyClasses[] = 'inv-mode'; }
?>
<body<?= $_bodyClasses ? ' class="' . htmlspecialchars(implode(' ', $_bodyClasses)) . '"' : '' ?>>

<button class="menu-toggle" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
<div class="overlay" onclick="toggleSidebar()"></div>
<div class="toast-container" id="toastContainer"></div>

<div class="flex h-screen w-screen overflow-hidden layout app-layout">

<!-- ═══ SIDEBAR ═══ -->
<?php require_once __DIR__ . '/sidebar.php'; ?>
<!-- end sidebar -->

<!-- ═══ MAIN ═══ -->
<main class="flex-1 h-full overflow-y-auto p-6 main app-main">

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
            <form id="dashFilterForm" method="GET" action="dashboard.php" style="margin:0;display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <!-- Quick Range Dropdown -->
                <div class="filter-wrapper range-filter">
                    <i class="fa-solid fa-calendar-day"></i>
                    <select id="dashQuickRange" name="quick_range" class="dash-filter-select filter-range">
                        <option value=""><?= __('quick_range', '-- Quick Range --') ?></option>
                        <option value="today" <?= $_quick_range === 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="this_week" <?= $_quick_range === 'this_week' ? 'selected' : '' ?>>This Week</option>
                        <option value="this_month" <?= $_quick_range === 'this_month' ? 'selected' : '' ?>>This Month</option>
                        <option value="this_year" <?= $_quick_range === 'this_year' ? 'selected' : '' ?>>This Year</option>
                    </select>
                </div>

                <!-- Month Dropdown -->
                <div class="filter-wrapper month-filter">
                    <i class="fa-solid fa-calendar-week"></i>
                    <select id="dashMonthSelect" name="select_month" class="dash-filter-select filter-month">
                        <option value=""><?= __('select_month', '-- Select Month --') ?></option>
                        <?php foreach ($months_list as $mnum => $mname): ?>
                        <option value="<?= $mnum ?>" <?= (string)$_select_month === (string)$mnum ? 'selected' : '' ?>><?= $mname ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($_is_mgr && !empty($user_options)): ?>
                <!-- Staff Member Dropdown -->
                <div class="filter-wrapper user-filter">
                    <i class="fa-solid fa-user-gear"></i>
                    <select id="dashUserSelect" name="user_id" class="dash-filter-select filter-user">
                        <?php foreach ($user_options as $uid => $uname): ?>
                        <option value="<?= $uid ?>" <?= $filter_user == $uid ? 'selected' : '' ?>><?= htmlspecialchars($uname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Clear / Reset Filter Button -->
                <button type="button" id="dashResetBtn" class="dash-reset-btn" title="Reset filter to current date/time" onclick="resetDashFilter()">
                    <i class="fa-solid fa-rotate-left"></i>
                    <span>Clear</span>
                </button>
            </form>
            <button class="theme-toggle" onclick="toggleTheme()">
                <i class="fa-solid fa-moon" id="themeIcon"></i>
                <span id="themeText"><?= __('dark_mode', 'Dark') ?></span>
            </button>
            <?php if (!$_is_mgr): ?>
            <?php
            $clocked  = $_is_clocked_in;
            $clkBg    = $clocked ? 'rgba(255,95,95,.08)'   : 'rgba(85,224,135,.08)';
            $clkBr    = $clocked ? 'rgba(255,95,95,.25)'   : 'rgba(85,224,135,.25)';
            $clkColor = $clocked ? '#ff6b6b'               : '#55e087';
            $clkIcon  = $clocked ? 'right-from-bracket'    : 'fingerprint';
            $clkLabel = $clocked ? __('clock_out', 'Clock Out') : __('clock_in', 'Clock In');
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
                <span><?= __('logout', 'Logout') ?></span>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php $user_param = $filter_user > 0 ? '&user_id=' . $filter_user : ''; ?>

    <?php if ($_is_mgr): ?>
    <!-- KPI ROW -->
    <div class="kpi-row fu" style="animation-delay:.1s">

        <!-- Revenue Today -->
        <a href="daily_report.php?date=<?= urlencode($business_date) ?><?= $user_param ?>" class="kpi-card c-amber" title="Open today's sales report">
            <i class="kpi-watermark fa-solid fa-dollar-sign"></i>
            <span class="kpi-drill"><?= __('view_report', 'View report') ?> <i class="fa-solid fa-arrow-right"></i></span>
            <div class="kpi-label"><?= __('revenue_today', 'Revenue Today') ?></div>
            <div class="kpi-value">$<span id="kpiRevenue"><?= number_format($sales, 2) ?></span></div>
        </a>

        <!-- Orders Today -->
        <a href="view_order.php?tab=all" class="kpi-card c-blue" title="Open the order board">
            <i class="kpi-watermark fa-solid fa-receipt"></i>
            <span class="kpi-drill"><?= __('view_orders', 'View orders') ?> <i class="fa-solid fa-arrow-right"></i></span>
            <div class="kpi-label"><?= __('orders_today', 'Orders Today') ?></div>
            <div class="kpi-value"><span id="kpiOrders"><?= (int)$total_orders ?></span></div>
            <span class="kpi-pill flat">
                <i class="fa-solid fa-circle-check"></i>
                <?= $completed_count ?> <?= __('completed', 'completed') ?>
            </span>
        </a>

        <!-- Items Sold -->
        <a href="report.php?date=<?= urlencode($business_date) ?><?= $user_param ?>" class="kpi-card c-purple" title="Open the item breakdown">
            <i class="kpi-watermark fa-solid fa-mug-hot"></i>
            <span class="kpi-drill"><?= __('view_items', 'View items') ?> <i class="fa-solid fa-arrow-right"></i></span>
            <div class="kpi-label"><?= __('items_sold', 'Items Sold') ?></div>
            <div class="kpi-value"><span id="kpiItems"><?= (int)$items_sold ?></span></div>
            <span class="kpi-pill flat">
                <i class="fa-solid fa-box-open"></i>
                <?= __('from_completed_orders', 'from completed orders') ?>
            </span>
        </a>
    </div>

    <!-- ═══ 2-CARD ANALYTICS CHARTS GRID (Row 1: Category Sales & Sales Trend) ═══ -->
    <div class="dash-charts-grid fu" style="animation-delay:.15s">

        <!-- Panel 1: Expenses / Category Sales (Donut Chart) -->
        <div class="panel chart-panel">
            <div class="panel-head flex items-center justify-between">
                <h3><i class="fa-solid fa-chart-pie text-cyan-400"></i> <?= __('category_sales', 'Category Sales') ?></h3>
                <span class="period-badge"><?= htmlspecialchars($period_badge_label) ?></span>
            </div>
            <div class="chart-body p-4 flex flex-col sm:flex-row items-center justify-center gap-4 min-h-[220px]">
                <div class="relative w-[150px] h-[150px] flex-shrink-0 flex items-center justify-center">
                    <canvas id="chartCategory"></canvas>
                    <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none text-center">
                        <span class="text-[9px] uppercase font-bold tracking-wider text-slate-400" id="catTotalLabel">Items Sold</span>
                        <span class="text-sm font-black text-slate-100" id="catTotalVal">0 items</span>
                    </div>
                </div>
                <div class="cat-legend space-y-1.5 flex-1 w-full text-xs">
                    <!-- Dynamic legend injected by Chart JS -->
                </div>
            </div>
        </div>

        <!-- Panel 2: Sales Trend (Line Chart) -->
        <div class="panel chart-panel">
            <div class="panel-head flex items-center justify-between">
                <h3><i class="fa-solid fa-chart-column text-emerald-400"></i> <?= __('sales_trend', 'Sales Trend') ?></h3>
                <span class="period-badge"><?= __('last_7_days', 'Last 7 Days') ?></span>
            </div>
            <div class="chart-body p-4 min-h-[220px] relative">
                <canvas id="chartSalesTrend" height="180"></canvas>
            </div>
        </div>
    </div>

    <!-- ═══ HOURLY ORDERS (Row 2: Moved to New Separate Row Below) ═══ -->
    <div class="fu" style="animation-delay:.2s; margin-bottom:18px;">
        <div class="panel chart-panel">
            <div class="panel-head flex items-center justify-between">
                <h3><i class="fa-solid fa-clock text-blue-400"></i> <?= __('hourly_orders', 'Hourly Orders') ?></h3>
                <span class="period-badge"><?= htmlspecialchars($period_badge_label) ?></span>
            </div>
            <div class="chart-body p-4 min-h-[220px] relative">
                <canvas id="chartStatus" height="180"></canvas>
            </div>
        </div>
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
    <?php endif; /* end inventory_clerk outer check */ ?>

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


/* ── Mobile sidebar overlay helper ── */
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
    if (typeof initCharts === 'function') initCharts();
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

/* ── TAB SWITCHING ── */
function switchDashTab(tabName, btn) {
    document.querySelectorAll('.dash-tab-btn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    
    const tk = document.getElementById('tabKitchenContent');
    const tr = document.getElementById('tabRecentContent');
    if (tk) tk.style.display = tabName === 'kitchen' ? 'block' : 'none';
    if (tr) tr.style.display = tabName === 'recent' ? 'block' : 'none';
}

/* ── CHART INITIALIZATION & UPDATES ── */
var chartCategoryObj = null;
var chartStatusObj = null;
var chartSalesTrendObj = null;

var initChartData = {
    trendLabels: <?= json_encode($chart_7days_labels) ?>,
    trendRevenue: <?= json_encode($chart_7days_revenue) ?>,
    trendProfit: <?= json_encode($chart_7days_profit) ?>,
    catLabels: <?= json_encode($chart_cat_labels) ?>,
    catSales: <?= json_encode($chart_cat_sales) ?>,
    hourlyLabels: <?= json_encode($chart_hourly_labels) ?>,
    hourlyCounts: <?= json_encode($chart_hourly_counts) ?>,
    hourlySales: <?= json_encode($chart_hourly_sales) ?>
};

function initCharts() {
    const isLight = document.documentElement.getAttribute('data-theme') === 'light';
    const textColor = isLight ? '#64748b' : '#94a3b8';
    const gridColor = isLight ? 'rgba(0,0,0,0.05)' : 'rgba(255,255,255,0.06)';
    const tooltipBg = isLight ? 'rgba(255, 255, 255, 0.96)' : 'rgba(15, 23, 42, 0.95)';
    const tooltipText = isLight ? '#0f172a' : '#f8fafc';
    const tooltipSub = isLight ? '#475569' : '#cbd5e1';
    const tooltipBorder = isLight ? '#e2e8f0' : 'rgba(255,255,255,0.1)';

    // Cleanup existing charts if re-initializing on theme toggle
    if (chartCategoryObj) { chartCategoryObj.destroy(); chartCategoryObj = null; }
    if (chartStatusObj) { chartStatusObj.destroy(); chartStatusObj = null; }
    if (chartSalesTrendObj) { chartSalesTrendObj.destroy(); chartSalesTrendObj = null; }
    
    // 1. Donut Chart (Categories)
    const ctxCat = document.getElementById('chartCategory');
    if (ctxCat) {
        const catColors = ['#d1904b', '#3498db', '#55e087', '#9b59b6', '#ff6b6b', '#f1c40f'];
        chartCategoryObj = new Chart(ctxCat, {
            type: 'doughnut',
            data: {
                labels: initChartData.catLabels,
                datasets: [{
                    data: initChartData.catSales,
                    backgroundColor: catColors,
                    borderWidth: 3,
                    borderColor: isLight ? '#ffffff' : '#111111',
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: tooltipBg,
                        titleColor: tooltipText,
                        bodyColor: tooltipSub,
                        borderColor: tooltipBorder,
                        borderWidth: 1,
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(context) {
                                const val = context.parsed || 0;
                                return ` ${context.label}: ${val} item${val === 1 ? '' : 's'}`;
                            }
                        }
                    }
                },
                cutout: '72%'
            }
        });
        renderCatLegend(initChartData.catLabels, initChartData.catSales, catColors);
    }
    
    // 2. Bar Chart (Hourly Orders)
    const ctxStat = document.getElementById('chartStatus');
    if (ctxStat) {
        const ctx2d = ctxStat.getContext('2d');
        const barGradient = ctx2d.createLinearGradient(0, 0, 0, 180);
        barGradient.addColorStop(0, 'rgba(52, 152, 219, 0.90)');
        barGradient.addColorStop(1, 'rgba(52, 152, 219, 0.20)');

        chartStatusObj = new Chart(ctxStat, {
            type: 'bar',
            data: {
                labels: initChartData.hourlyLabels,
                datasets: [{
                    label: 'Orders',
                    data: initChartData.hourlyCounts,
                    salesData: initChartData.hourlySales,
                    backgroundColor: barGradient,
                    hoverBackgroundColor: '#3498db',
                    borderRadius: { topLeft: 6, topRight: 6, bottomLeft: 0, bottomRight: 0 },
                    borderSkipped: false,
                    maxBarThickness: 26
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: tooltipBg,
                        titleColor: tooltipText,
                        bodyColor: tooltipSub,
                        borderColor: tooltipBorder,
                        borderWidth: 1,
                        padding: 10,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            title: function(items) {
                                return `Hour: ${items[0].label}`;
                            },
                            label: function(context) {
                                const count = context.parsed.y || 0;
                                const sales = (context.dataset.salesData && context.dataset.salesData[context.dataIndex]) || 0;
                                return `Orders: ${count}  •  Sales: $${parseFloat(sales).toFixed(2)}`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: {
                            color: textColor,
                            font: { family: 'Poppins', size: 10, weight: '500' },
                            maxRotation: 0,
                            minRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: 12
                        },
                        grid: { display: false }
                    },
                    y: {
                        ticks: {
                            color: textColor,
                            font: { family: 'Poppins', size: 10 },
                            precision: 0,
                            stepSize: 1
                        },
                        grid: { color: gridColor, borderDash: [3, 3] },
                        beginAtZero: true
                    }
                }
            }
        });
    }

    // 3. Bar Chart (Sales Trend)
    const ctxTrend = document.getElementById('chartSalesTrend');
    if (ctxTrend) {
        chartSalesTrendObj = new Chart(ctxTrend, {
            type: 'bar',
            data: {
                labels: initChartData.trendLabels,
                datasets: [
                    {
                        label: 'Revenue ($)',
                        data: initChartData.trendRevenue,
                        backgroundColor: '#d1904b',
                        borderColor: '#d1904b',
                        borderRadius: 4,
                        maxBarThickness: 24
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        align: 'end',
                        labels: {
                            color: textColor,
                            font: { family: 'Poppins', size: 11, weight: '500' },
                            usePointStyle: true,
                            pointStyle: 'circle',
                            boxWidth: 7,
                            padding: 12
                        }
                    },
                    tooltip: {
                        backgroundColor: tooltipBg,
                        titleColor: tooltipText,
                        bodyColor: tooltipSub,
                        borderColor: tooltipBorder,
                        borderWidth: 1,
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(context) {
                                return ` ${context.dataset.label}: $${parseFloat(context.parsed.y).toFixed(2)}`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: textColor, font: { family: 'Poppins', size: 10 } },
                        grid: { display: false }
                    },
                    y: {
                        ticks: {
                            color: textColor,
                            font: { family: 'Poppins', size: 10 },
                            callback: function(val) { return '$' + val; }
                        },
                        grid: { color: gridColor, borderDash: [3, 3] },
                        beginAtZero: true
                    }
                }
            }
        });
    }
}

function renderCatLegend(labels, data, colors) {
    const el = document.querySelector('.cat-legend');
    const totalEl = document.getElementById('catTotalVal');
    if (!el) return;
    const total = data.reduce((a, b) => a + b, 0);
    if (totalEl) totalEl.textContent = total + (total === 1 ? ' item' : ' items');

    const isLight = document.documentElement.getAttribute('data-theme') === 'light';
    const labelClass = isLight ? 'text-slate-700' : 'text-slate-300';
    const valClass = isLight ? 'text-slate-900' : 'text-slate-100';
    const pctClass = isLight ? 'text-slate-600 bg-slate-100' : 'text-slate-400 bg-white/5';
    const hoverClass = isLight ? 'hover:bg-slate-100' : 'hover:bg-white/5';

    el.innerHTML = labels.map((l, i) => {
        const val = data[i] || 0;
        const pct = total > 0 ? Math.round((val / total) * 100) : 0;
        return `<div class="flex items-center justify-between gap-2 p-1 rounded-lg transition-colors ${hoverClass}">
            <span class="flex items-center gap-2 truncate">
                <span class="w-2.5 h-2.5 rounded-full inline-block flex-shrink-0 shadow-sm" style="background:${colors[i % colors.length]}"></span>
                <span class="truncate ${labelClass} font-medium text-xs">${l}</span>
            </span>
            <span class="font-bold ${valClass} text-xs flex items-center gap-1.5 whitespace-nowrap">
                ${val} ${val === 1 ? 'item' : 'items'}
                <span class="text-[10px] font-semibold ${pctClass} px-1.5 py-0.5 rounded-full">${pct}%</span>
            </span>
        </div>`;
    }).join('');
}

function updateChartsFromAjax(chartsData) {
    if (!chartsData) return;
    
    if (chartCategoryObj && chartsData.cat_labels) {
        chartCategoryObj.data.labels = chartsData.cat_labels;
        chartCategoryObj.data.datasets[0].data = chartsData.cat_sales;
        chartCategoryObj.update();
        const catColors = ['#d1904b', '#3498db', '#55e087', '#9b59b6', '#ff6b6b', '#f1c40f'];
        renderCatLegend(chartsData.cat_labels, chartsData.cat_sales, catColors);
    }
    
    if (chartStatusObj && chartsData.hourly_labels) {
        chartStatusObj.data.labels = chartsData.hourly_labels;
        chartStatusObj.data.datasets[0].data = chartsData.hourly_counts;
        chartStatusObj.data.datasets[0].salesData = chartsData.hourly_sales;
        chartStatusObj.update();
    }
    
    if (chartSalesTrendObj && chartsData.trend_labels) {
        chartSalesTrendObj.data.labels = chartsData.trend_labels;
        chartSalesTrendObj.data.datasets[0].data = chartsData.trend_revenue;
        chartSalesTrendObj.data.datasets[1].data = chartsData.trend_profit;
        chartSalesTrendObj.update();
    }
}

document.addEventListener('DOMContentLoaded', initCharts);

/* ── AJAX polling (kitchen + KPIs) ── */
var OVERDUE_MINUTES = <?= (int)OVERDUE_MINUTES ?>;
var WARN_MINUTES    = Math.max(1, Math.floor(OVERDUE_MINUTES * 0.7));
function fetchDashboardData(){
    const form = document.getElementById('dashFilterForm');
    const params = form ? new URLSearchParams(new FormData(form)).toString() : '';
    const url = 'dashboard_data.php' + (params ? '?' + params : '');

    fetch(url)
        .then(r=>r.json())
        .then(d=>{
            const lu=document.getElementById('lastUpdated');
            if(lu) lu.textContent=new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});

            const rev=document.getElementById('kpiRevenue');
            const prf=document.getElementById('kpiProfit');
            const ord=document.getElementById('kpiOrders');
            const itm=document.getElementById('kpiItems');
            const mgn=document.getElementById('kpiMargin');
            if(rev) rev.textContent=parseFloat(d.sales).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
            if(prf && d.profit_today!==undefined) prf.textContent=parseFloat(d.profit_today).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
            if(ord) ord.textContent=d.total_orders;
            if(itm && d.items_sold!==undefined) itm.textContent=d.items_sold;
            if(mgn && d.margin_today!==undefined) mgn.textContent=d.margin_today;

            if (d.period_badge_label) {
                document.querySelectorAll('.period-badge').forEach(el => {
                    if (!el.textContent.includes('7 Days')) {
                        el.textContent = d.period_badge_label;
                    }
                });
            }

            const pnlAmt = document.getElementById('pnlAmount');
            const pnlInc = document.getElementById('pnlIncome');
            const pnlCogs = document.getElementById('pnlCogs');
            const pnlMarg = document.getElementById('pnlMarginRate');
            const pnlMargTop = document.getElementById('pnlMarginRateTop');
            const pnlGross = document.getElementById('pnlGross');
            const pnlCogsBar = document.getElementById('pnlCogsBar');
            if(pnlAmt && d.profit_today!==undefined) pnlAmt.textContent = '$' + parseFloat(d.profit_today).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
            if(pnlInc && d.sales!==undefined) pnlInc.textContent = '$' + parseFloat(d.sales).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
            if(pnlCogs && d.cogs_today!==undefined) pnlCogs.textContent = '$' + parseFloat(d.cogs_today).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
            if(pnlMarg && d.margin_today!==undefined) pnlMarg.textContent = d.margin_today + '%';
            if(pnlMargTop && d.margin_today!==undefined) pnlMargTop.textContent = d.margin_today + '% Margin';
            if(pnlGross && d.profit_today!==undefined) pnlGross.textContent = '$' + parseFloat(d.profit_today).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
            if(pnlCogsBar && d.sales>0 && d.cogs_today!==undefined) {
                pnlCogsBar.style.width = Math.min(100, Math.round((parseFloat(d.cogs_today)/parseFloat(d.sales))*100)) + '%';
            }

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

            if(d.charts) updateChartsFromAjax(d.charts);
        })
        .catch(()=>{});
}

document.addEventListener('DOMContentLoaded', function() {
    const qSelect = document.getElementById('dashQuickRange');
    const mSelect = document.getElementById('dashMonthSelect');
    const uSelect = document.getElementById('dashUserSelect');

    if (qSelect) {
        qSelect.addEventListener('change', function() {
            if (this.value && mSelect) mSelect.value = '';
            fetchDashboardData();
        });
    }
    if (mSelect) {
        mSelect.addEventListener('change', function() {
            if (this.value && qSelect) qSelect.value = '';
            fetchDashboardData();
        });
    }
    if (uSelect) {
        uSelect.addEventListener('change', function() {
            fetchDashboardData();
        });
    }
});

function resetDashFilter() {
    const qSelect = document.getElementById('dashQuickRange');
    const mSelect = document.getElementById('dashMonthSelect');
    const uSelect = document.getElementById('dashUserSelect');

    if (qSelect) qSelect.value = 'today';
    if (mSelect) mSelect.value = '';
    if (uSelect) uSelect.value = '0';

    fetchDashboardData();
}

setInterval(fetchDashboardData,5000);
fetchDashboardData();



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
