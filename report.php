<?php
require 'auth.php';
require 'config.php';
if (!can('report')) { header("Location: dashboard.php?denied=1"); exit; }

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
    $fromDate = $_GET['from_date'] ?? business_date_today();
    $toDate   = $_GET['to_date'] ?? business_date_today();

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

$stmt_orders = $conn->prepare("SELECT order_id, total FROM orders WHERE " . paid_orders_where() . " AND order_date BETWEEN ? AND ?");
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

        if (!isset($topProducts[$pname])) {
            $topProducts[$pname] = ["qty" => 0, "cogs" => 0, "revenue" => 0];
        }

        $topProducts[$pname]["qty"]     += $qty;
        $topProducts[$pname]["cogs"]    += $itemCost;
        $topProducts[$pname]["revenue"] += $itemRevenue;

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
    // For daily mode, show refunds by hour
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
    // For monthly mode, show refunds by day
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
    // For range mode, show refunds by day
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
<title>Profit & Refund Report | Bird's Nest Coffee</title>
<meta name="viewport" content="width=device-width,initial-scale=1" />

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
/* ── RESET & ROOT ── */
:root {
    --bg: #0c0c0c;
    --bg-card: #121212;
    --bg-card-hover: #181818;
    --border: #222;
    --border-hover: #333;
    --accent: #b57b3b;
    --accent-2: #d1904b;
    --gold: #e3b382;
    --text: #ffffff;
    --text-muted: #aaa;
    --text-light: #f5f5f5;
    --refund-color: #9b59b6;
    --refund-light: #bb8fce;
    --pos: #63f1a0;
    --neg: #ff6b6b;
    --warn: #f1c40f;
    --amber: #f0b45a;
    
    /* ── Shadow System ── */
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.3);
    --shadow-md: 0 4px 20px rgba(0,0,0,0.4);
    --shadow-lg: 0 8px 40px rgba(0,0,0,0.5);
    --shadow-accent: 0 4px 20px rgba(209, 144, 75, 0.15);
    --shadow-refund: 0 4px 20px rgba(155, 89, 182, 0.15);
    
    /* ── Transitions ── */
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* ── Light Theme Variables ── */
[data-theme="light"] {
    --bg:           #F0F2F5;
    --bg-card:      #FFFFFF;
    --bg-card-hover:#F5F7FA;
    --bg-input:     #F9FAFB;
    --border:       #E5E7EB;
    --border-hover: #D1D5DB;
    --text:         #111827;
    --text-muted:   #6B7280;
    --text-light:   #ffffff;
    --accent:       #d1904b;
    --accent-2:     #d1904b;
    --gold:         #a0702a;
    --refund-color: #8e44ad;
    --refund-light: #9b59b6;
    --pos: #1a9d5a;
    --neg: #d63031;
    --warn: #c08a00;
    --amber: #b8761e;
    --shadow-sm:    0 2px 8px  rgba(0,0,0,0.06);
    --shadow-md:    0 4px 20px rgba(0,0,0,0.08);
    --shadow-lg:    0 8px 40px rgba(0,0,0,0.10);
}

/* ══════════════════════════════════════════════════
   LIGHT MODE — override every hardcoded rgba dark bg
══════════════════════════════════════════════════ */

[data-theme="light"] .top {
    background: var(--bg-card);
    backdrop-filter: none;
    -webkit-backdrop-filter: none;
}

[data-theme="light"] .card {
    background: var(--bg-card);
    backdrop-filter: none;
    -webkit-backdrop-filter: none;
}

[data-theme="light"] .kpi {
    background: var(--bg-card-hover);
}

[data-theme="light"] .chart-wrap {
    background: var(--bg-card-hover);
}

[data-theme="light"] input,
[data-theme="light"] select,
[data-theme="light"] textarea {
    background-color: var(--bg-input) !important;
    color: var(--text) !important;
    border-color: var(--border) !important;
    color-scheme: light;
}

[data-theme="light"] .badge.good {
    background: rgba(34, 139, 34, 0.12);
    color: #1a6b2e;
    border-color: rgba(34, 139, 34, 0.25);
}
[data-theme="light"] .badge.warn {
    background: rgba(180, 100, 0, 0.12);
    color: #7a4400;
    border-color: rgba(180, 100, 0, 0.25);
}
[data-theme="light"] .badge.refund {
    background: rgba(142, 68, 173, 0.12);
    color: #6a2c8a;
    border-color: rgba(142, 68, 173, 0.25);
}

[data-theme="light"] tbody tr {
    border-bottom-color: rgba(0,0,0,0.06);
}
[data-theme="light"] tbody tr:hover {
    background: rgba(0,0,0,0.025);
}
[data-theme="light"] th {
    background: rgba(0,0,0,0.03);
}

[data-theme="light"] ::-webkit-scrollbar-track { background: var(--bg); }

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: 'Poppins', sans-serif;
    background: var(--bg);
    color: var(--text);
    padding: 40px;
    line-height: 1.6;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    text-rendering: optimizeLegibility;
}

/* ── Custom Scrollbar ── */
::-webkit-scrollbar { width: 8px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb {
    background: var(--accent-2);
    border-radius: 10px;
}
::-webkit-scrollbar-thumb:hover { background: var(--gold); }

/* ========== HEADER ========== */
.top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 32px;
    flex-wrap: wrap;
    padding: 20px 28px;
    background: rgba(18, 18, 18, 0.95);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-radius: 16px;
    border: 1px solid var(--border);
}

a.back {
    color: #d1904b;
    text-decoration: none;
    font-weight: 600;
    font-size: 14px;
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 7px 14px;
    border-radius: 10px;
    border: 1px solid rgba(209,144,75,.35);
    background: rgba(209,144,75,.08);
}
a.back:hover { background: rgba(209,144,75,.16); border-color: #d1904b; }

a.back i {
    font-size: 18px;
    transition: var(--transition);
}

a.back:hover {
    color: var(--gold);
}

a.back:hover i {
    transform: translateX(-4px);
}

h2 {
    margin: 0;
    color: var(--gold);
    font-weight: 700;
    text-align: center;
    font-size: 24px;
    letter-spacing: 0.5px;
}

h2 i {
    color: var(--accent-2);
    margin-right: 8px;
}

.report-date {
    color: var(--text-muted);
    font-size: 14px;
    font-weight: 500;
    background: var(--bg);
    padding: 8px 18px;
    border-radius: 50px;
    border: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 8px;
}

.report-date i {
    color: var(--accent-2);
}

/* ========== THEME TOGGLE ========== */
.theme-toggle {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 50px;
    padding: 8px 16px;
    cursor: pointer;
    transition: var(--transition);
    color: var(--text);
    font-size: 14px;
}

.theme-toggle:hover {
    border-color: var(--accent-2);
    box-shadow: var(--shadow-accent);
}

.theme-toggle i {
    font-size: 18px;
    color: var(--accent-2);
}

/* ========== GENERATE REPORT BUTTON ========== */
.btn-generate {
    padding: 8px 16px;
    border-radius: 50px;
    border: 1px solid var(--border);
    background: var(--bg-card);
    color: var(--text);
    font-size: 14px;
    cursor: pointer;
    transition: var(--transition);
    font-family: 'Poppins', sans-serif;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-generate:hover {
    border-color: var(--accent-2);
    box-shadow: var(--shadow-accent);
    transform: translateY(-2px);
}

.btn-generate i {
    color: var(--accent-2);
}

.btn-generate:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* ========== CARDS ========== */
.card {
    background: rgba(18, 18, 18, 0.95);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 28px 24px;
    box-shadow: var(--shadow-md);
    margin-bottom: 28px;
    transition: var(--transition);
}

.card:hover {
    border-color: var(--border-hover);
    box-shadow: var(--shadow-lg);
}

/* ========== FILTERS ========== */
.filters {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    align-items: flex-end;
    margin-bottom: 24px;
    padding-bottom: 24px;
    border-bottom: 1px solid var(--border);
}

.filters > div {
    flex: 1;
    min-width: 150px;
}

label {
    font-size: 12px;
    color: var(--text-muted);
    display: block;
    margin-bottom: 6px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

select,
input {
    width: 100%;
    padding: 12px 16px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: var(--bg-card);
    color: var(--text);
    outline: none;
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    transition: var(--transition);
}

select:focus,
input:focus {
    border-color: var(--accent-2);
    box-shadow: 0 0 0 3px rgba(209, 144, 75, 0.1);
}

select option {
    background: var(--bg-card);
}

.btn {
    padding: 12px 24px;
    border-radius: 10px;
    border: none;
    background: linear-gradient(135deg, var(--accent), var(--accent-2));
    color: var(--text);
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 120px;
    justify-content: center;
}

.btn:hover {
    background: linear-gradient(135deg, var(--accent-2), var(--gold));
    transform: translateY(-2px);
    box-shadow: var(--shadow-accent);
}

.btn:active {
    transform: translateY(0);
}

/* ========== KPIS ========== */
.kpis {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-top: 4px;
}

.kpi {
    background: rgba(16, 16, 16, 0.8);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 20px;
    transition: var(--transition);
    position: relative;
    overflow: hidden;
}

.kpi::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--accent), var(--gold));
    opacity: 0;
    transition: var(--transition);
}

.kpi:hover {
    transform: translateY(-4px);
    border-color: var(--border-hover);
    box-shadow: var(--shadow-md);
}

.kpi:hover::before {
    opacity: 1;
}

.kpi-icon {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}

.kpi-icon i {
    color: var(--accent-2);
    font-size: 18px;
}

.kpi > span {
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.6px;
}

.kpi h3 {
    margin: 6px 0 0;
    color: var(--accent-2);
    font-size: 27px;
    font-weight: 700;
    line-height: 1.15;
    letter-spacing: -0.01em;
    font-variant-numeric: tabular-nums;
    font-feature-settings: "tnum" 1;
}

.small {
    color: var(--text-muted);
    font-size: 12px;
    margin-top: 6px;
}

.badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 50px;
    font-weight: 600;
    font-size: 12px;
}

.badge.good {
    background: rgba(15, 58, 31, 0.6);
    color: var(--pos);
    border: 1px solid rgba(99, 241, 160, 0.2);
}

.badge.warn {
    background: rgba(58, 42, 16, 0.6);
    color: var(--amber);
    border: 1px solid rgba(240, 180, 90, 0.2);
}

.badge.refund {
    background: rgba(155, 89, 182, 0.2);
    color: var(--refund-light);
    border: 1px solid rgba(155, 89, 182, 0.3);
}

/* ── Refund KPI special styling ── */
.kpi.refund-kpi::before {
    background: linear-gradient(90deg, var(--refund-color), var(--refund-light));
}

.kpi.refund-kpi h3 {
    color: var(--refund-light);
}

.kpi.refund-kpi .kpi-icon i {
    color: var(--refund-color);
}

/* ========== CHART GRID ========== */
.chart-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-top: 8px;
}

.chart-wrap {
    aspect-ratio: 4 / 3;
    min-height: 200px;
    max-height: 420px;
    position: relative;
    background: rgba(16, 16, 16, 0.5);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 16px;
    transition: var(--transition);
    overflow: hidden;
}

.chart-wrap canvas {
    max-width: 100% !important;
    height: auto !important;
}

.chart-wrap:hover {
    border-color: var(--border-hover);
}

.chart-wrap .chart-title {
    text-align: center;
    color: var(--gold);
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.chart-wrap .chart-title i {
    color: var(--accent-2);
}

.chart-wrap.refund-chart .chart-title {
    color: var(--refund-light);
}

.chart-wrap.refund-chart .chart-title i {
    color: var(--refund-color);
}

.empty {
    color: var(--text-muted);
    text-align: center;
    padding: 40px 20px;
    font-size: 16px;
}

.empty i {
    font-size: 32px;
    display: block;
    margin-bottom: 12px;
    color: var(--text-muted);
}

/* ========== REFUND TABLE ========== */
.refund-table-wrapper {
    overflow-x: auto;
    margin-top: 12px;
}

/* ── Table pagination ── */
.report-pager {
    display: flex; align-items: center; justify-content: center;
    gap: 6px; flex-wrap: wrap; margin-top: 16px;
}
.report-pager .rp-btn {
    min-width: 36px; height: 36px; padding: 0 10px;
    border-radius: 9px; border: 1px solid var(--border);
    background: var(--bg-card); color: var(--text);
    font-size: 13px; font-weight: 600; cursor: pointer;
    font-family: inherit; transition: all .15s;
    display: inline-flex; align-items: center; justify-content: center;
}
.report-pager .rp-btn:hover:not(:disabled):not(.active) {
    border-color: var(--accent-2); color: var(--accent-2);
}
.report-pager .rp-btn.active {
    background: var(--accent-2); border-color: var(--accent-2); color: #1a1410;
}
.report-pager .rp-btn:disabled { opacity: .35; cursor: not-allowed; }
.report-pager .rp-ellipsis { color: var(--text-muted); padding: 0 4px; }

.refund-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

.refund-table th {
    text-align: left;
    padding: 12px 16px;
    color: var(--refund-light);
    border-bottom: 2px solid var(--border);
    font-weight: 600;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.refund-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
    color: var(--text);
    font-variant-numeric: tabular-nums;
}

.refund-table tr:hover td {
    background: rgba(255,255,255,0.02);
}

.refund-table .refund-amount {
    color: var(--refund-light);
    font-weight: 600;
}

.refund-table .refund-reason {
    color: var(--text-muted);
    font-style: italic;
}

.refund-table .refunded-by {
    font-size: 13px;
    color: var(--text-muted);
}

/* ── PC improvements ── */
@media (min-width: 1280px) {
    .chart-wrap {
        aspect-ratio: 16 / 6;
        max-height: 500px;
    }
}

/* ── Tablet ── */
@media (max-width: 1024px) {
    .kpis {
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    }
    .chart-wrap {
        aspect-ratio: 3 / 2;
    }
}

/* ── Hourly 2-col → 1-col on narrow screens ── */
@media (max-width: 900px) {
    .hourly-grid {
        grid-template-columns: 1fr !important;
    }
    .hourly-grid .chart-wrap {
        height: 220px !important;
    }
}

/* ── Mobile ── */
@media (max-width: 768px) {
    body {
        padding: 12px;
    }

    .top {
        flex-direction: column;
        align-items: stretch;
        text-align: center;
        padding: 14px 16px;
        gap: 10px;
    }

    h2 {
        font-size: 20px;
    }

    .filters {
        flex-direction: column;
        gap: 12px;
    }

    .filters > div {
        min-width: 100%;
    }

    .btn {
        width: 100%;
    }

    .kpis {
        grid-template-columns: 1fr 1fr;
    }

    .chart-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }

    .chart-wrap {
        aspect-ratio: 4 / 3;
        padding: 10px;
    }

    .card {
        padding: 16px 12px;
    }

    .refund-table {
        font-size: 12px;
    }
    
    .refund-table th,
    .refund-table td {
        padding: 8px 10px;
    }
}

@media (max-width: 480px) {
    .kpis {
        grid-template-columns: 1fr;
    }

    .chart-wrap {
        aspect-ratio: 3 / 2;
        min-height: 180px;
    }

    .kpi h3 {
        font-size: 22px;
    }

    .report-date {
        font-size: 12px;
        padding: 6px 14px;
    }
}

/* ── SECTION HEADER ── */
.section-hdr { margin-bottom: 18px; }
.section-hdr-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.section-hdr-row > i { color:var(--accent-2); font-size:16px; flex-shrink:0; }
.section-hdr-title { color:var(--gold); font-weight:700; font-size:17px; }
.section-hdr-badge { color:var(--text-muted); font-size:12px; font-weight:400; }
.section-hdr-tag { margin-left:auto; background:rgba(209,144,75,.15); color:var(--accent-2); border:1px solid rgba(209,144,75,.3); border-radius:50px; padding:4px 14px; font-size:12px; font-weight:600; white-space:nowrap; }
.section-desc { font-size:12px; color:var(--text-muted); margin-top:5px; padding-left:26px; line-height:1.55; }

/* ── INSIGHTS STRIP ── */
.insights-row { display:flex; flex-wrap:wrap; gap:12px; margin-bottom:24px; }
.insight-chip {
    display:flex; align-items:center; gap:10px;
    background:rgba(255,255,255,.03); border:1px solid var(--border);
    border-radius:12px; padding:11px 16px; flex:1; min-width:150px;
    transition:var(--transition);
}
.insight-chip:hover { border-color:var(--border-hover); }
.insight-chip > i { font-size:18px; color:var(--accent-2); flex-shrink:0; }
.insight-chip.ic-good > i { color:var(--pos); }
.insight-chip.ic-warn > i { color:var(--amber); }
.insight-chip.ic-alert > i { color:var(--refund-color); }
.ic-label { font-size:10px; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:.5px; }
.ic-val { font-size:13px; font-weight:600; color:var(--text); margin-top:2px; }
[data-theme="light"] .insight-chip { background:rgba(0,0,0,.025); }

.report-summary { font-size:14px; line-height:1.8; color:var(--text); margin:10px 0 0; padding:14px 18px; background:rgba(255,255,255,.03); border-left:3px solid var(--accent-2); border-radius:0 8px 8px 0; }
.report-summary strong.good { color:var(--pos); }
.report-summary strong.ok   { color:var(--accent-2); }
.report-summary strong.warn { color:var(--amber); }

/* ── Live indicator ── */
.live-bar { display:flex; align-items:center; gap:8px; font-size:11px; color:var(--text-muted); margin:10px 0 16px; padding:6px 14px; background:rgba(99,241,160,.06); border:1px solid rgba(99,241,160,.15); border-radius:50px; width:fit-content; }
.live-dot { width:7px; height:7px; border-radius:50%; background:var(--pos); flex-shrink:0; }
.live-dot.pulsing { animation:livePulse 1.8s ease-in-out infinite; }
@keyframes livePulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(.75)} }
@keyframes kpiFlash { 0%{background:rgba(99,241,160,.18)} 100%{background:transparent} }
.kpi-flash { animation:kpiFlash .7s ease-out forwards; }

/* ── Delta badges ── */
.kpi-delta { font-size:11px; margin-top:4px; line-height:1.3; font-variant-numeric:tabular-nums; }
.delta { font-weight:700; font-size:11px; padding:2px 6px; border-radius:20px; }
.delta.up      { color:var(--pos); background:rgba(99,241,160,.12); }
.delta.down    { color:var(--neg); background:rgba(255,107,107,.12); }
.delta.neutral { color:var(--text-muted); background:rgba(255,255,255,.05); }

/* ── Daily goal progress bar ── */
.goal-bar-wrap { margin-top:8px; }
.goal-bar-track { height:5px; background:rgba(255,255,255,.08); border-radius:99px; overflow:hidden; margin-bottom:4px; }
.goal-bar-fill  { height:100%; background:linear-gradient(90deg,var(--accent),var(--accent-2)); border-radius:99px; transition:width .6s ease; }
.goal-label     { font-size:11px; color:var(--text-muted); font-variant-numeric:tabular-nums; }

/* ── REDUCED MOTION ── */
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.001ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.001ms !important;
        scroll-behavior: auto !important;
    }
}

/* ── PRINT ── */
@media print {
    body { padding:20px; background:#fff !important; color:#000 !important; }
    .top { background:#fff !important; border-color:#ddd !important; box-shadow:none !important; }
    .btn-generate, .theme-toggle, a.back { display:none !important; }
    .card { box-shadow:none !important; border:1px solid #ddd !important; background:#fff !important; break-inside:avoid; }
    .kpi { background:#f9f9f9 !important; }
    .chart-wrap { border:1px solid #ddd !important; background:#fff !important; }
    .section-desc { color:#555 !important; }
    .insight-chip { border-color:#ddd !important; background:#fafafa !important; }
    .report-summary { border-left-color:#aaa !important; background:#f9f9f9 !important; color:#000 !important; }
    /* Print every row, hide the pager controls */
    .report-pager { display:none !important; }
    .refund-table tbody tr { display:table-row !important; }
}
</style>
</head>

<body>

<div class="top">
    <a class="back" href="dashboard.php">
        <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
    </a>
    <h2><i class="fa-solid fa-chart-line"></i> Profit & Refund Report</h2>
    <div class="report-date">
        <i class="fa-regular fa-calendar"></i>
        <?= htmlspecialchars($label) ?>
    </div>
    
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <button class="btn-generate" onclick="exportCSV()" title="Export product data as CSV">
            <i class="fa-solid fa-file-csv"></i> CSV
        </button>
        <?php /* One button, not two. A separate "PDF" beside a "Print" left the manager
                guessing which one to click, and the two did different things: Print sent
                the live page to the printer, PDF built the laid-out document. The document
                is the one worth having, so Print opens it and the viewer prints from
                there. */ ?>
        <button class="btn-generate" onclick="exportPDF()" title="Open the printable report">
            <i class="fa-solid fa-print"></i> Print
        </button>
        <button class="theme-toggle" onclick="toggleTheme()">
            <i class="fa-solid fa-moon" id="themeIcon"></i>
            <span id="themeText">Dark</span>
        </button>
    </div>
</div>

<div class="card">
    <form class="filters" method="GET">
        <div>
            <label><i class="fa-regular fa-clock"></i> Mode</label>
            <select name="mode" onchange="this.form.submit()">
                <option value="daily" <?= $mode === 'daily' ? 'selected' : '' ?>>Daily</option>
                <option value="monthly" <?= $mode === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                <option value="range" <?= $mode === 'range' ? 'selected' : '' ?>>Custom Range</option>
            </select>
        </div>

        <?php if ($mode === 'daily'): ?>
            <div>
                <label>Business Date</label>
                <input type="date" name="date" value="<?= htmlspecialchars($_GET['date'] ?? business_date_today()) ?>">
            </div>
        <?php elseif ($mode === 'monthly'): ?>
            <div>
                <label>Month</label>
                <input type="month" name="month" value="<?= htmlspecialchars($_GET['month'] ?? (new DateTime())->format("Y-m")) ?>">
            </div>
        <?php elseif ($mode === 'range'): ?>
            <div>
                <label>From Date</label>
                <input type="date" name="from_date" value="<?= htmlspecialchars($_GET['from_date'] ?? business_date_today()) ?>">
            </div>
            <div>
                <label>To Date</label>
                <input type="date" name="to_date" value="<?= htmlspecialchars($_GET['to_date'] ?? business_date_today()) ?>">
            </div>
        <?php endif; ?>

        <button class="btn" type="submit">
            <i class="fa-solid fa-magnifying-glass"></i> View Report
        </button>
    </form>

    <div class="kpis">
        <div class="kpi">
            <div class="kpi-icon">
                <i class="fa-solid fa-receipt"></i>
            </div>
            <span>Orders</span>
            <h3 class="num" id="kv-orders"><?= $orderCount ?></h3>
            <div class="small" id="kv-avg">Avg $<?= fmtMoney($avgOrder) ?> &middot; <span id="kv-items"><?= $totalItemsSold ?></span> items</div>
            <?php if ($deltaOrders): ?><div class="kpi-delta" id="kv-delta-orders"><?= $deltaOrders ?> vs prev</div><?php endif; ?>
        </div>

        <div class="kpi">
            <div class="kpi-icon">
                <i class="fa-solid fa-dollar-sign"></i>
            </div>
            <span>Sales</span>
            <h3 class="num" id="kv-sales">$<?= fmtMoney($totalSales) ?></h3>
            <?php if ($deltaSales): ?><div class="kpi-delta" id="kv-delta-sales"><?= $deltaSales ?> vs prev</div><?php endif; ?>
            <?php if ($mode === 'daily' && $dailyTarget > 0): ?>
            <div class="goal-bar-wrap" id="goal-bar-wrap">
                <?php $goalPct = min(100, $totalSales / $dailyTarget * 100); ?>
                <div class="goal-bar-track"><div class="goal-bar-fill" id="goal-fill" style="width:<?= round($goalPct, 1) ?>%"></div></div>
                <div class="goal-label">$<span id="goal-current"><?= fmtMoney($totalSales) ?></span> of $<?= fmtMoney($dailyTarget) ?> goal &middot; <span id="goal-pct"><?= round($goalPct) ?>%</span></div>
            </div>
            <?php endif; ?>
        </div>

        <div class="kpi">
            <div class="kpi-icon">
                <i class="fa-solid fa-boxes-stacked"></i>
            </div>
            <span>Food Cost</span>
            <h3 class="num" id="kv-foodcost">$<?= fmtMoney($totalCOGS) ?></h3>
            <div class="small">Ingredients used</div>
        </div>

        <div class="kpi">
            <div class="kpi-icon">
                <i class="fa-solid fa-coins"></i>
            </div>
            <span>Profit</span>
            <h3 class="num" id="kv-profit">$<?= fmtMoney($totalProfit) ?></h3>
            <div class="small">
                <span id="kv-margin" class="badge <?= ($margin >= 30 ? 'good' : 'warn') ?>">
                    <i class="fa-solid fa-percent"></i> Margin: <?= number_format($margin, 1) ?>%
                </span>
            </div>
        </div>

        <!-- ── REFUND KPI ── -->
        <div class="kpi refund-kpi">
            <div class="kpi-icon">
                <i class="fa-solid fa-rotate-left"></i>
            </div>
            <span>Refunds</span>
            <h3 class="num" id="kv-refunds">$<?= fmtMoney($totalRefunded) ?></h3>
            <div class="small">
                <span id="kv-refcount" class="badge refund">
                    <i class="fa-solid fa-receipt"></i> <?= $refundCount ?> orders refunded
                </span>
            </div>
        </div>

        <!-- ── REMAKE KPI ── -->
        <div class="kpi" style="border-top: 3px solid var(--warn);">
            <div class="kpi-icon" style="color:var(--warn);">
                <i class="fa-solid fa-repeat"></i>
            </div>
            <span>Remakes</span>
            <h3 class="num" style="color:var(--warn);"><?= $remakeCount ?></h3>
            <div class="small">
                <span class="badge warn"><i class="fa-solid fa-repeat"></i> drinks remade</span>
            </div>
        </div>

        <!-- ── NET REVENUE KPI ── -->
        <div class="kpi" style="border-top: 3px solid var(--pos);">
            <div class="kpi-icon">
                <i class="fa-solid fa-sack-dollar"></i>
            </div>
            <span>Take Home</span>
            <h3 class="num" id="kv-takehome" style="color:var(--pos);">$<?= fmtMoney($netRevenue) ?></h3>
            <div class="small">Sales minus refunds</div>
        </div>

        <?php if ($mode === 'daily' && $peakHour): ?>
        <!-- ── PEAK HOUR KPI ── -->
        <div class="kpi" style="border-top: 3px solid var(--gold);">
            <div class="kpi-icon">
                <i class="fa-solid fa-fire"></i>
            </div>
            <span>Busiest Hour</span>
            <h3 class="num" id="kv-peak" style="color:var(--gold); font-size:22px;"><?= $peakHour ?></h3>
            <div class="small">Most orders this hour</div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($isLive): ?>
    <div class="live-bar" id="live-bar">
        <span class="live-dot pulsing" id="live-dot"></span>
        Live &nbsp;&bull;&nbsp; updated <span id="live-ts">now</span>
    </div>
    <?php endif; ?>
</div>

<!-- ── INSIGHTS STRIP ── -->
<?php if ($orderCount > 0): ?>
<div class="insights-row">
    <?php if (!empty($topProducts)): $topProdName = array_key_first($topProducts); ?>
    <div class="insight-chip" id="ic-bestseller">
        <i class="fa-solid fa-trophy"></i>
        <div><div class="ic-label">Best Seller</div><div class="ic-val" id="ic-bestseller-val"><?= htmlspecialchars($topProdName) ?> &mdash; <?= (int)$topProducts[$topProdName]['qty'] ?> sold</div></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($categorySales)): $topCatName = array_key_first($categorySales); ?>
    <div class="insight-chip" id="ic-topcat">
        <i class="fa-solid fa-tags"></i>
        <div><div class="ic-label">Top Category</div><div class="ic-val" id="ic-topcat-val"><?= htmlspecialchars($topCatName) ?> &mdash; <?= (int)$categorySales[$topCatName]['qty'] ?> items</div></div>
    </div>
    <?php endif; ?>
    <div class="insight-chip <?= $margin >= 30 ? 'ic-good' : 'ic-warn' ?>" id="ic-margin">
        <i class="fa-solid fa-percent"></i>
        <div><div class="ic-label">Profit Margin</div><div class="ic-val" id="ic-margin-val"><?= number_format($margin, 1) ?>% &mdash; <?= $margin >= 30 ? 'healthy' : 'below 30% target' ?></div></div>
    </div>
    <?php if ($mode === 'daily' && $peakHour): ?>
    <div class="insight-chip" id="ic-peak">
        <i class="fa-solid fa-fire"></i>
        <div><div class="ic-label">Busiest Hour</div><div class="ic-val" id="ic-peak-val"><?= $peakHour ?> &mdash; most orders this hour</div></div>
    </div>
    <?php endif; ?>
    <?php if ($refundCount > 0): $refundRate = $orderCount > 0 ? $refundCount / $orderCount * 100 : 0; ?>
    <div class="insight-chip ic-alert" id="ic-refrate">
        <i class="fa-solid fa-rotate-left"></i>
        <div><div class="ic-label">Refund Rate</div><div class="ic-val" id="ic-refrate-val"><?= number_format($refundRate, 1) ?>% of orders &mdash; <?= $refundCount ?> refunded</div></div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── PERIOD SUMMARY ── -->
<?php if ($orderCount > 0):
    $marginHealth = $margin >= 50 ? 'excellent' : ($margin >= 30 ? 'healthy' : 'below the 30% target');
    $marginClass  = $margin >= 50 ? 'good' : ($margin >= 30 ? 'ok' : 'warn');

    if ($refundCount > 0) {
        $refundRate2 = round($refundCount / $orderCount * 100, 1);
        $refundSentence = " <strong>{$refundCount}</strong> " . ($refundCount === 1 ? 'order was' : 'orders were') . " refunded, totalling <strong>\$" . fmtMoney($totalRefunded) . "</strong> ({$refundRate2}% refund rate), bringing the take-home revenue to <strong>\$" . fmtMoney($netRevenue) . "</strong>.";
    } else {
        $refundSentence = " No refunds were issued, so the full <strong>\$" . fmtMoney($netRevenue) . "</strong> is the take-home revenue.";
    }

    $peakSentence = ($mode === 'daily' && $peakHour) ? " The busiest sales hour was <strong>{$peakHour}</strong>." : '';

    $topProdSentence = '';
    if (!empty($topProducts)) {
        $tp   = array_key_first($topProducts);
        $tqty = (int)$topProducts[$tp]['qty'];
        $topProdSentence = " Best-selling item: <strong>" . htmlspecialchars($tp) . "</strong> with <strong>{$tqty}</strong> " . ($tqty === 1 ? 'unit' : 'units') . " sold.";
    }
?>
<div class="card" style="margin-bottom:16px;">
    <div class="section-hdr">
        <div class="section-hdr-row">
            <i class="fa-solid fa-file-lines"></i>
            <span class="section-hdr-title">Period Summary</span>
            <span class="section-hdr-badge">&nbsp;<?= htmlspecialchars($label) ?></span>
        </div>
        <p class="section-desc">Auto-generated plain-English overview of this period's performance.</p>
    </div>
    <p class="report-summary" id="live-summary">
        During <strong><?= htmlspecialchars($label) ?></strong>, the café completed <strong><?= $orderCount ?></strong> <?= $orderCount === 1 ? 'order' : 'orders' ?><?= $totalItemsSold > 0 ? ', serving <strong>' . $totalItemsSold . '</strong> items,' : '' ?> totalling <strong>$<?= fmtMoney($totalSales) ?></strong> in sales (avg <strong>$<?= fmtMoney($avgOrder) ?></strong> per order). After ingredient costs of <strong>$<?= fmtMoney($totalCOGS) ?></strong>, the gross profit was <strong>$<?= fmtMoney($totalProfit) ?></strong> — a margin of <strong class="<?= $marginClass ?>"><?= number_format($margin, 1) ?>%</strong>, which is <?= $marginHealth ?>.<?= $refundSentence ?><?= $peakSentence ?><?= $topProdSentence ?>
    </p>
</div>
<?php endif; ?>

<div class="card">
    <div class="section-hdr">
        <div class="section-hdr-row">
            <i class="fa-solid fa-chart-bar"></i>
            <span class="section-hdr-title">Sales Analytics</span>
        </div>
        <p class="section-desc">Quantity sold per category and your top-performing products for this period.</p>
    </div>

    <?php if (count($categorySales) > 0 || count($topProducts) > 0): ?>
        <div class="chart-grid">
            <!-- LEFT: Category Sales (BAR CHART) -->
            <div class="chart-wrap">
                <div class="chart-title">
                    <i class="fa-solid fa-tags"></i> Category Sales
                </div>
                <canvas id="categoryChart" role="img" aria-label="Quantity sold per product category for this period"></canvas>
            </div>

            <!-- RIGHT: Top Products (LINE CHART) -->
            <div class="chart-wrap">
                <div class="chart-title">
                    <i class="fa-solid fa-trophy"></i> Top Products
                </div>
                <canvas id="productChart" role="img" aria-label="Top-performing products by revenue for this period"></canvas>
            </div>
        </div>
    <?php else: ?>
        <div class="empty">
            <i class="fa-regular fa-chart-bar"></i>
            No sales data in this range.
        </div>
    <?php endif; ?>
</div>

<!-- ── DAILY TREND (monthly / range mode) ── -->
<?php if ($mode !== 'daily' && !empty($dailyTrendData)): ?>
<div class="card">
    <div class="section-hdr">
        <div class="section-hdr-row">
            <i class="fa-solid fa-chart-area"></i>
            <span class="section-hdr-title">Daily Sales Trend</span>
        </div>
        <p class="section-desc">Revenue and order count per day — spot your busiest days and slow periods across the selected range.</p>
    </div>
    <div class="chart-wrap" style="height:280px;">
        <canvas id="dailyTrendChart" role="img" aria-label="Daily sales trend across the selected period"></canvas>
    </div>
</div>
<?php endif; ?>

<!-- ── HOURLY SALES (daily mode only) ── -->
<?php if ($mode === 'daily' && !empty($hourlyData)): ?>
<div class="card">
    <div class="section-hdr">
        <div class="section-hdr-row">
            <i class="fa-solid fa-clock"></i>
            <span class="section-hdr-title">Sales by Hour</span>
            <?php if ($peakHour): ?>
            <span class="section-hdr-tag"><i class="fa-solid fa-fire"></i> Peak: <?= $peakHour ?></span>
            <?php endif; ?>
        </div>
        <p class="section-desc">Hourly revenue and order volume from 06:00–22:00. Highlighted bar = highest-revenue hour. Right panel ranks top 5 hours.</p>
    </div>
    <div class="hourly-grid" style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:stretch;">
        <!-- Main hourly timeline -->
        <div class="chart-wrap" style="height:280px;aspect-ratio:unset;min-height:unset;">
            <canvas id="hourlyChart" role="img" aria-label="Sales broken down by hour of the day"></canvas>
        </div>
        <!-- Top revenue hours (right panel) -->
        <div class="chart-wrap" style="height:280px;aspect-ratio:unset;min-height:unset;display:flex;flex-direction:column;">
            <div style="font-size:13px;font-weight:600;color:var(--gold);margin-bottom:12px;display:flex;align-items:center;gap:8px;">
                <i class="fa-solid fa-ranking-star" style="color:var(--accent-2);"></i>
                Top Revenue Hours
            </div>
            <canvas id="topHoursChart" style="flex:1;" role="img" aria-label="Busiest hours ranked by sales"></canvas>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── PAYMENT METHOD BREAKDOWN ── -->
<?php if (!empty($paymentMethods)): ?>
<div class="card">
    <div class="section-hdr">
        <div class="section-hdr-row">
            <i class="fa-solid fa-credit-card"></i>
            <span class="section-hdr-title">Payment Methods</span>
        </div>
        <p class="section-desc">How customers paid — useful for cash drawer reconciliation and understanding payment preferences.</p>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:center;">
        <div class="chart-wrap" style="height:240px;">
            <canvas id="paymentChart" role="img" aria-label="Sales split by payment method"></canvas>
        </div>
        <div>
            <?php foreach ($paymentMethods as $pm): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border);">
                <div>
                    <div style="font-weight:600;color:var(--text);"><?= htmlspecialchars($pm['method']) ?></div>
                    <div style="font-size:12px;color:var(--text-muted);"><?= $pm['count'] ?> order<?= $pm['count']!==1?'s':'' ?></div>
                </div>
                <div style="font-weight:700;color:var(--accent-2);font-size:16px;">$<?= fmtMoney($pm['revenue']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── TOP PRODUCTS TABLE ── -->
<?php if (!empty($topProducts)): ?>
<div class="card">
    <div class="section-hdr">
        <div class="section-hdr-row">
            <i class="fa-solid fa-table"></i>
            <span class="section-hdr-title">Product Breakdown</span>
        </div>
        <p class="section-desc">Per-item revenue, ingredient cost (COGS), profit, and margin. Click any column header to sort. Green margin = ≥50%, orange = ≥25%, yellow = below 25%.</p>
    </div>
    <div class="refund-table-wrapper">
        <table class="refund-table" id="productsTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th onclick="sortTable(1,'str')" style="cursor:pointer;" title="Sort by name">Product <i class="fa-solid fa-sort" style="font-size:10px;opacity:0.5;"></i></th>
                    <th onclick="sortTable(2,'num')" style="cursor:pointer;text-align:right;" title="Sort by qty">Qty <i class="fa-solid fa-sort" style="font-size:10px;opacity:0.5;"></i></th>
                    <th onclick="sortTable(3,'money')" style="cursor:pointer;text-align:right;" title="Sort by revenue">Revenue <i class="fa-solid fa-sort" style="font-size:10px;opacity:0.5;"></i></th>
                    <th onclick="sortTable(4,'money')" style="cursor:pointer;text-align:right;" title="Sort by cogs">COGS <i class="fa-solid fa-sort" style="font-size:10px;opacity:0.5;"></i></th>
                    <th onclick="sortTable(5,'money')" style="cursor:pointer;text-align:right;" title="Sort by profit">Profit <i class="fa-solid fa-sort" style="font-size:10px;opacity:0.5;"></i></th>
                    <th onclick="sortTable(6,'pct')" style="cursor:pointer;text-align:right;" title="Sort by margin">Margin <i class="fa-solid fa-sort" style="font-size:10px;opacity:0.5;"></i></th>
                </tr>
            </thead>
            <tbody>
                <?php $rank=1; foreach ($topProducts as $pname => $pd):
                    $rev  = (float)$pd['revenue'];
                    $cogs = (float)$pd['cogs'];
                    $prof = $rev - $cogs;
                    $mgn  = $rev > 0 ? ($prof / $rev * 100) : 0;
                    $mgClass = $mgn >= 50 ? 'color:var(--pos)' : ($mgn >= 25 ? 'color:var(--accent-2)' : 'color:var(--amber)');
                ?>
                <tr>
                    <td style="color:var(--text-muted);font-weight:600;"><?= $rank++ ?></td>
                    <td style="font-weight:600;"><?= htmlspecialchars($pname) ?></td>
                    <td style="text-align:right;"><?= (int)$pd['qty'] ?></td>
                    <td style="text-align:right;color:var(--accent-2);font-weight:600;">$<?= fmtMoney($rev) ?></td>
                    <td style="text-align:right;color:var(--text-muted);">$<?= fmtMoney($cogs) ?></td>
                    <td style="text-align:right;font-weight:700;<?= $prof>=0?'color:var(--pos)':'color:var(--neg)' ?>">
                        <?= $prof >= 0 ? '+' : '' ?>$<?= fmtMoney($prof) ?>
                    </td>
                    <td style="text-align:right;font-weight:700;<?= $mgClass ?>">
                        <?= number_format($mgn, 1) ?>%
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="report-pager" id="productsPager"></div>
</div>
<?php endif; ?>

<!-- ── NEW: REFUND CHART ── -->
<?php if (count($refundChartData) > 0): ?>
<div class="card">
    <div class="section-hdr">
        <div class="section-hdr-row">
            <i class="fa-solid fa-rotate-left" style="color:var(--refund-color)"></i>
            <span class="section-hdr-title" style="color:var(--refund-light)">Refund Analytics</span>
        </div>
        <p class="section-desc">Refund frequency over time — bars show count, line shows total amount refunded. Helps identify patterns or staff issues.</p>
    </div>

    <div class="chart-grid">
        <!-- Refund Chart -->
        <div class="chart-wrap refund-chart" style="grid-column: 1 / -1;">
            <div class="chart-title">
                <i class="fa-solid fa-chart-area"></i> Refunds Over Time
            </div>
            <canvas id="refundChart" role="img" aria-label="Refunds over the selected period"></canvas>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── NEW: REFUND LIST ── -->
<?php if (count($refundOrders) > 0): ?>
<div class="card">
    <div class="section-hdr">
        <div class="section-hdr-row">
            <i class="fa-solid fa-list" style="color:var(--refund-color)"></i>
            <span class="section-hdr-title" style="color:var(--refund-light)">Refunded Orders</span>
            <span class="section-hdr-badge"><?= $refundCount ?> order<?= $refundCount !== 1 ? 's' : '' ?></span>
        </div>
        <p class="section-desc">Full list of refunds processed in this period — original amount, refund amount, stated reason, and who processed it.</p>
    </div>

    <div class="refund-table-wrapper">
        <table class="refund-table" id="refundTable">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Customer</th>
                    <th>Original Total</th>
                    <th>Refund Amount</th>
                    <th>Reason</th>
                    <th>Refunded By</th>
                    <th>Refunded At</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($refundOrders as $r): ?>
                <tr>
                    <td>#<?= $r['daily_order_no'] ?></td>
                    <td><?= htmlspecialchars($r['customer_name']) ?></td>
                    <td>$<?= fmtMoney($r['original_total']) ?></td>
                    <td class="refund-amount">-$<?= fmtMoney($r['refund_amount']) ?></td>
                    <td class="refund-reason">"<?= htmlspecialchars($r['refund_reason']) ?>"</td>
                    <td class="refunded-by"><?= htmlspecialchars($r['refunded_by']) ?></td>
                    <td style="font-size:13px; color:var(--text-muted);">
                        <?= date('M d, g:i A', strtotime($r['refunded_at'])) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="report-pager" id="refundPager"></div>
</div>
<?php endif; ?>

<?php if ($remakeCount > 0): ?>
<div class="report-section">
    <div class="section-hdr">
        <i class="fa-solid fa-repeat" style="color:var(--warn);"></i>
        <span class="section-hdr-title" style="color:var(--warn);">Remade Orders</span>
        <span class="section-hdr-badge"><?= $remakeCount ?> remake<?= $remakeCount !== 1 ? 's' : '' ?></span>
    </div>
    <p class="section-desc">Drinks that were remade due to quality issues — useful for spotting recurring problems with specific drinks or baristas.</p>

    <div class="refund-table-wrapper">
        <table class="refund-table" id="remakeTable">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Customer</th>
                    <th>Drinks</th>
                    <th>Reason</th>
                    <th>Logged By</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($remakeOrders as $r): ?>
                <tr>
                    <td>#<?= $r['daily_order_no'] ?></td>
                    <td><?= htmlspecialchars($r['customer_name']) ?></td>
                    <td style="color:var(--text);"><?= htmlspecialchars($r['products'] ?? '—') ?></td>
                    <td class="refund-reason">"<?= htmlspecialchars($r['reason']) ?>"</td>
                    <td class="refunded-by"><?= htmlspecialchars($r['remade_by']) ?></td>
                    <td style="font-size:13px; color:var(--text-muted);">
                        <?= date('M d, g:i A', strtotime($r['remade_at'])) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="report-pager" id="remakePager"></div>
</div>
<?php endif; ?>

<script>
// ── Apply saved theme immediately (prevents flash) ──
(function() {
    if (localStorage.getItem('theme') === 'light') {
        document.documentElement.setAttribute('data-theme', 'light');
    }
})();

// ── Theme Toggle ──
function toggleTheme() {
    const html = document.documentElement;
    const icon = document.getElementById('themeIcon');
    const text = document.getElementById('themeText');
    if (html.getAttribute('data-theme') === 'light') {
        html.removeAttribute('data-theme');
        if (icon) icon.className = 'fa-solid fa-moon';
        if (text) text.textContent = 'Dark';
        localStorage.setItem('theme', 'dark');
    } else {
        html.setAttribute('data-theme', 'light');
        if (icon) icon.className = 'fa-solid fa-sun';
        if (text) text.textContent = 'Light';
        localStorage.setItem('theme', 'light');
    }
    if (typeof rebuildAllCharts === 'function') rebuildAllCharts();
}

// ── Sync toggle button state on load ──
document.addEventListener('DOMContentLoaded', function() {
    if (localStorage.getItem('theme') === 'light') {
        document.documentElement.setAttribute('data-theme', 'light');
        const icon = document.getElementById('themeIcon');
        const text = document.getElementById('themeText');
        if (icon) icon.className = 'fa-solid fa-sun';
        if (text) text.textContent = 'Light';
    }
});

// ── Responsive chart helper ──
function getChartConfig() {
    const w = window.innerWidth;
    if (w < 480) return { mobile: true,  fontSize: 9,  ticksX: 4, ticksY: 4, pointR: 3 };
    if (w < 768) return { mobile: true,  fontSize: 10, ticksX: 5, ticksY: 5, pointR: 4 };
    if (w < 1024)return { mobile: false, fontSize: 11, ticksX: 6, ticksY: 6, pointR: 5 };
    return             { mobile: false, fontSize: 12, ticksX: 8, ticksY: 7, pointR: 6 };
}

// ── Shared tooltip style ──
const tooltipDefaults = {
    backgroundColor: 'rgba(18,18,18,0.95)',
    titleColor: '#fff',
    titleFont: { family: 'Poppins', size: 14, weight: '600' },
    bodyColor: '#e3b382',
    bodyFont: { family: 'Poppins', size: 13 },
    borderColor: 'rgba(209,144,75,0.3)',
    borderWidth: 1,
    padding: 12,
    cornerRadius: 10,
};

// ── Theme-aware chart tokens (re-read live so charts follow light/dark) ──
function CT() {
    const cs = getComputedStyle(document.documentElement);
    const light = document.documentElement.getAttribute('data-theme') === 'light';
    return {
        text:    (cs.getPropertyValue('--text-muted') || '').trim() || '#aaa',
        grid:    light ? 'rgba(17,24,39,0.08)' : 'rgba(255,255,255,0.06)',
        surface: (cs.getPropertyValue('--bg-card') || '').trim() || '#121212'
    };
}

// ── CATEGORY CHART ──
const categoryLabels = <?= json_encode(array_keys($categorySales), JSON_UNESCAPED_UNICODE) ?>;
const categoryQty    = <?= json_encode(array_map(function($d) {
    return (float)$d['qty'];
}, array_values($categorySales))) ?>;
const categoryCanvas = document.getElementById('categoryChart');
let categoryChart    = null;

function buildCategoryChart() {
    if (!categoryCanvas || categoryLabels.length === 0) return;
    const cfg = getChartConfig();
    const maxDisplay   = Math.min(8, categoryLabels.length);
    const displayLabels = categoryLabels.slice(0, maxDisplay);
    const displayData   = categoryQty.slice(0, maxDisplay);

    const colors = [
        'rgba(209,144,75,0.85)','rgba(227,179,130,0.85)',
        'rgba(181,123,59,0.85)','rgba(245,194,123,0.85)',
        'rgba(160,100,45,0.85)','rgba(200,150,80,0.85)',
        'rgba(220,170,110,0.85)'
    ];

    if (categoryChart) categoryChart.destroy();

    categoryChart = new Chart(categoryCanvas, {
        type: 'bar',
        data: {
            labels: displayLabels,
            datasets: [{
                label: 'Quantity Sold',
                data: displayData,
                backgroundColor: colors.slice(0, displayLabels.length),
                borderColor: '#d1904b',
                borderWidth: 1,
                borderRadius: 8,
                hoverBackgroundColor: 'rgba(209,144,75,1)',
                hoverBorderColor: '#e3b382',
                hoverBorderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            clip: false,
            layout: {
                padding: {
                    left: 10,
                    right: 10,
                    bottom: 20
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...tooltipDefaults,
                    callbacks: { label: ctx => ' Qty: ' + ctx.raw }
                }
            },
            scales: {
                x: {
                    ticks: {
                        color: CT().text,
                        font: { family: 'Poppins', size: cfg.fontSize },
                        maxRotation: 30,
                        minRotation: 0,
                        autoSkip: false,
                        callback: function(value, index, ticks) {
                            const label = this.getLabelForValue(value);
                            if (typeof label !== 'string') return label;
                            
                            const chartWidth = this.chart.width;
                            const tickCount = ticks.length || 1;
                            const pxPerTick = chartWidth / tickCount;
                            const charsAllowed = Math.max(5, Math.floor(pxPerTick / (cfg.fontSize * 0.55)));
                            
                            return label.length > charsAllowed
                                ? label.slice(0, charsAllowed - 1) + '…'
                                : label;
                        }
                    },
                    grid: { display: false },
                    barPercentage: 0.9,
                    categoryPercentage: 1.0
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: CT().text,
                        precision: 0,
                        font: { family: 'Poppins', size: cfg.fontSize },
                        maxTicksLimit: cfg.ticksY
                    },
                    grid: { color: CT().grid, drawBorder: false }
                }
            },
            animation: { duration: 800, easing: 'easeOutQuart' }
        }
    });
}

// ── TOP PRODUCTS CHART ──
const productLabels = <?= json_encode(array_keys($topProducts), JSON_UNESCAPED_UNICODE) ?>;
const productQty    = <?= json_encode(array_map(function($d) {
    return (float)$d['qty'];
}, array_values($topProducts))) ?>;
const productCanvas = document.getElementById('productChart');
let productChart    = null;

function buildProductChart() {
    if (!productCanvas || productLabels.length === 0) return;
    const cfg = getChartConfig();
    const maxDisplay    = Math.min(5, productLabels.length);
    const displayLabels = productLabels.slice(0, maxDisplay);
    const displayData   = productQty.slice(0, maxDisplay);

    if (productChart) productChart.destroy();

    productChart = new Chart(productCanvas, {
        type: 'line',
        data: {
            labels: displayLabels,
            datasets: [{
                label: 'Quantity Sold',
                data: displayData,
                borderColor: '#d1904b',
                backgroundColor: 'rgba(209,144,75,0.12)',
                borderWidth: cfg.mobile ? 2 : 3,
                pointBackgroundColor: '#d1904b',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: cfg.pointR,
                pointHoverRadius: cfg.pointR + 2,
                tension: 0.4,
                fill: true,
                borderCapStyle: 'round',
                borderJoinStyle: 'round'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            clip: false,
            layout: {
                padding: {
                    left: 10,
                    right: 10,
                    bottom: 20
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...tooltipDefaults,
                    callbacks: { label: ctx => ' Qty: ' + ctx.raw }
                }
            },
            scales: {
                x: {
                    ticks: {
                        color: CT().text,
                        font: { family: 'Poppins', size: cfg.fontSize },
                        maxRotation: 30,
                        minRotation: 0,
                        autoSkip: false,
                        callback: function(value, index, ticks) {
                            const label = this.getLabelForValue(value);
                            if (typeof label !== 'string') return label;
                            
                            const chartWidth = this.chart.width;
                            const tickCount = ticks.length || 1;
                            const pxPerTick = chartWidth / tickCount;
                            const charsAllowed = Math.max(5, Math.floor(pxPerTick / (cfg.fontSize * 0.55)));
                            
                            return label.length > charsAllowed
                                ? label.slice(0, charsAllowed - 1) + '…'
                                : label;
                        }
                    },
                    grid: { display: false }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: CT().text,
                        precision: 0,
                        font: { family: 'Poppins', size: cfg.fontSize },
                        maxTicksLimit: cfg.ticksY
                    },
                    grid: { color: CT().grid, drawBorder: false }
                }
            },
            animation: { duration: 1000, easing: 'easeOutQuart' }
        }
    });
}

// ── REFUND CHART ──
const refundChartData = <?= json_encode($refundChartData, JSON_UNESCAPED_UNICODE) ?>;
const refundCanvas = document.getElementById('refundChart');
let refundChart = null;

function buildRefundChart() {
    if (!refundCanvas || refundChartData.length === 0) return;
    const cfg = getChartConfig();
    
    const labels = refundChartData.map(d => d.label);
    const counts = refundChartData.map(d => d.count);
    const totals = refundChartData.map(d => d.total);

    if (refundChart) refundChart.destroy();

    refundChart = new Chart(refundCanvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Number of Refunds',
                    data: counts,
                    backgroundColor: 'rgba(155,89,182,0.6)',
                    borderColor: '#9b59b6',
                    borderWidth: 1,
                    borderRadius: 6,
                    categoryPercentage: 0.55,
                    barPercentage: 0.8,
                    maxBarThickness: 54,
                    yAxisID: 'y',
                    order: 2
                },
                {
                    label: 'Refund Amount ($)',
                    data: totals,
                    type: 'line',
                    borderColor: '#bb8fce',
                    backgroundColor: 'rgba(187,143,206,0.1)',
                    borderWidth: 2,
                    pointBackgroundColor: '#bb8fce',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: cfg.pointR,
                    pointHoverRadius: cfg.pointR + 3,
                    tension: 0.4,
                    fill: true,
                    yAxisID: 'y1',
                    order: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            clip: false,
            layout: {
                padding: {
                    left: 10,
                    right: 10,
                    bottom: 20
                }
            },
            plugins: {
                legend: {
                    labels: {
                        color: CT().text,
                        font: { family: 'Poppins', size: 12 },
                        padding: 16
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(18,18,18,0.95)',
                    titleColor: '#fff',
                    titleFont: { family: 'Poppins', size: 14, weight: '600' },
                    bodyColor: '#bb8fce',
                    bodyFont: { family: 'Poppins', size: 13 },
                    borderColor: 'rgba(155,89,182,0.3)',
                    borderWidth: 1,
                    padding: 12,
                    cornerRadius: 10,
                    callbacks: {
                        label: function(ctx) {
                            if (ctx.dataset.label === 'Number of Refunds') {
                                return ' Refunds: ' + ctx.raw;
                            } else {
                                return ' Amount: $' + ctx.raw.toFixed(2);
                            }
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: {
                        color: CT().text,
                        font: { family: 'Poppins', size: cfg.fontSize },
                        maxRotation: 30,
                        minRotation: 0,
                        autoSkip: false
                    },
                    grid: { display: false }
                },
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    beginAtZero: true,
                    ticks: {
                        color: CT().text,
                        font: { family: 'Poppins', size: cfg.fontSize },
                        precision: 0,
                        maxTicksLimit: cfg.ticksY
                    },
                    grid: { color: CT().grid, drawBorder: false },
                    title: {
                        display: true,
                        text: 'Number of Refunds',
                        color: CT().text,
                        font: { family: 'Poppins', size: 11 }
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    beginAtZero: true,
                    ticks: {
                        color: CT().text,
                        font: { family: 'Poppins', size: cfg.fontSize },
                        callback: function(value) {
                            return '$' + value.toFixed(2);
                        },
                        maxTicksLimit: cfg.ticksY
                    },
                    grid: { display: false },
                    title: {
                        display: true,
                        text: 'Refund Amount ($)',
                        color: CT().text,
                        font: { family: 'Poppins', size: 11 }
                    }
                }
            },
            animation: { duration: 1000, easing: 'easeOutQuart' }
        }
    });
}

// ── HOURLY SALES CHART ──
let hourlyRawData = <?= json_encode($hourlyData) ?>;
const hourlyCanvas  = document.getElementById('hourlyChart');
let hourlyChart     = null;

function buildHourlyChart() {
    if (!hourlyCanvas || !hourlyRawData.length) return;
    const cfg     = getChartConfig();
    const labels  = hourlyRawData.map(d => d.label);
    const counts  = hourlyRawData.map(d => d.count);
    const revenue = hourlyRawData.map(d => d.revenue);

    if (hourlyChart) hourlyChart.destroy();

    // Highlight the peak hour
    const maxRev = Math.max(...revenue);
    const bgColors = revenue.map(v =>
        v === maxRev && v > 0 ? 'rgba(209,144,75,1)' : 'rgba(209,144,75,0.5)'
    );

    hourlyChart = new Chart(hourlyCanvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Revenue ($)',
                data: revenue,
                backgroundColor: bgColors,
                borderColor: '#d1904b',
                borderWidth: 1,
                borderRadius: 6,
                yAxisID: 'y',
                order: 2,
            }, {
                label: 'Orders',
                data: counts,
                type: 'line',
                borderColor: '#e8b87a',
                backgroundColor: 'rgba(232,184,122,0.08)',
                borderWidth: 2,
                pointBackgroundColor: '#e8b87a',
                pointRadius: cfg.pointR - 1,
                pointHoverRadius: cfg.pointR + 1,
                tension: 0.4,
                fill: true,
                yAxisID: 'y1',
                order: 1,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, clip: false,
            layout: { padding: { left: 8, right: 8, bottom: 8 } },
            plugins: {
                legend: { labels: { color: CT().text, font: { family: 'Poppins', size: 11 }, padding: 12 } },
                tooltip: {
                    ...tooltipDefaults,
                    callbacks: {
                        label: ctx => ctx.dataset.label === 'Orders'
                            ? ' Orders: ' + ctx.raw
                            : ' Revenue: $' + ctx.raw.toFixed(2)
                    }
                }
            },
            scales: {
                x: {
                    ticks: {
                        color: CT().text,
                        font: { family: 'Poppins', size: cfg.fontSize },
                        maxRotation: 0,
                        minRotation: 0,
                        autoSkip: true,
                        maxTicksLimit: 9
                    },
                    grid: { display: false }
                },
                y: {
                    position: 'left', beginAtZero: true,
                    ticks: { color: CT().text, font: { family: 'Poppins', size: cfg.fontSize }, callback: v => '$' + v.toFixed(0), maxTicksLimit: 5 },
                    grid: { color: CT().grid }
                },
                y1: {
                    position: 'right', beginAtZero: true,
                    ticks: { color: CT().text, font: { family: 'Poppins', size: cfg.fontSize }, precision: 0, maxTicksLimit: 5 },
                    grid: { display: false }
                }
            },
            animation: { duration: 900, easing: 'easeOutQuart' }
        }
    });
}

// ── TOP REVENUE HOURS CHART ──
const topHoursCanvas = document.getElementById('topHoursChart');
let   topHoursChart  = null;

function buildTopHoursChart() {
    if (!topHoursCanvas || !hourlyRawData.length) return;
    const cfg = getChartConfig();

    // Filter hours with activity, sort by revenue desc, take top 5
    const active = hourlyRawData
        .filter(d => d.revenue > 0 || d.count > 0)
        .sort((a, b) => b.revenue - a.revenue)
        .slice(0, 5);

    if (!active.length) return;

    const labels  = active.map(d => d.label);
    const revs    = active.map(d => d.revenue);
    const cts     = active.map(d => d.count);
    const maxRev  = Math.max(...revs);

    const colors  = revs.map(v =>
        v === maxRev ? 'rgba(227,179,130,0.95)' : 'rgba(209,144,75,0.65)'
    );

    if (topHoursChart) topHoursChart.destroy();

    topHoursChart = new Chart(topHoursCanvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Revenue ($)',
                data: revs,
                backgroundColor: colors,
                borderColor: '#d1904b',
                borderWidth: 1,
                borderRadius: 6,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: { right: 8 } },
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...tooltipDefaults,
                    callbacks: {
                        label: ctx => {
                            const h = active[ctx.dataIndex];
                            return [
                                ' Revenue: $' + ctx.raw.toFixed(2),
                                ' Orders:  ' + h.count
                            ];
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
                        color: CT().text,
                        font: { family: 'Poppins', size: cfg.fontSize },
                        callback: v => '$' + v.toFixed(0),
                        maxTicksLimit: 5
                    },
                    grid: { color: CT().grid }
                },
                y: {
                    ticks: {
                        color: '#e3b382',
                        font: { family: 'Poppins', size: cfg.fontSize + 1, weight: '600' }
                    },
                    grid: { display: false }
                }
            },
            animation: { duration: 800, easing: 'easeOutQuart' }
        }
    });
}

// ── DAILY TREND CHART (monthly / range mode) ──
const dailyTrendRaw    = <?= json_encode($dailyTrendData) ?>;
const dailyTrendCanvas = document.getElementById('dailyTrendChart');
let   dailyTrendChart  = null;

function buildDailyTrendChart() {
    if (!dailyTrendCanvas || !dailyTrendRaw.length) return;
    const cfg     = getChartConfig();
    const labels  = dailyTrendRaw.map(d => d.label);
    const counts  = dailyTrendRaw.map(d => d.count);
    const revenue = dailyTrendRaw.map(d => d.revenue);

    if (dailyTrendChart) dailyTrendChart.destroy();

    const maxRev  = Math.max(...revenue);
    const bgColors = revenue.map(v => v === maxRev && v > 0 ? 'rgba(209,144,75,1)' : 'rgba(209,144,75,0.55)');

    // Auto-limit x ticks based on data density
    const maxLabels = Math.min(labels.length, cfg.mobile ? 8 : 14);

    dailyTrendChart = new Chart(dailyTrendCanvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Revenue ($)',
                data: revenue,
                backgroundColor: bgColors,
                borderColor: '#d1904b',
                borderWidth: 1,
                borderRadius: 6,
                yAxisID: 'y',
                order: 2,
            }, {
                label: 'Orders',
                data: counts,
                type: 'line',
                borderColor: '#e8b87a',
                backgroundColor: 'rgba(232,184,122,0.08)',
                borderWidth: 2,
                pointBackgroundColor: '#e8b87a',
                pointRadius: cfg.pointR - 1,
                pointHoverRadius: cfg.pointR + 1,
                tension: 0.4,
                fill: true,
                yAxisID: 'y1',
                order: 1,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, clip: false,
            layout: { padding: { left: 8, right: 8, bottom: 8 } },
            plugins: {
                legend: { labels: { color: CT().text, font: { family: 'Poppins', size: 11 }, padding: 12 } },
                tooltip: {
                    ...tooltipDefaults,
                    callbacks: {
                        label: ctx => ctx.dataset.label === 'Orders'
                            ? ' Orders: ' + ctx.raw
                            : ' Revenue: $' + ctx.raw.toFixed(2)
                    }
                }
            },
            scales: {
                x: {
                    ticks: {
                        color: CT().text,
                        font: { family: 'Poppins', size: cfg.fontSize },
                        maxRotation: 30,
                        minRotation: 0,
                        autoSkip: true,
                        maxTicksLimit: maxLabels
                    },
                    grid: { display: false }
                },
                y: {
                    position: 'left', beginAtZero: true,
                    ticks: { color: CT().text, font: { family: 'Poppins', size: cfg.fontSize }, callback: v => '$' + v.toFixed(0), maxTicksLimit: 5 },
                    grid: { color: CT().grid }
                },
                y1: {
                    position: 'right', beginAtZero: true,
                    ticks: { color: CT().text, font: { family: 'Poppins', size: cfg.fontSize }, precision: 0, maxTicksLimit: 5 },
                    grid: { display: false }
                }
            },
            animation: { duration: 900, easing: 'easeOutQuart' }
        }
    });
}

// ── PAYMENT METHOD CHART ──
const paymentRawData = <?= json_encode($paymentMethods) ?>;
const paymentCanvas  = document.getElementById('paymentChart');
let paymentChart     = null;

function buildPaymentChart() {
    if (!paymentCanvas || !paymentRawData.length) return;
    if (paymentChart) paymentChart.destroy();

    const labels   = paymentRawData.map(d => d.method);
    const revenues = paymentRawData.map(d => d.revenue);
    const palette  = ['#d1904b', '#3498db', '#9b59b6', '#55e087', '#f1c40f'];

    paymentChart = new Chart(paymentCanvas, {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{ data: revenues, backgroundColor: palette.slice(0, labels.length), borderWidth: 2, borderColor: CT().surface, hoverOffset: 8 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '62%',
            plugins: {
                legend: { position: 'bottom', labels: { color: CT().text, font: { family: 'Poppins', size: 12 }, padding: 14 } },
                tooltip: { ...tooltipDefaults, callbacks: { label: ctx => ' $' + ctx.raw.toFixed(2) } }
            },
            animation: { duration: 900, animateRotate: true, easing: 'easeOutQuart' }
        }
    });
}

// ── EXPORT PDF ──
function exportPDF() {
    const params = new URLSearchParams(window.location.search);
    window.open('report_pdf.php?' + params.toString(), '_blank');
}

// ── EXPORT CSV ──
function exportCSV() {
    const rows = [['Product','Qty Sold','Revenue','COGS','Profit','Margin %']];
    const tbl  = document.getElementById('productsTable');
    if (tbl) {
        const trs = tbl.querySelectorAll('tbody tr');
        trs.forEach(tr => {
            const cells = tr.querySelectorAll('td');
            if (cells.length >= 7) {
                rows.push([
                    cells[1].textContent.trim(),
                    cells[2].textContent.trim(),
                    cells[3].textContent.trim(),
                    cells[4].textContent.trim(),
                    cells[5].textContent.trim(),
                    cells[6].textContent.trim(),
                ]);
            }
        });
    }
    rows.push([]);
    rows.push(['Metric', 'Value']);
    rows.push(['Orders', <?= $orderCount ?>]);
    rows.push(['Sales', '$<?= fmtMoney($totalSales) ?>']);
    rows.push(['Food Cost', '$<?= fmtMoney($totalCOGS) ?>']);
    rows.push(['Profit', '$<?= fmtMoney($totalProfit) ?>']);
    rows.push(['Profit Margin', '<?= number_format($margin, 1) ?>%']);
    rows.push(['Avg per Order', '$<?= fmtMoney($avgOrder) ?>']);
    rows.push(['Refunds', '$<?= fmtMoney($totalRefunded) ?>']);
    rows.push(['Net Revenue', '$<?= fmtMoney($netRevenue) ?>']);
    rows.push(['Remakes', <?= (int)$remakeCount ?>]);
    rows.push(['Peak Hour', <?= json_encode((string)$peakHour) ?>]);

    const csv  = rows.map(r => r.map(c => '"' + String(c).replace(/"/g,'""') + '"').join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url; a.download = 'report_<?= $label ?>'.replace(/[^a-z0-9]/gi,'_') + '.csv';
    a.click(); URL.revokeObjectURL(url);
}

// ── TABLE SORT ──
let sortState = { col: -1, dir: 1 };
// ── Client-side table pagination ──
const __pagers = {};
function setupPager(tableId, pagerId, pageSize) {
    const tbl = document.getElementById(tableId);
    const pager = document.getElementById(pagerId);
    if (!tbl || !pager) return;
    __pagers[tableId] = { pagerId, pageSize, page: 1 };
    renderPagerPage(tableId);
}
function renderPagerPage(tableId) {
    const st = __pagers[tableId];
    if (!st) return;
    const tbl = document.getElementById(tableId);
    const rows = Array.from(tbl.querySelector('tbody').querySelectorAll('tr'));
    const pages = Math.max(1, Math.ceil(rows.length / st.pageSize));
    if (st.page > pages) st.page = pages;
    const start = (st.page - 1) * st.pageSize, end = start + st.pageSize;
    rows.forEach((r, i) => { r.style.display = (i >= start && i < end) ? '' : 'none'; });
    buildPagerControls(tableId, pages);
}
function buildPagerControls(tableId, pages) {
    const st = __pagers[tableId];
    const pager = document.getElementById(st.pagerId);
    if (pages <= 1) { pager.innerHTML = ''; return; }
    const p = st.page;
    const btn = (label, target, o = {}) => o.ellipsis
        ? '<span class="rp-ellipsis">…</span>'
        : `<button class="rp-btn${o.active ? ' active' : ''}" ${o.disabled ? 'disabled' : ''} data-go="${target}">${label}</button>`;
    let html = btn('«', 1, { disabled: p === 1 }) + btn('‹', p - 1, { disabled: p === 1 });
    const win = [];
    if (pages <= 7) { for (let i = 1; i <= pages; i++) win.push(i); }
    else {
        win.push(1);
        let s = Math.max(2, p - 1), e = Math.min(pages - 1, p + 1);
        if (s > 2) win.push('...');
        for (let i = s; i <= e; i++) win.push(i);
        if (e < pages - 1) win.push('...');
        win.push(pages);
    }
    win.forEach(n => { html += n === '...' ? btn('', 0, { ellipsis: true }) : btn(n, n, { active: n === p }); });
    html += btn('›', p + 1, { disabled: p === pages }) + btn('»', pages, { disabled: p === pages });
    pager.innerHTML = html;
    pager.querySelectorAll('button[data-go]').forEach(b => {
        b.addEventListener('click', () => {
            const g = parseInt(b.dataset.go);
            if (g >= 1 && g <= pages) { st.page = g; renderPagerPage(tableId); }
        });
    });
}
function pagerResetToFirst(tableId) {
    if (__pagers[tableId]) { __pagers[tableId].page = 1; renderPagerPage(tableId); }
}
document.addEventListener('DOMContentLoaded', function () {
    setupPager('productsTable', 'productsPager', 10);
    setupPager('refundTable',   'refundPager',   10);
    setupPager('remakeTable',   'remakePager',   10);
});

function sortTable(colIdx, type) {
    const tbl  = document.getElementById('productsTable');
    if (!tbl) return;
    const tbody = tbl.querySelector('tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));

    if (sortState.col === colIdx) { sortState.dir *= -1; } else { sortState.col = colIdx; sortState.dir = 1; }

    const parse = (cell, t) => {
        const txt = cell.textContent.trim().replace(/[$%+,]/g, '');
        if (t === 'num' || t === 'money' || t === 'pct') return parseFloat(txt) || 0;
        return txt.toLowerCase();
    };

    rows.sort((a, b) => {
        const ca = a.querySelectorAll('td')[colIdx];
        const cb = b.querySelectorAll('td')[colIdx];
        const va = parse(ca, type), vb = parse(cb, type);
        return va < vb ? -sortState.dir : va > vb ? sortState.dir : 0;
    });

    // Update rank numbers
    rows.forEach((r, i) => { const rankCell = r.querySelector('td:first-child'); if (rankCell) rankCell.textContent = i + 1; });
    rows.forEach(r => tbody.appendChild(r));

    // Re-apply pagination after re-ordering (jump back to first page)
    pagerResetToFirst('productsTable');

    // Update sort icons
    tbl.querySelectorAll('thead th').forEach((th, i) => {
        const ico = th.querySelector('i');
        if (!ico) return;
        ico.className = i === colIdx
            ? (sortState.dir === 1 ? 'fa-solid fa-sort-down' : 'fa-solid fa-sort-up')
            : 'fa-solid fa-sort';
        ico.style.opacity = i === colIdx ? '0.9' : '0.5';
    });
}

// ── (Re)build every chart — reused by initial render, resize, and theme toggle ──
function rebuildAllCharts() {
    buildCategoryChart();
    buildProductChart();
    buildRefundChart();
    buildHourlyChart();
    buildTopHoursChart();
    buildPaymentChart();
    buildDailyTrendChart();
}

// ── Initial render ──
rebuildAllCharts();

// ── Re-render on resize (debounced) ──
let resizeTimer;
window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(rebuildAllCharts, 250);
});

// ── LIVE POLLING ──
const LIVE_MODE    = <?= json_encode($isLive) ?>;
const REPORT_PARAMS = <?= json_encode([
    'mode'      => $mode,
    'date'      => $date      ?? null,
    'month'     => $month     ?? null,
    'from_date' => $fromDate  ?? null,
    'to_date'   => $toDate    ?? null,
]) ?>;

function fmt2(n) { return Number(n).toFixed(2); }

function flash(el) {
    if (!el) return;
    el.classList.remove('kpi-flash');
    void el.offsetWidth; // force reflow
    el.classList.add('kpi-flash');
}

function setEl(id, text, parentForFlash) {
    const el = document.getElementById(id);
    if (!el || el.textContent === text) return;
    el.textContent = text;
    flash(parentForFlash || el.closest('.kpi') || el);
}

function applyLiveData(d) {
    // ── KPI numbers ──
    setEl('kv-orders',   String(d.orderCount));
    setEl('kv-avg',      'Avg $' + fmt2(d.avgOrder) + ' · ' + (d.totalItemsSold ?? 0) + ' items');
    setEl('kv-sales',    '$' + fmt2(d.totalSales));
    setEl('kv-foodcost', '$' + fmt2(d.totalCOGS));
    setEl('kv-profit',   '$' + fmt2(d.totalProfit));
    setEl('kv-refunds',  '$' + fmt2(d.totalRefunded));
    setEl('kv-takehome', '$' + fmt2(d.netRevenue));

    // Margin badge
    const mb = document.getElementById('kv-margin');
    if (mb) {
        const newText = 'Margin: ' + d.margin + '%';
        const newClass = 'badge ' + (d.margin >= 30 ? 'good' : 'warn');
        if (mb.className !== newClass || mb.textContent.trim() !== newText) {
            mb.className = newClass;
            mb.innerHTML = '<i class="fa-solid fa-percent"></i> ' + newText;
            flash(mb.closest('.kpi'));
        }
    }

    // Refund count badge
    const rb = document.getElementById('kv-refcount');
    if (rb) {
        const newRc = d.refundCount + ' orders refunded';
        if (!rb.textContent.includes(d.refundCount)) {
            rb.innerHTML = '<i class="fa-solid fa-receipt"></i> ' + newRc;
            flash(rb.closest('.kpi'));
        }
    }

    // Peak hour
    const pk = document.getElementById('kv-peak');
    if (pk && d.peakHour) setEl('kv-peak', d.peakHour);

    // ── Insights strip ──
    if (d.topProduct) {
        const bsv = document.getElementById('ic-bestseller-val');
        if (bsv) bsv.textContent = d.topProduct + ' — ' + d.topProductQty + ' sold';
    }
    if (d.topCategory) {
        const tcv = document.getElementById('ic-topcat-val');
        if (tcv) tcv.textContent = d.topCategory + ' — ' + d.topCategoryQty + ' items';
    }
    const mgv = document.getElementById('ic-margin-val');
    if (mgv) {
        mgv.textContent = d.margin + '% — ' + (d.margin >= 30 ? 'healthy' : 'below 30% target');
        const mc = document.getElementById('ic-margin');
        if (mc) mc.className = 'insight-chip ' + (d.margin >= 30 ? 'ic-good' : 'ic-warn');
    }
    if (d.peakHour) {
        const pkv = document.getElementById('ic-peak-val');
        if (pkv) pkv.textContent = d.peakHour + ' — most orders this hour';
    }
    if (d.refundCount > 0) {
        const rrv = document.getElementById('ic-refrate-val');
        if (rrv) rrv.textContent = d.refundRate + '% of orders — ' + d.refundCount + ' refunded';
    }

    // ── Period summary ──
    const sumEl = document.getElementById('live-summary');
    if (sumEl && d.orderCount > 0) {
        const marginHealth = d.margin >= 50 ? 'excellent' : d.margin >= 30 ? 'healthy' : 'below the 30% target';
        const marginCls    = d.margin >= 50 ? 'good' : d.margin >= 30 ? 'ok' : 'warn';
        const refNote = d.refundCount > 0
            ? ' <strong>' + d.refundCount + '</strong> ' + (d.refundCount === 1 ? 'order was' : 'orders were') + ' refunded, totalling <strong>$' + fmt2(d.totalRefunded) + '</strong>, bringing take-home to <strong>$' + fmt2(d.netRevenue) + '</strong>.'
            : ' No refunds were issued, so the full <strong>$' + fmt2(d.netRevenue) + '</strong> is the take-home revenue.';
        const pkNote  = d.peakHour ? ' The busiest sales hour was <strong>' + d.peakHour + '</strong>.' : '';
        const tpNote  = d.topProduct ? ' Best-selling item: <strong>' + d.topProduct + '</strong> with <strong>' + d.topProductQty + '</strong> ' + (d.topProductQty === 1 ? 'unit' : 'units') + ' sold.' : '';
        const itemsNote = (d.totalItemsSold > 0) ? ', serving <strong>' + d.totalItemsSold + '</strong> items,' : '';
        sumEl.innerHTML = 'During <strong>' + sumEl.dataset.label + '</strong>, the café completed <strong>' + d.orderCount + '</strong> ' + (d.orderCount === 1 ? 'order' : 'orders') + itemsNote + ' totalling <strong>$' + fmt2(d.totalSales) + '</strong> in sales (avg <strong>$' + fmt2(d.avgOrder) + '</strong> per order). After ingredient costs of <strong>$' + fmt2(d.totalCOGS) + '</strong>, the gross profit was <strong>$' + fmt2(d.totalProfit) + '</strong> — a margin of <strong class="' + marginCls + '">' + d.margin + '%</strong>, which is ' + marginHealth + '.' + refNote + pkNote + tpNote;
    }

    // ── Hourly chart (daily mode only) ──
    if (d.hourlyData && d.hourlyData.length && typeof hourlyChart !== 'undefined' && hourlyChart) {
        hourlyRawData = d.hourlyData;
        const revenue = d.hourlyData.map(h => h.revenue);
        const counts  = d.hourlyData.map(h => h.count);
        const maxRev  = Math.max(...revenue);
        const bgColors = revenue.map(v => v > 0 && v === maxRev ? 'rgba(227,179,130,0.95)' : 'rgba(209,144,75,0.65)');
        hourlyChart.data.datasets[0].data = revenue;
        hourlyChart.data.datasets[0].backgroundColor = bgColors;
        hourlyChart.data.datasets[1].data = counts;
        hourlyChart.update('none');
        buildTopHoursChart();
    }

    // ── Goal progress bar ──
    const goalFill = document.getElementById('goal-fill');
    if (goalFill) {
        const target = <?= json_encode($dailyTarget) ?>;
        const pct = target > 0 ? Math.min(100, d.totalSales / target * 100) : 0;
        goalFill.style.width = pct.toFixed(1) + '%';
        const gc = document.getElementById('goal-current');
        if (gc) gc.textContent = fmt2(d.totalSales);
        const gp = document.getElementById('goal-pct');
        if (gp) gp.textContent = Math.round(pct) + '%';
    }

    // ── Page title ──
    document.title = 'Report — $' + fmt2(d.totalSales) + ' · Café';

    // ── Timestamp ──
    const ts = document.getElementById('live-ts');
    if (ts) ts.textContent = d.ts;
}

if (LIVE_MODE) {
    // Store label on summary el for JS rebuild
    const sumEl = document.getElementById('live-summary');
    if (sumEl) sumEl.dataset.label = <?= json_encode($label) ?>;

    const liveInterval = <?= $mode === 'daily' ? 30000 : 60000 ?>;

    let _pollFails = 0;

    function _setDotState(ok) {
        const dot = document.getElementById('live-dot');
        const ts  = document.getElementById('live-ts');
        if (!dot) return;
        if (ok) {
            dot.style.background = '';
            dot.classList.add('pulsing');
        } else {
            dot.style.background = '#f0b45a';
            dot.classList.remove('pulsing');
            if (ts && !ts.textContent.startsWith('reconnecting')) ts.textContent = 'reconnecting…';
        }
    }

    function pollLive() {
        if (document.hidden) return;
        const url = new URL('report_live.php', window.location.href);
        Object.entries(REPORT_PARAMS).forEach(([k,v]) => { if (v !== null) url.searchParams.set(k, v); });
        fetch(url)
            .then(r => r.ok ? r.json() : null)
            .then(d => {
                if (d && !d.error) { _pollFails = 0; _setDotState(true); applyLiveData(d); }
                else { _pollFails++; if (_pollFails >= 3) _setDotState(false); }
            })
            .catch(() => { _pollFails++; if (_pollFails >= 3) _setDotState(false); });
    }

    setInterval(pollLive, liveInterval);
}
</script>
<script src="animations.js"></script>
</body>
</html>