<?php
require 'admin_only.php';
require 'config.php';
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$raw    = explode(',', $_POST['ids'] ?? '');
$ids    = array_values(array_filter(array_map('intval', $raw), fn($v) => $v > 0));

if (empty($ids)) { echo json_encode(['ok' => false, 'error' => 'No IDs']); exit; }

$ph = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));

if ($action === 'delete') {
    // Remove image files
    $sel = $conn->prepare("SELECT image FROM products WHERE product_id IN ($ph)");
    $sel->bind_param($types, ...$ids);
    $sel->execute();
    $rows = $sel->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $r) {
        if (!empty($r['image']) && file_exists($r['image'])) unlink($r['image']);
    }
    $del = $conn->prepare("DELETE FROM products WHERE product_id IN ($ph)");
    $del->bind_param($types, ...$ids);
    $del->execute();
    echo json_encode(['ok' => true, 'deleted' => $ids]);

} elseif ($action === 'toggle') {
    $upd = $conn->prepare("UPDATE products SET is_available = 1 - is_available WHERE product_id IN ($ph)");
    $upd->bind_param($types, ...$ids);
    $upd->execute();
    $sel = $conn->prepare("SELECT product_id, is_available FROM products WHERE product_id IN ($ph)");
    $sel->bind_param($types, ...$ids);
    $sel->execute();
    $states = [];
    foreach ($sel->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $states[$r['product_id']] = (int)$r['is_available'];
    }
    echo json_encode(['ok' => true, 'states' => $states]);

} elseif ($action === 'clear_promo') {
    // Ends a promotion across a selection in one go. Clearing them one product at
    // a time through edit_product.php is the pain this exists to remove.
    //
    // Only products that actually carry a promo are counted, so the confirmation
    // can say what really changed rather than echoing the selection size — a
    // manager who selects all 53 products should be told 6 were cleared, not 53.
    //
    // products.promo_percent is the CURRENT price rule. order_items.promo_percent
    // is a separate column holding what each past sale was actually charged, and
    // is deliberately untouched: clearing a promotion must not rewrite the history
    // of what customers already paid.
    $sel = $conn->prepare("SELECT COUNT(*) FROM products WHERE product_id IN ($ph) AND promo_percent > 0");
    $sel->bind_param($types, ...$ids);
    $sel->execute();
    $had = (int)$sel->get_result()->fetch_row()[0];

    $upd = $conn->prepare("UPDATE products SET promo_percent = 0 WHERE product_id IN ($ph) AND promo_percent > 0");
    $upd->bind_param($types, ...$ids);
    $upd->execute();

    echo json_encode(['ok' => true, 'cleared' => $had, 'ids' => $ids]);

} else {
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
