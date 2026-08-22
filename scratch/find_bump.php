<?php
$lines = file(__DIR__ . '/../menu.php');
foreach ($lines as $i => $l) {
    if (stripos($l, 'cart-badge-bump') !== false) {
        echo ($i + 1) . ': ' . trim($l) . "\n";
    }
}
