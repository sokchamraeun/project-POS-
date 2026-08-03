<?php
/**
 * Printable cash drawer reconciliation — every count in the selected range.
 *
 * Takes the same three filters the screen uses (?from=&to=&user_id=) so the PDF
 * always matches what the manager was looking at when they clicked Export.
 *
 * DELIBERATELY NOT PAGINATED. The screen shows ten rows at a time because a
 * screen has to; a printed reconciliation that silently stopped at row ten would
 * be worse than no report, because the total at the bottom would not match the
 * rows above it.
 *
 * Styled to match ingredients_pdf.php and stock_count_pdf.php — one house style
 * across everything a manager files.
 */
require 'auth.php';
require 'config.php';
if (!can('cash_reconciliation')) { header('Location: dashboard.php?denied=1'); exit; }
require 'dompdf/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

date_default_timezone_set('Asia/Phnom_Penh');

function money($n) { return ((float)$n < 0 ? '-$' : '$') . number_format(abs((float)$n), 2); }
function he($s)    { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ── Filters, same shape as the screen ────────────────────────────────────────
$filter_user = (int)($_GET['user_id'] ?? 0);
$filter_from = trim($_GET['from'] ?? '');
$filter_to   = trim($_GET['to']   ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_from)) $filter_from = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_to))   $filter_to   = date('Y-m-d');

$where  = ['cr.shift_date BETWEEN ? AND ?'];
$params = [$filter_from, $filter_to];
$types  = 'ss';
if ($filter_user > 0) { $where[] = 'cr.user_id = ?'; $params[] = $filter_user; $types .= 'i'; }
$where_sql = implode(' AND ', $where);

// ── Summary over the whole range ─────────────────────────────────────────────
$ss = $conn->prepare("SELECT
    COUNT(*)                                                AS total,
    SUM(CASE WHEN ABS(difference) < 0.01 THEN 1 ELSE 0 END) AS matches,
    SUM(CASE WHEN difference >  0.005    THEN 1 ELSE 0 END) AS overs,
    SUM(CASE WHEN difference < -0.005    THEN 1 ELSE 0 END) AS shorts,
    SUM(CASE WHEN ABS(difference) >= 0.01 AND resolved_at IS NULL THEN 1 ELSE 0 END) AS unresolved,
    COALESCE(SUM(difference), 0)                            AS total_diff
    FROM cash_counts cr WHERE $where_sql");
$ss->bind_param($types, ...$params);
$ss->execute();
$st = $ss->get_result()->fetch_assoc();

$total      = (int)$st['total'];
$matches    = (int)$st['matches'];
$overs      = (int)$st['overs'];
$shorts     = (int)$st['shorts'];
$unresolved = (int)$st['unresolved'];
$total_diff = (float)$st['total_diff'];
$pct_match  = $total > 0 ? round($matches / $total * 100) : 0;

// ── Every row in range ───────────────────────────────────────────────────────
// Same nearest-session join the screen uses: a cashier can clock in more than
// once a day, so the shift end is the attendance row whose clock_in sits closest
// to this drawer count, not simply MAX(clock_out).
$q = $conn->prepare("SELECT cr.id, cr.username, cr.shift_date, cr.login_time,
               cr.expected_cash, cr.actual_cash, cr.difference,
               cr.resolved_at, cr.resolved_by, cr.resolution_note,
               (SELECT a.clock_out FROM attendance a
                 WHERE a.user_id = cr.user_id AND a.date = cr.shift_date
                   AND a.clock_out IS NOT NULL
                 ORDER BY ABS(TIMESTAMPDIFF(SECOND, a.clock_in, cr.login_time)) ASC
                 LIMIT 1) AS shift_end
        FROM cash_counts cr
        WHERE $where_sql
        ORDER BY cr.shift_date DESC, cr.recorded_at DESC");
$q->bind_param($types, ...$params);
$q->execute();
$rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Cashier name for the header, when filtered to one ────────────────────────
$who = 'All cashiers';
if ($filter_user > 0) {
    $u = $conn->prepare("SELECT username FROM cash_counts WHERE user_id = ? LIMIT 1");
    $u->bind_param('i', $filter_user);
    $u->execute();
    $ur = $u->get_result()->fetch_row();
    if ($ur) $who = $ur[0];
}

$generated = date('d M Y, g:i A');
$reportId  = 'CR-' . date('Ymd-Hi');

// ── Unresolved callout ───────────────────────────────────────────────────────
// The exception belongs at the top: a manager reads the head of a sheet and
// skims the rest, and an unresolved drawer difference is the only thing here
// that still needs someone to do something.
$flag_html = '';
$open = array_values(array_filter($rows, fn($r) => abs((float)$r['difference']) >= 0.01 && empty($r['resolved_at'])));
if ($open) {
    $parts = array_map(
        fn($r) => he($r['username']) . ' ' . date('d M', strtotime($r['shift_date'])) . ' (' . money($r['difference']) . ')',
        array_slice($open, 0, 12)
    );
    $more = count($open) > 12 ? ' &hellip; and ' . (count($open) - 12) . ' more' : '';
    $flag_html = '<div style="margin-bottom:12px;border:1px dashed #888;border-radius:2px;padding:8px 12px;">'
        . '<div style="font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Unresolved &mdash; ' . count($open) . ' drawer difference(s) still open</div>'
        . '<div style="font-size:9px;">' . implode(', ', $parts) . $more . '</div></div>';
}

// ── Rows ─────────────────────────────────────────────────────────────────────
$rows_html = '';
$i = 1;
foreach ($rows as $r) {
    $diff = (float)$r['difference'];
    if     (abs($diff) < 0.01) { $label = 'Match'; $badge = 'border:1px solid #bbb;color:#555;'; }
    elseif ($diff > 0)         { $label = 'Over';  $badge = 'border:1.5px solid #444;color:#111;font-weight:700;'; }
    else                       { $label = 'Short'; $badge = 'border:2px solid #111;color:#111;font-weight:700;background:#ebebeb;'; }

    $diffStyle = abs($diff) < 0.01 ? 'color:#777;' : 'color:#111;font-weight:700;';
    $diffText  = abs($diff) < 0.01 ? '&mdash;' : ($diff > 0 ? '+' . money($diff) : money($diff));

    $resolved = !empty($r['resolved_at'])
        ? he($r['resolved_by'] ?: 'Resolved')
        : (abs($diff) < 0.01 ? '&mdash;' : '<strong>Open</strong>');

    $shift = date('g:i A', strtotime($r['login_time']))
           . ' &ndash; ' . (!empty($r['shift_end']) ? date('g:i A', strtotime($r['shift_end'])) : 'n/a');

    $rowBg = ($i % 2 === 0) ? '#f7f7f7' : '#ffffff';
    $rows_html .= '
    <tr style="background:' . $rowBg . ';">
        <td style="color:#aaa;font-size:9px;text-align:center;">' . $i . '</td>
        <td style="font-size:10px;color:#333;">' . he(date('d M Y', strtotime($r['shift_date']))) . '</td>
        <td><strong style="color:#111;font-size:11px;">' . he($r['username']) . '</strong></td>
        <td style="font-size:9.5px;color:#666;">' . $shift . '</td>
        <td style="text-align:right;color:#555;">' . money($r['expected_cash']) . '</td>
        <td style="text-align:right;color:#111;font-weight:700;">' . money($r['actual_cash']) . '</td>
        <td style="text-align:right;' . $diffStyle . '">' . $diffText . '</td>
        <td style="text-align:center;">
            <span style="' . $badge . 'padding:2px 7px;border-radius:2px;font-size:8.5px;letter-spacing:.3px;">' . $label . '</span>
        </td>
        <td style="font-size:9.5px;color:#555;">' . $resolved . '</td>
    </tr>';
    $i++;
}
if ($total === 0) {
    $rows_html = '<tr><td colspan="9" style="text-align:center;color:#888;padding:18px;">No drawer counts were recorded in this period.</td></tr>';
}

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
        <div class="report-title">Cash Reconciliation Report</div>
        <div class="report-id">Report No: ' . he($reportId) . '</div>
        <div class="report-meta">Generated: ' . $generated . ' &nbsp;|&nbsp; Phnom Penh Time</div>
    </div>
</div>

<div class="summary-bar">
    <strong>Summary:</strong> &nbsp;&nbsp;
    Drawer Counts: <strong>' . $total . '</strong>
    &nbsp;&nbsp;&#124;&nbsp;&nbsp;
    Matched: <strong>' . $matches . '</strong> (' . $pct_match . '%)
    &nbsp;&nbsp;&#124;&nbsp;&nbsp;
    Short: <strong>' . $shorts . '</strong>
    &nbsp;&nbsp;&#124;&nbsp;&nbsp;
    Over: <strong>' . $overs . '</strong>
    &nbsp;&nbsp;&#124;&nbsp;&nbsp;
    Unresolved: <strong>' . $unresolved . '</strong>
    &nbsp;&nbsp;&#124;&nbsp;&nbsp;
    Net Difference: <strong>' . money($total_diff) . '</strong>
</div>

<div class="meta-bar">
    <strong>Period:</strong> ' . he(date('d M Y', strtotime($filter_from))) . ' &ndash; ' . he(date('d M Y', strtotime($filter_to))) . '
    &nbsp;&nbsp;&#124;&nbsp;&nbsp; <strong>Cashier:</strong> ' . he($who) . '
</div>

' . $flag_html . '

<table class="main">
    <thead>
        <tr>
            <th class="center" style="width:26px">#</th>
            <th style="width:78px">Date</th>
            <th>Cashier</th>
            <th style="width:120px">Shift</th>
            <th class="right" style="width:78px">Expected</th>
            <th class="right" style="width:78px">Counted</th>
            <th class="right" style="width:80px">Difference</th>
            <th class="center" style="width:70px">Status</th>
            <th style="width:110px">Resolved By</th>
        </tr>
    </thead>
    <tbody>
        ' . $rows_html . '
    </tbody>
    <tfoot>
        <tr>
            <td colspan="5">' . $total . ' drawer count(s) &mdash; ' . $matches . ' matched &nbsp;/&nbsp; ' . $shorts . ' short &nbsp;/&nbsp; ' . $overs . ' over &nbsp;/&nbsp; ' . $unresolved . ' unresolved</td>
            <td colspan="4" style="text-align:right;font-weight:700;">Net Difference: ' . money($total_diff) . '</td>
        </tr>
    </tfoot>
</table>

<div class="sig-row">
    <div class="sig-cell">
        <div class="sig-line">&nbsp;</div>
        <div class="sig-label">Prepared by / Date</div>
    </div>
    <div class="sig-cell">
        <div class="sig-line">&nbsp;</div>
        <div class="sig-label">Reviewed by / Date</div>
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
$dompdf->stream('cash_reconciliation_' . $filter_from . '_to_' . $filter_to . '.pdf', ['Attachment' => false]);
