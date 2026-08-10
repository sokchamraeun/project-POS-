<?php
require 'auth.php';
require 'config.php';

$user_id  = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';
$role     = $_SESSION['role']     ?? 'staff';

// Load current user record
$stmt = $conn->prepare("SELECT username, security_question, must_change_password, must_set_security FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Resolve display name, color, icon for role from DB
$rn = $conn->prepare("SELECT name, color, icon FROM roles WHERE slug = ?");
$rn->bind_param("s", $role);
$rn->execute();
$rn_row       = $rn->get_result()->fetch_assoc();
$role_display = $rn_row['name']  ?? ucfirst(str_replace('_', ' ', $role));
$role_color   = $rn_row['color'] ?? '#888888';
$role_icon    = $rn_row['icon']  ?? 'fa-user';

$toast      = '';
$toast_type = '';
$active_tab = $_POST['active_tab'] ?? 'password';

// ── Handle POST ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Change password
    if ($action === 'change_password') {
        $active_tab  = 'password';
        $current     = $_POST['current_password']  ?? '';
        $new_pass    = $_POST['new_password']       ?? '';
        $confirm     = $_POST['confirm_password']   ?? '';

        $stmt2 = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
        $stmt2->bind_param("i", $user_id);
        $stmt2->execute();
        $row = $stmt2->get_result()->fetch_assoc();

        if (!password_verify($current, $row['password'])) {
            $toast = "Current password is incorrect.";
            $toast_type = 'error';
        } elseif ($new_pass !== $confirm) {
            $toast = "New passwords do not match.";
            $toast_type = 'error';
        } elseif (strlen($new_pass) < 8
               || !preg_match('/[A-Z]/', $new_pass)
               || !preg_match('/[0-9]/', $new_pass)
               || !preg_match('/[^a-zA-Z0-9]/', $new_pass)) {
            $toast = "Password must be at least 8 characters and include an uppercase letter, a number, and a symbol.";
            $toast_type = 'error';
        } else {
            $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt3  = $conn->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE user_id = ?");
            $stmt3->bind_param("si", $hashed, $user_id);
            $stmt3->execute();
            $toast = "Password updated successfully!";
            $toast_type = 'success';
            $user['must_change_password'] = 0;
        }
    }

    // Set / update security question
    elseif ($action === 'save_security') {
        $active_tab = 'security';
        $question   = trim($_POST['security_question'] ?? '');
        $answer     = strtolower(trim($_POST['security_answer'] ?? ''));
        $verify_pass = $_POST['verify_password'] ?? '';

        $stmt2 = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
        $stmt2->bind_param("i", $user_id);
        $stmt2->execute();
        $row = $stmt2->get_result()->fetch_assoc();

        if (!password_verify($verify_pass, $row['password'])) {
            $toast = "Password verification failed.";
            $toast_type = 'error';
        } elseif (empty($question) || strlen($answer) < 2) {
            $toast = "Please enter both a question and an answer.";
            $toast_type = 'error';
        } else {
            $hashed_ans = password_hash($answer, PASSWORD_DEFAULT);
            $stmt3 = $conn->prepare("UPDATE users SET security_question = ?, security_answer = ?, must_set_security = 0 WHERE user_id = ?");
            $stmt3->bind_param("ssi", $question, $hashed_ans, $user_id);
            $stmt3->execute();
            $toast = "Security question saved successfully!";
            $toast_type = 'success';
            $user['security_question']  = $question;
            $user['must_set_security']  = 0;
        }
    }
}

$must_change = (bool)($user['must_change_password'] ?? 0);
$has_sq      = !empty($user['security_question']);
$must_set_sec = !empty($user['must_set_security']) && !$has_sq;
$home_url = ($role === 'barista') ? 'view_order.php' : 'dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.tailwindcss.com"></script>
<script>(function(){try{if(localStorage.getItem("theme")==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
<style>
:root {
    --accent:       #d1904b;
    --accent-light: #e8b87a;
    --accent-dark:  #a0702a;
    --bg:           #0b0b0b;
    --card:         #111;
    --card2:        #161616;
    --border:       rgba(255,255,255,0.07);
    --text:         #f5f5f5;
    --text-muted:   #777;
    --danger:       #e74c3c;
    --success:      #55e087;
    --warning:      #f39c12;
}
@keyframes fadeInUp { from{opacity:0;transform:translateY(18px)} to{opacity:1;transform:translateY(0)} }
@keyframes scaleIn  { from{opacity:0;transform:scale(.94)}       to{opacity:1;transform:scale(1)}     }
@keyframes fadeIn   { from{opacity:0}                             to{opacity:1}                        }

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Poppins', sans-serif;
    background: radial-gradient(ellipse 80% 40% at 50% 0%,rgba(209,144,75,.07) 0%,transparent 100%),var(--bg);
    color: var(--text);
    min-height: 100vh;
}

/* ── TOPBAR ── */
.topbar {
    position: sticky; top: 0; z-index: 100;
    background: rgba(10,10,10,0.95);
    border-bottom: 1px solid var(--border);
    backdrop-filter: blur(12px);
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 28px; height: 62px;
    animation: fadeIn .35s ease both;
}
.topbar-left { display:flex; align-items:center; gap:16px; }
.back-btn {
    display:inline-flex; align-items:center; gap:7px;
    text-decoration:none; color:#d1904b;
    font-size:13px; font-weight:600;
    padding:7px 14px; border-radius:10px;
    border:1px solid rgba(209,144,75,.35); background:rgba(209,144,75,.08);
    transition:all .2s ease;
}
.back-btn:hover { background:rgba(209,144,75,.16); border-color:#d1904b; }
.page-title { font-size:15px; font-weight:700; color:var(--text); }
.page-title span { color:var(--accent); }
.topbar-right { display:flex; align-items:center; gap:10px; }
.user-chip {
    display:flex; align-items:center; gap:8px;
    padding:5px 12px 5px 5px;
    background:rgba(255,255,255,0.04);
    border:1px solid var(--border);
    border-radius:20px;
}
.user-avatar {
    width:30px; height:30px; border-radius:50%;
    background:linear-gradient(135deg,var(--accent),var(--accent-dark));
    display:flex; align-items:center; justify-content:center;
    font-size:12px; color:#fff; font-weight:700;
}
.user-name  { font-size:12.5px; font-weight:600; color:var(--text); }
.user-role  { font-size:10px; color:var(--accent); font-weight:500; text-transform:capitalize; }

/* ── LAYOUT ── */
.page-wrap {
    max-width: 820px; margin: 0 auto;
    padding: 32px 20px 60px;
}

/* ── BANNER (must change) ── */
.must-change-banner {
    display:flex; align-items:center; gap:14px;
    padding:16px 20px; border-radius:14px;
    background:rgba(243,156,18,0.08);
    border:1px solid rgba(243,156,18,0.25);
    margin-bottom:24px;
    animation:fadeInUp .4s ease .05s both;
}
.must-change-banner i { color:var(--warning); font-size:20px; flex-shrink:0; }
.must-change-banner strong { display:block; font-size:14px; color:var(--text); margin-bottom:2px; }
.must-change-banner p { font-size:12.5px; color:var(--text-muted); }

/* Security tip banner */
.sq-missing-banner {
    display:flex; align-items:flex-start; gap:12px;
    padding:14px 18px; border-radius:12px;
    background:rgba(209,144,75,0.06); border:1px solid rgba(209,144,75,0.2);
    margin-bottom:22px;
    animation:fadeInUp .4s ease .1s both;
}
.sq-missing-banner i { color:var(--accent); margin-top:2px; flex-shrink:0; }
.sq-missing-banner span { font-size:13px; color:var(--accent-light); line-height:1.5; }

/* ── CARD ── */
.card {
    background:var(--card);
    border:1px solid var(--border);
    border-radius:18px;
    overflow:hidden;
    margin-bottom:20px;
    animation:fadeInUp .45s cubic-bezier(.16,1,.3,1) both;
    transition:border-color .2s;
}
.card:hover { border-color:rgba(255,255,255,.11); }
.card:nth-of-type(1){ animation-delay:.14s }
.card:nth-of-type(2){ animation-delay:.22s }
.card:nth-of-type(3){ animation-delay:.30s }
.card-header {
    padding:20px 24px 0;
    display:flex; align-items:center; gap:14px;
}
.card-icon {
    width:42px; height:42px; border-radius:12px;
    background:linear-gradient(135deg,rgba(209,144,75,0.2),rgba(209,144,75,0.06));
    border:1px solid rgba(209,144,75,0.2);
    display:flex; align-items:center; justify-content:center;
    color:var(--accent); font-size:17px; flex-shrink:0;
}
.card-title    { font-size:16px; font-weight:700; color:var(--text); }
.card-subtitle { font-size:12px; color:var(--text-muted); margin-top:2px; }
.card-body { padding:22px 24px; }
.card-divider { height:1px; background:var(--border); margin:0; }

/* ── FORM FIELDS ── */
.field-row { display:grid; grid-template-columns:1fr; gap:14px; margin-bottom:14px; }
.field-row.two { grid-template-columns:1fr 1fr; }
.field-group { position:relative; }
.fl-label {
    display:block; font-size:11px; font-weight:600;
    color:var(--text-muted); text-transform:uppercase;
    letter-spacing:.5px; margin-bottom:6px;
}
.fl-input {
    width:100%; height:48px;
    padding:0 44px 0 16px;
    border-radius:10px;
    border:1px solid rgba(255,255,255,0.09);
    background:rgba(255,255,255,0.03);
    color:var(--text);
    font-size:14px; font-family:'Poppins',sans-serif;
    outline:none;
    transition:border-color .2s, box-shadow .2s, background .2s;
}
.fl-input:focus {
    border-color:rgba(209,144,75,0.45);
    background:rgba(209,144,75,0.04);
    box-shadow:0 0 0 3px rgba(209,144,75,0.08);
}
.fl-input.select-input { padding-right:16px; cursor:pointer; }
.fl-input.select-input option { background:#1a1a1a; }
.input-wrap { position:relative; }
.fl-toggle {
    position:absolute; right:12px; top:50%; transform:translateY(-50%);
    background:none; border:none;
    color:var(--text-muted); font-size:14px;
    cursor:pointer; padding:4px; transition:color .2s;
}
.fl-toggle:hover { color:var(--text); }
.fl-hint { font-size:11.5px; color:var(--text-muted); margin-top:5px; }

/* Strength meter */
.strength-wrap { margin-top:8px; }
.s-bars { display:flex; gap:4px; margin-bottom:5px; }
.s-bar  { flex:1; height:4px; border-radius:4px; background:rgba(255,255,255,0.08); transition:background .3s; }
.s-text { font-size:11px; font-weight:600; color:var(--text-muted); transition:color .3s; }
.s-reqs { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
.req {
    display:flex; align-items:center; gap:5px;
    font-size:10.5px; color:var(--text-muted);
    padding:3px 8px; border-radius:20px;
    border:1px solid rgba(255,255,255,0.07);
    background:rgba(255,255,255,0.02);
    transition:all .25s;
}
.req.met { color:var(--success); border-color:rgba(85,224,135,0.25); background:rgba(85,224,135,0.07); }
.req i   { font-size:9px; }

/* Match hint */
.match-hint { font-size:11.5px; margin-top:5px; min-height:18px; }

/* Submit btn */
.btn-primary {
    display:inline-flex; align-items:center; gap:8px;
    padding:0 22px; height:46px; border:none; border-radius:10px;
    background:linear-gradient(135deg,var(--accent),var(--accent-dark));
    color:#fff; font-family:'Poppins',sans-serif;
    font-size:14px; font-weight:600; cursor:pointer;
    box-shadow:0 3px 14px rgba(209,144,75,0.3);
    transition:transform .2s, box-shadow .2s;
}
.btn-primary:hover { transform:translateY(-1px); box-shadow:0 6px 22px rgba(209,144,75,0.4); }
.btn-primary:disabled { opacity:.5; cursor:not-allowed; transform:none; }

/* Status badge */
.badge {
    display:inline-flex; align-items:center; gap:5px;
    padding:3px 10px; border-radius:20px;
    font-size:11px; font-weight:600;
}
.badge-ok      { background:rgba(85,224,135,0.12); color:var(--success); border:1px solid rgba(85,224,135,0.25); }
.badge-missing { background:rgba(243,156,18,0.12); color:var(--warning); border:1px solid rgba(243,156,18,0.25); }

/* Profile summary card */
.profile-summary {
    display:flex; align-items:center; gap:20px;
    padding:22px 24px;
    animation:scaleIn .45s cubic-bezier(.16,1,.3,1) .12s both;
}
.profile-summary .profile-avatar {
    width:68px; height:68px; border-radius:18px; flex-shrink:0;
    background:linear-gradient(135deg,var(--accent),var(--accent-dark));
    display:flex; align-items:center; justify-content:center;
    font-size:26px; color:#fff; font-weight:800;
    box-shadow:0 4px 20px rgba(209,144,75,0.35);
}
.profile-info { flex:1; }
.profile-name { font-size:20px; font-weight:800; color:var(--text); margin-bottom:4px; }
.profile-badges { display:flex; gap:7px; flex-wrap:wrap; }
.profile-stats {
    display:grid; grid-template-columns:repeat(3,1fr);
    gap:1px; background:var(--border);
    border-top:1px solid var(--border);
}
.pstat {
    padding:14px 20px;
    background:var(--card);
    display:flex; flex-direction:column; gap:3px;
}
.pstat-val { font-size:16px; font-weight:700; color:var(--accent); }
.pstat-lbl { font-size:11px; color:var(--text-muted); }

/* Toast */
.toast {
    position:fixed; bottom:28px; right:28px; z-index:9999;
    display:flex; align-items:center; gap:12px;
    padding:14px 20px; border-radius:14px;
    font-size:13.5px; font-weight:500;
    box-shadow:0 8px 32px rgba(0,0,0,0.5);
    animation:toastIn .4s ease both;
    min-width:260px; max-width:380px;
}
.toast.success { background:#1a3a28; border:1px solid rgba(85,224,135,0.3); color:var(--success); }
.toast.error   { background:#3a1a1a; border:1px solid rgba(231,76,60,0.3);  color:#e87070; }
.toast i       { font-size:16px; flex-shrink:0; }
@keyframes toastIn  { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }
@keyframes toastOut { from{opacity:1;transform:translateY(0)} to{opacity:0;transform:translateY(12px)} }

@media(max-width:600px) {
    .field-row.two { grid-template-columns:1fr; }
    .profile-stats { grid-template-columns:1fr 1fr; }
    .page-wrap { padding:20px 14px 50px; }
}
/* Light theme (follows shared localStorage theme) */
[data-theme="light"]{--bg:#ECEEF2;--card:#FFFFFF;--card2:#F5F7FA;--border:#E2E5EA;--text:#111827;--text-muted:#5A6373;}
[data-theme="light"] .topbar{background:rgba(255,255,255,.92);}
</style>
</head>
<body>
<div class="flex h-screen w-screen overflow-hidden bg-[#0e0e10] app-layout">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<div class="app-main flex-1 h-full overflow-y-auto">

<!-- ── TOPBAR ── -->
<div class="topbar" style="display:flex;align-items:center;justify-content:space-between;padding:16px 24px;border-bottom:1px solid rgba(255,255,255,0.06);background:rgba(18,18,21,0.5);backdrop-filter:blur(12px);">
    <div class="topbar-left" style="display:flex;align-items:center;gap:12px;">
        <h1 class="page-title" style="font-size:22px;font-weight:800;color:var(--accent,#d1904b);display:flex;align-items:center;gap:10px;margin:0;">
            <i class="fa-solid fa-circle-user"></i> My Profile
        </h1>
    </div>
    <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
        <div class="user-chip flex items-center gap-2.5 bg-[#18181c] p-2.5 px-3.5 rounded-xl border border-[#24242b]" style="display:flex;align-items:center;gap:10px;background:#18181c;padding:8px 14px;border-radius:12px;border:1px solid #24242b;">
            <div class="w-8 h-8 rounded-full bg-[#d1904b]/20 text-[#d1904b] font-bold flex items-center justify-center text-xs flex-shrink-0" style="width:32px;height:32px;border-radius:50%;background:rgba(209,144,75,0.2);color:#d1904b;font-weight:700;display:flex;align-items:center;justify-content:center;">
                <?php $__ph = current_user_photo($conn); if ($__ph): ?><img src="<?= htmlspecialchars($__ph) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;display:block"><?php else: ?><?= strtoupper(substr($username, 0, 1)) ?><?php endif; ?>
            </div>
            <div>
                <div class="user-name" style="font-size:13px;font-weight:600;color:#fff;"><?= htmlspecialchars($username) ?></div>
                <div class="user-role" style="font-size:11px;color:#888;"><?= htmlspecialchars($role_display) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="page-wrap">

    <!-- Must-change warning -->
    <?php if ($must_change): ?>
    <div class="must-change-banner">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>
            <strong>Password change required</strong>
            <p>Your account requires a new password before you can access the system.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Security question required by manager -->
    <?php if ($must_set_sec): ?>
    <div class="sq-missing-banner" style="border-color:rgba(243,156,18,.35);background:rgba(243,156,18,.08)">
        <i class="fa-solid fa-user-shield" style="color:var(--warning)"></i>
        <span style="color:var(--text)">
            <strong>Your manager asked you to set a security question</strong> so you can recover your own password if you forget it. Please set one below.
            &nbsp;<a href="<?= $home_url ?>" style="color:var(--text-muted);text-decoration:underline">Remind me later</a>
        </span>
    </div>
    <?php elseif (!$has_sq): ?>
    <div class="sq-missing-banner">
        <i class="fa-solid fa-shield-exclamation"></i>
        <span><strong>No security question set.</strong> Set one up below so you can recover your account if you ever forget your password — without needing to contact an admin.</span>
    </div>
    <?php endif; ?>

    <!-- ── Profile Summary ── -->
    <div class="card" style="margin-bottom:24px">
        <div class="profile-summary">
            <div class="profile-avatar"><?php $__ph = current_user_photo($conn); if ($__ph): ?><img src="<?= htmlspecialchars($__ph) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;display:block"><?php else: ?><?= strtoupper(substr($username, 0, 1)) ?><?php endif; ?></div>
            <div class="profile-info">
                <div class="profile-name"><?= htmlspecialchars($username) ?></div>
                <div class="profile-badges">
                    <span class="badge" style="background:<?= $role_color ?>22;color:<?= $role_color ?>;border:1px solid <?= $role_color ?>44">
                        <i class="fa-solid <?= htmlspecialchars($role_icon) ?>"></i>
                        <?= htmlspecialchars($role_display) ?>
                    </span>
                    <span class="badge <?= $has_sq ? 'badge-ok' : 'badge-missing' ?>">
                        <i class="fa-solid fa-<?= $has_sq ? 'shield-check' : 'shield-exclamation' ?>"></i>
                        Security Q: <?= $has_sq ? 'Set' : 'Not Set' ?>
                    </span>
                </div>
            </div>
        </div>
        <div class="card-divider"></div>
        <div class="profile-stats">
            <div class="pstat">
                <span class="pstat-val"><?= htmlspecialchars($username) ?></span>
                <span class="pstat-lbl">Username</span>
            </div>
            <div class="pstat">
                <span class="pstat-val"><?= htmlspecialchars($role_display) ?></span>
                <span class="pstat-lbl">Role</span>
            </div>
            <div class="pstat">
                <span class="pstat-val"><?= $has_sq ? 'Active' : 'Not set' ?></span>
                <span class="pstat-lbl">Account Recovery</span>
            </div>
        </div>
    </div>

    <!-- ── Change Password ── -->
    <div class="card">
        <div class="card-header">
            <div class="card-icon"><i class="fa-solid fa-key"></i></div>
            <div>
                <div class="card-title">Change Password</div>
                <div class="card-subtitle">Use a strong password to keep your account secure</div>
            </div>
        </div>
        <div class="card-body">
            <form method="POST" id="passForm">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="active_tab" value="password">

                <div class="field-row">
                    <div class="field-group">
                        <label class="fl-label">Current Password</label>
                        <div class="input-wrap">
                            <input type="password" name="current_password" id="currentPass" class="fl-input" required autocomplete="current-password">
                            <button type="button" class="fl-toggle" data-target="currentPass"><i class="fa-solid fa-eye-slash"></i></button>
                        </div>
                    </div>
                </div>

                <div class="field-row two">
                    <div class="field-group">
                        <label class="fl-label">New Password</label>
                        <div class="input-wrap">
                            <input type="password" name="new_password" id="newPass" class="fl-input" required autocomplete="new-password">
                            <button type="button" class="fl-toggle" data-target="newPass"><i class="fa-solid fa-eye-slash"></i></button>
                        </div>
                        <!-- Strength -->
                        <div class="strength-wrap">
                            <div class="s-bars">
                                <div class="s-bar" id="p-sb1"></div>
                                <div class="s-bar" id="p-sb2"></div>
                                <div class="s-bar" id="p-sb3"></div>
                                <div class="s-bar" id="p-sb4"></div>
                            </div>
                            <span class="s-text" id="p-slabel">—</span>
                            <div class="s-reqs">
                                <span class="req" id="p-req-len"><i class="fa-solid fa-circle-dot"></i> 8+ chars</span>
                                <span class="req" id="p-req-upper"><i class="fa-solid fa-circle-dot"></i> Uppercase</span>
                                <span class="req" id="p-req-num"><i class="fa-solid fa-circle-dot"></i> Number</span>
                                <span class="req" id="p-req-sym"><i class="fa-solid fa-circle-dot"></i> Symbol</span>
                            </div>
                        </div>
                    </div>
                    <div class="field-group">
                        <label class="fl-label">Confirm New Password</label>
                        <div class="input-wrap">
                            <input type="password" name="confirm_password" id="confirmPass" class="fl-input" required autocomplete="new-password">
                            <button type="button" class="fl-toggle" data-target="confirmPass"><i class="fa-solid fa-eye-slash"></i></button>
                        </div>
                        <div class="match-hint" id="matchHint"></div>
                    </div>
                </div>

                <button type="submit" class="btn-primary" id="passBtn" disabled>
                    <i class="fa-solid fa-floppy-disk"></i> Update Password
                </button>
            </form>
        </div>
    </div>

    <!-- ── Security Question ── -->
    <div class="card">
        <div class="card-header">
            <div class="card-icon"><i class="fa-solid fa-shield-halved"></i></div>
            <div>
                <div class="card-title">Security Question</div>
                <div class="card-subtitle">Used to verify your identity if you forget your password</div>
            </div>
        </div>
        <div class="card-body">
            <?php if ($has_sq): ?>
            <div style="display:flex;align-items:center;gap:10px;padding:12px 16px;background:rgba(85,224,135,0.06);border:1px solid rgba(85,224,135,0.2);border-radius:10px;margin-bottom:18px">
                <i class="fa-solid fa-circle-check" style="color:var(--success)"></i>
                <div>
                    <div style="font-size:13px;font-weight:600;color:var(--text)">Security question is set</div>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= htmlspecialchars($user['security_question']) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <form method="POST" id="sqForm">
                <input type="hidden" name="action" value="save_security">
                <input type="hidden" name="active_tab" value="security">

                <div class="field-row">
                    <div class="field-group">
                        <label class="fl-label">Security Question</label>
                        <select name="security_question" id="sqSelect" class="fl-input select-input" required>
                            <option value="" disabled <?= $has_sq ? '' : 'selected' ?>>— Choose a question —</option>
                            <?php
                            $questions = [
                                "What is your mother's maiden name?",
                                "What was the name of your first pet?",
                                "What city were you born in?",
                                "What was the name of your primary school?",
                                "What is your oldest sibling's middle name?",
                                "What was the make of your first car?",
                                "What street did you grow up on?",
                                "What is your favourite movie?",
                            ];
                            foreach ($questions as $q):
                            $sel = ($user['security_question'] === $q) ? 'selected' : '';
                            ?>
                            <option value="<?= htmlspecialchars($q) ?>" <?= $sel ?>><?= htmlspecialchars($q) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="field-row two">
                    <div class="field-group">
                        <label class="fl-label">Your Answer</label>
                        <input type="text" name="security_answer" id="sqAnswer" class="fl-input" placeholder="Enter your answer" required autocomplete="off">
                        <p class="fl-hint"><i class="fa-solid fa-info-circle" style="color:var(--accent)"></i> Not case-sensitive. Keep it memorable.</p>
                    </div>
                    <div class="field-group">
                        <label class="fl-label">Confirm Your Password</label>
                        <div class="input-wrap">
                            <input type="password" name="verify_password" id="sqPass" class="fl-input" placeholder="Current password" required autocomplete="current-password">
                            <button type="button" class="fl-toggle" data-target="sqPass"><i class="fa-solid fa-eye-slash"></i></button>
                        </div>
                        <p class="fl-hint">Required to confirm changes.</p>
                    </div>
                </div>

                <button type="submit" class="btn-primary">
                    <i class="fa-solid fa-shield-check"></i> <?= $has_sq ? 'Update' : 'Save' ?> Security Question
                </button>
            </form>
        </div>
    </div>

</div><!-- /page-wrap -->

<!-- Toast -->
<?php if ($toast): ?>
<div class="toast <?= $toast_type ?>" id="toastEl">
    <i class="fa-solid fa-<?= $toast_type === 'success' ? 'circle-check' : 'circle-exclamation' ?>"></i>
    <span><?= htmlspecialchars($toast) ?></span>
</div>
<script>
setTimeout(function(){
    var t = document.getElementById('toastEl');
    if(t){t.style.animation='toastOut .4s ease forwards';setTimeout(()=>t.remove(),400);}
}, 4000);
</script>
<?php endif; ?>

<script>
// ── Password toggle ──────────────────────────────────────────────────
document.querySelectorAll('.fl-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var inp  = document.getElementById(btn.dataset.target);
        var icon = btn.querySelector('i');
        if (!inp) return;
        var show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        icon.className = show ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
    });
});

// ── Strength meter ───────────────────────────────────────────────────
var newPass     = document.getElementById('newPass');
var confirmPass = document.getElementById('confirmPass');
var passBtn     = document.getElementById('passBtn');
var matchHint   = document.getElementById('matchHint');

var colors  = ['#e74c3c','#f39c12','#f1c40f','#55e087'];
var sLabels = ['Too Weak','Weak','Fair','Strong','Very Strong'];
var bars    = [1,2,3,4].map(function(i){ return document.getElementById('p-sb'+i); });
var slabel  = document.getElementById('p-slabel');
var reqs    = {
    len:   document.getElementById('p-req-len'),
    upper: document.getElementById('p-req-upper'),
    num:   document.getElementById('p-req-num'),
    sym:   document.getElementById('p-req-sym'),
};

function getScore(v) {
    var s = 0;
    if (v.length >= 8) s++;
    if (/[A-Z]/.test(v)) s++;
    if (/[0-9]/.test(v)) s++;
    if (/[^a-zA-Z0-9]/.test(v)) s++;
    return s;
}

function updateMeter() {
    var v = newPass.value;
    var score = getScore(v);

    bars.forEach(function(b, i) {
        b.style.background = i < score ? colors[score - 1] : 'rgba(255,255,255,0.08)';
    });
    slabel.textContent = v.length ? sLabels[score] : '—';
    slabel.style.color = v.length && score > 0 ? colors[score - 1] : 'var(--text-muted)';

    reqs.len.classList.toggle('met',   v.length >= 8);
    reqs.upper.classList.toggle('met', /[A-Z]/.test(v));
    reqs.num.classList.toggle('met',   /[0-9]/.test(v));
    reqs.sym.classList.toggle('met',   /[^a-zA-Z0-9]/.test(v));

    checkPassReady();
}

function checkPassReady() {
    var v = newPass.value;
    var c = confirmPass.value;
    var strong = getScore(v) >= 3;
    var match  = v === c && v.length > 0;

    passBtn.disabled = !(strong && match && document.getElementById('currentPass').value.length > 0);

    if (c.length > 0) {
        if (match) {
            matchHint.innerHTML = '<span style="color:var(--success)"><i class="fa-solid fa-check"></i> Passwords match</span>';
        } else {
            matchHint.innerHTML = '<span style="color:var(--danger)"><i class="fa-solid fa-xmark"></i> Passwords do not match</span>';
        }
    } else {
        matchHint.innerHTML = '';
    }
}

newPass.addEventListener('input', updateMeter);
confirmPass.addEventListener('input', checkPassReady);
document.getElementById('currentPass').addEventListener('input', checkPassReady);
</script>
</div></div>
</body>
</html>
