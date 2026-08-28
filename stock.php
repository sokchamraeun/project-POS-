<?php
/**
 * Bird's Nest Coffee POS - Direct Drinks & Retail Stock Management (Cans & Bottles)
 * Full-stack PHP + PDO + MySQL + Tailwind CSS + Vanilla JS/AJAX
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/cloudinary_config.php';

// Access open for all authenticated staff
$_user_role = $_SESSION['role'] ?? 'staff';

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

    // Ensure selling price & box image columns exist
    try {
        $colCheck = $pdo->query("SHOW COLUMNS FROM stock_items LIKE 'selling_price_per_unit'")->fetch();
        if (!$colCheck) {
            $pdo->exec("ALTER TABLE stock_items ADD COLUMN selling_price_per_unit DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER cost_per_purchase_unit");
        }
        $colCheck2 = $pdo->query("SHOW COLUMNS FROM stock_items LIKE 'selling_price_per_box'")->fetch();
        if (!$colCheck2) {
            $pdo->exec("ALTER TABLE stock_items ADD COLUMN selling_price_per_box DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER selling_price_per_unit");
        }
        $colCheck3 = $pdo->query("SHOW COLUMNS FROM stock_items LIKE 'image_box'")->fetch();
        if (!$colCheck3) {
            $pdo->exec("ALTER TABLE stock_items ADD COLUMN image_box VARCHAR(255) NULL DEFAULT NULL AFTER image");
        }
        $colCheck4 = $pdo->query("SHOW COLUMNS FROM stock_items LIKE 'category_id'")->fetch();
        if (!$colCheck4) {
            $pdo->exec("ALTER TABLE stock_items ADD COLUMN category_id INT(11) NULL DEFAULT NULL AFTER category");
        }
        $colCheck5 = $pdo->query("SHOW COLUMNS FROM stock_restocks LIKE 'boxes_added'")->fetch();
        if (!$colCheck5) {
            $pdo->exec("ALTER TABLE stock_restocks ADD COLUMN boxes_added DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER quantity_added");
        }
        $colCheck6 = $pdo->query("SHOW COLUMNS FROM stock_restocks LIKE 'loose_added'")->fetch();
        if (!$colCheck6) {
            $pdo->exec("ALTER TABLE stock_restocks ADD COLUMN loose_added DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER boxes_added");
        }
    } catch (Exception $e) {
        // Table doesn't exist yet or already altered
    }
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
                'pack' => 'យួរ', 'packs' => 'យួរ',
                'package' => 'កញ្ចប់', 'packages' => 'កញ្ចប់',
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

if (!function_exists('getPackageUnitSuffixKm')) {
    function getPackageUnitSuffixKm(string $pUnit): string {
        $pUnit = strtolower(trim($pUnit));
        $map = [
            'box' => 'កេស', 'boxes' => 'កេស',
            'pack' => 'យួរ', 'packs' => 'យួរ',
            'package' => 'កញ្ចប់', 'packages' => 'កញ្ចប់',
            'carton' => 'កាតុង', 'cartons' => 'កាតុង',
            'case' => 'កេសធំ', 'cases' => 'កេសធំ',
            'dozen' => 'ឡូ', 'dozens' => 'ឡូ',
            'crate' => 'ស្នោ', 'crates' => 'ស្នោ'
        ];
        return $map[$pUnit] ?? 'កេស';
    }
}

if (!function_exists('getPackageUnitSuffixEn')) {
    function getPackageUnitSuffixEn(string $pUnit): string {
        $pUnit = strtolower(trim($pUnit));
        $map = [
            'box' => 'Box', 'boxes' => 'Box',
            'pack' => 'Pack', 'packs' => 'Pack',
            'package' => 'Package', 'packages' => 'Package',
            'carton' => 'Carton', 'cartons' => 'Carton',
            'case' => 'Case', 'cases' => 'Case',
            'dozen' => 'Dozen', 'dozens' => 'Dozen',
            'crate' => 'Crate', 'crates' => 'Crate'
        ];
        return $map[$pUnit] ?? 'Box';
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

            $sql = "SELECT s.*, 
                           COALESCE(s.category_id, p.category_id, 0) AS category_id,
                           COALESCE(NULLIF(s.image, ''), p.image, '') AS image,
                           COALESCE(NULLIF(s.selling_price_per_unit, 0), p.price, 0) AS selling_price_per_unit,
                           COALESCE(NULLIF(s.selling_price_per_box, 0), (COALESCE(NULLIF(s.selling_price_per_unit, 0), p.price, 0) * s.conversion_rate), 0) AS selling_price_per_box
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
            $stmt = $pdo->prepare("SELECT s.*, 
                           COALESCE(s.category_id, p.category_id, 0) AS category_id,
                           COALESCE(NULLIF(s.image, ''), p.image, '') AS image,
                           COALESCE(NULLIF(s.image_box, ''), '') AS image_box,
                           COALESCE(NULLIF(s.selling_price_per_unit, 0), p.price, 0) AS selling_price_per_unit,
                           COALESCE(NULLIF(s.selling_price_per_box, 0), (COALESCE(NULLIF(s.selling_price_per_unit, 0), p.price, 0) * s.conversion_rate), 0) AS selling_price_per_box
                    FROM stock_items s 
                    LEFT JOIN products p ON LOWER(REPLACE(s.item_name, ' ', '')) = LOWER(REPLACE(p.name, ' ', '')) 
                    WHERE s.item_id = ? AND s.item_type = 'direct_drink' AND s.is_active = 1 LIMIT 1");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();

            if (!$item) {
                sendJsonResponse(['success' => false, 'message' => 'Drink item not found.'], 404);
            }

            if (!empty($item['image'])) {
                $item['image'] = get_image_url($item['image']);
            }
            if (!empty($item['image_box'])) {
                $item['image_box'] = get_image_url($item['image_box']);
            }

            sendJsonResponse(['success' => true, 'item' => $item]);
        }

        // 3. Create New Canned/Bottled Drink
        if ($action === 'create_item') {
            $name          = trim($_POST['item_name'] ?? '');
            $catId         = (int)($_POST['category_id'] ?? 0);
            $unit          = trim($_POST['unit'] ?? 'can');
            $purchaseUnit  = trim($_POST['purchase_unit'] ?? 'box');
            $rate          = max(1.0, (float)($_POST['conversion_rate'] ?? 24.0));
            $boxes         = (float)($_POST['initial_boxes'] ?? 0);
            $loose         = (float)($_POST['initial_loose'] ?? 0);
            $costBox       = (float)($_POST['cost_per_purchase_unit'] ?? 0);
            $costUnit      = isset($_POST['cost_per_unit']) && $_POST['cost_per_unit'] !== '' ? (float)$_POST['cost_per_unit'] : (($costBox > 0 && $rate > 0) ? ($costBox / $rate) : 0.0);
            if ($costBox <= 0 && $costUnit > 0) {
                $costBox = $costUnit * $rate;
            }
            $alertLevel    = (float)($_POST['alert_level'] ?? 24.0); // e.g. 1 box
            $sellPriceUnit = (float)($_POST['selling_price_per_unit'] ?? 0);
            $sellPriceBox  = isset($_POST['selling_price_per_box']) && $_POST['selling_price_per_box'] !== '' ? (float)$_POST['selling_price_per_box'] : ($sellPriceUnit * $rate);
            $notes         = trim($_POST['notes'] ?? '');

            if (empty($name)) {
                sendJsonResponse(['success' => false, 'message' => 'Drink name is required.'], 422);
            }

            // Resolve Category Details
            $targetCatSlug = 'Drinks';
            $targetCatId = 3;
            $targetCatName = 'Direct Drinks';
            if ($catId > 0) {
                $catStmt = $pdo->prepare("SELECT category_id, slug, name FROM categories WHERE category_id = ? AND is_active = 1 LIMIT 1");
                $catStmt->execute([$catId]);
                $cRow = $catStmt->fetch();
                if ($cRow) {
                    $targetCatSlug = $cRow['slug'];
                    $targetCatId   = (int)$cRow['category_id'];
                    $targetCatName = $cRow['name'];
                }
            } else {
                $catStmt = $pdo->query("SELECT category_id, slug, name FROM categories WHERE is_active = 1 ORDER BY (slug = 'Drinks' OR name LIKE '%Drink%' OR name LIKE '%ភេសជ្ជៈ%') DESC, display_order ASC LIMIT 1");
                $cRow = $catStmt->fetch();
                if ($cRow) {
                    $targetCatSlug = $cRow['slug'];
                    $targetCatId   = (int)$cRow['category_id'];
                    $targetCatName = $cRow['name'];
                }
            }

            // Duplicate Name Check
            $chk = $pdo->prepare("SELECT item_id, item_name, quantity, unit FROM stock_items WHERE item_type = 'direct_drink' AND LOWER(TRIM(item_name)) = LOWER(?) AND is_active = 1 LIMIT 1");
            $chk->execute([$name]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                sendJsonResponse([
                    'success' => false, 
                    'message' => "A stock drink named '{$name}' already exists in inventory (Current Stock: " . ((float)$existing['quantity']) . " {$existing['unit']}). Please use Restock to add more quantities or edit the existing item."
                ], 422);
            }

            // Unit Image Upload Handling
            $image_path = null;
            if (!empty($_FILES['image']['name']) && ($_FILES['image']['error'] ?? 1) === UPLOAD_ERR_OK) {
                $uploadRes = cloudinary_upload_file($_FILES['image'], 'pos_coffee/stock');
                if ($uploadRes['success']) {
                    $image_path = $uploadRes['url'];
                }
            }

            if (!$image_path) {
                $pImg = $pdo->prepare("SELECT image FROM products WHERE LOWER(REPLACE(name, ' ', '')) = LOWER(REPLACE(?, ' ', '')) AND image IS NOT NULL AND image != '' LIMIT 1");
                $pImg->execute([$name]);
                $image_path = $pImg->fetchColumn() ?: null;
            }

            // Box Image Upload Handling
            $image_box_path = null;
            if (!empty($_FILES['image_box']['name']) && ($_FILES['image_box']['error'] ?? 1) === UPLOAD_ERR_OK) {
                $uploadResBox = cloudinary_upload_file($_FILES['image_box'], 'pos_coffee/stock');
                if ($uploadResBox['success']) {
                    $image_box_path = $uploadResBox['url'];
                }
            }

            $totalBaseUnits = ($boxes * $rate) + $loose;
            $unitCost = $costUnit > 0 ? $costUnit : (($costBox > 0 && $rate > 0) ? ($costBox / $rate) : 0.0);

            $stmt = $pdo->prepare("INSERT INTO stock_items 
                (item_name, image, image_box, category, category_id, item_type, quantity, unit, purchase_unit, conversion_rate, alert_level, cost_per_unit, cost_per_purchase_unit, selling_price_per_unit, selling_price_per_box, notes, is_active) 
                VALUES (?, ?, ?, ?, ?, 'direct_drink', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([$name, $image_path, $image_box_path, $targetCatSlug, $targetCatId, $totalBaseUnits, $unit, $purchaseUnit, $rate, $alertLevel, $unitCost, $costBox, $sellPriceUnit, $sellPriceBox, $notes]);
            $newId = (int)$pdo->lastInsertId();

            // ── Auto Add/Sync Products for POS (Unit and Box) ──
            try {
                $kmUnitSuffix = getPackageUnitSuffixKm($purchaseUnit);
                $enUnitSuffix = getPackageUnitSuffixEn($purchaseUnit);
                $boxName = $name . ' (' . $kmUnitSuffix . ')';
                $boxNameEn = $name . ' (' . $enUnitSuffix . ')';

                // 1. Auto Create / Update UNIT Product
                $uPrice = $sellPriceUnit > 0 ? $sellPriceUnit : (($sellPriceBox > 0 && $rate > 0) ? ($sellPriceBox / $rate) : 1.0);
                $uCost = $unitCost;

                $pCheck = $pdo->prepare("SELECT product_id, image FROM products WHERE LOWER(REPLACE(name, ' ', '')) = LOWER(REPLACE(?, ' ', '')) LIMIT 1");
                $pCheck->execute([$name]);
                $existingUnitProd = $pCheck->fetch();

                if ($existingUnitProd) {
                    $uImg = !empty($image_path) ? $image_path : $existingUnitProd['image'];
                    $uUpd = $pdo->prepare("UPDATE products SET category = ?, category_id = ?, price = ?, cost_price = ?, image = ?, is_available = 1 WHERE product_id = ?");
                    $uUpd->execute([$targetCatSlug, $targetCatId, $uPrice, $uCost, $uImg, $existingUnitProd['product_id']]);
                } else {
                    $uIns = $pdo->prepare("INSERT INTO products (name, description, price, cost_price, category, category_id, image, is_available, has_sizes, promo_percent) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0, 0)");
                    $uDesc = "Single unit direct drink ({$unit}) from stock inventory.";
                    $uIns->execute([$name, $uDesc, $uPrice, $uCost, $targetCatSlug, $targetCatId, $image_path]);
                }

                // 2. Auto Create / Update BOX Product ONLY if image_box is provided
                if (!empty($image_box_path)) {
                    $bPrice = $sellPriceBox > 0 ? $sellPriceBox : ($uPrice * $rate);
                    $bCost = $costBox > 0 ? $costBox : ($uCost * $rate);
                    $bImage = $image_box_path;

                    $bCheck = $pdo->prepare("SELECT product_id, image FROM products WHERE LOWER(REPLACE(name, ' ', '')) IN (
                        LOWER(REPLACE(?, ' ', '')),
                        LOWER(REPLACE(?, ' ', '')),
                        LOWER(REPLACE(?, ' ', '')),
                        LOWER(REPLACE(?, ' ', ''))
                    ) LIMIT 1");
                    $bCheck->execute([$boxName, $boxNameEn, $name . ' (កេស)', $name . ' (យួរ)']);
                    $existingBoxProd = $bCheck->fetch();

                    if ($existingBoxProd) {
                        $bUpd = $pdo->prepare("UPDATE products SET name = ?, category = ?, category_id = ?, price = ?, cost_price = ?, image = ?, is_available = 1 WHERE product_id = ?");
                        $bUpd->execute([$boxName, $targetCatSlug, $targetCatId, $bPrice, $bCost, $bImage, $existingBoxProd['product_id']]);
                    } else {
                        $bIns = $pdo->prepare("INSERT INTO products (name, description, price, cost_price, category, category_id, image, is_available, has_sizes, promo_percent) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0, 0)");
                        $bDesc = "1 {$purchaseUnit} = {$rate} {$unit}s direct drink from stock inventory.";
                        $bIns->execute([$boxName, $bDesc, $bPrice, $bCost, $targetCatSlug, $targetCatId, $bImage]);
                    }
                }
            } catch (Exception $e) {
                error_log("Auto sync products on create_item error: " . $e->getMessage());
            }

            // Log initial stock creation if units > 0
            if ($totalBaseUnits > 0) {
                try {
                    $rParts = [];
                    if ($boxes > 0) $rParts[] = "{$boxes} {$purchaseUnit}(s)";
                    if ($loose > 0) $rParts[] = "{$loose} {$unit}(s)";
                    $initLogDesc = "Initial Stock: " . implode(' + ', $rParts) . " = +{$totalBaseUnits} {$unit}s";
                    if ($notes !== '') $initLogDesc .= " | " . $notes;

                    $rStmt = $pdo->prepare("INSERT INTO stock_restocks 
                        (item_id, quantity_added, boxes_added, loose_added, cost_per_unit, total_cost, supplier, notes, recorded_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $initTotalCost = ($boxes * $costBox) + ($loose * $unitCost);
                    $rStmt->execute([$newId, $totalBaseUnits, $boxes, $loose, $unitCost, $initTotalCost, '', $initLogDesc, $recorded_by]);
                } catch (Exception $e) {
                    error_log("Failed to log initial stock restock: " . $e->getMessage());
                }
            }

            sendJsonResponse([
                'success' => true,
                'message' => "Direct drink '{$name}' added to inventory & auto-created both Unit and Box items on Product page!",
                'item_id' => $newId
            ]);
        }

        // 4. Bulk Box + Loose Restock (Atomic Transaction)
        if ($action === 'quick_restock') {
            $itemId      = (int)($_POST['item_id'] ?? 0);
            $boxesToAdd  = max(0, (float)($_POST['purchase_qty'] ?? 0));
            $looseToAdd  = max(0, (float)($_POST['loose_qty'] ?? 0));
            $costPerBox  = isset($_POST['cost_per_box']) && $_POST['cost_per_box'] !== '' ? (float)$_POST['cost_per_box'] : null;
            $supplier    = trim($_POST['supplier'] ?? '');
            $notes       = trim($_POST['notes'] ?? '');

            if ($itemId <= 0 || ($boxesToAdd <= 0 && $looseToAdd <= 0)) {
                sendJsonResponse(['success' => false, 'message' => 'សូមបញ្ចូលចំនួនកេស ឬចំនួនរាយដែលត្រូវបន្ថែម។ (Please enter boxes or loose units to add)'], 422);
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
                $totalBaseUnits   = ($boxesToAdd * $rate) + $looseToAdd;
                $pUnit            = $cur['purchase_unit'];
                $bUnit            = $cur['unit'];

                $activeBoxCost    = ($costPerBox !== null && $costPerBox >= 0) ? $costPerBox : (float)$cur['cost_per_purchase_unit'];
                $activeUnitCost   = ($activeBoxCost > 0) ? ($activeBoxCost / $rate) : (float)$cur['cost_per_unit'];
                $totalPurchaseCost = ($boxesToAdd * $activeBoxCost) + ($looseToAdd * $activeUnitCost);

                $uStmt = $pdo->prepare("UPDATE stock_items SET 
                    quantity = quantity + ?, 
                    cost_per_purchase_unit = ?, 
                    cost_per_unit = ?, 
                    updated_at = NOW() 
                    WHERE item_id = ?");
                $uStmt->execute([$totalBaseUnits, $activeBoxCost, $activeUnitCost, $itemId]);

                $parts = [];
                if ($boxesToAdd > 0) $parts[] = "{$boxesToAdd} {$pUnit}(s)";
                if ($looseToAdd > 0) $parts[] = "{$looseToAdd} {$bUnit}(s)";
                $logDesc = "Restock: " . implode(' + ', $parts) . " = +{$totalBaseUnits} {$bUnit}s";
                if ($notes !== '') $logDesc .= " | " . $notes;

                $rStmt = $pdo->prepare("INSERT INTO stock_restocks 
                    (item_id, quantity_added, boxes_added, loose_added, cost_per_unit, total_cost, supplier, notes, recorded_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $rStmt->execute([$itemId, $totalBaseUnits, $boxesToAdd, $looseToAdd, $activeUnitCost, $totalPurchaseCost, $supplier, $logDesc, $recorded_by]);

                $pdo->commit();

                sendJsonResponse([
                    'success' => true,
                    'message' => "បានបញ្ចូលស្តុក {$cur['item_name']} ជោគជ័យ! បន្ថែមសរុប +{$totalBaseUnits} {$bUnit}."
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                sendJsonResponse(['success' => false, 'message' => 'Restock transaction failed: ' . $e->getMessage()], 500);
            }
        }

        // 4.5. Deduct Stock / Stock Correction (Atomic Transaction)
        if ($action === 'deduct_stock') {
            $itemId        = (int)($_POST['item_id'] ?? 0);
            $boxesToDeduct = max(0, (float)($_POST['purchase_qty'] ?? 0));
            $looseToDeduct = max(0, (float)($_POST['loose_qty'] ?? 0));
            $reason        = trim($_POST['reason'] ?? 'Wrong input / Correction');
            $customNotes   = trim($_POST['notes'] ?? '');

            if ($itemId <= 0 || ($boxesToDeduct <= 0 && $looseToDeduct <= 0)) {
                sendJsonResponse(['success' => false, 'message' => 'សូមបញ្ចូលចំនួនកេស ឬចំនួនរាយដែលត្រូវដកចេញពីស្តុក។ (Please enter boxes or loose units to deduct)'], 422);
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

                $rate           = max(1.0, (float)$cur['conversion_rate']);
                $totalBaseUnits = ($boxesToDeduct * $rate) + $looseToDeduct;
                $currentQty     = (float)$cur['quantity'];
                $pUnit          = $cur['purchase_unit'];
                $bUnit          = $cur['unit'];
                $unitCost       = (float)$cur['cost_per_unit'];

                if ($totalBaseUnits > $currentQty) {
                    $pdo->rollBack();
                    sendJsonResponse([
                        'success' => false, 
                        'message' => "មិនអាចដកចំនួនលើសពីស្តុកបច្ចុប្បន្នបានទេ! (ស្តុកមាន: {$currentQty} {$bUnit}, ប៉ុន្តែព្យាយាមដក: {$totalBaseUnits} {$bUnit})"
                    ], 422);
                }

                $newQty = max(0.0, $currentQty - $totalBaseUnits);

                $uStmt = $pdo->prepare("UPDATE stock_items SET 
                    quantity = ?, 
                    updated_at = NOW() 
                    WHERE item_id = ?");
                $uStmt->execute([$newQty, $itemId]);

                $parts = [];
                if ($boxesToDeduct > 0) $parts[] = "{$boxesToDeduct} {$pUnit}(s)";
                if ($looseToDeduct > 0) $parts[] = "{$looseToDeduct} {$bUnit}(s)";
                $logDesc = "Deduction: -" . implode(' - ', $parts) . " = -{$totalBaseUnits} {$bUnit}s | Reason: {$reason}";
                if ($customNotes !== '') $logDesc .= " | Note: " . $customNotes;

                // Log into stock_logs for audit ledger & deduction history
                try {
                    $lStmt = $pdo->prepare("INSERT INTO stock_logs 
                        (item_id, change_type, quantity_changed, stock_before, stock_after, cost_at_time, notes, created_by) 
                        VALUES (?, 'stock_correction', ?, ?, ?, ?, ?, ?)");
                    $lStmt->execute([$itemId, -$totalBaseUnits, $currentQty, $newQty, $unitCost, $logDesc, $recorded_by]);
                } catch (Exception $e) {
                    error_log("Failed to insert stock_logs: " . $e->getMessage());
                }

                $pdo->commit();

                sendJsonResponse([
                    'success' => true,
                    'message' => "បានដកស្តុក {$cur['item_name']} ចំនួន -{$totalBaseUnits} {$bUnit} ជោគជ័យ! ស្តុកនៅសល់: {$newQty} {$bUnit}."
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                sendJsonResponse(['success' => false, 'message' => 'Deduct stock transaction failed: ' . $e->getMessage()], 500);
            }
        }

        // 5. Update Drink Details
        if ($action === 'update_item') {
            $itemId        = (int)($_POST['item_id'] ?? 0);
            $name          = trim($_POST['item_name'] ?? '');
            $catId         = (int)($_POST['category_id'] ?? 0);
            $unit          = trim($_POST['unit'] ?? 'can');
            $purchaseUnit  = trim($_POST['purchase_unit'] ?? 'box');
            $rate          = max(1.0, (float)($_POST['conversion_rate'] ?? 24.0));
            $alertLevel    = (float)($_POST['alert_level'] ?? 24.0);
            $costBox       = (float)($_POST['cost_per_purchase_unit'] ?? 0);
            $costUnit      = isset($_POST['cost_per_unit']) && $_POST['cost_per_unit'] !== '' ? (float)$_POST['cost_per_unit'] : (($costBox > 0) ? ($costBox / $rate) : 0.0);
            if ($costBox <= 0 && $costUnit > 0) {
                $costBox = $costUnit * $rate;
            }
            $sellPriceUnit = (float)($_POST['selling_price_per_unit'] ?? 0);
            $sellPriceBox  = isset($_POST['selling_price_per_box']) && $_POST['selling_price_per_box'] !== '' ? (float)$_POST['selling_price_per_box'] : ($sellPriceUnit * $rate);
            $notes         = trim($_POST['notes'] ?? '');

            if ($itemId <= 0 || empty($name)) {
                sendJsonResponse(['success' => false, 'message' => 'Invalid parameters.'], 422);
            }

            // Resolve Category Details
            $targetCatSlug = 'Drinks';
            $targetCatId = 3;
            $targetCatName = 'Direct Drinks';
            if ($catId > 0) {
                $catStmt = $pdo->prepare("SELECT category_id, slug, name FROM categories WHERE category_id = ? AND is_active = 1 LIMIT 1");
                $catStmt->execute([$catId]);
                $cRow = $catStmt->fetch();
                if ($cRow) {
                    $targetCatSlug = $cRow['slug'];
                    $targetCatId   = (int)$cRow['category_id'];
                    $targetCatName = $cRow['name'];
                }
            } else {
                $catStmt = $pdo->query("SELECT category_id, slug, name FROM categories WHERE is_active = 1 ORDER BY (slug = 'Drinks' OR name LIKE '%Drink%' OR name LIKE '%ភេសជ្ជៈ%') DESC, display_order ASC LIMIT 1");
                $cRow = $catStmt->fetch();
                if ($cRow) {
                    $targetCatSlug = $cRow['slug'];
                    $targetCatId   = (int)$cRow['category_id'];
                    $targetCatName = $cRow['name'];
                }
            }

            // Duplicate Name Check on Edit
            $chk = $pdo->prepare("SELECT item_id, image, image_box, category, category_id, quantity, cost_per_unit, unit FROM stock_items WHERE item_id = ? AND is_active = 1 LIMIT 1");
            $chk->execute([$itemId]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                sendJsonResponse(['success' => false, 'message' => 'Item not found.'], 404);
            }

            $dupChk = $pdo->prepare("SELECT item_id FROM stock_items WHERE item_type = 'direct_drink' AND LOWER(TRIM(item_name)) = LOWER(?) AND item_id != ? AND is_active = 1 LIMIT 1");
            $dupChk->execute([$name, $itemId]);
            if ($dupChk->fetch()) {
                sendJsonResponse([
                    'success' => false, 
                    'message' => "Another stock drink named '{$name}' already exists."
                ], 422);
            }

            // Optional Unit Image Upload on Edit or Remove
            $remove_image = isset($_POST['remove_image']) && $_POST['remove_image'] === '1';
            $new_image_path = null;
            if (!empty($_FILES['image']['name']) && ($_FILES['image']['error'] ?? 1) === UPLOAD_ERR_OK) {
                $uploadRes = cloudinary_upload_file($_FILES['image'], 'pos_coffee/stock');
                if ($uploadRes['success']) {
                    $new_image_path = $uploadRes['url'];
                    if (!empty($existing['image'])) {
                        cloudinary_delete_image($existing['image']);
                    }
                }
            } elseif ($remove_image) {
                if (!empty($existing['image'])) {
                    cloudinary_delete_image($existing['image']);
                }
                $new_image_path = ''; // Empty string indicates explicit removal
            }

            // Optional Box Image Upload on Edit or Remove
            $remove_image_box = isset($_POST['remove_image_box']) && $_POST['remove_image_box'] === '1';
            $new_image_box_path = null;
            if (!empty($_FILES['image_box']['name']) && ($_FILES['image_box']['error'] ?? 1) === UPLOAD_ERR_OK) {
                $uploadResBox = cloudinary_upload_file($_FILES['image_box'], 'pos_coffee/stock');
                if ($uploadResBox['success']) {
                    $new_image_box_path = $uploadResBox['url'];
                    if (!empty($existing['image_box'])) {
                        cloudinary_delete_image($existing['image_box']);
                    }
                }
            } elseif ($remove_image_box) {
                if (!empty($existing['image_box'])) {
                    cloudinary_delete_image($existing['image_box']);
                }
                $new_image_box_path = ''; // Empty string indicates explicit removal
            }

            $finalImage = $new_image_path !== null ? ($new_image_path === '' ? null : $new_image_path) : $existing['image'];
            $finalImageBox = $new_image_box_path !== null ? ($new_image_box_path === '' ? null : $new_image_box_path) : ($existing['image_box'] ?? null);

            $stmt = $pdo->prepare("UPDATE stock_items SET 
                item_name = ?, 
                image = ?,
                image_box = ?,
                category = ?,
                category_id = ?,
                unit = ?, 
                purchase_unit = ?, 
                conversion_rate = ?, 
                alert_level = ?, 
                cost_per_purchase_unit = ?, 
                cost_per_unit = ?, 
                selling_price_per_unit = ?,
                selling_price_per_box = ?, 
                notes = ?, 
                updated_at = NOW() 
                WHERE item_id = ? AND is_active = 1");
            $stmt->execute([$name, $finalImage, $finalImageBox, $targetCatSlug, $targetCatId, $unit, $purchaseUnit, $rate, $alertLevel, $costBox, $costUnit, $sellPriceUnit, $sellPriceBox, $notes, $itemId]);

            // ── Auto Sync Products for POS (Unit and Box) ──
            try {
                $oldPurchaseUnit = $existing['purchase_unit'] ?? 'box';
                $oldKmUnitSuffix = getPackageUnitSuffixKm($oldPurchaseUnit);
                $oldEnUnitSuffix = getPackageUnitSuffixEn($oldPurchaseUnit);

                $kmUnitSuffix = getPackageUnitSuffixKm($purchaseUnit);
                $enUnitSuffix = getPackageUnitSuffixEn($purchaseUnit);

                $boxName = $name . ' (' . $kmUnitSuffix . ')';
                $boxNameEn = $name . ' (' . $enUnitSuffix . ')';
                $oldName = $existing['item_name'] ?? '';
                $oldBoxName = $oldName ? ($oldName . ' (' . $oldKmUnitSuffix . ')') : '';
                $oldBoxNameEn = $oldName ? ($oldName . ' (' . $oldEnUnitSuffix . ')') : '';

                // 1. Sync Unit Product
                $uPrice = $sellPriceUnit > 0 ? $sellPriceUnit : (($sellPriceBox > 0 && $rate > 0) ? ($sellPriceBox / $rate) : 1.0);
                $uCost = $costUnit;

                $pCheck = $pdo->prepare("SELECT product_id, image FROM products WHERE LOWER(REPLACE(name, ' ', '')) = LOWER(REPLACE(?, ' ', '')) OR LOWER(REPLACE(name, ' ', '')) = LOWER(REPLACE(?, ' ', '')) LIMIT 1");
                $pCheck->execute([$name, $oldName]);
                $existingUnitProd = $pCheck->fetch();

                if ($existingUnitProd) {
                    $uImg = $remove_image ? null : (!empty($finalImage) ? $finalImage : $existingUnitProd['image']);
                    $uUpd = $pdo->prepare("UPDATE products SET name = ?, category = ?, category_id = ?, price = ?, cost_price = ?, image = ?, is_available = 1 WHERE product_id = ?");
                    $uUpd->execute([$name, $targetCatSlug, $targetCatId, $uPrice, $uCost, $uImg, $existingUnitProd['product_id']]);
                } else {
                    $uIns = $pdo->prepare("INSERT INTO products (name, description, price, cost_price, category, category_id, image, is_available, has_sizes, promo_percent) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0, 0)");
                    $uDesc = "Single unit direct drink ({$unit}) from stock inventory.";
                    $uIns->execute([$name, $uDesc, $uPrice, $uCost, $targetCatSlug, $targetCatId, $finalImage]);
                }

                // 2. Sync Box Product ONLY if image_box is provided
                $bCheck = $pdo->prepare("SELECT product_id, image FROM products WHERE LOWER(REPLACE(name, ' ', '')) IN (
                    LOWER(REPLACE(?, ' ', '')),
                    LOWER(REPLACE(?, ' ', '')),
                    LOWER(REPLACE(?, ' ', '')),
                    LOWER(REPLACE(?, ' ', '')),
                    LOWER(REPLACE(?, ' ', '')),
                    LOWER(REPLACE(?, ' ', '')),
                    LOWER(REPLACE(?, ' ', '')),
                    LOWER(REPLACE(?, ' ', ''))
                ) LIMIT 1");
                $bCheck->execute([
                    $boxName,
                    $boxNameEn,
                    $oldBoxName,
                    $oldBoxNameEn,
                    $name . ' (កេស)',
                    $name . ' (យួរ)',
                    $oldName . ' (កេស)',
                    $oldName . ' (យួរ)'
                ]);
                $existingBoxProd = $bCheck->fetch();

                if (!empty($finalImageBox)) {
                    $bPrice = $sellPriceBox > 0 ? $sellPriceBox : ($uPrice * $rate);
                    $bCost = $costBox > 0 ? $costBox : ($uCost * $rate);
                    $bImage = $finalImageBox;

                    if ($existingBoxProd) {
                        $bUpd = $pdo->prepare("UPDATE products SET name = ?, category = ?, category_id = ?, price = ?, cost_price = ?, image = ?, is_available = 1 WHERE product_id = ?");
                        $bUpd->execute([$boxName, $targetCatSlug, $targetCatId, $bPrice, $bCost, $bImage, $existingBoxProd['product_id']]);
                    } else {
                        $bIns = $pdo->prepare("INSERT INTO products (name, description, price, cost_price, category, category_id, image, is_available, has_sizes, promo_percent) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0, 0)");
                        $bDesc = "1 {$purchaseUnit} = {$rate} {$unit}s direct drink from stock inventory.";
                        $bIns->execute([$boxName, $bDesc, $bPrice, $bCost, $targetCatSlug, $targetCatId, $bImage]);
                    }
                } else {
                    // If no box image is set, remove Box product so POS menu only sells unit
                    if ($existingBoxProd) {
                        $bPid = (int)$existingBoxProd['product_id'];
                        $pdo->prepare("DELETE FROM product_recipes WHERE product_id = ?")->execute([$bPid]);
                        $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$bPid]);
                    }
                }
            } catch (Exception $e) {
                error_log("Auto sync products on update_item error: " . $e->getMessage());
            }

            sendJsonResponse(['success' => true, 'message' => "Drink '{$name}' updated in stock & synced with Unit and Box products on Products page!"]);
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
            $stmt = $pdo->query("SELECT s.item_id, s.item_name, s.quantity, s.unit, s.purchase_unit, s.conversion_rate, s.alert_level, s.cost_per_unit, s.cost_per_purchase_unit, 
                COALESCE(NULLIF(s.selling_price_per_unit, 0), p.price, 0) AS selling_price_per_unit,
                COALESCE(NULLIF(s.selling_price_per_box, 0), (COALESCE(NULLIF(s.selling_price_per_unit, 0), p.price, 0) * s.conversion_rate), 0) AS selling_price_per_box,
                (s.quantity * s.cost_per_unit) AS valuation, s.notes, s.updated_at 
                FROM stock_items s 
                LEFT JOIN products p ON LOWER(REPLACE(s.item_name, ' ', '')) = LOWER(REPLACE(p.name, ' ', '')) 
                WHERE s.item_type = 'direct_drink' AND s.is_active = 1 ORDER BY s.item_name ASC");
            $rows = $stmt->fetchAll();

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=birds_nest_direct_drinks_stock_' . date('Y-m-d_His') . '.csv');
            
            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($output, ['Drink ID', 'Drink Name', 'Quantity (Units)', 'Unit', 'Package Unit', 'Units Per Package', 'Alert Level', 'Cost Per Unit ($)', 'Cost Per Box ($)', 'Sell Price Per Unit ($)', 'Sell Price Per Box ($)', 'Total Valuation ($)', 'Stock Status', 'Notes', 'Last Updated']);

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
                    number_format((float)$row['selling_price_per_unit'], 2, '.', ''),
                    number_format((float)$row['selling_price_per_box'], 2, '.', ''),
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
                ORDER BY r.created_at DESC LIMIT 60");
            $rawRestocks = $rStmt->fetchAll();

            $restocks = [];
            foreach ($rawRestocks as $row) {
                $qtyAdded = (float)($row['quantity_added'] ?? 0);
                $convRate = max(1.0, (float)($row['conversion_rate'] ?? 24.0));
                
                $boxes = isset($row['boxes_added']) ? (float)$row['boxes_added'] : 0.0;
                $loose = isset($row['loose_added']) ? (float)$row['loose_added'] : 0.0;

                if ($boxes == 0.0 && !empty($row['notes'])) {
                    if (preg_match('/(\d+(?:\.\d+)?)\s*(?:box\(s\)|box|boxes|កេស|យួរ|កញ្ចប់|កាតុង|ឡូ)/i', $row['notes'], $m)) {
                        $boxes = (float)$m[1];
                    }
                    if (preg_match('/\+\s*(\d+(?:\.\d+)?)\s*(?:bottle\(s\)|bottle|bottles|can\(s\)|can|cans|កំប៉ុង|ដប|រាយ)\s*=/i', $row['notes'], $m2)) {
                        $loose = (float)$m2[1];
                    } else {
                        $rem = $qtyAdded - ($boxes * $convRate);
                        $loose = ($rem > 0 && $rem < $convRate) ? $rem : 0.0;
                    }
                }

                if ($boxes == 0.0 && $loose == 0.0 && $convRate > 0 && $qtyAdded > 0) {
                    $boxes = floor($qtyAdded / $convRate);
                    $loose = fmod($qtyAdded, $convRate);
                }

                $row['boxes_added'] = $boxes;
                $row['loose_added'] = $loose;
                $restocks[] = $row;
            }

            // 2. Direct Drink Deductions (from stock_logs)
            $deductions = [];
            try {
                $dStmt = $pdo->query("SELECT l.*, s.item_name, s.unit, s.purchase_unit, s.conversion_rate 
                    FROM stock_logs l 
                    JOIN stock_items s ON l.item_id = s.item_id 
                    WHERE s.item_type = 'direct_drink' AND l.quantity_changed < 0 
                    ORDER BY l.created_at DESC LIMIT 80");
                $rawDeductions = $dStmt->fetchAll();

                foreach ($rawDeductions as $row) {
                    $qtyDeducted = abs((float)($row['quantity_changed'] ?? 0));
                    $convRate = max(1.0, (float)($row['conversion_rate'] ?? 24.0));
                    
                    $boxes = 0.0;
                    $loose = 0.0;

                    if (!empty($row['notes'])) {
                        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:box\(s\)|box|boxes|កេស|យួរ|កញ្ចប់|កាតុង|ឡូ)/i', $row['notes'], $m)) {
                            $boxes = (float)$m[1];
                        }
                    }
                    
                    if ($boxes > 0) {
                        $rem = $qtyDeducted - ($boxes * $convRate);
                        $loose = ($rem > 0 && $rem < $convRate) ? $rem : 0.0;
                    } else {
                        if ($convRate > 0 && $qtyDeducted >= $convRate && fmod($qtyDeducted, $convRate) == 0) {
                            $boxes = floor($qtyDeducted / $convRate);
                            $loose = 0.0;
                        } else {
                            $boxes = floor($qtyDeducted / $convRate);
                            $loose = fmod($qtyDeducted, $convRate);
                        }
                    }

                    $row['boxes_deducted'] = $boxes;
                    $row['loose_deducted'] = $loose;
                    $row['total_deducted'] = $qtyDeducted;
                    $deductions[] = $row;
                }
            } catch (Exception $e) {
                $deductions = [];
            }

            // 3. Waste Logs
            $wStmt = $pdo->query("SELECT w.*, s.item_name, s.unit 
                FROM stock_waste_logs w 
                JOIN stock_items s ON w.item_id = s.item_id 
                WHERE s.item_type = 'direct_drink' 
                ORDER BY w.created_at DESC LIMIT 60");
            $waste = $wStmt->fetchAll();

            sendJsonResponse([
                'success'    => true,
                'restocks'   => $restocks,
                'deductions' => $deductions,
                'waste'      => $waste
            ]);
        }

        sendJsonResponse(['success' => false, 'message' => 'Unknown action requested.'], 400);
    }
}

// ── Initial Page Load Data ──
$initialKpis = getDirectDrinkKPIs($pdo);
$initStmt = $pdo->query("SELECT s.*, 
                         COALESCE(s.category_id, p.category_id, 0) AS category_id,
                         COALESCE(NULLIF(s.image, ''), p.image, '') AS image,
                         COALESCE(NULLIF(s.selling_price_per_unit, 0), p.price, 0) AS selling_price_per_unit,
                         COALESCE(NULLIF(s.selling_price_per_box, 0), (COALESCE(NULLIF(s.selling_price_per_unit, 0), p.price, 0) * s.conversion_rate), 0) AS selling_price_per_box
                         FROM stock_items s 
                         LEFT JOIN products p ON LOWER(REPLACE(s.item_name, ' ', '')) = LOWER(REPLACE(p.name, ' ', '')) 
                         WHERE s.item_type = 'direct_drink' AND s.is_active = 1 
                         ORDER BY s.item_name ASC");
$stockItems = $initStmt->fetchAll();

// Active Categories for Add/Edit Drink modals
$catInitStmt = $pdo->query("SELECT category_id, name, slug FROM categories WHERE is_active = 1 ORDER BY display_order ASC, name ASC");
$activeCategories = $catInitStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>" data-lang="<?= current_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <title>Direct Drinks Stock Management | Bird's Nest Coffee</title>

    <!-- Google Fonts: Poppins & Kantumruy Pro (Khmer) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap" rel="stylesheet">

    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Cropper.js & Product Cropper Assets -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css">
    <link rel="stylesheet" href="assets/css/product_cropper.css">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
    <script src="assets/js/product_cropper.js"></script>

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
        [data-theme="light"] .modal-content label { color: #475569 !important; }
        
        /* ══════════════════════════════════════════════════════════════
           MODAL OBSIDIAN DARK THEME (Default / Dark Mode)
        ══════════════════════════════════════════════════════════════ */
        html:not([data-theme="light"]) .modal-overlay {
            background-color: rgba(0, 0, 0, 0.8) !important;
            backdrop-filter: blur(10px) !important;
            -webkit-backdrop-filter: blur(10px) !important;
        }
        html:not([data-theme="light"]) .modal-content {
            background-color: #0e1422 !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.85) !important;
            color: #ffffff !important;
        }
        html:not([data-theme="light"]) .modal-header,
        html:not([data-theme="light"]) .modal-content .modal-header,
        html:not([data-theme="light"]) #restockModal .px-6.py-5 {
            background-color: #0e1422 !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
        }
        html:not([data-theme="light"]) .modal-content form {
            background-color: #080c14 !important;
            color: #ffffff !important;
        }
        html:not([data-theme="light"]) .modal-content label {
            color: #cbd5e1 !important;
        }
        html:not([data-theme="light"]) .modal-content h3,
        html:not([data-theme="light"]) .modal-content h4,
        html:not([data-theme="light"]) .modal-content strong,
        html:not([data-theme="light"]) .modal-content .modal-title,
        html:not([data-theme="light"]) .modal-content .text-slate-800,
        html:not([data-theme="light"]) .modal-content .text-slate-900,
        html:not([data-theme="light"]) .modal-content .text-slate-700 {
            color: #ffffff !important;
        }
        html:not([data-theme="light"]) .modal-content p,
        html:not([data-theme="light"]) .modal-content .text-slate-400,
        html:not([data-theme="light"]) .modal-content .text-slate-500,
        html:not([data-theme="light"]) .modal-content .text-slate-600,
        html:not([data-theme="light"]) #editTotalStockFormula {
            color: #94a3b8 !important;
        }
        html:not([data-theme="light"]) #editQuantity {
            color: #818cf8 !important;
            border-color: rgba(99, 102, 241, 0.35) !important;
        }

        /* Modal Inputs & Selects */
        html:not([data-theme="light"]) .modal-content input:not([type="checkbox"]):not([type="radio"]),
        html:not([data-theme="light"]) .modal-content select,
        html:not([data-theme="light"]) .modal-content textarea {
            background-color: #101726 !important;
            border: 1.5px solid rgba(255, 255, 255, 0.08) !important;
            color: #ffffff !important;
        }
        html:not([data-theme="light"]) .modal-content input::placeholder,
        html:not([data-theme="light"]) .modal-content textarea::placeholder {
            color: #64748b !important;
        }
        html:not([data-theme="light"]) .modal-content input:focus,
        html:not([data-theme="light"]) .modal-content select:focus,
        html:not([data-theme="light"]) .modal-content textarea:focus {
            border-color: #10b981 !important;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2) !important;
        }
        html:not([data-theme="light"]) .modal-content select option {
            background-color: #101726 !important;
            color: #ffffff !important;
        }

        /* Sub-cards and nested containers inside modals */
        html:not([data-theme="light"]) #editCurrentStockBreakdown {
            background-color: #1e293b !important;
            color: #ffffff !important;
            border-color: rgba(255, 255, 255, 0.12) !important;
        }
        html:not([data-theme="light"]) #editCurrentStockTotal {
            background-color: rgba(99, 102, 241, 0.2) !important;
            color: #a5b4fc !important;
            border-color: rgba(99, 102, 241, 0.35) !important;
        }
        html:not([data-theme="light"]) .modal-content .bg-white,
        html:not([data-theme="light"]) .modal-content .bg-slate-50,
        html:not([data-theme="light"]) .modal-content .bg-slate-50\/70,
        html:not([data-theme="light"]) .modal-content .bg-slate-50\/50,
        html:not([data-theme="light"]) .modal-content .bg-slate-100 {
            background-color: #101726 !important;
        }
        html:not([data-theme="light"]) .modal-content .border-slate-100,
        html:not([data-theme="light"]) .modal-content .border-slate-200,
        html:not([data-theme="light"]) .modal-content .border-slate-200\/80,
        html:not([data-theme="light"]) .modal-content .border-slate-200\/60,
        html:not([data-theme="light"]) .modal-content .border-indigo-100,
        html:not([data-theme="light"]) .modal-content .border-emerald-100 {
            border-color: rgba(255, 255, 255, 0.08) !important;
        }

        /* Image Dropzones in Add / Edit */
        html:not([data-theme="light"]) .modal-content .border-dashed {
            background-color: #101726 !important;
            border-color: rgba(255, 255, 255, 0.15) !important;
        }
        html:not([data-theme="light"]) .modal-content .border-dashed:hover {
            border-color: #10b981 !important;
            background-color: rgba(16, 185, 129, 0.05) !important;
        }
        html:not([data-theme="light"]) .modal-content .border-dashed .bg-indigo-50,
        html:not([data-theme="light"]) .modal-content .border-dashed .bg-emerald-50 {
            background-color: rgba(16, 185, 129, 0.12) !important;
            color: #34d399 !important;
        }
        html:not([data-theme="light"]) .modal-content .border-dashed span.text-indigo-600,
        html:not([data-theme="light"]) .modal-content .border-dashed span.text-emerald-600 {
            color: #ffffff !important;
        }

        /* Restock & Deduct & Profit highlight cards */
        html:not([data-theme="light"]) #restockPreviewCard,
        html:not([data-theme="light"]) .modal-content .bg-indigo-50\/60,
        html:not([data-theme="light"]) .modal-content .bg-indigo-50 {
            background-color: rgba(99, 102, 241, 0.1) !important;
            border-color: rgba(99, 102, 241, 0.25) !important;
        }
        html:not([data-theme="light"]) #restockPreviewCard .text-indigo-950,
        html:not([data-theme="light"]) #restockPreviewCard .text-indigo-900\/80 {
            color: #ffffff !important;
        }
        html:not([data-theme="light"]) #restockBadgeUnits {
            background-color: #101726 !important;
            border-color: rgba(99, 102, 241, 0.3) !important;
            color: #818cf8 !important;
        }

        html:not([data-theme="light"]) #deductPreviewCard,
        html:not([data-theme="light"]) .modal-content .bg-rose-50\/70,
        html:not([data-theme="light"]) .modal-content .bg-rose-50\/40 {
            background-color: rgba(244, 63, 94, 0.1) !important;
            border-color: rgba(244, 63, 94, 0.25) !important;
        }
        html:not([data-theme="light"]) #deductPreviewCard .text-rose-950,
        html:not([data-theme="light"]) #deductPreviewCard .text-rose-900\/80 {
            color: #ffffff !important;
        }
        html:not([data-theme="light"]) #deductBadgeUnits {
            background-color: #101726 !important;
            border-color: rgba(244, 63, 94, 0.3) !important;
            color: #fb7185 !important;
        }
        html:not([data-theme="light"]) #restockCurrentStock {
            color: #94a3b8 !important;
        }
        html:not([data-theme="light"]) #restockProjectedStock {
            color: #34d399 !important;
        }
        html:not([data-theme="light"]) #restockBoxToLoosePreview {
            color: #34d399 !important;
        }

        /* Modal Footer & Buttons */
        html:not([data-theme="light"]) .modal-footer,
        html:not([data-theme="light"]) .modal-content .modal-footer {
            background-color: #0e1422 !important;
            border-top: 1px solid rgba(255, 255, 255, 0.08) !important;
        }
        html:not([data-theme="light"]) #addStockSubmitBtn,
        html:not([data-theme="light"]) #editStockSubmitBtn,
        html:not([data-theme="light"]) #restockSubmitBtn,
        html:not([data-theme="light"]) .modal-footer button[type="submit"] {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
            color: #ffffff !important;
            border: none !important;
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35) !important;
        }
        html:not([data-theme="light"]) #addStockSubmitBtn:hover,
        html:not([data-theme="light"]) #editStockSubmitBtn:hover,
        html:not([data-theme="light"]) #restockSubmitBtn:hover,
        html:not([data-theme="light"]) .modal-footer button[type="submit"]:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%) !important;
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(16, 185, 129, 0.45) !important;
        }
        html:not([data-theme="light"]) .modal-footer button[type="button"]:not([type="submit"]):not(.btn-primary),
        html:not([data-theme="light"]) form .flex.items-center.justify-end.gap-3 button[type="button"] {
            color: #ffffff !important;
            background-color: rgba(255, 255, 255, 0.06) !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
        }
        html:not([data-theme="light"]) .modal-footer button[type="button"]:not([type="submit"]):not(.btn-primary):hover,
        html:not([data-theme="light"]) form .flex.items-center.justify-end.gap-3 button[type="button"]:hover {
            background-color: rgba(255, 255, 255, 0.12) !important;
            color: #ffffff !important;
        }
        
        /* ── Modal Header & Button Color Fix ── */
        .modal-header,
        .modal-header .modal-title,
        .modal-header h3 {
            color: #ffffff !important;
        }
        .modal-header p,
        .modal-header .text-slate-400 {
            color: #94a3b8 !important;
        }
        .modal-header .text-indigo-400 {
            color: #818cf8 !important;
        }
        .modal-header button {
            color: #94a3b8 !important;
        }
        .modal-header button:hover {
            color: #ffffff !important;
        }
        #addStockSubmitBtn,
        #editStockSubmitBtn,
        #addStockSubmitBtn *,
        #editStockSubmitBtn * {
            color: #ffffff !important;
        }
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
        /* Action Buttons Base Styling */
        .btn-action-neutral {
            width: 32px;
            height: 32px;
            border-radius: 9px;
            border: 1px solid #2b2b36;
            background-color: #1f1f26;
            color: #94a3b8;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }
        .btn-action-neutral:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        .btn-action-neutral:active {
            transform: translateY(0);
        }

        /* 1. Edit Button Hover (Indigo) */
        .btn-act-edit:hover {
            background-color: rgba(99, 102, 241, 0.15) !important;
            border-color: rgba(99, 102, 241, 0.5) !important;
            color: #818cf8 !important;
            box-shadow: 0 4px 14px rgba(99, 102, 241, 0.25) !important;
        }

        /* 2. Delete Button Hover (Rose Red) */
        .btn-act-delete:hover {
            background-color: rgba(239, 68, 68, 0.15) !important;
            border-color: rgba(239, 68, 68, 0.5) !important;
            color: #ef4444 !important;
            box-shadow: 0 4px 14px rgba(239, 68, 68, 0.25) !important;
        }

        /* Light Theme Action Button Overrides */
        [data-theme="light"] .btn-action-neutral {
            background-color: #f1f5f9;
            color: #64748b;
            border-color: #e2e4ea;
        }
        [data-theme="light"] .btn-act-edit:hover {
            background-color: #eef2ff !important;
            border-color: #6366f1 !important;
            color: #4f46e5 !important;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.2) !important;
        }
        [data-theme="light"] .btn-act-delete:hover {
            background-color: #fef2f2 !important;
            border-color: #ef4444 !important;
            color: #dc2626 !important;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2) !important;
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

        /* ── Sticky Table Header ── */
        .stock-table-scroll-container {
            overflow-x: auto;
            overflow-y: auto;
            max-height: calc(100vh - 350px);
            min-height: 360px;
            position: relative;
        }
        .stock-table-scroll-container table {
            border-collapse: separate !important;
            border-spacing: 0 !important;
        }
        .stock-table-scroll-container thead {
            position: sticky !important;
            top: 0 !important;
            z-index: 25 !important;
        }
        .stock-table-scroll-container thead tr {
            position: sticky !important;
            top: 0 !important;
            z-index: 25 !important;
        }
        .stock-table-scroll-container thead th {
            position: sticky !important;
            top: 0 !important;
            z-index: 25 !important;
            background-color: #141418 !important;
            border-bottom: 1px solid #24242b !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2) !important;
        }
        [data-theme="light"] .stock-table-scroll-container thead th,
        html[data-theme="light"] .stock-table-scroll-container thead th {
            background-color: #f1f5f9 !important;
            border-bottom: 1px solid #e2e4ea !important;
            color: #475569 !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.04) !important;
        }

        /* ── Mini Image Thumbnails for Table (Category Tint & Badge) ── */
        .item-mini-img {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            background-color: #1e1e24;
            border: 1px solid #282834;
            overflow: visible;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.15);
            position: relative;
            transition: transform 0.15s ease, border-color 0.15s ease;
        }
        .item-mini-img:hover {
            transform: scale(1.08);
            border-color: #10b981;
            z-index: 10;
        }
        .item-mini-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            border-radius: 10px;
        }

        /* Category Tint Placeholders */
        .item-mini-img.tint-unit {
            background-color: #eef2ff !important;
            border-color: #dbeafe !important;
        }
        .item-mini-img.tint-box {
            background-color: #fffbeb !important;
            border-color: #fef3c7 !important;
        }

        [data-theme="dark"] .item-mini-img.tint-unit {
            background-color: rgba(99, 102, 241, 0.12) !important;
            border-color: rgba(99, 102, 241, 0.28) !important;
        }
        [data-theme="dark"] .item-mini-img.tint-box {
            background-color: rgba(16, 185, 129, 0.12) !important;
            border-color: rgba(16, 185, 129, 0.28) !important;
        }

        [data-theme="light"] .item-mini-img {
            background-color: #f8fafc !important;
            border-color: #e2e8f0 !important;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04) !important;
        }

        .mini-img-tag {
            position: absolute;
            bottom: -2px;
            right: -2px;
            background: #0f172a;
            color: #ffffff;
            font-size: 7.5px;
            font-weight: 800;
            padding: 0.5px 4.5px;
            border-radius: 9999px;
            line-height: 1.25;
            pointer-events: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.3);
            z-index: 2;
            letter-spacing: 0.2px;
        }
        .mini-img-tag.unit-tag {
            background: #090d16 !important;
            color: #ffffff !important;
        }
        .mini-img-tag.box-tag {
            background: #059669 !important;
            color: #ffffff !important;
        }

        /* ── Modern Adaptive Stock Image Upload Box ── */
        .stock-img-upload-box {
            background-color: #141418;
            border: 1.5px dashed #2d2d38;
            border-radius: 14px;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            gap: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .stock-img-upload-box:hover {
            border-color: #10b981;
            background-color: #1a1a22;
        }
        .stock-img-thumb {
            width: 54px;
            height: 54px;
            border-radius: 12px;
            background-color: #1a1a20;
            border: 1.5px solid #2e2e3a;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            position: relative;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
        }
        .stock-img-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .stock-img-thumb img[src=""],
        .stock-img-thumb img:not([src]),
        .stock-img-thumb img.hidden,
        .stock-img-thumb img[style*="display: none"],
        .stock-img-thumb img[style*="display:none"] {
            display: none !important;
        }
        .stock-upload-btn-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
            background: rgba(16, 185, 129, 0.16);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.35);
            transition: all 0.2s ease;
        }
        .stock-img-upload-box:hover .stock-upload-btn-pill {
            background: rgba(16, 185, 129, 0.26);
            color: #10b981;
            border-color: #10b981;
        }

        [data-theme="light"] .stock-img-upload-box {
            background-color: #f8fafc !important;
            border-color: #cbd5e1 !important;
        }
        [data-theme="light"] .stock-img-upload-box:hover {
            border-color: #059669 !important;
            background-color: #f0fdf4 !important;
        }
        [data-theme="light"] .stock-img-thumb {
            background-color: #ffffff !important;
            border-color: #e2e8f0 !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06) !important;
        }
        [data-theme="light"] .stock-upload-btn-pill {
            background-color: #d1fae5 !important;
            color: #065f46 !important;
            border-color: #a7f3d0 !important;
        }
        [data-theme="light"] .stock-img-upload-box:hover .stock-upload-btn-pill {
            background-color: #a7f3d0 !important;
            color: #047857 !important;
            border-color: #10b981 !important;
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
                            onclick="openAuditLogsModal('restock')" 
                            class="btn-top-toolbar inline-flex items-center gap-2 px-3.5 py-2.5 rounded-xl bg-[#18181c] border border-[#262630] text-xs font-semibold text-[#c5c5d2] hover:text-white hover:border-[#10b981] hover:bg-[#1f1f26] transition-all cursor-pointer shadow-sm">
                        <i class="fa-solid fa-clock-rotate-left text-[#10b981]"></i>
                        <span><?= __('audit_and_logs', 'Audit & Logs') ?></span>
                    </button>

                    <button type="button" 
                            onclick="openAuditLogsModal('deduct')" 
                            class="btn-top-toolbar inline-flex items-center gap-2 px-3.5 py-2.5 rounded-xl bg-[#18181c] border border-[#262630] text-xs font-semibold text-[#c5c5d2] hover:text-white hover:border-rose-500/50 hover:bg-[#1f1f26] transition-all cursor-pointer shadow-sm">
                        <i class="fa-solid fa-cart-arrow-down text-rose-400"></i>
                        <span><?= current_lang() === 'km' ? 'ប្រវត្តិដកស្តុក' : 'Deduct History' ?></span>
                    </button>

                    <button type="button" 
                            onclick="openAddStockModal()" 
                            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-[#10b981] to-[#059669] text-white text-xs font-bold hover:brightness-110 active:scale-95 transition-all cursor-pointer shadow-lg shadow-[#10b981]/25">
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
                               class="w-full pl-10 pr-9 py-2.5 rounded-xl bg-[#141418] border border-[#252530] text-sm text-[var(--text-main)] placeholder-[#727282] focus:outline-none focus:border-[#10b981] focus:ring-1 focus:ring-[#10b981] transition-all">
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
                <div class="stock-table-scroll-container flex-1">
                    <table class="w-full text-left border-separate border-spacing-0 text-xs">
                        <thead class="sticky top-0 z-20 shadow-sm">
                            <tr class="table-header-cell bg-[#141418] text-[#8e8e9f] uppercase tracking-wider font-semibold">
                                <th class="sticky top-0 z-20 py-3.5 px-4 bg-[#141418] border-b border-[#24242b]"><?= __('col_drink_product', 'Drink Product') ?></th>
                                <th class="sticky top-0 z-20 py-3.5 px-3 bg-[#141418] border-b border-[#24242b]"><?= __('col_unit', 'Unit') ?></th>
                                <th class="sticky top-0 z-20 py-3.5 px-3 bg-[#141418] border-b border-[#24242b]"><?= __('col_conversion_rate', 'Conversion Rate') ?></th>
                                <th class="sticky top-0 z-20 py-3.5 px-3 bg-[#141418] border-b border-[#24242b]"><?= __('col_total_qty', 'Total Qty (Units)') ?></th>
                                <th class="sticky top-0 z-20 py-3.5 px-3 bg-[#141418] border-b border-[#24242b]"><?= __('col_breakdown', 'Package Breakdown') ?></th>
                                <th class="sticky top-0 z-20 py-3.5 px-3 bg-[#141418] border-b border-[#24242b]"><?= __('col_cost_box_unit', 'Cost (Per Box / Unit)') ?></th>
                                <th class="sticky top-0 z-20 py-3.5 px-3 bg-[#141418] border-b border-[#24242b]"><?= __('col_sell_price_unit', 'Sell Price / Unit') ?></th>
                                <th class="sticky top-0 z-20 py-3.5 px-3 bg-[#141418] border-b border-[#24242b]"><?= __('col_sell_price_box', 'Sell Price / Box') ?></th>
                                <th class="sticky top-0 z-20 py-3.5 px-3 bg-[#141418] border-b border-[#24242b]"><?= __('col_status', 'Status') ?></th>
                                <th class="sticky top-0 z-20 py-3.5 px-3 bg-[#141418] border-b border-[#24242b]"><?= __('col_valuation', 'Valuation') ?></th>
                                <th class="sticky top-0 z-20 py-3.5 px-4 bg-[#141418] border-b border-[#24242b] text-right"><?= __('col_actions', 'Actions') ?></th>
                            </tr>
                        </thead>
                        <tbody id="stockTableBody" class="table-divide divide-y divide-[#1f1f28]">
                            <!-- Initial PHP render -->
                            <?php 
                            $isKm = (function_exists('current_lang') ? current_lang() : ($_SESSION['lang'] ?? 'km')) === 'km';
                            foreach ($stockItems as $item): 
                                $qty = (float)$item['quantity'];
                                $rate = max(1.0, (float)$item['conversion_rate']);
                                $alert = (float)$item['alert_level'];
                                $costUnit = (float)$item['cost_per_unit'];
                                $costBox = (float)$item['cost_per_purchase_unit'];
                                $sellPriceUnit = (float)($item['selling_price_per_unit'] ?? 0);
                                $sellPriceBox = (float)($item['selling_price_per_box'] ?? ($sellPriceUnit * $rate));
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
                                        <div class="flex items-center gap-1.5 flex-shrink-0">
                                            <!-- Unit Image -->
                                            <?php 
                                            $hasUnitImg = !empty($item['image']) && trim($item['image']) !== '' && !str_contains($item['image'], 'no-image.png');
                                            ?>
                                            <div class="item-mini-img <?= !$hasUnitImg ? 'tint-unit' : '' ?>" title="<?= __('unit_image', 'រូបភាពរាយ (Unit Image)') ?>">
                                                <?php if ($hasUnitImg): ?>
                                                    <?php $imgUnitSrc = get_image_url($item['image']); ?>
                                                    <img src="<?= htmlspecialchars($imgUnitSrc) ?>" alt="<?= htmlspecialchars($item['item_name']) ?>" onerror="this.onerror=null; this.parentNode.classList.add('tint-unit'); this.remove();">
                                                <?php else: ?>
                                                    <svg class="w-4 h-4 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m10 2 2 5"/><path d="M6 7h12l-1.5 13.5a2 2 0 0 1-2 1.5H9.5a2 2 0 0 1-2-1.5L6 7Z"/></svg>
                                                <?php endif; ?>
                                                <span class="mini-img-tag unit-tag"><?= $isKm ? 'រាយ' : 'Unit' ?></span>
                                            </div>

                                            <!-- Box Image -->
                                            <?php 
                                            $hasBoxImg = !empty($item['image_box']) && trim($item['image_box']) !== '' && !str_contains($item['image_box'], 'no-image.png');
                                            ?>
                                            <div class="item-mini-img <?= !$hasBoxImg ? 'tint-box' : '' ?>" title="<?= __('box_image', 'រូបភាពកេស (Box Image)') ?>">
                                                <?php if ($hasBoxImg): ?>
                                                    <?php $imgBoxSrc = get_image_url($item['image_box']); ?>
                                                    <img src="<?= htmlspecialchars($imgBoxSrc) ?>" alt="<?= htmlspecialchars($item['item_name']) ?> Box" onerror="this.onerror=null; this.parentNode.classList.add('tint-box'); this.remove();">
                                                <?php else: ?>
                                                    <svg class="w-4 h-4 text-amber-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                                                <?php endif; ?>
                                                <span class="mini-img-tag box-tag"><?= $isKm ? 'កេស' : 'Box' ?></span>
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="item-name-text font-bold text-[var(--text-main)] text-sm group-hover:text-[#10b981] transition-colors truncate">
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
                                    <span class="threshold-badge px-2.5 py-1 rounded-lg bg-[#101726] border border-emerald-500/25 text-xs font-bold text-[#34d399]">
                                        <?= htmlspecialchars($breakdown) ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-3 text-xs">
                                    <div class="font-bold text-[var(--text-main)]">$<?= number_format($costBox, 2) ?> / <?= formatUnitLabel($item['purchase_unit'], 1) ?></div>
                                    <div class="text-[11px] text-[#8e8e9f] mt-0.5">$<?= number_format($costUnit, 4) ?> / <?= formatUnitLabel($item['unit'], 1) ?></div>
                                </td>
                                <td class="py-3.5 px-3 text-xs font-semibold">
                                    <div class="font-bold text-[var(--text-main)]">$<?= number_format($sellPriceUnit, 2) ?></div>
                                    <div class="text-[11px] text-[#8e8e9f] mt-0.5">/ <?= htmlspecialchars(formatUnitLabel($item['unit'], 1)) ?></div>
                                </td>
                                <td class="py-3.5 px-3 text-xs font-semibold">
                                    <div class="font-bold text-[var(--text-main)]">$<?= number_format($sellPriceBox, 2) ?></div>
                                    <div class="text-[11px] text-[#8e8e9f] mt-0.5">/ <?= htmlspecialchars(formatUnitLabel($item['purchase_unit'], 1)) ?></div>
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
                                                class="px-2.5 py-1.5 rounded-lg bg-emerald-500/15 text-emerald-400 hover:bg-[#10b981] hover:text-white font-bold transition-all cursor-pointer border border-emerald-500/30" 
                                                title="<?= __('btn_restock', 'Restock') ?>">
                                            <i class="fa-solid fa-boxes-stacked mr-1"></i> <?= __('btn_restock', 'Restock') ?>
                                        </button>
                                        <!-- Quick Stock Deduction -->
                                        <button type="button" 
                                                onclick="openDeductStockModal(<?= $item['item_id'] ?>)" 
                                                class="px-2.5 py-1.5 rounded-lg bg-rose-500/15 text-rose-400 hover:bg-rose-600 hover:text-white font-bold transition-all cursor-pointer border border-rose-500/30" 
                                                title="<?= current_lang() === 'km' ? 'ដកស្ដុក (កែតម្រូវទិន្នន័យ)' : 'Deduct / Reduce Stock' ?>">
                                            <i class="fa-solid fa-box-open mr-1"></i> <?= current_lang() === 'km' ? 'ដកស្ដុក' : 'Deduct' ?>
                                        </button>
                                        <!-- Edit -->
                                        <button type="button" 
                                                onclick="openEditStockModal(<?= $item['item_id'] ?>)" 
                                                class="btn-action-neutral btn-act-edit" 
                                                title="<?= __('btn_edit', 'Edit') ?>">
                                            <i class="fa-solid fa-pen-to-square w-4 text-center"></i>
                                        </button>
                                        <!-- Delete -->
                                        <button type="button" 
                                                onclick="confirmDeleteItem(<?= $item['item_id'] ?>, '<?= addslashes(htmlspecialchars($item['item_name'])) ?>')" 
                                                class="btn-action-neutral btn-act-delete" 
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
    <div id="addStockModal" class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-3 md:p-4 bg-black/80 backdrop-blur-sm">
        <div class="modal-content max-w-2xl w-full bg-white rounded-3xl shadow-2xl overflow-hidden border border-slate-100 relative max-h-[92vh] flex flex-col">
            <!-- Header -->
            <div class="modal-header flex items-center justify-between px-6 py-4 bg-[#121528] text-white shrink-0">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-[#1e2340] border border-indigo-500/30 text-indigo-400 flex items-center justify-center text-base shadow-inner">
                        <i class="fa-solid fa-wine-bottle"></i>
                    </div>
                    <div>
                        <h3 class="modal-title text-base md:text-lg font-bold text-white leading-tight">
                            <?= current_lang() === 'km' ? 'បន្ថែមភេសជ្ជៈដប/កំប៉ុង' : 'Add Canned / Bottled Drink' ?>
                        </h3>
                        <p class="text-[11px] md:text-xs text-slate-400 mt-0.5 font-normal">
                            <?= current_lang() === 'km' ? 'កំណត់ខ្នាត តម្លៃដើម និងតម្លៃលក់ចេញក្នុងស្តុក' : 'Configure packaging units, purchase costs & retail prices' ?>
                        </p>
                    </div>
                </div>
                <button type="button" onclick="closeModal('addStockModal')" class="w-8 h-8 rounded-xl bg-white/5 hover:bg-white/15 text-slate-400 hover:text-white flex items-center justify-center transition-all cursor-pointer">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>

            <!-- Body -->
            <form id="addStockForm" onsubmit="handleAddStock(event)" class="flex-1 overflow-y-auto p-5 md:p-6 space-y-4 text-slate-800 bg-[#f8fafc]/50">
                <input type="hidden" name="action" value="create_item">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <!-- Top Section: 2 Columns (Inputs + Square Image Dropzone) -->
                <div class="flex flex-col md:flex-row gap-4 items-start">
                    <div class="flex-1 space-y-3 w-full">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">
                                <?= current_lang() === 'km' ? 'ឈ្មោះភេសជ្ជៈ (Drink Name)' : 'Drink Name' ?> <span class="text-rose-500">*</span>
                            </label>
                            <input type="text" 
                                   name="item_name" 
                                   id="addStockItemName"
                                   required 
                                   autocomplete="off"
                                   placeholder="e.g. Sting Energy Drink 250ml, Coca-Cola 330ml" 
                                   class="w-full px-3.5 py-2.5 rounded-xl bg-white border border-slate-200 text-xs font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 transition-all">
                            <div id="addStockDupAlert" class="hidden mt-1 text-[11px] text-rose-500 font-semibold flex items-center gap-1">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                <span></span>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">
                                <?= current_lang() === 'km' ? 'អ្នកផ្គត់ផ្គង់ / កំណត់សម្គាល់ (Supplier & Notes)' : 'Supplier & Notes' ?>
                            </label>
                            <input type="text" 
                                   name="notes" 
                                   placeholder="e.g. Cambodia Beverage Co." 
                                   class="w-full px-3.5 py-2.5 rounded-xl bg-white border border-slate-200 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 transition-all">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">
                                <?= current_lang() === 'km' ? 'ក្រុមប្រភេទភេសជ្ជៈ (Drink Category)' : 'Drink Category' ?> <span class="text-rose-500">*</span>
                            </label>
                            <div class="relative">
                                <select name="category_id" 
                                        id="addStockCategoryId" 
                                        required 
                                        class="w-full px-3.5 py-2.5 rounded-xl bg-white border border-slate-200 text-xs font-semibold text-slate-800 focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 transition-all appearance-none cursor-pointer">
                                    <option value=""><?= current_lang() === 'km' ? '-- ជ្រើសរើសក្រុមប្រភេទ --' : '-- Select Category --' ?></option>
                                    <?php foreach ($activeCategories as $cat): ?>
                                        <option value="<?= (int)$cat['category_id'] ?>" <?= ($cat['slug'] === 'Drinks' || stripos($cat['name'], 'Drink') !== false || stripos($cat['name'], 'ភេសជ្ជៈ') !== false) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat['name']) ?> (<?= htmlspecialchars($cat['slug']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3.5 text-slate-400">
                                    <i class="fa-solid fa-chevron-down text-xs"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right 2 Image Upload Square Boxes: Unit & Box -->
                    <div class="flex items-center gap-3 shrink-0 mx-auto md:mx-0">
                        <!-- Image 1: Unit Image -->
                        <div class="flex flex-col items-center">
                            <label class="block text-[11px] font-bold text-slate-700 mb-1 text-center">
                                <?= current_lang() === 'km' ? 'រូបភាពរាយ' : 'Unit Image' ?>
                            </label>
                            <div class="w-[110px] h-[110px] aspect-square border-2 border-dashed border-indigo-200 hover:border-indigo-400 bg-white rounded-2xl p-1.5 flex flex-col items-center justify-center text-center cursor-pointer transition-all relative overflow-hidden group shadow-sm shrink-0"
                                 onclick="document.getElementById('addStockImageInput').click()" 
                                 title="<?= current_lang() === 'km' ? 'ចុចដើម្បីជ្រើសរើសរូបភាពរាយ' : 'Click to choose unit image' ?>">
                                <img id="addStockImagePreview" src="" alt="" style="display:none;" class="absolute inset-0 w-full h-full object-cover rounded-2xl">
                                <button type="button" 
                                        id="addStockImageRemoveBtn" 
                                        style="display:none;" 
                                        onclick="event.stopPropagation(); removeStockImage('addStockImageInput', 'addStockImagePreview', 'addStockImagePlaceholder', 'addStockImageHoverOverlay', 'addStockImageRemoveBtn');" 
                                        class="absolute top-1 right-1 w-6 h-6 rounded-full bg-rose-500 hover:bg-rose-600 active:scale-90 text-white flex items-center justify-center text-xs shadow-md z-30 transition-all border border-white cursor-pointer"
                                        title="<?= current_lang() === 'km' ? 'លុបរូបភាព' : 'Remove image' ?>">
                                    <i class="fa-solid fa-xmark text-[11px]"></i>
                                </button>
                                <div id="addStockImageHoverOverlay" style="display:none;" class="absolute inset-0 bg-black/40 text-white flex flex-col items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity rounded-2xl">
                                    <i class="fa-solid fa-arrow-up-from-bracket text-sm mb-0.5"></i>
                                    <span class="text-[9px] font-bold"><?= current_lang() === 'km' ? 'ប្តូររូប' : 'Change' ?></span>
                                </div>
                                <div id="addStockImagePlaceholder" class="flex flex-col items-center justify-center pointer-events-none p-1">
                                    <div class="w-7 h-7 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-xs mb-1 group-hover:scale-110 transition-transform">
                                        <i class="fa-regular fa-image"></i>
                                    </div>
                                    <span class="text-[10px] font-bold text-indigo-600 mb-0.5 leading-tight"><?= current_lang() === 'km' ? 'រូបរាយ' : 'Unit' ?></span>
                                    <span class="text-[8.5px] text-slate-400 leading-tight">កំប៉ុង/ដប</span>
                                </div>
                                <input type="file" 
                                       name="image" 
                                       id="addStockImageInput" 
                                       accept="image/*" 
                                       onchange="previewStockImageLight(this, 'addStockImagePreview', 'addStockImagePlaceholder', 'addStockImageHoverOverlay', 'addStockImageRemoveBtn')" 
                                       style="display:none;">
                            </div>
                        </div>

                        <!-- Image 2: Box Image -->
                        <div class="flex flex-col items-center">
                            <label class="block text-[11px] font-bold text-slate-700 mb-1 text-center">
                                <?= current_lang() === 'km' ? 'រូបភាពកេស' : 'Box Image' ?>
                            </label>
                            <div class="w-[110px] h-[110px] aspect-square border-2 border-dashed border-indigo-200 hover:border-indigo-400 bg-white rounded-2xl p-1.5 flex flex-col items-center justify-center text-center cursor-pointer transition-all relative overflow-hidden group shadow-sm shrink-0"
                                 onclick="document.getElementById('addStockImageBoxInput').click()" 
                                 title="<?= current_lang() === 'km' ? 'ចុចដើម្បីជ្រើសរើសរូបភាពកេស' : 'Click to choose box image' ?>">
                                <img id="addStockImageBoxPreview" src="" alt="" style="display:none;" class="absolute inset-0 w-full h-full object-cover rounded-2xl">
                                <button type="button" 
                                        id="addStockImageBoxRemoveBtn" 
                                        style="display:none;" 
                                        onclick="event.stopPropagation(); removeStockImage('addStockImageBoxInput', 'addStockImageBoxPreview', 'addStockImageBoxPlaceholder', 'addStockImageBoxHoverOverlay', 'addStockImageBoxRemoveBtn');" 
                                        class="absolute top-1 right-1 w-6 h-6 rounded-full bg-rose-500 hover:bg-rose-600 active:scale-90 text-white flex items-center justify-center text-xs shadow-md z-30 transition-all border border-white cursor-pointer"
                                        title="<?= current_lang() === 'km' ? 'លុបរូបភាព' : 'Remove image' ?>">
                                    <i class="fa-solid fa-xmark text-[11px]"></i>
                                </button>
                                <div id="addStockImageBoxHoverOverlay" style="display:none;" class="absolute inset-0 bg-black/40 text-white flex flex-col items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity rounded-2xl">
                                    <i class="fa-solid fa-arrow-up-from-bracket text-sm mb-0.5"></i>
                                    <span class="text-[9px] font-bold"><?= current_lang() === 'km' ? 'ប្តូររូប' : 'Change' ?></span>
                                </div>
                                <div id="addStockImageBoxPlaceholder" class="flex flex-col items-center justify-center pointer-events-none p-1">
                                    <div class="w-7 h-7 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-xs mb-1 group-hover:scale-110 transition-transform">
                                        <i class="fa-solid fa-boxes-stacked"></i>
                                    </div>
                                    <span class="text-[10px] font-bold text-indigo-600 mb-0.5 leading-tight"><?= current_lang() === 'km' ? 'រូបកេស' : 'Box' ?></span>
                                    <span class="text-[8.5px] text-slate-400 leading-tight">កេស/កាតុង</span>
                                </div>
                                <input type="file" 
                                       name="image_box" 
                                       id="addStockImageBoxInput" 
                                       accept="image/*" 
                                       onchange="previewStockImageLight(this, 'addStockImageBoxPreview', 'addStockImageBoxPlaceholder', 'addStockImageBoxHoverOverlay', 'addStockImageBoxRemoveBtn')" 
                                       style="display:none;">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Middle Section: Unit Packaging & Stock -->
                <div class="border border-slate-200/80 bg-white rounded-2xl p-4 shadow-sm space-y-3">
                    <div class="flex items-center gap-2 text-xs font-bold text-slate-800 border-b border-slate-100 pb-2">
                        <i class="fa-solid fa-shapes text-indigo-500"></i>
                        <span><?= current_lang() === 'km' ? 'ខ្នាត និងការបំប្លែងស្តុក (Unit Packaging & Stock)' : 'Unit Packaging & Stock' ?></span>
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1"><?= current_lang() === 'km' ? 'ខ្នាតរាយ *' : 'Single Unit *' ?></label>
                            <select name="unit" onchange="updateCardUnitLabels('add')" class="w-full px-3 py-2 rounded-xl bg-white border border-slate-200 text-xs font-semibold text-slate-800 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                                <option value="can" selected><?= current_lang() === 'km' ? 'កំប៉ុង (Can)' : 'Can' ?></option>
                                <option value="bottle"><?= current_lang() === 'km' ? 'ដប (Bottle)' : 'Bottle' ?></option>
                                <option value="pack"><?= current_lang() === 'km' ? 'កញ្ចប់ (Pack)' : 'Pack' ?></option>
                                <option value="pcs"><?= current_lang() === 'km' ? 'គ្រាប់ / ដុំ (Pcs)' : 'Pcs (Pieces)' ?></option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1"><?= current_lang() === 'km' ? 'ខ្នាតកេស *' : 'Package Unit *' ?></label>
                            <select name="purchase_unit" onchange="updateCardUnitLabels('add')" class="w-full px-3 py-2 rounded-xl bg-white border border-slate-200 text-xs font-semibold text-slate-800 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                                <option value="box" selected><?= current_lang() === 'km' ? 'កេស (Box)' : 'Box' ?></option>
                                <option value="pack"><?= current_lang() === 'km' ? 'យួរ (Pack)' : 'Pack (Sleeve)' ?></option>
                                <option value="package"><?= current_lang() === 'km' ? 'កញ្ចប់ (Package)' : 'Package' ?></option>
                                <option value="carton"><?= current_lang() === 'km' ? 'កាតុង (Carton)' : 'Carton' ?></option>
                                <option value="dozen"><?= current_lang() === 'km' ? 'ឡូ (Dozen)' : 'Dozen (12 pcs)' ?></option>
                                <option value="case"><?= current_lang() === 'km' ? 'កេសធំ (Case)' : 'Case' ?></option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1"><?= current_lang() === 'km' ? 'ចំនួនក្នុង១កេស *' : 'Units per Box *' ?></label>
                            <input type="number" 
                                   step="1" 
                                   min="1" 
                                   id="addConversionRate" 
                                   name="conversion_rate" 
                                   value="24" 
                                   required 
                                   oninput="onAddSellPriceUnitChange(document.getElementById('addSellPriceUnit')?.value); onAddCostBoxChange(document.getElementById('addCostBox')?.value);" 
                                   class="w-full px-3 py-2 rounded-xl bg-white border border-slate-200 text-xs font-bold text-slate-800 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-3 pt-1">
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1"><?= current_lang() === 'km' ? 'ចំនួនកេសដំបូង' : 'Initial Boxes' ?></label>
                            <input type="number" 
                                   step="1" 
                                   min="0" 
                                   name="initial_boxes" 
                                   value="0" 
                                   class="w-full px-3 py-2 rounded-xl bg-white border border-slate-200 text-xs text-slate-800 focus:outline-none focus:border-indigo-500">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1"><?= current_lang() === 'km' ? 'ចំនួនរាយបន្ថែម' : 'Loose Units' ?></label>
                            <input type="number" 
                                   step="1" 
                                   min="0" 
                                   name="initial_loose" 
                                   value="0" 
                                   class="w-full px-3 py-2 rounded-xl bg-white border border-slate-200 text-xs text-slate-800 focus:outline-none focus:border-indigo-500">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1"><?= current_lang() === 'km' ? 'កម្រិតប្រកាសអាសន្ន' : 'Alert Threshold' ?></label>
                            <input type="number" 
                                   step="any" 
                                   min="0" 
                                   name="alert_level" 
                                   value="24" 
                                   class="w-full px-3 py-2 rounded-xl bg-white border border-slate-200 text-xs text-slate-800 focus:outline-none focus:border-indigo-500">
                        </div>
                    </div>
                </div>

                <!-- Bottom Section: Cost & Selling Prices -->
                <div class="border border-slate-200/80 bg-white rounded-2xl p-4 shadow-sm space-y-3">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                        <div class="flex items-center gap-2 text-xs font-bold text-slate-800">
                            <i class="fa-solid fa-circle-dollar-to-slot text-indigo-500"></i>
                            <span><?= current_lang() === 'km' ? 'ការកំណត់តម្លៃ (Cost & Selling Prices)' : 'Cost & Selling Prices' ?></span>
                        </div>
                        <span class="text-[11px] text-slate-400 font-medium">
                            <?= current_lang() === 'km' ? 'គណនាប្រាក់ចំណេញដោយស្វ័យប្រវត្តិ' : 'Auto calculates profit & conversions' ?>
                        </span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3.5">
                        <!-- Sub Card: Unit -->
                        <div class="bg-slate-50/70 border border-slate-200 rounded-xl p-3.5 space-y-2.5">
                            <div class="text-xs font-bold text-slate-800" id="addSubCardUnitTitle">
                                <?= current_lang() === 'km' ? 'ខ្នាតរាយ (Per Unit / Can)' : 'Single Unit (Per Unit)' ?>
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold text-slate-600 mb-1"><?= current_lang() === 'km' ? 'ថ្លៃដើម/ខ្នាត ($)' : 'Cost/Unit ($)' ?></label>
                                <input type="number" 
                                       step="0.0001" 
                                       min="0" 
                                       name="cost_per_unit" 
                                       id="addCostUnit" 
                                       value="0.5000" 
                                       oninput="onAddCostUnitChange(this.value)" 
                                       class="w-full px-3 py-2 rounded-xl bg-white border border-slate-200 text-xs text-slate-700 focus:outline-none focus:border-indigo-500">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-700 mb-1"><?= current_lang() === 'km' ? 'តម្លៃលក់/ខ្នាត ($) *' : 'Sell Price/Unit ($) *' ?></label>
                                <input type="number" 
                                       step="0.01" 
                                       min="0" 
                                       name="selling_price_per_unit" 
                                       id="addSellPriceUnit" 
                                       value="1.00" 
                                       required 
                                       class="w-full px-3 py-2 rounded-xl bg-emerald-50/30 border border-emerald-400 text-xs font-bold text-emerald-800 focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20">
                            </div>
                        </div>

                        <!-- Sub Card: Box -->
                        <div class="bg-slate-50/70 border border-slate-200 rounded-xl p-3.5 space-y-2.5">
                            <div class="text-xs font-bold text-slate-800" id="addSubCardBoxTitle">
                                <?= current_lang() === 'km' ? 'ខ្នាតកេស (Per Box / Carton)' : 'Package (Per Box)' ?>
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold text-slate-600 mb-1"><?= current_lang() === 'km' ? 'ថ្លៃដើម/កេស ($)' : 'Cost/Box ($)' ?></label>
                                <input type="number" 
                                       step="0.01" 
                                       min="0" 
                                       name="cost_per_purchase_unit" 
                                       id="addCostBox" 
                                       value="12.00" 
                                       oninput="onAddCostBoxChange(this.value)" 
                                       class="w-full px-3 py-2 rounded-xl bg-white border border-slate-200 text-xs text-slate-700 focus:outline-none focus:border-indigo-500">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-indigo-700 mb-1"><?= current_lang() === 'km' ? 'តម្លៃលក់/កេស ($)' : 'Sell Price/Box ($)' ?></label>
                                <input type="number" 
                                       step="0.01" 
                                       min="0" 
                                       name="selling_price_per_box" 
                                       id="addSellPriceBox" 
                                       value="24.00" 
                                       class="w-full px-3 py-2 rounded-xl bg-indigo-50/30 border border-indigo-300 text-xs font-bold text-indigo-800 focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="modal-footer flex items-center justify-end gap-3 pt-3 border-t border-slate-200">
                    <button type="button" 
                            onclick="closeModal('addStockModal')" 
                            class="px-4 py-2.5 rounded-xl text-xs font-semibold text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition-all cursor-pointer">
                        <?= current_lang() === 'km' ? 'បោះបង់ (Cancel)' : 'Cancel' ?>
                    </button>
                    <button type="submit" 
                            id="addStockSubmitBtn" 
                            class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold transition-all shadow-lg shadow-indigo-600/25 flex items-center gap-1.5 cursor-pointer">
                        <i class="fa-solid fa-check text-xs"></i>
                        <span><?= current_lang() === 'km' ? 'រក្សាទុកទិន្នន័យ (Save Drink)' : 'Save Direct Drink' ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         MODAL 2: QUICK BOX & LOOSE RESTOCK
    ══════════════════════════════════════════════════════════════ -->
    <div id="restockModal" class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs transition-opacity duration-200">
        <div class="modal-content max-w-xl w-full bg-white rounded-3xl shadow-2xl overflow-hidden border border-slate-100 relative font-['Poppins','Kantumruy_Pro',sans-serif]">
            <!-- Modal Header (Dark Slate / Navy) -->
            <div class="px-6 py-5 bg-[#0f172a] text-white flex items-center justify-between relative">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-indigo-500/20 border border-indigo-500/30 text-indigo-400 flex items-center justify-center text-lg shadow-inner">
                        <i class="fa-solid fa-boxes-stacked"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-white tracking-tight"><?= current_lang() === 'km' ? 'បញ្ចូលស្តុកភេសជ្ជៈ' : __('quick_restock', 'Quick Restock') ?></h3>
                        <p class="text-xs text-slate-400 font-medium"><?= current_lang() === 'km' ? 'គាំទ្រការបញ្ចូលជាកេស និងចំនួនរាយព្រមគ្នា' : 'Supports adding by boxes and loose units simultaneously' ?></p>
                    </div>
                </div>
                <button type="button" onclick="closeModal('restockModal')" class="w-8 h-8 rounded-full bg-slate-800/80 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center transition cursor-pointer text-sm">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form id="restockForm" onsubmit="handleQuickRestock(event)" class="p-6 space-y-4 bg-white">
                <input type="hidden" name="action" value="quick_restock">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <!-- 1. Drink Selection -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5"><?= current_lang() === 'km' ? 'ឈ្មោះភេសជ្ជៈ' : __('col_drink_product', 'Select Drink') ?> <span class="text-rose-500">*</span></label>
                    <div class="relative">
                        <select name="item_id" 
                                id="restockItemSelect" 
                                required 
                                onchange="updateRestockModalPreview()" 
                                class="w-full px-4 py-3 rounded-2xl bg-white border border-slate-200 text-xs md:text-sm font-bold text-slate-800 focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 shadow-xs appearance-none">
                            <option value="">-- <?= current_lang() === 'km' ? 'ជ្រើសរើសភេសជ្ជៈ' : 'Choose Drink' ?> --</option>
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
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-slate-500">
                            <i class="fa-solid fa-chevron-down text-xs"></i>
                        </div>
                    </div>
                </div>

                <!-- 2. Dual Inputs: Boxes vs Loose Units -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <!-- Left: Box Input Card -->
                    <div class="p-3.5 rounded-2xl bg-slate-50/70 border border-slate-200/80 flex flex-col justify-between gap-2">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-black text-slate-700 flex items-center gap-1.5">
                                <i class="fa-solid fa-cube text-indigo-500"></i>
                                <?= current_lang() === 'km' ? 'បញ្ចូលជាកេស' : 'Add Boxes' ?>
                            </span>
                            <span class="px-2 py-0.5 rounded-md bg-indigo-50 text-indigo-600 text-[10px] font-black border border-indigo-100">
                                <?= current_lang() === 'km' ? 'ខ្នាតធំ' : 'Bulk' ?>
                            </span>
                        </div>
                        <div class="flex items-center justify-between gap-2 mt-0.5">
                            <input type="number" 
                                   step="any" 
                                   min="0" 
                                   name="purchase_qty" 
                                   id="restockQtyInput" 
                                   placeholder="0" 
                                   oninput="calculateRestockTotal()" 
                                   class="w-full bg-transparent text-2xl font-black text-slate-900 focus:outline-none tracking-tight">
                            <span id="restockBoxUnitName" class="text-xs font-bold text-slate-500 whitespace-nowrap">កេស</span>
                        </div>
                        <div class="text-[11px] font-semibold text-slate-400 pt-1 border-t border-slate-200/60 flex items-center gap-1">
                            <span>=</span> <b id="restockBoxToLoosePreview" class="text-indigo-600 font-bold">0</b> <span><?= current_lang() === 'km' ? 'ឯកតារាយ' : 'loose units' ?></span>
                        </div>
                    </div>

                    <!-- Right: Loose Units Input Card -->
                    <div class="p-3.5 rounded-2xl bg-slate-50/70 border border-slate-200/80 flex flex-col justify-between gap-2">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-black text-slate-700 flex items-center gap-1.5">
                                <i class="fa-solid fa-glass-water text-emerald-500"></i>
                                <?= current_lang() === 'km' ? 'បញ្ចូលរាយ' : 'Add Loose Units' ?>
                            </span>
                            <span class="px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-600 text-[10px] font-black border border-emerald-100">
                                <?= current_lang() === 'km' ? 'ខ្នាតរាយ' : 'Loose' ?>
                            </span>
                        </div>
                        <div class="flex items-center justify-between gap-2 mt-0.5">
                            <input type="number" 
                                   step="any" 
                                   min="0" 
                                   name="loose_qty" 
                                   id="restockLooseQtyInput" 
                                   placeholder="0" 
                                   oninput="calculateRestockTotal()" 
                                   class="w-full bg-transparent text-2xl font-black text-slate-900 focus:outline-none tracking-tight">
                            <span id="restockLooseUnitName" class="text-xs font-bold text-slate-500 whitespace-nowrap">កំប៉ុង</span>
                        </div>
                        <div class="text-[11px] font-semibold text-slate-400 pt-1 border-t border-slate-200/60">
                            <?= current_lang() === 'km' ? 'ថែមផ្ទាល់ចូលស្តុករាយ' : 'Add directly to loose stock' ?>
                        </div>
                    </div>
                </div>

                <!-- 3. Cost Per Box Input Card -->
                <div class="p-3.5 rounded-2xl bg-white border border-slate-200/80 shadow-xs space-y-1">
                    <label class="block text-xs font-bold text-slate-700"><?= current_lang() === 'km' ? 'ថ្លៃដើមក្នុង១កេស ($)' : __('col_cost_box_unit', 'Cost Per Box ($)') ?></label>
                    <div class="relative flex items-center">
                        <span class="absolute left-3.5 text-sm font-bold text-slate-400 select-none">$</span>
                        <input type="number" 
                               step="0.01" 
                               min="0" 
                               name="cost_per_box" 
                               id="restockCostInput" 
                               placeholder="12.00" 
                               class="w-full pl-8 pr-4 py-2 rounded-xl bg-slate-50/50 border border-slate-200 text-sm font-bold text-slate-900 focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20">
                    </div>
                </div>

                <!-- 4. Total Added Units Highlight Card (Soft Indigo Lavender Box) -->
                <div id="restockPreviewCard" class="p-4 rounded-2xl bg-indigo-50/60 border border-indigo-200/80 text-xs space-y-2.5 shadow-xs">
                    <div class="flex items-center justify-between font-black text-indigo-950">
                        <span class="flex items-center gap-2 text-xs md:text-sm">
                            <i class="fa-solid fa-calculator text-indigo-600 text-sm"></i> <?= current_lang() === 'km' ? 'សរុបស្តុកត្រូវបន្ថែម:' : 'Total Added Units:' ?>
                        </span>
                        <span id="restockBadgeUnits" class="px-3 py-1 rounded-2xl bg-white border border-indigo-200 text-indigo-600 font-black text-sm shadow-xs">+0 កំប៉ុង</span>
                    </div>
                    <p id="restockFormula" class="text-xs font-bold text-indigo-900/80 leading-relaxed">(0 កេស × 24) + 0 រាយ = +0 កំប៉ុង</p>
                    <div class="pt-2 border-t border-indigo-200/60 flex items-center justify-between text-xs font-bold">
                        <span id="restockCurrentStock" class="text-slate-600"><?= current_lang() === 'km' ? 'ស្តុកបច្ចុប្បន្ន:' : 'Current:' ?> --</span>
                        <span id="restockProjectedStock" class="text-emerald-700 font-black"><?= current_lang() === 'km' ? 'ស្តុកសរុបថ្មី:' : 'New Total:' ?> --</span>
                    </div>
                </div>

                <!-- 5. Footer -->
                <div class="flex items-center justify-end gap-3 pt-2">
                    <button type="button" 
                            onclick="closeModal('restockModal')" 
                            class="px-5 py-2.5 rounded-xl text-xs font-bold text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition-all cursor-pointer">
                        <?= current_lang() === 'km' ? 'បោះបង់' : 'Cancel' ?>
                    </button>
                    <button type="submit" 
                            id="restockSubmitBtn" 
                            class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-black transition-all shadow-lg shadow-indigo-600/25 flex items-center gap-1.5 cursor-pointer">
                        <i class="fa-solid fa-check text-xs"></i>
                        <span><?= current_lang() === 'km' ? 'បញ្ជាក់ការបញ្ចូលស្តុក' : 'Confirm Restock' ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         MODAL 2.5: DEDUCT / REDUCE STOCK (កែតម្រូវ / ដកស្តុក)
    ══════════════════════════════════════════════════════════════ -->
    <div id="deductStockModal" class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs transition-opacity duration-200">
        <div class="modal-content max-w-xl w-full bg-white rounded-3xl shadow-2xl overflow-hidden border border-slate-100 relative font-['Poppins','Kantumruy_Pro',sans-serif]">
            <!-- Modal Header (Dark Slate / Crimson Rose Accent) -->
            <div class="px-6 py-5 bg-[#0f172a] text-white flex items-center justify-between relative border-b border-rose-500/20">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-rose-500/20 border border-rose-500/30 text-rose-400 flex items-center justify-center text-lg shadow-inner">
                        <i class="fa-solid fa-box-open"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-white tracking-tight"><?= current_lang() === 'km' ? 'ដកស្ដុកភេសជ្ជៈ (កែតម្រូវទិន្នន័យ)' : 'Deduct Stock / Correction' ?></h3>
                        <p class="text-xs text-slate-400 font-medium"><?= current_lang() === 'km' ? 'ដកចំនួនកេស ឬចំនួនរាយចេញពីស្តុកពេលបញ្ចូលខុស ឬខូចខាត' : 'Deduct boxes or loose units due to wrong input or damage' ?></p>
                    </div>
                </div>
                <button type="button" onclick="closeModal('deductStockModal')" class="w-8 h-8 rounded-full bg-slate-800/80 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center transition cursor-pointer text-sm">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form id="deductStockForm" onsubmit="handleDeductStock(event)" class="p-6 space-y-4 bg-white">
                <input type="hidden" name="action" value="deduct_stock">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <!-- 1. Drink Selection -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5"><?= current_lang() === 'km' ? 'ឈ្មោះភេសជ្ជៈដែលត្រូវដក' : 'Select Drink to Deduct' ?> <span class="text-rose-500">*</span></label>
                    <div class="relative">
                        <select name="item_id" 
                                id="deductItemSelect" 
                                required 
                                onchange="updateDeductModalPreview()" 
                                class="w-full px-4 py-3 rounded-2xl bg-white border border-slate-200 text-xs md:text-sm font-bold text-slate-800 focus:outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-500/20 shadow-xs appearance-none">
                            <option value="">-- <?= current_lang() === 'km' ? 'ជ្រើសរើសភេសជ្ជៈ' : 'Choose Drink' ?> --</option>
                            <?php foreach ($stockItems as $it): ?>
                            <option value="<?= $it['item_id'] ?>" 
                                    data-unit="<?= htmlspecialchars($it['unit']) ?>" 
                                    data-punit="<?= htmlspecialchars($it['purchase_unit']) ?>"
                                    data-rate="<?= (float)$it['conversion_rate'] ?>"
                                    data-qty="<?= (float)$it['quantity'] ?>" 
                                    data-boxcost="<?= (float)$it['cost_per_purchase_unit'] ?>">
                                <?= htmlspecialchars($it['item_name']) ?> (1 <?= htmlspecialchars(formatUnitLabel($it['purchase_unit'], 1)) ?> = <?= (int)$it['conversion_rate'] ?> <?= htmlspecialchars(formatUnitLabel($it['unit'], (float)$it['conversion_rate'])) ?>) — <?= current_lang() === 'km' ? 'មាន' : 'Stock:' ?> <?= (float)$it['quantity'] ?> <?= htmlspecialchars(formatUnitLabel($it['unit'], (float)$it['quantity'])) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-slate-500">
                            <i class="fa-solid fa-chevron-down text-xs"></i>
                        </div>
                    </div>
                </div>

                <!-- 2. Dual Inputs: Boxes vs Loose Units -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <!-- Left: Deduct Box Input Card -->
                    <div class="p-3.5 rounded-2xl bg-rose-50/40 border border-rose-200/70 flex flex-col justify-between gap-2">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-black text-slate-700 flex items-center gap-1.5">
                                <i class="fa-solid fa-cube text-rose-500"></i>
                                <?= current_lang() === 'km' ? 'ដកជាកេស' : 'Deduct Boxes' ?>
                            </span>
                            <span class="px-2 py-0.5 rounded-md bg-rose-100 text-rose-600 text-[10px] font-black border border-rose-200">
                                <?= current_lang() === 'km' ? 'ខ្នាតធំ' : 'Bulk' ?>
                            </span>
                        </div>
                        <div class="flex items-center justify-between gap-2 mt-0.5">
                            <input type="number" 
                                   step="any" 
                                   min="0" 
                                   name="purchase_qty" 
                                   id="deductQtyInput" 
                                   placeholder="0" 
                                   oninput="calculateDeductTotal()" 
                                   class="w-full bg-transparent text-2xl font-black text-slate-900 focus:outline-none tracking-tight">
                            <span id="deductBoxUnitName" class="text-xs font-bold text-slate-500 whitespace-nowrap">កេស</span>
                        </div>
                        <div class="text-[11px] font-semibold text-slate-400 pt-1 border-t border-rose-200/50 flex items-center gap-1">
                            <span>= -</span> <b id="deductBoxToLoosePreview" class="text-rose-600 font-bold">0</b> <span><?= current_lang() === 'km' ? 'ឯកតារាយ' : 'loose units' ?></span>
                        </div>
                    </div>

                    <!-- Right: Deduct Loose Units Input Card -->
                    <div class="p-3.5 rounded-2xl bg-rose-50/40 border border-rose-200/70 flex flex-col justify-between gap-2">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-black text-slate-700 flex items-center gap-1.5">
                                <i class="fa-solid fa-glass-water text-rose-500"></i>
                                <?= current_lang() === 'km' ? 'ដកជារាយ' : 'Deduct Loose Units' ?>
                            </span>
                            <span class="px-2 py-0.5 rounded-md bg-rose-100 text-rose-600 text-[10px] font-black border border-rose-200">
                                <?= current_lang() === 'km' ? 'ខ្នាតរាយ' : 'Loose' ?>
                            </span>
                        </div>
                        <div class="flex items-center justify-between gap-2 mt-0.5">
                            <input type="number" 
                                   step="any" 
                                   min="0" 
                                   name="loose_qty" 
                                   id="deductLooseQtyInput" 
                                   placeholder="0" 
                                   oninput="calculateDeductTotal()" 
                                   class="w-full bg-transparent text-2xl font-black text-slate-900 focus:outline-none tracking-tight">
                            <span id="deductLooseUnitName" class="text-xs font-bold text-slate-500 whitespace-nowrap">កំប៉ុង</span>
                        </div>
                        <div class="text-[11px] font-semibold text-slate-400 pt-1 border-t border-rose-200/50">
                            <?= current_lang() === 'km' ? 'ដកផ្ទាល់ពីស្តុករាយ' : 'Deduct directly from loose stock' ?>
                        </div>
                    </div>
                </div>

                <!-- 3. Reason & Notes -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1"><?= current_lang() === 'km' ? 'មូលហេតុនៃការដក' : 'Reason for Deduction' ?> <span class="text-rose-500">*</span></label>
                        <select name="reason" id="deductReasonSelect" class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs font-semibold text-slate-800 focus:outline-none focus:border-rose-500">
                            <option value="បញ្ចូលខុស / កែតម្រូវស្តុក (Wrong input / Correction)" selected><?= current_lang() === 'km' ? 'បញ្ចូលខុស / កែតម្រូវស្តុក (Correction)' : 'Wrong input / Correction' ?></option>
                            <option value="ខូចខាត / ធ្លាយបែក (Damaged / Broken)"><?= current_lang() === 'km' ? 'ខូចខាត / ធ្លាយបែក (Damaged)' : 'Damaged / Broken' ?></option>
                            <option value="ផុតកំណត់កាលបរិច្ឆេទ (Expired)"><?= current_lang() === 'km' ? 'ផុតកំណត់កាលបរិច្ឆេទ (Expired)' : 'Expired' ?></option>
                            <option value="បាត់បង់ / រាប់ខ្វះ (Discrepancy / Lost)"><?= current_lang() === 'km' ? 'បាត់បង់ / រាប់ខ្វះ (Discrepancy)' : 'Discrepancy / Lost' ?></option>
                            <option value="ដកប្រើប្រាស់ផ្ទៃក្នុង (Internal Use / Testing)"><?= current_lang() === 'km' ? 'ដកប្រើប្រាស់ផ្ទៃក្នុង (Internal Use)' : 'Internal Use / Tasting' ?></option>
                            <option value="ផ្សេងៗ (Other)"><?= current_lang() === 'km' ? 'ផ្សេងៗ (Other)' : 'Other' ?></option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1"><?= current_lang() === 'km' ? 'កំណត់សម្គាល់បន្ថែម (បើមាន)' : 'Additional Note (Optional)' ?></label>
                        <input type="text" 
                               name="notes" 
                               id="deductNotesInput" 
                               placeholder="<?= current_lang() === 'km' ? 'ឧ. បញ្ចូលច្រឡំកាលពីម្សិលមិញ' : 'e.g. Mistyped quantity earlier' ?>" 
                               class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs font-semibold text-slate-800 focus:outline-none focus:border-rose-500">
                    </div>
                </div>

                <!-- 4. Total Deducted Highlight Card -->
                <div id="deductPreviewCard" class="p-4 rounded-2xl bg-rose-50/70 border border-rose-200/80 text-xs space-y-2.5 shadow-xs">
                    <div class="flex items-center justify-between font-black text-rose-950">
                        <span class="flex items-center gap-2 text-xs md:text-sm">
                            <i class="fa-solid fa-calculator text-rose-600 text-sm"></i> <?= current_lang() === 'km' ? 'សរុបស្តុកត្រូវដកចេញ:' : 'Total Deducted Units:' ?>
                        </span>
                        <span id="deductBadgeUnits" class="px-3 py-1 rounded-2xl bg-white border border-rose-200 text-rose-600 font-black text-sm shadow-xs">-0 កំប៉ុង</span>
                    </div>
                    <p id="deductFormula" class="text-xs font-bold text-rose-900/80 leading-relaxed">(0 កេស × 24) + 0 រាយ = -0 កំប៉ុង</p>
                    <div class="pt-2 border-t border-rose-200/60 flex items-center justify-between text-xs font-bold">
                        <span id="deductCurrentStock" class="text-slate-600"><?= current_lang() === 'km' ? 'ស្តុកបច្ចុប្បន្ន:' : 'Current:' ?> --</span>
                        <span id="deductProjectedStock" class="text-rose-700 font-black"><?= current_lang() === 'km' ? 'ស្តុកសល់ថ្មី:' : 'New Remaining:' ?> --</span>
                    </div>
                    <div id="deductExcessWarning" class="hidden p-2.5 rounded-xl bg-rose-600 text-white font-bold text-xs flex items-center gap-2">
                        <i class="fa-solid fa-triangle-exclamation text-sm"></i>
                        <span><?= current_lang() === 'km' ? 'ចំនួនដកលើសពីស្តុកបច្ចុប្បន្ន! សូមពិនិត្យឡើងវិញ។' : 'Deduction exceeds current stock! Please check values.' ?></span>
                    </div>
                </div>

                <!-- 5. Footer -->
                <div class="flex items-center justify-end gap-3 pt-2">
                    <button type="button" 
                            onclick="closeModal('deductStockModal')" 
                            class="px-5 py-2.5 rounded-xl text-xs font-bold text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition-all cursor-pointer">
                        <?= current_lang() === 'km' ? 'បោះបង់' : 'Cancel' ?>
                    </button>
                    <button type="submit" 
                            id="deductSubmitBtn" 
                            class="px-6 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 active:scale-95 text-white text-xs font-black transition-all shadow-lg shadow-rose-600/25 flex items-center gap-1.5 cursor-pointer">
                        <i class="fa-solid fa-minus text-xs"></i>
                        <span><?= current_lang() === 'km' ? 'បញ្ជាក់ការដកស្ដុក' : 'Confirm Deduction' ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         MODAL 3: EDIT DRINK DETAILS
    ══════════════════════════════════════════════════════════════ -->
    <div id="editStockModal" class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-3 md:p-4 bg-black/80 backdrop-blur-sm">
        <div class="modal-content max-w-2xl w-full bg-white rounded-3xl shadow-2xl overflow-hidden border border-slate-100 relative max-h-[92vh] flex flex-col">
            <!-- Header -->
            <div class="modal-header flex items-center justify-between px-6 py-4 bg-[#121528] text-white shrink-0">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-[#1e2340] border border-indigo-500/30 text-indigo-400 flex items-center justify-center text-base shadow-inner">
                        <i class="fa-solid fa-pen-to-square"></i>
                    </div>
                    <div>
                        <h3 class="modal-title text-base md:text-lg font-bold text-white leading-tight">
                            <?= current_lang() === 'km' ? 'កែប្រែភេសជ្ជៈដប/កំប៉ុង' : 'Edit Canned / Bottled Drink' ?>
                        </h3>
                        <p class="text-[11px] md:text-xs text-slate-400 mt-0.5 font-normal">
                            <?= current_lang() === 'km' ? 'កំណត់ខ្នាត តម្លៃដើម និងតម្លៃលក់ចេញក្នុងស្តុក' : 'Configure packaging units, purchase costs & retail prices' ?>
                        </p>
                    </div>
                </div>
                <button type="button" onclick="closeModal('editStockModal')" class="w-8 h-8 rounded-xl bg-white/5 hover:bg-white/15 text-slate-400 hover:text-white flex items-center justify-center transition-all cursor-pointer">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>

            <!-- Body -->
            <form id="editStockForm" onsubmit="handleEditStock(event)" class="flex-1 overflow-y-auto p-5 md:p-6 space-y-4 text-slate-800 bg-[#f8fafc]/50">
                <input type="hidden" name="action" value="update_item">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="item_id" id="editItemId">

                <!-- Top Section: 2 Columns (Inputs + Square Image Dropzone) -->
                <div class="flex flex-col md:flex-row gap-4 items-start">
                    <div class="flex-1 space-y-3 w-full">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">
                                <?= current_lang() === 'km' ? 'ឈ្មោះភេសជ្ជៈ (Drink Name)' : 'Drink Name' ?> <span class="text-rose-500">*</span>
                            </label>
                            <input type="text" 
                                   id="editItemName" 
                                   name="item_name" 
                                   required 
                                   autocomplete="off"
                                   placeholder="e.g. Sting Energy Drink 250ml, Coca-Cola 330ml" 
                                   class="w-full px-3.5 py-2.5 rounded-xl bg-white border border-slate-200 text-xs font-semibold text-slate-800 placeholder-slate-400 focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 transition-all">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">
                                <?= current_lang() === 'km' ? 'អ្នកផ្គត់ផ្គង់ / កំណត់សម្គាល់ (Supplier & Notes)' : 'Supplier & Notes' ?>
                            </label>
                            <input type="text" 
                                   id="editNotes"
                                   name="notes" 
                                   placeholder="e.g. Cambodia Beverage Co." 
                                   class="w-full px-3.5 py-2.5 rounded-xl bg-white border border-slate-200 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 transition-all">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">
                                <?= current_lang() === 'km' ? 'ក្រុមប្រភេទភេសជ្ជៈ (Drink Category)' : 'Drink Category' ?> <span class="text-rose-500">*</span>
                            </label>
                            <div class="relative">
                                <select name="category_id" 
                                        id="editStockCategoryId" 
                                        required 
                                        class="w-full px-3.5 py-2.5 rounded-xl bg-white border border-slate-200 text-xs font-semibold text-slate-800 focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 transition-all appearance-none cursor-pointer">
                                    <option value=""><?= current_lang() === 'km' ? '-- ជ្រើសរើសក្រុមប្រភេទ --' : '-- Select Category --' ?></option>
                                    <?php foreach ($activeCategories as $cat): ?>
                                        <option value="<?= (int)$cat['category_id'] ?>">
                                            <?= htmlspecialchars($cat['name']) ?> (<?= htmlspecialchars($cat['slug']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3.5 text-slate-400">
                                    <i class="fa-solid fa-chevron-down text-xs"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Hidden Flags for Image Removal on Edit -->
                    <input type="hidden" name="remove_image" id="editRemoveImageFlag" value="0">
                    <input type="hidden" name="remove_image_box" id="editRemoveImageBoxFlag" value="0">

                    <!-- Right 2 Image Upload Square Boxes: Unit & Box -->
                    <div class="flex items-center gap-3 shrink-0 mx-auto md:mx-0">
                        <!-- Image 1: Unit Image -->
                        <div class="flex flex-col items-center">
                            <label class="block text-[11px] font-bold text-slate-700 mb-1 text-center">
                                <?= current_lang() === 'km' ? 'រូបភាពរាយ' : 'Unit Image' ?>
                            </label>
                            <div class="w-[110px] h-[110px] aspect-square border-2 border-dashed border-indigo-200 hover:border-indigo-400 bg-white rounded-2xl p-1.5 flex flex-col items-center justify-center text-center cursor-pointer transition-all relative overflow-hidden group shadow-sm shrink-0"
                                 onclick="document.getElementById('editStockImageInput').click()" 
                                 title="<?= current_lang() === 'km' ? 'ចុចដើម្បីប្តូររូបភាពរាយ' : 'Click to change unit image' ?>">
                                <img id="editStockImagePreview" src="" alt="" style="display:none;" class="absolute inset-0 w-full h-full object-cover rounded-2xl" onerror="this.style.display='none'; (document.getElementById('editStockImagePlaceholder')||{}).style && (document.getElementById('editStockImagePlaceholder').style.display='flex'); (document.getElementById('editStockImageRemoveBtn')||{}).style && (document.getElementById('editStockImageRemoveBtn').style.display='none');">
                                <button type="button" 
                                        id="editStockImageRemoveBtn" 
                                        style="display:none;" 
                                        onclick="event.stopPropagation(); removeStockImage('editStockImageInput', 'editStockImagePreview', 'editStockImagePlaceholder', 'editStockImageHoverOverlay', 'editStockImageRemoveBtn', 'editRemoveImageFlag');" 
                                        class="absolute top-1 right-1 w-6 h-6 rounded-full bg-rose-500 hover:bg-rose-600 active:scale-90 text-white flex items-center justify-center text-xs shadow-md z-30 transition-all border border-white cursor-pointer"
                                        title="<?= current_lang() === 'km' ? 'លុបរូបភាព' : 'Remove image' ?>">
                                    <i class="fa-solid fa-xmark text-[11px]"></i>
                                </button>
                                <div id="editStockImageHoverOverlay" style="display:none;" class="absolute inset-0 bg-black/40 text-white flex flex-col items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity rounded-2xl">
                                    <i class="fa-solid fa-arrow-up-from-bracket text-sm mb-0.5"></i>
                                    <span class="text-[9px] font-bold"><?= current_lang() === 'km' ? 'ប្តូររូប' : 'Change' ?></span>
                                </div>
                                <div id="editStockImagePlaceholder" class="flex flex-col items-center justify-center pointer-events-none p-1">
                                    <div class="w-7 h-7 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-xs mb-1 group-hover:scale-110 transition-transform">
                                        <i class="fa-regular fa-image"></i>
                                    </div>
                                    <span class="text-[10px] font-bold text-indigo-600 mb-0.5 leading-tight"><?= current_lang() === 'km' ? 'រូបរាយ' : 'Unit' ?></span>
                                    <span class="text-[8.5px] text-slate-400 leading-tight">កំប៉ុង/ដប</span>
                                </div>
                                <input type="file" 
                                       name="image" 
                                       id="editStockImageInput" 
                                       accept="image/*" 
                                       onchange="previewStockImageLight(this, 'editStockImagePreview', 'editStockImagePlaceholder', 'editStockImageHoverOverlay', 'editStockImageRemoveBtn', 'editRemoveImageFlag')" 
                                       style="display:none;">
                            </div>
                        </div>

                        <!-- Image 2: Box Image -->
                        <div class="flex flex-col items-center">
                            <label class="block text-[11px] font-bold text-slate-700 mb-1 text-center">
                                <?= current_lang() === 'km' ? 'រូបភាពកេស' : 'Box Image' ?>
                            </label>
                            <div class="w-[110px] h-[110px] aspect-square border-2 border-dashed border-indigo-200 hover:border-indigo-400 bg-white rounded-2xl p-1.5 flex flex-col items-center justify-center text-center cursor-pointer transition-all relative overflow-hidden group shadow-sm shrink-0"
                                 onclick="document.getElementById('editStockImageBoxInput').click()" 
                                 title="<?= current_lang() === 'km' ? 'ចុចដើម្បីប្តូររូបភាពកេស' : 'Click to change box image' ?>">
                                <img id="editStockImageBoxPreview" src="" alt="" style="display:none;" class="absolute inset-0 w-full h-full object-cover rounded-2xl" onerror="this.style.display='none'; (document.getElementById('editStockImageBoxPlaceholder')||{}).style && (document.getElementById('editStockImageBoxPlaceholder').style.display='flex'); (document.getElementById('editStockImageBoxRemoveBtn')||{}).style && (document.getElementById('editStockImageBoxRemoveBtn').style.display='none');">
                                <button type="button" 
                                        id="editStockImageBoxRemoveBtn" 
                                        style="display:none;" 
                                        onclick="event.stopPropagation(); removeStockImage('editStockImageBoxInput', 'editStockImageBoxPreview', 'editStockImageBoxPlaceholder', 'editStockImageBoxHoverOverlay', 'editStockImageBoxRemoveBtn', 'editRemoveImageBoxFlag');" 
                                        class="absolute top-1 right-1 w-6 h-6 rounded-full bg-rose-500 hover:bg-rose-600 active:scale-90 text-white flex items-center justify-center text-xs shadow-md z-30 transition-all border border-white cursor-pointer"
                                        title="<?= current_lang() === 'km' ? 'លុបរូបភាព' : 'Remove image' ?>">
                                    <i class="fa-solid fa-xmark text-[11px]"></i>
                                </button>
                                <div id="editStockImageBoxHoverOverlay" style="display:none;" class="absolute inset-0 bg-black/40 text-white flex flex-col items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity rounded-2xl">
                                    <i class="fa-solid fa-arrow-up-from-bracket text-sm mb-0.5"></i>
                                    <span class="text-[9px] font-bold"><?= current_lang() === 'km' ? 'ប្តូររូប' : 'Change' ?></span>
                                </div>
                                <div id="editStockImageBoxPlaceholder" class="flex flex-col items-center justify-center pointer-events-none p-1">
                                    <div class="w-7 h-7 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-xs mb-1 group-hover:scale-110 transition-transform">
                                        <i class="fa-solid fa-boxes-stacked"></i>
                                    </div>
                                    <span class="text-[10px] font-bold text-indigo-600 mb-0.5 leading-tight"><?= current_lang() === 'km' ? 'រូបកេស' : 'Box' ?></span>
                                    <span class="text-[8.5px] text-slate-400 leading-tight">កេស/កាតុង</span>
                                </div>
                                <input type="file" 
                                       name="image_box" 
                                       id="editStockImageBoxInput" 
                                       accept="image/*" 
                                       onchange="previewStockImageLight(this, 'editStockImageBoxPreview', 'editStockImageBoxPlaceholder', 'editStockImageBoxHoverOverlay', 'editStockImageBoxRemoveBtn', 'editRemoveImageBoxFlag')" 
                                       style="display:none;">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Middle Section: Unit Packaging & Stock -->
                <div class="border border-slate-200/80 bg-white rounded-2xl p-4 shadow-sm space-y-3">
                    <div class="flex items-center gap-2 text-xs font-bold text-slate-800 border-b border-slate-100 pb-2">
                        <i class="fa-solid fa-shapes text-indigo-500"></i>
                        <span><?= current_lang() === 'km' ? 'ខ្នាត និងការបំប្លែងស្តុក (Unit Packaging & Stock)' : 'Unit Packaging & Stock' ?></span>
                    </div>

                    <!-- 4 Packaging Inputs Grid -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1"><?= current_lang() === 'km' ? 'ខ្នាតរាយ *' : 'Single Unit *' ?></label>
                            <select id="editUnit" name="unit" onchange="updateCardUnitLabels('edit')" class="w-full px-3 py-2 rounded-xl bg-white border border-slate-200 text-xs font-semibold text-slate-800 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                                <option value="can"><?= current_lang() === 'km' ? 'កំប៉ុង (Can)' : 'Can' ?></option>
                                <option value="bottle"><?= current_lang() === 'km' ? 'ដប (Bottle)' : 'Bottle' ?></option>
                                <option value="pack"><?= current_lang() === 'km' ? 'កញ្ចប់ (Pack)' : 'Pack' ?></option>
                                <option value="pcs"><?= current_lang() === 'km' ? 'គ្រាប់ / ដុំ (Pcs)' : 'Pcs (Pieces)' ?></option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1"><?= current_lang() === 'km' ? 'ខ្នាតកេស *' : 'Package Unit *' ?></label>
                            <select id="editPurchaseUnit" name="purchase_unit" onchange="updateCardUnitLabels('edit')" class="w-full px-3 py-2 rounded-xl bg-white border border-slate-200 text-xs font-semibold text-slate-800 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                                <option value="box"><?= current_lang() === 'km' ? 'កេស (Box)' : 'Box' ?></option>
                                <option value="pack"><?= current_lang() === 'km' ? 'យួរ (Pack)' : 'Pack (Sleeve)' ?></option>
                                <option value="package"><?= current_lang() === 'km' ? 'កញ្ចប់ (Package)' : 'Package' ?></option>
                                <option value="carton"><?= current_lang() === 'km' ? 'កាតុង (Carton)' : 'Carton' ?></option>
                                <option value="dozen"><?= current_lang() === 'km' ? 'ឡូ (Dozen)' : 'Dozen (12 pcs)' ?></option>
                                <option value="case"><?= current_lang() === 'km' ? 'កេសធំ (Case)' : 'Case' ?></option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1"><?= current_lang() === 'km' ? 'ចំនួនក្នុង១កេស *' : 'Units per Box *' ?></label>
                            <input type="number" 
                                   step="1" 
                                   min="1" 
                                   id="editConversionRate" 
                                   name="conversion_rate" 
                                   required 
                                   oninput="onEditConversionRateChange(this.value)" 
                                   class="w-full px-3 py-2 rounded-xl bg-white border border-slate-200 text-xs font-bold text-slate-800 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1">
                                <?= current_lang() === 'km' ? 'កម្រិតប្រកាសអាសន្ន *' : 'Alert Level *' ?>
                            </label>
                            <input type="number" 
                                   step="any" 
                                   min="0" 
                                   id="editAlertLevel" 
                                   name="alert_level" 
                                   required 
                                   class="w-full px-3 py-2 rounded-xl bg-white border border-slate-200 text-xs font-semibold text-slate-800 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                        </div>
                    </div>

                    <!-- Full-Width Read-Only Current Stock Status Banner -->
                    <div class="px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200/80 flex items-center justify-between gap-2 shadow-2xs">
                        <div class="flex items-center gap-2.5">
                            <div class="w-7 h-7 rounded-lg bg-indigo-50 border border-indigo-100 flex items-center justify-center text-indigo-600 text-xs shrink-0">
                                <i class="fa-solid fa-lock"></i>
                            </div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-xs font-bold text-slate-600">
                                    <?= current_lang() === 'km' ? 'ស្តុកបច្ចុប្បន្ន:' : 'Stock on Hand:' ?>
                                </span>
                                <span id="editCurrentStockBreakdown" class="text-xs font-black text-slate-900 bg-white px-2.5 py-1 rounded-md border border-slate-200 shadow-2xs">
                                    0 កេស + 0 កំប៉ុង
                                </span>
                                <span id="editCurrentStockTotal" class="text-xs font-black text-indigo-600 bg-indigo-50 px-2.5 py-1 rounded-md border border-indigo-200/70">
                                    = 0 កំប៉ុង
                                </span>
                            </div>
                        </div>
                        <span class="px-2 py-0.5 rounded-full bg-slate-200/70 text-slate-600 text-[10px] font-bold">
                            <?= current_lang() === 'km' ? 'អានប៉ុណ្ណោះ' : 'Read-only' ?>
                        </span>
                        <input type="hidden" id="editQuantity" name="quantity" value="0">
                    </div>
                </div>

                <!-- Bottom Section: Cost & Selling Prices -->
                <div class="border border-slate-200/80 bg-white rounded-2xl p-4 shadow-sm space-y-3">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                        <div class="flex items-center gap-2 text-xs font-bold text-slate-800">
                            <i class="fa-solid fa-circle-dollar-to-slot text-indigo-500"></i>
                            <span><?= current_lang() === 'km' ? 'ការកំណត់តម្លៃ (Cost & Selling Prices)' : 'Cost & Selling Prices' ?></span>
                        </div>
                        <span class="text-[11px] text-slate-400 font-medium">
                            <?= current_lang() === 'km' ? 'គណនាប្រាក់ចំណេញដោយស្វ័យប្រវត្តិ' : 'Auto calculates profit & conversions' ?>
                        </span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3.5">
                        <!-- Sub Card: Unit -->
                        <div class="bg-slate-50/70 border border-slate-200 rounded-xl p-3.5 space-y-2.5">
                            <div class="text-xs font-bold text-slate-800" id="editSubCardUnitTitle">
                                <?= current_lang() === 'km' ? 'ខ្នាតរាយ (Per Unit / Can)' : 'Single Unit (Per Unit)' ?>
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold text-slate-600 mb-1"><?= current_lang() === 'km' ? 'ថ្លៃដើម/ខ្នាត ($)' : 'Cost/Unit ($)' ?></label>
                                <input type="number" 
                                       step="0.0001" 
                                       min="0" 
                                       id="editCostUnit" 
                                       name="cost_per_unit" 
                                       oninput="onEditCostUnitChange(this.value)" 
                                       class="w-full px-3 py-2 rounded-xl bg-white border border-slate-200 text-xs text-slate-700 focus:outline-none focus:border-indigo-500">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-700 mb-1"><?= current_lang() === 'km' ? 'តម្លៃលក់/ខ្នាត ($) *' : 'Sell Price/Unit ($) *' ?></label>
                                <input type="number" 
                                       step="0.01" 
                                       min="0" 
                                       id="editSellPriceUnit" 
                                       name="selling_price_per_unit" 
                                       required 
                                       class="w-full px-3 py-2 rounded-xl bg-emerald-50/30 border border-emerald-400 text-xs font-bold text-emerald-800 focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20">
                            </div>
                        </div>

                        <!-- Sub Card: Box -->
                        <div class="bg-slate-50/70 border border-slate-200 rounded-xl p-3.5 space-y-2.5">
                            <div class="text-xs font-bold text-slate-800" id="editSubCardBoxTitle">
                                <?= current_lang() === 'km' ? 'ខ្នាតកេស (Per Box / Carton)' : 'Package (Per Box)' ?>
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold text-slate-600 mb-1"><?= current_lang() === 'km' ? 'ថ្លៃដើម/កេស ($)' : 'Cost/Box ($)' ?></label>
                                <input type="number" 
                                       step="0.01" 
                                       min="0" 
                                       id="editCostPurchase" 
                                       name="cost_per_purchase_unit" 
                                       oninput="onEditCostBoxChange(this.value)" 
                                       class="w-full px-3 py-2 rounded-xl bg-white border border-slate-200 text-xs text-slate-700 focus:outline-none focus:border-indigo-500">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-indigo-700 mb-1"><?= current_lang() === 'km' ? 'តម្លៃលក់/កេស ($)' : 'Sell Price/Box ($)' ?></label>
                                <input type="number" 
                                       step="0.01" 
                                       min="0" 
                                       id="editSellPriceBox" 
                                       name="selling_price_per_box" 
                                       class="w-full px-3 py-2 rounded-xl bg-indigo-50/30 border border-indigo-300 text-xs font-bold text-indigo-800 focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="modal-footer flex items-center justify-end gap-3 pt-3 border-t border-slate-200">
                    <button type="button" 
                            onclick="closeModal('editStockModal')" 
                            class="px-4 py-2.5 rounded-xl text-xs font-semibold text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition-all cursor-pointer">
                        <?= current_lang() === 'km' ? 'បោះបង់ (Cancel)' : 'Cancel' ?>
                    </button>
                    <button type="submit" 
                            id="editStockSubmitBtn" 
                            class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold transition-all shadow-lg shadow-indigo-600/25 flex items-center gap-1.5 cursor-pointer">
                        <i class="fa-solid fa-check text-xs"></i>
                        <span><?= current_lang() === 'km' ? 'កែប្រែទិន្នន័យ (Save Changes)' : 'Update Drink' ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         MODAL 4: AUDIT LOGS & HISTORY
    ══════════════════════════════════════════════════════════════ -->
    <div id="auditLogsModal" class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-3 md:p-4 bg-black/80 backdrop-blur-sm">
        <div class="modal-content max-w-5xl xl:max-w-6xl w-full bg-white rounded-3xl shadow-2xl overflow-hidden border border-slate-100 relative h-[90vh] max-h-[90vh] flex flex-col">
            <!-- Modal Header -->
            <div class="modal-header flex flex-col md:flex-row md:items-center justify-between gap-3 px-6 py-4 bg-[#121528] text-white shrink-0">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-[#1e2340] border border-emerald-500/30 text-emerald-400 flex items-center justify-center text-base shadow-inner shrink-0">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                    <div>
                        <h3 class="modal-title text-base md:text-lg font-bold text-white leading-tight">
                            <?= __('audit_ledger_title', 'Direct Drinks Audit & History Ledger') ?>
                        </h3>
                        <p class="text-[11px] md:text-xs text-slate-400 mt-0.5 font-normal">
                            <?= __('audit_ledger_sub', 'Recent box restocks, sales deductions, and activity history.') ?>
                        </p>
                    </div>
                </div>

                <!-- History Navigation Tab Buttons -->
                <div class="flex items-center gap-1.5 bg-[#0a0c16] p-1.5 rounded-2xl border border-slate-700/60 shadow-inner shrink-0 self-start md:self-auto">
                    <button type="button" 
                            id="auditTabRestock"
                            onclick="switchAuditTab('restock')" 
                            class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all flex items-center gap-2 cursor-pointer bg-emerald-500/25 text-emerald-300 border border-emerald-500/40 shadow-xs">
                        <i class="fa-solid fa-boxes-stacked text-xs text-emerald-400"></i>
                        <span><?= current_lang() === 'km' ? 'ប្រវត្តិបញ្ចូលស្តុក' : 'Restock History' ?></span>
                        <span id="auditBadgeRestock" class="ml-0.5 px-2 py-0.5 rounded-full bg-emerald-500/30 text-emerald-200 text-[10px] font-black border border-emerald-500/30">0</span>
                    </button>
                    <button type="button" 
                            id="auditTabDeduct"
                            onclick="switchAuditTab('deduct')" 
                            class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all flex items-center gap-2 cursor-pointer text-slate-300 hover:text-white hover:bg-white/[0.06] border border-transparent">
                        <i class="fa-solid fa-cart-arrow-down text-xs text-rose-400"></i>
                        <span><?= current_lang() === 'km' ? 'ប្រវត្តិដកស្តុក' : 'Deduct History' ?></span>
                        <span id="auditBadgeDeduct" class="ml-0.5 px-2 py-0.5 rounded-full bg-rose-500/20 text-rose-300 text-[10px] font-black border border-rose-500/20">0</span>
                    </button>
                </div>

                <button type="button" onclick="closeModal('auditLogsModal')" class="w-8 h-8 rounded-xl bg-white/5 hover:bg-white/15 text-slate-400 hover:text-white flex items-center justify-center transition-all cursor-pointer hidden md:flex">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>

            <!-- Modal Body -->
            <div class="flex-1 overflow-hidden p-4 md:p-6 space-y-3 text-slate-800 bg-[#f8fafc]/70 flex flex-col min-h-0">
                <!-- ── Filter & Search Toolbar (Clean Light Theme) ── -->
                <div class="bg-white p-3 rounded-2xl border border-slate-200/90 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-3 shrink-0">
                    <!-- Date Presets -->
                    <div class="flex items-center gap-1 bg-slate-100 p-1 rounded-xl border border-slate-200 overflow-x-auto text-xs shrink-0">
                        <button type="button" onclick="setAuditDatePreset('all')" id="preset_all" class="px-3 py-1.5 rounded-lg font-bold transition-all bg-indigo-600 text-white shadow-xs cursor-pointer">
                            <?= __('filter_all', 'All') ?>
                        </button>
                        <button type="button" onclick="setAuditDatePreset('today')" id="preset_today" class="px-3 py-1.5 rounded-lg font-semibold text-slate-600 hover:text-slate-900 hover:bg-white/80 border border-transparent cursor-pointer">
                            <?= __('filter_today', 'Today') ?>
                        </button>
                        <button type="button" onclick="setAuditDatePreset('yesterday')" id="preset_yesterday" class="px-3 py-1.5 rounded-lg font-semibold text-slate-600 hover:text-slate-900 hover:bg-white/80 border border-transparent cursor-pointer">
                            <?= __('filter_yesterday', 'Yesterday') ?>
                        </button>
                        <button type="button" onclick="setAuditDatePreset('week')" id="preset_week" class="px-3 py-1.5 rounded-lg font-semibold text-slate-600 hover:text-slate-900 hover:bg-white/80 border border-transparent cursor-pointer">
                            <?= __('filter_this_week', 'This Week') ?>
                        </button>
                        <button type="button" onclick="setAuditDatePreset('month')" id="preset_month" class="px-3 py-1.5 rounded-lg font-semibold text-slate-600 hover:text-slate-900 hover:bg-white/80 border border-transparent cursor-pointer">
                            <?= __('filter_this_month', 'This Month') ?>
                        </button>
                    </div>

                    <!-- Date Pickers, Search & Clear -->
                    <div class="flex items-center gap-2 flex-wrap flex-1 justify-end">
                        <!-- Date & Time Range Inputs -->
                        <div class="flex items-center gap-1.5 bg-slate-50 px-3 py-1.5 rounded-xl border border-slate-200 text-slate-700 shrink-0">
                            <i class="fa-regular fa-calendar-check text-xs text-indigo-500"></i>
                            <input type="datetime-local" 
                                   id="auditFilterDateFrom" 
                                   onchange="onAuditDateInputChange()" 
                                   class="bg-transparent text-xs text-slate-800 font-semibold outline-none border-none cursor-pointer w-36 md:w-40"
                                   title="<?= __('from_date', 'From Date & Time') ?>">
                            <span class="text-xs text-slate-400 font-bold">→</span>
                            <input type="datetime-local" 
                                   id="auditFilterDateTo" 
                                   onchange="onAuditDateInputChange()" 
                                   class="bg-transparent text-xs text-slate-800 font-semibold outline-none border-none cursor-pointer w-36 md:w-40"
                                   title="<?= __('to_date', 'To Date & Time') ?>">
                        </div>

                        <!-- Search Drink / Order -->
                        <div class="relative min-w-[170px] max-w-xs flex-1">
                            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                            <input type="text" 
                                   id="auditFilterKeyword" 
                                   oninput="applyAuditFilters()" 
                                   placeholder="<?= __('search_drink_order', 'Search drink, order #, staff...') ?>" 
                                   class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-8 pr-8 py-1.5 text-xs text-slate-800 placeholder-slate-400 focus:bg-white focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 transition-all font-medium">
                            <button type="button" onclick="clearAuditSearch()" id="auditSearchClearBtn" class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 text-xs cursor-pointer">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>

                        <!-- Reset Filter Button -->
                        <button type="button" 
                                onclick="resetAuditFilters()" 
                                class="py-1.5 px-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-xs font-bold text-slate-700 hover:text-slate-900 border border-slate-200 transition-all cursor-pointer flex items-center gap-1.5 shrink-0"
                                title="<?= __('clear_filter', 'Clear') ?>">
                            <i class="fa-solid fa-rotate-left text-xs text-indigo-500"></i>
                            <span><?= __('clear_filter', 'Clear') ?></span>
                        </button>
                    </div>
                </div>

                <!-- Content Area (Flex-1) -->
                <div id="auditLogsContent" class="flex-1 flex flex-col min-h-0 overflow-hidden space-y-2">
                    <!-- Loaded via AJAX -->
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="modal-footer flex items-center justify-end px-6 py-3.5 bg-slate-50 border-t border-slate-200/80 shrink-0">
                <button type="button" onclick="closeModal('auditLogsModal')" class="px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-xs font-bold text-white transition-all cursor-pointer shadow-sm">
                    <?= __('btn_close', 'Close') ?>
                </button>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         MODAL: DELETE DIRECT DRINK CONFIRMATION
    ══════════════════════════════════════════════════════════════ -->
    <div id="deleteStockModal" class="modal-overlay fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-sm">
        <div class="modal-content glass-card max-w-md w-full p-6 rounded-3xl shadow-2xl relative text-center">
            <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-rose-500/15 border border-rose-500/30 flex items-center justify-center text-rose-400 text-2xl shadow-lg shadow-rose-500/10">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            
            <h3 class="text-lg font-black text-[var(--text-main)] mb-1.5" id="deleteModalTitle">
                <?= current_lang() === 'km' ? 'លុបទំនិញពីស្តុក?' : 'Archive / Delete Direct Drink?' ?>
            </h3>
            
            <p class="text-xs text-[#8e8e9f] mb-6 leading-relaxed">
                <?= current_lang() === 'km' ? 'តើអ្នកពិតជាចង់លុបទំនិញ' : 'Are you sure you want to archive' ?>
                <strong class="text-rose-400 font-bold px-1.5 py-0.5 rounded-md bg-rose-500/10 border border-rose-500/20" id="deleteItemNameDisplay"></strong> 
                <?= current_lang() === 'km' ? 'នេះចេញពីស្តុកទំនិញស្រាប់មែនទេ?' : 'from direct drinks stock?' ?>
            </p>
            
            <input type="hidden" id="deleteItemIdHidden" value="">
            
            <div class="flex items-center justify-center gap-3">
                <button type="button" 
                        onclick="closeModal('deleteStockModal')" 
                        class="flex-1 py-2.5 px-4 rounded-xl bg-[#202026] text-xs font-bold text-[#b4b4c2] hover:text-white hover:bg-[#2a2a34] border border-[#2e2e3e] transition-all cursor-pointer">
                    <?= __('cancel', 'Cancel') ?>
                </button>
                <button type="button" 
                        id="confirmDeleteSubmitBtn"
                        onclick="executeDeleteItem()" 
                        class="flex-1 py-2.5 px-4 rounded-xl bg-gradient-to-r from-rose-600 to-red-600 hover:from-rose-500 hover:to-red-500 text-white font-black text-xs transition-all shadow-lg shadow-rose-600/30 hover:scale-[1.02] active:scale-[0.98] cursor-pointer flex items-center justify-center gap-2">
                    <i class="fa-solid fa-trash-can text-xs"></i>
                    <span><?= current_lang() === 'km' ? 'យល់ព្រមលុប' : 'Yes, Delete' ?></span>
                </button>
            </div>
        </div>
    </div>

    <!-- ── JavaScript Client Engine ── -->
    <script>
        const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        let stockItemsData = <?= json_encode($stockItems ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
        
        const I18N = {
            lang: "<?= current_lang() ?>",
            inStock: "<?= __('status_in_stock', 'In Stock') ?>",
            lowStock: "<?= __('status_low_stock', 'Low Stock') ?>",
            outOfStock: "<?= __('status_out_of_stock', 'Out of Stock') ?>",
            restock: "<?= __('btn_restock', 'Restock') ?>",
            deduct: "<?= current_lang() === 'km' ? 'ដកស្ដុក' : 'Deduct' ?>",
            edit: "<?= __('btn_edit', 'Edit') ?>",
            delete: "<?= __('btn_delete', 'Delete') ?>",
            showingDrinks: "<?= __('showing_drinks_count', 'Showing direct drinks') ?>",
            recentRestocks: "<?= __('recent_restocks', 'Recent Direct Drink Restocks') ?>",
            recentDeductions: "<?= __('recent_deductions', 'Stock Out / Deduction History') ?>",
            recentWaste: "<?= __('recent_waste', 'Recent Wastage / Breakage') ?>",
            noRestocksYet: "<?= __('no_restocks_yet', 'No restock entries recorded yet.') ?>",
            noDeductYet: "<?= __('no_deduct_yet', 'No stock deduction entries recorded yet.') ?>",
            noWasteYet: "<?= __('no_waste_yet', 'No waste logged yet.') ?>",
            date: "<?= __('date', 'Date') ?>",
            drink: "<?= __('col_drink_product', 'Drink') ?>",
            boxesAdded: "<?= __('boxes_added', 'Boxes Added') ?>",
            looseAdded: "<?= __('loose_added', 'Loose Units') ?>",
            totalUnitsAdded: "<?= __('total_units_added', 'Total Units') ?>",
            boxesDeducted: "<?= __('boxes_deducted', 'Boxes Out') ?>",
            looseDeducted: "<?= __('loose_deducted', 'Loose Out') ?>",
            totalDeducted: "<?= __('total_deducted', 'Total Out') ?>",
            unitsAdded: "<?= __('units_added', 'Units Added') ?>",
            wasted: "<?= __('log_waste', 'Wasted') ?>",
            reason: "<?= __('reason', 'Reason') ?>",
            supplierNotes: "<?= __('supplier_notes', 'Supplier / Notes') ?>",
            orderNotes: "<?= __('order_notes', 'Order / Deduction Notes') ?>",
            staff: "<?= __('staff_member', 'Staff') ?>",
            filterAll: "<?= __('filter_all', 'All') ?>",
            filterToday: "<?= __('filter_today', 'Today') ?>",
            filterYesterday: "<?= __('filter_yesterday', 'Yesterday') ?>",
            filterThisWeek: "<?= __('filter_this_week', 'This Week') ?>",
            filterThisMonth: "<?= __('filter_this_month', 'This Month') ?>",
            fromDate: "<?= __('from_date', 'From Date') ?>",
            toDate: "<?= __('to_date', 'To Date') ?>",
            searchDrinkOrder: "<?= __('search_drink_order', 'Search drink, order #, staff...') ?>",
            clearFilter: "<?= __('clear_filter', 'Clear') ?>",
            noMatchFilter: "<?= __('no_match_filter', 'No records match the selected date/filter criteria.') ?>"
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

                stockItemsData = Array.isArray(data.items) ? data.items : [];
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
                    'pack': 'យួរ', 'packs': 'យួរ',
                    'package': 'កញ្ចប់', 'packages': 'កញ្ចប់', 'packet': 'កញ្ចប់', 'packets': 'កញ្ចប់',
                    'carton': 'កាតុង', 'cartons': 'កាតុង',
                    'case': 'កេសធំ', 'cases': 'កេសធំ',
                    'crate': 'ស្នោ', 'crates': 'ស្នោ',
                    'dozen': 'ឡូ', 'dozens': 'ឡូ',
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
                        <td colspan="11" class="py-12 text-center text-[#8e8e9f]">
                            <div class="w-12 h-12 rounded-full bg-[#1e1e24] text-[#10b981] mx-auto flex items-center justify-center text-xl mb-3">
                                <i class="fa-solid fa-wine-bottle"></i>
                            </div>
                            <div class="text-sm font-bold text-white mb-1">${escapeHtml(I18N.noDrinksFound)}</div>
                            <p class="text-xs text-[#7d7d8e] max-w-sm mx-auto mb-4">No direct drinks matched your search. Try resetting filters or adding a new canned/bottled drink.</p>
                            <button type="button" onclick="openAddStockModal()" class="px-4 py-2 rounded-xl bg-gradient-to-r from-[#10b981] to-[#059669] text-white text-xs font-bold hover:brightness-110 cursor-pointer shadow-md shadow-emerald-500/20">
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
                const sellUnit = parseFloat(item.selling_price_per_unit) || 0;
                const sellBox = parseFloat(item.selling_price_per_box) || (sellUnit * rate);
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

                const hasUnit = item.image && item.image.trim() !== '' && !item.image.includes('no-image.png');
                const hasBox  = item.image_box && item.image_box.trim() !== '' && !item.image_box.includes('no-image.png');

                const unitImgHtml = hasUnit
                    ? `<img src="${escapeHtml(item.image)}" alt="${escapeHtml(item.item_name)}" onerror="this.onerror=null; this.parentNode.classList.add('tint-unit'); this.remove();">`
                    : `<svg class="w-4 h-4 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m10 2 2 5"/><path d="M6 7h12l-1.5 13.5a2 2 0 0 1-2 1.5H9.5a2 2 0 0 1-2-1.5L6 7Z"/></svg>`;

                const boxImgHtml = hasBox
                    ? `<img src="${escapeHtml(item.image_box)}" alt="${escapeHtml(item.item_name)} Box" onerror="this.onerror=null; this.parentNode.classList.add('tint-box'); this.remove();">`
                    : `<svg class="w-4 h-4 text-amber-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>`;

                const unitTagText = I18N.lang === 'km' ? 'រាយ' : 'Unit';
                const boxTagText = I18N.lang === 'km' ? 'កេស' : 'Box';

                html += `
                <tr class="row-hover group" data-item-id="${item.item_id}">
                    <td class="py-3 px-4">
                        <div class="flex items-center gap-3">
                            <div class="flex items-center gap-1.5 flex-shrink-0">
                                <div class="item-mini-img ${!hasUnit ? 'tint-unit' : ''}" title="${I18N.lang === 'km' ? 'រូបភាពរាយ (Unit Image)' : 'Unit Image'}">
                                    ${unitImgHtml}
                                    <span class="mini-img-tag unit-tag">${unitTagText}</span>
                                </div>
                                <div class="item-mini-img ${!hasBox ? 'tint-box' : ''}" title="${I18N.lang === 'km' ? 'រូបភាពកេស (Box Image)' : 'Box Image'}">
                                    ${boxImgHtml}
                                    <span class="mini-img-tag box-tag">${boxTagText}</span>
                                </div>
                            </div>
                            <div class="min-w-0">
                                <div class="item-name-text font-bold text-[var(--text-main)] text-sm group-hover:text-[#10b981] transition-colors truncate">
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
                        <span class="threshold-badge px-2.5 py-1 rounded-lg bg-[#101726] border border-emerald-500/25 text-xs font-bold text-[#34d399]">
                            ${escapeHtml(breakdown)}
                        </span>
                    </td>
                    <td class="py-3.5 px-3 text-xs">
                        <div class="font-bold text-[var(--text-main)]">$${costBox.toFixed(2)} / ${escapeHtml(formatUnitLabel(item.purchase_unit, 1))}</div>
                        <div class="text-[11px] text-[#8e8e9f] mt-0.5">$${costUnit.toFixed(4)} / ${escapeHtml(formatUnitLabel(item.unit, 1))}</div>
                    </td>
                    <td class="py-3.5 px-3 text-xs font-semibold">
                        <div class="font-bold text-[var(--text-main)]">$${sellUnit.toFixed(2)}</div>
                        <div class="text-[11px] text-[#8e8e9f] mt-0.5">/ ${escapeHtml(formatUnitLabel(item.unit, 1))}</div>
                    </td>
                    <td class="py-3.5 px-3 text-xs font-semibold">
                        <div class="font-bold text-[var(--text-main)]">$${sellBox.toFixed(2)}</div>
                        <div class="text-[11px] text-[#8e8e9f] mt-0.5">/ ${escapeHtml(formatUnitLabel(item.purchase_unit, 1))}</div>
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
                                    class="px-2.5 py-1.5 rounded-lg bg-emerald-500/15 text-emerald-400 hover:bg-[#10b981] hover:text-white font-bold transition-all cursor-pointer border border-emerald-500/30" 
                                    title="${escapeHtml(I18N.restock)}">
                                <i class="fa-solid fa-boxes-stacked mr-1"></i> ${escapeHtml(I18N.restock)}
                            </button>
                            <button type="button" 
                                    onclick="openDeductStockModal(${item.item_id})" 
                                    class="px-2.5 py-1.5 rounded-lg bg-rose-500/15 text-rose-400 hover:bg-rose-600 hover:text-white font-bold transition-all cursor-pointer border border-rose-500/30" 
                                    title="${I18N.lang === 'km' ? 'ដកស្ដុក (កែតម្រូវទិន្នន័យ)' : 'Deduct / Reduce Stock'}">
                                <i class="fa-solid fa-box-open mr-1"></i> ${escapeHtml(I18N.deduct || 'Deduct')}
                            </button>
                            <button type="button" 
                                    onclick="openEditStockModal(${item.item_id})" 
                                    class="btn-action-neutral btn-act-edit" 
                                    title="${escapeHtml(I18N.edit)}">
                                <i class="fa-solid fa-pen-to-square w-4 text-center"></i>
                            </button>
                            <button type="button" 
                                    onclick="confirmDeleteItem(${item.item_id}, '${escapeHtml(item.item_name).replace(/'/g, "\\'")}')" 
                                    class="btn-action-neutral btn-act-delete" 
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
            const dSelect = document.getElementById('deductItemSelect');
            const currentRVal = rSelect ? rSelect.value : '';
            const currentDVal = dSelect ? dSelect.value : '';

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

            if (rSelect) {
                rSelect.innerHTML = optHtml;
                if (currentRVal) rSelect.value = currentRVal;
            }
            if (dSelect) {
                dSelect.innerHTML = optHtml;
                if (currentDVal) dSelect.value = currentDVal;
            }
        }

        // ── Image Preview Helper for New Light Card Layout ──
        function previewStockImageLight(input, previewId, placeholderId, hoverOverlayId, removeBtnId, removeFlagId) {
            if (!input.files || !input.files[0]) return;
            const file = input.files[0];
            const preview = document.getElementById(previewId);
            const placeholder = placeholderId ? document.getElementById(placeholderId) : null;
            const hoverOverlay = hoverOverlayId ? document.getElementById(hoverOverlayId) : null;
            const removeBtn = removeBtnId ? document.getElementById(removeBtnId) : null;
            const removeFlag = removeFlagId ? document.getElementById(removeFlagId) : null;

            if (removeFlag) removeFlag.value = '0';

            if (typeof openProductCropper === 'function') {
                const reader = new FileReader();
                reader.onload = function(e) {
                    openProductCropper(e.target.result, function(blob, dataUrl, croppedFile) {
                        croppedFile._isCropped = true;
                        try {
                            const dt = new DataTransfer();
                            dt.items.add(croppedFile);
                            input.files = dt.files;
                        } catch(err) { console.warn(err); }

                        if (preview) {
                            preview.src = dataUrl;
                            preview.style.display = 'block';
                            preview.classList.remove('hidden');
                        }
                        if (placeholder) {
                            placeholder.style.display = 'none';
                            placeholder.classList.add('hidden');
                        }
                        if (hoverOverlay) {
                            hoverOverlay.style.display = 'flex';
                        }
                        if (removeBtn) {
                            removeBtn.style.display = 'flex';
                        }
                    }, 1);
                };
                reader.readAsDataURL(file);
            } else {
                const reader = new FileReader();
                reader.onload = function(e) {
                    if (preview) {
                        preview.src = e.target.result;
                        preview.style.display = 'block';
                        preview.classList.remove('hidden');
                    }
                    if (placeholder) {
                        placeholder.style.display = 'none';
                        placeholder.classList.add('hidden');
                    }
                    if (hoverOverlay) {
                        hoverOverlay.style.display = 'flex';
                    }
                    if (removeBtn) {
                        removeBtn.style.display = 'flex';
                    }
                };
                reader.readAsDataURL(file);
            }
        }

        // ── Remove Image Helper (Btn X) ──
        function removeStockImage(inputId, previewId, placeholderId, hoverOverlayId, removeBtnId, removeFlagId) {
            const input = inputId ? document.getElementById(inputId) : null;
            const preview = previewId ? document.getElementById(previewId) : null;
            const placeholder = placeholderId ? document.getElementById(placeholderId) : null;
            const hoverOverlay = hoverOverlayId ? document.getElementById(hoverOverlayId) : null;
            const removeBtn = removeBtnId ? document.getElementById(removeBtnId) : null;
            const removeFlag = removeFlagId ? document.getElementById(removeFlagId) : null;

            if (input) input.value = '';
            if (preview) {
                preview.src = '';
                preview.style.display = 'none';
                preview.classList.add('hidden');
            }
            if (placeholder) {
                placeholder.style.display = 'flex';
                placeholder.classList.remove('hidden');
            }
            if (hoverOverlay) hoverOverlay.style.display = 'none';
            if (removeBtn) removeBtn.style.display = 'none';
            if (removeFlag) removeFlag.value = '1';
        }

        function updateCardUnitLabels(modalPrefix) {
            const form = document.getElementById(`${modalPrefix}StockForm`);
            if (!form) return;
            const uSelect = form.querySelector('select[name="unit"]');
            const pSelect = form.querySelector('select[name="purchase_unit"]');
            
            const uName = uSelect ? (uSelect.options[uSelect.selectedIndex]?.text?.split('(')[0]?.trim() || 'ខ្នាត') : 'ខ្នាត';
            const pName = pSelect ? (pSelect.options[pSelect.selectedIndex]?.text?.split('(')[0]?.trim() || 'កេស') : 'កេស';

            const uTitle = document.getElementById(`${modalPrefix}SubCardUnitTitle`);
            const pTitle = document.getElementById(`${modalPrefix}SubCardBoxTitle`);

            if (uTitle) {
                uTitle.textContent = I18N.lang === 'km' ? `ខ្នាតរាយ (Per Unit / ${uName})` : `Single Unit (Per Unit / ${uName})`;
            }
            if (pTitle) {
                pTitle.textContent = I18N.lang === 'km' ? `ខ្នាតកេស (Per Box / ${pName})` : `Package (Per Box / ${pName})`;
            }

            if (modalPrefix === 'edit') {
                updateEditTotalStockDisplay();
            }
        }

        // ── Cost Calculations (Unit <-> Box) ──
        function onAddCostUnitChange(val) {
            const rateInput = document.getElementById('addConversionRate') || document.querySelector('#addStockForm input[name="conversion_rate"]');
            const rate = parseFloat(rateInput?.value) || 1;
            const unitCost = parseFloat(val);
            const boxInput = document.getElementById('addCostBox');
            if (boxInput && !isNaN(unitCost) && rate > 0) {
                boxInput.value = (unitCost * rate).toFixed(2);
            }
        }

        function onAddCostBoxChange(val) {
            const rateInput = document.getElementById('addConversionRate') || document.querySelector('#addStockForm input[name="conversion_rate"]');
            const rate = parseFloat(rateInput?.value) || 1;
            const boxCost = parseFloat(val);
            const unitInput = document.getElementById('addCostUnit');
            if (unitInput && !isNaN(boxCost) && rate > 0) {
                unitInput.value = (boxCost / rate).toFixed(4);
            }
        }

        function onEditCostUnitChange(val) {
            const rateInput = document.getElementById('editConversionRate');
            const rate = parseFloat(rateInput?.value) || 1;
            const unitCost = parseFloat(val);
            const boxInput = document.getElementById('editCostPurchase');
            if (boxInput && !isNaN(unitCost) && rate > 0) {
                boxInput.value = (unitCost * rate).toFixed(2);
            }
        }

        function onEditCostBoxChange(val) {
            const rateInput = document.getElementById('editConversionRate');
            const rate = parseFloat(rateInput?.value) || 1;
            const boxCost = parseFloat(val);
            const unitInput = document.getElementById('editCostUnit');
            if (unitInput && !isNaN(boxCost) && rate > 0) {
                unitInput.value = (boxCost / rate).toFixed(4);
            }
        }

        // ── Edit Modal Stock Display (Read-Only Status Card) ──
        function onEditConversionRateChange(val) {
            updateEditTotalStockDisplay();
            onEditCostBoxChange(document.getElementById('editCostPurchase')?.value);
        }

        function updateEditTotalStockDisplay() {
            const unit = document.getElementById('editUnit')?.value || 'can';
            const punit = document.getElementById('editPurchaseUnit')?.value || 'box';
            const rate = Math.max(1, parseFloat(document.getElementById('editConversionRate')?.value) || 24);
            const total = parseFloat(document.getElementById('editQuantity')?.value) || 0;
            const boxes = Math.floor(total / rate);
            const loose = total % rate;

            const unitLabel = formatUnitLabel(unit, total || 1);
            const punitLabel = formatUnitLabel(punit, boxes || 1);

            const breakdownEl = document.getElementById('editCurrentStockBreakdown');
            const totalEl = document.getElementById('editCurrentStockTotal');
            
            if (breakdownEl) {
                let parts = [];
                if (boxes > 0) parts.push(`${formatNumber(boxes)} ${punitLabel}`);
                if (loose > 0 || boxes === 0) parts.push(`${formatNumber(loose)} ${unitLabel}`);
                breakdownEl.textContent = parts.join(' + ');
            }
            if (totalEl) {
                totalEl.textContent = `= ${formatNumber(total)} ${unitLabel}`;
            }
        }

        // ── Modal Actions ──
        function openAddStockModal() {
            document.getElementById('addStockForm').reset();
            
            // Reset Unit Image Preview
            const preview = document.getElementById('addStockImagePreview');
            const placeholder = document.getElementById('addStockImagePlaceholder');
            const hoverOverlay = document.getElementById('addStockImageHoverOverlay');
            const removeBtn = document.getElementById('addStockImageRemoveBtn');
            if (preview) {
                preview.src = '';
                preview.style.display = 'none';
                preview.classList.add('hidden');
            }
            if (placeholder) {
                placeholder.style.display = 'flex';
                placeholder.classList.remove('hidden');
            }
            if (hoverOverlay) hoverOverlay.style.display = 'none';
            if (removeBtn) removeBtn.style.display = 'none';
            const fileInput = document.getElementById('addStockImageInput');
            if (fileInput) fileInput.value = '';

            // Reset Box Image Preview
            const previewBox = document.getElementById('addStockImageBoxPreview');
            const placeholderBox = document.getElementById('addStockImageBoxPlaceholder');
            const hoverOverlayBox = document.getElementById('addStockImageBoxHoverOverlay');
            const removeBtnBox = document.getElementById('addStockImageBoxRemoveBtn');
            if (previewBox) {
                previewBox.src = '';
                previewBox.style.display = 'none';
                previewBox.classList.add('hidden');
            }
            if (placeholderBox) {
                placeholderBox.style.display = 'flex';
                placeholderBox.classList.remove('hidden');
            }
            if (hoverOverlayBox) hoverOverlayBox.style.display = 'none';
            if (removeBtnBox) removeBtnBox.style.display = 'none';
            const fileInputBox = document.getElementById('addStockImageBoxInput');
            if (fileInputBox) fileInputBox.value = '';

            const alertBox = document.getElementById('addStockDupAlert');
            if (alertBox) alertBox.classList.add('hidden');
            const nameInput = document.getElementById('addStockItemName');
            if (nameInput) nameInput.classList.remove('border-rose-500');
            const submitBtn = document.getElementById('addStockSubmitBtn');
            if (submitBtn) submitBtn.disabled = false;

            const addCatSelect = document.getElementById('addStockCategoryId');
            if (addCatSelect) {
                const drinksOpt = Array.from(addCatSelect.options).find(opt => opt.text.toLowerCase().includes('drink') || opt.text.toLowerCase().includes('ភេសជ្ជៈ'));
                if (drinksOpt) {
                    addCatSelect.value = drinksOpt.value;
                } else if (addCatSelect.options.length > 1) {
                    addCatSelect.selectedIndex = 1;
                }
            }

            updateCardUnitLabels('add');
            openModal('addStockModal');
        }

        function checkAddStockDuplicate() {
            const input = document.getElementById('addStockItemName');
            if (!input) return false;
            const val = input.value.trim().toLowerCase();
            const alertBox = document.getElementById('addStockDupAlert');
            const submitBtn = document.getElementById('addStockSubmitBtn');
            if (!val) {
                if (alertBox) alertBox.classList.add('hidden');
                input.classList.remove('border-rose-500');
                if (submitBtn) submitBtn.disabled = false;
                return false;
            }
            const items = (typeof stockItemsData !== 'undefined' && Array.isArray(stockItemsData)) ? stockItemsData : [];
            const match = items.find(item => item && item.item_name && item.item_name.trim().toLowerCase() === val && (item.is_active == 1 || item.is_active === undefined));
            if (match) {
                if (alertBox) {
                    alertBox.querySelector('span').textContent = `⚠️ A stock drink named "${match.item_name}" already exists (${match.quantity} ${match.unit} in stock).`;
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

        function onAddCostUnitChange(val) {
            const rateInput = document.querySelector('#addStockModal input[name="conversion_rate"]');
            const rate = parseFloat(rateInput ? rateInput.value : 24) || 24;
            const cUnit = parseFloat(val);
            const boxInput = document.getElementById('addCostBox');
            if (boxInput && !isNaN(cUnit)) {
                boxInput.value = (cUnit * rate).toFixed(2);
            }
        }

        function onAddCostBoxChange(val) {
            const rateInput = document.querySelector('#addStockModal input[name="conversion_rate"]');
            const rate = parseFloat(rateInput ? rateInput.value : 24) || 24;
            const cBox = parseFloat(val);
            const unitInput = document.getElementById('addCostUnit');
            if (unitInput && !isNaN(cBox) && rate > 0) {
                unitInput.value = (cBox / rate).toFixed(4);
            }
        }

        function onAddSellPriceUnitChange(val) {
            const rateInput = document.querySelector('#addStockModal input[name="conversion_rate"]');
            const rate = parseFloat(rateInput ? rateInput.value : 24) || 24;
            const uPrice = parseFloat(val);
            const boxInput = document.getElementById('addSellPriceBox');
            if (boxInput && !isNaN(uPrice)) {
                boxInput.value = (uPrice * rate).toFixed(2);
            }
        }

        function onAddSellPriceBoxChange(val) {
            const rateInput = document.querySelector('#addStockModal input[name="conversion_rate"]');
            const rate = parseFloat(rateInput ? rateInput.value : 24) || 24;
            const sBox = parseFloat(val);
            const unitInput = document.getElementById('addSellPriceUnit');
            if (unitInput && !isNaN(sBox) && rate > 0) {
                unitInput.value = (sBox / rate).toFixed(2);
            }
        }

        function onEditCostUnitChange(val) {
            const rate = parseFloat(document.getElementById('editConversionRate')?.value || 24) || 24;
            const cUnit = parseFloat(val);
            const boxInput = document.getElementById('editCostPurchase');
            if (boxInput && !isNaN(cUnit)) {
                boxInput.value = (cUnit * rate).toFixed(2);
            }
        }

        function onEditCostBoxChange(val) {
            const rate = parseFloat(document.getElementById('editConversionRate')?.value || 24) || 24;
            const cBox = parseFloat(val);
            const unitInput = document.getElementById('editCostUnit');
            if (unitInput && !isNaN(cBox) && rate > 0) {
                unitInput.value = (cBox / rate).toFixed(4);
            }
        }

        function onEditSellPriceUnitChange(val) {
            const rate = parseFloat(document.getElementById('editConversionRate')?.value || 24) || 24;
            const uPrice = parseFloat(val);
            const boxInput = document.getElementById('editSellPriceBox');
            if (boxInput && !isNaN(uPrice)) {
                boxInput.value = (uPrice * rate).toFixed(2);
            }
        }

        function onEditSellPriceBoxChange(val) {
            const rate = parseFloat(document.getElementById('editConversionRate')?.value || 24) || 24;
            const sBox = parseFloat(val);
            const unitInput = document.getElementById('editSellPriceUnit');
            if (unitInput && !isNaN(sBox) && rate > 0) {
                unitInput.value = (sBox / rate).toFixed(2);
            }
        }

        async function handleAddStock(e) {
            e.preventDefault();
            if (checkAddStockDuplicate()) {
                showToast('A stock drink with this name already exists. Cannot add duplicate.', 'error');
                const nameInput = document.getElementById('addStockItemName');
                if (nameInput) {
                    nameInput.focus();
                }
                return;
            }

            const form = e.target;
            const btn = document.getElementById('addStockSubmitBtn');
            const origContent = btn ? btn.innerHTML : 'Save Direct Drink';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Saving...';
            }

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
                    if (result.message && result.message.toLowerCase().includes('already exists')) {
                        const alertBox = document.getElementById('addStockDupAlert');
                        if (alertBox) {
                            alertBox.querySelector('span').textContent = '⚠️ ' + result.message;
                            alertBox.classList.remove('hidden');
                        }
                        const nameInput = document.getElementById('addStockItemName');
                        if (nameInput) nameInput.classList.add('border-rose-500');
                    }
                }
            } catch (err) {
                console.error(err);
                showToast('Server connection error.', 'error');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = origContent;
                }
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
            const boxUnitName = document.getElementById('restockBoxUnitName');
            const looseUnitName = document.getElementById('restockLooseUnitName');

            if (!selectedOpt || !selectedOpt.value) {
                if (boxUnitName) boxUnitName.textContent = 'កេស';
                if (looseUnitName) looseUnitName.textContent = 'កំប៉ុង';
                const bPreview = document.getElementById('restockBoxToLoosePreview');
                if (bPreview) bPreview.textContent = '0';
                document.getElementById('restockBadgeUnits').textContent = I18N.lang === 'km' ? '+0 ឯកតា' : '+0 units';
                document.getElementById('restockFormula').textContent = I18N.lang === 'km' ? 'ជ្រើសរើសភេសជ្ជៈ និងបញ្ចូលចំនួនដើម្បីគណនា' : 'Select a drink and enter quantity to see calculation.';
                document.getElementById('restockCurrentStock').textContent = `${I18N.lang === 'km' ? 'ស្តុកបច្ចុប្បន្ន:' : 'Current:'} --`;
                document.getElementById('restockProjectedStock').textContent = `${I18N.lang === 'km' ? 'ស្តុកសរុបថ្មី:' : 'New Total:'} --`;
                return;
            }

            const punit = selectedOpt.getAttribute('data-punit') || 'box';
            const unit = selectedOpt.getAttribute('data-unit') || 'can';
            const rate = parseFloat(selectedOpt.getAttribute('data-rate')) || 24;
            const currentQty = parseFloat(selectedOpt.getAttribute('data-qty')) || 0;
            const boxCost = parseFloat(selectedOpt.getAttribute('data-boxcost')) || 0;

            const unitLabel = formatUnitLabel(unit, 1);
            const punitLabel = formatUnitLabel(punit, 1);

            if (boxUnitName) boxUnitName.textContent = punitLabel;
            if (looseUnitName) looseUnitName.textContent = unitLabel;

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

            const boxesToAdd = parseFloat(document.getElementById('restockQtyInput')?.value) || 0;
            const looseToAdd = parseFloat(document.getElementById('restockLooseQtyInput')?.value) || 0;

            const boxAddedUnits = boxesToAdd * rate;
            const totalAddedUnits = boxAddedUnits + looseToAdd;
            const newTotalUnits = currentQty + totalAddedUnits;

            const unitLabel = formatUnitLabel(unit, totalAddedUnits || 1);
            const punitLabel = formatUnitLabel(punit, boxesToAdd || 1);

            const boxToLoosePreview = document.getElementById('restockBoxToLoosePreview');
            if (boxToLoosePreview) {
                boxToLoosePreview.textContent = formatNumber(boxAddedUnits);
            }

            document.getElementById('restockBadgeUnits').textContent = `+${formatNumber(totalAddedUnits)} ${unitLabel}`;
            
            let formulaText = `(${formatNumber(boxesToAdd)} ${punitLabel} × ${rate})`;
            if (looseToAdd > 0) {
                formulaText += ` + ${formatNumber(looseToAdd)} ${I18N.lang === 'km' ? 'រាយ' : 'loose'}`;
            }
            formulaText += ` = +${formatNumber(totalAddedUnits)} ${unitLabel}`;
            document.getElementById('restockFormula').textContent = formulaText;

            document.getElementById('restockCurrentStock').textContent = `${I18N.lang === 'km' ? 'ស្តុកបច្ចុប្បន្ន:' : 'Current:'} ${formatNumber(currentQty)} ${unitLabel}`;
            document.getElementById('restockProjectedStock').textContent = `${I18N.lang === 'km' ? 'ស្តុកសរុបថ្មី:' : 'New Total:'} ${formatNumber(newTotalUnits)} ${unitLabel}`;
        }

        async function handleQuickRestock(e) {
            e.preventDefault();
            const form = e.target;
            const btn = document.getElementById('restockSubmitBtn');
            const origContent = btn ? btn.innerHTML : 'Complete Restock';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Saving...';
            }

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
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = origContent;
                }
            }
        }

        // ── Deduct Stock / Stock Correction Actions ──
        function openDeductStockModal(itemId = null) {
            document.getElementById('deductStockForm').reset();
            const select = document.getElementById('deductItemSelect');
            if (itemId && select) {
                select.value = itemId;
            }
            updateDeductModalPreview();
            openModal('deductStockModal');
        }

        function updateDeductModalPreview() {
            const select = document.getElementById('deductItemSelect');
            const selectedOpt = select ? select.options[select.selectedIndex] : null;
            const boxUnitName = document.getElementById('deductBoxUnitName');
            const looseUnitName = document.getElementById('deductLooseUnitName');
            const warnBox = document.getElementById('deductExcessWarning');
            const submitBtn = document.getElementById('deductSubmitBtn');

            if (!selectedOpt || !selectedOpt.value) {
                if (boxUnitName) boxUnitName.textContent = 'កេស';
                if (looseUnitName) looseUnitName.textContent = 'កំប៉ុង';
                const bPreview = document.getElementById('deductBoxToLoosePreview');
                if (bPreview) bPreview.textContent = '0';
                document.getElementById('deductBadgeUnits').textContent = I18N.lang === 'km' ? '-0 ឯកតា' : '-0 units';
                document.getElementById('deductFormula').textContent = I18N.lang === 'km' ? 'ជ្រើសរើសភេសជ្ជៈ និងបញ្ចូលចំនួនដើម្បីគណនា' : 'Select a drink and enter quantity to see calculation.';
                document.getElementById('deductCurrentStock').textContent = `${I18N.lang === 'km' ? 'ស្តុកបច្ចុប្បន្ន:' : 'Current:'} --`;
                document.getElementById('deductProjectedStock').textContent = `${I18N.lang === 'km' ? 'ស្តុកសល់ថ្មី:' : 'New Remaining:'} --`;
                if (warnBox) warnBox.classList.add('hidden');
                if (submitBtn) submitBtn.disabled = false;
                return;
            }

            const punit = selectedOpt.getAttribute('data-punit') || 'box';
            const unit = selectedOpt.getAttribute('data-unit') || 'can';
            const unitLabel = formatUnitLabel(unit, 1);
            const punitLabel = formatUnitLabel(punit, 1);

            if (boxUnitName) boxUnitName.textContent = punitLabel;
            if (looseUnitName) looseUnitName.textContent = unitLabel;

            calculateDeductTotal();
        }

        function calculateDeductTotal() {
            const select = document.getElementById('deductItemSelect');
            const selectedOpt = select ? select.options[select.selectedIndex] : null;
            if (!selectedOpt || !selectedOpt.value) return;

            const punit = selectedOpt.getAttribute('data-punit') || 'box';
            const unit = selectedOpt.getAttribute('data-unit') || 'can';
            const rate = parseFloat(selectedOpt.getAttribute('data-rate')) || 24;
            const currentQty = parseFloat(selectedOpt.getAttribute('data-qty')) || 0;

            const boxesToDeduct = parseFloat(document.getElementById('deductQtyInput')?.value) || 0;
            const looseToDeduct = parseFloat(document.getElementById('deductLooseQtyInput')?.value) || 0;

            const boxDeductedUnits = boxesToDeduct * rate;
            const totalDeductedUnits = boxDeductedUnits + looseToDeduct;
            const newRemainingUnits = currentQty - totalDeductedUnits;

            const unitLabel = formatUnitLabel(unit, totalDeductedUnits || 1);
            const punitLabel = formatUnitLabel(punit, boxesToDeduct || 1);

            const boxToLoosePreview = document.getElementById('deductBoxToLoosePreview');
            if (boxToLoosePreview) {
                boxToLoosePreview.textContent = formatNumber(boxDeductedUnits);
            }

            document.getElementById('deductBadgeUnits').textContent = `-${formatNumber(totalDeductedUnits)} ${unitLabel}`;
            
            let formulaText = `(${formatNumber(boxesToDeduct)} ${punitLabel} × ${rate})`;
            if (looseToDeduct > 0) {
                formulaText += ` + ${formatNumber(looseToDeduct)} ${I18N.lang === 'km' ? 'រាយ' : 'loose'}`;
            }
            formulaText += ` = -${formatNumber(totalDeductedUnits)} ${unitLabel}`;
            document.getElementById('deductFormula').textContent = formulaText;

            document.getElementById('deductCurrentStock').textContent = `${I18N.lang === 'km' ? 'ស្តុកបច្ចុប្បន្ន:' : 'Current:'} ${formatNumber(currentQty)} ${unitLabel}`;
            
            const projEl = document.getElementById('deductProjectedStock');
            const warnBox = document.getElementById('deductExcessWarning');
            const submitBtn = document.getElementById('deductSubmitBtn');

            if (projEl) {
                projEl.textContent = `${I18N.lang === 'km' ? 'ស្តុកសល់ថ្មី:' : 'New Remaining:'} ${formatNumber(Math.max(0, newRemainingUnits))} ${unitLabel}`;
                if (newRemainingUnits < 0) {
                    projEl.classList.add('text-rose-500');
                    projEl.classList.remove('text-rose-700');
                } else {
                    projEl.classList.remove('text-rose-500');
                    projEl.classList.add('text-rose-700');
                }
            }

            if (totalDeductedUnits > currentQty) {
                if (warnBox) warnBox.classList.remove('hidden');
                if (submitBtn) submitBtn.disabled = true;
            } else {
                if (warnBox) warnBox.classList.add('hidden');
                if (submitBtn) submitBtn.disabled = false;
            }
        }

        async function handleDeductStock(e) {
            e.preventDefault();
            const form = e.target;
            const btn = document.getElementById('deductSubmitBtn');
            const origContent = btn ? btn.innerHTML : 'Confirm Deduction';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Processing...';
            }

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
                    closeModal('deductStockModal');
                    loadStockTable();
                } else {
                    showToast(result.message || 'Deduction failed.', 'error');
                }
            } catch (err) {
                console.error(err);
                showToast('Server connection error.', 'error');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = origContent;
                }
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

                const cRate = Math.max(1, parseFloat(it.conversion_rate || 24));
                const cBox = parseFloat(it.cost_per_purchase_unit || 0);
                const cUnit = parseFloat(it.cost_per_unit || (cBox > 0 ? cBox / cRate : 0));
                document.getElementById('editCostPurchase').value = cBox.toFixed(2);
                document.getElementById('editCostUnit').value = cUnit.toFixed(4);
                const sUnit = parseFloat(it.selling_price_per_unit || 0);
                const sBox = parseFloat(it.selling_price_per_box || (sUnit * cRate));
                document.getElementById('editSellPriceUnit').value = sUnit.toFixed(2);
                document.getElementById('editSellPriceBox').value = sBox.toFixed(2);
                document.getElementById('editNotes').value = it.notes || '';

                const editCatSelect = document.getElementById('editStockCategoryId');
                if (editCatSelect) {
                    editCatSelect.value = it.category_id || '';
                }

                updateCardUnitLabels('edit');
                updateEditTotalStockDisplay();

                // Reset remove flags
                const remFlag = document.getElementById('editRemoveImageFlag');
                if (remFlag) remFlag.value = '0';
                const remBoxFlag = document.getElementById('editRemoveImageBoxFlag');
                if (remBoxFlag) remBoxFlag.value = '0';

                // Preview Unit Image
                const editPreview = document.getElementById('editStockImagePreview');
                const editPlaceholder = document.getElementById('editStockImagePlaceholder');
                const editHoverOverlay = document.getElementById('editStockImageHoverOverlay');
                const editRemoveBtn = document.getElementById('editStockImageRemoveBtn');
                if (editPreview) {
                    if (it.image && it.image.trim() && !it.image.includes('no-image.png')) {
                        editPreview.src = it.image;
                        editPreview.style.display = 'block';
                        editPreview.classList.remove('hidden');
                        if (editPlaceholder) {
                            editPlaceholder.style.display = 'none';
                            editPlaceholder.classList.add('hidden');
                        }
                        if (editHoverOverlay) editHoverOverlay.style.display = 'flex';
                        if (editRemoveBtn) editRemoveBtn.style.display = 'flex';
                    } else {
                        editPreview.src = '';
                        editPreview.style.display = 'none';
                        editPreview.classList.add('hidden');
                        if (editPlaceholder) {
                            editPlaceholder.style.display = 'flex';
                            editPlaceholder.classList.remove('hidden');
                        }
                        if (editHoverOverlay) editHoverOverlay.style.display = 'none';
                        if (editRemoveBtn) editRemoveBtn.style.display = 'none';
                    }
                }
                const editFileInput = document.getElementById('editStockImageInput');
                if (editFileInput) editFileInput.value = '';

                // Preview Box Image
                const editPreviewBox = document.getElementById('editStockImageBoxPreview');
                const editPlaceholderBox = document.getElementById('editStockImageBoxPlaceholder');
                const editHoverOverlayBox = document.getElementById('editStockImageBoxHoverOverlay');
                const editRemoveBtnBox = document.getElementById('editStockImageBoxRemoveBtn');
                if (editPreviewBox) {
                    if (it.image_box && it.image_box.trim() && !it.image_box.includes('no-image.png')) {
                        editPreviewBox.src = it.image_box;
                        editPreviewBox.style.display = 'block';
                        editPreviewBox.classList.remove('hidden');
                        if (editPlaceholderBox) {
                            editPlaceholderBox.style.display = 'none';
                            editPlaceholderBox.classList.add('hidden');
                        }
                        if (editHoverOverlayBox) editHoverOverlayBox.style.display = 'flex';
                        if (editRemoveBtnBox) editRemoveBtnBox.style.display = 'flex';
                    } else {
                        editPreviewBox.src = '';
                        editPreviewBox.style.display = 'none';
                        editPreviewBox.classList.add('hidden');
                        if (editPlaceholderBox) {
                            editPlaceholderBox.style.display = 'flex';
                            editPlaceholderBox.classList.remove('hidden');
                        }
                        if (editHoverOverlayBox) editHoverOverlayBox.style.display = 'none';
                        if (editRemoveBtnBox) editRemoveBtnBox.style.display = 'none';
                    }
                }
                const editFileInputBox = document.getElementById('editStockImageBoxInput');
                if (editFileInputBox) editFileInputBox.value = '';

                updateCardUnitLabels('edit');
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
            const origContent = btn ? btn.innerHTML : 'Update';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Saving...';
            }

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
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = origContent;
                }
            }
        }

        let pendingDeleteItemId = null;

        function confirmDeleteItem(itemId, itemName) {
            pendingDeleteItemId = itemId;
            const hiddenInp = document.getElementById('deleteItemIdHidden');
            const nameDisp = document.getElementById('deleteItemNameDisplay');
            if (hiddenInp) hiddenInp.value = itemId;
            if (nameDisp) nameDisp.textContent = itemName;
            openModal('deleteStockModal');
        }

        async function executeDeleteItem() {
            const itemId = document.getElementById('deleteItemIdHidden')?.value || pendingDeleteItemId;
            if (!itemId) return;

            const btn = document.getElementById('confirmDeleteSubmitBtn');
            const origHtml = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin text-xs"></i> <span>${I18N.lang === 'km' ? 'កំពុងលុប...' : 'Deleting...'}</span>`;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'delete_item');
                formData.append('item_id', itemId);
                formData.append('csrf_token', CSRF_TOKEN);

                const r = await fetch('stock.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const res = await r.json();

                if (res.success) {
                    closeModal('deleteStockModal');
                    showToast(res.message || (I18N.lang === 'km' ? 'បានលុបទំនិញដោយជោគជ័យ' : 'Item archived successfully.'), 'success');
                    loadStockTable();
                } else {
                    showToast(res.message || (I18N.lang === 'km' ? 'មិនអាចលុបបានទេ' : 'Archive failed.'), 'error');
                }
            } catch (err) {
                console.error(err);
                showToast(I18N.lang === 'km' ? 'កំហុសបណ្តាញ' : 'Network error.', 'error');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                }
            }
        }

        let currentAuditLogsData = { restocks: [], deductions: [], waste: [] };
        let activeAuditTab = 'restock';
        let activeAuditDatePreset = 'all';

        function switchAuditTab(tab) {
            activeAuditTab = tab;
            updateAuditTabButtonStyles();
            renderAuditContent();
        }

        function updateAuditTabButtonStyles() {
            const btnRestock = document.getElementById('auditTabRestock');
            const btnDeduct = document.getElementById('auditTabDeduct');

            const baseInactive = "px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all flex items-center gap-2 cursor-pointer text-slate-300 hover:text-white hover:bg-white/[0.06] border border-transparent";
            const activeRestock = "px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all flex items-center gap-2 cursor-pointer bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 shadow-xs shadow-emerald-500/10";
            const activeDeduct = "px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all flex items-center gap-2 cursor-pointer bg-rose-500/20 text-rose-300 border border-rose-500/40 shadow-xs shadow-rose-500/10";

            if (btnRestock) btnRestock.className = (activeAuditTab === 'restock') ? activeRestock : baseInactive;
            if (btnDeduct) btnDeduct.className = (activeAuditTab === 'deduct') ? activeDeduct : baseInactive;
        }

        function getLocalDateTimeISO(d, endOfDay = false) {
            const year = d.getFullYear();
            const month = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            const time = endOfDay ? '23:59' : '00:00';
            return `${year}-${month}-${day}T${time}`;
        }

        function setAuditDatePreset(preset) {
            activeAuditDatePreset = preset;
            const fromInput = document.getElementById('auditFilterDateFrom');
            const toInput = document.getElementById('auditFilterDateTo');
            const now = new Date();

            const presets = ['all', 'today', 'yesterday', 'week', 'month'];
            presets.forEach(p => {
                const el = document.getElementById('preset_' + p);
                if (el) {
                    if (p === preset) {
                        el.className = "px-3 py-1.5 rounded-lg font-bold transition-all bg-indigo-600 text-white shadow-xs cursor-pointer";
                    } else {
                        el.className = "px-3 py-1.5 rounded-lg font-semibold text-slate-600 hover:text-slate-900 hover:bg-white/80 border border-transparent cursor-pointer";
                    }
                }
            });

            if (preset === 'all') {
                if (fromInput) fromInput.value = '';
                if (toInput) toInput.value = '';
            } else if (preset === 'today') {
                if (fromInput) fromInput.value = getLocalDateTimeISO(now, false);
                if (toInput) toInput.value = getLocalDateTimeISO(now, true);
            } else if (preset === 'yesterday') {
                const yest = new Date();
                yest.setDate(yest.getDate() - 1);
                if (fromInput) fromInput.value = getLocalDateTimeISO(yest, false);
                if (toInput) toInput.value = getLocalDateTimeISO(yest, true);
            } else if (preset === 'week') {
                const weekStart = new Date();
                weekStart.setDate(weekStart.getDate() - 6);
                if (fromInput) fromInput.value = getLocalDateTimeISO(weekStart, false);
                if (toInput) toInput.value = getLocalDateTimeISO(now, true);
            } else if (preset === 'month') {
                const monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
                if (fromInput) fromInput.value = getLocalDateTimeISO(monthStart, false);
                if (toInput) toInput.value = getLocalDateTimeISO(now, true);
            }

            applyAuditFilters();
        }

        function onAuditDateInputChange() {
            activeAuditDatePreset = 'custom';
            const presets = ['all', 'today', 'yesterday', 'week', 'month'];
            presets.forEach(p => {
                const el = document.getElementById('preset_' + p);
                if (el) {
                    el.className = "px-3 py-1.5 rounded-lg font-semibold text-slate-600 hover:text-slate-900 hover:bg-white/80 border border-transparent cursor-pointer";
                }
            });
            applyAuditFilters();
        }

        function clearAuditSearch() {
            const kw = document.getElementById('auditFilterKeyword');
            if (kw) {
                kw.value = '';
                applyAuditFilters();
            }
        }

        function resetAuditFilters() {
            const kw = document.getElementById('auditFilterKeyword');
            if (kw) kw.value = '';
            setAuditDatePreset('all');
        }

        function filterAuditList(list) {
            if (!Array.isArray(list)) return [];

            const fromVal = (document.getElementById('auditFilterDateFrom')?.value || '').trim();
            const toVal = (document.getElementById('auditFilterDateTo')?.value || '').trim();
            const kwVal = (document.getElementById('auditFilterKeyword')?.value || '').trim().toLowerCase();

            const clearBtn = document.getElementById('auditSearchClearBtn');
            if (clearBtn) {
                clearBtn.classList.toggle('hidden', kwVal.length === 0);
            }

            // Normalization for date & time comparison
            const normToVal = toVal ? (toVal.length === 16 ? toVal + ':59' : toVal) : '';

            return list.filter(item => {
                // 1. Date & Time Filter
                if (item.created_at) {
                    const itemDT = item.created_at.replace(' ', 'T');
                    if (fromVal && itemDT < fromVal) return false;
                    if (normToVal && itemDT > normToVal) return false;
                }

                // 2. Keyword Search
                if (kwVal) {
                    const str = [
                        item.item_name || '',
                        item.supplier || '',
                        item.notes || '',
                        item.reason || '',
                        item.recorded_by || '',
                        item.created_by || '',
                        item.order_id ? 'order #' + item.order_id : '',
                        item.change_type || ''
                    ].join(' ').toLowerCase();

                    if (!str.includes(kwVal)) return false;
                }

                return true;
            });
        }

        function renderAuditLoadingState() {
            const container = document.getElementById('auditLogsContent');
            if (!container) return;

            let titleHtml = '';
            let theadHtml = '';

            if (activeAuditTab === 'restock') {
                titleHtml = `<h4 class="text-xs font-bold uppercase tracking-wider text-emerald-600 flex items-center gap-1.5"><i class="fa-solid fa-boxes-stacked"></i> ${escapeHtml(I18N.recentRestocks)}</h4><span class="text-[11px] text-slate-400 font-mono bg-white px-2.5 py-0.5 rounded-full border border-slate-200 shadow-2xs font-semibold">...</span>`;
                theadHtml = `<tr><th class="py-3 px-3.5 whitespace-nowrap bg-slate-50/95">${escapeHtml(I18N.date)}</th><th class="py-3 px-3.5 whitespace-nowrap bg-slate-50/95 text-slate-900">${escapeHtml(I18N.drink)}</th><th class="py-3 px-3.5 whitespace-nowrap text-center text-amber-700 bg-slate-50/95 font-extrabold">${escapeHtml(I18N.boxesAdded)}</th><th class="py-3 px-3.5 whitespace-nowrap text-center text-sky-700 bg-slate-50/95 font-extrabold">${escapeHtml(I18N.looseAdded)}</th><th class="py-3 px-3.5 whitespace-nowrap text-center text-emerald-700 bg-slate-50/95 font-black">${escapeHtml(I18N.totalUnitsAdded)}</th><th class="py-3 px-3.5 bg-slate-50/95">${escapeHtml(I18N.supplierNotes)}</th><th class="py-3 px-3.5 whitespace-nowrap bg-slate-50/95">${escapeHtml(I18N.staff)}</th></tr>`;
            } else {
                titleHtml = `<h4 class="text-xs font-bold uppercase tracking-wider text-rose-600 flex items-center gap-1.5"><i class="fa-solid fa-cart-arrow-down"></i> ${escapeHtml(I18N.recentDeductions)}</h4><span class="text-[11px] text-slate-400 font-mono bg-white px-2.5 py-0.5 rounded-full border border-slate-200 shadow-2xs font-semibold">...</span>`;
                theadHtml = `<tr><th class="py-3 px-3.5 whitespace-nowrap bg-slate-50/95">${escapeHtml(I18N.date)}</th><th class="py-3 px-3.5 whitespace-nowrap bg-slate-50/95 text-slate-900">${escapeHtml(I18N.drink)}</th><th class="py-3 px-3.5 whitespace-nowrap text-center text-amber-700 bg-slate-50/95 font-extrabold">${escapeHtml(I18N.boxesDeducted)}</th><th class="py-3 px-3.5 whitespace-nowrap text-center text-sky-700 bg-slate-50/95 font-extrabold">${escapeHtml(I18N.looseDeducted)}</th><th class="py-3 px-3.5 whitespace-nowrap text-center text-rose-700 bg-slate-50/95 font-black">${escapeHtml(I18N.totalDeducted)}</th><th class="py-3 px-3.5 bg-slate-50/95">${escapeHtml(I18N.orderNotes)}</th><th class="py-3 px-3.5 whitespace-nowrap bg-slate-50/95">${escapeHtml(I18N.staff)}</th></tr>`;
            }

            container.innerHTML = `<div class="flex-1 flex flex-col min-h-0 space-y-2">
                <div class="flex items-center justify-between pb-0.5">${titleHtml}</div>
                <div class="flex-1 overflow-x-auto overflow-y-auto rounded-2xl border border-slate-200/90 shadow-sm bg-white min-h-[380px]">
                    <table class="w-full text-xs text-left whitespace-nowrap">
                        <thead class="sticky top-0 bg-slate-50/95 backdrop-blur-md z-10 text-slate-600 font-bold uppercase text-[11px] border-b border-slate-200 shadow-2xs">
                            ${theadHtml}
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr>
                                <td colspan="7" class="py-24 text-center text-slate-400 bg-white">
                                    <div class="flex flex-col items-center justify-center gap-2.5">
                                        <i class="fa-solid fa-circle-notch fa-spin text-2xl text-indigo-500"></i>
                                        <div class="text-xs font-semibold text-slate-600">${escapeHtml(I18N.lang === 'km' ? 'កំពុងទាញយកទិន្នន័យ...' : 'Loading records...')}</div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>`;
        }

        function renderAuditContent() {
            const container = document.getElementById('auditLogsContent');
            if (!container) return;

            const filteredRestocks = filterAuditList(currentAuditLogsData.restocks);
            const filteredDeductions = filterAuditList(currentAuditLogsData.deductions);

            // Update tab badge numbers
            const bRestock = document.getElementById('auditBadgeRestock');
            const bDeduct = document.getElementById('auditBadgeDeduct');
            if (bRestock) bRestock.textContent = filteredRestocks.length;
            if (bDeduct) bDeduct.textContent = filteredDeductions.length;

            let html = '';

            if (activeAuditTab === 'restock') {
                html += `<div class="flex-1 flex flex-col min-h-0 space-y-2">`;
                html += `<div class="flex items-center justify-between pb-0.5"><h4 class="text-xs font-bold uppercase tracking-wider text-emerald-600 flex items-center gap-1.5"><i class="fa-solid fa-boxes-stacked"></i> ${escapeHtml(I18N.recentRestocks)}</h4><span class="text-[11px] text-slate-500 font-mono bg-white px-2.5 py-0.5 rounded-full border border-slate-200 shadow-2xs font-semibold">${filteredRestocks.length} records</span></div>`;
                
                html += `<div class="flex-1 overflow-x-auto overflow-y-auto rounded-2xl border border-slate-200/90 shadow-sm bg-white min-h-[380px]"><table class="w-full text-xs text-left whitespace-nowrap"><thead class="sticky top-0 bg-slate-50/95 backdrop-blur-md z-10 text-slate-600 font-bold uppercase text-[11px] border-b border-slate-200 shadow-2xs"><tr><th class="py-3 px-3.5 whitespace-nowrap bg-slate-50/95">${escapeHtml(I18N.date)}</th><th class="py-3 px-3.5 whitespace-nowrap bg-slate-50/95 text-slate-900">${escapeHtml(I18N.drink)}</th><th class="py-3 px-3.5 whitespace-nowrap text-center text-amber-700 bg-slate-50/95 font-extrabold">${escapeHtml(I18N.boxesAdded)}</th><th class="py-3 px-3.5 whitespace-nowrap text-center text-sky-700 bg-slate-50/95 font-extrabold">${escapeHtml(I18N.looseAdded)}</th><th class="py-3 px-3.5 whitespace-nowrap text-center text-emerald-700 bg-slate-50/95 font-black">${escapeHtml(I18N.totalUnitsAdded)}</th><th class="py-3 px-3.5 bg-slate-50/95">${escapeHtml(I18N.supplierNotes)}</th><th class="py-3 px-3.5 whitespace-nowrap bg-slate-50/95">${escapeHtml(I18N.staff)}</th></tr></thead><tbody class="divide-y divide-slate-100 bg-white">`;
                
                if (filteredRestocks.length === 0) {
                    html += `<tr><td colspan="7" class="py-24 text-center text-slate-400 bg-white"><div class="flex flex-col items-center justify-center gap-2.5"><div class="w-12 h-12 rounded-2xl bg-slate-100 border border-slate-200/80 flex items-center justify-center text-slate-400 text-xl shadow-2xs"><i class="fa-solid fa-folder-open"></i></div><div class="text-xs font-bold text-slate-700">${escapeHtml(I18N.noMatchFilter || I18N.noRestocksYet)}</div><div class="text-[11px] text-slate-400 font-normal">${escapeHtml(I18N.lang === 'km' ? 'មិនមានទិន្នន័យបញ្ចូលស្តុកត្រូវបង្ហាញទេ' : 'No restock records found')}</div></div></td></tr>`;
                } else {
                    filteredRestocks.forEach(r => {
                        const boxesAdded = parseFloat(r.boxes_added) || 0;
                        const looseAdded = parseFloat(r.loose_added) || 0;
                        const unitsAdded = parseFloat(r.quantity_added) || 0;
                        const unitName = formatUnitLabel(r.unit || 'can', unitsAdded);
                        const looseUnitName = formatUnitLabel(r.unit || 'can', looseAdded || 1);
                        const punitName = formatUnitLabel(r.purchase_unit || 'box', boxesAdded || 1);

                        const boxDisplay = boxesAdded > 0 
                            ? `<span class="font-extrabold text-amber-700 whitespace-nowrap bg-amber-50 px-2.5 py-0.5 rounded-lg border border-amber-200/80">+${formatNumber(boxesAdded)} ${escapeHtml(punitName)}</span>`
                            : `<span class="text-slate-400 font-medium whitespace-nowrap">0 ${escapeHtml(punitName)}</span>`;

                        const looseDisplay = looseAdded > 0 
                            ? `<span class="font-bold text-sky-700 whitespace-nowrap bg-sky-50 px-2.5 py-0.5 rounded-lg border border-sky-200/80">+${formatNumber(looseAdded)} ${escapeHtml(looseUnitName)}</span>`
                            : `<span class="text-slate-400 font-medium whitespace-nowrap">0 ${escapeHtml(looseUnitName)}</span>`;

                        const totalDisplay = `<span class="font-black text-emerald-700 whitespace-nowrap bg-emerald-50 px-3 py-0.5 rounded-lg border border-emerald-300/80">+${formatNumber(unitsAdded)} ${escapeHtml(unitName)}</span>`;

                        html += `<tr class="hover:bg-slate-50/80 transition-colors">
                            <td class="py-3 px-3.5 text-slate-500 whitespace-nowrap font-mono text-[11px]">${escapeHtml(r.created_at)}</td>
                            <td class="py-3 px-3.5 font-bold text-slate-900 whitespace-nowrap text-xs">${escapeHtml(r.item_name)}</td>
                            <td class="py-3 px-3.5 text-center whitespace-nowrap">${boxDisplay}</td>
                            <td class="py-3 px-3.5 text-center whitespace-nowrap">${looseDisplay}</td>
                            <td class="py-3 px-3.5 text-center whitespace-nowrap font-extrabold">${totalDisplay}</td>
                            <td class="py-3 px-3.5 text-slate-600 whitespace-nowrap">${escapeHtml(r.supplier || r.notes || '--')}</td>
                            <td class="py-3 px-3.5 text-slate-500 whitespace-nowrap text-xs">${escapeHtml(r.recorded_by || 'Staff')}</td>
                        </tr>`;
                    });
                }
                html += `</tbody></table></div></div>`;
            } else if (activeAuditTab === 'deduct') {
                html += `<div class="flex-1 flex flex-col min-h-0 space-y-2">`;
                html += `<div class="flex items-center justify-between pb-0.5"><h4 class="text-xs font-bold uppercase tracking-wider text-rose-600 flex items-center gap-1.5"><i class="fa-solid fa-cart-arrow-down"></i> ${escapeHtml(I18N.recentDeductions)}</h4><span class="text-[11px] text-slate-500 font-mono bg-white px-2.5 py-0.5 rounded-full border border-slate-200 shadow-2xs font-semibold">${filteredDeductions.length} records</span></div>`;
                
                html += `<div class="flex-1 overflow-x-auto overflow-y-auto rounded-2xl border border-slate-200/90 shadow-sm bg-white min-h-[380px]"><table class="w-full text-xs text-left whitespace-nowrap"><thead class="sticky top-0 bg-slate-50/95 backdrop-blur-md z-10 text-slate-600 font-bold uppercase text-[11px] border-b border-slate-200 shadow-2xs"><tr><th class="py-3 px-3.5 whitespace-nowrap bg-slate-50/95">${escapeHtml(I18N.date)}</th><th class="py-3 px-3.5 whitespace-nowrap bg-slate-50/95 text-slate-900">${escapeHtml(I18N.drink)}</th><th class="py-3 px-3.5 whitespace-nowrap text-center text-amber-700 bg-slate-50/95 font-extrabold">${escapeHtml(I18N.boxesDeducted)}</th><th class="py-3 px-3.5 whitespace-nowrap text-center text-sky-700 bg-slate-50/95 font-extrabold">${escapeHtml(I18N.looseDeducted)}</th><th class="py-3 px-3.5 whitespace-nowrap text-center text-rose-700 bg-slate-50/95 font-black">${escapeHtml(I18N.totalDeducted)}</th><th class="py-3 px-3.5 bg-slate-50/95">${escapeHtml(I18N.orderNotes)}</th><th class="py-3 px-3.5 whitespace-nowrap bg-slate-50/95">${escapeHtml(I18N.staff)}</th></tr></thead><tbody class="divide-y divide-slate-100 bg-white">`;
                
                if (filteredDeductions.length === 0) {
                    html += `<tr><td colspan="7" class="py-24 text-center text-slate-400 bg-white"><div class="flex flex-col items-center justify-center gap-2.5"><div class="w-12 h-12 rounded-2xl bg-slate-100 border border-slate-200/80 flex items-center justify-center text-slate-400 text-xl shadow-2xs"><i class="fa-solid fa-folder-open"></i></div><div class="text-xs font-bold text-slate-700">${escapeHtml(I18N.noMatchFilter || I18N.noDeductYet)}</div><div class="text-[11px] text-slate-400 font-normal">${escapeHtml(I18N.lang === 'km' ? 'មិនមានទិន្នន័យដកស្តុកត្រូវបង្ហាញទេ' : 'No deduction records found')}</div></div></td></tr>`;
                } else {
                    filteredDeductions.forEach(d => {
                        const boxesDeducted = parseFloat(d.boxes_deducted) || 0;
                        const looseDeducted = parseFloat(d.loose_deducted) || 0;
                        const totalDeducted = parseFloat(d.total_deducted) || 0;
                        const unitName = formatUnitLabel(d.unit || 'can', totalDeducted);
                        const looseUnitName = formatUnitLabel(d.unit || 'can', looseDeducted || 1);
                        const punitName = formatUnitLabel(d.purchase_unit || 'box', boxesDeducted || 1);

                        const boxDisplay = boxesDeducted > 0 
                            ? `<span class="font-extrabold text-amber-700 whitespace-nowrap bg-amber-50 px-2.5 py-0.5 rounded-lg border border-amber-200/80">-${formatNumber(boxesDeducted)} ${escapeHtml(punitName)}</span>`
                            : `<span class="text-slate-400 font-medium whitespace-nowrap">0 ${escapeHtml(punitName)}</span>`;

                        const looseDisplay = looseDeducted > 0 
                            ? `<span class="font-bold text-sky-700 whitespace-nowrap bg-sky-50 px-2.5 py-0.5 rounded-lg border border-sky-200/80">-${formatNumber(looseDeducted)} ${escapeHtml(looseUnitName)}</span>`
                            : `<span class="text-slate-400 font-medium whitespace-nowrap">0 ${escapeHtml(looseUnitName)}</span>`;

                        const totalDisplay = `<span class="font-black text-rose-700 whitespace-nowrap bg-rose-50 px-3 py-0.5 rounded-lg border border-rose-300/80">-${formatNumber(totalDeducted)} ${escapeHtml(unitName)}</span>`;

                        let typeBadge = '';
                        if (d.change_type === 'sale_deduct' || (d.notes && d.notes.toLowerCase().includes('order #'))) {
                            typeBadge = `<span class="px-2 py-0.5 rounded-md bg-indigo-50 text-indigo-700 text-[10px] font-bold border border-indigo-200 mr-1.5 inline-flex items-center gap-1"><i class="fa-solid fa-receipt text-[9px]"></i>POS Sale</span>`;
                        } else {
                            typeBadge = `<span class="px-2 py-0.5 rounded-md bg-rose-50 text-rose-700 text-[10px] font-bold border border-rose-200 mr-1.5 inline-flex items-center gap-1"><i class="fa-solid fa-minus text-[9px]"></i>Deduct</span>`;
                        }

                        html += `<tr class="hover:bg-slate-50/80 transition-colors">
                            <td class="py-3 px-3.5 text-slate-500 whitespace-nowrap font-mono text-[11px]">${escapeHtml(d.created_at)}</td>
                            <td class="py-3 px-3.5 font-bold text-slate-900 whitespace-nowrap text-xs">${escapeHtml(d.item_name)}</td>
                            <td class="py-3 px-3.5 text-center whitespace-nowrap">${boxDisplay}</td>
                            <td class="py-3 px-3.5 text-center whitespace-nowrap">${looseDisplay}</td>
                            <td class="py-3 px-3.5 text-center whitespace-nowrap font-extrabold">${totalDisplay}</td>
                            <td class="py-3 px-3.5 text-slate-700 whitespace-nowrap">${typeBadge} <span class="text-slate-800 font-medium">${escapeHtml(d.notes || ('Order #' + (d.order_id || '--')))}</span></td>
                            <td class="py-3 px-3.5 text-slate-500 whitespace-nowrap text-xs">${escapeHtml(d.created_by || 'Staff')}</td>
                        </tr>`;
                    });
                }
                html += `</tbody></table></div></div>`;
            }

            container.innerHTML = html;
        }

        function applyAuditFilters() {
            renderAuditContent();
        }

        async function openAuditLogsModal(defaultTab = 'restock') {
            activeAuditTab = defaultTab;
            openModal('auditLogsModal');
            updateAuditTabButtonStyles();
            
            // Set preset 'all'
            setAuditDatePreset('all');

            // Render table layout with loading state inside tbody
            renderAuditLoadingState();

            try {
                const res = await fetch('stock.php?action=get_audit_logs');
                const data = await res.json();

                if (!data.success) {
                    const container = document.getElementById('auditLogsContent');
                    if (container) {
                        container.innerHTML = `<div class="p-8 text-center text-rose-500 font-semibold bg-white rounded-2xl border border-slate-200">${escapeHtml(data.message || 'Failed to load audit logs.')}</div>`;
                    }
                    return;
                }

                currentAuditLogsData = {
                    restocks: data.restocks || [],
                    deductions: data.deductions || [],
                    waste: data.waste || []
                };

                renderAuditContent();
            } catch (err) {
                console.error(err);
                const container = document.getElementById('auditLogsContent');
                if (container) {
                    container.innerHTML = `<div class="p-8 text-center text-rose-500 font-semibold bg-white rounded-2xl border border-slate-200">Failed to load audit logs.</div>`;
                }
            }
        }
    </script>
</body>
</html>
