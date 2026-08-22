<?php
require 'config.php';
$p = password_hash('123456', PASSWORD_DEFAULT);
$conn->query("UPDATE users SET password='$p' WHERE username='visal'");
echo "visal password set to 123456\n";
