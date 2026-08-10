<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$filter_from = trim($_GET['from_date'] ?? $_GET['date_from'] ?? date('Y-m-d'));
$filter_to   = trim($_GET['to_date']   ?? $_GET['date_to']   ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_from)) $filter_from = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_to))   $filter_to   = date('Y-m-d');
if ($filter_from > $filter_to) [$filter_from, $filter_to] = [$filter_to, $filter_from];

$filter_user = (int)($_GET['user_id'] ?? 0);

$where_cond = ["DATE(o.order_date) BETWEEN '$filter_from' AND '$filter_to'"];
if ($filter_user > 0) {
    $where_cond[] = "o.user_id = $filter_user";
}
$where_str = implode(' AND ', $where_cond);

// Fetch all staff users for dropdown
$user_options = [0 => 'All Staff'];
$q_users = $conn->query("SELECT u.user_id, u.username, r.slug AS role FROM users u LEFT JOIN roles r ON r.id = u.role_id ORDER BY u.username ASC");
if ($q_users) {
    while ($ur = $q_users->fetch_assoc()) {
        $user_options[$ur['user_id']] = $ur['username'] . ' (' . ucfirst($ur['role'] ?? 'staff') . ')';
    }
}

$where_user = $filter_user > 0 ? "WHERE u.user_id = $filter_user" : "";

$sql_staff = "SELECT 
                u.user_id,
                u.username,
                r.slug AS role,
                COUNT(o.order_id) AS total_orders,
                COALESCE(SUM(CASE WHEN o.status NOT IN ('Cancelled','Refunded') THEN o.total ELSE 0 END), 0) AS total_sales,
                COALESCE(SUM(CASE WHEN o.status = 'Cancelled' THEN 1 ELSE 0 END), 0) AS cancelled_count,
                COALESCE(SUM(CASE WHEN o.status = 'Paid' THEN o.total ELSE 0 END), 0) AS paid_sales,
                MIN(o.order_date) as first_order,
                MAX(o.order_date) as last_order
              FROM users u
              LEFT JOIN roles r ON r.id = u.role_id
              LEFT JOIN orders o ON o.user_id = u.user_id AND $where_str
              $where_user
              GROUP BY u.user_id, u.username, r.slug
              ORDER BY total_sales DESC";
$res_staff = $conn->query($sql_staff);
$staff_rows = $res_staff ? $res_staff->fetch_all(MYSQLI_ASSOC) : [];

$grand_total_orders = 0;
$grand_total_sales = 0.0;
foreach ($staff_rows as $sr) {
    $grand_total_orders += (int)$sr['total_orders'];
    $grand_total_sales  += (float)$sr['total_sales'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Employee Report | Bird's Nest Coffee</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="flex h-screen w-screen overflow-hidden bg-[#0e0e10] app-layout">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<main class="app-main flex-1 h-full overflow-y-auto er-container">
    <?php
    $report_category = 'Employee';
    $report_title    = 'Employee Report';
    $date_from       = $filter_from;
    $date_to         = $filter_to;

    $filter_options  = [
        [
            'name' => 'user_id',
            'label' => 'Staff Member',
            'options' => $user_options,
            'selected' => $filter_user
        ]
    ];
    $export_excel_url = "#";
    $export_pdf_url   = "#";
    require __DIR__ . '/report_header.php';
    ?>

    <!-- Data Table -->
    <div class="er-table-card">
        <div class="er-table-wrap">
            <table class="er-table">
                <thead>
                    <tr>
                        <th>Doc. Type</th>
                        <th>Staff ID</th>
                        <th>Employee Name</th>
                        <th>Role</th>
                        <th>First Order Time</th>
                        <th>Last Order Time</th>
                        <th>Total Orders</th>
                        <th>Cancelled Orders</th>
                        <th>Billed Revenue</th>
                        <th>Avg / Order</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($staff_rows)): ?>
                    <tr class="no-data">
                        <td colspan="11" class="no-data">No data</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($staff_rows as $s): ?>
                    <?php
                        $ord_cnt  = (int)$s['total_orders'];
                        $sales_val = (float)$s['total_sales'];
                        $canc_cnt = (int)$s['cancelled_count'];
                        $billed   = $ord_cnt - $canc_cnt;
                        $avg_val  = $billed > 0 ? $sales_val / $billed : 0;
                    ?>
                    <tr>
                        <td><span class="px-2 py-0.5 rounded text-xs bg-purple-500/10 text-purple-400 font-medium">Staff Performance</span></td>
                        <td class="font-bold text-slate-400">#STF-<?= (int)$s['user_id'] ?></td>
                        <td class="font-bold text-white"><?= htmlspecialchars($s['username']) ?></td>
                        <td><span class="px-2 py-0.5 rounded text-xs bg-slate-800 text-amber-400 font-semibold uppercase"><?= htmlspecialchars($s['role']) ?></span></td>
                        <td><?= $s['first_order'] ? date('H:i:s', strtotime($s['first_order'])) : '—' ?></td>
                        <td><?= $s['last_order'] ? date('H:i:s', strtotime($s['last_order'])) : '—' ?></td>
                        <td class="font-semibold text-center"><?= $ord_cnt ?></td>
                        <td class="text-red-400 font-semibold text-center"><?= $canc_cnt ?></td>
                        <td class="font-bold text-amber-400">$<?= number_format($sales_val, 2) ?></td>
                        <td class="text-slate-300">$<?= number_format($avg_val, 2) ?></td>
                        <td><span class="text-xs text-emerald-400 font-semibold">Active</span></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Summary Card -->
    <div class="er-summary-card">
        <div class="er-summary-info">
            <span>Date From : <strong><?= htmlspecialchars($filter_from) ?></strong></span>
            <span>Date To : <strong><?= htmlspecialchars($filter_to) ?></strong></span>
            <span>Doc.Count : <strong><?= count($staff_rows) ?></strong></span>
        </div>
        <div class="er-summary-stats">
            <div class="er-summary-stat-item">
                <span class="stat-label">Total Orders Handled</span>
                <span class="stat-val text-amber-400"><?= $grand_total_orders ?></span>
            </div>
            <div class="er-summary-stat-item">
                <span class="stat-label">Total Staff Revenue</span>
                <span class="stat-val text-emerald-400">$<?= number_format($grand_total_sales, 2) ?></span>
            </div>
        </div>
    </div>
</main>
</div>
</body>
</html>
