<?php
/**
 * Printable Employee Sales Report (Shift Audit PDF Export).
 * Supports Khmer and English language modes.
 */
require 'auth.php';
require 'config.php';
require_once 'lang.php';
require_once 'dompdf/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (!can('report') && !can('report_employee') && !can('report_sale')) {
    header("Location: dashboard.php?denied=1");
    exit;
}

date_default_timezone_set('Asia/Phnom_Penh');

function he($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// ── Language Detection ──
$lang = is_string($_GET['lang'] ?? null) ? trim($_GET['lang']) : current_lang();
$isKm = ($lang === 'km');

// ── Date & User Filter validation ──
$today       = business_date_today();
$filter_from = trim($_GET['date_from'] ?? $_GET['from_date'] ?? $today);
$filter_to   = trim($_GET['date_to']   ?? $_GET['to_date']   ?? $today);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_from)) $filter_from = $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_to))   $filter_to   = $today;
if ($filter_from > $filter_to) [$filter_from, $filter_to] = [$filter_to, $filter_from];

$_is_mgr = in_array($_SESSION['role'] ?? '', ['admin', 'manager']);
$filter_user = (int)($_GET['user_id'] ?? 0);
if (!$_is_mgr) {
    $filter_user = (int)$_SESSION['user_id'];
}

$where_user = $filter_user > 0 ? "WHERE u.user_id = $filter_user" : "";

$sql_staff = "SELECT 
                u.user_id,
                COALESCE(NULLIF(u.name, ''), u.username) AS username,
                u.role,
                COUNT(o.order_id) AS total_orders,
                COALESCE(SUM(o.total), 0) AS total_sales
              FROM users u
              LEFT JOIN orders o ON (o.user_id = u.user_id OR (o.user_id IS NULL AND LOWER(o.prepared_by) = LOWER(u.username))) AND DATE(o.order_date) BETWEEN '$filter_from' AND '$filter_to'
              $where_user
              GROUP BY u.user_id, u.username, u.name, u.role
              ORDER BY u.username ASC";

$res_staff  = $conn->query($sql_staff);
$staff_rows = $res_staff ? $res_staff->fetch_all(MYSQLI_ASSOC) : [];

$grand_total_orders = 0;
$grand_total_sales  = 0.0;
foreach ($staff_rows as $sr) {
    $grand_total_orders += (int)$sr['total_orders'];
    $grand_total_sales  += (float)$sr['total_sales'];
}

$_gen_name = $_SESSION['emp_name'] ?? $_SESSION['username'] ?? 'Root';
$gen_by_str = $_gen_name;

// ── HTML Template Construction ──
ob_start();
?>
<!DOCTYPE html>
<html lang="<?= $isKm ? 'km' : 'en' ?>">
<head>
<meta charset="UTF-8">
<title><?= $isKm ? 'របាយការណ៍បុគ្គលិក' : 'Employee Report' ?></title>
<style>
    body {
        font-family: 'Helvetica', 'Arial', sans-serif;
        font-size: 12px;
        color: #111827;
        margin: 0;
        padding: 20px;
    }
    .header-bar {
        border-bottom: 2px solid #d1904b;
        padding-bottom: 12px;
        margin-bottom: 20px;
    }
    .title {
        font-size: 22px;
        font-weight: bold;
        color: #d1904b;
        margin: 0 0 4px 0;
    }
    .subtitle {
        font-size: 14px;
        font-weight: 600;
        color: #374151;
        margin: 0 0 8px 0;
    }
    .meta-text {
        font-size: 11px;
        color: #6b7280;
        line-height: 1.5;
    }
    table.report-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
        margin-bottom: 20px;
    }
    table.report-table th {
        background-color: #f3f4f6;
        color: #374151;
        font-weight: bold;
        text-transform: uppercase;
        font-size: 10px;
        letter-spacing: 0.05em;
        padding: 10px 12px;
        border: 1px solid #e5e7eb;
        text-align: left;
    }
    table.report-table th.center, table.report-table td.center {
        text-align: center;
    }
    table.report-table th.right, table.report-table td.right {
        text-align: right;
    }
    table.report-table td {
        padding: 10px 12px;
        border: 1px solid #e5e7eb;
        font-size: 12px;
    }
    table.report-table tr:nth-child(even) td {
        background-color: #f9fafb;
    }
    .summary-card {
        background-color: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 14px 18px;
        width: 260px;
        margin-left: auto;
    }
    .summary-row {
        display: flex;
        justify-content: space-between;
        font-size: 12px;
        margin-bottom: 6px;
    }
    .summary-row.total {
        font-weight: bold;
        font-size: 14px;
        color: #d1904b;
        border-top: 1px solid #cbd5e1;
        padding-top: 6px;
        margin-bottom: 0;
    }
</style>
</head>
<body>

<div class="header-bar">
    <div class="title">The Bird Nest Café</div>
    <div class="subtitle"><?= $isKm ? 'របាយការណ៍ការងារបុគ្គលិក / Shift Audit Report' : 'Shift Audit / Employee Sales Report' ?></div>
    <div class="meta-text">
        <strong><?= $isKm ? 'រយៈពេល:' : 'Period:' ?></strong> <?= he($filter_from) ?> <?= $isKm ? 'ដល់' : 'to' ?> <?= he($filter_to) ?><br>
        <strong><?= $isKm ? 'បង្កើតនៅ:' : 'Generated:' ?></strong> <?= date('Y-m-d H:i:s') ?><br>
        <strong><?= $isKm ? 'បង្កើតដោយ:' : 'Generated by:' ?></strong> <?= he($gen_by_str) ?>
    </div>
</div>

<table class="report-table">
    <thead>
        <tr>
            <th style="width: 45%;"><?= $isKm ? 'ឈ្មោះបុគ្គលិក' : 'Employee Name' ?></th>
            <th class="center" style="width: 25%;"><?= $isKm ? 'ចំនួន Order សរុប' : 'Total Orders' ?></th>
            <th class="right" style="width: 30%;"><?= $isKm ? 'ចំណូលសរុប' : 'Billed Revenue' ?></th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($staff_rows)): ?>
        <tr>
            <td colspan="3" class="center" style="color: #9ca3af; padding: 24px;"><?= $isKm ? 'គ្មានទិន្នន័យ' : 'No employee data found.' ?></td>
        </tr>
        <?php else: ?>
        <?php foreach ($staff_rows as $sr): ?>
        <tr>
            <td><strong><?= he($sr['username']) ?></strong></td>
            <td class="center"><?= number_format((int)$sr['total_orders']) ?></td>
            <td class="right">$<?= number_format((float)$sr['total_sales'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<table style="width: 100%; border-collapse: collapse;">
    <tr>
        <td style="border: none; text-align: right;">
            <div style="display: inline-block; text-align: left; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 18px; min-width: 220px;">
                <div style="font-size: 11px; color: #6b7280; margin-bottom: 4px;"><?= $isKm ? 'ចំនួន Order សរុប:' : 'Total Handled Orders:' ?> <strong><?= number_format($grand_total_orders) ?></strong></div>
                <div style="font-size: 14px; font-weight: bold; color: #d1904b; border-top: 1px dashed #cbd5e1; padding-top: 4px; margin-top: 4px;">
                    <?= $isKm ? 'ចំណូលបុគ្គលិកសរុប:' : 'Total Staff Revenue:' ?> $<?= number_format($grand_total_sales, 2) ?>
                </div>
            </div>
        </td>
    </tr>
</table>

</body>
</html>
<?php
$html = ob_get_clean();

// Check mode: PDF vs HTML preview
if (isset($_GET['preview'])) {
    echo $html;
    exit;
}

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = "employee_report_" . $filter_from . "_to_" . $filter_to . ".pdf";
$dompdf->stream($filename, ["Attachment" => false]);
exit;
