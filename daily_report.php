<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lang.php';

if (!can('report_sale') && !can('sales_report') && !can('report')) {
    header("Location: dashboard.php?denied=1");
    exit;
}

// ── AJAX Order Details for Popup Modal ──
if (isset($_GET['action']) && $_GET['action'] === 'order_details') {
    header('Content-Type: application/json; charset=utf-8');
    $order_id = (int)($_GET['order_id'] ?? 0);
    if ($order_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
        exit;
    }
    
    $stmt_o = $conn->prepare("
        SELECT o.*, 
               COALESCE(NULLIF(u.name, ''), u.username, o.prepared_by, 'Staff') AS seller_name
        FROM orders o
        LEFT JOIN users u ON u.user_id = o.user_id
        WHERE o.order_id = ?
        LIMIT 1
    ");
    $stmt_o->bind_param("i", $order_id);
    $stmt_o->execute();
    $order = $stmt_o->get_result()->fetch_assoc();
    
    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }
    
    $stmt_items = $conn->prepare("
        SELECT oi.*, p.name AS product_name, p.category, p.image
        FROM order_items oi
        LEFT JOIN products p ON p.product_id = oi.product_id
        WHERE oi.order_id = ?
        ORDER BY oi.order_item_id ASC
    ");
    $stmt_items->bind_param("i", $order_id);
    $stmt_items->execute();
    $res_items = $stmt_items->get_result();
    $items = [];
    while ($row = $res_items->fetch_assoc()) {
        $addons = [];
        if (!empty($row['addons_snapshot'])) {
            $dec = json_decode($row['addons_snapshot'], true);
            if (is_array($dec)) {
                foreach ($dec as $a) {
                    if (is_string($a)) $addons[] = $a;
                    elseif (is_array($a) && !empty($a['name'])) $addons[] = $a['name'];
                }
            }
        }
        $row['addons'] = $addons;
        $items[] = $row;
    }
    $order['items'] = $items;
    
    echo json_encode(['success' => true, 'order' => $order]);
    exit;
}

date_default_timezone_set("Asia/Phnom_Penh");
$isKm = (current_lang() === 'km');
$_is_mgr = in_array($_SESSION['role'] ?? '', ['admin', 'manager']);

$today = business_date_today();
$quickRange = $_GET['quick_range'] ?? 'today';
$fromDateTimeParam = trim($_GET['from_datetime'] ?? '');
$toDateTimeParam   = trim($_GET['to_datetime']   ?? '');

if (!empty($fromDateTimeParam)) {
    $parts = explode('T', str_replace(' ', 'T', $fromDateTimeParam));
    $fromDate = $parts[0] ?? $today;
    $fromTime = $parts[1] ?? '00:00';
} else {
    $fromDate = $_GET['from_date'] ?? $_GET['date_from'] ?? '';
    $fromTime = trim($_GET['from_time'] ?? '');
}

if (!empty($toDateTimeParam)) {
    $parts = explode('T', str_replace(' ', 'T', $toDateTimeParam));
    $toDate = $parts[0] ?? $today;
    $toTime = $parts[1] ?? '23:59';
} else {
    $toDate = $_GET['to_date'] ?? $_GET['date_to'] ?? '';
    $toTime = trim($_GET['to_time'] ?? '');
}

if (empty($fromDate) || empty($toDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    if ($quickRange === 'today') {
        $fromDate = $today;
        $toDate   = $today;
    } elseif ($quickRange === 'yesterday') {
        $fromDate = date('Y-m-d', strtotime($today . ' -1 day'));
        $toDate   = date('Y-m-d', strtotime($today . ' -1 day'));
    } elseif ($quickRange === 'week' || $quickRange === 'this_week') {
        $fromDate = date('Y-m-d', strtotime('monday this week', strtotime($today)));
        $toDate   = date('Y-m-d', strtotime('sunday this week', strtotime($today)));
    } elseif ($quickRange === 'month' || $quickRange === 'this_month') {
        $fromDate = date('Y-m-01', strtotime($today));
        $toDate   = $today;
    } elseif ($quickRange === 'year' || $quickRange === 'this_year') {
        $fromDate = date('Y-01-01', strtotime($today));
        $toDate   = date('Y-12-31', strtotime($today));
    } else {
        $fromDate = $today;
        $toDate   = $today;
        $quickRange = 'today';
    }
}
if ($fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$fromTimeFull = '00:00:00';
if (!empty($fromTime) && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $fromTime)) {
    $fromTimeFull = (strlen($fromTime) === 5) ? ($fromTime . ':00') : $fromTime;
}
$toTimeFull = '23:59:59';
if (!empty($toTime) && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $toTime)) {
    $toTimeFull = (strlen($toTime) === 5) ? ($toTime . ':59') : $toTime;
}

$fromDateTime = $fromDate . ' ' . $fromTimeFull;
$toDateTime   = $toDate   . ' ' . $toTimeFull;

$fromDateTimeInputVal = $fromDate . 'T' . substr($fromTimeFull, 0, 5);
$toDateTimeInputVal   = $toDate   . 'T' . substr($toTimeFull, 0, 5);

$timeShift     = trim($_GET['time_shift'] ?? '');
$filterUser    = (int)($_GET['user_id'] ?? $_GET['user'] ?? 0);
if (!$_is_mgr) {
    $filterUser = (int)$_SESSION['user_id'];
}
$paymentMethod = trim($_GET['payment_method'] ?? 'all');
$searchQuery   = trim($_GET['search'] ?? '');

$whereClauses = ["o.order_date BETWEEN ? AND ?"];
$bindTypes    = "ss";
$bindParams   = [$fromDateTime, $toDateTime];

$whereClauses[] = "oc.order_id IS NULL";

if ($timeShift === 'morning') {
    $whereClauses[] = "TIME(o.order_date) BETWEEN '06:00:00' AND '11:59:59'";
} elseif ($timeShift === 'afternoon') {
    $whereClauses[] = "TIME(o.order_date) BETWEEN '12:00:00' AND '17:59:59'";
} elseif ($timeShift === 'evening') {
    $whereClauses[] = "TIME(o.order_date) BETWEEN '18:00:00' AND '23:59:59'";
}

if ($filterUser > 0) {
    $whereClauses[] = "o.user_id = ?";
    $bindTypes   .= "i";
    $bindParams[] = $filterUser;
}

if ($paymentMethod !== 'all' && !empty($paymentMethod)) {
    if (strtolower($paymentMethod) === 'cash') {
        $whereClauses[] = "LOWER(o.payment_method) LIKE '%cash%'";
    } elseif (strtolower($paymentMethod) === 'bakong' || strtolower($paymentMethod) === 'khqr') {
        $whereClauses[] = "(LOWER(o.payment_method) LIKE '%bakong%' OR LOWER(o.payment_method) LIKE '%khqr%' OR LOWER(o.payment_method) LIKE '%qr%')";
    } elseif (strtolower($paymentMethod) === 'paylater' || strtolower($paymentMethod) === 'pay_later') {
        $whereClauses[] = "(LOWER(o.payment_method) LIKE '%later%' OR LOWER(o.payment_method) LIKE '%credit%')";
    } else {
        $whereClauses[] = "o.payment_method = ?";
        $bindTypes   .= "s";
        $bindParams[] = $paymentMethod;
    }
}

if (!empty($searchQuery)) {
    $whereClauses[] = "(o.order_id LIKE ? OR u.username LIKE ? OR u.name LIKE ?)";
    $sParam = "%$searchQuery%";
    $bindTypes .= "sss";
    $bindParams[] = $sParam;
    $bindParams[] = $sParam;
    $bindParams[] = $sParam;
}

$whereSql = implode(" AND ", $whereClauses);

$sql = "
    SELECT 
        o.order_id,
        o.order_id AS daily_order_no,
        'Guest' AS customer_name,
        o.total,
        o.order_date,
        o.payment_method,
        COALESCE(NULLIF(u.name, ''), u.username, o.prepared_by, 'Staff') AS seller_name,
        COALESCE(SUM(oi.quantity), 0) AS total_items,
        COALESCE(SUM(oi.quantity * COALESCE(p.cost_price, 0)), 0) AS total_cost,
        COALESCE(SUM(oi.quantity * (oi.price - COALESCE(p.cost_price, 0))), 0) AS total_profit
    FROM orders o
    LEFT JOIN users u ON u.user_id = o.user_id
    LEFT JOIN order_items oi ON oi.order_id = o.order_id
    LEFT JOIN products p ON p.product_id = oi.product_id
    LEFT JOIN order_cancellations oc ON oc.order_id = o.order_id
    WHERE {$whereSql}
    GROUP BY o.order_id, o.total, o.order_date, o.payment_method, u.name, u.username, o.prepared_by
    ORDER BY o.order_id DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Database query error: " . $conn->error);
}
if ($bindTypes !== "") {
    $stmt->bind_param($bindTypes, ...$bindParams);
}
$stmt->execute();
$ordersResult = $stmt->get_result();

$ordersList = [];
$totalOrdersCount = 0;
$totalItemsSold = 0;
$totalSalesAmount = 0.0;
$totalCostAmount = 0.0;
$totalProfitAmount = 0.0;

while ($row = $ordersResult->fetch_assoc()) {
    $row['items'] = [];
    $ordersList[] = $row;
    $totalOrdersCount++;
    $totalItemsSold += (int)$row['total_items'];
    $totalSalesAmount += (float)$row['total'];
    $totalCostAmount += (float)$row['total_cost'];
    $totalProfitAmount += (float)$row['total_profit'];
}

// Preload items for fast instant modal opening
$orderIds = array_column($ordersList, 'order_id');
if (!empty($orderIds)) {
    $idsIn = implode(',', array_map('intval', $orderIds));
    $q_items = $conn->query("
        SELECT oi.*, p.name AS product_name, p.category, p.image
        FROM order_items oi
        LEFT JOIN products p ON p.product_id = oi.product_id
        WHERE oi.order_id IN ($idsIn)
        ORDER BY oi.order_item_id ASC
    ");
    if ($q_items) {
        $orderItemsMap = [];
        while ($item = $q_items->fetch_assoc()) {
            $oid = (int)$item['order_id'];
            $addons = [];
            if (!empty($item['addons_snapshot'])) {
                $dec = json_decode($item['addons_snapshot'], true);
                if (is_array($dec)) {
                    foreach ($dec as $a) {
                        if (is_string($a)) $addons[] = $a;
                        elseif (is_array($a) && !empty($a['name'])) $addons[] = $a['name'];
                    }
                }
            }
            $item['addons'] = $addons;
            $orderItemsMap[$oid][] = $item;
        }
        foreach ($ordersList as &$oRef) {
            $oid = (int)$oRef['order_id'];
            $oRef['items'] = $orderItemsMap[$oid] ?? [];
        }
        unset($oRef);
    }
}

$staffList = [];
if ($_is_mgr) {
    $q_staff = $conn->query("SELECT user_id, username, COALESCE(NULLIF(name, ''), username) AS display_name FROM users ORDER BY username ASC");
    if ($q_staff) {
        while ($sr = $q_staff->fetch_assoc()) {
            $staffList[] = $sr;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $isKm ? 'របាយការណ៍លក់' : 'Sales Report' ?> | Bird's Nest Coffee</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>(function(){try{if(localStorage.getItem("theme")==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root, [data-theme="dark"], html:not([data-theme="light"]) {
            --bg-main: #0b0c10;
            --bg-card: #14151e;
            --bg-card-hover: #191a26;
            --bg-input: #1b1c27;
            --bg-subtle: #101118;
            --border-color: #232433;
            --border-input: #2c2d3e;
            --text-main: #f8fafc;
            --text-muted: #8e8e9f;
            --text-sub: #c7c7d4;
            --accent: #10b981;
            --accent-hover: #059669;
        }

        [data-theme="light"], html[data-theme="light"] {
            --bg-main: #f1f5f9;
            --bg-card: #ffffff;
            --bg-card-hover: #f8fafc;
            --bg-input: #f8fafc;
            --bg-subtle: #f8fafc;
            --border-color: #e2e8f0;
            --border-input: #cbd5e1;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --text-sub: #334155;
            --accent: #10b981;
            --accent-hover: #059669;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body, .app-layout, .app-main, .rep-page-wrapper {
            background-color: var(--bg-main) !important;
            background-image: none !important;
            color: var(--text-main);
            font-family: 'Poppins', 'Kantumruy Pro', sans-serif;
        }

        /* Light Theme Overrides */
        [data-theme="light"] body,
        [data-theme="light"] .app-layout,
        [data-theme="light"] .app-main,
        [data-theme="light"] .rep-page-wrapper {
            background-color: #f1f5f9 !important;
            color: #0f172a !important;
        }
        [data-theme="light"] .bg-\[\#14151e\] {
            background-color: #ffffff !important;
            border-color: #e2e8f0 !important;
            color: #0f172a !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04) !important;
        }
        [data-theme="light"] .bg-\[\#1b1c27\] {
            background-color: #f8fafc !important;
            border-color: #cbd5e1 !important;
            color: #0f172a !important;
        }
        [data-theme="light"] .bg-\[\#101118\] {
            background-color: #f8fafc !important;
            border-color: #e2e8f0 !important;
            color: #0f172a !important;
        }

        /* ══ Table Header Color Styling ══ */
        .report-thead,
        .report-thead tr,
        .report-thead th {
            background: #0d231e !important;
            color: #34d399 !important;
            border-bottom: 2px solid #10b981 !important;
            font-weight: 700 !important;
        }

        [data-theme="light"] .report-thead,
        [data-theme="light"] .report-thead tr,
        [data-theme="light"] .report-thead th {
            background: #e6f7f0 !important;
            color: #047857 !important;
            border-bottom: 2px solid #10b981 !important;
            font-weight: 700 !important;
        }

        [data-theme="light"] thead tr.bg-\[\#101118\] {
            background-color: #f8fafc;
        }
        [data-theme="light"] th:not(.report-thead th):not(.report-thead) {
            color: #64748b !important;
            background-color: #f8fafc !important;
        }
        [data-theme="light"] .text-white {
            color: #0f172a !important;
        }
        [data-theme="light"] .text-\[\#8e8e9f\],
        [data-theme="light"] .text-\[\#78788c\],
        [data-theme="light"] .text-\[\#606175\] {
            color: #64748b !important;
        }
        [data-theme="light"] .text-\[\#c7c7d4\] {
            color: #334155 !important;
        }
        [data-theme="light"] .placeholder-\[\#68687a\]::placeholder {
            color: #94a3b8 !important;
        }
        [data-theme="light"] .border-\[\#232433\],
        [data-theme="light"] .border-\[\#2c2d3e\],
        [data-theme="light"] .divide-\[\#1e1f2c\] > :not([hidden]) ~ :not([hidden]) {
            border-color: #e2e8f0 !important;
        }
        [data-theme="light"] tr.border-b {
            border-color: #e2e8f0 !important;
        }
        [data-theme="light"] .hover\:bg-\[\#191a26\]:hover,
        [data-theme="light"] .hover\:bg-\[\#1b1c27\]:hover,
        [data-theme="light"] .hover\:bg-\[\#252737\]:hover {
            background-color: #f8fafc !important;
        }
        [data-theme="light"] .products-list-modal {
            background: rgba(15, 23, 42, 0.45);
        }
        [data-theme="light"] .products-list-modal .products-modal-dialog {
            background: #ffffff !important;
            border-color: #e2e8f0 !important;
            color: #0f172a !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25) !important;
        }
        [data-theme="light"] #reportOrdersContainer::-webkit-scrollbar-track { background: #f8fafc; }
        [data-theme="light"] #reportOrdersContainer::-webkit-scrollbar-thumb { background: #cbd5e1; }
        [data-theme="light"] #reportOrdersContainer::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* Custom Scrollbar */
        ::-webkit-scrollbar,
        #reportOrdersContainer::-webkit-scrollbar { width: 7px; height: 7px; }
        ::-webkit-scrollbar-track,
        #reportOrdersContainer::-webkit-scrollbar-track { background: #14151e; }
        ::-webkit-scrollbar-thumb,
        #reportOrdersContainer::-webkit-scrollbar-thumb { background: #2c2d3e; border-radius: 9999px; }
        ::-webkit-scrollbar-thumb:hover,
        #reportOrdersContainer::-webkit-scrollbar-thumb:hover { background: #3e4056; }

        @media print {
            .print-hide, aside, #sidebar, .sidebar { display: none !important; }
            body, .app-layout, .app-main, .rep-page-wrapper { background: #fff !important; color: #000 !important; }
            #reportOrdersCard { border: none !important; box-shadow: none !important; }
        }

        .products-list-modal {
            position: fixed;
            inset: 0;
            z-index: 9999;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
        }

        .products-list-modal.active {
            display: flex !important;
            opacity: 1 !important;
            pointer-events: auto !important;
        }

        .products-list-modal .products-modal-dialog {
            background: #14151e;
            border-radius: 24px;
            padding: 24px 28px;
            width: 100%;
            max-width: 520px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6);
            border: 1px solid #28293d;
            color: #f8fafc;
            transform: scale(0.96) translateY(6px);
            transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .products-list-modal.active .products-modal-dialog {
            transform: scale(1) translateY(0) !important;
        }
    </style>
</head>
<body>
<div class="flex h-screen w-screen overflow-hidden app-layout">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <div class="app-main flex-1 h-screen overflow-hidden flex flex-col">
        <div class="rep-page-wrapper w-full h-full p-4 md:p-6 flex flex-col gap-4 overflow-hidden">

            <!-- TOP BREADCRUMBS & ACTION BUTTONS -->
            <div class="flex items-center justify-between gap-4 pb-0.5 flex-shrink-0 print-hide">
                <!-- Breadcrumbs -->
                <div class="flex items-center gap-2 text-xs md:text-sm font-medium">
                    <span class="text-[#8e8e9f]"><?= $isKm ? 'របាយការណ៍' : 'Reports' ?></span>
                    <i class="fa-solid fa-chevron-right text-[10px] text-[#606175]"></i>
                    <span class="text-[#8e8e9f]">Sales</span>
                    <i class="fa-solid fa-chevron-right text-[10px] text-[#606175]"></i>
                    <span class="text-white font-bold"><?= $isKm ? 'របាយការណ៍លក់' : 'Sales Report' ?></span>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-3">
                    <?php
                    $pdf_params = [
                        'from_date'      => $fromDate,
                        'to_date'        => $toDate,
                        'from_time'      => $fromTime,
                        'to_time'        => $toTime,
                        'user_id'        => $filterUser,
                        'payment_method' => ($paymentMethod !== 'all' && !empty($paymentMethod)) ? $paymentMethod : '',
                        'lang'           => current_lang()
                    ];
                    $pdf_url = 'daily_report_pdf.php?' . http_build_query($pdf_params);
                    ?>
                    <a href="<?= htmlspecialchars($pdf_url) ?>" target="_blank" 
                       class="inline-flex items-center gap-2 px-3.5 py-2 bg-[#14151e] hover:bg-[#1b1c27] text-white text-xs md:text-sm font-semibold rounded-xl border border-[#2c2d3e] hover:border-emerald-500/40 shadow-sm transition cursor-pointer">
                        <i class="fa-solid fa-print text-emerald-400 text-xs"></i>
                        <span><?= $isKm ? 'បោះពុម្ព (PDF)' : 'Print (PDF)' ?></span>
                    </a>
                </div>
            </div>

            <!-- FILTER CARD -->
            <div class="bg-[#14151e] border border-[#232433] rounded-2xl shadow-lg p-4 md:p-5 flex-shrink-0 print-hide">
                <form method="GET" action="daily_report.php" id="salesReportFilterForm" class="flex flex-col gap-3.5">
                    <!-- Row 1: 5 Filter Columns -->
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                        <!-- 1. Start Date & Time -->
                        <div class="flex flex-col gap-1">
                            <label class="text-[11px] font-bold text-[#8e8e9f]"><?= $isKm ? 'ចាប់ពីថ្ងៃ' : 'From Date & Time' ?></label>
                            <div class="relative">
                                <input type="datetime-local" id="fromDateTimeInput" name="from_datetime" value="<?= htmlspecialchars($fromDateTimeInputVal) ?>" onchange="this.form.submit()"
                                       class="w-full px-3 py-2 bg-[#1b1c27] border border-[#2c2d3e] rounded-xl text-xs font-semibold text-white focus:outline-none focus:border-[#10b981] focus:ring-2 focus:ring-emerald-500/20 transition cursor-pointer">
                            </div>
                        </div>

                        <!-- 2. End Date & Time -->
                        <div class="flex flex-col gap-1">
                            <label class="text-[11px] font-bold text-[#8e8e9f]"><?= $isKm ? 'ដល់ថ្ងៃ' : 'To Date & Time' ?></label>
                            <div class="relative">
                                <input type="datetime-local" id="toDateTimeInput" name="to_datetime" value="<?= htmlspecialchars($toDateTimeInputVal) ?>" onchange="this.form.submit()"
                                       class="w-full px-3 py-2 bg-[#1b1c27] border border-[#2c2d3e] rounded-xl text-xs font-semibold text-white focus:outline-none focus:border-[#10b981] focus:ring-2 focus:ring-emerald-500/20 transition cursor-pointer">
                            </div>
                        </div>

                        <!-- 3. Quick Range -->
                        <div class="flex flex-col gap-1">
                            <label class="text-[11px] font-bold text-[#8e8e9f]"><?= $isKm ? 'កាលបរិច្ឆេទលឿន' : 'Quick Range' ?></label>
                            <div class="relative">
                                <select name="quick_range" id="quickRangeSelect" onchange="handleQuickRangeChange(this.value)" 
                                        class="w-full appearance-none px-3 py-2 bg-[#1b1c27] border border-[#2c2d3e] rounded-xl text-xs font-semibold text-white pr-7 focus:outline-none focus:border-[#10b981] focus:ring-2 focus:ring-emerald-500/20 transition cursor-pointer">
                                    <option value="">-- <?= $isKm ? 'ជ្រើសរើស' : 'Select' ?> --</option>
                                    <option value="today" <?= $quickRange === 'today' ? 'selected' : '' ?>><?= $isKm ? 'ថ្ងៃនេះ (Today)' : 'Today' ?></option>
                                    <option value="yesterday" <?= $quickRange === 'yesterday' ? 'selected' : '' ?>><?= $isKm ? 'ម្សិលមិញ (Yesterday)' : 'Yesterday' ?></option>
                                    <option value="week" <?= $quickRange === 'week' ? 'selected' : '' ?>><?= $isKm ? 'សប្តាហ៍នេះ (This Week)' : 'This Week' ?></option>
                                    <option value="month" <?= $quickRange === 'month' ? 'selected' : '' ?>><?= $isKm ? 'ខែនេះ (This Month)' : 'This Month' ?></option>
                                    <option value="year" <?= $quickRange === 'year' ? 'selected' : '' ?>><?= $isKm ? 'ឆ្នាំនេះ (This Year)' : 'This Year' ?></option>
                                </select>
                                <i class="fa-solid fa-chevron-down absolute right-2.5 top-1/2 -translate-y-1/2 text-[#78788c] text-[10px] pointer-events-none"></i>
                            </div>
                        </div>

                        <!-- 4. Staff -->
                        <div class="flex flex-col gap-1">
                            <label class="text-[11px] font-bold text-[#8e8e9f]"><?= $isKm ? 'បុគ្គលិក (Staff)' : 'Staff' ?></label>
                            <div class="relative">
                                <select name="user_id" onchange="this.form.submit()" class="w-full appearance-none px-3 py-2 bg-[#1b1c27] border border-[#2c2d3e] rounded-xl text-xs font-semibold text-white pr-7 focus:outline-none focus:border-[#10b981] focus:ring-2 focus:ring-emerald-500/20 transition cursor-pointer">
                                    <option value="0">All Staff</option>
                                    <?php foreach ($staffList as $st): ?>
                                    <option value="<?= (int)$st['user_id'] ?>" <?= $filterUser === (int)$st['user_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($st['display_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="fa-solid fa-chevron-down absolute right-2.5 top-1/2 -translate-y-1/2 text-[#78788c] text-[10px] pointer-events-none"></i>
                            </div>
                        </div>

                        <!-- 5. Payment Method -->
                        <div class="flex flex-col gap-1">
                            <label class="text-[11px] font-bold text-[#8e8e9f]"><?= $isKm ? 'វិធីទូទាត់' : 'Payment Method' ?></label>
                            <div class="relative">
                                <select name="payment_method" onchange="this.form.submit()" class="w-full appearance-none px-3 py-2 bg-[#1b1c27] border border-[#2c2d3e] rounded-xl text-xs font-semibold text-white pr-7 focus:outline-none focus:border-[#10b981] focus:ring-2 focus:ring-emerald-500/20 transition cursor-pointer">
                                    <option value="all" <?= $paymentMethod === 'all' ? 'selected' : '' ?>>All Methods</option>
                                    <option value="Cash" <?= $paymentMethod === 'Cash' ? 'selected' : '' ?>>Cash</option>
                                    <option value="Bakong" <?= $paymentMethod === 'Bakong' ? 'selected' : '' ?>>Bakong</option>
                                    <option value="PayLater" <?= $paymentMethod === 'PayLater' ? 'selected' : '' ?>>Pay Later</option>
                                </select>
                                <i class="fa-solid fa-chevron-down absolute right-2.5 top-1/2 -translate-y-1/2 text-[#78788c] text-[10px] pointer-events-none"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Row 2: Search Input & Action Buttons -->
                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 pt-1 border-t border-[#232433]">
                        <!-- Left: Search Box -->
                        <div class="relative flex-1 max-w-sm">
                            <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-[#78788c] text-xs pointer-events-none"></i>
                            <input type="text" id="tableSearchInput" name="search" value="<?= htmlspecialchars($searchQuery) ?>" oninput="filterTableClientSide()"
                                   placeholder="<?= $isKm ? 'ស្វែងរកតាមលេខ Order, អតិថិជន...' : 'Search order #, customer...' ?>"
                                   class="w-full pl-9 pr-3 py-2 bg-[#1b1c27] border border-[#2c2d3e] rounded-xl text-xs font-medium text-white placeholder-[#68687a] focus:outline-none focus:border-[#10b981] focus:ring-2 focus:ring-emerald-500/20 transition">
                        </div>

                        <!-- Right: Reset & Filter Button -->
                        <div class="flex items-center justify-end gap-3.5">
                            <a href="daily_report.php" class="text-xs font-bold text-[#8e8e9f] hover:text-white transition cursor-pointer">
                                <?= $isKm ? 'កំណត់ឡើងវិញ' : 'Reset' ?>
                            </a>
                            <button type="submit" 
                                    class="inline-flex items-center gap-2 px-5 py-2 bg-gradient-to-r from-[#10b981] to-[#059669] hover:from-[#059669] hover:to-[#047857] text-white text-xs font-bold rounded-xl shadow-lg shadow-emerald-500/25 transition cursor-pointer">
                                <i class="fa-solid fa-filter text-[11px]"></i>
                                <span><?= $isKm ? 'ស្វែងរកទិន្នន័យ' : 'Filter' ?></span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- DATA TABLE CARD WITH VERTICAL SCROLL -->
            <div class="bg-[#14151e] border border-[#232433] rounded-2xl shadow-xl flex flex-col flex-1 min-h-0 overflow-hidden" id="reportOrdersCard">
                <div class="flex-1 min-h-0 overflow-y-auto overflow-x-auto w-full" id="reportOrdersContainer">
                    <table class="w-full text-left border-collapse" id="reportOrdersTable">
                        <thead class="sticky top-0 z-20 shadow-[0_2px_10px_rgba(0,0,0,0.1)] report-thead">
                            <tr class="border-b-2 border-[#10b981]">
                                <th class="py-3.5 px-6 text-xs font-bold uppercase tracking-wider"><?= $isKm ? 'លេខ ORDER' : 'ORDER NO' ?></th>
                                <th class="py-3.5 px-6 text-xs font-bold uppercase tracking-wider"><?= $isKm ? 'កាលបរិច្ឆេទ' : 'DATE & TIME' ?></th>
                                <th class="py-3.5 px-6 text-xs font-bold uppercase tracking-wider"><?= $isKm ? 'អតិថិជន' : 'CUSTOMER' ?></th>
                                <th class="py-3.5 px-6 text-xs font-bold uppercase tracking-wider"><?= $isKm ? 'តម្លៃ' : 'TOTAL' ?></th>
                                <th class="py-3.5 px-6 text-xs font-bold uppercase tracking-wider"><?= $isKm ? 'ថ្លៃដើម' : 'COST' ?></th>
                                <th class="py-3.5 px-6 text-xs font-bold uppercase tracking-wider"><?= $isKm ? 'ប្រាក់ចំណេញ' : 'PROFIT' ?></th>
                                <th class="py-3.5 px-6 text-center text-xs font-bold uppercase tracking-wider"><?= $isKm ? 'ចំនួនទំនិញ' : 'ITEMS' ?></th>
                                <th class="py-3.5 px-6 text-xs font-bold uppercase tracking-wider"><?= $isKm ? 'វិធីទូទាត់' : 'PAYMENT' ?></th>
                                <th class="py-3.5 px-6 text-xs font-bold uppercase tracking-wider"><?= $isKm ? 'អ្នកលក់' : 'CASHIER' ?></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#1e1f2c]">
                            <?php if (empty($ordersList)): ?>
                            <tr id="noDataRow">
                                <td colspan="9" class="text-center py-16 px-4 text-[#8e8e9f]">
                                    <div class="w-14 h-14 rounded-full bg-[#1b1c27] flex items-center justify-center mx-auto mb-3 text-[#78788c] text-xl">
                                        <i class="fa-solid fa-folder-open"></i>
                                    </div>
                                    <div class="font-bold text-white text-sm mb-1"><?= $isKm ? 'គ្មានទិន្នន័យការលក់' : 'No sales records found' ?></div>
                                    <div class="text-xs text-[#8e8e9f]"><?= $isKm ? 'សូមជ្រើសរើសកាលបរិច្ឆេទផ្សេង ឬកំណត់តម្រងឡើងវិញ' : 'Try adjusting the date range or filters' ?></div>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($ordersList as $o): ?>
                                <?php
                                    $orderNoPadded = '#' . str_pad((string)($o['daily_order_no'] ?? $o['order_id']), 4, '0', STR_PAD_LEFT);
                                    $dtFormatted   = date('G:i j/n/Y', strtotime($o['order_date']));
                                    $customerName  = !empty($o['customer_name']) ? $o['customer_name'] : 'Guest';
                                    $totalFormatted = '$' . number_format((float)$o['total'], 2);
                                    $costVal         = (float)($o['total_cost'] ?? 0);
                                    $costFormatted   = '$' . number_format($costVal, 2);
                                    $profitVal       = (float)($o['total_profit'] ?? 0);
                                    $profitFormatted = ($profitVal >= 0 ? '$' : '-$') . number_format(abs($profitVal), 2);
                                    $profitClass     = $profitVal < 0 ? 'text-rose-400 font-bold' : 'text-emerald-400 font-bold';
                                    $itemsCount      = (int)$o['total_items'];
                                    $sellerName      = $o['seller_name'];
                                    
                                    // Payment Method badge
                                    $pmLower = strtolower($o['payment_method'] ?? 'cash');
                                    if (strpos($pmLower, 'bakong') !== false || strpos($pmLower, 'khqr') !== false || strpos($pmLower, 'qr') !== false) {
                                        $pmBadge = '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-rose-500/15 border border-rose-500/30 text-rose-400 text-xs font-bold"><i class="fa-solid fa-qrcode text-[10px]"></i> Bakong</span>';
                                    } elseif (strpos($pmLower, 'later') !== false || strpos($pmLower, 'credit') !== false) {
                                        $pmBadge = '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-amber-500/15 border border-amber-500/30 text-amber-400 text-xs font-bold"><i class="fa-regular fa-clock text-[10px]"></i> Pay Later</span>';
                                    } else {
                                        $pmBadge = '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 text-xs font-bold"><i class="fa-solid fa-money-bill-wave text-[10px]"></i> Cash</span>';
                                    }
                                ?>
                                <tr class="border-b border-[#1e1f2c] hover:bg-[#191a26] transition-colors cursor-pointer" onclick="openReportOrderDetails(<?= (int)$o['order_id'] ?>)" title="<?= $isKm ? 'ចុចដើម្បីមើលព័ត៌មានលម្អិតមុខទំនិញ' : 'Click to view product details' ?>">
                                    <td class="py-4 px-6 text-xs font-bold text-white whitespace-nowrap">
                                        <span class="hover:text-emerald-400 transition-colors font-bold"><?= $orderNoPadded ?></span>
                                    </td>
                                    <td class="py-4 px-6 text-xs text-[#8e8e9f] font-medium whitespace-nowrap"><?= htmlspecialchars($dtFormatted) ?></td>
                                    <td class="py-4 px-6 text-xs font-semibold text-[#c7c7d4] whitespace-nowrap"><?= htmlspecialchars($customerName) ?></td>
                                    <td class="py-4 px-6 text-xs font-black text-white whitespace-nowrap"><?= $totalFormatted ?></td>
                                    <td class="py-4 px-6 text-xs font-semibold text-[#8e8e9f] whitespace-nowrap"><?= $costFormatted ?></td>
                                    <td class="py-4 px-6 text-xs whitespace-nowrap <?= $profitClass ?>"><?= $profitFormatted ?></td>
                                    <td class="py-4 px-6 text-center text-xs font-semibold text-[#c7c7d4] whitespace-nowrap">
                                        <span class="inline-flex items-center justify-center min-w-[24px] px-2 py-0.5 rounded-full bg-[#1b1c27] border border-[#2c2d3e] font-semibold text-xs text-white"><?= $itemsCount ?></span>
                                    </td>
                                    <td class="py-4 px-6 whitespace-nowrap"><?= $pmBadge ?></td>
                                    <td class="py-4 px-6 text-xs font-medium text-[#8e8e9f] whitespace-nowrap"><?= htmlspecialchars($sellerName) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- TABLE SUMMARY FOOTER -->
                <div class="p-4 md:p-5 bg-[#101118] border-t border-[#232433] flex flex-col md:flex-row items-start md:items-center justify-between gap-4 flex-shrink-0">
                    <!-- Left: Date Range & Order Count -->
                    <div class="flex flex-col gap-0.5 text-xs text-[#8e8e9f]">
                        <div><?= $isKm ? 'ចន្លោះកាលបរិច្ឆេទ:' : 'Date Range:' ?> <span class="font-bold text-white"><?= date('j/n/Y', strtotime($fromDate)) ?> — <?= date('j/n/Y', strtotime($toDate)) ?></span></div>
                        <div><?= $isKm ? 'ចំនួនប្រតិបត្តិការសរុប (Orders):' : 'Total Orders Count:' ?> <span class="font-bold text-white"><?= number_format($totalOrdersCount) ?></span></div>
                    </div>

                    <!-- Right: Total Items, Total Sales, Total Cost & Total Profit -->
                    <div class="flex items-center gap-8 self-end md:self-auto">
                        <div class="text-right">
                            <div class="text-[11px] font-semibold text-[#8e8e9f] uppercase tracking-wider"><?= $isKm ? 'ចំនួនទំនិញសរុប' : 'Total Items' ?></div>
                            <div class="text-xl md:text-2xl font-black text-white leading-tight"><?= number_format($totalItemsSold) ?></div>
                        </div>
                        <div class="text-right">
                            <div class="text-[11px] font-semibold text-[#8e8e9f] uppercase tracking-wider"><?= $isKm ? 'ការលក់សរុប' : 'Total Sales' ?></div>
                            <div class="text-xl md:text-2xl font-black text-white leading-tight"><?= '$' . number_format($totalSalesAmount, 2) ?></div>
                        </div>
                        <div class="text-right">
                            <div class="text-[11px] font-semibold text-[#8e8e9f] uppercase tracking-wider"><?= $isKm ? 'ថ្លៃដើមសរុប' : 'Total Cost' ?></div>
                            <div class="text-xl md:text-2xl font-black text-[#8e8e9f] leading-tight"><?= '$' . number_format($totalCostAmount, 2) ?></div>
                        </div>
                        <div class="text-right">
                            <div class="text-[11px] font-semibold text-[#8e8e9f] uppercase tracking-wider"><?= $isKm ? 'ប្រាក់ចំណេញសរុប' : 'Total Profit' ?></div>
                            <?php 
                                $totalProfitFmt   = ($totalProfitAmount >= 0 ? '$' : '-$') . number_format(abs($totalProfitAmount), 2);
                                $totalProfitClass = $totalProfitAmount < 0 ? 'text-rose-400' : 'text-emerald-400';
                            ?>
                            <div class="text-xl md:text-2xl font-black <?= $totalProfitClass ?> leading-tight"><?= $totalProfitFmt ?></div>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /.rep-page-wrapper -->
    </div><!-- /.app-main -->
</div><!-- /.app-layout -->

<!-- Order Products List Modal -->
<div class="products-list-modal" id="orderProductsModal" onclick="handleProductsModalBackdrop(event)">
    <div class="products-modal-dialog relative flex flex-col max-h-[90vh]" onclick="event.stopPropagation()">
        <div id="orderProductsModalContent" class="flex flex-col h-full overflow-hidden"></div>
    </div>
</div>

<script>
const reportOrders = <?= json_encode($ordersList, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const reportOrdersMap = {};
(reportOrders || []).forEach(o => {
    reportOrdersMap[Number(o.order_id)] = o;
});

function openReportOrderDetails(orderId) {
    const o = reportOrdersMap[Number(orderId)];
    if (!o || !o.items || o.items.length === 0) {
        fetchOrderAndOpen(orderId);
        return;
    }
    renderOrderProductsModal(o);
}

async function fetchOrderAndOpen(orderId) {
    try {
        const res = await fetch('daily_report.php?action=order_details&order_id=' + Number(orderId));
        const data = await res.json();
        if (data && data.success && data.order) {
            reportOrdersMap[Number(orderId)] = data.order;
            renderOrderProductsModal(data.order);
        }
    } catch(e) {
        console.error("Error fetching order details:", e);
    }
}

function renderOrderProductsModal(o) {
    const modal = document.getElementById('orderProductsModal');
    const content = document.getElementById('orderProductsModalContent');
    if (!modal || !content) return;

    const currentIsKm = <?= json_encode($isKm) ?>;
    const orderNoPadded = '#' + String(o.daily_order_no || o.order_id || '').padStart(4, '0');
    
    // Format date & time
    let dtFormatted = o.order_date;
    try {
        const d = new Date(o.order_date.replace(/-/g, '/'));
        if (!isNaN(d.getTime())) {
            const yyyy = d.getFullYear();
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            const dd = String(d.getDate()).padStart(2, '0');
            let h = d.getHours();
            const min = String(d.getMinutes()).padStart(2, '0');
            const ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12;
            h = h ? h : 12;
            dtFormatted = `${yyyy}-${mm}-${dd} ${String(h).padStart(2, '0')}:${min} ${ampm}`;
        }
    } catch(e) {}

    const staffName = o.seller_name || 'Staff';
    const grandTotal = parseFloat(o.total || 0);
    const promoDisc = parseFloat(o.promotion_discount || 0);
    const manualDisc = parseFloat(o.manual_discount || 0);
    const totalDisc = promoDisc + manualDisc;
    const subtotalCalc = (totalDisc > 0) ? (grandTotal + totalDisc) : grandTotal;

    const itemsRowsHtml = (o.items || []).map((i) => {
        const unitPrice = parseFloat(i.price || 0);
        const qty = parseInt(i.quantity, 10) || 1;
        const lineTotal = unitPrice * qty;

        const customOpts = [i.size, i.sweetness, i.ice, i.milk].concat(i.addons || []).filter(Boolean).join(', ');
        const subtextHtml = customOpts ? `<div class="text-[11px] text-[#8e8e9f] mt-0.5">${escapeHtml(customOpts)}</div>` : '';

        return `
            <tr class="border-b border-[#1e1f2c] last:border-b-0">
                <td class="py-3 px-1 text-left">
                    <div class="font-normal text-white text-xs md:text-sm leading-snug">${escapeHtml(i.product_name || i.name || 'Product')}</div>
                    ${subtextHtml}
                </td>
                <td class="py-3 px-3 text-center">
                    <span class="inline-flex items-center justify-center min-w-[24px] h-6 px-2 rounded-lg bg-[#1b1c27] border border-[#2c2d3e] text-white font-normal text-xs">${qty}</span>
                </td>
                <td class="py-3 px-3 text-right text-[#8e8e9f] font-normal whitespace-nowrap text-xs">
                    $${unitPrice.toFixed(2)}
                </td>
                <td class="py-3 px-1 text-right font-bold text-white whitespace-nowrap text-xs md:text-sm">
                    $${lineTotal.toFixed(2)}
                </td>
            </tr>
        `;
    }).join('');

    content.innerHTML = `
        <!-- Modal Header -->
        <div class="flex items-start justify-between pb-3 border-b border-[#232433] flex-shrink-0">
            <div>
                <div class="flex items-center gap-2.5">
                    <h3 class="text-base font-extrabold text-white">
                        ${currentIsKm ? 'ព័ត៌មានលម្អិតមុខទំនិញ' : 'Order Product Details'}
                    </h3>
                    <span class="px-2.5 py-0.5 rounded-lg bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 text-xs font-bold tracking-wide">
                        ${escapeHtml(orderNoPadded)}
                    </span>
                </div>
                <div class="text-xs text-[#8e8e9f] font-normal mt-1 flex items-center gap-1.5 flex-wrap">
                    <span>${escapeHtml(dtFormatted)}</span>
                    <span>•</span>
                    <span>${currentIsKm ? 'បង្កើតដោយ' : 'Created by'}: ${escapeHtml(staffName)}</span>
                </div>
            </div>
            <button type="button" onclick="closeOrderProductsModal()" class="w-7 h-7 rounded-lg hover:bg-[#1b1c27] text-[#8e8e9f] hover:text-white inline-flex items-center justify-center transition cursor-pointer" title="Close">
                <i class="fa-solid fa-xmark text-sm"></i>
            </button>
        </div>

        <!-- Scrollable Product Table -->
        <div class="overflow-y-auto max-h-[50vh] my-2 min-h-0">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-t border-[#232433] text-[11px] text-[#8e8e9f] font-bold uppercase tracking-wider">
                        <th class="py-2.5 px-1 text-left font-bold">${currentIsKm ? 'មុខទំនិញ (ITEM)' : 'ITEM'}</th>
                        <th class="py-2.5 px-3 text-center font-bold">${currentIsKm ? 'ចំនួន (QTY)' : 'QTY'}</th>
                        <th class="py-2.5 px-3 text-right font-bold">${currentIsKm ? 'តម្លៃរាយ' : 'PRICE'}</th>
                        <th class="py-2.5 px-1 text-right font-bold">${currentIsKm ? 'សរុប' : 'TOTAL'}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#1e1f2c]">
                    ${itemsRowsHtml || `<tr><td colspan="4" class="text-center py-6 text-[#8e8e9f] text-xs">${currentIsKm ? 'មិនមានមុខទំនិញទេ' : 'No items found'}</td></tr>`}
                </tbody>
            </table>
        </div>

        <!-- Totals Calculation -->
        <div class="pt-3 border-t border-[#232433] space-y-1.5 text-xs flex-shrink-0">
            ${totalDisc > 0 ? `
            <div class="flex justify-between items-center text-[#8e8e9f]">
                <span>${currentIsKm ? 'ការបញ្ចុះតម្លៃ (Discount)' : 'Discount'}</span>
                <span class="font-bold text-rose-400">-$${totalDisc.toFixed(2)}</span>
            </div>
            ` : ''}
            <div class="flex justify-between items-center pt-1">
                <span class="text-xs md:text-sm font-black text-white">${currentIsKm ? 'តម្លៃសរុបចុងក្រោយ (Grand Total)' : 'Grand Total'}</span>
                <span class="text-sm md:text-base font-black text-emerald-400">$${grandTotal.toFixed(2)}</span>
            </div>
        </div>

        <!-- Footer Action Buttons -->
        <div class="mt-5 pt-2 flex items-center justify-end gap-3 flex-shrink-0">
            <button type="button" onclick="closeOrderProductsModal()" class="px-5 py-2 rounded-xl bg-[#1b1c27] hover:bg-[#252737] border border-[#2c2d3e] text-[#c7c7d4] hover:text-white text-xs font-bold transition shadow-sm cursor-pointer">
                ${currentIsKm ? 'បិទ (Close)' : 'Close'}
            </button>
            <button type="button" onclick="printReceipt(${Number(o.order_id)})" class="px-5 py-2 rounded-xl bg-gradient-to-r from-[#10b981] to-[#059669] hover:from-[#059669] hover:to-[#047857] text-white text-xs font-bold shadow-lg shadow-emerald-500/25 transition flex items-center gap-1.5 cursor-pointer">
                <i class="fa-solid fa-print text-xs"></i>
                <span>${currentIsKm ? 'បោះពុម្ព (Print)' : 'Print'}</span>
            </button>
        </div>
    `;

    modal.classList.add('active');
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function closeOrderProductsModal() {
    const modal = document.getElementById('orderProductsModal');
    if (modal) modal.classList.remove('active');
}

function handleProductsModalBackdrop(e) {
    if (e.target.id === 'orderProductsModal') {
        closeOrderProductsModal();
    }
}

function printReceipt(orderId) {
    if (!orderId) return;
    const url = 'receipt_print.php?order_id=' + Number(orderId) + '&auto_print=1';
    window.open(url, 'receipt_win', 'width=450,height=700,scrollbars=yes');
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeOrderProductsModal();
    }
});

function handleQuickRangeChange(val) {
    if (!val) return;
    const today = new Date();
    const yyyy = today.getFullYear();
    const mm = String(today.getMonth() + 1).padStart(2, '0');
    const dd = String(today.getDate()).padStart(2, '0');
    const todayStr = `${yyyy}-${mm}-${dd}`;
    
    let fromStr = todayStr;
    let toStr = todayStr;
    
    if (val === 'today') {
        fromStr = todayStr;
        toStr = todayStr;
    } else if (val === 'yesterday') {
        const yest = new Date(today);
        yest.setDate(today.getDate() - 1);
        const yMm = String(yest.getMonth() + 1).padStart(2, '0');
        const yDd = String(yest.getDate()).padStart(2, '0');
        fromStr = `${yest.getFullYear()}-${yMm}-${yDd}`;
        toStr = fromStr;
    } else if (val === 'week') {
        const day = today.getDay();
        const diffToMon = (day === 0 ? -6 : 1 - day);
        const monDate = new Date(today);
        monDate.setDate(today.getDate() + diffToMon);
        const sunDate = new Date(monDate);
        sunDate.setDate(monDate.getDate() + 6);
        const pad = (n) => String(n).padStart(2, '0');
        fromStr = `${monDate.getFullYear()}-${pad(monDate.getMonth() + 1)}-${pad(monDate.getDate())}`;
        toStr = `${sunDate.getFullYear()}-${pad(sunDate.getMonth() + 1)}-${pad(sunDate.getDate())}`;
    } else if (val === 'month') {
        fromStr = `${yyyy}-${mm}-01`;
        toStr = todayStr;
    } else if (val === 'year') {
        fromStr = `${yyyy}-01-01`;
        toStr = todayStr;
    }
    
    const fDt = document.getElementById('fromDateTimeInput');
    const tDt = document.getElementById('toDateTimeInput');
    if (fDt) fDt.value = `${fromStr}T00:00`;
    if (tDt) tDt.value = `${toStr}T23:59`;
    
    const fD = document.getElementById('fromDateInput');
    const tD = document.getElementById('toDateInput');
    if (fD) fD.value = fromStr;
    if (tD) tD.value = toStr;
    
    document.getElementById('salesReportFilterForm').submit();
}

function filterTableClientSide() {
    const query = (document.getElementById('tableSearchInput').value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('#reportOrdersTable tbody tr');
    let visibleCount = 0;
    
    rows.forEach(tr => {
        if (tr.id === 'noDataRow') return;
        const text = tr.innerText.toLowerCase();
        if (text.includes(query)) {
            tr.style.display = '';
            visibleCount++;
        } else {
            tr.style.display = 'none';
        }
    });
    
    const noDataRow = document.getElementById('noDataRow');
    if (noDataRow) {
        noDataRow.style.display = visibleCount === 0 ? '' : 'none';
    }
}
</script>
</body>
</html>
