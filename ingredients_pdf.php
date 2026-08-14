<?php
require 'auth.php';
require 'config.php';
if (!can('ingredients')) { header('Location: dashboard.php?denied=1'); exit; }
require 'dompdf/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

date_default_timezone_set('Asia/Phnom_Penh');

/* ── DATE RANGE & FILTERS ── */
$today     = date('Y-m-d');
$default_f = date('Y-m-d', strtotime('-30 days'));
$date_from = trim($_GET['from_date'] ?? $_GET['from'] ?? $default_f);
$date_to   = trim($_GET['to_date']   ?? $_GET['to']   ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = $default_f;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = $today;
if ($date_from > $date_to) [$date_from, $date_to] = [$date_to, $date_from];

$user_by  = $_SESSION['username'] ?? 'Admin';
$gen_time = date('n/j/Y, g:i:s A');

/* ── MAIN REPORT QUERY ── */
$stmt = $conn->prepare("
    SELECT
        i.ingredient_id,
        i.ingredient_name,
        i.unit,
        i.cost_per_unit,
        i.cost_price,
        i.purchase_qty,
        i.stock_quantity,
        i.minimum_stock,
        COALESCE(SUM(CASE
            WHEN h.change_type = 'order_deduct' THEN h.amount
            WHEN h.change_type = 'manual_adjust' AND h.amount < 0 THEN ABS(h.amount)
            WHEN h.change_type = 'count_adjust' AND h.amount < 0 THEN ABS(h.amount)
            ELSE 0
        END), 0) AS total_consumed
    FROM ingredients i
    LEFT JOIN ingredient_history h
        ON h.ingredient_id = i.ingredient_id
        AND DATE(h.created_at) BETWEEN ? AND ?
    GROUP BY i.ingredient_id, i.ingredient_name, i.unit, i.cost_per_unit, i.cost_price, i.purchase_qty, i.stock_quantity, i.minimum_stock
    ORDER BY total_consumed DESC, i.ingredient_name ASC
");
$stmt->bind_param("ss", $date_from, $date_to);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$low_count    = 0;
$normal_count = 0;
$out_count    = 0;
$stk_val      = 0;

foreach ($rows as &$r) {
    $stock = (float)$r['stock_quantity'];
    $min   = (float)$r['minimum_stock'];
    $cpu   = (float)$r['cost_per_unit'];
    if ($cpu <= 0 && (float)($r['purchase_qty'] ?? 0) > 0) {
        $cpu = (float)$r['cost_price'] / (float)$r['purchase_qty'];
    }
    $r['cpu_calc'] = $cpu;
    $stk_val += ($stock * $cpu);

    if ($stock <= 0) {
        $r['status'] = 'out';
        $out_count++;
    } elseif ($stock <= $min) {
        $r['status'] = 'Low';
        $low_count++;
    } else {
        $r['status'] = 'ok';
        $normal_count++;
    }
}
unset($r);

function fmtNum($n) {
    return rtrim(rtrim(number_format((float)$n, 4, '.', ''), '0'), '.');
}

function formatUnitPrice($cpu, $unit) {
    $uLower = strtolower(trim($unit));
    if ($uLower === 'ml' && $cpu > 0) {
        $cLiter = $cpu * 1000;
        return '$' . number_format($cpu, 4) . '/ml($' . number_format($cLiter, 2) . '/L)';
    } elseif ($uLower === 'g' && $cpu > 0) {
        $cKg = $cpu * 1000;
        return '$' . number_format($cpu, 4) . '/g($' . number_format($cKg, 2) . '/kg)';
    } elseif ($cpu > 0) {
        return '$' . number_format($cpu, 2) . '/' . $unit;
    }
    return '$0.00';
}

$filter_stock = trim($_GET['stock_status'] ?? '');

// Apply stock filter
$filtered_rows = [];
foreach ($rows as $r) {
    $stk = (float)$r['stock_quantity'];
    $min = (float)$r['minimum_stock'];

    if ($filter_stock === 'low' && ($stk <= 0 || $stk > $min)) continue;
    if ($filter_stock === 'out' && $stk > 0) continue;
    $filtered_rows[] = $r;
}

$rows_html = '';
$idx = 1;
foreach ($filtered_rows as $r) {
    $statusCell = match($r['status']) {
        'Low' => '<td style="background-color:#ffb800;font-weight:bold;text-align:center;border:1px solid #000;">Low</td>',
        'out' => '<td style="background-color:#e74c3c;color:#fff;font-weight:bold;text-align:center;border:1px solid #000;">Out</td>',
        default => '<td style="text-align:center;border:1px solid #000;">ok</td>'
    };

    $rows_html .= '<tr>';
    $rows_html .= '<td style="text-align:center;border:1px solid #000;padding:6px;">'.$idx++.'</td>';
    $rows_html .= '<td style="text-align:left;border:1px solid #000;padding:6px;">'.htmlspecialchars($r['ingredient_name']).'</td>';
    $rows_html .= '<td style="text-align:center;border:1px solid #000;padding:6px;">'.htmlspecialchars($r['unit']).'</td>';
    $rows_html .= '<td style="text-align:center;border:1px solid #000;padding:6px;">'.formatUnitPrice($r['cpu_calc'], $r['unit']).'</td>';
    $rows_html .= '<td style="text-align:center;border:1px solid #000;padding:6px;">'.fmtNum($r['total_consumed']).'</td>';
    $rows_html .= '<td style="text-align:center;border:1px solid #000;padding:6px;">'.fmtNum($r['stock_quantity']).'</td>';
    $rows_html .= '<td style="text-align:center;border:1px solid #000;padding:6px;">'.fmtNum($r['minimum_stock']).'</td>';
    $rows_html .= $statusCell;
    $rows_html .= '</tr>';
}

$dateFromFmt = date('n/j/Y', strtotime($date_from));
$dateToFmt   = date('n/j/Y', strtotime($date_to));

$html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
@page { size: A4 portrait; margin: 15mm; }
body {
    font-family: "DejaVu Serif", "Times New Roman", Georgia, serif;
    font-size: 13px;
    color: #000;
    line-height: 1.45;
    margin: 0;
    padding: 0;
}
.header-title {
    font-size: 24px;
    font-weight: normal;
    margin-bottom: 6px;
}
.header-subtitle {
    font-size: 15px;
    margin-bottom: 12px;
}
.meta-line {
    font-size: 13.5px;
    margin-bottom: 5px;
}
.table-heading {
    font-size: 16px;
    font-weight: normal;
    text-transform: uppercase;
    margin-top: 26px;
    margin-bottom: 12px;
    letter-spacing: 0.5px;
}
table.report-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 25px;
}
table.report-table th {
    background-color: #a6a6a6;
    color: #000;
    font-weight: bold;
    font-size: 12px;
    padding: 6px 4px;
    border: 1px solid #000;
    text-align: center;
}
table.report-table td {
    padding: 6px 6px;
    border: 1px solid #000;
    font-size: 12px;
}
table.summary-table {
    margin-top: 25px;
    border-collapse: collapse;
    width: 320px;
}
table.summary-table td {
    border: 1px solid #000;
    padding: 6px 10px;
    font-size: 13px;
    background: #fff;
    color: #000;
}
table.summary-table td.lbl {
    width: 190px;
}
</style>
</head>
<body>

<div class="header-title">The Brid Nest Café</div>
<div class="header-subtitle">Report Ingredient</div>
<div class="meta-line">Period: '.$date_from.' to '.$date_to.'</div>
<div class="meta-line">Generated: '.$gen_time.'</div>
<div class="meta-line">Generated by: '.htmlspecialchars($user_by).'</div>

<div class="table-heading">INGREDIENT REPORT TABLE</div>

<table class="report-table">
    <thead>
        <tr>
            <th style="width:30px;">No</th>
            <th>Ingredient Name</th>
            <th style="width:35px;">Unit</th>
            <th style="width:160px;">Unit Price (Cost/kg, L)</th>
            <th style="width:70px;">Qty<br>Consumed</th>
            <th style="width:70px;">Current Stock</th>
            <th style="width:70px;">Min Stock<br>Alert</th>
            <th style="width:50px;">Status</th>
        </tr>
    </thead>
    <tbody>
        '.$rows_html.'
    </tbody>
</table>

<table class="summary-table">
    <tr>
        <td class="lbl">Product Low Stock</td>
        <td>'.$low_count.' <em>(item)</em></td>
    </tr>
    <tr>
        <td class="lbl">Product Normal Stock</td>
        <td>'.$normal_count.' <em>(item)</em></td>
    </tr>
    <tr>
        <td class="lbl">Inventory Value</td>
        <td>$'.number_format($stk_val, 2).'</td>
    </tr>
</table>

</body>
</html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Serif');
$options->set('isHtml5ParserEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('ingredient_report_'.date('Y-m-d').'.pdf', ['Attachment' => false]);
