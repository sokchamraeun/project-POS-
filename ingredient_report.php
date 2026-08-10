<?php
require 'auth.php';
require 'config.php';
if (!can('ingredients')) { header("Location: dashboard.php?denied=1"); exit; }

/* ── DATE RANGE ── */
$today     = date('Y-m-d');
$default_f = date('Y-m-d', strtotime('-30 days'));
$date_from = trim($_GET['from_date'] ?? $_GET['from'] ?? $default_f);
$date_to   = trim($_GET['to_date']   ?? $_GET['to']   ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = $default_f;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = $today;
if ($date_from > $date_to) [$date_from, $date_to] = [$date_to, $date_from];

$days_in_range = (int)(new DateTime($date_from))->diff(new DateTime($date_to))->days + 1;

/* ── MAIN QUERY ── */
$stmt = $conn->prepare("
    SELECT
        i.ingredient_id,
        i.ingredient_name,
        i.unit,
        i.stock_quantity,
        i.minimum_stock,
        COALESCE(SUM(CASE
            WHEN h.change_type = 'order_deduct' THEN h.amount
            WHEN h.change_type = 'manual_adjust' AND h.amount < 0 THEN ABS(h.amount)
            ELSE 0
        END), 0) AS total_consumed,
        COALESCE(SUM(CASE
            WHEN h.change_type IN ('quick_restock','po_received','order_restore') THEN h.amount
            WHEN h.change_type = 'manual_adjust' AND h.amount > 0 THEN h.amount
            ELSE 0
        END), 0) AS total_added,
        COUNT(h.id) AS event_count
    FROM ingredients i
    LEFT JOIN ingredient_history h
        ON h.ingredient_id = i.ingredient_id
        AND DATE(h.created_at) BETWEEN ? AND ?
    GROUP BY i.ingredient_id, i.ingredient_name, i.unit, i.stock_quantity, i.minimum_stock
    ORDER BY total_consumed DESC, i.ingredient_name ASC
");
$stmt->bind_param("ss", $date_from, $date_to);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* ── COMPUTE PER-ROW DERIVED VALUES ── */
$grand_consumed = 0;
$grand_added    = 0;
$grand_events   = 0;
$active_ings    = 0;

foreach ($rows as &$r) {
    $r['consumed']    = (float)$r['total_consumed'];
    $r['added']       = (float)$r['total_added'];
    $r['net']         = $r['added'] - $r['consumed'];
    $r['daily_avg']   = $days_in_range > 0 ? $r['consumed'] / $days_in_range : 0;
    $stock            = (float)$r['stock_quantity'];
    $r['days_left']   = ($r['daily_avg'] > 0 && $stock > 0) ? (int)round($stock / $r['daily_avg']) : null;

    $grand_consumed += $r['consumed'];
    $grand_added    += $r['added'];
    $grand_events   += (int)$r['event_count'];
    if ($r['consumed'] > 0 || $r['added'] > 0) $active_ings++;
}
unset($r);

$grand_net = $grand_added - $grand_consumed;

function fmtR($n) { return rtrim(rtrim(number_format((float)$n, 4, '.', ''), '0'), '.'); }
function he($s)   { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inventory Report | Bird's Nest Coffee</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="flex h-screen w-screen overflow-hidden bg-[#0e0e10] app-layout">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<main class="app-main flex-1 h-full overflow-y-auto er-container">
    <?php
    $report_category = 'Inventory';
    $report_title    = 'Inventory Report';
    $filter_stock    = trim($_GET['stock_status'] ?? '');
    
    $filter_options  = [
        [
            'name' => 'stock_status',
            'label' => 'Stock Alert Status',
            'options' => ['' => 'All Items', 'low' => 'Low Stock Warning', 'out' => 'Out of Stock'],
            'selected' => $filter_stock
        ]
    ];
    $export_excel_url = "#";
    $export_pdf_url   = "ingredients_pdf.php";
    require __DIR__ . '/report_header.php';

    // Apply stock filter
    $filtered_rows = [];
    $low_count = 0;
    $out_count = 0;
    foreach ($rows as $r) {
        $stk = (float)$r['stock_quantity'];
        $min = (float)$r['minimum_stock'];
        if ($stk <= 0) $out_count++;
        elseif ($stk <= $min) $low_count++;

        if ($filter_stock === 'low' && $stk > $min) continue;
        if ($filter_stock === 'out' && $stk > 0) continue;
        $filtered_rows[] = $r;
    }
    ?>

    <!-- Data Table -->
    <div class="er-table-card">
        <div class="er-table-wrap">
            <table class="er-table">
                <thead>
                    <tr>
                        <th>Doc. Type</th>
                        <th>Item ID</th>
                        <th>Ingredient Name</th>
                        <th>Unit</th>
                        <th>Qty Consumed</th>
                        <th>Qty Restocked</th>
                        <th>Net Usage</th>
                        <th>Current Stock</th>
                        <th>Min Stock Alert</th>
                        <th>Est. Days Left</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($filtered_rows)): ?>
                    <tr class="no-data">
                        <td colspan="11" class="no-data">No data</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($filtered_rows as $r): ?>
                    <?php
                        $stk = (float)$r['stock_quantity'];
                        $min = (float)$r['minimum_stock'];
                        $is_out = ($stk <= 0);
                        $is_low = ($stk > 0 && $stk <= $min);
                    ?>
                    <tr>
                        <td><span class="px-2 py-0.5 rounded text-xs bg-cyan-500/10 text-cyan-400 font-medium">Stock Item</span></td>
                        <td class="font-bold text-slate-400">#ING-<?= (int)$r['ingredient_id'] ?></td>
                        <td class="font-bold text-white"><?= htmlspecialchars($r['ingredient_name']) ?></td>
                        <td><span class="px-2 py-0.5 rounded text-xs bg-slate-800 text-slate-300"><?= htmlspecialchars($r['unit']) ?></span></td>
                        <td class="text-amber-400 font-semibold"><?= fmtR($r['consumed']) ?></td>
                        <td class="text-emerald-400 font-semibold"><?= fmtR($r['added']) ?></td>
                        <td class="font-semibold <?= $r['net'] >= 0 ? 'text-emerald-400' : 'text-red-400' ?>"><?= fmtR($r['net']) ?></td>
                        <td class="font-bold text-white"><?= fmtR($stk) ?></td>
                        <td class="text-slate-400"><?= fmtR($min) ?></td>
                        <td>
                            <?php if ($r['days_left'] !== null): ?>
                                <span class="px-2 py-0.5 rounded text-xs font-bold <?= $r['days_left'] <= 3 ? 'bg-red-500/15 text-red-400' : 'bg-amber-500/15 text-amber-400' ?>">
                                    ~<?= $r['days_left'] ?> days
                                </span>
                            <?php else: ?>
                                <span class="text-slate-500 text-xs">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($is_out): ?>
                                <span class="px-2 py-0.5 rounded text-xs font-bold bg-red-500/20 text-red-400">OUT OF STOCK</span>
                            <?php elseif ($is_low): ?>
                                <span class="px-2 py-0.5 rounded text-xs font-bold bg-amber-500/20 text-amber-400">LOW STOCK</span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 rounded text-xs font-bold bg-emerald-500/15 text-emerald-400">OK</span>
                            <?php endif; ?>
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
            <span>Date From : <strong><?= htmlspecialchars($date_from) ?></strong></span>
            <span>Date To : <strong><?= htmlspecialchars($date_to) ?></strong></span>
            <span>Doc.Count : <strong><?= count($filtered_rows) ?></strong></span>
        </div>
        <div class="er-summary-stats">
            <div class="er-summary-stat-item">
                <span class="stat-label">Total Consumed</span>
                <span class="stat-val text-amber-400"><?= fmtR($grand_consumed) ?></span>
            </div>
            <div class="er-summary-stat-item">
                <span class="stat-label">Total Restocked</span>
                <span class="stat-val text-emerald-400"><?= fmtR($grand_added) ?></span>
            </div>
            <div class="er-summary-stat-item">
                <span class="stat-label">Low Stock Alerts</span>
                <span class="stat-val text-red-400"><?= $low_count + $out_count ?></span>
            </div>
        </div>
    </div>
</main>
</div>
</body>
</html>
