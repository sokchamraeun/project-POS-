<?php
ob_start();
require 'auth.php';
require_once 'config.php';
if (!can('employees')) { header("Location: dashboard.php?denied=1"); exit; }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_ok(): bool {
    return hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '');
}

/* ── Get employee data for edit modal (AJAX GET) ── */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_employee') {
    header('Content-Type: application/json');
    $eid = intval($_GET['eid'] ?? 0);
    if ($eid > 0) {
        $s = $conn->prepare("SELECT e.*, COALESCE(r.slug, 'staff') AS emp_role FROM employees e LEFT JOIN users u ON u.user_id = COALESCE(e.user_id, e.employee_id) LEFT JOIN roles r ON r.id = u.role_id WHERE e.employee_id=?");
        $s->bind_param("i", $eid); $s->execute();
        $emp = $s->get_result()->fetch_assoc();
        if ($emp) { ob_end_clean(); echo json_encode(['ok' => true, 'emp' => $emp]); exit; }
    }
    ob_end_clean();
    echo json_encode(['ok' => false]);
    exit;
}

/* ── Save employee from add modal (AJAX POST) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_employee') {
    header('Content-Type: application/json');
    if (!csrf_ok()) { ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Invalid session token']); exit; }
    $name     = trim($_POST['name'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $job      = trim($_POST['job_title'] ?? '');
    $salary   = floatval($_POST['salary'] ?? 0);
    $dob      = $_POST['date_of_birth'] ?? '';
    $hire     = $_POST['hire_date'] ?? '';
    $address  = trim($_POST['address'] ?? '');
    $shift_raw = trim($_POST['shift'] ?? '');
    $shift    = in_array($shift_raw, ['morning','afternoon','normal','night']) ? $shift_raw : null;

    if ($name === '') {
        ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Full name is required']); exit;
    }
    if ($job === '') {
        ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Position is required']); exit;
    }

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
    $hire_val = ($hire !== '') ? $hire : date('Y-m-d');

    $has_shift = $conn->query("SHOW COLUMNS FROM employees LIKE 'shift'")->num_rows > 0;
    $has_is_pos = $conn->query("SHOW COLUMNS FROM employees LIKE 'is_pos'")->num_rows > 0;

    if ($has_shift && $has_is_pos) {
        $st = $conn->prepare("INSERT INTO employees (name, phone, job_title, salary, date_of_birth, hire_date, address, photo, shift, is_pos) VALUES (?,?,?,?,?,?,?,?,?,0)");
        $st->bind_param("sssdsssss", $name, $phone, $job, $salary, $dob_val, $hire_val, $address, $photo, $shift);
    } else if ($has_shift) {
        $st = $conn->prepare("INSERT INTO employees (name, phone, job_title, salary, date_of_birth, hire_date, address, photo, shift) VALUES (?,?,?,?,?,?,?,?,?)");
        $st->bind_param("sssdsssss", $name, $phone, $job, $salary, $dob_val, $hire_val, $address, $photo, $shift);
    } else {
        $st = $conn->prepare("INSERT INTO employees (name, phone, job_title, salary, date_of_birth, hire_date, address, photo) VALUES (?,?,?,?,?,?,?,?)");
        $st->bind_param("sssdssss", $name, $phone, $job, $salary, $dob_val, $hire_val, $address, $photo);
    }

    if ($st && $st->execute()) {
        ob_end_clean(); echo json_encode(['ok' => true, 'msg' => 'Employee added successfully']); exit;
    } else {
        ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Failed to add employee: ' . ($conn->error ?: 'Database error')]); exit;
    }
}

/* ── Real-time stats (AJAX GET) ── */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_stats') {
    header('Content-Type: application/json');
    $hasBizDate = $conn->query("SHOW COLUMNS FROM `orders` LIKE 'business_date'")->num_rows > 0;
    $dc = $hasBizDate ? 'business_date' : 'created_at';
    $res = $conn->query("
        SELECT e.employee_id, e.user_id, e.shift, e.job_title,
            COALESCE(s.total_orders,      0) AS total_orders,
            COALESCE(s.total_revenue,     0) AS total_revenue,
            COALESCE(s.orders_this_month, 0) AS orders_this_month,
            COALESCE(s.orders_today,      0) AS orders_today,
            COALESCE(s.avg_order_value,   0) AS avg_order_value
        FROM employees e
        LEFT JOIN (
            SELECT employee_id,
                COUNT(*)                                                                        AS total_orders,
                COALESCE(SUM(total), 0)                                                         AS total_revenue,
                SUM(CASE WHEN DATE_FORMAT(`$dc`,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m') THEN 1 ELSE 0 END) AS orders_this_month,
                SUM(CASE WHEN DATE(`$dc`) = CURDATE() THEN 1 ELSE 0 END)                        AS orders_today,
                AVG(total)                                                                       AS avg_order_value
            FROM orders
            WHERE " . paid_orders_where() . "
            GROUP BY employee_id
        ) s ON s.employee_id = e.employee_id
    ");
    $stats = []; $max = 1;
    while ($r = $res->fetch_assoc()) {
        $stats[(int)$r['employee_id']] = [
            'total_orders'      => (int)$r['total_orders'],
            'total_revenue'     => (float)$r['total_revenue'],
            'orders_this_month' => (int)$r['orders_this_month'],
            'orders_today'      => (int)$r['orders_today'],
            'avg_order_value'   => (float)$r['avg_order_value'],
            'shift'             => $r['shift'] ?? null,
            'job_title'         => $r['job_title'] ?? '',
        ];
        if ((int)$r['total_orders'] > $max) $max = (int)$r['total_orders'];
    }
    ob_end_clean();
    echo json_encode(['ok' => true, 'stats' => $stats, 'max_orders' => $max]);
    exit;
}

/* ── Save employee from edit modal (AJAX POST) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_employee') {
    header('Content-Type: application/json');
    if (!csrf_ok()) { ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Invalid session token']); exit; }
    $eid      = intval($_POST['eid'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $job      = trim($_POST['job_title'] ?? '');
    $salary   = floatval($_POST['salary'] ?? 0);
    $dob      = $_POST['date_of_birth'] ?? '';
    $hire     = $_POST['hire_date'] ?? '';
    $address  = trim($_POST['address'] ?? '');
    $new_role = trim($_POST['emp_role'] ?? '');
    $shift_raw = trim($_POST['shift'] ?? '');
    $shift    = in_array($shift_raw, ['morning','afternoon','normal','night']) ? $shift_raw : null;

    // If job title was left blank, fallback to role name
    $_cur_is_admin = ($_SESSION['role'] ?? '') === 'admin';
    if ($job === '' && $new_role !== '' && ($_cur_is_admin || $new_role !== 'admin')) {
        $rn = $conn->prepare("SELECT name FROM roles WHERE slug=?");
        $rn->bind_param("s", $new_role); $rn->execute();
        if ($rnr = $rn->get_result()->fetch_assoc()) $job = $rnr['name'];
    }

    if ($eid <= 0 || $name === '') {
        ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Invalid data']); exit;
    }
    $se = $conn->prepare("SELECT user_id, employee_id FROM employees WHERE employee_id=?");
    $se->bind_param("i", $eid); $se->execute();
    $er = $se->get_result()->fetch_assoc();
    if (!$er) { ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Not found']); exit; }

    $photo = null;
    if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === 0) {
        $ext    = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $allowedExts = ['jpg','jpeg','png','gif','webp','svg','bmp','ico','tiff','tif','avif','heic','heif','jfif','pjpeg','pjp','apng','cur','dng'];
        $isImageMime = false;
        if (function_exists('finfo_open') && !empty($_FILES['photo']['tmp_name'])) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $_FILES['photo']['tmp_name']);
            finfo_close($finfo);
            $isImageMime = (strpos($mime, 'image/') === 0);
        }
        $okExt  = in_array($ext, $allowedExts) || $isImageMime;
        $okSize = $_FILES['photo']['size'] > 0 && $_FILES['photo']['size'] <= 15 * 1024 * 1024; // 15MB cap
        if ($okExt && $okSize) {
            if (!is_dir('uploads')) mkdir('uploads', 0755, true);
            $photo = 'uploads/' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            move_uploaded_file($_FILES['photo']['tmp_name'], $photo);
        }
    }

    if ($photo) {
        $st = $conn->prepare("UPDATE employees SET name=?,phone=?,job_title=?,salary=?,date_of_birth=?,hire_date=?,address=?,photo=?,shift=? WHERE employee_id=?");
        $st->bind_param("sssssssssi", $name,$phone,$job,$salary,$dob,$hire,$address,$photo,$shift,$eid);
    } else {
        $st = $conn->prepare("UPDATE employees SET name=?,phone=?,job_title=?,salary=?,date_of_birth=?,hire_date=?,address=?,shift=? WHERE employee_id=?");
        $st->bind_param("ssssssssi", $name,$phone,$job,$salary,$dob,$hire,$address,$shift,$eid);
    }
    $st->execute();

    $_cur_is_admin = ($_SESSION['role'] ?? '') === 'admin';
    if ($new_role !== '' && ($_cur_is_admin || $new_role !== 'admin')) {
        $vr = $conn->prepare("SELECT id, name FROM roles WHERE slug=?");
        $vr->bind_param("s", $new_role); $vr->execute();
        $r_info = $vr->get_result()->fetch_assoc();
        if ($r_info) {
            $role_id = (int)$r_info['id'];
            $job     = $r_info['name'];
            
            // 1. Ensure employees table has is_pos = 1 and updated job_title
            $upd_emp = $conn->prepare("UPDATE employees SET is_pos=1, job_title=? WHERE employee_id=?");
            $upd_emp->bind_param("si", $job, $eid); $upd_emp->execute();

            // 2. Update user's role in users table
            $target_uid = !empty($er['user_id']) ? (int)$er['user_id'] : $eid;
            $chk_u = $conn->prepare("SELECT user_id FROM users WHERE user_id=?");
            $chk_u->bind_param("i", $target_uid); $chk_u->execute();
            if ($chk_u->get_result()->num_rows > 0) {
                $rs = $conn->prepare("UPDATE users SET role_id=? WHERE user_id=?");
                $rs->bind_param("ii", $role_id, $target_uid); $rs->execute();
            } else {
                // If user record doesn't exist, create user or assign user_id
                $uname = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name)) ?: 'emp' . $eid;
                $def_pwd = password_hash('123456', PASSWORD_DEFAULT);
                $ins_u = $conn->prepare("INSERT INTO users (user_id, username, password, role_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE role_id=?");
                $ins_u->bind_param("issii", $target_uid, $uname, $def_pwd, $role_id, $role_id);
                $ins_u->execute();

                $link_e = $conn->prepare("UPDATE employees SET user_id=? WHERE employee_id=?");
                $link_e->bind_param("ii", $target_uid, $eid); $link_e->execute();
            }
        }
    }
    ob_end_clean();
    echo json_encode(['ok' => true, 'photo' => $photo, 'name' => $name, 'job' => $job, 'role' => $new_role, 'shift' => $shift]);
    exit;
}

/* ── Quick role update (AJAX) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_role') {
    header('Content-Type: application/json');
    if (!csrf_ok()) { ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Invalid session token']); exit; }
    $eid      = intval($_POST['eid'] ?? 0);
    $new_role = trim($_POST['role'] ?? '');
    $_cur_is_admin = ($_SESSION['role'] ?? '') === 'admin';
    if ($eid > 0 && $new_role !== '' && ($_cur_is_admin || $new_role !== 'admin')) {
        $vr = $conn->prepare($_cur_is_admin ? "SELECT slug FROM roles WHERE slug=?" : "SELECT slug FROM roles WHERE slug=? AND slug!='admin'");
        $vr->bind_param("s", $new_role); $vr->execute();
        if ($vr->get_result()->fetch_assoc()) {
            $se = $conn->prepare("SELECT user_id, is_pos FROM employees WHERE employee_id=?");
            $se->bind_param("i", $eid); $se->execute();
            $er = $se->get_result()->fetch_assoc();
            // Only POS staff with a real login can have their role changed.
            if ($er && (int)$er['is_pos'] === 1 && !empty($er['user_id'])) {
                $uid = intval($er['user_id']);
                $ru  = $conn->prepare("UPDATE users SET role_id=(SELECT id FROM roles WHERE slug=?) WHERE user_id=?");
                $ru->bind_param("si", $new_role, $uid); $ru->execute();
                if ($conn->affected_rows >= 0) {
                    // Keep job title in sync — it follows the role.
                    $newJob = '';
                    $rn = $conn->prepare("SELECT name FROM roles WHERE slug=?");
                    $rn->bind_param("s", $new_role); $rn->execute();
                    if ($rnr = $rn->get_result()->fetch_assoc()) {
                        $newJob = $rnr['name'];
                        $ju = $conn->prepare("UPDATE employees SET job_title=? WHERE employee_id=?");
                        $ju->bind_param("si", $newJob, $eid); $ju->execute();
                    }
                    ob_end_clean();
                    echo json_encode(['ok' => true, 'job' => $newJob]);
                    exit;
                }
            }
        }
    }
    ob_end_clean();
    echo json_encode(['ok' => false, 'msg' => 'Invalid request']);
    exit;
}

$sess_role = $_SESSION['role'] ?? 'staff';

/* ── Enrich employees with performance data from orders ── */
$has_orders = $conn->query("SHOW TABLES LIKE 'orders'")->num_rows > 0;

if ($has_orders) {
    $hasBizDate = $conn->query("SHOW COLUMNS FROM `orders` LIKE 'business_date'")->num_rows > 0;
    $dc = $hasBizDate ? 'business_date' : 'created_at';
    $emp_sql = "
        SELECT
            e.*,
            COALESCE(r.slug, 'staff') AS emp_role,
            COALESCE(s.total_orders,      0)    AS total_orders,
            COALESCE(s.total_revenue,     0)    AS total_revenue,
            COALESCE(s.orders_this_month, 0)    AS orders_this_month,
            COALESCE(s.orders_today,      0)    AS orders_today,
            COALESCE(s.avg_order_value,   0)    AS avg_order_value,
            s.last_order_date
        FROM employees e
        LEFT JOIN users u ON u.user_id = e.user_id
        LEFT JOIN roles r ON r.id = u.role_id
        LEFT JOIN (
            SELECT
                employee_id,
                COUNT(*)                                                                   AS total_orders,
                COALESCE(SUM(total), 0)                                                    AS total_revenue,
                SUM(CASE WHEN DATE_FORMAT(`$dc`,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m') THEN 1 ELSE 0 END) AS orders_this_month,
                SUM(CASE WHEN DATE(`$dc`) = CURDATE() THEN 1 ELSE 0 END)                  AS orders_today,
                AVG(total)                                                                 AS avg_order_value,
                MAX(`$dc`)                                                                 AS last_order_date
            FROM orders
            WHERE " . paid_orders_where() . "
            GROUP BY employee_id
        ) s ON s.employee_id = e.employee_id
        ORDER BY total_orders DESC, e.name ASC
    ";
} else {
    $emp_sql = "
        SELECT e.*, COALESCE(r.slug, 'staff') AS emp_role,
               0 AS total_orders, 0 AS total_revenue,
               0 AS orders_this_month, 0 AS orders_today,
               0 AS avg_order_value, NULL AS last_order_date
        FROM employees e
        LEFT JOIN users u ON u.user_id = e.user_id
        LEFT JOIN roles r ON r.id = u.role_id
        ORDER BY e.name ASC
    ";
}

$employees  = [];
$max_orders = 1;
$res = $conn->query($emp_sql);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $employees[] = $r;
        if ((int)$r['total_orders'] > $max_orders) $max_orders = (int)$r['total_orders'];
    }
}

$total_staff      = count($employees);
$total_orders_all = (int)array_sum(array_column($employees, 'total_orders'));
$total_revenue    = (float)array_sum(array_column($employees, 'total_revenue'));
$total_this_month = (int)array_sum(array_column($employees, 'orders_this_month'));

/* Who is eligible to be ranked.
   - The owner account is excluded: a leaderboard compares staff performance, and the
     admin isn't competing with the cashiers. Their orders still count toward the
     Revenue Served / Orders This Month totals, which say "all staff".
   - Zero-order employees are excluded: with most of the roster at 0, an unfiltered
     top-3 puts people on a podium for having served nobody.
   Matched on the role slug, not role_id — ids are not stable across installs. */
$rankable = array_values(array_filter($employees, function ($e) {
    return ($e['emp_role'] ?? '') !== 'admin' && (int)$e['total_orders'] > 0;
}));

// $employees is already ORDER BY total_orders DESC, so the filter preserves ranking.
$leaderboard = array_slice($rankable, 0, 3);

$top_month = null;
foreach ($rankable as $e) {
    if ((int)$e['orders_this_month'] <= 0) continue;   // "Top This Month: 0 orders" is not a fact
    if (!$top_month || (int)$e['orders_this_month'] > (int)$top_month['orders_this_month'])
        $top_month = $e;
}

// Load roles from DB for dynamic filter pills
$_roles_db = [];
$_rdb = $conn->query("SELECT slug, name, icon, color FROM roles ORDER BY is_system DESC, id ASC");
while ($_rdbr = $_rdb->fetch_assoc()) $_roles_db[$_rdbr['slug']] = $_rdbr;

// Count employees per role dynamically
$role_counts_emp = [];
foreach ($employees as $_e) {
    $r = $_e['emp_role'] ?? 'staff';
    $role_counts_emp[$r] = ($role_counts_emp[$r] ?? 0) + 1;
}
// Keep legacy vars for any remaining hardcoded references
$count_admin   = $role_counts_emp['admin']   ?? 0;
$count_manager = $role_counts_emp['manager'] ?? 0;
$count_staff   = $role_counts_emp['staff']   ?? 0;

// Shift counts
$shift_counts = ['morning' => 0, 'afternoon' => 0, 'night' => 0];
foreach ($employees as $_e) {
    $sh = $_e['shift'] ?? '';
    if (isset($shift_counts[$sh])) $shift_counts[$sh]++;
}

$sorted_employees = $employees;
$_role_order = array_keys($_roles_db);
usort($sorted_employees, function($a, $b) use ($_role_order) {
    $ap = array_search($a['emp_role'] ?? 'staff', $_role_order);
    $bp = array_search($b['emp_role'] ?? 'staff', $_role_order);
    $ap = $ap === false ? 999 : $ap;
    $bp = $bp === false ? 999 : $bp;
    if ($ap !== $bp) return $ap - $bp;
    return (int)$b['total_orders'] - (int)$a['total_orders'];
});

function h($s)       { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n)   { return number_format((float)$n, 2); }
function fmtnum($n)  { return number_format((int)$n); }
function tenure($d) {
    if (!$d || $d === '0000-00-00') return '—';
    try {
        $diff = (new DateTime($d))->diff(new DateTime());
        if ($diff->y > 0) return $diff->y . 'y ' . $diff->m . 'm';
        if ($diff->m > 0) return $diff->m . ' mo';
        return max(1, $diff->d) . 'd';
    } catch (Exception $e) { return '—'; }
}
function initials($name) {
    $p = array_values(array_filter(explode(' ', trim($name))));
    $i = strtoupper(substr($p[0] ?? 'E', 0, 1));
    if (count($p) > 1) $i .= strtoupper(substr(end($p), 0, 1));
    return $i;
}
function avatarColor($name) {
    $colors = ['#d1904b','#55e087','#3498db','#9b59b6','#e74c3c','#1abc9c','#e67e22','#e91e8c'];
    return $colors[crc32($name) % count($colors)];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Employees | Bird's Nest Coffee</title>
<script>(function(){if(localStorage.getItem('theme')==='light')document.documentElement.setAttribute('data-theme','light');}());</script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.tailwindcss.com"></script>
<style>
/* ── VARS ── */
:root {
    --bg:#0b0b0b; --bg-card:#131313; --bg-input:#1a1a1a;
    --border:#222; --border-hover:#333;
    --accent:#d1904b; --accent-light:#e8b87a; --accent-dark:#a0702a;
    --text:#f5f5f5; --text-muted:#888; --text-light:#fff;
    --ok:#55e087; --low:#f1c40f; --danger:#ff5f5f; --blue:#3498db; --purple:#9b59b6;
    --gold:#f1c40f; --silver:#bdc3c7; --bronze:#cd7f32;
    --shadow-sm:0 2px 8px rgba(0,0,0,.35); --shadow-md:0 4px 20px rgba(0,0,0,.45);
    --shadow-accent:0 0 0 3px rgba(209,144,75,.12);
    --radius:14px; --transition:all .22s cubic-bezier(.4,0,.2,1);
}
[data-theme="light"] {
    --bg:#F0F2F5; --bg-card:#FFFFFF; --bg-input:#F9FAFB;
    --border:#E5E7EB; --border-hover:#D1D5DB;
    --text:#111827; --text-muted:#6B7280; --text-light:#111827;
    --shadow-sm:0 2px 8px rgba(0,0,0,.06); --shadow-md:0 4px 20px rgba(0,0,0,.08);
}
[data-theme="light"] .topbar { background:rgba(255,255,255,.97); }
[data-theme="light"] thead th { background:#fff; }
[data-theme="light"] tbody tr:hover td { background:rgba(0,0,0,.02); }
[data-theme="light"] input,[data-theme="light"] select { background:var(--bg-input)!important; color:var(--text)!important; border-color:var(--border)!important; color-scheme:light; }

/* ── RESET ── */
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
html { scroll-behavior:smooth; }
body { font-family:'Inter',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; padding-bottom:60px; }
::-webkit-scrollbar { width:5px; height:5px; }
::-webkit-scrollbar-thumb { background:var(--accent); border-radius:10px; }

/* ── TOPBAR ── */
.topbar {
    position:sticky; top:0; z-index:200;
    display:flex; align-items:center; gap:10px; padding:10px 24px;
    background:rgba(11,11,11,.97); backdrop-filter:blur(20px);
    border-bottom:1px solid var(--border); flex-wrap:wrap;
}
.topbar-brand { display:flex; align-items:center; gap:10px; }
.brand-icon   { width:34px; height:34px; border-radius:9px; background:linear-gradient(135deg,var(--accent-dark),var(--accent)); display:flex; align-items:center; justify-content:center; font-size:15px; color:#fff; flex-shrink:0; }
.brand-text   { display:flex; flex-direction:column; line-height:1.2; }
.brand-title  { font-size:15px; font-weight:700; color:var(--text-light); }
.brand-sub    { font-size:10px; color:var(--text-muted); }
.topbar-sep   { width:1px; height:22px; background:var(--border); flex-shrink:0; }
.topbar-center { flex:1; display:flex; justify-content:center; min-width:180px; }
.topbar-right  { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.search-wrap {
    display:flex; align-items:center; gap:8px; padding:8px 14px;
    border-radius:50px; border:1px solid var(--border); background:var(--bg-input);
    transition:var(--transition); max-width:300px; width:100%;
}
.search-wrap:focus-within { border-color:var(--accent); box-shadow:var(--shadow-accent); }
.search-wrap i { color:var(--text-muted); font-size:12px; flex-shrink:0; }
.search-wrap input { border:none; background:transparent; outline:none; color:var(--text); font-size:13px; font-family:'Inter',sans-serif; flex:1; min-width:0; }
.search-wrap input::placeholder { color:var(--text-muted); }
#clearSearch { background:none; border:none; color:var(--text-muted); cursor:pointer; padding:0; font-size:12px; transition:var(--transition); display:none; align-items:center; }
#clearSearch.vis { display:flex; }
#clearSearch:hover { color:var(--danger); }
.kbd { font-size:10px; color:var(--text-muted); background:rgba(255,255,255,.06); padding:1px 6px; border-radius:4px; border:1px solid var(--border); font-family:monospace; }
.btn-nav {
    display:inline-flex; align-items:center; gap:6px; padding:7px 14px;
    border-radius:50px; border:1px solid var(--border); background:var(--bg-input);
    color:var(--text-muted); text-decoration:none; font-size:12px; font-weight:500;
    transition:var(--transition); cursor:pointer; white-space:nowrap; font-family:'Inter',sans-serif;
}
.btn-nav:hover { border-color:var(--accent); color:var(--accent); }
.btn-nav.primary { background:var(--accent); color:#000; border-color:var(--accent); font-weight:700; }
.btn-nav.primary:hover { background:var(--accent-light); }
.btn-nav.icon-only { padding:7px 10px; }

/* ── STATS ── */
.stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; padding:16px 24px 0; }
.stat-card {
    background: var(--bg-card, #131313);
    border: 1px solid var(--border, #222);
    border-radius: var(--radius, 14px);
    padding: 18px 20px;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 16px;
    min-height: 92px;
    position: relative;
    overflow: hidden;
}
.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 21px;
    flex-shrink: 0;
}
.si-a { background: rgba(139, 92, 246, 0.22); color: #a78bfa; border: 1px solid rgba(139, 92, 246, 0.4); }
.si-b { background: rgba(59, 130, 246, 0.22); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.4); }
.si-c { background: rgba(245, 158, 11, 0.22); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.4); }
.si-d { background: rgba(16, 185, 129, 0.22); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.4); }

.stat-label { font-size:11px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:var(--text-muted); }
.stat-num   { font-size:24px; font-weight:800; line-height:1.2; color:var(--text-light); }
.stat-hint  { font-size:11.5px; color:var(--text-muted); opacity:0.85; margin-top:2px; }
.s-a .stat-num { color: #f5f5f5; }
.s-b .stat-num { color: #60a5fa; }
.s-c .stat-num { color: #fbbf24; }
.s-d .stat-num { color: #34d399; }

/* ── LEADERBOARD ── */
.section-label { font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.8px; display:flex; align-items:center; gap:8px; padding:16px 24px 0; }
.section-label::after { content:''; flex:1; height:1px; background:var(--border); }
.leaderboard { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; padding:10px 24px 0; }
.podium-card {
    background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius);
    padding:20px; text-align:center; transition:var(--transition); position:relative; overflow:hidden;
}
.podium-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
.podium-card.rank-1::before { background:linear-gradient(90deg,#d4ac0d,var(--gold)); }
.podium-card.rank-2::before { background:linear-gradient(90deg,#95a5a6,var(--silver)); }
.podium-card.rank-3::before { background:linear-gradient(90deg,#a04000,var(--bronze)); }
.podium-card.rank-1 { border-color:rgba(241,196,15,.25); }
.podium-card:hover { transform:translateY(-2px); box-shadow:var(--shadow-md); }
.rank-badge {
    display:inline-flex; align-items:center; justify-content:center;
    width:28px; height:28px; border-radius:50%; font-size:13px; font-weight:800; margin-bottom:10px;
}
.rank-badge.r1 { background:rgba(241,196,15,.15); color:var(--gold); border:1px solid rgba(241,196,15,.3); }
.rank-badge.r2 { background:rgba(189,195,199,.12); color:var(--silver); border:1px solid rgba(189,195,199,.25); }
.rank-badge.r3 { background:rgba(205,127,50,.12);  color:var(--bronze); border:1px solid rgba(205,127,50,.25); }
.podium-avatar {
    width:56px; height:56px; border-radius:50%; display:flex; align-items:center; justify-content:center;
    font-size:20px; font-weight:700; color:#fff; margin:0 auto 10px; border:2px solid transparent;
    transition:var(--transition);
}
.rank-1 .podium-avatar { border-color:rgba(241,196,15,.4); box-shadow:0 0 16px rgba(241,196,15,.15); }
.podium-name { font-size:14px; font-weight:700; color:var(--text-light); margin-bottom:2px; }
.podium-title { font-size:11px; color:var(--text-muted); margin-bottom:10px; }
.podium-stats { display:flex; justify-content:center; gap:14px; }
.podium-stat { text-align:center; }
.podium-stat .ps-val { font-size:15px; font-weight:800; color:var(--accent); }
.podium-stat .ps-lbl { font-size:10px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.4px; }
.podium-img { width:56px; height:56px; border-radius:50%; object-fit:cover; display:block; margin:0 auto 10px; border:2px solid transparent; }
.rank-1 .podium-img { border-color:rgba(241,196,15,.4); box-shadow:0 0 16px rgba(241,196,15,.15); }
.top-badge { position:absolute; top:12px; right:12px; background:rgba(241,196,15,.15); color:var(--gold); border:1px solid rgba(241,196,15,.25); border-radius:20px; padding:2px 8px; font-size:10px; font-weight:700; }

/* ── CONTROLS BAR ── */
.controls-bar { display:flex; align-items:center; gap:8px; padding:14px 24px 0; flex-wrap:wrap; }
.filter-pill { display:inline-flex; align-items:center; gap:6px; padding:6px 13px; border-radius:50px; border:1px solid var(--border); background:var(--bg-card); color:var(--text-muted); font-size:12px; font-weight:600; cursor:pointer; transition:var(--transition); user-select:none; }
.filter-select { appearance:none; -webkit-appearance:none; padding:6px 30px 6px 14px; border-radius:50px; border:1px solid var(--border); background:var(--bg-card); color:var(--text); font-size:12px; font-weight:600; cursor:pointer; transition:var(--transition); font-family:inherit;
    background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%23999' stroke-width='3'><path d='M6 9l6 6 6-6'/></svg>"); background-repeat:no-repeat; background-position:right 11px center; }
.filter-select:hover { border-color:var(--accent); color:var(--accent); }
.filter-select:focus { outline:none; border-color:var(--accent); }
.filter-select option { background:var(--bg-card); color:var(--text); }
.filter-pill:hover { border-color:var(--accent); color:var(--accent); }
.filter-pill.role-empty { opacity:.45; }
.filter-pill.active { background:var(--accent); color:#000; border-color:var(--accent); }
.filter-pill.active-top  { background:var(--gold);   color:#000; border-color:var(--gold); }
.filter-pill.active-idle { background:var(--danger); color:#fff; border-color:var(--danger); }
<?php foreach ($_roles_db as $rslug => $rinfo):
    $rc = $rinfo['color'] ?? '#888'; ?>
.filter-pill.active-<?= $rslug ?> { background:<?= $rc ?>; color:#000; border-color:<?= $rc ?>; }
<?php endforeach; ?>
.filter-pill.active-general { background:#14b8a6; color:#000; border-color:#14b8a6; }
.pill-count { font-size:10px; font-weight:700; padding:1px 5px; border-radius:20px; background:rgba(255,255,255,.2); }
.ctrl-right { display:flex; align-items:center; gap:8px; margin-left:auto; }
.row-count  { font-size:12px; color:var(--text-muted); white-space:nowrap; }
.compact-btn { display:flex; align-items:center; gap:5px; padding:5px 10px; border-radius:7px; border:1px solid var(--border); background:transparent; color:var(--text-muted); font-size:11px; cursor:pointer; font-family:'Inter',sans-serif; transition:var(--transition); }
.compact-btn:hover { border-color:var(--border-hover); color:var(--text); }
.compact-btn.on { border-color:var(--accent); color:var(--accent); }

/* ── TABLE ── */
.table-card { margin:12px 24px 0; background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; box-shadow:var(--shadow-sm); }
.table-wrap { overflow:auto; max-height:calc(100vh - 420px); min-height:200px; }
table { width:100%; border-collapse:collapse; font-size:13px; }
thead { position:sticky; top:0; z-index:10; }
th { padding:10px 14px; text-align:left; font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.6px; color:var(--text-muted); background:var(--bg-card); border-bottom:1px solid var(--border); white-space:nowrap; cursor:pointer; user-select:none; transition:color .18s; }
th:hover { color:var(--accent); }
th.sorted { color:var(--accent); }
th .si { margin-left:4px; opacity:.3; font-size:9px; }
th.sorted .si { opacity:1; }
th:last-child { cursor:default; }
th:last-child:hover { color:var(--text-muted); }
td { padding:12px 14px; border-bottom:1px solid var(--border); color:var(--text); white-space:nowrap; transition:background .12s; vertical-align:middle; }
tr:last-child td { border-bottom:none; }
tbody tr:hover td { background:rgba(255,255,255,.025); }
tr.hidden { display:none!important; }
tr.compact td { padding:6px 14px; }
tfoot td { padding:10px 14px; font-size:11px; font-weight:700; border-top:2px solid var(--border); color:var(--text-muted); background:rgba(255,255,255,.015); }
.foot-val { color:var(--blue); font-size:13px; }

/* ── RANK CELL ── */
.rank-cell { font-size:13px; font-weight:700; color:var(--text-muted); text-align:center; min-width:36px; }
.rank-cell.r1 { color:var(--gold); }
.rank-cell.r2 { color:var(--silver); }
.rank-cell.r3 { color:var(--bronze); }

/* ── EMPLOYEE CELL ── */
.emp-cell { display:flex; align-items:center; gap:11px; }
.avatar { width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:700; color:#fff; flex-shrink:0; border:2px solid transparent; transition:var(--transition); }
.avatar-img { width:38px; height:38px; border-radius:50%; object-fit:cover; flex-shrink:0; border:2px solid transparent; transition:var(--transition); }
tbody tr:hover .avatar, tbody tr:hover .avatar-img { border-color:var(--accent); }
.emp-name  { font-size:13px; font-weight:600; color:var(--text-light); line-height:1.2; }
.emp-title { font-size:11px; color:var(--text-muted); }

/* ── ROLE BADGE ── */

/* ── GROUP SEPARATOR ── */
.group-sep { pointer-events:none; }
.group-sep td {
    padding:8px 14px 5px;
    font-size:10px; font-weight:700; letter-spacing:1px; text-transform:uppercase;
    border-bottom:1px solid var(--border);
    border-top:2px solid var(--border);
    background:var(--bg);
}
.group-sep td { color:var(--gs-color, var(--text-muted)); }
.group-sep .gc { display:inline-flex; align-items:center; gap:6px; }
.group-sep .gcnt {
    font-size:9px; padding:1px 6px; border-radius:20px;
    background:rgba(255,255,255,.08); font-weight:700;
}

/* ── PERFORMANCE BAR ── */
.perf-cell { min-width:120px; }
.perf-top  { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:4px; }
.perf-num  { font-size:13px; font-weight:700; color:var(--text-light); }
.perf-bar  { height:4px; background:rgba(255,255,255,.07); border-radius:4px; overflow:hidden; }
.perf-fill { height:100%; border-radius:4px; background:linear-gradient(90deg,var(--accent-dark),var(--accent)); transition:width .6s ease; }
.perf-sub  { font-size:10px; color:var(--text-muted); }

/* ── SMALL NUMBER ── */
.num-cell { font-size:13px; font-weight:600; color:var(--text-light); }
.num-zero { color:var(--text-muted); font-weight:400; }
.revenue  { color:var(--ok); font-weight:700; }

/* ── TENURE ── */
.tenure-cell { font-size:12px; color:var(--text-muted); display:flex; align-items:center; gap:5px; }

/* ── ROW ACTIONS ── */
.row-actions { display:flex; gap:5px; }
.btn-row { display:inline-flex; align-items:center; justify-content:center; gap:5px; padding:5px 9px; border-radius:8px; font-size:11px; font-weight:600; cursor:pointer; text-decoration:none; transition:var(--transition); border:1px solid transparent; font-family:'Inter',sans-serif; }
.btn-row.view { display:inline-flex; background:rgba(255,255,255,.04); border-color:var(--border); color:var(--text-muted); padding:5px 11px; }
.btn-row.view:hover { background:rgba(93,173,226,.12); border-color:#5dade2; color:#5dade2; }
[data-theme="light"] .btn-row.view { background:rgba(0,0,0,.03); }
/* Edit = subtle labelled button */
.btn-row.edit { background:rgba(255,255,255,.04); border-color:var(--border); color:var(--text-muted); padding:5px 11px; }
[data-theme="light"] .btn-row.edit { background:rgba(0,0,0,.03); }
.btn-row.edit:hover { background:rgba(209,144,75,.12); border-color:var(--accent); color:var(--accent); }
/* Delete = quiet labelled button, separated to prevent mis-click */
.btn-row.del  { background:transparent; color:var(--text-muted); padding:5px 11px; margin-left:8px; }
.btn-row.del:hover  { background:rgba(255,95,95,.12); color:var(--danger); }

/* ── INACTIVE TAG ── */
.inactive-tag { display:inline-flex; align-items:center; gap:5px; font-size:10px; font-weight:600; color:var(--text-muted); padding:2px 8px; border-radius:20px; background:rgba(255,255,255,.04); border:1px solid var(--border); }
[data-theme="light"] .inactive-tag { background:rgba(0,0,0,.03); }
.inactive-tag::before { content:''; width:6px; height:6px; border-radius:50%; background:var(--text-muted); opacity:.6; }

/* ── TABULAR FIGURES for scannable numeric columns ── */
.revenue, .perf-num, .num-cell, .lead-m b, .foot-val, .perf-sub, .stat-num { font-variant-numeric:tabular-nums; font-feature-settings:"tnum"; }

/* ── EMPTY STATE ── */
.empty-state { text-align:center; padding:60px 20px; }
.empty-state .ei { font-size:42px; color:var(--border-hover); margin-bottom:14px; }
.empty-state h3  { font-size:16px; font-weight:600; margin-bottom:6px; }
.empty-state p   { font-size:13px; color:var(--text-muted); margin-bottom:18px; }

/* ── TOAST ── */
#toast-cnt { position:fixed; bottom:24px; right:20px; z-index:99999; display:flex; flex-direction:column-reverse; gap:8px; pointer-events:none; }
.toast { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:11px 16px; font-size:13px; font-weight:500; color:var(--text); box-shadow:var(--shadow-md); display:flex; align-items:center; gap:10px; min-width:220px; max-width:320px; transform:translateX(120%); transition:transform .3s cubic-bezier(.34,1.56,.64,1); pointer-events:auto; }
.toast.show { transform:translateX(0); }
.toast.success { border-left:3px solid var(--ok); }
.toast.error   { border-left:3px solid var(--danger); }

/* ── DELETE MODAL ── */
.modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.72); backdrop-filter:blur(10px); z-index:9999; display:none; align-items:center; justify-content:center; padding:20px; }
.modal-overlay.open { display:flex; }
.modal-box { background:var(--bg-card); border:1px solid var(--border); border-radius:20px; padding:28px; max-width:360px; width:100%; box-shadow:0 24px 64px rgba(0,0,0,.65); animation:popIn .24s ease both; position:relative; }
@keyframes popIn { from{opacity:0;transform:scale(.92) translateY(14px)} to{opacity:1;transform:scale(1) translateY(0)} }
.modal-close { position:absolute; top:14px; right:14px; width:30px; height:30px; border-radius:50%; border:none; background:rgba(255,255,255,.06); color:var(--text-muted); font-size:14px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:var(--transition); }
.modal-close:hover { color:var(--text); background:rgba(255,255,255,.12); }
.modal-title { font-size:17px; font-weight:700; margin-bottom:4px; color:var(--danger); display:flex; align-items:center; gap:8px; }
.modal-sub   { font-size:12px; color:var(--text-muted); margin-bottom:18px; }
.modal-row   { display:flex; justify-content:space-between; align-items:center; padding:10px 14px; border-radius:10px; background:var(--bg-input); margin-bottom:16px; border:1px solid rgba(255,95,95,.2); background:rgba(255,95,95,.04); }
.modal-row .ml { font-size:11px; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:.4px; }
.modal-row .mv { font-size:14px; font-weight:700; color:var(--text-light); }
.btn-danger  { width:100%; padding:12px; border-radius:10px; border:none; background:var(--danger); color:#fff; font-size:14px; font-weight:700; cursor:pointer; transition:var(--transition); font-family:'Inter',sans-serif; display:flex; align-items:center; justify-content:center; gap:8px; }
.btn-danger:hover  { filter:brightness(1.1); transform:translateY(-1px); }
.btn-cancel  { width:100%; margin-top:8px; padding:10px; border-radius:10px; border:1px solid var(--border); background:transparent; color:var(--text-muted); font-size:13px; font-weight:600; cursor:pointer; transition:var(--transition); font-family:'Inter',sans-serif; }
.btn-cancel:hover  { border-color:var(--border-hover); color:var(--text); }

/* ── SHORTCUTS ── */
.sc-bar { position:fixed; bottom:16px; left:20px; display:flex; gap:14px; font-size:11px; color:var(--text-muted); pointer-events:none; }
.sc-key { background:rgba(255,255,255,.06); border:1px solid var(--border); border-radius:4px; padding:1px 5px; font-family:monospace; font-size:10px; }

/* ── RESPONSIVE ── */
@media (max-width:1100px) { .stats-row { grid-template-columns:repeat(2,1fr); } .leaderboard { grid-template-columns:repeat(2,1fr); } }
@media (max-width:900px)  { .topbar-center { display:none; } }
@media (max-width:768px)  {
    .stats-row,.controls-bar,.table-card,.leaderboard { margin-left:14px; margin-right:14px; }
    .section-label { padding-left:14px; padding-right:14px; }
    .topbar { padding:10px 14px; }
    td,th { padding:8px 10px; font-size:12px; }
    .stats-row { padding:12px 14px 0; }
    .hide-mob { display:none; }
    .sc-bar { display:none; }
    .leaderboard { grid-template-columns:1fr; }
    .table-wrap { max-height:calc(100vh - 280px); }
}
@media (max-width:480px) {
    .stats-row { grid-template-columns:1fr 1fr; }
}
/* ── MOBILE CARD LAYOUT (≤600px) ── */
@media (max-width:600px) {
    .table-card { border:none; background:transparent; box-shadow:none; margin:8px 12px 0; }
    .table-wrap { overflow:visible; max-height:none; min-height:0; }
    #empTable, #empTable tbody, #empTable tr, #empTable td { display:block; width:auto; }
    #empTable thead, #empTable tfoot { display:none; }
    tr.group-sep { margin:16px 2px 6px; }
    tr.group-sep td { border:none; background:transparent; padding:0 2px; }
    tr[data-name] { background:var(--bg-card); border:1px solid var(--border); border-radius:14px; padding:12px 14px; margin:0 0 10px; box-shadow:var(--shadow-sm); }
    tr[data-name] td { border:none; padding:6px 0; }
    .cell-rank, #empTable td.hide-mob { display:none !important; }
    .cell-emp { padding-bottom:10px !important; border-bottom:1px solid var(--border) !important; margin-bottom:4px; }
    .cell-role, .cell-orders, .cell-month { display:flex !important; align-items:center; justify-content:space-between; gap:12px; }
    td[data-label]::before { content:attr(data-label); font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); }
    .cell-orders .perf-cell { min-width:0; text-align:right; }
    .cell-orders .perf-bar { display:none; }
    .cell-actions { padding-top:10px !important; border-top:1px solid var(--border); margin-top:4px; }
    .row-actions { justify-content:flex-start; gap:8px; }
    .btn-row.view { background:rgba(255,255,255,.05); padding:6px 12px; }
    [data-theme="light"] .btn-row.view { background:rgba(0,0,0,.04); }
    .btn-row.del { margin-left:auto; }
}

@media print {
    .topbar,.controls-bar,.row-actions,#toast-cnt,.modal-overlay,.sc-bar { display:none!important; }
    body { background:#fff; color:#000; padding:0; }
    .table-card { box-shadow:none; border:1px solid #ccc; margin:0; }
    .table-wrap { max-height:none; overflow:visible; }
}
@media (prefers-reduced-motion:reduce) { *,*::before,*::after { transition:none!important; animation:none!important; } }

/* ── SHIFT BADGE ── */
.shift-badge { display:inline-flex; align-items:center; gap:4px; font-size:13px; font-weight:700; color:#ffffff; }
.shift-badge i { font-size:9px; }
.shift-pill i { color:inherit; }
.filter-pill.shift-active { background:var(--sb-active,var(--accent)); color:#000; border-color:var(--sb-active,var(--accent)); }
.filter-pill.shift-active i { color:inherit; }
.shift-sep { width:1px; height:20px; background:var(--border); margin:0 4px; flex-shrink:0; }

/* ── INLINE ROLE SELECTOR (dot + text) ── */
.role-wrap { display:inline-flex; align-items:center; gap:7px; padding:4px 11px 4px 10px; border-radius:50px; font-size:11px; font-weight:600; background:rgba(255,255,255,.035); border:1px solid var(--border); color:var(--text); transition:var(--transition); white-space:nowrap; }
[data-theme="light"] .role-wrap { background:rgba(0,0,0,.03); }
.role-wrap.editable:hover { border-color:var(--rb-color,var(--border-hover)); cursor:pointer; }
.role-dot { width:7px; height:7px; border-radius:50%; background:var(--rb-color,#888); box-shadow:0 0 0 3px color-mix(in srgb,var(--rb-color,#888) 22%,transparent); flex-shrink:0; }
.role-wrap i { pointer-events:none; }
.role-select { background:transparent; border:none; outline:none; color:var(--text); font-size:11px; font-weight:600; font-family:'Inter',sans-serif; cursor:pointer; max-width:90px; }
.role-select option { background:#1a1a1a; color:#f5f5f5; font-weight:600; font-size:12px; }
[data-theme="light"] .role-select option { background:#fff; color:#1a1410; }

/* ── EDIT MODAL ── */
.em-overlay { position:fixed; inset:0; background:rgba(0,0,0,.72); z-index:900; display:flex; align-items:center; justify-content:center; padding:16px; opacity:0; pointer-events:none; transition:opacity .25s ease; }
.em-overlay.open { opacity:1; pointer-events:all; }
.em-panel { background:var(--bg-card); border:1px solid var(--border); border-radius:20px; width:100%; max-width:760px; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,.6); transform:translateY(22px) scale(.97); transition:transform .28s cubic-bezier(.4,0,.2,1), opacity .25s ease; opacity:0; }
.em-overlay.open .em-panel { transform:none; opacity:1; }
.em-header { display:flex; align-items:center; gap:16px; padding:22px 26px 18px; border-bottom:1px solid var(--border); flex-shrink:0; }
.em-avatar-wrap { position:relative; flex-shrink:0; cursor:pointer; width:68px; height:68px; }
.em-avatar, .em-avatar-fallback { width:68px; height:68px; border-radius:50%; object-fit:cover; border:2px solid var(--accent); display:block; transition:var(--transition); }
.em-avatar-fallback { background:linear-gradient(135deg,var(--accent),var(--accent-dark,#a0702a)); color:#000; font-size:26px; font-weight:700; display:flex; align-items:center; justify-content:center; }
.em-avatar-overlay { position:absolute; inset:0; border-radius:50%; background:rgba(0,0,0,.5); display:flex; align-items:center; justify-content:center; opacity:0; transition:var(--transition); color:#fff; font-size:18px; }
.em-avatar-wrap:hover .em-avatar-overlay { opacity:1; }
.em-avatar-wrap:hover .em-avatar, .em-avatar-wrap:hover .em-avatar-fallback { transform:scale(1.05); }
.em-title { flex:1; }
.em-title h3 { font-size:17px; font-weight:700; margin-bottom:3px; }
.em-title p { font-size:12px; color:var(--text-muted); }
.em-close { width:34px; height:34px; border-radius:9px; border:1px solid var(--border); background:transparent; color:var(--text-muted); cursor:pointer; display:flex; align-items:center; justify-content:center; transition:var(--transition); font-size:16px; }
.em-close:hover { background:rgba(255,95,95,.1); color:var(--danger); border-color:rgba(255,95,95,.3); }
.em-body { overflow-y:auto; padding:22px 26px; flex:1; }
.em-grid { display:grid; grid-template-columns:1fr 1fr; gap:11px; }
.em-tile { background:rgba(255,255,255,.02); border:1px solid var(--border); border-radius:13px; padding:11px 14px; transition:var(--transition); }
.em-tile:focus-within { border-color:var(--accent); background:rgba(255,255,255,.04); box-shadow:0 0 0 3px rgba(209,144,75,.07); }
.em-tile.full { grid-column:1/-1; }
.em-label { font-size:10px; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:.6px; display:flex; align-items:center; gap:6px; margin-bottom:6px; }
.em-label i { font-size:10px; color:var(--accent); }
.em-input { width:100%; background:transparent; border:none; outline:none; color:var(--text); font-size:13px; font-weight:500; font-family:'Inter',sans-serif; }
.em-input::placeholder { color:var(--text-muted); font-weight:400; }
.em-input[type="date"], select.em-input { color-scheme:dark; }
select.em-input option { background-color: #1e1e24 !important; color: #ffffff !important; padding: 6px 10px; }
textarea.em-input { resize:vertical; min-height:62px; line-height:1.5; }
.em-role-grid { display:flex; flex-wrap:wrap; gap:8px; margin-top:4px; }
.em-role-opt { display:none; }
.em-role-label { display:flex; align-items:center; gap:8px; padding:8px 13px; border-radius:11px; border:1.5px solid var(--border); background:rgba(255,255,255,.02); cursor:pointer; transition:var(--transition); user-select:none; }
.em-role-label:hover { border-color:var(--border-hover); background:rgba(255,255,255,.04); }
.em-role-opt:checked + .em-role-label { border-color:var(--erc); background:var(--erc-bg); box-shadow:0 0 0 3px var(--erc-glow); }
.em-role-icon { width:28px; height:28px; border-radius:7px; display:flex; align-items:center; justify-content:center; font-size:12px; background:var(--erc-bg); color:var(--erc); flex-shrink:0; }
.em-role-name { font-size:12px; font-weight:600; }
.em-footer { display:flex; justify-content:flex-end; gap:10px; padding:16px 26px; border-top:1px solid var(--border); flex-shrink:0; }
.em-btn { padding:9px 20px; border-radius:11px; font-size:13px; font-weight:600; border:1px solid transparent; cursor:pointer; display:flex; align-items:center; gap:7px; transition:var(--transition); font-family:'Inter',sans-serif; }
.em-btn-cancel { border-color:var(--border-hover); color:var(--text-muted); background:transparent; }
.em-btn-cancel:hover { color:var(--text); border-color:var(--text-muted); }
.em-btn-save { background:linear-gradient(135deg,var(--accent),var(--accent-light,#e8b87a)); color:#000; box-shadow:0 4px 16px rgba(209,144,75,.25); border:none; }
.em-btn-save:hover { filter:brightness(1.1); transform:translateY(-1px); }
.em-btn-save:disabled { opacity:.6; cursor:not-allowed; transform:none; }
.em-photo-pill { display:none; align-items:center; gap:6px; font-size:11px; font-weight:500; color:var(--accent); background:rgba(209,144,75,.1); border:1px solid rgba(209,144,75,.2); padding:3px 10px; border-radius:20px; margin-top:6px; }

/* ── ELEVATION / DEPTH (subtle floating cards) ── */
.lead-card, .table-card { box-shadow: inset 0 1px 0 rgba(255,255,255,.05), 0 3px 14px rgba(0,0,0,.28); }
.lead-card:hover { box-shadow: inset 0 1px 0 rgba(255,255,255,.07), 0 8px 26px rgba(0,0,0,.42); }
[data-theme="light"] .lead-card, [data-theme="light"] .table-card { box-shadow: 0 1px 2px rgba(16,24,40,.05), 0 6px 18px rgba(16,24,40,.06); }
[data-theme="light"] .lead-card:hover { box-shadow: 0 2px 6px rgba(16,24,40,.08), 0 12px 28px rgba(16,24,40,.10); }

/* ── TOP PERFORMERS STRIP (compact leaderboard) ── */
.lead-strip { margin:16px 24px 0; }
.lead-strip-hd { font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.8px; display:flex; align-items:center; gap:7px; margin-bottom:9px; }
.lead-strip-hd i { color:var(--gold); }
.lead-cards { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; }
.lead-card { display:flex; align-items:center; gap:11px; padding:10px 14px; background:var(--bg-card); border:1px solid var(--border); border-left:3px solid var(--lc,var(--border)); border-radius:12px; transition:var(--transition); }
.lead-card:hover { border-color:var(--border-hover); border-left-color:var(--lc); transform:translateY(-1px); box-shadow:var(--shadow-sm); }
.lead-rank { color:var(--lc,var(--text-muted)); font-size:14px; width:16px; text-align:center; flex-shrink:0; }
.lead-av { width:38px; height:38px; border-radius:50%; object-fit:cover; flex-shrink:0; }
.lead-av-fb { display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:700; color:#fff; }
.lead-info { flex:1; min-width:0; display:flex; flex-direction:column; gap:1px; }
.lead-name { font-size:13px; font-weight:700; color:var(--text-light); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.lead-sub { font-size:11px; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.lead-metrics { display:flex; align-items:center; gap:16px; flex-shrink:0; }
.lead-m { display:flex; flex-direction:column; align-items:flex-end; line-height:1.15; }
.lead-m b { font-size:14px; font-weight:800; color:var(--text-light); }
.lead-m small { font-size:9px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.3px; }
@media (max-width:900px) { .lead-cards { grid-template-columns:1fr; } }

/* ── FILTER GROUPS ── */
.filter-group { display:inline-flex; align-items:center; gap:6px; }
.fg-label { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:var(--text-muted); opacity:.65; margin-right:1px; }
.fg-sep { width:1px; height:22px; background:var(--border); margin:0 4px; flex-shrink:0; }

/* ── UI CONFIRM MODAL (replaces native confirm) ── */
.ui-confirm-overlay { position:fixed; inset:0; z-index:10000; display:flex; align-items:center; justify-content:center; padding:20px; background:rgba(0,0,0,.66); backdrop-filter:blur(9px); opacity:0; pointer-events:none; transition:opacity .25s ease; }
.ui-confirm-overlay.open { opacity:1; pointer-events:auto; }
.ui-confirm-box { position:relative; width:100%; max-width:380px; background:var(--bg-card); border:1px solid var(--border); border-radius:22px; padding:30px 26px 24px; text-align:center; box-shadow:0 30px 80px rgba(0,0,0,.6); transform:translateY(20px) scale(.93); opacity:0; transition:transform .34s cubic-bezier(.34,1.56,.64,1), opacity .25s ease; overflow:hidden; }
.ui-confirm-overlay.open .ui-confirm-box { transform:none; opacity:1; }
.uc-glow { position:absolute; top:-64px; left:50%; transform:translateX(-50%); width:190px; height:120px; border-radius:50%; filter:blur(50px); opacity:.26; pointer-events:none; }
.uc-icon { position:relative; width:62px; height:62px; margin:0 auto 16px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:24px; color:var(--uc,#d1904b); animation:ucPop .42s cubic-bezier(.34,1.56,.64,1) both; }
.uc-icon::after { content:''; position:absolute; inset:-6px; border-radius:50%; border:2px solid var(--uc,#d1904b); opacity:.25; animation:ucRing 1.9s ease-out infinite; }
@keyframes ucRing { 0%{transform:scale(.9);opacity:.4} 70%{transform:scale(1.28);opacity:0} 100%{opacity:0} }
@keyframes ucPop  { from{transform:scale(.4);opacity:0} to{transform:scale(1);opacity:1} }
.uc-title { font-size:18px; font-weight:800; color:var(--text-light); margin-bottom:9px; }
.uc-roles { display:flex; align-items:center; justify-content:center; gap:10px; margin:4px 0 13px; flex-wrap:wrap; }
.uc-pill { display:inline-flex; align-items:center; gap:6px; padding:5px 12px; border-radius:20px; font-size:12px; font-weight:700; background:rgba(255,255,255,.06); border:1px solid var(--border); color:var(--text-muted); }
.uc-arrow { color:var(--text-muted); font-size:12px; animation:ucArrow 1.2s ease-in-out infinite; }
@keyframes ucArrow { 0%,100%{transform:translateX(0)} 50%{transform:translateX(4px)} }
.uc-msg { font-size:13px; color:var(--text-muted); line-height:1.6; margin-bottom:22px; }
.uc-msg b { color:var(--text); font-weight:700; }
.uc-actions { display:flex; gap:10px; }
.uc-btn { flex:1; padding:11px; border-radius:12px; font-size:13px; font-weight:700; cursor:pointer; font-family:'Inter',sans-serif; border:1px solid transparent; transition:var(--transition); }
.uc-cancel { background:transparent; border-color:var(--border-hover); color:var(--text-muted); }
.uc-cancel:hover { color:var(--text); border-color:var(--text-muted); }
.uc-ok { background:var(--uc,#d1904b); color:#fff; }
.uc-ok:hover { filter:brightness(1.09); transform:translateY(-1px); }
@media (prefers-reduced-motion:reduce){ .uc-icon::after,.uc-arrow{animation:none} }
</style>
</head>
<body>
<div class="flex h-screen w-screen overflow-hidden app-layout">
<?php require_once __DIR__ . '/sidebar.php'; ?>
<main class="app-main flex-1 h-full overflow-y-auto p-6">
<?php $page_title = __('nav_employees', 'Employees'); require __DIR__ . '/header_bar.php'; ?>



<!-- ── TOOLBAR ── -->
<div class="emp-toolbar" style="display:flex; align-items:center; gap:12px; margin:16px 24px 12px; flex-wrap:wrap;">
    <div class="search-wrap" style="display:flex; align-items:center; gap:10px; padding:10px 16px; border-radius:12px; border:1px solid var(--border); background:var(--bg-card,#131313); flex:1; max-width:500px; width:100%; transition:all 0.2s;">
        <i class="fa-solid fa-magnifying-glass" style="color:var(--text-muted);font-size:13px;flex-shrink:0;"></i>
        <input id="searchInput" placeholder="<?= __('search_emp_ph', 'Search by name or job title…') ?>" autocomplete="off" style="border:none;background:transparent;outline:none;color:var(--text);font-size:13px;font-family:inherit;width:100%;">
        <button id="clearSearch" onclick="clearSearch()" title="Clear search" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:13px;display:none;align-items:center;padding:0;"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="role-filter-wrap" style="position:relative; display:inline-flex; align-items:center;">
        <select id="roleFilter" onchange="applyFilters()" style="appearance:none; -webkit-appearance:none; padding:10px 36px 10px 16px; border-radius:12px; border:1px solid var(--border); background:var(--bg-card,#131313); color:var(--text); font-size:13px; font-weight:600; font-family:inherit; cursor:pointer; outline:none; transition:all 0.2s;">
            <option value="all"><?= __('all_roles', 'All Roles') ?></option>
            <?php foreach ($_roles_db as $_rs => $_ri): ?>
            <option value="<?= h($_rs) ?>"><?= h($_ri['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <i class="fa-solid fa-chevron-down" style="position:absolute; right:14px; color:var(--text-muted); font-size:11px; pointer-events:none;"></i>
    </div>
    <div style="display:flex; align-items:center; gap:10px; margin-left:auto;">
        <button type="button" onclick="openAddModal()" class="btn-nav primary" style="display:inline-flex; align-items:center; justify-content:center; gap:9px; padding:10px 28px; min-width:220px; border-radius:12px; background:var(--accent,#d1904b); color:#000; font-size:14px; font-weight:800; text-decoration:none; border:none; cursor:pointer; transition:all 0.2s; box-shadow:0 4px 16px rgba(209,144,75,0.25);">
            <i class="fa-solid fa-plus" style="font-size:15px"></i> <?= __('add_new_employee', 'Add New Employee') ?>
        </button>
    </div>
</div>

<!-- ── TABLE ── -->
<div class="table-card">
    <div class="table-wrap">
        <table id="empTable">
            <thead>
                <tr>
                    <th style="width:40px;text-align:center"><?= __('col_no', 'No') ?></th>
                    <th onclick="sortTable(1)"><?= __('col_staff_id', 'Staff ID') ?> <i class="fa-solid fa-sort si"></i></th>
                    <th style="width:52px"><?= __('image', 'Image') ?></th>
                    <th onclick="sortTable(3)"><?= __('col_name', 'Name') ?> <i class="fa-solid fa-sort si"></i></th>
                    <th onclick="sortTable(4)"><?= __('col_role', 'Role') ?> <i class="fa-solid fa-sort si"></i></th>
                    <th onclick="sortTable(5)"><?= __('col_phone', 'Phone') ?> <i class="fa-solid fa-sort si"></i></th>
                    <th onclick="sortTable(6)"><?= __('col_shift', 'Shift') ?> <i class="fa-solid fa-sort si"></i></th>
                    <th style="text-align:right"><?= __('actions', 'Actions') ?></th>
                </tr>
            </thead>
            <tbody id="tableBody">
<?php
foreach ($sorted_employees as $idx => $emp):
    $color   = avatarColor($emp['name']);
    $hasPhoto = !empty($emp['photo']) && file_exists($emp['photo']);
    $empRole  = $emp['emp_role'] ?? 'staff';
    $eid      = (int)$emp['employee_id'];
?>
                <tr data-name="<?= h(strtolower($emp['name'])) ?>"
                    data-title="<?= h(strtolower($emp['job_title'] ?? '')) ?>"
                    data-role="<?= h($empRole) ?>"
                    data-id="<?= $eid ?>">
                    <td style="text-align:center;font-weight:700;color:var(--text-muted);font-size:12px">
                        <?= $idx + 1 ?>
                    </td>
                    <td data-val="<?= $eid ?>" style="font-weight:700;font-size:12px;color:var(--accent)">
                        #STF-<?= $eid ?>
                    </td>
                    <td style="width:52px">
                        <?php if ($hasPhoto): ?>
                            <img src="<?= h($emp['photo']) ?>" class="avatar-img" alt="<?= h($emp['name']) ?>">
                        <?php else: ?>
                            <div class="avatar" style="background:<?= $color ?>"><?= initials($emp['name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td data-val="<?= h($emp['name']) ?>" class="cell-emp">
                        <div>
                            <div class="emp-name"><?= h($emp['name']) ?></div>
                        </div>
                    </td>
                    <td data-val="<?= h($empRole) ?>" class="cell-role" data-label="Role">
                        <?php
                            $_rinfo  = $_roles_db[$empRole] ?? ['name' => ucfirst($empRole), 'icon' => 'fa-user', 'color' => '#888'];
                            $_rbcol  = $_rinfo['color'] ?? '#888';
                            if ((int)($emp['is_pos'] ?? 1) === 0) {
                                $_rinfo['name'] = 'General';
                                $_rbcol = '#14b8a6';
                            }
                        ?>
                        <span class="role-wrap" style="--rb-bg:<?= $_rbcol ?>1a;--rb-color:<?= $_rbcol ?>;--rb-border:<?= $_rbcol ?>33">
                            <?= htmlspecialchars($_rinfo['name']) ?>
                        </span>
                    </td>
                    <td data-val="<?= h($emp['phone'] ?? '') ?>" style="font-size:12px;color:var(--text)">
                        <?= !empty($emp['phone']) ? h($emp['phone']) : '—' ?>
                    </td>
                    <td data-val="<?= h($emp['shift'] ?? '') ?>">
                        <?php if (!empty($emp['shift'])):
                            $shiftMeta = [
                                'normal'    => ['#ffffff','fa-clock', __('shift_normal', 'Normal')],
                                'morning'   => ['#ffffff','fa-sun', __('shift_morning', 'Morning')],
                                'afternoon' => ['#ffffff','fa-cloud-sun', __('shift_afternoon', 'Afternoon')],
                                'night'     => ['#ffffff','fa-moon', __('shift_night', 'Night')]
                            ];
                            [$sc,$si,$sl] = $shiftMeta[strtolower($emp['shift'])] ?? ['#ffffff','fa-clock',ucfirst($emp['shift'])];
                        ?>
                            <span class="shift-badge" style="color:#ffffff;font-size:13px;font-weight:700;"><?= $sl ?></span>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:13px;">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="cell-actions" style="text-align:right">
                        <div class="row-actions" style="justify-content:flex-end">
                            <button type="button" class="btn-row view" onclick="openViewModal(<?= $eid ?>)" title="View employee details">
                                <i class="fa-regular fa-eye"></i> <?= __('view_detail', 'View') ?>
                            </button>
                            <button class="btn-row edit" onclick="openEditModal(<?= $eid ?>)" title="Edit employee">
                                <i class="fa-solid fa-pen-to-square"></i> <?= __('edit', 'Edit') ?>
                            </button>
                            <?php if ($sess_role === 'admin'): ?>
                            <button class="btn-row del" onclick="confirmDelete(<?= $eid ?>,'<?= h($emp['name']) ?>')" title="Delete">
                                <i class="fa-solid fa-trash-can"></i> <?= __('delete', 'Delete') ?>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
            <tfoot id="tableFoot">
                <tr>
                    <td colspan="8" id="footLabel" style="color:var(--text-muted);font-size:11px;font-weight:700">
                        <?= $total_staff ?> employees
                    </td>
                </tr>
            </tfoot>
        </table>

        <div class="empty-state" id="emptyState" style="display:none">
            <div class="ei"><i class="fa-solid fa-users"></i></div>
            <h3>No employees found</h3>
            <p>Try adjusting your search or filter.</p>
            <button class="btn-nav" onclick="resetFilters()"><i class="fa-solid fa-rotate-left"></i> Reset filters</button>
        </div>
    </div>
</div>

<!-- ── DELETE MODAL ── -->
<div class="modal-overlay" id="deleteModal" onclick="if(event.target===this)closeDelete()">
    <div class="modal-box">
        <button class="modal-close" onclick="closeDelete()"><i class="fa-solid fa-xmark"></i></button>
        <div class="modal-title"><i class="fa-solid fa-triangle-exclamation"></i> Delete Employee</div>
        <div class="modal-sub">This will permanently remove the employee record. Order history is preserved.</div>
        <div class="modal-row">
            <span class="ml">Employee</span>
            <span class="mv" id="deleteName">—</span>
        </div>
        <input type="hidden" id="deleteId">
        <button id="deleteLink" class="btn-danger" onclick="executeDeleteEmployee()" style="border:none; cursor:pointer; width:100%; justify-content:center;">
            <i class="fa-solid fa-trash-can"></i> Delete Permanently
        </button>
        <button class="btn-cancel" onclick="closeDelete()">Cancel</button>
    </div>
</div>

<!-- ── UI CONFIRM MODAL ── -->
<div class="ui-confirm-overlay" id="uiConfirmOverlay">
  <div class="ui-confirm-box">
    <div class="uc-glow" id="ucGlow"></div>
    <div class="uc-icon" id="ucIcon"><i class="fa-solid fa-circle-question"></i></div>
    <h3 class="uc-title" id="ucTitle">Are you sure?</h3>
    <div class="uc-roles" id="ucRoles" style="display:none">
      <span class="uc-pill" id="ucFrom"></span>
      <i class="fa-solid fa-arrow-right uc-arrow"></i>
      <span class="uc-pill" id="ucTo"></span>
    </div>
    <p class="uc-msg" id="ucMsg"></p>
    <div class="uc-actions">
      <button class="uc-btn uc-cancel" id="ucCancel" type="button">Cancel</button>
      <button class="uc-btn uc-ok" id="ucOk" type="button">Confirm</button>
    </div>
  </div>
</div>

<div id="toast-cnt"></div>

<div class="sc-bar">
    <span><span class="sc-key">/</span> Search</span>
    <span><span class="sc-key">N</span> New employee</span>
    <span><span class="sc-key">Esc</span> Close</span>
</div>

<script>
const TOTAL = <?= $total_staff ?>;
const CSRF  = '<?= htmlspecialchars($_SESSION['csrf_token']) ?>';
const ROLES_INFO = <?= json_encode(array_combine(
    array_keys($_roles_db),
    array_map(fn($r) => ['name' => $r['name'], 'icon' => $r['icon'], 'color' => $r['color'] ?? '#888'], $_roles_db)
)) ?>;

/* ── COUNT-UP ── */
function countUp() {}

/* ── SEARCH ── */
let searchTimer;
const _searchInput = document.getElementById('searchInput');
if (_searchInput) {
    _searchInput.addEventListener('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(applyFilters, 150);
        const cs = document.getElementById('clearSearch');
        if (cs) cs.style.display = this.value.length > 0 ? 'inline-flex' : 'none';
    });
}
function clearSearch() {
    const si = document.getElementById('searchInput');
    if (si) si.value = '';
    const rf = document.getElementById('roleFilter');
    if (rf) rf.value = 'all';
    const cs = document.getElementById('clearSearch');
    if (cs) cs.style.display = 'none';
    applyFilters();
    if (si) si.focus();
}
function resetFilters() {
    clearSearch();
}

/* ── APPLY FILTERS ── */
function applyFilters() {
    const si   = document.getElementById('searchInput');
    const q    = si ? si.value.toLowerCase().trim() : '';
    const rf   = document.getElementById('roleFilter');
    const role = rf ? rf.value : 'all';
    const rows = document.querySelectorAll('#tableBody tr[data-name]');
    let shown  = 0;
    rows.forEach(row => {
        const nameOk = !q || row.dataset.name.includes(q) || row.dataset.title.includes(q);
        const roleOk = role === 'all' || row.dataset.role === role;
        const show   = nameOk && roleOk;
        row.classList.toggle('hidden', !show);
        if (show) shown++;
    });
    const empty = document.getElementById('emptyState');
    if (empty) empty.style.display = shown === 0 ? 'block' : 'none';
    const rc = document.getElementById('rowCount');
    if (rc) rc.textContent = `Showing ${shown} of ${TOTAL}`;
    const fl = document.getElementById('footLabel');
    if (fl) fl.textContent = `${shown} employee${shown !== 1 ? 's' : ''}`;
}

/* ── SORT ── */
let sortDir = {};
function sortTable(col) {
    const tbody = document.getElementById('tableBody');
    const rows  = [...tbody.querySelectorAll('tr[data-name]')];
    const asc   = sortDir[col] !== 'asc';
    sortDir = {}; sortDir[col] = asc ? 'asc' : 'desc';
    document.querySelectorAll('th').forEach((th, i) => {
        th.classList.toggle('sorted', i === col);
        const ic = th.querySelector('.si');
        if (ic) ic.className = i === col
            ? `fa-solid ${asc ? 'fa-sort-up' : 'fa-sort-down'} si`
            : 'fa-solid fa-sort si';
    });
    rows.sort((a, b) => {
        const av = a.children[col]?.dataset?.val ?? a.children[col]?.textContent?.trim() ?? '';
        const bv = b.children[col]?.dataset?.val ?? b.children[col]?.textContent?.trim() ?? '';
        const an = parseFloat(av), bn = parseFloat(bv);
        if (!isNaN(an) && !isNaN(bn)) return asc ? an - bn : bn - an;
        return asc ? av.localeCompare(bv) : bv.localeCompare(av);
    });
    rows.forEach(r => tbody.appendChild(r));
}

/* ── COMPACT ── */
function toggleCompact() {
    isCompact = !isCompact;
    document.querySelectorAll('#tableBody tr[data-name]').forEach(r => r.classList.toggle('compact', isCompact));
    document.getElementById('compactBtn').classList.toggle('on', isCompact);
}

/* ── DELETE CONFIRM ── */
let pendingDeleteEmpId = null;

function confirmDelete(id, name) {
    pendingDeleteEmpId = id;
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteName').textContent = name;
    document.getElementById('deleteModal').classList.add('open');
}
function closeDelete() {
    document.getElementById('deleteModal').classList.remove('open');
    pendingDeleteEmpId = null;
}

async function executeDeleteEmployee() {
    if (!pendingDeleteEmpId) return;
    const btn = document.getElementById('deleteLink');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting...';

    try {
        const res = await fetch(`employee_delete.php?id=${pendingDeleteEmpId}&ajax=1`);
        const j = await res.json();
        if (j.ok) {
            const row = document.querySelector(`#tableBody tr[data-id="${pendingDeleteEmpId}"]`);
            if (row) {
                const role = row.dataset.role;
                row.style.transition = 'all 0.3s ease';
                row.style.opacity = '0';
                row.style.transform = 'translateX(20px)';
                setTimeout(() => {
                    row.remove();
                    if (role) adjustPillCount(role, -1);
                    applyFilters();
                }, 300);
            }
            showToast('Employee deleted successfully', 'success');
        } else {
            showToast(j.error || 'Delete failed', 'error');
        }
    } catch {
        showToast('Network error', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-trash-can"></i> Delete Permanently';
        closeDelete();
    }
}

/* ── EXPORT CSV ── */
function exportCSV() {
    const headers = ['Rank','Name','Job Title','Role','Total Orders','This Month','Tenure'];
    const rows = [];
    document.querySelectorAll('#tableBody tr[data-name]:not(.hidden):not(.group-sep)').forEach((tr, i) => {
        const c = tr.querySelectorAll('td');
        rows.push([
            i + 1,
            c[1]?.querySelector('.emp-name')?.textContent?.trim() || '',
            c[1]?.querySelector('.emp-title')?.textContent?.trim() || '',
            c[2]?.querySelector('.role-wrap')?.textContent?.trim().replace(/\s+/g,' ') || '',
            c[3]?.dataset?.val || '0',
            c[4]?.dataset?.val || '0',
            c[c.length - 2]?.textContent?.trim().replace(/\s+/g,' ') || '',
        ]);
    });
    // Guard against CSV formula injection (Excel runs cells starting with = + - @ tab CR)
    const csvCell = v => { let s = String(v); if (/^[=+\-@\t\r]/.test(s)) s = "'" + s; return `"${s.replace(/"/g,'""')}"`; };
    const csv  = [headers, ...rows].map(r => r.map(csvCell).join(',')).join('\n');
    const blob = new Blob(['﻿' + csv], { type:'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `employees_${new Date().toISOString().slice(0,10)}.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
    showToast(`Exported ${rows.length} employee${rows.length !== 1 ? 's' : ''}`, 'success');
}

/* ── TOAST ── */
function showToast(msg, type = 'success') {
    const c = document.getElementById('toast-cnt');
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    const col = type === 'success' ? 'var(--ok)' : 'var(--danger)';
    const ico = type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation';
    t.innerHTML = `<i class="fa-solid ${ico}" style="color:${col};flex-shrink:0"></i><span>${msg}</span>`;
    c.appendChild(t);
    requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 380); }, 3500);
}

/* ── INLINE ROLE UPDATE ── */
/* ── ROLE CHANGE HELPERS ── */
function adjustPillCount(slug, delta) {
    const pill = document.querySelector(`.filter-pill[data-filter="${slug}"]`);
    if (!pill) return;
    const cnt = pill.querySelector('.pill-count');
    if (!cnt) return;
    const n = Math.max(0, (parseInt(cnt.textContent) || 0) + delta);
    cnt.textContent = n;
    pill.classList.toggle('role-empty', n === 0);
}

function recalcShiftCounts() {
    const counts = { morning: 0, afternoon: 0, night: 0 };
    document.querySelectorAll('#tableBody tr[data-id]').forEach(r => {
        const s = r.dataset.shift;
        if (s && counts[s] !== undefined) counts[s]++;
    });
    ['morning', 'afternoon', 'night'].forEach(s => {
        const pill = document.querySelector(`.shift-pill[data-shift-filter="${s}"]`);
        if (pill) { const cnt = pill.querySelector('.pill-count'); if (cnt) cnt.textContent = counts[s]; }
    });
}

function moveRowToGroup(row, prevRole, newRole, newInfo) {
    const tbody = document.getElementById('tableBody');
    tbody.appendChild(row);
}

/* ── REUSABLE STYLED CONFIRM (replaces native confirm) ── */
function uiConfirm(opts = {}) {
    return new Promise(resolve => {
        const ov     = document.getElementById('uiConfirmOverlay');
        const color  = opts.color || '#d1904b';
        const iconEl = document.getElementById('ucIcon');
        const okBtn  = document.getElementById('ucOk');
        const cancel = document.getElementById('ucCancel');

        document.getElementById('ucTitle').textContent = opts.title || 'Are you sure?';
        document.getElementById('ucMsg').innerHTML      = opts.message || '';
        iconEl.innerHTML = `<i class="fa-solid ${opts.icon || 'fa-circle-question'}"></i>`;
        iconEl.style.setProperty('--uc', color);
        iconEl.style.background = color + '24';
        iconEl.style.border     = '1px solid ' + color + '59';
        document.getElementById('ucGlow').style.background = color;
        okBtn.textContent     = opts.confirmText || 'Confirm';
        cancel.textContent    = opts.cancelText  || 'Cancel';
        okBtn.style.setProperty('--uc', color);

        const rolesWrap = document.getElementById('ucRoles');
        if (opts.fromRole && opts.toRole) {
            const fi = ROLES_INFO[opts.fromRole], ti = ROLES_INFO[opts.toRole];
            const from = document.getElementById('ucFrom'), to = document.getElementById('ucTo');
            from.innerHTML = `<i class="fa-solid ${fi?.icon || 'fa-user'}"></i> ${fi?.name || opts.fromRole}`;
            to.innerHTML   = `<i class="fa-solid ${ti?.icon || 'fa-user'}"></i> ${ti?.name || opts.toRole}`;
            to.style.background  = color + '22';
            to.style.borderColor = color + '66';
            to.style.color       = color;
            rolesWrap.style.display = 'flex';
        } else {
            rolesWrap.style.display = 'none';
        }

        ov.classList.add('open');
        setTimeout(() => okBtn.focus(), 60);

        function cleanup(val) {
            ov.classList.remove('open');
            okBtn.removeEventListener('click', onOk);
            cancel.removeEventListener('click', onCancel);
            ov.removeEventListener('click', onBack);
            document.removeEventListener('keydown', onKey);
            resolve(val);
        }
        const onOk     = () => cleanup(true);
        const onCancel = () => cleanup(false);
        const onBack   = e => { if (e.target === ov) cleanup(false); };
        const onKey    = e => {
            if (e.key === 'Escape') { e.stopPropagation(); cleanup(false); }
            else if (e.key === 'Enter') { e.preventDefault(); cleanup(true); }
        };
        okBtn.addEventListener('click', onOk);
        cancel.addEventListener('click', onCancel);
        ov.addEventListener('click', onBack);
        document.addEventListener('keydown', onKey);
    });
}

async function updateRole(eid, sel) {
    const newRole = sel.value;
    const wrap    = sel.closest('.role-wrap');
    const prev    = wrap.dataset.current;
    if (newRole === prev) return;
    const info = ROLES_INFO[newRole];
    const ok = await uiConfirm({
        title:       'Change Role?',
        message:     'Their page access updates <b>immediately</b>.',
        confirmText: 'Yes, change',
        icon:        info?.icon || 'fa-user-shield',
        color:       info?.color || '#d1904b',
        fromRole:    prev,
        toRole:      newRole
    });
    if (!ok) { sel.value = prev; return; }
    try {
        const fd = new FormData();
        fd.append('action', 'quick_role');
        fd.append('eid', eid);
        fd.append('role', newRole);
        fd.append('csrf_token', CSRF);
        const res = await fetch('employees.php', { method:'POST', body:fd });
        const j   = await res.json();
        if (j.ok) {
            const info  = ROLES_INFO[newRole];
            const color = info?.color || '#888';
            // Update badge
            wrap.style.setProperty('--rb-bg',     color + '1a');
            wrap.style.setProperty('--rb-color',  color);
            wrap.style.setProperty('--rb-border', color + '33');
            const icon = wrap.querySelector('i');
            if (icon && info?.icon) icon.className = `fa-solid ${info.icon}`;
            wrap.dataset.current = newRole;
            // Update row + move to correct group
            const row = wrap.closest('tr[data-role]');
            if (row) {
                row.dataset.role = newRole;
                // Job title follows the role — update the title text, keep the shift chip.
                if (j.job) {
                    const titleEl = row.querySelector('.emp-title');
                    if (titleEl) {
                        const shiftSpan = titleEl.querySelector('.shift-inline');
                        titleEl.textContent = j.job + ' ';
                        if (shiftSpan) titleEl.appendChild(shiftSpan);
                        row.dataset.title = j.job.toLowerCase();
                    }
                }
                moveRowToGroup(row, prev, newRole, info);
            }
            // Update filter pill counts
            adjustPillCount(prev, -1);
            adjustPillCount(newRole, +1);
            // Re-apply filters to handle visibility correctly
            applyFilters();
            showToast(`Role changed to ${info?.name || newRole}`, 'success');
        } else {
            sel.value = prev;
            showToast(j.msg || 'Failed to update role', 'error');
        }
    } catch(e) {
        sel.value = prev;
        showToast('Network error', 'error');
    }
}

/* ── THEME ── */
function toggleTheme() {
    const html  = document.documentElement;
    const icon  = document.getElementById('themeIcon');
    const light = html.getAttribute('data-theme') === 'light';
    if (light) { html.removeAttribute('data-theme'); icon.className='fa-solid fa-moon'; localStorage.setItem('theme','dark'); }
    else        { html.setAttribute('data-theme','light'); icon.className='fa-solid fa-sun'; localStorage.setItem('theme','light'); }
}

/* ── KEYBOARD ── */
document.addEventListener('keydown', e => {
    const tag = document.activeElement?.tagName;
    const inField = tag === 'INPUT' || tag === 'TEXTAREA';
    if (e.key === 'Escape') {
        if (document.getElementById('viewOverlay') && document.getElementById('viewOverlay').classList.contains('open')) { closeViewModal(); return; }
        if (document.getElementById('editOverlay').classList.contains('open')) { closeEditModal(); return; }
        closeDelete(); return;
    }
    if (inField) return;
    if (e.key === '/' || e.key === 'f') { const si = document.getElementById('searchInput'); if (si) { e.preventDefault(); si.focus(); } }
    if (e.key === 'n' || e.key === 'N') openAddModal();
});

/* ── TABLE HEIGHT (fill remaining viewport) ── */
function resizeTable() {
    const wrap = document.querySelector('.table-wrap');
    if (!wrap) return;
    const top = wrap.getBoundingClientRect().top + window.scrollY;
    const newMax = window.innerHeight - top - 32;
    wrap.style.maxHeight = Math.max(200, newMax) + 'px';
}

/* ── INIT ── */
document.addEventListener('DOMContentLoaded', () => {
    if (localStorage.getItem('theme') === 'light')
        document.getElementById('themeIcon').className = 'fa-solid fa-sun';
    countUp();
    resizeTable();
});
window.addEventListener('resize', resizeTable);
</script>
<script src="animations.js?v=<?= time() ?>"></script>

<!-- ── ADD EMPLOYEE MODAL ── -->
<div class="em-overlay" id="addOverlay" onclick="if(event.target===this)closeAddModal()">
  <div class="em-panel" role="dialog" aria-modal="true" aria-labelledby="addTitle">

    <div class="em-header">
      <label class="em-avatar-wrap" for="addPhotoInput" title="Upload photo">
        <img src="" class="em-avatar" id="addAvatar" alt="" style="display:none">
        <div class="em-avatar-fallback" id="addAvatarFb"><i class="fa-solid fa-user-plus"></i></div>
        <div class="em-avatar-overlay"><i class="fa-solid fa-camera"></i></div>
        <input type="file" id="addPhotoInput" accept="image/*" style="display:none">
      </label>
      <div class="em-title">
        <h3 id="addTitle">Add New Employee</h3>
        <p style="color:var(--text-muted);font-size:12px">Create a new employee profile</p>
        <div class="em-photo-pill" id="addPhotoPill" style="display:none"><i class="fa-solid fa-image"></i><span id="addPhotoName"></span></div>
      </div>
      <button class="em-close" onclick="closeAddModal()" title="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <form id="addForm" class="em-body" onsubmit="submitAddForm(event)">
      <input type="hidden" name="action" value="add_employee">

      <div class="em-grid">
        <div class="em-tile">
          <div class="em-label"><i class="fa-solid fa-user"></i> Full Name <span style="color:var(--accent)">*</span></div>
          <input class="em-input" type="text" name="name" id="addName" required placeholder="Enter full name">
        </div>
        <div class="em-tile">
          <div class="em-label"><i class="fa-solid fa-phone"></i> Phone Number</div>
          <input class="em-input" type="text" name="phone" id="addPhone" placeholder="012 345 678">
        </div>
        <div class="em-tile">
          <div class="em-label"><i class="fa-solid fa-briefcase"></i> Position <span style="color:var(--accent)">*</span></div>
          <div style="position:relative; display:flex; align-items:center;">
            <select class="em-input" name="job_title" id="addJob" required style="appearance:none; -webkit-appearance:none; padding-right:24px; cursor:pointer;">
              <option value="" disabled selected>— Select a position —</option>
              <?php foreach ($_roles_db as $_rk => $_rd): ?>
              <option value="<?= h($_rd['name']) ?>"><?= h($_rd['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <i class="fa-solid fa-chevron-down" style="position:absolute; right:4px; color:var(--text-muted); font-size:11px; pointer-events:none;"></i>
          </div>
        </div>
        <div class="em-tile">
          <div class="em-label"><i class="fa-solid fa-dollar-sign"></i> Monthly Salary</div>
          <input class="em-input" type="number" step="0.01" min="0" name="salary" id="addSalary" placeholder="0.00">
        </div>
        <div class="em-tile">
          <div class="em-label"><i class="fa-solid fa-calendar"></i> Date of Birth</div>
          <input class="em-input" type="date" name="date_of_birth" id="addDob">
        </div>
        <div class="em-tile">
          <div class="em-label"><i class="fa-solid fa-calendar-check"></i> Hire Date</div>
          <input class="em-input" type="date" name="hire_date" id="addHire" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="em-tile full">
          <div class="em-label"><i class="fa-solid fa-location-dot"></i> Address</div>
          <textarea class="em-input" name="address" id="addAddress" rows="2" placeholder="Employee address"></textarea>
        </div>
        <div class="em-tile full">
          <div class="em-label"><i class="fa-solid fa-clock"></i> Shift</div>
          <div class="em-role-grid" id="addShiftGrid">
            <input type="radio" class="em-role-opt" name="shift" id="addShift_normal" value="normal" checked>
            <label class="em-role-label" for="addShift_normal" style="--erc:#2ecc71;--erc-bg:#2ecc7122;--erc-glow:#2ecc7126">
              <div class="em-role-icon"><i class="fa-solid fa-clock"></i></div>
              <div style="display:flex; flex-direction:column; line-height:1.2;">
                <span class="em-role-name">Normal</span>
                <span style="font-size:10px; color:var(--text-muted); opacity:0.85; margin-top:2px;">8:00 AM - 5:00 PM</span>
              </div>
            </label>
            <input type="radio" class="em-role-opt" name="shift" id="addShift_morning" value="morning">
            <label class="em-role-label" for="addShift_morning" style="--erc:#f39c12;--erc-bg:#f39c1222;--erc-glow:#f39c1226">
              <div class="em-role-icon"><i class="fa-solid fa-sun"></i></div>
              <div style="display:flex; flex-direction:column; line-height:1.2;">
                <span class="em-role-name">Morning</span>
                <span style="font-size:10px; color:var(--text-muted); opacity:0.85; margin-top:2px;">6:00 AM - 2:00 PM</span>
              </div>
            </label>
            <input type="radio" class="em-role-opt" name="shift" id="addShift_afternoon" value="afternoon">
            <label class="em-role-label" for="addShift_afternoon" style="--erc:#3498db;--erc-bg:#3498db22;--erc-glow:#3498db26">
              <div class="em-role-icon"><i class="fa-solid fa-cloud-sun"></i></div>
              <div style="display:flex; flex-direction:column; line-height:1.2;">
                <span class="em-role-name">Afternoon</span>
                <span style="font-size:10px; color:var(--text-muted); opacity:0.85; margin-top:2px;">1:00 PM - 10:00 PM</span>
              </div>
            </label>
          </div>
        </div>
      </div>

      <div class="em-footer" style="padding:16px 0 0; margin-top:16px; border-top:1px solid var(--border);">
        <button type="button" class="em-btn em-btn-cancel" onclick="closeAddModal()">
          <i class="fa-solid fa-xmark"></i> Cancel
        </button>
        <button type="submit" class="em-btn em-btn-save" id="addSaveBtn">
          <i class="fa-solid fa-plus"></i> Add Employee
        </button>
      </div>
    </form>

  </div>
</div>

<script>
let _addPhotoFile = null;

function openAddModal() {
    _addPhotoFile = null;
    document.getElementById('addForm').reset();
    document.getElementById('addAvatar').style.display = 'none';
    document.getElementById('addAvatarFb').style.display = 'flex';
    document.getElementById('addPhotoPill').style.display = 'none';
    document.getElementById('addHire').value = new Date().toISOString().split('T')[0];
    const btn = document.getElementById('addSaveBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-plus"></i> Add Employee';
    document.getElementById('addOverlay').classList.add('open');
}

function closeAddModal() {
    document.getElementById('addOverlay').classList.remove('open');
}

document.getElementById('addPhotoInput').addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    _addPhotoFile = file;
    const reader = new FileReader();
    reader.onload = e => {
        const img = document.getElementById('addAvatar');
        const fb  = document.getElementById('addAvatarFb');
        img.src = e.target.result;
        img.style.display = 'block';
        fb.style.display  = 'none';
    };
    reader.readAsDataURL(file);
    const pill = document.getElementById('addPhotoPill');
    document.getElementById('addPhotoName').textContent = file.name;
    pill.style.display = 'flex';
});

async function submitAddForm(e) {
    e.preventDefault();
    const btn = document.getElementById('addSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Adding…';

    const form = document.getElementById('addForm');
    const fd   = new FormData(form);
    fd.append('csrf_token', CSRF);
    if (_addPhotoFile) fd.set('photo', _addPhotoFile);

    try {
        const res = await fetch('employees.php', { method: 'POST', body: fd });
        const j   = await res.json();
        if (j.ok) {
            showToast(j.msg || 'Employee added successfully', 'success');
            closeAddModal();
            setTimeout(() => window.location.reload(), 500);
        } else {
            showToast(j.msg || 'Failed to add employee', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-plus"></i> Add Employee';
        }
    } catch(err) {
        showToast('Network error', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-plus"></i> Add Employee';
    }
}
</script>

<!-- ── VIEW EMPLOYEE MODAL ── -->
<div class="em-overlay" id="viewOverlay" onclick="if(event.target===this)closeViewModal()">
  <div class="em-panel" style="max-width:780px; width:92%;" role="dialog" aria-modal="true" aria-labelledby="viewName">
    <div class="em-header" style="align-items:center; padding:24px 28px 20px; border-bottom:1px solid var(--border);">
      <div class="em-avatar-wrap" style="width:100px; height:100px; cursor:default; border-radius:20px; flex-shrink:0;">
        <img src="" class="em-avatar" id="viewAvatar" alt="" style="display:none; width:100px; height:100px; border-radius:20px; object-fit:cover;">
        <div class="em-avatar-fallback" id="viewAvatarFb" style="width:100px; height:100px; border-radius:20px; font-size:38px; font-weight:800; display:flex; align-items:center; justify-content:center; background:rgba(209,144,75,0.15); color:var(--accent);">?</div>
      </div>
      <div class="em-title" style="margin-left:18px;">
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
          <h3 id="viewName" style="font-size:22px; font-weight:800; color:var(--text); letter-spacing:-0.3px;">Employee Name</h3>
          <span class="role-pill" id="viewRolePill" style="margin:0; padding:5px 14px; font-size:13px;"><span class="role-dot" id="viewRoleDot" style="width:8px; height:8px;"></span><span id="viewRoleText">Staff</span></span>
        </div>
        <p id="viewJobTitle" style="color:var(--accent); font-size:14px; font-weight:700; margin-top:4px;">Position</p>
        <p id="viewStaffId" style="color:var(--text-muted); font-size:12px; margin-top:2px; font-weight:500;">#STF-0</p>
      </div>
      <button class="em-close" onclick="closeViewModal()" title="Close" style="width:38px; height:38px; font-size:18px;"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div class="em-body" style="padding:24px 28px;">
      <div class="em-grid" style="gap:16px;">
        <div class="em-tile" style="padding:14px 18px; border-radius:14px;">
          <div class="em-label" style="font-size:11px; margin-bottom:8px;"><i class="fa-solid fa-phone" style="font-size:12px;"></i> Phone Number</div>
          <div id="viewPhone" style="font-size:14px; font-weight:600; color:var(--text)">—</div>
        </div>
        <div class="em-tile" style="padding:14px 18px; border-radius:14px;">
          <div class="em-label" style="font-size:11px; margin-bottom:8px;"><i class="fa-solid fa-clock" style="font-size:12px;"></i> Shift</div>
          <div id="viewShift" style="font-size:14px; font-weight:600; color:var(--text)">—</div>
        </div>
        <div class="em-tile" style="padding:14px 18px; border-radius:14px;">
          <div class="em-label" style="font-size:11px; margin-bottom:8px;"><i class="fa-solid fa-dollar-sign" style="font-size:12px;"></i> Monthly Salary</div>
          <div id="viewSalary" style="font-size:15px; font-weight:700; color:var(--accent)">$0.00</div>
        </div>
        <div class="em-tile" style="padding:14px 18px; border-radius:14px;">
          <div class="em-label" style="font-size:11px; margin-bottom:8px;"><i class="fa-solid fa-calendar" style="font-size:12px;"></i> Date of Birth</div>
          <div id="viewDob" style="font-size:14px; font-weight:600; color:var(--text)">Not set</div>
        </div>
        <div class="em-tile" style="padding:14px 18px; border-radius:14px;">
          <div class="em-label" style="font-size:11px; margin-bottom:8px;"><i class="fa-solid fa-calendar-check" style="font-size:12px;"></i> Hire Date</div>
          <div id="viewHire" style="font-size:14px; font-weight:600; color:var(--text)">Not set</div>
        </div>
        <div class="em-tile" style="padding:14px 18px; border-radius:14px;">
          <div class="em-label" style="font-size:11px; margin-bottom:8px;"><i class="fa-solid fa-user-clock" style="font-size:12px;"></i> Tenure / Experience</div>
          <div id="viewTenure" style="font-size:14px; font-weight:600; color:var(--text)">—</div>
        </div>
        <div class="em-tile full" style="padding:16px 18px; border-radius:14px;">
          <div class="em-label" style="font-size:11px; margin-bottom:8px;"><i class="fa-solid fa-location-dot" style="font-size:12px;"></i> Address</div>
          <div id="viewAddress" style="font-size:14px; font-weight:500; color:var(--text); line-height:1.5;">Not set</div>
        </div>
      </div>

      <div class="em-footer" style="padding:20px 0 0; margin-top:20px; border-top:1px solid var(--border); gap:12px;">
        <button type="button" class="em-btn em-btn-cancel" onclick="closeViewModal()" style="padding:10px 22px; font-size:14px;">
          <i class="fa-solid fa-xmark"></i> Close
        </button>
        <button type="button" class="em-btn em-btn-save" id="viewEditBtn" onclick="closeViewModal(); openEditModal(_viewCurrentEid)" style="padding:10px 24px; font-size:14px;">
          <i class="fa-solid fa-pen-to-square"></i> Edit Profile
        </button>
      </div>
    </div>
  </div>
</div>

<script>
let _viewCurrentEid = 0;

function openViewModal(eid) {
    _viewCurrentEid = eid;
    fetch(`employees.php?action=get_employee&eid=${eid}`)
        .then(r => r.json())
        .then(j => {
            if (!j.ok) { showToast('Could not load employee details', 'error'); return; }
            const emp = j.emp;

            document.getElementById('viewName').textContent = emp.name || 'Unnamed Staff';
            document.getElementById('viewJobTitle').textContent = emp.job_title || 'No position';
            document.getElementById('viewStaffId').textContent = '#STF-' + emp.employee_id;
            document.getElementById('viewPhone').textContent = emp.phone || '—';
            document.getElementById('viewSalary').textContent = '$' + (parseFloat(emp.salary || 0).toFixed(2));
            document.getElementById('viewAddress').textContent = emp.address || 'Not set';

            // Avatar
            const img = document.getElementById('viewAvatar');
            const fb  = document.getElementById('viewAvatarFb');
            if (emp.photo) {
                img.src = emp.photo;
                img.style.display = 'block';
                fb.style.display  = 'none';
            } else {
                img.style.display = 'none';
                fb.style.display  = 'flex';
                fb.textContent    = (emp.name || '?')[0].toUpperCase();
            }

            // Role Pill
            const role = emp.emp_role || 'staff';
            const rinfo = ROLES_INFO[role] || { name: role, color: '#888' };
            document.getElementById('viewRoleDot').style.background = rinfo.color || '#888';
            document.getElementById('viewRoleText').textContent = rinfo.name || role;

            // Shift
            const shiftMap = {
                normal: 'Normal (8:00 AM - 5:00 PM)',
                morning: 'Morning (6:00 AM - 2:00 PM)',
                afternoon: 'Afternoon (1:00 PM - 10:00 PM)'
            };
            document.getElementById('viewShift').textContent = shiftMap[emp.shift] || (emp.shift ? emp.shift : '—');

            // DOB & Age
            if (emp.date_of_birth) {
                const dobDate = new Date(emp.date_of_birth);
                const age = new Date().getFullYear() - dobDate.getFullYear();
                document.getElementById('viewDob').textContent = dobDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ` (${age} yrs)`;
            } else {
                document.getElementById('viewDob').textContent = 'Not set';
            }

            // Hire & Tenure
            if (emp.hire_date) {
                const hireDate = new Date(emp.hire_date);
                document.getElementById('viewHire').textContent = hireDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                
                const now = new Date();
                const months = (now.getFullYear() - hireDate.getFullYear()) * 12 + (now.getMonth() - hireDate.getMonth());
                if (months < 1) {
                    document.getElementById('viewTenure').textContent = 'Less than 1 month';
                } else {
                    const yrs = Math.floor(months / 12);
                    const mos = months % 12;
                    let tStr = [];
                    if (yrs > 0) tStr.push(yrs + (yrs === 1 ? ' yr' : ' yrs'));
                    if (mos > 0) tStr.push(mos + ' mo');
                    document.getElementById('viewTenure').textContent = tStr.join(', ');
                }
            } else {
                document.getElementById('viewHire').textContent = 'Not set';
                document.getElementById('viewTenure').textContent = '—';
            }

            document.getElementById('viewOverlay').classList.add('open');
        })
        .catch(() => showToast('Network error', 'error'));
}

function closeViewModal() {
    document.getElementById('viewOverlay').classList.remove('open');
}
</script>

<!-- ── EDIT EMPLOYEE MODAL ── -->
<div class="em-overlay" id="editOverlay" onclick="if(event.target===this)closeEditModal()">
  <div class="em-panel" role="dialog" aria-modal="true" aria-labelledby="emTitle">

    <div class="em-header">
      <label class="em-avatar-wrap" for="emPhotoInput" title="Change photo">
        <img src="" class="em-avatar" id="emAvatar" alt="" style="display:none">
        <div class="em-avatar-fallback" id="emAvatarFb"></div>
        <div class="em-avatar-overlay"><i class="fa-solid fa-camera"></i></div>
        <input type="file" id="emPhotoInput" accept="image/*" style="display:none">
      </label>
      <div class="em-title">
        <h3 id="emTitle">Edit Employee</h3>
        <p id="emSubtitle" style="color:var(--text-muted);font-size:12px"></p>
        <div class="em-photo-pill" id="emPhotoPill"><i class="fa-solid fa-image"></i><span id="emPhotoName"></span></div>
      </div>
      <button class="em-close" onclick="closeEditModal()" title="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <form id="emForm" class="em-body" onsubmit="submitEditForm(event)">
      <input type="hidden" id="emEid" name="eid">
      <input type="hidden" name="action" value="edit_employee">

      <div class="em-grid">
        <div class="em-tile">
          <div class="em-label"><i class="fa-solid fa-user"></i> Full Name</div>
          <input class="em-input" type="text" name="name" id="emName" required placeholder="Employee name">
        </div>
        <div class="em-tile">
          <div class="em-label"><i class="fa-solid fa-phone"></i> Phone Number</div>
          <input class="em-input" type="text" name="phone" id="emPhone" placeholder="Phone number">
        </div>
        <div class="em-tile">
          <div class="em-label"><i class="fa-solid fa-briefcase"></i> Job Title <span id="emJobHint" style="font-weight:400;text-transform:none;letter-spacing:0;opacity:.6">(editable)</span></div>
          <input class="em-input" type="text" name="job_title" id="emJob"
                 placeholder="e.g. Senior Barista" title="Enter or edit job title">
        </div>
        <div class="em-tile">
          <div class="em-label"><i class="fa-solid fa-dollar-sign"></i> Salary</div>
          <input class="em-input" type="number" step="0.01" name="salary" id="emSalary" placeholder="0.00">
        </div>
        <div class="em-tile">
          <div class="em-label"><i class="fa-solid fa-calendar"></i> Date of Birth</div>
          <input class="em-input" type="date" name="date_of_birth" id="emDob">
        </div>
        <div class="em-tile">
          <div class="em-label"><i class="fa-solid fa-calendar-check"></i> Hire Date</div>
          <input class="em-input" type="date" name="hire_date" id="emHire">
        </div>
        <div class="em-tile full">
          <div class="em-label"><i class="fa-solid fa-location-dot"></i> Address</div>
          <textarea class="em-input" name="address" id="emAddress" rows="3" placeholder="Employee address"></textarea>
        </div>
        <div class="em-tile full">
          <div class="em-label"><i class="fa-solid fa-shield-halved"></i> Role</div>
          <div class="em-role-grid" id="emRoleGrid">
            <?php foreach ($_roles_db as $rslug => $rdata):
                if ($rslug === 'admin' && ($_SESSION['role'] ?? '') !== 'admin') continue;
                $rc = $rdata['color'] ?? '#888';
            ?>
            <input type="radio" class="em-role-opt" name="emp_role"
                   id="emRole_<?= $rslug ?>" value="<?= h($rslug) ?>">
            <label class="em-role-label" for="emRole_<?= $rslug ?>"
                   style="--erc:<?= $rc ?>;--erc-bg:<?= $rc ?>22;--erc-glow:<?= $rc ?>26">
              <div class="em-role-icon"><i class="fa-solid <?= htmlspecialchars($rdata['icon']) ?>"></i></div>
              <span class="em-role-name"><?= htmlspecialchars($rdata['name']) ?></span>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="em-tile full">
          <div class="em-label"><i class="fa-solid fa-clock"></i> Shift</div>
          <div class="em-role-grid" id="emShiftGrid">
            <input type="radio" class="em-role-opt" name="shift" id="emShift_normal" value="normal">
            <label class="em-role-label" for="emShift_normal" style="--erc:#2ecc71;--erc-bg:#2ecc7122;--erc-glow:#2ecc7126">
              <div class="em-role-icon"><i class="fa-solid fa-clock"></i></div>
              <div style="display:flex; flex-direction:column; line-height:1.2;">
                <span class="em-role-name">Normal</span>
                <span style="font-size:10px; color:var(--text-muted); opacity:0.85; margin-top:2px;">8:00 AM - 5:00 PM</span>
              </div>
            </label>
            <input type="radio" class="em-role-opt" name="shift" id="emShift_morning" value="morning">
            <label class="em-role-label" for="emShift_morning" style="--erc:#f39c12;--erc-bg:#f39c1222;--erc-glow:#f39c1226">
              <div class="em-role-icon"><i class="fa-solid fa-sun"></i></div>
              <div style="display:flex; flex-direction:column; line-height:1.2;">
                <span class="em-role-name">Morning</span>
                <span style="font-size:10px; color:var(--text-muted); opacity:0.85; margin-top:2px;">6:00 AM - 2:00 PM</span>
              </div>
            </label>
            <input type="radio" class="em-role-opt" name="shift" id="emShift_afternoon" value="afternoon">
            <label class="em-role-label" for="emShift_afternoon" style="--erc:#3498db;--erc-bg:#3498db22;--erc-glow:#3498db26">
              <div class="em-role-icon"><i class="fa-solid fa-cloud-sun"></i></div>
              <div style="display:flex; flex-direction:column; line-height:1.2;">
                <span class="em-role-name">Afternoon</span>
                <span style="font-size:10px; color:var(--text-muted); opacity:0.85; margin-top:2px;">1:00 PM - 10:00 PM</span>
              </div>
            </label>
          </div>
        </div>
      </div>

      <!-- footer inside form so submit button works -->
      <div class="em-footer" style="padding:16px 0 0; margin-top:16px; border-top:1px solid var(--border);">
        <button type="button" class="em-btn em-btn-cancel" onclick="closeEditModal()">
          <i class="fa-solid fa-xmark"></i> Cancel
        </button>
        <button type="submit" class="em-btn em-btn-save" id="emSaveBtn">
          <i class="fa-solid fa-floppy-disk"></i> Save Changes
        </button>
      </div>
    </form>

  </div>
</div>

<script>
/* ── EDIT MODAL ── */
let _emPhotoFile = null;

/* Job title follows the selected role (single source of truth). */
const ROLE_TITLES = <?= json_encode(array_map(fn($r) => $r['name'], $_roles_db)) ?>;
function syncJobTitle() {
    const sel = document.querySelector('#emRoleGrid input[name="emp_role"]:checked');
    const job = document.getElementById('emJob');
    if (sel && ROLE_TITLES[sel.value]) job.value = ROLE_TITLES[sel.value];
}
document.querySelectorAll('#emRoleGrid input[name="emp_role"]').forEach(r =>
    r.addEventListener('change', syncJobTitle));

function openEditModal(eid) {
    _emPhotoFile = null;
    document.getElementById('emPhotoPill').style.display = 'none';
    document.getElementById('emPhotoInput').value = '';
    const btn = document.getElementById('emSaveBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';

    fetch(`employees.php?action=get_employee&eid=${eid}`)
        .then(r => r.json())
        .then(j => {
            if (!j.ok) { showToast('Could not load employee data', 'error'); return; }
            const emp = j.emp;

            document.getElementById('emEid').value    = emp.employee_id;
            document.getElementById('emName').value   = emp.name || '';
            document.getElementById('emPhone').value  = emp.phone || '';
            document.getElementById('emJob').value    = emp.job_title || '';
            document.getElementById('emSalary').value = emp.salary || '';
            document.getElementById('emDob').value    = emp.date_of_birth || '';
            document.getElementById('emHire').value   = emp.hire_date || '';
            document.getElementById('emAddress').value= emp.address || '';

            // Avatar
            const img = document.getElementById('emAvatar');
            const fb  = document.getElementById('emAvatarFb');
            if (emp.photo) {
                img.src = emp.photo;
                img.style.display = 'block';
                fb.style.display  = 'none';
            } else {
                img.style.display = 'none';
                fb.style.display  = 'flex';
                fb.textContent    = (emp.name || '?')[0].toUpperCase();
            }

            // Title
            document.getElementById('emTitle').textContent   = emp.name || 'Edit Employee';
            document.getElementById('emSubtitle').textContent= emp.job_title || '';

            // Job title is fully editable
            const jobEl = document.getElementById('emJob');
            if (jobEl) {
                jobEl.readOnly = false;
                jobEl.style.cursor = 'text';
                jobEl.style.opacity = '1';
            }
            const roleTile = document.getElementById('emRoleGrid').closest('.em-tile');
            if (roleTile) roleTile.style.display = '';

            // Role
            const role = emp.emp_role || 'staff';
            const rb = document.querySelector(`#emRoleGrid input[value="${role}"]`);
            if (rb) rb.checked = true;
            else document.querySelectorAll('#emRoleGrid input[type="radio"]').forEach(r => r.checked = false);

            // Shift
            const shiftVal = (emp.shift || 'normal').toLowerCase();
            const sb = document.querySelector(`#emShiftGrid input[value="${shiftVal}"]`);
            if (sb) {
                sb.checked = true;
            } else {
                const ns = document.getElementById('emShift_normal');
                if (ns) ns.checked = true;
            }

            document.getElementById('editOverlay').classList.add('open');
        })
        .catch(() => showToast('Network error', 'error'));
}

function closeEditModal() {
    document.getElementById('editOverlay').classList.remove('open');
    const btn = document.getElementById('emSaveBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';
}

document.getElementById('emPhotoInput').addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    _emPhotoFile = file;
    const reader = new FileReader();
    reader.onload = e => {
        const img = document.getElementById('emAvatar');
        const fb  = document.getElementById('emAvatarFb');
        img.src = e.target.result;
        img.style.display = 'block';
        fb.style.display  = 'none';
    };
    reader.readAsDataURL(file);
    const pill = document.getElementById('emPhotoPill');
    document.getElementById('emPhotoName').textContent = file.name;
    pill.style.display = 'flex';
});

async function submitEditForm(e) {
    e.preventDefault();
    const btn = document.getElementById('emSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const form = document.getElementById('emForm');
    const fd   = new FormData(form);
    fd.append('csrf_token', CSRF);
    if (_emPhotoFile) fd.set('photo', _emPhotoFile);

    try {
        const res = await fetch('employees.php', { method: 'POST', body: fd });
        const j   = await res.json();
        if (j.ok) {
            const eid  = parseInt(document.getElementById('emEid').value);
            const row  = document.querySelector(`#tableBody tr[data-id="${eid}"]`);
            if (row) {
                // Update name + job title
                const nameEl = row.querySelector('.emp-name');
                const titleEl = row.querySelector('.emp-title');
                if (nameEl) nameEl.textContent = j.name;
                if (titleEl) {
                    const existingShiftSpan = titleEl.querySelector('.shift-inline');
                    titleEl.textContent = j.job;
                    if (existingShiftSpan) titleEl.appendChild(existingShiftSpan);
                }
                // Keep data-name / data-title in sync for search
                row.dataset.name  = j.name.toLowerCase();
                row.dataset.title = j.job.toLowerCase();

                // Update shift cell in table
                const shiftCell = row.querySelectorAll('td')[6] || row.children[6];
                const shiftMeta = {
                    normal:    ['#ffffff', 'fa-clock', 'Normal'],
                    morning:   ['#ffffff', 'fa-sun', 'Morning'],
                    afternoon: ['#ffffff', 'fa-cloud-sun', 'Afternoon'],
                    night:     ['#ffffff', 'fa-moon', 'Night']
                };
                if (shiftCell) {
                    const sVal = (j.shift || '').toLowerCase();
                    shiftCell.dataset.val = sVal;
                    if (sVal && shiftMeta[sVal]) {
                        const [sc, si, sl] = shiftMeta[sVal];
                        shiftCell.innerHTML = `<span class="shift-badge" style="color:#ffffff;font-size:13px;font-weight:700;">${sl}</span>`;
                    } else {
                        shiftCell.innerHTML = `<span style="color:var(--text-muted);font-size:13px;">—</span>`;
                    }
                }
                
                // Update avatar photo
                if (j.photo) {
                    const av = row.querySelector('.avatar-img, .avatar');
                    if (av) {
                        const img = document.createElement('img');
                        img.className = 'avatar-img';
                        img.src = j.photo;
                        img.alt = j.name;
                        av.replaceWith(img);
                    }
                }

                // Update shift inline span
                const shiftMetaInline = { normal:['#2ecc71','fa-clock','Normal'], morning:['#f39c12','fa-sun','Morning'], afternoon:['#3498db','fa-cloud-sun','Afternoon'], night:['#9b59b6','fa-moon','Night'] };
                row.dataset.shift = j.shift || '';
                const titleEl2 = row.querySelector('.emp-title');
                if (titleEl2) {
                    let si2 = titleEl2.querySelector('.shift-inline');
                    if (j.shift && shiftMetaInline[j.shift]) {
                        const [sc, si, sl] = shiftMetaInline[j.shift];
                        if (!si2) { si2 = document.createElement('span'); si2.className = 'shift-inline'; si2.style.marginLeft = '4px'; si2.style.fontSize = '9px'; si2.style.opacity = '.9'; titleEl2.appendChild(si2); }
                        si2.style.color = sc;
                        si2.innerHTML = `<i class="fa-solid ${si}"></i> ${sl}`;
                    } else if (si2) { si2.remove(); }
                }
                recalcShiftCounts();

                // If role changed, trigger the same real-time logic
                const prevRole = row.dataset.role;
                if (j.role && j.role !== prevRole && j.role !== 'admin') {
                    const info = ROLES_INFO[j.role];
                    moveRowToGroup(row, prevRole, j.role, info);
                    adjustPillCount(prevRole, -1);
                    adjustPillCount(j.role, +1);
                    row.dataset.role = j.role;
                    // Update the inline role select too
                    const sel = row.querySelector('.role-select');
                    if (sel) sel.value = j.role;
                    const wrap = row.querySelector('.role-wrap');
                    if (wrap && info) {
                        const color = info.color || '#888';
                        wrap.style.setProperty('--rb-bg',     color+'1a');
                        wrap.style.setProperty('--rb-color',  color);
                        wrap.style.setProperty('--rb-border', color+'33');
                        const icon = wrap.querySelector('i');
                        if (icon) icon.className = `fa-solid ${info.icon}`;
                        wrap.dataset.current = j.role;
                    }
                }
                applyFilters();
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';
            closeEditModal();
            showToast(`${j.name} updated successfully`, 'success');
        } else {
            showToast(j.msg || 'Save failed', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';
        }
    } catch {
        showToast('Network error', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';
    }
}

/* ── Real-time stats polling (every 30s) ── */
let _statsMax = <?= $max_orders ?>;

function fmtNum(n) {
    return n >= 1000 ? (n / 1000).toFixed(1).replace(/\.0$/, '') + 'k' : String(n);
}

async function refreshStats() {
    if (document.hidden) return;
    try {
        const res  = await fetch('employees.php?action=get_stats');
        const data = await res.json();
        if (!data.ok) return;
        _statsMax = data.max_orders || 1;

        for (const [eidStr, s] of Object.entries(data.stats)) {
            const eid = parseInt(eidStr);

            // All-time orders cell
            const ordTd = document.querySelector(`[data-stat-orders="${eid}"]`);
            if (ordTd) {
                const pct     = Math.min(100, Math.round(s.total_orders / _statsMax * 100));
                const hasOrd  = s.total_orders > 0;
                const numEl   = ordTd.querySelector('.perf-num');
                const fillEl  = ordTd.querySelector('.perf-fill');
                const subEl   = ordTd.querySelector('.perf-sub');
                if (numEl)  { numEl.textContent = fmtNum(s.total_orders); numEl.classList.toggle('num-zero', !hasOrd); }
                if (fillEl) fillEl.style.width = pct + '%';
                if (subEl)  subEl.textContent  = hasOrd ? `avg $${s.avg_order_value.toFixed(2)} / order` : 'No orders yet';
                ordTd.dataset.val = s.total_orders;
            }

            // This month cell
            const monTd = document.querySelector(`[data-stat-month="${eid}"]`);
            if (monTd) {
                const numEl   = monTd.querySelector('.num-cell');
                let   todayEl = monTd.querySelector('.today-badge');
                if (numEl) { numEl.textContent = fmtNum(s.orders_this_month); numEl.classList.toggle('num-zero', s.orders_this_month === 0); }
                if (s.orders_today > 0) {
                    if (!todayEl) { todayEl = document.createElement('div'); todayEl.className = 'today-badge'; todayEl.style.cssText = 'font-size:10px;color:var(--ok);margin-top:2px'; monTd.appendChild(todayEl); }
                    todayEl.textContent = `+${s.orders_today} today`;
                } else if (todayEl) {
                    todayEl.remove();
                }
                monTd.dataset.val = s.orders_this_month;
            }

            // Revenue cell
            const revTd = document.querySelector(`[data-stat-rev="${eid}"]`);
            if (revTd) {
                const numEl = revTd.querySelector('.revenue');
                if (numEl) { numEl.textContent = s.total_revenue > 0 ? `$${s.total_revenue.toFixed(2)}` : '—'; numEl.classList.toggle('num-zero', s.total_revenue <= 0); }
                revTd.dataset.val = s.total_revenue.toFixed(2);
            }

            // Leaderboard podium card
            const card = document.querySelector(`[data-podium-eid="${eid}"]`);
            if (card) {
                const ordEl = card.querySelector('.ps-orders');
                const revEl = card.querySelector('.ps-revenue');
                const moEl  = card.querySelector('.ps-month');
                const revWrap = card.querySelector('.ps-rev-wrap');
                if (ordEl) ordEl.textContent = fmtNum(s.total_orders);
                if (moEl)  moEl.textContent  = fmtNum(s.orders_this_month);
                if (revEl) { revEl.textContent = `$${fmtNum(s.total_revenue)}`; }
                if (revWrap) revWrap.style.display = s.total_revenue > 0 ? '' : 'none';
                // Update job title + shift inline
                const titleEl = card.querySelector('.podium-title');
                if (titleEl) {
                    const job = s.job_title || '';
                    const shiftColors = {morning:'#f39c12',afternoon:'#3498db',night:'#9b59b6'};
                    const shiftIcons  = {morning:'fa-sun',afternoon:'fa-cloud-sun',night:'fa-moon'};
                    const shiftLabels = {morning:'Morning',afternoon:'Afternoon',night:'Night'};
                    if (s.shift && shiftColors[s.shift]) {
                        titleEl.innerHTML = `${job} <span style="color:${shiftColors[s.shift]};font-size:9px;opacity:.85"><i class="fa-solid ${shiftIcons[s.shift]}"></i> ${shiftLabels[s.shift]}</span>`;
                    } else {
                        titleEl.textContent = job;
                    }
                }
            }

            // Sync table row data-shift + shift-inline from polling
            const rowEl = document.querySelector(`#tableBody tr[data-id="${eid}"]`);
            if (rowEl && rowEl.dataset.shift !== (s.shift || '')) {
                rowEl.dataset.shift = s.shift || '';
                const shiftMeta3 = { morning:['#f39c12','fa-sun','Morning'], afternoon:['#3498db','fa-cloud-sun','Afternoon'], night:['#9b59b6','fa-moon','Night'] };
                const titleEl3 = rowEl.querySelector('.emp-title');
                if (titleEl3) {
                    let si3 = titleEl3.querySelector('.shift-inline');
                    if (s.shift && shiftMeta3[s.shift]) {
                        const [sc3, si3icon, sl3] = shiftMeta3[s.shift];
                        if (!si3) { si3 = document.createElement('span'); si3.className = 'shift-inline'; si3.style.marginLeft = '4px'; si3.style.fontSize = '9px'; si3.style.opacity = '.9'; titleEl3.appendChild(si3); }
                        si3.style.color = sc3;
                        si3.innerHTML = `<i class="fa-solid ${si3icon}"></i> ${sl3}`;
                    } else if (si3) { si3.remove(); }
                }
                recalcShiftCounts();
            }
        }
    } catch (_) { /* silent — no disruption on network error */ }
}

refreshStats();
setInterval(refreshStats, 10000);
</script>
</main>
</div>
</body>
</html>
