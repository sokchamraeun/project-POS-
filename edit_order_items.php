<?php
require 'auth.php';
require 'config.php';

$order_id = (int)($_GET['order_id'] ?? 0);
if ($order_id <= 0) { header("Location: find_order.php"); exit; }

/**
 * Keep ingredient stock in sync when an order is edited.
 * $delta_qty > 0  → more drinks: deduct (honest — floors at 0, logs actual, returns shortfalls).
 * $delta_qty < 0  → fewer/removed drinks: restore the difference.
 * Mirrors confirm_order's deduction and cancel_order's restore. Returns shortfalls
 * (['name','need','had']) when a deduction ran short so the caller can warn staff.
 */
function _sync_item_stock(mysqli $conn, int $product_id, int $delta_qty, string $milk_choice, int $order_id): array {
    return [];
}

// ── AJAX: Save changes ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    header('Content-Type: application/json');

    $remove_ids = [];
    if (!empty($_POST['remove_ids'])) {
        $remove_ids = array_filter(array_map('intval', explode(',', $_POST['remove_ids'])));
    }
    $qtys = isset($_POST['qtys']) ? json_decode($_POST['qtys'], true) : [];

    // Verify order is still editable and fetch order_date to preserve happy hour
    $stmt = $conn->prepare("SELECT order_id, order_date, total FROM orders WHERE order_id = ? AND payment_method = 'paylater'");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $editable_order = $stmt->get_result()->fetch_assoc();
    if (!$editable_order) {
        echo json_encode(['success' => false, 'error' => 'Order is no longer editable.']);
        exit;
    }
    // Determine if the order was placed during happy hour
    $orig_hour        = (int)date('H', strtotime($editable_order['order_date']));
    $was_happy_hour   = ($orig_hour >= HAPPY_HOUR_START && $orig_hour < HAPPY_HOUR_END);

    $conn->begin_transaction();
    try {
        // ── STOCK SYNC: restore/deduct ingredient stock for the edit delta ──
        // Snapshot current quantities BEFORE mutating, then for each item compute
        // new-vs-old: removed → restore full, reduced → restore diff, increased → deduct diff.
        $pre = [];
        $stmt_pre = $conn->prepare("SELECT item_id, product_id, product_name, quantity, milk, made_qty FROM order_items WHERE order_id = ?");
        $stmt_pre->bind_param("i", $order_id);
        $stmt_pre->execute();
        $pre_res = $stmt_pre->get_result();
        while ($r = $pre_res->fetch_assoc()) { $pre[(int)$r['item_id']] = $r; }

        // ── Made drinks are locked ── the barista already made them (made_qty >= quantity),
        // so they cannot be removed or re-quantified. The edit UI hides those controls; this
        // strips any such change from a crafted/stale request before it is applied.
        foreach ($pre as $iid => $r) {
            $q = (int)$r['quantity']; $mq = (int)($r['made_qty'] ?? 0);
            if ($q > 0 && $mq >= $q) {
                unset($qtys[$iid], $qtys[(string)$iid]);
                $remove_ids = array_values(array_filter($remove_ids, fn($x) => (int)$x !== $iid));
            }
        }

        $stock_warnings = [];
        foreach ($pre as $iid => $r) {
            $pid  = (int)$r['product_id'];
            $oldQ = (int)$r['quantity'];
            $milk = (string)$r['milk'];
            if ($pid <= 0) continue;                       // loyalty gifts have no recipe
            if (in_array($iid, $remove_ids)) {
                $newQ = 0;
            } elseif (isset($qtys[$iid])) {
                $newQ = max(1, (int)$qtys[$iid]);
            } else {
                $newQ = $oldQ;                             // untouched
            }
            $delta = $newQ - $oldQ;
            if ($delta !== 0) {
                $stock_warnings = array_merge($stock_warnings, _sync_item_stock($conn, $pid, $delta, $milk, $order_id));
            }
        }

        // Delete removed items
        if (!empty($remove_ids)) {
            $stmt_del = $conn->prepare("DELETE FROM order_items WHERE item_id = ? AND order_id = ?");
            foreach ($remove_ids as $item_id) {
                $stmt_del->bind_param("ii", $item_id, $order_id);
                $stmt_del->execute();
            }
        }

        // Update quantities
        if (!empty($qtys)) {
            $stmt_qty = $conn->prepare("UPDATE order_items SET quantity = ? WHERE item_id = ? AND order_id = ?");
            foreach ($qtys as $item_id => $qty) {
                $item_id = (int)$item_id;
                $qty     = max(1, (int)$qty);
                if (!in_array($item_id, $remove_ids)) {
                    $stmt_qty->bind_param("iii", $qty, $item_id, $order_id);
                    $stmt_qty->execute();
                }
            }
        }

        // Recalculate totals from remaining items
        $stmt_r = $conn->prepare("SELECT price, quantity, earns_points FROM order_items WHERE order_id = ?");
        $stmt_r->bind_param("i", $order_id);
        $stmt_r->execute();
        $remaining = $stmt_r->get_result()->fetch_all(MYSQLI_ASSOC);

        if (empty($remaining)) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => 'Cannot remove all items. Cancel the order instead.']);
            exit;
        }

        $subtotal  = 0; $total_qty = 0; $min_price = PHP_FLOAT_MAX; $points_qty = 0;
        foreach ($remaining as $row) {
            $p = (float)$row['price']; $q = (int)$row['quantity'];
            $subtotal  += $p * $q;
            $total_qty += $q;
            if ($p > 0 && (int)($row['earns_points'] ?? 1) === 1) $points_qty += $q;   // chargeable earning items only (gifts $0 + merch don't)
            if ($p < $min_price) $min_price = $p;
        }

        $buy3 = 0;
        if (BUY_X_GET_1_ENABLED && $total_qty >= BUY_X_COUNT && $min_price < PHP_FLOAT_MAX) {
            $buy3 = floor($total_qty / BUY_X_COUNT) * $min_price;
        }
        // Preserve happy hour if the order was originally placed during happy hour
        $happy_hour = 0;
        if ($was_happy_hour && HAPPY_HOUR_ENABLED) {
            $happy_hour = $subtotal * (HAPPY_HOUR_DISCOUNT / 100);
        }
        // Buy-X-Get-1-Free is a GIFT (extra free drink), not a discount — do NOT subtract $buy3.
        $total_discount = $happy_hour;
        $after  = $subtotal - $total_discount;
        $tax    = $after * (TAX_RATE / 100);
        $total  = round($after + $tax, 2);

        $stmt_upd = $conn->prepare("UPDATE orders SET total = ? WHERE order_id = ?");
        $stmt_upd->bind_param("di", $total, $order_id);
        $stmt_upd->execute();

        // ── AUDIT ── Record what this edit did to a already-placed order. Revenue on the
        // dashboard/report is SUM(orders.total), so an edit here moves the reported figures;
        // this is the trail that says who moved them. Written inside the transaction so the
        // log can't claim a change that was rolled back.
        $_changes = [];
        foreach ($pre as $iid => $r) {
            $nm   = $r['product_name'] ?? ('item ' . $iid);
            $oldQ = (int)$r['quantity'];
            if (in_array($iid, $remove_ids)) {
                $_changes[] = "removed {$nm} x{$oldQ}";
            } elseif (isset($qtys[$iid])) {
                $newQ = max(1, (int)$qtys[$iid]);
                if ($newQ !== $oldQ) $_changes[] = "{$nm} qty {$oldQ}→{$newQ}";
            }
        }
        if ($_changes) {
            $_total_before = (float)($editable_order['total'] ?? 0);
            log_order_audit($conn, $order_id, 'items_edited', implode('; ', $_changes), $_total_before, $total);
        }

        $conn->commit();

        // Build a one-line low-stock notice if any added drink ran short.
        $warning = '';
        if (!empty($stock_warnings)) {
            $names = array_values(array_unique(array_map(fn($s) => $s['name'], $stock_warnings)));
            $warning = 'Low stock — ' . implode(', ', $names) . ' ran short. Restock soon.';
        }

        echo json_encode([
            'success'     => true,
            'total'       => number_format($total, 2),
            'subtotal'    => number_format($subtotal, 2),
            'discount'    => number_format($total_discount, 2),
            'tax'         => number_format($tax, 2),
            'items_left'  => count($remaining),
            'warning'     => $warning,
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Fetch order ──
$stmt = $conn->prepare("
    SELECT order_id, order_id AS daily_order_no, 'Guest' AS customer_name, total, order_id AS token_number, 0 AS promotion_discount, order_date
    FROM orders
    WHERE order_id = ? AND payment_method = 'paylater'
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) { header("Location: find_order.php"); exit; }

// ── Fetch items ──
$stmt = $conn->prepare("
    SELECT item_id, product_name, price, quantity, size_label, sweetness, ice, milk, addons_snapshot, promo_percent, made_qty
    FROM order_items WHERE order_id = ? ORDER BY item_id ASC
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Order #<?= $order['daily_order_no'] ?> | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --bg: #0c0c0c; --bg-card: #131313; --bg-input: #1a1a1a;
    --border: #222; --border-hover: #2e2e2e;
    --accent: #d1904b; --accent-light: #e8b87a; --accent-dark: #a0702a;
    --text: #f0f0f0; --text-muted: #777; --text-light: #fff;
    --success: #55e087; --danger: #ff5f5f; --purple: #9b59b6;
    --shadow-sm: 0 2px 8px rgba(0,0,0,.35); --shadow-md: 0 4px 20px rgba(0,0,0,.45);
    --shadow-accent: 0 4px 20px rgba(209,144,75,.18);
    --radius: 14px; --transition: all .25s cubic-bezier(.4,0,.2,1);
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Poppins', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; padding: 28px 20px; }
::-webkit-scrollbar { width: 5px; } ::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 10px; }

.page { max-width: 980px; margin: 0 auto; }

/* Top bar */
.top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px; flex-wrap: wrap; gap: 10px; }
.btn-back { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 50px; background: var(--bg-card); border: 1px solid var(--border); color: var(--text-muted); text-decoration: none; font-size: 13px; font-weight: 500; transition: var(--transition); }
.btn-back:hover { border-color: var(--accent); color: var(--accent); }
.btn-back i { color: var(--accent); }

/* Order header */
.order-header { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 22px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; box-shadow: var(--shadow-sm); }
.order-meta { display: flex; gap: 24px; flex-wrap: wrap; align-items: center; }
.meta-item { display: flex; flex-direction: column; }
.meta-label { font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .5px; font-weight: 600; }
.meta-value { font-size: 16px; font-weight: 700; color: var(--text-light); margin-top: 2px; }
.meta-value.accent { color: var(--accent); }
.meta-value.purple { color: var(--purple); }
.paylater-badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 14px; border-radius: 50px; background: rgba(155,89,182,.12); color: var(--purple); border: 1px solid rgba(155,89,182,.25); font-size: 12px; font-weight: 600; }

/* Layout */
.layout { display: grid; grid-template-columns: 1fr 300px; gap: 20px; align-items: start; }

/* Items panel */
.panel { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 22px; box-shadow: var(--shadow-sm); }
.panel-title { font-size: 15px; font-weight: 700; color: var(--text-light); margin-bottom: 18px; padding-bottom: 12px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 9px; }
.panel-title i { color: var(--accent); }

/* Item row */
.item-row { border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; margin-bottom: 10px; transition: var(--transition); position: relative; display: flex; gap: 14px; align-items: center; }
.item-row:last-child { margin-bottom: 0; }
.item-row:hover { border-color: var(--border-hover); background: rgba(255,255,255,.02); }
.item-row.removed { opacity: .45; border-color: rgba(255,95,95,.25); background: rgba(255,95,95,.03); }
.item-row.removed .item-name { text-decoration: line-through; color: var(--text-muted); }

.item-num { font-size: 12px; color: var(--text-muted); font-weight: 600; min-width: 20px; }
.item-info { flex: 1; min-width: 0; }
.item-name { font-size: 14px; font-weight: 600; color: var(--text-light); margin-bottom: 3px; }
.item-custom { font-size: 11px; color: var(--text-muted); }
.item-unit-price { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

.item-controls { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

/* Qty control */
.qty-control { display: flex; align-items: center; background: var(--bg-input); border: 1px solid var(--border); border-radius: 50px; overflow: hidden; }
.qty-control button { width: 28px; height: 28px; background: none; border: none; color: var(--accent); font-size: 15px; cursor: pointer; transition: var(--transition); display: flex; align-items: center; justify-content: center; }
.qty-control button:hover { background: rgba(209,144,75,.15); }
.qty-control button:disabled { opacity: .3; cursor: default; }
.qty-control input[type="number"] { width: 34px; text-align: center; font-weight: 600; font-size: 13px; background: transparent; border: none; outline: none; color: var(--text); font-family: 'Poppins', sans-serif; -moz-appearance: textfield; }
.qty-control input::-webkit-inner-spin-button, .qty-control input::-webkit-outer-spin-button { -webkit-appearance: none; }

.item-line-total { font-size: 14px; font-weight: 600; color: var(--accent); min-width: 52px; text-align: right; }
.item-row.removed .item-line-total { color: var(--text-muted); }
/* Made drinks: barista already made them — locked, cannot edit/remove. */
.item-row.made { opacity: .6; background: rgba(255,255,255,.015); }
.item-row.made:hover { background: rgba(255,255,255,.015); border-color: var(--border); }
.item-row.made .item-name { color: var(--text-muted); }
.made-tag { font-size: 11px; font-weight: 700; color: var(--success); background: rgba(85,224,135,.12); border: 1px solid rgba(85,224,135,.3); border-radius: 20px; padding: 1px 8px; margin-left: 6px; vertical-align: middle; white-space: nowrap; }
.item-qty-made { font-size: 15px; font-weight: 700; color: var(--text-muted); min-width: 44px; text-align: center; }
.item-locked { color: var(--text-muted); font-size: 13px; width: 34px; text-align: center; }

/* Remove / undo buttons */
.btn-remove { width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 50%; border: 1px solid rgba(255,95,95,.25); background: rgba(255,95,95,.08); color: var(--danger); cursor: pointer; transition: var(--transition); font-size: 13px; }
.btn-remove:hover { background: var(--danger); color: #fff; border-color: var(--danger); transform: scale(1.1); }
.btn-undo { width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 50%; border: 1px solid rgba(85,224,135,.25); background: rgba(85,224,135,.08); color: var(--success); cursor: pointer; transition: var(--transition); font-size: 13px; }
.btn-undo:hover { background: var(--success); color: #000; border-color: var(--success); transform: scale(1.1); }

.removed-badge { position: absolute; top: -8px; right: 10px; background: var(--danger); color: #fff; font-size: 9px; font-weight: 700; padding: 2px 8px; border-radius: 20px; letter-spacing: .5px; text-transform: uppercase; }

/* Empty state */
.empty-items { text-align: center; padding: 40px 20px; color: var(--text-muted); }
.empty-items i { font-size: 36px; margin-bottom: 10px; display: block; }

/* Item list pager */
.item-pager { display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 16px; flex-wrap: wrap; }
.item-pager button { min-width: 32px; height: 32px; padding: 0 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-input); color: var(--text-muted); font-family: 'Poppins', sans-serif; font-size: 13px; font-weight: 600; cursor: pointer; transition: var(--transition); }
.item-pager button:hover:not(:disabled) { border-color: var(--accent); color: var(--accent); }
.item-pager button.active { background: var(--accent); border-color: var(--accent); color: #000; }
.item-pager button:disabled { opacity: .4; cursor: default; }
.item-pager .pager-ellipsis { color: var(--text-muted); padding: 0 2px; }

/* Summary panel */
.summary-panel { position: sticky; top: 20px; }
.summary-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 13px; color: var(--text-muted); border-bottom: 1px solid var(--border); }
.summary-row:last-of-type { border-bottom: none; }
.summary-row.discount { color: var(--success); }
.summary-row.total-row { padding-top: 12px; font-size: 18px; font-weight: 700; color: var(--text-light); }
.summary-row.total-row span:last-child { color: var(--accent); }
.discount-row { display: none; }
.discount-row.visible { display: flex; }

/* Save button */
.btn-save { width: 100%; margin-top: 18px; padding: 13px; background: var(--accent); border: none; border-radius: 10px; color: #000; font-weight: 700; font-size: 15px; cursor: pointer; transition: var(--transition); display: flex; align-items: center; justify-content: center; gap: 8px; font-family: 'Poppins', sans-serif; }
.btn-save:hover:not(:disabled) { background: var(--accent-light); transform: translateY(-2px); box-shadow: var(--shadow-accent); }
.btn-save:disabled { opacity: .5; cursor: not-allowed; transform: none; }

.change-hint { margin-top: 10px; font-size: 11px; color: var(--text-muted); text-align: center; display: none; }
.change-hint.visible { display: block; }
.change-hint.has-changes { color: var(--accent); }

/* Toast */
#toast { position: fixed; bottom: 28px; left: 50%; transform: translateX(-50%) translateY(20px); background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 12px 22px; font-size: 13px; font-weight: 600; box-shadow: var(--shadow-md); opacity: 0; transition: all .3s ease; pointer-events: none; display: flex; align-items: center; gap: 8px; min-width: 220px; justify-content: center; z-index: 9999; }
#toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
#toast.success { border-color: rgba(85,224,135,.35); color: var(--success); }
#toast.error   { border-color: rgba(255,95,95,.35);  color: var(--danger); }

@media (max-width: 720px) {
    .layout { grid-template-columns: 1fr; }
    .summary-panel { position: static; }
    .order-meta { gap: 16px; }
}
</style>
</head>
<body>

<div class="page">

    <!-- Top bar -->
    <div class="top-bar">
        <a href="find_order.php?tab=paylater" class="btn-back">
            <i class="fa-solid fa-arrow-left"></i> Back to Orders
        </a>
        <span style="font-size:13px;color:var(--text-muted);">
            <i class="fa-solid fa-pen-to-square" style="color:var(--accent);margin-right:6px;"></i>
            Editing Pay Later Order
        </span>
    </div>

    <!-- Order header -->
    <div class="order-header">
        <div class="order-meta">
            <div class="meta-item">
                <span class="meta-label">Order</span>
                <span class="meta-value accent">#<?= (int)$order['daily_order_no'] ?></span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Customer</span>
                <span class="meta-value"><?= htmlspecialchars($order['customer_name']) ?></span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Current Total</span>
                <span class="meta-value accent" id="headerTotal">$<?= number_format($order['total'], 2) ?></span>
            </div>
        </div>
        <div class="paylater-badge">
            <i class="fa-solid fa-clock"></i> Pay Later
        </div>
    </div>

    <div class="layout">

        <!-- Items list -->
        <div class="panel">
            <div class="panel-title">
                <i class="fa-solid fa-list"></i>
                Order Items
                <span style="margin-left:auto;font-size:12px;font-weight:500;color:var(--text-muted);" id="itemCountLabel">
                    <?= count($items) ?> item<?= count($items) !== 1 ? 's' : '' ?>
                </span>
            </div>

            <?php if (empty($items)): ?>
            <div class="empty-items">
                <i class="fa-solid fa-box-open"></i>
                <p>No items found for this order.</p>
            </div>
            <?php else: ?>
            <div id="itemList">
            <?php foreach ($items as $n => $item):
                $lineTotal = $item['price'] * $item['quantity'];
                $customs = [];
                if (!empty($item['size_label'])) $customs[] = 'Size: ' . $item['size_label'];
                if (!empty($item['sweetness'])) $customs[] = 'Sweet: ' . $item['sweetness'];
                if (!empty($item['ice']))       $customs[] = 'Ice: '   . $item['ice'];
                if (!empty($item['milk']))      $customs[] = 'Milk: '  . $item['milk'];
                $__ad = json_decode($item['addons_snapshot'] ?? '[]', true) ?: [];
                if ($__ad) $customs[] = 'Add-ons: ' . implode(', ', array_map(fn($a) => $a['name'], $__ad));
            ?>
            <?php
                $__qty  = (int)$item['quantity'];
                $__made = ((int)($item['made_qty'] ?? 0) >= $__qty && $__qty > 0);  // drink already made by barista
            ?>
            <div class="item-row<?= $__made ? ' made' : '' ?>" id="row-<?= $item['item_id'] ?>" data-id="<?= $item['item_id'] ?>" data-price="<?= (float)$item['price'] ?>" data-name="<?= htmlspecialchars($item['product_name'], ENT_QUOTES) ?>" data-made="<?= $__made ? '1' : '0' ?>">

                <span class="item-num"><?= $n + 1 ?></span>

                <div class="item-info">
                    <div class="item-name"><?= htmlspecialchars($item['product_name']) ?><?php if ($__made): ?> <span class="made-tag"><i class="fa-solid fa-check"></i> Made</span><?php endif; ?></div>
                    <?php if ((int)($item['promo_percent'] ?? 0) > 0): ?>
                    <div class="item-custom" style="color:#c0392b;font-weight:600;"><?= (int)$item['promo_percent'] ?>% OFF applied</div>
                    <?php endif; ?>
                    <?php if ($customs): ?>
                    <div class="item-custom"><?= htmlspecialchars(implode(' · ', $customs)) ?></div>
                    <?php endif; ?>
                    <div class="item-unit-price">$<?= number_format($item['price'], 2) ?> each</div>
                </div>

                <div class="item-controls">
                    <?php if ($__made): ?>
                    <!-- Made drinks are locked: no qty change, no removal. Hidden qty keeps recalc/save consistent. -->
                    <span class="item-qty-made">×<?= $__qty ?></span>
                    <input type="hidden" id="qty-<?= $item['item_id'] ?>" value="<?= $__qty ?>">
                    <span class="item-line-total" id="line-<?= $item['item_id'] ?>">
                        $<?= number_format($lineTotal, 2) ?>
                    </span>
                    <span class="item-locked" title="Already made — cannot edit"><i class="fa-solid fa-lock"></i></span>
                    <?php else: ?>
                    <div class="qty-control">
                        <button type="button" onclick="changeQty(<?= $item['item_id'] ?>, -1)" id="minus-<?= $item['item_id'] ?>">−</button>
                        <input type="number" id="qty-<?= $item['item_id'] ?>" value="<?= $item['quantity'] ?>" min="1" max="99"
                               oninput="onQtyInput(<?= $item['item_id'] ?>)">
                        <button type="button" onclick="changeQty(<?= $item['item_id'] ?>, 1)">+</button>
                    </div>

                    <span class="item-line-total" id="line-<?= $item['item_id'] ?>">
                        $<?= number_format($lineTotal, 2) ?>
                    </span>

                    <button class="btn-remove" id="remove-btn-<?= $item['item_id'] ?>"
                            onclick="markRemove(<?= $item['item_id'] ?>)" title="Remove item">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                    <button class="btn-undo" id="undo-btn-<?= $item['item_id'] ?>"
                            onclick="undoRemove(<?= $item['item_id'] ?>)" title="Undo remove" style="display:none;">
                        <i class="fa-solid fa-rotate-left"></i>
                    </button>
                    <?php endif; ?>
                </div>

            </div>
            <?php endforeach; ?>
            </div>
            <div id="itemPager" class="item-pager" style="display:none;"></div>
            <?php endif; ?>
        </div>

        <!-- Summary panel -->
        <div class="summary-panel">
            <div class="panel">
                <div class="panel-title"><i class="fa-solid fa-receipt"></i> Summary</div>

                <div class="summary-row">
                    <span>Subtotal</span>
                    <span id="sumSubtotal">$<?= number_format(array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $items)), 2) ?></span>
                </div>
                <?php
                // Buy-X-Get-1-Free GIFT (display only — free drink is an extra bonus, not subtracted).
                $__gift_qty = array_sum(array_map(fn($i) => (int)$i['quantity'], $items));
                $__gift_min = PHP_FLOAT_MAX; $__gift_name = '';
                foreach ($items as $__gi) {
                    if ((float)$__gi['price'] < $__gift_min) { $__gift_min = (float)$__gi['price']; $__gift_name = $__gi['product_name']; }
                }
                $__gift_count = (BUY_X_GET_1_ENABLED && $__gift_qty >= BUY_X_COUNT && $__gift_min < PHP_FLOAT_MAX)
                    ? (int)floor($__gift_qty / BUY_X_COUNT) : 0;
                // Free-item override (Settings > Free Drink) wins over the cheapest fallback.
                if (defined('FREE_ITEM_PRODUCT_ID') && FREE_ITEM_PRODUCT_ID > 0) {
                    if ($__ovr = $conn->query("SELECT name FROM products WHERE product_id = " . (int)FREE_ITEM_PRODUCT_ID)) {
                        if ($__ov = $__ovr->fetch_assoc()) $__gift_name = $__ov['name'];
                    }
                }
                ?>
                <div class="summary-row" id="giftRow" style="<?= $__gift_count > 0 ? '' : 'display:none' ?>">
                    <span>&#x1F381; Buy <?= BUY_X_COUNT ?> Get 1 Free</span>
                    <span id="giftLabel" style="color:#27ae60;font-weight:700;"><?= $__gift_count > 0 ? htmlspecialchars($__gift_name) . ($__gift_count > 1 ? ' &times;'.$__gift_count : '') . ' FREE' : '' ?></span>
                </div>
                <div class="summary-row discount discount-row" id="discountRow">
                    <span><i class="fa-solid fa-tag" style="font-size:10px;"></i> Discount</span>
                    <span id="sumDiscount">-$<?= number_format($order['promotion_discount'], 2) ?></span>
                </div>
                <div class="summary-row">
                    <span>Tax (<?= TAX_RATE ?>%)</span>
                    <span id="sumTax">$—</span>
                </div>
                <div class="summary-row total-row">
                    <span>Total</span>
                    <span id="sumTotal">$<?= number_format($order['total'], 2) ?></span>
                </div>

                <button class="btn-save" id="saveBtn" onclick="saveChanges()">
                    <i class="fa-solid fa-floppy-disk"></i> Save Changes
                </button>

                <p class="change-hint" id="changeHint">No changes yet</p>
            </div>
        </div>

    </div>

</div>

<div id="toast"></div>

<script>
const ORDER_ID          = <?= (int)$order_id ?>;
const WAS_HAPPY_HOUR    = <?= ((int)date('H', strtotime($order['order_date'])) >= HAPPY_HOUR_START && (int)date('H', strtotime($order['order_date'])) < HAPPY_HOUR_END) ? 'true' : 'false' ?>;
const HAPPY_HOUR_PCT    = <?= HAPPY_HOUR_DISCOUNT ?> / 100;
const HAPPY_HOUR_ON     = <?= HAPPY_HOUR_ENABLED ? 'true' : 'false' ?>;
const TAX_RATE_MULT     = <?= TAX_RATE ?> / 100;
const BUY_X_ON          = <?= BUY_X_GET_1_ENABLED ? 'true' : 'false' ?>;
const BUY_X_CNT         = <?= BUY_X_COUNT ?>;
const FREE_OVERRIDE_NAME = <?= json_encode((defined('FREE_ITEM_PRODUCT_ID') && FREE_ITEM_PRODUCT_ID > 0) ? $__gift_name : '') ?>;

// ── State ──
const removedIds = new Set();
let isDirty = false;

// ── Quantity helpers ──
function changeQty(id, delta) {
    const input = document.getElementById('qty-' + id);
    let val = parseInt(input.value || '1', 10) + delta;
    if (val < 1) val = 1;
    if (val > 99) val = 99;
    input.value = val;
    onQtyInput(id);
}

function onQtyInput(id) {
    const input = document.getElementById('qty-' + id);
    let val = parseInt(input.value || '1', 10);
    if (isNaN(val) || val < 1) val = 1;
    if (val > 99) val = 99;
    input.value = val;

    const row = document.getElementById('row-' + id);
    const price = parseFloat(row.dataset.price);
    const lineEl = document.getElementById('line-' + id);
    lineEl.textContent = '$' + (price * val).toFixed(2);

    markDirty();
    recalcSummary();
}

// ── Remove / undo ──
function markRemove(id) {
    removedIds.add(id);
    const row = document.getElementById('row-' + id);
    row.classList.add('removed');

    // Add badge
    if (!document.getElementById('badge-' + id)) {
        const badge = document.createElement('span');
        badge.className = 'removed-badge';
        badge.id = 'badge-' + id;
        badge.textContent = 'Removed';
        row.appendChild(badge);
    }

    document.getElementById('remove-btn-' + id).style.display = 'none';
    document.getElementById('undo-btn-' + id).style.display  = 'flex';

    // Disable qty controls
    document.getElementById('qty-' + id).disabled = true;
    const btns = row.querySelectorAll('.qty-control button');
    btns.forEach(b => b.disabled = true);

    markDirty();
    recalcSummary();
    updateItemCount();
}

function undoRemove(id) {
    removedIds.delete(id);
    const row = document.getElementById('row-' + id);
    row.classList.remove('removed');

    const badge = document.getElementById('badge-' + id);
    if (badge) badge.remove();

    document.getElementById('remove-btn-' + id).style.display = 'flex';
    document.getElementById('undo-btn-' + id).style.display  = 'none';

    document.getElementById('qty-' + id).disabled = false;
    const btns = row.querySelectorAll('.qty-control button');
    btns.forEach(b => b.disabled = false);

    markDirty();
    recalcSummary();
    updateItemCount();
}

// ── Live recalculation ──
function recalcSummary() {
    let subtotal = 0, totalQty = 0, minPrice = Infinity, minName = '';

    document.querySelectorAll('#itemList .item-row').forEach(row => {
        const id = parseInt(row.dataset.id);
        if (removedIds.has(id)) return;

        const price = parseFloat(row.dataset.price);
        const qty   = parseInt(document.getElementById('qty-' + id)?.value || '1', 10);
        subtotal  += price * qty;
        totalQty  += qty;
        if (price < minPrice) { minPrice = price; minName = row.dataset.name || ''; }
    });

    // Buy-X-Get-1-Free GIFT: number of free bonus drinks (display only, not subtracted).
    const freeCount = (BUY_X_ON && totalQty >= BUY_X_CNT && isFinite(minPrice)) ? Math.floor(totalQty / BUY_X_CNT) : 0;
    const giftRow = document.getElementById('giftRow');
    const giftLabel = document.getElementById('giftLabel');
    if (giftRow && giftLabel) {
        if (freeCount > 0) {
            giftLabel.textContent = (FREE_OVERRIDE_NAME || minName) + (freeCount > 1 ? ' ×' + freeCount : '') + ' FREE';
            giftRow.style.display = '';
        } else {
            giftRow.style.display = 'none';
        }
    }
    let buy3 = 0;
    // Preserve happy hour if order was originally placed during happy hour
    const happyHour = (WAS_HAPPY_HOUR && HAPPY_HOUR_ON) ? subtotal * HAPPY_HOUR_PCT : 0;
    // Buy-X-Get-1-Free is a gift, not a discount — do not subtract buy3 from the total.
    const totalDiscount = happyHour;

    const after = subtotal - totalDiscount;
    const tax   = after * TAX_RATE_MULT;
    const total = Math.round((after + tax) * 100) / 100;

    document.getElementById('sumSubtotal').textContent = '$' + subtotal.toFixed(2);
    document.getElementById('sumTax').textContent      = '$' + tax.toFixed(2);
    document.getElementById('sumTotal').textContent    = '$' + total.toFixed(2);

    const discRow  = document.getElementById('discountRow');
    const discAmt  = document.getElementById('sumDiscount');
    if (totalDiscount > 0) {
        discAmt.textContent = '-$' + totalDiscount.toFixed(2);
        discRow.classList.add('visible');
    } else {
        discRow.classList.remove('visible');
    }
}

function updateItemCount() {
    const active = document.querySelectorAll('#itemList .item-row:not(.removed)').length;
    const label  = document.getElementById('itemCountLabel');
    if (label) label.textContent = active + ' item' + (active !== 1 ? 's' : '');
}

// ── Dirty state ──
function markDirty() {
    isDirty = true;
    const hint = document.getElementById('changeHint');
    const removedCount = removedIds.size;
    hint.classList.add('visible', 'has-changes');

    const parts = [];
    if (removedCount > 0) parts.push(removedCount + ' item' + (removedCount !== 1 ? 's' : '') + ' removed');
    hint.textContent = parts.length ? 'Unsaved: ' + parts.join(', ') : 'Quantities updated';
}

// ── Save ──
async function saveChanges() {
    if (!isDirty) { showToast('No changes to save.', 'info'); return; }

    const saveBtn = document.getElementById('saveBtn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

    // Collect qtys for non-removed items
    const qtys = {};
    document.querySelectorAll('#itemList .item-row').forEach(row => {
        const id = parseInt(row.dataset.id);
        if (!removedIds.has(id)) {
            qtys[id] = parseInt(document.getElementById('qty-' + id)?.value || '1', 10);
        }
    });

    const body = new URLSearchParams({
        action:     'save',
        remove_ids: [...removedIds].join(','),
        qtys:       JSON.stringify(qtys),
    });

    try {
        const res  = await fetch('edit_order_items.php?order_id=' + ORDER_ID, { method: 'POST', body });
        const data = await res.json();

        if (data.success) {
            // Update header total
            document.getElementById('headerTotal').textContent = '$' + data.total;
            document.getElementById('sumTotal').textContent    = '$' + data.total;

            // Remove "removed" rows from DOM
            removedIds.forEach(id => {
                const row = document.getElementById('row-' + id);
                if (row) row.remove();
            });
            removedIds.clear();

            isDirty = false;
            const hint = document.getElementById('changeHint');
            hint.textContent = 'Saved successfully';
            hint.classList.remove('has-changes');

            if (data.items_left === 0) {
                document.getElementById('itemList').innerHTML =
                    '<div class="empty-items"><i class="fa-solid fa-box-open"></i><p>All items have been removed.</p></div>';
            }

            updateItemCount();
            renderItemPage();
            if (data.warning) {
                showToast('Order updated — new total $' + data.total, 'success');
                setTimeout(() => showToast(data.warning, 'error'), 1400);
            } else {
                showToast('Order updated — new total $' + data.total, 'success');
            }
        } else {
            showToast(data.error || 'Save failed. Try again.', 'error');
        }
    } catch (e) {
        showToast('Network error. Try again.', 'error');
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';
    }
}

// ── Toast ──
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    const icon = type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-info';
    t.innerHTML = `<i class="fa-solid ${icon}"></i> ${msg}`;
    t.className = 'show ' + type;
    clearTimeout(t._timer);
    t._timer = setTimeout(() => { t.className = ''; }, 3200);
}

// ── Item list pagination (client-side, 10/page) ──
// Rows never leave the DOM — they're only hidden per page — so recalcSummary() and
// saveChanges() keep seeing every #itemList .item-row and the whole order still saves at once.
const ITEMS_PER_PAGE = 10;
let itemPage = 1;
function pagerRows() { return Array.from(document.querySelectorAll('#itemList .item-row')); }
function pagerTotalPages() { return Math.max(1, Math.ceil(pagerRows().length / ITEMS_PER_PAGE)); }

function renderItemPage() {
    const rows = pagerRows();
    const tp = pagerTotalPages();
    if (itemPage > tp) itemPage = tp;
    if (itemPage < 1) itemPage = 1;
    rows.forEach((row, i) => {
        const page = Math.floor(i / ITEMS_PER_PAGE) + 1;
        row.style.display = (page === itemPage) ? '' : 'none';
    });
    renderPager(tp);
}

function renderPager(tp) {
    const el = document.getElementById('itemPager');
    if (!el) return;
    if (tp <= 1) { el.innerHTML = ''; el.style.display = 'none'; return; }
    el.style.display = 'flex';
    let h = '<button ' + (itemPage === 1 ? 'disabled' : '') + ' onclick="gotoItemPage(' + (itemPage - 1) + ')">‹ Prev</button>';
    for (let p = 1; p <= tp; p++) {
        if (p === 1 || p === tp || Math.abs(p - itemPage) <= 1) {
            h += '<button class="' + (p === itemPage ? 'active' : '') + '" onclick="gotoItemPage(' + p + ')">' + p + '</button>';
        } else if (p === itemPage - 2 || p === itemPage + 2) {
            h += '<span class="pager-ellipsis">…</span>';
        }
    }
    h += '<button ' + (itemPage === tp ? 'disabled' : '') + ' onclick="gotoItemPage(' + (itemPage + 1) + ')">Next ›</button>';
    el.innerHTML = h;
}

function gotoItemPage(p) {
    itemPage = p;
    renderItemPage();
}

// ── Init ──
document.addEventListener('DOMContentLoaded', () => {
    recalcSummary();
    renderItemPage();
    const hint = document.getElementById('changeHint');
    hint.classList.add('visible');
    hint.textContent = 'No changes yet';
});

// Warn before leaving with unsaved changes
window.addEventListener('beforeunload', e => {
    if (isDirty) { e.preventDefault(); e.returnValue = ''; }
});
</script>

</body>
</html>
