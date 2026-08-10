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
    $is_avail    = isset($_POST['is_available']) ? 1 : 1;
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

                // Save Recipe Ingredients
                if (!empty($_POST['recipe_ingredient_id']) && is_array($_POST['recipe_ingredient_id'])) {
                    $pi_stmt = $conn->prepare("INSERT INTO product_ingredients (product_id, ingredient_id, amount_used) VALUES (?, ?, ?)");
                    foreach ($_POST['recipe_ingredient_id'] as $idx => $r_ing_id) {
                        $r_ing_id = (int)$r_ing_id;
                        $r_amt    = (float)($_POST['recipe_amount_used'][$idx] ?? 0);
                        if ($r_ing_id > 0 && $r_amt > 0) {
                            $pi_stmt->bind_param("iid", $new_id, $r_ing_id, $r_amt);
                            $pi_stmt->execute();
                        }
                    }
                }

                // Auto-recalculate authoritative cost_price from product_ingredients
                $cogsQ = $conn->prepare("SELECT SUM(pi.amount_used * i.cost_per_unit) AS cogs FROM product_ingredients pi JOIN ingredients i ON pi.ingredient_id = i.ingredient_id WHERE pi.product_id = ?");
                $cogsQ->bind_param("i", $new_id);
                $cogsQ->execute();
                $calcCogs = (float)($cogsQ->get_result()->fetch_assoc()['cogs'] ?? 0);
                if ($calcCogs > 0) {
                    $upCogs = $conn->prepare("UPDATE products SET cost_price = ? WHERE product_id = ?");
                    $upCogs->bind_param("di", $calcCogs, $new_id);
                    $upCogs->execute();
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

$allIngredients = [];
$ingRes = $conn->query("SELECT ingredient_id, ingredient_name, unit, stock_quantity, cost_per_unit FROM ingredients ORDER BY ingredient_name ASC");
if ($ingRes) {
    while ($ingRow = $ingRes->fetch_assoc()) {
        $allIngredients[] = $ingRow;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
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
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: Poppins, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    font-size: 14px;
}

/* ── LAYOUT ── */
.page-wrap {
    max-width: 100%;
    margin: 0 auto;
    padding: 0;
    display: grid;
    grid-template-columns: 350px 1fr;
    gap: 24px;
    align-items: start;
}
@media (max-width: 850px) { .page-wrap { grid-template-columns: 1fr; } }
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
.img-overlay i { font-size: 24px; color: var(--accent); }
.img-preview-wrap:hover .img-overlay { opacity: 1; }
.img-preview-wrap.drag-over { border-color: var(--accent); }
.img-preview-wrap.drag-over .img-overlay { opacity: 1; }

.no-image {
    height: 180px;
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
    display: flex; align-items: center; gap: 10px;
    position: relative;
    background: linear-gradient(180deg, rgba(255,255,255,0.02) 0%, transparent 100%);
}
.section-head i { color: var(--accent); font-size: 14px; }
.section-head h3 { font-size: 14px; font-weight: 600; }
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

/* ── ALERTS ── */
.alert {
    padding: 12px 16px; border-radius: 10px; font-size: 13px;
    display: flex; align-items: center; gap: 10px; margin-bottom: 10px;
}
.alert-danger  { background: rgba(255,77,77,.12);  color: var(--danger);  border: 1px solid rgba(255,77,77,.25); }

.orb {
    position: fixed; border-radius: 50%; filter: blur(90px);
    pointer-events: none; z-index: 0;
}
.orb-a {
    width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(209,144,75,.15) 0%, transparent 70%);
    top: -120px; right: -120px;
}
.orb-b {
    width: 300px; height: 300px;
    background: radial-gradient(circle, rgba(93,173,226,.1) 0%, transparent 70%);
    bottom: -80px; left: -80px;
}
</style>
</head>
<body>

<div class="flex h-screen w-screen overflow-hidden bg-[#0e0e10] app-layout">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<main class="app-main flex-1 h-full overflow-y-auto p-4 md:p-6 relative">
<div class="orb orb-a"></div>
<div class="orb orb-b"></div>

<!-- ADD PRODUCT MODAL BACKDROP -->
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 md:p-6 bg-black/75 backdrop-blur-md overflow-y-auto">
    <!-- MODAL DIALOG CONTAINER -->
    <div class="relative w-full max-w-5xl max-h-[92vh] bg-[#121215] border border-[#24242b] rounded-2xl shadow-2xl flex flex-col overflow-hidden text-white my-auto animate-scaleUp">
        
        <!-- MODAL HEADER -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-[#24242b] bg-[#18181c]/90 backdrop-blur-md shrink-0">
            <h2 class="text-lg font-bold text-white leading-tight flex items-center gap-2">
                <i class="fa-solid fa-plus-circle text-[#d1904b]"></i> Add New Product
            </h2>
            
            <a href="products.php" class="w-9 h-9 rounded-xl bg-[#22222a] text-[#888] hover:text-white hover:bg-red-500/20 hover:text-red-400 flex items-center justify-center transition-all" title="Close Modal (Esc)">
                <i class="fa-solid fa-xmark text-lg"></i>
            </a>
        </div>

        <!-- MODAL BODY -->
        <div class="flex-1 overflow-y-auto p-6 space-y-6">
            <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" id="addForm">
                <input type="hidden" name="add_product" value="1">
                <input type="file" name="image" id="f_img_input" accept="image/*" style="display:none">

                <div class="page-wrap">
                    <!-- LEFT COLUMN: PRODUCT INFO -->
                    <div class="left-col flex flex-col gap-4">
                        <div class="section-card">
                            <div class="section-head">
                                <i class="fa-solid fa-pen-line"></i>
                                <h3>Product Details</h3>
                            </div>
                            <div class="section-body flex flex-col gap-3">

                                <!-- PRODUCT IMAGE AT TOP OF DETAILS -->
                                <div class="image-panel">
                                    <div class="no-image" id="noImgBox" onclick="document.getElementById('f_img_input').click()">
                                        <i class="fa-solid fa-cloud-arrow-up"></i>
                                        <span>Click or drag to upload image</span>
                                    </div>
                                    <div class="img-preview-wrap" id="imgPreviewWrap" style="display:none" onclick="document.getElementById('f_img_input').click()">
                                        <img id="imgPreview" src="" alt="Preview">
                                        <div class="img-overlay">
                                            <i class="fa-solid fa-camera"></i>
                                            <span>Change Image</span>
                                        </div>
                                    </div>
                                    <div class="img-file-info text-center">
                                        <span id="imgStatusText" class="text-[11px] text-[#888]">No image selected</span>
                                    </div>
                                </div>

                                <div class="field">
                                    <label class="flabel" for="f_name">Product Name</label>
                                    <input type="text" name="name" id="f_name" value="" placeholder="e.g. Oolong Macchiato" required>
                                </div>

                                <!-- CATEGORY -->
                                <div class="field">
                                    <label class="flabel" for="f_cat">Category</label>
                                    <select name="category" id="f_cat" class="cat-select" required>
                                        <option value="">Select Category…</option>
                                        <?php foreach ($cats as $slug => $label): ?>
                                        <option value="<?= htmlspecialchars($slug) ?>">
                                            <?= htmlspecialchars($label) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
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

                    <!-- RIGHT COLUMN: RECIPE PANEL -->
                    <div class="right-col flex flex-col gap-4">
                        <div class="section-card">
                            <div class="section-head flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <i class="fa-solid fa-flask"></i>
                                    <h3>Recipe Ingredients</h3>
                                </div>
                                <button type="button" onclick="addRecipeRow()" class="text-xs text-[#d1904b] hover:underline flex items-center gap-1 font-semibold">
                                    <i class="fa-solid fa-plus text-[11px]"></i> Add Ingredient
                                </button>
                            </div>
                            <div class="section-body p-0 overflow-hidden">
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left text-xs border-collapse">
                                        <thead>
                                            <tr class="border-b border-[#24242b] bg-[#18181c] text-[#888] font-semibold uppercase tracking-wider text-[11px]">
                                                <th class="py-3 px-3">Ingredient</th>
                                                <th class="py-3 px-2 text-center w-[130px]">Qty Required</th>
                                                <th class="py-3 px-2 text-right w-[100px]">Cost / Unit</th>
                                                <th class="py-3 px-2 text-right w-[90px]">Item Cost</th>
                                                <th class="py-3 px-2 text-center w-[45px]">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="recipeRowsContainer" class="divide-y divide-[#1e1e24]">
                                        </tbody>
                                        <tfoot id="recipeFooterSummary" style="display:none">
                                            <tr class="border-t-2 border-[#2a2a32] bg-[#18181c]">
                                                <td colspan="3" class="py-3 px-3 font-bold text-[#888] uppercase tracking-wider text-[11px]">
                                                    <i class="fa-solid fa-calculator text-[#d1904b] mr-1.5"></i> Total Cost of Goods (COGS)
                                                </td>
                                                <td class="py-3 px-2 text-right font-extrabold text-[#3ecf70] text-sm">
                                                    $<span id="tableTotalCogs">0.00</span>
                                                </td>
                                                <td></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>

                                <div id="noRecipeMsg" class="text-center py-8 text-[#888] flex flex-col items-center justify-center p-4">
                                    <div class="w-12 h-12 rounded-full bg-[#222] flex items-center justify-center mb-3 text-[#d1904b]">
                                        <i class="fa-solid fa-book-open text-xl"></i>
                                    </div>
                                    <p class="text-xs font-medium text-white mb-1">No recipe configured</p>
                                    <p class="text-[11px] text-[#777] max-w-[200px] mb-4">No ingredients are linked to this drink yet.</p>
                                    <button type="button" onclick="addRecipeRow()" class="inline-flex items-center gap-1.5 text-xs px-3.5 py-2 rounded-xl bg-gradient-to-r from-[#e8b87a] to-[#d1904b] text-black font-bold hover:brightness-110 transition-all shadow-md">
                                        <i class="fa-solid fa-plus text-[10px]"></i> Setup Recipe
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- PRICING & FINANCIAL SUMMARY BELOW RECIPE TABLE -->
                        <div class="section-card">
                            <div class="section-head">
                                <i class="fa-solid fa-coins text-[#d1904b]"></i>
                                <h3>Pricing & Profit Summary</h3>
                            </div>
                            <div class="section-body flex flex-col gap-3">
                                <div class="grid grid-cols-2 gap-3">
                                    <div class="field">
                                        <label class="flabel" for="f_price">Selling Price</label>
                                        <div class="input-wrap">
                                            <span class="prefix">$</span>
                                            <input type="number" id="f_price" name="price" step="0.01" min="0" max="9999.99"
                                                required class="has-prefix" placeholder="0.00">
                                        </div>
                                    </div>
                                    <div class="field">
                                        <label class="flabel" for="f_cost_price">Cost Price</label>
                                        <div class="input-wrap">
                                            <span class="prefix">$</span>
                                            <input type="number" id="f_cost_price" name="cost_price" step="0.01" min="0" max="9999.99"
                                                readonly class="has-prefix text-[#3ecf70] font-bold bg-[#141418] cursor-not-allowed opacity-90"
                                                placeholder="0.00" title="Automatically calculated from total recipe ingredient costs">
                                        </div>
                                    </div>
                                </div>

                                <div class="grid grid-cols-3 gap-2 p-3 rounded-xl bg-[#141418] border border-[#24242b] text-center text-xs">
                                    <div>
                                        <span class="text-[10px] text-[#777] uppercase tracking-wider block font-semibold mb-0.5">Total COGS</span>
                                        <span class="text-sm font-extrabold text-white">$<span id="totalRecipeCogs">0.00</span></span>
                                    </div>
                                    <div>
                                        <span class="text-[10px] text-[#777] uppercase tracking-wider block font-semibold mb-0.5">Selling Price</span>
                                        <span class="text-sm font-extrabold text-[#d1904b]">$<span id="dispSellingPrice">0.00</span></span>
                                    </div>
                                    <div>
                                        <span class="text-[10px] text-[#777] uppercase tracking-wider block font-semibold mb-0.5">Gross Margin</span>
                                        <span id="grossMarginWrap" class="text-sm font-extrabold text-[#777]">
                                            $<span id="grossMarginDol">0.00</span> (<span id="grossMarginPct">0.0%</span>)
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>

    </div>
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

// Initial calculation
document.addEventListener('DOMContentLoaded', calculateTotalRecipeCost);

// ESC to exit modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        window.location.href = 'products.php';
    }
});
</script>
</main>
</div>
</body>
</html>