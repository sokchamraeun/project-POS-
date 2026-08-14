<?php
require 'auth.php';
require_once 'config.php';
if (!can('report_employee')) { header("Location: dashboard.php?denied=1"); exit; }

$filter_from = trim($_GET['from_date'] ?? $_GET['date_from'] ?? $_GET['from'] ?? date('Y-m-d'));
$filter_to   = trim($_GET['to_date']   ?? $_GET['date_to']   ?? $_GET['to']   ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_from)) $filter_from = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_to))   $filter_to   = date('Y-m-d');
if ($filter_from > $filter_to) [$filter_from, $filter_to] = [$filter_to, $filter_from];

if (isset($_GET['action']) && $_GET['action'] === 'get_staff_orders') {
    header('Content-Type: application/json');
    $userId   = (int)($_GET['user_id'] ?? 0);
    $dateFrom = trim($_GET['from_date'] ?? $_GET['date_from'] ?? $_GET['from'] ?? $filter_from);
    $dateTo   = trim($_GET['to_date']   ?? $_GET['date_to']   ?? $_GET['to']   ?? $filter_to);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = $filter_from;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = $filter_to;
    if ($dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];

    $stmt = $conn->prepare("
        SELECT 
            o.order_id,
            o.daily_order_no,
            o.order_date,
            o.total,
            o.status,
            o.payment_method,
            GROUP_CONCAT(CONCAT(oi.quantity, 'x ', oi.product_name) SEPARATOR ', ') AS items_summary
        FROM orders o
        LEFT JOIN order_items oi ON oi.order_id = o.order_id
        WHERE (o.user_id = ? OR o.employee_id = ?)
          AND DATE(o.order_date) BETWEEN ? AND ?
        GROUP BY o.order_id, o.daily_order_no, o.order_date, o.total, o.status, o.payment_method
        ORDER BY o.order_date DESC
    ");
    $orders = [];
    if ($stmt) {
        $stmt->bind_param("iiss", $userId, $userId, $dateFrom, $dateTo);
        $stmt->execute();
        $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    echo json_encode(['success' => true, 'orders' => $orders, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
    exit;
}

$_is_mgr = in_array($_SESSION['role'] ?? '', ['admin', 'manager']);
$filter_user = (int)($_GET['user_id'] ?? 0);
if (!$_is_mgr) {
    $filter_user = (int)$_SESSION['user_id'];
}

$where_cond = ["DATE(o.order_date) BETWEEN '$filter_from' AND '$filter_to'"];
if ($filter_user > 0) {
    $where_cond[] = "(o.user_id = $filter_user OR o.employee_id = $filter_user)";
}
$where_str = implode(' AND ', $where_cond);

// Fetch staff users for dropdown
$user_options = [];
if ($_is_mgr) {
    $user_options[0] = 'All Staff';
    $q_users = $conn->query("SELECT u.user_id, u.username, e.name AS emp_name, r.slug AS role FROM users u LEFT JOIN employees e ON e.user_id = u.user_id LEFT JOIN roles r ON r.id = u.role_id ORDER BY COALESCE(NULLIF(e.name, ''), u.username) ASC");
    if ($q_users) {
        while ($ur = $q_users->fetch_assoc()) {
            $displayName = !empty($ur['emp_name']) ? $ur['emp_name'] : $ur['username'];
            $user_options[$ur['user_id']] = $displayName . ' (' . ucfirst($ur['role'] ?? 'staff') . ')';
        }
    }
} else {
    $my_uid = (int)$_SESSION['user_id'];
    $my_name = $_SESSION['username'] ?? 'My Report';
    $user_options[$my_uid] = $my_name;
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
<script>(function(){if(localStorage.getItem('theme')==='light')document.documentElement.setAttribute('data-theme','light');})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Report User | Bird's Nest Coffee</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="flex h-screen w-screen overflow-hidden app-layout">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<main class="app-main flex-1 h-full overflow-y-auto er-container">
    <?php
    $report_category = 'User';
    $report_title    = 'Report User';
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
    $export_pdf_url   = "employee_report_pdf.php?date_from=" . urlencode($filter_from) . "&date_to=" . urlencode($filter_to) . "&user_id=" . urlencode($filter_user) . "&lang=" . urlencode(current_lang());
    require __DIR__ . '/report_header.php';
    ?>

    <!-- Data Table -->
    <div class="er-table-card">
        <div class="er-table-wrap">
            <table class="er-table">
                <thead>
                    <tr>
                        <th style="text-align:center">Staff ID</th>
                        <th style="text-align:center">Employee Name</th>
                        <th style="text-align:center">Role</th>
                        <th style="text-align:center">Total Orders</th>
                        <th style="text-align:center">Billed Revenue</th>
                        <th style="text-align:center">Status</th>
                        <th style="text-align:center;width:90px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($staff_rows)): ?>
                    <tr class="no-data">
                        <td colspan="7" class="no-data" style="text-align:center">No data</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($staff_rows as $s): ?>
                    <?php
                        $ord_cnt   = (int)$s['total_orders'];
                        $sales_val = (float)$s['total_sales'];
                    ?>
                    <tr>
                        <td style="text-align:center">#STF-<?= (int)$s['user_id'] ?></td>
                        <td style="text-align:center" class="er-prod-name"><?= htmlspecialchars($s['username']) ?></td>
                        <td style="text-align:center"><span class="er-badge-cat uppercase"><?= htmlspecialchars($s['role']) ?></span></td>
                        <td style="text-align:center"><?= $ord_cnt ?></td>
                        <td style="text-align:center" class="er-total-rev">$<?= number_format($sales_val, 2) ?></td>
                        <td style="text-align:center"><span class="er-badge-doc">Active</span></td>
                        <td style="text-align:center;">
                            <button type="button" class="px-2.5 py-1 rounded-lg text-xs transition-all inline-flex items-center gap-1.5" onclick="viewStaffOrders(<?= (int)$s['user_id'] ?>, '<?= htmlspecialchars($s['username'], ENT_QUOTES) ?>')">
                                <i class="fa-solid fa-eye"></i> View
                            </button>
                        </td>
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

<!-- ── STAFF ORDERS DETAIL MODAL ── -->
<div id="staffOrdersModal" class="fixed inset-0 z-[9999] hidden flex items-center justify-center bg-black/75 backdrop-blur-sm p-4">
    <div class="bg-[#15151a] border border-[#282836] rounded-2xl w-full max-w-2xl overflow-hidden shadow-2xl flex flex-col max-h-[85vh]">
        <!-- Header -->
        <div class="px-6 py-4 border-b border-[#282836] flex items-center justify-between bg-[#1a1a22]">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-amber-500/15 border border-amber-500/30 text-amber-400 flex items-center justify-center font-bold text-base">
                    <i class="fa-solid fa-receipt"></i>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-white tracking-wide" id="staffModalTitle">Staff Orders</h3>
                    <p class="text-xs text-slate-400" id="staffModalSub">Orders processed during selected period</p>
                </div>
            </div>
            <button type="button" class="w-8 h-8 rounded-xl bg-slate-800 text-slate-400 hover:text-white hover:bg-slate-700 flex items-center justify-center transition-all" onclick="closeStaffModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <!-- Body -->
        <div class="p-6 overflow-y-auto flex-1 custom-scrollbar" id="staffModalContent">
            <!-- Dynamic Content -->
        </div>
        <!-- Footer -->
        <div class="px-6 py-3.5 border-t border-[#282836] bg-[#1a1a22] flex justify-end">
            <button type="button" class="px-4 py-2 rounded-xl bg-slate-800 text-slate-300 hover:bg-slate-700 font-semibold text-xs transition-all" onclick="closeStaffModal()">Close</button>
        </div>
    </div>
</div>

<script>
function viewStaffOrders(userId, username) {
    const modal = document.getElementById('staffOrdersModal');
    const title = document.getElementById('staffModalTitle');
    const sub   = document.getElementById('staffModalSub');
    const body  = document.getElementById('staffModalContent');

    title.textContent = `${username}'s Orders`;
    sub.textContent   = `List of orders and total price handled by ${username}`;
    body.innerHTML    = `
        <div class="text-center py-10 text-slate-400">
            <i class="fa-solid fa-spinner fa-spin text-amber-400 text-2xl mb-2"></i>
            <p class="text-xs">Fetching order details...</p>
        </div>`;
    modal.classList.remove('hidden');

    const activeFrom = '<?= $filter_from ?>';
    const activeTo   = '<?= $filter_to ?>';

    fetch(`shift_report.php?action=get_staff_orders&user_id=${userId}&from_date=${encodeURIComponent(activeFrom)}&to_date=${encodeURIComponent(activeTo)}`)
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            body.innerHTML = `<div class="p-4 rounded-xl bg-red-500/10 border border-red-500/30 text-red-400 text-sm">Failed to load order history.</div>`;
            return;
        }
        const orders = data.orders || [];
        if (orders.length === 0) {
            body.innerHTML = `<div class="p-8 text-center text-slate-500 italic bg-[#1a1a22] rounded-xl border border-[#282836]">No orders found for this staff member in the selected date range.</div>`;
            return;
        }

        let totalRev = 0;
        let rowsHtml = '';
        orders.forEach((o, idx) => {
            const amt = parseFloat(o.total || 0);
            if (o.status !== 'Cancelled' && o.status !== 'Refunded') {
                totalRev += amt;
            }
            const statusCls = o.status === 'Paid' || o.status === 'Completed' 
                ? 'bg-emerald-500/15 text-emerald-400' 
                : (o.status === 'Cancelled' ? 'bg-red-500/15 text-red-400' : 'bg-amber-500/15 text-amber-400');

            const orderDisplayNum = o.daily_order_no ? `#${o.daily_order_no}` : `#ORD-${o.order_id}`;

            rowsHtml += `
                <tr class="hover:bg-slate-800/40 border-b border-[#282836]/50">
                    <td class="p-2.5 text-center text-slate-500 font-semibold">${idx + 1}</td>
                    <td class="p-2.5 font-bold text-amber-400 font-mono">${escapeHtml(orderDisplayNum)}</td>
                    <td class="p-2.5 text-slate-300 text-xs">${escapeHtml(o.order_date)}</td>
                    <td class="p-2.5 text-slate-300 font-medium">${escapeHtml(o.items_summary || '—')}</td>
                    <td class="p-2.5 text-center text-slate-400 capitalize">${escapeHtml(o.payment_method || 'Cash')}</td>
                    <td class="p-2.5 text-right font-bold text-white">$${amt.toFixed(2)}</td>
                </tr>`;
        });

        body.innerHTML = `
            <div class="mb-4 flex items-center justify-between bg-[#1a1a22] p-3 rounded-xl border border-[#282836]">
                <span class="text-xs text-slate-400">Total Orders: <strong class="text-white">${orders.length}</strong></span>
                <span class="text-xs text-slate-400">Total Revenue: <strong class="text-emerald-400">$${totalRev.toFixed(2)}</strong></span>
            </div>
            <div class="rounded-xl border border-[#282836] overflow-hidden">
                <table class="w-full text-xs text-left">
                    <thead class="bg-[#2a2218] text-amber-400 font-bold uppercase text-[10px]">
                        <tr>
                            <th class="p-2.5 text-center" style="width:35px;">No.</th>
                            <th class="p-2.5" style="width:95px;">Order No.</th>
                            <th class="p-2.5" style="width:130px;">Date & Time</th>
                            <th class="p-2.5">Items Ordered</th>
                            <th class="p-2.5 text-center" style="width:80px;">Payment</th>
                            <th class="p-2.5 text-right" style="width:80px;">Price</th>
                        </tr>
                    </thead>
                    <tbody class="bg-[#15151a]">
                        ${rowsHtml}
                    </tbody>
                </table>
            </div>`;
    })
    .catch(err => {
        console.error(err);
        body.innerHTML = `<div class="p-4 rounded-xl bg-red-500/10 border border-red-500/30 text-red-400 text-sm">An error occurred while loading data.</div>`;
    });
}

function closeStaffModal() {
    document.getElementById('staffOrdersModal').classList.add('hidden');
}

function escapeHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>
</body>
</html>
