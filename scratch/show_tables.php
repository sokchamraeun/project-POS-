<?php
require_once __DIR__ . '/../config.php';
$r = $conn->query("SHOW TABLES");
while ($row = $r->fetch_row()) {
    echo $row[0] . "\n";
}
