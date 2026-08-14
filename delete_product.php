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
    $path = $row['image'];
    if (!str_starts_with($path, 'uploads/')) $path = 'uploads/' . $path;
    if (file_exists($path)) unlink($path);
}

if ($conn->query("SHOW TABLES LIKE 'product_ingredients'")->num_rows > 0) {
    $del_pi = $conn->prepare("DELETE FROM product_ingredients WHERE product_id = ?");
    $del_pi->bind_param("i", $id);
    $del_pi->execute();
}

$del = $conn->prepare("DELETE FROM products WHERE product_id = ?");
$del->bind_param("i", $id);
$del->execute();

echo json_encode(['ok' => $del->affected_rows > 0]);
