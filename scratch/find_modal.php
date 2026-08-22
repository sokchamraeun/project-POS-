<?php
$lines = file(__DIR__ . '/../menu.php');
foreach ($lines as $i => $l) {
    if (preg_match('/id="modal"|id="productModal"|id="editItemModal"|class="modal|openModal|openCartItemEditModal/i', $l)) {
        echo ($i + 1) . ': ' . trim($l) . "\n";
    }
}
