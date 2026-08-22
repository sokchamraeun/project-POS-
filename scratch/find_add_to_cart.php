<?php
$lines = file(__DIR__ . '/../menu.php');
foreach ($lines as $i => $l) {
    if (preg_match('/addToCart\s*=/i', $l) || preg_match('/function\s+addToCart/i', $l)) {
        echo ($i + 1) . ': ' . trim($l) . "\n";
    }
}
