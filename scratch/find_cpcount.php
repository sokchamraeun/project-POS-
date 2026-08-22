<?php
$lines = file(__DIR__ . '/../menu.php');
foreach ($lines as $i => $l) {
    if (stripos($l, 'cpCount') !== false) {
        echo ($i + 1) . ': ' . trim($l) . "\n";
    }
}
