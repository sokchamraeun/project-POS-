<?php
require 'admin_only.php';
require 'config.php';

$currentUserId = (int)$_SESSION['user_id'];

// ── AJAX: username availability check ──
if (isset($_GET['check_username'])) {
    header('Content-Type: application/json');
    $u = trim($_GET['check_username'] ?? '');
    if (strlen($u) < 3) { echo json_encode(['available' => false, 'reason' => 'too_short']); exit; }
    $s = $conn->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
    $s->bind_param('s', $u); $s->execute(); $s->store_result();
    echo json_encode(['available' => $s->num_rows === 0]);
    exit;
}

$message      = '';
$message_type = '';

// ── ADD USER ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $username    = trim($_POST['username'] ?? '');
    $password    = $_POST['password']         ?? '';
    $confirm     = $_POST['confirm_password'] ?? '';
    $role_slug   = trim($_POST['role_slug'] ?? 'admin');
    $employee_id = (int)($_POST['employee_id'] ?? 0);

    if ($username === '' || $password === '') {
        $message = 'All fields are required.'; $message_type = 'error';
    } elseif (strlen($username) < 3) {
        $message = 'Username must be at least 3 characters.'; $message_type = 'error';
    } elseif (strlen($password) < 6) {
        $message = 'Password must be at least 6 characters.'; $message_type = 'error';
    } elseif ($password !== $confirm) {
        $message = 'Passwords do not match.'; $message_type = 'error';
    } else {
        $chk = $conn->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
        $chk->bind_param('s', $username); $chk->execute(); $chk->store_result();
        if ($chk->num_rows > 0) {
            $message = 'Username already taken.'; $message_type = 'error';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $ins = $conn->prepare("INSERT INTO users (username, password, role_id) VALUES (?, ?, COALESCE((SELECT id FROM roles WHERE slug=?), 1))");
            $ins->bind_param('sss', $username, $hashed, $role_slug);
            if ($ins->execute()) {
                $new_uid = $conn->insert_id;
                if ($employee_id > 0) {
                    $upd_emp = $conn->prepare("UPDATE employees SET user_id = ? WHERE employee_id = ?");
                    $upd_emp->bind_param('ii', $new_uid, $employee_id);
                    $upd_emp->execute();
                }
                $message = "User \"$username\" created successfully."; $message_type = 'success';
            } else { $message = 'Database error. Please try again.'; $message_type = 'error'; }
        }
    }
}

// ── CHANGE PASSWORD ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $uid     = (int)($_POST['target_user_id'] ?? 0);
    $new_pw  = $_POST['new_password'] ?? '';
    $conf_pw = $_POST['confirm_new_password'] ?? '';

    if ($uid <= 0 || $new_pw === '') {
        $message = 'Password cannot be empty.'; $message_type = 'error';
    } elseif (strlen($new_pw) < 6) {
        $message = 'New password must be at least 6 characters.'; $message_type = 'error';
    } elseif ($new_pw !== $conf_pw) {
        $message = 'Passwords do not match.'; $message_type = 'error';
    } else {
        $hashed = password_hash($new_pw, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $upd->bind_param('si', $hashed, $uid);
        if ($upd->execute()) {
            $message = 'Password updated successfully.'; $message_type = 'success';
        } else {
            $message = 'Failed to update password.'; $message_type = 'error';
        }
    }
}

// ── EDIT USER ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_user') {
    $uid         = (int)($_POST['target_user_id'] ?? 0);
    $username    = trim($_POST['username'] ?? '');
    $role_slug   = trim($_POST['role_slug'] ?? 'staff');
    $employee_id = (int)($_POST['employee_id'] ?? 0);

    if ($uid <= 0 || $username === '') {
        $message = 'Username is required.'; $message_type = 'error';
    } elseif (strlen($username) < 3) {
        $message = 'Username must be at least 3 characters.'; $message_type = 'error';
    } else {
        $chk = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ? LIMIT 1");
        $chk->bind_param('si', $username, $uid); $chk->execute(); $chk->store_result();
        if ($chk->num_rows > 0) {
            $message = 'Username already taken by another account.'; $message_type = 'error';
        } else {
            $upd = $conn->prepare("UPDATE users SET username = ?, role_id = COALESCE((SELECT id FROM roles WHERE slug=?), role_id) WHERE user_id = ?");
            $upd->bind_param('ssi', $username, $role_slug, $uid);
            if ($upd->execute()) {
                // Clear existing employee linkage for this user
                $clear_emp = $conn->prepare("UPDATE employees SET user_id = NULL WHERE user_id = ?");
                $clear_emp->bind_param('i', $uid); $clear_emp->execute();

                // Link to selected employee if set
                if ($employee_id > 0) {
                    $upd_emp = $conn->prepare("UPDATE employees SET user_id = ? WHERE employee_id = ?");
                    $upd_emp->bind_param('ii', $uid, $employee_id);
                    $upd_emp->execute();
                }
                $message = 'User updated successfully.'; $message_type = 'success';
            } else {
                $message = 'Failed to update user.'; $message_type = 'error';
            }
        }
    }
}

// ── FETCH ROLES FOR DROPDOWN ──
$all_roles = [];
$r_res = $conn->query("SELECT id, slug, name FROM roles ORDER BY name ASC");
if ($r_res) { $all_roles = $r_res->fetch_all(MYSQLI_ASSOC); }

// ── FETCH EMPLOYEES FOR DROPDOWN ──
$all_employees = [];
$e_res = $conn->query("SELECT employee_id, name, job_title, user_id FROM employees ORDER BY name ASC");
if ($e_res) { $all_employees = $e_res->fetch_all(MYSQLI_ASSOC); }

// ── FETCH ALL USERS ──
$users = [];
try {
    $res = $conn->prepare("SELECT u.user_id, u.username, e.employee_id AS linked_emp_id, e.name AS emp_name, COALESCE(r.slug, 'staff') AS role_slug, COALESCE(r.name, r.slug, 'Staff') AS role_name, u.created_at FROM users u LEFT JOIN employees e ON e.user_id = u.user_id LEFT JOIN roles r ON r.id = u.role_id ORDER BY u.user_id ASC");
    $res->execute();
    $users = $res->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $e) {
    $res = $conn->prepare("SELECT u.user_id, u.username, e.employee_id AS linked_emp_id, e.name AS emp_name, COALESCE(r.slug, 'staff') AS role_slug, COALESCE(r.name, r.slug, 'Staff') AS role_name FROM users u LEFT JOIN employees e ON e.user_id = u.user_id LEFT JOIN roles r ON r.id = u.role_id ORDER BY u.user_id ASC");
    $res->execute();
    $users = $res->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage Admins | Café</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
    --bg:      #0c0c0c;
    --card:    #161616;
    --border:  #222;
    --accent:  #d1904b;
    --accent2: #b57b3b;
    --text:    #f0f0f0;
    --muted:   #888;
    --success: #3ecf70;
    --danger:  #ff4d4d;
    --warning: #f5a623;
    --radius:  14px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: Poppins, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    font-size: 14px;
}

/* ── NAV ── */
.topnav {
    position: sticky; top: 0; z-index: 100;
    background: rgba(10,10,10,.92);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--border);
    padding: 14px 24px;
    display: flex; align-items: center; gap: 12px;
}
.back-btn {
    display: flex; align-items: center; gap: 7px;
    color: #d1904b; text-decoration: none;
    font-weight: 600; font-size: 13px;
    padding: 7px 14px; border: 1px solid rgba(209,144,75,.35);
    border-radius: 10px; background: rgba(209,144,75,.08);
    transition: background .2s, border-color .2s;
}
.back-btn:hover { background: rgba(209,144,75,.16); border-color: #d1904b; }
.nav-title {
    font-size: 14px; font-weight: 600; color: var(--text);
    margin-left: 4px;
}
.admin-count-chip {
    margin-left: auto;
    background: rgba(209,144,75,.12);
    border: 1px solid rgba(209,144,75,.25);
    color: var(--accent); font-size: 11px; font-weight: 600;
    padding: 4px 12px; border-radius: 20px;
    display: flex; align-items: center; gap: 5px;
}

/* ── LAYOUT ── */
.page-wrap {
    max-width: 960px;
    margin: 0 auto;
    padding: 32px 20px 60px;
    display: grid;
    grid-template-columns: 380px 1fr;
    gap: 24px;
    align-items: start;
}
@media (max-width: 740px) { .page-wrap { grid-template-columns: 1fr; } }

/* ── SECTION CARD ── */
.section-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
}
.section-head {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 10px;
}
.section-head i { color: var(--accent); font-size: 14px; }
.section-head h3 { font-size: 14px; font-weight: 600; }
.section-body { padding: 20px; display: flex; flex-direction: column; gap: 14px; }

/* ── FORM ── */
.field { display: flex; flex-direction: column; gap: 6px; }
.flabel { font-size: 12px; font-weight: 500; color: #c0a070; letter-spacing: .2px; }
.input-wrap { position: relative; }
.input-wrap .iicon {
    position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
    color: var(--muted); font-size: 14px; pointer-events: none;
    transition: color .2s;
}
.input-wrap input {
    width: 100%; padding: 11px 40px;
    border-radius: 10px; border: 1px solid var(--border);
    background: #0f0f0f; color: var(--text);
    font-family: Poppins, sans-serif; font-size: 14px;
    transition: border-color .2s, box-shadow .2s;
}
.input-wrap input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(209,144,75,.12); }
.input-wrap input:focus ~ .iicon { color: var(--accent); }
.toggle-pw {
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    color: var(--muted); cursor: pointer; font-size: 14px;
    transition: color .2s;
}
.toggle-pw:hover { color: var(--accent); }

/* username status */
.field-hint {
    font-size: 11px; display: flex; align-items: center; gap: 5px;
    height: 16px; transition: color .2s;
}
.field-hint.ok    { color: var(--success); }
.field-hint.err   { color: var(--danger); }
.field-hint.info  { color: var(--muted); }

/* password strength */
.pw-strength { display: flex; gap: 5px; margin-top: 4px; }
.pw-bar {
    flex: 1; height: 3px; border-radius: 99px;
    background: #2a2a2a; transition: background .3s;
}
.pw-label { font-size: 11px; color: var(--muted); margin-top: 4px; }

/* confirm match */
.match-icon {
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    font-size: 14px; pointer-events: none;
}

/* ── SUBMIT ── */
.btn-save {
    width: 100%; padding: 13px;
    border: none; border-radius: var(--radius);
    background: linear-gradient(135deg, var(--accent2), var(--accent));
    color: #000; font-size: 14px; font-weight: 700;
    cursor: pointer; display: flex; align-items: center;
    justify-content: center; gap: 8px;
    transition: opacity .2s, transform .15s;
    font-family: Poppins, sans-serif;
    margin-top: 4px;
}
.btn-save:hover { opacity: .9; transform: translateY(-1px); }
.btn-save:active { transform: translateY(0); }

/* ── MESSAGE ── */
.msg-bar {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 16px; border-radius: 12px;
    font-size: 13px; font-weight: 500;
    animation: slideDown .3s ease;
}
@keyframes slideDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
.msg-bar.success { background: rgba(62,207,112,.08); color: var(--success); border: 1px solid rgba(62,207,112,.2); }
.msg-bar.error   { background: rgba(255,77,77,.08);  color: var(--danger);  border: 1px solid rgba(255,77,77,.2); }

/* ── ADMIN LIST ── */
.admin-item {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 20px;
    border-bottom: 1px solid #1a1a1a;
    transition: background .15s;
}
.admin-item:last-child { border-bottom: none; }
.admin-item:hover { background: #1a1a1a; }

.admin-avatar {
    width: 40px; height: 40px; border-radius: 12px;
    background: linear-gradient(135deg, #1e1e1e, #2a2a2a);
    border: 1px solid #2e2e2e;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; font-weight: 700; color: var(--accent);
    flex-shrink: 0; text-transform: uppercase;
}
.admin-name { font-size: 14px; font-weight: 600; }
.admin-meta { font-size: 11px; color: var(--muted); margin-top: 2px; display: flex; gap: 8px; align-items: center; }

.badge {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 10px; font-weight: 600; padding: 2px 8px;
    border-radius: 20px;
}
.badge.you  { background: rgba(93,173,226,.12); color: #5dade2; border: 1px solid rgba(93,173,226,.25); }
.badge.role { background: rgba(209,144,75,.1);  color: var(--accent); border: 1px solid rgba(209,144,75,.15); }

.delete-btn {
    margin-left: auto; flex-shrink: 0;
    width: 32px; height: 32px; border-radius: 9px;
    border: 1px solid #2a2a2a; background: transparent;
    color: var(--muted); cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; transition: background .2s, border-color .2s, color .2s;
}
.delete-btn:hover { background: rgba(255,77,77,.1); border-color: rgba(255,77,77,.3); color: var(--danger); }
.delete-btn:disabled { opacity: .3; cursor: not-allowed; pointer-events: none; }

.empty-admins {
    text-align: center; padding: 40px 20px; color: var(--muted); font-size: 13px;
}
.empty-admins i { font-size: 28px; opacity: .3; display: block; margin-bottom: 8px; }

/* ── DELETE MODAL ── */
.modal-backdrop {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.7);
    z-index: 200; display: flex;
    align-items: center; justify-content: center;
    opacity: 0; pointer-events: none;
    transition: opacity .25s;
}
.modal-backdrop.open { opacity: 1; pointer-events: all; }
.modal {
    background: var(--card); border: 1px solid var(--border);
    border-radius: 18px; padding: 28px;
    width: 360px; max-width: 90vw;
    max-height: 85vh; overflow-y: auto;
    transform: scale(.94); transition: transform .25s;
}
.modal-backdrop.open .modal { transform: scale(1); }
.modal-icon {
    width: 48px; height: 48px; border-radius: 14px;
    background: rgba(255,77,77,.1); border: 1px solid rgba(255,77,77,.2);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; color: var(--danger); margin-bottom: 14px;
}
.modal h3 { font-size: 17px; margin-bottom: 6px; }
.modal p  { font-size: 13px; color: var(--muted); line-height: 1.6; }
.modal-name { color: var(--text); font-weight: 600; }
.modal-actions { display: flex; gap: 10px; margin-top: 22px; }
.btn-cancel {
    flex: 1; padding: 11px; border-radius: 10px;
    border: 1px solid var(--border); background: #111;
    color: var(--muted); font-family: Poppins, sans-serif;
    font-size: 14px; cursor: pointer;
    transition: border-color .2s, color .2s;
}
.btn-cancel:hover { border-color: #3a3a3a; color: var(--text); }
.btn-delete {
    flex: 1; padding: 11px; border-radius: 10px;
    border: none; background: var(--danger);
    color: #fff; font-family: Poppins, sans-serif;
    font-size: 14px; font-weight: 600; cursor: pointer;
    transition: opacity .2s;
}
.btn-delete:hover { opacity: .85; }

/* ── ANIMATIONS ── */
@keyframes fadeDown  { from { opacity:0; transform:translateY(-18px); } to { opacity:1; transform:translateY(0); } }
@keyframes fadeUp    { from { opacity:0; transform:translateY(22px);  } to { opacity:1; transform:translateY(0); } }
@keyframes slideLeft { from { opacity:0; transform:translateX(-22px); } to { opacity:1; transform:translateX(0); } }
@keyframes slideRight{ from { opacity:0; transform:translateX(22px);  } to { opacity:1; transform:translateX(0); } }
@keyframes popIn     { from { opacity:0; transform:scale(.88);        } to { opacity:1; transform:scale(1);     } }
@keyframes floatA    { 0%,100%{transform:translate(0,0)} 50%{transform:translate(-28px,22px)} }
@keyframes floatB    { 0%,100%{transform:translate(0,0)} 50%{transform:translate(22px,-28px)} }
@keyframes shimmer   { 0%{background-position:-200% center} 100%{background-position:200% center} }

.topnav   { animation: fadeDown  .45s ease both; }
.left-col { animation: slideLeft .5s  .1s ease both; }
.right-col{ animation: slideRight .5s .18s ease both; }

.section-card { position: relative; overflow: hidden; }
.section-head::after {
    content: '';
    position: absolute; left: 0; right: 0; bottom: 0;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(209,144,75,.35), transparent);
    background-size: 200% auto;
    animation: shimmer 3s linear infinite;
}
.section-head { position: relative; }

/* ambient orbs */
.orb {
    position: fixed; border-radius: 50%; filter: blur(90px);
    pointer-events: none; z-index: 0;
}
.orb-a {
    width: 420px; height: 420px;
    background: radial-gradient(circle, rgba(209,144,75,.18) 0%, transparent 70%);
    top: -120px; right: -120px;
    animation: floatA 9s ease-in-out infinite;
}
.orb-b {
    width: 320px; height: 320px;
    background: radial-gradient(circle, rgba(93,173,226,.12) 0%, transparent 70%);
    bottom: -80px; left: -80px;
    animation: floatB 11s ease-in-out infinite;
}
.page-wrap { position: relative; z-index: 1; }

/* avatar colour palette */
.admin-avatar {
    transition: transform .2s, box-shadow .2s;
}
.admin-avatar:hover {
    transform: scale(1.1) rotate(-3deg);
    box-shadow: 0 0 0 2px rgba(209,144,75,.4);
}
.admin-item {
    opacity: 0;
    animation: fadeUp .4s ease forwards;
    transition: background .15s, transform .15s;
}
.admin-item:hover { transform: translateX(4px); }

/* msg bar pop */
.msg-bar { animation: popIn .35s ease both; }

/* ── TOAST ── */
.toast {
    position: fixed; bottom: 24px; right: 24px;
    padding: 13px 18px; border-radius: 12px;
    font-weight: 600; font-size: 13px;
    display: flex; align-items: center; gap: 8px;
    transform: translateY(80px); opacity: 0;
    transition: transform .35s ease, opacity .35s ease;
    z-index: 500; pointer-events: none;
}
.toast.success { background: #1e2e20; border: 1px solid rgba(62,207,112,.35); color: var(--success); }
.toast.error   { background: #2a1515; border: 1px solid rgba(255,77,77,.35);  color: var(--danger); }
.toast.show    { transform: translateY(0); opacity: 1; }
</style>
</head>
<body class="bg-[#0e0e10] text-[#f0f0f0]">
<div class="app-layout flex h-screen w-screen overflow-hidden bg-[#0e0e10]">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<main class="app-main flex-1 h-full overflow-y-auto p-6 relative">
<div class="orb orb-a"></div>
<div class="orb orb-b"></div>

<div class="page-container" style="width:100%; max-width:100%; margin:0 auto; padding:10px 0;">

    <!-- TOP TOOLBAR -->
    <div style="display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:16px;">
        <div style="display:flex; align-items:center; gap:12px;">
            <div style="width:42px; height:42px; border-radius:12px; background:rgba(209,144,75,0.12); color:var(--accent,#d1904b); border:1px solid rgba(209,144,75,0.25); display:flex; align-items:center; justify-content:center; font-size:18px;">
                <i class="fa-solid fa-users-gear"></i>
            </div>
            <div>
                <h1 style="font-size:20px; font-weight:800; color:var(--text-light,#fff); margin:0;"><?= __('users_management', 'Users Management') ?></h1>
                <p style="font-size:12px; color:var(--muted,#888); margin:0;"><?= __('users_management_sub', 'Manage system users, administrator accounts and access privileges') ?></p>
            </div>
        </div>

        <div style="display:flex; align-items:center; gap:12px;">
            <div style="position:relative;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--muted,#888); font-size:13px;"></i>
                <input type="text" id="userSearch" placeholder="<?= __('search_user_ph', 'Search user...') ?>" onkeyup="filterUsers()" style="padding:10px 16px 10px 38px; border-radius:12px; border:1px solid var(--border,#222); background:var(--card,#161616); color:var(--text,#fff); font-size:13px; outline:none; width:240px;">
            </div>
            <button onclick="openAddAdminModal()" style="display:inline-flex; align-items:center; gap:8px; padding:10px 20px; border-radius:12px; background:var(--accent,#d1904b); color:#000; font-size:13.5px; font-weight:800; border:none; cursor:pointer; box-shadow:0 4px 16px rgba(209,144,75,.25); transition:all 0.2s;">
                <i class="fa-solid fa-plus"></i> <?= __('add_new_user', 'Add New User') ?>
            </button>
        </div>
    </div>

    <!-- ROLE FILTER PILLS -->
    <div style="display:flex; align-items:center; gap:8px; margin-bottom:16px; flex-wrap:wrap;">
        <button type="button" class="role-filter-btn" onclick="setRoleFilter('all', this)"
            style="padding:6px 14px; border-radius:20px; font-size:12px; font-weight:700; border:1px solid var(--accent,#d1904b); background:var(--accent,#d1904b); color:#000; cursor:pointer; transition:all 0.2s; display:inline-flex; align-items:center; gap:6px;">
            <i class="fa-solid fa-users" style="font-size:11px;"></i> <?= __('all_roles', 'All Roles') ?>
        </button>
        <?php foreach ($all_roles as $r): ?>
        <button type="button" class="role-filter-btn" onclick="setRoleFilter('<?= htmlspecialchars($r['slug']) ?>', this)"
            style="padding:6px 14px; border-radius:20px; font-size:12px; font-weight:600; border:1px solid var(--border,#222); background:rgba(255,255,255,0.04); color:var(--muted,#888); cursor:pointer; transition:all 0.2s; display:inline-flex; align-items:center; gap:6px;">
            <i class="fa-solid fa-shield-halved" style="font-size:10px;"></i> <?= htmlspecialchars($r['name']) ?>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- LIST TABLE CARD -->
    <div class="table-card" style="background:var(--card,#161616); border:1px solid var(--border,#222); border-radius:14px; overflow:hidden;">
        <div class="table-wrap" style="overflow-x:auto; max-height:calc(100vh - 200px); overflow-y:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:13px;" id="userTable">
                <thead style="position:sticky; top:0; z-index:10; background:#161616;">
                    <tr style="background:rgba(255,255,255,0.02); border-bottom:1px solid var(--border,#222);">
                        <th style="width:50px; text-align:center; padding:14px 16px; font-size:11px; font-weight:700; text-transform:uppercase; color:var(--muted,#888);"><?= __('col_no', 'No') ?></th>
                        <th style="padding:14px 20px; text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; color:var(--muted,#888);"><?= __('col_user', 'User') ?></th>
                        <th style="padding:14px 20px; text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; color:var(--muted,#888);"><?= __('col_emp_name', 'Employee Name') ?></th>
                        <th style="padding:14px 20px; text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; color:var(--muted,#888);"><?= __('col_role', 'Role') ?></th>
                        <th style="padding:14px 20px; text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; color:var(--muted,#888);"><?= __('col_password', 'Password') ?></th>
                        <th style="padding:14px 20px; text-align:right; font-size:11px; font-weight:700; text-transform:uppercase; color:var(--muted,#888);"><?= __('actions', 'Actions') ?></th>
                    </tr>
                </thead>
                <tbody id="userTableBody">
                <?php
                if (empty($users)):
                ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:40px; color:var(--muted,#888);">
                            <i class="fa-solid fa-user-slash" style="font-size:24px; opacity:0.4; display:block; margin-bottom:8px;"></i>
                            No users found
                        </td>
                    </tr>
                <?php
                else:
                    $u_idx = 0;
                    foreach ($users as $a):
                        $u_idx++;
                        $isYou    = ($a['user_id'] === $currentUserId);
                        $initials = strtoupper(substr($a['username'], 0, 1));
                        $rName    = htmlspecialchars($a['role_name'] ?? $a['role_slug'] ?? 'Staff');
                        $empName  = !empty($a['emp_name']) ? htmlspecialchars($a['emp_name']) : '—';
                ?>
                    <tr id="admin-row-<?= $a['user_id'] ?>" class="user-row" data-name="<?= strtolower(htmlspecialchars($a['username'] . ' ' . ($a['emp_name'] ?? ''))) ?>" data-role="<?= htmlspecialchars($a['role_slug']) ?>" style="border-bottom:1px solid var(--border,#222);">
                        <td style="text-align:center; font-weight:700; color:var(--muted,#888); font-size:12px; padding:14px 16px;">
                            <?= $u_idx ?>
                        </td>
                        <td style="padding:14px 20px;">
                            <div style="display:flex; align-items:center; gap:12px;">
                                <div style="width:36px; height:36px; border-radius:10px; background:rgba(209,144,75,0.12); color:var(--accent,#d1904b); border:1px solid rgba(209,144,75,0.25); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:15px; flex-shrink:0;">
                                    <?= htmlspecialchars($initials) ?>
                                </div>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <span style="font-weight:700; font-size:14px; color:var(--text-light,#fff);"><?= htmlspecialchars($a['username']) ?></span>
                                    <?php if ($isYou): ?>
                                    <span style="background:rgba(52,152,219,0.15); color:#3498db; border:1px solid rgba(52,152,219,0.3); font-size:10px; font-weight:800; padding:1px 7px; border-radius:10px; text-transform:uppercase;"><?= __('badge_you', 'You') ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td style="padding:14px 20px; font-weight:600; color:var(--text-light,#fff); font-size:13.5px;">
                            <?= $empName ?>
                        </td>
                        <td style="padding:14px 20px;">
                            <span style="background:rgba(209,144,75,0.12); color:var(--accent,#d1904b); border:1px solid rgba(209,144,75,0.25); font-size:11px; font-weight:700; padding:3px 10px; border-radius:12px; display:inline-flex; align-items:center; gap:5px;">
                                <i class="fa-solid fa-shield-halved" style="font-size:10px;"></i> <?= $rName ?>
                            </span>
                        </td>
                        <td style="padding:14px 20px;">
                            <button title="Change Password"
                                onclick="openChangePwModal(<?= $a['user_id'] ?>, '<?= htmlspecialchars($a['username'], ENT_QUOTES) ?>')"
                                style="padding:5px 12px; border-radius:8px; border:1px solid rgba(209,144,75,0.3); background:rgba(209,144,75,0.12); color:var(--accent,#d1904b); cursor:pointer; font-size:11.5px; font-weight:600; display:inline-flex; align-items:center; gap:5px; transition:all 0.2s;">
                                <i class="fa-solid fa-key" style="font-size:10px;"></i> <?= __('btn_change', 'Change') ?>
                            </button>
                        </td>
                        <td style="padding:14px 20px; text-align:right;">
                            <div style="display:flex; align-items:center; justify-content:flex-end; gap:8px;">
                                <button title="Edit User"
                                    onclick="openEditUserModal(<?= $a['user_id'] ?>, '<?= htmlspecialchars($a['username'], ENT_QUOTES) ?>', '<?= htmlspecialchars($a['role_slug'], ENT_QUOTES) ?>', <?= (int)($a['linked_emp_id'] ?? 0) ?>)"
                                    style="width:30px; height:30px; border-radius:8px; border:1px solid rgba(52,152,219,0.3); background:rgba(52,152,219,0.12); color:#3498db; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all 0.2s;">
                                    <i class="fa-solid fa-pen-to-square" style="font-size:12px;"></i>
                                </button>
                                <button class="delete-btn" <?= $isYou ? 'disabled title="Cannot delete your own account"' : '' ?>
                                    onclick="openDeleteModal(<?= $a['user_id'] ?>, '<?= htmlspecialchars($a['username'], ENT_QUOTES) ?>')"
                                    style="width:30px; height:30px; border-radius:8px; border:1px solid var(--border,#222); background:transparent; color:var(--muted,#888); cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all 0.2s;">
                                    <i class="fa-solid fa-trash-can" style="font-size:12px;"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php
                    endforeach;
                endif;
                ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ADD ADMIN MODAL -->
<div class="modal-backdrop" id="addAdminModal">
    <div class="modal" style="width:420px; padding:24px; border-radius:16px;">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid var(--border,#222);">
            <div style="display:flex; align-items:center; gap:8px; font-weight:700; font-size:16px; color:var(--text-light,#fff);">
                <i class="fa-solid fa-user-plus" style="color:var(--accent,#d1904b);"></i> Add New User
            </div>
            <button onclick="closeAddAdminModal()" style="background:none; border:none; color:var(--muted,#888); font-size:18px; cursor:pointer;">&times;</button>
        </div>

        <form method="POST" id="addForm" autocomplete="off">
            <input type="hidden" name="action" value="add">

            <!-- Username -->
            <div class="field" style="margin-bottom:12px">
                <label class="flabel">Username</label>
                <div class="input-wrap">
                    <i class="iicon fa-solid fa-user"></i>
                    <input type="text" name="username" id="f_user" placeholder="e.g. johndoe" minlength="3" required autocomplete="new-password">
                </div>
                <div class="field-hint info" id="userHint">
                    <i class="fa-solid fa-circle-dot" style="font-size:8px"></i> Min. 3 characters
                </div>
            </div>

            <!-- Link to Employee -->
            <div class="field" style="margin-bottom:12px">
                <label class="flabel">Link to Employee (Optional)</label>
                <div class="input-wrap">
                    <i class="iicon fa-solid fa-id-badge"></i>
                    <select name="employee_id" id="add_employee_id" style="width:100%; padding:11px 16px 11px 40px; border-radius:10px; border:1px solid var(--border,#222); background:#0f0f0f; color:var(--text,#fff); font-size:14px; outline:none; font-family:inherit;">
                        <option value="0" data-job="">-- None / System User --</option>
                        <?php foreach ($all_employees as $emp): ?>
                        <option value="<?= $emp['employee_id'] ?>" data-job="<?= htmlspecialchars(strtolower($emp['job_title'] ?? '')) ?>">
                            <?= htmlspecialchars($emp['name']) ?><?= !empty($emp['user_id']) ? ' (Already Linked)' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Role -->
            <div class="field" style="margin-bottom:12px">
                <label class="flabel">Role</label>
                <div class="input-wrap">
                    <i class="iicon fa-solid fa-shield-halved"></i>
                    <select name="role_slug" id="add_role_slug" style="width:100%; padding:11px 16px 11px 40px; border-radius:10px; border:1px solid var(--border,#222); background:#0f0f0f; color:var(--text,#fff); font-size:14px; outline:none; font-family:inherit;">
                        <?php foreach ($all_roles as $r): ?>
                        <option value="<?= htmlspecialchars($r['slug']) ?>"><?= htmlspecialchars($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Password -->
            <div class="field" style="margin-bottom:12px">
                <label class="flabel">Password</label>
                <div class="input-wrap">
                    <i class="iicon fa-solid fa-lock"></i>
                    <input type="password" name="password" id="f_pw" placeholder="Min. 6 characters" required autocomplete="new-password">
                    <span class="toggle-pw" onclick="togglePw('f_pw','pwEye')">
                        <i class="fa-solid fa-eye" id="pwEye"></i>
                    </span>
                </div>
                <div class="pw-strength" id="pwStrength" style="margin-top:6px;">
                    <div class="pw-bar" id="bar0"></div>
                    <div class="pw-bar" id="bar1"></div>
                    <div class="pw-bar" id="bar2"></div>
                    <div class="pw-bar" id="bar3"></div>
                </div>
                <div class="pw-label" id="pwLabel" style="font-size:11px; margin-top:2px;">Enter a password</div>
            </div>

            <!-- Confirm Password -->
            <div class="field" style="margin-bottom:16px">
                <label class="flabel">Confirm Password</label>
                <div class="input-wrap">
                    <i class="iicon fa-solid fa-lock-open"></i>
                    <input type="password" name="confirm_password" id="f_confirm" placeholder="Repeat password" required autocomplete="new-password">
                    <span class="toggle-pw" onclick="togglePw('f_confirm','cfEye')">
                        <i class="fa-solid fa-eye" id="cfEye"></i>
                    </span>
                    <i class="match-icon" id="matchIcon"></i>
                </div>
            </div>

            <div style="display:flex; gap:10px;">
                <button type="button" onclick="closeAddAdminModal()" class="btn-cancel" style="flex:1;">Cancel</button>
                <button type="submit" class="btn-save" style="flex:1.5; margin-top:0; padding:11px; border-radius:10px; background:var(--accent,#d1904b); color:#000; font-weight:700; border:none; cursor:pointer;">
                    <i class="fa-solid fa-plus"></i> Create Account
                </button>
            </div>
        </form>
    </div>
</div>

<!-- CHANGE PASSWORD MODAL -->
<div class="modal-backdrop" id="changePwModal">
    <div class="modal" style="width:400px; padding:24px; border-radius:16px;">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid var(--border,#222);">
            <div style="display:flex; align-items:center; gap:8px; font-weight:700; font-size:16px; color:var(--text-light,#fff);">
                <i class="fa-solid fa-key" style="color:var(--accent,#d1904b);"></i> Change Password
            </div>
            <button onclick="closeChangePwModal()" style="background:none; border:none; color:var(--muted,#888); font-size:18px; cursor:pointer;">&times;</button>
        </div>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="action" value="change_password">
            <input type="hidden" name="target_user_id" id="chPwUserId">

            <div style="font-size:13px; color:var(--muted,#888); margin-bottom:14px;">
                Updating password for user: <strong id="chPwUsername" style="color:var(--accent,#d1904b);"></strong>
            </div>

            <div class="field" style="margin-bottom:12px">
                <label class="flabel">New Password</label>
                <div class="input-wrap">
                    <i class="iicon fa-solid fa-lock"></i>
                    <input type="password" name="new_password" id="f_ch_pw" placeholder="Min. 6 characters" minlength="6" required autocomplete="new-password">
                    <span class="toggle-pw" onclick="togglePw('f_ch_pw','chPwEye')">
                        <i class="fa-solid fa-eye" id="chPwEye"></i>
                    </span>
                </div>
            </div>

            <div class="field" style="margin-bottom:16px">
                <label class="flabel">Confirm New Password</label>
                <div class="input-wrap">
                    <i class="iicon fa-solid fa-lock-open"></i>
                    <input type="password" name="confirm_new_password" id="f_ch_confirm" placeholder="Repeat new password" minlength="6" required autocomplete="new-password">
                    <span class="toggle-pw" onclick="togglePw('f_ch_confirm','chCfEye')">
                        <i class="fa-solid fa-eye" id="chCfEye"></i>
                    </span>
                </div>
            </div>

            <div style="display:flex; gap:10px;">
                <button type="button" onclick="closeChangePwModal()" class="btn-cancel" style="flex:1;">Cancel</button>
                <button type="submit" class="btn-save" style="flex:1.5; margin-top:0; padding:11px; border-radius:10px; background:var(--accent,#d1904b); color:#000; font-weight:700; border:none; cursor:pointer;">
                    <i class="fa-solid fa-check"></i> Save Password
                </button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT USER MODAL -->
<div class="modal-backdrop" id="editUserModal">
    <div class="modal" style="width:400px; padding:24px; border-radius:16px;">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid var(--border,#222);">
            <div style="display:flex; align-items:center; gap:8px; font-weight:700; font-size:16px; color:var(--text-light,#fff);">
                <i class="fa-solid fa-user-pen" style="color:#3498db;"></i> Edit User Details
            </div>
            <button onclick="closeEditUserModal()" style="background:none; border:none; color:var(--muted,#888); font-size:18px; cursor:pointer;">&times;</button>
        </div>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="target_user_id" id="editUserId">

            <!-- Username -->
            <div class="field" style="margin-bottom:12px">
                <label class="flabel">Username</label>
                <div class="input-wrap">
                    <i class="iicon fa-solid fa-user"></i>
                    <input type="text" name="username" id="edit_username" placeholder="Username" minlength="3" required>
                </div>
            </div>

            <!-- Link to Employee -->
            <div class="field" style="margin-bottom:12px">
                <label class="flabel">Link to Employee</label>
                <div class="input-wrap">
                    <i class="iicon fa-solid fa-id-badge"></i>
                    <select name="employee_id" id="edit_employee_id" style="width:100%; padding:11px 16px 11px 40px; border-radius:10px; border:1px solid var(--border,#222); background:#0f0f0f; color:var(--text,#fff); font-size:14px; outline:none; font-family:inherit;">
                        <option value="0" data-job="">-- None / System User --</option>
                        <?php foreach ($all_employees as $emp): ?>
                        <option value="<?= $emp['employee_id'] ?>" data-job="<?= htmlspecialchars(strtolower($emp['job_title'] ?? '')) ?>">
                            <?= htmlspecialchars($emp['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Role -->
            <div class="field" style="margin-bottom:16px">
                <label class="flabel">Role</label>
                <div class="input-wrap">
                    <i class="iicon fa-solid fa-shield-halved"></i>
                    <select name="role_slug" id="edit_role_slug" style="width:100%; padding:11px 16px 11px 40px; border-radius:10px; border:1px solid var(--border,#222); background:#0f0f0f; color:var(--text,#fff); font-size:14px; outline:none; font-family:inherit;">
                        <?php foreach ($all_roles as $r): ?>
                        <option value="<?= htmlspecialchars($r['slug']) ?>"><?= htmlspecialchars($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="display:flex; gap:10px;">
                <button type="button" onclick="closeEditUserModal()" class="btn-cancel" style="flex:1;">Cancel</button>
                <button type="submit" class="btn-save" style="flex:1.5; margin-top:0; padding:11px; border-radius:10px; background:#3498db; color:#fff; font-weight:700; border:none; cursor:pointer;">
                    <i class="fa-solid fa-check"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE MODAL -->
<div class="modal-backdrop" id="deleteModal">
    <div class="modal">
        <div class="modal-icon"><i class="fa-solid fa-trash-can"></i></div>
        <h3>Delete Admin Account</h3>
        <p>This will permanently remove <span class="modal-name" id="modalName">—</span>'s access to the dashboard. This cannot be undone.</p>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeModal()">Cancel</button>
            <button class="btn-delete" id="confirmDeleteBtn" onclick="executeDelete()">Delete Account</button>
        </div>
    </div>
</div>

<!-- TOAST -->
<div class="toast" id="toast"></div>

<script>
let pendingDeleteId = null;
let activeRoleFilter = 'all';

function setRoleFilter(slug, btn) {
    activeRoleFilter = slug;
    document.querySelectorAll('.role-filter-btn').forEach(b => {
        b.style.background = 'rgba(255,255,255,0.04)';
        b.style.color = 'var(--muted,#888)';
        b.style.borderColor = 'var(--border,#222)';
        b.style.fontWeight = '600';
    });
    btn.style.background = 'var(--accent,#d1904b)';
    btn.style.color = '#000';
    btn.style.borderColor = 'var(--accent,#d1904b)';
    btn.style.fontWeight = '700';
    filterUsers();
}

function filterUsers() {
    const input = document.getElementById('userSearch').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#userTableBody .user-row');
    rows.forEach(row => {
        const name = row.getAttribute('data-name') || '';
        const role = row.getAttribute('data-role') || '';
        const matchesSearch = name.includes(input);
        const matchesRole = (activeRoleFilter === 'all' || role === activeRoleFilter);
        row.style.display = (matchesSearch && matchesRole) ? '' : 'none';
    });
}
function openAddAdminModal() {
    const empSel = document.getElementById('add_employee_id');
    const roleSel = document.getElementById('add_role_slug');
    if (empSel && roleSel) {
        empSel.value = '0';
        autoSyncRole(empSel, roleSel);
    }
    document.getElementById('addAdminModal').classList.add('open');
}
function closeAddAdminModal() {
    document.getElementById('addAdminModal').classList.remove('open');
}
document.getElementById('addAdminModal').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeAddAdminModal();
});

function openChangePwModal(id, username) {
    document.getElementById('chPwUserId').value = id;
    document.getElementById('chPwUsername').textContent = username;
    document.getElementById('changePwModal').classList.add('open');
}
function closeChangePwModal() {
    document.getElementById('changePwModal').classList.remove('open');
}
document.getElementById('changePwModal').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeChangePwModal();
});

const empRoleMap = {
    'admin': 'admin',
    'administrator': 'admin',
    'manager': 'manager',
    'cashier': 'staff',
    'staff': 'staff',
    'barista': 'barista',
    'inventory': 'inventory_clerk',
    'inventory clerk': 'inventory_clerk',
    'inventory_clerk': 'inventory_clerk'
};

function autoSyncRole(empSelect, roleSelect) {
    if (!empSelect || !roleSelect) return;
    const opt = empSelect.options[empSelect.selectedIndex];
    const job = (opt ? opt.getAttribute('data-job') : '') || '';
    if (!job || empSelect.value === '0') {
        roleSelect.style.pointerEvents = 'auto';
        roleSelect.style.opacity = '1';
        return;
    }

    let matchedSlug = empRoleMap[job] || '';
    if (!matchedSlug) {
        for (let rOpt of roleSelect.options) {
            if (rOpt.text.toLowerCase().includes(job) || job.includes(rOpt.text.toLowerCase())) {
                matchedSlug = rOpt.value;
                break;
            }
        }
    }

    if (matchedSlug) {
        roleSelect.value = matchedSlug;
    }
    roleSelect.style.pointerEvents = 'none';
    roleSelect.style.opacity = '0.7';
}

document.getElementById('add_employee_id')?.addEventListener('change', function() {
    autoSyncRole(this, document.getElementById('add_role_slug'));
});

document.getElementById('edit_employee_id')?.addEventListener('change', function() {
    autoSyncRole(this, document.getElementById('edit_role_slug'));
});

function openEditUserModal(id, username, roleSlug, empId) {
    document.getElementById('editUserId').value = id;
    document.getElementById('edit_username').value = username;
    const empSel = document.getElementById('edit_employee_id');
    const roleSel = document.getElementById('edit_role_slug');
    if (empSel && roleSel) {
        empSel.value = empId || 0;
        roleSel.value = roleSlug;
        autoSyncRole(empSel, roleSel);
    }
    document.getElementById('editUserModal').classList.add('open');
}
function closeEditUserModal() {
    document.getElementById('editUserModal').classList.remove('open');
}
document.getElementById('editUserModal').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeEditUserModal();
});

// ── Username availability check ──
const fUser = document.getElementById('f_user');
const userHint = document.getElementById('userHint');
let usernameTimer;
fUser.addEventListener('input', () => {
    clearTimeout(usernameTimer);
    const val = fUser.value.trim();
    if (val.length < 3) {
        userHint.className = 'field-hint info';
        userHint.innerHTML = '<i class="fa-solid fa-circle-dot" style="font-size:8px"></i> Min. 3 characters';
        return;
    }
    userHint.className = 'field-hint info';
    userHint.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="font-size:10px"></i> Checking...';
    usernameTimer = setTimeout(async () => {
        try {
            const r = await fetch(`manage_admin.php?check_username=${encodeURIComponent(val)}`);
            const d = await r.json();
            if (d.available) {
                userHint.className = 'field-hint ok';
                userHint.innerHTML = '<i class="fa-solid fa-circle-check"></i> Available';
            } else {
                userHint.className = 'field-hint err';
                userHint.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> Username taken';
            }
        } catch { userHint.className = 'field-hint info'; userHint.innerHTML = ''; }
    }, 420);
});

// ── Password strength ──
const fPw = document.getElementById('f_pw');
const bars = [0,1,2,3].map(i => document.getElementById('bar'+i));
const pwLabel = document.getElementById('pwLabel');
const colors = { weak:'#ff4d4d', medium:'#f5a623', strong:'#3ecf70', very:'#3ecf70' };
const labels = { 0:'', 1:'Weak', 2:'Fair', 3:'Good', 4:'Strong' };

fPw.addEventListener('input', () => {
    const pw = fPw.value;
    let score = 0;
    if (pw.length >= 6)  score++;
    if (pw.length >= 10) score++;
    if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
    if (/[0-9]/.test(pw) && /[^a-zA-Z0-9]/.test(pw)) score++;
    const col = score <= 1 ? colors.weak : score === 2 ? colors.medium : colors.strong;
    bars.forEach((b, i) => { b.style.background = i < score ? col : '#2a2a2a'; });
    pwLabel.textContent = pw.length === 0 ? 'Enter a password' : labels[score];
    pwLabel.style.color = pw.length === 0 ? 'var(--muted)' : col;
    checkMatch();
});

// ── Password match ──
const fConfirm = document.getElementById('f_confirm');
const matchIcon = document.getElementById('matchIcon');
fConfirm.addEventListener('input', checkMatch);
function checkMatch() {
    if (!fConfirm.value) { matchIcon.innerHTML = ''; return; }
    if (fPw.value === fConfirm.value) {
        matchIcon.innerHTML = '<i class="fa-solid fa-check" style="color:var(--success)"></i>';
    } else {
        matchIcon.innerHTML = '<i class="fa-solid fa-xmark" style="color:var(--danger)"></i>';
    }
}

// ── Show/hide password ──
function togglePw(inputId, iconId) {
    const inp = document.getElementById(inputId);
    const ico = document.getElementById(iconId);
    if (inp.type === 'password') { inp.type = 'text'; ico.className = 'fa-solid fa-eye-slash'; }
    else { inp.type = 'password'; ico.className = 'fa-solid fa-eye'; }
}

// ── Delete modal ──
function openDeleteModal(id, name) {
    pendingDeleteId = id;
    document.getElementById('modalName').textContent = name;
    document.getElementById('deleteModal').classList.add('open');
}
function closeModal() {
    document.getElementById('deleteModal').classList.remove('open');
    pendingDeleteId = null;
}
document.getElementById('deleteModal').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeModal();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

async function executeDelete() {
    if (!pendingDeleteId) return;
    const btn = document.getElementById('confirmDeleteBtn');
    btn.textContent = 'Deleting...'; btn.disabled = true;
    try {
        const fd = new FormData();
        fd.append('id', pendingDeleteId);
        const r = await fetch('delete_admin.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) {
            const row = document.getElementById('admin-row-' + pendingDeleteId);
            if (row) { row.style.opacity = '0'; row.style.transition = 'opacity .3s'; setTimeout(() => row.remove(), 300); }
            showToast('Admin account deleted.', 'success');
            // update count chip
            const chip = document.querySelector('.admin-count-chip');
            if (chip) {
                const cur = parseInt(chip.textContent) - 1;
                chip.innerHTML = `<i class="fa-solid fa-users" style="font-size:10px"></i> ${cur} admin${cur !== 1 ? 's' : ''}`;
            }
        } else {
            showToast(d.error || 'Delete failed.', 'error');
        }
    } catch { showToast('Network error.', 'error'); }
    closeModal();
    btn.textContent = 'Delete Account'; btn.disabled = false;
}

// ── Toast ──
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.className = 'toast ' + type;
    t.innerHTML = `<i class="fa-solid ${type === 'success' ? 'fa-circle-check' : 'fa-circle-xmark'}"></i> ${msg}`;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3500);
}

<?php if ($message && $message_type === 'success'): ?>
setTimeout(() => showToast(<?= json_encode($message) ?>, 'success'), 200);
<?php endif; ?>
</script>
</main>
</div>
</body>
</html>
