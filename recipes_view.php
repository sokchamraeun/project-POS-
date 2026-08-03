<?php
require 'auth.php';
require 'config.php';
if (!can('recipes')) { header("Location: dashboard.php?denied=1"); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

/* ── Theme flash prevention ── */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ── Category colours & icons ── */
function getCategoryMeta($cat) {
    $map = [
        'Iced'     => ['color' => '#3498db', 'icon' => 'fa-snowflake'],
        'Hot'      => ['color' => '#e74c3c', 'icon' => 'fa-mug-hot'],
        'Frappe'   => ['color' => '#9b59b6', 'icon' => 'fa-blender'],
        'Juice'    => ['color' => '#27ae60', 'icon' => 'fa-lemon'],
        'Milk Tea' => ['color' => '#d1904b', 'icon' => 'fa-circle-dot'],
    ];
    return $map[$cat] ?? ['color' => '#888', 'icon' => 'fa-circle'];
}

/* ═══════════════════════════════
   1. INGREDIENT COSTS & STOCK
═══════════════════════════════ */
$ingredientMeta = [];
$qIng = mysqli_query($conn, "
    SELECT ingredient_id, ingredient_name, cost_price, purchase_qty, cost_per_unit,
           stock_quantity, minimum_stock, unit
    FROM ingredients
");
while ($r = mysqli_fetch_assoc($qIng)) {
    $iid = (int)$r['ingredient_id'];
    $cpu = (float)$r['cost_per_unit'];
    if ($cpu <= 0 && (float)$r['purchase_qty'] > 0) {
        $cpu = (float)$r['cost_price'] / (float)$r['purchase_qty'];
    }
    $ingredientMeta[$iid] = [
        'name'          => $r['ingredient_name'],
        'cpu'           => $cpu,
        'stock'         => (float)$r['stock_quantity'],
        'min_stock'     => (float)$r['minimum_stock'],
        'unit'          => $r['unit'],
        'low_stock'     => (float)$r['stock_quantity'] < (float)$r['minimum_stock'],
    ];
}

/* ═══════════════════════════════
   2. RECIPES (products WITH ingredients)
═══════════════════════════════ */
$res = mysqli_query($conn, "
    SELECT
        p.product_id,
        p.name  AS product_name,
        p.category,
        p.image,
        p.price,
        pi.ingredient_id,
        i.ingredient_name,
        i.unit,
        pi.amount_used
    FROM products p
    JOIN product_ingredients pi ON p.product_id = pi.product_id
    JOIN ingredients i           ON pi.ingredient_id = i.ingredient_id
    WHERE pi.amount_used > 0
    ORDER BY p.category ASC, p.name ASC, i.ingredient_name ASC
");

$recipes = [];
while ($row = mysqli_fetch_assoc($res)) {
    $pid = (int)$row['product_id'];
    if (!isset($recipes[$pid])) {
        $recipes[$pid] = [
            'product_id'   => $pid,
            'product_name' => $row['product_name'],
            'category'     => $row['category'],
            'image'        => $row['image'],
            'price'        => (float)$row['price'],
            'items'        => [],
            'cogs'         => 0.0,
            'has_low_stock'=> false,
        ];
    }
    $iid     = (int)$row['ingredient_id'];
    $amount  = (float)$row['amount_used'];
    $cpu     = $ingredientMeta[$iid]['cpu'] ?? 0;
    $low     = $ingredientMeta[$iid]['low_stock'] ?? false;

    $recipes[$pid]['items'][] = [
        'ingredient_id'   => $iid,
        'ingredient_name' => $row['ingredient_name'],
        'unit'            => $row['unit'],
        'amount_used'     => $amount,
        'cost'            => $amount * $cpu,
        'low_stock'       => $low,
    ];
    $recipes[$pid]['cogs'] += $amount * $cpu;
    if ($low) $recipes[$pid]['has_low_stock'] = true;
}

/* ═══════════════════════════════
   3. PRODUCTS WITHOUT RECIPES
═══════════════════════════════ */
$noRecipe = [];
$qNR = mysqli_query($conn, "
    SELECT p.product_id, p.name, p.category, p.image
    FROM products p
    WHERE NOT EXISTS (
        SELECT 1 FROM product_ingredients pi
        WHERE pi.product_id = p.product_id AND pi.amount_used > 0
    )
    ORDER BY p.category, p.name
");
while ($r = mysqli_fetch_assoc($qNR)) {
    $noRecipe[] = $r;
}

/* ═══════════════════════════════
   3b. INLINE EDITOR DATA
   (mirrors manage_recipe.php's split of base / Milk base / Sugar Syrup,
   so the in-page modal can save through the same handler)
═══════════════════════════════ */
$baseIngredients = [];
$qBases = mysqli_query($conn, "
    SELECT ingredient_id, ingredient_name FROM ingredients
    WHERE ingredient_name NOT IN ('Milk base','Sugar Syrup')
    ORDER BY ingredient_name
");
while ($row = mysqli_fetch_assoc($qBases)) {
    $baseIngredients[] = ['id' => (int)$row['ingredient_id'], 'name' => $row['ingredient_name']];
}

$recipeEditData = [];
foreach ($recipes as $pid => $rec) {
    $base = []; $milk = 0; $syrup = 0;
    foreach ($rec['items'] as $it) {
        if ($it['ingredient_name'] === 'Milk base')        $milk  = (int)$it['amount_used'];
        elseif ($it['ingredient_name'] === 'Sugar Syrup')  $syrup = (int)$it['amount_used'];
        else $base[] = ['id' => $it['ingredient_id'], 'amount' => (int)$it['amount_used']];
    }
    $recipeEditData[$pid] = ['name' => $rec['product_name'], 'base' => $base, 'milk' => $milk, 'syrup' => $syrup];
}
foreach ($noRecipe as $nr) {
    $recipeEditData[(int)$nr['product_id']] = ['name' => $nr['name'], 'base' => [], 'milk' => 0, 'syrup' => 0];
}

/* ═══════════════════════════════
   4. STATS
═══════════════════════════════ */
$totalRecipes     = count($recipes);
$totalNoRecipe    = count($noRecipe);
$avgIngredients   = $totalRecipes > 0
    ? round(array_sum(array_map(fn($r) => count($r['items']), $recipes)) / $totalRecipes, 1)
    : 0;
$lowStockRecipes  = count(array_filter($recipes, fn($r) => $r['has_low_stock']));
/* Every product is either counted in $totalRecipes or in $totalNoRecipe — never both,
   so the two always add up to the catalogue size. Shown as denominators on the stat
   cards so the numbers can be reconciled at a glance. */
$totalProducts    = $totalRecipes + $totalNoRecipe;
$okStockRecipes   = $totalRecipes - $lowStockRecipes;

/* Ingredients used in a recipe but carrying no unit cost contribute $0 to that
   recipe's COGS, so the cost shown is silently too low. Surface them rather than
   letting a confident-looking figure quietly understate the margin. */
$uncostedIngs = [];
foreach ($recipes as $rec) {
    foreach ($rec['items'] as $it) {
        $meta = $ingredientMeta[$it['ingredient_id']] ?? null;
        if ($meta && $meta['cpu'] <= 0) $uncostedIngs[$meta['name']] = true;
    }
}
$uncostedIngs = array_keys($uncostedIngs);
sort($uncostedIngs);
$uncostedCount = count($uncostedIngs);

/* ── Category counts ── */
$categoryCounts = [];
foreach ($recipes as $r) {
    $cat = $r['category'] ?: 'Other';
    $categoryCounts[$cat] = ($categoryCounts[$cat] ?? 0) + 1;
}
ksort($categoryCounts);

/* ── JSON for JS ── */
$recipesJson = [];
foreach ($recipes as $r) {
    $recipesJson[$r['product_id']] = [
        'name'       => $r['product_name'],
        'category'   => $r['category'],
        'cogs'       => round($r['cogs'], 3),
        'price'      => $r['price'],
        'ing_count'  => count($r['items']),
        'low_stock'  => $r['has_low_stock'],
        'ingredients'=> array_map(fn($it) => strtolower($it['ingredient_name']), $r['items']),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Drink Recipes | Bird's Nest Coffee</title>
<script>(function(){if(localStorage.getItem('theme')==='light')document.documentElement.setAttribute('data-theme','light');})();</script>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* ── VARS ── */
:root {
    --bg:#0b0b0b; --bg-card:#131313; --bg-card-hover:#1a1a1a;
    --bg-input:#1a1a1a; --border:#222; --border-hover:#333;
    --accent:#d1904b; --accent-light:#e8b87a; --accent-dark:#a0702a;
    --text:#f5f5f5; --text-muted:#888; --text-light:#fff;
    --success:#55e087; --danger:#ff5f5f; --warning:#f1c40f;
    --shadow-sm:0 2px 8px rgba(0,0,0,.35); --shadow-md:0 4px 20px rgba(0,0,0,.45);
    --shadow-lg:0 8px 40px rgba(0,0,0,.55); --shadow-accent:0 4px 20px rgba(209,144,75,.18);
    --transition:all .25s cubic-bezier(.4,0,.2,1); --radius:14px;
}
[data-theme="light"] {
    --bg:#F0F2F5; --bg-card:#FFFFFF; --bg-card-hover:#F5F7FA;
    --bg-input:#F9FAFB; --border:#E5E7EB; --border-hover:#D1D5DB;
    --text:#111827; --text-muted:#6B7280; --text-light:#fff;
    --shadow-sm:0 2px 8px rgba(0,0,0,.06); --shadow-md:0 4px 20px rgba(0,0,0,.08);
    --shadow-lg:0 8px 40px rgba(0,0,0,.12);
}
[data-theme="light"] .topbar { background:rgba(255,255,255,.97); }
[data-theme="light"] .card   { background:var(--bg-card); }
[data-theme="light"] .stat-bar { background:var(--bg-card); }
[data-theme="light"] .ing-row  { background:rgba(0,0,0,.025); }
[data-theme="light"] .ing-row:hover { background:rgba(0,0,0,.05); }
[data-theme="light"] input,[data-theme="light"] select {
    background:var(--bg-input) !important; color:var(--text) !important;
    border-color:var(--border) !important; color-scheme:light;
}

*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
html { scroll-behavior:smooth; scroll-padding-top:80px; }
body { font-family:'Poppins',sans-serif; background:var(--bg); color:var(--text);
       padding:0 0 48px; min-height:100vh; }
::-webkit-scrollbar { width:6px; }
::-webkit-scrollbar-thumb { background:var(--accent); border-radius:10px; }

/* ── TOPBAR ── */
.topbar {
    position:sticky; top:0; z-index:100;
    display:flex; align-items:center; justify-content:space-between;
    gap:12px; padding:13px 28px;
    background:rgba(14,14,14,.97); backdrop-filter:blur(16px);
    border-bottom:1px solid var(--border); flex-wrap:wrap;
}
.topbar-left, .topbar-right { display:flex; align-items:center; gap:8px; }
.topbar-center { flex:1; min-width:200px; }
.topbar-center h1 {
    font-size:17px; font-weight:700; color:var(--text-light);
    display:flex; align-items:center; gap:8px; white-space:nowrap;
}
.topbar-center h1 i { color:var(--accent); }
.topbar-center .count { font-size:12px; color:var(--text-muted); font-weight:400; }
.btn-nav {
    display:inline-flex; align-items:center; gap:6px; padding:7px 14px;
    border-radius:50px; border:1px solid var(--border); background:var(--bg-input);
    color:var(--text-muted); text-decoration:none; font-size:13px; font-weight:500;
    transition:var(--transition); cursor:pointer; white-space:nowrap;
    font-family:'Poppins',sans-serif;
}
.btn-nav:hover { border-color:var(--accent); color:var(--accent); }
.search-wrap {
    display:flex; align-items:center; gap:8px; padding:7px 14px;
    border-radius:50px; border:1px solid var(--border); background:var(--bg-input);
    transition:var(--transition); min-width:220px;
}
.search-wrap:focus-within { border-color:var(--accent); box-shadow:var(--shadow-accent); }
.search-wrap i { color:var(--text-muted); font-size:13px; flex-shrink:0; }
.search-wrap input {
    border:none; background:transparent; outline:none; color:var(--text);
    font-size:13px; font-family:'Poppins',sans-serif; width:100%;
}
.search-wrap input::placeholder { color:var(--text-muted); }
.sort-select {
    padding:7px 12px; border-radius:50px; border:1px solid var(--border);
    background:var(--bg-input); color:var(--text); font-size:12px;
    font-family:'Poppins',sans-serif; outline:none; cursor:pointer; transition:var(--transition);
}
.sort-select:hover { border-color:var(--accent); }

/* ── STAT BAR ── */
.stat-bar {
    display:flex; gap:0; align-items:stretch; margin:16px 28px 0;
    background:var(--bg-card); border-radius:var(--radius);
    border:1px solid var(--border); overflow:hidden;
}
.stat-item {
    flex:1; display:flex; align-items:center; gap:10px;
    padding:14px 20px; border-right:1px solid var(--border);
    transition:var(--transition);
}
.stat-item:last-child { border-right:none; }
.stat-item:hover { background:var(--bg-card-hover); }
.stat-icon {
    width:36px; height:36px; border-radius:10px; display:flex;
    align-items:center; justify-content:center; font-size:16px; flex-shrink:0;
}
.stat-icon.orange { background:rgba(209,144,75,.12); color:var(--accent); }
.stat-icon.green  { background:rgba(85,224,135,.12);  color:var(--success); }
.stat-icon.red    { background:rgba(255,95,95,.12);   color:var(--danger); }
.stat-icon.yellow { background:rgba(241,196,15,.12);  color:var(--warning); }
.stat-label { font-size:11px; color:var(--text-muted); font-weight:500; }
.stat-value { font-size:20px; font-weight:800; color:var(--text-light); line-height:1.1; }
.stat-sub   { font-size:10px; color:var(--text-muted); font-weight:500; margin-top:2px; opacity:.75; }

/* Cost-integrity notice: informational, not an error — the recipes are fine,
   the costing input is incomplete. */
.cost-gap-bar{
    display:flex; align-items:flex-start; gap:10px;
    margin:0 28px 16px; padding:11px 16px; border-radius:12px;
    font-size:12.5px; line-height:1.55;
    background:rgba(241,196,15,.08); border:1px solid rgba(241,196,15,.22); color:#f1c40f;
}
.cost-gap-bar i{ margin-top:2px; flex-shrink:0; }
.cost-gap-bar strong{ color:#ffd964; font-weight:700; }
.cost-gap-names{ color:var(--text-muted); }
.cost-gap-bar a{ color:var(--accent); text-decoration:none; font-weight:600; margin-left:6px; white-space:nowrap; }
.cost-gap-bar a:hover{ text-decoration:underline; }

/* ── CATEGORY PILLS ── */
.cat-bar {
    display:flex; gap:8px; flex-wrap:wrap; padding:16px 28px 0;
    align-items:center;
}
.cat-pill {
    display:inline-flex; align-items:center; gap:6px; padding:7px 16px;
    border-radius:50px; border:1px solid var(--border); background:var(--bg-card);
    color:var(--text-muted); font-size:12px; font-weight:600; cursor:pointer;
    transition:var(--transition); user-select:none; white-space:nowrap;
}
.cat-pill:hover { border-color:var(--accent); color:var(--accent); }
.cat-pill.active { color:#000; border-color:transparent; }
.cat-pill .pill-count {
    font-size:10px; font-weight:700; padding:1px 6px; border-radius:20px;
    background:rgba(255,255,255,.2);
}
.cat-pill:not(.active) .pill-count { background:rgba(209,144,75,.15); color:var(--accent); }

/* ── GRID ── */
.grid {
    display:grid;
    grid-template-columns:repeat(auto-fill, minmax(320px, 1fr));
    gap:20px; padding:20px 28px 0;
}

/* ── CARD ── */
.card {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:var(--radius); overflow:hidden; transition:var(--transition);
    position:relative; display:flex; flex-direction:column;
}
.card:hover { transform:translateY(-4px); border-color:var(--border-hover); box-shadow:var(--shadow-md); }
.card.hidden { display:none; }

/* card top accent line */
.card::before {
    content:''; position:absolute; top:0; left:0; right:0; height:3px;
    background:linear-gradient(90deg,var(--accent),var(--accent-light));
    opacity:0; transition:var(--transition);
}
.card:hover::before { opacity:1; }

/* product image */
.card-img {
    height:200px; overflow:hidden; position:relative; flex-shrink:0;
    background:var(--bg-card-hover);
}
.card-img img {
    width:100%; height:100%; object-fit:cover; transition:transform .5s ease;
}
.card:hover .card-img img { transform:scale(1.06); }
.card-img .no-img {
    width:100%; height:100%; display:flex; align-items:center; justify-content:center;
    font-size:40px; color:var(--border-hover);
}
.card-img .cat-overlay {
    position:absolute; top:10px; left:10px;
    display:inline-flex; align-items:center; gap:5px; padding:4px 12px;
    border-radius:50px; font-size:11px; font-weight:700; color:#fff;
    backdrop-filter:blur(8px); background:rgba(0,0,0,.4);
    border:1px solid rgba(255,255,255,.15);
}
.card-img .low-stock-badge {
    position:absolute; top:10px; right:10px;
    padding:3px 10px; border-radius:50px; font-size:10px; font-weight:700;
    background:rgba(255,95,95,.9); color:#fff;
    display:flex; align-items:center; gap:4px;
}
.card-img .low-stock-badge i { font-size:9px; }

/* card body */
.card-body { padding:14px 16px; flex:1; display:flex; flex-direction:column; gap:10px; }
.card-title { font-size:15px; font-weight:700; color:var(--text-light); transition:var(--transition); }
.card:hover .card-title { color:var(--accent); }
.card-meta {
    display:flex; align-items:center; gap:12px; flex-wrap:wrap;
}
.meta-pill {
    display:inline-flex; align-items:center; gap:4px; font-size:11px;
    color:var(--text-muted); padding:2px 8px; border-radius:20px;
    background:var(--bg-input); border:1px solid var(--border);
}
.meta-pill.green { color:var(--success); background:rgba(85,224,135,.08); border-color:rgba(85,224,135,.2); }
.meta-pill.orange { color:var(--accent); background:rgba(209,144,75,.08); border-color:rgba(209,144,75,.2); }

/* ingredients table */
.ing-table { width:100%; border-collapse:collapse; margin-top:2px; }
.ing-table th {
    text-align:left; font-size:10px; font-weight:600; text-transform:uppercase;
    letter-spacing:.5px; color:var(--text-muted); padding:4px 8px 6px;
}
.ing-table th:last-child { text-align:right; }
.ing-row { display:table-row; }
.ing-row td {
    padding:7px 8px; font-size:12px; background:rgba(255,255,255,.02);
    border-radius:0; transition:var(--transition);
}
.ing-row:hover td { background:rgba(255,255,255,.05); }
.ing-row td:first-child { border-radius:6px 0 0 6px; }
.ing-row td:last-child  { border-radius:0 6px 6px 0; text-align:right; font-weight:600; color:var(--accent); }
.ing-name { display:flex; align-items:center; gap:6px; }
.ing-name i { font-size:11px; color:var(--text-muted); }
.ing-low { color:var(--danger) !important; }
.ing-low i { color:var(--danger) !important; }

/* card actions */
.card-actions {
    display:flex; gap:8px; padding:12px 16px;
    border-top:1px solid var(--border); margin-top:auto;
}
.btn-action {
    display:inline-flex; align-items:center; gap:5px; padding:7px 14px;
    border-radius:8px; font-size:12px; font-weight:600; cursor:pointer;
    text-decoration:none; transition:var(--transition); border:none;
    font-family:'Poppins',sans-serif;
}
.btn-action.orange { background:var(--accent); color:#000; }
.btn-action.orange:hover { background:var(--accent-light); transform:translateY(-1px); box-shadow:var(--shadow-accent); }
.btn-action.ghost { background:transparent; color:var(--text-muted); border:1px solid var(--border); }
.btn-action.ghost:hover { border-color:var(--accent); color:var(--accent); }

/* ── NO RECIPE SECTION ── */
.no-recipe-section {
    margin:20px 28px 0; padding:18px 20px;
    background:rgba(255,95,95,.05); border:1px solid rgba(255,95,95,.2);
    border-radius:var(--radius);
}
.no-recipe-header {
    display:flex; align-items:center; justify-content:space-between;
    margin-bottom:12px;
}
.no-recipe-header h3 {
    font-size:14px; font-weight:700; color:var(--danger);
    display:flex; align-items:center; gap:8px;
}
.no-recipe-pills { display:flex; gap:8px; flex-wrap:wrap; }
.no-recipe-pill {
    display:inline-flex; align-items:center; gap:6px; padding:5px 12px;
    border-radius:20px; font-size:12px; font-weight:500; border:1px solid rgba(255,95,95,.25);
    background:rgba(255,95,95,.06); color:var(--text-muted); text-decoration:none;
    transition:var(--transition); cursor:pointer; font-family:'Poppins',sans-serif;
}
.no-recipe-pill:hover { border-color:var(--danger); color:var(--danger); background:rgba(255,95,95,.1); }

/* ── EMPTY STATE ── */
.empty-grid {
    grid-column:1/-1; text-align:center; padding:60px 20px;
    background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius);
}
.empty-grid i { font-size:44px; color:var(--border-hover); margin-bottom:14px; display:block; }
.empty-grid h3 { color:var(--text); font-size:18px; margin-bottom:6px; }
.empty-grid p  { color:var(--text-muted); font-size:13px; }

/* ── PRINT STYLES ── */
@media print {
    .topbar, .stat-bar, .cat-bar, .card-actions, .no-recipe-section,
    .card-img .low-stock-badge { display:none !important; }
    body { background:#fff; color:#000; padding:0; }
    .card { break-inside:avoid; box-shadow:none; border:1px solid #ccc; }
    .grid { grid-template-columns:1fr 1fr; gap:16px; padding:16px; }
    .ing-row td { background:#f8f8f8; color:#000; }
    .card-title, .card-meta { color:#000; }
}

/* ── RESPONSIVE ── */
@media (max-width:900px) {
    .grid { grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); padding:16px; }
    .stat-bar { margin:12px 16px 0; }
    .cat-bar  { padding:12px 16px 0; }
    .no-recipe-section { margin:12px 16px 0; }
    .topbar   { padding:11px 16px; }
}
@media (max-width:640px) {
    .grid { grid-template-columns:1fr; gap:14px; }
    .stat-bar { flex-wrap:wrap; }
    .stat-item { flex:1 1 calc(50% - 1px); border-right:1px solid var(--border); border-bottom:1px solid var(--border); }
    .stat-item:nth-child(2n) { border-right:none; }
    .stat-item:nth-child(3),:nth-child(4) { border-bottom:none; }
    .topbar-center h1 { font-size:15px; }
    .search-wrap { min-width:0; flex:1; }
}

/* ── INLINE RECIPE EDITOR MODAL ── */
.recipe-modal {
    display:none; position:fixed; inset:0; z-index:9999;
    background:rgba(0,0,0,.75); backdrop-filter:blur(8px);
    justify-content:center; align-items:center; padding:20px;
}
.recipe-modal.active { display:flex; }
.recipe-modal-content {
    width:100%; max-width:560px; max-height:88vh; overflow-y:auto;
    background:var(--bg-card); border:1px solid var(--border-hover);
    border-radius:var(--radius); padding:32px; box-shadow:var(--shadow-lg);
    animation:modalPop .25s ease;
}
@keyframes modalPop { from{opacity:0;transform:scale(.95) translateY(10px);} to{opacity:1;transform:scale(1) translateY(0);} }
.recipe-modal-content h2 {
    display:flex; align-items:center; gap:10px; font-size:19px; margin-bottom:22px;
}
.recipe-modal-content h2 i { color:var(--accent); }
.recipe-modal-content h2 span { color:var(--accent); }
.rm-group { margin-bottom:18px; }
.rm-group > label {
    display:flex; align-items:center; gap:7px; font-size:13px; font-weight:600;
    color:var(--text-muted); margin-bottom:8px;
}
.rm-row { display:flex; gap:10px; margin-bottom:8px; }
.rm-row select, .rm-row input, .rm-amount-only {
    background:var(--bg-input); border:1px solid var(--border); color:var(--text);
    border-radius:9px; padding:9px 12px; font-family:'Poppins',sans-serif; font-size:13px;
    outline:none; transition:var(--transition);
}
.rm-row select:focus, .rm-row input:focus { border-color:var(--accent); }
.rm-row select { flex:2; min-width:0; }
.rm-row input[type="number"] { flex:1; min-width:0; }
.rm-row .rm-remove {
    flex:0 0 auto; width:38px; border:1px solid var(--border); background:transparent;
    color:var(--text-muted); border-radius:9px; cursor:pointer; transition:var(--transition);
}
.rm-row .rm-remove:hover { border-color:var(--danger); color:var(--danger); }
.rm-add {
    display:inline-flex; align-items:center; gap:6px; background:transparent;
    border:1px dashed var(--border-hover); color:var(--text-muted); border-radius:9px;
    padding:8px 14px; font-size:12px; font-weight:600; cursor:pointer; transition:var(--transition);
    font-family:'Poppins',sans-serif;
}
.rm-add:hover { border-color:var(--accent); color:var(--accent); }
.rm-fixed-row { display:flex; align-items:center; gap:10px; }
.rm-fixed-row .rm-fixed-label {
    flex:2; padding:9px 12px; border-radius:9px; border:1px solid var(--border);
    background:rgba(255,255,255,.02); color:var(--text-muted); font-size:13px;
}
.rm-fixed-row input {
    flex:1; min-width:0;
    background:var(--bg-input); border:1px solid var(--border); color:var(--text);
    border-radius:9px; padding:9px 12px; font-family:'Poppins',sans-serif; font-size:13px;
    outline:none; transition:var(--transition);
}
.rm-fixed-row input:focus { border-color:var(--accent); }
.rm-btn-group { display:flex; gap:12px; margin-top:26px; }
.rm-btn-group button {
    flex:1; padding:13px; border-radius:10px; border:none; font-weight:600;
    cursor:pointer; font-family:'Poppins',sans-serif; font-size:14px; transition:var(--transition);
}
.rm-save { background:var(--accent); color:#000; }
.rm-save:hover { background:var(--accent-light); transform:translateY(-1px); }
.rm-save:disabled { opacity:.6; cursor:not-allowed; transform:none; }
.rm-cancel { background:rgba(255,255,255,.05); color:var(--text); border:1px solid var(--border) !important; }
.rm-cancel:hover { border-color:var(--accent) !important; }

/* ── PAGINATION ── */
.pg-wrap { display:none; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin:20px 28px 0; padding:14px 18px; background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius); }
.pg-nav { display:flex; gap:4px; flex-wrap:wrap; }
.pg-btn { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text-muted); font-size:13px; font-weight:600; text-decoration:none; transition:var(--transition); }
.pg-btn:hover { border-color:var(--accent); color:var(--accent); }
.pg-active { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; background:var(--accent); border:1px solid var(--accent); color:#000; font-size:13px; font-weight:700; }
.pg-disabled { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; border:1px solid var(--border); color:var(--text-muted); font-size:13px; opacity:.35; cursor:default; }
.pg-ellipsis { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; color:var(--text-muted); font-size:13px; }
.pg-info { font-size:12px; color:var(--text-muted); }
@media print { .pg-wrap { display:none !important; } }
</style>
</head>
<body>

<!-- ── TOPBAR ── -->
<div class="topbar">
    <div class="topbar-left">
        <?php if (($_SESSION['role'] ?? '') === 'barista'): ?>
        <a href="view_order.php" class="btn-nav"><i class="fa-solid fa-arrow-left"></i> Orders</a>
        <?php else: ?>
        <a href="dashboard.php" class="btn-nav"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
        <?php endif; ?>
        <?php if (can('manage_recipes')): ?>
        <a href="manage_recipe.php" class="btn-nav"><i class="fa-solid fa-pen-to-square"></i> Manage</a>
        <?php endif; ?>
    </div>

    <div class="topbar-center">
        <h1>
            <i class="fa-solid fa-utensils"></i>
            Drink Recipes
            <span class="count" id="countLabel">(<?= $totalRecipes ?> recipes)</span>
        </h1>
    </div>

    <div class="topbar-right">
        <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input id="search" placeholder="Drink or ingredient…" autocomplete="off">
        </div>
        <select class="sort-select" id="sizeSelect" title="Scale recipe measurements by cup size" style="border-color:var(--accent);color:var(--accent);font-weight:600;">
            <option value="1.0" selected>Size: Regular (1.0×)</option>
            <option value="1.25">Size: Medium (1.25×)</option>
            <option value="1.5">Size: Large (1.5×)</option>
            <option value="1.75">Size: Extra Large (1.75×)</option>
            <option value="2.0">Size: Double (2.0×)</option>
        </select>
        <select class="sort-select" id="sortSelect">
            <option value="name">A → Z</option>
            <option value="name_desc">Z → A</option>
            <option value="cogs">COGS ↑</option>
            <option value="cogs_desc">COGS ↓</option>
            <option value="ings">Ingredients ↑</option>
        </select>
        <button class="btn-nav" onclick="window.open('recipes_pdf.php','_blank')" title="Export recipe cost report as PDF">
            <i class="fa-solid fa-file-pdf"></i> Export PDF
        </button>
        <button class="btn-nav" onclick="toggleTheme()" id="themeBtn">
            <i class="fa-solid fa-moon" id="themeIcon"></i>
        </button>
    </div>
</div>

<!-- ── STATS ── -->
<div class="stat-bar">
    <div class="stat-item">
        <div class="stat-icon orange"><i class="fa-solid fa-utensils"></i></div>
        <div>
            <div class="stat-label">Recipes Configured</div>
            <div class="stat-value"><?= $totalRecipes ?></div>
            <div class="stat-sub">of <?= $totalProducts ?> products</div>
        </div>
    </div>
    <div class="stat-item">
        <div class="stat-icon green"><i class="fa-solid fa-flask"></i></div>
        <div>
            <div class="stat-label">Avg Ingredients</div>
            <div class="stat-value"><?= $avgIngredients ?></div>
            <div class="stat-sub">per recipe</div>
        </div>
    </div>
    <div class="stat-item">
        <div class="stat-icon <?= $lowStockRecipes > 0 ? 'red' : 'green' ?>">
            <i class="fa-solid <?= $lowStockRecipes > 0 ? 'fa-triangle-exclamation' : 'fa-check-circle' ?>"></i>
        </div>
        <div>
            <div class="stat-label">Low-Stock Recipes</div>
            <div class="stat-value"><?= $lowStockRecipes ?></div>
            <div class="stat-sub">of <?= $totalRecipes ?> · <?= $okStockRecipes ?> fully stocked</div>
        </div>
    </div>
    <div class="stat-item">
        <div class="stat-icon <?= $totalNoRecipe > 0 ? 'yellow' : 'green' ?>">
            <i class="fa-solid <?= $totalNoRecipe > 0 ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
        </div>
        <div>
            <div class="stat-label">No Recipe</div>
            <div class="stat-value"><?= $totalNoRecipe ?></div>
            <div class="stat-sub">of <?= $totalProducts ?> products</div>
        </div>
    </div>
</div>

<!-- ── INGREDIENTS WITH NO COST ── -->
<?php if ($uncostedCount > 0): ?>
<div class="cost-gap-bar">
    <i class="fa-solid fa-circle-info"></i>
    <div>
        <strong><?= $uncostedCount ?> ingredient<?= $uncostedCount !== 1 ? 's' : '' ?></strong>
        used in these recipes ha<?= $uncostedCount !== 1 ? 've' : 's' ?> no unit cost, so the costs below are understated:
        <span class="cost-gap-names"><?= h(implode(' · ', $uncostedIngs)) ?></span>
        <?php if (can('manage_ingredients') || can('inventory')): ?>
        <a href="ingredients.php">Set costs →</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── PRODUCTS WITH NO RECIPE ── -->
<?php if (!empty($noRecipe)): ?>
<div class="no-recipe-section">
    <div class="no-recipe-header">
        <h3><i class="fa-solid fa-circle-exclamation"></i> <?= $totalNoRecipe ?> product<?= $totalNoRecipe!==1?'s':'' ?> have no recipe configured</h3>
        <?php if (can('manage_recipes')): ?>
        <a href="manage_recipe.php" class="btn-action ghost" style="font-size:11px;padding:5px 12px;">
            <i class="fa-solid fa-pen-to-square"></i> Set up recipes
        </a>
        <?php endif; ?>
    </div>
    <div class="no-recipe-pills">
        <?php foreach ($noRecipe as $nr):
            $cm = getCategoryMeta($nr['category']);
        ?>
        <?php if (can('manage_recipes')): ?>
        <button type="button" class="no-recipe-pill" onclick="openRecipeModal(<?= (int)$nr['product_id'] ?>)">
            <i class="fa-solid <?= $cm['icon'] ?>" style="color:<?= $cm['color'] ?>;font-size:11px;"></i>
            <?= h($nr['name']) ?>
        </button>
        <?php else: ?>
        <span class="no-recipe-pill" style="cursor:default;">
            <i class="fa-solid <?= $cm['icon'] ?>" style="color:<?= $cm['color'] ?>;font-size:11px;"></i>
            <?= h($nr['name']) ?>
        </span>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── CATEGORY FILTER ── -->
<div class="cat-bar" id="catBar">
    <button class="cat-pill active" data-cat="all" style="background:var(--accent);" onclick="filterCat(this,'all')">
        <i class="fa-solid fa-layer-group"></i> All
        <span class="pill-count"><?= $totalRecipes ?></span>
    </button>
    <?php foreach ($categoryCounts as $cat => $cnt):
        $cm = getCategoryMeta($cat);
    ?>
    <button class="cat-pill" data-cat="<?= h($cat) ?>"
            onclick="filterCat(this,'<?= h($cat) ?>')"
            style="--cat-color:<?= $cm['color'] ?>">
        <i class="fa-solid <?= $cm['icon'] ?>" style="color:<?= $cm['color'] ?>"></i>
        <?= h($cat) ?>
        <span class="pill-count"><?= $cnt ?></span>
    </button>
    <?php endforeach; ?>
</div>

<!-- ── RECIPE CARDS ── -->
<div class="grid" id="grid">
<?php if (empty($recipes)): ?>
    <div class="empty-grid">
        <i class="fa-solid fa-flask"></i>
        <h3>No recipes yet</h3>
        <p>Add ingredients to your products in Manage Recipe to see them here.</p>
    </div>
<?php else: ?>
    <?php foreach ($recipes as $r):
        $cm      = getCategoryMeta($r['category']);
        $margin  = $r['price'] > 0 ? (($r['price'] - $r['cogs']) / $r['price'] * 100) : 0;
        $ingList = implode(',', array_map(fn($it) => h(strtolower($it['ingredient_name'])), $r['items']));
    ?>
    <div class="card"
         data-name="<?= h(strtolower($r['product_name'])) ?>"
         data-cat="<?= h($r['category']) ?>"
         data-cogs="<?= round($r['cogs'], 4) ?>"
         data-ings="<?= count($r['items']) ?>"
         data-ingredients="<?= $ingList ?>">

        <!-- Image -->
        <div class="card-img">
            <?php if (!empty($r['image'])): ?>
            <img src="<?= h($r['image']) ?>" alt="<?= h($r['product_name']) ?>" loading="lazy">
            <?php else: ?>
            <div class="no-img"><i class="fa-solid fa-mug-hot"></i></div>
            <?php endif; ?>

            <div class="cat-overlay" style="background:<?= $cm['color'] ?>88;">
                <i class="fa-solid <?= $cm['icon'] ?>" style="font-size:10px;"></i>
                <?= h($r['category'] ?: 'No Category') ?>
            </div>

            <?php if ($r['has_low_stock']): ?>
            <div class="low-stock-badge">
                <i class="fa-solid fa-triangle-exclamation"></i> Low Stock
            </div>
            <?php endif; ?>
        </div>

        <!-- Body -->
        <div class="card-body">
            <div class="card-title"><?= h($r['product_name']) ?></div>

            <div class="card-meta">
                <span class="meta-pill orange"><i class="fa-solid fa-flask"></i> <?= count($r['items']) ?> ingredients</span>
                <?php if ($r['cogs'] > 0): ?>
                <span class="meta-pill orange cogs-pill" data-base-cogs="<?= round($r['cogs'], 4) ?>"><i class="fa-solid fa-dollar-sign"></i> Cost $<span class="cogs-val"><?= number_format($r['cogs'], 2) ?></span></span>
                <?php endif; ?>
                <?php if ($r['price'] > 0 && $margin > 0): ?>
                <span class="meta-pill green margin-pill" data-price="<?= $r['price'] ?>" data-base-cogs="<?= round($r['cogs'], 4) ?>"><i class="fa-solid fa-percent"></i> <span class="margin-val"><?= number_format($margin, 0) ?></span>% margin</span>
                <?php endif; ?>
            </div>

            <!-- Ingredients -->
            <table class="ing-table">
                <thead>
                    <tr>
                        <th>Ingredient</th>
                        <th style="text-align:right;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($r['items'] as $it): ?>
                    <tr class="ing-row<?= $it['low_stock'] ? ' ing-low-row' : '' ?>">
                        <td>
                            <div class="ing-name<?= $it['low_stock'] ? ' ing-low' : '' ?>">
                                <i class="fa-solid <?= $it['low_stock'] ? 'fa-triangle-exclamation' : 'fa-circle' ?>" style="font-size:10px;"></i>
                                <?= h($it['ingredient_name']) ?>
                            </div>
                        </td>
                        <td class="ing-amount-cell <?= $it['low_stock'] ? 'ing-low' : '' ?>" data-base-amount="<?= (float)$it['amount_used'] ?>" data-unit="<?= h($it['unit']) ?>">
                            <span class="amount-val"><?= number_format($it['amount_used'], 0) ?></span> <?= h($it['unit']) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Actions -->
        <div class="card-actions">
            <?php if (can('manage_recipes')): ?>
            <button type="button" class="btn-action orange" onclick="openRecipeModal(<?= (int)$r['product_id'] ?>)">
                <i class="fa-solid fa-pen-to-square"></i> Edit Recipe
            </button>
            <?php endif; ?>
            <button class="btn-action ghost" onclick="printCard(this)" title="Print this recipe">
                <i class="fa-solid fa-print"></i>
            </button>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="empty-grid" id="emptySearch" style="display:none;">
        <i class="fa-solid fa-magnifying-glass"></i>
        <h3>No matching recipes</h3>
        <p>Try a different drink name or ingredient.</p>
    </div>
<?php endif; ?>
</div>

<!-- ── PAGINATION ── -->
<div id="pgWrapRV" class="pg-wrap">
    <span id="pgInfoRV" class="pg-info"></span>
    <nav id="pgNavRV" class="pg-nav"></nav>
</div>

<!-- ── Inline Recipe Editor Modal ── -->
<div class="recipe-modal" id="recipeModal">
    <div class="recipe-modal-content">
        <h2><i class="fa-solid fa-pen-to-square"></i> Edit Recipe — <span id="rmProductName"></span></h2>

        <div class="rm-group">
            <label><i class="fa-solid fa-leaf"></i> Coffee / Tea Base</label>
            <div id="rmBaseBox"></div>
            <button type="button" class="rm-add" onclick="addRecipeBaseRow()">
                <i class="fa-solid fa-plus"></i> Add another base ingredient
            </button>
        </div>

        <div class="rm-group">
            <label><i class="fa-solid fa-cow"></i> Milk (ml)</label>
            <div class="rm-fixed-row">
                <span class="rm-fixed-label">Milk base</span>
                <input type="number" id="rmMilk" min="0" step="1" placeholder="Amount (ml)">
            </div>
        </div>

        <div class="rm-group">
            <label><i class="fa-solid fa-droplet"></i> Sugar Syrup (ml)</label>
            <div class="rm-fixed-row">
                <span class="rm-fixed-label">Sugar Syrup</span>
                <input type="number" id="rmSyrup" min="0" step="1" placeholder="Amount (ml)">
            </div>
        </div>

        <div class="rm-btn-group">
            <button type="button" class="rm-save" id="rmSaveBtn" onclick="saveRecipeModal()">
                <i class="fa-solid fa-floppy-disk"></i> Save Recipe
            </button>
            <button type="button" class="rm-cancel" onclick="closeRecipeModal()">
                <i class="fa-solid fa-xmark"></i> Cancel
            </button>
        </div>
    </div>
</div>

<script>
// ── Inline Recipe Editor: data from server ──
const BASE_INGREDIENTS = <?= json_encode($baseIngredients) ?>;
const RECIPE_CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;
const RECIPE_EDIT_DATA = <?= json_encode($recipeEditData) ?>;
let rmCurrentProductId = 0;

function rmBaseRowHtml(selectedId, amount) {
    let opts = '<option value="">— Choose —</option>';
    BASE_INGREDIENTS.forEach(ing => {
        opts += `<option value="${ing.id}" ${ing.id === selectedId ? 'selected' : ''}>${ing.name}</option>`;
    });
    return `<div class="rm-row">
        <select>${opts}</select>
        <input type="number" min="0" step="1" placeholder="Amount (g/ml)" value="${amount > 0 ? amount : ''}">
        <button type="button" class="rm-remove" onclick="this.parentElement.remove()" title="Remove">
            <i class="fa-solid fa-trash-can"></i>
        </button>
    </div>`;
}

function addRecipeBaseRow(selectedId, amount) {
    if (selectedId === undefined) selectedId = null;
    if (amount === undefined) amount = 0;
    document.getElementById('rmBaseBox').insertAdjacentHTML('beforeend', rmBaseRowHtml(selectedId, amount));
}

function openRecipeModal(productId) {
    const data = RECIPE_EDIT_DATA[productId];
    if (!data) return;

    rmCurrentProductId = productId;
    document.getElementById('rmProductName').textContent = data.name;

    const box = document.getElementById('rmBaseBox');
    box.innerHTML = '';
    if (data.base.length > 0) {
        data.base.forEach(b => addRecipeBaseRow(b.id, b.amount));
    } else {
        addRecipeBaseRow();
    }

    document.getElementById('rmMilk').value  = data.milk  > 0 ? data.milk  : '';
    document.getElementById('rmSyrup').value = data.syrup > 0 ? data.syrup : '';

    document.getElementById('recipeModal').classList.add('active');
}

function closeRecipeModal() {
    document.getElementById('recipeModal').classList.remove('active');
    rmCurrentProductId = 0;
}

async function saveRecipeModal() {
    if (!rmCurrentProductId) return;

    const btn = document.getElementById('rmSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const formData = new FormData();
    formData.append('save_recipe', '1');
    formData.append('ajax', '1');
    formData.append('csrf_token', RECIPE_CSRF);
    formData.append('product_id', rmCurrentProductId);
    formData.append('milk_amount', document.getElementById('rmMilk').value || '');
    formData.append('syrup_amount', document.getElementById('rmSyrup').value || '');
    document.querySelectorAll('#rmBaseBox .rm-row').forEach(row => {
        const sel = row.querySelector('select');
        const amt = row.querySelector('input');
        formData.append('base_ingredient[]', sel.value);
        formData.append('base_amount[]', amt.value);
    });

    try {
        const resp = await fetch('manage_recipe.php', { method: 'POST', body: formData });
        const data = await resp.json();
        if (data.ok) {
            showRecipeToast('Recipe saved successfully.', 'success');
            closeRecipeModal();
            setTimeout(() => location.reload(), 800);
        } else {
            showRecipeToast(data.error || 'Failed to save recipe.', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Recipe';
        }
    } catch (e) {
        showRecipeToast('Connection error.', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Recipe';
    }
}

function showRecipeToast(msg, type) {
    if (type === undefined) type = 'success';
    const el = document.createElement('div');
    el.textContent = msg;
    el.style.cssText = 'position:fixed;bottom:28px;left:50%;transform:translateX(-50%);' +
        'background:' + (type === 'success' ? 'rgba(85,224,135,.15)' : 'rgba(255,95,95,.15)') + ';' +
        'color:' + (type === 'success' ? '#55e087' : '#ff5f5f') + ';' +
        'border:1px solid ' + (type === 'success' ? 'rgba(85,224,135,.35)' : 'rgba(255,95,95,.35)') + ';' +
        'padding:12px 22px;border-radius:12px;font-family:\'Poppins\',sans-serif;font-size:13px;' +
        'font-weight:600;z-index:10000;backdrop-filter:blur(12px);box-shadow:0 8px 30px rgba(0,0,0,.4);';
    document.body.appendChild(el);
    setTimeout(function(){ el.remove(); }, 2600);
}

document.getElementById('recipeModal').addEventListener('click', function(e){
    if (e.target.id === 'recipeModal') closeRecipeModal();
});
</script>

<script>
// ── Data ──
const recipesData = <?= json_encode(array_values($recipesJson)) ?>;

// ── DOM ──
const grid        = document.getElementById('grid');
const searchInput = document.getElementById('search');
const sortSelect  = document.getElementById('sortSelect');
const countLabel  = document.getElementById('countLabel');
const emptySearch = document.getElementById('emptySearch');

// ── State ──
let currentCat  = 'all';
let currentQ    = '';
let currentSort = 'name';

// ── Category filter ──
function filterCat(btn, cat) {
    currentCat = cat;
    document.querySelectorAll('.cat-pill').forEach(p => {
        const isActive = p.dataset.cat === cat;
        p.classList.toggle('active', isActive);
        p.style.background = isActive ? (cat === 'all' ? 'var(--accent)' : p.style.getPropertyValue('--cat-color') || 'var(--accent)') : '';
        p.style.color = isActive ? '#000' : '';
    });
    currentPageRV = 1;
    applyFilters();
}

// ── Search ──
searchInput.addEventListener('input', function() {
    currentQ = this.value.toLowerCase().trim();
    currentPageRV = 1;
    applyFilters();
});

// ── Sort ──
sortSelect.addEventListener('change', function() {
    currentSort = this.value;
    applySortToDOM();
    currentPageRV = 1;
    applyFilters();
});

// ── Pagination state ──
const PER_PAGE_RV = 12;
let currentPageRV = 1;

// ── Apply filters (search + category) + paginate ──
function applyFilters() {
    const cards = [...grid.querySelectorAll('.card:not(#emptySearch)')];

    const matched = cards.filter(card => {
        const name = card.dataset.name || '';
        const cat  = card.dataset.cat  || '';
        const ings = card.dataset.ingredients || '';
        const catMatch  = currentCat === 'all' || cat === currentCat;
        const textMatch = !currentQ || name.includes(currentQ) || ings.includes(currentQ);
        return catMatch && textMatch;
    });

    const total      = matched.length;
    const totalPages = Math.max(1, Math.ceil(total / PER_PAGE_RV));
    if (currentPageRV > totalPages) currentPageRV = totalPages;
    const start = (currentPageRV - 1) * PER_PAGE_RV;
    const end   = start + PER_PAGE_RV;

    // hide everything, then reveal only the matched cards on this page
    cards.forEach(card => card.classList.add('hidden'));
    matched.slice(start, end).forEach(card => card.classList.remove('hidden'));

    countLabel.textContent = `(${total} recipe${total !== 1 ? 's' : ''})`;
    if (emptySearch) emptySearch.style.display = total === 0 ? 'block' : 'none';

    renderPagerRV(total, totalPages);
}

function renderPagerRV(total, totalPages) {
    const wrap = document.getElementById('pgWrapRV');
    if (!wrap) return;
    if (totalPages <= 1) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'flex';
    document.getElementById('pgInfoRV').textContent =
        `Page ${currentPageRV} of ${totalPages} · ${total} drink${total !== 1 ? 's' : ''}`;
    const nav = document.getElementById('pgNavRV');
    let html = currentPageRV > 1
        ? `<a href="#" class="pg-btn" onclick="goPageRV(1);return false;">«</a><a href="#" class="pg-btn" onclick="goPageRV(${currentPageRV - 1});return false;">‹</a>`
        : `<span class="pg-disabled">«</span><span class="pg-disabled">‹</span>`;
    const ws = Math.max(1, currentPageRV - 2);
    const we = Math.min(totalPages, currentPageRV + 2);
    if (ws > 1) html += `<span class="pg-ellipsis">…</span>`;
    for (let i = ws; i <= we; i++) {
        html += i === currentPageRV
            ? `<span class="pg-active">${i}</span>`
            : `<a href="#" class="pg-btn" onclick="goPageRV(${i});return false;">${i}</a>`;
    }
    if (we < totalPages) html += `<span class="pg-ellipsis">…</span>`;
    html += currentPageRV < totalPages
        ? `<a href="#" class="pg-btn" onclick="goPageRV(${currentPageRV + 1});return false;">›</a><a href="#" class="pg-btn" onclick="goPageRV(${totalPages});return false;">»</a>`
        : `<span class="pg-disabled">›</span><span class="pg-disabled">»</span>`;
    nav.innerHTML = html;
}

function goPageRV(p) {
    currentPageRV = p;
    applyFilters();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── Sort DOM cards ──
function applySortToDOM() {
    const cards = [...grid.querySelectorAll('.card:not(#emptySearch)')];
    cards.sort((a, b) => {
        switch (currentSort) {
            case 'name':      return a.dataset.name.localeCompare(b.dataset.name);
            case 'name_desc': return b.dataset.name.localeCompare(a.dataset.name);
            case 'cogs':      return parseFloat(a.dataset.cogs) - parseFloat(b.dataset.cogs);
            case 'cogs_desc': return parseFloat(b.dataset.cogs) - parseFloat(a.dataset.cogs);
            case 'ings':      return parseInt(a.dataset.ings)   - parseInt(b.dataset.ings);
            default:          return 0;
        }
    });
    cards.forEach(c => grid.insertBefore(c, emptySearch));
}

// ── Print single recipe card ──
function printCard(btn) {
    const card = btn.closest('.card');
    const name = card.querySelector('.card-title').textContent.trim();
    const rows = [...card.querySelectorAll('.ing-row')].map(tr => {
        const cells = tr.querySelectorAll('td');
        return `<tr><td>${cells[0]?.textContent?.trim()}</td><td style="text-align:right">${cells[1]?.textContent?.trim()}</td></tr>`;
    }).join('');

    const win = window.open('', '_blank', 'width=400,height=500');
    win.document.write(`<!DOCTYPE html><html><head>
        <title>${name} Recipe</title>
        <style>
            body{font-family:'Courier New',monospace;padding:24px;font-size:13px;color:#000}
            h2{font-size:18px;margin-bottom:4px}
            .cat{font-size:11px;color:#666;margin-bottom:16px}
            table{width:100%;border-collapse:collapse;margin-top:8px}
            td{padding:6px 4px;border-bottom:1px solid #eee}
            td:last-child{text-align:right;font-weight:bold}
            .footer{margin-top:20px;font-size:10px;color:#999;text-align:center}
        </style></head><body>
        <h2>${name}</h2>
        <div class="cat">Bird's Nest Coffee — Recipe Card</div>
        <table><tbody>${rows}</tbody></table>
        <div class="footer">Printed ${new Date().toLocaleDateString()}</div>
        <script>window.onload=()=>{window.print();window.close()}<\/script>
    </body></html>`);
    win.document.close();
}

// ── Theme ──
function toggleTheme() {
    const html  = document.documentElement;
    const icon  = document.getElementById('themeIcon');
    const isLight = html.getAttribute('data-theme') === 'light';
    if (isLight) {
        html.removeAttribute('data-theme');
        icon.className = 'fa-solid fa-moon';
        localStorage.setItem('theme', 'dark');
    } else {
        html.setAttribute('data-theme', 'light');
        icon.className = 'fa-solid fa-sun';
        localStorage.setItem('theme', 'light');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    if (localStorage.getItem('theme') === 'light') {
        document.getElementById('themeIcon').className = 'fa-solid fa-sun';
    }
});

// ── Dynamic Cup Size Scaling ──
const sizeSelect = document.getElementById('sizeSelect');
if (sizeSelect) {
    sizeSelect.addEventListener('change', function() {
        const factor = parseFloat(this.value) || 1.0;
        scaleRecipeSizes(factor);
    });
}

function scaleRecipeSizes(factor) {
    document.querySelectorAll('.card:not(#emptySearch)').forEach(card => {
        // 1. Scale ingredient amounts
        card.querySelectorAll('.ing-amount-cell').forEach(cell => {
            const baseAmount = parseFloat(cell.dataset.baseAmount) || 0;
            const unit = cell.dataset.unit || '';
            const scaled = baseAmount * factor;
            const formatted = (scaled % 1 === 0) ? scaled : scaled.toFixed(1);
            const valSpan = cell.querySelector('.amount-val');
            if (valSpan) valSpan.textContent = formatted;
        });

        // 2. Scale COGS
        const cogsPill = card.querySelector('.cogs-pill');
        if (cogsPill) {
            const baseCogs = parseFloat(cogsPill.dataset.baseCogs) || 0;
            const scaledCogs = baseCogs * factor;
            const cogsVal = cogsPill.querySelector('.cogs-val');
            if (cogsVal) cogsVal.textContent = scaledCogs.toFixed(2);
            card.dataset.cogs = scaledCogs;
        }

        // 3. Scale Margin
        const marginPill = card.querySelector('.margin-pill');
        if (marginPill) {
            const price = parseFloat(marginPill.dataset.price) || 0;
            const baseCogs = parseFloat(marginPill.dataset.baseCogs) || 0;
            const scaledCogs = baseCogs * factor;
            const margin = price > 0 ? (((price - scaledCogs) / price) * 100) : 0;
            const marginVal = marginPill.querySelector('.margin-val');
            if (marginVal) marginVal.textContent = Math.max(0, Math.round(margin));
        }
    });
}

// initial pagination render (paginate the full set on first load)
applyFilters();
</script>
<script src="animations.js"></script>
</body>
</html>
