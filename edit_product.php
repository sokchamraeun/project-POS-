<?php
require 'admin_only.php';
require 'config.php';

if (empty($_GET['id'])) { header("Location: products.php"); exit; }
$id = (int)$_GET['id'];

$stmt_sel = $conn->prepare("SELECT * FROM products WHERE product_id = ?");
$stmt_sel->bind_param("i", $id);
$stmt_sel->execute();
$product = $stmt_sel->get_result()->fetch_assoc();
if (!$product) { header("Location: products.php"); exit; }

$existingSizes = [];
$qs = $conn->prepare("SELECT size_code,label,price,size_factor,sort_order FROM product_sizes WHERE product_id=? ORDER BY sort_order ASC");
$qs->bind_param("i", $id);
$qs->execute();
$rs = $qs->get_result();
while ($row = $rs->fetch_assoc()) { $existingSizes[$row['size_code']] = $row; }
$hasSizes = !empty($product['has_sizes']) || !empty($existingSizes);

$error   = '';
$success = false;

if (isset($_POST['update_product'])) {
    $name        = trim($_POST['name']        ?? '');
    $description = trim($_POST['description'] ?? '');
    $price       = round((float)($_POST['price'] ?? 0), 2);
    $cost_price  = round((float)($_POST['cost_price'] ?? 0), 2);
    $category    = $_POST['category']    ?? '';
    $is_avail    = isset($_POST['is_available']) ? 1 : 0;
    $badge_text  = substr(trim($_POST['badge_text'] ?? ''), 0, 40) ?: null;
    $promo_percent = max(0, min(100, (int)($_POST['promo_percent'] ?? 0)));

    $cat_r = $conn->prepare("SELECT category_id FROM categories WHERE slug = ? LIMIT 1");
    $cat_r->bind_param("s", $category); $cat_r->execute();
    $category_id = ($cat_r->get_result()->fetch_assoc())['category_id'] ?? null;

    if ($name === '' || $price < 0 || $category === '') {
        $error = "Please fill in all required fields.";
    } elseif (!empty($_FILES['image']['name'])) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $allowedExts = ['jpg','jpeg','png','gif','webp','svg','bmp','ico','tiff','tif','avif','heic','heif','jfif','pjpeg','pjp','apng','cur','dng'];
        $isImageMime = false;
        if (function_exists('finfo_open') && !empty($_FILES['image']['tmp_name'])) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $_FILES['image']['tmp_name']);
            finfo_close($finfo);
            $isImageMime = (strpos($mime, 'image/') === 0);
        }
        if (!in_array($ext, $allowedExts) && !$isImageMime) {
            $error = "Invalid file type. Please upload a valid image file.";
        } else {
            $upload_dir = "uploads/";
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $image_name = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $image_path = $upload_dir . $image_name;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $image_path)) {
                if (!empty($product['image']) && file_exists($product['image'])) unlink($product['image']);
                $stmt = $conn->prepare("UPDATE products SET name=?,description=?,price=?,cost_price=?,category=?,category_id=?,image=?,is_available=?,badge_text=?,promo_percent=? WHERE product_id=?");
                $stmt->bind_param("ssddsisisii", $name, $description, $price, $cost_price, $category, $category_id, $image_path, $is_avail, $badge_text, $promo_percent, $id);
                if ($stmt->execute()) { $success = true; $product['image'] = $image_path; }
                else $error = "Database error while updating product.";
            } else {
                $error = "Failed to upload image.";
            }
        }
    } else {
        $stmt = $conn->prepare("UPDATE products SET name=?,description=?,price=?,cost_price=?,category=?,category_id=?,is_available=?,badge_text=?,promo_percent=? WHERE product_id=?");
        $stmt->bind_param("ssddsiisii", $name, $description, $price, $cost_price, $category, $category_id, $is_avail, $badge_text, $promo_percent, $id);
        if ($stmt->execute()) $success = true;
        else $error = "Database error while updating product.";
    }

    if ($success) {
        $product['name']         = $name;
        $product['description']  = $description;
        $product['price']        = $price;
        $product['cost_price']   = $cost_price;
        $product['category']     = $category;
        $product['is_available'] = $is_avail;
        $product['badge_text']   = $badge_text ?: null;
        $product['promo_percent'] = $promo_percent;

        // ── Sizes: toggle + upsert S/M/L rows, sync products.price to Medium ──
        $has_sizes = isset($_POST['has_sizes']) ? 1 : 0;
        $conn->query("UPDATE products SET has_sizes=" . (int)$has_sizes . " WHERE product_id=" . (int)$id);

        if ($has_sizes) {
            $codes   = $_POST['size_code']   ?? [];
            $labels  = $_POST['size_label']  ?? [];
            $prices  = $_POST['size_price']  ?? [];
            $factors = $_POST['size_factor'] ?? [];
            $sorts   = $_POST['size_sort']   ?? [];
            // Replace set: drop existing rows, re-insert only sizes with a price (blank = size not offered / removed)
            $conn->query("DELETE FROM product_sizes WHERE product_id = " . (int)$id);
            $up = $conn->prepare("INSERT INTO product_sizes (product_id,size_code,label,price,size_factor,sort_order)
                VALUES (?,?,?,?,?,?)");
            $mediumPrice = null;
            $fallbackPrice = null;
            foreach ($codes as $i => $code) {
                $sizePrice = (float)($prices[$i] ?? 0);
                if ($sizePrice <= 0) continue; // skip blank rows
                $sizeLabel  = trim((string)($labels[$i] ?? $code));
                $sizeFactor = (float)($factors[$i] ?? 1.0);
                $sizeSort   = (int)($sorts[$i] ?? 0);
                $up->bind_param("issddi", $id, $code, $sizeLabel, $sizePrice, $sizeFactor, $sizeSort);
                $up->execute();
                if ($code === 'M') $mediumPrice = $sizePrice;
                if ($fallbackPrice === null) $fallbackPrice = $sizePrice; // first surviving size (form order S→M→L)
            }
            // Keep products.price synced for legacy single-price paths: Medium if offered, else the first available size (e.g. Large-only)
            $basePrice = $mediumPrice ?? $fallbackPrice;
            if ($basePrice !== null) {
                $sp = $conn->prepare("UPDATE products SET price=? WHERE product_id=?");
                $sp->bind_param("di", $basePrice, $id);
                $sp->execute();
                $product['price'] = $basePrice;
            } else {
                // "has sizes" was checked but every row left blank → not actually sized; keep the base Price field
                $conn->query("UPDATE products SET has_sizes=0 WHERE product_id=" . (int)$id);
                $has_sizes = 0;
            }
        } else {
            // Sizes turned off: clear leftover rows so menu/cart never see stale sizes
            $conn->query("DELETE FROM product_sizes WHERE product_id = " . (int)$id);
        }

        // Refresh prefill state to reflect what was just saved
        $existingSizes = [];
        $qs2 = $conn->prepare("SELECT size_code,label,price,size_factor,sort_order FROM product_sizes WHERE product_id=? ORDER BY sort_order ASC");
        $qs2->bind_param("i", $id);
        $qs2->execute();
        $rs2 = $qs2->get_result();
        while ($row2 = $rs2->fetch_assoc()) { $existingSizes[$row2['size_code']] = $row2; }
        $hasSizes = (bool)$has_sizes;

        // ── Add-on assignments: replace set (empty when the "has add-ons" toggle is off) ──
        $conn->query("DELETE FROM product_addons WHERE product_id = " . (int)$id);
        $addonIds = isset($_POST['has_addons'])
            ? array_values(array_unique(array_map('intval', $_POST['addon_id'] ?? [])))
            : [];
        if ($addonIds) {
            $pa = $conn->prepare("INSERT IGNORE INTO product_addons (product_id, addon_id) VALUES (?, ?)");
            foreach ($addonIds as $aid) {
                if ($aid > 0) { $pa->bind_param('ii', $id, $aid); $pa->execute(); }
            }
        }
        // ── Recipe Ingredients: inline save ──
        $conn->query("DELETE FROM product_ingredients WHERE product_id = " . (int)$id);
        if (!empty($_POST['recipe_ingredient_id']) && is_array($_POST['recipe_ingredient_id'])) {
            $pi_stmt = $conn->prepare("INSERT INTO product_ingredients (product_id, ingredient_id, amount_used) VALUES (?, ?, ?)");
            foreach ($_POST['recipe_ingredient_id'] as $idx => $r_ing_id) {
                $r_ing_id = (int)$r_ing_id;
                $r_amt    = (float)($_POST['recipe_amount_used'][$idx] ?? 0);
                if ($r_ing_id > 0 && $r_amt > 0) {
                    $pi_stmt->bind_param("iid", $id, $r_ing_id, $r_amt);
                    $pi_stmt->execute();
                }
            }
        }

        // Auto-recalculate authoritative cost_price from product_ingredients
        $cogsQ = $conn->prepare("SELECT SUM(pi.amount_used * i.cost_per_unit) AS cogs FROM product_ingredients pi JOIN ingredients i ON pi.ingredient_id = i.ingredient_id WHERE pi.product_id = ?");
        $cogsQ->bind_param("i", $id);
        $cogsQ->execute();
        $calcCogs = (float)($cogsQ->get_result()->fetch_assoc()['cogs'] ?? 0);
        if ($calcCogs > 0) {
            $upCogs = $conn->prepare("UPDATE products SET cost_price = ? WHERE product_id = ?");
            $upCogs->bind_param("di", $calcCogs, $id);
            $upCogs->execute();
            $product['cost_price'] = $calcCogs;
        }
    }
}

$cats = [];
$_cat_r = $conn->query("SELECT slug, name FROM categories WHERE is_active = 1 ORDER BY display_order");
while ($_c = $_cat_r->fetch_assoc()) $cats[$_c['slug']] = $_c['name'];

$allAddons = [];
$__ar = $conn->query("SELECT id, name, price FROM addons WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
while ($__a = $__ar->fetch_assoc()) $allAddons[] = $__a;

$assignedAddons = [];
$__aa = $conn->prepare("SELECT addon_id FROM product_addons WHERE product_id = ?");
$__aa->bind_param('i', $id);
$__aa->execute();
$__ar2 = $__aa->get_result();
while ($__r = $__ar2->fetch_assoc()) $assignedAddons[(int)$__r['addon_id']] = true;
$hasAddons = !empty($assignedAddons);

$productIngredients = [];
$pi_q = $conn->prepare("
    SELECT pi.ingredient_id, pi.amount_used, i.ingredient_name, i.unit, i.stock_quantity
    FROM product_ingredients pi
    JOIN ingredients i ON pi.ingredient_id = i.ingredient_id
    WHERE pi.product_id = ?
    ORDER BY i.ingredient_name ASC
");
$pi_q->bind_param("i", $id);
$pi_q->execute();
$pi_res = $pi_q->get_result();
while ($r = $pi_res->fetch_assoc()) {
    $productIngredients[] = $r;
}

$allIngredients = [];
$_ig_q = $conn->query("SELECT ingredient_id, ingredient_name, unit, stock_quantity, cost_per_unit FROM ingredients ORDER BY ingredient_name ASC");
while ($r = $_ig_q->fetch_assoc()) {
    $allIngredients[] = $r;
}
$allIngMap = [];
foreach ($allIngredients as $aIng) $allIngMap[$aIng['ingredient_id']] = $aIng;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>(function(){if(localStorage.getItem('theme')==='light')document.documentElement.setAttribute('data-theme','light');})();</script>
<title>Edit — <?= htmlspecialchars($product['name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.tailwindcss.com"></script>
<style>
:root {
    --bg:      #0c0c0c;
    --surface: #111;
    --card:    #161616;
    --border:  #222;
    --accent:  #d1904b;
    --accent2: #b57b3b;
    --text:    #f0f0f0;
    --muted:   #888;
    --success: #3ecf70;
    --danger:  #ff4d4d;
    --radius:  14px;
}
[data-theme="light"], html[data-theme="light"] {
    --bg:      #f4efe9 !important;
    --surface: #ede8e0 !important;
    --card:    #ffffff !important;
    --border:  #e0d4c4 !important;
    --text:    #1a1410 !important;
    --muted:   #5a4a3a !important;
}

/* Light Mode Backdrop Blur */
[data-theme="light"] .fixed.inset-0 {
    background-color: rgba(0,0,0,0.2) !important;
    backdrop-filter: blur(4px) !important;
    -webkit-backdrop-filter: blur(4px) !important;
}
html[data-theme="light"] body,
html:not([data-theme="dark"]) body {
    background: var(--bg) !important;
    color: var(--text) !important;
}
html[data-theme="light"] .modal-dialog,
html:not([data-theme="dark"]) .modal-dialog,
html[data-theme="light"] div.bg-\[\#121215\],
html:not([data-theme="dark"]) div.bg-\[\#121215\] {
    background: #ffffff !important;
    border-color: #e0d4c4 !important;
    color: #1a1410 !important;
}
html[data-theme="light"] div.bg-\[\#18181c\]\/90,
html:not([data-theme="dark"]) div.bg-\[\#18181c\]\/90 {
    background: rgba(248, 245, 240, 0.95) !important;
    border-bottom-color: #e0d4c4 !important;
}
html[data-theme="light"] h2,
html:not([data-theme="dark"]) h2,
html[data-theme="light"] h2.text-white,
html:not([data-theme="dark"]) h2.text-white,
html[data-theme="light"] h3,
html:not([data-theme="dark"]) h3 {
    color: #1a1410 !important;
}
html[data-theme="light"] a.bg-\[\#22222a\],
html:not([data-theme="dark"]) a.bg-\[\#22222a\] {
    background: #ede8e0 !important;
    color: #5a4a3a !important;
}
html[data-theme="light"] a.bg-\[\#22222a\]:hover,
html:not([data-theme="dark"]) a.bg-\[\#22222a\]:hover {
    background: #fee2e2 !important;
    color: #dc2626 !important;
}
html[data-theme="light"] input[type=text],
html:not([data-theme="dark"]) input[type=text],
html[data-theme="light"] input[type=number],
html:not([data-theme="dark"]) input[type=number],
html[data-theme="light"] textarea,
html:not([data-theme="dark"]) textarea,
html[data-theme="light"] select,
html:not([data-theme="dark"]) select {
    background: #ede8e0 !important;
    color: #1a1410 !important;
    border-color: #e0d4c4 !important;
}
html[data-theme="light"] select.cat-select,
html:not([data-theme="dark"]) select.cat-select {
    background-color: #f4efe9 !important;
    color: #1a1410 !important;
    border-color: #d1904b !important;
}
html[data-theme="light"] select option,
html:not([data-theme="dark"]) select option {
    background: #ffffff !important;
    color: #1a1410 !important;
}
html[data-theme="light"] .toggle-row,
html:not([data-theme="dark"]) .toggle-row {
    background: #f4efe9 !important;
    border-color: #e0d4c4 !important;
}
html[data-theme="light"] .toggle-info h4,
html:not([data-theme="dark"]) .toggle-info h4 {
    color: #1a1410 !important;
}
html[data-theme="light"] .toggle-info p,
html:not([data-theme="dark"]) .toggle-info p {
    color: #7a6a5d !important;
}
html[data-theme="light"] .toggle-track,
html:not([data-theme="dark"]) .toggle-track {
    background: #d0c5b5 !important;
}
html[data-theme="light"] .toggle-track::after,
html:not([data-theme="dark"]) .toggle-track::after {
    background: #ffffff !important;
}
html[data-theme="light"] .no-image,
html:not([data-theme="dark"]) .no-image {
    background: #f4efe9 !important;
    border-color: #d0c5b5 !important;
    color: #7a6a5d !important;
}
html[data-theme="light"] .img-preview-wrap,
html:not([data-theme="dark"]) .img-preview-wrap {
    background: #f4efe9 !important;
    border-color: #e0d4c4 !important;
}
html[data-theme="light"] .img-file-info,
html:not([data-theme="dark"]) .img-file-info {
    background: #f4efe9 !important;
    border-color: #e0d4c4 !important;
}
html[data-theme="light"] .img-file-info span,
html:not([data-theme="dark"]) .img-file-info span {
    color: #b8732d !important;
}
html[data-theme="light"] label.flabel,
html:not([data-theme="dark"]) label.flabel {
    color: #b8732d !important;
}
html[data-theme="light"] .addon-chip,
html:not([data-theme="dark"]) .addon-chip {
    background: #f4efe9 !important;
    border-color: #e0d4c4 !important;
    color: #5a4a3a !important;
}
html[data-theme="light"] .addon-chip.on,
html:not([data-theme="dark"]) .addon-chip.on {
    background: rgba(209,144,75,.15) !important;
    border-color: #d1904b !important;
    color: #b8732d !important;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: Poppins, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    font-size: 14px;
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
.back-btn {
    display: flex; align-items: center; gap: 7px;
    color: #d1904b; text-decoration: none;
    font-weight: 600; font-size: 13px;
    padding: 7px 14px;
    border: 1px solid rgba(209,144,75,.35);
    border-radius: 10px;
    background: rgba(209,144,75,.08);
    transition: background .2s, border-color .2s;
}
.back-btn:hover { background: rgba(209,144,75,.16); border-color: #d1904b; }
.breadcrumb {
    font-size: 12px; color: var(--muted);
    display: flex; align-items: center; gap: 6px;
}
.breadcrumb span { color: var(--text); font-weight: 500; }
.avail-pill {
    margin-left: auto;
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; font-weight: 600;
    padding: 5px 12px; border-radius: 20px;
}
.avail-pill.on  { background: rgba(62,207,112,.12); color: var(--success); border: 1px solid rgba(62,207,112,.25); }
.avail-pill.off { background: rgba(255,77,77,.12);  color: var(--danger);  border: 1px solid rgba(255,77,77,.25); }

/* ── LAYOUT ── */
.page-wrap {
    max-width: 100%;
    margin: 0 auto;
    padding: 0;
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 20px;
    align-items: start;
}
@media (max-width: 768px) {
    .page-wrap { grid-template-columns: 1fr; }
}
.image-panel { position: relative; top: 0; }
@keyframes scaleUp {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}
.animate-scaleUp { animation: scaleUp 0.25s cubic-bezier(0.16, 1, 0.3, 1) both; }

/* ── IMAGE PANEL ── */
.image-panel {
    display: flex; flex-direction: column; gap: 8px;
    position: relative;
}
.img-preview-wrap {
    height: 180px;
    width: 100%;
    border-radius: var(--radius);
    overflow: hidden;
    background: #141414;
    border: 1px solid var(--border);
    position: relative;
    cursor: pointer;
}
.img-preview-wrap img {
    width: 100%; height: 100%;
    object-fit: cover;
    display: block;
    transition: transform .3s;
}
.img-preview-wrap:hover img { transform: scale(1.03); }
.img-overlay {
    position: absolute; inset: 0;
    background: rgba(0,0,0,.55);
    display: flex; flex-direction: column;
    align-items: center; justify-content: center; gap: 8px;
    opacity: 0; transition: opacity .25s;
    color: #fff; font-size: 13px; font-weight: 600;
}
.img-overlay i { font-size: 28px; color: var(--accent); }
.img-preview-wrap:hover .img-overlay { opacity: 1; }
.img-preview-wrap.drag-over { border-color: var(--accent); }
.img-preview-wrap.drag-over .img-overlay { opacity: 1; }

/* badge overlaid on the image */
.img-badge-overlay {
    position: absolute;
    bottom: 12px;
    left: 12px;
    z-index: 20;
    pointer-events: none;
    width: 72px;
    height: 72px;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 12px;
    clip-path: polygon(
        50% 0%, 59.6% 14.3%, 75% 6.7%, 76.2% 23.8%, 93.3% 25%, 85.7% 40.4%,
        100% 50%, 85.7% 59.6%, 93.3% 75%, 76.2% 76.2%, 75% 93.3%, 59.6% 85.7%,
        50% 100%, 40.4% 85.7%, 25% 93.3%, 23.8% 76.2%, 6.7% 75%, 14.3% 59.6%,
        0% 50%, 14.3% 40.4%, 6.7% 25%, 23.8% 23.8%, 25% 6.7%, 40.4% 14.3%
    );
    font-size: 11px;
    font-weight: 800;
    line-height: 1.3;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    background: linear-gradient(135deg, #a81e1e 0%, #e74c3c 50%, #a81e1e 100%);
    color: #fff;
    text-shadow: 0 1px 3px rgba(0,0,0,0.45);
    filter: drop-shadow(0 3px 10px rgba(231,76,60,0.75));
    word-break: break-word;
}
.no-image {
    aspect-ratio: 1;
    border-radius: var(--radius);
    background: #141414;
    border: 2px dashed #2a2a2a;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center; gap: 10px;
    color: var(--muted); font-size: 13px;
    cursor: pointer; transition: border-color .2s;
}
.no-image:hover, .no-image.drag-over { border-color: var(--accent); color: var(--accent); }
.no-image i { font-size: 32px; }
.img-file-info {
    font-size: 11px; color: var(--muted); text-align: center;
    padding: 8px 12px;
    background: #111; border: 1px solid #1e1e1e;
    border-radius: 10px;
}
.img-file-info span { color: var(--accent); font-weight: 500; }

/* live product card preview */
.product-preview {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px;
}
.product-preview .pp-label {
    font-size: 10px; text-transform: uppercase; letter-spacing: .6px;
    color: var(--muted); margin-bottom: 10px;
}
.pp-name {
    font-size: 16px; font-weight: 700;
    color: var(--text); margin-bottom: 4px;
    word-break: break-word;
}
.pp-meta {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}
.pp-price {
    font-size: 18px; font-weight: 700; color: var(--accent);
}
.pp-cat {
    font-size: 11px; color: var(--muted);
    background: #1e1e1e; border: 1px solid #2a2a2a;
    padding: 2px 9px; border-radius: 20px;
}

/* ── FORM PANEL ── */
.form-panel { display: flex; flex-direction: column; gap: 18px; }

.section-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
}
.section-head {
    padding: 15px 20px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 10px;
}
.section-head i { color: var(--accent); font-size: 14px; }
.section-head h3 { font-size: 14px; font-weight: 600; }
.section-body { padding: 20px; display: flex; flex-direction: column; gap: 16px; }

/* ── FIELDS ── */
.field { display: flex; flex-direction: column; gap: 7px; }
label.flabel {
    font-size: 12px; font-weight: 500; color: #c0a070; letter-spacing: .2px;
}
.input-wrap { position: relative; }
.input-wrap .prefix {
    position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
    color: var(--muted); font-size: 14px; pointer-events: none;
}
input[type=text], input[type=number], textarea, select {
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
input[type=number].has-prefix { padding-left: 28px; }
input:focus, textarea:focus, select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(209,144,75,.12);
}
textarea { resize: vertical; min-height: 90px; }
select { cursor: pointer; }
select option { background: #1a1a1a; }
.char-count { font-size: 11px; color: var(--muted); text-align: right; }
.char-count.warn { color: var(--accent); }

/* category select box */
select.cat-select {
    width: 100%;
    padding: 12px 16px;
    border-radius: 12px;
    border: 1px solid var(--accent, #d1904b);
    background: #0f0f12 url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23d1904b' viewBox='0 0 16 16'><path d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/></svg>") no-repeat calc(100% - 16px) center;
    color: var(--text, #f0f0f0);
    font-family: Poppins, sans-serif;
    font-size: 14px;
    font-weight: 600;
    appearance: none;
    -webkit-appearance: none;
    cursor: pointer;
    transition: all .2s ease;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
}
select.cat-select:focus {
    outline: none;
    border-color: #e8b87a;
    box-shadow: 0 0 0 3px rgba(209,144,75,0.3);
}
select.cat-select option {
    background: #18181c;
    color: #ffffff;
    padding: 10px;
    font-weight: 500;
}
input[name=category] { display: none; }

/* availability toggle */
.toggle-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px;
    background: #0f0f0f;
    border: 1px solid var(--border);
    border-radius: 12px;
}
.toggle-info h4 { font-size: 14px; font-weight: 600; }
.toggle-info p { font-size: 12px; color: var(--muted); margin-top: 2px; }
.toggle-switch { position: relative; width: 46px; height: 26px; flex-shrink: 0; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-track {
    position: absolute; inset: 0;
    border-radius: 13px;
    background: #2a2a2a;
    cursor: pointer;
    transition: background .25s;
}
.toggle-track::after {
    content: '';
    position: absolute;
    left: 3px; top: 3px;
    width: 20px; height: 20px;
    border-radius: 50%;
    background: #555;
    transition: transform .25s, background .25s;
}
.toggle-switch input:checked + .toggle-track { background: rgba(62,207,112,.3); }
.toggle-switch input:checked + .toggle-track::after { transform: translateX(20px); background: var(--success); }

/* ── ERROR / TOAST ── */
.error-bar {
    display: flex; align-items: center; gap: 10px;
    background: rgba(255,77,77,.08);
    border: 1px solid rgba(255,77,77,.3);
    color: var(--danger);
    padding: 12px 16px; border-radius: 12px;
    font-size: 13px;
}
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

/* ── SAVE BTN ── */
.btn-save {
    width: 100%; padding: 14px;
    border: none; border-radius: var(--radius);
    background: linear-gradient(135deg, var(--accent2), var(--accent));
    color: #000; font-size: 15px; font-weight: 700;
    cursor: pointer; display: flex; align-items: center;
    justify-content: center; gap: 8px;
    transition: opacity .2s, transform .15s;
    font-family: Poppins, sans-serif;
}
.btn-save:hover { opacity: .9; transform: translateY(-1px); }
.btn-save:active { transform: translateY(0); }

/* ── BADGE ── */
.product-badge {
    display: inline-flex;
    width: 76px;
    height: 76px;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 14px;
    clip-path: polygon(
        50% 0%, 59.6% 14.3%, 75% 6.7%, 76.2% 23.8%, 93.3% 25%, 85.7% 40.4%,
        100% 50%, 85.7% 59.6%, 93.3% 75%, 76.2% 76.2%, 75% 93.3%, 59.6% 85.7%,
        50% 100%, 40.4% 85.7%, 25% 93.3%, 23.8% 76.2%, 6.7% 75%, 14.3% 59.6%,
        0% 50%, 14.3% 40.4%, 6.7% 25%, 23.8% 23.8%, 25% 6.7%, 40.4% 14.3%
    );
    font-size: 11px;
    font-weight: 800;
    line-height: 1.3;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    background: linear-gradient(135deg, #a81e1e 0%, #e74c3c 50%, #a81e1e 100%);
    color: #fff;
    text-shadow: 0 1px 3px rgba(0,0,0,0.45);
    filter: drop-shadow(0 3px 10px rgba(231,76,60,0.75));
    word-break: break-word;
}
.badge-preview-row {
    display: flex; align-items: center; gap: 10px;
    min-height: 28px;
}
.badge-clear-btn {
    background: none; border: none; color: var(--muted);
    cursor: pointer; font-size: 13px; padding: 0;
    transition: color .2s;
}
.badge-clear-btn:hover { color: var(--danger); }

/* ── ANIMATIONS ── */
@keyframes fadeDown  { from{opacity:0;transform:translateY(-16px)} to{opacity:1;transform:translateY(0)} }
@keyframes slideLeft { from{opacity:0;transform:translateX(-24px)} to{opacity:1;transform:translateX(0)} }
@keyframes slideRight{ from{opacity:0;transform:translateX(24px)}  to{opacity:1;transform:translateX(0)} }
@keyframes fadeUp    { from{opacity:0;transform:translateY(20px)}  to{opacity:1;transform:translateY(0)} }
@keyframes shimmer   { 0%{background-position:-200% center} 100%{background-position:200% center} }
@keyframes floatA    { 0%,100%{transform:translate(0,0)} 50%{transform:translate(-24px,18px)} }
@keyframes floatB    { 0%,100%{transform:translate(0,0)} 50%{transform:translate(18px,-24px)} }

.topnav      { animation: fadeDown  .45s ease both; }
.image-panel { animation: slideLeft .5s  .1s ease both; }
.form-panel  { animation: slideRight .5s .15s ease both; }

.form-panel .section-card:nth-child(1) { animation: fadeUp .4s .2s  ease both; }
.form-panel .section-card:nth-child(2) { animation: fadeUp .4s .28s ease both; }
.form-panel .section-card:nth-child(3) { animation: fadeUp .4s .36s ease both; }
.form-panel .btn-save                  { animation: fadeUp .4s .44s ease both; }
.product-preview { animation: fadeUp .4s .28s ease both; }

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

.addon-chip { display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:50px;border:1px solid var(--border);background:#0f0f0f;color:var(--text);cursor:pointer;font-size:13px; }
.addon-chip.on { border-color:var(--accent); background:rgba(209,144,75,.12); color:var(--accent); }
</style>
</head>
<body>
<div class="flex h-screen w-screen overflow-hidden app-layout">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<main class="app-main flex-1 h-full overflow-y-auto p-4 md:p-6 relative">
<div class="orb orb-a"></div>
<div class="orb orb-b"></div>

<!-- EDIT PRODUCT MODAL BACKDROP -->
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 md:p-6 bg-black/75 backdrop-blur-md overflow-y-auto">
    <!-- MODAL DIALOG CONTAINER -->
    <div class="relative w-full max-w-4xl max-h-[92vh] bg-[#121215] border border-[#24242b] rounded-2xl shadow-2xl flex flex-col overflow-hidden text-white my-auto animate-scaleUp">
        
        <!-- MODAL HEADER -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-[#24242b] bg-[#18181c]/90 backdrop-blur-md shrink-0">
            <h2 class="text-lg font-bold text-white leading-tight">
                Edit product <span id="nav-name"><?= htmlspecialchars($product['name']) ?></span>
            </h2>
            
            <a href="products.php" class="w-9 h-9 rounded-xl bg-[#22222a] text-[#888] hover:text-white hover:bg-red-500/20 hover:text-red-400 flex items-center justify-center transition-all" title="Close Modal (Esc)">
                <i class="fa-solid fa-xmark text-lg"></i>
            </a>
        </div>

        <!-- MODAL BODY -->
        <div class="flex-1 overflow-y-auto p-5 md:p-6">
            <form method="POST" enctype="multipart/form-data" id="editForm">
                <input type="file" name="image" id="imgInput" accept="image/*" style="display:none">

                <div class="page-wrap">
                    <!-- COLUMN 1: PRODUCT IMAGE -->
                    <div class="section-card">
                        <div class="section-head">
                            <i class="fa-solid fa-image text-[#d1904b]"></i>
                            <h3>Product Image</h3>
                        </div>
                        <div class="section-body flex flex-col gap-3">
                            <div class="image-panel">
                                <?php if (!empty($product['image']) && file_exists($product['image'])): ?>
                                <div class="img-preview-wrap" id="imgWrap" onclick="document.getElementById('imgInput').click()">
                                    <img src="<?= htmlspecialchars($product['image']) ?>" alt="Product image" id="imgPreview">
                                    <div class="img-overlay">
                                        <i class="fa-solid fa-camera"></i>
                                        Replace image
                                    </div>
                                    <?php if (!empty($product['badge_text'])): ?>
                                    <span class="img-badge-overlay" id="imgBadge"><?= htmlspecialchars($product['badge_text']) ?></span>
                                    <?php else: ?>
                                    <span class="img-badge-overlay" id="imgBadge" style="display:none"></span>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <div class="no-image" id="imgWrap" onclick="document.getElementById('imgInput').click()">
                                    <i class="fa-solid fa-image"></i>
                                    <span>Click or drag to upload image</span>
                                </div>
                                <img id="imgPreview" style="display:none">
                                <?php endif; ?>

                                <div class="img-file-info text-center" id="fileInfo">
                                    <span id="fileName" class="text-[11px] text-[#888]">Current image — click above to replace</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- COLUMN 2: PRODUCT DETAILS -->
                    <div class="flex flex-col gap-4">
                        <div class="section-card">
                            <div class="section-head">
                                <i class="fa-solid fa-pen-line text-[#d1904b]"></i>
                                <h3>Product Details</h3>
                            </div>
                            <div class="section-body flex flex-col gap-4">
                                <div class="field">
                                    <label class="flabel" for="f_name">Product Name</label>
                                    <input type="text" id="f_name" name="name" required maxlength="120"
                                        value="<?= htmlspecialchars($product['name']) ?>"
                                        placeholder="e.g. Iced Caramel Latte">
                                </div>

                                <!-- CATEGORY -->
                                <div class="field">
                                    <label class="flabel" for="f_cat">Category</label>
                                    <select name="category" id="f_cat" class="cat-select" required>
                                        <option value="">Select Category…</option>
                                        <?php foreach ($cats as $slug => $label): ?>
                                        <option value="<?= htmlspecialchars($slug) ?>" <?= ($product['category'] === $slug) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($label) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- SELLING PRICE -->
                                <div class="field">
                                    <label class="flabel" for="f_price">Selling Price</label>
                                    <div class="input-wrap">
                                        <span class="prefix">$</span>
                                        <input type="number" id="f_price" name="price" step="0.01" min="0" max="9999.99"
                                            required class="has-prefix"
                                            value="<?= $product['price'] ?>">
                                    </div>
                                </div>

                                <!-- AVAILABILITY -->
                                <div class="field">
                                    <label class="flabel" for="availToggle">Availability</label>
                                    <div class="toggle-row">
                                        <div class="toggle-info">
                                            <h4>Show on menu</h4>
                                            <p class="text-[11px] text-[#777]">Toggle off to hide this product.</p>
                                        </div>
                                        <label class="toggle-switch">
                                            <input type="checkbox" name="is_available" id="availToggle"
                                                <?= $product['is_available'] ? 'checked' : '' ?>>
                                            <span class="toggle-track"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button type="submit" name="update_product" class="btn-save">
                            <i class="fa-solid fa-floppy-disk"></i>
                            Save Changes
                        </button>
                    </div>
                </div><!-- /.page-wrap -->
            </form>

        </div><!-- /.modal-body -->
    </div><!-- /.modal-dialog -->
</div><!-- /.modal-backdrop -->
</main>
</div>

<!-- TOAST -->
<div class="toast" id="toast">
    <i class="fa-solid fa-circle-check"></i>
    Product updated successfully!
</div>

<script>
// ── Image drag-drop + preview ──
const imgWrap = document.getElementById('imgWrap');
const imgInput = document.getElementById('imgInput');
const imgPreview = document.getElementById('imgPreview');
const fileInfo = document.getElementById('fileName');

imgInput.addEventListener('change', handleFile);

['dragenter','dragover'].forEach(e => imgWrap.addEventListener(e, ev => {
    ev.preventDefault(); imgWrap.classList.add('drag-over');
}));
['dragleave','drop'].forEach(e => imgWrap.addEventListener(e, ev => {
    ev.preventDefault(); imgWrap.classList.remove('drag-over');
}));
imgWrap.addEventListener('drop', ev => {
    ev.preventDefault();
    if (ev.dataTransfer.files[0]) {
        const dt = new DataTransfer();
        dt.items.add(ev.dataTransfer.files[0]);
        imgInput.files = dt.files;
        handleFile();
    }
});

function handleFile() {
    const file = imgInput.files[0];
    if (!file) return;
    fileInfo.innerHTML = `<span>${file.name}</span> — ${(file.size/1024).toFixed(1)} KB`;
    const reader = new FileReader();
    reader.onload = e => {
        imgPreview.src = e.target.result;
        imgPreview.style.display = 'block';
        // If no-image div, swap it for an img-preview-wrap look
        if (imgWrap.classList.contains('no-image')) {
            imgWrap.classList.remove('no-image');
            imgWrap.classList.add('img-preview-wrap');
            imgWrap.innerHTML = '';
            imgWrap.appendChild(imgPreview);
            const ov = document.createElement('div');
            ov.className = 'img-overlay';
            ov.innerHTML = '<i class="fa-solid fa-camera"></i>Replace image';
            ov.onclick = () => imgInput.click();
            imgWrap.appendChild(ov);
        }
    };
    reader.readAsDataURL(file);
}

// ── Live preview & input sync ──
const fName  = document.getElementById('f_name');
const fPrice = document.getElementById('f_price');
const ppName = document.getElementById('ppName');
const ppPrice= document.getElementById('ppPrice');
const ppCat  = document.getElementById('ppCat');
const navName= document.getElementById('nav-name');

if (fName) {
    fName.addEventListener('input', () => {
        if (ppName) ppName.textContent = fName.value || 'Product name';
        if (navName) navName.textContent = fName.value || 'Edit Product';
    });
}
if (fPrice) {
    fPrice.addEventListener('input', () => {
        const v = parseFloat(fPrice.value);
        if (ppPrice) ppPrice.textContent = isNaN(v) ? '$—' : '$' + v.toFixed(2);
        calculateTotalRecipeCost();
    });
}

function syncBasePrice() {
    const hasSizesEl = document.getElementById('has_sizes');
    if (!hasSizesEl) return;
    const on   = hasSizesEl.checked;
    const note = document.getElementById('priceNote');

    if (fPrice) fPrice.readOnly = on;
    if (note) note.style.display = on ? 'block' : 'none';
    if (!on) return;

    const priced = [...document.querySelectorAll('.size-price')]
        .filter(el => parseFloat(el.value) > 0);
    if (!priced.length) return;

    const medium = priced.find(el => el.dataset.sizeCode === 'M');
    const chosen = parseFloat((medium || priced[0]).value);

    if (fPrice) fPrice.value = chosen.toFixed(2);
    if (ppPrice) ppPrice.textContent = '$' + chosen.toFixed(2);
}

document.querySelectorAll('.size-price').forEach(el => el.addEventListener('input', syncBasePrice));

// ── Category select box ──
const fCatSelect = document.getElementById('f_cat');
if (fCatSelect) {
    fCatSelect.addEventListener('change', function() {
        if (ppCat) ppCat.textContent = this.value;
    });
}

// ── Char counter ──
const fDesc = document.getElementById('f_desc');
const charCount = document.getElementById('charCount');
function updateChar() {
    if (!fDesc || !charCount) return;
    const n = fDesc.value.length;
    charCount.textContent = n + ' / 300';
    charCount.className = 'char-count' + (n > 260 ? ' warn' : '');
}
if (fDesc) fDesc.addEventListener('input', updateChar);

// ── Availability pill in nav ──
const availToggle = document.getElementById('availToggle');
const navPill = document.getElementById('navPill');
if (availToggle && navPill) {
    availToggle.addEventListener('change', () => {
        if (availToggle.checked) {
            navPill.className = 'avail-pill on';
            navPill.innerHTML = '<i class="fa-solid fa-circle" style="font-size:7px"></i> Available';
        } else {
            navPill.className = 'avail-pill off';
            navPill.innerHTML = '<i class="fa-solid fa-circle" style="font-size:7px"></i> Unavailable';
        }
    });
}

// ── Badge live preview ──
const fBadge       = document.getElementById('f_badge');
const badgeLiveRow = document.getElementById('badgeLiveRow');
const badgeLive    = document.getElementById('badgeLive');
const ppBadgeRow   = document.getElementById('ppBadgeRow');
const ppBadge      = document.getElementById('ppBadge');
const imgBadge     = document.getElementById('imgBadge');

if (fBadge) {
    fBadge.addEventListener('input', updateBadge);
}
function updateBadge() {
    if (!fBadge) return;
    const val = fBadge.value.trim();
    if (val) {
        if (badgeLive && badgeLiveRow) { badgeLive.textContent = val; badgeLiveRow.style.display = 'flex'; }
        if (ppBadge && ppBadgeRow) { ppBadge.textContent = val; ppBadgeRow.style.display = 'flex'; }
        if (imgBadge) { imgBadge.textContent = val; imgBadge.style.display = 'flex'; }
    } else {
        if (badgeLiveRow) badgeLiveRow.style.display = 'none';
        if (ppBadgeRow) ppBadgeRow.style.display   = 'none';
        if (imgBadge) imgBadge.style.display = 'none';
    }
}
function clearBadge() { if (fBadge) { fBadge.value = ''; updateBadge(); } }

const fPromo = document.getElementById('f_promo');
if (fPromo) {
    fPromo.addEventListener('input', applyPromoBadge);
    applyPromoBadge();
}
function applyPromoBadge() {
    if (!fPromo) return;
    const promo = Math.max(0, Math.min(100, parseInt(fPromo.value || '0', 10)));
    if (promo > 0) {
        if (fBadge) { fBadge.disabled = true; fBadge.style.opacity = '0.5'; }
        const txt = promo + '% OFF';
        if (badgeLive && badgeLiveRow) { badgeLive.textContent = txt; badgeLiveRow.style.display = 'flex'; }
        if (ppBadge && ppBadgeRow) { ppBadge.textContent   = txt; ppBadgeRow.style.display   = 'flex'; }
        if (imgBadge) { imgBadge.textContent = txt; imgBadge.style.display = 'flex'; }
    } else {
        if (fBadge) { fBadge.disabled = false; fBadge.style.opacity = ''; }
        updateBadge();
    }
}

// ── Inline Recipe Rows Manager ──
const allIngredients = <?= json_encode($allIngredients) ?>;

function addRecipeRow(ingId = '', amt = '') {
    const container = document.getElementById('recipeRowsContainer');
    const noMsg = document.getElementById('noRecipeMsg');
    if (noMsg) noMsg.style.display = 'none';

    let options = '<option value="">Select ingredient…</option>';
    allIngredients.forEach(i => {
        const sel = (i.ingredient_id == ingId) ? 'selected' : '';
        const cpu = parseFloat(i.cost_per_unit || 0);
        options += `<option value="${i.ingredient_id}" data-unit="${escapeHtml(i.unit)}" data-cpu="${cpu}" ${sel}>${escapeHtml(i.ingredient_name)} (Stock: ${i.stock_quantity} ${escapeHtml(i.unit)})</option>`;
    });

    const tr = document.createElement('tr');
    tr.className = 'recipe-row hover:bg-[#151519] transition-all';
    tr.innerHTML = `
        <td class="py-2 px-3">
            <select name="recipe_ingredient_id[]" class="w-full text-xs bg-[#18181c] border border-[#333] rounded-lg p-2 text-white outline-none focus:border-[#d1904b]" onchange="updateRecipeRow(this)" required>
                ${options}
            </select>
        </td>
        <td class="py-2 px-2">
            <div class="flex items-center justify-center gap-1">
                <input type="number" step="any" min="0" name="recipe_amount_used[]" value="${amt}" placeholder="0" class="w-16 text-xs bg-[#18181c] border border-[#333] rounded-md px-2 py-1.5 text-white outline-none focus:border-[#d1904b] text-right font-bold row-qty-input" oninput="calculateRowTotal(this)" required>
                <span class="unit-label text-[11px] text-[#d1904b] font-bold min-w-[20px]">unit</span>
            </div>
        </td>
        <td class="py-2 px-2 text-right text-[11px] text-[#aaa]">
            <span class="unit-price-label text-white font-semibold">$0.00</span>/<span class="unit-name-label text-[#777]">unit</span>
        </td>
        <td class="py-2 px-2 text-right text-xs font-bold text-[#3ecf70]">
            $<span class="row-total-label">0.00</span>
        </td>
        <td class="py-2 px-2 text-center">
            <button type="button" onclick="this.closest('.recipe-row').remove(); calculateTotalRecipeCost(); checkEmptyRecipe();" class="text-[#888] hover:text-red-400 p-1.5 rounded-lg hover:bg-red-500/10 transition-all" title="Remove ingredient">
                <i class="fa-solid fa-trash-can text-xs"></i>
            </button>
        </td>
    `;
    container.appendChild(tr);
    const sel = tr.querySelector('select');
    if (sel.value) updateRecipeRow(sel);
}

function updateRecipeRow(selectEl) {
    const opt = selectEl.options[selectEl.selectedIndex];
    const row = selectEl.closest('.recipe-row');
    if (!row || !opt) return;

    const unit = opt.dataset.unit || 'unit';
    const cpu  = parseFloat(opt.dataset.cpu || '0');

    const uLbl = row.querySelector('.unit-label');
    if (uLbl) uLbl.textContent = unit;
    const unLbl = row.querySelector('.unit-name-label');
    if (unLbl) unLbl.textContent = unit;
    const upLbl = row.querySelector('.unit-price-label');
    if (upLbl) {
        const cpuStr = (cpu < 0.01 && cpu > 0) ? cpu.toFixed(4).replace(/0+$/, '') : cpu.toFixed(2);
        upLbl.textContent = '$' + cpuStr;
    }

    calculateRowTotal(row.querySelector('.row-qty-input'));
}

function calculateRowTotal(qtyInput) {
    if (!qtyInput) return;
    const row = qtyInput.closest('.recipe-row');
    if (!row) return;

    const selectEl = row.querySelector('select[name="recipe_ingredient_id[]"]');
    const opt = selectEl ? selectEl.options[selectEl.selectedIndex] : null;
    const cpu = opt ? parseFloat(opt.dataset.cpu || '0') : 0;
    const qty = parseFloat(qtyInput.value || '0');

    const total = qty * cpu;
    const rtLbl = row.querySelector('.row-total-label');
    if (rtLbl) rtLbl.textContent = total.toFixed(2);

    calculateTotalRecipeCost();
}

function calculateTotalRecipeCost() {
    let grandTotal = 0;
    document.querySelectorAll('.recipe-row').forEach(row => {
        const qtyInput = row.querySelector('.row-qty-input');
        const selectEl = row.querySelector('select[name="recipe_ingredient_id[]"]');
        const opt = selectEl ? selectEl.options[selectEl.selectedIndex] : null;
        const cpu = opt ? parseFloat(opt.dataset.cpu || '0') : 0;
        const qty = parseFloat(qtyInput ? qtyInput.value || '0' : '0');
        grandTotal += (qty * cpu);
    });

    const cogsEl = document.getElementById('totalRecipeCogs');
    if (cogsEl) cogsEl.textContent = grandTotal.toFixed(2);

    const tblCogs = document.getElementById('tableTotalCogs');
    if (tblCogs) tblCogs.textContent = grandTotal.toFixed(2);

    const costPriceInput = document.getElementById('f_cost_price');
    if (costPriceInput) costPriceInput.value = grandTotal.toFixed(2);

    // Live Gross Margin calculation
    const sellingPrice = parseFloat(document.getElementById('f_price')?.value || '0');
    const dispSelling  = document.getElementById('dispSellingPrice');
    if (dispSelling) dispSelling.textContent = sellingPrice.toFixed(2);

    const marginDol    = sellingPrice - grandTotal;
    const marginPct    = sellingPrice > 0 ? ((sellingPrice - grandTotal) / sellingPrice) * 100 : 0;

    const gmDolEl = document.getElementById('grossMarginDol');
    const gmPctEl = document.getElementById('grossMarginPct');
    const gmWrap  = document.getElementById('grossMarginWrap');

    if (gmDolEl) gmDolEl.textContent = marginDol.toFixed(2);
    if (gmPctEl) gmPctEl.textContent = marginPct.toFixed(1) + '%';

    if (gmWrap) {
        if (marginDol > 0 || marginPct > 0) gmWrap.className = 'text-sm font-extrabold text-[#3ecf70]';
        else if (sellingPrice > 0 && marginDol <= 0) gmWrap.className = 'text-sm font-extrabold text-[#ff4d4d]';
        else gmWrap.className = 'text-sm font-extrabold text-[#777]';
    }

    const summary = document.getElementById('recipeFooterSummary');
    if (summary) summary.style.display = document.querySelectorAll('.recipe-row').length > 0 ? 'table-footer-group' : 'none';
}

function checkEmptyRecipe() {
    const container = document.getElementById('recipeRowsContainer');
    const noMsg = document.getElementById('noRecipeMsg');
    if (container.querySelectorAll('.recipe-row').length === 0) {
        if (noMsg) noMsg.style.display = 'flex';
    }
}

function escapeHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// ── Initial load calculation ──
document.addEventListener('DOMContentLoaded', calculateTotalRecipeCost);
calculateTotalRecipeCost();

// ── Shortcuts: Escape to exit modal, Ctrl+Enter to save ──
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') window.location.href = 'products.php';
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') document.getElementById('editForm').submit();
});

// ── Toast ──
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
