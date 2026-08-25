<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lang.php';

// Only Admin and Manager are permitted to access user management
$_role = $_SESSION['role'] ?? 'staff';
if (!in_array($_role, ['admin', 'manager'], true)) {
    header("Location: dashboard.php?denied=1");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function he($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$flash = null;
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// ── ACTION ROUTER ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid security token. Please try again.'];
        header("Location: users.php");
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username  = trim((string)($_POST['username'] ?? ''));
        $name      = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            $name = $username;
        }
        $email     = trim((string)($_POST['email'] ?? ''));
        $password  = (string)($_POST['password'] ?? '');
        $user_role = strtolower(trim((string)($_POST['role'] ?? 'staff')));
        $is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

        $valid_roles = ['admin', 'manager', 'staff', 'barista'];
        if (!in_array($user_role, $valid_roles, true)) {
            $user_role = 'staff';
        }

        if ($user_role === 'admin' && $_role !== 'admin') {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Only Administrators can create Admin accounts.'];
            header("Location: users.php");
            exit;
        }

        if (strlen($username) < 2 || strlen($username) > 50) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Username must be between 2 and 50 characters.'];
            header("Location: users.php");
            exit;
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Please provide a valid email address (e.g. name@example.com).'];
            header("Location: users.php");
            exit;
        }

        if (strlen($password) < 4) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Password must be at least 4 characters long.'];
            header("Location: users.php");
            exit;
        }

        $chk = $conn->prepare("SELECT user_id FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1");
        $chk->bind_param("s", $username);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Username "' . he($username) . '" already exists.'];
            header("Location: users.php");
            exit;
        }

        $chk_email = $conn->prepare("SELECT user_id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $chk_email->bind_param("s", $email);
        $chk_email->execute();
        if ($chk_email->get_result()->fetch_assoc()) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Email "' . he($email) . '" is already registered to another user.'];
            header("Location: users.php");
            exit;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $ins = $conn->prepare("INSERT INTO users (username, name, email, password, role, is_active) VALUES (?, ?, ?, ?, ?, ?)");
        $ins->bind_param("sssssi", $username, $name, $email, $hash, $user_role, $is_active);
        if ($ins->execute()) {
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'User "' . he($name) . '" created successfully.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Failed to create user: ' . $conn->error];
        }
        header("Location: users.php");
        exit;
    }

    if ($action === 'update') {
        $target_id = (int)($_POST['user_id'] ?? 0);
        $username  = trim((string)($_POST['username'] ?? ''));
        $name      = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            $name = $username;
        }
        $email     = trim((string)($_POST['email'] ?? ''));
        $password  = (string)($_POST['password'] ?? '');
        $is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

        if ($target_id <= 0) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid user selected.'];
            header("Location: users.php");
            exit;
        }

        $cur = $conn->prepare("SELECT user_id, username, role FROM users WHERE user_id = ? LIMIT 1");
        $cur->bind_param("i", $target_id);
        $cur->execute();
        $target_user = $cur->get_result()->fetch_assoc();

        if (!$target_user) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'User not found.'];
            header("Location: users.php");
            exit;
        }

        $user_role = isset($_POST['role']) ? strtolower(trim((string)$_POST['role'])) : strtolower($target_user['role'] ?? 'staff');
        $valid_roles = ['admin', 'manager', 'staff', 'barista'];
        if (!in_array($user_role, $valid_roles, true)) {
            $user_role = strtolower($target_user['role'] ?? 'staff');
        }

        if ($target_user['role'] === 'admin' && $_role !== 'admin') {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Only Administrators can edit Admin accounts.'];
            header("Location: users.php");
            exit;
        }

        if ($user_role === 'admin' && $_role !== 'admin') {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Only Administrators can assign the Admin role.'];
            header("Location: users.php");
            exit;
        }

        if (strlen($username) < 2 || strlen($username) > 50) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Username must be between 2 and 50 characters.'];
            header("Location: users.php");
            exit;
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Please provide a valid email address.'];
            header("Location: users.php");
            exit;
        }

        $chk = $conn->prepare("SELECT user_id FROM users WHERE LOWER(username) = LOWER(?) AND user_id != ? LIMIT 1");
        $chk->bind_param("si", $username, $target_id);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Username "' . he($username) . '" is taken by another account.'];
            header("Location: users.php");
            exit;
        }

        if ($email !== '') {
            $chk_email = $conn->prepare("SELECT user_id FROM users WHERE LOWER(email) = LOWER(?) AND user_id != ? LIMIT 1");
            $chk_email->bind_param("si", $email, $target_id);
            $chk_email->execute();
            if ($chk_email->get_result()->fetch_assoc()) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Email "' . he($email) . '" is already registered to another user.'];
                header("Location: users.php");
                exit;
            }
        }

        if (!empty($password)) {
            if (strlen($password) < 4) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'New password must be at least 4 characters long.'];
                header("Location: users.php");
                exit;
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $upd = $conn->prepare("UPDATE users SET username = ?, name = ?, email = ?, role = ?, is_active = ?, password = ? WHERE user_id = ?");
            $upd->bind_param("ssssisi", $username, $name, $email, $user_role, $is_active, $hash, $target_id);
        } else {
            $upd = $conn->prepare("UPDATE users SET username = ?, name = ?, email = ?, role = ?, is_active = ? WHERE user_id = ?");
            $upd->bind_param("ssssii", $username, $name, $email, $user_role, $is_active, $target_id);
        }

        if ($upd->execute()) {
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'User "' . he($name) . '" updated successfully.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Failed to update user: ' . $conn->error];
        }
        header("Location: users.php");
        exit;
    }

    if ($action === 'delete') {
        $target_id = (int)($_POST['user_id'] ?? 0);

        if ($target_id <= 0) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid user ID.'];
            header("Location: users.php");
            exit;
        }

        if ($target_id === (int)$_SESSION['user_id']) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'You cannot delete your own logged-in account!'];
            header("Location: users.php");
            exit;
        }

        $cur = $conn->prepare("SELECT username, name, role FROM users WHERE user_id = ? LIMIT 1");
        $cur->bind_param("i", $target_id);
        $cur->execute();
        $target_user = $cur->get_result()->fetch_assoc();

        if (!$target_user) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'User not found.'];
            header("Location: users.php");
            exit;
        }

        if ($target_user['role'] === 'admin' && $_role !== 'admin') {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Only Administrators can delete Admin accounts.'];
            header("Location: users.php");
            exit;
        }

        $del = $conn->prepare("DELETE FROM users WHERE user_id = ?");
        $del->bind_param("i", $target_id);
        if ($del->execute()) {
            $disp_name = $target_user['name'] ?: $target_user['username'];
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'User "' . he($disp_name) . '" deleted.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Failed to delete user: ' . $conn->error];
        }
        header("Location: users.php");
        exit;
    }
}

// ── AUTO-MIGRATE USERS SCHEMA IF NEEDED ON HOSTING ──
try {
    @$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS name VARCHAR(100) NULL DEFAULT NULL");
    @$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(255) NULL DEFAULT NULL");
    @$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1");
    @$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS role VARCHAR(50) NOT NULL DEFAULT 'staff'");
    @$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0");
} catch (Throwable $e) {}

// ── FETCH USERS LIST RESILIENTLY ──
$users = [];
$users_result = @$conn->query("SELECT * FROM users ORDER BY user_id ASC");
if ($users_result) {
    while ($row = $users_result->fetch_assoc()) {
        $uName = (string)($row['username'] ?? 'user');
        $dName = !empty($row['name']) ? (string)$row['name'] : $uName;
        $users[] = [
            'user_id'   => (int)($row['user_id'] ?? 0),
            'username'  => $uName,
            'name'      => $dName,
            'email'     => (string)($row['email'] ?? ''),
            'role'      => (string)($row['role'] ?? 'staff'),
            'is_active' => isset($row['is_active']) ? (int)$row['is_active'] : 1
        ];
    }
}

$role_meta = [
    'admin'   => ['label' => 'Admin',   'color' => '#ef4444', 'bg' => 'rgba(239, 68, 68, 0.15)',  'icon' => 'fa-user-shield'],
    'manager' => ['label' => 'Manager', 'color' => '#3b82f6', 'bg' => 'rgba(59, 130, 246, 0.15)', 'icon' => 'fa-user-tie'],
    'staff'   => ['label' => 'Cashier', 'color' => '#10b981', 'bg' => 'rgba(16, 185, 129, 0.15)', 'icon' => 'fa-user'],
    'barista' => ['label' => 'Barista', 'color' => '#f59e0b', 'bg' => 'rgba(245, 158, 11, 0.15)', 'icon' => 'fa-mug-hot'],
];

// Calculate 3 main counts: Total Users, Active, Inactive
$count_all      = count($users);
$count_active   = 0;
$count_inactive = 0;

foreach ($users as $u) {
    if ((int)($u['is_active'] ?? 1) === 1) {
        $count_active++;
    } else {
        $count_inactive++;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>" data-lang="<?= current_lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script>(function(){try{if(localStorage.getItem("theme")==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
<script src="https://cdn.tailwindcss.com"></script>

<style>
@import url('https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap');

:root {
    --accent: #10b981;
    --accent-dark: #059669;
    --bg: #0c0c0c;
    --bg-card: #121212;
    --border: #1f1f1f;
    --text: #f5f5f5;
    --text-muted: #888888;
}

[data-theme="light"], html[data-theme="light"] {
    --bg: #f8fafc;
    --bg-card: #FFFFFF;
    --border: #E2E8F0;
    --text: #0f172a;
    --text-muted: #64748b;
}

body, input, select, textarea, button, .modal-content, table {
    font-family: 'Poppins', 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

body {
    background-color: var(--bg);
    color: var(--text);
    margin: 0;
    padding: 0;
    min-height: 100vh;
}

:lang(km), [data-lang="km"], html[lang="km"], html[lang="km"] * {
    font-family: 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', 'Khmer OS Siemreap', 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
}
html[lang="km"] .fa, html[lang="km"] [class*="fa-"], html[lang="km"] i {
    font-family: 'Font Awesome 6 Free', 'FontAwesome' !important;
}
html[lang="km"] .fa-brands, html[lang="km"] [class*="fa-brands"] {
    font-family: 'Font Awesome 6 Brands', 'FontAwesome' !important;
}

body,
.app-layout,
.app-main,
[data-theme="light"] body,
[data-theme="light"] .app-layout,
[data-theme="light"] .app-main {
    background-color: #f8fafc !important;
    color: #0f172a !important;
}

.um-wrapper {
    padding: 0 24px 24px;
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box;
    flex: 1;
}

.um-stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 20px;
    width: 100% !important;
}

.um-stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
}

[data-theme="light"] .um-stat-card {
    background: #FFFFFF !important;
    border-color: #E2E5EA !important;
    box-shadow: 0 2px 10px rgba(0,0,0,0.04);
}

.um-stat-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.um-stat-value {
    font-size: 22px;
    font-weight: 800;
    color: var(--text);
    line-height: 1.2;
}

[data-theme="light"] .um-stat-value {
    color: #111827 !important;
}

.um-stat-label {
    font-size: 12px;
    color: var(--text-muted);
    font-weight: 600;
    margin-top: 2px;
}

[data-theme="light"] .um-stat-label {
    color: #5A6373 !important;
}

.um-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 16px;
    width: 100% !important;
}

.um-search-box {
    position: relative;
    flex: 1;
    max-width: 420px;
}

.um-search-box i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    font-size: 14px;
}

.um-search-box input {
    width: 100%;
    padding: 10px 14px 10px 38px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: var(--bg-card);
    color: var(--text);
    font-size: 13.5px;
    outline: none;
    transition: all 0.2s ease;
}

[data-theme="light"] .um-search-box input {
    background: #FFFFFF !important;
    border-color: #CDD0D8 !important;
    color: #111827 !important;
}

.um-search-box input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15);
}

.btn-add-user {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 10px;
    background: #10b981;
    color: #ffffff;
    font-weight: 700;
    font-size: 13.5px;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 4px 14px rgba(16, 185, 129, 0.28);
}

.btn-add-user:hover {
    background: #059669;
    color: #ffffff;
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(16, 185, 129, 0.38);
}

.um-table-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
}

[data-theme="light"] .um-table-card {
    background: #FFFFFF !important;
    border-color: #E2E5EA !important;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
}

.um-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
    font-size: 13.5px;
}

.um-table th {
    padding: 16px 24px;
    font-size: 11.5px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #64748b;
    background: #16161a;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    font-weight: 700;
}

[data-theme="light"] .um-table th {
    background: #F8FAFC !important;
    color: #64748b !important;
    border-bottom-color: #E2E5EA !important;
}

.um-table td {
    padding: 16px 24px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    color: var(--text);
    vertical-align: middle;
}

[data-theme="light"] .um-table td {
    border-bottom-color: #F1F5F9 !important;
    color: #111827 !important;
}

.um-table tr:hover {
    background: rgba(255, 255, 255, 0.02);
}

[data-theme="light"] .um-table tr:hover {
    background: #F8FAFC !important;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 50px;
    font-size: 11.5px;
    font-weight: 600;
}

.status-badge.active {
    background: rgba(16, 185, 129, 0.15);
    color: #34d399;
    border: 1px solid rgba(16, 185, 129, 0.3);
}

.status-badge.inactive {
    background: rgba(239, 68, 68, 0.15);
    color: #f87171;
    border: 1px solid rgba(239, 68, 68, 0.3);
}

.btn-action-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    background: rgba(255, 255, 255, 0.04);
    color: var(--text-muted);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
}

[data-theme="light"] .btn-action-icon {
    background: #F1F5F9 !important;
    border-color: #E2E5EA !important;
    color: #64748B !important;
}

.btn-action-icon:hover {
    color: #fff;
    background: rgba(255, 255, 255, 0.1);
}

.btn-action-edit:hover {
    border-color: #3b82f6 !important;
    color: #60a5fa !important;
}

.btn-action-delete:hover {
    border-color: #ef4444 !important;
    color: #f87171 !important;
}

.um-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.75);
    backdrop-filter: blur(8px);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.25s ease;
}

.um-modal-overlay.active {
    opacity: 1;
    pointer-events: auto;
}

.um-modal-content {
    background: #141417;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 20px;
    width: 100%;
    max-width: 460px;
    padding: 28px;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.6);
    transform: scale(0.95) translateY(10px);
    transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}

[data-theme="light"] .um-modal-content {
    background: #FFFFFF !important;
    border-color: #E2E8F0 !important;
    color: #111827 !important;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.1) !important;
}

.um-modal-overlay.active .um-modal-content {
    transform: scale(1) translateY(0);
}

.um-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}

.um-modal-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 8px;
}

.um-modal-close {
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: 18px;
    cursor: pointer;
    padding: 4px;
}

.um-modal-close:hover {
    color: var(--text);
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.form-group input, .form-group select {
    width: 100%;
    padding: 10px 14px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: rgba(255, 255, 255, 0.04);
    color: var(--text);
    font-size: 13.5px;
    outline: none;
    transition: all 0.2s ease;
}

[data-theme="light"] .form-group input, [data-theme="light"] .form-group select {
    background: #F8FAFC !important;
    border-color: #CBD5E1 !important;
    color: #0F172A !important;
}

.form-group input:focus, .form-group select:focus {
    border-color: var(--accent);
}

.modal-actions {
    display: flex;
    gap: 10px;
    margin-top: 24px;
}

.btn-submit {
    flex: 1;
    padding: 12px;
    border-radius: 10px;
    border: none;
    background: #10b981;
    color: #ffffff;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.2s ease;
}

.btn-submit:hover {
    background: #059669;
}

.btn-cancel {
    padding: 12px 20px;
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    background: rgba(255, 255, 255, 0.05);
    color: var(--text);
    font-weight: 600;
    cursor: pointer;
}

[data-theme="light"] .btn-cancel {
    background: #F1F5F9 !important;
    border-color: #E2E5EA !important;
    color: #475569 !important;
}
</style>
</head>
<body>

<div class="app-layout">
    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="app-main flex-1 flex flex-col min-w-0 overflow-y-auto">
        <div class="flex items-center justify-between gap-4 px-6 pt-6 pb-2">
            <h1 class="text-xl md:text-2xl font-black text-slate-900 tracking-tight">
                <?= __('nav_user_management', 'User Management') ?>
            </h1>
        </div>

        <div class="um-wrapper">

            <!-- Flash Alert -->
            <?php if ($flash): ?>
            <div style="margin-bottom: 20px; padding: 14px 18px; border-radius: 12px; background: <?= $flash['type'] === 'success' ? 'rgba(16, 185, 129, 0.15)' : 'rgba(239, 68, 68, 0.15)' ?>; border: 1px solid <?= $flash['type'] === 'success' ? '#10b981' : '#ef4444' ?>; color: <?= $flash['type'] === 'success' ? '#34d399' : '#f87171' ?>; font-weight: 600; font-size: 13.5px; display: flex; align-items: center; justify-content: space-between;">
                <span><i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i> <?= he($flash['msg']) ?></span>
                <button onclick="this.parentElement.remove()" style="background:none;border:none;color:inherit;cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <?php endif; ?>

            <!-- Stat Cards Grid -->
            <div class="um-stats-grid">
                <div class="um-stat-card">
                    <div class="um-stat-icon" style="background: rgba(16, 185, 129, 0.15); color: #10b981;">
                        <i class="fa-solid fa-users"></i>
                    </div>
                    <div>
                        <div class="um-stat-value"><?= $count_all ?></div>
                        <div class="um-stat-label">Total Users</div>
                    </div>
                </div>

                <div class="um-stat-card">
                    <div class="um-stat-icon" style="background: rgba(16, 185, 129, 0.15); color: #34d399;">
                        <i class="fa-solid fa-user-check"></i>
                    </div>
                    <div>
                        <div class="um-stat-value"><?= $count_active ?></div>
                        <div class="um-stat-label">Active Users</div>
                    </div>
                </div>

                <div class="um-stat-card">
                    <div class="um-stat-icon" style="background: rgba(239, 68, 68, 0.15); color: #ef4444;">
                        <i class="fa-solid fa-user-xmark"></i>
                    </div>
                    <div>
                        <div class="um-stat-value"><?= $count_inactive ?></div>
                        <div class="um-stat-label">Inactive Users</div>
                    </div>
                </div>
            </div>

            <!-- Toolbar (Search & Add User Button) -->
            <div class="um-toolbar">
                <div class="um-search-box">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="userSearchInput" placeholder="Search by username, name, email, or status..." oninput="filterUserTable()">
                </div>

                <button type="button" class="btn-add-user" onclick="openAddModal()">
                    <i class="fa-solid fa-user-plus"></i> Add New User
                </button>
            </div>

            <!-- Users Table Card -->
            <div class="um-table-card">
                <table class="um-table" id="usersTable">
                    <thead>
                        <tr>
                            <th style="width: 8%;">ID</th>
                            <th class="col-username" style="width: 20%;">Username</th>
                            <th style="width: 24%;">Name</th>
                            <th style="width: 26%;">Email</th>
                            <th style="width: 12%;">Status</th>
                            <th style="width: 10%; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">
                                No users found in database.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($users as $u):
                            $rKey = strtolower($u['role'] ?? 'staff');
                            $rMeta = $role_meta[$rKey] ?? ['label' => ucfirst($rKey), 'color' => '#a0a0ab', 'bg' => 'rgba(255,255,255,0.08)', 'icon' => 'fa-user'];
                            $isActive = (int)($u['is_active'] ?? 1) === 1;
                            $displayName = $u['name'] ?: $u['username'];
                            $userEmail = $u['email'] ?: '—';
                        ?>
                        <tr data-username="<?= he(strtolower($u['username'])) ?>" data-name="<?= he(strtolower($displayName)) ?>" data-email="<?= he(strtolower($u['email'])) ?>" data-role="<?= he(strtolower($rMeta['label'])) ?>" data-status="<?= $isActive ? 'active' : 'inactive' ?>">
                            <td style="font-weight: 700; color: var(--accent);">#<?= sprintf('%03d', $u['user_id']) ?></td>
                            <td class="col-username">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div style="font-weight: 700;"><?= he($u['username']) ?></div>
                                    <?php if ((int)$u['user_id'] === (int)$_SESSION['user_id']): ?>
                                    <span style="font-size: 10px; color: #10b981; background: rgba(16,185,129,0.15); padding: 1px 6px; border-radius: 4px; font-weight: 700;">You</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <div style="font-weight: 600; color: var(--text);"><?= he($displayName) ?></div>
                                </div>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 6px; color: var(--text-muted); font-size: 13px;">
                                    <i class="fa-regular fa-envelope" style="color: #64748b; font-size: 12px;"></i>
                                    <span><?= he($userEmail) ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge <?= $isActive ? 'active' : 'inactive' ?>">
                                    <i class="fa-solid <?= $isActive ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
                                    <?= $isActive ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <div style="display: inline-flex; align-items: center; justify-content: flex-end; gap: 4px; flex-wrap: nowrap; white-space: nowrap;">
                                    <button type="button" class="btn-action-icon btn-action-edit" title="Edit User" onclick="openEditModal(<?= $u['user_id'] ?>, '<?= he($u['username']) ?>', '<?= he($u['name']) ?>', '<?= he($u['email']) ?>', <?= $isActive ? 1 : 0 ?>)">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                    <?php if ((int)$u['user_id'] !== (int)$_SESSION['user_id']): ?>
                                    <button type="button" class="btn-action-icon btn-action-delete" title="Delete User" onclick="openDeleteModal(<?= $u['user_id'] ?>, '<?= he($displayName) ?>')">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div><!-- /.um-wrapper -->
    </div><!-- /.app-main -->
</div><!-- /.app-layout -->

<!-- Add User Modal -->
<div class="um-modal-overlay" id="addUserModal">
    <div class="um-modal-content">
        <div class="um-modal-header">
            <h3 class="um-modal-title"><i class="fa-solid fa-user-plus" style="color: var(--accent);"></i> Add New User</h3>
            <button class="um-modal-close" onclick="closeModal('addUserModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="users.php">
            <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="create">

            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" placeholder="e.g. Visal, Cashier01" required autocomplete="off">
            </div>

            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="name" placeholder="e.g. Sok Visal" autocomplete="off">
            </div>

            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="e.g. staff@birdsnest.com" required autocomplete="off">
            </div>

            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="Enter password (min 4 chars)" required>
            </div>

            <div class="form-group">
                <label>Status</label>
                <select name="is_active" required>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>

            <div class="modal-actions">
                <button type="submit" class="btn-submit">Create User</button>
                <button type="button" class="btn-cancel" onclick="closeModal('addUserModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div class="um-modal-overlay" id="editUserModal">
    <div class="um-modal-content">
        <div class="um-modal-header">
            <h3 class="um-modal-title"><i class="fa-solid fa-pen-to-square" style="color: #60a5fa;"></i> Edit User</h3>
            <button class="um-modal-close" onclick="closeModal('editUserModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="users.php">
            <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="user_id" id="editUserId">

            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" id="editUsername" required autocomplete="off">
            </div>

            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="name" id="editName" placeholder="e.g. Sok Visal" autocomplete="off">
            </div>

            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" id="editEmail" placeholder="e.g. staff@birdsnest.com" required autocomplete="off">
            </div>

            <div class="form-group">
                <label>New Password (leave empty to keep current password)</label>
                <input type="password" name="password" placeholder="Optional password update">
            </div>

            <div class="form-group">
                <label>Account Status</label>
                <select name="is_active" id="editUserActive" required>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>

            <div class="modal-actions">
                <button type="submit" class="btn-submit">Save Changes</button>
                <button type="button" class="btn-cancel" onclick="closeModal('editUserModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete User Modal -->
<div class="um-modal-overlay" id="deleteUserModal">
    <div class="um-modal-content">
        <div class="um-modal-header">
            <h3 class="um-modal-title" style="color: #f87171;"><i class="fa-solid fa-trash-can"></i> Delete User</h3>
            <button class="um-modal-close" onclick="closeModal('deleteUserModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="users.php">
            <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" id="deleteUserId">

            <p style="color: var(--text-muted); margin-bottom: 20px; font-size: 14px;">
                Are you sure you want to delete user <strong id="deleteUsername"></strong>? This action cannot be undone.
            </p>

            <div class="modal-actions">
                <button type="submit" class="btn-submit" style="background: #ef4444; color: #fff;">Yes, Delete User</button>
                <button type="button" class="btn-cancel" onclick="closeModal('deleteUserModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('addUserModal').classList.add('active');
}

function openEditModal(id, username, name, email, isActive) {
    document.getElementById('editUserId').value = id;
    document.getElementById('editUsername').value = username;
    document.getElementById('editName').value = name;
    document.getElementById('editEmail').value = email || '';
    document.getElementById('editUserActive').value = isActive;
    document.getElementById('editUserModal').classList.add('active');
}

function openDeleteModal(id, username) {
    document.getElementById('deleteUserId').value = id;
    document.getElementById('deleteUsername').textContent = username;
    document.getElementById('deleteUserModal').classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

function filterUserTable() {
    const query = document.getElementById('userSearchInput').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#usersTable tbody tr');

    rows.forEach(row => {
        const username = row.dataset.username || '';
        const name = row.dataset.name || '';
        const email = row.dataset.email || '';
        const role = row.dataset.role || '';
        const status = row.dataset.status || '';
        if (username.includes(query) || name.includes(query) || email.includes(query) || role.includes(query) || status.includes(query)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}
</script>

</body>
</html>
