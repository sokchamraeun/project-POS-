<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lang.php';

if (!can('report_product') && !can('report_sale') && !can('sales_report') && !can('report')) {
    header("Location: dashboard.php?denied=1");
    exit;
}

date_default_timezone_set("Asia/Phnom_Penh");
$isKm = (current_lang() === 'km');
$_is_mgr = in_array($_SESSION['role'] ?? '', ['admin', 'manager']);

// ── Date Filters Setup ──
$today = business_date_today();
$currentYear = date('Y', strtotime($today));
$quickRange = $_GET['quick_range'] ?? 'month';
$selectedMonth = $_GET['month_select'] ?? '';
$fromDateTimeParam = trim($_GET['from_datetime'] ?? '');
$toDateTimeParam   = trim($_GET['to_datetime']   ?? '');

if (!empty($fromDateTimeParam)) {
    $parts = explode('T', str_replace(' ', 'T', $fromDateTimeParam));
    $fromDate = $parts[0] ?? $today;
    $fromTime = $parts[1] ?? '00:00';
} else {
    $fromDate = trim($_GET['from_date'] ?? $_GET['date_from'] ?? $_GET['from'] ?? '');
    $fromTime = trim($_GET['from_time'] ?? '');
}

if (!empty($toDateTimeParam)) {
    $parts = explode('T', str_replace(' ', 'T', $toDateTimeParam));
    $toDate = $parts[0] ?? $today;
    $toTime = $parts[1] ?? '23:59';
} else {
    $toDate = trim($_GET['to_date'] ?? $_GET['date_to'] ?? $_GET['to'] ?? '');
    $toTime = trim($_GET['to_time'] ?? '');
}

// If a specific month is chosen, override fromDate & toDate
if (!empty($selectedMonth) && preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $fromDate = $selectedMonth . '-01';
    $toDate   = date('Y-m-t', strtotime($fromDate));
} elseif (empty($fromDate) || empty($toDate)) {
    if ($quickRange === 'today') {
        $fromDate = $today;
        $toDate   = $today;
    } elseif ($quickRange === 'yesterday') {
        $fromDate = date('Y-m-d', strtotime($today . ' -1 day'));
        $toDate   = date('Y-m-d', strtotime($today . ' -1 day'));
    } elseif ($quickRange === 'week' || $quickRange === 'this_week') {
        $fromDate = date('Y-m-d', strtotime('monday this week', strtotime($today)));
        $toDate   = date('Y-m-d', strtotime('sunday this week', strtotime($today)));
    } elseif ($quickRange === 'year' || $quickRange === 'this_year') {
        $fromDate = date('Y-01-01', strtotime($today));
        $toDate   = date('Y-12-31', strtotime($today));
    } elseif ($quickRange === 'all') {
        $fromDate = '2020-01-01';
        $toDate   = date('Y-m-d', strtotime($today . ' +1 day'));
    } else { // default 'month'
        $fromDate = date('Y-m-01', strtotime($today));
        $toDate   = $today;
        $quickRange = 'month';
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

$timeShift      = trim($_GET['time_shift'] ?? '');
$filterUser     = (int)($_GET['user_id'] ?? $_GET['user'] ?? 0);
if (!$_is_mgr) {
    $filterUser = (int)$_SESSION['user_id'];
}
$filterCategory = trim($_GET['category'] ?? '');
$searchQuery    = trim($_GET['search'] ?? '');

// ── Fetch Staff & Categories for Filter Dropdowns ──
$staffList = [];
$q_staff = $conn->query("SELECT user_id, username, COALESCE(NULLIF(name, ''), username) AS display_name FROM users ORDER BY username ASC");
if ($q_staff) {
    while ($sr = $q_staff->fetch_assoc()) {
        $staffList[] = $sr;
    }
}

$categoryList = [];
$q_cat = $conn->query("SELECT category_id, name, slug FROM categories ORDER BY display_order ASC, name ASC");
if ($q_cat) {
    while ($cr = $q_cat->fetch_assoc()) {
        $categoryList[] = $cr;
    }
}

// ── Build Database Query for Product Sales & Profit Analysis ──
$whereClauses = [
    "o.order_date BETWEEN ? AND ?",
    "oc.order_id IS NULL",
    paid_orders_where('o')
];
$bindTypes    = "ss";
$bindParams   = [$fromDateTime, $toDateTime];

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

if (!empty($filterCategory)) {
    $whereClauses[] = "(c.slug = ? OR c.name = ? OR p.category = ?)";
    $bindTypes   .= "sss";
    $bindParams[] = $filterCategory;
    $bindParams[] = $filterCategory;
    $bindParams[] = $filterCategory;
}

if (!empty($searchQuery)) {
    $whereClauses[] = "(p.name LIKE ? OR oi.product_name LIKE ? OR c.name LIKE ? OR p.category LIKE ?)";
    $sParam = "%$searchQuery%";
    $bindTypes .= "ssss";
    $bindParams[] = $sParam;
    $bindParams[] = $sParam;
    $bindParams[] = $sParam;
    $bindParams[] = $sParam;
}

$whereSql = implode(" AND ", $whereClauses);

$sql = "
    SELECT 
        COALESCE(p.product_id, oi.product_id) AS product_id,
        COALESCE(p.name, oi.product_name) AS product_name,
        COALESCE(NULLIF(c.name, ''), NULLIF(p.category, ''), 'Other') AS category_name,
        COALESCE(p.cost_price, 0) AS cost_per_unit,
        CASE 
            WHEN SUM(oi.quantity) > 0 THEN SUM(oi.quantity * oi.price) / SUM(oi.quantity) 
            ELSE COALESCE(p.price, 0) 
        END AS avg_selling_price,
        SUM(oi.quantity) AS total_qty_sold,
        SUM(oi.quantity * oi.price) AS total_revenue,
        SUM(oi.quantity * COALESCE(p.cost_price, 0)) AS total_cost,
        (SUM(oi.quantity * oi.price) - SUM(oi.quantity * COALESCE(p.cost_price, 0))) AS total_profit
    FROM order_items oi
    JOIN orders o ON o.order_id = oi.order_id
    LEFT JOIN products p ON p.product_id = oi.product_id
    LEFT JOIN categories c ON (c.category_id = p.category_id OR c.slug = p.category OR c.name = p.category)
    LEFT JOIN order_cancellations oc ON oc.order_id = o.order_id
    WHERE {$whereSql}
    GROUP BY COALESCE(p.product_id, oi.product_id), COALESCE(p.name, oi.product_name), COALESCE(NULLIF(c.name, ''), NULLIF(p.category, ''), 'Other'), p.cost_price, p.price
    ORDER BY total_qty_sold DESC, total_revenue DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Database query error: " . $conn->error);
}
if ($bindTypes !== "") {
    $stmt->bind_param($bindTypes, ...$bindParams);
}
$stmt->execute();
$result = $stmt->get_result();

$productsList = [];
$totalDistinctProducts = 0;
$totalUnitsSold = 0;
$totalRevenue = 0.0;
$totalCost = 0.0;
$totalProfit = 0.0;

while ($row = $result->fetch_assoc()) {
    $productsList[] = $row;
    $totalDistinctProducts++;
    $totalUnitsSold += (int)$row['total_qty_sold'];
    $totalRevenue   += (float)$row['total_revenue'];
    $totalCost      += (float)$row['total_cost'];
    $totalProfit    += (float)$row['total_profit'];
}

// ── Export CSV Handler ──
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="product_analytics_report_' . $fromDate . '_to_' . $toDate . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Product Name', 'Category', 'Qty Sold', 'Cost / Unit ($)', 'Price / Unit ($)', 'Total Revenue ($)', 'Total Cost ($)', 'Profit ($)']);
    foreach ($productsList as $p) {
        fputcsv($out, [
            $p['product_name'],
            $p['category_name'],
            $p['total_qty_sold'],
            number_format((float)$p['cost_per_unit'], 2, '.', ''),
            number_format((float)$p['avg_selling_price'], 2, '.', ''),
            number_format((float)$p['total_revenue'], 2, '.', ''),
            number_format((float)$p['total_cost'], 2, '.', ''),
            number_format((float)$p['total_profit'], 2, '.', '')
        ]);
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>" data-lang="<?= current_lang() ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $isKm ? 'ការវិភាគ & នាំចេញ' : 'Analytics & Export' ?> | Bird's Nest Coffee</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>(function(){try{if(localStorage.getItem("theme")==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap" rel="stylesheet">
    
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
        }
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
        [data-theme="light"] thead tr.bg-\[\#101118\] {
            background-color: #f8fafc !important;
        }
        [data-theme="light"] th {
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
        [data-theme="light"] #reportOrdersContainer::-webkit-scrollbar-track { background: #f8fafc; }
        [data-theme="light"] #reportOrdersContainer::-webkit-scrollbar-thumb { background: #cbd5e1; }
        [data-theme="light"] #reportOrdersContainer::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* Custom Scrollbar matching Daily Summary */
        ::-webkit-scrollbar,
        #reportOrdersContainer::-webkit-scrollbar { width: 7px; height: 7px; }
        ::-webkit-scrollbar-track,
        #reportOrdersContainer::-webkit-scrollbar-track { background: #14151e; }
        ::-webkit-scrollbar-thumb,
        #reportOrdersContainer::-webkit-scrollbar-thumb {
            background: #2c2d3e;
            border-radius: 9999px;
        }
        ::-webkit-scrollbar-thumb:hover,
        #reportOrdersContainer::-webkit-scrollbar-thumb:hover { background: #3e4056; }

        @media print {
            .sidebar, .print-hide, aside, #sidebar { display: none !important; }
            .app-main { overflow: visible !important; height: auto !important; padding: 0 !important; }
            body, .app-layout, .rep-page-wrapper { background: #fff !important; color: #000 !important; }
            #reportOrdersCard { border: none !important; box-shadow: none !important; }
            #reportOrdersContainer { max-height: none !important; overflow: visible !important; }
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
                    <span class="text-[#8e8e9f]"><?= $isKm ? 'ការលក់' : 'Sales' ?></span>
                    <i class="fa-solid fa-chevron-right text-[10px] text-[#606175]"></i>
                    <span class="text-white font-bold"><?= $isKm ? 'ការវិភាគ & នាំចេញ' : 'Analytics & Export' ?></span>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-3">
                    <?php
                    $pdf_params = [
                        'from_date' => $fromDate,
                        'to_date'   => $toDate,
                        'category'  => $filterCategory,
                        'lang'      => current_lang()
                    ];
                    $pdf_url = 'report_pdf.php?' . http_build_query($pdf_params);
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
                <form method="GET" action="report.php" id="analyticsReportFilterForm" class="flex flex-col gap-3.5">
                    <!-- Row 1: 6 Filter Columns -->
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
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

                        <!-- 3. Select Month -->
                        <div class="flex flex-col gap-1">
                            <label class="text-[11px] font-bold text-[#8e8e9f]"><?= $isKm ? 'ជ្រើសរើសខែ' : 'Select Month' ?></label>
                            <div class="relative">
                                <select name="month_select" id="monthSelect" onchange="handleMonthSelect(this.value)" 
                                        class="w-full appearance-none px-3 py-2 bg-[#1b1c27] border border-[#2c2d3e] rounded-xl text-xs font-semibold text-white pr-7 focus:outline-none focus:border-[#10b981] focus:ring-2 focus:ring-emerald-500/20 transition cursor-pointer">
                                    <option value="">-- <?= $isKm ? 'គ្រប់ខែ' : 'All Months' ?> --</option>
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <?php 
                                            $mStr = str_pad((string)$m, 2, '0', STR_PAD_LEFT);
                                            $mVal = $currentYear . '-' . $mStr;
                                            $mNamesKm = ['', 'មករា', 'កុម្ភៈ', 'មីនា', 'មេសា', 'ឧសភា', 'មិថុនា', 'កក្កដា', 'សីហា', 'កញ្ញា', 'តុលា', 'វិច្ឆិកា', 'ធ្នូ'];
                                            $mLabel = $isKm ? ($mStr . ' (' . $mNamesKm[$m] . ')') : date('F', mktime(0, 0, 0, $m, 10));
                                        ?>
                                        <option value="<?= $mVal ?>" <?= $selectedMonth === $mVal ? 'selected' : '' ?>><?= $mLabel ?></option>
                                    <?php endfor; ?>
                                </select>
                                <i class="fa-solid fa-chevron-down absolute right-2.5 top-1/2 -translate-y-1/2 text-[#78788c] text-[10px] pointer-events-none"></i>
                            </div>
                        </div>

                        <!-- 4. Quick Range -->
                        <div class="flex flex-col gap-1">
                            <label class="text-[11px] font-bold text-[#8e8e9f]"><?= $isKm ? 'កាលបរិច្ឆេទលឿន' : 'Quick Range' ?></label>
                            <div class="relative">
                                <select name="quick_range" id="quickRangeSelect" onchange="handleQuickRangeChange(this.value)" 
                                        class="w-full appearance-none px-3 py-2 bg-[#1b1c27] border border-[#2c2d3e] rounded-xl text-xs font-semibold text-white pr-7 focus:outline-none focus:border-[#10b981] focus:ring-2 focus:ring-emerald-500/20 transition cursor-pointer">
                                    <option value="">-- <?= $isKm ? 'ជ្រើសរើស' : 'Select' ?> --</option>
                                    <option value="today" <?= $quickRange === 'today' ? 'selected' : '' ?>><?= $isKm ? 'ថ្ងៃនេះ (Today)' : 'Today' ?></option>
                                    <option value="yesterday" <?= $quickRange === 'yesterday' ? 'selected' : '' ?>><?= $isKm ? 'ម្សិលមិញ (Yesterday)' : 'Yesterday' ?></option>
                                    <option value="week" <?= ($quickRange === 'week' || $quickRange === 'this_week') ? 'selected' : '' ?>><?= $isKm ? 'សប្តាហ៍នេះ (This Week)' : 'This Week' ?></option>
                                    <option value="month" <?= $quickRange === 'month' ? 'selected' : '' ?>><?= $isKm ? 'ខែនេះ (This Month)' : 'This Month' ?></option>
                                    <option value="year" <?= ($quickRange === 'year' || $quickRange === 'this_year') ? 'selected' : '' ?>><?= $isKm ? 'ឆ្នាំនេះ (This Year)' : 'This Year' ?></option>
                                    <option value="all" <?= $quickRange === 'all' ? 'selected' : '' ?>><?= $isKm ? 'ទាំងអស់ (All Time)' : 'All Time' ?></option>
                                </select>
                                <i class="fa-solid fa-chevron-down absolute right-2.5 top-1/2 -translate-y-1/2 text-[#78788c] text-[10px] pointer-events-none"></i>
                            </div>
                        </div>

                        <!-- 5. Staff -->
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

                        <!-- 6. Category -->
                        <div class="flex flex-col gap-1">
                            <label class="text-[11px] font-bold text-[#8e8e9f]"><?= $isKm ? 'ប្រភេទទំនិញ' : 'Category' ?></label>
                            <div class="relative">
                                <select name="category" onchange="this.form.submit()" class="w-full appearance-none px-3 py-2 bg-[#1b1c27] border border-[#2c2d3e] rounded-xl text-xs font-semibold text-white pr-7 focus:outline-none focus:border-[#10b981] focus:ring-2 focus:ring-emerald-500/20 transition cursor-pointer">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categoryList as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat['slug'] ?: $cat['name']) ?>" <?= $filterCategory === ($cat['slug'] ?: $cat['name']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
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
                                   placeholder="<?= $isKm ? 'ស្វែងរកទំនិញ, ប្រភេទ...' : 'Search product, category...' ?>"
                                   class="w-full pl-9 pr-3 py-2 bg-[#1b1c27] border border-[#2c2d3e] rounded-xl text-xs font-medium text-white placeholder-[#68687a] focus:outline-none focus:border-[#10b981] focus:ring-2 focus:ring-emerald-500/20 transition">
                        </div>

                        <!-- Right: Reset & Filter Button -->
                        <div class="flex items-center justify-end gap-3.5">
                            <a href="report.php" class="text-xs font-bold text-[#8e8e9f] hover:text-white transition cursor-pointer">
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
                        <thead class="sticky top-0 bg-[#101118] z-20 shadow-[0_1px_0_0_#232433]">
                            <tr class="border-b border-[#232433] bg-[#101118]">
                                <th class="py-3.5 px-6 text-xs font-bold text-[#8e8e9f] uppercase tracking-wider"><?= $isKm ? 'ឈ្មោះទំនិញ (PRODUCT)' : 'PRODUCT NAME' ?></th>
                                <th class="py-3.5 px-6 text-center text-xs font-bold text-[#8e8e9f] uppercase tracking-wider"><?= $isKm ? 'ប្រភេទ' : 'CATEGORY' ?></th>
                                <th class="py-3.5 px-6 text-center text-xs font-bold text-[#8e8e9f] uppercase tracking-wider"><?= $isKm ? 'ចំនួនលក់' : 'QTY SOLD' ?></th>
                                <th class="py-3.5 px-6 text-right text-xs font-bold text-[#8e8e9f] uppercase tracking-wider"><?= $isKm ? 'ថ្លៃដើម' : 'COST / UNIT' ?></th>
                                <th class="py-3.5 px-6 text-right text-xs font-bold text-[#8e8e9f] uppercase tracking-wider"><?= $isKm ? 'តម្លៃលក់' : 'PRICE / UNIT' ?></th>
                                <th class="py-3.5 px-6 text-right text-xs font-bold text-[#8e8e9f] uppercase tracking-wider"><?= $isKm ? 'ចំណូលសរុប (REV)' : 'REVENUE' ?></th>
                                <th class="py-3.5 px-6 text-right text-xs font-bold text-[#8e8e9f] uppercase tracking-wider"><?= $isKm ? 'ថ្លៃដើមសរុប (COST)' : 'TOTAL COST' ?></th>
                                <th class="py-3.5 px-6 text-right text-xs font-bold text-[#8e8e9f] uppercase tracking-wider"><?= $isKm ? 'ប្រាក់ចំណេញ (PROFIT)' : 'PROFIT' ?></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#1e1f2c]">
                            <?php if (empty($productsList)): ?>
                            <tr id="noDataRow">
                                <td colspan="8" class="text-center py-16 px-4 text-[#8e8e9f]">
                                    <div class="w-14 h-14 rounded-full bg-[#1b1c27] flex items-center justify-center mx-auto mb-3 text-[#78788c] text-xl">
                                        <i class="fa-solid fa-folder-open"></i>
                                    </div>
                                    <div class="font-bold text-white text-sm mb-1"><?= $isKm ? 'គ្មានទិន្នន័យការលក់' : 'No product sales recorded' ?></div>
                                    <div class="text-xs text-[#8e8e9f]"><?= $isKm ? 'សូមជ្រើសរើសកាលបរិច្ឆេទផ្សេង ឬកំណត់តម្រងឡើងវិញ' : 'Try adjusting the date range or filters' ?></div>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($productsList as $p): ?>
                                <?php
                                    $pName       = htmlspecialchars($p['product_name']);
                                    $catName     = htmlspecialchars($p['category_name']);
                                    $qtySold     = (int)$p['total_qty_sold'];
                                    $costUnit    = (float)$p['cost_per_unit'];
                                    $priceUnit   = (float)$p['avg_selling_price'];
                                    $revTotal    = (float)$p['total_revenue'];
                                    $costTotal   = (float)$p['total_cost'];
                                    $profit      = (float)$p['total_profit'];
                                    $isProfitPos = ($profit >= 0);
                                ?>
                                <tr class="border-b border-[#1e1f2c] hover:bg-[#191a26] transition-colors product-item-row">
                                    <!-- 1. Product Name with Dot Indicator -->
                                    <td class="py-4 px-6 text-xs font-bold text-white whitespace-nowrap">
                                        <div class="flex items-center gap-2.5">
                                            <span class="w-2 h-2 rounded-full bg-emerald-400 shrink-0 shadow-xs"></span>
                                            <span><?= $pName ?></span>
                                        </div>
                                    </td>
                                    <!-- 2. Category Pill Badge -->
                                    <td class="py-4 px-6 text-center whitespace-nowrap">
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 text-xs font-bold">
                                            <?= $catName ?>
                                        </span>
                                    </td>
                                    <!-- 3. Qty Sold -->
                                    <td class="py-4 px-6 text-center text-xs font-black text-white whitespace-nowrap">
                                        <?= number_format($qtySold) ?>
                                    </td>
                                    <!-- 4. Cost / Unit -->
                                    <td class="py-4 px-6 text-right text-xs font-semibold text-[#8e8e9f] whitespace-nowrap">
                                        $<?= number_format($costUnit, 2) ?>
                                    </td>
                                    <!-- 5. Price / Unit -->
                                    <td class="py-4 px-6 text-right text-xs font-semibold text-[#c7c7d4] whitespace-nowrap">
                                        $<?= number_format($priceUnit, 2) ?>
                                    </td>
                                    <!-- 6. Total Revenue -->
                                    <td class="py-4 px-6 text-right text-xs font-black text-white whitespace-nowrap">
                                        $<?= number_format($revTotal, 2) ?>
                                    </td>
                                    <!-- 7. Total Cost -->
                                    <td class="py-4 px-6 text-right text-xs font-semibold text-[#8e8e9f] whitespace-nowrap">
                                        $<?= number_format($costTotal, 2) ?>
                                    </td>
                                    <!-- 8. Profit -->
                                    <td class="py-4 px-6 text-right text-xs font-black whitespace-nowrap <?= $isProfitPos ? 'text-emerald-400' : 'text-rose-400' ?>">
                                        <?= ($isProfitPos ? '+' : '') . '$' . number_format($profit, 2) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- TABLE SUMMARY FOOTER -->
                <div class="p-4 md:p-5 bg-[#101118] border-t border-[#232433] flex flex-col md:flex-row items-start md:items-center justify-between gap-4 flex-shrink-0">
                    <!-- Left: Date Range & Product Count -->
                    <div class="flex flex-col gap-0.5 text-xs text-[#8e8e9f]">
                        <div><?= $isKm ? 'ចន្លោះកាលបរិច្ឆេទ:' : 'Date Range:' ?> <span class="font-bold text-white"><?= date('j/n/Y', strtotime($fromDate)) ?> — <?= date('j/n/Y', strtotime($toDate)) ?></span></div>
                        <div><?= $isKm ? 'ចំនួនមុខទំនិញសរុប:' : 'Total Products Count:' ?> <span class="font-bold text-white"><?= number_format($totalDistinctProducts) ?> <?= $isKm ? 'មុខ' : 'items' ?></span></div>
                    </div>

                    <!-- Right: Units, Total Profit & Total Sales -->
                    <div class="flex items-center gap-7 sm:gap-10 self-end md:self-auto flex-wrap">
                        <div class="text-left sm:text-right">
                            <div class="text-[11px] font-extrabold text-[#8e8e9f] uppercase tracking-wider mb-0.5"><?= $isKm ? 'ចំនួនលក់សរុប' : 'TOTAL UNITS' ?></div>
                            <div class="text-xl md:text-2xl font-black text-white tracking-tight leading-tight"><?= number_format($totalUnitsSold) ?></div>
                        </div>
                        <div class="text-left sm:text-right">
                            <div class="text-[11px] font-extrabold text-[#8e8e9f] uppercase tracking-wider mb-0.5"><?= $isKm ? 'ប្រាក់ចំណេញសរុប' : 'TOTAL PROFIT' ?></div>
                            <div class="text-xl md:text-2xl font-black text-emerald-400 tracking-tight leading-tight"><?= ($totalProfit >= 0 ? '+' : '') . '$' . number_format($totalProfit, 2) ?></div>
                        </div>
                        <div class="text-left sm:text-right">
                            <div class="text-[11px] font-extrabold text-[#8e8e9f] uppercase tracking-wider mb-0.5"><?= $isKm ? 'ចំណូលសរុប' : 'TOTAL SALES' ?></div>
                            <div class="text-xl md:text-2xl font-black text-white tracking-tight leading-tight"><?= '$' . number_format($totalRevenue, 2) ?></div>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /.rep-page-wrapper -->
    </div><!-- /.app-main -->
</div><!-- /.app-layout -->

<script>
function handleMonthSelect(val) {
    if (!val) return;
    const form = document.getElementById('analyticsReportFilterForm');
    const [yyyy, mm] = val.split('-');
    const lastDay = new Date(yyyy, mm, 0).getDate();
    const fDt = document.getElementById('fromDateTimeInput');
    const tDt = document.getElementById('toDateTimeInput');
    if (fDt) fDt.value = `${yyyy}-${pad(mm)}-01T00:00`;
    if (tDt) tDt.value = `${yyyy}-${pad(mm)}-${pad(lastDay)}T23:59`;
    
    const fD = document.getElementById('fromDateInput');
    const tD = document.getElementById('toDateInput');
    if (fD) fD.value = `${yyyy}-${pad(mm)}-01`;
    if (tD) tD.value = `${yyyy}-${pad(mm)}-${pad(lastDay)}`;
    
    const qr = document.getElementById('quickRangeSelect');
    if (qr) qr.value = '';
    
    form.submit();
}

function handleQuickRangeChange(val) {
    if (!val) return;
    const today = new Date('<?= $today ?>T12:00:00');
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
    } else if (val === 'week' || val === 'this_week') {
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
    } else if (val === 'year' || val === 'this_year') {
        fromStr = `${yyyy}-01-01`;
        toStr = todayStr;
    } else if (val === 'all') {
        fromStr = '2020-01-01';
        toStr = todayStr;
    }
    
    const ms = document.getElementById('monthSelect');
    if (ms) ms.value = '';
    
    const fDt = document.getElementById('fromDateTimeInput');
    const tDt = document.getElementById('toDateTimeInput');
    if (fDt) fDt.value = `${fromStr}T00:00`;
    if (tDt) tDt.value = `${toStr}T23:59`;
    
    const fD = document.getElementById('fromDateInput');
    const tD = document.getElementById('toDateInput');
    if (fD) fD.value = fromStr;
    if (tD) tD.value = toStr;
    
    document.getElementById('analyticsReportFilterForm').submit();
}

function filterTableClientSide() {
    const query = (document.getElementById('tableSearchInput').value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('#reportOrdersTable tbody tr.product-item-row');
    let visibleCount = 0;
    
    rows.forEach(tr => {
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