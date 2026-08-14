<?php
require 'auth.php';
require 'config.php';
if (!can('ingredients')) { header("Location: dashboard.php?denied=1"); exit; }

/* ══════════════════════════════════════════
   FILTERS
══════════════════════════════════════════ */
$filter_ing  = (int)($_GET['ingredient_id'] ?? 0);
$filter_type = trim($_GET['type'] ?? '');
$filter_from = trim($_GET['from'] ?? '');
$filter_to   = trim($_GET['to']   ?? '');

$valid_types = ['order_deduct','order_restore','quick_restock','po_received','manual_adjust','count_adjust'];
if (!in_array($filter_type, $valid_types)) $filter_type = '';

$per_page = 10;
$page     = max(1, (int)($_GET['page'] ?? 1));

/* Display reference: show the customer-facing order number (daily_order_no),
   not the raw internal order_id. Falls back to the stored reference text. */
function _hist_ref(array $r): string {
    if (!empty($r['order_id']) && !empty($r['daily_order_no'])) {
        return 'Order #' . (int)$r['daily_order_no'];
    }
    return ($r['reference'] ?? '') !== '' ? $r['reference'] : '—';
}

/* ══════════════════════════════════════════
   AJAX POLL — returns new rows as JSON
══════════════════════════════════════════ */
if (($_GET['ajax'] ?? '') === '1') {
    header('Content-Type: application/json');
    $last_id = (int)($_GET['last_id'] ?? 0);

    $where  = ['h.id > ?'];
    $params = [$last_id];
    $types  = 'i';
    if ($filter_ing > 0)   { $where[] = 'h.ingredient_id = ?'; $params[] = $filter_ing;  $types .= 'i'; }
    if ($filter_type !== '') { $where[] = 'h.change_type = ?';  $params[] = $filter_type; $types .= 's'; }
    if ($filter_from !== '') { $where[] = 'DATE(h.created_at) >= ?'; $params[] = $filter_from; $types .= 's'; }
    if ($filter_to   !== '') { $where[] = 'DATE(h.created_at) <= ?'; $params[] = $filter_to;   $types .= 's'; }

    $stmt = $conn->prepare("
        SELECT h.id, h.ingredient_id, h.change_type, h.amount, h.order_id,
               h.reference, h.created_by, h.created_at, i.ingredient_name, i.unit,
               o.daily_order_no
        FROM ingredient_history h
        JOIN ingredients i ON i.ingredient_id = h.ingredient_id
        LEFT JOIN orders o ON o.order_id = h.order_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY h.created_at DESC
        LIMIT 50
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $new_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($new_rows as &$_nr) { $_nr['display_ref'] = _hist_ref($_nr); }
    unset($_nr);
    echo json_encode(['rows' => $new_rows]);
    exit;
}

/* ══════════════════════════════════════════
   DATA: ingredient list for dropdown
══════════════════════════════════════════ */
$ing_list = [];
$ir = $conn->query("SELECT ingredient_id, ingredient_name FROM ingredients ORDER BY ingredient_name ASC");
while ($row = $ir->fetch_assoc()) $ing_list[] = $row;

/* ══════════════════════════════════════════
   DATA: history rows
══════════════════════════════════════════ */
$where   = [];
$params  = [];
$types   = '';

if ($filter_ing > 0) {
    $where[]  = 'h.ingredient_id = ?';
    $params[] = $filter_ing;
    $types   .= 'i';
}
if ($filter_type !== '') {
    $where[]  = 'h.change_type = ?';
    $params[] = $filter_type;
    $types   .= 's';
}
if ($filter_from !== '') {
    $where[]  = 'DATE(h.created_at) >= ?';
    $params[] = $filter_from;
    $types   .= 's';
}
if ($filter_to !== '') {
    $where[]  = 'DATE(h.created_at) <= ?';
    $params[] = $filter_to;
    $types   .= 's';
}

/* ── aggregate stats across ALL matching rows (not paginated) ── */
$agg_sql = "
    SELECT
        SUM(CASE WHEN h.change_type='order_deduct' OR (h.change_type='manual_adjust' AND h.amount<0) OR (h.change_type='count_adjust' AND h.amount<0) THEN 1 ELSE 0 END) AS cnt_deduct,
        SUM(CASE WHEN h.change_type='order_deduct' OR (h.change_type='manual_adjust' AND h.amount<0) OR (h.change_type='count_adjust' AND h.amount<0) THEN ABS(h.amount) ELSE 0 END) AS total_deducted,
        SUM(CASE WHEN NOT(h.change_type='order_deduct' OR (h.change_type='manual_adjust' AND h.amount<0) OR (h.change_type='count_adjust' AND h.amount<0)) THEN 1 ELSE 0 END) AS cnt_add,
        SUM(CASE WHEN NOT(h.change_type='order_deduct' OR (h.change_type='manual_adjust' AND h.amount<0) OR (h.change_type='count_adjust' AND h.amount<0)) THEN ABS(h.amount) ELSE 0 END) AS total_added,
        COUNT(*) AS total_count
    FROM ingredient_history h
    JOIN ingredients i ON i.ingredient_id = h.ingredient_id
" . ($where ? 'WHERE ' . implode(' AND ', $where) : '');
$ss = $conn->prepare($agg_sql);
if ($params) $ss->bind_param($types, ...$params);
$ss->execute();
$agg = $ss->get_result()->fetch_assoc();

$cnt_deduct     = (int)($agg['cnt_deduct']     ?? 0);
$total_deducted = (float)($agg['total_deducted'] ?? 0);
$cnt_add        = (int)($agg['cnt_add']        ?? 0);
$total_added    = (float)($agg['total_added']   ?? 0);
$total_count    = (int)($agg['total_count']    ?? 0);
$total_pages    = max(1, (int)ceil($total_count / $per_page));
$page           = min($page, $total_pages);
$offset         = ($page - 1) * $per_page;

/* ── paginated rows ── */
$paged_sql = "
    SELECT h.id, h.ingredient_id, h.change_type, h.amount, h.order_id, h.reference, h.created_by, h.created_at,
           i.ingredient_name, i.unit, o.daily_order_no
    FROM ingredient_history h
    JOIN ingredients i ON i.ingredient_id = h.ingredient_id
    LEFT JOIN orders o ON o.order_id = h.order_id
" . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
    ORDER BY h.created_at DESC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($paged_sql);
$paged_params = array_merge($params, [$per_page, $offset]);
$stmt->bind_param($types . 'ii', ...$paged_params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* ── base URL for pagination links (preserves filters, strips page) ── */
$base_query = http_build_query(array_filter([
    'ingredient_id' => $filter_ing ?: '',
    'type'          => $filter_type,
    'from'          => $filter_from,
    'to'            => $filter_to,
]));
$pg_base = 'ingredient_history.php?' . ($base_query ? $base_query . '&' : '') . 'page=';

function fmtQ($n) { return rtrim(rtrim(number_format((float)$n, 4, '.', ''), '0'), '.'); }
function h($s)    { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$filter_ing_name = '';
if ($filter_ing > 0) {
    foreach ($ing_list as $il) {
        if ((int)$il['ingredient_id'] === $filter_ing) { $filter_ing_name = $il['ingredient_name']; break; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stock History | Bird's Nest Coffee</title>
<script>(function(){if(localStorage.getItem('theme')==='light')document.documentElement.setAttribute('data-theme','light');}());</script>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --bg:#0b0b0b; --bg-card:#131313; --bg-card-hover:#1a1a1a; --bg-input:#1a1a1a;
    --border:#222; --border-hover:#333;
    --accent:#d1904b; --accent-light:#e8b87a; --accent-dark:#a0702a;
    --text:#f5f5f5; --text-muted:#888; --text-light:#fff;
    --ok:#55e087; --low:#f1c40f; --danger:#ff5f5f; --blue:#3498db; --purple:#9b59b6;
    --shadow-sm:0 2px 8px rgba(0,0,0,.35); --shadow-md:0 4px 20px rgba(0,0,0,.45);
    --shadow-accent:0 0 0 3px rgba(209,144,75,.12);
    --radius:14px; --transition:all .22s cubic-bezier(.4,0,.2,1);
}
[data-theme="light"] {
    --bg:#F0F2F5; --bg-card:#FFFFFF; --bg-card-hover:#F5F7FA; --bg-input:#F9FAFB;
    --border:#E5E7EB; --border-hover:#D1D5DB;
    --text:#111827; --text-muted:#6B7280; --text-light:#111827;
    --shadow-sm:0 2px 8px rgba(0,0,0,.06); --shadow-md:0 4px 20px rgba(0,0,0,.08);
}
[data-theme="light"] .topbar { background:rgba(255,255,255,.97); }
[data-theme="light"] thead th { background:#fff; }
[data-theme="light"] tr:hover td { background:rgba(0,0,0,.02); }
[data-theme="light"] input,
[data-theme="light"] select { background:var(--bg-input)!important; color:var(--text)!important; border-color:var(--border)!important; color-scheme:light; }

*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Poppins',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; padding-bottom:40px; }
::-webkit-scrollbar { width:5px; height:5px; }
::-webkit-scrollbar-thumb { background:var(--accent); border-radius:10px; }

/* TOPBAR */
.topbar {
    position:sticky; top:0; z-index:200;
    display:flex; align-items:center; gap:10px; padding:10px 24px;
    background:rgba(11,11,11,.97); backdrop-filter:blur(20px);
    border-bottom:1px solid var(--border); flex-wrap:wrap;
}
.brand-icon  { width:34px; height:34px; border-radius:9px; background:linear-gradient(135deg,var(--accent-dark),var(--accent)); display:flex; align-items:center; justify-content:center; font-size:15px; color:#fff; flex-shrink:0; }
.brand-text  { display:flex; flex-direction:column; line-height:1.2; }
.brand-title { font-size:15px; font-weight:700; color:var(--text-light); }
.brand-sub   { font-size:10px; color:var(--text-muted); }
.topbar-sep  { width:1px; height:22px; background:var(--border); flex-shrink:0; }
.topbar-right { display:flex; align-items:center; gap:6px; margin-left:auto; flex-wrap:wrap; }

.btn-nav {
    display:inline-flex; align-items:center; gap:6px; padding:7px 14px;
    border-radius:50px; border:1px solid var(--border); background:var(--bg-input);
    color:var(--text-muted); text-decoration:none; font-size:12px; font-weight:500;
    transition:var(--transition); cursor:pointer; white-space:nowrap; font-family:'Poppins',sans-serif;
}
.btn-nav:hover { border-color:var(--accent); color:var(--accent); }
.btn-nav.icon-only { padding:7px 10px; }

/* STATS */
.stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; padding:14px 24px 0; }
.stat-card {
    background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius);
    padding:16px 18px; display:flex; align-items:center; gap:12px; position:relative; overflow:hidden;
}
.stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; border-radius:var(--radius) var(--radius) 0 0; }
.stat-card.s-deduct::before { background:linear-gradient(90deg,#c0392b,var(--danger)); }
.stat-card.s-add::before    { background:linear-gradient(90deg,#2ecc71,var(--ok)); }
.stat-card.s-total::before  { background:linear-gradient(90deg,var(--accent-dark),var(--accent-light)); }
.stat-card.s-net::before    { background:linear-gradient(90deg,#2471a3,var(--blue)); }
.stat-icon { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0; }
.stat-icon.deduct { background:rgba(255,95,95,.12); color:var(--danger); }
.stat-icon.add    { background:rgba(85,224,135,.12);  color:var(--ok); }
.stat-icon.total  { background:rgba(209,144,75,.12);  color:var(--accent); }
.stat-icon.net    { background:rgba(52,152,219,.12);  color:var(--blue); }
.stat-label { font-size:10px; color:var(--text-muted); font-weight:500; text-transform:uppercase; letter-spacing:.5px; }
.stat-num   { font-size:18px; font-weight:800; line-height:1.2; }
.stat-hint  { font-size:10px; color:var(--text-muted); margin-top:1px; }
.s-deduct .stat-num { color:var(--danger); }
.s-add    .stat-num { color:var(--ok); }
.s-total  .stat-num { color:var(--accent); }
.s-net    .stat-num { color:var(--blue); }

/* FILTER BAR */
.filter-bar {
    margin:12px 24px 0; padding:14px 18px;
    background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius);
    display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap;
}
.filter-group { display:flex; flex-direction:column; gap:5px; min-width:140px; }
.filter-label { font-size:10px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.5px; }
.filter-input {
    padding:7px 12px; border-radius:8px; border:1px solid var(--border);
    background:var(--bg-input); color:var(--text); font-size:13px;
    font-family:'Poppins',sans-serif; outline:none; transition:var(--transition);
}
.filter-input:focus { border-color:var(--accent); box-shadow:var(--shadow-accent); }
.filter-actions { display:flex; gap:8px; margin-left:auto; align-items:flex-end; }
.btn-filter {
    padding:7px 16px; border-radius:8px; border:1px solid var(--accent);
    background:var(--accent); color:#000; font-size:13px; font-weight:700;
    cursor:pointer; font-family:'Poppins',sans-serif; transition:var(--transition);
}
.btn-filter:hover { background:var(--accent-light); }
.btn-reset {
    padding:7px 14px; border-radius:8px; border:1px solid var(--border);
    background:transparent; color:var(--text-muted); font-size:13px;
    cursor:pointer; font-family:'Poppins',sans-serif; transition:var(--transition);
    text-decoration:none; display:inline-flex; align-items:center; gap:6px;
}
.btn-reset:hover { border-color:var(--border-hover); color:var(--text); }

/* ACTIVE FILTER CHIP */
.filter-chips { display:flex; align-items:center; gap:6px; padding:8px 24px 0; flex-wrap:wrap; }
.chip {
    display:inline-flex; align-items:center; gap:6px; padding:4px 12px;
    border-radius:50px; font-size:11px; font-weight:600;
    background:rgba(209,144,75,.12); color:var(--accent); border:1px solid rgba(209,144,75,.25);
}
.chip a { color:inherit; text-decoration:none; opacity:.7; margin-left:2px; }
.chip a:hover { opacity:1; }

/* TABLE */
.table-card { margin:12px 24px 0; background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; box-shadow:var(--shadow-sm); }
.table-header { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid var(--border); }
.table-title { font-size:14px; font-weight:700; display:flex; align-items:center; gap:8px; }
.row-count  { font-size:12px; color:var(--text-muted); }
.table-wrap { overflow:auto; max-height:calc(100vh - 350px); }
table { width:100%; border-collapse:collapse; font-size:13px; }
thead { position:sticky; top:0; z-index:10; }
th { padding:10px 14px; text-align:left; font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.6px; color:var(--text-muted); background:var(--bg-card); border-bottom:1px solid var(--border); white-space:nowrap; }
td { padding:11px 14px; border-bottom:1px solid var(--border); color:var(--text); white-space:nowrap; vertical-align:middle; }
tr:last-child td { border-bottom:none; }
tr:hover td { background:rgba(255,255,255,.025); }
tr.hidden { display:none !important; }

/* TYPE BADGE */
.type-badge {
    display:inline-flex; align-items:center; gap:6px; padding:4px 10px;
    border-radius:50px; font-size:11px; font-weight:700;
}
.type-badge.order_deduct  { background:rgba(255,95,95,.1);   color:var(--danger);  border:1px solid rgba(255,95,95,.2); }
.type-badge.order_restore { background:rgba(241,196,15,.1);  color:var(--low);     border:1px solid rgba(241,196,15,.2); }
.type-badge.quick_restock { background:rgba(85,224,135,.1);  color:var(--ok);      border:1px solid rgba(85,224,135,.2); }
.type-badge.po_received   { background:rgba(52,152,219,.1);  color:var(--blue);    border:1px solid rgba(52,152,219,.2); }
.type-badge.manual_adjust { background:rgba(155,89,182,.1);  color:var(--purple);  border:1px solid rgba(155,89,182,.2); }
.type-badge.count_adjust  { background:rgba(96,165,250,.1);  color:var(--blue);    border:1px solid rgba(96,165,250,.2); }

/* TOAST */
#toast-cnt { position:fixed; bottom:24px; right:20px; z-index:99999; display:flex; flex-direction:column-reverse; gap:8px; pointer-events:none; }
.toast { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:11px 16px; font-size:13px; font-weight:500; color:var(--text); box-shadow:var(--shadow-md); display:flex; align-items:center; gap:10px; min-width:220px; max-width:320px; transform:translateX(120%); transition:transform .3s cubic-bezier(.34,1.56,.64,1); pointer-events:auto; }
.toast.show { transform:translateX(0); }
.toast.success { border-left:3px solid var(--ok); }
.toast.error   { border-left:3px solid var(--danger); }

/* AMOUNT */
.amount-neg { color:var(--danger); font-weight:700; }
.amount-pos { color:var(--ok);     font-weight:700; }

/* ING NAME */
.ing-cell { display:flex; align-items:center; gap:8px; }
.ing-dot  { width:24px; height:24px; border-radius:6px; background:rgba(209,144,75,.1); display:flex; align-items:center; justify-content:center; font-size:11px; color:var(--accent); flex-shrink:0; }

/* EMPTY */
.empty-state { text-align:center; padding:60px 20px; }
.empty-state .ei { font-size:42px; color:var(--border-hover); margin-bottom:14px; }
.empty-state h3  { font-size:16px; font-weight:600; margin-bottom:6px; }
.empty-state p   { font-size:13px; color:var(--text-muted); }

/* SEARCH */
.search-wrap { display:flex; align-items:center; gap:8px; padding:7px 12px; border-radius:50px; border:1px solid var(--border); background:var(--bg-input); transition:var(--transition); max-width:240px; }
.search-wrap:focus-within { border-color:var(--accent); }
.search-wrap i { color:var(--text-muted); font-size:12px; }
.search-wrap input { border:none; background:transparent; outline:none; color:var(--text); font-size:12px; font-family:'Poppins',sans-serif; width:100%; }
.search-wrap input::placeholder { color:var(--text-muted); }

/* PAGINATION */
.pagination-wrap { display:flex; flex-direction:column; align-items:center; gap:10px; padding:18px 24px; border-top:1px solid var(--border); }
.pagination { display:flex; align-items:center; gap:4px; flex-wrap:wrap; justify-content:center; }
.pg-btn {
    display:inline-flex; align-items:center; justify-content:center;
    min-width:34px; height:34px; padding:0 10px;
    border-radius:8px; border:1px solid var(--border);
    background:var(--bg-input); color:var(--text-muted);
    text-decoration:none; font-size:13px; font-weight:500;
    transition:var(--transition); cursor:pointer; white-space:nowrap;
}
.pg-btn:hover:not(.disabled) { border-color:var(--accent); color:var(--accent); background:rgba(209,144,75,.06); }
.pg-btn.pg-active { background:var(--accent); border-color:var(--accent); color:#000; font-weight:700; cursor:default; }
.pg-btn.disabled  { opacity:.3; cursor:default; pointer-events:none; }
.pg-ellipsis { color:var(--text-muted); padding:0 2px; font-size:14px; line-height:34px; }
.pg-info { font-size:12px; color:var(--text-muted); }

@media (max-width:900px)  { .stats-row { grid-template-columns:repeat(2,1fr); } }
@media (max-width:640px)  { .stats-row,.filter-bar,.table-card,.filter-chips { margin-left:14px; margin-right:14px; } .topbar { padding:10px 14px; } }
@media print {
    .topbar,.filter-bar,.filter-chips { display:none!important; }
    body { background:#fff; color:#000; }
    .table-card { box-shadow:none; border:1px solid #ccc; margin:0; }
    .table-wrap { max-height:none; overflow:visible; }
}
</style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
    <a href="ingredients.php" class="btn-nav icon-only" title="Back to Ingredients"><i class="fa-solid fa-arrow-left"></i></a>
    <div class="brand-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
    <div class="brand-text">
        <span class="brand-title">Stock History<?= $filter_ing_name ? ' — ' . h($filter_ing_name) : '' ?></span>
        <span class="brand-sub">Bird's Nest Coffee &rsaquo; Ingredients</span>
    </div>
    <div class="topbar-right">
        <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input id="searchInput" placeholder="Search…" autocomplete="off" oninput="liveSearch(this.value)">
        </div>
        <button class="btn-nav" onclick="exportCSV()" title="Export current view to CSV"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
        <button class="btn-nav icon-only" onclick="toggleTheme()" title="Toggle theme"><i class="fa-solid fa-moon" id="themeIcon"></i></button>
    </div>
</div>

<!-- STATS -->
<div class="stats-row">
    <div class="stat-card s-deduct">
        <div class="stat-icon deduct"><i class="fa-solid fa-arrow-trend-down"></i></div>
        <div>
            <div class="stat-label">Deductions</div>
            <div class="stat-num" id="statDeductCnt"><?= $cnt_deduct ?></div>
            <div class="stat-hint" id="statDeductAmt"><?= fmtQ($total_deducted) ?> units total</div>
        </div>
    </div>
    <div class="stat-card s-add">
        <div class="stat-icon add"><i class="fa-solid fa-arrow-trend-up"></i></div>
        <div>
            <div class="stat-label">Additions</div>
            <div class="stat-num" id="statAddCnt"><?= $cnt_add ?></div>
            <div class="stat-hint" id="statAddAmt"><?= fmtQ($total_added) ?> units total</div>
        </div>
    </div>
    <div class="stat-card s-total">
        <div class="stat-icon total"><i class="fa-solid fa-list-ul"></i></div>
        <div>
            <div class="stat-label">Total Events</div>
            <div class="stat-num" id="statTotalCnt"><?= $total_count ?></div>
            <div class="stat-hint" id="statTotalHint"><?= number_format($total_count) ?> total records</div>
        </div>
    </div>
    <div class="stat-card s-net">
        <div class="stat-icon net"><i class="fa-solid fa-scale-balanced"></i></div>
        <div>
            <div class="stat-label">Net Change</div>
            <?php $net = $total_added - $total_deducted; ?>
            <div class="stat-num" id="statNetAmt" style="color:<?= $net >= 0 ? 'var(--ok)' : 'var(--danger)' ?>">
                <?= ($net >= 0 ? '+' : '') . fmtQ($net) ?>
            </div>
            <div class="stat-hint">Added minus deducted</div>
        </div>
    </div>
</div>

<!-- FILTER BAR -->
<form method="get" class="filter-bar">
    <div class="filter-group" style="min-width:200px">
        <label class="filter-label">Ingredient</label>
        <select name="ingredient_id" class="filter-input">
            <option value="">All Ingredients</option>
            <?php foreach ($ing_list as $il): ?>
            <option value="<?= $il['ingredient_id'] ?>" <?= $filter_ing === (int)$il['ingredient_id'] ? 'selected' : '' ?>>
                <?= h($il['ingredient_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-label">Movement Type</label>
        <select name="type" class="filter-input">
            <option value="">All Types</option>
            <option value="order_deduct"  <?= $filter_type === 'order_deduct'  ? 'selected' : '' ?>>Order Deduction</option>
            <option value="order_restore" <?= $filter_type === 'order_restore' ? 'selected' : '' ?>>Order Restore</option>
            <option value="quick_restock" <?= $filter_type === 'quick_restock' ? 'selected' : '' ?>>Quick Restock</option>
            <option value="po_received"   <?= $filter_type === 'po_received'   ? 'selected' : '' ?>>PO Received</option>
            <option value="manual_adjust" <?= $filter_type === 'manual_adjust' ? 'selected' : '' ?>>Manual Adjustment</option>
            <option value="count_adjust" <?= $filter_type === 'count_adjust' ? 'selected' : '' ?>>Stock Count</option>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-label">From Date</label>
        <input type="date" name="from" class="filter-input" value="<?= h($filter_from) ?>">
    </div>
    <div class="filter-group">
        <label class="filter-label">To Date</label>
        <input type="date" name="to" class="filter-input" value="<?= h($filter_to) ?>">
    </div>
    <div class="filter-actions">
        <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Filter</button>
        <a href="ingredient_history.php" class="btn-reset"><i class="fa-solid fa-rotate-left"></i> Reset</a>
    </div>
</form>

<?php if ($filter_ing || $filter_type || $filter_from || $filter_to): ?>
<div class="filter-chips">
    <span style="font-size:11px;color:var(--text-muted)">Active filters:</span>
    <?php if ($filter_ing_name): ?>
    <span class="chip"><i class="fa-solid fa-flask"></i> <?= h($filter_ing_name) ?> <a href="?<?= http_build_query(array_filter(['type'=>$filter_type,'from'=>$filter_from,'to'=>$filter_to])) ?>">×</a></span>
    <?php endif; ?>
    <?php if ($filter_type): ?>
    <?php $typeLabels = ['order_deduct'=>'Order Deduction','order_restore'=>'Order Restore','quick_restock'=>'Quick Restock','po_received'=>'PO Received','count_adjust'=>'Stock Count']; ?>
    <span class="chip"><i class="fa-solid fa-tag"></i> <?= h($typeLabels[$filter_type] ?? $filter_type) ?> <a href="?<?= http_build_query(array_filter(['ingredient_id'=>$filter_ing,'from'=>$filter_from,'to'=>$filter_to])) ?>">×</a></span>
    <?php endif; ?>
    <?php if ($filter_from): ?>
    <span class="chip"><i class="fa-solid fa-calendar-days"></i> From <?= h($filter_from) ?> <a href="?<?= http_build_query(array_filter(['ingredient_id'=>$filter_ing,'type'=>$filter_type,'to'=>$filter_to])) ?>">×</a></span>
    <?php endif; ?>
    <?php if ($filter_to): ?>
    <span class="chip"><i class="fa-solid fa-calendar-days"></i> To <?= h($filter_to) ?> <a href="?<?= http_build_query(array_filter(['ingredient_id'=>$filter_ing,'type'=>$filter_type,'from'=>$filter_from])) ?>">×</a></span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- TABLE -->
<div class="table-card">
    <div class="table-header">
        <div class="table-title"><i class="fa-solid fa-clock-rotate-left" style="color:var(--accent)"></i> Movement Log</div>
        <span class="row-count" id="rowCount">
            <?php if ($total_count > 0): ?>
            <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $per_page, $total_count)) ?> of <?= number_format($total_count) ?> records
            <?php else: ?>
            0 records
            <?php endif; ?>
        </span>
    </div>
    <div class="table-wrap">
        <?php if (empty($rows)): ?>
        <div class="empty-state">
            <div class="ei"><i class="fa-solid fa-clock-rotate-left"></i></div>
            <h3>No history yet</h3>
            <p>Stock movements will appear here once orders are placed, restocked, or received.</p>
        </div>
        <?php else: ?>
        <table id="histTable">
            <thead>
                <tr>
                    <th>Date &amp; Time</th>
                    <th>Ingredient</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Reference</th>
                    <th>Done By</th>
                </tr>
            </thead>
            <tbody id="histBody">
            <?php foreach ($rows as $r):
                $rawAmt    = (float)$r['amount'];
                $isDeduct  = $r['change_type'] === 'order_deduct'
                          || ($r['change_type'] === 'manual_adjust' && $rawAmt < 0)
                          || ($r['change_type'] === 'count_adjust' && $rawAmt < 0);
                $typeLabel = match((string)$r['change_type']) {
                    'order_deduct'  => ['Order Deduction',  'fa-arrow-trend-down'],
                    'order_restore' => ['Order Restore',    'fa-rotate-left'],
                    'quick_restock' => ['Quick Restock',    'fa-arrow-trend-up'],
                    'po_received'   => ['PO Received',      'fa-truck'],
                    'manual_adjust' => ['Manual Adjustment','fa-sliders'],
                    'count_adjust'  => ['Stock Count',       'fa-clipboard-check'],
                    default         => [$r['change_type'],  'fa-circle'],
                };
                $amtDisplay = ($isDeduct ? '−' : '+') . fmtQ(abs($rawAmt)) . ($r['unit'] ? ' ' . h($r['unit']) : '');
                $ts = date('d M Y, H:i', strtotime($r['created_at']));
                $ref = h(_hist_ref($r));
            ?>
            <tr data-id="<?= (int)$r['id'] ?>" data-search="<?= h(strtolower($r['ingredient_name'] . ' ' . ($r['reference'] ?? '') . ' ' . ($r['created_by'] ?? ''))) ?>">
                <td style="color:var(--text-muted);font-size:12px"><?= $ts ?></td>
                <td>
                    <div class="ing-cell">
                        <div class="ing-dot"><i class="fa-solid fa-leaf"></i></div>
                        <a href="ingredient_history.php?ingredient_id=<?= (int)$r['ingredient_id'] ?>" style="color:var(--text);text-decoration:none;font-weight:500">
                            <?= h($r['ingredient_name']) ?>
                        </a>
                    </div>
                </td>
                <td>
                    <span class="type-badge <?= $r['change_type'] ?>">
                        <i class="fa-solid <?= $typeLabel[1] ?>"></i>
                        <?= $typeLabel[0] ?>
                    </span>
                </td>
                <td class="<?= $isDeduct ? 'amount-neg' : 'amount-pos' ?>"><?= $amtDisplay ?></td>
                <td style="font-size:12px;color:var(--text-muted)"><?= $ref ?></td>
                <td style="font-size:12px">
                    <?php if ($r['created_by']): ?>
                    <span style="display:inline-flex;align-items:center;gap:5px">
                        <i class="fa-solid fa-user" style="font-size:10px;color:var(--text-muted)"></i>
                        <?= h($r['created_by']) ?>
                    </span>
                    <?php else: ?>
                    <span style="color:var(--border-hover)">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php if ($total_pages > 1): ?>
    <div class="pagination-wrap">
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="<?= $pg_base . 1 ?>" class="pg-btn" title="First"><i class="fa-solid fa-angles-left"></i></a>
            <a href="<?= $pg_base . ($page - 1) ?>" class="pg-btn" title="Previous"><i class="fa-solid fa-angle-left"></i></a>
            <?php else: ?>
            <span class="pg-btn disabled"><i class="fa-solid fa-angles-left"></i></span>
            <span class="pg-btn disabled"><i class="fa-solid fa-angle-left"></i></span>
            <?php endif; ?>

            <?php
            // Build visible page window: always show 1, last, and pages around current
            $window = array_unique(array_merge([1, $total_pages], range(max(2, $page - 2), min($total_pages - 1, $page + 2))));
            sort($window);
            $prev_p = null;
            foreach ($window as $p_num):
                if ($prev_p !== null && $p_num - $prev_p > 1) echo '<span class="pg-ellipsis">…</span>';
            ?>
            <a href="<?= $pg_base . $p_num ?>" class="pg-btn <?= $p_num === $page ? 'pg-active' : '' ?>"><?= $p_num ?></a>
            <?php $prev_p = $p_num; endforeach; ?>

            <?php if ($page < $total_pages): ?>
            <a href="<?= $pg_base . ($page + 1) ?>" class="pg-btn" title="Next"><i class="fa-solid fa-angle-right"></i></a>
            <a href="<?= $pg_base . $total_pages ?>" class="pg-btn" title="Last"><i class="fa-solid fa-angles-right"></i></a>
            <?php else: ?>
            <span class="pg-btn disabled"><i class="fa-solid fa-angle-right"></i></span>
            <span class="pg-btn disabled"><i class="fa-solid fa-angles-right"></i></span>
            <?php endif; ?>
        </div>
        <div class="pg-info">
            Page <?= $page ?> of <?= $total_pages ?> &nbsp;·&nbsp; <?= number_format($total_count) ?> total records
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
const CURRENT_PAGE  = <?= $page ?>;
const TOTAL_RECORDS = <?= $total_count ?>;
const OFFSET_START  = <?= $offset ?>;
const PER_PAGE      = <?= $per_page ?>;

/* ── LIVE SEARCH ── */
function liveSearch(q) {
    q = q.toLowerCase().trim();
    let shown = 0;
    document.querySelectorAll('#histBody tr').forEach(tr => {
        const match = !q || tr.dataset.search.includes(q);
        tr.classList.toggle('hidden', !match);
        if (match) shown++;
    });
    if (q) {
        document.getElementById('rowCount').textContent = shown + ' match' + (shown !== 1 ? 'es' : '') + ' on this page';
    } else {
        const from = Math.min(OFFSET_START + 1, TOTAL_RECORDS);
        const to   = Math.min(OFFSET_START + shown, TOTAL_RECORDS);
        document.getElementById('rowCount').textContent = from + '–' + to + ' of ' + TOTAL_RECORDS.toLocaleString() + ' records';
    }
}

/* ── THEME ── */
function toggleTheme() {
    const html = document.documentElement;
    const icon = document.getElementById('themeIcon');
    const light = html.getAttribute('data-theme') === 'light';
    if (light) { html.removeAttribute('data-theme'); icon.className = 'fa-solid fa-moon'; localStorage.setItem('theme','dark'); }
    else        { html.setAttribute('data-theme','light'); icon.className = 'fa-solid fa-sun'; localStorage.setItem('theme','light'); }
}

/* ── REAL-TIME POLLING ── */
const TYPE_META = {
    order_deduct:  { label: 'Order Deduction',  icon: 'fa-arrow-trend-down' },
    order_restore: { label: 'Order Restore',    icon: 'fa-rotate-left'      },
    quick_restock: { label: 'Quick Restock',    icon: 'fa-arrow-trend-up'   },
    po_received:   { label: 'PO Received',      icon: 'fa-truck'            },
    manual_adjust: { label: 'Manual Adjustment',icon: 'fa-sliders'          },
    count_adjust:  { label: 'Stock Count',       icon: 'fa-clipboard-check' },
};

function buildRow(r) {
    const rawAmt   = parseFloat(r.amount);
    const isDeduct = r.change_type === 'order_deduct'
                  || (r.change_type === 'manual_adjust' && rawAmt < 0)
                  || (r.change_type === 'count_adjust' && rawAmt < 0);
    const meta     = TYPE_META[r.change_type] || { label: r.change_type, icon: 'fa-circle' };
    const amt      = Math.abs(rawAmt);
    const unit     = r.unit ? ' ' + r.unit : '';
    const amtStr   = (isDeduct ? '−' : '+') + amt.toFixed(4).replace(/\.?0+$/, '') + unit;
    const ts       = new Date(r.created_at.replace(' ', 'T'));
    const tsStr    = ts.toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' })
                   + ', ' + ts.toLocaleTimeString('en-GB', { hour:'2-digit', minute:'2-digit' });
    const ref      = r.display_ref || r.reference || '—';
    const by       = r.created_by
        ? `<span style="display:inline-flex;align-items:center;gap:5px"><i class="fa-solid fa-user" style="font-size:10px;color:var(--text-muted)"></i>${esc(r.created_by)}</span>`
        : `<span style="color:var(--border-hover)">—</span>`;
    const search   = (r.ingredient_name + ' ' + ref + ' ' + (r.created_by || '')).toLowerCase();

    return `<tr data-id="${r.id}" data-search="${esc(search)}" style="animation:fadeIn .4s ease">
        <td style="color:var(--text-muted);font-size:12px">${tsStr}</td>
        <td>
            <div class="ing-cell">
                <div class="ing-dot"><i class="fa-solid fa-leaf"></i></div>
                <a href="ingredient_history.php?ingredient_id=${r.ingredient_id}" style="color:var(--text);text-decoration:none;font-weight:500">${esc(r.ingredient_name)}</a>
            </div>
        </td>
        <td><span class="type-badge ${r.change_type}"><i class="fa-solid ${meta.icon}"></i> ${meta.label}</span></td>
        <td class="${isDeduct ? 'amount-neg' : 'amount-pos'}">${amtStr}</td>
        <td style="font-size:12px;color:var(--text-muted)">${esc(ref)}</td>
        <td style="font-size:12px">${by}</td>
    </tr>`;
}

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

let lastId = 0;
let cntDeduct = <?= $cnt_deduct ?>;
let cntAdd    = <?= $cnt_add ?>;
let totalDeducted = <?= round($total_deducted, 4) ?>;
let totalAdded    = <?= round($total_added,    4) ?>;

function initLastId() {
    const rows = document.querySelectorAll('#histBody tr[data-id]');
    rows.forEach(r => { const id = parseInt(r.dataset.id); if (id > lastId) lastId = id; });
}

async function pollHistory() {
    try {
        const params = new URLSearchParams(location.search);
        params.set('ajax', '1');
        params.set('last_id', lastId);
        const res  = await fetch('ingredient_history.php?' + params);
        const data = await res.json();
        if (!data.rows || data.rows.length === 0) return;

        const tbody  = document.getElementById('histBody');
        const empty  = document.getElementById('emptyState');
        let newHtml  = '';

        data.rows.forEach(r => {
            const id = parseInt(r.id);
            if (id > lastId) lastId = id;

            const rawAmt = parseFloat(r.amount);
            const isDeduct = r.change_type === 'order_deduct'
                          || (r.change_type === 'manual_adjust' && rawAmt < 0)
                          || (r.change_type === 'count_adjust' && rawAmt < 0);
            if (isDeduct) {
                cntDeduct++;
                totalDeducted += Math.abs(rawAmt);
            } else {
                cntAdd++;
                totalAdded += Math.abs(rawAmt);
            }
            newHtml += buildRow(r);
        });

        // Only insert rows into the DOM when viewing page 1 (newest records)
        if (CURRENT_PAGE === 1 && tbody) {
            tbody.insertAdjacentHTML('afterbegin', newHtml);
            if (empty) empty.style.display = 'none';
        }

        // Update stats (always, regardless of page)
        const net = totalAdded - totalDeducted;
        document.getElementById('statDeductCnt').textContent  = cntDeduct;
        document.getElementById('statDeductAmt').textContent  = fmt(totalDeducted) + ' units total';
        document.getElementById('statAddCnt').textContent     = cntAdd;
        document.getElementById('statAddAmt').textContent     = fmt(totalAdded) + ' units total';
        document.getElementById('statTotalCnt').textContent   = cntDeduct + cntAdd;
        document.getElementById('statNetAmt').textContent     = (net >= 0 ? '+' : '') + fmt(net);
        document.getElementById('statNetAmt').style.color     = net >= 0 ? 'var(--ok)' : 'var(--danger)';

    } catch (_) { /* silent — network hiccup */ }
}

function fmt(n) { return parseFloat(n).toFixed(4).replace(/\.?0+$/, ''); }

/* ── EXPORT CSV ── */
function exportCSV() {
    const headers = ['Date & Time','Ingredient','Type','Amount','Reference','Done By'];
    const rows = [];
    document.querySelectorAll('#histBody tr:not(.hidden)').forEach(tr => {
        const tds = tr.querySelectorAll('td');
        rows.push([
            tds[0]?.textContent?.trim() || '',
            tds[1]?.querySelector('a')?.textContent?.trim() || '',
            (tds[2]?.querySelector('.type-badge')?.textContent?.trim() || '').replace(/\s+/g,' '),
            tds[3]?.textContent?.trim() || '',
            tds[4]?.textContent?.trim() || '',
            tds[5]?.textContent?.trim().replace(/\s+/g,' ') || '',
        ]);
    });
    const csv  = [headers, ...rows].map(r => r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n');
    const blob = new Blob(['﻿' + csv], { type:'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `stock_history_${new Date().toISOString().slice(0,10)}.csv`;
    a.click(); URL.revokeObjectURL(a.href);
    showToast(`Exported ${rows.length} record${rows.length !== 1 ? 's' : ''}`, 'success');
}

/* ── TOAST ── */
function showToast(msg, type = 'success') {
    const c = document.getElementById('toast-cnt');
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    const icon = type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation';
    const col  = type === 'success' ? 'var(--ok)' : 'var(--danger)';
    t.innerHTML = `<i class="fa-solid ${icon}" style="color:${col};flex-shrink:0"></i><span>${msg}</span>`;
    c.appendChild(t);
    requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 380); }, 3500);
}

document.addEventListener('DOMContentLoaded', () => {
    if (localStorage.getItem('theme') === 'light')
        document.getElementById('themeIcon').className = 'fa-solid fa-sun';
    initLastId();
    setInterval(pollHistory, 5000);
});
</script>
<style>
@keyframes fadeIn { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }
</style>
<div id="toast-cnt"></div>
</body>
</html>
