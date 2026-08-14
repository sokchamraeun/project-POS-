<?php
require 'auth.php';
require 'config.php';
header("Location: dashboard.php?denied=1"); exit;

/* ── AJAX: GET INGREDIENT USAGE DETAILS (PRODUCTS & DEDUCTION HISTORY) ── */
if (isset($_GET['action']) && $_GET['action'] === 'get_usage_detail') {
    header('Content-Type: application/json');
    $ingId     = (int)($_GET['ingredient_id'] ?? 0);
    $date_from = trim($_GET['from_date'] ?? $_GET['from'] ?? '');
    $date_to   = trim($_GET['to_date']   ?? $_GET['to']   ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-d', strtotime('-30 days'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');
    if ($date_from > $date_to) [$date_from, $date_to] = [$date_to, $date_from];

    if ($ingId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid ID']); exit; }

    // 1. Products using this ingredient with QTY Consumed calculated from order sales
    $products = [];
    $pStmt = $conn->prepare("
        SELECT 
            p.product_id, 
            p.name AS product_name, 
            p.category_id, 
            c.name AS category_name, 
            pi.amount_used AS recipe_qty,
            COALESCE(SUM(oi.quantity), 0) AS items_sold,
            COALESCE(SUM(oi.quantity * pi.amount_used), 0) AS total_consumed
        FROM product_ingredients pi
        JOIN products p ON p.product_id = pi.product_id
        LEFT JOIN categories c ON c.category_id = p.category_id
        LEFT JOIN order_items oi ON oi.product_id = p.product_id
        LEFT JOIN orders o ON o.order_id = oi.order_id AND (o.status IS NULL OR o.status != 'Cancelled') AND DATE(o.created_at) BETWEEN ? AND ?
        WHERE pi.ingredient_id = ?
        GROUP BY p.product_id, p.name, p.category_id, c.name, pi.amount_used
        ORDER BY total_consumed DESC, p.name ASC
    ");
    if ($pStmt) {
        $pStmt->bind_param("ssi", $date_from, $date_to, $ingId);
        $pStmt->execute();
        $products = $pStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // 2. Deduction History events matching Qty Consumed
    $deductions = [];
    $total_deducted_sum = 0;
    $dStmt = $conn->prepare("
        SELECT id, change_type, amount, reference, created_by, created_at
        FROM ingredient_history
        WHERE ingredient_id = ?
          AND (change_type = 'order_deduct' OR (change_type = 'manual_adjust' AND amount < 0) OR (change_type = 'count_adjust' AND amount < 0))
          AND DATE(created_at) BETWEEN ? AND ?
        ORDER BY created_at DESC
    ");
    if ($dStmt) {
        $dStmt->bind_param("iss", $ingId, $date_from, $date_to);
        $dStmt->execute();
        $deductions = $dStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($deductions as $d) {
            $total_deducted_sum += abs((float)$d['amount']);
        }
    }

    echo json_encode([
        'success'        => true,
        'date_from'      => $date_from,
        'date_to'        => $date_to,
        'products'       => $products,
        'deductions'     => $deductions,
        'total_deducted' => $total_deducted_sum
    ]);
    exit;
}

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
        i.cost_per_unit,
        i.stock_quantity,
        i.minimum_stock,
        COALESCE(SUM(CASE
            WHEN h.change_type = 'order_deduct' THEN h.amount
            WHEN h.change_type = 'manual_adjust' AND h.amount < 0 THEN ABS(h.amount)
            WHEN h.change_type = 'count_adjust' AND h.amount < 0 THEN ABS(h.amount)
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
    GROUP BY i.ingredient_id, i.ingredient_name, i.unit, i.cost_per_unit, i.stock_quantity, i.minimum_stock
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
$stk_val        = 0;

foreach ($rows as &$r) {
    $r['consumed']    = (float)$r['total_consumed'];
    $r['added']       = (float)$r['total_added'];
    $r['net']         = $r['added'] - $r['consumed'];
    $r['daily_avg']   = $days_in_range > 0 ? $r['consumed'] / $days_in_range : 0;
    $stock            = (float)$r['stock_quantity'];
    $r['days_left']   = ($r['daily_avg'] > 0 && $stock > 0) ? (int)round($stock / $r['daily_avg']) : null;
    $cpu              = (float)($r['cost_per_unit'] ?? 0);
    $stk_val         += ($stock * $cpu);

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
<div class="flex h-screen w-screen overflow-hidden app-layout">
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
    $export_pdf_url   = "ingredients_pdf.php?from_date=" . urlencode($date_from) . "&to_date=" . urlencode($date_to) . "&stock_status=" . urlencode($filter_stock);
    require __DIR__ . '/report_header.php';

    // Apply stock filter
    $filtered_rows = [];
    $low_count    = 0;
    $out_count    = 0;
    $normal_count = 0;
    foreach ($rows as $r) {
        $stk = (float)$r['stock_quantity'];
        $min = (float)$r['minimum_stock'];
        if ($stk <= 0)        $out_count++;
        elseif ($stk <= $min) $low_count++;
        else                  $normal_count++;

        if ($filter_stock === 'low' && ($stk <= 0 || $stk > $min)) continue;
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
                        <th style="width:50px;text-align:center;">No</th>
                        <th>Ingredient Name</th>
                        <th>Unit</th>
                        <th>Unit Price (Cost/kg, L)</th>
                        <th>Qty Consumed</th>
                        <th>Current Stock</th>
                        <th>Min Stock Alert</th>
                        <th>Status</th>
                        <th style="text-align:center;width:90px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($filtered_rows)): ?>
                    <tr class="no-data">
                        <td colspan="9" class="no-data">No data</td>
                    </tr>
                    <?php else: ?>
                    <?php $_row_idx = 1; foreach ($filtered_rows as $r): ?>
                    <?php
                        $stk = (float)$r['stock_quantity'];
                        $min = (float)$r['minimum_stock'];
                        $is_out = ($stk <= 0);
                        $is_low = ($stk > 0 && $stk <= $min);
                        $cpu    = (float)($r['cost_per_unit'] ?? 0);
                        $uLower = strtolower(trim($r['unit'] ?? ''));
                    ?>
                    <tr>
                        <td style="text-align:center;" class="font-semibold text-slate-400"><?= $_row_idx++ ?></td>
                        <td class="font-bold text-white"><?= htmlspecialchars($r['ingredient_name']) ?></td>
                        <td><span class="px-2 py-0.5 rounded text-xs bg-slate-800 text-slate-300"><?= htmlspecialchars($r['unit']) ?></span></td>
                        <td class="font-semibold text-amber-300">
                            <?php if ($uLower === 'ml' && $cpu > 0): 
                                $cLiter = $cpu * 1000;
                            ?>
                                $<?= number_format($cpu, 4) ?>/ml <span class="text-[10px] text-slate-400 block font-normal">($<?= number_format($cLiter, 2) ?>/L)</span>
                            <?php elseif ($uLower === 'g' && $cpu > 0): 
                                $cKg = $cpu * 1000;
                            ?>
                                $<?= number_format($cpu, 4) ?>/g <span class="text-[10px] text-slate-400 block font-normal">($<?= number_format($cKg, 2) ?>/kg)</span>
                            <?php elseif ($cpu > 0): ?>
                                $<?= number_format($cpu, 2) ?>/<?= htmlspecialchars($r['unit']) ?>
                            <?php else: ?>
                                <span class="text-slate-500">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-amber-400 font-semibold"><?= fmtR($r['consumed']) ?></td>
                        <td class="font-bold text-white"><?= fmtR($stk) ?></td>
                        <td class="text-slate-400"><?= fmtR($min) ?></td>
                        <td>
                            <?php if ($is_out): ?>
                                <span class="px-2 py-0.5 rounded text-xs font-bold bg-red-500/20 text-red-400">OUT OF STOCK</span>
                            <?php elseif ($is_low): ?>
                                <span class="px-2 py-0.5 rounded text-xs font-bold bg-amber-500/20 text-amber-400">LOW STOCK</span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 rounded text-xs font-bold bg-emerald-500/15 text-emerald-400">OK</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <button type="button" class="px-2.5 py-1 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-400 hover:bg-amber-500/20 font-semibold text-xs transition-all inline-flex items-center gap-1.5" onclick="viewIngredientUsage(<?= (int)$r['ingredient_id'] ?>, '<?= htmlspecialchars($r['ingredient_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['unit'], ENT_QUOTES) ?>')">
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

    <!-- Summary Section (Full Width, Single Horizontal Row) -->
    <div style="margin-top: 24px; width: 100%; border-radius: 14px; border: 1px solid #252530; background: #131317; padding: 18px 24px;">
        <div style="display: flex; align-items: center; justify-content: space-between; gap: 24px; flex-wrap: wrap;">
            <!-- 1. Product Low Stock -->
            <div style="flex: 1; min-width: 200px; display: flex; align-items: center; gap: 12px; border-right: 1px solid #252530; padding-right: 20px;">
                <span style="color: #94a3b8; font-weight: 500; font-size: 13px;">Product Low Stock</span>
                <span style="color: #f59e0b; font-weight: 700; font-size: 15px; margin-left: auto;">
                    <?= $low_count + $out_count ?> <span style="font-style: italic; font-weight: normal; color: #64748b; font-size: 12px; margin-left: 3px;">(item)</span>
                </span>
            </div>
            <!-- 2. Product Normal Stock -->
            <div style="flex: 1; min-width: 200px; display: flex; align-items: center; gap: 12px; border-right: 1px solid #252530; padding-right: 20px;">
                <span style="color: #94a3b8; font-weight: 500; font-size: 13px;">Product Normal Stock</span>
                <span style="color: #10b981; font-weight: 700; font-size: 15px; margin-left: auto;">
                    <?= $normal_count ?> <span style="font-style: italic; font-weight: normal; color: #64748b; font-size: 12px; margin-left: 3px;">(item)</span>
                </span>
            </div>
            <!-- 3. Inventory Value -->
            <div style="flex: 1; min-width: 200px; display: flex; align-items: center; gap: 12px;">
                <span style="color: #94a3b8; font-weight: 500; font-size: 13px;">Inventory Value</span>
                <span style="color: #ffffff; font-weight: 700; font-size: 16px; margin-left: auto;">
                    $<?= number_format($stk_val, 2) ?>
                </span>
            </div>
        </div>
    </div>
</main>
</div>

<!-- ── INGREDIENT USAGE DETAIL MODAL ── -->
<div id="usageDetailModal" class="fixed inset-0 z-[9999] hidden flex items-center justify-center bg-black/75 backdrop-blur-sm p-4">
    <div class="bg-[#15151a] border border-[#282836] rounded-2xl w-full max-w-2xl overflow-hidden shadow-2xl flex flex-col max-h-[85vh]">
        <!-- Header -->
        <div class="px-6 py-4 border-b border-[#282836] flex items-center justify-between bg-[#1a1a22]">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-amber-500/15 border border-amber-500/30 text-amber-400 flex items-center justify-center font-bold text-base">
                    <i class="fa-solid fa-receipt"></i>
                </div>
                <div>
                    <h3 class="font-bold text-white text-base leading-tight" id="usageModalTitle">Ingredient Usage Detail</h3>
                    <p class="text-xs text-slate-400" id="usageModalSub">Products linked & time log</p>
                </div>
            </div>
            <button type="button" onclick="closeUsageModal()" class="w-8 h-8 rounded-lg bg-slate-800 text-slate-400 hover:text-white flex items-center justify-center text-sm transition-all">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Body Content -->
        <div class="p-6 overflow-y-auto space-y-6" id="usageModalContent">
            <div class="text-center py-8 text-slate-400">
                <i class="fa-solid fa-spinner fa-spin text-amber-400 text-2xl mb-2"></i>
                <p class="text-xs">Loading ingredient usage details...</p>
            </div>
        </div>

        <!-- Footer -->
        <div class="px-6 py-3.5 border-t border-[#282836] bg-[#1a1a22] flex justify-end">
            <button type="button" onclick="closeUsageModal()" class="px-5 py-2 rounded-xl bg-slate-800 text-slate-300 hover:bg-slate-700 font-semibold text-xs transition-all">
                Close
            </button>
        </div>
    </div>
</div>

<script>
function viewIngredientUsage(ingId, ingName, ingUnit) {
    const modal = document.getElementById('usageDetailModal');
    const title = document.getElementById('usageModalTitle');
    const sub   = document.getElementById('usageModalSub');
    const body  = document.getElementById('usageModalContent');

    title.textContent = ingName;
    sub.textContent   = `Products linked & deduction history log (${ingUnit})`;
    body.innerHTML    = `
        <div class="text-center py-10 text-slate-400">
            <i class="fa-solid fa-spinner fa-spin text-amber-400 text-2xl mb-2"></i>
            <p class="text-xs">Calculating product consumption details...</p>
        </div>`;
    modal.classList.remove('hidden');

    const urlParams = new URLSearchParams(window.location.search);
    const dateFrom  = document.querySelector('input[name="from_date"]')?.value || document.querySelector('input[name="from"]')?.value || urlParams.get('from_date') || urlParams.get('from') || '';
    const dateTo    = document.querySelector('input[name="to_date"]')?.value   || document.querySelector('input[name="to"]')?.value   || urlParams.get('to_date')   || urlParams.get('to')   || '';

    fetch(`ingredient_report.php?action=get_usage_detail&ingredient_id=${ingId}&from_date=${encodeURIComponent(dateFrom)}&to_date=${encodeURIComponent(dateTo)}`)
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            body.innerHTML = `<div class="p-4 rounded-xl bg-red-500/10 border border-red-500/30 text-red-400 text-sm">Failed to load product details.</div>`;
            return;
        }

        const prods = data.products || [];
        const deds  = data.deductions || [];

        let totalDeductedHistory = parseFloat(data.total_deducted || 0);

        let html = '';

        // 1. Products Linked & Sales Consumption Table
        html += `
            <div class="mb-6">
                <div class="flex items-center justify-between mb-3 pb-2 border-b border-[#282836]">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-amber-400 flex items-center gap-2">
                        <i class="fa-solid fa-mug-hot"></i> Products Using This Ingredient (${prods.length})
                    </h4>
                </div>`;

        if (prods.length === 0) {
            html += `<p class="text-xs text-slate-500 italic bg-[#1a1a22] p-4 rounded-xl border border-[#282836] text-center">No products are currently linked to this ingredient.</p>`;
        } else {
            html += `
                <div class="rounded-xl border border-[#282836] overflow-hidden">
                    <table class="w-full text-xs text-left">
                        <thead class="bg-[#2a2218] text-amber-400 font-bold uppercase text-[10px]">
                            <tr>
                                <th class="p-2.5 text-center" style="width:35px;">No.</th>
                                <th class="p-2.5">Product Name</th>
                                <th class="p-2.5">Category</th>
                                <th class="p-2.5 text-center">Recipe Amount</th>
                                <th class="p-2.5 text-center">Units Sold</th>
                                <th class="p-2.5 text-right">QTY Consumed</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#282836] bg-[#15151a]">`;

            prods.forEach((p, idx) => {
                const consumed = parseFloat(p.total_consumed || 0);
                html += `
                    <tr class="hover:bg-slate-800/40">
                        <td class="p-2.5 text-center text-slate-500 font-semibold">${idx + 1}</td>
                        <td class="p-2.5 font-bold text-white">${escapeHtml(p.product_name)}</td>
                        <td class="p-2.5 text-slate-400">${escapeHtml(p.category_name || 'General')}</td>
                        <td class="p-2.5 text-center font-mono text-slate-300">${p.recipe_qty} ${ingUnit} / unit</td>
                        <td class="p-2.5 text-center font-semibold text-slate-300">${p.items_sold}</td>
                        <td class="p-2.5 text-right font-bold text-amber-400 text-sm whitespace-nowrap">${fmtNum(consumed)} ${ingUnit}</td>
                    </tr>`;
            });

            html += `
                        </tbody>
                    </table>
                </div>`;
        }
        html += `</div>`;

        // 2. Deduction History Events Log (Order Deductions & Manual Deductions)
        html += `
            <div>
                <div class="flex items-center justify-between mb-3 pb-2 border-b border-[#282836]">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-cyan-400 flex items-center gap-2">
                        <i class="fa-solid fa-clock-rotate-left"></i> Deduction History Log (${deds.length} events)
                    </h4>
                    <span class="text-xs text-slate-400">Total Deducted: <strong class="text-amber-400 font-bold">${fmtNum(totalDeductedHistory)} ${ingUnit}</strong></span>
                </div>`;

        if (deds.length === 0) {
            html += `<p class="text-xs text-slate-500 italic bg-[#1a1a22] p-4 rounded-xl border border-[#282836] text-center">No deduction history records found for this date range.</p>`;
        } else {
            html += `
                <div class="rounded-xl border border-[#282836] overflow-hidden max-h-[220px] overflow-y-auto">
                    <table class="w-full text-xs text-left">
                        <thead class="bg-[#1e2830] text-[#92b4c0] font-bold uppercase text-[10px] sticky top-0 bg-[#1e2830]">
                            <tr>
                                <th class="p-2.5">Time</th>
                                <th class="p-2.5">Type</th>
                                <th class="p-2.5">Deducted Qty</th>
                                <th class="p-2.5">Reference / Notes</th>
                                <th class="p-2.5">User</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#282836] bg-[#15151a]">`;

            deds.forEach(d => {
                const amt = Math.abs(parseFloat(d.amount || 0));
                const typeLabel = d.change_type === 'order_deduct' ? 'Order Deduct' : (d.change_type === 'count_adjust' ? 'Count Adjust' : 'Manual Adjust');
                html += `
                    <tr class="hover:bg-slate-800/40">
                        <td class="p-2.5 text-slate-400 font-mono text-[11px] whitespace-nowrap">${escapeHtml(d.created_at)}</td>
                        <td class="p-2.5 font-medium text-slate-300"><span class="px-2 py-0.5 rounded text-[10px] font-bold bg-red-500/10 text-red-400 border border-red-500/20">${typeLabel}</span></td>
                        <td class="p-2.5 font-bold text-amber-400 whitespace-nowrap">-${fmtNum(amt)} ${ingUnit}</td>
                        <td class="p-2.5 text-slate-300 max-w-[200px] truncate" title="${escapeHtml(d.reference || '')}">${escapeHtml(d.reference || '—')}</td>
                        <td class="p-2.5 text-slate-400">${escapeHtml(d.created_by || 'System')}</td>
                    </tr>`;
            });

            html += `
                        </tbody>
                    </table>
                </div>`;
        }
        html += `</div>`;

        body.innerHTML = html;
    })
    .catch(err => {
        body.innerHTML = `<div class="p-4 rounded-xl bg-red-500/10 border border-red-500/30 text-red-400 text-sm">Failed to connect to server.</div>`;
    });
}

function closeUsageModal() {
    document.getElementById('usageDetailModal').classList.add('hidden');
}

function fmtNum(n) {
    return (Math.round(n * 100) / 100).toLocaleString();
}

function escapeHtml(str) {
    return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>
</body>
</html>
