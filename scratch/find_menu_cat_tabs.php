<?php
$lines = file(__DIR__ . '/../menu.php');
foreach ($lines as $i => $l) {
    if (preg_match('/category|cat-tab|cat-btn|Iced Beverages|Soft Drink/i', $l)) {
        if (strlen($l) < 250 && strpos($l, 'svg') === false) {
            echo ($i + 1) . ': ' . trim($l) . "\n";
        }
    }
}
