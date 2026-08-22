<?php
$lines = file(__DIR__ . '/../menu.php');
foreach ($lines as $i => $l) {
    if (preg_match('/\.cp-title|\.cp-count|\.cp-header/i', $l)) {
        echo ($i + 1) . ': ' . trim($l) . "\n";
    }
}
