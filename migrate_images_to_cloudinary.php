<?php
/**
 * Cloudinary Migration Script
 * Migrates local images from `uploads/` to Cloudinary and updates database URLs.
 */

if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    require_once __DIR__ . '/admin_only.php';
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cloudinary_config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "========================================\n";
echo " POS COFFEE - CLOUDINARY IMAGE MIGRATION\n";
echo "========================================\n\n";

$isCli = (php_sapi_name() === 'cli');

// 1. PRODUCTS
echo "1. Migrating Products...\n";
$prodRes = $conn->query("SELECT product_id, name, image FROM products WHERE image IS NOT NULL AND image != ''");
$prodCount = 0;
$prodSuccess = 0;
$prodSkipped = 0;
$prodFailed = 0;

if ($prodRes) {
    while ($row = $prodRes->fetch_assoc()) {
        $id    = (int)$row['product_id'];
        $name  = $row['name'];
        $image = trim($row['image']);

        // Check if already Cloudinary or remote URL
        if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://') || str_starts_with($image, '//')) {
            $prodSkipped++;
            continue;
        }

        $prodCount++;
        $localPath = $image;
        if (!file_exists($localPath)) {
            $localPath = __DIR__ . '/' . ltrim($image, '/');
        }
        if (!file_exists($localPath) && file_exists(__DIR__ . '/uploads/' . basename($image))) {
            $localPath = __DIR__ . '/uploads/' . basename($image);
        }

        if (!file_exists($localPath) || is_dir($localPath)) {
            echo "   [!] Product #$id ($name): Local image not found ($image)\n";
            $prodFailed++;
            continue;
        }

        echo "   -> Uploading Product #$id ($name) from $localPath ... ";
        $res = cloudinary_upload_file($localPath, 'pos_coffee/products');
        if ($res['success']) {
            $newUrl = $res['url'];
            $uStmt = $conn->prepare("UPDATE products SET image = ? WHERE product_id = ?");
            $uStmt->bind_param("si", $newUrl, $id);
            $uStmt->execute();
            $uStmt->close();
            echo "SUCCESS -> $newUrl\n";
            $prodSuccess++;
        } else {
            echo "FAILED: " . ($res['error'] ?? 'Unknown error') . "\n";
            $prodFailed++;
        }
    }
}
echo "Products Migration Finished: $prodSuccess uploaded, $prodSkipped already on cloud, $prodFailed failed.\n\n";

// 2. CATEGORIES
echo "2. Migrating Categories...\n";
$catRes = $conn->query("SELECT category_id, name, icon FROM categories WHERE icon IS NOT NULL AND icon != ''");
$catSuccess = 0;
$catSkipped = 0;
$catFailed = 0;

if ($catRes) {
    while ($row = $catRes->fetch_assoc()) {
        $id   = (int)$row['category_id'];
        $name = $row['name'];
        $icon = trim($row['icon']);

        // If it's a FontAwesome icon (e.g., fa-coffee, fa-circle) or remote URL, skip
        if (!str_contains($icon, '/') && !file_exists(__DIR__ . '/uploads/' . $icon)) {
            $catSkipped++;
            continue;
        }
        if (str_starts_with($icon, 'http://') || str_starts_with($icon, 'https://') || str_starts_with($icon, '//')) {
            $catSkipped++;
            continue;
        }

        $localPath = $icon;
        if (!file_exists($localPath)) {
            $localPath = __DIR__ . '/' . ltrim($icon, '/');
        }
        if (!file_exists($localPath) && file_exists(__DIR__ . '/uploads/' . basename($icon))) {
            $localPath = __DIR__ . '/uploads/' . basename($icon);
        }

        if (!file_exists($localPath) || is_dir($localPath)) {
            $catSkipped++;
            continue;
        }

        echo "   -> Uploading Category #$id ($name) ... ";
        $res = cloudinary_upload_file($localPath, 'pos_coffee/categories');
        if ($res['success']) {
            $newUrl = $res['url'];
            $uStmt = $conn->prepare("UPDATE categories SET icon = ? WHERE category_id = ?");
            $uStmt->bind_param("si", $newUrl, $id);
            $uStmt->execute();
            $uStmt->close();
            echo "SUCCESS -> $newUrl\n";
            $catSuccess++;
        } else {
            echo "FAILED: " . ($res['error'] ?? 'Unknown error') . "\n";
            $catFailed++;
        }
    }
}
echo "Categories Migration Finished: $catSuccess uploaded, $catSkipped skipped, $catFailed failed.\n\n";

// 3. STOCK ITEMS
echo "3. Migrating Stock Items...\n";
$stockRes = $conn->query("SELECT item_id, item_name, image FROM stock_items WHERE image IS NOT NULL AND image != ''");
$stockSuccess = 0;
$stockSkipped = 0;
$stockFailed = 0;

if ($stockRes) {
    while ($row = $stockRes->fetch_assoc()) {
        $id    = (int)$row['item_id'];
        $name  = $row['item_name'];
        $image = trim($row['image']);

        if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://') || str_starts_with($image, '//')) {
            $stockSkipped++;
            continue;
        }

        $localPath = $image;
        if (!file_exists($localPath)) {
            $localPath = __DIR__ . '/' . ltrim($image, '/');
        }
        if (!file_exists($localPath) && file_exists(__DIR__ . '/uploads/' . basename($image))) {
            $localPath = __DIR__ . '/uploads/' . basename($image);
        }

        if (!file_exists($localPath) || is_dir($localPath)) {
            $stockSkipped++;
            continue;
        }

        echo "   -> Uploading Stock Item #$id ($name) ... ";
        $res = cloudinary_upload_file($localPath, 'pos_coffee/stock');
        if ($res['success']) {
            $newUrl = $res['url'];
            $uStmt = $conn->prepare("UPDATE stock_items SET image = ? WHERE item_id = ?");
            $uStmt->bind_param("si", $newUrl, $id);
            $uStmt->execute();
            $uStmt->close();
            echo "SUCCESS -> $newUrl\n";
            $stockSuccess++;
        } else {
            echo "FAILED: " . ($res['error'] ?? 'Unknown error') . "\n";
            $stockFailed++;
        }
    }
}
echo "Stock Items Migration Finished: $stockSuccess uploaded, $stockSkipped skipped, $stockFailed failed.\n\n";

echo "========================================\n";
echo " MIGRATION COMPLETE\n";
echo "========================================\n";
