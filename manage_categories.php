<?php
require 'auth.php';
require_once 'config.php';
if (file_exists(__DIR__ . '/cloudinary_config.php')) {
    require_once __DIR__ . '/cloudinary_config.php';
}
if (!can('manage_categories')) { header("Location: dashboard.php?denied=1"); exit; }

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

function cat_slug(string $name): string { return preg_replace('/\s+/', ' ', trim($name)); }
function he($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function getCategorySubtitle($name, $slug): string {
    $map = [
        'iced beverages' => 'ភេសជ្ជៈត្រជាក់គ្រប់ប្រភេទ',
        'soft drink'     => 'ភេសជ្ជៈកំប៉ុង និងដប',
        'soft drinks'    => 'ភេសជ្ជៈកំប៉ុង និងដប',
        'hot beverages'  => 'កាហ្វេ និងតែស្រស់ក្ដៅៗ',
        'coffee'         => 'កាហ្វេស្រស់ឈ្ងុយឆ្ងាញ់',
        'tea'            => 'តែរសជាតិ និងតែបៃតង',
        'bakery'         => 'នំបុ័ង និងនំផ្អែម',
        'snacks'         => 'អាហារសម្រន់ឆ្ងាញ់ៗ',
        'direct drinks'  => 'ភេសជ្ជៈកំប៉ុង និងដបស្រាប់',
        'drinks'         => 'ភេសជ្ជៈគ្រប់ប្រភេទ'
    ];
    $k = strtolower(trim($name));
    if (isset($map[$k])) return $map[$k];
    $s = strtolower(trim($slug));
    if (isset($map[$s])) return $map[$s];
    return current_lang() === 'km' ? 'ភេសជ្ជៈ និងទំនិញក្នុងម៉ឺនុយ' : 'Menu Category';
}

function _ensure_categories_schema($conn) {
    static $done = false;
    if ($done) return;
    $done = true;

    // 1. Ensure table exists
    @$conn->query("CREATE TABLE IF NOT EXISTS categories (
        category_id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(50) NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL,
        description VARCHAR(255) NULL DEFAULT '',
        icon VARCHAR(50) DEFAULT 'fa-circle',
        display_order INT DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1
    ) DEFAULT CHARSET=utf8mb4");

    // 2. Safe cross-version column checks
    $cols = [
        'description'     => "VARCHAR(255) NULL DEFAULT ''",
        'offer_sweetness' => "TINYINT(1) NOT NULL DEFAULT 1",
        'offer_ice'       => "TINYINT(1) NOT NULL DEFAULT 1",
        'offer_milk'      => "TINYINT(1) NOT NULL DEFAULT 1",
        'offer_addons'    => "TINYINT(1) NOT NULL DEFAULT 1",
        'earns_points'    => "TINYINT(1) NOT NULL DEFAULT 1"
    ];
    foreach ($cols as $colName => $colDef) {
        $chk = @$conn->query("SHOW COLUMNS FROM categories LIKE '$colName'");
        if ($chk && $chk->num_rows === 0) {
            @$conn->query("ALTER TABLE categories ADD `$colName` $colDef");
        }
    }
}

function cat_upload_icon(): string {
    if (empty($_FILES['icon']['name'])) return '';
    if (($_FILES['icon']['error'] ?? 1) !== UPLOAD_ERR_OK) return '__UPLOAD_ERR__';
    try {
        if (function_exists('cloudinary_upload_file')) {
            $res = cloudinary_upload_file($_FILES['icon'], 'pos_coffee/categories');
            if (!empty($res['success']) && !empty($res['url'])) {
                return $res['url'];
            }
        }
    } catch (Throwable $t) {
        // Continue to local fallback
    }
    // Local fallback if Cloudinary is not configured or fails
    $ext = strtolower(pathinfo($_FILES['icon']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg', 'gif'], true)) {
        $uploadDir = __DIR__ . '/uploads/categories/';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
        $fileName = 'cat_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (move_uploaded_file($_FILES['icon']['tmp_name'], $uploadDir . $fileName)) {
            return 'uploads/categories/' . $fileName;
        }
    }
    return '__UPLOAD_ERR__';
}

if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
} else {
    $flash = null;
}

// ── POST action router (CSRF-guarded) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    _ensure_categories_schema($conn);
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed. Please retry.'];
    } else {
        try {
            switch ($_POST['action'] ?? '') {
                case 'create': {
                    $name = trim((string)($_POST['name'] ?? ''));
                    $desc = trim((string)($_POST['description'] ?? ''));
                    $icon = cat_upload_icon();
                    if ($icon === '__UPLOAD_ERR__') { $flash = ['type'=>'error','msg'=>'Image upload failed. Please try again.']; break; }
                    if ($icon === '') $icon = 'fa-circle';
                    $active = isset($_POST['is_active']) ? 1 : 0;
                    $slug = cat_slug($name);
                    if ($slug === '') { $flash = ['type'=>'error','msg'=>'Category name is required.']; break; }
                    $dup = $conn->prepare("SELECT category_id FROM categories WHERE LOWER(slug) = LOWER(?) LIMIT 1");
                    if ($dup) {
                        $dup->bind_param('s', $slug); $dup->execute();
                        if ($dup->get_result()->fetch_assoc()) { $flash = ['type'=>'error','msg'=>"A category named \"$slug\" already exists."]; break; }
                    }
                    $ordRow = $conn->query("SELECT COALESCE(MAX(display_order),0)+1 AS n FROM categories");
                    $ord = ($ordRow && ($orow = $ordRow->fetch_assoc())) ? (int)$orow['n'] : 1;
                    $os = isset($_POST['offer_sweetness']) ? 1 : 0;
                    $oi = isset($_POST['offer_ice'])       ? 1 : 0;
                    $om = isset($_POST['offer_milk'])      ? 1 : 0;
                    $oa = isset($_POST['offer_addons'])    ? 1 : 0;
                    $ep = isset($_POST['earns_points'])    ? 1 : 0;
                    
                    $ins = $conn->prepare("INSERT INTO categories (slug, name, description, icon, display_order, is_active, offer_sweetness, offer_ice, offer_milk, offer_addons, earns_points) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if ($ins) {
                        $ins->bind_param('ssssiiiiiii', $slug, $name, $desc, $icon, $ord, $active, $os, $oi, $om, $oa, $ep);
                        $ins->execute();
                    } else {
                        $escSlug = $conn->real_escape_string($slug);
                        $escName = $conn->real_escape_string($name);
                        $escIcon = $conn->real_escape_string($icon);
                        $conn->query("INSERT INTO categories (slug, name, icon, display_order, is_active) VALUES ('$escSlug', '$escName', '$escIcon', $ord, $active)");
                    }
                    $flash = ['type'=>'success','msg'=>"Category \"$slug\" added successfully."];
                    break;
                }
                case 'update': {
                    $id   = (int)($_POST['category_id'] ?? 0);
                    $name = trim((string)($_POST['name'] ?? ''));
                    $desc = trim((string)($_POST['description'] ?? ''));
                    $active = isset($_POST['is_active']) ? 1 : 0;
                    if ($id <= 0 || $name === '') { $flash = ['type'=>'error','msg'=>'Name is required.']; break; }
                    $iconRow = $conn->query("SELECT icon FROM categories WHERE category_id=" . (int)$id)->fetch_assoc();
                    $icon = $iconRow['icon'] ?? 'fa-circle';
                    $newIcon = cat_upload_icon();
                    if ($newIcon === '__UPLOAD_ERR__') { $flash = ['type'=>'error','msg'=>'Image upload failed. Please try again.']; break; }
                    if ($newIcon !== '') {
                        if (!empty($iconRow['icon']) && function_exists('cloudinary_delete_image')) {
                            cloudinary_delete_image($iconRow['icon']);
                        }
                        $icon = $newIcon;
                    }
                    $os = isset($_POST['offer_sweetness']) ? 1 : 0;
                    $oi = isset($_POST['offer_ice'])       ? 1 : 0;
                    $om = isset($_POST['offer_milk'])      ? 1 : 0;
                    $oa = isset($_POST['offer_addons'])    ? 1 : 0;
                    $ep = isset($_POST['earns_points'])    ? 1 : 0;
                    
                    $u = $conn->prepare("UPDATE categories SET name=?, description=?, icon=?, is_active=?, offer_sweetness=?, offer_ice=?, offer_milk=?, offer_addons=?, earns_points=? WHERE category_id=?");
                    if ($u) {
                        $u->bind_param('sssiiiiiii', $name, $desc, $icon, $active, $os, $oi, $om, $oa, $ep, $id);
                        $u->execute();
                    } else {
                        $escName = $conn->real_escape_string($name);
                        $escIcon = $conn->real_escape_string($icon);
                        $conn->query("UPDATE categories SET name='$escName', icon='$escIcon', is_active=$active WHERE category_id=$id");
                    }
                    $flash = ['type'=>'success','msg'=>'Category updated successfully.'];
                    break;
                }
                case 'toggle': {
                    $id = (int)($_POST['category_id'] ?? 0);
                    if ($id > 0) {
                        $conn->query("UPDATE categories SET is_active = 1 - is_active WHERE category_id = " . $id);
                        $res = $conn->query("SELECT is_active FROM categories WHERE category_id = " . $id)->fetch_assoc();
                        $newActive = (int)($res['is_active'] ?? 0);
                        
                        $totalCats    = (int)$conn->query("SELECT COUNT(*) c FROM categories")->fetch_assoc()['c'];
                        $activeCats   = (int)$conn->query("SELECT COUNT(*) c FROM categories WHERE is_active = 1")->fetch_assoc()['c'];
                        $inactiveCats = $totalCats - $activeCats;

                        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_POST['ajax']) && $_POST['ajax'] == '1')) {
                            header('Content-Type: application/json');
                            echo json_encode([
                                'success' => true,
                                'is_active' => $newActive,
                                'active_count' => $activeCats,
                                'inactive_count' => $inactiveCats,
                                'total_count' => $totalCats
                            ]);
                            exit;
                        }
                        $flash = ['type'=>'success','msg'=>'Category visibility updated.'];
                    }
                    break;
                }
                case 'delete': {
                    $id = (int)($_POST['category_id'] ?? 0);
                    if ($id <= 0) { $flash = ['type'=>'error','msg'=>'Invalid category.']; break; }
                    $chk = $conn->prepare("SELECT COUNT(*) AS n FROM products WHERE category_id = ?");
                    if ($chk) {
                        $chk->bind_param('i', $id); $chk->execute();
                        $n = (int)$chk->get_result()->fetch_assoc()['n'];
                        if ($n > 0) { $flash = ['type'=>'error','msg'=>"$n product(s) use this category — reassign them or delete them first."]; break; }
                    }
                    $curCat = $conn->query("SELECT icon FROM categories WHERE category_id=" . (int)$id)->fetch_assoc();
                    if (!empty($curCat['icon']) && function_exists('cloudinary_delete_image')) {
                        cloudinary_delete_image($curCat['icon']);
                    }
                    $d = $conn->prepare("DELETE FROM categories WHERE category_id = ?");
                    if ($d) {
                        $d->bind_param('i', $id); $d->execute();
                    } else {
                        $conn->query("DELETE FROM categories WHERE category_id = " . (int)$id);
                    }
                    $flash = ['type'=>'success','msg'=>'Category deleted.'];
                    break;
                }
                case 'reorder': {
                    $id  = (int)($_POST['category_id'] ?? 0);
                    $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
                    if ($id > 0) {
                        $cur = $conn->query("SELECT category_id, display_order FROM categories WHERE category_id = " . $id)->fetch_assoc();
                        if ($cur) {
                            $cmp = $dir === 'up' ? '<' : '>';
                            $ord = $dir === 'up' ? 'DESC' : 'ASC';
                            $nb = $conn->query("SELECT category_id, display_order FROM categories WHERE display_order $cmp " . (int)$cur['display_order'] . " ORDER BY display_order $ord LIMIT 1")->fetch_assoc();
                            if ($nb) {
                                $a = (int)$cur['display_order']; $b = (int)$nb['display_order'];
                                $ca = (int)$cur['category_id'];  $cb = (int)$nb['category_id'];
                                $conn->query("UPDATE categories SET display_order = $b WHERE category_id = $ca");
                                $conn->query("UPDATE categories SET display_order = $a WHERE category_id = $cb");
                                $flash = ['type'=>'success','msg'=>'Order updated.'];
                            }
                        }
                    }
                    break;
                }
            }
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'msg' => 'Error saving category: ' . $e->getMessage()];
        }
    }

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_POST['ajax']) && $_POST['ajax'] == '1')) {
        header('Content-Type: application/json');
        $totalCats    = (int)$conn->query("SELECT COUNT(*) c FROM categories")->fetch_assoc()['c'];
        $activeCats   = (int)$conn->query("SELECT COUNT(*) c FROM categories WHERE is_active = 1")->fetch_assoc()['c'];
        $inactiveCats = $totalCats - $activeCats;
        echo json_encode([
            'success'        => ($flash['type'] ?? '') === 'success',
            'type'           => $flash['type'] ?? 'success',
            'msg'            => $flash['msg'] ?? '',
            'active_count'   => $activeCats,
            'inactive_count' => $inactiveCats,
            'total_count'    => $totalCats
        ]);
        exit;
    }

    if ($flash) {
        $_SESSION['flash'] = $flash;
    }
    header("Location: manage_categories.php");
    exit;
}

// ── Always ensure database schema is up to date ──
_ensure_categories_schema($conn);

// ── Check if categories table is empty on live host and seed (or force sync via ?sync=1) ──
$catCheck = $conn->query("SELECT COUNT(*) AS c FROM categories");
$catCountInDb = ($catCheck && ($cr = $catCheck->fetch_assoc())) ? (int)$cr['c'] : 0;

if ($catCountInDb === 0 || isset($_GET['sync'])) {
    // 1. Seed standard default categories
    $defaultCats = [
        ['slug' => 'Iced',        'name' => 'Iced Beverages', 'icon' => 'fa-snowflake',  'order' => 1],
        ['slug' => 'Hot',         'name' => 'Hot Beverages',  'icon' => 'fa-mug-hot',    'order' => 2],
        ['slug' => 'Frappe',      'name' => 'Frappes',        'icon' => 'fa-blender',    'order' => 3],
        ['slug' => 'Juice',       'name' => 'Juices',         'icon' => 'fa-lemon',      'order' => 4],
        ['slug' => 'Milk Tea',    'name' => 'Milk Tea',       'icon' => 'fa-circle-dot', 'order' => 5],
        ['slug' => 'Soft Drinks', 'name' => 'Soft Drinks',    'icon' => 'fa-bottle-water','order' => 6],
    ];
    foreach ($defaultCats as $dc) {
        $s = $conn->real_escape_string($dc['slug']);
        $n = $conn->real_escape_string($dc['name']);
        $ic = $conn->real_escape_string($dc['icon']);
        $o = (int)$dc['order'];
        $conn->query("INSERT INTO categories (slug, name, icon, display_order, is_active) VALUES ('$s', '$n', '$ic', $o, 1) ON DUPLICATE KEY UPDATE name='$n'");
    }

    // 2. Import distinct categories from products
    $prodCatsRes = $conn->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND TRIM(category) != ''");
    if ($prodCatsRes) {
        $nextOrder = 10;
        while ($pRow = $prodCatsRes->fetch_assoc()) {
            $pCatName = trim($pRow['category']);
            if ($pCatName === '') continue;
            $escName = $conn->real_escape_string($pCatName);
            $conn->query("INSERT INTO categories (slug, name, icon, display_order, is_active) VALUES ('$escName', '$escName', 'fa-circle', $nextOrder, 1) ON DUPLICATE KEY UPDATE is_active=1");
            $nextOrder++;
        }
    }

    // Link products to categories
    $conn->query("UPDATE products p JOIN categories c ON (c.slug = p.category OR c.name = p.category) SET p.category_id = c.category_id WHERE p.category_id IS NULL OR p.category_id = 0");
}

// ── Load categories with product counts ──
$categories = [];
$res = $conn->query("SELECT * FROM categories ORDER BY display_order ASC, category_id ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $row['description']     = $row['description']     ?? '';
        $row['offer_sweetness'] = (int)($row['offer_sweetness'] ?? 1);
        $row['offer_ice']       = (int)($row['offer_ice']       ?? 1);
        $row['offer_milk']      = (int)($row['offer_milk']      ?? 1);
        $row['offer_addons']    = (int)($row['offer_addons']    ?? 1);
        $row['earns_points']    = (int)($row['earns_points']    ?? 1);
        $row['is_active']       = (int)($row['is_active']       ?? 1);
        $row['display_order']   = (int)($row['display_order']   ?? 0);
        $row['icon']            = !empty($row['icon']) ? $row['icon'] : 'fa-circle';
        $row['product_count']   = 0;
        $categories[] = $row;
    }
}

// Populate product counts for each category
if (!empty($categories)) {
    $counts = [];
    $cRes = @$conn->query("SELECT category, category_id, COUNT(*) AS cnt FROM products GROUP BY category, category_id");
    if ($cRes) {
        while ($cr = $cRes->fetch_assoc()) {
            if (!empty($cr['category_id'])) {
                $k = 'id_' . (int)$cr['category_id'];
                $counts[$k] = ($counts[$k] ?? 0) + (int)$cr['cnt'];
            }
            if (!empty($cr['category'])) {
                $k = 'name_' . strtolower(trim($cr['category']));
                $counts[$k] = ($counts[$k] ?? 0) + (int)$cr['cnt'];
            }
        }
    }
    foreach ($categories as &$c) {
        $cid = (int)$c['category_id'];
        $slug = strtolower(trim($c['slug'] ?? ''));
        $name = strtolower(trim($c['name'] ?? ''));
        $c['product_count'] = $counts['id_' . $cid] ?? $counts['name_' . $slug] ?? $counts['name_' . $name] ?? 0;
    }
    unset($c);
}

$totalCats    = count($categories);
$activeCats   = count(array_filter($categories, fn($c) => (int)$c['is_active'] === 1));
$inactiveCats = $totalCats - $activeCats;
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>" data-lang="<?= current_lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= current_lang() === 'km' ? 'គ្រប់គ្រងប្រភេទទំនិញ | Bird\'s Nest Coffee' : 'Manage Categories | Bird\'s Nest Coffee' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<!-- Cropper.js & Product Cropper Assets -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css">
<link rel="stylesheet" href="assets/css/product_cropper.css">
<script>(function(){try{if(localStorage.getItem("theme")==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
<script src="https://cdn.tailwindcss.com"></script>
<style>
:root, [data-theme="dark"], html:not([data-theme="light"]) {
    --bg-main: #0b0c10;
    --bg-card: #14151e;
    --bg-card-hover: #191a26;
    --border-color: #232433;
    --border-hover: #35364c;
    --text-main: #f8fafc;
    --text-muted: #8e8e9f;
    --accent: #10b981;
    --accent-hover: #059669;
}
[data-theme="light"], html[data-theme="light"] {
    --bg-main: #f8fafc;
    --bg-card: #ffffff;
    --bg-card-hover: #f1f5f9;
    --border-color: #e2e8f0;
    --border-hover: #cbd5e1;
    --text-main: #0f172a;
    --text-muted: #64748b;
    --accent: #10b981;
    --accent-hover: #059669;
}

body, input, select, textarea, button, table {
    font-family: 'Poppins', 'Kantumruy Pro', 'Noto Sans Khmer', -apple-system, BlinkMacSystemFont, sans-serif;
    -webkit-font-smoothing: antialiased;
}
:lang(km), [data-lang="km"], html[lang="km"], html[lang="km"] * {
    font-family: 'Kantumruy Pro', 'Noto Sans Khmer', 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif !important;
}
html[lang="km"] .fa, html[lang="km"] [class*="fa-"], html[lang="km"] i {
    font-family: 'Font Awesome 6 Free', 'FontAwesome' !important;
}
html[lang="km"] .fa-brands, html[lang="km"] [class*="fa-brands"] {
    font-family: 'Font Awesome 6 Brands', 'FontAwesome' !important;
}

/* Light Theme Overrides */
[data-theme="light"] body,
[data-theme="light"] .app-layout,
[data-theme="light"] .app-main,
[data-theme="light"] main {
    background-color: #f8fafc !important;
    color: #0f172a !important;
}
[data-theme="light"] .bg-\[\#14151e\] {
    background-color: #ffffff !important;
    border-color: #e2e8f0 !important;
    color: #0f172a !important;
}
[data-theme="light"] .bg-\[\#1b1c27\] {
    background-color: #f1f5f9 !important;
    border-color: #e2e8f0 !important;
    color: #0f172a !important;
}
[data-theme="light"] .bg-\[\#101118\],
[data-theme="light"] .bg-\[\#101118\]\/50 {
    background-color: #f8fafc !important;
    border-color: #e2e8f0 !important;
}
[data-theme="light"] .text-white {
    color: #0f172a !important;
}
[data-theme="light"] .text-\[\#e2e8f0\] {
    color: #1e293b !important;
}
[data-theme="light"] .text-\[\#8e8e9f\],
[data-theme="light"] .text-\[\#78788c\] {
    color: #64748b !important;
}
[data-theme="light"] .text-\[\#c7c7d4\] {
    color: #334155 !important;
}
[data-theme="light"] .border-\[\#232433\],
[data-theme="light"] .border-\[\#2c2d3e\],
[data-theme="light"] .divide-\[\#1e1f2c\] > :not([hidden]) ~ :not([hidden]) {
    border-color: #f1f5f9 !important;
}
[data-theme="light"] .hover\:bg-\[\#191a26\]:hover,
[data-theme="light"] .hover\:bg-\[\#1e1f2d\]:hover,
[data-theme="light"] .hover\:bg-\[\#252737\]:hover,
[data-theme="light"] .hover\:bg-\[\#202230\]:hover {
    background-color: #f1f5f9 !important;
}
[data-theme="light"] .toast {
    background: #ffffff;
    border-color: #e2e8f0;
    color: #0f172a;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

/* Toast Alert */
#toast-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 999999;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.toast {
    background: #14151e;
    border: 1px solid #28293d;
    border-radius: 16px;
    padding: 12px 18px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 260px;
    transform: translateX(120%);
    opacity: 0;
    transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    font-size: 13.5px;
    color: #f8fafc;
    font-weight: 600;
}
.toast.show {
    transform: translateX(0);
    opacity: 1;
}
.toast.success { border-left: 4px solid #10b981; }
.toast.success i { color: #10b981; }
.toast.error { border-left: 4px solid #ef4444; }
.toast.error i { color: #ef4444; }

/* iOS Toggle Switch */
.ios-switch {
    position: relative;
    display: inline-block;
    width: 48px;
    height: 26px;
    flex-shrink: 0;
    cursor: pointer;
}
.ios-switch input {
    opacity: 0;
    width: 0;
    height: 0;
    position: absolute;
}
.ios-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #222332;
    border: 1px solid #33344a;
    transition: 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    border-radius: 30px;
}
.ios-slider:before {
    position: absolute;
    content: "";
    height: 20px;
    width: 20px;
    left: 2px;
    bottom: 2px;
    background-color: white;
    transition: 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    border-radius: 50%;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.4);
}
.ios-switch input:checked + .ios-slider {
    background-color: #10b981;
    border-color: #10b981;
}
.ios-switch input:checked + .ios-slider:before {
    transform: translateX(22px);
}
</style>
</head>
<body class="bg-[#0b0c10] text-[#e2e8f0] h-screen overflow-hidden antialiased">
<div class="flex h-screen w-screen overflow-hidden">
    <!-- Sidebar -->
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <!-- Main Content -->
    <main class="flex-1 h-full overflow-y-auto p-4 md:p-6 lg:p-7 bg-[#0b0c10]">
        <div class="w-full space-y-5 md:space-y-6">

            <!-- 1. Header Row -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl md:text-[26px] font-black text-white tracking-tight leading-tight">
                        <?= current_lang() === 'km' ? 'គ្រប់គ្រងប្រភេទទំនិញ' : __('manage_categories', 'Manage Categories') ?>
                    </h1>
                    <p class="text-xs text-[#8e8e9f] font-medium mt-0.5">
                        Bird's Nest Coffee &rsaquo; <?= current_lang() === 'km' ? 'កាតាឡុក' : __('catalog', 'Catalog') ?>
                    </p>
                </div>
                <div>
                    <button type="button" 
                            onclick="openAddCategoryModal()" 
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-gradient-to-r from-[#10b981] to-[#059669] hover:from-[#059669] hover:to-[#047857] text-white text-xs md:text-sm font-bold shadow-lg shadow-emerald-500/25 transition-all transform active:scale-95 cursor-pointer">
                        <i class="fa-solid fa-plus text-xs"></i>
                        <span><?= current_lang() === 'km' ? 'បន្ថែមប្រភេទ' : __('add_category', 'Add Category') ?></span>
                    </button>
                </div>
            </div>

            <!-- 2. KPI Stat Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                <!-- Card 1: Total Categories -->
                <div onclick="setCatFilter('all')" 
                     class="vo-stat-box bg-[#14151e] rounded-3xl p-5 md:p-6 border border-[#232433] shadow-lg flex items-center gap-4 transition-all duration-300 hover:shadow-emerald-500/5 hover:-translate-y-1 hover:border-emerald-500/40 cursor-pointer group" 
                     data-filter="all">
                    <div class="w-12 h-12 rounded-2xl bg-sky-500/15 border border-sky-500/30 text-sky-400 flex items-center justify-center text-xl shrink-0 group-hover:scale-105 transition-all duration-200 shadow-sm">
                        <i class="fa-solid fa-layer-group"></i>
                    </div>
                    <div>
                        <span class="block text-xs font-bold text-[#8e8e9f] tracking-wide"><?= current_lang() === 'km' ? 'ប្រភេទទាំងអស់' : __('all_categories', 'All Categories') ?></span>
                        <span class="block text-2xl md:text-3xl font-black text-white leading-tight mt-0.5 tracking-tight" id="statTotalCats"><?= $totalCats ?></span>
                        <span class="block text-[11px] font-medium text-[#8e8e9f] mt-0.5"><?= current_lang() === 'km' ? 'សរុបក្នុងប្រព័ន្ធ' : 'Total in system' ?></span>
                    </div>
                </div>

                <!-- Card 2: Active Categories -->
                <div onclick="setCatFilter('active')" 
                     class="vo-stat-box bg-[#14151e] rounded-3xl p-5 md:p-6 border border-[#232433] shadow-lg flex items-center gap-4 transition-all duration-300 hover:shadow-emerald-500/5 hover:-translate-y-1 hover:border-emerald-500/40 cursor-pointer group" 
                     data-filter="active">
                    <div class="w-12 h-12 rounded-2xl bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 flex items-center justify-center text-xl shrink-0 group-hover:scale-105 transition-all duration-200 shadow-sm">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <div>
                        <span class="block text-xs font-bold text-[#8e8e9f] tracking-wide"><?= current_lang() === 'km' ? 'សកម្ម' : __('active', 'Active') ?></span>
                        <span class="block text-2xl md:text-3xl font-black text-white leading-tight mt-0.5 tracking-tight" id="statActiveCats"><?= $activeCats ?></span>
                        <span class="block text-[11px] font-medium text-[#8e8e9f] mt-0.5"><?= current_lang() === 'km' ? 'បង្ហាញក្នុងម៉ឺនុយ' : 'Visible on menu' ?></span>
                    </div>
                </div>

                <!-- Card 3: Inactive Categories -->
                <div onclick="setCatFilter('inactive')" 
                     class="vo-stat-box bg-[#14151e] rounded-3xl p-5 md:p-6 border border-[#232433] shadow-lg flex items-center gap-4 transition-all duration-300 hover:shadow-rose-500/5 hover:-translate-y-1 hover:border-rose-500/40 cursor-pointer group" 
                     data-filter="inactive">
                    <div class="w-12 h-12 rounded-2xl bg-rose-500/15 border border-rose-500/30 text-rose-400 flex items-center justify-center text-xl shrink-0 group-hover:scale-105 transition-all duration-200 shadow-sm">
                        <i class="fa-solid fa-circle-exclamation"></i>
                    </div>
                    <div>
                        <span class="block text-xs font-bold text-[#8e8e9f] tracking-wide"><?= current_lang() === 'km' ? 'អសកម្ម' : __('inactive', 'Inactive') ?></span>
                        <span class="block text-2xl md:text-3xl font-black text-white leading-tight mt-0.5 tracking-tight" id="statInactiveCats"><?= $inactiveCats ?></span>
                        <span class="block text-[11px] font-medium text-[#8e8e9f] mt-0.5"><?= current_lang() === 'km' ? 'លាក់ពីការពិនិត្យ' : 'Hidden from menu' ?></span>
                    </div>
                </div>
            </div>

            <!-- 3. Main Categories Table Card -->
            <div class="bg-[#14151e] rounded-3xl border border-[#232433] shadow-xl p-5 md:p-6 space-y-5">
                <!-- Search & Actions Bar -->
                <div class="flex flex-col sm:flex-row items-center justify-between gap-3">
                    <div class="relative w-full sm:w-80">
                        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-[#78788c] text-xs"></i>
                        <input type="text" 
                               id="catSearchInput" 
                               oninput="filterCategoriesLive()" 
                               placeholder="<?= current_lang() === 'km' ? 'ស្វែងរកប្រភេទ...' : 'Search categories...' ?>" 
                               class="w-full pl-9 pr-4 py-2.5 rounded-2xl bg-[#1b1c27] border border-[#2c2d3e] text-xs font-semibold text-white placeholder-[#78788c] focus:outline-none focus:border-[#10b981] focus:ring-2 focus:ring-emerald-500/20 shadow-xs transition">
                    </div>
                    <div class="flex items-center gap-2 self-end sm:self-auto">
                        <button type="button" 
                                onclick="toggleSortOrder()" 
                                class="px-4 py-2.5 rounded-2xl bg-[#1b1c27] border border-[#2c2d3e] text-xs font-bold text-[#c7c7d4] hover:text-white hover:bg-[#222332] flex items-center gap-2 shadow-xs transition cursor-pointer">
                            <i class="fa-solid fa-arrow-down-short-wide text-[#78788c] text-xs"></i>
                            <span><?= current_lang() === 'km' ? 'តម្រៀប' : 'Filter / Sort' ?></span>
                        </button>
                    </div>
                </div>

                <!-- Table Container -->
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse" id="categoriesTable">
                        <thead>
                            <tr class="border-b border-[#232433] text-[#8e8e9f] text-xs font-bold uppercase tracking-wider whitespace-nowrap">
                                <th class="py-4 px-4 text-center whitespace-nowrap"><?= current_lang() === 'km' ? 'លេខរៀង' : __('col_no', 'No.') ?></th>
                                <th class="py-4 px-3 text-center whitespace-nowrap"><?= current_lang() === 'km' ? 'រូបភាព' : __('image', 'Image') ?></th>
                                <th class="py-4 px-4 text-left whitespace-nowrap"><?= current_lang() === 'km' ? 'ឈ្មោះប្រភេទ' : __('category_name', 'Category Name') ?></th>
                                <th class="py-4 px-4 text-center whitespace-nowrap"><?= current_lang() === 'km' ? 'ទំនិញសរុប' : __('nav_products', 'Total Products') ?></th>
                                <th class="py-4 px-4 text-center whitespace-nowrap"><?= current_lang() === 'km' ? 'ជម្រើសបន្ថែម' : __('offers', 'Options') ?></th>
                                <th class="py-4 px-4 text-center whitespace-nowrap"><?= current_lang() === 'km' ? 'ស្ថានភាព' : __('active', 'Status') ?></th>
                                <th class="py-4 px-4 text-right whitespace-nowrap"><?= current_lang() === 'km' ? 'សកម្មភាព' : __('actions', 'Actions') ?></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#1e1f2c] text-[#e2e8f0]" id="categoriesTbody">
                            <?php foreach ($categories as $i => $c): ?>
                            <tr class="cat-row hover:bg-[#191a26] transition-colors duration-150 <?= $c['is_active'] ? '' : 'opacity-60 bg-[#101118]/50' ?>" 
                                data-active="<?= (int)$c['is_active'] ?>"
                                data-name="<?= he(strtolower($c['name'])) ?>"
                                data-slug="<?= he(strtolower($c['slug'])) ?>">
                                <!-- No. -->
                                <td class="py-4 px-3 text-center font-black text-xs text-[#78788c]">
                                    <?= sprintf('#%02d', $i + 1) ?>
                                </td>

                                <!-- Image / Icon -->
                                <td class="py-4 px-3 text-center">
                                    <div class="flex items-center justify-center">
                                        <?php $__icon = $c['icon'] ?: 'fa-circle'; ?>
                                        <?php if (str_contains($__icon, '/')): ?>
                                        <div class="w-12 h-12 rounded-2xl bg-[#1b1c27] overflow-hidden border border-[#2c2d3e] shadow-sm flex items-center justify-center p-0.5">
                                            <img src="<?= he($__icon) ?>" alt="<?= he($c['name']) ?>" class="w-full h-full object-cover rounded-[14px]">
                                        </div>
                                        <?php else: ?>
                                        <div class="w-12 h-12 rounded-2xl bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 flex items-center justify-center text-lg shadow-sm">
                                            <i class="fa-solid <?= he($__icon) ?>"></i>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Name & Subtitle -->
                                <td class="py-4 px-4">
                                    <div>
                                        <div class="text-sm font-bold text-white tracking-tight">
                                            <?= he($c['name']) ?>
                                        </div>
                                        <div class="text-xs text-[#8e8e9f] font-normal mt-0.5">
                                            <?= he(!empty($c['description']) ? $c['description'] : getCategorySubtitle($c['name'], $c['slug'])) ?>
                                        </div>
                                    </div>
                                </td>

                                <!-- Total Products Count Badge -->
                                <td class="py-4 px-4 text-center">
                                    <span class="inline-flex items-center px-3 py-1 bg-[#1b1c27] text-[#c7c7d4] font-bold rounded-xl text-xs border border-[#2c2d3e]">
                                        <?= (int)$c['product_count'] ?> <?= current_lang() === 'km' ? 'មុខ' : 'items' ?>
                                    </span>
                                </td>

                                <!-- Customization Offers (Sugar, Ice, etc.) -->
                                <td class="py-4 px-4 text-center">
                                    <div class="inline-flex items-center justify-center gap-1.5 flex-wrap">
                                        <?php if ($c['offer_sweetness']): ?>
                                        <span class="px-2.5 py-1 bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 rounded-xl text-[11px] font-bold shadow-2xs">
                                            Sugar
                                        </span>
                                        <?php endif; ?>

                                        <?php if ($c['offer_ice']): ?>
                                        <span class="px-2.5 py-1 bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 rounded-xl text-[11px] font-bold shadow-2xs">
                                            <?= current_lang() === 'km' ? 'កម្រិតទឹកកក' : 'Ice Level' ?>
                                        </span>
                                        <?php endif; ?>

                                        <?php if (!$c['offer_sweetness'] && !$c['offer_ice']): ?>
                                        <span class="text-[#68687a] text-xs font-medium">-</span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Status (Active / Inactive Toggle) -->
                                <td class="py-4 px-4 text-center">
                                    <button type="button" 
                                            onclick="toggleCategoryActive(<?= (int)$c['category_id'] ?>, this, event)"
                                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold transition-all shadow-sm cursor-pointer <?= $c['is_active'] ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/25' : 'bg-rose-500/15 text-rose-400 border border-rose-500/30 hover:bg-rose-500/25' ?>">
                                        <span class="w-1.5 h-1.5 rounded-full <?= $c['is_active'] ? 'bg-emerald-400' : 'bg-rose-400' ?>"></span>
                                        <span><?= $c['is_active'] ? (current_lang() === 'km' ? 'បើក' : 'Active') : (current_lang() === 'km' ? 'បិទ' : 'Inactive') ?></span>
                                    </button>
                                </td>

                                <!-- Actions (Edit, Delete) -->
                                <td class="py-4 px-4 text-right">
                                    <div class="inline-flex items-center justify-end gap-1.5">
                                        <!-- Edit -->
                                        <button type="button" 
                                                onclick="openEditCategoryModal(<?= htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8') ?>)" 
                                                class="w-8 h-8 rounded-xl bg-[#1b1c27] hover:bg-[#252737] border border-[#2c2d3e] hover:border-emerald-500/40 text-[#b4b4c2] hover:text-white flex items-center justify-center transition-all duration-150 cursor-pointer shadow-xs" 
                                                title="<?= __('edit', 'Edit Category') ?>">
                                            <i class="fa-solid fa-pen text-xs"></i>
                                        </button>

                                        <!-- Delete -->
                                        <?php if ((int)$c['product_count'] > 0): ?>
                                        <button type="button" 
                                                class="w-8 h-8 rounded-xl bg-[#1b1c27]/40 border border-[#232433] text-[#4d4e60] cursor-not-allowed flex items-center justify-center shadow-2xs" 
                                                disabled 
                                                title="Cannot delete: <?= (int)$c['product_count'] ?> product(s) use this category">
                                            <i class="fa-solid fa-trash-can text-xs"></i>
                                        </button>
                                        <?php else: ?>
                                        <form method="POST" class="inline" onsubmit="return deleteCategory(<?= (int)$c['category_id'] ?>, this, event);">
                                            <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="category_id" value="<?= (int)$c['category_id'] ?>">
                                            <button type="submit" 
                                                    class="w-8 h-8 rounded-xl bg-[#1b1c27] hover:bg-rose-500/20 border border-[#2c2d3e] hover:border-rose-500/40 text-[#b4b4c2] hover:text-rose-400 flex items-center justify-center transition-all duration-150 cursor-pointer shadow-xs" 
                                                    title="<?= __('delete', 'Delete Category') ?>">
                                                <i class="fa-solid fa-trash-can text-xs"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 4. Footer Summary -->
                <div class="pt-4 border-t border-[#232433] flex items-center justify-between text-xs text-[#8e8e9f] font-semibold">
                    <div>
                        <?= current_lang() === 'km' ? "បង្ហាញ {$totalCats} នៃ {$totalCats} ប្រភេទ" : "Showing {$totalCats} of {$totalCats} categories" ?>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- ══════════════════════════════════════════════════════════════
     MODAL 1: ADD CATEGORY MODAL
══════════════════════════════════════════════════════════════ -->
<div id="addCategoryModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-sm transition-opacity duration-200 hidden">
    <div class="max-w-md w-full bg-[#14151e] rounded-[28px] shadow-2xl overflow-hidden border border-[#2b2c3e] relative text-white">
        <!-- Modal Header -->
        <div class="px-6 py-5 bg-[#0f1017] border-b border-[#232433] text-white flex items-center justify-between relative">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-2xl bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 flex items-center justify-center text-lg shadow-inner">
                    <i class="fa-solid fa-shapes"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-white tracking-tight"><?= current_lang() === 'km' ? 'បន្ថែមប្រភេទថ្មី' : 'Add New Category' ?></h3>
                    <p class="text-xs text-[#8e8e9f] font-medium mt-0.5"><?= current_lang() === 'km' ? 'បង្កើតប្រភេទមុខទំនិញថ្មី និងជម្រើស' : 'Create a new menu item category & modifiers' ?></p>
                </div>
            </div>
            <button type="button" onclick="closeAddCategoryModal()" class="w-9 h-9 rounded-full bg-white/5 hover:bg-white/10 text-[#8e8e9f] hover:text-white flex items-center justify-center transition cursor-pointer text-sm">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Form -->
        <form method="POST" class="p-6 space-y-4 bg-[#14151e]" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="create">

            <div>
                <label class="block text-xs font-bold text-[#e2e8f0] mb-1.5"><?= current_lang() === 'km' ? 'ឈ្មោះប្រភេទ' : 'Category Name' ?> <span class="text-rose-500">*</span></label>
                <input type="text" 
                       name="name" 
                       id="add_cat_name" 
                       required 
                       placeholder="e.g. Smoothies" 
                       class="w-full px-4 py-3 rounded-2xl bg-[#1b1c27] border border-[#2c2d3e] text-xs md:text-sm font-bold text-white focus:outline-none focus:border-[#10b981] focus:ring-2 focus:ring-emerald-500/20 shadow-xs placeholder-[#68687a]">
            </div>

            <div>
                <label class="block text-xs font-bold text-[#e2e8f0] mb-1.5"><?= current_lang() === 'km' ? 'ឈ្មោះជាភាសាខ្មែរ / ការពិពណ៌នា' : 'Khmer Subtitle / Description' ?></label>
                <input type="text" 
                       name="description" 
                       id="add_cat_desc" 
                       placeholder="e.g. ភេសជ្ជៈក្រឡុក និងទឹកផ្លែឈើ" 
                       class="w-full px-4 py-3 rounded-2xl bg-[#1b1c27] border border-[#2c2d3e] text-xs md:text-sm font-medium text-white focus:outline-none focus:border-[#10b981] focus:ring-2 focus:ring-emerald-500/20 shadow-xs placeholder-[#68687a]">
            </div>

            <div>
                <label class="block text-xs font-bold text-[#e2e8f0] mb-1.5"><?= current_lang() === 'km' ? 'រូបភាព' : 'Category Image / Icon' ?></label>
                <div class="flex items-center gap-3.5 p-3.5 rounded-2xl bg-[#1b1c27] border border-[#2c2d3e] shadow-sm">
                    <div class="w-16 h-16 rounded-2xl bg-[#14151e] border border-[#2c2d3e] flex items-center justify-center text-[#78788c] overflow-hidden shrink-0 cursor-pointer shadow-xs relative group hover:ring-2 hover:ring-emerald-500/40 transition" 
                         onclick="document.getElementById('add_cat_icon').click()" 
                         id="add_cat_icon_box"
                         title="<?= current_lang() === 'km' ? 'ចុចដើម្បីជ្រើសរើសរូបភាព' : 'Click to choose image' ?>">
                        <img id="add_cat_icon_preview" src="" alt="Preview" class="w-full h-full object-cover hidden">
                        <div id="add_cat_icon_placeholder" class="text-xl text-[#78788c] flex flex-col items-center">
                            <i class="fa-solid fa-image"></i>
                        </div>
                        <div class="absolute inset-0 bg-black/60 text-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity text-xs">
                            <i class="fa-solid fa-camera"></i>
                        </div>
                    </div>
                    <div class="flex-1 min-w-0 flex items-center gap-2">
                        <input type="file" name="icon" id="add_cat_icon" accept="image/*" class="hidden">
                        <button type="button" onclick="document.getElementById('add_cat_icon').click()" class="flex-1 py-3 px-4 rounded-xl bg-[#14151e] hover:bg-[#202230] border border-[#2c2d3e] text-emerald-400 font-bold text-xs flex items-center justify-center gap-2 shadow-xs cursor-pointer transition">
                            <i class="fa-solid fa-cloud-arrow-up text-emerald-400 text-xs"></i>
                            <span id="add_cat_file_name" class="truncate text-white"><?= current_lang() === 'km' ? 'ជ្រើសរើសរូបភាព...' : 'Choose Image...' ?></span>
                        </button>
                        <button type="button" id="add_cat_crop_btn" onclick="reCropCurrentImage('add_cat_icon_preview', 'add_cat_icon', 'add_cat_file_name')" class="py-3 px-3 rounded-xl bg-emerald-500/15 border border-emerald-500/30 text-xs font-bold text-emerald-400 hover:bg-emerald-500/25 hidden items-center justify-center gap-1.5 cursor-pointer transition" title="Crop Image">
                            <i class="fa-solid fa-crop-simple text-xs"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Customization Options (Modifiers) -->
            <div class="p-4 rounded-2xl bg-[#1b1c27] border border-[#2c2d3e] shadow-sm space-y-3">
                <div class="text-xs font-bold text-[#e2e8f0]"><?= current_lang() === 'km' ? 'ជម្រើសបន្ថែមសម្រាប់ភេសជ្ជៈ:' : 'Drink Modifiers:' ?></div>
                <div class="grid grid-cols-2 gap-3">
                    <label class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl bg-[#14151e] border border-[#2c2d3e] cursor-pointer font-bold text-xs text-white hover:bg-[#1e1f2d] transition select-none">
                        <input type="checkbox" name="offer_sweetness" checked class="w-4 h-4 rounded text-emerald-500 focus:ring-0 cursor-pointer accent-emerald-500">
                        <span><?= current_lang() === 'km' ? 'កម្រិតស្ករ' : 'Sugar Level' ?></span>
                    </label>
                    <label class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl bg-[#14151e] border border-[#2c2d3e] cursor-pointer font-bold text-xs text-white hover:bg-[#1e1f2d] transition select-none">
                        <input type="checkbox" name="offer_ice" checked class="w-4 h-4 rounded text-emerald-500 focus:ring-0 cursor-pointer accent-emerald-500">
                        <span><?= current_lang() === 'km' ? 'កម្រិតទឹកកក' : 'Ice Level' ?></span>
                    </label>
                </div>
            </div>

            <!-- Active Status (iOS Toggle) -->
            <div class="flex items-center justify-between pt-1">
                <div>
                    <div class="text-xs font-bold text-white"><?= current_lang() === 'km' ? 'បើកដំណើរការ (បង្ហាញក្នុងម៉ឺនុយ)' : 'Active (Visible on menu)' ?></div>
                </div>
                <label class="ios-switch">
                    <input type="checkbox" name="is_active" id="add_is_active" checked>
                    <span class="ios-slider"></span>
                </label>
            </div>

            <!-- Footer -->
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-[#232433]">
                <button type="button" 
                        onclick="closeAddCategoryModal()" 
                        class="px-5 py-2.5 rounded-xl bg-[#1b1c27] hover:bg-[#252737] text-[#c7c7d4] hover:text-white border border-[#2c2d3e] text-xs font-bold transition cursor-pointer">
                    <?= current_lang() === 'km' ? 'បោះបង់' : 'Cancel' ?>
                </button>
                <button type="submit" 
                        class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-[#10b981] to-[#059669] hover:from-[#059669] hover:to-[#047857] text-white text-xs font-bold shadow-lg shadow-emerald-500/25 flex items-center gap-1.5 cursor-pointer transition">
                    <i class="fa-solid fa-check text-xs"></i>
                    <span><?= current_lang() === 'km' ? 'រក្សាទុក' : 'Save Category' ?></span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     MODAL 2: EDIT CATEGORY MODAL
══════════════════════════════════════════════════════════════ -->
<div id="editCategoryModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-sm transition-opacity duration-200 hidden">
    <div class="max-w-md w-full bg-[#14151e] rounded-[28px] shadow-2xl overflow-hidden border border-[#2b2c3e] relative text-white">
        <!-- Modal Header -->
        <div class="px-6 py-5 bg-[#0f1017] border-b border-[#232433] text-white flex items-center justify-between relative">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-2xl bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 flex items-center justify-center text-lg shadow-inner">
                    <i class="fa-solid fa-pen-to-square"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-white tracking-tight"><?= current_lang() === 'km' ? 'កែប្រែប្រភេទ' : 'Edit Category' ?></h3>
                    <p class="text-xs text-[#8e8e9f] font-medium mt-0.5"><?= current_lang() === 'km' ? 'កែសម្រួលព័ត៌មានប្រភេទទំនិញ និងជម្រើស' : 'Update category details & modifiers' ?></p>
                </div>
            </div>
            <button type="button" onclick="closeEditCategoryModal()" class="w-9 h-9 rounded-full bg-white/5 hover:bg-white/10 text-[#8e8e9f] hover:text-white flex items-center justify-center transition cursor-pointer text-sm">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Form -->
        <form method="POST" class="p-6 space-y-4 bg-[#14151e]" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="category_id" id="edit_cat_id">

            <div>
                <label class="block text-xs font-bold text-[#e2e8f0] mb-1.5"><?= current_lang() === 'km' ? 'ឈ្មោះប្រភេទ' : 'Category Name' ?> <span class="text-rose-500">*</span></label>
                <input type="text" 
                       name="name" 
                       id="edit_cat_name" 
                       required 
                       class="w-full px-4 py-3 rounded-2xl bg-[#1b1c27] border border-[#2c2d3e] text-xs md:text-sm font-bold text-white focus:outline-none focus:border-[#10b981] focus:ring-2 focus:ring-emerald-500/20 shadow-xs placeholder-[#68687a]">
            </div>

            <div>
                <label class="block text-xs font-bold text-[#e2e8f0] mb-1.5"><?= current_lang() === 'km' ? 'ឈ្មោះជាភាសាខ្មែរ / ការពិពណ៌នា' : 'Khmer Subtitle / Description' ?></label>
                <input type="text" 
                       name="description" 
                       id="edit_cat_desc" 
                       placeholder="e.g. ភេសជ្ជៈត្រជាក់គ្រប់ប្រភេទ" 
                       class="w-full px-4 py-3 rounded-2xl bg-[#1b1c27] border border-[#2c2d3e] text-xs md:text-sm font-medium text-white focus:outline-none focus:border-[#10b981] focus:ring-2 focus:ring-emerald-500/20 shadow-xs placeholder-[#68687a]">
            </div>

            <div>
                <label class="block text-xs font-bold text-[#e2e8f0] mb-1.5"><?= current_lang() === 'km' ? 'រូបភាព' : 'Category Image / Icon' ?></label>
                <div class="flex items-center gap-3.5 p-3.5 rounded-2xl bg-[#1b1c27] border border-[#2c2d3e] shadow-sm">
                    <div class="w-16 h-16 rounded-2xl bg-[#14151e] border border-[#2c2d3e] flex items-center justify-center text-[#78788c] overflow-hidden shrink-0 cursor-pointer shadow-xs relative group hover:ring-2 hover:ring-emerald-500/40 transition" 
                         onclick="document.getElementById('edit_cat_icon').click()" 
                         id="edit_cat_icon_box"
                         title="<?= current_lang() === 'km' ? 'ចុចដើម្បីប្តូររូបភាព' : 'Click to change image' ?>">
                        <img id="edit_cat_icon_preview" src="" alt="Preview" class="w-full h-full object-cover hidden">
                        <div id="edit_cat_icon_placeholder" class="text-xl text-[#78788c] flex flex-col items-center">
                            <i class="fa-solid fa-image"></i>
                        </div>
                        <div class="absolute inset-0 bg-black/60 text-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity text-xs">
                            <i class="fa-solid fa-camera"></i>
                        </div>
                    </div>
                    <div class="flex-1 min-w-0 flex items-center gap-2">
                        <input type="file" name="icon" id="edit_cat_icon" accept="image/*" class="hidden">
                        <button type="button" onclick="document.getElementById('edit_cat_icon').click()" class="flex-1 py-3 px-4 rounded-xl bg-[#14151e] hover:bg-[#202230] border border-[#2c2d3e] text-emerald-400 font-bold text-xs flex items-center justify-center gap-2 shadow-xs cursor-pointer transition">
                            <i class="fa-solid fa-cloud-arrow-up text-emerald-400 text-xs"></i>
                            <span id="edit_cat_file_name" class="truncate text-white"><?= current_lang() === 'km' ? 'ប្តូររូបភាព...' : 'Change Image...' ?></span>
                        </button>
                        <button type="button" id="edit_cat_crop_btn" onclick="reCropCurrentImage('edit_cat_icon_preview', 'edit_cat_icon', 'edit_cat_file_name')" class="py-3 px-3 rounded-xl bg-emerald-500/15 border border-emerald-500/30 text-xs font-bold text-emerald-400 hover:bg-emerald-500/25 hidden items-center justify-center gap-1.5 cursor-pointer transition" title="Crop Image">
                            <i class="fa-solid fa-crop-simple text-xs"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Customization Options (Modifiers) -->
            <div class="p-4 rounded-2xl bg-[#1b1c27] border border-[#2c2d3e] shadow-sm space-y-3">
                <div class="text-xs font-bold text-[#e2e8f0]"><?= current_lang() === 'km' ? 'ជម្រើសបន្ថែមសម្រាប់ភេសជ្ជៈ:' : 'Drink Modifiers:' ?></div>
                <div class="grid grid-cols-2 gap-3">
                    <label class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl bg-[#14151e] border border-[#2c2d3e] cursor-pointer font-bold text-xs text-white hover:bg-[#1e1f2d] transition select-none">
                        <input type="checkbox" name="offer_sweetness" id="edit_offer_sweetness" class="w-4 h-4 rounded text-emerald-500 focus:ring-0 cursor-pointer accent-emerald-500">
                        <span><?= current_lang() === 'km' ? 'កម្រិតស្ករ' : 'Sugar Level' ?></span>
                    </label>
                    <label class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl bg-[#14151e] border border-[#2c2d3e] cursor-pointer font-bold text-xs text-white hover:bg-[#1e1f2d] transition select-none">
                        <input type="checkbox" name="offer_ice" id="edit_offer_ice" class="w-4 h-4 rounded text-emerald-500 focus:ring-0 cursor-pointer accent-emerald-500">
                        <span><?= current_lang() === 'km' ? 'កម្រិតទឹកកក' : 'Ice Level' ?></span>
                    </label>
                </div>
            </div>

            <!-- Active Status (iOS Toggle) -->
            <div class="flex items-center justify-between pt-1">
                <div>
                    <div class="text-xs font-bold text-white"><?= current_lang() === 'km' ? 'បើកដំណើរការ (បង្ហាញក្នុងម៉ឺនុយ)' : 'Active (Visible on menu)' ?></div>
                </div>
                <label class="ios-switch">
                    <input type="checkbox" name="is_active" id="edit_is_active">
                    <span class="ios-slider"></span>
                </label>
            </div>

            <!-- Footer -->
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-[#232433]">
                <button type="button" 
                        onclick="closeEditCategoryModal()" 
                        class="px-5 py-2.5 rounded-xl bg-[#1b1c27] hover:bg-[#252737] text-[#c7c7d4] hover:text-white border border-[#2c2d3e] text-xs font-bold transition cursor-pointer">
                    <?= current_lang() === 'km' ? 'បោះបង់' : 'Cancel' ?>
                </button>
                <button type="submit" 
                        class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-[#10b981] to-[#059669] hover:from-[#059669] hover:to-[#047857] text-white text-xs font-bold shadow-lg shadow-emerald-500/25 flex items-center gap-1.5 cursor-pointer transition">
                    <i class="fa-solid fa-check text-xs"></i>
                    <span><?= current_lang() === 'km' ? 'រក្សាទុក' : 'Save Changes' ?></span>
                </button>
            </div>
        </form>
    </div>
</div>

<div id="toast-container"></div>

<!-- Cropper.js Scripts -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
<script src="assets/js/product_cropper.js"></script>

<script>
function setupCategoryCropper(inputId, previewId, placeholderId, nameSpanId, cropBtnId) {
    const input = document.getElementById(inputId);
    const prev = document.getElementById(previewId);
    const placeholder = document.getElementById(placeholderId);
    const nameSpan = document.getElementById(nameSpanId);
    const cropBtn = document.getElementById(cropBtnId);

    if (!input) return;

    input.addEventListener('change', function(e) {
        if (!this.files || !this.files[0]) return;
        const file = this.files[0];
        if (file._isCropped) return;

        const reader = new FileReader();
        reader.onload = function(ev) {
            if (typeof openProductCropper === 'function') {
                openProductCropper(ev.target.result, function(blob, dataUrl, croppedFile) {
                    croppedFile._isCropped = true;
                    try {
                        const dt = new DataTransfer();
                        dt.items.add(croppedFile);
                        input.files = dt.files;
                    } catch(err) { console.warn(err); }

                    if (prev) {
                        prev.src = dataUrl;
                        prev.classList.remove('hidden');
                    }
                    if (placeholder) placeholder.classList.add('hidden');
                    if (nameSpan) nameSpan.textContent = croppedFile.name || 'Cropped Image';
                    if (cropBtn) {
                        cropBtn.classList.remove('hidden');
                        cropBtn.classList.add('inline-flex');
                    }
                }, 1);
            } else {
                if (prev) {
                    prev.src = ev.target.result;
                    prev.classList.remove('hidden');
                }
                if (placeholder) placeholder.classList.add('hidden');
                if (nameSpan) nameSpan.textContent = file.name;
                if (cropBtn) {
                    cropBtn.classList.remove('hidden');
                    cropBtn.classList.add('inline-flex');
                }
            }
        };
        reader.readAsDataURL(file);
    });
}

function reCropCurrentImage(previewId, inputId, nameSpanId) {
    const prev = document.getElementById(previewId);
    const input = document.getElementById(inputId);
    const nameSpan = document.getElementById(nameSpanId);
    if (!prev || !prev.src || prev.classList.contains('hidden')) return;

    if (typeof openProductCropper === 'function') {
        openProductCropper(prev.src, function(blob, dataUrl, croppedFile) {
            croppedFile._isCropped = true;
            try {
                const dt = new DataTransfer();
                dt.items.add(croppedFile);
                if (input) input.files = dt.files;
            } catch(err) { console.warn(err); }

            prev.src = dataUrl;
            if (nameSpan) nameSpan.textContent = croppedFile.name || 'Cropped Image';
        }, 1);
    }
}

function openAddCategoryModal() {
    document.getElementById('add_cat_name').value = '';
    document.getElementById('add_cat_desc').value = '';
    document.getElementById('add_cat_icon').value = '';
    document.getElementById('add_cat_file_name').textContent = '<?= current_lang() === "km" ? "ជ្រើសរើសរូបភាព..." : "Choose Image..." ?>';
    const prev = document.getElementById('add_cat_icon_preview');
    const placeholder = document.getElementById('add_cat_icon_placeholder');
    const cropBtn = document.getElementById('add_cat_crop_btn');
    if (prev) { prev.src = ''; prev.classList.add('hidden'); }
    if (placeholder) placeholder.classList.remove('hidden');
    if (cropBtn) { cropBtn.classList.add('hidden'); cropBtn.classList.remove('inline-flex'); }
    document.getElementById('addCategoryModal').classList.remove('hidden');
}

function closeAddCategoryModal() {
    document.getElementById('addCategoryModal').classList.add('hidden');
}

function openEditCategoryModal(catData) {
    document.getElementById('edit_cat_id').value = catData.category_id;
    document.getElementById('edit_cat_name').value = catData.name;
    document.getElementById('edit_cat_desc').value = catData.description || '';
    document.getElementById('edit_cat_icon').value = '';
    document.getElementById('edit_cat_file_name').textContent = '<?= current_lang() === "km" ? "ប្តូររូបភាព..." : "Change Image..." ?>';
    
    const prev = document.getElementById('edit_cat_icon_preview');
    const placeholder = document.getElementById('edit_cat_icon_placeholder');
    const cropBtn = document.getElementById('edit_cat_crop_btn');
    const isImg = catData.icon && catData.icon.indexOf('/') !== -1;
    
    if (isImg) {
        if (prev) { prev.src = catData.icon; prev.classList.remove('hidden'); }
        if (placeholder) placeholder.classList.add('hidden');
        if (cropBtn) { cropBtn.classList.remove('hidden'); cropBtn.classList.add('inline-flex'); }
    } else {
        if (prev) { prev.src = ''; prev.classList.add('hidden'); }
        if (placeholder) placeholder.classList.remove('hidden');
        if (cropBtn) { cropBtn.classList.add('hidden'); cropBtn.classList.remove('inline-flex'); }
    }
    
    document.getElementById('edit_is_active').checked = Number(catData.is_active) === 1;
    document.getElementById('edit_offer_sweetness').checked = Number(catData.offer_sweetness) === 1;
    document.getElementById('edit_offer_ice').checked = Number(catData.offer_ice) === 1;
    
    document.getElementById('editCategoryModal').classList.remove('hidden');
}

function closeEditCategoryModal() {
    document.getElementById('editCategoryModal').classList.add('hidden');
}

function filterCategoriesLive() {
    const q = (document.getElementById('catSearchInput')?.value || '').toLowerCase().trim();
    document.querySelectorAll('#categoriesTbody .cat-row').forEach(row => {
        const name = row.getAttribute('data-name') || '';
        const slug = row.getAttribute('data-slug') || '';
        const match = !q || name.includes(q) || slug.includes(q);
        row.style.display = match ? '' : 'none';
    });
}

function setCatFilter(filter) {
    document.querySelectorAll('.vo-stat-box').forEach(box => {
        const isActive = box.getAttribute('data-filter') === filter;
        box.classList.toggle('ring-2', isActive);
        box.classList.toggle('ring-emerald-500/40', isActive);
    });
    document.querySelectorAll('#categoriesTbody .cat-row').forEach(row => {
        const isRowActive = Number(row.getAttribute('data-active')) === 1;
        const show = filter === 'all' || (filter === 'active' && isRowActive) || (filter === 'inactive' && !isRowActive);
        row.style.display = show ? '' : 'none';
    });
}

async function toggleCategoryActive(catId, btn, ev) {
    if (ev) { ev.preventDefault(); ev.stopPropagation(); }
    btn.disabled = true;
    try {
        const formData = new FormData();
        formData.append('csrf_token', '<?= he($_SESSION['csrf_token']) ?>');
        formData.append('action', 'toggle');
        formData.append('category_id', catId);
        formData.append('ajax', '1');

        const res = await fetch('manage_categories.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        if (data.success) {
            const isAct = data.is_active === 1;
            const kmText = isAct ? 'បើក' : 'បិទ';
            const enText = isAct ? 'Active' : 'Inactive';
            const text = '<?= current_lang() ?>' === 'km' ? kmText : enText;
            
            btn.className = `inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold transition-all shadow-sm cursor-pointer ${isAct ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/25' : 'bg-rose-500/15 text-rose-400 border border-rose-500/30 hover:bg-rose-500/25'}`;
            btn.innerHTML = `<span class="w-1.5 h-1.5 rounded-full ${isAct ? 'bg-emerald-400' : 'bg-rose-400'}"></span><span>${text}</span>`;
            
            const row = btn.closest('tr');
            if (row) {
                row.dataset.active = data.is_active;
                row.setAttribute('data-active', data.is_active);
                row.classList.toggle('opacity-60', !isAct);
                row.classList.toggle('bg-[#101118]/50', !isAct);
            }

            const activeVal = document.getElementById('statActiveCats');
            if (activeVal && data.active_count !== undefined) activeVal.textContent = data.active_count;

            const inactiveVal = document.getElementById('statInactiveCats');
            if (inactiveVal && data.inactive_count !== undefined) inactiveVal.textContent = data.inactive_count;

            showToast(`Category status updated`, 'success');
        } else {
            showToast('Failed to update category', 'error');
        }
    } catch (e) {
        showToast('Network error', 'error');
    } finally {
        btn.disabled = false;
    }
}

function deleteCategory(id, formElement, e) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
    if (!confirm('<?= current_lang() === "km" ? "តើអ្នកពិតជាចង់លុបប្រភេទនេះមែនទេ?" : "Delete this category? This cannot be undone." ?>')) {
        return false;
    }

    const row = formElement.closest('tr');
    const formData = new FormData(formElement);
    formData.append('ajax', '1');

    fetch('manage_categories.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            if (row) {
                row.style.transition = 'all 0.3s ease';
                row.style.opacity = '0';
                row.style.transform = 'scale(0.95)';
                setTimeout(() => row.remove(), 300);
            }
            const totalEl = document.getElementById('statTotalCats');
            const activeEl = document.getElementById('statActiveCats');
            const inactiveEl = document.getElementById('statInactiveCats');
            if (totalEl && data.total_count !== undefined) totalEl.textContent = data.total_count;
            if (activeEl && data.active_count !== undefined) activeEl.textContent = data.active_count;
            if (inactiveEl && data.inactive_count !== undefined) inactiveEl.textContent = data.inactive_count;
            showToast(data.msg || 'Category deleted.', 'success');
        } else {
            showToast(data.msg || 'Error deleting category.', 'error');
        }
    })
    .catch(err => {
        showToast('Error processing delete request.', 'error');
    });

    return false;
}

let sortAsc = true;
function toggleSortOrder() {
    const tbody = document.getElementById('categoriesTbody');
    const rows = Array.from(tbody.querySelectorAll('.cat-row'));
    sortAsc = !sortAsc;
    rows.sort((a, b) => {
        const nameA = a.getAttribute('data-name') || '';
        const nameB = b.getAttribute('data-name') || '';
        return sortAsc ? nameA.localeCompare(nameB) : nameB.localeCompare(nameA);
    });
    rows.forEach(r => tbody.appendChild(r));
    showToast(sortAsc ? 'Sorted A to Z' : 'Sorted Z to A', 'success');
}

function showToast(message, type = 'success') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    const icon = type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation';
    toast.innerHTML = `<i class="fa-solid ${icon}"></i><span>${message}</span>`;
    container.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('show'));
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 350);
    }, 2800);
}

document.addEventListener('DOMContentLoaded', () => {
    setupCategoryCropper('add_cat_icon', 'add_cat_icon_preview', 'add_cat_icon_placeholder', 'add_cat_file_name', 'add_cat_crop_btn');
    setupCategoryCropper('edit_cat_icon', 'edit_cat_icon_preview', 'edit_cat_icon_placeholder', 'edit_cat_file_name', 'edit_cat_crop_btn');

    <?php if ($flash): ?>
    showToast(<?= json_encode($flash['msg']) ?>, <?= json_encode($flash['type']) ?>);
    <?php endif; ?>
});
</script>
</body>
</html>
