<?php
require 'auth.php';
require 'config.php';
if (!can('report_sale')) { header("Location: dashboard.php?denied=1"); exit; }

// ── View mode: daily, monthly, yearly, range ──
$today = business_date_today();
$view  = is_string($_GET['view'] ?? null) ? trim($_GET['view']) : 'daily';
if (!in_array($view, ['daily','monthly','yearly','range'], true)) { $view = 'daily'; }

$date  = is_string($_GET['date'] ?? null) ? trim($_GET['date']) : '';
$dateOk = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
if (!$dateOk || $date > $today) { $date = $today; }

$dateFrom = is_string($_GET['from_date'] ?? $_GET['date_from'] ?? null) ? trim($_GET['from_date'] ?? $_GET['date_from']) : '';
$dateTo   = is_string($_GET['to_date']   ?? $_GET['date_to']   ?? null) ? trim($_GET['to_date']   ?? $_GET['date_to'])   : '';
$_is_mgr = in_array($_SESSION['role'] ?? '', ['admin', 'manager']);
$filter_user = (int)($_GET['user_id'] ?? $_GET['user'] ?? 0);
if (!$_is_mgr) {
    $filter_user = (int)$_SESSION['user_id'];
}

if ($dateFrom !== '' && $dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    if ($dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    $view = 'range';
    $isRange = true;
} else {
    $isRange = ($view === 'range' && $dateFrom !== '' && $dateTo !== '');
    if ($isRange && !(preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) && $dateFrom <= $dateTo && $dateTo <= $today)) {
        $isRange = false;
    }
}

// ── Compute date range and navigation based on view ──
if ($isRange) {
    $dateExpr = "business_date BETWEEN '$dateFrom' AND '$dateTo'";
} elseif ($view === 'monthly') {
    $monthStart = date('Y-m-01', strtotime($date));
    $monthEnd   = date('Y-m-t',  strtotime($date));
    $dateExpr   = "business_date BETWEEN '$monthStart' AND '$monthEnd'";
} elseif ($view === 'yearly') {
    $yearStart = date('Y-01-01', strtotime($date));
    $yearEnd   = date('Y-12-31', strtotime($date));
    $dateExpr  = "business_date BETWEEN '$yearStart' AND '$yearEnd'";
} else {
    $dateExpr = "business_date = '$date'";
}

if ($filter_user > 0) {
    $dateExpr .= " AND (user_id = $filter_user OR employee_id = $filter_user)";
}

switch ($view) {
    case 'monthly': $prevDate = date('Y-m-d', strtotime($date . ' -1 month')); $nextDate = date('Y-m-d', strtotime($date . ' +1 month')); break;
    case 'yearly':  $prevDate = date('Y-m-d', strtotime($date . ' -1 year'));  $nextDate = date('Y-m-d', strtotime($date . ' +1 year'));  break;
    case 'range':   $prevDate = $date; $nextDate = $date; break;
    default:        $prevDate = date('Y-m-d', strtotime($date . ' -1 day'));   $nextDate = date('Y-m-d', strtotime($date . ' +1 day'));   break;
}
$isToday  = ($view === 'daily' && $date === $today);

// Navigation labels
switch ($view) {
    case 'monthly': $todayLabel = 'This Month'; $prevLabel = 'Prev Month'; $nextLabel = 'Next Month'; break;
    case 'yearly':  $todayLabel = 'This Year';  $prevLabel = 'Prev Year';  $nextLabel = 'Next Year';  break;
    default:        $todayLabel = 'Today';       $prevLabel = 'Yesterday';  $nextLabel = 'Tomorrow';   break;
}

// ── Live refresh (Task 9): cheap signature, not a data refetch ──
// dashboard.php re-runs its full KPI queries every 5s for every open browser;
// this endpoint returns only a signature so the client can decide whether a
// reload is even worth doing. Must exit before any HTML is output.
if (isset($_GET['poll'])) {
    header('Content-Type: application/json');
    // COUNT/SUM(total)/MAX(order_date) alone are blind to a settlement or a
    // cancellation: admin_pay_cash.php and cancel_order.php both flip
    // is_open/status without touching total or order_date, but tab 1's
    // collected figure (paid_orders_where()) moves the instant either
    // happens. SUM(is_open) catches settlement; the cancelled/refunded/void
    // count catches a status flip that leaves is_open alone. The two
    // ingredients subqueries catch the case no order touches at all — a
    // restock, a PO receipt, or a stock count — which still moves box 3, the
    // stock lines, and the tab badge, and would otherwise sit stale on a
    // 30s-old "YES" long after an item ran low.
    $stmt = $conn->prepare("
        SELECT COUNT(*), COALESCE(SUM(total),0), COALESCE(SUM(is_open),0),
               COALESCE(SUM(CASE WHEN status IN ('Cancelled','Refunded','Void') THEN 1 ELSE 0 END),0),
               COALESCE(MAX(order_date),''),
               (SELECT COUNT(*) FROM ingredients WHERE stock_quantity <= minimum_stock),
               (SELECT COALESCE(SUM(stock_quantity),0) FROM ingredients)
        FROM orders WHERE $dateExpr
    ");
    $stmt->execute();
    $sig = implode('|', $stmt->get_result()->fetch_row());
    echo json_encode(['sig' => md5($sig)]);
    exit;
}

// ── Tab 1: the three verdicts (Task 4) ──
// Money we got — the app-wide definition of collected, never hand-rolled.
$stmt = $conn->prepare("SELECT COALESCE(SUM(total),0), COUNT(*) FROM orders WHERE $dateExpr AND " . paid_orders_where());
$stmt->execute();
[$gotToday, $paidOrderCount] = $stmt->get_result()->fetch_row();
$gotToday = (float)$gotToday;

$ids = [];
$stmt = $conn->prepare("SELECT order_id FROM orders WHERE $dateExpr AND " . paid_orders_where());
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_row()) { $ids[] = (int)$row[0]; }

$costMap  = ingredient_cost_map($conn);
$cogs     = order_cogs($conn, $ids, $costMap);
$keptToday = $gotToday - $cogs['total'];
$centsKept = $gotToday > 0 ? round(($keptToday / $gotToday) * 100) : 0;

$baseGot = $view !== 'daily' ? ['value' => null, 'basis' => 'none', 'label' => '', 'days' => 0, 'dates' => []] : weekday_baseline($conn, $date);

/**
 * Turn a difference into the sentence a manager reads. Money, never percent —
 * "9.1% less" is a maths sentence, "$30.50 less" is a money sentence.
 */
function dr_verdict(float $now, ?float $baseline, string $label): array {
    if ($baseline === null) {
        return ['tone' => 'flat', 'line' => 'first day — nothing to compare yet', 'sub' => ''];
    }
    $diff = $now - $baseline;
    if (abs($diff) < 0.005) {
        return ['tone' => 'flat', 'line' => 'the same as ' . $label, 'sub' => ''];
    }
    return [
        'tone' => $diff > 0 ? 'good' : 'bad',
        'line' => '$' . number_format(abs($diff), 2) . ($diff > 0 ? ' MORE' : ' LESS') . ' than',
        'sub'  => $label,
    ];
}

/**
 * The one line under a headline figure that says whether it is good.
 * A number on its own — "TOTAL SALES $14,450" — cannot tell a manager anything;
 * this is the whole reason the page exists, compressed into a single line so it
 * fits a stat card instead of needing a box of its own.
 *
 * $money formats the difference as dollars; otherwise it is a plain count.
 */
function dr_delta(?float $now, ?float $base, string $label, bool $money = true): array {
    if ($base === null || $now === null) {
        return ['tone' => 'flat', 'text' => 'nothing to compare yet'];
    }
    $diff = $now - $base;
    // Counts are whole things. The baseline is an average, so the difference can
    // land on 6.3 — but "6.3 cups less" is not how anyone reads a cup.
    $unit = $money ? '$' . number_format(abs($diff), 2) : (string)(int)round(abs($diff));
    if (($money && abs($diff) < 0.005) || (!$money && round(abs($diff)) < 1)) {
        return ['tone' => 'flat', 'text' => 'same as ' . $label];
    }
    return [
        'tone' => $diff > 0 ? 'good' : 'bad',
        'text' => ($diff > 0 ? '↑ ' : '↓ ') . $unit . ($diff > 0 ? ' more' : ' less') . ' than ' . $label,
    ];
}

$vGot  = dr_verdict($gotToday, $baseGot['value'], $baseGot['label']);

/**
 * What we kept on the baseline days, costed the same way today is.
 *
 * Scaling the takings baseline by today's keep-rate would be cheaper, but it
 * reduces to the takings difference times a constant — box 2 would always
 * agree with box 1 and never tell the manager anything new. A day can take
 * less and keep more (cheap drinks sold instead of dear ones), and that is
 * exactly the day this box exists to catch. So cost the baseline days for real.
 */
$keptBaseline = null;
if ($baseGot['basis'] !== 'none') {
    // Take the day's total from this same read — a second SUM(total) query per
    // day would only re-fetch rows already in hand.
    $stmt = $conn->prepare("
        SELECT business_date, order_id, total
        FROM orders
        WHERE business_date IN (" . implode(',', array_fill(0, count($baseGot['dates']), '?')) . ")
          AND " . paid_orders_where()
    );
    $stmt->bind_param(str_repeat('s', count($baseGot['dates'])), ...$baseGot['dates']);
    $stmt->execute();
    $res = $stmt->get_result();
    $byDay = [];
    $gotByDay = [];
    while ($r = $res->fetch_assoc()) {
        $byDay[$r['business_date']][] = (int)$r['order_id'];
        $gotByDay[$r['business_date']] = ($gotByDay[$r['business_date']] ?? 0.0) + (float)$r['total'];
    }

    $keptSum = 0.0;
    $orderSum = 0;
    $cupSum   = 0;
    foreach ($baseGot['dates'] as $d) {
        $dayIds = $byDay[$d] ?? [];
        $dayCogs = order_cogs($conn, $dayIds, $costMap);
        $keptSum  += ($gotByDay[$d] ?? 0.0) - $dayCogs['total'];
        $orderSum += count($dayIds);
        $cupSum   += cogs_cups($dayCogs);
    }
    $n = count($baseGot['dates']);
    $keptBaseline   = $keptSum / $n;
    // The same baseline days answer every headline stat, so all four cards
    // compare against one consistent idea of "a normal Monday".
    $ordersBaseline = $orderSum / $n;
    $cupsBaseline   = $cupSum / $n;
    $avgBaseline    = $orderSum > 0 ? ($baseGot['value'] * $n) / $orderSum : null;
}
$ordersBaseline = $ordersBaseline ?? null;
$cupsBaseline   = $cupsBaseline   ?? null;
$avgBaseline    = $avgBaseline    ?? null;
$avgToday       = $paidOrderCount > 0 ? $gotToday / $paidOrderCount : 0.0;

$vKept = dr_verdict($keptToday, $keptBaseline, $baseGot['label']);

// Zero sales (the common every-morning-before-the-first-order state) is not
// a "LESS than a normal Saturday" verdict — that reads as an alarm before
// the shop has even had a chance. Neutral and unmissable instead.
// The zero-sales override lives further down, after $notPaidCount is known —
// it needs to tell "nothing sold" apart from "sold, but all still on tabs".

// Plain-words tooltip for box 1's baseline line — only meaningful when the
// line actually states a weekday-average comparison (not the yesterday
// fallback, the no-baseline state, or the zero-sales override above, which
// replace the line with something that makes no baseline claim at all).
$baselineTooltip = '';
if ($paidOrderCount > 0 && $baseGot['basis'] === 'weekday') {
    $baselineTooltip = 'average of your last ' . $baseGot['days'] . ' ' . date('l', strtotime($date)) . 's';
}

// Yesterday's figure stays visible regardless of which baseline drove the
// colour above — a manager glancing at box 1 shouldn't have to open tab 2 to
// see what yesterday did.
$gotYesterday = 0.0;
if ($view === 'daily') {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE business_date = ? AND " . paid_orders_where());
    $stmt->bind_param("s", $prevDate);
    $stmt->execute();
    $gotYesterday = (float)$stmt->get_result()->fetch_row()[0];
}

// Stock going DOWN is not bad — it means drinks were sold. Red fires only
// when something will actually stop service tomorrow.
$low = $conn->query("
    SELECT ingredient_name, stock_quantity, minimum_stock, unit
    FROM ingredients
    WHERE stock_quantity <= minimum_stock
    ORDER BY (stock_quantity - minimum_stock) ASC
")->fetch_all(MYSQLI_ASSOC);
$lowItems  = count($low);
$lowNames  = array_column(array_slice($low, 0, 3), 'ingredient_name');
$lowExtra  = max(0, $lowItems - 3);
// Already out is a harder fact than merely low — the alert strip names both.
$outItems  = count(array_filter($low, fn($r) => (float)$r['stock_quantity'] <= 0));

$stockValue = (float)$conn->query("SELECT COALESCE(SUM(stock_quantity * cost_per_unit),0) FROM ingredients")->fetch_row()[0];

// ingredient_history has no business_date column, so match the business day by
// its 06:00-to-06:00 window. Joining through orders would look tidier but drops
// the 33 order_deduct rows that carry a NULL order_id.
$usedValue = 0.0;
if ($view === 'daily') {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(ABS(h.amount) * i.cost_per_unit),0)
        FROM ingredient_history h
        JOIN ingredients i ON i.ingredient_id = h.ingredient_id
        WHERE h.change_type = 'order_deduct'
          AND h.created_at >= CONCAT(?, ' 06:00:00')
          AND h.created_at <  CONCAT(DATE_ADD(?, INTERVAL 1 DAY), ' 06:00:00')
    ");
    $stmt->bind_param("ss", $date, $date);
    $stmt->execute();
    $usedValue = (float)$stmt->get_result()->fetch_row()[0];
}

// ── Tab 1: the neutral row (Task 5) — how the money came in, facts only ──
// How the collected money arrived. Pay-later only counts once settled.
$stmt = $conn->prepare("
    SELECT payment_method, COALESCE(SUM(total),0) AS amount
    FROM orders WHERE $dateExpr AND " . paid_orders_where() . "
    GROUP BY payment_method
");
$stmt->execute();
$byMethod = [];
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) { $byMethod[strtolower((string)$r['payment_method'])] = (float)$r['amount']; }

$gotCash   = $byMethod['cash'] ?? 0.0;
$gotBakong = $byMethod['bakong'] ?? 0.0;
$gotLater  = $byMethod['paylater'] ?? 0.0;
// Remainder, not enumeration: legacy rows (payment_method='0', 'riel', ...)
// predate the current three-method model. Deriving this from the total
// keeps the cards honest even as old data carries values we don't name here.
$gotOther  = $gotToday - ($gotCash + $gotBakong + $gotLater);

// Money not (yet) collected — every order failing the same paid_orders_where()
// test tab 2's footer uses, minus Cancelled/Void (never owed, never "not paid
// yet"). This used to hand-roll payment_method='paylater' AND is_open=1,
// which silently missed a Refunded or PendingPayment cash/bakong order and
// made this card disagree with tab 2's footer on the same day.
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(total),0), COUNT(*)
    FROM orders
    WHERE $dateExpr AND status NOT IN ('Cancelled','Void') AND NOT (" . paid_orders_where() . ")
");
$stmt->execute();
[$notPaidYet, $notPaidCount] = $stmt->get_result()->fetch_row();
$notPaidYet   = (float)$notPaidYet;
$notPaidCount = (int)$notPaidCount;

// Two different zero days, and calling both "no sales" is a lie on the second.
// A day whose every order is still on a tab HAS sold drinks — it just has not
// been paid for them. "No sales yet today" printed beside a non-zero "not paid
// yet" card reads as a broken page.
$drAllOnTab = ($paidOrderCount === 0 && $notPaidCount > 0);
$drZeroLine = $drAllOnTab
    ? ($isRange ? 'nothing paid in this period — every order is on a tab' : 'nothing paid yet — every order is still on a tab')
    : ($isRange ? 'no sales in this period' : 'no sales yet today');
if ($paidOrderCount === 0) {
    $vGot  = ['tone' => 'flat', 'line' => $drZeroLine, 'sub' => ''];
    $vKept = ['tone' => 'flat', 'line' => $drZeroLine, 'sub' => ''];
}

// ── When the money came in, hour by hour ──
// Business days run 06:00 to 06:00, so the axis runs from 6am and wraps past
// midnight rather than starting at hour 0 with a dead morning.
$hourRev = array_fill(0, 24, 0.0);
$yMax    = 10;
$yTicks  = [0, 2, 4, 6, 8, 10];
if ($view === 'monthly') {
    // Daily revenue bars for the month — every day shown, even $0 days
    $daysInMonth = (int)date('t', strtotime($date));
    $periodLabels = range(1, $daysInMonth);
    $periodData = array_fill(0, $daysInMonth, 0.0);
    $stmt = $conn->prepare("SELECT business_date d, COALESCE(SUM(total),0) rev FROM orders WHERE $dateExpr AND " . paid_orders_where() . " GROUP BY business_date ORDER BY d");
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $dayNum = (int)substr($r['d'], 8, 2) - 1;
        $periodData[$dayNum] = (float)$r['rev'];
    }
    $periodPeak = $periodData ? max($periodData) : 0.0;
    $yMax2      = $periodPeak > 0 ? ceil($periodPeak / ($periodPeak > 100 ? 100 : 10)) * ($periodPeak > 100 ? 100 : 10) : 100;
    $yTicks2    = $yMax2 > 0 ? [$yMax2 * 0.25, $yMax2 * 0.5, $yMax2 * 0.75, $yMax2] : [25, 50, 75, 100];
    $periodLabel = 'day of month';
    $periodAxisLabels = $periodLabels;
    $hourPeakVal = 0.0; $busiestHour = null; $hourOrder = []; $hhData = []; $hhYMax = 100;
} elseif ($view === 'yearly') {
    // Monthly revenue bars for the year
    $periodData = []; $periodLabels = [];
    $stmt = $conn->prepare("SELECT DATE_FORMAT(business_date,'%Y-%m') ym, COALESCE(SUM(total),0) rev FROM orders WHERE $dateExpr AND " . paid_orders_where() . " GROUP BY ym ORDER BY ym");
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) { $periodData[] = (float)$r['rev']; $periodLabels[] = date('M', strtotime($r['ym'] . '-01')); }
    $periodPeak = $periodData ? max($periodData) : 0.0;
    $yMax2      = $periodPeak > 0 ? ceil($periodPeak / ($periodPeak > 100 ? 100 : 10)) * ($periodPeak > 100 ? 100 : 10) : 100;
    $yTicks2    = $yMax2 > 0 ? [$yMax2 * 0.25, $yMax2 * 0.5, $yMax2 * 0.75, $yMax2] : [25, 50, 75, 100];
    $periodLabel = 'month';
    $periodAxisLabels = $periodLabels;
    $hourPeakVal = 0.0; $busiestHour = null; $hourOrder = []; $hhData = []; $hhYMax = 100;
} elseif ($view === 'range') {
    // Daily revenue bars for the custom range
    $periodData = []; $periodLabels = [];
    $stmt = $conn->prepare("SELECT business_date d, COALESCE(SUM(total),0) rev FROM orders WHERE $dateExpr AND " . paid_orders_where() . " GROUP BY business_date ORDER BY d");
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) { $periodData[] = (float)$r['rev']; $periodLabels[] = $r['d']; }
    $periodPeak = $periodData ? max($periodData) : 0.0;
    $yMax2      = $periodPeak > 0 ? ceil($periodPeak / ($periodPeak > 100 ? 100 : 10)) * ($periodPeak > 100 ? 100 : 10) : 100;
    $yTicks2    = $yMax2 > 0 ? [$yMax2 * 0.25, $yMax2 * 0.5, $yMax2 * 0.75, $yMax2] : [25, 50, 75, 100];
    $periodLabel = 'date';
    $n = count($periodLabels); $periodAxisLabels = [];
    if ($n <= 10) { foreach ($periodLabels as $d) $periodAxisLabels[] = substr($d, 5, 5); }
    else { $step = max(1, floor($n / 8)); for ($i = 0; $i < $n; $i++) { if ($i === 0 || $i === $n - 1 || $i % $step === 0) $periodAxisLabels[] = substr($periodLabels[$i], 5, 5); else $periodAxisLabels[] = ''; } }
    $hourPeakVal = 0.0; $busiestHour = null; $hourOrder = []; $hhData = []; $hhYMax = 100;
} else {
    $stmt = $conn->prepare("
        SELECT HOUR(order_date) h, COALESCE(SUM(total),0) rev, COUNT(*) AS cnt
        FROM orders WHERE $dateExpr AND " . paid_orders_where() . "
        GROUP BY HOUR(order_date)
    ");
    $stmt->execute();
    $res = $stmt->get_result();
    $hourCnt = array_fill(0, 24, 0);
    while ($r = $res->fetch_assoc()) { $h = (int)$r['h']; $hourRev[$h] = (float)$r['rev']; $hourCnt[$h] = (int)$r['cnt']; }

    $hourOrder = [];
    for ($i = 6; $i < 30; $i++) { $hourOrder[] = $i % 24; }
    $hourPeakVal = max($hourRev) ?: 0.0;
    $busiestHour = null;
    if ($hourPeakVal > 0) {
        foreach ($hourOrder as $h) { if ($hourRev[$h] === $hourPeakVal) { $busiestHour = $h; break; } }
    }
    // Y-axis intervals for the bar chart — round peak up to a clean breakpoint
    $yMagnitude = $hourPeakVal > 0 ? pow(10, floor(log10($hourPeakVal)) - 1) : 10;
    $yMax       = $hourPeakVal > 0 ? ceil($hourPeakVal / $yMagnitude) * $yMagnitude : 10;
    $yTicks     = $hourPeakVal > 0
        ? [0, $yMax * 0.25, $yMax * 0.5, $yMax * 0.75, $yMax]
        : [0, 2, 4, 6, 8, 10];
    $periodData = []; $periodLabels = []; $periodAxisLabels = []; $periodPeak = 0; $yMax2 = 10; $yTicks2 = [2.5,5,7.5,10]; $periodLabel = '';

    // ── Hourly orders breakdown (06:00 – 22:00) ──
    $hhData = []; $hhPeakVal = 0;
    for ($h = 6; $h <= 22; $h++) {
        $cnt = $hourCnt[$h] ?? 0;
        $rev = $hourRev[$h] ?? 0.0;
        $hhData[] = ['hour'=>sprintf('%02d:00',$h), 'cnt'=>$cnt, 'rev'=>$rev];
        if ($cnt > $hhPeakVal) $hhPeakVal = $cnt;
    }
    $hhYMax = $hhPeakVal > 0 ? (ceil($hhPeakVal / 5) * 5) : 10;
}

// Best seller: $cogs['by_product'] arrives unsorted (insertion order), so sort
// by cups moved. uasort preserves the key, and the key IS the product name.
//
// Loyalty redemptions are skipped. A shirt handed over for points is not a
// thing the shop sold, and on a quiet day it outranks every real drink —
// "[GIFT] Free Shirt (Loyalty)" sat under a heading reading BEST SELLER on
// 31 May, which reads as a broken report rather than a slow day. The same
// is_gift flag drives the PDF and the spreadsheet.
$byProductSorted = array_filter($cogs['by_product'], fn($p) => empty($p['is_gift']));
uasort($byProductSorted, fn($a, $b) => $b['qty'] <=> $a['qty']);
$bestSellerName = null;
$bestSellerQty  = 0;
foreach ($byProductSorted as $bpName => $bpRow) { $bestSellerName = $bpName; $bestSellerQty = (int)$bpRow['qty']; break; }
$cupsToday = cogs_cups($cogs);
$avgCups   = $paidOrderCount > 0 ? $cupsToday / $paidOrderCount : null;

// ── Add-ons breakdown (Best Add-on Price / Data) ──
$addonSales = [];
if ($ids) {
  $inP = implode(',', array_fill(0, count($ids), '?'));
  $stmt = $conn->prepare("SELECT oi.addons_snapshot, oi.quantity FROM order_items oi WHERE oi.order_id IN ($inP) AND oi.addons_snapshot IS NOT NULL AND oi.addons_snapshot <> '' AND oi.addons_snapshot <> '[]'");
  $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) {
    $addonsArr = json_decode($r['addons_snapshot'] ?? '[]', true) ?: [];
    $itemQty = (int)$r['quantity'];
    foreach ($addonsArr as $ad) {
      $name = trim($ad['name'] ?? '');
      if ($name === '') continue;
      $price = (float)($ad['price'] ?? 0);
      if (!isset($addonSales[$name])) {
        $addonSales[$name] = [
          'name' => $name,
          'price' => $price,
          'qty' => 0,
          'revenue' => 0.0
        ];
      }
      $addonSales[$name]['qty'] += $itemQty;
      $addonSales[$name]['revenue'] += $price * $itemQty;
    }
  }
}
uasort($addonSales, fn($a, $b) => $b['revenue'] <=> $a['revenue'] ?: $b['qty'] <=> $a['qty']);
$totalAddonRev = array_sum(array_column($addonSales, 'revenue'));

/**
 * For pay-later, Completed means the drinks were made and the customer still
 * owes — is_open is the only trustworthy signal. Getting this wrong has caused
 * three money bugs in this codebase.
 *
 * Non-payment outranks method: an order that fails the same collected test
 * paid_orders_where() uses reads "not paid yet" regardless of what method it
 * carries — a Preparing/is_open=1 cash row has not been paid any more than an
 * open pay-later tab has. Only once collected do we name the method, and
 * anything outside cash/bakong/paylater (legacy payment_method='0'/'riel'
 * rows) reads "other way", matching tab 1's "other ways" card instead of
 * being folded silently into "cash".
 *
 * Returns [label, state, bucket]. bucket is the payment-method category
 * (cash/bakong/paylater/other) and drives the filter pills; state is
 * 'open'/'ok'/'refunded' and drives the amber tint ('open' only). The two
 * are independent — a paylater row keeps bucket=paylater whether or not it
 * is state=open.
 */
function dr_pay_label(array $o): array {
    $m = strtolower((string)$o['payment_method']);
    $bucket = in_array($m, ['cash', 'bakong', 'paylater'], true) ? $m : 'other';

    // Checked before the collected test: money that WAS collected and then
    // given back is not money that was never paid. Its own neutral state
    // (not 'open') keeps it out of the amber "needs attention" styling too.
    if ($o['status'] === 'Refunded') {
        return ['money given back', 'refunded', $bucket];
    }

    $collected = ((int)$o['is_open'] === 0)
        && !in_array($o['status'], ['PendingPayment', 'Cancelled', 'Refunded', 'Void'], true);
    if (!$collected) {
        return ['not paid yet', 'open', $bucket];
    }

    $label = match ($bucket) {
        'paylater' => 'pay later — paid',
        'other'    => 'other way',
        default    => $bucket, // cash, bakong
    };
    return [$label, 'ok', $bucket];
}

/**
 * Build the date expression inside a fragment from the current request.
 * Fragments receive their own $_GET params (date, date_from, date_to, view).
 */
function dr_date_expr(): string {
    $d  = $_GET['date'] ?? '';
    $df = $_GET['from_date'] ?? $_GET['date_from'] ?? '';
    $dt = $_GET['to_date']   ?? $_GET['date_to']   ?? '';
    $v  = $_GET['view'] ?? 'daily';
    $u  = (int)($_GET['user_id'] ?? $_GET['user'] ?? 0);
    $uCond = $u > 0 ? " AND user_id = $u" : "";
    if ($df !== '' && $dt !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $df) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
        return "business_date BETWEEN '$df' AND '$dt'" . $uCond;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        if ($v === 'monthly') {
            $ms = date('Y-m-01', strtotime($d));
            $me = date('Y-m-t',  strtotime($d));
            return "business_date BETWEEN '$ms' AND '$me'" . $uCond;
        }
        if ($v === 'yearly') {
            $ys = date('Y-01-01', strtotime($d));
            $ye = date('Y-12-31', strtotime($d));
            return "business_date BETWEEN '$ys' AND '$ye'" . $uCond;
        }
        return "business_date = '$d'" . $uCond;
    }
    return "business_date = '1970-01-01'"; // fallback: no rows
}

// ── Tab 2: Orders (Task 6) — the day's orders and how each was paid ──
function dr_fragment_orders(mysqli $conn, string $date): void {
    $dex = dr_date_expr();
    $stmt = $conn->prepare("
        SELECT order_id, daily_order_no, customer_name, total, payment_method, status, is_open,
               order_date
        FROM orders
        WHERE $dex AND status NOT IN ('Cancelled','Void')
        ORDER BY order_date ASC
    ");
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Neutral counts, never a red card — see banned-vocabulary note.
    // Use the raw expression with o. prefix for the refund/remake joins
    $stmt = $conn->query("SELECT COUNT(*) FROM order_refunds r JOIN orders o ON o.order_id = r.order_id WHERE " . str_replace('business_date', 'o.business_date', $dex));
    $givenBackCount = (int)$stmt->fetch_row()[0];

    $stmt = $conn->query("SELECT COUNT(*) FROM order_remakes r JOIN orders o ON o.order_id = r.order_id WHERE " . str_replace('business_date', 'o.business_date', $dex));
    $remadeCount = (int)$stmt->fetch_row()[0];

    // Cups per order, for the per-row data attribute the footer's JS sums
    // over the currently-filtered rows.
    //
    // product_id <> 0 drops loyalty redemptions, matching cogs_cups() on tab 1.
    // This footer and that card have disagreed before — 70 against 92 — and the
    // two count cups by different routes, so the same rule has to be written
    // into both. Do not change one without the other.
    $cupsByOrder = [];
    $stmt = $conn->prepare("
        SELECT oi.order_id, COALESCE(SUM(oi.quantity),0) AS cups
        FROM order_items oi
        JOIN orders o ON o.order_id = oi.order_id
        WHERE " . str_replace('business_date', 'o.business_date', $dex) . " AND oi.product_id <> 0 AND " . paid_orders_where('o') . "
        GROUP BY oi.order_id
    ");
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) { $cupsByOrder[(int)$r['order_id']] = (int)$r['cups']; }
    $cupsToday = array_sum($cupsByOrder);

    // Money collected vs. still owed — the same collected test tab 1 uses
    // (paid_orders_where()), never a hand-rolled status check. This is the
    // figure that belongs in a "total" slot; folding in open pay-later tabs
    // here is exactly the confusion that has cost this codebase three money
    // bugs already.
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total),0), COUNT(*) FROM orders WHERE $dex AND " . paid_orders_where());
    $stmt->execute();
    [$collectedTotal, $collectedCount] = $stmt->get_result()->fetch_row();
    $collectedTotal = (float)$collectedTotal;

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total),0), COUNT(*)
        FROM orders
        WHERE $dex AND status NOT IN ('Cancelled','Void') AND NOT (" . paid_orders_where() . ")
    ");
    $stmt->execute();
    [$notPaidTotal, $notPaidCount] = $stmt->get_result()->fetch_row();
    $notPaidTotal = (float)$notPaidTotal;

    // Pre-derive each row's label/state/bucket once — the table and the
    // "Other" pill's visibility both need it, and deriving it twice is
    // exactly the desync risk the bucket used to have.
    $rowData = [];
    $hasOther = false;
    foreach ($rows as $o) {
        [$label, $state, $bucket] = dr_pay_label($o);
        if ($bucket === 'other') { $hasOther = true; }
        $rowData[] = [
            'label'  => $label,
            'state'  => $state,
            'bucket' => $bucket,
            'time'   => date('H:i', strtotime($o['order_date'])),
            'no'     => '#' . str_pad((string)(int)$o['daily_order_no'], 4, '0', STR_PAD_LEFT),
            'cust'   => (($name = trim((string)$o['customer_name'])) === '' || $name === 'Guest') ? '—' : $name,
            'total'  => (float)$o['total'],
            'cups'   => $cupsByOrder[(int)$o['order_id']] ?? 0,
        ];
    }
    ?>
    <div class="dr-card dr-wide" style="margin-top:0">
      <p class="dr-note" style="margin-bottom:14px">money given back: <?= (int)$givenBackCount ?> &middot; drinks made again: <?= (int)$remadeCount ?></p>

      <div class="dr-pills" role="group" aria-label="Filter by how the order was paid">
        <button type="button" class="dr-pill is-on" data-filter="all">All</button>
        <button type="button" class="dr-pill" data-filter="cash">Cash</button>
        <button type="button" class="dr-pill" data-filter="bakong">Bakong</button>
        <button type="button" class="dr-pill" data-filter="paylater">Pay later</button>
        <?php if ($hasOther): ?>
        <button type="button" class="dr-pill" data-filter="other">Other</button>
        <?php endif; ?>
      </div>

      <div class="dr-table-wrap orders-scroll">
        <table class="dr-table" id="ordersTable">
          <thead>
            <tr><th>No.</th><th>Time</th><th>Order</th><th>Customer</th><th>Method</th><th>Total</th><th>Paid</th></tr>
          </thead>
          <tbody>
            <?php if (!$rowData): ?>
            <tr><td colspan="7" class="dr-note" style="padding:20px 0;text-align:center">no orders this day</td></tr>
            <?php endif; ?>
            <?php $i = 0; ?>
            <?php foreach ($rowData as $rd): $i++; ?>
            <tr class="<?= $rd['state'] === 'open' ? 'is-open' : '' ?>"
                data-method="<?= htmlspecialchars($rd['bucket']) ?>"
                data-state="<?= htmlspecialchars($rd['state']) ?>"
                data-total="<?= htmlspecialchars((string)$rd['total']) ?>"
                data-cups="<?= (int)$rd['cups'] ?>">
              <td class="dr-mono-dim"><?= $i ?></td>
              <td class="dr-mono-dim"><?= htmlspecialchars($rd['time']) ?></td>
              <td class="dr-mono"><?= htmlspecialchars($rd['no']) ?></td>
              <td><?= htmlspecialchars($rd['cust']) ?></td>
              <td><span class="dr-status is-<?= htmlspecialchars($rd['state']) ?>"><?= htmlspecialchars(ucfirst($rd['bucket'])) ?></span></td>
              <td class="dr-mono">$<?= htmlspecialchars(number_format($rd['total'], 2)) ?></td>
              <td><span class="dr-status is-<?= htmlspecialchars($rd['state']) ?>"><?= htmlspecialchars($rd['label']) ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="dr-table-foot" id="ordersFoot">
        <span><?= (int)count($rowData) ?> orders</span> &middot; <span><?= (int)$cupsToday ?> cups</span> &middot; <span>$<?= htmlspecialchars(number_format($collectedTotal, 2)) ?> collected</span><?php if ($notPaidCount > 0): ?> &middot; <span>$<?= htmlspecialchars(number_format($notPaidTotal, 2)) ?> not paid yet</span><?php endif; ?>
      </div>

    </div>
    <?php
}

// ── Tab 3: Stock (Task 7) — levels, what today used, what needs buying ──
/**
 * Quantity formatting shared by the stock table: whole numbers print plain,
 * fractional ones (ingredient_history.amount is DECIMAL(10,4), so "used
 * today" can be fractional even though stock_quantity/minimum_stock are
 * always ints) get two decimal places.
 */
function dr_qty(float $n): string {
    return (abs($n - round($n)) < 0.005) ? number_format($n, 0) : number_format($n, 2);
}

function dr_fragment_stock(mysqli $conn, string $date): void {
    // Same 06:00-to-06:00 business-day window as tab 1's usedValue query —
    // ingredient_history has no business_date column, and joining through
    // orders would silently drop the order_deduct rows with a NULL order_id.
    $stmt = $conn->prepare("
        SELECT i.ingredient_id, i.ingredient_name, i.unit, i.stock_quantity,
               i.minimum_stock, i.cost_per_unit,
               COALESCE(u.used, 0) AS used_today
        FROM ingredients i
        LEFT JOIN (
            SELECT ingredient_id, SUM(ABS(amount)) AS used
            FROM ingredient_history
            WHERE change_type = 'order_deduct'
              AND created_at >= CONCAT(?, ' 06:00:00')
              AND created_at <  CONCAT(DATE_ADD(?, INTERVAL 1 DAY), ' 06:00:00')
            GROUP BY ingredient_id
        ) u ON u.ingredient_id = i.ingredient_id
        ORDER BY (i.stock_quantity - i.minimum_stock) ASC, i.ingredient_name ASC
    ");
    $stmt->bind_param("ss", $date, $date);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $trackedCount = count($rows);
    $buyCount = 0;
    $outCount = 0;
    $rowData  = [];
    foreach ($rows as $r) {
        $stock = (int)$r['stock_quantity'];
        $min   = (int)$r['minimum_stock'];
        $used  = (float)$r['used_today'];
        $cost  = (float)$r['cost_per_unit'];

        // Three logical states, but only two the manager needs to act on:
        // "buy now" is the only one that ever gets amber — it is also the
        // exact test tab 1's $lowItems uses, so the badge and this table
        // never disagree. "low" is shown as a heads-up label only, styled
        // the same neutral way as "OK" — nothing red or green on this tab.
        if ($stock <= $min) {
            $bucket = 'buy';   $statusLabel = 'buy now';
            $buyCount++;
        } elseif ($min > 0 && $stock <= $min * 1.25) {
            $bucket = 'ok';    $statusLabel = 'getting low';
        } else {
            $bucket = 'ok';    $statusLabel = 'have enough';
        }
        if ($stock <= 0) { $outCount++; }

        $barBase = max(1, $min * 2);
        $barPct  = min(100, ($stock / $barBase) * 100);

        $rowData[] = [
            'name'    => (string)$r['ingredient_name'],
            'unit'    => (string)$r['unit'],
            'stock'   => $stock,
            'min'     => $min,
            'used'    => $used,
            'cost'    => $cost,
            'bucket'  => $bucket,
            'label'   => $statusLabel,
            'needBuy' => $bucket === 'buy',
            'barPct'  => $barPct,
        ];
    }
    ?>
    <div class="dr-card dr-wide" style="margin-top:0">
      <div class="dr-facts-inline" style="margin-top:0">
        <div class="dr-fact"><div class="dr-v-sm"><?= (int)$trackedCount ?></div><div class="dr-note">items we track</div></div>
        <div class="dr-fact"><div class="dr-v-sm"><?= (int)$buyCount ?></div><div class="dr-note">items to buy</div></div>
        <div class="dr-fact"><div class="dr-v-sm"><?= (int)$outCount ?></div><div class="dr-note">items already out</div></div>
      </div>

      <div class="dr-toolbar">
        <div class="dr-pills" role="group" aria-label="Filter by whether we need to buy more">
          <button type="button" class="dr-pill is-on" data-filter="all">All</button>
          <button type="button" class="dr-pill" data-filter="buy">Need buying</button>
          <button type="button" class="dr-pill" data-filter="ok">OK</button>
        </div>
        <input type="text" class="dr-search" id="stockSearch" placeholder="Search ingredient…" aria-label="Search ingredients">
      </div>

      <div class="dr-table-wrap stock-scroll">
        <table class="dr-table" id="stockTable">
          <thead>
            <tr><th>No.</th><th>Item</th><th>We have</th><th>Buy more below</th><th>Used today</th><th>What it costs us (per unit)</th><th>Level</th></tr>
          </thead>
          <tbody>
            <?php if (!$rowData): ?>
            <tr><td colspan="7" class="dr-note" style="padding:20px 0;text-align:center">no ingredients tracked</td></tr>
            <?php endif; ?>
            <?php $i = 0; ?>
            <?php foreach ($rowData as $rd): $i++; ?>
            <tr class="<?= $rd['needBuy'] ? 'needs-buy' : '' ?>"
                data-bucket="<?= htmlspecialchars($rd['bucket']) ?>"
                data-name="<?= htmlspecialchars(mb_strtolower($rd['name'])) ?>">
              <td class="dr-mono-dim"><?= $i ?></td>
              <td><?= htmlspecialchars($rd['name']) ?></td>
              <td class="dr-mono"><?= htmlspecialchars(dr_qty($rd['stock'])) ?> <?= htmlspecialchars($rd['unit']) ?></td>
              <td class="dr-mono-dim"><?= htmlspecialchars(dr_qty($rd['min'])) ?> <?= htmlspecialchars($rd['unit']) ?></td>
              <td class="dr-mono-dim"><?= htmlspecialchars(dr_qty($rd['used'])) ?> <?= htmlspecialchars($rd['unit']) ?></td>
              <td class="dr-mono-dim">$<?= htmlspecialchars(number_format($rd['cost'], 4)) ?> / <?= htmlspecialchars($rd['unit']) ?></td>
              <td>
                <div class="dr-stock-bar"><span class="dr-stock-fill <?= $rd['needBuy'] ? 'tone-attn' : 'tone-normal' ?>" style="width:<?= round($rd['barPct'], 2) ?>%"></span></div>
                <div style="margin-top:5px"><span class="dr-status <?= $rd['needBuy'] ? 'is-open' : 'is-neutral' ?>"><?= htmlspecialchars($rd['label']) ?></span></div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="dr-table-foot" id="stockFoot"></div>
    </div>
    <?php
}

// ── Tab 4: Staff (Task 8) — who worked today and what they served ──
//
// LANDMINE: orders.employee_id is a FK to employees.employee_id, which is NOT
// users.user_id and the two never coincide. attendance keys on user_id. The
// only correct chain is attendance.user_id -> employees.user_id ->
// employees.employee_id -> orders.employee_id. Joining orders to attendance
// any more directly attributes work to the wrong person (or nobody) and has
// produced silently wrong staff figures in this codebase before.
// employees.user_id carries a UNIQUE key, so this chain cannot fan out one
// attendance row into more than one employee.

/**
 * "two shifts" / "three shifts" — spelled out for the common small counts a
 * split-shift day actually produces, numeric fallback beyond that.
 */
function dr_shift_note(int $n): string {
    static $words = [2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five'];
    return ($words[$n] ?? (string)$n) . ' shifts';
}

function dr_fragment_staff(mysqli $conn, string $date): void {
    // Order/money aggregates are computed per employee_id in their own
    // subquery, then joined in once. Aggregating orders directly against a
    // one-row-per-shift attendance join would fan out: a person with two
    // attendance rows that day would multiply their order rows by two before
    // GROUP BY ever runs, doubling both the order count and the money.
    $stmt = $conn->prepare("
        SELECT e.employee_id, MAX(e.name) AS full_name,
               MIN(a.clock_in)                                    AS clock_in,
               MAX(a.clock_out)                                   AS clock_out,
               SUM(a.hours_worked)                                AS hours_worked,
               COUNT(a.id)                                        AS shift_count,
               SUM(a.clock_out IS NULL)                           AS open_shifts,
               COALESCE(MAX(oe.orders_served), 0)
                 + COALESCE(MAX(ou.orders_served), 0)            AS orders_served,
               COALESCE(MAX(oe.money_taken), 0)
                 + COALESCE(MAX(ou.money_taken), 0)              AS money_taken
        FROM employees e
        LEFT JOIN attendance a ON a.user_id = e.user_id AND a.date = ?
        LEFT JOIN (
            SELECT employee_id,
                   COUNT(order_id)          AS orders_served,
                   COALESCE(SUM(total), 0)  AS money_taken
            FROM orders
            WHERE business_date = ? AND " . paid_orders_where() . " AND employee_id IS NOT NULL
            GROUP BY employee_id
        ) oe ON oe.employee_id = e.employee_id
        LEFT JOIN (
            SELECT user_id,
                   COUNT(order_id)          AS orders_served,
                   COALESCE(SUM(total), 0)  AS money_taken
            FROM orders
            WHERE business_date = ? AND " . paid_orders_where() . " AND employee_id IS NULL AND user_id IS NOT NULL
            GROUP BY user_id
        ) ou ON ou.user_id = e.user_id
        WHERE oe.employee_id IS NOT NULL OR ou.user_id IS NOT NULL OR a.id IS NOT NULL
        GROUP BY e.employee_id, e.name
        ORDER BY MIN(a.clock_in) ASC
    ");
    $stmt->bind_param("sss", $date, $date, $date);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Money a person "took" means money actually collected (paid_orders_where
    // above), never an open pay-later tab they merely rang up. One row per
    // person now (split shifts collapsed above), so a plain sum is correct —
    // no per-employee dedup needed.
    $peopleCount = count($rows);
    $ordersTotal = 0;
    $moneyTotal  = 0.0;
    foreach ($rows as $r) {
        $ordersTotal += (int)$r['orders_served'];
        $moneyTotal  += (float)$r['money_taken'];
    }

    // The day's collected money (tab 1's own figure, same paid_orders_where()
    // test) minus what landed on someone who was actually clocked in. Orders
    // with a NULL employee_id — or an employee_id that isn't in today's
    // attendance at all — never show up in $moneyTotal above, so without this
    // line the table would silently show a column of dashes next to a tab 1
    // total that says money came in, and read as broken rather than honest.
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total), 0) FROM orders WHERE business_date = ? AND " . paid_orders_where());
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $collectedTotal = (float)$stmt->get_result()->fetch_row()[0];
    $unlinkedMoney  = $collectedTotal - $moneyTotal;
    ?>
    <div class="dr-card dr-wide" style="margin-top:0">
      <div class="dr-table-wrap">
        <table class="dr-table" id="staffTable">
          <thead>
            <tr><th>Name</th><th>Clocked in</th><th>Clocked out</th><th>Hours</th><th>Orders served</th><th>Money taken</th></tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
            <tr><td colspan="6" class="dr-note" style="padding:20px 0;text-align:center">no one worked this day</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
                // Any open shift that day means the person reads as still
                // working — the normal case for a manager reading this
                // mid-shift, not an edge case. hours_worked is genuinely NULL
                // until clock-out sets it, so a shift in progress must never
                // be summed into fmt_hours() (non-nullable float param); we
                // simply don't use the hours figure while still working.
                $stillWorking = (int)$r['open_shifts'] > 0;
                $shiftCount   = (int)$r['shift_count'];
                $orders = (int)$r['orders_served'];
                $money  = (float)$r['money_taken'];
                $served = $orders > 0;

                $hoursCell = $stillWorking
                    ? 'still working'
                    : ($r['hours_worked'] !== null ? fmt_hours((float)$r['hours_worked']) : '—');
                if ($shiftCount > 1) { $hoursCell .= ' · ' . dr_shift_note($shiftCount); }
            ?>
            <?php
                // Initials for the avatar: first letter of the first two words,
                // so "Sok Dara" reads SD and a single name reads one letter.
                $parts = preg_split('/\s+/', trim((string)$r['full_name']));
                $initials = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));
            ?>
            <tr>
              <td>
                <span class="dr-who">
                  <span class="dr-av" aria-hidden="true"><?= htmlspecialchars($initials) ?></span>
                  <?= htmlspecialchars((string)$r['full_name']) ?>
                </span>
              </td>
              <td class="dr-mono-dim"><?= $r['clock_in'] ? htmlspecialchars(date('H:i', strtotime($r['clock_in']))) : '<span class="dr-muted">no clock-in</span>' ?></td>
              <td class="dr-mono-dim"><?= $r['clock_in'] ? ($stillWorking ? '<span class="dr-status is-ok">still working</span>' : htmlspecialchars(date('H:i', strtotime($r['clock_out'])))) : '<span class="dr-muted">—</span>' ?></td>
              <td class="dr-mono"><?= htmlspecialchars($hoursCell) ?></td>
              <td class="dr-mono"><?= $served ? (int)$orders : '—' ?></td>
              <td class="dr-mono"><?= $served ? '$' . htmlspecialchars(number_format($money, 2)) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($rows): ?>
      <div class="dr-table-foot" id="staffFoot">
        <span><?= (int)$peopleCount ?> people worked</span> &middot; <span><?= (int)$ordersTotal ?> orders served</span> &middot; <span>$<?= htmlspecialchars(number_format($moneyTotal, 2)) ?> taken</span>
      </div>
      <?php endif; ?>

      <?php if ($unlinkedMoney > 0.005): ?>
      <p class="dr-note" style="margin-top:12px">$<?= htmlspecialchars(number_format($unlinkedMoney, 2)) ?> collected today is not linked to anyone who was clocked in.</p>
      <?php endif; ?>
    </div>
    <?php
}

// Tabs 2-4 ask for their own HTML. Each branch echoes a fragment and exits.
// This MUST run before any HTML output — a fragment response is inlined into
// a tab panel by JS, so it must never carry the page chrome.
$fragment = $_GET['fragment'] ?? '';
if ($fragment !== '') {
    header('Content-Type: text/html; charset=utf-8');
    switch ($fragment) {
        case 'orders': dr_fragment_orders($conn, $date); break;
        case 'stock':  dr_fragment_stock($conn, $date); break;
        case 'staff':  dr_fragment_staff($conn, $date); break;
        default: http_response_code(404); echo 'Unknown tab.';
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Daily Report | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.tailwindcss.com"></script>
<script>(function(){try{if(localStorage.getItem("theme")==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0a0a0a;--surface:#111;--surface2:#161616;--border:rgba(255,255,255,.07);
  --amber:#d1904b;--amber-dim:rgba(209,144,75,.12);--amber-border:rgba(209,144,75,.2);
  --text:#f0f0f0;--muted:#555;--muted2:#888;
  --bg-card:var(--surface);--text-muted:var(--muted2);
  --radius:10px;
  --bar:#16130f;              /* brand bar — near-black in both themes */
  --bar-text:#f3f1ee;
  --ok:#2f9e5f;--ok-bg:rgba(47,158,95,.14);
  --warn:#c98a2e;--warn-bg:rgba(201,138,46,.14);
  --stop:#d1544a;--stop-bg:rgba(209,84,74,.14);
  /* Data — counts, ids, times, money in tables — is set in mono. Numbers line
     up column to column and stop reading as prose. System stack: no web font
     to load, so this costs nothing. */
  --mono:ui-monospace,SFMono-Regular,"SF Mono",Menlo,Consolas,"Liberation Mono",monospace;
}
[data-theme="light"]{
  --bg:#f6f5f3;--surface:#FFFFFF;--surface2:#faf9f7;--border:#e6e3de;
  --text:#141210;--muted:#9a8f84;--muted2:#6b6259;
  --bg-card:var(--surface);--text-muted:var(--muted2);
  --ok:#1f7a45;--ok-bg:#e8f4ec;
  --warn:#9a6410;--warn-bg:#fbf1de;
  --stop:#b0342a;--stop-bg:#fbeae8;
}
body{
  font-family:'Poppins',sans-serif;
  background:var(--bg);
  color:var(--text);min-height:100vh;
}

/* ── Brand bar ── the app's name and where you are, before anything else. */
.dr-topbar{
  background:var(--bar);color:var(--bar-text);
  padding:0 20px;
}
.dr-topbar-in{
  max-width:1180px;margin:0 auto;height:52px;
  display:flex;align-items:center;gap:12px;
}
.dr-topbar-mark{
  width:26px;height:26px;border-radius:7px;background:var(--amber);
  display:inline-flex;align-items:center;justify-content:center;color:#1a1207;font-size:13px;
}
.dr-topbar-name{font-size:14px;font-weight:700;letter-spacing:.2px}
.dr-topbar-sep{color:rgba(243,241,238,.28)}
.dr-topbar-where{font-family:var(--mono);font-size:12px;color:rgba(243,241,238,.62)}
.dr-topbar-right{margin-left:auto;font-family:var(--mono);font-size:12px;color:rgba(243,241,238,.62)}
.dr-topbar-right a{color:inherit;text-decoration:none}
.dr-topbar-right a:hover{color:var(--amber)}

.wrap{max-width:1180px;margin:0 auto;padding:22px 20px 60px}

.back-btn{
  display:inline-flex;align-items:center;gap:8px;text-decoration:none;color:var(--amber);
  font-size:13.5px;font-weight:600;padding:9px 16px;border-radius:10px;
  border:1px solid var(--amber-border);background:var(--amber-dim);transition:all .2s;
  margin-bottom:18px;
}
.back-btn:hover{background:rgba(209,144,75,.22)}
.dr-live{
  display:inline-block;width:7px;height:7px;border-radius:50%;background:#4ade80;
  margin-right:7px;vertical-align:middle;
}

/* ── Header ── */
.dr-head{
  display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:14px;
  margin-bottom:18px;
}
.dr-eyebrow{font-family:var(--mono);font-size:11px;font-weight:500;color:var(--muted2);text-transform:uppercase;letter-spacing:1.4px;margin-bottom:5px}
.dr-head h1{font-size:26px;font-weight:700;color:var(--text);letter-spacing:-.4px}
.dr-head-sub{font-family:var(--mono);font-size:12px;color:var(--muted2);margin-top:5px}
.dr-head-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.dr-nav{
  display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border-radius:9px;
  background:var(--surface);border:1px solid var(--border);color:var(--text);
  font-size:13px;font-weight:600;text-decoration:none;cursor:pointer;font-family:'Poppins',sans-serif;
  transition:all .2s;
}
.dr-nav:hover,.dr-nav:focus{outline:none;border-color:var(--amber);color:var(--amber)}
.dr-nav.is-disabled{pointer-events:none;opacity:.35}
.dr-range-label{pointer-events:none;background:var(--amber-dim);border-color:var(--amber-border);color:var(--amber)}

/* ── Tabs ── */
.dr-tabs{
  display:flex;gap:2px;border-bottom:1px solid var(--border);margin-bottom:20px;
  overflow-x:auto;
  /* Scrolls on a narrow screen without ever drawing a bar across the tabs. */
  scrollbar-width:none;-ms-overflow-style:none;
}
.dr-tabs::-webkit-scrollbar{display:none}
.dr-tab{
  appearance:none;background:none;border:none;border-bottom:2px solid transparent;
  padding:12px 16px;font-size:13.5px;font-weight:600;color:var(--muted2);
  font-family:'Poppins',sans-serif;cursor:pointer;white-space:nowrap;
  display:inline-flex;align-items:center;gap:8px;margin-bottom:-1px;
}
.dr-tab i{font-size:12px;opacity:.85}
.dr-tab:hover{color:var(--text)}
.dr-tab.is-on{color:var(--text);border-bottom-color:var(--amber)}
.dr-tab.is-on i{color:var(--amber);opacity:1}
.dr-badge{
  display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;
  padding:0 6px;border-radius:20px;background:var(--amber);
  color:#1a1207;font-family:var(--mono);font-size:10.5px;font-weight:700;
}
.dr-badge:empty{display:none}

/* ── Panels ── */
.dr-panel{animation:fadeInUp .3s ease both}
@keyframes fadeInUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.dr-card{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  padding:18px 20px;
}
.dr-loading,.dr-error{
  padding:48px 20px;text-align:center;color:var(--muted2);font-size:13.5px;
}
.dr-error button{
  margin-left:8px;padding:6px 12px;border-radius:8px;border:1px solid var(--amber-border);
  background:var(--amber-dim);color:var(--amber);font-size:12.5px;font-weight:600;
  cursor:pointer;font-family:'Poppins',sans-serif;
}

/* ── Tab 1: the three verdicts ── */

.tone-good .dr-line { color: var(--ok); }
.tone-bad  .dr-line { color: var(--stop); }
.tone-flat .dr-line { color: var(--text-muted); }

.dr-q     { font-family: var(--mono); font-size: 11px; font-weight: 500; letter-spacing: .1em; text-transform: uppercase; color: var(--text-muted); }
/* Khmer needs more leading than Latin or the diacritics clip. */
.dr-sub   { font-size: 13px;   color: var(--text-muted); }
.dr-line  { font-weight: 700; margin-top: 10px; }
.dr-foot  { font-size: 12px; color: var(--text-muted); margin-top: 8px; line-height: 1.7; }

/* ── Headline stats ── the reference's card row, with a comparison line where
   it had a static caption. Colour lives on that line only: it is the one part
   that carries a judgement, so it is the one part allowed to be red or green. */
.dr-cards { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; grid-auto-rows: 1fr; }
@media (max-width: 1100px) { .dr-cards { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 700px)  { .dr-cards { grid-template-columns: repeat(2, 1fr); } }
.dr-stat, .dr-cards > .dr-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 10px 12px; cursor: pointer; transition: all .2s ease; display: flex; flex-direction: column; justify-content: space-between; height: 100%; }
.dr-stat:hover, .dr-cards > .dr-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(209,144,75,.12); border-color: rgba(209,144,75,.5); background: rgba(209,144,75,.12); }
.dr-stat.is-lead { border-left: 3px solid var(--amber); }
.dr-v-lg  { font-size: 22px; font-weight: 800; font-variant-numeric: tabular-nums; margin: 4px 0 6px; letter-spacing: -.5px; flex: 1; display: flex; align-items: center; }
.dr-delta { font-size: 12px; font-weight: 600; line-height: 1.5; min-height: 2.5rem; display: flex; align-items: flex-end; }
.dr-delta.tone-good { color: var(--ok); }
.dr-delta.tone-bad  { color: var(--stop); }
.dr-delta.tone-flat { color: var(--text-muted); font-weight: 500; }

/* Stock warning — always rendered (fixed slot, never shifts layout). */
.dr-alert-wrap { min-height: 46px; margin-bottom: 18px; }
.dr-alert {
  display: flex; align-items: flex-start; gap: 10px; margin-top: 14px;
  padding: 12px 16px; border-radius: var(--radius); font-size: 13px; line-height: 1.55;
  background: var(--warn-bg); border: 1px solid var(--amber-border); color: var(--text);
}
.dr-alert i { color: var(--warn); margin-top: 2px; }
.dr-alert.is-clear { background: var(--ok-bg); border-color: rgba(47,158,95,.25); }
.dr-alert.is-clear i { color: var(--ok); }

/* ── Tab 1: the neutral row — facts, no colour ── */
.dr-k     { font-family: var(--mono); font-size: 11px; font-weight: 500; letter-spacing: .1em; text-transform: uppercase; color: var(--text-muted); }
.dr-v     { font-size: 18px; font-weight: 800; font-variant-numeric: tabular-nums; margin-top: 2px; flex: 1; display: flex; align-items: center; }
.dr-card, .dr-fact {
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 10px 12px; display: flex; flex-direction: column; justify-content: space-between; height: 100%; }
.dr-note  { font-size: 12px; color: var(--text-muted); margin-top: 4px; line-height: 1.6; }
.dr-muted { color: var(--text-muted); font-size: 11.5px; }
.dr-note, .dr-delta { min-height: 1.8rem; display: flex; align-items: flex-end; }
.dr-delta-spacer { min-height: 1.8rem; }
.dr-wide  { margin-top: 16px; }

.dr-bar   { display: flex; width: 100%; height: 14px; border-radius: 8px; overflow: hidden; background: var(--surface2); margin-top: 10px; }
.seg      { display: block; height: 100%; }
/* One hue, descending strength — segments are labelled underneath, so they
   don't need to be independently identifiable by colour. Never red/green:
   colour on this page means "act on this", reserved for the verdict boxes. */
.seg-cash   { background: rgba(209,144,75,1);    }
.seg-bakong { background: rgba(209,144,75,.65);  }
.seg-later  { background: rgba(209,144,75,.4);   }
.seg-other  { background: rgba(209,144,75,.22);  }

/* Auto-fit, not a fixed four: a row of three stats should fill the width
   rather than leave a dead fourth column. */
.dr-facts-inline { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; margin-top: 12px; }




/* ── Bottom tall row (Best Sellers + Best Category) ── */
.dr-tall { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; margin-top: 16px; align-items: stretch; }
.dr-tall > .dr-card { display: flex; flex-direction: column; }
.dr-tall > .dr-card > .dr-k { flex-shrink: 0; }
@media (max-width: 1000px) { .dr-tall { grid-template-columns: 1fr 1fr; } }
@media (max-width: 600px) { .dr-tall { grid-template-columns: 1fr; } }

.dr-bar-list { list-style: none; padding: 0; margin: 12px 0 0; display: flex; flex-direction: column; gap: 10px; flex: 1; }
.dr-bar-item { display: grid; grid-template-columns: 1fr auto; gap: 4px 12px; align-items: center; }
.dr-bar-label { font-size: 13px; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dr-bar-num  { font-size: 12px; font-weight: 700; color: var(--text); font-variant-numeric: tabular-nums; text-align: right; white-space: nowrap; }
.dr-bar-track { grid-column: 1 / -1; height: 6px; border-radius: 3px; background: var(--border); overflow: hidden; }
.dr-bar-fill  { height: 100%; border-radius: 3px; background: var(--amber); transition: width .3s ease; }
.dr-bar-fill.cat { background: var(--ok); }

/* ── Charts ── drawn by hand in CSS and SVG. No chart library: a CDN that
   fails on venue wifi is a blank chart in front of a judge. */
.dr-charts { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 16px; align-items: stretch; }
.dr-charts.dr-charts--full { grid-template-columns: 1fr; }
.dr-charts > .dr-card { display: flex; flex-direction: column; }
.dr-charts > .dr-card > .dr-k { flex-shrink: 0; }
.dr-charts > .dr-card > .dr-note { flex-shrink: 0; }
.dr-charts > .dr-card .dr-chart-area { flex: 1; min-height: 0; }
@media (max-width: 900px) { .dr-charts { grid-template-columns: 1fr; } }

.dr-chart-area { display: grid; grid-template-columns: 40px 1fr; gap: 8px; margin-top: 14px; }
.dr-y-axis { height: 150px; display: flex; flex-direction: column; justify-content: space-between; align-items: flex-end; padding-bottom: 0; }
.dr-y-label { font-family: var(--mono); font-size: 9px; color: var(--muted2); line-height: 1; }
.dr-chart-body { min-width: 0; }
.dr-chart-vis { position: relative; }
.dr-gridlines { position: absolute; inset: 0; pointer-events: none; }
.dr-gridline { display: block; position: absolute; left: 0; right: 0; border-top: 1px dashed var(--border); }
.dr-hours { display: flex; align-items: flex-end; gap: 3px; height: 150px; }
.dr-hour  { flex: 1; height: 100%; display: flex; align-items: flex-end; border-radius: 3px; cursor: pointer; position: relative; }
.dr-hour-fill { display: block; width: 100%; background: linear-gradient(to top, rgba(209,144,75,.25), rgba(209,144,75,.7)); border-radius: 3px 3px 0 0; transition: all .2s ease; }
.dr-hour:hover .dr-hour-fill { filter: brightness(1.35); box-shadow: 0 0 16px rgba(209,144,75,.35); transform: scaleY(1.06); transform-origin: bottom; }
.dr-hour.is-peak .dr-hour-fill { background: linear-gradient(to top, rgba(255,193,7,.3), #e8a84c); box-shadow: 0 0 12px rgba(232,168,76,.3); }
.dr-hour-tip { display: none; position: absolute; bottom: calc(100% + 4px); left: 50%; transform: translateX(-50%);
  background: var(--surface2); border: 1px solid var(--border); border-radius: 5px; padding: 4px 8px;
  font-size: 10px; font-weight: 600; color: var(--text); white-space: nowrap; z-index: 10; pointer-events: none; }

/* Midnight. Marked because the axis crosses a calendar day mid-chart and a
   reader who doesn't know the 06:00 business day would otherwise read the
   post-midnight bars as an error. */
.dr-hour.is-daybreak { border-left: 1px dashed var(--border); margin-left: 2px; padding-left: 2px; }
.dr-hours-axis { display: flex; justify-content: space-between; font-family: var(--mono); font-size: 10px; color: var(--muted2); margin-top: 6px; }

.dr-donut-row { display: flex; flex-direction: column; align-items: center; margin-top: 12px; flex: 1; }
.dr-donut-row .dr-legend { margin-top: auto; }
.dr-donut { width: 140px; height: 140px; flex: none; transform: rotate(-90deg); }
.dr-donut-track { fill: none; stroke: var(--surface2); stroke-width: 16; }
.dr-donut-seg   { fill: none; stroke-width: 16; cursor: pointer; transition: stroke-width .2s, filter .2s; }
.dr-donut-seg:hover { stroke-width: 22; filter: brightness(1.2); }
.dr-donut-group { cursor: pointer; }
.dr-donut-float-tip { position: fixed; z-index: 999; pointer-events: none; display: none;
  background: rgba(26,26,30,.94); border: 1px solid var(--border); border-radius: 6px;
  padding: 5px 10px; font-size: 11px; font-weight: 600; color: #eaeaea; white-space: nowrap;
  box-shadow: 0 4px 14px rgba(0,0,0,.35); }
.dr-donut-float-tip .dr-dot { width: 7px; height: 7px; border-radius: 2px; margin-right: 6px; vertical-align: middle; }
.dr-legend li { display: flex; justify-content: space-between; align-items: center; width: 100%; cursor: pointer; transition: opacity .2s; }
.dr-legend li:hover { opacity: .8; }
.dr-legend li:hover .dr-dot { transform: scale(1.35); }
.dr-legend-left { display: flex; align-items: center; gap: 8px; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; }
.dr-legend b { flex-shrink: 0; margin-left: 0; }
.dr-dot { transition: transform .2s; }
.dr-donut-mid, .dr-donut-sub { transform: rotate(90deg); transform-origin: 70px 70px; text-anchor: middle; }
.dr-donut-mid { font-family: var(--mono); font-size: 15px; font-weight: 700; fill: var(--text); }
.dr-donut-sub { font-family: var(--mono); font-size: 9px; fill: var(--muted2); letter-spacing: .1em; }
.dr-legend { list-style: none; display: flex; flex-direction: column; gap: 6px; font-size: 12.5px; color: var(--text-muted); width: 100%; padding: 0; margin-top: 12px; }
.dr-legend b { color: var(--text); font-family: var(--mono); font-size: 12px; margin-left: 4px; }
.dr-dot { display: inline-block; width: 9px; height: 9px; border-radius: 3px; margin-right: 7px; }
.dr-dot.seg-cash{background:rgba(209,144,75,1)} .dr-dot.seg-bakong{background:rgba(209,144,75,.65)}
.dr-dot.seg-later{background:rgba(209,144,75,.4)} .dr-dot.seg-other{background:rgba(209,144,75,.22)}
.dr-donut-seg.seg-cash{stroke:rgba(209,144,75,1)} .dr-donut-seg.seg-bakong{stroke:rgba(209,144,75,.65)}
.dr-donut-seg.seg-later{stroke:rgba(209,144,75,.4)} .dr-donut-seg.seg-other{stroke:rgba(209,144,75,.22)}
.dr-donut-seg.cat0{stroke:#d1904b} .dr-donut-seg.cat1{stroke:#5a9e7e}
.dr-donut-seg.cat2{stroke:#b87a7a} .dr-donut-seg.cat3{stroke:#7a96b8}
.dr-donut-seg.cat4{stroke:#a88ab8}
.dr-dot.cat0{background:#d1904b} .dr-dot.cat1{background:#5a9e7e}
.dr-dot.cat2{background:#b87a7a} .dr-dot.cat3{background:#7a96b8}
.dr-dot.cat4{background:#a88ab8}
.dr-donut-seg.sel0{stroke:#e8a84c} .dr-donut-seg.sel1{stroke:#c97b84}
.dr-donut-seg.sel2{stroke:#7fb08c} .dr-donut-seg.sel3{stroke:#b58ac4}
.dr-donut-seg.sel4{stroke:#7a9ec9} .dr-donut-seg.sel5{stroke:#b8a07a}
.dr-dot.sel0{background:#e8a84c} .dr-dot.sel1{background:#c97b84}
.dr-dot.sel2{background:#7fb08c} .dr-dot.sel3{background:#b58ac4}
.dr-dot.sel4{background:#7a9ec9} .dr-dot.sel5{background:#b8a07a}
.dr-fact  { display: flex; flex-direction: column; }
.dr-v-sm  { font-size: 20px; font-weight: 800; font-variant-numeric: tabular-nums; }
.dr-v-text{ font-size: 20px; font-weight: 700; font-variant-numeric: initial; letter-spacing: 0; }

/* ── Tab 2: Orders ── */
.dr-pills   { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
.dr-pill    {
  appearance: none; font-family: 'Poppins',sans-serif; cursor: pointer;
  padding: 7px 14px; border-radius: 20px; font-size: 12.5px; font-weight: 600;
  background: var(--surface2); border: 1px solid var(--border); color: var(--text-muted);
  transition: all .15s;
}
.dr-pill:hover  { color: var(--text); }
.dr-pill.is-on  { background: var(--amber-dim); border-color: var(--amber-border); color: var(--amber); }

.dr-table-wrap  { overflow-x: auto; }
.dr-table-wrap.orders-scroll,
.dr-table-wrap.stock-scroll { max-height: 550px; overflow-y: auto; scrollbar-width: none; -ms-overflow-style: none; }
.dr-table-wrap.orders-scroll::-webkit-scrollbar,
.dr-table-wrap.stock-scroll::-webkit-scrollbar { display: none; }
.dr-table-wrap.orders-scroll thead,
.dr-table-wrap.stock-scroll thead { position: sticky; top: 0; z-index: 2; }
.dr-table-wrap.orders-scroll thead th,
.dr-table-wrap.stock-scroll thead th { background: var(--surface2); }
.dr-table       { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.dr-table th    {
  text-align: left; font-family: var(--mono); font-size: 10.5px; font-weight: 500; letter-spacing: .1em; text-transform: uppercase;
  color: var(--text-muted); padding: 10px; border-bottom: 1px solid var(--border);
  background: var(--surface2);
}
.dr-table td    { padding: 11px 10px; border-bottom: 1px solid var(--border); font-variant-numeric: tabular-nums; }
.dr-table tbody tr:hover td { background: var(--surface2); }
.dr-table tbody tr:last-child td { border-bottom: none; }
/* Data columns — ids, clock times, money — set in mono so they align down the
   column and stop reading as prose. */
.dr-mono { font-family: var(--mono); font-size: 12.5px; }
.dr-mono-dim { font-family: var(--mono); font-size: 12.5px; color: var(--text-muted); }

/* Status pills. These are row-level state, not verdicts: small, inside dense
   text, describing one line. The big cards stay uncoloured. */
.dr-status {
  display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 20px;
  font-family: var(--mono); font-size: 10.5px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase;
}
.dr-status.is-ok       { background: var(--ok-bg);   color: var(--ok); }
.dr-status.is-open     { background: var(--warn-bg); color: var(--warn); }
.dr-status.is-refunded { background: var(--stop-bg); color: var(--stop); }
.dr-status.is-neutral  { background: var(--surface2); color: var(--text-muted); }

/* Initial avatars on the staff table, as in the reference. */
.dr-who { display: inline-flex; align-items: center; gap: 9px; }
.dr-av  {
  width: 26px; height: 26px; border-radius: 50%; flex: none;
  display: inline-flex; align-items: center; justify-content: center;
  font-family: var(--mono); font-size: 10.5px; font-weight: 700;
  background: var(--amber-dim); color: var(--amber); border: 1px solid var(--amber-border);
}
/* Amber = needs attention, not a verdict colour — reserved for tab 1. */
.dr-table tr.is-open td { background: var(--amber-dim); }
.dr-table tr.is-open td:first-child { border-left: 3px solid var(--amber); }

.dr-table-foot  { display: flex; gap: 10px; margin-top: 12px; font-size: 12.5px; color: var(--text-muted); }
/* Pagination — same component as ingredients.php so paging feels identical
   wherever a table appears in this app. Kept selector-for-selector. */
.pg-wrap { padding:14px 0 0; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
.pg-nav { display:flex; gap:4px; flex-wrap:wrap; }
.pg-btn { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text-muted); font-size:13px; font-weight:600; text-decoration:none; cursor:pointer; transition:.15s ease; }
.pg-btn:hover { border-color:var(--amber); color:var(--amber); }
.pg-active { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; background:var(--amber); border:1px solid var(--amber); color:#000; font-size:13px; font-weight:700; }
.pg-disabled { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; border:1px solid var(--border); color:var(--text-muted); font-size:13px; opacity:.35; cursor:default; }
.pg-ellipsis { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; color:var(--text-muted); font-size:13px; }
.pg-info { font-size:12px; color:var(--text-muted); }

/* ── Tab 3: Stock ── */
.dr-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin: 16px 0 14px; }
.dr-search  {
  font-family: 'Poppins',sans-serif; font-size: 12.5px; color: var(--text);
  background: var(--surface2); border: 1px solid var(--border); border-radius: 20px;
  padding: 8px 14px; min-width: 200px;
}
.dr-search:focus { outline: none; border-color: var(--amber); }
/* Amber = needs attention, same rule as the orders tab's open rows — never a
   verdict colour. Rows that don't need buying get a neutral, colourless bar. */
.dr-table tr.needs-buy td { background: var(--amber-dim); }
.dr-table tr.needs-buy td:first-child { border-left: 3px solid var(--amber); }
.dr-stock-bar  { width: 100px; height: 8px; border-radius: 6px; overflow: hidden; background: var(--surface2); }
.dr-stock-fill { display: block; height: 100%; }
.dr-stock-fill.tone-attn   { background: var(--amber); }
.dr-stock-fill.tone-normal { background: var(--muted2); }

/* ── Date range picker ── */
.dr-range-form {
  display: flex; align-items: center; gap: 8px;
}
.dr-date-input {
  font-family:'Poppins',sans-serif; font-size:13px; color:var(--text);
  background:var(--surface2); border:1px solid var(--border); border-radius:9px;
  padding:9px 12px; cursor:pointer;
}
.dr-date-input:focus { outline:none; border-color:var(--amber); }
.dr-range-sep { color:var(--muted2); font-size:14px; }
.dr-range-go { padding:9px 14px !important; }

/* ── View selector ── */
.dr-view-form { display:flex; align-items:center; }
.dr-view-select {
  font-family:'Poppins',sans-serif; font-size:12.5px; color:var(--text);
  background:var(--surface2); border:1px solid var(--border); border-radius:9px;
  padding:8px 28px 8px 12px; cursor:pointer; appearance:auto;
  -webkit-appearance:auto; -moz-appearance:auto;
}
.dr-view-select:focus { outline:none; border-color:var(--amber); }

.dr-range-overlay {
  position: fixed; inset: 0; z-index: 999;
  background: rgba(0,0,0,.5);
  display: flex; align-items: center; justify-content: center;
}
.dr-range-modal {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 28px; width: 380px; max-width: 90vw;
  box-shadow: 0 20px 60px rgba(0,0,0,.4);
}
.dr-range-modal h3 { font-size:16px; font-weight:700; margin-bottom:18px; }
.dr-range-modal .dr-range-form { flex-wrap:wrap; }
.dr-range-modal .dr-date-input { flex:1; min-width:130px; }
.dr-range-actions { display:flex; gap:8px; margin-top:16px; justify-content:flex-end; }

.dr-foot-bar{
  display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;
  margin-top:28px;padding-top:14px;border-top:1px solid var(--border);
  font-family:var(--mono);font-size:11px;color:var(--muted2);
}

@media print {
    .dr-tabs, .dr-head-actions, .dr-nav, .back-btn, .dr-topbar, .dr-foot-bar { display: none !important; }
    .dr-panel[hidden] { display: none !important; }
    body { background: #fff !important; color: #000 !important; }
    .dr-card { break-inside: avoid; border: 1px solid #ccc !important; }
}
</style>
</head>
<?php
$filter_from = trim($_GET['from_date'] ?? $_GET['date_from'] ?? ($isRange ? $dateFrom : $date));
$filter_to   = trim($_GET['to_date']   ?? $_GET['date_to']   ?? ($isRange ? $dateTo   : $date));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_from)) $filter_from = $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_to))   $filter_to   = $today;
if ($filter_from > $filter_to) [$filter_from, $filter_to] = [$filter_to, $filter_from];

$filter_status  = trim($_GET['status'] ?? '');
$filter_payment = trim($_GET['payment_method'] ?? '');
if (!$_is_mgr) {
    $filter_user = (int)$_SESSION['user_id'];
}

$where_conds = ["o.business_date BETWEEN '$filter_from' AND '$filter_to'"];
if ($filter_status !== '') {
    $where_conds[] = "o.status = '" . $conn->real_escape_string($filter_status) . "'";
}
if ($filter_payment !== '') {
    $where_conds[] = "o.payment_method = '" . $conn->real_escape_string($filter_payment) . "'";
}
if ($filter_user > 0) {
    $where_conds[] = "(o.user_id = $filter_user OR o.employee_id = $filter_user)";
}
$where_str = implode(' AND ', $where_conds);

$sql_table_orders = "SELECT o.order_id, o.daily_order_no, o.order_date, o.customer_name, o.table_number, o.order_type, o.total, o.payment_method, o.status,
                            IFNULL(o.promotion_discount, 0) + IFNULL(o.manual_discount, 0) AS discount_amount,
                            COALESCE(e.name, u.username) as staff_name 
                     FROM orders o 
                     LEFT JOIN users u ON u.user_id = o.user_id 
                     LEFT JOIN employees e ON e.user_id = o.user_id OR e.employee_id = o.user_id
                     WHERE $where_str 
                     ORDER BY o.order_id DESC";
$res_table_orders = $conn->query($sql_table_orders);
$table_orders = $res_table_orders ? $res_table_orders->fetch_all(MYSQLI_ASSOC) : [];

// Compute per-order COGS for Profit column
$cogsByOrder = [];
if ($table_orders) {
    $tbl_order_ids = array_map(fn($o) => (int)$o['order_id'], $table_orders);
    $in_ids = implode(',', $tbl_order_ids);
    $cost_map_ref = ingredient_cost_map($conn);
    
    $q_its = $conn->query("
        SELECT oi.order_id, oi.product_id, oi.milk, oi.quantity
        FROM order_items oi
        WHERE oi.order_id IN ($in_ids)
    ");
    $items_by_order = [];
    $pids_map = [];
    if ($q_its) {
        while ($it = $q_its->fetch_assoc()) {
            $items_by_order[(int)$it['order_id']][] = $it;
            if ((int)$it['product_id'] > 0) { $pids_map[(int)$it['product_id']] = true; }
        }
    }
    
    $recipes_map = [];
    if ($pids_map) {
        $pin_str = implode(',', array_keys($pids_map));
        $qr_rec = $conn->query("
            SELECT pi.product_id, pi.ingredient_id, pi.amount_used, i.ingredient_name
            FROM product_ingredients pi
            JOIN ingredients i ON i.ingredient_id = pi.ingredient_id
            WHERE pi.product_id IN ($pin_str)
        ");
        if ($qr_rec) {
            while ($r = $qr_rec->fetch_assoc()) {
                $recipes_map[(int)$r['product_id']][] = [
                    'ingredient_id'   => (int)$r['ingredient_id'],
                    'ingredient_name' => $r['ingredient_name'],
                    'amount_used'     => (float)$r['amount_used'],
                ];
            }
        }
    }

    foreach ($items_by_order as $oid => $its) {
        $ocost = 0.0;
        foreach ($its as $it) {
            $pid  = (int)$it['product_id'];
            $qty  = max(1, (int)$it['quantity']);
            $milk = trim((string)$it['milk']);
            foreach ($recipes_map[$pid] ?? [] as $rc) {
                $amount = $rc['amount_used'] * $qty;
                if ($amount <= 0) continue;
                if (strpos(strtolower(trim($rc['ingredient_name'])), 'milk') !== false) {
                    $key = strtolower(trim($milk !== '' ? $milk : 'Fresh Milk'));
                    if (isset($cost_map_ref[$key])) { $ocost += $amount * (float)$cost_map_ref[$key]['unit_cost']; }
                } else {
                    $ocost += $amount * (float)($cost_map_ref[$rc['ingredient_id']]['unit_cost'] ?? 0);
                }
            }
        }
        $cogsByOrder[$oid] = $ocost;
    }
}

$sum_gross  = 0.0;
$sum_disc   = 0.0;
$sum_net    = 0.0;
$sum_cogs   = 0.0;
$sum_profit = 0.0;
$sum_qty    = 0;

$processed_rows = [];
$idx = 0;
foreach ($table_orders as $to) {
    $idx++;
    $dailyOrderNo = (int)($to['daily_order_no'] ?? $idx);
    $orderNoStr   = '#' . str_pad((string)$dailyOrderNo, 4, '0', STR_PAD_LEFT);
    $oid   = (int)$to['order_id'];
    $disc  = (float)($to['discount_amount'] ?? 0);
    $net   = (float)$to['total'];
    $gross = $net + $disc;
    $ocogs = (float)($cogsByOrder[$oid] ?? 0.0);
    $profit = $net - $ocogs;

    $o_its = $items_by_order[$oid] ?? [];
    $o_qty = 0;
    foreach ($o_its as $oit) {
        $o_qty += max(1, (int)($oit['quantity'] ?? 1));
    }

    $sum_gross  += $gross;
    $sum_disc   += $disc;
    $sum_net    += $net;
    $sum_cogs   += $ocogs;
    $sum_profit += $profit;
    $sum_qty    += $o_qty;

    $date_str = date('g:i j/n/Y', strtotime($to['order_date']));
    $cust = htmlspecialchars($to['customer_name'] ?: 'General');
    $staff = htmlspecialchars($to['staff_name'] ?: 'Visal');

    $fmt_gross  = ($gross == floor($gross) ? number_format($gross, 0) : number_format($gross, 2)) . '$';
    $fmt_disc   = $disc > 0 ? (($disc == floor($disc) ? number_format($disc, 0) : number_format($disc, 2)) . '$') : '0';
    $fmt_net    = ($net == floor($net) ? number_format($net, 0) : number_format($net, 2)) . '$';
    $fmt_cogs   = ($ocogs == floor($ocogs) ? number_format($ocogs, 0) : number_format($ocogs, 2)) . '$';
    $fmt_profit = ($profit == floor($profit) ? number_format($profit, 0) : number_format($profit, 2)) . '$';

    $processed_rows[] = [
        'no'         => $orderNoStr,
        'date'       => $date_str,
        'customer'   => $cust,
        'qty_item'   => $o_qty,
        'price'      => $fmt_gross,
        'discount'   => $fmt_disc,
        'total'      => $fmt_net,
        'profit'     => $fmt_profit,
        'cogs'       => $fmt_cogs,
        'place_by'   => $staff,
    ];
}

$fmt_sum_gross  = ($sum_gross == floor($sum_gross) ? number_format($sum_gross, 0) : number_format($sum_gross, 2)) . '$';
$fmt_sum_disc   = ($sum_disc == floor($sum_disc) ? number_format($sum_disc, 0) : number_format($sum_disc, 2)) . '$';
$fmt_sum_net    = ($sum_net == floor($sum_net) ? number_format($sum_net, 0) : number_format($sum_net, 2)) . '$';
$fmt_sum_cogs   = ($sum_cogs == floor($sum_cogs) ? number_format($sum_cogs, 0) : number_format($sum_cogs, 2)) . '$';
$fmt_sum_profit = ($sum_profit == floor($sum_profit) ? number_format($sum_profit, 0) : number_format($sum_profit, 2)) . '$';
?>
<body>
<div class="flex h-screen w-full overflow-hidden app-layout">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<main class="app-main flex-1 h-full overflow-y-auto er-container">
    <?php
    $report_category = 'Sales';
    $report_title    = 'Sales Report';
    $date_from       = $filter_from;
    $date_to         = $filter_to;

    $user_options = [];
    if ($_is_mgr) {
        $user_options[''] = 'All Staff';
        $q_users = $conn->query("SELECT u.user_id, u.username, e.name AS emp_name, r.slug AS role FROM users u LEFT JOIN employees e ON e.user_id = u.user_id LEFT JOIN roles r ON r.id = u.role_id ORDER BY COALESCE(NULLIF(e.name, ''), u.username) ASC");
        if ($q_users) {
            while ($ur = $q_users->fetch_assoc()) {
                $displayName = !empty($ur['emp_name']) ? $ur['emp_name'] : $ur['username'];
                $user_options[$ur['user_id']] = $displayName . ' (' . ucfirst($ur['role'] ?? 'staff') . ')';
            }
        }
    } else {
        $my_uid = (int)$_SESSION['user_id'];
        $my_name = $_SESSION['username'] ?? 'My Sales';
        $user_options[$my_uid] = $my_name;
    }

    $filter_options  = [
        [
            'name' => 'user_id',
            'label' => 'Staff Member',
            'options' => $user_options,
            'selected' => $filter_user
        ],
        [
            'name' => 'payment_method',
            'label' => 'Payment Method',
            'options' => ['' => 'All Methods', 'cash' => 'Cash', 'bakong' => 'Bakong'],
            'selected' => $filter_payment
        ]
    ];
    $export_excel_url = "daily_report_xlsx.php?date_from=" . urlencode($filter_from) . "&date_to=" . urlencode($filter_to);
    $export_pdf_url   = "daily_report_pdf.php?date_from=" . urlencode($filter_from) . "&date_to=" . urlencode($filter_to) . "&user_id=" . urlencode($filter_user) . "&payment_method=" . urlencode($filter_payment) . "&lang=" . urlencode(current_lang());
    require __DIR__ . '/report_header.php';

    $isKm = (current_lang() === 'km');
    $lbl_col_no       = $isKm ? 'លេខ Order' : 'No Order';
    $lbl_col_date     = $isKm ? 'កាលបរិច្ឆេទ' : 'Date';
    $lbl_col_cust     = $isKm ? 'អតិថិជន' : 'Customer';
    $lbl_col_price    = $isKm ? 'តម្លៃ' : 'Price';
    $lbl_col_qty      = $isKm ? 'ចំនួនទំនិញ' : 'Qty Item';
    $lbl_col_total    = $isKm ? 'ចំណូល' : 'Revenue';
    $lbl_col_profit   = $isKm ? 'ប្រាក់ចំណេញ' : 'Profit';
    $lbl_col_cogs     = $isKm ? 'ដើមទុនសរុប' : 'Total COGS';
    $lbl_col_place    = $isKm ? 'អ្នកលក់' : 'Place by';
    $lbl_col_sum      = $isKm ? 'សរុប' : 'Total';
    $lbl_date_from    = $isKm ? 'ចាប់ពីថ្ងៃ :' : 'Date From :';
    $lbl_date_to      = $isKm ? 'ដល់ថ្ងៃ :' : 'Date To :';
    $lbl_doc_no       = $isKm ? 'ចំនួន Order :' : 'Order :';
    $lbl_gross_sales  = $isKm ? 'ការលក់សរុប' : 'Total Gross Sales';
    $lbl_total_items  = $isKm ? 'ទំនិញសរុប' : 'Total Items';
    $lbl_net_revenue  = $isKm ? 'ចំណូលសុទ្ធសរុប' : 'Total Net Revenue';
    $lbl_total_profit = $isKm ? 'ប្រាក់ចំណេញសរុប' : 'Total Profit';
    ?>

    <!-- Data Table -->
    <div class="er-table-card">
        <div class="er-table-wrap">
            <table class="er-table">
                <thead>
                    <tr>
                        <th style="text-align:center"><?= htmlspecialchars($lbl_col_no) ?></th>
                        <th><?= htmlspecialchars($lbl_col_date) ?></th>
                        <th><?= htmlspecialchars($lbl_col_cust) ?></th>
                        <th style="text-align:right"><?= htmlspecialchars($lbl_col_price) ?></th>
                        <th style="text-align:center"><?= htmlspecialchars($lbl_col_qty) ?></th>
                        <th style="text-align:right"><?= htmlspecialchars($lbl_col_total) ?></th>
                        <th><?= htmlspecialchars($lbl_col_place) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($processed_rows)): ?>
                    <tr class="no-data">
                        <td colspan="7" class="no-data"><?= $isKm ? 'គ្មានទិន្នន័យ' : 'No data' ?></td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($processed_rows as $r): ?>
                    <tr>
                        <td style="text-align:center;font-weight:600;"><?= $r['no'] ?></td>
                        <td><?= $r['date'] ?></td>
                        <td><?= $r['customer'] ?></td>
                        <td style="text-align:right;"><?= $r['price'] ?></td>
                        <td style="text-align:center;font-weight:600;"><?= $r['qty_item'] ?></td>
                        <td style="text-align:right;font-weight:bold;color:#d1904b;"><?= $r['total'] ?></td>
                        <td><?= $r['place_by'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-summary-row" style="font-weight:700; background: linear-gradient(90deg, rgba(209, 144, 75, 0.15) 0%, rgba(20, 20, 28, 0.95) 100%); border-top: 2px solid #d1904b; border-bottom: 2px solid rgba(209, 144, 75, 0.3);">
                        <td colspan="3" style="text-align:center; padding: 0.85rem 1rem;">
                            <span style="display:inline-flex; align-items:center; gap:0.4rem; padding: 0.25rem 0.75rem; border-radius: 6px; background: rgba(209, 144, 75, 0.2); color: #d1904b; font-size: 0.8rem; letter-spacing: 0.05em; text-transform: uppercase; border: 1px solid rgba(209, 144, 75, 0.35);">
                                <i class="fa-solid fa-calculator" style="font-size:0.75rem;"></i> <?= htmlspecialchars($lbl_col_sum) ?>
                            </span>
                        </td>
                        <td style="text-align:right; color: #38bdf8; font-size: 0.95rem; font-weight: 700;"><?= $fmt_sum_gross ?></td>
                        <td style="text-align:center; padding: 0.85rem 1rem;">
                            <span style="display:inline-block; padding: 0.15rem 0.6rem; border-radius: 9999px; background: rgba(167, 139, 250, 0.18); color: #c084fc; font-weight: 700; font-size: 0.9rem; border: 1px solid rgba(167, 139, 250, 0.3);"><?= $sum_qty ?></span>
                        </td>
                        <td style="text-align:right; color: #fbbf24; font-size: 1rem; font-weight: 800; text-shadow: 0 0 10px rgba(251,191,36,0.25);"><?= $fmt_sum_net ?></td>
                        <td></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Summary Card -->
    <div class="er-summary-card">
        <div class="er-summary-info">
            <span><?= htmlspecialchars($lbl_date_from) ?> <strong><?= htmlspecialchars(date('j/n/Y', strtotime($filter_from))) ?></strong></span>
            <span><?= htmlspecialchars($lbl_date_to) ?> <strong><?= htmlspecialchars(date('j/n/Y', strtotime($filter_to))) ?></strong></span>
            <span><?= htmlspecialchars($lbl_doc_no) ?> <strong><?= count($processed_rows) ?></strong></span>
        </div>
        <div class="er-summary-stats">
            <div class="er-summary-stat-item">
                <span class="stat-label"><?= htmlspecialchars($lbl_gross_sales) ?></span>
                <span class="stat-val"><?= $fmt_sum_gross ?></span>
            </div>
            <div class="er-summary-stat-item">
                <span class="stat-label"><?= htmlspecialchars($lbl_total_items) ?></span>
                <span class="stat-val text-blue-400"><?= $sum_qty ?></span>
            </div>
            <div class="er-summary-stat-item">
                <span class="stat-label"><?= htmlspecialchars($lbl_net_revenue) ?></span>
                <span class="stat-val text-amber-400"><?= $fmt_sum_net ?></span>
            </div>
        </div>
    </div>
</main>
</div>
        </div>


    </div>
    <div class="dr-panel" id="panel-orders" hidden></div>
    <div class="dr-panel" id="panel-stock"  hidden></div>
    <div class="dr-panel" id="panel-staff"  hidden></div>



</div>

<script>
const drLoaded = {};           // tab -> true once its HTML has arrived
const DR_DATE  = <?= json_encode($date) ?>;
const DR_IS_RANGE = <?= $isRange ? 'true' : 'false' ?>;
const DR_DATE_FROM = <?= $isRange ? json_encode($dateFrom) : 'null' ?>;
const DR_DATE_TO   = <?= $isRange ? json_encode($dateTo) : 'null' ?>;
const DR_VIEW   = <?= json_encode($view) ?>;

function drShowTab(tab) {
    const btn = document.querySelector('.dr-tab[data-tab="' + tab + '"]');
    if (!btn) return;
    document.querySelectorAll('.dr-tab').forEach(b => b.classList.toggle('is-on', b === btn));
    document.querySelectorAll('.dr-panel').forEach(p => p.hidden = (p.id !== 'panel-' + tab));
    if (tab !== 'today' && !drLoaded[tab]) { loadFragment(tab); }
    try { sessionStorage.setItem('drActiveTab:' + DR_DATE, tab); } catch (e) {}
}

document.querySelectorAll('.dr-tab').forEach(btn => {
    btn.addEventListener('click', () => drShowTab(btn.dataset.tab));
});

// A poll-triggered reload must not throw away where the manager was reading —
// restore the tab they had open, and their scroll position, right after load.
// Gated on a one-shot flag set only by the poll handler immediately before
// its reload: an ordinary visit (nav click, typed URL, "Full analytics" back
// button) must always land on Today, never on whatever tab was left open the
// last time this date happened to be viewed.
(function drRestoreAfterReload() {
    try {
        const pendingKey = 'drPendingRestore:' + DR_DATE;
        if (sessionStorage.getItem(pendingKey) !== '1') { return; }
        sessionStorage.removeItem(pendingKey);
        const savedTab = sessionStorage.getItem('drActiveTab:' + DR_DATE);
        if (savedTab && savedTab !== 'today') { drShowTab(savedTab); }
        const savedY = sessionStorage.getItem('drScrollY:' + DR_DATE);
        if (savedY !== null) {
            sessionStorage.removeItem('drScrollY:' + DR_DATE);
            window.scrollTo(0, parseInt(savedY, 10) || 0);
        }
    } catch (e) {}
})();

async function loadFragment(tab) {
    const panel = document.getElementById('panel-' + tab);
    panel.innerHTML = '<div class="dr-loading">Loading…</div>';
    try {
        let url = 'daily_report.php?fragment=' + tab + '&date=' + encodeURIComponent(DR_DATE);
        if (DR_IS_RANGE) {
            url += '&date_from=' + encodeURIComponent(DR_DATE_FROM) + '&date_to=' + encodeURIComponent(DR_DATE_TO);
        }
        url += '&view=' + encodeURIComponent(DR_VIEW);
        const res  = await fetch(url);
        if (!res.ok) throw new Error(res.status);
        panel.innerHTML = await res.text();
        drLoaded[tab] = true;
        // Fragment HTML is set via innerHTML, so any <script> inside it is
        // inert. Wire up per-tab behaviour here instead, keyed by tab name.
        const initFn = window['drInit_' + tab];
        if (typeof initFn === 'function') initFn();
    } catch (e) {
        // Never leave a blank panel — say what happened and offer the retry.
        panel.innerHTML = '<div class="dr-error">Could not load this tab. '
                        + '<button onclick="loadFragment(\'' + tab + '\')">Try again</button></div>';
    }
}

/* The Excel export used to be built here, in the browser, by scraping the
 * rendered tables into a CSV. It has moved to daily_report_xlsx.php, which
 * queries the same shared helpers this page does. Scraping the DOM meant the
 * file could only ever contain what had already been rendered — tabs the
 * manager never opened had to be fetched first just to be read back out — and
 * CSV could carry no headings, no currency and no charts. */

/**
 * Shared pager for every table on this page — « ‹ 1 2 3 › », windowed two
 * either side of the current page with ellipses beyond. One renderer rather
 * than one per tab, so the two tables cannot drift apart.
 *   wrap  — the .pg-wrap element (hidden when there is only one page)
 *   info  — text for the left-hand side, e.g. "showing 1–10 of 49"
 *   onGo  — called with the requested page number
 */
function drRenderPager(wrap, page, totalPages, info, onGo) {
    if (!wrap) return;
    if (totalPages <= 1) { wrap.style.display = 'none'; wrap.innerHTML = ''; return; }
    wrap.style.display = 'flex';

    const btn = (label, target, title) =>
        `<a href="#" class="pg-btn" data-go="${target}" aria-label="${title}" title="${title}">${label}</a>`;
    const off = label => `<span class="pg-disabled">${label}</span>`;

    let nav = page > 1
        ? btn('&laquo;', 1, 'First page') + btn('&lsaquo;', page - 1, 'Previous page')
        : off('&laquo;') + off('&lsaquo;');

    const from = Math.max(1, page - 2);
    const to   = Math.min(totalPages, page + 2);
    if (from > 1) nav += '<span class="pg-ellipsis">…</span>';
    for (let i = from; i <= to; i++) {
        nav += i === page ? `<span class="pg-active">${i}</span>` : btn(i, i, 'Page ' + i);
    }
    if (to < totalPages) nav += '<span class="pg-ellipsis">…</span>';

    nav += page < totalPages
        ? btn('&rsaquo;', page + 1, 'Next page') + btn('&raquo;', totalPages, 'Last page')
        : off('&rsaquo;') + off('&raquo;');

    wrap.innerHTML = `<span class="pg-info">${info}</span><nav class="pg-nav">${nav}</nav>`;
    wrap.querySelectorAll('a[data-go]').forEach(a => {
        a.addEventListener('click', e => { e.preventDefault(); onGo(parseInt(a.dataset.go, 10)); });
    });
}

// ── Tab 2: Orders — filter pills + scrollable list ──
// Run once per fragment load (loadFragment calls this after innerHTML is set,
// since <script> tags inside injected HTML never execute).
function drInit_orders() {
    const panel = document.getElementById('panel-orders');
    const table = panel && panel.querySelector('#ordersTable');
    if (!table) return;
    const allRows  = Array.from(table.querySelectorAll('tbody tr[data-method]'));
    const foot     = panel.querySelector('#ordersFoot');
    let filtered = allRows.slice();

    function render() {
        allRows.forEach(r => { r.style.display = 'none'; });
        filtered.forEach(r => { r.style.display = ''; });
        renderFoot();
    }

    function renderFoot() {
        if (!foot) return;
        let cups = 0, collected = 0, notPaid = 0, notPaidCount = 0;
        filtered.forEach(r => {
            cups += parseInt(r.dataset.cups || '0', 10);
            const total = parseFloat(r.dataset.total || '0');
            if (r.dataset.state !== 'ok') { notPaid += total; notPaidCount++; }
            else { collected += total; }
        });
        let html = '<span>' + filtered.length + ' orders</span> · <span>' + cups + ' cups</span> · '
                 + '<span>$' + collected.toFixed(2) + ' collected</span>';
        if (notPaidCount > 0) {
            html += ' · <span>$' + notPaid.toFixed(2) + ' not paid yet</span>';
        }
        foot.innerHTML = html;
    }

    panel.querySelectorAll('.dr-pill').forEach(btn => {
        btn.addEventListener('click', () => {
            panel.querySelectorAll('.dr-pill').forEach(b => b.classList.toggle('is-on', b === btn));
            const f = btn.dataset.filter;
            filtered = (f === 'all') ? allRows.slice() : allRows.filter(r => r.dataset.method === f);
            render();
        });
    });

    render();
}

// ── Tab 3: Stock — filter pills + live search over ingredient name ──
function drInit_stock() {
    const panel = document.getElementById('panel-stock');
    const table = panel && panel.querySelector('#stockTable');
    if (!table) return;
    const allRows = Array.from(table.querySelectorAll('tbody tr[data-bucket]'));
    const search  = panel.querySelector('#stockSearch');
    const foot    = panel.querySelector('#stockFoot');
    let activeFilter = 'all';
    let filtered = allRows.slice();

    function apply() {
        const q = (search && search.value || '').trim().toLowerCase();
        filtered = allRows.filter(r =>
            (activeFilter === 'all' || r.dataset.bucket === activeFilter) &&
            (!q || r.dataset.name.includes(q))
        );
        render();
    }

    function render() {
        allRows.forEach(r => { r.style.display = 'none'; });
        filtered.forEach(r => { r.style.display = ''; });
        renderFoot();
    }

    function renderFoot() {
        if (!foot) return;
        if (filtered.length === 0)      foot.innerHTML = '<span>nothing matches</span>';
        else                            foot.innerHTML = '<span>' + filtered.length + ' item' + (filtered.length === 1 ? '' : 's') + '</span>';
    }

    panel.querySelectorAll('.dr-pill').forEach(btn => {
        btn.addEventListener('click', () => {
            panel.querySelectorAll('.dr-pill').forEach(b => b.classList.toggle('is-on', b === btn));
            activeFilter = btn.dataset.filter;
            apply();
        });
    });

    if (search) search.addEventListener('input', () => apply());

    apply();
}

// ── Live refresh (Task 9) — poll a cheap signature, today only. A past date
// is settled history and must never repeat a network request for it.
const DR_IS_TODAY = <?= ($isToday && !$isRange) ? 'true' : 'false' ?>;
let drSig = null;
if (DR_IS_TODAY) {
    setInterval(async () => {
        try {
            const res = await fetch('daily_report.php?poll=1&date=' + encodeURIComponent(DR_DATE));
            const { sig } = await res.json();
            if (drSig !== null && sig !== drSig) {
                // Reloading mid-read is hostile to a manager part-way down a
                // table — stash where they were so drRestoreAfterReload can
                // put them back once the new page settles. The pending-restore
                // flag is what tells that IIFE this reload was poll-triggered,
                // as opposed to an ordinary nav click that should land on Today.
                try {
                    sessionStorage.setItem('drScrollY:' + DR_DATE, String(window.scrollY));
                    sessionStorage.setItem('drPendingRestore:' + DR_DATE, '1');
                } catch (e) {}
                window.location.reload();
            }
            drSig = sig;
        } catch (e) { /* a dropped poll is not worth interrupting the manager */ }
    }, 30000);
}

// ── Range picker modal (triggered by selecting "Range" in view dropdown) ──
function drOpenRangeModal() {
    const today = <?= json_encode($today) ?>;
    const overlay = document.createElement('div');
    overlay.className = 'dr-range-overlay';
    overlay.innerHTML = `<div class="dr-range-modal">
      <h3>Select date range</h3>
      <form method="get" class="dr-range-form">
        <input type="hidden" name="view" value="range">
        <input type="date" name="date_from" value="${today}" class="dr-date-input" required max="${today}">
        <span class="dr-range-sep">→</span>
        <input type="date" name="date_to"   value="${today}" class="dr-date-input" required max="${today}">
        <div class="dr-range-actions">
          <button type="button" class="dr-nav" id="drRangeCancel">Cancel</button>
          <button type="submit" class="dr-nav" style="background:var(--amber);color:#1a1207;border-color:var(--amber)">View report</button>
        </div>
      </form>
    </div>`;
    document.body.appendChild(overlay);
    overlay.querySelector('#drRangeCancel').addEventListener('click', () => overlay.remove());
    overlay.addEventListener('click', function (ev) { if (ev.target === overlay) overlay.remove(); });
}
document.getElementById('drViewSelect')?.addEventListener('change', function (e) {
    if (this.value === 'range') {
        this.value = <?= json_encode($view) ?>;
        drOpenRangeModal();
    } else {
        this.form.submit();
    }
});
document.getElementById('drReRangeBtn')?.addEventListener('click', function () {
    drOpenRangeModal();
});

// ── Donut chart floating tooltip (cursor-follows) ──
document.addEventListener('DOMContentLoaded', function () {
    var tip = document.createElement('div');
    tip.className = 'dr-donut-float-tip';
    tip.id = 'drDonutTip';
    document.body.appendChild(tip);
    var segs = document.querySelectorAll('.dr-donut-seg');
    var activeSeg = null;
    function show(seg, x, y) {
        var label = seg.getAttribute('data-label') || '';
        var amt   = seg.getAttribute('data-amt') || '0.00';
        var pct   = seg.getAttribute('data-pct') || '0';
        var cls   = seg.getAttribute('data-cls') || '';
        tip.innerHTML = '<span class="dr-dot ' + cls + '"></span> ' + label + ' \u00B7 $' + amt + ' (' + pct + '%)';
        tip.style.display = 'block';
        position(tip, x, y);
    }
    function position(el, mx, my) {
        el.style.left = (mx + 15) + 'px';
        el.style.top  = (my - 35) + 'px';
    }
    function hide() {
        tip.style.display = 'none';
    }
    segs.forEach(function (seg) {
        seg.addEventListener('mouseenter', function (e) {
            activeSeg = this;
            show(this, e.clientX, e.clientY);
        });
        seg.addEventListener('mousemove', function (e) {
            if (activeSeg === this) position(tip, e.clientX, e.clientY);
        });
        seg.addEventListener('mouseleave', function () {
            if (activeSeg === this) { activeSeg = null; hide(); }
        });
    });

    // ── Bar chart floating tooltip (cursor-follows) ──
    var bars = document.querySelectorAll('.dr-hour');
    var activeBar = null;
    function barShow(el, x, y) {
        var tipText = el.querySelector('.dr-hour-tip');
        tip.innerHTML = tipText ? tipText.textContent : '';
        tip.style.display = 'block';
        position(tip, x, y);
    }
    bars.forEach(function (bar) {
        bar.addEventListener('mouseenter', function (e) {
            activeBar = this;
            barShow(this, e.clientX, e.clientY);
        });
        bar.addEventListener('mousemove', function (e) {
            if (activeBar === this) position(tip, e.clientX, e.clientY);
        });
        bar.addEventListener('mouseleave', function () {
            if (activeBar === this) { activeBar = null; hide(); }
        });
    });
});

// follows shared theme key (toggled elsewhere)
window.addEventListener('storage', function (e) {
    if (e.key === 'theme') {
        if (e.newValue === 'light') document.documentElement.setAttribute('data-theme', 'light');
        else document.documentElement.removeAttribute('data-theme');
    }
});
</script>

</main>
</div>
</body>
</html>
