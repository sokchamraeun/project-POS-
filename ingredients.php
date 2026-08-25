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
$_user_role = strtolower(trim($_SESSION['role'] ?? 'staff'));
if (!in_array($_user_role, ['admin', 'manager', 'staff', 'cashier'], true)) {
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

    // Auto-create settings table if not present on hosting & ensure column is LONGTEXT
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
            `setting_id` INT AUTO_INCREMENT PRIMARY KEY,
            `setting_key` VARCHAR(100) NOT NULL UNIQUE,
            `setting_value` LONGTEXT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("ALTER TABLE `settings` MODIFY COLUMN `setting_value` LONGTEXT NULL");
    } catch (Throwable $t) {}
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
        FROM stock_items 
        WHERE item_type = 'ingredient' 
          AND is_active = 1 
          AND (item_name NOT LIKE '%Packaging Set%' AND item_name NOT LIKE '%ឈុត%')");
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
                'Liquids'           => 'ទឹក',
                'Dairy'             => 'ទឹក',
                'Syrups'            => 'ទឹក',
                'ទឹក'               => 'ទឹក',
                'Beans'             => 'គ្រាប់',
                'គ្រាប់'             => 'គ្រាប់',
                'Packaging'         => 'កែវ & ការវេចខ្ចប់',
                'កែវ & ការវេចខ្ចប់'   => 'កែវ & ការវេចខ្ចប់',
                'កែវ &ការវេចខ្ជប់'   => 'កែវ & ការវេចខ្ចប់',
                'General Supplies'  => 'សម្ភារទូទៅ',
                'Bakery / Toppings' => 'សម្ភារទូទៅ',
                'សម្ភារទូទៅ'         => 'សម្ភារទូទៅ',
                'សម្ភារៈទូទៅ'        => 'សម្ភារទូទៅ'
            ];
            return $map[$category] ?? $category;
        } else {
            $map = [
                'Liquids'           => 'Liquids',
                'Dairy'             => 'Liquids',
                'Syrups'            => 'Liquids',
                'ទឹក'               => 'Liquids',
                'Beans'             => 'Beans',
                'គ្រាប់'             => 'Beans',
                'Packaging'         => 'Cups & Packaging',
                'កែវ & ការវេចខ្ចប់'   => 'Cups & Packaging',
                'កែវ &ការវេចខ្ជប់'   => 'Cups & Packaging',
                'General Supplies'  => 'General Supplies',
                'Bakery / Toppings' => 'General Supplies',
                'សម្ភារទូទៅ'         => 'General Supplies',
                'សម្ភារៈទូទៅ'        => 'General Supplies'
            ];
            return $map[$category] ?? $category;
        }
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
            if (!isset($_SESSION['csrf_token']) || empty($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
            if (!hash_equals($_SESSION['csrf_token'], $token)) {
                // If token mismatched due to session issue on hosting, verify authentication
                if (!isset($_SESSION['user_id']) && !isset($_SESSION['emp_id']) && !isset($_SESSION['username'])) {
                    sendJsonResponse(['success' => false, 'message' => 'Invalid or expired session security token. Please refresh.'], 403);
                }
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
                if ($catFilter === 'Liquids' || $catFilter === 'Dairy' || $catFilter === 'Syrups' || $catFilter === 'ទឹក') {
                    $sql .= " AND (category IN ('Liquids', 'Dairy', 'Syrups', 'ទឹក') OR category LIKE '%ទឹក%')";
                } elseif ($catFilter === 'Beans' || $catFilter === 'គ្រាប់') {
                    $sql .= " AND (category IN ('Beans', 'គ្រាប់') OR category LIKE '%គ្រាប់%')";
                } elseif ($catFilter === 'Packaging' || $catFilter === 'កែវ & ការវេចខ្ចប់' || $catFilter === 'កែវ &ការវេចខ្ចប់' || $catFilter === 'កែវ &ការវេចខ្ជប់') {
                    $sql .= " AND (category IN ('Packaging', 'កែវ & ការវេចខ្ចប់', 'កែវ &ការវេចខ្ចប់', 'កែវ &ការវេចខ្ជប់') OR category LIKE '%កែវ%' OR category LIKE '%វេចខ្ចប់%')";
                } elseif ($catFilter === 'General Supplies' || $catFilter === 'Bakery / Toppings' || $catFilter === 'សម្ភារទូទៅ' || $catFilter === 'សម្ភារៈទូទៅ') {
                    $sql .= " AND (category IN ('General Supplies', 'Bakery / Toppings', 'សម្ភារទូទៅ', 'សម្ភារៈទូទៅ') OR category LIKE '%សម្ភារ%')";
                } else {
                    $sql .= " AND category = ?";
                    $params[] = $catFilter;
                }
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

            // Duplicate Name Check
            $chk = $pdo->prepare("SELECT item_id, item_name, quantity, unit FROM stock_items WHERE item_type = 'ingredient' AND LOWER(TRIM(item_name)) = LOWER(?) AND is_active = 1 LIMIT 1");
            $chk->execute([$name]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                sendJsonResponse([
                    'success' => false, 
                    'message' => "An ingredient named '{$name}' already exists in stock (Current Stock: " . ((float)$existing['quantity']) . " {$existing['unit']}). Please use Restock or edit the existing ingredient."
                ], 422);
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

            // Duplicate Name Check on Edit
            $chk = $pdo->prepare("SELECT item_id FROM stock_items WHERE item_type = 'ingredient' AND LOWER(TRIM(item_name)) = LOWER(?) AND item_id != ? AND is_active = 1 LIMIT 1");
            $chk->execute([$name, $itemId]);
            if ($chk->fetch()) {
                sendJsonResponse([
                    'success' => false, 
                    'message' => "Another ingredient named '{$name}' already exists."
                ], 422);
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

            // Guard: Prevent deleting auto packaging set
            $chkPkg = $pdo->prepare("SELECT item_name FROM stock_items WHERE item_id = ?");
            $chkPkg->execute([$itemId]);
            $itemRow = $chkPkg->fetch();
            if ($itemRow && (str_contains(strtolower($itemRow['item_name']), 'packaging set') || str_contains($itemRow['item_name'], 'ឈុត'))) {
                sendJsonResponse(['success' => false, 'message' => 'This is an automatic Packaging Set. It cannot be deleted.'], 403);
            }

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

        // 8b. Fetch Detailed Ingredient Deduction & Movement History
        if ($action === 'get_ingredient_history') {
            $itemId = (int)($_GET['item_id'] ?? 0);
            if ($itemId <= 0) {
                sendJsonResponse(['success' => false, 'message' => 'Invalid ingredient ID.'], 400);
            }

            // Ensure stock_logs table exists
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `stock_logs` (
                    `log_id` INT AUTO_INCREMENT PRIMARY KEY,
                    `item_id` INT NOT NULL,
                    `order_id` INT DEFAULT NULL,
                    `product_id` INT DEFAULT NULL,
                    `change_type` VARCHAR(50) NOT NULL DEFAULT 'sale_deduct',
                    `quantity_changed` DECIMAL(12,4) NOT NULL,
                    `stock_before` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
                    `stock_after` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
                    `cost_at_time` DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
                    `notes` TEXT DEFAULT NULL,
                    `created_by` VARCHAR(100) DEFAULT NULL,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX (`item_id`),
                    INDEX (`order_id`),
                    INDEX (`product_id`),
                    INDEX (`change_type`),
                    INDEX (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } catch (Throwable $t) {}

            $ingStmt = $pdo->prepare("SELECT * FROM stock_items WHERE item_id = ? AND is_active = 1 LIMIT 1");
            $ingStmt->execute([$itemId]);
            $ing = $ingStmt->fetch();

            if (!$ing) {
                sendJsonResponse(['success' => false, 'message' => 'Ingredient not found.'], 404);
            }

            $logStmt = $pdo->prepare("
                SELECT 
                    l.log_id,
                    l.item_id,
                    l.order_id,
                    l.product_id,
                    l.change_type,
                    l.quantity_changed,
                    l.stock_before,
                    l.stock_after,
                    l.cost_at_time,
                    l.notes,
                    l.created_by,
                    l.created_at,
                    p.name AS product_name,
                    p.image AS product_image,
                    o.total AS order_total,
                    o.order_date
                FROM stock_logs l
                LEFT JOIN products p ON l.product_id = p.product_id
                LEFT JOIN orders o ON l.order_id = o.order_id
                WHERE l.item_id = ?
                ORDER BY l.created_at DESC, l.log_id DESC
                LIMIT 150
            ");
            $logStmt->execute([$itemId]);
            $logs = $logStmt->fetchAll();

            $totalDeducted = 0.0;
            $totalRestocked = 0.0;
            $orderCount = 0;

            foreach ($logs as $lg) {
                $chg = (float)$lg['quantity_changed'];
                if ($chg < 0) {
                    $totalDeducted += abs($chg);
                    if (!empty($lg['order_id'])) $orderCount++;
                } else {
                    $totalRestocked += $chg;
                }
            }

            sendJsonResponse([
                'success'         => true,
                'ingredient'      => $ing,
                'logs'            => $logs,
                'total_deducted'  => $totalDeducted,
                'total_restocked' => $totalRestocked,
                'total_orders'    => $orderCount
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

        // 10. Get Packaging Set Config
        if ($action === 'get_packaging_set') {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
                    `setting_id` INT AUTO_INCREMENT PRIMARY KEY,
                    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
                    `setting_value` TEXT NULL,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('packaging_cost_per_set', 'packaging_set_config')");
                $res = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

                $costPerSet = isset($res['packaging_cost_per_set']) ? (float)$res['packaging_cost_per_set'] : 0.0920;
                $rawConfig = $res['packaging_set_config'] ?? '';
                $items = !empty($rawConfig) ? json_decode($rawConfig, true) : null;

                // Default fallback set if none saved yet
                if (empty($items) || !is_array($items)) {
                    $items = [
                        ['name' => 'កែវ (Plastic / Paper Cup)', 'cost' => 0.0450, 'qty' => 1],
                        ['name' => 'គម្របកែវ (Cup Lid)', 'cost' => 0.0180, 'qty' => 1],
                        ['name' => 'បំពង់បឺត (Straw)', 'cost' => 0.0080, 'qty' => 1],
                        ['name' => 'ស្រោមដៃកែវ / ថង់យួរ (Sleeve / Carrier)', 'cost' => 0.0150, 'qty' => 1],
                        ['name' => 'ស្ទីគ័រ / ក្រដាសជូត (Logo Sticker / Napkin)', 'cost' => 0.0060, 'qty' => 1],
                    ];
                }

                // Available inventory packaging items
                $inventoryPkg = [];
                try {
                    $pkgStmt = $pdo->query("SELECT item_id, item_name, unit, cost_per_unit FROM stock_items WHERE item_type = 'ingredient' AND (category IN ('Packaging', 'កែវ & ការវេចខ្ចប់', 'កែវ &ការវេចខ្ជប់') OR unit = 'pcs') AND is_active = 1 ORDER BY item_name ASC");
                    $inventoryPkg = $pkgStmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $eInv) {}

                sendJsonResponse([
                    'success' => true,
                    'cost_per_set' => $costPerSet,
                    'items' => $items,
                    'inventory_packaging' => $inventoryPkg
                ]);
            } catch (Throwable $e) {
                sendJsonResponse([
                    'success' => true,
                    'cost_per_set' => 0.0920,
                    'items' => [
                        ['name' => 'កែវ (Plastic / Paper Cup)', 'cost' => 0.0450, 'qty' => 1],
                        ['name' => 'គម្របកែវ (Cup Lid)', 'cost' => 0.0180, 'qty' => 1],
                        ['name' => 'បំពង់បឺត (Straw)', 'cost' => 0.0080, 'qty' => 1],
                        ['name' => 'ស្រោមដៃកែវ / ថង់យួរ (Sleeve / Carrier)', 'cost' => 0.0150, 'qty' => 1],
                        ['name' => 'ស្ទីគ័រ / ក្រដាសជូត (Logo Sticker / Napkin)', 'cost' => 0.0060, 'qty' => 1],
                    ],
                    'inventory_packaging' => []
                ]);
            }
        }

        // 11. Save Packaging Set Config
        if ($action === 'save_packaging_set') {
            try {
                $rawItems = $_POST['items'] ?? '';
                $items = is_array($rawItems) ? $rawItems : json_decode($rawItems, true);

                if (!is_array($items) || empty($items)) {
                    sendJsonResponse(['success' => false, 'message' => 'Please provide at least one packaging item.'], 422);
                }

                $totalCost = 0.0;
                $cleanItems = [];
                foreach ($items as $it) {
                    $name = trim($it['name'] ?? '');
                    $cost = max(0.0, (float)($it['cost'] ?? 0));
                    $qty  = max(0.0, (float)($it['qty'] ?? 1));
                    if (!empty($name)) {
                        $sub = $cost * $qty;
                        $totalCost += $sub;
                        $cleanItems[] = [
                            'name' => $name,
                            'cost' => $cost,
                            'qty'  => $qty,
                            'subtotal' => $sub
                        ];
                    }
                }

                $jsonConfig = json_encode($cleanItems, JSON_UNESCAPED_UNICODE);

                // Auto-create settings table if not present on hosting & upgrade column to LONGTEXT
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
                        `setting_id` INT AUTO_INCREMENT PRIMARY KEY,
                        `setting_key` VARCHAR(100) NOT NULL UNIQUE,
                        `setting_value` LONGTEXT NULL,
                        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    $pdo->exec("ALTER TABLE `settings` MODIFY COLUMN `setting_value` LONGTEXT NULL");
                } catch (Throwable $t) {}

                $stmt1 = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('packaging_cost_per_set', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $stmt1->execute([(string)$totalCost]);

                $stmt2 = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('packaging_set_config', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $stmt2->execute([$jsonConfig]);

                // Sync or insert into stock_items safely so differences in table schemas won't break saving
                try {
                    $chkPkg = $pdo->query("SELECT item_id FROM stock_items WHERE (item_name = 'ឈុតកែវ & ការវេចខ្ចប់ (Packaging Set)' OR item_name LIKE '%Packaging Set%') AND is_active = 1 LIMIT 1")->fetch();
                    if ($chkPkg) {
                        $updPkg = $pdo->prepare("UPDATE stock_items SET cost_per_unit = ?, quantity = 0, alert_level = 0, unit = 'pcs', category = 'Packaging' WHERE item_id = ?");
                        $updPkg->execute([$totalCost, $chkPkg['item_id']]);
                    } else {
                        $insPkg = $pdo->prepare("INSERT INTO stock_items (item_name, category, item_type, quantity, unit, alert_level, cost_per_unit, notes, is_active) VALUES ('ឈុតកែវ & ការវេចខ្ចប់ (Packaging Set)', 'Packaging', 'ingredient', 0, 'pcs', 0, ?, 'Default packaging set per drink', 1)");
                        $insPkg->execute([$totalCost]);
                    }
                } catch (Throwable $eStock) {
                    error_log("Stock item sync notice: " . $eStock->getMessage());
                }

                $khrCost = $totalCost * (defined('KHR_RATE') ? KHR_RATE : 4000);
                $khrFormatted = $khrCost >= 10 ? number_format(round($khrCost)) : ($khrCost > 0 ? number_format($khrCost, 1) : '0');

                sendJsonResponse([
                    'success' => true,
                    'message' => __('packaging_saved_success', 'Packaging set cost saved successfully!'),
                    'total_cost' => $totalCost,
                    'total_khr' => $khrFormatted,
                    'items' => $cleanItems
                ]);
            } catch (Throwable $e) {
                sendJsonResponse(['success' => false, 'message' => 'Database error on hosting: ' . $e->getMessage()], 500);
            }
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
<html lang="<?= current_lang() ?>" data-lang="<?= current_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <title>Raw Ingredients & Recipe Supplies | Bird's Nest Coffee</title>

    <!-- Google Fonts: Poppins & Kantumruy Pro (Khmer) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap" rel="stylesheet">

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
        @import url('https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap');

        :root {
            --bg: #0e0e10;
            --surface: #121215;
            --surface-card: #18181c;
            --surface-hover: #202028;
            --border: #24242b;
            --border-subtle: #1c1c22;
            --accent: #10b981;
            --accent-hover: #059669;
            --accent-glow: rgba(16, 185, 129, 0.25);
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
            --accent: #10b981;
            --accent-hover: #059669;
            --accent-glow: rgba(16, 185, 129, 0.18);
            --text-main: #111827;
            --text-muted: #64748b;
        }

        body, input, select, textarea, button, .modal-content, .glass-card, table {
            font-family: 'Poppins', 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        body {
            background-color: var(--bg);
            color: var(--text-main);
            margin: 0;
            padding: 0;
            overflow: hidden;
        }

        :lang(km), [data-lang="km"], html[lang="km"], html[lang="km"] * {
            font-family: 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', 'Khmer OS Siemreap', 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
        }
        html[lang="km"] .fa, html[lang="km"] [class*="fa-"], html[lang="km"] i {
            font-family: 'Font Awesome 6 Free', 'FontAwesome' !important;
        }
        html[lang="km"] .fa-brands, html[lang="km"] [class*="fa-brands"] {
            font-family: 'Font Awesome 6 Brands', 'FontAwesome' !important;
        }

        .glass-card {
            background: var(--surface-card);
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.15);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .glass-card:hover {
            border-color: rgba(16, 185, 129, 0.35);
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

        /* ── Sticky Table Header ── */
        .ingredient-table-scroll-container {
            overflow-x: auto;
            overflow-y: auto;
            max-height: calc(100vh - 350px);
            min-height: 360px;
            position: relative;
        }
        .ingredient-table-scroll-container table {
            border-collapse: separate !important;
            border-spacing: 0 !important;
        }
        .ingredient-table-scroll-container thead {
            position: sticky !important;
            top: 0 !important;
            z-index: 25 !important;
        }
        .ingredient-table-scroll-container thead tr {
            position: sticky !important;
            top: 0 !important;
            z-index: 25 !important;
        }
        .ingredient-table-scroll-container thead th {
            position: sticky !important;
            top: 0 !important;
            z-index: 25 !important;
            background-color: #141418 !important;
            border-bottom: 1px solid #24242b !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2) !important;
        }
        [data-theme="light"] .ingredient-table-scroll-container thead th,
        html[data-theme="light"] .ingredient-table-scroll-container thead th {
            background-color: #f1f5f9 !important;
            border-bottom: 1px solid #e2e4ea !important;
            color: #475569 !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.04) !important;
        }

        /* ── Pricing Calculator Box ── */
        .pricing-calc-box {
            background-color: #141418;
            border: 1px solid #282834;
        }
        .pricing-header-title { color: #10b981; }
        .pricing-header-sub { color: #8e8e9f; }
        .pricing-calc-input {
            background-color: #18181f;
            border: 1px solid #282834;
            color: var(--text-main, #ffffff);
        }
        .pricing-calc-input-highlight {
            border-color: rgba(16, 185, 129, 0.45);
            color: #ffffff;
        }
        .pricing-dollar-icon { color: #10b981; }
        .pricing-dollar-icon-muted { color: #8e8e9f; }
        .pricing-calc-preview {
            border-top: 1px solid #23232c;
            color: #8e8e9f;
        }
        .pricing-calc-preview strong { color: #34d399; }
        .pricing-calc-preview .formula-text { color: #727282; }

        /* Light Mode Styling for Pricing Calculator Box */
        [data-theme="light"] .pricing-calc-box,
        html[data-theme="light"] .pricing-calc-box {
            background-color: #f0fdf4 !important;
            border: 1px solid #bbf7d0 !important;
            box-shadow: 0 1px 4px rgba(16, 185, 129, 0.08) !important;
        }
        [data-theme="light"] .pricing-header-title,
        html[data-theme="light"] .pricing-header-title {
            color: #047857 !important;
        }
        [data-theme="light"] .pricing-header-sub,
        html[data-theme="light"] .pricing-header-sub {
            color: #64748b !important;
        }
        [data-theme="light"] .pricing-calc-box .modal-label,
        html[data-theme="light"] .pricing-calc-box .modal-label {
            color: #334155 !important;
        }
        [data-theme="light"] .pricing-calc-input,
        html[data-theme="light"] .pricing-calc-input {
            background-color: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            color: #0f172a !important;
        }
        [data-theme="light"] .pricing-calc-input-highlight,
        html[data-theme="light"] .pricing-calc-input-highlight {
            background-color: #ffffff !important;
            border: 1.5px solid #10b981 !important;
            color: #0f172a !important;
            box-shadow: 0 0 0 1px rgba(16, 185, 129, 0.2) !important;
        }
        [data-theme="light"] .pricing-dollar-icon,
        html[data-theme="light"] .pricing-dollar-icon {
            color: #10b981 !important;
        }
        [data-theme="light"] .pricing-dollar-icon-muted,
        html[data-theme="light"] .pricing-dollar-icon-muted {
            color: #64748b !important;
        }
        [data-theme="light"] .pricing-calc-preview,
        html[data-theme="light"] .pricing-calc-preview {
            border-top: 1px solid #ffedd5 !important;
            color: #475569 !important;
        }
        [data-theme="light"] .pricing-calc-preview strong,
        html[data-theme="light"] .pricing-calc-preview strong {
            color: #059669 !important;
        }
        [data-theme="light"] .pricing-calc-preview .formula-text,
        html[data-theme="light"] .pricing-calc-preview .formula-text {
            color: #64748b !important;
        }

        /* ══════════════════════════════════════════════════════════════
           STOCK-IN MASTER MODAL ENHANCED STYLING (DARK & LIGHT MODES)
        ══════════════════════════════════════════════════════════════ */
        select optgroup {
            background-color: #1e293b;
            color: #34d399;
            font-weight: 700;
        }
        select option {
            background-color: #0f172a;
            color: #f8fafc;
        }
        [data-theme="light"] select optgroup,
        html[data-theme="light"] select optgroup {
            background-color: #f0fdf4 !important;
            color: #047857 !important;
        }
        [data-theme="light"] select option,
        html[data-theme="light"] select option {
            background-color: #ffffff !important;
            color: #1a1410 !important;
        }
        
        #addStockModal .modal-content {
            background-color: #141419;
            border-color: #2b2b36;
            color: #ffffff;
        }
        #addStockModal .modal-header {
            border-color: #252530;
        }
        #addStockModal .modal-footer {
            border-color: #252530;
        }
        #addStockModal .stockin-title {
            color: #ffffff;
        }
        #addStockModal .stockin-sub {
            color: #8e8e9f;
        }
        #addStockModal .stockin-badge {
            background: rgba(16, 185, 129, 0.12);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        #addStockModal .stockin-label {
            color: #cbd5e1;
            font-size: 12px;
            font-weight: 600;
        }
        #addStockModal .stockin-input {
            background-color: #1a1a22;
            border: 1.5px solid #2d2d3b;
            color: #ffffff;
            font-weight: 500;
            transition: all 0.18s ease;
        }
        #addStockModal .stockin-input:focus {
            background-color: #1e1e28;
            border-color: #10b981 !important;
            outline: none;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
        }
        #addStockModal .stockin-density-box {
            background-color: rgba(30, 41, 59, 0.4);
            border: 1px solid rgba(51, 65, 85, 0.6);
            color: #cbd5e1;
        }
        #addStockModal .stockin-conv-box {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(16, 185, 129, 0.03) 100%);
            border: 1.5px solid rgba(16, 185, 129, 0.25);
        }
        #addStockModal .stockin-conv-title {
            color: #34d399;
        }
        #addStockModal .stockin-conv-formula {
            color: #10b981;
        }
        #addStockModal .stockin-stat-card {
            background-color: #1a1a22;
            border: 1px solid #2d2d3b;
        }
        #addStockModal .stockin-stat-label {
            color: #8e8e9f;
        }
        #addStockModal .stockin-stat-val-units,
        #addStockModal .stockin-stat-val-ml,
        #addStockModal .stockin-stat-val-g,
        #addStockModal .stockin-stat-val-cost {
            color: #ffffff;
        }
        #addStockModal .btn-stockin-cancel {
            background-color: #202028;
            border: 1px solid #2d2d3b;
            color: #cbd5e1;
        }
        #addStockModal .btn-stockin-cancel:hover {
            background-color: #2b2b36;
            color: #ffffff;
        }
        #addStockModal .btn-stockin-submit {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            font-weight: 800;
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35);
        }
        #addStockModal .btn-stockin-submit:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(16, 185, 129, 0.45);
        }

        /* ── LIGHT MODE OVERRIDES ── */
        [data-theme="light"] #addStockModal .modal-content,
        html[data-theme="light"] #addStockModal .modal-content {
            background-color: #ffffff !important;
            border-color: #e2e8f0 !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25) !important;
            color: #0f172a !important;
        }
        [data-theme="light"] #addStockModal .modal-header,
        html[data-theme="light"] #addStockModal .modal-header,
        [data-theme="light"] #addStockModal .modal-footer,
        html[data-theme="light"] #addStockModal .modal-footer {
            border-color: #e2e8f0 !important;
        }
        [data-theme="light"] #addStockModal .stockin-icon-wrap,
        html[data-theme="light"] #addStockModal .stockin-icon-wrap {
            background-color: #dcfce7 !important;
            border-color: #bbf7d0 !important;
            color: #047857 !important;
        }
        [data-theme="light"] #addStockModal .stockin-title,
        html[data-theme="light"] #addStockModal .stockin-title {
            color: #065f46 !important;
        }
        [data-theme="light"] #addStockModal .stockin-sub,
        html[data-theme="light"] #addStockModal .stockin-sub {
            color: #64748b !important;
        }
        [data-theme="light"] #addStockModal .stockin-badge,
        html[data-theme="light"] #addStockModal .stockin-badge {
            background-color: #dcfce7 !important;
            color: #047857 !important;
            border-color: #bbf7d0 !important;
        }
        [data-theme="light"] #addStockModal .stockin-label,
        html[data-theme="light"] #addStockModal .stockin-label {
            color: #334155 !important;
            font-weight: 700 !important;
        }
        [data-theme="light"] #addStockModal .stockin-input,
        html[data-theme="light"] #addStockModal .stockin-input {
            background-color: #f8fafc !important;
            border-color: #cbd5e1 !important;
            color: #0f172a !important;
        }
        [data-theme="light"] #addStockModal .stockin-input:focus,
        html[data-theme="light"] #addStockModal .stockin-input:focus {
            background-color: #ffffff !important;
            border-color: #059669 !important;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.18) !important;
        }
        [data-theme="light"] #addStockModal .stockin-input::placeholder,
        html[data-theme="light"] #addStockModal .stockin-input::placeholder {
            color: #94a3b8 !important;
        }
        [data-theme="light"] #addStockModal select#packageType,
        html[data-theme="light"] #addStockModal select#packageType {
            color: #047857 !important;
            font-weight: 700 !important;
        }
        [data-theme="light"] #addStockModal select optgroup,
        html[data-theme="light"] #addStockModal select optgroup {
            background-color: #f8fafc !important;
            color: #047857 !important;
        }
        [data-theme="light"] #addStockModal select option,
        html[data-theme="light"] #addStockModal select option {
            background-color: #ffffff !important;
            color: #1e293b !important;
        }
        [data-theme="light"] #addStockModal #subUnitConfig,
        html[data-theme="light"] #addStockModal #subUnitConfig {
            background-color: #f8fafc !important;
            border-color: #e2e8f0 !important;
        }
        [data-theme="light"] #addStockModal #subUnitMultiplier,
        html[data-theme="light"] #addStockModal #subUnitMultiplier {
            background-color: #ffffff !important;
            border-color: #cbd5e1 !important;
            color: #0f172a !important;
        }
        [data-theme="light"] #addStockModal #subUnitHint,
        html[data-theme="light"] #addStockModal #subUnitHint {
            color: #64748b !important;
        }
        [data-theme="light"] #addStockModal .stockin-density-box,
        html[data-theme="light"] #addStockModal .stockin-density-box {
            background-color: #f8fafc !important;
            border-color: #e2e8f0 !important;
            color: #475569 !important;
        }
        [data-theme="light"] #addStockModal .stockin-density-box #baseUnitDisplay,
        html[data-theme="light"] #addStockModal .stockin-density-box #baseUnitDisplay {
            background-color: #e0f2fe !important;
            color: #0369a1 !important;
            border-color: #bae6fd !important;
        }
        [data-theme="light"] #addStockModal .stockin-density-box span.font-semibold,
        html[data-theme="light"] #addStockModal .stockin-density-box span.font-semibold {
            color: #047857 !important;
        }
        [data-theme="light"] #addStockModal .stockin-conv-box,
        html[data-theme="light"] #addStockModal .stockin-conv-box {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%) !important;
            border-color: #86efac !important;
        }
        [data-theme="light"] #addStockModal .stockin-conv-title,
        html[data-theme="light"] #addStockModal .stockin-conv-title {
            color: #065f46 !important;
        }
        [data-theme="light"] #addStockModal .stockin-conv-formula,
        html[data-theme="light"] #addStockModal .stockin-conv-formula {
            color: #047857 !important;
        }
        [data-theme="light"] #addStockModal .stockin-stat-card,
        html[data-theme="light"] #addStockModal .stockin-stat-card {
            background-color: #ffffff !important;
            border-color: #bbf7d0 !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05) !important;
        }
        [data-theme="light"] #addStockModal .stockin-stat-label,
        html[data-theme="light"] #addStockModal .stockin-stat-label {
            color: #64748b !important;
        }
        [data-theme="light"] #addStockModal .stockin-stat-val-units,
        html[data-theme="light"] #addStockModal .stockin-stat-val-units,
        [data-theme="light"] #addStockModal .stockin-stat-val-ml,
        html[data-theme="light"] #addStockModal .stockin-stat-val-ml,
        [data-theme="light"] #addStockModal .stockin-stat-val-g,
        html[data-theme="light"] #addStockModal .stockin-stat-val-g,
        [data-theme="light"] #addStockModal .stockin-stat-val-cost,
        html[data-theme="light"] #addStockModal .stockin-stat-val-cost,
        [data-theme="light"] #addStockModal .stockin-stat-val-cost span,
        html[data-theme="light"] #addStockModal .stockin-stat-val-cost span {
            color: #0f172a !important;
        }
        [data-theme="light"] #addStockModal .btn-stockin-cancel,
        html[data-theme="light"] #addStockModal .btn-stockin-cancel {
            background-color: #f1f5f9 !important;
            border-color: #cbd5e1 !important;
            color: #334155 !important;
        }
        [data-theme="light"] #addStockModal .btn-stockin-cancel:hover,
        html[data-theme="light"] #addStockModal .btn-stockin-cancel:hover {
            background-color: #e2e8f0 !important;
            color: #0f172a !important;
        }
        [data-theme="light"] #addStockModal .btn-stockin-submit,
        html[data-theme="light"] #addStockModal .btn-stockin-submit {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
            color: #ffffff !important;
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.3) !important;
        }

        /* ══════════════════════════════════════════════════════════════
           RESTOCK MODAL ENHANCED STYLING (DARK & LIGHT MODES)
        ══════════════════════════════════════════════════════════════ */
        #restockModal .modal-content {
            background-color: #141419;
            border-color: #2b2b36;
            color: #ffffff;
            overflow: hidden !important;
        }
        #restockModal form {
            overflow-x: hidden !important;
            overflow-y: auto !important;
            scrollbar-width: thin;
        }
        #restockModal .modal-header,
        #restockModal .modal-footer {
            border-color: #252530;
        }
        #restockModal .restock-title {
            color: #10b981;
        }
        #restockModal .restock-label {
            color: #cbd5e1;
            font-size: 12px;
            font-weight: 700;
        }
        #restockModal .restock-input {
            background-color: #1a1a22;
            border: 1.5px solid #2d2d3b;
            color: #ffffff;
            font-weight: 600;
            transition: all 0.18s ease;
        }
        #restockModal .restock-input:focus {
            background-color: #1e1e28;
            border-color: #10b981 !important;
            outline: none;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
        }
        #restockModal .restock-subunit-box {
            background: rgba(16, 185, 129, 0.06);
            border: 1.5px dashed rgba(16, 185, 129, 0.35);
        }
        #restockModal .restock-subunit-hint {
            color: #94a3b8;
        }
        #restockModal .restock-preview-box {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.12) 0%, rgba(16, 185, 129, 0.04) 100%);
            border: 1.5px solid rgba(16, 185, 129, 0.3);
        }
        #restockModal .restock-preview-label {
            color: #94a3b8;
            font-size: 11px;
            font-weight: 600;
        }
        #restockModal .restock-curr-toggle-wrap {
            background-color: #1a1a22;
            border: 1px solid #2d2d3b;
        }

        /* Light mode for restock modal */
        [data-theme="light"] #restockModal .modal-content,
        html[data-theme="light"] #restockModal .modal-content {
            background-color: #ffffff !important;
            border-color: #e2e8f0 !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25) !important;
            color: #0f172a !important;
        }
        [data-theme="light"] #restockModal .modal-header,
        html[data-theme="light"] #restockModal .modal-header,
        [data-theme="light"] #restockModal .modal-footer,
        html[data-theme="light"] #restockModal .modal-footer {
            border-color: #e2e8f0 !important;
        }
        [data-theme="light"] #restockModal .restock-title,
        html[data-theme="light"] #restockModal .restock-title {
            color: #059669 !important;
        }
        [data-theme="light"] #restockModal .restock-label,
        html[data-theme="light"] #restockModal .restock-label {
            color: #1e293b !important;
            font-weight: 700 !important;
        }
        [data-theme="light"] #restockModal .restock-input,
        html[data-theme="light"] #restockModal .restock-input {
            background-color: #ffffff !important;
            border-color: #cbd5e1 !important;
            color: #0f172a !important;
        }
        [data-theme="light"] #restockModal .restock-input:focus,
        html[data-theme="light"] #restockModal .restock-input:focus {
            background-color: #ffffff !important;
            border-color: #059669 !important;
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.18) !important;
        }
        [data-theme="light"] #restockModal .restock-subunit-box,
        html[data-theme="light"] #restockModal .restock-subunit-box {
            background-color: #f8fafc !important;
            border: 1.5px dashed #cbd5e1 !important;
        }
        [data-theme="light"] #restockModal .restock-subunit-hint,
        html[data-theme="light"] #restockModal .restock-subunit-hint {
            color: #475569 !important;
            font-weight: 600 !important;
        }
        [data-theme="light"] #restockModal .restock-curr-toggle-wrap,
        html[data-theme="light"] #restockModal .restock-curr-toggle-wrap {
            background-color: #f1f5f9 !important;
            border-color: #cbd5e1 !important;
        }
        [data-theme="light"] #restockModal .restock-preview-box,
        html[data-theme="light"] #restockModal .restock-preview-box {
            background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%) !important;
            border: 1.5px solid #86efac !important;
        }
        [data-theme="light"] #restockModal .restock-preview-label,
        html[data-theme="light"] #restockModal .restock-preview-label {
            color: #1e293b !important;
            font-weight: 700 !important;
        }
        [data-theme="light"] #restockModal #restockAddedBaseUnits,
        html[data-theme="light"] #restockModal #restockAddedBaseUnits {
            color: #15803d !important;
            font-weight: 900 !important;
        }
        [data-theme="light"] #restockModal #restockCurrentQty,
        html[data-theme="light"] #restockModal #restockCurrentQty {
            color: #0f172a !important;
            font-weight: 800 !important;
        }
        [data-theme="light"] #restockModal #restockNewQty,
        html[data-theme="light"] #restockModal #restockNewQty {
            color: #15803d !important;
            font-weight: 900 !important;
        }
        [data-theme="light"] #restockModal #restockNewUnitCost,
        html[data-theme="light"] #restockModal #restockNewUnitCost {
            color: #b45309 !important;
            font-weight: 900 !important;
        }

        /* Custom Slim Scrollbar for Modal */
        .custom-modal-scroll::-webkit-scrollbar {
            width: 6px;
        }
        .custom-modal-scroll::-webkit-scrollbar-track {
            background: transparent;
        }
        .custom-modal-scroll::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.3);
            border-radius: 9999px;
        }
        .custom-modal-scroll::-webkit-scrollbar-thumb:hover {
            background: rgba(148, 163, 184, 0.5);
        }

        /* ══════════════════════════════════════════════════════════════
           PACKAGING SET COST MODAL STYLES (LIGHT & DARK THEMES)
        ══════════════════════════════════════════════════════════════ */
        .pkg-table-wrap {
            background-color: #141418;
            border: 1px solid #282834;
        }
        .pkg-thead {
            background-color: #1b1b22;
            color: #8e8e9f;
            border-bottom: 1px solid #282834;
        }
        .pkg-row {
            border-bottom: 1px solid #202028;
            transition: background-color 0.15s ease;
        }
        .pkg-row:last-child {
            border-bottom: none;
        }
        .pkg-row:hover {
            background-color: rgba(255, 255, 255, 0.03);
        }
        .pkg-input {
            background-color: #141418;
            border: 1px solid #282834;
            color: var(--text-main, #ffffff);
            transition: all 0.15s ease;
        }
        .pkg-input:focus {
            border-color: #10b981 !important;
            outline: none;
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2);
        }
        .pkg-summary-box {
            background: linear-gradient(135deg, #15151a 0%, #191921 100%);
            border: 1.5px solid rgba(16, 185, 129, 0.35);
        }
        .pkg-kpi-card {
            background-color: #141418;
            border: 1px solid #282834;
        }
        .btn-pkg-add {
            background-color: #24242e;
            border: 1px solid #343444;
            color: #10b981;
        }
        .btn-pkg-add:hover {
            background-color: #2e2e3a;
            color: #34d399;
        }
        .btn-pkg-cancel {
            background-color: #202026;
            border: 1px solid #2f2f3c;
            color: #e2e8f0;
        }
        .btn-pkg-cancel:hover {
            background-color: #2b2b36;
        }

        /* Light Mode Styling for Packaging Modal */
        [data-theme="light"] #packagingCostModal .modal-content,
        html[data-theme="light"] #packagingCostModal .modal-content {
            background-color: #ffffff !important;
            border-color: #e2e8f0 !important;
            color: #111827 !important;
        }
        [data-theme="light"] #packagingCostModal .modal-header,
        html[data-theme="light"] #packagingCostModal .modal-header,
        [data-theme="light"] #packagingCostModal .modal-footer,
        html[data-theme="light"] #packagingCostModal .modal-footer {
            border-color: #e2e8f0 !important;
        }
        [data-theme="light"] #packagingCostModal .pkg-table-wrap,
        html[data-theme="light"] #packagingCostModal .pkg-table-wrap {
            background-color: #ffffff !important;
            border-color: #e2e8f0 !important;
        }
        [data-theme="light"] #packagingCostModal .pkg-thead,
        html[data-theme="light"] #packagingCostModal .pkg-thead {
            background-color: #f8fafc !important;
            color: #475569 !important;
            border-bottom-color: #e2e8f0 !important;
        }
        [data-theme="light"] #packagingCostModal .pkg-row,
        html[data-theme="light"] #packagingCostModal .pkg-row {
            border-bottom-color: #f1f5f9 !important;
        }
        [data-theme="light"] #packagingCostModal .pkg-row:hover,
        html[data-theme="light"] #packagingCostModal .pkg-row:hover {
            background-color: #f8fafc !important;
        }
        [data-theme="light"] #packagingCostModal .pkg-input,
        html[data-theme="light"] #packagingCostModal .pkg-input {
            background-color: #ffffff !important;
            border-color: #cbd5e1 !important;
            color: #0f172a !important;
        }
        [data-theme="light"] #packagingCostModal .pkg-input:focus,
        html[data-theme="light"] #packagingCostModal .pkg-input:focus {
            border-color: #10b981 !important;
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2) !important;
        }
        [data-theme="light"] #packagingCostModal .pkg-summary-box,
        html[data-theme="light"] #packagingCostModal .pkg-summary-box {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%) !important;
            border: 1.5px solid #86efac !important;
            box-shadow: 0 4px 16px rgba(16, 185, 129, 0.08) !important;
        }
        [data-theme="light"] #packagingCostModal .pkg-kpi-card,
        html[data-theme="light"] #packagingCostModal .pkg-kpi-card {
            background-color: #ffffff !important;
            border: 1px solid #bbf7d0 !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03) !important;
        }
        [data-theme="light"] #packagingCostModal .pkg-kpi-card .card-kpi-val,
        html[data-theme="light"] #packagingCostModal .pkg-kpi-card .card-kpi-val {
            color: #0f172a !important;
        }
        [data-theme="light"] #packagingCostModal .btn-pkg-add,
        html[data-theme="light"] #packagingCostModal .btn-pkg-add {
            background-color: #f0fdf4 !important;
            border-color: #bbf7d0 !important;
            color: #047857 !important;
        }
        [data-theme="light"] #packagingCostModal .btn-pkg-add:hover,
        html[data-theme="light"] #packagingCostModal .btn-pkg-add:hover {
            background-color: #dcfce7 !important;
        }
        [data-theme="light"] #packagingCostModal .btn-pkg-cancel,
        html[data-theme="light"] #packagingCostModal .btn-pkg-cancel {
            background-color: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            color: #475569 !important;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04) !important;
        }
        [data-theme="light"] #packagingCostModal .btn-pkg-cancel:hover,
        html[data-theme="light"] #packagingCostModal .btn-pkg-cancel:hover {
            background-color: #f8fafc !important;
            color: #0f172a !important;
        }

        /* ══ Emerald Table Row Hover Effect ══ */
        .row-hover {
            transition: background-color 0.18s ease-in-out, border-color 0.18s ease-in-out;
        }
        .row-hover:hover {
            background-color: rgba(16, 185, 129, 0.08) !important;
        }
        html[data-theme="light"] .row-hover:hover,
        [data-theme="light"] .row-hover:hover {
            background-color: rgba(16, 185, 129, 0.1) !important;
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
            border-color: rgba(16, 185, 129, 0.4);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.4), 0 0 15px rgba(16, 185, 129, 0.1);
        }

        .stat-card.active {
            border-color: #10b981;
            background: rgba(16, 185, 129, 0.12);
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.35), 0 8px 30px rgba(0, 0, 0, 0.4);
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

        .hist-filter-tab {
            background: #141418;
            color: #8e8e9f;
            border: 1px solid #252530;
        }
        .hist-filter-tab:hover {
            background: #1f1f26;
            color: #ffffff;
        }
        .hist-filter-tab.active {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border-color: rgba(16, 185, 129, 0.35);
        }
        [data-theme="light"] .hist-filter-tab {
            background: #f3f4f6;
            color: #4b5563;
            border-color: #e5e7eb;
        }
        [data-theme="light"] .hist-filter-tab:hover {
            background: #e5e7eb;
            color: #111827;
        }
        [data-theme="light"] .hist-filter-tab.active {
            background: rgba(16, 185, 129, 0.12);
            color: #047857;
            border-color: rgba(16, 185, 129, 0.4);
        }

        /* ══════════════════════════════════════════════════════════════
           INGREDIENT DEDUCTION HISTORY MODAL (LIGHT & DARK THEMES)
        ══════════════════════════════════════════════════════════════ */
        .hist-modal-content {
            background-color: #18181c;
            border: 1px solid #2b2b36;
            color: #ffffff;
        }
        .hist-kpi-card {
            background-color: #141418;
            border: 1px solid #252530;
        }
        .hist-kpi-label {
            color: #8e8e9f;
        }
        .hist-kpi-val {
            color: #ffffff;
        }
        .hist-search-box {
            background-color: #141418;
            border: 1px solid #252530;
            color: #ffffff;
        }
        .hist-search-box:focus {
            border-color: #10b981;
        }
        .hist-table-wrap {
            background-color: #141418;
            border: 1px solid #252530;
        }
        .hist-thead {
            background-color: #141418;
            color: #8e8e9f;
            border-bottom: 1px solid #252530;
        }
        .hist-tbody {
            background-color: #16161b;
        }
        .hist-row {
            border-bottom: 1px solid #202028;
            color: #d4d4d8;
            transition: background-color 0.15s ease;
        }
        .hist-row:hover {
            background-color: rgba(255, 255, 255, 0.03);
        }
        .hist-row-date {
            color: #ffffff;
        }
        .hist-row-time {
            color: #7d7d8e;
        }
        .hist-row-prod {
            color: #ffffff;
        }
        .hist-row-notes {
            color: #b4b4c2;
        }
        .hist-row-staff {
            color: #8e8e9f;
        }
        .hist-flow-pill {
            background-color: #141418;
            border: 1px solid #252530;
            color: #ffffff;
        }
        .hist-btn-close {
            background-color: #202026;
            color: #ffffff;
            border: 1px solid #2b2b36;
        }
        .hist-btn-close:hover {
            background-color: #282832;
        }

        /* Light Mode Styling for Ingredient History Modal */
        [data-theme="light"] #ingredientHistoryModal .hist-modal-content,
        html[data-theme="light"] #ingredientHistoryModal .hist-modal-content {
            background-color: #ffffff !important;
            border-color: #e2e8f0 !important;
            color: #0f172a !important;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04) !important;
        }
        [data-theme="light"] #ingredientHistoryModal .modal-header,
        html[data-theme="light"] #ingredientHistoryModal .modal-header,
        [data-theme="light"] #ingredientHistoryModal .modal-footer,
        html[data-theme="light"] #ingredientHistoryModal .modal-footer {
            border-color: #e2e8f0 !important;
        }
        [data-theme="light"] #ingredientHistoryModal .modal-title,
        html[data-theme="light"] #ingredientHistoryModal .modal-title {
            color: #0f172a !important;
        }
        [data-theme="light"] #ingredientHistoryModal #historyIngUnitBadge,
        html[data-theme="light"] #ingredientHistoryModal #historyIngUnitBadge {
            background-color: #f1f5f9 !important;
            border-color: #cbd5e1 !important;
            color: #475569 !important;
        }
        [data-theme="light"] #ingredientHistoryModal .hist-kpi-card,
        html[data-theme="light"] #ingredientHistoryModal .hist-kpi-card {
            background-color: #f8fafc !important;
            border-color: #e2e8f0 !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05) !important;
        }
        [data-theme="light"] #ingredientHistoryModal .hist-kpi-label,
        html[data-theme="light"] #ingredientHistoryModal .hist-kpi-label {
            color: #64748b !important;
        }
        [data-theme="light"] #ingredientHistoryModal #historyKpiCurrentStock,
        html[data-theme="light"] #ingredientHistoryModal #historyKpiCurrentStock {
            color: #0f172a !important;
        }
        [data-theme="light"] #ingredientHistoryModal #historyKpiTotalDeducted,
        html[data-theme="light"] #ingredientHistoryModal #historyKpiTotalDeducted {
            color: #e11d48 !important;
        }
        [data-theme="light"] #ingredientHistoryModal #historyKpiTotalOrders,
        html[data-theme="light"] #ingredientHistoryModal #historyKpiTotalOrders {
            color: #d97706 !important;
        }
        [data-theme="light"] #ingredientHistoryModal .hist-search-box,
        html[data-theme="light"] #ingredientHistoryModal .hist-search-box {
            background-color: #f8fafc !important;
            border-color: #cbd5e1 !important;
            color: #0f172a !important;
        }
        [data-theme="light"] #ingredientHistoryModal .hist-search-box:focus,
        html[data-theme="light"] #ingredientHistoryModal .hist-search-box:focus {
            border-color: #10b981 !important;
            background-color: #ffffff !important;
        }
        [data-theme="light"] #ingredientHistoryModal .hist-table-wrap,
        html[data-theme="light"] #ingredientHistoryModal .hist-table-wrap {
            background-color: #ffffff !important;
            border-color: #e2e8f0 !important;
        }
        [data-theme="light"] #ingredientHistoryModal .hist-thead,
        html[data-theme="light"] #ingredientHistoryModal .hist-thead {
            background-color: #f1f5f9 !important;
            color: #475569 !important;
            border-bottom-color: #e2e8f0 !important;
            font-weight: 700 !important;
        }
        [data-theme="light"] #ingredientHistoryModal .hist-tbody,
        html[data-theme="light"] #ingredientHistoryModal .hist-tbody {
            background-color: #ffffff !important;
        }
        [data-theme="light"] #ingredientHistoryModal .hist-row,
        html[data-theme="light"] #ingredientHistoryModal .hist-row {
            border-bottom-color: #f1f5f9 !important;
            color: #1e293b !important;
        }
        [data-theme="light"] #ingredientHistoryModal .hist-row:hover,
        html[data-theme="light"] #ingredientHistoryModal .hist-row:hover {
            background-color: #f8fafc !important;
        }
        [data-theme="light"] #ingredientHistoryModal .hist-row-date,
        html[data-theme="light"] #ingredientHistoryModal .hist-row-date {
            color: #0f172a !important;
            font-weight: 600 !important;
        }
        [data-theme="light"] #ingredientHistoryModal .hist-row-time,
        html[data-theme="light"] #ingredientHistoryModal .hist-row-time {
            color: #64748b !important;
        }
        [data-theme="light"] #ingredientHistoryModal .hist-row-prod,
        html[data-theme="light"] #ingredientHistoryModal .hist-row-prod {
            color: #0f172a !important;
            font-weight: 700 !important;
        }
        [data-theme="light"] #ingredientHistoryModal .hist-row-notes,
        html[data-theme="light"] #ingredientHistoryModal .hist-row-notes {
            color: #334155 !important;
        }
        [data-theme="light"] #ingredientHistoryModal .hist-row-staff,
        html[data-theme="light"] #ingredientHistoryModal .hist-row-staff {
            color: #64748b !important;
        }
        [data-theme="light"] #ingredientHistoryModal .hist-flow-pill,
        html[data-theme="light"] #ingredientHistoryModal .hist-flow-pill {
            background-color: #f1f5f9 !important;
            border-color: #cbd5e1 !important;
            color: #0f172a !important;
        }
        [data-theme="light"] #ingredientHistoryModal .hist-flow-pill span:first-child,
        html[data-theme="light"] #ingredientHistoryModal .hist-flow-pill span:first-child {
            color: #64748b !important;
        }
        [data-theme="light"] #ingredientHistoryModal .hist-flow-pill span:last-child,
        html[data-theme="light"] #ingredientHistoryModal .hist-flow-pill span:last-child {
            color: #0f172a !important;
            font-weight: 700 !important;
        }
        [data-theme="light"] #ingredientHistoryModal .hist-btn-close,
        html[data-theme="light"] #ingredientHistoryModal .hist-btn-close {
            background-color: #f1f5f9 !important;
            border-color: #cbd5e1 !important;
            color: #334155 !important;
        }
        [data-theme="light"] #ingredientHistoryModal .hist-btn-close:hover,
        html[data-theme="light"] #ingredientHistoryModal .hist-btn-close:hover {
            background-color: #e2e8f0 !important;
            color: #0f172a !important;
        }

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
                    <!-- Packaging Set Cost Button -->
                    <button type="button" 
                            onclick="openPackagingCostModal()" 
                            class="btn-top-toolbar inline-flex items-center gap-2 px-3.5 py-2.5 rounded-xl bg-[#18181c] border border-[#262630] text-xs font-semibold text-[#c5c5d2] hover:text-white hover:border-[#10b981] hover:bg-[#1f1f26] transition-all cursor-pointer shadow-sm"
                            title="<?= __('packaging_modal_sub', 'Calculate & manage total packaging cost per cup') ?>">
                        <i class="fa-solid fa-box-open text-[#10b981]"></i>
                        <span><?= __('packaging_set_cost', 'ថ្លៃដើមវេចខ្ចប់សរុប (Cost per Set)') ?></span>
                    </button>

                    <button type="button" 
                            onclick="openAuditLogsModal()" 
                            class="btn-top-toolbar inline-flex items-center gap-2 px-3.5 py-2.5 rounded-xl bg-[#18181c] border border-[#262630] text-xs font-semibold text-[#c5c5d2] hover:text-white hover:border-[#10b981] hover:bg-[#1f1f26] transition-all cursor-pointer shadow-sm">
                        <i class="fa-solid fa-clock-rotate-left text-[#10b981]"></i>
                        <span><?= __('audit_and_logs', 'Audit & Logs') ?></span>
                    </button>

                    <button type="button" 
                            onclick="openAddStockModal()" 
                            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-[#10b981] to-[#059669] text-white text-xs font-bold hover:brightness-110 active:scale-95 transition-all cursor-pointer shadow-lg shadow-[#10b981]/25">
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
                               class="w-full pl-10 pr-9 py-2.5 rounded-xl bg-[#141418] border border-[#252530] text-sm text-[var(--text-main)] placeholder-[#727282] focus:outline-none focus:border-[#10b981] focus:ring-1 focus:ring-[#10b981] transition-all">
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
                                    class="w-full appearance-none pl-3.5 pr-8 py-2.5 rounded-xl bg-[#141418] border border-[#252530] text-xs font-semibold text-[var(--text-main)] focus:outline-none focus:border-[#10b981] cursor-pointer">
                                <option value="all"><?= __('all_categories', 'All Ingredient Categories') ?></option>
                                <option value="Liquids"><?= __('cat_liquids', 'ទឹក') ?></option>
                                <option value="Beans"><?= __('cat_beans', 'គ្រាប់') ?></option>
                                <option value="Packaging"><?= __('cat_packaging', 'កែវ & ការវេចខ្ចប់') ?></option>
                                <option value="General Supplies"><?= __('cat_general', 'សម្ភារទូទៅ') ?></option>
                            </select>
                            <i class="fa-solid fa-chevron-down absolute right-3 top-1/2 -translate-y-1/2 text-xs text-[#727282] pointer-events-none"></i>
                        </div>

                        <input type="hidden" id="statusFilter" value="all">

                        <!-- Sort Selector -->
                        <div class="relative min-w-[150px]">
                            <select id="sortSelector" 
                                    onchange="loadStockTable()" 
                                    class="w-full appearance-none pl-3.5 pr-8 py-2.5 rounded-xl bg-[#141418] border border-[#252530] text-xs font-semibold text-[var(--text-main)] focus:outline-none focus:border-[#10b981] cursor-pointer">
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
                                class="btn-reset-filter w-9 h-9 rounded-xl bg-[#141418] border border-[#252530] text-[#8e8e9f] hover:text-[#10b981] hover:border-[#10b981] flex items-center justify-center transition-all cursor-pointer" 
                                title="Reset Filters and Refresh Table">
                            <i class="fa-solid fa-arrows-rotate text-xs"></i>
                        </button>
                    </div>

                </div>
            </div>

            <!-- ── Data Table Card ── -->
            <div class="glass-card overflow-hidden flex-1 flex flex-col">
                <div class="ingredient-table-scroll-container flex-1">
                    <table class="w-full text-left border-separate border-spacing-0 text-xs">
                        <thead class="sticky top-0 z-20 shadow-sm">
                            <tr class="table-header-cell bg-[#141418] text-[#8e8e9f] uppercase tracking-wider font-semibold">
                                <th class="sticky top-0 z-20 py-3.5 px-4 bg-[#141418] border-b border-[#24242b]"><?= __('col_ingredient_details', 'Ingredient Details') ?></th>
                                <th class="sticky top-0 z-20 py-3.5 px-3 bg-[#141418] border-b border-[#24242b]"><?= __('col_category', 'Category') ?></th>
                                <th class="sticky top-0 z-20 py-3.5 px-3 bg-[#141418] border-b border-[#24242b]"><?= __('col_qty_on_hand', 'Qty on Hand') ?></th>
                                <th class="sticky top-0 z-20 py-3.5 px-3 bg-[#141418] border-b border-[#24242b]"><?= __('col_alert_threshold', 'Alert Threshold') ?></th>
                                <th class="sticky top-0 z-20 py-3.5 px-3 bg-[#141418] border-b border-[#24242b]"><?= __('col_status', 'Status') ?></th>
                                <th class="sticky top-0 z-20 py-3.5 px-3 bg-[#141418] border-b border-[#24242b]"><?= __('col_valuation', 'Valuation') ?></th>
                                <th class="sticky top-0 z-20 py-3.5 px-4 bg-[#141418] border-b border-[#24242b] text-right"><?= __('col_actions', 'Actions') ?></th>
                            </tr>
                        </thead>
                        <tbody id="stockTableBody" class="table-divide divide-y divide-[#1f1f28]">
                            <!-- Initial PHP render -->
                            <?php foreach ($stockItems as $item): 
                                $isPkgSet = (str_contains(strtolower($item['item_name']), 'packaging set') || str_contains($item['item_name'], 'ឈុត'));
                                $qty = (float)$item['quantity'];
                                $alert = (float)$item['alert_level'];
                                $cost = (float)$item['cost_per_unit'];
                                $val = $qty * $cost;
                                $bulkPrice = ($item['unit'] === 'g' || $item['unit'] === 'ml') ? ($cost * 1000) : $cost;
                                $bulkUnit = ($item['unit'] === 'g') ? 'kg' : (($item['unit'] === 'ml') ? 'L' : 'pcs');

                                $status = 'in_stock';
                                $statusBadge = '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> ' . __('status_in_stock', 'In Stock') . '</span>';
                                
                                if ($qty <= 0) {
                                    $status = 'out_of_stock';
                                    $statusBadge = '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-rose-500/10 text-rose-400 border border-rose-500/20"><i class="fa-solid fa-circle-exclamation text-[10px]"></i> ' . __('status_out_of_stock', 'Out of Stock') . '</span>';
                                } elseif ($qty <= $alert) {
                                    $status = 'low_stock';
                                    $statusBadge = '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-500/10 text-amber-400 border border-amber-500/20"><i class="fa-solid fa-triangle-exclamation text-[10px]"></i> ' . __('status_low_stock', 'Low Stock') . '</span>';
                                }

                                $khrCost = $cost * (defined('KHR_RATE') ? KHR_RATE : 4000);
                                $khrFormatted = $khrCost >= 10 ? number_format(round($khrCost)) : ($khrCost > 0 ? (floor($khrCost) == $khrCost ? number_format($khrCost, 0) : number_format($khrCost, 1)) : '0');
                            ?>
                            <tr class="row-hover group" data-item-id="<?= $item['item_id'] ?>">
                                <td class="py-3.5 px-4">
                                    <div class="item-name-text font-bold text-[var(--text-main)] text-sm group-hover:text-[#10b981] transition-colors truncate flex items-center gap-2">
                                        <?php if ($isPkgSet): ?>
                                            <i class="fa-solid fa-box-open text-[#10b981] text-xs"></i>
                                        <?php endif; ?>
                                        <span><?= htmlspecialchars($item['item_name']) ?></span>
                                    </div>
                                </td>
                                <td class="py-3.5 px-3">
                                    <span class="cat-badge inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold bg-[#1e1e24] text-[#b4b4c2] border border-[#282834]">
                                        <?= htmlspecialchars(formatCategoryLabel($item['category'])) ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-3 font-semibold <?= $isPkgSet ? 'text-center' : '' ?>">
                                    <?php if ($isPkgSet): ?>
                                        <span class="text-xs font-medium text-[#8e8e9f]">-</span>
                                    <?php else: ?>
                                        <span class="text-sm font-extrabold <?= ($qty <= 0) ? 'text-rose-400' : (($qty <= $alert) ? 'text-amber-400' : 'text-[var(--text-main)]') ?>">
                                            <?= formatQty($qty) ?> <span class="text-xs font-normal text-[#8e8e9f]"><?= htmlspecialchars($item['unit']) ?></span>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3.5 px-3 font-medium <?= $isPkgSet ? 'text-center' : '' ?>">
                                    <?php if ($isPkgSet): ?>
                                        <span class="text-xs font-medium text-[#8e8e9f]">-</span>
                                    <?php else: ?>
                                        <span class="threshold-badge px-2.5 py-1 rounded-lg bg-[#1e1e24] border border-[#282834] text-xs text-[#8e8e9f]">
                                            <?= formatQty($alert) ?> <?= htmlspecialchars($item['unit']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3.5 px-3">
                                    <?php if ($isPkgSet): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                            <i class="fa-solid fa-calculator text-[10px]"></i> <?= __('auto_calculated_set', 'គិតតាមរូបមន្ត (Auto Set)') ?>
                                        </span>
                                    <?php else: ?>
                                        <?= $statusBadge ?>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3.5 px-3">
                                    <?php if ($isPkgSet): ?>
                                        <div class="font-bold text-emerald-400 text-xs">$<?= number_format($cost, 4) ?> / set</div>
                                    <?php else: ?>
                                        <div class="val-main-text text-[var(--text-main)] font-bold text-xs">$<?= number_format($val, 2) ?></div>
                                        <div class="text-[11px] font-bold text-[#34d399] mt-0.5">$<?= number_format($bulkPrice, 2) ?> / <?= $bulkUnit ?></div>
                                        <div class="text-[10px] text-[#8e8e9f]">$<?= number_format($cost, 4) ?> / <?= htmlspecialchars($item['unit']) ?> <span class="text-emerald-400/80 font-semibold">(≈ <?= $khrFormatted ?> ៛)</span></div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3.5 px-4 text-right">
                                    <?php if ($isPkgSet): ?>
                                        <button type="button" onclick="openPackagingCostModal()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-500/15 hover:bg-emerald-500/25 text-emerald-400 border border-emerald-500/30 text-xs font-bold transition-all cursor-pointer shadow-sm">
                                            <i class="fa-solid fa-gear text-xs"></i> <span><?= __('packaging_set_cost', 'ថ្លៃដើមវេចខ្ចប់') ?></span>
                                        </button>
                                    <?php else: ?>
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button type="button" onclick="openIngredientHistoryModal(<?= $item['item_id'] ?>, '<?= addslashes(htmlspecialchars($item['item_name'])) ?>')" class="btn-action-neutral p-1.5 rounded-lg bg-[#1f1f26] text-[#b4b4c2] hover:text-sky-400 hover:bg-sky-500/15 border border-[#2b2b36] transition-all cursor-pointer" title="<?= __('btn_history', 'ប្រវត្តិប្រើប្រាស់ & កាត់ស្តុក (Deduction History)') ?>"><i class="fa-solid fa-clock-rotate-left w-4 text-center"></i></button>
                                            <button type="button" onclick="openEditStockModal(<?= $item['item_id'] ?>)" class="btn-action-neutral p-1.5 rounded-lg bg-[#1f1f26] text-[#b4b4c2] hover:text-white hover:bg-[#282832] border border-[#2b2b36] transition-all cursor-pointer" title="<?= __('btn_edit', 'Edit') ?>"><i class="fa-solid fa-pen-to-square w-4 text-center"></i></button>
                                            <button type="button" onclick="openRestockModal(<?= $item['item_id'] ?>)" class="btn-action-neutral p-1.5 rounded-lg bg-[#1f1f26] text-[#b4b4c2] hover:text-emerald-400 hover:bg-emerald-500/15 border border-[#2b2b36] transition-all cursor-pointer" title="<?= __('btn_restock', 'Restock') ?>"><i class="fa-solid fa-plus w-4 text-center"></i></button>
                                            <button type="button" onclick="confirmDeleteItem(<?= $item['item_id'] ?>, '<?= addslashes(htmlspecialchars($item['item_name'])) ?>')" class="btn-action-neutral p-1.5 rounded-lg bg-[#1f1f26] text-[#8e8e9f] hover:text-rose-400 hover:bg-rose-500/15 border border-[#2b2b36] transition-all cursor-pointer" title="<?= __('btn_delete', 'Delete') ?>"><i class="fa-solid fa-trash-can w-4 text-center"></i></button>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="table-header-cell px-4 py-3 bg-[#141418] border-t border-[#24242b] flex items-center justify-between text-xs text-[#8e8e9f]">
                    <div id="tableRecordCount"><?= __('showing_ingredients_count', 'Showing raw ingredients') ?>: <?= count($stockItems) ?></div>
                </div>
            </div>
        </main>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         MODAL 1: STOCK-IN MASTER (បញ្ចូលស្តុកគ្រឿងផ្សំ - FULL MASTER UNITS)
    ══════════════════════════════════════════════════════════════ -->
    <div id="addStockModal" class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-sm">
        <div class="modal-content glass-card max-w-2xl w-full p-6 sm:p-7 rounded-3xl shadow-2xl relative flex flex-col max-h-[90vh]">
            <!-- Header -->
            <div class="modal-header flex items-center justify-between pb-4 border-b shrink-0">
                <div class="flex items-center gap-3">
                    <div class="stockin-icon-wrap w-11 h-11 rounded-2xl bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 flex items-center justify-center text-xl shadow-sm">
                        <i class="fa-solid fa-box-open"></i>
                    </div>
                    <div>
                        <h2 class="stockin-title text-base sm:text-lg font-extrabold flex items-center gap-2">
                            📦 បញ្ចូលស្តុកគ្រឿងផ្សំ (Stock-In Master)
                        </h2>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="stockin-badge hidden sm:inline-block px-3 py-1 rounded-full text-[11px] font-bold">
                        POS Inventory
                    </span>
                    <button type="button" onclick="closeModal('addStockModal')" class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10 text-slate-500 hover:text-slate-900 dark:text-[#7d7d8e] dark:hover:text-white flex items-center justify-center transition-all cursor-pointer">
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>
            </div>

            <!-- Scrollable Form Body -->
            <form id="addStockForm" onsubmit="handleAddStock(event)" class="custom-modal-scroll mt-3 space-y-4 overflow-y-auto px-1 py-1 flex-1">
                <input type="hidden" name="action" value="create_item">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="quantity" id="addQuantityHidden" value="0">
                <input type="hidden" name="unit" id="addUnitHidden" value="g">
                <input type="hidden" name="cost_per_unit" id="addCostUnitHidden" value="0.0000">

                <!-- ឈ្មោះគ្រឿងផ្សំ -->
                <div>
                    <label class="stockin-label block mb-1.5">
                        ឈ្មោះគ្រឿងផ្សំ (Item Name) <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" 
                           name="item_name" 
                           id="addIngredientName"
                           required 
                           autocomplete="off"
                           placeholder="e.g. Soda Water (កំប៉ុង 320ml)" 
                           class="stockin-input w-full px-4 py-2.5 rounded-xl text-sm">
                    <div id="addIngredientDupAlert" class="hidden mt-1.5 text-xs text-rose-500 font-semibold flex items-center gap-1.5">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span></span>
                    </div>
                </div>

                <!-- ប្រភេទ & Alert Threshold -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <div>
                        <label class="stockin-label block mb-1.5">
                            ប្រភេទ (Category) <span class="text-rose-500">*</span>
                        </label>
                        <select name="category" id="addStockCategory" required 
                                class="stockin-input w-full px-3.5 py-2.5 rounded-xl text-xs font-bold">
                            <option value="Liquids"><?= __('cat_liquids', 'ទឹក') ?></option>
                            <option value="Beans" selected><?= __('cat_beans', 'គ្រាប់') ?></option>
                            <option value="Packaging"><?= __('cat_packaging', 'កែវ & ការវេចខ្ចប់') ?></option>
                            <option value="General Supplies"><?= __('cat_general', 'សម្ភារទូទៅ') ?></option>
                        </select>
                    </div>

                    <div>
                        <label class="stockin-label block mb-1.5">
                            កម្រិតប្រកាសអាសន្ន (Alert Threshold)
                        </label>
                        <input type="number" 
                               step="any" 
                               min="0" 
                               name="alert_level" 
                               id="addAlertLevelInput"
                               value="1000" 
                               class="stockin-input w-full px-3.5 py-2.5 rounded-xl text-xs">
                    </div>
                </div>

                <!-- ប្រភេទការទិញចូល និង ចំនួន -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <div>
                        <label class="stockin-label block mb-1.5">
                            ខ្នាតទិញចូល (Purchase Unit) <span class="text-rose-500">*</span>
                        </label>
                        <select id="packageType" name="purchase_unit_type" onchange="handlePackageTypeChange(); calculateLiveStock();"
                                class="stockin-input w-full px-3.5 py-2.5 rounded-xl text-xs font-bold">
                            <optgroup label="⚖️ ខ្នាតទម្ងន់ (Weight)">
                                <option value="kg">គីឡូក្រាម (kg)</option>
                                <option value="g">ក្រាម (g)</option>
                                <option value="bag">បាវ / ថង់ធំ (Bag / Sack - 20kg/25kg)</option>
                            </optgroup>
                            <optgroup label="🥤 ខ្នាតចំណុះរាវ (Volume)">
                                <option value="L">លីត្រ (L)</option>
                                <option value="ml">មីលីលីត្រ (ml)</option>
                                <option value="can" selected>កំប៉ុង (Can)</option>
                                <option value="bottle">ដប (Bottle)</option>
                                <option value="gallon">ហ្គាឡុង (Gallon / Jug - 5L)</option>
                            </optgroup>
                            <optgroup label="📦 ខ្នាតវេចខ្ចប់ និងរាប់ចំនួន (Packaging & Count)">
                                <option value="sleeve">ដើម (Sleeve - 50pcs)</option>
                                <option value="pack">កញ្ចប់ (Pack - 100pcs)</option>
                                <option value="dozen">ឡូ (Dozen - 12pcs)</option>
                                <option value="box">ប្រអប់ (Box)</option>
                                <option value="case">កេស (Case / Carton)</option>
                                <option value="pcs">ចំនួនរាយ (pcs)</option>
                            </optgroup>
                        </select>
                    </div>
                    <div>
                        <label class="stockin-label block mb-1.5">
                            ចំនួនទិញចូល (Quantity) <span class="text-rose-500">*</span>
                        </label>
                        <input type="number" id="qtyInput" min="0.01" step="any" value="24" oninput="calculateLiveStock();" required
                               class="stockin-input w-full px-3.5 py-2.5 rounded-xl font-bold text-sm">
                    </div>
                </div>

                <!-- Dynamic Sub-Units Config (Appears for Case / Box / Bag / Gallon) -->
                <div id="subUnitConfig" class="p-3.5 rounded-2xl grid grid-cols-1 sm:grid-cols-2 gap-3.5 hidden">
                    <div>
                        <label id="subUnitLabel" class="stockin-label block mb-1 text-xs font-semibold">ចំនួនក្នុង ១ ឯកតាទិញ</label>
                        <input type="number" id="subUnitMultiplier" value="24" min="0.001" step="any" oninput="calculateLiveStock();"
                               class="stockin-input w-full px-3 py-2 rounded-xl text-xs font-bold">
                    </div>
                    <div class="flex items-center text-[11px] leading-relaxed" id="subUnitHint">
                        💡 ឧទាហរណ៍៖ សូដា ១ កេសមាន ២៤ កំប៉ុង (24 Cans/Case)
                    </div>
                </div>

                <!-- ទំហំក្នុង ១ ឯកតាតូច & តម្លៃទិញសរុប -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5 items-end">
                    <div>
                        <div class="flex items-center justify-between mb-1.5 h-6">
                            <label id="unitVolLabel" class="stockin-label whitespace-nowrap">
                                ចំណុះក្នុង ១ កំប៉ុង <span class="text-rose-500">*</span>
                            </label>
                        </div>
                        <div class="relative">
                            <input type="number" id="volumePerUnit" value="320" step="any" min="0.001" oninput="calculateLiveStock();" required
                                   class="stockin-input w-full px-3.5 py-2.5 rounded-xl font-bold pr-12 text-xs">
                            <span id="unitVolSuffix" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-xs font-mono font-extrabold text-emerald-500 dark:text-emerald-400">ml</span>
                        </div>
                    </div>
                    <div>
                        <div class="flex items-center justify-between mb-1.5 h-6">
                            <label class="stockin-label whitespace-nowrap">
                                តម្លៃទិញសរុប (Total Cost) <span class="text-rose-500">*</span>
                            </label>
                            <div class="inline-flex p-0.5 rounded-lg bg-slate-100 dark:bg-[#1a1a22] border border-slate-200 dark:border-[#2d2d3b] shrink-0">
                                <button type="button" id="currBtnUsd" onclick="setAddCurrency('USD')" class="px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-[#10b981] text-white shadow-xs cursor-pointer transition-all">USD ($)</button>
                                <button type="button" id="currBtnKhr" onclick="setAddCurrency('KHR')" class="px-2 py-0.5 rounded-md text-[10px] font-semibold text-slate-600 dark:text-[#8e8e9f] hover:text-slate-900 dark:hover:text-white cursor-pointer transition-all">KHR (៛)</button>
                            </div>
                        </div>
                        <div class="relative">
                            <input type="number" id="addTotalCostInput" value="9" step="any" min="0" oninput="calculateLiveStock();" required
                                   class="stockin-input w-full px-3.5 py-2.5 rounded-xl font-extrabold text-emerald-600 dark:text-emerald-400 pr-16 text-sm">
                            <span id="totalCostSuffix" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-xs font-mono font-bold text-emerald-600 dark:text-emerald-400">$ USD</span>
                            <input type="hidden" id="addCostCurrency" value="USD">
                        </div>
                    </div>
                </div>

                <!-- Density / Recipe DB info -->
                <div class="stockin-density-box flex items-center justify-between p-3 rounded-2xl text-[11px]">
                    <div class="flex items-center gap-2">
                        <span class="font-bold">ខ្នាតមូលដ្ឋាន Recipe DB:</span> 
                        <span id="baseUnitDisplay" class="font-mono font-extrabold px-2.5 py-0.5 rounded-lg border">ml</span>
                    </div>
                    <div class="opacity-90 font-medium">
                        <span id="densityInfo">1 ml ≈ 1.00 g (Water/Soda)</span>
                    </div>
                </div>

                <!-- Live Conversion Result Card -->
                <div class="stockin-conv-box rounded-2xl p-4 space-y-2.5">
                    <div class="flex items-center justify-between">
                        <h3 class="stockin-conv-title text-[11px] font-extrabold uppercase tracking-wider flex items-center gap-1.5">
                            ⚡ លទ្ធផលបំប្លែងស្វ័យប្រវត្តិក្នងប្រព័ន្ធ (POS Live Conversion)
                        </h3>
                        <span class="stockin-conv-formula text-xs font-mono font-bold" id="formulaPreview">24 កំប៉ុង = 7,680 ml</span>
                    </div>
                    
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 pt-1">
                        <div class="stockin-stat-card p-3 rounded-xl text-center">
                            <div id="resUnitHeader" class="stockin-stat-label text-[11px] font-medium">ចំនួនកំប៉ុងសរុប</div>
                            <div id="resTotalUnits" class="stockin-stat-val-units text-sm sm:text-base font-extrabold font-mono mt-1">24 កំប៉ុង</div>
                        </div>
                        <div class="stockin-stat-card p-3 rounded-xl text-center">
                            <div class="stockin-stat-label text-[11px] font-medium">ស្តុកកាត់ជា ml</div>
                            <div id="resTotalMl" class="stockin-stat-val-ml text-sm sm:text-base font-extrabold font-mono mt-1">7,680 ml</div>
                        </div>
                        <div class="stockin-stat-card p-3 rounded-xl text-center">
                            <div class="stockin-stat-label text-[11px] font-medium">ស្តុកកាត់ជា g</div>
                            <div id="resTotalG" class="stockin-stat-val-g text-sm sm:text-base font-extrabold font-mono mt-1">7,680 g</div>
                        </div>
                        <div class="stockin-stat-card p-3 rounded-xl text-center">
                            <div class="stockin-stat-label text-[11px] font-medium">ថ្លៃដើម/Unit</div>
                            <div id="resCostBreakdown" class="stockin-stat-val-cost text-sm sm:text-base font-extrabold font-mono mt-1">$0.0012</div>
                        </div>
                    </div>
                </div>

                <!-- Supplier / Notes -->
                <div>
                    <label class="stockin-label block mb-1 text-xs">អ្នកផ្គត់ផ្គង់ / កំណត់សម្គាល់ (Supplier & Notes)</label>
                    <input type="text" name="notes" placeholder="e.g. Royal Wholesale Beverage Supplier" 
                           class="stockin-input w-full px-3.5 py-2 rounded-xl text-xs">
                </div>

                <!-- Footer Action Buttons -->
                <div class="modal-footer flex items-center justify-end gap-3 pt-3 border-t shrink-0">
                    <button type="button" 
                            onclick="closeModal('addStockModal')" 
                            class="btn-stockin-cancel px-5 py-2.5 rounded-xl text-xs font-bold transition-all cursor-pointer">
                        បោះបង់ (Cancel)
                    </button>
                    <button type="submit" 
                            id="addStockSubmitBtn" 
                            class="btn-stockin-submit px-6 py-2.5 rounded-xl text-xs font-black transition-all flex items-center justify-center gap-2 cursor-pointer">
                        <span>រក្សាទុកទិន្នន័យចូលស្តុក POS (Save Stock)</span>
                    </button>
                </div>
            </form>

        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         MODAL 2: QUICK INGREDIENT RESTOCK
    ══════════════════════════════════════════════════════════════ -->
    <!-- ══════════════════════════════════════════════════════════════
         MODAL 2: QUICK INGREDIENT RESTOCK (ថែមស្តុកទំនិញ)
    ══════════════════════════════════════════════════════════════ -->
    <div id="restockModal" class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-sm">
        <div class="modal-content glass-card max-w-2xl w-full p-6 sm:p-8 rounded-3xl shadow-2xl relative flex flex-col max-h-[92vh]">
            
            <!-- Header -->
            <div class="modal-header flex items-center justify-between pb-3.5 border-b shrink-0">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-emerald-500/15 text-emerald-500 border border-emerald-500/30 flex items-center justify-center text-lg shadow-sm">
                        <i class="fa-solid fa-truck-ramp-box"></i>
                    </div>
                    <div>
                        <h2 class="restock-title text-base sm:text-lg font-extrabold flex items-center gap-2">
                            📦 ថែមស្តុកទំនិញ (Restock Item)
                        </h2>
                        <p class="text-[11px] text-slate-500 dark:text-[#8e8e9f] mt-0.5">
                            បន្ថែមបរិមាណស្តុក និងគណនាថ្លៃដើមស្វ័យប្រវត្តិ
                        </p>
                    </div>
                </div>
                <button type="button" onclick="closeModal('restockModal')" class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10 text-slate-500 hover:text-slate-900 dark:text-[#7d7d8e] dark:hover:text-white flex items-center justify-center transition-all cursor-pointer">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>

            <!-- Form -->
            <form id="restockForm" onsubmit="handleQuickRestock(event)" class="custom-modal-scroll mt-3.5 space-y-4 overflow-y-auto px-1 py-1 flex-1">
                <input type="hidden" name="action" value="quick_restock">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="unit_multiplier" id="restockMultiplierHidden" value="1000">
                <input type="hidden" name="cost_per_unit" id="restockCostUnitHidden" value="">

                <!-- មុខទំនិញត្រូវថែមស្តុក -->
                <div>
                    <label class="restock-label block text-xs mb-1.5 font-bold">
                        មុខទំនិញត្រូវថែមស្តុក (Select Ingredient) <span class="text-rose-500">*</span>
                    </label>
                    <select name="item_id" 
                            id="restockItemSelect" 
                            required 
                            onchange="handleRestockItemChange()" 
                            class="restock-input w-full px-3.5 py-2.5 rounded-xl text-xs font-bold focus:outline-none">
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

                <!-- ខ្នាតទិញចូល និង ចំនួន -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5 items-end">
                    <div>
                        <label class="restock-label block text-xs mb-1.5 font-bold">
                            ខ្នាតទិញចូល (Purchase Unit) <span class="text-rose-500">*</span>
                        </label>
                        <select id="restockPurchaseUnit" onchange="handleRestockUnitChange()" 
                                class="restock-input w-full px-3.5 py-2.5 rounded-xl text-xs font-bold focus:outline-none">
                            <optgroup label="⚖️ ខ្នាតទម្ងន់ (Weight)">
                                <option value="kg" selected>គីឡូក្រាម (kg)</option>
                                <option value="g">ក្រាម (g)</option>
                                <option value="bag">បាវ / ថង់ធំ (Bag / Sack - 20kg/25kg)</option>
                            </optgroup>
                            <optgroup label="🥤 ខ្នាតចំណុះរាវ (Volume)">
                                <option value="L">លីត្រ (L)</option>
                                <option value="ml">មីលីលីត្រ (ml)</option>
                                <option value="can">កំប៉ុង (Can)</option>
                                <option value="bottle">ដប (Bottle)</option>
                                <option value="gallon">ហ្គាឡុង (Gallon / Jug - 5L)</option>
                            </optgroup>
                            <optgroup label="📦 ខ្នាតវេចខ្ចប់ និងរាប់ចំនួន (Packaging & Count)">
                                <option value="sleeve">ដើម (Sleeve - 50pcs)</option>
                                <option value="pack">កញ្ចប់ (Pack - 100pcs)</option>
                                <option value="dozen">ឡូ (Dozen - 12pcs)</option>
                                <option value="box">ប្រអប់ (Box)</option>
                                <option value="case">កេស (Case / Carton)</option>
                                <option value="pcs">ចំនួនរាយ (pcs)</option>
                            </optgroup>
                        </select>
                    </div>

                    <div>
                        <label class="restock-label block text-xs mb-1.5 font-bold">
                            ចំនួនទិញចូល (Qty) <span class="text-rose-500">*</span>
                        </label>
                        <input type="number" 
                               step="any" 
                               min="0.01" 
                               name="quantity_added" 
                               id="restockQtyInput" 
                               required 
                               value="1" 
                               oninput="calculateRestockTotal()" 
                               class="restock-input w-full px-3.5 py-2.5 rounded-xl text-sm font-bold focus:outline-none">
                    </div>
                </div>

                <!-- Dynamic Sub-Units Config (Appears for Case / Box / Bag / Gallon) -->
                <div id="restockSubUnitConfig" class="restock-subunit-box p-3.5 rounded-2xl grid grid-cols-1 sm:grid-cols-2 gap-3.5 items-center hidden">
                    <div>
                        <label id="restockSubUnitLabel" class="restock-label block mb-1 text-xs">ចំនួនក្នុង ១ ឯកតាទិញ</label>
                        <input type="number" id="restockSubUnitMultiplier" value="24" min="0.001" step="any" oninput="calculateRestockTotal();"
                               class="restock-input w-full px-3.5 py-2 rounded-xl text-xs font-bold">
                    </div>
                    <div class="flex items-center text-[11px] restock-subunit-hint leading-relaxed" id="restockSubUnitHint">
                        💡 ឧទាហរណ៍៖ សូដា ១ កេសមាន ២៤ កំប៉ុង (24 Cans/Case)
                    </div>
                </div>

                <!-- ទំហំក្នុង ១ ឯកតាតូច & តម្លៃទិញសរុប -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5 items-end">
                    <div>
                        <div class="flex items-center justify-between mb-1.5 h-6">
                            <label id="restockUnitVolLabel" class="restock-label whitespace-nowrap">
                                ចំណុះ/ទម្ងន់ក្នុង ១ ឯកតា <span class="text-rose-500">*</span>
                            </label>
                        </div>
                        <div class="relative">
                            <input type="number" id="restockVolumePerUnit" value="1000" step="any" min="0.001" oninput="calculateRestockTotal();" required
                                   class="restock-input w-full px-3.5 py-2.5 rounded-xl font-bold pr-12 text-xs">
                            <span id="restockUnitVolSuffix" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-xs font-mono font-extrabold text-emerald-600 dark:text-emerald-400">g</span>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-1.5 h-6">
                            <label class="restock-label whitespace-nowrap">
                                តម្លៃទិញសរុប (Total Cost)
                            </label>
                            <div class="restock-curr-toggle-wrap inline-flex p-0.5 rounded-lg shrink-0">
                                <button type="button" id="restockCurrBtnKhr" onclick="setRestockCurrency('KHR')" class="px-2 py-0.5 rounded-md text-[10px] font-semibold text-slate-500 hover:text-slate-900 dark:text-[#8e8e9f] dark:hover:text-white cursor-pointer transition-all">KHR (៛)</button>
                                <button type="button" id="restockCurrBtnUsd" onclick="setRestockCurrency('USD')" class="px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-[#059669] text-white shadow-xs cursor-pointer transition-all">USD ($)</button>
                            </div>
                        </div>
                        <div class="relative">
                            <input type="number" 
                                   id="restockTotalCostInput" 
                                   step="any" 
                                   min="0" 
                                   placeholder="e.g. 45000" 
                                   oninput="calculateRestockTotal()" 
                                   class="restock-input w-full px-3.5 py-2.5 rounded-xl font-bold pr-16 text-sm">
                            <span id="restockCostSuffix" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-xs font-mono font-bold text-emerald-600 dark:text-emerald-400">$ USD</span>
                            <input type="hidden" id="restockCostCurrency" value="USD">
                        </div>
                    </div>
                </div>

                <!-- Live Calculated Units Badge -->
                <div id="restockPreviewCard" class="restock-preview-box p-3.5 rounded-2xl text-xs space-y-2.5">
                    <div class="flex justify-between items-center font-extrabold">
                        <span class="restock-preview-label">ស្តុកបន្ថែម (Added):</span>
                        <span id="restockAddedBaseUnits" class="font-mono text-emerald-600 dark:text-emerald-400 text-sm font-bold">+1,000 g</span>
                    </div>
                    <div class="flex justify-between items-center text-xs pt-1.5 border-t border-emerald-500/20">
                        <span class="restock-preview-label">ស្តុកចាស់: <strong id="restockCurrentQty" class="font-mono font-bold ml-1">--</strong></span>
                        <span class="restock-preview-label">ស្តុកថ្មីសរុប: <strong id="restockNewQty" class="font-mono font-extrabold text-sm ml-1">--</strong></span>
                    </div>
                    <div id="restockCostPreviewRow" class="flex justify-between items-center text-xs pt-1.5 border-t border-emerald-500/20">
                        <span class="restock-preview-label">ថ្លៃដើមគិតជាមធ្យមថ្មី:</span>
                        <span id="restockNewUnitCost" class="font-mono font-extrabold">--</span>
                    </div>
                </div>

                <!-- Supplier / Notes -->
                <div>
                    <label class="restock-label block text-xs mb-1 font-medium">អ្នកផ្គត់ផ្គង់ / កំណត់សម្គាល់ (Supplier & Notes)</label>
                    <input type="text" 
                           name="notes" 
                           placeholder="e.g. Replenishment from Coffee Supplier" 
                           class="restock-input w-full px-3.5 py-2 rounded-xl text-xs">
                </div>

                <!-- Footer -->
                <div class="modal-footer flex items-center justify-end gap-3 pt-3 border-t shrink-0">
                    <button type="button" 
                            onclick="closeModal('restockModal')" 
                            class="px-4 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-[#202026] dark:hover:bg-[#282832] text-xs font-bold text-slate-600 dark:text-[#b4b4c2] hover:text-slate-900 dark:hover:text-white transition-all cursor-pointer">
                        <?= __('cancel', 'Cancel') ?>
                    </button>
                    <button type="submit" 
                            id="restockSubmitBtn" 
                            class="px-6 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-extrabold transition-all shadow-md shadow-emerald-600/25 active:scale-[0.99] cursor-pointer">
                        <span>បញ្ជាក់ការថែមស្តុក (Confirm Restock)</span>
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
                            class="w-full px-3.5 py-2.5 rounded-xl bg-[#141418] border border-[#282834] text-xs font-semibold text-[var(--text-main)] focus:outline-none focus:border-[#10b981]">
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
                           class="w-full px-3.5 py-2 rounded-xl bg-[#141418] border border-[#282834] text-xs text-[var(--text-main)] focus:outline-none focus:border-[#10b981]">
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
                    <div class="w-8 h-8 rounded-xl bg-emerald-500/20 text-[#10b981] flex items-center justify-center text-sm font-bold">
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
                           class="w-full px-3.5 py-2.5 rounded-xl bg-[#141418] border border-[#282834] text-sm text-[var(--text-main)] focus:outline-none focus:border-[#10b981]">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_category', 'Category') ?> <span class="text-rose-400">*</span></label>
                        <select id="editCategory" name="category" required class="w-full px-3.5 py-2.5 rounded-xl bg-[#141418] border border-[#282834] text-xs font-semibold text-[var(--text-main)] focus:outline-none focus:border-[#10b981]">
                            <option value="Liquids"><?= __('cat_liquids', 'ទឹក') ?></option>
                            <option value="Beans"><?= __('cat_beans', 'គ្រាប់') ?></option>
                            <option value="Packaging"><?= __('cat_packaging', 'កែវ & ការវេចខ្ចប់') ?></option>
                            <option value="General Supplies"><?= __('cat_general', 'សម្ភារទូទៅ') ?></option>
                        </select>
                    </div>

                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_unit', 'Measurement Unit') ?> <span class="text-rose-400">*</span></label>
                        <select id="editUnit" name="unit" required onchange="handleUnitChange('edit')" class="w-full px-3.5 py-2.5 rounded-xl bg-[#141418] border border-[#282834] text-xs font-semibold text-[var(--text-main)] focus:outline-none focus:border-[#10b981]">
                            <option value="g">g (Grams)</option>
                            <option value="ml">ml (Milliliters)</option>
                            <option value="pcs">pcs (Pieces)</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_qty_on_hand', 'Quantity on Hand') ?></label>
                        <input type="number" 
                               step="any" 
                               min="0" 
                               id="editQuantity" 
                               name="quantity" 
                               class="w-full px-3 py-2 rounded-xl bg-[#141418] border border-[#282834] text-xs text-[var(--text-main)] focus:outline-none focus:border-[#10b981]">
                    </div>
                    <div>
                        <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('col_alert_threshold', 'Alert Threshold') ?></label>
                        <input type="number" 
                               step="any" 
                               min="0" 
                               id="editAlertLevel" 
                               name="alert_level" 
                               class="w-full px-3 py-2 rounded-xl bg-[#141418] border border-[#282834] text-xs text-[var(--text-main)] focus:outline-none focus:border-[#10b981]">
                    </div>
                </div>

                <!-- Pricing Section: Price per KG/L + Base Unit Cost -->
                <div class="pricing-calc-box p-3.5 rounded-xl space-y-2.5">
                    <div class="flex items-center justify-between text-xs">
                        <span class="pricing-header-title font-bold flex items-center gap-1.5 text-emerald-400">
                            <i class="fa-solid fa-calculator"></i>
                            <span id="editPricingSectionTitle"><?= __('cost_per_kg_l', 'Price per 1 KG / 1 Liter') ?></span>
                        </span>
                        <span class="pricing-header-sub text-[11px]" id="editPricingFormula">1 kg = 1,000 g</span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="modal-label block text-[11px] font-semibold text-[#b4b4c2] mb-1">
                                <span id="editBulkCostLabelText"><?= __('cost_per_1kg', 'Price per 1 KG ($)') ?></span> <span class="text-rose-400">*</span>
                            </label>
                            <div class="relative">
                                <span class="pricing-dollar-icon absolute left-3 top-1/2 -translate-y-1/2 text-xs font-bold text-[#10b981]">$</span>
                                <input type="number" 
                                       step="any" 
                                       min="0" 
                                       id="editBulkCostInput" 
                                       placeholder="e.g. 12.00" 
                                       oninput="syncCostInputs('edit', 'bulk')" 
                                       class="pricing-calc-input pricing-calc-input-highlight w-full pl-7 pr-3 py-2 rounded-xl text-xs font-bold focus:outline-none focus:border-[#10b981] focus:ring-1 focus:ring-[#10b981]">
                            </div>
                        </div>

                        <div>
                            <label class="modal-label block text-[11px] font-semibold text-[#b4b4c2] mb-1">
                                <span id="editBaseCostLabelText"><?= __('cost_per_base_unit', 'Cost per Base Unit ($ / g)') ?></span>
                            </label>
                            <div class="relative">
                                <span class="pricing-dollar-icon-muted absolute left-3 top-1/2 -translate-y-1/2 text-xs">$</span>
                                <input type="number" 
                                       step="0.0001" 
                                       min="0" 
                                       id="editCostUnit" 
                                       name="cost_per_unit" 
                                       value="0.0000" 
                                       oninput="syncCostInputs('edit', 'base')" 
                                       class="pricing-calc-input w-full pl-7 pr-3 py-2 rounded-xl text-xs focus:outline-none focus:border-[#10b981]">
                            </div>
                        </div>
                    </div>
                    
                    <div id="editCostPreviewPill" class="pricing-calc-preview text-[11px] flex items-center justify-between pt-1">
                        <span>Live Unit Cost: <strong class="font-mono text-emerald-400" id="editLiveBaseCostDisplay">$0.0000 / g</strong></span>
                        <span class="formula-text text-[10px]" id="editLiveFormulaText">1 kg @ $0.00</span>
                    </div>
                </div>

                <div>
                    <label class="modal-label block text-xs font-semibold text-[#b4b4c2] mb-1"><?= __('supplier_notes', 'Supplier & Notes') ?></label>
                    <textarea id="editNotes" 
                              name="notes" 
                              rows="2" 
                              class="w-full px-3.5 py-2 rounded-xl bg-[#141418] border border-[#282834] text-xs text-[var(--text-main)] focus:outline-none focus:border-[#10b981]"></textarea>
                </div>

                <div class="modal-footer flex items-center justify-end gap-2.5 pt-3 border-t border-[#252530]">
                    <button type="button" 
                            onclick="closeModal('editStockModal')" 
                            class="px-4 py-2 rounded-xl bg-[#202026] text-xs font-semibold text-[#b4b4c2] hover:text-white transition-all cursor-pointer">
                        <?= __('cancel', 'Cancel') ?>
                    </button>
                    <button type="submit" 
                            id="editStockSubmitBtn" 
                            class="px-5 py-2 rounded-xl bg-gradient-to-r from-[#10b981] to-[#059669] hover:from-[#059669] hover:to-[#047857] text-white text-xs font-bold transition-all shadow-md shadow-emerald-500/20 cursor-pointer">
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
                    <div class="w-8 h-8 rounded-xl bg-emerald-500/20 text-[#10b981] flex items-center justify-center text-sm font-bold">
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
                    <i class="fa-solid fa-spinner fa-spin text-2xl text-[#10b981] mb-2"></i>
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

    <!-- ══════════════════════════════════════════════════════════════
         MODAL 6: PACKAGING SET COST (ថ្លៃដើមវេចខ្ចប់សរុប)
    ══════════════════════════════════════════════════════════════ -->
    <div id="packagingCostModal" class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75">
        <div class="modal-content glass-card max-w-2xl w-full p-6 bg-[#18181c] border border-[#2b2b36] rounded-2xl shadow-2xl relative flex flex-col max-h-[90vh]">
            <!-- Modal Header -->
            <div class="modal-header flex items-center justify-between pb-3 mb-4 border-b border-[#252530]">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/20 text-[#10b981] border border-emerald-500/30 flex items-center justify-center text-base font-bold shadow-sm">
                        <i class="fa-solid fa-box-open"></i>
                    </div>
                    <div>
                        <h3 class="modal-title text-base font-bold text-[var(--text-main,#ffffff)]"><?= __('packaging_modal_title', 'ថ្លៃដើមវេចខ្ចប់សរុបក្នុង 1 ឈុត (Packaging Cost per Set)') ?></h3>
                        <p class="text-xs text-[#8e8e9f] card-subtext"><?= __('packaging_modal_sub', 'គណនា និងកំណត់ថ្លៃដើមសម្ភារវេចខ្ចប់សរុបក្នុង 1 កែវ (កែវ គម្រប បំពង់បឺត ថង់ ស្រោមដៃកែវ...)') ?></p>
                    </div>
                </div>
                <button type="button" onclick="closeModal('packagingCostModal')" class="w-8 h-8 rounded-lg hover:bg-black/10 dark:hover:bg-white/10 text-[#7d7d8e] hover:text-[var(--text-main,#ffffff)] flex items-center justify-center transition-all cursor-pointer">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>

            <!-- Modal Body / Items List -->
            <div class="overflow-y-auto flex-1 space-y-4 pr-1">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-[#b4b4c2] flex items-center gap-2">
                        <i class="fa-solid fa-layer-group text-[#10b981]"></i> <?= __('packaging_item_name', 'Packaging Materials Breakdown') ?>
                    </span>
                    <button type="button" 
                            onclick="addPackagingRow()" 
                            class="btn-pkg-add px-3.5 py-1.5 rounded-lg text-xs font-bold flex items-center gap-1.5 transition-all cursor-pointer shadow-sm">
                        <i class="fa-solid fa-plus text-[11px]"></i> <?= __('packaging_add_item_btn', 'Add Component') ?>
                    </button>
                </div>

                <!-- Interactive Item List Table -->
                <div class="pkg-table-wrap rounded-xl overflow-hidden shadow-sm">
                    <table class="w-full text-xs text-left">
                        <thead class="pkg-thead">
                            <tr>
                                <th class="py-2.5 px-3 font-semibold"><?= __('packaging_item_name', 'Component') ?></th>
                                <th class="py-2.5 px-3 font-semibold w-32"><?= __('packaging_cost_unit', 'Price / Pc ($)') ?></th>
                                <th class="py-2.5 px-3 font-semibold w-24 text-center"><?= __('packaging_qty_per_set', 'Qty/Cup') ?></th>
                                <th class="py-2.5 px-3 font-semibold w-28 text-right"><?= __('packaging_subtotal', 'Subtotal') ?></th>
                                <th class="py-2.5 px-2 w-10 text-center"></th>
                            </tr>
                        </thead>
                        <tbody id="packagingRowsBody">
                            <!-- JS will dynamically render interactive rows -->
                        </tbody>
                    </table>
                </div>

                <!-- Big Dynamic KPI / Total Summary Card -->
                <div class="pkg-summary-box p-4 rounded-xl space-y-3 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="text-xs font-semibold text-[#8e8e9f] block"><?= __('packaging_total_per_cup', 'Total Packaging Cost per 1 Cup') ?></span>
                            <div class="flex items-baseline gap-2 mt-0.5">
                                <span class="text-2xl font-black text-emerald-500 font-mono" id="pkgTotalPerCupUsd">$0.0000</span>
                                <span class="text-sm font-bold text-emerald-400 font-mono" id="pkgTotalPerCupKhr">≈ 0 ៛ (Riel)</span>
                            </div>
                        </div>
                        <div class="w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 flex items-center justify-center text-lg shadow-sm">
                            <i class="fa-solid fa-calculator"></i>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 pt-2 border-t border-[var(--border,#252530)]">
                        <div class="pkg-kpi-card p-2.5 rounded-lg">
                            <span class="text-[11px] text-[#8e8e9f] font-medium block"><?= __('packaging_100_cups', 'Cost for 100 Cups') ?></span>
                            <div class="card-kpi-val text-sm font-bold font-mono mt-0.5" id="pkg100CupsVal">$0.00 <span class="text-[11px] font-normal text-emerald-400">(≈ 0 ៛)</span></div>
                        </div>
                        <div class="pkg-kpi-card p-2.5 rounded-lg">
                            <span class="text-[11px] text-[#8e8e9f] font-medium block"><?= __('packaging_1000_cups', 'Cost for 1,000 Cups') ?></span>
                            <div class="card-kpi-val text-sm font-bold font-mono mt-0.5" id="pkg1000CupsVal">$0.00 <span class="text-[11px] font-normal text-emerald-400">(≈ 0 ៛)</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="modal-footer flex items-center justify-between pt-4 border-t border-[#252530] mt-4">
                <button type="button" 
                        onclick="closeModal('packagingCostModal')" 
                        class="btn-pkg-cancel px-4 py-2 rounded-xl text-xs font-semibold transition-all cursor-pointer">
                    <?= __('btn_cancel', 'Cancel') ?>
                </button>
                <button type="button" 
                        id="savePackagingSetBtn" 
                        onclick="savePackagingSet()" 
                        class="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-gradient-to-r from-[#10b981] to-[#059669] text-white text-xs font-bold hover:brightness-110 active:scale-95 transition-all cursor-pointer shadow-md shadow-emerald-500/20">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <span><?= __('packaging_save_btn', 'Save Packaging Set Cost') ?></span>
                </button>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         MODAL 7: INGREDIENT DEDUCTION & USAGE HISTORY
    ══════════════════════════════════════════════════════════════ -->
    <div id="ingredientHistoryModal" class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75">
        <div class="modal-content hist-modal-content max-w-3xl w-full p-5 sm:p-6 rounded-2xl shadow-2xl relative flex flex-col max-h-[85vh]">
            <!-- Header -->
            <div class="modal-header flex items-center justify-between pb-3.5 mb-3.5 border-b border-[#252530]">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-sky-500/15 text-sky-400 border border-sky-500/30 flex items-center justify-center text-base font-bold shadow-sm">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <h3 class="modal-title text-base font-bold flex items-center gap-2">
                                <span><?= __('ingredient_history_title', 'ប្រវត្តិប្រើប្រាស់ & កាត់ស្តុក') ?>:</span>
                                <span id="historyIngTitleName" class="text-[#10b981]">--</span>
                            </h3>
                            <span id="historyIngUnitBadge" class="px-2 py-0.5 rounded-md text-[11px] font-bold bg-[#202028] text-[#8e8e9f] border border-[#2b2b36]">--</span>
                        </div>
                        <p class="text-xs text-[#8e8e9f] card-subtext"><?= __('ingredient_history_sub', 'ពិនិត្យមើលរាល់ការកាត់ស្តុកតាម Order នីមួយៗ និងមុខភេសជ្ជៈដែលបានប្រើប្រាស់') ?></p>
                    </div>
                </div>
                <button type="button" onclick="closeModal('ingredientHistoryModal')" class="w-8 h-8 rounded-lg hover:bg-black/10 dark:hover:bg-white/10 text-[#7d7d8e] hover:text-[var(--text-main,#ffffff)] flex items-center justify-center transition-all cursor-pointer">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>

            <!-- Instant Search Toolbar -->
            <div class="pb-2.5 border-b border-[#252530]">
                <div class="relative w-full">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-[#8e8e9f]"></i>
                    <input type="text" 
                           id="historySearchInput" 
                           placeholder="<?= __('search_orders_or_products', 'ស្វែងរកតាមលេខ Order ឬ ឈ្មោះភេសជ្ជៈ...') ?>" 
                           oninput="handleHistorySearch(this.value)" 
                           class="hist-search-box w-full pl-8 pr-3 py-2 rounded-xl text-xs focus:outline-none">
                </div>
            </div>

            <!-- Content Area (Scrollable Table) -->
            <div class="overflow-y-auto flex-1 pr-1 mt-2.5 custom-modal-scroll" id="historyModalBody">
                <div class="text-center py-10 text-[#8e8e9f]">
                    <i class="fa-solid fa-spinner fa-spin text-2xl text-[#10b981] mb-2"></i>
                    <p class="text-xs">Loading deduction history...</p>
                </div>
            </div>

            <!-- Footer -->
            <div class="modal-footer flex items-center justify-between pt-3 border-t border-[#252530] mt-3">
                <div class="text-[11px] text-[#727282] flex items-center gap-1.5">
                    <i class="fa-solid fa-circle-info text-sky-400"></i>
                    <span id="historyLogCountText">Showing recent movements</span>
                </div>
                <button type="button" 
                        onclick="closeModal('ingredientHistoryModal')" 
                        class="hist-btn-close px-5 py-2 rounded-xl text-xs font-semibold transition-all cursor-pointer">
                    <?= __('btn_close', 'Close') ?>
                </button>
            </div>
        </div>
    </div>

    <!-- ── JavaScript Client Engine ── -->
    <script>
        const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        const KHR_RATE = <?= defined('KHR_RATE') ? KHR_RATE : 4000 ?>;
        let stockItemsData = <?= json_encode($stockItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?> || [];

        const I18N = {
            lang: "<?= current_lang() ?>",
            packagingSetCost: "<?= __('packaging_set_cost', 'ថ្លៃដើមវេចខ្ចប់') ?>",
            inStock: "<?= __('status_in_stock', 'In Stock') ?>",
            lowStock: "<?= __('status_low_stock', 'Low Stock') ?>",
            outOfStock: "<?= __('status_out_of_stock', 'Out of Stock') ?>",
            restock: "<?= __('btn_restock', 'Restock') ?>",
            edit: "<?= __('btn_edit', 'Edit') ?>",
            delete: "<?= __('btn_delete', 'Delete') ?>",
            history: "<?= __('btn_history', 'ប្រវត្តិប្រើប្រាស់ & កាត់ស្តុក') ?>",
            allLogs: "<?= __('filter_all_logs', 'ទាំងអស់') ?>",
            saleDeduct: "<?= __('log_sale_deduct', 'កាត់តាមការលក់') ?>",
            restockLog: "<?= __('log_restock', 'ថែមស្តុក') ?>",
            wasteLog: "<?= __('log_waste', 'ខូចខាត') ?>",
            orderNo: "<?= __('order_no', 'Order #') ?>",
            product: "<?= __('product', 'ភេសជ្ជៈ / ទំនិញ') ?>",
            qtyChange: "<?= __('qty_change', 'បរិមាណកាត់') ?>",
            stockFlow: "<?= __('stock_flow', 'ស្តុកដើម → ស្តុកនៅសល់') ?>",
            recordedBy: "<?= __('recorded_by', 'អ្នកកត់ត្រា') ?>",
            noHistoryLogs: "<?= __('no_history_logs', 'មិនទាន់មានប្រវត្តិកាត់ស្តុកសម្រាប់គ្រឿងផ្សំនេះនៅឡើយទេ') ?>",
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
                'Liquids': "<?= __('cat_liquids', 'ទឹក') ?>",
                'Dairy': "<?= __('cat_liquids', 'ទឹក') ?>",
                'Syrups': "<?= __('cat_liquids', 'ទឹក') ?>",
                'ទឹក': "<?= __('cat_liquids', 'ទឹក') ?>",
                'Beans': "<?= __('cat_beans', 'គ្រាប់') ?>",
                'គ្រាប់': "<?= __('cat_beans', 'គ្រាប់') ?>",
                'Packaging': "<?= __('cat_packaging', 'កែវ & ការវេចខ្ចប់') ?>",
                'កែវ & ការវេចខ្ចប់': "<?= __('cat_packaging', 'កែវ & ការវេចខ្ចប់') ?>",
                'កែវ &ការវេចខ្ជប់': "<?= __('cat_packaging', 'កែវ & ការវេចខ្ចប់') ?>",
                'General Supplies': "<?= __('cat_general', 'សម្ភារទូទៅ') ?>",
                'Bakery / Toppings': "<?= __('cat_general', 'សម្ភារទូទៅ') ?>",
                'សម្ភារទូទៅ': "<?= __('cat_general', 'សម្ភារទូទៅ') ?>",
                'សម្ភារៈទូទៅ': "<?= __('cat_general', 'សម្ភារទូទៅ') ?>"
            },
            costPer1kg: "<?= __('cost_per_1kg', 'Price per 1 KG ($)') ?>",
            costPer1l: "<?= __('cost_per_1l', 'Price per 1 Liter ($)') ?>",
            costPer1pc: "<?= __('cost_per_1pc', 'Price per 1 Piece ($)') ?>",
            costPerBaseUnit: "<?= __('cost_per_base_unit', 'Cost per Base Unit ($)') ?>"
        };

        const CATEGORY_META = {
            'Liquids': { icon: 'fa-bottle-water', color: 'sky' },
            'Dairy': { icon: 'fa-bottle-water', color: 'sky' },
            'Syrups': { icon: 'fa-bottle-water', color: 'sky' },
            'ទឹក': { icon: 'fa-bottle-water', color: 'sky' },
            'Beans': { icon: 'fa-seedling', color: 'amber' },
            'គ្រាប់': { icon: 'fa-seedling', color: 'amber' },
            'Packaging': { icon: 'fa-box-open', color: 'emerald' },
            'កែវ & ការវេចខ្ចប់': { icon: 'fa-box-open', color: 'emerald' },
            'General Supplies': { icon: 'fa-boxes-stacked', color: 'slate' },
            'Bakery / Toppings': { icon: 'fa-boxes-stacked', color: 'slate' },
            'សម្ភារទូទៅ': { icon: 'fa-boxes-stacked', color: 'slate' }
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

                stockItemsData = data.items || [];
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
                            <div class="w-12 h-12 rounded-full bg-[#1e1e24] text-[#10b981] mx-auto flex items-center justify-center text-xl mb-3">
                                <i class="fa-solid fa-seedling"></i>
                            </div>
                            <div class="text-sm font-bold text-white mb-1">${escapeHtml(I18N.noIngredientsFound)}</div>
                            <p class="text-xs text-[#7d7d8e] max-w-sm mx-auto mb-4">No ingredients matched your current filters. Try resetting filters or adding a new raw ingredient.</p>
                            <button type="button" onclick="openAddStockModal()" class="px-4 py-2 rounded-xl bg-gradient-to-r from-[#10b981] to-[#059669] text-white text-xs font-bold hover:brightness-110 cursor-pointer shadow-md shadow-emerald-500/20">
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
                const isPkgSet = (item.item_name && (item.item_name.toLowerCase().includes('packaging set') || item.item_name.includes('ឈុត')));
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
                const bulkPrice = (item.unit === 'g' || item.unit === 'ml') ? (cost * 1000) : cost;
                const bulkUnit = (item.unit === 'g') ? 'kg' : ((item.unit === 'ml') ? 'L' : 'pcs');
                const khrCost = cost * KHR_RATE;
                const khrCostText = khrCost >= 10 ? Math.round(khrCost).toLocaleString('en-US') : (khrCost > 0 ? (Number.isInteger(khrCost) ? khrCost : khrCost.toFixed(1)) : '0');

                if (isPkgSet) {
                    html += `
                    <tr class="row-hover group" data-item-id="${item.item_id}">
                        <td class="py-3.5 px-4">
                            <div class="item-name-text font-bold text-[var(--text-main)] text-sm group-hover:text-[#10b981] transition-colors truncate flex items-center gap-2">
                                <i class="fa-solid fa-box-open text-[#10b981] text-xs"></i>
                                <span>${escapeHtml(item.item_name)}</span>
                            </div>
                        </td>
                        <td class="py-3.5 px-3">
                            <span class="cat-badge inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold bg-[#1e1e24] text-[#b4b4c2] border border-[#282834]">
                                ${escapeHtml(displayCategory)}
                            </span>
                        </td>
                        <td class="py-3.5 px-3 font-semibold text-center">
                            <span class="text-xs font-medium text-[#8e8e9f]">-</span>
                        </td>
                        <td class="py-3.5 px-3 font-medium text-center">
                            <span class="text-xs font-medium text-[#8e8e9f]">-</span>
                        </td>
                        <td class="py-3.5 px-3">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                <i class="fa-solid fa-calculator text-[10px]"></i> ${I18N.lang === 'km' ? 'គិតតាមរូបមន្ត (Auto Set)' : 'Auto Set'}
                            </span>
                        </td>
                        <td class="py-3.5 px-3">
                            <div class="font-bold text-emerald-400 text-xs">$${cost.toFixed(4)} / set</div>
                            <div class="text-[10px] text-emerald-500 font-semibold mt-0.5">(≈ ${khrCostText} ៛)</div>
                        </td>
                        <td class="py-3.5 px-4 text-right">
                            <button type="button" 
                                    onclick="openPackagingCostModal()" 
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-500/15 hover:bg-emerald-500/25 text-emerald-400 border border-emerald-500/30 text-xs font-bold transition-all cursor-pointer shadow-sm" 
                                    title="${escapeHtml(I18N.packagingSetCost)}">
                                <i class="fa-solid fa-gear text-xs"></i>
                                <span>${escapeHtml(I18N.packagingSetCost)}</span>
                            </button>
                        </td>
                    </tr>
                    `;
                } else {
                    html += `
                    <tr class="row-hover group" data-item-id="${item.item_id}">
                        <td class="py-3.5 px-4">
                            <div class="item-name-text font-bold text-[var(--text-main)] text-sm group-hover:text-[#10b981] transition-colors truncate">
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
                            <div class="text-[11px] font-bold text-[#34d399] mt-0.5">$${bulkPrice.toFixed(2)} / ${bulkUnit}</div>
                            <div class="text-[10px] text-[#8e8e9f]">$${cost.toFixed(4)} / ${escapeHtml(item.unit)} <span class="text-emerald-400/80 font-semibold">(≈ ${khrCostText} ៛)</span></div>
                        </td>
                        <td class="py-3.5 px-4 text-right">
                            <div class="flex items-center justify-end gap-1.5">
                                <button type="button" 
                                        onclick="openIngredientHistoryModal(${item.item_id}, '${escapeHtml(item.item_name).replace(/'/g, "\\'")}')" 
                                        class="btn-action-neutral p-1.5 rounded-lg bg-[#1f1f26] text-[#b4b4c2] hover:text-sky-400 hover:bg-sky-500/15 border border-[#2b2b36] transition-all cursor-pointer" 
                                        title="${escapeHtml(I18N.history || 'Deduction History')}">
                                    <i class="fa-solid fa-clock-rotate-left w-4 text-center"></i>
                                </button>
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
                }
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

        // ── Pricing Synchronizer & Calculator ──
        function handleUnitChange(mode) {
            const unitSelect = document.getElementById(mode === 'add' ? 'addUnitSelect' : 'editUnit');
            const unit = unitSelect ? unitSelect.value : 'g';
            
            const titleEl = document.getElementById(mode === 'add' ? 'addPricingSectionTitle' : 'editPricingSectionTitle');
            const formulaEl = document.getElementById(mode === 'add' ? 'addPricingFormula' : 'editPricingFormula');
            const bulkLabelEl = document.getElementById(mode === 'add' ? 'addBulkCostLabelText' : 'editBulkCostLabelText');
            const baseLabelEl = document.getElementById(mode === 'add' ? 'addBaseCostLabelText' : 'editBaseCostLabelText');
            const bulkInput = document.getElementById(mode === 'add' ? 'addBulkCostInput' : 'editBulkCostInput');

            if (unit === 'g') {
                if (titleEl) titleEl.textContent = (I18N.costPer1kg || 'Price per 1 KG ($)');
                if (formulaEl) formulaEl.textContent = '1 kg = 1,000 g';
                if (bulkLabelEl) bulkLabelEl.textContent = (I18N.costPer1kg || 'Price per 1 KG ($)');
                if (baseLabelEl) baseLabelEl.textContent = (I18N.costPerBaseUnit || 'Cost per Base Unit ($)') + ' (/ g)';
                if (bulkInput) bulkInput.placeholder = 'e.g. 12.00 ($/kg)';
            } else if (unit === 'ml') {
                if (titleEl) titleEl.textContent = (I18N.costPer1l || 'Price per 1 Liter ($)');
                if (formulaEl) formulaEl.textContent = '1 L = 1,000 ml';
                if (bulkLabelEl) bulkLabelEl.textContent = (I18N.costPer1l || 'Price per 1 Liter ($)');
                if (baseLabelEl) baseLabelEl.textContent = (I18N.costPerBaseUnit || 'Cost per Base Unit ($)') + ' (/ ml)';
                if (bulkInput) bulkInput.placeholder = 'e.g. 1.80 ($/L)';
            } else {
                if (titleEl) titleEl.textContent = (I18N.costPer1pc || 'Price per 1 Piece ($)');
                if (formulaEl) formulaEl.textContent = '1 piece';
                if (bulkLabelEl) bulkLabelEl.textContent = (I18N.costPer1pc || 'Price per 1 Piece ($)');
                if (baseLabelEl) baseLabelEl.textContent = (I18N.costPerBaseUnit || 'Cost per Base Unit ($)') + ' (/ pc)';
                if (bulkInput) bulkInput.placeholder = 'e.g. 0.05 ($/pc)';
            }

            syncCostInputs(mode, 'bulk');
        }

        function syncCostInputs(mode, source) {
            const unitSelect = document.getElementById(mode === 'add' ? 'addUnitSelect' : 'editUnit');
            const unit = unitSelect ? unitSelect.value : 'g';
            const multiplier = (unit === 'g' || unit === 'ml') ? 1000 : 1;

            const bulkInput = document.getElementById(mode === 'add' ? 'addBulkCostInput' : 'editBulkCostInput');
            const baseInput = document.getElementById(mode === 'add' ? 'addCostUnit' : 'editCostUnit');
            const liveDisplay = document.getElementById(mode === 'add' ? 'addLiveBaseCostDisplay' : 'editLiveBaseCostDisplay');
            const liveFormula = document.getElementById(mode === 'add' ? 'addLiveFormulaText' : 'editLiveFormulaText');

            if (!bulkInput || !baseInput) return;

            let baseVal = 0;
            if (source === 'bulk') {
                const bulkVal = parseFloat(bulkInput.value);
                if (!isNaN(bulkVal) && bulkVal >= 0) {
                    baseVal = bulkVal / multiplier;
                    baseInput.value = baseVal.toFixed(4);
                } else {
                    baseInput.value = '0.0000';
                }
            } else {
                const rawBase = parseFloat(baseInput.value);
                if (!isNaN(rawBase) && rawBase >= 0) {
                    baseVal = rawBase;
                    const bulkVal = baseVal * multiplier;
                    bulkInput.value = (Math.round(bulkVal * 100) / 100).toFixed(2);
                } else {
                    bulkInput.value = '';
                }
            }

            if (liveDisplay) {
                liveDisplay.textContent = `$${baseVal.toFixed(4)} / ${unit}`;
            }

            if (liveFormula) {
                if (baseVal > 0) {
                    const khrVal = baseVal * KHR_RATE;
                    const khrFormatted = khrVal >= 10 ? Math.round(khrVal).toLocaleString('en-US') : (khrVal > 0 ? (Number.isInteger(khrVal) ? khrVal : khrVal.toFixed(1)) : '0');
                    liveFormula.innerHTML = `<span class="font-bold text-amber-500">≈ ${khrFormatted} ៛ (Riel)</span> <span class="text-[#8e8e9f]">/ ${escapeHtml(unit)}</span>`;
                } else {
                    liveFormula.innerHTML = `<span class="text-[#727282]">0 ៛ / ${escapeHtml(unit)}</span>`;
                }
            }
        }

        // ── Stock-In Master (Full Units Conversion & Live Calculator) ──
        function handlePackageTypeChange() {
            const pType = document.getElementById('packageType')?.value || 'can';
            const subUnitConfig = document.getElementById('subUnitConfig');
            const subUnitLabel = document.getElementById('subUnitLabel');
            const subUnitHint = document.getElementById('subUnitHint');
            const subUnitMultiplier = document.getElementById('subUnitMultiplier');
            const unitVolLabel = document.getElementById('unitVolLabel');
            const unitVolSuffix = document.getElementById('unitVolSuffix');
            const volumePerUnit = document.getElementById('volumePerUnit');
            const baseUnitDisplay = document.getElementById('baseUnitDisplay');
            const densityInfo = document.getElementById('densityInfo');
            const catSelect = document.getElementById('addStockCategory');

            if (!subUnitConfig) return;

            // Default hide subUnitConfig unless grouped packaging
            subUnitConfig.classList.add('hidden');

            if (pType === 'case') {
                subUnitConfig.classList.remove('hidden');
                if (subUnitLabel) subUnitLabel.textContent = 'ចំនួន កំប៉ុង/ដប/កញ្ចប់ ក្នុង ១ កេស';
                if (subUnitHint) subUnitHint.innerHTML = '💡 ឧ. សូដា ១ កេសមាន ២៤ កំប៉ុង, កែវ ១ កេសមាន ២០ ដើម';
                if (subUnitMultiplier && (!subUnitMultiplier.value || subUnitMultiplier.value === '1')) subUnitMultiplier.value = 24;
                if (unitVolLabel) unitVolLabel.innerHTML = 'ចំណុះក្នុង ១ ឯកតារាយ <span class="text-rose-500">*</span>';
                if (unitVolSuffix) unitVolSuffix.textContent = 'ml';
                if (volumePerUnit && (!volumePerUnit.value || volumePerUnit.value === '1000')) volumePerUnit.value = 320;
                if (baseUnitDisplay) baseUnitDisplay.textContent = 'ml / pcs';
                if (densityInfo) densityInfo.textContent = 'Liquid Can (320ml)';
                if (catSelect && catSelect.value === 'Beans') catSelect.value = 'Liquids';
            } else if (pType === 'box') {
                subUnitConfig.classList.remove('hidden');
                if (subUnitLabel) subUnitLabel.textContent = 'ចំនួនកញ្ចប់/ដប ក្នុង ១ ប្រអប់';
                if (subUnitHint) subUnitHint.innerHTML = '💡 ឧ. តែ ១ ប្រអប់មាន ២៥ កញ្ចប់តូច (Teabags)';
                if (subUnitMultiplier && (!subUnitMultiplier.value || subUnitMultiplier.value === '1')) subUnitMultiplier.value = 25;
                if (unitVolLabel) unitVolLabel.innerHTML = 'ទម្ងន់/ចំណុះ ក្នុង ១ កញ្ចប់ <span class="text-rose-500">*</span>';
                if (unitVolSuffix) unitVolSuffix.textContent = 'g';
                if (volumePerUnit) volumePerUnit.value = 20;
                if (baseUnitDisplay) baseUnitDisplay.textContent = 'g';
                if (densityInfo) densityInfo.textContent = 'Box Items';
            } else if (pType === 'bag') {
                subUnitConfig.classList.remove('hidden');
                if (subUnitLabel) subUnitLabel.textContent = 'ទម្ងន់ក្នុង ១ បាវ (kg/Bag)';
                if (subUnitHint) subUnitHint.innerHTML = '💡 ឧ. ទឹកកកអនាម័យ ១ បាវ = 20kg, ស្ករស ១ បាវ = 50kg';
                if (subUnitMultiplier && (!subUnitMultiplier.value || subUnitMultiplier.value === '1')) subUnitMultiplier.value = 20;
                if (unitVolLabel) unitVolLabel.innerHTML = 'មេគុណបំប្លែងទៅជា g <span class="text-rose-500">*</span>';
                if (unitVolSuffix) unitVolSuffix.textContent = 'g';
                if (volumePerUnit) volumePerUnit.value = 1000;
                if (baseUnitDisplay) baseUnitDisplay.textContent = 'g';
                if (densityInfo) densityInfo.textContent = 'Weight: 1 kg = 1,000 g';
            } else if (pType === 'gallon') {
                subUnitConfig.classList.remove('hidden');
                if (subUnitLabel) subUnitLabel.textContent = 'ចំណុះក្នុង ១ ហ្គាឡុង (Liters/Gal)';
                if (subUnitHint) subUnitHint.innerHTML = '💡 ឧ. ទឹកស៊ីរ៉ូ ឬទឹកដោះគោ ១ ហ្គាឡុង = 5L (5,000ml)';
                if (subUnitMultiplier && (!subUnitMultiplier.value || subUnitMultiplier.value === '1')) subUnitMultiplier.value = 5;
                if (unitVolLabel) unitVolLabel.innerHTML = 'មេគុណបំប្លែងទៅជា ml <span class="text-rose-500">*</span>';
                if (unitVolSuffix) unitVolSuffix.textContent = 'ml';
                if (volumePerUnit) volumePerUnit.value = 1000;
                if (baseUnitDisplay) baseUnitDisplay.textContent = 'ml';
                if (densityInfo) densityInfo.textContent = 'Volume: 1 L = 1,000 ml';
                if (catSelect) catSelect.value = 'Liquids';
            } else if (pType === 'sleeve') {
                if (unitVolLabel) unitVolLabel.innerHTML = 'ចំនួនក្នុង ១ ដើម <span class="text-rose-500">*</span>';
                if (volumePerUnit) volumePerUnit.value = 50;
                if (unitVolSuffix) unitVolSuffix.textContent = 'pcs';
                if (baseUnitDisplay) baseUnitDisplay.textContent = 'pcs';
                if (densityInfo) densityInfo.textContent = 'Count Item';
                if (catSelect) catSelect.value = 'Packaging';
            } else if (pType === 'pack') {
                if (unitVolLabel) unitVolLabel.innerHTML = 'ចំនួនក្នុង ១ កញ្ចប់ <span class="text-rose-500">*</span>';
                if (volumePerUnit) volumePerUnit.value = 100;
                if (unitVolSuffix) unitVolSuffix.textContent = 'pcs';
                if (baseUnitDisplay) baseUnitDisplay.textContent = 'pcs';
                if (densityInfo) densityInfo.textContent = 'Count Item';
                if (catSelect) catSelect.value = 'Packaging';
            } else if (pType === 'dozen') {
                if (unitVolLabel) unitVolLabel.innerHTML = 'ចំនួនក្នុង ១ ឡូ <span class="text-rose-500">*</span>';
                if (volumePerUnit) volumePerUnit.value = 12;
                if (unitVolSuffix) unitVolSuffix.textContent = 'pcs';
                if (baseUnitDisplay) baseUnitDisplay.textContent = 'pcs';
                if (densityInfo) densityInfo.textContent = 'Count Item';
                if (catSelect) catSelect.value = 'Packaging';
            } else if (pType === 'kg') {
                if (unitVolLabel) unitVolLabel.innerHTML = 'ចំនួនក្រាមក្នុង ១ kg <span class="text-rose-500">*</span>';
                if (volumePerUnit) volumePerUnit.value = 1000;
                if (unitVolSuffix) unitVolSuffix.textContent = 'g';
                if (baseUnitDisplay) baseUnitDisplay.textContent = 'g';
                if (densityInfo) densityInfo.textContent = 'Weight: 1 kg = 1,000 g';
                if (catSelect && catSelect.value === 'Liquids') catSelect.value = 'Beans';
            } else if (pType === 'g') {
                if (unitVolLabel) unitVolLabel.innerHTML = 'ទម្ងន់ (g) <span class="text-rose-500">*</span>';
                if (volumePerUnit) volumePerUnit.value = 1;
                if (unitVolSuffix) unitVolSuffix.textContent = 'g';
                if (baseUnitDisplay) baseUnitDisplay.textContent = 'g';
                if (densityInfo) densityInfo.textContent = 'Base Unit: g';
            } else if (pType === 'L') {
                if (unitVolLabel) unitVolLabel.innerHTML = 'ចំនួន ml ក្នុង ១ លីត្រ <span class="text-rose-500">*</span>';
                if (volumePerUnit) volumePerUnit.value = 1000;
                if (unitVolSuffix) unitVolSuffix.textContent = 'ml';
                if (baseUnitDisplay) baseUnitDisplay.textContent = 'ml';
                if (densityInfo) densityInfo.textContent = 'Volume: 1 L = 1,000 ml';
                if (catSelect) catSelect.value = 'Liquids';
            } else if (pType === 'ml') {
                if (unitVolLabel) unitVolLabel.innerHTML = 'ចំណុះ (ml) <span class="text-rose-500">*</span>';
                if (volumePerUnit) volumePerUnit.value = 1;
                if (unitVolSuffix) unitVolSuffix.textContent = 'ml';
                if (baseUnitDisplay) baseUnitDisplay.textContent = 'ml';
                if (densityInfo) densityInfo.textContent = 'Base Unit: ml';
                if (catSelect) catSelect.value = 'Liquids';
            } else if (pType === 'can') {
                if (unitVolLabel) unitVolLabel.innerHTML = 'ចំណុះក្នុង ១ កំប៉ុង <span class="text-rose-500">*</span>';
                if (volumePerUnit) volumePerUnit.value = 320;
                if (unitVolSuffix) unitVolSuffix.textContent = 'ml';
                if (baseUnitDisplay) baseUnitDisplay.textContent = 'ml';
                if (densityInfo) densityInfo.textContent = 'Liquid Can (320ml)';
                if (catSelect) catSelect.value = 'Liquids';
            } else if (pType === 'bottle') {
                if (unitVolLabel) unitVolLabel.innerHTML = 'ចំណុះក្នុង ១ ដប <span class="text-rose-500">*</span>';
                if (volumePerUnit) volumePerUnit.value = 330;
                if (unitVolSuffix) unitVolSuffix.textContent = 'ml';
                if (baseUnitDisplay) baseUnitDisplay.textContent = 'ml';
                if (densityInfo) densityInfo.textContent = 'Liquid Bottle';
                if (catSelect) catSelect.value = 'Liquids';
            } else if (pType === 'pcs') {
                if (unitVolLabel) unitVolLabel.innerHTML = 'ចំនួនរាយ <span class="text-rose-500">*</span>';
                if (volumePerUnit) volumePerUnit.value = 1;
                if (unitVolSuffix) unitVolSuffix.textContent = 'pcs';
                if (baseUnitDisplay) baseUnitDisplay.textContent = 'pcs';
                if (densityInfo) densityInfo.textContent = 'Single Unit';
            }
        }

        function setAddCurrency(curr) {
            const currInput = document.getElementById('addCostCurrency');
            const suffix = document.getElementById('totalCostSuffix');
            const btnUsd = document.getElementById('currBtnUsd');
            const btnKhr = document.getElementById('currBtnKhr');
            const costInput = document.getElementById('addTotalCostInput');

            if (!currInput || !costInput) return;

            if (curr === 'KHR') {
                currInput.value = 'KHR';
                if (suffix) suffix.textContent = '៛ KHR';
                if (btnKhr) {
                    btnKhr.className = 'px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-[#10b981] text-white shadow-xs cursor-pointer transition-all';
                }
                if (btnUsd) {
                    btnUsd.className = 'px-2 py-0.5 rounded-md text-[10px] font-semibold text-slate-600 dark:text-[#8e8e9f] hover:text-slate-900 dark:hover:text-white cursor-pointer transition-all';
                }
                
                const val = parseFloat(costInput.value) || 0;
                if (val > 0 && val < 500) {
                    costInput.value = Math.round(val * KHR_RATE);
                }
            } else {
                currInput.value = 'USD';
                if (suffix) suffix.textContent = '$ USD';
                if (btnUsd) {
                    btnUsd.className = 'px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-[#10b981] text-white shadow-xs cursor-pointer transition-all';
                }
                if (btnKhr) {
                    btnKhr.className = 'px-2 py-0.5 rounded-md text-[10px] font-semibold text-slate-600 dark:text-[#8e8e9f] hover:text-slate-900 dark:hover:text-white cursor-pointer transition-all';
                }
                
                const val = parseFloat(costInput.value) || 0;
                if (val >= 500) {
                    costInput.value = (val / KHR_RATE).toFixed(2);
                }
            }
            calculateLiveStock();
        }

        function calculateLiveStock() {
            const pTypeEl = document.getElementById('packageType');
            if (!pTypeEl) return;
            const pType = pTypeEl.value;
            const qty = parseFloat(document.getElementById('qtyInput')?.value) || 0;
            const volumePerUnit = parseFloat(document.getElementById('volumePerUnit')?.value) || 0;
            const subMultiplier = parseFloat(document.getElementById('subUnitMultiplier')?.value) || 1;
            const rawCost = parseFloat(document.getElementById('addTotalCostInput')?.value) || 0;
            const costCurrency = document.getElementById('addCostCurrency')?.value || 'USD';

            // Normalize cost to USD for database consistency
            const totalCostUsd = costCurrency === 'KHR' ? (rawCost / KHR_RATE) : rawCost;
            const totalCostKhr = costCurrency === 'KHR' ? rawCost : (rawCost * KHR_RATE);

            let totalUnits = qty;
            let totalBaseStock = 0;
            let baseUnit = 'ml';
            let unitSuffix = ' ឯកតា';

            const volSuffixEl = document.getElementById('unitVolSuffix');
            const volSuffixText = volSuffixEl ? volSuffixEl.textContent.trim() : 'ml';

            if (['case', 'box'].includes(pType)) {
                totalUnits = qty * subMultiplier;
                totalBaseStock = totalUnits * volumePerUnit;
                unitSuffix = (pType === 'case') ? ' កំប៉ុង/ដប' : ' កញ្ចប់';
                baseUnit = volSuffixText === 'g' ? 'g' : (volSuffixText === 'pcs' ? 'pcs' : 'ml');
            } else if (pType === 'bag') {
                totalUnits = qty;
                totalBaseStock = qty * subMultiplier * 1000; // kg -> g
                unitSuffix = ' បាវ';
                baseUnit = 'g';
            } else if (pType === 'gallon') {
                totalUnits = qty;
                totalBaseStock = qty * subMultiplier * 1000; // L -> ml
                unitSuffix = ' ហ្គាឡុង';
                baseUnit = 'ml';
            } else if (['sleeve', 'pack', 'dozen'].includes(pType)) {
                totalUnits = qty;
                totalBaseStock = qty * volumePerUnit;
                unitSuffix = (pType === 'sleeve') ? ' ដើម' : (pType === 'pack' ? ' កញ្ចប់' : ' ឡូ');
                baseUnit = 'pcs';
            } else if (['kg', 'g'].includes(pType)) {
                totalUnits = qty;
                totalBaseStock = (pType === 'kg') ? (qty * 1000) : qty;
                unitSuffix = (pType === 'kg') ? ' kg' : ' g';
                baseUnit = 'g';
            } else if (['L', 'ml'].includes(pType)) {
                totalUnits = qty;
                totalBaseStock = (pType === 'L') ? (qty * 1000) : qty;
                unitSuffix = (pType === 'L') ? ' L' : ' ml';
                baseUnit = 'ml';
            } else if (pType === 'can') {
                totalUnits = qty;
                totalBaseStock = qty * volumePerUnit;
                unitSuffix = ' កំប៉ុង';
                baseUnit = 'ml';
            } else if (pType === 'bottle') {
                totalUnits = qty;
                totalBaseStock = qty * volumePerUnit;
                unitSuffix = ' ដប';
                baseUnit = 'ml';
            } else if (pType === 'pcs') {
                totalUnits = qty;
                totalBaseStock = qty;
                unitSuffix = ' pcs';
                baseUnit = 'pcs';
            }

            const costPerBaseUsd = totalBaseStock > 0 ? (totalCostUsd / totalBaseStock) : 0;
            const costPerBaseKhr = costPerBaseUsd * KHR_RATE;
            const khrCostText = costPerBaseKhr >= 10 ? Math.round(costPerBaseKhr).toLocaleString('en-US') : (costPerBaseKhr > 0 ? costPerBaseKhr.toFixed(2) : '0');

            // Render live stats
            const resUnitHeader = document.getElementById('resUnitHeader');
            const resTotalUnits = document.getElementById('resTotalUnits');
            const resTotalMl = document.getElementById('resTotalMl');
            const resTotalG = document.getElementById('resTotalG');
            const resCostBreakdown = document.getElementById('resCostBreakdown');
            const formulaPreview = document.getElementById('formulaPreview');

            if (resUnitHeader) resUnitHeader.textContent = `ចំនួន (${pType})`;
            if (resTotalUnits) resTotalUnits.textContent = `${formatNumber(totalUnits, 0)}${unitSuffix}`;
            
            if (baseUnit === 'pcs') {
                if (resTotalMl) resTotalMl.textContent = `${formatNumber(totalBaseStock, 0)} pcs`;
                if (resTotalG) resTotalG.textContent = `N/A`;
            } else if (baseUnit === 'ml') {
                if (resTotalMl) resTotalMl.textContent = `${formatNumber(totalBaseStock, 0)} ml`;
                if (resTotalG) resTotalG.textContent = `${formatNumber(totalBaseStock, 0)} g`;
            } else {
                if (resTotalMl) resTotalMl.textContent = `N/A`;
                if (resTotalG) resTotalG.textContent = `${formatNumber(totalBaseStock, 0)} g`;
            }

            if (resCostBreakdown) {
                const usdText = costPerBaseUsd < 0.01 && costPerBaseUsd > 0 ? costPerBaseUsd.toFixed(4) : costPerBaseUsd.toFixed(2);
                resCostBreakdown.innerHTML = `$${usdText} <span class="text-[11px] opacity-80 font-bold block">(≈ ${khrCostText} ៛)</span>`;
            }

            if (formulaPreview) {
                formulaPreview.textContent = `${qty} ${pType} = ${formatNumber(totalBaseStock, 0)} ${baseUnit}`;
            }

            // Sync hidden inputs for backend submission
            const qtyHidden = document.getElementById('addQuantityHidden');
            const unitHidden = document.getElementById('addUnitHidden');
            const costHidden = document.getElementById('addCostUnitHidden');

            if (qtyHidden) qtyHidden.value = totalBaseStock;
            if (unitHidden) unitHidden.value = baseUnit;
            if (costHidden) costHidden.value = costPerBaseUsd.toFixed(6);

            return {
                totalUnits,
                totalBaseStock,
                baseUnit,
                costPerBaseUsd,
                costPerBaseKhr,
                pType,
                unitSuffix
            };
        }

        // ── Modal Actions ──
        function openAddStockModal() {
            const form = document.getElementById('addStockForm');
            if (form) form.reset();
            const alertBox = document.getElementById('addIngredientDupAlert');
            if (alertBox) alertBox.classList.add('hidden');
            const nameInput = document.getElementById('addIngredientName');
            if (nameInput) nameInput.classList.remove('border-rose-500');
            const submitBtn = document.getElementById('addStockSubmitBtn');
            if (submitBtn) submitBtn.disabled = false;
            
            handlePackageTypeChange();
            calculateLiveStock();
            openModal('addStockModal');
        }

        function checkAddIngredientDuplicate() {
            const input = document.getElementById('addIngredientName');
            if (!input) return false;
            const val = input.value.trim().toLowerCase();
            const alertBox = document.getElementById('addIngredientDupAlert');
            const submitBtn = document.getElementById('addStockSubmitBtn');
            if (!val) {
                if (alertBox) alertBox.classList.add('hidden');
                input.classList.remove('border-rose-500');
                if (submitBtn) submitBtn.disabled = false;
                return false;
            }
            const items = (typeof stockItemsData !== 'undefined' && Array.isArray(stockItemsData)) ? stockItemsData : [];
            const match = items.find(item => item.item_name && item.item_name.trim().toLowerCase() === val && (item.is_active == 1 || item.is_active === undefined));
            if (match) {
                if (alertBox) {
                    alertBox.querySelector('span').textContent = `⚠️ An ingredient named "${match.item_name}" already exists (${match.quantity} ${match.unit} in stock).`;
                    alertBox.classList.remove('hidden');
                }
                input.classList.add('border-rose-500');
                return true;
            } else {
                if (alertBox) alertBox.classList.add('hidden');
                input.classList.remove('border-rose-500');
                if (submitBtn) submitBtn.disabled = false;
                return false;
            }
        }

        async function handleAddStock(e) {
            e.preventDefault();
            calculateLiveStock();

            if (checkAddIngredientDuplicate()) {
                showToast('An ingredient with this name already exists. Cannot add duplicate.', 'error');
                const nameInput = document.getElementById('addIngredientName');
                if (nameInput) nameInput.focus();
                return;
            }

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
                    if (result.message && result.message.toLowerCase().includes('already exists')) {
                        const alertBox = document.getElementById('addIngredientDupAlert');
                        if (alertBox) {
                            alertBox.querySelector('span').textContent = '⚠️ ' + result.message;
                            alertBox.classList.remove('hidden');
                        }
                        const nameInput = document.getElementById('addIngredientName');
                        if (nameInput) nameInput.classList.add('border-rose-500');
                    }
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
            if (form) form.reset();
            const select = document.getElementById('restockItemSelect');
            if (preSelectedId && select) {
                select.value = preSelectedId;
            }
            handleRestockItemChange();
            openModal('restockModal');
        }

        function handleRestockItemChange() {
            const select = document.getElementById('restockItemSelect');
            if (!select) return;
            const selectedOpt = select.selectedOptions[0];
            const baseUnit = selectedOpt && selectedOpt.dataset.unit ? selectedOpt.dataset.unit.toLowerCase() : 'g';
            const pUnitSelect = document.getElementById('restockPurchaseUnit');

            if (pUnitSelect) {
                if (baseUnit === 'ml') {
                    pUnitSelect.value = 'L';
                } else if (baseUnit === 'pcs') {
                    pUnitSelect.value = 'sleeve';
                } else {
                    pUnitSelect.value = 'kg';
                }
            }
            handleRestockUnitChange();
        }

        function handleRestockUnitChange() {
            const pType = document.getElementById('restockPurchaseUnit')?.value || 'kg';
            const subUnitConfig = document.getElementById('restockSubUnitConfig');
            const subUnitLabel = document.getElementById('restockSubUnitLabel');
            const subUnitHint = document.getElementById('restockSubUnitHint');
            const subUnitMultiplier = document.getElementById('restockSubUnitMultiplier');
            const unitVolLabel = document.getElementById('restockUnitVolLabel');
            const unitVolSuffix = document.getElementById('restockUnitVolSuffix');
            const volumePerUnit = document.getElementById('restockVolumePerUnit');

            if (!subUnitConfig) return;
            subUnitConfig.classList.add('hidden');

            const select = document.getElementById('restockItemSelect');
            const selectedOpt = select ? select.selectedOptions[0] : null;
            const itemBaseUnit = selectedOpt && selectedOpt.dataset.unit ? selectedOpt.dataset.unit.toLowerCase() : 'g';

            if (pType === 'case') {
                subUnitConfig.classList.remove('hidden');
                if (subUnitLabel) subUnitLabel.textContent = 'ចំនួន កំប៉ុង/ដប/កញ្ចប់ ក្នុង ១ កេស';
                if (subUnitHint) subUnitHint.innerHTML = '💡 ឧ. សូដា ១ កេសមាន ២៤ កំប៉ុង, កែវ ១ កេសមាន ២០ ដើម';
                if (subUnitMultiplier && (!subUnitMultiplier.value || subUnitMultiplier.value === '1')) subUnitMultiplier.value = 24;
                if (unitVolLabel) unitVolLabel.innerHTML = 'ចំណុះ/ទម្ងន់ក្នុង ១ ឯកតារាយ <span class="text-rose-500">*</span>';
                if (unitVolSuffix) unitVolSuffix.textContent = itemBaseUnit === 'g' ? 'g' : (itemBaseUnit === 'pcs' ? 'pcs' : 'ml');
                if (volumePerUnit && (!volumePerUnit.value || volumePerUnit.value === '1000')) volumePerUnit.value = (itemBaseUnit === 'ml' ? 320 : (itemBaseUnit === 'g' ? 250 : 50));
            } else if (pType === 'box') {
                subUnitConfig.classList.remove('hidden');
                if (subUnitLabel) subUnitLabel.textContent = 'ចំនួនកញ្ចប់/ដប ក្នុង ១ ប្រអប់';
                if (subUnitHint) subUnitHint.innerHTML = '💡 ឧ. តែ ១ ប្រអប់មាន ២៥ កញ្ចប់តូច (Teabags)';
                if (subUnitMultiplier && (!subUnitMultiplier.value || subUnitMultiplier.value === '1')) subUnitMultiplier.value = 25;
                if (unitVolLabel) unitVolLabel.innerHTML = 'ទម្ងន់/ចំណុះ ក្នុង ១ កញ្ចប់ <span class="text-rose-500">*</span>';
                if (unitVolSuffix) unitVolSuffix.textContent = itemBaseUnit;
                if (volumePerUnit) volumePerUnit.value = 20;
            } else if (pType === 'bag') {
                subUnitConfig.classList.remove('hidden');
                if (subUnitLabel) subUnitLabel.textContent = 'ទម្ងន់ក្នុង ១ បាវ (kg/Bag)';
                if (subUnitHint) subUnitHint.innerHTML = '💡 ឧ. ទឹកកកអនាម័យ ១ បាវ = 20kg, ស្ករស ១ បាវ = 50kg';
                if (subUnitMultiplier && (!subUnitMultiplier.value || subUnitMultiplier.value === '1')) subUnitMultiplier.value = 20;
                if (unitVolLabel) unitVolLabel.innerHTML = 'មេគុណបំប្លែងទៅជា g <span class="text-rose-500">*</span>';
                if (unitVolSuffix) unitVolSuffix.textContent = 'g';
                if (volumePerUnit) volumePerUnit.value = 1000;
            } else if (pType === 'gallon') {
                subUnitConfig.classList.remove('hidden');
                if (subUnitLabel) subUnitLabel.textContent = 'ចំណុះក្នុង ១ ហ្គាឡុង (Liters/Gal)';
                if (subUnitHint) subUnitHint.innerHTML = '💡 ឧ. ទឹកស៊ីរ៉ូ ឬទឹកដោះគោ ១ ហ្គាឡុង = 5L (5,000ml)';
                if (subUnitMultiplier && (!subUnitMultiplier.value || subUnitMultiplier.value === '1')) subUnitMultiplier.value = 5;
                if (unitVolLabel) unitVolLabel.innerHTML = 'មេគុណបំប្លែងទៅជា ml <span class="text-rose-500">*</span>';
                if (unitVolSuffix) unitVolSuffix.textContent = 'ml';
                if (volumePerUnit) volumePerUnit.value = 1000;
            } else if (pType === 'sleeve') {
                if (unitVolLabel) unitVolLabel.innerHTML = 'ចំនួនក្នុង ១ ដើម <span class="text-rose-500">*</span>';
                if (volumePerUnit) volumePerUnit.value = 50;
                if (unitVolSuffix) unitVolSuffix.textContent = 'pcs';
            } else if (pType === 'pack') {
                if (unitVolLabel) unitVolLabel.innerHTML = 'ចំនួនក្នុង ១ កញ្ចប់ <span class="text-rose-500">*</span>';
                if (volumePerUnit) volumePerUnit.value = 100;
                if (unitVolSuffix) unitVolSuffix.textContent = 'pcs';
            } else if (pType === 'dozen') {
                if (unitVolLabel) unitVolLabel.innerHTML = 'ចំនួនក្នុង ១ ឡូ <span class="text-rose-500">*</span>';
                if (volumePerUnit) volumePerUnit.value = 12;
                if (unitVolSuffix) unitVolSuffix.textContent = 'pcs';
            } else if (pType === 'kg') {
                if (unitVolLabel) unitVolLabel.innerHTML = 'ចំនួនក្រាមក្នុង ១ kg <span class="text-rose-500">*</span>';
                if (volumePerUnit) volumePerUnit.value = 1000;
                if (unitVolSuffix) unitVolSuffix.textContent = 'g';
            } else if (pType === 'g') {
                if (unitVolLabel) unitVolLabel.innerHTML = 'ទម្ងន់ (g) <span class="text-rose-500">*</span>';
                if (volumePerUnit) volumePerUnit.value = 1;
                if (unitVolSuffix) unitVolSuffix.textContent = 'g';
            } else if (pType === 'L') {
                if (unitVolLabel) unitVolLabel.innerHTML = 'ចំនួន ml ក្នុង ១ លីត្រ <span class="text-rose-500">*</span>';
                if (volumePerUnit) volumePerUnit.value = 1000;
                if (unitVolSuffix) unitVolSuffix.textContent = 'ml';
            } else if (pType === 'ml') {
                if (unitVolLabel) unitVolLabel.innerHTML = 'ចំណុះ (ml) <span class="text-rose-500">*</span>';
                if (volumePerUnit) volumePerUnit.value = 1;
                if (unitVolSuffix) unitVolSuffix.textContent = 'ml';
            } else if (pType === 'can') {
                if (unitVolLabel) unitVolLabel.innerHTML = 'ចំណុះក្នុង ១ កំប៉ុង <span class="text-rose-500">*</span>';
                if (volumePerUnit) volumePerUnit.value = 320;
                if (unitVolSuffix) unitVolSuffix.textContent = 'ml';
            } else if (pType === 'bottle') {
                if (unitVolLabel) unitVolLabel.innerHTML = 'ចំណុះក្នុង ១ ដប <span class="text-rose-500">*</span>';
                if (volumePerUnit) volumePerUnit.value = 330;
                if (unitVolSuffix) unitVolSuffix.textContent = 'ml';
            } else if (pType === 'pcs') {
                if (unitVolLabel) unitVolLabel.innerHTML = 'ចំនួនរាយ <span class="text-rose-500">*</span>';
                if (volumePerUnit) volumePerUnit.value = 1;
                if (unitVolSuffix) unitVolSuffix.textContent = 'pcs';
            }
            calculateRestockTotal();
        }

        function setRestockCurrency(curr) {
            const currInput = document.getElementById('restockCostCurrency');
            const suffix = document.getElementById('restockCostSuffix');
            const btnUsd = document.getElementById('restockCurrBtnUsd');
            const btnKhr = document.getElementById('restockCurrBtnKhr');
            const costInput = document.getElementById('restockTotalCostInput');

            if (!currInput || !costInput) return;

            if (curr === 'KHR') {
                currInput.value = 'KHR';
                if (suffix) suffix.textContent = '៛ KHR';
                if (btnKhr) {
                    btnKhr.className = 'px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-[#059669] text-white shadow-xs cursor-pointer transition-all';
                }
                if (btnUsd) {
                    btnUsd.className = 'px-2 py-0.5 rounded-md text-[10px] font-semibold text-slate-500 hover:text-slate-900 dark:text-[#8e8e9f] dark:hover:text-white cursor-pointer transition-all';
                }
                
                const val = parseFloat(costInput.value) || 0;
                if (val > 0 && val < 500) {
                    costInput.value = Math.round(val * KHR_RATE);
                }
            } else {
                currInput.value = 'USD';
                if (suffix) suffix.textContent = '$ USD';
                if (btnUsd) {
                    btnUsd.className = 'px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-[#059669] text-white shadow-xs cursor-pointer transition-all';
                }
                if (btnKhr) {
                    btnKhr.className = 'px-2 py-0.5 rounded-md text-[10px] font-semibold text-slate-500 hover:text-slate-900 dark:text-[#8e8e9f] dark:hover:text-white cursor-pointer transition-all';
                }
                
                const val = parseFloat(costInput.value) || 0;
                if (val >= 500) {
                    costInput.value = (val / KHR_RATE).toFixed(2);
                }
            }
            calculateRestockTotal();
        }

        function calculateRestockTotal() {
            const select = document.getElementById('restockItemSelect');
            if (!select) return;
            const selectedOpt = select.selectedOptions[0];
            const onHand = selectedOpt && selectedOpt.dataset.qty ? parseFloat(selectedOpt.dataset.qty) : 0;
            const currentCost = selectedOpt && selectedOpt.dataset.cost ? parseFloat(selectedOpt.dataset.cost) : 0;
            const itemBaseUnit = selectedOpt && selectedOpt.dataset.unit ? selectedOpt.dataset.unit.toLowerCase() : 'g';

            const pType = document.getElementById('restockPurchaseUnit')?.value || 'kg';
            const qty = parseFloat(document.getElementById('restockQtyInput')?.value) || 0;
            const volumePerUnit = parseFloat(document.getElementById('restockVolumePerUnit')?.value) || 0;
            const subMultiplier = parseFloat(document.getElementById('restockSubUnitMultiplier')?.value) || 1;

            const rawCost = parseFloat(document.getElementById('restockTotalCostInput')?.value) || 0;
            const costCurr = document.getElementById('restockCostCurrency')?.value || 'KHR';
            const totalCostUsd = costCurr === 'KHR' ? (rawCost / KHR_RATE) : rawCost;

            let totalUnits = qty;
            let baseAdded = 0;

            if (['case', 'box'].includes(pType)) {
                totalUnits = qty * subMultiplier;
                baseAdded = totalUnits * volumePerUnit;
            } else if (pType === 'bag') {
                totalUnits = qty;
                baseAdded = qty * subMultiplier * 1000; // kg -> g
            } else if (pType === 'gallon') {
                totalUnits = qty;
                baseAdded = qty * subMultiplier * 1000; // L -> ml
            } else if (['sleeve', 'pack', 'dozen'].includes(pType)) {
                totalUnits = qty;
                baseAdded = qty * volumePerUnit;
            } else if (['kg', 'g', 'L', 'ml'].includes(pType)) {
                totalUnits = qty;
                baseAdded = ['kg', 'L'].includes(pType) ? (qty * 1000) : qty;
            } else if (['can', 'bottle'].includes(pType)) {
                totalUnits = qty;
                baseAdded = qty * volumePerUnit;
            } else {
                totalUnits = qty;
                baseAdded = qty;
            }

            let newUnitCostUsd = currentCost;
            if (rawCost > 0 && baseAdded > 0) {
                newUnitCostUsd = totalCostUsd / baseAdded;
            }
            const newUnitCostKhr = newUnitCostUsd * KHR_RATE;
            const newKhrFormatted = newUnitCostKhr >= 10 ? Math.round(newUnitCostKhr).toLocaleString('en-US') : (newUnitCostKhr > 0 ? newUnitCostKhr.toFixed(2) : '0');

            const addedEl = document.getElementById('restockAddedBaseUnits');
            const currentEl = document.getElementById('restockCurrentQty');
            const newQtyEl = document.getElementById('restockNewQty');
            const newCostEl = document.getElementById('restockNewUnitCost');

            if (addedEl) addedEl.textContent = `+${formatNumber(baseAdded)} ${itemBaseUnit}`;
            if (currentEl) currentEl.textContent = `${formatNumber(onHand)} ${itemBaseUnit}`;
            if (newQtyEl) newQtyEl.textContent = `${formatNumber(onHand + baseAdded)} ${itemBaseUnit}`;
            if (newCostEl) {
                const usdTxt = newUnitCostUsd < 0.01 && newUnitCostUsd > 0 ? newUnitCostUsd.toFixed(4) : newUnitCostUsd.toFixed(2);
                newCostEl.innerHTML = `$${usdTxt} / ${itemBaseUnit} <span class="text-xs font-bold ml-1 opacity-90">(≈ ${newKhrFormatted} ៛)</span>`;
            }

            const multHidden = document.getElementById('restockMultiplierHidden');
            if (multHidden) {
                multHidden.value = qty > 0 ? (baseAdded / qty) : 1;
            }
            const costHidden = document.getElementById('restockCostUnitHidden');
            if (costHidden) costHidden.value = rawCost > 0 ? newUnitCostUsd.toFixed(6) : '';
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
                btn.textContent = 'បញ្ជាក់ការថែមស្តុក (Confirm Restock)';
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
                document.getElementById('editCostUnit').value = parseFloat(item.cost_per_unit || 0).toFixed(4);
                document.getElementById('editNotes').value = item.notes || '';

                handleUnitChange('edit');
                syncCostInputs('edit', 'base');

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
            container.innerHTML = `<div class="text-center py-8 text-[#8e8e9f]"><i class="fa-solid fa-spinner fa-spin text-2xl text-[#10b981] mb-2"></i><p>Loading audit ledger...</p></div>`;

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

        // ══════════════════════════════════════════════════════════════
        // ── PACKAGING SET COST CONTROLLER ──
        // ══════════════════════════════════════════════════════════════
        let packagingItems = [];

        async function openPackagingCostModal() {
            openModal('packagingCostModal');
            const body = document.getElementById('packagingRowsBody');
            body.innerHTML = `<tr><td colspan="5" class="py-6 text-center text-[#8e8e9f]"><i class="fa-solid fa-spinner fa-spin mr-1"></i> Loading packaging set...</td></tr>`;

            try {
                const res = await fetch('ingredients.php?action=get_packaging_set');
                const data = await res.json();
                if (data.success && Array.isArray(data.items)) {
                    packagingItems = data.items;
                } else {
                    packagingItems = [
                        { name: 'កែវ (Plastic / Paper Cup)', cost: 0.0450, qty: 1 },
                        { name: 'គម្របកែវ (Cup Lid)', cost: 0.0180, qty: 1 },
                        { name: 'បំពង់បឺត (Straw)', cost: 0.0080, qty: 1 },
                        { name: 'ស្រោមដៃកែវ / ថង់យួរ (Sleeve / Carrier)', cost: 0.0150, qty: 1 },
                        { name: 'ស្ទីគ័រ / ក្រដាសជូត (Logo Sticker / Napkin)', cost: 0.0060, qty: 1 }
                    ];
                }
                renderPackagingRows();
            } catch (err) {
                console.error(err);
                renderPackagingRows();
            }
        }

        function renderPackagingRows() {
            const body = document.getElementById('packagingRowsBody');
            if (!body) return;

            if (!packagingItems || packagingItems.length === 0) {
                body.innerHTML = `<tr><td colspan="5" class="py-6 text-center text-[#727282] italic">No packaging components added yet. Click "+ Add Component" above.</td></tr>`;
                recalculatePackagingTotals();
                return;
            }

            let html = '';
            packagingItems.forEach((item, idx) => {
                const cost = parseFloat(item.cost) || 0;
                const qty = parseFloat(item.qty) || 1;
                const sub = cost * qty;
                const khr = cost * KHR_RATE;
                const khrText = khr >= 10 ? Math.round(khr).toLocaleString('en-US') : (khr > 0 ? (Number.isInteger(khr) ? khr : khr.toFixed(1)) : '0');

                html += `
                <tr class="pkg-row group transition-colors" data-idx="${idx}">
                    <td class="py-2.5 px-3">
                        <input type="text" 
                               value="${escapeHtml(item.name || '')}" 
                               placeholder="e.g. កែវ Cup" 
                               oninput="updatePackagingItem(${idx}, 'name', this.value)" 
                               class="pkg-input w-full px-2.5 py-1.5 rounded-lg text-xs">
                    </td>
                    <td class="py-2.5 px-3">
                        <div class="relative">
                            <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-[#8e8e9f] font-bold">$</span>
                            <input type="number" 
                                   step="0.0001" 
                                   min="0" 
                                   value="${cost > 0 ? cost : ''}" 
                                   placeholder="0.0000" 
                                   oninput="updatePackagingItem(${idx}, 'cost', this.value)" 
                                   class="pkg-input w-full pl-6 pr-2 py-1.5 rounded-lg text-xs font-mono">
                        </div>
                        <div class="text-[10px] text-amber-500 font-semibold mt-0.5 pl-1">≈ ${khrText} ៛</div>
                    </td>
                    <td class="py-2.5 px-3 text-center">
                        <input type="number" 
                               step="any" 
                               min="0.1" 
                               value="${qty}" 
                               oninput="updatePackagingItem(${idx}, 'qty', this.value)" 
                               class="pkg-input w-16 px-2 py-1.5 rounded-lg text-xs text-center font-mono">
                    </td>
                    <td class="py-2.5 px-3 text-right font-mono">
                        <div class="font-bold text-emerald-500 text-xs">$${sub.toFixed(4)}</div>
                    </td>
                    <td class="py-2.5 px-2 text-center">
                        <button type="button" 
                                onclick="removePackagingRow(${idx})" 
                                class="p-1.5 rounded-lg text-[#8e8e9f] hover:text-rose-500 hover:bg-rose-500/10 transition-all cursor-pointer" 
                                title="Remove">
                            <i class="fa-solid fa-trash-can text-xs"></i>
                        </button>
                    </td>
                </tr>
                `;
            });

            body.innerHTML = html;
            recalculatePackagingTotals();
        }

        function updatePackagingItem(index, field, value) {
            if (!packagingItems[index]) return;
            if (field === 'name') {
                packagingItems[index].name = value;
            } else if (field === 'cost') {
                packagingItems[index].cost = parseFloat(value) || 0;
            } else if (field === 'qty') {
                packagingItems[index].qty = parseFloat(value) || 0;
            }
            recalculatePackagingTotals();
        }

        function addPackagingRow() {
            packagingItems.push({ name: '', cost: 0.0100, qty: 1 });
            renderPackagingRows();
        }

        function removePackagingRow(index) {
            packagingItems.splice(index, 1);
            renderPackagingRows();
        }

        function recalculatePackagingTotals() {
            let totalPerCup = 0;
            packagingItems.forEach(item => {
                const cost = parseFloat(item.cost) || 0;
                const qty = parseFloat(item.qty) || 0;
                totalPerCup += (cost * qty);
            });

            const khrTotal = totalPerCup * KHR_RATE;
            const khrText = khrTotal >= 10 ? Math.round(khrTotal).toLocaleString('en-US') : (khrTotal > 0 ? (Number.isInteger(khrTotal) ? khrTotal : khrTotal.toFixed(1)) : '0');

            const totalUsdEl = document.getElementById('pkgTotalPerCupUsd');
            const totalKhrEl = document.getElementById('pkgTotalPerCupKhr');
            if (totalUsdEl) totalUsdEl.textContent = `$${totalPerCup.toFixed(4)}`;
            if (totalKhrEl) totalKhrEl.textContent = `≈ ${khrText} ៛ (Riel)`;

            const val100 = totalPerCup * 100;
            const khr100 = Math.round(val100 * KHR_RATE).toLocaleString('en-US');
            const el100 = document.getElementById('pkg100CupsVal');
            if (el100) el100.innerHTML = `$${val100.toFixed(2)} <span class="text-[11px] font-normal text-amber-500">(≈ ${khr100} ៛)</span>`;

            const val1000 = totalPerCup * 1000;
            const khr1000 = Math.round(val1000 * KHR_RATE).toLocaleString('en-US');
            const el1000 = document.getElementById('pkg1000CupsVal');
            if (el1000) el1000.innerHTML = `$${val1000.toFixed(2)} <span class="text-[11px] font-normal text-amber-500">(≈ ${khr1000} ៛)</span>`;
        }

        async function savePackagingSet() {
            const btn = document.getElementById('savePackagingSetBtn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin mr-1"></i> Saving...`;
            }

            try {
                const csrfMeta = document.querySelector('meta[name="csrf-token"]');
                const token = csrfMeta ? csrfMeta.getAttribute('content') : (typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '');

                const formData = new FormData();
                formData.append('action', 'save_packaging_set');
                formData.append('csrf_token', token);
                formData.append('items', JSON.stringify(packagingItems));

                const res = await fetch('ingredients.php', {
                    method: 'POST',
                    body: formData
                });

                const text = await res.text();
                let data = null;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('Non-JSON Server response:', text);
                    showToast('Server response error: ' + (text.length > 120 ? text.substring(0, 120) + '...' : text), 'error');
                    return;
                }

                if (data && data.success) {
                    showToast(data.message || 'Packaging set cost saved successfully!', 'success');
                    closeModal('packagingCostModal');
                    loadStockTable();
                } else {
                    showToast((data && data.message) ? data.message : 'Failed to save packaging set.', 'error');
                }
            } catch (err) {
                console.error('Fetch error:', err);
                showToast('Network error while saving packaging set: ' + (err.message || ''), 'error');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = `<i class="fa-solid fa-floppy-disk mr-1"></i> <?= __('packaging_save_btn', 'Save Packaging Set Cost') ?>`;
                }
            }
        }

        // ══════════════════════════════════════════════════════════════
        // ── INGREDIENT DEDUCTION & USAGE HISTORY CONTROLLER ──
        // ══════════════════════════════════════════════════════════════
        let currentHistoryData = {
            ingredient: null,
            logs: [],
            total_deducted: 0,
            total_restocked: 0,
            total_orders: 0
        };
        let currentHistorySearch = '';

        async function openIngredientHistoryModal(itemId, itemName) {
            openModal('ingredientHistoryModal');
            
            // Set initial titles
            const titleEl = document.getElementById('historyIngTitleName');
            if (titleEl) titleEl.textContent = itemName || '--';
            const badgeEl = document.getElementById('historyIngUnitBadge');
            if (badgeEl) badgeEl.textContent = '...';
            
            // Reset search
            currentHistorySearch = '';
            const searchInput = document.getElementById('historySearchInput');
            if (searchInput) searchInput.value = '';

            const body = document.getElementById('historyModalBody');
            if (body) {
                body.innerHTML = `
                    <div class="text-center py-12 text-[#8e8e9f]">
                        <i class="fa-solid fa-spinner fa-spin text-2xl text-[#10b981] mb-2"></i>
                        <p class="text-xs">Loading deduction logs for ${escapeHtml(itemName)}...</p>
                    </div>
                `;
            }

            try {
                const res = await fetch(`ingredients.php?action=get_ingredient_history&item_id=${itemId}`);
                const data = await res.json();

                if (!data.success) {
                    if (body) body.innerHTML = `<div class="text-center py-12 text-rose-400 text-xs"><i class="fa-solid fa-triangle-exclamation text-2xl mb-2"></i><p>${escapeHtml(data.message || 'Failed to load history.')}</p></div>`;
                    return;
                }

                currentHistoryData = data;
                const ing = data.ingredient || {};
                const unit = ing.unit || '';

                // Populate Header
                if (titleEl) titleEl.textContent = ing.item_name || itemName;
                if (badgeEl) badgeEl.textContent = unit ? `Unit: ${unit}` : '--';

                renderIngredientHistoryLogs();
            } catch (err) {
                console.error(err);
                if (body) body.innerHTML = `<div class="text-center py-12 text-rose-400 text-xs"><i class="fa-solid fa-triangle-exclamation text-2xl mb-2"></i><p>Server connection error.</p></div>`;
            }
        }

        function handleHistorySearch(val) {
            currentHistorySearch = (val || '').toLowerCase().trim();
            renderIngredientHistoryLogs();
        }

        function renderIngredientHistoryLogs() {
            const body = document.getElementById('historyModalBody');
            const countText = document.getElementById('historyLogCountText');
            if (!body) return;

            const allLogs = currentHistoryData.logs || [];
            const ing = currentHistoryData.ingredient || {};
            const unit = ing.unit || '';

            // Filter by Search Query
            let filtered = allLogs;
            if (currentHistorySearch) {
                filtered = filtered.filter(l => {
                    const orderStr = (l.order_id ? `order #${l.order_id}` : '').toLowerCase();
                    const prodStr = (l.product_name || '').toLowerCase();
                    const notesStr = (l.notes || '').toLowerCase();
                    return orderStr.includes(currentHistorySearch) || 
                           prodStr.includes(currentHistorySearch) || 
                           notesStr.includes(currentHistorySearch);
                });
            }

            if (countText) {
                countText.textContent = `Showing ${filtered.length} of ${allLogs.length} logged entries`;
            }

            if (filtered.length === 0) {
                body.innerHTML = `
                    <div class="text-center py-12 text-[#7d7d8e]">
                        <div class="w-12 h-12 rounded-2xl bg-[#1f1f26] text-[#7d7d8e] flex items-center justify-center text-xl mx-auto mb-3">
                            <i class="fa-solid fa-receipt"></i>
                        </div>
                        <p class="text-xs font-semibold text-[#b4b4c2]">${escapeHtml(I18N.noHistoryLogs || 'No logs found')}</p>
                        <p class="text-[11px] text-[#6e6e7e] mt-1">No matching log entries found for this filter.</p>
                    </div>
                `;
                return;
            }

            let html = `
                <div class="hist-table-wrap overflow-x-auto rounded-xl shadow-sm">
                    <table class="w-full text-xs text-left">
                        <thead class="hist-thead uppercase text-[10px] tracking-wider">
                            <tr>
                                <th class="py-3 px-4 w-40">${escapeHtml(I18N.date || 'Date & Time')}</th>
                                <th class="py-3 px-4 w-32">#Order</th>
                                <th class="py-3 px-4 font-semibold">${escapeHtml(I18N.product || 'Product Name')}</th>
                                <th class="py-3 px-4 text-right w-36 font-semibold">${escapeHtml(I18N.qtyChange || 'Qty Deduct')}</th>
                            </tr>
                        </thead>
                        <tbody class="hist-tbody divide-y divide-inherit">
            `;

            filtered.forEach(log => {
                const chg = parseFloat(log.quantity_changed) || 0;
                const notes = log.notes || '';
                const dateStr = log.created_at || '';
                const prodName = log.product_name || (notes.match(/Used for (.*?)(?: \[|\(|$)/)?.[1] || (notes.match(/Direct Drink Sale for (.*?)(?: \(|$)/)?.[1] || 'Product'));

                html += `
                    <tr class="hist-row">
                        <!-- 1. Date & Time -->
                        <td class="py-3 px-4 whitespace-nowrap">
                            <div class="hist-row-date text-xs font-bold">${escapeHtml(dateStr.split(' ')[0])}</div>
                            <div class="hist-row-time text-[11px]">${escapeHtml(dateStr.split(' ')[1] || '')}</div>
                        </td>

                        <!-- 2. #Order -->
                        <td class="py-3 px-4 whitespace-nowrap">
                            ${log.order_id ? `
                                <a href="view_order.php?order_id=${log.order_id}" target="_blank" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-sky-500/10 text-sky-500 hover:bg-sky-500/20 font-bold font-mono text-xs transition-colors">
                                    <span>Order #${log.order_id}</span>
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i>
                                </a>
                            ` : `<span class="text-[#8e8e9f] text-xs font-mono">--</span>`}
                        </td>

                        <!-- 3. Product Name -->
                        <td class="py-3 px-4">
                            <div class="hist-row-prod font-bold text-xs flex items-center gap-2">
                                <i class="fa-solid fa-mug-hot text-[#10b981] text-xs"></i>
                                <span>${escapeHtml(prodName)}</span>
                            </div>
                        </td>

                        <!-- 4. Qty Deduct -->
                        <td class="py-3 px-4 text-right whitespace-nowrap">
                            <span class="font-black font-mono text-xs ${chg < 0 ? 'text-rose-500' : 'text-emerald-500'}">
                                ${chg < 0 ? '-' : '+'}${formatNumber(Math.abs(chg))} ${escapeHtml(unit)}
                            </span>
                        </td>
                    </tr>
                `;
            });

            html += `
                        </tbody>
                    </table>
                </div>
            `;

            body.innerHTML = html;
        }

        document.addEventListener('DOMContentLoaded', function() {
            const addNameInput = document.getElementById('addIngredientName');
            if (addNameInput) {
                addNameInput.addEventListener('input', checkAddIngredientDuplicate);
            }
        });
    </script>
</body>
</html>
