<?php
$lines = file(__DIR__ . '/../cart.php');
foreach ($lines as $i => $l) {
    if (preg_match('/\$_POST\[/i', $l)) {
        echo ($i + 1) . ': ' . trim($l) . "\n";
    }
}
