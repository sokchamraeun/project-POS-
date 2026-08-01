<?php
require 'auth.php';
require_once 'config.php';
if (!can('find_orders')) { header("Location: dashboard.php?denied=1"); exit; }

// ── Poll endpoint: returns current order IDs for change-detection ──
if (isset($_GET['action']) && $_GET['action'] === 'poll') {
    header('Content-Type: application/json');
    $poll_tab = $_GET['tab'] ?? 'all';
    $poll_sql = "SELECT order_id FROM orders WHERE (
        status = 'PendingPayment'
        OR (status = 'Paid' AND is_open = 1)
        OR (payment_method = 'paylater' AND status IN ('Preparing','PendingPayment','Completed'))
    )
    AND ( business_date = CURDATE() OR payment_method = 'paylater' )";
    if ($poll_tab === 'paylater') {
        $poll_sql .= " AND payment_method = 'paylater' AND status IN ('Preparing','PendingPayment','Completed')";
    } elseif ($poll_tab === 'preparing') {
        $poll_sql .= " AND status = 'Preparing'";
    } elseif ($poll_tab === 'pending') {
        $poll_sql .= " AND status = 'PendingPayment'";
    } elseif ($poll_tab === 'paid_open') {
        $poll_sql .= " AND status = 'Paid' AND is_open = 1";
    }
    $poll_sql = str_replace('SELECT order_id', 'SELECT order_id, status', $poll_sql);
    $pr = mysqli_query($conn, $poll_sql);
    $sig = '';
    while ($row = mysqli_fetch_assoc($pr)) $sig .= $row['order_id'] . ':' . $row['status'] . '|';
    echo json_encode(['sig' => md5($sig)]);
    exit;
}

$search_type  = $_GET['search_type']  ?? 'all';
$search_value = $_GET['search_value'] ?? '';
$filter_tab   = $_GET['tab']          ?? 'all';

// Cashiers only manage pay-later orders; force the tab and restrict the query
$is_cashier = (($_SESSION['role'] ?? '') === 'staff');
if ($is_cashier) {
    $filter_tab = 'paylater';
}

// The tab the cashier is looking at, carried into the settle links so they come
// back to it instead of always landing on Pending Payment. Read after the cashier
// override so a forced paylater tab returns to paylater.
$card_tab = pay_return_tab($filter_tab);

$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));

// ── Main query: unpaid OR paid-but-open orders ──
$sql = "
SELECT order_id, daily_order_no, customer_name, total, status, payment_method, order_date, is_open, token_number, table_number
FROM orders
WHERE (
    status = 'PendingPayment'
    OR (status = 'Paid' AND is_open = 1)
    OR (payment_method = 'paylater' AND status IN ('Preparing','PendingPayment','Completed'))
)
AND ( business_date = CURDATE() OR payment_method = 'paylater' )
";

if (!empty($search_value)) {
    $safe = $conn->real_escape_string($search_value);
    if ($search_type === 'exact_id') {
        $sql .= " AND order_id = " . (int)$search_value;
    } elseif ($search_type === 'order_id') {
        $sql .= " AND daily_order_no = '$safe'";
    } else {
        $sql .= " AND customer_name LIKE '%$safe%'";
    }
}

// Tab filter
if ($filter_tab === 'preparing') {
    $sql .= " AND status = 'Preparing'";
} elseif ($filter_tab === 'pending') {
    $sql .= " AND status = 'PendingPayment'";
} elseif ($filter_tab === 'paid_open') {
    $sql .= " AND status = 'Paid' AND is_open = 1";
} elseif ($filter_tab === 'paylater') {
    $sql .= " AND payment_method = 'paylater' AND status IN ('Preparing', 'PendingPayment', 'Completed')";
}

// Total matching rows (same filters, for pagination)
$count_main_sql = preg_replace(
    '/^\s*SELECT .*?FROM orders/s',
    'SELECT COUNT(*) AS c FROM orders',
    $sql,
    1
);
$total      = (int)(mysqli_fetch_assoc(mysqli_query($conn, $count_main_sql))['c'] ?? 0);
$sum_main_sql = preg_replace(
    '/^\s*SELECT .*?FROM orders/s',
    'SELECT COALESCE(SUM(total),0) AS s FROM orders',
    $sql,
    1
);
$total_unpaid = (float)(mysqli_fetch_assoc(mysqli_query($conn, $sum_main_sql))['s'] ?? 0);
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql .= " ORDER BY order_date DESC LIMIT $perPage OFFSET $offset";
$result = mysqli_query($conn, $sql);
$orders = [];
while ($row = mysqli_fetch_assoc($result)) {
    $orders[] = $row;
}

// ── Tab counts ──
$count_sql = "
SELECT status, is_open, payment_method, COUNT(*) as cnt
FROM orders
WHERE (
    status = 'PendingPayment'
    OR (status = 'Paid' AND is_open = 1)
    OR (payment_method = 'paylater' AND status IN ('Preparing','PendingPayment','Completed'))
)
AND ( business_date = CURDATE() OR payment_method = 'paylater' )
GROUP BY status, is_open, payment_method
";
$count_result = mysqli_query($conn, $count_sql);
$tab_counts = ['all' => 0, 'preparing' => 0, 'pending' => 0, 'paid_open' => 0, 'paylater' => 0];
while ($r = mysqli_fetch_assoc($count_result)) {
    $tab_counts['all'] += $r['cnt'];
    if ($r['status'] === 'Preparing') {
        $tab_counts['preparing'] += $r['cnt'];
        if ($r['payment_method'] === 'paylater') $tab_counts['paylater'] += $r['cnt'];
    }
    if ($r['status'] === 'PendingPayment') {
        $tab_counts['pending'] += $r['cnt'];
        if ($r['payment_method'] === 'paylater') $tab_counts['paylater'] += $r['cnt'];
    }
    if ($r['status'] === 'Completed' && $r['payment_method'] === 'paylater') {
        $tab_counts['paylater'] += $r['cnt'];
    }
    if ($r['status'] === 'Paid' && $r['is_open'] == 1) $tab_counts['paid_open'] += $r['cnt'];
}

// ── Shared empty-state markup (used by both AJAX and full render) ──
$noResultsHtml = '<div class="no-results">'
    . '<i class="fa-solid fa-check-circle" style="color:var(--success);"></i>'
    . '<h3>No orders found</h3>'
    . '<p>' . (!empty($search_value) ? 'Try a different search term.' : 'All orders are settled.') . '</p>'
    . '</div>';

// ── AJAX: rendered card list + pagination meta for the current page ──
if (isset($_GET['action']) && $_GET['action'] === 'list') {
    header('Content-Type: application/json');
    if ($orders) {
        ob_start();
        foreach ($orders as $order) include '_order_card.php';
        $html = ob_get_clean();
    } else {
        $html = $noResultsHtml;
    }

    $sig = '';
    foreach ($orders as $o) $sig .= $o['order_id'] . ':' . $o['status'] . '|';

    echo json_encode([
        'html'        => $html,
        'page'        => $page,
        'perPage'     => $perPage,
        'total'       => $total,
        'totalPages'  => $totalPages,
        'pageCount'   => count($orders),
        'totalUnpaid' => number_format($total_unpaid, 2),
        'sig'         => md5($sig),
        'tabCounts'   => $tab_counts,
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Orders | Bird's Nest Coffee</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg: #0c0c0c;
            --bg-card: #121212;
            --border: #1f1f1f;
            --border-hover: #2a2a2a;
            --accent: #d1904b;
            --accent-light: #e8b87a;
            --accent-dark: #a0702a;
            --text: #f5f5f5;
            --text-muted: #888888;
            --text-light: #ffffff;
            --success: #55e087;
            --warning: #f1c40f;
            --danger: #ff6b6b;
            --purple: #9b59b6;
            --info: #3498db;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.3);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.4);
            --shadow-accent: 0 4px 20px rgba(209,144,75,0.15);
            --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        [data-theme="light"] {
            --bg: #f0ece8; --bg-card: #faf8f6; --bg-card-hover: #f3ede8;
            --bg-input: #f5f1ed; --border: #ddd5cc; --border-hover: #c9bfb6;
            --text: #1e1a16; --text-muted: #6b5f55; --text-light: #1e1a16;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.08); --shadow-md: 0 4px 20px rgba(0,0,0,0.10);
        }
        [data-theme="light"] input,[data-theme="light"] select,[data-theme="light"] textarea {
            background-color: var(--bg-input) !important; color: var(--text) !important;
            border-color: var(--border) !important; color-scheme: light;
        }
        [data-theme="light"] .order-card { background: var(--bg-card); border-color: var(--border); }
        [data-theme="light"] .tab-btn { background: var(--bg-card); border-color: var(--border); color: var(--text); }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Poppins', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; padding: 32px 20px; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 10px; }

        .page-wrapper { max-width: 960px; margin: 0 auto; }

        /* Top bar */
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 10px; }
        .top-bar a { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 50px; background: var(--bg-card); border: 1px solid var(--border); color: var(--text); text-decoration: none; transition: var(--transition); font-weight: 500; font-size: 14px; }
        .top-bar a:hover { border-color: var(--accent); box-shadow: var(--shadow-accent); }
        .top-bar a i { color: var(--accent); }
        .theme-toggle { cursor: pointer; }

        /* Header */
        .header { text-align: center; margin-bottom: 28px; }
        .header h1 { font-size: 26px; color: var(--text-light); margin-bottom: 4px; }
        .header h1 i { color: var(--accent); margin-right: 8px; }
        .header p { color: var(--text-muted); font-size: 14px; }

        /* Search box */
        .search-box { background: var(--bg-card); border-radius: 16px; padding: 20px; border: 1px solid var(--border); margin-bottom: 20px; box-shadow: var(--shadow-md); }
        .search-form { display: flex; gap: 10px; flex-wrap: wrap; }
        .search-form select, .search-form input {
            padding: 11px 14px; border-radius: 10px; border: 1px solid var(--border);
            background: var(--bg); color: var(--text); font-size: 14px;
            font-family: 'Poppins', sans-serif; transition: var(--transition); outline: none;
        }
        .search-form select { flex: 0 0 150px; }
        .search-form input { flex: 1; min-width: 180px; }
        .search-form select:focus, .search-form input:focus { border-color: var(--accent); box-shadow: var(--shadow-accent); }
        .search-form button { padding: 11px 22px; background: var(--accent); border: none; border-radius: 10px; color: #000; font-weight: 600; cursor: pointer; transition: var(--transition); font-family: 'Poppins', sans-serif; display: flex; align-items: center; gap: 6px; }
        .search-form button:hover { background: var(--accent-light); transform: translateY(-1px); }
        .btn-clear { padding: 11px 14px; border-radius: 10px; border: 1px solid var(--border); background: var(--bg); color: var(--text-muted); text-decoration: none; display: flex; align-items: center; transition: var(--transition); }
        .btn-clear:hover { color: var(--danger); border-color: var(--danger); }

        /* Tab filters */
        .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
        .tab-btn {
            display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px;
            border-radius: 50px; border: 1px solid var(--border); background: var(--bg-card);
            color: var(--text-muted); text-decoration: none; font-size: 13px; font-weight: 600;
            transition: var(--transition); cursor: pointer;
        }
        .tab-btn:hover { border-color: var(--accent); color: var(--accent); }
        .tab-btn.active { background: var(--accent); border-color: var(--accent); color: #000; }
        .tab-btn.active .tab-count { background: rgba(0,0,0,0.2); color: #000; }
        .tab-count { background: rgba(255,255,255,0.1); padding: 1px 8px; border-radius: 20px; font-size: 11px; }
        .tab-btn.tab-addable { border-color: var(--accent); color: var(--accent); }
        .tab-btn.tab-addable:hover, .tab-btn.tab-addable.active { background: var(--accent); color: #1a1a1a; border-color: var(--accent); }
        .tab-btn.tab-paylater { border-color: var(--purple); color: var(--purple); }
        .tab-btn.tab-paylater:hover, .tab-btn.tab-paylater.active { background: var(--purple); color: #fff; border-color: var(--purple); }

        /* Guidance callout */
        .guidance-callout {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            background: rgba(209,144,75,0.08);
            border: 1px solid rgba(209,144,75,0.25);
            border-left: 3px solid var(--accent);
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 18px;
            animation: fadeSlideIn 0.4s ease;
        }

        @keyframes fadeSlideIn {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .guidance-icon { color: var(--accent); font-size: 18px; flex-shrink: 0; margin-top: 1px; }

        .guidance-text {
            flex: 1;
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.6;
        }

        .guidance-text strong { color: var(--text-light); }

        .guide-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .guide-pill.cash { background: rgba(85,224,135,0.15); color: #55e087; border: 1px solid rgba(85,224,135,0.25); }
        .guide-pill.bakong { background: rgba(52,152,219,0.15); color: #5dade2; border: 1px solid rgba(52,152,219,0.25); }

        .guidance-dismiss {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 14px;
            padding: 2px 4px;
            flex-shrink: 0;
            transition: color 0.2s;
            line-height: 1;
        }

        .guidance-dismiss:hover { color: var(--text-light); }

        /* Results header */
        .results-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 8px; }
        .results-header h2 { font-size: 18px; color: var(--text-light); }
        .results-header .meta { display: flex; gap: 10px; align-items: center; }
        .count-badge { background: var(--purple); color: #fff; padding: 3px 14px; border-radius: 50px; font-size: 12px; font-weight: 700; }
        .total-amount { color: var(--accent); font-weight: 700; font-size: 14px; }

        /* Pagination bar */
        .pagination-bar{display:flex;justify-content:center;align-items:center;gap:6px;margin:22px 0 8px;flex-wrap:wrap;}
        .pagination-bar button{min-width:38px;height:38px;padding:0 10px;border-radius:10px;border:1px solid var(--border);
          background:var(--bg-card);color:var(--text);font-family:inherit;font-size:14px;cursor:pointer;transition:all .15s;}
        .pagination-bar button:hover:not(:disabled){border-color:var(--accent);color:var(--accent);}
        .pagination-bar button.active{background:var(--accent);border-color:var(--accent);color:#1a1a1a;font-weight:600;}
        .pagination-bar button:disabled{opacity:.4;cursor:default;}
        .pagination-bar .ellipsis{color:var(--text-muted);padding:0 4px;}

        /* Order card */
        .order-card {
            background: var(--bg-card); border-radius: 16px; padding: 18px 20px;
            border: 1px solid var(--border); margin-bottom: 12px;
            transition: var(--transition); box-shadow: var(--shadow-sm);
        }
        .order-card:hover { border-color: var(--border-hover); box-shadow: var(--shadow-md); }
        .order-card.can-add { border-left: 3px solid var(--accent); }
        .order-card.is-paid-open { border-left: 3px solid var(--success); }

        .card-top { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; }
        .card-main-info { display: flex; gap: 20px; flex-wrap: wrap; align-items: center; }

        .token-badge { font-size: 26px; font-weight: 800; color: var(--accent); min-width: 48px; text-align: center; }
        .token-badge.empty { font-size: 14px; color: var(--text-muted); font-weight: 400; }

        .info-group { display: flex; flex-direction: column; }
        .info-label { font-size: 10px; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
        .info-value { font-size: 15px; font-weight: 600; color: var(--text-light); }
        .info-value.total { color: var(--accent); }
        .info-value.small { font-size: 13px; }

        /* Status badges */
        .status-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .status-badge.preparing { background: rgba(209,144,75,0.15); color: var(--accent); }
        .status-badge.pendingpayment { background: rgba(255,183,77,0.15); color: #ffb74d; }
        .status-badge.paid { background: rgba(85,224,135,0.15); color: var(--success); }
        .status-badge.refunded { background: rgba(155,89,182,0.15); color: var(--purple); }

        .open-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 700; background: rgba(209,144,75,0.12); color: var(--accent); border: 1px solid rgba(209,144,75,0.3); margin-left: 6px; }
        .open-badge i { font-size: 9px; }

        .card-bottom { margin-top: 14px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; border-top: 1px solid var(--border); padding-top: 12px; }
        .card-meta { font-size: 12px; color: var(--text-muted); display: flex; gap: 12px; flex-wrap: wrap; }
        .card-meta span { display: flex; align-items: center; gap: 5px; }

        /* Action buttons */
        .actions { display: flex; gap: 6px; flex-wrap: wrap; }
        .btn { padding: 7px 14px; border-radius: 8px; border: none; font-weight: 600; cursor: pointer; transition: var(--transition); font-family: 'Poppins', sans-serif; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; font-size: 12px; }
        .btn-add { background: var(--accent); color: #000; }
        .btn-add:hover { background: var(--accent-light); transform: translateY(-2px); box-shadow: var(--shadow-accent); }
        .btn-pay-cash { background: var(--success); color: #000; }
        .btn-pay-cash:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(85,224,135,0.3); }
        .btn-pay-bakong { background: var(--info); color: #fff; }
        .btn-pay-bakong:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(52,152,219,0.3); }
        .btn-receipt { background: #c0392b; color: #fff; }
        .btn-receipt:hover { background: #e74c3c; transform: translateY(-2px); }
        .btn-view { background: var(--purple); color: #fff; }
        .btn-view:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(155,89,182,0.3); }
        .btn-close { background: transparent; color: var(--text-muted); border: 1px solid var(--border); }

        /* ── Loyalty Modal ── */
        #lpModal {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.75); z-index: 9999;
            align-items: center; justify-content: center; padding: 20px;
        }
        .lp-modal-box {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 16px; padding: 28px 24px; max-width: 380px; width: 100%;
            box-shadow: var(--shadow-lg);
        }
        .lp-modal-box h3 { font-size: 16px; font-weight: 700; color: var(--text); margin-bottom: 4px; display: flex; align-items: center; gap: 8px; }
        .lp-modal-box h3 i { color: var(--purple); }
        .lp-modal-subtitle { font-size: 12px; color: var(--text-muted); margin-bottom: 18px; }
        .lp-input-row { display: flex; gap: 8px; margin-bottom: 10px; }
        .lp-input-row input {
            flex: 1; padding: 9px 12px; background: #1a1a1a; border: 1px solid var(--border);
            border-radius: 8px; color: var(--text); font-family: 'Poppins', sans-serif;
            font-size: 13px; outline: none; transition: var(--transition);
        }
        .lp-input-row input:focus { border-color: var(--purple); }
        .btn-lp-lookup {
            padding: 9px 14px; background: rgba(155,89,182,0.15); border: 1px solid rgba(155,89,182,0.3);
            border-radius: 8px; color: var(--purple); font-size: 12px; font-weight: 600;
            cursor: pointer; transition: var(--transition); font-family: 'Poppins', sans-serif;
        }
        .btn-lp-lookup:hover { background: rgba(155,89,182,0.25); }
        .lp-status { font-size: 12px; min-height: 18px; margin-bottom: 16px; }
        .lp-status.lp-success { color: var(--success); }
        .lp-status.lp-error   { color: var(--danger); }
        .lp-status.lp-loading { color: var(--text-muted); }
        .lp-modal-actions { display: flex; gap: 8px; }
        .btn-lp-skip {
            flex: 1; padding: 10px; background: transparent; border: 1px solid var(--border);
            border-radius: 8px; color: var(--text-muted); font-size: 13px; font-weight: 600;
            cursor: pointer; transition: var(--transition); font-family: 'Poppins', sans-serif;
        }
        .btn-lp-skip:hover { border-color: var(--text-muted); color: var(--text); }
        .btn-lp-confirm {
            flex: 2; padding: 10px; background: var(--purple); border: none; border-radius: 8px;
            color: #fff; font-size: 13px; font-weight: 600; cursor: pointer; transition: var(--transition);
            font-family: 'Poppins', sans-serif; display: flex; align-items: center; justify-content: center; gap: 6px;
        }
        .btn-lp-confirm:hover:not(:disabled) { filter: brightness(1.15); }
        .btn-lp-confirm:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-close:hover { color: var(--danger); border-color: var(--danger); }
        .btn-cancel-order { background: transparent; color: var(--danger); border: 1px solid rgba(231,76,60,.35); }
        .btn-cancel-order:hover { background: rgba(231,76,60,.1); border-color: var(--danger); }
        .btn-edit { background: transparent; color: var(--purple); border: 1px solid rgba(155,89,182,.4); }
        .btn-edit:hover { background: var(--purple); color: #fff; border-color: var(--purple); transform: translateY(-2px); box-shadow: 0 4px 15px rgba(155,89,182,.3); }

        /* Empty state */
        .no-results { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .no-results i { font-size: 48px; margin-bottom: 16px; display: block; }

        /* Hidden for JS filtering */
        .order-card.hidden { display: none; }

        /* Overdue pay later */
        .order-card.overdue { border-left: 3px solid var(--danger); animation: pulse-red 2s ease-in-out infinite; }
        @keyframes pulse-red {
            0%, 100% { box-shadow: var(--shadow-sm); }
            50% { box-shadow: 0 0 16px rgba(255,107,107,0.35); }
        }
        .overdue-warning {
            font-size: 12px; font-weight: 600; color: var(--danger);
            display: flex; align-items: center; gap: 6px; margin-top: 6px;
        }
        .card-meta .age-overdue { color: var(--danger); font-weight: 700; }

        .table-edit-wrap { display: inline-flex; align-items: center; gap: 4px; }
        .table-edit-btn {
            background: none; border: none; color: var(--text-muted); cursor: pointer;
            font-size: 11px; padding: 0 2px; opacity: 0.6; transition: opacity 0.2s;
        }
        .table-edit-btn:hover { opacity: 1; color: var(--accent); }
        .table-input-wrap { display: inline-flex; align-items: center; gap: 4px; }
        .table-input {
            background: var(--bg); border: 1px solid var(--border-hover); border-radius: 6px;
            color: var(--text); font-size: 12px; padding: 2px 8px; width: 80px;
            font-family: inherit; outline: none;
        }
        .table-input:focus { border-color: var(--accent); }
        .table-save-btn {
            background: var(--accent); border: none; border-radius: 6px; color: #000;
            font-size: 11px; font-weight: 600; padding: 2px 8px; cursor: pointer;
        }
        .table-cancel-btn {
            background: none; border: none; color: var(--text-muted); font-size: 12px;
            cursor: pointer; padding: 0 2px;
        }

        @media (max-width: 640px) {
            body { padding: 20px 12px; }
            .search-form { flex-direction: column; }
            .search-form select, .search-form input, .search-form button { width: 100%; }
            .card-top { flex-direction: column; }
            .actions { justify-content: flex-start; }
        }
    </style>
</head>
<body>

<div class="page-wrapper">

    <!-- Top Bar -->
    <div class="top-bar">
        <a href="dashboard.php" style="display:inline-flex;align-items:center;gap:7px;color:#d1904b;text-decoration:none;font-size:13px;font-weight:600;padding:7px 14px;border-radius:10px;border:1px solid rgba(209,144,75,.35);background:rgba(209,144,75,.08);transition:all .2s;"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
        <button class="top-bar a theme-toggle" onclick="toggleTheme()" style="background:var(--bg-card);border:1px solid var(--border);border-radius:50px;padding:8px 16px;cursor:pointer;color:var(--text);font-family:'Poppins',sans-serif;font-size:14px;font-weight:500;display:inline-flex;align-items:center;gap:8px;">
            <i id="themeIcon" class="fa-solid fa-moon" style="color:var(--accent);"></i>
            <span id="themeText">Dark</span>
        </button>
    </div>

    <!-- Header -->
    <div class="header">
        <h1><i class="fa-solid fa-magnifying-glass"></i> Find Orders</h1>
        <p>Search, filter, and manage all active orders in one place</p>
    </div>

    <!-- Search Box -->
    <div class="search-box">
        <form class="search-form" method="GET" action="">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($filter_tab) ?>">
            <select name="search_type">
                <option value="order_id"      <?= $search_type === 'order_id'      ? 'selected' : '' ?>>Order #</option>
                <option value="customer_name" <?= $search_type === 'customer_name' ? 'selected' : '' ?>>Customer Name</option>
            </select>
            <input type="text" name="search_value" placeholder="Search orders..." value="<?= htmlspecialchars($search_value) ?>" autofocus>
            <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
            <?php if (!empty($search_value)): ?>
            <a href="find_order.php?tab=<?= htmlspecialchars($filter_tab) ?>" class="btn-clear">
                <i class="fa-solid fa-xmark"></i>
            </a>
            <?php endif; ?>
        </form>

    </div>

    <!-- Tab Filters -->
    <div class="tabs">
        <?php if (!$is_cashier): ?>
        <a href="?tab=all<?= !empty($search_value) ? '&search_type='.urlencode($search_type).'&search_value='.urlencode($search_value) : '' ?>"
           class="tab-btn <?= $filter_tab === 'all' ? 'active' : '' ?>">
            <i class="fa-solid fa-layer-group"></i> All Active
            <span class="tab-count" data-tab="all"><?= $tab_counts['all'] ?></span>
        </a>
        <a href="?tab=pending<?= !empty($search_value) ? '&search_type='.urlencode($search_type).'&search_value='.urlencode($search_value) : '' ?>"
           class="tab-btn <?= $filter_tab === 'pending' ? 'active' : '' ?>">
            <i class="fa-solid fa-clock"></i> Pending Payment
            <span class="tab-count" data-tab="pending"><?= $tab_counts['pending'] ?></span>
        </a>
        <?php /* "Open Tabs" (paid_open = Paid + is_open=1) tab hidden — redundant with Pay Later
                 and no live flow deliberately creates that state. Filter/poll code left dormant
                 (find_order.php:74 etc.) so it can be restored by re-adding this button. */ ?>
        <?php endif; ?>
        <a href="?tab=paylater<?= !empty($search_value) ? '&search_type='.urlencode($search_type).'&search_value='.urlencode($search_value) : '' ?>"
           class="tab-btn tab-paylater <?= $filter_tab === 'paylater' ? 'active' : '' ?>">
            <i class="fa-solid fa-wallet"></i> Pay Later
            <span class="tab-count" data-tab="paylater"><?= $tab_counts['paylater'] ?></span>
        </a>
    </div>

    <?php if ($filter_tab === 'pending'): ?>
    <div class="guidance-callout" id="payGuidance">
        <div class="guidance-icon"><i class="fa-solid fa-hand-point-right"></i></div>
        <div class="guidance-text">
            <strong>How to collect payment:</strong>
            Find the customer's order below, then click <span class="guide-pill cash"><i class="fa-solid fa-money-bill-wave"></i> Cash</span>
            for cash payment or <span class="guide-pill bakong"><i class="fa-solid fa-qrcode"></i> Bakong</span> to show the QR code.
        </div>
        <button class="guidance-dismiss" onclick="dismissGuidance()" title="Dismiss (won't show again)">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
    <script>
        function dismissGuidance(){
            var g=document.getElementById('payGuidance'); if(g) g.style.display='none';
            try{ localStorage.setItem('fo_guidance_dismissed','1'); }catch(e){}
        }
        try{ if(localStorage.getItem('fo_guidance_dismissed')==='1'){ var _g=document.getElementById('payGuidance'); if(_g) _g.style.display='none'; } }catch(e){}
    </script>
    <?php endif; ?>

    <!-- Results Header -->
    <div class="results-header">
        <h2 id="resultsTitle">
            <?php
            $tabLabels = ['all'=>'All Active Orders','preparing'=>'Preparing','pending'=>'Pending Payment','paid_open'=>'Open Tabs','paylater'=>'Pay Later Orders'];
            echo $tabLabels[$filter_tab] ?? 'Orders';
            ?>
        </h2>
        <div class="meta">
            <span class="count-badge" id="visibleCount"><?= count($orders) ?> order<?= count($orders) != 1 ? 's' : '' ?></span>
            <span class="total-amount" title="Total outstanding balance across these unpaid orders">Outstanding $<?= number_format($total_unpaid, 2) ?></span>
        </div>
    </div>

    <!-- Order Cards (container always present so silent refresh can repopulate) -->
    <div id="orderList">
    <?php if (count($orders) > 0): ?>
        <?php foreach ($orders as $order) include '_order_card.php'; ?>
    <?php else: ?>
        <?= $noResultsHtml ?>
    <?php endif; ?>
    </div>
    <div id="pagination" class="pagination-bar"></div>

    <div id="noFilterResults" style="display:none;" class="no-results">
        <i class="fa-solid fa-filter" style="color:var(--text-muted);"></i>
        <h3>No matching orders</h3>
        <p>Try a different search term.</p>
    </div>


</div><!-- end page-wrapper -->

<!-- ── Tender Modal (cash settlement, panel fetched from admin_pay_cash.php) ── -->
<div id="tenderModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);
     z-index:1200;align-items:center;justify-content:center;padding:16px;">
    <div id="tenderModalBody" style="width:100%;max-width:420px;max-height:94vh;overflow-y:auto;"></div>
</div>

<!-- ── Loyalty Card Modal (Pay Later) ── -->
<div id="lpModal">
    <div class="lp-modal-box">
        <h3><i class="fa-solid fa-id-card"></i> Loyalty Card</h3>
        <p class="lp-modal-subtitle">Scan or enter the customer's loyalty card number (optional)</p>
        <div class="lp-input-row">
            <input type="text" id="lpCardInput" placeholder="e.g. LC-001234" autocomplete="off">
            <button class="btn-lp-lookup" onclick="lpLookupCard()">
                <i class="fa-solid fa-magnifying-glass"></i> Check
            </button>
        </div>
        <div id="lpCardStatus" class="lp-status"></div>
        <div class="lp-modal-actions">
            <button class="btn-lp-skip" onclick="skipLpPayment()">
                <i class="fa-solid fa-forward"></i> Skip
            </button>
            <button class="btn-lp-confirm" id="lpConfirmBtn" onclick="confirmLpPayment()">
                <i class="fa-solid fa-check"></i> Confirm
            </button>
        </div>
    </div>
</div>

<script>
// ── Theme ──
(function() { if (localStorage.getItem('theme') === 'light') { document.documentElement.setAttribute('data-theme','light'); } })();
function toggleTheme() {
    const html = document.documentElement;
    const isLight = html.getAttribute('data-theme') === 'light';
    if (isLight) { html.removeAttribute('data-theme'); localStorage.setItem('theme','dark'); document.getElementById('themeIcon').className='fa-solid fa-moon'; document.getElementById('themeText').textContent='Dark'; }
    else { html.setAttribute('data-theme','light'); localStorage.setItem('theme','light'); document.getElementById('themeIcon').className='fa-solid fa-sun'; document.getElementById('themeText').textContent='Light'; }
}
document.addEventListener('DOMContentLoaded', function() {
    if (localStorage.getItem('theme') === 'light') { document.getElementById('themeIcon').className='fa-solid fa-sun'; document.getElementById('themeText').textContent='Light'; }
});

// ── Tender modal ──
// The panel is FETCHED from admin_pay_cash.php rather than rebuilt here, so the
// tender markup has exactly one source. The form inside POSTs normally, so
// settling still lands on the success screen and its receipt.
async function openTenderModal(url) {
    // Only cash has a tender to enter. Bakong shows a QR and keeps navigating.
    if (url.indexOf('admin_pay_cash.php') === -1) { window.location.href = url; return; }

    const modal = document.getElementById('tenderModal');
    const body  = document.getElementById('tenderModalBody');
    body.innerHTML = '<div style="padding:40px;color:#aaa;text-align:center">' +
                     '<i class="fa-solid fa-spinner fa-spin fa-2x"></i></div>';
    modal.style.display = 'flex';
    try {
        const sep  = url.indexOf('?') === -1 ? '?' : '&';
        const resp = await fetch(url + sep + 'partial=1', { credentials: 'same-origin' });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        body.innerHTML = await resp.text();
        // innerHTML does not execute <script>, so re-run them to wire up the
        // tender buttons, the change calculator and the prefill.
        body.querySelectorAll('script').forEach(function (old) {
            const s = document.createElement('script');
            s.textContent = old.textContent;
            document.body.appendChild(s);
            s.remove();
        });
    } catch (e) {
        // Never strand the cashier mid-payment: fall back to the page that always worked.
        window.location.href = url;
    }
}
function closeTenderModal() {
    const m = document.getElementById('tenderModal');
    if (m) m.style.display = 'none';
}

// ── Loyalty modal (Pay Later orders) ──
let lpDestUrl = '';
let lpOrderId = 0;

function interceptPayLater(event, el) {
    event.preventDefault();
    lpDestUrl = el.dataset.lpDest;
    lpOrderId = parseInt(el.dataset.lpOrder, 10);
    document.getElementById('lpCardInput').value = '';
    document.getElementById('lpCardStatus').innerHTML = '';
    document.getElementById('lpCardStatus').className = 'lp-status';
    const btn = document.getElementById('lpConfirmBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Confirm';
    document.getElementById('lpModal').style.display = 'flex';
    setTimeout(() => document.getElementById('lpCardInput').focus(), 80);
    return false;
}

function closeLpModal() { document.getElementById('lpModal').style.display = 'none'; }

async function lpLookupCard() {
    const val = document.getElementById('lpCardInput').value.trim();
    const status = document.getElementById('lpCardStatus');
    if (!val) { status.className = 'lp-status lp-error'; status.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> Enter a card number'; return; }
    status.className = 'lp-status lp-loading'; status.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Looking up...';
    try {
        const res = await fetch('loyalty_lookup.php?loyalty_id=' + encodeURIComponent(val));
        const data = await res.json();
        if (data.found) {
            status.className = 'lp-status lp-success';
            status.innerHTML = '<i class="fa-solid fa-check-circle"></i> Found — ' + data.points + ' pts currently';
        } else {
            status.className = 'lp-status lp-error';
            status.innerHTML = '<i class="fa-solid fa-times-circle"></i> ' + (data.message || 'Card not found');
        }
    } catch (e) {
        status.className = 'lp-status lp-error'; status.innerHTML = '<i class="fa-solid fa-times-circle"></i> Lookup failed';
    }
}

async function confirmLpPayment() {
    const cardVal = document.getElementById('lpCardInput').value.trim();
    const btn = document.getElementById('lpConfirmBtn');
    const status = document.getElementById('lpCardStatus');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';

    if (cardVal) {
        try {
            const body = new URLSearchParams({ order_id: lpOrderId, loyalty_id: cardVal });
            const res = await fetch('set_order_loyalty.php', { method: 'POST', body });
            const data = await res.json();
            if (!data.success) {
                status.className = 'lp-status lp-error';
                status.innerHTML = '<i class="fa-solid fa-times-circle"></i> ' + (data.message || 'Failed to link card');
                btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-check"></i> Confirm';
                return;
            }
        } catch (e) {
            status.className = 'lp-status lp-error'; status.innerHTML = '<i class="fa-solid fa-times-circle"></i> Network error. Try again.';
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-check"></i> Confirm';
            return;
        }
    }
    closeLpModal();
    openTenderModal(lpDestUrl);
}

function skipLpPayment() { closeLpModal(); openTenderModal(lpDestUrl); }

document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('lpModal');
    if (modal) modal.addEventListener('click', e => { if (e.target === modal) closeLpModal(); });
    // Backdrop dismiss, but not a click that started inside the panel.
    const tm = document.getElementById('tenderModal');
    if (tm) tm.addEventListener('click', e => { if (e.target === tm) closeTenderModal(); });
    const inp = document.getElementById('lpCardInput');
    if (inp) inp.addEventListener('keydown', e => { if (e.key === 'Enter') lpLookupCard(); });
});

// ── Close order (lock from additions) ──
function cancelOrderFromFind(orderId, btn) {
    const reason = prompt('Reason for cancellation (required):');
    if (reason === null) return;
    if (!reason.trim()) { alert('Please provide a reason.'); return; }
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    fetch('cancel_order.php?order_id=' + orderId, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'cancel_reason=' + encodeURIComponent(reason.trim())
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            btn.closest('.order-card').remove();
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-ban"></i>';
            alert(data.error || 'Failed to cancel order.');
        }
    });
}

</script>
<script src="animations.js?v=<?= @filemtime('animations.js') ?>"></script>
<script>
// Shared flag — polling skips reload while any table edit is open
let tableEditOpen = false;

setInterval(function() {
    if (tableEditOpen) return;            // don't disturb an open edit
    if (document.getElementById('lpModal').style.display === 'flex') return; // skip while loyalty modal open
    loadPage(currentPage, { silent: true });
}, 5000);

// ── Inline table number edit ──
function bindCardHandlers() {
    document.querySelectorAll('.table-edit-wrap').forEach(function(wrap) {
        if (wrap.dataset.bound === '1') return; // idempotent
        wrap.dataset.bound = '1';
        const orderId    = wrap.dataset.order;
        const labelEl    = wrap.querySelector('.table-label');
        const editBtn    = wrap.querySelector('.table-edit-btn');
        const inputWrap  = wrap.querySelector('.table-input-wrap');
        const input      = wrap.querySelector('.table-input');
        const saveBtn    = wrap.querySelector('.table-save-btn');
        const cancelBtn  = wrap.querySelector('.table-cancel-btn');

        // Inline stand editing was removed from the card; skip if controls absent.
        if (!editBtn || !inputWrap || !input || !saveBtn || !cancelBtn) return;

        editBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            tableEditOpen = true;
            editBtn.style.display = 'none';
            labelEl.style.display = 'none';
            inputWrap.style.display = 'inline-flex';
            input.focus();
            input.select();
        });

        cancelBtn.addEventListener('click', function() {
            tableEditOpen = false;
            inputWrap.style.display = 'none';
            editBtn.style.display = '';
            labelEl.style.display = '';
        });

        saveBtn.addEventListener('click', async function() {
            const val = input.value.trim();
            const body = new URLSearchParams({ order_id: orderId, table_number: val });
            try {
                const res = await fetch('update_table.php', { method: 'POST', body });
                const data = await res.json();
                if (data.ok) {
                    tableEditOpen = false;
                    labelEl.textContent = val ? 'Table ' + val : 'No table';
                    input.value = val;
                    inputWrap.style.display = 'none';
                    editBtn.style.display = '';
                    labelEl.style.display = '';
                }
            } catch(e) {}
        });

        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') saveBtn.click();
            if (e.key === 'Escape') cancelBtn.click();
        });
    });
}
bindCardHandlers();

// ── AJAX pagination ──
let currentPage = <?= (int)$page ?>;
let lastSig = null;

function currentQuery() {
    const p = new URLSearchParams(location.search);
    return {
        tab: p.get('tab') || <?= json_encode($filter_tab) ?>,
        search_type: p.get('search_type') || '',
        search_value: p.get('search_value') || ''
    };
}

async function loadPage(n, opts = {}) {
    const q = currentQuery();
    const url = 'find_order.php?action=list&tab=' + encodeURIComponent(q.tab)
              + '&search_type=' + encodeURIComponent(q.search_type)
              + '&search_value=' + encodeURIComponent(q.search_value)
              + '&page=' + n;
    let data;
    try { data = await (await fetch(url)).json(); }
    catch (e) { return; }

    if (opts.silent && data.sig === lastSig) { currentPage = data.page; return; }

    const list = document.getElementById('orderList');
    if (list) list.innerHTML = data.html;
    currentPage = data.page;
    lastSig = data.sig;
    renderPagination(data.page, data.totalPages);
    bindCardHandlers();
    updateMeta(data);
}

// Keep the results header and tab pills in sync after an AJAX swap.
function updateMeta(data) {
    const vc = document.getElementById('visibleCount');
    if (vc && typeof data.pageCount !== 'undefined') vc.textContent = data.pageCount + ' order' + (data.pageCount != 1 ? 's' : '');
    const ta = document.querySelector('.total-amount');
    if (ta && typeof data.totalUnpaid !== 'undefined') ta.textContent = 'Outstanding $' + data.totalUnpaid;
    if (data.tabCounts) {
        document.querySelectorAll('.tab-count[data-tab]').forEach(function(span) {
            const k = span.dataset.tab;
            if (typeof data.tabCounts[k] !== 'undefined') span.textContent = data.tabCounts[k];
        });
    }
}

function renderPagination(page, totalPages) {
    const bar = document.getElementById('pagination');
    if (!bar) return;
    if (totalPages <= 1) { bar.innerHTML = ''; return; }
    const btn = (label, target, {active = false, disabled = false} = {}) =>
        `<button ${disabled ? 'disabled' : ''} class="${active ? 'active' : ''}" data-pg="${target}">${label}</button>`;
    let html = '';
    html += btn('&laquo;', 1, {disabled: page === 1});
    html += btn('&lsaquo;', page - 1, {disabled: page === 1});
    const win = [];
    for (let i = Math.max(1, page - 2); i <= Math.min(totalPages, page + 2); i++) win.push(i);
    if (win[0] > 1) { html += btn('1', 1); if (win[0] > 2) html += '<span class="ellipsis">…</span>'; }
    win.forEach(i => html += btn(i, i, {active: i === page}));
    const last = win[win.length - 1];
    if (last < totalPages) { if (last < totalPages - 1) html += '<span class="ellipsis">…</span>'; html += btn(totalPages, totalPages); }
    html += btn('&rsaquo;', page + 1, {disabled: page === totalPages});
    html += btn('&raquo;', totalPages, {disabled: page === totalPages});
    bar.innerHTML = html;
    bar.querySelectorAll('button[data-pg]').forEach(b =>
        b.addEventListener('click', () => loadPage(parseInt(b.dataset.pg, 10))));
}

renderPagination(currentPage, <?= (int)$totalPages ?>);
lastSig = <?= json_encode(md5(implode('|', array_map(fn($o) => $o['order_id'].':'.$o['status'], $orders)))) ?>;
</script>
</body>
</html>