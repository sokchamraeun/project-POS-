<?php
require 'admin_only.php';
require 'config.php';
header('Content-Type: application/json');

$currentUserId = (int)$_SESSION['user_id'];
$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid ID.']); exit;
}
if ($id === $currentUserId) {
    echo json_encode(['ok' => false, 'error' => 'You cannot delete your own account.']); exit;
}

// Verify target user exists
$chk = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? LIMIT 1");
$chk->bind_param('i', $id);
$chk->execute();
$chk->store_result();
if ($chk->num_rows === 0) {
    echo json_encode(['ok' => false, 'error' => 'User not found.']); exit;
}

try {
    // Disable foreign key checks to prevent constraint failures
    $conn->query("SET FOREIGN_KEY_CHECKS=0");

    // Unlink employee if linked
    $unl = $conn->prepare("UPDATE employees SET user_id = NULL WHERE user_id = ?");
    $unl->bind_param('i', $id);
    $unl->execute();

    // Delete user permissions if table exists
    try {
        $del_perm = $conn->prepare("DELETE FROM user_permissions WHERE user_id = ?");
        if ($del_perm) {
            $del_perm->bind_param('i', $id);
            $del_perm->execute();
        }
    } catch (Throwable $pe) {
        // Table user_permissions does not exist, ignore
    }

    // Delete user
    $del = $conn->prepare("DELETE FROM users WHERE user_id = ?");
    $del->bind_param('i', $id);
    $success = $del->execute() && $del->affected_rows > 0;

    $conn->query("SET FOREIGN_KEY_CHECKS=1");

    if ($success) {
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Delete failed.']);
    }
} catch (Exception $e) {
    $conn->query("SET FOREIGN_KEY_CHECKS=1");
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
