<?php
$lines = file(__DIR__ . '/../menu.php');
foreach ($lines as $i => $l) {
    if (preg_match('/isKm|lang|current_lang|item_plural/i', $l)) {
        echo ($i + 1) . ': ' . trim($l) . "\n";
    }
}
