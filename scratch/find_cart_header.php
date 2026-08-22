<?php
$lines = file(__DIR__ . '/../menu.php');
foreach ($lines as $i => $l) {
    if (preg_match('/cart|order|badge|កន្ត្រក/i', $l) && preg_match('/header|title|h2|h3|h4|span|icon|fa-|badge/i', $l)) {
        if ($i > 1000 && $i < 3000) {
            echo ($i + 1) . ': ' . trim($l) . "\n";
        }
    }
}
