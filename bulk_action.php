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
    // 1. Remove product images and collect names/images
    $sel = $conn->prepare("SELECT name, image FROM products WHERE product_id IN ($ph)");
    $sel->bind_param($types, ...$ids);
    $sel->execute();
    $rows = $sel->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $prodImages = [];
    $prodNames  = [];
    foreach ($rows as $r) {
        if (!empty($r['image'])) {
            $img = trim($r['image']);
            cloudinary_delete_image($img);
            $prodImages[] = $img;
        }
        if (!empty($r['name'])) {
            $prodNames[] = trim($r['name']);
            $cleanName = preg_replace('/\((?:Box|កេស|កេសធំ|កាតុង|Carton|Case|Pack|យួរ|Package|កញ្ចប់|Dozen|ឡូ|Crate|ស្នោ)\)/ui', '', $r['name']);
            $cleanName = trim(preg_replace('/\b(?:Box|Carton|Case|Pack|Package|Dozen|Crate)\b/ui', '', $cleanName));
            if (!empty($cleanName)) $prodNames[] = $cleanName;
        }
    }

    // 2. Find linked stock items
    $stockItemIds = [];
    $recSel = $conn->prepare("SELECT item_id FROM product_recipes WHERE product_id IN ($ph)");
    if ($recSel) {
        $recSel->bind_param($types, ...$ids);
        $recSel->execute();
        $rRes = $recSel->get_result();
        while ($rRow = $rRes->fetch_assoc()) {
            if (!empty($rRow['item_id'])) {
                $stockItemIds[] = (int)$rRow['item_id'];
            }
        }
    }

    if (!empty($prodNames) || !empty($prodImages)) {
        $allNames = array_values(array_unique(array_filter($prodNames)));
        $allImgs  = array_values(array_unique(array_filter($prodImages)));
        
        $whereClauses = [];
        $params = [];
        $paramTypes = '';
        
        if (!empty($allNames)) {
            $nPh = implode(',', array_fill(0, count($allNames), '?'));
            $whereClauses[] = "LOWER(REPLACE(item_name, ' ', '')) IN ($nPh)";
            foreach ($allNames as $nm) {
                $params[] = strtolower(str_replace(' ', '', $nm));
                $paramTypes .= 's';
            }
        }
        if (!empty($allImgs)) {
            $iPh = implode(',', array_fill(0, count($allImgs), '?'));
            $whereClauses[] = "image IN ($iPh) OR image_box IN ($iPh)";
            foreach ($allImgs as $im) {
                $params[] = $im;
                $paramTypes .= 's';
            }
            foreach ($allImgs as $im) {
                $params[] = $im;
                $paramTypes .= 's';
            }
        }
        
        if (!empty($whereClauses)) {
            $stkSql = "SELECT item_id FROM stock_items WHERE " . implode(' OR ', $whereClauses);
            $stkStmt = $conn->prepare($stkSql);
            if ($stkStmt) {
                $stkStmt->bind_param($paramTypes, ...$params);
                $stkStmt->execute();
                $sRes = $stkStmt->get_result();
                while ($sRow = $sRes->fetch_assoc()) {
                    $stockItemIds[] = (int)$sRow['item_id'];
                }
            }
        }
    }

    $hasUnitDeletes = false;
    $hasBoxDeletes  = false;
    foreach ($rows as $r) {
        $pName = $r['name'] ?? '';
        $isB = preg_match('/\((?:Box|កេស|កេសធំ|កាតុង|Carton|Case|Pack|យួរ|Package|កញ្ចប់|Dozen|ឡូ|Crate|ស្នោ)\)/ui', $pName) ||
               preg_match('/\b(?:Box|Carton|Case|Pack|Package|Dozen|Crate)\b/ui', $pName) ||
               preg_match('/(?:កេស|កាតុង|យួរ|កញ្ចប់|ឡូ|ស្នោ)/u', $pName);
        if ($isB) {
            $hasBoxDeletes = true;
        } else {
            $hasUnitDeletes = true;
        }
    }

    $stockItemIds = array_values(array_unique(array_filter($stockItemIds)));
    if (!empty($stockItemIds)) {
        $sPh = implode(',', array_fill(0, count($stockItemIds), '?'));
        $sTypes = str_repeat('i', count($stockItemIds));
        
        $fetchImages = $conn->prepare("SELECT item_id, image, image_box FROM stock_items WHERE item_id IN ($sPh)");
        $fetchImages->bind_param($sTypes, ...$stockItemIds);
        $fetchImages->execute();
        $itemsWithImages = $fetchImages->get_result()->fetch_all(MYSQLI_ASSOC);
        
        foreach ($itemsWithImages as $it) {
            $sId = (int)$it['item_id'];
            if ($hasUnitDeletes && !empty($it['image'])) {
                cloudinary_delete_image($it['image']);
                $conn->query("UPDATE stock_items SET image = NULL WHERE item_id = {$sId}");
            }
            if ($hasBoxDeletes && !empty($it['image_box'])) {
                cloudinary_delete_image($it['image_box']);
                $conn->query("UPDATE stock_items SET image_box = NULL WHERE item_id = {$sId}");
            }
        }
    }

    // 3. Delete recipes and products
    $delRec = $conn->prepare("DELETE FROM product_recipes WHERE product_id IN ($ph)");
    $delRec->bind_param($types, ...$ids);
    $delRec->execute();

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
