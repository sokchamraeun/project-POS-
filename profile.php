<?php
require 'auth.php';
require 'config.php';
if (!can('my_profile')) { header("Location: dashboard.php?denied=1"); exit; }

$user_id  = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';
$role     = $_SESSION['role']     ?? 'staff';

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'change_password') {
        $new_pass = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (strlen($new_pass) < 4) {
            $err = 'New password must be at least 4 characters long.';
        } elseif ($new_pass !== $confirm) {
            $err = 'Password confirmation does not match.';
        } else {
            $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $upd = $conn->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE user_id = ?");
            $upd->bind_param("si", $hashed, $user_id);
            if ($upd->execute()) {
                $msg = 'Password changed successfully!';
            } else {
                $err = 'Failed to update password: ' . $conn->error;
            }
        }
    }
}

// Fetch staff & user information
$role_map = [
    'admin'   => ['name' => 'Admin',   'color' => '#d1904b', 'icon' => 'fa-user-shield'],
    'manager' => ['name' => 'Manager', 'color' => '#3498db', 'icon' => 'fa-user-tie'],
    'staff'   => ['name' => 'Cashier', 'color' => '#55e087', 'icon' => 'fa-user'],
    'barista' => ['name' => 'Barista', 'color' => '#d1904b', 'icon' => 'fa-mug-hot'],
];
$r_info       = $role_map[$role] ?? ['name' => ucfirst($role), 'color' => '#d1904b', 'icon' => 'fa-user'];
$role_display = $r_info['name'];
$role_color   = $r_info['color'];
$role_icon    = $r_info['icon'];
$emp_display_name = $username;
$emp_code         = '#USR-' . $user_id;
$emp_phone        = 'N/A';
$emp_job          = $role_display;
$emp_hire         = 'N/A';
$emp_shift        = 'N/A';
$emp_email        = 'N/A';
$must_change_pass = 0;

$u_stmt = $conn->prepare("SELECT name, email, must_change_password FROM users WHERE user_id = ? LIMIT 1");
if ($u_stmt) {
    $u_stmt->bind_param("i", $user_id);
    $u_stmt->execute();
    if ($u_row = $u_stmt->get_result()->fetch_assoc()) {
        if (!empty($u_row['name']))  $emp_display_name = $u_row['name'];
        if (!empty($u_row['email'])) $emp_email        = $u_row['email'];
        $must_change_pass = (int)($u_row['must_change_password'] ?? 0);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bird's Nest Coffee — Staff Profile</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Kantumruy+Pro:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg:          #0f0f12;
  --panel:       #18181c;
  --panel-border:#24242b;
  --accent:      #d1904b;
  --text:        #e4e4e7;
  --text-muted:  #a0a0ab;
  --mint:        #00f5a0;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  background: var(--bg);
  color: var(--text);
  font-family: 'Poppins', 'Kantumruy Pro', sans-serif;
  font-size: 14px;
}
.page-wrap {
  max-width: 800px;
  margin: 0 auto;
  padding: 24px 20px 48px;
}
.profile-card {
  background: var(--panel);
  border: 1px solid var(--panel-border);
  border-radius: 20px;
  overflow: hidden;
  box-shadow: 0 8px 32px rgba(0,0,0,0.4);
  margin-bottom: 24px;
}
.profile-banner {
  background: linear-gradient(135deg, rgba(209,144,75,0.18) 0%, rgba(24,24,28,0.9) 100%);
  padding: 32px 28px;
  border-bottom: 1px solid var(--panel-border);
  display: flex;
  align-items: center;
  gap: 24px;
}
.avatar-box {
  width: 76px; height: 76px;
  border-radius: 18px;
  background: linear-gradient(135deg, var(--accent), #b87b38);
  color: #fff;
  font-size: 30px;
  font-weight: 800;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 6px 20px rgba(209,144,75,0.35);
  flex-shrink: 0;
}
.profile-title-name {
  font-size: 22px;
  font-weight: 800;
  color: #fff;
  margin-bottom: 6px;
}
.badges-wrap {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.role-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 14px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
}
.code-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 14px;
  border-radius: 20px;
  background: rgba(255,255,255,0.06);
  border: 1px solid rgba(255,255,255,0.1);
  color: var(--text-muted);
  font-size: 12px;
  font-weight: 600;
  font-family: monospace;
}
.info-section {
  padding: 28px 30px;
}
.section-heading {
  font-size: 13.5px;
  font-weight: 700;
  color: var(--accent);
  text-transform: uppercase;
  letter-spacing: 0.8px;
  margin-bottom: 18px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.info-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
}
.info-item {
  background: rgba(255,255,255,0.02);
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 14px;
  padding: 14px 18px;
}
.info-label {
  font-size: 11px;
  font-weight: 600;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin-bottom: 4px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.info-label i { color: var(--accent); }
.info-val {
  font-size: 14.5px;
  font-weight: 600;
  color: #fff;
}
.alert-box {
  padding: 14px 18px;
  border-radius: 14px;
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13.5px;
  font-weight: 600;
}
.alert-warn {
  background: rgba(234, 179, 8, 0.12);
  border: 1px solid rgba(234, 179, 8, 0.3);
  color: #facc15;
}
.alert-success {
  background: rgba(0, 245, 160, 0.12);
  border: 1px solid rgba(0, 245, 160, 0.3);
  color: var(--mint);
}
.alert-err {
  background: rgba(239, 68, 68, 0.12);
  border: 1px solid rgba(239, 68, 68, 0.3);
  color: #f87171;
}
.form-input {
  width: 100%;
  padding: 11px 14px;
  border-radius: 10px;
  border: 1px solid var(--panel-border);
  background: rgba(255, 255, 255, 0.04);
  color: #fff;
  font-size: 13.5px;
  outline: none;
  transition: all 0.2s;
}
.form-input:focus {
  border-color: var(--accent);
  background: rgba(255, 255, 255, 0.07);
}
.btn-save-pass {
  background: var(--accent);
  color: #000;
  font-weight: 700;
  padding: 11px 24px;
  border-radius: 10px;
  border: none;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 13.5px;
  transition: all 0.2s;
}
.btn-save-pass:hover {
  background: #e8a255;
  color: #000;
}
@media(max-width:640px) {
  .info-grid { grid-template-columns: 1fr; }
  .profile-banner { flex-direction: column; text-align: center; }
  .badges-wrap { justify-content: center; }
}
</style>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body>
<div class="flex h-screen w-screen overflow-hidden app-layout" style="display:flex; width:100vw; height:100vh; overflow:hidden;">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<div class="app-main flex-1 h-full overflow-y-auto" style="flex:1; height:100%; overflow-y:auto;">

<!-- TOPBAR -->
<div class="topbar" style="display:flex;align-items:center;justify-content:space-between;padding:16px 24px;border-bottom:1px solid rgba(255,255,255,0.06);background:rgba(18,18,21,0.5);backdrop-filter:blur(12px);">
    <div style="display:flex;align-items:center;gap:12px;">
        <h1 style="font-size:20px;font-weight:800;color:var(--accent);display:flex;align-items:center;gap:10px;margin:0;">
            <i class="fa-solid fa-circle-user"></i> My Profile
        </h1>
    </div>
</div>

<div class="page-wrap">

    <?php if ($must_change_pass): ?>
    <div class="alert-box alert-warn">
        <i class="fa-solid fa-triangle-exclamation" style="font-size: 18px;"></i>
        <span>អ្នកបានចូលប្រព័ន្ធដោយប្រើពាក្យសម្ងាត់បណ្តោះអាសន្ន។ សូមកំណត់ពាក្យសម្ងាត់ថ្មីរបស់អ្នកខាងក្រោម។ (You logged in with a temporary password. Please set your new password below.)</span>
    </div>
    <?php endif; ?>

    <?php if ($msg): ?>
    <div class="alert-box alert-success">
        <i class="fa-solid fa-circle-check" style="font-size: 18px;"></i>
        <span><?= htmlspecialchars($msg) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($err): ?>
    <div class="alert-box alert-err">
        <i class="fa-solid fa-circle-exclamation" style="font-size: 18px;"></i>
        <span><?= htmlspecialchars($err) ?></span>
    </div>
    <?php endif; ?>

    <div class="profile-card">
        <!-- Banner Header -->
        <div class="profile-banner">
            <div class="avatar-box">
                <?= strtoupper(substr($emp_display_name, 0, 1)) ?>
            </div>
            <div>
                <h2 class="profile-title-name"><?= htmlspecialchars($emp_display_name) ?></h2>
                <div class="badges-wrap">
                    <span class="role-badge" style="background:<?= $role_color ?>22; color:<?= $role_color ?>; border:1px solid <?= $role_color ?>44">
                        <i class="fa-solid <?= htmlspecialchars($role_icon) ?>"></i>
                        <?= htmlspecialchars($role_display) ?>
                    </span>
                    <span class="code-badge">
                        <i class="fa-solid fa-id-badge"></i> <?= htmlspecialchars($emp_code) ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Information Body -->
        <div class="info-section">
            <div class="section-heading">
                <i class="fa-solid fa-address-card"></i> Staff Information
            </div>
            
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label"><i class="fa-solid fa-user"></i> Employee Name</div>
                    <div class="info-val"><?= htmlspecialchars($emp_display_name) ?></div>
                </div>

                <div class="info-item">
                    <div class="info-label"><i class="fa-solid fa-user-gear"></i> Username / Account</div>
                    <div class="info-val"><?= htmlspecialchars($username) ?></div>
                </div>

                <div class="info-item">
                    <div class="info-label"><i class="fa-solid fa-envelope"></i> Email Address</div>
                    <div class="info-val" style="color:#00f5a0;"><?= htmlspecialchars($emp_email) ?></div>
                </div>

                <div class="info-item">
                    <div class="info-label"><i class="fa-solid fa-id-card"></i> Staff ID</div>
                    <div class="info-val"><?= htmlspecialchars($emp_code) ?></div>
                </div>

                <div class="info-item">
                    <div class="info-label"><i class="fa-solid fa-briefcase"></i> Job Title / Role</div>
                    <div class="info-val"><?= htmlspecialchars($emp_job) ?></div>
                </div>

                <div class="info-item">
                    <div class="info-label"><i class="fa-solid fa-phone"></i> Phone Number</div>
                    <div class="info-val"><?= htmlspecialchars($emp_phone) ?></div>
                </div>

                <div class="info-item">
                    <div class="info-label"><i class="fa-solid fa-clock"></i> Work Shift</div>
                    <div class="info-val"><?= htmlspecialchars($emp_shift) ?></div>
                </div>

                <div class="info-item">
                    <div class="info-label"><i class="fa-solid fa-calendar-check"></i> Hire Date</div>
                    <div class="info-val"><?= htmlspecialchars($emp_hire) ?></div>
                </div>

                <div class="info-item">
                    <div class="info-label"><i class="fa-solid fa-circle-check"></i> Account Status</div>
                    <div class="info-val" style="color:#4ade80;">Active</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Security & Password Change Card -->
    <div class="profile-card">
        <div class="info-section">
            <div class="section-heading" style="color: <?= $must_change_pass ? '#facc15' : 'var(--accent)' ?>;">
                <i class="fa-solid fa-shield-halved"></i> Security & Change Password
            </div>
            <form method="POST" action="profile.php" autocomplete="off">
                <input type="hidden" name="action" value="change_password">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 20px;">
                    <div>
                        <label style="display:block; font-size: 11.5px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px;">New Password</label>
                        <input type="password" name="new_password" class="form-input" placeholder="Enter new password (min 4 chars)" required>
                    </div>
                    <div>
                        <label style="display:block; font-size: 11.5px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px;">Confirm Password</label>
                        <input type="password" name="confirm_password" class="form-input" placeholder="Re-type new password" required>
                    </div>
                </div>
                <button type="submit" class="btn-save-pass">
                    <i class="fa-solid fa-key"></i> Save New Password
                </button>
            </form>
        </div>
    </div>

</div>

</div>
</div>
</body>
</html>
