<?php
require 'admin_only.php';
require 'config.php';
header('Content-Type: application/json');

$id = (int)($_POST['product_id'] ?? 0);
if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid ID']); exit; }

$sel = $conn->prepare("SELECT image FROM products WHERE product_id = ?");
$sel->bind_param("i", $id);
$sel->execute();
$row = $sel->get_result()->fetch_assoc();

if (!$row) { echo json_encode(['ok' => false, 'error' => 'Product not found']); exit; }

if (!empty($row['image'])) {
    cloudinary_delete_image($row['image']);
}



$del = $conn->prepare("DELETE FROM products WHERE product_id = ?");
$del->bind_param("i", $id);
$del->execute();

echo json_encode(['ok' => $del->affected_rows > 0]);
