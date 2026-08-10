<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$lang = trim($_GET['lang'] ?? $_POST['lang'] ?? '');
if (in_array($lang, ['en', 'km'], true)) {
    $_SESSION['lang'] = $lang;
    setcookie('lang', $lang, time() + (86400 * 30), '/'); // 30 days
}

// If AJAX request, return JSON
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'lang' => $_SESSION['lang']]);
    exit;
}

// Redirect back to referring page or dashboard
$referer = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
header("Location: " . $referer);
exit;
?>
