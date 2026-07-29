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
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            $error = "Invalid file type. Allowed: jpg, jpeg, png, gif, webp.";
        } else {
            $upload_dir = "uploads/";
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $image_name = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $image_path = $upload_dir . $image_name;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $image_path)) {
                if (!empty($product['image']) && file_exists($product['image'])) unlink($product['image']);
                $stmt = $conn->prepare("UPDATE products SET name=?,description=?,price=?,category=?,category_id=?,image=?,is_available=?,badge_text=?,promo_percent=? WHERE product_id=?");
                $stmt->bind_param("ssdsisisii", $name, $description, $price, $category, $category_id, $image_path, $is_avail, $badge_text, $promo_percent, $id);
                if ($stmt->execute()) { $success = true; $product['image'] = $image_path; }
                else $error = "Database error while updating product.";
            } else {
                $error = "Failed to upload image.";
            }
        }
    } else {
        $stmt = $conn->prepare("UPDATE products SET name=?,description=?,price=?,category=?,category_id=?,is_available=?,badge_text=?,promo_percent=? WHERE product_id=?");
        $stmt->bind_param("ssdsiisii", $name, $description, $price, $category, $category_id, $is_avail, $badge_text, $promo_percent, $id);
        if ($stmt->execute()) $success = true;
        else $error = "Database error while updating product.";
    }

    if ($success) {
        $product['name']         = $name;
        $product['description']  = $description;
        $product['price']        = $price;
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
        // refresh prefill
        $assignedAddons = [];
        foreach ($addonIds as $aid) $assignedAddons[$aid] = true;
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit — <?= htmlspecialchars($product['name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
    max-width: 960px;
    margin: 0 auto;
    padding: 32px 20px 60px;
    display: grid;
    grid-template-columns: 340px 1fr;
    gap: 24px;
    align-items: start;
}
@media (max-width: 720px) { .page-wrap { grid-template-columns: 1fr; } }

/* ── IMAGE PANEL ── */
.image-panel {
    display: flex; flex-direction: column; gap: 16px;
    position: sticky; top: 74px;
}
.img-preview-wrap {
    aspect-ratio: 1;
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

/* category pills */
.cat-pills { display: flex; flex-wrap: wrap; gap: 8px; }
.cat-pill {
    padding: 7px 16px; border-radius: 20px;
    border: 1px solid #2a2a2a; background: #0f0f0f;
    color: var(--muted); font-size: 13px; font-weight: 500;
    cursor: pointer; transition: background .18s, border-color .18s, color .18s;
    user-select: none;
}
.cat-pill:hover { border-color: #3a3a3a; color: var(--text); }
.cat-pill.active {
    background: rgba(209,144,75,.15);
    border-color: var(--accent);
    color: var(--accent);
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
<div class="orb orb-a"></div>
<div class="orb orb-b"></div>

<!-- NAV -->
<nav class="topnav">
    <a class="back-btn" href="products.php">
        <i class="fa-solid fa-arrow-left"></i> Products
    </a>
    <div class="breadcrumb">
        <i class="fa-solid fa-chevron-right" style="font-size:10px"></i>
        <span id="nav-name"><?= htmlspecialchars($product['name']) ?></span>
    </div>
    <span class="avail-pill <?= $product['is_available'] ? 'on' : 'off' ?>" id="navPill">
        <i class="fa-solid fa-circle" style="font-size:7px"></i>
        <?= $product['is_available'] ? 'Available' : 'Unavailable' ?>
    </span>
</nav>

<div class="page-wrap">

    <!-- LEFT: IMAGE + PREVIEW -->
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
            <span>Click or drag to upload</span>
        </div>
        <img id="imgPreview" style="display:none">
        <?php endif; ?>

        <div class="img-file-info" id="fileInfo">
            <span id="fileName">Current image — click above to replace</span>
        </div>

        <!-- Live preview card -->
        <div class="product-preview">
            <div class="pp-label">Preview</div>
            <div class="pp-name" id="ppName"><?= htmlspecialchars($product['name']) ?></div>
            <div class="pp-meta">
                <span class="pp-price" id="ppPrice">$<?= number_format($product['price'], 2) ?></span>
                <span class="pp-cat" id="ppCat"><?= htmlspecialchars($product['category']) ?></span>
            </div>
            <div class="badge-preview-row" id="ppBadgeRow" style="margin-top:10px;<?= empty($product['badge_text']) ? 'display:none' : '' ?>">
                <span class="product-badge" id="ppBadge"><?= htmlspecialchars($product['badge_text'] ?? '') ?></span>
            </div>
        </div>

    </div>

    <!-- RIGHT: FORM -->
    <div class="form-panel">

        <?php if ($error): ?>
        <div class="error-bar"><i class="fa-solid fa-circle-xmark"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="editForm">
            <input type="file" name="image" id="imgInput" accept="image/*" style="display:none">

            <!-- DETAILS -->
            <div class="section-card">
                <div class="section-head">
                    <i class="fa-solid fa-pen-line"></i>
                    <h3>Product Details</h3>
                </div>
                <div class="section-body">
                    <div class="field">
                        <label class="flabel" for="f_name">Product Name</label>
                        <input type="text" id="f_name" name="name" required maxlength="120"
                            value="<?= htmlspecialchars($product['name']) ?>"
                            placeholder="e.g. Iced Caramel Latte">
                    </div>

                    <div class="field">
                        <label class="flabel" for="f_desc">Description</label>
                        <textarea id="f_desc" name="description" maxlength="300"
                            placeholder="Short description shown to customers..."><?= htmlspecialchars($product['description']) ?></textarea>
                        <div class="char-count" id="charCount">0 / 300</div>
                    </div>

                    <div class="field">
                        <label class="flabel" for="f_price">Price</label>
                        <div class="input-wrap">
                            <span class="prefix">$</span>
                            <?php /* When this product has sizes, the save handler overwrites
                                     products.price with the Medium size price (or the first
                                     size offered) so the legacy single-price paths in cart
                                     and receipts keep working. The field used to stay fully
                                     editable anyway, so typing a price here, saving, and
                                     watching it come back as something else looked like the
                                     save was broken. It is read-only while sizes are on, and
                                     mirrors the size that actually drives it. */ ?>
                            <input type="number" id="f_price" name="price" step="0.01" min="0" max="9999.99"
                                required class="has-prefix"
                                <?= $hasSizes ? 'readonly' : '' ?>
                                value="<?= $product['price'] ?>">
                        </div>
                        <div class="char-count" id="priceNote"
                             style="display:<?= $hasSizes ? 'block' : 'none' ?>;color:var(--muted);">
                            Set by the Medium size below — clear the sizes checkbox to price this product directly.
                        </div>
                    </div>

                    <div class="field">
                        <label class="form-check" for="has_sizes" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="checkbox" id="has_sizes" name="has_sizes" value="1"
                                <?= $hasSizes ? 'checked' : '' ?>
                                onchange="document.getElementById('sizeRows').style.display=this.checked?'block':'none'; syncBasePrice();">
                            <span class="flabel" style="margin:0">This product has sizes (S / M / L)</span>
                        </label>
                        <div id="sizeRows" style="display:<?= $hasSizes ? 'block' : 'none' ?>;flex-direction:column;gap:10px;margin-top:10px;">
                            <?php
                            $sizeDefaults = [
                                ['code'=>'S','label'=>'Small','factor'=>'0.80','sort'=>0],
                                ['code'=>'M','label'=>'Medium','factor'=>'1.00','sort'=>1],
                                ['code'=>'L','label'=>'Large','factor'=>'1.30','sort'=>2],
                            ];
                            foreach ($sizeDefaults as $d):
                                $ex = $existingSizes[$d['code']] ?? null;
                                $pv = $ex['price'] ?? '';
                                $lv = $ex['label'] ?? $d['label'];
                                $fv = $ex['size_factor'] ?? $d['factor'];
                            ?>
                            <div class="size-row" style="display:flex;gap:8px;align-items:center;">
                                <input type="hidden" name="size_code[]" value="<?= $d['code'] ?>">
                                <input type="hidden" name="size_sort[]" value="<?= $d['sort'] ?>">
                                <input type="text" name="size_label[]" value="<?= htmlspecialchars($lv) ?>" placeholder="Label" style="flex:1.3">
                                <div class="input-wrap" style="flex:1">
                                    <span class="prefix">$</span>
                                    <input type="number" step="0.01" min="0" name="size_price[]" value="<?= htmlspecialchars($pv) ?>" placeholder="Price" class="has-prefix size-price" data-size-code="<?= $d['code'] ?>">
                                </div>
                                <input type="number" step="0.01" min="0" name="size_factor[]" value="<?= htmlspecialchars($fv) ?>" placeholder="Stock ×" style="flex:0.8">
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if (!empty($allAddons)): ?>
                    <div class="input-group" style="padding-left:0;margin-bottom:8px;">
                        <label class="form-check" for="has_addons" style="display:flex;align-items:center;gap:8px;cursor:pointer;color:var(--muted);font-size:14px;">
                            <input type="checkbox" id="has_addons" name="has_addons" value="1" <?= $hasAddons ? 'checked' : '' ?>
                                onchange="var b=document.getElementById('addonRows');b.style.display=this.checked?'block':'none';if(!this.checked){b.querySelectorAll('input[type=checkbox]').forEach(function(c){c.checked=false;c.closest('.addon-chip').classList.remove('on');});}">
                            This product has add-ons
                        </label>
                    </div>
                    <div id="addonRows" class="input-group" style="padding-left:0;<?= $hasAddons ? '' : 'display:none;' ?>">
                        <div style="display:flex;flex-wrap:wrap;gap:8px;">
                            <?php foreach ($allAddons as $ad): $on = !empty($assignedAddons[(int)$ad['id']]); ?>
                            <label class="addon-chip<?= $on ? ' on' : '' ?>">
                                <input type="checkbox" name="addon_id[]" value="<?= (int)$ad['id'] ?>" <?= $on ? 'checked' : '' ?> style="display:none;" onchange="this.closest('.addon-chip').classList.toggle('on', this.checked);">
                                <?= htmlspecialchars($ad['name'], ENT_QUOTES, 'UTF-8') ?> +$<?= number_format((float)$ad['price'], 2) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- CATEGORY -->
            <div class="section-card">
                <div class="section-head">
                    <i class="fa-solid fa-layer-group"></i>
                    <h3>Category</h3>
                </div>
                <div class="section-body">
                    <div class="cat-pills" id="catPills">
                        <?php foreach ($cats as $slug => $label): ?>
                        <div class="cat-pill <?= ($product['category'] === $slug) ? 'active' : '' ?>"
                             data-cat="<?= htmlspecialchars($slug) ?>"
                             onclick="selectCat(this)">
                            <?= htmlspecialchars($label) ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="text" name="category" id="f_cat"
                        value="<?= htmlspecialchars($product['category']) ?>" required readonly>
                </div>
            </div>

            <!-- AVAILABILITY -->
            <div class="section-card">
                <div class="section-head">
                    <i class="fa-solid fa-toggle-on"></i>
                    <h3>Availability</h3>
                </div>
                <div class="section-body">
                    <div class="toggle-row">
                        <div class="toggle-info">
                            <h4>Show on menu</h4>
                            <p>Toggle off to hide this product from the customer menu.</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="is_available" id="availToggle"
                                <?= $product['is_available'] ? 'checked' : '' ?>>
                            <span class="toggle-track"></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- BADGE -->
            <div class="section-card">
                <div class="section-head">
                    <i class="fa-solid fa-certificate"></i>
                    <h3>Promotion Badge</h3>
                </div>
                <div class="section-body">
                    <div class="field">
                        <label class="flabel" for="f_promo">Promo % Off <span style="font-weight:400;color:var(--muted)">(0 = none; a non-zero promo replaces the badge with "N% OFF")</span></label>
                        <input type="number" id="f_promo" name="promo_percent" min="0" max="100" step="1"
                            value="<?= (int)($product['promo_percent'] ?? 0) ?>">
                    </div>
                    <div class="field">
                        <label class="flabel" for="f_badge">Badge Label <span style="font-weight:400;color:var(--muted)">(leave blank to remove)</span></label>
                        <input type="text" id="f_badge" name="badge_text" maxlength="40"
                            value="<?= htmlspecialchars($product['badge_text'] ?? '') ?>"
                            placeholder='e.g. "50% OFF", "New!", "Limited", "Hot Deal"'>
                    </div>
                    <div class="badge-preview-row" id="badgeLiveRow" style="<?= empty($product['badge_text']) ? 'display:none' : '' ?>">
                        <span class="product-badge" id="badgeLive"><?= htmlspecialchars($product['badge_text'] ?? '') ?></span>
                        <button type="button" class="badge-clear-btn" onclick="clearBadge()" title="Remove badge"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                </div>
            </div>

            <button type="submit" name="update_product" class="btn-save">
                <i class="fa-solid fa-floppy-disk"></i>
                Save Changes
            </button>
        </form>
    </div>

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

// ── Live preview ──
const fName  = document.getElementById('f_name');
const fPrice = document.getElementById('f_price');
const ppName = document.getElementById('ppName');
const ppPrice= document.getElementById('ppPrice');
const ppCat  = document.getElementById('ppCat');
const navName= document.getElementById('nav-name');

fName.addEventListener('input', () => {
    ppName.textContent = fName.value || 'Product name';
    navName.textContent = fName.value || 'Edit Product';
});
fPrice.addEventListener('input', () => {
    const v = parseFloat(fPrice.value);
    ppPrice.textContent = isNaN(v) ? '$—' : '$' + v.toFixed(2);
});

// ── Base price follows the sizes ──
// The server picks Medium, falling back to the first size actually offered
// (edit_product.php: $mediumPrice ?? $fallbackPrice). Mirror that rule exactly
// rather than approximating it, so the number on screen is the number that will
// be stored. A size left blank is not offered, which is why blanks are skipped
// here the same way the save handler skips them.
function syncBasePrice() {
    const on   = document.getElementById('has_sizes').checked;
    const note = document.getElementById('priceNote');

    fPrice.readOnly    = on;
    note.style.display = on ? 'block' : 'none';
    if (!on) return;

    const priced = [...document.querySelectorAll('.size-price')]
        .filter(el => parseFloat(el.value) > 0);
    if (!priced.length) return;   // every size blank — the server keeps the typed price

    const medium = priced.find(el => el.dataset.sizeCode === 'M');
    const chosen = parseFloat((medium || priced[0]).value);

    fPrice.value = chosen.toFixed(2);
    ppPrice.textContent = '$' + chosen.toFixed(2);
}

document.querySelectorAll('.size-price').forEach(el => el.addEventListener('input', syncBasePrice));
syncBasePrice();

// ── Category pills ──
function selectCat(el) {
    document.querySelectorAll('.cat-pill').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('f_cat').value = el.dataset.cat;
    ppCat.textContent = el.dataset.cat;
}

// ── Char counter ──
const fDesc = document.getElementById('f_desc');
const charCount = document.getElementById('charCount');
function updateChar() {
    const n = fDesc.value.length;
    charCount.textContent = n + ' / 300';
    charCount.className = 'char-count' + (n > 260 ? ' warn' : '');
}
fDesc.addEventListener('input', updateChar);
updateChar();

// ── Availability pill in nav ──
const availToggle = document.getElementById('availToggle');
const navPill = document.getElementById('navPill');
availToggle.addEventListener('change', () => {
    if (availToggle.checked) {
        navPill.className = 'avail-pill on';
        navPill.innerHTML = '<i class="fa-solid fa-circle" style="font-size:7px"></i> Available';
    } else {
        navPill.className = 'avail-pill off';
        navPill.innerHTML = '<i class="fa-solid fa-circle" style="font-size:7px"></i> Unavailable';
    }
});

// ── Badge live preview ──
const fBadge       = document.getElementById('f_badge');
const badgeLiveRow = document.getElementById('badgeLiveRow');
const badgeLive    = document.getElementById('badgeLive');
const ppBadgeRow   = document.getElementById('ppBadgeRow');
const ppBadge      = document.getElementById('ppBadge');
const imgBadge     = document.getElementById('imgBadge');

fBadge.addEventListener('input', updateBadge);
function updateBadge() {
    const val = fBadge.value.trim();
    if (val) {
        badgeLive.textContent = val; badgeLiveRow.style.display = 'flex';
        ppBadge.textContent   = val; ppBadgeRow.style.display   = 'flex';
        if (imgBadge) { imgBadge.textContent = val; imgBadge.style.display = 'flex'; }
    } else {
        badgeLiveRow.style.display = 'none';
        ppBadgeRow.style.display   = 'none';
        if (imgBadge) imgBadge.style.display = 'none';
    }
}
function clearBadge() { fBadge.value = ''; updateBadge(); }

const fPromo = document.getElementById('f_promo');
fPromo.addEventListener('input', applyPromoBadge);
function applyPromoBadge() {
    const promo = Math.max(0, Math.min(100, parseInt(fPromo.value || '0', 10)));
    if (promo > 0) {
        fBadge.disabled = true; fBadge.style.opacity = '0.5';
        const txt = promo + '% OFF';
        badgeLive.textContent = txt; badgeLiveRow.style.display = 'flex';
        ppBadge.textContent   = txt; ppBadgeRow.style.display   = 'flex';
        if (imgBadge) { imgBadge.textContent = txt; imgBadge.style.display = 'flex'; }
    } else {
        fBadge.disabled = false; fBadge.style.opacity = '';
        updateBadge();
    }
}
applyPromoBadge();

// ── Ctrl+Enter ──
document.addEventListener('keydown', e => {
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
