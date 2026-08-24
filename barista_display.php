<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if (!can('barista_station')) {
    header('Location: dashboard.php?denied=1');
    exit;
}

date_default_timezone_set("Asia/Phnom_Penh");
$now = new DateTime();
$business_date = (int)$now->format("H") < 6
    ? (clone $now)->modify("-1 day")->format("Y-m-d")
    : $now->format("Y-m-d");

// ── AJAX: return preparing orders as JSON ──
if (isset($_GET['ajax'])) {
    $stmt = $conn->prepare("
        SELECT o.order_id, o.daily_order_no, o.customer_name, o.order_date,
               oi.product_name, oi.quantity, oi.sweetness, oi.ice, oi.milk, oi.size_label, oi.addons_snapshot
        FROM orders o
        LEFT JOIN order_items oi ON o.order_id = oi.order_id
        WHERE o.business_date = ? AND o.status = 'Preparing'
        ORDER BY o.order_id ASC, oi.item_id ASC
    ");
    $stmt->bind_param("s", $business_date);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $orders = [];
    foreach ($rows as $r) {
        $oid = $r['order_id'];
        if (!isset($orders[$oid])) {
            $orders[$oid] = [
                'order_id'       => $oid,
                'daily_order_no' => $r['daily_order_no'],
                'customer_name'  => $r['customer_name'] ?: 'Guest',
                'order_date'     => $r['order_date'],
                'items'          => [],
            ];
        }
        if ($r['product_name']) {
            $orders[$oid]['items'][] = [
                'name'      => $r['product_name'],
                'qty'       => (int)$r['quantity'],
                'size'      => $r['size_label']  ?? null,
                'sweetness' => $r['sweetness'] ?? null,
                'ice'       => $r['ice']       ?? null,
                'milk'      => $r['milk']      ?? null,
                'addons'    => array_map(fn($a) => $a['name'], json_decode($r['addons_snapshot'] ?? '[]', true) ?: []),
            ];
        }
    }

    header('Content-Type: application/json');
    echo json_encode(array_values($orders));
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>" data-lang="<?= current_lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Barista Station</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script>(function(){try{if(localStorage.getItem("theme")==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap');

body, input, select, textarea, button, table {
  font-family:'Poppins', 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}
:lang(km), [data-lang="km"], html[lang="km"], html[lang="km"] * {
  font-family:'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', 'Khmer OS Siemreap', 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
}
html[lang="km"] .fa, html[lang="km"] [class*="fa-"], html[lang="km"] i {
  font-family: 'Font Awesome 6 Free', 'FontAwesome' !important;
}
html[lang="km"] .fa-brands, html[lang="km"] [class*="fa-brands"] {
  font-family: 'Font Awesome 6 Brands', 'FontAwesome' !important;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}

:root{
  --bg:#080808;
  --surface:#111111;
  --border:rgba(255,255,255,.07);
  --amber:#d1904b;
  --amber-dim:rgba(209,144,75,.12);
  --green:#22c55e;
  --green-dim:rgba(34,197,94,.12);
  --text:#f0f0f0;
  --muted:#555;
  --red:#ef4444;
}

@keyframes slideIn  { from{opacity:0;transform:translateY(18px)} to{opacity:1;transform:translateY(0)} }
@keyframes fadeOut  { from{opacity:1;transform:scale(1)}         to{opacity:0;transform:scale(.92)}    }
@keyframes pulseDot { 0%,100%{opacity:1} 50%{opacity:.3} }
@keyframes blink    { 0%,100%{opacity:1} 50%{opacity:.5} }

body{
  font-family:'Poppins',sans-serif;
  background:var(--bg);
  color:var(--text);
  min-height:100vh;
  display:flex;
  flex-direction:column;
}

/* ── Top bar ── */
.topbar{
  display:flex;align-items:center;justify-content:space-between;
  padding:14px 24px;
  background:rgba(255,255,255,.025);
  border-bottom:1px solid var(--border);
  flex-shrink:0;
}
.topbar-left{display:flex;align-items:center;gap:12px;}
.topbar-logo{font-size:20px;font-weight:800;color:var(--amber);letter-spacing:-.02em;}
.topbar-tag{font-size:11px;color:var(--muted);font-weight:400;letter-spacing:.5px;text-transform:uppercase;}
.live-dot{
  width:8px;height:8px;border-radius:50%;
  background:var(--green);
  animation:pulseDot 1.8s ease infinite;
  flex-shrink:0;
}
.queue-badge{
  display:flex;align-items:center;gap:7px;
  background:var(--amber-dim);
  border:1px solid rgba(209,144,75,.2);
  border-radius:20px;padding:5px 14px;
  font-size:13px;font-weight:600;color:var(--amber);
}
.queue-badge span{font-size:18px;font-weight:800;}
.topbar-right{display:flex;align-items:center;gap:16px;}
.clock{font-size:15px;font-weight:700;color:var(--text);letter-spacing:.03em;}
.back-btn{
  display:inline-flex;align-items:center;gap:7px;
  padding:7px 14px;border-radius:10px;
  background:rgba(209,144,75,.08);
  border:1px solid rgba(209,144,75,.35);
  color:#d1904b;font-size:13px;font-weight:600;
  text-decoration:none;transition:all .2s;
}
.back-btn:hover{background:rgba(209,144,75,.16);border-color:#d1904b;}

/* ── Queue area ── */
.queue{
  flex:1;padding:24px;
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(280px,1fr));
  gap:16px;
  align-content:start;
  overflow-y:auto;
}

/* ── Ticket card ── */
.ticket{
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:18px;
  overflow:hidden;
  display:flex;flex-direction:column;
  animation:slideIn .35s cubic-bezier(.16,1,.3,1) both;
  transition:border-color .2s,transform .2s;
}
.ticket:hover{border-color:rgba(255,255,255,.13);transform:translateY(-2px);}
.ticket.completing{pointer-events:none;animation:fadeOut .4s ease forwards;}

/* Ticket header */
.ticket-head{
  display:flex;align-items:center;justify-content:space-between;
  padding:14px 16px 10px;
  border-bottom:1px solid var(--border);
}
.ticket-no{font-size:26px;font-weight:800;color:var(--amber);line-height:1;}
.ticket-meta{text-align:right;}
.ticket-customer{font-size:13px;font-weight:600;color:var(--text);}
.ticket-age{font-size:11px;color:var(--muted);margin-top:2px;}

/* Items list */
.ticket-items{flex:1;padding:12px 16px;display:flex;flex-direction:column;gap:8px;max-height:260px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:rgba(209,144,75,.35) transparent;}
.ticket-items::-webkit-scrollbar{width:4px;}
.ticket-items::-webkit-scrollbar-track{background:transparent;}
.ticket-items::-webkit-scrollbar-thumb{background:rgba(209,144,75,.35);border-radius:99px;}
.ticket-items::-webkit-scrollbar-thumb:hover{background:var(--amber);}
.ticket-item{
  display:flex;gap:10px;align-items:flex-start;
  padding:8px 10px;border-radius:10px;
  background:rgba(255,255,255,.03);
}
.item-qty{
  min-width:26px;height:26px;border-radius:7px;
  background:var(--amber-dim);color:var(--amber);
  font-size:12px;font-weight:700;
  display:flex;align-items:center;justify-content:center;
  flex-shrink:0;
}
.item-body{flex:1;min-width:0;}
.item-name{font-size:13px;font-weight:600;color:var(--text);line-height:1.3;}
.item-mods{display:flex;flex-wrap:wrap;gap:4px;margin-top:5px;}
.mod{
  font-size:10px;font-weight:500;
  padding:2px 7px;border-radius:10px;
  background:rgba(255,255,255,.06);color:#888;
}

/* Done button */
.ticket-foot{padding:12px 16px;}
.done-btn{
  width:100%;padding:11px;border-radius:12px;border:none;
  background:var(--green-dim);
  color:var(--green);
  border:1px solid rgba(34,197,94,.2);
  font-family:inherit;font-size:14px;font-weight:700;
  cursor:pointer;transition:all .2s;
  display:flex;align-items:center;justify-content:center;gap:8px;
}
.done-btn:hover{background:rgba(34,197,94,.2);border-color:rgba(34,197,94,.4);transform:translateY(-1px);}
.done-btn:active{transform:scale(.98);}

/* Error toast (failed Done) */
.bd-toast{
  position:fixed;bottom:24px;left:50%;transform:translateX(-50%);
  display:flex;align-items:center;gap:10px;
  background:#2a1414;border:1px solid rgba(239,68,68,.4);color:#ff8a8a;
  padding:13px 20px;border-radius:12px;font-size:14px;font-weight:600;
  z-index:10000;box-shadow:0 8px 30px rgba(0,0,0,.5);
  animation:bdToastIn .3s ease both;
}
.bd-toast i{font-size:16px;}
.bd-toast-out{animation:bdToastOut .35s ease forwards;}
@keyframes bdToastIn{from{opacity:0;transform:translate(-50%,16px)}to{opacity:1;transform:translate(-50%,0)}}
@keyframes bdToastOut{to{opacity:0;transform:translate(-50%,12px)}}

/* ── Empty state ── */
.empty{
  grid-column:1/-1;
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  min-height:50vh;gap:14px;color:var(--muted);text-align:center;
}
.empty i{font-size:56px;color:rgba(255,255,255,.06);}
.empty h2{font-size:20px;font-weight:600;color:rgba(255,255,255,.15);}
.empty p{font-size:13px;}
/* Light theme (follows shared localStorage theme) */
[data-theme="light"]{--bg:#ECEEF2;--surface:#FFFFFF;--border:#E2E5EA;--text:#111827;--muted:#5A6373;}
[data-theme="light"] .empty i{color:rgba(0,0,0,.10);}
[data-theme="light"] .empty h2{color:rgba(0,0,0,.34);}
</style>
</head>
<body>

<div class="topbar">
    <div class="topbar-left">
        <div class="live-dot"></div>
        <div>
            <div class="topbar-logo">☕ Barista Station</div>
            <div class="topbar-tag">Bird's Nest Coffee &mdash; <?= date('d M Y') ?></div>
        </div>
    </div>
    <div class="topbar-right">
        <div class="queue-badge">
            <i class="fa-solid fa-ticket" style="font-size:12px"></i>
            <span id="queueCount">0</span> in queue
        </div>
        <div class="clock" id="clock"></div>
        <a href="dashboard.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back</a>
    </div>
</div>

<div class="queue" id="queue">
    <div class="empty">
        <i class="fa-solid fa-mug-hot"></i>
        <h2>Queue is clear</h2>
        <p>New orders will appear here automatically.</p>
    </div>
</div>

<script>
const queue     = document.getElementById('queue');
const countEl   = document.getElementById('queueCount');
let knownOrders = new Set();

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function ago(dateStr) {
    const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
    if (diff < 60)  return diff + 's ago';
    if (diff < 3600) return Math.floor(diff/60) + 'm ago';
    return Math.floor(diff/3600) + 'h ago';
}

function buildMods(item) {
    const mods = [];
    if (item.addons && item.addons.length) item.addons.forEach(a => mods.push(a));
    if (item.size)      mods.push('Size: ' + item.size);
    if (item.sweetness) mods.push(item.sweetness);
    if (item.ice)       mods.push(item.ice + ' ice');
    if (item.milk)      mods.push(item.milk);
    return mods;
}

function renderTicket(o) {
    const items = o.items.map(item => {
        const mods = buildMods(item);
        return `<div class="ticket-item">
            <div class="item-qty">${esc(item.qty)}×</div>
            <div class="item-body">
                <div class="item-name">${esc(item.name)}</div>
                ${mods.length ? '<div class="item-mods">' + mods.map(m=>`<span class="mod">${esc(m)}</span>`).join('') + '</div>' : ''}
            </div>
        </div>`;
    }).join('');

    const el = document.createElement('div');
    el.className = 'ticket';
    el.dataset.orderId = o.order_id;
    el.innerHTML = `
        <div class="ticket-head">
            <div class="ticket-no">#${esc(o.daily_order_no)}</div>
            <div class="ticket-meta">
                <div class="ticket-customer">${esc(o.customer_name)}</div>
                <div class="ticket-age" data-date="${esc(o.order_date)}">${ago(o.order_date)}</div>
            </div>
        </div>
        <div class="ticket-items">${items || '<div style="color:var(--muted);font-size:12px;padding:4px 0">No items</div>'}</div>
        <div class="ticket-foot">
            <button class="done-btn" onclick="markDone(${o.order_id}, this.closest('.ticket'))">
                <i class="fa-solid fa-check"></i> Done
            </button>
        </div>`;
    return el;
}

async function markDone(orderId, card) {
    card.classList.add('completing');
    try {
        const res  = await fetch(`update_status.php?order_id=${orderId}&status=Completed&ajax=1`);
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Could not complete order.');
        knownOrders.delete(String(orderId));
        setTimeout(() => card.remove(), 420);
    } catch (e) {
        // Server rejected (or unreachable) — keep the ticket on screen, surface why
        card.classList.remove('completing');
        showBaristaToast(e.message || 'Could not complete order.');
    }
}

function showBaristaToast(msg) {
    const t = document.createElement('div');
    t.className = 'bd-toast';
    t.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i><span></span>';
    t.querySelector('span').textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => { t.classList.add('bd-toast-out'); setTimeout(() => t.remove(), 350); }, 3200);
}

async function refresh() {
    try {
        const res  = await fetch('barista_display.php?ajax=1');
        const data = await res.json();

        countEl.textContent = data.length;

        const currentIds = new Set(data.map(o => String(o.order_id)));

        // Remove tickets no longer in queue
        queue.querySelectorAll('.ticket').forEach(el => {
            if (!currentIds.has(el.dataset.orderId)) el.remove();
        });

        // Add new tickets
        data.forEach(o => {
            const id = String(o.order_id);
            if (!knownOrders.has(id)) {
                knownOrders.add(id);
                const empty = queue.querySelector('.empty');
                if (empty) empty.remove();
                queue.appendChild(renderTicket(o));
            }
        });

        // Show empty state
        if (data.length === 0 && !queue.querySelector('.ticket')) {
            queue.innerHTML = `<div class="empty">
                <i class="fa-solid fa-mug-hot"></i>
                <h2>Queue is clear</h2>
                <p>New orders will appear here automatically.</p>
            </div>`;
            knownOrders.clear();
        }

        // Update age timers
        queue.querySelectorAll('.ticket-age[data-date]').forEach(el => {
            el.textContent = ago(el.dataset.date);
        });

    } catch(e) {}
}

// Live clock
function tick() {
    const now = new Date();
    document.getElementById('clock').textContent =
        now.toLocaleTimeString('en-US', {hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true});
}
tick();
setInterval(tick, 1000);

// Poll every 4 seconds
refresh();
setInterval(refresh, 4000);
</script>
</body>
</html>
