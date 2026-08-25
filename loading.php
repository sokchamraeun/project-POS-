<?php
session_start();
require 'config.php';

if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$role     = strtolower($_SESSION['role'] ?? 'staff');
$username = $_SESSION['username'] ?? 'User';

$roles = [
    'admin' => [
        'badge'      => 'ADMIN',
        'title'      => 'Admin Console',
        'portal'     => "Bird's Nest Coffee",
        'tagline'    => 'Full system access granted.',
        'sync_title' => 'Syncing Store Data',
        'sync_desc'  => 'Fetching latest menu, orders & prices...',
        'dest'       => 'menu.php',
    ],
    'manager' => [
        'badge'      => 'MANAGER',
        'title'      => 'Manager Portal',
        'portal'     => "Bird's Nest Coffee",
        'tagline'    => 'Shift overview is ready.',
        'sync_title' => 'Syncing Operations Data',
        'sync_desc'  => 'Loading shifts, stock & analytics...',
        'dest'       => 'menu.php',
    ],
    'supervisor' => [
        'badge'      => 'SUPERVISOR',
        'title'      => 'Supervisor View',
        'portal'     => "Bird's Nest Coffee",
        'tagline'    => 'Shift oversight active.',
        'sync_title' => 'Verifying Shift Status',
        'sync_desc'  => 'Loading staff & station access...',
        'dest'       => 'menu.php',
    ],
    'staff' => [
        'badge'      => 'STAFF',
        'title'      => 'Cashier Station',
        'portal'     => "Bird's Nest Coffee",
        'tagline'    => 'Ready to take orders.',
        'sync_title' => 'Syncing Menu & Register',
        'sync_desc'  => 'Loading drink recipes & pricing...',
        'dest'       => 'menu.php',
    ],
    'barista' => [
        'badge'      => 'BARISTA',
        'title'      => 'Kitchen Display',
        'portal'     => "Bird's Nest Coffee",
        'tagline'    => 'Live queue ready.',
        'sync_title' => 'Connecting to Order Queue',
        'sync_desc'  => 'Syncing pending drink tickets...',
        'dest'       => 'view_order.php',
    ],
    'inventory_clerk' => [
        'badge'      => 'INVENTORY',
        'title'      => 'Inventory Hub',
        'portal'     => "Bird's Nest Coffee",
        'tagline'    => 'Stock levels syncing.',
        'sync_title' => 'Auditing Ingredient Stock',
        'sync_desc'  => 'Syncing items, batches & reorders...',
        'dest'       => 'menu.php',
    ],
];

$cfg        = $roles[$role] ?? $roles['staff'];
$badge      = $cfg['badge'];
$title      = $cfg['title'];
$tagline    = $cfg['tagline'];
$sync_title = $cfg['sync_title'];
$sync_desc  = $cfg['sync_desc'];
$dest       = $cfg['dest'];

// Greeting based on hour
$hour = (int)date('H');
if ($hour < 12)       $greeting = 'Good morning';
elseif ($hour < 17)   $greeting = 'Good afternoon';
else                  $greeting = 'Good evening';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?> — Bird's Nest Coffee</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:          #040a08;
    --card-bg:     #091511;
    --card-border: rgba(0, 245, 160, 0.25);
    --mint:        #00f5a0;
    --mint-dim:    #00d486;
    --mint-glow:   rgba(0, 245, 160, 0.35);
    --mint-soft:   rgba(0, 245, 160, 0.12);
    --text-main:   #ffffff;
    --text-muted:  #708b82;
    --text-dim:    #435c54;
    --box-bg:      rgba(6, 17, 13, 0.85);
}

html, body {
    height: 100%;
    background-color: var(--bg);
    font-family: 'Plus Jakarta Sans', 'Outfit', sans-serif;
    color: var(--text-main);
    overflow: hidden;
    -webkit-font-smoothing: antialiased;
}

/* ── BACKGROUND GRID & AMBIENT GLOW ── */
.bg-scene {
    position: fixed;
    inset: 0;
    z-index: 0;
    background-color: var(--bg);
    background-image: 
        linear-gradient(rgba(0, 255, 170, 0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0, 255, 170, 0.04) 1px, transparent 1px);
    background-size: 36px 36px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.bg-scene::before {
    content: '';
    position: absolute;
    width: 650px;
    height: 650px;
    background: radial-gradient(circle, rgba(0, 245, 160, 0.16) 0%, rgba(0, 180, 120, 0.04) 45%, transparent 70%);
    filter: blur(50px);
    pointer-events: none;
}

.bg-scene::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at center, transparent 35%, rgba(3, 7, 5, 0.88) 100%);
    pointer-events: none;
}

/* ── PAGE LAYOUT ── */
.page {
    position: relative;
    z-index: 1;
    height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

/* ── MAIN CARD ── */
.loading-card {
    width: 100%;
    max-width: 440px;
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: 28px;
    padding: 34px 32px 30px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    position: relative;
    box-shadow: 
        0 0 50px -10px rgba(0, 245, 160, 0.2),
        0 30px 60px -15px rgba(0, 0, 0, 0.9),
        inset 0 1px 1px rgba(255, 255, 255, 0.08);
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
    animation: cardAppear 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
    transition: opacity 0.4s ease, transform 0.4s ease;
}

@keyframes cardAppear {
    from { opacity: 0; transform: translateY(20px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

.loading-card.fade-exit {
    opacity: 0;
    transform: scale(0.97) translateY(-10px);
}

/* ── TOP PILL BADGE ── */
.top-pill {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 6px 14px;
    background: rgba(9, 23, 18, 0.9);
    border: 1px solid rgba(0, 245, 160, 0.28);
    border-radius: 999px;
    box-shadow: 0 0 20px rgba(0, 245, 160, 0.15), inset 0 1px 1px rgba(255, 255, 255, 0.06);
    backdrop-filter: blur(12px);
    margin-bottom: 26px;
}

.top-pill .pill-brand {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-main);
    letter-spacing: 0.02em;
}

.top-pill .pill-brand svg {
    color: var(--mint);
    filter: drop-shadow(0 0 5px var(--mint));
}

.top-pill .role-badge {
    background: rgba(0, 245, 160, 0.14);
    border: 1px solid rgba(0, 245, 160, 0.4);
    color: var(--mint);
    font-size: 10px;
    font-weight: 800;
    padding: 2px 7px;
    border-radius: 6px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

/* ── CENTER RADAR & SHIELD ── */
.radar-wrapper {
    position: relative;
    width: 120px;
    height: 120px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 20px;
}

/* Outer rotating dashed ring */
.radar-outer-ring {
    position: absolute;
    inset: 0;
    border: 1.5px dashed rgba(0, 245, 160, 0.35);
    border-radius: 50%;
    animation: radarSpin 14s linear infinite;
}

/* Middle pulse ring */
.radar-mid-ring {
    position: absolute;
    inset: 12px;
    border: 1px solid rgba(0, 245, 160, 0.2);
    border-radius: 50%;
    box-shadow: 0 0 15px rgba(0, 245, 160, 0.1);
}

/* Center circular core */
.shield-core {
    position: relative;
    width: 66px;
    height: 66px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(0, 245, 160, 0.25) 0%, rgba(4, 26, 18, 0.95) 75%);
    border: 1.5px solid rgba(0, 245, 160, 0.65);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--mint);
    box-shadow: 
        0 0 30px rgba(0, 245, 160, 0.4),
        inset 0 0 15px rgba(0, 245, 160, 0.2);
    animation: shieldPulse 3s ease-in-out infinite alternate;
}

.shield-core svg {
    width: 28px;
    height: 28px;
    filter: drop-shadow(0 0 8px var(--mint));
}

@keyframes radarSpin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}

@keyframes shieldPulse {
    0%   { transform: scale(1);   box-shadow: 0 0 25px rgba(0, 245, 160, 0.35); }
    100% { transform: scale(1.05); box-shadow: 0 0 38px rgba(0, 245, 160, 0.55); }
}

/* ── TITLE SECTION ── */
.main-title {
    font-size: 26px;
    font-weight: 800;
    color: var(--text-main);
    letter-spacing: -0.02em;
    margin-bottom: 6px;
    line-height: 1.2;
}

.greeting-text {
    font-size: 13.5px;
    color: var(--text-muted);
    margin-bottom: 8px;
    font-weight: 400;
}

.greeting-text strong {
    color: var(--text-main);
    font-weight: 700;
}

.tagline-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--mint);
    letter-spacing: 0.01em;
}

.tagline-badge .status-dot {
    width: 6px;
    height: 6px;
    background-color: var(--mint);
    border-radius: 50%;
    box-shadow: 0 0 8px var(--mint);
    display: inline-block;
}

/* ── SYNC TASK STATUS BOX ── */
.sync-card {
    width: 100%;
    background: var(--box-bg);
    border: 1px solid rgba(0, 245, 160, 0.22);
    border-radius: 16px;
    padding: 13px 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    text-align: left;
    margin-top: 24px;
    margin-bottom: 22px;
    box-shadow: inset 0 1px 1px rgba(255, 255, 255, 0.05), 0 10px 20px rgba(0, 0, 0, 0.4);
}

.sync-icon-box {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    background: rgba(0, 245, 160, 0.08);
    border: 1.5px solid rgba(0, 245, 160, 0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--mint);
    box-shadow: 0 0 15px rgba(0, 245, 160, 0.2);
    font-size: 17px;
    flex-shrink: 0;
}

.sync-info {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.sync-title {
    font-size: 13.5px;
    font-weight: 700;
    color: var(--text-main);
    line-height: 1.3;
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    transition: opacity 0.2s ease;
}

.sync-desc {
    font-size: 11.5px;
    color: var(--text-muted);
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    transition: opacity 0.2s ease;
}

/* ── PROGRESS SECTION ── */
.progress-section {
    width: 100%;
}

.step-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--mint-dim);
    letter-spacing: 0.02em;
    margin-bottom: 12px;
    transition: opacity 0.2s ease;
}

.progress-track {
    width: 100%;
    height: 4px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 99px;
    overflow: hidden;
    margin-bottom: 12px;
    position: relative;
}

.progress-bar {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, #00d486 0%, #00f5a0 100%);
    border-radius: 99px;
    box-shadow: 0 0 12px rgba(0, 245, 160, 0.9);
    transition: width 0.08s linear;
}

.progress-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.progress-meta .meta-left {
    color: var(--text-muted);
    font-size: 11px;
}

.progress-meta .meta-right {
    color: var(--mint);
    font-size: 12px;
    font-weight: 800;
    font-family: 'Plus Jakarta Sans', monospace, sans-serif;
}
</style>
</head>
<body>

<div class="bg-scene"></div>

<div class="page">
    <div class="loading-card" id="card">
        
        <!-- Top Pill Badge -->
        <div class="top-pill">
            <div class="pill-brand">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8h1a4 4 0 0 1 0 8h-1"></path>
                    <path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"></path>
                    <line x1="6" y1="1" x2="6" y2="4"></line>
                    <line x1="10" y1="1" x2="10" y2="4"></line>
                    <line x1="14" y1="1" x2="14" y2="4"></line>
                </svg>
                <span>Bird's Nest Coffee</span>
            </div>
            <span class="role-badge"><?= htmlspecialchars($badge) ?></span>
        </div>

        <!-- Radar & Shield Core -->
        <div class="radar-wrapper">
            <div class="radar-outer-ring"></div>
            <div class="radar-mid-ring"></div>
            <div class="shield-core">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    <polyline points="9 12 11 14 15 10"></polyline>
                </svg>
            </div>
        </div>

        <!-- Title & Subtitle -->
        <h1 class="main-title"><?= htmlspecialchars($title) ?></h1>
        <div class="greeting-text"><?= htmlspecialchars($greeting) ?>, <strong><?= htmlspecialchars($username) ?></strong></div>
        <div class="tagline-badge">
            <span class="status-dot"></span>
            <span id="taglineText"><?= htmlspecialchars($tagline) ?></span>
        </div>

        <!-- Sync Task Status Card -->
        <div class="sync-card">
            <div class="sync-icon-box">
                <i class="fa-solid fa-microchip"></i>
            </div>
            <div class="sync-info">
                <div class="sync-title" id="syncTitle"><?= htmlspecialchars($sync_title) ?></div>
                <div class="sync-desc" id="syncDesc"><?= htmlspecialchars($sync_desc) ?></div>
            </div>
        </div>

        <!-- Progress Bar & Status -->
        <div class="progress-section">
            <div class="step-label" id="stepLabel">Connection verified — Initializing environment</div>
            <div class="progress-track">
                <div class="progress-bar" id="progressBar"></div>
            </div>
            <div class="progress-meta">
                <span class="meta-left" id="metaStatus">ALMOST READY</span>
                <span class="meta-right" id="pctText">0%</span>
            </div>
        </div>

    </div>
</div>

<script>
const dest = <?= json_encode($dest) ?>;

const progressBar = document.getElementById('progressBar');
const pctText     = document.getElementById('pctText');
const stepLabel   = document.getElementById('stepLabel');
const syncTitle   = document.getElementById('syncTitle');
const syncDesc    = document.getElementById('syncDesc');
const metaStatus  = document.getElementById('metaStatus');
const card        = document.getElementById('card');

// Preload cache in background if available
fetch('api/preload.php')
    .then(r => r.ok ? r.json() : null)
    .then(d => { if (d) try { sessionStorage.setItem('cafe_preload', JSON.stringify(d)); } catch(_) {} })
    .catch(() => {});

// Smooth progress simulation
function animateProgress(durationMs = 1700) {
    return new Promise(resolve => {
        const startTime = performance.now();
        
        function update(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / durationMs, 1);
            
            // Custom easing curve (accelerates and slows near 90-100%)
            const ease = progress < 0.7 
                ? Math.pow(progress / 0.7, 1.2) * 0.75 
                : 0.75 + Math.pow((progress - 0.7) / 0.3, 0.8) * 0.25;
            
            const currentPct = Math.round(ease * 100);
            
            progressBar.style.width = currentPct + '%';
            pctText.textContent = currentPct + '%';
            
            // Contextual step messages
            if (currentPct < 30) {
                stepLabel.textContent = 'Verifying credentials & secure tokens...';
                metaStatus.textContent = 'CONNECTING';
            } else if (currentPct < 70) {
                stepLabel.textContent = 'Syncing store catalog & prices...';
                metaStatus.textContent = 'SYNCING';
            } else if (currentPct < 95) {
                stepLabel.textContent = 'Connection verified — Initializing environment';
                metaStatus.textContent = 'ALMOST READY';
            } else {
                stepLabel.textContent = 'Ready — Launching portal...';
                metaStatus.textContent = 'INITIALIZED';
            }

            if (progress < 1) {
                requestAnimationFrame(update);
            } else {
                progressBar.style.width = '100%';
                pctText.textContent = '100%';
                resolve();
            }
        }
        
        requestAnimationFrame(update);
    });
}

async function startLoading() {
    await animateProgress(1650);
    
    // Brief completion pause
    await new Promise(r => setTimeout(r, 220));
    
    // Fade out card and redirect
    card.classList.add('fade-exit');
    setTimeout(() => {
        window.location.href = dest;
    }, 380);
}

document.addEventListener('DOMContentLoaded', startLoading);
</script>
</body>
</html>
