<?php
require 'admin_only.php';   // admin/manager only; pulls in config.php ($conn)

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

function cat_slug(string $name): string { return preg_replace('/\s+/', ' ', trim($name)); }
function he($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function cat_upload_icon(): string {
    if (empty($_FILES['icon']['name'])) return '';
    if (($_FILES['icon']['error'] ?? 1) !== UPLOAD_ERR_OK) return '__UPLOAD_ERR__';
    $ext = strtolower(pathinfo($_FILES['icon']['name'], PATHINFO_EXTENSION));
    if ($ext === '') $ext = 'bin';
    $upload_dir = "uploads/";
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
    $path = $upload_dir . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    move_uploaded_file($_FILES['icon']['tmp_name'], $path);
    return $path;
}

$flash = null;

// ── POST action router (CSRF-guarded). Cases added in later tasks. ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed. Please retry.'];
    } else {
        switch ($_POST['action'] ?? '') {
            // create / update / toggle / delete / reorder added in later tasks
            case 'create': {
                $name = trim((string)($_POST['name'] ?? ''));
                $icon = cat_upload_icon();
                if ($icon === '__UPLOAD_ERR__')  { $flash = ['type'=>'error','msg'=>'Image upload failed. Please try again.']; break; }
                if ($icon === '') $icon = 'fa-circle';
                $active = isset($_POST['is_active']) ? 1 : 0;
                $slug = cat_slug($name);
                if ($slug === '') { $flash = ['type'=>'error','msg'=>'Category name is required.']; break; }
                $dup = $conn->prepare("SELECT category_id FROM categories WHERE LOWER(slug) = LOWER(?) LIMIT 1");
                $dup->bind_param('s', $slug); $dup->execute();
                if ($dup->get_result()->fetch_assoc()) { $flash = ['type'=>'error','msg'=>"A category named \"$slug\" already exists."]; break; }
                $ord = (int)$conn->query("SELECT COALESCE(MAX(display_order),0)+1 AS n FROM categories")->fetch_assoc()['n'];
                $os = isset($_POST['offer_sweetness']) ? 1 : 0;
                $oi = isset($_POST['offer_ice'])       ? 1 : 0;
                $om = isset($_POST['offer_milk'])      ? 1 : 0;
                $oa = isset($_POST['offer_addons'])    ? 1 : 0;
                $ep = isset($_POST['earns_points'])    ? 1 : 0;
                $ins = $conn->prepare("INSERT INTO categories (slug, name, icon, display_order, is_active, offer_sweetness, offer_ice, offer_milk, offer_addons, earns_points) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->bind_param('sssiiiiiii', $slug, $name, $icon, $ord, $active, $os, $oi, $om, $oa, $ep);
                $ins->execute();
                $flash = ['type'=>'success','msg'=>"Category \"$slug\" added."];
                break;
            }
            case 'update': {
                $id   = (int)($_POST['category_id'] ?? 0);
                $name = trim((string)($_POST['name'] ?? ''));
                $active = isset($_POST['is_active']) ? 1 : 0;
                if ($id <= 0 || $name === '') { $flash = ['type'=>'error','msg'=>'Name is required.']; break; }
                $iconRow = $conn->query("SELECT icon FROM categories WHERE category_id=" . (int)$id)->fetch_assoc();
                $icon = $iconRow['icon'] ?? 'fa-circle';
                $newIcon = cat_upload_icon();
                if ($newIcon === '__UPLOAD_ERR__')  { $flash = ['type'=>'error','msg'=>'Image upload failed. Please try again.']; break; }
                if ($newIcon !== '') $icon = $newIcon;
                $os = isset($_POST['offer_sweetness']) ? 1 : 0;
                $oi = isset($_POST['offer_ice'])       ? 1 : 0;
                $om = isset($_POST['offer_milk'])      ? 1 : 0;
                $oa = isset($_POST['offer_addons'])    ? 1 : 0;
                $ep = isset($_POST['earns_points'])    ? 1 : 0;
                $u = $conn->prepare("UPDATE categories SET name=?, icon=?, is_active=?, offer_sweetness=?, offer_ice=?, offer_milk=?, offer_addons=?, earns_points=? WHERE category_id=?");
                $u->bind_param('ssiiiiiii', $name, $icon, $active, $os, $oi, $om, $oa, $ep, $id);
                $u->execute();
                $seeded = 0;
                if ($oa) {
                    // Seed-only: give every product in this category that has NO add-ons yet
                    // the full active add-on set. Products with a custom set are left untouched.
                    $slugRow = $conn->query("SELECT slug FROM categories WHERE category_id=" . (int)$id)->fetch_assoc();
                    $catSlug = $slugRow['slug'] ?? '';
                    if ($catSlug !== '') {
                        $seed = $conn->prepare("
                            INSERT IGNORE INTO product_addons (product_id, addon_id)
                            SELECT p.product_id, a.id
                            FROM products p CROSS JOIN addons a
                            WHERE p.category = ? AND a.is_active = 1
                              AND NOT EXISTS (SELECT 1 FROM product_addons pa WHERE pa.product_id = p.product_id)
                        ");
                        $seed->bind_param('s', $catSlug);
                        $seed->execute();
                        $seeded = $conn->affected_rows;
                    }
                }
                $flash = ['type'=>'success','msg'=>'Category updated.' . ($seeded > 0 ? " Seeded add-ons for products that had none." : '')];
                break;
            }
            case 'toggle': {
                $id = (int)($_POST['category_id'] ?? 0);
                if ($id > 0) {
                    $conn->query("UPDATE categories SET is_active = 1 - is_active WHERE category_id = " . $id);
                    $flash = ['type'=>'success','msg'=>'Category visibility updated.'];
                }
                break;
            }
            case 'delete': {
                $id = (int)($_POST['category_id'] ?? 0);
                if ($id <= 0) { $flash = ['type'=>'error','msg'=>'Invalid category.']; break; }
                $chk = $conn->prepare("SELECT COUNT(*) AS n FROM products WHERE category_id = ?");
                $chk->bind_param('i', $id); $chk->execute();
                $n = (int)$chk->get_result()->fetch_assoc()['n'];
                if ($n > 0) { $flash = ['type'=>'error','msg'=>"$n product(s) use this category — reassign them (via each product's Edit page) or delete them first."]; break; }
                $d = $conn->prepare("DELETE FROM categories WHERE category_id = ?");
                $d->bind_param('i', $id); $d->execute();
                $flash = ['type'=>'success','msg'=>'Category deleted.'];
                break;
            }
            case 'reorder': {
                $id  = (int)($_POST['category_id'] ?? 0);
                $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
                if ($id > 0) {
                    // current row
                    $cur = $conn->query("SELECT category_id, display_order FROM categories WHERE category_id = " . $id)->fetch_assoc();
                    if ($cur) {
                        // neighbor in the chosen direction by display_order
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
    }
}

// ── Load categories with product counts ──
$categories = [];
$res = $conn->query("
    SELECT c.category_id, c.slug, c.name, c.icon, c.display_order, c.is_active,
           c.offer_sweetness, c.offer_ice, c.offer_milk, c.offer_addons, c.earns_points,
           (SELECT COUNT(*) FROM products p WHERE p.category_id = c.category_id) AS product_count
    FROM categories c
    ORDER BY c.display_order ASC, c.category_id ASC
");
while ($row = $res->fetch_assoc()) $categories[] = $row;

$totalCats    = count($categories);
$activeCats   = count(array_filter($categories, fn($c) => (int)$c['is_active'] === 1));
$inactiveCats = $totalCats - $activeCats;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage Categories | Bird's Nest Coffee</title>
<script>(function(){if(localStorage.getItem('theme')==='light')document.documentElement.setAttribute('data-theme','light');}());</script>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --bg:#0b0b0b; --bg-card:#131313; --bg-card-hover:#1a1a1a; --bg-input:#1a1a1a;
    --border:#222; --border-hover:#333;
    --accent:#d1904b; --accent-light:#e8b87a; --accent-dark:#a0702a;
    --text:#f5f5f5; --text-muted:#888; --text-light:#fff;
    --ok:#55e087; --low:#f1c40f; --danger:#ff5f5f; --blue:#3498db; --purple:#9b59b6;
    --shadow-sm:0 2px 8px rgba(0,0,0,.35); --shadow-md:0 4px 20px rgba(0,0,0,.45);
    --shadow-accent:0 0 0 3px rgba(209,144,75,.12);
    --radius:14px; --transition:all .22s cubic-bezier(.4,0,.2,1);
}
[data-theme="light"] {
    --bg:#F0F2F5; --bg-card:#FFFFFF; --bg-card-hover:#F5F7FA; --bg-input:#F9FAFB;
    --border:#E5E7EB; --border-hover:#D1D5DB;
    --text:#111827; --text-muted:#6B7280; --text-light:#111827;
    --shadow-sm:0 2px 8px rgba(0,0,0,.06); --shadow-md:0 4px 20px rgba(0,0,0,.08);
}
[data-theme="light"] .topbar { background:rgba(255,255,255,.97); }
[data-theme="light"] thead th { background:#fff; }
[data-theme="light"] tr:hover td { background:rgba(0,0,0,.02); }
[data-theme="light"] input,[data-theme="light"] select { background:var(--bg-input)!important; color:var(--text)!important; border-color:var(--border)!important; color-scheme:light; }

*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Poppins',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; padding-bottom:48px; }
::-webkit-scrollbar { width:5px; height:5px; }
::-webkit-scrollbar-thumb { background:var(--accent); border-radius:10px; }

/* TOPBAR */
.topbar {
    position:sticky; top:0; z-index:200;
    display:flex; align-items:center; gap:10px; padding:10px 24px;
    background:rgba(11,11,11,.97); backdrop-filter:blur(20px);
    border-bottom:1px solid var(--border); flex-wrap:wrap;
}
.brand-icon  { width:34px; height:34px; border-radius:9px; background:linear-gradient(135deg,var(--accent-dark),var(--accent)); display:flex; align-items:center; justify-content:center; font-size:15px; color:#fff; flex-shrink:0; }
.brand-text  { display:flex; flex-direction:column; line-height:1.2; }
.brand-title { font-size:15px; font-weight:700; color:var(--text-light); }
.brand-sub   { font-size:10px; color:var(--text-muted); }
.topbar-sep  { width:1px; height:22px; background:var(--border); flex-shrink:0; }
.topbar-right { display:flex; align-items:center; gap:6px; margin-left:auto; flex-wrap:wrap; }

.btn-nav {
    display:inline-flex; align-items:center; gap:6px; padding:7px 14px;
    border-radius:50px; border:1px solid var(--border); background:var(--bg-input);
    color:var(--text-muted); text-decoration:none; font-size:12px; font-weight:500;
    transition:var(--transition); cursor:pointer; white-space:nowrap; font-family:'Poppins',sans-serif;
}
.btn-nav:hover { border-color:var(--accent); color:var(--accent); }
.btn-nav.icon-only { padding:7px 10px; }

.wrap { max-width:100%; margin:18px auto 60px; padding:0 24px; }
.flash { padding:12px 18px; border-radius:12px; font-size:13.5px; font-weight:500; margin-bottom:18px; }
.flash.success { background:rgba(85,224,135,.15); color:#55e087; border:1px solid rgba(85,224,135,.3); }
.flash.error   { background:rgba(255,95,95,.15);  color:#ff5f5f; border:1px solid rgba(255,95,95,.3); }

/* ── TOAST ALERT ── */
#toast-container {
    position: fixed;
    top: 18px;
    right: 18px;
    z-index: 999999;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.toast {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-left: 4px solid var(--accent);
    border-radius: 12px;
    padding: 13px 18px;
    box-shadow: var(--shadow-md);
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 240px;
    max-width: 360px;
    transform: translateX(120%);
    opacity: 0;
    transition: all 0.35s ease;
    font-size: 14px;
    color: var(--text);
    font-weight: 500;
    font-family: 'Poppins', sans-serif;
}
.toast.show {
    transform: translateX(0);
    opacity: 1;
}
.toast i {
    color: var(--accent);
    font-size: 17px;
    flex-shrink: 0;
}
.toast.success {
    border-left-color: var(--ok);
    background: rgba(16,185,129,.12);
    border-color: rgba(16,185,129,.3);
}
.toast.success i { color: var(--ok); }
.toast.error {
    border-left-color: #e74c3c;
    background: rgba(231,76,60,.12);
    border-color: rgba(231,76,60,.3);
}
.toast.error i { color: #e74c3c; }
@keyframes toastIn { from { transform: translateX(120%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
@keyframes toastOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(120%); opacity: 0; } }

/* ── STATS (matches view_order.php) ── */
.vo-stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    width: 100%;
    max-width: 100%;
    margin: 0 0 24px 0;
    padding: 0;
}

@media (max-width: 1200px) {
    .vo-stats-grid { grid-template-columns: repeat(3, 1fr); }
}

@media (max-width: 768px) {
    .vo-stats-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 576px) {
    .vo-stats-grid { grid-template-columns: 1fr; }
}

.vo-stat-box {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 16px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 16px;
    min-height: 92px;
    cursor: pointer;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.05);
    position: relative;
    overflow: hidden;
}

.vo-stat-box:hover {
    transform: translateY(-3px);
    border-color: rgba(255, 255, 255, 0.18);
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.4);
}

.vo-stat-content {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.vo-stat-title {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-muted);
}

.vo-stat-value {
    font-size: 26px;
    font-weight: 800;
    color: var(--text);
    line-height: 1.2;
}

.vo-stat-sub {
    font-size: 11.5px;
    color: var(--text-muted);
    font-weight: 400;
    opacity: 0.85;
}

.vo-stat-icon {
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

.vo-stat-box:hover .vo-stat-icon {
    transform: scale(1.08);
}

.vo-stat-box.active {
    border-color: var(--accent);
    background: rgba(209, 144, 75, 0.12);
    box-shadow: 0 0 20px rgba(209, 144, 75, 0.15), inset 0 1px 0 rgba(209, 144, 75, 0.2);
}

.vo-stat-icon.all-orders {
    background: rgba(139, 92, 246, 0.22);
    color: #a78bfa;
    border: 1px solid rgba(139, 92, 246, 0.4);
}

.vo-stat-icon.complete-orders {
    background: rgba(16, 185, 129, 0.22);
    color: #34d399;
    border: 1px solid rgba(16, 185, 129, 0.4);
}

.vo-stat-icon.overdue-orders {
    background: rgba(239, 68, 68, 0.22);
    color: #f87171;
    border: 1px solid rgba(239, 68, 68, 0.4);
}

/* ── Table Matching view_order.php ── */
.cat-table-wrapper {
    border-radius: 16px;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.08);
    box-shadow: 0 10px 35px rgba(0,0,0,0.5);
    background: rgba(18, 18, 18, 0.6);
    backdrop-filter: blur(20px);
}
.cat-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}
.cat-table thead tr {
    background: #0f766e !important;
    color: #ffffff !important;
}
.cat-table th {
    padding: 14px 16px !important;
    text-align: left;
    font-size: 11px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.06em !important;
    color: #ffffff !important;
    border-bottom: none !important;
}
.cat-table td {
    padding: 14px 16px !important;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05) !important;
    font-size: 13.5px !important;
    color: #f3f4f6 !important;
    vertical-align: middle;
}
.cat-table tr:last-child td {
    border-bottom: none !important;
}
.cat-table tbody tr {
    transition: background 0.2s ease;
}
.cat-table tbody tr:hover {
    background: rgba(255, 255, 255, 0.04) !important;
}
.cat-row.inactive { opacity:.55; background:rgba(255,255,255,.02); }
.cat-icon { width:34px; height:34px; border-radius:9px; background:rgba(15,118,110,.2); color:#55e087; display:inline-flex; align-items:center; justify-content:center; font-size:15px; }
.slug-muted { color:#9ca3af; font-size:12px; }
.pill { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:50px; font-size:10.5px; font-weight:700; }
.pill-inactive { background:rgba(239,68,68,.2); color:#f87171; }
.icon-btn { background:rgba(255,255,255,.06) !important; border:1px solid rgba(255,255,255,.1) !important; color:#d1d5db !important; border-radius:8px !important; width:30px !important; height:30px !important; cursor:pointer; transition:all 0.2s ease; }
.icon-btn:hover:not(:disabled) { background:rgba(15,118,110,.25) !important; border-color:#0f766e !important; color:#55e087 !important; }
.icon-btn:disabled { opacity:.25; cursor:not-allowed; }
.act-link { color:#d1904b; text-decoration:none; font-size:12.5px; font-weight:600; margin-right:10px; cursor:pointer; background:none; border:none; font-family:inherit; transition:color 0.2s ease; }
.act-link:hover { color:#e8b87a; }
.danger-link { color:#f87171; }
.danger-link:hover { color:#ef4444; }
.danger-link:disabled { opacity:.35; cursor:not-allowed; }
/* ── Edit Category Modal ── */
.edit-modal-overlay {
    position: fixed; inset: 0; z-index: 99999;
    background: rgba(0, 0, 0, 0.75);
    backdrop-filter: blur(12px);
    display: none; align-items: center; justify-content: center;
    padding: 20px;
}
.edit-modal-box {
    background: #18181b;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 22px;
    width: 500px; max-width: 95vw;
    padding: 28px 32px;
    box-shadow: 0 25px 60px rgba(0,0,0,0.7);
    color: #ffffff;
    animation: modalPop 0.25s ease;
}
@keyframes modalPop { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.edit-modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding-bottom: 16px; border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    margin-bottom: 20px;
}
.edit-modal-title {
    font-size: 20px; font-weight: 800; color: #ffffff; margin: 0;
    display: flex; align-items: center; gap: 10px;
}
.edit-modal-close {
    background: none; border: none; color: #9ca3af; font-size: 20px; cursor: pointer;
    transition: color 0.2s ease;
}
.edit-modal-close:hover { color: #ffffff; }
.edit-modal-form { display: flex; flex-direction: column; gap: 16px; }
.form-group-item { display: flex; flex-direction: column; gap: 6px; }
.form-label { font-size: 11px; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.05em; }
.form-input {
    padding: 10px 14px; border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    background: rgba(255, 255, 255, 0.05);
    color: #ffffff; font-size: 14px; font-family: 'Poppins', sans-serif;
    outline: none; transition: border-color 0.2s ease;
}
.form-input:focus { border-color: #0f766e; }
.disabled-input { opacity: 0.6; cursor: not-allowed; background: rgba(255, 255, 255, 0.02); }
.form-subtext { font-size: 11px; color: #71717a; }
.form-checkbox-card {
    padding: 12px 16px; border-radius: 12px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
}
.checkbox-row { display: flex; align-items: center; gap: 8px; font-size: 13.5px; font-weight: 600; color: #ffffff; cursor: pointer; }
.offers-section { display: flex; flex-direction: column; gap: 8px; }
.offers-grid { display: flex; flex-wrap: wrap; gap: 8px; }
.checkbox-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 12px; border-radius: 10px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    font-size: 12.5px; color: #e4e4e7; cursor: pointer;
    transition: background 0.2s ease;
}
.checkbox-pill:hover { background: rgba(255, 255, 255, 0.08); }
.edit-modal-footer {
    display: flex; justify-content: flex-end; gap: 12px;
    padding-top: 18px; border-top: 1px solid rgba(255, 255, 255, 0.1);
    margin-top: 6px;
}
.btn-primary-teal {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 22px; border-radius: 10px;
    background: linear-gradient(135deg, #0d9488, #0f766e);
    color: #ffffff !important;
    font-weight: 700; font-size: 13.5px;
    border: 1.5px solid #55e087 !important;
    cursor: pointer;
    transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 4px 15px rgba(15, 118, 110, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.2);
    position: relative;
    overflow: hidden;
}
.btn-primary-teal:hover {
    background: linear-gradient(135deg, #0f766e, #115e59);
    border-color: #55e087 !important;
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(85, 224, 135, 0.35), 0 0 12px rgba(85, 224, 135, 0.3);
}
.btn-primary-teal:active {
    transform: scale(0.95);
    box-shadow: 0 2px 8px rgba(15, 118, 110, 0.3);
}

/* Save Submit Pulse Animation */
@keyframes savePulse {
    0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(85, 224, 135, 0.8); }
    50% { transform: scale(1.05); box-shadow: 0 0 0 14px rgba(85, 224, 135, 0); }
    100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(85, 224, 135, 0); }
}
.btn-saving-anim {
    animation: savePulse 0.5s ease-in-out infinite !important;
    background: #059669 !important;
    border-color: #55e087 !important;
}

.btn-cancel-modal {
    padding: 10px 20px; border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    background: rgba(255, 255, 255, 0.08);
    color: #ffffff; font-weight: 600; font-size: 13.5px;
    cursor: pointer; transition: background 0.2s ease;
    font-family: 'Poppins', sans-serif;
}
.btn-cancel-modal:hover { background: rgba(255, 255, 255, 0.15); }

/* ── Page entrance fade-in ── */
@keyframes fadeInUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
.topbar { animation: fadeInUp .45s ease both; }
.wrap   { animation: fadeInUp .55s .08s ease both; }
@media (prefers-reduced-motion: reduce) { .topbar, .wrap { animation: none; } }
</style>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body style="margin:0; padding:0; background:var(--bg); color:var(--text); height:100vh; overflow:hidden;">
<div class="flex h-screen w-screen overflow-hidden bg-[#0b0b0b] app-layout" style="display:flex; width:100vw; height:100vh; overflow:hidden;">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<main class="app-main flex-1 h-full overflow-y-auto p-6" style="flex:1; height:100%; overflow-y:auto;">

<div class="topbar">
    <a href="products.php" class="btn-nav icon-only" title="Back to Products"><i class="fa-solid fa-arrow-left"></i></a>
    <div class="brand-icon"><i class="fa-solid fa-tags"></i></div>
    <div class="brand-text">
        <span class="brand-title"><?= __('manage_categories', 'Manage Categories') ?></span>
        <span class="brand-sub">Bird's Nest Coffee &rsaquo; <?= __('catalog', 'Catalog') ?></span>
    </div>
    <div class="topbar-right">
        <button class="btn-nav icon-only" onclick="toggleTheme()" title="Toggle theme"><i class="fa-solid fa-moon" id="themeIcon"></i></button>
    </div>
</div>

<div class="wrap">
    <?php if ($flash): ?>
    <div id="pageFlash" data-type="<?= he($flash['type']) ?>" data-msg="<?= he($flash['msg']) ?>" style="display:none;"></div>
    <?php endif; ?>

    <div style="display:flex;justify-content:flex-end;margin-bottom:16px;">
        <button type="button" class="btn-primary-teal" onclick="openAddCategoryModal()"><i class="fa-solid fa-plus"></i> <?= __('add_category', 'Add Category') ?></button>
    </div>

    <div class="vo-stats-grid">
        <div class="vo-stat-box active" data-filter="all" onclick="setCatFilter('all')" title="All categories">
            <div class="vo-stat-icon all-orders">
                <i class="fa-solid fa-layer-group"></i>
            </div>
            <div class="vo-stat-content">
                <span class="vo-stat-title"><?= __('all_categories', 'All Categories') ?></span>
                <span class="vo-stat-value"><?= $totalCats ?></span>
                <span class="vo-stat-sub"><?= __('total', 'Total') ?></span>
            </div>
        </div>

        <div class="vo-stat-box" data-filter="active" onclick="setCatFilter('active')" title="Active categories">
            <div class="vo-stat-icon complete-orders">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <div class="vo-stat-content">
                <span class="vo-stat-title"><?= __('active', 'Active') ?></span>
                <span class="vo-stat-value"><?= $activeCats ?></span>
                <span class="vo-stat-sub"><?= __('visible_on_menu', 'Visible on menu') ?></span>
            </div>
        </div>

        <div class="vo-stat-box" data-filter="inactive" onclick="setCatFilter('inactive')" title="Inactive categories">
            <div class="vo-stat-icon overdue-orders">
                <i class="fa-solid fa-circle-exclamation"></i>
            </div>
            <div class="vo-stat-content">
                <span class="vo-stat-title"><?= __('inactive', 'Inactive') ?></span>
                <span class="vo-stat-value"><?= $inactiveCats ?></span>
                <span class="vo-stat-sub"><?= __('hidden_from_menu', 'Hidden from menu') ?></span>
            </div>
        </div>
    </div>

    <div class="cat-table-wrapper">
    <table class="cat-table">
        <thead>
            <tr>
                <th style="width:70px"><?= __('col_no', 'No.') ?></th>
                <th style="width:44px"><?= __('image', 'Image') ?></th>
                <th><?= __('category_name', 'Name') ?></th>
                <th style="width:90px"><?= __('nav_products', 'Products') ?></th>
                <th style="width:120px"><?= __('offers', 'Offers') ?></th>
                <th style="width:80px"><?= __('active', 'Active') ?></th>
                <th style="width:150px"><?= __('actions', 'Actions') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($categories as $i => $c): ?>
            <tr class="cat-row <?= $c['is_active'] ? '' : 'inactive' ?>" data-active="<?= (int)$c['is_active'] ?>">
                <td><strong><?= $i + 1 ?></strong></td>
                <td>
                    <?php $__icon = $c['icon'] ?: 'fa-circle'; ?>
                    <?php if (str_contains($__icon, '/')): ?>
                    <span class="cat-icon" style="overflow:hidden;background:rgba(15,118,110,.15);"><img src="<?= he($__icon) ?>" alt="" style="width:100%;height:100%;object-fit:cover;"></span>
                    <?php else: ?>
                    <span class="cat-icon"><i class="fa-solid <?= he($__icon) ?>"></i></span>
                    <?php endif; ?>
                </td>
                <td><?= he($c['name']) ?><?php if (!$c['is_active']): ?> <span class="pill pill-inactive"><?= __('inactive', 'Inactive') ?></span><?php endif; ?></td>
                <td><?= (int)$c['product_count'] ?></td>
                <td style="white-space:nowrap;">
                    <div style="display:inline-flex;align-items:center;gap:4px;white-space:nowrap;">
                        <?php foreach ([[__('sweet','Sweet'),$c['offer_sweetness']],[__('ice','Ice'),$c['offer_ice']],[__('milk','Milk'),$c['offer_milk']],[__('add_ons','Add-ons'),$c['offer_addons']]] as $__o): ?>
                        <span class="pill" title="<?= $__o[1] ? 'Offered' : 'Hidden' ?>" style="white-space:nowrap;<?= $__o[1] ? 'background:rgba(85,224,135,.12);color:var(--ok);' : 'background:rgba(255,95,95,.10);color:var(--danger);opacity:.55;' ?>"><?= $__o[0] ?></span>
                        <?php endforeach; ?>
                    </div>
                </td>
                <td>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="category_id" value="<?= (int)$c['category_id'] ?>">
                        <button type="submit" class="act-link"><?= $c['is_active'] ? __('on', 'On') : __('off', 'Off') ?></button>
                    </form>
                </td>
                <td style="white-space:nowrap;">
                    <button type="button" class="act-link" onclick="openEditCategoryModal(<?= htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8') ?>)"><?= __('edit', 'Edit') ?></button>
                    <?php if ((int)$c['product_count'] > 0): ?>
                    <button type="button" class="act-link danger-link" disabled title="Cannot delete: <?= (int)$c['product_count'] ?> product(s) use this category"><?= __('delete', 'Delete') ?></button>
                    <?php else: ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this category? This cannot be undone.');">
                        <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="category_id" value="<?= (int)$c['category_id'] ?>">
                        <button type="submit" class="act-link danger-link"><?= __('delete', 'Delete') ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Edit Category Modal Popup -->
<div id="editCategoryModal" class="edit-modal-overlay">
    <div class="edit-modal-box">
        <div class="edit-modal-header">
            <h3 class="edit-modal-title">
                <i class="fa-solid fa-pen-to-square" style="color:#d1904b;"></i> Edit Category
            </h3>
            <button type="button" class="edit-modal-close" onclick="closeEditCategoryModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form method="POST" class="edit-modal-form" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="category_id" id="edit_cat_id">
            
            <div class="form-group-item">
                <label class="form-label">Category Name</label>
                <input type="text" name="name" id="edit_cat_name" required class="form-input" placeholder="Category Name">
            </div>

            <div class="form-group-item">
                <label class="form-label">Icon Image</label>
                <div style="display:flex;align-items:center;gap:12px;">
                    <img id="edit_cat_icon_preview" src="" alt="" style="width:48px;height:48px;border-radius:10px;object-fit:cover;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.05);display:none;">
                    <input type="file" name="icon" id="edit_cat_icon" class="form-input" onchange="previewIconImage(this,'edit_cat_icon_preview')">
                </div>
                <span class="form-subtext">Upload an icon file (any type). Leave empty to keep the current icon.</span>
            </div>

            <div class="form-group-item">
                <label class="form-label">Slug (Permanent)</label>
                <input type="text" id="edit_cat_slug" readonly class="form-input disabled-input" title="Permanent identifier linking products">
            </div>

            <div class="form-checkbox-card">
                <label class="checkbox-row">
                    <input type="checkbox" name="is_active" id="edit_is_active">
                    <span>Active (Visible on POS menu)</span>
                </label>
            </div>

            <div class="offers-section">
                <label class="form-label">Product Customization Offers</label>
                <div class="offers-grid">
                    <label class="checkbox-pill"><input type="checkbox" name="offer_sweetness" id="edit_offer_sweetness"> <span>Sweetness</span></label>
                    <label class="checkbox-pill"><input type="checkbox" name="offer_ice" id="edit_offer_ice"> <span>Ice Level</span></label>
                    <label class="checkbox-pill"><input type="checkbox" name="offer_milk" id="edit_offer_milk"> <span>Milk Option</span></label>
                    <label class="checkbox-pill"><input type="checkbox" name="offer_addons" id="edit_offer_addons"> <span>Add-ons</span></label>
                    <label class="checkbox-pill"><input type="checkbox" name="earns_points" id="edit_earns_points"> <span>Loyalty Points</span></label>
                </div>
            </div>

            <div class="edit-modal-footer">
                <button type="button" class="btn-cancel-modal" onclick="closeEditCategoryModal()">Cancel</button>
                <button type="submit" class="btn-primary-teal"><i class="fa-solid fa-check"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Category Modal Popup -->
<div id="addCategoryModal" class="edit-modal-overlay">
    <div class="edit-modal-box">
        <div class="edit-modal-header">
            <h3 class="edit-modal-title">
                <i class="fa-solid fa-plus" style="color:#d1904b;"></i> Add Category
            </h3>
            <button type="button" class="edit-modal-close" onclick="closeAddCategoryModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form method="POST" class="edit-modal-form" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="create">

            <div class="form-group-item">
                <label class="form-label">Category Name</label>
                <input type="text" name="name" id="add_cat_name" required class="form-input" placeholder="e.g. Smoothies" oninput="updateAddSlugPreview()">
                <span class="form-subtext" id="add_slugPreview">Slug will be: —</span>
            </div>

            <div class="form-group-item">
                <label class="form-label">Icon Image</label>
                <div style="display:flex;align-items:center;gap:12px;">
                    <img id="add_cat_icon_preview" src="" alt="" style="width:48px;height:48px;border-radius:10px;object-fit:cover;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.05);display:none;">
                    <input type="file" name="icon" id="add_cat_icon" class="form-input" onchange="previewIconImage(this,'add_cat_icon_preview')">
                </div>
                <span class="form-subtext">Upload an icon file (any type).</span>
            </div>

            <div class="form-checkbox-card">
                <label class="checkbox-row">
                    <input type="checkbox" name="is_active" checked>
                    <span>Active (Visible on POS menu)</span>
                </label>
            </div>

            <div class="offers-section">
                <label class="form-label">Product Customization Offers</label>
                <div class="offers-grid">
                    <label class="checkbox-pill"><input type="checkbox" name="offer_sweetness" checked> <span>Sweetness</span></label>
                    <label class="checkbox-pill"><input type="checkbox" name="offer_ice" checked> <span>Ice Level</span></label>
                    <label class="checkbox-pill"><input type="checkbox" name="offer_milk" checked> <span>Milk Option</span></label>
                    <label class="checkbox-pill"><input type="checkbox" name="offer_addons" checked> <span>Add-ons</span></label>
                    <label class="checkbox-pill"><input type="checkbox" name="earns_points" checked> <span>Loyalty Points</span></label>
                </div>
            </div>

            <div class="edit-modal-footer">
                <button type="button" class="btn-cancel-modal" onclick="closeAddCategoryModal()">Cancel</button>
                <button type="submit" class="btn-primary-teal"><i class="fa-solid fa-check"></i> Add Category</button>
            </div>
        </form>
    </div>
</div>

<div id="toast-container"></div>

<script>
function openEditCategoryModal(catData) {
    document.getElementById('edit_cat_id').value = catData.category_id;
    document.getElementById('edit_cat_name').value = catData.name;
    document.getElementById('edit_cat_icon').value = '';
    const prev = document.getElementById('edit_cat_icon_preview');
    const isImg = catData.icon && catData.icon.indexOf('/') !== -1;
    if (isImg) { prev.style.display = 'block'; prev.src = catData.icon; }
    else { prev.style.display = 'none'; prev.src = ''; }
    document.getElementById('edit_cat_slug').value = catData.slug;
    document.getElementById('edit_is_active').checked = Number(catData.is_active) === 1;
    document.getElementById('edit_offer_sweetness').checked = Number(catData.offer_sweetness) === 1;
    document.getElementById('edit_offer_ice').checked = Number(catData.offer_ice) === 1;
    document.getElementById('edit_offer_milk').checked = Number(catData.offer_milk) === 1;
    document.getElementById('edit_offer_addons').checked = Number(catData.offer_addons) === 1;
    document.getElementById('edit_earns_points').checked = Number(catData.earns_points) === 1;
    
    document.getElementById('editCategoryModal').style.display = 'flex';
}

function closeEditCategoryModal() {
    document.getElementById('editCategoryModal').style.display = 'none';
}

function openAddCategoryModal() {
    document.getElementById('add_cat_name').value = '';
    document.getElementById('add_cat_icon').value = '';
    document.getElementById('add_cat_icon_preview').style.display = 'none';
    document.getElementById('add_cat_icon_preview').src = '';
    document.getElementById('add_slugPreview').textContent = 'Slug will be: —';
    document.getElementById('addCategoryModal').style.display = 'flex';
}

function closeAddCategoryModal() {
    document.getElementById('addCategoryModal').style.display = 'none';
}

function updateAddSlugPreview() {
    const v = document.getElementById('add_cat_name').value.trim().replace(/\s+/g, ' ');
    document.getElementById('add_slugPreview').textContent = 'Slug will be: ' + (v || '—');
}

function previewIconImage(input, previewId) {
    const prev = document.getElementById(previewId);
    if (input.files && input.files[0]) {
        prev.style.display = 'block';
        prev.src = URL.createObjectURL(input.files[0]);
    } else {
        prev.style.display = 'none';
        prev.src = '';
    }
}

function toggleTheme() {
    const html = document.documentElement, icon = document.getElementById('themeIcon');
    if (html.getAttribute('data-theme') === 'light') { html.removeAttribute('data-theme'); icon.className = 'fa-solid fa-moon'; localStorage.setItem('theme','dark'); }
    else { html.setAttribute('data-theme','light'); icon.className = 'fa-solid fa-sun'; localStorage.setItem('theme','light'); }
}

function setCatFilter(filter) {
    document.querySelectorAll('.vo-stat-box').forEach(box => {
        box.classList.toggle('active', box.getAttribute('data-filter') === filter);
    });
    document.querySelectorAll('.cat-row').forEach(row => {
        const isActive = Number(row.getAttribute('data-active')) === 1;
        const show = filter === 'all' || (filter === 'active' && isActive) || (filter === 'inactive' && !isActive);
        row.style.display = show ? '' : 'none';
    });
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function() {
            const btn = form.querySelector('.btn-primary-teal');
            if (btn) {
                btn.classList.add('btn-saving-anim');
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
            }
        });
    });

    const pf = document.getElementById('pageFlash');
    if (pf) showToast(pf.getAttribute('data-msg'), pf.getAttribute('data-type'));
});

function showToast(message, type) {
    type = type || 'success';
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    const icon = type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation';
    toast.innerHTML = '<i class="fa-solid ' + icon + '"></i><span>' + message + '</span>';
    container.appendChild(toast);
    requestAnimationFrame(function() { toast.classList.add('show'); });
    setTimeout(function() { toast.classList.remove('show'); setTimeout(function() { toast.remove(); }, 350); }, 2800);
}
</script>
</main>
</div>
</body>
</html>
