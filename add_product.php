<?php
require 'admin_only.php';
require 'config.php';

$error   = '';
$success = false;

if (isset($_POST['add_product'])) {
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
    } else {
        $upload_dir = "uploads/";
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        $image_path = '';
        if (!empty($_FILES['image']['name'])) {
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
                $image_name = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $image_path = $upload_dir . $image_name;
                move_uploaded_file($_FILES['image']['tmp_name'], $image_path);
            }
        }

        if (empty($error)) {
            $stmt = $conn->prepare("INSERT INTO products (name, description, price, cost_price, category, category_id, image, is_available, badge_text, promo_percent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssddsisisi", $name, $description, $price, $cost_price, $category, $category_id, $image_path, $is_avail, $badge_text, $promo_percent);
            if ($stmt->execute()) {
                $new_id = $stmt->insert_id;

                // ── Save Recipe / Bill of Materials (BOM) ──
                if (!empty($_POST['recipe_ingredient_id']) && is_array($_POST['recipe_ingredient_id'])) {
                    $insRec = $conn->prepare("INSERT INTO product_recipes (product_id, item_id, quantity_required, unit, notes) VALUES (?, ?, ?, ?, ?)");
                    $ingIds  = $_POST['recipe_ingredient_id'];
                    $ingQtys = $_POST['recipe_amount_used'] ?? [];
                    $totalCogs = 0.0;

                    for ($i = 0; $i < count($ingIds); $i++) {
                        $ingId   = (int)($ingIds[$i] ?? 0);
                        $qtyUsed = (float)($ingQtys[$i] ?? 0);
                        if ($ingId > 0 && $qtyUsed > 0) {
                            $uStmt = $conn->prepare("SELECT unit, cost_per_unit FROM stock_items WHERE item_id = ?");
                            $uStmt->bind_param("i", $ingId);
                            $uStmt->execute();
                            $uRow = $uStmt->get_result()->fetch_assoc();
                            $unit = $uRow['unit'] ?? 'g';
                            $cpu  = (float)($uRow['cost_per_unit'] ?? 0);
                            $totalCogs += ($qtyUsed * $cpu);
                            $note = "Recipe for " . $name;

                            $insRec->bind_param("iidss", $new_id, $ingId, $qtyUsed, $unit, $note);
                            $insRec->execute();
                            $uStmt->close();
                        }
                    }
                    $insRec->close();

                    // Update cost_price in products to match exact COGS
                    if ($totalCogs > 0) {
                        $cost_price = round($totalCogs, 2);
                        $updCost = $conn->prepare("UPDATE products SET cost_price = ? WHERE product_id = ?");
                        $updCost->bind_param("di", $cost_price, $new_id);
                        $updCost->execute();
                        $updCost->close();
                    }
                }

                header("Location: products.php");
                exit;
            } else {
                $error = "Database error while adding product.";
            }
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>(function(){if(localStorage.getItem('theme')==='light')document.documentElement.setAttribute('data-theme','light');})();</script>
<title>Add New Product</title>
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

/* ══ LIGHT THEME OVERRIDES ══ */
[data-theme="light"], html[data-theme="light"] {
    --bg:      #f4efe9 !important;
    --surface: #ede8e0 !important;
    --card:    #ffffff !important;
    --border:  #e0d4c4 !important;
    --text:    #1a1410 !important;
    --muted:   #5a4a3a !important;
}

[data-theme="light"] .app-main {
    background-color: #f4efe9 !important;
    color: #1a1410 !important;
}

[data-theme="light"] .animate-scaleUp {
    background-color: #ffffff !important;
    border-color: #e0d4c4 !important;
    color: #1a1410 !important;
    box-shadow: 0 20px 50px rgba(90, 60, 20, 0.15) !important;
}

[data-theme="light"] .shrink-0 {
    background-color: #fdfaf6 !important;
    border-color: #e0d4c4 !important;
}

[data-theme="light"] h2,
[data-theme="light"] h3,
[data-theme="light"] .section-head h3,
[data-theme="light"] .toggle-info h4 {
    color: #1a1410 !important;
}

[data-theme="light"] p,
[data-theme="light"] .toggle-info p,
[data-theme="light"] .img-file-info {
    color: #5a4a3a !important;
}

[data-theme="light"] .section-card {
    background: #ffffff !important;
    border-color: #e0d4c4 !important;
    box-shadow: 0 4px 16px rgba(90,60,20,0.06) !important;
}

[data-theme="light"] .section-head {
    background: #ede8e0 !important;
    border-color: #e0d4c4 !important;
}

[data-theme="light"] .no-image,
[data-theme="light"] .img-preview-wrap,
[data-theme="light"] .img-file-info {
    background: #ede8e0 !important;
    border-color: #e0d4c4 !important;
    color: #5a4a3a !important;
}

[data-theme="light"] input[type=text],
[data-theme="light"] input[type=number],
[data-theme="light"] textarea,
[data-theme="light"] select,
[data-theme="light"] select.cat-select {
    background-color: #ede8e0 !important;
    border-color: #e0d4c4 !important;
    color: #1a1410 !important;
}

[data-theme="light"] select.cat-select option {
    background-color: #ffffff !important;
    color: #1a1410 !important;
}

[data-theme="light"] label.flabel {
    color: #1a1410 !important;
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
[data-theme="light"] .recipe-table th {
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
[data-theme="light"] .unit-label-pill {
    background: #ede8e0 !important;
    color: #c47c2c !important;
    border-color: #d0c5b5 !important;
}
[data-theme="light"] .unit-price-label {
    color: #1a1410 !important;
}
[data-theme="light"] .unit-name-label {
    color: #5a4a3a !important;
}
[data-theme="light"] .selling-price-disp,
[data-theme="light"] #dispSellingPrice {
    color: #1a1410 !important;
}

/* Unified Identical-Size Quantity & Unit Input Box */
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

[data-theme="light"] .qty-unit-group {
    background: #f4efe9 !important;
    border-color: #d0c5b5 !important;
}
[data-theme="light"] .qty-input-field {
    color: #1a1410 !important;
}
[data-theme="light"] .qty-unit-addon {
    background: #ede8e0 !important;
    border-left: 1px solid #d0c5b5 !important;
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

/* ── 3-COLUMN RESPONSIVE LAYOUT ── */
.page-wrap {
    max-width: 100%;
    margin: 0 auto;
    padding: 0;
    display: grid;
    grid-template-columns: 240px 300px 1fr;
    gap: 22px;
    align-items: start;
}
@media (min-width: 1280px) {
    .page-wrap {
        grid-template-columns: 250px 310px 1fr;
    }
}
@media (max-width: 1080px) {
    .page-wrap { grid-template-columns: 1fr; }
}

.recipe-table select {
    min-width: 240px;
}

@keyframes scaleUp {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}
.animate-scaleUp { animation: scaleUp 0.25s cubic-bezier(0.16, 1, 0.3, 1) both; }

/* ── IMAGE PANEL ── */
.image-panel {
    display: flex; flex-direction: column; gap: 10px;
}
.img-preview-wrap {
    height: 220px;
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
.img-overlay i { font-size: 24px; color: var(--accent); }
.img-preview-wrap:hover .img-overlay { opacity: 1; }
.img-preview-wrap.drag-over { border-color: var(--accent); }
.img-preview-wrap.drag-over .img-overlay { opacity: 1; }

.no-image {
    height: 220px;
    width: 100%;
    border-radius: var(--radius);
    background: #141414;
    border: 2px dashed #2a2a2a;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center; gap: 8px;
    color: var(--muted); font-size: 13px;
    cursor: pointer; transition: border-color .2s;
}
.no-image:hover, .no-image.drag-over { border-color: var(--accent); color: var(--accent); }
.no-image i { font-size: 28px; }
.img-file-info {
    font-size: 11px; color: var(--muted); text-align: center;
    padding: 6px 10px;
    background: #111; border: 1px solid #1e1e1e;
    border-radius: 10px;
}
.img-file-info span { color: var(--accent); font-weight: 500; }

/* ── FORM PANEL ── */
.form-panel { display: flex; flex-direction: column; gap: 18px; }

.section-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    position: relative;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    transition: transform 0.25s ease, border-color 0.25s ease;
}
.section-card:hover {
    border-color: rgba(209,144,75,0.3);
}
.section-head {
    padding: 14px 18px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    position: relative;
    background: linear-gradient(180deg, rgba(255,255,255,0.02) 0%, transparent 100%);
}
.section-head h3 { font-size: 14px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
.section-body { padding: 18px; display: flex; flex-direction: column; gap: 14px; }

/* ── FIELDS ── */
.field { display: flex; flex-direction: column; gap: 6px; }
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
    padding: 10px 14px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: #0f0f0f;
    color: var(--text);
    font-family: Poppins, sans-serif;
    font-size: 13px;
    transition: border-color .18s, box-shadow .18s;
    outline: none;
}
.input-wrap input[type=number] { padding-left: 30px; }
input[type=text]:focus, input[type=number]:focus, textarea:focus, select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(209,144,75,.15);
}

select.cat-select {
    width: 100%;
    padding: 10px 14px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: #0f0f12 url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='%23d1904b' viewBox='0 0 16 16'><path d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/></svg>") no-repeat calc(100% - 14px) center;
    color: var(--text, #f0f0f0);
    font-family: Poppins, sans-serif;
    font-size: 13px;
    appearance: none;
    -webkit-appearance: none;
    cursor: pointer;
    transition: all .2s ease;
}
select.cat-select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(209,144,75,0.2);
}
select.cat-select option {
    background: #18181c;
    color: #ffffff;
    padding: 8px;
}

/* ── TOGGLE SWITCH ── */
.toggle-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 4px 0;
}
.toggle-info h4 { font-size: 13px; font-weight: 600; color: #fff; }
.toggle-info p { font-size: 11px; color: var(--muted); }

.toggle-switch {
    position: relative;
    display: inline-block;
    width: 44px;
    height: 24px;
}
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-track {
    position: absolute; cursor: pointer; inset: 0;
    background-color: #24242b; border: 1px solid #333;
    border-radius: 24px; transition: .25s;
}
.toggle-track::before {
    position: absolute; content: ""; height: 16px; width: 16px;
    left: 3px; bottom: 3px; background-color: #777;
    border-radius: 50%; transition: .25s;
}
.toggle-switch input:checked + .toggle-track { background-color: rgba(62,207,112,.2); border-color: var(--success); }
.toggle-switch input:checked + .toggle-track::before { transform: translateX(20px); background-color: var(--success); }

/* ── SAVE BUTTON ── */
.btn-save {
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    color: #000; font-weight: 700; font-size: 13px;
    padding: 12px 20px; border-radius: 12px; border: none;
    cursor: pointer; transition: filter .2s, transform .2s;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    width: 100%;
}
.btn-save:hover { filter: brightness(1.15); transform: translateY(-1px); }

/* ── Recipe Table & Chips ── */
.recipe-row {
    transition: background-color 0.15s ease;
}
.recipe-row:hover {
    background-color: rgba(209, 144, 75, 0.08) !important;
}
.unit-label-pill {
    padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 700;
    background: #141418; border: 1px solid #282834; color: #d1904b;
}
.unit-price-label {
    color: var(--text);
    font-weight: 600;
}
.unit-name-label {
    color: #888;
}
.selling-price-disp {
    color: var(--text);
}
.btn-add-ing {
    display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px;
    border-radius: 10px; border: 1px dashed rgba(209,144,75,0.5);
    background: rgba(209,144,75,0.06); color: #d1904b; font-size: 12px; font-weight: 600;
    transition: all .2s; cursor: pointer;
}
.btn-add-ing:hover {
    background: rgba(209,144,75,0.15); border-color: #d1904b;
}

/* ── ALERTS ── */
.alert {
    padding: 12px 16px; border-radius: 10px; font-size: 13px;
    display: flex; align-items: center; gap: 10px; margin-bottom: 10px;
}
.alert-danger { background: rgba(255,77,77,.12); color: var(--danger); border: 1px solid rgba(255,77,77,.25); }
</style>
</head>
<body>
<div class="flex h-screen w-screen overflow-hidden app-layout">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<main class="app-main flex-1 h-full overflow-y-auto p-4 md:p-6 relative">

<!-- ADD PRODUCT MODAL BACKDROP -->
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 md:p-6 bg-black/75 backdrop-blur-md overflow-y-auto">
    <!-- MODAL DIALOG CONTAINER (Expanded horizontal width for Recipe BOM) -->
    <div class="relative w-[96vw] max-w-[1380px] max-h-[94vh] bg-[#121215] border border-[#24242b] rounded-2xl shadow-2xl flex flex-col overflow-hidden text-white my-auto animate-scaleUp">
        
        <!-- MODAL HEADER -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-[#24242b] bg-[#18181c]/90 backdrop-blur-md shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-[#d1904b]/15 border border-[#d1904b]/30 flex items-center justify-center text-[#d1904b]">
                    <i class="fa-solid fa-plus-circle"></i>
                </div>
                <div>
                    <h2 class="text-base md:text-lg font-bold text-white leading-tight">
                        Add New Product
                    </h2>
                </div>
            </div>
            
            <a href="products.php" class="w-9 h-9 rounded-xl bg-[#22222a] text-[#888] hover:text-white hover:bg-red-500/20 hover:text-red-400 flex items-center justify-center transition-all" title="Close Modal (Esc)">
                <i class="fa-solid fa-xmark text-lg"></i>
            </a>
        </div>

        <!-- MODAL BODY -->
        <div class="flex-1 overflow-y-auto p-5 md:p-6">
            <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" id="addForm">
                <input type="hidden" name="add_product" value="1">
                <input type="file" name="image" id="f_img_input" accept="image/*" style="display:none">
                <input type="hidden" name="cost_price" id="f_cost_price" value="0.00">

                <div class="page-wrap">
                    
                    <!-- ════ COLUMN 1: PRODUCT IMAGE ════ -->
                    <div class="section-card">
                        <div class="section-head">
                            <h3><i class="fa-solid fa-image text-[#d1904b]"></i> Product Image</h3>
                        </div>
                        <div class="section-body flex flex-col gap-3">
                            <div class="image-panel">
                                <div class="no-image" id="noImgBox" onclick="document.getElementById('f_img_input').click()">
                                    <i class="fa-solid fa-cloud-arrow-up text-3xl mb-2 text-[#d1904b]"></i>
                                    <span class="text-xs font-semibold">Click or drag to upload image</span>
                                </div>
                                <div class="img-preview-wrap" id="imgPreviewWrap" style="display:none" onclick="document.getElementById('f_img_input').click()">
                                    <img id="imgPreview" src="" alt="Preview">
                                    <div class="img-overlay">
                                        <i class="fa-solid fa-camera text-xl"></i>
                                        <span>Change Image</span>
                                    </div>
                                </div>
                                <div class="img-file-info text-center" id="fileInfo">
                                    <span id="imgStatusText" class="text-[11px] text-[#888]">No image selected</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ════ COLUMN 2: PRODUCT DETAILS ════ -->
                    <div class="flex flex-col gap-4">
                        <div class="section-card">
                            <div class="section-head">
                                <h3><i class="fa-solid fa-file-pen text-[#d1904b]"></i> Product Details</h3>
                            </div>
                            <div class="section-body flex flex-col gap-3.5">
                                <div class="field">
                                    <label class="flabel" for="f_name">Product Name *</label>
                                    <input type="text" name="name" id="f_name" value="" placeholder="e.g. Oolong Macchiato" required>
                                </div>

                                <!-- CATEGORY -->
                                <div class="field">
                                    <label class="flabel" for="f_cat">Category *</label>
                                    <select name="category" id="f_cat" class="cat-select" required>
                                        <option value="">Select Category…</option>
                                        <?php foreach ($cats as $slug => $label): ?>
                                        <option value="<?= htmlspecialchars($slug) ?>">
                                            <?= htmlspecialchars($label) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- SELLING PRICE -->
                                <div class="field">
                                    <label class="flabel" for="f_price">Selling Price *</label>
                                    <div class="input-wrap">
                                        <span class="prefix">$</span>
                                        <input type="number" id="f_price" name="price" step="0.01" min="0" max="9999.99"
                                            required class="has-prefix" placeholder="0.00">
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
                                            <input type="checkbox" name="is_available" id="availToggle" value="1" checked>
                                            <span class="toggle-track"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn-save">
                            <i class="fa-solid fa-plus"></i> Add Product
                        </button>
                    </div>

                    <!-- ════ COLUMN 3: RECIPE & INVENTORY (BILL OF MATERIALS - BOM) ════ -->
                    <div class="section-card flex-1">
                        <div class="section-head">
                            <h3><i class="fa-solid fa-mortar-pestle text-[#d1904b]"></i> Recipe & Ingredients (BOM)</h3>
                            <button type="button" onclick="addRecipeRow()" class="btn-add-ing">
                                <i class="fa-solid fa-plus text-xs"></i> Add Ingredient
                            </button>
                        </div>
                        <div class="section-body flex flex-col gap-4">
                            
                            <!-- Recipe Table -->
                            <div class="recipe-table-wrap overflow-x-auto rounded-xl">
                                <table class="w-full text-left text-xs recipe-table">
                                    <thead class="recipe-thead uppercase tracking-wider font-semibold">
                                        <tr>
                                            <th class="py-2.5 px-3">Raw Ingredient</th>
                                            <th class="py-2.5 px-2 text-center w-28">Qty Required</th>
                                            <th class="py-2.5 px-2 text-right">Unit Cost</th>
                                            <th class="py-2.5 px-2 text-right">Total Cost</th>
                                            <th class="py-2.5 px-2 text-center w-10"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="recipeRowsContainer" class="recipe-tbody divide-y">
                                    </tbody>
                                </table>

                                <!-- Empty State -->
                                <div id="noRecipeMsg" class="no-recipe-box flex flex-col items-center justify-center py-6 text-center">
                                    <i class="fa-solid fa-mortar-pestle text-2xl text-[#d1904b] mb-1 opacity-70"></i>
                                    <p class="no-recipe-title text-xs font-semibold">No Recipe Ingredients Linked</p>
                                    <p class="no-recipe-sub text-[11px] max-w-xs mt-0.5">Click "Add Ingredient" to connect raw materials.</p>
                                </div>
                            </div>

                            <!-- Live Recipe COGS & Gross Profit Calculator -->
                            <div class="recipe-summary-box p-3.5 rounded-xl text-xs flex items-center justify-between">
                                <div class="space-y-0.5">
                                    <div class="text-[10px] uppercase font-bold summary-label">Estimated Recipe Cost (COGS)</div>
                                    <div class="text-lg font-black text-[#d1904b]">$<span id="totalRecipeCogs">0.00</span></div>
                                </div>
                                <div class="space-y-0.5 text-center">
                                    <div class="text-[10px] uppercase font-bold summary-label">Selling Price</div>
                                    <div class="text-lg font-black selling-price-disp">$<span id="dispSellingPrice">0.00</span></div>
                                </div>
                                <div class="space-y-0.5 text-right">
                                    <div class="text-[10px] uppercase font-bold summary-label">Gross Profit Margin</div>
                                    <div id="grossMarginWrap" class="text-lg font-black text-[#3ecf70]">
                                        <span id="grossMarginDol">$0.00</span>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                </div><!-- /.page-wrap -->
            </form>
        </div><!-- /.modal-body -->
    </div><!-- /.modal-dialog -->
</div><!-- /.modal-backdrop -->
</main>
</div>

<script>
// ── Image upload preview ──
const fImgInput = document.getElementById('f_img_input');
const imgPreview = document.getElementById('imgPreview');
const imgPreviewWrap = document.getElementById('imgPreviewWrap');
const noImgBox = document.getElementById('noImgBox');
const imgStatusText = document.getElementById('imgStatusText');

if (fImgInput) {
    fImgInput.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                imgPreview.src = e.target.result;
                imgPreviewWrap.style.display = 'block';
                noImgBox.style.display = 'none';
                imgStatusText.textContent = fImgInput.files[0].name;
            };
            reader.readAsDataURL(this.files[0]);
        }
    });
}

// Drag & Drop
const dropTargets = [noImgBox, imgPreviewWrap];
dropTargets.forEach(target => {
    if (!target) return;
    target.addEventListener('dragover', e => { e.preventDefault(); target.classList.add('drag-over'); });
    target.addEventListener('dragleave', () => target.classList.remove('drag-over'));
    target.addEventListener('drop', e => {
        e.preventDefault();
        target.classList.remove('drag-over');
        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
            fImgInput.files = e.dataTransfer.files;
            fImgInput.dispatchEvent(new Event('change'));
        }
    });
});

// ── Live price input listener ──
const fPrice = document.getElementById('f_price');
if (fPrice) {
    fPrice.addEventListener('input', calculateTotalRecipeCost);
}

// ── Inline Recipe Rows Manager ──
const allIngredients = <?= json_encode($allIngredients) ?>;

function addRecipeRow(ingId = '', amt = '') {
    const container = document.getElementById('recipeRowsContainer');
    const noMsg = document.getElementById('noRecipeMsg');
    if (noMsg) noMsg.classList.add('hidden');

    let defaultAmt = amt;
    let selectedUnit = '';
    let options = '<option value="">Select ingredient…</option>';
    allIngredients.forEach(i => {
        const sel = (i.ingredient_id == ingId) ? 'selected' : '';
        const cpu = parseFloat(i.cost_per_unit || 0);
        if (sel) selectedUnit = (i.unit || '').toLowerCase();
        options += `<option value="${i.ingredient_id}" data-unit="${escapeHtml(i.unit)}" data-cpu="${cpu}" ${sel}>${escapeHtml(i.ingredient_name)}</option>`;
    });

    if ((!defaultAmt || defaultAmt === '0' || defaultAmt === 0) && ['can', 'cans', 'bottle', 'bottles', 'pcs', 'piece', 'pieces', 'cup', 'cups', 'pack', 'packs', 'portion', 'item'].includes(selectedUnit)) {
        defaultAmt = 1;
    }

    const tr = document.createElement('tr');
    tr.className = 'recipe-row';
    tr.innerHTML = `
        <td class="py-2 px-3">
            <select name="recipe_ingredient_id[]" class="recipe-select" onchange="updateRecipeRow(this)" required>
                ${options}
            </select>
        </td>
        <td class="py-2 px-2 text-center">
            <div class="qty-unit-group mx-auto">
                <input type="number" step="any" min="0.01" name="recipe_amount_used[]" value="${defaultAmt || ''}" placeholder="1" class="qty-input-field" oninput="calculateRowTotal(this)" required>
                <span class="qty-unit-addon unit-label">unit</span>
            </div>
        </td>
        <td class="py-2 px-2 text-right text-[11px]">
            <span class="unit-price-label">$0.00</span><span class="unit-name-label">/unit</span>
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

    const unit = (opt.dataset.unit || 'unit').trim();
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

    const qtyInput = row.querySelector('.qty-input-field');
    if (qtyInput) {
        const cleanUnit = unit.toLowerCase();
        const currentVal = parseFloat(qtyInput.value);
        if (isNaN(currentVal) || currentVal === 0 || qtyInput.value === '' || qtyInput.value === '0') {
            if (['can', 'cans', 'bottle', 'bottles', 'pcs', 'piece', 'pieces', 'cup', 'cups', 'pack', 'packs', 'portion', 'item'].includes(cleanUnit)) {
                qtyInput.value = '1';
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

    const costPriceInput = document.getElementById('f_cost_price');
    if (costPriceInput) costPriceInput.value = grandTotal.toFixed(2);

    // Live Gross Margin calculation
    const sellingPrice = parseFloat(document.getElementById('f_price')?.value || '0');
    const dispSelling  = document.getElementById('dispSellingPrice');
    if (dispSelling) dispSelling.textContent = sellingPrice.toFixed(2);

    const marginDol    = sellingPrice - grandTotal;
    const marginPct    = sellingPrice > 0 ? ((sellingPrice - grandTotal) / sellingPrice) * 100 : 0;

    const gmDolEl = document.getElementById('grossMarginDol');
    const gmWrap  = document.getElementById('grossMarginWrap');

    if (gmDolEl) gmDolEl.textContent = '$' + marginDol.toFixed(2) + (sellingPrice > 0 ? ` (${marginPct.toFixed(1)}%)` : '');

    if (gmWrap) {
        if (marginDol > 0 || marginPct > 0) gmWrap.className = 'text-lg font-black text-[#3ecf70]';
        else if (sellingPrice > 0 && marginDol <= 0) gmWrap.className = 'text-lg font-black text-[#ff4d4d]';
        else gmWrap.className = 'text-lg font-black text-[#777]';
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

// Initial calculation
document.addEventListener('DOMContentLoaded', calculateTotalRecipeCost);

// ESC to exit modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        window.location.href = 'products.php';
    }
});
</script>
</body>
</html>