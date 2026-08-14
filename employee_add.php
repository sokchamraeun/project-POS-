<?php
require 'admin_only.php';
require 'config.php';

// AJAX: username availability check
if (isset($_GET['check_username'])) {
    header('Content-Type: application/json');
    $u = trim($_GET['check_username'] ?? '');
    if (strlen($u) < 3) { echo json_encode(['available'=>false,'reason'=>'min3']); exit; }
    $q = $conn->prepare("SELECT user_id FROM users WHERE username=? LIMIT 1");
    $q->bind_param('s',$u); $q->execute(); $q->store_result();
    echo json_encode(['available' => $q->num_rows === 0]);
    exit;
}

$errors = [];
$old = ['name'=>'','phone'=>'','job'=>'','salary'=>'','dob'=>'','hire'=>date('Y-m-d'),'address'=>'','username'=>'','role'=>'staff'];

// Load roles from DB safely
$_all_roles = [];
$_rr = $conn->query("SELECT slug, name, icon, description FROM roles ORDER BY is_system DESC, id ASC");
if ($_rr) {
    while ($_rv = $_rr->fetch_assoc()) $_all_roles[] = $_rv;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $job_raw  = trim($_POST['job_title'] ?? '');
    $job      = ($job_raw === '__other__') ? trim($_POST['job_title_custom'] ?? '') : $job_raw;
    $salary   = (float)($_POST['salary'] ?? 0);
    $dob      = trim($_POST['date_of_birth'] ?? '');
    $hire     = trim($_POST['hire_date'] ?? '');
    $address  = trim($_POST['address'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    $roleRaw  = trim($_POST['role'] ?? 'staff');
    $is_pos   = 0;
    
    // Validate against DB roles
    $_vrole = $conn->prepare("SELECT slug FROM roles WHERE slug=?");
    if ($_vrole) {
        $_vrole->bind_param("s", $roleRaw); $_vrole->execute();
        $role = $_vrole->get_result()->fetch_assoc() ? $roleRaw : 'staff';
    } else {
        $role = 'staff';
    }

    $old = ['name'=>$name,'phone'=>$phone,'job'=>$job,'salary'=>$salary,'dob'=>$dob,'hire'=>$hire,'address'=>$address,'username'=>$username,'role'=>$roleRaw];

    if (!$name)    $errors[] = 'Full name is required.';
    if (!$job)     $errors[] = 'Job title is required.';
    // Account/role checks only apply to POS staff. Display-only staff have no login.
    if ($is_pos) {
        if (!$username) $errors[] = 'Username is required.';
        elseif (strlen($username) < 3) $errors[] = 'Username must be at least 3 characters.';
        if (!$password) $errors[] = 'Password is required.';
        elseif (strlen($password) < 8)            $errors[] = 'Password must be at least 8 characters.';
        elseif (!preg_match('/[A-Z]/', $password)) $errors[] = 'Password must contain at least one uppercase letter.';
        elseif (!preg_match('/[0-9]/', $password)) $errors[] = 'Password must contain at least one number.';
        elseif (!preg_match('/[^A-Za-z0-9]/', $password)) $errors[] = 'Password must contain at least one symbol (e.g. !@#$).';
        if ($password !== $confirm) $errors[] = 'Passwords do not match.';

        if (empty($errors)) {
            $q = $conn->prepare("SELECT user_id FROM users WHERE username=? LIMIT 1");
            if ($q) {
                $q->bind_param('s',$username); $q->execute(); $q->store_result();
                if ($q->num_rows > 0) $errors[] = 'Username is already taken.';
            }
        }
    }

    if (empty($errors)) {
        try {
            $photo = '';
            if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
                if (in_array($_FILES['photo']['type'], $allowed)) {
                    if (!is_dir('uploads')) mkdir('uploads', 0777, true);
                    $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                    $photo = 'uploads/' . time() . '_' . uniqid() . '.' . $ext;
                    move_uploaded_file($_FILES['photo']['tmp_name'], $photo);
                }
            }

            $dob_val  = ($dob !== '') ? $dob : null;
            $hire_val = ($hire !== '') ? $hire : null;

            // Check if is_pos column exists on employees table
            $has_is_pos = false;
            $c_chk = $conn->query("SHOW COLUMNS FROM employees LIKE 'is_pos'");
            if ($c_chk && $c_chk->num_rows > 0) {
                $has_is_pos = true;
            }

            if ($has_is_pos) {
                $s1 = $conn->prepare("INSERT INTO employees (name,phone,job_title,salary,date_of_birth,hire_date,address,photo,is_pos) VALUES (?,?,?,?,?,?,?,?,?)");
                $s1->bind_param("sssdssssi",$name,$phone,$job,$salary,$dob_val,$hire_val,$address,$photo,$is_pos);
            } else {
                $s1 = $conn->prepare("INSERT INTO employees (name,phone,job_title,salary,date_of_birth,hire_date,address,photo) VALUES (?,?,?,?,?,?,?,?)");
                $s1->bind_param("sssdssss",$name,$phone,$job,$salary,$dob_val,$hire_val,$address,$photo);
            }

            if (!$s1 || !$s1->execute()) {
                throw new Exception($conn->error ?: "Failed to insert employee record.");
            }
            $emp_id = $conn->insert_id;

            // POS staff get a login + role; display-only staff get neither (user_id stays NULL).
            if ($is_pos) {
                $hp = password_hash($password, PASSWORD_DEFAULT);

                // Fetch explicit role_id
                $role_id = null;
                $r_stmt = $conn->prepare("SELECT id FROM roles WHERE slug=? LIMIT 1");
                if ($r_stmt) {
                    $r_stmt->bind_param("s", $role);
                    $r_stmt->execute();
                    $r_res = $r_stmt->get_result()->fetch_assoc();
                    if ($r_res) $role_id = (int)$r_res['id'];
                }

                if (!$role_id) {
                    $r_fallback = $conn->query("SELECT id FROM roles WHERE slug='staff' UNION ALL SELECT id FROM roles LIMIT 1")->fetch_assoc();
                    if ($r_fallback) $role_id = (int)$r_fallback['id'];
                }

                if (!$role_id) {
                    throw new Exception("Role not found in database.");
                }

                $s2 = $conn->prepare("INSERT INTO users (username,password,role_id) VALUES (?,?,?)");
                $s2->bind_param("ssi",$username,$hp,$role_id);
                if (!$s2 || !$s2->execute()) {
                    throw new Exception("Failed to create user login: " . ($s2 ? $s2->error : $conn->error));
                }
                $usr_id = $conn->insert_id;

                // Check if user_id column exists on employees table
                $u_chk = $conn->query("SHOW COLUMNS FROM employees LIKE 'user_id'");
                if ($u_chk && $u_chk->num_rows > 0) {
                    $s3 = $conn->prepare("UPDATE employees SET user_id = ? WHERE employee_id = ?");
                    $s3->bind_param("ii", $usr_id, $emp_id);
                    $s3->execute();
                }
            }

            header("Location: employees.php?added=1");
            exit;
        } catch (Throwable $t) {
            $errors[] = "Error adding employee: " . $t->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Employee | Bird's Nest Coffee</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script>(function(){var t=localStorage.getItem('theme');if(t==='light')document.documentElement.setAttribute('data-theme','light');})();</script>
<style>
:root {
    --accent: #d1904b;
    --accent-light: #e8b87a;
    --accent-dark: #a0702a;
    --success: #55e087;
    --danger: #ff6b6b;
    --warning: #f0a500;
    --bg: #080808;
    --bg-card: #111111;
    --border: #1e1e1e;
    --border-hover: #2e2e2e;
    --text: #f0f0f0;
    --text-muted: #888;
    --text-dim: #444;
    --input: #0c0c0c;
    --nav-bg: rgba(8,8,8,0.92);
    --menu-bg: #141414;
    --scheme: dark;
    --shadow-accent: 0 4px 20px rgba(209,144,75,0.2);
    --radius: 14px;
    --transition: all 0.25s cubic-bezier(0.4,0,0.2,1);
}

[data-theme="light"] {
    --bg: #f5f0eb;
    --bg-card: #ffffff;
    --border: #e8ddd2;
    --border-hover: #d4c4b0;
    --text: #1a1008;
    --text-muted: #7a6a58;
    --text-dim: #b3a591;
    --input: #f0e9e0;
    --nav-bg: rgba(245,240,235,0.92);
    --menu-bg: #ffffff;
    --scheme: light;
}

*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }

body {
    font-family: 'Inter', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    overflow-x: hidden;
}

::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb { background: #252525; border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--accent); }

/* ── TOP NAV ── */
.top-nav {
    position: sticky;
    top: 0;
    z-index: 100;
    background: var(--nav-bg);
    backdrop-filter: blur(16px);
    border-bottom: 1px solid var(--border);
    padding: 0 28px;
    height: 60px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.nav-back {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--text-muted);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    padding: 6px 12px;
    border-radius: 8px;
    border: 1px solid var(--border);
    transition: var(--transition);
    white-space: nowrap;
}

.nav-back:hover { color: var(--accent); border-color: var(--border-hover); }

.nav-sep { color: var(--text-dim); font-size: 13px; }

.nav-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
}

/* ── PAGE ── */
.page-wrapper {
    width: 100%;
    max-width: 1100px;
    margin: 0 auto;
    padding: 20px 24px 60px;
}

.page-heading { margin-bottom: 28px; }
.page-heading h1 { font-size: 22px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
.page-heading h1 span { color: var(--accent); }
.page-heading p { font-size: 13px; color: var(--text-muted); }

/* ── ERROR BANNER ── */
.error-banner {
    background: rgba(255,107,107,0.07);
    border: 1px solid rgba(255,107,107,0.18);
    border-radius: var(--radius);
    padding: 14px 18px;
    margin-bottom: 20px;
    animation: shake 0.5s ease;
}

@keyframes shake {
    0%,100% { transform: translateX(0); }
    20% { transform: translateX(-6px); }
    40% { transform: translateX(6px); }
    60% { transform: translateX(-6px); }
    80% { transform: translateX(6px); }
}

.error-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 600;
    color: var(--danger);
    margin-bottom: 8px;
}

.error-list { list-style: none; display: flex; flex-direction: column; gap: 4px; }
.error-list li { font-size: 12px; color: #ffaaaa; display: flex; align-items: center; gap: 6px; }
.error-list li::before { content: '•'; color: var(--danger); }

/* ── LAYOUT ── */
.layout {
    display: block;
    max-width: 860px;
    margin: 0 auto;
}

.left-panel {
    position: sticky;
    top: 20px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.right-panel { display: flex; flex-direction: column; gap: 16px; }

/* ── PANEL CARD ── */
.panel-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    transition: var(--transition);
}

.panel-card:hover { border-color: var(--border-hover); }

.panel-card-title {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* ── PHOTO UPLOAD ── */
.photo-drop {
    position: relative;
    width: 140px;
    height: 140px;
    margin: 0 auto 14px;
    border-radius: 50%;
    cursor: pointer;
    border: 2px dashed var(--border);
    transition: var(--transition);
    overflow: hidden;
    background: var(--input);
}

.photo-drop:hover,
.photo-drop.drag-over { border-color: var(--accent); background: rgba(209,144,75,0.05); }

.photo-drop input[type="file"] {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
    width: 100%;
    height: 100%;
    z-index: 3;
}

.photo-placeholder {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 5px;
    z-index: 1;
}

.photo-placeholder i { font-size: 36px; color: var(--text-dim); }
.photo-placeholder span { font-size: 10px; color: var(--text-muted); text-align: center; padding: 0 8px; line-height: 1.4; }

#photoPreview {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    z-index: 1;
    display: none;
}

.photo-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,0.6);
    display: none;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    z-index: 2;
    pointer-events: none;
}

.photo-overlay i { font-size: 18px; color: #fff; }
.photo-overlay span { font-size: 10px; color: rgba(255,255,255,0.85); }
.photo-drop:hover .photo-overlay { display: flex; }

.photo-filename {
    text-align: center;
    font-size: 11px;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    line-height: 1.4;
}

.photo-filename .ok-icon { color: var(--success); display: none; }
.photo-filename.has-file .ok-icon { display: inline; }
.photo-filename.has-file { color: var(--accent-light); }

/* ── EMPLOYEE PREVIEW ── */
.emp-preview { display: flex; align-items: center; gap: 12px; }

.emp-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--accent-dark));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    font-weight: 700;
    color: #000;
    flex-shrink: 0;
    overflow: hidden;
}

.emp-avatar img { width: 100%; height: 100%; object-fit: cover; }
.emp-info { flex: 1; min-width: 0; }

.emp-name {
    font-weight: 600;
    font-size: 14px;
    color: var(--text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.emp-job {
    font-size: 12px;
    color: var(--text-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-top: 2px;
}

.emp-role-pill {
    display: inline-block;
    margin-top: 5px;
    padding: 2px 10px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 600;
    background: rgba(209,144,75,0.12);
    color: var(--accent-light);
    border: 1px solid rgba(209,144,75,0.2);
    transition: var(--transition);
}

.emp-role-pill.admin {
    background: rgba(139,92,246,0.12);
    color: #c084fc;
    border-color: rgba(139,92,246,0.2);
}

/* ── SECTION CARD ── */
.section-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    transition: var(--transition);
}

.section-card:hover { border-color: var(--border-hover); }

.section-header {
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    font-weight: 600;
    color: var(--accent-light);
}

.section-body {
    padding: 20px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}

.full-width { grid-column: 1 / -1; }

/* ── FORM GROUP ── */
.form-group { display: flex; flex-direction: column; gap: 5px; }
.form-group label { font-size: 12px; font-weight: 500; color: var(--text-muted); }
.form-group label .req { color: var(--accent); }

/* ── INPUT WRAPPER ── */
.input-wrapper { position: relative; display: flex; align-items: center; }

.input-icon {
    position: absolute;
    left: 12px;
    color: var(--text-dim);
    font-size: 13px;
    pointer-events: none;
    transition: color 0.2s;
    top: 50%;
    transform: translateY(-50%);
    z-index: 1;
}

.input-wrapper:focus-within .input-icon { color: var(--accent); }

.input-wrapper input,
.input-wrapper textarea,
.input-wrapper select {
    width: 100%;
    padding: 11px 14px 11px 36px;
    background: var(--input);
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--text);
    font-size: 13px;
    font-family: 'Inter', sans-serif;
    transition: var(--transition);
    outline: none;
    -webkit-appearance: none;
}
/* Keep native calendar picker on date inputs */
.input-wrapper input[type="date"] {
    -webkit-appearance: auto;
    appearance: auto;
    color-scheme: var(--scheme);
}

.input-wrapper textarea {
    resize: none;
    padding-top: 11px;
    padding-left: 36px;
}

.input-wrapper input:focus,
.input-wrapper textarea:focus,
.input-wrapper select:focus {
    border-color: var(--accent);
    background: rgba(209,144,75,0.04);
    box-shadow: var(--shadow-accent);
}

/* Salary prefix */
.input-wrapper.has-prefix input { padding-left: 28px; }
.input-prefix {
    position: absolute;
    left: 12px;
    color: var(--accent-light);
    font-weight: 600;
    font-size: 13px;
    pointer-events: none;
    z-index: 1;
}

/* Toggle password */
.toggle-pass {
    position: absolute;
    right: 10px;
    background: none;
    border: none;
    color: var(--text-dim);
    cursor: pointer;
    padding: 4px 5px;
    font-size: 13px;
    transition: var(--transition);
    z-index: 2;
    line-height: 1;
}

.toggle-pass:hover { color: var(--accent); }

.input-wrapper input[type="password"],
.input-wrapper input.pass-has-toggle { padding-right: 36px; }
.confirm-pass-input { padding-right: 66px !important; }

/* Username status */
.username-status {
    position: absolute;
    right: 10px;
    font-size: 13px;
    z-index: 2;
    line-height: 1;
}

.username-status.checking { color: var(--text-dim); }
.username-status.available { color: var(--success); }
.username-status.taken { color: var(--danger); }

.field-hint { font-size: 11px; min-height: 16px; transition: var(--transition); }
.field-hint.ok { color: var(--success); }
.field-hint.err { color: var(--danger); }
.field-hint.warn { color: var(--text-muted); }

/* Match icon */
.match-icon {
    position: absolute;
    right: 36px;
    font-size: 13px;
    z-index: 2;
    line-height: 1;
}

.match-icon.ok { color: var(--success); }
.match-icon.err { color: var(--danger); }

/* Strength bars */
.strength-wrap { display: flex; align-items: center; gap: 8px; margin-top: 6px; }
.strength-bars { display: flex; gap: 4px; flex: 1; }

.bar {
    height: 3px;
    flex: 1;
    border-radius: 2px;
    background: var(--border);
    transition: background 0.3s ease;
}

.bar.s1 { background: var(--danger); }
.bar.s2 { background: var(--warning); }
.bar.s3 { background: #a3e635; }
.bar.s4 { background: var(--success); }

.strength-label { font-size: 11px; min-width: 38px; text-align: right; color: var(--text-muted); }

/* Generate password button */
.gen-pass-btn {
    float: right;
    font-size: 10px;
    font-family: inherit;
    padding: 3px 10px;
    background: rgba(209,144,75,0.08);
    border: 1px solid rgba(209,144,75,0.3);
    border-radius: 20px;
    color: var(--accent);
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.gen-pass-btn:hover { background: rgba(209,144,75,0.18); border-color: var(--accent); }

/* Copy password button */
.copy-pass-btn {
    position: absolute;
    right: 38px;
    background: none;
    border: none;
    color: var(--text-dim);
    cursor: pointer;
    padding: 4px 5px;
    font-size: 13px;
    transition: color 0.2s;
    line-height: 1;
    z-index: 2;
}
.copy-pass-btn:hover { color: var(--accent); }
.copy-pass-btn.copied { color: var(--success); }
/* Extra padding when copy button is visible */
.pass-has-copy { padding-right: 66px !important; }

/* Password requirement badges */
.req-badges { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 8px; }
.req-badge {
    font-size: 10px;
    padding: 3px 9px;
    border-radius: 20px;
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--border);
    color: var(--text-dim);
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.25s;
}
.req-badge.met {
    background: rgba(34,197,94,0.1);
    border-color: rgba(34,197,94,0.4);
    color: #4ade80;
}

/* Username suggestion chips */
.username-suggestions { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 6px; }
.un-chip {
    font-size: 10px;
    padding: 3px 10px;
    border-radius: 20px;
    background: rgba(209,144,75,0.07);
    border: 1px solid rgba(209,144,75,0.22);
    color: var(--accent-light);
    cursor: pointer;
    transition: all 0.2s;
}
.un-chip:hover { background: rgba(209,144,75,0.18); border-color: var(--accent); }

/* Char counter */
.char-counter { text-align: right; font-size: 11px; color: var(--text-dim); margin-top: 3px; }
.char-counter.warn { color: var(--warning); }

/* Role pills */
.role-pills { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }

.role-pill {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    padding: 14px 12px;
    border-radius: 10px;
    border: 1px solid var(--border);
    cursor: pointer;
    transition: var(--transition);
    text-align: center;
    background: var(--input);
    user-select: none;
}

.role-pill:hover { border-color: var(--border-hover); }

.role-pill.selected {
    border-color: var(--accent);
    background: rgba(209,144,75,0.07);
}

.role-pill i { font-size: 20px; color: var(--text-dim); transition: var(--transition); }
.role-pill.selected i { color: var(--accent); }

.role-pill .role-name { font-size: 12px; font-weight: 600; color: var(--text-muted); transition: var(--transition); }
.role-pill.selected .role-name { color: var(--accent-light); }
.role-pill small { font-size: 10px; color: var(--text-dim); }

/* Submit */
.submit-btn {
    width: 100%;
    padding: 14px;
    background: linear-gradient(135deg, var(--accent), var(--accent-light));
    border: none;
    border-radius: 12px;
    color: #000;
    font-size: 14px;
    font-weight: 700;
    font-family: 'Inter', sans-serif;
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    overflow: hidden;
}

.submit-btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-accent); filter: brightness(1.1); }
.submit-btn:active { transform: translateY(0); }
.submit-btn.loading { opacity: 0.7; pointer-events: none; }

.shortcut-hint { text-align: center; font-size: 11px; color: var(--text-dim); margin-top: 8px; }
kbd {
    display: inline-block;
    padding: 1px 5px;
    border-radius: 4px;
    border: 1px solid var(--border);
    font-size: 10px;
    font-family: 'Inter', sans-serif;
    color: var(--text-muted);
    background: var(--bg-card);
}

/* ── ANIMATIONS ── */
@keyframes fadeDown  { from{opacity:0;transform:translateY(-16px)} to{opacity:1;transform:translateY(0)} }
@keyframes slideLeft { from{opacity:0;transform:translateX(-24px)} to{opacity:1;transform:translateX(0)} }
@keyframes slideRight{ from{opacity:0;transform:translateX(24px)}  to{opacity:1;transform:translateX(0)} }
@keyframes fadeUp    { from{opacity:0;transform:translateY(20px)}  to{opacity:1;transform:translateY(0)} }
@keyframes shimmer   { 0%{background-position:-200% center} 100%{background-position:200% center} }
@keyframes floatA    { 0%,100%{transform:translate(0,0)} 50%{transform:translate(-24px,18px)} }
@keyframes floatB    { 0%,100%{transform:translate(0,0)} 50%{transform:translate(18px,-24px)} }

.top-nav      { animation: fadeDown  .45s ease both; }
.page-heading { animation: fadeUp    .4s  .08s ease both; }
.error-banner { animation: fadeUp    .35s .1s  ease both; }
.left-panel   { animation: slideLeft .5s  .12s ease both; }

.right-panel .section-card:nth-child(1) { animation: fadeUp .4s .18s ease both; }
.right-panel .section-card:nth-child(2) { animation: fadeUp .4s .28s ease both; }
.right-panel .submit-btn                { animation: fadeUp .4s .36s ease both; }
.right-panel .shortcut-hint             { animation: fadeUp .4s .42s ease both; }

.section-header { position: relative; }
.section-header::after {
    content: '';
    position: absolute; left: 0; right: 0; bottom: 0; height: 1px;
    background: linear-gradient(90deg, transparent, rgba(209,144,75,.25), transparent);
    background-size: 200% auto;
    animation: shimmer 3s linear infinite;
}

.orb {
    position: absolute; border-radius: 50%; filter: blur(90px);
    pointer-events: none; z-index: 0;
}
.orb-a {
    width: 420px; height: 420px;
    background: radial-gradient(circle, rgba(209,144,75,.15) 0%, transparent 70%);
    top: -120px; right: -100px;
    animation: floatA 9s ease-in-out infinite;
}
.orb-b {
    width: 300px; height: 300px;
    background: radial-gradient(circle, rgba(93,173,226,.1) 0%, transparent 70%);
    bottom: -80px; left: -80px;
    animation: floatB 11s ease-in-out infinite;
}
.page-wrapper { position: relative; z-index: 1; }

/* ── CUSTOM DROPDOWN ── */
.cdd-wrap { position: relative; }

.cdd-trigger {
    display: flex; align-items: center; gap: 10px;
    padding: 11px 14px;
    background: var(--input);
    border: 1px solid var(--border);
    border-radius: 10px;
    cursor: pointer;
    transition: var(--transition);
    user-select: none;
}
.cdd-trigger:hover,
.cdd-trigger:focus { border-color: var(--accent); outline: none; box-shadow: var(--shadow-accent); }
.cdd-trigger.open  { border-color: var(--accent); background: rgba(209,144,75,0.04); border-radius: 10px 10px 0 0; }

.cdd-trigger-icon { color: var(--text-dim); font-size: 13px; flex-shrink: 0; transition: color .2s; }
.cdd-trigger.open .cdd-trigger-icon,
.cdd-trigger.has-value .cdd-trigger-icon { color: var(--accent); }

.cdd-trigger-text { flex: 1; font-size: 13px; color: var(--text); }
.cdd-arrow { color: var(--text-dim); font-size: 11px; transition: transform .2s ease; flex-shrink: 0; }
.cdd-trigger.open .cdd-arrow { transform: rotate(180deg); color: var(--accent); }

.cdd-menu {
    display: none;
    position: absolute; top: 100%; left: 0; right: 0;
    background: var(--menu-bg);
    border: 1px solid var(--accent);
    border-top: none;
    border-radius: 0 0 12px 12px;
    overflow: hidden;
    z-index: 50;
    box-shadow: 0 12px 40px rgba(0,0,0,0.6);
    max-height: 280px; overflow-y: auto;
}
.cdd-menu.open { display: block; animation: cddOpen .18s ease both; }

@keyframes cddOpen { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }

.cdd-option {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 16px;
    font-size: 13px; color: var(--text-muted);
    cursor: pointer;
    transition: background .15s, color .15s;
    position: relative;
}
.cdd-option:hover { background: rgba(209,144,75,0.07); color: var(--text); }
.cdd-option.selected { background: rgba(209,144,75,0.1); color: var(--accent-light); }
.cdd-option:not(:last-child) { border-bottom: 1px solid rgba(255,255,255,0.03); }

.cdd-opt-icon { font-size: 13px; color: var(--text-dim); width: 16px; text-align: center; flex-shrink: 0; transition: color .15s; }
.cdd-option:hover .cdd-opt-icon,
.cdd-option.selected .cdd-opt-icon { color: var(--accent); }

.cdd-opt-check { margin-left: auto; font-size: 11px; color: var(--accent); }

.cdd-other { border-top: 1px solid rgba(209,144,75,0.12) !important; }
.cdd-other .cdd-opt-icon { color: var(--accent-dark); }

.cdd-custom-input {
    margin-top: 8px; width: 100%;
    padding: 10px 14px;
    background: var(--input); border: 1px solid var(--accent);
    border-radius: 10px; color: var(--text);
    font-size: 13px; font-family: 'Inter', sans-serif;
    outline: none; transition: var(--transition);
}
.cdd-custom-input:focus { box-shadow: var(--shadow-accent); }

/* scrollbar inside menu */
.cdd-menu::-webkit-scrollbar { width: 4px; }
.cdd-menu::-webkit-scrollbar-thumb { background: rgba(209,144,75,0.3); border-radius: 2px; }

/* Responsive */
@media (max-width: 760px) {
    .layout { grid-template-columns: 1fr; }
    .left-panel { position: static; flex-direction: row; flex-wrap: wrap; }
    .left-panel .panel-card { flex: 1; min-width: 220px; }
    .section-body { grid-template-columns: 1fr; }
    .full-width { grid-column: auto; }
    .role-pills { grid-template-columns: 1fr 1fr 1fr; }
    .page-wrapper { padding: 20px 16px 60px; }
    .top-nav { padding: 0 16px; }
}

@media (max-width: 480px) {
    .role-pills { grid-template-columns: 1fr 1fr; }
    .nav-back span { display: none; }
}
</style>
</head>
<body>
<div class="flex h-screen w-full overflow-hidden app-layout">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<main class="app-main flex-1 min-w-0 h-full overflow-y-auto overflow-x-hidden p-6 relative">
<?php 
$page_title    = __('add_employee', 'Add Employee'); 
$page_subtitle = 'Create a new employee profile and login account.'; 
require __DIR__ . '/header_bar.php'; 
?>
<div class="orb orb-a"></div>
<div class="orb orb-b"></div>

<div class="page-wrapper">

    <?php if (!empty($errors)): ?>
    <div class="error-banner">
        <div class="error-title"><i class="fa-solid fa-circle-exclamation"></i> Please fix the following:</div>
        <ul class="error-list">
            <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="addForm">
        <div class="layout">



            <!-- RIGHT: Form sections -->
            <div class="right-panel">

                <!-- Personal Information -->
                <div class="section-card">
                    <div class="section-header">
                        <i class="fa-solid fa-user"></i> Personal Information
                    </div>
                    <div class="section-body">
                        <div class="form-group full-width">
                            <label>Full Name <span class="req">*</span></label>
                            <div class="input-wrapper">
                                <i class="fa-solid fa-user input-icon"></i>
                                <input type="text" name="name" id="nameInput" placeholder="Enter full name"
                                    value="<?= htmlspecialchars($old['name']) ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Phone <span class="req">*</span></label>
                            <div class="input-wrapper">
                                <i class="fa-solid fa-phone input-icon"></i>
                                <input type="text" name="phone" placeholder="012 345 678"
                                    value="<?= htmlspecialchars($old['phone']) ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label><?= __('col_position', 'Position') ?> <span class="req">*</span></label>
                            <?php
                            // Position titles come directly from DB roles
                            $job_titles = array_map(fn($r) => ['value' => $r['name'], 'icon' => $r['icon'], 'slug' => $r['slug']], $_all_roles);
                            $old_job = $old['job'] ?? '';
                            $display_label = $old_job;
                            $display_icon  = '';
                            foreach ($job_titles as $jt) { if ($jt['value'] === $old_job) { $display_icon = $jt['icon']; break; } }
                            // Custom job title = a non-empty value not matching any known role
                            $is_other = $old_job !== '' && !in_array($old_job, array_column($job_titles, 'value'), true);
                            ?>
                            <div class="input-wrapper" style="position:relative;">
                                <i class="fa-solid fa-briefcase input-icon"></i>
                                <select name="job_title" id="jobSelect" required
                                    style="width:100%; padding:10px 36px 10px 38px; border-radius:10px; border:1px solid var(--border); background:var(--input); color:var(--text); font-size:13px; outline:none; appearance:none; -webkit-appearance:none; cursor:pointer;">
                                    <option value="" disabled <?= empty($old_job) ? 'selected' : '' ?>>— Select a position —</option>
                                    <?php foreach ($job_titles as $jt): ?>
                                    <option value="<?= htmlspecialchars($jt['value']) ?>" <?= ($old_job === $jt['value']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($jt['value']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="fa-solid fa-chevron-down" style="position:absolute; right:14px; color:var(--text-muted); font-size:11px; pointer-events:none;"></i>
                            </div>
                        </div>

                        <div class="form-group full-width">
                            <label>Monthly Salary <span class="req">*</span></label>
                            <div class="input-wrapper has-prefix">
                                <span class="input-prefix">$</span>
                                <input type="number" step="0.01" min="0" name="salary" placeholder="0.00"
                                    value="<?= htmlspecialchars($old['salary']) ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Date of Birth <span class="req">*</span></label>
                            <div class="input-wrapper">
                                <i class="fa-solid fa-calendar input-icon"></i>
                                <input type="date" name="date_of_birth"
                                    value="<?= htmlspecialchars($old['dob']) ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Hire Date <span class="req">*</span></label>
                            <div class="input-wrapper">
                                <i class="fa-solid fa-calendar-check input-icon"></i>
                                <input type="date" name="hire_date" id="hireDate"
                                    value="<?= htmlspecialchars($old['hire']) ?>" required>
                            </div>
                        </div>

                        <div class="form-group full-width">
                            <label>Address</label>
                            <div class="input-wrapper">
                                <i class="fa-solid fa-location-dot input-icon" style="top:14px;transform:none"></i>
                                <textarea name="address" id="addressInput" rows="2"
                                    placeholder="Enter address" maxlength="200"><?= htmlspecialchars($old['address']) ?></textarea>
                            </div>
                            <div class="char-counter"><span id="addrCount">0</span>/200</div>
                        </div>
                    </div>
                </div>



                <!-- Submit -->
                <button type="submit" class="submit-btn" id="submitBtn">
                    <i class="fa-solid fa-user-plus"></i> Add Employee
                </button>
                <div class="shortcut-hint"><kbd>Ctrl</kbd>+<kbd>Enter</kbd> to save</div>
            </div>
        </div>
    </form>
</div>

<script>
// ── Hire date default to today ──
(function() {
    const hd = document.getElementById('hireDate');
    if (hd && !hd.value) {
        const n = new Date();
        hd.value = n.getFullYear() + '-' +
            String(n.getMonth()+1).padStart(2,'0') + '-' +
            String(n.getDate()).padStart(2,'0');
    }
})();

// ── Photo upload & preview ──
const photoDrop    = document.getElementById('photoDrop');
const photoInput   = document.getElementById('photoInput');
const photoPreview = document.getElementById('photoPreview');
const photoPlaceholder = document.getElementById('photoPlaceholder');
const photoFilename = document.getElementById('photoFilename');
const photoNameText = document.getElementById('photoNameText');
const previewAvatar = document.getElementById('previewAvatar');

function handlePhoto(file) {
    if (!file || !file.type.startsWith('image/')) return;
    const reader = new FileReader();
    reader.onload = e => {
        photoPreview.src = e.target.result;
        photoPreview.style.display = 'block';
        photoPlaceholder.style.display = 'none';
        photoFilename.classList.add('has-file');
        photoNameText.textContent = file.name + ' (' + (file.size/1024).toFixed(0) + ' KB)';
        previewAvatar.innerHTML = `<img src="${e.target.result}" alt="">`;
    };
    reader.readAsDataURL(file);
}

photoInput.addEventListener('change', function() {
    if (this.files[0]) handlePhoto(this.files[0]);
});

photoDrop.addEventListener('dragover', e => { e.preventDefault(); photoDrop.classList.add('drag-over'); });
photoDrop.addEventListener('dragleave', () => photoDrop.classList.remove('drag-over'));
photoDrop.addEventListener('drop', e => {
    e.preventDefault();
    photoDrop.classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (file) {
        try {
            const dt = new DataTransfer();
            dt.items.add(file);
            photoInput.files = dt.files;
        } catch(err) {}
        handlePhoto(file);
    }
});

// ── Live preview: name & job ──
const nameInput  = document.getElementById('nameInput');
const previewName = document.getElementById('previewName');
const previewJob  = document.getElementById('previewJob');

function updateInitials() {
    if (previewAvatar.querySelector('img')) return;
    const n = nameInput.value.trim();
    if (n) {
        const parts = n.split(' ').filter(Boolean);
        const init = parts.length >= 2
            ? parts[0][0] + parts[parts.length-1][0]
            : parts[0].substring(0,2);
        previewAvatar.textContent = init.toUpperCase();
    } else {
        previewAvatar.textContent = '?';
    }
}

nameInput.addEventListener('input', function() {
    previewName.textContent = this.value.trim() || 'Full Name';
    updateInitials();
    updateUsernameSuggestions(this.value.trim());
});

function updateUsernameSuggestions(fullName) {
    const box = document.getElementById('usernameSuggestions');
    if (!fullName || usernameInput.value.trim()) { box.innerHTML = ''; return; }
    const parts = fullName.toLowerCase().replace(/[^a-z\s]/g, '').trim().split(/\s+/);
    if (parts.length < 1 || !parts[0]) { box.innerHTML = ''; return; }
    const first = parts[0];
    const last  = parts[1] || '';
    const suggestions = [];
    if (last) {
        suggestions.push(first + '.' + last);
        suggestions.push(first[0] + last);
        suggestions.push(first + last.charAt(0).toUpperCase() + last.slice(1));
    } else {
        suggestions.push(first);
        suggestions.push(first + Math.floor(10 + Math.random() * 90));
    }
    box.innerHTML = '<span style="font-size:10px;color:var(--text-dim);align-self:center">Suggestions:</span> ' +
        suggestions.map(s => `<span class="un-chip" onclick="pickUsername('${s}')">${s}</span>`).join('');
}

function pickUsername(val) {
    usernameInput.value = val;
    document.getElementById('usernameSuggestions').innerHTML = '';
    usernameInput.dispatchEvent(new Event('input'));
}

// ── Custom job-title dropdown ────────────────────────────────────────
var jobInput       = document.getElementById('jobInput');
var jobCustomInput = document.getElementById('jobCustomInput');
var cddTrigger     = document.getElementById('cddTrigger');
var cddMenu        = document.getElementById('cddMenu');
var cddArrow       = document.getElementById('cddArrow');
var cddTriggerText = document.getElementById('cddTriggerText');
var cddTriggerIcon = document.getElementById('cddTriggerIcon');

function cddOpen() {
    cddTrigger.classList.add('open');
    cddMenu.classList.add('open');
}
function cddClose() {
    cddTrigger.classList.remove('open');
    cddMenu.classList.remove('open');
}
function cddToggle() {
    cddTrigger.classList.contains('open') ? cddClose() : cddOpen();
}

cddTrigger.addEventListener('click', cddToggle);
cddTrigger.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); cddToggle(); }
    if (e.key === 'Escape') cddClose();
});

document.addEventListener('click', function(e) {
    if (!document.getElementById('cddWrap').contains(e.target)) cddClose();
});

document.querySelectorAll('.cdd-option').forEach(function(opt) {
    opt.addEventListener('click', function() {
        var val  = this.dataset.value;
        var icon = this.dataset.icon;
        var label = this.querySelector('span').textContent;

        // Clear all checks
        document.querySelectorAll('.cdd-option').forEach(function(o) {
            o.classList.remove('selected');
            var chk = o.querySelector('.cdd-opt-check');
            if (chk) chk.remove();
        });
        this.classList.add('selected');
        var chk = document.createElement('i');
        chk.className = 'fa-solid fa-check cdd-opt-check';
        this.appendChild(chk);

        // "Other" → reveal the free-text field so any custom position can be typed.
        if (val === '__other__') {
            jobInput.value = '__other__';
            jobInput.setAttribute('required', '');
            cddTriggerText.textContent = 'Custom position';
            cddTriggerIcon.className = 'fa-solid fa-plus cdd-trigger-icon';
            cddTrigger.classList.add('has-value');
            jobCustomInput.style.display = '';
            jobCustomInput.required = true;
            jobCustomInput.value = '';
            previewJob.textContent = 'Custom';
            cddClose();
            jobCustomInput.focus();
            return;
        }

        jobInput.value = val;
        jobInput.setAttribute('required', '');
        cddTriggerText.textContent = label;
        cddTriggerIcon.className = 'fa-solid ' + icon + ' cdd-trigger-icon';
        cddTrigger.classList.add('has-value');
        jobCustomInput.style.display = 'none';
        jobCustomInput.required = false;
        jobCustomInput.value = '';
        previewJob.textContent = label;

        // Auto-select matching role
        if (!_syncingFromRole) {
            var slug = this.dataset.slug || _jobToRole[val];
            if (slug) {
                _syncingFromJob = true;
                selectRole(slug);
                _syncingFromJob = false;
            }
        }

        cddClose();
    });
});

// Live-preview the custom job title as it's typed.
jobCustomInput.addEventListener('input', function() {
    previewJob.textContent = this.value.trim() || 'Job Title';
});

// ── Address char counter ──
const addressInput = document.getElementById('addressInput');
const addrCount    = document.getElementById('addrCount');

function updateAddr() {
    const len = addressInput.value.length;
    addrCount.textContent = len;
    const counter = addressInput.closest('.form-group').querySelector('.char-counter');
    counter.classList.toggle('warn', len > 170);
}

addressInput.addEventListener('input', updateAddr);
updateAddr();

// ── Role pills (dynamic) ──
var _roleLabels = <?= json_encode(array_column($_all_roles, 'name', 'slug')) ?>;

// Role ↔ Job Title sync maps (generated from DB roles)
var _roleToJob = <?= json_encode(array_column($_all_roles, 'name', 'slug')) ?>;
var _jobToRole = <?= json_encode(array_column($_all_roles, 'slug', 'name')) ?>;

var _syncingFromRole = false;
var _syncingFromJob  = false;

function cddSelectByValue(value) {
    var opt = document.querySelector('.cdd-option[data-value="' + CSS.escape(value) + '"]');
    if (!opt) return;
    var icon  = opt.dataset.icon;
    var label = opt.querySelector('span') ? opt.querySelector('span').textContent : value;
    document.querySelectorAll('.cdd-option').forEach(function(o) {
        o.classList.remove('selected');
        var chk = o.querySelector('.cdd-opt-check');
        if (chk) chk.remove();
    });
    opt.classList.add('selected');
    var chk = document.createElement('i');
    chk.className = 'fa-solid fa-check cdd-opt-check';
    opt.appendChild(chk);
    jobInput.value = value;
    jobInput.setAttribute('required', '');
    cddTriggerText.textContent = label;
    cddTriggerIcon.className = 'fa-solid ' + icon + ' cdd-trigger-icon';
    cddTrigger.classList.add('has-value');
    jobCustomInput.style.display = 'none';
    jobCustomInput.required = false;
    jobCustomInput.value = '';
    previewJob.textContent = label;
}

// ── POS access toggle: show/hide the login+role section for display-only staff ──
function togglePOS() {
    var on = document.getElementById('posToggle').checked;
    var sec = document.getElementById('posSection');
    if (sec) sec.style.display = on ? '' : 'none';
    ['usernameInput', 'passInput', 'confirmPass'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) { el.required = on; el.disabled = !on; }
    });
    // Job-title options follow the toggle: POS staff pick a role-based title
    // (Admin/Manager/Cashier…) which also sets their role; display-only staff
    // pick a general one (Cleaner/Waiter…) and get no role.
    var clearedSelected = false;
    document.querySelectorAll('.cdd-option').forEach(function(opt) {
        var isRole = (opt.getAttribute('data-slug') || '') !== '';
        var show   = on ? isRole : !isRole;
        opt.style.display = show ? '' : 'none';
        if (!show && opt.classList.contains('selected')) clearedSelected = true;
    });
    // If the chosen job title is no longer valid for this mode, reset it.
    if (clearedSelected) {
        document.querySelectorAll('.cdd-option').forEach(function(o) {
            o.classList.remove('selected');
            var chk = o.querySelector('.cdd-opt-check'); if (chk) chk.remove();
        });
        var ji = document.getElementById('jobInput'); if (ji) ji.value = '';
        var tt = document.getElementById('cddTriggerText');
        if (tt) tt.innerHTML = '<span style="color:var(--text-dim)">— Select a position —</span>';
        var ti = document.getElementById('cddTriggerIcon');
        if (ti) ti.className = 'fa-solid fa-briefcase cdd-trigger-icon';
        var jc = document.getElementById('jobCustomInput');
        if (jc) { jc.style.display = 'none'; jc.required = false; jc.value = ''; }
    }
}
document.addEventListener('DOMContentLoaded', togglePOS);

function selectRole(role) {
    document.querySelectorAll('.role-pill').forEach(function(p) { p.classList.remove('selected'); });
    var pill = document.getElementById('pill_' + role);
    if (pill) pill.classList.add('selected');
    var radio = document.getElementById('role_' + role);
    if (radio) radio.checked = true;
    var preview = document.getElementById('previewRole');
    if (preview) {
        preview.textContent = _roleLabels[role] || role;
        preview.classList.toggle('admin', role === 'admin' || role === 'manager');
    }
    // Auto-select matching job title (skip if this was triggered by a job title click)
    if (!_syncingFromJob && _roleToJob[role]) {
        _syncingFromRole = true;
        cddSelectByValue(_roleToJob[role]);
        _syncingFromRole = false;
    }
}

// ── Password toggle ──
function togglePass(id, btn) {
    const inp = document.getElementById(id);
    const icon = btn.querySelector('i');
    const showing = inp.type === 'text';
    inp.type = showing ? 'password' : 'text';
    icon.className = showing ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
}

// ── Password strength ──
const passInput = document.getElementById('passInput');
const bars = ['bar1','bar2','bar3','bar4'].map(id => document.getElementById(id));
const strengthLabel = document.getElementById('strengthLabel');
const levels = ['','Weak','Fair','Good','Strong'];
const barClass = ['','s1','s2','s3','s4'];

function calcStrength(p) {
    if (!p) return 0;
    let s = 0;
    if (p.length >= 8) s++;
    if (/[A-Z]/.test(p)) s++;
    if (/[0-9]/.test(p)) s++;
    if (/[^A-Za-z0-9]/.test(p)) s++;
    return Math.min(4, Math.max(1, s));
}

function updateReqBadges(p) {
    const reqs = [
        { id: 'req-len',   met: p.length >= 8 },
        { id: 'req-upper', met: /[A-Z]/.test(p) },
        { id: 'req-num',   met: /[0-9]/.test(p) },
        { id: 'req-sym',   met: /[^A-Za-z0-9]/.test(p) },
    ];
    reqs.forEach(r => {
        const el = document.getElementById(r.id);
        el.classList.toggle('met', r.met);
        el.querySelector('i').className = r.met ? 'fa-solid fa-check' : 'fa-solid fa-xmark';
    });
}

function generatePassword() {
    const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const lower = 'abcdefghjkmnpqrstuvwxyz';
    const nums  = '23456789';
    const syms  = '!@#$%&*?';
    const pick  = (str) => str[Math.floor(Math.random() * str.length)];
    let chars   = [pick(upper), pick(upper), pick(lower), pick(lower),
                   pick(lower), pick(lower), pick(nums), pick(nums), pick(syms), pick(syms)];
    const pass  = chars.sort(() => Math.random() - 0.5).join('');
    passInput.value = pass;
    passInput.type  = 'text';
    document.querySelector('[onclick="togglePass(\'passInput\',this)"] i').className = 'fa-solid fa-eye';
    document.getElementById('confirmPass').value = pass;
    document.getElementById('copyPassBtn').style.display = '';
    passInput.classList.add('pass-has-copy');
    passInput.dispatchEvent(new Event('input'));
    checkMatch();
}

function copyPassword() {
    const btn = document.getElementById('copyPassBtn');
    navigator.clipboard.writeText(passInput.value).then(() => {
        btn.classList.add('copied');
        btn.innerHTML = '<i class="fa-solid fa-check"></i>';
        setTimeout(() => {
            btn.classList.remove('copied');
            btn.innerHTML = '<i class="fa-solid fa-copy"></i>';
        }, 1800);
    });
}

passInput.addEventListener('input', function() {
    const s = this.value ? calcStrength(this.value) : 0;
    bars.forEach((b, i) => { b.className = 'bar' + (i < s ? ' ' + barClass[s] : ''); });
    strengthLabel.textContent = levels[s] || '';
    updateReqBadges(this.value);
    if (!this.value) {
        document.getElementById('copyPassBtn').style.display = 'none';
        passInput.classList.remove('pass-has-copy');
    }
    checkMatch();
});

// ── Confirm password ──
const confirmPass = document.getElementById('confirmPass');
const matchIcon   = document.getElementById('matchIcon');

function checkMatch() {
    if (!confirmPass.value) { matchIcon.className = 'match-icon'; matchIcon.innerHTML = ''; return; }
    const ok = passInput.value === confirmPass.value;
    matchIcon.className = 'match-icon ' + (ok ? 'ok' : 'err');
    matchIcon.innerHTML = ok
        ? '<i class="fa-solid fa-check-circle"></i>'
        : '<i class="fa-solid fa-times-circle"></i>';
}

confirmPass.addEventListener('input', checkMatch);

// ── Live username availability ──
const usernameInput  = document.getElementById('usernameInput');
const usernameStatus = document.getElementById('usernameStatus');
const usernameHint   = document.getElementById('usernameHint');
let unTimer = null;

usernameInput.addEventListener('input', function() {
    const val = this.value.trim();
    clearTimeout(unTimer);
    usernameStatus.innerHTML = '';
    usernameStatus.className = 'username-status';
    usernameHint.className = 'field-hint';
    usernameHint.textContent = '';

    if (val.length < 3) {
        if (val.length > 0) {
            usernameHint.className = 'field-hint err';
            usernameHint.textContent = 'Minimum 3 characters';
        }
        return;
    }

    usernameStatus.className = 'username-status checking';
    usernameStatus.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    usernameHint.className = 'field-hint warn';
    usernameHint.textContent = 'Checking…';

    unTimer = setTimeout(() => {
        fetch('?check_username=' + encodeURIComponent(val))
            .then(r => r.json())
            .then(data => {
                if (data.available) {
                    usernameStatus.className = 'username-status available';
                    usernameStatus.innerHTML = '<i class="fa-solid fa-check-circle"></i>';
                    usernameHint.className = 'field-hint ok';
                    usernameHint.textContent = 'Username available';
                } else {
                    usernameStatus.className = 'username-status taken';
                    usernameStatus.innerHTML = '<i class="fa-solid fa-times-circle"></i>';
                    usernameHint.className = 'field-hint err';
                    usernameHint.textContent = data.reason === 'min3' ? 'Minimum 3 characters' : 'Username already taken';
                }
            })
            .catch(() => {
                usernameStatus.className = 'username-status';
                usernameStatus.innerHTML = '';
                usernameHint.textContent = '';
            });
    }, 420);
});

// ── Ctrl+Enter submit ──
document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        document.getElementById('addForm').requestSubmit();
    }
});

// ── Submit: loading state ──
document.getElementById('addForm').addEventListener('submit', function() {
    const btn = document.getElementById('submitBtn');
    btn.classList.add('loading');
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
});

// follows shared theme key (toggled elsewhere)
window.addEventListener('storage', function (e) {
    if (e.key === 'theme') {
        if (e.newValue === 'light') document.documentElement.setAttribute('data-theme', 'light');
        else document.documentElement.removeAttribute('data-theme');
    }
});
</script>
</main>
</div>
</body>
</html>
