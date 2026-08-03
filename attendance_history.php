<?php
require 'auth.php';
require 'config.php';
if (!can('attendance')) { header("Location: dashboard.php?denied=1"); exit; }

// ── Resolve filters ──
$today        = date('Y-m-d');
$default_from = date('Y-m-d', strtotime('-30 days'));

$from = $_GET['from'] ?? $default_from;
$to   = $_GET['to']   ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $default_from;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $today;
if ($to < $from) { $tmp = $from; $from = $to; $to = $tmp; }

$emp = (int)($_GET['emp'] ?? 0);   // 0 = all

// ── Shared WHERE fragment + bind set (reused by count, rows, summary, CSV) ──
$where = "a.date BETWEEN ? AND ?";
$types = "ss";
$binds = [$from, $to];
if ($emp > 0) { $where .= " AND a.user_id = ?"; $types .= "i"; $binds[] = $emp; }

// ── URL helper: preserve current filters, override as needed ──
function qs(array $overrides = []): string {
    global $from, $to, $emp;
    $p = array_merge(['from' => $from, 'to' => $to, 'emp' => $emp ?: 'all'], $overrides);
    return 'attendance_history.php?' . http_build_query($p);
}

function format_attendance_hours(?float $hours): string {
    if ($hours === null) return '—';
    $val = (float)$hours;
    if ($val < 0.01) return '< 1m';
    if ($val < 0.1) return max(1, (int)round($val * 60)) . 'm';
    return number_format($val, 2) . 'h';
}

// ── CSV export (must run before any HTML output) ──
if (($_GET['export'] ?? '') === 'csv') {
    $csv_stmt = $conn->prepare(
        "SELECT a.date, COALESCE(e.name, a.username) AS emp_name, a.username,
                a.clock_in, a.clock_out, a.hours_worked
         FROM attendance a LEFT JOIN employees e ON e.user_id = a.user_id
         WHERE $where
         ORDER BY a.date DESC, a.clock_in ASC"
    );
    $csv_stmt->bind_param($types, ...$binds);
    $csv_stmt->execute();
    $csv_res = $csv_stmt->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"attendance_{$from}_to_{$to}.csv\"");
    $out = fopen('php://output', 'w');

    $csv_safe = function ($v) {
        $s = (string)$v;
        return ($s !== '' && strpbrk($s[0], "=+-@\t\r\n") !== false) ? "'" . $s : $s;
    };

    fputcsv($out, array_map($csv_safe, ['Date', 'Employee', 'Username', 'Clock In', 'Clock Out', 'Hours', 'Status']));
    while ($r = $csv_res->fetch_assoc()) {
        fputcsv($out, array_map($csv_safe, [
            $r['date'],
            $r['emp_name'],
            $r['username'],
            $r['clock_in'],
            $r['clock_out'] ?? '',
            is_null($r['hours_worked']) ? '' : format_attendance_hours((float)$r['hours_worked']),
            is_null($r['clock_out']) ? 'Active' : 'Complete',
        ]));
    }
    fclose($out);
    exit;
}

// ── Pagination math ──
$per_page = 10;
$page     = max(1, (int)($_GET['page'] ?? 1));

$cnt_stmt = $conn->prepare("SELECT COUNT(*) FROM attendance a WHERE $where");
$cnt_stmt->bind_param($types, ...$binds);
$cnt_stmt->execute();
$total_count = (int)$cnt_stmt->get_result()->fetch_row()[0];
$total_pages = $total_count > 0 ? (int)ceil($total_count / $per_page) : 1;
$page   = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

// ── Page rows ──
$rows_stmt = $conn->prepare(
    "SELECT a.*, e.name AS emp_name
     FROM attendance a LEFT JOIN employees e ON e.user_id = a.user_id
     WHERE $where
     ORDER BY a.date DESC, a.clock_in ASC
     LIMIT ? OFFSET ?"
);
$rt = $types . "ii";
$rb = array_merge($binds, [$per_page, $offset]);
$rows_stmt->bind_param($rt, ...$rb);
$rows_stmt->execute();
$records = $rows_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Range summary (full filtered range, not just page) ──
$sum_stmt = $conn->prepare(
    "SELECT COUNT(*)                      AS shifts,
            COALESCE(SUM(hours_worked),0) AS total_hours,
            COUNT(DISTINCT a.user_id)     AS staff,
            COALESCE(AVG(hours_worked),0) AS avg_hours
     FROM attendance a WHERE $where"
);
$sum_stmt->bind_param($types, ...$binds);
$sum_stmt->execute();
$summary = $sum_stmt->get_result()->fetch_assoc();

// ── Employee dropdown (roster, only those linked to a login) ──
$emp_list = $conn->query(
    "SELECT user_id, name FROM employees WHERE user_id IS NOT NULL ORDER BY name ASC"
)->fetch_all(MYSQLI_ASSOC);

// ── Quick-range presets + active detection ──
$presets = [
    'week'  => [date('Y-m-d', strtotime('monday this week')), $today],
    'month' => [date('Y-m-01'),                               $today],
    'm30'   => [$default_from,                                $today],
];
$active_preset = '';
foreach ($presets as $k => $range) {
    if ($from === $range[0] && $to === $range[1]) { $active_preset = $k; break; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance History | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --accent:       #d1904b;
    --accent-light: #e8b87a;
    --accent-dark:  #a0702a;
    --bg:           #0b0b0b;
    --card:         #111;
    --border:       rgba(255,255,255,0.07);
    --text:         #f5f5f5;
    --text-muted:   #777;
    --success:      #55e087;
    --warning:      #f39c12;
    --danger:       #e74c3c;
}
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Poppins',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; }

.topbar {
    position:sticky; top:0; z-index:100;
    background:rgba(10,10,10,.95); border-bottom:1px solid var(--border);
    backdrop-filter:blur(12px);
    display:flex; align-items:center; justify-content:space-between;
    padding:0 28px; height:62px;
}
.topbar-left { display:flex; align-items:center; gap:16px; }
.back-btn {
    display:inline-flex; align-items:center; gap:7px;
    text-decoration:none; color:#d1904b;
    font-size:13px; font-weight:600; padding:7px 14px;
    border-radius:10px; border:1px solid rgba(209,144,75,.35); background:rgba(209,144,75,.08); transition:all .2s;
}
.back-btn:hover { background:rgba(209,144,75,.16); border-color:#d1904b; }
.page-title { font-size:15px; font-weight:700; }
.page-title span { color:var(--accent); }

.page-wrap { max-width:900px; margin:0 auto; padding:32px 20px 60px; }

/* Date nav */
.date-nav {
    display:flex; align-items:center; gap:12px; margin-bottom:24px; flex-wrap:wrap;
}
.date-nav a {
    display:inline-flex; align-items:center; gap:6px;
    padding:7px 14px; border-radius:8px; border:1px solid var(--border);
    background:rgba(255,255,255,.03); color:var(--text-muted);
    text-decoration:none; font-size:13px; transition:all .2s;
}
.date-nav a:hover { color:var(--accent); border-color:rgba(209,144,75,.3); }
.date-input {
    padding:7px 14px; border-radius:8px; border:1px solid var(--border);
    background:rgba(255,255,255,.03); color:var(--text);
    font-size:13px; font-family:'Poppins',sans-serif; outline:none;
    transition:border-color .2s;
    color-scheme: dark; /* dark native dropdown + date-picker popups */
}
.date-input:focus { border-color:rgba(209,144,75,.45); }
.date-input option { background:#1a1a1a; color:#f5f5f5; }

/* ── Searchable employee dropdown ── */
.emp-dd { position:relative; }
.emp-dd-trigger { display:flex; align-items:center; justify-content:space-between; gap:10px; min-width:150px; width:100%; cursor:pointer; text-align:left; }
.emp-dd-trigger i { font-size:11px; color:var(--text-muted); transition:transform .2s; }
.emp-dd.open .emp-dd-trigger i { transform:rotate(180deg); }
.emp-dd.open .emp-dd-trigger { border-color:rgba(209,144,75,.45); }
.emp-dd-panel { position:absolute; top:calc(100% + 6px); left:0; z-index:60; width:240px; max-width:82vw; background:#161616; border:1px solid var(--border-hover,#333); border-radius:10px; box-shadow:0 14px 36px rgba(0,0,0,.55); padding:6px; display:none; }
.emp-dd.open .emp-dd-panel { display:block; animation:empIn .15s ease; }
@keyframes empIn { from{opacity:0;transform:translateY(-4px)} to{opacity:1;transform:none} }
.emp-dd-search { display:flex; align-items:center; gap:7px; padding:6px 9px; border:1px solid var(--border); border-radius:8px; background:rgba(255,255,255,.03); margin-bottom:6px; }
.emp-dd-search i { font-size:11px; color:var(--text-muted); flex-shrink:0; }
.emp-dd-search input { flex:1; min-width:0; background:transparent; border:none; outline:none; color:var(--text); font-size:13px; font-family:'Poppins',sans-serif; }
.emp-dd-list { max-height:240px; overflow-y:auto; }
.emp-dd-opt { padding:8px 11px; border-radius:7px; font-size:13px; color:var(--text); cursor:pointer; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.emp-dd-opt:hover { background:rgba(209,144,75,.12); color:var(--accent); }
.emp-dd-opt.sel { background:rgba(209,144,75,.16); color:var(--accent); font-weight:600; }
.emp-dd-empty { padding:12px; text-align:center; color:var(--text-muted); font-size:12px; }
.date-label {
    font-size:15px; font-weight:700;
    color:var(--text);
}

/* Summary cards */
.summary { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:24px; }
@media(max-width:600px) { .summary { grid-template-columns:1fr 1fr; } }
.scard {
    background:var(--card); border:1px solid var(--border);
    border-radius:14px; padding:18px 20px;
}
.scard-val { font-size:24px; font-weight:800; margin-bottom:4px; }
.scard-lbl { font-size:11px; color:var(--text-muted); font-weight:500; }
.scard.c-accent { border-color:rgba(209,144,75,.2); }
.scard.c-accent .scard-val { color:var(--accent); }
.scard.c-green  { border-color:rgba(85,224,135,.15); }
.scard.c-green  .scard-val { color:var(--success); }
.scard.c-orange { border-color:rgba(243,156,18,.15); }
.scard.c-orange .scard-val { color:var(--warning); }
.scard.c-blue   { border-color:rgba(91,192,222,.15); }
.scard.c-blue   .scard-val { color:#5bc0de; }

/* Table */
.card { background:var(--card); border:1px solid var(--border); border-radius:18px; overflow:hidden; }
.card-header {
    padding:18px 24px; border-bottom:1px solid var(--border);
    display:flex; align-items:center; gap:14px;
}
.card-icon {
    width:38px; height:38px; border-radius:10px; flex-shrink:0;
    background:linear-gradient(135deg,rgba(209,144,75,.2),rgba(209,144,75,.06));
    border:1px solid rgba(209,144,75,.2);
    display:flex; align-items:center; justify-content:center;
    color:var(--accent); font-size:15px;
}
.card-title { font-size:14px; font-weight:700; }
.card-sub   { font-size:12px; color:var(--text-muted); margin-top:2px; }

table { width:100%; border-collapse:collapse; }
thead th {
    padding:11px 20px; text-align:left;
    font-size:10.5px; font-weight:700; text-transform:uppercase;
    letter-spacing:.6px; color:var(--text-muted);
    border-bottom:1px solid var(--border);
    background:rgba(255,255,255,.02);
}
tbody tr { border-bottom:1px solid var(--border); transition:background .15s; }
tbody tr:last-child { border-bottom:none; }
tbody tr:hover { background:rgba(255,255,255,.02); }
tbody td { padding:13px 20px; font-size:13px; vertical-align:middle; }

.badge {
    display:inline-flex; align-items:center; gap:5px;
    padding:3px 10px; border-radius:20px; font-size:11px; font-weight:600;
}
.badge-working { background:rgba(85,224,135,.1); color:var(--success); border:1px solid rgba(85,224,135,.25); }
.badge-done    { background:rgba(255,255,255,.05); color:var(--text-muted); border:1px solid var(--border); }

.empty-state { padding:48px 20px; text-align:center; color:var(--text-muted); }
.empty-state i { font-size:40px; margin-bottom:14px; opacity:.25; display:block; }
.empty-state p { font-size:13px; }

.live-dot { width:7px; height:7px; border-radius:50%; background:var(--success); display:inline-block; animation:pulse 1.5s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.3} }

/* ── Entrance & motion ─────────────────────────────── */
@keyframes fadeUp    { from{opacity:0;transform:translateY(18px)} to{opacity:1;transform:translateY(0)} }
@keyframes slideDown { from{opacity:0;transform:translateY(-10px)} to{opacity:1;transform:translateY(0)} }

.topbar   { animation:slideDown .45s cubic-bezier(.22,1,.36,1) both; }
.date-nav { animation:fadeUp .45s cubic-bezier(.22,1,.36,1) both; animation-delay:.08s; }
.card     { animation:fadeUp .45s cubic-bezier(.22,1,.36,1) both; animation-delay:.38s; box-shadow:0 4px 32px rgba(0,0,0,.3); }

.scard {
    animation:fadeUp .4s cubic-bezier(.22,1,.36,1) both;
    transition:transform .22s ease, box-shadow .22s ease, border-color .22s ease;
}
.scard:nth-child(1) { animation-delay:.14s; }
.scard:nth-child(2) { animation-delay:.21s; }
.scard:nth-child(3) { animation-delay:.28s; }
.scard:nth-child(4) { animation-delay:.35s; }

.scard:hover { transform:translateY(-3px); }
.scard.c-accent:hover { box-shadow:0 8px 28px rgba(209,144,75,.18); border-color:rgba(209,144,75,.4); }
.scard.c-green:hover  { box-shadow:0 8px 28px rgba(85,224,135,.14); border-color:rgba(85,224,135,.35); }
.scard.c-blue:hover   { box-shadow:0 8px 28px rgba(91,192,222,.14); border-color:rgba(91,192,222,.35); }
.scard.c-orange:hover { box-shadow:0 8px 28px rgba(243,156,18,.14); border-color:rgba(243,156,18,.35); }

/* Staggered table row entrances */
#attTbody tr { animation:fadeUp .35s cubic-bezier(.22,1,.36,1) both; }
#attTbody tr:nth-child(1)  { animation-delay:.44s; }
#attTbody tr:nth-child(2)  { animation-delay:.48s; }
#attTbody tr:nth-child(3)  { animation-delay:.52s; }
#attTbody tr:nth-child(4)  { animation-delay:.56s; }
#attTbody tr:nth-child(5)  { animation-delay:.60s; }
#attTbody tr:nth-child(6)  { animation-delay:.64s; }
#attTbody tr:nth-child(7)  { animation-delay:.68s; }
#attTbody tr:nth-child(8)  { animation-delay:.72s; }
#attTbody tr:nth-child(9)  { animation-delay:.76s; }
#attTbody tr:nth-child(10) { animation-delay:.80s; }

/* Filters bar */
.filters-bar { display:flex; align-items:flex-end; gap:14px; flex-wrap:wrap; margin-bottom:22px; }
.fb-field { display:flex; flex-direction:column; gap:5px; }
.fb-label { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:var(--text-muted); }
.filters-bar .date-input { min-width:150px; }

/* Presets + CSV */
.preset-row { display:flex; gap:6px; flex-wrap:wrap; }
.preset-btn {
    display:inline-flex; align-items:center; height:38px; padding:0 14px;
    border-radius:8px; border:1px solid var(--border); background:rgba(255,255,255,.03);
    color:var(--text-muted); font-size:12.5px; font-weight:600; text-decoration:none; transition:all .2s;
}
.preset-btn:hover { color:var(--accent); border-color:rgba(209,144,75,.3); }
.preset-btn.active { background:var(--accent); border-color:var(--accent); color:#1a1410; }
.csv-btn {
    display:inline-flex; align-items:center; gap:7px; height:38px; padding:0 16px;
    border-radius:8px; border:1px solid rgba(85,224,135,.3); background:rgba(85,224,135,.08);
    color:var(--success); font-size:12.5px; font-weight:600; text-decoration:none; transition:all .2s;
}
.csv-btn:hover { background:rgba(85,224,135,.16); border-color:var(--success); }

/* Pagination */
.pg-wrap { padding:14px 18px; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
.pg-nav { display:flex; gap:4px; flex-wrap:wrap; }
.pg-btn { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text-muted); font-size:13px; font-weight:600; text-decoration:none; transition:all .2s; }
.pg-btn:hover { border-color:var(--accent); color:var(--accent); }
.pg-active { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; background:var(--accent); border:1px solid var(--accent); color:#000; font-size:13px; font-weight:700; }
.pg-disabled { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; border:1px solid var(--border); color:var(--text-muted); font-size:13px; opacity:.35; cursor:default; }
.pg-ellipsis { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; color:var(--text-muted); font-size:13px; }
.pg-info { font-size:12px; color:var(--text-muted); }

/* Table Column Sorting */
th.sortable { cursor: pointer; user-select: none; transition: color .15s ease; }
th.sortable:hover { color: var(--accent); }
th.sortable .sort-icon { font-size: 10px; margin-left: 6px; opacity: 0.35; transition: opacity .15s; }
th.sortable:hover .sort-icon { opacity: 0.75; }
th.sortable.asc .sort-icon,
th.sortable.desc .sort-icon { opacity: 1; color: var(--accent); }
</style>
</head>
<body>
<div class="topbar">
    <div class="topbar-left">
        <a href="attendance.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Attendance</a>
        <span class="page-title">Attendance <span>History</span></span>
    </div>
</div>
<div class="page-wrap">
    <form method="GET" class="filters-bar" id="filtersForm">
        <div class="fb-field">
            <label class="fb-label">From</label>
            <input type="date" name="from" class="date-input" value="<?= htmlspecialchars($from) ?>" max="<?= $today ?>" onchange="document.getElementById('filtersForm').submit()">
        </div>
        <div class="fb-field">
            <label class="fb-label">To</label>
            <input type="date" name="to" class="date-input" value="<?= htmlspecialchars($to) ?>" max="<?= $today ?>" onchange="document.getElementById('filtersForm').submit()">
        </div>
        <div class="fb-field">
            <label class="fb-label">Employee</label>
            <?php
                $emp_selected_name = 'All staff';
                foreach ($emp_list as $e) { if ($emp === (int)$e['user_id']) { $emp_selected_name = $e['name']; break; } }
            ?>
            <div class="emp-dd" id="empDD">
                <input type="hidden" name="emp" id="empInput" value="<?= $emp === 0 ? 'all' : (int)$emp ?>">
                <button type="button" class="date-input emp-dd-trigger" id="empTrigger" onclick="empToggle(event)">
                    <span id="empLabel"><?= htmlspecialchars($emp_selected_name) ?></span>
                    <i class="fa-solid fa-chevron-down"></i>
                </button>
                <div class="emp-dd-panel" id="empPanel">
                    <div class="emp-dd-search">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="empSearch" placeholder="Search staff…" autocomplete="off" oninput="empFilter(this.value)">
                    </div>
                    <div class="emp-dd-list" id="empList">
                        <div class="emp-dd-opt<?= $emp === 0 ? ' sel' : '' ?>" data-value="all" data-label="All staff" onclick="empPick(this)">All staff</div>
                        <?php foreach ($emp_list as $e): ?>
                        <div class="emp-dd-opt<?= $emp === (int)$e['user_id'] ? ' sel' : '' ?>" data-value="<?= (int)$e['user_id'] ?>" data-label="<?= htmlspecialchars($e['name']) ?>" onclick="empPick(this)"><?= htmlspecialchars($e['name']) ?></div>
                        <?php endforeach; ?>
                        <div class="emp-dd-empty" id="empEmpty" style="display:none">No staff found</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="fb-field">
            <label class="fb-label">Quick range</label>
            <div class="preset-row">
                <a href="<?= htmlspecialchars(qs(['from'=>$presets['week'][0],  'to'=>$presets['week'][1],  'page'=>1])) ?>"  class="preset-btn<?= $active_preset==='week'  ? ' active' : '' ?>">This week</a>
                <a href="<?= htmlspecialchars(qs(['from'=>$presets['month'][0], 'to'=>$presets['month'][1], 'page'=>1])) ?>" class="preset-btn<?= $active_preset==='month' ? ' active' : '' ?>">This month</a>
                <a href="<?= htmlspecialchars(qs(['from'=>$presets['m30'][0],   'to'=>$presets['m30'][1],   'page'=>1])) ?>"   class="preset-btn<?= $active_preset==='m30'   ? ' active' : '' ?>">Last 30 days</a>
            </div>
        </div>
        <div class="fb-field" style="margin-left:auto">
            <label class="fb-label">&nbsp;</label>
            <a href="<?= htmlspecialchars(qs(['export'=>'csv'])) ?>" class="csv-btn"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
        </div>
    </form>

    <!-- Summary -->
    <div class="summary">
        <div class="scard c-accent">
            <div class="scard-val"><?= (int)$summary['shifts'] ?></div>
            <div class="scard-lbl"><i class="fa-solid fa-calendar-check"></i> Total Shifts</div>
        </div>
        <div class="scard c-orange">
            <div class="scard-val"><?= number_format((float)$summary['total_hours'], 1) ?>h</div>
            <div class="scard-lbl"><i class="fa-solid fa-clock"></i> Total Hours</div>
        </div>
        <div class="scard c-green">
            <div class="scard-val"><?= (int)$summary['staff'] ?></div>
            <div class="scard-lbl"><i class="fa-solid fa-users"></i> Staff</div>
        </div>
        <div class="scard c-blue">
            <div class="scard-val"><?= number_format((float)$summary['avg_hours'], 2) ?>h</div>
            <div class="scard-lbl"><i class="fa-solid fa-chart-simple"></i> Avg / Shift</div>
        </div>
    </div>

    <!-- Table -->
    <div class="card">
        <div class="card-header">
            <div class="card-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
            <div>
                <div class="card-title">Attendance History</div>
                <div class="card-sub"><?= htmlspecialchars($from) ?> → <?= htmlspecialchars($to) ?></div>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th class="sortable" onclick="sortTable(0, 'date')">Date <i class="fa-solid fa-sort sort-icon"></i></th>
                    <th class="sortable" onclick="sortTable(1, 'text')">Employee <i class="fa-solid fa-sort sort-icon"></i></th>
                    <th class="sortable" onclick="sortTable(2, 'time')">Clock In <i class="fa-solid fa-sort sort-icon"></i></th>
                    <th class="sortable" onclick="sortTable(3, 'time')">Clock Out <i class="fa-solid fa-sort sort-icon"></i></th>
                    <th class="sortable" onclick="sortTable(4, 'number')">Hours <i class="fa-solid fa-sort sort-icon"></i></th>
                    <th class="sortable" onclick="sortTable(5, 'text')">Status <i class="fa-solid fa-sort sort-icon"></i></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($records)): ?>
            <tr><td colspan="6" style="padding:40px;text-align:center;color:var(--text-muted)">
                <i class="fa-solid fa-calendar-xmark" style="display:block;font-size:32px;margin-bottom:12px;opacity:.25"></i>
                No attendance records in this range.
            </td></tr>
            <?php else: foreach ($records as $r):
                $name    = $r['emp_name'] ?: $r['username'];
                $working = is_null($r['clock_out']);
            ?>
            <tr>
                <td><?= date('d M Y', strtotime($r['date'])) ?></td>
                <td>
                    <div style="font-weight:600"><?= htmlspecialchars($name) ?></div>
                    <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($r['username']) ?></div>
                </td>
                <td><?= date('g:i A', strtotime($r['clock_in'])) ?></td>
                <td><?= $working ? '<span style="color:var(--text-muted)">—</span>' : date('g:i A', strtotime($r['clock_out'])) ?></td>
                <td><?= $working ? '<span style="color:var(--text-muted)">—</span>' : format_attendance_hours((float)$r['hours_worked']) ?></td>
                <td>
                    <?php if ($working): ?>
                    <span class="badge badge-working"><span class="live-dot" style="width:6px;height:6px"></span> Active</span>
                    <?php else: ?>
                    <span class="badge badge-done"><i class="fa-solid fa-circle-check"></i> Complete</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php if ($total_pages > 1 || $total_count > 0): ?>
        <div class="pg-wrap">
            <span class="pg-info">
                <?php $rng_start = $total_count ? $offset + 1 : 0; $rng_end = $offset + count($records); ?>
                <?= $rng_start ?>–<?= $rng_end ?> of <?= number_format($total_count) ?> results
            </span>
            <?php if ($total_pages > 1): ?>
            <nav class="pg-nav">
                <?php if ($page > 1): ?>
                <a href="<?= htmlspecialchars(qs(['page'=>1])) ?>" class="pg-btn">«</a>
                <a href="<?= htmlspecialchars(qs(['page'=>$page-1])) ?>" class="pg-btn">‹</a>
                <?php else: ?>
                <span class="pg-disabled">«</span><span class="pg-disabled">‹</span>
                <?php endif; ?>
                <?php
                $w_start = max(1, $page - 2);
                $w_end   = min($total_pages, $page + 2);
                if ($w_start > 1): ?><span class="pg-ellipsis">…</span><?php endif;
                for ($pg_i = $w_start; $pg_i <= $w_end; $pg_i++): ?>
                    <?php if ($pg_i === $page): ?>
                    <span class="pg-active"><?= $pg_i ?></span>
                    <?php else: ?>
                    <a href="<?= htmlspecialchars(qs(['page'=>$pg_i])) ?>" class="pg-btn"><?= $pg_i ?></a>
                    <?php endif; ?>
                <?php endfor;
                if ($w_end < $total_pages): ?><span class="pg-ellipsis">…</span><?php endif; ?>
                <?php if ($page < $total_pages): ?>
                <a href="<?= htmlspecialchars(qs(['page'=>$page+1])) ?>" class="pg-btn">›</a>
                <a href="<?= htmlspecialchars(qs(['page'=>$total_pages])) ?>" class="pg-btn">»</a>
                <?php else: ?>
                <span class="pg-disabled">›</span><span class="pg-disabled">»</span>
                <?php endif; ?>
            </nav>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

</div>
<script>
function empToggle(e) {
    e.stopPropagation();
    const dd = document.getElementById('empDD');
    const wasOpen = dd.classList.toggle('open');
    if (wasOpen) {
        const s = document.getElementById('empSearch');
        s.value = ''; empFilter('');
        setTimeout(() => s.focus(), 30);
    }
}
function empFilter(q) {
    q = q.toLowerCase().trim();
    let any = false;
    document.querySelectorAll('#empList .emp-dd-opt').forEach(o => {
        const m = o.dataset.label.toLowerCase().includes(q);
        o.style.display = m ? '' : 'none';
        if (m) any = true;
    });
    document.getElementById('empEmpty').style.display = any ? 'none' : 'block';
}
function empPick(el) {
    document.getElementById('empInput').value = el.dataset.value;
    document.getElementById('empLabel').textContent = el.dataset.label;
    document.getElementById('filtersForm').submit();
}
document.addEventListener('click', e => {
    const dd = document.getElementById('empDD');
    if (dd && !dd.contains(e.target)) dd.classList.remove('open');
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.getElementById('empDD')?.classList.remove('open');
});

// ── Client-side Table Column Sorting ──
let sortState = { col: -1, dir: 'asc' };

function parseCellVal(tr, colIdx, type) {
    const td = tr.children[colIdx];
    if (!td) return '';
    const text = td.innerText.trim();
    if (type === 'time') {
        if (text === '—' || !text) return 0;
        const dummyDate = '2026-01-01';
        const parsed = Date.parse(`${dummyDate} ${text}`);
        return isNaN(parsed) ? text : parsed;
    }
    if (type === 'number') {
        if (text === '—' || !text) return -1;
        if (text.startsWith('<')) return 0.001;
        if (text.endsWith('m')) return parseFloat(text) / 60;
        return parseFloat(text) || 0;
    }
    if (type === 'date') {
        const parsed = Date.parse(text);
        return isNaN(parsed) ? text : parsed;
    }
    return text.toLowerCase();
}

function sortTable(colIdx, type) {
    const tbody = document.querySelector('table tbody');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr'));
    if (rows.length <= 1) return;

    let dir = 'asc';
    if (sortState.col === colIdx && sortState.dir === 'asc') {
        dir = 'desc';
    }
    sortState = { col: colIdx, dir };

    document.querySelectorAll('th.sortable').forEach((th, idx) => {
        th.classList.remove('asc', 'desc');
        const icon = th.querySelector('.sort-icon');
        if (icon) {
            if (idx === colIdx) {
                th.classList.add(dir);
                icon.className = `fa-solid fa-sort-${dir === 'asc' ? 'up' : 'down'} sort-icon`;
            } else {
                icon.className = 'fa-solid fa-sort sort-icon';
            }
        }
    });

    rows.sort((a, b) => {
        const vA = parseCellVal(a, colIdx, type);
        const vB = parseCellVal(b, colIdx, type);
        if (vA < vB) return dir === 'asc' ? -1 : 1;
        if (vA > vB) return dir === 'asc' ? 1 : -1;
        return 0;
    });

    rows.forEach(r => tbody.appendChild(r));
}
</script>
</body>
</html>
