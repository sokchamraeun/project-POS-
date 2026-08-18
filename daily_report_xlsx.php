<?php
/**
 * The day's report as a real Excel workbook.
 *
 * This replaced a CSV export. CSV opens in Excel but arrives as a wall of
 * undifferentiated text: no headings, no currency, and — the reason it was
 * worth changing — no charts. A manager who exports a report expects to hand
 * the file to someone, and a spreadsheet that has to be explained is not that.
 *
 * Every figure comes from the same shared helpers the screen and the PDF use:
 * paid_orders_where() for what counts as collected, order_cogs() for what the
 * drinks cost, weekday_baseline() for whether the day beat a normal one. Screen,
 * paper and spreadsheet cannot disagree, because none of them owns the numbers.
 *
 * Requires ext-zip (an .xlsx is a zip archive). Enabled in php.ini alongside
 * the PhpSpreadsheet install.
 */
require 'auth.php';
require 'config.php';
if (!can('report') && !can('report_sale') && !can('report_employee')) { header("Location: dashboard.php?denied=1"); exit; }
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title as ChartTitle;

date_default_timezone_set('Asia/Phnom_Penh');

// The report palette, shared with daily_report_pdf.php so the two exports of
// the same day look like they came from the same shop.
const XL_INK    = '16130F';   // near-black, header bands
const XL_AMBER  = 'D1904B';   // the one accent; totals and rules only
const XL_PAPER  = 'FAF9F7';   // banded row tint
const XL_MUTED  = '6B6259';
const XL_LINE   = 'E4E0DA';
const XL_WARN   = 'FFF4E0';   // "buy now" fill — amber means attention, never a verdict
const XL_WARNTX = '8A5A12';
const XL_OUT    = 'FBE4E4';   // out of stock
const XL_OUTTX  = '9C2B2B';

const MONEY_FMT = '"$"#,##0.00';

// ── Date validation ──
$today = business_date_today();
$date  = is_string($_GET['date'] ?? null) ? trim($_GET['date']) : '';
if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
    $date = $today;
}
if ($date > $today) { $date = $today; }

// ── Range mode ──
$dateFrom = is_string($_GET['date_from'] ?? null) ? trim($_GET['date_from']) : '';
$dateTo   = is_string($_GET['date_to']   ?? null) ? trim($_GET['date_to'])   : '';
$isRange  = false;
if ($dateFrom !== '' && $dateTo !== '') {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        if ($dateFrom <= $dateTo && $dateTo <= $today) { $isRange = true; }
    }
}
$_is_mgr = in_array($_SESSION['role'] ?? '', ['admin', 'manager']);
$filter_user = (int)($_GET['user_id'] ?? $_GET['user'] ?? 0);
if (!$_is_mgr) {
    $filter_user = (int)$_SESSION['user_id'];
}

$dateExpr = $isRange ? "DATE(order_date) BETWEEN '$dateFrom' AND '$dateTo'" : "DATE(order_date) = '$date'";
if ($filter_user > 0) {
    $dateExpr .= " AND user_id = $filter_user";
}

// ─────────────────────────────────────────────────────────────────────────────
// The period's figures
// ─────────────────────────────────────────────────────────────────────────────

$stmt = $conn->prepare("SELECT COALESCE(SUM(total),0), COUNT(*) FROM orders WHERE $dateExpr AND " . paid_orders_where());
$stmt->execute();
[$gotToday, $orderCount] = $stmt->get_result()->fetch_row();
$gotToday   = (float)$gotToday;
$orderCount = (int)$orderCount;

$ids  = [];
$stmt = $conn->prepare("SELECT order_id FROM orders WHERE $dateExpr AND " . paid_orders_where());
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_row()) { $ids[] = (int)$row[0]; }

$costMap   = ingredient_cost_map($conn);
$cogs      = order_cogs($conn, $ids, $costMap);
$itemsSold = cogs_cups($cogs);
$kept      = $gotToday - $cogs['total'];
$avgOrder  = $orderCount > 0 ? $gotToday / $orderCount : 0.0;

// Against a normal same-weekday trading day (single day only).
$base = $isRange ? ['value' => null, 'label' => '', 'dates' => [], 'basis' => 'none', 'days' => 0] : weekday_baseline($conn, $date);

/**
 * The comparison line that sits under each headline figure.
 *
 * A number with no baseline cannot tell a manager anything, which is the whole
 * reason this report exists. Before the first sale of the day every card would
 * otherwise read as a shortfall — "$14.65 less than a normal Tuesday" at 8am is
 * an alarm about a day that has not happened yet — so a day with nothing
 * collected reads neutral instead.
 */
function xl_delta(?float $now, ?float $base, string $label, bool $zeroDay): string {
    if ($zeroDay)                    { return 'no sales yet today'; }
    if ($now === null || $base === null) { return 'no comparison available yet'; }
    $diff = $now - $base;
    if (abs($diff) < 0.005)          { return 'the same as ' . $label; }
    // Dollars, never percentages: "$30.50 less" is a fact a manager can act on.
    return '$' . number_format(abs($diff), 2) . ($diff > 0 ? ' more' : ' less') . ' than ' . $label;
}

$zeroDay   = ($orderCount === 0);
$baseVal   = $base['value'];
$baseLabel = $base['label'];

// The money-kept baseline re-costs the baseline's own days rather than scaling
// the takings baseline. Scaling reduces to the takings difference times a
// constant, which would make this card a copy of the first one.
$baseKept = null;
if (!empty($base['dates'])) {
    $baseKeptTotal = 0.0;
    foreach ($base['dates'] as $bd) {
        $bIds  = [];
        $stmt = $conn->prepare("SELECT order_id, total FROM orders WHERE business_date = ? AND " . paid_orders_where());
        $stmt->bind_param("s", $bd);
        $stmt->execute();
        $r = $stmt->get_result();
        $bGot = 0.0;
        while ($row = $r->fetch_row()) { $bIds[] = (int)$row[0]; $bGot += (float)$row[1]; }
        $bCogs = order_cogs($conn, $bIds, $costMap);
        $baseKeptTotal += $bGot - $bCogs['total'];
    }
    $baseKept = $baseKeptTotal / count($base['dates']);
}

// ── Still owed ──
$notPaid      = 0.0;
$notPaidCount = 0;

// ── Low stock (single day only) ──
$low = [];
$staff = [];
$unlinkedMoney = 0;
$orders = [];
$cupsByOrder = [];
$giftCount = 0;
$topProducts = [];

// ── Top sellers ──
$byProduct = array_filter($cogs['by_product'], fn($p) => empty($p['is_gift']));
$giftCount = (int)$cogs['gift_items'];
uasort($byProduct, fn($a, $b) => $b['qty'] <=> $a['qty']);
$topProducts = array_slice($byProduct, 0, 8, true);

if (!$isRange) {
    // ── Who was on ──
    $stmt = $conn->prepare("
        SELECT COALESCE(NULLIF(u.name, ''), u.username) AS full_name,
               COUNT(o.order_id) AS orders_served,
               COALESCE(SUM(o.total), 0) AS money_taken
        FROM attendance a
        JOIN users u ON u.user_id = a.user_id
        LEFT JOIN orders o ON (o.user_id = u.user_id OR (o.user_id IS NULL AND LOWER(o.prepared_by) = LOWER(u.username))) AND DATE(o.order_date) = ?
        WHERE a.date = ?
        GROUP BY u.user_id, u.username
        ORDER BY money_taken DESC, u.username ASC
    ");
    $stmt->bind_param("ss", $date, $date);
    $stmt->execute();
    $staff = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $staffMoney = array_sum(array_map(fn($r) => (float)$r['money_taken'], $staff));
    $unlinkedMoney = $gotToday - $staffMoney;
}

$dex = $isRange ? "DATE(o.order_date) BETWEEN '$dateFrom' AND '$dateTo'" : "DATE(o.order_date) = '$date'";
if ($filter_user > 0) {
    $dex .= " AND o.user_id = $filter_user";
}

// ── The period's orders ──
$stmt = $conn->prepare("
    SELECT o.order_id, o.order_id AS daily_order_no, 'Guest' AS customer_name, o.total, o.payment_method,
           'Completed' AS status, 0 AS is_open, o.order_date, COALESCE(NULLIF(u.name, ''), u.username, o.prepared_by, 'Staff') AS cashier
    FROM orders o
    LEFT JOIN users u ON u.user_id = o.user_id
    WHERE $dex
    ORDER BY o.order_date ASC
");
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$cupsByOrder = [];
$stmt = $conn->prepare("
    SELECT oi.order_id, COALESCE(SUM(oi.quantity),0) AS cups
    FROM order_items oi
    JOIN orders o ON o.order_id = oi.order_id
    WHERE $dex AND oi.product_id <> 0
    GROUP BY oi.order_id
");
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) { $cupsByOrder[(int)$r['order_id']] = (int)$r['cups']; }

/**
 * How a single order was paid, in the words the report uses everywhere else.
 * Refunded is tested before the collected test on purpose: money that WAS
 * collected and then given back is not money that was never paid.
 */
function xl_pay_label(array $o): string {
    $m      = strtolower((string)$o['payment_method']);
    $bucket = in_array($m, ['cash', 'bakong', 'paylater'], true) ? $m : 'other';
    if ($o['status'] === 'Refunded') { return 'money given back'; }
    $collected = ((int)$o['is_open'] === 0)
        && !in_array($o['status'], ['PendingPayment', 'Cancelled', 'Refunded', 'Void'], true);
    if (!$collected) { return 'not paid yet'; }
    return match ($bucket) {
        'paylater' => 'pay later - paid',
        'bakong'   => 'Bakong (KHQR)',
        'cash'     => 'Cash',
        default    => 'other way',
    };
}

// ── How it was paid, for the pie ──
// Anything that is not cash/bakong/paylater is a legacy row: payment_method
// holds 195 rows of '0' and 4 of 'riel'. The remainder is DERIVED rather than
// enumerated, so the slices always sum to the headline figure — enumerating
// them silently dropped about $430.
$stmt = $conn->prepare("
    SELECT payment_method, COALESCE(SUM(total),0) AS amount
    FROM orders WHERE $dateExpr AND " . paid_orders_where() . " GROUP BY payment_method
");
$stmt->execute();
$rawMethods = [];
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) { $rawMethods[strtolower((string)$r['payment_method'])] = (float)$r['amount']; }

$namedTotal = ($rawMethods['cash'] ?? 0) + ($rawMethods['bakong'] ?? 0) + ($rawMethods['paylater'] ?? 0);
$methods = [
    'Cash'                => $rawMethods['cash']     ?? 0.0,
    'Bakong (KHQR)'       => $rawMethods['bakong']   ?? 0.0,
    'Pay later, settled'  => $rawMethods['paylater'] ?? 0.0,
    'Riel (KHR)'          => ($rawMethods['riel'] ?? 0.0) + max(0, $gotToday - $namedTotal - ($rawMethods['riel'] ?? 0.0)),
];
$methods = array_filter($methods, fn($v) => $v > 0.005);

// ── Daily trend ──
// Single day: last 7 trading days. Range: every day in the range.
$trend = [];
if ($isRange) {
    $d = new DateTime($dateFrom);
    $end = new DateTime($dateTo);
    while ($d <= $end) { $trend[$d->format('Y-m-d')] = 0.0; $d->modify('+1 day'); }
    $stmt = $conn->prepare("
        SELECT DATE(order_date) AS b_date, COALESCE(SUM(total),0) rev
        FROM orders
        WHERE DATE(order_date) BETWEEN ? AND ? AND " . paid_orders_where() . "
        GROUP BY DATE(order_date)
    ");
    $stmt->bind_param("ss", $dateFrom, $dateTo);
} else {
    for ($i = 6; $i >= 0; $i--) { $trend[date('Y-m-d', strtotime("$date -$i day"))] = 0.0; }
    $from = array_key_first($trend);
    $stmt = $conn->prepare("
        SELECT DATE(order_date) AS b_date, COALESCE(SUM(total),0) rev
        FROM orders
        WHERE DATE(order_date) BETWEEN ? AND ? AND " . paid_orders_where() . "
        GROUP BY DATE(order_date)
    ");
    $stmt->bind_param("ss", $from, $date);
}
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) { $trend[(string)$r['b_date']] = (float)$r['rev']; }

// ─────────────────────────────────────────────────────────────────────────────
// The workbook
// ─────────────────────────────────────────────────────────────────────────────

$book  = new Spreadsheet();
$sheet = $book->getActiveSheet();
$sheet->setTitle('Daily Report');
$sheetRef = "'Daily Report'";

$book->getProperties()
     ->setCreator("The Bird's Nest Coffee")
     ->setTitle(($isRange ? 'Sales report ' . $dateFrom : 'Daily report ' . $date))
     ->setSubject($isRange ? "Period $dateFrom to $dateTo" : 'Trading day ' . $date);

/** Paint a block of cells as a section heading band. */
function xl_band($sheet, string $range, string $bg, string $fg, int $size = 10, bool $bold = true): void {
    $sheet->getStyle($range)->applyFromArray([
        'font' => ['bold' => $bold, 'size' => $size, 'color' => ['rgb' => $fg]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
}

/** Thin rule under a row of data cells. */
function xl_hairline($sheet, string $range): void {
    $sheet->getStyle($range)->applyFromArray([
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => XL_LINE]]],
    ]);
}

foreach (['A' => 26, 'B' => 14, 'C' => 15, 'D' => 3, 'E' => 24, 'F' => 10,
          'G' => 13, 'H' => 3, 'I' => 22, 'J' => 11, 'K' => 14] as $col => $w) {
    $sheet->getColumnDimension($col)->setWidth($w);
}

// ── Title band ──
$sheet->mergeCells('A1:K1');
$sheet->setCellValue('A1', $isRange
    ? "THE BIRD'S NEST COFFEE  —  SALES REPORT (RANGE)"
    : "THE BIRD'S NEST COFFEE  —  DAILY REPORT");
xl_band($sheet, 'A1:K1', XL_INK, 'FFFFFF', 16);
$sheet->getRowDimension(1)->setRowHeight(34);
$sheet->getStyle('A1')->getAlignment()->setIndent(1);

$sheet->mergeCells('A2:K2');
$sheet->setCellValue('A2', $isRange
    ? sprintf('Period: %s to %s     |     Generated: %s', $dateFrom, $dateTo, date('j/n/Y, g:i A'))
    : sprintf('Trading day: %s  (06:00 to 05:00 next morning)     |     Generated: %s',
        date('l, j F Y', strtotime($date)),
        date('j/n/Y, g:i A')
    ));
xl_band($sheet, 'A2:K2', XL_AMBER, '1A1207', 10, false);
$sheet->getRowDimension(2)->setRowHeight(20);
$sheet->getStyle('A2')->getAlignment()->setIndent(1);

// ── Headline figures ──
// Five cards, each carrying its own comparison on the third line. Only these
// comparison lines are ever coloured; the cards themselves stay neutral,
// because colour that does not imply an action is decoration.
$kpis = [
    ['A', 'B', 'Total Sales',  $gotToday,  MONEY_FMT, xl_delta($gotToday, $baseVal, $baseLabel, $zeroDay),
        $baseVal === null ? 0 : $gotToday <=> $baseVal],
    ['C', 'D', 'Total Orders', $orderCount, '0',      $zeroDay ? 'no sales yet today' : $orderCount . ' paid ' . ($orderCount === 1 ? 'order' : 'orders'), 0],
    ['E', 'F', 'Cups Sold',    $itemsSold,  '0',      $zeroDay ? 'no sales yet today' : $itemsSold . ' items across the day', 0],
    ['G', 'H', 'Money Kept',   $kept,       MONEY_FMT, xl_delta($kept, $baseKept, $baseLabel, $zeroDay),
        $baseKept === null ? 0 : $kept <=> $baseKept],
    ['I', 'K', 'Avg per Order', $avgOrder,  MONEY_FMT, $zeroDay ? 'no sales yet today' : 'across ' . $orderCount . ' paid ' . ($orderCount === 1 ? 'order' : 'orders'), 0],
];
foreach ($kpis as [$c1, $c2, $label, $value, $fmt, $sub, $dir]) {
    $sheet->mergeCells("{$c1}4:{$c2}4");
    $sheet->mergeCells("{$c1}5:{$c2}5");
    $sheet->mergeCells("{$c1}6:{$c2}6");
    $sheet->setCellValue("{$c1}4", strtoupper($label));
    $sheet->setCellValue("{$c1}5", $value);
    $sheet->setCellValue("{$c1}6", $sub);
    $sheet->getStyle("{$c1}5")->getNumberFormat()->setFormatCode($fmt);
    $sheet->getStyle("{$c1}4:{$c2}6")->applyFromArray([
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']],
        'borders'   => ['outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => XL_LINE]]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getStyle("{$c1}4")->getFont()->setSize(8)->setBold(true)->getColor()->setRGB(XL_MUTED);
    $sheet->getStyle("{$c1}5")->getFont()->setSize(18)->setBold(true)->getColor()->setRGB(XL_INK);
    $sheet->getStyle("{$c1}6")->getFont()->setSize(8.5)
          ->getColor()->setRGB($dir > 0 ? '1F7A3D' : ($dir < 0 ? '9C2B2B' : XL_MUTED));
}
$sheet->getRowDimension(4)->setRowHeight(16);
$sheet->getRowDimension(5)->setRowHeight(30);
$sheet->getRowDimension(6)->setRowHeight(16);

// A tab still open is money the shop has not got. It sits beside the headline
// figures rather than inside them — folding open pay-later tabs into a total is
// the exact confusion that has cost this codebase four money bugs.
if ($notPaid > 0) {
    $sheet->mergeCells('A7:K7');
    $sheet->setCellValue('A7', sprintf(
        'Not paid yet: $%s across %d order%s — counted only once the customer pays.',
        number_format($notPaid, 2), $notPaidCount, $notPaidCount === 1 ? '' : 's'
    ));
    xl_band($sheet, 'A7:K7', XL_WARN, XL_WARNTX, 9, false);
    $sheet->getStyle('A7')->getAlignment()->setIndent(1);
}

// ── Three side-by-side panels ──
$r = 9;
foreach ([['A', 'Low Stock Items'], ['E', 'Top Selling Drinks'], ['I', 'Who Was On Shift']] as [$c, $heading]) {
    $sheet->setCellValue("{$c}{$r}", $heading);
    $sheet->getStyle("{$c}{$r}")->getFont()->setBold(true)->setSize(11)->getColor()->setRGB(XL_INK);
}
$r++;

$heads = [
    'A' => ['Item Name', 'Current Stock', 'Status'],
    'E' => ['Drink', 'Cups', 'Sales'],
    'I' => ['Name', 'Orders', 'Money Taken'],
];
foreach ($heads as $c => $labels) {
    $cols = [$c, chr(ord($c) + 1), chr(ord($c) + 2)];
    foreach ($labels as $i => $label) { $sheet->setCellValue($cols[$i] . $r, $label); }
    xl_band($sheet, "{$cols[0]}{$r}:{$cols[2]}{$r}", XL_INK, 'FFFFFF', 9);
    $sheet->getStyle("{$cols[1]}{$r}:{$cols[2]}{$r}")->getAlignment()
          ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
}
$headRow  = $r;
$panelTop = $r + 1;

// Low stock. Amber is "buy this before service tomorrow"; red is "already out".
// Nothing else on the sheet is coloured, so both actually mean something.
$rr = $panelTop;
foreach (array_slice($low, 0, 8) as $item) {
    $out = (float)$item['stock_quantity'] <= 0;
    $sheet->setCellValue("A$rr", $item['ingredient_name']);
    $sheet->setCellValue("B$rr", rtrim(rtrim(number_format((float)$item['stock_quantity'], 2, '.', ''), '0'), '.') . ' ' . $item['unit']);
    $sheet->setCellValue("C$rr", $out ? 'Out of Stock' : 'Buy now');
    $sheet->getStyle("B$rr:C$rr")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle("C$rr")->applyFromArray([
        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => $out ? XL_OUTTX : XL_WARNTX]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $out ? XL_OUT : XL_WARN]],
    ]);
    xl_hairline($sheet, "A$rr:C$rr");
    $rr++;
}
if (!$low) {
    $sheet->setCellValue("A$panelTop", 'Nothing needs buying.');
    $sheet->getStyle("A$panelTop")->getFont()->setItalic(true)->getColor()->setRGB(XL_MUTED);
    $rr = $panelTop + 1;
}
$lowEnd = $rr;

// Top sellers.
$rr = $panelTop;
foreach ($topProducts as $name => $p) {
    $sheet->setCellValue("E$rr", $name);
    $sheet->setCellValue("F$rr", (int)$p['qty']);
    $sheet->setCellValue("G$rr", (float)$p['revenue']);
    $sheet->getStyle("G$rr")->getNumberFormat()->setFormatCode(MONEY_FMT);
    $sheet->getStyle("F$rr:G$rr")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    xl_hairline($sheet, "E$rr:G$rr");
    $rr++;
}
if (!$topProducts) {
    $sheet->setCellValue("E$panelTop", 'Nothing sold on this day.');
    $sheet->getStyle("E$panelTop")->getFont()->setItalic(true)->getColor()->setRGB(XL_MUTED);
    $rr = $panelTop + 1;
}
$topEnd = $rr;

// Staff.
$rr = $panelTop;
foreach (array_slice($staff, 0, 8) as $s) {
    $sheet->setCellValue("I$rr", $s['full_name']);
    $sheet->setCellValue("J$rr", (int)$s['orders_served']);
    $sheet->setCellValue("K$rr", (float)$s['money_taken']);
    $sheet->getStyle("K$rr")->getNumberFormat()->setFormatCode(MONEY_FMT);
    $sheet->getStyle("J$rr:K$rr")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    xl_hairline($sheet, "I$rr:K$rr");
    $rr++;
}
if (!$staff) {
    $sheet->setCellValue("I$panelTop", 'No one was clocked in.');
    $sheet->getStyle("I$panelTop")->getFont()->setItalic(true)->getColor()->setRGB(XL_MUTED);
    $rr = $panelTop + 1;
}
$staffEnd = $rr;

$r = max($lowEnd, $topEnd, $staffEnd);

// Orders are attributed at creation, and almost none of the existing rows carry
// an employee. A footnote is honest; a table of blanks looks broken.
if ($unlinkedMoney > 0.005) {
    $sheet->mergeCells("I{$r}:K{$r}");
    $sheet->setCellValue("I$r", '$' . number_format($unlinkedMoney, 2) . ' is not linked to anyone clocked in.');
    $sheet->getStyle("I$r")->getFont()->setSize(8)->setItalic(true)->getColor()->setRGB(XL_MUTED);
    $r++;
}
if ($giftCount > 0) {
    $sheet->mergeCells("E{$r}:G{$r}");
    $sheet->setCellValue("E$r", 'Excludes ' . $giftCount . ' item' . ($giftCount === 1 ? '' : 's') . ' given away for loyalty points.');
    $sheet->getStyle("E$r")->getFont()->setSize(8)->setItalic(true)->getColor()->setRGB(XL_MUTED);
    $r++;
}
$r += 2;

// ── Every order in the period ──
$sheet->setCellValue("A$r", $isRange ? "Orders ($dateFrom to $dateTo)" : 'Orders (this trading day)');
$sheet->getStyle("A$r")->getFont()->setBold(true)->setSize(11)->getColor()->setRGB(XL_INK);
$r++;

foreach (array_combine(
    ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'],
    ['Order', 'Time', 'Cashier', 'Customer', 'Payment', 'Items', 'Total', 'Status']
) as $c => $label) {
    $sheet->setCellValue("{$c}{$r}", $label);
}
xl_band($sheet, "A$r:H$r", XL_INK, 'FFFFFF', 9);
$sheet->getStyle("F$r:G$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$r++;

$ordersTop = $r;
foreach ($orders as $i => $o) {
    $payLabel = xl_pay_label($o);
    $open     = ($payLabel === 'not paid yet');
    // daily_order_no is what the shop calls an order; order_id is an internal
    // key and printing it confuses anyone holding a receipt.
    $sheet->setCellValue("A$r", '#' . str_pad((string)(int)$o['daily_order_no'], 4, '0', STR_PAD_LEFT));
    $sheet->setCellValue("B$r", date('g:i A', strtotime((string)$o['order_date'])));
    $sheet->setCellValue("C$r", $o['cashier'] ?? 'not recorded');
    $sheet->setCellValue("D$r", trim((string)$o['customer_name']) !== '' ? $o['customer_name'] : 'Walk-in');
    $sheet->setCellValue("E$r", $payLabel);
    $sheet->setCellValue("F$r", $cupsByOrder[(int)$o['order_id']] ?? 0);
    $sheet->setCellValue("G$r", (float)$o['total']);
    $sheet->setCellValue("H$r", $open ? 'Open tab' : 'Paid');
    $sheet->getStyle("G$r")->getNumberFormat()->setFormatCode(MONEY_FMT);
    $sheet->getStyle("F$r:G$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle("H$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    if ($open) {
        $sheet->getStyle("H$r")->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => XL_WARNTX]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => XL_WARN]],
        ]);
    } elseif ($i % 2 === 1) {
        $sheet->getStyle("A$r:H$r")->getFill()
              ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(XL_PAPER);
    }
    xl_hairline($sheet, "A$r:H$r");
    $r++;
}
if (!$orders) {
    $sheet->mergeCells("A$r:H$r");
    $sheet->setCellValue("A$r", $isRange ? 'No orders recorded for this period.' : 'No orders recorded for this day.');
    $sheet->getStyle("A$r")->getFont()->setItalic(true)->getColor()->setRGB(XL_MUTED);
    $sheet->getStyle("A$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $r++;
} else {
    // The total is collected money only, on the same test tab 1 uses.
    $sheet->setCellValue("A$r", 'COLLECTED');
    $sheet->setCellValue("F$r", $itemsSold);
    $sheet->setCellValue("G$r", $gotToday);
    $sheet->getStyle("G$r")->getNumberFormat()->setFormatCode(MONEY_FMT);
    $sheet->getStyle("F$r:G$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    xl_band($sheet, "A$r:H$r", XL_AMBER, '1A1207', 10);
    $r++;
}
$r += 2;

// ── Chart source ──
// Kept visible on purpose: a chart whose numbers cannot be checked is a picture.
$sheet->setCellValue("A$r", 'Chart Source Data');
$sheet->getStyle("A$r")->getFont()->setBold(true)->setSize(11)->getColor()->setRGB(XL_INK);
$srcHead = $r + 1;
$sheet->setCellValue("A$srcHead", 'Date');
$sheet->setCellValue("B$srcHead", 'Sales');
$sheet->setCellValue("E$srcHead", 'Method');
$sheet->setCellValue("F$srcHead", 'Amount');
xl_band($sheet, "A$srcHead:B$srcHead", XL_INK, 'FFFFFF', 9);
xl_band($sheet, "E$srcHead:F$srcHead", XL_INK, 'FFFFFF', 9);

$trendTop = $srcHead + 1;
$rr = $trendTop;
foreach ($trend as $d => $v) {
    $sheet->setCellValueExplicit("A$rr", date('D j M', strtotime($d)), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue("B$rr", $v);
    $sheet->getStyle("B$rr")->getNumberFormat()->setFormatCode(MONEY_FMT);
    xl_hairline($sheet, "A$rr:B$rr");
    $rr++;
}
$trendEnd = $rr - 1;

$methTop = $trendTop;
$rr = $methTop;
foreach ($methods as $name => $amount) {
    $sheet->setCellValue("E$rr", $name);
    $sheet->setCellValue("F$rr", $amount);
    $sheet->getStyle("F$rr")->getNumberFormat()->setFormatCode(MONEY_FMT);
    xl_hairline($sheet, "E$rr:F$rr");
    $rr++;
}
$methEnd  = $rr - 1;
$chartTop = max($trendEnd, $methEnd) + 2;

// ── The two charts ──
$trendCount = count($trend);
$series = new DataSeries(
    DataSeries::TYPE_LINECHART,
    DataSeries::GROUPING_STANDARD,
    [0],
    [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "{$sheetRef}!\$B\${$srcHead}", null, 1)],
    [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "{$sheetRef}!\$A\${$trendTop}:\$A\${$trendEnd}", null, $trendCount)],
    [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "{$sheetRef}!\$B\${$trendTop}:\$B\${$trendEnd}", null, $trendCount)]
);
$series->setPlotDirection(DataSeries::DIRECTION_COL);

$lineChart = new Chart(
    'salesTrend',
    new ChartTitle('Sales, last 7 trading days'),
    null,                                   // one series needs no legend
    new PlotArea(null, [$series]),
    true,
    DataSeries::EMPTY_AS_GAP
);
$lineChart->setTopLeftPosition('A' . $chartTop);
$lineChart->setBottomRightPosition('F' . ($chartTop + 16));
$sheet->addChart($lineChart);

if ($methods) {
    $pieValues = new DataSeriesValues(
        DataSeriesValues::DATASERIES_TYPE_NUMBER,
        "{$sheetRef}!\$F\${$methTop}:\$F\${$methEnd}", null, count($methods)
    );
    // Shades of the report's own two colours, so the slices read as one family.
    $pieValues->setFillColor(array_slice(['16130F', 'D1904B', '8C7A63', 'C9C3BA'], 0, count($methods)));

    $pieSeries = new DataSeries(
        DataSeries::TYPE_PIECHART,
        null,
        [0],
        [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "{$sheetRef}!\$F\${$srcHead}", null, 1)],
        [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "{$sheetRef}!\$E\${$methTop}:\$E\${$methEnd}", null, count($methods))],
        [$pieValues]
    );
    $pieSeries->setPlotDirection(DataSeries::DIRECTION_COL);

    $pieChart = new Chart(
        'paymentMethods',
        new ChartTitle('How it was paid'),
        new Legend(Legend::POSITION_RIGHT, null, false),
        new PlotArea(null, [$pieSeries]),
        true,
        DataSeries::EMPTY_AS_GAP
    );
    $pieChart->setTopLeftPosition('G' . $chartTop);
    $pieChart->setBottomRightPosition('K' . ($chartTop + 16));
    $sheet->addChart($pieChart);
}

// ── Footnote ──
$foot = $chartTop + 18;
$sheet->mergeCells("A$foot:K$foot");
$sheet->setCellValue("A$foot",
    'Sales count money actually collected: an order is included once it is paid, so an unsettled pay-later '
  . 'tab appears under "not paid yet" rather than in sales. The ingredient costs behind "money kept" are '
  . 'documented estimates at market wholesale rates, not supplier invoices.');
$sheet->getStyle("A$foot")->getFont()->setSize(8)->getColor()->setRGB(XL_MUTED);
$sheet->getStyle("A$foot")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
$sheet->getRowDimension($foot)->setRowHeight(26);

$sheet->setSelectedCells('A1');
$sheet->freezePane('A3');

// ── Send it ──
// Any stray output ahead of this would land inside the zip and corrupt the file.
if (ob_get_length()) { ob_end_clean(); }
$filename = $isRange ? 'sales-report-' . $dateFrom . '-' . $dateTo . '.xlsx' : 'daily-report-' . $date . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($book);
$writer->setIncludeCharts(true);   // without this the charts are silently dropped
$writer->save('php://output');
exit;
