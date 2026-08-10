<?php
require 'auth.php';
require 'config.php';
if (!can('manage_recipes')) { header("Location: products.php"); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

/* ================= SAVE RECIPE ================= */
if (isset($_POST['save_recipe'])) {

    // CSRF: this endpoint wipes + rewrites a product's recipe, so guard it.
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(["ok" => 0, "error" => "Security check failed. Please refresh and try again."]);
            exit;
        }
        header("Location: manage_recipe.php?error=csrf");
        exit;
    }

    $product_id = (int)$_POST['product_id'];

    // Guard: a missing/zero product_id would delete WHERE product_id=0 and then
    // insert orphan (0, ...) recipe rows tied to no product.
    if ($product_id <= 0) {
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(["ok" => 0, "error" => "Please choose a product."]);
            exit;
        }
        header("Location: manage_recipe.php?error=noproduct");
        exit;
    }

    // Clear old recipe
    mysqli_query($conn, "DELETE FROM product_ingredients WHERE product_id = $product_id");

    // Base ingredients (multiple) — aggregate by ingredient so the same base picked
    // in two rows becomes ONE row. product_ingredients has no unique key, so duplicate
    // rows would otherwise double-count that ingredient's stock at order time.
    if (!empty($_POST['base_ingredient'])) {
        $baseAgg = [];
        foreach ($_POST['base_ingredient'] as $i => $ing_id) {

            $ing_id = (int)$ing_id;
            $amountRaw = $_POST['base_amount'][$i] ?? '';

            // STRICT validation
            if ($ing_id > 0 && $amountRaw !== '' && is_numeric($amountRaw)) {
                $amount = (int)$amountRaw; // FORCE INTEGER (g/ml)

                if ($amount > 0) {
                    $baseAgg[$ing_id] = ($baseAgg[$ing_id] ?? 0) + $amount;
                }
            }
        }
        foreach ($baseAgg as $ing_id => $amount) {
            mysqli_query($conn, "
                INSERT INTO product_ingredients (product_id, ingredient_id, amount_used)
                VALUES ($product_id, $ing_id, $amount)
            ");
        }
    }

    // Milk
    if (isset($_POST['milk_amount']) && $_POST['milk_amount'] !== '') {
        $milk_amount = (int)$_POST['milk_amount'];

        if ($milk_amount > 0) {
            $milk = mysqli_fetch_assoc(mysqli_query(
                $conn,
                "SELECT ingredient_id FROM ingredients WHERE ingredient_name='Milk base' LIMIT 1"
            ));

            if ($milk) {
                mysqli_query($conn, "
                    INSERT INTO product_ingredients (product_id, ingredient_id, amount_used)
                    VALUES ($product_id, {$milk['ingredient_id']}, $milk_amount)
                ");
            }
        }
    }

    // Sugar Syrup
    if (isset($_POST['syrup_amount']) && $_POST['syrup_amount'] !== '') {
        $syrup_amount = (int)$_POST['syrup_amount'];

        if ($syrup_amount > 0) {
            $syrup = mysqli_fetch_assoc(mysqli_query(
                $conn,
                "SELECT ingredient_id FROM ingredients WHERE ingredient_name='Sugar Syrup' LIMIT 1"
            ));

            if ($syrup) {
                mysqli_query($conn, "
                    INSERT INTO product_ingredients (product_id, ingredient_id, amount_used)
                    VALUES ($product_id, {$syrup['ingredient_id']}, $syrup_amount)
                ");
            }
        }
    }

    // Inline editor on recipes_view.php saves via fetch() and expects JSON, not a redirect
    if (!empty($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(["ok" => 1]);
        exit;
    }

    header("Location: manage_recipe.php?success=1");
    exit;
}

/* ================= LOAD DATA ================= */
$products = mysqli_query($conn, "SELECT * FROM products ORDER BY name");

$bases = mysqli_query($conn, "
    SELECT * FROM ingredients
    WHERE ingredient_name NOT IN ('Milk base','Sugar Syrup')
    ORDER BY ingredient_name
");

$is_edit = isset($_GET['product_id']);
$edit_id = $is_edit ? (int)$_GET['product_id'] : 0;

$base_items = [];
$milk_amount = '';
$syrup_amount = '';

if ($is_edit) {
    $q = mysqli_query($conn, "
        SELECT pi.ingredient_id, pi.amount_used, i.ingredient_name
        FROM product_ingredients pi
        JOIN ingredients i ON pi.ingredient_id = i.ingredient_id
        WHERE pi.product_id = $edit_id
    ");

    while ($r = mysqli_fetch_assoc($q)) {
        if ($r['ingredient_name'] === 'Milk base') {
            $milk_amount = $r['amount_used'];
        } elseif ($r['ingredient_name'] === 'Sugar Syrup') {
            $syrup_amount = $r['amount_used'];
        } else {
            $base_items[] = $r;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Recipe | Obsidian Cafe</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
/* ── RESET & ROOT ── */
:root {
    --bg: #0c0c0c;
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
    
    /* ── Shadow System ── */
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.3);
    --shadow-md: 0 4px 20px rgba(0,0,0,0.4);
    --shadow-lg: 0 8px 40px rgba(0,0,0,0.5);
    --shadow-accent: 0 4px 20px rgba(209, 144, 75, 0.15);
    
    /* ── Transitions ── */
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* ── Light Theme Variables ── */
[data-theme="light"] {
    --bg:           #F0F2F5;
    --bg-card:      #FFFFFF;
    --bg-card-hover:#F5F7FA;
    --bg-input:     #F9FAFB;
    --border:       #E5E7EB;
    --border-hover: #D1D5DB;
    --text:         #111827;
    --text-muted:   #6B7280;
    --text-light:   #ffffff;
    --accent: #d1904b;
    --accent-light: #e8b87a;
    --accent-dark: #a0702a;
}

/* ── Light mode: inputs/selects — defeat browser dark-mode extension ── */
[data-theme="light"] input,
[data-theme="light"] select,
[data-theme="light"] textarea {
    background-color: var(--bg-input) !important;
    color: var(--text) !important;
    border-color: var(--border) !important;
    color-scheme: light;
}

/* ── Light mode: table ── */
[data-theme="light"] tbody tr {
    border-bottom-color: rgba(0,0,0,0.06);
}
[data-theme="light"] tbody tr:hover {
    background: rgba(0,0,0,0.025);
}
[data-theme="light"] th {
    background: rgba(0,0,0,0.03);
}


/* ── RESET: Remove all scrollbars ── */
* { 
    box-sizing: border-box; 
    margin: 0; 
    padding: 0; 
}

html, body {
    width: 100%;
    height: 100%;
    overflow: hidden;
    margin: 0;
    padding: 0;
}

body {
    font-family: 'Poppins', sans-serif;
    background: var(--bg);
    color: var(--text);
    line-height: 1.6;
    min-height: 100vh;
    min-height: 100dvh;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 20px;
    position: relative;
}

/* ── Background decorative elements ── */
body::before {
    content: '';
    position: absolute;
    top: -200px;
    right: -200px;
    width: 500px;
    height: 500px;
    background: radial-gradient(circle, rgba(209, 144, 75, 0.06) 0%, transparent 70%);
    border-radius: 50%;
    z-index: 0;
}

body::after {
    content: '';
    position: absolute;
    bottom: -200px;
    left: -200px;
    width: 500px;
    height: 500px;
    background: radial-gradient(circle, rgba(209, 144, 75, 0.04) 0%, transparent 70%);
    border-radius: 50%;
    z-index: 0;
}

/* ── Scrollbars: invisible, still fully scrollable ──
   Needs all three: Firefox reads scrollbar-width, old Edge reads -ms-overflow-style,
   Chrome/Safari need the pseudo-element. Miss one and the bar comes back there. */
* { scrollbar-width: none; -ms-overflow-style: none; }
::-webkit-scrollbar { width: 0; height: 0; display: none; }

/* ── Back Button ── */
.back-btn {
    position: fixed;
    top: 24px;
    left: 24px;
    color: #d1904b;
    text-decoration: none;
    font-weight: 600;
    font-size: 15px;
    transition: var(--transition);
    display: flex;
    align-items: center;
    gap: 7px;
    background: rgba(209,144,75,.08);
    padding: 10px 18px;
    border-radius: 12px;
    border: 1px solid rgba(209,144,75,.35);
    z-index: 100;
}

.back-btn:hover {
    color: var(--accent);
    border-color: var(--accent);
    transform: translateX(-4px);
}

/* ── Main Card ── */
.card-wrapper {
    width: 100%;
    max-width: 820px;
    max-height: 90vh;
    overflow-y: auto;
    padding: 4px;
}

/* ── Card wrapper scrolls with no visible bar ── */
.card-wrapper::-webkit-scrollbar { width: 0; height: 0; display: none; }

.card {
    width: 100%;
    margin: 0 auto;
    background: var(--bg-card);
    padding: 48px;
    border-radius: 24px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-lg);
    position: relative;
    animation: slideUp 0.6s ease;
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(40px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* ── Card Header ── */
.card-header {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 32px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border);
}

.card-header i {
    font-size: 32px;
    color: var(--accent);
    background: rgba(209, 144, 75, 0.1);
    padding: 12px;
    border-radius: 12px;
}

.card-header h2 {
    color: var(--text);
    font-size: 26px;
    font-weight: 700;
}

.card-header h2 span {
    color: var(--accent);
}

/* ── Success Message ── */
.success {
    background: rgba(85, 224, 135, 0.1);
    color: #55e087;
    padding: 14px 20px;
    border-radius: 12px;
    text-align: center;
    margin-bottom: 24px;
    border: 1px solid rgba(85, 224, 135, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    animation: fadeIn 0.5s ease;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: scale(0.95);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

.success i {
    font-size: 20px;
}

/* ── Post-create handoff note (arrives from add_product.php) ── */
.created-note {
    background: rgba(209, 144, 75, 0.09);
    border: 1px solid rgba(209, 144, 75, 0.28);
    color: var(--text);
    padding: 14px 18px;
    border-radius: 12px;
    margin-bottom: 24px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    font-size: 13.5px;
    line-height: 1.6;
    animation: fadeIn 0.5s ease;
}
.created-note i { color: var(--accent); font-size: 18px; margin-top: 2px; }
.created-note a { color: var(--accent); font-weight: 600; text-decoration: none; white-space: nowrap; }
.created-note a:hover { text-decoration: underline; }

/* ── Form ── */
form {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

/* ── Form Groups ── */
.form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.form-group label {
    color: var(--accent);
    font-weight: 600;
    font-size: 14px;
    letter-spacing: 0.3px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.form-group label i {
    font-size: 16px;
}

/* ── Select & Input ── */
select, input[type="number"], input[type="text"] {
    width: 100%;
    padding: 14px 16px;
    border-radius: 12px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    color: var(--text);
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    transition: var(--transition);
    outline: none;
}

select:focus, input:focus {
    border-color: var(--accent);
    background: var(--bg-card-hover);
    box-shadow: var(--shadow-accent);
}

select option {
    background: var(--bg-card);
    color: var(--text);
}

/* ── Disabled Input ── */
input:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* ── Base Ingredient Row ── */
.base-row {
    display: flex;
    gap: 12px;
    margin-top: 8px;
    animation: slideUp 0.3s ease;
}

.base-row select {
    flex: 2;
}

.base-row input {
    flex: 1;
}

/* ── Add Button ── */
.add-btn {
    margin-top: 8px;
    background: rgba(209, 144, 75, 0.08);
    color: var(--accent);
    border: 1px dashed var(--border-hover);
    padding: 12px;
    border-radius: 12px;
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    font-weight: 500;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.add-btn:hover {
    background: rgba(209, 144, 75, 0.15);
    border-color: var(--accent);
    transform: translateY(-2px);
}

/* ── Submit Button (SMALLER WIDTH) ── */
.submit-btn {
    margin-top: 12px;
    padding: 14px 40px;
    border: none;
    border-radius: 14px;
    background: linear-gradient(135deg, var(--accent), var(--accent-light));
    color: #000;
    font-weight: 700;
    font-size: 15px;
    cursor: pointer;
    transition: var(--transition);
    font-family: 'Poppins', sans-serif;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    position: relative;
    overflow: hidden;
    width: auto;
    min-width: 200px;
    margin: 0 auto;
}

/* ── FIXED: Full button glow ── */
.submit-btn::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    background: rgba(255, 255, 255, 0.25);
    border-radius: 50%;
    transform: translate(-50%, -50%);
    transition: width 0.6s ease, height 0.6s ease;
    pointer-events: none;
}

.submit-btn:hover::after {
    width: 600px;
    height: 600px;
}

.submit-btn:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-accent);
    filter: brightness(1.1);
}

.submit-btn:active {
    transform: translateY(0);
}

/* ── Submit Button Wrapper ── */
.submit-wrapper {
    display: flex;
    justify-content: center;
    margin-top: 12px;
}

/* ── Theme Toggle ── */
.theme-toggle {
    position: fixed;
    top: 24px;
    right: 24px;
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 50px;
    padding: 8px 16px;
    cursor: pointer;
    transition: var(--transition);
    color: var(--text);
    font-size: 14px;
    z-index: 100;
}

.theme-toggle:hover {
    border-color: var(--accent);
    box-shadow: var(--shadow-accent);
}

.theme-toggle i {
    font-size: 18px;
    color: var(--accent);
}

/* ── Responsive ── */
@media (max-width: 768px) {
    body {
        padding: 16px;
    }
    
    .card {
        padding: 28px 20px;
    }
    
    .back-btn {
        top: 16px;
        left: 16px;
        padding: 8px 14px;
        font-size: 13px;
    }
    
    .theme-toggle {
        top: 16px;
        right: 16px;
        padding: 6px 12px;
        font-size: 12px;
    }
    
    .card-header h2 {
        font-size: 22px;
    }
    
    .card-header i {
        font-size: 24px;
        padding: 10px;
    }
    
    .base-row {
        flex-direction: column;
        gap: 8px;
    }
    
    .submit-btn {
        font-size: 14px;
        padding: 12px 30px;
        min-width: 160px;
    }
}

@media (max-width: 480px) {
    .card {
        padding: 20px 14px;
    }
    
    .card-header h2 {
        font-size: 18px;
    }
    
    .card-header i {
        font-size: 20px;
        padding: 8px;
    }
    
    select, input {
        font-size: 13px;
        padding: 12px 14px;
    }
    
    .submit-btn {
        font-size: 13px;
        padding: 10px 24px;
        min-width: 140px;
    }
}
</style>
</head>

<body>

<!-- Back Button -->
<a class="back-btn" href="products.php">
    <i class="fa-solid fa-arrow-left"></i> Back
</a>

<!-- Theme Toggle -->
<button class="theme-toggle" onclick="toggleTheme()">
    <i class="fa-solid fa-moon" id="themeIcon"></i>
    <span id="themeText">Dark</span>
</button>

<!-- Main Card -->
<div class="card-wrapper">
    <div class="card">
        <div class="card-header">
            <i class="fa-solid fa-book-open"></i>
            <h2>Manage <span>Recipe</span></h2>
        </div>

        <?php if (isset($_GET['success'])): ?>
        <div class="success">
            <i class="fa-solid fa-circle-check"></i>
            Recipe saved successfully
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['created']) && $edit_id > 0):
            $__nm = $conn->prepare("SELECT name FROM products WHERE product_id = ?");
            $__nm->bind_param("i", $edit_id); $__nm->execute();
            $__new_name = ($__nm->get_result()->fetch_assoc())['name'] ?? 'Product';
        ?>
        <div class="created-note">
            <i class="fa-solid fa-circle-check"></i>
            <div>
                <strong><?= htmlspecialchars($__new_name) ?></strong> was created.
                Set its recipe now so stock deducts automatically on every sale.
                <a href="products.php">Skip for now →</a>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES) ?>">
            <!-- Product Selection -->
            <div class="form-group">
                <label><i class="fa-solid fa-cube"></i> Select Product</label>
                <select name="product_id" required>
                    <option value="">— Choose a product —</option>
                    <?php while ($p = mysqli_fetch_assoc($products)): ?>
                    <option value="<?= $p['product_id'] ?>" <?= $is_edit && $p['product_id'] == $edit_id ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['name']) ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <!-- Coffee / Tea Base -->
            <div class="form-group">
                <label><i class="fa-solid fa-leaf"></i> Coffee / Tea Base</label>
                <div id="baseBox">
                    <?php if ($base_items): ?>
                        <?php foreach ($base_items as $b): ?>
                        <div class="base-row">
                            <select name="base_ingredient[]">
                                <?php mysqli_data_seek($bases, 0); while ($i = mysqli_fetch_assoc($bases)): ?>
                                <option value="<?= $i['ingredient_id'] ?>" <?= $i['ingredient_id'] == $b['ingredient_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($i['ingredient_name']) ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                            <input type="number" name="base_amount[]" min="0" step="1" value="<?= (int)$b['amount_used'] ?>" placeholder="Amount (g/ml)">
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="base-row">
                            <select name="base_ingredient[]">
                                <?php mysqli_data_seek($bases, 0); while ($i = mysqli_fetch_assoc($bases)): ?>
                                <option value="<?= $i['ingredient_id'] ?>">
                                    <?= htmlspecialchars($i['ingredient_name']) ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                            <input type="number" name="base_amount[]" min="0" step="1" placeholder="Amount (g/ml)">
                        </div>
                    <?php endif; ?>
                </div>
                <button type="button" class="add-btn" onclick="addBase()">
                    <i class="fa-solid fa-plus"></i> Add another base ingredient
                </button>
            </div>

            <!-- Milk -->
            <div class="form-group">
                <label><i class="fa-solid fa-cow"></i> Milk</label>
                <div class="base-row">
                    <input type="text" value="Milk base" disabled>
                    <input type="number" name="milk_amount" min="0" step="1" value="<?= (int)$milk_amount ?>" placeholder="Amount (ml)">
                </div>
            </div>

            <!-- Sugar Syrup -->
            <div class="form-group">
                <label><i class="fa-solid fa-droplet"></i> Sugar Syrup</label>
                <div class="base-row">
                    <input type="text" value="Sugar Syrup" disabled>
                    <input type="number" name="syrup_amount" min="0" step="1" value="<?= (int)$syrup_amount ?>" placeholder="Amount (ml)">
                </div>
            </div>

            <!-- Submit Button Wrapper -->
            <div class="submit-wrapper">
                <button type="submit" name="save_recipe" class="submit-btn">
                    <i class="fa-solid fa-floppy-disk"></i> Save Recipe
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function addBase() {
    const box = document.getElementById("baseBox");
    const row = box.children[0].cloneNode(true);
    row.querySelectorAll("select, input").forEach(e => e.value = "");
    box.appendChild(row);
}

// ── Theme Toggle ──
function toggleTheme() {
    const html = document.documentElement;
    const icon = document.getElementById('themeIcon');
    const text = document.getElementById('themeText');
    
    if (html.getAttribute('data-theme') === 'light') {
        html.removeAttribute('data-theme');
        if (icon) icon.className = 'fa-solid fa-moon';
        if (text) text.textContent = 'Dark';
        localStorage.setItem('theme', 'dark');
    } else {
        html.setAttribute('data-theme', 'light');
        if (icon) icon.className = 'fa-solid fa-sun';
        if (text) text.textContent = 'Light';
        localStorage.setItem('theme', 'light');
    }
}

// ── Load saved theme ──
document.addEventListener('DOMContentLoaded', function() {
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'light') {
        document.documentElement.setAttribute('data-theme', 'light');
        const icon = document.getElementById('themeIcon');
        const text = document.getElementById('themeText');
        if (icon) icon.className = 'fa-solid fa-sun';
        if (text) text.textContent = 'Light';
    }
});
</script>

</body>
</html>