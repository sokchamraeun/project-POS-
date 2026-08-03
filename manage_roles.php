<?php
ob_start();
require 'admin_only.php';
require_once 'config.php';

if (($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: dashboard.php"); exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_ok(): bool {
    return hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '');
}

/* Colour and icon end up inside style="" and class="" on the client. The picker UI
   constrains them, but that is client-side only — a hand-rolled POST walks past it.
   Pin both to their real shape here, at the one place they enter the database. */
function safe_role_color(string $c): string {
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : '#888888';
}
function safe_role_icon(string $i): string {
    return preg_match('/^fa-[a-z0-9-]{1,40}$/i', $i) ? $i : 'fa-user';
}

function audit_log(mysqli $db, string $action, string $role_slug, string $detail = ''): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $by = $_SESSION['username'] ?? (isset($_SESSION['user_id']) ? 'user#'.$_SESSION['user_id'] : 'unknown');
    $s  = $db->prepare("INSERT INTO role_audit_log (action, role_slug, detail, performed_by) VALUES (?,?,?,?)");
    $s->bind_param("ssss", $action, $role_slug, $detail, $by);
    $s->execute();
}

/* ── POST: Create role ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_role') {
    if (!csrf_ok()) { header("Location: manage_roles.php"); exit; }
    $name = trim($_POST['role_name'] ?? '');
    $icon = trim($_POST['role_icon'] ?? 'fa-user');
    $color= trim($_POST['role_color'] ?? '#888888');
    $desc = trim($_POST['role_desc'] ?? '');
    $icon = safe_role_icon($icon);
    $color= safe_role_color($color);
    $slug = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name)), '_');
    if ($name !== '' && $slug !== '') {
        $s = $conn->prepare("INSERT IGNORE INTO roles (slug, name, icon, color, description, is_system) VALUES (?,?,?,?,?,0)");
        $s->bind_param("sssss", $slug, $name, $icon, $color, $desc);
        $s->execute();
        // Only seed template perms for a genuinely new role — never merge into an existing slug
        if ($s->affected_rows > 0) {
            audit_log($conn, 'create', $slug, "name=$name");
            $tpl = trim($_POST['role_template'] ?? '');
            if (in_array($tpl, ['manager', 'staff'], true)) {
                $st = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id)
                    SELECT (SELECT id FROM roles WHERE slug=?), permission_id FROM role_permissions WHERE role_id=(SELECT id FROM roles WHERE slug=?)");
                $st->bind_param("ss", $slug, $tpl);
                $st->execute();
            }
        }
    }
    header("Location: manage_roles.php"); exit;
}

/* ── POST: Delete role ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_role') {
    if (!csrf_ok()) { header("Location: manage_roles.php"); exit; }
    $slug     = trim($_POST['role_slug'] ?? '');
    $reassign = trim($_POST['reassign_to'] ?? '');
    if ($slug !== '' && $slug !== 'admin') {
        // Validate reassign target exists (if provided)
        if ($reassign !== '' && $reassign !== $slug) {
            $vr = $conn->prepare("SELECT slug FROM roles WHERE slug=?");
            $vr->bind_param("s", $reassign); $vr->execute();
            if (!$vr->get_result()->fetch_assoc()) $reassign = '';
        }
        // Reassign employees before deleting
        if ($reassign !== '') {
            $sr = $conn->prepare("UPDATE users SET role_id=(SELECT id FROM roles WHERE slug=?) WHERE role_id=(SELECT id FROM roles WHERE slug=?)");
            $sr->bind_param("ss", $reassign, $slug);
            $sr->execute();
        }
        $sd = $conn->prepare("DELETE FROM roles WHERE slug=? AND is_system=0");
        $sd->bind_param("s", $slug); $sd->execute();
        if ($sd->affected_rows > 0) {
            $detail = $reassign !== '' ? "reassigned_to=$reassign" : 'no_employees';
            audit_log($conn, 'delete', $slug, $detail);
        }
        // role_permissions rows are removed automatically by fk_rp_role ON DELETE CASCADE
    }
    header("Location: manage_roles.php"); exit;
}

/* ── POST: Edit role metadata ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_role') {
    if (!csrf_ok()) { header("Location: manage_roles.php"); exit; }
    $slug = trim($_POST['role_slug'] ?? '');
    $name = trim($_POST['role_name'] ?? '');
    $icon = trim($_POST['role_icon'] ?? 'fa-user');
    $color= trim($_POST['role_color'] ?? '#888888');
    $desc = trim($_POST['role_desc'] ?? '');
    $icon = safe_role_icon($icon);
    $color= safe_role_color($color);
    if ($slug !== '' && $name !== '') {
        $s = $conn->prepare("UPDATE roles SET name=?, icon=?, color=?, description=? WHERE slug=? AND slug != 'admin'");
        $s->bind_param("sssss", $name, $icon, $color, $desc, $slug);
        $s->execute();
        if ($s->affected_rows > 0) audit_log($conn, 'edit_meta', $slug, "name=$name");
    }
    header("Location: manage_roles.php"); exit;
}

/* ── AJAX: Get employees for a role ── */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'role_employees') {
    header('Content-Type: application/json');
    $slug = trim($_GET['slug'] ?? '');
    if ($slug === '') { ob_clean(); echo json_encode(['names' => []]); exit; }
    $q = $conn->prepare("SELECT e.name FROM employees e JOIN users u ON u.user_id = COALESCE(e.user_id, e.employee_id) JOIN roles r ON r.id = u.role_id WHERE r.slug=? ORDER BY e.name ASC LIMIT 20");
    $q->bind_param("s", $slug); $q->execute();
    $res = $q->get_result();
    $names = [];
    while ($row = $res->fetch_assoc()) $names[] = $row['name'];
    ob_clean(); echo json_encode(['names' => $names]); exit;
}

/* ── AJAX: Save permissions ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_permissions') {
    header('Content-Type: application/json');
    if (!csrf_ok()) { ob_clean(); echo json_encode(['success' => false, 'message' => 'Invalid session token']); exit; }
    $role = trim($_POST['role'] ?? '');
    // Validate against DB roles (not hardcoded)
    $vr = $conn->prepare("SELECT slug FROM roles WHERE slug=? AND slug != 'admin'");
    $vr->bind_param("s", $role); $vr->execute();
    if (!$vr->get_result()->fetch_assoc()) {
        ob_clean(); echo json_encode(['success' => false, 'message' => 'Invalid role']); exit;
    }
    $ids = array_map('intval', $_POST['permissions'] ?? []);
    $sdp = $conn->prepare("DELETE FROM role_permissions WHERE role_id=(SELECT id FROM roles WHERE slug=?)");
    $sdp->bind_param("s", $role); $sdp->execute();
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $s  = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT (SELECT id FROM roles WHERE slug=?), id FROM permissions WHERE id IN ($ph)");
        $s->bind_param('s' . str_repeat('i', count($ids)), $role, ...$ids);
        $s->execute();
    }
    audit_log($conn, 'save_permissions', $role, count($ids).' permissions');
    ob_clean(); echo json_encode(['success' => true]);
    exit;
}

/* ── AJAX: Get User Specific Permissions & Overrides ── */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'user_permissions') {
    header('Content-Type: application/json');
    $uid = (int)($_GET['user_id'] ?? 0);
    if ($uid <= 0) { ob_clean(); echo json_encode(['error' => 'Invalid user ID']); exit; }

    // Fetch user info & role
    $uq = $conn->prepare("
        SELECT u.user_id, u.username, r.slug AS role_slug, r.name AS role_name, COALESCE(e.name, u.username) AS emp_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        LEFT JOIN employees e ON e.user_id = u.user_id
        WHERE u.user_id = ?
        LIMIT 1
    ");
    $uq->bind_param("i", $uid);
    $uq->execute();
    $userInfo = $uq->get_result()->fetch_assoc();

    if (!$userInfo) { ob_clean(); echo json_encode(['error' => 'User not found']); exit; }

    // Fetch base role permissions for this user's role
    $rolePerms = [];
    $rpq = $conn->prepare("
        SELECT rp.permission_id
        FROM role_permissions rp
        JOIN roles r ON r.id = rp.role_id
        WHERE r.slug = ?
    ");
    $rpq->bind_param("s", $userInfo['role_slug']);
    $rpq->execute();
    $rpres = $rpq->get_result();
    while ($row = $rpres->fetch_assoc()) {
        $rolePerms[(int)$row['permission_id']] = true;
    }

    // Fetch explicit user overrides
    $userOverrides = [];
    $uoq = $conn->prepare("SELECT permission_id, is_granted FROM user_permissions WHERE user_id = ?");
    $uoq->bind_param("i", $uid);
    $uoq->execute();
    $uores = $uoq->get_result();
    while ($row = $uores->fetch_assoc()) {
        $userOverrides[(int)$row['permission_id']] = (int)$row['is_granted'];
    }

    // Fetch all available permissions grouped by module
    $allP = $conn->query("SELECT id, name, slug, module FROM permissions ORDER BY module ASC, sort_order ASC, name ASC");
    $modules = [];
    while ($p = $allP->fetch_assoc()) {
        $pid = (int)$p['id'];
        $inherited = isset($rolePerms[$pid]);
        $override  = isset($userOverrides[$pid]) ? $userOverrides[$pid] : null; // 1 = grant, 0 = deny, null = inherit
        $effective = ($override !== null) ? ($override === 1) : $inherited;

        $modules[$p['module']][] = [
            'id'        => $pid,
            'name'      => $p['name'],
            'slug'      => $p['slug'],
            'inherited' => $inherited,
            'override'  => $override,
            'effective' => $effective
        ];
    }

    ob_clean();
    echo json_encode([
        'success' => true,
        'user'    => $userInfo,
        'modules' => $modules
    ]);
    exit;
}

/* ── AJAX: Save User Specific Permission Overrides ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_user_permissions') {
    header('Content-Type: application/json');
    if (!csrf_ok()) { ob_clean(); echo json_encode(['success' => false, 'message' => 'Invalid session token']); exit; }

    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid <= 0) { ob_clean(); echo json_encode(['success' => false, 'message' => 'Invalid user ID']); exit; }

    // Parse overrides map: [ permission_id => 'grant' | 'deny' | 'inherit' ]
    $overrides = $_POST['overrides'] ?? [];
    if (!is_array($overrides)) $overrides = [];

    $conn->begin_transaction();
    try {
        $del = $conn->prepare("DELETE FROM user_permissions WHERE user_id = ?");
        $del->bind_param("i", $uid);
        $del->execute();

        $ins = $conn->prepare("INSERT INTO user_permissions (user_id, permission_id, is_granted, granted_by) VALUES (?, ?, ?, ?)");
        $admin_id = $_SESSION['user_id'] ?? null;

        $count_grants = 0;
        $count_denies = 0;

        foreach ($overrides as $pid_str => $state) {
            $pid = (int)$pid_str;
            if ($pid <= 0) continue;
            if ($state === 'grant') {
                $is_g = 1;
                $ins->bind_param("iiii", $uid, $pid, $is_g, $admin_id);
                $ins->execute();
                $count_grants++;
            } else if ($state === 'deny') {
                $is_g = 0;
                $ins->bind_param("iiii", $uid, $pid, $is_g, $admin_id);
                $ins->execute();
                $count_denies++;
            }
        }

        $conn->commit();

        audit_log($conn, 'user_permissions_override', 'user#'.$uid, "grants={$count_grants},denies={$count_denies}");
        ob_clean();
        echo json_encode(['success' => true, 'grants' => $count_grants, 'denies' => $count_denies]);
        exit;
    } catch (Throwable $e) {
        $conn->rollback();
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Database error saving user permissions']);
        exit;
    }
}


/* ── POST: Bulk reassign ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_reassign') {
    if (!csrf_ok()) { header("Location: manage_roles.php"); exit; }
    $from = trim($_POST['from_role'] ?? '');
    $to   = trim($_POST['to_role']   ?? '');
    if ($from !== '' && $to !== '' && $from !== $to && $from !== 'admin') {
        $vt = $conn->prepare("SELECT slug FROM roles WHERE slug=?");
        $vt->bind_param("s", $to); $vt->execute();
        if ($vt->get_result()->fetch_assoc()) {
            $su = $conn->prepare("UPDATE users SET role_id=(SELECT id FROM roles WHERE slug=?) WHERE role_id=(SELECT id FROM roles WHERE slug=?)");
            $su->bind_param("ss", $to, $from); $su->execute();
            if ($su->affected_rows > 0) audit_log($conn, 'bulk_reassign', $from, "to=$to,count={$su->affected_rows}");
        }
    }
    header("Location: manage_roles.php"); exit;
}

/* ── DATA ── */
$all_perms        = [];
$total_perm_count = 0;

$user_options_query = $conn->query("
    SELECT u.user_id, u.username, r.name AS role_name, COALESCE(e.name, u.username) AS emp_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    LEFT JOIN employees e ON e.user_id = u.user_id
    WHERE r.slug != 'admin'
    ORDER BY r.name ASC, emp_name ASC
");
$all_user_options = $user_options_query ? $user_options_query->fetch_all(MYSQLI_ASSOC) : [];
$res = $conn->query("SELECT * FROM permissions ORDER BY sort_order ASC");
while ($r = $res->fetch_assoc()) {
    $all_perms[$r['module']][] = $r;
    $total_perm_count++;
}

// Load roles from DB
$roles = [];
$role_counts = [];
$res_roles = $conn->query("SELECT * FROM roles ORDER BY is_system DESC, id ASC");
while ($rr = $res_roles->fetch_assoc()) {
    $roles[$rr['slug']] = [
        'label'    => $rr['name'],
        'icon'     => $rr['icon'],
        'color'    => $rr['color'],
        'editable' => $rr['slug'] !== 'admin',
        'system'   => (bool)$rr['is_system'],
        'desc'     => $rr['description'],
    ];
    $role_counts[$rr['slug']] = ($rr['slug'] === 'admin') ? $total_perm_count : 0;
}

$role_perm_ids = [];
$res2 = $conn->query("SELECT ro.slug AS role, rp.permission_id FROM role_permissions rp JOIN roles ro ON ro.id = rp.role_id");
while ($r = $res2->fetch_assoc()) {
    $role_perm_ids[$r['role']][$r['permission_id']] = true;
    if ($r['role'] !== 'admin') {
        $role_counts[$r['role']] = ($role_counts[$r['role']] ?? 0) + 1;
    }
}

$emp_counts = [];
$ec_res = $conn->query("SELECT COALESCE(ro.slug,'staff') AS emp_role, COUNT(*) AS cnt FROM employees e LEFT JOIN users u ON u.user_id = COALESCE(e.user_id, e.employee_id) LEFT JOIN roles ro ON ro.id = u.role_id GROUP BY emp_role");
if ($ec_res) { while ($ec = $ec_res->fetch_assoc()) $emp_counts[$ec['emp_role']] = (int)$ec['cnt']; }

$roles_js = [];
foreach ($roles as $_slug => $_rinfo) {
    $roles_js[$_slug] = [
        'label'  => $_rinfo['label'],
        'icon'   => $_rinfo['icon'],
        'color'  => $_rinfo['color'],
        'cls'    => 'for-' . (in_array($_slug, ['manager','staff']) ? $_slug : 'role'),
        'accent' => 'linear-gradient(90deg,'.$_rinfo['color'].'99,'.$_rinfo['color'].')',
    ];
}

$module_meta = [
    // ── core module names — must match the `module` column in the permissions table ──
    'Overview'    => ['icon' => 'fa-gauge',          'color' => '#d1904b'],
    'Orders'      => ['icon' => 'fa-receipt',        'color' => '#3498db'],
    'Loyalty'     => ['icon' => 'fa-star',           'color' => '#9b59b6'],
    'Inventory'   => ['icon' => 'fa-boxes-stacked',  'color' => '#27ae60'],
    'Operations'      => ['icon' => 'fa-sliders',        'color' => '#8b5cf6'],
    'Reconciliation'  => ['icon' => 'fa-scale-balanced','color' => '#06b6d4'],
    'Procurement'     => ['icon' => 'fa-truck',          'color' => '#e67e22'],
    'Analytics'   => ['icon' => 'fa-chart-bar',      'color' => '#e74c3c'],
    'Staff'       => ['icon' => 'fa-users',          'color' => '#1abc9c'],
    'Admin'       => ['icon' => 'fa-shield-halved',  'color' => '#c0392b'],
    // ── legacy / alternate spellings kept so old seeds still render correctly ──
    'Dashboard'   => ['icon' => 'fa-gauge',          'color' => '#d1904b'],
    'Reports'     => ['icon' => 'fa-chart-bar',      'color' => '#e74c3c'],
    'People'      => ['icon' => 'fa-users',          'color' => '#1abc9c'],
    'Content'     => ['icon' => 'fa-bullhorn',       'color' => '#f39c12'],
    'Access'      => ['icon' => 'fa-shield-halved',  'color' => '#c0392b'],
];
$module_count = count($all_perms);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage Roles | Bird's Nest Coffee</title>
<script>(function(){if(localStorage.getItem('theme')==='light')document.documentElement.setAttribute('data-theme','light');}());</script>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --bg:#0b0b0b; --bg-card:#131313; --bg-card-hover:#1a1a1a; --bg-input:#1a1a1a;
    --border:#222; --border-hover:#333;
    --accent:#d1904b; --accent-light:#e8b87a; --accent-dark:#a0702a;
    --text:#f5f5f5; --text-muted:#888; --text-light:#fff;
    --ok:#55e087; --blue:#3498db; --danger:#ff5f5f; --low:#f1c40f;
    --shadow-sm:0 2px 8px rgba(0,0,0,.35); --shadow-md:0 6px 24px rgba(0,0,0,.5);
    --radius:14px; --transition:all .22s cubic-bezier(.4,0,.2,1);
    --topbar-h:57px;
}
[data-theme="light"] {
    --bg:#F0F2F5; --bg-card:#FFFFFF; --bg-card-hover:#F5F7FA; --bg-input:#F9FAFB;
    --border:#E5E7EB; --border-hover:#D1D5DB;
    --text:#111827; --text-muted:#6B7280; --text-light:#111827;
    --shadow-sm:0 2px 8px rgba(0,0,0,.06); --shadow-md:0 6px 24px rgba(0,0,0,.10);
}
[data-theme="light"] .topbar { background:rgba(255,255,255,.97); }
[data-theme="light"] .table-sticky-head { background:var(--bg-card) !important; }
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
html { scroll-behavior:smooth; }
body { font-family:'Poppins',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; padding-bottom:80px; }
::-webkit-scrollbar { width:5px; height:5px; }
::-webkit-scrollbar-thumb { background:var(--accent); border-radius:10px; }

/* ── TOPBAR ── */
.topbar {
    position:sticky; top:0; z-index:200; height:var(--topbar-h);
    display:flex; align-items:center; gap:10px; padding:0 24px;
    background:rgba(11,11,11,.97); backdrop-filter:blur(20px);
    border-bottom:1px solid var(--border);
    animation:slideDown .4s cubic-bezier(.22,1,.36,1) both;
}
.brand-icon { width:34px; height:34px; border-radius:9px; background:linear-gradient(135deg,var(--accent-dark),var(--accent)); display:flex; align-items:center; justify-content:center; font-size:15px; color:#fff; flex-shrink:0; }
.brand-text  { display:flex; flex-direction:column; line-height:1.2; }
.brand-title { font-size:15px; font-weight:700; color:var(--text-light); }
.brand-sub   { font-size:10px; color:var(--text-muted); }
.topbar-sep  { width:1px; height:22px; background:var(--border); flex-shrink:0; }
.topbar-right { display:flex; align-items:center; gap:6px; margin-left:auto; }
.btn-nav {
    display:inline-flex; align-items:center; gap:6px; padding:7px 14px;
    border-radius:50px; border:1px solid var(--border); background:var(--bg-input);
    color:var(--text-muted); text-decoration:none; font-size:12px; font-weight:500;
    transition:var(--transition); cursor:pointer; white-space:nowrap; font-family:'Poppins',sans-serif;
}
.btn-nav:hover { border-color:var(--accent); color:var(--accent); }
.btn-nav.icon-only { padding:7px 10px; }

/* ── CONTAINER ── */
.container { max-width:1320px; margin:0 auto; padding:0 28px; }

/* ── HERO ── */
.page-hero {
    padding:32px 0 0;
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:18px;
    animation:fadeUp .45s cubic-bezier(.22,1,.36,1) both; animation-delay:.05s;
}
.hero-left  { display:flex; align-items:center; gap:16px; }
.hero-icon  {
    width:54px; height:54px; border-radius:16px; flex-shrink:0;
    background:linear-gradient(135deg,var(--accent-dark),var(--accent));
    display:flex; align-items:center; justify-content:center; font-size:22px; color:#fff;
    box-shadow:0 4px 18px rgba(209,144,75,.28);
}
.hero-title { font-size:23px; font-weight:800; color:var(--text-light); line-height:1.2; }
.hero-sub   { font-size:13px; color:var(--text-muted); margin-top:3px; }
.hero-badge { display:inline-flex; align-items:center; gap:5px; font-size:10px; font-weight:700; padding:3px 10px; border-radius:20px; background:rgba(209,144,75,.1); color:var(--accent); border:1px solid rgba(209,144,75,.2); margin-left:8px; vertical-align:middle; }

/* ── STATS BAR ── */
.stats-bar  { display:flex; align-items:center; gap:10px; flex-shrink:0; }
.stat-chip  {
    display:flex; align-items:center; gap:8px; padding:10px 16px;
    background:var(--bg-card); border:1px solid var(--border); border-radius:12px;
    transition:var(--transition);
}
.stat-chip:hover { border-color:var(--border-hover); }
.stat-chip-icon { width:30px; height:30px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:12px; flex-shrink:0; }
.stat-chip-icon.ic-roles   { background:rgba(209,144,75,.1); color:var(--accent); }
.stat-chip-icon.ic-perms   { background:rgba(52,152,219,.1);  color:#3498db; }
.stat-chip-icon.ic-modules { background:rgba(85,224,135,.1);  color:#55e087; }
.stat-chip-body { display:flex; flex-direction:column; line-height:1.2; }
.stat-chip-num  { font-size:17px; font-weight:800; color:var(--text-light); }
.stat-chip-lbl  { font-size:10px; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:.4px; }

/* ── SECTION HEADERS ── */
.sec-hdr {
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;
    padding:28px 0 14px;
}
.sec-hdr-left { display:flex; align-items:center; gap:8px; }
.sec-hdr-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:var(--text-muted); }
.sec-hdr-dot   { width:5px; height:5px; border-radius:50%; background:var(--accent); flex-shrink:0; }

/* ── ROLES GRID ── */
.roles-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:18px; animation:fadeUp .45s cubic-bezier(.22,1,.36,1) both; animation-delay:.12s; }

.role-card {
    background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius);
    display:flex; flex-direction:column; overflow:hidden; position:relative;
    transition:var(--transition);
}
.role-card:hover { border-color:var(--border-hover); box-shadow:var(--shadow-md); transform:translateY(-3px); }
.role-card-accent { height:3px; flex-shrink:0; }
.r-admin   .role-card-accent { background:linear-gradient(90deg,#a0702a,#d1904b,#e8b87a); }
.r-manager .role-card-accent { background:linear-gradient(90deg,#1a6899,#3498db,#5dade2); }
.r-staff   .role-card-accent { background:linear-gradient(90deg,#1e8449,#55e087,#82e9a8); }

.role-card-body { padding:22px 22px 18px; flex:1; display:flex; flex-direction:column; gap:16px; }
.role-card-foot { padding:0 22px 20px; }

.role-header   { display:flex; align-items:flex-start; gap:13px; }
.role-avatar   { width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:19px; flex-shrink:0; }
.r-admin   .role-avatar { background:rgba(209,144,75,.12); color:#d1904b; }
.r-manager .role-avatar { background:rgba(52,152,219,.12);  color:#3498db; }
.r-staff   .role-avatar { background:rgba(85,224,135,.12);  color:#55e087; }
.role-name-wrap { display:flex; flex-direction:column; gap:3px; }
.role-name     { font-size:17px; font-weight:800; color:var(--text-light); }
.role-badge    { display:inline-block; font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; text-transform:uppercase; letter-spacing:.4px; }
.r-admin   .role-badge { background:rgba(209,144,75,.1); color:#d1904b; border:1px solid rgba(209,144,75,.2); }
.r-manager .role-badge { background:rgba(52,152,219,.1);  color:#3498db; border:1px solid rgba(52,152,219,.2); }
.r-staff   .role-badge { background:rgba(85,224,135,.1);  color:#55e087; border:1px solid rgba(85,224,135,.2); }
.role-desc     { font-size:12px; color:var(--text-muted); line-height:1.55; margin-top:4px; }

.perm-count-row { display:flex; align-items:flex-end; gap:6px; }
.perm-big-num   { font-size:32px; font-weight:800; color:var(--text-light); line-height:1; }
.perm-big-of    { font-size:13px; color:var(--text-muted); margin-bottom:3px; }

.perm-bar-wrap  { display:flex; flex-direction:column; gap:5px; }
.perm-bar-lbl   { display:flex; justify-content:space-between; font-size:11px; color:var(--text-muted); }
.perm-bar-lbl strong { color:var(--text); font-weight:600; }
.perm-bar       { height:7px; background:rgba(255,255,255,.06); border-radius:8px; overflow:hidden; }
.perm-bar-fill  { height:100%; border-radius:8px; transition:width .7s cubic-bezier(.22,1,.36,1); }
.r-admin   .perm-bar-fill { background:linear-gradient(90deg,#a0702a,#d1904b); }
.r-manager .perm-bar-fill { background:linear-gradient(90deg,#1a6899,#3498db); }
.r-staff   .perm-bar-fill { background:linear-gradient(90deg,#1e8449,#55e087); }

.btn-edit-role {
    display:flex; align-items:center; justify-content:center; gap:8px; width:100%;
    padding:10px 16px; border-radius:10px; font-size:12px; font-weight:700;
    cursor:pointer; transition:var(--transition); font-family:'Poppins',sans-serif;
    border:1.5px solid var(--border); background:transparent; color:var(--text-muted);
}
.r-manager .btn-edit-role:hover { background:rgba(52,152,219,.1); border-color:#3498db; color:#3498db; }
.r-staff   .btn-edit-role:hover { background:rgba(85,224,135,.1); border-color:#55e087; color:#55e087; }
.lock-note {
    display:flex; align-items:center; gap:7px; font-size:11px; color:var(--text-muted);
    padding:9px 13px; background:rgba(209,144,75,.05); border:1px solid rgba(209,144,75,.14);
    border-radius:9px;
}

/* ── PERMISSIONS SECTION ── */
.perms-section { animation:fadeUp .45s cubic-bezier(.22,1,.36,1) both; animation-delay:.2s; }

.perms-controls {
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;
    margin-bottom:14px;
}
.search-wrap {
    position:relative; flex-shrink:0;
}
.search-wrap i {
    position:absolute; left:12px; top:50%; transform:translateY(-50%);
    color:var(--text-muted); font-size:13px; pointer-events:none;
}
.perm-search {
    padding:9px 14px 9px 36px; border-radius:10px; border:1px solid var(--border);
    background:var(--bg-input); color:var(--text); font-family:'Poppins',sans-serif;
    font-size:13px; outline:none; transition:var(--transition); width:230px;
}
.perm-search:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(209,144,75,.1); }
.perm-search::placeholder { color:var(--text-muted); }

.module-filters { display:flex; align-items:center; gap:7px; flex-wrap:wrap; }
.mod-filter-btn {
    display:inline-flex; align-items:center; gap:5px; padding:6px 12px;
    border-radius:20px; border:1px solid var(--border); background:var(--bg-input);
    color:var(--text-muted); font-size:11px; font-weight:600; cursor:pointer;
    transition:var(--transition); font-family:'Poppins',sans-serif; white-space:nowrap;
}
.mod-filter-btn:hover { border-color:var(--border-hover); color:var(--text); }
.mod-filter-btn.active { background:rgba(209,144,75,.1); border-color:rgba(209,144,75,.3); color:var(--accent); }
.mod-filter-btn .mod-badge { background:rgba(255,255,255,.07); padding:1px 6px; border-radius:10px; font-size:10px; }
.mod-filter-btn.active .mod-badge { background:rgba(209,144,75,.15); }

/* ── TABLE ── */
.table-wrap { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; box-shadow:var(--shadow-sm); }
.table-scroll { overflow-x:auto; }
table { width:100%; border-collapse:collapse; font-size:13px; }

thead th {
    padding:12px 20px; text-align:left; font-size:10px; font-weight:700;
    text-transform:uppercase; letter-spacing:.65px; color:var(--text-muted);
    background:var(--bg-card); border-bottom:2px solid var(--border);
    white-space:nowrap;
}
thead th.role-col { text-align:center; }

td { padding:12px 20px; border-bottom:1px solid var(--border); color:var(--text); transition:background .12s; vertical-align:middle; }
tr:last-child td { border-bottom:none; }
.perm-row:hover td { background:rgba(255,255,255,.018); }
[data-theme="light"] .perm-row:hover td { background:rgba(0,0,0,.02); }

.module-row td {
    padding:9px 20px; border-bottom:1px solid var(--border);
    background:rgba(255,255,255,.02); border-top:1px solid var(--border);
}
[data-theme="light"] .module-row td { background:rgba(0,0,0,.025); }
.module-row:first-child td { border-top:none; }

.module-row-inner { display:flex; align-items:center; gap:9px; }
.module-icon-wrap {
    width:26px; height:26px; border-radius:7px; display:flex; align-items:center;
    justify-content:center; font-size:11px; flex-shrink:0;
}
.module-row-name { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:var(--text-muted); }
.module-row-count { margin-left:auto; font-size:10px; font-weight:600; padding:2px 8px; border-radius:10px; background:rgba(255,255,255,.06); color:var(--text-muted); }

.perm-name { display:flex; align-items:center; gap:9px; font-weight:500; color:var(--text); }
.perm-dot  { width:6px; height:6px; border-radius:50%; background:var(--border-hover); flex-shrink:0; }

.role-col { text-align:center; }
.toggle-pill {
    display:inline-flex; align-items:center; gap:5px; padding:5px 13px;
    border-radius:20px; font-size:11px; font-weight:600; cursor:pointer;
    transition:var(--transition); border:1px solid var(--border);
    background:transparent; color:var(--text-muted); font-family:'Poppins',sans-serif;
    white-space:nowrap;
}
.toggle-pill.on-admin { background:rgba(209,144,75,.1); color:#d1904b; border-color:rgba(209,144,75,.25); cursor:default; }
.toggle-pill.on-role  { background:var(--pill-bg,rgba(255,255,255,.07)); color:var(--pill-color,#fff); border-color:var(--pill-border,rgba(255,255,255,.2)); }
.toggle-pill.off { opacity:.55; }
.toggle-pill:not([disabled]):not(.on-admin):hover { border-color:var(--border-hover); opacity:1; }

/* no-results row */
.no-results-row td { text-align:center; padding:28px; color:var(--text-muted); font-size:13px; }

/* ── MODAL ── */
.modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.78); backdrop-filter:blur(12px); z-index:9999; display:none; align-items:center; justify-content:center; padding:20px; }
.modal-overlay.open { display:flex; }
.modal-box { background:var(--bg-card); border:1px solid var(--border); border-radius:20px; max-width:520px; width:100%; max-height:88vh; display:flex; flex-direction:column; box-shadow:0 28px 70px rgba(0,0,0,.65); animation:popIn .22s ease both; position:relative; }
@keyframes popIn { from{opacity:0;transform:scale(.92) translateY(16px)} to{opacity:1;transform:scale(1) translateY(0)} }

.modal-accent { height:3px; border-radius:20px 20px 0 0; flex-shrink:0; }
.modal-head { padding:22px 24px 0; flex-shrink:0; }
.modal-close { position:absolute; top:14px; right:14px; width:30px; height:30px; border-radius:50%; border:none; background:rgba(255,255,255,.07); color:var(--text-muted); font-size:14px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:var(--transition); }
.modal-close:hover { background:rgba(255,255,255,.13); color:var(--text); }
.modal-title { font-size:18px; font-weight:800; color:var(--text-light); display:flex; align-items:center; gap:10px; }
.modal-sub   { font-size:12px; color:var(--text-muted); margin-top:3px; }

.modal-toolbar { display:flex; align-items:center; justify-content:space-between; padding:14px 0 0; }
.modal-count   { font-size:12px; color:var(--text-muted); }
.modal-actions { display:flex; gap:10px; }
.btn-select { background:none; border:none; color:var(--accent); font-size:12px; font-weight:600; cursor:pointer; font-family:'Poppins',sans-serif; padding:0; }
.btn-select:hover { text-decoration:underline; }
.modal-divider { height:1px; background:var(--border); margin:12px 0 0; }

.modal-body { overflow-y:auto; padding:0 24px; flex:1; }
.modal-body::-webkit-scrollbar { width:4px; } .modal-body::-webkit-scrollbar-thumb { background:var(--border-hover); border-radius:4px; }

.module-group    { margin:18px 0; }
.module-group-hdr {
    display:flex; align-items:center; gap:8px; font-size:10px; font-weight:700;
    text-transform:uppercase; letter-spacing:.7px; color:var(--text-muted);
    margin-bottom:10px;
}
.module-group-hdr .mg-icon { width:22px; height:22px; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:10px; flex-shrink:0; }
.module-group-hdr::after { content:''; flex:1; height:1px; background:var(--border); }

/* User Override Segmented Controls */
.uo-btn { padding:4px 10px; border-radius:6px; border:none; background:transparent; color:var(--text-muted); font-size:11px; font-weight:600; cursor:pointer; transition:all .15s ease; font-family:inherit; }
.uo-btn:hover { color:var(--text); }
.uo-btn.sel-inherit { background:rgba(255,255,255,.1); color:var(--text); font-weight:700; }
.uo-btn.sel-grant   { background:rgba(85,224,135,.2); color:#55e087; font-weight:700; border:1px solid rgba(85,224,135,.4); }
.uo-btn.sel-deny    { background:rgba(231,76,60,.2); color:#e74c3c; font-weight:700; border:1px solid rgba(231,76,60,.4); }

.perm-check-list { display:flex; flex-direction:column; gap:6px; }
.perm-check-item {
    display:flex; align-items:center; gap:12px; padding:10px 14px;
    border-radius:10px; border:1px solid var(--border); background:var(--bg-input);
    cursor:pointer; transition:var(--transition); user-select:none;
}
.perm-check-item:hover { border-color:var(--border-hover); background:var(--bg-card-hover); }
.perm-check-item.checked.for-manager { border-color:rgba(52,152,219,.3); background:rgba(52,152,219,.06); }
.perm-check-item.checked.for-staff   { border-color:rgba(85,224,135,.3); background:rgba(85,224,135,.06); }
.perm-checkbox { width:18px; height:18px; border-radius:5px; border:2px solid var(--border-hover); background:transparent; display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:var(--transition); }
.perm-check-item.checked.for-manager .perm-checkbox { background:#3498db; border-color:#3498db; }
.perm-check-item.checked.for-staff   .perm-checkbox { background:#55e087; border-color:#55e087; }
.perm-checkbox i { font-size:10px; color:#fff; display:none; }
.perm-check-item.checked .perm-checkbox i { display:block; }
.perm-check-name { font-size:13px; font-weight:500; flex:1; }

.modal-foot { padding:16px 24px; flex-shrink:0; border-top:1px solid var(--border); display:flex; gap:10px; }
.btn-save { flex:1; padding:12px; border-radius:10px; border:none; background:var(--accent); color:#000; font-size:14px; font-weight:700; cursor:pointer; font-family:'Poppins',sans-serif; transition:var(--transition); display:flex; align-items:center; justify-content:center; gap:8px; }
.btn-save:hover { filter:brightness(1.1); transform:translateY(-1px); }
.btn-save:disabled { opacity:.6; transform:none; cursor:not-allowed; }
.btn-cancel-modal { padding:12px 20px; border-radius:10px; border:1px solid var(--border); background:transparent; color:var(--text-muted); font-size:13px; font-weight:600; cursor:pointer; font-family:'Poppins',sans-serif; transition:var(--transition); }
.btn-cancel-modal:hover { border-color:var(--border-hover); color:var(--text); }


/* ── TOAST ── */
#toast-cnt { position:fixed; bottom:24px; right:20px; z-index:99999; display:flex; flex-direction:column-reverse; gap:8px; pointer-events:none; }
.toast { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:11px 16px; font-size:13px; font-weight:500; color:var(--text); box-shadow:var(--shadow-md); display:flex; align-items:center; gap:10px; min-width:220px; transform:translateX(120%); transition:transform .3s cubic-bezier(.34,1.56,.64,1); pointer-events:auto; }
.toast.show { transform:translateX(0); }
.toast.success { border-left:3px solid var(--ok); }
.toast.error   { border-left:3px solid var(--danger); }

@keyframes fadeUp    { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }
@keyframes slideDown { from{opacity:0;transform:translateY(-10px)} to{opacity:1;transform:translateY(0)} }

@media (max-width:960px)  { .roles-grid { grid-template-columns:1fr 1fr; } .stats-bar { gap:8px; } }
@media (max-width:640px)  { .roles-grid { grid-template-columns:1fr; } .page-hero { flex-direction:column; align-items:flex-start; } .stats-bar { width:100%; } .stat-chip { flex:1; justify-content:center; } .perms-controls { flex-direction:column; align-items:stretch; } .perm-search { width:100%; } }

/* ── Create Role button ── */
.btn-create-role {
    display:inline-flex;align-items:center;gap:6px;
    padding:7px 14px;border-radius:9px;border:1px solid rgba(209,144,75,.35);
    background:rgba(209,144,75,.1);color:var(--accent);
    font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;
    transition:all .2s;
}
.btn-create-role:hover { background:rgba(209,144,75,.22);transform:translateY(-1px);box-shadow:0 4px 14px rgba(209,144,75,.15); }
.btn-create-role i { transition:transform .35s; }
.btn-create-role:hover i { transform:rotate(90deg); }

/* ── Delete role button ── */
.btn-delete-role {
    width:28px;height:28px;border-radius:7px;border:1px solid rgba(231,76,60,.2);
    background:rgba(231,76,60,.08);color:#e74c3c;cursor:pointer;font-size:11px;
    display:flex;align-items:center;justify-content:center;transition:all .2s;flex-shrink:0;
}
.btn-delete-role:hover { background:#e74c3c;color:#fff;border-color:#e74c3c; }
/* ── Bulk reassign button ── */
.btn-reassign-role {
    width:28px;height:28px;border-radius:7px;border:1px solid rgba(52,152,219,.2);
    background:rgba(52,152,219,.08);color:#3498db;cursor:pointer;font-size:11px;
    display:flex;align-items:center;justify-content:center;transition:all .2s;flex-shrink:0;
}
.btn-reassign-role:hover { background:#3498db;color:#fff;border-color:#3498db; }

/* ── Create Role modal ── */
.cr-overlay {
    position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;
    display:flex;align-items:center;justify-content:center;
    opacity:0;pointer-events:none;transition:opacity .25s;backdrop-filter:blur(4px);
}
.cr-overlay.open { opacity:1;pointer-events:all; }
.cr-modal {
    background:var(--bg-card);border:1px solid var(--border-hover);border-radius:16px;
    width:100%;max-width:480px;padding:28px;
    transform:translateY(16px);transition:transform .25s;
}
.cr-overlay.open .cr-modal { transform:translateY(0); }
.cr-modal-head { display:flex;align-items:center;justify-content:space-between;margin-bottom:22px; font-weight:700;font-size:15px; }
.cr-close { background:none;border:none;color:var(--text-muted);font-size:20px;cursor:pointer;line-height:1;padding:2px 6px;border-radius:6px;transition:all .2s; }
.cr-close:hover { background:var(--bg-hover);color:var(--text); }
.cr-field { display:flex;flex-direction:column;gap:5px;margin-bottom:14px; }
.cr-field label { font-size:11px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.04em; }
.cr-field input[type="text"] {
    padding:9px 12px;border-radius:9px;border:1px solid var(--border);
    background:var(--bg);color:var(--text);font-family:inherit;font-size:13px;outline:none;
    transition:border-color .2s;
}
.cr-field input[type="text"]:focus { border-color:var(--accent); }
.cr-row { display:flex;gap:14px; }
.cr-icon-pick { display:flex;flex-wrap:wrap;gap:6px; }
.cr-icon-opt {
    width:34px;height:34px;border-radius:8px;border:1px solid var(--border);
    background:var(--bg);display:flex;align-items:center;justify-content:center;
    cursor:pointer;font-size:14px;color:var(--text-muted);transition:all .2s;
}
.cr-icon-opt:hover { border-color:var(--accent);color:var(--accent); }
.cr-icon-opt:has(input:checked) { border-color:var(--accent);background:rgba(209,144,75,.12);color:var(--accent); }
.cr-submit {
    width:100%;padding:11px;border-radius:10px;border:none;
    background:linear-gradient(135deg,var(--accent),#e8a85d);color:#000;
    font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;
    transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px;
    margin-top:6px;
}
.cr-submit:hover { filter:brightness(1.08);transform:translateY(-1px);box-shadow:0 6px 20px rgba(209,144,75,.3); }

/* ── Edit meta button ── */
.btn-edit-meta {
    width:28px;height:28px;border-radius:7px;border:1px solid var(--border);
    background:var(--bg-input);color:var(--text-muted);cursor:pointer;font-size:11px;
    display:flex;align-items:center;justify-content:center;transition:all .2s;flex-shrink:0;
    margin-right:4px;
}
.btn-edit-meta:hover { background:rgba(209,144,75,.15);color:var(--accent);border-color:rgba(209,144,75,.4); }

/* ── Employee count chip ── */
.emp-count-chip {
    display:inline-flex;align-items:center;gap:6px;
    font-size:11px;color:var(--text-muted);
    background:rgba(255,255,255,.04);border:1px solid var(--border);
    border-radius:20px;padding:3px 10px;align-self:flex-start;margin-top:2px;
}
[data-theme="light"] .emp-count-chip { background:rgba(0,0,0,.04); }

/* ── Permission template selector ── */
.cr-template-group { display:flex;flex-direction:column;gap:6px; }
.cr-tmpl-opt {
    display:flex;align-items:center;gap:9px;
    padding:9px 12px;border-radius:9px;border:1px solid var(--border);
    background:var(--bg);cursor:pointer;font-size:12px;color:var(--text-muted);
    transition:all .2s;
}
.cr-tmpl-opt:hover { border-color:var(--border-hover);color:var(--text); }
.cr-tmpl-opt:has(input:checked) { border-color:var(--accent);background:rgba(209,144,75,.07);color:var(--accent); }
.cr-tmpl-opt input { display:none; }
.cr-tmpl-opt i { font-size:13px;flex-shrink:0; }
</style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
    <a href="dashboard.php" class="btn-nav icon-only" title="Back to dashboard"><i class="fa-solid fa-arrow-left"></i></a>
    <div class="topbar-sep"></div>
    <div class="brand-icon"><i class="fa-solid fa-shield-halved"></i></div>
    <div class="brand-text">
        <span class="brand-title">Role Permissions</span>
        <span class="brand-sub">Bird's Nest Coffee &rsaquo; Admin</span>
    </div>
    <div class="topbar-right">
        <button class="btn-nav icon-only" onclick="toggleTheme()" title="Toggle theme"><i class="fa-solid fa-moon" id="themeIcon"></i></button>
    </div>
</div>

<div class="container">

    <!-- HERO -->
    <div class="page-hero">
        <div class="hero-left">
            <div class="hero-icon"><i class="fa-solid fa-shield-halved"></i></div>
            <div>
                <div class="hero-title">Manage Role Permissions <span class="hero-badge"><i class="fa-solid fa-lock" style="font-size:9px"></i> Admin Only</span></div>
                <div class="hero-sub">Control which pages and features each role can access. Admin always retains full access.</div>
            </div>
        </div>
        <div class="stats-bar">
            <div class="stat-chip">
                <div class="stat-chip-icon ic-roles"><i class="fa-solid fa-users"></i></div>
                <div class="stat-chip-body">
                    <span class="stat-chip-num"><?= count($roles) ?></span>
                    <span class="stat-chip-lbl">Roles</span>
                </div>
            </div>
            <div class="stat-chip">
                <div class="stat-chip-icon ic-perms"><i class="fa-solid fa-key"></i></div>
                <div class="stat-chip-body">
                    <span class="stat-chip-num"><?= $total_perm_count ?></span>
                    <span class="stat-chip-lbl">Permissions</span>
                </div>
            </div>
            <div class="stat-chip">
                <div class="stat-chip-icon ic-modules"><i class="fa-solid fa-layer-group"></i></div>
                <div class="stat-chip-body">
                    <span class="stat-chip-num"><?= $module_count ?></span>
                    <span class="stat-chip-lbl">Modules</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ROLES SECTION -->
    <div class="sec-hdr" style="animation:fadeUp .45s cubic-bezier(.22,1,.36,1) both;animation-delay:.1s">
        <div class="sec-hdr-left">
            <div class="sec-hdr-dot"></div>
            <span class="sec-hdr-label">Roles Overview</span>
        </div>
        <div style="display:flex;align-items:center;gap:12px;">
            <span style="font-size:11px;color:var(--text-muted)">Click <strong style="color:var(--text)">Edit Permissions</strong> on a role to configure access</span>
            <button type="button" class="btn-create-role" onclick="openUserOverridesModal()" style="background:rgba(52,152,219,.12);border-color:rgba(52,152,219,.35);color:#3498db;">
                <i class="fa-solid fa-user-gear"></i> Per-User Overrides
            </button>
            <button class="btn-create-role" onclick="document.getElementById('createRoleModal').classList.add('open')">
                <i class="fa-solid fa-plus"></i> Create Role
            </button>
        </div>
    </div>

    <div class="roles-grid">
    <?php foreach ($roles as $rkey => $rinfo):
        $count = $role_counts[$rkey] ?? 0;
        $pct   = $total_perm_count > 0 ? round($count / $total_perm_count * 100) : 0;
        $col   = $rinfo['color'];
    ?>
    <div class="role-card" style="--rc:<?= $col ?>" data-role="<?= $rkey ?>">
        <div class="role-card-accent" style="background:linear-gradient(90deg,<?= $col ?>99,<?= $col ?>,<?= $col ?>cc)"></div>
        <div class="role-card-body">
            <div class="role-header">
                <div class="role-avatar" style="background:<?= $col ?>22;color:<?= $col ?>"><i class="fa-solid <?= $rinfo['icon'] ?>"></i></div>
                <div class="role-name-wrap" style="flex:1">
                    <div class="role-name"><?= htmlspecialchars($rinfo['label']) ?></div>
                    <span class="role-badge"><?= $rinfo['editable'] ? 'Configurable' : 'Protected' ?></span>
                </div>
                <?php if ($rkey !== 'admin'): ?>
                <button class="btn-edit-meta" title="Edit role"
                    data-slug="<?= htmlspecialchars($rkey) ?>"
                    data-name="<?= htmlspecialchars($rinfo['label']) ?>"
                    data-desc="<?= htmlspecialchars($rinfo['desc']) ?>"
                    data-icon="<?= htmlspecialchars($rinfo['icon']) ?>"
                    data-color="<?= htmlspecialchars($rinfo['color']) ?>"
                    onclick="openEditRoleMeta(this)">
                    <i class="fa-solid fa-pen-to-square"></i>
                </button>
                <?php if (($emp_counts[$rkey] ?? 0) > 0): ?>
                <button class="btn-reassign-role" title="Bulk reassign employees"
                    onclick="openBulkReassignModal('<?= htmlspecialchars($rkey, ENT_QUOTES) ?>','<?= htmlspecialchars($rinfo['label'], ENT_QUOTES) ?>',<?= $emp_counts[$rkey] ?? 0 ?>)">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </button>
                <?php endif; ?>
                <?php if (!$rinfo['system']): ?>
                <button class="btn-delete-role" title="Delete role"
                    onclick="openDeleteModal('<?= htmlspecialchars($rkey, ENT_QUOTES) ?>','<?= htmlspecialchars($rinfo['label'], ENT_QUOTES) ?>',<?= $emp_counts[$rkey] ?? 0 ?>)">
                    <i class="fa-solid fa-trash-can"></i>
                </button>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="role-desc"><?= htmlspecialchars($rinfo['desc']) ?></div>
            <?php $ecount = $emp_counts[$rkey] ?? 0; ?>
            <div class="emp-count-chip">
                <i class="fa-solid fa-users" style="color:<?= $col ?>;opacity:.7"></i>
                <span><?= $ecount ?> employee<?= $ecount !== 1 ? 's' : '' ?></span>
            </div>
            <div class="perm-count-row">
                <span class="perm-big-num" id="bignum-<?= $rkey ?>"><?= $count ?></span>
                <span class="perm-big-of">/ <?= $total_perm_count ?> permissions</span>
            </div>
            <div class="perm-bar-wrap">
                <div class="perm-bar-lbl">
                    <span>Access coverage</span>
                    <strong id="pct-<?= $rkey ?>"><?= $pct ?>%</strong>
                </div>
                <div class="perm-bar"><div class="perm-bar-fill" id="bar-<?= $rkey ?>" style="width:<?= $pct ?>%;background:<?= $col ?>"></div></div>
            </div>
        </div>
        <div class="role-card-foot">
            <?php if ($rinfo['editable']): ?>
            <button class="btn-edit-role" style="border-color:<?= $col ?>44;color:<?= $col ?>" onclick="openModal('<?= $rkey ?>')">
                <i class="fa-solid fa-sliders"></i> Edit Permissions
            </button>
            <?php else: ?>
            <div class="lock-note"><i class="fa-solid fa-lock" style="color:var(--accent)"></i> Admin has all <?= $total_perm_count ?> permissions and cannot be restricted.</div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <!-- CREATE ROLE MODAL -->
    <div class="cr-overlay" id="createRoleModal" onclick="if(event.target===this)this.classList.remove('open')">
        <div class="cr-modal">
            <div class="cr-modal-head">
                <span><i class="fa-solid fa-shield-halved" style="color:var(--accent);margin-right:7px"></i>Create New Role</span>
                <button class="cr-close" onclick="document.getElementById('createRoleModal').classList.remove('open')">&times;</button>
            </div>
            <form method="POST" class="cr-form">
                <input type="hidden" name="action" value="create_role">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <div class="cr-field">
                    <label>Role Name <span style="color:#e74c3c">*</span></label>
                    <input type="text" name="role_name" placeholder="e.g. Kitchen, Supervisor" maxlength="50" required>
                </div>
                <div class="cr-field">
                    <label>Description</label>
                    <input type="text" name="role_desc" placeholder="Brief description of access level" maxlength="150">
                </div>
                <div class="cr-row">
                    <div class="cr-field">
                        <label>Icon</label>
                        <div class="cr-icon-pick">
                            <?php
                            $icon_opts = ['fa-user','fa-user-tie','fa-user-gear','fa-user-check','fa-utensils','fa-mug-hot','fa-cash-register','fa-headset','fa-star','fa-shield-halved','fa-crown','fa-store'];
                            foreach ($icon_opts as $ic): ?>
                            <label class="cr-icon-opt" title="<?= $ic ?>">
                                <input type="radio" name="role_icon" value="<?= $ic ?>" <?= $ic==='fa-user'?'checked':'' ?> hidden>
                                <i class="fa-solid <?= $ic ?>"></i>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="cr-field" style="min-width:110px">
                        <label>Color</label>
                        <input type="color" name="role_color" value="#888888" style="width:100%;height:38px;border-radius:8px;border:1px solid var(--border);background:var(--bg-card);cursor:pointer;padding:2px 4px;">
                    </div>
                </div>
                <div class="cr-field" style="margin-bottom:4px">
                    <label>Permission Template</label>
                    <div class="cr-template-group">
                        <label class="cr-tmpl-opt">
                            <input type="radio" name="role_template" value="" checked>
                            <i class="fa-solid fa-ban"></i> Start Empty
                        </label>
                        <label class="cr-tmpl-opt">
                            <input type="radio" name="role_template" value="staff">
                            <i class="fa-solid fa-user"></i> Copy from Staff
                        </label>
                        <label class="cr-tmpl-opt">
                            <input type="radio" name="role_template" value="manager">
                            <i class="fa-solid fa-user-tie"></i> Copy from Manager
                        </label>
                    </div>
                </div>
                <button type="submit" class="cr-submit"><i class="fa-solid fa-plus"></i> Create Role</button>
            </form>
        </div>
    </div>

    <!-- EDIT ROLE META MODAL -->
    <div class="cr-overlay" id="editRoleMetaModal" onclick="if(event.target===this)closeEditMeta()">
        <div class="cr-modal">
            <div class="cr-modal-head">
                <span><i class="fa-solid fa-pen-to-square" style="color:var(--accent);margin-right:7px"></i>Edit Role</span>
                <button class="cr-close" onclick="closeEditMeta()">&times;</button>
            </div>
            <form method="POST" class="cr-form">
                <input type="hidden" name="action" value="edit_role">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="role_slug" id="erm-slug">
                <div class="cr-field">
                    <label>Role Name <span style="color:#e74c3c">*</span></label>
                    <input type="text" id="erm-name" name="role_name" placeholder="e.g. Kitchen, Supervisor" maxlength="50" required>
                </div>
                <div class="cr-field">
                    <label>Description</label>
                    <input type="text" id="erm-desc" name="role_desc" placeholder="Brief description of access level" maxlength="150">
                </div>
                <div class="cr-row">
                    <div class="cr-field">
                        <label>Icon</label>
                        <div class="cr-icon-pick">
                            <?php foreach ($icon_opts as $ic): ?>
                            <label class="cr-icon-opt" title="<?= $ic ?>">
                                <input type="radio" name="role_icon" value="<?= $ic ?>" hidden>
                                <i class="fa-solid <?= $ic ?>"></i>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="cr-field" style="min-width:110px">
                        <label>Color</label>
                        <input type="color" id="erm-color" name="role_color" value="#888888" style="width:100%;height:38px;border-radius:8px;border:1px solid var(--border);background:var(--bg-card);cursor:pointer;padding:2px 4px;">
                    </div>
                </div>
                <button type="submit" class="cr-submit"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
            </form>
        </div>
    </div>

    <!-- DELETE ROLE MODAL -->
    <div class="cr-overlay" id="deleteRoleModal" onclick="if(event.target===this)closeDeleteModal()">
        <div class="cr-modal" style="max-width:420px">
            <div class="cr-modal-head">
                <span><i class="fa-solid fa-trash-can" style="color:#e74c3c;margin-right:7px"></i>Delete Role</span>
                <button class="cr-close" onclick="closeDeleteModal()">&times;</button>
            </div>
            <form method="POST" id="deleteRoleForm">
                <input type="hidden" name="action" value="delete_role">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="role_slug" id="dr-slug">
                <input type="hidden" name="reassign_to" id="dr-reassign">

                <p id="dr-msg" style="font-size:13px;color:var(--text-muted);margin-bottom:10px;line-height:1.6"></p>
                <div id="dr-names-list" style="margin-bottom:14px;line-height:1.8"></div>

                <div id="dr-reassign-wrap" style="display:none;margin-bottom:18px">
                    <label style="font-size:11px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:8px">
                        Reassign employees to
                    </label>
                    <div id="dr-role-options" style="display:flex;flex-direction:column;gap:6px"></div>
                </div>

                <div style="display:flex;gap:10px">
                    <button type="button" onclick="closeDeleteModal()" class="btn-cancel-modal" style="flex:1">Cancel</button>
                    <button type="submit" id="dr-confirm-btn" class="cr-submit" style="flex:1;background:#e74c3c;margin-top:0">
                        <i class="fa-solid fa-trash-can"></i> Delete
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- BULK REASSIGN MODAL -->
    <div class="cr-overlay" id="bulkReassignModal" onclick="if(event.target===this)closeBulkReassign()">
        <div class="cr-modal" style="max-width:400px">
            <div class="cr-modal-head">
                <span><i class="fa-solid fa-arrows-rotate" style="color:#3498db;margin-right:7px"></i>Bulk Reassign</span>
                <button class="cr-close" onclick="closeBulkReassign()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="bulk_reassign">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="from_role" id="br-from">
                <p id="br-msg" style="font-size:13px;color:var(--text-muted);margin-bottom:14px;line-height:1.6"></p>
                <div style="margin-bottom:18px">
                    <label style="font-size:11px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:8px">
                        Move all employees to
                    </label>
                    <div id="br-role-options" style="display:flex;flex-direction:column;gap:6px"></div>
                    <input type="hidden" name="to_role" id="br-to">
                </div>
                <div style="display:flex;gap:10px">
                    <button type="button" onclick="closeBulkReassign()" class="btn-cancel-modal" style="flex:1">Cancel</button>
                    <button type="submit" id="br-confirm-btn" class="cr-submit" style="flex:1;background:#3498db;margin-top:0">
                        <i class="fa-solid fa-arrows-rotate"></i> Reassign
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- PERMISSIONS MATRIX SECTION -->
    <div class="perms-section">
        <div class="sec-hdr">
            <div class="sec-hdr-left">
                <div class="sec-hdr-dot"></div>
                <span class="sec-hdr-label">Permissions Matrix</span>
            </div>
            <div class="search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" class="perm-search" id="permSearch" placeholder="Search permissions…" autocomplete="off">
            </div>
        </div>

        <!-- Module filters -->
        <div class="module-filters" style="margin-bottom:14px">
            <button class="mod-filter-btn active" data-module="all">
                <i class="fa-solid fa-table-cells"></i> All
                <span class="mod-badge"><?= $total_perm_count ?></span>
            </button>
            <?php foreach ($all_perms as $module => $mperms):
                $meta = $module_meta[$module] ?? ['icon' => 'fa-puzzle-piece', 'color' => '#888'];
            ?>
            <button class="mod-filter-btn" data-module="<?= htmlspecialchars($module) ?>">
                <i class="fa-solid <?= $meta['icon'] ?>" style="color:<?= $meta['color'] ?>"></i>
                <?= htmlspecialchars($module) ?>
                <span class="mod-badge"><?= count($mperms) ?></span>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- Table -->
        <div class="table-wrap">
        <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th style="min-width:200px">Permission</th>
                    <?php foreach ($roles as $rslug => $rinfo): ?>
                    <th class="role-col" style="min-width:110px">
                        <i class="fa-solid <?= $rinfo['icon'] ?>" style="color:<?= $rinfo['color'] ?>;margin-right:5px"></i><?= htmlspecialchars($rinfo['label']) ?>
                    </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody id="permTableBody">
            <?php foreach ($all_perms as $module => $mperms):
                $meta = $module_meta[$module] ?? ['icon' => 'fa-puzzle-piece', 'color' => '#888'];
            ?>
                <tr class="module-row" data-module="<?= htmlspecialchars($module) ?>">
                    <td colspan="<?= count($roles) + 1 ?>">
                        <div class="module-row-inner">
                            <div class="module-icon-wrap" style="background:<?= $meta['color'] ?>1a;color:<?= $meta['color'] ?>">
                                <i class="fa-solid <?= $meta['icon'] ?>"></i>
                            </div>
                            <span class="module-row-name"><?= htmlspecialchars($module) ?></span>
                            <span class="module-row-count"><?= count($mperms) ?> permission<?= count($mperms) !== 1 ? 's' : '' ?></span>
                        </div>
                    </td>
                </tr>
                <?php foreach ($mperms as $p): ?>
                <tr class="perm-row" data-module="<?= htmlspecialchars($module) ?>" id="perm-row-<?= $p['id'] ?>">
                    <td>
                        <div class="perm-name">
                            <div class="perm-dot"></div>
                            <span class="perm-name-text" id="perm-name-<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></span>
                        </div>
                    </td>
                    <?php foreach ($roles as $rslug => $rinfo):
                        $col = $rinfo['color'];
                        if ($rslug === 'admin'): ?>
                    <td class="role-col">
                        <span class="toggle-pill on-admin"><i class="fa-solid fa-check"></i> Yes</span>
                    </td>
                    <?php else:
                        $has = isset($role_perm_ids[$rslug][$p['id']]);
                        $pillStyle = $has ? "style=\"--pill-bg:{$col}1a;--pill-color:{$col};--pill-border:{$col}40\"" : '';
                    ?>
                    <td class="role-col" id="cell-<?= $rslug ?>-<?= $p['id'] ?>">
                        <button class="toggle-pill <?= $has ? 'on-role' : 'off' ?>" <?= $pillStyle ?>
                                data-color="<?= $col ?>"
                                onclick="quickToggle('<?= $rslug ?>',<?= $p['id'] ?>,this)" title="Click to toggle">
                            <?= $has ? '<i class="fa-solid fa-check"></i> Yes' : '<i class="fa-solid fa-xmark"></i> No' ?>
                        </button>
                    </td>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <tr class="no-results-row" id="noResults" style="display:none">
                <td colspan="<?= count($roles) + 1 ?>"><i class="fa-solid fa-search" style="margin-right:7px;opacity:.4"></i>No permissions match your search.</td>
            </tr>
            </tbody>
        </table>
        </div>
        </div>
    </div>

</div><!-- /container -->

<!-- EDIT MODAL -->
<div class="modal-overlay" id="editModal" onclick="if(event.target===this)closeModal()">
    <div class="modal-box">
        <div class="modal-accent" id="modalAccent"></div>
        <button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        <div class="modal-head">
            <div class="modal-title" id="modalTitle"><i class="fa-solid fa-sliders"></i> Edit Role</div>
            <div class="modal-sub" id="modalSub">Check the pages and features this role can access.</div>
            <div class="modal-toolbar">
                <span class="modal-count" id="checkedCount">0 permissions selected</span>
                <div class="modal-actions">
                    <button class="btn-select" onclick="selectAll(true)">Select All</button>
                    <span style="color:var(--border-hover)">·</span>
                    <button class="btn-select" onclick="selectAll(false)">Clear All</button>
                </div>
            </div>
            <div class="modal-divider"></div>
        </div>
        <div class="modal-body" id="modalBody"></div>
        <div class="modal-foot">
            <button class="btn-cancel-modal" onclick="closeModal()">Cancel</button>
            <button class="btn-save" id="saveBtn" onclick="savePermissions()">
                <i class="fa-solid fa-floppy-disk"></i> Save Changes
            </button>
        </div>
<!-- USER PERMISSIONS OVERRIDES MODAL -->
<div class="modal-overlay" id="userOverridesModal" onclick="if(event.target===this)closeUserOverridesModal()">
    <div class="modal-box" style="max-width:680px">
        <div class="modal-accent" style="background:linear-gradient(90deg, #1a6899, #3498db, #5dade2)"></div>
        <button class="modal-close" onclick="closeUserOverridesModal()"><i class="fa-solid fa-xmark"></i></button>
        <div class="modal-head">
            <div class="modal-title">
                <div style="width:36px;height:36px;border-radius:10px;background:rgba(52,152,219,.14);color:#3498db;display:inline-flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;">
                    <i class="fa-solid fa-user-gear"></i>
                </div>
                Per-User Permission Overrides
            </div>
            <div class="modal-sub">Grant or revoke specific permissions for an individual employee without affecting their entire role.</div>
            
            <div style="margin-top:16px;display:flex;gap:10px;align-items:center;">
                <select id="userOverrideSelect" onchange="onUserOverrideSelectChange()" style="flex:1;padding:11px 16px;border-radius:12px;border:1px solid var(--border-hover);background:var(--bg-input);color:var(--text);font-family:'Poppins',sans-serif;font-size:13.5px;font-weight:500;outline:none;transition:all .2s;cursor:pointer;">
                    <option value="0">-- Select Employee --</option>
                    <?php foreach ($all_user_options as $uopt): ?>
                    <option value="<?= (int)$uopt['user_id'] ?>"><?= htmlspecialchars($uopt['emp_name']) ?> (<?= htmlspecialchars($uopt['username']) ?> — <?= htmlspecialchars($uopt['role_name']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-divider"></div>
        </div>

        <div class="modal-body" id="userOverridesModalBody" style="padding-top:14px;padding-bottom:20px;">
            <div style="padding:48px 20px;text-align:center;color:var(--text-muted)">
                <i class="fa-solid fa-user-check" style="font-size:36px;display:block;margin-bottom:12px;opacity:.3"></i>
                Select an employee from the dropdown above to manage their custom permissions.
            </div>
        </div>

        <div class="modal-foot">
            <button class="btn-cancel-modal" onclick="closeUserOverridesModal()">Close</button>
            <button class="btn-save" id="saveUserOverridesBtn" onclick="saveUserOverrides()" disabled style="background:linear-gradient(135deg, #1a6899, #3498db);color:#fff;box-shadow:0 4px 16px rgba(52,152,219,.35);">
                <i class="fa-solid fa-floppy-disk"></i> Save User Overrides
            </button>
        </div>
    </div>
</div>

<div id="toast-cnt"></div>

<script>
const CSRF       = '<?= htmlspecialchars($_SESSION['csrf_token']) ?>';
const ALL_PERMS  = <?= json_encode($all_perms, JSON_UNESCAPED_UNICODE) ?>;
const ROLE_IDS   = <?= json_encode(array_map(fn($ids) => array_keys($ids), $role_perm_ids), JSON_UNESCAPED_UNICODE) ?>;
const TOTAL      = <?= $total_perm_count ?>;
const MOD_META   = <?= json_encode($module_meta, JSON_UNESCAPED_UNICODE) ?>;
const ROLES_INFO = <?= json_encode($roles_js, JSON_UNESCAPED_UNICODE) ?>;
/* Role names, descriptions and employee names are free text. Anything from those
   columns must go through this before it touches innerHTML. */
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => (
    { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]
));

let currentRole      = null;
let currentRoleColor = '#888888';
let currentRoleCls   = 'for-role';
let selected         = new Set();

/* ── SEARCH + FILTER ── */
const searchEl = document.getElementById('permSearch');
searchEl.addEventListener('input', filterTable);

document.querySelectorAll('.mod-filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.mod-filter-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        filterTable();
    });
});

function filterTable() {
    const q      = searchEl.value.toLowerCase().trim();
    const active = document.querySelector('.mod-filter-btn.active');
    const mod    = active ? active.dataset.module : 'all';
    let   anyVisible = false;

    document.querySelectorAll('#permTableBody .perm-row').forEach(row => {
        const name    = row.querySelector('.perm-name')?.textContent.toLowerCase() || '';
        const rowMod  = row.dataset.module || '';
        const matchQ  = !q || name.includes(q);
        const matchM  = mod === 'all' || rowMod === mod;
        const visible = matchQ && matchM;
        row.style.display = visible ? '' : 'none';
        if (visible) anyVisible = true;
    });

    document.querySelectorAll('#permTableBody .module-row').forEach(mrow => {
        const modName = mrow.dataset.module;
        if (mod !== 'all' && modName !== mod) { mrow.style.display = 'none'; return; }
        let next = mrow.nextElementSibling;
        let vis  = false;
        while (next && !next.classList.contains('module-row')) {
            if (next.style.display !== 'none' && next.classList.contains('perm-row')) vis = true;
            next = next.nextElementSibling;
        }
        mrow.style.display = vis ? '' : 'none';
    });

    document.getElementById('noResults').style.display = anyVisible ? 'none' : '';
}

/* ── MODAL ── */
function openModal(role) {
    currentRole = role;
    const info  = ROLES_INFO[role];
    if (!info) return;
    currentRoleColor = info.color;
    currentRoleCls   = info.cls;
    document.getElementById('modalTitle').innerHTML =
        `<i class="fa-solid ${esc(info.icon)}" style="color:${esc(info.color)}"></i> Edit Permissions — ${esc(info.label)}`;
    document.getElementById('modalAccent').style.background = info.accent;
    selected = new Set((ROLE_IDS[role] || []).map(Number));
    renderModal();
    document.getElementById('editModal').classList.add('open');
}

function renderModal() {
    let html = '';
    for (const [module, perms] of Object.entries(ALL_PERMS)) {
        const meta = MOD_META[module] || { icon:'fa-puzzle-piece', color:'#888' };
        const checkedN = perms.filter(p => selected.has(parseInt(p.id))).length;
        html += `<div class="module-group">
            <div class="module-group-hdr">
                <div class="mg-icon" style="background:${meta.color}1a;color:${meta.color}"><i class="fa-solid ${meta.icon}"></i></div>
                ${module}
                <span style="font-size:10px;color:var(--text-muted);margin-left:4px;font-weight:500;text-transform:none;letter-spacing:0">${checkedN}/${perms.length}</span>
            </div>
            <div class="perm-check-list">`;
        for (const p of perms) {
            const checked = selected.has(parseInt(p.id));
            const bStyle  = checked ? `border-color:${currentRoleColor}44;background:${currentRoleColor}11` : '';
            const cbStyle = checked ? `background:${currentRoleColor};border-color:${currentRoleColor}` : '';
            html += `<div class="perm-check-item ${checked ? 'checked' : ''}" style="${bStyle}"
                          onclick="togglePerm(${p.id}, this)">
                <div class="perm-checkbox" style="${cbStyle}"><i class="fa-solid fa-check"></i></div>
                <span class="perm-check-name">${p.name}</span>
            </div>`;
        }
        html += `</div></div>`;
    }
    document.getElementById('modalBody').innerHTML = html;
    updateCount();
}

function togglePerm(id, el) {
    const chk = el.querySelector('.perm-checkbox');
    if (selected.has(id)) {
        selected.delete(id); el.classList.remove('checked');
        el.style.borderColor = ''; el.style.background = '';
        chk.style.background = ''; chk.style.borderColor = '';
    } else {
        selected.add(id); el.classList.add('checked');
        el.style.borderColor = currentRoleColor + '44'; el.style.background = currentRoleColor + '11';
        chk.style.background = currentRoleColor; chk.style.borderColor = currentRoleColor;
    }
    updateCount();
}

function updateCount() {
    document.getElementById('checkedCount').textContent =
        `${selected.size} of ${TOTAL} permissions selected`;
}

function selectAll(state) {
    document.querySelectorAll('.perm-check-item').forEach(el => {
        const id  = parseInt(el.getAttribute('onclick').match(/\d+/)[0]);
        const chk = el.querySelector('.perm-checkbox');
        if (state) {
            selected.add(id); el.classList.add('checked');
            el.style.borderColor = currentRoleColor + '44'; el.style.background = currentRoleColor + '11';
            chk.style.background = currentRoleColor; chk.style.borderColor = currentRoleColor;
        } else {
            selected.delete(id); el.classList.remove('checked');
            el.style.borderColor = ''; el.style.background = '';
            chk.style.background = ''; chk.style.borderColor = '';
        }
    });
    updateCount();
}

function closeModal() {
    document.getElementById('editModal').classList.remove('open');
    currentRole = null;
}

/* ── SAVE MODAL ── */
async function savePermissions() {
    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    const ids  = [...selected];
    const body = new URLSearchParams({ action:'save_permissions', role:currentRole, csrf_token:CSRF });
    ids.forEach(id => body.append('permissions[]', id));
    try {
        const res  = await fetch('manage_roles.php', { method:'POST', body });
        const data = await res.json();
        if (data.success) {
            ROLE_IDS[currentRole] = ids;
            refreshTableCells(currentRole, ids);
            updateCard(currentRole, ids.length);
            const savedRole = currentRole;
            closeModal();
            showToast(`${savedRole.charAt(0).toUpperCase()+savedRole.slice(1)} permissions saved`, 'success');
        } else {
            showToast(data.message || 'Save failed', 'error');
        }
    } catch { showToast('Network error', 'error'); }
    finally  { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes'; }
}

/* ── QUICK TOGGLE (table inline) ── */
const _pending = {};
async function quickToggle(role, pid, btn) {
    const isOn     = btn.classList.contains('on-role');
    const newState = !isOn;
    const color    = btn.dataset.color || ROLES_INFO[role]?.color || '#888';
    btn.className  = `toggle-pill ${newState ? 'on-role' : 'off'}`;
    btn.style.cssText = newState ? `--pill-bg:${color}1a;--pill-color:${color};--pill-border:${color}40` : '';
    btn.innerHTML  = newState ? '<i class="fa-solid fa-check"></i> Yes' : '<i class="fa-solid fa-xmark"></i> No';

    const arr = ROLE_IDS[role] ? [...ROLE_IDS[role]] : [];
    if (newState && !arr.includes(pid)) arr.push(pid);
    if (!newState) { const i = arr.indexOf(pid); if (i > -1) arr.splice(i, 1); }
    ROLE_IDS[role] = arr;
    updateCard(role, arr.length);

    clearTimeout(_pending[role]);
    _pending[role] = setTimeout(async () => {
        const body = new URLSearchParams({ action:'save_permissions', role, csrf_token:CSRF });
        ROLE_IDS[role].forEach(id => body.append('permissions[]', id));
        try {
            const res  = await fetch('manage_roles.php', { method:'POST', body });
            const data = await res.json();
            if (!data.success) showToast('Save failed', 'error');
            else showToast('Saved', 'success');
        } catch { showToast('Network error', 'error'); }
    }, 600);
}

function refreshTableCells(role, ids) {
    const color = ROLES_INFO[role]?.color || '#888';
    document.querySelectorAll(`[id^="cell-${role}-"]`).forEach(cell => {
        const pid = parseInt(cell.id.slice(`cell-${role}-`.length));
        const has = ids.includes(pid);
        const st  = has ? `style="--pill-bg:${color}1a;--pill-color:${color};--pill-border:${color}40"` : '';
        cell.innerHTML = `<button class="toggle-pill ${has ? 'on-role' : 'off'}" ${st}
            data-color="${color}" onclick="quickToggle('${role}',${pid},this)" title="Click to toggle">
            ${has ? '<i class="fa-solid fa-check"></i> Yes' : '<i class="fa-solid fa-xmark"></i> No'}
        </button>`;
    });
}

function updateCard(role, count) {
    const pct    = Math.round(count / TOTAL * 100);
    const bigNum = document.getElementById(`bignum-${role}`);
    const pctEl  = document.getElementById(`pct-${role}`);
    const fill   = document.getElementById(`bar-${role}`);
    if (bigNum) bigNum.textContent = count;
    if (pctEl)  pctEl.textContent  = pct + '%';
    if (fill)   fill.style.width   = pct + '%';
}

/* ── TOAST ── */
function showToast(msg, type='success') {
    const c = document.getElementById('toast-cnt');
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    const icon = type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation';
    const col  = type === 'success' ? 'var(--ok)' : 'var(--danger)';
    t.innerHTML = `<i class="fa-solid ${icon}" style="color:${col};flex-shrink:0"></i><span>${msg}</span>`;
    c.appendChild(t);
    requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 380); }, 3000);
}

/* ── EDIT ROLE META ── */
function openEditRoleMeta(btn) {
    const d = btn.dataset;
    document.getElementById('erm-slug').value  = d.slug;
    document.getElementById('erm-name').value  = d.name;
    document.getElementById('erm-desc').value  = d.desc || '';
    document.getElementById('erm-color').value = d.color;
    document.querySelectorAll('#editRoleMetaModal .cr-icon-opt input').forEach(r => {
        r.checked = r.value === d.icon;
    });
    document.getElementById('editRoleMetaModal').classList.add('open');
}
function closeEditMeta() {
    document.getElementById('editRoleMetaModal').classList.remove('open');
}

/* One role-picker row, built as DOM nodes with a real event listener.
   The previous version interpolated the slug and colour into an onclick="" attribute.
   Escaping cannot secure that: the HTML parser decodes entities when it builds the
   attribute, and only then does the JS parser see the result — so a quote comes back
   to life and breaks out. Removing the inline handler removes the sink. */
function buildRoleOption(slug, info, hiddenInputId, containerSel) {
    const label = document.createElement('label');
    label.style.cssText = 'display:flex;align-items:center;gap:10px;padding:9px 13px;'
        + 'border-radius:9px;border:1.5px solid var(--border);background:var(--bg);'
        + 'cursor:pointer;transition:all .2s;font-size:13px;font-weight:500';

    const icon = document.createElement('i');
    icon.className     = 'fa-solid ' + info.icon;
    icon.style.color   = info.color;   // CSSOM drops an invalid value instead of parsing it
    icon.style.width   = '16px';
    icon.style.textAlign = 'center';

    label.append(icon, document.createTextNode(' ' + info.label));
    label.addEventListener('click', () => {
        document.getElementById(hiddenInputId).value = slug;
        document.querySelectorAll(containerSel + ' label').forEach(l => l.style.borderColor = '');
        label.style.borderColor = info.color;
    });
    return label;
}

/* ── DELETE ROLE MODAL ── */
async function openDeleteModal(slug, label, empCount) {
    document.getElementById('dr-slug').value = slug;
    document.getElementById('dr-reassign').value = '';

    const msg  = document.getElementById('dr-msg');
    const wrap = document.getElementById('dr-reassign-wrap');
    const opts = document.getElementById('dr-role-options');
    const namesList = document.getElementById('dr-names-list');

    if (empCount > 0) {
        msg.innerHTML = `<strong style="color:var(--text)">${empCount} employee${empCount !== 1 ? 's' : ''}</strong> currently have the <strong style="color:var(--text)">${esc(label)}</strong> role. Choose a role to reassign them to before deleting.`;
        wrap.style.display = 'block';

        // Fetch employee names async
        if (namesList) {
            namesList.textContent = 'Loading…';
            try {
                const r = await fetch(`manage_roles.php?action=role_employees&slug=${encodeURIComponent(slug)}`);
                const d = await r.json();
                if (d.names && d.names.length) {
                    namesList.innerHTML = d.names.map(n => `<span style="display:inline-block;background:rgba(255,255,255,.07);border:1px solid var(--border);border-radius:6px;padding:2px 8px;font-size:11px;margin:2px">${esc(n)}</span>`).join('');
                    if (empCount > d.names.length) namesList.innerHTML += `<span style="font-size:11px;color:var(--text-muted)"> +${empCount - d.names.length} more</span>`;
                } else { namesList.textContent = ''; }
            } catch(_) { namesList.textContent = ''; }
        }

        // Build role options
        opts.innerHTML = '';
        let first = true;
        for (const [rs, ri] of Object.entries(ROLES_INFO)) {
            if (rs === slug || rs === 'admin') continue;
            if (first) { document.getElementById('dr-reassign').value = rs; first = false; }
            opts.append(buildRoleOption(rs, ri, 'dr-reassign', '#dr-role-options'));
        }
        document.getElementById('dr-confirm-btn').innerHTML = '<i class="fa-solid fa-trash-can"></i> Reassign & Delete';

        const firstLabel = opts.querySelector('label');
        const firstSlug  = Object.keys(ROLES_INFO).find(rs => rs !== slug && rs !== 'admin');
        if (firstLabel && firstSlug) firstLabel.style.borderColor = ROLES_INFO[firstSlug].color;
    } else {
        msg.innerHTML = `Are you sure you want to delete the <strong style="color:var(--text)">${esc(label)}</strong> role? This cannot be undone.`;
        wrap.style.display = 'none';
        if (namesList) namesList.textContent = '';
        document.getElementById('dr-reassign').value = '';
        document.getElementById('dr-confirm-btn').innerHTML = '<i class="fa-solid fa-trash-can"></i> Delete';
    }

    document.getElementById('deleteRoleModal').classList.add('open');
}
function closeDeleteModal() {
    document.getElementById('deleteRoleModal').classList.remove('open');
}

/* ── BULK REASSIGN MODAL ── */
function openBulkReassignModal(slug, label, empCount) {
    document.getElementById('br-from').value = slug;
    document.getElementById('br-to').value   = '';
    document.getElementById('br-msg').innerHTML =
        `Move all <strong style="color:var(--text)">${empCount} employee${empCount !== 1 ? 's' : ''}</strong> currently assigned to <strong style="color:var(--text)">${esc(label)}</strong> to a different role.`;

    const opts = document.getElementById('br-role-options');
    opts.innerHTML = '';
    let first = true;
    for (const [rs, ri] of Object.entries(ROLES_INFO)) {
        if (rs === slug || rs === 'admin') continue;
        if (first) { document.getElementById('br-to').value = rs; first = false; }
        opts.append(buildRoleOption(rs, ri, 'br-to', '#br-role-options'));
    }
    const firstLabel = opts.querySelector('label');
    const firstSlug  = Object.keys(ROLES_INFO).find(rs => rs !== slug && rs !== 'admin');
    if (firstLabel && firstSlug) firstLabel.style.borderColor = ROLES_INFO[firstSlug].color;

    document.getElementById('bulkReassignModal').classList.add('open');
}
function closeBulkReassign() {
    document.getElementById('bulkReassignModal').classList.remove('open');
}

/* ── THEME ── */
function toggleTheme() {
    const html  = document.documentElement;
    const icon  = document.getElementById('themeIcon');
    const light = html.getAttribute('data-theme') === 'light';
    if (light) { html.removeAttribute('data-theme'); icon.className='fa-solid fa-moon'; localStorage.setItem('theme','dark'); }
    else        { html.setAttribute('data-theme','light'); icon.className='fa-solid fa-sun'; localStorage.setItem('theme','light'); }
}
document.addEventListener('DOMContentLoaded', () => {
    if (localStorage.getItem('theme') === 'light')
        document.getElementById('themeIcon').className = 'fa-solid fa-sun';
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeModal(); closeEditMeta(); closeDeleteModal(); closeBulkReassign(); closeUserOverridesModal(); } });

/* ── PER-USER PERMISSION OVERRIDES MODAL ── */
let currentOverrideUserId = 0;
let userOverridesMap = {};

function openUserOverridesModal(userId = 0) {
    document.getElementById('userOverridesModal').classList.add('open');
    if (userId > 0) {
        document.getElementById('userOverrideSelect').value = userId;
        onUserOverrideSelectChange();
    }
}

function closeUserOverridesModal() {
    document.getElementById('userOverridesModal').classList.remove('open');
}

function onUserOverrideSelectChange() {
    const uid = parseInt(document.getElementById('userOverrideSelect').value) || 0;
    currentOverrideUserId = uid;
    const saveBtn = document.getElementById('saveUserOverridesBtn');
    const body = document.getElementById('userOverridesModalBody');

    if (uid <= 0) {
        saveBtn.disabled = true;
        body.innerHTML = `
            <div style="padding:40px;text-align:center;color:var(--text-muted)">
                <i class="fa-solid fa-user-check" style="font-size:32px;display:block;margin-bottom:10px;opacity:.3"></i>
                Select an employee from the dropdown above to manage their custom permissions.
            </div>`;
        return;
    }

    body.innerHTML = `
        <div style="padding:40px;text-align:center;color:var(--text-muted)">
            <i class="fa-solid fa-spinner fa-spin" style="font-size:28px;display:block;margin-bottom:10px;color:#3498db"></i>
            Loading custom permissions for employee...
        </div>`;

    fetch(`manage_roles.php?action=user_permissions&user_id=${uid}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                showToast(data.error || 'Failed to load user permissions', false);
                return;
            }
            userOverridesMap = {};
            renderUserOverridesUI(data.user, data.modules);
            saveBtn.disabled = false;
        })
        .catch(err => {
            showToast('Network error loading user permissions', false);
        });
}

function renderUserOverridesUI(user, modules) {
    const body = document.getElementById('userOverridesModalBody');
    let html = `
        <div style="background:rgba(52,152,219,.08);border:1px solid rgba(52,152,219,.2);border-radius:12px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;">
            <div>
                <div style="font-weight:700;font-size:14px;color:var(--text)">${esc(user.emp_name)}</div>
                <div style="font-size:11px;color:var(--text-muted)">Username: <strong>${esc(user.username)}</strong> &bull; Base Role: <span class="role-badge" style="background:rgba(52,152,219,.15);color:#3498db;border:1px solid rgba(52,152,219,.3)">${esc(user.role_name)}</span></div>
            </div>
            <div style="font-size:11px;color:var(--text-muted);text-align:right">
                <div><span style="color:#55e087;font-weight:700">Grant</span> = Custom Allow</div>
                <div><span style="color:#e74c3c;font-weight:700">Deny</span> = Custom Revoke</div>
            </div>
        </div>
    `;

    for (const [module, perms] of Object.entries(modules)) {
        const meta = MOD_META[module] || { icon:'fa-puzzle-piece', color:'#888' };
        html += `
            <div class="module-group">
                <div class="module-group-hdr">
                    <div class="mg-icon" style="background:${meta.color}1a;color:${meta.color}">
                        <i class="fa-solid ${meta.icon}"></i>
                    </div>
                    ${esc(module)} (${perms.length})
                </div>
                <div style="display:flex;flex-direction:column;gap:8px;">
        `;

        perms.forEach(p => {
            let initialOpt = 'inherit';
            if (p.override === 1) initialOpt = 'grant';
            if (p.override === 0) initialOpt = 'deny';
            userOverridesMap[p.id] = initialOpt;

            const baseStatus = p.inherited
                ? `<span style="color:var(--success);font-size:11px;font-weight:600;"><i class="fa-solid fa-check"></i> Role Default: Allowed</span>`
                : `<span style="color:var(--text-muted);font-size:11px;"><i class="fa-solid fa-xmark"></i> Role Default: Denied</span>`;

            html += `
                <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-radius:10px;border:1px solid var(--border);background:var(--bg-input);">
                    <div>
                        <div style="font-weight:600;font-size:13px;">${esc(p.name)}</div>
                        <div style="margin-top:2px;">${baseStatus}</div>
                    </div>
                    <div class="override-segmented" data-pid="${p.id}" style="display:flex;background:rgba(255,255,255,.05);border-radius:8px;padding:3px;gap:3px;">
                        <button type="button" class="uo-btn ${initialOpt==='inherit'?'sel-inherit':''}" onclick="setOverrideState(${p.id}, 'inherit', this)" title="Inherit from role">Inherit</button>
                        <button type="button" class="uo-btn ${initialOpt==='grant'?'sel-grant':''}" onclick="setOverrideState(${p.id}, 'grant', this)" title="Force allow for this user">Grant</button>
                        <button type="button" class="uo-btn ${initialOpt==='deny'?'sel-deny':''}" onclick="setOverrideState(${p.id}, 'deny', this)" title="Force deny for this user">Deny</button>
                    </div>
                </div>
            `;
        });

        html += `</div></div>`;
    }

    body.innerHTML = html;
}

function setOverrideState(pid, state, btn) {
    userOverridesMap[pid] = state;
    const parent = btn.parentElement;
    parent.querySelectorAll('.uo-btn').forEach(b => b.className = 'uo-btn');
    if (state === 'inherit') btn.classList.add('sel-inherit');
    if (state === 'grant') btn.classList.add('sel-grant');
    if (state === 'deny') btn.classList.add('sel-deny');
}

function saveUserOverrides() {
    if (currentOverrideUserId <= 0) return;
    const saveBtn = document.getElementById('saveUserOverridesBtn');
    saveBtn.disabled = true;

    const fd = new FormData();
    fd.append('action', 'save_user_permissions');
    fd.append('csrf_token', CSRF);
    fd.append('user_id', currentOverrideUserId);

    for (const [pid, state] of Object.entries(userOverridesMap)) {
        fd.append(`overrides[${pid}]`, state);
    }

    fetch('manage_roles.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            saveBtn.disabled = false;
            if (res.success) {
                showToast(`Saved user overrides (${res.grants} granted, ${res.denies} denied)`, true);
                closeUserOverridesModal();
            } else {
                showToast(res.message || 'Failed to save user overrides', false);
            }
        })
        .catch(err => {
            saveBtn.disabled = false;
            showToast('Network error saving user permissions', false);
        });
}
</script>
</body>
</html>
