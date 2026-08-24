<?php
require 'config.php';
$o80 = $conn->query("SELECT COUNT(*) FROM order_items WHERE product_id=80")->fetch_row()[0];
$o68 = $conn->query("SELECT COUNT(*) FROM order_items WHERE product_id=68")->fetch_row()[0];
echo "Orders for Product 80 (Bachas): $o80\n";
echo "Orders for Product 68 (Bacchus): $o68\n";
