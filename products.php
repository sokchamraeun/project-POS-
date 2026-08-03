<?php
require 'auth.php';
require 'config.php';
if (!can('products')) { header("Location: dashboard.php?denied=1"); exit; }
$_can_manage_products = in_array($_SESSION['role'] ?? '', ['admin', 'manager']);
$_flash_welcome = !empty($_SESSION['flash_welcome']); unset($_SESSION['flash_welcome']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

// Bulk action: enable sizes for every product in a category + seed default S/M/L rows
if (($_POST['action'] ?? '') === 'bulk_enable_sizes' && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $cat = trim((string)($_POST['category'] ?? ''));
    if ($cat !== '') {
        $catEsc = $conn->real_escape_string($cat);
        $conn->query("UPDATE products SET has_sizes=1 WHERE category='$catEsc'");

        // seed default rows for products in that category that have none yet
        $seed = $conn->query("SELECT p.product_id, p.price FROM products p
            LEFT JOIN product_sizes ps ON ps.product_id=p.product_id
            WHERE p.category='$catEsc' GROUP BY p.product_id, p.price HAVING COUNT(ps.size_id)=0");

        // one single-row prepared insert, executed three times per product (S/M/L)
        $ins = $conn->prepare("INSERT INTO product_sizes
            (product_id,size_code,label,price,size_factor,sort_order) VALUES (?,?,?,?,?,?)");
        while ($p = $seed->fetch_assoc()) {
            $pid  = (int)$p['product_id'];
            $base = (float)$p['price'];
            $defaults = [
                ['S','Small',  round($base*0.8,2), 0.80, 0],
                ['M','Medium', $base,              1.00, 1],
                ['L','Large',  round($base*1.2,2), 1.30, 2],
            ];
            foreach ($defaults as $d) {
                $ins->bind_param("issddi", $pid, $d[0], $d[1], $d[2], $d[3], $d[4]);
                $ins->execute();
            }
        }
    }
    header('Location: products.php'); exit;
}

// products flagged sized but with zero size rows
$missing = [];
$mr = $conn->query("SELECT p.product_id FROM products p
    LEFT JOIN product_sizes ps ON ps.product_id=p.product_id
    WHERE p.has_sizes=1 GROUP BY p.product_id HAVING COUNT(ps.size_id)=0");
while ($row = $mr->fetch_assoc()) { $missing[(int)$row['product_id']] = true; }

// Quick-view AJAX endpoint
if (($_GET['action'] ?? '') === 'view') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['ok' => false]); exit; }
    $s = $conn->prepare("SELECT * FROM products WHERE product_id = ?");
    $s->bind_param('i', $id);
    $s->execute();
    $p = $s->get_result()->fetch_assoc();
    if (!$p) { echo json_encode(['ok' => false]); exit; }
    $img = $p['image'] ?? '';
    if ($img && strpos($img, 'uploads/') !== false) $src = $img;
    elseif ($img) $src = 'uploads/' . $img;
    else $src = '';
    echo json_encode(['ok' => true, 'product' => [
        'id'           => (int)$p['product_id'],
        'name'         => $p['name'],
        'description'  => $p['description'] ?? '',
        'price'        => (float)$p['price'],
        'category'     => $p['category'] ?? '',
        'image'        => $src,
        'is_available' => (int)($p['is_available'] ?? 1),
        'badge_text'   => $p['badge_text'] ?? '',
    ]]);
    exit;
}

$result = $conn->query("SELECT * FROM products ORDER BY product_id DESC");
$products = [];
while ($row = $result->fetch_assoc()) { $products[] = $row; }
$totalProducts = count($products);
$availCount    = count(array_filter($products, fn($p) => ($p['is_available'] ?? 1) == 1));
$unavailCount  = $totalProducts - $availCount;

$catCounts = [];
$realCategories = [];
foreach ($products as $p) {
    $cat = $p['category'] ?: 'Uncategorized';
    $catCounts[$cat] = ($catCounts[$cat] ?? 0) + 1;
    if ($p['category']) { $realCategories[$p['category']] = true; }
}
arsort($catCounts);
$top   = array_key_first($catCounts) ?? '';
$realCategories = array_keys($realCategories);
sort($realCategories);

// Category filter chips are sourced from the categories table so that a newly created
// but still-empty category still appears. $catCounts (slug => product count) is already
// built above from the product rows.
$filterCats = [];
$activeCatRes = $conn->query("SELECT slug FROM categories WHERE is_active = 1 ORDER BY display_order, category_id");
while ($cr = $activeCatRes->fetch_assoc()) {
    $slug = $cr['slug'];
    $filterCats[] = ['slug' => $slug, 'count' => $catCounts[$slug] ?? 0];
}
// Only truly empty-category products bucket into the "Uncategorized" chip. Products whose
// slug belongs to an inactive (or orphan) category are intentionally NOT counted here:
// their cards keep their real slug in data-category, so a "Uncategorized" filter click would
// not surface them — counting them would make the chip's number disagree with what it shows.
// They remain reachable via the "All" chip. Keeps count == click for every chip.
$uncat = $catCounts['Uncategorized'] ?? 0;
if ($uncat > 0) $filterCats[] = ['slug' => 'Uncategorized', 'count' => $uncat];

$availPct = $totalProducts > 0 ? round($availCount / $totalProducts * 100) : 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Products | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script>(function(){try{if(localStorage.getItem("theme")==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>

<style>
/* ── RESET & ROOT ── */
:root {
    --bg: #0b0b0b;
    --bg-card: #121212;
    --bg-card-hover: #181818;
    --border: #1f1f1f;
    --border-hover: #2a2a2a;
    --accent: #d1904b;
    --accent-light: #e8b87a;
    --accent-dark: #a0702a;
    --text: #f5f5f5;
    --text-muted: #888888;
    --text-light: #ffffff;
    --danger: #ff6b6b;
    --success: #55e087;
    --info: #5bc0de;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.3);
    --shadow-md: 0 4px 20px rgba(0,0,0,0.4);
    --shadow-lg: 0 8px 40px rgba(0,0,0,0.5);
    --shadow-accent: 0 4px 20px rgba(209,144,75,0.15);
    --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
    --radius: 16px;
}

[data-theme="light"] {
    --bg: #F0F2F5;
    --bg-card: #FFFFFF;
    --bg-card-hover: #F5F7FA;
    --border: #E5E7EB;
    --border-hover: #D1D5DB;
    --text: #111827;
    --text-muted: #6B7280;
    --text-light: #ffffff;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.06);
    --shadow-md: 0 4px 20px rgba(0,0,0,0.08);
    --shadow-lg: 0 8px 40px rgba(0,0,0,0.12);
}

* { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }

body {
    font-family: 'Poppins', sans-serif;
    background: var(--bg);
    color: var(--text);
    line-height: 1.6;
    min-height: 100vh;
    transition: background 0.3s, color 0.3s;
}

::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 10px; }
::-webkit-scrollbar-thumb:hover { background: var(--accent-dark); }

/* ========== NAVBAR ========== */
.navbar {
    position: sticky;
    top: 0;
    z-index: 200;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 12px 24px;
    background: rgba(11,11,11,0.88);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-bottom: 1px solid var(--border);
    flex-wrap: wrap;
}

[data-theme="light"] .navbar { background: rgba(255,255,255,0.95); }

.navbar-left  { display: flex; align-items: center; gap: 14px; }
.navbar-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

.back-link {
    color: #d1904b;
    text-decoration: none;
    font-weight: 600;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 7px 14px;
    border-radius: 10px;
    background: rgba(209,144,75,.08);
    border: 1px solid rgba(209,144,75,.35);
    transition: var(--transition);
    white-space: nowrap;
}
.back-link:hover { background: var(--accent); color: #000; border-color: var(--accent); transform: translateX(-3px); }

.navbar-title {
    font-size: 17px;
    font-weight: 700;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 10px;
}
.navbar-title i { color: var(--accent); }
.navbar-title .count-badge {
    font-size: 11px;
    font-weight: 600;
    background: rgba(209,144,75,0.15);
    color: var(--accent);
    border: 1px solid rgba(209,144,75,0.25);
    padding: 2px 9px;
    border-radius: 50px;
}

/* ── Search ── */
.search-box {
    display: flex;
    align-items: center;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 7px 12px;
    transition: var(--transition);
    min-width: 180px;
}
.search-box:focus-within { border-color: var(--accent); box-shadow: var(--shadow-accent); }
.search-box i { color: var(--text-muted); font-size: 12px; margin-right: 8px; }
.search-box input {
    background: transparent;
    border: none;
    color: var(--text);
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
    outline: none;
    width: 140px;
}
.search-box input::placeholder { color: var(--text-muted); }
.search-clear {
    background: none; border: none; color: var(--text-muted);
    cursor: pointer; font-size: 11px; padding: 0 0 0 6px;
    display: none; transition: var(--transition);
}
.search-clear:hover { color: var(--danger); }
.search-clear.visible { display: block; }

/* ── Sort ── */
.sort-select {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 7px 10px;
    color: var(--text);
    font-family: 'Poppins', sans-serif;
    font-size: 12px;
    outline: none;
    cursor: pointer;
    transition: var(--transition);
}
.sort-select:focus { border-color: var(--accent); box-shadow: var(--shadow-accent); }

/* ── View Toggle ── */
.view-toggle {
    display: flex;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 10px;
    overflow: hidden;
}
.view-btn {
    padding: 7px 11px;
    background: transparent;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    font-size: 13px;
    transition: var(--transition);
}
.view-btn.active { background: var(--accent); color: #000; }
.view-btn:hover:not(.active) { color: var(--text); }

/* ── Icon Buttons ── */
.btn-icon {
    padding: 7px 11px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--text-muted);
    font-size: 13px;
    cursor: pointer;
    transition: var(--transition);
    font-family: 'Poppins', sans-serif;
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}
.btn-icon:hover { border-color: var(--accent); color: var(--accent); }
.btn-icon.active {
    background: rgba(209,144,75,0.15);
    border-color: var(--accent);
    color: var(--accent);
}
.btn-icon.select-active {
    background: rgba(209,144,75,0.15);
    border-color: var(--accent);
    color: var(--accent);
}

/* ── Add Button ── */
.btn-add {
    background: var(--accent);
    color: #000;
    padding: 8px 16px;
    text-decoration: none;
    border-radius: 10px;
    font-weight: 600;
    font-size: 13px;
    transition: var(--transition);
    display: flex;
    align-items: center;
    gap: 7px;
    border: none;
    cursor: pointer;
    white-space: nowrap;
}
.btn-add:hover { background: var(--accent-light); transform: translateY(-2px); box-shadow: var(--shadow-accent); }

/* ── Theme Toggle ── */
.theme-toggle {
    display: flex;
    align-items: center;
    gap: 6px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 50px;
    padding: 7px 12px;
    cursor: pointer;
    transition: var(--transition);
    color: var(--text);
    font-size: 12px;
    font-family: 'Poppins', sans-serif;
    white-space: nowrap;
}
.theme-toggle:hover { border-color: var(--accent); box-shadow: var(--shadow-accent); }
.theme-toggle i { font-size: 14px; color: var(--accent); }

/* ========== CONTAINER ========== */
.container {
    max-width: 1320px;
    margin: 0 auto;
    padding: 24px 20px 100px;
}

/* ========== STATS BAR ========== */
.stats-bar {
    display: flex;
    flex-wrap: nowrap;
    align-items: stretch;
    gap: 12px;
    margin-bottom: 20px;
    overflow-x: auto;
    scrollbar-width: none;
}
.stats-bar::-webkit-scrollbar { display: none; }

.stat-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 18px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 14px;
    font-size: 13px;
    transition: var(--transition);
    flex: 1 1 0;
    min-width: 140px;
}
.stat-card:hover { border-color: var(--border-hover); }

.stat-card .stat-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    flex-shrink: 0;
}
.stat-card.total .stat-icon    { background: rgba(209,144,75,0.12); color: var(--accent); }
.stat-card.avail .stat-icon    { background: rgba(85,224,135,0.12); color: var(--success); }
.stat-card.unavail .stat-icon  { background: rgba(255,107,107,0.12); color: var(--danger); }
.stat-card.top-cat .stat-icon  { background: rgba(209,144,75,0.12); color: var(--accent); }

.stat-card .stat-body { flex: 1; min-width: 0; }
.stat-card .stat-label {
    font-size: 10px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.6px;
    font-weight: 500;
    white-space: nowrap;
}
.stat-card .stat-value {
    font-size: 20px;
    font-weight: 700;
    color: var(--text);
    line-height: 1.2;
}
.stat-card.avail .stat-value    { color: var(--success); }
.stat-card.unavail .stat-value  { color: var(--danger); }
.stat-card .stat-sub {
    font-size: 11px;
    color: var(--text-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* availability progress bar */
.avail-progress-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 4px;
}
.avail-bar {
    flex: 1;
    height: 4px;
    background: rgba(255,107,107,0.25);
    border-radius: 10px;
    overflow: hidden;
}
.avail-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--success), #3ecf70);
    border-radius: 10px;
    transition: width 0.6s ease;
}
.avail-pct { font-size: 10px; color: var(--text-muted); flex-shrink: 0; }

/* ========== FILTER SECTION ========== */
.filter-section {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 14px 16px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.filter-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.filter-row-label {
    font-size: 11px;
    color: var(--text-muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    min-width: 58px;
    flex-shrink: 0;
}

.filter-tab {
    padding: 5px 14px;
    border-radius: 50px;
    border: 1px solid var(--border);
    background: transparent;
    color: var(--text-muted);
    font-family: 'Poppins', sans-serif;
    font-size: 12px;
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
}
.filter-tab:hover { border-color: var(--accent); color: var(--text); }
.filter-tab.active { background: var(--accent); color: #000; border-color: var(--accent); font-weight: 600; }
.filter-tab .tab-count {
    background: rgba(0,0,0,0.15);
    border-radius: 50px;
    padding: 1px 6px;
    font-size: 9px;
    font-weight: 700;
}
.filter-tab.active .tab-count { background: rgba(0,0,0,0.2); }

/* price filter buttons share same style */
.price-tab { /* extends .filter-tab */ }
.price-tab.active { background: var(--info); color: #000; border-color: var(--info); }

/* bulk enable-sizes row */
.bulk-sizes-select {
    padding: 5px 12px;
    border-radius: 50px;
    border: 1px solid var(--border);
    background: var(--bg-card);
    color: var(--text);
    font-family: 'Poppins', sans-serif;
    font-size: 12px;
    cursor: pointer;
}
.bulk-sizes-submit { cursor: pointer; }
.bulk-sizes-submit:hover { background: var(--accent); color: #000; border-color: var(--accent); }

/* ========== RESULTS ROW ========== */
.results-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
    flex-wrap: wrap;
    gap: 8px;
}
.results-info {
    font-size: 13px;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 6px;
}
.results-info strong { color: var(--text); }
.results-hint {
    font-size: 11px;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 6px;
}
.kbd {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 1px 7px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 5px;
    font-size: 10px;
    font-family: monospace;
    color: var(--text-muted);
}

/* ========== PRODUCT GRID ========== */
.product-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 18px;
}

/* ── List View ── */
.product-grid.list-view { grid-template-columns: 1fr; gap: 8px; }
.product-grid.list-view .product-card { display: flex; flex-direction: row; align-items: center; }
.product-grid.list-view .product-card::before { width: 4px; height: auto; align-self: stretch; border-radius: 0; }
.product-grid.list-view .product-card .image-wrapper { width: 90px; height: 72px; flex-shrink: 0; border-radius: 0; }
.product-grid.list-view .product-card .content {
    flex: 1; display: flex; align-items: center; gap: 14px;
    padding: 12px 16px; flex-wrap: wrap;
}
.product-grid.list-view .product-card .content h3 { flex: 1; min-width: 120px; margin-bottom: 0; }
.product-grid.list-view .product-card .content .price { margin-top: 0; min-width: 60px; font-size: 16px; }
.product-grid.list-view .product-card .content .actions { margin-top: 0; }
.product-grid.list-view .product-card .content .product-id { display: none; }
.product-grid.list-view .product-card:hover { transform: translateX(4px) translateY(0); }
.product-grid.list-view .product-card .image-wrapper .overlay { display: none; }

/* ========== PRODUCT CARD ========== */
.product-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    transition: var(--transition);
    position: relative;
    animation: cardIn 0.4s ease both;
}

/* availability indicator line at top */
.product-card::before {
    content: '';
    display: block;
    height: 3px;
    background: linear-gradient(90deg, var(--success), #3ecf70);
    transition: background 0.3s;
}
.product-card.unavailable::before {
    background: linear-gradient(90deg, var(--danger), #ff8a8a);
}

@keyframes cardIn {
    from { opacity: 0; transform: translateY(16px); }
    to   { opacity: 1; transform: translateY(0); }
}

.product-card:hover {
    transform: translateY(-6px);
    border-color: rgba(209,144,75,0.35);
    box-shadow: var(--shadow-lg), 0 0 0 1px rgba(209,144,75,0.1);
}

/* Selected state */
.product-card.selected {
    border-color: var(--accent) !important;
    box-shadow: 0 0 0 2px rgba(209,144,75,0.3), var(--shadow-md) !important;
}

/* Select mode overlay — captures clicks for selection */
.card-select-overlay {
    position: absolute;
    inset: 0;
    z-index: 50;
    display: none;
    cursor: pointer;
}
body.select-mode .card-select-overlay { display: block; }

/* Checkbox indicator */
.card-checkbox {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    border: 2px solid rgba(255,255,255,0.4);
    background: rgba(0,0,0,0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    color: transparent;
    transition: var(--transition);
    opacity: 0;
    pointer-events: none;
    z-index: 60;
    backdrop-filter: blur(4px);
}
body.select-mode .card-checkbox { opacity: 1; }
.product-card.selected .card-checkbox {
    background: var(--accent);
    border-color: var(--accent);
    color: #000;
}

/* ── Image ── */
.product-card .image-wrapper {
    position: relative;
    width: 100%;
    height: 185px;
    overflow: hidden;
    background: #151515;
}
[data-theme="light"] .product-card .image-wrapper { background: #e8e0d5; }
.product-card .image-wrapper img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.5s cubic-bezier(0.4,0,0.2,1);
}
.product-card:hover .image-wrapper img { transform: scale(1.07); }

/* ── Overlay ── */
.product-card .image-wrapper .overlay {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,0.55);
    opacity: 0;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.product-card:hover .image-wrapper .overlay { opacity: 1; }
body.select-mode .product-card .image-wrapper .overlay { display: none; }

.overlay-btn {
    padding: 7px 14px;
    border-radius: 50px;
    font-weight: 600;
    font-size: 11px;
    text-decoration: none;
    transition: var(--transition);
    display: flex;
    align-items: center;
    gap: 5px;
    border: none;
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
}
.overlay-btn.edit-btn   { background: var(--accent); color: #000; }
.overlay-btn.edit-btn:hover { background: var(--accent-light); transform: scale(1.05); }
.overlay-btn.dup-btn    { background: rgba(91,192,222,0.2); color: var(--info); border: 1px solid rgba(91,192,222,0.4); }
.overlay-btn.dup-btn:hover  { background: var(--info); color: #000; }
.overlay-btn.delete-btn { background: rgba(255,107,107,0.15); color: var(--danger); border: 1px solid rgba(255,107,107,0.3); }
.overlay-btn.delete-btn:hover { background: var(--danger); color: #fff; }

/* ── Content ── */
.product-card .content { padding: 14px 16px 16px; }

.product-card .content .top-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 6px;
}
.product-card .content .category-badge {
    display: inline-block;
    padding: 3px 9px;
    border-radius: 50px;
    background: rgba(209,144,75,0.1);
    color: var(--accent);
    border: 1px solid rgba(209,144,75,0.2);
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    transition: var(--transition);
}
.product-card:hover .content .category-badge { background: var(--accent); color: #000; border-color: var(--accent); }

.product-card .content .product-id { color: var(--text-muted); font-size: 10px; }

.product-card .content h3 {
    color: var(--text);
    font-size: 15px;
    font-weight: 600;
    margin-bottom: 2px;
    transition: var(--transition);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.product-card:hover .content h3 { color: var(--accent); }

/* Inline price edit */
.price {
    color: var(--accent);
    font-size: 21px;
    font-weight: 700;
    display: inline-block;
    margin-top: 8px;
    letter-spacing: -0.5px;
    cursor: default;
    border-radius: 6px;
    padding: 1px 4px;
    margin-left: -4px;
    transition: background 0.2s;
}
.price:hover { background: rgba(209,144,75,0.1); }
.price.editable-hint::after {
    content: ' ✎';
    font-size: 12px;
    opacity: 0.5;
    vertical-align: middle;
}
.price-input-inline {
    color: var(--accent);
    font-size: 21px;
    font-weight: 700;
    font-family: 'Poppins', sans-serif;
    background: rgba(209,144,75,0.08);
    border: 2px solid var(--accent);
    border-radius: 8px;
    padding: 2px 8px;
    outline: none;
    width: 110px;
    margin-top: 8px;
    display: inline-block;
}

/* ── Badges (size status etc.) ── */
.badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
    margin-left: 6px;
}
.badge-danger { background: rgba(255,107,107,0.1); color: var(--danger); border: 1px solid rgba(255,107,107,0.25); }

/* ── Actions ── */
.product-card .content .actions {
    display: flex;
    gap: 6px;
    margin-top: 10px;
}
.product-card .content .actions .btn-action {
    flex: 1;
    padding: 6px 8px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: transparent;
    color: var(--text-muted);
    text-decoration: none;
    font-size: 11px;
    font-weight: 500;
    text-align: center;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
}
.btn-action.edit:hover     { border-color: var(--success); color: var(--success); background: rgba(85,224,135,0.08); }
.btn-action.delete:hover   { border-color: var(--danger); color: var(--danger); background: rgba(255,107,107,0.08); }
.btn-action.duplicate:hover { border-color: var(--info); color: var(--info); background: rgba(91,192,222,0.08); }
.btn-action.avail-on  { border-color: var(--success); color: var(--success); }
.btn-action.avail-off { border-color: var(--danger);  color: var(--danger); }
.btn-action.avail-on:hover  { background: rgba(85,224,135,0.08); }
.btn-action.avail-off:hover { background: rgba(255,107,107,0.08); }

/* ── Product badge starburst on image ── */
.product-badge {
    position: absolute;
    bottom: 8px;
    left: 8px;
    z-index: 10;
    display: flex;
    width: 76px;
    height: 76px;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 13px;
    clip-path: polygon(
        50% 0%, 59.6% 14.3%, 75% 6.7%, 76.2% 23.8%, 93.3% 25%, 85.7% 40.4%,
        100% 50%, 85.7% 59.6%, 93.3% 75%, 76.2% 76.2%, 75% 93.3%, 59.6% 85.7%,
        50% 100%, 40.4% 85.7%, 25% 93.3%, 23.8% 76.2%, 6.7% 75%, 14.3% 59.6%,
        0% 50%, 14.3% 40.4%, 6.7% 25%, 23.8% 23.8%, 25% 6.7%, 40.4% 14.3%
    );
    font-size: 11px;
    font-weight: 800;
    line-height: 1.3;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    background: linear-gradient(135deg, #a81e1e 0%, #e74c3c 50%, #a81e1e 100%);
    color: #fff;
    text-shadow: 0 1px 3px rgba(0,0,0,0.45);
    filter: drop-shadow(0 2px 6px rgba(231,76,60,0.7));
    word-break: break-word;
    pointer-events: none;
}

/* Sold out card dim */
.product-card.unavailable .image-wrapper img { filter: grayscale(0.6) opacity(0.55); }

.sold-out-badge {
    position: absolute;
    top: 10px;
    left: 10px;
    background: rgba(255,107,107,0.9);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 50px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    pointer-events: none;
}

/* ========== BULK ACTION BAR ========== */
.bulk-bar {
    position: fixed;
    bottom: -80px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 300;
    background: var(--bg-card);
    border: 1px solid var(--border-hover);
    border-radius: 16px;
    padding: 12px 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    box-shadow: var(--shadow-lg);
    transition: bottom 0.35s cubic-bezier(0.4,0,0.2,1);
    flex-wrap: wrap;
    max-width: 600px;
    width: calc(100% - 32px);
    backdrop-filter: blur(16px);
}
.bulk-bar.visible { bottom: 24px; }

.bulk-count {
    font-size: 13px;
    font-weight: 600;
    color: var(--accent);
    white-space: nowrap;
    flex-shrink: 0;
}
.bulk-divider { width: 1px; height: 20px; background: var(--border); flex-shrink: 0; }
.bulk-actions { display: flex; gap: 6px; flex: 1; flex-wrap: wrap; }

.bulk-btn {
    padding: 7px 14px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: transparent;
    color: var(--text-muted);
    font-family: 'Poppins', sans-serif;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}
.bulk-btn:hover       { border-color: var(--accent); color: var(--accent); }
.bulk-btn.all         { border-color: rgba(209,144,75,0.3); }
.bulk-btn.toggle-avail:hover { border-color: var(--success); color: var(--success); }
/* Amber on hover, not red: clearing a promo is a pricing decision, not a
   deletion, and it must not read as dangerous as Delete sitting beside it. */
.bulk-btn.clear-promo:hover { border-color: var(--warning, #e0a955); color: var(--warning, #e0a955); }
.bulk-btn.bulk-delete-btn:hover { border-color: var(--danger); color: var(--danger); }
.bulk-btn.cancel-bulk { color: var(--text-muted); }
.bulk-btn.cancel-bulk:hover { border-color: var(--border-hover); color: var(--text); }

/* ========== EMPTY STATE ========== */
.empty-state {
    text-align: center;
    padding: 80px 20px;
    color: var(--text-muted);
    grid-column: 1 / -1;
}
.empty-state .empty-icon { font-size: 50px; margin-bottom: 14px; display: block; opacity: 0.35; }
.empty-state h3 { color: var(--text); font-size: 19px; margin-bottom: 8px; }
.empty-state p  { font-size: 13px; margin-bottom: 18px; }

/* ========== TOAST ========== */
#toast-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

/* ========== DELETE MODAL ========== */
.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.7);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.25s;
    backdrop-filter: blur(4px);
}
.modal-backdrop.open { opacity: 1; pointer-events: all; }
.modal {
    background: var(--bg-card);
    border: 1px solid var(--border-hover);
    border-radius: 20px;
    padding: 30px 26px;
    max-width: 360px;
    width: 90%;
    text-align: center;
    transform: scale(0.92);
    transition: transform 0.25s cubic-bezier(0.4,0,0.2,1);
    box-shadow: var(--shadow-lg);
}
.modal-backdrop.open .modal { transform: scale(1); }
.modal .modal-icon  { font-size: 38px; color: var(--danger); margin-bottom: 14px; }
.modal h3 { font-size: 17px; margin-bottom: 7px; color: var(--text); }
.modal p  { font-size: 13px; color: var(--text-muted); margin-bottom: 22px; }
.modal-name { font-weight: 700; color: var(--text); }
.modal-actions { display: flex; gap: 10px; }
.modal-actions button {
    flex: 1;
    padding: 10px;
    border-radius: 10px;
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    border: none;
}
.modal-cancel  { background: var(--bg); color: var(--text); border: 1px solid var(--border) !important; }
.modal-cancel:hover { border-color: var(--border-hover) !important; }
.modal-confirm { background: var(--danger); color: #fff; }
.modal-confirm:hover { background: #e55a5a; transform: translateY(-1px); }

/* ========== RESPONSIVE ========== */
@media (max-width: 900px) {
    .stat-card.top-cat { display: none; }
    .hide-sm { display: none; }
}
@media (min-width: 901px) {
    .hide-sm { display: inline; }
}
@media (max-width: 768px) {

    .navbar { padding: 10px 14px; }
    .navbar-title { display: none; }
    .search-box { min-width: 0; flex: 1; }
    .search-box input { width: 100%; }
    .container { padding: 16px 12px 100px; }
    .product-grid { grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px; }
    .product-card .image-wrapper { height: 150px; }
}
@media (max-width: 480px) {
    .stats-bar { grid-template-columns: repeat(3, 1fr); }
    .product-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
    .product-card .content { padding: 9px 10px 11px; }
    .product-card .content h3 { font-size: 13px; }
    .price { font-size: 16px; }
    .product-card .content .actions { display: none; }
    .product-card .image-wrapper { height: 120px; }
}

/* ========== QUICK-VIEW DRAWER ========== */
.qv-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    z-index: 400;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s;
    backdrop-filter: blur(4px);
}
.qv-overlay.open { opacity: 1; pointer-events: all; }

.qv-drawer {
    position: fixed;
    top: 0; right: 0; bottom: 0;
    width: 430px;
    max-width: 100vw;
    background: var(--bg-card);
    border-left: 1px solid var(--border-hover);
    z-index: 401;
    transform: translateX(100%);
    transition: transform 0.38s cubic-bezier(0.4,0,0.2,1);
    display: flex;
    flex-direction: column;
    box-shadow: -8px 0 40px rgba(0,0,0,0.5);
    overflow: hidden;
}
.qv-drawer.open { transform: translateX(0); }

.qv-close {
    position: absolute;
    top: 14px; right: 14px;
    z-index: 10;
    background: rgba(0,0,0,0.55);
    border: none;
    color: #fff;
    width: 34px; height: 34px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 14px;
    transition: var(--transition);
    backdrop-filter: blur(6px);
}
.qv-close:hover { background: rgba(255,107,107,0.85); transform: scale(1.05); }

.qv-img-wrap {
    position: relative;
    width: 100%;
    height: 270px;
    overflow: hidden;
    background: #151515;
    flex-shrink: 0;
}
[data-theme="light"] .qv-img-wrap { background: #e8e0d5; }
.qv-img-wrap img { width: 100%; height: 100%; object-fit: cover; }
.qv-img-wrap::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(to top, var(--bg-card) 0%, transparent 55%);
    pointer-events: none;
}

.qv-sold-badge {
    position: absolute;
    top: 14px; left: 14px;
    background: rgba(255,107,107,0.92);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    padding: 4px 11px;
    border-radius: 50px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    display: none;
    z-index: 2;
    box-shadow: 0 2px 8px rgba(255,107,107,0.4);
}
.qv-star-badge {
    position: absolute;
    bottom: 22px; left: 14px;
    width: 76px; height: 76px;
    display: none;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 13px;
    clip-path: polygon(50% 0%,59.6% 14.3%,75% 6.7%,76.2% 23.8%,93.3% 25%,85.7% 40.4%,100% 50%,85.7% 59.6%,93.3% 75%,76.2% 76.2%,75% 93.3%,59.6% 85.7%,50% 100%,40.4% 85.7%,25% 93.3%,23.8% 76.2%,6.7% 75%,14.3% 59.6%,0% 50%,14.3% 40.4%,6.7% 25%,23.8% 23.8%,25% 6.7%,40.4% 14.3%);
    font-size: 11px;
    font-weight: 800;
    line-height: 1.3;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    background: linear-gradient(135deg,#a81e1e 0%,#e74c3c 50%,#a81e1e 100%);
    color: #fff;
    text-shadow: 0 1px 3px rgba(0,0,0,0.45);
    filter: drop-shadow(0 2px 6px rgba(231,76,60,0.7));
    word-break: break-word;
    pointer-events: none;
    z-index: 2;
}

.qv-body {
    padding: 20px 24px 24px;
    overflow-y: auto;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 0;
}

/* header row: category pill + status pill */
.qv-header-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 10px;
}
.qv-cat {
    display: inline-flex;
    align-items: center;
    padding: 3px 11px;
    border-radius: 50px;
    background: rgba(209,144,75,0.1);
    color: var(--accent);
    border: 1px solid rgba(209,144,75,0.22);
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.6px;
}
.qv-name {
    font-size: 22px;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 10px;
    line-height: 1.3;
}
.qv-price-row {
    display: flex;
    align-items: baseline;
    gap: 8px;
    margin-bottom: 18px;
}
.qv-price {
    font-size: 32px;
    font-weight: 700;
    color: var(--accent);
    letter-spacing: -0.5px;
    line-height: 1;
}
.qv-price-sub {
    font-size: 11px;
    color: var(--text-muted);
    font-weight: 400;
}
.qv-status {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 50px;
    flex-shrink: 0;
}
.qv-status.available   { background: rgba(85,224,135,0.12); color: var(--success); border: 1px solid rgba(85,224,135,0.25); }
.qv-status.unavailable { background: rgba(255,107,107,0.12); color: var(--danger);  border: 1px solid rgba(255,107,107,0.25); }

.qv-divider {
    border: none;
    border-top: 1px solid var(--border);
    margin: 16px 0;
}
.qv-section-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.9px;
    color: var(--text-muted);
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 7px;
}
.qv-desc {
    font-size: 13px;
    color: var(--text-muted);
    line-height: 1.78;
    white-space: pre-wrap;
}
.qv-no-desc {
    font-size: 13px;
    color: var(--text-muted);
    font-style: italic;
}

.qv-details-grid {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.qv-detail-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 9px 13px;
    background: var(--bg);
    border-radius: 10px;
    border: 1px solid var(--border);
}
.qv-detail-label {
    font-size: 11px;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 7px;
}
.qv-detail-value {
    font-size: 12px;
    font-weight: 600;
    color: var(--text);
}

.qv-actions {
    display: flex;
    gap: 8px;
    margin-top: auto;
    padding-top: 20px;
}
.qv-btn {
    flex: 1;
    padding: 11px 16px;
    border-radius: 10px;
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    border: none;
    text-align: center;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}
.qv-btn.edit      { background: var(--accent); color: #000; }
.qv-btn.edit:hover { background: var(--accent-light); transform: translateY(-1px); }
.qv-btn.close-btn { background: var(--bg); color: var(--text); border: 1px solid var(--border); }
.qv-btn.close-btn:hover { border-color: var(--border-hover); }

.qv-shimmer {
    background: linear-gradient(90deg, var(--border) 25%, var(--border-hover) 50%, var(--border) 75%);
    background-size: 200% 100%;
    animation: qvShimmer 1.2s infinite;
    border-radius: 6px;
    height: 16px;
    margin-bottom: 10px;
}
@keyframes qvShimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

.product-card .content h3.qv-trigger { cursor: pointer; }
.product-card .content h3.qv-trigger:hover { text-decoration: underline; text-decoration-color: rgba(209,144,75,0.4); text-underline-offset: 3px; }

/* Pagination */
.pg-wrap { padding:14px 20px; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
.pg-nav { display:flex; gap:4px; flex-wrap:wrap; }
.pg-btn { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text-muted); font-size:13px; font-weight:600; text-decoration:none; transition:var(--transition); }
.pg-btn:hover { border-color:var(--accent); color:var(--accent); }
.pg-active { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; background:var(--accent); border:1px solid var(--accent); color:#000; font-size:13px; font-weight:700; }
.pg-disabled { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; border:1px solid var(--border); color:var(--text-muted); font-size:13px; opacity:.35; cursor:default; }
.pg-ellipsis { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; color:var(--text-muted); font-size:13px; }
.pg-info { font-size:12px; color:var(--text-muted); }
</style>
</head>

<body>

<!-- ========== NAVBAR ========== -->
<nav class="navbar">
    <div class="navbar-left">
        <a href="dashboard.php" class="back-link">
            <i class="fa-solid fa-arrow-left"></i> Dashboard
        </a>
        <div class="navbar-title">
            <i class="fa-solid fa-mug-hot"></i>
            Products
            <span class="count-badge"><?= $totalProducts ?></span>
        </div>
    </div>

    <div class="navbar-right">
        <!-- Search -->
        <div class="search-box">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="searchInput" placeholder="Search… (/)">
            <button class="search-clear" id="searchClear"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <!-- Sort -->
        <select class="sort-select" id="sortSelect">
            <option value="default">Newest</option>
            <option value="name-asc">Name A–Z</option>
            <option value="name-desc">Name Z–A</option>
            <option value="price-asc">Price ↑</option>
            <option value="price-desc">Price ↓</option>
            <option value="avail-first">Available First</option>
            <option value="unavail-first">Sold Out First</option>
        </select>

        <!-- View Toggle -->
        <div class="view-toggle">
            <button class="view-btn active" id="gridViewBtn" title="Grid view"><i class="fa-solid fa-grip"></i></button>
            <button class="view-btn" id="listViewBtn" title="List view"><i class="fa-solid fa-list"></i></button>
        </div>

        <!-- Export CSV -->
        <button class="btn-icon" id="exportBtn" title="Export visible products to CSV" onclick="exportCSV()">
            <i class="fa-solid fa-file-csv"></i>
            <span class="hide-sm">Export</span>
        </button>

        <?php if ($_can_manage_products): ?>
        <!-- Select Mode -->
        <button class="btn-icon" id="selectModeBtn" title="Select products" onclick="toggleSelectMode()">
            <i class="fa-solid fa-check-square"></i>
            <span class="hide-sm" id="selectBtnText">Select</span>
        </button>

        <!-- Add -->
        <a href="add_product.php" class="btn-add">
            <i class="fa-solid fa-plus"></i>
            <span class="hide-sm">Add Product</span>
        </a>

        <!-- Manage Categories -->
        <a href="manage_categories.php" class="btn-add" style="background:transparent;border:1px solid var(--border,#2a2a2a);color:var(--text,#f5f5f5);">
            <i class="fa-solid fa-tags"></i>
            <span class="hide-sm">Categories</span>
        </a>

        <!-- Manage Milk -->
        <a href="manage_milk.php" class="btn-add" style="background:transparent;border:1px solid var(--border,#2a2a2a);color:var(--text,#f5f5f5);">
            <i class="fa-solid fa-bottle-water"></i>
            <span class="hide-sm">Milk</span>
        </a>

        <!-- Manage Add-ons -->
        <a href="manage_addons.php" class="btn-add" style="background:transparent;border:1px solid var(--border,#2a2a2a);color:var(--text,#f5f5f5);">
            <i class="fa-solid fa-plus-circle"></i>
            <span class="hide-sm">Add-ons</span>
        </a>
        <?php endif; ?>

        <!-- Theme -->
        <button class="theme-toggle" onclick="toggleTheme()">
            <i class="fa-solid fa-moon" id="themeIcon"></i>
            <span id="themeText" class="hide-sm">Dark</span>
        </button>
    </div>
</nav>

<!-- ========== MAIN ========== -->
<div class="container">

    <!-- ── Stats Bar ── -->
    <div class="stats-bar">
        <div class="stat-card total">
            <div class="stat-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
            <div class="stat-body">
                <div class="stat-label">Total</div>
                <div class="stat-value"><?= $totalProducts ?></div>
                <div class="stat-sub">products</div>
            </div>
        </div>

        <div class="stat-card avail">
            <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div class="stat-body">
                <div class="stat-label">Available</div>
                <div class="stat-value"><?= $availCount ?></div>
                <div class="avail-progress-wrap">
                    <div class="avail-bar">
                        <div class="avail-bar-fill" style="width:<?= $availPct ?>%"></div>
                    </div>
                    <span class="avail-pct"><?= $availPct ?>%</span>
                </div>
            </div>
        </div>

        <div class="stat-card unavail">
            <div class="stat-icon"><i class="fa-solid fa-circle-xmark"></i></div>
            <div class="stat-body">
                <div class="stat-label">Sold Out</div>
                <div class="stat-value"><?= $unavailCount ?></div>
                <div class="stat-sub"><?= $unavailCount === 1 ? 'item' : 'items' ?> off menu</div>
            </div>
        </div>


        <?php if ($top): ?>
        <div class="stat-card top-cat">
            <div class="stat-icon"><i class="fa-solid fa-trophy"></i></div>
            <div class="stat-body">
                <div class="stat-label">Top Category</div>
                <div class="stat-value" style="font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($top) ?></div>
                <div class="stat-sub"><?= $catCounts[$top] ?> products</div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Filter Section ── -->
    <div class="filter-section">
        <!-- Category row -->
        <div class="filter-row" id="filterTabs">
            <span class="filter-row-label"><i class="fa-solid fa-tag"></i> Category</span>
            <button class="filter-tab active" data-filter="all">
                All <span class="tab-count"><?= $totalProducts ?></span>
            </button>
            <?php foreach ($filterCats as $fc): ?>
            <button class="filter-tab" data-filter="<?= htmlspecialchars($fc['slug']) ?>">
                <?= htmlspecialchars($fc['slug']) ?> <span class="tab-count"><?= (int)$fc['count'] ?></span>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- Price range row -->
        <div class="filter-row" id="priceFilterRow">
            <span class="filter-row-label"><i class="fa-solid fa-coins"></i> Price</span>
            <button class="filter-tab price-tab active" data-min="0" data-max="99999">All Prices</button>
            <button class="filter-tab price-tab" data-min="0"   data-max="4.99">Under $5</button>
            <button class="filter-tab price-tab" data-min="5"   data-max="9.99">$5 – $10</button>
            <button class="filter-tab price-tab" data-min="10"  data-max="99999">$10+</button>
        </div>

        <!-- Badge filter row -->
        <div class="filter-row" id="badgeFilterRow">
            <span class="filter-row-label"><i class="fa-solid fa-tag"></i> Badge</span>
            <button class="filter-tab badge-tab active" data-badge="all">All</button>
            <button class="filter-tab badge-tab" data-badge="has">
                <i class="fa-solid fa-tag" style="font-size:10px;"></i> Has Badge
            </button>
            <button class="filter-tab badge-tab" data-badge="none">No Badge</button>
        </div>

        <?php /* Bulk "Enable sizes by category" hidden 2026-07-09 — sizes are set per-product via the
                 add/edit product "This product has sizes" toggle. Handler (action=bulk_enable_sizes) is
                 kept; to restore this UI, change `false` back to `$_can_manage_products`. */ ?>
        <?php if (false): ?>
        <!-- Bulk enable sizes by category -->
        <form class="filter-row" id="bulkSizesRow" method="POST" action="products.php">
            <span class="filter-row-label"><i class="fa-solid fa-ruler-combined"></i> Sizes</span>
            <input type="hidden" name="action" value="bulk_enable_sizes">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <select name="category" class="bulk-sizes-select" required>
                <option value="">Choose category…</option>
                <?php foreach ($realCategories as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="filter-tab bulk-sizes-submit"
                    onclick="return confirm('Enable sizes for every product in this category and seed default S/M/L prices for any product that has none yet?');">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Enable sizes
            </button>
        </form>
        <?php endif; ?>
    </div>

    <!-- ── Results Row ── -->
    <div class="results-row">
        <div class="results-info">
            Showing <strong id="resultsCount"><?= $totalProducts ?></strong> of <strong><?= $totalProducts ?></strong> products
        </div>
        <div class="results-hint">
            <span>Double-click price to edit inline</span>
            &nbsp;·&nbsp;
            <span class="kbd">/</span> to search
        </div>
    </div>

    <!-- ── Product Grid ── -->
    <div class="product-grid" id="productGrid">

        <?php if ($totalProducts > 0): ?>
            <?php foreach ($products as $i => $row):
                $img = $row['image'];
                if ($img && strpos($img, 'uploads/') !== false) $src = $img;
                elseif ($img) $src = 'uploads/' . $img;
                else $src = 'uploads/no-image.png';
                $available = (int)($row['is_available'] ?? 1);
            ?>
            <div class="product-card <?= $available ? '' : 'unavailable' ?>"
                 data-category="<?= htmlspecialchars($row['category'] ?: 'Uncategorized') ?>"
                 data-name="<?= htmlspecialchars(strtolower($row['name'])) ?>"
                 data-price="<?= (float)$row['price'] ?>"
                 data-id="<?= (int)$row['product_id'] ?>"
                 data-avail="<?= $available ?>"
                 data-badge="<?= product_badge_label($row) !== '' ? '1' : '0' ?>"
                 style="animation-delay:<?= min($i * 0.04, 0.6) ?>s">

                <!-- Selection overlay (intercepts clicks in select mode) -->
                <div class="card-select-overlay" onclick="toggleCardSelection(<?= $row['product_id'] ?>, this.closest('.product-card'))"></div>

                <!-- Checkbox indicator -->
                <div class="card-checkbox"><i class="fa-solid fa-check"></i></div>

                <div class="image-wrapper">
                    <img src="<?= htmlspecialchars($src) ?>"
                         alt="<?= htmlspecialchars($row['name']) ?>"
                         loading="lazy"
                         onerror="this.src='uploads/no-image.png'">
                    <?php if (!$available): ?>
                    <div class="sold-out-badge">Sold Out</div>
                    <?php endif; ?>
                    <?php $__badge = product_badge_label($row); if ($__badge !== ''): ?>
                    <span class="product-badge"><?= htmlspecialchars($__badge) ?></span>
                    <?php endif; ?>
                    <?php /* The hover overlay used to repeat Edit / Duplicate / Delete on the
                             image. Every one of those already sits in the actions row below,
                             which also carries the On/Off toggle and — unlike a hover overlay —
                             works on a touchscreen till. Removed as pure duplication. */ ?>
                </div>

                <div class="content">
                    <div class="top-row">
                        <span class="category-badge"><?= htmlspecialchars($row['category'] ?: 'Uncategorized') ?></span>
                        <span class="product-id">#<?= $row['product_id'] ?></span>
                    </div>
                    <h3 class="qv-trigger"
                        title="Click to preview"
                        onclick="openQV(<?= (int)$row['product_id'] ?>)"><?= htmlspecialchars($row['name']) ?></h3>
                    <span class="price" data-pid="<?= $row['product_id'] ?>" title="Double-click to edit price">
                        $<?= number_format($row['price'], 2) ?>
                    </span>
                    <?php if (!empty($missing[(int)$row['product_id']])): ?>
                    <span class="badge badge-danger" title="has_sizes is on but no size prices set">missing size prices</span>
                    <?php endif; ?>

                    <?php if ($_can_manage_products): ?>
                    <div class="actions">
                        <a href="edit_product.php?id=<?= $row['product_id'] ?>" class="btn-action edit">
                            <i class="fa-solid fa-pen-to-square"></i> Edit
                        </a>
                        <button class="btn-action <?= $available ? 'avail-on' : 'avail-off' ?>"
                                onclick="toggleAvailability(<?= $row['product_id'] ?>, this)"
                                title="<?= $available ? 'Mark as sold out' : 'Mark as available' ?>">
                            <i class="fa-solid <?= $available ? 'fa-eye' : 'fa-eye-slash' ?>"></i>
                            <?= $available ? 'On' : 'Off' ?>
                        </button>
                        <button class="btn-action duplicate"
                                onclick="duplicateProduct(<?= $row['product_id'] ?>, '<?= htmlspecialchars(addslashes($row['name'])) ?>')">
                            <i class="fa-solid fa-copy"></i>
                        </button>
                        <button class="btn-action delete"
                                onclick="confirmDelete(<?= $row['product_id'] ?>, '<?= htmlspecialchars(addslashes($row['name'])) ?>')">
                            <i class="fa-solid fa-trash-can"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fa-regular fa-mug-hot empty-icon"></i>
                <h3>No Products Yet</h3>
                <p>Add your first product to get the menu started.</p>
                <?php if ($_can_manage_products): ?>
                <a href="add_product.php" class="btn-add" style="display:inline-flex;margin:0 auto;">
                    <i class="fa-solid fa-plus"></i> Add Product
                </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div id="filterEmpty" class="empty-state" style="display:none">
            <i class="fa-solid fa-magnifying-glass empty-icon"></i>
            <h3>No Results</h3><p>Try a different search or filter.</p>
        </div>
    </div><!-- /product-grid -->
    <div id="pgWrap" class="pg-wrap" style="display:none">
        <span id="pgInfo" class="pg-info"></span>
        <nav id="pgNav" class="pg-nav"></nav>
    </div>
</div><!-- /container -->

<!-- ========== BULK ACTION BAR ========== -->
<div class="bulk-bar" id="bulkBar">
    <span class="bulk-count" id="bulkCount">0 selected</span>
    <div class="bulk-divider"></div>
    <div class="bulk-actions">
        <button class="bulk-btn all" onclick="selectAll()">
            <i class="fa-solid fa-check-double"></i> All
        </button>
        <button class="bulk-btn toggle-avail" onclick="bulkToggle()">
            <i class="fa-solid fa-eye"></i> Toggle
        </button>
        <button class="bulk-btn clear-promo" onclick="bulkClearPromo()">
            <i class="fa-solid fa-tag"></i> Clear promo
        </button>
        <button class="bulk-btn bulk-delete-btn" onclick="bulkDelete()">
            <i class="fa-solid fa-trash-can"></i> Delete
        </button>
        <button class="bulk-btn cancel-bulk" onclick="exitSelectMode()">
            <i class="fa-solid fa-xmark"></i> Cancel
        </button>
    </div>
</div>

<!-- ========== DELETE MODAL ========== -->
<div class="modal-backdrop" id="deleteModal">
    <div class="modal">
        <div class="modal-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <h3>Delete Product?</h3>
        <p>You're about to permanently delete <span class="modal-name" id="deleteProductName"></span>.</p>
        <div class="modal-actions">
            <button class="modal-cancel" onclick="closeDeleteModal()">Cancel</button>
            <button class="modal-confirm" id="deleteConfirmBtn">Delete</button>
        </div>
    </div>
</div>

<!-- ========== QUICK-VIEW DRAWER ========== -->
<div class="qv-overlay" id="qvOverlay" onclick="closeQV()"></div>
<div class="qv-drawer" id="qvDrawer">
    <button class="qv-close" onclick="closeQV()"><i class="fa-solid fa-xmark"></i></button>
    <div class="qv-img-wrap">
        <img id="qvImg" src="" alt="">
        <div id="qvSoldBadge" class="qv-sold-badge">Sold Out</div>
        <div id="qvStarBadge" class="qv-star-badge"></div>
    </div>
    <div class="qv-body" id="qvBody">
        <div class="qv-shimmer" style="width:55%;height:12px;margin-bottom:14px;"></div>
        <div class="qv-shimmer" style="width:90%;height:22px;margin-bottom:8px;"></div>
        <div class="qv-shimmer" style="width:38%;height:28px;margin-bottom:14px;"></div>
        <div class="qv-shimmer" style="width:80%;"></div>
        <div class="qv-shimmer" style="width:65%;"></div>
        <div class="qv-shimmer" style="width:75%;"></div>
    </div>
</div>

<!-- ========== TOAST ========== -->
<div id="toast-container"></div>

<script>
// ── Card data ──
let allCards    = Array.from(document.querySelectorAll('.product-card'));
let activeFilter     = 'all';
let activeSearch     = '';
let activeSort       = 'default';
let activePriceMin   = 0;
let activePriceMax   = 99999;
let activeBadge      = 'all';
let selectMode       = false;
let selectedIds      = new Set();

// ─────────────────────────────────────────────
// FILTER TABS (Category)
// ─────────────────────────────────────────────
document.querySelectorAll('#filterTabs .filter-tab').forEach(tab => {
    tab.addEventListener('click', function () {
        document.querySelectorAll('#filterTabs .filter-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        activeFilter = this.dataset.filter;
        applyFilters();
    });
});

// ─────────────────────────────────────────────
// BADGE FILTER
// ─────────────────────────────────────────────
document.querySelectorAll('#badgeFilterRow .badge-tab').forEach(tab => {
    tab.addEventListener('click', function () {
        document.querySelectorAll('#badgeFilterRow .badge-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        activeBadge = this.dataset.badge;
        applyFilters();
    });
});

// ─────────────────────────────────────────────
// PRICE FILTER
// ─────────────────────────────────────────────
document.querySelectorAll('#priceFilterRow .price-tab').forEach(tab => {
    tab.addEventListener('click', function () {
        document.querySelectorAll('#priceFilterRow .price-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        activePriceMin = parseFloat(this.dataset.min);
        activePriceMax = parseFloat(this.dataset.max);
        applyFilters();
    });
});

// ─────────────────────────────────────────────
// SEARCH
// ─────────────────────────────────────────────
const searchInput = document.getElementById('searchInput');
const searchClear = document.getElementById('searchClear');

searchInput.addEventListener('input', function () {
    activeSearch = this.value.trim().toLowerCase();
    searchClear.classList.toggle('visible', activeSearch.length > 0);
    applyFilters();
});
searchClear.addEventListener('click', function () {
    searchInput.value = '';
    activeSearch = '';
    this.classList.remove('visible');
    searchInput.focus();
    applyFilters();
});

// ─────────────────────────────────────────────
// SORT
// ─────────────────────────────────────────────
document.getElementById('sortSelect').addEventListener('change', function () {
    activeSort = this.value;
    applyFilters();
});

// ─────────────────────────────────────────────
// APPLY ALL FILTERS + SORT  (JS pagination)
// ─────────────────────────────────────────────
const PER_PAGE   = 12;
let currentPage  = 1;
let lastFiltered = [];

function applyFilters() {
    currentPage  = 1;
    lastFiltered = allCards.filter(card => {
        const catMatch   = activeFilter === 'all' || card.dataset.category === activeFilter;
        const nameMatch  = card.dataset.name.includes(activeSearch);
        const price      = parseFloat(card.dataset.price);
        const priceMatch = price >= activePriceMin && price <= activePriceMax;
        const hasBadge   = card.dataset.badge === '1';
        const badgeMatch = activeBadge === 'all' || (activeBadge === 'has' ? hasBadge : !hasBadge);
        return catMatch && nameMatch && priceMatch && badgeMatch;
    });
    lastFiltered.sort((a, b) => {
        switch (activeSort) {
            case 'name-asc':      return a.dataset.name.localeCompare(b.dataset.name);
            case 'name-desc':     return b.dataset.name.localeCompare(a.dataset.name);
            case 'price-asc':     return parseFloat(a.dataset.price) - parseFloat(b.dataset.price);
            case 'price-desc':    return parseFloat(b.dataset.price) - parseFloat(a.dataset.price);
            case 'avail-first':   return parseInt(b.dataset.avail) - parseInt(a.dataset.avail);
            case 'unavail-first': return parseInt(a.dataset.avail) - parseInt(b.dataset.avail);
            default:              return parseInt(b.dataset.id) - parseInt(a.dataset.id);
        }
    });
    renderPage();
}

function renderPage() {
    const total      = lastFiltered.length;
    const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));
    currentPage      = Math.min(currentPage, totalPages);
    const start      = (currentPage - 1) * PER_PAGE;
    const pageCards  = lastFiltered.slice(start, start + PER_PAGE);

    allCards.forEach(c => { c.style.display = 'none'; c.style.animation = 'none'; });

    const grid = document.getElementById('productGrid');
    pageCards.forEach((card, i) => {
        card.style.display = '';
        grid.appendChild(card);
        void card.offsetWidth;
        card.style.animation = `cardIn 0.3s ease ${Math.min(i * 0.04, 0.4)}s both`;
    });

    document.getElementById('resultsCount').textContent = total;
    document.getElementById('filterEmpty').style.display = total === 0 ? 'block' : 'none';

    renderPagination(total, totalPages);
}

function renderPagination(total, totalPages) {
    const wrap = document.getElementById('pgWrap');
    if (totalPages <= 1) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'flex';

    document.getElementById('pgInfo').textContent =
        `Page ${currentPage} of ${totalPages} · ${total} products`;

    const nav = document.getElementById('pgNav');
    let html = currentPage > 1
        ? `<a href="#" class="pg-btn" onclick="goPage(1);return false;">«</a>
           <a href="#" class="pg-btn" onclick="goPage(${currentPage - 1});return false;">‹</a>`
        : `<span class="pg-disabled">«</span><span class="pg-disabled">‹</span>`;

    const ws = Math.max(1, currentPage - 2);
    const we = Math.min(totalPages, currentPage + 2);
    if (ws > 1) html += `<span class="pg-ellipsis">…</span>`;
    for (let i = ws; i <= we; i++) {
        html += i === currentPage
            ? `<span class="pg-active">${i}</span>`
            : `<a href="#" class="pg-btn" onclick="goPage(${i});return false;">${i}</a>`;
    }
    if (we < totalPages) html += `<span class="pg-ellipsis">…</span>`;
    html += currentPage < totalPages
        ? `<a href="#" class="pg-btn" onclick="goPage(${currentPage + 1});return false;">›</a>
           <a href="#" class="pg-btn" onclick="goPage(${totalPages});return false;">»</a>`
        : `<span class="pg-disabled">›</span><span class="pg-disabled">»</span>`;
    nav.innerHTML = html;
}

function goPage(p) {
    currentPage = p;
    renderPage();
    document.getElementById('productGrid').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// ─────────────────────────────────────────────
// VIEW TOGGLE
// ─────────────────────────────────────────────
const gridViewBtn  = document.getElementById('gridViewBtn');
const listViewBtn  = document.getElementById('listViewBtn');
const productGrid  = document.getElementById('productGrid');

gridViewBtn.addEventListener('click', () => {
    productGrid.classList.remove('list-view');
    gridViewBtn.classList.add('active');
    listViewBtn.classList.remove('active');
    localStorage.setItem('productView', 'grid');
});
listViewBtn.addEventListener('click', () => {
    productGrid.classList.add('list-view');
    listViewBtn.classList.add('active');
    gridViewBtn.classList.remove('active');
    localStorage.setItem('productView', 'list');
});

// ─────────────────────────────────────────────
// SELECT MODE
// ─────────────────────────────────────────────
function toggleSelectMode() {
    if (selectMode) { exitSelectMode(); return; }
    selectMode = true;
    document.body.classList.add('select-mode');
    document.getElementById('selectModeBtn').classList.add('select-active');
    document.getElementById('selectBtnText').textContent = 'Done';
}

function exitSelectMode() {
    selectMode = false;
    document.body.classList.remove('select-mode');
    document.getElementById('selectModeBtn').classList.remove('select-active');
    document.getElementById('selectBtnText').textContent = 'Select';
    clearSelection();
}

function toggleCardSelection(id, card) {
    if (selectedIds.has(id)) {
        selectedIds.delete(id);
        card.classList.remove('selected');
    } else {
        selectedIds.add(id);
        card.classList.add('selected');
    }
    updateBulkBar();
}

function clearSelection() {
    selectedIds.clear();
    document.querySelectorAll('.product-card.selected').forEach(c => c.classList.remove('selected'));
    updateBulkBar();
}

function selectAll() {
    const visible = allCards.filter(c => c.style.display !== 'none');
    visible.forEach(c => {
        const id = parseInt(c.dataset.id);
        selectedIds.add(id);
        c.classList.add('selected');
    });
    updateBulkBar();
}

function updateBulkBar() {
    const count = selectedIds.size;
    document.getElementById('bulkCount').textContent = count + ' selected';
    document.getElementById('bulkBar').classList.toggle('visible', count > 0);
}

// ─────────────────────────────────────────────
// BULK ACTIONS
// ─────────────────────────────────────────────
function bulkToggle() {
    if (selectedIds.size === 0) return;
    fetch('bulk_action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=toggle&ids=' + [...selectedIds].join(',')
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) { showToast('Error toggling products', 'error'); return; }
        Object.entries(data.states).forEach(([id, avail]) => {
            const card = document.querySelector(`.product-card[data-id="${id}"]`);
            if (!card) return;
            card.dataset.avail = avail;
            const isAvail = avail == 1;
            card.classList.toggle('unavailable', !isAvail);

            // Update ::before via data attr — we use the class
            const btn = card.querySelector('.btn-action.avail-on, .btn-action.avail-off');
            const badge = card.querySelector('.sold-out-badge');

            paintAvailBtn(btn, isAvail);
            if (isAvail && badge) badge.remove();
            if (!isAvail && !badge) {
                const b = document.createElement('div');
                b.className = 'sold-out-badge';
                b.textContent = 'Sold Out';
                card.querySelector('.image-wrapper').appendChild(b);
            }
        });
        showToast(`${selectedIds.size} product${selectedIds.size !== 1 ? 's' : ''} updated`);
        clearSelection();
    })
    .catch(() => showToast('Request failed', 'error'));
}

/* Ends a promotion across the selection. The percentages are not recoverable
   afterwards, so this confirms first — and reports how many products actually
   carried a promo rather than how many were selected, because selecting all 53
   and being told "53 cleared" would misdescribe what happened. */
function bulkClearPromo() {
    if (selectedIds.size === 0) return;
    if (!confirm(`Clear the promotion on ${selectedIds.size} selected product${selectedIds.size !== 1 ? 's' : ''}?\n\nThe discount percentages are removed and cannot be restored. Past orders keep the price they were charged.`)) return;
    fetch('bulk_action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=clear_promo&ids=' + [...selectedIds].join(',')
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) { showToast('Error clearing promotions', 'error'); return; }
        if (data.cleared === 0) { showToast('No promotions to clear in that selection'); clearSelection(); return; }
        showToast(`${data.cleared} promotion${data.cleared !== 1 ? 's' : ''} cleared`);
        // The cards carry promo badges and struck-through prices rendered
        // server-side; a reload is the honest way to show the new prices rather
        // than patching each card and risking the two drifting.
        setTimeout(() => location.reload(), 700);
    })
    .catch(() => showToast('Request failed', 'error'));
}

function bulkDelete() {
    if (selectedIds.size === 0) return;
    if (!confirm(`Delete ${selectedIds.size} product${selectedIds.size !== 1 ? 's' : ''}? This cannot be undone.`)) return;
    fetch('bulk_action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=delete&ids=' + [...selectedIds].join(',')
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) { showToast('Error deleting', 'error'); return; }
        data.deleted.forEach(id => {
            const card = document.querySelector(`.product-card[data-id="${id}"]`);
            if (card) {
                card.style.transition = 'opacity 0.3s, transform 0.3s';
                card.style.opacity = '0';
                card.style.transform = 'scale(0.85)';
                setTimeout(() => {
                    allCards = allCards.filter(c => c !== card);
                    card.remove();
                    applyFilters();
                }, 300);
            }
        });
        showToast(`Deleted ${data.deleted.length} product${data.deleted.length !== 1 ? 's' : ''}`, 'error');
        clearSelection();
        exitSelectMode();
    })
    .catch(() => showToast('Request failed', 'error'));
}

// ─────────────────────────────────────────────
// EXPORT CSV
// ─────────────────────────────────────────────
function exportCSV() {
    const visible = allCards.filter(c => c.style.display !== 'none');
    if (visible.length === 0) { showToast('Nothing to export', 'error'); return; }
    const rows = [['Name', 'Category', 'Price', 'Available']];
    visible.forEach(c => {
        rows.push([
            c.querySelector('h3').textContent.trim(),
            c.dataset.category,
            '$' + parseFloat(c.dataset.price).toFixed(2),
            c.dataset.avail === '1' ? 'Yes' : 'No'
        ]);
    });
    const csv = rows.map(r => r.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\r\n');
    const blob = new Blob([csv], {type: 'text/csv;charset=utf-8;'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'products_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
    URL.revokeObjectURL(a.href);
    showToast(`Exported ${visible.length} products`);
}

// ─────────────────────────────────────────────
// DUPLICATE PRODUCT
// ─────────────────────────────────────────────
function duplicateProduct(id, name) {
    showToast('Duplicating…');
    fetch('duplicate_product.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'product_id=' + id
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) { showToast('Duplicate failed', 'error'); return; }
        showToast('Duplicated! Redirecting to edit…');
        setTimeout(() => { window.location.href = 'edit_product.php?id=' + data.new_id; }, 900);
    })
    .catch(() => showToast('Request failed', 'error'));
}

// ─────────────────────────────────────────────
// INLINE PRICE EDIT (double-click) — admin/manager only
// ─────────────────────────────────────────────
const canManageProducts = <?= json_encode($_can_manage_products) ?>;
document.querySelectorAll('.price').forEach(span => {
    span.addEventListener('dblclick', function (e) {
        if (selectMode || !canManageProducts) return;
        e.stopPropagation();
        const card = this.closest('.product-card');
        const id   = card.dataset.id;
        const cur  = parseFloat(card.dataset.price);

        const input = document.createElement('input');
        input.type  = 'number';
        input.value = cur.toFixed(2);
        input.className = 'price-input-inline';
        input.step  = '0.01';
        input.min   = '0';
        input.max   = '9999.99';

        this.replaceWith(input);
        input.focus();
        input.select();

        let saved = false;
        const save = () => {
            if (saved) return;
            saved = true;
            const newVal = parseFloat(input.value);
            if (isNaN(newVal) || newVal < 0 || newVal > 9999.99) {
                input.replaceWith(span);
                return;
            }
            if (Math.abs(newVal - cur) < 0.001) { input.replaceWith(span); return; }

            fetch('update_product_price.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `product_id=${id}&price=${newVal}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    span.textContent = '$' + parseFloat(data.price).toFixed(2);
                    card.dataset.price = data.price;
                    showToast('Price updated to $' + parseFloat(data.price).toFixed(2));
                } else {
                    showToast('Update failed', 'error');
                }
                input.replaceWith(span);
            })
            .catch(() => { showToast('Request failed', 'error'); input.replaceWith(span); });
        };

        const cancel = () => { saved = true; input.replaceWith(span); };
        input.addEventListener('blur', save);
        input.addEventListener('keydown', e => {
            if (e.key === 'Enter')  { e.preventDefault(); input.blur(); }
            if (e.key === 'Escape') { e.preventDefault(); cancel(); }
        });
    });
});

// ─────────────────────────────────────────────
// AVAILABILITY TOGGLE
// ─────────────────────────────────────────────
/* Repaint the On/Off button from scratch.
   The old code indexed into btn.childNodes to find the label, but childNodes[1] is the
   <i> element — the label is the trailing text node — so it wrote " Off" INSIDE the icon
   and left the real label reading "On", producing "Off On". Rebuilding the contents is
   immune to how the server happened to indent the markup. */
function paintAvailBtn(btn, isAvail) {
    if (!btn) return;
    btn.className = btn.className.replace(/avail-(on|off)/, isAvail ? 'avail-on' : 'avail-off');
    btn.title     = isAvail ? 'Mark as sold out' : 'Mark as available';
    btn.textContent = '';
    const icon = document.createElement('i');
    icon.className = 'fa-solid ' + (isAvail ? 'fa-eye' : 'fa-eye-slash');
    btn.append(icon, document.createTextNode(' ' + (isAvail ? 'On' : 'Off')));
}

function toggleAvailability(id, btn) {
    btn.disabled = true;
    fetch('toggle_product.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'product_id=' + id
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) { btn.disabled = false; return; }
        const avail = data.available;
        const card  = btn.closest('.product-card');
        const badge = card.querySelector('.sold-out-badge');

        card.dataset.avail = avail;
        paintAvailBtn(btn, avail);
        if (avail) {
            card.classList.remove('unavailable');
            if (badge) badge.remove();
            showToast('Marked as available');
        } else {
            card.classList.add('unavailable');
            if (!badge) {
                const b = document.createElement('div');
                b.className = 'sold-out-badge';
                b.textContent = 'Sold Out';
                card.querySelector('.image-wrapper').appendChild(b);
            }
            showToast('Marked as sold out', 'error');
        }
        btn.disabled = false;
    })
    .catch(() => { btn.disabled = false; });
}

// ─────────────────────────────────────────────
// DELETE MODAL
// ─────────────────────────────────────────────
let deleteTarget = null;

function confirmDelete(id, name) {
    deleteTarget = id;
    document.getElementById('deleteProductName').textContent = name;
    document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('open');
    deleteTarget = null;
}
document.getElementById('deleteConfirmBtn').addEventListener('click', async function () {
    if (!deleteTarget) return;
    const btn = this;
    btn.disabled = true;
    btn.textContent = 'Deleting…';
    try {
        const res  = await fetch('delete_product.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'product_id=' + deleteTarget
        });
        const data = await res.json();
        if (data.ok) {
            const card = document.querySelector(`.product-card[data-id="${deleteTarget}"]`);
            if (card) {
                card.style.transition = 'opacity .25s, transform .25s';
                card.style.opacity = '0';
                card.style.transform = 'scale(0.9)';
                setTimeout(() => {
                    allCards = allCards.filter(c => c !== card);
                    card.remove();
                    applyFilters();
                }, 260);
            }
            closeDeleteModal();
            showToast('Product deleted', 'error');
        } else {
            showToast(data.error || 'Delete failed', 'error');
        }
    } catch { showToast('Network error', 'error'); }
    finally { btn.disabled = false; btn.textContent = 'Delete'; }
});
document.getElementById('deleteModal').addEventListener('click', function (e) {
    if (e.target === this) closeDeleteModal();
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeDeleteModal();
        closeQV();
        if (selectMode) exitSelectMode();
    }
});

// ─────────────────────────────────────────────
// QUICK-VIEW DRAWER
// ─────────────────────────────────────────────
function _qvEsc(e) { if (e.key === 'Escape') closeQV(); }

function openQV(id) {
    // reset image + badges
    const img      = document.getElementById('qvImg');
    const soldBadge = document.getElementById('qvSoldBadge');
    const starBadge = document.getElementById('qvStarBadge');
    img.src = '';
    soldBadge.style.display = 'none';
    starBadge.style.display = 'none';

    // shimmer while loading
    document.getElementById('qvBody').innerHTML =
        '<div class="qv-shimmer" style="width:55%;height:12px;margin-bottom:14px;"></div>' +
        '<div class="qv-shimmer" style="width:90%;height:22px;margin-bottom:8px;"></div>' +
        '<div class="qv-shimmer" style="width:38%;height:28px;margin-bottom:14px;"></div>' +
        '<div class="qv-shimmer" style="width:80%;"></div>' +
        '<div class="qv-shimmer" style="width:65%;"></div>' +
        '<div class="qv-shimmer" style="width:75%;"></div>';

    document.getElementById('qvOverlay').classList.add('open');
    document.getElementById('qvDrawer').classList.add('open');

    fetch('products.php?action=view&id=' + id)
        .then(r => r.json())
        .then(data => {
            if (!data.ok) { closeQV(); showToast('Could not load product', 'error'); return; }
            const p = data.product;

            img.src = p.image || 'uploads/no-image.png';
            img.alt = p.name;
            img.onerror = () => { img.src = 'uploads/no-image.png'; };

            soldBadge.style.display = p.is_available ? 'none' : 'block';

            if (p.badge_text) {
                starBadge.textContent = p.badge_text;
                starBadge.style.display = 'flex';
            }

            const editBtn = canManageProducts
                ? `<a href="edit_product.php?id=${p.id}" class="qv-btn edit"><i class="fa-solid fa-pen-to-square"></i> Edit Product</a>`
                : '';
            const descBlock = p.description
                ? `<div class="qv-section-label"><i class="fa-solid fa-align-left"></i> About</div>
                   <p class="qv-desc">${_qvEscHtml(p.description)}</p>`
                : `<div class="qv-section-label"><i class="fa-solid fa-align-left"></i> About</div>
                   <p class="qv-no-desc">No description provided.</p>`;
            const badgeRow = p.badge_text
                ? `<div class="qv-detail-item">
                       <span class="qv-detail-label"><i class="fa-solid fa-tag"></i> Badge Label</span>
                       <span class="qv-detail-value">${_qvEscHtml(p.badge_text)}</span>
                   </div>`
                : '';

            document.getElementById('qvBody').innerHTML =
                `<div class="qv-header-row">
                     <span class="qv-cat">${_qvEscHtml(p.category || 'Uncategorized')}</span>
                     <div class="qv-status ${p.is_available ? 'available' : 'unavailable'}">
                         <i class="fa-solid ${p.is_available ? 'fa-circle-check' : 'fa-circle-xmark'}"></i>
                         ${p.is_available ? 'Available' : 'Sold Out'}
                     </div>
                 </div>
                 <h2 class="qv-name">${_qvEscHtml(p.name)}</h2>
                 <div class="qv-price-row">
                     <span class="qv-price">$${parseFloat(p.price).toFixed(2)}</span>
                     <span class="qv-price-sub">per serving</span>
                 </div>
                 <hr class="qv-divider">
                 ${descBlock}
                 <hr class="qv-divider">
                 <div class="qv-section-label"><i class="fa-solid fa-circle-info"></i> Details</div>
                 <div class="qv-details-grid">
                     <div class="qv-detail-item">
                         <span class="qv-detail-label"><i class="fa-solid fa-hashtag"></i> Product ID</span>
                         <span class="qv-detail-value">#${p.id}</span>
                     </div>
                     ${badgeRow}
                 </div>
                 <div class="qv-actions">
                     ${editBtn}
                     <button class="qv-btn close-btn" onclick="closeQV()">
                         <i class="fa-solid fa-xmark"></i> Close
                     </button>
                 </div>`;
        })
        .catch(() => { closeQV(); showToast('Request failed', 'error'); });
}

function closeQV() {
    document.getElementById('qvOverlay').classList.remove('open');
    document.getElementById('qvDrawer').classList.remove('open');
}

function _qvEscHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ─────────────────────────────────────────────
// TOAST
// ─────────────────────────────────────────────
function showToast(message, type = 'success') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    const color = type === 'success' ? '#55e087' : '#ff6b6b';
    toast.style.cssText = `
        background:#1e1e1e; color:#fff; padding:10px 18px; border-radius:50px;
        box-shadow:0 6px 24px rgba(0,0,0,0.35); font-family:Poppins,sans-serif;
        font-size:12px; font-weight:500; border-left:4px solid ${color};
        transform:translateX(120%); transition:transform 0.3s ease;
        min-width:180px; pointer-events:none;`;
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(() => { toast.style.transform = 'translateX(0)'; }, 10);
    setTimeout(() => {
        toast.style.transform = 'translateX(120%)';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// ─────────────────────────────────────────────
// THEME
// ─────────────────────────────────────────────
function toggleTheme() {
    const html = document.documentElement;
    const icon = document.getElementById('themeIcon');
    const text = document.getElementById('themeText');
    if (html.getAttribute('data-theme') === 'light') {
        html.removeAttribute('data-theme');
        icon.className = 'fa-solid fa-moon';
        if (text) text.textContent = 'Dark';
        localStorage.setItem('theme', 'dark');
    } else {
        html.setAttribute('data-theme', 'light');
        icon.className = 'fa-solid fa-sun';
        if (text) text.textContent = 'Light';
        localStorage.setItem('theme', 'light');
    }
}

// ─────────────────────────────────────────────
// KEYBOARD SHORTCUT
// ─────────────────────────────────────────────
document.addEventListener('keydown', e => {
    if (e.key === '/' && document.activeElement !== searchInput && document.activeElement.tagName !== 'INPUT') {
        e.preventDefault();
        searchInput.focus();
    }
});

// ─────────────────────────────────────────────
// RESTORE PREFERENCES ON LOAD
// ─────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'light') {
        document.documentElement.setAttribute('data-theme', 'light');
        const icon = document.getElementById('themeIcon');
        const text = document.getElementById('themeText');
        if (icon) icon.className = 'fa-solid fa-sun';
        if (text) text.textContent = 'Light';
    }
    const savedView = localStorage.getItem('productView');
    if (savedView === 'list') {
        productGrid.classList.add('list-view');
        listViewBtn.classList.add('active');
        gridViewBtn.classList.remove('active');
    }
    // initial JS pagination render
    lastFiltered = [...allCards];
    renderPage();
});
</script>
<?php if ($_flash_welcome): ?>
<script>document.addEventListener('DOMContentLoaded',()=>showToast('Welcome back, <?= htmlspecialchars($_SESSION['username'] ?? 'User', ENT_QUOTES) ?>!','success'));</script>
<?php endif; ?>
</body>
</html>
