<?php
require 'auth.php';
require 'config.php';
require_once 'lang.php';
if (!can('report_product')) { header("Location: dashboard.php?denied=1"); exit; }

date_default_timezone_set("Asia/Phnom_Penh");

if (!function_exists('businessRangeFromDate')) {
    function businessRangeFromDate(string $dateYmd): array {
        $start = new DateTime($dateYmd . " 06:00:00");
        $end = clone $start;
        $end->modify("+1 day")->modify("-1 second");
        return [$start, $end];
    }
}

if (!function_exists('fmtQty')) {
    function fmtQty($n): string {
        return rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');
    }
}

if (!function_exists('fmtMoney')) {
    function fmtMoney($n): string {
        return number_format((float)$n, 2);
    }
}

if (!function_exists('deltaStr')) {
    function deltaStr(float $current, float $prev): string {
        if ($prev <= 0) return '';
        $pct = round(($current - $prev) / $prev * 100, 1);
        if ($pct === 0.0) return '<span class="delta neutral">= same</span>';
        $cls = $pct > 0 ? 'up' : 'down';
        $arrow = $pct > 0 ? '&#9650;' : '&#9660;';
        return "<span class=\"delta {$cls}\">{$arrow} " . abs($pct) . "%</span>";
    }
}

/* =========================
   INPUT
========================= */
$mode = $_GET['mode'] ?? 'daily';
$filter_user = (int)($_GET['user_id'] ?? $_GET['user'] ?? 0);
$quickRange = trim($_GET['quick_range'] ?? '');
$selectMonth = trim($_GET['select_month'] ?? '');

if (isset($_GET['from_date']) || isset($_GET['to_date']) || isset($_GET['date_from']) || isset($_GET['date_to']) || in_array($quickRange, ['week','this_week','month','this_month','year','this_year']) || !empty($selectMonth)) {
    $mode = 'range';
}

if (!in_array($mode, ['daily', 'monthly', 'range'])) {
    $mode = 'daily';
}

if ($mode === 'monthly') {
    $month = $_GET['month'] ?? (new DateTime())->format("Y-m");

    $start = new DateTime($month . "-01 06:00:00");
    $end = clone $start;
    $end->modify("+1 month")->modify("-1 second");

    $label = $start->format("F Y");

} elseif ($mode === 'range') {
    $fromDate = $_GET['from_date'] ?? $_GET['date_from'] ?? null;
    $toDate   = $_GET['to_date']   ?? $_GET['date_to']   ?? null;

    if ($fromDate === null || $toDate === null) {
        if ($quickRange === 'week' || $quickRange === 'this_week') {
            $fromDate = date('Y-m-d', strtotime('monday this week'));
            $toDate   = date('Y-m-d', strtotime('sunday this week'));
        } elseif ($quickRange === 'month' || $quickRange === 'this_month') {
            $fromDate = date('Y-m-01');
            $toDate   = date('Y-m-t');
        } elseif ($quickRange === 'year' || $quickRange === 'this_year') {
            $fromDate = date('Y-01-01');
            $toDate   = date('Y-12-31');
        } elseif (!empty($selectMonth) && (int)$selectMonth >= 1 && (int)$selectMonth <= 12) {
            $m_num    = sprintf('%02d', (int)$selectMonth);
            $curr_yr  = date('Y');
            $fromDate = "$curr_yr-$m_num-01";
            $toDate   = date('Y-m-t', strtotime($fromDate));
        } else {
            $fromDate = business_date_today();
            $toDate   = business_date_today();
        }
    }
    if ($fromDate > $toDate) [$fromDate, $toDate] = [$toDate, $fromDate];

    $start = new DateTime($fromDate . " 06:00:00");
    $end   = new DateTime($toDate . " 06:00:00");
    $end->modify("+1 day")->modify("-1 second");

    $label = (new DateTime($fromDate))->format("d M Y") .
             " → " .
             (new DateTime($toDate))->format("d M Y");

} else {
    $date = $_GET['date'] ?? business_date_today();

    [$start, $end] = businessRangeFromDate($date);

    $label = (new DateTime($date))->format("d M Y");
}

// ── Daily sales target (configurable in Settings → Sales Target) ──
$dailyTarget = DAILY_SALES_TARGET;

// ── Is this period "live" (includes today)? ──
$_today = business_date_today();
$isLive = match($mode) {
    'monthly' => isset($month)    && $month    === (new DateTime())->format("Y-m"),
    'range'   => isset($toDate)   && $toDate   >= $_today,
    default   => isset($date)     && $date     === $_today,
};
unset($_today);

/* =========================
   LOAD INGREDIENT COST MAP
========================= */
$ingredients = ingredient_cost_map($conn);

/* =========================
   GET COMPLETED ORDERS
========================= */
$startStr = $start->format("Y-m-d H:i:s");
$endStr   = $end->format("Y-m-d H:i:s");

$orderIds = [];
$totalSales = 0;
$orderCount = 0;

$userCond = $filter_user > 0 ? " AND user_id = $filter_user" : "";

$stmt_orders = $conn->prepare("SELECT order_id, total FROM orders WHERE " . paid_orders_where() . " AND order_date BETWEEN ? AND ?" . $userCond);
$stmt_orders->bind_param("ss", $startStr, $endStr);
$stmt_orders->execute();
$qOrders = $stmt_orders->get_result();

while ($o = mysqli_fetch_assoc($qOrders)) {
    $id = (int)$o['order_id'];
    $orderIds[] = $id;
    $totalSales += (float)$o['total'];
    $orderCount++;
}
$avgOrder = $orderCount > 0 ? $totalSales / $orderCount : 0;

$totalCOGS = 0;
$totalProfit = 0;
$margin = 0;
$totalItemsSold = 0;
$topProducts = [];
$categorySales = [];

if (count($orderIds) > 0) {
    $inOrder = implode(",", array_map('intval', $orderIds));

    $items = [];
    $productIds = [];

    $qItems = mysqli_query($conn, "
        SELECT
            oi.order_id,
            oi.product_id,
            oi.product_name,
            oi.milk,
            oi.quantity,
            oi.price,
            COALESCE(oi.orig_price, oi.price) AS orig_price,
            COALESCE(oi.promo_percent, 0) AS promo_percent,
            COALESCE(NULLIF(p.category, ''), 'Uncategorized') AS category,
            COALESCE(p.cost_price, 0) AS cost_price
        FROM order_items oi
        LEFT JOIN products p ON p.product_id = oi.product_id
        WHERE oi.order_id IN ($inOrder)
          AND oi.price > 0
    ");

    while ($it = mysqli_fetch_assoc($qItems)) {
        $items[] = $it;
        $pid = (int)$it['product_id'];

        if ($pid > 0) {
            $productIds[$pid] = true;
        }
    }

    $recipes = [];

    foreach ($items as $it) {
        $pid      = (int)$it['product_id'];
        $qty      = (int)$it['quantity'];
        $milkType = trim((string)$it['milk']);
        $pname    = (string)$it['product_name'];
        $category = trim((string)$it['category']);

        if ($category === '') {
            $category = 'Uncategorized';
        }

        if ($qty <= 0) {
            $qty = 1;
        }

        $itemCost = 0;

        if (isset($recipes[$pid])) {
            foreach ($recipes[$pid] as $rc) {
                $iname  = strtolower(trim($rc['ingredient_name']));
                $amount = (float)$rc['amount_used'] * $qty;

                if ($amount <= 0) {
                    continue;
                }

                if (strpos($iname, 'milk') !== false) {
                    if ($milkType === '') {
                        $milkType = 'Fresh Milk';
                    }

                    $key = strtolower(trim($milkType));

                    if (isset($ingredients[$key])) {
                        $unitCost = (float)$ingredients[$key]['unit_cost'];
                        $itemCost += ($amount * $unitCost);
                    }
                } else {
                    $iid = (int)$rc['ingredient_id'];
                    $unitCost = isset($ingredients[$iid]) ? (float)$ingredients[$iid]['unit_cost'] : 0;
                    $itemCost += ($amount * $unitCost);
                }
            }
        }

        $p_unit_cost = (float)($it['cost_price'] ?? 0);
        if ($itemCost <= 0 && $p_unit_cost > 0) {
            $itemCost = $p_unit_cost * $qty;
        }

        $totalCOGS     += $itemCost;
        $totalItemsSold += $qty;

        $itemRevenue = (float)($it['price'] ?? 0) * $qty;

        $origPrice = (float)($it['orig_price'] > 0 ? $it['orig_price'] : $it['price']);
        $sellingPrice = (float)($it['price'] ?? 0);
        $promoPct = (float)($it['promo_percent'] ?? 0);
        $itemDiscUnit = 0;
        if ($origPrice > $sellingPrice) {
            $itemDiscUnit = $origPrice - $sellingPrice;
        } elseif ($promoPct > 0) {
            $itemDiscUnit = $sellingPrice * ($promoPct / 100);
        }
        $itemTotalDisc = $itemDiscUnit * $qty;

        if (!isset($topProducts[$pname])) {
            $topProducts[$pname] = [
                "qty" => 0, 
                "cogs" => 0, 
                "revenue" => 0, 
                "discount" => 0, 
                "category" => $category,
                "unit_cost" => $p_unit_cost,
                "unit_price" => (float)($it['price'] ?? 0)
            ];
        }

        $topProducts[$pname]["qty"]      += $qty;
        $topProducts[$pname]["cogs"]     += $itemCost;
        $topProducts[$pname]["revenue"]  += $itemRevenue;
        $topProducts[$pname]["discount"] += $itemTotalDisc;
        if ($p_unit_cost > 0) {
            $topProducts[$pname]["unit_cost"] = $p_unit_cost;
        }

        if (!isset($categorySales[$category])) {
            $categorySales[$category] = [
                "qty" => 0,
                "cogs" => 0
            ];
        }

        $categorySales[$category]["qty"] += $qty;
        $categorySales[$category]["cogs"] += $itemCost;
    }

    $totalProfit = $totalSales - $totalCOGS;
    $margin = ($totalSales > 0) ? (($totalProfit / $totalSales) * 100) : 0;

    uasort($topProducts, function($a, $b) {
        return $b["qty"] <=> $a["qty"];
    });

    uasort($categorySales, function($a, $b) {
        return $b["qty"] <=> $a["qty"];
    });
}

/* =========================
   HOURLY BREAKDOWN (daily only)
========================= */
$hourlyData = [];
$peakHour   = null;
if ($mode === 'daily') {
    $qHourly = mysqli_query($conn, "
        SELECT HOUR(order_date) as h, COUNT(*) as cnt, SUM(total) as rev
        FROM orders
        WHERE " . paid_orders_where() . "
          AND order_date BETWEEN '$startStr' AND '$endStr'
        GROUP BY HOUR(order_date)
        ORDER BY h ASC
    ");
    $hourMap = [];
    while ($r = mysqli_fetch_assoc($qHourly)) {
        $hourMap[(int)$r['h']] = ['count' => (int)$r['cnt'], 'revenue' => (float)$r['rev']];
    }
    $maxRev = 0;
    for ($h = 6; $h <= 22; $h++) {
        $rev = $hourMap[$h]['revenue'] ?? 0;
        $hourlyData[] = [
            'label'   => sprintf('%02d:00', $h),
            'count'   => $hourMap[$h]['count']   ?? 0,
            'revenue' => $rev,
        ];
        if ($rev > $maxRev) { $maxRev = $rev; $peakHour = date('g:i A', mktime($h, 0, 0)); }
    }
}

/* =========================
   DAILY TREND (monthly / range)
========================= */
$dailyTrendData = [];
if ($mode !== 'daily') {
    $qTrend = mysqli_query($conn, "
        SELECT DATE(order_date) as d, COUNT(*) as cnt, SUM(total) as rev
        FROM orders
        WHERE " . paid_orders_where() . "
          AND order_date BETWEEN '$startStr' AND '$endStr'
        GROUP BY DATE(order_date)
        ORDER BY d ASC
    ");
    while ($r = mysqli_fetch_assoc($qTrend)) {
        $dailyTrendData[] = [
            'label'   => date('M d', strtotime($r['d'])),
            'count'   => (int)$r['cnt'],
            'revenue' => (float)$r['rev'],
        ];
    }
}

/* =========================
   PAYMENT METHOD BREAKDOWN
========================= */
$paymentMethods = [];
if (count($orderIds) > 0) {
    $qPay = mysqli_query($conn, "
        SELECT payment_method, COUNT(*) as cnt, SUM(total) as rev
        FROM orders
        WHERE " . paid_orders_where() . "
          AND order_date BETWEEN '$startStr' AND '$endStr'
        GROUP BY payment_method
        ORDER BY rev DESC
    ");
    while ($r = mysqli_fetch_assoc($qPay)) {
        $paymentMethods[] = [
            'method'  => ucfirst($r['payment_method']),
            'count'   => (int)$r['cnt'],
            'revenue' => (float)$r['rev'],
        ];
    }
}

/* =========================
   GET REFUND DATA (NEW)
========================= */
$totalRefunded = 0;
$refundCount = 0;
$refundOrders = [];
$netRevenue = $totalSales - $totalRefunded;

/* =========================
   GET REMAKE DATA (removed)
========================= */
$remakeCount  = 0;
$remakeOrders = [];

/* =========================
   PREVIOUS PERIOD COMPARISON
========================= */
$prevSales = 0.0; $prevOrderCount = 0;
if ($mode === 'daily') {
    $prevD     = (new DateTime($date))->modify('-1 day');
    $prevStart2 = new DateTime($prevD->format('Y-m-d') . ' 06:00:00');
    $prevEnd2   = clone $prevStart2; $prevEnd2->modify('+1 day')->modify('-1 second');
} elseif ($mode === 'monthly') {
    $prevStart2 = new DateTime($month . '-01 06:00:00'); $prevStart2->modify('-1 month');
    $prevEnd2   = clone $prevStart2; $prevEnd2->modify('+1 month')->modify('-1 second');
} else {
    $rangeDays  = (new DateTime($fromDate))->diff(new DateTime($toDate))->days + 1;
    $prevEnd2   = new DateTime($fromDate . ' 06:00:00'); $prevEnd2->modify('-1 second');
    $prevStart2 = clone $prevEnd2; $prevStart2->modify('+1 second')->modify("-{$rangeDays} days");
}
$prevStartStr2 = $prevStart2->format('Y-m-d H:i:s');
$prevEndStr2   = $prevEnd2->format('Y-m-d H:i:s');
$qPrev = mysqli_query($conn, "SELECT COUNT(*) as cnt, COALESCE(SUM(total),0) as rev FROM orders WHERE " . paid_orders_where() . " AND order_date BETWEEN '$prevStartStr2' AND '$prevEndStr2'");
if ($rp = mysqli_fetch_assoc($qPrev)) { $prevOrderCount = (int)$rp['cnt']; $prevSales = (float)$rp['rev']; }
$deltaOrders = deltaStr((float)$orderCount, (float)$prevOrderCount);
$deltaSales  = deltaStr($totalSales, $prevSales);

/* =========================
   GET DAILY REFUNDS FOR CHART (removed)
========================= */
$refundChartData = [];
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8" />
<script>(function(){if(localStorage.getItem('theme')==='light')document.documentElement.setAttribute('data-theme','light');})();</script>
<title>Product Report | Bird's Nest Coffee</title>
<meta name="viewport" content="width=device-width,initial-scale=1" />
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="flex h-screen w-full overflow-hidden app-layout">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<main class="app-main flex-1 h-full overflow-y-auto er-container">
    <?php
    $report_category = 'Products';
    $report_title    = 'Product Report';
    $date_from       = $start->format('Y-m-d');
    $date_to         = $end->format('Y-m-d');

    // Fetch category slug -> display name map from categories table
    $cat_slug_to_name = [];
    $q_cats = mysqli_query($conn, "SELECT slug, name FROM categories ORDER BY display_order, category_id ASC");
    if ($q_cats) {
        while ($cr = mysqli_fetch_assoc($q_cats)) {
            $cat_slug_to_name[$cr['slug']] = !empty($cr['name']) ? $cr['name'] : $cr['slug'];
        }
    }

    $cat_options = ['' => 'All Categories'];
    $q_prod_cats = mysqli_query($conn, "SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category <> ''");
    if ($q_prod_cats) {
        while ($pcr = mysqli_fetch_assoc($q_prod_cats)) {
            $slug = $pcr['category'];
            $cat_options[$slug] = $cat_slug_to_name[$slug] ?? $slug;
        }
    }
    foreach ($cat_slug_to_name as $slug => $name) {
        if (!isset($cat_options[$slug])) {
            $cat_options[$slug] = $name;
        }
    }
    $selected_cat = trim($_GET['category'] ?? '');

    $user_options = ['' => 'All Staff'];
    $q_users = $conn->query("SELECT u.user_id, u.username, COALESCE(NULLIF(u.name, ''), u.username) AS display_name FROM users u ORDER BY u.username ASC");
    if ($q_users) {
        while ($ur = $q_users->fetch_assoc()) {
            $displayName = $ur['display_name'] ?? $ur['username'];
            $user_options[$ur['user_id']] = $displayName;
        }
    }

    $filter_options  = [
        [
            'name' => 'user_id',
            'label' => 'Staff Member',
            'options' => $user_options,
            'selected' => $filter_user
        ],
        [
            'name' => 'category',
            'label' => 'Category',
            'options' => $cat_options,
            'selected' => $selected_cat
        ]
    ];
    $isKm = (current_lang() === 'km');
    $lbl_table_title  = $isKm ? 'ការវិភាគចំណូល & ប្រាក់ចំណេញតាមមុខទំនិញ' : 'Revenue & Profit Analysis by Product';
    $lbl_search_ph    = $isKm ? 'ស្វែងរកទំនិញ...' : 'Search product...';
    $lbl_col_product  = $isKm ? 'ឈ្មោះទំនិញ (PRODUCT)' : 'Product (PRODUCT)';
    $lbl_col_cat      = $isKm ? 'ប្រភេទ' : 'Category';
    $lbl_col_qty      = $isKm ? 'ចំនួនលក់' : 'Qty Sold';
    $lbl_col_cost_cup = $isKm ? 'ថ្លៃដើម/កែវ' : 'Cost/Cup';
    $lbl_col_pr_cup   = $isKm ? 'តម្លៃលក់/កែវ' : 'Price/Cup';
    $lbl_col_rev      = $isKm ? 'ចំណូលសរុប (REV)' : 'Total Rev (REV)';
    $lbl_col_cost     = $isKm ? 'ថ្លៃដើមសរុប (COST)' : 'Total Cost (COST)';
    $lbl_col_profit   = $isKm ? 'ប្រាក់ចំណេញ (PROFIT)' : 'Profit (PROFIT)';

    $lbl_date_from    = $isKm ? 'ចាប់ពីថ្ងៃ :' : 'Date From :';
    $lbl_date_to      = $isKm ? 'ដល់ថ្ងៃ :' : 'Date To :';
    $lbl_doc_count    = $isKm ? 'ចំនួនមុខទំនិញ :' : 'Products Count :';
    $lbl_stat_rev     = $isKm ? 'ចំណូលសរុប' : 'Total Revenue';
    $lbl_stat_cogs    = $isKm ? 'ថ្លៃដើមសរុប' : 'Total Cost';
    $lbl_stat_profit  = $isKm ? 'ប្រាក់ចំណេញសរុប' : 'Total Profit';
    $lbl_no_data      = $isKm ? 'គ្មានទិន្នន័យ' : 'No data';

    $export_excel_url = "daily_report_xlsx.php?mode=products&from_date=" . urlencode($date_from) . "&to_date=" . urlencode($date_to) . "&user_id=" . urlencode($filter_user) . "&category=" . urlencode($selected_cat);
    $export_pdf_url   = "report_pdf.php?from_date=" . urlencode($date_from) . "&to_date=" . urlencode($date_to) . "&user_id=" . urlencode($filter_user) . "&category=" . urlencode($selected_cat) . "&lang=" . urlencode(current_lang());
    require __DIR__ . '/report_header.php';
    ?>

    <style>
    .prod-summary-card {
        background: #111114 !important;
        border: 1px solid rgba(255, 255, 255, 0.08) !important;
        border-radius: 14px !important;
        padding: 1.25rem !important;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25) !important;
        margin-bottom: 1.25rem !important;
    }
    .prod-summary-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 1.25rem;
        flex-wrap: wrap;
        gap: 12px;
    }
    .prod-summary-title {
        font-size: 1.05rem;
        font-weight: 700;
        color: #f3f4f6;
        margin: 0;
        letter-spacing: 0.01em;
    }
    .prod-search-wrapper {
        position: relative;
        width: 240px;
        max-width: 100%;
    }
    .prod-search-icon {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #6b7280;
        font-size: 0.8rem;
        pointer-events: none;
    }
    .prod-search-input {
        width: 100%;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        padding: 7px 12px 7px 32px;
        color: #f3f4f6;
        font-size: 0.85rem;
        outline: none;
        transition: all 0.2s ease;
    }
    .prod-search-input:focus {
        border-color: rgba(245, 158, 11, 0.5);
        background: rgba(255, 255, 255, 0.08);
        box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.15);
    }
    .prod-search-input::placeholder {
        color: #6b7280;
    }

    .prod-analysis-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
    }
    .prod-analysis-table thead th {
        background: transparent !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
        color: #9ca3af !important;
        font-weight: 600 !important;
        font-size: 0.75rem !important;
        text-transform: uppercase !important;
        letter-spacing: 0.05em !important;
        padding: 0.85rem 0.9rem !important;
        border-right: none !important;
    }
    .prod-analysis-table tbody td {
        padding: 0.95rem 0.9rem !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.04) !important;
        border-right: none !important;
        vertical-align: middle;
    }
    .prod-analysis-table tbody tr.product-data-row:hover {
        background: rgba(255, 255, 255, 0.02) !important;
    }

    .prod-amber-dot {
        display: inline-block;
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #f59e0b;
        margin-right: 8px;
        vertical-align: middle;
        box-shadow: 0 0 6px rgba(245, 158, 11, 0.6);
    }
    .prod-cat-pill {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 9999px;
        background: rgba(255, 255, 255, 0.05);
        color: #9ca3af;
        font-size: 0.72rem;
        font-weight: 500;
        border: 1px solid rgba(255, 255, 255, 0.08);
        text-transform: capitalize;
    }
    .prod-total-row {
        border-top: 2px solid rgba(245, 158, 11, 0.4) !important;
        border-bottom: none !important;
        background: rgba(245, 158, 11, 0.02) !important;
    }
    .prod-total-label {
        display: inline-flex;
        align-items: center;
        color: #f59e0b;
        font-weight: 700;
        letter-spacing: 0.06em;
        font-size: 0.85rem;
    }

    [data-theme="light"] .prod-summary-card {
        background: #ffffff !important;
        border: 1px solid rgba(0, 0, 0, 0.08) !important;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06) !important;
    }
    [data-theme="light"] .prod-summary-title {
        color: #1f2937 !important;
    }
    [data-theme="light"] .prod-search-input {
        background: #f9fafb !important;
        border: 1px solid #e5e7eb !important;
        color: #1f2937 !important;
    }
    [data-theme="light"] .prod-analysis-table thead th {
        border-bottom: 1px solid #e5e7eb !important;
        color: #6b7280 !important;
    }
    [data-theme="light"] .prod-analysis-table tbody td {
        border-bottom: 1px solid #f3f4f6 !important;
        color: #374151 !important;
    }
    [data-theme="light"] .prod-analysis-table tbody tr.product-data-row:hover {
        background: #f9fafb !important;
    }
    [data-theme="light"] .prod-cat-pill {
        background: #f3f4f6 !important;
        color: #4b5563 !important;
        border: 1px solid #e5e7eb !important;
    }
    </style>

    <!-- Data Table Card -->
    <div class="er-table-card prod-summary-card">
        <div class="prod-summary-header">
            <h3 class="prod-summary-title"><?= htmlspecialchars($lbl_table_title) ?></h3>
            <div class="prod-search-wrapper">
                <i class="fa-solid fa-magnifying-glass prod-search-icon"></i>
                <input type="text" id="prodSearchInput" class="prod-search-input" placeholder="<?= htmlspecialchars($lbl_search_ph) ?>" oninput="filterProductTable()">
            </div>
        </div>

        <div class="er-table-wrap">
            <table class="er-table prod-analysis-table">
                <thead>
                    <tr>
                        <th style="text-align:left; padding-left:1.25rem;"><?= htmlspecialchars($lbl_col_product) ?></th>
                        <th style="text-align:center;"><?= htmlspecialchars($lbl_col_cat) ?></th>
                        <th style="text-align:center;"><?= htmlspecialchars($lbl_col_qty) ?></th>
                        <th style="text-align:right;"><?= htmlspecialchars($lbl_col_cost_cup) ?></th>
                        <th style="text-align:right;"><?= htmlspecialchars($lbl_col_pr_cup) ?></th>
                        <th style="text-align:right;"><?= htmlspecialchars($lbl_col_rev) ?></th>
                        <th style="text-align:right;"><?= htmlspecialchars($lbl_col_cost) ?></th>
                        <th style="text-align:right; padding-right:1.25rem; color:#22c55e;"><?= htmlspecialchars($lbl_col_profit) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $filtered_products = [];
                    foreach ($topProducts as $pname => $pdata) {
                        $p_cat = $pdata['category'] ?? '';
                        if ($p_cat === '' || $p_cat === 'Uncategorized') {
                            $q_pcat = mysqli_query($conn, "SELECT category FROM products WHERE name = '" . mysqli_real_escape_string($conn, $pname) . "'");
                            if ($q_pcat && ($row = mysqli_fetch_assoc($q_pcat))) {
                                $p_cat = !empty($row['category']) ? $row['category'] : 'Uncategorized';
                                $topProducts[$pname]['category'] = $p_cat;
                            } else {
                                $p_cat = 'Uncategorized';
                            }
                        }
                        if ($selected_cat !== '' && strtolower($p_cat) !== strtolower($selected_cat)) {
                            continue;
                        }
                        $filtered_products[$pname] = $pdata;
                    }
                    ?>
                    <?php if (empty($filtered_products)): ?>
                    <tr class="no-data">
                        <td colspan="8" class="no-data" style="text-align:center; padding:3rem 1rem; color:#777788;"><?= $lbl_no_data ?></td>
                    </tr>
                    <?php else: ?>
                    <?php 
                    $tot_qty = 0; $tot_rev = 0; $tot_cogs = 0;
                    foreach ($filtered_products as $pname => $pdata): 
                        $p_qty   = (int)$pdata['qty'];
                        $p_rev   = (float)$pdata['revenue'];
                        $p_cogs  = (float)($pdata['cogs'] ?? 0);
                        $p_profit = $p_rev - $p_cogs;
                        $avg_pr  = $p_qty > 0 ? ($p_rev / $p_qty) : (float)($pdata['unit_price'] ?? 0);
                        $avg_cost = $p_qty > 0 ? ($p_cogs / $p_qty) : (float)($pdata['unit_cost'] ?? 0);
                        $raw_cat = !empty($pdata['category']) ? $pdata['category'] : 'Uncategorized';
                        $cat_name = $cat_slug_to_name[$raw_cat] ?? $raw_cat;

                        $tot_qty  += $p_qty;
                        $tot_rev  += $p_rev;
                        $tot_cogs += $p_cogs;
                    ?>
                    <tr class="product-data-row" data-name="<?= htmlspecialchars(strtolower($pname)) ?>" data-cat="<?= htmlspecialchars(strtolower($cat_name)) ?>" data-qty="<?= $p_qty ?>" data-rev="<?= $p_rev ?>" data-cost="<?= $p_cogs ?>" data-profit="<?= $p_profit ?>">
                        <td style="text-align:left; padding-left:1.25rem; font-weight:600; color:#f9fafb;">
                            <span class="prod-amber-dot"></span>
                            <span><?= htmlspecialchars($pname) ?></span>
                        </td>
                        <td style="text-align:center;">
                            <span class="prod-cat-pill"><?= htmlspecialchars($cat_name) ?></span>
                        </td>
                        <td style="text-align:center; font-weight:600; color:#f3f4f6;"><?= $p_qty ?></td>
                        <td style="text-align:right; color:#9ca3af;">$<?= number_format($avg_cost, 2) ?></td>
                        <td style="text-align:right; color:#d1d5db;">$<?= number_format($avg_pr, 2) ?></td>
                        <td style="text-align:right; font-weight:600; color:#f3f4f6;">$<?= number_format($p_rev, 2) ?></td>
                        <td style="text-align:right; color:#9ca3af;">$<?= number_format($p_cogs, 2) ?></td>
                        <td style="text-align:right; padding-right:1.25rem; font-weight:700; color:<?= $p_profit >= 0 ? '#22c55e' : '#ef4444' ?>;">
                            <?= ($p_profit >= 0 ? '+' : '-') ?>$<?= number_format(abs($p_profit), 2) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php $tot_profit = $tot_rev - $tot_cogs; ?>
                    <tr id="noMatchRow" style="display:none;">
                        <td colspan="8" style="text-align:center; padding:2.5rem 1rem; color:#888;">
                            <i class="fa-solid fa-magnifying-glass" style="font-size:1.5rem; opacity:0.3; margin-bottom:8px; display:block;"></i>
                            <?= $isKm ? 'រកមិនឃើញទំនិញដែលត្រូវនឹងការស្វែងរកទេ' : 'No matching products found' ?>
                        </td>
                    </tr>
                    <tr class="total-summary-row prod-total-row">
                        <td style="text-align:left; padding-left:1.25rem;">
                            <span class="prod-total-label">
                                <i class="fa-regular fa-file-lines" style="margin-right:6px;"></i> TOTAL
                            </span>
                        </td>
                        <td></td>
                        <td style="text-align:center; font-weight:700; color:#f3f4f6;" id="totalQtyVal"><?= $tot_qty ?></td>
                        <td></td>
                        <td></td>
                        <td style="text-align:right; font-weight:700; color:#f59e0b;" id="totalRevVal">$<?= number_format($tot_rev, 2) ?></td>
                        <td style="text-align:right; font-weight:600; color:#d1d5db;" id="totalCostVal">$<?= number_format($tot_cogs, 2) ?></td>
                        <td style="text-align:right; padding-right:1.25rem; font-weight:700; color:<?= $tot_profit >= 0 ? '#22c55e' : '#ef4444' ?>;" id="totalProfitVal">
                            <?= ($tot_profit >= 0 ? '+' : '-') ?>$<?= number_format(abs($tot_profit), 2) ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    function filterProductTable() {
        const query = (document.getElementById('prodSearchInput')?.value || '').toLowerCase().trim();
        const rows = document.querySelectorAll('.product-data-row');
        let visQty = 0, visRev = 0, visCost = 0, visProfit = 0;
        let matchCount = 0;

        rows.forEach(r => {
            const name = (r.dataset.name || '').toLowerCase();
            const cat = (r.dataset.cat || '').toLowerCase();
            const match = query === '' || name.includes(query) || cat.includes(query);
            r.style.display = match ? '' : 'none';
            if (match) {
                matchCount++;
                visQty += parseInt(r.dataset.qty || 0, 10);
                visRev += parseFloat(r.dataset.rev || 0);
                visCost += parseFloat(r.dataset.cost || 0);
                visProfit += parseFloat(r.dataset.profit || 0);
            }
        });

        const noDataRow = document.getElementById('noMatchRow');
        if (noDataRow) {
            noDataRow.style.display = (matchCount === 0 && rows.length > 0) ? '' : 'none';
        }

        const elQty = document.getElementById('totalQtyVal');
        if (elQty) elQty.textContent = visQty;

        const elRev = document.getElementById('totalRevVal');
        if (elRev) elRev.textContent = '$' + visRev.toFixed(2);

        const elCost = document.getElementById('totalCostVal');
        if (elCost) elCost.textContent = '$' + visCost.toFixed(2);

        const elProfit = document.getElementById('totalProfitVal');
        if (elProfit) {
            const sign = visProfit >= 0 ? '+' : '-';
            elProfit.textContent = sign + '$' + Math.abs(visProfit).toFixed(2);
            elProfit.style.color = visProfit >= 0 ? '#22c55e' : '#ef4444';
        }

        const elSummaryProfit = document.getElementById('summaryCardProfitVal');
        if (elSummaryProfit) {
            const sign = visProfit >= 0 ? '+' : '-';
            elSummaryProfit.textContent = sign + '$' + Math.abs(visProfit).toFixed(2);
            elSummaryProfit.style.color = visProfit >= 0 ? '#22c55e' : '#ef4444';
        }

        const elSummaryRev = document.getElementById('summaryCardRevVal');
        if (elSummaryRev) {
            elSummaryRev.textContent = '$' + visRev.toFixed(2);
        }
    }
    </script>

    <!-- Summary Card -->
    <?php
    $filtered_sales = 0;
    $filtered_cogs  = 0;
    foreach ($filtered_products as $fpdata) {
        $filtered_sales += (float)($fpdata['revenue'] ?? 0);
        $filtered_cogs  += (float)($fpdata['cogs'] ?? 0);
    }
    $filtered_profit = $filtered_sales - $filtered_cogs;
    ?>
    <div class="er-summary-card">
        <div class="er-summary-info">
            <span><?= $lbl_date_from ?> <strong><?= htmlspecialchars($date_from) ?></strong></span>
            <span><?= $lbl_date_to ?> <strong><?= htmlspecialchars($date_to) ?></strong></span>
            <span><?= $lbl_doc_count ?> <strong><?= count($filtered_products) ?></strong></span>
        </div>
        <div class="er-summary-stats">
            <div class="er-summary-stat-item">
                <span class="stat-label"><?= $lbl_stat_profit ?></span>
                <span class="stat-val" id="summaryCardProfitVal" style="color: #22c55e;"><?= ($filtered_profit >= 0 ? '+' : '-') ?>$<?= number_format(abs($filtered_profit), 2) ?></span>
            </div>
            <div class="er-summary-stat-item">
                <span class="stat-label"><?= $lbl_stat_rev ?></span>
                <span class="stat-val" id="summaryCardRevVal">$<?= number_format($filtered_sales, 2) ?></span>
            </div>
        </div>
    </div>
</main>
</div>
</body>
</html>