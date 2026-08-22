<?php
$lines = file(__DIR__ . '/../lang.php');
foreach ($lines as $i => $l) {
    if (preg_match('/cart|item_plural|item_single|កន្ត្រក/u', $l)) {
        echo ($i + 1) . ': ' . trim($l) . "\n";
    }
}
