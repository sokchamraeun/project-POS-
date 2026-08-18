<?php
/**
 * Bird's Nest Coffee POS - Raw Ingredients & Recipe Supplies Management
 * Full-stack PHP + PDO + MySQL + Tailwind CSS + Vanilla JS/AJAX
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lang.php';

// Role check: Only Admin, Manager, and authorized staff can access stock management
$_user_role = $_SESSION['role'] ?? 'staff';
if (!in_array($_user_role, ['admin', 'manager', 'staff'], true)) {
    header("Location: dashboard.php?denied=1");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Establish PDO Connection ──
try {
    $pdo_dsn = "mysql:host={$servername};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($pdo_dsn, $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    die("Database Connection Error: " . htmlspecialchars($e->getMessage()));
}

// ── Helper: Format Quantity ──
if (!function_exists('formatQty')) {
    function formatQty($qty): string {
        $f = (float)$qty;
        if (floor($f) == $f) {
            return number_format($f, 0);
        }
        return number_format($f, 2);
    }
}

// ── Helper: Calculate KPI Metrics for Ingredients ──
if (!function_exists('getIngredientKPIs')) {
    function getIngredientKPIs(PDO $pdo): array {
        $stmt = $pdo->query("SELECT 
            COUNT(*) as total_items,
            SUM(CASE WHEN quantity > alert_level THEN 1 ELSE 0 END) as in_stock,
            SUM(CASE WHEN quantity > 0 AND quantity <= alert_level THEN 1 ELSE 0 END) as low_stock,
            SUM(CASE WHEN quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock,
            SUM(quantity * cost_per_unit) as total_valuation,
            COUNT(DISTINCT category) as total_categories
        FROM stock_items WHERE item_type = 'ingredient' AND is_active = 1");
        $kpi = $stmt->fetch() ?: [];
        
        return [
            'total_items'     => (int)($kpi['total_items'] ?? 0),
            'in_stock'        => (int)($kpi['in_stock'] ?? 0),
            'low_stock'       => (int)($kpi['low_stock'] ?? 0),
            'out_of_stock'    => (int)($kpi['out_of_stock'] ?? 0),
            'total_valuation' => (float)($kpi['total_valuation'] ?? 0),
            'total_categories'=> (int)($kpi['total_categories'] ?? 0),
        ];
    }
}

// ── Helper: Estimated Drink Capacity ──
if (!function_exists('estimateDrinkCapacity')) {
    function estimateDrinkCapacity(string $category, float $qty, string $unit): string {
        $lang = function_exists('current_lang') ? current_lang() : 'en';
        if ($qty <= 0) return $lang === 'km' ? '0 កែវ' : '0 cups';
        
        if ($category === 'Dairy' && $unit === 'ml') {
            $lattes = floor($qty / 150); // ~150ml milk per iced latte
            return $lang === 'km' ? "~{$lattes} កែវ (ឡាតេ)" : "~{$lattes} cups (latte)";
        }
        if ($category === 'Beans' && $unit === 'g') {
            $shots = floor($qty / 18); // ~18g per double espresso shot
            return $lang === 'km' ? "~{$shots} ស៊ុត" : "~{$shots} shots";
        }
        if ($category === 'Packaging' && $unit === 'pcs') {
            return floor($qty) . ($lang === 'km' ? " ចំណែក" : " servings");
        }
        if ($category === 'Syrups' && $unit === 'ml') {
            $pumps = floor($qty / 20); // ~20ml syrup per drink
            return $lang === 'km' ? "~{$pumps} កែវ" : "~{$pumps} drinks";
        }
        if ($category === 'Bakery / Toppings' && $unit === 'g') {
            $servings = floor($qty / 10); // ~10g matcha/cocoa per cup
            return $lang === 'km' ? "~{$servings} កែវ" : "~{$servings} cups";
        }
        return formatQty($qty) . " " . $unit;
    }
}

// ── Helper: Format Category Label ──
if (!function_exists('formatCategoryLabel')) {
    function formatCategoryLabel(string $category): string {
        $lang = function_exists('current_lang') ? current_lang() : 'en';
        if ($lang === 'km') {
            $map = [
                'Dairy' => 'ទឹកដោះគោ & ទឹក',
                'Beans' => 'គ្រាប់កាហ្វេ',
                'Syrups' => 'ស៊ីរ៉ូ & រសជាតិ',
                'Packaging' => 'ការវេចខ្ចប់',
                'Bakery / Toppings' => 'នំ / គ្រឿងបន្ថែម',
                'General Supplies' => 'សម្ភារៈទូទៅ'
            ];
            return $map[$category] ?? $category;
        }
        return $category;
    }
}

// ── Helper: Send JSON response ──
if (!function_exists('sendJsonResponse')) {
    function sendJsonResponse(array $payload, int $statusCode = 200): void {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ══════════════════════════════════════════════════════════════
// ── AJAX / API REQUEST HANDLER ──
// ══════════════════════════════════════════════════════════════
$reqMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($reqMethod === 'POST' || isset($_GET['action'])) {
    $action = $_POST['action'] ?? ($_GET['action'] ?? '');

    if (!empty($action)) {
        // Validate CSRF token for mutating POST actions
        if ($reqMethod === 'POST') {
            $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
            if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
                sendJsonResponse(['success' => false, 'message' => 'Invalid or expired session security token. Please refresh.'], 403);
            }
        }

        $recorded_by = $_SESSION['emp_name'] ?? ($_SESSION['username'] ?? 'Staff');

        // 1. Fetch Ingredients (JSON)
        if ($action === 'get_ingredient_data') {
            $catFilter = trim($_GET['category'] ?? '');
            $statusFilter = trim($_GET['status'] ?? '');
            $search = trim($_GET['search'] ?? '');
            $sortBy = trim($_GET['sort'] ?? 'name_asc');

            $sql = "SELECT * FROM stock_items WHERE item_type = 'ingredient' AND is_active = 1";
            $params = [];

            if ($catFilter !== '' && $catFilter !== 'all') {
                $sql .= " AND category = ?";
                $params[] = $catFilter;
            }

            if ($statusFilter === 'low_stock') {
                $sql .= " AND quantity > 0 AND quantity <= alert_level";
            } elseif ($statusFilter === 'out_of_stock') {
                $sql .= " AND quantity <= 0";
            } elseif ($statusFilter === 'in_stock') {
                $sql .= " AND quantity > alert_level";
            }

            if ($search !== '') {
                $sql .= " AND (item_name LIKE ? OR notes LIKE ? OR category LIKE ?)";
                $searchWild = "%{$search}%";
                $params[] = $searchWild;
                $params[] = $searchWild;
                $params[] = $searchWild;
            }

            switch ($sortBy) {
                case 'qty_asc':
                    $sql .= " ORDER BY quantity ASC, item_name ASC";
                    break;
                case 'qty_desc':
                    $sql .= " ORDER BY quantity DESC, item_name ASC";
                    break;
                case 'value_desc':
                    $sql .= " ORDER BY (quantity * cost_per_unit) DESC";
                    break;
                case 'newest':
                    $sql .= " ORDER BY item_id DESC";
                    break;
                case 'name_desc':
                    $sql .= " ORDER BY item_name DESC";
                    break;
                case 'name_asc':
                default:
                    $sql .= " ORDER BY item_name ASC";
                    break;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $items = $stmt->fetchAll();

            sendJsonResponse([
                'success' => true,
                'items'   => $items,
                'kpis'    => getIngredientKPIs($pdo)
            ]);
        }

        // 2. Get Single Item
        if ($action === 'get_item' || $action === 'get_single_item') {
            $itemId = (int)($_GET['item_id'] ?? ($_POST['item_id'] ?? 0));
            $stmt = $pdo->prepare("SELECT * FROM stock_items WHERE item_id = ? AND item_type = 'ingredient' AND is_active = 1 LIMIT 1");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();

            if (!$item) {
                sendJsonResponse(['success' => false, 'message' => 'Ingredient item not found.'], 404);
            }

            sendJsonResponse(['success' => true, 'item' => $item]);
        }

        // 3. Create New Raw Ingredient
        if ($action === 'create_item') {
            $name        = trim($_POST['item_name'] ?? '');
            $category    = trim($_POST['category'] ?? 'Dairy');
            $quantity    = (float)($_POST['quantity'] ?? 0);
            $unit        = trim($_POST['unit'] ?? 'g');
            $alert_level = (float)($_POST['alert_level'] ?? 10);
            $cost_unit   = (float)($_POST['cost_per_unit'] ?? 0);
            $notes       = trim($_POST['notes'] ?? '');

            if (empty($name)) {
                sendJsonResponse(['success' => false, 'message' => 'Ingredient name is required.'], 422);
            }

            $stmt = $pdo->prepare("INSERT INTO stock_items 
                (item_name, category, item_type, quantity, unit, alert_level, cost_per_unit, notes, is_active) 
                VALUES (?, ?, 'ingredient', ?, ?, ?, ?, ?, 1)");
            $stmt->execute([$name, $category, $quantity, $unit, $alert_level, $cost_unit, $notes]);
            $newId = (int)$pdo->lastInsertId();

            sendJsonResponse([
                'success' => true,
                'message' => "Ingredient '{$name}' created successfully!",
                'item_id' => $newId
            ]);
        }

        // 4. Quick Restock with Multiplier (e.g. 5 kg -> 5,000 g, 2 L -> 2,000 ml)
        if ($action === 'quick_restock') {
            $itemId    = (int)($_POST['item_id'] ?? 0);
            $rawQty    = (float)($_POST['quantity_added'] ?? 0);
            $mult      = (float)($_POST['unit_multiplier'] ?? 1.0);
            $qtyAdded  = $rawQty * max(1.0, $mult);
            $costUnit  = isset($_POST['cost_per_unit']) && $_POST['cost_per_unit'] !== '' ? (float)$_POST['cost_per_unit'] : null;
            $supplier  = trim($_POST['supplier'] ?? '');
            $notes     = trim($_POST['notes'] ?? '');

            if ($itemId <= 0 || $qtyAdded <= 0) {
                sendJsonResponse(['success' => false, 'message' => 'Invalid ingredient selection or quantity.'], 422);
            }

            $pdo->beginTransaction();
            try {
                $cStmt = $pdo->prepare("SELECT item_name, quantity, unit, cost_per_unit FROM stock_items WHERE item_id = ? AND is_active = 1 FOR UPDATE");
                $cStmt->execute([$itemId]);
                $cur = $cStmt->fetch();

                if (!$cur) {
                    $pdo->rollBack();
                    sendJsonResponse(['success' => false, 'message' => 'Ingredient not found.'], 404);
                }

                $activeCost = ($costUnit !== null && $costUnit >= 0) ? $costUnit : (float)$cur['cost_per_unit'];
                $totalCost  = $qtyAdded * $activeCost;

                $uStmt = $pdo->prepare("UPDATE stock_items SET 
                    quantity = quantity + ?, 
                    cost_per_unit = ?, 
                    updated_at = NOW() 
                    WHERE item_id = ?");
                $uStmt->execute([$qtyAdded, $activeCost, $itemId]);

                $rStmt = $pdo->prepare("INSERT INTO stock_restocks 
                    (item_id, quantity_added, cost_per_unit, total_cost, supplier, notes, recorded_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)");
                $rStmt->execute([$itemId, $qtyAdded, $activeCost, $totalCost, $supplier, $notes, $recorded_by]);

                $pdo->commit();

                sendJsonResponse([
                    'success' => true,
                    'message' => "Restocked +" . formatQty($qtyAdded) . " {$cur['unit']} of '{$cur['item_name']}' successfully!"
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                sendJsonResponse(['success' => false, 'message' => 'Restock transaction failed: ' . $e->getMessage()], 500);
            }
        }

        // 5. Log Waste / Spillage
        if ($action === 'log_waste') {
            $itemId     = (int)($_POST['item_id'] ?? 0);
            $qtyWasted  = (float)($_POST['quantity_wasted'] ?? 0);
            $reason     = trim($_POST['reason'] ?? 'Spillage');
            $notes      = trim($_POST['notes'] ?? '');

            if ($itemId <= 0 || $qtyWasted <= 0) {
                sendJsonResponse(['success' => false, 'message' => 'Invalid waste quantity or item.'], 422);
            }

            $pdo->beginTransaction();
            try {
                $cStmt = $pdo->prepare("SELECT item_name, quantity, unit, cost_per_unit FROM stock_items WHERE item_id = ? AND is_active = 1 FOR UPDATE");
                $cStmt->execute([$itemId]);
                $cur = $cStmt->fetch();

                if (!$cur) {
                    $pdo->rollBack();
                    sendJsonResponse(['success' => false, 'message' => 'Ingredient not found.'], 404);
                }

                $curQty   = (float)$cur['quantity'];
                $costUnit = (float)$cur['cost_per_unit'];
                $costLoss = $qtyWasted * $costUnit;

                $newQty = max(0.0, $curQty - $qtyWasted);

                $uStmt = $pdo->prepare("UPDATE stock_items SET quantity = ?, updated_at = NOW() WHERE item_id = ?");
                $uStmt->execute([$newQty, $itemId]);

                $wStmt = $pdo->prepare("INSERT INTO stock_waste_logs 
                    (item_id, quantity_wasted, reason, cost_loss, notes, recorded_by) 
                    VALUES (?, ?, ?, ?, ?, ?)");
                $wStmt->execute([$itemId, $qtyWasted, $reason, $costLoss, $notes, $recorded_by]);

                $pdo->commit();

                sendJsonResponse([
                    'success' => true,
                    'message' => "Logged -" . formatQty($qtyWasted) . " {$cur['unit']} waste for '{$cur['item_name']}'."
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                sendJsonResponse(['success' => false, 'message' => 'Waste logging failed: ' . $e->getMessage()], 500);
            }
        }

        // 6. Update Ingredient Details
        if ($action === 'update_item') {
            $itemId      = (int)($_POST['item_id'] ?? 0);
            $name        = trim($_POST['item_name'] ?? '');
            $category    = trim($_POST['category'] ?? 'Dairy');
            $quantity    = (float)($_POST['quantity'] ?? 0);
            $unit        = trim($_POST['unit'] ?? 'g');
            $alert_level = (float)($_POST['alert_level'] ?? 10);
            $cost_unit   = (float)($_POST['cost_per_unit'] ?? 0);
            $notes       = trim($_POST['notes'] ?? '');

            if ($itemId <= 0 || empty($name)) {
                sendJsonResponse(['success' => false, 'message' => 'Invalid parameters.'], 422);
            }

            $stmt = $pdo->prepare("UPDATE stock_items SET 
                item_name = ?, 
                category = ?, 
                quantity = ?, 
                unit = ?, 
                alert_level = ?, 
                cost_per_unit = ?, 
                notes = ?, 
                updated_at = NOW() 
                WHERE item_id = ? AND is_active = 1");
            $stmt->execute([$name, $category, $quantity, $unit, $alert_level, $cost_unit, $notes, $itemId]);

            sendJsonResponse(['success' => true, 'message' => "Ingredient '{$name}' updated successfully!"]);
        }

        // 7. Soft Delete
        if ($action === 'delete_item') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE stock_items SET is_active = 0, updated_at = NOW() WHERE item_id = ?");
            $stmt->execute([$itemId]);

            sendJsonResponse(['success' => true, 'message' => 'Ingredient archived successfully.']);
        }

        // 8. Fetch Logs
        if ($action === 'get_audit_logs') {
            $rStmt = $pdo->query("SELECT r.*, s.item_name, s.unit FROM stock_restocks r JOIN stock_items s ON r.item_id = s.item_id WHERE s.item_type = 'ingredient' ORDER BY r.created_at DESC LIMIT 30");
            $restocks = $rStmt->fetchAll();

            $wStmt = $pdo->query("SELECT w.*, s.item_name, s.unit FROM stock_waste_logs w JOIN stock_items s ON w.item_id = s.item_id WHERE s.item_type = 'ingredient' ORDER BY w.created_at DESC LIMIT 30");
            $waste = $wStmt->fetchAll();

            sendJsonResponse([
                'success'  => true,
                'restocks' => $restocks,
                'waste'    => $waste
            ]);
        }

        // 9. Export CSV
        if ($action === 'export_csv') {
            $stmt = $pdo->query("SELECT item_id, item_name, category, quantity, unit, alert_level, cost_per_unit, (quantity * cost_per_unit) AS valuation, notes, updated_at 
                FROM stock_items WHERE item_type = 'ingredient' AND is_active = 1 ORDER BY category ASC, item_name ASC");
            $rows = $stmt->fetchAll();

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=birds_nest_raw_ingredients_' . date('Y-m-d_His') . '.csv');
            
            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($output, ['Ingredient ID', 'Ingredient Name', 'Category', 'Quantity on Hand', 'Unit', 'Alert Level', 'Cost Per Unit ($)', 'Total Valuation ($)', 'Stock Status', 'Notes', 'Last Updated']);

            foreach ($rows as $row) {
                $status = 'In Stock';
                if ((float)$row['quantity'] <= 0) {
                    $status = 'Out of Stock';
                } elseif ((float)$row['quantity'] <= (float)$row['alert_level']) {
                    $status = 'Low Stock';
                }

                fputcsv($output, [
                    $row['item_id'],
                    $row['item_name'],
                    $row['category'],
                    $row['quantity'],
                    $row['unit'],
                    $row['alert_level'],
                    number_format((float)$row['cost_per_unit'], 4, '.', ''),
                    number_format((float)$row['valuation'], 2, '.', ''),
                    $status,
                    $row['notes'],
                    $row['updated_at']
                ]);
            }
            fclose($output);
            exit;
        }

        sendJsonResponse(['success' => false, 'message' => 'Unknown action requested.'], 400);
    }
}

// ── Initial Page Load Data ──
$initialKpis = getIngredientKPIs($pdo);
$initStmt = $pdo->query("SELECT * FROM stock_items WHERE item_type = 'ingredient' AND is_active = 1 ORDER BY item_name ASC");
$stockItems = $initStmt->fetchAll();

$categoriesList = [
    'Dairy'             => ['label' => 'Dairy & Milk', 'icon' => 'fa-bottle-water', 'color' => 'sky'],
    'Beans'             => ['label' => 'Coffee Beans', 'icon' => 'fa-seedling', 'color' => 'amber'],
    'Syrups'            => ['label' => 'Syrups & Flavors', 'icon' => 'fa-flask', 'color' => 'purple'],
    'Packaging'         => ['label' => 'Cups & Packaging', 'icon' => 'fa-box-open', 'color' => 'emerald'],
    'Bakery / Toppings' => ['label' => 'Bakery / Toppings', 'icon' => 'fa-cookie-bite', 'color' => 'yellow'],
    'General Supplies'  => ['label' => 'General Supplies', 'icon' => 'fa-boxes-stacked', 'color' => 'slate']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <title>Raw Ingredients & Recipe Supplies | Bird's Nest Coffee</title>

    <!-- Google Fonts: Poppins -->
    <!-- Google Fonts: Poppins & Kantumruy Pro (Khmer) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap" rel="stylesheet">

    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Theme Preload -->
    <script>
        (function(){
            try {
                if (localStorage.getItem("theme") === "light") {
                    document.documentElement.setAttribute("data-theme", "light");
                }
            } catch(e) {}
        })();
    </script>

    <style>
        :root {
            --bg: #0e0e10;
            --surface: #121215;
            --surface-card: #18181c;
            --surface-hover: #202028;
            --border: #24242b;
            --border-subtle: #1c1c22;
            --accent: #d1904b;
            --accent-hover: #e5a15a;
            --accent-glow: rgba(209, 144, 75, 0.25);
            --text-main: #f4f4f6;
            --text-muted: #8e8e9f;
            --sidebar-w: 256px;
        }

        [data-theme="light"], html[data-theme="light"] {
            --bg: #f4f5f8;
            --surface: #ffffff;
            --surface-card: #ffffff;
            --surface-hover: #f1f5f9;
            --border: #e2e4ea;
            --border-subtle: #ebedf2;
            --accent: #c47c2c;
            --accent-hover: #ad6b22;
            --accent-glow: rgba(196, 124, 44, 0.18);
            --text-main: #111827;
            --text-muted: #64748b;
        }

        body, input, select, textarea, button {
            font-family: 'Poppins', 'Kantumruy Pro', 'Siemreap', 'Noto Sans Khmer', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        body {
            background-color: var(--bg);
            color: var(--text-main);
            margin: 0;
            padding: 0;
            overflow: hidden;
        }

        :lang(km), [data-lang="km"], html[lang="km"] * {
            font-family: 'Kantumruy Pro', 'Poppins', 'Siemreap', 'Noto Sans Khmer', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        .glass-card {
            background: var(--surface-card);
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.15);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .glass-card:hover {
            border-color: rgba(209, 144, 75, 0.35);
        }

        html[data-theme="light"] body,
        [data-theme="light"] body,
        html[data-theme="light"] .app-layout,
        [data-theme="light"] .app-layout,
        html[data-theme="light"] .app-main,
        [data-theme="light"] .app-main {
            background-color: #f4f5f8 !important;
            color: #111827 !important;
        }
        [data-theme="light"] .page-header-divider { border-color: #e2e4ea !important; }
        [data-theme="light"] .page-header-title { color: #111827 !important; }
        [data-theme="light"] .btn-top-toolbar {
            background-color: #ffffff !important;
            border-color: #e2e4ea !important;
            color: #334155 !important;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important;
        }
        [data-theme="light"] .btn-top-toolbar:hover {
            background-color: #f8fafc !important;
            color: #0f172a !important;
            border-color: #cbd5e1 !important;
        }
        [data-theme="light"] .modal-content {
            background-color: #ffffff !important;
            border-color: #e2e4ea !important;
            color: #111827 !important;
        }
        [data-theme="light"] .modal-content .text-white { color: #111827 !important; }
        [data-theme="light"] .modal-content label { color: #475569 !important; }
        [data-theme="light"] input,
        [data-theme="light"] select,
        [data-theme="light"] textarea {
            background-color: #f8fafc !important;
            border-color: #e2e4ea !important;
            color: #111827 !important;
        }
        [data-theme="light"] .table-header-cell {
            background-color: #f1f5f9 !important;
            color: #475569 !important;
            border-color: #e2e4ea !important;
        }
        [data-theme="light"] .item-name-text { color: #111827 !important; }
        [data-theme="light"] .item-notes-text { color: #64748b !important; }
        [data-theme="light"] .cat-badge {
            background-color: #f1f5f9 !important;
            color: #334155 !important;
            border-color: #cbd5e1 !important;
        }
        [data-theme="light"] .val-main-text { color: #111827 !important; }
        [data-theme="light"] .val-sub-text { color: #64748b !important; }
        [data-theme="light"] .btn-action-neutral {
            background-color: #f1f5f9 !important;
            color: #475569 !important;
            border-color: #e2e4ea !important;
        }
        [data-theme="light"] .btn-reset-filter {
            background-color: #f1f5f9 !important;
            border-color: #e2e4ea !important;
            color: #64748b !important;
        }
        [data-theme="light"] .threshold-badge {
            background-color: #f1f5f9 !important;
            border-color: #e2e4ea !important;
            color: #475569 !important;
        }
        [data-theme="light"] .item-icon-box {
            background-color: #f1f5f9 !important;
            border-color: #e2e4ea !important;
        }
        [data-theme="light"] .card-num { color: #111827 !important; }
        [data-theme="light"] .card-subtext { color: #64748b !important; }
        [data-theme="light"] .unit-text { color: #64748b !important; }
        [data-theme="light"] .tab-inactive {
            background-color: #ffffff !important;
            border-color: #e2e4ea !important;
            color: #64748b !important;
        }
        [data-theme="light"] .tab-inactive:hover {
            color: #111827 !important;
            border-color: #cbd5e1 !important;
        }

        /* ══ Amber Table Row Hover Effect ══ */
        .row-hover {
            transition: background-color 0.18s ease-in-out, border-color 0.18s ease-in-out;
        }
        .row-hover:hover {
            background-color: rgba(209, 144, 75, 0.09) !important;
        }
        html[data-theme="light"] .row-hover:hover,
        [data-theme="light"] .row-hover:hover {
            background-color: rgba(209, 144, 75, 0.14) !important;
        }

        /* ========== STATS BAR (5 CARDS GRID) ========== */
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 10px;
            margin-bottom: 14px;
            width: 100%;
        }
        @media (max-width: 1200px) {
            .stats-bar { 
                grid-template-columns: repeat(3, 1fr) !important; 
                gap: 10px !important;
            }
        }
        @media (max-width: 768px) {
            .stats-bar { 
                grid-template-columns: repeat(2, 1fr) !important; 
                gap: 8px !important;
            }
        }
        @media (max-width: 480px) {
            .stats-bar { 
                grid-template-columns: 1fr !important; 
                gap: 6px !important;
            }
            .stat-card {
                padding: 8px 10px !important;
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
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 12px 14px;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 12px;
            min-height: 74px;
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
            border-color: #d1904b;
            background: rgba(209, 144, 75, 0.12);
            box-shadow: 0 0 0 2px rgba(209, 144, 75, 0.35), 0 8px 30px rgba(0, 0, 0, 0.4);
        }
        .stat-card.total.active {
            border-color: #a78bfa;
            background: rgba(139, 92, 246, 0.14);
            box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.35), 0 8px 30px rgba(0, 0, 0, 0.4);
        }
        .stat-card.avail.active,
        .stat-card.in-stock.active {
            border-color: #34d399;
            background: rgba(16, 185, 129, 0.14);
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.35), 0 8px 30px rgba(0, 0, 0, 0.4);
        }
        .stat-card.amber.active,
        .stat-card.low-stock.active {
            border-color: #fbbf24;
            background: rgba(245, 158, 11, 0.14);
            box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.35), 0 8px 30px rgba(0, 0, 0, 0.4);
        }
        .stat-card.unavail.active,
        .stat-card.out-stock.active {
            border-color: #f87171;
            background: rgba(239, 68, 68, 0.14);
            box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.35), 0 8px 30px rgba(0, 0, 0, 0.4);
        }
        .stat-card.top-cat.active,
        .stat-card.valuation.active {
            border-color: #fbbf24;
            background: rgba(245, 158, 11, 0.14);
            box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.35), 0 8px 30px rgba(0, 0, 0, 0.4);
        }

        .stat-card .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
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
        .stat-card.avail .stat-icon,
        .stat-card.in-stock .stat-icon {
            background: rgba(16, 185, 129, 0.22);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.4);
        }
        .stat-card.amber .stat-icon,
        .stat-card.low-stock .stat-icon {
            background: rgba(245, 158, 11, 0.22);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.4);
        }
        .stat-card.unavail .stat-icon,
        .stat-card.out-stock .stat-icon {
            background: rgba(239, 68, 68, 0.22);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.4);
        }
        .stat-card.top-cat .stat-icon,
        .stat-card.valuation .stat-icon {
            background: rgba(245, 158, 11, 0.22);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.4);
        }

        .stat-card .stat-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
        .stat-card .stat-label {
            font-size: 10.5px;
            color: #888888;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 700;
            white-space: nowrap;
        }
        .stat-card .stat-value {
            font-size: 24px;
            font-weight: 800;
            color: #f5f5f5;
            line-height: 1.2;
        }
        .stat-card.avail .stat-value,
        .stat-card.in-stock .stat-value { color: #34d399; }
        .stat-card.amber .stat-value,
        .stat-card.low-stock .stat-value { color: #fbbf24; }
        .stat-card.unavail .stat-value,
        .stat-card.out-stock .stat-value { color: #f87171; }
        .stat-card .stat-sub {
            font-size: 11px;
            color: #888888;
            white-space: nowrap;
        }

        /* Light Mode Overrides */
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
        [data-theme="light"] .stat-card.avail .stat-value,
        [data-theme="light"] .stat-card.in-stock .stat-value {
            color: #059669 !important;
        }
        [data-theme="light"] .stat-card.amber .stat-value,
        [data-theme="light"] .stat-card.low-stock .stat-value {
            color: #d97706 !important;
        }
        [data-theme="light"] .stat-card.unavail .stat-value,
        [data-theme="light"] .stat-card.out-stock .stat-value {
            color: #dc2626 !important;
        }
        [data-theme="light"] .stat-card.top-cat .stat-value,
        [data-theme="light"] .stat-card.valuation .stat-value {
            color: #111827 !important;
        }
        [data-theme="light"] .stat-card .stat-sub {
            color: #6B7280 !important;
        }

        .modal-overlay {
            opacity: 0; visibility: hidden; transition: all 0.25s ease-in-out; backdrop-filter: blur(8px);
        }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .modal-content {
            transform: scale(0.94) translateY(12px);
            transition: all 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .modal-overlay.active .modal-content { transform: scale(1) translateY(0); }

        #toastContainer {
            position: fixed; top: 24px; right: 24px; z-index: 999999;
            display: flex; flex-direction: column; gap: 10px; pointer-events: none;
        }
        .toast-item {
            pointer-events: auto; min-width: 320px; max-width: 420px; padding: 14px 18px;
            border-radius: 14px; background: #18181c; border: 1px solid #282832; color: #fff;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5); display: flex; align-items: center; gap: 12px;
        }
    </style>
</head>
<body class="text-[var(--text-main)]" style="background-color: var(--bg);">

    <!-- Toast Notification Container -->
    <div id="toastContainer"></div>

    <div class="app-layout flex h-screen w-screen overflow-hidden" style="background-color: var(--bg);">
        <!-- ══ SIDEBAR COMPONENT ══ -->
        <?php require_once __DIR__ . '/sidebar.php'; ?>

        <!-- ══ MAIN CONTENT AREA ══ -->
        <main class="app-main flex-1 h-screen overflow-y-auto flex flex-col p-4 md:p-6 lg:p-8" style="background-color: var(--bg);">
            


            <!-- ── Header Bar ── -->
            <div class="page-header-divider flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6 pb-4 border-b border-[#1f1f26]">
                <div class="flex items-center gap-3">
                    <h1 class="page-header-title text-xl md:text-2xl font-black tracking-tight"><?= __('raw_ingredients_supplies', 'Raw Ingredients & Supplies') ?></h1>
                </div>

                <!-- Action Button Toolbar -->
                <div class="flex flex-wrap items-center gap-2.5">
                    <button type="button" 
                            onclick="openAuditLogsModal()" 
                            class="btn-top-toolbar inline-flex items-center gap-2 px-3.5 py-2.5 rounded-xl bg-[#18181c] border border-[#262630] text-xs font-semibold text-[#c5c5d2] hover:text-white hover:border-[#d1904b] hover:bg-[#1f1f26] transition-all cursor-pointer shadow-sm">
                        <i class="fa-solid fa-clock-rotate-left text-[#d1904b]"></i>
                        <span><?= __('audit_and_logs', 'Audit & Logs') ?></span>
                    </button>

                    <button type="button" 
                            onclick="openAddStockModal()" 
                            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-[#d1904b] to-[#e5a15a] text-black text-xs font-bold hover:brightness-110 active:scale-95 transition-all cursor-pointer shadow-lg shadow-[#d1904b]/20">
                        <i class="fa-solid fa-plus text-sm"></i>
                        <span><?= __('add_raw_ingredient', 'Add Raw Ingredient') ?></span>
                    </button>
                </div>
            </div>

            <!-- ── Stats Bar (5 Cards: Total, In Stock, Low Stock, Out of Stock, Valuation) ── -->
            <div class="stats-bar">
                <!-- 1. Total -->
                <div class="stat-card total" id="statTotal" data-stat="total" role="button" tabindex="0" onclick="filterByStatus('all')" title="Click to show all ingredients">
                    <div class="stat-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
                    <div class="stat-body">
                        <div class="stat-label"><?= __('kpi_total', 'TOTAL') ?></div>
                        <div class="stat-value" id="kpiTotalItems"><?= number_format($initialKpis['total_items']) ?></div>
                        <div class="stat-sub"><?= __('ingredients_unit', 'Ingredients') ?></div>
                    </div>
                </div>

                <!-- 2. In Stock -->
                <div class="stat-card avail in-stock" id="statInStock" data-stat="in_stock" role="button" tabindex="0" onclick="filterByStatus('in_stock')" title="Click to filter in stock ingredients">
                    <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                    <div class="stat-body">
                        <div class="stat-label"><?= __('kpi_in_stock', 'IN STOCK') ?></div>
                        <div class="stat-value" id="kpiInStock"><?= number_format($initialKpis['in_stock']) ?></div>
                        <div class="stat-sub"><?= __('healthy_stock', 'healthy stock') ?></div>
                    </div>
                </div>

                <!-- 3. Low Stock -->
                <div class="stat-card amber low-stock" id="statLowStock" data-stat="low_stock" role="button" tabindex="0" onclick="filterByStatus('low_stock')" title="Click to filter low stock ingredients">
                    <div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <div class="stat-body">
                        <div class="stat-label"><?= __('kpi_low_stock', 'LOW STOCK') ?></div>
                        <div class="stat-value" id="kpiLowStock"><?= number_format($initialKpis['low_stock']) ?></div>
                        <div class="stat-sub"><?= __('reorder_soon', 'reorder soon') ?></div>
                    </div>
                </div>

                <!-- 4. Out of Stock -->
                <div class="stat-card unavail out-stock" id="statOutOfStock" data-stat="out_of_stock" role="button" tabindex="0" onclick="filterByStatus('out_of_stock')" title="Click to filter out of stock ingredients">
                    <div class="stat-icon"><i class="fa-solid fa-circle-xmark"></i></div>
                    <div class="stat-body">
                        <div class="stat-label"><?= __('kpi_out_of_stock', 'OUT OF STOCK') ?></div>
                        <div class="stat-value" id="kpiOutOfStock"><?= number_format($initialKpis['out_of_stock']) ?></div>
                        <div class="stat-sub"><?= __('depleted_items', 'depleted items') ?></div>
                    </div>
                </div>

                <!-- 5. Valuation -->
                <div class="stat-card top-cat valuation" id="statTopCat" role="button" tabindex="0" title="Total raw inventory valuation">
                    <div class="stat-icon"><i class="fa-solid fa-trophy"></i></div>
                    <div class="stat-body">
                        <div class="stat-label"><?= __('kpi_valuation', 'VALUATION') ?></div>
                        <div class="stat-value" id="kpiTotalValuation" style="font-size:22px;">$<?= number_format($initialKpis['total_valuation'], 2) ?></div>
                        <div class="stat-sub"><?= __('raw_materials', 'Raw materials') ?></div>
                    </div>
                </div>
            </div>

            <!-- ── Search, Filter & Action Bar ── -->
            <div class="glass-card p-4 mb-6">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
                    
                    <!-- Search Input -->
                    <div class="relative flex-1 min-w-[220px]">
                        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-[#727282] text-sm"></i>
                        <input type="text" 
                               id="stockSearchInput" 
                               placeholder="<?= __('search_ingredients_ph', 'Search ingredients by name, category, notes...') ?>" 
                               class="w-full pl-10 pr-9 py-2.5 rounded-xl bg-[#141418] border border-[#252530] text-sm text-[var(--text-main)] placeholder-[#727282] focus:outline-none focus:border-[#d1904b] focus:ring-1 focus:ring-[#d1904b] transition-all">
                        <button type="button" 
                                id="clearSearchBtn" 
                                onclick="clearSearch()" 
                                class="hidden absolute right-3 top-1/2 -translate-y-1/2 text-[#727282] hover:text-white text-xs p-1">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>

                    <!-- Category Filter Dropdown -->
                    <div class="flex flex-wrap items-center gap-2.5">
                        <div class="relative min-w-[170px]">
                            <select id="categoryFilter" 
                                    onchange="loadStockTable()" 
                                    class="w-full appearance-none pl-3.5 pr-8 py-2.5 rounded-xl bg-[#141418] border border-[#252530] text-xs font-semibold text-[var(--text-main)] focus:outline-none focus:border-[#d1904b] cursor-pointer">
                                <option value="all"><?= __('all_categories', 'All Ingredient Categories') ?></option>
                                <option value="Dairy"><?= __('cat_dairy', 'Dairy & Milk') ?></option>
                                <option value="Beans"><?= __('cat_beans', 'Coffee Beans') ?></option>
                                <option value="Syrups"><?= __('cat_syrups', 'Syrups & Flavors') ?></option>
                                <option value="Packaging"><?= __('cat_packaging', 'Cups & Packaging') ?></option>
                                <option value="Bakery / Toppings"><?= __('cat_bakery', 'Bakery / Toppings') ?></option>
                                <option value="General Supplies"><?= __('cat_general', 'General Supplies') ?></option>
                            </select>
                            <i class="fa-solid fa-chevron-down absolute right-3 top-1/2 -translate-y-1/2 text-xs text-[#727282] pointer-events-none"></i>
                        </div>

                        <input type="hidden" id="statusFilter" value="all">

                        <!-- Sort Selector -->
                        <div class="relative min-w-[150px]">
                            <select id="sortSelector" 
                                    onchange="loadStockTable()" 
                                    class="w-full appearance-none pl-3.5 pr-8 py-2.5 rounded-xl bg-[#141418] border border-[#252530] text-xs font-semibold text-[var(--text-main)] focus:outline-none focus:border-[#d1904b] cursor-pointer">
                                <option value="name_asc"><?= __('sort_name_asc', 'Name: A to Z') ?></option>
                                <option value="name_desc"><?= __('sort_name_desc', 'Name: Z to A') ?></option>
                                <option value="qty_asc"><?= __('sort_qty_asc', 'Qty: Low to High') ?></option>
                                <option value="qty_desc"><?= __('sort_qty_desc', 'Qty: High to Low') ?></option>
                                <option value="value_desc"><?= __('sort_val_desc', 'Highest Valuation') ?></option>
                                <option value="newest"><?= __('sort_newest', 'Recently Added') ?></option>
                            </select>
                            <i class="fa-solid fa-arrow-down-short-wide absolute right-3 top-1/2 -translate-y-1/2 text-xs text-[#727282] pointer-events-none"></i>
                        </div>

                        <!-- Reset / Refresh Button -->
                        <button type="button" 
                                onclick="resetFilters()" 
                                class="btn-reset-filter w-9 h-9 rounded-xl bg-[#141418] border border-[#252530] text-[#8e8e9f] hover:text-[#d1904b] hover:border-[#d1904b] flex items-center justify-center transition-all cursor-pointer" 
                                title="Reset Filters and Refresh Table">
                            <i class="fa-solid fa-arrows-rotate text-xs"></i>
                        </button>
                    </div>

                </div>
            </div>

            <!-- ── Data Table Card ── -->
            <div class="glass-card overflow-hidden flex-1 flex flex-col">
                <div class="overflow-x-auto flex-1">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="table-header-cell bg-[#141418] border-b border-[#24242b] text-[#8e8e9f] uppercase tracking-wider font-semibold">
                                <th class="py-3.5 px-4"><?= __('col_ingredient_details', 'Ingredient Details') ?></th>
                                <th class="py-3.5 px-3"><?= __('col_category', 'Category') ?></th>
                                <th class="py-3.5 px-3"><?= __('col_qty_on_hand', 'Qty on Hand') ?></th>
                                <th class="py-3.5 px-3"><?= __('col_alert_threshold', 'Alert Threshold') ?></th>
                                <th class="py-3.5 px-3"><?= __('col_status', 'Status') ?></th>
                                <th class="py-3.5 px-3"><?= __('col_valuation', 'Valuation') ?></th>
                                <th class="py-3.5 px-4 text-right"><?= __('col_actions', 'Actions') ?></th>
                            </tr>
                        </thead>
                        <tbody id="stockTableBody" class="table-divide divide-y divide-[#1f1f28]">
                            <!-- Initial PHP render -->
                            <?php foreach ($stockItems as $item): 
                                $qty = (float)$item['quantity'];
                                $alert = (float)$item['alert_level'];
                                $cost = (float)$item['cost_per_unit'];
                                $val = $qty * $cost;

                                $status = 'in_stock';
                                $statusBadge = '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> ' . __('status_in_stock', 'In Stock') . '</span>';
                                
                                if ($qty <= 0) {
                                    $status = 'out_of_stock';
                                    $statusBadge = '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-rose-500/10 text-rose-400 border border-rose-500/20"><i class="fa-solid fa-circle-exclamation text-[10px]"></i> ' . __('status_out_of_stock', 'Out of Stock') . '</span>';
                                } elseif ($qty <= $alert) {
                                    $status = 'low_stock';
                                    $statusBadge = '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-500/10 text-amber-400 border border-amber-500/20"><i class="fa-solid fa-triangle-exclamation text-[10px]"></i> ' . __('status_low_stock', 'Low Stock') . '</span>';
                                }

                                $catMeta = $categoriesList[$item['category']] ?? ['icon' => 'fa-box', 'color' => 'slate'];
                            ?>
                            <tr class="row-hover group" data-item-id="<?= $item['item_id'] ?>">
                                <td class="py-3.5 px-4">
                                    <div class="item-name-text font-bold text-[var(--text-main)] text-sm group-hover:text-[#d1904b] transition-colors truncate">
                                        <?= htmlspecialchars($item['item_name']) ?>
                                    </div>
                                </td>
                                <td class="py-3.5 px-3">
                                    <span class="cat-badge inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold bg-[#1e1e24] text-[#b4b4c2] border border-[#282834]">
                                        <?= htmlspecialchars(formatCategoryLabel($item['category'])) ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-3 font-semibold">
                                    <span class="text-sm font-extrabold <?= ($qty <= 0) ? 'text-rose-400' : (($qty <= $alert) ? 'text-amber-400' : 'text-[var(--text-main)]') ?>">
                                        <?= formatQty($qty) ?> <span class="text-xs font-normal text-[#8e8e9f]"><?= htmlspecialchars($item['unit']) ?></span>
                                    </span>
                                </td>
                                <td class="py-3.5 px-3 font-medium">
                                    <span class="threshold-badge px-2.5 py-1 rounded-lg bg-[#1e1e24] border border-[#282834] text-xs text-[#8e8e9f]">
                                        <?= formatQty($alert) ?> <?= htmlspecialchars($item['unit']) ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-3">
                                    <?= $statusBadge ?>
                                </td>
                                <td class="py-3.5 px-3">
                                    <div class="val-main-text text-[var(--text-main)] font-bold text-xs">$<?= number_format($val, 2) ?></div>
                                    <div class="text-[11px] text-[#8e8e9f]">$<?= number_format($cost, 4) ?> / <?= htmlspecialchars($item['unit']) ?></div>
                                </td>
                                <td class="py-3.5 px-4 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <!-- Edit -->
                                        <button type="button" 
                                                onclick="openEditStockModal(<?= $item['item_id'] ?>)" 
                                                class="btn-action-neutral p-1.5 rounded-lg bg-[#1f1f26] text-[#b4b4c2] hover:text-white hover:bg-[#282832] border border-[#2b2b36] transition-all cursor-pointer" 
                                                title="<?= __('btn_edit', 'Edit') ?>">
                                            <i class="fa-solid fa-pen-to-square w-4 text-center"></i>
                                        </button>
                                        <!-- Quick Restock -->
                                        <button type="button" 
                                                onclick="openRestockModal(<?= $item['item_id'] ?>)" 
                                                class="btn-action-neutral p-1.5 rounded-lg bg-[#1f1f26] text-[#b4b4c2] hover:text-emerald-400 hover:bg-emerald-500/15 border border-[#2b2b36] transition-all cursor-pointer" 
                                                title="<?= __('btn_restock', 'Restock') ?>">
                                            <i class="fa-solid fa-plus w-4 text-center"></i>
                                        </button>
                                        <!-- Delete -->
                                        <button type="button" 
                                                onclick="confirmDeleteItem(<?= $item['item_id'] ?>, '<?= addslashes(htmlspecialchars($item['item_name'])) ?>')" 
                                                class="btn-action-neutral p-1.5 rounded-lg bg-[#1f1f26] text-[#8e8e9f] hover:text-rose-400 hover:bg-rose-500/15 border border-[#2b2b36] transition-all cursor-pointer" 
                                                title="<?= __('btn_delete', 'Delete') ?>">
                                            <i class="fa-solid fa-trash-can w-4 text-center"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Table Footer Info -->
                <div class="table-header-cell px-4 py-3 bg-[#141418] border-t border-[#24242b] flex items-center justify-between text-xs text-[#8e8e9f]">
                    <div id="tableRecordCount">
                        <?= __('showing_ingredients_count', 'Showing raw ingredients') ?>: <?= count($stockItems) ?>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         MODAL 1: ADD NEW RAW INGREDIENT
    ══════════════════════════════════════════════════════════════ -->
    <div id="addStockModal" class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75">
        <div class="modal-content glass-card max-w-lg w-full p-6 bg-[#18181c] border border-[#2b2b36] rounded-2xl shadow-2xl relative">
            <div class="modal-header flex items-center justify-between pb-3 mb-4 border-b border-[#252530]">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-[#d1904b]/20 text-[#d1904b] flex items-center justify-center text-sm font-bold">
                        <i class="fa-solid fa-seedling"></i>
                    </div>
                    <h3 class="modal-title text-base font-bold text-white"><?= __('add_raw_ingredient', 'Add Raw Ingredient') ?></h3>
                </div>
                <button type="button" onclick="closeModal('addStockModal')" class="text-[#7d7d8e] hover:text-white p-1 text-sm">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form id="addStockForm" onsubmit="handleAddStock(event)" class="space-y-4">
                <input type="hidden" name="action" value="create_item">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div>
                    <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_ingredient_details', 'Ingredient Name') ?> <span class="text-rose-400">*</span></label>
                    <input type="text" 
                           name="item_name" 
                           required 
                           placeholder="e.g. Arabica Whole Beans 1kg, Meiji Whole Milk 2L" 
                           class="w-full px-3.5 py-2.5 rounded-xl bg-[#141418] border border-[#282834] text-sm text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_category', 'Category') ?> <span class="text-rose-400">*</span></label>
                        <select name="category" required class="w-full px-3.5 py-2.5 rounded-xl bg-[#141418] border border-[#282834] text-xs font-semibold text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                            <option value="Dairy"><?= __('cat_dairy', 'Dairy & Milk') ?></option>
                            <option value="Beans" selected><?= __('cat_beans', 'Coffee Beans') ?></option>
                            <option value="Syrups"><?= __('cat_syrups', 'Syrups & Flavors') ?></option>
                            <option value="Packaging"><?= __('cat_packaging', 'Cups & Packaging') ?></option>
                            <option value="Bakery / Toppings"><?= __('cat_bakery', 'Bakery / Toppings') ?></option>
                            <option value="General Supplies"><?= __('cat_general', 'General Supplies') ?></option>
                        </select>
                    </div>

                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_unit', 'Base Measurement Unit') ?> <span class="text-rose-400">*</span></label>
                        <select name="unit" required class="w-full px-3.5 py-2.5 rounded-xl bg-[#141418] border border-[#282834] text-xs font-semibold text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                            <option value="g" selected>g (Grams)</option>
                            <option value="ml">ml (Milliliters)</option>
                            <option value="pcs">pcs (Pieces)</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_qty_on_hand', 'Initial Qty') ?></label>
                        <input type="number" 
                               step="any" 
                               min="0" 
                               name="quantity" 
                               value="0" 
                               class="w-full px-3 py-2 rounded-xl bg-[#141418] border border-[#282834] text-xs text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                    </div>
                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_alert_threshold', 'Alert Threshold') ?></label>
                        <input type="number" 
                               step="any" 
                               min="0" 
                               name="alert_level" 
                               value="1000" 
                               class="w-full px-3 py-2 rounded-xl bg-[#141418] border border-[#282834] text-xs text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                    </div>
                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_cost_box_unit', 'Cost Per Base Unit ($)') ?></label>
                        <input type="number" 
                               step="0.0001" 
                               min="0" 
                               name="cost_per_unit" 
                               value="0.0000" 
                               class="w-full px-3 py-2 rounded-xl bg-[#141418] border border-[#282834] text-xs text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                    </div>
                </div>

                <div>
                    <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('supplier_notes', 'Supplier & Notes') ?></label>
                    <textarea name="notes" 
                              rows="2" 
                              placeholder="e.g. Royal Coffee Wholesale 1kg vacuum packs" 
                              class="w-full px-3.5 py-2 rounded-xl bg-[#141418] border border-[#282834] text-xs text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]"></textarea>
                </div>

                <div class="modal-footer flex items-center justify-end gap-2.5 pt-3 border-t border-[#252530]">
                    <button type="button" 
                            onclick="closeModal('addStockModal')" 
                            class="px-4 py-2 rounded-xl bg-[#202026] text-xs font-semibold text-[#b4b4c2] hover:text-white transition-all cursor-pointer">
                        <?= __('cancel', 'Cancel') ?>
                    </button>
                    <button type="submit" 
                            id="addStockSubmitBtn" 
                            class="px-5 py-2 rounded-xl bg-[#d1904b] hover:bg-[#e5a15a] text-black text-xs font-bold transition-all shadow-md shadow-[#d1904b]/20 cursor-pointer">
                        <?= __('save', 'Save Ingredient') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         MODAL 2: QUICK INGREDIENT RESTOCK
    ══════════════════════════════════════════════════════════════ -->
    <div id="restockModal" class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75">
        <div class="modal-content glass-card max-w-md w-full p-6 bg-[#18181c] border border-[#2b2b36] rounded-2xl shadow-2xl relative">
            <div class="modal-header flex items-center justify-between pb-3 mb-4 border-b border-[#252530]">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-[#d1904b]/20 text-[#d1904b] flex items-center justify-center text-sm font-bold">
                        <i class="fa-solid fa-truck-ramp-box"></i>
                    </div>
                    <h3 class="modal-title text-base font-bold text-white"><?= __('quick_restock', 'Quick Ingredient Restock') ?></h3>
                </div>
                <button type="button" onclick="closeModal('restockModal')" class="text-[#7d7d8e] hover:text-white p-1 text-sm">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form id="restockForm" onsubmit="handleQuickRestock(event)" class="space-y-4">
                <input type="hidden" name="action" value="quick_restock">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div>
                    <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_ingredient_details', 'Select Ingredient') ?> <span class="text-rose-400">*</span></label>
                    <select name="item_id" 
                            id="restockItemSelect" 
                            required 
                            onchange="updateRestockModalPreview()" 
                            class="w-full px-3.5 py-2.5 rounded-xl bg-[#141418] border border-[#282834] text-xs font-semibold text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                        <option value="">-- Choose Ingredient --</option>
                        <?php foreach ($stockItems as $it): ?>
                        <option value="<?= $it['item_id'] ?>" 
                                data-unit="<?= htmlspecialchars($it['unit']) ?>" 
                                data-qty="<?= (float)$it['quantity'] ?>" 
                                data-cost="<?= (float)$it['cost_per_unit'] ?>">
                            <?= htmlspecialchars($it['item_name']) ?> (<?= __('col_qty_on_hand', 'On Hand') ?>: <?= formatQty($it['quantity']) ?> <?= htmlspecialchars($it['unit']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('quantity_added', 'Quantity to Add') ?> <span class="text-rose-400">*</span></label>
                        <input type="number" 
                               step="any" 
                               min="0.01" 
                               name="quantity_added" 
                               id="restockQtyInput" 
                               required 
                               placeholder="e.g. 5" 
                               oninput="calculateRestockTotal()" 
                               class="w-full px-3.5 py-2 rounded-xl bg-[#141418] border border-[#282834] text-sm font-bold text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                    </div>

                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_unit', 'Unit Multiplier') ?></label>
                        <select name="unit_multiplier" id="restockMultiplier" onchange="calculateRestockTotal()"
                                class="w-full px-3 py-2 rounded-xl bg-[#141418] border border-[#282834] text-xs font-semibold text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                            <option value="1">Base Units (g / ml / pcs)</option>
                            <option value="1000">1 kg / 1 Liter (= 1,000)</option>
                            <option value="2000">2 Liters / 2 kg (= 2,000)</option>
                            <option value="50">1 Sleeve / Pack (= 50 pcs)</option>
                            <option value="100">1 Box / Pack (= 100 pcs)</option>
                        </select>
                    </div>
                </div>

                <!-- Live Calculated Units Badge -->
                <div id="restockPreviewCard" class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-xs space-y-1">
                    <div class="flex justify-between text-amber-400 font-bold">
                        <span>Total Base Units:</span>
                        <span id="restockAddedBaseUnits">+0 units</span>
                    </div>
                    <div class="flex justify-between text-[#8e8e9f] text-[11px]">
                        <span>Current Stock: <span id="restockCurrentQty" class="font-bold text-white">--</span></span>
                        <span>New Total: <span id="restockNewQty" class="font-bold text-emerald-400">--</span></span>
                    </div>
                </div>

                <div>
                    <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('supplier_notes', 'Supplier / Notes') ?></label>
                    <input type="text" 
                           name="notes" 
                           placeholder="e.g. Weekly replenishment from Meiji" 
                           class="w-full px-3.5 py-2 rounded-xl bg-[#141418] border border-[#282834] text-xs text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                </div>

                <div class="modal-footer flex items-center justify-end gap-2.5 pt-3 border-t border-[#252530]">
                    <button type="button" 
                            onclick="closeModal('restockModal')" 
                            class="px-4 py-2 rounded-xl bg-[#202026] text-xs font-semibold text-[#b4b4c2] hover:text-white transition-all cursor-pointer">
                        <?= __('cancel', 'Cancel') ?>
                    </button>
                    <button type="submit" 
                            id="restockSubmitBtn" 
                            class="px-5 py-2 rounded-xl bg-[#d1904b] hover:bg-[#e5a15a] text-black text-xs font-bold transition-all shadow-md shadow-[#d1904b]/20 cursor-pointer">
                        <?= __('btn_confirm_restock', 'Confirm Restock') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         MODAL 3: LOG WASTE / SPILLAGE
    ══════════════════════════════════════════════════════════════ -->
    <div id="wasteModal" class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75">
        <div class="modal-content glass-card max-w-md w-full p-6 bg-[#18181c] border border-[#2b2b36] rounded-2xl shadow-2xl relative">
            <div class="modal-header flex items-center justify-between pb-3 mb-4 border-b border-[#252530]">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-rose-500/20 text-rose-400 flex items-center justify-center text-sm font-bold">
                        <i class="fa-solid fa-trash-can-arrow-up"></i>
                    </div>
                    <h3 class="modal-title text-base font-bold text-white"><?= __('log_waste', 'Log Ingredient Waste') ?></h3>
                </div>
                <button type="button" onclick="closeModal('wasteModal')" class="text-[#7d7d8e] hover:text-white p-1 text-sm">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form id="wasteForm" onsubmit="handleLogWaste(event)" class="space-y-4">
                <input type="hidden" name="action" value="log_waste">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div>
                    <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_ingredient_details', 'Select Ingredient') ?> <span class="text-rose-400">*</span></label>
                    <select name="item_id" 
                            id="wasteItemSelect" 
                            required 
                            class="w-full px-3.5 py-2.5 rounded-xl bg-[#141418] border border-[#282834] text-xs font-semibold text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                        <option value="">-- Choose Ingredient --</option>
                        <?php foreach ($stockItems as $it): ?>
                        <option value="<?= $it['item_id'] ?>">
                            <?= htmlspecialchars($it['item_name']) ?> (<?= __('col_qty_on_hand', 'On Hand') ?>: <?= formatQty($it['quantity']) ?> <?= htmlspecialchars($it['unit']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('quantity_wasted', 'Quantity Wasted') ?> *</label>
                        <input type="number" 
                               step="any" 
                               min="0.01" 
                               name="quantity_wasted" 
                               required 
                               placeholder="e.g. 500" 
                               class="w-full px-3.5 py-2 rounded-xl bg-[#141418] border border-[#282834] text-sm font-bold text-[var(--text-main)] focus:outline-none focus:border-rose-500">
                    </div>
                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('reason', 'Reason') ?> *</label>
                        <select name="reason" required class="w-full px-3 py-2 rounded-xl bg-[#141418] border border-[#282834] text-xs font-semibold text-[var(--text-main)] focus:outline-none focus:border-rose-500">
                            <option value="Spillage">Spilled Milk / Liquid</option>
                            <option value="Grind Waste">Espresso Calibration Waste</option>
                            <option value="Expired">Expired / Spoiled</option>
                            <option value="Damaged">Damaged Packaging</option>
                            <option value="Staff Drink">Staff Consumption</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('notes', 'Notes') ?></label>
                    <input type="text" name="notes" placeholder="e.g. Steam pitcher dropped during rush" 
                           class="w-full px-3.5 py-2 rounded-xl bg-[#141418] border border-[#282834] text-xs text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                </div>

                <div class="modal-footer flex items-center justify-end gap-2.5 pt-3 border-t border-[#252530]">
                    <button type="button" 
                            onclick="closeModal('wasteModal')" 
                            class="px-4 py-2 rounded-xl bg-[#202026] text-xs font-semibold text-[#b4b4c2] hover:text-white transition-all cursor-pointer">
                        <?= __('cancel', 'Cancel') ?>
                    </button>
                    <button type="submit" 
                            class="px-5 py-2 rounded-xl bg-rose-500 hover:bg-rose-600 text-white text-xs font-bold transition-all shadow-md shadow-rose-500/20 cursor-pointer">
                        <?= __('log_waste', 'Log Waste') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         MODAL 4: EDIT RAW INGREDIENT
    ══════════════════════════════════════════════════════════════ -->
    <div id="editStockModal" class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75">
        <div class="modal-content glass-card max-w-lg w-full p-6 bg-[#18181c] border border-[#2b2b36] rounded-2xl shadow-2xl relative">
            <div class="modal-header flex items-center justify-between pb-3 mb-4 border-b border-[#252530]">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-[#d1904b]/20 text-[#d1904b] flex items-center justify-center text-sm font-bold">
                        <i class="fa-solid fa-pen-to-square"></i>
                    </div>
                    <h3 class="modal-title text-base font-bold text-white"><?= __('btn_edit', 'Edit Ingredient Details') ?></h3>
                </div>
                <button type="button" onclick="closeModal('editStockModal')" class="text-[#7d7d8e] hover:text-white p-1 text-sm">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form id="editStockForm" onsubmit="handleEditStock(event)" class="space-y-4">
                <input type="hidden" name="action" value="update_item">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="item_id" id="editItemId">

                <div>
                    <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_ingredient_details', 'Ingredient Name') ?> <span class="text-rose-400">*</span></label>
                    <input type="text" 
                           id="editItemName" 
                           name="item_name" 
                           required 
                           class="w-full px-3.5 py-2.5 rounded-xl bg-[#141418] border border-[#282834] text-sm text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_category', 'Category') ?> <span class="text-rose-400">*</span></label>
                        <select id="editCategory" name="category" required class="w-full px-3.5 py-2.5 rounded-xl bg-[#141418] border border-[#282834] text-xs font-semibold text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                            <option value="Dairy"><?= __('cat_dairy', 'Dairy & Milk') ?></option>
                            <option value="Beans"><?= __('cat_beans', 'Coffee Beans') ?></option>
                            <option value="Syrups"><?= __('cat_syrups', 'Syrups & Flavors') ?></option>
                            <option value="Packaging"><?= __('cat_packaging', 'Cups & Packaging') ?></option>
                            <option value="Bakery / Toppings"><?= __('cat_bakery', 'Bakery / Toppings') ?></option>
                            <option value="General Supplies"><?= __('cat_general', 'General Supplies') ?></option>
                        </select>
                    </div>

                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_unit', 'Measurement Unit') ?> <span class="text-rose-400">*</span></label>
                        <select id="editUnit" name="unit" required class="w-full px-3.5 py-2.5 rounded-xl bg-[#141418] border border-[#282834] text-xs font-semibold text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                            <option value="g">g (Grams)</option>
                            <option value="ml">ml (Milliliters)</option>
                            <option value="pcs">pcs (Pieces)</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_qty_on_hand', 'Quantity on Hand') ?></label>
                        <input type="number" 
                               step="any" 
                               min="0" 
                               id="editQuantity" 
                               name="quantity" 
                               class="w-full px-3 py-2 rounded-xl bg-[#141418] border border-[#282834] text-xs text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                    </div>
                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_alert_threshold', 'Alert Threshold') ?></label>
                        <input type="number" 
                               step="any" 
                               min="0" 
                               id="editAlertLevel" 
                               name="alert_level" 
                               class="w-full px-3 py-2 rounded-xl bg-[#141418] border border-[#282834] text-xs text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                    </div>
                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_cost_box_unit', 'Cost Per Base Unit ($)') ?></label>
                        <input type="number" 
                               step="0.0001" 
                               min="0" 
                               id="editCostUnit" 
                               name="cost_per_unit" 
                               class="w-full px-3 py-2 rounded-xl bg-[#141418] border border-[#282834] text-xs text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                    </div>
                </div>

                <div>
                    <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('supplier_notes', 'Supplier & Notes') ?></label>
                    <textarea id="editNotes" 
                              name="notes" 
                              rows="2" 
                              class="w-full px-3.5 py-2 rounded-xl bg-[#141418] border border-[#282834] text-xs text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]"></textarea>
                </div>

                <div class="modal-footer flex items-center justify-end gap-2.5 pt-3 border-t border-[#252530]">
                    <button type="button" 
                            onclick="closeModal('editStockModal')" 
                            class="px-4 py-2 rounded-xl bg-[#202026] text-xs font-semibold text-[#b4b4c2] hover:text-white transition-all cursor-pointer">
                        <?= __('cancel', 'Cancel') ?>
                    </button>
                    <button type="submit" 
                            id="editStockSubmitBtn" 
                            class="px-5 py-2 rounded-xl bg-[#d1904b] hover:bg-[#e5a15a] text-black text-xs font-bold transition-all shadow-md shadow-[#d1904b]/20 cursor-pointer">
                        <?= __('btn_update_drink', 'Update Ingredient') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         MODAL 5: AUDIT LOGS
    ══════════════════════════════════════════════════════════════ -->
    <div id="auditLogsModal" class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75">
        <div class="modal-content glass-card max-w-4xl w-full p-6 bg-[#18181c] border border-[#2b2b36] rounded-2xl shadow-2xl relative flex flex-col max-h-[85vh]">
            <div class="modal-header flex items-center justify-between pb-3 mb-4 border-b border-[#252530]">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-[#d1904b]/20 text-[#d1904b] flex items-center justify-center text-sm font-bold">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                    <div>
                        <h3 class="modal-title text-base font-bold text-white"><?= __('audit_ledger_title', 'Ingredient Restocks & Waste Ledger') ?></h3>
                        <p class="text-xs text-[#8e8e9f] card-subtext"><?= __('audit_ledger_sub', 'Recent raw ingredient replenishments and logged wastage.') ?></p>
                    </div>
                </div>
                <button type="button" onclick="closeModal('auditLogsModal')" class="text-[#7d7d8e] hover:text-white p-1 text-sm">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="overflow-y-auto flex-1 space-y-4 pr-1" id="auditLogsContent">
                <div class="text-center py-8 text-[#8e8e9f]">
                    <i class="fa-solid fa-spinner fa-spin text-2xl text-[#d1904b] mb-2"></i>
                    <p>Loading audit ledger...</p>
                </div>
            </div>

            <div class="modal-footer flex items-center justify-end pt-3 border-t border-[#252530] mt-4">
                <button type="button" 
                        onclick="closeModal('auditLogsModal')" 
                        class="px-5 py-2 rounded-xl bg-[#202026] text-xs font-semibold text-white hover:bg-[#2b2b36] transition-all cursor-pointer">
                    <?= __('btn_close', 'Close Ledger') ?>
                </button>
            </div>
        </div>
    </div>

    <!-- ── JavaScript Client Engine ── -->
    <script>
        const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        const I18N = {
            lang: "<?= current_lang() ?>",
            inStock: "<?= __('status_in_stock', 'In Stock') ?>",
            lowStock: "<?= __('status_low_stock', 'Low Stock') ?>",
            outOfStock: "<?= __('status_out_of_stock', 'Out of Stock') ?>",
            restock: "<?= __('btn_restock', 'Restock') ?>",
            edit: "<?= __('btn_edit', 'Edit') ?>",
            delete: "<?= __('btn_delete', 'Delete') ?>",
            logWaste: "<?= __('log_waste', 'Log Waste') ?>",
            addRawIngredient: "<?= __('add_raw_ingredient', 'Add Raw Ingredient') ?>",
            showingIngredients: "<?= __('showing_ingredients_count', 'Showing raw ingredients') ?>",
            noIngredientsFound: "<?= __('no_data', 'No Raw Ingredients Found') ?>",
            recentRestocks: "<?= __('recent_restocks', 'Recent Raw Ingredient Restocks') ?>",
            recentWaste: "<?= __('recent_waste', 'Recent Wastage / Spillage') ?>",
            noRestocksYet: "<?= __('no_restocks_yet', 'No restock entries recorded yet.') ?>",
            noWasteYet: "<?= __('no_waste_yet', 'No waste logged yet.') ?>",
            date: "<?= __('date', 'Date') ?>",
            ingredient: "<?= __('col_ingredient_details', 'Ingredient') ?>",
            added: "<?= __('quantity_added', 'Added') ?>",
            wasted: "<?= __('log_waste', 'Wasted') ?>",
            reason: "<?= __('reason', 'Reason') ?>",
            supplierNotes: "<?= __('supplier_notes', 'Supplier / Notes') ?>",
            staff: "<?= __('staff_member', 'Staff') ?>",
            categories: {
                'Dairy': "<?= __('cat_dairy', 'Dairy & Milk') ?>",
                'Beans': "<?= __('cat_beans', 'Coffee Beans') ?>",
                'Syrups': "<?= __('cat_syrups', 'Syrups & Flavors') ?>",
                'Packaging': "<?= __('cat_packaging', 'Cups & Packaging') ?>",
                'Bakery / Toppings': "<?= __('cat_bakery', 'Bakery / Toppings') ?>",
                'General Supplies': "<?= __('cat_general', 'General Supplies') ?>"
            }
        };

        const CATEGORY_META = {
            'Dairy': { icon: 'fa-bottle-water', color: 'sky' },
            'Beans': { icon: 'fa-seedling', color: 'amber' },
            'Syrups': { icon: 'fa-flask', color: 'purple' },
            'Packaging': { icon: 'fa-box-open', color: 'emerald' },
            'Bakery / Toppings': { icon: 'fa-cookie-bite', color: 'yellow' },
            'General Supplies': { icon: 'fa-boxes-stacked', color: 'slate' }
        };

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatNumber(num, decimals = 2) {
            const val = parseFloat(num);
            if (isNaN(val)) return '0';
            if (Number.isInteger(val)) return val.toLocaleString('en-US');
            return val.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
        }

        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = 'toast-item';

            const iconClass = type === 'success' ? 'fa-solid fa-circle-check text-emerald-400' : 'fa-solid fa-triangle-exclamation text-rose-400';
            toast.innerHTML = `<i class="${iconClass} text-lg"></i><div class="text-xs font-semibold flex-1">${escapeHtml(message)}</div>`;
            container.appendChild(toast);

            setTimeout(() => {
                toast.classList.add('removing');
                setTimeout(() => toast.remove(), 250);
            }, 3000);
        }

        function openModal(modalId) {
            const m = document.getElementById(modalId);
            if (m) m.classList.add('active');
        }

        function closeModal(modalId) {
            const m = document.getElementById(modalId);
            if (m) m.classList.remove('active');
        }

        function filterByStatus(status) {
            document.getElementById('statusFilter').value = status;
            document.querySelectorAll('.stat-card').forEach(c => c.classList.remove('active'));
            if (status === 'all') document.getElementById('statTotal')?.classList.add('active');
            else if (status === 'in_stock') document.getElementById('statInStock')?.classList.add('active');
            else if (status === 'low_stock') document.getElementById('statLowStock')?.classList.add('active');
            else if (status === 'out_of_stock') document.getElementById('statOutOfStock')?.classList.add('active');
            loadStockTable();
        }

        function clearSearch() {
            document.getElementById('stockSearchInput').value = '';
            document.getElementById('clearSearchBtn').classList.add('hidden');
            loadStockTable();
        }

        function resetFilters() {
            document.getElementById('stockSearchInput').value = '';
            document.getElementById('clearSearchBtn').classList.add('hidden');
            document.getElementById('categoryFilter').value = 'all';
            document.getElementById('statusFilter').value = 'all';
            document.getElementById('sortSelector').value = 'name_asc';
            loadStockTable();
        }

        function triggerCsvExport() {
            window.location.href = 'ingredients.php?action=export_csv';
        }

        // Live Search Debounce
        let searchTimeout = null;
        document.getElementById('stockSearchInput').addEventListener('input', function(e) {
            const val = e.target.value.trim();
            document.getElementById('clearSearchBtn').classList.toggle('hidden', val === '');
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(loadStockTable, 300);
        });

        // ── AJAX Table Loader ──
        async function loadStockTable() {
            const search = document.getElementById('stockSearchInput').value.trim();
            const category = document.getElementById('categoryFilter').value;
            const status = document.getElementById('statusFilter').value;
            const sort = document.getElementById('sortSelector').value;

            const params = new URLSearchParams({
                action: 'get_ingredient_data',
                category: category,
                status: status,
                sort: sort,
                search: search
            });

            try {
                const res = await fetch(`ingredients.php?${params.toString()}`);
                const data = await res.json();

                if (!data.success) {
                    showToast(data.message || 'Error loading ingredients', 'error');
                    return;
                }

                updateKpiCards(data.kpis);
                renderTableRows(data.items);
                updateDropdownOptions(data.items);
            } catch (err) {
                console.error('Fetch error:', err);
                showToast('Failed to connect to server. Please refresh.', 'error');
            }
        }

        function updateKpiCards(kpis) {
            if (!kpis) return;
            const t = document.getElementById('kpiTotalItems');
            if (t) t.textContent = formatNumber(kpis.total_items, 0);
            const inSt = document.getElementById('kpiInStock');
            if (inSt) inSt.textContent = formatNumber(kpis.in_stock, 0);
            const lowSt = document.getElementById('kpiLowStock');
            if (lowSt) lowSt.textContent = formatNumber(kpis.low_stock, 0);
            const o = document.getElementById('kpiOutOfStock');
            if (o) o.textContent = formatNumber(kpis.out_of_stock, 0);
            const v = document.getElementById('kpiTotalValuation');
            if (v) v.textContent = '$' + (parseFloat(kpis.total_valuation) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function estimateCapacity(cat, qty, unit) {
            if (qty <= 0) return I18N.lang === 'km' ? '0 កែវ' : '0 cups';
            if (cat === 'Dairy' && unit === 'ml') return I18N.lang === 'km' ? `~${Math.floor(qty / 150)} កែវ (ឡាតេ)` : `~${Math.floor(qty / 150)} cups (latte)`;
            if (cat === 'Beans' && unit === 'g') return I18N.lang === 'km' ? `~${Math.floor(qty / 18)} ស៊ុត` : `~${Math.floor(qty / 18)} shots`;
            if (cat === 'Packaging' && unit === 'pcs') return `${Math.floor(qty)}` + (I18N.lang === 'km' ? " ចំណែក" : " servings");
            if (cat === 'Syrups' && unit === 'ml') return I18N.lang === 'km' ? `~${Math.floor(qty / 20)} កែវ` : `~${Math.floor(qty / 20)} drinks`;
            if (cat === 'Bakery / Toppings' && unit === 'g') return I18N.lang === 'km' ? `~${Math.floor(qty / 10)} កែវ` : `~${Math.floor(qty / 10)} cups`;
            return `${formatNumber(qty)} ${unit}`;
        }

        function renderTableRows(items) {
            const tbody = document.getElementById('stockTableBody');
            const countDisplay = document.getElementById('tableRecordCount');

            if (!items || items.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" class="py-12 text-center text-[#8e8e9f]">
                            <div class="w-12 h-12 rounded-full bg-[#1e1e24] text-[#d1904b] mx-auto flex items-center justify-center text-xl mb-3">
                                <i class="fa-solid fa-seedling"></i>
                            </div>
                            <div class="text-sm font-bold text-white mb-1">${escapeHtml(I18N.noIngredientsFound)}</div>
                            <p class="text-xs text-[#7d7d8e] max-w-sm mx-auto mb-4">No ingredients matched your current filters. Try resetting filters or adding a new raw ingredient.</p>
                            <button type="button" onclick="openAddStockModal()" class="px-4 py-2 rounded-xl bg-[#d1904b] text-black text-xs font-bold hover:bg-[#e5a15a] cursor-pointer">
                                <i class="fa-solid fa-plus mr-1"></i> ${escapeHtml(I18N.addRawIngredient)}
                            </button>
                        </td>
                    </tr>
                `;
                countDisplay.textContent = `${I18N.showingIngredients}: 0`;
                return;
            }

            countDisplay.textContent = `${I18N.showingIngredients}: ${items.length}`;

            let html = '';
            items.forEach(item => {
                const qty = parseFloat(item.quantity) || 0;
                const alert = parseFloat(item.alert_level) || 0;
                const cost = parseFloat(item.cost_per_unit) || 0;
                const val = qty * cost;

                let statusBadge = `<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> ${escapeHtml(I18N.inStock)}</span>`;
                let qtyColor = 'text-white';

                if (qty <= 0) {
                    statusBadge = `<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-rose-500/10 text-rose-400 border border-rose-500/20"><i class="fa-solid fa-circle-exclamation text-[10px]"></i> ${escapeHtml(I18N.outOfStock)}</span>`;
                    qtyColor = 'text-rose-400';
                } else if (qty <= alert) {
                    statusBadge = `<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-500/10 text-amber-400 border border-amber-500/20"><i class="fa-solid fa-triangle-exclamation text-[10px]"></i> ${escapeHtml(I18N.lowStock)}</span>`;
                    qtyColor = 'text-amber-400';
                }

                const catMeta = CATEGORY_META[item.category] || { icon: 'fa-box', color: 'slate' };
                const cap = estimateCapacity(item.category, qty, item.unit);
                const displayCategory = I18N.categories[item.category] || item.category;

                html += `
                <tr class="row-hover group" data-item-id="${item.item_id}">
                    <td class="py-3.5 px-4">
                        <div class="item-name-text font-bold text-[var(--text-main)] text-sm group-hover:text-[#d1904b] transition-colors truncate">
                            ${escapeHtml(item.item_name)}
                        </div>
                    </td>
                    <td class="py-3.5 px-3">
                        <span class="cat-badge inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold bg-[#1e1e24] text-[#b4b4c2] border border-[#282834]">
                            ${escapeHtml(displayCategory)}
                        </span>
                    </td>
                    <td class="py-3.5 px-3 font-semibold">
                        <span class="text-sm font-extrabold ${qtyColor === 'text-white' ? 'text-[var(--text-main)]' : qtyColor}">
                            ${formatNumber(qty)} <span class="text-xs font-normal text-[#8e8e9f]">${escapeHtml(item.unit)}</span>
                        </span>
                    </td>
                    <td class="py-3.5 px-3 font-medium">
                        <span class="threshold-badge px-2.5 py-1 rounded-lg bg-[#1e1e24] border border-[#282834] text-xs text-[#8e8e9f]">
                            ${formatNumber(alert)} ${escapeHtml(item.unit)}
                        </span>
                    </td>
                    <td class="py-3.5 px-3">
                        ${statusBadge}
                    </td>
                    <td class="py-3.5 px-3">
                        <div class="val-main-text text-[var(--text-main)] font-bold text-xs">$${val.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
                        <div class="text-[11px] text-[#8e8e9f]">$${cost.toFixed(4)} / ${escapeHtml(item.unit)}</div>
                    </td>
                    <td class="py-3.5 px-4 text-right">
                        <div class="flex items-center justify-end gap-1.5">
                            <button type="button" 
                                    onclick="openEditStockModal(${item.item_id})" 
                                    class="btn-action-neutral p-1.5 rounded-lg bg-[#1f1f26] text-[#b4b4c2] hover:text-white hover:bg-[#282832] border border-[#2b2b36] transition-all cursor-pointer" 
                                    title="${escapeHtml(I18N.edit)}">
                                <i class="fa-solid fa-pen-to-square w-4 text-center"></i>
                            </button>
                            <button type="button" 
                                    onclick="openRestockModal(${item.item_id})" 
                                    class="btn-action-neutral p-1.5 rounded-lg bg-[#1f1f26] text-[#b4b4c2] hover:text-emerald-400 hover:bg-emerald-500/15 border border-[#2b2b36] transition-all cursor-pointer" 
                                    title="${escapeHtml(I18N.restock)}">
                                <i class="fa-solid fa-plus w-4 text-center"></i>
                            </button>
                            <button type="button" 
                                    onclick="confirmDeleteItem(${item.item_id}, '${escapeHtml(item.item_name).replace(/'/g, "\\'")}')" 
                                    class="btn-action-neutral p-1.5 rounded-lg bg-[#1f1f26] text-[#8e8e9f] hover:text-rose-400 hover:bg-rose-500/15 border border-[#2b2b36] transition-all cursor-pointer" 
                                    title="${escapeHtml(I18N.delete)}">
                                <i class="fa-solid fa-trash-can w-4 text-center"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                `;
            });

            tbody.innerHTML = html;
        }

        function updateDropdownOptions(items) {
            if (!items) return;
            const rSelect = document.getElementById('restockItemSelect');
            const wSelect = document.getElementById('wasteItemSelect');
            const currentRVal = rSelect.value;
            const currentWVal = wSelect.value;

            let optHtml = '<option value="">-- Choose Ingredient --</option>';
            items.forEach(it => {
                optHtml += `<option value="${it.item_id}" data-unit="${escapeHtml(it.unit)}" data-qty="${it.quantity}" data-cost="${it.cost_per_unit}">
                    ${escapeHtml(it.item_name)} (${escapeHtml(I18N.ingredient)}: ${formatNumber(it.quantity)} ${escapeHtml(it.unit)})
                </option>`;
            });

            rSelect.innerHTML = optHtml;
            wSelect.innerHTML = optHtml;

            if (currentRVal) rSelect.value = currentRVal;
            if (currentWVal) wSelect.value = currentWVal;
        }

        // ── Modal Actions ──
        function openAddStockModal() {
            document.getElementById('addStockForm').reset();
            openModal('addStockModal');
        }

        async function handleAddStock(e) {
            e.preventDefault();
            const form = e.target;
            const btn = document.getElementById('addStockSubmitBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Saving...';

            const formData = new FormData(form);

            try {
                const res = await fetch('ingredients.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const result = await res.json();

                if (result.success) {
                    showToast(result.message, 'success');
                    closeModal('addStockModal');
                    form.reset();
                    loadStockTable();
                } else {
                    showToast(result.message || 'Failed to create ingredient.', 'error');
                }
            } catch (err) {
                console.error(err);
                showToast('Server connection error.', 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Save Ingredient';
            }
        }

        function openRestockModal(preSelectedId = null) {
            const form = document.getElementById('restockForm');
            form.reset();
            const select = document.getElementById('restockItemSelect');
            if (preSelectedId) {
                select.value = preSelectedId;
            }
            updateRestockModalPreview();
            openModal('restockModal');
        }

        function updateRestockModalPreview() {
            calculateRestockTotal();
        }

        function calculateRestockTotal() {
            const select = document.getElementById('restockItemSelect');
            const selectedOpt = select.selectedOptions[0];
            const qtyInput = parseFloat(document.getElementById('restockQtyInput').value) || 0;
            const mult = parseFloat(document.getElementById('restockMultiplier').value) || 1.0;

            const baseAdded = qtyInput * mult;
            const onHand = selectedOpt && selectedOpt.dataset.qty ? parseFloat(selectedOpt.dataset.qty) : 0;
            const unit = selectedOpt && selectedOpt.dataset.unit ? selectedOpt.dataset.unit : 'units';

            document.getElementById('restockAddedBaseUnits').textContent = `+${formatNumber(baseAdded)} ${unit}`;
            document.getElementById('restockCurrentQty').textContent = `${formatNumber(onHand)} ${unit}`;
            document.getElementById('restockNewQty').textContent = `${formatNumber(onHand + baseAdded)} ${unit}`;
        }

        async function handleQuickRestock(e) {
            e.preventDefault();
            const form = e.target;
            const btn = document.getElementById('restockSubmitBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Processing...';

            const formData = new FormData(form);

            try {
                const res = await fetch('ingredients.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const result = await res.json();

                if (result.success) {
                    showToast(result.message, 'success');
                    closeModal('restockModal');
                    form.reset();
                    loadStockTable();
                } else {
                    showToast(result.message || 'Restock failed.', 'error');
                }
            } catch (err) {
                console.error(err);
                showToast('Server connection error.', 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Confirm Restock';
            }
        }

        function openWasteModal(preSelectedId = null) {
            const form = document.getElementById('wasteForm');
            form.reset();
            const select = document.getElementById('wasteItemSelect');
            if (preSelectedId) {
                select.value = preSelectedId;
            }
            openModal('wasteModal');
        }

        async function handleLogWaste(e) {
            e.preventDefault();
            const form = e.target;
            const formData = new FormData(form);

            try {
                const res = await fetch('ingredients.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const result = await res.json();

                if (result.success) {
                    showToast(result.message, 'success');
                    closeModal('wasteModal');
                    form.reset();
                    loadStockTable();
                } else {
                    showToast(result.message || 'Failed to log waste.', 'error');
                }
            } catch (err) {
                console.error(err);
                showToast('Server connection error.', 'error');
            }
        }

        async function openEditStockModal(itemId) {
            try {
                const res = await fetch(`ingredients.php?action=get_item&item_id=${itemId}`);
                const data = await res.json();

                if (!data.success || !data.item) {
                    showToast(data.message || 'Failed to load ingredient.', 'error');
                    return;
                }

                const item = data.item;
                document.getElementById('editItemId').value = item.item_id;
                document.getElementById('editItemName').value = item.item_name;
                document.getElementById('editCategory').value = item.category;
                document.getElementById('editUnit').value = item.unit;
                document.getElementById('editQuantity').value = item.quantity;
                document.getElementById('editAlertLevel').value = item.alert_level;
                document.getElementById('editCostUnit').value = item.cost_per_unit;
                document.getElementById('editNotes').value = item.notes || '';

                openModal('editStockModal');
            } catch (err) {
                console.error(err);
                showToast('Error loading ingredient details.', 'error');
            }
        }

        async function handleEditStock(e) {
            e.preventDefault();
            const form = e.target;
            const btn = document.getElementById('editStockSubmitBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Saving...';

            const formData = new FormData(form);

            try {
                const res = await fetch('ingredients.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const result = await res.json();

                if (result.success) {
                    showToast(result.message, 'success');
                    closeModal('editStockModal');
                    loadStockTable();
                } else {
                    showToast(result.message || 'Failed to update ingredient.', 'error');
                }
            } catch (err) {
                console.error(err);
                showToast('Server connection error.', 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Update Ingredient';
            }
        }

        function confirmDeleteItem(itemId, itemName) {
            if (!confirm(`Are you sure you want to archive '${itemName}' from raw ingredients?`)) return;

            const formData = new FormData();
            formData.append('action', 'delete_item');
            formData.append('item_id', itemId);
            formData.append('csrf_token', CSRF_TOKEN);

            fetch('ingredients.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    showToast(res.message, 'success');
                    loadStockTable();
                } else {
                    showToast(res.message || 'Archive failed.', 'error');
                }
            })
            .catch(err => {
                console.error(err);
                showToast('Network error.', 'error');
            });
        }

        async function openAuditLogsModal() {
            openModal('auditLogsModal');
            const container = document.getElementById('auditLogsContent');
            container.innerHTML = `<div class="text-center py-8 text-[#8e8e9f]"><i class="fa-solid fa-spinner fa-spin text-2xl text-[#d1904b] mb-2"></i><p>Loading audit ledger...</p></div>`;

            try {
                const res = await fetch('ingredients.php?action=get_audit_logs');
                const data = await res.json();

                if (!data.success) {
                    container.innerHTML = `<p class="text-rose-400 text-center py-4">Failed to load audit logs.</p>`;
                    return;
                }

                let html = '';

                html += `<div class="space-y-2 mb-6"><h4 class="text-xs font-bold uppercase tracking-wider text-emerald-400 flex items-center gap-1.5"><i class="fa-solid fa-truck-ramp-box"></i> ${escapeHtml(I18N.recentRestocks)}</h4>`;
                if (!data.restocks || data.restocks.length === 0) {
                    html += `<p class="text-xs text-[#7d7d8e] italic py-2">${escapeHtml(I18N.noRestocksYet)}</p>`;
                } else {
                    html += `<div class="overflow-x-auto"><table class="w-full text-xs text-left"><thead class="text-[#8e8e9f] border-b border-[#252530]"><tr><th class="py-2 px-3">${escapeHtml(I18N.date)}</th><th class="py-2 px-3">${escapeHtml(I18N.ingredient)}</th><th class="py-2 px-3">${escapeHtml(I18N.added)}</th><th class="py-2 px-3">${escapeHtml(I18N.supplierNotes)}</th><th class="py-2 px-3">${escapeHtml(I18N.staff)}</th></tr></thead><tbody class="divide-y divide-[#202028]">`;
                    data.restocks.forEach(r => {
                        html += `<tr>
                            <td class="py-2 px-3 text-[#8e8e9f]">${escapeHtml(r.created_at)}</td>
                            <td class="py-2 px-3 font-bold text-[var(--text-main)]">${escapeHtml(r.item_name)}</td>
                            <td class="py-2 px-3 text-emerald-400 font-extrabold">+${formatNumber(r.quantity_added)} ${escapeHtml(r.unit)}</td>
                            <td class="py-2 px-3 text-[#b4b4c2]">${escapeHtml(r.supplier || r.notes || '--')}</td>
                            <td class="py-2 px-3 text-[#8e8e9f]">${escapeHtml(r.recorded_by || 'Staff')}</td>
                        </tr>`;
                    });
                    html += `</tbody></table></div>`;
                }
                html += `</div>`;

                html += `<div class="space-y-2"><h4 class="text-xs font-bold uppercase tracking-wider text-rose-400 flex items-center gap-1.5"><i class="fa-solid fa-trash-can-arrow-up"></i> ${escapeHtml(I18N.recentWaste)}</h4>`;
                if (!data.waste || data.waste.length === 0) {
                    html += `<p class="text-xs text-[#7d7d8e] italic py-2">${escapeHtml(I18N.noWasteYet)}</p>`;
                } else {
                    html += `<div class="overflow-x-auto"><table class="w-full text-xs text-left"><thead class="text-[#8e8e9f] border-b border-[#252530]"><tr><th class="py-2 px-3">${escapeHtml(I18N.date)}</th><th class="py-2 px-3">${escapeHtml(I18N.ingredient)}</th><th class="py-2 px-3">${escapeHtml(I18N.wasted)}</th><th class="py-2 px-3">${escapeHtml(I18N.reason)}</th><th class="py-2 px-3">${escapeHtml(I18N.staff)}</th></tr></thead><tbody class="divide-y divide-[#202028]">`;
                    data.waste.forEach(w => {
                        html += `<tr>
                            <td class="py-2 px-3 text-[#8e8e9f]">${escapeHtml(w.created_at)}</td>
                            <td class="py-2 px-3 font-bold text-[var(--text-main)]">${escapeHtml(w.item_name)}</td>
                            <td class="py-2 px-3 text-rose-400 font-extrabold">-${formatNumber(w.quantity_wasted)} ${escapeHtml(w.unit)}</td>
                            <td class="py-2 px-3"><span class="px-2 py-0.5 rounded bg-rose-500/10 text-rose-400 text-[10px] font-bold border border-rose-500/20">${escapeHtml(w.reason)}</span> ${w.notes ? '<span class="text-[#8e8e9f] ml-1">(' + escapeHtml(w.notes) + ')</span>' : ''}</td>
                            <td class="py-2 px-3 text-[#8e8e9f]">${escapeHtml(w.recorded_by || 'Staff')}</td>
                        </tr>`;
                    });
                    html += `</tbody></table></div>`;
                }
                html += `</div>`;

                container.innerHTML = html;
            } catch (err) {
                console.error(err);
                container.innerHTML = `<p class="text-rose-400 text-center py-4">Failed to load audit logs.</p>`;
            }
        }
    </script>
</body>
</html>
