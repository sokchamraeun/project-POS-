<?php
require 'auth.php';
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Every "today" below means the trading day (06:00 to 06:00), read from the
// business_date column — the same definition dashboard.php renders with.
//
// This file used to filter on DATE(order_date) = CURDATE(). Because it is
// polled every 5 seconds and writes straight into the KPI cards, it quietly
// replaced the correct figures with calendar-date ones a few seconds after
// every page load. The two disagree on 186 orders, and disagree about every
// order between midnight and 06:00 — the tail of a trading day belongs to the
// day before, which is exactly when a late shift is watching this screen.
$admin_name = $_SESSION['emp_name'] ?? $_SESSION['username'] ?? 'Admin';
$_is_mgr = in_array($_SESSION['role'] ?? '', ['admin', 'manager']);
$filter_user = 0;
if ($_is_mgr) {
    $filter_user = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
} else {
    $filter_user = (int)$_SESSION['user_id'];
}

$user_clause_w = "";
$user_clause_o = "";

$_now = new DateTime();
$business_date = (int)$_now->format("H") < 6
    ? (clone $_now)->modify("-1 day")->format("Y-m-d")
    : $_now->format("Y-m-d");

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
    $date_cond_w = "DATE(order_date) = '$date_start'";
    $date_cond_o = "DATE(o.order_date) = '$date_start'";
} else {
    $date_cond_w = "DATE(order_date) BETWEEN '$date_start' AND '$date_end'";
    $date_cond_o = "DATE(o.order_date) BETWEEN '$date_start' AND '$date_end'";
}

// ── TODAY SALES ──
$sales_sql = "
SELECT IFNULL(SUM(total),0) AS total_sales
FROM orders
WHERE $date_cond_w AND " . paid_orders_where() . "
";
$sales = (float)$conn->query($sales_sql)->fetch_assoc()['total_sales'];

// ── TOTAL ORDERS TODAY ──
$total_orders = (int)$conn->query("SELECT COUNT(*) AS total_orders FROM orders WHERE $date_cond_w")->fetch_assoc()['total_orders'];

// ── UNPAID ORDERS COUNT ──
$unpaid_count = 0;

// ── LOW STOCK ──
$low_stock = 0;

// ── REFUND DATA (removed) ──
$total_refunds = 0;
$refund_count = 0;

// ── STATUS COUNTS ──
$status_counts = ['Completed' => $total_orders];
$pending_count = 0;
$paid_count = $total_orders;
$preparing_count = 0;
$completed_count = $total_orders;
$cancelled_count = 0;

// ── UNPAID ORDERS LIST (LIMIT 5) ──
$unpaid_orders = [];

// ── KITCHEN QUEUE (LIMIT 5) ──
$kitchen_orders = [];

// ── ITEMS SOLD TODAY ──
$stmt_items = $conn->query("SELECT IFNULL(SUM(oi.quantity),0) AS total_items FROM order_items oi JOIN orders o ON oi.order_id=o.order_id WHERE $date_cond_o " . $user_clause_o . " AND oi.product_id <> 0 AND " . paid_orders_where('o'));
$items_sold = (int)$stmt_items->fetch_assoc()['total_items'];

// ── PROFIT TODAY ──
$cogs_today = 0.0;
$profit_today = $sales - $cogs_today;
$margin_today = $sales > 0 ? round(($profit_today / $sales) * 100, 1) : 0;

// ── CHART DATA (LAST 7 DAYS TREND) ──
$chart_7days_labels  = [];
$chart_7days_revenue = [];
$chart_7days_profit  = [];
for ($i = 6; $i >= 0; $i--) {
    $d_date = (new DateTime($business_date))->modify("-$i days")->format("Y-m-d");
    $d_label = (new DateTime($d_date))->format("D (j/n)");
    
    $st_rev = $conn->query("SELECT IFNULL(SUM(total),0) AS rev FROM orders WHERE DATE(order_date)='$d_date' " . $user_clause_w . " AND " . paid_orders_where());
    $d_rev = (float)$st_rev->fetch_assoc()['rev'];
    
    $d_cogs = 0.0;
    
    $chart_7days_labels[]  = $d_label;
    $chart_7days_revenue[] = round($d_rev, 2);
    $chart_7days_profit[]  = round(max(0, $d_rev - $d_cogs), 2);
}

// ── CATEGORY SALES BREAKDOWN ──
$chart_cat_labels = [];
$chart_cat_sales  = [];
$st_cat = $conn->query("
    SELECT COALESCE(NULLIF(p.category, ''), 'Other') AS cat_name, IFNULL(SUM(oi.quantity), 0) AS total_qty
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.order_id
    JOIN products p ON oi.product_id = p.product_id
    WHERE " . paid_orders_where('o') . " AND $date_cond_o " . $user_clause_o . " AND oi.product_id <> 0
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
    FROM orders
    WHERE $date_cond_w " . $user_clause_w . " AND " . paid_orders_where() . "
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

// ── RETURN JSON ──
header('Content-Type: application/json');
echo json_encode([
    'period_badge_label' => $period_badge_label,
    'sales' => number_format($sales, 2),
    'profit_today' => number_format($profit_today, 2),
    'margin_today' => $margin_today,
    'cogs_today' => number_format($cogs_today, 2),
    'total_orders' => $total_orders,
    'items_sold' => $items_sold,
    'unpaid_count' => $unpaid_count,
    'low_stock' => $low_stock,
    'total_refunds' => number_format($total_refunds, 2),
    'refund_count' => $refund_count,
    'pending_count' => $pending_count,
    'paid_count' => $paid_count,
    'preparing_count' => $preparing_count,
    'completed_count' => $completed_count,
    'cancelled_count' => $cancelled_count,
    'unpaid_orders' => $unpaid_orders,
    'kitchen_orders' => $kitchen_orders,
    'charts' => [
        'trend_labels' => $chart_7days_labels,
        'trend_revenue' => $chart_7days_revenue,
        'trend_profit' => $chart_7days_profit,
        'cat_labels' => $chart_cat_labels,
        'cat_sales' => $chart_cat_sales,
        'hourly_labels' => $chart_hourly_labels,
        'hourly_counts' => $chart_hourly_counts,
        'hourly_sales' => $chart_hourly_sales
    ]
]);
?>