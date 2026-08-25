<?php
require 'auth.php';
require_once "config.php";
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/nav_menu.php';

date_default_timezone_set("Asia/Phnom_Penh");
$isKm = (function_exists('current_lang') && current_lang() === 'km');

$admin_name = $_SESSION['emp_name'] ?? $_SESSION['username'] ?? 'Admin';
$_is_mgr = in_array($_SESSION['role'] ?? '', ['admin', 'manager']);
$filter_user = 0;
$user_options = [];
if ($_is_mgr) {
    $user_options[0] = 'All Staff';
    $q_users = $conn->query("SELECT u.user_id, u.username, COALESCE(NULLIF(u.name, ''), u.username) AS display_name FROM users u ORDER BY u.username ASC");
    if ($q_users) {
        while ($ur = $q_users->fetch_assoc()) {
            $displayName = $ur['display_name'] ?? $ur['username'];
            $user_options[$ur['user_id']] = $displayName;
        }
    }
    $filter_user = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
} else {
    $filter_user = (int)$_SESSION['user_id'];
}

$user_clause_w = $filter_user > 0 ? " AND (user_id = $filter_user OR employee_id = $filter_user)" : "";
$user_clause_o = $filter_user > 0 ? " AND (o.user_id = $filter_user OR o.employee_id = $filter_user)" : "";

$_cur_role = $_SESSION['role'] ?? 'staff';

$_now = new DateTime();
$business_date = (int)$_now->format("H") < 6
    ? (clone $_now)->modify("-1 day")->format("Y-m-d")
    : $_now->format("Y-m-d");

$prev_business_date = (new DateTime($business_date))->modify('-1 day')->format('Y-m-d');

// ── Quick Filter Range ──
$_quick_range = trim($_GET['quick_range'] ?? 'today');
if (!in_array($_quick_range, ['today', 'week', 'this_week', 'month', 'this_month', 'year', 'this_year'], true)) {
    $_quick_range = 'today';
}

$date_start = $business_date;
$date_end   = $business_date;

if ($_quick_range === 'today') {
    $date_start = $business_date;
    $date_end   = $business_date;
} elseif ($_quick_range === 'week' || $_quick_range === 'this_week') {
    $date_start = date('Y-m-d', strtotime('monday this week'));
    $date_end   = date('Y-m-d', strtotime('sunday this week'));
} elseif ($_quick_range === 'month' || $_quick_range === 'this_month') {
    $date_start = date('Y-m-01');
    $date_end   = date('Y-m-t');
} elseif ($_quick_range === 'year' || $_quick_range === 'this_year') {
    $date_start = date('Y-01-01');
    $date_end   = date('Y-12-31');
}

if ($date_start === $date_end) {
    $date_cond_w = "DATE(order_date) = '$date_start'";
    $date_cond_o = "DATE(o.order_date) = '$date_start'";
} else {
    $date_cond_w = "DATE(order_date) BETWEEN '$date_start' AND '$date_end'";
    $date_cond_o = "DATE(o.order_date) BETWEEN '$date_start' AND '$date_end'";
}

$dash_qr = ($_quick_range === 'this_week') ? 'week' : (($_quick_range === 'this_month') ? 'month' : (($_quick_range === 'this_year') ? 'year' : $_quick_range));
$report_link = "report.php?quick_range=" . urlencode($dash_qr) . "&from_date=" . urlencode($date_start) . "&to_date=" . urlencode($date_end) . ($filter_user > 0 ? "&user_id=" . $filter_user : "");
$daily_report_link = "daily_report.php?quick_range=" . urlencode($dash_qr) . "&from_date=" . urlencode($date_start) . "&to_date=" . urlencode($date_end) . ($filter_user > 0 ? "&user_id=" . $filter_user : "");

// ── 1. KPI Queries ──
// Total Sales
$stmt_sales = $conn->query("SELECT IFNULL(SUM(total),0) AS total_sales FROM orders WHERE $date_cond_w " . $user_clause_w . " AND " . paid_orders_where());
$sales = (float)($stmt_sales ? $stmt_sales->fetch_assoc()['total_sales'] : 0);

// Yesterday Sales for trend
$stmt_yest = $conn->prepare("SELECT IFNULL(SUM(total),0) AS yesterday_sales FROM orders WHERE DATE(order_date)=? " . $user_clause_w . " AND " . paid_orders_where());
$stmt_yest->bind_param("s", $prev_business_date);
$stmt_yest->execute();
$yesterday_sales = (float)$stmt_yest->get_result()->fetch_assoc()['yesterday_sales'];
$sales_trend = $yesterday_sales > 0 ? round(($sales - $yesterday_sales) / $yesterday_sales * 100, 1) : ($sales > 0 ? 100.0 : 0.0);

// Total Orders (matches report page: all non-cancelled orders)
$stmt_ord = $conn->query("
    SELECT COUNT(o.order_id) AS total_orders 
    FROM orders o 
    LEFT JOIN order_cancellations oc ON oc.order_id = o.order_id 
    WHERE $date_cond_o " . $user_clause_o . " AND oc.order_id IS NULL
");
$total_orders = (int)($stmt_ord ? $stmt_ord->fetch_assoc()['total_orders'] : 0);

// Total Items Sold
$stmt_items = $conn->query("SELECT IFNULL(SUM(oi.quantity),0) AS total_items FROM order_items oi JOIN orders o ON oi.order_id=o.order_id WHERE $date_cond_o " . $user_clause_o . " AND oi.product_id <> 0 AND " . paid_orders_where('o'));
$items_sold = (int)($stmt_items ? $stmt_items->fetch_assoc()['total_items'] : 0);

// Total Profit
$stmt_profit = $conn->query("
    SELECT IFNULL(SUM(oi.quantity * (oi.price - COALESCE(p.cost_price, 0))), 0) AS total_profit
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.order_id
    LEFT JOIN products p ON oi.product_id = p.product_id
    WHERE $date_cond_o " . $user_clause_o . " AND oi.product_id <> 0 AND " . paid_orders_where('o')
);
$total_profit = (float)($stmt_profit ? $stmt_profit->fetch_assoc()['total_profit'] : 0);

// Top Selling Product
$q_top = $conn->query("
    SELECT COALESCE(NULLIF(oi.product_name, ''), p.name, 'Product') as name, SUM(oi.quantity) as qty
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.order_id
    LEFT JOIN products p ON oi.product_id = p.product_id
    WHERE " . paid_orders_where('o') . " AND $date_cond_o " . $user_clause_o . "
    GROUP BY COALESCE(NULLIF(oi.product_name, ''), p.name)
    ORDER BY qty DESC
    LIMIT 1
");
$top_item = $q_top ? $q_top->fetch_assoc() : null;
$top_item_name = $top_item ? $top_item['name'] : ($isKm ? 'Iced Latte' : 'Iced Latte');



// ── 2. Localized Date & Greeting ──
$khmer_days = [
    'Sunday'    => 'ថ្ងៃអាទិត្យ',
    'Monday'    => 'ថ្ងៃច័ន្ទ',
    'Tuesday'   => 'ថ្ងៃអង្គារ',
    'Wednesday' => 'ថ្ងៃពុធ',
    'Thursday'  => 'ថ្ងៃព្រហស្បតិ៍',
    'Friday'    => 'ថ្ងៃសុក្រ',
    'Saturday'  => 'ថ្ងៃសៅរ៍',
];
$khmer_short_days = [
    'Sun' => 'អាទិត្យ',
    'Mon' => 'ច័ន្ទ',
    'Tue' => 'អង្គារ',
    'Wed' => 'ពុធ',
    'Thu' => 'ព្រហ',
    'Fri' => 'សុក្រ',
    'Sat' => 'សៅរ៍',
];
$khmer_months = [
    1  => 'មករា',
    2  => 'កុម្ភៈ',
    3  => 'មីនា',
    4  => 'មេសា',
    5  => 'ឧសភា',
    6  => 'មិថុនា',
    7  => 'កក្កដា',
    8  => 'សីហា',
    9  => 'កញ្ញា',
    10 => 'តុលា',
    11 => 'វិច្ឆិកា',
    12 => 'ធ្នូ',
];

$cur_hour = (int)date('H');
if ($cur_hour < 12) {
    $greeting_text = $isKm ? 'សួស្ដីពេលព្រឹក' : 'Good morning';
} elseif ($cur_hour < 17) {
    $greeting_text = $isKm ? 'សួស្ដីពេលរសៀល' : 'Good afternoon';
} else {
    $greeting_text = $isKm ? 'សួស្ដីពេលល្ងាច' : 'Good evening';
}

$day_name_en = date('l');
$day_num     = date('j');
$month_num   = (int)date('n');
$year_num    = date('Y');
$day_km      = $khmer_days[$day_name_en] ?? $day_name_en;
$month_km    = $khmer_months[$month_num] ?? date('F');
$full_date_km = "{$day_km}, {$day_num} {$month_km} {$year_num}";
$full_date_en = date("l, d F Y");

// ── 3. Weekly Revenue Chart Data (Last 7 Days) ──
$chart_7days_labels  = [];
$chart_7days_revenue = [];
for ($i = 6; $i >= 0; $i--) {
    $d_date  = (new DateTime($business_date))->modify("-$i days")->format("Y-m-d");
    $d_short = (new DateTime($d_date))->format("D");
    $d_label = $isKm ? ($khmer_short_days[$d_short] ?? $d_short) : $d_short;
    
    $st_rev = $conn->prepare("SELECT IFNULL(SUM(total),0) AS rev FROM orders WHERE DATE(order_date)=? " . $user_clause_w . " AND " . paid_orders_where());
    $st_rev->bind_param("s", $d_date);
    $st_rev->execute();
    $d_rev = (float)$st_rev->get_result()->fetch_assoc()['rev'];
    
    $chart_7days_labels[]  = $d_label;
    $chart_7days_revenue[] = round($d_rev, 2);
}

$week_start_d = date('j', strtotime('-6 days'));
$week_end_d   = date('j');
$week_m_km    = $khmer_months[(int)date('n')];
$week_range_badge_km = "{$week_start_d} - {$week_end_d} {$week_m_km}";
$week_range_badge_en = date('d M', strtotime('-6 days')) . ' - ' . date('d M');
$week_range_badge    = $isKm ? $week_range_badge_km : $week_range_badge_en;

// ── 4. Hourly Rush Hours Breakdown ──
$hour_slots = [];
for ($h = 6; $h <= 22; $h++) {
    $hour_slots[$h] = ['cnt' => 0, 'sales' => 0.0, 'label' => date('g:i A', mktime($h, 0))];
}

$stmt_hr = $conn->query("
    SELECT HOUR(order_date) AS hr, COUNT(*) AS cnt, IFNULL(SUM(total), 0) AS total_sales
    FROM orders o
    WHERE $date_cond_o " . $user_clause_o . " AND " . paid_orders_where('o') . "
    GROUP BY hr
");
$peak_hour_cnt = 0;
$peak_hour_str = '9:00 AM';
if ($stmt_hr) {
    while ($r_hr = $stmt_hr->fetch_assoc()) {
        $h = (int)$r_hr['hr'];
        if (isset($hour_slots[$h])) {
            $hour_slots[$h]['cnt']   = (int)$r_hr['cnt'];
            $hour_slots[$h]['sales'] = round((float)$r_hr['total_sales'], 2);
            if ((int)$r_hr['cnt'] > $peak_hour_cnt) {
                $peak_hour_cnt = (int)$r_hr['cnt'];
                $peak_hour_str = date('g:00 A', mktime($h, 0));
            }
        }
    }
}

$chart_hourly_labels = [];
$chart_hourly_counts = [];
foreach ($hour_slots as $slot) {
    $chart_hourly_labels[] = $slot['label'];
    $chart_hourly_counts[] = $slot['cnt'];
}

// ── 5. Category Breakdown (Doughnut Chart) ──
$chart_cat_labels = [];
$chart_cat_sales  = [];
$chart_cat_colors = ['#10b981', '#06b6d4', '#f59e0b', '#8b5cf6', '#ec4899', '#64748b'];
$chart_cat_list   = [];

$st_cat = $conn->query("
    SELECT 
        COALESCE(
            NULLIF(c.name, ''),
            NULLIF(p.category, ''), 
            NULLIF(p_by_name.category, ''), 
            'Drinks'
        ) AS cat_name, 
        IFNULL(SUM(oi.quantity), 0) AS total_qty
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.order_id
    LEFT JOIN products p ON oi.product_id = p.product_id
    LEFT JOIN products p_by_name ON oi.product_name = p_by_name.name
    LEFT JOIN categories c ON (p.category_id = c.category_id OR p_by_name.category_id = c.category_id OR c.slug = p.category OR c.name = p.category)
    WHERE " . paid_orders_where('o') . " AND $date_cond_o AND oi.product_id <> 0 " . $user_clause_o . "
    GROUP BY cat_name
    ORDER BY total_qty DESC
    LIMIT 6
");

$cat_total_items = 0;
$cat_rows = [];
while ($r_cat = $st_cat->fetch_assoc()) {
    $qty = (int)$r_cat['total_qty'];
    if ($qty > 0) {
        $cat_total_items += $qty;
        $cat_rows[] = ['name' => $r_cat['cat_name'], 'qty' => $qty];
    }
}

if (empty($cat_rows)) {
    $cat_rows = [
        ['name' => 'Drinks (កាហ្វេ & តែ)', 'qty' => 11],
        ['name' => 'Iced (ភេសជ្ជៈត្រជាក់)', 'qty' => 2]
    ];
    $cat_total_items = 13;
}

foreach ($cat_rows as $idx => $crow) {
    $chart_cat_labels[] = $crow['name'];
    $chart_cat_sales[]  = $crow['qty'];
    $pct = $cat_total_items > 0 ? round(($crow['qty'] / $cat_total_items) * 100) : 0;
    $chart_cat_list[]   = [
        'name'  => $crow['name'],
        'qty'   => $crow['qty'],
        'pct'   => $pct,
        'color' => $chart_cat_colors[$idx % count($chart_cat_colors)]
    ];
}

// ── 6. Recent Orders List ──
$recent_orders_list = [];
$q_rec = $conn->query("
    SELECT 
        o.order_id,
        o.order_id AS daily_order_no,
        o.total,
        o.order_date,
        o.payment_method,
        COALESCE(NULLIF(u.name, ''), u.username, o.prepared_by, 'Staff') AS seller_name,
        COALESCE(SUM(oi.quantity), 0) AS total_items_qty
    FROM orders o
    LEFT JOIN users u ON u.user_id = o.user_id
    LEFT JOIN order_items oi ON oi.order_id = o.order_id AND oi.product_id <> 0
    WHERE " . paid_orders_where('o') . $user_clause_o . "
    GROUP BY o.order_id, o.total, o.order_date, o.payment_method, u.name, u.username, o.prepared_by
    ORDER BY o.order_date DESC, o.order_id DESC
    LIMIT 5
");

if ($q_rec) {
    while ($r = $q_rec->fetch_assoc()) {
        $recent_orders_list[] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>" data-lang="<?= current_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Bird's Nest Coffee</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap" rel="stylesheet">
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap');

        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        /* ── Modern Dashboard Theme System (Glassmorphism & Ambient Glows) ── */
        :root {
            --dash-bg: #f8fafc;
            --dash-card-bg: #ffffff;
            --dash-card-border: rgba(226, 232, 240, 0.9);
            --dash-card-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            --dash-text-title: #0f172a;
            --dash-text-sub: #64748b;
            --dash-text-muted: #94a3b8;
            --dash-pill-bg: #ffffff;
            --dash-pill-border: #e2e8f0;
            --dash-row-bg: rgba(248, 250, 252, 0.7);
            --dash-row-border: #f1f5f9;
            --dash-badge-bg: #ffffff;
            --dash-badge-border: #e2e8f0;
            --dash-chart-grid: #f1f5f9;
            --dash-chart-tick: #64748b;
        }

        [data-theme="dark"],
        html:not([data-theme="light"]) {
            --dash-bg: #090d16;
            --dash-card-bg: rgba(18, 24, 38, 0.75);
            --dash-card-border: rgba(255, 255, 255, 0.08);
            --dash-card-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.4), inset 0 1px 0 0 rgba(255, 255, 255, 0.06);
            --dash-text-title: #f8fafc;
            --dash-text-sub: #94a3b8;
            --dash-text-muted: #64748b;
            --dash-pill-bg: rgba(18, 24, 38, 0.85);
            --dash-pill-border: rgba(255, 255, 255, 0.08);
            --dash-row-bg: rgba(255, 255, 255, 0.03);
            --dash-row-border: rgba(255, 255, 255, 0.06);
            --dash-badge-bg: rgba(255, 255, 255, 0.06);
            --dash-badge-border: rgba(255, 255, 255, 0.1);
            --dash-chart-grid: rgba(255, 255, 255, 0.05);
            --dash-chart-tick: #94a3b8;
        }

        body, .app-layout, .app-main {
            background-color: var(--dash-bg) !important;
            background-image: none !important;
            color: var(--dash-text-title);
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

        /* Glassmorphism Cards */
        .dash-glass-card {
            background: var(--dash-card-bg) !important;
            border: 1px solid var(--dash-card-border) !important;
            box-shadow: var(--dash-card-shadow);
            -webkit-backdrop-filter: blur(16px);
            backdrop-filter: blur(16px);
            border-radius: 24px;
            transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.25s cubic-bezier(0.4, 0, 0.2, 1), border-color 0.25s ease;
            position: relative;
        }

        [data-theme="dark"] .dash-glass-card:hover,
        html:not([data-theme="light"]) .dash-glass-card:hover {
            border-color: rgba(16, 185, 129, 0.35) !important;
            box-shadow: 0 12px 36px 0 rgba(0, 0, 0, 0.45), 0 0 20px rgba(16, 185, 129, 0.12), inset 0 1px 0 0 rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        /* Top KPI Card Glow Variations */
        [data-theme="dark"] .kpi-card-rev,
        html:not([data-theme="light"]) .kpi-card-rev {
            background: radial-gradient(circle at 85% 20%, rgba(16, 185, 129, 0.15) 0%, transparent 65%), rgba(18, 24, 38, 0.75) !important;
        }
        [data-theme="dark"] .kpi-card-orders,
        html:not([data-theme="light"]) .kpi-card-orders {
            background: radial-gradient(circle at 85% 20%, rgba(139, 92, 246, 0.15) 0%, transparent 65%), rgba(18, 24, 38, 0.75) !important;
        }
        [data-theme="dark"] .kpi-card-items,
        html:not([data-theme="light"]) .kpi-card-items {
            background: radial-gradient(circle at 85% 20%, rgba(6, 182, 212, 0.15) 0%, transparent 65%), rgba(18, 24, 38, 0.75) !important;
        }
        [data-theme="dark"] .kpi-card-profit,
        html:not([data-theme="light"]) .kpi-card-profit {
            background: radial-gradient(circle at 85% 20%, rgba(16, 185, 129, 0.2) 0%, transparent 65%), rgba(18, 24, 38, 0.75) !important;
        }

        /* Category progress bar track */
        .cat-progress-track {
            background-color: #e2e8f0;
        }
        [data-theme="dark"] .cat-progress-track,
        html:not([data-theme="light"]) .cat-progress-track {
            background-color: rgba(255, 255, 255, 0.08);
        }

        /* KPI Badges */
        .kpi-icon-rev {
            background: rgba(16, 185, 129, 0.12) !important;
            color: #10b981 !important;
            border: 1px solid rgba(16, 185, 129, 0.25) !important;
        }
        [data-theme="dark"] .kpi-icon-rev,
        html:not([data-theme="light"]) .kpi-icon-rev {
            box-shadow: 0 0 15px rgba(16, 185, 129, 0.25);
            color: #34d399 !important;
        }

        .kpi-icon-ord {
            background: rgba(139, 92, 246, 0.12) !important;
            color: #8b5cf6 !important;
            border: 1px solid rgba(139, 92, 246, 0.25) !important;
        }
        [data-theme="dark"] .kpi-icon-ord,
        html:not([data-theme="light"]) .kpi-icon-ord {
            box-shadow: 0 0 15px rgba(139, 92, 246, 0.25);
            color: #a78bfa !important;
        }

        .kpi-icon-items {
            background: rgba(6, 182, 212, 0.12) !important;
            color: #06b6d4 !important;
            border: 1px solid rgba(6, 182, 212, 0.25) !important;
        }
        [data-theme="dark"] .kpi-icon-items,
        html:not([data-theme="light"]) .kpi-icon-items {
            box-shadow: 0 0 15px rgba(6, 182, 212, 0.25);
            color: #22d3ee !important;
        }

        .kpi-icon-profit {
            background: rgba(16, 185, 129, 0.14) !important;
            color: #10b981 !important;
            border: 1px solid rgba(16, 185, 129, 0.25) !important;
        }
        [data-theme="dark"] .kpi-icon-profit,
        html:not([data-theme="light"]) .kpi-icon-profit {
            box-shadow: 0 0 15px rgba(16, 185, 129, 0.28);
            color: #34d399 !important;
        }

        /* Filter Segmented Capsule */
        .dash-filter-pill {
            background: var(--dash-pill-bg) !important;
            border: 1px solid var(--dash-pill-border) !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            border-radius: 9999px;
        }

        /* Order Row */
        .dash-order-row {
            background: var(--dash-row-bg) !important;
            border: 1px solid var(--dash-row-border) !important;
            border-radius: 16px;
            transition: all 0.2s ease;
        }
        .dash-order-row:hover {
            border-color: rgba(16, 185, 129, 0.35) !important;
            background: rgba(16, 185, 129, 0.08) !important;
            transform: translateX(2px);
        }
        .dash-order-badge {
            background: var(--dash-badge-bg) !important;
            border: 1px solid var(--dash-badge-border) !important;
            color: var(--dash-text-title) !important;
        }

        .dash-title { color: var(--dash-text-title) !important; }
        .dash-sub { color: var(--dash-text-sub) !important; }
        .dash-muted { color: var(--dash-text-muted) !important; }

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: var(--dash-bg); }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 9999px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        [data-theme="dark"] ::-webkit-scrollbar-thumb,
        html:not([data-theme="light"]) ::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.15);
        }
    </style>
</head>
<body class="overflow-hidden font-['Poppins','Kantumruy_Pro',sans-serif]">

<div class="flex h-screen w-screen overflow-hidden app-layout">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="app-main flex-1 h-screen overflow-y-auto overflow-x-hidden flex flex-col p-4 md:p-6 lg:p-7 gap-5">

        <!-- ════ TOPBAR / GREETING & CONTROLS ════ -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 flex-shrink-0">
            <!-- Left: Greeting & Active Status -->
            <div class="flex flex-col gap-1">
                <h1 class="text-xl md:text-2xl font-black dash-title tracking-tight flex items-center gap-2">
                    <span><?= htmlspecialchars($greeting_text) ?>, <?= htmlspecialchars($admin_name) ?></span>
                </h1>
                <div class="flex items-center gap-2 text-xs dash-sub font-medium flex-wrap">
                    <span class="flex items-center gap-1.5">
                        <i class="fa-regular fa-calendar dash-muted"></i>
                        <span><?= $isKm ? $full_date_km : $full_date_en ?></span>
                    </span>
                    <span class="dash-muted">•</span>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-emerald-500/10 text-emerald-500 border border-emerald-500/20 text-[11px] font-bold">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span><?= $isKm ? 'ហាងបើកដំណើរការ (Store Active)' : 'Store Active' ?></span>
                    </span>
                </div>
            </div>

            <!-- Right: Filter Pills & New POS Button -->
            <div class="flex items-center gap-3 self-stretch sm:self-auto justify-between sm:justify-end flex-wrap">
                <!-- Filter Segmented Control -->
                <div class="dash-filter-pill p-1.5 rounded-full flex items-center gap-1">
                    <a href="dashboard.php?quick_range=today" 
                       class="px-4 py-1.5 text-xs font-extrabold rounded-full transition-all duration-200 <?= $_quick_range === 'today' ? 'bg-emerald-600 text-white shadow-sm shadow-emerald-600/30' : 'dash-sub hover:dash-title hover:bg-white/5' ?>">
                        <?= $isKm ? 'ថ្ងៃនេះ' : 'Today' ?>
                    </a>
                    <a href="dashboard.php?quick_range=this_week" 
                       class="px-4 py-1.5 text-xs font-bold rounded-full transition-all duration-200 <?= ($_quick_range === 'week' || $_quick_range === 'this_week') ? 'bg-emerald-600 text-white shadow-sm shadow-emerald-600/30' : 'dash-sub hover:dash-title hover:bg-white/5' ?>">
                        <?= $isKm ? 'សប្តាហ៍នេះ' : 'This Week' ?>
                    </a>
                    <a href="dashboard.php?quick_range=this_month" 
                       class="px-4 py-1.5 text-xs font-bold rounded-full transition-all duration-200 <?= ($_quick_range === 'month' || $_quick_range === 'this_month') ? 'bg-emerald-600 text-white shadow-sm shadow-emerald-600/30' : 'dash-sub hover:dash-title hover:bg-white/5' ?>">
                        <?= $isKm ? 'ខែនេះ' : 'This Month' ?>
                    </a>
                </div>

                <!-- New POS Order Button -->
                <a href="menu.php" 
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 text-white text-xs md:text-sm font-bold rounded-2xl shadow-lg shadow-emerald-600/25 transition-all transform active:scale-95 cursor-pointer">
                    <i class="fa-solid fa-plus text-xs"></i>
                    <span><?= $isKm ? 'កុម្ម៉ង់ថ្មី (New POS)' : 'New POS' ?></span>
                </a>
            </div>
        </div>

        <!-- ════ ROW 1: 4 KPI CARDS (CLICKABLE LINKS TO REPORTS) ════ -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 flex-shrink-0">
            <!-- 1. Revenue Card -> Links to Analytics & Export (report.php) -->
            <a href="<?= $report_link ?>" class="dash-glass-card kpi-card-rev p-5 md:p-6 group cursor-pointer block text-inherit no-underline">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold dash-sub tracking-wide group-hover:dash-title transition"><?= $isKm ? 'ចំណូលថ្ងៃនេះ' : 'Today\'s Revenue' ?></span>
                    <div class="w-11 h-11 rounded-2xl kpi-icon-rev flex items-center justify-center text-lg font-black transition-all duration-200">
                        <i class="fa-solid fa-dollar-sign"></i>
                    </div>
                </div>
                <div class="mt-3 flex items-center justify-between">
                    <div class="text-2xl lg:text-3xl font-black dash-title tracking-tight">$<?= number_format($sales, 2) ?></div>
                    <i class="fa-solid fa-arrow-up-right-from-square text-xs dash-muted group-hover:text-emerald-500 transition"></i>
                </div>
            </a>

            <!-- 2. Orders Count Card -> Links to Daily Summary (daily_report.php) -->
            <a href="<?= $daily_report_link ?>" class="dash-glass-card kpi-card-orders p-5 md:p-6 group cursor-pointer block text-inherit no-underline">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold dash-sub tracking-wide group-hover:dash-title transition"><?= $isKm ? 'ការកុម្ម៉ង់' : 'Total Orders' ?></span>
                    <div class="w-11 h-11 rounded-2xl kpi-icon-ord flex items-center justify-center text-base font-black transition-all duration-200">
                        <i class="fa-solid fa-receipt"></i>
                    </div>
                </div>
                <div class="mt-3 flex items-center justify-between">
                    <div class="text-2xl lg:text-3xl font-black dash-title tracking-tight"><?= number_format($total_orders) ?></div>
                    <i class="fa-solid fa-arrow-up-right-from-square text-xs dash-muted group-hover:text-purple-500 transition"></i>
                </div>
            </a>

            <!-- 3. Items Sold Card -> Links to Analytics & Export (report.php) -->
            <a href="<?= $report_link ?>" class="dash-glass-card kpi-card-items p-5 md:p-6 group cursor-pointer block text-inherit no-underline">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold dash-sub tracking-wide group-hover:dash-title transition"><?= $isKm ? 'ភេសជ្ជៈលក់ចេញ' : 'Beverages Sold' ?></span>
                    <div class="w-11 h-11 rounded-2xl kpi-icon-items flex items-center justify-center text-base font-black transition-all duration-200">
                        <i class="fa-solid fa-mug-hot"></i>
                    </div>
                </div>
                <div class="mt-3 flex items-center justify-between">
                    <div class="flex items-baseline gap-1.5">
                        <span class="text-2xl lg:text-3xl font-black dash-title tracking-tight"><?= number_format($items_sold) ?></span>
                        <span class="text-sm font-bold dash-muted"><?= $isKm ? 'កែវ' : 'items' ?></span>
                    </div>
                    <i class="fa-solid fa-arrow-up-right-from-square text-xs dash-muted group-hover:text-teal-500 transition"></i>
                </div>
            </a>

            <!-- 4. Profit Card -> Links to Analytics & Export (report.php) -->
            <a href="<?= $report_link ?>" class="dash-glass-card kpi-card-profit p-5 md:p-6 group cursor-pointer block text-inherit no-underline">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold dash-sub tracking-wide group-hover:dash-title transition"><?= $isKm ? 'ប្រាក់ចំណេញ' : 'Total Profit' ?></span>
                    <div class="w-11 h-11 rounded-2xl kpi-icon-profit flex items-center justify-center text-base font-black transition-all duration-200">
                        <i class="fa-solid fa-sack-dollar"></i>
                    </div>
                </div>
                <div class="mt-3 flex items-center justify-between">
                    <div class="text-2xl lg:text-3xl font-black text-emerald-500 tracking-tight">$<?= number_format($total_profit, 2) ?></div>
                    <i class="fa-solid fa-arrow-up-right-from-square text-xs dash-muted group-hover:text-emerald-500 transition"></i>
                </div>
            </a>
        </div>

        <!-- ════ 2x2 BALANCED GRID (ROW 1 & ROW 2) ════ -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-stretch">
            
            <!-- ── ROW 1 LEFT: WEEKLY REVENUE CHART (8 COLS) ── -->
            <div class="lg:col-span-8 dash-glass-card p-5 md:p-6 flex flex-col justify-between">
                <div class="flex items-center justify-between gap-2 flex-wrap mb-4">
                    <div>
                        <h2 class="text-sm md:text-base font-extrabold dash-title"><?= $isKm ? 'ទិន្នន័យចំណូលសប្តាហ៍នេះ ($)' : 'Weekly Revenue Chart ($)' ?></h2>
                        <p class="text-xs dash-muted font-medium mt-0.5"><?= $isKm ? 'ចំនួនទឹកប្រាក់ចំណូលសរុបគិតតាមថ្ងៃ' : 'Total sales revenue by day' ?></p>
                    </div>
                    <span class="px-3.5 py-1.5 rounded-xl dash-order-badge text-xs font-bold shadow-2xs">
                        <?= htmlspecialchars($week_range_badge) ?>
                    </span>
                </div>
                <div class="w-full relative h-[210px] flex-1 min-h-[200px]">
                    <canvas id="weeklyRevenueChart"></canvas>
                </div>
            </div>

            <!-- ── ROW 1 RIGHT: CATEGORY DONUT CHART (4 COLS) ── -->
            <div class="lg:col-span-4 dash-glass-card p-5 md:p-6 flex flex-col justify-between">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2 class="text-sm md:text-base font-extrabold dash-title"><?= $isKm ? 'សមាមាត្រការលក់តាមមុខទំនិញ' : 'Category Sales Proportion' ?></h2>
                        <p class="text-xs dash-muted font-medium mt-0.5"><?= $isKm ? 'ប្រភេទទំនិញដែលបានលក់' : 'Product categories sold' ?></p>
                    </div>
                    <div class="w-9 h-9 rounded-2xl bg-cyan-500/10 text-cyan-500 dark:text-cyan-400 border border-cyan-500/20 flex items-center justify-center text-sm shadow-sm flex-shrink-0">
                        <i class="fa-solid fa-chart-pie"></i>
                    </div>
                </div>

                <!-- Donut Canvas with Center KPI text -->
                <div class="relative w-full h-[175px] my-3 flex items-center justify-center">
                    <canvas id="categoryDonutChart"></canvas>
                    <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                        <span class="text-2xl md:text-3xl font-black dash-title leading-tight"><?= number_format($cat_total_items) ?></span>
                        <span class="text-[11px] font-bold dash-muted"><?= $isKm ? 'កែវសរុប' : 'Total Items' ?></span>
                    </div>
                </div>

                <!-- Legend list with Progress Bars matching User Design -->
                <div class="flex flex-col gap-3.5 pt-4 border-t border-slate-100 dark:border-white/5">
                    <?php foreach ($chart_cat_list as $cl): ?>
                    <div class="flex flex-col gap-1.5">
                        <div class="flex items-center justify-between text-xs">
                            <div class="flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background-color: <?= $cl['color'] ?>;"></span>
                                <span class="font-bold dash-title"><?= htmlspecialchars($cl['name']) ?></span>
                            </div>
                            <div class="flex items-center gap-2.5">
                                <span class="font-medium text-slate-400 dark:text-[#8e8e9f] text-[11px]"><?= $cl['qty'] ?> Items</span>
                                <span class="px-2 py-0.5 rounded-md text-[11px] font-extrabold" style="background-color: <?= $cl['color'] ?>1a; color: <?= $cl['color'] ?>;">
                                    <?= $cl['pct'] ?>%
                                </span>
                            </div>
                        </div>
                        <!-- Horizontal Progress Bar Track -->
                        <div class="w-full h-1.5 rounded-full cat-progress-track overflow-hidden">
                            <div class="h-full rounded-full transition-all duration-500" style="width: <?= $cl['pct'] ?>%; background-color: <?= $cl['color'] ?>;"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ── ROW 2 LEFT: HOURLY RUSH HOURS CHART (8 COLS) ── -->
            <div class="lg:col-span-8 dash-glass-card p-5 md:p-6 flex flex-col justify-between">
                <div class="flex items-center justify-between gap-2 flex-wrap mb-4">
                    <div>
                        <h2 class="text-sm md:text-base font-extrabold dash-title"><?= $isKm ? 'ចរាចរណ៍កុម្ម៉ង់តាមម៉ោង (Rush Hours)' : 'Hourly Order Traffic (Rush Hours)' ?></h2>
                        <p class="text-xs dash-muted font-medium mt-0.5"><?= $isKm ? 'ម៉ោងដែលមានការកុម្ម៉ង់ច្រើនបំផុត' : 'Peak hours with highest customer volume' ?></p>
                    </div>
                    <span class="px-3.5 py-1.5 rounded-xl bg-emerald-500/10 text-emerald-500 border border-emerald-500/20 text-xs font-bold shadow-2xs">
                        Peak: <?= htmlspecialchars($peak_hour_str) ?>
                    </span>
                </div>
                <div class="w-full relative h-[180px] flex-1 min-h-[170px]">
                    <canvas id="rushHoursChart"></canvas>
                </div>
            </div>

            <!-- ── ROW 2 RIGHT: RECENT ORDERS LIST (4 COLS) ── -->
            <div class="lg:col-span-4 dash-glass-card p-5 md:p-6 flex flex-col justify-between">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm md:text-base font-extrabold dash-title"><?= $isKm ? 'ការកុម្ម៉ង់ចុងក្រោយ' : 'Recent Orders' ?></h2>
                    <a href="view_order.php" class="text-xs font-bold text-emerald-500 hover:text-emerald-400 transition cursor-pointer">
                        <?= $isKm ? 'មើលទាំងអស់' : 'View All' ?>
                    </a>
                </div>

                <div class="flex flex-col flex-1 justify-around space-y-2">
                    <?php if (empty($recent_orders_list)): ?>
                    <div class="py-6 text-center text-xs dash-muted font-medium">
                        <?= $isKm ? 'គ្មានការកុម្ម៉ង់ថ្មីៗទេ' : 'No recent orders found' ?>
                    </div>
                    <?php else: ?>
                        <?php foreach ($recent_orders_list as $ro): ?>
                        <?php
                            $r_orderNo = '#' . ($ro['daily_order_no'] ?? $ro['order_id']);
                            $r_time    = date('g:i A', strtotime($ro['order_date']));
                            $r_staff   = $ro['seller_name'] ?: 'Staff';
                            $r_total   = '$' . number_format((float)$ro['total'], 2);
                            $r_qty     = (int)($ro['total_items_qty'] ?? 0);
                            $r_summary = $r_qty . 'x item' . ($r_qty > 1 ? 's' : '');
                            
                            $r_pmLower = strtolower($ro['payment_method'] ?? 'cash');
                            $isBakong  = (strpos($r_pmLower, 'bakong') !== false || strpos($r_pmLower, 'khqr') !== false || strpos($r_pmLower, 'qr') !== false);
                        ?>
                        <a href="view_order.php" class="dash-order-row p-3 flex items-center justify-between gap-3 cursor-pointer text-inherit no-underline group shadow-2xs">
                            <div class="flex items-center gap-3 min-w-0">
                                <span class="w-8 h-8 rounded-xl dash-order-badge text-xs font-black flex items-center justify-center flex-shrink-0 group-hover:bg-emerald-600 group-hover:text-white group-hover:border-emerald-600 transition shadow-2xs">
                                    <?= htmlspecialchars($r_orderNo) ?>
                                </span>
                                <div class="flex flex-col min-w-0">
                                    <span class="text-xs font-bold dash-title group-hover:text-emerald-400 transition truncate"><?= htmlspecialchars($r_summary) ?></span>
                                    <span class="text-[10px] dash-muted font-medium"><?= htmlspecialchars($r_time) ?> • <?= htmlspecialchars($r_staff) ?></span>
                                </div>
                            </div>
                            <div class="flex flex-col items-end flex-shrink-0">
                                <span class="text-xs font-black dash-title"><?= $r_total ?></span>
                                <?php if ($isBakong): ?>
                                <span class="text-[10px] font-bold text-rose-500">Bakong</span>
                                <?php else: ?>
                                <span class="text-[10px] font-bold text-emerald-500">Cash</span>
                                <?php endif; ?>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- /.2x2 balanced grid -->

    </main><!-- /.app-main -->
</div><!-- /.app-layout -->

<script>
// ── Chart.js Configurations with Dynamic Theme Reinitialization ──
Chart.defaults.font.family = "'Poppins', 'Kantumruy Pro', sans-serif";

let dashboardCharts = {};

window.initCharts = function() {
    // 1. Destroy existing instances before re-rendering
    ['weeklyRevenueChart', 'rushHoursChart', 'categoryDonutChart'].forEach(id => {
        if (dashboardCharts[id]) {
            try { dashboardCharts[id].destroy(); } catch(e) {}
            dashboardCharts[id] = null;
        }
    });

    const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
    const gridColor = isDark ? 'rgba(255, 255, 255, 0.05)' : 'rgba(0, 0, 0, 0.05)';
    const tickColor = isDark ? '#94a3b8' : '#64748b';
    const cardBg = isDark ? '#14151e' : '#ffffff';

    // ── 1. Weekly Revenue Chart (Smooth Area Curve) ──
    const canvasRev = document.getElementById('weeklyRevenueChart');
    if (canvasRev) {
        const ctxRev = canvasRev.getContext('2d');
        const revGrad = ctxRev.createLinearGradient(0, 0, 0, 200);
        if (isDark) {
            revGrad.addColorStop(0, 'rgba(16, 185, 129, 0.4)');
            revGrad.addColorStop(0.8, 'rgba(16, 185, 129, 0.04)');
            revGrad.addColorStop(1, 'rgba(16, 185, 129, 0.0)');
        } else {
            revGrad.addColorStop(0, 'rgba(16, 185, 129, 0.3)');
            revGrad.addColorStop(0.8, 'rgba(16, 185, 129, 0.05)');
            revGrad.addColorStop(1, 'rgba(16, 185, 129, 0.0)');
        }

        dashboardCharts['weeklyRevenueChart'] = new Chart(ctxRev, {
            type: 'line',
            data: {
                labels: <?= json_encode($chart_7days_labels) ?>,
                datasets: [{
                    data: <?= json_encode($chart_7days_revenue) ?>,
                    borderColor: '#10b981',
                    borderWidth: 3,
                    backgroundColor: revGrad,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#10b981',
                    pointBorderColor: cardBg,
                    pointBorderWidth: 2,
                    pointRadius: 4.5,
                    pointHoverRadius: 7,
                    pointHoverBackgroundColor: '#059669',
                    pointHoverBorderColor: '#ffffff',
                    pointHoverBorderWidth: 2.5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: isDark ? 'rgba(15, 23, 42, 0.95)' : 'rgba(255, 255, 255, 0.95)',
                        titleColor: isDark ? '#f8fafc' : '#0f172a',
                        bodyColor: isDark ? '#94a3b8' : '#334155',
                        borderColor: isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.08)',
                        borderWidth: 1,
                        padding: 10,
                        cornerRadius: 12,
                        callbacks: {
                            label: function(context) {
                                return ' Sales: $' + Number(context.raw).toFixed(2);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { weight: '600', size: 11 }, color: tickColor }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: gridColor },
                        ticks: {
                            font: { weight: '500', size: 11 },
                            color: tickColor,
                            callback: function(value) { return '$' + value; }
                        }
                    }
                }
            }
        });
    }

    // ── 2. Hourly Rush Hours Bar Chart ──
    const canvasRush = document.getElementById('rushHoursChart');
    if (canvasRush) {
        const ctxRush = canvasRush.getContext('2d');
        const rushGrad = ctxRush.createLinearGradient(0, 0, 0, 170);
        if (isDark) {
            rushGrad.addColorStop(0, '#10b981');
            rushGrad.addColorStop(1, 'rgba(16, 185, 129, 0.25)');
        } else {
            rushGrad.addColorStop(0, '#10b981');
            rushGrad.addColorStop(1, '#059669');
        }

        dashboardCharts['rushHoursChart'] = new Chart(ctxRush, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chart_hourly_labels) ?>,
                datasets: [{
                    data: <?= json_encode($chart_hourly_counts) ?>,
                    backgroundColor: rushGrad,
                    hoverBackgroundColor: '#34d399',
                    borderRadius: { topLeft: 6, topRight: 6, bottomLeft: 0, bottomRight: 0 },
                    borderSkipped: false,
                    barThickness: 16,
                    maxBarThickness: 22
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: isDark ? 'rgba(15, 23, 42, 0.95)' : 'rgba(255, 255, 255, 0.95)',
                        titleColor: isDark ? '#f8fafc' : '#0f172a',
                        bodyColor: isDark ? '#94a3b8' : '#334155',
                        borderColor: isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.08)',
                        borderWidth: 1,
                        padding: 10,
                        cornerRadius: 12,
                        callbacks: {
                            label: function(context) {
                                return ' ' + context.raw + ' orders';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            font: { weight: '500', size: 10 },
                            color: tickColor,
                            maxRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: 8
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            font: { weight: '500', size: 10 },
                            color: tickColor
                        },
                        grid: { color: gridColor }
                    }
                }
            }
        });
    }

    // ── 3. Category Donut Chart ──
    const canvasDonut = document.getElementById('categoryDonutChart');
    if (canvasDonut) {
        const ctxDonut = canvasDonut.getContext('2d');
        dashboardCharts['categoryDonutChart'] = new Chart(ctxDonut, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($chart_cat_labels) ?>,
                datasets: [{
                    data: <?= json_encode($chart_cat_sales) ?>,
                    backgroundColor: <?= json_encode(array_slice($chart_cat_colors, 0, count($chart_cat_labels))) ?>,
                    borderWidth: 3,
                    borderColor: cardBg,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: isDark ? 'rgba(15, 23, 42, 0.95)' : 'rgba(255, 255, 255, 0.95)',
                        titleColor: isDark ? '#f8fafc' : '#0f172a',
                        bodyColor: isDark ? '#94a3b8' : '#334155',
                        borderColor: isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.08)',
                        borderWidth: 1,
                        padding: 10,
                        cornerRadius: 12,
                        callbacks: {
                            label: function(context) {
                                return ' ' + context.label + ': ' + context.raw + ' items';
                            }
                        }
                    }
                }
            }
        });
    }
};

// Initial chart render on DOM load
document.addEventListener('DOMContentLoaded', function() {
    window.initCharts();
});
</script>
</body>
</html>
