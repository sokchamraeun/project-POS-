<?php
/**
 * Bird's Nest Coffee POS - Recipe & Inventory Deduction Engine
 * Full-stack PHP + PDO + MySQL
 */

/**
 * Initialize database schema for recipes and stock audit logs
 */
function initRecipeAndStockLogSchema(PDO $pdo): void {
    try {
        // 1. product_recipes (Bill of Materials)
        $pdo->exec("CREATE TABLE IF NOT EXISTS `product_recipes` (
            `recipe_id` INT AUTO_INCREMENT PRIMARY KEY,
            `product_id` INT NOT NULL,
            `item_id` INT NOT NULL,
            `quantity_required` DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
            `unit` VARCHAR(20) NOT NULL DEFAULT 'g',
            `notes` VARCHAR(255) DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_prod_item` (`product_id`, `item_id`),
            INDEX (`product_id`),
            INDEX (`item_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 2. stock_logs (Audit Ledger for Sales Deductions, Restocks, Waste, Reversals)
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // Auto-seed starter recipes for products if table is empty
        $recipeCount = $pdo->query("SELECT COUNT(*) FROM `product_recipes`")->fetchColumn();
        if ($recipeCount == 0) {
            // Find existing products and stock items to create realistic BOM links
            $products = $pdo->query("SELECT product_id, name FROM products")->fetchAll();
            $stockItems = $pdo->query("SELECT item_id, item_name, unit, cost_per_unit FROM stock_items WHERE is_active = 1")->fetchAll();
            
            $stockByName = [];
            foreach ($stockItems as $si) {
                $stockByName[strtolower(trim($si['item_name']))] = $si;
            }

            // Helper to lookup item by keyword
            $findItem = function($keyword) use ($stockItems) {
                foreach ($stockItems as $si) {
                    if (stripos($si['item_name'], $keyword) !== false) {
                        return $si;
                    }
                }
                return null;
            };

            $milkItem   = $findItem('Whole Milk') ?? $findItem('Milk');
            $oatMilk    = $findItem('Oat Milk');
            $arabica    = $findItem('Arabica') ?? $findItem('Beans');
            $robusta    = $findItem('Robusta');
            $matcha     = $findItem('Matcha');
            $cocoa      = $findItem('Cocoa');
            $vanilla    = $findItem('Vanilla');
            $caramel    = $findItem('Caramel');
            $coldCup    = $findItem('16oz') ?? $findItem('Cup');
            $straw      = $findItem('Straw');

            $insStmt = $pdo->prepare("INSERT IGNORE INTO `product_recipes` 
                (`product_id`, `item_id`, `quantity_required`, `unit`, `notes`) 
                VALUES (?, ?, ?, ?, ?)");

            foreach ($products as $p) {
                $pId = (int)$p['product_id'];
                $pName = strtolower($p['name']);

                // Matcha drinks
                if (stripos($pName, 'matcha') !== false) {
                    if ($matcha)  $insStmt->execute([$pId, $matcha['item_id'], 15.0, $matcha['unit'], 'Ceremonial Matcha Powder']);
                    if ($milkItem) $insStmt->execute([$pId, $milkItem['item_id'], 220.0, $milkItem['unit'], 'Fresh Whole Milk base']);
                    if ($coldCup)  $insStmt->execute([$pId, $coldCup['item_id'], 1.0, $coldCup['unit'], '16oz Cold Cup & Lid']);
                    if ($straw)    $insStmt->execute([$pId, $straw['item_id'], 1.0, $straw['unit'], 'Paper Straw']);
                }
                // Latte / Cappuccino / Coffee drinks
                elseif (stripos($pName, 'latte') !== false || stripos($pName, 'កាហ្វេ') !== false || stripos($pName, 'coffee') !== false || stripos($pName, 'cappuccino') !== false) {
                    if ($arabica)  $insStmt->execute([$pId, $arabica['item_id'], 18.0, $arabica['unit'], 'Double shot espresso grind (18g)']);
                    if ($milkItem) $insStmt->execute([$pId, $milkItem['item_id'], 200.0, $milkItem['unit'], 'Steamed / Fresh milk']);
                    if ($coldCup)  $insStmt->execute([$pId, $coldCup['item_id'], 1.0, $coldCup['unit'], 'Serving Cup']);
                    if ($straw)    $insStmt->execute([$pId, $straw['item_id'], 1.0, $straw['unit'], 'Paper Straw']);
                }
                // Mocha / Chocolate drinks
                elseif (stripos($pName, 'mocha') !== false || stripos($pName, 'chocolate') !== false || stripos($pName, 'cocoa') !== false) {
                    if ($arabica)  $insStmt->execute([$pId, $arabica['item_id'], 18.0, $arabica['unit'], 'Espresso shot']);
                    if ($cocoa)    $insStmt->execute([$pId, $cocoa['item_id'], 25.0, $cocoa['unit'], 'Dark Cocoa Powder']);
                    if ($milkItem) $insStmt->execute([$pId, $milkItem['item_id'], 180.0, $milkItem['unit'], 'Fresh Milk']);
                    if ($coldCup)  $insStmt->execute([$pId, $coldCup['item_id'], 1.0, $coldCup['unit'], 'Serving Cup']);
                    if ($straw)    $insStmt->execute([$pId, $straw['item_id'], 1.0, $straw['unit'], 'Paper Straw']);
                }
                // Direct canned drinks (Coke, Red Bull, etc.)
                elseif (stripos($pName, 'coke') !== false || stripos($pName, 'coka') !== false || stripos($pName, 'cola') !== false || stripos($pName, 'red bull') !== false) {
                    $canned = $findItem('Red Bull') ?? $findItem('Drink');
                    if ($canned)  $insStmt->execute([$pId, $canned['item_id'], 1.0, $canned['unit'], 'Direct canned beverage']);
                    if ($straw)   $insStmt->execute([$pId, $straw['item_id'], 1.0, $straw['unit'], 'Paper Straw']);
                }
                // Default generic fallback recipe (Beans + Milk + Cup + Straw)
                else {
                    if ($arabica)  $insStmt->execute([$pId, $arabica['item_id'], 16.0, $arabica['unit'], 'Espresso shot']);
                    if ($coldCup)  $insStmt->execute([$pId, $coldCup['item_id'], 1.0, $coldCup['unit'], 'Serving Cup']);
                    if ($straw)    $insStmt->execute([$pId, $straw['item_id'], 1.0, $straw['unit'], 'Paper Straw']);
                }
            }
        }
    } catch (Exception $e) {
        error_log("initRecipeAndStockLogSchema Error: " . $e->getMessage());
    }
}

/**
 * Deduct inventory stock for an order based on Bill of Materials (BOM) recipes.
 * 
 * @param int    $order_id      The order ID to process
 * @param PDO    $pdo           PDO database instance
 * @param bool   $strict_mode   If true, fails transaction if any item is out of stock. If false, deducts to 0 and records warning.
 * @param string $performed_by  Username or role performing the action
 * @return array Result array ['success' => bool, 'message' => string, 'deducted' => array, 'warnings' => array, 'errors' => array]
 */
function deductStockForOrder(int $order_id, PDO $pdo, bool $strict_mode = false, string $performed_by = 'POS Checkout'): array {
    if ($order_id <= 0) {
        return ['success' => false, 'message' => 'Invalid order ID.', 'deducted' => [], 'warnings' => [], 'errors' => ['Invalid order ID']];
    }

    // Ensure database tables exist
    initRecipeAndStockLogSchema($pdo);

    // 1. Fetch all items in the order
    $orderStmt = $pdo->prepare("SELECT item_id, product_id, product_name, quantity, sweetness, ice, milk, size_code 
                                FROM order_items 
                                WHERE order_id = ?");
    $orderStmt->execute([$order_id]);
    $orderItems = $orderStmt->fetchAll();

    if (empty($orderItems)) {
        return ['success' => true, 'message' => 'No order items found to deduct.', 'deducted' => [], 'warnings' => [], 'errors' => []];
    }

    // 2. Aggregate all ingredients needed across the whole order
    // Key: item_id => [item_id, item_name, unit, total_qty_needed, cost_per_unit, product_ids => [...]]
    $aggregatedIngredients = [];
    $recipeStmt = $pdo->prepare("SELECT r.item_id, r.quantity_required, r.unit, s.item_name, s.category, s.quantity AS current_stock, s.cost_per_unit, s.alert_level 
                                 FROM product_recipes r 
                                 JOIN stock_items s ON r.item_id = s.item_id 
                                 WHERE r.product_id = ? AND s.is_active = 1");

    foreach ($orderItems as $oi) {
        $productId = (int)$oi['product_id'];
        $orderedQty = max(1, (int)$oi['quantity']);
        $milkChoice = strtolower(trim((string)($oi['milk'] ?? '')));
        $sweet = (string)($oi['sweetness'] ?? '');
        $ice = (string)($oi['ice'] ?? '');

        if ($productId <= 0) continue;

        // Sweetness Factor
        $sweetnessFactor = 1.0;
        $swNorm = str_replace(' ', '', strtolower(trim($sweet)));
        if ($swNorm === '0%' || $swNorm === '0' || $swNorm === 'nosugar' || $swNorm === 'គ្មានស្ករ') {
            $sweetnessFactor = 0.0;
        } elseif ($swNorm === '25%' || $swNorm === '25') {
            $sweetnessFactor = 0.25;
        } elseif ($swNorm === '50%' || $swNorm === '50') {
            $sweetnessFactor = 0.50;
        } elseif ($swNorm === '75%' || $swNorm === '75') {
            $sweetnessFactor = 0.75;
        } elseif ($swNorm === '100%' || $swNorm === '100') {
            $sweetnessFactor = 1.0;
        }

        // Ice Factor
        $iceFactor = 1.0;
        $iceNorm = strtolower(trim($ice));
        if (str_contains($iceNorm, 'no ice') || str_contains($iceNorm, 'គ្មានទឹកកក') || $iceNorm === 'no') {
            $iceFactor = 0.0;
        } elseif (str_contains($iceNorm, 'less ice') || str_contains($iceNorm, 'ទឹកកកតិច')) {
            $iceFactor = 0.5;
        } elseif (str_contains($iceNorm, 'more ice') || str_contains($iceNorm, 'extra ice') || str_contains($iceNorm, 'ទឹកកកច្រើន')) {
            $iceFactor = 1.3;
        } elseif (str_contains($iceNorm, 'normal') || str_contains($iceNorm, 'ធម្មតា')) {
            $iceFactor = 1.0;
        }

        $recipeStmt->execute([$productId]);
        $recipes = $recipeStmt->fetchAll();

        foreach ($recipes as $r) {
            $rName = strtolower($r['item_name'] ?? '');
            $rCat = $r['category'] ?? '';

            // Skip Auto Packaging Sets from physical stock deduction
            if (str_contains($rName, 'packaging set') || str_contains($r['item_name'], 'ឈុត')) {
                continue;
            }

            $itemId = (int)$r['item_id'];
            $reqPerDrink = (float)$r['quantity_required'];
            $itemName = $r['item_name'];
            $costPerUnit = (float)$r['cost_per_unit'];
            $alertLevel = (float)$r['alert_level'];
            $currentStock = (float)$r['current_stock'];
            $unit = $r['unit'];

            // Handle customized milk substitution (e.g. Oat Milk substitution if specified)
            if (!empty($milkChoice) && (str_contains($rName, 'milk') || str_contains($rName, 'ទឹកដោះគោ')) && !str_contains($rName, 'oat') && str_contains($milkChoice, 'oat')) {
                $subStmt = $pdo->prepare("SELECT item_id, item_name, quantity AS current_stock, cost_per_unit, alert_level, unit 
                                          FROM stock_items 
                                          WHERE LOWER(item_name) LIKE '%oat milk%' AND is_active = 1 LIMIT 1");
                $subStmt->execute();
                $subItem = $subStmt->fetch();
                if ($subItem) {
                    $itemId = (int)$subItem['item_id'];
                    $itemName = $subItem['item_name'];
                    $costPerUnit = (float)$subItem['cost_per_unit'];
                    $currentStock = (float)$subItem['current_stock'];
                    $alertLevel = (float)$subItem['alert_level'];
                    $unit = $subItem['unit'];
                }
            }

            // Customization Multiplier
            $customMultiplier = 1.0;
            if (str_contains($rName, 'sugar') || str_contains($rName, 'syrup') || str_contains($r['item_name'], 'ស្ករ') || str_contains($r['item_name'], 'ទឹកស្ករ') || $rCat === 'Syrups') {
                $customMultiplier = $sweetnessFactor;
            } elseif (str_contains($rName, 'ice') || str_contains($r['item_name'], 'ទឹកកក') || $rCat === 'Ice') {
                $customMultiplier = $iceFactor;
            }

            $totalReq = $reqPerDrink * $orderedQty * $customMultiplier;
            if ($totalReq <= 0) {
                continue; // 0% sweetness or No Ice
            }

            if (!isset($aggregatedIngredients[$itemId])) {
                $aggregatedIngredients[$itemId] = [
                    'item_id'          => $itemId,
                    'item_name'        => $itemName,
                    'unit'             => $unit,
                    'cost_per_unit'    => $costPerUnit,
                    'alert_level'      => $alertLevel,
                    'current_stock'    => $currentStock,
                    'total_qty_needed' => 0.0,
                    'products'         => []
                ];
            }

            $aggregatedIngredients[$itemId]['total_qty_needed'] += $totalReq;
            $aggregatedIngredients[$itemId]['products'][] = [
                'product_id'   => $productId,
                'product_name' => $oi['product_name'],
                'qty_ordered'  => $orderedQty,
                'amount_used'  => $totalReq
            ];
        }
    }

    if (empty($aggregatedIngredients)) {
        return ['success' => true, 'message' => 'No recipe ingredients configured for ordered products.', 'deducted' => [], 'warnings' => [], 'errors' => []];
    }

    // 3. Validation & Stock Sufficiency Pre-Check
    $errors = [];
    $warnings = [];

    foreach ($aggregatedIngredients as $ing) {
        $needed = $ing['total_qty_needed'];
        $current = $ing['current_stock'];

        if ($current < $needed) {
            $msg = "Insufficient stock for '{$ing['item_name']}': required {$needed} {$ing['unit']}, but only {$current} {$ing['unit']} on hand.";
            if ($strict_mode) {
                $errors[] = $msg;
            } else {
                $warnings[] = $msg . " (Stock deducted to 0.00)";
            }
        } elseif (($current - $needed) <= $ing['alert_level']) {
            $rem = $current - $needed;
            $warnings[] = "Low stock alert for '{$ing['item_name']}': only {$rem} {$ing['unit']} remaining after Order #{$order_id}.";
        }
    }

    if ($strict_mode && !empty($errors)) {
        return [
            'success'  => false,
            'message'  => 'Cannot fulfill order due to insufficient inventory stock.',
            'deducted' => [],
            'warnings' => $warnings,
            'errors'   => $errors
        ];
    }

    // 4. Atomic Execution of Inventory Deductions & Stock Logs
    $alreadyInTransaction = $pdo->inTransaction();
    if (!$alreadyInTransaction) {
        $pdo->beginTransaction();
    }

    $deductedItems = [];

    try {
        $updateStockStmt = $pdo->prepare("UPDATE stock_items 
            SET quantity = GREATEST(0, quantity - ?) 
            WHERE item_id = ?");

        $insertLogStmt = $pdo->prepare("INSERT INTO stock_logs 
            (item_id, order_id, product_id, change_type, quantity_changed, stock_before, stock_after, cost_at_time, notes, created_by) 
            VALUES (?, ?, ?, 'sale_deduct', ?, ?, ?, ?, ?, ?)");

        // Fetch fresh lock on each item
        $stockCheckStmt = $pdo->prepare("SELECT quantity, cost_per_unit FROM stock_items WHERE item_id = ? FOR UPDATE");

        foreach ($aggregatedIngredients as $ing) {
            $itemId = $ing['item_id'];
            $deductQty = $ing['total_qty_needed'];

            $stockCheckStmt->execute([$itemId]);
            $freshStock = $stockCheckStmt->fetch();
            $stockBefore = (float)($freshStock['quantity'] ?? 0.0);
            $costPerUnit = (float)($freshStock['cost_per_unit'] ?? $ing['cost_per_unit']);
            $stockAfter  = max(0.0, $stockBefore - $deductQty);

            // Execute deduction
            $updateStockStmt->execute([$deductQty, $itemId]);

            // Construct notes with product details
            $prodSummary = [];
            foreach ($ing['products'] as $p) {
                $prodSummary[] = "{$p['product_name']} (x{$p['qty_ordered']})";
            }
            $logNotes = "Order #{$order_id}: Used for " . implode(', ', $prodSummary);
            $primaryProductId = $ing['products'][0]['product_id'] ?? null;

            // Log deduction record
            $insertLogStmt->execute([
                $itemId,
                $order_id,
                $primaryProductId,
                -$deductQty,
                $stockBefore,
                $stockAfter,
                $costPerUnit,
                $logNotes,
                $performed_by
            ]);

            $deductedItems[] = [
                'item_id'          => $itemId,
                'item_name'        => $ing['item_name'],
                'unit'             => $ing['unit'],
                'quantity_deducted'=> $deductQty,
                'stock_before'     => $stockBefore,
                'stock_after'      => $stockAfter,
                'total_cost_value' => round($deductQty * $costPerUnit, 4)
            ];
        }

        if (!$alreadyInTransaction) {
            $pdo->commit();
        }

        return [
            'success'        => true,
            'message'        => "Successfully deducted " . count($deductedItems) . " inventory ingredient(s) for Order #{$order_id}.",
            'deducted'       => $deductedItems,
            'warnings'       => $warnings,
            'errors'         => []
        ];

    } catch (Exception $e) {
        if (!$alreadyInTransaction) {
            $pdo->rollBack();
        }
        error_log("deductStockForOrder Transaction Exception: " . $e->getMessage());
        return [
            'success'  => false,
            'message'  => 'Database error during stock deduction: ' . $e->getMessage(),
            'deducted' => [],
            'warnings' => $warnings,
            'errors'   => [$e->getMessage()]
        ];
    }
}

/**
 * Revert inventory deductions if an order is cancelled or refunded.
 */
function revertStockForOrder(int $order_id, PDO $pdo, string $performed_by = 'Order Cancellation'): array {
    if ($order_id <= 0) {
        return ['success' => false, 'message' => 'Invalid order ID.'];
    }

    $logsStmt = $pdo->prepare("SELECT * FROM stock_logs WHERE order_id = ? AND change_type = 'sale_deduct'");
    $logsStmt->execute([$order_id]);
    $deductionLogs = $logsStmt->fetchAll();

    if (empty($deductionLogs)) {
        return ['success' => true, 'message' => 'No previous stock deductions found for this order.'];
    }

    $alreadyInTransaction = $pdo->inTransaction();
    if (!$alreadyInTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $updStock = $pdo->prepare("UPDATE stock_items SET quantity = quantity + ? WHERE item_id = ?");
        $insLog = $pdo->prepare("INSERT INTO stock_logs 
            (item_id, order_id, product_id, change_type, quantity_changed, stock_before, stock_after, cost_at_time, notes, created_by) 
            VALUES (?, ?, ?, 'order_reversal', ?, ?, ?, ?, ?, ?)");
        $getStock = $pdo->prepare("SELECT quantity FROM stock_items WHERE item_id = ? FOR UPDATE");

        foreach ($deductionLogs as $log) {
            $itemId = (int)$log['item_id'];
            $restoreQty = abs((float)$log['quantity_changed']);

            $getStock->execute([$itemId]);
            $stockBefore = (float)$getStock->fetchColumn();
            $stockAfter = $stockBefore + $restoreQty;

            $updStock->execute([$restoreQty, $itemId]);

            $insLog->execute([
                $itemId,
                $order_id,
                $log['product_id'],
                $restoreQty,
                $stockBefore,
                $stockAfter,
                $log['cost_at_time'],
                "Reversal of Order #{$order_id} cancellation/refund",
                $performed_by
            ]);
        }

        if (!$alreadyInTransaction) {
            $pdo->commit();
        }

        return ['success' => true, 'message' => "Restored " . count($deductionLogs) . " ingredient(s) for Order #{$order_id}."];
    } catch (Exception $e) {
        if (!$alreadyInTransaction) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => 'Failed to revert stock: ' . $e->getMessage()];
    }
}

/**
 * Calculate the Cost of Goods Sold (COGS) and profit margin for a product.
 */
function getProductRecipeCost(int $product_id, PDO $pdo): array {
    $stmt = $pdo->prepare("SELECT p.product_id, p.name, p.price, p.category 
                           FROM products p 
                           WHERE p.product_id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();

    if (!$product) {
        return ['cost' => 0.0, 'margin' => 0.0, 'ingredients' => []];
    }

    $recipeStmt = $pdo->prepare("SELECT r.recipe_id, r.item_id, r.quantity_required, r.unit, r.notes, 
                                        s.item_name, s.category AS item_category, s.quantity AS on_hand, s.cost_per_unit 
                                 FROM product_recipes r 
                                 JOIN stock_items s ON r.item_id = s.item_id 
                                 WHERE r.product_id = ? AND s.is_active = 1");
    $recipeStmt->execute([$product_id]);
    $ingredients = $recipeStmt->fetchAll();

    $totalCost = 0.0;
    foreach ($ingredients as &$ing) {
        $qty = (float)$ing['quantity_required'];
        $unitCost = (float)$ing['cost_per_unit'];
        $ingCost = $qty * $unitCost;
        $ing['line_cost'] = round($ingCost, 4);
        $totalCost += $ingCost;
    }

    $sellingPrice = (float)$product['price'];
    $profit = max(0.0, $sellingPrice - $totalCost);
    $marginPct = $sellingPrice > 0 ? ($profit / $sellingPrice) * 100.0 : 0.0;

    return [
        'product'      => $product,
        'cogs'         => round($totalCost, 4),
        'selling_price'=> $sellingPrice,
        'profit'       => round($profit, 2),
        'margin_pct'   => round($marginPct, 1),
        'ingredients'  => $ingredients
    ];
}
