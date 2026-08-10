<?php
require 'admin_only.php';
require 'config.php';

$id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
$is_ajax = isset($_GET['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

if ($id > 0) {
    try {
        $conn->query("SET FOREIGN_KEY_CHECKS=0");
        $r = $conn->query("SELECT user_id FROM employees WHERE employee_id = $id");
        $uid = ($r && $row = $r->fetch_assoc()) ? (int)($row['user_id'] ?? 0) : 0;

        $conn->query("DELETE FROM employees WHERE employee_id = $id");
        if ($uid > 0) {
            try { $conn->query("DELETE FROM user_permissions WHERE user_id = $uid"); } catch (Throwable $t) {}
            $conn->query("DELETE FROM users WHERE user_id = $uid");
        }
        $conn->query("SET FOREIGN_KEY_CHECKS=1");

        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
    } catch (Exception $e) {
        $conn->query("SET FOREIGN_KEY_CHECKS=1");
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }
}

if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid ID']);
    exit;
}

header("Location: employees.php");
exit;