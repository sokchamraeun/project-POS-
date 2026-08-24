<?php
require 'auth.php';
require_once 'config.php';
require_once __DIR__ . '/lang.php';
$isKm = (function_exists('current_lang') && current_lang() === 'km');
if (!can('take_order')) { header('Location: dashboard.php?denied=1'); exit; }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cat_anchor_id($key) {
    $slug = strtolower(trim((string)$key));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return 'cat-' . ($slug !== '' ? $slug : 'uncategorized');
}

/* ── NAV: view_order.php (Kitchen) is for barista only; admin/manager go to dashboard ── */
$_show_kitchen_btn = ($_SESSION['role'] ?? '') === 'barista';

/* ── CART CALCULATIONS ── */
$cart = $_SESSION['cart'] ?? [];
if (function_exists('reconcile_cart_stock') && !empty($cart)) {
    reconcile_cart_stock($conn, $cart);
    $_SESSION['cart'] = $cart;
}
$cart_count = 0;
$cp_subtotal = 0.0; $cp_min_price = PHP_FLOAT_MAX; $cp_cheapest_idx = -1; $cp_item_promos = 0.0;
$_cp_fpid = defined('FREE_ITEM_PRODUCT_ID') ? (int)FREE_ITEM_PRODUCT_ID : 0;
$_cp_fname = ''; $_cp_fprice = 0.0; $_cp_fidx = -1;

foreach ($cart as $idx => $item) {
    $q = (int)($item['qty'] ?? 1); $p = (float)($item['price'] ?? 0);
    $cart_count += $q; $cp_subtotal += $p * $q;
    $cp_item_promos += (max(0, (float)($item['orig_price'] ?? $p) - $p)) * $q;
    if ($p < $cp_min_price) { $cp_min_price = $p; $cp_cheapest_idx = $idx; }
    if ($_cp_fpid > 0 && (int)($item['product_id'] ?? 0) === $_cp_fpid && $_cp_fidx < 0) {
        $_cp_fidx = $idx; $_cp_fname = $item['product_name'] ?? ''; $_cp_fprice = $p;
    }
}
if ($_cp_fpid > 0 && $_cp_fname === '') {
    $_fp_s = $conn->prepare("SELECT name, price FROM products WHERE product_id = ?");
    if ($_fp_s) { $_fp_s->bind_param("i", $_cp_fpid); $_fp_s->execute();
        if ($_fp_r = $_fp_s->get_result()->fetch_assoc()) { $_cp_fname = $_fp_r['name']; $_cp_fprice = (float)$_fp_r['price']; }
        $_fp_s->close(); }
}
$cp_cheapest_name  = ($cp_cheapest_idx >= 0) ? ($cart[$cp_cheapest_idx]['product_name'] ?? '') : '';
$cp_cheapest_price = ($cp_cheapest_idx >= 0 && $cp_min_price < PHP_FLOAT_MAX) ? $cp_min_price : 0.0;
$cp_free_name  = ($_cp_fpid > 0 && $_cp_fname !== '') ? $_cp_fname : $cp_cheapest_name;
$cp_free_price = ($_cp_fpid > 0 && $_cp_fprice > 0) ? $_cp_fprice : $cp_cheapest_price;
// BUY X GET 1 FREE — DISPLAY ONLY. Customer pays FULL price for all ordered drinks.
// The free drink is an *extra* gift on top — it does NOT reduce the total.
// $cp_buy3 is the *value* of the free drink shown in the summary row (informational).
// DO NOT subtract $cp_buy3 from $cp_after or the total — that would incorrectly undercharge.
$_cp_free_idx = ($_cp_fpid > 0 && $_cp_fidx >= 0) ? $_cp_fidx : $cp_cheapest_idx;
$cp_buy3 = (BUY_X_GET_1_ENABLED && $cart_count >= BUY_X_COUNT && $cp_min_price < PHP_FLOAT_MAX && $_cp_free_idx >= 0)
    ? floor($cart_count / BUY_X_COUNT) * $cp_free_price : 0.0;

$cp_hh = 0.0;
if (HAPPY_HOUR_ENABLED && (int)date('H') >= HAPPY_HOUR_START && (int)date('H') < HAPPY_HOUR_END)
    $cp_hh = $cp_subtotal * (HAPPY_HOUR_DISCOUNT / 100);

$cp_after = $cp_subtotal - $cp_hh;
$cp_md = $_SESSION['manual_discount'] ?? null;
$cp_manual = 0.0; $cp_manual_label = '';
if ($cp_md && (float)($cp_md['amount'] ?? 0) > 0) {
    $cp_manual = $cp_md['type'] === 'flat'
        ? min((float)$cp_md['amount'], max(0, $cp_after))
        : max(0, $cp_after) * ((float)$cp_md['amount'] / 100.0);
    $r = trim($cp_md['reason'] ?? ''); $cp_manual_label = $r ?: 'Discount';
    if ($cp_md['type'] === 'percent') $cp_manual_label .= ' (' . (int)$cp_md['amount'] . '% off)';
    $cp_after -= $cp_manual;
}
$cp_tax   = $cp_after * (TAX_RATE / 100);
$cp_total = round($cp_after + $cp_tax, 2);

/* ── LOYALTY (removed) ── */
$linked_loyalty = null;
$linked_loyalty_id_int = 0;

/* ── ADD TO EXISTING ORDER DETECTION ── */
$add_to_order_mode = isset($_GET['add_to_order']) ? (int)$_GET['add_to_order'] : 0;

/* Reaching a plain menu is the only way to begin a NEW order, so it is the moment
   the cashier has demonstrably left add-to-order mode — by a Back button, the nav,
   or the URL. Put back the cart they were building before they switched.

   Keyed on cart_stash, NOT add_to_order_id: a successful add already clears that at
   confirm_order.php:251, so keying on it would orphan the stash on the one path
   meant to work — the cashier would confirm an add and never get their drinks back.

   Clearing add_to_order_id here also closes the stale-session hole that
   confirm_order.php:95 warns about: cart.php derives its add-to-order flag from
   this key, so an abandoned flow can no longer hijack a later normal checkout. */
$cart_restored_count = 0;
$add_cart_dropped    = 0;
$add_cart_dropped_no = 0;
if ($add_to_order_mode === 0 && isset($_SESSION['cart_stash'])) {
    /* Anything still queued for the tab is abandoned by leaving — the restore
       overwrites it. Counted BEFORE the overwrite so the cashier can be told what
       was lost. Reported after the fact rather than confirmed before: a
       beforeunload prompt cannot tell an abandon from an ordinary navigation,
       fires when it should not, and gets dismissed by habit. Here we already know
       exactly what was dropped. */
    $add_cart_dropped    = count($_SESSION['cart'] ?? []);
    $add_cart_dropped_no = (int)($_SESSION['add_to_daily_no'] ?? 0);

    $_SESSION['cart']    = $_SESSION['cart_stash'];
    $cart_restored_count = count($_SESSION['cart_stash']);
    unset($_SESSION['cart_stash'], $_SESSION['add_to_order_id'],
          $_SESSION['add_to_daily_no'], $_SESSION['paylater_reopen']);
}
// Drinks held while adding to a tab — drives the notice on the add-to-order banner.
$cart_stash_count = isset($_SESSION['cart_stash']) ? count($_SESSION['cart_stash']) : 0;

$parent_loyalty     = null;
$parent_has_loyalty = false;

/* ── ACTIVE ORDERS COUNT ── */
date_default_timezone_set('Asia/Phnom_Penh');
$now_dt    = new DateTime();
$today6am  = (clone $now_dt)->setTime(6, 0, 0);
if ($now_dt < $today6am) $today6am->modify('-1 day');
$day_start = $today6am->format('Y-m-d H:i:s');
$day_end   = (clone $today6am)->modify('+1 day -1 second')->format('Y-m-d H:i:s');
$stmt_ao   = $conn->prepare("SELECT COUNT(*) FROM orders WHERE order_date >= ? AND order_date <= ?");
$stmt_ao->bind_param('ss', $day_start, $day_end);
$stmt_ao->execute();
$active_orders = (int)$stmt_ao->get_result()->fetch_row()[0];

/* ── BEST SELLER ── */
// TODO(merch): once merch volume is significant, exclude non-drink categories
// (categories.earns_points = 0) from best-seller / top-drinks stats.
$bestSellerName = null;
$bs = mysqli_query($conn, "SELECT product_name FROM order_items GROUP BY product_name ORDER BY SUM(quantity) DESC LIMIT 1");
if ($bs && $r = mysqli_fetch_assoc($bs)) $bestSellerName = $r['product_name'];

/* ── TOP SELLERS ── */
$top_sellers = [];
$ts_result = mysqli_query($conn, "SELECT p.*, COUNT(r.recipe_id) AS recipe_count, COALESCE(SUM(oi.quantity),0) AS total_sold FROM products p LEFT JOIN product_recipes r ON p.product_id = r.product_id LEFT JOIN order_items oi ON p.product_id = oi.product_id WHERE p.is_available = 1 GROUP BY p.product_id ORDER BY total_sold DESC LIMIT 12");
if ($ts_result) {
    while ($ts_row = mysqli_fetch_assoc($ts_result)) {
        if ((int)$ts_row['total_sold'] > 0) {
            if (!is_direct_drink_product($ts_row) && (int)($ts_row['recipe_count'] ?? 0) === 0) {
                continue;
            }
            $top_sellers[] = $ts_row;
            if (count($top_sellers) >= 6) break;
        }
    }
}

/* ── FETCH ALL PRODUCTS (ONLY PRODUCTS WITH LINKED RECIPES) ── */
$search_term   = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort          = $_GET['sort'] ?? 'default';
$is_price_sort = ($sort === 'price_low' || $sort === 'price_high');

$stock_sql_base = "SELECT 
    p.*,
    COUNT(r.recipe_id) AS recipe_count,
    MIN(CASE WHEN s.item_id IS NOT NULL AND (s.item_name NOT LIKE '%Packaging Set%' AND s.item_name NOT LIKE '%ឈុត%' AND s.category != 'Packaging') AND r.quantity_required > 0 THEN FLOOR(s.quantity / r.quantity_required) ELSE NULL END) AS max_servings,
    SUM(CASE WHEN s.item_id IS NOT NULL AND (s.item_name NOT LIKE '%Packaging Set%' AND s.item_name NOT LIKE '%ឈុត%' AND s.category != 'Packaging') AND (s.quantity < r.quantity_required OR s.quantity <= 0) THEN 1 ELSE 0 END) AS out_of_stock_ingredients,
    SUM(CASE WHEN s.item_id IS NOT NULL AND (s.item_name NOT LIKE '%Packaging Set%' AND s.item_name NOT LIKE '%ឈុត%' AND s.category != 'Packaging') AND s.quantity >= r.quantity_required AND s.quantity <= s.alert_level THEN 1 ELSE 0 END) AS low_stock_ingredients,
    GROUP_CONCAT(CASE WHEN s.item_id IS NOT NULL AND (s.item_name NOT LIKE '%Packaging Set%' AND s.item_name NOT LIKE '%ឈុត%' AND s.category != 'Packaging') AND (s.quantity < r.quantity_required OR s.quantity <= 0) THEN s.item_name ELSE NULL END SEPARATOR ', ') AS missing_ingredients,
    GROUP_CONCAT(CASE WHEN s.item_id IS NOT NULL AND (s.item_name NOT LIKE '%Packaging Set%' AND s.item_name NOT LIKE '%ឈុត%' AND s.category != 'Packaging') AND s.quantity >= r.quantity_required AND s.quantity <= s.alert_level THEN s.item_name ELSE NULL END SEPARATOR ', ') AS low_ingredients
FROM products p
LEFT JOIN product_recipes r ON p.product_id = r.product_id
LEFT JOIN stock_items s ON r.item_id = s.item_id AND s.is_active = 1
WHERE p.is_available = 1";

$order_by = " ORDER BY " . ($sort === 'price_low' ? "p.price ASC" : ($sort === 'price_high' ? "p.price DESC" : "p.category, p.name"));

if (!empty($search_term)) {
    $query = $stock_sql_base . " AND p.name LIKE ? GROUP BY p.product_id " . $order_by;
    $stmt_search = $conn->prepare($query);
    $like_param  = '%' . $search_term . '%';
    $stmt_search->bind_param("s", $like_param);
    $stmt_search->execute();
    $result = $stmt_search->get_result();
} else {
    $query = $stock_sql_base . " GROUP BY p.product_id " . $order_by;
    $result = mysqli_query($conn, $query);
}

$categories = []; $catIcons = [];
$_cat_res = $conn->query("SELECT slug, name, icon FROM categories WHERE is_active = 1 ORDER BY display_order");
while ($_cat_row = $_cat_res->fetch_assoc()) {
    $categories[$_cat_row['slug']] = $_cat_row['name'];
    $catIcons[$_cat_row['slug']]   = $_cat_row['icon'];
}

$products = []; $flat_products = []; $productsById = [];
$live_stock_statuses = evaluate_products_stock($conn, $_SESSION['cart'] ?? []);

while ($row = mysqli_fetch_assoc($result)) {
    $pId = (int)$row['product_id'];
    $recipeCnt = (int)($row['recipe_count'] ?? 0);
    $isDirect = is_direct_drink_product($row);

    // Make-to-order products require at least one linked recipe to be shown in the menu page
    if (!$isDirect && $recipeCnt === 0) {
        continue;
    }

    if (isset($live_stock_statuses[$pId])) {
        $st = $live_stock_statuses[$pId];
        $row['live_status'] = $st['status'];
        $row['live_reason'] = $st['reason'];
        $row['live_max_servings'] = $st['max_servings'];
        $row['is_out'] = ($st['status'] === 'out_of_stock');
    } else {
        $row['is_out'] = ((int)($row['is_available'] ?? 1) === 0);
        $row['live_reason'] = '';
        $row['live_status'] = $row['is_out'] ? 'out_of_stock' : 'in_stock';
        $row['live_max_servings'] = null;
    }
    $products[$row['category']][] = $row;
    $flat_products[] = $row;
    $productsById[$pId] = $row;
}

/* ── SIZES PER PRODUCT (removed) ── */
$sizesByProduct = [];

/* ── ADD-ONS PER PRODUCT (removed) ── */
$addonsByProduct = [];

/* ── PER-CATEGORY OPTION VISIBILITY (sweetness / ice / milk), keyed by slug & name ── */
$categoryOpts = [];
$co_res = $conn->query("SELECT slug, name, offer_sweetness, offer_ice, offer_milk, offer_addons FROM categories");
if ($co_res) {
    while ($co = $co_res->fetch_assoc()) {
        $opts = [
            'sweet'  => (int)$co['offer_sweetness'],
            'ice'    => (int)$co['offer_ice'],
            'milk'   => (int)$co['offer_milk'],
            'addons' => (int)$co['offer_addons'],
        ];
        $categoryOpts[$co['slug']] = $opts;
        if (!empty($co['name'])) {
            $categoryOpts[$co['name']] = $opts;
            $categoryOpts[strtolower($co['name'])] = $opts;
        }
    }
}

/* ── MILK OPTIONS ── */
$milkOptions = ['Fresh Milk', 'Almond Milk', 'Soy Milk', 'Oat Milk'];
$defaultMilk = 'Fresh Milk';

?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>" data-lang="<?= current_lang() ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <!-- Apply theme before paint to avoid flash -->
  <script>(function(){var t=localStorage.getItem('theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
  <title>POS | Bird's Nest Coffee</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <link rel="stylesheet" href="assets/css/menu.css?v=<?= filemtime(__DIR__.'/assets/css/menu.css') ?>">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap');

    /* ── BASE ── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100vh; width: 100%; overflow: hidden; margin: 0; padding: 0; }
    :root { --sidebar-w: 260px; }
    body, input, select, textarea, button {
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
    body {
      background: var(--bg, #ffffff);
      color: var(--text, #1a1410);
      display: flex;
      flex-direction: row;
      padding-left: 0;
      height: 100vh;
      width: 100%;
      overflow: hidden;
      margin: 0;
    }

    /* ── CART SIDEBAR HIDDEN ── */
    #cart-sidebar.hidden, .cart-panel.hidden {
      display: none !important;
    }
  </style>

  <script>
  function updateCartIconVisibility(isOpen) {
    var btn = document.getElementById('cart-toggle-btn') || document.getElementById('cartToggleBtn');
    if (btn) {
      if (isOpen) {
        btn.style.setProperty('display', 'none', 'important');
      } else {
        btn.style.setProperty('display', 'flex', 'important');
      }
    }
  }

  function openCartSidebar() {
    var s = document.getElementById('cart-sidebar') || document.getElementById('cartPanel');
    if (!s) return;
    s.classList.remove('hidden');
    s.style.setProperty('display', 'flex', 'important');
    localStorage.setItem('cart_sidebar_closed', 'false');
    updateCartIconVisibility(true);
  }
  function closeCartSidebar() {
    var s = document.getElementById('cart-sidebar') || document.getElementById('cartPanel');
    if (!s) return;
    s.classList.add('hidden');
    s.style.setProperty('display', 'none', 'important');
    localStorage.setItem('cart_sidebar_closed', 'true');
    updateCartIconVisibility(false);
  }
  function toggleCartSidebar() {
    var s = document.getElementById('cart-sidebar') || document.getElementById('cartPanel');
    if (!s) return;
    var isHidden = s.classList.contains('hidden') || s.style.display === 'none' || window.getComputedStyle(s).display === 'none';
    if (isHidden) {
      openCartSidebar();
    } else {
      closeCartSidebar();
    }
  }
  window.openCartSidebar = openCartSidebar;
  window.closeCartSidebar = closeCartSidebar;
  window.toggleCartSidebar = toggleCartSidebar;

  document.addEventListener('DOMContentLoaded', function() {
    if (localStorage.getItem('cart_sidebar_closed') === 'true') {
      closeCartSidebar();
    } else {
      openCartSidebar();
    }
  });
  </script>
  <style>

    /* ── HEADER ── */
    .menu-header {
      position: relative; z-index: 1000;
      display: flex; align-items: center; justify-content: space-between; gap: 12px;
      padding: 12px 20px;
      background: var(--bg-header, rgba(255,255,255,.97));
      backdrop-filter: blur(16px);
      border-bottom: 1px solid var(--border, #e5e7eb);
      box-shadow: 0 1px 4px rgba(0,0,0,.04);
      flex-shrink: 0;
    }
    .header-left  { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
    .header-center{ flex: 1; max-width: 480px; display: flex; align-items: center; gap: 8px; }
    .header-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .brand { display: flex; align-items: center; gap: 8px; }
    .brand img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border,#e5e7eb); flex-shrink: 0; }
    .brand-name { font-size: 15px; font-weight: 700; white-space: nowrap; color: var(--text,#1a1410); }
    .search-form  { display: flex; align-items: center; gap: 8px; width: 100%; }
    .search-inner { display: flex; align-items: center; gap: 8px; flex: 1; border-radius: 50px; padding: 7px 14px; background: var(--bg-input,#f3f4f6); border: 1px solid var(--border,#e5e7eb); }
    .search-inner input { flex: 1; border: none; outline: none; background: transparent; font-family: 'Poppins',sans-serif; font-size: 13px; color: var(--text,#1a1410); }
    .sort-select { border-radius: 50px; padding: 7px 12px; border: 1px solid var(--border,#e5e7eb); background: var(--bg-input,#f3f4f6); font-family: 'Poppins',sans-serif; font-size: 12px; outline: none; cursor: pointer; flex-shrink: 0; }
    .btn-nav { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 50px; border: 1px solid var(--border,#e5e7eb); background: var(--bg-input,#f3f4f6); text-decoration: none; color: var(--text-sec,#5a4a3a); font-size: 13px; font-weight: 500; white-space: nowrap; transition: all .25s; }
    .btn-nav:hover { background: #d1904b; color: #fff; border-color: #d1904b; }
    .btn-theme { display: flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; border: 1px solid var(--border,#e5e7eb); background: var(--bg-input,#f3f4f6); color: var(--text,#1a1410); cursor: pointer; flex-shrink: 0; }
    .badge { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; border-radius: 50%; background: #d1904b; color: #fff; font-size: 10px; font-weight: 700; }

    /* ── SLEEK GLASS PILL CART BUTTON ── */
    .cart-pill-btn {
      display: inline-flex;
      align-items: center;
      gap: 9px;
      height: 40px;
      padding: 0 14px 0 8px;
      border-radius: 9999px;
      background: linear-gradient(135deg, rgba(255, 255, 255, 0.09) 0%, rgba(255, 255, 255, 0.03) 100%);
      border: 1px solid rgba(209, 144, 75, 0.35);
      box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15), inset 0 1px 1px rgba(255, 255, 255, 0.12);
      -webkit-backdrop-filter: blur(14px);
      backdrop-filter: blur(14px);
      color: var(--text, #ffffff);
      cursor: pointer;
      transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
      -webkit-user-select: none;
      user-select: none;
      text-decoration: none;
      outline: none;
    }

    [data-theme="light"] .cart-pill-btn {
      background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
      border: 1px solid rgba(209, 144, 75, 0.35);
      box-shadow: 0 4px 14px rgba(209, 144, 75, 0.12), 0 1px 3px rgba(0, 0, 0, 0.05);
      color: #1e293b;
    }

    .cart-pill-btn:hover {
      transform: translateY(-2px);
      border-color: #d1904b;
      box-shadow: 0 8px 24px rgba(209, 144, 75, 0.28), inset 0 1px 1px rgba(255, 255, 255, 0.22);
      background: linear-gradient(135deg, rgba(209, 144, 75, 0.18) 0%, rgba(255, 255, 255, 0.06) 100%);
    }

    [data-theme="light"] .cart-pill-btn:hover {
      background: linear-gradient(135deg, #fffaf5 0%, #fef3c7 100%);
      border-color: #d1904b;
      box-shadow: 0 8px 22px rgba(209, 144, 75, 0.25);
    }

    .cart-pill-btn:active {
      transform: scale(0.96);
    }

    .cart-pill-icon-wrap {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: rgba(209, 144, 75, 0.16);
      color: #d1904b;
      font-size: 13px;
      transition: all 0.25s ease;
    }

    .cart-pill-btn:hover .cart-pill-icon-wrap {
      background: #d1904b;
      color: #ffffff;
      transform: rotate(-6deg);
    }

    .cart-pill-text {
      font-size: 13px;
      font-weight: 600;
      letter-spacing: 0.2px;
      white-space: nowrap;
    }

    .cart-pill-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 20px;
      height: 20px;
      padding: 0 6px;
      border-radius: 9999px;
      background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
      color: #1a1410;
      font-size: 11px;
      font-weight: 700;
      line-height: 1;
      box-shadow: 0 2px 8px rgba(245, 158, 11, 0.45);
      transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    /* ── POS SPLIT LAYOUT ── */
    .pos-layout {
      flex: 1;
      display: flex;
      height: calc(100vh - 61px);
      overflow: hidden;
      min-height: 0;
      background: var(--bg, #ffffff);
    }

    /* ── MENU PANEL (left, scrollable) ── */
    .menu-panel {
      flex: 1;
      display: flex;
      flex-direction: column;
      height: 100%;
      overflow: hidden;
      min-width: 0;
      background: var(--bg, #ffffff);
    }
    .menu-panel .cat-nav {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 20px 12px;
      background: var(--bg-header, rgba(255,255,255,.97));
      border-bottom: 1px solid var(--border,#e5e7eb);
      overflow-x: auto;
      flex-shrink: 0;
      position: sticky; top: 0; z-index: 50;
      -ms-overflow-style: none;
      scrollbar-width: none;
    }
    .menu-panel .cat-nav::-webkit-scrollbar {
      display: none;
    }

    .cat-icon {
      display: flex; align-items: center; justify-content: center;
      width: 42px; height: 42px;
      border-radius: 10px !important;
      background: linear-gradient(135deg, rgba(209, 144, 75, 0.15), rgba(209, 144, 75, 0.25));
      border: 1px solid rgba(209, 144, 75, 0.3);
      color: #d1904b;
      font-size: 18px;
      flex-shrink: 0;
      overflow: hidden;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }
    .cat-icon img {
      width: 100%; height: 100%;
      object-fit: cover;
      border-radius: 9px;
      display: block;
    }
    .menu-panel .menu-scroll {
      flex: 1;
      height: 100%;
      overflow-y: auto;
      padding-bottom: 20px;
    }
    /* ── Custom thin amber scrollbars — replaces default OS scrollbar ── */
    .menu-scroll { scrollbar-width: thin; scrollbar-color: rgba(209,144,75,.35) transparent; }
    .menu-scroll::-webkit-scrollbar { width: 3px; }
    .menu-scroll::-webkit-scrollbar-track { background: transparent; }
    .menu-scroll::-webkit-scrollbar-thumb { background: rgba(209,144,75,.35); border-radius: 99px; }
    .menu-scroll::-webkit-scrollbar-thumb:hover { background: rgba(209,144,75,.65); }
    #cpItems::-webkit-scrollbar { display: none; }

    /* Stand picker cells: scrolls (STAND_COUNT is configurable) but no visible
       scrollbar, matching #cpItems. overflow-x hidden or the reserved gutter
       pushes the columns wide enough to add a horizontal bar too. */
    .stand-cells {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(42px, 1fr));
      gap: 6px; max-height: 190px; overflow-y: auto; overflow-x: hidden;
      scrollbar-width: none; -ms-overflow-style: none;
    }
    .stand-cells::-webkit-scrollbar { display: none; }

    .menu-main { padding: 16px 24px; width: 100%; box-sizing: border-box; }
    .product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 18px; width: 100%; }

    /* ── ADD TO ORDER BANNER ── */
    .add-order-banner {
      background: #9b59b6; color: #fff;
      padding: 10px 20px; font-size: 13px; font-weight: 600;
      display: flex; align-items: center; gap: 8px;
      flex-shrink: 0;
    }

    /* ── CART PANEL (right, fixed width, full height flexbox) ── */
    .cart-panel {
      width: 420px;
      min-width: 340px;
      flex-shrink: 0;
      height: 100%;
      border-left: 1px solid var(--border,#e0d4c4);
      background: var(--bg-card, #fff);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      overflow: hidden;
      min-height: 0;
    }

    /* Cart header */
    .cp-header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 15px 16px; border-bottom: 1px solid var(--border,#e0d4c4);
      flex-shrink: 0;
    }
    .cp-title {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 16px;
      font-weight: 700;
      color: var(--text,#1a1410);
      letter-spacing: -.01em;
      font-family: 'Poppins', 'Kantumruy Pro', sans-serif;
    }
    .cp-title i {
      color: #c8863f;
      font-size: 1.15rem;
    }
    .cp-count {
      background: #c8863f;
      color: #fff;
      border-radius: 9999px;
      padding: 3px 14px;
      font-size: 12px;
      font-weight: 600;
      letter-spacing: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      line-height: 1.4;
      font-family: 'Poppins', 'Kantumruy Pro', sans-serif;
      box-shadow: 0 1px 3px rgba(200, 134, 63, 0.2);
    }
    .cp-clear-btn {
      background: transparent; border: 1px solid rgba(231,76,60,.5); color: #e74c3c;
      border-radius: 8px; padding: 5px 11px; font-size: 11px; font-weight: 600;
      cursor: pointer; font-family: 'Poppins',sans-serif; transition: all .2s;
      display: flex; align-items: center; gap: 5px;
    }
    .cp-clear-btn:hover { background: #e74c3c; color: #fff; border-color: #e74c3c; }

    /* Cart body: middle scrollable area for cart items and order options */
    .cp-body { display: flex; flex-direction: column; flex: 1; overflow-y: auto; min-height: 0; scrollbar-width: thin; }
    #cpItems { flex: 0 1 auto; overflow-x: hidden; overflow-y: auto; min-height: 0; scrollbar-width: none; }
    .cp-empty { flex: 1; }

    /* Empty state */
    .cp-empty {
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      height: 100%; min-height: 180px; padding: 30px 20px; text-align: center;
      color: var(--text-muted,#9a8070);
    }
    .cp-empty i { font-size: 38px; margin-bottom: 10px; opacity: .5; }
    .cp-empty p { font-size: 14px; font-weight: 500; color: var(--text-sec,#5a4a3a); }
    .cp-empty small { font-size: 11px; }

    /* Cart item row */
    .cp-item {
      display: flex; align-items: center; gap: 12px;
      padding: 12px 16px; border-bottom: 1px solid var(--border,#e0d4c4);
      transition: background .15s;
    }
    .cp-item:hover { background: var(--bg-card-hover,#fdf8f2); }
    .cp-item img { width: 54px; height: 54px; object-fit: cover; border-radius: 10px; border: 1px solid var(--border,#e0d4c4); flex-shrink: 0; }
    .cp-item-info { flex: 1; min-width: 0; }
    .cp-item-name { font-size: 13px; font-weight: 600; color: var(--text,#1a1410); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cp-item-meta { font-size: 11px; color: var(--text-sec,#5a4a3a); margin-top: 2px; }
    .cp-item-price { font-size: 13px; font-weight: 700; color: #d1904b; margin-top: 3px; }
    .cp-item-actions { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
    .cp-qty {
      display: flex; align-items: center;
      border: 1.5px solid var(--border,#e5e7eb); border-radius: 50px;
      background: var(--bg-input,#f3f4f6); overflow: hidden;
    }
    .cp-qty button {
      width: 28px; height: 28px; background: none; border: none;
      color: #d1904b; font-size: 14px; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      transition: background .15s;
    }
    .cp-qty button:hover { background: rgba(209,144,75,.18); }
    .cp-qty input[type="number"], #modalQtyInput {
      width: 38px; text-align: center; font-size: 13.5px; font-weight: 700;
      color: #1a1410 !important; background: transparent; border: none; outline: none;
      font-family: 'Poppins',sans-serif; padding: 0; cursor: text;
      -moz-appearance: textfield !important;
      appearance: textfield !important;
      -webkit-appearance: none !important;
    }
    html.dark .cp-qty input[type="number"], body.dark .cp-qty input[type="number"] {
      color: #ffffff !important;
    }
    #modalQtyInput {
      color: #0f172a !important;
      font-weight: 900 !important;
    }
    .cp-qty input[type="number"]::-webkit-inner-spin-button,
    .cp-qty input[type="number"]::-webkit-outer-spin-button,
    #modalQtyInput::-webkit-inner-spin-button,
    #modalQtyInput::-webkit-outer-spin-button {
      -webkit-appearance: none !important;
      margin: 0 !important;
      display: none !important;
    }
    .cp-remove {
      width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;
      border-radius: 50%; background: rgba(231,76,60,.08); color: #e74c3c;
      border: 1px solid rgba(231,76,60,.18); cursor: pointer; font-size: 11px; transition: all .18s;
    }
    .cp-remove:hover { background: #e74c3c; color: #fff; border-color: #e74c3c; }
    .cp-item-disc-btn {
      background: rgba(209,144,75,0.12);
      border: 1px solid rgba(209,144,75,0.35);
      color: #d1904b;
      border-radius: 6px;
      padding: 3px 8px;
      font-size: 10px;
      font-weight: 600;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      transition: all 0.2s ease;
      white-space: nowrap;
      margin-top: 2px;
    }
    .cp-item-disc-btn:hover {
      background: #d1904b;
      color: #000;
      border-color: #d1904b;
    }
    .cp-free-icon { width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; font-size: 22px; background: linear-gradient(135deg,#e8f5e9,#f0fff4); border-radius: 7px; flex-shrink: 0; }
    .cp-free-badge { background: #27ae60; color: #fff; font-size: 9px; padding: 1px 5px; border-radius: 20px; font-weight: 700; vertical-align: middle; }

    @keyframes inputShake {
      0%, 100% { transform: translateX(0); }
      20%, 60% { transform: translateX(-4px); }
      40%, 80% { transform: translateX(4px); }
    }
    .qty-limit-warning {
      animation: inputShake 0.35s ease-in-out !important;
      border-color: #ef4444 !important;
      color: #ef4444 !important;
    }
    /* ═══════════════════════════════════════════════════════════════════
       PREMIUM SENIOR UX/UI TOAST NOTIFICATION SYSTEM
       ═══════════════════════════════════════════════════════════════════ */
    #toast-container {
      position: fixed !important;
      top: 20px !important;
      left: 50% !important;
      transform: translateX(-50%) !important;
      z-index: 2147483647 !important;
      display: flex !important;
      flex-direction: column !important;
      align-items: center !important;
      gap: 8px !important;
      pointer-events: none !important;
      width: auto !important;
      max-width: calc(100vw - 32px) !important;
    }

    .toast {
      pointer-events: auto !important;
      display: inline-flex !important;
      align-items: center !important;
      gap: 10px !important;
      padding: 9px 14px 9px 10px !important;
      border-radius: 9999px !important;
      font-family: 'Poppins', 'Kantumruy Pro', sans-serif !important;
      font-size: 13px !important;
      font-weight: 600 !important;
      letter-spacing: 0.01em !important;
      white-space: nowrap !important;
      -webkit-backdrop-filter: blur(20px) !important;
      backdrop-filter: blur(20px) !important;
      background: rgba(18, 18, 24, 0.94) !important;
      color: #f9fafb !important;
      border: 1px solid rgba(255, 255, 255, 0.1) !important;
      box-shadow: 0 16px 40px -4px rgba(0, 0, 0, 0.65), 0 4px 14px rgba(0, 0, 0, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.08) !important;
      transform: translateY(-24px) scale(0.92) !important;
      opacity: 0 !important;
      transition: transform 0.36s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.22s ease !important;
      -webkit-user-select: none !important;
      user-select: none !important;
      position: relative !important;
      overflow: hidden !important;
    }

    .toast.show {
      transform: translateY(0) scale(1) !important;
      opacity: 1 !important;
    }

    .toast.hide {
      transform: translateY(-16px) scale(0.92) !important;
      opacity: 0 !important;
      transition: transform 0.22s ease-in, opacity 0.22s ease-in !important;
    }

    [data-theme="light"] .toast {
      background: rgba(255, 255, 255, 0.96) !important;
      color: #111827 !important;
      border: 1px solid rgba(229, 231, 235, 0.95) !important;
      box-shadow: 0 14px 34px -4px rgba(0, 0, 0, 0.12), 0 4px 12px rgba(0, 0, 0, 0.04), inset 0 1px 0 rgba(255, 255, 255, 1) !important;
    }

    .toast-icon-badge {
      width: 26px !important;
      height: 26px !important;
      border-radius: 50% !important;
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      flex-shrink: 0 !important;
      font-size: 12px !important;
    }

    .toast.success .toast-icon-badge {
      background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(5, 150, 105, 0.25)) !important;
      color: #10b981 !important;
      border: 1px solid rgba(16, 185, 129, 0.35) !important;
      box-shadow: 0 0 10px rgba(16, 185, 129, 0.2) !important;
    }

    .toast.warning .toast-icon-badge {
      background: linear-gradient(135deg, rgba(245, 158, 11, 0.2), rgba(217, 119, 6, 0.25)) !important;
      color: #f59e0b !important;
      border: 1px solid rgba(245, 158, 11, 0.35) !important;
      box-shadow: 0 0 10px rgba(245, 158, 11, 0.2) !important;
    }

    .toast.error .toast-icon-badge {
      background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(220, 38, 38, 0.25)) !important;
      color: #ef4444 !important;
      border: 1px solid rgba(239, 68, 68, 0.35) !important;
      box-shadow: 0 0 10px rgba(239, 68, 68, 0.2) !important;
    }

    .toast.info .toast-icon-badge {
      background: linear-gradient(135deg, rgba(59, 130, 246, 0.2), rgba(37, 99, 235, 0.25)) !important;
      color: #3b82f6 !important;
      border: 1px solid rgba(59, 130, 246, 0.35) !important;
      box-shadow: 0 0 10px rgba(59, 130, 246, 0.2) !important;
    }

    .toast-msg {
      flex: 1 !important;
      line-height: 1.4 !important;
      padding-right: 2px !important;
    }

    .toast-dismiss {
      width: 20px !important;
      height: 20px !important;
      border-radius: 50% !important;
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      background: transparent !important;
      border: none !important;
      color: #9ca3af !important;
      font-size: 10.5px !important;
      cursor: pointer !important;
      transition: all 0.15s ease !important;
      padding: 0 !important;
      margin-left: 2px !important;
      flex-shrink: 0 !important;
    }

    .toast-dismiss:hover {
      background: rgba(255, 255, 255, 0.12) !important;
      color: #ffffff !important;
    }

    [data-theme="light"] .toast-dismiss:hover {
      background: rgba(0, 0, 0, 0.08) !important;
      color: #111827 !important;
    }

    .toast-progress {
      position: absolute !important;
      bottom: 0 !important;
      left: 14px !important;
      right: 14px !important;
      height: 2px !important;
      border-radius: 99px !important;
      background: rgba(255, 255, 255, 0.1) !important;
      overflow: hidden !important;
    }

    .toast-progress-bar {
      height: 100% !important;
      width: 100% !important;
      transform-origin: left !important;
      animation: toastProgress 2.8s linear forwards !important;
    }

    .toast.success .toast-progress-bar { background: #10b981 !important; }
    .toast.warning .toast-progress-bar { background: #f59e0b !important; }
    .toast.error .toast-progress-bar   { background: #ef4444 !important; }
    .toast.info .toast-progress-bar    { background: #3b82f6 !important; }

    @keyframes toastProgress {
      from { transform: scaleX(1); }
      to   { transform: scaleX(0); }
    }

    /* ── FLY-TO-CART ANIMATION & CART ICON BUMP ── */
    .fly-to-cart-clone {
      position: fixed !important;
      pointer-events: none !important;
      z-index: 2147483646 !important;
      will-change: transform, opacity !important;
      transition: none;
    }

    @keyframes cartIconBounce {
      0%   { transform: scale(1) rotate(0deg); }
      20%  { transform: scale(1.28) rotate(-14deg); }
      40%  { transform: scale(1.22) rotate(12deg); }
      60%  { transform: scale(1.12) rotate(-6deg); }
      80%  { transform: scale(1.04) rotate(2deg); }
      100% { transform: scale(1) rotate(0deg); }
    }

    @keyframes cartBadgePop {
      0%   { transform: scale(1); }
      45%  { transform: scale(1.55); box-shadow: 0 0 16px rgba(245, 158, 11, 0.95); }
      100% { transform: scale(1); }
    }

    .cart-icon-bump {
      animation: cartIconBounce 0.55s cubic-bezier(0.175, 0.885, 0.32, 1.275) !important;
      transform-origin: center center !important;
    }

    .cart-badge-bump {
      animation: cartBadgePop 0.45s cubic-bezier(0.175, 0.885, 0.32, 1.275) !important;
      transform-origin: center center !important;
    }

    /* Summary area: pinned below the scrolling items, above the footer */
    .cp-summary { flex-shrink: 0; padding: 12px 16px; border-top: 1px solid var(--border,#e0d4c4); }
    .cp-sum-row { display: flex; justify-content: space-between; align-items: center; padding: 4px 0; font-size: 12.5px; color: var(--text-sec,#5a4a3a); }
    .cp-sum-row.discount { color: #e74c3c; }

    /* Discount toggle */
    .cp-discount-toggle {
      width: 100%; padding: 6px 10px; border-radius: 7px;
      border: 1px dashed var(--border-hover,#c9b89f);
      background: transparent; color: #d1904b;
      font-family: 'Poppins',sans-serif; font-size: 11px; font-weight: 600;
      cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 5px;
      transition: all .2s; margin: 5px 0;
    }
    .cp-discount-toggle:hover { background: rgba(209,144,75,.07); border-color: #d1904b; }
    .cp-discount-toggle.remove { color: #e74c3c; border-color: rgba(231,76,60,.35); }
    .cp-discount-toggle.remove:hover { background: rgba(231,76,60,.07); border-color: #e74c3c; }
    #cpDiscountForm { background: rgba(209,144,75,.04); border: 1px solid rgba(209,144,75,.18); border-radius: 9px; padding: 10px; margin: 4px 0; }
    .cp-dtype-row { display: flex; gap: 5px; margin-bottom: 7px; }
    .cp-dtype-btn { flex: 1; padding: 4px 0; border-radius: 6px; border: 1px solid var(--border-hover,#c9b89f); background: transparent; color: var(--text-sec,#5a4a3a); font-family: 'Poppins',sans-serif; font-size: 11px; font-weight: 600; cursor: pointer; transition: all .2s; }
    .cp-dtype-btn.active { background: #d1904b; color: #000; border-color: #d1904b; }
    .cp-disc-inputs { display: flex; flex-direction: column; gap: 5px; margin-bottom: 7px; }
    .cp-disc-inputs input { width: 100%; padding: 6px 9px; border-radius: 7px; border: 1px solid var(--border-hover,#c9b89f); background: var(--bg-card,#fff); color: var(--text,#1a1410); font-family: 'Poppins',sans-serif; font-size: 12px; outline: none; }
    .cp-disc-inputs input:focus { border-color: #d1904b; }
    .cp-disc-actions { display: flex; gap: 5px; }
    .cp-btn-apply { flex: 1; padding: 6px 0; background: #d1904b; color: #000; border: none; border-radius: 7px; font-family: 'Poppins',sans-serif; font-size: 12px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 4px; transition: opacity .2s; }
    .cp-btn-apply:hover { opacity: .88; }
    .cp-btn-cancel { padding: 6px 9px; background: transparent; color: var(--text-muted,#9a8070); border: 1px solid var(--border-hover,#c9b89f); border-radius: 7px; font-family: 'Poppins',sans-serif; font-size: 11px; cursor: pointer; }

    /* Sections inside summary */
    .cp-section { padding-top: 8px; margin-top: 6px; border-top: 1px solid var(--border,#e0d4c4); }
    .cp-section-label { font-size: 10px; font-weight: 700; color: var(--text-sec,#5a4a3a); display: flex; align-items: center; gap: 5px; margin-bottom: 7px; letter-spacing: .07em; text-transform: uppercase; }
    .cp-section-label i { color: #d1904b; }

    /* Payment methods — tactile tiles */
    .cp-pm-methods-label { font-size: 11px; font-weight: 600; color: var(--text-muted,#9a8070); margin-bottom: 8px; }
    .cp-pay-methods { display: flex; gap: 8px; }
    .cp-pay-method {
      flex: 1; position: relative; display: flex; flex-direction: column; align-items: center; gap: 6px;
      padding: 13px 4px 11px; border: 1.5px solid var(--border,#e5e7eb); border-radius: 14px;
      cursor: pointer; background: var(--bg-card-hover,#f9fafb); color: var(--text-sec,#5a4a3a);
      transition: transform .12s ease, border-color .15s ease, background .15s ease, box-shadow .15s ease;
      user-select: none;
    }
    /* per-method accent colors */
    .cp-pay-method[data-method="bakong"]   { --m:#e0454a; --mbg:rgba(224,69,74,.12);  --mring:rgba(224,69,74,.22); }
    .cp-pay-method[data-method="cash"]     { --m:#27ae60; --mbg:rgba(39,174,96,.12);  --mring:rgba(39,174,96,.22); }
    .cp-pay-method[data-method="paylater"] { --m:#e8973a; --mbg:rgba(232,151,58,.12); --mring:rgba(232,151,58,.22); }
    .cp-pay-method[data-method="riel"]     { --m:#2d8fd5; --mbg:rgba(45,143,213,.12); --mring:rgba(45,143,213,.22); }
    .cp-pay-method .cp-pm-ico { font-size: 19px; color: var(--m,#9a8070); transition: color .15s ease; }
    .cp-pay-method .cp-pm-lbl { font-size: 11.5px; font-weight: 600; }
    .cp-pay-method .cp-pm-check { position: absolute; top: 6px; right: 7px; font-size: 13px; color: var(--m,#d1904b); opacity: 0; transform: scale(.5); transition: opacity .15s ease, transform .15s ease; }
    .cp-pay-method:hover { border-color: var(--m,#d1904b); }
    .cp-pay-method:active { transform: scale(.96); }
    .cp-pay-method.selected { border-color: var(--m,#d1904b); background: var(--mbg,rgba(209,144,75,.12)); color: var(--text,#1a1410); box-shadow: 0 0 0 3px var(--mring,rgba(209,144,75,.16)); }
    .cp-pay-method.selected .cp-pm-check { opacity: 1; transform: scale(1); }
    .cp-pay-method input { display: none; }

    /* Split payment inputs */
    .cp-split-inputs { display: none; background: var(--bg-card-hover,#f9fafb); border-radius: 8px; padding: 8px; border: 1px solid var(--border,#e5e7eb); margin-top: 5px; }
    .cp-split-inputs.active { display: block; }
    .cp-split-row { display: flex; align-items: center; gap: 7px; margin-bottom: 4px; }
    .cp-split-row:last-child { margin-bottom: 0; }
    .cp-split-row label { font-size: 11px; color: var(--text-sec,#5a4a3a); min-width: 52px; }
    .cp-split-row input { flex: 1; padding: 5px 7px; border-radius: 5px; border: 1px solid var(--border,#e5e7eb); background: var(--bg-card,#fff); color: var(--text,#1a1410); font-size: 12px; font-family: 'Poppins',sans-serif; outline: none; }
    .cp-split-row input:focus { border-color: #d1904b; }

    /* Change calculator */
    .cp-change-calc { display: none; margin-top: 6px; padding: 9px; background: rgba(85,224,135,.05); border-radius: 9px; border: 1px solid rgba(85,224,135,.2); }
    .cp-change-calc.visible { display: block; }
    .cp-change-calc label { font-size: 11px; color: var(--text-sec,#5a4a3a); font-weight: 600; display: block; margin-bottom: 3px; }
    .cp-change-calc input { width: 100%; padding: 6px 9px; border-radius: 6px; border: 1px solid rgba(85,224,135,.35); background: var(--bg-card,#fff); color: var(--text,#1a1410); font-size: 14px; font-weight: 700; font-family: 'Poppins',sans-serif; outline: none; text-align: right; }
    .cp-change-calc input:focus { border-color: #55e087; }
    /* Quick tender — the note the customer actually handed over, one tap */
    .cp-tender-quick { display: flex; gap: 5px; flex-wrap: wrap; margin-top: 6px; }
    .cp-tender-btn { flex: 1 1 auto; min-width: 48px; padding: 6px 8px; border-radius: 7px; border: 1.5px solid rgba(85,224,135,.3); background: var(--bg-input,#f3f4f6); color: var(--text-sec,#5a4a3a); font-family: 'Poppins',sans-serif; font-size: 12px; font-weight: 700; cursor: pointer; transition: all .15s; }
    .cp-tender-btn:hover { border-color: #55e087; color: #2e9c5a; }
    .cp-tender-btn.active { background: rgba(85,224,135,.16); border-color: #55e087; color: #2e9c5a; box-shadow: 0 0 0 2px rgba(85,224,135,.15); }
    [data-theme="dark"] .cp-tender-btn { background: #1a1a1a; border-color: #2d2d2d; color: #aaa; }
    [data-theme="dark"] .cp-tender-btn.active { background: rgba(85,224,135,.14); border-color: #55e087; color: #55e087; }

    .cp-change-row { display: flex; justify-content: space-between; align-items: center; margin-top: 7px; padding-top: 5px; border-top: 1px solid rgba(85,224,135,.15); }
    .cp-change-row .change-label { font-size: 11px; font-weight: 600; color: var(--text-sec,#5a4a3a); }
    .cp-change-row .change-amount { font-size: 17px; font-weight: 800; color: #55e087; }
    .cp-change-row .change-amount.not-enough { color: #e74c3c; font-size: 12px; }

    /* Drink type toggle */
    .cp-drink-type { display: flex; gap: 6px; margin-top: 4px; }
    .cp-drink-btn { flex: 1; display: flex; align-items: center; justify-content: center; gap: 5px; padding: 9px; border-radius: 10px; border: 1.5px solid var(--border,#e5e7eb); background: var(--bg-card-hover,#f9fafb); color: var(--text-sec,#5a4a3a); font-family: 'Poppins',sans-serif; font-size: 12px; font-weight: 500; cursor: pointer; transition: all .2s; }
    .cp-drink-btn.active { border-color: #d1904b; background: rgba(209,144,75,.12); color: #d1904b; font-weight: 600; box-shadow: 0 0 0 2px rgba(209,144,75,.15); }

    /* Customer input */
    .cp-form-group { margin-top: 7px; }
    .cp-form-group label { display: block; font-size: 11px; font-weight: 600; color: var(--text-sec,#5a4a3a); margin-bottom: 3px; }
    .cp-form-group input,
    .cp-form-group select { width: 100%; padding: 7px 10px; border-radius: 7px; border: 1px solid var(--border,#e5e7eb); background: var(--bg-card-hover,#f9fafb); color: var(--text,#1a1410); font-family: 'Poppins',sans-serif; font-size: 12px; outline: none; transition: border-color .2s; }
    .cp-form-group input:focus,
    .cp-form-group select:focus { border-color: #d1904b; }

    /* Loyalty */
    .cp-loyalty { display: flex; align-items: center; justify-content: space-between; margin-top: 6px; }
    .cp-loyalty-info { font-size: 10px; color: var(--text-sec,#5a4a3a); }
    .cp-loyalty-info .linked { color: #d1904b; font-weight: 600; }
    .cp-loyalty-btn { background: #d1904b; color: #fff; border: none; border-radius: 50px; padding: 4px 10px; font-size: 10px; font-weight: 600; cursor: pointer; font-family: 'Poppins',sans-serif; transition: all .2s; }
    .cp-loyalty-btn:hover { background: #a0702a; }

    /* Cart footer */
    .cp-footer {
      position: sticky;
      bottom: 0;
      border-top: 1px solid var(--border,#e0d4c4);
      padding: 14px 16px 16px;
      background: var(--bg-card,#fff);
      margin-top: auto;
      flex-shrink: 0;
      z-index: 10;
    }
    .cp-total-row { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px; }
    .cp-total-row .lbl { font-size: 13px; font-weight: 600; color: var(--text-sec,#5a4a3a); letter-spacing: .04em; text-transform: uppercase; }
    .cp-total-row .amt { font-size: 30px; font-weight: 900; color: #d1904b; letter-spacing: -.02em; }
    /* ── 3D Tactile Confirm Order Button ── */
    .cp-confirm-btn {
      width: 100%;
      padding: 14px 18px;
      background: linear-gradient(180deg, #f5b974 0%, #d1904b 50%, #ad6d28 100%);
      border: 1px solid #f8cd99;
      border-top: 1px solid #ffe3c0;
      border-radius: 14px;
      color: #140c02;
      font-weight: 800;
      font-size: 15px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      font-family: 'Poppins',sans-serif;
      letter-spacing: .02em;
      box-shadow: 0 4.5px 0 #7c4511, 0 8px 22px rgba(209, 144, 75, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.65);
      text-shadow: 0 1px 0 rgba(255, 255, 255, 0.35);
      transition: all 0.18s cubic-bezier(0.34, 1.56, 0.64, 1);
      user-select: none;
      position: relative;
    }
    .cp-confirm-btn:hover {
      background: linear-gradient(180deg, #ffd094 0%, #e09d57 50%, #c47c2c 100%);
      transform: translateY(-2px);
      box-shadow: 0 6.5px 0 #7c4511, 0 12px 28px rgba(209, 144, 75, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.7);
      color: #100a02;
    }
    .cp-confirm-btn:active {
      transform: translateY(3.5px) scale(0.98);
      box-shadow: 0 1px 0 #7c4511, inset 0 2px 5px rgba(0, 0, 0, 0.25);
    }
    .cp-confirm-btn:disabled {
      opacity: .5;
      cursor: not-allowed;
      transform: none !important;
      box-shadow: none !important;
      filter: grayscale(0.5);
    }
    .cp-confirm-btn.paylater {
      background: linear-gradient(180deg, #a78bfa 0%, #8b5cf6 50%, #6d28d9 100%) !important;
      border: 1px solid #c4b5fd !important;
      border-top: 1px solid #ede9fe !important;
      box-shadow: 0 4.5px 0 #4c1d95, 0 8px 20px rgba(139, 92, 246, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.6) !important;
      color: #ffffff !important;
      text-shadow: 0 1px 0 rgba(0, 0, 0, 0.35);
    }
    .cp-confirm-btn.paylater:hover {
      background: linear-gradient(180deg, #c4b5fd 0%, #9f75ff 50%, #7c3aed 100%) !important;
      box-shadow: 0 6.5px 0 #4c1d95, 0 12px 28px rgba(139, 92, 246, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.7) !important;
      color: #ffffff !important;
    }
    .cp-confirm-btn.paylater:active {
      transform: translateY(3.5px) scale(0.98);
      box-shadow: 0 1px 0 #4c1d95, inset 0 2px 5px rgba(0, 0, 0, 0.3) !important;
    }
    .cp-shortcuts { display: flex; flex-wrap: wrap; gap: 4px 10px; margin-top: 9px; font-size: 10px; color: var(--text-muted,#9a8070); opacity: .8; }
    .cp-shortcuts kbd { background: var(--bg-input,#f3f4f6); border: 1px solid var(--border,#e5e7eb); border-radius: 4px; padding: 2px 5px; font-size: 9px; font-weight: 700; color: var(--text-sec,#5a4a3a); font-family: 'Poppins',sans-serif; letter-spacing: .03em; }

    /* ── Dark theme overrides ── */
    [data-theme="dark"] body { background: #0c0c0c; }
    [data-theme="dark"] .menu-header { background: rgba(14,14,14,.96); border-color: #252525; }
    [data-theme="dark"] .menu-panel .cat-nav { background: rgba(14,14,14,.96); border-color: #252525; }
    [data-theme="dark"] .cart-panel { background: #151515; border-left: 1px solid rgba(209,144,75,.14); }
    [data-theme="dark"] .cp-header { border-color: #282828; background: #191919; }
    [data-theme="dark"] .cp-body { background: #151515; }
    [data-theme="dark"] .cp-item { border-color: #232323; }
    [data-theme="dark"] .cp-item:hover { background: #1d1d1d; }
    [data-theme="dark"] .cp-summary { border-color: #232323; }
    [data-theme="dark"] .cp-section { border-color: #232323; }
    [data-theme="dark"] .cp-footer { background: #191919; border-color: #282828; }
    [data-theme="dark"] .cp-form-group input,
    [data-theme="dark"] .cp-form-group select,
    [data-theme="dark"] .cp-disc-inputs input,
    [data-theme="dark"] .cp-split-row input,
    [data-theme="dark"] .cp-change-calc input { background: #1a1a1a; color: #f0f0f0; border-color: #252525; color-scheme: dark; }
    [data-theme="dark"] .cp-qty { background: #0c0c0c; border-color: #252525; }
    [data-theme="dark"] .cp-pay-method { background: #1e1e1e; border-color: #2d2d2d; color: #aaa; }
    [data-theme="dark"] .cp-pay-method:hover { background: rgba(209,144,75,.07); border-color: rgba(209,144,75,.4); }
    [data-theme="dark"] .cp-pay-method.selected { background: rgba(209,144,75,.14); border-color: #d1904b; color: #f0f0f0; box-shadow: 0 0 0 2px rgba(209,144,75,.2); }
    [data-theme="dark"] .cp-drink-btn { background: #1e1e1e; border-color: #2d2d2d; color: #aaa; }
    [data-theme="dark"] .cp-drink-btn.active { background: rgba(209,144,75,.14); border-color: #d1904b; box-shadow: 0 0 0 2px rgba(209,144,75,.15); }
    [data-theme="dark"] .cp-split-inputs { background: #1a1a1a; border-color: #252525; }
    [data-theme="dark"] .cp-change-calc { background: rgba(85,224,135,.03); }
    [data-theme="dark"] .cp-discount-toggle { border-color: #363636; }
    [data-theme="dark"] #cpDiscountForm { background: rgba(209,144,75,.06); border-color: rgba(209,144,75,.25); }

    /* ── Cash Payment Settlement Modal (POS Layout) ── */
    #cashPaymentModal {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.75);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        z-index: 999999;
        align-items: center;
        justify-content: center;
        padding: 16px;
    }
    #cashPaymentModal.active {
        display: flex !important;
    }
    #cashPaymentModal .cpm-card {
        background: #ffffff;
        border: 1px solid rgba(0, 0, 0, 0.08);
        border-radius: 28px;
        width: 96vw;
        max-width: 1060px;
        box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.2), 0 0 1px 1px rgba(0, 0, 0, 0.04);
        position: relative;
        overflow: hidden;
        animation: cpmFadeIn 0.22s cubic-bezier(0.16, 1, 0.3, 1);
        font-family: 'Poppins', 'Kantumruy Pro', sans-serif;
    }
    @keyframes cpmFadeIn {
        from { opacity: 0; transform: translateY(14px) scale(0.98); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }

    /* Dual Received Input Cards */
    #cashPaymentModal #cpmCardUsd,
    #cashPaymentModal #cpmCardKhr {
        background: #f8fafc;
        border: 2px solid #e2e8f0;
        border-radius: 16px;
        transition: all 0.2s ease;
    }
    #cashPaymentModal #cpmCardUsd:hover,
    #cashPaymentModal #cpmCardUsd:focus-within,
    #cashPaymentModal #cpmCardKhr:hover,
    #cashPaymentModal #cpmCardKhr:focus-within {
        background: #ffffff !important;
        border-color: #10b981 !important;
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15), 0 2px 8px rgba(0, 0, 0, 0.04) !important;
    }

    /* Total Received Banner */
    #cashPaymentModal #cpmTotalRecBanner {
        background: #f8fafc;
        border: 1.5px solid #e2e8f0;
        border-radius: 16px;
        padding: 10px 16px;
    }

    /* Selectors: Currency & Change Return Mode */
    #cashPaymentModal #cpmCurrSelector,
    #cashPaymentModal #cpmChangeModeWrap {
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 2px;
        display: inline-flex;
        gap: 2px;
    }
    #cashPaymentModal #cpmCurrSelector button,
    #cashPaymentModal #cpmChangeModeWrap button {
        background: transparent;
        border: none;
        color: #64748b;
        font-size: 12px;
        font-weight: 600;
        padding: 5px 12px;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.15s ease;
        white-space: nowrap;
        outline: none;
    }
    #cashPaymentModal #cpmCurrSelector button.cpm-mode-active,
    #cashPaymentModal #cpmChangeModeWrap button.cpm-mode-active,
    #cashPaymentModal #cpmCurrUsd.cpm-mode-active,
    #cashPaymentModal #cpmCurrKhr.cpm-mode-active,
    #cashPaymentModal #cpmModeMixed.cpm-mode-active,
    #cashPaymentModal #cpmModeKhr.cpm-mode-active {
        background: #10b981 !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        box-shadow: 0 1px 4px rgba(16, 185, 129, 0.35) !important;
    }

    /* Payment Methods */
    #cashPaymentModal #cpmMethodPills {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }
    #cashPaymentModal #cpmMethodCash,
    #cashPaymentModal #cpmMethodBakong {
        height: 44px;
        border-radius: 12px;
        font-size: 13px;
        font-weight: 800;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        cursor: pointer;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        border: 1.5px solid #e2e8f0;
        background: #f8fafc;
        color: #475569;
        outline: none;
    }
    #cashPaymentModal #cpmMethodCash:hover {
        background: #f0fdf4;
        border-color: #86efac;
        color: #15803d;
    }
    #cashPaymentModal #cpmMethodBakong:hover {
        background: #fff1f2;
        border-color: #fecdd3;
        color: #e11d48;
    }
    #cashPaymentModal #cpmMethodCash.cpm-method-active {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
        color: #ffffff !important;
        border-color: #059669 !important;
        box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35) !important;
    }
    #cashPaymentModal #cpmMethodCash.cpm-method-active i {
        color: #ffffff !important;
    }
    #cashPaymentModal #cpmMethodBakong.cpm-method-active {
        background: linear-gradient(135deg, #e11d48 0%, #be123c 100%) !important;
        color: #ffffff !important;
        border-color: #be123c !important;
        box-shadow: 0 4px 16px rgba(225, 29, 72, 0.4) !important;
    }
    #cashPaymentModal #cpmMethodBakong.cpm-method-active i {
        color: #ffffff !important;
    }

    /* Change Box */
    #cashPaymentModal #cpmChangeBox {
        background: #ecfdf5;
        border: 1.5px solid #a7f3d0;
        border-radius: 16px;
        padding: 12px 18px;
    }
    #cashPaymentModal #cpmChangeBox.cpm-short {
        background: #fef2f2 !important;
        border-color: #fca5a5 !important;
    }
    #cashPaymentModal #cpmChangeBox .cpm-change-lbl {
        color: #047857;
    }
    #cashPaymentModal #cpmChangeBox.cpm-short .cpm-change-lbl {
        color: #b91c1c !important;
    }
    #cashPaymentModal #cpmChangeUsd {
        color: #047857;
    }
    #cashPaymentModal #cpmChangeBox.cpm-short #cpmChangeUsd {
        color: #dc2626 !important;
    }
    #cashPaymentModal #cpmChangeKhr {
        color: #059669;
    }
    #cashPaymentModal #cpmChangeBox.cpm-short #cpmChangeKhr {
        color: #ef4444 !important;
    }

    /* Apply button */
    #cashPaymentModal #cpmApplyBtn {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
        color: #ffffff !important;
        font-weight: 800 !important;
        border: none !important;
        box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35) !important;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }
    #cashPaymentModal #cpmApplyBtn:hover {
        background: linear-gradient(135deg, #059669 0%, #047857 100%) !important;
        transform: translateY(-1px);
        box-shadow: 0 6px 18px rgba(16, 185, 129, 0.45) !important;
    }
    #cashPaymentModal #cpmApplyBtn.bakong-mode {
        background: linear-gradient(135deg, #e11d48 0%, #be123c 100%) !important;
        box-shadow: 0 4px 16px rgba(225, 29, 72, 0.38) !important;
    }
    #cashPaymentModal #cpmApplyBtn.bakong-mode:hover {
        background: linear-gradient(135deg, #f43f5e 0%, #e11d48 100%) !important;
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(225, 29, 72, 0.48) !important;
    }

    /* Senior UI/UX: Contextual Bakong Mode Theming */
    #cashPaymentModal.cpm-is-bakong #cpmModalHeaderIconBox {
        background: #fff1f2 !important;
        border-color: #fecdd3 !important;
        color: #e11d48 !important;
    }
    #cashPaymentModal.cpm-is-bakong #cpmCardUsd,
    #cashPaymentModal.cpm-is-bakong #cpmCardKhr {
        background: #fff5f5 !important;
        border-color: #fecdd3 !important;
    }
    #cashPaymentModal.cpm-is-bakong #cpmCardUsd span.text-slate-400,
    #cashPaymentModal.cpm-is-bakong #cpmCardKhr span.text-slate-400 {
        color: #fda4af !important;
    }
    #cashPaymentModal.cpm-is-bakong #cpmTotalRecBanner {
        background: linear-gradient(135deg, #fff1f2 0%, #ffe4e6 100%) !important;
        border-color: #fecdd3 !important;
    }
    #cashPaymentModal.cpm-is-bakong #cpmTotalReceivedUsd {
        color: #be123c !important;
    }
    #cashPaymentModal.cpm-is-bakong #cpmTotalReceivedKhr {
        color: #e11d48 !important;
    }

    #cashPaymentModal #cpmBakongQrCanvas img, #cashPaymentModal #cpmBakongQrCanvas canvas {
        display: block;
        margin: 0 auto;
        border-radius: 12px;
    }

    /* Dark Theme Overrides */
    [data-theme="dark"] #cashPaymentModal .cpm-card {
        background: #111827 !important;
        border-color: #1f2937 !important;
        color: #f8fafc !important;
        box-shadow: 0 25px 60px rgba(0, 0, 0, 0.8) !important;
    }
    [data-theme="dark"] #cashPaymentModal .bg-white {
        background: #1e293b !important;
        border-color: #334155 !important;
    }
    [data-theme="dark"] #cashPaymentModal .bg-slate-50\/50,
    [data-theme="dark"] #cashPaymentModal .bg-slate-50,
    [data-theme="dark"] #cashPaymentModal .bg-slate-100 {
        background: #0f172a !important;
        border-color: #334155 !important;
    }
    [data-theme="dark"] #cashPaymentModal .text-slate-900,
    [data-theme="dark"] #cashPaymentModal .text-slate-800 {
        color: #ffffff !important;
    }
    [data-theme="dark"] #cashPaymentModal .text-slate-500,
    [data-theme="dark"] #cashPaymentModal .text-slate-600 {
        color: #cbd5e1 !important;
    }
    [data-theme="dark"] #cashPaymentModal .border-slate-100,
    [data-theme="dark"] #cashPaymentModal .border-slate-200,
    [data-theme="dark"] #cashPaymentModal .divide-slate-50 > * {
        border-color: #334155 !important;
    }
    [data-theme="dark"] #cashPaymentModal .cpm-item-qty {
        background: #334155 !important;
        color: #ffffff !important;
    }
    [data-theme="dark"] #cashPaymentModal #cpmCardUsd,
    [data-theme="dark"] #cashPaymentModal #cpmCardKhr {
        background: #0f172a !important;
        border-color: #334155 !important;
    }
    [data-theme="dark"] #cashPaymentModal #cpmCardUsd:focus-within,
    [data-theme="dark"] #cashPaymentModal #cpmCardKhr:focus-within {
        background: #1e293b !important;
        border-color: #10b981 !important;
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.25) !important;
    }
    [data-theme="dark"] #cashPaymentModal #cpmReceivedUsdInput,
    [data-theme="dark"] #cashPaymentModal #cpmReceivedKhrInput {
        color: #ffffff !important;
    }
    [data-theme="dark"] #cashPaymentModal #cpmTotalRecBanner {
        background: #1e293b !important;
        border-color: #334155 !important;
    }
    [data-theme="dark"] #cashPaymentModal #cpmTotalReceivedUsd {
        color: #ffffff !important;
    }
    [data-theme="dark"] #cashPaymentModal #cpmTotalReceivedKhr {
        color: #94a3b8 !important;
    }
    [data-theme="dark"] #cashPaymentModal #cpmChangeBox {
        background: #064e3b !important;
        border-color: #059669 !important;
    }
    [data-theme="dark"] #cashPaymentModal #cpmChangeBox .cpm-change-lbl,
    [data-theme="dark"] #cashPaymentModal #cpmChangeBox #cpmChangeUsd,
    [data-theme="dark"] #cashPaymentModal #cpmChangeBox #cpmChangeKhr {
        color: #34d399 !important;
    }

    /* Dark Theme Overrides for Bakong Mode */
    [data-theme="dark"] #cashPaymentModal #cpmMethodCash {
        background: #0f172a !important;
        border-color: #334155 !important;
        color: #94a3b8 !important;
    }
    [data-theme="dark"] #cashPaymentModal #cpmMethodBakong {
        background: #0f172a !important;
        border-color: #334155 !important;
        color: #94a3b8 !important;
    }
    [data-theme="dark"] #cashPaymentModal #cpmMethodCash:hover {
        background: #064e3b !important;
        border-color: #059669 !important;
        color: #34d399 !important;
    }
    [data-theme="dark"] #cashPaymentModal #cpmMethodBakong:hover {
        background: #4c0519 !important;
        border-color: #e11d48 !important;
        color: #f43f5e !important;
    }
    [data-theme="dark"] #cashPaymentModal #cpmMethodBakong.cpm-method-active {
        background: linear-gradient(135deg, #e11d48 0%, #9f1239 100%) !important;
        border-color: #e11d48 !important;
        color: #ffffff !important;
        box-shadow: 0 4px 16px rgba(225, 29, 72, 0.45) !important;
    }
    [data-theme="dark"] #cashPaymentModal #cpmApplyBtn.bakong-mode {
        background: linear-gradient(135deg, #e11d48 0%, #9f1239 100%) !important;
        box-shadow: 0 4px 16px rgba(225, 29, 72, 0.45) !important;
    }
    [data-theme="dark"] #cashPaymentModal.cpm-is-bakong #cpmModalHeaderIconBox {
        background: rgba(225, 29, 72, 0.15) !important;
        border-color: rgba(225, 29, 72, 0.3) !important;
        color: #fb7185 !important;
    }
    [data-theme="dark"] #cashPaymentModal.cpm-is-bakong #cpmCardUsd,
    [data-theme="dark"] #cashPaymentModal.cpm-is-bakong #cpmCardKhr {
        background: rgba(225, 29, 72, 0.06) !important;
        border-color: rgba(225, 29, 72, 0.25) !important;
    }
    [data-theme="dark"] #cashPaymentModal.cpm-is-bakong #cpmTotalRecBanner {
        background: rgba(225, 29, 72, 0.12) !important;
        border-color: rgba(225, 29, 72, 0.3) !important;
    }
    [data-theme="dark"] #cashPaymentModal.cpm-is-bakong #cpmTotalReceivedUsd {
        color: #fda4af !important;
    }
    [data-theme="dark"] #cashPaymentModal.cpm-is-bakong #cpmTotalReceivedKhr {
        color: #f43f5e !important;
    }

    /* ── Cart Remove Confirmation Modal ── */
    #cartRemoveModal .cpm-card {
        background: #16161e;
        border: 1px solid #2d2d3e;
        border-radius: 20px;
        box-shadow: 0 25px 60px rgba(0, 0, 0, 0.65), 0 0 0 1px rgba(255, 255, 255, 0.05);
    }
    [data-theme="light"] #cartRemoveModal .cpm-card {
        background: #ffffff !important;
        border: 1px solid #e2d8cc !important;
        box-shadow: 0 20px 50px rgba(90, 60, 20, 0.18) !important;
    }
    [data-theme="light"] #cartRemoveModal h3 {
        color: #1a1410 !important;
    }
    [data-theme="light"] #cartRemoveModal p {
        color: #6a5e52 !important;
    }
    [data-theme="light"] #cartRemoveModal .btn-remove-cancel {
        background: #f3f4f6 !important;
        border-color: #e5e7eb !important;
        color: #4b5563 !important;
    }
    [data-theme="light"] #cartRemoveModal .btn-remove-cancel:hover {
        background: #e5e7eb !important;
        color: #1a1410 !important;
    }

    /* ── Quick Pressed Animation for Card Click ── */
    .product-card.card-quick-pressed {
        transform: scale(0.94) !important;
        transition: transform 0.15s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
    }

    /* ── Chat toggle in header ── */
    #chatToggle {
      position: static;
      width: 36px; height: 36px; border-radius: 50%;
      border: 1px solid var(--border,#e0d4c4);
      background: var(--bg-input,#ede8e0);
      color: var(--text,#1a1410);
      font-size: 15px; cursor: pointer; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      transition: all .25s; animation: none; box-shadow: none;
    }
    .card-desc { display: none !important; }

    /* Option Pills Styling */
    .option-pill {
      background: #1a1a1e !important;
      color: #9ca3af !important;
      border: 1px solid #1f2937 !important;
      border-radius: 12px !important;
      padding: 8px 14px !important;
      font-size: 12.5px !important;
      font-weight: 600 !important;
      cursor: pointer !important;
      transition: all 0.18s ease !important;
    }
    .option-pill:hover {
      border-color: #374151 !important;
      color: #ffffff !important;
    }
    .option-pill.active {
      background: #f59e0b !important;
      color: #000000 !important;
      font-weight: 800 !important;
      border-color: #f59e0b !important;
      box-shadow: 0 0 12px rgba(245, 158, 11, 0.3) !important;
    }

    /* ══ LIGHT THEME COMPLETE WHITE CONTENT BACKGROUND ══ */
    [data-theme="light"],
    html[data-theme="light"] {
      --bg: #ffffff;
      --bg-card: #ffffff;
      --bg-card-hover: #f9fafb;
      --bg-input: #f3f4f6;
      --bg-header: rgba(255,255,255,0.96);
      --border: #e5e7eb;
      --border-hover: #d1d5db;
    }

    [data-theme="light"] body,
    [data-theme="light"] .app-main,
    [data-theme="light"] .pos-layout,
    [data-theme="light"] .menu-panel,
    [data-theme="light"] .menu-scroll,
    [data-theme="light"] .menu-main {
      background-color: #ffffff !important;
    }

    [data-theme="light"] .menu-header,
    [data-theme="light"] .menu-panel .cat-nav {
      background-color: rgba(255, 255, 255, 0.97) !important;
      border-color: #e5e7eb !important;
    }

    [data-theme="light"] .search-inner,
    [data-theme="light"] .sort-select,
    [data-theme="light"] .btn-nav,
    [data-theme="light"] .btn-theme {
      background-color: #f3f4f6 !important;
      border-color: #e5e7eb !important;
    }


    [data-theme="light"] .cat-header {
      border-bottom-color: #e5e7eb !important;
    }

    [data-theme="light"] .product-card {
      background-color: #ffffff !important;
      border-color: #e5e7eb !important;
      box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05) !important;
    }

    [data-theme="light"] .product-card:hover {
      border-color: #d1904b !important;
      box-shadow: 0 4px 20px rgba(209,144,75,0.25) !important;
    }

    [data-theme="light"] .cart-panel {
      background-color: #ffffff !important;
      border-left-color: #e5e7eb !important;
    }

    [data-theme="light"] .cp-header,
    [data-theme="light"] .cp-footer,
    [data-theme="light"] .cp-summary,
    [data-theme="light"] .cp-section,
    [data-theme="light"] .cp-item {
      border-color: #e5e7eb !important;
    }

    [data-theme="light"] .cp-qty {
      background-color: #f3f4f6 !important;
      border-color: #e5e7eb !important;
    }

    [data-theme="light"] .cp-pay-method {
      background-color: #f9fafb !important;
      border-color: #e5e7eb !important;
    }

    [data-theme="light"] .cp-drink-btn {
      background-color: #f9fafb !important;
      border-color: #e5e7eb !important;
    }

    [data-theme="light"] .cp-form-group input,
    [data-theme="light"] .cp-form-group select {
      background-color: #f9fafb !important;
      border-color: #e5e7eb !important;
    }

    /* ══ LIGHT THEME CUSTOMIZATION MODAL COMPLETE FIX ══ */
    [data-theme="light"] #product-modal,
    [data-theme="light"] .cp-paymodal {
      background-color: rgba(0,0,0,0.3) !important;
      backdrop-filter: blur(4px) !important;
      -webkit-backdrop-filter: blur(4px) !important;
    }

    [data-theme="light"] #product-modal .modal-card,
    [data-theme="light"] .modal-card {
      background-color: #ffffff !important;
      border: 1px solid #e5e7eb !important;
      box-shadow: 0 20px 45px rgba(0, 0, 0, 0.12) !important;
      color: #1a1410 !important;
    }

    [data-theme="light"] #product-modal .modal-header,
    [data-theme="light"] #product-modal #modalName,
    [data-theme="light"] #product-modal .modal-name,
    [data-theme="light"] #product-modal h2,
    [data-theme="light"] #product-modal h3 {
      color: #1a1410 !important;
    }

    [data-theme="light"] #product-modal #modalDesc {
      color: #5a4a3a !important;
    }

    [data-theme="light"] #product-modal .modal-close {
      background-color: #f3f4f6 !important;
      color: #1a1410 !important;
      border-color: #e5e7eb !important;
    }

    [data-theme="light"] #product-modal .modal-close:hover {
      background-color: #e5e7eb !important;
      color: #000000 !important;
    }

    [data-theme="light"] #product-modal .modal-price-row {
      background-color: #f9fafb !important;
      border-color: #e5e7eb !important;
    }

    [data-theme="light"] #product-modal .qty-control,
    [data-theme="light"] .qty-control {
      background-color: #f3f4f6 !important;
      border-color: #e5e7eb !important;
    }

    [data-theme="light"] #product-modal #modalQtyDisplay,
    [data-theme="light"] #product-modal .qty-control span {
      color: #1a1410 !important;
    }

    [data-theme="light"] #product-modal .option-label {
      color: #5a4a3a !important;
    }

    [data-theme="light"] #product-modal .modal-footer,
    [data-theme="light"] .modal-footer {
      background-color: #ffffff !important;
      border-top: 1px solid #e5e7eb !important;
    }

    /* ══ PRODUCT CUSTOMIZATION / EDIT MODAL STYLING ══ */
    #product-modal .modal-card {
      background: #ffffff !important;
      color: #0f172a !important;
    }
    #product-modal #modalQtyInput {
      color: #0f172a !important;
      font-weight: 900 !important;
      opacity: 1 !important;
    }
    #product-modal .option-pill {
      background: #ffffff !important;
      color: #334155 !important;
      border: 1px solid #e2e8f0 !important;
      border-radius: 12px !important;
      padding: 9px 4px !important;
      font-size: 13px !important;
      font-weight: 700 !important;
      text-align: center !important;
      cursor: pointer !important;
      transition: all 0.18s ease !important;
      box-shadow: none !important;
      width: 100% !important;
    }
    #product-modal .option-pill:hover {
      border-color: #cbd5e1 !important;
      color: #0f172a !important;
      background: #f8fafc !important;
    }
    #product-modal .option-pill.active {
      background: #f59e0b !important;
      color: #ffffff !important;
      border-color: #f59e0b !important;
      font-weight: 800 !important;
      box-shadow: 0 4px 14px rgba(245, 158, 11, 0.45) !important;
    }
  </style>
<body>
<div class="flex h-screen w-screen overflow-hidden app-layout">
<?php require __DIR__ . '/sidebar.php'; ?>
<main class="flex-1 h-full overflow-y-auto app-main flex flex-col">

<?php unset($_SESSION['stock_warning']); ?>

<!-- HEADER -->
<header class="menu-header">
  <div class="header-center">
    <form class="search-form" method="GET" id="searchForm">
      <div class="search-inner">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" name="search" placeholder="<?= __('search_products', 'Search drinks...') ?>" value="<?= e($search_term) ?>" id="searchInput" autocomplete="off">
        <?php if (!empty($search_term)): ?>
        <a href="menu.php" class="search-clear"><i class="fa-solid fa-xmark"></i></a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="header-right">
    <button type="button" class="cart-pill-btn" id="cart-toggle-btn" onclick="toggleCartSidebar()" title="<?= __('cart', 'Cart') ?>">
      <div class="cart-pill-icon-wrap">
        <i class="fa-solid fa-cart-shopping"></i>
      </div>
      <span class="cart-pill-text"><?= __('cart', 'Cart') ?></span>
      <span id="cart-badge" class="cart-pill-badge"><?= $cart_count ?></span>
    </button>
  </div>
</header>

<!-- Drinks queued for a tab that was left without confirming. Shown once, on the
     menu the cashier lands on, so an abandoned add is never silently swallowed. -->
<?php if ($add_cart_dropped > 0): ?>
<div class="add-order-banner" style="background:#8a5a1e;">
  <i class="fa-solid fa-triangle-exclamation"></i>
  <?= (int)$add_cart_dropped ?> drink<?= $add_cart_dropped === 1 ? '' : 's' ?>
  queued for Order #<?= (int)$add_cart_dropped_no ?>
  <?= $add_cart_dropped === 1 ? 'was' : 'were' ?> discarded &mdash; that order wasn&rsquo;t confirmed.
</div>
<?php endif; ?>

<!-- ADD TO ORDER BANNER -->
<?php if ($add_to_order_mode > 0): ?>
<div class="add-order-banner">
  <i class="fa-solid fa-cart-plus"></i>
  Adding to Order #<?= $add_to_order_mode ?>
  <?php /* No "View Cart & Confirm" link here any more. It was the only caller of
           cart_paylater.php and it led off a page that can already finish the job —
           the cart panel's button reads "Add to Order #N" in this mode. With an
           empty tab-cart it landed the cashier on "Your cart is empty, go back",
           which reads like an error when nothing is wrong. cart_paylater.php is
           left on disk, now unreferenced, if that review step is ever wanted. */ ?>
  <?php /* Says HELD, not removed. It belongs on this strip and not in the checkout
           panel: the panel only renders once the cart has items, and this notice is
           needed precisely when the cart is empty — a cashier who was mid-order
           would otherwise assume the system lost their work and rebuild it. */ ?>
  <?php if ($cart_stash_count > 0): ?>
  <span style="display:inline-block;margin-left:10px;padding:2px 9px;border-radius:999px;
               background:rgba(0,0,0,.22);font-weight:600;font-size:12px;">
    <i class="fa-solid fa-box-archive"></i>
    <?= (int)$cart_stash_count ?> drink<?= $cart_stash_count === 1 ? '' : 's' ?>
    held &mdash; back when you leave
  </span>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── POS SPLIT LAYOUT ── -->
<div class="pos-layout">

  <!-- ════ LEFT: MENU PANEL ════ -->
  <div class="menu-panel" id="menuPanel">

    <!-- Category nav (sticky within menu panel) -->
    <?php if (!$is_price_sort): ?>
    <nav class="cat-nav" id="catNav">
      <?php 
      $isFirstCat = true;
      foreach ($categories as $key => $label):
        if (empty($products[$key])) continue;
        $count  = count(array_filter($products[$key], fn($p) => ((int)($p['is_available'] ?? 1) === 1) && !((int)($p['recipe_count'] ?? 0) > 0 && (int)($p['out_of_stock_ingredients'] ?? 0) > 0)));
        $anchor = e(cat_anchor_id($key));
        $activeCls = $isFirstCat ? ' active' : '';
        $isFirstCat = false;

        $dbIcon   = $catIcons[$key] ?? '';
        $s        = strtolower(trim($key . ' ' . $label));
        $hasDbImg = !empty($dbIcon) && (str_contains($dbIcon, '/') || str_contains($dbIcon, '.'));
        $hasDbFa  = !empty($dbIcon) && str_starts_with($dbIcon, 'fa-');

        if (str_contains($s, 'hot') || str_contains($s, 'ក្តៅ')) {
            $catIcon = 'fa-mug-hot';
            $iconBg  = '#fee2e2';
            $iconClr = '#ef4444';
        } elseif (str_contains($s, 'tea') || str_contains($s, 'តែ') || str_contains($s, 'green') || str_contains($s, 'matcha')) {
            $catIcon = 'fa-leaf';
            $iconBg  = '#ecfdf5';
            $iconClr = '#10b981';
        } elseif (str_contains($s, 'smoothie') || str_contains($s, 'soda') || str_contains($s, 'juice') || str_contains($s, 'drink') || str_contains($s, 'ភេសជ្ជៈ')) {
            $catIcon = 'fa-glass-water';
            $iconBg  = '#e0f2fe';
            $iconClr = '#0ea5e9';
        } elseif (str_contains($s, 'non') || str_contains($s, 'milk') || str_contains($s, 'ទឹកដោះគោ')) {
            $catIcon = 'fa-bottle-water';
            $iconBg  = '#ede9fe';
            $iconClr = '#6366f1';
        } elseif (str_contains($s, 'ice') || str_contains($s, 'cold') || str_contains($s, 'ត្រជាក់')) {
            $catIcon = 'fa-snowflake';
            $iconBg  = '#e0f2fe';
            $iconClr = '#0284c7';
        } elseif (str_contains($s, 'coffee') || str_contains($s, 'កាហ្វេ')) {
            $catIcon = 'fa-mug-saucer';
            $iconBg  = '#fef3c7';
            $iconClr = '#d97706';
        } elseif (str_contains($s, 'cake') || str_contains($s, 'bakery') || str_contains($s, 'food') || str_contains($s, 'snack') || str_contains($s, 'នំ')) {
            $catIcon = 'fa-cookie-bite';
            $iconBg  = '#fef3c7';
            $iconClr = '#d97706';
        } else {
            $catIcon = 'fa-circle-dot';
            $iconBg  = '#f1f5f9';
            $iconClr = '#64748b';
        }

        if ($hasDbFa) {
            $catIcon = $dbIcon;
        }
      ?>
      <a href="#<?= $anchor ?>" class="cat-pill<?= $activeCls ?>" data-target="<?= $anchor ?>">
        <span class="cat-pill-icon-box" style="--cat-icon-bg: <?= $iconBg ?>; --cat-icon-color: <?= $iconClr ?>;">
          <?php if ($hasDbImg): ?>
            <img src="<?= e($dbIcon) ?>" alt="" class="cat-pill-img">
          <?php else: ?>
            <i class="fa-solid <?= e($catIcon) ?>"></i>
          <?php endif; ?>
        </span>
        <span class="cat-name"><?= e($label) ?></span>
        <span class="pill-count"><?= $count ?></span>
      </a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>

    <div class="menu-scroll" id="menuScroll">
      <main class="menu-main">



        <!-- PRODUCTS -->
        <?php if ($is_price_sort && !empty($flat_products)): ?>
          <div class="cat-header" style="margin-top:20px;">
            <div class="cat-title-text">
              <h2><?= $sort==='price_low' ? 'Price: Low to High' : 'Price: High to Low' ?></h2>
            </div>
          </div>
          <div class="product-grid">
            <?php foreach ($flat_products as $p): 
              $isOut = !empty($p['is_out']) || ($p['live_status'] ?? '') === 'out_of_stock';
              $isLow = !$isOut && (($p['live_status'] ?? '') === 'low_stock');
              $outReason = !empty($p['live_reason']) ? $p['live_reason'] : (!empty($p['missing_ingredients']) ? 'Out of ' . $p['missing_ingredients'] : 'Out of Stock');
              $servingsLeft = isset($p['live_max_servings']) ? $p['live_max_servings'] : (isset($p['max_servings']) ? (int)$p['max_servings'] : null);
              $catKey = $p['category'] ?? '';
              $catOpt = $categoryOpts[$catKey] ?? $categoryOpts[strtolower($catKey)] ?? null;
              $hasSizes = (int)($p['has_sizes'] ?? 0) === 1;
              $needsCustomization = $hasSizes || ($catOpt ? ((int)($catOpt['sweet'] ?? 0) === 1 || (int)($catOpt['ice'] ?? 0) === 1) : false);
            ?>
              <div class="product-card js-open-product relative <?= $isOut ? 'disabled out-of-stock-card cursor-not-allowed opacity-60 grayscale-[35%]' : '' ?>"
                   data-product-id="<?= (int)$p['product_id'] ?>"
                   data-product-name="<?= e($p['name']) ?>"
                   data-product-price="<?= e($p['price']) ?>"
                   data-product-image="<?= e($p['image']) ?>"
                   data-product-category="<?= e($p['category']) ?>"
                   data-product-desc="<?= e($p['description']) ?>"
                   data-product-badge="<?= e(product_badge_label($p)) ?>"
                   data-product-promo="<?= (int)($p['promo_percent'] ?? 0) ?>"
                   data-product-has-sizes="<?= $hasSizes ? '1' : '0' ?>"
                   data-has-customization="<?= $needsCustomization ? '1' : '0' ?>"
                   data-stock-status="<?= $isOut ? 'out_of_stock' : ($isLow ? 'low_stock' : 'in_stock') ?>"
                   data-max-servings="<?= $servingsLeft !== null ? $servingsLeft : '' ?>"
                   data-product-sizes='<?= htmlspecialchars(json_encode($sizesByProduct[(int)$p['product_id']] ?? []), ENT_QUOTES) ?>'
                   data-product-addons='<?= htmlspecialchars(json_encode($addonsByProduct[(int)$p['product_id']] ?? []), ENT_QUOTES) ?>'
                   title="<?= e($isOut ? $outReason : '') ?>"
                   role="button" tabindex="0">
                <div class="card-img relative">
                  <?php $__badge = product_badge_label($p); if ($__badge !== ''): ?><span class="product-badge"><?= e($__badge) ?></span><?php endif; ?>
                  <img src="<?= e($p['image']) ?>" loading="lazy" alt="<?= e($p['name']) ?>" onerror="this.onerror=null; this.src='images/logo.png'; this.style.objectFit='contain'; this.style.padding='16px';">
                  <div class="img-overlay"></div>
                  <button class="quick-add-btn" style="<?= $isOut ? 'display:none;' : '' ?>" onclick="event.stopPropagation(); quickAdd(<?= (int)$p['product_id'] ?>, <?= (float)$p['price'] ?>)" title="Add to cart"><i class="fa-solid fa-plus"></i></button>
                  <?php if ($isOut): ?>
                  <div class="out-of-stock-overlay absolute inset-0 bg-black/75 backdrop-blur-[2px] flex flex-col items-center justify-center text-center p-2.5 rounded-2xl z-20 transition-opacity duration-300">
                    <span class="px-2.5 py-1 rounded-full bg-rose-600 text-white text-[10.5px] font-extrabold uppercase tracking-wider shadow-md flex items-center gap-1.5">
                      <i class="fa-solid fa-circle-xmark text-xs"></i> <?= $isKm ? 'អស់ស្តុក' : 'Out of Stock' ?>
                    </span>
                    <span class="text-[10px] text-rose-200 mt-2 font-medium px-1.5 py-0.5 rounded bg-black/40 border border-rose-500/30 line-clamp-2 max-w-full leading-tight">
                      <i class="fa-solid fa-triangle-exclamation text-[9px] text-rose-400 mr-1"></i><?= e($outReason) ?>
                    </span>
                  </div>
                  <?php endif; ?>
                </div>
                <div class="card-info">
                  <div class="card-name"><?= e($p['name']) ?></div>
                  <div class="card-price flex items-center justify-between">
                    <span>$<?= number_format($p['price'], 2) ?></span>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php elseif (!empty($products)): ?>
          <?php foreach ($categories as $key => $label):
            if (empty($products[$key])) continue;
            $anchor = cat_anchor_id($key);
            $icon   = $catIcons[$key] ?? 'fa-circle';
          ?>
          <section class="cat-section" id="<?= e($anchor) ?>">
            <div class="cat-header">
              <div class="cat-icon">
                <?php if (str_contains($icon, '/')): ?>
                <img src="<?= e($icon) ?>" alt="" class="cat-header-img">
                <?php else: ?>
                <i class="fa-solid <?= $icon ?>"></i>
                <?php endif; ?>
              </div>
              <div class="cat-title-text">
                <h2><?= e($label) ?></h2>
                <?php $_in_stock = count(array_filter($products[$key], fn($p) => empty($p['is_out']) && (($p['live_status'] ?? '') !== 'out_of_stock'))); ?>
                <span><?= $_in_stock ?> <?= $_in_stock !== 1 ? __('item_plural', 'items') : __('item_single', 'item') ?></span>
              </div>
            </div>
            <div class="product-grid">
            <?php foreach ($products[$key] as $p): 
              $isOut = !empty($p['is_out']) || ($p['live_status'] ?? '') === 'out_of_stock';
              $isLow = !$isOut && (($p['live_status'] ?? '') === 'low_stock');
              $outReason = !empty($p['live_reason']) ? $p['live_reason'] : (!empty($p['missing_ingredients']) ? 'Out of ' . $p['missing_ingredients'] : 'Out of Stock');
              $servingsLeft = isset($p['live_max_servings']) ? $p['live_max_servings'] : (isset($p['max_servings']) ? (int)$p['max_servings'] : null);
              $catKey = $p['category'] ?? '';
              $catOpt = $categoryOpts[$catKey] ?? $categoryOpts[strtolower($catKey)] ?? null;
              $hasSizes = (int)($p['has_sizes'] ?? 0) === 1;
              $needsCustomization = $hasSizes || ($catOpt ? ((int)($catOpt['sweet'] ?? 0) === 1 || (int)($catOpt['ice'] ?? 0) === 1) : false);
            ?>
              <div class="product-card js-open-product relative <?= $isOut ? 'disabled out-of-stock-card cursor-not-allowed opacity-60 grayscale-[35%]' : '' ?>"
                   data-product-id="<?= (int)$p['product_id'] ?>"
                   data-product-name="<?= e($p['name']) ?>"
                   data-product-price="<?= e($p['price']) ?>"
                   data-product-image="<?= e($p['image']) ?>"
                   data-product-category="<?= e($p['category']) ?>"
                   data-product-desc="<?= e($p['description']) ?>"
                   data-product-badge="<?= e(product_badge_label($p)) ?>"
                   data-product-promo="<?= (int)($p['promo_percent'] ?? 0) ?>"
                   data-product-has-sizes="<?= $hasSizes ? '1' : '0' ?>"
                   data-has-customization="<?= $needsCustomization ? '1' : '0' ?>"
                   data-stock-status="<?= $isOut ? 'out_of_stock' : ($isLow ? 'low_stock' : 'in_stock') ?>"
                   data-max-servings="<?= $servingsLeft !== null ? $servingsLeft : '' ?>"
                   data-product-sizes='<?= htmlspecialchars(json_encode($sizesByProduct[(int)$p['product_id']] ?? []), ENT_QUOTES) ?>'
                   data-product-addons='<?= htmlspecialchars(json_encode($addonsByProduct[(int)$p['product_id']] ?? []), ENT_QUOTES) ?>'
                   title="<?= e($isOut ? $outReason : '') ?>"
                   role="button" tabindex="0">
                <div class="card-img relative">
                  <?php $__badge = product_badge_label($p); if ($__badge !== ''): ?><span class="product-badge"><?= e($__badge) ?></span><?php endif; ?>
                  <img src="<?= e($p['image']) ?>" loading="lazy" alt="<?= e($p['name']) ?>" onerror="this.onerror=null; this.src='images/logo.png'; this.style.objectFit='contain'; this.style.padding='16px';">
                  <div class="img-overlay"></div>
                  <button class="quick-add-btn" style="<?= $isOut ? 'display:none;' : '' ?>" onclick="event.stopPropagation(); quickAdd(<?= (int)$p['product_id'] ?>, <?= (float)$p['price'] ?>)" title="Add to cart"><i class="fa-solid fa-plus"></i></button>
                  <?php if ($isOut): ?>
                  <div class="out-of-stock-overlay absolute inset-0 bg-black/75 backdrop-blur-[2px] flex flex-col items-center justify-center text-center p-2.5 rounded-2xl z-20 transition-opacity duration-300">
                    <span class="px-2.5 py-1 rounded-full bg-rose-600 text-white text-[10.5px] font-extrabold uppercase tracking-wider shadow-md flex items-center gap-1.5">
                      <i class="fa-solid fa-circle-xmark text-xs"></i> <?= $isKm ? 'អស់ស្តុក' : 'Out of Stock' ?>
                    </span>
                    <span class="text-[10px] text-rose-200 mt-2 font-medium px-1.5 py-0.5 rounded bg-black/40 border border-rose-500/30 line-clamp-2 max-w-full leading-tight">
                      <i class="fa-solid fa-triangle-exclamation text-[9px] text-rose-400 mr-1"></i><?= e($outReason) ?>
                    </span>
                  </div>
                  <?php endif; ?>
                </div>
                <div class="card-info">
                  <div class="card-name"><?= e($p['name']) ?></div>
                  <div class="card-price flex items-center justify-between">
                    <span>$<?= number_format($p['price'], 2) ?></span>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
            </div>
          </section>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="empty-state">
            <i class="fa-solid fa-mug-hot"></i>
            <h3>No items found</h3>
            <p>Try a different search term.</p>
            <?php if (!empty($search_term)): ?>
            <a href="menu.php" class="btn-clear-search">Clear search</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>

      </main><!-- /menu-main -->
    </div><!-- /menu-scroll -->
  </div><!-- /menu-panel -->

  <!-- ════ RIGHT: CART PANEL ════ -->
  <aside class="cart-panel" id="cart-sidebar">

    <!-- Cart header -->
    <div class="cp-header flex items-center justify-between">
      <div class="cp-title flex items-center gap-2">
        <i class="fa-solid fa-cart-shopping"></i>
        <span><?= __('cart', 'Cart') ?></span>
        <span class="cp-count" id="cpCount"><?= $cart_count ?> <?= $cart_count != 1 ? __('item_plural', 'items') : __('item_single', 'item') ?></span>
      </div>
      <div class="flex items-center gap-2">
        <?php if (!empty($cart)): ?>
        <button class="cp-clear-btn" id="cpClearBtn" onclick="cpClearCart()">
          <i class="fa-solid fa-trash"></i> <?= __('clear', 'Clear') ?>
        </button>
        <?php else: ?>
        <button class="cp-clear-btn" id="cpClearBtn" onclick="cpClearCart()" style="display:none">
          <i class="fa-solid fa-trash"></i> <?= __('clear', 'Clear') ?>
        </button>
        <?php endif; ?>
        <button type="button" class="cp-close-btn p-1 text-gray-400 hover:text-white" id="close-cart-btn" onclick="closeCartSidebar()" title="Close Cart">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>
    </div>

    <!-- Cart items (scrollable) -->
    <div class="cp-body" id="cpBody">

      <?php if (empty($cart)): ?>
      <!-- Empty state -->
      <div class="cp-empty" id="cpEmpty">
        <i class="fa-solid fa-mug-hot"></i>
        <p><?= __('cart_empty', 'Cart is empty') ?></p>
        <small><?= __('tap_drink_to_add', 'Tap a drink to add it') ?></small>
      </div>

      <?php else: ?>
      <!-- Items -->
      <div id="cpItems">
        <?php foreach ($cart as $i => $item):
          $qty  = (int)($item['qty'] ?? 1);
          $line = (float)($item['price'] ?? 0) * $qty;
          $pId  = (int)($item['product_id'] ?? 0);
          $pData = $productsById[$pId] ?? null;
          $canCustomize = false;
          if (isset($item['has_customization'])) {
              $canCustomize = ((int)$item['has_customization'] === 1);
          } elseif ($pData) {
              $catKey = $pData['category'] ?? '';
              $catOpt = $categoryOpts[$catKey] ?? $categoryOpts[strtolower($catKey)] ?? null;
              $hasSizes = (int)($pData['has_sizes'] ?? 0) === 1;
              $canCustomize = $hasSizes || ($catOpt ? ((int)($catOpt['sweet'] ?? 0) === 1 || (int)($catOpt['ice'] ?? 0) === 1) : false);
          } else {
              $canCustomize = !empty($item['sweetness']) || !empty($item['ice']) || !empty($item['size_label']);
          }
          $meta = array_filter([
            !empty($item['size_label']) ? 'Size: '.$item['size_label']  : '',
            !empty($item['sweetness'])  ? 'Sweet: '.$item['sweetness']  : '',
            !empty($item['ice'])        ? 'Ice: '.$item['ice']          : '',
            !empty($item['milk'])       ? 'Milk: '.$item['milk']        : '',
          ]);
        ?>
        <div class="cp-item" id="cp-item-<?= $i ?>" data-product-id="<?= $pId ?>" data-cart-index="<?= $i ?>" data-can-customize="<?= $canCustomize ? '1' : '0' ?>">
          <?php if ($canCustomize): ?>
          <img src="<?= e($item['image'] ?? '') ?>" alt="<?= e($item['product_name'] ?? '') ?>" class="js-cart-item-open" onclick="openCartItemEditModal(<?= $i ?>)" style="cursor:pointer;" title="<?= __('click_to_customize', 'Click to customize') ?>" onerror="this.onerror=null; this.src='images/logo.png';">
          <div class="cp-item-info js-cart-item-open" onclick="openCartItemEditModal(<?= $i ?>)" style="cursor:pointer;" title="<?= __('click_to_customize', 'Click to customize') ?>">
          <?php else: ?>
          <img src="<?= e($item['image'] ?? '') ?>" alt="<?= e($item['product_name'] ?? '') ?>" style="cursor:default;" onerror="this.onerror=null; this.src='images/logo.png';">
          <div class="cp-item-info" style="cursor:default;">
          <?php endif; ?>
            <div class="cp-item-name"><?= e($item['product_name'] ?? '') ?></div>
            <?php if ($meta): ?><div class="cp-item-meta"><?= e(implode(' • ', $meta)) ?></div><?php endif; ?>
            <?php if (!empty($item['addons'])): ?><div class="cp-item-meta"><?= e(implode(', ', array_map(fn($a) => $a['name'], $item['addons']))) ?></div><?php endif; ?>
            <?php $__op = (float)($item['orig_price'] ?? $item['price']); $__pp = (int)($item['promo_percent'] ?? 0); ?>
            <div class="cp-item-price">
              <?php if ($__pp > 0 && $__op > (float)$item['price']): ?>
              <s style="color:#aaa;font-size:11px;margin-right:5px;">$<?= number_format($__op, 2) ?></s>
              <?php endif; ?>
              $<span id="cp-line-<?= $i ?>"><?= number_format((float)($item['price'] ?? 0), 2) ?></span>
              <?php if ($__pp > 0): ?><span style="color:#e74c3c;font-size:9px;font-weight:700;margin-left:4px;"><?= $__pp ?>% OFF</span><?php endif; ?>
            </div>
          </div>
          <div class="cp-item-actions" style="display:flex; flex-direction:column; align-items:flex-end; gap:4px;">
            <div style="display:flex; align-items:center; gap:6px;">
              <div class="cp-qty">
                <button onclick="cpChangeQty(<?= $i ?>, -1)">−</button>
                <input type="number" id="cp-qty-<?= $i ?>" value="<?= $qty ?>" min="1" onchange="cpSetQty(<?= $i ?>,this.value)" onfocus="this.select()" onkeydown="if(event.key==='Enter'){event.preventDefault();cpSetQty(<?= $i ?>,this.value);this.blur();}">
                <button onclick="cpChangeQty(<?= $i ?>, 1)">+</button>
              </div>
              <button class="cp-remove" onclick="cpRemoveItem(<?= $i ?>)" title="Remove"><i class="fa-solid fa-trash-can"></i></button>
            </div>
            <?php
              $itemDiscType = $item['discount_type'] ?? '';
              $itemDiscAmt  = (float)($item['discount_amount'] ?? 0);
              $hasItemDisc  = $itemDiscAmt > 0;
              $itemDiscLabel = '';
              if ($hasItemDisc) {
                  if ($itemDiscType === 'percent') {
                      $itemDiscLabel = (int)$itemDiscAmt . '%';
                  } else {
                      $itemDiscLabel = '$' . number_format($itemDiscAmt, 2);
                  }
              }
            ?>
            <?php if ($hasItemDisc): ?>
            <button type="button" class="cp-item-disc-btn active" onclick="cpOpenItemDiscount(<?= $i ?>)" title="Edit Discount" style="color:#2ecc71;border-color:rgba(46,204,113,0.4);background:rgba(46,204,113,0.1);"><i class="fa-solid fa-check"></i> Discount <?= $itemDiscLabel ?> <span onclick="event.stopPropagation();cpClearItemDiscount(<?= $i ?>)" title="Remove item discount" style="margin-left:5px;color:#e74c3c;font-weight:bold;cursor:pointer;padding:0 3px;">&times;</span></button>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div><!-- /cpItems -->
      <script>window.currentCartItems = <?= json_encode(array_values(function_exists('get_cart_payload') ? (get_cart_payload($conn)['items'] ?? []) : [])) ?>;</script>
      <?php endif; ?>
    </div><!-- /cp-body -->

    <!-- Cart footer (always visible when cart has items) -->
    <div class="cp-footer" id="cpFooter" <?= empty($cart) ? 'style="display:none"' : '' ?>>
      <!-- Summary rows -->
      <div class="cp-summary" id="cpSummary">
        <div class="cp-sum-row">
          <span><?= __('subtotal', 'Subtotal') ?></span>
          <span id="cpSubtotal">$<?= number_format($cp_subtotal, 2) ?></span>
        </div>
        <div class="cp-sum-row discount" id="cpItemPromoRow" style="<?= $cp_item_promos > 0 ? '' : 'display:none' ?>">
          <span>&#x1F3F7;&#xFE0F; Item Promos</span>
          <span id="cpItemPromoAmt">-$<?= number_format($cp_item_promos, 2) ?></span>
        </div>
        <div class="cp-sum-row discount" id="cpHHRow" style="<?= $cp_hh > 0 ? '' : 'display:none' ?>">
          <span>&#x1F305; Happy Hour (<?= HAPPY_HOUR_DISCOUNT ?>% off)</span>
          <span id="cpHHAmt">-$<?= number_format($cp_hh, 2) ?></span>
        </div>
        <div class="cp-sum-row discount" id="cpManualRow" style="<?= $cp_manual > 0 ? '' : 'display:none' ?>">
          <span id="cpManualLabel">&#x1F3F7;&#xFE0F; <?= e($cp_manual_label) ?></span>
          <span id="cpManualAmt">-$<?= number_format($cp_manual, 2) ?></span>
        </div>

        <!-- Discount panel -->
        <div id="cpDiscountPanel" style="display:none !important;">
          <?php if ($cp_manual > 0): ?>
          <button type="button" class="cp-discount-toggle remove" onclick="cpClearDiscount()">
            <i class="fa-solid fa-xmark"></i> Remove Discount
          </button>
          <?php else: ?>
          <button type="button" class="cp-discount-toggle" id="cpAddDiscBtn" onclick="cpOpenDiscount()">
            <i class="fa-solid fa-tag"></i> Add Discount
          </button>
          <?php endif; ?>
          <div id="cpDiscountForm" style="display:none">
            <div class="cp-dtype-row">
              <button type="button" class="cp-dtype-btn active" id="cpDtypePercent" onclick="cpSetDType('percent')">% Percent</button>
              <button type="button" class="cp-dtype-btn" id="cpDtypeFlat" onclick="cpSetDType('flat')">$ Flat</button>
            </div>
            <div class="cp-disc-inputs">
              <input type="number" id="cpDiscAmount" placeholder="0" min="0" step="0.01">
              <input type="text"   id="cpDiscReason" placeholder="Reason (e.g. Staff, VIP)" maxlength="100">
            </div>
            <div class="cp-disc-actions">
              <button type="button" class="cp-btn-apply" onclick="cpApplyDiscount()"><i class="fa-solid fa-check"></i> Apply</button>
              <button type="button" class="cp-btn-cancel" onclick="cpCloseDiscount()">Cancel</button>
            </div>
          </div>
        </div>

        <div class="cp-sum-row" id="cpTaxRow" style="<?= (float)$cp_tax > 0 ? '' : 'display:none;' ?>">
          <span><?= __('tax', 'Tax') ?> (<?= TAX_RATE ?>%)</span>
          <span id="cpTax">$<?= number_format($cp_tax, 2) ?></span>
        </div>

        <?php if (!$add_to_order_mode): ?>
        <!-- Hidden default payment selector (payment settled in payment modal) -->
        <div id="cpDirectPayMethods" style="display:none;">
          <input type="checkbox" name="payment_methods[]" value="cash" checked>
        </div>
        <?php else: ?>
        <!-- Add-to-order mode: payment already set, just show a note -->
        <div class="cp-section" style="background:rgba(209,144,75,.08);border:1px solid rgba(209,144,75,.3);border-radius:10px;padding:10px 12px;margin-top:10px;">
          <div style="font-size:12px;font-weight:600;color:#d1904b;margin-bottom:4px;"><i class="fa-solid fa-clock-rotate-left"></i> Adding to Pay Later Order #<?= $add_to_order_mode ?></div>
          <div style="font-size:11px;color:#888;line-height:1.5;">Payment was already set when this order was created. Just select items and confirm to add them.</div>
        </div>
        <?php endif; ?>

      </div><!-- /cp-summary -->

      <!-- Total hidden here — shown on the confirm/payment screen instead.
           #cpTotal kept in DOM (display:none) because cpGetCartTotal() reads it for payment math. -->
      <div class="cp-total-row" style="display:none">
        <span class="lbl">Total</span>
        <span class="amt" id="cpTotal">$<?= number_format($cp_total, 2) ?></span>
      </div>
      <form method="post" action="confirm_order.php" id="cpCheckoutForm">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="order_type" id="cpOrderTypeInput" value="drink_in">
        <input type="hidden" name="is_add_to_order" value="<?= $add_to_order_mode > 0 ? '1' : '0' ?>">
        <?php if ($add_to_order_mode > 0): ?>
        <input type="hidden" name="add_to_order_id" value="<?= $add_to_order_mode ?>">
        <?php endif; ?>
        <div id="cpPaymentInputs"></div>
        <button type="button" class="cp-confirm-btn<?= $add_to_order_mode ? ' paylater' : '' ?>" id="cpConfirmBtn" onclick="cpOnConfirmOrderClick()">
          <i class="fa-solid fa-<?= $add_to_order_mode ? 'cart-plus' : 'credit-card' ?>" id="cpConfirmIcon"></i>
          <span id="cpConfirmText"><?= $add_to_order_mode ? __('add_to_order', 'Add to Order #').$add_to_order_mode : __('confirm_order', 'Confirm Order') ?></span>
        </button>
      </form>
    </div>

  </aside><!-- /cart-panel -->
</div><!-- /pos-layout -->

<!-- PRODUCT MODAL (DRINK CUSTOMIZATION / EDIT PRODUCT) -->
<div id="product-modal" class="modal fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4" style="display:none; z-index:99999;">
  <div class="modal-card bg-white rounded-[32px] max-w-[430px] w-full p-6 md:p-7 shadow-[0_25px_60px_-15px_rgba(0,0,0,0.3)] relative overflow-hidden flex flex-col text-slate-900 border border-slate-100">
    
    <!-- Top-Right Close Button -->
    <button class="modal-close absolute top-5 right-5 w-8 h-8 rounded-full bg-slate-100/90 text-slate-400 hover:text-slate-700 hover:bg-slate-200 flex items-center justify-center transition border-none cursor-pointer" onclick="closeModal()" title="Close">
      <i class="fa-solid fa-xmark text-xs"></i>
    </button>

    <!-- Top Centered Showcase -->
    <div class="flex flex-col items-center text-center mt-1 mb-5">
      <div class="relative w-28 h-28 md:w-32 md:h-32 rounded-2xl overflow-hidden shadow-sm bg-slate-50 border border-slate-100 flex items-center justify-center">
        <img id="modalImg" class="w-full h-full object-cover" src="images/logo.png" alt="">
        <span id="modalBadge" class="modal-product-badge absolute bottom-1 right-1 bg-amber-500 text-black text-[10px] font-extrabold px-1.5 py-0.5 rounded-md shadow" style="display:none"></span>
        <span class="absolute bottom-1.5 right-1.5 w-4 h-4 rounded-full bg-emerald-500 border-2 border-white shadow-xs"></span>
      </div>
      <h2 class="modal-name text-xl md:text-2xl font-black text-slate-900 mt-3 leading-tight tracking-tight" id="modalName">Product Name</h2>
      <p class="modal-desc text-xs font-semibold text-slate-400 mt-1" id="modalDesc"><?= $isKm ? 'កែសម្រួលភេសជ្ជៈរបស់អ្នក' : 'Customize your beverage' ?></p>
    </div>

    <!-- Unit Price & Quantity Stepper Row -->
    <div class="modal-price-row flex items-center justify-between bg-[#fffdf5] border border-[#fef08a] rounded-2xl p-4 mb-4">
      <div>
        <span class="text-xs font-bold text-amber-700/90 block"><?= $isKm ? 'តម្លៃរាយ' : 'Unit Price' ?></span>
        <span class="text-xl font-black text-amber-600 block mt-0.5 tracking-tight" id="modalPrice">$0.00</span>
      </div>
      <div class="qty-control flex items-center gap-3 bg-white border border-amber-200/90 rounded-xl px-3 py-1.5 shadow-xs">
        <button type="button" class="text-amber-600 hover:text-amber-700 text-lg font-bold w-5 h-5 flex items-center justify-center cursor-pointer select-none active:scale-95 transition" onclick="changeQty(-1)">−</button>
        <input type="number" id="modalQtyInput" value="1" min="1" max="100"
               class="w-8 bg-transparent text-center font-black text-sm text-slate-900 focus:outline-none"
               style="color: #0f172a !important; -webkit-text-fill-color: #0f172a !important;"
               oninput="onModalQtyInput(this)" onchange="onModalQtyChange(this)" onfocus="this.select()"
               onkeydown="if(event.key==='Enter'){event.preventDefault();addToCart();}">
        <button type="button" class="text-amber-600 hover:text-amber-700 text-lg font-bold w-5 h-5 flex items-center justify-center cursor-pointer select-none active:scale-95 transition" onclick="changeQty(1)">+</button>
      </div>
    </div>

    <!-- Option: Sweetness Level -->
    <div id="optSweetness" class="option-section mb-4">
      <div class="flex items-center justify-between mb-2">
        <span class="text-xs font-black text-slate-900"><?= $isKm ? 'កម្រិតជាតិផ្អែម' : 'Sweetness Level' ?></span>
        <span id="sweetnessBadge" class="text-[11px] font-bold text-amber-700 bg-amber-50 px-2.5 py-0.5 rounded-full border border-amber-200/70"><?= $isKm ? 'បានជ្រើស: 50%' : 'Selected: 50%' ?></span>
      </div>
      <div class="grid grid-cols-5 gap-2" id="sweetnessPills">
        <?php foreach (['0%','25%','50%','75%','100%'] as $s): ?>
        <button type="button" class="option-pill <?= $s==='50%'?'active':'' ?>" data-group="sweetness" data-value="<?= $s ?>" onclick="selectPill(this)"><?= $s ?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Option: Ice Level -->
    <div id="optIce" class="option-section mb-5">
      <div class="flex items-center justify-between mb-2">
        <span class="text-xs font-black text-slate-900"><?= $isKm ? 'កម្រិតទឹកកក' : 'Ice Level' ?></span>
        <span id="iceBadge" class="text-[11px] font-bold text-amber-700 bg-amber-50 px-2.5 py-0.5 rounded-full border border-amber-200/70"><?= $isKm ? 'បានជ្រើស: ធម្មតា' : 'Selected: Normal' ?></span>
      </div>
      <div class="grid grid-cols-4 gap-2" id="icePills">
        <?php 
        $ice_map = [
          'No Ice'     => $isKm ? 'គ្មាន' : 'No Ice',
          'Less Ice'   => $isKm ? 'តិច' : 'Less Ice',
          'Normal Ice' => $isKm ? 'ធម្មតា' : 'Normal Ice',
          'More Ice'   => $isKm ? 'ច្រើន' : 'More Ice'
        ];
        foreach ($ice_map as $ic_val => $ic_label): ?>
        <button type="button" class="option-pill <?= $ic_val==='Normal Ice'?'active':'' ?>" data-group="ice" data-value="<?= $ic_val ?>" onclick="selectPill(this)"><?= $ic_label ?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Bottom Total & Action Button -->
    <div class="modal-footer flex items-center justify-between pt-3 border-t border-slate-100 mt-1">
      <div>
        <span class="text-[11px] font-bold text-slate-400 block"><?= $isKm ? 'សរុបចុងក្រោយ' : 'Final Total' ?></span>
        <span class="text-2xl font-black text-slate-900 tracking-tight block mt-0.5" id="modalTotalDisplay">$0.00</span>
      </div>
      <button type="button" class="btn-add-to-cart bg-[#f59e0b] hover:bg-[#d97706] active:scale-95 text-white font-extrabold px-8 py-3.5 rounded-2xl flex items-center gap-2 transition shadow-lg shadow-amber-500/30 cursor-pointer" onclick="addToCart()">
        <i class="fa-solid fa-check text-base"></i> <span id="modalBtnText"><?= $isKm ? 'រក្សាទុក' : 'Save' ?></span>
      </button>
    </div>
  </div>
</div>

<!-- ── CASH PAYMENT SETTLEMENT MODAL (POS LAYOUT) ── -->
<div id="cashPaymentModal" class="modal fixed inset-0 bg-black/60 backdrop-blur-md flex items-center justify-center p-3 md:p-6 z-[99999]" style="display:none;">
  <div class="cpm-card flex flex-col max-h-[94vh] w-full max-w-5xl shadow-2xl rounded-3xl overflow-hidden bg-white border border-slate-100">
    
    <!-- Modal Header -->
    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-white shrink-0">
      <div class="flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-emerald-50 border border-emerald-100 flex items-center justify-center text-emerald-500 font-bold text-lg shadow-sm transition-all duration-200" id="cpmModalHeaderIconBox">
          <i class="fa-solid fa-money-bill-wave" id="cpmModalHeaderIcon"></i>
        </div>
        <div>
          <h2 class="text-base md:text-lg font-extrabold text-slate-900 leading-tight transition-colors duration-200" id="cpmModalHeaderTitle"><?= __('cpm_modal_title', 'Cash Payment Settlement') ?></h2>
          <p class="text-xs text-slate-400 font-medium transition-colors duration-200" id="cpmModalHeaderSub"><?= __('cpm_modal_subtitle', 'Take order cash & calculate change') ?></p>
        </div>
      </div>
      <button type="button" onclick="closeCashPaymentModal()" class="w-9 h-9 rounded-full bg-slate-50 border border-slate-200 text-slate-500 hover:text-slate-900 hover:bg-slate-100 flex items-center justify-center transition" title="Close (Esc)">
        <i class="fa-solid fa-xmark text-sm"></i>
      </button>
    </div>

    <!-- Modal Body (2-Column POS Layout) -->
    <div class="p-4 md:p-6 overflow-y-auto flex-1 grid grid-cols-1 lg:grid-cols-12 gap-5 items-stretch bg-slate-50/50">
      
      <!-- LEFT COLUMN: Current Transaction Summary (5 cols) -->
      <div class="lg:col-span-5 xl:col-span-5 bg-white border border-slate-200/80 rounded-2xl p-5 flex flex-col shadow-sm h-full">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 shrink-0">
          <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-800 flex items-center gap-2">
            <i class="fa-solid fa-file-invoice text-emerald-500"></i> <?= __('cpm_summary_title', 'Current Transaction Summary') ?>
          </h3>
          <span class="text-xs font-bold px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-700 border border-slate-200" id="cpmItemCount">0 items</span>
        </div>

        <!-- Table Column Headers: Items, Price, Qty, Total -->
        <div class="grid grid-cols-12 gap-2 py-2.5 text-[11px] font-bold text-slate-400 uppercase tracking-wider shrink-0 border-b border-slate-100 cpm-table-head">
          <div class="col-span-6"><?= __('cpm_col_items', 'Items') ?></div>
          <div class="col-span-2 text-right"><?= __('cpm_col_price', 'Price') ?></div>
          <div class="col-span-2 text-center"><?= __('cpm_col_qty', 'Qty') ?></div>
          <div class="col-span-2 text-right"><?= __('cpm_col_total', 'Total') ?></div>
        </div>

        <!-- Items Table -->
        <div class="flex-1 overflow-y-auto min-h-[140px] max-h-[260px] divide-y divide-slate-50" id="cpmItemsList">
          <!-- Dynamic Items Populated here -->
        </div>

        <!-- Running Subtotal, Tax, Discount & Total Due (Pinned to Bottom) -->
        <div class="pt-4 border-t border-slate-100 space-y-2 mt-auto shrink-0">
          <div class="flex justify-between text-xs font-semibold text-slate-500">
            <span><?= __('cpm_subtotal', 'Running Subtotal') ?></span>
            <span class="font-bold text-slate-800" id="cpmSubtotal">$0.00</span>
          </div>
          <div class="flex justify-between text-xs font-semibold text-slate-500" id="cpmDiscountRow" style="display:none;">
            <span class="text-emerald-600"><?= __('cpm_discount', 'Discount') ?></span>
            <span class="font-bold text-emerald-600" id="cpmDiscount">-$0.00</span>
          </div>
          <div class="flex justify-between text-xs font-semibold text-slate-500" id="cpmTaxRow" style="display:none;">
            <span><?= __('cpm_tax', 'Tax') ?></span>
            <span class="font-bold text-slate-800" id="cpmTax">$0.00</span>
          </div>

          <div class="pt-3 border-t border-slate-100 flex items-baseline justify-between">
            <div>
              <div class="text-xs font-extrabold uppercase tracking-wider text-slate-900"><?= __('cpm_total_due', 'Total Amount Due') ?></div>
              <div class="text-xs font-semibold text-slate-400 mt-0.5" id="cpmTotalKhr">៛ 0</div>
            </div>
            <div class="text-2xl md:text-3xl font-black text-slate-900 tracking-tight" id="cpmTotalUsd">$0.00</div>
          </div>
        </div>
      </div>

      <!-- RIGHT COLUMN: Payment Details & Change Calculated (7 cols) -->
      <div class="lg:col-span-7 xl:col-span-7 flex flex-col gap-4">
        
        <!-- Top Box: Payment Details -->
        <div class="bg-white border border-slate-200/80 rounded-2xl p-5 shadow-sm flex flex-col gap-3" id="cpmPaymentDetailsBox">
          <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-800 flex items-center gap-2" id="cpmBoxTitle">
              <i class="fa-solid fa-calculator text-emerald-500"></i> <?= __('cpm_payment_details', 'Payment Details') ?>
            </h3>
            
            <!-- Exchange rate badge -->
            <div class="flex items-center gap-2">
              <div class="flex items-center gap-1.5 px-3 py-1 rounded-lg bg-slate-50 border border-slate-200 text-xs font-bold text-slate-700" id="cpmRateBadge" title="Current Exchange Rate">
                <i class="fa-solid fa-arrow-right-arrow-left text-slate-400 text-[10px]"></i>
                <span>1$ = <b class="text-slate-900" id="cpmRateDisplay">៛<?= number_format(defined('KHR_RATE') ? (int)KHR_RATE : 4100) ?></b></span>
              </div>
            </div>
          </div>

          <!-- Cash Payment View (Full-Width Dual Inputs Layout) -->
          <div id="cpmCashView" class="flex flex-col gap-3">
            
            <!-- Dual Inputs Grid (Dollar + Riel Side-by-Side) -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              
              <!-- 1. Received Dollar ($ USD) Input Card -->
              <div class="p-3.5 rounded-2xl bg-slate-50 border-2 border-slate-200/80 hover:border-emerald-500 focus-within:border-emerald-500 focus-within:bg-white transition-all shadow-sm flex flex-col justify-between gap-1.5" id="cpmCardUsd">
                <div class="flex items-center justify-between">
                  <label for="cpmReceivedUsdInput" class="text-xs font-extrabold text-slate-700 uppercase tracking-wider flex items-center gap-1.5 cursor-pointer">
                    <span class="w-5 h-5 rounded-full bg-slate-100 text-slate-700 flex items-center justify-center text-xs font-black shadow-sm">$</span>
                    <?= $isKm ? 'ប្រាក់ដុល្លារទទួល ($)' : __('cpm_received_usd', 'Received Dollar ($)') ?>
                  </label>
                  <button type="button" onclick="cpmClearUsdInput()" class="text-[11px] font-bold text-slate-400 hover:text-rose-500 transition flex items-center gap-1 cursor-pointer" title="Clear USD">
                    <i class="fa-solid fa-rotate-left text-[9px]"></i> <?= $isKm ? 'លុប' : 'Clear' ?>
                  </button>
                </div>
                <div class="relative flex items-center mt-1">
                  <span class="text-2xl font-black text-slate-400 mr-2 select-none">$</span>
                  <input type="text" id="cpmReceivedUsdInput" 
                         class="w-full bg-transparent text-2xl md:text-3xl font-black text-slate-900 text-right focus:outline-none tracking-tight"
                         placeholder="0.00" oninput="cpmOnManualUsdInput(this.value)">
                </div>
              </div>

              <!-- 2. Received Riel (៛ KHR) Input Card -->
              <div class="p-3.5 rounded-2xl bg-slate-50 border-2 border-slate-200/80 hover:border-emerald-500 focus-within:border-emerald-500 focus-within:bg-white transition-all shadow-sm flex flex-col justify-between gap-1.5" id="cpmCardKhr">
                <div class="flex items-center justify-between">
                  <label for="cpmReceivedKhrInput" class="text-xs font-extrabold text-slate-700 uppercase tracking-wider flex items-center gap-1.5 cursor-pointer">
                    <span class="w-5 h-5 rounded-full bg-slate-100 text-slate-700 flex items-center justify-center text-xs font-black shadow-sm">៛</span>
                    <?= $isKm ? 'ប្រាក់រៀលទទួល (៛)' : __('cpm_received_khr', 'Received Riel (៛)') ?>
                  </label>
                  <button type="button" onclick="cpmClearKhrInput()" class="text-[11px] font-bold text-slate-400 hover:text-rose-500 transition flex items-center gap-1 cursor-pointer" title="Clear Riel">
                    <i class="fa-solid fa-rotate-left text-[9px]"></i> <?= $isKm ? 'លុប' : 'Clear' ?>
                  </button>
                </div>
                <div class="relative flex items-center mt-1">
                  <span class="text-2xl font-black text-slate-400 mr-2 select-none">៛</span>
                  <input type="text" id="cpmReceivedKhrInput" 
                         class="w-full bg-transparent text-2xl md:text-3xl font-black text-slate-900 text-right focus:outline-none tracking-tight"
                         placeholder="0" oninput="cpmOnManualKhrInput(this.value)">
                </div>
              </div>

            </div>

            <!-- 3. Combined Total Received Banner -->
            <div class="px-4 py-2.5 rounded-2xl bg-slate-50 border border-slate-200 flex items-center justify-between shadow-sm transition-all duration-200" id="cpmTotalRecBanner">
              <span class="text-xs font-extrabold text-slate-700 uppercase tracking-wider flex items-center gap-2" id="cpmTotalRecLabel">
                <i class="fa-solid fa-hand-holding-dollar text-emerald-500 text-sm" id="cpmTotalRecIcon"></i> <span id="cpmTotalRecText"><?= $isKm ? 'សរុបប្រាក់ទទួលបាន:' : __('cpm_total_received', 'Total Received') . ':' ?></span>
              </span>
              <div class="text-right flex items-baseline gap-2">
                <span class="text-lg md:text-xl font-black text-slate-900" id="cpmTotalReceivedUsd">$0.00</span>
                <span class="text-xs md:text-sm font-bold text-slate-500" id="cpmTotalReceivedKhr">(៛ 0)</span>
              </div>
            </div>

            <!-- 4. Payment Method Selector -->
            <div class="pt-2 border-t border-slate-100">
              <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1.5"><?= __('cpm_payment_method', 'Payment Method') ?></div>
              <div class="grid grid-cols-2 gap-3" id="cpmMethodPills">
                <button type="button" id="cpmMethodCash" onclick="cpmSetMethod('cash')" class="py-2.5 px-4 rounded-xl cpm-method-active font-bold text-xs md:text-sm flex items-center justify-center gap-2 transition cursor-pointer">
                  <i class="fa-solid fa-money-bill-wave"></i> <?= __('cpm_method_cash', 'Cash') ?>
                </button>
                <button type="button" id="cpmMethodBakong" onclick="cpmSetMethod('bakong')" class="py-2.5 px-4 rounded-xl font-bold text-xs md:text-sm flex items-center justify-center gap-2 transition cursor-pointer">
                  <i class="fa-solid fa-qrcode text-slate-500"></i> <?= __('cpm_method_bakong', 'Bakong QR') ?>
                </button>
              </div>
            </div>

          </div>

          <!-- Bakong QR View -->
          <div id="cpmBakongQrView" style="display:none;" class="flex flex-col items-center justify-center p-3 text-center">
            <div class="w-full max-w-[270px] bg-white rounded-3xl p-4 text-black shadow-2xl border-2 border-rose-500 relative overflow-hidden flex flex-col items-center" style="box-shadow: 0 16px 36px -8px rgba(225, 29, 72, 0.25);">
              <div class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-full bg-gradient-to-r from-rose-600 to-red-600 text-white font-black text-[11px] uppercase tracking-wider shadow-sm mb-3">
                <i class="fa-solid fa-qrcode text-xs"></i> <?= __('cpm_khqr_scan_pay', 'KHQR SCAN TO PAY') ?>
              </div>
              <div id="cpmBakongQrCanvas" class="flex items-center justify-center min-h-[160px] min-w-[160px] my-1 bg-white p-1 rounded-xl"></div>
              <div class="mt-3 pt-2.5 border-t border-dashed border-rose-200 w-full flex items-baseline justify-center gap-2">
                <span class="text-2xl font-black text-slate-900" id="cpmBakongDispUsd">$0.00</span>
                <span class="text-xs font-bold text-rose-600" id="cpmBakongDispKhr">(៛ 0)</span>
              </div>
              <div class="text-[11px] font-semibold text-slate-500 mt-1 flex items-center justify-center gap-1">
                <i class="fa-solid fa-circle-check text-emerald-500 text-[10px]"></i>
                <span id="cpmBakongMerchant">The Bird's Nest Coffee</span>
              </div>
            </div>
            <div id="cpmBakongStatusBadge" class="mt-3.5 px-4 py-2 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-extrabold flex items-center justify-center gap-2 shadow-sm">
              <i class="fa-solid fa-spinner fa-spin text-rose-500 text-xs" id="cpmBakongSpinner"></i>
              <span id="cpmBakongStatusText"><?= __('cpm_waiting_bakong', 'Waiting for Bakong payment...') ?></span>
            </div>
          </div>

          <!-- Bakong Success Celebration -->
          <div id="cpmBakongSuccessView" style="display:none;" class="flex flex-col items-center justify-center p-6 text-center">
            <div class="w-16 h-16 rounded-full bg-emerald-100 border-2 border-emerald-500 text-emerald-600 flex items-center justify-center text-3xl mb-3 shadow-lg">
              <i class="fa-solid fa-check"></i>
            </div>
            <h3 class="text-xl font-extrabold text-emerald-600 mb-1"><?= __('cpm_payment_received', 'Payment Received!') ?></h3>
            <p class="text-xs text-slate-500 mb-3" id="cpmSuccessOrderInfo"><?= __('cpm_order_settled_bakong', 'Order has been settled via Bakong KHQR.') ?></p>
            <div class="text-xs text-slate-400 flex items-center gap-1.5 font-medium">
              <i class="fa-regular fa-clock"></i> <?= __('cpm_auto_completing', 'Auto-completing in') ?> <b class="text-slate-800" id="cpmSuccessCountdown">2</b>s...
            </div>
          </div>

        </div>

        <!-- Bottom Box: Change Calculated & Actions -->
        <div class="bg-white border border-slate-200/80 rounded-2xl p-5 shadow-sm flex flex-col gap-3.5" id="cpmBottomBox">
          
          <div id="cpmChangeInfoWrap" class="flex flex-col gap-3">
            <div class="flex items-center justify-between pb-2 border-b border-slate-100">
              <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-800 flex items-center gap-2" id="cpmBottomHeader">
                <i class="fa-solid fa-coins text-emerald-500" id="cpmBottomHeaderIcon"></i> <span id="cpmBottomHeaderTitle"><?= __('cpm_change_calc', 'Change Calculated') ?></span>
              </h3>
              <span class="text-xs font-semibold text-slate-500"><?= __('cpm_return_method', 'Return Method') ?>: <b class="text-slate-900" id="cpmReturnMethod"><?= __('cpm_method_cash', 'Cash') ?></b></span>
            </div>

            <!-- Return Change Currency Options -->
            <div class="flex items-center justify-between gap-2" id="cpmReturnOptionsRow">
              <span class="text-xs font-bold text-slate-500">
                <?= __('cpm_return_change_in', 'Return Change In:') ?>
              </span>
              <div class="inline-flex p-1 rounded-xl bg-slate-100 border border-slate-200 gap-1" id="cpmChangeModeWrap">
                <button type="button" id="cpmModeMixed" onclick="cpmSetChangeMode('mixed')"
                        class="px-3 py-1 rounded-lg text-xs font-extrabold transition cpm-mode-active"
                        title="Whole dollars in USD, cents in Riel">
                  <?= __('cpm_mode_mixed', 'Dollar + Riel') ?>
                </button>
                <button type="button" id="cpmModeKhr" onclick="cpmSetChangeMode('khr')"
                        class="px-3 py-1 rounded-lg text-xs font-bold text-slate-500 hover:text-slate-800 transition"
                        title="All change in Cambodian Riel">
                  <?= __('cpm_mode_khr', 'Riel Only') ?>
                </button>
              </div>
            </div>

            <!-- Digital KHQR Info Row -->
            <div class="items-center justify-between gap-2" id="cpmBakongDigitalNoticeRow" style="display:none;">
              <span class="text-xs font-bold text-slate-700 flex items-center gap-1.5">
                <i class="fa-solid fa-qrcode text-emerald-500"></i> <?= __('cpm_bakong_no_change_label', 'KHQR Transfer') ?>:
              </span>
              <span class="text-xs font-bold text-slate-700 py-1 px-3 rounded-lg bg-slate-100 border border-slate-200" id="cpmBakongExactNotice">
                <?= __('cpm_exact_no_change', 'Exact Amount • No Change Needed') ?>
              </span>
            </div>

            <!-- Highlight Change to Return Box (Big Green Alert Display) -->
            <div class="rounded-2xl p-4 bg-emerald-50 border-2 border-emerald-200 flex items-center justify-between transition min-h-[64px]" id="cpmChangeBox">
              <div class="flex-1 pr-3 min-w-0">
                <span class="text-[11px] font-extrabold uppercase tracking-wider text-emerald-800 cpm-change-lbl" id="cpmChangeTitle"><?= __('cpm_change_to_return', 'Change to Return') ?></span>
                <div class="text-xs font-bold text-emerald-700 truncate mt-0.5" id="cpmChangeKhr">៛ 0</div>
              </div>
              <div class="text-2xl md:text-3xl font-black text-emerald-700 text-right tracking-tight whitespace-nowrap" id="cpmChangeUsd">$0.00</div>
            </div>
          </div>

          <!-- Action Buttons for Cash Mode -->
          <div class="flex items-center gap-3 pt-1" id="cpmNormalActions">
            <button type="button" onclick="closeCashPaymentModal()" class="btn-cpm-cancel py-3 px-6 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 hover:text-slate-900 font-extrabold text-xs md:text-sm transition flex-shrink-0">
              <?= __('cpm_cancel', 'Cancel') ?>
            </button>
            <button type="button" onclick="cpmConfirmPayment()" id="cpmApplyBtn" class="flex-1 py-3 px-6 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white font-black text-xs md:text-sm shadow-lg shadow-emerald-500/25 flex items-center justify-center gap-2 transition hover:scale-[1.01] active:scale-[0.99] cursor-pointer">
              <i class="fa-solid fa-circle-check text-base"></i> <?= __('cpm_apply_payment', 'Apply Payment & Print Receipt') ?>
            </button>
          </div>

          <!-- Action Buttons for Active Bakong QR Mode -->
          <div class="flex items-center gap-3 pt-1" id="cpmBakongActions" style="display:none;">
            <button type="button" onclick="cpmCancelActiveBakongOrder()" class="py-3 px-5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-extrabold text-xs md:text-sm transition flex-shrink-0 flex items-center gap-1.5">
              <i class="fa-solid fa-xmark"></i> <?= __('cpm_cancel_order', 'Cancel Order') ?>
            </button>
            <button type="button" id="cpmBtnManualConfirm" onclick="cpmManualConfirmBakong()" class="flex-1 py-3 px-6 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white font-black text-xs md:text-sm shadow-lg shadow-emerald-500/25 flex items-center justify-center gap-2 transition hover:scale-[1.01] active:scale-[0.99] cursor-pointer">
              <i class="fa-solid fa-circle-check text-base"></i> <?= __('cpm_confirm_received', 'Confirm Payment Received') ?>
            </button>
          </div>

        </div>

      </div>

    </div>

  </div>
</div>

<!-- ── CART ITEM REMOVE CONFIRMATION MODAL ── -->
<div id="cartRemoveModal" class="modal fixed inset-0 bg-black/75 backdrop-blur-md flex items-center justify-center p-4 z-[99999]" style="display:none;">
  <div class="cpm-card bg-[#16161e] border border-[#2d2d3e] rounded-2xl shadow-2xl p-6 max-w-sm w-full text-center relative">
    <div class="w-14 h-14 mx-auto mb-4 rounded-2xl bg-red-500/15 border border-red-500/30 flex items-center justify-center text-red-400 text-2xl shadow-lg shadow-red-500/10">
      <i class="fa-solid fa-trash-can"></i>
    </div>
    <h3 class="text-base font-extrabold text-white mb-1.5" id="cartRemoveTitle"><?= __('cart_remove_item_title', 'Remove Item from Cart?') ?></h3>
    <p class="text-xs text-[#a0a0b2] mb-5 leading-relaxed" id="cartRemoveMsg"><?= __('cart_remove_item_msg', 'Are you sure you want to remove this item from the cart?') ?></p>
    
    <div class="flex items-center justify-center gap-2.5">
      <button type="button" onclick="closeCartRemoveModal()" class="btn-remove-cancel flex-1 py-2.5 px-4 rounded-xl border border-[#343446] bg-[#22222e] text-[#c0c0d2] hover:text-white font-bold text-xs transition active:scale-95">
        <?= __('cpm_cancel', 'Cancel') ?>
      </button>
      <button type="button" id="cartRemoveConfirmBtn" class="flex-1 py-2.5 px-4 rounded-xl bg-gradient-to-r from-red-600 to-rose-600 hover:from-red-500 hover:to-rose-500 text-white font-extrabold text-xs transition shadow-lg shadow-red-600/30 active:scale-95">
        <?= __('cart_remove_item_confirm', 'Yes, Remove') ?>
      </button>
    </div>
  </div>
</div>

<!-- TOAST -->
<div id="toast-container"></div>

<!-- CHAT -->
<div id="chatBox">
  <div class="chat-header">
    <div class="chat-title"><i class="fa-solid fa-robot"></i> AI Assistant</div>
    <button onclick="toggleChat()" style="background:none;border:none;color:white;cursor:pointer;font-size:18px;"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div id="chatMessages">
    <div class="msg-bot">
      <div class="avatar"><i class="fa-solid fa-robot"></i></div>
      <div class="bubble">Hello! Welcome to Bird's Nest Coffee! &#x2615; Ask me about our menu or recommendations!</div>
    </div>
  </div>
  <div class="chat-input">
    <input type="text" id="chatInput" placeholder="Ask me something..." autocomplete="off">
    <button id="chatSendBtn" onclick="sendChat()"><i class="fa-solid fa-paper-plane"></i></button>
  </div>
</div>

<!-- LOYALTY MODAL -->
<div id="loyaltyModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(8px);z-index:9999;justify-content:center;align-items:center;">
  <div style="background:var(--bg-card,#fff);border-radius:16px;padding:28px;max-width:420px;width:90%;position:relative;border:1px solid var(--border,#e0d4c4);box-shadow:0 12px 48px rgba(90,60,20,.16);">
    <span onclick="closeLoyaltyModal()" style="position:absolute;right:14px;top:10px;font-size:22px;color:var(--text-muted,#9a8070);cursor:pointer;"><i class="fa-solid fa-xmark"></i></span>
    <div style="text-align:center;margin-bottom:18px;">
      <i class="fa-solid fa-star" style="font-size:36px;color:#d1904b;"></i>
      <h2 style="font-size:18px;margin:6px 0 3px;color:var(--text,#1a1410);">Loyalty Card</h2>
      <p style="font-size:12px;color:var(--text-sec,#5a4a3a);">Enter your loyalty ID to view points and redeem rewards</p>
    </div>
    <div style="display:flex;gap:8px;margin-bottom:14px;">
      <input type="text" id="loyaltyIdInput" placeholder="e.g. CARD-12345" style="flex:1;padding:9px 12px;border-radius:9px;border:1px solid var(--border,#e5e7eb);background:var(--bg-input,#f3f4f6);color:var(--text,#1a1410);font-family:'Poppins',sans-serif;font-size:13px;outline:none;">
      <button onclick="lookupLoyalty()" style="background:#d1904b;color:#fff;border:none;padding:9px 16px;border-radius:9px;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;"><i class="fa-solid fa-magnifying-glass"></i></button>
    </div>
    <div id="loyaltyResult" style="display:none;padding:14px;background:rgba(255,255,255,.03);border-radius:10px;border:1px solid var(--border,#e0d4c4);">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <div><div style="font-size:11px;color:var(--text-sec,#5a4a3a);">Loyalty ID</div><div id="loyaltyDisplayId" style="font-size:15px;font-weight:700;color:#d1904b;"></div></div>
        <div style="text-align:right;"><div style="font-size:11px;color:var(--text-sec,#5a4a3a);">Points</div><div id="loyaltyPoints" style="font-size:20px;font-weight:700;color:var(--text,#1a1410);">0</div></div>
      </div>
      <div id="loyaltyRewards" style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:8px;"></div>
      <div id="loyaltyHistory" style="margin-top:10px;max-height:100px;overflow-y:auto;font-size:11px;color:var(--text-sec,#5a4a3a);border-top:1px solid var(--border,#e0d4c4);padding-top:6px;"></div>
    </div>
    <div id="loyaltyError" style="display:none;padding:10px;background:rgba(231,76,60,.08);border-radius:8px;border:1px solid rgba(231,76,60,.2);color:#e74c3c;text-align:center;font-size:12px;"><i class="fa-solid fa-circle-exclamation"></i> Card not found.</div>
  </div>
</div>

<script src="tender.js?v=<?= @filemtime('tender.js') ?>"></script>
<script>
window.OVERDUE_MINUTES = window.OVERDUE_MINUTES || <?= (int)OVERDUE_MINUTES ?>;
window.CP_KHR_RATE     = window.CP_KHR_RATE     || <?= defined('KHR_RATE') ? (int)KHR_RATE : 4100 ?>;
var CP_KHR_RATE        = window.CP_KHR_RATE;
var CP_SHOW_DISCOUNT   = false;

// ── Constants from PHP ──
var CSRF        = '<?= e($_SESSION['csrf_token']) ?>';
window.CSRF     = CSRF;
window.MENU_CONFIG = { csrfToken: CSRF };
var CATEGORY_OPTS = <?= json_encode($categoryOpts, JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
var MILK_DEFAULT = <?= json_encode($defaultMilk, JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
var BUY_X_COUNT = <?= (int)BUY_X_COUNT ?>;
var ADD_TO_ORDER_MODE = <?= (int)$add_to_order_mode ?>;
// Card on the order being added to, so the cart re-render can show it read-only.
// id is pre-escaped for HTML: it lands in an innerHTML string below, and JSON_HEX_TAG
// only protects the JS source, not the sink.
var PARENT_LOYALTY = <?= json_encode($parent_loyalty ? [
    'id'  => htmlspecialchars($parent_loyalty['loyalty_id'], ENT_QUOTES, 'UTF-8'),
    'pts' => (int)$parent_loyalty['points'],
] : null, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG) ?>;
var CAFE_TABLES = [];
var CP_STAND_MAX = <?= STAND_COUNT ?>;
var CP_TAX_RATE  = <?= TAX_RATE ?>;

// ── Escape HTML for JS-built elements ──
function escH(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── PRODUCT MODAL ──
var product = {}, modalQty = 1, modalUnitPrice = 0, modalAddonTotal = 0;
var editingCartIndex = null;

// Net drink price after a per-product promo (rounded per unit, mirrors the server).
function promoNet(gross, promoPct) {
  if (!promoPct) return gross;
  return Math.round(gross * (1 - promoPct / 100) * 100) / 100;
}

function setModalEditMode(isEdit) {
  var modalAddBtn = document.querySelector('.btn-add-to-cart');
  var btnText = document.getElementById('modalBtnText');
  var isKm = window.CPM_IS_KM;
  if (btnText) {
    btnText.textContent = isKm ? 'រក្សាទុក' : (isEdit ? 'Save Changes' : 'Add to Cart');
  } else if (modalAddBtn) {
    modalAddBtn.innerHTML = '<i class="fa-solid fa-check text-base"></i> <span id="modalBtnText">' + (isKm ? 'រក្សាទុក' : (isEdit ? 'Save Changes' : 'Add to Cart')) + '</span>';
  }
}

function itemCanCustomize(item) {
  if (!item) return false;
  if (item.has_customization !== undefined && item.has_customization !== null) {
    return (item.has_customization === 1 || item.has_customization === '1' || item.has_customization === true);
  }
  var pId = item.product_id;
  var card = pId ? document.querySelector('.product-card[data-product-id="' + pId + '"], .seller-card[data-product-id="' + pId + '"]') : null;
  if (card) {
    if (card.dataset.hasCustomization === '1') return true;
    if (card.dataset.hasCustomization === '0') return false;
    var cat = card.dataset.productCategory || '';
    var co = (typeof CATEGORY_OPTS !== 'undefined' && CATEGORY_OPTS[cat]) ? CATEGORY_OPTS[cat] : null;
    return card.dataset.productHasSizes === '1' || (co ? (Boolean(co.sweet) || Boolean(co.ice)) : false);
  }
  return Boolean(item.sweetness || item.ice || item.size_label);
}

function openCartItemEditModal(cartIndex) {
  var row = document.getElementById('cp-item-' + cartIndex);
  if (row && row.dataset.canCustomize === '0') {
    return;
  }

  var item = null;
  if (window.currentCartItems && window.currentCartItems[cartIndex]) {
    item = window.currentCartItems[cartIndex];
  }
  if (!item && window.currentCartItems && Array.isArray(window.currentCartItems)) {
    item = window.currentCartItems.find(function(it) { return Number(it.index) === Number(cartIndex); });
  }

  if (item && !itemCanCustomize(item)) {
    return;
  }

  if (!item) {
    if (row && row.dataset.productId) {
      var matchingCard = document.querySelector('.product-card[data-product-id="' + row.dataset.productId + '"], .seller-card[data-product-id="' + row.dataset.productId + '"]');
      if (matchingCard) {
        if (matchingCard.dataset.hasCustomization === '0') {
          return;
        }
        var pId = matchingCard.dataset.productId;
        var name = matchingCard.dataset.productName || '';
        var price = Number(matchingCard.dataset.productPrice || 0);
        var img = matchingCard.dataset.productImage || 'images/logo.png';
        var promoPct = parseInt(matchingCard.dataset.productPromo || 0, 10);
        var maxStock = matchingCard.dataset.maxServings ? parseInt(matchingCard.dataset.maxServings, 10) : 100;
        var cat = matchingCard.dataset.productCategory || '';
        openModal(pId, name, price, img, cat, '', matchingCard.dataset.productBadge || '', matchingCard.dataset.productHasSizes === '1', [], [], promoPct, maxStock);
        editingCartIndex = Number(cartIndex);
        setModalEditMode(true);
        return;
      }
    }
    return;
  }

  editingCartIndex = Number(cartIndex);

  var pId = item.product_id;
  var name = item.product_name || '';
  var price = Number(item.orig_price || item.price || 0);
  var img = item.image || 'images/logo.png';
  var promoPct = parseInt(item.promo_percent || 0, 10);
  var rawStock = item.max_stock || 100;
  var cat = item.category || '';

  var matchingCard = document.querySelector('.product-card[data-product-id="' + pId + '"], .seller-card[data-product-id="' + pId + '"]');
  if (matchingCard) {
    if (!cat) cat = matchingCard.dataset.productCategory || '';
    if (matchingCard.dataset.maxServings) rawStock = parseInt(matchingCard.dataset.maxServings, 10);
  }

  var maxStock = Math.min(100, Math.max(1, parseInt(rawStock, 10) || 100));
  product = { id: pId, name: name, price: price, cat: cat, promo: promoPct, maxStock: maxStock };
  modalQty = Math.min(maxStock, parseInt(item.qty, 10) || 1);
  modalUnitPrice = price;

  var modalImg = document.getElementById('modalImg');
  if (modalImg) modalImg.src = img;

  var mb = document.getElementById('modalBadge');
  if (mb) mb.style.display = 'none';

  var modalName = document.getElementById('modalName');
  if (modalName) modalName.textContent = name;

  var modalPrice = document.getElementById('modalPrice');
  if (modalPrice) modalPrice.textContent = '$' + promoNet(price, promoPct).toFixed(2);

  var _mInp = document.getElementById('modalQtyInput') || document.getElementById('modalQtyDisplay');
  if (_mInp) {
    if (_mInp.tagName === 'INPUT') {
      _mInp.value = modalQty;
      _mInp.max = maxStock;
      _mInp.setAttribute('data-max', maxStock);
    } else {
      _mInp.textContent = modalQty;
    }
  }

  var co = (typeof CATEGORY_OPTS !== 'undefined' && CATEGORY_OPTS[cat]) ? CATEGORY_OPTS[cat] : { sweet: 1, ice: 1, milk: 1, addons: 1 };
  var _optSw = document.getElementById('optSweetness');
  if (_optSw) _optSw.style.display = co.sweet ? 'block' : 'none';
  var _optIce = document.getElementById('optIce');
  if (_optIce) _optIce.style.display = co.ice ? 'block' : 'none';

  var curSweet = item.sweetness || '50%';
  var _swPills = document.querySelectorAll('#sweetnessPills .option-pill');
  if (_swPills) {
    _swPills.forEach(function(pill) {
      var isAct = (pill.dataset.value === curSweet);
      pill.classList.toggle('active', isAct);
      if (isAct) {
        var b = document.getElementById('sweetnessBadge');
        if (b) b.textContent = (window.CPM_IS_KM ? 'បានជ្រើស: ' : 'Selected: ') + pill.textContent.trim();
      }
    });
  }

  var curIce = item.ice || 'Normal Ice';
  var _icePills = document.querySelectorAll('#icePills .option-pill');
  if (_icePills) {
    _icePills.forEach(function(pill) {
      var isAct = (pill.dataset.value === curIce);
      pill.classList.toggle('active', isAct);
      if (isAct) {
        var b = document.getElementById('iceBadge');
        if (b) b.textContent = (window.CPM_IS_KM ? 'បានជ្រើស: ' : 'Selected: ') + pill.textContent.trim();
      }
    });
  }

  modalAddonTotal = 0;
  updateModalTotal();

  setModalEditMode(true);

  var m = document.getElementById('product-modal') || document.getElementById('modal');
  if (m) {
    m.dataset.currentProductId = pId;
    m.style.display = 'flex';
  }
  document.body.style.overflow = 'hidden';
}
window.openCartItemEditModal = openCartItemEditModal;

function openModal(id, name, price, img, cat, desc, badge, hasSizes, sizes, addons, promo, maxStock) {
  editingCartIndex = null;
  setModalEditMode(false);

  var p = Number(price) || 0;
  var promoPct = Math.max(0, Math.min(100, parseInt(promo || 0, 10)));
  var rawLimit = (maxStock !== null && maxStock !== undefined && !isNaN(maxStock)) ? parseInt(maxStock, 10) : 100;
  var limit = Math.min(100, Math.max(1, rawLimit));
  product = { id: id, name: name, price: p, cat: cat, promo: promoPct, maxStock: limit };
  modalQty = 1; modalUnitPrice = p;
  document.getElementById('modalImg').src = img;
  var mb = document.getElementById('modalBadge');
  if (mb) { mb.textContent = badge || ''; mb.style.display = badge ? 'flex' : 'none'; }
  document.getElementById('modalName').textContent = name;
  document.getElementById('modalPrice').textContent = '$' + promoNet(p, promoPct).toFixed(2);
  var _mInp = document.getElementById('modalQtyInput') || document.getElementById('modalQtyDisplay');
  if (_mInp) {
    if (_mInp.tagName === 'INPUT') {
      _mInp.value = '1';
      _mInp.max = limit;
      _mInp.setAttribute('data-max', limit);
    } else {
      _mInp.textContent = '1';
    }
  }
  // Per-category option visibility (configured in Manage Categories); default = show all.
  var co = (typeof CATEGORY_OPTS !== 'undefined' && CATEGORY_OPTS[cat]) ? CATEGORY_OPTS[cat] : { sweet: 1, ice: 1, milk: 1, addons: 1 };
  var _optSw = document.getElementById('optSweetness');
  if (_optSw) _optSw.style.display = co.sweet ? 'block' : 'none';
  var _optIce = document.getElementById('optIce');
  if (_optIce) _optIce.style.display = co.ice ? 'block' : 'none';
  var _swPills = document.querySelectorAll('#sweetnessPills .option-pill');
  if (_swPills) {
    _swPills.forEach(function(pill) {
      var isAct = (pill.dataset.value === '50%');
      pill.classList.toggle('active', isAct);
      if (isAct) {
        var b = document.getElementById('sweetnessBadge');
        if (b) b.textContent = (window.CPM_IS_KM ? 'បានជ្រើស: ' : 'Selected: ') + pill.textContent.trim();
      }
    });
  }
  var _icePills = document.querySelectorAll('#icePills .option-pill');
  if (_icePills) {
    _icePills.forEach(function(pill) {
      var isAct = (pill.dataset.value === 'Normal Ice');
      pill.classList.toggle('active', isAct);
      if (isAct) {
        var b = document.getElementById('iceBadge');
        if (b) b.textContent = (window.CPM_IS_KM ? 'បានជ្រើស: ' : 'Selected: ') + pill.textContent.trim();
      }
    });
  }

  // Size, Milk, and Add-ons are removed from product customization modal
  modalAddonTotal = 0;

  updateModalTotal();
  var m = document.getElementById('product-modal') || document.getElementById('modal');
  if (m) m.style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

// ── FLY-TO-CART ANIMATION ENGINE ──
function flyToCart(sourceElement) {
  if (!sourceElement) return;

  var sourceImg = null;
  if (sourceElement.tagName === 'IMG') {
    sourceImg = sourceElement;
  } else if (sourceElement.querySelector) {
    sourceImg = sourceElement.querySelector('img');
  }

  if (!sourceImg || !sourceImg.src) return;

  var rect = sourceImg.getBoundingClientRect();
  if (!rect || rect.width === 0 || rect.height === 0) return;

  // Target: Cart Sidebar Header Count (#cpCount) if open, or Header Cart Button if closed
  var sidebar = document.getElementById('cart-sidebar') || document.getElementById('cartPanel');
  var isSidebarOpen = sidebar && !sidebar.classList.contains('hidden') && sidebar.style.display !== 'none' && window.getComputedStyle(sidebar).display !== 'none';

  var targetEl = null;
  if (isSidebarOpen) {
    targetEl = document.getElementById('cpCount') || 
               sidebar.querySelector('.cp-count') || 
               sidebar.querySelector('.cp-title') || 
               sidebar.querySelector('.cp-header');
  } else {
    targetEl = document.getElementById('cart-badge') || 
               document.getElementById('cart-toggle-btn') || 
               document.getElementById('cartToggleBtn') || 
               document.querySelector('.header-right .cart-pill-btn') ||
               document.querySelector('.header-right .btn-nav') ||
               document.querySelector('.cart-toggle-btn');
  }

  var targetX = window.innerWidth - 65;
  var targetY = 28;

  if (targetEl) {
    var tRect = targetEl.getBoundingClientRect();
    if (tRect.width > 0 && tRect.left > 0) {
      targetX = tRect.left + (tRect.width / 2) - 15;
      targetY = tRect.top + (tRect.height / 2) - 15;
    }
  }

  var dx = targetX - rect.left;
  var dy = targetY - rect.top;

  var clone = document.createElement('img');
  clone.src = sourceImg.src;
  clone.className = 'fly-to-cart-clone';
  clone.style.position = 'fixed';
  clone.style.top = rect.top + 'px';
  clone.style.left = rect.left + 'px';
  clone.style.width = rect.width + 'px';
  clone.style.height = rect.height + 'px';
  clone.style.objectFit = 'contain';
  clone.style.borderRadius = '16px';
  clone.style.pointerEvents = 'none';
  clone.style.zIndex = '2147483646';
  clone.style.boxShadow = '0 12px 28px rgba(0, 0, 0, 0.4)';
  clone.style.transformOrigin = 'center center';
  document.body.appendChild(clone);

  function triggerCartBump() {
    var cpCount = document.getElementById('cpCount');
    if (cpCount) {
      cpCount.classList.remove('cart-badge-bump');
      void cpCount.offsetWidth;
      cpCount.classList.add('cart-badge-bump');
      setTimeout(function() { cpCount.classList.remove('cart-badge-bump'); }, 400);
    }

    var badge = document.getElementById('cart-badge');
    if (badge) {
      badge.classList.remove('cart-badge-bump');
      void badge.offsetWidth;
      badge.classList.add('cart-badge-bump');
      setTimeout(function() { badge.classList.remove('cart-badge-bump'); }, 400);
    }
  }

  if (typeof clone.animate === 'function') {
    var anim = clone.animate([
      {
        transform: 'translate(0px, 0px) scale(1) rotate(0deg)',
        opacity: 1,
        borderRadius: '16px'
      },
      {
        transform: 'translate(' + (dx * 0.5) + 'px, ' + (dy * 0.3 - 35) + 'px) scale(0.65) rotate(-10deg)',
        opacity: 0.9,
        offset: 0.45
      },
      {
        transform: 'translate(' + dx + 'px, ' + dy + 'px) scale(0.18) rotate(15deg)',
        opacity: 0.1,
        borderRadius: '50%'
      }
    ], {
      duration: 600,
      easing: 'cubic-bezier(0.2, 0.8, 0.25, 1)',
      fill: 'forwards'
    });

    anim.onfinish = function() {
      clone.remove();
      triggerCartBump();
    };
  } else {
    clone.style.transition = 'transform 0.6s cubic-bezier(0.2, 0.8, 0.25, 1), opacity 0.6s cubic-bezier(0.2, 0.8, 0.25, 1), border-radius 0.6s ease';
    requestAnimationFrame(function() {
      clone.style.transform = 'translate(' + dx + 'px, ' + dy + 'px) scale(0.2) rotate(15deg)';
      clone.style.opacity = '0.1';
      clone.style.borderRadius = '50%';
    });
    setTimeout(function() {
      clone.remove();
      triggerCartBump();
    }, 600);
  }
}
window.flyToCart = flyToCart;

// Open the product directly to cart from card click
function openModalFromCard(card) {
  if (!card) return;
  if (card.dataset.stockStatus === 'out_of_stock' || card.classList.contains('disabled') || card.classList.contains('out-of-stock-card')) {
    var outReason = card.getAttribute('title') || (window.CPM_IS_KM ? 'អស់ស្តុក' : 'Out of stock');
    showToast((card.dataset.productName || 'This item') + ' is currently out of stock (' + outReason + ').', 'warning');
    return;
  }

  // Card micro-press feedback animation
  card.classList.add('card-quick-pressed');
  setTimeout(function() { card.classList.remove('card-quick-pressed'); }, 200);

  // Smooth Fly-to-Cart animation
  flyToCart(card);

  // Auto add directly to cart!
  quickAdd(card.dataset.productId, Number(card.dataset.productPrice || 0), card, true);
}

function closeModal() {
  editingCartIndex = null;
  setModalEditMode(false);
  var m = document.getElementById('product-modal') || document.getElementById('modal');
  if (m) {
    m.style.display = 'none';
    delete m.dataset.currentProductId;
  }
  document.body.style.overflow = '';
}
function changeQty(delta) {
  var inp = document.getElementById('modalQtyInput') || document.getElementById('modalQtyDisplay');
  var current = parseInt(inp ? (inp.value || inp.textContent) : modalQty) || 1;
  var maxLimit = Math.min(100, Math.max(1, parseInt(product.maxStock || 100, 10)));
  if (delta > 0 && current >= maxLimit) {
    var warningMsg = (window.CPM_IS_KM ? 'ចំនួនអតិបរិមាអនុញ្ញាតគឺ ' : 'Maximum quantity allowed is ') + maxLimit + (window.CPM_IS_KM ? ' កែវ' : ' units');
    triggerQtyWarning(inp, warningMsg);
    return;
  }
  modalQty = Math.max(1, Math.min(maxLimit, current + delta));
  if (inp) {
    if (inp.tagName === 'INPUT') inp.value = modalQty;
    else inp.textContent = modalQty;
  }
  updateModalTotal();
}
var _qtyToastDebounce = null;
function triggerQtyWarning(input, msg) {
  if (input) {
    input.classList.remove('qty-limit-warning');
    void input.offsetWidth; // trigger reflow for animation restart
    input.classList.add('qty-limit-warning');
    setTimeout(function() { input.classList.remove('qty-limit-warning'); }, 500);
  }
  clearTimeout(_qtyToastDebounce);
  _qtyToastDebounce = setTimeout(function() {
    showToast(msg || 'Maximum quantity limit reached', 'warning');
  }, 100);
}

function onModalQtyInput(input) {
  var raw = (input.value || '').trim();
  if (raw === '') return;
  var maxLimit = Math.min(100, Math.max(1, parseInt(product.maxStock || 100, 10)));
  var val = parseInt(raw, 10);
  if (val > maxLimit) {
    var warningMsg = (window.CPM_IS_KM ? 'ចំនួនអតិបរិមាអនុញ្ញាតគឺ ' : 'Maximum quantity allowed is ') + maxLimit + (window.CPM_IS_KM ? ' កែវ' : ' units');
    triggerQtyWarning(input, warningMsg);
    modalQty = maxLimit;
    input.value = maxLimit;
  } else if (val >= 1) {
    input.classList.remove('qty-limit-warning');
    modalQty = val;
  }
  updateModalTotal();
}
function onModalQtyChange(input) {
  var val = parseInt(input.value, 10);
  var maxLimit = Math.min(100, Math.max(1, parseInt(product.maxStock || 100, 10)));
  if (isNaN(val) || val < 1) {
    val = 1;
    input.value = 1;
    input.classList.remove('qty-limit-warning');
  } else if (val > maxLimit) {
    val = maxLimit;
    input.value = maxLimit;
    var warningMsg = (window.CPM_IS_KM ? 'ចំនួនអតិបរិមាអនុញ្ញាតគឺ ' : 'Maximum quantity allowed is ') + maxLimit + (window.CPM_IS_KM ? ' កែវ' : ' units');
    triggerQtyWarning(input, warningMsg);
  } else {
    input.classList.remove('qty-limit-warning');
  }
  modalQty = val;
  updateModalTotal();
}
function onCartQtyInput(index, input) {
  var raw = (input.value || '').trim();
  if (raw === '') return;
  var rawMax = parseInt(input.dataset.max || input.getAttribute('max') || '100', 10);
  var maxQty = Math.min(100, Math.max(1, rawMax));
  var val = parseInt(raw, 10);
  if (val > maxQty) {
    var warningMsg = (window.CPM_IS_KM ? 'ចំនួនអតិបរិមាអនុញ្ញាតគឺ ' : 'Maximum quantity allowed is ') + maxQty + (window.CPM_IS_KM ? ' កែវ' : ' units');
    triggerQtyWarning(input, warningMsg);
    input.value = maxQty;
  } else if (val >= 1) {
    input.classList.remove('qty-limit-warning');
  }
}
function updateModalTotal() {
  var net = promoNet(modalUnitPrice, (product.promo || 0));
  document.getElementById('modalTotalDisplay').textContent = '$' + ((net + modalAddonTotal) * modalQty).toFixed(2);
}
function selectPill(pill) {
  var grpWrap = pill.closest('#sweetnessPills, #icePills, .pill-group, .grid');
  if (grpWrap) {
    grpWrap.querySelectorAll('.option-pill').forEach(function(p) { p.classList.remove('active'); });
  }
  pill.classList.add('active');

  var grp = pill.dataset.group;
  var text = pill.textContent.trim();
  if (grp === 'sweetness') {
    var b = document.getElementById('sweetnessBadge');
    if (b) b.textContent = (window.CPM_IS_KM ? 'បានជ្រើស: ' : 'Selected: ') + text;
  } else if (grp === 'ice') {
    var b = document.getElementById('iceBadge');
    if (b) b.textContent = (window.CPM_IS_KM ? 'បានជ្រើស: ' : 'Selected: ') + text;
  }
}
function getPillValue(groupId) { var a = document.querySelector('#' + groupId + ' .option-pill.active'); return a ? a.dataset.value : ''; }

// ── REAL-TIME STOCK STATUS CARD UPDATER ──
function updateProductCardStockState(card, info) {
  if (!card || !info) return;
  var pId = card.dataset.productId;
  var status = info.status; // 'in_stock' | 'low_stock' | 'out_of_stock'
  var reason = info.reason || '';
  var maxServings = info.max_servings;

  card.dataset.stockStatus = status;
  card.dataset.maxServings = (maxServings !== null && maxServings !== undefined) ? maxServings : '';

  var cardImg = card.querySelector('.card-img');
  var existingOverlay = card.querySelector('.out-of-stock-overlay');
  var existingLowBadge = card.querySelector('.low-stock-badge');
  var quickBtn = card.querySelector('.quick-add-btn');

  if (status === 'out_of_stock') {
    card.classList.add('disabled', 'out-of-stock-card', 'cursor-not-allowed', 'opacity-60', 'grayscale-[35%]');
    card.classList.remove('has-low-stock', 'ring-1', 'ring-amber-500/30');

    if (existingLowBadge) existingLowBadge.remove();

    card.setAttribute('title', reason || 'Out of stock');
    if (!existingOverlay && cardImg) {
      var overlay = document.createElement('div');
      overlay.className = 'out-of-stock-overlay absolute inset-0 bg-black/75 backdrop-blur-[2px] flex flex-col items-center justify-center text-center p-2.5 rounded-2xl z-20 transition-opacity duration-300';
      overlay.innerHTML = '<span class="px-2.5 py-1 rounded-full bg-rose-600 text-white text-[10.5px] font-extrabold uppercase tracking-wider shadow-md flex items-center gap-1.5"><i class="fa-solid fa-circle-xmark text-xs"></i> ' + (window.CPM_IS_KM ? 'អស់ស្តុក' : 'Out of Stock') + '</span><span class="text-[10px] text-rose-200 mt-2 font-medium px-1.5 py-0.5 rounded bg-black/40 border border-rose-500/30 line-clamp-2 max-w-full leading-tight"><i class="fa-solid fa-triangle-exclamation text-[9px] text-rose-400 mr-1"></i>' + escH(reason || 'Out of ingredients') + '</span>';
      cardImg.appendChild(overlay);
    } else if (existingOverlay) {
      var reasonSpan = existingOverlay.querySelector('span:nth-child(2)');
      if (reasonSpan) reasonSpan.innerHTML = '<i class="fa-solid fa-triangle-exclamation text-[9px] text-rose-400 mr-1"></i>' + escH(reason || 'Out of ingredients');
    }

    if (quickBtn) quickBtn.style.display = 'none';

    // If modal is currently open with this product, disable Add button
    var activeModal = document.getElementById('product-modal') || document.getElementById('modal');
    var modalAddBtn = document.querySelector('.btn-add-to-cart');
    if (activeModal && activeModal.style.display !== 'none' && activeModal.dataset.currentProductId == pId) {
      if (modalAddBtn) {
        modalAddBtn.disabled = true;
        modalAddBtn.innerHTML = '<i class="fa-solid fa-ban"></i> Out of Stock';
      }
    }
  } else {
    // In Stock or Low Stock (clean card UI without warning badges)
    card.classList.remove('disabled', 'out-of-stock-card', 'cursor-not-allowed', 'opacity-60', 'grayscale-[35%]', 'has-low-stock', 'ring-1', 'ring-amber-500/30');
    if (existingOverlay) existingOverlay.remove();
    if (existingLowBadge) existingLowBadge.remove();
    if (quickBtn) quickBtn.style.display = '';

    var activeModal = document.getElementById('product-modal') || document.getElementById('modal');
    var modalAddBtn = document.querySelector('.btn-add-to-cart');
    if (activeModal && activeModal.style.display !== 'none' && activeModal.dataset.currentProductId == pId) {
      if (modalAddBtn && modalAddBtn.disabled) {
        modalAddBtn.disabled = false;
        modalAddBtn.innerHTML = '<i class="fa-solid fa-cart-plus"></i> Add to Cart';
      }
    }
  }
}

function pollProductStockStatuses() {
  fetch('api_stock_status.php?_t=' + Date.now(), { cache: 'no-store' })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data || !data.success) return;
      if (data.statuses) {
        var cards = document.querySelectorAll('.product-card[data-product-id]');
        cards.forEach(function(card) {
          var pId = card.dataset.productId;
          if (data.statuses[pId]) {
            updateProductCardStockState(card, data.statuses[pId]);
          }
        });
      }
      if (data.sidebar_alerts && typeof updateSidebarStockBadges === 'function') {
        updateSidebarStockBadges(data.sidebar_alerts);
      }
    })
    .catch(function() {});
}
window.pollProductStockStatuses = pollProductStockStatuses;

// Background polling every 8 seconds
setInterval(pollProductStockStatuses, 8000);

// ── ADD / UPDATE CART (from modal) ──
function addToCart() {
  var btn = document.querySelector('.btn-add-to-cart');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + (editingCartIndex !== null ? 'Updating...' : 'Adding...');
  }

  var _optSw = document.getElementById('optSweetness');
  var swVal = (_optSw && _optSw.style.display !== 'none') ? getPillValue('sweetnessPills') : '';
  var _optIce = document.getElementById('optIce');
  var iceVal = (_optIce && _optIce.style.display !== 'none') ? getPillValue('icePills') : '';

  if (editingCartIndex !== null) {
    // ── EDIT EXISTING CART ITEM ──
    var editParams = new URLSearchParams({
      ajax_edit_item: '1',
      index: editingCartIndex,
      qty: modalQty,
      sweetness: swVal,
      ice: iceVal,
      csrf_token: CSRF
    });

    fetch('cart.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: editParams.toString()
    })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data || !data.success) {
          showToast(data.message || 'Error updating cart item', 'error');
          return;
        }
        var isKm = window.CPM_IS_KM;
        showToast(isKm ? 'បានកែប្រែទំនិញក្នុងកន្ត្រកជោគជ័យ!' : 'Cart item updated!', 'success');
        closeModal();
        if (data.cart) {
          renderCartPanel(data.cart);
        } else {
          loadCartPanel();
        }
        pollProductStockStatuses();
      })
      .catch(function(err) {
        console.error('Edit cart item error:', err);
        showToast('Error updating cart item', 'error');
      })
      .finally(function() {
        if (btn) {
          btn.disabled = false;
          setModalEditMode(false);
        }
      });
    return;
  }

  var modalImg = document.getElementById('modalImg');
  if (modalImg && modalImg.src && modalImg.offsetParent !== null) {
    flyToCart(modalImg);
  } else {
    var curCard = document.querySelector('.product-card[data-product-id="' + product.id + '"]');
    if (curCard) flyToCart(curCard);
  }

  var params = new URLSearchParams({ id: product.id, qty: modalQty, csrf_token: CSRF });
  if (swVal) params.append('sweetness', swVal);
  if (iceVal) params.append('ice', iceVal);

  fetch('add_to_cart.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'}, body: params.toString() })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data.success) { 
        showToast(data.message || 'Error', 'error'); 
        pollProductStockStatuses();
        return; 
      }
      showToast('Added to cart!', 'success');
      closeModal();
      if (data.cart) {
        renderCartPanel(data.cart);
      }
      loadCartPanel();
      pollProductStockStatuses();
    })
    .catch(function() { showToast('Error adding to cart', 'error'); })
    .finally(function() { 
      if (btn) {
        btn.disabled = false; 
        setModalEditMode(false);
      }
    });
}

// ── QUICK ADD ──
function quickAdd(productId, price, sourceEl, skipFly) {
  var card = (sourceEl && sourceEl.nodeType) ? sourceEl : document.querySelector('.product-card[data-product-id="' + productId + '"]');
  if (card && (card.dataset.stockStatus === 'out_of_stock' || card.classList.contains('disabled'))) {
    var outReason = card.getAttribute('title') || (window.CPM_IS_KM ? 'អស់ស្តុក' : 'Out of stock');
    showToast((card.dataset.productName || 'This item') + ' is currently out of stock (' + outReason + ').', 'warning');
    return;
  }
  var prodName = card ? (card.dataset.productName || '') : '';

  if (!skipFly && card) {
    flyToCart(card);
  }

  fetch('add_to_cart.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'}, body: new URLSearchParams({ id: productId, qty: 1, csrf_token: CSRF }).toString() })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data.success) { 
        showToast(data.message || 'Error', 'error'); 
        pollProductStockStatuses();
        return; 
      }
      var isKm = window.CPM_IS_KM;
      var msg = isKm 
        ? (prodName ? ('បានបន្ថែម «' + prodName + '» ទៅក្នុងកន្ត្រក') : 'បានបន្ថែមទៅក្នុងកន្ត្រក!')
        : (prodName ? ('Added "' + prodName + '" to cart!') : 'Added to cart!');
      showToast(msg, 'success');
      if (data.cart) {
        renderCartPanel(data.cart);
      }
      loadCartPanel();
      pollProductStockStatuses();
    })
    .catch(function() { showToast('Error adding to cart', 'error'); });
}

// ── LOAD & RENDER CART PANEL ──
function loadCartPanel() {
  fetch('cart_refresh.php?_t=' + Date.now(), { cache: 'no-store' })
    .then(function(r) { return r.json(); })
    .then(function(data) { 
      if (data) renderCartPanel(data); 
      pollProductStockStatuses();
    })
    .catch(function(err) { console.error('cart_refresh error:', err); });
}
window.loadCartPanel = loadCartPanel;

function renderCartPanel(data) {
  window.currentCartItems = (data && data.items) ? data.items : [];

  // Update header count & badge
  var countEl = document.getElementById('cpCount');
  if (countEl) {
    var itemUnit = (window.CPM_I18N && window.CPM_I18N.item_single)
      ? (data.count == 1 ? window.CPM_I18N.item_single : window.CPM_I18N.item_plural)
      : (window.CPM_IS_KM ? 'មុខទំនិញ' : (data.count == 1 ? 'item' : 'items'));
    countEl.textContent = data.count + ' ' + itemUnit;
  }
  var badgeEl = document.getElementById('cart-badge') || document.getElementById('headerCartCount');
  if (badgeEl) badgeEl.textContent = data.count;

  var footer   = document.getElementById('cpFooter');
  var clearBtn = document.getElementById('cpClearBtn');
  var body     = document.getElementById('cpBody');

  if (!data.items || data.items.length === 0) {
    if (body) body.innerHTML = '<div class="cp-empty"><i class="fa-solid fa-mug-hot"></i><p>Cart is empty</p><small>Tap a drink to add it</small></div>';
    if (footer) footer.style.display = 'none';
    if (clearBtn) clearBtn.style.display = 'none';
    return;
  }

  if (clearBtn) clearBtn.style.display = '';
  if (footer)  footer.style.display = '';

  // Build items HTML for #cpBody ONLY
  var itemsHtml = '<div id="cpItems">';
  data.items.forEach(function(item) {
    var meta = [
      item.size_label ? 'Size: '  + item.size_label : '',
      item.sweetness  ? 'Sweet: ' + item.sweetness  : '',
      item.ice        ? 'Ice: '   + item.ice        : '',
      item.milk       ? 'Milk: '  + item.milk       : '',
    ].filter(Boolean).join(' • ');

    var unitPrice = Number(item.price || 0);
    var origUnitPrice = Number(item.orig_price || item.price || 0);
    var itemDisc = Number(item.item_discount || 0);

    var itemDiscBtnHtml = '';
    if (itemDisc > 0) {
      var discLabel = '';
      if (item.discount_type === 'percent' && item.discount_amount > 0) {
        discLabel = (parseFloat(item.discount_amount) || 0) + '%';
      } else if (item.discount_type === 'flat' && item.discount_amount > 0) {
        discLabel = '$' + Number(item.discount_amount).toFixed(2);
      } else {
        discLabel = '-$' + itemDisc.toFixed(2);
      }
      itemDiscBtnHtml = '<button type="button" class="cp-item-disc-btn active" onclick="cpOpenItemDiscount(' + item.index + ')" title="Edit Discount" style="color:#2ecc71;border-color:rgba(46,204,113,0.4);background:rgba(46,204,113,0.1);"><i class="fa-solid fa-check"></i> Discount ' + discLabel + ' <span onclick="event.stopPropagation();cpClearItemDiscount(' + item.index + ')" title="Remove item discount" style="margin-left:5px;color:#e74c3c;font-weight:bold;cursor:pointer;padding:0 3px;">&times;</span></button>';
    }

    var priceHtml = '';
    if (itemDisc > 0) {
      var finalItemPrice = Math.max(0, unitPrice - (itemDisc / Number(item.qty || 1)));
      priceHtml = '<s style="color:#888;font-size:11px;margin-right:5px;">$' + unitPrice.toFixed(2) + '</s>' +
        '<span style="color:#2ecc71;font-weight:700;">$' + finalItemPrice.toFixed(2) + '</span>' +
        '<span style="color:#e74c3c;font-size:9.5px;font-weight:700;margin-left:6px;background:rgba(231,76,60,0.12);padding:1px 5px;border-radius:4px;"><i class="fa-solid fa-tag"></i> -$' + itemDisc.toFixed(2) + '</span>';
    } else {
      priceHtml = ((item.promo_percent > 0 && origUnitPrice > unitPrice)
        ? '<s style="color:#aaa;font-size:11px;margin-right:5px;">$' + origUnitPrice.toFixed(2) + '</s>' : '') +
        '$<span id="cp-line-' + item.index + '">' + unitPrice.toFixed(2) + '</span>' +
        (item.promo_percent > 0 ? '<span style="color:#e74c3c;font-size:9px;font-weight:700;margin-left:4px;">' + item.promo_percent + '% OFF</span>' : '');
    }

    var canCustomize = itemCanCustomize(item);
    var clickAttr = canCustomize
      ? ' class="js-cart-item-open" onclick="openCartItemEditModal(' + item.index + ')" style="cursor:pointer;" title="' + (window.CPM_IS_KM ? 'ចុចដើម្បីកែប្រែ' : 'Click to customize') + '"'
      : ' style="cursor:default;"';

    itemsHtml += '<div class="cp-item" id="cp-item-' + item.index + '" data-product-id="' + (item.product_id || '') + '" data-cart-index="' + item.index + '" data-can-customize="' + (canCustomize ? '1' : '0') + '">' +
      '<img src="' + escH(item.image) + '" alt="' + escH(item.product_name) + '"' + clickAttr + ' onerror="this.onerror=null; this.src=\'images/logo.png\';">' +
      '<div class="cp-item-info"' + clickAttr + '>' +
        '<div class="cp-item-name">' + escH(item.product_name) + '</div>' +
        (meta ? '<div class="cp-item-meta">' + escH(meta) + '</div>' : '') +
        (item.addons && item.addons.length
          ? '<div class="cp-item-meta">' + item.addons.map(function(a){ return escH(a.name); }).join(', ') + '</div>'
          : '') +
        '<div class="cp-item-price">' + priceHtml + '</div>' +
      '</div>' +
      '<div class="cp-item-actions" style="display:flex; flex-direction:column; align-items:flex-end; gap:4px;">' +
        '<div style="display:flex; align-items:center; gap:6px;">' +
          '<div class="cp-qty">' +
            '<button onclick="cpChangeQty(' + item.index + ',-1)">−</button>' +
            '<input type="number" id="cp-qty-' + item.index + '" value="' + item.qty + '" min="1" max="' + (item.max_stock || 100) + '" data-max="' + (item.max_stock || 100) + '" oninput="onCartQtyInput(' + item.index + ', this)" onchange="cpSetQty(' + item.index + ', this.value)" onfocus="this.select()" onkeydown="if(event.key===\'Enter\'){event.preventDefault();cpSetQty(' + item.index + ', this.value);this.blur();}">' +
            '<button onclick="cpChangeQty(' + item.index + ',1)">+</button>' +
          '</div>' +
          '<button class="cp-remove" onclick="cpRemoveItem(' + item.index + ')" title="Remove"><i class="fa-solid fa-trash-can"></i></button>' +
        '</div>' +
        itemDiscBtnHtml +
      '</div>' +
    '</div>';
  });
  itemsHtml += '</div>'; // /cpItems

  if (body) body.innerHTML = itemsHtml;

  // Update summary values safely
  var subtotalEl = document.getElementById('cpSubtotal');
  if (subtotalEl) subtotalEl.textContent = '$' + data.subtotal;

  var taxRow = document.getElementById('cpTaxRow');
  var taxEl = document.getElementById('cpTax');
  var taxVal = parseFloat(data.tax) || 0;
  if (taxEl) taxEl.textContent = '$' + data.tax;
  if (taxRow) taxRow.style.display = taxVal > 0 ? 'flex' : 'none';

  var totalEl = document.getElementById('cpTotal');
  if (totalEl) totalEl.textContent = '$' + data.total;

  // Update Manual Discount row
  var manualRow = document.getElementById('cpManualRow');
  var manualLabel = document.getElementById('cpManualLabel');
  var manualAmt = document.getElementById('cpManualAmt');
  var discForm = document.getElementById('cpDiscountForm');
  var addDiscBtn = document.getElementById('cpAddDiscBtn');

  if (parseFloat(data.manual) > 0) {
    if (manualRow) manualRow.style.display = 'flex';
    if (manualLabel) manualLabel.innerHTML = '🏷️ ' + escH(data.manual_label);
    if (manualAmt) manualAmt.textContent = '-$' + data.manual;
    if (discForm) discForm.style.display = 'none';
    if (addDiscBtn) addDiscBtn.style.display = 'none';
  } else {
    if (manualRow) manualRow.style.display = 'none';
    if (addDiscBtn) addDiscBtn.style.display = 'none';
  }
}

// ── CART ITEM OPERATIONS ──
var _cartPendingRemoveIndex = null;
var _cartPendingIsClear = false;

function cpChangeQty(index, delta) {
  var inp = document.getElementById('cp-qty-' + index);
  if (!inp) return;
  var rawMax = parseInt(inp.dataset.max || inp.getAttribute('max') || '100', 10);
  var maxQty = Math.min(100, Math.max(1, rawMax));
  var currentVal = parseInt(inp.value, 10) || 1;
  var targetVal = currentVal + delta;

  if (delta < 0 && targetVal < 1) {
    // When decreasing below 1, confirm removal with the user
    cpRemoveItem(index);
    return;
  }

  if (delta > 0 && targetVal > maxQty) {
    var warningMsg = (window.CPM_IS_KM ? 'ចំនួនអតិបរិមាអនុញ្ញាតគឺ ' : 'Maximum quantity allowed is ') + maxQty + (window.CPM_IS_KM ? ' កែវ' : ' units');
    triggerQtyWarning(inp, warningMsg);
    targetVal = maxQty;
  }
  var qty = Math.max(1, Math.min(maxQty, targetVal));
  inp.value = qty;
  fetch('cart.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'ajax_update=1&index='+index+'&qty='+qty })
    .then(function(r) { return r.json(); })
    .then(function() { loadCartPanel(); });
}

function cpSetQty(index, val) {
  var raw = parseInt(val, 10);
  var inp = document.getElementById('cp-qty-' + index);
  var rawMax = inp ? parseInt(inp.dataset.max || inp.getAttribute('max') || '100', 10) : 100;
  var maxQty = Math.min(100, Math.max(1, rawMax));
  if (isNaN(raw) || raw < 1) {
    raw = 1;
    if (inp) { inp.value = 1; inp.classList.remove('qty-limit-warning'); }
  } else if (raw > maxQty) {
    var warningMsg = (window.CPM_IS_KM ? 'ចំនួនអតិបរិមាអនុញ្ញាតគឺ ' : 'Maximum quantity allowed is ') + maxQty + (window.CPM_IS_KM ? ' កែវ' : ' units');
    triggerQtyWarning(inp, warningMsg);
    raw = maxQty;
    if (inp) inp.value = maxQty;
  } else {
    if (inp) { inp.value = raw; inp.classList.remove('qty-limit-warning'); }
  }
  fetch('cart.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'ajax_update=1&index='+index+'&qty='+raw })
    .then(function(r) { return r.json(); })
    .then(function() { loadCartPanel(); });
}

function cpRemoveItem(index) {
  _cartPendingRemoveIndex = index;
  _cartPendingIsClear = false;

  var row = document.getElementById('cp-item-' + index);
  var itemName = row ? (row.querySelector('.cp-item-name') ? row.querySelector('.cp-item-name').textContent.trim() : '') : '';

  var modal = document.getElementById('cartRemoveModal');
  var titleEl = document.getElementById('cartRemoveTitle');
  var msgEl = document.getElementById('cartRemoveMsg');
  var confirmBtn = document.getElementById('cartRemoveConfirmBtn');
  var isKm = window.CPM_IS_KM;

  if (titleEl) titleEl.textContent = isKm ? 'លុបទំនិញពីកន្ត្រក?' : 'Remove Item from Cart?';
  if (msgEl) {
    if (itemName) {
      msgEl.textContent = isKm 
        ? ('តើអ្នកពិតជាចង់លុប «' + itemName + '» ចេញពីកន្ត្រកមែនទេ?')
        : ('Are you sure you want to remove "' + itemName + '" from the cart?');
    } else {
      msgEl.textContent = isKm
        ? 'តើអ្នកពិតជាចង់លុបទំនិញនេះចេញពីកន្ត្រកមែនទេ?'
        : 'Are you sure you want to remove this item from the cart?';
    }
  }
  if (confirmBtn) {
    confirmBtn.textContent = isKm ? 'យល់ព្រមលុប' : 'Yes, Remove';
    confirmBtn.onclick = executeCartRemove;
  }

  if (modal) {
    modal.style.display = 'flex';
  } else if (confirm(isKm ? ('តើអ្នកពិតជាចង់លុប ' + (itemName || 'ទំនិញនេះ') + ' ចេញពីកន្ត្រកមែនទេ?') : ('Remove ' + (itemName || 'this item') + ' from cart?'))) {
    executeCartRemove();
  }
}

function cpClearCart() {
  _cartPendingRemoveIndex = null;
  _cartPendingIsClear = true;

  var modal = document.getElementById('cartRemoveModal');
  var titleEl = document.getElementById('cartRemoveTitle');
  var msgEl = document.getElementById('cartRemoveMsg');
  var confirmBtn = document.getElementById('cartRemoveConfirmBtn');
  var isKm = window.CPM_IS_KM;

  if (titleEl) titleEl.textContent = isKm ? 'សម្អាតកន្ត្រកទាំងមូល?' : 'Clear All Items?';
  if (msgEl) {
    msgEl.textContent = isKm
      ? 'តើអ្នកពិតជាចង់លុបទំនិញទាំងអស់ចេញពីកន្ត្រកមែនទេ?'
      : 'Are you sure you want to remove all items from the cart?';
  }
  if (confirmBtn) {
    confirmBtn.textContent = isKm ? 'យល់ព្រមសម្អាត' : 'Yes, Clear All';
    confirmBtn.onclick = executeCartRemove;
  }

  if (modal) {
    modal.style.display = 'flex';
  } else if (confirm(isKm ? 'តើអ្នកពិតជាចង់សម្អាតកន្ត្រកទាំងមូលមែនទេ?' : 'Remove all items from the cart?')) {
    executeCartRemove();
  }
}

function closeCartRemoveModal() {
  var modal = document.getElementById('cartRemoveModal');
  if (modal) modal.style.display = 'none';
  _cartPendingRemoveIndex = null;
  _cartPendingIsClear = false;
}

function executeCartRemove() {
  var modal = document.getElementById('cartRemoveModal');
  if (modal) modal.style.display = 'none';

  if (_cartPendingIsClear) {
    fetch('cart.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'ajax_clear=1' })
      .then(function() {
        loadCartPanel();
        showToast(window.CPM_IS_KM ? 'បានសម្អាតកន្ត្រករួចរាល់' : 'All items removed from cart', 'success');
      });
    return;
  }

  var index = _cartPendingRemoveIndex;
  if (index === null || typeof index === 'undefined') return;

  var row = document.getElementById('cp-item-' + index);
  var itemName = row ? (row.querySelector('.cp-item-name') ? row.querySelector('.cp-item-name').textContent.trim() : '') : '';
  if (row) { row.style.opacity='0'; row.style.transform='translateX(20px)'; row.style.transition='all .25s'; }

  setTimeout(function() {
    fetch('cart.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'ajax_remove=1&index='+index })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        loadCartPanel();
        var msg = window.CPM_IS_KM 
          ? (itemName ? ('បានលុប «' + itemName + '» ចេញពីកន្ត្រក') : 'បានលុបទំនិញចេញពីកន្ត្រក')
          : (itemName ? ('"' + itemName + '" removed from cart') : 'Item removed from cart');
        showToast(msg, 'success');
      });
  }, 250);
}

// ── REFRESH SUMMARY FROM AJAX RESPONSE (qty update) ──
function cpRefreshSummaryFromData(data) {
  var set = function(id, val) { var el = document.getElementById(id); if (el) el.textContent = val; };
  set('cpSubtotal', '$' + data.cartSubtotal);
  set('cpTax',      '$' + data.tax);

  var newTotal = '$' + data.cartTotal;
  set('cpTotal', newTotal); // footer total
  var st = document.getElementById('cpSubtotal'); // also inline

  var hhr = document.getElementById('cpHHRow');
  if (hhr) hhr.style.display = parseFloat(data.happy_hour_discount) > 0 ? '' : 'none';
  set('cpHHAmt', '-$' + (data.happy_hour_discount || '0.00'));

  var mdr = document.getElementById('cpManualRow');
  if (mdr) mdr.style.display = parseFloat(data.manual_discount) > 0 ? '' : 'none';
  set('cpManualAmt', '-$' + (data.manual_discount || '0.00'));

  // Re-seed rather than just recalculating: if an item is added while the modal is
  // open the total moves, and a stale prefill would quietly understate the tender.
  cpPrefillCashReceived();
  cpUpdateSplitAmounts();
}

// ── DISCOUNT PANEL ──
var _cpDiscountType = 'percent';
var _cpTargetItemIndex = null;

function cpOpenItemDiscount(itemIndex) {
  _cpTargetItemIndex = itemIndex;
  var btn = document.getElementById('cpAddDiscBtn');
  if (btn) btn.style.display = 'none';
  var form = document.getElementById('cpDiscountForm');
  if (form) { form.style.display = 'block'; }
  var amtInput = document.getElementById('cpDiscAmount');
  if (amtInput) { amtInput.value = ''; amtInput.focus(); }
}

function cpOpenDiscount() {
  _cpTargetItemIndex = null;
  var btn = document.getElementById('cpAddDiscBtn');
  if (btn) btn.style.display = 'none';
  var form = document.getElementById('cpDiscountForm');
  if (form) { form.style.display = 'block'; }
  var amtInput = document.getElementById('cpDiscAmount');
  if (amtInput) { amtInput.value = ''; amtInput.focus(); }
}

function cpCloseDiscount() {
  _cpTargetItemIndex = null;
  var form = document.getElementById('cpDiscountForm');
  if (form) form.style.display = 'none';
  var btn = document.getElementById('cpAddDiscBtn');
  if (btn) btn.style.display = '';
}

function cpSetDType(type) {
  _cpDiscountType = type;
  var p = document.getElementById('cpDtypePercent'), f = document.getElementById('cpDtypeFlat');
  if (p) p.classList.toggle('active', type === 'percent');
  if (f) f.classList.toggle('active', type === 'flat');
  var inp = document.getElementById('cpDiscAmount');
  if (inp) inp.placeholder = type === 'percent' ? '0  (e.g. 10 = 10%)' : '0.00  (e.g. 0.50)';
}

function cpApplyDiscount() {
  var amount = parseFloat(document.getElementById('cpDiscAmount').value) || 0;
  var reason = document.getElementById('cpDiscReason').value.trim();
  if (amount <= 0) { alert('Please enter a discount amount.'); return; }

  var bodyData = '';
  if (_cpTargetItemIndex !== null && _cpTargetItemIndex >= 0) {
    bodyData = 'ajax_apply_item_discount=1&index=' + _cpTargetItemIndex + '&type=' + encodeURIComponent(_cpDiscountType) + '&amount=' + amount;
  } else {
    bodyData = 'ajax_apply_discount=1&type=' + encodeURIComponent(_cpDiscountType) + '&amount=' + amount + '&reason=' + encodeURIComponent(reason);
  }

  fetch('cart.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: bodyData })
    .then(function() {
      _cpTargetItemIndex = null;
      var form = document.getElementById('cpDiscountForm');
      if (form) form.style.display = 'none';
      loadCartPanel();
    });
}

function cpClearItemDiscount(itemIndex) {
  fetch('cart.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'ajax_clear_item_discount=1&index='+itemIndex })
    .then(function() { loadCartPanel(); });
}

function cpClearDiscount() {
  fetch('cart.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'ajax_clear_discount=1' })
    .then(function() { loadCartPanel(); });
}

// ── PAYMENT METHODS ──
function cpTogglePayment(label) {
  var cb = label.querySelector('input[type="checkbox"]');
  var value = cb.value;
  if (value === 'paylater' || value === 'riel') {
    document.querySelectorAll('.cp-pay-method input[type="checkbox"]').forEach(function(c) { c.checked = false; });
    cb.checked = true;
  } else {
    var pl = document.querySelector('.cp-pay-method input[value="paylater"]');
    if (pl && pl.checked) pl.checked = false;
    var rielCb = document.querySelector('.cp-pay-method input[value="riel"]');
    if (rielCb && rielCb.checked) rielCb.checked = false;
    cb.checked = !cb.checked;
  }
  if (value === 'riel' && !cb.checked) {
    var ri = document.getElementById('cpRielReceived');
    if (ri) ri.value = '';
  }
  document.querySelectorAll('.cp-pay-method').forEach(function(el) {
    el.classList.toggle('selected', el.querySelector('input[type="checkbox"]').checked);
  });
  var selected = cpGetSelected();
  cpUpdateConfirmBtn(selected);
  cpUpdateSplitInputs();
}

function cpGetSelected() {
  var sel = [];
  document.querySelectorAll('.cp-pay-method input[type="checkbox"]:checked').forEach(function(cb) { sel.push(cb.value); });
  return sel;
}

function cpGetCartTotal() {
  var el = document.getElementById('cpTotal');
  if (!el) return 0;
  return parseFloat(el.textContent.replace('$','').replace(/,/g,'')) || 0;
}

function cpGetCartCount() {
  var el = document.getElementById('cpCount');
  if (el) {
    var count = parseInt(el.textContent) || 0;
    if (count > 0) return count;
  }
  var items = document.querySelectorAll('#cpItems .cp-item');
  return items.length;
}

function cpHasCartItems() {
  return cpGetCartCount() > 0;
}

/* Seed "Amount Received" with what the customer actually owes in cash.
   Left blank it was skipped on 93% of cash sales, so most receipts carried no tender
   line and the cashier did the change in their head — which is how a drawer ends up
   short. Exact cash is now one tap; a customer handing over a note just overtypes it.
   Only ever prefills an untouched field, so a typed amount is never clobbered. */
// In a split, the cash leg is its own amount, not the order total.
function cpOwedInCash() {
  var split = document.querySelector('.cp-split-amount[data-method="cash"]');
  if (split) return parseFloat(split.value) || 0;
  return cpGetCartTotal();
}

function cpPrefillCashReceived() {
  var cr = document.getElementById('cpCashReceived');
  var ri = document.getElementById('cpRielCash');
  if (!cr) return;
  cr.value = '';
  if (ri) ri.value = '';
  delete cr.dataset.touched;
  cpCalcChange();
}

/* One tap for the note the customer actually handed over.
   The prefilled exact amount on its own was a trap: it LOOKS handled, so a rushed
   cashier can leave "Change $0.00" showing while holding a $20 note. Putting the real
   notes on screen makes picking the right one faster than ignoring it, and the active
   highlight shows which tender was actually chosen. */
function cpRenderTenderQuick() {
  var wrap = document.getElementById('cpTenderQuick');
  if (!wrap) return;
  wrap.innerHTML = '';

  var owed = cpOwedInCash();
  if (owed <= 0) return;

  var mk = function (label, val) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'cp-tender-btn';
    b.textContent = label;
    b.dataset.tender = val.toFixed(2);
    b.addEventListener('click', function () { cpSetTender(val); });
    return b;
  };

  wrap.appendChild(mk('Exact', owed));
  // Only notes that actually cover the bill — a $5 button on a $23 order just
  // produces "Need $18 more".
  [1, 5, 10, 20, 50, 100].filter(function (d) { return d > owed; })
                         .slice(0, 4)
                         .forEach(function (d) { wrap.appendChild(mk('$' + d, d)); });

  cpMarkActiveTender(parseFloat(document.getElementById('cpCashReceived').value) || 0);
}

function cpSetTender(val) {
  var cr = document.getElementById('cpCashReceived');
  var ri = document.getElementById('cpRielCash');
  if (!cr) return;
  cr.value = Number(val).toFixed(2);
  cr.dataset.touched = '1';
  if (ri) { ri.value = ''; }
  var eq = document.getElementById('cpRielCashUsd');
  if (eq) { eq.textContent = '≈ $0.00'; }
  cpCalcChange();
  cpMarkActiveTender(val);
}

function cpMarkActiveTender(val) {
  var v = Number(val).toFixed(2);
  document.querySelectorAll('#cpTenderQuick .cp-tender-btn').forEach(function (b) {
    b.classList.toggle('active', b.dataset.tender === v);
  });
}

function cpUpdateConfirmBtn(selected) {
  // Updates the MODAL's Confirm Payment button — never the footer Confirm Order
  // button, which must always stay "Confirm Order" (it just opens the modal).
  var btn  = document.getElementById('cpConfirmPayBtn');
  var icon = document.getElementById('cpConfirmPayIcon');
  var text = document.getElementById('cpConfirmPayText');
  var cc   = document.getElementById('cpChangeCalc');
  var rc   = document.getElementById('cpRielCalc');
  if (!btn) return;

  btn.className = 'cp-pm-confirm';
  if (cc) cc.classList.remove('visible');
  if (rc) rc.classList.remove('visible');

  if (selected.includes('paylater')) {
    if (icon) icon.className = 'fa-solid fa-clock';
    if (text) text.textContent = 'Place Pay Later Order';
    btn.classList.add('paylater');
  } else if (selected.length > 1) {
    if (icon) icon.className = 'fa-solid fa-layer-group';
    if (text) text.textContent = 'Confirm Split Payment';
    btn.classList.add('split');
    if (selected.includes('cash') && cc) {
      cc.classList.add('visible');
      cpPrefillCashReceived();
      cpRenderRielQuick();
      setTimeout(function() { var cr = document.getElementById('cpCashReceived'); if (cr) { cr.focus(); cr.select(); } }, 50);
    }
  } else if (selected.includes('riel')) {
    if (icon) icon.className = 'fa-solid fa-coins';
    if (text) text.textContent = 'Confirm Riel Payment';
    btn.classList.add('riel');
    if (rc) {
      rc.classList.add('visible');
      var total = cpGetCartTotal();
      var ri = document.getElementById('cpRielReceived');
      if (ri && !ri.value) ri.value = Math.round(total * CP_KHR_RATE / 100) * 100;
      cpCalcRielChange();
      setTimeout(function() { if (ri) ri.focus(); }, 50);
    }
  } else if (selected.includes('cash')) {
    if (icon) icon.className = 'fa-solid fa-money-bill-wave';
    if (text) text.textContent = 'Confirm Cash Payment';
    btn.classList.add('cash');
    if (cc) cc.classList.add('visible');
    cpPrefillCashReceived();
    cpRenderRielQuick();
    setTimeout(function() { var cr = document.getElementById('cpCashReceived'); if (cr) { cr.focus(); cr.select(); } }, 50);
  } else if (selected.includes('bakong')) {
    if (icon) icon.className = 'fa-solid fa-qrcode';
    if (text) text.textContent = 'Generate Bakong QR';
    btn.classList.add('bakong');
  } else {
    if (icon) icon.className = 'fa-solid fa-check';
    if (text) text.textContent = 'Confirm Payment';
  }
}

// ── PAYMENT MODAL OPEN/CLOSE ──
// ── STAND NUMBER GATE ──
// A Drink In order with no stand number can't be delivered — staff have no way to
// find the customer. So an empty stand blocks checkout instead of just warning.
// The only way past is the takeaway path, which REQUIRES a customer name: the
// barista card shows a name only when it isn't 'Guest' (view_order.php), so a
// nameless drink_out order is the same mystery drink under a different label.
// No route may produce a Drink In with no stand.
function cpStandGateShow(freeCount, onTakeaway) {
  var allBusy = freeCount === 0;
  var old = document.getElementById('cpStandGate');
  if (old) old.remove();
  var wrap = document.createElement('div');
  wrap.id = 'cpStandGate';
  wrap.style.cssText = 'position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.72);display:flex;align-items:center;justify-content:center;padding:20px;';
  wrap.innerHTML =
    '<div style="background:#161616;border:1px solid rgba(255,193,7,.35);border-radius:16px;padding:26px 24px;max-width:380px;width:100%;text-align:center;box-shadow:0 12px 40px rgba(0,0,0,.5);">' +
      '<div style="font-size:38px;color:#f0ad4e;margin-bottom:12px;"><i class="fa-solid fa-hashtag"></i></div>' +
      '<div style="font-size:17px;font-weight:700;color:#fff;margin-bottom:8px;">Stand Number Required</div>' +
      '<div style="font-size:13px;color:#9a9a9a;line-height:1.6;margin-bottom:18px;">' +
        (allBusy
          ? 'Every stand is currently in use. Ask staff to release returned placards on the Stands page, or hand this order over the counter.'
          : 'This is a <b style="color:#ccc;">Drink In</b> order. Without a stand number, staff won\'t know where to deliver it.') +
      '</div>' +
      '<div id="cpStandGateChoices" style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">' +
        '<button type="button" id="cpStandGateSet" style="padding:11px 20px;border-radius:50px;border:none;background:#d1904b;color:#fff;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;">' +
          '<i class="fa-solid fa-table-cells-large"></i> Set Stand</button>' +
        '<button type="button" id="cpStandGateTa" style="padding:11px 20px;border-radius:50px;border:1px solid rgba(255,255,255,.2);background:transparent;color:#bbb;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;">' +
          '<i class="fa-solid fa-bag-shopping"></i> No stand — Takeaway</button>' +
      '</div>' +
      // Revealed by the takeaway button — the customer must be callable by name.
      '<div id="cpStandGateName" style="display:none;margin-top:16px;text-align:left;">' +
        '<div style="font-size:12px;color:#9a9a9a;margin-bottom:6px;">Customer name so the barista can call it out:</div>' +
        '<input type="text" id="cpStandGateNameInput" maxlength="120" placeholder="e.g. Sokha" ' +
          'style="width:100%;padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.15);background:#101010;color:#eee;font-size:13px;font-family:inherit;">' +
        '<div id="cpStandGateNameErr" style="display:none;margin-top:6px;font-size:11.5px;color:#ff6b6b;"></div>' +
        '<button type="button" id="cpStandGateTaGo" style="margin-top:10px;width:100%;padding:11px 20px;border-radius:50px;border:none;background:#d1904b;color:#fff;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;">' +
          '<i class="fa-solid fa-check"></i> Confirm Takeaway</button>' +
      '</div>' +
    '</div>';
  document.body.appendChild(wrap);
  function close() { wrap.remove(); }
  wrap.addEventListener('click', function(e) { if (e.target === wrap) close(); });

  document.getElementById('cpStandGateSet').onclick = function() {
    close();
    var inp = document.getElementById('cpTableNumber');
    if (inp) inp.focus();
    var grid = document.getElementById('cpStandGrid');
    if (grid && grid.style.display === 'none') cpToggleStandGrid();
  };

  document.getElementById('cpStandGateTa').onclick = function() {
    document.getElementById('cpStandGateChoices').style.display = 'none';
    document.getElementById('cpStandGateName').style.display = 'block';
    var ni = document.getElementById('cpStandGateNameInput');
    var existing = document.getElementById('cpCustomerName');
    if (existing && existing.value.trim()) ni.value = existing.value.trim();
    ni.focus();
  };

  function submitTakeaway() {
    var ni  = document.getElementById('cpStandGateNameInput');
    var err = document.getElementById('cpStandGateNameErr');
    var name = ni.value.trim();
    if (name === '' || name.toLowerCase() === 'guest') {
      err.textContent = name === '' ? 'Enter a name — the barista has no stand to deliver to.'
                                    : '"Guest" won\'t help the barista find them. Use a real name.';
      err.style.display = 'block';
      ni.focus();
      return;
    }
    // Push the name into the real form field, flip the order to takeaway, drop
    // any stale stand value, then continue to payment.
    var cn = document.getElementById('cpCustomerName');
    if (cn) cn.value = name;
    var tn = document.getElementById('cpTableNumber');
    if (tn) tn.value = '';
    cpSetDrinkType('drink_out');
    close();
    onTakeaway();
  }
  document.getElementById('cpStandGateTaGo').onclick = submitTakeaway;
  document.getElementById('cpStandGateNameInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); submitTakeaway(); }
  });
}

function cpRequireStand(onOk) {
  if (typeof onOk === 'function') onOk();
}

// ══════════════════════════════════════════════════════════════════════════════
// ── CASH PAYMENT SETTLEMENT MODAL CONTROLLER (POS LAYOUT) ──
// ══════════════════════════════════════════════════════════════════════════════
window.CPM_IS_KM = <?= $isKm ? 'true' : 'false' ?>;
window.CPM_I18N = {
  payment_details: <?= json_encode(__('cpm_payment_details', 'Payment Details')) ?>,
  modal_title_cash: <?= json_encode(__('cpm_modal_title', 'Cash Payment Settlement')) ?>,
  modal_sub_cash: <?= json_encode(__('cpm_modal_subtitle', 'Take order cash & calculate change')) ?>,
  modal_title_bakong: <?= json_encode($isKm ? 'ការទូទាត់តាមបាកុង KHQR' : 'Bakong KHQR Payment') ?>,
  modal_sub_bakong: <?= json_encode($isKm ? 'ស្កេនទូទាត់ជាមួយកម្មវិធីធនាគារទាំងអស់' : 'Scan KHQR code with any mobile banking app') ?>,
  box_title_cash: <?= json_encode(__('cpm_payment_details', 'Payment Details')) ?>,
  box_title_bakong: <?= json_encode($isKm ? 'ព័ត៌មានបាកុង KHQR' : 'Bakong KHQR Details') ?>,
  banner_label_cash: <?= json_encode($isKm ? 'សរុបប្រាក់ទទួលបាន:' : 'Total Received:') ?>,
  banner_label_bakong: <?= json_encode($isKm ? 'ទឹកប្រាក់ត្រូវទូទាត់ KHQR:' : 'Exact KHQR Total:') ?>,
  received_usd: <?= json_encode($isKm ? 'ប្រាក់ដុល្លារទទួល ($)' : 'Received Dollar ($)') ?>,
  received_khr: <?= json_encode($isKm ? 'ប្រាក់រៀលទទួល (៛)' : 'Received Riel (៛)') ?>,
  total_received: <?= json_encode($isKm ? 'សរុបប្រាក់ទទួលបាន:' : 'Total Received:') ?>,
  change_to_return: <?= json_encode(__('cpm_change_to_return', 'Change to Return')) ?>,
  shortage_need_more: <?= json_encode($isKm ? 'ខ្វះ / ត្រូវការប្រាក់បន្ថែម:' : 'Shortage / Need More:') ?>,
  short_khr_prefix: <?= json_encode($isKm ? 'ខ្វះ: ' : 'Short: ') ?>,
  exact_amount_0: <?= json_encode($isKm ? 'លុយគ្រប់ (៛ 0)' : 'Exact Amount (៛ 0)') ?>,
  exact_usd_notes: <?= json_encode($isKm ? 'ក្រដាសប្រាក់ដុល្លារសុទ្ធ (៛ 0)' : 'Exact USD notes (៛ 0)') ?>,
  method_cash: <?= json_encode(__('cpm_method_cash', 'Cash')) ?>,
  method_bakong: <?= json_encode(__('cpm_method_bakong', 'Bakong QR')) ?>,
  apply_payment: <?= json_encode(__('cpm_apply_payment', 'Apply Payment & Print Receipt')) ?>,
  apply_bakong: <?= json_encode(__('cpm_apply_bakong', 'Apply Payment & Show QR')) ?>,
  waiting_bakong: <?= json_encode(__('cpm_waiting_bakong', 'Waiting for Bakong payment...')) ?>,
  change_calc: <?= json_encode(__('cpm_change_calc', 'Change Calculated')) ?>,
  bakong_digital_title: <?= json_encode(__('cpm_digital_payment_title', 'Digital Transfer (No Change Required)')) ?>,
  bakong_exact_total: <?= json_encode(__('cpm_exact_khqr_total', 'Exact KHQR Total')) ?>,
  bakong_header: <?= json_encode(__('cpm_method_bakong', 'Bakong KHQR')) ?>,
  no_items_cart: <?= json_encode($isKm ? 'គ្មានទំនិញក្នុងកន្ត្រកទេ' : 'Your cart is empty!') ?>,
  item_single: <?= json_encode($isKm ? 'មុខទំនិញ' : 'item') ?>,
  item_plural: <?= json_encode($isKm ? 'មុខទំនិញ' : 'items') ?>,
  cart_remove_item_title: <?= json_encode(__('cart_remove_item_title', 'Remove Item from Cart?')) ?>,
  cart_remove_item_confirm: <?= json_encode(__('cart_remove_item_confirm', 'Yes, Remove')) ?>,
  cart_clear_title: <?= json_encode(__('cart_clear_title', 'Clear All Items?')) ?>,
  cart_clear_msg: <?= json_encode(__('cart_clear_msg', 'Are you sure you want to remove all items from the cart?')) ?>,
  cart_clear_confirm: <?= json_encode(__('cart_clear_confirm', 'Yes, Clear All')) ?>,
  cart_item_removed: <?= json_encode(__('cart_item_removed', 'Item removed from cart')) ?>
};

var cpmState = {
  activeField: 'usd', // 'usd' or 'khr'
  usdStr: '0.00',
  khrStr: '0',
  changeMode: 'mixed',
  owedUsd: 0,
  owedKhr: 0,
  receivedUsd: 0,
  receivedKhr: 0,
  method: 'cash',
  activeBakongOrderId: null,
  pollTimer: null,
  countdownTimer: null,
  qrString: ''
};

function openCashPaymentModal() {
  if (!cpHasCartItems()) {
    alert(window.CPM_I18N.no_items_cart);
    return;
  }

  var modal = document.getElementById('cashPaymentModal');
  if (!modal) return;

  // Stop any active polling from previous modal open
  cpmStopBakongPolling();
  cpmState.activeBakongOrderId = null;
  cpmState.qrString = '';

  var totalUsd = cpGetCartTotal();
  var rate = window.CP_KHR_RATE || 4100;
  var totalKhr = Math.round(totalUsd * rate / 100) * 100;

  cpmState.owedUsd = totalUsd;
  cpmState.owedKhr = totalKhr;
  
  // Default values: full amount in USD, 0 in KHR
  cpmState.usdStr = totalUsd.toFixed(2);
  cpmState.khrStr = '0';
  cpmState.activeField = 'usd';

  var rateDisplayEl = document.getElementById('cpmRateDisplay');
  if (rateDisplayEl) rateDisplayEl.textContent = '៛' + rate.toLocaleString();

  // Reset UI Views
  var cashView = document.getElementById('cpmCashView');
  var qrView = document.getElementById('cpmBakongQrView');
  var successView = document.getElementById('cpmBakongSuccessView');
  var changeInfoWrap = document.getElementById('cpmChangeInfoWrap');
  var normalActions = document.getElementById('cpmNormalActions');
  var bakongActions = document.getElementById('cpmBakongActions');
  var boxTitle = document.getElementById('cpmBoxTitle');

  if (cashView) cashView.style.display = 'grid';
  if (qrView) qrView.style.display = 'none';
  if (successView) successView.style.display = 'none';
  if (changeInfoWrap) changeInfoWrap.style.display = 'flex';
  if (normalActions) normalActions.style.display = 'flex';
  if (bakongActions) bakongActions.style.display = 'none';
  if (boxTitle) boxTitle.innerHTML = '<i class="fa-solid fa-calculator"></i> ' + (window.CPM_I18N ? window.CPM_I18N.payment_details : 'Payment Details');

  // Initialize Change Return Mode
  cpmState.changeMode = localStorage.getItem('cpm_change_mode') || 'mixed';
  cpmSetChangeMode(cpmState.changeMode);

  // Populate Items list from cart DOM
  var itemsContainer = document.getElementById('cpmItemsList');
  var cartItemEls = document.querySelectorAll('#cpItems .cp-item');
  var itemsHtml = '';
  var totalItemsCount = 0;

  cartItemEls.forEach(function(itemEl) {
    var name = (itemEl.querySelector('.cp-item-name') || {}).textContent || 'Item';
    var meta = (itemEl.querySelector('.cp-item-meta') || {}).textContent || '';
    var priceEl = itemEl.querySelector('.cp-item-price span');
    var price = priceEl ? (priceEl.textContent || '0.00') : '0.00';
    var qtyInput = itemEl.querySelector('.cp-qty input');
    var qty = qtyInput ? (parseInt(qtyInput.value) || 1) : 1;
    totalItemsCount += qty;

    var numPrice = parseFloat(price) || 0;
    var lineTotal = (numPrice * qty).toFixed(2);

    itemsHtml += '<div class="grid grid-cols-12 gap-2 py-2.5 items-center text-xs border-b border-slate-100 cpm-item-row">' +
      '<div class="col-span-6 min-w-0 pr-1">' +
        '<div class="font-bold text-slate-800 leading-snug break-words cpm-item-name">' + name + '</div>' +
        (meta ? '<div class="text-[11px] text-slate-400 leading-snug break-words mt-0.5 cpm-item-meta">' + meta + '</div>' : '') +
      '</div>' +
      '<div class="col-span-2 text-right font-medium text-slate-500 cpm-item-price">$' + numPrice.toFixed(2) + '</div>' +
      '<div class="col-span-2 text-center">' +
        '<span class="inline-block px-2 py-0.5 rounded-md bg-slate-100 text-slate-800 font-extrabold text-xs cpm-item-qty">' + qty + '</span>' +
      '</div>' +
      '<div class="col-span-2 text-right font-bold text-slate-900 cpm-item-total">$' + lineTotal + '</div>' +
    '</div>';
  });

  if (itemsContainer) itemsContainer.innerHTML = itemsHtml || '<div class="text-center py-4 text-xs text-slate-400 italic">' + (window.CPM_IS_KM ? 'គ្មានទំនិញក្នុងកន្ត្រកទេ' : 'No items in cart') + '</div>';
  var countEl = document.getElementById('cpmItemCount');
  if (countEl) countEl.textContent = totalItemsCount + ' ' + (totalItemsCount === 1 ? window.CPM_I18N.item_single : window.CPM_I18N.item_plural);

  // Subtotal, Discount, Tax
  var subtotal = (document.getElementById('cpSubtotal') || {}).textContent || ('$' + totalUsd.toFixed(2));
  var subEl = document.getElementById('cpmSubtotal');
  if (subEl) subEl.textContent = subtotal;

  var discEl = document.getElementById('cpManualAmt') || document.getElementById('cpItemPromoAmt');
  var discRow = document.getElementById('cpmDiscountRow');
  if (discEl && discEl.offsetParent !== null && discRow) {
    discRow.style.display = 'flex';
    document.getElementById('cpmDiscount').textContent = discEl.textContent;
  } else if (discRow) {
    discRow.style.display = 'none';
  }

  var taxEl = document.getElementById('cpTax');
  var taxRow = document.getElementById('cpmTaxRow');
  if (taxEl && taxEl.offsetParent !== null && taxRow) {
    taxRow.style.display = 'flex';
    document.getElementById('cpmTax').textContent = taxEl.textContent;
  } else if (taxRow) {
    taxRow.style.display = 'none';
  }

  var totUsdEl = document.getElementById('cpmTotalUsd');
  if (totUsdEl) totUsdEl.textContent = '$' + totalUsd.toFixed(2);
  var totKhrEl = document.getElementById('cpmTotalKhr');
  if (totKhrEl) totKhrEl.textContent = '៛ ' + totalKhr.toLocaleString();

  // Set Initial Method to Cash
  cpmSetMethod('cash');

  cpmUpdateDualDisplay(true);

  // Show Modal
  modal.style.display = 'flex';

  setTimeout(function() {
    var inField = document.getElementById('cpmReceivedUsdInput');
    if (inField) { inField.focus(); inField.select(); }
  }, 60);
}

function closeCashPaymentModal() {
  var modal = document.getElementById('cashPaymentModal');
  if (!modal) return;

  if (cpmState.activeBakongOrderId) {
    var confirmMsg = window.CPM_IS_KM 
      ? ('អ្នកមានការកុម្ម៉ង់បាគង #' + cpmState.activeBakongOrderId + ' កំពុងដំណើរការ។ តើអ្នកចង់បោះបង់ការកុម្ម៉ង់នេះ ហើយត្រឡប់ទៅកាន់កន្ត្រកវិញទេ?')
      : ('You have an active Bakong QR order #' + cpmState.activeBakongOrderId + '. Do you want to cancel this order and return to cart?');
    if (confirm(confirmMsg)) {
      cpmCancelActiveBakongOrder(true);
      modal.style.display = 'none';
    }
    return;
  }

  cpmStopBakongPolling();
  modal.style.display = 'none';
}

function cpmSetChangeMode(mode) {
  if (mode !== 'khr') mode = 'mixed';
  cpmState.changeMode = mode;
  try { localStorage.setItem('cpm_change_mode', mode); } catch(e) {}

  var btnMixed = document.getElementById('cpmModeMixed');
  var btnKhr = document.getElementById('cpmModeKhr');

  if (btnMixed) {
    if (mode === 'mixed') btnMixed.classList.add('cpm-mode-active');
    else btnMixed.classList.remove('cpm-mode-active');
  }
  if (btnKhr) {
    if (mode === 'khr') btnKhr.classList.add('cpm-mode-active');
    else btnKhr.classList.remove('cpm-mode-active');
  }

  cpmUpdateDualDisplay(false);
}

function cpmClearUsdInput() {
  if (cpmState.method === 'bakong') return;
  cpmState.usdStr = '0';
  cpmUpdateDualDisplay(true);
  var inUsd = document.getElementById('cpmReceivedUsdInput');
  if (inUsd) inUsd.focus();
}

function cpmClearKhrInput() {
  if (cpmState.method === 'bakong') return;
  cpmState.khrStr = '0';
  cpmUpdateDualDisplay(true);
  var inKhr = document.getElementById('cpmReceivedKhrInput');
  if (inKhr) inKhr.focus();
}

function cpmClearActiveInput() {
  cpmClearUsdInput();
  cpmClearKhrInput();
}

function cpmOnManualUsdInput(val) {
  if (cpmState.method === 'bakong') return;
  cpmState.usdStr = val.replace(/[^0-9.]/g, '');
  cpmUpdateDualDisplay(false);
}

function cpmOnManualKhrInput(val) {
  if (cpmState.method === 'bakong') return;
  cpmState.khrStr = val.replace(/[^0-9]/g, '');
  cpmUpdateDualDisplay(false);
}

function cpmUpdateDualDisplay(updateInputs) {
  if (typeof updateInputs === 'undefined') updateInputs = true;
  var inUsd = document.getElementById('cpmReceivedUsdInput');
  var inKhr = document.getElementById('cpmReceivedKhrInput');

  if (updateInputs) {
    if (inUsd) inUsd.value = cpmState.usdStr;
    if (inKhr) inKhr.value = cpmState.khrStr;
  }

  var numUsd = parseFloat(cpmState.usdStr) || 0;
  var numKhr = parseFloat(cpmState.khrStr) || 0;
  var rate = window.CP_KHR_RATE || 4100;
  var i18n = window.CPM_I18N || {};

  // Combined total received
  var totalRecUsd = numUsd + (numKhr / rate);
  var totalRecKhr = (numUsd * rate) + numKhr;

  cpmState.receivedUsd = totalRecUsd;
  cpmState.receivedKhr = totalRecKhr;

  var totUsdEl = document.getElementById('cpmTotalReceivedUsd');
  var totKhrEl = document.getElementById('cpmTotalReceivedKhr');
  if (totUsdEl) totUsdEl.textContent = '$' + totalRecUsd.toFixed(2);
  if (totKhrEl) totKhrEl.textContent = '(៛ ' + Math.round(totalRecKhr).toLocaleString() + ')';

  // Calculate Change
  var changeUsd = totalRecUsd - cpmState.owedUsd;
  var changeKhr = Math.round(changeUsd * rate / 100) * 100;
  var changeBox = document.getElementById('cpmChangeBox');
  var changeUsdEl = document.getElementById('cpmChangeUsd');
  var changeKhrEl = document.getElementById('cpmChangeKhr');
  var changeTitleEl = document.getElementById('cpmChangeTitle');

  if (cpmState.method === 'bakong') {
    if (changeBox) changeBox.classList.remove('cpm-short');
    if (changeTitleEl) changeTitleEl.textContent = i18n.bakong_digital_title || 'Digital Transfer (No Change Required)';
    if (changeUsdEl) changeUsdEl.textContent = '$0.00';
    if (changeKhrEl) changeKhrEl.textContent = (i18n.bakong_exact_total || 'Exact KHQR Total') + ': $' + cpmState.owedUsd.toFixed(2) + ' (៛ ' + cpmState.owedKhr.toLocaleString() + ')';
  } else if (changeUsd < -0.005) {
    // Shortage
    var shortAmt = Math.abs(changeUsd);
    var shortKhrAmt = Math.abs(changeKhr);
    if (changeTitleEl) changeTitleEl.textContent = i18n.shortage_need_more || 'Shortage / Need More:';
    if (changeUsdEl) changeUsdEl.textContent = '-$' + shortAmt.toFixed(2);
    if (changeKhrEl) changeKhrEl.textContent = (i18n.short_khr_prefix || 'Short: ') + '៛ ' + shortKhrAmt.toLocaleString();
    if (changeBox) changeBox.classList.add('cpm-short');
  } else {
    if (changeBox) changeBox.classList.remove('cpm-short');
    if (changeTitleEl) changeTitleEl.textContent = i18n.change_to_return || 'Change to Return';

    var cleanChangeUsd = Math.max(0, changeUsd);
    var cleanChangeKhr = Math.max(0, changeKhr);
    var mode = cpmState.changeMode || 'mixed';

    if (typeof tenderChangeFormatted === 'function') {
      var fmt = tenderChangeFormatted(cleanChangeUsd, rate, mode);
      if (changeUsdEl) changeUsdEl.textContent = fmt.main;
      if (changeKhrEl) changeKhrEl.textContent = fmt.sub;
    } else if (cleanChangeUsd === 0) {
      if (changeUsdEl) changeUsdEl.textContent = '$0.00';
      if (changeKhrEl) changeKhrEl.textContent = i18n.exact_amount_0 || 'Exact Amount (៛ 0)';
    } else if (mode === 'khr') {
      if (changeUsdEl) changeUsdEl.textContent = '៛ ' + cleanChangeKhr.toLocaleString();
      if (changeKhrEl) changeKhrEl.textContent = '≈ $' + cleanChangeUsd.toFixed(2) + (window.CPM_IS_KM ? ' ដុល្លារ' : ' USD');
    } else {
      // Mixed Dollar + Riel
      var wholeDollars = 0;
      var rielPart = 0;
      if (cleanChangeUsd < 10.0) {
        rielPart = Math.round((cleanChangeUsd * rate) / 100) * 100;
        if (rielPart >= (10 * rate)) { wholeDollars = 10; rielPart = 0; }
      } else {
        wholeDollars = Math.floor(cleanChangeUsd / 10) * 10;
        var centRemainder = Math.round((cleanChangeUsd - wholeDollars) * 10000) / 10000;
        rielPart = Math.round((centRemainder * rate) / 100) * 100;
        if (rielPart >= (10 * rate)) { wholeDollars += 10; rielPart = 0; }
      }
      if (wholeDollars > 0 && rielPart > 0) {
        if (changeUsdEl) changeUsdEl.textContent = '$' + wholeDollars + ' + ៛ ' + rielPart.toLocaleString();
        if (changeKhrEl) changeKhrEl.textContent = window.CPM_IS_KM
          ? ('អាប់: $' + wholeDollars + ' USD (ក្រដាស 10$) + ៛ ' + rielPart.toLocaleString() + ' រៀល')
          : ('Give: $' + wholeDollars + ' USD ($10 notes) + ៛ ' + rielPart.toLocaleString() + ' KHR');
      } else if (wholeDollars > 0) {
        if (changeUsdEl) changeUsdEl.textContent = '$' + wholeDollars.toFixed(2);
        if (changeKhrEl) changeKhrEl.textContent = i18n.exact_usd_notes || 'Exact USD notes (៛ 0)';
      } else {
        if (changeUsdEl) changeUsdEl.textContent = '៛ ' + rielPart.toLocaleString();
        if (changeKhrEl) changeKhrEl.textContent = window.CPM_IS_KM
          ? ('ក្រោម 10$: អាប់ជាប្រាក់រៀលសុទ្ធ (≈ $' + cleanChangeUsd.toFixed(2) + ')')
          : ('Under $10: Return in Riel notes only (≈ $' + cleanChangeUsd.toFixed(2) + ')');
      }
    }
  }

  // Animation pop
  if (changeUsdEl) {
    changeUsdEl.classList.remove('cpm-val-pop');
    void changeUsdEl.offsetWidth;
    changeUsdEl.classList.add('cpm-val-pop');
  }
}

function cpmSetMethod(method) {
  cpmState.method = method;
  var modal = document.getElementById('cashPaymentModal');
  var cashBtn = document.getElementById('cpmMethodCash');
  var bkgBtn = document.getElementById('cpmMethodBakong');
  var applyBtn = document.getElementById('cpmApplyBtn');
  var changeInfoWrap = document.getElementById('cpmChangeInfoWrap');
  var returnMethodEl = document.getElementById('cpmReturnMethod');
  var returnOptionsRow = document.getElementById('cpmReturnOptionsRow');
  var bakongNoticeRow = document.getElementById('cpmBakongDigitalNoticeRow');
  var bottomHeaderTitle = document.getElementById('cpmBottomHeaderTitle');
  var bottomHeaderIcon = document.getElementById('cpmBottomHeaderIcon');
  var inUsd = document.getElementById('cpmReceivedUsdInput');
  var inKhr = document.getElementById('cpmReceivedKhrInput');

  var modalHeaderIcon = document.getElementById('cpmModalHeaderIcon');
  var modalHeaderTitle = document.getElementById('cpmModalHeaderTitle');
  var modalHeaderSub = document.getElementById('cpmModalHeaderSub');
  var boxTitle = document.getElementById('cpmBoxTitle');
  var totalRecIcon = document.getElementById('cpmTotalRecIcon');
  var totalRecText = document.getElementById('cpmTotalRecText');
  var i18n = window.CPM_I18N || {};

  if (method === 'bakong') {
    if (modal) modal.classList.add('cpm-is-bakong');
    if (cashBtn) cashBtn.classList.remove('cpm-method-active');
    if (bkgBtn) bkgBtn.classList.add('cpm-method-active');
    if (applyBtn) {
      applyBtn.classList.add('bakong-mode');
      applyBtn.innerHTML = '<i class="fa-solid fa-qrcode text-base"></i> ' + (i18n.apply_bakong || 'Apply Payment & Show QR');
    }
    if (changeInfoWrap) changeInfoWrap.style.display = 'none';
    if (returnMethodEl) returnMethodEl.textContent = 'Bakong KHQR';
    if (returnOptionsRow) returnOptionsRow.style.display = 'none';
    if (bakongNoticeRow) bakongNoticeRow.style.display = 'none';
    if (bottomHeaderTitle) bottomHeaderTitle.textContent = i18n.bakong_header || 'Bakong KHQR';
    if (bottomHeaderIcon) bottomHeaderIcon.className = 'fa-solid fa-qrcode text-rose-500';

    // Senior UX: Contextual Header Transition
    if (modalHeaderIcon) modalHeaderIcon.className = 'fa-solid fa-qrcode';
    if (modalHeaderTitle) modalHeaderTitle.textContent = i18n.modal_title_bakong || 'Bakong KHQR Payment';
    if (modalHeaderSub) modalHeaderSub.textContent = i18n.modal_sub_bakong || 'Scan QR code with any banking app';
    if (boxTitle) boxTitle.innerHTML = '<i class="fa-solid fa-qrcode text-rose-500"></i> ' + (i18n.box_title_bakong || 'Bakong KHQR Details');
    if (totalRecIcon) totalRecIcon.className = 'fa-solid fa-qrcode text-rose-500 text-sm';
    if (totalRecText) totalRecText.textContent = i18n.banner_label_bakong || 'Exact KHQR Total:';
    
    // Lock inputs
    if (inUsd) { inUsd.readOnly = true; inUsd.classList.add('opacity-75', 'cursor-not-allowed'); }
    if (inKhr) { inKhr.readOnly = true; inKhr.classList.add('opacity-75', 'cursor-not-allowed'); }

    cpmState.usdStr = cpmState.owedUsd.toFixed(2);
    cpmState.khrStr = '0';
    cpmUpdateDualDisplay(true);
  } else {
    if (modal) modal.classList.remove('cpm-is-bakong');
    if (cashBtn) cashBtn.classList.add('cpm-method-active');
    if (bkgBtn) bkgBtn.classList.remove('cpm-method-active');
    if (applyBtn) {
      applyBtn.classList.remove('bakong-mode');
      applyBtn.innerHTML = '<i class="fa-solid fa-circle-check text-base"></i> ' + (i18n.apply_payment || 'Apply Payment & Print Receipt');
    }
    if (changeInfoWrap) changeInfoWrap.style.display = 'flex';
    if (returnMethodEl) returnMethodEl.textContent = i18n.method_cash || 'Cash';
    if (returnOptionsRow) returnOptionsRow.style.display = 'flex';
    if (bakongNoticeRow) bakongNoticeRow.style.display = 'none';
    if (bottomHeaderTitle) bottomHeaderTitle.textContent = i18n.change_calc || 'Change Calculated';
    if (bottomHeaderIcon) bottomHeaderIcon.className = 'fa-solid fa-coins text-[#d1904b]';

    // Senior UX: Transition back to cash
    if (modalHeaderIcon) modalHeaderIcon.className = 'fa-solid fa-money-bill-wave';
    if (modalHeaderTitle) modalHeaderTitle.textContent = i18n.modal_title_cash || 'Cash Payment Settlement';
    if (modalHeaderSub) modalHeaderSub.textContent = i18n.modal_sub_cash || 'Take order cash & calculate change';
    if (boxTitle) boxTitle.innerHTML = '<i class="fa-solid fa-calculator text-emerald-500"></i> ' + (i18n.payment_details || 'Payment Details');
    if (totalRecIcon) totalRecIcon.className = 'fa-solid fa-hand-holding-dollar text-emerald-500 text-sm';
    if (totalRecText) totalRecText.textContent = (window.CPM_IS_KM ? 'សរុបប្រាក់ទទួលបាន:' : 'Total Received:');

    // Unlock inputs
    if (inUsd) { inUsd.readOnly = false; inUsd.classList.remove('opacity-75', 'cursor-not-allowed'); }
    if (inKhr) { inKhr.readOnly = false; inKhr.classList.remove('opacity-75', 'cursor-not-allowed'); }

    cpmUpdateDualDisplay(true);
  }
}

function cpSilentClearCart() {
  fetch('cart.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'ajax_clear=1' })
    .then(function() {
      if (typeof loadCartPanel === 'function') loadCartPanel();
      if (typeof pollProductStockStatuses === 'function') pollProductStockStatuses();
      if (typeof pollSidebarStockBadges === 'function') pollSidebarStockBadges();
    })
    .catch(function() {
      if (typeof loadCartPanel === 'function') loadCartPanel();
      if (typeof pollSidebarStockBadges === 'function') pollSidebarStockBadges();
    });
}
window.cpSilentClearCart = cpSilentClearCart;

function cpmConfirmPayment() {
  var form = document.getElementById('cpCheckoutForm');
  if (!form) return;

  var totalVal = cpGetCartTotal();

  if (cpmState.method === 'bakong') {
    cpmApplyBakongPayment();
    return;
  }

  var numUsd = parseFloat(cpmState.usdStr) || 0;
  var numKhr = parseFloat(cpmState.khrStr) || 0;
  var rate = window.CP_KHR_RATE || 4100;
  var totalRecUsd = numUsd + (numKhr / rate);

  if (totalRecUsd < totalVal - 0.005) {
    if (!confirm('Amount received ($' + totalRecUsd.toFixed(2) + ') is less than total due ($' + totalVal.toFixed(2) + '). Do you want to proceed?')) {
      return;
    }
  }

  var recUsdVal = numUsd.toFixed(2);
  var recKhrVal = Math.round(numKhr);
  var refStr = 'USD: $' + recUsdVal + ' | KHR: ៛' + recKhrVal.toLocaleString();
  if (typeof tenderRef === 'function') {
    refStr = tenderRef(numUsd, recKhrVal);
  }

  var inputsContainer = document.getElementById('cpPaymentInputs');
  if (inputsContainer) {
    inputsContainer.innerHTML =
      '<input type="hidden" name="payment_methods[]" value="cash">' +
      '<input type="hidden" name="payment_amounts[]" value="' + totalVal + '">' +
      '<input type="hidden" name="payment_references[]" value="' + refStr + '">' +
      '<input type="hidden" name="cash_received" value="' + recUsdVal + '">' +
      '<input type="hidden" name="cash_received_khr" value="' + recKhrVal + '">' +
      '<input type="hidden" name="change_return_mode" value="' + (cpmState.changeMode || 'mixed') + '">';
  }

  closeCashPaymentModal();

  var popupWin = window.open('about:blank', 'receipt_win', 'width=460,height=720,top=100,left=100,scrollbars=yes');
  if (popupWin) {
    try { popupWin.focus(); } catch(e) {}
    form.target = 'receipt_win';
  } else {
    form.target = '_self';
  }
  form.submit();
  setTimeout(function() {
    form.target = '_self';
    cpSilentClearCart();
  }, 400);
}

// ── In-Modal Bakong KHQR Execution ──
function cpmApplyBakongPayment() {
  var form = document.getElementById('cpCheckoutForm');
  if (!form) return;

  var totalVal = cpGetCartTotal();
  var applyBtn = document.getElementById('cpmApplyBtn');
  if (applyBtn) {
    applyBtn.disabled = true;
    applyBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-base"></i> Generating KHQR...';
  }

  var formData = new FormData(form);
  formData.append('payment_methods[]', 'bakong');
  formData.append('payment_amounts[]', totalVal);
  formData.append('payment_references[]', '');
  formData.append('is_ajax', '1');

  fetch('confirm_order.php', {
    method: 'POST',
    body: formData,
    headers: {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    }
  })
  .then(function(r) { return r.json(); })
  .then(function(res) {
    if (applyBtn) {
      applyBtn.disabled = false;
      applyBtn.innerHTML = '<i class="fa-solid fa-qrcode text-base"></i> Apply Payment & Show QR';
    }

    if (!res || !res.success) {
      if (cpmState.receiptWindow && !cpmState.receiptWindow.closed) {
        try { cpmState.receiptWindow.close(); } catch(e) {}
        cpmState.receiptWindow = null;
      }
      alert(res && res.error ? res.error : 'Failed to generate Bakong KHQR. Please check network/inventory.');
      return;
    }

    cpmState.activeBakongOrderId = res.order_id;
    cpmState.qrString = res.qr || '';

    // Switch Right Column from Cash/Keypad View to Bakong QR View
    var cashView = document.getElementById('cpmCashView');
    var qrView = document.getElementById('cpmBakongQrView');
    var successView = document.getElementById('cpmBakongSuccessView');
    var currSelector = document.getElementById('cpmCurrSelector');
    var changeInfoWrap = document.getElementById('cpmChangeInfoWrap');
    var normalActions = document.getElementById('cpmNormalActions');
    var bakongActions = document.getElementById('cpmBakongActions');
    var boxTitle = document.getElementById('cpmBoxTitle');

    var returnOptionsRow = document.getElementById('cpmReturnOptionsRow');
    var bakongNoticeRow = document.getElementById('cpmBakongDigitalNoticeRow');

    if (cashView) cashView.style.display = 'none';
    if (currSelector) currSelector.style.display = 'none';
    if (changeInfoWrap) changeInfoWrap.style.display = 'none';
    if (returnOptionsRow) returnOptionsRow.style.display = 'none';
    if (bakongNoticeRow) bakongNoticeRow.style.display = 'none';
    if (successView) successView.style.display = 'none';
    if (qrView) qrView.style.display = 'flex';
    if (normalActions) normalActions.style.display = 'none';
    if (bakongActions) bakongActions.style.display = 'flex';
    if (boxTitle) boxTitle.innerHTML = '<i class="fa-solid fa-qrcode text-red-400"></i> ' + (window.CPM_I18N ? window.CPM_I18N.bakong_header : 'Bakong KHQR Payment');

    // Update labels and amounts
    var dispUsd = document.getElementById('cpmBakongDispUsd');
    var dispKhr = document.getElementById('cpmBakongDispKhr');
    var merchantEl = document.getElementById('cpmBakongMerchant');
    var statusText = document.getElementById('cpmBakongStatusText');
    var spinner = document.getElementById('cpmBakongSpinner');

    var numTotal = parseFloat(res.amount || res.total || totalVal) || 0;
    var rate = window.CP_KHR_RATE || 4100;
    var numKhr = res.amount_khr || Math.round(numTotal * rate / 100) * 100;

    if (dispUsd) dispUsd.textContent = '$' + numTotal.toFixed(2);
    if (dispKhr) dispKhr.textContent = '(៛ ' + numKhr.toLocaleString() + ')';
    if (merchantEl) merchantEl.textContent = res.merchant_name || "The Bird's Nest Coffee";
    if (statusText) statusText.textContent = 'Waiting for Bakong payment...';
    if (spinner) spinner.className = 'fa-solid fa-spinner fa-spin text-amber-400';

    // Render QR
    cpmRenderBakongQR(res.qr);

    // Start background polling
    cpmStartBakongPolling(res.order_id);
  })
  .catch(function(err) {
    if (applyBtn) {
      applyBtn.disabled = false;
      applyBtn.innerHTML = '<i class="fa-solid fa-qrcode text-base"></i> Apply Payment & Show QR';
    }
    console.error('Bakong checkout error:', err);
    alert('Network error while generating Bakong QR. Please try again.');
  });
}

function cpmRenderBakongQR(qrString) {
  var container = document.getElementById('cpmBakongQrCanvas');
  if (!container) return;
  container.innerHTML = '';

  if (qrString && typeof QRCode !== 'undefined') {
    try {
      new QRCode(container, {
        text: qrString,
        width: 145,
        height: 145,
        colorDark: '#000000',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
      });
      return;
    } catch(e) {
      console.warn('QRCode JS render error:', e);
    }
  }

  // Fallback to QR server API image if QRCode.js isn't ready
  if (qrString) {
    var img = document.createElement('img');
    img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=290x290&data=' + encodeURIComponent(qrString);
    img.alt = 'Bakong KHQR';
    img.className = 'w-[145px] h-[145px] rounded-lg object-contain';
    container.appendChild(img);
  } else {
    container.innerHTML = '<div class="text-xs text-rose-500 font-bold p-4"><i class="fa-solid fa-triangle-exclamation text-xl mb-1"></i><br>QR not generated</div>';
  }
}

function cpmStartBakongPolling(orderId) {
  cpmStopBakongPolling();
  if (!orderId) return;

  cpmState.pollTimer = setInterval(function() {
    fetch('check_payment.php?order_id=' + orderId)
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res && res.paid) {
          cpmStopBakongPolling();
          cpmHandleBakongPaymentSuccess(orderId);
        } else if (res && res.error === 'rate_limited') {
          cpmStopBakongPolling();
          var st = document.getElementById('cpmBakongStatusText');
          var sp = document.getElementById('cpmBakongSpinner');
          if (sp) sp.className = 'fa-solid fa-triangle-exclamation text-amber-400';
          if (st) st.textContent = 'Bakong daily limit reached. Click Confirm Payment below.';
        }
      })
      .catch(function() {});
  }, 2500);
}

function cpmStopBakongPolling() {
  if (cpmState.pollTimer) {
    clearInterval(cpmState.pollTimer);
    cpmState.pollTimer = null;
  }
  if (cpmState.countdownTimer) {
    clearInterval(cpmState.countdownTimer);
    cpmState.countdownTimer = null;
  }
}

function cpmManualConfirmBakong() {
  var orderId = cpmState.activeBakongOrderId;
  if (!orderId) return;

  var btn = document.getElementById('cpmBtnManualConfirm');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Verifying...';
  }

  var formData = new FormData();
  formData.append('order_id', orderId);
  formData.append('action', 'manual_confirm');

  fetch('check_payment.php', { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (res && res.paid) {
        cpmHandleBakongPaymentSuccess(orderId);
      } else {
        alert(res && res.error ? res.error : 'Payment confirmation failed');
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = '<i class="fa-solid fa-circle-check text-base"></i> Confirm Payment Received';
        }
      }
    })
    .catch(function() {
      alert('Network error while confirming payment.');
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-circle-check text-base"></i> Confirm Payment Received';
      }
    });
}

function cpmHandleBakongPaymentSuccess(orderId, existingWin) {
  cpmStopBakongPolling();

  // Hide the payment settlement modal
  var cpmModal = document.getElementById('cashPaymentModal');
  if (cpmModal) {
    cpmModal.style.display = 'none';
  }

  // Silently clear cart so cashier is ready for next customer
  if (typeof cpSilentClearCart === 'function') {
    cpSilentClearCart();
  }

  var receiptUrl = 'receipt_print.php?order_id=' + Number(orderId);
  var targetWin = existingWin || cpmState.receiptWindow;

  // If popup window already open (from click when QR was generated or confirmed), load receipt immediately
  if (targetWin && !targetWin.closed) {
    try {
      targetWin.location.href = receiptUrl;
      targetWin.focus();
      cpmState.receiptWindow = null;
      return;
    } catch(e) {}
  }

  // Otherwise, open receipt print window
  var win = null;
  try {
    win = window.open(receiptUrl, 'receipt_win', 'width=460,height=720,top=100,left=100,scrollbars=yes');
    if (win) {
      try { win.focus(); } catch(e) {}
    }
  } catch(e) {}

  // Fallback to hidden iframe auto-print if browser blocked async popup
  if (!win || win.closed || typeof win.closed === 'undefined') {
    var iframe = document.getElementById('receiptPrintFrame');
    if (!iframe) {
      iframe = document.createElement('iframe');
      iframe.id = 'receiptPrintFrame';
      iframe.style.position = 'fixed';
      iframe.style.right = '0';
      iframe.style.bottom = '0';
      iframe.style.width = '0';
      iframe.style.height = '0';
      iframe.style.border = '0';
      document.body.appendChild(iframe);
    }
    iframe.src = receiptUrl;
  }
  cpmState.receiptWindow = null;
}

function cpmCancelActiveBakongOrder(skipConfirm) {
  var orderId = cpmState.activeBakongOrderId;
  if (!orderId) return;

  if (!skipConfirm) {
    var confirmMsg = window.CPM_IS_KM 
      ? ('អ្នកមានការកុម្ម៉ង់បាគង #' + orderId + ' កំពុងដំណើរការ។ តើអ្នកចង់បោះបង់ការកុម្ម៉ង់នេះ ហើយត្រឡប់ទៅកាន់កន្ត្រកវិញទេ?')
      : ('Do you want to cancel this Bakong payment and return items to cart?');
    if (!confirm(confirmMsg)) {
      return;
    }
  }

  cpmStopBakongPolling();

  if (cpmState.receiptWindow && !cpmState.receiptWindow.closed) {
    try { cpmState.receiptWindow.close(); } catch(e) {}
    cpmState.receiptWindow = null;
  }

  fetch('cancel_bakong_order.php?order_id=' + orderId, {
    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(function(r) { return r.json(); })
  .then(function() {
    cpmState.activeBakongOrderId = null;
    cpmState.qrString = '';

    // Restore Cash/Payment Details view
    var cashView = document.getElementById('cpmCashView');
    var qrView = document.getElementById('cpmBakongQrView');
    var successView = document.getElementById('cpmBakongSuccessView');
    var currSelector = document.getElementById('cpmCurrSelector');
    var normalActions = document.getElementById('cpmNormalActions');
    var bakongActions = document.getElementById('cpmBakongActions');
    var boxTitle = document.getElementById('cpmBoxTitle');

    if (cashView) cashView.style.display = 'grid';
    if (qrView) qrView.style.display = 'none';
    if (successView) successView.style.display = 'none';
    if (currSelector) currSelector.style.display = 'flex';
    if (normalActions) normalActions.style.display = 'flex';
    if (bakongActions) bakongActions.style.display = 'none';
    if (boxTitle) boxTitle.innerHTML = '<i class="fa-solid fa-dollar-sign"></i> Payment Details';

    cpmSetMethod('cash');
  })
  .catch(function() {
    window.location.href = 'cancel_bakong_order.php?order_id=' + orderId;
  });
}

function cpSelectDirectPayment(el, method) {
  var container = document.getElementById('cpDirectPayMethods');
  if (container) {
    container.querySelectorAll('.cp-pay-method').forEach(function(item) {
      item.classList.remove('selected');
      item.style.border = '';
      item.style.background = '';
      var cb = item.querySelector('input[type="checkbox"]');
      if (cb) cb.checked = false;
    });
  }
  el.classList.add('selected');
  var checkbox = el.querySelector('input[type="checkbox"]');
  if (checkbox) checkbox.checked = true;

  if (method === 'cash') {
    el.style.border = '1.5px solid #27ae60';
    el.style.background = 'rgba(39,174,96,.12)';
  } else if (method === 'bakong') {
    el.style.border = '1.5px solid #e0454a';
    el.style.background = 'rgba(224,69,74,.12)';
  } else if (method === 'paylater') {
    el.style.border = '1.5px solid #e8973a';
    el.style.background = 'rgba(232,151,58,.12)';
  }
}
window.cpSelectDirectPayment = cpSelectDirectPayment;

function cpSilentClearCart() {
  fetch('cart.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'ajax_clear=1' })
    .then(function() { loadCartPanel(); });
}

function cpOnConfirmOrderClick() {
  if (!cpHasCartItems()) {
    alert('Your cart is empty!');
    return;
  }
  if (!confirmOrderSubmit()) return;

  var selectedCb = document.querySelector('#cpDirectPayMethods input[name="payment_methods[]"]:checked');
  var methodVal = selectedCb ? selectedCb.value : 'cash';

  if (methodVal === 'cash' || methodVal === 'riel') {
    openCashPaymentModal();
    return;
  }

  var form = document.getElementById('cpCheckoutForm');
  if (form) {
    var totalVal = cpGetCartTotal();
    var inputsContainer = document.getElementById('cpPaymentInputs');
    if (inputsContainer) {
      inputsContainer.innerHTML =
        '<input type="hidden" name="payment_methods[]" value="' + methodVal + '">' +
        '<input type="hidden" name="payment_amounts[]" value="' + totalVal + '">' +
        '<input type="hidden" name="payment_references[]" value="">';
    }
    form.target = '_self';
    form.submit();
  }
}

// ── SPLIT PAYMENT ──
function cpInputToUsd(inp) {
  var val = Math.max(0, parseFloat(inp.value) || 0);
  return inp.dataset.currency === 'khr' ? val / CP_KHR_RATE : val;
}
function cpSetInputUsd(inp, usd) {
  if (inp.dataset.currency === 'khr') {
    inp.value = Math.round(usd * CP_KHR_RATE / 100) * 100;
    var d = inp.parentElement && inp.parentElement.querySelector('.cp-khr-usd');
    if (d) d.textContent = '≈ $' + usd.toFixed(2);
  } else {
    inp.value = usd.toFixed(2);
  }
}

function cpUpdateSplitInputs() {
  var selected = cpGetSelected();
  var si = document.getElementById('cpSplitInputs');
  var sr = document.getElementById('cpSplitRows');
  if (!si || !sr) return;
  if (selected.includes('paylater') || selected.length <= 1) { si.classList.remove('active'); sr.innerHTML = ''; return; }
  si.classList.add('active');
  var total = cpGetCartTotal();
  var each  = Math.floor((total / selected.length) * 100) / 100;
  var rem   = Math.round((total - each * selected.length) * 100) / 100;
  var html  = '';
  selected.forEach(function(m, i) {
    var usd = i === selected.length - 1 ? (each + rem).toFixed(2) : each.toFixed(2);
    if (m === 'riel') {
      var khr = Math.round(parseFloat(usd) * CP_KHR_RATE / 100) * 100;
      html += '<div class="cp-split-row"><label>Riel &#x17DB;</label>' +
        '<div style="display:flex;flex-direction:column;gap:3px;flex:1;">' +
        '<input type="number" step="1" class="cp-split-amount" value="' + khr + '" data-method="riel" data-currency="khr" oninput="cpOnSplitChange(this)">' +
        '<span class="cp-khr-usd" style="font-size:11px;color:#888;">≈ $' + usd + '</span>' +
        '</div></div>';
    } else {
      var lbl = m.charAt(0).toUpperCase() + m.slice(1);
      html += '<div class="cp-split-row"><label>' + lbl + '</label><input type="number" step="0.01" class="cp-split-amount" value="' + usd + '" data-method="' + m + '" oninput="cpOnSplitChange(this)"></div>';
    }
  });
  sr.innerHTML = html;
}

function cpUpdateSplitAmounts() {
  var selected = cpGetSelected();
  if (selected.length < 2) return;
  var total = cpGetCartTotal();
  var each  = Math.floor((total / selected.length) * 100) / 100;
  var rem   = Math.round((total - each * selected.length) * 100) / 100;
  var inputs = document.querySelectorAll('.cp-split-amount');
  inputs.forEach(function(inp, i) {
    cpSetInputUsd(inp, i === inputs.length - 1 ? each + rem : each);
  });
}

function cpOnSplitChange(changedInp) {
  var total = cpGetCartTotal();
  var inputs = Array.from(document.querySelectorAll('.cp-split-amount'));
  var changedUsd = cpInputToUsd(changedInp);
  if (changedInp.dataset.currency === 'khr') {
    var d = changedInp.parentElement && changedInp.parentElement.querySelector('.cp-khr-usd');
    if (d) d.textContent = '≈ $' + changedUsd.toFixed(2);
  }
  var others = inputs.filter(function(inp) { return inp !== changedInp; });
  if (others.length === 1) {
    var remaining = total - changedUsd;
    if (remaining < 0) { cpSetInputUsd(changedInp, total); cpSetInputUsd(others[0], 0); }
    else cpSetInputUsd(others[0], remaining);
  }
}

// ── CHANGE CALCULATOR ──
/* The received === 0 guard used to mean "nothing entered yet". With dollars and
   riel in separate fields, ZERO DOLLARS IS THE NORMAL RIEL-ONLY CASE — a cashier
   who types ៛5,500 on a $1.34 order would otherwise see the change line sit at
   $0.00 and hand back nothing: wrong on screen, right in the database. The guard
   keys on the combined total from tender.js instead.
   The arithmetic and the wording both live in tender.js so this screen and the
   counter screen cannot drift apart. */
function cpCashReceivedUsd() {
  return tenderCashReceivedUsd('cpCashReceived', 'cpRielCash', CP_KHR_RATE);
}

function cpCalcChange() {
  var el = document.getElementById('cpChangeAmount');
  var elDollar = document.getElementById('cpChangeDollar');
  var elRiel = document.getElementById('cpChangeRiel');
  var elMixed = document.getElementById('cpChangeMixed');

  var received = cpCashReceivedUsd();
  var owed     = cpOwedInCash();
  var ch       = tenderChange(received, owed, CP_KHR_RATE,
                              tenderFieldsRielOnly('cpCashReceived', 'cpRielCash'));

  var warn = document.getElementById('cpShortWarn');
  if (warn) warn.style.display = (received > 0 && ch.short) ? 'block' : 'none';

  var changeUsd = Math.max(0, received - owed);
  var changeKhr = Math.round((changeUsd * CP_KHR_RATE) / 100) * 100;

  var mixedUsd = ch.usd;
  var mixedKhr = ch.khr;
  var mixedStr = '$0.00';
  if (changeUsd > 0) {
    if (mixedUsd > 0 && mixedKhr > 0) {
      mixedStr = '$' + mixedUsd + ' + ៛' + mixedKhr.toLocaleString();
    } else if (mixedUsd > 0) {
      mixedStr = '$' + mixedUsd.toFixed(2);
    } else {
      mixedStr = '៛' + mixedKhr.toLocaleString();
    }
  }

  if (elDollar) elDollar.textContent = '$' + changeUsd.toFixed(2);
  if (elRiel) elRiel.textContent = '៛' + changeKhr.toLocaleString();
  if (elMixed) elMixed.textContent = mixedStr;

  if (el) {
    if (received === 0) { el.textContent = '$0.00'; el.className = 'change-amount'; return; }
    el.className   = ch.short ? 'change-amount not-enough' : 'change-amount';
    el.textContent = tenderChangeText(ch, received, owed);
  }
}

function cpSelectChangeCurrency(curr) {
  var bUsd = document.querySelector('#cpChangeCurrencySelect button[data-curr="usd"]');
  var bKhr = document.querySelector('#cpChangeCurrencySelect button[data-curr="khr"]');
  var bMixed = document.querySelector('#cpChangeCurrencySelect button[data-curr="mixed"]');

  var boxUsd = document.getElementById('cpChangeBoxUsd');
  var boxKhr = document.getElementById('cpChangeBoxKhr');
  var boxMixed = document.getElementById('cpChangeBoxMixed');

  [bUsd, bKhr, bMixed].forEach(function(b) {
    if (b) { b.style.background = 'transparent'; b.style.color = '#aaa'; b.classList.remove('active'); }
  });
  [boxUsd, boxKhr, boxMixed].forEach(function(box) {
    if (box) box.style.display = 'none';
  });

  if (curr === 'khr') {
    if (bKhr) { bKhr.style.background = '#55e087'; bKhr.style.color = '#000'; bKhr.classList.add('active'); }
    if (boxKhr) boxKhr.style.display = 'flex';
  } else if (curr === 'usd') {
    if (bUsd) { bUsd.style.background = '#55e087'; bUsd.style.color = '#000'; bUsd.classList.add('active'); }
    if (boxUsd) boxUsd.style.display = 'flex';
  } else {
    if (bMixed) { bMixed.style.background = '#55e087'; bMixed.style.color = '#000'; bMixed.classList.add('active'); }
    if (boxMixed) boxMixed.style.display = 'flex';
  }
}
window.cpSelectChangeCurrency = cpSelectChangeCurrency;

function cpOnRielInput() {
  tenderOnRielInput('cpCashReceived', 'cpRielCash', 'cpRielCashUsd', CP_KHR_RATE);
}

function cpRenderRielQuick() {
  tenderRenderRielQuick('cpRielQuick', 'cpRielCash', cpOwedInCash(), CP_KHR_RATE,
    function () { cpOnRielInput(); cpCalcChange(); });
}

function cpCalcRielChange() {
  var khr       = parseFloat(document.getElementById('cpRielReceived')?.value) || 0;
  var usdEl     = document.getElementById('cpRielUsdEquiv');
  var changeRow = document.getElementById('cpRielChangeRow');
  var changeEl  = document.getElementById('cpRielChangeKhr');
  if (usdEl) usdEl.textContent = '$' + (khr / CP_KHR_RATE).toFixed(2);
  if (changeRow && changeEl) {
    var orderKhr = Math.round(cpGetCartTotal() * CP_KHR_RATE / 100) * 100;
    var diff     = Math.round(khr) - orderKhr;
    if (khr > 0 && diff < 0) {
      changeEl.textContent = 'Need ៛' + Math.abs(diff).toLocaleString();
      changeEl.className   = 'change-amount not-enough';
      changeRow.style.display = 'flex';
    } else if (khr > 0 && diff >= 0) {
      changeEl.textContent = '៛' + diff.toLocaleString();
      changeEl.className   = 'change-amount';
      changeRow.style.display = 'flex';
    } else {
      changeRow.style.display = 'none';
    }
  }
}

// ── ORDER TYPE ──
function cpSetDrinkType(type) {
  var inp = document.getElementById('cpOrderTypeInput');
  if (inp) inp.value = type;
  var din  = document.getElementById('cpBtnDrinkIn');
  var dout = document.getElementById('cpBtnDrinkOut');
  if (din)  din.classList.toggle('active',  type === 'drink_in');
  if (dout) dout.classList.toggle('active', type === 'drink_out');
}

function confirmOrderSubmit() {
  return true;
}
window.confirmOrderSubmit = confirmOrderSubmit;

// ── CHECKOUT FORM SUBMIT ──
document.addEventListener('DOMContentLoaded', function() {
  var form = document.getElementById('cpCheckoutForm');
  if (form) {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      var selected = cpGetSelected();
      if (selected.length === 0 && !ADD_TO_ORDER_MODE) { alert('Please select a payment method.'); return; }
      if (ADD_TO_ORDER_MODE) selected = ['paylater'];

      if (!ADD_TO_ORDER_MODE) {
        if (!confirmOrderSubmit()) return;

        // Sync table / stand number to hidden input in form
        var tableInput = document.getElementById('stand-number-input') || document.getElementById('cpTableNumber');
        var existingTable = form.querySelector('input[name="table_number"]');
        if (!existingTable) {
          existingTable = document.createElement('input');
          existingTable.type = 'hidden'; existingTable.name = 'table_number';
          form.appendChild(existingTable);
        }
        existingTable.value = tableInput ? tableInput.value : '';
      }

      var total = cpGetCartTotal();
      var selected = cpGetSelected();
      if (selected.length === 0) selected = ['cash'];

      if (selected.includes('cash')) {
        var recUsd = cpCashReceivedUsd();
        if (recUsd <= 0) {
          alert('Please enter received money!');
          var crInput = document.getElementById('cpCashReceived');
          if (crInput) crInput.focus();
          return;
        }
        if (recUsd < total - 0.005) {
          alert('Amount received ($' + recUsd.toFixed(2) + ') is lower than the total price ($' + total.toFixed(2) + ')!');
          var crInput = document.getElementById('cpCashReceived');
          if (crInput) crInput.focus();
          return;
        }
      }

      var splits = document.querySelectorAll('.cp-split-amount');
      var selectedAmounts = [];

      var isSplitActive = (splits.length > 0 && document.getElementById('cpSplitInputs') && document.getElementById('cpSplitInputs').classList.contains('active'));

      if (isSplitActive) {
        // Sum in USD (convert KHR inputs)
        var sumUsd = 0;
        splits.forEach(function(inp) { sumUsd += cpInputToUsd(inp); });
        if (Math.abs(sumUsd - total) > 0.005) {
          var last = splits[splits.length - 1];
          var diff = total - sumUsd;
          if (last.dataset.currency === 'khr') {
            last.value = Math.max(0, Math.round(((parseFloat(last.value) || 0) + diff * CP_KHR_RATE) / 100) * 100);
          } else {
            last.value = Math.max(0, parseFloat(last.value) + diff).toFixed(2);
          }
        }
        splits.forEach(function(inp) { selectedAmounts.push(parseFloat(inp.value).toFixed(2)); });
      } else {
        if (selected.length > 1) {
          selected = [selected[0]];
        }
        selectedAmounts = [total.toFixed(2)];
      }

      var container = document.getElementById('cpPaymentInputs');
      container.innerHTML = '';
      selected.forEach(function(method, i) {
        var usdAmount = selectedAmounts[i] || '0';
        var reference = '';
        if (method === 'riel') {
          var khrInput = selected.length > 1
            ? document.querySelector('.cp-split-amount[data-method="riel"]')
            : document.getElementById('cpRielReceived');
          var khrVal = Math.max(0, parseFloat(khrInput ? khrInput.value : 0) || 0);
          usdAmount = (khrVal / CP_KHR_RATE).toFixed(2);
          reference = Math.round(khrVal).toString();
        }
        if (method === 'cash') {
          var usd = Math.max(0, parseFloat((document.getElementById('cpCashReceived') || {}).value) || 0);
          var khr = Math.max(0, parseFloat((document.getElementById('cpRielCash')     || {}).value) || 0);
          reference = tenderRef(usd, Math.round(khr));
        }
        var h1 = document.createElement('input'); h1.type='hidden'; h1.name='payment_methods[]'; h1.value=method; container.appendChild(h1);
        var h2 = document.createElement('input'); h2.type='hidden'; h2.name='payment_amounts[]'; h2.value=usdAmount; container.appendChild(h2);
        var h3 = document.createElement('input'); h3.type='hidden'; h3.name='payment_references[]'; h3.value=reference; container.appendChild(h3);
      });
      try {
        window.open('about:blank', 'receipt_win', 'width=460,height=720,top=100,left=100,scrollbars=yes');
      } catch(err) {}
      form.submit();
    });
  }

  // Confirm Payment button (inside modal) triggers the form submit handler above
  var confirmPayBtn = document.getElementById('cpConfirmPayBtn');
  if (confirmPayBtn) {
    confirmPayBtn.addEventListener('click', function() {
      var selected = cpGetSelected();
      if (selected.length === 0 || selected.includes('cash')) {
        var recUsd = cpCashReceivedUsd();
        var total  = cpGetCartTotal();
        if (recUsd <= 0) {
          alert('Please enter received money!');
          var crInput = document.getElementById('cpCashReceived');
          if (crInput) crInput.focus();
          return;
        }
        if (recUsd < total - 0.005) {
          alert('Amount received ($' + recUsd.toFixed(2) + ') is lower than the total price ($' + total.toFixed(2) + ')!');
          var crInput = document.getElementById('cpCashReceived');
          if (crInput) crInput.focus();
          return;
        }
      }
      try {
        window.open('about:blank', 'receipt_win', 'width=460,height=720,top=100,left=100,scrollbars=yes');
      } catch(err) {}
      document.getElementById('cpCheckoutForm').requestSubmit();
    });
  }

  // Cash Payment Modal: backdrop + Esc close + Enter confirm
  var cpmModal = document.getElementById('cashPaymentModal');
  if (cpmModal) {
    cpmModal.addEventListener('click', function(e) {
      if (e.target === this) closeCashPaymentModal();
    });
  }
  document.addEventListener('keydown', function(e) {
    var cpm = document.getElementById('cashPaymentModal');
    if (cpm && cpm.style.display !== 'none' && cpm.style.display !== '') {
      if (e.key === 'Escape') {
        e.preventDefault();
        closeCashPaymentModal();
      } else if (e.key === 'Enter') {
        e.preventDefault();
        cpmConfirmPayment();
      }
    }
  });

  // Keyboard shortcuts
  document.addEventListener('keydown', function(e) {
    var cpm = document.getElementById('cashPaymentModal');
    if (cpm && cpm.style.display !== 'none' && cpm.style.display !== '') return;

    var tag = document.activeElement.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
    if (typeof ADD_TO_ORDER_MODE !== 'undefined' && ADD_TO_ORDER_MODE) {
      if (e.key.toLowerCase() === 'enter') { e.preventDefault(); cpOnConfirmOrderClick(); }
      return;
    }
    var key = e.key.toLowerCase();
    if (key === 'enter') {
      e.preventDefault();
      var prodModal = document.getElementById('product-modal') || document.getElementById('modal');
      if (prodModal && prodModal.style.display !== 'none') {
        addToCart();
        return;
      }
      cpOnConfirmOrderClick();
    } else if (key === 'escape') {
      closeModal();
      closeCashPaymentModal();
    }
  });

  // Sort on change
  var sortSelect = document.getElementById('sortSelect');
  if (sortSelect) sortSelect.addEventListener('change', function() { document.getElementById('searchForm').submit(); });

  // Modal backdrop close
  var modal = document.getElementById('modal');
  if (modal) modal.addEventListener('click', function(e) { if (e.target === this) closeModal(); });

  // Wire product cards
  // Wire product cards with universal event delegation
  document.addEventListener('click', function(e) {
    var card = e.target.closest('.product-card');
    if (card && !e.target.closest('.quick-add-btn') && !e.target.closest('button')) {
      openModalFromCard(card);
    }
  });
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' || e.key === ' ') {
      var card = document.activeElement ? document.activeElement.closest('.product-card') : null;
      if (card && !document.activeElement.closest('.quick-add-btn') && !document.activeElement.closest('button') && document.activeElement.tagName !== 'INPUT') {
        e.preventDefault();
        openModalFromCard(card);
      }
    }
  });

  // Scrollspy
  var catSections = document.querySelectorAll('.cat-section');
  var catPills    = document.querySelectorAll('.cat-pill[data-target]');
  if (catSections.length && catPills.length) {
    var menuScroll = document.getElementById('menuScroll');
    var observer = new IntersectionObserver(function(entries) {
      entries.forEach(function(entry) {
        if (entry.isIntersecting) {
          var id = entry.target.id;
          catPills.forEach(function(pill) { pill.classList.toggle('active', pill.dataset.target === id); });
        }
      });
    }, { threshold: 0.25, root: menuScroll, rootMargin: '-60px 0px -55% 0px' });
    catSections.forEach(function(s) { observer.observe(s); });
  }

  // Category pill smooth scroll (within menu panel)
  catPills.forEach(function(pill) {
    pill.addEventListener('click', function(e) {
      e.preventDefault();
      var targetId = this.dataset.target;
      var target = document.getElementById(targetId);
      var scrollEl = document.getElementById('menuScroll');
      if (target && scrollEl) {
        var scrollRect = scrollEl.getBoundingClientRect();
        var targetRect = target.getBoundingClientRect();
        var targetTop = scrollEl.scrollTop + (targetRect.top - scrollRect.top) - 12;
        scrollEl.scrollTo({ top: Math.max(0, targetTop), behavior: 'smooth' });

        catPills.forEach(function(p) { p.classList.toggle('active', p === pill); });
        pill.scrollIntoView({ behavior: 'smooth', inline: 'nearest', block: 'nearest' });
      } else if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });

  // Chat enter key
  var chatInput = document.getElementById('chatInput');
  if (chatInput) chatInput.addEventListener('keypress', function(e) { if (e.key==='Enter') sendChat(); });
});

function cpClickPayMethod(method) {
  var el = document.querySelector('.cp-pay-method input[value="' + method + '"]');
  if (el) el.closest('.cp-pay-method').click();
}

// ── TOAST (Max 2 concurrent alerts with automatic dismiss queue) ──
function showToast(message, type, duration) {
  type = type || 'success';
  duration = duration || 2800;
  var MAX_VISIBLE_TOASTS = 2;

  var container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    document.body.appendChild(container);
  }

  // Cap visible toasts to max 2 (gracefully dismiss oldest if full)
  var activeToasts = container.querySelectorAll('.toast:not(.hide)');
  if (activeToasts.length >= MAX_VISIBLE_TOASTS) {
    var toRemoveCount = activeToasts.length - MAX_VISIBLE_TOASTS + 1;
    for (var i = 0; i < toRemoveCount; i++) {
      var oldest = activeToasts[i];
      if (oldest && !oldest.classList.contains('hide')) {
        oldest.classList.remove('show');
        oldest.classList.add('hide');
        (function(el) {
          setTimeout(function() {
            if (el && el.parentNode) el.parentNode.removeChild(el);
          }, 200);
        })(oldest);
      }
    }
  }

  // Clean message if emojis already exist
  var cleanMsg = String(message || '').replace(/^[✅❌⚠️🔁🗑️🔔👨‍🍳\s]+/, '');

  var toast = document.createElement('div');
  toast.className = 'toast ' + type;

  var iconClass = 'fa-check';
  if (type === 'warning') iconClass = 'fa-triangle-exclamation';
  else if (type === 'error') iconClass = 'fa-xmark';
  else if (type === 'info') iconClass = 'fa-circle-info';

  toast.innerHTML = 
    '<div class="toast-icon-badge"><i class="fa-solid ' + iconClass + '"></i></div>' +
    '<span class="toast-msg">' + cleanMsg + '</span>' +
    '<button type="button" class="toast-dismiss" title="Dismiss"><i class="fa-solid fa-xmark"></i></button>' +
    '<div class="toast-progress"><div class="toast-progress-bar" style="animation-duration:' + (duration / 1000) + 's"></div></div>';

  var dismissTimeout = null;
  var remainingTime = duration;
  var startTime = Date.now();
  var isPaused = false;

  function startTimer(ms) {
    startTime = Date.now();
    dismissTimeout = setTimeout(removeToast, ms);
  }

  function removeToast() {
    if (dismissTimeout) clearTimeout(dismissTimeout);
    toast.classList.remove('show');
    toast.classList.add('hide');
    setTimeout(function() {
      if (toast && toast.parentNode) toast.parentNode.removeChild(toast);
    }, 220);
  }

  // Hover to pause countdown timer
  var progressBar = toast.querySelector('.toast-progress-bar');
  toast.addEventListener('mouseenter', function() {
    if (isPaused) return;
    isPaused = true;
    clearTimeout(dismissTimeout);
    var elapsed = Date.now() - startTime;
    remainingTime = Math.max(500, remainingTime - elapsed);
    if (progressBar) {
      progressBar.style.animationPlayState = 'paused';
    }
  });

  toast.addEventListener('mouseleave', function() {
    if (!isPaused) return;
    isPaused = false;
    if (progressBar) {
      progressBar.style.animationPlayState = 'running';
    }
    startTimer(remainingTime);
  });

  var closeBtn = toast.querySelector('.toast-dismiss');
  if (closeBtn) {
    closeBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      removeToast();
    });
  }

  container.appendChild(toast);
  requestAnimationFrame(function() {
    toast.classList.add('show');
  });

  startTimer(duration);
}

// ── CHAT ──
function toggleChat() {
  var box = document.getElementById('chatBox');
  var isOpen = box.style.display === 'flex';
  box.style.display = isOpen ? 'none' : 'flex';
  if (!isOpen) document.getElementById('chatInput').focus();
}

function sendChat() {
  var input   = document.getElementById('chatInput');
  var msg     = input.value.trim();
  if (!msg) return;
  var chat    = document.getElementById('chatMessages');
  var sendBtn = document.getElementById('chatSendBtn');
  var userMsg = document.createElement('div');
  userMsg.className = 'msg-user';
  userMsg.innerHTML = '<div class="bubble">' + msg + '</div><div class="avatar"><i class="fa-solid fa-user"></i></div>';
  chat.appendChild(userMsg);
  chat.scrollTop = chat.scrollHeight;
  input.value = ''; sendBtn.disabled = true;
  fetch('chatbot.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'message='+encodeURIComponent(msg) })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      var botMsg = document.createElement('div');
      botMsg.className = 'msg-bot';
      botMsg.innerHTML = '<div class="avatar"><i class="fa-solid fa-robot"></i></div><div class="bubble">' + (data.reply||'Sorry, I did not catch that.') + '</div>';
      chat.appendChild(botMsg);
      chat.scrollTop = chat.scrollHeight;
    })
    .catch(function() {})
    .finally(function() { sendBtn.disabled = false; });
}

// ── LOYALTY MODAL ──
function openLoyaltyModal() {
  document.getElementById('loyaltyModal').style.display = 'flex';
  setTimeout(function() { document.getElementById('loyaltyIdInput').focus(); }, 100);
}
function closeLoyaltyModal() {
  document.getElementById('loyaltyModal').style.display = 'none';
  document.getElementById('loyaltyResult').style.display = 'none';
  document.getElementById('loyaltyError').style.display = 'none';
}

async function lookupLoyalty() {
  var loyaltyId = document.getElementById('loyaltyIdInput').value.trim();
  if (!loyaltyId) { showToast('Please enter a loyalty ID', 'error'); return; }
  try {
    var res  = await fetch('loyalty_lookup.php?loyalty_id=' + encodeURIComponent(loyaltyId));
    var data = await res.json();
    if (!data.found) {
      document.getElementById('loyaltyResult').style.display = 'none';
      document.getElementById('loyaltyError').style.display = 'block';
      return;
    }
    document.getElementById('loyaltyError').style.display  = 'none';
    document.getElementById('loyaltyResult').style.display = 'block';
    document.getElementById('loyaltyDisplayId').textContent = data.loyalty_id;
    document.getElementById('loyaltyPoints').textContent    = data.points;

    var rewardsHtml = data.rewards.map(function(reward) {
      var can = data.points >= reward.points_required;
      return '<div style="padding:10px;border-radius:7px;border:1px solid ' + (can?'#d1904b':'var(--border,#e0d4c4)') + ';text-align:center;background:' + (can?'rgba(209,144,75,.08)':'rgba(0,0,0,.02)') + ';">' +
        '<div style="font-weight:600;color:var(--text,#1a1410);font-size:12px;">' + escH(reward.reward_name) + '</div>' +
        '<div style="font-size:11px;color:var(--text-sec,#5a4a3a);">' + reward.points_required + ' pts</div>' +
        (can ? '<button onclick="redeemReward(\'' + escH(reward.reward_name) + '\',' + reward.points_required + ')" style="margin-top:4px;padding:3px 10px;border-radius:50px;border:none;background:#d1904b;color:#000;font-weight:600;font-size:10px;cursor:pointer;font-family:\'Poppins\',sans-serif;">Redeem</button>'
             : '<button disabled style="margin-top:4px;padding:3px 10px;border-radius:50px;border:none;background:#ccc;color:#666;font-weight:600;font-size:10px;cursor:not-allowed;font-family:\'Poppins\',sans-serif;">Need ' + reward.points_required + ' pts</button>') +
      '</div>';
    }).join('');
    document.getElementById('loyaltyRewards').innerHTML = rewardsHtml;

    var historyHtml = data.history.map(function(h) {
      var sign = h.points_change > 0 ? '+' : '';
      var color = h.points_change > 0 ? '#55e087' : '#e74c3c';
      return '<div style="display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px solid var(--border,#e0d4c4);">' +
        '<span style="color:var(--text-sec,#5a4a3a);">' + h.type.charAt(0).toUpperCase()+h.type.slice(1) + (h.reward_name?' - '+h.reward_name:'') + '</span>' +
        '<span style="color:'+color+';font-weight:600;">' + sign + h.points_change + '</span></div>';
    }).join('');
    document.getElementById('loyaltyHistory').innerHTML = historyHtml || '<div style="text-align:center;color:var(--text-muted,#9a8070);">No history yet</div>';

    // Update cart panel loyalty status
    var statusEl = document.getElementById('cpLoyaltyStatus');
    var btnEl    = document.getElementById('cpLoyaltyBtn');
    if (statusEl) { statusEl.className = 'linked'; statusEl.innerHTML = data.loyalty_id + ' &mdash; ' + data.points + ' pts'; }
    if (btnEl)    btnEl.innerHTML = '<i class="fa-solid fa-circle-check"></i> Linked';
  } catch(err) { showToast('Error looking up loyalty card', 'error'); }
}

async function redeemReward(rewardName, pointsRequired) {
  var loyaltyId = document.getElementById('loyaltyDisplayId').textContent;
  if (!confirm('Redeem ' + rewardName + ' for ' + pointsRequired + ' points?')) return;
  try {
    var res  = await fetch('loyalty_redeem.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'loyalty_id='+encodeURIComponent(loyaltyId)+'&reward_name='+encodeURIComponent(rewardName) });
    var data = await res.json();
    if (data.success) {
      document.getElementById('loyaltyPoints').textContent = data.new_points;
      showToast('✅ ' + data.message, 'success');
    } else { showToast(data.message || 'Error redeeming reward', 'error'); }
  } catch(e) { showToast('Error redeeming reward', 'error'); }
}

// ── Stand number picker ──
function cpToggleStandGrid() {
  var grid = document.getElementById('cpStandGrid');
  if (!grid) return;
  if (grid.style.display !== 'none') { grid.style.display = 'none'; return; }
  grid.innerHTML = '<div style="text-align:center;padding:10px;color:#888;font-size:12px;"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div>';
  grid.style.display = 'block';
  fetch('get_stands.php')
    .then(function(r) { return r.json(); })
    .then(function(data) {
      var active = data.stands || {};
      var cells = '';
      for (var i = 1; i <= CP_STAND_MAX; i++) {
        var key = String(i);
        var info = active[key];
        if (info) {
          var tip = 'Order #' + info.order_no + (info.customer ? ' (' + info.customer + ')' : '') + ' — ' + info.status;
          cells += '<div title="' + tip.replace(/"/g,'&quot;') + '" style="display:flex;align-items:center;justify-content:center;height:36px;border-radius:7px;font-size:13px;font-weight:600;cursor:not-allowed;background:rgba(231,76,60,.18);color:#ff6b6b;border:1px solid rgba(231,76,60,.35);position:relative;">' + i + '<span style="position:absolute;top:-3px;right:-3px;width:8px;height:8px;border-radius:50%;background:#ef4444;border:1px solid #1a1a1a;"></span></div>';
        } else {
          cells += '<div onclick="cpPickStand(' + i + ')" style="display:flex;align-items:center;justify-content:center;height:36px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;background:rgba(62,207,112,.15);color:#3ecf70;border:1px solid rgba(62,207,112,.3);transition:transform .1s;" onmouseover="this.style.transform=\'scale(1.1)\'" onmouseout="this.style.transform=\'\'">' + i + '</div>';
        }
      }
      // Cells scroll inside a capped box (.stand-cells) — STAND_COUNT is configurable
      // (70+ is valid) and .cp-summary can't grow past the panel, so an uncapped grid
      // gets clipped with no way to reach the lower stands. Legend stays outside it.
      grid.innerHTML =
        '<div class="stand-cells">' + cells + '</div>' +
        '<div style="margin-top:8px;font-size:10px;color:#666;display:flex;gap:12px;">' +
        '<span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:rgba(62,207,112,.15);border:1px solid rgba(62,207,112,.3);vertical-align:middle;margin-right:3px;"></span>Free</span>' +
        '<span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:rgba(231,76,60,.18);border:1px solid rgba(231,76,60,.35);vertical-align:middle;margin-right:3px;"></span>In use</span>' +
        '</div>';
    })
    .catch(function() {
      grid.innerHTML = '<div style="text-align:center;padding:10px;color:#ef4444;font-size:12px;">Could not load stands</div>';
    });
}

function cpPickStand(num) {
  var inp = document.getElementById('cpTableNumber');
  if (inp) { inp.value = num; cpCheckStand(String(num)); }
  var grid = document.getElementById('cpStandGrid');
  if (grid) grid.style.display = 'none';
}

function cpCheckStand(val) {
  var warn = document.getElementById('cpStandWarn');
  if (!warn) return;
  val = (val || '').trim();
  if (!val) { warn.style.display = 'none'; return; }
  fetch('check_stand.php?stand=' + encodeURIComponent(val))
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.in_use) {
        document.getElementById('cpStandWarnText').textContent =
          'Stand ' + val + ' is in use by Order #' + data.order_no +
          (data.customer ? ' (' + data.customer + ')' : '') + ' – ' + data.status;
        warn.style.display = 'flex';
      } else {
        warn.style.display = 'none';
      }
    })
    .catch(function() { warn.style.display = 'none'; });
}

</script>
<?php 
$_print_order_no = '';
if (isset($_GET['print_order_id'])) {
    $_p_id = (int)$_GET['print_order_id'];
    $_stmt_p = $conn->prepare("SELECT order_id AS daily_order_no FROM orders WHERE order_id = ?");
    if ($_stmt_p) {
        $_stmt_p->bind_param("i", $_p_id);
        $_stmt_p->execute();
        $_res_p = $_stmt_p->get_result()->fetch_assoc();
        if ($_res_p && !empty($_res_p['daily_order_no'])) {
            $_print_order_no = sprintf('%04d', (int)$_res_p['daily_order_no']);
        }
        $_stmt_p->close();
    }
}
?>
<?php if (isset($_GET['print_order_id'])): ?>
<style>
.order-toast-right {
    position: fixed;
    top: 24px;
    right: 24px;
    z-index: 999999;
    background: #18181b;
    border: 1px solid rgba(85, 224, 135, 0.4);
    border-left: 5px solid #55e087;
    border-radius: 14px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 12px 35px rgba(0, 0, 0, 0.6);
    animation: slideInRight 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    min-width: 300px;
    max-width: 90vw;
}
@keyframes slideInRight {
    from { transform: translateX(120%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
.order-toast-icon {
    width: 40px;
    height: 40px;
    background: rgba(85, 224, 135, 0.15);
    color: #55e087;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}
.order-toast-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.order-toast-title {
    color: #ffffff;
    font-size: 15px;
    font-weight: 800;
    margin: 0;
    font-family: 'Poppins', sans-serif;
}
.order-toast-msg {
    color: #a1a1aa;
    font-size: 13px;
    margin: 0;
    font-family: 'Poppins', sans-serif;
}
.order-toast-close {
    background: none;
    border: none;
    color: #71717a;
    font-size: 16px;
    cursor: pointer;
    padding: 4px;
    line-height: 1;
    transition: color 0.2s ease;
}
.order-toast-close:hover {
    color: #ffffff;
}
</style>

<div class="order-toast-right" id="orderSuccessAlert">
    <div class="order-toast-icon">
        <i class="fa-solid fa-circle-check"></i>
    </div>
    <div class="order-toast-content">
        <h4 class="order-toast-title">Order #<?= htmlspecialchars($_print_order_no ?: '001') ?></h4>
        <p class="order-toast-msg">Done! Sent to printer 🖨️</p>
    </div>
    <button type="button" onclick="printReceipt(<?= (int)$_GET['print_order_id'] ?>)" class="print-toast-btn" style="background:#d1904b;color:#fff;border:none;border-radius:8px;padding:6px 12px;font-size:12px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:5px;">
        <i class="fa-solid fa-print"></i> Print
    </button>
    <button type="button" class="order-toast-close" onclick="closeOrderSuccessAlert()" title="Close">
        <i class="fa-solid fa-xmark"></i>
    </button>
</div>

<script>
function printReceipt(orderId) {
    if (!orderId) return;
    var win = window.open('receipt_print.php?order_id=' + orderId, 'receipt_win', 'width=460,height=720,top=100,left=100,scrollbars=yes');
    if (win) {
        try { win.focus(); } catch(e) {}
    }
}

function closeOrderSuccessAlert() {
    var el = document.getElementById('orderSuccessAlert');
    if (el) el.remove();
}

setTimeout(closeOrderSuccessAlert, 7000);

document.addEventListener("DOMContentLoaded", function() {
    var printOrderId = <?= (int)($_GET['print_order_id'] ?? 0) ?>;
    if (printOrderId > 0) {
        // Automatically open receipt popup for printing
        printReceipt(printOrderId);
    }

    if (window.history && window.history.replaceState) {
        var url = new URL(window.location.href);
        url.searchParams.delete('print_order_id');
        window.history.replaceState({}, document.title, url.toString());
    }
});
</script>
<?php endif; ?>
</main>
</div>
<?php include __DIR__ . '/bakong_modal_partial.php'; ?>
<?php include __DIR__ . '/receipt_modal_partial.php'; ?>
</body>
</html>
