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
$business_date = business_date_today();

// ── TODAY SALES ──
$sales_sql = "
SELECT IFNULL(SUM(total),0) AS total_sales
FROM orders
WHERE business_date = ? AND " . paid_orders_where() . "
";
$stmt = $conn->prepare($sales_sql);
$stmt->bind_param("s", $business_date);
$stmt->execute();
$sales = $stmt->get_result()->fetch_assoc()['total_sales'];

// ── TOTAL ORDERS TODAY ──
$stmt = $conn->prepare("SELECT COUNT(*) AS total_orders FROM orders WHERE business_date = ?");
$stmt->bind_param("s", $business_date);
$stmt->execute();
$total_orders = $stmt->get_result()->fetch_assoc()['total_orders'];

// ── UNPAID ORDERS COUNT ──
$unpaid_sql = "
SELECT COUNT(*) AS unpaid_count
FROM orders
WHERE status = 'PendingPayment'
";
$unpaid_result = mysqli_query($conn, $unpaid_sql);
$unpaid_count = mysqli_fetch_assoc($unpaid_result)['unpaid_count'];

// ── LOW STOCK ──
$low_sql = "
SELECT COUNT(*) AS low_count
FROM ingredients
WHERE stock_quantity < minimum_stock
";
$low_result = mysqli_query($conn, $low_sql);
$low_stock = mysqli_fetch_assoc($low_result)['low_count'];

// ── REFUND DATA (NEW) ──
$refund_sql = "
SELECT
    IFNULL(SUM(refund_amount), 0) AS total_refunds,
    COUNT(*) AS refund_count
FROM order_refunds
WHERE DATE(refunded_at) = CURDATE()
";
$refund_result = mysqli_query($conn, $refund_sql);
$refund_data = mysqli_fetch_assoc($refund_result);
$total_refunds = $refund_data['total_refunds'];
$refund_count = $refund_data['refund_count'];

// ── STATUS COUNTS ──
$stmt = $conn->prepare("SELECT status, COUNT(*) as count FROM orders WHERE business_date = ? GROUP BY status");
$stmt->bind_param("s", $business_date);
$stmt->execute();
$status_result = $stmt->get_result();
$status_counts = [];
while ($row = mysqli_fetch_assoc($status_result)) {
    $status_counts[$row['status']] = $row['count'];
}

$pending_count = $status_counts['PendingPayment'] ?? 0;
$paid_count = $status_counts['Preparing'] ?? 0;
$preparing_count = $status_counts['Preparing'] ?? 0;
$completed_count = $status_counts['Completed'] ?? 0;
$cancelled_count = $status_counts['Cancelled'] ?? 0;

// ── UNPAID ORDERS LIST (LIMIT 5) ──
$unpaid_orders_sql = "
SELECT order_id, daily_order_no, customer_name, total, status, DATE_FORMAT(order_date, '%d %b %H:%i') as date, is_open
FROM orders
WHERE status = 'PendingPayment'
ORDER BY order_date DESC
LIMIT 5
";
$unpaid_orders_result = mysqli_query($conn, $unpaid_orders_sql);
$unpaid_orders = [];
while ($row = mysqli_fetch_assoc($unpaid_orders_result)) {
    $unpaid_orders[] = $row;
}

// ── KITCHEN QUEUE (LIMIT 5) ──
$stmt = $conn->prepare("
SELECT order_id, daily_order_no, customer_name, total, token_number, order_date
FROM orders
WHERE business_date = ?
AND status = 'Preparing'
ORDER BY order_date ASC
LIMIT 5
");
$stmt->bind_param("s", $business_date);
$stmt->execute();
$kitchen_result = $stmt->get_result();
$kitchen_orders = [];
while ($row = mysqli_fetch_assoc($kitchen_result)) {
    $kitchen_orders[] = $row;
}

// ── ITEMS SOLD TODAY ──
$stmt_items = $conn->prepare("SELECT IFNULL(SUM(oi.quantity),0) AS total_items FROM order_items oi JOIN orders o ON oi.order_id=o.order_id WHERE o.business_date=? AND oi.product_id <> 0 AND " . paid_orders_where('o'));
$stmt_items->bind_param("s", $business_date);
$stmt_items->execute();
$items_sold = (int)$stmt_items->get_result()->fetch_assoc()['total_items'];

// ── PROFIT TODAY ──
$stmt_cogs = $conn->prepare("
    SELECT IFNULL(SUM(
        oi.quantity * (
            SELECT IFNULL(SUM(pi.amount_used * COALESCE(NULLIF(i.cost_per_unit, 0), CASE WHEN i.purchase_qty > 0 THEN i.cost_price / i.purchase_qty ELSE 0 END, 0)), 0)
            FROM product_ingredients pi
            JOIN ingredients i ON i.ingredient_id = pi.ingredient_id
            WHERE pi.product_id = oi.product_id
        )
    ), 0) AS total_cogs
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.order_id
    WHERE o.business_date = ? AND oi.product_id <> 0 AND " . paid_orders_where('o')
);
$stmt_cogs->bind_param("s", $business_date);
$stmt_cogs->execute();
$cogs_today = (float)($stmt_cogs->get_result()->fetch_assoc()['total_cogs'] ?? 0);
$profit_today = $sales - $cogs_today;
$margin_today = $sales > 0 ? round(($profit_today / $sales) * 100, 1) : 0;

// ── CHART DATA (LAST 7 DAYS TREND) ──
$chart_7days_labels  = [];
$chart_7days_revenue = [];
$chart_7days_profit  = [];
for ($i = 6; $i >= 0; $i--) {
    $d_date = (new DateTime($business_date))->modify("-$i days")->format("Y-m-d");
    $d_label = (new DateTime($d_date))->format("D (j/n)");
    
    $st_rev = $conn->prepare("SELECT IFNULL(SUM(total),0) AS rev FROM orders WHERE business_date=? AND " . paid_orders_where());
    $st_rev->bind_param("s", $d_date);
    $st_rev->execute();
    $d_rev = (float)$st_rev->get_result()->fetch_assoc()['rev'];
    
    $st_cogs = $conn->prepare("
        SELECT IFNULL(SUM(
            oi.quantity * (
                SELECT IFNULL(SUM(pi.amount_used * COALESCE(NULLIF(i.cost_per_unit, 0), CASE WHEN i.purchase_qty > 0 THEN i.cost_price / i.purchase_qty ELSE 0 END, 0)), 0)
                FROM product_ingredients pi
                JOIN ingredients i ON i.ingredient_id = pi.ingredient_id
                WHERE pi.product_id = oi.product_id
            )
        ), 0) AS total_cogs
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.order_id
        WHERE o.business_date = ? AND oi.product_id <> 0 AND " . paid_orders_where('o')
    );
    $st_cogs->bind_param("s", $d_date);
    $st_cogs->execute();
    $d_cogs = (float)$st_cogs->get_result()->fetch_assoc()['total_cogs'];
    
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
    WHERE " . paid_orders_where('o') . " AND o.business_date = '{$business_date}' AND oi.product_id <> 0
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

$stmt_hr = $conn->prepare("
    SELECT HOUR(order_date) AS hr, COUNT(*) AS cnt, IFNULL(SUM(total), 0) AS total_sales
    FROM orders
    WHERE business_date = ? AND " . paid_orders_where() . "
    GROUP BY hr
");
$stmt_hr->bind_param("s", $business_date);
$stmt_hr->execute();
$res_hr = $stmt_hr->get_result();
while ($r_hr = $res_hr->fetch_assoc()) {
    $h = (int)$r_hr['hr'];
    if (isset($hour_slots[$h])) {
        $hour_slots[$h]['cnt']   = (int)$r_hr['cnt'];
        $hour_slots[$h]['sales'] = round((float)$r_hr['total_sales'], 2);
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