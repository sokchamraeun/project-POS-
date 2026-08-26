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
            o.order_id AS daily_order_no,
            o.order_date,
            o.total,
            'Completed' AS status,
            o.payment_method,
            GROUP_CONCAT(CONCAT(oi.quantity, 'x ', oi.product_name) SEPARATOR ', ') AS items_summary
        FROM users u
        JOIN orders o ON (o.user_id = u.user_id OR (o.user_id IS NULL AND LOWER(o.prepared_by) = LOWER(u.username)))
        LEFT JOIN order_items oi ON oi.order_id = o.order_id
        WHERE u.user_id = ? AND DATE(o.order_date) BETWEEN ? AND ?
        GROUP BY o.order_id, o.order_date, o.total, o.payment_method
        ORDER BY o.order_date DESC
    ");
    $orders = [];
    if ($stmt) {
        $stmt->bind_param("iss", $userId, $dateFrom, $dateTo);
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
$where_str = implode(' AND ', $where_cond);

// Fetch staff users for dropdown
$user_options = [];
if ($_is_mgr) {
    $user_options[0] = 'All Staff';
    $q_users = $conn->query("SELECT u.user_id, COALESCE(NULLIF(u.name, ''), u.username) AS display_name FROM users u ORDER BY display_name ASC");
    if ($q_users) {
        while ($ur = $q_users->fetch_assoc()) {
            $displayName = $ur['display_name'];
            $user_options[$ur['user_id']] = $displayName;
        }
    }
} else {
    $my_uid = (int)$_SESSION['user_id'];
    $my_name = $_SESSION['emp_name'] ?? ($_SESSION['username'] ?? 'My Report');
    $user_options[$my_uid] = $my_name;
}

$where_user = $filter_user > 0 ? "WHERE u.user_id = $filter_user" : "";

$sql_staff = "SELECT 
                u.user_id,
                COALESCE(NULLIF(u.name, ''), u.username) AS username,
                u.role,
                COUNT(o.order_id) AS total_orders,
                COALESCE(SUM(o.total), 0) AS total_sales,
                0 AS cancelled_count,
                COALESCE(SUM(o.total), 0) AS paid_sales,
                MIN(o.order_date) AS first_order,
                MAX(o.order_date) AS last_order
              FROM users u
              LEFT JOIN orders o ON (o.user_id = u.user_id OR (o.user_id IS NULL AND LOWER(o.prepared_by) = LOWER(u.username))) AND DATE(o.order_date) BETWEEN '$filter_from' AND '$filter_to'
              $where_user
              GROUP BY u.user_id, u.username, u.name, u.role
              ORDER BY u.username ASC";
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
<html lang="<?= current_lang() ?>" data-lang="<?= current_lang() ?>">
<head>
<meta charset="UTF-8">
<script>(function(){if(localStorage.getItem('theme')==='light')document.documentElement.setAttribute('data-theme','light');})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Report User | Bird's Nest Coffee</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
body, input, select, textarea, button, table {
    font-family: 'Poppins', 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}
:lang(km), [data-lang="km"], html[lang="km"], html[lang="km"] * {
    font-family: 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', 'Khmer OS Siemreap', 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
}
html[lang="km"] .fa, html[lang="km"] [class*="fa-"], html[lang="km"] i {
    font-family: 'Font Awesome 6 Free', 'FontAwesome' !important;
}
html[lang="km"] .fa-brands, html[lang="km"] [class*="fa-brands"] {
    font-family: 'Font Awesome 6 Brands', 'FontAwesome' !important;
}
.er-btn-icon-view {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(16, 185, 129, 0.12);
    border: 1px solid rgba(16, 185, 129, 0.25);
    color: #10b981;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s ease;
}
.er-btn-icon-view:hover {
    background: #10b981;
    color: #fff;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}
[data-theme="light"] .er-btn-icon-view {
    background: rgba(16, 185, 129, 0.1);
    border-color: rgba(16, 185, 129, 0.3);
    color: #059669;
}
[data-theme="light"] .er-btn-icon-view:hover {
    background: #059669;
    color: #fff;
}

/* ── Staff Orders Modal (Theme Aware) ── */
.som-dialog {
    background: var(--bg-card, #16161e);
    border: 1px solid var(--border, #282836);
    color: var(--text, #f1f5f9);
    border-radius: 16px;
    box-shadow: 0 20px 45px rgba(0,0,0,0.4);
    max-width: 650px;
    width: 95%;
    max-height: 85vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.som-header {
    padding: 12px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--bg-main, #1c1c26);
    border-bottom: 1px solid var(--border, #282836);
}
.som-title {
    font-size: 13.5px;
    font-weight: 700;
    color: var(--text, #ffffff);
}
.som-sub {
    font-size: 11px;
    color: var(--text-muted, #94a3b8);
}
.som-close-btn {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    background: rgba(255,255,255,0.06);
    border: 1px solid var(--border, #282836);
    color: var(--text-muted, #94a3b8);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 13px;
}
.som-close-btn:hover {
    color: var(--text, #fff);
    background: rgba(16, 185, 129, 0.2);
    border-color: var(--accent, #10b981);
}
.som-body {
    padding: 14px 18px;
    overflow-y: auto;
    flex: 1;
}
.som-stat-box {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    border-radius: 10px;
    margin-bottom: 12px;
    background: var(--bg-main, #1c1c26);
    border: 1px solid var(--border, #282836);
    font-size: 11.5px;
}
.som-table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    border-radius: 10px;
    border: 1px solid var(--border, #282836);
}
.som-table {
    width: 100%;
    font-size: 11.5px;
    text-align: left;
    border-collapse: collapse;
}
.som-table th {
    padding: 8px 10px;
    background: rgba(16, 185, 129, 0.15);
    color: var(--accent, #10b981);
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    white-space: nowrap;
    border-bottom: 1px solid var(--border, #282836);
}
.som-table td {
    padding: 8px 10px;
    border-bottom: 1px solid var(--border, #282836);
    white-space: nowrap;
}
.som-table tr:last-child td {
    border-bottom: none;
}
.som-footer {
    padding: 10px 18px;
    display: flex;
    justify-content: flex-end;
    background: var(--bg-main, #1c1c26);
    border-top: 1px solid var(--border, #282836);
}
.som-btn-close {
    padding: 6px 16px;
    border-radius: 8px;
    background: rgba(255,255,255,0.08);
    border: 1px solid var(--border, #282836);
    color: var(--text, #e2e8f0);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}
.som-btn-close:hover {
    background: var(--accent, #10b981);
    color: #fff;
    border-color: var(--accent, #10b981);
}

/* Light theme overrides */
[data-theme="light"] .som-dialog {
    background: #fdfbf7;
    border-color: #e0d4c4;
    color: #1a1410;
}
[data-theme="light"] .som-header,
[data-theme="light"] .som-footer,
[data-theme="light"] .som-stat-box {
    background: #f4eee5;
    border-color: #e0d4c4;
}
[data-theme="light"] .som-title {
    color: #1a1410;
}
[data-theme="light"] .som-sub {
    color: #6b5e51;
}
[data-theme="light"] .som-close-btn {
    background: #ede6dc;
    border-color: #ddcfbf;
    color: #6b5e51;
}
[data-theme="light"] .som-close-btn:hover {
    background: rgba(184, 115, 51, 0.15);
    border-color: #b87333;
    color: #b87333;
}
[data-theme="light"] .som-table-wrap {
    border-color: #e0d4c4;
}
[data-theme="light"] .som-table th {
    background: #ede3d5;
    color: #b87333;
    border-color: #e0d4c4;
}
[data-theme="light"] .som-table td {
    border-color: #ede4d8;
    color: #1a1410;
}
[data-theme="light"] .som-btn-close {
    background: #ede6dc;
    border-color: #ddcfbf;
    color: #3d332a;
}
[data-theme="light"] .som-btn-close:hover {
    background: #b87333;
    color: #fff;
    border-color: #b87333;
}
</style>
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
                        <th style="text-align:center">Employee Name</th>
                        <th style="text-align:center">Total Orders</th>
                        <th style="text-align:center">Billed Revenue</th>
                        <th style="text-align:center;width:60px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($staff_rows)): ?>
                    <tr class="no-data">
                        <td colspan="4" class="no-data" style="text-align:center">No data</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($staff_rows as $s): ?>
                    <?php
                        $ord_cnt   = (int)$s['total_orders'];
                        $sales_val = (float)$s['total_sales'];
                    ?>
                    <tr>
                        <td style="text-align:center" class="er-prod-name"><?= htmlspecialchars($s['username']) ?></td>
                        <td style="text-align:center"><?= $ord_cnt ?></td>
                        <td style="text-align:center" class="er-total-rev">$<?= number_format($sales_val, 2) ?></td>
                        <td style="text-align:center;">
                            <button type="button" class="er-btn-icon-view" onclick="viewStaffOrders(<?= (int)$s['user_id'] ?>, '<?= htmlspecialchars($s['username'], ENT_QUOTES) ?>')" title="View Orders">
                                <i class="fa-solid fa-eye"></i>
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
<div id="staffOrdersModal" class="fixed inset-0 z-[9999] hidden flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="som-dialog">
        <!-- Header -->
        <div class="som-header">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-amber-500/15 border border-amber-500/30 text-amber-500 flex items-center justify-center font-bold text-sm">
                    <i class="fa-solid fa-receipt"></i>
                </div>
                <div>
                    <h3 class="som-title" id="staffModalTitle">Staff Orders</h3>
                    <p class="som-sub" id="staffModalSub">Orders processed during selected period</p>
                </div>
            </div>
            <button type="button" class="som-close-btn" onclick="closeStaffModal()" title="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <!-- Body -->
        <div class="som-body" id="staffModalContent">
            <!-- Dynamic Content -->
        </div>
        <!-- Footer -->
        <div class="som-footer">
            <button type="button" class="som-btn-close" onclick="closeStaffModal()">Close</button>
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
        <div class="text-center py-10" style="color: var(--text-muted, #94a3b8);">
            <i class="fa-solid fa-spinner fa-spin text-amber-500 text-2xl mb-2"></i>
            <p class="text-xs">Fetching order details...</p>
        </div>`;
    modal.classList.remove('hidden');

    const activeFrom = '<?= $filter_from ?>';
    const activeTo   = '<?= $filter_to ?>';

    fetch(`shift_report.php?action=get_staff_orders&user_id=${userId}&from_date=${encodeURIComponent(activeFrom)}&to_date=${encodeURIComponent(activeTo)}`)
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            body.innerHTML = `<div class="p-4 rounded-xl bg-red-500/10 border border-red-500/30 text-red-500 text-xs">Failed to load order history.</div>`;
            return;
        }
        const orders = data.orders || [];
        if (orders.length === 0) {
            body.innerHTML = `<div class="p-6 text-center italic rounded-xl border border-dashed" style="color: var(--text-muted, #94a3b8); border-color: var(--border, #282836); font-size: 12px;">No orders found for this staff member in the selected date range.</div>`;
            return;
        }

        let totalRev = 0;
        let rowsHtml = '';
        orders.forEach((o, idx) => {
            const amt = parseFloat(o.total || 0);
            if (o.status !== 'Cancelled' && o.status !== 'Refunded') {
                totalRev += amt;
            }
            const orderDisplayNum = o.daily_order_no ? `#${o.daily_order_no}` : `#ORD-${o.order_id}`;

            rowsHtml += `
                <tr>
                    <td style="text-align:center; font-weight:600; color: var(--text-muted, #94a3b8); width:32px;">${idx + 1}</td>
                    <td style="font-weight:700; color: var(--accent, #10b981); font-family: monospace; width:80px;">${escapeHtml(orderDisplayNum)}</td>
                    <td style="font-size:11px; color: var(--text-muted, #94a3b8); width:115px;">${escapeHtml(o.order_date)}</td>
                    <td style="font-weight:600;">${escapeHtml(o.items_summary || '—')}</td>
                    <td style="text-align:center; text-transform:capitalize; width:75px;">${escapeHtml(o.payment_method || 'Cash')}</td>
                    <td style="text-align:right; font-weight:700; color: var(--accent, #10b981); width:75px;">$${amt.toFixed(2)}</td>
                </tr>`;
        });

        body.innerHTML = `
            <div class="som-stat-box">
                <span>Total Orders: <strong style="color: var(--text, #fff); font-size: 12.5px;">${orders.length}</strong></span>
                <span>Total Revenue: <strong style="color: #10b981; font-size: 13px;">$${totalRev.toFixed(2)}</strong></span>
            </div>
            <div class="som-table-wrap">
                <table class="som-table">
                    <thead>
                        <tr>
                            <th style="text-align:center;width:32px;">No.</th>
                            <th style="width:80px;">Order No.</th>
                            <th style="width:115px;">Date & Time</th>
                            <th>Items Ordered</th>
                            <th style="text-align:center;width:75px;">Payment</th>
                            <th style="text-align:right;width:75px;">Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rowsHtml}
                    </tbody>
                </table>
            </div>`;
    })
    .catch(err => {
        console.error(err);
        body.innerHTML = `<div class="p-4 rounded-xl bg-red-500/10 border border-red-500/30 text-red-500 text-xs">An error occurred while loading data.</div>`;
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
