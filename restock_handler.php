<?php
/**
 * Bird's Nest Coffee POS - Bulk Restock & Unit Conversion Handler
 * Full-stack PHP + PDO + MySQL
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=UTF-8');

// 1. Authentication & Role Check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.']);
    exit;
}

$_user_role = $_SESSION['role'] ?? 'staff';
if (!in_array($_user_role, ['admin', 'manager', 'staff'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden: Insufficient privileges.']);
    exit;
}

// 2. CSRF Token Validation
$csrf_token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired security token.']);
    exit;
}

// 3. Parse & Validate Input Parameters
$item_id      = (int)($_POST['item_id'] ?? 0);
$purchase_qty = (float)($_POST['purchase_qty'] ?? 0.0); // e.g. 3 boxes
$cost_per_box = isset($_POST['cost_per_box']) && $_POST['cost_per_box'] !== '' ? (float)$_POST['cost_per_box'] : null;
$supplier     = trim((string)($_POST['supplier'] ?? ''));
$invoice_no   = trim((string)($_POST['invoice_no'] ?? ''));
$notes        = trim((string)($_POST['notes'] ?? ''));
$is_loose     = !empty($_POST['is_loose']) && $_POST['is_loose'] == '1'; // true if adding loose units directly

if ($item_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please select a valid inventory item.']);
    exit;
}

if ($purchase_qty <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Purchase quantity must be greater than zero.']);
    exit;
}

try {
    $pdo_dsn = "mysql:host={$servername};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($pdo_dsn, $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $pdo->beginTransaction();

    // 4. Fetch Item with Row Lock (SELECT ... FOR UPDATE)
    $stmt = $pdo->prepare("
        SELECT item_id, item_name, category, base_unit, quantity_on_hand, 
               purchase_unit, conversion_rate, cost_per_purchase_unit, cost_per_base_unit, min_alert_level
        FROM inventory_items
        WHERE item_id = ? AND is_active = 1
        FOR UPDATE
    ");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch();

    if (!$item) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Inventory item not found or inactive.']);
        exit;
    }

    $conversion_rate = max(1.0, (float)$item['conversion_rate']);
    $base_unit       = $item['base_unit'];
    $purchase_unit   = $item['purchase_unit'];
    $stock_before    = (float)$item['quantity_on_hand'];

    // 5. Calculate Total Base Units to Add
    if ($is_loose) {
        $total_base_units = $purchase_qty; // directly loose units
        $boxes_added      = round($total_base_units / $conversion_rate, 2);
        $calc_note        = "Added {$purchase_qty} loose {$base_unit}s";
    } else {
        $total_base_units = $purchase_qty * $conversion_rate; // e.g. 3 boxes * 24 cans = 72 cans
        $boxes_added      = $purchase_qty;
        $calc_note        = "Added {$purchase_qty} {$purchase_unit}(s) × {$conversion_rate} {$base_unit}s/{$purchase_unit} = {$total_base_units} {$base_unit}s";
    }

    $stock_after = $stock_before + $total_base_units;

    // 6. Cost Calculation
    $unit_cost = (float)$item['cost_per_base_unit'];
    if ($cost_per_box !== null && $cost_per_box >= 0) {
        $new_purchase_cost = $cost_per_box;
        $new_base_cost     = $is_loose ? ($cost_per_box) : ($cost_per_box / $conversion_rate);
        $unit_cost         = $new_base_cost;

        // Update item costs
        $costUpdate = $pdo->prepare("
            UPDATE inventory_items 
            SET cost_per_purchase_unit = ?, cost_per_base_unit = ? 
            WHERE item_id = ?
        ");
        $costUpdate->execute([$new_purchase_cost, $new_base_cost, $item_id]);
    }

    // 7. Update Inventory Item Stock
    $updateStmt = $pdo->prepare("
        UPDATE inventory_items 
        SET quantity_on_hand = quantity_on_hand + ?, 
            supplier = COALESCE(NULLIF(?, ''), supplier),
            updated_at = NOW()
        WHERE item_id = ?
    ");
    $updateStmt->execute([$total_base_units, $supplier, $item_id]);

    // Also sync with stock_items table if it exists for backwards compatibility
    try {
        $pdo->prepare("UPDATE stock_items SET quantity = quantity + ? WHERE item_name = ?")
            ->execute([$total_base_units, $item['item_name']]);
    } catch (Exception $ignored) {}

    // 8. Insert Audit Ledger Log into stock_logs
    $log_notes = $calc_note;
    if ($notes !== '') $log_notes .= " | " . $notes;

    $logStmt = $pdo->prepare("
        INSERT INTO inventory_stock_logs 
        (item_id, change_type, purchase_qty, purchase_unit, conversion_rate, quantity_changed, 
         stock_before, stock_after, cost_per_purchase_unit, cost_at_time, supplier, invoice_no, notes, created_by, created_at)
        VALUES (?, 'purchase_restock', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $logStmt->execute([
        $item_id,
        $boxes_added,
        $is_loose ? $base_unit : $purchase_unit,
        $conversion_rate,
        $total_base_units,
        $stock_before,
        $stock_after,
        $cost_per_box,
        $unit_cost,
        $supplier ?: ($item['supplier'] ?? null),
        $invoice_no ?: null,
        $log_notes,
        $_SESSION['username'] ?? 'Staff'
    ]);

    $pdo->commit();

    // 9. Format Helper Response for UI
    function formatStockDisplay(float $qty, string $base, string $pkg, float $rate): string {
        $qtyStr = floor($qty) == $qty ? number_format($qty, 0) : number_format($qty, 2);
        if ($rate <= 1.0) return "{$qtyStr} {$base}s";
        $boxes = floor($qty / $rate);
        $loose = fmod($qty, $rate);
        $boxLabel = ($boxes == 1 ? $pkg : "{$pkg}es");
        if (strtolower($pkg) === 'box') $boxLabel = ($boxes == 1 ? 'box' : 'boxes');
        $baseLabel = ($loose == 1 ? $base : "{$base}s");

        if ($boxes > 0 && $loose > 0) {
            $looseStr = floor($loose) == $loose ? number_format($loose, 0) : number_format($loose, 2);
            return "{$qtyStr} {$base}s ({$boxes} {$boxLabel}, {$looseStr} loose {$baseLabel})";
        } elseif ($boxes > 0 && $loose == 0.0) {
            return "{$qtyStr} {$base}s ({$boxes} full {$boxLabel})";
        } else {
            return "{$qtyStr} {$base}s (0 full {$boxLabel}, {$qtyStr} loose {$baseLabel})";
        }
    }

    $formatted_new_stock = formatStockDisplay($stock_after, $base_unit, $purchase_unit, $conversion_rate);

    echo json_encode([
        'success'   => true,
        'message'   => "Successfully restocked {$item['item_name']}! Added {$total_base_units} {$base_unit}s ({$boxes_added} {$purchase_unit}).",
        'data'      => [
            'item_id'           => $item_id,
            'item_name'         => $item['item_name'],
            'added_boxes'       => $boxes_added,
            'added_base_units'  => $total_base_units,
            'base_unit'         => $base_unit,
            'purchase_unit'     => $purchase_unit,
            'stock_before'      => $stock_before,
            'stock_after'       => $stock_after,
            'formatted_display' => $formatted_new_stock,
            'total_cost'        => $cost_per_box !== null ? round($cost_per_box * $boxes_added, 2) : null
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error during restock: ' . $e->getMessage()
    ]);
}
