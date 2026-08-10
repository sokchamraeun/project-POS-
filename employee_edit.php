<?php
require 'admin_only.php';
require 'config.php';

$id = intval($_GET['id']);

// Load employee first (needed in POST too for user_id)
$stmt_sel = $conn->prepare("SELECT e.*, COALESCE(r.slug,'staff') AS emp_role FROM employees e LEFT JOIN users u ON u.user_id = COALESCE(e.user_id, e.employee_id) LEFT JOIN roles r ON r.id = u.role_id WHERE e.employee_id=?");
$stmt_sel->bind_param("i", $id);
$stmt_sel->execute();
$e = $stmt_sel->get_result()->fetch_assoc();
if (!$e) { header("Location: employees.php"); exit; }

if (isset($_POST['update'])) {
    $name     = $_POST['name']          ?? '';
    $phone    = $_POST['phone']         ?? '';
    $job      = $_POST['job_title']     ?? '';
    $salary   = $_POST['salary']        ?? '0';
    $dob      = $_POST['date_of_birth'] ?? '';
    $hire     = $_POST['hire_date']     ?? '';
    $address  = $_POST['address']       ?? '';
    $new_role = trim($_POST['emp_role'] ?? '');

    if (!empty($_FILES['photo']['name'])) {
        $ext    = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $okSize = $_FILES['photo']['size'] > 0 && $_FILES['photo']['size'] <= 15 * 1024 * 1024; // 15MB cap
        $allowedExts = ['jpg','jpeg','png','gif','webp','svg','bmp','ico','tiff','tif','avif','heic','heif','jfif','pjpeg','pjp','apng','cur','dng'];
        $isImageMime = false;
        if (function_exists('finfo_open') && !empty($_FILES['photo']['tmp_name'])) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $_FILES['photo']['tmp_name']);
            finfo_close($finfo);
            $isImageMime = (strpos($mime, 'image/') === 0);
        }
        if ((in_array($ext, $allowedExts) || $isImageMime) && $okSize) {
            if (!is_dir('uploads')) mkdir('uploads');
            $photo = 'uploads/' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            move_uploaded_file($_FILES['photo']['tmp_name'], $photo);
            $stmt = $conn->prepare("UPDATE employees SET name=?, phone=?, job_title=?, salary=?, date_of_birth=?, hire_date=?, address=?, photo=? WHERE employee_id=?");
            $stmt->bind_param("ssssssssi", $name, $phone, $job, $salary, $dob, $hire, $address, $photo, $id);
        } else {
            $stmt = $conn->prepare("UPDATE employees SET name=?, phone=?, job_title=?, salary=?, date_of_birth=?, hire_date=?, address=? WHERE employee_id=?");
            $stmt->bind_param("sssssssi", $name, $phone, $job, $salary, $dob, $hire, $address, $id);
        }
    } else {
        $stmt = $conn->prepare("UPDATE employees SET name=?, phone=?, job_title=?, salary=?, date_of_birth=?, hire_date=?, address=? WHERE employee_id=?");
        $stmt->bind_param("sssssssi", $name, $phone, $job, $salary, $dob, $hire, $address, $id);
    }
    $stmt->execute();

    // Update role in users table
    if ($new_role !== '') {
        $vr = $conn->prepare("SELECT slug FROM roles WHERE slug=? AND slug != 'admin'");
        $vr->bind_param("s", $new_role); $vr->execute();
        if ($vr->get_result()->fetch_assoc()) {
            $uid = !empty($e['user_id']) ? intval($e['user_id']) : $id;
            $rs  = $conn->prepare("UPDATE users SET role_id=(SELECT id FROM roles WHERE slug=?) WHERE user_id=?");
            $rs->bind_param("si", $new_role, $uid); $rs->execute();
        }
    }

    header("Location: employees.php");
    exit;
}

// Load all assignable roles
$roles_res = $conn->query("SELECT * FROM roles WHERE slug != 'admin' ORDER BY is_system DESC, id ASC");
$all_roles = [];
while ($r = $roles_res->fetch_assoc()) $all_roles[] = $r;

$current_role = $e['emp_role'] ?? 'staff';
$has_photo = !empty($e['photo']) && file_exists($e['photo']);
$initials  = strtoupper(mb_substr($e['name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Employee | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
:root {
    --accent: #d1904b;
    --accent-light: #e8b87a;
    --accent-dark: #a0702a;
    --bg: #0c0c0c;
    --bg-card: #121212;
    --border: #222;
    --border-hover: #333;
    --text: #ffffff;
    --text-muted: #9a9a9a;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.3);
    --shadow-lg: 0 8px 40px rgba(0,0,0,0.5);
    --shadow-accent: 0 4px 20px rgba(209,144,75,0.2);
    --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Inter', sans-serif;
    background: radial-gradient(circle at top, #111 0%, #000 60%);
    min-height: 100vh;
    color: var(--text);
    padding: 80px 20px 40px;
    position: relative;
}

body::before {
    content: '';
    position: fixed;
    top: -200px; right: -200px;
    width: 500px; height: 500px;
    background: radial-gradient(circle, rgba(209,144,75,0.06) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
}
body::after {
    content: '';
    position: fixed;
    bottom: -200px; left: -200px;
    width: 500px; height: 500px;
    background: radial-gradient(circle, rgba(209,144,75,0.04) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
}

::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 10px; }

/* ── Back Button ── */
.back-btn {
    position: fixed;
    top: 20px; left: 20px;
    display: flex; align-items: center; gap: 7px;
    color: #d1904b;
    text-decoration: none;
    font-weight: 600; font-size: 14px;
    padding: 9px 16px;
    border-radius: 12px;
    background: rgba(209,144,75,.08);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(209,144,75,.35);
    transition: var(--transition);
    z-index: 100;
}
.back-btn:hover {
    color: var(--accent);
    transform: translateX(-3px);
    border-color: var(--border-hover);
}

/* ── Wrapper ── */
.wrapper {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 820px;
    margin: 0 auto;
}

/* ── Card ── */
.card {
    background: rgba(18,18,18,0.95);
    backdrop-filter: blur(20px);
    border-radius: 24px;
    padding: 36px 40px;
    box-shadow: var(--shadow-lg);
    border: 1px solid var(--border);
    animation: slideUp 0.5s ease;
}

@keyframes slideUp {
    from { opacity: 0; transform: translateY(30px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── Profile Header ── */
.profile-header {
    display: flex;
    align-items: center;
    gap: 24px;
    padding-bottom: 28px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 28px;
    flex-wrap: wrap;
}

/* ── Clickable avatar photo upload ── */
.avatar-label {
    position: relative;
    flex-shrink: 0;
    cursor: pointer;
    display: block;
    width: 100px; height: 100px;
}

.avatar {
    width: 100px; height: 100px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid var(--accent);
    box-shadow: var(--shadow-accent);
    display: block;
    transition: var(--transition);
}

.avatar-fallback {
    width: 100px; height: 100px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--accent-dark));
    color: #000;
    font-size: 38px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    border: 2px solid var(--accent);
    box-shadow: var(--shadow-accent);
    transition: var(--transition);
}

.avatar-overlay {
    position: absolute;
    inset: 0;
    border-radius: 50%;
    background: rgba(0,0,0,0.55);
    display: flex; align-items: center; justify-content: center;
    opacity: 0;
    transition: var(--transition);
    color: #fff;
    font-size: 22px;
}
.avatar-label:hover .avatar-overlay { opacity: 1; }
.avatar-label:hover .avatar,
.avatar-label:hover .avatar-fallback { transform: scale(1.04); }

.profile-meta { flex: 1; min-width: 0; }

.page-title {
    font-size: 22px; font-weight: 700;
    margin-bottom: 6px;
}
.page-title span { color: var(--accent); }

.photo-hint {
    font-size: 12px;
    color: var(--text-muted);
    display: flex; align-items: center; gap: 6px;
    margin-bottom: 10px;
}
.photo-hint i { color: var(--accent); font-size: 11px; }

.photo-new-name {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12px; font-weight: 500;
    color: var(--accent);
    background: rgba(209,144,75,0.1);
    border: 1px solid rgba(209,144,75,0.2);
    padding: 4px 12px;
    border-radius: 20px;
    display: none;
}

/* ── Form Grid (matches view info-grid style) ── */
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 28px;
}

.form-tile {
    background: rgba(255,255,255,0.02);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 13px 16px;
    transition: var(--transition);
}
.form-tile:focus-within {
    border-color: var(--accent);
    background: rgba(255,255,255,0.04);
    box-shadow: 0 0 0 3px rgba(209,144,75,0.07);
}
.form-tile.full { grid-column: 1 / -1; }

.tile-label {
    font-size: 10px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.6px;
    display: flex; align-items: center; gap: 6px;
    margin-bottom: 7px;
}
.tile-label i { font-size: 11px; color: var(--accent); }

.tile-input {
    width: 100%;
    background: transparent;
    border: none;
    outline: none;
    color: var(--text);
    font-size: 14px;
    font-weight: 500;
    font-family: 'Inter', sans-serif;
    padding: 0;
    transition: var(--transition);
}
.tile-input::placeholder { color: var(--text-muted); font-weight: 400; }

/* Date inputs — remove browser default icon tint */
.tile-input[type="date"] { color-scheme: dark; }

textarea.tile-input {
    resize: vertical;
    min-height: 72px;
    line-height: 1.5;
}

/* ── Actions ── */
.actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding-top: 20px;
    border-top: 1px solid var(--border);
}

.btn {
    padding: 11px 24px;
    border-radius: 12px;
    font-size: 14px;
    text-decoration: none;
    border: 1px solid transparent;
    font-weight: 600;
    transition: var(--transition);
    display: flex; align-items: center; gap: 8px;
    cursor: pointer;
    font-family: 'Inter', sans-serif;
}
.btn i { font-size: 15px; }

.btn-cancel {
    border-color: var(--border-hover);
    color: var(--text-muted);
    background: transparent;
}
.btn-cancel:hover {
    border-color: var(--text-muted);
    color: var(--text);
    transform: translateY(-2px);
}

.btn-save {
    background: linear-gradient(135deg, var(--accent), var(--accent-light));
    color: #000;
    box-shadow: var(--shadow-accent);
    border: none;
}
.btn-save:hover {
    transform: translateY(-2px);
    filter: brightness(1.1);
    box-shadow: 0 6px 24px rgba(209,144,75,0.35);
}

/* ── Role Selector ── */
.role-selector-wrap { grid-column: 1 / -1; }
.role-selector-grid {
    display: flex; flex-wrap: wrap; gap: 10px;
    margin-top: 4px;
}
.role-opt { display: none; }
.role-opt-label {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 16px;
    border-radius: 12px;
    border: 1.5px solid var(--border);
    background: rgba(255,255,255,0.02);
    cursor: pointer;
    transition: var(--transition);
    user-select: none;
    min-width: 130px;
}
.role-opt-label:hover { border-color: var(--border-hover); background: rgba(255,255,255,0.04); }
.role-opt:checked + .role-opt-label { border-color: var(--rc); background: var(--rc-bg); box-shadow: 0 0 0 3px var(--rc-glow); }
.role-opt-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; background: var(--rc-bg); color: var(--rc); flex-shrink: 0; }
.role-opt-name { font-size: 13px; font-weight: 600; }
.role-opt-desc { font-size: 10px; color: var(--text-muted); margin-top: 1px; }

/* ── Responsive ── */
@media (max-width: 640px) {
    body { padding: 70px 14px 30px; }
    .card { padding: 22px 18px; }
    .form-grid { grid-template-columns: 1fr; }
    .page-title { font-size: 18px; }
    .avatar-label { width: 76px; height: 76px; }
    .avatar, .avatar-fallback { width: 76px; height: 76px; }
    .avatar-fallback { font-size: 28px; }
    .profile-header { gap: 16px; }
    .btn { padding: 9px 18px; font-size: 13px; }
}
</style>
</head>

<body>

<a href="employees.php" class="back-btn">
    <i class="fa-solid fa-arrow-left"></i> Back to Employees
</a>

<div class="wrapper">
<form method="POST" enctype="multipart/form-data">
    <div class="card">

        <!-- Profile Header -->
        <div class="profile-header">
            <label class="avatar-label" for="photoInput" title="Click to change photo">
                <?php if ($has_photo): ?>
                    <img src="<?= htmlspecialchars($e['photo']) ?>" class="avatar" id="avatarImg" alt="Photo">
                <?php else: ?>
                    <div class="avatar-fallback" id="avatarFallback"><?= htmlspecialchars($initials) ?></div>
                <?php endif; ?>
                <div class="avatar-overlay"><i class="fa-solid fa-camera"></i></div>
                <input type="file" name="photo" id="photoInput" accept="image/*" style="display:none">
            </label>

            <div class="profile-meta">
                <div class="page-title">Edit <span>Employee</span></div>
                <div class="photo-hint">
                    <i class="fa-solid fa-camera"></i> Click the photo to change it
                </div>
                <div class="photo-new-name" id="photoNewName">
                    <i class="fa-solid fa-check"></i>
                    <span id="photoNewNameText"></span>
                </div>
            </div>
        </div>

        <!-- Form Grid -->
        <div class="form-grid">

            <div class="form-tile">
                <div class="tile-label"><i class="fa-solid fa-user"></i> Full Name</div>
                <input class="tile-input" type="text" name="name"
                       value="<?= htmlspecialchars($e['name']) ?>" required placeholder="Employee name">
            </div>

            <div class="form-tile">
                <div class="tile-label"><i class="fa-solid fa-phone"></i> Phone Number</div>
                <input class="tile-input" type="text" name="phone"
                       value="<?= htmlspecialchars($e['phone']) ?>" placeholder="Phone number">
            </div>

            <div class="form-tile">
                <div class="tile-label"><i class="fa-solid fa-briefcase"></i> Job Title</div>
                <input class="tile-input" type="text" name="job_title"
                       value="<?= htmlspecialchars($e['job_title']) ?>" required placeholder="e.g. Barista">
            </div>

            <div class="form-tile">
                <div class="tile-label"><i class="fa-solid fa-dollar-sign"></i> Salary</div>
                <input class="tile-input" type="number" step="0.01" name="salary"
                       value="<?= htmlspecialchars($e['salary']) ?>" required placeholder="0.00">
            </div>

            <div class="form-tile">
                <div class="tile-label"><i class="fa-solid fa-calendar"></i> Date of Birth</div>
                <input class="tile-input" type="date" name="date_of_birth"
                       value="<?= htmlspecialchars($e['date_of_birth']) ?>">
            </div>

            <div class="form-tile">
                <div class="tile-label"><i class="fa-solid fa-calendar-check"></i> Hire Date</div>
                <input class="tile-input" type="date" name="hire_date"
                       value="<?= htmlspecialchars($e['hire_date']) ?>">
            </div>

            <div class="form-tile full">
                <div class="tile-label"><i class="fa-solid fa-location-dot"></i> Address</div>
                <textarea class="tile-input" name="address" rows="3"
                          placeholder="Employee address"><?= htmlspecialchars($e['address']) ?></textarea>
            </div>

            <div class="form-tile full role-selector-wrap">
                <div class="tile-label"><i class="fa-solid fa-shield-halved"></i> Role</div>
                <div class="role-selector-grid">
                    <?php foreach ($all_roles as $role): ?>
                    <input type="radio" class="role-opt" name="emp_role"
                           id="role_<?= $role['slug'] ?>"
                           value="<?= htmlspecialchars($role['slug']) ?>"
                           <?= $current_role === $role['slug'] ? 'checked' : '' ?>>
                    <label class="role-opt-label" for="role_<?= $role['slug'] ?>"
                           style="--rc:<?= htmlspecialchars($role['color']) ?>;--rc-bg:<?= $role['color'] ?>22;--rc-glow:<?= $role['color'] ?>26">
                        <div class="role-opt-icon"><i class="fa-solid <?= htmlspecialchars($role['icon']) ?>"></i></div>
                        <div>
                            <div class="role-opt-name"><?= htmlspecialchars($role['name']) ?></div>
                            <?php if (!empty($role['description'])): ?>
                            <div class="role-opt-desc"><?= htmlspecialchars(mb_strimwidth($role['description'], 0, 40, '…')) ?></div>
                            <?php endif; ?>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>

        <!-- Actions -->
        <div class="actions">
            <a href="employees.php" class="btn btn-cancel">
                <i class="fa-solid fa-xmark"></i> Cancel
            </a>
            <button type="submit" name="update" class="btn btn-save">
                <i class="fa-solid fa-floppy-disk"></i> Save Changes
            </button>
        </div>

    </div>
</form>
</div>

<script>
document.getElementById('photoInput').addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function(e) {
        const src = e.target.result;

        // Swap avatar to preview
        const existing = document.getElementById('avatarImg') || document.getElementById('avatarFallback');
        if (existing) {
            const img = document.createElement('img');
            img.className = 'avatar';
            img.id = 'avatarImg';
            img.src = src;
            img.alt = 'New photo preview';
            existing.replaceWith(img);
        }

        // Show filename pill
        const pill = document.getElementById('photoNewName');
        document.getElementById('photoNewNameText').textContent = file.name;
        pill.style.display = 'inline-flex';
    };
    reader.readAsDataURL(file);
});
</script>

</body>
</html>
