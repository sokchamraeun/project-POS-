<?php
session_start();
require 'config.php';

if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$role     = $_SESSION['role'] ?? 'staff';
$username = $_SESSION['username'] ?? 'User';

$gold = '#d1904b';
$glow = 'rgba(209,144,75,0.12)';

$roles = [
    'admin' => [
        'speed'     => 'normal',
        'icon'      => 'fa-user-shield',
        'color'     => $gold,
        'glow'      => $glow,
        'title'     => 'Admin Console',
        'portal'    => 'Admin Portal',
        'tagline'   => 'Full system access granted.',
        'bar_label' => 'Setting up your console',
        'dest'      => 'menu.php',
        'duration'  => '1.8s',
        'features'  => ['Manage staff, roles & permissions', 'System-wide settings & analytics', 'Full order & payment control'],
    ],
    'manager' => [
        'speed'     => 'normal',
        'icon'      => 'fa-user-tie',
        'color'     => $gold,
        'glow'      => $glow,
        'title'     => 'Manager Portal',
        'portal'    => 'Manager Portal',
        'tagline'   => 'Shift overview is ready.',
        'bar_label' => 'Loading operations hub',
        'dest'      => 'menu.php',
        'duration'  => '1.6s',
        'features'  => ['Real-time sales monitoring', 'Reports & daily analytics', 'Staff & inventory oversight'],
    ],
    'staff' => [
        'speed'     => 'skip',
        'icon'      => 'fa-cash-register',
        'color'     => $gold,
        'glow'      => $glow,
        'title'     => 'Cashier Station',
        'portal'    => 'Cashier Portal',
        'tagline'   => 'Ready to take orders.',
        'bar_label' => 'Preparing your station',
        'dest'      => 'menu.php',
        'duration'  => '1.4s',
        'features'  => ['Menu & order management', 'Payment processing', 'Loyalty card handling'],
    ],
    'barista' => [
        'speed'     => 'skip',
        'icon'      => 'fa-mug-hot',
        'color'     => $gold,
        'glow'      => $glow,
        'title'     => 'Kitchen Display',
        'portal'    => 'Barista Station',
        'tagline'   => 'Orders coming right up.',
        'bar_label' => 'Firing up the queue',
        'dest'      => 'view_order.php',
        'duration'  => '1.4s',
        'features'  => ['Live order queue', 'Drink recipe reference', 'Order status updates'],
    ],
    'supervisor' => [
        'speed'     => 'normal',
        'icon'      => 'fa-user-check',
        'color'     => $gold,
        'glow'      => $glow,
        'title'     => 'Supervisor View',
        'portal'    => 'Supervisor Portal',
        'tagline'   => 'Shift oversight active.',
        'bar_label' => 'Activating shift view',
        'dest'      => 'menu.php',
        'duration'  => '1.5s',
        'features'  => ['Staff attendance tracking', 'Inventory & stock overview', 'Operational order control'],
    ],
    'inventory_clerk' => [
        'speed'     => 'medium',
        'icon'      => 'fa-box-open',
        'color'     => $gold,
        'glow'      => $glow,
        'title'     => 'Inventory Hub',
        'portal'    => 'Inventory Portal',
        'tagline'   => 'Stock levels syncing.',
        'bar_label' => 'Syncing stock data',
        'dest'      => 'menu.php',
        'duration'  => '1.4s',
        'features'  => ['Ingredient stock levels', 'Purchase order management', 'Supplier coordination'],
    ],
];

$cfg       = $roles[$role] ?? $roles['staff'];
$speed     = $cfg['speed'] ?? 'normal';
$color     = $cfg['color'];
$glow      = $cfg['glow'];
$icon      = $cfg['icon'];
$title     = $cfg['title'];
$portal    = $cfg['portal'];
$tagline   = $cfg['tagline'];
$bar_label = $cfg['bar_label'];
$dest      = $cfg['dest'];
$duration  = $cfg['duration'];
$features  = $cfg['features'];

if ($speed === 'skip') {
    header("Location: " . $dest);
    exit;
}

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
<title>Loading — Bird's Nest Coffee</title>
<script>if (localStorage.getItem('theme') === 'light') document.documentElement.setAttribute('data-theme','light');</script>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --accent: <?= $color ?>;
    --glow:   <?= $glow ?>;
    --bg:     #0b0b0b;
    --surface:#111111;
    --border: #1f1f1f;
    --text:   #f0f0f0;
    --muted:  #666;
}

[data-theme="light"] {
    --bg:     #ECEEF2;
    --surface:#FFFFFF;
    --border: #E2E5EA;
    --text:   #111827;
    --muted:  #5A6373;
}
[data-theme="light"] .grid-lines {
    background-image:
        linear-gradient(rgba(0,0,0,0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0,0,0,0.04) 1px, transparent 1px);
}
[data-theme="light"] .orb-1,
[data-theme="light"] .orb-2 { opacity: 0.5; }

html, body {
    height: 100%;
    background: var(--bg);
    font-family: 'Poppins', sans-serif;
    color: var(--text);
    overflow: hidden;
}

/* ── Background glow orbs ── */
.bg-orb {
    position: fixed;
    border-radius: 50%;
    filter: blur(90px);
    pointer-events: none;
    z-index: 0;
}
.orb-1 {
    width: 600px; height: 600px;
    top: -150px; left: 50%;
    transform: translateX(-50%);
    background: var(--glow);
    animation: orb-drift 8s ease-in-out infinite alternate;
}
.orb-2 {
    width: 400px; height: 400px;
    bottom: -100px; right: -100px;
    background: var(--glow);
    opacity: 0.5;
    animation: orb-drift 10s ease-in-out infinite alternate-reverse;
}

@keyframes orb-drift {
    from { transform: translateX(-50%) translateY(0); }
    to   { transform: translateX(-50%) translateY(30px); }
}

/* ── Grid lines ── */
.grid-lines {
    position: fixed; inset: 0; z-index: 0;
    background-image:
        linear-gradient(rgba(255,255,255,0.015) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,0.015) 1px, transparent 1px);
    background-size: 60px 60px;
    pointer-events: none;
}

/* ── Main layout ── */
.wrapper {
    position: relative; z-index: 1;
    height: 100vh;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    gap: 0;
    animation: fade-in 0.6s ease forwards;
}

@keyframes fade-in {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── Brand header ── */
.brand {
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 48px;
    opacity: 0;
    animation: fade-in 0.5s ease 0.2s forwards;
}
.brand-icon {
    width: 36px; height: 36px;
    background: rgba(255,255,255,0.06);
    border: 1px solid var(--border);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; color: var(--accent);
}
.brand-text { font-size: 13px; color: var(--muted); font-weight: 500; letter-spacing: 0.03em; }
.brand-dot  { color: var(--border); }
.brand-portal { color: var(--accent); }

/* ── Progress ring ── */
.ring-wrap {
    position: relative;
    width: 140px; height: 140px;
    margin-bottom: 32px;
    opacity: 0;
    animation: fade-in 0.5s ease 0.3s forwards;
}
.ring-svg {
    position: absolute; inset: 0;
    transform: rotate(-90deg);
}
.ring-track {
    fill: none;
    stroke: var(--border);
    stroke-width: 3;
}
.ring-progress {
    fill: none;
    stroke: var(--accent);
    stroke-width: 3;
    stroke-linecap: round;
    stroke-dasharray: 376.99;
    stroke-dashoffset: 376.99;
    filter: drop-shadow(0 0 6px var(--accent));
}

/* Idle spinner — shown before JS progress starts */
.ring-spinner {
    fill: none;
    stroke: var(--accent);
    stroke-width: 3;
    stroke-linecap: round;
    stroke-dasharray: 70 306.99;
    opacity: 0.35;
    transform-origin: 65px 65px;
    transform-box: fill-box;
    animation: spinner-rotate 1.1s linear infinite;
}
@keyframes spinner-rotate {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}

.ring-inner {
    position: absolute; inset: 14px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    flex-direction: column;
    gap: 4px;
}
.ring-icon {
    font-size: 32px;
    color: var(--accent);
    animation: icon-pulse 2s ease-in-out 0.5s infinite;
}
@keyframes icon-pulse {
    0%, 100% { transform: scale(1);   opacity: 1; }
    50%       { transform: scale(1.08); opacity: 0.85; }
}

/* ── Text block ── */
.text-block {
    text-align: center;
    margin-bottom: 36px;
    opacity: 0;
    animation: fade-in 0.5s ease 0.4s forwards;
}
.role-title {
    font-size: 26px; font-weight: 700;
    letter-spacing: -0.02em;
    color: var(--text);
    margin-bottom: 6px;
}
.greeting-line {
    font-size: 13px; color: var(--muted);
    margin-bottom: 8px;
}
.greeting-line span { color: var(--text); font-weight: 500; }
.tagline {
    font-size: 13px;
    color: var(--accent);
    font-weight: 500;
    letter-spacing: 0.02em;
}

/* ── Features ── */
.features {
    display: flex; flex-direction: column; gap: 10px;
    margin-bottom: 40px;
    width: 280px;
}
.feature-item {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 16px;
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--border);
    border-radius: 10px;
    font-size: 13px; color: var(--muted);
    opacity: 0;
    transform: translateX(-8px);
    transition: border-color 0.3s, color 0.3s;
}
.feature-item.visible {
    animation: feature-in 0.4s ease forwards;
}
@keyframes feature-in {
    from { opacity: 0; transform: translateX(-8px); }
    to   { opacity: 1; transform: translateX(0); }
}
.feature-item i {
    font-size: 12px;
    color: var(--accent);
    width: 14px; text-align: center;
    flex-shrink: 0;
}

/* ── Error message ── */
.error-msg {
    font-size: 11px;
    color: #e67e5a;
    text-align: center;
    margin-bottom: 10px;
    letter-spacing: 0.03em;
    opacity: 0;
    height: 0;
    transition: opacity 0.4s, height 0.3s;
}
.error-msg.show { opacity: 1; height: 18px; }

/* ── Progress bar ── */
.bar-wrap {
    width: 280px;
    opacity: 0;
    animation: fade-in 0.4s ease 0.1s forwards;
}
.bar-label {
    display: flex; justify-content: space-between;
    font-size: 11px; color: var(--muted);
    margin-bottom: 8px;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.bar-track {
    height: 3px;
    background: var(--border);
    border-radius: 99px;
    overflow: hidden;
}
.bar-fill {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, var(--accent), color-mix(in srgb, var(--accent) 60%, white));
    border-radius: 99px;
    box-shadow: 0 0 8px var(--accent);
}

/* ── Fade-out ── */
.wrapper.fade-out {
    animation: fade-out 0.5s ease forwards;
}
@keyframes fade-out {
    from { opacity: 1; }
    to   { opacity: 0; }
}
</style>
</head>
<body>
<div class="bg-orb orb-1"></div>
<div class="bg-orb orb-2"></div>
<div class="grid-lines"></div>

<div class="wrapper" id="wrapper">

    <div class="brand">
        <div class="brand-icon"><i class="fa-solid fa-mug-saucer"></i></div>
        <span class="brand-text">Bird's Nest Coffee <span class="brand-dot">·</span> <span class="brand-portal"><?= htmlspecialchars($portal) ?></span></span>
    </div>

    <div class="ring-wrap">
        <svg class="ring-svg" viewBox="0 0 130 130">
            <circle class="ring-track"    cx="65" cy="65" r="60"/>
            <circle class="ring-spinner"  id="ringSpinner" cx="65" cy="65" r="60"/>
            <circle class="ring-progress" id="ringProgress" cx="65" cy="65" r="60"/>
        </svg>
        <div class="ring-inner">
            <i class="fa-solid <?= $icon ?> ring-icon"></i>
        </div>
    </div>

    <div class="text-block">
        <div class="role-title"><?= htmlspecialchars($title) ?></div>
        <div class="greeting-line"><?= $greeting ?>, <span><?= htmlspecialchars($username) ?></span></div>
        <div class="tagline"><?= htmlspecialchars($tagline) ?></div>
    </div>

    <div class="features" id="features">
        <?php foreach ($features as $f): ?>
        <div class="feature-item">
            <i class="fa-solid fa-check"></i>
            <?= htmlspecialchars($f) ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="error-msg" id="errorMsg"></div>

    <div class="bar-wrap">
        <div class="bar-label">
            <span id="barLabel"><?= htmlspecialchars($bar_label) ?></span>
            <span id="pct">0%</span>
        </div>
        <div class="bar-track"><div class="bar-fill" id="barFill"></div></div>
    </div>

</div>

<script>
const dest      = <?= json_encode($dest) ?>;
const initLabel = <?= json_encode($bar_label) ?>;
const role      = <?= json_encode($role) ?>;
const speed     = <?= json_encode($speed) ?>;

const pctEl   = document.getElementById('pct');
const barFill = document.getElementById('barFill');
const barLabel= document.getElementById('barLabel');
const ringEl  = document.getElementById('ringProgress');
const spinEl  = document.getElementById('ringSpinner');
const errEl   = document.getElementById('errorMsg');
const DASH    = 376.99;

// Stagger feature items in
document.querySelectorAll('.feature-item').forEach((el, i) => {
    setTimeout(() => el.classList.add('visible'), 250 + i * 210);
});

// Animate bar + ring + counter in sync
function smoothBar(from, to, ms) {
    return new Promise(resolve => {
        const t0 = performance.now();
        function tick(now) {
            const p    = Math.min((now - t0) / ms, 1);
            const ease = p < 0.5 ? 2*p*p : -1 + (4 - 2*p)*p;
            const val  = Math.round(from + (to - from) * ease);
            barFill.style.width           = val + '%';
            pctEl.textContent             = val + '%';
            ringEl.style.strokeDashoffset = DASH * (1 - val / 100);
            if (p < 1) requestAnimationFrame(tick);
            else resolve();
        }
        requestAnimationFrame(tick);
    });
}

function wait(ms) { return new Promise(r => setTimeout(r, ms)); }

// Update tagline with live data — shown data is gated by role
function updateTagline(data) {
    if (!data) return;
    let text = '';

    if (role === 'barista') {
        text = data.queue_count > 0
            ? data.queue_count + ' order' + (data.queue_count !== 1 ? 's' : '') + ' in queue'
            : 'Queue is clear — ready to go';
    } else if (role === 'inventory_clerk') {
        text = data.low_stock > 0
            ? data.low_stock + ' low stock alert' + (data.low_stock !== 1 ? 's' : '')
            : 'All stock levels normal';
    } else if (role === 'admin' || role === 'manager' || role === 'supervisor') {
        const sales = '$' + parseFloat(data.sales_today || 0).toFixed(2);
        text = data.active_orders + ' active · ' + sales + ' today';
    }
    // staff (cashier): tagline stays as-is — no revenue or analytics exposure

    if (!text) return;
    const taglineEl = document.querySelector('.tagline');
    taglineEl.style.transition = 'opacity 0.25s';
    taglineEl.style.opacity = '0';
    setTimeout(() => { taglineEl.textContent = text; taglineEl.style.opacity = '1'; }, 260);
}

async function run() {
    // ── Fast: cashier (<1s total) ──────────────────────────────────────────
    if (speed === 'fast') {
        spinEl.style.display = 'none';
        // Fire preload in background — don't block redirect on it
        fetch('api/preload.php')
            .then(r => r.ok ? r.json() : null)
            .then(d => { if (d) try { sessionStorage.setItem('cafe_preload', JSON.stringify(d)); } catch(_) {} })
            .catch(() => {});
        barLabel.textContent = initLabel;
        await smoothBar(0, 100, 500);
        document.getElementById('wrapper').classList.add('fade-out');
        setTimeout(() => { window.location.href = dest; }, 300);
        return;
    }

    // ── Medium: inventory (~1.5s total) ───────────────────────────────────
    if (speed === 'medium') {
        spinEl.style.display = 'none';
        barLabel.textContent = initLabel;
        await smoothBar(0, 25, 200);

        barLabel.textContent = 'Loading your data';
        const fetchMed = fetch('api/preload.php')
            .then(r => r.ok ? r.json() : Promise.reject(new Error('server')))
            .then(data => {
                try { sessionStorage.setItem('cafe_preload', JSON.stringify(data)); } catch(_) {}
                updateTagline(data);
            })
            .catch(() => {
                errEl.textContent = 'Connection issue — continuing anyway';
                errEl.classList.add('show');
            });

        await Promise.all([fetchMed, smoothBar(25, 85, 600), wait(600)]);

        barLabel.textContent = 'Almost ready';
        await smoothBar(85, 100, 200);
        await wait(100);

        document.getElementById('wrapper').classList.add('fade-out');
        setTimeout(() => { window.location.href = dest; }, 400);
        return;
    }

    // ── Normal: admin / manager / supervisor ──────────────────────────────
    await wait(600);
    spinEl.style.display = 'none';

    barLabel.textContent = initLabel;
    await smoothBar(0, 18, 350);
    await wait(350);

    barLabel.textContent = 'Loading your data';
    const fetchDone = fetch('api/preload.php')
        .then(r => r.ok ? r.json() : Promise.reject(new Error('server')))
        .then(data => {
            try { sessionStorage.setItem('cafe_preload', JSON.stringify(data)); } catch(_) {}
            updateTagline(data);
        })
        .catch(() => {
            errEl.textContent = 'Connection issue — continuing anyway';
            errEl.classList.add('show');
        });

    await Promise.all([fetchDone, smoothBar(18, 76, 700), wait(700)]);
    await wait(200);

    barLabel.textContent = 'Almost ready';
    await smoothBar(76, 100, 450);
    await wait(350);

    document.getElementById('wrapper').classList.add('fade-out');
    setTimeout(() => { window.location.href = dest; }, 500);
}

run();
</script>
</body>
</html>
