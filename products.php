<?php
header('X-Frame-Options: SAMEORIGIN');
require 'auth.php';
require 'config.php';
require_once __DIR__ . '/lang.php';
if (!can('products')) { header("Location: dashboard.php?denied=1"); exit; }
$_can_manage_products = in_array($_SESSION['role'] ?? '', ['admin', 'manager']);
$_flash_welcome = !empty($_SESSION['flash_welcome']); unset($_SESSION['flash_welcome']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

$missing = [];

// Quick-view AJAX endpoint
if (($_GET['action'] ?? '') === 'view') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['ok' => false]); exit; }
    $s = $conn->prepare("
        SELECT p.*, COUNT(r.recipe_id) AS recipe_count 
        FROM products p 
        LEFT JOIN product_recipes r ON p.product_id = r.product_id 
        WHERE p.product_id = ? 
        GROUP BY p.product_id
    ");
    $s->bind_param('i', $id);
    $s->execute();
    $p = $s->get_result()->fetch_assoc();
    if (!$p) { echo json_encode(['ok' => false]); exit; }
    $img = $p['image'] ?? '';
    $src = get_image_url($img, '');
    echo json_encode(['ok' => true, 'product' => [
        'id'           => (int)$p['product_id'],
        'name'         => $p['name'],
        'description'  => $p['description'] ?? '',
        'price'        => (float)$p['price'],
        'category'     => $p['category'] ?? '',
        'image'        => $src,
        'is_available' => (int)($p['is_available'] ?? 1),
        'badge_text'   => $p['badge_text'] ?? '',
        'has_recipe'   => ((int)($p['recipe_count'] ?? 0) > 0),
    ]]);
    exit;
}

$result = $conn->query("
    SELECT p.*, 
           COUNT(r.recipe_id) AS recipe_count 
    FROM products p 
    LEFT JOIN product_recipes r ON p.product_id = r.product_id 
    GROUP BY p.product_id 
    ORDER BY p.product_id DESC
");
$products = [];
while ($row = $result->fetch_assoc()) { $products[] = $row; }
$totalProducts  = count($products);
$availCount     = count(array_filter($products, fn($p) => ($p['is_available'] ?? 1) == 1));
$unavailCount   = $totalProducts - $availCount;
$noRecipeCount  = count(array_filter($products, fn($p) => (int)($p['recipe_count'] ?? 0) === 0));

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
$catNames   = [];
$activeCatRes = $conn->query("SELECT slug, name FROM categories WHERE is_active = 1 ORDER BY display_order, category_id");
if ($activeCatRes) {
    while ($cr = $activeCatRes->fetch_assoc()) {
        $slug = $cr['slug'];
        $name = !empty($cr['name']) ? $cr['name'] : $slug;
        $catNames[$slug] = $name;
        $filterCats[] = ['slug' => $slug, 'name' => $name, 'count' => $catCounts[$slug] ?? 0];
    }
}
// Only truly empty-category products bucket into the "Uncategorized" chip.
$uncat = $catCounts['Uncategorized'] ?? 0;
if ($uncat > 0) {
    $filterCats[] = ['slug' => 'Uncategorized', 'name' => 'Uncategorized', 'count' => $uncat];
    $catNames['Uncategorized'] = 'Uncategorized';
}

$availPct = $totalProducts > 0 ? round($availCount / $totalProducts * 100) : 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Products | Bird's Nest Coffee</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.tailwindcss.com"></script>
<script>(function(){try{if(localStorage.getItem("theme")==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>

<style>
body, input, select, textarea, button {
    font-family: 'Poppins', 'Kantumruy Pro', 'Siemreap', 'Noto Sans Khmer', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}
:lang(km), [data-lang="km"], html[lang="km"] * {
    font-family: 'Kantumruy Pro', 'Poppins', 'Siemreap', 'Noto Sans Khmer', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

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
    --text-light: #111827;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.06);
    --shadow-md: 0 4px 20px rgba(0,0,0,0.08);
    --shadow-lg: 0 8px 40px rgba(0,0,0,0.12);
}

[data-theme="light"] body,
[data-theme="light"] .app-layout,
[data-theme="light"] .app-main {
    background-color: #F0F2F5 !important;
    color: #111827 !important;
}

[data-theme="light"] .products-sticky-header {
    background: #F0F2F5 !important;
    border-bottom-color: #E5E7EB !important;
}

[data-theme="light"] .header-bar h1 {
    color: #111827 !important;
}
[data-theme="light"] .header-bar {
    border-bottom-color: #E2E8F0 !important;
}
[data-theme="light"] .sidebar-toggle-btn,
[data-theme="light"] .top-theme-toggle {
    background: #FFFFFF !important;
    border-color: #CBD5E1 !important;
    color: #334155 !important;
}

/* Stat Cards in Light Mode */
[data-theme="light"] .stat-card {
    background: #FFFFFF !important;
    border-color: #E5E7EB !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 4px 14px rgba(0,0,0,0.05) !important;
}
[data-theme="light"] .stat-card:hover {
    border-color: #D1D5DB !important;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1) !important;
}
[data-theme="light"] .stat-card .stat-label {
    color: #6B7280 !important;
}
[data-theme="light"] .stat-card .stat-value {
    color: #111827 !important;
}
[data-theme="light"] .stat-card.avail .stat-value {
    color: #059669 !important;
}
[data-theme="light"] .stat-card.unavail .stat-value {
    color: #dc2626 !important;
}
[data-theme="light"] .stat-card .stat-sub {
    color: #6B7280 !important;
}

/* Search Box & Controls in Light Mode */
[data-theme="light"] .search-box input {
    background: #FFFFFF !important;
    border-color: #E5E7EB !important;
    color: #111827 !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important;
}
[data-theme="light"] .search-box input::placeholder {
    color: #9CA3AF !important;
}
[data-theme="light"] select.cat-filter-select {
    background-color: #FFFFFF !important;
    border-color: #E5E7EB !important;
    color: #111827 !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important;
}
[data-theme="light"] select.cat-filter-select option {
    background: #FFFFFF !important;
    color: #111827 !important;
}
[data-theme="light"] .view-mode-toggle {
    background: #FFFFFF !important;
    border-color: #E5E7EB !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important;
}
[data-theme="light"] .view-btn {
    color: #6B7280 !important;
}
[data-theme="light"] .view-btn:hover:not(.active) {
    background: #F3F4F6 !important;
    color: #111827 !important;
}
[data-theme="light"] .results-info {
    color: #6B7280 !important;
}
[data-theme="light"] .results-info strong {
    color: #111827 !important;
}

/* List View Table Header in Light Mode */
[data-theme="light"] .list-table-header {
    background: #F1F5F9 !important;
    color: #1E293B !important;
    border-bottom-color: #E2E8F0 !important;
}

/* Product Card & Row in Light Mode */
[data-theme="light"] .products-scroll-wrap:has(.product-grid.list-view),
[data-theme="light"] .products-scroll-wrap.list-view-box {
    background: #FFFFFF !important;
    border-color: #E5E7EB !important;
}
[data-theme="light"] .product-card {
    background: #FFFFFF !important;
    border-color: #E5E7EB !important;
}
[data-theme="light"] .product-card.list-row {
    background: #FFFFFF !important;
    border-bottom-color: #F1F5F9 !important;
}
[data-theme="light"] .product-card.list-row:hover {
    background: #F8FAFC !important;
}
[data-theme="light"] .product-card .title,
[data-theme="light"] .product-card .name,
[data-theme="light"] .product-card h3 {
    color: #111827 !important;
}
[data-theme="light"] .product-card .price {
    color: #111827 !important;
}
[data-theme="light"] .product-card .category,
[data-theme="light"] .product-card .cat-badge {
    background: #F3F4F6 !important;
    color: #4B5563 !important;
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
    width: 100% !important;
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    height: 100% !important;
    display: flex !important;
    flex-direction: column !important;
    min-height: 0 !important;
    box-sizing: border-box;
}

/* ========== STICKY TOP HEADER & CONTROLS BOX ========== */
.products-sticky-header {
    position: relative;
    top: 0;
    z-index: 40;
    background: #0e0e10;
    margin-top: 0;
    padding-top: 0;
    padding-bottom: 6px;
    margin-bottom: 10px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

/* ========== STATS BAR (ORDER PAGE STYLE) ========== */
.stats-bar {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 12px;
    width: 100%;
}
@media (max-width: 1024px) {
    .stats-bar { 
        grid-template-columns: repeat(2, 1fr) !important; 
        gap: 10px !important;
    }
}
@media (max-width: 640px) {
    .stats-bar { 
        grid-template-columns: repeat(3, 1fr) !important; 
        gap: 6px !important;
    }
    .stat-card {
        padding: 8px 8px !important;
        gap: 8px !important;
        min-height: 54px !important;
        border-radius: 11px !important;
    }
    .stat-card .stat-icon {
        width: 30px !important;
        height: 30px !important;
        min-width: 30px !important;
        font-size: 13px !important;
        border-radius: 8px !important;
    }
    .stat-card .stat-label {
        font-size: 8.5px !important;
        letter-spacing: 0.03em !important;
    }
    .stat-card .stat-value {
        font-size: 16px !important;
    }
    .stat-card .stat-sub {
        display: none !important;
    }
    .stat-card .avail-progress-wrap {
        display: none !important;
    }
    .stat-card.top-cat {
        display: none !important; /* Hide secondary 4th card on mobile so 3 main cards stay in 1 single row */
    }
}

.stat-card {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 14px;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 14px;
    min-height: 76px;
    cursor: pointer;
    user-select: none;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.05);
    position: relative;
    overflow: hidden;
}

.stat-card:hover {
    transform: translateY(-3px);
    border-color: rgba(209, 144, 75, 0.4);
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.4), 0 0 15px rgba(209, 144, 75, 0.1);
}

.stat-card.active {
    border-color: var(--accent, #d1904b);
    background: rgba(209, 144, 75, 0.12);
    box-shadow: 0 0 0 2px rgba(209, 144, 75, 0.35), 0 8px 30px rgba(0, 0, 0, 0.4);
}
.stat-card.total.active {
    border-color: #a78bfa;
    background: rgba(139, 92, 246, 0.14);
    box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.35), 0 8px 30px rgba(0, 0, 0, 0.4);
}
.stat-card.avail.active {
    border-color: #34d399;
    background: rgba(16, 185, 129, 0.14);
    box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.35), 0 8px 30px rgba(0, 0, 0, 0.4);
}
.stat-card.unavail.active {
    border-color: #f87171;
    background: rgba(239, 68, 68, 0.14);
    box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.35), 0 8px 30px rgba(0, 0, 0, 0.4);
}
.stat-card.top-cat.active {
    border-color: #fbbf24;
    background: rgba(245, 158, 11, 0.14);
    box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.35), 0 8px 30px rgba(0, 0, 0, 0.4);
}

.stat-card .stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 21px;
    flex-shrink: 0;
    transition: transform 0.25s ease;
}

.stat-card:hover .stat-icon {
    transform: scale(1.08);
}

.stat-card.total .stat-icon {
    background: rgba(139, 92, 246, 0.22);
    color: #a78bfa;
    border: 1px solid rgba(139, 92, 246, 0.4);
}
.stat-card.avail .stat-icon {
    background: rgba(16, 185, 129, 0.22);
    color: #34d399;
    border: 1px solid rgba(16, 185, 129, 0.4);
}
.stat-card.unavail .stat-icon {
    background: rgba(239, 68, 68, 0.22);
    color: #f87171;
    border: 1px solid rgba(239, 68, 68, 0.4);
}
.stat-card.top-cat .stat-icon {
    background: rgba(245, 158, 11, 0.22);
    color: #fbbf24;
    border: 1px solid rgba(245, 158, 11, 0.4);
}

.stat-card .stat-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
.stat-card .stat-label {
    font-size: 11px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-weight: 700;
    white-space: nowrap;
}
.stat-card .stat-value {
    font-size: 26px;
    font-weight: 800;
    color: var(--text);
    line-height: 1.2;
}
.stat-card.avail .stat-value    { color: #34d399; }
.stat-card.unavail .stat-value  { color: #f87171; }
.stat-card .stat-sub {
    font-size: 11.5px;
    color: var(--text-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    opacity: 0.85;
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
    background: linear-gradient(90deg, #34d399, #3ecf70);
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

/* ========== PRODUCT CONTROLS BAR ========== */
.prod-controls-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 10px;
    flex-wrap: wrap;
    padding: 0;
}

.prod-controls-left {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
    min-width: 280px;
    flex-wrap: wrap;
}

/* View Toggle Group */
.view-mode-toggle {
    display: inline-flex;
    align-items: center;
    background: rgba(18, 18, 21, 0.8);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    padding: 3px;
    gap: 3px;
}

.view-btn {
    width: 34px;
    height: 34px;
    border-radius: 9px;
    border: none;
    background: transparent;
    color: var(--text-muted, #888);
    font-size: 14px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.view-btn:hover {
    color: var(--text, #fff);
    background: rgba(255, 255, 255, 0.05);
}

.view-btn.active {
    background: var(--accent, #d1904b);
    color: #000;
    font-weight: bold;
    box-shadow: 0 2px 8px rgba(209, 144, 75, 0.35);
}

.prod-controls-right {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

/* Search Box */
.search-box {
    position: relative;
    display: flex;
    align-items: center;
    flex: 1;
    min-width: 220px;
    max-width: 380px;
}

.search-box .search-icon {
    position: absolute;
    left: 14px;
    color: var(--text-muted, #888);
    font-size: 14px;
    pointer-events: none;
}

.search-box input {
    width: 100%;
    padding: 10px 38px 10px 38px;
    background: rgba(18, 18, 21, 0.8);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    color: var(--text, #f5f5f5);
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
    outline: none;
    transition: all 0.2s ease;
}

.search-box input:focus {
    border-color: var(--accent, #d1904b);
    background: rgba(24, 24, 28, 0.95);
    box-shadow: 0 0 0 3px rgba(209, 144, 75, 0.25);
}

.search-clear-btn {
    position: absolute;
    right: 12px;
    background: none;
    border: none;
    color: #888;
    cursor: pointer;
    font-size: 15px;
    padding: 2px;
    display: none;
    transition: color 0.2s, transform 0.2s;
}

.search-clear-btn:hover {
    color: #ff6b6b;
    transform: scale(1.1);
}

.search-clear-btn.visible {
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Category Filter Select */
.filter-select-wrap {
    position: relative;
    display: flex;
    align-items: center;
}

.filter-select-wrap .filter-icon {
    position: absolute;
    left: 14px;
    color: var(--accent, #d1904b);
    font-size: 13px;
    pointer-events: none;
}

select.cat-filter-select {
    padding: 10px 36px 10px 36px;
    background: rgba(18, 18, 21, 0.8) url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='%23d1904b' viewBox='0 0 16 16'><path d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/></svg>") no-repeat calc(100% - 12px) center;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    color: var(--text, #f5f5f5);
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
    font-weight: 500;
    appearance: none;
    -webkit-appearance: none;
    cursor: pointer;
    outline: none;
    transition: all 0.2s ease;
    min-width: 180px;
}

select.cat-filter-select:focus {
    border-color: var(--accent, #d1904b);
    box-shadow: 0 0 0 3px rgba(209, 144, 75, 0.25);
}

select.cat-filter-select option {
    background: #18181c;
    color: #ffffff;
    padding: 8px;
}

/* No Recipe Filter Button */
.filter-no-recipe-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 14px;
    background: rgba(18, 18, 21, 0.8);
    border: 1px solid rgba(239, 68, 68, 0.4);
    border-radius: 12px;
    color: #f87171;
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    white-space: nowrap;
    user-select: none;
    height: 41px;
    box-sizing: border-box;
}

.filter-no-recipe-btn i {
    font-size: 12px;
    color: #ef4444;
    transition: transform 0.2s ease;
}

.filter-no-recipe-btn .chip-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 50px;
    background: rgba(239, 68, 68, 0.2);
    color: #fca5a5;
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
}

.filter-no-recipe-btn:hover {
    background: rgba(239, 68, 68, 0.15);
    border-color: #ef4444;
    color: #ffffff;
}

.filter-no-recipe-btn:hover i {
    transform: scale(1.1);
}

.filter-no-recipe-btn.active {
    background: #ef4444 !important;
    border-color: #ef4444 !important;
    color: #ffffff !important;
    box-shadow: 0 2px 12px rgba(239, 68, 68, 0.45);
}

.filter-no-recipe-btn.active i {
    color: #ffffff !important;
}

.filter-no-recipe-btn.active .chip-count {
    background: rgba(0, 0, 0, 0.3) !important;
    color: #ffffff !important;
}

/* Light Theme Overrides */
[data-theme="light"] .filter-no-recipe-btn {
    background: #ffffff;
    border-color: #fca5a5;
    color: #dc2626;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
[data-theme="light"] .filter-no-recipe-btn:hover {
    background: #fee2e2;
    border-color: #ef4444;
    color: #b91c1c;
}
[data-theme="light"] .filter-no-recipe-btn.active {
    background: #dc2626 !important;
    border-color: #dc2626 !important;
    color: #ffffff !important;
    box-shadow: 0 2px 10px rgba(220, 38, 38, 0.35);
}
[data-theme="light"] .filter-no-recipe-btn .chip-count {
    background: #fee2e2;
    color: #dc2626;
}
[data-theme="light"] .filter-no-recipe-btn.active .chip-count {
    background: rgba(0,0,0,0.2) !important;
    color: #ffffff !important;
}

/* Add Product Button */
.btn-add-product {
    background: linear-gradient(135deg, #e8b87a, #d1904b);
    color: #000;
    padding: 9px 18px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    transition: all 0.2s ease;
    box-shadow: 0 4px 14px rgba(209, 144, 75, 0.3);
}

.btn-add-product:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(209, 144, 75, 0.45);
    color: #000;
}

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

/* Scroll Container for Product List */
.app-main {
    height: 100vh !important;
    max-height: 100vh !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
    padding: 20px 28px !important;
    box-sizing: border-box;
}

.container {
    height: 100% !important;
    display: flex !important;
    flex-direction: column !important;
    min-height: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box;
}

.products-sticky-header {
    flex: 0 0 auto !important;
}

.products-scroll-wrap {
    flex: 1 1 0% !important;
    height: 100% !important;
    min-height: 0 !important;
    max-height: 100% !important;
    overflow-y: auto !important;
    padding-right: 4px;
    border-radius: 14px;
    transition: all 0.3s ease;
}
.products-scroll-wrap:has(.product-grid.list-view),
.products-scroll-wrap.list-view-box {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 16px;
    box-shadow: var(--shadow-md);
    padding-right: 0;
}

/* Table Header for List View */
.list-table-header {
    display: none;
    align-items: center;
    padding: 10px 16px;
    background: #141416;
    border: none;
    border-bottom: 1px solid var(--border);
    border-radius: 0;
    margin-bottom: 0;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-muted, #888);
    position: sticky;
    top: 0;
    z-index: 20;
}
.list-table-header .th-no {
    width: 45px;
    flex-shrink: 0;
    text-align: center;
}
.list-table-header .th-img {
    width: 64px;
    flex-shrink: 0;
    text-align: center;
}
.list-table-header .th-content-wrap {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 14px;
    padding-left: 14px;
}
.list-table-header .th-name {
    flex: 2;
    min-width: 180px;
}
.list-table-header .th-cat {
    flex: 1;
    min-width: 120px;
    text-align: center;
}
.list-table-header .th-price {
    flex: 1;
    min-width: 100px;
    text-align: center;
}
.list-table-header .th-cogs {
    width: 95px;
    flex-shrink: 0;
    text-align: right;
}
.list-table-header .th-margin {
    width: 135px;
    flex-shrink: 0;
    text-align: right;
}
.list-table-header .th-actions {
    width: 160px;
    flex-shrink: 0;
    text-align: right;
}

/* ── List View & Metrics ── */
.col-no { display: none; }
.card-metrics-row {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 4px;
    flex-wrap: nowrap;
    white-space: nowrap;
    overflow: hidden;
    margin-top: 6px;
    width: 100%;
}
.col-cogs {
    display: inline-flex;
    align-items: center;
    font-size: 10px;
    font-weight: 600;
    color: #a1a1aa;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    padding: 1.5px 5px;
    border-radius: 5px;
    white-space: nowrap;
    flex-shrink: 0;
}
.col-margin {
    display: inline-flex;
    align-items: center;
    font-size: 10px;
    font-weight: 700;
    padding: 1.5px 5px;
    border-radius: 5px;
    white-space: nowrap;
    flex-shrink: 0;
}
.col-margin.high-margin {
    background: rgba(62, 207, 112, 0.15);
    color: #3ecf70;
    border: 1px solid rgba(62, 207, 112, 0.3);
}
.col-margin.mid-margin {
    background: rgba(209, 144, 75, 0.15);
    color: #d1904b;
    border: 1px solid rgba(209, 144, 75, 0.3);
}
.col-margin.low-margin {
    background: rgba(255, 107, 107, 0.15);
    color: #ff6b6b;
    border: 1px solid rgba(255, 107, 107, 0.3);
}
.col-margin.no-margin {
    background: rgba(255, 255, 255, 0.05);
    color: #777;
    border: 1px solid rgba(255, 255, 255, 0.08);
}
.product-grid.list-view .card-metrics-row {
    display: contents;
}
.product-grid.list-view .col-cogs,
.product-grid.list-view .col-margin {
    background: none;
    border: none;
    padding: 0;
}

.product-grid.list-view { grid-template-columns: 1fr; gap: 0; }
.product-grid.list-view .product-card {
    display: flex;
    flex-direction: row;
    align-items: center;
    padding: 6px 16px;
    background: transparent;
    border: none;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    border-radius: 0;
    box-shadow: none;
    transition: background 0.2s ease;
}
.product-grid.list-view .product-card:last-child {
    border-bottom: none;
}
.product-grid.list-view .product-card::before { display: none; }
.product-grid.list-view .col-no {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 45px;
    flex-shrink: 0;
    font-size: 12px;
    font-weight: 700;
    color: var(--text-muted, #888);
}
.product-grid.list-view .product-card .image-wrapper { width: 64px; height: 64px; aspect-ratio: 1 / 1; flex-shrink: 0; border-radius: 12px; margin: 8px 0; overflow: hidden; }
.product-grid.list-view .product-card .content {
    flex: 1; display: flex; align-items: center; gap: 14px;
    padding: 8px 0 8px 14px; flex-wrap: nowrap;
}
.product-grid.list-view .image-wrapper .no-recipe-badge,
.product-grid.list-view .image-wrapper .sold-out-badge,
.product-grid.list-view .image-wrapper .product-badge {
    display: none !important;
}
.no-recipe-inline-badge,
.sold-out-inline-badge {
    display: none;
}
.product-grid.list-view .product-card .content .name-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 2;
    min-width: 180px;
    flex-wrap: wrap;
}
.product-grid.list-view .product-card .content .name-wrap h3 {
    flex: none;
    min-width: 0;
    margin-bottom: 0;
}
.product-grid.list-view .no-recipe-inline-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: rgba(239, 68, 68, 0.14);
    border: 1px solid rgba(239, 68, 68, 0.35);
    color: #ef4444;
    font-size: 11px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 6px;
    white-space: nowrap;
    line-height: 1.3;
}
.product-grid.list-view .no-recipe-inline-badge i {
    font-size: 9px;
}
[data-theme="light"] .product-grid.list-view .no-recipe-inline-badge {
    background: #fee2e2;
    border-color: #fca5a5;
    color: #dc2626;
}
.product-grid.list-view .sold-out-inline-badge {
    display: inline-flex;
    align-items: center;
    background: rgba(245, 158, 11, 0.14);
    border: 1px solid rgba(245, 158, 11, 0.35);
    color: #f59e0b;
    font-size: 11px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 6px;
    white-space: nowrap;
    line-height: 1.3;
}
[data-theme="light"] .product-grid.list-view .sold-out-inline-badge {
    background: #fef3c7;
    border-color: #fcd34d;
    color: #d97706;
}
.product-grid.list-view .product-card .content .top-row {
    margin: 0;
    flex: 1;
    min-width: 120px;
    flex-shrink: 0;
    display: flex;
    justify-content: center;
    align-items: center;
}
.product-grid.list-view .product-card .content .price {
    margin-top: 0;
    flex: 1;
    min-width: 100px;
    flex-shrink: 0;
    text-align: center;
    font-size: 15px;
    font-weight: 700;
}
.product-grid.list-view .col-cogs {
    display: block;
    width: 95px;
    flex-shrink: 0;
    text-align: right;
}
.product-grid.list-view .col-margin {
    display: block;
    width: 135px;
    flex-shrink: 0;
    text-align: right;
}
.product-grid.list-view .product-card .content .actions {
    margin-top: 0;
    width: 160px;
    flex-shrink: 0;
    justify-content: flex-end;
}
.product-grid.list-view .product-card .content .product-id { display: none; }
.product-grid.list-view .product-card:hover {
    background: rgba(255, 255, 255, 0.04);
    transform: none;
    border-color: transparent;
    box-shadow: none;
}
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

/* availability indicator line removed */
.product-card::before { display: none; }

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
    aspect-ratio: 1 / 1;
    height: auto;
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
    text-decoration: none !important;
    cursor: pointer;
}
.product-card .content h3:hover,
.product-card:hover .content h3,
.qv-trigger:hover { color: var(--accent); text-decoration: none !important; }

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
    z-index: 5;
}

.no-recipe-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    background: rgba(239, 68, 68, 0.92);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 50px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    pointer-events: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border: 1px solid rgba(255, 255, 255, 0.25);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.35);
    z-index: 5;
    line-height: 1.2;
}
.no-recipe-badge i {
    font-size: 9px;
    color: #ffd2d2;
}
body.select-mode .no-recipe-badge {
    display: none;
}
[data-theme="light"] .no-recipe-badge {
    background: #dc2626;
    color: #ffffff;
    box-shadow: 0 2px 6px rgba(220, 38, 38, 0.35);
}

/* ========== BULK ACTION BAR ========== */
.bulk-bar {
    position: fixed;
    bottom: -200px;
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
    .product-card .image-wrapper { aspect-ratio: 1 / 1; height: auto; }
}
@media (max-width: 480px) {
    .stats-bar { grid-template-columns: repeat(3, 1fr); }
    .product-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
    .product-card .content { padding: 9px 10px 11px; }
    .product-card .content h3 { font-size: 13px; }
    .price { font-size: 16px; }
    .product-card .content .actions { display: none; }
    .product-card .image-wrapper { aspect-ratio: 1 / 1; height: auto; }
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

/* ── Mobile Product Card Actions Layout ── */
@media (max-width: 640px) {
    .product-grid {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 10px !important;
    }
    .product-card .content {
        padding: 10px 10px 12px !important;
    }
    .product-card .content h3 {
        font-size: 13px !important;
        margin-bottom: 2px !important;
    }
    .product-card .content .top-row {
        margin-bottom: 4px !important;
    }
    .product-card .content .category-badge {
        font-size: 8.5px !important;
        padding: 2px 7px !important;
    }
    .product-card .content .product-id {
        font-size: 8.5px !important;
    }
    .card-metrics-row {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        margin-top: 4px !important;
    }
    .product-card .content .price {
        font-size: 15px !important;
        margin-top: 0 !important;
        margin-left: 0 !important;
        padding: 0 !important;
    }
    .product-card .content .actions {
        display: flex !important;
        align-items: center !important;
        justify-content: flex-end !important;
        gap: 4px !important;
        margin-top: 0 !important;
    }
    .product-card .content .actions .btn-action {
        width: 28px !important;
        height: 28px !important;
        min-width: 28px !important;
        padding: 0 !important;
        border-radius: 7px !important;
        font-size: 11px !important;
        flex: none !important;
    }
    .product-card .content .actions .btn-action.edit {
        background: rgba(16, 185, 129, 0.12) !important;
        border-color: rgba(16, 185, 129, 0.3) !important;
        color: #34d399 !important;
    }
    .product-card .content .actions .btn-action.delete {
        background: rgba(239, 68, 68, 0.12) !important;
        border-color: rgba(239, 68, 68, 0.3) !important;
        color: #f87171 !important;
    }
    .product-card .content .actions .btn-action.edit span,
    .product-card .content .actions .btn-action.avail-on span,
    .product-card .content .actions .btn-action.avail-off span {
        display: none !important;
    }
    .product-card .content .actions .btn-action.avail-on,
    .product-card .content .actions .btn-action.avail-off {
        display: none !important; /* Hide On/Off toggle text button in compact mobile card, keeping Edit and Delete icons */
    }

    /* Small Screen: Only Grid View (Hide list/grid switcher) */
    .view-mode-toggle {
        display: none !important;
    }

    /* ── Mobile 2-Column Search & Category Filter ── */
    .prod-controls-bar {
        display: flex !important;
        flex-direction: column !important;
        gap: 8px !important;
        margin-bottom: 8px !important;
    }
    .prod-controls-left {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 8px !important;
        width: 100% !important;
        min-width: 0 !important;
    }
    .search-box {
        width: 100% !important;
        min-width: 0 !important;
        max-width: 100% !important;
        background: transparent !important;
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    .search-box input {
        width: 100% !important;
        height: 40px !important;
        font-size: 12px !important;
        font-weight: 500 !important;
        padding: 0 10px 0 32px !important;
        border-radius: 10px !important;
        border: 1px solid #E5E7EB !important;
        background: #FFFFFF !important;
        color: #111827 !important;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important;
        box-sizing: border-box !important;
    }
    [data-theme="dark"] .search-box input,
    html:not([data-theme="light"]) .search-box input {
        background: rgba(18, 18, 21, 0.8) !important;
        border-color: rgba(255, 255, 255, 0.1) !important;
        color: #f5f5f5 !important;
    }
    .search-box .search-icon {
        left: 11px !important;
        font-size: 12px !important;
    }
    .filter-select-wrap {
        width: 100% !important;
        min-width: 0 !important;
    }
    select.cat-filter-select {
        width: 100% !important;
        min-width: 0 !important;
        height: 40px !important;
        font-size: 12px !important;
        font-weight: 500 !important;
        padding: 0 24px 0 28px !important;
        border-radius: 10px !important;
        border: 1px solid #E5E7EB !important;
        background-color: #FFFFFF !important;
        color: #111827 !important;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important;
        background-position: calc(100% - 8px) center !important;
        text-overflow: ellipsis !important;
        box-sizing: border-box !important;
    }
    [data-theme="dark"] select.cat-filter-select,
    html:not([data-theme="light"]) select.cat-filter-select {
        background-color: rgba(18, 18, 21, 0.8) !important;
        border-color: rgba(255, 255, 255, 0.1) !important;
        color: #f5f5f5 !important;
    }
    .filter-select-wrap .filter-icon {
        left: 10px !important;
        font-size: 11px !important;
    }
    .prod-controls-right {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        width: 100% !important;
        gap: 8px !important;
    }
    .results-info {
        font-size: 11.5px !important;
    }
    .btn-add-product {
        padding: 6px 12px !important;
        font-size: 11.5px !important;
        border-radius: 8px !important;
    }
}
</style>
</head>

<body>
<div class="flex h-screen w-screen overflow-hidden app-layout">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<main class="app-main flex-1 h-full overflow-hidden p-6 flex flex-col">

<!-- ========== MAIN ========== -->
<div class="container">
    <div class="products-sticky-header">
        <?php $page_title = __('nav_products', 'Products'); require __DIR__ . '/header_bar.php'; ?>

        <!-- ── Stats Bar ── -->
        <div class="stats-bar">
            <div class="stat-card total active" id="statTotal" data-stat="total" role="button" tabindex="0" title="Click to show all products">
                <div class="stat-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
                <div class="stat-body">
                    <div class="stat-label"><?= __('total', 'Total') ?></div>
                    <div class="stat-value"><?= $totalProducts ?></div>
                    <div class="stat-sub"><?= __('nav_products', 'products') ?></div>
                </div>
            </div>

            <div class="stat-card avail" id="statAvail" data-stat="avail" role="button" tabindex="0" title="Click to filter active products">
                <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                <div class="stat-body">
                    <div class="stat-label"><?= __('active', 'Active') ?></div>
                    <div class="stat-value"><?= $availCount ?></div>
                    <div class="stat-sub"><?= __('products_on_menu', 'products on menu') ?></div>
                </div>
            </div>

            <div class="stat-card unavail" id="statUnavail" data-stat="unavail" role="button" tabindex="0" title="Click to filter inactive products">
                <div class="stat-icon"><i class="fa-solid fa-circle-xmark"></i></div>
                <div class="stat-body">
                    <div class="stat-label"><?= __('inactive', 'Inactive') ?></div>
                    <div class="stat-value"><?= $unavailCount ?></div>
                    <div class="stat-sub"><?= $unavailCount ?> <?= __('off_menu', 'items off menu') ?></div>
                </div>
            </div>

            <?php if ($top): ?>
            <div class="stat-card top-cat" id="statTopCat" data-stat="top-cat" data-cat="<?= htmlspecialchars($top) ?>" role="button" tabindex="0" title="Click to filter by top category (<?= htmlspecialchars($catNames[$top] ?? $top) ?>)">
                <div class="stat-icon"><i class="fa-solid fa-trophy"></i></div>
                <div class="stat-body">
                    <div class="stat-label"><?= __('top_category', 'Top Category') ?></div>
                    <div class="stat-value" style="font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($catNames[$top] ?? $top) ?></div>
                    <div class="stat-sub"><?= $catCounts[$top] ?> <?= __('nav_products', 'products') ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Controls & Filter Bar ── -->
        <div class="prod-controls-bar">
            <div class="prod-controls-left">
                <!-- Search Box with Clear Button -->
                <div class="search-box">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" id="searchInput" placeholder="<?= __('search_products_ph', 'Search products… (Press /)') ?>" autocomplete="off">
                    <button class="search-clear-btn" id="searchClear" title="Clear search (Esc)">
                        <i class="fa-solid fa-circle-xmark"></i>
                    </button>
                </div>

                <!-- Category Filter Select -->
                <div class="filter-select-wrap">
                    <i class="fa-solid fa-filter filter-icon"></i>
                    <select id="catSelect" class="cat-filter-select">
                        <option value="all">All Categories (<?= $totalProducts ?>)</option>
                        <?php foreach ($filterCats as $fc): ?>
                        <option value="<?= htmlspecialchars($fc['slug']) ?>">
                            <?= htmlspecialchars($fc['name'] ?? $fc['slug']) ?> (<?= (int)$fc['count'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- No Recipe Filter Button -->
                <button type="button" 
                        id="noRecipeFilterBtn" 
                        class="filter-no-recipe-btn" 
                        onclick="toggleNoRecipeFilter()" 
                        title="Filter products without recipe">
                    <i class="fa-solid fa-mortar-pestle"></i>
                    <span><?= htmlspecialchars(__('no_recipe', 'No Recipe')) ?></span>
                    <span class="chip-count" id="noRecipeCountBadge"><?= $noRecipeCount ?></span>
                </button>
            </div>

            <div class="prod-controls-right">
                <div class="results-info">
                    Showing <strong id="resultsCount"><?= $totalProducts ?></strong> of <strong id="totalCountSummary"><?= $totalProducts ?></strong> products
                </div>

                <!-- View Mode Switcher -->
                <div class="view-mode-toggle">
                    <button type="button" class="view-btn active" id="gridViewBtn" title="Grid View">
                        <i class="fa-solid fa-border-all"></i>
                    </button>
                    <button type="button" class="view-btn" id="listViewBtn" title="List View">
                        <i class="fa-solid fa-list-ul"></i>
                    </button>
                </div>

                <?php if ($_can_manage_products): ?>
                <a href="add_product.php" class="btn-add-product">
                    <i class="fa-solid fa-plus"></i> Add Product
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="products-scroll-wrap">
        <!-- ── Table Header (List View Only) ── -->
        <div class="list-table-header" id="listTableHeader" style="display:none">
            <div class="th-col th-no">No</div>
            <div class="th-col th-img">Image</div>
            <div class="th-content-wrap">
                <div class="th-col th-name">Product Name</div>
                <div class="th-col th-cat">Category</div>
                <div class="th-col th-price">Sell Price</div>
                <div class="th-col th-actions">Actions</div>
            </div>
        </div>

        <!-- ── Product Grid ── -->
        <div class="product-grid" id="productGrid">

            <?php if ($totalProducts > 0): ?>
                <?php foreach ($products as $i => $row):
                    $src = get_image_url($row['image'], 'uploads/no-image.png');
                    $available = (int)($row['is_available'] ?? 1);
                    $costP = (float)($row['cost_price'] ?? 0);
                    $sellP = (float)$row['price'];
                    $mDol  = $sellP - $costP;
                    $mPct  = $sellP > 0 ? (($sellP - $costP) / $sellP) * 100 : 0;
                    $hasRecipe = ((int)($row['recipe_count'] ?? 0) > 0) ? '1' : '0';
                ?>
                <div class="product-card <?= $available ? '' : 'unavailable' ?>"
                     data-category="<?= htmlspecialchars($row['category'] ?: 'Uncategorized') ?>"
                     data-name="<?= htmlspecialchars(strtolower($row['name'])) ?>"
                     data-price="<?= (float)$row['price'] ?>"
                     data-id="<?= (int)$row['product_id'] ?>"
                     data-avail="<?= $available ?>"
                     data-badge="<?= product_badge_label($row) !== '' ? '1' : '0' ?>"
                     data-has-recipe="<?= $hasRecipe ?>"
                     style="animation-delay:<?= min($i * 0.04, 0.6) ?>s">

                    <!-- Selection overlay (intercepts clicks in select mode) -->
                    <div class="card-select-overlay" onclick="toggleCardSelection(<?= $row['product_id'] ?>, this.closest('.product-card'))"></div>

                    <!-- Checkbox indicator -->
                    <div class="card-checkbox"><i class="fa-solid fa-check"></i></div>

                    <!-- Row Number -->
                    <div class="col-no"><?= $i + 1 ?></div>

                    <div class="image-wrapper">
                        <img src="<?= htmlspecialchars($src) ?>"
                             alt="<?= htmlspecialchars($row['name']) ?>"
                             loading="lazy"
                             onerror="this.src='uploads/no-image.png'">
                        <?php if (!$available): ?>
                        <div class="sold-out-badge">Inactive</div>
                        <?php endif; ?>
                        <?php if ((int)($row['recipe_count'] ?? 0) === 0): ?>
                        <div class="no-recipe-badge" title="No Recipe Linked"><i class="fa-solid fa-mortar-pestle"></i> <?= htmlspecialchars(__('no_recipe', 'No Recipe')) ?></div>
                        <?php endif; ?>
                        <?php $__badge = product_badge_label($row); if ($__badge !== ''): ?>
                        <span class="product-badge"><?= htmlspecialchars($__badge) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="content">
                        <div class="name-wrap">
                            <h3 class="qv-trigger"
                                title="Click to preview"
                                onclick="openQV(<?= (int)$row['product_id'] ?>)"><?= htmlspecialchars($row['name']) ?></h3>
                            <?php if ((int)($row['recipe_count'] ?? 0) === 0): ?>
                            <span class="no-recipe-inline-badge" title="No Recipe Linked"><i class="fa-solid fa-mortar-pestle"></i> <?= htmlspecialchars(__('no_recipe', 'No Recipe')) ?></span>
                            <?php endif; ?>
                            <?php if (!$available): ?>
                            <span class="sold-out-inline-badge">Inactive</span>
                            <?php endif; ?>
                        </div>
                        <div class="top-row">
                            <span class="category-badge"><?= htmlspecialchars($catNames[$row['category']] ?? ($row['category'] ?: 'Uncategorized')) ?></span>
                            <span class="product-id">#<?= $row['product_id'] ?></span>
                        </div>
                        <div class="card-metrics-row">
                            <span class="price" data-pid="<?= $row['product_id'] ?>" title="Double-click to edit price">
                                $<?= number_format($row['price'], 2) ?>
                            </span>

                            <?php if ($_can_manage_products): ?>
                            <div class="actions">
                                <a href="edit_product.php?id=<?= $row['product_id'] ?>" onclick="openEditProductModal(<?= (int)$row['product_id'] ?>, event)" class="btn-action edit" title="Edit Product">
                                    <i class="fa-solid fa-pen-to-square"></i> <span>Edit</span>
                                </a>
                                <button type="button" class="btn-action <?= $available ? 'avail-on' : 'avail-off' ?>"
                                        onclick="toggleAvailability(<?= $row['product_id'] ?>, this, event)"
                                        title="<?= $available ? 'Mark as inactive' : 'Mark as available' ?>">
                                    <i class="fa-solid <?= $available ? 'fa-eye' : 'fa-eye-slash' ?>"></i>
                                    <span><?= $available ? 'On' : 'Off' ?></span>
                                </button>
                                <button type="button" class="btn-action delete"
                                        onclick="confirmDelete(<?= $row['product_id'] ?>, '<?= htmlspecialchars(addslashes($row['name'])) ?>', event)"
                                        title="Delete Product">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($missing[(int)$row['product_id']])): ?>
                        <span class="badge badge-danger" title="has_sizes is on but no size prices set">missing size prices</span>
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
    </div><!-- /products-scroll-wrap -->
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

<!-- ========== EDIT PRODUCT MODAL (SEAMLESS IN-PAGE MODAL) ========== -->
<div id="productEditModalBackdrop" style="position: fixed; inset: 0; z-index: 99999; display: none; opacity: 0; transition: opacity 0.2s ease; background: rgba(0,0,0,0.8); backdrop-filter: blur(8px);">
    <iframe id="productEditFrame" src="" style="width: 100%; height: 100%; border: none; background: transparent;" allowtransparency="true"></iframe>
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
        <div id="qvSoldBadge" class="qv-sold-badge">Inactive</div>
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
window.CAT_NAMES = <?= json_encode($catNames, JSON_UNESCAPED_UNICODE) ?>;
// ── Card data ──
let allCards    = Array.from(document.querySelectorAll('.product-card'));
let activeFilter       = 'all';
let activeAvail        = 'all'; // 'all', '1' (Available), '0' (Inactive)
let activeSearch       = '';
let activeSort         = 'default';
let activePriceMin     = 0;
let activePriceMax     = 99999;
let activeBadge        = 'all';
let activeRecipeFilter = 'all'; // 'all', 'no_recipe'
let selectMode         = false;
let selectedIds        = new Set();

// Category Select Change Listener
document.getElementById('catSelect')?.addEventListener('change', function () {
    activeFilter = this.value;
    applyFilters();
});

// No Recipe Filter Toggle
function toggleNoRecipeFilter() {
    const btn = document.getElementById('noRecipeFilterBtn');
    if (activeRecipeFilter === 'no_recipe') {
        activeRecipeFilter = 'all';
        btn?.classList.remove('active');
    } else {
        activeRecipeFilter = 'no_recipe';
        btn?.classList.add('active');
    }
    applyFilters();
}

// ─────────────────────────────────────────────
// STAT CARDS FILTER
// ─────────────────────────────────────────────
document.querySelectorAll('.stat-card').forEach(card => {
    card.addEventListener('click', function () {
        const type = this.dataset.stat;
        if (type === 'total') {
            activeAvail = 'all';
            activeFilter = 'all';
            const catSel = document.getElementById('catSelect');
            if (catSel) catSel.value = 'all';
        } else if (type === 'avail') {
            activeAvail = activeAvail === '1' ? 'all' : '1';
        } else if (type === 'unavail') {
            activeAvail = activeAvail === '0' ? 'all' : '0';
        } else if (type === 'top-cat') {
            const cat = this.dataset.cat;
            if (activeFilter === cat) {
                activeFilter = 'all';
                const catSel = document.getElementById('catSelect');
                if (catSel) catSel.value = 'all';
            } else {
                activeFilter = cat;
                const catSel = document.getElementById('catSelect');
                if (catSel) catSel.value = cat;
            }
        }
        applyFilters();
    });
});

function updateStatCardsUI() {
    const statTotal   = document.getElementById('statTotal');
    const statAvail   = document.getElementById('statAvail');
    const statUnavail = document.getElementById('statUnavail');
    const statTopCat  = document.getElementById('statTopCat');

    if (statTotal)   statTotal.classList.toggle('active', activeAvail === 'all' && activeFilter === 'all');
    if (statAvail)   statAvail.classList.toggle('active', activeAvail === '1');
    if (statUnavail) statUnavail.classList.toggle('active', activeAvail === '0');
    if (statTopCat) {
        const topCat = statTopCat.dataset.cat;
        statTopCat.classList.toggle('active', activeFilter === topCat);
    }
}

function updateStatCounts() {
    const total = allCards.length;
    const avail = allCards.filter(c => c.dataset.avail === '1').length;
    const unavail = total - avail;
    const noRecipe = allCards.filter(c => c.dataset.hasRecipe === '0').length;

    const elTotal = document.querySelector('#statTotal .stat-value');
    if (elTotal) elTotal.textContent = total;

    const elAvail = document.querySelector('#statAvail .stat-value');
    if (elAvail) elAvail.textContent = avail;

    const elUnavail = document.querySelector('#statUnavail .stat-value');
    if (elUnavail) elUnavail.textContent = unavail;

    const elTotalSum = document.getElementById('totalCountSummary');
    if (elTotalSum) elTotalSum.textContent = total;

    const elNoRecipeCount = document.getElementById('noRecipeCountBadge');
    if (elNoRecipeCount) elNoRecipeCount.textContent = noRecipe;
}

function updateRowNumbersAndCounts() {
    updateStatCounts();
    const visibleCards = Array.from(document.querySelectorAll('#productGrid .product-card:not([style*="display: none"])'));
    visibleCards.forEach((c, idx) => {
        const colNo = c.querySelector('.col-no');
        if (colNo) colNo.textContent = idx + 1;
    });

    const resCount = document.getElementById('resultsCount');
    if (resCount) resCount.textContent = visibleCards.length;

    const filterEmpty = document.getElementById('filterEmpty');
    if (filterEmpty) filterEmpty.style.display = visibleCards.length === 0 ? 'block' : 'none';
}

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

if (searchInput) {
    searchInput.addEventListener('input', function () {
        activeSearch = this.value.trim().toLowerCase();
        if (searchClear) searchClear.classList.toggle('visible', activeSearch.length > 0);
        applyFilters();
    });
}
if (searchClear) {
    searchClear.addEventListener('click', function () {
        if (searchInput) searchInput.value = '';
        activeSearch = '';
        this.classList.remove('visible');
        if (searchInput) searchInput.focus();
        applyFilters();
    });
}

// ─────────────────────────────────────────────
// SORT
// ─────────────────────────────────────────────
document.getElementById('sortSelect')?.addEventListener('change', function () {
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
        const availMatch = activeAvail === 'all' || card.dataset.avail === activeAvail;
        const nameMatch  = card.dataset.name.includes(activeSearch);
        const price      = parseFloat(card.dataset.price);
        const priceMatch = price >= activePriceMin && price <= activePriceMax;
        const hasBadge    = card.dataset.badge === '1';
        const badgeMatch  = activeBadge === 'all' || (activeBadge === 'has' ? hasBadge : !hasBadge);
        const hasRecipe   = card.dataset.hasRecipe === '1';
        const recipeMatch = activeRecipeFilter === 'all' || (activeRecipeFilter === 'no_recipe' && !hasRecipe);
        return catMatch && availMatch && nameMatch && priceMatch && badgeMatch && recipeMatch;
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
    updateStatCardsUI();
    renderPage();
}

function renderPage() {
    const total = lastFiltered.length;

    allCards.forEach(c => { c.style.display = 'none'; c.style.animation = 'none'; });

    const grid = document.getElementById('productGrid');
    lastFiltered.forEach((card, i) => {
        card.style.display = '';
        const colNo = card.querySelector('.col-no');
        if (colNo) colNo.textContent = i + 1;
        grid.appendChild(card);
        void card.offsetWidth;
        card.style.animation = `cardIn 0.25s ease ${Math.min(i * 0.02, 0.4)}s both`;
    });

    document.getElementById('resultsCount').textContent = total;
    document.getElementById('filterEmpty').style.display = total === 0 ? 'block' : 'none';

    const pgWrap = document.getElementById('pgWrap');
    if (pgWrap) pgWrap.style.display = 'none';
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
const listHeader   = document.getElementById('listTableHeader');

if (gridViewBtn) {
    gridViewBtn.addEventListener('click', () => {
        productGrid.classList.remove('list-view');
        document.querySelector('.products-scroll-wrap')?.classList.remove('list-view-box');
        gridViewBtn.classList.add('active');
        if (listViewBtn) listViewBtn.classList.remove('active');
        if (listHeader) listHeader.style.display = 'none';
        localStorage.setItem('productView', 'grid');
    });
}
if (listViewBtn) {
    listViewBtn.addEventListener('click', () => {
        productGrid.classList.add('list-view');
        document.querySelector('.products-scroll-wrap')?.classList.add('list-view-box');
        listViewBtn.classList.add('active');
        if (gridViewBtn) gridViewBtn.classList.remove('active');
        if (listHeader) listHeader.style.display = 'flex';
        localStorage.setItem('productView', 'list');
    });
}

// ─────────────────────────────────────────────
// SELECT MODE
// ─────────────────────────────────────────────
function toggleSelectMode() {
    if (selectMode) { exitSelectMode(); return; }
    selectMode = true;
    document.body.classList.add('select-mode');
    document.getElementById('selectModeBtn')?.classList.add('select-active');
    const selectBtnText = document.getElementById('selectBtnText');
    if (selectBtnText) selectBtnText.textContent = 'Done';
}

function exitSelectMode() {
    selectMode = false;
    document.body.classList.remove('select-mode');
    document.getElementById('selectModeBtn')?.classList.remove('select-active');
    const selectBtnText = document.getElementById('selectBtnText');
    if (selectBtnText) selectBtnText.textContent = 'Select';
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
                b.textContent = 'Inactive';
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
                    updateRowNumbersAndCounts();
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
        showToast('Duplicated! Opening editor…');
        setTimeout(() => { openEditProductModal(data.new_id); }, 900);
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
    btn.title     = isAvail ? 'Mark as inactive' : 'Mark as available';
    btn.textContent = '';
    const icon = document.createElement('i');
    icon.className = 'fa-solid ' + (isAvail ? 'fa-eye' : 'fa-eye-slash');
    btn.append(icon, document.createTextNode(' ' + (isAvail ? 'On' : 'Off')));
}

function toggleAvailability(id, btn, e) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
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
                b.textContent = 'Inactive';
                card.querySelector('.image-wrapper').appendChild(b);
            }
            showToast('Marked as inactive', 'error');
        }
        updateStatCounts();
        if (typeof activeAvail !== 'undefined' && activeAvail !== 'all') {
            const matches = (activeAvail === '1' && avail === 1) || (activeAvail === '0' && avail === 0);
            if (!matches) {
                card.style.display = 'none';
            }
        }
        btn.disabled = false;
    })
    .catch(() => { btn.disabled = false; });
}

// ─────────────────────────────────────────────
// DELETE MODAL
// ─────────────────────────────────────────────
let deleteTarget = null;

function confirmDelete(id, name, ev) {
    if (ev) { ev.preventDefault(); ev.stopPropagation(); }
    deleteTarget = id;
    document.getElementById('deleteProductName').textContent = name;
    document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('open');
    deleteTarget = null;
}
document.getElementById('deleteConfirmBtn').addEventListener('click', async function (e) {
    if (e) e.preventDefault();
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
                    updateRowNumbersAndCounts();
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
                ? `<a href="edit_product.php?id=${p.id}" onclick="closeQV(); openEditProductModal(${p.id}, event);" class="qv-btn edit"><i class="fa-solid fa-pen-to-square"></i> Edit Product</a>`
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
                     <span class="qv-cat">${_qvEscHtml((window.CAT_NAMES && window.CAT_NAMES[p.category]) || p.category || 'Uncategorized')}</span>
                     <div class="qv-status ${p.is_available ? 'available' : 'unavailable'}">
                         <i class="fa-solid ${p.is_available ? 'fa-circle-check' : 'fa-circle-xmark'}"></i>
                         ${p.is_available ? 'Available' : 'Inactive'}
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
// SEAMLESS IN-PAGE EDIT MODAL (NO REFRESH / NO SCROLL)
// ─────────────────────────────────────────────
function openEditProductModal(id, e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    const backdrop = document.getElementById('productEditModalBackdrop');
    const frame = document.getElementById('productEditFrame');
    if (!backdrop || !frame) {
        window.location.href = 'edit_product.php?id=' + id;
        return;
    }
    frame.src = 'edit_product.php?id=' + id + '&modal=1';
    backdrop.style.display = 'flex';
    requestAnimationFrame(() => {
        backdrop.style.opacity = '1';
    });
    document.body.style.overflow = 'hidden';
}

function closeEditProductModal() {
    const backdrop = document.getElementById('productEditModalBackdrop');
    const frame = document.getElementById('productEditFrame');
    if (!backdrop) return;
    backdrop.style.opacity = '0';
    setTimeout(() => {
        backdrop.style.display = 'none';
        if (frame) frame.src = 'about:blank';
        document.body.style.overflow = '';
    }, 200);
}

// Listen for messages from edit_product iframe
window.addEventListener('message', function(e) {
    if (!e.data || typeof e.data !== 'object') return;
    
    if (e.data.type === 'CLOSE_EDIT_MODAL') {
        closeEditProductModal();
    } else if (e.data.type === 'PRODUCT_SAVED') {
        closeEditProductModal();
        if (typeof showToast === 'function') {
            showToast(e.data.message || 'Product & recipe updated successfully!', 'success');
        }
        
        // Update product card in DOM without reloading or scrolling
        const p = e.data.product;
        const pid = e.data.productId;
        if (p && pid) {
            const card = document.querySelector(`.product-card[data-id="${pid}"]`);
            if (card) {
                if (p.name) {
                    card.setAttribute('data-name', p.name.toLowerCase());
                    const nameEl = card.querySelector('.qv-trigger');
                    if (nameEl) nameEl.textContent = p.name;
                }
                if (p.price !== undefined) {
                    const priceNum = parseFloat(p.price);
                    card.setAttribute('data-price', priceNum);
                    const priceEl = card.querySelector('.price');
                    if (priceEl) priceEl.textContent = '$' + priceNum.toFixed(2);
                }
                if (p.category) {
                    card.setAttribute('data-category', p.category);
                    const catBadge = card.querySelector('.category-badge');
                    if (catBadge) {
                        const catDisplay = (window.CAT_NAMES && window.CAT_NAMES[p.category]) || p.category;
                        catBadge.textContent = catDisplay;
                    }
                }
                if (p.image) {
                    const img = card.querySelector('.image-wrapper img');
                    if (img) img.src = p.image;
                }
                if (p.is_available !== undefined) {
                    const isAvail = parseInt(p.is_available, 10) === 1;
                    card.setAttribute('data-avail', isAvail ? '1' : '0');
                    if (isAvail) {
                        card.classList.remove('unavailable');
                        const soldBadge = card.querySelector('.sold-out-badge');
                        if (soldBadge) soldBadge.remove();
                    } else {
                        card.classList.add('unavailable');
                        let soldBadge = card.querySelector('.sold-out-badge');
                        if (!soldBadge) {
                            soldBadge = document.createElement('div');
                            soldBadge.className = 'sold-out-badge';
                            soldBadge.textContent = 'Inactive';
                            card.querySelector('.image-wrapper')?.appendChild(soldBadge);
                        }
                    }
                    const availBtn = card.querySelector('.actions .avail-on, .actions .avail-off');
                    if (availBtn) {
                        availBtn.className = `btn-action ${isAvail ? 'avail-on' : 'avail-off'}`;
                        availBtn.title = isAvail ? 'Mark as inactive' : 'Mark as available';
                        availBtn.innerHTML = `<i class="fa-solid ${isAvail ? 'fa-eye' : 'fa-eye-slash'}"></i> <span>${isAvail ? 'On' : 'Off'}</span>`;
                    }
                }
                if (p.badge_text !== undefined) {
                    let badgeEl = card.querySelector('.product-badge');
                    if (p.badge_text) {
                        card.setAttribute('data-badge', '1');
                        if (!badgeEl) {
                            badgeEl = document.createElement('span');
                            badgeEl.className = 'product-badge';
                            card.querySelector('.image-wrapper')?.appendChild(badgeEl);
                        }
                        badgeEl.textContent = p.badge_text;
                    } else {
                        card.setAttribute('data-badge', '0');
                        if (badgeEl) badgeEl.remove();
                    }
                }

                if (p.has_recipe !== undefined || p.recipe_count !== undefined) {
                    const hasRec = (p.has_recipe === 1 || p.has_recipe === true || parseInt(p.recipe_count, 10) > 0);
                    card.setAttribute('data-has-recipe', hasRec ? '1' : '0');
                    
                    // 1. Grid View Badge (.no-recipe-badge in .image-wrapper)
                    const imgWrapper = card.querySelector('.image-wrapper');
                    let noRecBadge = card.querySelector('.image-wrapper .no-recipe-badge');
                    if (!hasRec) {
                        if (!noRecBadge && imgWrapper) {
                            noRecBadge = document.createElement('div');
                            noRecBadge.className = 'no-recipe-badge';
                            noRecBadge.title = 'No Recipe Linked';
                            noRecBadge.innerHTML = '<i class="fa-solid fa-mortar-pestle"></i> <?= htmlspecialchars(addslashes(__("no_recipe", "No Recipe"))) ?>';
                            imgWrapper.appendChild(noRecBadge);
                        }
                    } else {
                        if (noRecBadge) noRecBadge.remove();
                    }

                    // 2. List View Badge (.no-recipe-inline-badge in .name-wrap)
                    const nameWrap = card.querySelector('.content .name-wrap');
                    let noRecInline = card.querySelector('.no-recipe-inline-badge');
                    if (!hasRec) {
                        if (!noRecInline && nameWrap) {
                            noRecInline = document.createElement('span');
                            noRecInline.className = 'no-recipe-inline-badge';
                            noRecInline.title = 'No Recipe Linked';
                            noRecInline.innerHTML = '<i class="fa-solid fa-mortar-pestle"></i> <?= htmlspecialchars(addslashes(__("no_recipe", "No Recipe"))) ?>';
                            nameWrap.appendChild(noRecInline);
                        }
                    } else {
                        if (noRecInline) noRecInline.remove();
                    }

                    // 3. Refresh counters & filter
                    if (typeof updateStatCounts === 'function') {
                        updateStatCounts();
                    }
                    if (typeof filterProducts === 'function') {
                        filterProducts();
                    }
                }
                
                // Highlight updated card with a brief glow
                card.style.transition = 'box-shadow 0.3s ease, border-color 0.3s ease';
                card.style.borderColor = '#d1904b';
                card.style.boxShadow = '0 0 20px rgba(209,144,75,0.4)';
                setTimeout(() => {
                    card.style.borderColor = '';
                    card.style.boxShadow = '';
                }, 2000);
            }
        }
    }
});

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

// Category filter select listener
document.getElementById('catSelect')?.addEventListener('change', function () {
    activeFilter = this.value;
    applyFilters();
});

// Keylogger shortcut listener: Pressing '/' focuses search, 'Escape' clears search
document.addEventListener('keydown', e => {
    if (searchInput && e.key === '/' && document.activeElement !== searchInput && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') {
        e.preventDefault();
        searchInput.focus();
    }
    if (searchInput && e.key === 'Escape' && document.activeElement === searchInput) {
        searchInput.value = '';
        activeSearch = '';
        if (searchClear) searchClear.classList.remove('visible');
        applyFilters();
        searchInput.blur();
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
    if (savedView === 'list' && listViewBtn && gridViewBtn) {
        productGrid.classList.add('list-view');
        document.querySelector('.products-scroll-wrap')?.classList.add('list-view-box');
        listViewBtn.classList.add('active');
        gridViewBtn.classList.remove('active');
        if (listHeader) listHeader.style.display = 'flex';
    }
    // initial JS pagination render
    lastFiltered = [...allCards];
    renderPage();
});
</script>
<?php if ($_flash_welcome): ?>
<script>document.addEventListener('DOMContentLoaded',()=>showToast('Welcome back, <?= htmlspecialchars($_SESSION['username'] ?? 'User', ENT_QUOTES) ?>!','success'));</script>
<?php endif; ?>
</main>
</div>
</body>
</html>
