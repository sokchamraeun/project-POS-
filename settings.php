<?php
require 'auth.php';
require 'config.php';
if (!can('promotions')) { header("Location: dashboard.php?denied=1"); exit; }

$message = '';
$message_type = '';

// ── SAVE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = $_POST['_section'] ?? '';

    $fields = match($section) {
        'happy_hour' => [
            'happy_hour_enabled'    => isset($_POST['happy_hour_enabled']) ? '1' : '0',
            'happy_hour_start'      => (string)max(0, min(23, (int)($_POST['happy_hour_start']    ?? 14))),
            'happy_hour_end'        => (string)max(0, min(23, (int)($_POST['happy_hour_end']      ?? 16))),
            'happy_hour_discount'   => (string)max(1, min(100, (int)($_POST['happy_hour_discount'] ?? 20))),
            'happy_hour_start_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['happy_hour_start_date'] ?? '') ? $_POST['happy_hour_start_date'] : '',
            'happy_hour_end_date'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['happy_hour_end_date']   ?? '') ? $_POST['happy_hour_end_date']   : '',
        ],
        'free_drink' => [
            'buy_x_get_1_enabled'  => isset($_POST['buy_x_get_1_enabled']) ? '1' : '0',
            'buy_x_count'          => (string)max(2, min(20, (int)($_POST['buy_x_count']        ?? 3))),
            'free_item_product_id' => (string)max(0, (int)($_POST['free_item_product_id']       ?? 0)),
            'buy_x_start_date'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['buy_x_start_date'] ?? '') ? $_POST['buy_x_start_date'] : '',
            'buy_x_end_date'       => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['buy_x_end_date']   ?? '') ? $_POST['buy_x_end_date']   : '',
        ],
        'currency' => [
            'khr_exchange_rate' => (string)max(100, min(99999, (int)($_POST['khr_exchange_rate'] ?? 4100))),
        ],
        'loyalty' => [
            'loyalty_mode'          => in_array($_POST['loyalty_mode'] ?? 'drinks', ['drinks', 'spend']) ? $_POST['loyalty_mode'] : 'drinks',
            'loyalty_points_per'    => (string)max(1, min(100, (int)($_POST['loyalty_points_per']    ?? 1))),
            'loyalty_points_drinks' => (string)max(1, min(100, (int)($_POST['loyalty_points_drinks'] ?? 1))),
        ],
        'tax' => [
            'tax_rate' => (string)max(0, min(100, round((float)($_POST['tax_rate'] ?? 10), 2))),
        ],
        'target' => [
            'daily_sales_target' => (string)max(0, round((float)($_POST['daily_sales_target'] ?? 500), 2)),
        ],
        'stands' => [
            'stand_count' => (string)max(1, min(100, (int)($_POST['stand_count'] ?? 20))),
        ],
        'barista' => [
            'overdue_minutes' => (string)max(1, min(120, (int)($_POST['overdue_minutes'] ?? 10))),
        ],
        'paylater' => [
            'paylater_followup_minutes' => (string)max(5, min(240, (int)($_POST['paylater_followup_minutes'] ?? 45))),
        ],
        default => [],
    };

    if (!empty($fields)) {
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $ok = true;
        foreach ($fields as $k => $v) {
            $stmt->bind_param('ss', $k, $v);
            if (!$stmt->execute()) { $ok = false; break; }
        }
        if ($ok) {
            header("Location: settings.php?saved=" . urlencode($section));
            exit;
        }
        $message      = 'Error saving settings: ' . $conn->error;
        $message_type = 'error';
        $saved_section = $section;
    }
}

// ── Show flash from redirect ──
if (isset($_GET['saved'])) {
    $message       = 'Settings saved successfully.';
    $message_type  = 'success';
    $saved_section = $_GET['saved'];
}

// ── LOAD CURRENT VALUES ──
$s = [];
$res = $conn->query("SELECT setting_key, setting_value FROM settings");
if ($res) { while ($r = $res->fetch_assoc()) $s[$r['setting_key']] = $r['setting_value']; }

$hh_enabled      = (bool)(int)($s['happy_hour_enabled']  ?? 1);
$hh_start        = (int)($s['happy_hour_start']    ?? 14);
$hh_end          = (int)($s['happy_hour_end']      ?? 16);
$hh_discount     = (int)($s['happy_hour_discount'] ?? 20);
$hh_start_date   = $s['happy_hour_start_date'] ?? '';
$hh_end_date     = $s['happy_hour_end_date']   ?? '';
$bx_enabled      = (bool)(int)($s['buy_x_get_1_enabled'] ?? 1);
$bx_count        = (int)($s['buy_x_count']         ?? 3);
$free_item_pid   = (int)($s['free_item_product_id'] ?? 0);
$bx_start_date   = $s['buy_x_start_date'] ?? '';
$bx_end_date     = $s['buy_x_end_date']   ?? '';
$khr_rate        = (int)($s['khr_exchange_rate']   ?? 4100);
$loy_mode        = $s['loyalty_mode']              ?? 'drinks';
$loy_per         = (int)($s['loyalty_points_per']    ?? 1);
$loy_drinks      = (int)($s['loyalty_points_drinks'] ?? 1);
$tax_rate        = (float)($s['tax_rate']          ?? 10);
$daily_target    = (float)($s['daily_sales_target'] ?? 500);
$stand_count     = (int)($s['stand_count'] ?? 20);
$overdue_minutes = (int)($s['overdue_minutes']     ?? 10);
$paylater_followup = (int)($s['paylater_followup_minutes'] ?? 45);

$_today      = date('Y-m-d');
$hh_expired  = $hh_end_date !== '' && $_today > $hh_end_date;
$hh_upcoming = $hh_start_date !== '' && $_today < $hh_start_date;
$bx_expired  = $bx_end_date !== '' && $_today > $bx_end_date;
$bx_upcoming = $bx_start_date !== '' && $_today < $bx_start_date;

$products_list = [];
$_pr = $conn->query("SELECT product_id, name FROM products WHERE is_available = 1 ORDER BY name");
if ($_pr) while ($row = $_pr->fetch_assoc()) $products_list[] = $row;

function fmt_hour(int $h): string {
    if ($h === 0)  return '12:00 AM';
    if ($h < 12)  return $h . ':00 AM';
    if ($h === 12) return '12:00 PM';
    return ($h - 12) . ':00 PM';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Promotion Settings | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script>(function(){try{if(localStorage.getItem('theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}})();</script>
<style>
:root {
    --accent: #d1904b;
    --accent-light: #e8b87a;
    --accent-dark: #a0702a;
    --bg: #080808;
    --bg-card: #0f0f0f;
    --border: #1e1e1e;
    --border-hover: #2e2e2e;
    --text: #f0f0f0;
    --text-muted: #707070;
    --success: #4ade80;
    --danger: #f87171;
    --warning: #fbbf24;
    --shadow-lg: 0 20px 60px rgba(0,0,0,0.6);
    --shadow-accent: 0 8px 32px rgba(209,144,75,0.25);
    --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
    --glow: 0 0 40px rgba(209,144,75,0.08);
}
/* ── Light theme (follows shared localStorage 'theme'; palette matches dashboard) ── */
[data-theme="light"] {
    --bg: #ECEEF2;
    --bg-card: #FFFFFF;
    --border: #E2E5EA;
    --border-hover: #CDD0D8;
    --text: #111827;
    --text-muted: #5A6373;
    --shadow-lg: 0 4px 20px rgba(0,0,0,.09), 0 1px 4px rgba(0,0,0,.05);
    --shadow-accent: 0 6px 22px rgba(209,144,75,0.18);
    --glow: none;
}
/* gradient title would clip white-on-white in light mode */
[data-theme="light"] .hero h1 {
    background: linear-gradient(135deg, #1f2937 25%, var(--accent-dark) 115%);
    -webkit-background-clip: text; background-clip: text;
}
/* native dropdown options need a light surface */
[data-theme="light"] .field select option { background: #FFFFFF; }
/* hardcoded dark surfaces → light equivalents */
[data-theme="light"] .card {
    background: var(--bg-card);
    border-color: var(--border);
    box-shadow: var(--shadow-lg);
}
[data-theme="light"] .toggle-row,
[data-theme="light"] .field input,
[data-theme="light"] .field select,
[data-theme="light"] .field textarea {
    background: #F5F7FA;
    border-color: var(--border);
}
[data-theme="light"] .field input:focus,
[data-theme="light"] .field select:focus,
[data-theme="light"] .field textarea:focus { background: #FFFFFF; }
[data-theme="light"] .btn-cancel { background: #F5F7FA; }
[data-theme="light"] .btn-cancel:hover { background: #ECEEF2; }

*{box-sizing:border-box;margin:0;padding:0;}
html{height:100%;}

body {
    font-family: 'Poppins', sans-serif;
    background: var(--bg);
    min-height: 100vh;
    color: var(--text);
    padding: 100px 20px 80px;
    overflow-x: hidden;
}

/* ── Ambient blobs ── */
body::before, body::after {
    content: '';
    position: fixed;
    border-radius: 50%;
    filter: blur(120px);
    pointer-events: none;
    z-index: 0;
}
body::before {
    width: 500px; height: 500px;
    top: -100px; left: -100px;
    background: radial-gradient(circle, rgba(209,144,75,0.07) 0%, transparent 70%);
}
body::after {
    width: 400px; height: 400px;
    bottom: -80px; right: -80px;
    background: radial-gradient(circle, rgba(209,144,75,0.05) 0%, transparent 70%);
}

::-webkit-scrollbar{width:5px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:rgba(209,144,75,0.4);border-radius:10px;}

/* ── Back btn ── */
.back-btn {
    position: fixed;
    top: 22px; left: 22px;
    display: flex; align-items: center; gap: 7px;
    color: #d1904b;
    text-decoration: none;
    font-weight: 600; font-size: 13px;
    padding: 9px 16px;
    border-radius: 12px;
    background: rgba(209,144,75,.08);
    backdrop-filter: blur(16px);
    border: 1px solid rgba(209,144,75,.35);
    transition: var(--transition);
    z-index: 100;
}
.back-btn:hover { color: var(--accent); transform: translateX(-3px); border-color: rgba(209,144,75,0.3); box-shadow: 0 0 20px rgba(209,144,75,0.08); }

/* ── Wrapper ── */
.wrapper {
    max-width: 700px;
    margin: 0 auto;
    position: relative;
    z-index: 1;
}

/* ── Hero header ── */
.hero {
    text-align: center;
    margin-bottom: 40px;
}
.hero-icon {
    width: 64px; height: 64px;
    border-radius: 20px;
    background: linear-gradient(135deg, rgba(209,144,75,0.2), rgba(209,144,75,0.05));
    border: 1px solid rgba(209,144,75,0.25);
    display: flex; align-items: center; justify-content: center;
    font-size: 26px; color: var(--accent);
    margin: 0 auto 18px;
    box-shadow: 0 0 40px rgba(209,144,75,0.15), inset 0 1px 0 rgba(255,255,255,0.06);
}
.hero h1 {
    font-size: 26px; font-weight: 700;
    background: linear-gradient(135deg, #fff 30%, var(--accent-light) 100%);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    background-clip: text;
    margin-bottom: 6px;
    letter-spacing: -0.3px;
}
.hero p {
    font-size: 13px; color: var(--text-muted);
    max-width: 380px; margin: 0 auto;
    line-height: 1.6;
}

/* ── Message ── */
.msg {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 18px;
    border-radius: 14px;
    font-size: 13.5px; font-weight: 500;
    margin-bottom: 24px;
    animation: slideDown 0.35s cubic-bezier(0.34,1.56,0.64,1);
    backdrop-filter: blur(10px);
}
@keyframes slideDown { from{opacity:0;transform:translateY(-12px) scale(0.98);} to{opacity:1;transform:translateY(0) scale(1);} }
.msg.success { background: rgba(74,222,128,0.07); border: 1px solid rgba(74,222,128,0.2); color: var(--success); }
.msg.error   { background: rgba(248,113,113,0.07); border: 1px solid rgba(248,113,113,0.2); color: var(--danger); }

/* ── Card ── */
.card {
    background: linear-gradient(145deg, rgba(20,20,20,0.95), rgba(12,12,12,0.98));
    backdrop-filter: blur(24px);
    border-radius: 24px;
    padding: 0;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-lg), var(--glow);
    margin-bottom: 20px;
    animation: slideUp 0.45s cubic-bezier(0.34,1.2,0.64,1) both;
    overflow: hidden;
    transition: border-color 0.3s, box-shadow 0.3s;
    position: relative;
}
.card:hover {
    border-color: rgba(209,144,75,0.2);
    box-shadow: var(--shadow-lg), 0 0 60px rgba(209,144,75,0.06);
}
.card:nth-child(1) { animation-delay: 0.05s; }
.card:nth-child(2) { animation-delay: 0.12s; }
.card:nth-child(3) { animation-delay: 0.19s; }

/* Top accent line */
.card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(209,144,75,0.5) 50%, transparent);
    opacity: 0;
    transition: opacity 0.3s;
}
.card:hover::before { opacity: 1; }

@keyframes slideUp { from{opacity:0;transform:translateY(28px);} to{opacity:1;transform:translateY(0);} }

.card-inner { padding: 28px 32px 32px; }

.card-header {
    display: flex; align-items: center; gap: 16px;
    padding: 24px 32px 20px;
    border-bottom: 1px solid var(--border);
    background: rgba(255,255,255,0.01);
}
.card-icon {
    width: 48px; height: 48px;
    border-radius: 16px;
    background: linear-gradient(135deg, rgba(209,144,75,0.18), rgba(209,144,75,0.05));
    border: 1px solid rgba(209,144,75,0.22);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; color: var(--accent);
    flex-shrink: 0;
    box-shadow: 0 4px 16px rgba(209,144,75,0.12), inset 0 1px 0 rgba(255,255,255,0.06);
}
.card-title { font-size: 16px; font-weight: 700; letter-spacing: -0.2px; }
.card-sub   { font-size: 12px; color: var(--text-muted); margin-top: 3px; line-height: 1.5; }

/* ── Section label ── */
.section-label {
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 1.2px;
    color: var(--text-muted);
    margin-bottom: 12px;
    margin-top: 22px;
    display: flex; align-items: center; gap: 8px;
}
.section-label::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
}

/* ── Toggle ── */
.toggle-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 18px;
    background: rgba(255,255,255,0.025);
    border: 1px solid var(--border);
    border-radius: 16px;
    margin-top: 20px;
    transition: border-color 0.25s, background 0.25s;
}
.toggle-row:has(input:checked) {
    border-color: rgba(209,144,75,0.2);
    background: rgba(209,144,75,0.03);
}
.toggle-label { font-size: 14px; font-weight: 600; }
.toggle-sub   { font-size: 12px; color: var(--text-muted); margin-top: 3px; }

.switch {
    position: relative; width: 52px; height: 28px; flex-shrink: 0;
}
.switch input { opacity: 0; width: 0; height: 0; }
.slider {
    position: absolute; inset: 0;
    background: rgba(255,255,255,0.08);
    border: 1px solid var(--border);
    border-radius: 28px;
    cursor: pointer;
    transition: var(--transition);
}
.slider::before {
    content: '';
    position: absolute;
    width: 22px; height: 22px;
    left: 2px; top: 2px;
    background: #888;
    border-radius: 50%;
    transition: var(--transition);
    box-shadow: 0 2px 6px rgba(0,0,0,0.4);
}
.switch input:checked + .slider {
    background: linear-gradient(135deg, var(--accent), var(--accent-light));
    border-color: var(--accent);
    box-shadow: 0 0 14px rgba(209,144,75,0.35);
}
.switch input:checked + .slider::before {
    transform: translateX(24px);
    background: #fff;
}

/* ── Fields ── */
.fields-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-top: 22px;
}
.fields-grid.single { grid-template-columns: 1fr; }
.fields-grid.triple { grid-template-columns: 1fr 1fr 1fr; }

.field { display: flex; flex-direction: column; gap: 7px; }

.field label {
    font-size: 10.5px; font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.8px;
    display: flex; align-items: center; gap: 6px;
}
.field label i { color: var(--accent); font-size: 10px; }

.field input[type="number"],
.field input[type="date"],
.field select {
    width: 100%;
    padding: 11px 15px;
    background: rgba(255,255,255,0.035);
    border: 1px solid var(--border);
    border-radius: 12px;
    color: var(--text);
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    outline: none;
    transition: var(--transition);
    color-scheme: dark;
    appearance: none;
    -webkit-appearance: none;
}
.field select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    padding-right: 38px;
}
.field input:focus,
.field select:focus {
    border-color: rgba(209,144,75,0.5);
    box-shadow: 0 0 0 3px rgba(209,144,75,0.08), 0 0 20px rgba(209,144,75,0.05);
    background: rgba(255,255,255,0.055);
}
.field select option { background: #141414; }

.field-hint {
    font-size: 11px; color: var(--text-muted);
    line-height: 1.5;
}

/* ── Divider ── */
.divider {
    height: 1px;
    background: linear-gradient(90deg, transparent, var(--border) 30%, var(--border) 70%, transparent);
    margin: 24px 0;
}

/* ── Preview badge ── */
.preview-row {
    margin-top: 20px;
    padding: 14px 18px;
    background: linear-gradient(135deg, rgba(209,144,75,0.07), rgba(209,144,75,0.03));
    border: 1px solid rgba(209,144,75,0.18);
    border-radius: 14px;
    font-size: 13px;
    color: var(--accent-light);
    display: flex; align-items: flex-start; gap: 10px;
    line-height: 1.55;
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.04);
}
.preview-row i { font-size: 14px; margin-top: 1px; flex-shrink: 0; }

/* ── Status chips ── */
.chip {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 10.5px; font-weight: 700;
    padding: 4px 11px;
    border-radius: 20px;
    letter-spacing: 0.3px;
    flex-shrink: 0;
}
.chip::before { content: ''; width: 5px; height: 5px; border-radius: 50%; }
.chip-active  { background: rgba(74,222,128,0.1);  color: var(--success); border: 1px solid rgba(74,222,128,0.25); }
.chip-active::before  { background: var(--success); box-shadow: 0 0 5px var(--success); animation: chipPulse 2s infinite; }
.chip-expired { background: rgba(248,113,113,0.1); color: var(--danger);  border: 1px solid rgba(248,113,113,0.25); }
.chip-expired::before { background: var(--danger); }
.chip-upcoming{ background: rgba(251,191,36,0.1);  color: var(--warning); border: 1px solid rgba(251,191,36,0.25); }
.chip-upcoming::before{ background: var(--warning); box-shadow: 0 0 5px var(--warning); animation: chipPulse 1.5s infinite; }
@keyframes chipPulse { 0%,100%{opacity:1;} 50%{opacity:0.4;} }

/* ── Actions ── */
.form-actions {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 20px 32px;
    border-top: 1px solid var(--border);
    background: rgba(255,255,255,0.01);
}
.form-actions-info {
    display: flex; align-items: center; gap: 8px;
    font-size: 12px; color: var(--text-muted);
    font-weight: 500;
}
.form-actions-info i { color: rgba(209,144,75,0.6); font-size: 12px; }

.btn {
    padding: 12px 26px;
    border-radius: 14px;
    font-size: 14px; font-weight: 600;
    font-family: 'Poppins', sans-serif;
    border: 1px solid transparent;
    cursor: pointer;
    display: flex; align-items: center; gap: 8px;
    text-decoration: none;
    transition: var(--transition);
    position: relative;
    overflow: hidden;
}
.btn-save {
    background: linear-gradient(135deg, var(--accent) 0%, var(--accent-light) 100%);
    color: #000;
    box-shadow: var(--shadow-accent);
}
.btn-save::after {
    content: '';
    position: absolute;
    top: 0; left: -100%;
    width: 60%; height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.18), transparent);
    transition: left 0.5s ease;
}
.btn-save:hover::after { left: 140%; }
.btn-save:hover { transform: translateY(-2px); box-shadow: 0 12px 40px rgba(209,144,75,0.35); }
.btn-save:active { transform: translateY(0); }
.btn-cancel {
    border-color: var(--border);
    color: var(--text-muted);
    background: rgba(255,255,255,0.03);
}
.btn-cancel:hover { border-color: var(--border-hover); color: var(--text); background: rgba(255,255,255,0.05); }

/* ── Disabled overlay ── */
.fields-wrapper { transition: opacity 0.3s; }
.fields-wrapper.dimmed { opacity: 0.28; pointer-events: none; filter: blur(0.5px); }

/* ── Date separator ── */
.date-range-row {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    gap: 12px;
    align-items: center;
    margin-top: 16px;
}
.date-arrow {
    color: var(--text-muted);
    font-size: 13px;
    text-align: center;
    padding-top: 22px;
}

@media (max-width: 640px) {
    body { padding: 80px 14px 60px; }
    .card-header, .card-inner, .form-actions { padding-left: 20px; padding-right: 20px; }
    .fields-grid, .fields-grid.triple { grid-template-columns: 1fr; }
    .date-range-row { grid-template-columns: 1fr; }
    .date-arrow { display: none; }
    .hero h1 { font-size: 22px; }
}
</style>
</head>
<body>

<a href="dashboard.php" class="back-btn">
    <i class="fa-solid fa-arrow-left"></i> Dashboard
</a>

<div class="wrapper">

    <div class="hero">
        <div class="hero-icon"><i class="fa-solid fa-sliders"></i></div>
        <h1>Promotion Settings</h1>
        <p>Configure time-based discounts, free drink deals, and currency settings for your café.</p>
    </div>

    <?php if ($message): ?>
    <?php
        $section_label = match($saved_section ?? '') {
            'happy_hour' => 'Happy Hour',
            'free_drink' => 'Free Drink Promotion',
            'currency'   => 'Currency Settings',
            'tax'        => 'Tax Settings',
            'target'     => 'Sales Target',
            'stands'     => 'Stand Numbers',
            'barista'    => 'Barista Station',
            'paylater'   => 'Pay Later',
            default      => 'Settings',
        };
    ?>
    <div class="msg <?= htmlspecialchars($message_type) ?>">
        <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
        <strong><?= $section_label ?>:</strong>&nbsp;<?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>

    <!-- ── HAPPY HOUR ── -->
    <form method="POST">
        <div class="card">
            <div class="card-header">
                <div class="card-icon"><i class="fa-solid fa-sun"></i></div>
                <div style="flex:1;">
                    <div class="card-title">Happy Hour</div>
                    <div class="card-sub">Automatically discount orders placed during a time window</div>
                </div>
                <?php if ($hh_expired): ?>
                    <span class="chip chip-expired"><i class="fa-solid fa-ban" style="font-size:9px;"></i> Expired</span>
                <?php elseif ($hh_upcoming): ?>
                    <span class="chip chip-upcoming"><i class="fa-solid fa-clock" style="font-size:9px;"></i> Upcoming</span>
                <?php elseif ($hh_enabled && ($hh_start_date !== '' || $hh_end_date !== '')): ?>
                    <span class="chip chip-active">Active</span>
                <?php endif; ?>
            </div>

            <div class="card-inner">
                <div class="toggle-row">
                    <div>
                        <div class="toggle-label">Enable Happy Hour</div>
                        <div class="toggle-sub">Toggle off to pause the time-based discount</div>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="happy_hour_enabled" id="hhToggle" <?= $hh_enabled ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="fields-wrapper" id="hhFields">
                    <div class="section-label"><i class="fa-solid fa-clock" style="color:var(--accent);font-size:10px;"></i> Time Window & Discount</div>
                    <div class="fields-grid triple">
                        <div class="field">
                            <label><i class="fa-solid fa-clock"></i> Start Time</label>
                            <select name="happy_hour_start" id="hhStart">
                                <?php for ($h = 0; $h < 24; $h++): ?>
                                <option value="<?= $h ?>" <?= $h === $hh_start ? 'selected' : '' ?>><?= fmt_hour($h) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label><i class="fa-solid fa-clock"></i> End Time</label>
                            <select name="happy_hour_end" id="hhEnd">
                                <?php for ($h = 0; $h < 24; $h++): ?>
                                <option value="<?= $h ?>" <?= $h === $hh_end ? 'selected' : '' ?>><?= fmt_hour($h) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label><i class="fa-solid fa-percent"></i> Discount %</label>
                            <input type="number" name="happy_hour_discount" id="hhDiscount"
                                   value="<?= $hh_discount ?>" min="1" max="100">
                            <span class="field-hint">Off the subtotal (1–100)</span>
                        </div>
                    </div>

                    <div class="section-label" style="margin-top:24px;"><i class="fa-solid fa-calendar-range" style="color:var(--accent);font-size:10px;"></i> Promotion Date Range <span style="color:var(--text-muted);font-weight:400;text-transform:none;letter-spacing:0;font-size:10px;">&nbsp;— optional, leave blank to run every day</span></div>
                    <div class="date-range-row">
                        <div class="field">
                            <label><i class="fa-solid fa-calendar-day"></i> Start Date</label>
                            <input type="date" name="happy_hour_start_date" id="hhStartDate" value="<?= htmlspecialchars($hh_start_date) ?>">
                        </div>
                        <div class="date-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                        <div class="field">
                            <label><i class="fa-solid fa-calendar-xmark"></i> Expiry Date</label>
                            <input type="date" name="happy_hour_end_date" id="hhEndDate" value="<?= htmlspecialchars($hh_end_date) ?>">
                            <span class="field-hint">Auto-disables after this date</span>
                        </div>
                    </div>

                    <div class="preview-row" id="hhPreview">
                        <i class="fa-solid fa-tag"></i>
                        <span id="hhPreviewText"></span>
                    </div>
                </div>
            </div>

            <input type="hidden" name="_section" value="happy_hour">
            <div class="form-actions">
                <div class="form-actions-info">
                    <i class="fa-solid fa-circle-info"></i>
                    Saves Happy Hour only
                </div>
                <button type="submit" class="btn btn-save" style="padding:10px 22px;font-size:13px;">
                    <i class="fa-solid fa-floppy-disk"></i> Save
                </button>
            </div>
        </div>
    </form>

    <!-- ── BUY X GET 1 FREE ── -->
    <form method="POST">
        <div class="card">
            <div class="card-header">
                <div class="card-icon"><i class="fa-solid fa-gift"></i></div>
                <div style="flex:1;">
                    <div class="card-title">Free Drink Promotion</div>
                    <div class="card-sub">Get 1 free drink (cheapest item) when buying a set number</div>
                </div>
                <?php if ($bx_expired): ?>
                    <span class="chip chip-expired"><i class="fa-solid fa-ban" style="font-size:9px;"></i> Expired</span>
                <?php elseif ($bx_upcoming): ?>
                    <span class="chip chip-upcoming"><i class="fa-solid fa-clock" style="font-size:9px;"></i> Upcoming</span>
                <?php elseif ($bx_enabled && ($bx_start_date !== '' || $bx_end_date !== '')): ?>
                    <span class="chip chip-active">Active</span>
                <?php endif; ?>
            </div>

            <div class="card-inner">
                <div class="toggle-row">
                    <div>
                        <div class="toggle-label">Enable Free Drink</div>
                        <div class="toggle-sub">Toggle off to pause the buy-X-get-1 deal</div>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="buy_x_get_1_enabled" id="bxToggle" <?= $bx_enabled ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="fields-wrapper" id="bxFields">
                    <div class="section-label"><i class="fa-solid fa-gift" style="color:var(--accent);font-size:10px;"></i> Deal Configuration</div>
                    <div class="fields-grid">
                        <div class="field">
                            <label><i class="fa-solid fa-mug-hot"></i> Buy How Many to Get 1 Free</label>
                            <input type="number" name="buy_x_count" id="bxCount"
                                   value="<?= $bx_count ?>" min="2" max="20">
                            <span class="field-hint">e.g. 3 = "Buy 3 Get 1 Free" (min 2)</span>
                        </div>
                        <div class="field">
                            <label><i class="fa-solid fa-gift"></i> Free Item Override</label>
                            <select name="free_item_product_id" id="freeItemPid">
                                <option value="0" <?= $free_item_pid === 0 ? 'selected' : '' ?>>Auto — cheapest item in cart</option>
                                <?php foreach ($products_list as $p): ?>
                                <option value="<?= (int)$p['product_id'] ?>" <?= $free_item_pid === (int)$p['product_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="field-hint">Falls back to cheapest if not in cart</span>
                        </div>
                    </div>

                    <div class="section-label" style="margin-top:24px;"><i class="fa-solid fa-calendar-range" style="color:var(--accent);font-size:10px;"></i> Promotion Date Range <span style="color:var(--text-muted);font-weight:400;text-transform:none;letter-spacing:0;font-size:10px;">&nbsp;— optional, leave blank to run every day</span></div>
                    <div class="date-range-row">
                        <div class="field">
                            <label><i class="fa-solid fa-calendar-day"></i> Start Date</label>
                            <input type="date" name="buy_x_start_date" id="bxStartDate" value="<?= htmlspecialchars($bx_start_date) ?>">
                        </div>
                        <div class="date-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                        <div class="field">
                            <label><i class="fa-solid fa-calendar-xmark"></i> Expiry Date</label>
                            <input type="date" name="buy_x_end_date" id="bxEndDate" value="<?= htmlspecialchars($bx_end_date) ?>">
                            <span class="field-hint">Auto-disables after this date</span>
                        </div>
                    </div>

                    <div class="preview-row" id="bxPreview">
                        <i class="fa-solid fa-tag"></i>
                        <span id="bxPreviewText"></span>
                    </div>
                </div>
            </div>

            <input type="hidden" name="_section" value="free_drink">
            <div class="form-actions">
                <div class="form-actions-info">
                    <i class="fa-solid fa-circle-info"></i>
                    Saves Free Drink only
                </div>
                <button type="submit" class="btn btn-save" style="padding:10px 22px;font-size:13px;">
                    <i class="fa-solid fa-floppy-disk"></i> Save
                </button>
            </div>
        </div>
    </form>

    <!-- ── CURRENCY ── -->
    <form method="POST">
        <div class="card">
            <div class="card-header">
                <div class="card-icon"><i class="fa-solid fa-coins"></i></div>
                <div>
                    <div class="card-title">Currency Settings</div>
                    <div class="card-sub">Exchange rate used when customers pay in Khmer Riel (KHR)</div>
                </div>
            </div>

            <div class="card-inner">
                <div class="fields-grid single">
                    <div class="field">
                        <label><i class="fa-solid fa-dollar-sign"></i> Exchange Rate (KHR per 1 USD)</label>
                        <input type="number" name="khr_exchange_rate" id="khrRate"
                               value="<?= $khr_rate ?>" min="100" max="99999">
                        <span class="field-hint">e.g. 4100 means $1.00 = ៛4,100</span>
                    </div>
                </div>

                <div class="preview-row" id="khrPreview" style="margin-top:18px;">
                    <i class="fa-solid fa-tag"></i>
                    <span id="khrPreviewText"></span>
                </div>
            </div>

            <input type="hidden" name="_section" value="currency">
            <div class="form-actions">
                <div class="form-actions-info">
                    <i class="fa-solid fa-circle-info"></i>
                    Saves Currency only
                </div>
                <button type="submit" class="btn btn-save" style="padding:10px 22px;font-size:13px;">
                    <i class="fa-solid fa-floppy-disk"></i> Save
                </button>
            </div>
        </div>
    </form>

    <!-- ── LOYALTY EARN RATE ── -->
    <form method="POST">
        <div class="card">
            <div class="card-header">
                <div class="card-icon"><i class="fa-solid fa-stamp"></i></div>
                <div>
                    <div class="card-title">Loyalty Earn Rate</div>
                    <div class="card-sub">How many points a customer earns for how many drinks</div>
                </div>
            </div>

            <div class="card-inner">
                <div class="field" style="margin-bottom:16px;">
                    <label><i class="fa-solid fa-sliders"></i> Earning Mode</label>
                    <select name="loyalty_mode" id="loyMode" onchange="loyPreview()">
                        <option value="drinks" <?= $loy_mode === 'drinks' ? 'selected' : '' ?>>☕ Per Drink Quantity (e.g. 1 point per 2 drinks)</option>
                        <option value="spend" <?= $loy_mode === 'spend' ? 'selected' : '' ?>>💵 Per Dollar Spend ($) (e.g. 1 point per $10 spent)</option>
                    </select>
                    <span class="field-hint">Earn by drink count or by total order spend amount</span>
                </div>

                <div class="fields-grid">
                    <div class="field">
                        <label><i class="fa-solid fa-star"></i> Points earned</label>
                        <input type="number" name="loyalty_points_per" id="loyPer"
                               value="<?= $loy_per ?>" min="1" max="100">
                        <span class="field-hint">How many points to award</span>
                    </div>
                    <div class="field">
                        <label id="loyUnitsLabel"><i class="fa-solid fa-mug-hot"></i> Per how many drinks</label>
                        <input type="number" name="loyalty_points_drinks" id="loyDrinks"
                               value="<?= $loy_drinks ?>" min="1" max="100">
                        <span class="field-hint" id="loyUnitsHint">Leftover drinks carry over to the next visit</span>
                    </div>
                </div>

                <?php /* Two bare number boxes are ambiguous until the ratio is
                         spelled out, so the sentence is the part that matters. */ ?>
                <div class="preview-row" id="loyPreview" style="margin-top:18px;">
                    <i class="fa-solid fa-tag"></i>
                    <span id="loyPreviewText"></span>
                </div>
            </div>

            <input type="hidden" name="_section" value="loyalty">
            <div class="form-actions">
                <div class="form-actions-info">
                    <i class="fa-solid fa-circle-info"></i>
                    Saves Loyalty Earn Rate only
                </div>
                <button type="submit" class="btn btn-save" style="padding:10px 22px;font-size:13px;">
                    <i class="fa-solid fa-floppy-disk"></i> Save
                </button>
            </div>
        </div>
    </form>

    <!-- ── TAX RATE ── -->
    <form method="POST">
        <div class="card">
            <div class="card-header">
                <div class="card-icon"><i class="fa-solid fa-receipt"></i></div>
                <div>
                    <div class="card-title">Tax Rate</div>
                    <div class="card-sub">Applied to every order total in the cart and on receipts</div>
                </div>
            </div>

            <div class="card-inner">
                <div class="fields-grid single">
                    <div class="field">
                        <label><i class="fa-solid fa-percent"></i> Tax Rate (%)</label>
                        <input type="number" name="tax_rate" id="taxRate"
                               value="<?= $tax_rate ?>" min="0" max="100" step="0.01">
                        <span class="field-hint">Enter 0 to disable tax. Supports decimals (e.g. 7.5)</span>
                    </div>
                </div>

                <div class="preview-row" id="taxPreview" style="margin-top:18px;">
                    <i class="fa-solid fa-tag"></i>
                    <span id="taxPreviewText"></span>
                </div>
            </div>

            <input type="hidden" name="_section" value="tax">
            <div class="form-actions">
                <div class="form-actions-info">
                    <i class="fa-solid fa-circle-info"></i>
                    Saves Tax Rate only
                </div>
                <div style="display:flex;gap:10px;">
                    <a href="dashboard.php" class="btn btn-cancel">
                        <i class="fa-solid fa-xmark"></i> Dashboard
                    </a>
                    <button type="submit" class="btn btn-save" style="padding:10px 22px;font-size:13px;">
                        <i class="fa-solid fa-floppy-disk"></i> Save
                    </button>
                </div>
            </div>
        </div>
    </form>

    <!-- ── DAILY SALES TARGET ── -->
    <form method="POST">
        <div class="card">
            <div class="card-header">
                <div class="card-icon"><i class="fa-solid fa-bullseye"></i></div>
                <div>
                    <div class="card-title">Sales Target</div>
                    <div class="card-sub">Daily revenue goal shown on the Report goal-progress bar</div>
                </div>
            </div>

            <div class="card-inner">
                <div class="fields-grid single">
                    <div class="field">
                        <label><i class="fa-solid fa-dollar-sign"></i> Daily Sales Target ($)</label>
                        <input type="number" name="daily_sales_target" id="dailyTarget"
                               value="<?= $daily_target ?>" min="0" step="0.01">
                        <span class="field-hint">The Report's progress bar fills toward this amount each day.</span>
                    </div>
                </div>
            </div>

            <input type="hidden" name="_section" value="target">
            <div class="form-actions">
                <div class="form-actions-info">
                    <i class="fa-solid fa-circle-info"></i>
                    Saves Sales Target only
                </div>
                <div style="display:flex;gap:10px;">
                    <a href="dashboard.php" class="btn btn-cancel">
                        <i class="fa-solid fa-xmark"></i> Dashboard
                    </a>
                    <button type="submit" class="btn btn-save" style="padding:10px 22px;font-size:13px;">
                        <i class="fa-solid fa-floppy-disk"></i> Save
                    </button>
                </div>
            </div>
        </div>
    </form>

    <!-- ── STAND NUMBERS ── -->
    <form method="POST">
        <div class="card">
            <div class="card-header">
                <div class="card-icon"><i class="fa-solid fa-table-cells-large"></i></div>
                <div>
                    <div class="card-title">Stand Numbers</div>
                    <div class="card-sub">How many metal stand numbers you hand out (the order picker &amp; Stand Numbers page show 1&hellip;N)</div>
                </div>
            </div>

            <div class="card-inner">
                <div class="fields-grid single">
                    <div class="field">
                        <label><i class="fa-solid fa-hashtag"></i> Number of stands</label>
                        <input type="number" name="stand_count" id="standCount"
                               value="<?= $stand_count ?>" min="1" max="100" step="1">
                        <span class="field-hint">Set 1&ndash;100. Reducing it won't free stands already in use.</span>
                    </div>
                </div>
            </div>

            <input type="hidden" name="_section" value="stands">
            <div class="form-actions">
                <div class="form-actions-info">
                    <i class="fa-solid fa-circle-info"></i>
                    Saves Stand Numbers only
                </div>
                <div style="display:flex;gap:10px;">
                    <a href="dashboard.php" class="btn btn-cancel">
                        <i class="fa-solid fa-xmark"></i> Dashboard
                    </a>
                    <button type="submit" class="btn btn-save" style="padding:10px 22px;font-size:13px;">
                        <i class="fa-solid fa-floppy-disk"></i> Save
                    </button>
                </div>
            </div>
        </div>
    </form>

    <!-- ── BARISTA SETTINGS ── -->
    <form method="POST">
        <div class="card">
            <div class="card-header">
                <div class="card-icon"><i class="fa-solid fa-hourglass-half"></i></div>
                <div>
                    <div class="card-title">Barista Station</div>
                    <div class="card-sub">Configure the barista station display and order prioritization</div>
                </div>
            </div>

            <div class="card-inner">
                <div class="fields-grid single">
                    <div class="field">
                        <label><i class="fa-solid fa-clock"></i> Overdue threshold (minutes)</label>
                        <input type="number" name="overdue_minutes" id="overdueMinutes"
                               value="<?= $overdue_minutes ?>" min="1" max="120" step="1">
                        <span class="field-hint">Set 1–120 minutes. Orders older than this are marked Overdue on the barista station.</span>
                    </div>
                </div>
            </div>

            <input type="hidden" name="_section" value="barista">
            <div class="form-actions">
                <div class="form-actions-info">
                    <i class="fa-solid fa-circle-info"></i>
                    Saves Barista Station settings only
                </div>
                <div style="display:flex;gap:10px;">
                    <a href="dashboard.php" class="btn btn-cancel">
                        <i class="fa-solid fa-xmark"></i> Dashboard
                    </a>
                    <button type="submit" class="btn btn-save" style="padding:10px 22px;font-size:13px;">
                        <i class="fa-solid fa-floppy-disk"></i> Save
                    </button>
                </div>
            </div>
        </div>
    </form>

    <!-- ── PAY LATER SETTINGS ── -->
    <form method="POST">
        <div class="card">
            <div class="card-header">
                <div class="card-icon"><i class="fa-solid fa-wallet"></i></div>
                <div>
                    <div class="card-title">Pay Later</div>
                    <div class="card-sub">Control how quickly staff are prompted to follow up on unpaid tabs</div>
                </div>
            </div>

            <div class="card-inner">
                <div class="fields-grid single">
                    <div class="field">
                        <label><i class="fa-solid fa-clock"></i> Follow-up threshold (minutes)</label>
                        <input type="number" name="paylater_followup_minutes" id="paylaterFollowup"
                               value="<?= $paylater_followup ?>" min="5" max="240" step="1">
                        <span class="field-hint">Set 5&ndash;240 minutes. Pay Later tabs unpaid longer than this flash red on Find Orders with a "follow up" prompt.</span>
                    </div>
                </div>
            </div>

            <input type="hidden" name="_section" value="paylater">
            <div class="form-actions">
                <div class="form-actions-info">
                    <i class="fa-solid fa-circle-info"></i>
                    Saves Pay Later settings only
                </div>
                <div style="display:flex;gap:10px;">
                    <a href="dashboard.php" class="btn btn-cancel">
                        <i class="fa-solid fa-xmark"></i> Dashboard
                    </a>
                    <button type="submit" class="btn btn-save" style="padding:10px 22px;font-size:13px;">
                        <i class="fa-solid fa-floppy-disk"></i> Save
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
// ── Toggle enable/disable dimming ──
function bindToggle(toggleId, fieldsId) {
    const toggle = document.getElementById(toggleId);
    const fields = document.getElementById(fieldsId);
    function update() {
        fields.classList.toggle('dimmed', !toggle.checked);
    }
    toggle.addEventListener('change', update);
    update();
}
bindToggle('hhToggle', 'hhFields');
bindToggle('bxToggle', 'bxFields');

// ── Format date for preview ──
function fmtDate(val) {
    if (!val) return '';
    var d = new Date(val + 'T00:00:00');
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

// ── Happy Hour live preview ──
function updateHHPreview() {
    const start    = document.getElementById('hhStart');
    const end      = document.getElementById('hhEnd');
    const discount = document.getElementById('hhDiscount');
    const text     = document.getElementById('hhPreviewText');
    const startText = start.options[start.selectedIndex].text;
    const endText   = end.options[end.selectedIndex].text;
    const pct       = parseInt(discount.value) || 0;
    const sd = document.getElementById('hhStartDate').value;
    const ed = document.getElementById('hhEndDate').value;
    let dateStr = '';
    if (sd && ed)       dateStr = ` · ${fmtDate(sd)} – ${fmtDate(ed)}`;
    else if (ed)        dateStr = ` · until ${fmtDate(ed)}`;
    else if (sd)        dateStr = ` · from ${fmtDate(sd)}`;
    text.textContent = `${pct}% off all orders placed between ${startText} and ${endText}${dateStr}`;
}
document.getElementById('hhStart').addEventListener('change', updateHHPreview);
document.getElementById('hhEnd').addEventListener('change', updateHHPreview);
document.getElementById('hhDiscount').addEventListener('input', updateHHPreview);
document.getElementById('hhStartDate').addEventListener('change', updateHHPreview);
document.getElementById('hhEndDate').addEventListener('change', updateHHPreview);
updateHHPreview();

// ── Buy X Get 1 live preview ──
function updateBXPreview() {
    const count = parseInt(document.getElementById('bxCount').value) || 3;
    const sel   = document.getElementById('freeItemPid');
    const freeLabel = sel.value === '0' ? 'cheapest item' : sel.options[sel.selectedIndex].text.trim();
    const sd = document.getElementById('bxStartDate').value;
    const ed = document.getElementById('bxEndDate').value;
    let dateStr = '';
    if (sd && ed)       dateStr = ` · ${fmtDate(sd)} – ${fmtDate(ed)}`;
    else if (ed)        dateStr = ` · until ${fmtDate(ed)}`;
    else if (sd)        dateStr = ` · from ${fmtDate(sd)}`;
    document.getElementById('bxPreviewText').textContent =
        `Buy ${count} drink${count !== 1 ? 's' : ''}, get 1 free (${freeLabel} is free)${dateStr}`;
}
document.getElementById('bxCount').addEventListener('input', updateBXPreview);
document.getElementById('freeItemPid').addEventListener('change', updateBXPreview);
document.getElementById('bxStartDate').addEventListener('change', updateBXPreview);
document.getElementById('bxEndDate').addEventListener('change', updateBXPreview);
updateBXPreview();

// ── KHR live preview ──
function updateKHRPreview() {
    const rate = parseInt(document.getElementById('khrRate').value) || 4100;
    document.getElementById('khrPreviewText').textContent =
        '$1.00 USD = ៛' + rate.toLocaleString() + ' KHR';
}
document.getElementById('khrRate').addEventListener('input', updateKHRPreview);
updateKHRPreview();

// ── Loyalty Earn Rate live preview ──
function loyPreview() {
  var mode   = document.getElementById('loyMode') ? document.getElementById('loyMode').value : 'drinks';
  var per    = Math.max(1, parseInt(document.getElementById('loyPer').value, 10)    || 1);
  var drinks = Math.max(1, parseInt(document.getElementById('loyDrinks').value, 10) || 1);
  var el = document.getElementById('loyPreviewText');
  var lbl = document.getElementById('loyUnitsLabel');
  var hint = document.getElementById('loyUnitsHint');
  if (!el) return;

  if (mode === 'spend') {
      if (lbl) lbl.innerHTML = '<i class="fa-solid fa-dollar-sign"></i> Per how many dollars spent ($)';
      if (hint) hint.textContent = 'Leftover dollars carry over to the next visit';
      el.textContent = per + (per === 1 ? ' point' : ' points') + ' per $' + drinks + ' spent'
                     + ' — applies across Cash, Bakong QR, and Pay Later orders'
                     + (drinks > 1 ? ', and leftover dollars carry to the next visit.' : '.');
  } else {
      if (lbl) lbl.innerHTML = '<i class="fa-solid fa-mug-hot"></i> Per how many drinks';
      if (hint) hint.textContent = 'Leftover drinks carry over to the next visit';
      el.textContent = per + (per === 1 ? ' point' : ' points') + ' per '
                     + (drinks === 1 ? 'drink' : drinks + ' drinks')
                     + ' — merch and free gift drinks never earn points'
                     + (drinks > 1 ? ', and leftover drinks carry to the next visit.' : '.');
  }
}
['loyPer', 'loyDrinks'].forEach(function (id) {
  var n = document.getElementById(id);
  if (n) n.addEventListener('input', loyPreview);
});
loyPreview();

// ── Tax Rate live preview ──
function updateTaxPreview() {
    const pct = parseFloat(document.getElementById('taxRate').value);
    const el  = document.getElementById('taxPreviewText');
    if (isNaN(pct) || pct <= 0) {
        el.textContent = 'No tax applied — orders are tax-free.';
    } else {
        const example = (10 * (1 + pct / 100)).toFixed(2);
        el.textContent = pct + '% tax · e.g. $10.00 subtotal → $' + example + ' total';
    }
}
document.getElementById('taxRate').addEventListener('input', updateTaxPreview);
updateTaxPreview();
</script>

</body>
</html>
