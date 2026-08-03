<?php
/**
 * Printable sheet for ONE stock count — the physical count taken on a business date.
 *
 * Accepts ?date=YYYY-MM-DD (what stock_count.php and inventory_count.php link with)
 * or ?count_id=N. The date form is the one staff recognise, since a count IS a day.
 *
 * Styled to match ingredients_pdf.php exactly: same B&W A4 landscape template,
 * same header, signature row and footer, so a manager filing both sees one house
 * style rather than two.
 *
 * Value impact is the variance priced at the ingredient's cost — a count that is
 * 40 units short means nothing until it means money, and the manager signing this
 * off is approving a write-off, not a number.
 */
require 'auth.php';
require 'config.php';
if (!can('stock_count')) { header('Location: dashboard.php?denied=1'); exit; }
require 'dompdf/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

date_default_timezone_set('Asia/Phnom_Penh');

function fmt($n)   { return rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.'); }
function money($n) { return ($n < 0 ? '-$' : '$') . number_format(abs((float)$n), 2); }
function he($s)    { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ── Resolve which count ──────────────────────────────────────────────────────
$count_id = (int)($_GET['count_id'] ?? 0);
$date     = trim($_GET['date'] ?? '');

if ($count_id > 0) {
    $q = $conn->prepare("SELECT * FROM stock_counts WHERE count_id = ?");
    $q->bind_param('i', $count_id);
} else {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = date('Y-m-d'); }
    $q = $conn->prepare("SELECT * FROM stock_counts WHERE business_date = ? ORDER BY count_id DESC LIMIT 1");
    $q->bind_param('s', $date);
}
$q->execute();
$count = $q->get_result()->fetch_assoc();

if (!$count) {
    // A missing count is a normal thing to ask for — a manager clicking a date
    // nobody counted. Say so in plain language rather than rendering an empty sheet
    // that looks like everything reconciled perfectly.
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8">'
       . '<div style="font-family:system-ui,sans-serif;max-width:520px;margin:80px auto;text-align:center;color:#333;">'
       . '<h2 style="margin:0 0 8px;">No stock count found</h2>'
       . '<p style="color:#666;">There is no count recorded for '
       . he($count_id > 0 ? "count #$count_id" : $date) . '.</p>'
       . '<p><a href="inventory_count.php">Back to Inventory Counts</a></p></div>';
    exit;
}

$count_id  = (int)$count['count_id'];
$bdate     = $count['business_date'];
$is_draft  = ($count['status'] ?? 'draft') === 'draft';

// ── Lines, priced ────────────────────────────────────────────────────────────
// cost_per_unit can be 0 on ingredients bought as a pack, in which case the unit
// cost is derived the same way ingredients_pdf.php derives it. Without this the
// value impact silently reads $0.00 for exactly the bulk items most likely to
// drift.
$sql = "SELECT sci.*, i.ingredient_name, i.unit, i.cost_per_unit, i.cost_price, i.purchase_qty
        FROM stock_count_items sci
        JOIN ingredients i ON i.ingredient_id = sci.ingredient_id
        WHERE sci.count_id = ?
        ORDER BY i.ingredient_name ASC";
$st = $conn->prepare($sql);
$st->bind_param('i', $count_id);
$st->execute();
$res = $st->get_result();

$rows = [];
$cnt_match = $cnt_short = $cnt_over = 0;
$val_short = $val_over = 0.0;

while ($r = $res->fetch_assoc()) {
    $var = (float)$r['variance'];
    $cpu = (float)$r['cost_per_unit'];
    if ($cpu <= 0 && (float)($r['purchase_qty'] ?? 0) > 0) {
        $cpu = (float)$r['cost_price'] / (float)$r['purchase_qty'];
    }
    $value = $var * $cpu;

    if     (abs($var) < 0.0005) { $status = 'match'; $cnt_match++; }
    elseif ($var < 0)           { $status = 'short'; $cnt_short++; $val_short += $value; }
    else                        { $status = 'over';  $cnt_over++;  $val_over  += $value; }

    $rows[] = array_merge($r, ['var' => $var, 'cpu' => $cpu, 'value' => $value, 'status' => $status]);
}

$total     = count($rows);
$net_value = $val_short + $val_over;
$counted   = $cnt_match + $cnt_short + $cnt_over;
$pct_match = $counted > 0 ? round($cnt_match / $counted * 100) : 0;

$generated = date('d M Y, g:i A');
$reportId  = 'SC-' . date('Ymd', strtotime($bdate)) . '-' . $count_id;

$submitted = trim((string)($count['submitted_by'] ?? '')) !== ''
    ? he($count['submitted_by']) . ($count['submitted_at'] ? ' on ' . date('d M Y, g:i A', strtotime($count['submitted_at'])) : '')
    : 'Not submitted';
$reconciled = trim((string)($count['reconciled_by'] ?? '')) !== ''
    ? he($count['reconciled_by']) . ($count['reconciled_at'] ? ' on ' . date('d M Y, g:i A', strtotime($count['reconciled_at'])) : '')
    : 'Not reconciled';

// ── Discrepancy callout ──────────────────────────────────────────────────────
// Mirrors the "Procurement Required" block on the ingredient report: the exception
// belongs at the top, because a manager reads the top of a sheet and skims the rest.
$flag_html = '';
$off = array_values(array_filter($rows, fn($r) => $r['status'] !== 'match'));
if (count($off) > 0) {
    $flag_html .= '<div style="margin-bottom:12px;border:1px dashed #888;border-radius:2px;padding:8px 12px;">';
    $flag_html .= '<div style="font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Discrepancies &mdash; ' . count($off) . ' item(s) did not match</div>';
    $shorts = array_values(array_filter($rows, fn($r) => $r['status'] === 'short'));
    $overs  = array_values(array_filter($rows, fn($r) => $r['status'] === 'over'));
    if ($shorts) {
        $p = array_map(fn($s) => he($s['ingredient_name']) . ' (' . fmt($s['var']) . ' ' . he($s['unit']) . ', ' . money($s['value']) . ')', $shorts);
        $flag_html .= '<div style="font-size:9px;margin-bottom:3px;"><strong>Short (' . count($shorts) . '):</strong> ' . implode(', ', $p) . '</div>';
    }
    if ($overs) {
        $p = array_map(fn($o) => he($o['ingredient_name']) . ' (+' . fmt($o['var']) . ' ' . he($o['unit']) . ', ' . money($o['value']) . ')', $overs);
        $flag_html .= '<div style="font-size:9px;"><strong>Over (' . count($overs) . '):</strong> ' . implode(', ', $p) . '</div>';
    }
    $flag_html .= '</div>';
}

// ── Rows ─────────────────────────────────────────────────────────────────────
$rows_html = '';
$i = 1;
foreach ($rows as $r) {
    $label = match($r['status']) { 'match' => 'Match', 'short' => 'Short', 'over' => 'Over' };
    $badge = match($r['status']) {
        'match' => 'border:1px solid #bbb;color:#555;',
        'short' => 'border:2px solid #111;color:#111;font-weight:700;background:#ebebeb;',
        'over'  => 'border:1.5px solid #444;color:#111;font-weight:700;',
    };
    $varStyle = $r['status'] === 'match' ? 'color:#777;' : 'color:#111;font-weight:700;';
    $varText  = $r['var'] > 0 ? '+' . fmt($r['var']) : fmt($r['var']);
    $rowBg    = ($i % 2 === 0) ? '#f7f7f7' : '#ffffff';
    $unit     = $r['unit'] ? he($r['unit']) : '&mdash;';

    $rows_html .= '
    <tr style="background:' . $rowBg . ';">
        <td style="color:#aaa;font-size:9px;text-align:center;">' . $i . '</td>
        <td><strong style="color:#111;font-size:11px;">' . he($r['ingredient_name']) . '</strong></td>
        <td style="text-align:center;color:#555;font-size:10px;">' . $unit . '</td>
        <td style="text-align:right;color:#777;">' . fmt($r['opening_stock']) . '</td>
        <td style="text-align:right;color:#777;">' . fmt($r['system_used']) . '</td>
        <td style="text-align:right;color:#111;">' . fmt($r['expected_qty']) . '</td>
        <td style="text-align:right;color:#111;font-weight:700;">' . fmt($r['actual_qty']) . '</td>
        <td style="text-align:right;' . $varStyle . '">' . $varText . '</td>
        <td style="text-align:right;' . $varStyle . '">' . ($r['status'] === 'match' ? '&mdash;' : money($r['value'])) . '</td>
        <td style="text-align:center;">
            <span style="' . $badge . 'padding:2px 7px;border-radius:2px;font-size:8.5px;letter-spacing:.3px;">' . $label . '</span>
        </td>
    </tr>';
    $i++;
}
if ($total === 0) {
    $rows_html = '<tr><td colspan="10" style="text-align:center;color:#888;padding:18px;">No ingredients were counted on this sheet.</td></tr>';
}

$draftNote = $is_draft
    ? '<div style="margin-bottom:12px;border:2px solid #111;border-radius:2px;padding:8px 12px;font-size:9.5px;">'
      . '<strong>DRAFT &mdash; NOT YET SUBMITTED.</strong> These figures are still being entered and may change. '
      . 'Do not use this copy to approve a write-off.</div>'
    : '';

$html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
@page { size: A4 landscape; margin: 0; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #111; margin: 0; padding: 0; }
.page-body { padding: 12mm 14mm 14mm; }

.header { display: table; width: 100%; border-bottom: 2px solid #111; padding-bottom: 10px; margin-bottom: 12px; }
.header-left  { display: table-cell; vertical-align: top; }
.header-right { display: table-cell; vertical-align: top; text-align: right; }

.brand-name    { font-size: 20px; font-weight: 700; color: #111; text-transform: uppercase; letter-spacing: 1px; }
.brand-tagline { font-size: 9px; color: #666; letter-spacing: 1px; text-transform: uppercase; margin-top: 2px; }
.brand-contact { font-size: 8.5px; color: #888; margin-top: 3px; }

.report-title { font-size: 17px; font-weight: 700; color: #111; }
.report-id    { font-size: 9px; color: #444; font-weight: 600; margin-top: 3px; letter-spacing: .4px; }
.report-meta  { font-size: 9px; color: #777; margin-top: 2px; }

.summary-bar  { background: #f5f5f5; border: 1px solid #ddd; padding: 7px 12px; margin-bottom: 12px; font-size: 10px; color: #333; border-radius: 2px; }
.meta-bar     { font-size: 9px; color: #666; margin-bottom: 12px; }

table.main { width: 100%; border-collapse: collapse; font-size: 10.5px; }
table.main thead tr { background: #1a1a1a; }
table.main thead th { color: #fff; font-weight: 700; padding: 8px 10px; text-align: left; font-size: 8.5px; text-transform: uppercase; letter-spacing: .6px; white-space: nowrap; }
table.main thead th.right  { text-align: right; }
table.main thead th.center { text-align: center; }
table.main tbody td { padding: 7px 10px; border-bottom: 1px solid #eeeeee; vertical-align: middle; }
table.main tfoot td { padding: 8px 10px; border-top: 2px solid #111; font-size: 10px; background: #f5f5f5; color: #333; }

.sig-row  { display: table; width: 100%; margin-top: 18px; border-top: 1px solid #ccc; padding-top: 12px; }
.sig-cell { display: table-cell; text-align: center; }
.sig-line { border-top: 1px solid #888; width: 140px; margin: 0 auto; padding-top: 4px; font-size: 9px; color: #555; }
.sig-label { font-size: 8px; color: #888; margin-top: 2px; text-transform: uppercase; letter-spacing: .4px; }

.footer       { margin-top: 12px; border-top: 1px solid #ddd; padding-top: 6px; display: table; width: 100%; }
.footer-left  { display: table-cell; font-size: 8px; color: #888; vertical-align: middle; }
.footer-right { display: table-cell; text-align: right; font-size: 8px; color: #888; vertical-align: middle; }
</style>
</head>
<body>
<div class="page-body">

<div class="header">
    <div class="header-left">
        <div class="brand-name">Bird\'s Nest Coffee</div>
        <div class="brand-tagline">Specialty Coffee &amp; Beverages</div>
        <div class="brand-contact">2nd Floor, Chbar Ampov District, Phnom Penh, Cambodia &nbsp;|&nbsp; operations@birdsnestcoffee.com</div>
    </div>
    <div class="header-right">
        <div class="report-title">Stock Count Sheet</div>
        <div class="report-id">Report No: ' . he($reportId) . '</div>
        <div class="report-meta">Count Date: ' . he(date('d M Y', strtotime($bdate))) . ' &nbsp;|&nbsp; Generated: ' . $generated . '</div>
    </div>
</div>

' . $draftNote . '

<div class="summary-bar">
    <strong>Summary:</strong> &nbsp;&nbsp;
    Items Counted: <strong>' . $total . '</strong>
    &nbsp;&nbsp;&#124;&nbsp;&nbsp;
    Matched: <strong>' . $cnt_match . '</strong> (' . $pct_match . '%)
    &nbsp;&nbsp;&#124;&nbsp;&nbsp;
    Short: <strong>' . $cnt_short . '</strong>
    &nbsp;&nbsp;&#124;&nbsp;&nbsp;
    Over: <strong>' . $cnt_over . '</strong>
    &nbsp;&nbsp;&#124;&nbsp;&nbsp;
    Net Value Impact: <strong>' . money($net_value) . '</strong>
</div>

<div class="meta-bar">
    <strong>Status:</strong> ' . he(ucfirst($count['status'] ?? 'draft')) . '
    &nbsp;&nbsp;&#124;&nbsp;&nbsp; <strong>Submitted by:</strong> ' . $submitted . '
    &nbsp;&nbsp;&#124;&nbsp;&nbsp; <strong>Reconciled by:</strong> ' . $reconciled . '
</div>

' . $flag_html . '

<table class="main">
    <thead>
        <tr>
            <th class="center" style="width:26px">#</th>
            <th>Ingredient</th>
            <th class="center" style="width:42px">Unit</th>
            <th class="right" style="width:70px">Opening</th>
            <th class="right" style="width:66px">Used</th>
            <th class="right" style="width:72px">Expected</th>
            <th class="right" style="width:70px">Counted</th>
            <th class="right" style="width:66px">Variance</th>
            <th class="right" style="width:78px">Value Impact</th>
            <th class="center" style="width:72px">Status</th>
        </tr>
    </thead>
    <tbody>
        ' . $rows_html . '
    </tbody>
    <tfoot>
        <tr>
            <td colspan="6">' . $total . ' item(s) counted &mdash; ' . $cnt_match . ' matched &nbsp;/&nbsp; ' . $cnt_short . ' short &nbsp;/&nbsp; ' . $cnt_over . ' over</td>
            <td colspan="4" style="text-align:right;font-weight:700;">Net Value Impact: ' . money($net_value) . '</td>
        </tr>
    </tfoot>
</table>

<div class="sig-row">
    <div class="sig-cell">
        <div class="sig-line">' . (trim((string)($count['submitted_by'] ?? '')) !== '' ? he($count['submitted_by']) : '&nbsp;') . '</div>
        <div class="sig-label">Counted by / Date</div>
    </div>
    <div class="sig-cell">
        <div class="sig-line">' . (trim((string)($count['reconciled_by'] ?? '')) !== '' ? he($count['reconciled_by']) : '&nbsp;') . '</div>
        <div class="sig-label">Reconciled by / Date</div>
    </div>
    <div class="sig-cell">
        <div class="sig-line">&nbsp;</div>
        <div class="sig-label">Approved by / Date</div>
    </div>
</div>

<div class="footer">
    <div class="footer-left">CONFIDENTIAL &mdash; FOR INTERNAL USE ONLY &nbsp;|&nbsp; Bird\'s Nest Coffee &copy; ' . date('Y') . '</div>
    <div class="footer-right">' . he($reportId) . ' &nbsp;|&nbsp; Page 1 of 1</div>
</div>

</div>
</body>
</html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream('stock_count_' . $bdate . '.pdf', ['Attachment' => false]);
