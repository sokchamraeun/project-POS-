<?php
require 'admin_only.php';
require_once 'config.php';

$message      = '';
$message_type = '';

// ── ADD NEW REWARD ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $reward_name      = trim($_POST['reward_name'] ?? '');
    $points_required  = (int)($_POST['points_required'] ?? 0);
    $description      = trim($_POST['description'] ?? '');

    if (empty($reward_name) || $points_required <= 0) {
        $message      = "Please fill in all required fields.";
        $message_type = 'error';
    } else {
        $stmt = $conn->prepare("INSERT INTO rewards (reward_name, points_required, description) VALUES (?, ?, ?)");
        $stmt->bind_param("sis", $reward_name, $points_required, $description);
        if ($stmt->execute()) {
            $message      = "Reward '$reward_name' added successfully!";
            $message_type = 'success';
        } else {
            $message      = "Error adding reward: " . $conn->error;
            $message_type = 'error';
        }
    }
}

// ── EDIT REWARD ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $reward_id        = (int)($_POST['reward_id'] ?? 0);
    $reward_name      = trim($_POST['reward_name'] ?? '');
    $points_required  = (int)($_POST['points_required'] ?? 0);
    $description      = trim($_POST['description'] ?? '');
    $is_active        = isset($_POST['is_active']) ? 1 : 0;

    if ($reward_id <= 0 || empty($reward_name) || $points_required <= 0) {
        $message      = "Please fill in all required fields.";
        $message_type = 'error';
    } else {
        $stmt = $conn->prepare("UPDATE rewards SET reward_name = ?, points_required = ?, description = ?, is_active = ? WHERE reward_id = ?");
        $stmt->bind_param("sisii", $reward_name, $points_required, $description, $is_active, $reward_id);
        if ($stmt->execute()) {
            $message      = "Reward '$reward_name' updated successfully!";
            $message_type = 'success';
        } else {
            $message      = "Error updating reward: " . $conn->error;
            $message_type = 'error';
        }
    }
}

// ── DELETE REWARD ──
if (isset($_GET['delete'])) {
    $reward_id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM rewards WHERE reward_id = ?");
    $stmt->bind_param("i", $reward_id);
    if ($stmt->execute()) {
        $message      = "Reward deleted successfully!";
        $message_type = 'success';
    } else {
        $message      = "Error deleting reward: " . $conn->error;
        $message_type = 'error';
    }
}

// ── FETCH ALL REWARDS ──
$stmt = $conn->prepare("SELECT * FROM rewards ORDER BY points_required ASC");
$stmt->execute();
$rewards = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── GET REWARD FOR EDITING ──
$edit_reward = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    foreach ($rewards as $r) {
        if ((int)$r['reward_id'] === $edit_id) {
            $edit_reward = $r;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Rewards | Bird's Nest Coffee</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>(function(){var t=localStorage.getItem('theme');if(t==='light')document.documentElement.setAttribute('data-theme','light');})();</script>
    <style>
        /* ── CSS VARIABLES (dark-only) ── */
        :root {
            --bg: #0b0b0b;
            --bg-card: #121212;
            --bg-card-hover: #1a1a1a;
            --border: #1f1f1f;
            --border-hover: #2a2a2a;
            --accent: #d1904b;
            --accent-light: #e8b87a;
            --accent-dark: #a0702a;
            --text: #f5f5f5;
            --text-muted: #888888;
            --text-light: #ffffff;
            --success: #55e087;
            --danger: #e74c3c;
            --warning: #f1c40f;
            --info: #3498db;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.3);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.4);
            --shadow-lg: 0 8px 40px rgba(0,0,0,0.5);
            --shadow-accent: 0 4px 20px rgba(209,144,75,0.15);
            --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
            --radius: 12px;
        }

        [data-theme="light"] {
            --bg: #f5f0eb;
            --bg-card: #ffffff;
            --bg-card-hover: #fdf8f3;
            --border: #e8ddd2;
            --border-hover: #d4c4b0;
            --text: #1a1008;
            --text-muted: #7a6a58;
            --text-light: #1a1008;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            overflow-x: hidden;
        }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg); }
        ::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 10px; }

        /* ── HEADER ── */
        .ar-header {
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 28px;
            background: var(--bg-card);
            border-bottom: 1px solid var(--border);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            flex-wrap: wrap;
            gap: 10px;
        }

        .ar-header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .ar-brand {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .ar-brand i {
            color: var(--accent);
            font-size: 18px;
        }

        .ar-brand span {
            font-size: 16px;
            font-weight: 700;
            color: var(--accent);
        }

        .btn-nav {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 50px;
            border: 1px solid var(--border);
            background: var(--bg-card);
            color: var(--text-muted);
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-nav:hover {
            border-color: var(--accent);
            color: var(--accent);
        }

        /* ── MAIN WRAPPER ── */
        .ar-wrap {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px 28px 40px;
        }

        /* ── MESSAGE BANNER ── */
        .ar-message {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 18px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 500;
            animation: bannerIn 0.35s ease both;
        }

        @keyframes bannerIn {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .ar-message.success {
            background: rgba(85,224,135,0.08);
            border: 1px solid rgba(85,224,135,0.25);
            color: var(--success);
        }

        .ar-message.error {
            background: rgba(231,76,60,0.08);
            border: 1px solid rgba(231,76,60,0.25);
            color: var(--danger);
        }

        .ar-message i { font-size: 16px; flex-shrink: 0; }

        .ar-message-close {
            margin-left: auto;
            background: none;
            border: none;
            color: currentColor;
            cursor: pointer;
            font-size: 14px;
            opacity: 0.7;
            padding: 0;
            transition: var(--transition);
        }

        .ar-message-close:hover { opacity: 1; }

        /* ── TWO-COLUMN GRID ── */
        .ar-grid {
            display: grid;
            grid-template-columns: 400px 1fr;
            gap: 24px;
            align-items: start;
        }

        /* ── PANEL ── */
        .ar-panel {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            transition: var(--transition);
        }

        .ar-panel:hover {
            border-color: var(--border-hover);
        }

        .ar-panel-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
        }

        .ar-panel-header i {
            color: var(--accent);
            font-size: 16px;
        }

        .ar-panel-header h2 {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-light);
        }

        .ar-panel-body {
            padding: 20px 22px;
        }

        /* ── FORM ── */
        .form-group {
            margin-bottom: 14px;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: var(--bg);
            color: var(--text);
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            outline: none;
            transition: var(--transition);
        }

        .form-group input[type="text"]:focus,
        .form-group input[type="number"]:focus,
        .form-group textarea:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(209,144,75,0.08);
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: var(--text-muted);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 72px;
        }

        /* ── CHECKBOX ── */
        .checkbox-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 0 4px;
        }

        .checkbox-row input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--accent);
            cursor: pointer;
        }

        .checkbox-row label {
            font-size: 13px;
            font-weight: 500;
            color: var(--text);
            cursor: pointer;
            margin: 0;
            text-transform: none;
            letter-spacing: 0;
        }

        /* ── BUTTONS ── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 10px 20px;
            border-radius: 50px;
            border: none;
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            white-space: nowrap;
        }

        .btn-primary {
            background: var(--accent);
            color: #000;
        }

        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-1px);
            box-shadow: var(--shadow-accent);
        }

        .btn-danger {
            background: transparent;
            color: var(--danger);
            border: 1px solid rgba(231,76,60,0.35);
        }

        .btn-danger:hover {
            background: rgba(231,76,60,0.1);
            border-color: var(--danger);
        }

        .btn-secondary {
            background: transparent;
            color: var(--text-muted);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            border-color: var(--accent);
            color: var(--accent);
        }

        .btn-sm {
            padding: 6px 14px;
            font-size: 11px;
            border-radius: 20px;
        }

        .btn-full {
            width: 100%;
            justify-content: center;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 6px;
        }

        /* ── EDITING INDICATOR ── */
        .editing-banner {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 8px;
            background: rgba(209,144,75,0.08);
            border: 1px solid rgba(209,144,75,0.2);
            margin-bottom: 16px;
            font-size: 12px;
            color: var(--accent);
            font-weight: 600;
        }

        /* ── TABLE ── */
        .table-wrap {
            overflow-x: auto;
        }

        .rewards-table {
            width: 100%;
            border-collapse: collapse;
        }

        .rewards-table th {
            padding: 10px 16px;
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .rewards-table td {
            padding: 13px 16px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        .rewards-table tr:last-child td {
            border-bottom: none;
        }

        .rewards-table tr:hover td {
            background: rgba(255,255,255,0.02);
        }

        .reward-name-cell strong {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-light);
        }

        .reward-name-cell .reward-desc {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
            max-width: 220px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .pts-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            background: rgba(209,144,75,0.12);
            color: var(--accent);
            border: 1px solid rgba(209,144,75,0.2);
            white-space: nowrap;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }

        .status-badge.active {
            background: rgba(85,224,135,0.12);
            color: var(--success);
            border: 1px solid rgba(85,224,135,0.2);
        }

        .status-badge.inactive {
            background: rgba(231,76,60,0.12);
            color: var(--danger);
            border: 1px solid rgba(231,76,60,0.2);
        }

        .actions-cell {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .actions-cell a { text-decoration: none; }

        /* ── EMPTY STATE ── */
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 36px;
            display: block;
            margin-bottom: 12px;
            color: var(--border);
        }

        .empty-state h3 {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--text);
        }

        .empty-state p {
            font-size: 13px;
        }

        /* ── REWARD COUNT ── */
        .reward-count-badge {
            margin-left: auto;
            font-size: 11px;
            color: var(--text-muted);
            background: var(--bg);
            border: 1px solid var(--border);
            padding: 2px 10px;
            border-radius: 20px;
        }

        /* ── RESPONSIVE ── */
        @media (max-width: 1024px) {
            .ar-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .ar-header { padding: 12px 16px; }
            .ar-wrap   { padding: 16px; }
            .ar-panel-body { padding: 16px; }
            .rewards-table th,
            .rewards-table td { padding: 10px 12px; font-size: 12px; }
        }

        @media (max-width: 640px) {
            .ar-wrap { padding: 12px 16px; }
            .actions-cell { flex-direction: column; gap: 4px; }
            .btn-sm { width: 100%; justify-content: center; }
        }

        @media (max-width: 400px) {
            .ar-header-left { flex-wrap: wrap; gap: 6px; }
            .reward-name-cell .reward-desc { display: none; }
        }

        /* ── ENTRANCE ANIMATIONS ── */
        @keyframes fadeDown {
            from { opacity: 0; transform: translateY(-18px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(18px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes slideLeft {
            from { opacity: 0; transform: translateX(-28px); }
            to   { opacity: 1; transform: translateX(0); }
        }
        @keyframes slideRight {
            from { opacity: 0; transform: translateX(28px); }
            to   { opacity: 1; transform: translateX(0); }
        }
        @keyframes shimmer {
            from { background-position: -200% center; }
            to   { background-position:  200% center; }
        }
        @keyframes floatA {
            0%,100% { transform: translateY(0) scale(1); }
            50%     { transform: translateY(-22px) scale(1.04); }
        }
        @keyframes floatB {
            0%,100% { transform: translateY(0) scale(1); }
            50%     { transform: translateY(20px) scale(0.97); }
        }

        /* ── AMBIENT ORBS ── */
        .orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(90px);
            pointer-events: none;
            z-index: 0;
        }
        .orb-amber {
            width: 420px; height: 420px;
            background: radial-gradient(circle, rgba(209,144,75,0.18) 0%, transparent 70%);
            top: -80px; right: -80px;
            animation: floatA 10s ease-in-out infinite;
        }
        .orb-blue {
            width: 360px; height: 360px;
            background: radial-gradient(circle, rgba(52,152,219,0.12) 0%, transparent 70%);
            bottom: -60px; left: -60px;
            animation: floatB 12s ease-in-out infinite;
        }

        /* ── PAGE LAYERING ── */
        .ar-wrap { position: relative; z-index: 1; }

        /* ── HEADER ENTRANCE ── */
        .ar-header { animation: fadeDown .45s ease both; }

        /* ── PANEL ENTRANCES ── */
        .ar-grid > .ar-panel:first-child { animation: slideLeft  .5s .10s ease both; }
        .ar-grid > .ar-panel:last-child  { animation: slideRight .5s .18s ease both; }

        /* ── PANEL HEADER SHIMMER ── */
        .ar-panel-header { position: relative; overflow: hidden; }
        .ar-panel-header::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(
                90deg,
                transparent 0%,
                rgba(209,144,75,0.07) 50%,
                transparent 100%
            );
            background-size: 200% 100%;
            animation: shimmer 3s ease-in-out infinite;
            pointer-events: none;
        }

        /* ── FORM GROUP STAGGER ── */
        .ar-panel-body .form-group                { animation: fadeUp .35s ease both; }
        .ar-panel-body .form-group:nth-child(1)   { animation-delay: .22s; }
        .ar-panel-body .form-group:nth-child(2)   { animation-delay: .30s; }
        .ar-panel-body .form-group:nth-child(3)   { animation-delay: .38s; }
        .ar-panel-body .form-group:nth-child(4)   { animation-delay: .46s; }
        .form-actions                              { animation: fadeUp .35s .52s ease both; }

        /* ── TABLE ROW STAGGER ── */
        .rewards-table tbody tr                   { animation: fadeUp .35s ease both; }
        .rewards-table tbody tr:nth-child(1)      { animation-delay: .26s; }
        .rewards-table tbody tr:nth-child(2)      { animation-delay: .33s; }
        .rewards-table tbody tr:nth-child(3)      { animation-delay: .40s; }
        .rewards-table tbody tr:nth-child(4)      { animation-delay: .47s; }
        .rewards-table tbody tr:nth-child(5)      { animation-delay: .54s; }
        .rewards-table tbody tr:nth-child(6)      { animation-delay: .61s; }
        .rewards-table tbody tr:nth-child(7)      { animation-delay: .68s; }
        .rewards-table tbody tr:nth-child(8)      { animation-delay: .75s; }

        /* ── PAGINATION ── */
        .report-pager {
            display: flex; align-items: center; justify-content: center;
            gap: 6px; flex-wrap: wrap; padding: 16px 20px 20px;
            border-top: 1px solid var(--border);
        }
        .report-pager .rp-btn {
            min-width: 36px; height: 36px; padding: 0 10px;
            border-radius: 9px; border: 1px solid var(--border);
            background: rgba(255,255,255,.04); color: var(--text);
            font-size: 13px; font-weight: 600; cursor: pointer;
            font-family: 'Poppins', sans-serif; transition: var(--transition);
            display: inline-flex; align-items: center; justify-content: center;
        }
        .report-pager .rp-btn:hover:not(:disabled):not(.active) { border-color: var(--accent); color: var(--accent); }
        .report-pager .rp-btn.active { background: var(--accent); border-color: var(--accent); color: #000; }
        .report-pager .rp-btn:disabled { opacity: .35; cursor: not-allowed; }
        .report-pager .rp-ellipsis { color: var(--text-muted); padding: 0 4px; }
    </style>
</head>
<body>

<!-- AMBIENT ORBS -->
<div class="orb orb-amber"></div>
<div class="orb orb-blue"></div>

<!-- HEADER -->
<header class="ar-header">
    <div class="ar-header-left">
        <a href="dashboard.php" class="btn-nav">
            <i class="fa-solid fa-arrow-left"></i> Dashboard
        </a>
        <div class="ar-brand">
            <i class="fa-solid fa-gift"></i>
            <span>Manage Rewards</span>
        </div>
    </div>
</header>

<div class="ar-wrap">

    <!-- MESSAGE BANNER -->
    <?php if ($message): ?>
    <div class="ar-message <?= htmlspecialchars($message_type) ?>">
        <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
        <span><?= htmlspecialchars($message) ?></span>
        <button class="ar-message-close" onclick="this.parentElement.remove()" title="Dismiss">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
    <?php endif; ?>

    <div class="ar-grid">

        <!-- LEFT: Add / Edit Form -->
        <div class="ar-panel">
            <div class="ar-panel-header">
                <i class="fa-solid <?= $edit_reward ? 'fa-pen-to-square' : 'fa-plus' ?>"></i>
                <h2><?= $edit_reward ? 'Edit Reward' : 'Add New Reward' ?></h2>
            </div>
            <div class="ar-panel-body">

                <?php if ($edit_reward): ?>
                <div class="editing-banner">
                    <i class="fa-solid fa-pen-to-square"></i>
                    Editing: <?= htmlspecialchars($edit_reward['reward_name']) ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="admin_rewards.php<?= $edit_reward ? '?edit=' . (int)$edit_reward['reward_id'] : '' ?>">
                    <input type="hidden" name="action" value="<?= $edit_reward ? 'edit' : 'add' ?>">
                    <?php if ($edit_reward): ?>
                    <input type="hidden" name="reward_id" value="<?= (int)$edit_reward['reward_id'] ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Reward Name <span style="color:var(--danger)">*</span></label>
                        <input type="text" name="reward_name"
                            value="<?= $edit_reward ? htmlspecialchars($edit_reward['reward_name']) : '' ?>"
                            placeholder="e.g. Free Drink, Free Pastry"
                            required>
                    </div>

                    <div class="form-group">
                        <label>Points Required <span style="color:var(--danger)">*</span></label>
                        <input type="number" name="points_required"
                            value="<?= $edit_reward ? (int)$edit_reward['points_required'] : 10 ?>"
                            min="1"
                            required>
                    </div>

                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" placeholder="Brief description of the reward (optional)"><?= $edit_reward ? htmlspecialchars($edit_reward['description'] ?? '') : '' ?></textarea>
                    </div>

                    <?php if ($edit_reward): ?>
                    <div class="form-group">
                        <div class="checkbox-row">
                            <input type="checkbox" name="is_active" id="is_active" value="1"
                                <?= !empty($edit_reward['is_active']) ? 'checked' : '' ?>>
                            <label for="is_active">Active (visible to customers)</label>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary btn-full">
                            <i class="fa-solid <?= $edit_reward ? 'fa-floppy-disk' : 'fa-plus' ?>"></i>
                            <?= $edit_reward ? 'Update Reward' : 'Add Reward' ?>
                        </button>
                        <?php if ($edit_reward): ?>
                        <a href="admin_rewards.php" class="btn btn-secondary" style="flex-shrink:0;">
                            <i class="fa-solid fa-xmark"></i> Cancel
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- RIGHT: Rewards List -->
        <div class="ar-panel">
            <div class="ar-panel-header">
                <i class="fa-solid fa-list"></i>
                <h2>All Rewards</h2>
                <span class="reward-count-badge"><?= count($rewards) ?> total</span>
            </div>

            <?php if (empty($rewards)): ?>
            <div class="empty-state">
                <i class="fa-solid fa-gift"></i>
                <h3>No Rewards Yet</h3>
                <p>Add your first reward using the form on the left.</p>
            </div>
            <?php else: ?>
            <div class="table-wrap">
                <table class="rewards-table" id="rewardsTable">
                    <thead>
                        <tr>
                            <th>Reward</th>
                            <th>Points</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rewards as $reward): ?>
                        <tr>
                            <td>
                                <div class="reward-name-cell">
                                    <strong><?= htmlspecialchars($reward['reward_name']) ?></strong>
                                    <?php if (!empty($reward['description'])): ?>
                                    <div class="reward-desc" title="<?= htmlspecialchars($reward['description']) ?>">
                                        <?= htmlspecialchars($reward['description']) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td>
                                <span class="pts-badge">
                                    <i class="fa-solid fa-star"></i>
                                    <?= number_format((int)$reward['points_required']) ?>
                                </span>
                            </td>

                            <td>
                                <span class="status-badge <?= $reward['is_active'] ? 'active' : 'inactive' ?>">
                                    <i class="fa-solid <?= $reward['is_active'] ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
                                    <?= $reward['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>

                            <td>
                                <div class="actions-cell">
                                    <button type="button" class="btn btn-secondary btn-sm"
                                        data-id="<?= (int)$reward['reward_id'] ?>"
                                        data-name="<?= htmlspecialchars($reward['reward_name'], ENT_QUOTES) ?>"
                                        data-points="<?= (int)$reward['points_required'] ?>"
                                        data-desc="<?= htmlspecialchars($reward['description'] ?? '', ENT_QUOTES) ?>"
                                        data-active="<?= (int)$reward['is_active'] ?>"
                                        onclick="openEditModal(this)">
                                        <i class="fa-solid fa-pen-to-square"></i> Edit
                                    </button>
                                    <a href="admin_rewards.php?delete=<?= (int)$reward['reward_id'] ?>"
                                       class="btn btn-danger btn-sm"
                                       onclick="return confirm('Delete reward \'<?= addslashes(htmlspecialchars($reward['reward_name'])) ?>\'?\n\nThis cannot be undone.');">
                                        <i class="fa-solid fa-trash-can"></i> Delete
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="report-pager" id="rewardsPager"></div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
// ── THEME (follows shared key; toggled elsewhere) ──
window.addEventListener('storage', function (e) {
    if (e.key === 'theme') {
        if (e.newValue === 'light') document.documentElement.setAttribute('data-theme', 'light');
        else document.documentElement.removeAttribute('data-theme');
    }
});

// ── Client-side table pagination (10 rows/page) ──
const __pagers = {};
function setupPager(tableId, pagerId, pageSize) {
    const tbl = document.getElementById(tableId);
    const pager = document.getElementById(pagerId);
    if (!tbl || !pager) return;
    const body = tbl.querySelector('tbody');
    if (body && body.querySelector('td[colspan]')) return;
    __pagers[tableId] = { pagerId, pageSize, page: 1 };
    renderPagerPage(tableId);
}
function renderPagerPage(tableId) {
    const st = __pagers[tableId];
    if (!st) return;
    const rows = Array.from(document.getElementById(tableId).querySelector('tbody').querySelectorAll('tr'));
    const pages = Math.max(1, Math.ceil(rows.length / st.pageSize));
    if (st.page > pages) st.page = pages;
    const start = (st.page - 1) * st.pageSize, end = start + st.pageSize;
    rows.forEach((r, i) => { r.style.display = (i >= start && i < end) ? '' : 'none'; });
    buildPagerControls(tableId, pages);
}
function buildPagerControls(tableId, pages) {
    const st = __pagers[tableId];
    const pager = document.getElementById(st.pagerId);
    if (pages <= 1) { pager.innerHTML = ''; return; }
    const p = st.page;
    const btn = (label, target, o = {}) => o.ellipsis
        ? '<span class="rp-ellipsis">…</span>'
        : `<button class="rp-btn${o.active ? ' active' : ''}" ${o.disabled ? 'disabled' : ''} data-go="${target}" aria-label="${o.aria || ('Page ' + label)}">${label}</button>`;
    let html = btn('«', 1, { disabled: p === 1, aria: 'First page' }) + btn('‹', p - 1, { disabled: p === 1, aria: 'Previous page' });
    const win = [];
    if (pages <= 7) { for (let i = 1; i <= pages; i++) win.push(i); }
    else {
        win.push(1);
        let s = Math.max(2, p - 1), e = Math.min(pages - 1, p + 1);
        if (s > 2) win.push('...');
        for (let i = s; i <= e; i++) win.push(i);
        if (e < pages - 1) win.push('...');
        win.push(pages);
    }
    win.forEach(n => { html += n === '...' ? btn('', 0, { ellipsis: true }) : btn(n, n, { active: n === p }); });
    html += btn('›', p + 1, { disabled: p === pages, aria: 'Next page' }) + btn('»', pages, { disabled: p === pages, aria: 'Last page' });
    pager.innerHTML = html;
    pager.querySelectorAll('button[data-go]').forEach(b => {
        b.addEventListener('click', () => {
            const g = parseInt(b.dataset.go, 10);
            if (g >= 1 && g <= pages) { st.page = g; renderPagerPage(tableId); }
        });
    });
}
setupPager('rewardsTable', 'rewardsPager', 10);
</script>

<!-- ── Edit Reward Modal (opens in-page, no navigation) ── -->
<style>
    .rw-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center; padding:20px; }
    .rw-modal-overlay.open { display:flex; }
    .rw-modal { background:var(--bg-card); border:1px solid var(--border); border-radius:16px; width:100%; max-width:440px; padding:22px 24px; box-shadow:var(--shadow-lg); animation:rwIn .18s ease both; }
    @keyframes rwIn { from { opacity:0; transform:translateY(10px) scale(.98); } to { opacity:1; transform:none; } }
    .rw-modal-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; }
    .rw-modal-head h3 { margin:0; font-size:18px; color:var(--text-light); display:flex; align-items:center; gap:8px; }
    .rw-modal-head h3 i { color:var(--accent); }
    .rw-modal-close { background:none; border:none; color:var(--text-muted); font-size:26px; line-height:1; cursor:pointer; transition:var(--transition); }
    .rw-modal-close:hover { color:var(--danger); }
</style>
<div class="rw-modal-overlay" id="editModal">
    <div class="rw-modal">
        <div class="rw-modal-head">
            <h3><i class="fa-solid fa-pen-to-square"></i> Edit Reward</h3>
            <button type="button" class="rw-modal-close" onclick="closeEditModal()" aria-label="Close">&times;</button>
        </div>
        <form method="POST" action="admin_rewards.php">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="reward_id" id="em_id">
            <div class="form-group">
                <label>Reward Name</label>
                <input type="text" name="reward_name" id="em_name" required>
            </div>
            <div class="form-group">
                <label>Points Required</label>
                <input type="number" name="points_required" id="em_points" min="1" required>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" id="em_desc" placeholder="Brief description of the reward (optional)"></textarea>
            </div>
            <div class="form-group">
                <div class="checkbox-row">
                    <input type="checkbox" name="is_active" id="em_active" value="1">
                    <label for="em_active">Active (visible to customers)</label>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-full"><i class="fa-solid fa-floppy-disk"></i> Update Reward</button>
                <button type="button" class="btn btn-secondary" style="flex-shrink:0;" onclick="closeEditModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
            </div>
        </form>
    </div>
</div>
<script>
function openEditModal(btn) {
    document.getElementById('em_id').value     = btn.dataset.id;
    document.getElementById('em_name').value   = btn.dataset.name || '';
    document.getElementById('em_points').value = btn.dataset.points || '1';
    document.getElementById('em_desc').value   = btn.dataset.desc || '';
    document.getElementById('em_active').checked = btn.dataset.active === '1';
    document.getElementById('editModal').classList.add('open');
}
function closeEditModal() { document.getElementById('editModal').classList.remove('open'); }
document.getElementById('editModal').addEventListener('click', function (e) { if (e.target === this) closeEditModal(); });
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeEditModal(); });
</script>
</body>
</html>
