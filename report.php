<?php
require 'auth.php';
require 'config.php';
require_once 'lang.php';
if (!can('report_product')) { header("Location: dashboard.php?denied=1"); exit; }

date_default_timezone_set("Asia/Phnom_Penh");

function businessRangeFromDate(string $dateYmd): array {
    $start = new DateTime($dateYmd . " 06:00:00");
    $end = clone $start;
    $end->modify("+1 day")->modify("-1 second");
    return [$start, $end];
}

function fmtQty($n): string {
    return rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');
}

function fmtMoney($n): string {
    return number_format((float)$n, 2);
}

function deltaStr(float $current, float $prev): string {
    if ($prev <= 0) return '';
    $pct = round(($current - $prev) / $prev * 100, 1);
    if ($pct === 0.0) return '<span class="delta neutral">= same</span>';
    $cls = $pct > 0 ? 'up' : 'down';
    $arrow = $pct > 0 ? '&#9650;' : '&#9660;';
    return "<span class=\"delta {$cls}\">{$arrow} " . abs($pct) . "%</span>";
}

/* =========================
   INPUT
========================= */
$mode = $_GET['mode'] ?? 'daily';
$filter_user = (int)($_GET['user_id'] ?? $_GET['user'] ?? 0);

if (isset($_GET['from_date']) || isset($_GET['to_date']) || isset($_GET['date_from']) || isset($_GET['date_to'])) {
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
    $fromDate = $_GET['from_date'] ?? $_GET['date_from'] ?? business_date_today();
    $toDate   = $_GET['to_date']   ?? $_GET['date_to']   ?? business_date_today();
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
            COALESCE(NULLIF(p.category, ''), 'Uncategorized') AS category
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

    if (count($productIds) > 0) {
        $inProduct = implode(",", array_map('intval', array_keys($productIds)));

        $qRec = mysqli_query($conn, "
            SELECT pi.product_id, pi.ingredient_id, pi.amount_used, i.ingredient_name
            FROM product_ingredients pi
            JOIN ingredients i ON i.ingredient_id = pi.ingredient_id
            WHERE pi.product_id IN ($inProduct)
        ");

        while ($r = mysqli_fetch_assoc($qRec)) {
            $pid = (int)$r['product_id'];

            if (!isset($recipes[$pid])) {
                $recipes[$pid] = [];
            }

            $recipes[$pid][] = [
                "ingredient_id" => (int)$r['ingredient_id'],
                "ingredient_name" => $r['ingredient_name'],
                "amount_used" => (float)$r['amount_used']
            ];
        }
    }

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
            $topProducts[$pname] = ["qty" => 0, "cogs" => 0, "revenue" => 0, "discount" => 0, "category" => $category];
        }

        $topProducts[$pname]["qty"]      += $qty;
        $topProducts[$pname]["cogs"]     += $itemCost;
        $topProducts[$pname]["revenue"]  += $itemRevenue;
        $topProducts[$pname]["discount"] += $itemTotalDisc;

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

$sqlRefunds = "
    SELECT
        o.order_id,
        o.daily_order_no,
        o.customer_name,
        orr.refund_amount,
        orr.refund_reason,
        orr.refunded_at,
        orr.refunded_by,
        o.total
    FROM order_refunds orr
    JOIN orders o ON o.order_id = orr.order_id
    WHERE orr.refunded_at BETWEEN '$startStr' AND '$endStr'
    ORDER BY orr.refunded_at DESC
";

$qRefunds = mysqli_query($conn, $sqlRefunds);

while ($r = mysqli_fetch_assoc($qRefunds)) {
    $totalRefunded += (float)$r['refund_amount'];
    $refundCount++;
    $refundOrders[] = [
        'order_id' => (int)$r['order_id'],
        'daily_order_no' => (int)$r['daily_order_no'],
        'customer_name' => $r['customer_name'],
        'refund_amount' => (float)$r['refund_amount'],
        'refund_reason' => $r['refund_reason'],
        'refunded_at' => $r['refunded_at'],
        'refunded_by' => $r['refunded_by'],
        'original_total' => (float)$r['total']
    ];
}

$netRevenue = $totalSales - $totalRefunded;

/* =========================
   GET REMAKE DATA
========================= */
$remakeCount  = 0;
$remakeOrders = [];
$_tbl_check   = $conn->query("SHOW TABLES LIKE 'order_remakes'");
if ($_tbl_check && $_tbl_check->num_rows > 0) {
    $sqlRemakes = "
        SELECT rm.id, rm.reason, rm.remade_by, rm.remade_at,
               o.daily_order_no, o.customer_name,
               GROUP_CONCAT(DISTINCT oi.product_name ORDER BY oi.product_name SEPARATOR ', ') AS products
        FROM order_remakes rm
        JOIN orders o ON o.order_id = rm.order_id
        LEFT JOIN order_items oi ON oi.order_id = rm.order_id
        WHERE rm.remade_at BETWEEN '$startStr' AND '$endStr'
        GROUP BY rm.id
        ORDER BY rm.remade_at DESC
    ";
    $qRemakes = mysqli_query($conn, $sqlRemakes);
    while ($r = mysqli_fetch_assoc($qRemakes)) {
        $remakeCount++;
        $remakeOrders[] = [
            'daily_order_no' => (int)$r['daily_order_no'],
            'customer_name'  => $r['customer_name'],
            'products'       => $r['products'],
            'reason'         => $r['reason'],
            'remade_by'      => $r['remade_by'],
            'remade_at'      => $r['remade_at'],
        ];
    }
}

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
   GET DAILY REFUNDS FOR CHART (NEW)
========================= */
$refundChartData = [];

if ($mode === 'daily') {
    $sqlDaily = "
        SELECT
            HOUR(refunded_at) as hour,
            COUNT(*) as count,
            SUM(refund_amount) as total
        FROM order_refunds
        WHERE refunded_at BETWEEN '$startStr' AND '$endStr'
        GROUP BY HOUR(refunded_at)
        ORDER BY hour ASC
    ";
    $qDaily = mysqli_query($conn, $sqlDaily);
    while ($r = mysqli_fetch_assoc($qDaily)) {
        $refundChartData[] = [
            'label' => (int)$r['hour'] . ':00',
            'count' => (int)$r['count'],
            'total' => (float)$r['total']
        ];
    }
} elseif ($mode === 'monthly') {
    $sqlMonthly = "
        SELECT
            DAY(refunded_at) as day,
            COUNT(*) as count,
            SUM(refund_amount) as total
        FROM order_refunds
        WHERE refunded_at BETWEEN '$startStr' AND '$endStr'
        GROUP BY DAY(refunded_at)
        ORDER BY day ASC
    ";
    $qMonthly = mysqli_query($conn, $sqlMonthly);
    while ($r = mysqli_fetch_assoc($qMonthly)) {
        $refundChartData[] = [
            'label' => 'Day ' . (int)$r['day'],
            'count' => (int)$r['count'],
            'total' => (float)$r['total']
        ];
    }
} else {
    $sqlRange = "
        SELECT
            DATE(refunded_at) as date,
            COUNT(*) as count,
            SUM(refund_amount) as total
        FROM order_refunds
        WHERE refunded_at BETWEEN '$startStr' AND '$endStr'
        GROUP BY DATE(refunded_at)
        ORDER BY date ASC
    ";
    $qRange = mysqli_query($conn, $sqlRange);
    while ($r = mysqli_fetch_assoc($qRange)) {
        $refundChartData[] = [
            'label' => date('M d', strtotime($r['date'])),
            'count' => (int)$r['count'],
            'total' => (float)$r['total']
        ];
    }
}
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
    $q_users = $conn->query("SELECT u.user_id, u.username, e.name AS emp_name, r.slug AS role FROM users u LEFT JOIN employees e ON e.user_id = u.user_id LEFT JOIN roles r ON r.id = u.role_id ORDER BY COALESCE(NULLIF(e.name, ''), u.username) ASC");
    if ($q_users) {
        while ($ur = $q_users->fetch_assoc()) {
            $displayName = !empty($ur['emp_name']) ? $ur['emp_name'] : $ur['username'];
            $user_options[$ur['user_id']] = $displayName . ' (' . ucfirst($ur['role'] ?? 'staff') . ')';
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
    $lbl_doc_type     = $isKm ? 'ប្រភេទឯកសារ' : 'Doc. Type';
    $lbl_product_name = $isKm ? 'ឈ្មោះទំនិញ' : 'Product Name';
    $lbl_category     = $isKm ? 'ប្រភេទ' : 'Category';
    $lbl_qty_sold     = $isKm ? 'ចំនួនលក់' : 'Qty Sold';
    $lbl_price_cup    = $isKm ? 'តម្លៃ/កែវ' : 'Price Per Cup';
    $lbl_disc         = $isKm ? 'បញ្ចុះតម្លៃ' : 'Disc';
    $lbl_total_rev    = $isKm ? 'ចំណូលសរុប' : 'Total Revenue';
    $lbl_total_cogs   = $isKm ? 'ដើមទុនសរុប' : 'Total COGS';
    $lbl_gross_profit = $isKm ? 'ប្រាក់ចំណេញ' : 'Gross Profit';
    $lbl_margin       = $isKm ? 'កម្រិតចំណេញ %' : 'Margin %';

    $lbl_date_from    = $isKm ? 'ចាប់ពីថ្ងៃ :' : 'Date From :';
    $lbl_date_to      = $isKm ? 'ដល់ថ្ងៃ :' : 'Date To :';
    $lbl_doc_count    = $isKm ? 'ចំនួន Order :' : 'Doc.Count :';

    $lbl_stat_rev     = $isKm ? 'ចំណូលសរុប' : 'Total Product Revenue';
    $lbl_stat_cogs    = $isKm ? 'ដើមទុនសរុប' : 'Total COGS';
    $lbl_stat_profit  = $isKm ? 'ប្រាក់ចំណេញសរុប' : 'Total Gross Profit';
    $lbl_doc_item     = $isKm ? 'ទំនិញ' : 'Product Item';
    $lbl_no_data      = $isKm ? 'គ្មានទិន្នន័យ' : 'No data';

    $export_excel_url = "daily_report_xlsx.php?mode=products&from_date=" . urlencode($date_from) . "&to_date=" . urlencode($date_to) . "&user_id=" . urlencode($filter_user) . "&category=" . urlencode($selected_cat);
    $export_pdf_url   = "report_pdf.php?from_date=" . urlencode($date_from) . "&to_date=" . urlencode($date_to) . "&user_id=" . urlencode($filter_user) . "&category=" . urlencode($selected_cat) . "&lang=" . urlencode(current_lang());
    require __DIR__ . '/report_header.php';
    ?>

    <!-- Data Table -->
    <div class="er-table-card">
        <div class="er-table-wrap">
            <table class="er-table">
                <thead>
                    <tr>
                        <th style="text-align:center"><?= $lbl_doc_type ?></th>
                        <th style="text-align:center"><?= $lbl_product_name ?></th>
                        <th style="text-align:center"><?= $lbl_category ?></th>
                        <th style="text-align:center"><?= $lbl_qty_sold ?></th>
                        <th style="text-align:center"><?= $lbl_price_cup ?></th>
                        <th style="text-align:center"><?= $lbl_total_rev ?></th>
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
                        <td colspan="6" class="no-data" style="text-align:center"><?= $lbl_no_data ?></td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($filtered_products as $pname => $pdata): ?>
                    <?php
                        $p_qty   = (int)$pdata['qty'];
                        $p_rev   = (float)$pdata['revenue'];
                        $p_disc  = (float)($pdata['discount'] ?? 0);
                        $avg_pr  = $p_qty > 0 ? $p_rev / $p_qty : 0;
                        $raw_cat = !empty($pdata['category']) ? $pdata['category'] : 'Uncategorized';
                        $cat_name = $cat_slug_to_name[$raw_cat] ?? $raw_cat;
                    ?>
                    <tr>
                        <td style="text-align:center"><span class="er-badge-doc"><?= $lbl_doc_item ?></span></td>
                        <td style="text-align:center" class="er-prod-name"><?= htmlspecialchars($pname) ?></td>
                        <td style="text-align:center"><span class="er-badge-cat"><?= htmlspecialchars($cat_name) ?></span></td>
                        <td style="text-align:center"><?= $p_qty ?></td>
                        <td style="text-align:center">$<?= number_format($avg_pr, 2) ?></td>
                        <td style="text-align:center" class="er-total-rev">$<?= number_format($p_rev, 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php
                        $tot_qty = 0; $tot_rev = 0;
                        foreach ($filtered_products as $fpd) {
                            $tot_qty  += (int)($fpd['qty'] ?? 0);
                            $tot_rev  += (float)($fpd['revenue'] ?? 0);
                        }
                    ?>
                    <tr class="total-summary-row">
                        <td colspan="3" style="text-align:center; padding: 0.85rem 1rem;">
                            <span class="er-badge-total">
                                <i class="fa-solid fa-calculator" style="font-size:0.75rem;"></i> Total
                            </span>
                        </td>
                        <td style="text-align:center; padding: 0.85rem 1rem;">
                            <span class="er-qty-pill"><?= $tot_qty ?></span>
                        </td>
                        <td></td>
                        <td style="text-align:center;" class="er-total-rev">$<?= number_format($tot_rev, 2) ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Summary Card -->
    <?php
    $filtered_sales = 0;
    foreach ($filtered_products as $fpdata) {
        $filtered_sales += (float)($fpdata['revenue'] ?? 0);
    }
    ?>
    <div class="er-summary-card">
        <div class="er-summary-info">
            <span><?= $lbl_date_from ?> <strong><?= htmlspecialchars($date_from) ?></strong></span>
            <span><?= $lbl_date_to ?> <strong><?= htmlspecialchars($date_to) ?></strong></span>
            <span><?= $lbl_doc_count ?> <strong><?= count($filtered_products) ?></strong></span>
        </div>
        <div class="er-summary-stats">
            <div class="er-summary-stat-item">
                <span class="stat-label"><?= $lbl_stat_rev ?></span>
                <span class="stat-val">$<?= number_format($filtered_sales, 2) ?></span>
            </div>
        </div>
    </div>
</main>
</div>
</body>
</html>