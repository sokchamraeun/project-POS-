<?php
$lines = file(__DIR__ . '/../menu.php');
foreach ($lines as $i => $l) {
    if (preg_match('/changeQty|onModalQtyInput|onModalQtyChange|onCartQtyInput|maxStock|max_stock|100/i', $l)) {
        if (strpos($l, 'svg') === false && strlen($l) < 300) {
            echo ($i + 1) . ': ' . trim($l) . "\n";
        }
    }
}
