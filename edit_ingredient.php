<?php
require 'auth.php';
require 'config.php';
if (!can('ingredients')) { header("Location: ingredients.php"); exit; }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header("Location: ingredients.php"); exit; }

$stmt = $conn->prepare("SELECT * FROM ingredients WHERE ingredient_id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$ing = $stmt->get_result()->fetch_assoc();
if (!$ing) { header("Location: ingredients.php"); exit; }

// Load suppliers for dropdown
$supplierList = [];
$sr = $conn->query("SELECT supplier_id, name FROM suppliers ORDER BY name ASC");
if ($sr) while ($row = $sr->fetch_assoc()) $supplierList[] = $row;

function fmt($n) {
    return rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');
}

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['ingredient_name'] ?? '');
    $cost        = (float)($_POST['cost_price']    ?? 0);
    $buyqty      = (float)($_POST['purchase_qty']  ?? 0);
    $min         = (float)($_POST['minimum_stock'] ?? 0);
    $notes       = trim($_POST['notes'] ?? '');
    $supplier_id = (int)($_POST['supplier_id'] ?? 0);

    if (empty($name)) $name = $ing['ingredient_name'];
    if ($min < 0)     $min  = 0;

    $old_stock = (float)$ing['stock_quantity'];
    $old_cpu   = (float)$ing['cost_per_unit'];
    $new_stock = $old_stock + $buyqty;
    $final_cpu = $old_cpu;

    if ($buyqty > 0 && $cost > 0) {
        $new_cpu   = $cost / $buyqty;
        $old_value = $old_stock * $old_cpu;
        $new_value = $buyqty   * $new_cpu;
        $final_cpu = ($new_stock > 0) ? (($old_value + $new_value) / $new_stock) : 0;
    }

    $final_cost = ($cost   > 0) ? $cost   : (float)$ing['cost_price'];
    $final_qty  = ($buyqty > 0) ? $buyqty : (float)$ing['purchase_qty'];

    $upd = $conn->prepare(
        "UPDATE ingredients SET ingredient_name=?, cost_price=?, purchase_qty=?,
         stock_quantity=?, minimum_stock=?, cost_per_unit=?, supplier_id=? WHERE ingredient_id=?"
    );
    $sid = $supplier_id > 0 ? $supplier_id : null;
    $upd->bind_param('sdddddii', $name, $final_cost, $final_qty, $new_stock, $min, $final_cpu, $sid, $id);
    $upd->execute();

    if ($buyqty > 0) {
        try {
            $ref = $conn->prepare("INSERT INTO stock_refills (ingredient_id, purchase_qty, notes) VALUES (?, ?, ?)");
            if ($ref) { $ref->bind_param('ids', $id, $buyqty, $notes); $ref->execute(); }
        } catch (mysqli_sql_exception $e) { /* table may not exist yet */ }
    }

    // Re-fetch
    $stmt2 = $conn->prepare("SELECT * FROM ingredients WHERE ingredient_id = ?");
    $stmt2->bind_param('i', $id);
    $stmt2->execute();
    $ing     = $stmt2->get_result()->fetch_assoc();
    $success = 'Changes saved successfully.';
}

$stock = (float)$ing['stock_quantity'];
$minS  = (float)$ing['minimum_stock'];
$cpu   = (float)$ing['cost_per_unit'];
if ($cpu <= 0 && (float)$ing['purchase_qty'] > 0)
    $cpu = (float)$ing['cost_price'] / (float)$ing['purchase_qty'];

if ($stock <= 0)               { $status = 'out'; $statusLabel = 'Out of Stock'; $statusColor = '#ff4d4d'; }
elseif ($minS > 0 && $stock <= $minS) { $status = 'low'; $statusLabel = 'Low Stock';    $statusColor = '#f5a623'; }
else                            { $status = 'ok';  $statusLabel = 'In Stock';     $statusColor = '#3ecf70'; }

$stockPct = ($minS > 0) ? min(100, round(($stock / ($minS * 2)) * 100)) : 100;

$refills = [];
try {
    $rq = $conn->prepare(
        "SELECT purchase_qty, notes, created_at FROM stock_refills
         WHERE ingredient_id = ? ORDER BY created_at DESC LIMIT 5"
    );
    if ($rq) {
        $rq->bind_param('i', $id);
        $rq->execute();
        $refills = $rq->get_result()->fetch_all(MYSQLI_ASSOC);
    }
} catch (mysqli_sql_exception $e) { /* table may not exist */ }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit — <?= htmlspecialchars($ing['ingredient_name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.tailwindcss.com"></script>
<style>
:root {
    --bg:      #0c0c0c;
    --surface: #111111;
    --card:    #161616;
    --border:  #222;
    --accent:  #d1904b;
    --accent2: #b57b3b;
    --text:    #f0f0f0;
    --muted:   #888;
    --success: #3ecf70;
    --warning: #f5a623;
    --danger:  #ff4d4d;
    --info:    #5dade2;
    --radius:  14px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
    font-family: Poppins, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    font-size: 14px;
    line-height: 1.5;
}

/* ── TOP NAV ── */
.topnav {
    position: sticky; top: 0; z-index: 100;
    background: rgba(10,10,10,.92);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--border);
    padding: 14px 24px;
    display: flex; align-items: center; gap: 12px;
}
.topnav a.back-btn {
    display: flex; align-items: center; gap: 7px;
    color: #d1904b; text-decoration: none;
    font-weight: 600; font-size: 13px;
    padding: 7px 14px;
    border: 1px solid rgba(209,144,75,.35);
    border-radius: 10px;
    background: rgba(209,144,75,.08);
    transition: background .2s, border-color .2s;
}
.topnav a.back-btn:hover { background: rgba(209,144,75,.16); border-color: #d1904b; }
.breadcrumb {
    font-size: 12px; color: var(--muted);
    display: flex; align-items: center; gap: 6px;
}
.breadcrumb span { color: var(--text); font-weight: 500; }

/* ── LAYOUT ── */
.page-wrap {
    max-width: 900px;
    margin: 0 auto;
    padding: 32px 20px 60px;
    display: grid;
    grid-template-columns: 1fr 380px;
    gap: 24px;
    align-items: start;
}
@media (max-width: 760px) {
    .page-wrap { grid-template-columns: 1fr; }
}

/* ── HEADER CARD ── */
.ingredient-header {
    grid-column: 1 / -1;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 22px 24px;
    display: flex; align-items: center; gap: 18px;
    position: relative; overflow: hidden;
}
.ingredient-header::before {
    content: '';
    position: absolute; left: 0; top: 0; bottom: 0;
    width: 4px;
    background: v-status-color;
}
.ing-icon {
    width: 52px; height: 52px;
    border-radius: 14px;
    background: linear-gradient(135deg, #1e1e1e, #282828);
    border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; flex-shrink: 0;
}
.ing-meta { flex: 1; }
.ing-meta h1 { font-size: 20px; font-weight: 700; margin-bottom: 3px; }
.ing-meta .unit-badge {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; color: var(--muted);
    background: #1e1e1e; border: 1px solid #2a2a2a;
    padding: 3px 9px; border-radius: 20px;
}
.status-chip {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; font-weight: 600;
    padding: 5px 12px; border-radius: 20px;
    letter-spacing: .3px;
}
.status-chip.ok  { background: rgba(62,207,112,.12); color: var(--success); border: 1px solid rgba(62,207,112,.25); }
.status-chip.low { background: rgba(245,166,35,.12);  color: var(--warning); border: 1px solid rgba(245,166,35,.25); }
.status-chip.out { background: rgba(255,77,77,.12);   color: var(--danger);  border: 1px solid rgba(255,77,77,.25); }

/* ── STAT GRID ── */
.stat-grid {
    grid-column: 1 / -1;
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}
@media (max-width: 600px) { .stat-grid { grid-template-columns: repeat(2, 1fr); } }
.stat-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 18px;
}
.stat-label {
    font-size: 11px; color: var(--muted);
    text-transform: uppercase; letter-spacing: .6px;
    margin-bottom: 6px;
}
.stat-value {
    font-size: 22px; font-weight: 700; color: var(--text);
    line-height: 1.1;
}
.stat-value small { font-size: 13px; font-weight: 400; color: var(--muted); }
.stat-sub { font-size: 11px; color: var(--muted); margin-top: 4px; }

/* stock progress */
.stock-progress {
    margin-top: 8px;
    height: 5px; border-radius: 99px;
    background: #222;
    overflow: hidden;
}
.stock-progress-fill {
    height: 100%; border-radius: 99px;
    transition: width .6s ease;
}

/* ── SECTION CARD ── */
.section-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
}
.section-head {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 10px;
}
.section-head i { color: var(--accent); font-size: 15px; }
.section-head h3 { font-size: 14px; font-weight: 600; }
.section-head .section-desc { font-size: 11px; color: var(--muted); margin-left: auto; }
.section-body { padding: 20px; }

/* ── FORM ELEMENTS ── */
.field { margin-bottom: 16px; }
.field:last-child { margin-bottom: 0; }
label.field-label {
    display: block;
    font-size: 12px; font-weight: 500; color: #c0a070;
    margin-bottom: 7px; letter-spacing: .2px;
}
.input-wrap { position: relative; }
.input-wrap .unit-tag {
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    font-size: 11px; color: var(--muted);
    background: #1e1e1e; border: 1px solid #2a2a2a;
    padding: 2px 8px; border-radius: 6px;
    pointer-events: none;
}
input[type=text], input[type=number] {
    width: 100%;
    padding: 11px 14px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: #0f0f0f;
    color: var(--text);
    font-family: Poppins, sans-serif;
    font-size: 14px;
    transition: border-color .2s, box-shadow .2s;
}
input[type=text]:focus, input[type=number]:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(209,144,75,.12);
}
input[disabled] {
    background: #0a0a0a; color: var(--muted); cursor: not-allowed;
}
input.has-unit { padding-right: 72px; }
.field-hint { font-size: 11px; color: var(--muted); margin-top: 6px; }

textarea {
    width: 100%; padding: 11px 14px;
    border-radius: 10px; border: 1px solid var(--border);
    background: #0f0f0f; color: var(--text);
    font-family: Poppins, sans-serif; font-size: 13px;
    resize: vertical; min-height: 72px;
    transition: border-color .2s;
}
textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(209,144,75,.12); }

/* ── LIVE PREVIEW ── */
.live-preview {
    background: #0f0f0f;
    border: 1px solid #2a2a2a;
    border-radius: 12px;
    padding: 14px 16px;
    margin-top: 14px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.preview-item .plabel { font-size: 10px; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); margin-bottom: 3px; }
.preview-item .pvalue {
    font-size: 17px; font-weight: 700;
    transition: color .2s;
}
.pvalue.up   { color: var(--success); }
.pvalue.same { color: var(--muted); }
.pvalue.warn { color: var(--warning); }

/* ── MIN STOCK VISUAL ── */
.min-hint {
    display: flex; align-items: center; gap: 8px;
    margin-top: 8px; padding: 10px 12px;
    background: #0f0f0f; border: 1px solid #2a2a2a;
    border-radius: 10px; font-size: 12px; color: var(--muted);
}
.min-hint i { color: var(--warning); }

/* ── SUBMIT BTN ── */
.btn-save {
    width: 100%; padding: 14px;
    border: none; border-radius: var(--radius);
    background: linear-gradient(135deg, var(--accent2), var(--accent));
    color: #000; font-size: 15px; font-weight: 700;
    cursor: pointer; display: flex; align-items: center;
    justify-content: center; gap: 8px;
    transition: opacity .2s, transform .15s;
    margin-top: 6px;
}
.btn-save:hover { opacity: .9; transform: translateY(-1px); }
.btn-save:active { transform: translateY(0); }

/* ── REFILL HISTORY ── */
.history-table { width: 100%; border-collapse: collapse; }
.history-table th {
    text-align: left; font-size: 11px; text-transform: uppercase;
    letter-spacing: .5px; color: var(--muted);
    padding: 8px 12px; border-bottom: 1px solid var(--border);
}
.history-table td {
    padding: 10px 12px; font-size: 13px;
    border-bottom: 1px solid #1a1a1a;
}
.history-table tr:last-child td { border-bottom: none; }
.history-table .qty-cell { font-weight: 600; color: var(--accent); }
.history-table .date-cell { color: var(--muted); font-size: 11px; }
.empty-history { text-align: center; padding: 24px; color: var(--muted); font-size: 13px; }

/* ── TOAST ── */
.toast {
    position: fixed; bottom: 24px; right: 24px;
    background: #1e2e20; border: 1px solid rgba(62,207,112,.35);
    color: var(--success); padding: 13px 18px;
    border-radius: 12px; font-weight: 600; font-size: 13px;
    display: flex; align-items: center; gap: 8px;
    transform: translateY(80px); opacity: 0;
    transition: transform .35s ease, opacity .35s ease;
    z-index: 500; pointer-events: none;
}
.toast.show { transform: translateY(0); opacity: 1; }

/* ── ANIMATIONS ── */
@keyframes fadeDown  { from{opacity:0;transform:translateY(-16px)} to{opacity:1;transform:translateY(0)} }
@keyframes fadeUp    { from{opacity:0;transform:translateY(20px)}  to{opacity:1;transform:translateY(0)} }
@keyframes slideLeft { from{opacity:0;transform:translateX(-24px)} to{opacity:1;transform:translateX(0)} }
@keyframes slideRight{ from{opacity:0;transform:translateX(24px)}  to{opacity:1;transform:translateX(0)} }
@keyframes shimmer   { 0%{background-position:-200% center} 100%{background-position:200% center} }
@keyframes floatA    { 0%,100%{transform:translate(0,0)} 50%{transform:translate(-24px,18px)} }
@keyframes floatB    { 0%,100%{transform:translate(0,0)} 50%{transform:translate(18px,-24px)} }

.topnav              { animation: fadeDown  .45s ease both; }
.ingredient-header   { animation: fadeUp    .45s .08s ease both; }

.stat-grid .stat-card:nth-child(1) { animation: fadeUp .4s .14s ease both; }
.stat-grid .stat-card:nth-child(2) { animation: fadeUp .4s .21s ease both; }
.stat-grid .stat-card:nth-child(3) { animation: fadeUp .4s .28s ease both; }
.stat-grid .stat-card:nth-child(4) { animation: fadeUp .4s .35s ease both; }

.left-col  { animation: slideLeft  .5s .2s ease both; }
.right-col { animation: slideRight .5s .25s ease both; }

.section-head { position: relative; }
.section-head::after {
    content: '';
    position: absolute; left: 0; right: 0; bottom: 0; height: 1px;
    background: linear-gradient(90deg, transparent, rgba(209,144,75,.3), transparent);
    background-size: 200% auto;
    animation: shimmer 3s linear infinite;
}

.orb {
    position: fixed; border-radius: 50%; filter: blur(90px);
    pointer-events: none; z-index: 0;
}
.orb-a {
    width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(209,144,75,.15) 0%, transparent 70%);
    top: -120px; right: -120px;
    animation: floatA 9s ease-in-out infinite;
}
.orb-b {
    width: 300px; height: 300px;
    background: radial-gradient(circle, rgba(93,173,226,.1) 0%, transparent 70%);
    bottom: -80px; left: -80px;
    animation: floatB 11s ease-in-out infinite;
}
.page-wrap { position: relative; z-index: 1; }
</style>
</head>
<body>
<div class="flex h-screen w-screen overflow-hidden app-layout">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<main class="app-main flex-1 h-full overflow-y-auto p-4 md:p-6 relative">
<div class="orb orb-a"></div>
<div class="orb orb-b"></div>

<!-- MODAL BACKDROP -->
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 md:p-6 bg-black/75 backdrop-blur-md overflow-y-auto">
    <!-- MODAL DIALOG CONTAINER -->
    <div class="relative w-full max-w-5xl max-h-[92vh] bg-[#121215] border border-[#24242b] rounded-2xl shadow-2xl flex flex-col overflow-hidden text-white my-auto animate-scaleUp">
        
        <!-- MODAL HEADER -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-[#24242b] bg-[#18181c]/90 backdrop-blur-md shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-[#d1904b]/10 border border-[#d1904b]/30 text-[#d1904b] flex items-center justify-center font-bold">
                    <i class="fa-solid fa-pen-to-square"></i>
                </div>
                <div>
                    <h2 class="text-base font-bold text-white leading-tight">Edit Ingredient</h2>
                    <p class="text-xs text-[#888]"><?= htmlspecialchars($ing['ingredient_name']) ?> (ID #<?= $id ?>)</p>
                </div>
            </div>
            
            <a href="ingredients.php" class="w-9 h-9 rounded-xl bg-[#22222a] text-[#888] hover:text-white hover:bg-red-500/20 hover:text-red-400 flex items-center justify-center transition-all" title="Close Modal (Esc)">
                <i class="fa-solid fa-xmark text-lg"></i>
            </a>
        </div>

        <!-- MODAL BODY -->
        <div class="flex-1 overflow-y-auto p-6">

    <!-- HEADER -->
    <div class="ingredient-header" style="border-left: 4px solid <?= $statusColor ?>">
        <div class="ing-icon">☕</div>
        <div class="ing-meta">
            <h1><?= htmlspecialchars($ing['ingredient_name']) ?></h1>
            <div style="display:flex;align-items:center;gap:8px;margin-top:5px;">
                <span class="unit-badge">
                    <i class="fa-solid fa-ruler-combined" style="font-size:9px"></i>
                    <?= htmlspecialchars($ing['unit']) ?>
                </span>
                <span class="unit-badge">
                    <i class="fa-solid fa-tag" style="font-size:9px"></i>
                    ID #<?= $id ?>
                </span>
            </div>
        </div>
        <span class="status-chip <?= $status ?>">
            <i class="fa-solid fa-circle" style="font-size:7px"></i>
            <?= $statusLabel ?>
        </span>
    </div>

    <!-- STAT GRID -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-label">Current Stock</div>
            <div class="stat-value"><?= fmt($stock) ?> <small><?= htmlspecialchars($ing['unit']) ?></small></div>
            <div class="stock-progress">
                <div class="stock-progress-fill" style="width:<?= $stockPct ?>%;background:<?= $statusColor ?>"></div>
            </div>
            <div class="stat-sub">Min: <?= fmt($minS) ?> <?= htmlspecialchars($ing['unit']) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Cost / Unit</div>
            <div class="stat-value">$<?= number_format($cpu, 4) ?></div>
            <div class="stat-sub">Weighted average</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Last Purchase</div>
            <div class="stat-value"><?= fmt($ing['purchase_qty']) ?> <small><?= htmlspecialchars($ing['unit']) ?></small></div>
            <div class="stat-sub">$<?= number_format($ing['cost_price'], 2) ?> total cost</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Value</div>
            <div class="stat-value">$<?= number_format($stock * $cpu, 2) ?></div>
            <div class="stat-sub">stock × cost/unit</div>
        </div>
    </div>

    <!-- LEFT COLUMN: form -->
    <div class="left-col" style="display:flex;flex-direction:column;gap:18px;">

        <!-- RESTOCK SECTION -->
        <div class="section-card">
            <div class="section-head">
                <i class="fa-solid fa-box-open"></i>
                <h3>Add Stock</h3>
                <span class="section-desc">Restock entry</span>
            </div>
            <div class="section-body">
                <form method="post" id="editForm">
                    <div class="field">
                        <label class="field-label">Quantity to Add</label>
                        <div class="input-wrap">
                            <input type="number" step="0.01" min="0" name="purchase_qty"
                                id="f_qty" value="0" class="has-unit" autocomplete="off">
                            <span class="unit-tag"><?= htmlspecialchars($ing['unit']) ?></span>
                        </div>
                        <div class="field-hint">Enter 0 if you're only updating settings below.</div>
                    </div>
                    <div class="field">
                        <label class="field-label">Total Purchase Cost ($)</label>
                        <div class="input-wrap">
                            <input type="number" step="0.01" min="0" name="cost_price"
                                id="f_cost" placeholder="e.g. 12.50" class="has-unit">
                            <span class="unit-tag">USD</span>
                        </div>
                        <div class="field-hint">Leave blank if not restocking.</div>
                    </div>
                    <div class="field">
                        <label class="field-label">Refill Notes <span style="color:var(--muted);font-weight:400">(optional)</span></label>
                        <textarea name="notes" placeholder="e.g. Purchased from Metro Market..."></textarea>
                    </div>

                    <!-- LIVE PREVIEW -->
                    <div class="live-preview" id="livePreview">
                        <div class="preview-item">
                            <div class="plabel">New Total Stock</div>
                            <div class="pvalue same" id="p_stock"><?= fmt($stock) ?> <span style="font-size:12px;font-weight:400;color:var(--muted)"><?= htmlspecialchars($ing['unit']) ?></span></div>
                        </div>
                        <div class="preview-item">
                            <div class="plabel">New Cost / Unit</div>
                            <div class="pvalue same" id="p_cpu">$<?= number_format($cpu, 4) ?></div>
                        </div>
                    </div>

                    <!-- SETTINGS SECTION (inline) -->
                    <div style="margin-top:22px;padding-top:18px;border-top:1px solid var(--border)">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
                            <i class="fa-solid fa-sliders" style="color:var(--accent);font-size:13px"></i>
                            <span style="font-size:13px;font-weight:600">Settings</span>
                        </div>

                        <div class="field">
                            <label class="field-label">Ingredient Name</label>
                            <input type="text" name="ingredient_name"
                                value="<?= htmlspecialchars($ing['ingredient_name']) ?>" required>
                        </div>

                        <div class="field">
                            <label class="field-label">Minimum Stock Alert Level</label>
                            <div class="input-wrap">
                                <input type="number" step="0.01" min="0" name="minimum_stock"
                                    id="f_min" value="<?= htmlspecialchars(fmt($minS)) ?>" class="has-unit" required>
                                <span class="unit-tag"><?= htmlspecialchars($ing['unit']) ?></span>
                            </div>
                            <div class="min-hint" id="minHint">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                <span id="minHintText">Alert triggers when stock falls to or below this level.</span>
                            </div>
                        </div>

                        <?php if (!empty($supplierList)): ?>
                        <div class="field">
                            <label class="field-label"><i class="fa-solid fa-truck-ramp-box" style="color:var(--accent)"></i> Preferred Supplier</label>
                            <select name="supplier_id" style="width:100%;padding:11px 14px;border-radius:10px;border:1px solid var(--border);background:#0f0f0f;color:var(--text);font-family:Poppins,sans-serif;font-size:14px;outline:none;appearance:none;">
                                <option value="0">— None —</option>
                                <?php foreach ($supplierList as $sup): $sel = ((int)($ing['supplier_id'] ?? 0) === (int)$sup['supplier_id']) ? 'selected' : ''; ?>
                                <option value="<?= $sup['supplier_id'] ?>" <?= $sel ?>><?= htmlspecialchars($sup['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn-save" id="saveBtn">
                        <i class="fa-solid fa-floppy-disk"></i>
                        Save Changes
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- RIGHT COLUMN: history -->
    <div class="right-col" style="display:flex;flex-direction:column;gap:18px;">

        <!-- REFILL HISTORY -->
        <div class="section-card">
            <div class="section-head">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <h3>Refill History</h3>
                <span class="section-desc">Last 5 entries</span>
            </div>
            <?php if (empty($refills)): ?>
            <div class="empty-history">
                <i class="fa-solid fa-inbox" style="font-size:24px;opacity:.3;display:block;margin-bottom:8px"></i>
                No refill history yet
            </div>
            <?php else: ?>
            <table class="history-table">
                <thead>
                    <tr>
                        <th>Qty Added</th>
                        <th>Notes</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($refills as $r): ?>
                    <tr>
                        <td class="qty-cell">+<?= fmt($r['purchase_qty']) ?> <?= htmlspecialchars($ing['unit']) ?></td>
                        <td style="color:var(--muted);font-size:12px"><?= $r['notes'] ? htmlspecialchars($r['notes']) : '—' ?></td>
                        <td class="date-cell"><?= $r['created_at'] ? date('M j, Y', strtotime($r['created_at'])) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- QUICK INFO -->
        <div class="section-card">
            <div class="section-head">
                <i class="fa-solid fa-circle-info"></i>
                <h3>How Pricing Works</h3>
            </div>
            <div class="section-body" style="color:var(--muted);font-size:12px;line-height:1.7">
                <p style="margin-bottom:8px">
                    <strong style="color:var(--text)">Cost / Unit</strong> uses a weighted average of old and new stock:
                </p>
                <code style="display:block;background:#0a0a0a;border:1px solid #222;padding:10px;border-radius:8px;font-size:11px;line-height:1.8;color:#aaa">
                    new_cpu = (<br>
                    &nbsp;&nbsp;(old_stock × old_cpu)<br>
                    &nbsp;&nbsp;+ (qty_added × new_cpu)<br>
                    ) ÷ total_stock
                </code>
                <p style="margin-top:10px">This keeps the cost accurate as you restock over time.</p>
            </div>
        </div>
    </div>

        </div><!-- /modal-body -->
    </div><!-- /modal-dialog -->
</div><!-- /modal-backdrop -->
</main>
</div><!-- /app-layout -->

<!-- TOAST -->
<div class="toast" id="toast">
    <i class="fa-solid fa-circle-check"></i>
    <?= $success ?: 'Saved' ?>
</div>

<script>
const CUR_STOCK = <?= (float)$ing['stock_quantity'] ?>;
const CUR_CPU   = <?= round($cpu, 6) ?>;
const UNIT      = <?= json_encode($ing['unit']) ?>;

const fQty  = document.getElementById('f_qty');
const fCost = document.getElementById('f_cost');
const fMin  = document.getElementById('f_min');
const pStock = document.getElementById('p_stock');
const pCpu   = document.getElementById('p_cpu');
const minHint = document.getElementById('minHintText');

function fmt(n, dec) {
    return parseFloat(n.toFixed(dec ?? 2)).toString();
}

function updatePreview() {
    const qty  = parseFloat(fQty.value)  || 0;
    const cost = parseFloat(fCost.value) || 0;

    const newTotal = CUR_STOCK + qty;

    let newCpu = CUR_CPU;
    if (qty > 0 && cost > 0) {
        const newCpuUnit = cost / qty;
        const oldVal     = CUR_STOCK * CUR_CPU;
        const addVal     = qty * newCpuUnit;
        newCpu = newTotal > 0 ? (oldVal + addVal) / newTotal : 0;
    }

    pStock.textContent = fmt(newTotal) + ' ' + UNIT;
    pStock.className   = 'pvalue ' + (qty > 0 ? 'up' : 'same');

    pCpu.textContent = '$' + newCpu.toFixed(4);
    pCpu.className   = 'pvalue ' + (Math.abs(newCpu - CUR_CPU) > 0.0001 ? (newCpu < CUR_CPU ? 'up' : 'warn') : 'same');
}

function updateMinHint() {
    const minVal   = parseFloat(fMin.value) || 0;
    const newStock = CUR_STOCK + (parseFloat(fQty.value) || 0);
    if (newStock <= 0)       minHint.textContent = 'Stock is empty — will show Out of Stock.';
    else if (newStock <= minVal) minHint.textContent = `After this update, stock (${fmt(newStock)} ${UNIT}) will still be at/below minimum — still shows Low Stock.`;
    else                     minHint.textContent = `After this update, stock will be above the minimum — shows In Stock.`;
}

fQty.addEventListener('input', () => { updatePreview(); updateMinHint(); });
fCost.addEventListener('input', updatePreview);
fMin.addEventListener('input', updateMinHint);
updateMinHint();

// Ctrl+Enter shortcut to save, Escape key to close modal
document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') document.getElementById('editForm').submit();
    if (e.key === 'Escape') window.location.href = 'ingredients.php';
});

// Show success toast
<?php if ($success): ?>
setTimeout(() => {
    const t = document.getElementById('toast');
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3500);
}, 100);
<?php endif; ?>
</script>
</body>
</html>
