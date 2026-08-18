<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// ── Session timeout: 30 minutes idle ──
$timeout = 30 * 60;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_unset();
    session_destroy();
    header("Location: login.php?timeout=1");
    exit;
}
$_SESSION['last_activity'] = time();

// ── Role check: admin or manager only ──
$stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user || !in_array($user['role'], ['admin', 'manager'])) {
    header("Location: dashboard.php?denied=1");
    exit;
}
?>
