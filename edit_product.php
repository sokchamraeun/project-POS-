<?php
header('X-Frame-Options: SAMEORIGIN');
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
$hasSizes = false;

$error   = '';
$success = false;

if (isset($_POST['update_product']) || isset($_POST['ajax'])) {
    $isAjax = !empty($_POST['ajax']) 
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
        || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

    $name        = trim($_POST['name']        ?? '');
    $description = trim($_POST['description'] ?? '');
    $price       = round((float)($_POST['price'] ?? 0), 2);
    $cost_price  = round((float)($_POST['cost_price'] ?? 0), 2);
    $category    = $_POST['category']    ?? '';
    $product_type = trim($_POST['product_type'] ?? 'recipe');
    $is_avail    = isset($_POST['is_available']) ? 1 : 0;
    $badge_text  = isset($_POST['badge_text']) ? (substr(trim($_POST['badge_text']), 0, 40) ?: null) : ($product['badge_text'] ?? null);
    $promo_percent = max(0, min(100, (int)($_POST['promo_percent'] ?? 0)));

    $cat_r = $conn->prepare("SELECT category_id FROM categories WHERE slug = ? LIMIT 1");
    $cat_r->bind_param("s", $category); $cat_r->execute();
    $category_id = ($cat_r->get_result()->fetch_assoc())['category_id'] ?? null;

    if ($name === '' || $price < 0 || $category === '') {
        $error = "Please fill in all required fields.";
    } else {
        // Check for duplicate product name
        $chk = $conn->prepare("SELECT product_id FROM products WHERE LOWER(TRIM(name)) = LOWER(?) AND product_id != ? LIMIT 1");
        $chk->bind_param("si", $name, $id);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            $error = "Another product named \"$name\" already exists on the menu.";
        }
    }

    if (!$error && !empty($_FILES['image']['name'])) {
        $uploadRes = cloudinary_upload_file($_FILES['image'], 'pos_coffee/products');
        if ($uploadRes['success']) {
            $image_path = $uploadRes['url'];
            if (!empty($product['image'])) {
                cloudinary_delete_image($product['image']);
            }
            $stmt = $conn->prepare("UPDATE products SET name=?,description=?,price=?,cost_price=?,category=?,category_id=?,image=?,is_available=?,badge_text=?,promo_percent=? WHERE product_id=?");
            $stmt->bind_param("ssddsisisii", $name, $description, $price, $cost_price, $category, $category_id, $image_path, $is_avail, $badge_text, $promo_percent, $id);
            if ($stmt->execute()) { $success = true; $product['image'] = $image_path; }
            else $error = "Database error while updating product.";
        } else {
            $error = $uploadRes['error'];
        }
    } else if (!$error) {
        $stmt = $conn->prepare("UPDATE products SET name=?,description=?,price=?,cost_price=?,category=?,category_id=?,is_available=?,badge_text=?,promo_percent=? WHERE product_id=?");
        $stmt->bind_param("ssddsiisii", $name, $description, $price, $cost_price, $category, $category_id, $is_avail, $badge_text, $promo_percent, $id);
        if ($stmt->execute()) $success = true;
        else $error = "Database error while updating product.";
    }

    // ── Save Recipe / Bill of Materials (BOM) ──
    if ($success) {
        // Clear previous recipe items for this product
        $conn->query("DELETE FROM product_recipes WHERE product_id = " . (int)$id);

        if ($product_type !== 'direct_drink' && !empty($_POST['recipe_ingredient_id']) && is_array($_POST['recipe_ingredient_id'])) {
            $insRec = $conn->prepare("INSERT INTO product_recipes (product_id, item_id, quantity_required, unit, notes) VALUES (?, ?, ?, ?, ?)");
            $ingIds  = $_POST['recipe_ingredient_id'];
            $ingQtys = $_POST['recipe_amount_used'] ?? [];
            $totalCogs = 0.0;
            $seenIngs  = [];

            for ($i = 0; $i < count($ingIds); $i++) {
                $ingId   = (int)($ingIds[$i] ?? 0);
                $qtyUsed = (float)($ingQtys[$i] ?? 0);
                if ($ingId > 0 && $qtyUsed > 0 && !isset($seenIngs[$ingId])) {
                    $seenIngs[$ingId] = true;
                    $uStmt = $conn->prepare("SELECT unit, cost_per_unit FROM stock_items WHERE item_id = ?");
                    $uStmt->bind_param("i", $ingId);
                    $uStmt->execute();
                    $uRow = $uStmt->get_result()->fetch_assoc();
                    $unit = $uRow['unit'] ?? 'g';
                    $cpu  = (float)($uRow['cost_per_unit'] ?? 0);
                    $totalCogs += ($qtyUsed * $cpu);
                    $note = "Recipe for " . $name;

                    $insRec->bind_param("iidss", $id, $ingId, $qtyUsed, $unit, $note);
                    $insRec->execute();
                    $uStmt->close();
                }
            }
            $insRec->close();

            // Update cost_price in products to match exact COGS
            if ($totalCogs > 0) {
                $cost_price = round($totalCogs, 2);
                $updCost = $conn->prepare("UPDATE products SET cost_price = ? WHERE product_id = ?");
                $updCost->bind_param("di", $cost_price, $id);
                $updCost->execute();
                $updCost->close();
            }
        }

        $product['name']         = $name;
        $product['description']  = $description;
        $product['price']        = $price;
        $product['cost_price']   = $cost_price;
        $product['category']     = $category;
        $product['is_available'] = $is_avail;
        $product['badge_text']   = $badge_text ?: null;
        $product['promo_percent'] = $promo_percent;

        $recCountStmt = $conn->prepare("SELECT COUNT(*) AS rc FROM product_recipes WHERE product_id = ?");
        $recCountStmt->bind_param("i", $id);
        $recCountStmt->execute();
        $recipeCount = (int)($recCountStmt->get_result()->fetch_assoc()['rc'] ?? 0);
        $recCountStmt->close();

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => true,
                'message' => 'Product & recipe updated successfully!',
                'product' => [
                    'name' => $name,
                    'price' => $price,
                    'cost_price' => $cost_price,
                    'category' => $category,
                    'is_available' => $is_avail,
                    'badge_text' => $badge_text,
                    'recipe_count' => $recipeCount,
                    'has_recipe' => ($recipeCount > 0 ? 1 : 0),
                    'image' => !empty($product['image']) ? get_image_url($product['image']) : ''
                ]
            ]);
            exit;
        }

        header("Location: products.php");
        exit;
    } else {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => false,
                'error' => $error ?: 'Database error while updating product.'
            ]);
            exit;
        }
    }
}

$cats = [];
$_cat_r = $conn->query("SELECT slug, name FROM categories WHERE is_active = 1 ORDER BY display_order");
while ($_c = $_cat_r->fetch_assoc()) $cats[$_c['slug']] = $_c['name'];

// ── Load All Stock Items for Recipe Dropdowns ──
$allIngredients = [];
$stockRes = $conn->query("SELECT item_id AS ingredient_id, item_name AS ingredient_name, category, item_type, unit, cost_per_unit, quantity AS stock_quantity, purchase_unit, conversion_rate 
                          FROM stock_items 
                          WHERE is_active = 1 
                          ORDER BY category ASC, item_name ASC");
if ($stockRes) {
    while ($si = $stockRes->fetch_assoc()) {
        $allIngredients[] = $si;
    }
}

$directDrinkList = [];
foreach ($allIngredients as $ing) {
    if (($ing['item_type'] ?? '') === 'direct_drink' || ($ing['category'] ?? '') === 'Direct Drinks') {
        $directDrinkList[] = [
            'id'       => (int)$ing['ingredient_id'],
            'name'     => $ing['ingredient_name'],
            'qty'      => (float)$ing['stock_quantity'],
            'unit'     => $ing['unit'] ?: 'cans',
            'cost'     => (float)$ing['cost_per_unit'],
            'category' => $ing['category'] ?? ''
        ];
    }
}

if (!function_exists('renderIngredientOptGroups')) {
    function renderIngredientOptGroups($allIngredients, $selectedId = null) {
        $rawIngredients = [];
        $pkgIngredients = [];
        $drinkStock = [];
        foreach ($allIngredients as $item) {
            $isDrink = (($item['item_type'] ?? '') === 'direct_drink' || ($item['category'] ?? '') === 'Direct Drinks');
            $isPkg = (($item['category'] ?? '') === 'Packaging' || ($item['category'] ?? '') === 'កែវ & ការវេចខ្ចប់' || str_contains(strtolower($item['ingredient_name'] ?? ''), 'packaging') || str_contains($item['ingredient_name'] ?? '', 'ឈុត'));
            if ($isDrink) {
                $drinkStock[] = $item;
            } elseif ($isPkg) {
                $pkgIngredients[] = $item;
            } else {
                $rawIngredients[] = $item;
            }
        }

        $html = '<option value="">Select ingredient / stock drink…</option>';

        if (!empty($rawIngredients)) {
            $html .= '<optgroup label="🥛 Ingredients / Raw Materials">';
            foreach ($rawIngredients as $i) {
                $sel = ((string)$i['ingredient_id'] === (string)$selectedId) ? 'selected' : '';
                $unit = htmlspecialchars($i['unit'] ?? 'unit');
                $cpu = (float)($i['cost_per_unit'] ?? 0);
                $name = htmlspecialchars($i['ingredient_name']);
                $html .= "<option value=\"{$i['ingredient_id']}\" data-unit=\"{$unit}\" data-type=\"ingredient\" data-cpu=\"{$cpu}\" {$sel}>{$name}</option>";
            }
            $html .= '</optgroup>';
        }

        if (!empty($pkgIngredients)) {
            $html .= '<optgroup label="📦 Packaging / Cups & Sets">';
            foreach ($pkgIngredients as $i) {
                $sel = ((string)$i['ingredient_id'] === (string)$selectedId) ? 'selected' : '';
                $unit = htmlspecialchars($i['unit'] ?? 'pcs');
                $cpu = (float)($i['cost_per_unit'] ?? 0);
                $name = htmlspecialchars($i['ingredient_name']);
                $html .= "<option value=\"{$i['ingredient_id']}\" data-unit=\"{$unit}\" data-type=\"packaging\" data-cpu=\"{$cpu}\" {$sel}>{$name}</option>";
            }
            $html .= '</optgroup>';
        }

        if (!empty($drinkStock)) {
            $html .= '<optgroup label="🥫 Drink Stock (Cans & Bottles)">';
            foreach ($drinkStock as $i) {
                $sel = ((string)$i['ingredient_id'] === (string)$selectedId) ? 'selected' : '';
                $unit = htmlspecialchars($i['unit'] ?? 'can');
                $cpu = (float)($i['cost_per_unit'] ?? 0);
                $name = htmlspecialchars($i['ingredient_name']);
                $html .= "<option value=\"{$i['ingredient_id']}\" data-unit=\"{$unit}\" data-type=\"direct_drink\" data-cpu=\"{$cpu}\" {$sel}>{$name}</option>";
            }
            $html .= '</optgroup>';
        }

        return $html;
    }
}

if (!function_exists('renderCustomRecipeDropdown')) {
    function renderCustomRecipeDropdown($allIngredients, $selectedId = null) {
        $rawIngredients = [];
        $pkgIngredients = [];
        $drinkStock = [];
        $selectedItem = null;

        foreach ($allIngredients as $item) {
            $isDrink = (($item['item_type'] ?? '') === 'direct_drink' || ($item['category'] ?? '') === 'Direct Drinks');
            $isPkg = (($item['category'] ?? '') === 'Packaging' || ($item['category'] ?? '') === 'កែវ & ការវេចខ្ចប់' || str_contains(strtolower($item['ingredient_name'] ?? ''), 'packaging') || str_contains($item['ingredient_name'] ?? '', 'ឈុត'));
            if ($isDrink) {
                $drinkStock[] = $item;
            } elseif ($isPkg) {
                $pkgIngredients[] = $item;
            } else {
                $rawIngredients[] = $item;
            }
            if ((string)$item['ingredient_id'] === (string)$selectedId) {
                $selectedItem = $item;
            }
        }

        $isDrinkSel = $selectedItem ? ((($selectedItem['item_type'] ?? '') === 'direct_drink') || (($selectedItem['category'] ?? '') === 'Direct Drinks')) : false;
        $isPkgSel = $selectedItem ? ((($selectedItem['category'] ?? '') === 'Packaging') || str_contains(strtolower($selectedItem['ingredient_name'] ?? ''), 'packaging') || str_contains($selectedItem['ingredient_name'] ?? '', 'ឈុត')) : false;
        $defaultCat = $isDrinkSel ? 'direct_drink' : ($isPkgSel ? 'packaging' : 'ingredient');

        $btnIcon = '<i class="fa-solid fa-layer-group text-[#888]"></i>';
        if ($selectedItem) {
            if ($isDrinkSel) $btnIcon = '<i class="fa-solid fa-wine-bottle text-amber-400"></i>';
            elseif ($isPkgSel) $btnIcon = '<i class="fa-solid fa-box-open text-sky-400"></i>';
            else $btnIcon = '<i class="fa-solid fa-seedling text-emerald-400"></i>';
        }
        $btnText = $selectedItem ? htmlspecialchars($selectedItem['ingredient_name']) : 'Select ingredient / stock drink…';

        $html = '<div class="crd-wrap">';
        $html .= '<button type="button" class="crd-btn" onclick="toggleCrd(this, event)">';
        $html .= '<span class="crd-btn-main"><span class="crd-btn-icon">' . $btnIcon . '</span><span class="crd-btn-text">' . $btnText . '</span></span>';
        $html .= '<i class="fa-solid fa-chevron-down crd-btn-arrow"></i>';
        $html .= '</button>';

        $html .= '<div class="crd-popover">';
        // Left Column: Categories with Hover Switch
        $html .= '<div class="crd-categories">';
        
        $cat1Active = ($defaultCat === 'ingredient') ? 'active' : '';
        $html .= '<div class="crd-cat-item ' . $cat1Active . '" data-cat="ingredient" onmouseenter="crdSwitchCat(this, \'ingredient\')">';
        $html .= '<span class="crd-cat-title"><i class="fa-solid fa-seedling text-emerald-400 mr-1.5"></i> Ingredients</span>';
        $html .= '<span class="crd-cat-count">' . count($rawIngredients) . '</span>';
        $html .= '<i class="fa-solid fa-chevron-right crd-cat-arrow ml-1"></i>';
        $html .= '</div>';

        $catPkgActive = ($defaultCat === 'packaging') ? 'active' : '';
        $html .= '<div class="crd-cat-item ' . $catPkgActive . '" data-cat="packaging" onmouseenter="crdSwitchCat(this, \'packaging\')">';
        $html .= '<span class="crd-cat-title"><i class="fa-solid fa-box-open text-sky-400 mr-1.5"></i> Packaging</span>';
        $html .= '<span class="crd-cat-count">' . count($pkgIngredients) . '</span>';
        $html .= '<i class="fa-solid fa-chevron-right crd-cat-arrow ml-1"></i>';
        $html .= '</div>';

        $cat2Active = ($defaultCat === 'direct_drink') ? 'active' : '';
        $html .= '<div class="crd-cat-item ' . $cat2Active . '" data-cat="direct_drink" onmouseenter="crdSwitchCat(this, \'direct_drink\')">';
        $html .= '<span class="crd-cat-title"><i class="fa-solid fa-wine-bottle text-amber-400 mr-1.5"></i> Drink Stock</span>';
        $html .= '<span class="crd-cat-count">' . count($drinkStock) . '</span>';
        $html .= '<i class="fa-solid fa-chevron-right crd-cat-arrow ml-1"></i>';
        $html .= '</div>';

        $html .= '</div>'; // end crd-categories

        // Right Column: Panels
        $html .= '<div class="crd-subpanels">';
        
        // Panel 1: Ingredients
        $p1Active = ($defaultCat === 'ingredient') ? 'active' : '';
        $html .= '<div class="crd-panel crd-panel-ingredient ' . $p1Active . '">';
        if (empty($rawIngredients)) {
            $html .= '<div class="crd-empty-msg">No ingredients found</div>';
        } else {
            foreach ($rawIngredients as $ri) {
                $sel = ((string)$ri['ingredient_id'] === (string)$selectedId) ? 'selected' : '';
                $cpu = (float)($ri['cost_per_unit'] ?? 0);
                $cpuStr = ($cpu < 0.01 && $cpu > 0) ? rtrim(rtrim(number_format($cpu, 4), '0'), '.') : number_format($cpu, 2);
                $html .= '<div class="crd-item-row ' . $sel . '" data-id="' . $ri['ingredient_id'] . '" data-unit="' . htmlspecialchars($ri['unit']) . '" data-cpu="' . $cpu . '" data-type="ingredient" data-name="' . htmlspecialchars($ri['ingredient_name']) . '" onclick="crdSelectItem(this, event)">';
                $html .= '<span class="crd-item-name">' . htmlspecialchars($ri['ingredient_name']) . '</span>';
                $html .= '<span class="crd-item-meta">$' . $cpuStr . '/' . htmlspecialchars($ri['unit']) . '</span>';
                $html .= '</div>';
            }
        }
        $html .= '</div>';

        // Panel: Packaging
        $pPkgActive = ($defaultCat === 'packaging') ? 'active' : '';
        $html .= '<div class="crd-panel crd-panel-packaging ' . $pPkgActive . '">';
        if (empty($pkgIngredients)) {
            $html .= '<div class="crd-empty-msg">No packaging items found</div>';
        } else {
            foreach ($pkgIngredients as $pi) {
                $sel = ((string)$pi['ingredient_id'] === (string)$selectedId) ? 'selected' : '';
                $cpu = (float)($pi['cost_per_unit'] ?? 0);
                $cpuStr = ($cpu < 0.01 && $cpu > 0) ? rtrim(rtrim(number_format($cpu, 4), '0'), '.') : number_format($cpu, 2);
                $html .= '<div class="crd-item-row ' . $sel . '" data-id="' . $pi['ingredient_id'] . '" data-unit="' . htmlspecialchars($pi['unit']) . '" data-cpu="' . $cpu . '" data-type="packaging" data-name="' . htmlspecialchars($pi['ingredient_name']) . '" onclick="crdSelectItem(this, event)">';
                $html .= '<span class="crd-item-name">' . htmlspecialchars($pi['ingredient_name']) . '</span>';
                $html .= '<span class="crd-item-meta">$' . $cpuStr . '/' . htmlspecialchars($pi['unit']) . '</span>';
                $html .= '</div>';
            }
        }
        $html .= '</div>';

        // Panel 2: Drink Stock
        $p2Active = ($defaultCat === 'direct_drink') ? 'active' : '';
        $html .= '<div class="crd-panel crd-panel-direct_drink ' . $p2Active . '">';
        if (empty($drinkStock)) {
            $html .= '<div class="crd-empty-msg">No drink stock found</div>';
        } else {
            foreach ($drinkStock as $ds) {
                $sel = ((string)$ds['ingredient_id'] === (string)$selectedId) ? 'selected' : '';
                $cpu = (float)($ds['cost_per_unit'] ?? 0);
                $cpuStr = ($cpu < 0.01 && $cpu > 0) ? rtrim(rtrim(number_format($cpu, 4), '0'), '.') : number_format($cpu, 2);
                $html .= '<div class="crd-item-row ' . $sel . '" data-id="' . $ds['ingredient_id'] . '" data-unit="' . htmlspecialchars($ds['unit']) . '" data-cpu="' . $cpu . '" data-type="direct_drink" data-name="' . htmlspecialchars($ds['ingredient_name']) . '" onclick="crdSelectItem(this, event)">';
                $html .= '<span class="crd-item-name">' . htmlspecialchars($ds['ingredient_name']) . '</span>';
                $html .= '<span class="crd-item-meta">$' . $cpuStr . '/' . htmlspecialchars($ds['unit']) . '</span>';
                $html .= '</div>';
            }
        }
        $html .= '</div>';

        $html .= '</div>'; // end crd-subpanels
        $html .= '</div>'; // end crd-popover
        $html .= '</div>'; // end crd-wrap

        return $html;
    }
}



// ── Load Existing Recipe for this Product ──
$productIngredients = [];
$recStmt = $conn->prepare("SELECT r.recipe_id, r.item_id, r.quantity_required, r.unit, r.notes, s.item_name, s.category, s.item_type, s.cost_per_unit, s.quantity AS stock_quantity, s.unit AS stock_unit 
                           FROM product_recipes r 
                           JOIN stock_items s ON r.item_id = s.item_id 
                           WHERE r.product_id = ? AND s.is_active = 1 
                           ORDER BY r.recipe_id ASC");
$recStmt->bind_param("i", $id);
$recStmt->execute();
$productIngredients = $recStmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>(function(){if(localStorage.getItem('theme')==='light')document.documentElement.setAttribute('data-theme','light');})();</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.tailwindcss.com"></script>
<style>
body, input, select, textarea, button {
    font-family: 'Poppins', 'Kantumruy Pro', 'Siemreap', 'Noto Sans Khmer', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}
:lang(km), [data-lang="km"], html[lang="km"] * {
    font-family: 'Kantumruy Pro', 'Poppins', 'Siemreap', 'Noto Sans Khmer', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

:root {
    --bg:      #0c0c0c;
    --surface: #111;
    --card:    #161616;
    --border:  #24242b;
    --accent:  #d1904b;
    --accent2: #b57b3b;
    --text:    #f0f0f0;
    --muted:   #888;
    --success: #3ecf70;
    --danger:  #ff4d4d;
    --radius:  14px;
}
[data-theme="light"], html[data-theme="light"] {
    --bg:      #ffffff !important;
    --surface: #f3f4f6 !important;
    --card:    #ffffff !important;
    --border:  #e5e7eb !important;
    --text:    #1a1410 !important;
    --muted:   #5a4a3a !important;
}

/* Light Mode Overrides */
[data-theme="light"] .fixed.inset-0 {
    background-color: rgba(0,0,0,0.45) !important;
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
html[data-theme="light"] input[type=text],
html:not([data-theme="dark"]) input[type=text],
html[data-theme="light"] input[type=number],
html:not([data-theme="dark"]) input[type=number],
html[data-theme="light"] textarea,
html:not([data-theme="dark"]) textarea,
html[data-theme="light"] select,
html:not([data-theme="dark"]) select {
    background: #f4efe9 !important;
    color: #1a1410 !important;
    border-color: #d0c5b5 !important;
}
html[data-theme="light"] select.cat-select,
html:not([data-theme="dark"]) select.cat-select {
    background-color: #f4efe9 !important;
    color: #1a1410 !important;
    border-color: #d0c5b5 !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%235a4a3a' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") !important;
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
html[data-theme="light"] .section-card,
html:not([data-theme="dark"]) .section-card {
    background: #ffffff !important;
    border-color: #e0d4c4 !important;
}

/* ── Recipe Table & Summary Styling ── */
.recipe-table-wrap {
    background: #121215;
    border: 1px solid #24242b;
}
.recipe-thead {
    background: #18181c;
    color: #888888;
    border-bottom: 1px solid #24242b;
}
.recipe-tbody {
    border-color: #1c1c22;
}
.no-recipe-box {
    color: #888888;
}
.no-recipe-title {
    color: #ffffff;
}
.no-recipe-sub {
    color: #777777;
}
.recipe-summary-box {
    background: #141418;
    border: 1px solid #24242b;
}
.summary-label {
    color: #888888;
}
.recipe-select {
    width: 100%;
    font-size: 12px;
    background: #18181c;
    border: 1px solid #2b2b36;
    border-radius: 8px;
    padding: 8px;
    color: #ffffff;
    outline: none;
}
.recipe-select:focus {
    border-color: #d1904b;
}

/* ══ Light Theme Overrides ══ */
[data-theme="light"] .recipe-table-wrap,
html[data-theme="light"] .recipe-table-wrap {
    background: #fbf9f6 !important;
    border-color: #e0d4c4 !important;
}
[data-theme="light"] .recipe-thead,
html[data-theme="light"] .recipe-thead {
    background: #ede8e0 !important;
    color: #5a4a3a !important;
    border-bottom-color: #e0d4c4 !important;
}
[data-theme="light"] .recipe-table th,
html[data-theme="light"] .recipe-table th {
    color: #5a4a3a !important;
}
[data-theme="light"] .recipe-tbody,
html[data-theme="light"] .recipe-tbody {
    border-color: #e0d4c4 !important;
}
[data-theme="light"] .recipe-tbody tr,
html[data-theme="light"] .recipe-tbody tr {
    border-color: #e0d4c4 !important;
}
[data-theme="light"] .no-recipe-box,
html[data-theme="light"] .no-recipe-box {
    color: #7a6a5a !important;
}
[data-theme="light"] .no-recipe-title,
html[data-theme="light"] .no-recipe-title {
    color: #1a1410 !important;
}
[data-theme="light"] .no-recipe-sub,
html[data-theme="light"] .no-recipe-sub {
    color: #7a6a5a !important;
}
[data-theme="light"] .recipe-summary-box,
html[data-theme="light"] .recipe-summary-box {
    background: #ede8e0 !important;
    border-color: #e0d4c4 !important;
}
[data-theme="light"] .summary-label,
html[data-theme="light"] .summary-label {
    color: #5a4a3a !important;
}
[data-theme="light"] .recipe-select,
html[data-theme="light"] .recipe-select {
    background: #ede8e0 !important;
    border-color: #d0c5b5 !important;
    color: #1a1410 !important;
}
[data-theme="light"] .recipe-select option,
html[data-theme="light"] .recipe-select option {
    background: #ffffff !important;
    color: #1a1410 !important;
}
[data-theme="light"] .unit-label-pill,
html[data-theme="light"] .unit-label-pill {
    background: #ede8e0 !important;
    color: #c47c2c !important;
    border-color: #d0c5b5 !important;
}
[data-theme="light"] .unit-price-label,
html[data-theme="light"] .unit-price-label {
    color: #1a1410 !important;
}
[data-theme="light"] .unit-name-label,
html[data-theme="light"] .unit-name-label {
    color: #5a4a3a !important;
}
[data-theme="light"] .selling-price-disp,
html[data-theme="light"] .selling-price-disp,
[data-theme="light"] #dispSellingPrice,
html[data-theme="light"] #dispSellingPrice {
    color: #1a1410 !important;
}
[data-theme="light"] label.flabel,
html[data-theme="light"] label.flabel {
    color: #8c5820 !important;
}

/* ══ Styled Category Optgroups & Custom Cascading Hover Dropdown (CRD) ══ */
.recipe-table-wrap {
    overflow: visible !important;
}
.recipe-table {
    overflow: visible !important;
}
.recipe-tbody, .recipe-row {
    position: relative;
    overflow: visible !important;
}
.recipe-row:has(.crd-popover.open) {
    z-index: 99999 !important;
    position: relative;
}

.crd-wrap {
    position: relative;
    width: 100%;
    z-index: 20;
}
.crd-wrap:has(.crd-popover.open) {
    z-index: 99999 !important;
}
.crd-btn {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    width: 100%;
    height: 38px;
    padding: 0 12px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: #141418;
    color: var(--text);
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    box-sizing: border-box;
    text-align: left;
}
.crd-btn:hover, .crd-btn:focus {
    border-color: #d1904b;
    box-shadow: 0 0 0 3px rgba(209, 144, 75, 0.15);
}
.crd-btn-main {
    display: flex;
    align-items: center;
    gap: 8px;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    flex: 1;
}
.crd-btn-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    flex-shrink: 0;
}
.crd-btn-text {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.crd-btn-arrow {
    font-size: 10px;
    color: #8e8e9f;
    transition: transform 0.2s ease;
    flex-shrink: 0;
}
.crd-popover.open ~ .crd-btn .crd-btn-arrow,
.crd-wrap:has(.crd-popover.open) .crd-btn-arrow {
    transform: rotate(180deg);
}

.crd-popover {
    display: none;
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    width: 380px;
    max-width: 90vw;
    background: #16161c;
    border: 1px solid #2e2e3e;
    border-radius: 12px;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.8), 0 0 0 1px rgba(255,255,255,0.08);
    z-index: 999999 !important;
    flex-direction: row;
    overflow: hidden;
    animation: crdFadeIn 0.18s cubic-bezier(0.16, 1, 0.3, 1);
}
.crd-popover.open {
    display: flex;
}
@keyframes crdFadeIn {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
}

.crd-categories {
    width: 150px;
    background: #111116;
    border-right: 1px solid #262634;
    padding: 6px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex-shrink: 0;
}
.crd-cat-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 9px 8px;
    border-radius: 8px;
    font-size: 11.5px;
    font-weight: 700;
    color: #9494a8;
    cursor: pointer;
    transition: all 0.15s ease;
    user-select: none;
}
.crd-cat-item:hover, .crd-cat-item.active {
    background: rgba(209, 144, 75, 0.18);
    color: #d1904b;
}
.crd-cat-title {
    display: flex;
    align-items: center;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.crd-cat-count {
    background: rgba(255, 255, 255, 0.08);
    color: #b8b8c8;
    font-size: 10px;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 10px;
    margin-left: auto;
}
.crd-cat-item:hover .crd-cat-count, .crd-cat-item.active .crd-cat-count {
    background: rgba(209, 144, 75, 0.3);
    color: #d1904b;
}
.crd-cat-arrow {
    font-size: 9px;
    color: #66667a;
    transition: transform 0.15s ease;
}
.crd-cat-item:hover .crd-cat-arrow, .crd-cat-item.active .crd-cat-arrow {
    color: #d1904b;
    transform: translateX(2px);
}

.crd-subpanels {
    flex: 1;
    min-width: 0;
    min-height: 340px;
    max-height: 440px;
    overflow-y: auto;
    padding: 8px 6px;
    scrollbar-width: none;
    -ms-overflow-style: none;
}
.crd-subpanels::-webkit-scrollbar {
    display: none;
    width: 0;
    height: 0;
}
.crd-panel {
    display: none;
    flex-direction: column;
    gap: 3px;
}
.crd-panel.active {
    display: flex;
}
.crd-item-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 8.5px 11px;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text);
    cursor: pointer;
    transition: all 0.15s ease;
}
.crd-item-row:hover {
    background: #d1904b !important;
    color: #141418 !important;
    font-weight: 700 !important;
}
.crd-item-row.selected {
    background: rgba(209, 144, 75, 0.2);
    color: #d1904b;
}
.crd-item-row.disabled-in-recipe {
    opacity: 0.4 !important;
    cursor: not-allowed !important;
    background: transparent !important;
}
.crd-item-row.disabled-in-recipe .crd-item-meta::after {
    content: " (In recipe)";
    font-size: 9.5px;
    color: #ef4444;
    font-weight: 700;
}
.highlight-duplicate {
    animation: flashRowDup 1.2s ease-in-out;
}
@keyframes flashRowDup {
    0%, 100% { background: transparent; }
    25%, 75% { background: rgba(239, 68, 68, 0.25); outline: 2px solid #ef4444; }
    50% { background: transparent; }
}
.crd-item-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.crd-item-meta {
    font-size: 10.5px;
    font-weight: 700;
    opacity: 0.75;
    flex-shrink: 0;
}
.crd-item-row:hover .crd-item-meta {
    opacity: 1;
    color: #141418;
}
.crd-empty-msg {
    font-size: 11.5px;
    color: #7d7d8e;
    text-align: center;
    padding: 24px 8px;
    font-style: italic;
}

/* Light Mode Overrides for CRD */
[data-theme="light"] .crd-btn {
    background: #ffffff !important;
    border-color: #d0c5b5 !important;
    color: #1a1410 !important;
}
[data-theme="light"] .crd-btn:hover, [data-theme="light"] .crd-btn:focus {
    border-color: #d1904b !important;
    background: #fdfaf6 !important;
}
[data-theme="light"] .crd-popover {
    background: #ffffff !important;
    border-color: #dcd4c8 !important;
    box-shadow: 0 16px 36px rgba(0, 0, 0, 0.14), 0 0 0 1px rgba(0,0,0,0.05) !important;
}
[data-theme="light"] .crd-categories {
    background: #f6f1ea !important;
    border-right-color: #e5ddd2 !important;
}
[data-theme="light"] .crd-cat-item {
    color: #635446 !important;
}
[data-theme="light"] .crd-cat-item:hover, [data-theme="light"] .crd-cat-item.active {
    background: rgba(209, 144, 75, 0.2) !important;
    color: #935610 !important;
}
[data-theme="light"] .crd-cat-count {
    background: rgba(0, 0, 0, 0.06) !important;
    color: #635446 !important;
}
[data-theme="light"] .crd-cat-item:hover .crd-cat-count, [data-theme="light"] .crd-cat-item.active .crd-cat-count {
    background: rgba(209, 144, 75, 0.28) !important;
    color: #935610 !important;
}
[data-theme="light"] .crd-item-row {
    color: #1a1410 !important;
}
[data-theme="light"] .crd-item-row:hover {
    background: #d1904b !important;
    color: #ffffff !important;
}
[data-theme="light"] .crd-item-row.selected {
    background: rgba(209, 144, 75, 0.15) !important;
    color: #935610 !important;
}
[data-theme="light"] .crd-item-row:hover .crd-item-meta {
    color: #ffffff !important;
}

/* ══ Unified Identical-Size Quantity & Unit Input Box ══ */
.qty-unit-group {
    display: inline-flex;
    align-items: center;
    width: 104px;
    height: 38px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: #141418;
    overflow: hidden;
    transition: border-color 0.2s, box-shadow 0.2s;
    box-sizing: border-box;
}
.qty-unit-group:focus-within {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(209, 144, 75, 0.15);
}
.qty-input-field {
    width: 62px !important;
    height: 100% !important;
    border: none !important;
    background: transparent !important;
    text-align: center !important;
    font-weight: 700 !important;
    font-size: 13px !important;
    color: var(--text) !important;
    padding: 0 4px !important;
    outline: none !important;
    box-shadow: none !important;
}
.qty-unit-addon {
    width: 42px;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(209, 144, 75, 0.12);
    border-left: 1px solid var(--border);
    color: #d1904b;
    font-size: 11px;
    font-weight: 700;
    text-transform: lowercase;
    flex-shrink: 0;
    user-select: none;
    box-sizing: border-box;
}

/* Light Mode Overrides for Unified Qty Group */
html[data-theme="light"] .qty-unit-group,
[data-theme="light"] .qty-unit-group {
    background: #f4efe9 !important;
    border-color: #d0c5b5 !important;
}
html[data-theme="light"] .qty-input-field,
[data-theme="light"] .qty-input-field {
    color: #1a1410 !important;
}
html[data-theme="light"] .qty-unit-addon,
[data-theme="light"] .qty-unit-addon {
    background: #ede8e0 !important;
    border-left: 1px solid #d0c5b5 !important;
    color: #b8732d !important;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Kantumruy Pro', Poppins, -apple-system, BlinkMacSystemFont, sans-serif;
    background: #0d1117;
    color: #1e293b;
    min-height: 100vh;
    font-size: 13.5px;
}

/* ── MODAL DIALOG CONTAINER ── */
.modal-dialog-box {
    background: #ffffff !important;
    border-radius: 24px !important;
    border: 1px solid #e2e8f0 !important;
    box-shadow: 0 25px 60px -15px rgba(15, 23, 42, 0.45) !important;
    color: #1e293b !important;
    overflow: hidden !important;
    transition: max-width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.modal-dialog-box.direct-drink-mode {
    max-width: 860px !important;
}

/* ── MODAL HEADER ── */
.modal-hdr-dark {
    background: #141724 !important;
    padding: 18px 26px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

/* ── 3-COLUMN RESPONSIVE LAYOUT ── */
.page-wrap {
    max-width: 100%;
    margin: 0 auto;
    padding: 0;
    display: grid;
    grid-template-columns: 230px 310px 1fr;
    gap: 18px;
    align-items: stretch;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
@media (min-width: 1280px) {
    .page-wrap {
        grid-template-columns: 240px 330px 1fr;
    }
}
@media (max-width: 1080px) {
    .page-wrap { grid-template-columns: 1fr; }
}
.page-wrap.direct-drink-mode {
    grid-template-columns: 260px minmax(360px, 480px);
    max-width: 820px;
    margin: 0 auto;
    justify-content: center;
}
.page-wrap.direct-drink-mode #recipeColumnWrap,
.page-wrap.direct-drink-mode .recipe-column-wrap {
    display: none !important;
}

/* ── WHITE SECTION CARDS ── */
.section-card {
    background: #ffffff !important;
    border: 1.5px solid #eef1f6 !important;
    border-radius: 20px !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02) !important;
    overflow: hidden !important;
    display: flex;
    flex-direction: column;
}
.section-head {
    padding: 14px 18px !important;
    border-bottom: 1px solid #f1f4f9 !important;
    background: #ffffff !important;
    border-top-left-radius: 20px;
    border-top-right-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.section-head-title {
    font-size: 13.5px;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 8px;
}
.section-icon-badge {
    width: 28px;
    height: 28px;
    border-radius: 10px;
    background: #eff2fe;
    color: #6366f1;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12.5px;
}
.section-body {
    padding: 18px;
    display: flex;
    flex-direction: column;
    gap: 14px;
    flex: 1;
}

/* ── IMAGE DROPZONE ── */
.no-image {
    min-height: 230px;
    width: 100%;
    border-radius: 18px;
    background: #fafbfc;
    border: 2px dashed #dbe2ea;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    cursor: pointer;
    transition: all .2s ease;
    padding: 20px 14px;
    text-align: center;
}
.no-image:hover, .no-image.drag-over {
    border-color: #6366f1;
    background: #f5f7ff;
}
.img-cloud-circle {
    width: 48px;
    height: 48px;
    border-radius: 16px;
    background: #eff2fe;
    color: #6366f1;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    margin-bottom: 6px;
    box-shadow: 0 2px 6px rgba(99, 102, 241, 0.12);
}
.img-preview-wrap {
    height: 230px;
    width: 100%;
    border-radius: 18px;
    overflow: hidden;
    background: #fafbfc;
    border: 1.5px solid #eef1f6;
    position: relative;
    cursor: pointer;
}
.img-preview-wrap img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
}
.img-overlay {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.6);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    opacity: 0;
    transition: opacity .2s ease;
    color: #ffffff;
    font-size: 12px;
    font-weight: 600;
}
.img-preview-wrap:hover .img-overlay { opacity: 1; }
.img-file-status {
    background: #f1f4f9;
    border-radius: 9999px;
    padding: 7px 14px;
    text-align: center;
    color: #94a3b8;
    font-size: 11px;
    font-weight: 500;
}

/* ── PRODUCT TYPE SWITCHER ── */
.pt-segment {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 5px;
    background: #f1f4f9;
    padding: 5px;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    margin-bottom: 2px;
}
.pt-btn {
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 14px;
    border-radius: 12px;
    background: transparent;
    border: 1.5px solid transparent;
    color: #64748b;
    cursor: pointer;
    transition: all 0.2s ease;
    position: relative;
    user-select: none;
    text-align: center;
}
.pt-btn .pt-icon {
    font-size: 14px;
    display: inline-block;
}
.pt-btn .pt-icon.tilted {
    transform: rotate(-35deg);
}
.pt-btn .pt-title {
    font-size: 12.5px;
    font-weight: 700;
    line-height: 1.2;
}
.pt-btn:hover:not(.active) {
    background: rgba(0, 0, 0, 0.04);
    color: #334155;
}
.pt-btn.active {
    background: #4f46e5 !important;
    color: #ffffff !important;
    border-color: #4f46e5 !important;
    box-shadow: 0 4px 14px rgba(79, 70, 229, 0.35) !important;
}
.pt-btn.active .pt-icon,
.pt-btn.active .pt-title {
    color: #ffffff !important;
}

/* ── INPUT FIELDS ── */
.field { display: flex; flex-direction: column; gap: 5px; }
label.flabel {
    font-size: 12px;
    font-weight: 700;
    color: #334155;
    letter-spacing: 0.1px;
}
.flabel .req { color: #ef4444; }
.input-wrap { position: relative; }
.input-wrap .prefix {
    position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
    color: #94a3b8; font-size: 14px; font-weight: 600; pointer-events: none;
}
.field input[type=text],
.field input[type=number],
.field select,
.input-wrap input,
#f_name,
#f_cat,
#f_price,
#f_price_dd,
#f_direct_cost {
    display: block !important;
    width: 100% !important;
    height: 48px !important;
    min-height: 48px !important;
    padding: 12px 16px !important;
    font-size: 14px !important;
    font-weight: 500 !important;
    border-radius: 14px !important;
    border: 1.5px solid #e0d4c4 !important;
    background-color: #ede8e0 !important;
    color: #1a1410 !important;
    transition: border-color .18s, box-shadow .18s !important;
    outline: none !important;
    box-sizing: border-box !important;
}
textarea {
    width: 100% !important;
    height: auto !important;
    min-height: 80px !important;
    padding: 12px 16px !important;
    font-size: 14px !important;
    font-weight: 500 !important;
    border-radius: 14px !important;
    border: 1.5px solid #e0d4c4 !important;
    background-color: #ede8e0 !important;
    color: #1a1410 !important;
    transition: border-color .18s, box-shadow .18s !important;
    outline: none !important;
    box-sizing: border-box !important;
}
.input-wrap input[type=number] { padding-left: 32px !important; }
.input-wrap .prefix {
    position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
    color: #8c827a; font-size: 15px; font-weight: 700; pointer-events: none; z-index: 2;
}
.field input:focus, .field select:focus, textarea:focus, .input-wrap input:focus {
    border-color: #4f46e5 !important;
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.14) !important;
}
input::placeholder, textarea::placeholder { color: #8c827a !important; opacity: 0.8; }

#f_cat, select.cat-select {
    display: block !important;
    width: 100% !important;
    height: 48px !important;
    min-height: 48px !important;
    padding: 12px 44px 12px 16px !important;
    font-size: 14px !important;
    font-weight: 500 !important;
    border-radius: 14px !important;
    border: 1.5px solid #e0d4c4 !important;
    appearance: none !important;
    -webkit-appearance: none !important;
    -moz-appearance: none !important;
    cursor: pointer !important;
    line-height: 1.5 !important;
    background-color: #ede8e0 !important;
    color: #1a1410 !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%235a4a3a' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: calc(100% - 16px) center !important;
    background-size: 16px 16px !important;
    transition: border-color .18s, box-shadow .18s !important;
    box-sizing: border-box !important;
}

/* Dark mode overrides */
html:not([data-theme="light"]) .field input[type=text],
html:not([data-theme="light"]) .field input[type=number],
html:not([data-theme="light"]) .field select,
html:not([data-theme="light"]) .field textarea,
html:not([data-theme="light"]) .input-wrap input,
html:not([data-theme="light"]) #f_name,
html:not([data-theme="light"]) #f_cat,
html:not([data-theme="light"]) #f_price,
html:not([data-theme="light"]) #f_price_dd,
html:not([data-theme="light"]) #f_direct_cost {
    background-color: #141418 !important;
    border-color: #2b2b36 !important;
    color: #f0f0f0 !important;
}
html:not([data-theme="light"]) #f_cat,
html:not([data-theme="light"]) select.cat-select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") !important;
}

/* ── TOGGLE SWITCH ── */
.toggle-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 2px 0;
}
.toggle-info h4 { font-size: 12.5px; font-weight: 700; color: #1e293b; }
.toggle-info p { font-size: 11px; color: #94a3b8; margin-top: 1px; }
.toggle-switch {
    position: relative; display: inline-block; width: 44px; height: 24px;
}
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-track {
    position: absolute; cursor: pointer; inset: 0;
    background-color: #cbd5e1; border-radius: 9999px; transition: .25s;
}
.toggle-track::before {
    position: absolute; content: ""; height: 18px; width: 18px;
    left: 3px; bottom: 3px; background-color: #ffffff;
    border-radius: 50%; transition: .25s;
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
.toggle-switch input:checked + .toggle-track { background-color: #10b981; }
.toggle-switch input:checked + .toggle-track::before { transform: translateX(20px); }

/* ── BOM CHIP BUTTONS ── */
.btn-chip-pkg {
    display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px;
    border-radius: 9999px; background: #fef3c7; border: 1px solid #fde68a;
    color: #d97706; font-size: 11px; font-weight: 700; cursor: pointer;
    transition: all .2s ease;
}
.btn-chip-pkg:hover { background: #fde68a; }
.btn-chip-ing {
    display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px;
    border-radius: 9999px; background: #ede9fe; border: 1px solid #ddd6fe;
    color: #6366f1; font-size: 11px; font-weight: 700; cursor: pointer;
    transition: all .2s ease;
}
.btn-chip-ing:hover { background: #ddd6fe; }

/* ── RECIPE TABLE ── */
.recipe-table-wrap {
    border: 1px solid #e2e8f0; border-radius: 16px; background: #f8fafc; overflow: hidden;
}
.recipe-thead {
    background: #f8fafc; color: #64748b; font-size: 10.5px; font-weight: 700;
}
.recipe-tbody { background: #ffffff; }
.no-recipe-box {
    padding: 32px 16px; text-align: center;
}

/* ── RECIPE SUMMARY BAR ── */
.recipe-summary-box {
    background: #0f1422;
    border-radius: 16px;
    padding: 14px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: #ffffff;
}
.summary-label {
    font-size: 10px;
    font-weight: 700;
    color: #94a3b8;
    letter-spacing: 0.3px;
    text-transform: uppercase;
}
.selling-price-disp { color: #ffffff; }

/* ── DIRECT DRINK INFO CARD ── */
.dd-info-card {
    background: #f0f9ff;
    border: 1px solid #bae6fd;
    border-radius: 14px;
    padding: 10px 14px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    font-size: 11.5px;
    color: #0369a1;
    line-height: 1.4;
}

/* ── MODAL FOOTER BAR ── */
.modal-footer-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 24px;
    margin-top: 6px;
    border-top: 1px solid #f1f4f9;
}
.btn-cancel-link {
    color: #64748b;
    font-weight: 700;
    font-size: 13px;
    padding: 8px 14px;
    border-radius: 12px;
    transition: all .2s;
    text-decoration: none;
}
.btn-cancel-link:hover {
    color: #0f172a;
    background: #f1f5f9;
}
.btn-save-draft {
    background: #f1f4f9;
    color: #475569;
    font-weight: 700;
    font-size: 13px;
    padding: 10px 18px;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    cursor: pointer;
    transition: all .2s;
}
.btn-save-draft:hover {
    background: #e2e8f0;
    color: #1e293b;
}
.btn-submit-primary {
    background: #4f46e5;
    color: #ffffff;
    font-weight: 700;
    font-size: 13px;
    padding: 11px 24px;
    border-radius: 14px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 4px 14px rgba(79, 70, 229, 0.4);
    transition: all .2s;
}
.btn-submit-primary:hover {
    background: #4338ca;
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(79, 70, 229, 0.45);
}

@keyframes scaleUp {
    from { opacity: 0; transform: scale(0.96); }
    to { opacity: 1; transform: scale(1); }
}
.animate-scaleUp { animation: scaleUp 0.22s cubic-bezier(0.16, 1, 0.3, 1) both; }
</style>
<link rel="stylesheet" href="assets/css/product_cropper.css">
</head>
<body>
<?php if (empty($_GET['modal'])): ?>
<div class="flex h-screen w-screen overflow-hidden app-layout">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<main class="app-main flex-1 h-full overflow-y-auto p-4 md:p-6 relative">
<?php endif; ?>

<!-- EDIT PRODUCT MODAL BACKDROP -->
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 md:p-6 bg-slate-950/70 backdrop-blur-sm overflow-y-auto">
    <!-- MODAL DIALOG CONTAINER -->
    <div id="editModalDialog" class="modal-dialog-box relative w-[96vw] max-w-[1380px] bg-white rounded-[20px] shadow-2xl flex flex-col my-auto animate-scaleUp">
        
        <!-- MODAL HEADER -->
        <div class="modal-hdr-dark shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-[#232738] flex items-center justify-center text-white text-base shadow-sm">
                    <i class="fa-solid fa-pen-to-square"></i>
                </div>
                <div>
                    <h2 class="text-base font-bold text-white leading-tight flex items-center gap-1.5">
                        <span>កែប្រែមុខទំនិញ (Edit Product)</span>
                        <span id="nav-name-badge" class="text-indigo-400 font-bold ml-1"><?= htmlspecialchars($product['name']) ?></span>
                    </h2>
                    <p class="text-xs text-[#8c93a8] font-normal mt-0.5">
                        កំណត់រូបភាព ព័ត៌មានទំនិញ និងរូបមន្តផ្សំគ្រឿងផ្សំ (BOM)
                    </p>
                </div>
            </div>
            
            <a href="products.php" onclick="closeEditModal(event)" class="w-8 h-8 rounded-lg bg-[#232738] text-[#8c93a8] hover:text-white hover:bg-[#2d3246] flex items-center justify-center transition-all" title="Close Modal (Esc)">
                <i class="fa-solid fa-xmark text-sm"></i>
            </a>
        </div>

        <!-- MODAL BODY -->
        <div class="p-5 md:p-6 bg-white" style="overflow: visible !important;">
            <form method="POST" enctype="multipart/form-data" id="editForm">
                <input type="hidden" name="update_product" value="1">
                <input type="file" name="image" id="imgInput" accept="image/*" style="display:none">
                <input type="hidden" name="cost_price" id="f_cost_price" value="<?= $product['cost_price'] ?? '0.00' ?>">
                <input type="hidden" name="product_type" id="product_type_input" value="recipe">

                <div class="page-wrap" id="editPageWrap">
                    
                    <!-- ════ COLUMN 1: PRODUCT IMAGE ════ -->
                    <div class="section-card">
                        <div class="section-head">
                            <div class="section-head-title">
                                <div class="section-icon-badge"><i class="fa-regular fa-image"></i></div>
                                <span>រូបភាពទំនិញ (Product Image)</span>
                            </div>
                        </div>
                        <div class="section-body flex flex-col justify-between">
                            <div class="image-panel">
                                <?php 
                                $hasImg = !empty($product['image']) && (str_starts_with($product['image'], 'http') || file_exists($product['image']) || file_exists('uploads/' . $product['image']));
                                $prodImgSrc = !empty($product['image']) ? get_image_url($product['image']) : '';
                                ?>
                                <div class="img-preview-wrap flex items-center justify-center cursor-pointer" id="imgWrap" onclick="document.getElementById('imgInput').click()" style="height:220px; background:#fafbfc; border:2px dashed #dbe2ea; border-radius:18px;">
                                    <?php if ($hasImg): ?>
                                     <img src="<?= htmlspecialchars($prodImgSrc) ?>" alt="Product image" id="imgPreview" class="max-h-[190px] max-w-[85%] object-contain">
                                    <div class="img-overlay">
                                        <i class="fa-solid fa-camera text-xl mb-1"></i>
                                        <span>ប្តូររូបភាព (Replace Image)</span>
                                    </div>
                                    <?php else: ?>
                                    <div class="flex flex-col items-center justify-center gap-2 p-4 text-center">
                                        <div class="img-cloud-circle">
                                            <i class="fa-solid fa-cloud-arrow-up"></i>
                                        </div>
                                        <span class="font-bold text-slate-700 text-xs">ចុច ឬទម្លាក់រូបភាពនៅទីនេះ</span>
                                        <span class="text-[11px] text-slate-400">PNG, JPG ឬ WEBP (អតិបរមា 2MB)</span>
                                    </div>
                                    <img id="imgPreview" style="display:none" class="max-h-[190px] max-w-[85%] object-contain">
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="img-file-status" id="imgHelpText">
                                រូបភាពបច្ចុប្បន្ន — ចុចខាងលើដើម្បីផ្លាស់ប្តូរ
                            </div>
                        </div>
                    </div>

                    <!-- ════ COLUMN 2: PRODUCT DETAILS ════ -->
                    <div class="section-card">
                        <div class="section-head">
                            <div class="section-head-title">
                                <div class="section-icon-badge"><i class="fa-solid fa-sliders"></i></div>
                                <span>ព័ត៌មានទំនិញ (Product Details)</span>
                            </div>
                        </div>
                        <div class="section-body flex flex-col gap-3.5">
                            
                            <!-- PRODUCT TYPE SELECTOR -->
                            <div class="field">
                                <label class="flabel">ប្រភេទផលិតកម្ម (PRODUCT TYPE) <span class="req">*</span></label>
                                <div class="pt-segment">
                                    <button type="button" class="pt-btn active" id="ptBtnRecipe" onclick="setProductType('recipe')">
                                        <i class="fa-solid fa-mug-hot pt-icon"></i>
                                        <span class="pt-title">កែច្នៃផ្ទាល់</span>
                                    </button>
                                    <button type="button" class="pt-btn" id="ptBtnDirect" onclick="setProductType('direct_drink')">
                                        <i class="fa-solid fa-box-archive pt-icon"></i>
                                        <span class="pt-title">ទំនិញស្រាប់</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Direct Drink Info Notice -->
                            <div id="directDrinkInfoCard" class="dd-info-card" style="display:none;">
                                <i class="fa-solid fa-circle-info text-base text-sky-500 mt-0.5 shrink-0"></i>
                                <div>
                                    <strong class="text-sky-900 block font-semibold text-xs">Direct Drink Stock Item:</strong>
                                    <span class="text-[11.5px] text-sky-800">ចំនួនស្តុកនឹងកាត់ចេញដោយផ្ទាល់តាមចំនួនកំប៉ុង/ដប។ មិនចាំបាច់កំណត់រូបមន្តផ្សំ (BOM) ឡើយ។</span>
                                </div>
                            </div>

                            <div class="field">
                                <label class="flabel" for="f_name">ឈ្មោះទំនិញ (PRODUCT NAME) <span class="req">*</span></label>

                                <?php if (!empty($directDrinkList)): ?>
                                <!-- Direct Drink Quick Selector (Only shown for Direct Drink product type) -->
                                <div id="directDrinkPickerWrap" style="display:none;">
                                    <select id="directDrinkSelect" onchange="onSelectDirectDrink(this)" class="w-full text-xs font-semibold p-2.5 rounded-xl bg-slate-50 border border-slate-200 text-slate-800 outline-none focus:border-indigo-500">
                                        <option value="">-- ជ្រើសរើសទំនិញពីស្តុក (Select Stock Drink) --</option>
                                        <?php foreach ($directDrinkList as $dd): ?>
                                        <option value="<?= htmlspecialchars($dd['name']) ?>" 
                                                data-cost="<?= $dd['cost'] ?>" 
                                                data-qty="<?= (int)$dd['qty'] ?>" 
                                                data-unit="<?= htmlspecialchars($dd['unit']) ?>"
                                                <?= (strtolower($product['name'] ?? '') === strtolower($dd['name'])) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($dd['name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>

                                <div class="relative" id="recipeNameWrap">
                                    <input type="text" id="f_name" name="name" required maxlength="120"
                                        value="<?= htmlspecialchars($product['name']) ?>"
                                        placeholder="ឧ. Vital 500ml"
                                        autocomplete="off" oninput="checkDirectStockMatch(this.value); const b = document.getElementById('nav-name-badge'); if(b) b.textContent = this.value || 'Product';">
                                </div>
                            </div>

                            <!-- CATEGORY -->
                            <div class="field">
                                <label class="flabel" for="f_cat">ក្រុមប្រភេទ (CATEGORY) <span class="req">*</span></label>
                                <select name="category" id="f_cat" class="cat-select" required>
                                    <option value="">Select Category…</option>
                                    <?php foreach ($cats as $slug => $label): ?>
                                    <option value="<?= htmlspecialchars($slug) ?>" <?= ($product['category'] === $slug) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- MADE-TO-ORDER SELLING PRICE FIELD -->
                            <div class="field" id="recipePriceWrap">
                                <label class="flabel" for="f_price">តម្លៃលក់ (SELLING PRICE) <span class="req">*</span></label>
                                <div class="input-wrap">
                                    <span class="prefix">$</span>
                                    <input type="number" id="f_price" name="price" step="0.01" min="0" max="9999.99"
                                        required class="has-prefix font-bold text-slate-800"
                                        value="<?= htmlspecialchars($product['price']) ?>"
                                        oninput="syncSellingPrice(this.value)">
                                </div>
                            </div>

                            <!-- DIRECT DRINK 2-COLUMN PRICES ROW -->
                            <div id="directPricesRow" class="grid grid-cols-2 gap-3.5" style="display:none;">
                                <div class="field">
                                    <label class="flabel" for="f_price_dd">តម្លៃលក់ (Selling Price) <span class="req">*</span></label>
                                    <div class="input-wrap">
                                        <span class="prefix">$</span>
                                        <input type="number" id="f_price_dd" step="0.01" min="0" max="9999.99" class="has-prefix font-bold text-slate-800" value="<?= htmlspecialchars($product['price']) ?>" oninput="syncSellingPrice(this.value)">
                                    </div>
                                </div>
                                <div class="field">
                                    <label class="flabel" for="f_direct_cost">ថ្លៃដើមទិញចូល (Purchase Cost) <span class="req">*</span></label>
                                    <div class="input-wrap">
                                        <span class="prefix">$</span>
                                        <input type="number" id="f_direct_cost" step="0.01" min="0" max="9999.99" class="has-prefix font-bold text-slate-800" value="<?= $product['cost_price'] > 0 ? htmlspecialchars($product['cost_price']) : '' ?>" placeholder="0.00" oninput="onDirectCostChange(this.value)">
                                    </div>
                                </div>
                            </div>

                            <!-- DIRECT DRINK PROFIT MARGIN HIGHLIGHT CARD -->
                            <div id="directMarginCard" class="w-full bg-[#ecfdf5] border border-[#a7f3d0] rounded-xl px-4 py-3 flex items-center justify-between" style="display:none;">
                                <span class="text-xs font-bold text-[#065f46]">ចំណេញដុល (Profit Margin):</span>
                                <span id="directMarginDisp" class="text-sm font-black text-[#059669]">+$0.00 (+0.0%)</span>
                            </div>

                            <!-- AVAILABILITY -->
                            <div class="field mt-1">
                                <div class="toggle-row">
                                    <div class="toggle-info">
                                        <h4 class="font-bold text-slate-800 text-xs">បង្ហាញលើម៉ឺនុយ (Show on Menu)</h4>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="is_available" id="availToggle" value="1" <?= $product['is_available'] ? 'checked' : '' ?>>
                                        <span class="toggle-track"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ════ COLUMN 3: RECIPE & INVENTORY (BILL OF MATERIALS - BOM) ════ -->
                    <div class="section-card flex-1 recipe-column-wrap" id="recipeColumnWrap">
                        <div class="section-head">
                            <div class="section-head-title">
                                <div class="section-icon-badge"><i class="fa-solid fa-flask"></i></div>
                                <span>រូបមន្ត & គ្រឿងផ្សំ (BOM)</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button" onclick="addPackagingSetRow()" class="btn-chip-pkg" title="Add default packaging set (Cup + Lid + Straw + Sleeve)">
                                    <i class="fa-solid fa-box-open text-xs"></i> + ឈុតវេចខ្ចប់
                                </button>
                                <button type="button" onclick="addRecipeRow()" class="btn-chip-ing">
                                    <i class="fa-solid fa-plus text-xs"></i> + ថែមគ្រឿងផ្សំ
                                </button>
                            </div>
                        </div>
                        <div class="section-body flex flex-col justify-between">
                            
                            <!-- Recipe Table -->
                            <div class="recipe-table-wrap overflow-x-auto">
                                <table class="w-full text-left text-xs recipe-table">
                                    <thead class="recipe-thead uppercase tracking-wider">
                                        <tr>
                                            <th class="py-2.5 px-3">គ្រឿងផ្សំ (RAW MATERIAL)</th>
                                            <th class="py-2.5 px-2 text-center w-28">ចំនួន</th>
                                            <th class="py-2.5 px-2 text-right">តម្លៃរាយ</th>
                                            <th class="py-2.5 px-2 text-right">សរុប</th>
                                            <th class="py-2.5 px-2 text-center w-10"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="recipeRowsContainer" class="recipe-tbody divide-y divide-slate-100">
                                        <!-- Pre-populated from database -->
                                        <?php if (!empty($productIngredients)): ?>
                                            <?php foreach ($productIngredients as $pi): 
                                                $ingCpu = (float)$pi['cost_per_unit'];
                                                $reqQty = (float)$pi['quantity_required'];
                                                $lineCost = $ingCpu * $reqQty;
                                                $isPkg = (str_contains(strtolower($pi['ingredient_name'] ?? $pi['item_name'] ?? ''), 'packaging set') || str_contains($pi['ingredient_name'] ?? $pi['item_name'] ?? '', 'ឈុត'));
                                            ?>
                                            <tr class="recipe-row">
                                                <td class="py-2 px-3">
                                                    <select name="recipe_ingredient_id[]" class="recipe-select" style="display:none;" onchange="updateRecipeRow(this)" required>
                                                        <?= renderIngredientOptGroups($allIngredients, $pi['item_id']) ?>
                                                    </select>
                                                    <?= renderCustomRecipeDropdown($allIngredients, $pi['item_id']) ?>
                                                </td>
                                                <td class="py-2 px-2 text-center">
                                                    <div class="qty-unit-group mx-auto <?= $isPkg ? 'opacity-80' : '' ?>">
                                                        <input type="number" step="any" min="0.01" name="recipe_amount_used[]" value="<?= $isPkg ? '1' : $reqQty ?>" placeholder="1" class="qty-input-field" oninput="calculateRowTotal(this)" <?= $isPkg ? 'readonly title="Fixed 1 set per drink"' : '' ?> required>
                                                        <span class="qty-unit-addon unit-label"><?= htmlspecialchars($pi['unit']) ?></span>
                                                    </div>
                                                </td>
                                                <td class="py-2 px-2 text-right text-[11px]">
                                                    <span class="unit-price-label font-semibold text-slate-700">$<?= ($ingCpu < 0.01 && $ingCpu > 0) ? rtrim(rtrim(number_format($ingCpu, 4), '0'), '.') : number_format($ingCpu, 2) ?></span><span class="unit-name-label text-slate-400">/<?= htmlspecialchars($pi['unit']) ?></span>
                                                </td>
                                                <td class="py-2 px-2 text-right text-xs font-bold text-emerald-600">
                                                    $<span class="row-total-label"><?= number_format($lineCost, 2) ?></span>
                                                </td>
                                                <td class="py-2 px-2 text-center">
                                                    <button type="button" onclick="this.closest('.recipe-row').remove(); calculateTotalRecipeCost(); checkEmptyRecipe();" class="text-slate-400 hover:text-red-500 p-1.5 rounded-lg hover:bg-red-50 transition-all" title="Remove ingredient">
                                                        <i class="fa-solid fa-trash-can text-xs"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>

                                <!-- Empty State -->
                                <div id="noRecipeMsg" class="no-recipe-box <?= !empty($productIngredients) ? 'hidden' : 'flex' ?> flex-col items-center justify-center">
                                    <div class="w-12 h-12 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mb-2">
                                        <i class="fa-solid fa-mortar-pestle text-xl"></i>
                                    </div>
                                    <p class="text-xs font-bold text-slate-700">មិនទាន់មានគ្រឿងផ្សំនៅឡើយ</p>
                                    <p class="text-[11px] text-slate-400 mt-0.5">ចុច "+ ថែមគ្រឿងផ្សំ" ដើម្បីគណនាតម្លៃដើមផលិតផល (COGS)</p>
                                </div>
                            </div>

                            <!-- Live Recipe COGS & Gross Profit Calculator -->
                            <div class="recipe-summary-box">
                                <div class="space-y-0.5">
                                    <div class="summary-label">ថ្លៃដើម (COGS)</div>
                                    <div class="text-lg font-black text-[#f59e0b]">$<span id="totalRecipeCogs">0.00</span></div>
                                </div>
                                <div class="space-y-0.5 text-center">
                                    <div class="summary-label">តម្លៃលក់</div>
                                    <div class="text-lg font-black text-white">$<span id="dispSellingPrice"><?= number_format($product['price'], 2) ?></span></div>
                                </div>
                                <div class="space-y-0.5 text-right">
                                    <div class="summary-label">ប្រាក់ចំណេញ (MARGIN)</div>
                                    <div id="grossMarginWrap" class="text-lg font-black text-[#10b981]">
                                        <span id="grossMarginDol">$0.00</span>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                </div><!-- /.page-wrap -->

                <!-- MODAL BOTTOM ACTION BAR -->
                <div class="modal-footer-bar">
                    <div>
                        <a href="products.php" onclick="closeEditModal(event)" class="btn-cancel-link">
                            បោះបង់ (Cancel)
                        </a>
                    </div>
                    <div class="flex items-center gap-3">
                        <?php if (!empty($product['id'])): ?>
                        <button type="button" onclick="confirmDeleteProduct(<?= (int)$product['id'] ?>, '<?= addslashes($product['name']) ?>')" class="text-rose-500 hover:text-rose-700 font-bold text-xs flex items-center gap-1.5 px-3 py-2 rounded-lg hover:bg-rose-50 transition-all border-none bg-transparent cursor-pointer mr-2">
                            <i class="fa-regular fa-trash-can text-sm"></i> លុបទំនិញនេះ
                        </button>
                        <?php endif; ?>
                        <button type="button" onclick="closeEditModal(event)" class="btn-save-draft">
                            រក្សាទុកជាព្រាង (Save Draft)
                        </button>
                        <button type="submit" name="update_product" class="btn-submit-primary">
                            <i class="fa-solid fa-check"></i> រក្សាទុកការផ្លាស់ប្តូរ (Save Changes)
                        </button>
                    </div>
                </div>
            </form>
        </div><!-- /.modal-body -->
    </div><!-- /.modal-dialog -->
</div><!-- /.modal-backdrop -->
<?php if (empty($_GET['modal'])): ?>
</main>
</div>
<?php endif; ?>

<!-- TOAST -->
<div class="toast" id="toast">
    <i class="fa-solid fa-circle-check"></i>
    Product & recipe updated successfully!
</div>

<script>
// ── Image drag-drop + preview ──
const imgWrap = document.getElementById('imgWrap');
const imgInput = document.getElementById('imgInput');
const imgPreview = document.getElementById('imgPreview');
const fileInfo = document.getElementById('fileName');

if (imgInput) imgInput.addEventListener('change', handleFile);

if (imgWrap) {
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
}

function handleFile() {
    const file = imgInput.files[0];
    if (!file) return;
    
    if (file._isCropped) {
        if (fileInfo) fileInfo.innerHTML = `<span>${file.name}</span> (Cropped) — ${(file.size/1024).toFixed(1)} KB`;
        const reader = new FileReader();
        reader.onload = e => {
            if (imgPreview) {
                imgPreview.src = e.target.result;
                imgPreview.style.display = 'block';
            }
        };
        reader.readAsDataURL(file);
        return;
    }

    if (typeof openProductCropper === 'function') {
        const reader = new FileReader();
        reader.onload = e => {
            openProductCropper(e.target.result, function(blob, dataUrl, croppedFile) {
                croppedFile._isCropped = true;
                try {
                    const dt = new DataTransfer();
                    dt.items.add(croppedFile);
                    imgInput.files = dt.files;
                } catch (err) {
                    console.warn(err);
                }

                if (fileInfo) fileInfo.innerHTML = `<span>${croppedFile.name}</span> (Cropped) — ${(croppedFile.size/1024).toFixed(1)} KB`;
                if (imgPreview) {
                    imgPreview.src = dataUrl;
                    imgPreview.style.display = 'block';
                }
                if (imgWrap && imgWrap.classList.contains('no-image')) {
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
            }, 1);
        };
        reader.readAsDataURL(file);
    } else {
        if (fileInfo) fileInfo.innerHTML = `<span>${file.name}</span> — ${(file.size/1024).toFixed(1)} KB`;
        const reader = new FileReader();
        reader.onload = e => {
            if (imgPreview) {
                imgPreview.src = e.target.result;
                imgPreview.style.display = 'block';
            }
            if (imgWrap && imgWrap.classList.contains('no-image')) {
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
}

// ── Live preview & input sync ──
const fName  = document.getElementById('f_name');
const fPrice = document.getElementById('f_price');
const navName= document.getElementById('nav-name');

if (fName) {
    fName.addEventListener('input', () => {
        if (navName) navName.textContent = fName.value || 'Edit Product';
    });
}
if (fPrice) {
    fPrice.addEventListener('input', () => {
        calculateTotalRecipeCost();
    });
}

// ── Inline Recipe Rows Engine ──
const allIngredients = <?= json_encode($allIngredients) ?>;

function buildIngredientOptionsHtml(ingId = '') {
    let rawIngs = [];
    let pkgIngs = [];
    let drinkStock = [];
    allIngredients.forEach(i => {
        const isDrink = (i.item_type === 'direct_drink' || i.category === 'Direct Drinks');
        const isPkg = (i.category === 'Packaging' || i.category === 'កែវ & ការវេចខ្ចប់' || (i.ingredient_name && (i.ingredient_name.toLowerCase().includes('packaging') || i.ingredient_name.includes('ឈុត'))));
        if (isDrink) {
            drinkStock.push(i);
        } else if (isPkg) {
            pkgIngs.push(i);
        } else {
            rawIngs.push(i);
        }
    });

    let html = '<option value="">Select ingredient / stock drink…</option>';

    if (rawIngs.length > 0) {
        html += '<optgroup label="🥛 Ingredients / Raw Materials">';
        rawIngs.forEach(i => {
            const sel = (i.ingredient_id == ingId) ? 'selected' : '';
            const cpu = parseFloat(i.cost_per_unit || 0);
            html += `<option value="${i.ingredient_id}" data-unit="${escapeHtml(i.unit)}" data-type="ingredient" data-cpu="${cpu}" ${sel}>${escapeHtml(i.ingredient_name)}</option>`;
        });
        html += '</optgroup>';
    }

    if (pkgIngs.length > 0) {
        html += '<optgroup label="📦 Packaging / Cups & Sets">';
        pkgIngs.forEach(i => {
            const sel = (i.ingredient_id == ingId) ? 'selected' : '';
            const cpu = parseFloat(i.cost_per_unit || 0);
            html += `<option value="${i.ingredient_id}" data-unit="${escapeHtml(i.unit)}" data-type="packaging" data-cpu="${cpu}" ${sel}>${escapeHtml(i.ingredient_name)}</option>`;
        });
        html += '</optgroup>';
    }

    if (drinkStock.length > 0) {
        html += '<optgroup label="🥫 Drink Stock (Cans & Bottles)">';
        drinkStock.forEach(i => {
            const sel = (i.ingredient_id == ingId) ? 'selected' : '';
            const cpu = parseFloat(i.cost_per_unit || 0);
            html += `<option value="${i.ingredient_id}" data-unit="${escapeHtml(i.unit)}" data-type="direct_drink" data-cpu="${cpu}" ${sel}>${escapeHtml(i.ingredient_name)}</option>`;
        });
        html += '</optgroup>';
    }

    return html;
}

function buildCustomRecipeDropdownHtml(ingId = '') {
    let rawIngs = [];
    let pkgIngs = [];
    let drinkStock = [];
    let selectedItem = null;

    allIngredients.forEach(i => {
        const isDrink = (i.item_type === 'direct_drink' || i.category === 'Direct Drinks');
        const isPkg = (i.category === 'Packaging' || i.category === 'កែវ & ការវេចខ្ចប់' || (i.ingredient_name && (i.ingredient_name.toLowerCase().includes('packaging') || i.ingredient_name.includes('ឈុត'))));
        if (isDrink) {
            drinkStock.push(i);
        } else if (isPkg) {
            pkgIngs.push(i);
        } else {
            rawIngs.push(i);
        }
        if (String(i.ingredient_id) === String(ingId)) {
            selectedItem = i;
        }
    });

    const isDrinkSel = selectedItem ? (selectedItem.item_type === 'direct_drink' || selectedItem.category === 'Direct Drinks') : false;
    const isPkgSel = selectedItem ? (selectedItem.category === 'Packaging' || (selectedItem.ingredient_name && (selectedItem.ingredient_name.toLowerCase().includes('packaging') || selectedItem.ingredient_name.includes('ឈុត')))) : false;
    const defaultCat = isDrinkSel ? 'direct_drink' : (isPkgSel ? 'packaging' : 'ingredient');

    let btnIcon = '<i class="fa-solid fa-layer-group text-[#888]"></i>';
    if (selectedItem) {
        if (isDrinkSel) btnIcon = '<i class="fa-solid fa-wine-bottle text-amber-400"></i>';
        else if (isPkgSel) btnIcon = '<i class="fa-solid fa-box-open text-sky-400"></i>';
        else btnIcon = '<i class="fa-solid fa-seedling text-emerald-400"></i>';
    }
    const btnText = selectedItem ? escapeHtml(selectedItem.ingredient_name) : 'Select ingredient / stock drink…';

    let html = `<div class="crd-wrap">
        <button type="button" class="crd-btn" onclick="toggleCrd(this, event)">
            <span class="crd-btn-main"><span class="crd-btn-icon">${btnIcon}</span><span class="crd-btn-text">${btnText}</span></span>
            <i class="fa-solid fa-chevron-down crd-btn-arrow"></i>
        </button>
        <div class="crd-popover">
            <div class="crd-categories">
                <div class="crd-cat-item ${defaultCat === 'ingredient' ? 'active' : ''}" data-cat="ingredient" onmouseenter="crdSwitchCat(this, 'ingredient')">
                    <span class="crd-cat-title"><i class="fa-solid fa-seedling text-emerald-400 mr-1.5"></i> Ingredients</span>
                    <span class="crd-cat-count">${rawIngs.length}</span>
                    <i class="fa-solid fa-chevron-right crd-cat-arrow ml-1"></i>
                </div>
                <div class="crd-cat-item ${defaultCat === 'packaging' ? 'active' : ''}" data-cat="packaging" onmouseenter="crdSwitchCat(this, 'packaging')">
                    <span class="crd-cat-title"><i class="fa-solid fa-box-open text-sky-400 mr-1.5"></i> Packaging</span>
                    <span class="crd-cat-count">${pkgIngs.length}</span>
                    <i class="fa-solid fa-chevron-right crd-cat-arrow ml-1"></i>
                </div>
                <div class="crd-cat-item ${defaultCat === 'direct_drink' ? 'active' : ''}" data-cat="direct_drink" onmouseenter="crdSwitchCat(this, 'direct_drink')">
                    <span class="crd-cat-title"><i class="fa-solid fa-wine-bottle text-amber-400 mr-1.5"></i> Drink Stock</span>
                    <span class="crd-cat-count">${drinkStock.length}</span>
                    <i class="fa-solid fa-chevron-right crd-cat-arrow ml-1"></i>
                </div>
            </div>
            <div class="crd-subpanels">
                <div class="crd-panel crd-panel-ingredient ${defaultCat === 'ingredient' ? 'active' : ''}">`;

    if (rawIngs.length === 0) {
        html += `<div class="crd-empty-msg">No ingredients found</div>`;
    } else {
        rawIngs.forEach(ri => {
            const sel = (String(ri.ingredient_id) === String(ingId)) ? 'selected' : '';
            const cpu = parseFloat(ri.cost_per_unit || 0);
            const cpuStr = (cpu < 0.01 && cpu > 0) ? cpu.toFixed(4).replace(/0+$/, '') : cpu.toFixed(2);
            html += `<div class="crd-item-row ${sel}" data-id="${ri.ingredient_id}" data-unit="${escapeHtml(ri.unit)}" data-cpu="${cpu}" data-type="ingredient" data-name="${escapeHtml(ri.ingredient_name)}" onclick="crdSelectItem(this, event)">
                <span class="crd-item-name">${escapeHtml(ri.ingredient_name)}</span>
                <span class="crd-item-meta">$${cpuStr}/${escapeHtml(ri.unit)}</span>
            </div>`;
        });
    }

    html += `</div>
                <div class="crd-panel crd-panel-packaging ${defaultCat === 'packaging' ? 'active' : ''}">`;

    if (pkgIngs.length === 0) {
        html += `<div class="crd-empty-msg">No packaging items found</div>`;
    } else {
        pkgIngs.forEach(pi => {
            const sel = (String(pi.ingredient_id) === String(ingId)) ? 'selected' : '';
            const cpu = parseFloat(pi.cost_per_unit || 0);
            const cpuStr = (cpu < 0.01 && cpu > 0) ? cpu.toFixed(4).replace(/0+$/, '') : cpu.toFixed(2);
            html += `<div class="crd-item-row ${sel}" data-id="${pi.ingredient_id}" data-unit="${escapeHtml(pi.unit)}" data-cpu="${cpu}" data-type="packaging" data-name="${escapeHtml(pi.ingredient_name)}" onclick="crdSelectItem(this, event)">
                <span class="crd-item-name">${escapeHtml(pi.ingredient_name)}</span>
                <span class="crd-item-meta">$${cpuStr}/${escapeHtml(pi.unit)}</span>
            </div>`;
        });
    }

    html += `</div>
                <div class="crd-panel crd-panel-direct_drink ${defaultCat === 'direct_drink' ? 'active' : ''}">`;

    if (drinkStock.length === 0) {
        html += `<div class="crd-empty-msg">No drink stock found</div>`;
    } else {
        drinkStock.forEach(ds => {
            const sel = (String(ds.ingredient_id) === String(ingId)) ? 'selected' : '';
            const cpu = parseFloat(ds.cost_per_unit || 0);
            const cpuStr = (cpu < 0.01 && cpu > 0) ? cpu.toFixed(4).replace(/0+$/, '') : cpu.toFixed(2);
            html += `<div class="crd-item-row ${sel}" data-id="${ds.ingredient_id}" data-unit="${escapeHtml(ds.unit)}" data-cpu="${cpu}" data-type="direct_drink" data-name="${escapeHtml(ds.ingredient_name)}" onclick="crdSelectItem(this, event)">
                <span class="crd-item-name">${escapeHtml(ds.ingredient_name)}</span>
                <span class="crd-item-meta">$${cpuStr}/${escapeHtml(ds.unit)}</span>
            </div>`;
        });
    }

    html += `</div>
            </div>
        </div>
    </div>`;

    return html;
}

// Toast Notification Helper
function showToast(message, type = 'success') {
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.style.cssText = 'position:fixed;top:20px;right:20px;z-index:999999;display:flex;flex-direction:column;gap:8px;pointer-events:none;';
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.style.cssText = 'background:#1e1e24;color:#fff;border:1px solid #33333e;padding:10px 16px;border-radius:10px;display:flex;align-items:center;gap:10px;font-size:12px;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,0.4);pointer-events:auto;transition:all 0.25s ease;transform:translateY(0);opacity:1;';
    
    let icon = '<i class="fa-solid fa-circle-check text-emerald-400"></i>';
    if (type === 'error') {
        icon = '<i class="fa-solid fa-triangle-exclamation text-rose-400"></i>';
        toast.style.borderColor = 'rgba(244,63,94,0.4)';
    } else if (type === 'warning' || type === 'info') {
        icon = '<i class="fa-solid fa-circle-info text-amber-400"></i>';
        toast.style.borderColor = 'rgba(209,144,75,0.4)';
    }

    toast.innerHTML = `${icon}<span>${escapeHtml(message)}</span>`;
    container.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-10px)';
        setTimeout(() => toast.remove(), 250);
    }, 3000);
}

function refreshCrdDisabledStates() {
    const selectedIds = new Set();
    document.querySelectorAll('.recipe-row select[name="recipe_ingredient_id[]"]').forEach(sel => {
        if (sel.value && sel.value !== '') {
            selectedIds.add(String(sel.value));
        }
    });

    document.querySelectorAll('.crd-wrap').forEach(wrap => {
        const row = wrap.closest('.recipe-row');
        const currentSel = row ? row.querySelector('select[name="recipe_ingredient_id[]"]') : null;
        const currentId = currentSel ? String(currentSel.value) : '';

        wrap.querySelectorAll('.crd-item-row').forEach(itemEl => {
            const itemId = String(itemEl.getAttribute('data-id'));
            if (selectedIds.has(itemId) && itemId !== currentId) {
                itemEl.classList.add('disabled-in-recipe');
                itemEl.setAttribute('title', 'Already added to recipe');
            } else {
                itemEl.classList.remove('disabled-in-recipe');
                itemEl.removeAttribute('title');
            }
        });
    });
}

function addPackagingSetRow() {
    // Check if packaging set already in recipe table
    let existingRow = null;
    document.querySelectorAll('.recipe-row').forEach(row => {
        const sel = row.querySelector('select[name="recipe_ingredient_id[]"]');
        if (sel && sel.options[sel.selectedIndex]) {
            const txt = (sel.options[sel.selectedIndex].textContent || '').toLowerCase();
            if (txt.includes('packaging set') || txt.includes('ឈុត')) {
                existingRow = row;
            }
        }
    });

    if (existingRow) {
        existingRow.classList.add('highlight-duplicate');
        setTimeout(() => existingRow.classList.remove('highlight-duplicate'), 1200);
        showToast('Packaging Set is already added to this recipe (1 set per drink).', 'info');
        return;
    }

    // Find the packaging set item from allIngredients
    const pkgItem = allIngredients.find(i => (i.ingredient_name && (i.ingredient_name.includes('Packaging Set') || i.ingredient_name.includes('ឈុត')))) 
                 || allIngredients.find(i => (i.category === 'Packaging' || (i.category && i.category.includes('វេចខ្ចប់'))));
    
    if (pkgItem) {
        addRecipeRow(pkgItem.ingredient_id, 1);
    } else {
        addRecipeRow('', 1);
    }
}

function toggleCrd(btn, e) {
    if (e) e.stopPropagation();
    const wrap = btn.closest('.crd-wrap');
    const pop = wrap.querySelector('.crd-popover');
    const isOpen = pop.classList.contains('open');

    // Close all other popovers first
    document.querySelectorAll('.crd-popover.open').forEach(p => {
        if (p !== pop) p.classList.remove('open');
    });

    if (isOpen) {
        pop.classList.remove('open');
    } else {
        refreshCrdDisabledStates();
        pop.classList.add('open');
    }
}

function crdSwitchCat(catEl, catName) {
    const pop = catEl.closest('.crd-popover');
    pop.querySelectorAll('.crd-cat-item').forEach(c => c.classList.remove('active'));
    catEl.classList.add('active');

    pop.querySelectorAll('.crd-panel').forEach(p => p.classList.remove('active'));
    const targetPanel = pop.querySelector('.crd-panel-' + catName);
    if (targetPanel) targetPanel.classList.add('active');
}

function crdSelectItem(itemEl, e) {
    if (e) e.stopPropagation();
    const itemName = itemEl.getAttribute('data-name');
    const itemId = itemEl.getAttribute('data-id');

    if (itemEl.classList.contains('disabled-in-recipe')) {
        showToast(`"${itemName}" is already added to this recipe!`, 'warning');
        return;
    }

    const wrap = itemEl.closest('.crd-wrap');
    const row = wrap.closest('.recipe-row');
    const select = row.querySelector('select[name="recipe_ingredient_id[]"]');
    const itemType = itemEl.getAttribute('data-type');
    const pop = wrap.querySelector('.crd-popover');

    // Duplicate Check across other rows
    let isDuplicate = false;
    document.querySelectorAll('.recipe-row').forEach(r => {
        if (r !== row) {
            const otherSel = r.querySelector('select[name="recipe_ingredient_id[]"]');
            if (otherSel && String(otherSel.value) === String(itemId)) {
                isDuplicate = true;
                r.classList.add('highlight-duplicate');
                setTimeout(() => r.classList.remove('highlight-duplicate'), 1200);
            }
        }
    });

    if (isDuplicate) {
        showToast(`"${itemName}" is already added in another row!`, 'error');
        pop.classList.remove('open');
        return;
    }

    // Update select value
    select.value = itemId;

    // Update trigger UI
    let iconHtml = '<i class="fa-solid fa-seedling text-emerald-400"></i>';
    if (itemType === 'direct_drink') {
        iconHtml = '<i class="fa-solid fa-wine-bottle text-amber-400"></i>';
    } else if (itemType === 'packaging' || (itemName && (itemName.toLowerCase().includes('packaging') || itemName.includes('ឈុត') || itemName.includes('កែវ')))) {
        iconHtml = '<i class="fa-solid fa-box-open text-sky-400"></i>';
    }
    wrap.querySelector('.crd-btn-icon').innerHTML = iconHtml;
    wrap.querySelector('.crd-btn-text').textContent = itemName;

    // Mark selected in popover
    pop.querySelectorAll('.crd-item-row').forEach(r => r.classList.remove('selected'));
    itemEl.classList.add('selected');

    // Close popover
    pop.classList.remove('open');

    // Refresh disabled state across all dropdowns
    refreshCrdDisabledStates();

    // Trigger change event and calculations
    updateRecipeRow(select);
}

// Global click outside listener
document.addEventListener('click', function(e) {
    if (!e.target.closest('.crd-wrap')) {
        document.querySelectorAll('.crd-popover.open').forEach(p => p.classList.remove('open'));
    }
});

function addRecipeRow(ingId = '', amt = '') {
    const container = document.getElementById('recipeRowsContainer');
    const noMsg = document.getElementById('noRecipeMsg');
    if (noMsg) noMsg.classList.add('hidden');

    // Collect already selected IDs
    const selectedIds = new Set();
    document.querySelectorAll('.recipe-row select[name="recipe_ingredient_id[]"]').forEach(sel => {
        if (sel.value && sel.value !== '') selectedIds.add(String(sel.value));
    });

    let targetIngId = ingId;
    if (!targetIngId) {
        // Find first unused non-packaging ingredient
        const available = allIngredients.find(i => {
            const isPkg = (i.category === 'Packaging' || (i.ingredient_name && (i.ingredient_name.includes('Packaging Set') || i.ingredient_name.includes('ឈុត'))));
            return !isPkg && !selectedIds.has(String(i.ingredient_id));
        }) || allIngredients.find(i => !selectedIds.has(String(i.ingredient_id)));

        if (available) {
            targetIngId = available.ingredient_id;
        } else {
            showToast('All available ingredients have already been added to the recipe.', 'warning');
            return;
        }
    }

    let defaultAmt = amt;
    let selectedUnit = '';
    const selectedItem = allIngredients.find(i => i.ingredient_id == targetIngId);
    if (selectedItem) {
        selectedUnit = (selectedItem.unit || '').toLowerCase();
    }
    const options = buildIngredientOptionsHtml(targetIngId);
    const crdHtml = buildCustomRecipeDropdownHtml(targetIngId);

    if ((!defaultAmt || defaultAmt === '0' || defaultAmt === 0) && ['can', 'cans', 'bottle', 'bottles', 'pcs', 'piece', 'pieces', 'cup', 'cups', 'pack', 'packs', 'portion', 'item'].includes(selectedUnit)) {
        defaultAmt = 1;
    }

    const tr = document.createElement('tr');
    tr.className = 'recipe-row';
    tr.innerHTML = `
        <td class="py-2 px-3">
            <select name="recipe_ingredient_id[]" class="recipe-select" style="display:none;" onchange="updateRecipeRow(this)" required>
                ${options}
            </select>
            ${crdHtml}
        </td>
        <td class="py-2 px-2 text-center">
            <div class="qty-unit-group mx-auto">
                <input type="number" step="any" min="0.01" name="recipe_amount_used[]" value="${defaultAmt || ''}" placeholder="1" class="qty-input-field" oninput="calculateRowTotal(this)" required>
                <span class="qty-unit-addon unit-label">unit</span>
            </div>
        </td>
        <td class="py-2 px-2 text-right text-[11px]">
            <span class="unit-price-label font-semibold text-[var(--text)]">$0.00</span><span class="text-[#888]">/</span><span class="unit-name-label text-[#888]">unit</span>
        </td>
        <td class="py-2 px-2 text-right text-xs font-bold text-[#3ecf70]">
            $<span class="row-total-label">0.00</span>
        </td>
        <td class="py-2 px-2 text-center">
            <button type="button" onclick="this.closest('.recipe-row').remove(); calculateTotalRecipeCost(); checkEmptyRecipe(); refreshCrdDisabledStates();" class="text-[#888] hover:text-red-400 p-1.5 rounded-lg hover:bg-red-500/10 transition-all" title="Remove ingredient">
                <i class="fa-solid fa-trash-can text-xs"></i>
            </button>
        </td>
    `;
    container.appendChild(tr);
    const sel = tr.querySelector('select');
    if (sel.value) updateRecipeRow(sel);
    refreshCrdDisabledStates();
}

function updateRecipeRow(selectEl) {
    const opt = selectEl.options[selectEl.selectedIndex];
    const row = selectEl.closest('.recipe-row');
    if (!row || !opt) return;

    const unit = (opt.dataset.unit || 'unit').trim();
    const cpu  = parseFloat(opt.dataset.cpu || '0');
    const optText = (opt.textContent || '').toLowerCase();
    const optType = opt.dataset.type || '';
    const isPkg = (optType === 'packaging' || optText.includes('packaging set') || optText.includes('ឈុត'));

    const uLbl = row.querySelector('.unit-label');
    if (uLbl) uLbl.textContent = unit;
    const unLbl = row.querySelector('.unit-name-label');
    if (unLbl) unLbl.textContent = unit;
    const upLbl = row.querySelector('.unit-price-label');
    if (upLbl) {
        const cpuStr = (cpu < 0.01 && cpu > 0) ? cpu.toFixed(4).replace(/0+$/, '') : cpu.toFixed(2);
        upLbl.textContent = '$' + cpuStr;
    }

    const qtyInput = row.querySelector('.qty-input-field');
    const qtyGroup = row.querySelector('.qty-unit-group');
    if (qtyInput) {
        if (isPkg) {
            qtyInput.value = '1';
            qtyInput.readOnly = true;
            qtyInput.title = 'Fixed 1 set per drink';
            if (qtyGroup) qtyGroup.classList.add('opacity-80');
        } else {
            qtyInput.readOnly = false;
            qtyInput.removeAttribute('title');
            if (qtyGroup) qtyGroup.classList.remove('opacity-80');

            const cleanUnit = unit.toLowerCase();
            const currentVal = parseFloat(qtyInput.value);
            if (isNaN(currentVal) || currentVal === 0 || qtyInput.value === '' || qtyInput.value === '0') {
                if (['can', 'cans', 'bottle', 'bottles', 'pcs', 'piece', 'pieces', 'cup', 'cups', 'pack', 'packs', 'portion', 'item'].includes(cleanUnit)) {
                    qtyInput.value = '1';
                }
            }
        }
    }

    calculateRowTotal(qtyInput);
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
        const qtyInput = row.querySelector('.qty-input-field');
        const selectEl = row.querySelector('select[name="recipe_ingredient_id[]"]');
        const opt = selectEl ? selectEl.options[selectEl.selectedIndex] : null;
        const cpu = opt ? parseFloat(opt.dataset.cpu || '0') : 0;
        const qty = parseFloat(qtyInput ? qtyInput.value || '0' : '0');
        grandTotal += (qty * cpu);
    });

    const cogsEl = document.getElementById('totalRecipeCogs');
    if (cogsEl) cogsEl.textContent = grandTotal.toFixed(2);

    // Live Gross Margin calculation
    const sellingPrice = parseFloat(document.getElementById('f_price')?.value || '0');
    const dispSelling  = document.getElementById('dispSellingPrice');
    if (dispSelling) dispSelling.textContent = sellingPrice.toFixed(2);

    const marginDol = sellingPrice - grandTotal;
    const gmDolEl   = document.getElementById('grossMarginDol');
    const gmWrap    = document.getElementById('grossMarginWrap');

    if (gmDolEl) {
        if (marginDol < 0) {
            gmDolEl.textContent = '-$' + Math.abs(marginDol).toFixed(2);
        } else {
            gmDolEl.textContent = '$' + marginDol.toFixed(2);
        }
    }

    if (gmWrap) {
        if (marginDol > 0) {
            gmWrap.className = 'text-lg font-black text-[#3ecf70]';
        } else if (marginDol < 0) {
            gmWrap.className = 'text-lg font-black text-[#ff4d4d]';
        } else {
            gmWrap.className = 'text-lg font-black text-[#777]';
        }
    }
}

function checkEmptyRecipe() {
    const container = document.getElementById('recipeRowsContainer');
    const noMsg = document.getElementById('noRecipeMsg');
    if (container.querySelectorAll('.recipe-row').length === 0) {
        if (noMsg) noMsg.classList.remove('hidden');
    }
}

function escapeHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// ── Initial load calculation ──
document.addEventListener('DOMContentLoaded', () => {
    calculateTotalRecipeCost();
    refreshCrdDisabledStates();
});
calculateTotalRecipeCost();
refreshCrdDisabledStates();

// ── AJAX Form Submission (No page refresh / no scroll reset) ──
const editForm = document.getElementById('editForm');
if (editForm) {
    editForm.addEventListener('submit', async function(e) {
        e.preventDefault();

        const saveBtn = editForm.querySelector('.btn-save');
        const origContent = saveBtn ? saveBtn.innerHTML : '';
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving changes...';
            saveBtn.style.opacity = '0.85';
        }

        try {
            const formData = new FormData(editForm);
            formData.append('ajax', '1');
            formData.append('update_product', '1');

            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (data.ok) {
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({
                        type: 'PRODUCT_SAVED',
                        productId: <?= (int)$id ?>,
                        product: data.product,
                        message: data.message
                    }, '*');
                    return;
                }

                showToast(data.message || 'Product & recipe updated successfully!', 'success');
                if (saveBtn) {
                    saveBtn.innerHTML = '<i class="fa-solid fa-check"></i> Saved successfully!';
                    saveBtn.style.background = 'linear-gradient(135deg, #3ecf70, #2eb35a)';
                    saveBtn.style.color = '#fff';
                    saveBtn.style.opacity = '1';
                    setTimeout(() => {
                        saveBtn.innerHTML = origContent;
                        saveBtn.style.background = '';
                        saveBtn.style.color = '';
                        saveBtn.disabled = false;
                    }, 2000);
                }

                // Update product title on header if changed
                const navName = document.getElementById('nav-name');
                if (navName && data.product?.name) {
                    navName.textContent = data.product.name;
                }
                // Update image preview if returned
                if (data.product?.image) {
                    const imgPreview = document.getElementById('imgPreview');
                    if (imgPreview) {
                        imgPreview.src = data.product.image;
                        imgPreview.style.display = 'block';
                    }
                }
            } else {
                showToast(data.error || 'Failed to save changes.', 'error');
                if (saveBtn) {
                    saveBtn.innerHTML = origContent;
                    saveBtn.style.opacity = '1';
                    saveBtn.disabled = false;
                }
            }
        } catch (err) {
            console.error('Save error:', err);
            showToast('Network error while saving product.', 'error');
            if (saveBtn) {
                saveBtn.innerHTML = origContent;
                saveBtn.style.opacity = '1';
                saveBtn.disabled = false;
            }
        }
    });
}

// ── Close Modal Helper ──
function closeEditModal(e) {
    if (e) e.preventDefault();
    if (window.parent && window.parent !== window) {
        window.parent.postMessage({ type: 'CLOSE_EDIT_MODAL' }, '*');
        return;
    }
    if (window.history.length > 1 && document.referrer && document.referrer.includes('products.php')) {
        window.history.back();
    } else {
        window.location.href = 'products.php';
    }
}

// ── Shortcuts: Escape to exit modal, Ctrl+Enter to save ──
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeEditModal();
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        const form = document.getElementById('editForm');
        if (form) {
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.dispatchEvent(new Event('submit', { cancelable: true }));
            }
        }
    }
});

// ── Direct Stock Link & Product Type Switching ──
const directDrinksMap = <?= json_encode($directDrinkList, JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const initialHasRecipes = <?= !empty($productIngredients) ? 'true' : 'false' ?>;
const initialCategory = <?= json_encode($product['category'] ?? '') ?>;
const initialName = <?= json_encode($product['name'] ?? '') ?>;

function setProductType(type) {
    const isDirect = (type === 'direct_drink');
    const input = document.getElementById('product_type_input');
    if (input) input.value = isDirect ? 'direct_drink' : 'recipe';

    const btnRecipe = document.getElementById('ptBtnRecipe');
    const btnDirect = document.getElementById('ptBtnDirect');
    if (btnRecipe) btnRecipe.classList.toggle('active', !isDirect);
    if (btnDirect) btnDirect.classList.toggle('active', isDirect);

    const modalDialog = document.getElementById('editModalDialog');
    const pageWrap = document.getElementById('editPageWrap');
    const recipeCol = document.getElementById('recipeColumnWrap');
    const ddInfo = document.getElementById('directDrinkInfoCard');
    const ddPricesRow = document.getElementById('directPricesRow');
    const recipePriceWrap = document.getElementById('recipePriceWrap');
    const ddMarginCard = document.getElementById('directMarginCard');
    const ddPicker = document.getElementById('directDrinkPickerWrap');

    if (modalDialog) modalDialog.classList.toggle('direct-drink-mode', isDirect);
    if (pageWrap) pageWrap.classList.toggle('direct-drink-mode', isDirect);

    if (recipeCol) {
        recipeCol.style.display = isDirect ? 'none' : '';
        // Toggle required on recipe fields
        recipeCol.querySelectorAll('select, input').forEach(el => {
            if (isDirect) {
                if (el.hasAttribute('required')) {
                    el.setAttribute('data-was-required', 'true');
                    el.removeAttribute('required');
                }
            } else {
                if (el.getAttribute('data-was-required') === 'true') {
                    el.setAttribute('required', 'required');
                }
            }
        });
    }

    if (ddInfo) ddInfo.style.display = isDirect ? 'flex' : 'none';
    if (ddPricesRow) ddPricesRow.style.display = isDirect ? 'grid' : 'none';
    if (recipePriceWrap) recipePriceWrap.style.display = isDirect ? 'none' : 'block';
    if (ddMarginCard) ddMarginCard.style.display = isDirect ? 'flex' : 'none';
    
    // Show ONLY 1 box for product name: dropdown in Direct Drink, text input in Made-to-Order
    const nameWrap = document.getElementById('recipeNameWrap');
    if (ddPicker) ddPicker.style.display = isDirect ? 'block' : 'none';
    if (nameWrap) nameWrap.style.display = isDirect ? 'none' : 'block';

    if (isDirect) {
        const costInp = document.getElementById('f_direct_cost');
        if (costInp && costInp.value) {
            const hCost = document.getElementById('f_cost_price');
            if (hCost) hCost.value = costInp.value;
        }
        recalcDirectMargin();
    } else {
        if (typeof calculateTotalRecipeCost === 'function') calculateTotalRecipeCost();
    }
}

function syncSellingPrice(val) {
    const fPrice = document.getElementById('f_price');
    const fPriceDD = document.getElementById('f_price_dd');
    if (fPrice && fPrice.value !== val) fPrice.value = val;
    if (fPriceDD && fPriceDD.value !== val) fPriceDD.value = val;
    const disp = document.getElementById('dispSellingPrice');
    if (disp) disp.textContent = parseFloat(val || 0).toFixed(2);
    calculateTotalRecipeCost();
    recalcDirectMargin();
}

function onDirectCostChange(val) {
    const cost = parseFloat(val || 0);
    const hiddenCost = document.getElementById('f_cost_price');
    if (hiddenCost) hiddenCost.value = isNaN(cost) ? '0.00' : cost.toFixed(2);
    recalcDirectMargin();
}

function recalcDirectMargin() {
    const sell = parseFloat(document.getElementById('f_price')?.value || document.getElementById('f_price_dd')?.value || 0);
    const cost = parseFloat(document.getElementById('f_direct_cost')?.value || 0);
    const marginDisp = document.getElementById('directMarginDisp');
    if (!marginDisp) return;
    const margin = sell - cost;
    const pct = sell > 0 ? ((margin / sell) * 100).toFixed(1) : '0.0';
    const sign = margin >= 0 ? '+' : '';
    marginDisp.textContent = `${sign}$${margin.toFixed(2)} (${sign}${pct}%)`;
    marginDisp.className = margin >= 0 ? 'text-sm font-black text-[#059669]' : 'text-sm font-black text-[#dc2626]';
}

function confirmDeleteProduct(id, name) {
    if (confirm('តើអ្នកពិតជាចង់លុបទំនិញ "' + name + '" នេះមែនទេ? (Are you sure you want to permanently delete this product?)')) {
        window.location.href = 'products.php?delete=' + id;
    }
}

function toggleDirectDrinkPicker() {
    const wrap = document.getElementById('directDrinkPickerWrap');
    if (!wrap) return;
    const isShown = (wrap.style.display !== 'none');
    wrap.style.display = isShown ? 'none' : 'block';
    if (!isShown) {
        document.getElementById('directDrinkSelect')?.focus();
    }
}

function onSelectDirectDrink(sel) {
    if (!sel || !sel.value) return;
    const val = sel.value;
    const opt = sel.options[sel.selectedIndex];
    const inp = document.getElementById('f_name');
    if (inp) {
        inp.value = val;
        checkDirectStockMatch(val);
    }
    
    // Automatically switch to Direct Drink type & hide recipe section
    setProductType('direct_drink');

    // Auto fill cost price if available
    const cost = parseFloat(opt.dataset.cost || 0);
    const costInp = document.getElementById('f_cost_price');
    const directCostInp = document.getElementById('f_direct_cost');
    if (cost > 0) {
        if (costInp) costInp.value = cost.toFixed(2);
        if (directCostInp) directCostInp.value = cost.toFixed(2);
        recalcDirectMargin();
    }

    // Auto select Soft Drink or Drinks category if empty
    const catSel = document.getElementById('f_cat');
    if (catSel && (!catSel.value || catSel.value === 'coffee' || catSel.value === 'tea')) {
        for (let i = 0; i < catSel.options.length; i++) {
            const oText = catSel.options[i].text.toLowerCase();
            const oVal = catSel.options[i].value.toLowerCase();
            if (oVal.includes('soft') || oText.includes('soft') || oVal.includes('direct') || oText.includes('drink')) {
                catSel.selectedIndex = i;
                break;
            }
        }
    }
}

function checkDirectStockMatch(val) {
    const hint = document.getElementById('directStockBadgeHint');
    const hintText = document.getElementById('directStockHintText');
    
    if (!val || !val.trim()) {
        if (hint) hint.style.display = 'none';
        return;
    }
    
    const cleanV = val.trim().toLowerCase().replace(/\s+/g, '');
    const match = directDrinksMap.find(d => {
        const cleanD = d.name.toLowerCase().replace(/\s+/g, '');
        return cleanD === cleanV || cleanV.includes(cleanD) || cleanD.includes(cleanV);
    });
    
    if (match) {
        if (hint && hintText) {
            hint.style.display = 'flex';
            hintText.innerHTML = `<i class="fa-solid fa-circle-check text-sky-500"></i> Auto-linked with Stock Drink: <strong>${match.name}</strong> (${parseInt(match.qty, 10)} ${match.unit || 'cans'} in inventory)`;
        }
        // Auto fill cost if empty
        const directCostInp = document.getElementById('f_direct_cost');
        if (directCostInp && (!directCostInp.value || parseFloat(directCostInp.value) === 0) && match.cost > 0) {
            directCostInp.value = parseFloat(match.cost).toFixed(2);
            document.getElementById('f_cost_price').value = parseFloat(match.cost).toFixed(2);
            recalcDirectMargin();
        }
    } else {
        if (hint) hint.style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const fName = document.getElementById('f_name');
    if (fName && fName.value) checkDirectStockMatch(fName.value);

    // Initial check: if product has 0 recipes and is direct drink / soft drink, default to direct_drink
    const cleanName = (initialName || '').toLowerCase();
    const cleanCat = (initialCategory || '').toLowerCase();
    const isDirectByDefault = !initialHasRecipes && (
        cleanCat.includes('direct') || cleanCat.includes('soft') || cleanCat.includes('bottle') || cleanCat.includes('can') ||
        cleanName.includes('coca') || cleanName.includes('coke') || cleanName.includes('red bull') || cleanName.includes('sting') || cleanName.includes('ize') || cleanName.includes('water')
    );

    if (isDirectByDefault) {
        setProductType('direct_drink');
    } else {
        setProductType('recipe');
    }

    // Auto switch type when category changes to a direct drink category
    const catSel = document.getElementById('f_cat');
    if (catSel) {
        catSel.addEventListener('change', function() {
            const val = this.value.toLowerCase();
            const txt = this.options[this.selectedIndex]?.text.toLowerCase() || '';
            if (val.includes('soft') || txt.includes('soft') || val.includes('direct') || txt.includes('direct') || val.includes('bottle') || val.includes('can') || val.includes('snack')) {
                setProductType('direct_drink');
            }
        });
    }
});
</script>
<script src="assets/js/product_cropper.js"></script>
</body>
</html>
