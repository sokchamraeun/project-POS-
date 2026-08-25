<?php
require 'admin_only.php';
require 'config.php';
header('Content-Type: application/json');

$id = (int)($_POST['product_id'] ?? 0);
if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid ID']); exit; }

$sel = $conn->prepare("SELECT name, image FROM products WHERE product_id = ?");
$sel->bind_param("i", $id);
$sel->execute();
$row = $sel->get_result()->fetch_assoc();

if (!$row) { echo json_encode(['ok' => false, 'error' => 'Product not found']); exit; }

$prodImage = trim($row['image'] ?? '');
$prodName  = trim($row['name'] ?? '');

// 1. Delete product image file
if (!empty($prodImage)) {
    cloudinary_delete_image($prodImage);
}

// 2. Also find and delete image for linked stock items (only image, keep the stock item)
$stockItemIds = [];

// Check via recipe link
$recSel = $conn->prepare("SELECT item_id FROM product_recipes WHERE product_id = ?");
if ($recSel) {
    $recSel->bind_param("i", $id);
    $recSel->execute();
    $rRes = $recSel->get_result();
    while ($rRow = $rRes->fetch_assoc()) {
        if (!empty($rRow['item_id'])) {
            $stockItemIds[] = (int)$rRow['item_id'];
        }
    }
}

// Check via product name / direct drink or matching image
$cleanName = preg_replace('/\((?:Box|កេស|កេសធំ|កាតុង|Carton|Case|Pack|យួរ|Package|កញ្ចប់|Dozen|ឡូ|Crate|ស្នោ)\)/ui', '', $prodName);
$cleanName = trim(preg_replace('/\b(?:Box|Carton|Case|Pack|Package|Dozen|Crate)\b/ui', '', $cleanName));

$stkSel = $conn->prepare("SELECT item_id, image, image_box FROM stock_items WHERE LOWER(REPLACE(item_name, ' ', '')) = LOWER(REPLACE(?, ' ', '')) OR LOWER(REPLACE(item_name, ' ', '')) = LOWER(REPLACE(?, ' ', '')) OR (image IS NOT NULL AND image != '' AND image = ?) OR (image_box IS NOT NULL AND image_box != '' AND image_box = ?)");
if ($stkSel) {
    $stkSel->bind_param("ssss", $prodName, $cleanName, $prodImage, $prodImage);
    $stkSel->execute();
    $sRes = $stkSel->get_result();
    while ($sRow = $sRes->fetch_assoc()) {
        $stockItemIds[] = (int)$sRow['item_id'];
    }
}

$stockItemIds = array_values(array_unique(array_filter($stockItemIds)));

$isBox = preg_match('/\((?:Box|កេស|កេសធំ|កាតុង|Carton|Case|Pack|យួរ|Package|កញ្ចប់|Dozen|ឡូ|Crate|ស្នោ)\)/ui', $prodName) ||
         preg_match('/\b(?:Box|Carton|Case|Pack|Package|Dozen|Crate)\b/ui', $prodName) ||
         preg_match('/(?:កេស|កាតុង|យួរ|កញ្ចប់|ឡូ|ស្នោ)/u', $prodName);

if (!empty($stockItemIds)) {
    $ph = implode(',', array_fill(0, count($stockItemIds), '?'));
    $types = str_repeat('i', count($stockItemIds));
    
    $fetchImages = $conn->prepare("SELECT item_id, image, image_box FROM stock_items WHERE item_id IN ($ph)");
    $fetchImages->bind_param($types, ...$stockItemIds);
    $fetchImages->execute();
    $itemsWithImages = $fetchImages->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($itemsWithImages as $it) {
        $sId = (int)$it['item_id'];
        if ($isBox) {
            // Deleting Box Product -> only clear image_box in stock_items
            if (!empty($it['image_box'])) {
                cloudinary_delete_image($it['image_box']);
            }
            $conn->query("UPDATE stock_items SET image_box = NULL WHERE item_id = {$sId}");
        } else {
            // Deleting Unit Product -> only clear unit image in stock_items
            if (!empty($it['image'])) {
                cloudinary_delete_image($it['image']);
            }
            $conn->query("UPDATE stock_items SET image = NULL WHERE item_id = {$sId}");
        }
    }
}

// 3. Clean recipes and delete product
$conn->query("DELETE FROM product_recipes WHERE product_id = " . (int)$id);

$del = $conn->prepare("DELETE FROM products WHERE product_id = ?");
$del->bind_param("i", $id);
$del->execute();

echo json_encode(['ok' => $del->affected_rows > 0]);
