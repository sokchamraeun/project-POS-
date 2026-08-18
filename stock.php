<?php
/**
 * Bird's Nest Coffee POS - Direct Drinks & Retail Stock Management (Cans & Bottles)
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

// ── Helper: Format Numbers ──
if (!function_exists('formatQty')) {
    function formatQty($qty): string {
        $f = (float)$qty;
        return (floor($f) == $f) ? number_format($f, 0) : number_format($f, 2);
    }
}

// ── Helper: Format Unit Label with proper Pluralization & Khmer support ──
if (!function_exists('formatUnitLabel')) {
    function formatUnitLabel(string $unit, float $qty = 2): string {
        $clean = trim(strtolower($unit));
        $lang = function_exists('current_lang') ? current_lang() : 'en';

        if ($lang === 'km') {
            $kmMap = [
                'can' => 'កំប៉ុង', 'cans' => 'កំប៉ុង',
                'bottle' => 'ដប', 'bottles' => 'ដប',
                'box' => 'កេស', 'boxes' => 'កេស',
                'pack' => 'យួរ/កញ្ចប់', 'packs' => 'យួរ/កញ្ចប់',
                'carton' => 'កាតុង', 'cartons' => 'កាតុង',
                'case' => 'កេសធំ', 'cases' => 'កេសធំ',
                'crate' => 'ស្នោ', 'crates' => 'ស្នោ',
                'dozen' => 'ឡូ', 'dozens' => 'ឡូ',
                'cup' => 'កែវ', 'cups' => 'កែវ',
                'shot' => 'ស៊ុត', 'shots' => 'ស៊ុត',
                'pcs' => 'គ្រាប់', 'piece' => 'គ្រាប់', 'pieces' => 'គ្រាប់',
                'bag' => 'ថង់', 'bags' => 'ថង់',
                'ml' => 'ml', 'g' => 'g', 'kg' => 'kg', 'oz' => 'oz'
            ];
            if (isset($kmMap[$clean])) return $kmMap[$clean];
            $cleanSingular = rtrim($clean, 's');
            if (isset($kmMap[$cleanSingular])) return $kmMap[$cleanSingular];
            return $unit;
        }

        if (in_array($clean, ['ml', 'g', 'kg', 'oz', 'pcs', 'servings', 'shots', 'cups', 'portion'])) {
            return $unit;
        }
        $singular = rtrim($clean, 's');
        if ($singular === 'boxe') $singular = 'box';
        
        if ($qty == 1.0) {
            return $singular;
        }
        if ($singular === 'box') {
            return 'boxes';
        }
        return $singular . 's';
    }
}

// ── Helper: Stock Breakdown Display (Boxes + Loose Cans/Bottles) ──
if (!function_exists('formatInventoryBreakdown')) {
    function formatInventoryBreakdown(float $qtyOnHand, string $baseUnit, string $purchaseUnit, float $conversionRate): string {
        $qtyFormatted = formatQty($qtyOnHand);
        $lang = function_exists('current_lang') ? current_lang() : 'en';

        if ($conversionRate <= 1.0) {
            return "{$qtyFormatted} " . formatUnitLabel($baseUnit, $qtyOnHand);
        }

        $boxes = floor($qtyOnHand / $conversionRate);
        $loose = fmod($qtyOnHand, $conversionRate);

        $boxLabel = formatUnitLabel($purchaseUnit, (float)$boxes);
        $baseLabel = formatUnitLabel($baseUnit, (float)$loose);

        if ($lang === 'km') {
            if ($boxes > 0 && $loose > 0) {
                return "{$boxes} {$boxLabel} + " . formatQty($loose) . " {$baseLabel}";
            } elseif ($boxes > 0 && $loose == 0.0) {
                return "{$boxes} {$boxLabel}ពេញ";
            } else {
                return "{$qtyFormatted} {$baseLabel}រាយ";
            }
        }

        if ($boxes > 0 && $loose > 0) {
            return "{$boxes} {$boxLabel} + " . formatQty($loose) . " {$baseLabel}";
        } elseif ($boxes > 0 && $loose == 0.0) {
            return "{$boxes} full {$boxLabel}";
        } else {
            return "{$qtyFormatted} loose {$baseLabel}";
        }
    }
}

// ── Helper: Calculate KPI Metrics for Direct Drinks ──
if (!function_exists('getDirectDrinkKPIs')) {
    function getDirectDrinkKPIs(PDO $pdo): array {
        $stmt = $pdo->query("SELECT 
            COUNT(*) as total_items,
            SUM(CASE WHEN quantity > alert_level THEN 1 ELSE 0 END) as in_stock,
            SUM(CASE WHEN quantity > 0 AND quantity <= alert_level THEN 1 ELSE 0 END) as low_stock,
            SUM(CASE WHEN quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock,
            SUM(quantity * cost_per_unit) as total_valuation,
            SUM(quantity) as total_units_count
        FROM stock_items WHERE item_type = 'direct_drink' AND is_active = 1");
        $kpi = $stmt->fetch() ?: [];
        
        return [
            'total_items'       => (int)($kpi['total_items'] ?? 0),
            'in_stock'          => (int)($kpi['in_stock'] ?? 0),
            'low_stock'         => (int)($kpi['low_stock'] ?? 0),
            'out_of_stock'      => (int)($kpi['out_of_stock'] ?? 0),
            'total_valuation'   => (float)($kpi['total_valuation'] ?? 0),
            'total_units_count' => (float)($kpi['total_units_count'] ?? 0),
        ];
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

        // 1. Fetch Direct Drinks (JSON)
        if ($action === 'get_stock_data') {
            $statusFilter = trim($_GET['status'] ?? '');
            $search = trim($_GET['search'] ?? '');
            $sortBy = trim($_GET['sort'] ?? 'name_asc');

            $sql = "SELECT s.*, COALESCE(NULLIF(s.image, ''), p.image, '') AS image 
                    FROM stock_items s 
                    LEFT JOIN products p ON LOWER(REPLACE(s.item_name, ' ', '')) = LOWER(REPLACE(p.name, ' ', '')) 
                    WHERE s.item_type = 'direct_drink' AND s.is_active = 1";
            $params = [];

            if ($statusFilter === 'low_stock') {
                $sql .= " AND s.quantity > 0 AND s.quantity <= s.alert_level";
            } elseif ($statusFilter === 'out_of_stock') {
                $sql .= " AND s.quantity <= 0";
            } elseif ($statusFilter === 'in_stock') {
                $sql .= " AND s.quantity > s.alert_level";
            }

            if ($search !== '') {
                $sql .= " AND (s.item_name LIKE ? OR s.notes LIKE ? OR s.unit LIKE ?)";
                $searchWild = "%{$search}%";
                $params[] = $searchWild;
                $params[] = $searchWild;
                $params[] = $searchWild;
            }

            switch ($sortBy) {
                case 'qty_asc':
                    $sql .= " ORDER BY s.quantity ASC, s.item_name ASC";
                    break;
                case 'qty_desc':
                    $sql .= " ORDER BY s.quantity DESC, s.item_name ASC";
                    break;
                case 'value_desc':
                    $sql .= " ORDER BY (s.quantity * s.cost_per_unit) DESC";
                    break;
                case 'newest':
                    $sql .= " ORDER BY s.item_id DESC";
                    break;
                case 'name_desc':
                    $sql .= " ORDER BY s.item_name DESC";
                    break;
                case 'name_asc':
                default:
                    $sql .= " ORDER BY s.item_name ASC";
                    break;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $items = $stmt->fetchAll();

            sendJsonResponse([
                'success' => true,
                'items'   => $items,
                'kpis'    => getDirectDrinkKPIs($pdo)
            ]);
        }

        // 2. Get Single Item
        if ($action === 'get_item' || $action === 'get_single_item') {
            $itemId = (int)($_GET['item_id'] ?? ($_POST['item_id'] ?? 0));
            $stmt = $pdo->prepare("SELECT * FROM stock_items WHERE item_id = ? AND item_type = 'direct_drink' AND is_active = 1 LIMIT 1");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();

            if (!$item) {
                sendJsonResponse(['success' => false, 'message' => 'Drink item not found.'], 404);
            }

            sendJsonResponse(['success' => true, 'item' => $item]);
        }

        // 3. Create New Canned/Bottled Drink
        if ($action === 'create_item') {
            $name         = trim($_POST['item_name'] ?? '');
            $unit         = trim($_POST['unit'] ?? 'can');
            $purchaseUnit = trim($_POST['purchase_unit'] ?? 'box');
            $rate         = max(1.0, (float)($_POST['conversion_rate'] ?? 24.0));
            $boxes        = (float)($_POST['initial_boxes'] ?? 0);
            $loose        = (float)($_POST['initial_loose'] ?? 0);
            $costBox      = (float)($_POST['cost_per_purchase_unit'] ?? 0);
            $alertLevel   = (float)($_POST['alert_level'] ?? 24.0); // e.g. 1 box
            $notes        = trim($_POST['notes'] ?? '');

            if (empty($name)) {
                sendJsonResponse(['success' => false, 'message' => 'Drink name is required.'], 422);
            }

            $totalBaseUnits = ($boxes * $rate) + $loose;
            $unitCost = ($costBox > 0 && $rate > 0) ? ($costBox / $rate) : 0.0;

            $pImg = $pdo->prepare("SELECT image FROM products WHERE LOWER(REPLACE(name, ' ', '')) = LOWER(REPLACE(?, ' ', '')) AND image IS NOT NULL AND image != '' LIMIT 1");
            $pImg->execute([$name]);
            $foundImg = $pImg->fetchColumn() ?: null;

            $stmt = $pdo->prepare("INSERT INTO stock_items 
                (item_name, image, category, item_type, quantity, unit, purchase_unit, conversion_rate, alert_level, cost_per_unit, cost_per_purchase_unit, notes, is_active) 
                VALUES (?, ?, 'Direct Drinks', 'direct_drink', ?, ?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([$name, $foundImg, $totalBaseUnits, $unit, $purchaseUnit, $rate, $alertLevel, $unitCost, $costBox, $notes]);
            $newId = (int)$pdo->lastInsertId();

            sendJsonResponse([
                'success' => true,
                'message' => "Direct drink '{$name}' added! Total on hand: {$totalBaseUnits} {$unit}s ({$boxes} {$purchaseUnit}s).",
                'item_id' => $newId
            ]);
        }

        // 4. Bulk Box Restock (Atomic Transaction)
        if ($action === 'quick_restock') {
            $itemId      = (int)($_POST['item_id'] ?? 0);
            $boxesToAdd  = (float)($_POST['purchase_qty'] ?? 0);
            $costPerBox  = isset($_POST['cost_per_box']) && $_POST['cost_per_box'] !== '' ? (float)$_POST['cost_per_box'] : null;
            $supplier    = trim($_POST['supplier'] ?? '');
            $notes       = trim($_POST['notes'] ?? '');

            if ($itemId <= 0 || $boxesToAdd <= 0) {
                sendJsonResponse(['success' => false, 'message' => 'Invalid drink selection or quantity.'], 422);
            }

            $pdo->beginTransaction();
            try {
                $cStmt = $pdo->prepare("SELECT item_name, quantity, unit, purchase_unit, conversion_rate, cost_per_unit, cost_per_purchase_unit 
                                       FROM stock_items WHERE item_id = ? AND is_active = 1 FOR UPDATE");
                $cStmt->execute([$itemId]);
                $cur = $cStmt->fetch();

                if (!$cur) {
                    $pdo->rollBack();
                    sendJsonResponse(['success' => false, 'message' => 'Drink item not found.'], 404);
                }

                $rate             = max(1.0, (float)$cur['conversion_rate']);
                $totalBaseUnits   = $boxesToAdd * $rate;
                $pUnit            = $cur['purchase_unit'];
                $bUnit            = $cur['unit'];

                $activeBoxCost    = ($costPerBox !== null && $costPerBox >= 0) ? $costPerBox : (float)$cur['cost_per_purchase_unit'];
                $activeUnitCost   = ($activeBoxCost > 0) ? ($activeBoxCost / $rate) : (float)$cur['cost_per_unit'];
                $totalPurchaseCost = $boxesToAdd * $activeBoxCost;

                $uStmt = $pdo->prepare("UPDATE stock_items SET 
                    quantity = quantity + ?, 
                    cost_per_purchase_unit = ?, 
                    cost_per_unit = ?, 
                    updated_at = NOW() 
                    WHERE item_id = ?");
                $uStmt->execute([$totalBaseUnits, $activeBoxCost, $activeUnitCost, $itemId]);

                $logDesc = "Bulk Restock: {$boxesToAdd} {$pUnit}(s) × {$rate} = +{$totalBaseUnits} {$bUnit}s";
                if ($notes !== '') $logDesc .= " | " . $notes;

                $rStmt = $pdo->prepare("INSERT INTO stock_restocks 
                    (item_id, quantity_added, cost_per_unit, total_cost, supplier, notes, recorded_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)");
                $rStmt->execute([$itemId, $totalBaseUnits, $activeUnitCost, $totalPurchaseCost, $supplier, $logDesc, $recorded_by]);

                $pdo->commit();

                sendJsonResponse([
                    'success' => true,
                    'message' => "Successfully restocked {$cur['item_name']}! Added +{$totalBaseUnits} {$bUnit}s ({$boxesToAdd} {$pUnit})."
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                sendJsonResponse(['success' => false, 'message' => 'Restock transaction failed: ' . $e->getMessage()], 500);
            }
        }

        // 5. Update Drink Details
        if ($action === 'update_item') {
            $itemId      = (int)($_POST['item_id'] ?? 0);
            $name        = trim($_POST['item_name'] ?? '');
            $unit        = trim($_POST['unit'] ?? 'can');
            $purchaseUnit= trim($_POST['purchase_unit'] ?? 'box');
            $rate        = max(1.0, (float)($_POST['conversion_rate'] ?? 24.0));
            $quantity    = (float)($_POST['quantity'] ?? 0);
            $alertLevel  = (float)($_POST['alert_level'] ?? 24.0);
            $costBox     = (float)($_POST['cost_per_purchase_unit'] ?? 0);
            $costUnit    = ($costBox > 0) ? ($costBox / $rate) : (float)($_POST['cost_per_unit'] ?? 0);
            $notes       = trim($_POST['notes'] ?? '');

            if ($itemId <= 0 || empty($name)) {
                sendJsonResponse(['success' => false, 'message' => 'Invalid parameters.'], 422);
            }

            $stmt = $pdo->prepare("UPDATE stock_items SET 
                item_name = ?, 
                quantity = ?, 
                unit = ?, 
                purchase_unit = ?, 
                conversion_rate = ?, 
                alert_level = ?, 
                cost_per_purchase_unit = ?, 
                cost_per_unit = ?, 
                notes = ?, 
                updated_at = NOW() 
                WHERE item_id = ? AND is_active = 1");
            $stmt->execute([$name, $quantity, $unit, $purchaseUnit, $rate, $alertLevel, $costBox, $costUnit, $notes, $itemId]);

            sendJsonResponse(['success' => true, 'message' => "Drink '{$name}' updated successfully!"]);
        }

        // 6. Delete Drink
        if ($action === 'delete_item') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE stock_items SET is_active = 0, updated_at = NOW() WHERE item_id = ?");
            $stmt->execute([$itemId]);

            sendJsonResponse(['success' => true, 'message' => 'Drink item archived successfully.']);
        }

        // 7. Export CSV
        if ($action === 'export_csv') {
            $stmt = $pdo->query("SELECT item_id, item_name, quantity, unit, purchase_unit, conversion_rate, alert_level, cost_per_unit, cost_per_purchase_unit, (quantity * cost_per_unit) AS valuation, notes, updated_at 
                FROM stock_items WHERE item_type = 'direct_drink' AND is_active = 1 ORDER BY item_name ASC");
            $rows = $stmt->fetchAll();

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=birds_nest_direct_drinks_stock_' . date('Y-m-d_His') . '.csv');
            
            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($output, ['Drink ID', 'Drink Name', 'Quantity (Units)', 'Unit', 'Package Unit', 'Units Per Package', 'Alert Level', 'Cost Per Unit ($)', 'Cost Per Box ($)', 'Total Valuation ($)', 'Stock Status', 'Notes', 'Last Updated']);

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
                    $row['quantity'],
                    $row['unit'],
                    $row['purchase_unit'],
                    $row['conversion_rate'],
                    $row['alert_level'],
                    number_format((float)$row['cost_per_unit'], 4, '.', ''),
                    number_format((float)$row['cost_per_purchase_unit'], 2, '.', ''),
                    number_format((float)$row['valuation'], 2, '.', ''),
                    $status,
                    $row['notes'],
                    $row['updated_at']
                ]);
            }
            fclose($output);
            exit;
        }

        // 8. Fetch Direct Drinks Audit / History Logs
        if ($action === 'get_audit_logs') {
            $rStmt = $pdo->query("SELECT r.*, s.item_name, s.unit, s.purchase_unit, s.conversion_rate 
                FROM stock_restocks r 
                JOIN stock_items s ON r.item_id = s.item_id 
                WHERE s.item_type = 'direct_drink' 
                ORDER BY r.created_at DESC LIMIT 40");
            $restocks = $rStmt->fetchAll();

            $wStmt = $pdo->query("SELECT w.*, s.item_name, s.unit 
                FROM stock_waste_logs w 
                JOIN stock_items s ON w.item_id = s.item_id 
                WHERE s.item_type = 'direct_drink' 
                ORDER BY w.created_at DESC LIMIT 40");
            $waste = $wStmt->fetchAll();

            sendJsonResponse([
                'success'  => true,
                'restocks' => $restocks,
                'waste'    => $waste
            ]);
        }

        sendJsonResponse(['success' => false, 'message' => 'Unknown action requested.'], 400);
    }
}

// ── Initial Page Load Data ──
$initialKpis = getDirectDrinkKPIs($pdo);
$initStmt = $pdo->query("SELECT s.*, COALESCE(NULLIF(s.image, ''), p.image, '') AS image 
                         FROM stock_items s 
                         LEFT JOIN products p ON LOWER(REPLACE(s.item_name, ' ', '')) = LOWER(REPLACE(p.name, ' ', '')) 
                         WHERE s.item_type = 'direct_drink' AND s.is_active = 1 
                         ORDER BY s.item_name ASC");
$stockItems = $initStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <title>Direct Drinks Stock Management | Bird's Nest Coffee</title>

    <!-- Google Fonts: Poppins -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

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

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg);
            color: var(--text-main);
            margin: 0;
            padding: 0;
            overflow: hidden;
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
        /* ── Mini Image Thumbnail for Table ── */
        .item-mini-img {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background-color: #1e1e24;
            border: 1px solid #282834;
            overflow: hidden;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.25);
        }
        .item-mini-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        [data-theme="light"] .item-mini-img {
            background-color: #f1f5f9 !important;
            border-color: #e2e4ea !important;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06) !important;
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
                    <h1 class="page-header-title text-xl md:text-2xl font-black tracking-tight"><?= __('direct_drinks_stock', 'Direct Drinks Stock') ?></h1>
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
                        <span><?= __('add_canned_bottled_drink', 'Add Canned / Bottled Drink') ?></span>
                    </button>
                </div>
            </div>

            <!-- ── Stats Bar (5 Cards: Total, In Stock, Low Stock, Out of Stock, Valuation) ── -->
            <div class="stats-bar">
                <!-- 1. Total -->
                <div class="stat-card total" id="statTotal" data-stat="total" role="button" tabindex="0" onclick="filterByStatus('all')" title="Click to show all drinks">
                    <div class="stat-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
                    <div class="stat-body">
                        <div class="stat-label"><?= __('kpi_total', 'TOTAL') ?></div>
                        <div class="stat-value" id="kpiTotalItems"><?= number_format($initialKpis['total_items']) ?></div>
                        <div class="stat-sub"><?= __('products_unit', 'Products') ?></div>
                    </div>
                </div>

                <!-- 2. In Stock -->
                <div class="stat-card avail in-stock" id="statInStock" data-stat="in_stock" role="button" tabindex="0" onclick="filterByStatus('in_stock')" title="Click to filter in stock drinks">
                    <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                    <div class="stat-body">
                        <div class="stat-label"><?= __('kpi_in_stock', 'IN STOCK') ?></div>
                        <div class="stat-value" id="kpiInStock"><?= number_format($initialKpis['in_stock']) ?></div>
                        <div class="stat-sub"><?= __('healthy_stock', 'healthy stock') ?></div>
                    </div>
                </div>

                <!-- 3. Low Stock -->
                <div class="stat-card amber low-stock" id="statLowStock" data-stat="low_stock" role="button" tabindex="0" onclick="filterByStatus('low_stock')" title="Click to filter low stock drinks">
                    <div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <div class="stat-body">
                        <div class="stat-label"><?= __('kpi_low_stock', 'LOW STOCK') ?></div>
                        <div class="stat-value" id="kpiLowStock"><?= number_format($initialKpis['low_stock']) ?></div>
                        <div class="stat-sub"><?= __('reorder_soon', 'reorder soon') ?></div>
                    </div>
                </div>

                <!-- 4. Out of Stock -->
                <div class="stat-card unavail out-stock" id="statOutOfStock" data-stat="out_of_stock" role="button" tabindex="0" onclick="filterByStatus('out_of_stock')" title="Click to filter out of stock drinks">
                    <div class="stat-icon"><i class="fa-solid fa-circle-xmark"></i></div>
                    <div class="stat-body">
                        <div class="stat-label"><?= __('kpi_out_of_stock', 'OUT OF STOCK') ?></div>
                        <div class="stat-value" id="kpiOutOfStock"><?= number_format($initialKpis['out_of_stock']) ?></div>
                        <div class="stat-sub"><?= __('depleted_items', 'depleted items') ?></div>
                    </div>
                </div>

                <!-- 5. Valuation -->
                <div class="stat-card top-cat valuation" id="statValuation" role="button" tabindex="0" title="Total drinks inventory valuation">
                    <div class="stat-icon"><i class="fa-solid fa-trophy"></i></div>
                    <div class="stat-body">
                        <div class="stat-label"><?= __('kpi_valuation', 'VALUATION') ?></div>
                        <div class="stat-value" id="kpiTotalValuation" style="font-size:22px;">$<?= number_format($initialKpis['total_valuation'], 2) ?></div>
                        <div class="stat-sub"><?= __('direct_drinks', 'Direct drinks') ?></div>
                    </div>
                </div>
            </div>

            <!-- ── Search & Filter Bar ── -->
            <div class="glass-card p-4 mb-6">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
                    
                    <!-- Search Input -->
                    <div class="relative flex-1 min-w-[220px]">
                        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-[#727282] text-sm"></i>
                        <input type="text" 
                               id="stockSearchInput" 
                               placeholder="<?= __('search_drinks_ph', 'Search direct drinks by name (e.g. Sting, Coke, Water)...') ?>" 
                               class="w-full pl-10 pr-9 py-2.5 rounded-xl bg-[#141418] border border-[#252530] text-sm text-[var(--text-main)] placeholder-[#727282] focus:outline-none focus:border-[#d1904b] focus:ring-1 focus:ring-[#d1904b] transition-all">
                        <button type="button" 
                                id="clearSearchBtn" 
                                onclick="clearSearch()" 
                                class="hidden absolute right-3 top-1/2 -translate-y-1/2 text-[#727282] hover:text-white text-xs p-1">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>

                    <!-- Filter & Sort Controls -->
                    <div class="flex flex-wrap items-center gap-2.5">
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
                                <th class="py-3.5 px-4"><?= __('col_drink_product', 'Drink Product') ?></th>
                                <th class="py-3.5 px-3"><?= __('col_unit', 'Unit') ?></th>
                                <th class="py-3.5 px-3"><?= __('col_conversion_rate', 'Conversion Rate') ?></th>
                                <th class="py-3.5 px-3"><?= __('col_total_qty', 'Total Qty (Units)') ?></th>
                                <th class="py-3.5 px-3"><?= __('col_breakdown', 'Package Breakdown') ?></th>
                                <th class="py-3.5 px-3"><?= __('col_cost_box_unit', 'Cost (Per Box / Unit)') ?></th>
                                <th class="py-3.5 px-3"><?= __('col_status', 'Status') ?></th>
                                <th class="py-3.5 px-3"><?= __('col_valuation', 'Valuation') ?></th>
                                <th class="py-3.5 px-4 text-right"><?= __('col_actions', 'Actions') ?></th>
                            </tr>
                        </thead>
                        <tbody id="stockTableBody" class="table-divide divide-y divide-[#1f1f28]">
                            <!-- Initial PHP render -->
                            <?php foreach ($stockItems as $item): 
                                $qty = (float)$item['quantity'];
                                $rate = max(1.0, (float)$item['conversion_rate']);
                                $alert = (float)$item['alert_level'];
                                $costUnit = (float)$item['cost_per_unit'];
                                $costBox = (float)$item['cost_per_purchase_unit'];
                                $val = $qty * $costUnit;

                                $status = 'in_stock';
                                $statusBadge = '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> ' . __('status_in_stock', 'In Stock') . '</span>';
                                
                                if ($qty <= 0) {
                                    $status = 'out_of_stock';
                                    $statusBadge = '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-rose-500/10 text-rose-400 border border-rose-500/20"><i class="fa-solid fa-circle-exclamation text-[10px]"></i> ' . __('status_out_of_stock', 'Out of Stock') . '</span>';
                                } elseif ($qty <= $alert) {
                                    $status = 'low_stock';
                                    $statusBadge = '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-500/10 text-amber-400 border border-amber-500/20"><i class="fa-solid fa-triangle-exclamation text-[10px]"></i> ' . __('status_low_stock', 'Low Stock') . '</span>';
                                }

                                $breakdown = formatInventoryBreakdown($qty, $item['unit'], $item['purchase_unit'], $rate);
                            ?>
                            <tr class="row-hover group" data-item-id="<?= $item['item_id'] ?>">
                                <td class="py-3 px-4">
                                    <div class="flex items-center gap-3">
                                        <div class="item-mini-img">
                                            <?php 
                                            $imgSrc = !empty($item['image']) ? $item['image'] : 'uploads/no-image.png'; 
                                            ?>
                                            <img src="<?= htmlspecialchars($imgSrc) ?>" alt="<?= htmlspecialchars($item['item_name']) ?>" onerror="this.onerror=null; this.src='uploads/no-image.png';">
                                        </div>
                                        <div class="min-w-0">
                                            <div class="item-name-text font-bold text-[var(--text-main)] text-sm group-hover:text-[#d1904b] transition-colors truncate">
                                                <?= htmlspecialchars($item['item_name']) ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3.5 px-3">
                                    <span class="cat-badge inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold bg-[#1e1e24] text-[#b4b4c2] border border-[#282834] uppercase">
                                        <?= htmlspecialchars(formatUnitLabel($item['unit'], 1)) ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-3 text-xs text-[#8e8e9f]">
                                    1 <?= formatUnitLabel($item['purchase_unit'], 1) ?> = <span class="font-bold text-[var(--text-main)]"><?= (int)$rate ?></span> <?= formatUnitLabel($item['unit'], $rate) ?>
                                </td>
                                <td class="py-3.5 px-3 font-semibold">
                                    <span class="text-sm font-extrabold <?= ($qty <= 0) ? 'text-rose-400' : (($qty <= $alert) ? 'text-amber-400' : 'text-[var(--text-main)]') ?>">
                                        <?= formatQty($qty) ?> <span class="text-xs font-normal text-[#8e8e9f]"><?= formatUnitLabel($item['unit'], $qty) ?></span>
                                    </span>
                                </td>
                                <td class="py-3.5 px-3 font-medium">
                                    <span class="threshold-badge px-2.5 py-1 rounded-lg bg-[#1e1e24] border border-[#282834] text-xs font-semibold text-[#d1904b]">
                                        <?= htmlspecialchars($breakdown) ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-3 text-xs">
                                    <div class="font-bold text-[var(--text-main)]">$<?= number_format($costBox, 2) ?> / <?= formatUnitLabel($item['purchase_unit'], 1) ?></div>
                                    <div class="text-[11px] text-[#8e8e9f] mt-0.5">$<?= number_format($costUnit, 4) ?> / <?= formatUnitLabel($item['unit'], 1) ?></div>
                                </td>
                                <td class="py-3.5 px-3">
                                    <?= $statusBadge ?>
                                </td>
                                <td class="py-3.5 px-3">
                                    <div class="val-main-text text-[var(--text-main)] font-bold text-xs">$<?= number_format($val, 2) ?></div>
                                </td>
                                <td class="py-3.5 px-4 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <!-- Quick Box Restock -->
                                        <button type="button" 
                                                onclick="openRestockModal(<?= $item['item_id'] ?>)" 
                                                class="px-2.5 py-1.5 rounded-lg bg-[#d1904b]/15 text-[#d1904b] hover:bg-[#d1904b] hover:text-black font-bold transition-all cursor-pointer border border-[#d1904b]/30" 
                                                title="<?= __('btn_restock', 'Restock') ?>">
                                            <i class="fa-solid fa-boxes-stacked mr-1"></i> <?= __('btn_restock', 'Restock') ?>
                                        </button>
                                        <!-- Edit -->
                                        <button type="button" 
                                                onclick="openEditStockModal(<?= $item['item_id'] ?>)" 
                                                class="btn-action-neutral p-1.5 rounded-lg bg-[#1f1f26] text-[#b4b4c2] hover:text-white hover:bg-[#282832] border border-[#2b2b36] transition-all cursor-pointer" 
                                                title="<?= __('btn_edit', 'Edit') ?>">
                                            <i class="fa-solid fa-pen-to-square w-4 text-center"></i>
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
                        <?= __('showing_drinks_count', 'Showing direct drinks') ?>: <?= count($stockItems) ?>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         MODAL 1: ADD NEW CANNED/BOTTLED DRINK
    ══════════════════════════════════════════════════════════════ -->
    <div id="addStockModal" class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75">
        <div class="modal-content glass-card max-w-lg w-full p-6 bg-[#18181c] border border-[#2b2b36] rounded-2xl shadow-2xl relative">
            <div class="modal-header flex items-center justify-between pb-3 mb-4 border-b border-[#252530]">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-[#d1904b]/20 text-[#d1904b] flex items-center justify-center text-sm font-bold">
                        <i class="fa-solid fa-wine-bottle"></i>
                    </div>
                    <h3 class="modal-title text-base font-bold text-white"><?= __('add_canned_bottled_drink', 'Add Canned / Bottled Drink') ?></h3>
                </div>
                <button type="button" onclick="closeModal('addStockModal')" class="text-[#7d7d8e] hover:text-white p-1 text-sm">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form id="addStockForm" onsubmit="handleAddStock(event)" class="space-y-4">
                <input type="hidden" name="action" value="create_item">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div>
                    <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_drink_product', 'Drink Name') ?> <span class="text-rose-400">*</span></label>
                    <input type="text" 
                           name="item_name" 
                           required 
                           placeholder="e.g. Sting Energy Drink 250ml, Coca-Cola 330ml" 
                           class="w-full px-3.5 py-2.5 rounded-xl bg-[#141418] border border-[#282834] text-sm text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('single_unit', 'Single Unit') ?> *</label>
                        <select name="unit" required class="w-full px-3.5 py-2.5 rounded-xl bg-[#141418] border border-[#282834] text-xs font-semibold text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                            <option value="can" selected><?= current_lang() === 'km' ? 'កំប៉ុង (Can)' : 'Can' ?></option>
                            <option value="bottle"><?= current_lang() === 'km' ? 'ដប (Bottle)' : 'Bottle' ?></option>
                            <option value="pack"><?= current_lang() === 'km' ? 'កញ្ចប់ (Pack)' : 'Pack' ?></option>
                            <option value="pcs"><?= current_lang() === 'km' ? 'គ្រាប់ / ដុំ (Pcs)' : 'Pcs (Pieces)' ?></option>
                        </select>
                    </div>

                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('package_unit', 'Purchase Package') ?> *</label>
                        <select name="purchase_unit" required class="w-full px-3.5 py-2.5 rounded-xl bg-[#141418] border border-[#282834] text-xs font-semibold text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                            <option value="box" selected><?= current_lang() === 'km' ? 'កេស (Box)' : 'Box' ?></option>
                            <option value="pack"><?= current_lang() === 'km' ? 'យួរ / កញ្ចប់ (Pack)' : 'Pack / Sleeve' ?></option>
                            <option value="carton"><?= current_lang() === 'km' ? 'កាតុង (Carton)' : 'Carton' ?></option>
                            <option value="dozen"><?= current_lang() === 'km' ? 'ឡូ (Dozen)' : 'Dozen (12 pcs)' ?></option>
                            <option value="case"><?= current_lang() === 'km' ? 'កេសធំ (Case)' : 'Case' ?></option>
                        </select>
                    </div>

                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('units_per_package', 'Units per Box') ?> *</label>
                        <input type="number" 
                               step="1" 
                               min="1" 
                               name="conversion_rate" 
                               value="24" 
                               required 
                               class="w-full px-3 py-2 rounded-xl bg-[#141418] border border-[#282834] text-xs font-bold text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('initial_boxes', 'Initial Boxes') ?></label>
                        <input type="number" 
                               step="1" 
                               min="0" 
                               name="initial_boxes" 
                               value="0" 
                               class="w-full px-3 py-2 rounded-xl bg-[#141418] border border-[#282834] text-xs text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                    </div>
                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('loose_units', 'Loose Units') ?></label>
                        <input type="number" 
                               step="1" 
                               min="0" 
                               name="initial_loose" 
                               value="0" 
                               class="w-full px-3 py-2 rounded-xl bg-[#141418] border border-[#282834] text-xs text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                    </div>
                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_cost_box', 'Cost Per Box ($)') ?></label>
                        <input type="number" 
                               step="0.01" 
                               min="0" 
                               name="cost_per_purchase_unit" 
                               value="12.00" 
                               class="w-full px-3 py-2 rounded-xl bg-[#141418] border border-[#282834] text-xs text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                    </div>
                </div>

                <div>
                    <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('supplier_notes', 'Notes / Supplier') ?></label>
                    <textarea name="notes" 
                              rows="2" 
                              placeholder="e.g. Cambodia Beverage Co. Fast selling canned soda" 
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
                        <?= __('save', 'Save Direct Drink') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         MODAL 2: QUICK BOX RESTOCK (WITH UNIT CONVERSION)
    ══════════════════════════════════════════════════════════════ -->
    <div id="restockModal" class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75">
        <div class="modal-content glass-card max-w-md w-full p-6 bg-[#18181c] border border-[#2b2b36] rounded-2xl shadow-2xl relative">
            <div class="modal-header flex items-center justify-between pb-3 mb-4 border-b border-[#252530]">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-[#d1904b]/20 text-[#d1904b] flex items-center justify-center text-sm font-bold">
                        <i class="fa-solid fa-boxes-stacked"></i>
                    </div>
                    <div>
                        <h3 class="modal-title text-base font-bold text-white"><?= __('quick_restock', 'Quick Box Restock') ?></h3>
                        <p class="text-xs text-[#8e8e9f]">Auto-converts boxes into individual units</p>
                    </div>
                </div>
                <button type="button" onclick="closeModal('restockModal')" class="text-[#7d7d8e] hover:text-white p-1 text-sm">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form id="restockForm" onsubmit="handleQuickRestock(event)" class="space-y-4">
                <input type="hidden" name="action" value="quick_restock">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div>
                    <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_drink_product', 'Select Drink') ?> <span class="text-rose-400">*</span></label>
                    <select name="item_id" 
                            id="restockItemSelect" 
                            required 
                            onchange="updateRestockModalPreview()" 
                            class="w-full px-3.5 py-2.5 rounded-xl bg-[#141418] border border-[#282834] text-xs font-semibold text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                        <option value="">-- Choose Drink --</option>
                        <?php foreach ($stockItems as $it): ?>
                        <option value="<?= $it['item_id'] ?>" 
                                data-unit="<?= htmlspecialchars($it['unit']) ?>" 
                                data-punit="<?= htmlspecialchars($it['purchase_unit']) ?>"
                                data-rate="<?= (float)$it['conversion_rate'] ?>"
                                data-qty="<?= (float)$it['quantity'] ?>" 
                                data-boxcost="<?= (float)$it['cost_per_purchase_unit'] ?>">
                            <?= htmlspecialchars($it['item_name']) ?> (1 <?= htmlspecialchars(formatUnitLabel($it['purchase_unit'], 1)) ?> = <?= (int)$it['conversion_rate'] ?> <?= htmlspecialchars(formatUnitLabel($it['unit'], (float)$it['conversion_rate'])) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1" id="lblRestockQty"><?= __('boxes_added', 'Boxes to Add') ?> *</label>
                        <input type="number" 
                               step="0.01" 
                               min="0.01" 
                               name="purchase_qty" 
                               id="restockQtyInput" 
                               required 
                               placeholder="e.g. 3" 
                               oninput="calculateRestockTotal()" 
                               class="w-full px-3.5 py-2 rounded-xl bg-[#141418] border border-[#282834] text-sm font-bold text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                    </div>

                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_cost_box_unit', 'Cost Per Box ($)') ?></label>
                        <input type="number" 
                               step="0.01" 
                               min="0" 
                               name="cost_per_box" 
                               id="restockCostInput" 
                               placeholder="e.g. 12.00" 
                               oninput="calculateRestockTotal()" 
                               class="w-full px-3.5 py-2 rounded-xl bg-[#141418] border border-[#282834] text-sm text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                    </div>
                </div>

                <!-- Live Conversion Result Badge -->
                <div id="restockPreviewCard" class="p-3.5 rounded-xl bg-amber-500/10 border border-amber-500/20 text-xs space-y-1.5">
                    <div class="flex items-center justify-between font-bold text-amber-400">
                        <span class="flex items-center gap-1.5">
                            <i class="fa-solid fa-calculator"></i> <?= __('col_conversion_rate', 'Conversion Result') ?>:
                        </span>
                        <span id="restockBadgeUnits" class="text-sm font-extrabold text-white">+0 units</span>
                    </div>
                    <p id="restockFormula" class="text-[11px] text-[#e0d4c4]">Select a drink and enter quantity to see calculation.</p>
                    <div class="pt-1.5 mt-1.5 border-t border-amber-500/20 flex items-center justify-between text-[11px]">
                        <span id="restockCurrentStock">Current: --</span>
                        <span id="restockProjectedStock" class="font-bold text-emerald-400">New Total: --</span>
                    </div>
                </div>

                <div>
                    <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('supplier_notes', 'Supplier / Vendor') ?></label>
                    <input type="text" 
                           name="supplier" 
                           placeholder="e.g. Cambodia Beverage Co." 
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
                        <?= __('btn_confirm_restock', 'Complete Restock') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         MODAL 3: EDIT DRINK DETAILS
    ══════════════════════════════════════════════════════════════ -->
    <div id="editStockModal" class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75">
        <div class="modal-content glass-card max-w-lg w-full p-6 bg-[#18181c] border border-[#2b2b36] rounded-2xl shadow-2xl relative">
            <div class="modal-header flex items-center justify-between pb-3 mb-4 border-b border-[#252530]">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-[#d1904b]/20 text-[#d1904b] flex items-center justify-center text-sm font-bold">
                        <i class="fa-solid fa-pen-to-square"></i>
                    </div>
                    <h3 class="modal-title text-base font-bold text-white"><?= __('btn_edit', 'Edit Drink Details') ?></h3>
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
                    <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_drink_product', 'Drink Name') ?> <span class="text-rose-400">*</span></label>
                    <input type="text" 
                           id="editItemName" 
                           name="item_name" 
                           required 
                           class="w-full px-3.5 py-2.5 rounded-xl bg-[#141418] border border-[#282834] text-sm text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('single_unit', 'Single Unit') ?></label>
                        <select id="editUnit" name="unit" required class="w-full px-3.5 py-2.5 rounded-xl bg-[#141418] border border-[#282834] text-xs font-semibold text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                            <option value="can"><?= current_lang() === 'km' ? 'កំប៉ុង (Can)' : 'Can' ?></option>
                            <option value="bottle"><?= current_lang() === 'km' ? 'ដប (Bottle)' : 'Bottle' ?></option>
                            <option value="pack"><?= current_lang() === 'km' ? 'កញ្ចប់ (Pack)' : 'Pack' ?></option>
                            <option value="pcs"><?= current_lang() === 'km' ? 'គ្រាប់ / ដុំ (Pcs)' : 'Pcs (Pieces)' ?></option>
                        </select>
                    </div>

                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('package_unit', 'Package Unit') ?></label>
                        <select id="editPurchaseUnit" name="purchase_unit" required class="w-full px-3.5 py-2.5 rounded-xl bg-[#141418] border border-[#282834] text-xs font-semibold text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                            <option value="box"><?= current_lang() === 'km' ? 'កេស (Box)' : 'Box' ?></option>
                            <option value="pack"><?= current_lang() === 'km' ? 'យួរ / កញ្ចប់ (Pack)' : 'Pack / Sleeve' ?></option>
                            <option value="carton"><?= current_lang() === 'km' ? 'កាតុង (Carton)' : 'Carton' ?></option>
                            <option value="dozen"><?= current_lang() === 'km' ? 'ឡូ (Dozen)' : 'Dozen (12 pcs)' ?></option>
                            <option value="case"><?= current_lang() === 'km' ? 'កេសធំ (Case)' : 'Case' ?></option>
                        </select>
                    </div>

                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('units_per_package', 'Units Per Box') ?></label>
                        <input type="number" 
                               step="1" 
                               min="1" 
                               id="editConversionRate" 
                               name="conversion_rate" 
                               required 
                               class="w-full px-3 py-2 rounded-xl bg-[#141418] border border-[#282834] text-xs font-bold text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_total_qty', 'Qty on Hand (Units)') ?></label>
                        <input type="number" 
                               step="any" 
                               min="0" 
                               id="editQuantity" 
                               name="quantity" 
                               class="w-full px-3 py-2 rounded-xl bg-[#141418] border border-[#282834] text-xs text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                    </div>
                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('alert_threshold', 'Alert Threshold') ?></label>
                        <input type="number" 
                               step="any" 
                               min="0" 
                               id="editAlertLevel" 
                               name="alert_level" 
                               class="w-full px-3 py-2 rounded-xl bg-[#141418] border border-[#282834] text-xs text-[var(--text-main)] focus:outline-none focus:border-[#d1904b]">
                    </div>
                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_cost_box', 'Cost Per Box ($)') ?></label>
                        <input type="number" 
                               step="0.01" 
                               min="0" 
                               id="editCostPurchase" 
                               name="cost_per_purchase_unit" 
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
                        <?= __('btn_update_drink', 'Update Drink') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         MODAL 4: AUDIT LOGS & HISTORY
    ══════════════════════════════════════════════════════════════ -->
    <div id="auditLogsModal" class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75">
        <div class="modal-content glass-card max-w-4xl w-full p-6 bg-[#18181c] border border-[#2b2b36] rounded-2xl shadow-2xl relative flex flex-col max-h-[85vh]">
            <div class="modal-header flex items-center justify-between pb-3 mb-4 border-b border-[#252530]">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-[#d1904b]/20 text-[#d1904b] flex items-center justify-center text-sm font-bold">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                    <div>
                        <h3 class="modal-title text-base font-bold text-white"><?= __('audit_ledger_title', 'Direct Drinks Audit & History Ledger') ?></h3>
                        <p class="text-xs text-[#8e8e9f] card-subtext"><?= __('audit_ledger_sub', 'Recent box restocks and direct drink activity history.') ?></p>
                    </div>
                </div>
                <button type="button" onclick="closeModal('auditLogsModal')" class="text-[#7d7d8e] hover:text-white p-1 text-sm">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div id="auditLogsContent" class="overflow-y-auto flex-1 pr-1 space-y-4">
                <!-- Loaded via AJAX -->
            </div>

            <div class="modal-footer flex items-center justify-end pt-3 mt-3 border-t border-[#252530]">
                <button type="button" onclick="closeModal('auditLogsModal')" class="px-4 py-2 rounded-xl bg-[#202026] text-xs font-semibold text-[#b4b4c2] hover:text-white transition-all cursor-pointer">
                    <?= __('btn_close', 'Close') ?>
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
            showingDrinks: "<?= __('showing_drinks_count', 'Showing direct drinks') ?>",
            noDrinksFound: "<?= __('no_data', 'No Direct Drinks Found') ?>",
            recentRestocks: "<?= __('recent_restocks', 'Recent Direct Drink Restocks') ?>",
            recentWaste: "<?= __('recent_waste', 'Recent Wastage / Breakage') ?>",
            noRestocksYet: "<?= __('no_restocks_yet', 'No restock entries recorded yet.') ?>",
            noWasteYet: "<?= __('no_waste_yet', 'No waste logged yet.') ?>",
            date: "<?= __('date', 'Date') ?>",
            drink: "<?= __('col_drink_product', 'Drink') ?>",
            boxesAdded: "<?= __('boxes_added', 'Boxes Added') ?>",
            unitsAdded: "<?= __('units_added', 'Units Added') ?>",
            wasted: "<?= __('log_waste', 'Wasted') ?>",
            reason: "<?= __('reason', 'Reason') ?>",
            supplierNotes: "<?= __('supplier_notes', 'Supplier / Notes') ?>",
            staff: "<?= __('staff_member', 'Staff') ?>"
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
            document.getElementById('statusFilter').value = 'all';
            document.getElementById('sortSelector').value = 'name_asc';
            loadStockTable();
        }

        function triggerCsvExport() {
            window.location.href = 'stock.php?action=export_csv';
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
            const status = document.getElementById('statusFilter').value;
            const sort = document.getElementById('sortSelector').value;

            const params = new URLSearchParams({
                action: 'get_stock_data',
                status: status,
                sort: sort,
                search: search
            });

            try {
                const res = await fetch(`stock.php?${params.toString()}`);
                const data = await res.json();

                if (!data.success) {
                    showToast(data.message || 'Error loading stock', 'error');
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

        function formatUnitLabel(unit, qty = 2) {
            if (!unit) return '';
            const clean = unit.trim().toLowerCase();
            if (I18N.lang === 'km') {
                const kmMap = {
                    'can': 'កំប៉ុង', 'cans': 'កំប៉ុង',
                    'bottle': 'ដប', 'bottles': 'ដប',
                    'box': 'កេស', 'boxes': 'កេស',
                    'pack': 'កញ្ចប់', 'packs': 'កញ្ចប់',
                    'case': 'កេស', 'cases': 'កេស',
                    'crate': 'កេស', 'crates': 'កេស',
                    'cup': 'កែវ', 'cups': 'កែវ',
                    'shot': 'ស៊ុត', 'shots': 'ស៊ុត',
                    'pcs': 'គ្រាប់', 'piece': 'គ្រាប់', 'pieces': 'គ្រាប់',
                    'bag': 'ថង់', 'bags': 'ថង់',
                    'ml': 'ml', 'g': 'g', 'kg': 'kg', 'oz': 'oz'
                };
                if (kmMap[clean]) return kmMap[clean];
                const cleanSingular = clean.endsWith('s') ? clean.slice(0, -1) : clean;
                if (kmMap[cleanSingular]) return kmMap[cleanSingular];
                return unit;
            }

            if (['ml', 'g', 'kg', 'oz', 'pcs', 'servings', 'shots', 'cups', 'portion'].includes(clean)) {
                return unit;
            }
            let singular = clean.endsWith('s') ? clean.slice(0, -1) : clean;
            if (singular === 'boxe') singular = 'box';

            if (qty === 1) return singular;
            if (singular === 'box') return 'boxes';
            return singular + 's';
        }

        function calculateBreakdownText(qty, unit, punit, rate) {
            if (rate <= 1) return `${formatNumber(qty)} ${formatUnitLabel(unit, qty)}`;
            const boxes = Math.floor(qty / rate);
            const loose = qty % rate;
            const boxLabel = formatUnitLabel(punit, boxes);
            const baseLabel = formatUnitLabel(unit, loose);

            if (I18N.lang === 'km') {
                if (boxes > 0 && loose > 0) return `${boxes} ${boxLabel} + ${formatNumber(loose)} ${baseLabel}`;
                if (boxes > 0 && loose === 0) return `${boxes} ${boxLabel}ពេញ`;
                return `${formatNumber(qty)} ${baseLabel}រាយ`;
            }

            if (boxes > 0 && loose > 0) return `${boxes} ${boxLabel} + ${formatNumber(loose)} ${baseLabel}`;
            if (boxes > 0 && loose === 0) return `${boxes} full ${boxLabel}`;
            return `${formatNumber(qty)} loose ${baseLabel}`;
        }

        function renderTableRows(items) {
            const tbody = document.getElementById('stockTableBody');
            const countDisplay = document.getElementById('tableRecordCount');

            if (!items || items.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="9" class="py-12 text-center text-[#8e8e9f]">
                            <div class="w-12 h-12 rounded-full bg-[#1e1e24] text-[#d1904b] mx-auto flex items-center justify-center text-xl mb-3">
                                <i class="fa-solid fa-wine-bottle"></i>
                            </div>
                            <div class="text-sm font-bold text-white mb-1">${escapeHtml(I18N.noDrinksFound)}</div>
                            <p class="text-xs text-[#7d7d8e] max-w-sm mx-auto mb-4">No direct drinks matched your search. Try resetting filters or adding a new canned/bottled drink.</p>
                            <button type="button" onclick="openAddStockModal()" class="px-4 py-2 rounded-xl bg-[#d1904b] text-black text-xs font-bold hover:bg-[#e5a15a] cursor-pointer">
                                <i class="fa-solid fa-plus mr-1"></i> Add Direct Drink
                            </button>
                        </td>
                    </tr>
                `;
                countDisplay.textContent = `${I18N.showingDrinks}: 0`;
                return;
            }

            countDisplay.textContent = `${I18N.showingDrinks}: ${items.length}`;

            let html = '';
            items.forEach(item => {
                const qty = parseFloat(item.quantity) || 0;
                const rate = Math.max(1, parseFloat(item.conversion_rate) || 24);
                const alert = parseFloat(item.alert_level) || 0;
                const costUnit = parseFloat(item.cost_per_unit) || 0;
                const costBox = parseFloat(item.cost_per_purchase_unit) || 0;
                const val = qty * costUnit;
                const breakdown = calculateBreakdownText(qty, item.unit, item.purchase_unit, rate);

                let statusBadge = `<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> ${escapeHtml(I18N.inStock)}</span>`;
                let qtyColor = 'text-white';

                if (qty <= 0) {
                    statusBadge = `<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-rose-500/10 text-rose-400 border border-rose-500/20"><i class="fa-solid fa-circle-exclamation text-[10px]"></i> ${escapeHtml(I18N.outOfStock)}</span>`;
                    qtyColor = 'text-rose-400';
                } else if (qty <= alert) {
                    statusBadge = `<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-500/10 text-amber-400 border border-amber-500/20"><i class="fa-solid fa-triangle-exclamation text-[10px]"></i> ${escapeHtml(I18N.lowStock)}</span>`;
                    qtyColor = 'text-amber-400';
                }

                const imgSrc = item.image ? item.image : 'uploads/no-image.png';

                html += `
                <tr class="row-hover group" data-item-id="${item.item_id}">
                    <td class="py-3 px-4">
                        <div class="flex items-center gap-3">
                            <div class="item-mini-img">
                                <img src="${escapeHtml(imgSrc)}" alt="${escapeHtml(item.item_name)}" onerror="this.onerror=null; this.src='uploads/no-image.png';">
                            </div>
                            <div class="min-w-0">
                                <div class="item-name-text font-bold text-[var(--text-main)] text-sm group-hover:text-[#d1904b] transition-colors truncate">
                                    ${escapeHtml(item.item_name)}
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="py-3.5 px-3">
                        <span class="cat-badge inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold bg-[#1e1e24] text-[#b4b4c2] border border-[#282834] uppercase">
                            ${escapeHtml(formatUnitLabel(item.unit, 1))}
                        </span>
                    </td>
                    <td class="py-3.5 px-3 text-xs text-[#8e8e9f]">
                        1 ${escapeHtml(formatUnitLabel(item.purchase_unit, 1))} = <span class="font-bold text-[var(--text-main)]">${rate}</span> ${escapeHtml(formatUnitLabel(item.unit, rate))}
                    </td>
                    <td class="py-3.5 px-3 font-semibold">
                        <span class="text-sm font-extrabold ${qtyColor === 'text-white' ? 'text-[var(--text-main)]' : qtyColor}">
                            ${formatNumber(qty)} <span class="text-xs font-normal text-[#8e8e9f]">${escapeHtml(formatUnitLabel(item.unit, qty))}</span>
                        </span>
                    </td>
                    <td class="py-3.5 px-3 font-medium">
                        <span class="threshold-badge px-2.5 py-1 rounded-lg bg-[#1e1e24] border border-[#282834] text-xs font-semibold text-[#d1904b]">
                            ${escapeHtml(breakdown)}
                        </span>
                    </td>
                    <td class="py-3.5 px-3 text-xs">
                        <div class="font-bold text-[var(--text-main)]">$${costBox.toFixed(2)} / ${escapeHtml(formatUnitLabel(item.purchase_unit, 1))}</div>
                        <div class="text-[11px] text-[#8e8e9f] mt-0.5">$${costUnit.toFixed(4)} / ${escapeHtml(formatUnitLabel(item.unit, 1))}</div>
                    </td>
                    <td class="py-3.5 px-3">
                        ${statusBadge}
                    </td>
                    <td class="py-3.5 px-3">
                        <div class="val-main-text text-[var(--text-main)] font-bold text-xs">$${val.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
                    </td>
                    <td class="py-3.5 px-4 text-right">
                        <div class="flex items-center justify-end gap-1.5">
                            <button type="button" 
                                    onclick="openRestockModal(${item.item_id})" 
                                    class="px-2.5 py-1.5 rounded-lg bg-[#d1904b]/15 text-[#d1904b] hover:bg-[#d1904b] hover:text-black font-bold transition-all cursor-pointer border border-[#d1904b]/30" 
                                    title="${escapeHtml(I18N.restock)}">
                                <i class="fa-solid fa-boxes-stacked mr-1"></i> ${escapeHtml(I18N.restock)}
                            </button>
                            <button type="button" 
                                    onclick="openEditStockModal(${item.item_id})" 
                                    class="btn-action-neutral p-1.5 rounded-lg bg-[#1f1f26] text-[#b4b4c2] hover:text-white hover:bg-[#282832] border border-[#2b2b36] transition-all cursor-pointer" 
                                    title="${escapeHtml(I18N.edit)}">
                                <i class="fa-solid fa-pen-to-square w-4 text-center"></i>
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
            const currentRVal = rSelect.value;

            let optHtml = '<option value="">-- Choose Drink --</option>';
            items.forEach(it => {
                optHtml += `<option value="${it.item_id}" 
                    data-unit="${escapeHtml(it.unit)}" 
                    data-punit="${escapeHtml(it.purchase_unit)}"
                    data-rate="${it.conversion_rate}"
                    data-qty="${it.quantity}" 
                    data-boxcost="${it.cost_per_purchase_unit}">
                    ${escapeHtml(it.item_name)} (1 ${escapeHtml(formatUnitLabel(it.purchase_unit, 1))} = ${it.conversion_rate} ${escapeHtml(formatUnitLabel(it.unit, it.conversion_rate))})
                </option>`;
            });

            rSelect.innerHTML = optHtml;
            if (currentRVal) rSelect.value = currentRVal;
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
                const res = await fetch('stock.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const result = await res.json();

                if (result.success) {
                    showToast(result.message, 'success');
                    closeModal('addStockModal');
                    loadStockTable();
                } else {
                    showToast(result.message || 'Failed to add drink.', 'error');
                }
            } catch (err) {
                console.error(err);
                showToast('Server connection error.', 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Save Direct Drink';
            }
        }

        function openRestockModal(itemId = null) {
            document.getElementById('restockForm').reset();
            const select = document.getElementById('restockItemSelect');
            if (itemId) {
                select.value = itemId;
            }
            updateRestockModalPreview();
            openModal('restockModal');
        }

        function updateRestockModalPreview() {
            const select = document.getElementById('restockItemSelect');
            const selectedOpt = select.options[select.selectedIndex];
            const costInput = document.getElementById('restockCostInput');
            const qtyLabel = document.getElementById('lblRestockQty');

            if (!selectedOpt || !selectedOpt.value) {
                document.getElementById('restockBadgeUnits').textContent = '+0 units';
                document.getElementById('restockFormula').textContent = 'Select a drink and enter quantity to see calculation.';
                document.getElementById('restockCurrentStock').textContent = 'Current: --';
                document.getElementById('restockProjectedStock').textContent = 'New Total: --';
                return;
            }

            const punit = selectedOpt.getAttribute('data-punit') || 'box';
            const unit = selectedOpt.getAttribute('data-unit') || 'can';
            const rate = parseFloat(selectedOpt.getAttribute('data-rate')) || 24;
            const currentQty = parseFloat(selectedOpt.getAttribute('data-qty')) || 0;
            const boxCost = parseFloat(selectedOpt.getAttribute('data-boxcost')) || 0;

            qtyLabel.textContent = `${formatUnitLabel(punit, 2)} to Add *`;
            if (!costInput.value && boxCost > 0) {
                costInput.value = boxCost.toFixed(2);
            }

            calculateRestockTotal();
        }

        function calculateRestockTotal() {
            const select = document.getElementById('restockItemSelect');
            const selectedOpt = select.options[select.selectedIndex];
            if (!selectedOpt || !selectedOpt.value) return;

            const punit = selectedOpt.getAttribute('data-punit') || 'box';
            const unit = selectedOpt.getAttribute('data-unit') || 'can';
            const rate = parseFloat(selectedOpt.getAttribute('data-rate')) || 24;
            const currentQty = parseFloat(selectedOpt.getAttribute('data-qty')) || 0;

            const boxesToAdd = parseFloat(document.getElementById('restockQtyInput').value) || 0;
            const addedUnits = boxesToAdd * rate;
            const newTotalUnits = currentQty + addedUnits;

            const unitLabel = formatUnitLabel(unit, addedUnits);
            const punitLabel = formatUnitLabel(punit, boxesToAdd);

            document.getElementById('restockBadgeUnits').textContent = `+${formatNumber(addedUnits)} ${unitLabel}`;
            document.getElementById('restockFormula').textContent = `${formatNumber(boxesToAdd)} ${punitLabel} × ${rate} ${formatUnitLabel(unit, rate)}/${formatUnitLabel(punit, 1)} = +${formatNumber(addedUnits)} ${unitLabel}`;
            document.getElementById('restockCurrentStock').textContent = `Current: ${formatNumber(currentQty)} ${formatUnitLabel(unit, currentQty)}`;
            document.getElementById('restockProjectedStock').textContent = `New Total: ${formatNumber(newTotalUnits)} ${formatUnitLabel(unit, newTotalUnits)}`;
        }

        async function handleQuickRestock(e) {
            e.preventDefault();
            const form = e.target;
            const btn = document.getElementById('restockSubmitBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Saving...';

            const formData = new FormData(form);

            try {
                const res = await fetch('stock.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const result = await res.json();

                if (result.success) {
                    showToast(result.message, 'success');
                    closeModal('restockModal');
                    loadStockTable();
                } else {
                    showToast(result.message || 'Restock failed.', 'error');
                }
            } catch (err) {
                console.error(err);
                showToast('Server connection error.', 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Complete Restock';
            }
        }

        async function openEditStockModal(itemId) {
            try {
                const res = await fetch(`stock.php?action=get_item&item_id=${itemId}`);
                const data = await res.json();

                if (!data.success || !data.item) {
                    showToast('Failed to load drink details.', 'error');
                    return;
                }

                const it = data.item;
                document.getElementById('editItemId').value = it.item_id;
                document.getElementById('editItemName').value = it.item_name;
                document.getElementById('editUnit').value = it.unit;
                document.getElementById('editPurchaseUnit').value = it.purchase_unit;
                document.getElementById('editConversionRate').value = it.conversion_rate;
                document.getElementById('editQuantity').value = it.quantity;
                document.getElementById('editAlertLevel').value = it.alert_level;
                document.getElementById('editCostPurchase').value = parseFloat(it.cost_per_purchase_unit || 0).toFixed(2);
                document.getElementById('editNotes').value = it.notes || '';

                openModal('editStockModal');
            } catch (err) {
                console.error(err);
                showToast('Error loading drink details.', 'error');
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
                const res = await fetch('stock.php', {
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
                    showToast(result.message || 'Failed to update drink.', 'error');
                }
            } catch (err) {
                console.error(err);
                showToast('Server connection error.', 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Update Drink';
            }
        }

        function confirmDeleteItem(itemId, itemName) {
            if (!confirm(`Are you sure you want to archive '${itemName}' from direct drinks stock?`)) return;

            const formData = new FormData();
            formData.append('action', 'delete_item');
            formData.append('item_id', itemId);
            formData.append('csrf_token', CSRF_TOKEN);

            fetch('stock.php', {
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
            container.innerHTML = `<div class="text-center py-8 text-[#8e8e9f]"><i class="fa-solid fa-spinner fa-spin text-2xl text-[#d1904b] mb-2"></i><p>Loading drink audit history...</p></div>`;

            try {
                const res = await fetch('stock.php?action=get_audit_logs');
                const data = await res.json();

                if (!data.success) {
                    container.innerHTML = `<p class="text-rose-400 text-center py-4">Failed to load audit logs.</p>`;
                    return;
                }

                let html = '';

                html += `<div class="space-y-2 mb-6"><h4 class="text-xs font-bold uppercase tracking-wider text-emerald-400 flex items-center gap-1.5"><i class="fa-solid fa-boxes-stacked"></i> ${escapeHtml(I18N.recentRestocks)}</h4>`;
                if (!data.restocks || data.restocks.length === 0) {
                    html += `<p class="text-xs text-[#7d7d8e] italic py-2">${escapeHtml(I18N.noRestocksYet)}</p>`;
                } else {
                    html += `<div class="overflow-x-auto"><table class="w-full text-xs text-left"><thead class="text-[#8e8e9f] border-b border-[#252530]"><tr><th class="py-2 px-3">${escapeHtml(I18N.date)}</th><th class="py-2 px-3">${escapeHtml(I18N.drink)}</th><th class="py-2 px-3">${escapeHtml(I18N.boxesAdded)}</th><th class="py-2 px-3">${escapeHtml(I18N.unitsAdded)}</th><th class="py-2 px-3">${escapeHtml(I18N.supplierNotes)}</th><th class="py-2 px-3">${escapeHtml(I18N.staff)}</th></tr></thead><tbody class="divide-y divide-[#202028]">`;
                    data.restocks.forEach(r => {
                        const boxesAdded = r.boxes_added ? parseFloat(r.boxes_added) : 0;
                        const unitsAdded = parseFloat(r.quantity_added) || 0;
                        const unitName = formatUnitLabel(r.unit || 'can', unitsAdded);
                        const punitName = formatUnitLabel(r.purchase_unit || 'box', boxesAdded);
                        html += `<tr>
                            <td class="py-2 px-3 text-[#8e8e9f]">${escapeHtml(r.created_at)}</td>
                            <td class="py-2 px-3 font-bold text-[var(--text-main)]">${escapeHtml(r.item_name)}</td>
                            <td class="py-2 px-3 text-amber-400 font-extrabold">+${formatNumber(boxesAdded)} ${escapeHtml(punitName)}</td>
                            <td class="py-2 px-3 text-emerald-400 font-extrabold">+${formatNumber(unitsAdded)} ${escapeHtml(unitName)}</td>
                            <td class="py-2 px-3 text-[#b4b4c2]">${escapeHtml(r.supplier || r.notes || '--')}</td>
                            <td class="py-2 px-3 text-[#8e8e9f]">${escapeHtml(r.recorded_by || 'Staff')}</td>
                        </tr>`;
                    });
                    html += `</tbody></table></div>`;
                }
                html += `</div>`;

                if (data.waste && data.waste.length > 0) {
                    html += `<div class="space-y-2"><h4 class="text-xs font-bold uppercase tracking-wider text-rose-400 flex items-center gap-1.5"><i class="fa-solid fa-trash-can-arrow-up"></i> ${escapeHtml(I18N.recentWaste)}</h4>`;
                    html += `<div class="overflow-x-auto"><table class="w-full text-xs text-left"><thead class="text-[#8e8e9f] border-b border-[#252530]"><tr><th class="py-2 px-3">${escapeHtml(I18N.date)}</th><th class="py-2 px-3">${escapeHtml(I18N.drink)}</th><th class="py-2 px-3">${escapeHtml(I18N.wasted)}</th><th class="py-2 px-3">${escapeHtml(I18N.reason)}</th><th class="py-2 px-3">${escapeHtml(I18N.staff)}</th></tr></thead><tbody class="divide-y divide-[#202028]">`;
                    data.waste.forEach(w => {
                        const unitsWasted = parseFloat(w.quantity_wasted) || 0;
                        const unitName = formatUnitLabel(w.unit || 'can', unitsWasted);
                        html += `<tr>
                            <td class="py-2 px-3 text-[#8e8e9f]">${escapeHtml(w.created_at)}</td>
                            <td class="py-2 px-3 font-bold text-[var(--text-main)]">${escapeHtml(w.item_name)}</td>
                            <td class="py-2 px-3 text-rose-400 font-extrabold">-${formatNumber(unitsWasted)} ${escapeHtml(unitName)}</td>
                            <td class="py-2 px-3"><span class="px-2 py-0.5 rounded bg-rose-500/10 text-rose-400 text-[10px] font-bold border border-rose-500/20">${escapeHtml(w.reason)}</span> ${w.notes ? '<span class="text-[#8e8e9f] ml-1">(' + escapeHtml(w.notes) + ')</span>' : ''}</td>
                            <td class="py-2 px-3 text-[#8e8e9f]">${escapeHtml(w.recorded_by || 'Staff')}</td>
                        </tr>`;
                    });
                    html += `</tbody></table></div></div>`;
                }

                container.innerHTML = html;
            } catch (err) {
                console.error(err);
                container.innerHTML = `<p class="text-rose-400 text-center py-4">Failed to load audit logs.</p>`;
            }
        }
    </script>
</body>
</html>
