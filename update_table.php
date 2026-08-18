<?php
require 'auth.php';
require 'config.php';
header('Content-Type: application/json');

echo json_encode(['ok' => true, 'table_number' => '']);
exit;
