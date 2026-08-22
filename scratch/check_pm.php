<?php
require_once __DIR__ . '/../config.php';
$res = $conn->query("SELECT DISTINCT payment_method, COUNT(*) as c FROM orders GROUP BY payment_method");
while ($r = $res->fetch_assoc()) {
    echo $r['payment_method'] . ': ' . $r['c'] . "\n";
}
