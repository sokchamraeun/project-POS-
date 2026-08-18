<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

date_default_timezone_set('Asia/Phnom_Penh');
$now = new DateTime();
$business_date = (int)$now->format('H') < 6
    ? (clone $now)->modify('-1 day')->format('Y-m-d')
    : $now->format('Y-m-d');

// ── AJAX: return JSON ──
if (isset($_GET['ajax'])) {
    $stmt = $conn->prepare("
        SELECT order_id, daily_order_no, customer_name, status
        FROM orders
        WHERE business_date = ?
          AND status IN ('Preparing','Completed')
        ORDER BY CASE status WHEN 'Preparing' THEN 1 WHEN 'Completed' THEN 2 END, order_id ASC
        LIMIT 24
    ");
    $stmt->bind_param('s', $business_date);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Order Status — Bird's Nest Coffee</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script>(function(){try{if(localStorage.getItem("theme")==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
<style>
body, input, select, textarea, button {
  font-family:'Poppins', 'Kantumruy Pro', 'Siemreap', 'Noto Sans Khmer', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}
:lang(km), [data-lang="km"], html[lang="km"] * {
  font-family:'Kantumruy Pro', 'Poppins', 'Siemreap', 'Noto Sans Khmer', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0a0a0a;
  --surface:#111;
  --border:rgba(255,255,255,.07);
  --amber:#d1904b;
  --amber-dim:rgba(209,144,75,.12);
  --amber-border:rgba(209,144,75,.25);
  --green:#22c55e;
  --green-dim:rgba(34,197,94,.12);
  --green-border:rgba(34,197,94,.25);
  --yellow:#f59e0b;
  --yellow-dim:rgba(245,158,11,.12);
  --yellow-border:rgba(245,158,11,.25);
  --text:#f0f0f0;
  --muted:#555;
  --muted2:#888;
  --r:18px;
}

@keyframes fadeInUp  {from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeOut   {from{opacity:1;transform:scale(1)}to{opacity:0;transform:scale(.88)}}
@keyframes readyPop  {0%,100%{transform:scale(1)}40%{transform:scale(1.06)}70%{transform:scale(.97)}}
@keyframes pulseDot  {0%,100%{opacity:1}50%{opacity:.3}}
@keyframes spin      {to{transform:rotate(360deg)}}

body{
  font-family:'Poppins',sans-serif;
  background:radial-gradient(ellipse 80% 35% at 50% 0%,rgba(209,144,75,.06) 0%,transparent 100%),var(--bg);
  color:var(--text);
  min-height:100vh;
  display:flex;
  flex-direction:column;
}

/* ── TOPBAR ── */
.topbar{
  display:flex;align-items:center;justify-content:space-between;
  padding:16px 28px;
  background:rgba(255,255,255,.02);
  border-bottom:1px solid var(--border);
  flex-shrink:0;
}
.topbar-brand{display:flex;align-items:center;gap:12px;}
.brand-icon{font-size:22px;color:var(--amber);}
.brand-name{font-size:18px;font-weight:800;color:var(--text);letter-spacing:-.02em;}
.brand-sub{font-size:11px;color:var(--muted2);margin-top:1px;}

.topbar-center{display:flex;align-items:center;gap:8px;}
.live-pill{
  display:flex;align-items:center;gap:7px;
  background:var(--green-dim);border:1px solid var(--green-border);
  color:var(--green);font-size:12px;font-weight:600;
  padding:5px 14px;border-radius:50px;
}
.live-dot{width:7px;height:7px;border-radius:50%;background:var(--green);animation:pulseDot 1.8s ease infinite;}

.topbar-right{display:flex;align-items:center;gap:14px;}
.clock{font-size:16px;font-weight:700;color:var(--amber);letter-spacing:.04em;}
.back-btn{
  display:inline-flex;align-items:center;gap:7px;padding:7px 14px;border-radius:10px;
  background:rgba(209,144,75,.08);border:1px solid rgba(209,144,75,.35);
  color:#d1904b;font-size:13px;font-weight:600;text-decoration:none;transition:all .2s;
}
.back-btn:hover{background:rgba(209,144,75,.16);border-color:#d1904b;}

/* ── LEGEND ── */
.legend{
  display:flex;align-items:center;justify-content:center;gap:20px;
  padding:12px 28px;border-bottom:1px solid var(--border);
  background:rgba(255,255,255,.01);flex-shrink:0;
}
.legend-item{display:flex;align-items:center;gap:7px;font-size:12px;font-weight:500;color:var(--muted2);}
.legend-dot{width:8px;height:8px;border-radius:50%;}
.legend-dot.preparing{background:var(--yellow);}
.legend-dot.ready{background:var(--green);}

/* ── GRID AREA ── */
.grid-area{
  flex:1;padding:24px 28px;
  overflow-y:auto;
}

.order-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(220px,1fr));
  gap:16px;
  max-width:1280px;
  margin:0 auto;
}

/* ── CARD ── */
.order-card{
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:var(--r);
  padding:28px 20px 22px;
  text-align:center;
  position:relative;overflow:hidden;
  transition:border-color .25s,transform .2s,opacity .25s;
}
.order-card:hover{transform:translateY(-2px);}

.order-card.preparing{
  border-color:var(--yellow-border);
  background:linear-gradient(160deg,rgba(245,158,11,.06) 0%,var(--surface) 60%);
}
.order-card.ready{
  border-color:var(--green-border);
  background:linear-gradient(160deg,rgba(34,197,94,.08) 0%,var(--surface) 60%);
}

/* glow line at top */
.order-card::before{
  content:'';
  position:absolute;top:0;left:10%;right:10%;
  height:2px;border-radius:0 0 4px 4px;
}
.order-card.preparing::before{background:var(--yellow);}
.order-card.ready::before{background:var(--green);}

.card-status-icon{
  font-size:28px;margin-bottom:10px;
}
.card-status-icon.preparing{color:var(--yellow);}
.card-status-icon.ready{color:var(--green);}

.card-number{
  font-size:52px;font-weight:800;line-height:1;
  margin-bottom:6px;
  letter-spacing:-.03em;
}
.order-card.preparing .card-number{color:var(--yellow);}
.order-card.ready    .card-number{color:var(--green);}

.card-name{
  font-size:14px;font-weight:500;color:var(--muted2);
  margin-bottom:14px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}

.card-badge{
  display:inline-flex;align-items:center;gap:6px;
  font-size:12px;font-weight:700;
  padding:6px 16px;border-radius:50px;
  text-transform:uppercase;letter-spacing:.4px;
}
.card-badge.preparing{
  background:var(--yellow-dim);color:var(--yellow);
  border:1px solid var(--yellow-border);
}
.card-badge.ready{
  background:var(--green-dim);color:var(--green);
  border:1px solid var(--green-border);
}

/* ready pop animation */
.order-card.pop{animation:readyPop .5s ease both;}
/* fade-out when removing */
.order-card.removing{animation:fadeOut .35s ease forwards;pointer-events:none;}

/* ── FILTER BAR ── */
.filter-bar{
  display:flex;align-items:center;justify-content:space-between;
  padding:10px 28px;border-bottom:1px solid var(--border);
  flex-shrink:0;
}
.filter-left{font-size:12px;color:var(--muted2);}
.toggle-btn{
  display:inline-flex;align-items:center;gap:7px;
  padding:7px 16px;border-radius:50px;
  background:rgba(255,255,255,.05);border:1px solid var(--border);
  color:var(--muted2);font-family:inherit;font-size:12px;font-weight:500;
  cursor:pointer;transition:all .2s;
}
.toggle-btn:hover{color:var(--text);border-color:var(--amber);}
.toggle-btn.active{background:var(--amber-dim);border-color:var(--amber-border);color:var(--amber);}

/* ── EMPTY STATE ── */
.empty-state{
  grid-column:1/-1;
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  min-height:40vh;gap:14px;color:var(--muted);text-align:center;
}
.empty-state i{font-size:60px;color:rgba(255,255,255,.05);}
.empty-state h3{font-size:18px;font-weight:600;color:rgba(255,255,255,.12);}
.empty-state p{font-size:13px;}

/* ── LOADING SPINNER ── */
.spinner{
  display:flex;align-items:center;justify-content:center;
  grid-column:1/-1;min-height:40vh;
}
.spinner i{font-size:28px;color:var(--amber);animation:spin 1s linear infinite;}
/* Light theme (follows shared localStorage theme) */
[data-theme="light"]{--bg:#ECEEF2;--surface:#FFFFFF;--border:#E2E5EA;--text:#111827;--muted:#5A6373;--muted2:#5A6373;}
</style>
</head>
<body>

<div class="topbar">
    <div class="topbar-brand">
        <i class="fa-solid fa-mug-hot brand-icon"></i>
        <div>
            <div class="brand-name">Bird's Nest Coffee</div>
            <div class="brand-sub">Order Status Board</div>
        </div>
    </div>
    <div class="topbar-center">
        <div class="live-pill">
            <span class="live-dot"></span>
            Live
        </div>
    </div>
    <div class="topbar-right">
        <div class="clock" id="clock">--:--</div>
        <a href="dashboard.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back</a>
    </div>
</div>

<div class="legend">
    <div class="legend-item"><span class="legend-dot preparing"></span> Preparing — Your order is being made</div>
    <div class="legend-item"><span class="legend-dot ready"></span> Ready — Please collect your order</div>
</div>

<div class="filter-bar">
    <div class="filter-left" id="orderCount">Loading…</div>
    <button class="toggle-btn" id="toggleBtn" onclick="toggleCompleted()">
        <i class="fa-solid fa-eye"></i> Show Completed
    </button>
</div>

<div class="grid-area">
    <div class="order-grid" id="orderGrid">
        <div class="spinner"><i class="fa-solid fa-spinner"></i></div>
    </div>
</div>

<script>
const readyAt       = new Map();  // order_id → JS ms when first became Completed
let   prevCompleted = new Set();
let   showCompleted = false;
const REMOVE_AFTER_MS = 30000;

function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function buildCard(o) {
    const prep  = o.status === 'Preparing';
    const cls   = prep ? 'preparing' : 'ready';
    const icon  = prep ? 'fa-fire-burner' : 'fa-circle-check';
    const label = prep ? '<i class="fa-solid fa-fire-burner"></i> Preparing' : '<i class="fa-solid fa-circle-check"></i> Ready!';
    return `<div class="order-card ${cls}" data-id="${esc(o.order_id)}" data-status="${esc(o.status)}">
        <div class="card-status-icon ${cls}"><i class="fa-solid ${icon}"></i></div>
        <div class="card-number">#${esc(o.daily_order_no)}</div>
        <div class="card-name">${esc(o.customer_name || 'Guest')}</div>
        <div class="card-badge ${cls}">${label}</div>
    </div>`;
}

// Patch an existing card's status in-place — zero flicker, no DOM removal
function patchCard(card, o) {
    const cls   = o.status === 'Preparing' ? 'preparing' : 'ready';
    const icon  = o.status === 'Preparing' ? 'fa-fire-burner' : 'fa-circle-check';
    const label = o.status === 'Preparing'
        ? '<i class="fa-solid fa-fire-burner"></i> Preparing'
        : '<i class="fa-solid fa-circle-check"></i> Ready!';
    card.dataset.status = o.status;
    card.className      = `order-card ${cls}`;
    const iconEl  = card.querySelector('.card-status-icon');
    const badgeEl = card.querySelector('.card-badge');
    if (iconEl)  { iconEl.className  = `card-status-icon ${cls}`; iconEl.innerHTML  = `<i class="fa-solid ${icon}"></i>`; }
    if (badgeEl) { badgeEl.className = `card-badge ${cls}`;       badgeEl.innerHTML = label; }
    card.classList.add('pop');
    setTimeout(() => card.classList.remove('pop'), 520);
}

function playBell() {
    try {
        const ctx  = new (window.AudioContext || window.webkitAudioContext)();
        const osc  = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain); gain.connect(ctx.destination);
        osc.frequency.setValueAtTime(880, ctx.currentTime);
        osc.frequency.exponentialRampToValueAtTime(660, ctx.currentTime + 0.3);
        gain.gain.setValueAtTime(0.4, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.6);
        osc.start(); osc.stop(ctx.currentTime + 0.6);
    } catch(e) {}
}

async function refresh() {
    try {
        const res  = await fetch('customer_display.php?ajax=1');
        const data = await res.json();
        const grid = document.getElementById('orderGrid');
        const now  = Date.now();

        // Remove initial spinner once
        grid.querySelector('.spinner')?.remove();

        // Track newly completed → bell
        const newCompleted = new Set();
        data.forEach(o => {
            if (o.status !== 'Completed') return;
            const id = String(o.order_id);
            newCompleted.add(id);
            if (!prevCompleted.has(id)) { readyAt.set(id, now); playBell(); }
        });
        prevCompleted = newCompleted;

        // Expired Ready cards (shown > 30s)
        const removeIds = new Set();
        readyAt.forEach((ts, id) => { if (now - ts > REMOVE_AFTER_MS) removeIds.add(id); });

        const incomingIds = new Set(data.map(o => String(o.order_id)));

        // Fade-out cards that left or expired — don't touch anything else
        grid.querySelectorAll('.order-card').forEach(card => {
            const gone = !incomingIds.has(card.dataset.id) || removeIds.has(card.dataset.id);
            if (gone && !card.classList.contains('removing')) {
                card.classList.add('removing');
                setTimeout(() => card.remove(), 360);
            }
        });

        const visible = data.filter(o => !removeIds.has(String(o.order_id)));

        visible.forEach((o, idx) => {
            const id       = String(o.order_id);
            const existing = grid.querySelector(`.order-card[data-id="${id}"]`);
            if (existing) {
                // Only touch the card if its status actually changed
                if (existing.dataset.status !== o.status) patchCard(existing, o);
            } else {
                // Brand-new card — fade in quietly
                const tmp = document.createElement('div');
                tmp.innerHTML = buildCard(o);
                const card = tmp.firstElementChild;
                card.style.opacity = '0';
                if (!showCompleted && o.status === 'Completed') card.style.display = 'none';
                grid.appendChild(card);
                requestAnimationFrame(() => { card.style.opacity = '1'; });
            }
        });

        // Show empty state only after removing cards have animated out
        if (visible.length === 0) {
            setTimeout(() => {
                if (!grid.querySelector('.order-card')) {
                    grid.innerHTML = `<div class="empty-state">
                        <i class="fa-solid fa-mug-hot"></i>
                        <h3>All clear!</h3>
                        <p>No active orders right now.</p>
                    </div>`;
                }
            }, 400);
        }

        const nPrep  = visible.filter(o => o.status === 'Preparing').length;
        const nReady = visible.filter(o => o.status === 'Completed').length;
        document.getElementById('orderCount').textContent = `${nPrep} preparing · ${nReady} ready`;

        removeIds.forEach(id => readyAt.delete(id));
    } catch(e) {}
}

function applyToggle() {
    document.querySelectorAll('.order-card[data-status="Completed"]').forEach(card => {
        card.style.display = showCompleted ? '' : 'none';
    });
}

function toggleCompleted() {
    showCompleted = !showCompleted;
    const btn = document.getElementById('toggleBtn');
    btn.classList.toggle('active', showCompleted);
    btn.innerHTML = showCompleted
        ? '<i class="fa-solid fa-eye-slash"></i> Hide Completed'
        : '<i class="fa-solid fa-eye"></i> Show Completed';
    applyToggle();
}

function tick() {
    document.getElementById('clock').textContent =
        new Date().toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true});
}
tick(); setInterval(tick, 1000);

refresh(); setInterval(refresh, 3000);
</script>
</body>
</html>
