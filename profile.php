<?php
require 'auth.php';
require 'config.php';
if (!can('my_profile')) { header("Location: dashboard.php?denied=1"); exit; }

$user_id  = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';
$role     = $_SESSION['role']     ?? 'staff';

// Fetch staff & user information
$stmt_emp = $conn->prepare("
    SELECT u.user_id, u.username,
           r.name AS role_name, r.color AS role_color, r.icon AS role_icon,
           emp.employee_id, emp.name AS emp_name, emp.phone, emp.job_title, emp.hire_date, emp.shift
    FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    LEFT JOIN employees emp ON emp.user_id = u.user_id
    WHERE u.user_id = ?
");
$stmt_emp->bind_param("i", $user_id);
$stmt_emp->execute();
$emp_info = $stmt_emp->get_result()->fetch_assoc();

$role_display = $emp_info['role_name'] ?? ucfirst(str_replace('_', ' ', $role));
$role_color   = $emp_info['role_color'] ?? '#d1904b';
$role_icon    = $emp_info['role_icon']  ?? 'fa-user-tie';

$emp_display_name = !empty($emp_info['emp_name']) ? $emp_info['emp_name'] : $username;
$emp_code         = !empty($emp_info['employee_id']) ? '#STF-' . $emp_info['employee_id'] : '#USR-' . $user_id;
$emp_phone        = !empty($emp_info['phone']) ? $emp_info['phone'] : 'N/A';
$emp_job          = !empty($emp_info['job_title']) ? $emp_info['job_title'] : $role_display;
$emp_hire         = !empty($emp_info['hire_date']) ? date('F d, Y', strtotime($emp_info['hire_date'])) : 'N/A';
$emp_shift        = !empty($emp_info['shift']) ? ucfirst($emp_info['shift']) : 'N/A';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bird's Nest Coffee — Staff Profile</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
  --bg:          #0f0f12;
  --panel:       #18181c;
  --panel-border:#24242b;
  --accent:      #d1904b;
  --text:        #e4e4e7;
  --text-muted:  #a0a0ab;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  background: var(--bg);
  color: var(--text);
  font-family: 'Poppins', sans-serif;
  font-size: 14px;
}
.page-wrap {
  max-width: 800px;
  margin: 0 auto;
  padding: 32px 20px;
}
.profile-card {
  background: var(--panel);
  border: 1px solid var(--panel-border);
  border-radius: 20px;
  overflow: hidden;
  box-shadow: 0 8px 32px rgba(0,0,0,0.4);
}
.profile-banner {
  background: linear-gradient(135deg, rgba(209,144,75,0.18) 0%, rgba(24,24,28,0.9) 100%);
  padding: 36px 32px;
  border-bottom: 1px solid var(--panel-border);
  display: flex;
  align-items: center;
  gap: 24px;
}
.avatar-box {
  width: 80px; height: 80px;
  border-radius: 20px;
  background: linear-gradient(135deg, var(--accent), #b87b38);
  color: #fff;
  font-size: 32px;
  font-weight: 800;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 6px 20px rgba(209,144,75,0.35);
  flex-shrink: 0;
}
.profile-title-name {
  font-size: 24px;
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
  padding: 32px;
}
.section-heading {
  font-size: 14px;
  font-weight: 700;
  color: var(--accent);
  text-transform: uppercase;
  letter-spacing: 0.8px;
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.info-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 20px;
}
.info-item {
  background: rgba(255,255,255,0.02);
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 14px;
  padding: 16px 20px;
}
.info-label {
  font-size: 11px;
  font-weight: 600;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin-bottom: 6px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.info-label i { color: var(--accent); }
.info-val {
  font-size: 15px;
  font-weight: 600;
  color: #fff;
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
</div>

</div>
</div>
</body>
</html>
